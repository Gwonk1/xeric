<?php
/**
 * Xeric — scenes. The room you are standing in, described for a machine that
 * can show it to you, and play it to you.
 *
 * THE SIBLING OF photo.php, ONE SIZE UP. A photograph is a person in a frame;
 * a scene is the whole room at this minute — its shell and its furniture, the
 * light through its windows, the sky the cast was told about this morning,
 * and whoever is standing in it doing whatever their week says they are
 * doing. The machines that can turn that into something to look at (a
 * first-person still, an explorable room, a world model that streams) are
 * arriving faster than any of them is worth naming, so this file names none.
 * It answers the one question every one of them asks — WHAT IS HERE, NOW —
 * and hands the answer to whichever machine the owner chose.
 *
 * MODEL PROPOSES, CODE DISPOSES — for rooms too. Nothing in the brief is
 * invented and nothing is asked of a language model. It is read off canon:
 * the player's position (xeric_player_where), presence (who_is_where, the
 * dead excluded), the place's description and interior, the derived weather
 * WITH the database so the sky agrees with what the narrator told the cast,
 * the clock, the open/dark state. An `ask` may be folded in as a clause, the
 * way photo.php folds the model's proposal in; it is never the prompt, and
 * the rating language lands after it.
 *
 * ONLY OBSERVABLES. A scene is what a body in the doorway could SEE: who is
 * there, what they look like, what they are wearing, what they are at. Never
 * a wall, a secret, a psyche, a memory, a standing, a ledger — none of those
 * are read here at all, so none of them can leak by being mis-filtered. The
 * brief is safe to hand to a third-party machine whole because there is
 * nothing in it that was not already on the map (xeric_travel_map) or in a
 * portrait (xeric_photo_identity).
 *
 * THE AGE RULE IS THE SAME RULE, IN THE SAME WORDS. A minor anywhere in the
 * room forces the brief to the floor and appends photo.php's own wholesome
 * framing (XERIC_PHOTO_FLOOR_FRAMING — one constant, so the two composers
 * cannot drift), whatever the world's rating and whatever the ask said. A
 * machine's own classifier is never the only control. Everyone else is
 * rendered at the world's effective rating, passed through: the owner chose
 * the machine, and what it will draw is between them and it.
 *
 * NO OFFER, NO 'approved' GATE. Photos ask once ("generate photos now?")
 * because a forge enqueues a whole cast's worth of renders the owner never
 * individually requested. A scene is requested by somebody looking: the
 * player is standing in a room and a client asked to see it. The owner has
 * already chosen a scene machine (or an image machine) on the machines
 * screen, and that choice IS the consent. Dormant is still the shipping
 * state — no machine, no renders, no errors.
 *
 * KEYED BY WHAT THE ROOM LOOKS LIKE. One job per (place, fingerprint), and
 * the fingerprint is everything that changes the picture and nothing that
 * does not (xeric_scene_fingerprint says exactly what). Two visits in the
 * same light to the same company are the same scene and cost one render.
 *
 * WHERE THINGS SIT. Every person and every named thing in a brief carries a
 * bearing — whole degrees clockwise from straight ahead — derived the way the
 * seeds are and never stored. A panorama with name tags over it, a headset, a
 * map of one room: each puts the pie case where the last one did, and none of
 * them needs a floor plan that somebody had to draw and keep in step with the
 * template (xeric_scene_bearings).
 *
 * DRIFT IS ANSWERED WITH CONDITIONING. World models drift: ask one for the
 * same diner twice and the booths move. A seed holds a room still only for a
 * machine that honours seeds, but every machine can be SHOWN what it drew
 * last time — so the reaper hands the newest picture of this room back with
 * the next request for it (xeric_scene_reference), and the pie case stays the
 * pie case because the last picture of it rides along.
 *
 * WHAT YOU HEAR is the second channel (xeric_sound_brief), composed in code
 * from the same canon under the same refusals: the kind of place, open or
 * shut, the sky, the part of the day, how many are in it — a count, never a
 * name. Voices, never words: an audio machine that rendered speech you could
 * make out would be writing dialogue nobody wrote.
 *
 * AHEAD OF BEING LOOKED AT (xeric_scene_backfill, xeric_scene_ahead). The
 * heart may draw a room before anybody asks for it — on the owner's own
 * electricity by default, and on a machine that bills only when the world's
 * template asked in so many words. A glance is consent to draw what you are
 * looking at; a heartbeat is consent to nothing.
 *
 * PHP 8.1+.
 */

declare(strict_types=1);

require_once __DIR__ . '/photo.php';    // seeds, identity, the floor's words, the image machine
require_once __DIR__ . '/travel.php';   // position, open hours; death.php rides in with it
require_once __DIR__ . '/weather.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/clock.php';
require_once __DIR__ . '/splat.php';    // a splat, kept as PLY on arrival
require_once __DIR__ . '/layout.php';   // the room as a box, for a machine that lays one out

// ---------------------------------------------------------------------------
// The template's own knobs
// ---------------------------------------------------------------------------

/**
 * `media.scene`, with its defaults. A missing block is the defaults; a
 * malformed one is read as far as it reads and the rest defaults — a world
 * forged before scenes existed is already viewable, and nothing here throws.
 *
 *   enabled    true            the master switch: off is no rooms AND no sound
 *   vantage    first_person    or establishing
 *   style      ''              a clause folded in before the rating
 *   kinds      [pano, image]   what to ask a machine for, in preference order
 *   quality    full            or draft
 *   negative   ''              what a picture must not hold, for machines that take one
 *   sound      {enabled: true, style: ''}   the second channel
 *   prerender  (unset)         off | here | occupied | all; unset is decided by cost
 *
 * @return array{enabled:bool,vantage:string,style:string,kinds:string[],quality:string,
 *               negative:string,sound:array{enabled:bool,style:string},prerender:?string}
 */
function xeric_scene_config(array $t): array
{
    $c = $t['media']['scene'] ?? [];
    if (!is_array($c)) $c = [];
    // A word, or nothing: an array where a word belongs reads as unsaid,
    // not as "Array" with a warning attached.
    $word = fn($v): string => is_scalar($v) ? strtolower(trim((string)$v)) : '';
    $v    = $word($c['vantage'] ?? 'first_person');
    $pre  = $word($c['prerender'] ?? '');
    $s    = is_array($c['sound'] ?? null) ? $c['sound'] : [];
    $on   = !array_key_exists('enabled', $c) || !empty($c['enabled']);
    return [
        'enabled' => $on,
        // Two vantages and no third: an unknown word is the default, not an
        // error, because a typo in a template should not cost anyone the view.
        'vantage' => in_array($v, ['first_person', 'establishing'], true) ? $v : 'first_person',
        'style'   => trim(xeric_text($c['style'] ?? '')),
        // What the owner would like back, in preference order, said to the
        // machine as `kind_wanted`; the machine answers with what it made.
        'kinds'   => array_values(array_filter(array_map($word, is_array($c['kinds'] ?? null) ? $c['kinds'] : []),
                         fn($k) => in_array($k, ['pano', 'image', 'splat', 'glb', 'viewer'], true)))
                     ?: ['pano', 'image'],
        'quality' => $word($c['quality'] ?? '') === 'draft' ? 'draft' : 'full',
        'negative'=> trim(xeric_text($c['negative'] ?? '')),
        // The second channel lives inside the first: a world that turned its
        // rooms off has turned their sound off with them.
        'sound'   => ['enabled' => $on && (!array_key_exists('enabled', $s) || !empty($s['enabled'])),
                      'style'   => trim(xeric_text($s['style'] ?? ''))],
        // Null is "the template did not say", which xeric_scene_prerender
        // answers by cost. An unknown word is the same as not saying.
        'prerender' => in_array($pre, ['off', 'here', 'occupied', 'all'], true) ? $pre : null,
    ];
}

/** The light buckets, in the order a day goes through them. */
function xeric_scene_lights(): array
{
    return ['pre-dawn', 'morning light', 'noon', 'afternoon light', 'golden hour', 'dusk', 'lamplit night'];
}

/**
 * The light, as a short phrase. DETERMINISTIC from the clock alone: the
 * phase is the engine's canon bucket (xeric_world_phase) and the hour splits
 * it where a window would. No latitude and no season — the same reason the
 * phase boundaries are not per-template: a light that drifted by date would
 * mint a new fingerprint every few days for a room that has not changed.
 */
function xeric_scene_light(array $now): string
{
    $m     = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    $phase = (string)($now['phase'] ?? xeric_world_phase($m));
    return match ($phase) {
        'morning'   => $m < 390 ? 'pre-dawn' : ($m < 660 ? 'morning light' : 'noon'),
        'afternoon' => $m < 840 ? 'noon' : 'afternoon light',
        'evening'   => $m < 1140 ? 'golden hour' : ($m < 1260 ? 'dusk' : 'lamplit night'),
        default     => ($m >= 240 && $m < 300) ? 'pre-dawn' : 'lamplit night',
    };
}

// ---------------------------------------------------------------------------
// Where things sit
// ---------------------------------------------------------------------------

/** Nearer than GAP degrees, two labels are one smudge, and the later moves on by NUDGE. */
const XERIC_SCENE_BEARING_GAP   = 20;
const XERIC_SCENE_BEARING_NUDGE = 40;

/**
 * Where everything in a room sits around you: whole degrees, 0 to 359,
 * clockwise from straight ahead as you stand at the vantage. DERIVED, NEVER
 * STORED — crc32 of world, place and the thing's own name, lowercased the way
 * photo.php's per-thing seeds are — so two visits, two renders and two
 * different clients all put the pie case in the same place, and nobody had to
 * author a floor plan or keep one in step with the template.
 *
 * SPREAD SO A LABEL CAN BE READ. Anything landing within 20 degrees of
 * something already placed moves on by 40, as many times as it takes, in a
 * fixed order: FURNITURE FIRST, by name, then PEOPLE, by handle. Furniture
 * first so that a room's things never move because somebody walked in;
 * people after, so they find their places around what was already there, and
 * the earliest handle keeps its own. Nine things always fit — a placed thing
 * shuts out a window narrower than one nudge, so one of the nine stops round
 * the circle is always free — and a room more crowded than that keeps the
 * raw bearing for whatever does not fit: the ninth nudge lands back where it
 * started and stays. An honest overlap, never a loop.
 *
 * Not in the fingerprint, and it need not be: a bearing is a function of the
 * interior and the company, which the fingerprint already holds.
 *
 * @param string[] $items  the interior's texts
 * @param string[] $people handles
 * @return array{items:array<string,int>,who:array<string,int>}
 */
function xeric_scene_bearings(array $t, string $key, array $items, array $people): array
{
    $world  = (string)($t['meta']['name'] ?? 'xeric');
    $placed = [];
    $out    = ['items' => [], 'who' => []];
    foreach (['items' => $items, 'who' => $people] as $pool => $names) {
        $names = array_values(array_unique(array_map('strval', $names)));
        sort($names, SORT_STRING);
        foreach ($names as $n) {
            $b = crc32($world . '|' . $key . '|' . mb_strtolower(trim($n))) % 360;
            for ($i = 0; $i < 9 && xeric_scene_bearing_taken($b, $placed); $i++) {
                $b = ($b + XERIC_SCENE_BEARING_NUDGE) % 360;
            }
            $placed[] = $b;
            $out[$pool][$n] = $b;
        }
    }
    return $out;
}

