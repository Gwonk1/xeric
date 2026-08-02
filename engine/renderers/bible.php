<?php
/**
 * Xeric — the bible renderer.
 *
 * Turns world-template FACTS into the shared-canon prose block an LLM system
 * prompt consumes, filtered through the viewer's knowledge walls and the
 * effective content rating.
 *
 * Deterministic by construction: no clock, no rand, no hash ordering, no LLM.
 * Same (template, viewer, rating) in → same bytes out. A later optional "forge
 * polish" pass may rewrite this prose with a model; that pass is not this file,
 * and this file must remain the thing you can diff.
 *
 * Two disciplines that are easy to lose and expensive to lose:
 *
 *  1. COMMONS TEXT IS TRULY COMMON. place.description, character.one_line and
 *     the schedules are read by every viewer who isn't walled off the section.
 *     Nothing that a wall protects may be authored into them — a walled fact
 *     put in a room's description is a leak the walls cannot see. Milldale is
 *     the worked example: Dale's Thursday slot says the basement door is
 *     closed, never what is behind it. The renderer cannot enforce this;
 *     template authors must.
 *  2. NO NEGATIVE SPACE. When a wall removes something, its header goes too.
 *     A viewer must never be able to infer the shape of what was taken.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/../walls.php';

/**
 * @param array      $template        world-template.json, decoded
 * @param array|null $viewer          null = narrator (full canon), else ['handle' => …]
 * @param string     $effectiveRating sfw | mature | explicit
 */
function xeric_render_bible(array $template, ?array $viewer, string $effectiveRating): string
{
    $v     = xeric_viewer($template, $viewer);
    $walls = xeric_viewer_walls($template, $v);
    $own   = (bool)$v['own_bible'];
    $eff   = $effectiveRating;
    $idx   = xeric_bible_index($template);

    $out  = [];
    $meta = $template['meta'] ?? [];

    $out[] = strtoupper((string)($meta['name'] ?? 'the world'));
    if (!empty($meta['description'])) $out[] = xeric_sentence((string)$meta['description']);

    // Framing first: the model should read its own posture before it reads facts.
    $framings = xeric_framings($walls);
    if ($framings) {
        $body = [];
        foreach ($framings as $f) $body[] = xeric_sentence($f);
        xeric_section($out, 'HOW YOU SEE THIS WORLD', $body);
    }

    xeric_section($out, 'THE PLACE',       xeric_bible_setting($template, $walls, $eff));
    // Never gender this header. The engine forged a they/them user its first
    // night out and called them "THE MAN AT THE CENTER" — the one line in the
    // whole prompt that is about the reader, misgendering them.
    //
    // And never assume they are central. A side character is not "at the
    // centre" of anything, and a heading that says otherwise makes a whole cast
    // orbit somebody they barely know. The forge derives the heading from the
    // user's declared narrative gravity; the default stays neutral.
    $heading = xeric_text($template['user']['centrality_heading'] ?? '') ?: 'THE PERSON AT THE CENTER';
    xeric_section($out, $heading, xeric_bible_user($template, $walls, $eff));
    xeric_section($out, 'PLACES',          xeric_bible_places($template, $walls, $eff, $idx));
    xeric_section($out, 'HOW PEOPLE GROUP', xeric_bible_orbits($template, $walls, $eff, $idx, $own));
    xeric_section($out, 'THE CAST',        xeric_bible_cast_lines($template, $walls, $eff, $v, $own, $idx));
    xeric_section($out, 'WHOSE STORY THIS IS', xeric_bible_protagonist($template, $walls, $idx));
    xeric_section($out, 'SCENERY',         xeric_bible_fixtures($template, $walls, $eff, $idx));
    xeric_section($out, 'WHERE THEY ARE',  xeric_bible_schedules($template, $walls, $eff, $idx));
    xeric_section($out, 'WHAT THEY CARRY', xeric_bible_dossiers($template, $walls, $eff, $v, $own, $idx));
    xeric_section($out, 'THE STRANGE PLACE', xeric_bible_mystery($template, $walls, $eff, $v, $idx));
    xeric_section($out, 'THE WEATHER OF THIS PLACE', xeric_bible_mood($template, $walls, $eff));

    return implode("\n", $out) . "\n";
}

