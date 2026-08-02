<?php
/**
 * Xeric — viewers, knowledge walls, rating gates.
 *
 * Shared by every renderer. The rule this codebase lives by, learned the
 * expensive way when a protected relative was handed the same canon as
 * everybody else: renderers CONSUME walls, they never re-derive who may know
 * what. If a fact is audience-restricted, a wall in the template says so and
 * the renderer asks before it writes a word.
 *
 * PHP 8.2+.
 */

// world.php requires this file back, and require_once makes the cycle a no-op
// in either direction: whichever is entered first finishes defining the other's
// dependencies before anything is called. The dependency is deliberate and it
// is one-way in meaning — the age contract (xeric_is_minor, xeric_ratings,
// xeric_effective_rating) is DEFINED there and ENFORCED here, and a second copy
// of the age test living in this file is exactly the drift that would let one
// of them say adult while the other says child.
require_once __DIR__ . '/world.php';

// ---------------------------------------------------------------------------
// Rating
// ---------------------------------------------------------------------------

/** sfw < mature < explicit. Unknown strings sort as sfw so a typo can't unlock. */
function xeric_rating_rank(?string $rating): int
{
    // Broadcast tiers (2026-08-02): pg and teen slotted between sfw and
    // mature, old keys keeping their relative order so every stored
    // rating_min still means what it meant. Unknown still ranks 0 — an
    // unreadable rating must never UNGATE anything.
    switch (strtolower(trim((string)$rating))) {
        case 'explicit': return 4;
        case 'mature':   return 3;
        case 'teen':     return 2;
        case 'pg':       return 1;
        default:         return 0;
    }
}

/**
 * A node is allowed when the effective rating reaches its rating_min.
 * Plain strings (a texture line, a canon rule) carry no gate and always pass —
 * the object form {"text": "...", "rating_min": "mature"} is how you gate one.
 *
 * $subject is the CHARACTER the node concerns — the person it is about, or the
 * person reading it, whichever the caller has in hand. Pass it and the gate is
 * evaluated at that character's ceiling instead of the world's, which for a
 * minor is the weakest rating and cannot be anything else. Every rating
 * decision in the engine funnels through here, so this is where "a minor is
 * never rendered above sfw" stops being a rule and becomes arithmetic.
 *
 * Omitting $subject gates at the world rating, which is right for a node that
 * belongs to nobody — a texture line, a place, a canon rule. It is wrong, and
 * fails open, for anything under a character: give the character.
 */
function xeric_rating_allows(string $effective, $node, ?array $subject = null): bool
{
    if (!is_array($node)) return true;
    if (!isset($node['rating_min'])) return true;
    return xeric_rating_rank(xeric_rating_for_subject($effective, $subject)) >= xeric_rating_rank((string)$node['rating_min']);
}

/** Filter a list of nodes by rating, reindexed. Order preserved. */
function xeric_rating_filter(array $list, string $effective, ?array $subject = null): array
{
    $keep = [];
    foreach ($list as $node) {
        if (xeric_rating_allows($effective, $node, $subject)) $keep[] = $node;
    }
    return $keep;
}

/**
 * The rating a character's own material may be gated at: the world's, unless
 * the character is a minor, in which case the weakest one regardless.
 *
 * Cheap and pure, so a renderer can compute it once per character and hand the
 * string down — the prompt cache cares that the same reader gets the same
 * bytes, not how many times the ceiling was worked out.
 */
function xeric_rating_for_subject(string $effective, ?array $subject): string
{
    if ($subject === null) return $effective;
    return xeric_effective_rating($effective, $subject);
}

/**
 * The rating this VIEWER may be shown, given the world's.
 *
 * The reader's own ceiling, as opposed to the subject's: a minor reading an
 * adult world reads it at the weakest rating. Reads the derived flag that
 * xeric_viewer() computed from the template, and treats a viewer array that
 * carries no flag at all as a minor, because the only arrays without one are
 * hand-built and unresolved.
 */
