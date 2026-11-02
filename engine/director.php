<?php
/**
 * Xeric — the Director. It changes the SET, never the performance.
 *
 * Everything else in the engine pushes toward autonomy, and free will left
 * alone produces drift: everyone doing their own plausible thing forever. The
 * Director is the counterweight, and it has exactly one power — it can put two
 * or three people in the same room for an hour. It cannot decide what they say
 * there, what kind of hour it is, or whether anything happens at all. The
 * sweep's groups then form the way they always form, out of presence: two
 * people standing in the same place is a group, and the hour is theirs.
 *
 * A STAGING IS A ROW (`director_stagings`, created here, never in state.php):
 *   who     2–3 handles
 *   where   a place key
 *   window  world epochs [from, to), at most two hours
 *   why     a short reason for the inspector, in plain words
 *   source  `narrator:<n>` (an intent) | `apart` | `player` (a rule) | `owner`
 *   state   planned | live | done | cancelled
 *
 * PRESENCE READS IT, AND ONLY PRESENCE. xeric_world_who_is_where() consults
 * stagings when it is handed a database, or when the template it is handed was
 * dressed with them (xeric_director_dress — how the heart lets a sweep, which
 * has no database argument, see them). A staged person is AT the place with
 * `staged: true` and a `doing` composed from observables — "waiting on a
 * coffee" where the place serves, "walking past" where it does not — and
 * nothing else about them changes.
 *
 * THREE REFUSALS, checked when a staging is made and again every time presence
 * is read, because a world moves between the two:
 *   - the dead are in no room, staged or not (absent from presence: never moved);
 *   - nobody is pulled off a shift that cannot be missed — a week[] block marked
 *     `unmissable: true`, or, unmarked, a block at a place of kind `work` or at a
 *     non-home place that lists them (or a fixture that is them) as a resident:
 *     the place they are usually found is their job. `unmissable: false` on a
 *     block frees it explicitly;
 *   - a minor is never staged into an adult-rated room (a place whose
 *     rating_min is above the floor, that pours, or whose kind is bar or club).
 * A staging that can no longer seat two people seats nobody: one person moved
 * alone is a schedule edit for no reason.
 *
 * OFF BY DEFAULT. The rules run only when the template's `director.rules`
 * lists them, so a world that never asked has no table, no rows, no world_state
 * keys and presence identical to the schedule — the continuity baseline never
 * moves. An owner staging (xeric_director_stage with source `owner`) needs no
 * rule; it is a hand on the set, not the Director deciding.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/death.php';
require_once __DIR__ . '/trust.php';
require_once __DIR__ . '/travel.php';   // where the player is; pulls in the sweeps' hours reader and quiet hours

/** The rules a template may list in `director.rules`, in the order they run. */
const XERIC_DIRECTOR_RULES = ['narrator', 'apart', 'player'];

/** The longest a staging may hold anybody. */
const XERIC_DIRECTOR_MAX_WINDOW = 7200;

/** Kinds of hour that do not need anybody to meet. */
const XERIC_DIRECTOR_SOLITARY = ['glimpse'];

/** The template's `director` block, defaulted. Unknown rules are dropped, not errors. */
function xeric_director_config(array $t): array
{
    $d = is_array($t['director'] ?? null) ? $t['director'] : [];
    $rules = [];
    foreach ((array)($d['rules'] ?? []) as $r) {
        $r = (string)$r;
        if (in_array($r, XERIC_DIRECTOR_RULES, true)) $rules[$r] = true;
    }
    $len = (int)($d['minutes'] ?? 60) * 60;
    return [
        'rules'      => array_values(array_intersect(XERIC_DIRECTOR_RULES, array_keys($rules))),
        'per_day'    => max(0, (int)($d['per_day'] ?? 2)),
        'apart_days' => max(1, (int)($d['apart_days'] ?? 7)),
        'player_gap' => max(1, (int)($d['player_gap'] ?? 2)),
        'length'     => max(900, min(XERIC_DIRECTOR_MAX_WINDOW, $len)),
        'lookahead'  => max(1, min(72, (int)($d['lookahead_hours'] ?? 24))),
    ];
}

// ---------------------------------------------------------------------------
// The table
// ---------------------------------------------------------------------------