// ---------------------------------------------------------------------------
// Index — built once, iterated never (determinism comes from template order)
// ---------------------------------------------------------------------------

function xeric_bible_index(array $template): array
{
    $idx = ['char' => [], 'fixture' => [], 'orbit' => [], 'place' => []];
    foreach ($template['cast']['characters'] ?? [] as $c) {
        if (isset($c['handle'])) $idx['char'][(string)$c['handle']] = $c;
    }
    foreach ($template['cast']['fixtures'] ?? [] as $f) {
        if (isset($f['key'])) $idx['fixture'][(string)$f['key']] = $f;
    }
    foreach ($template['cast']['orbits'] ?? [] as $o) {
        if (isset($o['key'])) $idx['orbit'][(string)$o['key']] = $o;
    }
    foreach ($template['places'] ?? [] as $p) {
        if (isset($p['key'])) $idx['place'][(string)$p['key']] = $p;
    }
    return $idx;
}

/** Display name for a handle that may be a character, a fixture, or neither. */
function xeric_bible_name(array $idx, string $key): string
{
    if (isset($idx['char'][$key]))    return (string)($idx['char'][$key]['display_name'] ?? $key);
    if (isset($idx['fixture'][$key])) {
        $f = $idx['fixture'][$key];
        // A fixture may be the scenery form of a speaking character (Milldale's
        // man behind the register IS Harlan): show the person, not the shift.
        $same = (string)($f['same_as'] ?? '');
        if ($same !== '' && isset($idx['char'][$same])) return (string)($idx['char'][$same]['display_name'] ?? $same);
        return (string)($f['name'] ?? $key);
    }
    return $key;
}

function xeric_bible_place_name(array $idx, string $key): string
{
    return isset($idx['place'][$key]) ? (string)($idx['place'][$key]['name'] ?? $key) : $key;
}

// ---------------------------------------------------------------------------
// Sections
// ---------------------------------------------------------------------------

function xeric_bible_setting(array $template, array $walls, string $eff): array
{
    if (xeric_hidden($walls, 'setting')) return [];
    $s = $template['setting'] ?? [];
    if (!$s) return [];

    $body = [];
    $where = trim((string)($s['locale'] ?? ''));
    $when  = trim((string)($s['era'] ?? ''));
    if ($where !== '' || $when !== '') {
        $body[] = xeric_sentence(trim($where . ($when !== '' ? ($where !== '' ? ', ' : '') . $when : '')));
    }

    if (!xeric_hidden($walls, 'setting.texture')) {
        $tex = [];
        foreach (xeric_rating_filter((array)($s['texture'] ?? []), $eff) as $t) {
            $line = xeric_text($t);
            if ($line !== '') $tex[] = $line;
        }
        if ($tex) $body[] = 'It smells and sounds like this: ' . xeric_join_list($tex) . '.';
    }

    if (!xeric_hidden($walls, 'setting.canon_rules')) {
        $rules = [];
        foreach (xeric_rating_filter((array)($s['canon_rules'] ?? []), $eff) as $r) {
            $line = xeric_text($r);
            if ($line !== '') $rules[] = '- ' . xeric_sentence($line);
        }
        if ($rules) {
            $body[] = '';
            $body[] = 'These are laws here, not flavor. They hold even when the story would rather they did not:';
            foreach ($rules as $r) $body[] = $r;
        }
    }
    return $body;
}

/**
 * The user's pronouns, resolved into every form the prose needs.
 *
 * The bible used to hardcode he/him/his in six places, so a they/them user —
 * the first one the forge ever built — read their own world as "him", "his
 * hands", "what he is after". The one section of the prompt that is ABOUT the
 * reader was the one that got them wrong.
 *
 * `user.pronouns` is free text because people write it many ways ("she/her",
 * "they", "he/him", "ze/hir"). Parse what we can, fall back to they/them —
 * never to a gender. Returns subject/object/possessive/reflexive plus `plural`
 * (they-verbs: "they are", not "they is").
 */