/** Is something already within the gap of this bearing, the short way round? */
function xeric_scene_bearing_taken(int $b, array $placed): bool
{
    foreach ($placed as $p) {
        $d = abs($b - $p);
        if (min($d, 360 - $d) < XERIC_SCENE_BEARING_GAP) return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// The brief
// ---------------------------------------------------------------------------

/**
 * The composed description of a place at a moment. THE thing a viewport
 * client is built against, the way xeric_travel_map is the thing a map is.
 *
 * $key null is the player's own room — the CURRENT player's (travel.php, a
 * body each), with every other player standing in it in `who` as
 * `{handle, name, player: true, bearing}`; a player who is nowhere has no room,
 * and null is the honest answer (nowhere is a real state). An unknown key,
 * or a home not yet in the story, is null too — fail closed, the same way the
 * map simply does not list an unstaged house.
 *
 * $opts: 'ask' (a clause folded in before the rating, never the prompt).
 *
 * @return ?array see the shape in the return statement; every field is an
 *         observable or a number derived from one.
 */
function xeric_scene_brief(array $t, PDO $db, ?string $key = null, ?array $now = null, array $opts = []): ?array
{
    $key ??= xeric_player_where($t, $db);
    if ($key === null || $key === '') return null;
    $p = xeric_world_place($t, $key);
    if ($p === null || xeric_travel_unstaged($t, $key)) return null;

    $now  ??= xeric_clock_now($db, $t);
    $cfg    = xeric_scene_config($t);
    $dark   = xeric_dark_places($db);
    $seeds  = xeric_photo_place_seeds($t, $key);
    $light  = xeric_scene_light($now);
    $wx     = trim(xeric_weather_line($t, $now, $db));
    $open   = xeric_travel_open($t, $key, $now, $dark);
    $isDark = in_array($key, $dark, true);

    // Who is here, and only what a body in the doorway could see of them.
    // Presence already drops the dead and the not-yet-entered; the dead are
    // passed explicitly so nobody is ever standing in the room they died in.
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));
    $who   = [];
    $faces = [];
    $minor = false;
    foreach (xeric_world_who_is_at($presence, $key) as $h) {
        $id = xeric_photo_identity($t, $h);
        if ($id === null) continue;
        $minor = $minor || $id['minor'];
        $faces[$h] = $id['face_seed'];
        $who[] = [
            'handle'     => $h,
            'name'       => $id['name'],
            'doing'      => trim(xeric_text($presence[$h]['doing'] ?? '')),
            'at_home'    => !empty($presence[$h]['at_home']),
            'appearance' => $id['appearance'],
            'build'      => $id['build'],
            'wears'      => array_column($id['wears'], 'text'),
        ];
    }

    // The ceiling, and the floor that beats it. Same reasoning as photo.php's
    // AGE RULE: a minor in the room is a minor in the frame.
    $rating = $minor ? xeric_ratings()[0] : xeric_world_rating($t);

    $name  = (string)($p['name'] ?? $key);
    $desc  = trim(xeric_text($p['description'] ?? ''));
    $items = array_column((array)($seeds['items'] ?? []), 'text');

    // Where everything sits, the furniture placed before the company.
    $bear = xeric_scene_bearings($t, $key, $items, array_column($who, 'handle'));
    foreach ($who as $i => $w) $who[$i]['bearing'] = $bear['who'][$w['handle']];

    // THE OTHER PLAYERS IN THE ROOM, after the cast and placed after it, so
    // nobody the cast already stood beside is moved for them. Observables only
    // and that means a name: a player has no appearance in the template, and
    // the engine does not invent one for a real person. The bearing is where
    // they stand, which is the room's to say, not theirs.
    $bodies  = xeric_travel_players($t, $db);
    $players = xeric_travel_others_at($t, $db, $key, null, $bodies);
    // EVERY HUMAN IN THE ROOM, the one looking included: two players standing
    // in one room are looking at ONE room, so what the room is (its prompt,
    // its fingerprint) is keyed on the set of them, never on who is asking —
    // otherwise Ann's look and Walt's look at the same moment are two
    // fingerprints and two renders of one place. `who` still leaves the viewer
    // out, because a person is not company to themselves. Set only when
    // somebody else is here, so a world of one composes what it always did.
    $company = [];
    if ($players !== []) {
        foreach ($bodies as $pb) if ($pb['where'] === $key) $company[(string)$pb['handle']] = (string)$pb['name'];
        ksort($company, SORT_STRING);
    }
    if ($players !== []) {
        $placed = array_merge(array_values($bear['items']), array_values($bear['who']));
        $world  = (string)($t['meta']['name'] ?? 'xeric');
        usort($players, fn($a, $b) => strcmp($a['handle'], $b['handle']));
        foreach ($players as $o) {
            $b = crc32($world . '|' . $key . '|' . $o['handle']) % 360;
            for ($i = 0; $i < 9 && xeric_scene_bearing_taken($b, $placed); $i++) {
                $b = ($b + XERIC_SCENE_BEARING_NUDGE) % 360;
            }
            $placed[] = $b;
            $who[] = $o + ['bearing' => $b];
        }
    }
    $interior = [];
    foreach ((array)($seeds['items'] ?? []) as $it) $interior[] = $it + ['bearing' => $bear['items'][$it['text']]];

    $brief = [
        'key'      => $key,
        'name'     => $name,
        'kind'     => (string)($p['kind'] ?? ''),
        'channel'  => 'look',
        'now'      => [
            'epoch' => (int)($now['epoch'] ?? 0),
            'hhmm'  => (string)($now['hhmm'] ?? ''),
            'dow'   => (int)($now['dow'] ?? 0),
            'phase' => (string)($now['phase'] ?? ''),
        ],
        'light'    => $light,
        'weather'  => $wx,
        'open'     => $open,
        'dark'     => $isDark,
        'exterior' => ['description' => $desc, 'seed' => (int)($seeds['exterior'] ?? 0)],
        'interior' => $interior,
        'who'      => $who,
        'you'      => ['name' => xeric_travel_you_name($t, $db),
                       'since' => xeric_player_since($db)],
        'setting'  => ['era'    => trim(xeric_text($t['setting']['era'] ?? '')),
                       'locale' => trim(xeric_text($t['setting']['locale'] ?? ''))],
        'rating'   => $rating,
        'minor'    => $minor,
        'enabled'  => $cfg['enabled'],
        'vantage'  => $cfg['vantage'],
        'seeds'    => ['place' => $seeds, 'faces' => $faces],
    ] + ($company !== [] ? ['company' => $company] : []);

    $brief['prompt']      = xeric_scene_prompt($brief, $name, $desc, $items, $cfg, trim((string)($opts['ask'] ?? '')));
    $brief['fingerprint'] = xeric_scene_fingerprint($brief, $desc, $items, $cfg, trim((string)($opts['ask'] ?? '')));
    // The room as a box (layout.php). After the fingerprint and never in it:
    // it is derived from the interior, the company and the vantage, all in.
    $brief['layout'] = xeric_layout($brief, $t);
    return $brief;
}

/**
 * The paragraph, composed in code, in the order photo.php composes: the
 * world's own aesthetic first (an era-true clause, because a phone in an 1873
 * room is a broken room), then the room, its things, its light and sky, then
 * the people, then where the eye is — and the rating LAST, after style and
 * ask, so nothing upstream of it can talk it down or up.
 */
function xeric_scene_prompt(array $b, string $name, string $desc, array $items, array $cfg, string $ask): string
{
    $bits = [];
    if ($b['setting']['locale'] !== '') $bits[] = $b['setting']['locale'];
    if ($b['setting']['era'] !== '') {
        $bits[] = 'period-true to ' . $b['setting']['era'] . ', nothing anachronistic in view';
    }

    $room = 'inside ' . $name;
    if ($desc !== '') $room .= ', ' . rtrim($desc, '.');
    $bits[] = $room;
    if ($items !== []) $bits[] = 'in the room: ' . implode(', ', $items);

    $sky = $b['light'];
    if ($b['weather'] !== '') $sky .= ', ' . rtrim($b['weather'], '.');
    $bits[] = $sky;
    // The state of the room, said only when it is not the ordinary one: an
    // open room needs no announcement, a shut one changes every surface.
    if ($b['dark'])       $bits[] = 'the place has gone dark and stands unkept';
    elseif (!$b['open'])  $bits[] = 'closed at this hour';

    // The people, as a bystander would list them. No pronouns: names and
    // the template's own words carry every person, so nothing here guesses.
    if ($b['who'] === []) {
        $bits[] = 'nobody else is here';
    } else {
        $people = [];
        foreach ($b['who'] as $w) {
            $line = $w['name'];
            // Another player: a name and nothing the template never said —
            // and with company, every human present is named below instead,
            // the same list whoever is looking.
            if (!empty($w['player'])) { if (!isset($b['company'])) $people[] = $line; continue; }
            if ($w['build'] !== '')      $line .= ', ' . rtrim($w['build'], '.');
            if ($w['appearance'] !== '') $line .= ', ' . rtrim($w['appearance'], '.');
            if ($w['wears'] !== [])      $line .= ', wearing ' . implode(', ', $w['wears']);
            if ($w['doing'] !== '')      $line .= ' — ' . rtrim($w['doing'], '.');
            elseif ($w['at_home'])       $line .= ' — at home';
            $people[] = $line;
        }
        foreach ((array)($b['company'] ?? []) as $pn) $people[] = (string)$pn;
        $bits[] = 'present: ' . implode('; ', $people);
    }

    $bits[] = $cfg['vantage'] === 'establishing'
        ? 'a wide establishing view of the whole place, nobody at the centre of the frame'
        : 'seen from where you stand, just inside the door';

    if ($cfg['style'] !== '') $bits[] = rtrim($cfg['style'], '.');
    if ($ask !== '')          $bits[] = rtrim($ask, '.');

    // THE STRUCTURAL CAP, last. Photo.php's floor words for a minor in the
    // room, verbatim; the rating ladder's own language for everyone else.
    $bits[] = $b['minor'] ? XERIC_PHOTO_FLOOR_FRAMING : match ($b['rating']) {
        'sfw', 'pg' => 'a wholesome everyday scene, nothing suggestive',
        'teen'      => 'an everyday scene, nothing explicit',
        default     => 'a scene in keeping with the world\'s adult rating',
    };

    // Each bit is a sentence; strip a trailing stop from each so a template
    // that ends its description with one does not double the full stop.
    return implode('. ', array_map(fn($s) => rtrim($s, '. '), $bits)) . '.';
}

/**
 * What the room LOOKS like, hashed. In: the place key and description, its
 * interior, the light, the weather line, who is present (sorted handles, and
 * what each looks like and wears), open/dark, the vantage and style, the ask,
 * the rating. Out, deliberately: the minute, the epoch, the day of week, and
 * what each person is DOING — the minute because a room at 07:10 and at 07:40
 * in the same morning light is the same room, and a fingerprint that ticked
 * would make every glance a render; the doing because it is a pose, and the
 * week's blocks change who is present (which IS in) far more often than they
 * change one present person's errand. The prompt row keeps the doing words as
 * they were first asked; a viewer that wants the present tense reads the
 * brief, which is fresh every call. Bearings are out too, because they are
 * derived from the interior and the company, which are already in.
 */