function xeric_director_ensure(PDO $db): void
{
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS director_stagings (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            who         TEXT    NOT NULL,              -- JSON array of 2-3 handles
            place       TEXT    NOT NULL,
            epoch_from  INTEGER NOT NULL,              -- world epoch, inclusive
            epoch_to    INTEGER NOT NULL,              -- world epoch, exclusive
            why         TEXT    NOT NULL,
            source      TEXT    NOT NULL,              -- narrator:<n> | apart | player | owner
            state       TEXT    NOT NULL DEFAULT 'planned',
            made        INTEGER NOT NULL,              -- world epoch it was planned at
            ended       INTEGER,                       -- world epoch it was cancelled or finished
            created_at  INTEGER NOT NULL
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_director_when ON director_stagings(epoch_from)');
    // The reader's index: "which rows can still cover a moment" is a range on
    // the END of the window — everything that finished before it is behind it.
    $db->exec('CREATE INDEX IF NOT EXISTS idx_director_to ON director_stagings(epoch_to, epoch_from)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_director_state ON director_stagings(state)');
}

/** Rows the Director has decoded off disk in this process; $add counts more (the test's seam). */
function xeric_director_rows_read(int $add = 0): int
{
    static $n = 0;
    return $n += $add;
}

/**
 * The rows matching a WHERE, decoded, oldest window first. No table reads as
 * no rows. Every bound is an INTEGER: execute([...]) binds strings, and in
 * SQLite every integer sorts below every string.
 */
function xeric_director_query(PDO $db, string $where, array $ints = []): array
{
    try {
        $q = $db->prepare('SELECT * FROM director_stagings' . ($where !== '' ? ' WHERE ' . $where : '') . ' ORDER BY epoch_from, id');
        foreach (array_values($ints) as $i => $v) $q->bindValue($i + 1, (int)$v, PDO::PARAM_INT);
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    xeric_director_rows_read(count($rows));
    return array_map('xeric_director_row', $rows ?: []);
}

/**
 * Only the rows that can be holding somebody at $epoch: its window around the
 * moment, and not cancelled before it. The same test as xeric_director_covers,
 * asked of the index instead of of every row ever staged — a world a year old
 * has hundreds of finished stagings, and who_is_where is asked per person per
 * prompt, per panel, per sweep window. Time, not the stored state, decides, as
 * covers() does: a history read of last Tuesday still sees last Tuesday's.
 */
function xeric_director_rows_at(PDO $db, int $epoch): array
{
    return xeric_director_query($db,
        "epoch_to > ? AND epoch_from <= ? AND (state != 'cancelled' OR (ended IS NOT NULL AND ended > ?))",
        [$epoch, $epoch, $epoch]);
}

/**
 * Every staging, oldest window first. A database that never had the Director
 * has no table, and that reads as no rows — reading never creates it.
 */
function xeric_director_rows(PDO $db): array
{
    return xeric_director_query($db, '');
}

function xeric_director_row(array $r): array
{
    return [
        'id'     => (int)$r['id'],
        'who'    => array_values(array_map('strval', (array)(json_decode((string)$r['who'], true) ?: []))),
        'where'  => (string)$r['place'],
        'from'   => (int)$r['epoch_from'],
        'to'     => (int)$r['epoch_to'],
        'why'    => (string)$r['why'],
        'source' => (string)$r['source'],
        'state'  => (string)$r['state'],
        'made'   => (int)$r['made'],
        'ended'  => $r['ended'] !== null ? (int)$r['ended'] : null,
    ];
}

/**
 * Is this staging holding people at this moment? Time decides, not the stored
 * state, so a heart that has not run since the window opened still shows it.
 * A cancelled staging held for the part of its window before it was cancelled:
 * that half-hour happened, and history read back must agree with history.
 */
function xeric_director_covers(array $s, int $epoch): bool
{
    if ($epoch < $s['from'] || $epoch >= $s['to']) return false;
    if ($s['state'] === 'cancelled') return $s['ended'] !== null && $epoch < $s['ended'];
    return true;
}

/** The state a row is in at $epoch, by time, without writing anything. */
function xeric_director_state_at(array $s, int $epoch): string
{
    if ($s['state'] === 'cancelled' || $s['state'] === 'done') return $s['state'];
    if ($epoch >= $s['to'])   return 'done';
    if ($epoch >= $s['from']) return 'live';
    return 'planned';
}

/** Write planned → live → done as the clock passes them. Idempotent. */
function xeric_director_advance(PDO $db, int $epoch): void
{
    // Only what can still move: a done or cancelled row is history and stays.
    foreach (xeric_director_query($db, "state IN ('planned', 'live')") as $s) {
        $st = xeric_director_state_at($s, $epoch);
        if ($st === $s['state']) continue;
        $q = $db->prepare('UPDATE director_stagings SET state = ?, ended = ? WHERE id = ?');
        $q->execute([$st, $st === 'done' ? $s['to'] : null, $s['id']]);
    }
}

// ---------------------------------------------------------------------------
// Observables: the pretext, the room's rating, the shift
// ---------------------------------------------------------------------------

/**
 * What a staged person is doing, from what the place is and nothing else. No
 * name, no pronoun, no reason — the reason is for the inspector, not the room.
 */
function xeric_director_pretext(array $t, string $place): string
{
    $p    = xeric_world_place($t, $place) ?? [];
    $kind = strtolower((string)($p['kind'] ?? ''));
    if ($kind === 'home') return 'stopping by';
    if (!empty($p['serves_alcohol'])) return 'waiting on a drink';
    if (!empty($p['serves']) || in_array($kind, ['diner', 'cafe', 'coffee', 'restaurant', 'bakery', 'bar', 'pub'], true)) {
        return 'waiting on a coffee';
    }
    return 'walking past';
}

/** A room a minor is never staged into. Fails closed on anything above the floor. */
function xeric_director_adult_room(array $t, string $place): bool
{
    $p = xeric_world_place($t, $place);
    if ($p === null) return true;
    $floor = xeric_ratings()[0];
    $min = $p['rating_min'] ?? null;
    if ($min !== null && (string)$min !== $floor) return true;
    if (!empty($p['serves_alcohol'])) return true;
    return in_array(strtolower((string)($p['kind'] ?? '')), ['bar', 'club'], true);
}

/** The week[] block that has $handle placed at $now, or null. Same tiebreak as who_is_where. */
function xeric_director_block(array $t, string $handle, array $now): ?array
{
    $c = xeric_world_character($t, $handle);
    if ($c === null) return null;
    $dow  = (int)($now['dow'] ?? 0);
    $mins = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    foreach ((array)($c['week'] ?? []) as $w) {
        if (xeric_world_week_covers((array)$w, $dow, ($dow + 6) % 7, $mins)) return (array)$w;
    }
    return null;
}

/** Is $handle on a shift right now that a staging may not pull them off? */
function xeric_director_unmissable(array $t, string $handle, array $now): bool
{
    $w = xeric_director_block($t, $handle, $now);
    if ($w === null || (string)($w['where'] ?? '') === '') return false;
    if (array_key_exists('unmissable', $w)) return $w['unmissable'] === true;
    $p = xeric_world_place($t, (string)$w['where']);
    if ($p === null) return false;
    $kind = strtolower((string)($p['kind'] ?? ''));
    if ($kind === 'work') return true;
    if ($kind === 'home') return false;
    $as = [$handle => true];
    foreach ((array)($t['cast']['fixtures'] ?? []) as $f) {
        if ((string)($f['same_as'] ?? '') === $handle) $as[(string)($f['key'] ?? '')] = true;
    }
    foreach ((array)($p['residents'] ?? []) as $r) if (isset($as[(string)$r])) return true;
    return false;
}

/**
 * Why $handle cannot be staged at $place at $now, or null when they can.
 * $row is their row from presence (without the Director); null means presence
 * left them out — dead, out of the story, or excluded by the caller.
 */
function xeric_director_refuse(array $t, string $handle, string $place, array $now, ?array $row): ?string
{
    $c = xeric_world_character($t, $handle);
    if ($c === null) return 'not in the cast';
    if ($row === null) return 'in no room at all';
    if (($row['where'] ?? null) === $place) return null;           // already there: nothing moves
    if (xeric_director_unmissable($t, $handle, $now)) return 'on a shift that cannot be missed';
    if (xeric_is_minor($c) && xeric_director_adult_room($t, $place)) return 'a minor, and the room is adult-rated';
    return null;
}

/** Is the place open at $now? Hours as the sweeps read them; no hours is open. */
function xeric_director_open(array $t, string $place, array $now): bool
{
    $p = xeric_world_place($t, $place);
    if ($p === null) return false;
    return xeric_sweep_place_open((array)($p['hours'] ?? []),
        xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0, (int)($now['dow'] ?? 0));
}

// ---------------------------------------------------------------------------
// Presence
// ---------------------------------------------------------------------------

/**
 * Put the staged people where their staging says, on top of the schedule's
 * answer. Called by xeric_world_who_is_where() only; $presence is its answer
 * without the Director, so the dead and the out are already absent from it.
 */
function xeric_director_apply(array $t, array $now, array $presence, ?PDO $db = null): array
{
    $epoch = (int)($now['epoch'] ?? 0);
    if ($epoch <= 0) return $presence;

    $all = [];
    foreach ((array)($t['_director']['stagings'] ?? []) as $s) $all[(int)$s['id']] = $s;
    // The rows that can cover this moment, and no others: decoding the whole
    // history on every presence read was the Director's cost growing with the
    // world's age instead of with what is happening now.
    if ($db !== null) foreach (xeric_director_rows_at($db, $epoch) as $s) $all[$s['id']] = $s;
    if ($all === []) return $presence;
    ksort($all);

    $moved = [];
    foreach ($all as $s) {
        if (!xeric_director_covers($s, $epoch)) continue;
        $place = (string)$s['where'];
        if (xeric_world_place($t, $place) === null || !xeric_director_open($t, $place, $now)) continue;

        $come = [];
        $there = 0;
        foreach ($s['who'] as $h) {
            if (isset($moved[$h])) continue;                       // one staging per person per moment
            $row = $presence[$h] ?? null;
            if (xeric_director_refuse($t, $h, $place, $now, $row) !== null) continue;
            if (($row['where'] ?? null) === $place) { $there++; continue; }
            $come[] = $h;
        }
        if ($there + count($come) < 2 || $come === []) continue;  // seats two or seats nobody

        $doing = xeric_director_pretext($t, $place);
        foreach ($come as $h) {
            $presence[$h] = ['handle' => $h, 'where' => $place, 'doing' => $doing,
                             'staged' => true, 'staging' => (int)$s['id']];
            $moved[$h] = true;
        }
    }
    return $presence;
}

/**
 * The template, carrying the stagings presence should honour — for readers
 * that take no database (the sweeps). A world with no stagings comes back
 * byte-for-byte as it went in.
 *
 * NOT THE WHOLE HISTORY. Every finished staging used to ride along on every
 * dressed template, so a world's every open copied a year of rows into memory
 * to answer questions about tonight. $from is the earliest moment the reader
 * will ask about (the heart passes the first unswept window): rows that ended
 * before it are left on disk. Without it, the reader is asking about now and
 * after, and a row the clock has already written done or cancelled cannot be
 * holding anybody then — so only planned and live rows ride, plus a
 * cancellation that still holds part of its window.
 */
function xeric_director_dress(array $t, PDO $db, ?int $from = null): array
{
    $rows = $from === null
        ? xeric_director_query($db, "state IN ('planned', 'live') OR (state = 'cancelled' AND ended > epoch_from AND ended > ?)",
                               [xeric_clock_epoch($db)])
        : xeric_director_query($db, "epoch_to > ? AND (state != 'cancelled' OR (ended IS NOT NULL AND ended > epoch_from AND ended > ?))",
                               [$from, $from]);
    if ($rows === []) return $t;
    $t['_director'] = ['stagings' => $rows];
    return $t;
}

// ---------------------------------------------------------------------------
// Making and unmaking a staging
// ---------------------------------------------------------------------------

/** The world-local date of an epoch, for the per-day cap. */
function xeric_director_day(array $t, int $epoch): string
{
    return substr(xeric_world_now($t, $epoch)['iso'], 0, 10);
}

/**
 * Why this staging may not be made, or null. Checks the shape, the window,
 * every person at every quarter-hour of it, overlaps and the day's cap.
 */
function xeric_director_check(array $t, PDO $db, array $s, int $epoch): ?string
{
    unset($t['_director']);                              // judged against the schedule, not against itself
    $who = array_values(array_unique(array_map('strval', (array)($s['who'] ?? []))));
    if (count($who) < 2 || count($who) > 3) return 'a staging is two or three people';
    foreach ($who as $h) if (xeric_world_character($t, $h) === null) return "'$h' is not in this cast";
    $place = (string)($s['where'] ?? '');
    if (xeric_world_place($t, $place) === null) return "'$place' is nowhere in this world";
    if (xeric_travel_unstaged($t, $place)) return 'that home belongs to somebody not yet in the story';
    $from = (int)($s['from'] ?? 0);
    $to   = (int)($s['to'] ?? 0);
    if ($to <= $from) return 'the window ends before it starts';
    if ($to - $from > XERIC_DIRECTOR_MAX_WINDOW) return 'a staging holds people for two hours at most';
    if ($to <= $epoch) return 'that window is already over';

    $dead = xeric_dead_handles($db);
    for ($e = max($from, $epoch); $e < $to; $e += 900) {
        $now = xeric_world_now($t, $e);
        if (!xeric_director_open($t, $place, $now)) return xeric_world_place_name($t, $place) . ' is shut then';
        $pres = xeric_world_who_is_where($t, $now, $dead);
        foreach ($who as $h) {
            $why = xeric_director_refuse($t, $h, $place, $now, $pres[$h] ?? null);
            if ($why !== null) return xeric_world_name($t, $h) . ' is ' . $why;
        }
    }

    $cfg = xeric_director_config($t);
    $day = xeric_director_day($t, $from);
    $sameDay = 0;
    foreach (xeric_director_rows($db) as $o) {
        if ($o['state'] === 'cancelled') continue;
        if ($o['from'] < $to && $from < $o['to'] && array_intersect($o['who'], $who) !== []) {
            return 'somebody in it is already staged then (staging #' . $o['id'] . ')';
        }
        if (xeric_director_day($t, $o['from']) === $day) $sameDay++;
    }
    if ($sameDay >= $cfg['per_day']) return 'the day already has ' . $sameDay . ' staging' . ($sameDay === 1 ? '' : 's')
        . ', the most director.per_day allows';
    return null;
}

/**
 * Make a staging. $s: who, where, from, to, why, source (default owner).
 * $epoch is the world's now. Returns the row.
 *
 * @throws RuntimeException naming the refusal
 */
function xeric_director_stage(array $t, PDO $db, array $s, int $epoch): array
{
    $why = xeric_director_check($t, $db, $s, $epoch);
    if ($why !== null) throw new RuntimeException('director: ' . $why);
    xeric_director_ensure($db);
    $who = array_values(array_unique(array_map('strval', (array)$s['who'])));
    $q = $db->prepare('INSERT INTO director_stagings (who, place, epoch_from, epoch_to, why, source, state, made, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $q->execute([json_encode($who), (string)$s['where'], (int)$s['from'], (int)$s['to'],
                 trim((string)($s['why'] ?? '')) ?: 'the owner asked',
                 (string)($s['source'] ?? 'owner'),
                 (int)$s['from'] <= $epoch ? 'live' : 'planned', $epoch, xeric_state_time()]);
    $id = (int)$db->lastInsertId();
    foreach (xeric_director_rows($db) as $r) if ($r['id'] === $id) return $r;
    throw new RuntimeException('director: the staging did not land');
}

/**
 * The owner takes a staging back. Planned or live only — a finished one is
 * history. Engine-side; the web surface belongs to the inspector.
 */
function xeric_director_cancel(PDO $db, int $id, int $epoch): ?array
{
    foreach (xeric_director_rows($db) as $s) {
        if ($s['id'] !== $id) continue;
        if (xeric_director_state_at($s, $epoch) === 'done' || $s['state'] === 'cancelled') return null;
        $q = $db->prepare("UPDATE director_stagings SET state = 'cancelled', ended = ? WHERE id = ?");
        $q->execute([$epoch, $id]);
        $s['state'] = 'cancelled';
        $s['ended'] = $epoch;
        return $s;
    }
    return null;
}

/**
 * The inspector's seam (why.php): what is staged now, what is coming, and what
 * finished or was cancelled in the last week, each with its reason. Reads only.
 *
 * @param int|array $now an epoch or a xeric_world_now() moment
 */
function xeric_director_why(PDO $db, $now): array
{
    $epoch = is_array($now) ? (int)($now['epoch'] ?? 0) : (int)$now;
    $out = ['live' => [], 'planned' => [], 'recent' => []];
    foreach (xeric_director_rows($db) as $s) {
        $s['state'] = xeric_director_state_at($s, $epoch);
        if ($s['state'] === 'live')    { $out['live'][] = $s; continue; }
        if ($s['state'] === 'planned') { $out['planned'][] = $s; continue; }
        if ((int)($s['ended'] ?? $s['to']) >= $epoch - 7 * 86400) $out['recent'][] = $s;
    }
    $out['recent'] = array_reverse($out['recent']);
    return $out;
}

// ---------------------------------------------------------------------------
// The rules
// ---------------------------------------------------------------------------

/** "nine" for 9, digits past twenty — the inspector reads it as a sentence. */
function xeric_director_count(int $n): string
{
    $w = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven',
          'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];
    return $w[$n] ?? (string)$n;
}

/** Living, in-story characters, in cast order. */
function xeric_director_people(array $t, PDO $db): array
{
    $dead = array_flip(xeric_dead_handles($db));
    $out = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || !empty($c['out']) || isset($dead[$h])) continue;
        $out[$h] = $c;
    }
    return $out;
}