function xeric_viewer_rating(string $effective, array $viewer): string
{
    return ($viewer['is_minor'] ?? true) ? xeric_ratings()[0] : $effective;
}

/**
 * What an unaffirmed session may be shown: the weakest rating, always.
 *
 * Down only, and deliberately blunt — the affirmation is a yes/no about the
 * content, never a stored age, so the only two answers this can give are "what
 * you asked for" and "the floor". xeric_world_clamp_rating() applies it to a
 * whole template; the web layer owns the session that answers the bool.
 */
function xeric_rating_clamp(string $rating, bool $adultAffirmed): string
{
    return $adultAffirmed ? $rating : xeric_ratings()[0];
}

/** Text out of a string-or-{text} node. */
function xeric_text($node): string
{
    if (is_string($node)) return trim($node);
    if (is_array($node))  return trim((string)($node['text'] ?? $node['line'] ?? ''));
    return '';
}

// ---------------------------------------------------------------------------
// Viewers
// ---------------------------------------------------------------------------

/**
 * Normalize a viewer into the shape every renderer reads.
 *
 * Input may be:
 *   null                          → the narrator: full canon, walls off
 *   ['handle' => 'janelle']       → a cast member; orbit/role/circles resolved
 *   ['handle' => 'cy']            → a fixture; orbit defaults to 'extras'
 *   plus explicit overrides for orbit / role / circles when a caller knows better.
 *
 * Returned keys: kind, handle, name, orbit, role, circles[], own_bible,
 * wall_keys[] (walls named directly by a special_role), is_narrator, is_minor.
 *
 * is_minor is DERIVED from the template's integer age, here and nowhere else.
 * It is never read off the passed-in $viewer, so a caller cannot declare itself
 * an adult; the narrator, who is the author's own view of full canon rather
 * than a person in the world, is not a minor.
 */
function xeric_viewer(array $template, ?array $viewer): array
{
    $v = [
        'kind'        => 'narrator',
        'handle'      => null,
        'name'        => 'the narrator',
        'orbit'       => null,
        'role'        => null,
        'circles'     => [],
        'own_bible'   => false,
        'wall_keys'   => [],
        'is_narrator' => true,
        'is_minor'    => false,
        'resolved'    => true,
    ];
    if ($viewer === null) return $v;

    $handle = $viewer['handle'] ?? $viewer['key'] ?? $viewer['character'] ?? null;
    $v['handle']      = $handle !== null ? (string)$handle : null;
    $v['is_narrator'] = false;
    $v['kind']        = 'viewer';
    $v['resolved']    = false;
    // Fails closed the way the walls do: a handle that resolves to nobody gets
    // the commons only, and the weakest rating with it.
    $v['is_minor']    = true;
    $v['name']        = (string)($viewer['name'] ?? $v['handle'] ?? 'someone');

    $cast     = $template['cast'] ?? [];
    $chars    = $cast['characters'] ?? [];
    $fixtures = $cast['fixtures'] ?? [];

    foreach ($chars as $c) {
        if (($c['handle'] ?? null) === $v['handle']) {
            $v['kind']     = 'character';
            $v['name']     = (string)($c['display_name'] ?? $v['handle']);
            $v['orbit']    = $c['orbit'] ?? null;
            $v['is_minor'] = xeric_is_minor((array)$c);
            $v['resolved'] = true;
            break;
        }
    }
    if (!$v['resolved']) {
        foreach ($fixtures as $f) {
            if (($f['key'] ?? null) === $v['handle']) {
                $v['kind']     = 'fixture';
                $v['name']     = (string)($f['name'] ?? $v['handle']);
                $v['orbit']    = $f['orbit'] ?? 'extras';
                // A fixture carries no required age, so it fails closed unless
                // it is the scenery form of a character who has one — the
                // two-Harlans case, where the man behind the register is a
                // sixty-six-year-old with a dossier three lines up.
                $same          = (string)($f['same_as'] ?? '');
                $behind        = $same !== '' ? xeric_world_character($template, $same) : null;
                $v['is_minor'] = xeric_is_minor($behind ?? (array)$f);
                $v['resolved'] = true;
                break;
            }
        }
    }

    // A special_role both names the viewer's role and hands them wall keys
    // directly — audience selectors are the general case, this is the explicit one.
    foreach ($cast['special_roles'] ?? [] as $sr) {
        if (($sr['character'] ?? null) !== $v['handle']) continue;
        $v['role']      = $sr['role'] ?? null;
        $v['own_bible'] = !empty($sr['own_bible']);
        foreach ($sr['walls'] ?? [] as $wk) $v['wall_keys'][] = (string)$wk;
    }

    // Caller overrides win — the engine sometimes knows an orbit the template doesn't.
    if (isset($viewer['orbit'])) $v['orbit'] = (string)$viewer['orbit'];
    if (isset($viewer['role']))  $v['role']  = (string)$viewer['role'];

    $v['circles'] = isset($viewer['circles'])
        ? array_values(array_map('strval', (array)$viewer['circles']))
        : xeric_viewer_circles($template, $v);

    return $v;
}