function xeric_scene_fingerprint(array $b, string $desc, array $items, array $cfg, string $ask = ''): string
{
    $people  = [];
    $players = [];
    foreach ($b['who'] as $w) {
        if (!empty($w['player'])) { $players[$w['handle']] = $w['name']; continue; }
        $people[$w['handle']] = [$w['appearance'], $w['build'], $w['wears']];
    }
    ksort($people, SORT_STRING);
    ksort($players, SORT_STRING);
    // ONE ROOM, ONE KEY: with company the players are the whole set standing
    // in it, the viewer included (xeric_scene_brief's `company`), so everyone
    // in the room shares a fingerprint and one render serves them all.
    if (isset($b['company'])) $players = (array)$b['company'];
    // A SECOND HUMAN IN THE ROOM IS A DIFFERENT ROOM — but the key is there
    // only when somebody is, so a world of one hashes exactly what it always
    // hashed and no view on anybody's disk goes stale for this.
    return sha1(json_encode([
        'key' => $b['key'], 'description' => $desc, 'interior' => $items,
        'light' => $b['light'], 'weather' => $b['weather'],
        'who' => $people, 'open' => $b['open'], 'dark' => $b['dark'],
        'vantage' => $cfg['vantage'], 'style' => $cfg['style'],
        'ask' => $ask, 'rating' => $b['rating'],
    ] + ($players !== [] ? ['players' => $players] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ---------------------------------------------------------------------------
// What you hear
// ---------------------------------------------------------------------------

/**
 * The floor, for sound. The same rule as the picture's — a minor in the room
 * forces it, in code, last, whatever the world's rating and whatever the ask
 * — said in the medium's own words, because a photograph's floor is about
 * clothes and daylight and a sound's is about what may be heard. One
 * constant, so the sound composer cannot drift from itself.
 */
const XERIC_SOUND_FLOOR_FRAMING = 'only wholesome everyday sound, nothing suggestive, fit for a family to overhear';

/** The most words the line under a view may run to. */
const XERIC_SOUND_LINE_WORDS = 14;

/**
 * What you hear, standing where you stand. The look brief's sibling, built
 * from the same canon under the same refusals, and smaller on purpose: a
 * doorway hears how MANY people are in a room and whether the one of them is
 * talking — never who they are, never what they said. No name, no doing text
 * and no description reaches this brief at all, and the line under the view
 * is built from a fixed vocabulary, so there is nothing in it that could
 * quote a wall even by accident.
 *
 * ERA-TRUE BY CONSTRUCTION. The line's vocabulary is sounds every era has —
 * rain on a roof, a floorboard, cups and plates, a murmur — so an 1873 fog
 * town never hears an engine in its own street; the paragraph for the machine
 * carries the look brief's era clause, in earshot rather than in view.
 *
 * Null exactly where the look brief is null: nowhere, an unknown key, a home
 * not yet in the story. $opts: 'ask', as for the look.
 *
 * @return ?array{key:string,name:string,kind:string,channel:string,now:array,open:bool,
 *                dark:bool,weather:string,phase:string,heard:int,voiced:bool,setting:array,
 *                vantage:string,rating:string,minor:bool,enabled:bool,kinds:string[],seed:int,
 *                line:string,prompt:string,fingerprint:string}
 */
function xeric_sound_brief(array $t, PDO $db, ?string $key = null, ?array $now = null, array $opts = []): ?array
{
    $key ??= xeric_player_where($t, $db);
    if ($key === null || $key === '') return null;
    $p = xeric_world_place($t, $key);
    if ($p === null || xeric_travel_unstaged($t, $key)) return null;

    $now  ??= xeric_clock_now($db, $t);
    $cfg    = xeric_scene_config($t);
    $dark   = xeric_dark_places($db);
    $phase  = (string)($now['phase'] ?? '')
           ?: xeric_world_phase(xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0);

    // A count, and whether the one is talking. The same presence, the same
    // dead and the same identities as the look brief, so the two channels
    // agree about who is in the room while saying different things about it.
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));
    $count = 0;
    $minor = false;
    $doing = '';
    foreach (xeric_world_who_is_at($presence, $key) as $h) {
        $id = xeric_photo_identity($t, $h);
        if ($id === null) continue;
        $count++;
        $minor = $minor || $id['minor'];
        $doing = trim(xeric_text($presence[$h]['doing'] ?? ''));
    }
    // AND ANOTHER PERSON AT THE CENTRE standing in it is somebody a doorway
    // hears too (the look counts them the same way). Never the listener
    // themselves, and nobody at all in a world of one — whose sound, and so
    // whose fingerprint, is exactly what it was.
    $count += count(xeric_travel_others_at($t, $db, $key));
    $heard = min(2, $count);   // nobody, one, several: all a doorway counts by ear

    $b = [
        'key'     => $key,
        'name'    => (string)($p['name'] ?? $key),
        'kind'    => (string)($p['kind'] ?? ''),
        'channel' => 'sound',
        'now'     => [
            'epoch' => (int)($now['epoch'] ?? 0),
            'hhmm'  => (string)($now['hhmm'] ?? ''),
            'dow'   => (int)($now['dow'] ?? 0),
            'phase' => $phase,
        ],
        'open'    => xeric_travel_open($t, $key, $now, $dark),
        'dark'    => in_array($key, $dark, true),
        'weather' => trim(xeric_weather_line($t, $now, $db)),
        'phase'   => $phase,
        'heard'   => $heard,
        'voiced'  => $heard === 2 || ($heard === 1 && xeric_sound_voiced($doing)),
        'setting' => ['era'    => trim(xeric_text($t['setting']['era'] ?? '')),
                      'locale' => trim(xeric_text($t['setting']['locale'] ?? ''))],
        'vantage' => $cfg['vantage'],
        'rating'  => $minor ? xeric_ratings()[0] : xeric_world_rating($t),
        'minor'   => $minor,
        'enabled' => $cfg['sound']['enabled'],
        'kinds'   => ['audio'],
        // The room's sound has a seed of its own, beside its two shells.
        'seed'    => xeric_photo_seed($t, 'place.sound', $key),
    ];
    $ask = trim((string)($opts['ask'] ?? ''));
    $b['line']        = xeric_sound_line($b);
    $b['prompt']      = xeric_sound_prompt($b, $cfg['sound']['style'], $ask);
    $b['fingerprint'] = xeric_sound_fingerprint($b, $cfg['sound']['style'], $ask);
    return $b;
}

/**
 * What sort of room it is, by ear. Read off the place's kind by keyword, as
 * coarse as weather.php's climate read and for the same reason: deriving
 * beats declaring, and every world ever forged already says what its places
 * are. An unknown kind is a room, which is the sound of not saying.
 */
function xeric_sound_class(string $kind): string
{
    $k   = mb_strtolower($kind);
    // UCP, so a word boundary knows that the é in café is a letter.
    $has = fn(string $re): bool => preg_match('/(*UCP)\b(?:' . $re . ')\b/u', $k) === 1;
    if ($has('diner|cafe|café|restaurant|bar|pub|tavern|inn|saloon|canteen|bakery|kitchen|coffeehouse'
           . '|coffee|teahouse|tearoom|bistro|eatery|mess')) return 'eatery';
    if ($has('church|chapel|temple|shrine|mosque|synagogue|cathedral|abbey|monastery|kirk|meeting house'))
        return 'worship';
    if ($has('shop|store|hardware|market|mercantile|grocer|grocery|pharmacy|chemist|bookshop|bookstore'
           . '|emporium|stall|smithy')) return 'shop';
    if ($has('site|park|street|square|plaza|field|yard|garden|woods?|forest|beach|shore|docks?|harbou?r'
           . '|pier|wharf|river|lake|bridge|lot|cemetery|graveyard|quarry|ruins?|trail|road|alley'))
        return 'outdoors';
    if ($has('home|house|apartment|flat|cottage|cabin|lodging|farmhouse|manor')) return 'home';
    return 'room';
}

/**
 * The sky, as heard from here — or nothing, which is most days. Read by
 * keyword off the day's weather line, which is era-neutral by construction
 * (weather.php's own rule); first match wins, coarse on purpose, and every
 * phrase is five words or fewer so the line's budget holds.
 */
function xeric_sound_weather(string $line, bool $outdoors): string
{
    $w   = mb_strtolower($line);
    $has = fn(string $re): bool => preg_match('/\b(?:' . $re . ')\b/u', $w) === 1;
    if ($has('thunder\w*|lightning|storms?'))  return 'thunder far off';
    if ($has('gale|whiteout'))                 return $outdoors ? 'a gale tearing past' : 'wind hard at the walls';
    // Rain that "never comes" is a sky, not a sound.
    if ($has('rain|rains|raining|downpour|showers?|squalls?|drizzle') && !$has('never comes|no rain')) {
        return $outdoors ? 'rain on everything' : 'rain on the roof';
    }
    if ($has('foghorns?'))                     return 'a foghorn somewhere';
    if ($has('snow|snowing|drifts?'))          return 'the hush snow makes';
    if ($has('thaw|dripping|drips?|meltwater')) return 'water dripping off the eaves';
    if ($has('fog|foggy|mist|misty'))          return 'everything muffled by fog';
    if ($has('wind|winds|windy|breeze|gusts?|gusting')) {
        return $outdoors ? 'wind across open ground' : 'wind at the door';
    }
    if ($has('gulls?'))                        return 'gulls overhead';
    if ($has('vents?|air handlers?|recycled air')) return 'the vents humming low';
    if ($has('mosquito|mosquitoes'))           return 'mosquitoes whining';
    if ($has('heat|hot|furnace|sodden'))       return 'the heat holding everything still';
    return '';
}

/**
 * Does what one person is at sound like talk? Read off their week's `doing`
 * by keyword and NEVER ECHOED: the words decide one bit, and the bit is all
 * that reaches the line, the prompt or the machine.
 */
function xeric_sound_voiced(string $doing): bool
{
    return preg_match('/\b(?:talk\w*|chat\w*|gossip\w*|phone|calls?|calling|sings?|singing|choir|hymns?'
        . '|preach\w*|sermons?|service|handshakes?|visits?|visiting|meetings?|circle|class|classes|teach\w*'
        . '|lessons?|argu\w*|laugh\w*|story|stories|pray\w*|radio)\b/u', mb_strtolower($doing)) === 1;
}

/**
 * The room's own noise: what is left to hear once the people are counted. By
 * the class of room, occupied or not, open or shut — and a room the world has
 * lost above all of them.
 */
function xeric_sound_room(string $class, bool $occupied, bool $open, bool $dark): string
{
    if ($dark) return $occupied ? 'the rest gone quiet' : 'a silence nobody is keeping';
    $table = [
        //             somebody in it                          empty and open                   shut
        'eatery'   => ['cups and plates knocking',             'a cooling pan ticking',         'the shut room settling'],
        'worship'  => ['footsteps under a high ceiling',       "a high room's hush",            'old timber settling'],
        'shop'     => ["a floorboard under somebody's weight", 'stock settling on the shelves', 'the shut shop settling'],
        'outdoors' => ['footsteps on open ground',             'open air, nobody in it',        'nobody let in'],
        'home'     => ['a lived-in house',                     'a house holding still',         'a house holding still'],
        'room'     => ['the room going about itself',          "the room's own small noises",   'a shut room, nothing moving'],
    ];
    $row = $table[$class] ?? $table['room'];
    return $occupied ? $row[0] : ($open ? $row[1] : $row[2]);
}

/**
 * The line under the view: one sentence, fourteen words at most — the sky,
 * then the people, then the room. Built only from the fixed phrases above;
 * nothing in it is template prose, so it is safe on any surface that would
 * show a caption, and byte-identical every time the same room sounds the same.
 */
function xeric_sound_line(array $b): string
{
    $class  = xeric_sound_class((string)$b['kind']);
    $people = match ((int)$b['heard']) {
        0       => '',
        1       => $b['voiced'] ? 'one voice, low' : 'somebody busy, not talking',
        default => 'a murmur of voices',
    };
    $parts = array_values(array_filter([
        xeric_sound_weather((string)$b['weather'], $class === 'outdoors'),
        $people,
        xeric_sound_room($class, (int)$b['heard'] > 0, (bool)$b['open'], (bool)$b['dark']),
    ], fn($s) => $s !== ''));
    // Three phrases of at most five, four and five words: the cap is belt and
    // braces, so a phrase added later cannot break the budget unnoticed.
    $words = array_slice(preg_split('/\s+/u', implode(', ', $parts)) ?: [], 0, XERIC_SOUND_LINE_WORDS);
    return ucfirst(rtrim(implode(' ', $words), ',')) . '.';
}

/**
 * The paragraph for a machine that makes sound, in the look prompt's order:
 * the world's own aesthetic first, then the place and the part of the day,
 * the sky, the company as a count, where the ear is — and the rating LAST.
 */
function xeric_sound_prompt(array $b, string $style, string $ask): string
{
    $bits = [];
    if ($b['setting']['locale'] !== '') $bits[] = $b['setting']['locale'];
    if ($b['setting']['era'] !== '') {
        $bits[] = 'period-true to ' . $b['setting']['era'] . ', nothing anachronistic in earshot';
    }
    $at = 'the sound of ' . $b['name'];
    if ($b['kind'] !== '') $at .= ', ' . (preg_match('/^[aeiou]/i', $b['kind']) ? 'an ' : 'a ') . $b['kind'];
    $at .= match ($b['phase']) {
        'morning' => ', in the morning', 'afternoon' => ', in the afternoon',
        'evening' => ', in the evening', 'night'     => ', at night', default => '',
    };
    $bits[] = $at;
    if ($b['dark'])      $bits[] = 'the place has gone dark and stands unkept';
    elseif (!$b['open']) $bits[] = 'closed at this hour';
    if ($b['weather'] !== '') $bits[] = rtrim($b['weather'], '.');

    $bits[] = match ((int)$b['heard']) {
        0       => 'nobody here, only the room itself',
        1       => $b['voiced'] ? 'one person here, talking now and then' : 'one person here, busy and not talking',
        default => 'several people here, a murmur of talk',
    };
    // Voices are texture. A machine that rendered words you could make out
    // would be writing dialogue nobody wrote.
    if ($b['voiced']) $bits[] = 'voices as texture only, no words that can be made out';

    $bits[] = $b['vantage'] === 'establishing'
        ? 'the whole place as it sounds, from no one spot'
        : 'heard from where you stand, just inside the door';
    if ($style !== '') $bits[] = rtrim($style, '.');
    if ($ask !== '')   $bits[] = rtrim($ask, '.');

    // THE STRUCTURAL CAP, last, exactly where the look brief puts its own.
    $bits[] = $b['minor'] ? XERIC_SOUND_FLOOR_FRAMING : match ($b['rating']) {
        'sfw', 'pg' => 'wholesome everyday sound, nothing suggestive',
        'teen'      => 'everyday sound, nothing explicit',
        default     => 'sound in keeping with the world\'s adult rating',
    };
    return implode('. ', array_map(fn($s) => rtrim($s, '. '), $bits)) . '.';
}

/**
 * What the room SOUNDS like, hashed — and prefixed 'sound:', so a sound can
 * never be filed, served or mistaken as a look, and the one (key, fingerprint)
 * index holds both channels without a second one. In: the place (with its
 * kind and the era and locale the paragraph is written in), open/dark, the
 * weather line, the part of the day, the headcount as a doorway hears it
 * (nobody, one, several), whether the one is talking, the vantage, the style
 * and the ask — and the rating, because the age rule must never be undone by
 * a cache hit: a room rendered for adults is a different row from the same
 * room with a child in it. Out: the minute, and every name. Three people and
 * five are the same murmur, rendered once.
 */
function xeric_sound_fingerprint(array $b, string $style, string $ask = ''): string
{
    return 'sound:' . sha1(json_encode([
        'key' => $b['key'], 'kind' => $b['kind'],
        'era' => $b['setting']['era'], 'locale' => $b['setting']['locale'],
        'open' => $b['open'], 'dark' => $b['dark'], 'weather' => $b['weather'], 'phase' => $b['phase'],
        'heard' => $b['heard'], 'voiced' => $b['voiced'], 'vantage' => $b['vantage'],
        'style' => $style, 'ask' => $ask, 'rating' => $b['rating'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ---------------------------------------------------------------------------
// The machine
// ---------------------------------------------------------------------------

/**
 * The scene machine, if this install has one. Null is dormant, and dormant is
 * the shipping state and never an error.
 *
 * Read in order: the web layer's resolver (the machines screen, read at CALL
 * time so a worker under a forced session sees that session's choice; false
 * means "explicitly none" and stops here), then XERIC_SCENE_BASE / _KEY /
 * _KIND / _AUTH, then — the fallback — the image machine with kind 'image'. A still
 * of the room from where you stand is a legitimate viewport, and an owner who
 * wired up pictures has wired up this too without being asked twice.
 */
function xeric_scene_endpoint(): ?array
{
    $fn = xeric_scene_source();
    if ($fn !== null) {
        $ep = $fn();
        if (is_array($ep) && trim((string)($ep['base'] ?? '')) !== '') {
            return $ep + ['kind' => 'generic'];
        }
        if (is_array($ep) && isset($ep['stub']) && is_callable($ep['stub'])) return $ep + ['kind' => 'stub'];
        if ($ep === false) return null;
    }
    $base = trim((string)(getenv('XERIC_SCENE_BASE') ?: ''));
    if ($base !== '') {
        $kind = strtolower(trim((string)(getenv('XERIC_SCENE_KIND') ?: 'generic')));
        return ['base' => $base, 'key' => (string)(getenv('XERIC_SCENE_KEY') ?: ''),
                'kind' => in_array($kind, ['image', 'generic'], true) ? $kind : 'generic',
                // Vendors disagree about where the key goes: 'bearer' is
                // Authorization: Bearer, anything else is the header's name.
                'auth' => trim((string)(getenv('XERIC_SCENE_AUTH') ?: 'bearer'))];
    }
    $img = xeric_image_endpoint();
    if ($img === null) return null;
    // The image machine's own dialect (a1111 and friends) rides as image_kind;
    // this file speaks only the common images shape to it.
    return ['image_kind' => (string)($img['kind'] ?? '')] + ['kind' => 'image'] + $img;
}

/** The resolver seam, set once by the web layer, like xeric_image_source. */
function xeric_scene_source($fn = null): ?callable
{
    static $src = null;
    if ($fn !== null) $src = $fn;
    return $src;
}

/**
 * IS the scene machine answering? The same detection xeric_image_up does —
 * a GET on the base, short timeout, never a throw — because a configured but
 * absent machine should read as "no viewport here", not as a broken frame.
 */
function xeric_scene_up(?array $endpoint = null, int $timeout = 3): bool
{
    $endpoint ??= xeric_scene_endpoint();
    if ($endpoint === null) return false;
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) return true;
    if (trim((string)($endpoint['base'] ?? '')) === '') return false;
    return xeric_image_up($endpoint, $timeout);
}

/** Does rendering here spend money? xeric_image_costly's one bit, for scenes. */
function xeric_scene_costly(?array $endpoint = null): bool
{
    $endpoint ??= xeric_scene_endpoint();
    if ($endpoint === null) return false;
    return xeric_image_costly($endpoint);
}

/**
 * The key, in whichever header this machine wants it. 'bearer' (the default)
 * is Authorization: Bearer; anything else is taken as the header's own name
 * (x-api-key and friends) — and a name that is not a header name is bearer,
 * because a config typo should not become header injection.
 */
function xeric_scene_auth_headers(array $endpoint): array
{
    $key = (string)($endpoint['key'] ?? '');
    if ($key === '') return [];
    $how = trim((string)($endpoint['auth'] ?? 'bearer'));
    if ($how === '' || strtolower($how) === 'bearer' || !preg_match('/^[A-Za-z0-9-]+$/', $how)) {
        return ['Authorization: Bearer ' . $key];
    }
    return [$how . ': ' . $key];
}

/**
 * Which channels this machine can be asked for, in this world. An image
 * machine draws and cannot make a sound; a world that turned its sound off
 * has none; a world that turned scenes off has nothing. Rows queued for a
 * channel nobody here can make wait UNTRIED — no try is burned on a machine
 * that was never going to answer — and the day one that can is chosen, they
 * are already in the queue.
 *
 * @return string[] a subset of ['look', 'sound']
 */
function xeric_scene_channels(array $t, ?array $endpoint, ?PDO $db = null): array
{
    $cfg = xeric_scene_config($t);
    if (!$cfg['enabled'] || $endpoint === null) return [];
    $out = ['look'];
    if ($cfg['sound']['enabled'] && strtolower((string)($endpoint['kind'] ?? '')) !== 'image'
        && !($db !== null && xeric_scene_sound_unsupported($db, $endpoint))) $out[] = 'sound';
    return $out;
}

/** Which machine this is, for remembering what it said it cannot do: its address, or 'stub'. */
function xeric_scene_machine_id(array $endpoint): string
{
    return rtrim(trim((string)($endpoint['base'] ?? '')), '/') ?: 'stub';
}

/**
 * Has THIS machine said it cannot make sound? DETECTION, NOT CONFIGURATION:
 * nobody declares a machine deaf. The reaper writes the mark the first time a
 * generic machine answers a sound with a clear "cannot" (a 4xx naming no
 * asset, or a picture where a sound was asked for), and from then on the
 * sound rows wait untried, as they do for an image machine. The mark names
 * the machine it is about, so choosing another machine IS clearing it — a
 * mark for some other address is stale and is wiped as it is read — and a
 * sound that does arrive from this one wipes it too.
 */
function xeric_scene_sound_unsupported(PDO $db, array $endpoint): bool
{
    $mark = (string)(xeric_world_state_get($db, 'scene.sound.unsupported') ?? '');
    if ($mark === '') return false;
    if ($mark === xeric_scene_machine_id($endpoint)) return true;
    xeric_world_state_delete($db, 'scene.sound.unsupported');
    return false;
}

/** May this machine be shown what it drew before? Not an images/generations call: it has nowhere to put one. */
function xeric_scene_takes_reference(array $endpoint): bool
{
    return strtolower((string)($endpoint['kind'] ?? '')) !== 'image';
}

/**
 * Render one scene, or ask after one already submitted. THE ADAPTER — the one
 * function that speaks to a scene machine. Throws on nothing usable; the
 * reaper counts that as a try, not a catastrophe.
 *
 * Kinds of ENDPOINT:
 *
 *   'image'   — the common images shape xeric_image_render speaks: POST
 *               {base}/v1/images/generations {prompt, seed, n:1,
 *               response_format:'b64_json'}; the first data[].b64_json is the
 *               still. Seed is the place's INTERIOR seed: the room, not its
 *               street face. Always synchronous; comes back kind 'image'. It
 *               takes no reference and makes no sound, and is never asked to.
 *
 *   'generic' — THE CONTRACT for anyone writing an adapter to a machine that
 *               makes rooms. No vendor is named here and none needs to be.
 *
 *     REQUEST   POST {base}/v1/scenes, Content-Type: application/json, the
 *               key in the configured auth header (xeric_scene_auth_headers),
 *               the body built by xeric_scene_body:
 *                 {
 *                   "channel":     "look" | "sound",
 *                   "kind_wanted": ["pano","image"] for a look, preference
 *                                  order (media.scene.kinds, else this);
 *                                  ["audio"] for a sound,
 *                   "prompt":      the composed paragraph, rating clause last,
 *                   "negative":    media.scene.negative, a look's, only when set,
 *                   "brief":       the structured brief — observables only,
 *                                  bearings included for a machine that lays
 *                                  a room out,
 *                   "seed":        the place's interior seed (a sound's own),
 *                   "fingerprint": stable across visits that look alike,
 *                   "size":        [4096, 2048], only when a pano is wanted,
 *                   "quality":     "draft" | "full",
 *                   "timeout_s":   how long Xeric will wait on this call,
 *                   "reference_image":       {mime, b64} — the newest picture
 *                                            of this room, only when one exists,
 *                   "reference_fingerprint": which look that picture was of
 *                 }
 *
 *     ANSWER    { "kind": "image"|"pano"|"splat"|"glb"|"viewer"|"audio",
 *                 "mime": "...", and EXACTLY ONE of "b64" | "url",
 *                 "extras": [...] optional, "meta": {...} optional,
 *                 "usage": {...} optional, reaches the meter }
 *               image/pano/splat/glb/audio: a url is fetched AT ONCE with the
 *               same auth header (vendor links expire) and the bytes are
 *               stored; Xeric keeps no link to anybody's bucket. viewer: the
 *               url IS the scene (a hosted explorable room) and is stored as
 *               a string, no bytes. audio must be one of audio/mpeg,
 *               audio/ogg, audio/wav, audio/webm, audio/mp4, and is the only
 *               kind a sound may come back as. An answer that names no kind
 *               is taken as an image for a look and audio for a sound. `meta`
 *               (seed used, cost, the vendor's id) is recorded on the row as
 *               it came.
 *
 *     STREAM    { "kind": "stream", "session": {"id", "url", "protocol",
 *                 "expires_at", "max_seconds", "fallback"?}, "meta": {...} }
 *               — the answer to a channel "stream" request only, which
 *               engine/stream.php sends when the owner presses "go live" and
 *               at no other time. A live session is stored as a session
 *               (engine/stream.php's own table), never as bytes and never as
 *               a scene_jobs row: a look answered with a stream is refused
 *               here, so the reaper and the heart cannot start one.
 *
 *     CANNOT    { "error": "...", "cannot": true }, any status — the machine
 *               saying plainly that it does not do what was asked (today:
 *               does not stream). A 404, 405, 415 or 501 to a stream request
 *               means the same. Any OTHER refusal of a stream — 401, 403,
 *               408, 409, 429, 5xx — is "not now": its `error` is shown to
 *               the person in the machine's own words, and nothing is
 *               written down, so the next "go live" asks again.
 *
 *     LATER     { "status": "pending", "job": "<id>", "retry_after_s": n },
 *               usually as a 202: Xeric does not block. The reaper records
 *               the job and asks again on its next pass with
 *               GET {base}/v1/scenes/{job}, which answers either shape above.
 *
 *   'stub'    — $endpoint['stub']($tag, $brief, $opts) => {bytes, mime,
 *               kind?, url?, meta?} or {pending:true, job}; $tag is 'scene'
 *               for a look and 'sound' for a sound, $opts['job'] is set when
 *               it is being asked after a job, $opts['reference'] when a
 *               picture rides along. The tests' seam.
 *
 * $opts: channel, kinds, quality, negative (the reaper passes them from
 * media.scene), reference (array{bytes,mime,fingerprint}), timeout (default
 * 300 — rooms are slower than faces), job (ask after it).
 *
 * @return array{bytes:string,mime:string,usage:array,meta:array,kind:string,
 *               url:?string,extras:array,pending:?string,cannot:bool,session:?array}
 *         pending is the remote job id when the machine said "later", in
 *         which case bytes is '' and nothing else is meaningful. cannot is a
 *         sound the machine said plainly it cannot make (see
 *         xeric_scene_sound_unsupported) — not a failure, an answer.
 */
function xeric_scene_render(array $endpoint, array $brief, array $opts = []): array
{
    $job     = trim((string)($opts['job'] ?? ''));
    $channel = (string)($opts['channel'] ?? $brief['channel'] ?? 'look') === 'sound' ? 'sound' : 'look';
    $want    = $channel === 'sound' ? 'audio' : 'image';   // what an answer naming no kind is taken to be

    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
        $out = ($endpoint['stub'])($channel === 'sound' ? 'sound' : 'scene', $brief, $opts);
        if (!is_array($out)) throw new RuntimeException('scene: the stub returned no scene');
        return xeric_scene_answer($out, [], 0, true, $want, $endpoint);
    }

    $base = rtrim((string)($endpoint['base'] ?? ''), '/');
    if ($base === '') throw new RuntimeException('scene: no scene machine is configured');
    $auth    = xeric_scene_auth_headers($endpoint);
    $timeout = (int)($opts['timeout'] ?? 300);

    if (strtolower((string)($endpoint['kind'] ?? 'generic')) === 'image') {
        // An images call makes a still now or not at all: it has no job to
        // ask after (a job begun on another machine is a try, not a poll)
        // and no way to make a sound.
        if ($job !== '')          throw new RuntimeException('scene: an image machine has no job to ask after');
        if ($channel === 'sound') throw new RuntimeException('scene: an image machine cannot make a sound');
        $j = xeric_scene_http('POST', $base . '/v1/images/generations', $auth, [
            'prompt' => (string)($brief['prompt'] ?? ''),
            'seed'   => (int)($brief['seeds']['place']['interior'] ?? 0),
            'n' => 1, 'response_format' => 'b64_json',
        ], (int)($opts['timeout'] ?? 120));
        $bytes = base64_decode((string)($j['data'][0]['b64_json'] ?? ''), true);
        if ($bytes === false || $bytes === '') throw new RuntimeException('scene: the image machine answered with no image');
        return ['bytes' => $bytes, 'mime' => 'image/png', 'usage' => (array)($j['usage'] ?? []),
                'meta' => [], 'kind' => 'image', 'url' => null, 'extras' => [], 'pending' => null,
                'cannot' => false, 'session' => null];
    }

    if ($job !== '') {
        $j = xeric_scene_http('GET', $base . '/v1/scenes/' . rawurlencode($job), $auth, null, $timeout);
        return xeric_scene_answer($j + ['job' => $job], $auth, $timeout, false, $want, $endpoint);
    }

    $j = xeric_scene_http('POST', $base . '/v1/scenes', $auth,
                          xeric_scene_body($brief, ['channel' => $channel, 'timeout' => $timeout] + $opts), $timeout);
    return xeric_scene_answer($j, $auth, $timeout, false, $want, $endpoint);
}

/**
 * The generic request's body — the contract above, as one pure function, so
 * what an adapter receives is testable without a machine to receive it.
 */
function xeric_scene_body(array $brief, array $opts = []): array
{
    $channel = (string)($opts['channel'] ?? $brief['channel'] ?? 'look') === 'sound' ? 'sound' : 'look';
    $kinds   = array_values(array_map('strval', (array)($opts['kinds'] ?? [])))
            ?: ($channel === 'sound' ? ['audio'] : ['pano', 'image']);
    $body = [
        'channel'     => $channel,
        'kind_wanted' => $kinds,
        'prompt'      => (string)($brief['prompt'] ?? ''),
        'brief'       => array_diff_key($brief, ['layout' => true]),   // the layout rides once, below
        'seed'        => (int)($brief['seed'] ?? $brief['seeds']['place']['interior'] ?? 0),
        'fingerprint' => (string)($brief['fingerprint'] ?? ''),
        'quality'     => ($opts['quality'] ?? 'full') === 'draft' ? 'draft' : 'full',
        'timeout_s'   => (int)($opts['timeout'] ?? 300),
    ];
    if (trim((string)($opts['negative'] ?? '')) !== '') $body['negative'] = trim((string)$opts['negative']);
    if (in_array('pano', $kinds, true)) $body['size'] = [4096, 2048];
    $ref = $opts['reference'] ?? null;
    if (is_array($ref) && is_string($ref['bytes'] ?? null) && $ref['bytes'] !== '') {
        $body['reference_image']       = ['mime' => xeric_scene_mime($ref['mime'] ?? ''),
                                          'b64'  => base64_encode($ref['bytes'])];
        $body['reference_fingerprint'] = (string)($ref['fingerprint'] ?? '');
    }
    // The room as a box, always when the brief has one; its two pictures only
    // when the world asked (media.scene.layout_images), because they cost bandwidth.
    if (is_array($brief['layout'] ?? null)) {
        $body['layout'] = $brief['layout'];
        if (!empty($opts['layout_images'])) {
            // One cast, both pictures: they are the same rays read two ways.
            $png = xeric_layout_pngs($brief['layout']);
            $body['layout_depth'] = ['mime' => 'image/png', 'b64' => base64_encode($png['depth'])];
            $body['layout_seg']   = ['mime' => 'image/png', 'b64' => base64_encode($png['seg'])];
        }
    }
    return $body;
}

/**
 * One answer, read. Shared by the live path and the stub so the two cannot
 * disagree about what a scene IS; $stub allows raw `bytes` (the stub's
 * shortcut) where the wire only ever carries b64 or a url. $want is the kind
 * an answer that names none is taken to be; $endpoint is the machine that
 * answered, which a url in the answer is checked against before it is
 * fetched (xeric_scene_url_ok).
 *
 * A CLEAR "CANNOT" for a sound — an HTTP 4xx (`_http`, as xeric_scene_http
 * records it, or a stub's own) whose body names no asset, or a picture where
 * a sound was asked for — comes back as `cannot`, not as a throw: it is the
 * machine telling us what it is, and a try is for things that might go
 * better next time. For a look the same 4xx is an ordinary failed try, and
 * its note carries the machine's own `error` words.
 *
 * For a STREAM, cannot is narrower (XERIC_SCENE_STREAM_CANNOT, or the body
 * {error, cannot: true}); any other non-2xx throws with the machine's words,
 * and a throw is never written down against the machine.
 */
function xeric_scene_answer(array $j, array $auth, int $timeout, bool $stub, string $want = 'image',
                            array $endpoint = []): array
{
    $res = ['bytes' => '', 'mime' => '', 'usage' => (array)($j['usage'] ?? []),
            'meta' => is_array($j['meta'] ?? null) ? $j['meta'] : [],
            'kind' => '', 'url' => null, 'extras' => array_values((array)($j['extras'] ?? [])),
            'pending' => null, 'cannot' => false, 'session' => null];

    $http  = (int)($j['_http'] ?? 200);
    $asset = isset($j['b64']) || isset($j['url']) || isset($j['bytes']) || isset($j['job']) || isset($j['session']);
    $words = xeric_scene_words($j);
    // A STREAM REQUEST IS READ STRICTLY, because "cannot" is written down
    // against the machine and takes "go live" away. Only a route that is not
    // there or a shape it does not take (XERIC_SCENE_STREAM_CANNOT), or the
    // machine saying so in so many words ({error, cannot: true}), is cannot.
    // Every other refusal — a key it did not like (401/403), a timeout (408),
    // a session already running (409), too many (429), busy or broken (5xx,
    // 503 included) — is the machine saying not now, in its own words, and is
    // never cached: the next press asks again.
    if ($want === 'stream' && ($http >= 300 || ($j['cannot'] ?? null) === true)) {
        if (($j['cannot'] ?? null) === true || (in_array($http, XERIC_SCENE_STREAM_CANNOT, true) && !$asset)) {
            $res['cannot'] = true;
            return $res;
        }
        throw new RuntimeException('stream: the machine would not start a live view just now (HTTP ' . $http
                                   . ($words !== '' ? ': ' . $words : '') . '); press go live again in a moment');
    }
    if ($http >= 400 && !$asset) {
        if ($want === 'audio' && $http < 500) { $res['cannot'] = true; return $res; }
        throw new RuntimeException('scene: the machine refused the request (HTTP ' . $http . ')'
                                   . ($words !== '' ? ': ' . $words : ''));
    }

    if (!empty($j['pending']) || strtolower((string)($j['status'] ?? '')) === 'pending') {
        $id = trim((string)($j['job'] ?? ''));
        if ($id === '') throw new RuntimeException('scene: the machine said later but named no job');
        $res['pending'] = $id;
        return $res;
    }

    $kind = strtolower(trim((string)($j['kind'] ?? $want)));
    if (!in_array($kind, XERIC_SCENE_KINDS, true)) {
        throw new RuntimeException('scene: the machine made a kind of scene this engine does not know');
    }
    if ($want === 'audio' && $kind !== 'audio') { $res['cannot'] = true; return $res; }
    // A LIVE VIEW IS ASKED FOR, NEVER FALLEN INTO. Only engine/stream.php asks
    // with $want 'stream' (an owner pressed "go live"); a stream offered in
    // answer to a look the reaper or the heart sent is refused, because a
    // session that bills by the second must never start with nobody watching.
    // And a stream request answered with anything else is the machine saying
    // what it is: it does not stream — an answer, not a failed try.
    if ($kind === 'stream' && $want !== 'stream') {
        throw new RuntimeException('scene: the machine answered with a live stream, which only "go live" may start');
    }
    if ($want === 'stream') {
        if ($kind !== 'stream') { $res['cannot'] = true; return $res; }
        $res['kind']    = 'stream';
        $res['mime']    = xeric_scene_default_mime('stream');
        $res['session'] = is_array($j['session'] ?? null) ? $j['session'] : null;
        if ($res['session'] === null) throw new RuntimeException('scene: a stream must name its session');
        return $res;
    }
    $res['kind'] = $kind;
    $b64 = is_string($j['b64'] ?? null) && $j['b64'] !== '' ? $j['b64'] : null;
    $url = is_string($j['url'] ?? null) && $j['url'] !== '' ? $j['url'] : null;
    $raw = $stub && isset($j['bytes']) ? (string)$j['bytes'] : null;
    if ((int)($b64 !== null) + (int)($url !== null) + (int)($raw !== null) !== 1) {
        throw new RuntimeException('scene: the machine must answer with exactly one of b64 or url');
    }
    // http(s) only, everywhere: a reply naming file:// or php:// is not a
    // scene, it is a request to read this disk — and a viewer link that is
    // javascript: is not a link.
    if ($url !== null && !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new RuntimeException('scene: the machine answered with a url that is not http');
    }
    if ($kind === 'audio') {
        // Five sound types and no sixth: what a viewport is told it is
        // holding must be something a page can play.
        $res['mime'] = xeric_scene_audio_mime($j['mime'] ?? 'audio/mpeg');
        if ($res['mime'] === '') throw new RuntimeException('scene: the machine answered with a sound this engine will not keep');
    } else {
        $res['mime'] = xeric_scene_mime($j['mime'] ?? xeric_scene_default_mime($kind));
    }

    if ($kind === 'viewer') {
        if ($url === null) throw new RuntimeException('scene: a viewer scene must be a url');
        $res['url'] = $url;
        return $res;
    }
    if ($raw !== null) {
        $res['bytes'] = $raw;
    } elseif ($b64 !== null) {
        $bytes = base64_decode($b64, true);
        if ($bytes === false) throw new RuntimeException('scene: the machine answered with bad base64');
        $res['bytes'] = $bytes;
    } else {
        $res['bytes'] = xeric_scene_fetch($url, $endpoint, $auth, $timeout);
    }
    if ($res['bytes'] === '') throw new RuntimeException('scene: the machine answered with an empty scene');
    return $res;
}

/**
 * The statuses that mean a machine CANNOT stream, rather than will not just
 * now: no such route (404), not that verb (405), not that body (415), not
 * built (501). Everything else non-2xx is transient (xeric_scene_answer).
 */
const XERIC_SCENE_STREAM_CANNOT = [404, 405, 415, 501];

/**
 * The machine's own words for a refusal — `error` as a string, or the
 * `error.message` some vendors nest it in — as one line a person can be shown:
 * control characters gone, whitespace folded, 200 characters at most. '' when
 * it said nothing.
 */
function xeric_scene_words(array $j): string
{
    $e = $j['error'] ?? null;
    if (is_array($e)) $e = $e['message'] ?? null;
    if (!is_string($e)) return '';
    $e = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1f\x7f]/u', ' ', $e)));
    return mb_strlen($e) > 200 ? rtrim(mb_substr($e, 0, 199)) . '…' : $e;
}

/**
 * One JSON round trip to the machine. The status rides back as `_http`, so a
 * plain "no" (a 4xx or 5xx) can be told from a broken answer; a refusal with
 * no JSON is still a "no", anything else that is not a JSON object throws. Redirects are
 * NOT followed — the key goes to the address the owner typed and nowhere a
 * Location header names; a machine behind a redirect is configured at its
 * final address.
 */
function xeric_scene_http(string $method, string $url, array $auth, ?array $body, int $timeout): array
{
    $headers = array_merge(['Accept: application/json'], $body !== null ? ['Content-Type: application/json'] : [], $auth);
    $http = ['method' => $method, 'header' => implode("\r\n", $headers),
             'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0];
    if ($body !== null) $http['content'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$raw, $status] = xeric_scene_get($url, $http);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        // A refusal with no body is still a refusal, and its status is the
        // whole of what it said (xeric_scene_answer reads it).
        if ($status >= 400) return ['_http' => $status];
        throw new RuntimeException('scene: the machine did not answer in JSON');
    }
    return ['_http' => $status] + $j;
}

/**
 * THE HTTP SEAM: every network call this file and engine/stream.php make
 * passes through xeric_scene_get, and a test may watch it. $fn(url, http)
 * returns [body, status] to answer in the network's place, or null to let the
 * call through; false clears it. Never set in production.
 */
function xeric_scene_http_seam($fn = null): ?callable
{
    static $seam = null;
    if ($fn === false) $seam = null;
    elseif ($fn !== null) $seam = $fn;
    return $seam;
}

/** file_get_contents with the status line read back: [body or false, status or 0]. */
function xeric_scene_get(string $url, array $http): array
{
    if (($seam = xeric_scene_http_seam()) !== null) {
        $r = $seam($url, $http);
        if (is_array($r)) return [$r[0] ?? false, (int)($r[1] ?? 0)];
    }
    $raw  = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    $hdrs = function_exists('http_get_last_response_headers')
          ? (array)(http_get_last_response_headers() ?? [])
          : (array)($http_response_header ?? []);
    $status = 0;
    foreach ($hdrs as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $m)) $status = (int)$m[1];
    }
    return [$raw, $status];
}

/**
 * Where a machine's answer may send us to fetch its bytes from. A url is an
 * instruction FROM the machine, and a third-party machine, or one that has
 * been taken over, could name the cloud metadata address or the router's
 * admin page and have this engine read it into a "splat". So: http(s) only;
 * and the host is either the machine's own (a local box handing back its own
 * file is the ordinary case) or a name that resolves to no address in a
 * private, loopback, link-local, carrier-grade, multicast or reserved range
 * — every address it resolves to, v4 and v6, and a name that does not
 * resolve at all is refused. What is left is the DNS-rebinding race between
 * this check and the fetch, which is why the fetch follows no redirect and
 * carries no key off the machine's own host.
 */
function xeric_scene_url_ok(string $url, array $endpoint): bool
{
    if (!in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) return false;
    $host = xeric_scene_host($url);
    if ($host === '') return false;
    $own = xeric_scene_host((string)($endpoint['base'] ?? ''));
    if ($own !== '' && $host === $own) return true;

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = @gethostbynamel($host) ?: [];
        foreach ((array)(@dns_get_record($host, DNS_AAAA) ?: []) as $r) {
            if (isset($r['ipv6'])) $ips[] = (string)$r['ipv6'];
        }
    }
    if ($ips === []) return false;
    foreach ($ips as $ip) if (xeric_scene_ip_private($ip)) return false;
    return true;
}

/** A url's host, lowercased, IPv6 brackets off; '' when there is none. */
function xeric_scene_host(string $url): string
{
    return strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
}

/** Addresses no machine's answer may point this engine at. */
const XERIC_SCENE_PRIVATE_NETS = [
    '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
    '192.168.0.0/16', '224.0.0.0/4', '240.0.0.0/4',
    '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
];

/** Is this address in one of those ranges? Unparseable is yes: fail closed. IPv4-mapped v6 is read as its v4. */
function xeric_scene_ip_private(string $ip): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) return true;
    if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $bin = substr($bin, 12);
    foreach (XERIC_SCENE_PRIVATE_NETS as $cidr) {
        [$net, $bits] = explode('/', $cidr);
        $nb = inet_pton($net);
        if (strlen($nb) !== strlen($bin)) continue;
        $bits  = (int)$bits;
        $whole = intdiv($bits, 8);
        if (substr($bin, 0, $whole) !== substr($nb, 0, $whole)) continue;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xff << (8 - $rest)) & 0xff;
        if ((ord($bin[$whole]) & $mask) === (ord($nb[$whole]) & $mask)) return true;
    }
    return false;
}