/**
 * Where these people might plausibly be together, best first: a circle's
 * hangout they all belong to, a place in all their weeks, a place in any of
 * their weeks, then a home of one of them.
 */
function xeric_director_haunts(array $t, array $who): array
{
    $orbit = [];
    $weeks = [];
    foreach ($who as $h) {
        $c = xeric_world_character($t, $h) ?? [];
        $orbit[$h] = (string)($c['orbit'] ?? '');
        $weeks[$h] = [];
        foreach ((array)($c['week'] ?? []) as $w) {
            $k = (string)($w['where'] ?? '');
            if ($k !== '') $weeks[$h][$k] = true;
        }
    }
    $out = [];
    foreach ((array)($t['cast']['circles'] ?? []) as $ci) {
        $hang = (string)($ci['hangout_place'] ?? '');
        if ($hang === '') continue;
        $orbits = array_map('strval', (array)($ci['members_from_orbits'] ?? []));
        $members = array_map('strval', (array)($ci['members'] ?? []));
        $all = true;
        foreach ($who as $h) if (!in_array($orbit[$h], $orbits, true) && !in_array($h, $members, true)) $all = false;
        if ($all) $out[$hang] = true;
    }
    $common = null;
    foreach ($weeks as $set) $common = $common === null ? $set : array_intersect_key($common, $set);
    foreach (array_keys($common ?? []) as $k) $out[$k] = true;
    foreach ($weeks as $set) foreach (array_keys($set) as $k) $out[$k] = true;
    foreach ($who as $h) { $home = xeric_world_home_of($t, $h); if ($home !== null) $out[$home] = true; }
    return array_values(array_filter(array_keys($out), fn($k) => xeric_world_place($t, (string)$k) !== null));
}