function xeric_pronouns(array $template): array
{
    $raw = strtolower(trim((string)($template['user']['pronouns'] ?? '')));
    $known = [
        'he'   => ['he', 'him', 'his', 'himself', false],
        'she'  => ['she', 'her', 'her', 'herself', false],
        'they' => ['they', 'them', 'their', 'themselves', true],
        'ze'   => ['ze', 'hir', 'hir', 'hirself', false],
        'xe'   => ['xe', 'xem', 'xyr', 'xemself', false],
        'it'   => ['it', 'it', 'its', 'itself', false],
    ];
    $first = trim(explode('/', $raw)[0] ?? '');
    $set = $known[$first] ?? $known['they'];
    return ['subj' => $set[0], 'obj' => $set[1], 'poss' => $set[2],
            'refl' => $set[3], 'plural' => $set[4],
            // "they are" vs "he is" — a verb helper so callers can't get it wrong
            'is' => $set[4] ? 'are' : 'is', 'has' => $set[4] ? 'have' : 'has'];
}

/** Sentence-case a pronoun for the start of a sentence. */
function xeric_pronoun_cap(string $p): string
{
    return mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1);
}

function xeric_bible_user(array $template, array $walls, string $eff): array
{
    $pn = xeric_pronouns($template);
    if (xeric_hidden($walls, 'user')) return [];
    $u = $template['user'] ?? [];
    if (!$u) return [];

    $name = trim((string)($u['name'] ?? ''));
    if ($name === '') return [];

    $body  = [];
    $first = $name;
    $occ   = (array)($u['occupation'] ?? []);
    $title = trim((string)($occ['title'] ?? ''));
    $loc   = trim((string)($u['location'] ?? ''));

    $line = $first;
    if (!empty($u['pronouns'])) $line .= ' (' . $u['pronouns'] . ')';
    if ($title !== '') $line .= ' — ' . $title;
    if ($loc !== '')   $line .= ', in ' . $loc;
    $body[] = xeric_sentence($line);

    // The bio: what the player has chosen to be known as, in their own words.
    // It sits right under the name because it is the same kind of fact — the
    // town's working picture of them — and it is the one line of this section
    // the player writes from inside the world (the pill on the play screen).
    $bio = trim((string)($u['bio'] ?? ''));
    if ($bio !== '') $body[] = xeric_sentence($bio);

    $hours = trim((string)($occ['hours'] ?? ''));
    if ($hours !== '') $body[] = 'Working hours: ' . $hours . '.';
    if (!empty($occ['workplace_key'])) {
        $body[] = 'You can expect to find ' . $pn['obj'] . ' at ' . xeric_bible_place_name(xeric_bible_index($template), (string)$occ['workplace_key']) . ' on a working day.';
    }
    if (!empty($u['quiet_hours'])) $body[] = xeric_pronoun_cap($pn['subj']) . ' ' . $pn['is'] . ' not up between ' . str_replace('-', ' and ', (string)$u['quiet_hours']) . '. Nobody reaches ' . $pn['obj'] . ' then.';

    $goals = [];
    foreach (xeric_rating_filter((array)($u['goals'] ?? []), $eff) as $g) {
        $t = xeric_text($g);
        if ($t !== '') $goals[] = $t;
    }
    if ($goals) $body[] = 'What ' . $pn['subj'] . ' ' . $pn['is'] . ' after: ' . xeric_join_list($goals) . '.';

    // How much this world bends toward them. Last, so it colours everything
    // above it — and load-bearing for a side character, whose whole point is
    // that the cast does NOT orbit them.
    $framing = xeric_text($u['centrality_framing'] ?? '');
    if ($framing !== '') { $body[] = ''; $body[] = $framing; }

    return $body;
}

/**
 * Whose story this is, when it is not the reader's.
 *
 * Only rendered when the user declared themselves off-centre and the forge
 * named somebody. Deliberately thin, and thinner still behind a wall. The
 * feeling is commons — the cast is meant to know somebody is going through
 * something and to feel the world leaning their way — so the framing survives
 * any wall that leaves the section standing. The arc and the pressure are that
 * person's interior with a spotlight on it, and they ride `drives` like anyone
 * else's: a reader who cannot read what somebody is really after cannot read
 * this either, whatever the forge copied into it.
 *
 * A wall that hides `protagonist` outright takes the whole section, framing and
 * all, which is what a protected reader's own copy gets: in their world nothing
 * is moving.
 */