/**
 * The bytes behind an answer's url, fetched once, now (vendor links expire),
 * and only if xeric_scene_url_ok says the address may be read. The key goes
 * along only to the machine's own host, redirects are not followed, and
 * anything but a 2xx is a failure — a refusal names the host it refused.
 */
function xeric_scene_fetch(string $url, array $endpoint, array $auth, int $timeout): string
{
    $host = xeric_scene_host($url);
    if (!xeric_scene_url_ok($url, $endpoint)) {
        throw new RuntimeException('scene: refused to fetch from ' . ($host !== '' ? $host : 'that url')
            . ', which is neither the machine nor a public address');
    }
    $own = xeric_scene_host((string)($endpoint['base'] ?? ''));
    [$bytes, $status] = xeric_scene_get($url, [
        'method' => 'GET', 'header' => implode("\r\n", ($own !== '' && $host === $own) ? $auth : []),
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
    ]);
    if (!is_string($bytes) || $status < 200 || $status > 299) {
        throw new RuntimeException('scene: the scene could not be fetched from ' . $host);
    }
    return $bytes;
}

/**
 * Every kind a machine may answer with. 'stream' is a live session's, and is
 * read only for a stream request (xeric_scene_answer); the rest are kept.
 */
const XERIC_SCENE_KINDS = ['image', 'pano', 'splat', 'glb', 'viewer', 'audio', 'stream'];