/**
 * The next whole hour from $epoch at which these people can be staged at one
 * of $places: open, not quiet hours, everybody free (no week block) or already
 * there, and the full check passing. Returns [from, to, where] or null.
 *
 * @param callable|null $okHour extra gate on the moment (the player rule's shifts)
 */
function xeric_director_slot(array $t, PDO $db, array $who, array $places, int $epoch, array $cfg,
                             ?callable $okHour = null): ?array
{
    unset($t['_director']);
    $dead = xeric_dead_handles($db);
    $base = $epoch - ($epoch % 3600);
    for ($i = 1; $i <= $cfg['lookahead']; $i++) {
        $from = $base + $i * 3600;
        $to   = $from + $cfg['length'];
        $now  = xeric_world_now($t, $from);
        if (xeric_sweep_quiet($t, $now)) continue;
        if ($okHour !== null && !$okHour($now)) continue;
        $pres = xeric_world_who_is_where($t, $now, $dead);
        foreach ($places as $place) {
            $free = true;
            $moves = 0;                                   // a staging nobody walks into is not one
            foreach ($who as $h) {
                $row = $pres[$h] ?? null;
                if ($row === null) { $free = false; break; }
                if (($row['where'] ?? null) === $place) continue;
                $busy = ($row['where'] ?? null) !== null && empty($row['at_home']);
                if ($busy) { $free = false; break; }
                $moves++;
            }
            if (!$free || $moves === 0) continue;
            $s = ['who' => $who, 'where' => $place, 'from' => $from, 'to' => $to];
            if (xeric_director_check($t, $db, $s, $epoch) === null) return [$from, $to, $place];
        }
    }
    return null;
}