function xeric_bible_protagonist(array $template, array $walls, array $idx): array
{
    $p = (array)($template['cast']['protagonist'] ?? []);
    $h = (string)($p['handle'] ?? '');
    if ($h === '' || xeric_hidden($walls, 'protagonist')) return [];
    $name = xeric_bible_name($idx, $h);

    $body = [];
    $body[] = 'Something is moving around ' . $name . ', and everyone here can feel it '
        . 'even if nobody has put it into words.';
    if (!xeric_hidden($walls, 'drives') && !xeric_hidden($walls, 'drives.' . $h)) {
        $arc   = xeric_text($p['arc'] ?? '');
        $press = xeric_text($p['pressure'] ?? '');
        if ($arc !== '')   $body[] = 'Where it is going: ' . xeric_sentence($arc);
        if ($press !== '') $body[] = 'What is forcing it: ' . xeric_sentence($press);
    }
    $body[] = 'This is the current the rest of you are standing in. You are not required to '
        . 'care about it, and pretending you have not noticed is its own kind of answer.';
    return $body;
}

function xeric_bible_places(array $template, array $walls, string $eff, array $idx): array
{
    if (xeric_hidden($walls, 'places')) return [];

    $blocks = [];
    foreach ($template['places'] ?? [] as $p) {
        $key = (string)($p['key'] ?? '');
        if ($key !== '' && xeric_hidden($walls, 'places.' . $key)) continue;
        if (!xeric_rating_allows($eff, $p)) continue;

        $b    = [];
        $bits = [];
        if (!empty($p['kind'])) $bits[] = (string)$p['kind'];
        $aliases = array_values(array_filter(array_map('strval', (array)($p['aliases'] ?? []))));
        if ($aliases) {
            $quoted = array_map(fn($a) => '"' . $a . '"', $aliases);
            $bits[] = 'people call it ' . xeric_join_list($quoted, 'or');
        }
        $head = (string)($p['name'] ?? $key);
        $b[]  = $head . ($bits ? ' — ' . implode('; ', $bits) : '') . '.';

        $hours = xeric_bible_hours((array)($p['hours'] ?? []));
        if ($hours !== '') $b[] = '  ' . $hours;
        if (!empty($p['serves_alcohol'])) $b[] = '  They pour here.';

        $desc = xeric_text($p['description'] ?? '');
        if ($desc !== '') $b[] = '  ' . $desc;

        $res = [];
        foreach ((array)($p['residents'] ?? []) as $r) {
            $n = xeric_bible_name($idx, (string)$r);
            if ($n !== '' && !in_array($n, $res, true)) $res[] = $n;
        }
        if ($res) $b[] = '  Usually there: ' . xeric_join_list($res) . '.';

        $blocks[] = $b;
    }
    return xeric_blocks($blocks);
}

/**
 * `hours` is a free-form bag in the schema (the doc shows close_weeknight and
 * open_late_weekend and stops). Print it mechanically rather than guess a
 * vocabulary that templates won't share: keys become words, values stand.
 */
function xeric_bible_hours(array $hours): string
{
    if (!$hours) return '';
    $parts = [];
    foreach ($hours as $k => $val) {
        $label = str_replace('_', ' ', (string)$k);
        if (is_bool($val)) { if ($val) $parts[] = $label; continue; }
        if (is_array($val)) { $parts[] = $label . ' ' . xeric_join_list(array_map('strval', $val)); continue; }
        $parts[] = $label . ' ' . (string)$val;
    }
    return $parts ? 'Hours: ' . implode('; ', $parts) . '.' : '';
}