/** A media type as a machine said it, or octet-stream when it said nonsense. */
function xeric_scene_mime($m): string
{
    $m = trim(explode(';', strtolower(trim((string)$m)))[0]);
    return preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $m) ? $m : 'application/octet-stream';
}

/** The sounds this engine keeps. */
const XERIC_SCENE_AUDIO_MIMES = ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/mp4'];

/** A sound's type, with the common aliases folded in; '' for anything this engine will not keep. */
function xeric_scene_audio_mime($m): string
{
    $m = xeric_scene_mime($m);
    $m = ['audio/mp3' => 'audio/mpeg', 'audio/x-wav' => 'audio/wav', 'audio/wave' => 'audio/wav',
          'audio/vnd.wave' => 'audio/wav', 'audio/x-m4a' => 'audio/mp4', 'audio/m4a' => 'audio/mp4'][$m] ?? $m;
    return in_array($m, XERIC_SCENE_AUDIO_MIMES, true) ? $m : '';
}

function xeric_scene_default_mime(string $kind): string
{
    return match ($kind) {
        'image', 'pano' => 'image/png',
        'glb'           => 'model/gltf-binary',
        'viewer'        => 'text/html',
        'audio'         => 'audio/mpeg',
        'stream'        => 'application/x-xeric-stream',
        default         => 'application/octet-stream',
    };
}