/** Circle membership: explicit `members` list, else `members_from_orbits`. */
function xeric_viewer_circles(array $template, array $v): array
{
    $in = [];
    foreach ($template['cast']['circles'] ?? [] as $c) {
        $key = (string)($c['key'] ?? '');
        if ($key === '') continue;
        if (in_array($v['handle'], (array)($c['members'] ?? []), true)) { $in[] = $key; continue; }
        if ($v['orbit'] !== null && in_array($v['orbit'], (array)($c['members_from_orbits'] ?? []), true)) $in[] = $key;
    }
    return $in;
}

/**
 * Every wall that applies to this viewer, in template order.
 *
 * own_bible adds a synthetic floor wall: a protected relationship gets a
 * DENY-by-default posture on the intimate layers, not merely whatever their
 * declared wall happened to remember to list. (Resolved ambiguity — the doc
 * says "own_bible: true → gets a separate rendered world" without saying what
 * makes it separate. This is what makes it separate.) The floor carries no
 * shown_as; the declared wall supplies the framing.
 *
 * `protagonist` is on both floors because a named protagonist's arc is somebody's
 * interior with a spotlight on it — the one interior in the world that renders
 * from its own section rather than out of the dossiers, and therefore the one a
 * floor written as a list of dossier paths used to miss entirely.
 */
function xeric_viewer_walls(array $template, array $v): array
{
    if (!empty($v['is_narrator'])) return [];

    $walls = [];
    foreach ($template['knowledge_walls'] ?? [] as $w) {
        $key = (string)($w['key'] ?? '');
        if ($key !== '' && in_array($key, $v['wall_keys'], true)) { $walls[] = $w; continue; }
        if (isset($w['audience']) && xeric_audience_match((array)$w['audience'], $v)) $walls[] = $w;
    }

    if (!empty($v['own_bible'])) {
        $walls[] = [
            'key'    => '_own_bible_floor',
            'hidden' => ['cast_dossiers', 'secrets', 'drives', 'psyche', 'tells', 'protagonist', 'mystery.room'],
        ];
    }

    // Fail CLOSED on a handle that resolves to nobody. A typo'd viewer must not
    // be handed narrator-grade canon — that is the exact shape of the bug this
    // whole subsystem exists to prevent. Unknown viewers get the commons only.
    if (empty($v['resolved'])) {
        $walls[] = [
            'key'    => '_unknown_viewer_floor',
            'hidden' => ['cast_dossiers', 'secrets', 'drives', 'psyche', 'tells',
                         'protagonist', 'economies', 'boons', 'mystery'],
        ];
    }
    return $walls;
}