function xeric_bible_orbits(array $template, array $walls, string $eff, array $idx, bool $own): array
{
    $pn = xeric_pronouns($template);
    if (xeric_hidden($walls, 'orbits')) return [];

    // Who belongs where, in cast order.
    $members = [];
    foreach ($template['cast']['characters'] ?? [] as $c) {
        if (!xeric_rating_allows($eff, $c)) continue;
        $o = (string)($c['orbit'] ?? '');
        if ($o !== '') $members[$o][] = (string)($c['display_name'] ?? $c['handle'] ?? '');
    }
    foreach ($template['cast']['fixtures'] ?? [] as $f) {
        if (!xeric_rating_allows($eff, $f)) continue;
        if (!empty($f['same_as'])) continue;
        $o = (string)($f['orbit'] ?? 'extras');
        $members[$o][] = (string)($f['name'] ?? $f['key'] ?? '');
    }

    $blocks = [];
    foreach ($template['cast']['orbits'] ?? [] as $o) {
        $key = (string)($o['key'] ?? '');
        if ($key !== '' && xeric_hidden($walls, 'orbits.' . $key)) continue;

        $b   = [];
        $b[] = xeric_sentence((string)($o['label'] ?? $key));
        // membership_block is insider framing; a protected relationship gets the
        // roster and the label, not the room's own account of itself.
        if (!$own) {
            $mb = xeric_text($o['membership_block'] ?? '');
            if ($mb !== '') $b[] = '  ' . $mb;
        }
        if (!empty($members[$key])) $b[] = '  Who: ' . xeric_join_list($members[$key]) . '.';
        if (!empty($o['shares_daily_space_with_user'])) {
            // Name, not pronoun, on both sides of this sentence: with a
            // they/them user "They are in the same rooms as them" makes the
            // orbit and the user the same word. A name is unambiguous for
            // every pronoun set.
            $un = xeric_text($template['user']['name'] ?? '') ?: 'them';
            $b[] = '  They are in the same rooms as ' . $un . ' most days, so they see what ' . $un
                 . ' does with ' . $pn['poss'] . ' hands and hear what ' . $un . ' says to other people.';
        }
        $blocks[] = $b;
    }

    if (!xeric_hidden($walls, 'circles')) {
        foreach ($template['cast']['circles'] ?? [] as $c) {
            $names = [];
            foreach ((array)($c['members_from_orbits'] ?? []) as $ok) {
                $names[] = (string)($idx['orbit'][$ok]['label'] ?? $ok);
            }
            $line = 'Across those lines: ' . xeric_join_list($names) . ' overlap';
            if (!empty($c['hangout_place'])) $line .= ' at ' . xeric_bible_place_name($idx, (string)$c['hangout_place']);
            $blocks[] = [xeric_sentence($line . ' — they talk to each other, so anything said in front of one of them can reach the rest')];
        }
    }
    return xeric_blocks($blocks);
}

function xeric_bible_cast_lines(array $template, array $walls, string $eff, array $v, bool $own, array $idx): array
{
    $pn = xeric_pronouns($template);
    if (xeric_hidden($walls, 'cast_lines')) return [];

    $body = [];
    foreach ($template['cast']['characters'] ?? [] as $c) {
        if (!xeric_rating_allows($eff, $c)) continue;
        $handle = (string)($c['handle'] ?? '');
        $name   = (string)($c['display_name'] ?? $handle);
        $orbit  = (string)($idx['orbit'][$c['orbit'] ?? '']['label'] ?? ($c['orbit'] ?? ''));

        if ($own) {
            // A protected relationship meets acquaintances, not people with insides.
            // `surface` is authored for exactly this; the fallback is deliberately
            // mechanical, because deriving one from `voice` would leak voice.
            $line = xeric_text($c['surface'] ?? '');
            if ($line === '') $line = $orbit !== '' ? 'someone from ' . $orbit : 'someone ' . $pn['subj'] . ' ' . ($pn['plural'] ? 'know' : 'knows');
            $body[] = $name . ($handle === $v['handle'] ? ' (you)' : '') . ' — ' . xeric_sentence($line);
            continue;
        }

        $head = $name;
        if (!empty($c['age'])) $head .= ' (' . (int)$c['age'] . ')';
        if ($orbit !== '')     $head .= ', ' . $orbit;
        if ($handle === $v['handle']) $head .= ' — you';

        $one = xeric_text($c['one_line'] ?? '');
        if ($one === '') $one = xeric_bible_first_sentence(xeric_text($c['voice'] ?? ''));
        $body[] = $head . ($one !== '' ? '. ' . xeric_sentence($one) : '.');
    }
    return $body;
}