/** Live narrator intents, in the leans' shape (n, participants, kind, place). Read only. */
function xeric_director_intents(array $t, PDO $db, int $epoch, array $opts): array
{
    if (isset($opts['intents'])) {
        return is_callable($opts['intents']) ? (array)($opts['intents'])($t, $db, $epoch) : (array)$opts['intents'];
    }
    if (function_exists('xeric_narrator_leans')) return xeric_narrator_leans($t, $db, $epoch, ['settle' => false]);
    // narrator.php not loaded: the same rows, read where they live (world_state intent:<n>).
    $out = [];
    foreach (xeric_world_state_all($db) as $k => $v) {
        if (!preg_match('/^intent:(\d+)$/', (string)$k, $m)) continue;
        $in = json_decode((string)$v, true);
        if (!is_array($in) || ($in['state'] ?? '') !== 'live' || $epoch >= (int)($in['fades'] ?? 0)) continue;
        $out[] = ['n' => (int)$m[1], 'participants' => array_map('strval', (array)($in['participants'] ?? [])),
                  'kind' => $in['kind'] ?? null, 'place' => $in['place'] ?? null];
    }
    return $out;
}

/** Rule `narrator`: a live intent naming two or more people, for a kind that needs them to meet. */
function xeric_director_rule_narrator(array $t, PDO $db, int $epoch, array $cfg, array $opts): array
{
    $made = array_column(xeric_director_rows($db), 'source');
    $people = xeric_director_people($t, $db);
    $out = [];
    foreach (xeric_director_intents($t, $db, $epoch, $opts) as $in) {
        $n = (int)($in['n'] ?? 0);
        $kind = isset($in['kind']) && (string)$in['kind'] !== '' ? (string)$in['kind'] : null;
        if ($n <= 0 || in_array('narrator:' . $n, $made, true)) continue;
        if ($kind !== null && in_array($kind, XERIC_DIRECTOR_SOLITARY, true)) continue;
        $who = array_values(array_filter(array_unique(array_map('strval', (array)($in['participants'] ?? []))),
            fn($h) => isset($people[$h])));
        if (count($who) < 2) continue;
        $who = array_slice($who, 0, 3);
        $place = isset($in['place']) && xeric_world_place($t, (string)$in['place']) !== null ? [(string)$in['place']] : [];
        $out[] = [
            'who' => $who, 'places' => $place !== [] ? $place : xeric_director_haunts($t, $who),
            'why' => 'the narrator intends ' . ($kind !== null ? 'a ' . str_replace('_', ' ', $kind) . ' hour' : 'something')
                     . ' between ' . implode(' and ', array_map(fn($h) => xeric_world_name($t, $h), $who)),
            'source' => 'narrator:' . $n,
        ];
    }
    return $out;
}