/** {role: X} | {orbit: Y} | {circle: Z}. All present keys must match (AND). */
function xeric_audience_match(array $audience, array $v): bool
{
    if ($audience === []) return false;
    foreach ($audience as $field => $want) {
        switch ($field) {
            case 'role':   if ($v['role']  !== $want) return false; break;
            case 'orbit':  if ($v['orbit'] !== $want) return false; break;
            case 'circle': if (!in_array($want, $v['circles'], true)) return false; break;
            case 'handle': if ($v['handle'] !== $want) return false; break;
            default:       return false;   // unknown selector never matches
        }
    }
    return true;
}

// ---------------------------------------------------------------------------
// Walls
// ---------------------------------------------------------------------------

/**
 * Does any applied wall hide this path?
 *
 * A hidden entry hides itself and everything under it: "economies" hides
 * "economies.thursday_pot". The trailing ".*" in the doc's examples is
 * decoration — "drives.*" and "drives" mean the same thing here. Matching is
 * one-directional: hiding "economies.thursday_pot" does NOT hide the
 * "economies" section as a whole, so a wall can remove one ledger and leave the
 * rest standing.
 *
 * Hidden keys the renderer owns no path for ("what_dad_does_on_thursdays") are
 * inert — they still bring their shown_as framing, but the renderer never
 * invents structure for a key it doesn't own.
 */
function xeric_hidden(array $walls, string $path): bool
{
    foreach ($walls as $w) {
        foreach ((array)($w['hidden'] ?? []) as $h) {
            $h = (string)$h;
            if (str_ends_with($h, '.*')) $h = substr($h, 0, -2);
            if ($h === '') continue;
            if ($h === '*') return true;
            if ($h === $path) return true;
            if (str_starts_with($path, $h . '.')) return true;
        }
    }
    return false;
}