function xeric_bible_first_sentence(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    $cut = strcspn($s, '.!?');
    return trim(substr($s, 0, $cut));
}

function xeric_bible_fixtures(array $template, array $walls, string $eff, array $idx): array
{
    if (xeric_hidden($walls, 'fixtures')) return [];

    $body = [];
    foreach ($template['cast']['fixtures'] ?? [] as $f) {
        if (!xeric_rating_allows($eff, $f)) continue;
        // Same-entity link: a fixture that IS a cast member is already in the
        // roster with a voice; listing it again would double the person.
        $same = (string)($f['same_as'] ?? '');
        if ($same !== '' && isset($idx['char'][$same])) continue;

        $line = (string)($f['name'] ?? $f['key'] ?? '');
        $role = xeric_text($f['role'] ?? '');
        if ($role !== '') $line .= ' — ' . $role;

        $where = [];
        if (!empty($f['place'])) $where[] = 'at ' . xeric_bible_place_name($idx, (string)$f['place']);
        $days = xeric_days_phrase((array)($f['days'] ?? []));
        if ($days !== '') $where[] = $days;
        if ($where) $line .= ', ' . implode(', ', $where);
        $body[] = xeric_sentence($line);

        $extra = [];
        foreach (['look', 'wear', 'voice'] as $k) {
            $t = xeric_text($f[$k] ?? '');
            if ($t !== '') $extra[] = $t;
        }
        if (array_key_exists('flirts', $f)) $extra[] = $f['flirts'] ? 'flirts' : 'does not flirt';
        if ($extra) $body[] = '  ' . xeric_sentence(implode('; ', $extra));
    }
    return $body;
}

/**
 * Schedules are commons, not interior: fixtures and walled relatives are meant
 * to know which room somebody is in on a Thursday. Whatever happens in that
 * room is dossier material and lives behind cast_dossiers.
 */
function xeric_bible_schedules(array $template, array $walls, string $eff, array $idx): array
{
    if (xeric_hidden($walls, 'schedules')) return [];

    $body = [];
    foreach ($template['cast']['characters'] ?? [] as $c) {
        if (!xeric_rating_allows($eff, $c)) continue;
        $week = xeric_rating_filter((array)($c['week'] ?? []), $eff);
        if (!$week) continue;

        $name  = (string)($c['display_name'] ?? $c['handle'] ?? '');
        $slots = [];
        foreach ($week as $w) {
            $days = xeric_days_phrase((array)($w['days'] ?? []));
            $bit  = $days !== '' ? $days : 'most days';
            if (!empty($w['from']) && !empty($w['to'])) $bit .= ' ' . $w['from'] . '–' . $w['to'];
            if (!empty($w['where'])) $bit .= ', ' . xeric_bible_place_name($idx, (string)$w['where']);
            $doing = xeric_text($w['doing'] ?? '');
            if ($doing !== '') $bit .= ' (' . $doing . ')';
            $slots[] = $bit;
        }
        $body[] = $name . ': ' . implode('; ', $slots) . '.';
    }
    return $body;
}