/** The file extension for what a scene is. Unknown bytes are kept as .bin. */
function xeric_scene_ext(string $mime, string $kind = ''): string
{
    return match ($mime) {
        'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/webp' => '.webp',
        'video/mp4' => '.mp4', 'video/webm' => '.webm',
        'model/gltf-binary' => '.glb', 'model/gltf+json' => '.gltf',
        'application/ply', 'model/ply' => '.ply',
        'audio/mpeg' => '.mp3', 'audio/ogg' => '.ogg', 'audio/wav' => '.wav',
        'audio/webm' => '.weba', 'audio/mp4' => '.m4a',
        default => match ($kind) { 'splat' => '.splat', 'glb' => '.glb', default => '.bin' },
    };
}

// ---------------------------------------------------------------------------
// The queue, and the reaper that drains it
// ---------------------------------------------------------------------------

/** The jobs, oldest first; $status and $channel narrow, null is everything. */
function xeric_scene_jobs(PDO $db, ?string $status = null, ?string $channel = null): array
{
    $where = [];
    $args  = [];
    if ($status !== null)  { $where[] = 'status = ?';  $args[] = $status; }
    if ($channel !== null) { $where[] = 'channel = ?'; $args[] = $channel; }
    $st = $db->prepare('SELECT * FROM scene_jobs' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
                     . ' ORDER BY id');
    $st->execute($args);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows;
}

/**
 * The kind a look is filed under (scene_jobs.want): '' for the template's
 * first kind — the class every row from before belongs to, and what every
 * caller that names no kind means — or the other kind itself. A word that is
 * not a look's kind is the template's first, never an error.
 */
function xeric_scene_want(array $t, string $kind): string
{
    $kind = strtolower(trim($kind));
    $cfg  = xeric_scene_config($t);
    if (!in_array($kind, ['pano', 'image', 'splat', 'glb', 'viewer'], true)) return '';
    return $kind === ($cfg['kinds'][0] ?? '') ? '' : $kind;
}

/** What a look filed under $want asks a machine for: that kind first, then the template's. */
function xeric_scene_kinds_for(array $cfg, string $want): array
{
    if ($want === '' || $want === 'audio') return $cfg['kinds'];
    return array_values(array_unique(array_merge([$want], $cfg['kinds'])));
}

/** A want as the table keeps it: one of the look kinds, or '' (the template's first). */
function xeric_scene_want_word(string $want): string
{
    $want = strtolower(trim($want));
    return in_array($want, ['pano', 'image', 'splat', 'glb', 'viewer'], true) ? $want : '';
}

/**
 * The one job for this room looking (or sounding) exactly like this, done or
 * not, asked as $want ('' the template's first kind: xeric_scene_want).
 */