/** The shown_as framings of the applied walls, deduped, in wall order. */
function xeric_framings(array $walls): array
{
    $out = [];
    foreach ($walls as $w) {
        $f = trim((string)($w['shown_as'] ?? ''));
        if ($f !== '' && !in_array($f, $out, true)) $out[] = $f;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Walls against loose prose
// ---------------------------------------------------------------------------

/** How many words in a row make a quotation rather than a coincidence. */
const XERIC_WALL_QUOTE_RUN = 6;

/**
 * Every interior string the template holds, filed under the path that hides it.
 *
 * Walls name PATHS, and everything rendered FROM the template arrives through
 * one — but not everything in a prompt comes from the template. A lesson, a
 * seeded memory, an event title is prose written later, and it may have carried
 * a walled field's words out with it: the hand-edit crumbs in learn.php quote
 * the old and the new value of the edited field verbatim, and the model that
 * reads them is told to take them literally. That prose has no path to ask
 * about, so the only thing left to compare is the words themselves.
 *
 * Interiors only. The commons — a room, a shift, a one-liner everyone has heard —
 * exist to be repeated back, and matching against them would throw away true
 * sentences for the crime of naming the diner.
 *
 * @return array<string,array<int,string>> wall path => the strings under it
 */
function xeric_wall_interiors(array $template): array
{
    $map = [];
    $put = function (string $path, $node) use (&$map): void {
        $s = xeric_text($node);
        if ($s !== '') $map[$path][] = $s;
    };

    foreach ($template['cast']['characters'] ?? [] as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        // Two paths per string, because two different walls remove it: the
        // specific one, and cast_dossiers over the whole section it renders in.
        $dossier = 'cast_dossiers.' . $h;
        $put($dossier, $c['voice'] ?? '');

        foreach ((array)($c['psyche'] ?? []) as $node) {
            $put('psyche.' . $h, $node);
            $put($dossier, $node);
        }
        foreach ((array)($c['tells'] ?? []) as $node) {
            $put('tells', $node);
            $put($dossier, $node);
        }
        $put('solace', $c['solace'] ?? '');
        $put($dossier, $c['solace'] ?? '');
        foreach ((array)($c['secrets'] ?? []) as $node) {
            $put('secrets.' . $h, $node);
            $put($dossier, $node);
        }
        $put('drives.' . $h, ($c['drives']['pull'] ?? ''));
    }

    // The protagonist's arc rides `drives` as well as its own path — it is the
    // same interior said in the third person, and the forge has been known to
    // copy one into the other byte for byte.
    $p  = (array)($template['cast']['protagonist'] ?? []);
    $ph = (string)($p['handle'] ?? '');
    foreach (['arc', 'pressure'] as $k) {
        $put('protagonist', $p[$k] ?? '');
        if ($ph !== '') $put('drives.' . $ph, $p[$k] ?? '');
    }

    return $map;
}

/**
 * The wall path a loose sentence quotes from behind these walls, or '' if none.
 *
 * A wall's own `explain` and the special role's `must_not_know` are checked too,
 * against the walls that carry them: neither is a path anybody can hide, because
 * they exist to DESCRIBE the hiding — and both are one sentence naming the exact
 * thing being kept from this reader, sitting in a text box somebody may retype.
 * `shown_as` is deliberately not among them; that one is written to be read by
 * the walled viewer.
 */
function xeric_quotes_walled(array $template, array $walls, string $line): string
{
    $hay = xeric_wall_words($line);
    if ($hay === []) return '';

    $forbidden = [];
    foreach (xeric_wall_interiors($template) as $path => $strings) {
        if (xeric_hidden($walls, $path)) $forbidden[$path] = $strings;
    }
    foreach ($walls as $w) {
        $key   = (string)($w['key'] ?? '');
        $under = 'knowledge_walls.' . ($key !== '' ? $key : 'unnamed');
        $ex    = xeric_text($w['explain'] ?? '');
        if ($ex !== '') $forbidden[$under][] = $ex;
        if ($key === '') continue;
        foreach ($template['cast']['special_roles'] ?? [] as $sr) {
            if (!in_array($key, array_map('strval', (array)($sr['walls'] ?? [])), true)) continue;
            $mnk = xeric_text($sr['must_not_know'] ?? '');
            if ($mnk !== '') $forbidden[$under][] = $mnk;
        }
    }

    foreach ($forbidden as $path => $strings) {
        foreach ($strings as $s) {
            if (xeric_wall_quotes($hay, xeric_wall_words($s))) return (string)$path;
        }
    }
    return '';
}

/** Words, lowercased, punctuation gone — the shape two sentences are compared in. */
function xeric_wall_words(string $s): array
{
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(trim($s))) ?? '';
    return array_values(array_filter(explode(' ', $s), fn($w) => $w !== ''));
}

/** Does the haystack carry a run of the needle long enough to be a quotation? */
function xeric_wall_quotes(array $hay, array $needle): bool
{
    $n = count($needle);
    if ($n === 0) return false;
    $flat = ' ' . implode(' ', $hay) . ' ';

    // A short field is a quotation only when the whole of it is there, and only
    // when it is long enough to be somebody's words rather than a turn of phrase.
    if ($n <= XERIC_WALL_QUOTE_RUN) {
        $whole = implode(' ', $needle);
        return mb_strlen($whole) >= 16 && str_contains($flat, ' ' . $whole . ' ');
    }
    for ($i = 0; $i + XERIC_WALL_QUOTE_RUN <= $n; $i++) {
        if (str_contains($flat, ' ' . implode(' ', array_slice($needle, $i, XERIC_WALL_QUOTE_RUN)) . ' ')) return true;
    }
    return false;
}

/**
 * Audience selectors used by board.visible_to and friends:
 *   "all" | "*"            everyone who can see the economy at all
 *   "orbit:X" "role:X" "circle:X" "handle:X"
 *   "cast_minus:a,b"       every cast member except those roles/orbits/handles
 *   "dot"                  bare string = a handle
 */
function xeric_selector_match(string $selector, array $v): bool
{
    $selector = trim($selector);
    if ($selector === 'all' || $selector === '*') return true;

    $kind = $selector;
    $arg  = '';
    if (str_contains($selector, ':')) [$kind, $arg] = explode(':', $selector, 2);

    switch ($kind) {
        case 'orbit':  return $v['orbit'] === $arg;
        case 'role':   return $v['role'] === $arg;
        case 'circle': return in_array($arg, $v['circles'], true);
        case 'handle': return $v['handle'] === $arg;
        case 'cast_minus':
            if ($v['kind'] !== 'character') return false;
            foreach (explode(',', $arg) as $ex) {
                $ex = trim($ex);
                if ($ex === '') continue;
                if ($v['role'] === $ex || $v['orbit'] === $ex || $v['handle'] === $ex) return false;
            }
            return true;
        default:
            return $v['handle'] === $selector;
    }
}

/** True when ANY selector in the list matches. Empty list = nobody. */
function xeric_selector_any(array $selectors, array $v): bool
{
    foreach ($selectors as $s) {
        if (is_string($s) && xeric_selector_match($s, $v)) return true;
    }
    return false;
}

/**
 * The paths renderers ask about. A template validator can lint `hidden` lists
 * against this; unknown keys are legal but inert (see xeric_hidden).
 */
function xeric_wall_vocabulary(): array
{
    return [
        'user',
        'setting', 'setting.texture', 'setting.canon_rules',
        'places', 'places.<key>',
        'orbits', 'orbits.<key>', 'circles',
        'cast_lines', 'schedules', 'cast_dossiers', 'protagonist',
        'psyche', 'tells', 'secrets', 'secrets.<handle>', 'solace',
        'drives', 'drives.<handle>', 'relationships', 'fixtures',
        'economies', 'economies.<key>', 'boons', 'boons.<key>',
        'mystery', 'mystery.rumor', 'mystery.room',
        'world_mood',
    ];
}

// ---------------------------------------------------------------------------
// Small shared helpers
// ---------------------------------------------------------------------------

/** Load and decode a world template. Throws on unreadable / invalid JSON. */
function xeric_template_load(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) throw new RuntimeException("xeric: cannot read template $path");
    $t = json_decode($raw, true);
    if (!is_array($t)) throw new RuntimeException("xeric: bad JSON in $path: " . json_last_error_msg());
    return $t;
}