function xeric_bible_dossiers(array $template, array $walls, string $eff, array $v, bool $own, array $idx): array
{
    if ($own) return [];                                   // separate, smaller bible
    if (xeric_hidden($walls, 'cast_dossiers')) return [];

    $blocks = [];
    foreach ($template['cast']['characters'] ?? [] as $c) {
        if (!xeric_rating_allows($eff, $c)) continue;
        $handle = (string)($c['handle'] ?? '');
        $isSelf = ($handle !== '' && $handle === $v['handle']);
        $name   = (string)($c['display_name'] ?? $handle);

        $b   = [];
        $b[] = $name . ($isSelf ? ' (you)' : '');

        $voice = xeric_text($c['voice'] ?? '');
        if ($voice !== '') $b[] = '  Voice: ' . xeric_sentence($voice);

        if (!xeric_hidden($walls, 'psyche') && !xeric_hidden($walls, 'psyche.' . $handle)) {
            $psy   = (array)($c['psyche'] ?? []);
            $labels = [
                'sore_spot'        => 'Sore spot',
                'jealousy'         => 'Jealousy',
                'self_soothe'      => 'Self-soothe',
                'praise_that_lands'=> 'Praise that lands',
            ];
            foreach ($labels as $k => $label) {
                $t = xeric_text($psy[$k] ?? '');
                if ($t !== '' && xeric_rating_allows($eff, is_array($psy[$k] ?? null) ? $psy[$k] : [])) {
                    $b[] = '  ' . $label . ': ' . xeric_sentence($t);
                }
            }
        }

        if (!xeric_hidden($walls, 'tells')) {
            $tells = [];
            foreach (xeric_rating_filter((array)($c['tells'] ?? []), $eff) as $t) {
                $line = xeric_text($t);
                if ($line !== '') $tells[] = $line;
            }
            if ($tells) $b[] = '  Tells: ' . xeric_join_list($tells) . '.';
        }

        if (!xeric_hidden($walls, 'solace')) {
            $sol = xeric_text($c['solace'] ?? '');
            if ($sol !== '') $b[] = '  Goes to ground at: ' . xeric_sentence($sol);
        }
        if (!empty($c['flirt_style'])) $b[] = '  Flirts: ' . $c['flirt_style'] . '.';

        // A secret that isn't gossip_grade belongs to its owner. Shared canon is
        // where leaks live, so the default for a private secret is: only the
        // person carrying it, and the narrator, ever read it.
        if (!xeric_hidden($walls, 'secrets') && !xeric_hidden($walls, 'secrets.' . $handle)) {
            foreach (xeric_rating_filter((array)($c['secrets'] ?? []), $eff) as $s) {
                $text = xeric_text($s);
                if ($text === '') continue;
                $gossip = !empty($s['gossip_grade']);
                if (!$gossip && !$isSelf && !$v['is_narrator']) continue;
                $tag = [];
                if (isset($s['trust_gate'])) $tag[] = 'trust ' . (int)$s['trust_gate'];
                if ($gossip) $tag[] = 'travels if it gets out';
                $b[] = '  Holds back' . ($tag ? ' (' . implode(', ', $tag) . ')' : '') . ': ' . xeric_sentence($text);
            }
        }

        if (!xeric_hidden($walls, 'drives') && !xeric_hidden($walls, 'drives.' . $handle)) {
            $d = (array)($c['drives'] ?? []);
            if ($d && xeric_rating_allows($eff, $d)) {
                $pull = xeric_text($d['pull'] ?? '');
                if ($pull !== '') {
                    $disc = (string)($d['disclosure'] ?? 'subconscious');
                    $note = match ($disc) {
                        'open'   => 'They will say this one out loud.',
                        'earned' => 'This comes out only for someone who has earned it.',
                        default  => 'They do not know they are steering toward it and would deny it if asked.',
                    };
                    $b[] = '  Pull: ' . xeric_sentence($pull) . ' ' . $note;
                }
            }
        }

        if (!xeric_hidden($walls, 'relationships')) {
            $rel  = (array)($c['relationships'] ?? []);
            $rels = [];
            foreach ((array)($rel['roommates'] ?? []) as $r)    $rels[] = 'lives with ' . xeric_bible_name($idx, (string)$r);
            foreach ((array)($rel['friend_pairs'] ?? []) as $r) $rels[] = 'ride-or-die with ' . xeric_bible_name($idx, (string)$r);
            foreach ((array)($rel['attraction_seeds'] ?? []) as $who => $n) {
                $rels[] = 'carries a ' . (int)$n . '-out-of-10 soft spot for ' . xeric_bible_name($idx, (string)$who);
            }
            if ($rels) $b[] = '  ' . xeric_sentence(ucfirst(xeric_join_list($rels)));
        }

        $moods = [];
        foreach (xeric_rating_filter((array)($c['moods'] ?? []), $eff) as $m) {
            $cue  = xeric_text($m['cue'] ?? '');
            $note = xeric_text($m['note'] ?? '');
            if ($cue !== '' && $note !== '') $moods[] = $cue . ' → ' . $note;
        }
        if ($moods) $b[] = '  Weather: ' . implode('; ', $moods) . '.';

        if (count($b) > 1) $blocks[] = $b;
    }
    return xeric_blocks($blocks);
}