/** Rule `apart`: two people of one orbit not in an hour together (per events) for apart_days. */
function xeric_director_rule_apart(array $t, PDO $db, int $epoch, array $cfg, array $opts): array
{
    $rows = $db->query('SELECT world_epoch, participants FROM events WHERE world_epoch <= ' . $epoch)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];                              // no record yet: nothing to measure apart from
    $start = PHP_INT_MAX;
    $last  = [];
    foreach ($rows as $r) {
        $e = (int)$r['world_epoch'];
        $start = min($start, $e);
        $ps = array_values(array_unique(array_map('strval', (array)(json_decode((string)$r['participants'], true) ?: []))));
        sort($ps);
        foreach ($ps as $i => $a) foreach (array_slice($ps, $i + 1) as $b) {
            $k = $a . '|' . $b;
            if (!isset($last[$k]) || $last[$k] < $e) $last[$k] = $e;
        }
    }

    $recent = [];
    foreach (xeric_director_rows($db) as $s) {
        if ($s['source'] !== 'apart' || $s['state'] === 'cancelled') continue;
        if ($s['made'] >= $epoch - $cfg['apart_days'] * 86400) {
            $w = $s['who']; sort($w); $recent[implode('|', $w)] = true;
        }
    }

    $people = xeric_director_people($t, $db);
    $handles = array_keys($people);
    $cands = [];
    foreach ($handles as $i => $a) foreach (array_slice($handles, $i + 1) as $b) {
        $oa = (string)($people[$a]['orbit'] ?? '');
        if ($oa === '' || $oa !== (string)($people[$b]['orbit'] ?? '')) continue;
        $pair = [$a, $b]; sort($pair);
        $k = implode('|', $pair);
        if (isset($recent[$k])) continue;
        $since = $last[$k] ?? $start;
        $days = intdiv($epoch - $since, 86400);
        if ($days < $cfg['apart_days']) continue;
        $cands[] = ['days' => $days, 'who' => [$a, $b]];
    }
    usort($cands, fn($x, $y) => $y['days'] <=> $x['days']);
    $out = [];
    foreach ($cands as $c) {
        $out[] = ['who' => $c['who'], 'places' => array_values(array_filter(xeric_director_haunts($t, $c['who']),
                      fn($k) => strtolower((string)(xeric_world_place($t, $k)['kind'] ?? '')) !== 'home'))
                      ?: xeric_director_haunts($t, $c['who']),
                  'why' => 'they have not been in a room together for ' . xeric_director_count($c['days']) . ' days',
                  'source' => 'apart'];
    }
    return $out;
}