/** "a", "a and b", "a, b, and c" */
function xeric_join_list(array $items, string $conj = 'and'): string
{
    $items = array_values(array_filter(array_map('strval', $items), fn($s) => $s !== ''));
    $n = count($items);
    if ($n === 0) return '';
    if ($n === 1) return $items[0];
    if ($n === 2) return $items[0] . " $conj " . $items[1];
    $last = array_pop($items);
    return implode(', ', $items) . ", $conj " . $last;
}

/** Capitalize, guarantee terminal punctuation. Framings arrive as fragments. */
function xeric_sentence(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    $s = ucfirst($s);
    if (!in_array(substr($s, -1), ['.', '!', '?', ':'], true)) $s .= '.';
    return $s;
}

/** days:[0..6] with 0 = Sunday (PHP 'w'), matching the doc's [1,3,5] weekdays. */
function xeric_days_phrase(array $days): string
{
    $names = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays'];
    $out = [];
    foreach ($days as $d) {
        $d = (int)$d;
        if ($d >= 0 && $d <= 6) $out[] = $names[$d];
    }
    return xeric_join_list($out);
}

/** Join blocks of lines with one blank line between them. */
function xeric_blocks(array $blocks): array
{
    $out = [];
    foreach ($blocks as $b) {
        if (!$b) continue;
        if ($out) $out[] = '';
        foreach ($b as $line) $out[] = $line;
    }
    return $out;
}

/** Append a titled section if it has a body. */
function xeric_section(array &$out, string $title, array $body): void
{
    if (!$body) return;
    if ($out) $out[] = '';
    $out[] = $title;
    foreach ($body as $line) $out[] = $line;
}