function xeric_bible_mystery(array $template, array $walls, string $eff, array $v, array $idx): array
{
    $pn = xeric_pronouns($template);
    $m = (array)($template['mystery'] ?? []);
    if (empty($m['enabled'])) return [];
    if (xeric_hidden($walls, 'mystery')) return [];
    if (!xeric_rating_allows($eff, $m)) return [];

    $pk = (string)($m['place_key'] ?? '');
    if ($pk !== '' && xeric_hidden($walls, 'places.' . $pk)) return [];

    // Built after the walls, not before: a viewer walled off the rumor and the
    // room would otherwise get a header pointing at the shape of what was taken.
    // The section IS the rumor; without it there is no section.
    $body = [];
    if (!xeric_hidden($walls, 'mystery.rumor')) {
        $rumor = xeric_text($m['rumor'] ?? '');
        if ($rumor !== '') {
            $body[] = xeric_sentence($rumor);
            // "rumor_pays_out: false" is an engine invariant. It reaches the model
            // as in-world canon, never as a stage direction — a narrator told
            // "this never resolves" writes around it; a narrator told "nobody has
            // ever found anything" simply believes it.
            if (empty($m['rumor_pays_out'])) {
                $body[] = 'Nobody has ever found anything in there. Nobody ever will. The story is the point, and the story is where it ends.';
            }
        }
    }

    if ($v['is_narrator'] && !xeric_hidden($walls, 'mystery.room')) {
        $room = (array)($m['room'] ?? []);
        if ($room) {
            $r = [];
            if (($room['voice_source'] ?? '') === 'user_raw_messages') {
                $r[] = 'Whatever speaks in there speaks in ' . $pn['poss'] . ' own words, borrowed back at ' . $pn['obj'] . '.';
            }
            if (!empty($room['one_true_thing'])) $r[] = 'It says one thing that is actually true.';
            if (!empty($room['photo_transform'])) $r[] = 'Anything that comes out of it is wrong in exactly one consistent way (' . $room['photo_transform'] . ').';
            if ($r) { $body[] = ''; foreach ($r as $line) $body[] = $line; }
        }
    }

    if (!$body) return [];
    if ($pk !== '') array_unshift($body, xeric_sentence(xeric_bible_place_name($idx, $pk) . ' is the one that breaks the rules'));
    return $body;
}

function xeric_bible_mood(array $template, array $walls, string $eff): array
{
    if (xeric_hidden($walls, 'world_mood')) return [];
    $w = (array)($template['world_mood'] ?? []);
    if (!$w) return [];

    $body = [];
    $axis = (array)($w['axis'] ?? []);
    $pos  = xeric_text($axis['positive'] ?? '');
    $neg  = xeric_text($axis['negative'] ?? '');
    // The axis strings are authored as "word — gloss" fragments, so give each one
    // its own clause instead of welding them into a sentence that reads sideways.
    if ($pos !== '' || $neg !== '') {
        $body[] = 'This place runs on a needle, and most days it sits near the middle and drifts back there on its own.';
        if ($neg !== '') $body[] = '  At one end: ' . xeric_sentence($neg);
        if ($pos !== '') $body[] = '  At the other: ' . xeric_sentence($pos);
    }

    foreach ([['light', 'When it is running light, you see'], ['dark', 'When it is running the other way, you see']] as [$k, $lead]) {
        $motifs = [];
        foreach (xeric_rating_filter((array)($w['motifs'][$k] ?? []), $eff) as $m) {
            $t = xeric_text($m);
            if ($t !== '') $motifs[] = $t;
        }
        if ($motifs) $body[] = $lead . ': ' . xeric_join_list($motifs) . '.';
    }
    return $body;
}