/** The player's shifts as a gate on a moment, or null when the template gives none. */
function xeric_director_shift_gate(array $t): ?callable
{
    $shifts = (array)($t['user']['occupation']['shifts'] ?? []);
    if ($shifts === []) return null;
    $names = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    return function (array $now) use ($shifts, $names): bool {
        $mins = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
        $dow = (int)($now['dow'] ?? 0);
        foreach ($shifts as $s) {
            $days = [];
            foreach ((array)($s['days'] ?? []) as $d) {
                $d = strtolower(substr((string)$d, 0, 3));
                if (isset($names[$d])) $days[] = $names[$d]; elseif (is_numeric($d)) $days[] = (int)$d;
            }
            $w = ['days' => $days, 'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null];
            if (xeric_world_week_covers($w, $dow, ($dow + 6) % 7, $mins)) return true;
        }
        return false;
    };
}

/**
 * Rule `player`: the player's standing with two people is moving apart — the
 * gap between what each thinks of them has grown since the reference reading
 * (refreshed daily) and stands at player_gap or more. Stage them where the
 * player is likely to be: the workplace, else wherever they are standing.
 */
function xeric_director_rule_player(array $t, PDO $db, int $epoch, array $cfg, array $opts): array
{
    $people = xeric_director_people($t, $db);
    $cur = [];
    foreach (array_keys($people) as $h) $cur[$h] = xeric_trust_of($db, (string)$h);

    $raw = xeric_world_state_get($db, 'director.standings');
    $ref = $raw !== null ? json_decode($raw, true) : null;
    if (!is_array($ref) || $epoch - (int)($ref['epoch'] ?? 0) >= 86400) {
        xeric_world_state_set($db, 'director.standings', json_encode(['epoch' => $epoch, 'trust' => $cur]));
    }
    if (!is_array($ref)) return [];
    $then = (array)($ref['trust'] ?? []);

    $place = null;
    $wk = $t['user']['occupation']['workplace_key'] ?? null;
    if (is_string($wk) && xeric_world_place($t, $wk) !== null) $place = $wk;
    $place ??= xeric_player_where($t, $db);
    if ($place === null) return [];

    $recent = [];
    foreach (xeric_director_rows($db) as $s) {
        if ($s['source'] === 'player' && $s['state'] !== 'cancelled' && $s['made'] >= $epoch - $cfg['apart_days'] * 86400) {
            $w = $s['who']; sort($w); $recent[implode('|', $w)] = true;
        }
    }

    $hs = array_keys($cur);
    $cands = [];
    foreach ($hs as $i => $a) foreach (array_slice($hs, $i + 1) as $b) {
        $pair = [(string)$a, (string)$b]; sort($pair);
        if (isset($recent[implode('|', $pair)])) continue;
        $now  = abs($cur[$a] - $cur[$b]);
        $was  = abs((int)($then[$a] ?? 0) - (int)($then[$b] ?? 0));
        if ($now < $cfg['player_gap'] || $now <= $was) continue;
        $cands[] = ['grew' => $now - $was, 'gap' => $now, 'who' => [(string)$a, (string)$b]];
    }
    usort($cands, fn($x, $y) => [$y['grew'], $y['gap']] <=> [$x['grew'], $x['gap']]);
    $out = [];
    foreach (array_slice($cands, 0, 1) as $c) {
        $out[] = ['who' => $c['who'], 'places' => [$place], 'gate' => xeric_director_shift_gate($t),
                  'why' => "the player's standing with both is moving apart", 'source' => 'player'];
    }
    return $out;
}

/**
 * The Director's hour. Run by the heart once per world-hour; no model, cheap.
 * Advances states, then lets each rule the template lists propose stagings,
 * and makes the ones that fit. A template that lists no rule does nothing at
 * all — no table, no world_state — which is what keeps the baseline still.
 *
 * @param array $opts force (ignore the once-per-hour guard), intents (a list
 *                    or callable standing in for the narrator's read)
 * @return array{staged:array,notes:string[]}
 */
function xeric_director_tick(array $t, PDO $db, array $now, array $opts = []): array
{
    $epoch = (int)($now['epoch'] ?? 0);
    $cfg = xeric_director_config($t);
    if ($cfg['rules'] === []) return ['staged' => [], 'notes' => ['the Director is off: director.rules lists nothing']];

    xeric_director_ensure($db);
    xeric_director_advance($db, $epoch);

    $hour = intdiv($epoch, 3600);
    if (empty($opts['force']) && (int)xeric_world_state_get($db, 'director.hour', '0') >= $hour) {
        return ['staged' => [], 'notes' => ['already directed this hour']];
    }
    xeric_world_state_set($db, 'director.hour', $hour);

    $staged = [];
    $notes = [];
    foreach ($cfg['rules'] as $rule) {
        $fn = 'xeric_director_rule_' . $rule;
        foreach ($fn($t, $db, $epoch, $cfg, $opts) as $p) {
            $slot = xeric_director_slot($t, $db, $p['who'], (array)$p['places'], $epoch, $cfg, $p['gate'] ?? null);
            if ($slot === null) { $notes[] = $rule . ': no hour in reach for ' . implode(' and ', $p['who']); continue; }
            [$from, $to, $where] = $slot;
            try {
                $staged[] = xeric_director_stage($t, $db, ['who' => $p['who'], 'where' => $where, 'from' => $from,
                    'to' => $to, 'why' => $p['why'], 'source' => $p['source']], $epoch);
            } catch (RuntimeException $e) {
                $notes[] = $rule . ': ' . $e->getMessage();
            }
        }
    }
    return ['staged' => $staged, 'notes' => $notes];
}