function xeric_scene_of(PDO $db, string $key, string $fingerprint, string $channel = 'look', string $want = ''): ?array
{
    $st = $db->prepare('SELECT * FROM scene_jobs WHERE key = ? AND fingerprint = ? AND channel = ? AND want = ? LIMIT 1');
    $st->execute([$key, $fingerprint, $channel, xeric_scene_want_word($want)]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows ? $rows[0] : null;
}

/**
 * The most recent FINISHED view of a place, whatever it looked like then. A
 * stale view is better than a blank one while the fresh one renders — and the
 * caller says it is stale (its fingerprint is right there to compare). `kind`
 * rides on every row so the viewer can be chosen without sniffing bytes; the
 * channel keeps a sound out of a picture frame.
 */
function xeric_scene_latest(PDO $db, string $key, string $channel = 'look', string $want = ''): ?array
{
    $st = $db->prepare("SELECT * FROM scene_jobs WHERE key = ? AND channel = ? AND want = ? AND status = 'done'
                        ORDER BY done_at DESC, id DESC LIMIT 1");
    $st->execute([$key, $channel, xeric_scene_want_word($want)]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows ? $rows[0] : null;
}

/**
 * The room through its day: the newest finished look for every light it has
 * been seen in, keyed by light, in the order a day goes through them
 * (xeric_scene_lights). Only lights that have a view are keyed — a strip with
 * gaps is the client's to draw — and views from before the light was written
 * down have none and sit this out.
 *
 * @return array<string,array> light => row
 */
function xeric_scene_day(PDO $db, string $key, string $want = ''): array
{
    $st = $db->prepare("SELECT * FROM scene_jobs WHERE key = ? AND channel = 'look' AND want = ? AND status = 'done'
                        AND light != '' ORDER BY done_at DESC, id DESC");
    $st->execute([$key, xeric_scene_want_word($want)]);
    $newest = [];
    foreach ($st->fetchAll() as $row) $newest[(string)$row['light']] ??= $row;   // newest first: first seen wins
    $st->closeCursor();
    $out = [];
    foreach (xeric_scene_lights() as $l) if (isset($newest[$l])) $out[$l] = $newest[$l];
    return $out;
}

/**
 * File one brief, and say whether it was new. The channel is read off the
 * brief itself and must agree with its fingerprint (a sound's carries the
 * 'sound:' prefix, a look's never does), so no caller can file a sound under
 * a look by passing the wrong word. A look also writes down its light, which
 * is what the day strip reads.
 *
 * $want is the kind it is asked as ('' the template's first: xeric_scene_want);
 * a sound has none.
 *
 * @return array{id:int,added:bool}
 */
function xeric_scene_insert(PDO $db, array $brief, string $want = ''): array
{
    $none = ['id' => 0, 'added' => false];
    if (array_key_exists('enabled', $brief) && !$brief['enabled']) return $none;
    $key     = (string)($brief['key'] ?? '');
    $fp      = (string)($brief['fingerprint'] ?? '');
    $channel = (string)($brief['channel'] ?? 'look');
    if ($key === '' || $fp === '' || !in_array($channel, ['look', 'sound'], true)) return $none;
    if (($channel === 'sound') !== str_starts_with($fp, 'sound:')) return $none;

    $light = $channel === 'look' ? (string)($brief['light'] ?? '') : '';
    if (!in_array($light, xeric_scene_lights(), true)) $light = '';
    // Stamped with the rating it was ASKED under (xeric_state_rating): the
    // prompt is composed now and rendered as stored, so this is the rating the
    // picture is generated under, and a viewer below it is not shown it.
    // And with the layout it was asked with, for the same reason as the
    // prompt: what is rendered later is what was asked for now.
    $layout = $channel === 'look' && is_array($brief['layout'] ?? null)
            ? (string)json_encode($brief['layout'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    $want = $channel === 'look' ? xeric_scene_want_word($want) : '';
    $ins = $db->prepare('INSERT OR IGNORE INTO scene_jobs (key, fingerprint, channel, light, prompt, created_at, rating, layout, want)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$key, $fp, $channel, $light, (string)($brief['prompt'] ?? ''), xeric_state_time(), xeric_state_rating(),
                   $layout, $want]);
    if ($ins->rowCount() === 1) return ['id' => (int)$db->lastInsertId(), 'added' => true];
    $row = xeric_scene_of($db, $key, $fp, $channel, $want);
    return ['id' => $row !== null ? (int)$row['id'] : 0, 'added' => false];
}

/**
 * Ask for this room to be rendered (or heard). IDEMPOTENT: one row per (key,
 * fingerprint, want) — a panorama and a splat of one moment are two — so a client may ask on every glance and the table keeps one
 * row; the id comes back either way. 0 when the brief cannot be a job — a
 * world that turned scenes (or sound) off, a brief with no key or fingerprint
 * to file it under, or one whose channel and fingerprint disagree.
 */
function xeric_scene_enqueue(PDO $db, array $brief, string $want = ''): int
{
    return xeric_scene_insert($db, $brief, $want)['id'];
}

/** The most a previous view may weigh and still ride along with the next request. */
const XERIC_SCENE_REFERENCE_CAP = 8 * 1024 * 1024;

/**
 * The newest picture of this room, to show the machine what it drew before
 * (see DRIFT in the header). A finished LOOK with an image type and its bytes
 * on this disk, newest first: one too heavy to send (past $cap) or missing
 * from the disk is passed over for the one before it, five deep at most,
 * because conditioning on an older view of the same room still beats
 * conditioning on none. A sound, a splat, a viewer link — none of them is a
 * picture, and none rides along. $want null is a picture of the room asked as
 * any kind — a splat request is still helped by the panorama before it; a
 * string narrows to that class (xeric_scene_want).
 *
 * @return ?array{bytes:string,mime:string,fingerprint:string}
 */
function xeric_scene_reference(PDO $db, string $key, string $scenesDir, int $cap = XERIC_SCENE_REFERENCE_CAP,
                               ?string $rating = null, ?string $want = null): ?array
{
    $st = $db->prepare("SELECT fingerprint, file, mime, rating FROM scene_jobs
                        WHERE key = ? AND channel = 'look' AND status = 'done' AND file != ''
                          AND mime LIKE 'image/%'" . ($want !== null ? ' AND want = ?' : '')
                     . " ORDER BY done_at DESC, id DESC LIMIT 50");
    $st->execute($want !== null ? [$key, xeric_scene_want_word($want)] : [$key]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    // NEVER CONDITIONED ON A PICTURE ITS OWN VIEWER COULD NOT BE SHOWN: a view
    // stamped above the rating this render runs at is passed over, before the
    // five-deep walk counts it — a kids' headset's room is never drawn from an
    // adult one. No rating (an unclamped process) filters nothing.
    if ($rating !== null && $rating !== '') {
        $rows = array_values(array_filter($rows, fn($r) => xeric_state_shown((string)($r['rating'] ?? ''), $rating)));
    }
    foreach (array_slice($rows, 0, 5) as $row) {
        $path = rtrim($scenesDir, '/') . '/' . basename((string)$row['file']);
        // A worker lives for minutes and the same file can be rewritten under
        // it; PHP's stat cache would weigh the bytes that used to be there.
        clearstatcache(true, $path);
        $size = is_file($path) ? @filesize($path) : false;
        if ($size === false || $size === 0 || $size > $cap) continue;
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') continue;
        return ['bytes' => $bytes, 'mime' => (string)$row['mime'], 'fingerprint' => (string)$row['fingerprint']];
    }
    return null;
}

/**
 * The rating a render runs at, for the reference walk: the lowest of the ones
 * that are set — the row's stamp (whose request it was) and what it is drawn
 * at. '' when neither is.
 */
function xeric_scene_render_rating(string ...$ratings): string
{
    $low = '';
    foreach ($ratings as $r) {
        $r = strtolower(trim($r));
        if ($r === '') continue;
        if ($low === '' || xeric_rating_rank($r) < xeric_rating_rank($low)) $low = $r;
    }
    return $low;
}

/** The scene spend's sink — xeric_photo_meter's discipline, for rooms. */
function xeric_scene_meter($sink = null): ?callable
{
    static $fn = null;
    if ($sink !== null) $fn = $sink;
    return $fn;
}

/** How long a reaper's claim may sit before it is presumed dead, seconds. */
const XERIC_SCENE_CLAIM_TTL  = 1200;
/** How long a machine's "later" is believed before it counts as a failed try. */
const XERIC_SCENE_REMOTE_TTL = 1800;

/**
 * Drain scene jobs. Built exactly as xeric_photo_reap is — stale claims
 * reset first, the claim is the UPDATE, three tries and failed, every render
 * metered — minus the consent gate (see the header: the machine was the
 * consent), plus one thing photos never needed: a machine that says LATER.
 *
 * TWO KINDS OF WORK, in this order:
 *
 *   ASKING AFTER. A row that is 'working' with a remote_job is a scene the
 *   machine is still making. Every pass asks after every such row once (a GET
 *   is cheap and does not count against $limit) and never blocks on one.
 *   done_at holds the submit time while it waits and is not refreshed by
 *   asking, so the thirty-minute window is measured from the ask, not from
 *   the last poll; past it, the wait is a failed try and the row goes back
 *   to pending to be asked for afresh. Two reapers may ask after the same job
 *   at once; only the one whose finishing UPDATE lands writes the row or
 *   reaches the meter, and both would have written the same file name.
 *
 *   ASKING FOR. Up to $limit pending rows of a channel this machine can make
 *   (xeric_scene_channels; the rest wait untried). The brief is RE-COMPOSED
 *   at render time from the row's key and the world's current clock — the
 *   seeds and the structured remainder go to the machine fresh. But the row
 *   is what was asked for: if the room has changed since (somebody left, the
 *   light turned), the STORED prompt, fingerprint and layout are rendered anyway and
 *   a note says so, because a job keyed by one look that rendered another
 *   would file a picture under the wrong name. The changed room is another
 *   fingerprint, and the next glance asks for it. A look takes the newest
 *   picture of its room along (xeric_scene_reference) unless the machine is
 *   an images call, and the row's meta says which picture that was.
 *
 * Files land under $scenesDir as scene-<key>-<fp12><ext> (a sound as
 * sound-...); a 'viewer' scene has no file, only its url. $answering is the
 * caller's own word that the machine is up this pass — the heart asks once
 * per machine, not once per world — and null asks here.
 *
 * @return array{done:int,failed:int,notes:string[]}
 */
function xeric_scene_reap(array $t, PDO $db, string $scenesDir, ?array $endpoint = null, int $limit = 1,
                          ?bool $answering = null): array
{
    $out = ['done' => 0, 'failed' => 0, 'notes' => []];

    $cfg = xeric_scene_config($t);
    if (!$cfg['enabled']) {
        $out['notes'][] = 'scenes are turned off for this world';
        return $out;
    }

    // Stale claims first, so this pass can finish what a dead reaper dropped.
    // A room is slower than a face, so the window is twenty minutes, past the
    // adapter's five-minute default with room over. A row waiting on the
    // machine is NOT a dead claim and is left alone here.
    $tnow = xeric_state_time();
    $db->prepare("UPDATE scene_jobs SET status = 'pending', done_at = NULL
                  WHERE status = 'working' AND remote_job IS NULL AND done_at < ?")
       ->execute([$tnow - XERIC_SCENE_CLAIM_TTL]);
    // ...until it has waited too long, and then the wait was a try.
    $expired = $db->prepare("SELECT * FROM scene_jobs
                             WHERE status = 'working' AND remote_job IS NOT NULL AND done_at < ?");
    $expired->execute([$tnow - XERIC_SCENE_REMOTE_TTL]);
    foreach ($expired->fetchAll() as $row) {
        if (xeric_scene_fail($db, $row)) $out['failed']++;
        $out['notes'][] = xeric_scene_label($row) . ': the machine never finished it';
    }
    $expired->closeCursor();

    $waiting = $db->query("SELECT * FROM scene_jobs WHERE status = 'working' AND remote_job IS NOT NULL ORDER BY id")
                  ->fetchAll();
    $pending = xeric_scene_jobs($db, 'pending');
    if ($pending === [] && $waiting === []) return $out;

    $endpoint ??= xeric_scene_endpoint();
    // THE WORLD'S CEILING (engine/budget.php). Out of renders for today, a
    // pending row WAITS — it is not claimed, so it burns no try and is still
    // pending tomorrow. A row the machine is already making is let finish:
    // that render was asked for while there was budget.
    $now     = xeric_clock_now($db, $t);
    $costly  = $endpoint !== null && xeric_scene_costly($endpoint);
    $verdict = xeric_budget_allows($t, $db, $now, 'renders', 1, $costly);
    if (!$verdict['ok'] && $waiting === []) {
        $out['notes'][] = $verdict['reason'];
        return $out;
    }
    if ($endpoint === null || !($answering ?? xeric_scene_up($endpoint))) {
        $out['notes'][] = 'no scene machine is answering';
        return $out;
    }

    if (!is_dir($scenesDir) && !@mkdir($scenesDir, 0775, true) && !is_dir($scenesDir)) {
        $out['notes'][] = 'the scenes directory cannot be created';
        return $out;
    }

    // What this machine can make, for this world. The rest of the queue waits
    // its turn untried rather than burning three tries on the wrong machine.
    $channels = xeric_scene_channels($t, $endpoint, $db);
    $held     = [];
    foreach ($pending as $i => $job) {
        $c = xeric_scene_channel_of($job);
        if (!in_array($c, $channels, true)) { $held[$c] = true; unset($pending[$i]); }
    }
    $pending = array_values($pending);
    if (isset($held['sound'])) {
        $out['notes'][] = $cfg['sound']['enabled'] ? 'sound is waiting for a machine that can make it'
                                                   : 'sound is turned off for this world';
    }

    // A look asked as another kind than the template's first asks for that
    // kind first (scene_jobs.want); '' — every row from before — asks as today.
    $optsFor = fn(string $channel, string $want = ''): array => $channel === 'sound'
        ? ['channel' => 'sound', 'kinds' => ['audio'], 'quality' => $cfg['quality']]
        : ['channel' => 'look', 'kinds' => xeric_scene_kinds_for($cfg, $want), 'quality' => $cfg['quality'],
           'negative' => $cfg['negative'], 'layout_images' => xeric_layout_images($t)];

    foreach ($waiting as $job) {
        $channel = xeric_scene_channel_of($job);
        try {
            $r = xeric_scene_render($endpoint, ['key' => (string)$job['key'], 'channel' => $channel,
                                                'fingerprint' => (string)$job['fingerprint']],
                                    $optsFor($channel, (string)($job['want'] ?? '')) + ['job' => (string)$job['remote_job']]);
            if ($r['pending'] !== null) {
                // Still making it. A new job id (some machines re-issue) is
                // kept; the clock on the wait is not reset.
                $db->prepare("UPDATE scene_jobs SET remote_job = ? WHERE id = ? AND status = 'working'")
                   ->execute([$r['pending'], (int)$job['id']]);
                continue;
            }
            if ($r['cannot']) { $out['notes'][] = xeric_scene_cannot($db, $job, $endpoint); continue; }
            xeric_scene_fits($channel, $r);
            // The picture that rode along when this was sent was written down
            // then; it is still the one this answer was conditioned on.
            $sent = json_decode((string)($job['meta'] ?? '{}'), true);
            $ours = is_array($sent) && isset($sent['reference_fingerprint'])
                  ? ['reference_fingerprint' => (string)$sent['reference_fingerprint']] : [];
            if (xeric_scene_finish($db, $job, $r, $scenesDir, $endpoint, $ours)) $out['done']++;
        } catch (Throwable $e) {
            if (xeric_scene_fail($db, $job)) $out['failed']++;
            $out['notes'][] = xeric_scene_label($job) . ': ' . $e->getMessage();
        }
    }

    $claim = $db->prepare("UPDATE scene_jobs SET status = 'working', done_at = ?
                           WHERE id = ? AND status = 'pending'");
    foreach (array_slice($pending, 0, max(1, $limit)) as $job) {
        // Asked per row, before the claim: the render before this one may
        // have been the last the world could afford today.
        $verdict = xeric_budget_allows($t, $db, $now, 'renders', 1, $costly);
        if (!$verdict['ok']) { $out['notes'][] = $verdict['reason']; break; }
        $key     = (string)$job['key'];
        $fp      = (string)$job['fingerprint'];
        $channel = xeric_scene_channel_of($job);
        // A machine that said, earlier in this very pass, that it cannot
        // make sound is not asked again before the pass is out.
        if ($channel === 'sound' && xeric_scene_sound_unsupported($db, $endpoint)) continue;
        // THE CLAIM IS THE UPDATE: exactly one reaper gets rowCount 1.
        $claim->execute([xeric_state_time(), (int)$job['id']]);
        if ($claim->rowCount() !== 1) continue;
        try {
            $brief = $channel === 'sound' ? xeric_sound_brief($t, $db, $key, $now)
                                          : xeric_scene_brief($t, $db, $key, $now);
            if ($brief === null) throw new RuntimeException('the place is no longer in this world');
            if ($brief['fingerprint'] !== $fp) {
                $out['notes'][] = xeric_scene_label($job) . ': the room has changed since it was asked for; '
                                . 'rendering it as asked';
                $brief['prompt']      = (string)$job['prompt'];
                $brief['fingerprint'] = $fp;
                // The box it was asked with, too — its control images are
                // drawn from this — and none rather than today's when a row
                // from before kept none: a layout of another room is worse
                // than no layout.
                $kept = json_decode((string)($job['layout'] ?? ''), true);
                if (is_array($kept)) $brief['layout'] = $kept;
                else unset($brief['layout']);
            }
            $opts = $optsFor($channel, (string)($job['want'] ?? ''));
            $ours = [];
            if ($channel === 'look' && xeric_scene_takes_reference($endpoint)) {
                // At the lower of who asked for it and what it is drawn at.
                $ref = xeric_scene_reference($db, $key, $scenesDir, XERIC_SCENE_REFERENCE_CAP,
                    xeric_scene_render_rating((string)($job['rating'] ?? ''), (string)($brief['rating'] ?? '')));
                if ($ref !== null) {
                    $opts['reference']            = $ref;
                    $ours['reference_fingerprint'] = $ref['fingerprint'];
                }
            }
            $r = xeric_scene_render($endpoint, $brief, $opts);
            if ($r['pending'] !== null) {
                // LATER: the claim stays, and done_at (the claim stamp) is now
                // the submit time the remote window is measured from. What
                // rode along is written down now, while it is known.
                $db->prepare("UPDATE scene_jobs SET remote_job = ?, meta = ? WHERE id = ? AND status = 'working'")
                   ->execute([$r['pending'], json_encode((object)$ours, JSON_UNESCAPED_SLASHES), (int)$job['id']]);
                $out['notes'][] = xeric_scene_label($job) . ': the machine is making it';
                continue;
            }
            if ($r['cannot']) { $out['notes'][] = xeric_scene_cannot($db, $job, $endpoint); continue; }
            xeric_scene_fits($channel, $r);
            if (xeric_scene_finish($db, $job, $r, $scenesDir, $endpoint, $ours)) $out['done']++;
        } catch (Throwable $e) {
            if (xeric_scene_fail($db, $job)) $out['failed']++;
            $out['notes'][] = xeric_scene_label($job) . ': ' . $e->getMessage();
        }
    }
    return $out;
}

/** A row's channel; a row from before channels existed is a look. */
function xeric_scene_channel_of(array $job): string
{
    $c = (string)($job['channel'] ?? '');
    return $c === '' ? 'look' : $c;
}

/** How a row is named in a note: what it is, and of where. */
function xeric_scene_label(array $job): string
{
    return (xeric_scene_channel_of($job) === 'sound' ? 'sound of ' : 'scene of ') . (string)$job['key'];
}

/**
 * The machine said plainly that it cannot make sound. Written down against
 * THIS machine (xeric_scene_sound_unsupported) and the row put back exactly
 * as it was before the claim — pending, untried, its tries unspent — because
 * nothing went wrong that another try could put right. The note is the
 * pass's to say.
 */
function xeric_scene_cannot(PDO $db, array $job, array $endpoint): string
{
    xeric_world_state_set($db, 'scene.sound.unsupported', xeric_scene_machine_id($endpoint));
    $db->prepare("UPDATE scene_jobs SET status = 'pending', done_at = NULL, remote_job = NULL, meta = '{}'
                  WHERE id = ? AND status = 'working'")->execute([(int)$job['id']]);
    return xeric_scene_label($job) . ': ' . xeric_scene_machine_id($endpoint)
         . ' cannot make sound; the sound waits for a machine that can';
}

/** A sound must come back as a sound, and a room as anything but. */
function xeric_scene_fits(string $channel, array $r): void
{
    if ($channel === 'sound' && $r['kind'] !== 'audio') {
        throw new RuntimeException('the machine was asked for a sound and made something else');
    }
    if ($channel !== 'sound' && $r['kind'] === 'audio') {
        throw new RuntimeException('the machine was asked for a room and made a sound');
    }
}

/**
 * Where a row's bytes land: scene-<key>-<fp12><ext> for a look, sound-... for
 * a sound. Both halves are cleaned, the fingerprint too: it is ours, but a
 * file name is no place to find out that a row was edited by hand.
 */
function xeric_scene_file(array $job, array $r): string
{
    $fp = (string)$job['fingerprint'];
    if (str_starts_with($fp, 'sound:')) $fp = substr($fp, 6);
    return (xeric_scene_channel_of($job) === 'sound' ? 'sound-' : 'scene-')
         . preg_replace('/[^a-z0-9_-]+/i', '_', (string)$job['key']) . '-'
         . substr((string)preg_replace('/[^a-z0-9]+/i', '', $fp), 0, 12)
         // A look asked as a second kind keeps its own file: a still and a
         // panorama of one moment are both .png. The template's first kind
         // keeps the name it always had.
         . (($w = xeric_scene_want_word((string)($job['want'] ?? ''))) !== '' ? '-' . $w : '')
         . xeric_scene_ext($r['mime'], $r['kind']);
}

/**
 * A rendered answer onto its row, and the meter told. False when another
 * reaper finished this row first — then nothing is written to the row and
 * nothing is metered (the file, if any, is the same name either way). $ours
 * is what this engine sent along (the reference's fingerprint), recorded over
 * whatever the machine's own meta says about the same key.
 */
function xeric_scene_finish(PDO $db, array $job, array $r, string $scenesDir, ?array $endpoint, array $ours = []): bool
{
    $file = '';
    // A SPLAT IS KEPT AS PLY (engine/splat.php): a .splat is converted, a PLY
    // checked and kept as it came, both stored as application/ply under .ply.
    // What cannot be converted (SPZ, SOG, bytes that are not a splat) is kept
    // exactly as it arrived — a download is still a room — and the row's meta
    // says why. Nothing here touches the fingerprint.
    if ($r['kind'] === 'splat' && isset($r['bytes'])) {
        $ply = xeric_splat_to_ply((string)$r['bytes'], (string)$r['mime'], xeric_scene_ext((string)$r['mime'], 'splat'));
        if ($ply !== null) {
            $ours += ['ply' => $ply === $r['bytes'] ? 'kept' : 'converted from .splat'];
            $r['bytes'] = $ply;
            $r['mime']  = 'application/ply';
        } else {
            $ours += ['ply' => 'not converted: ' . xeric_splat_to_ply_why()];
        }
    }
    if ($r['kind'] !== 'viewer') {
        $file = xeric_scene_file($job, $r);
        if (@file_put_contents($scenesDir . '/' . $file, $r['bytes']) === false) {
            throw new RuntimeException('the scene could not be written to disk');
        }
    }
    $meta = $ours + $r['meta'] + ($r['extras'] !== [] ? ['extras' => $r['extras']] : []);
    $st = $db->prepare("UPDATE scene_jobs SET status = 'done', kind = ?, file = ?, url = ?, mime = ?, meta = ?,
                        remote_job = NULL, tries = tries + 1, done_at = ? WHERE id = ? AND status = 'working'");
    $st->execute([$r['kind'], $file, $r['url'], $r['mime'],
                  json_encode((object)$meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                  xeric_state_time(), (int)$job['id']]);
    if ($st->rowCount() !== 1) return false;
    // A sound arrived: whatever was written down about machines that cannot
    // make one is out of date.
    if (xeric_scene_channel_of($job) === 'sound') xeric_world_state_delete($db, 'scene.sound.unsupported');
    if (($fn = xeric_scene_meter()) !== null) {
        // A sound is a scene on the ledger, and says which it was.
        $fn(['scenes' => 1] + (xeric_scene_channel_of($job) === 'sound' ? ['sounds' => 1] : []) + $r['usage'],
            (string)($endpoint['base'] ?? 'stub'));
    }
    return true;
}

/**
 * A try burned: back to pending, or failed at three. The claim stamp, any
 * remote job and anything written down when it was sent come off with it —
 * a pending row carries none of them. Reads tries from the database rather
 * than the row in hand, because a row asked after across several passes is
 * older than the copy this pass read. True when this try was the third.
 */
function xeric_scene_fail(PDO $db, array $job): bool
{
    $q = $db->prepare('SELECT tries FROM scene_jobs WHERE id = ?');
    $q->execute([(int)$job['id']]);
    $tries = (int)($q->fetchColumn() ?: 0) + 1;
    $q->closeCursor();
    $st = $db->prepare("UPDATE scene_jobs SET status = ?, tries = ?, done_at = NULL, remote_job = NULL, meta = '{}'
                        WHERE id = ? AND status = ?");
    $st->execute([$tries >= 3 ? 'failed' : 'pending', $tries, (int)$job['id'], 'working']);
    return $st->rowCount() === 1 && $tries >= 3;
}

// ---------------------------------------------------------------------------
// Ahead of being looked at
// ---------------------------------------------------------------------------

/**
 * How far ahead of being looked at this world's rooms are drawn: 'off',
 * 'here' (the room the player is standing in), 'occupied' (and every room
 * with somebody in it now), 'all' (every room in the story).
 *
 * The template's word is final when it gives one — that is how a world asks.
 * When it does not, the answer is the machine's COST, because the heart runs
 * with nobody watching: on the owner's own electricity (a loopback machine,
 * xeric_scene_costly false) it draws the room the player is in; on anything
 * that bills it draws nothing at all. The heart must never spend an API key
 * nobody asked it to. No machine, or scenes turned off, is 'off'.
 */
function xeric_scene_prerender(array $t, ?array $endpoint = null): string
{
    $cfg = xeric_scene_config($t);
    if (!$cfg['enabled']) return 'off';
    $endpoint ??= xeric_scene_endpoint();
    if ($endpoint === null) return 'off';
    if ($cfg['prerender'] !== null) return $cfg['prerender'];
    return xeric_scene_costly($endpoint) ? 'off' : 'here';
}

/**
 * Queue what this world draws ahead (xeric_scene_prerender): the look brief,
 * and the sound brief when this machine and this world have sound, for the
 * player's own room in every mode that draws at all — plus every staged room
 * with somebody in it now ('occupied'), or every staged room ('all'). 'off'
 * queues nothing, the player's room included: off is off, whoever calls.
 * IDEMPOTENT by fingerprint, like every enqueue — a heart that offers the
 * same rooms every minute adds a row only when one of them changed.
 *
 * @return array{enqueued:int,keys:string[]} rows actually added, and every
 *         place offered (the player's first, then template order)
 */
function xeric_scene_backfill(array $t, PDO $db, ?array $now = null, ?array $endpoint = null): array
{
    $out = ['enqueued' => 0, 'keys' => []];
    $endpoint ??= xeric_scene_endpoint();
    $mode = xeric_scene_prerender($t, $endpoint);
    if ($mode === 'off') return $out;
    $now ??= xeric_clock_now($db, $t);

    $keys = [];
    $here = xeric_player_where($t, $db);
    if ($here !== null) $keys[] = $here;
    if ($mode !== 'here') {
        $presence = $mode === 'occupied' ? xeric_world_who_is_where($t, $now, xeric_dead_handles($db)) : [];
        foreach ((array)($t['places'] ?? []) as $p) {
            $k = (string)($p['key'] ?? '');
            if ($k === '' || in_array($k, $keys, true) || xeric_travel_unstaged($t, $k)) continue;
            if ($mode === 'occupied' && xeric_world_who_is_at($presence, $k) === []) continue;
            $keys[] = $k;
        }
    }

    $sound = in_array('sound', xeric_scene_channels($t, $endpoint, $db), true);
    foreach ($keys as $k) {
        $briefs = [xeric_scene_brief($t, $db, $k, $now)];
        if ($sound) $briefs[] = xeric_sound_brief($t, $db, $k, $now);
        foreach ($briefs as $b) {
            if ($b !== null && xeric_scene_insert($db, $b)['added']) $out['enqueued']++;
        }
    }
    $out['keys'] = $keys;
    return $out;
}

/**
 * What can move a fingerprint the heart would offer, cheaply: where the player
 * stands, the light bucket, who is where (handles and places, sorted), the
 * weather line, which rooms are dark, and the scene block itself. Every one
 * of those is a read or a pass over the cast's weeks — none composes a brief,
 * resolves an identity or derives a bearing. It is coarser than a fingerprint
 * in one direction only: a change here may turn out to move nothing, but
 * nothing moves a fingerprint the heart offers without changing this, short
 * of a hand edit to a room's furniture or a person's coat, which the next
 * change of light or company (or the next glance) picks up.
 */
function xeric_scene_ahead_key(array $t, PDO $db, ?array $now = null): string
{
    $now ??= xeric_clock_now($db, $t);
    $where = [];
    foreach (xeric_world_who_is_where($t, $now, xeric_dead_handles($db)) as $h => $row) {
        if (($row['where'] ?? null) !== null) $where[(string)$h] = (string)$row['where'];
    }
    ksort($where, SORT_STRING);
    $dark = xeric_dark_places($db);
    sort($dark, SORT_STRING);
    // The other bodies too, when there are any: somebody walking into the
    // room you stand in moves its fingerprint. Absent in a world of one.
    $bodies = [];
    if (xeric_players_many($db)) {
        foreach (xeric_travel_players($t, $db) as $p) $bodies[$p['handle']] = $p['where'];
    }
    return sha1(json_encode([
        'here' => xeric_player_where($t, $db), 'light' => xeric_scene_light($now), 'where' => $where,
        'weather' => trim(xeric_weather_line($t, $now, $db)), 'dark' => $dark,
        'scene' => $t['media']['scene'] ?? null,
    ] + ($bodies !== [] ? ['players' => $bodies] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * One pass of drawing ahead, for one world: what the heart calls after it has
 * lived the hours. The world's prerender decides first (no network, no
 * spend). Then THE CHEAP GATE: if nothing that can move a fingerprint has
 * moved since the last pass that got through (xeric_scene_ahead_key, kept as
 * world_state 'scene.ahead' with the mode and the machine folded in) and no
 * row this machine can make is pending or working, the pass is over — the
 * heart runs every minute and most minutes nothing has changed. Otherwise:
 * whether the machine answers, asked ONCE PER MACHINE PER PASS — $up is the
 * pass's memory of that, keyed by address, so a heart with twelve worlds on
 * one machine probes it once — then the backfill, then a reap of at most
 * $limit, told the machine's answer so it does not ask again. The key is
 * written only once a backfill has run, so a machine that was down is asked
 * again next minute.
 *
 * A world whose render budget for today is spent (engine/budget.php) skips
 * the whole pass — no probe, no backfill, no reap — and says so: `spent` is
 * true and the note is the sentence.
 *
 * @return array{mode:string,unchanged:bool,enqueued:int,keys:string[],done:int,failed:int,notes:string[],spent:bool}
 */
function xeric_scene_ahead(array $t, PDO $db, string $scenesDir, ?array $endpoint = null, int $limit = 2,
                           ?array &$up = null): array
{
    $out = ['mode' => 'off', 'unchanged' => false, 'enqueued' => 0, 'keys' => [], 'done' => 0, 'failed' => 0,
            'notes' => [], 'spent' => false];
    $endpoint ??= xeric_scene_endpoint();
    $out['mode'] = xeric_scene_prerender($t, $endpoint);
    if ($out['mode'] === 'off' || $endpoint === null) return $out;

    // A WORLD OUT OF RENDERS DRAWS NOTHING AHEAD. Drawing before anybody looks
    // is the first thing a ceiling takes away; the tick line says why, and
    // nothing is probed, queued or reaped until the world's date turns over.
    $verdict = xeric_budget_allows($t, $db, xeric_clock_now($db, $t), 'renders', 1, xeric_scene_costly($endpoint));
    if (!$verdict['ok']) {
        $out['spent']   = true;
        $out['notes'][] = 'not drawing ahead, ' . $verdict['reason'];
        return $out;
    }

    $key  = sha1(xeric_scene_ahead_key($t, $db) . '|' . $out['mode'] . '|' . xeric_scene_machine_id($endpoint));
    $open = 0;
    $channels = xeric_scene_channels($t, $endpoint, $db);
    if ($channels !== []) {
        $q = $db->prepare("SELECT COUNT(*) FROM scene_jobs WHERE status IN ('pending', 'working') AND channel IN ("
                        . implode(',', array_fill(0, count($channels), '?')) . ')');
        $q->execute($channels);
        $open = (int)$q->fetchColumn();
        $q->closeCursor();
    }
    if ($open === 0 && (string)(xeric_world_state_get($db, 'scene.ahead') ?? '') === $key) {
        $out['unchanged'] = true;
        return $out;
    }

    $up ??= [];
    $sig = json_encode([(string)($endpoint['base'] ?? ''), (string)($endpoint['kind'] ?? ''), isset($endpoint['stub'])]);
    if (!array_key_exists($sig, $up)) $up[$sig] = xeric_scene_up($endpoint, 3);
    if (!$up[$sig]) {
        $out['notes'][] = 'no scene machine is answering';
        return $out;
    }

    $b = xeric_scene_backfill($t, $db, null, $endpoint);
    xeric_world_state_set($db, 'scene.ahead', $key);
    $r = xeric_scene_reap($t, $db, $scenesDir, $endpoint, $limit, true);
    return ['mode' => $out['mode'], 'unchanged' => false, 'enqueued' => $b['enqueued'], 'keys' => $b['keys'],
            'spent' => false] + $r;
}
