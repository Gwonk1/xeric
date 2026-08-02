<?php
/**
 * Xeric — seed history. The past a world arrives with.
 *
 * The forge writes two files: world-template.json (the world) and seed.json
 * (what has already happened in it). The template is loaded on every turn; the
 * seed is loaded exactly ONCE, into the same tables a lived week would fill.
 * After that the seeded past and the lived past are indistinguishable, which is
 * the point — turn one has to feel like turn two hundred.
 *
 * Three disciplines:
 *
 *  1. DAYS_AGO IS NOT A TIMESTAMP. The forge writes "12 days ago" because it has
 *     no idea when the world will be launched. This file is where that becomes a
 *     real world epoch, measured back from the launch moment the CALLER passes
 *     in. Nothing here reads the clock; a world launched on a shifted clock gets
 *     a past shifted with it.
 *
 *  2. APPLY ONCE, EVER. A second call must not double the history — a demo that
 *     re-seeds on every page load would grow a character's memory by three every
 *     minute. The guard is a marker row in world_state carrying the counts, so a
 *     repeat call is a no-op that can still answer "what did you write".
 *
 *  3. NOTHING DANGLING GETS IN. A model wrote this file; it will name people by
 *     display name, invent a place key, and address memories to somebody who was
 *     cut from the cast. References that do not resolve are dropped and counted,
 *     never stored — a memory in nobody's head is a row that can only ever
 *     confuse a later reader.
 *
 *     And nothing a turn could not say gets in either. A seed is model-written
 *     prose that lands in the permanent record and is read back into every later
 *     prompt as history, so it passes the same age floor a reply does — see the
 *     drops in xeric_seed_apply(), which are drops and not refusals on purpose.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
// The age floor lives in chat.php, next to the detector it reads with, and
// chat.php already requires this file for xeric_seed_norm(). require_once
// resolves the cycle either way round: whichever of the two is entered first
// is marked as included before it asks for the other, and neither file runs
// anything at include time but const and function definitions.
require_once __DIR__ . '/chat.php';

/** The world_state key that says this world's past has already been written. */
const XERIC_SEED_MARKER = 'seed_applied';

/**
 * Read a seed.json. Takes the file, or the world directory that holds it.
 *
 * A missing seed file is not an error: a hand-built world can have no baked
 * past at all, and the engine must launch it anyway.
 *
 * @return array{events:array,memories:array}
 * @throws RuntimeException when the file exists but is not readable JSON
 */
function xeric_seed_load(string $path): array
{
    if (is_dir($path)) $path = rtrim($path, '/') . '/seed.json';
    if (!is_file($path)) return ['events' => [], 'memories' => []];

    $raw = @file_get_contents($path);
    if ($raw === false) throw new RuntimeException('xeric: cannot read seed ' . $path);
    $d = json_decode($raw, true);
    if (!is_array($d)) throw new RuntimeException('xeric: bad JSON in ' . basename($path) . ' (' . json_last_error_msg() . ')');

    return [
        'events'   => array_values((array)($d['events'] ?? [])),
        'memories' => array_values((array)($d['memories'] ?? [])),
    ];
}

/** Has this world's baked past already been written? */
function xeric_seed_applied(PDO $db): bool
{
    return xeric_world_state_get($db, XERIC_SEED_MARKER) !== null;
}

/**
 * Write a world's baked past into events and memories. Idempotent.
 *
 * @param int $nowEpoch the WORLD's launch moment. Every days_ago is measured
 *                      back from here, so the past lands behind the present the
 *                      first prompt will describe.
 * @return array{events:int,memories:int,skipped:int,applied:bool}
 *         `applied` is false on the second and every later call; the counts are
 *         then the ones the first call wrote, read back from the marker.
 * @throws RuntimeException if a write fails — in which case nothing is written
 *         at all and the marker is not set, so the caller may simply try again.
 */
function xeric_seed_apply(PDO $db, array $template, array $seed, int $nowEpoch): array
{
    $prior = xeric_world_state_get($db, XERIC_SEED_MARKER);
    if ($prior !== null) {
        $p = json_decode($prior, true);
        $p = is_array($p) ? $p : [];
        return [
            'events'   => (int)($p['events'] ?? 0),
            'memories' => (int)($p['memories'] ?? 0),
            'skipped'  => (int)($p['skipped'] ?? 0),
            'applied'  => false,
        ];
    }

    $at      = xeric_state_time();
    $people  = xeric_seed_index($template);          // anyone a name can point at
    // Exact only: a memory addressed to somebody who has left the cast belongs
    // to nobody, not to whoever inherited their first name.
    $cast    = xeric_seed_index($template, true, true);
    $places  = [];
    foreach ((array)($template['places'] ?? []) as $p) {
        $k = (string)($p['key'] ?? '');
        if ($k !== '') $places[$k] = true;
    }

    $events = 0;
    $mems   = 0;
    $skip   = 0;

    // All of it or none of it: a half-applied past with the marker unset would
    // double on the retry, and with the marker set would be permanently short.
    $db->beginTransaction();
    try {
        foreach ((array)($seed['events'] ?? []) as $e) {
            if (!is_array($e)) { $skip++; continue; }
            $title = trim((string)($e['title'] ?? ''));
            $prose = trim((string)($e['prose'] ?? ''));
            if ($title === '' && $prose === '') { $skip++; continue; }
            if ($title === '') $title = xeric_seed_headline($prose);

            // `who` is what the forge's model was asked for; `participants` is
            // what the forge normalised it to. Accept either, resolve both.
            $whoRaw = (array)($e['participants'] ?? $e['who'] ?? []);
            $who    = [];
            foreach ($whoRaw as $w) {
                $h = xeric_seed_resolve((string)$w, $people);
                if ($h !== null && !in_array($h, $who, true)) $who[] = $h;
            }

            // THE AGE FLOOR, on the past. The hour is read whole — title, prose
            // and who was in the room together — because that is the only way a
            // name in one half and the thing being refused in the other reads as
            // one sentence.
            //
            // DROPPED, NOT THROWN, and this is the deliberate half. A seed is
            // applied exactly once, at the moment a world is opened; a refusal
            // here would take the whole past down with it and the visitor would
            // get a world with no history and no way to ask for one. One line of
            // a seed is worth less than every other line in it, so the row is
            // skipped and counted like any other row that could not be stored.
            if (xeric_age_floor($template, $who, [$title, $prose]) !== null) { $skip++; continue; }

            $place = (string)($e['place'] ?? '');
            $place = ($place !== '' && isset($places[$place])) ? $place : null;

            // ON THE SPINE, or not. A seeded event carrying what somebody in
            // this world must not learn has to be marked as such, or the sweeps
            // hand its title back in ALREADY HAPPENED to the very person the
            // wall exists to keep it from — and a seed title is written to NAME
            // the thing. The forge sets the flag when it can; anything hand
            // written, or seeded before this existed, is measured here.
            $spine = array_key_exists('on_spine', $e)
                ? (bool)$e['on_spine']
                : xeric_seed_touches_secret($template, $title . ' ' . $prose);

            xeric_event_add($db, $title, xeric_seed_epoch($nowEpoch, $e['days_ago'] ?? 7), $place, $who, $prose, $at, $spine);
            $events++;
        }

        foreach ((array)($seed['memories'] ?? []) as $m) {
            if (!is_array($m)) { $skip++; continue; }
            $text = trim((string)($m['text'] ?? ''));
            $who  = xeric_seed_resolve((string)($m['handle'] ?? ''), $cast, true);
            // A memory whose owner was cut from the cast is not a memory; it is
            // a row nobody will ever read and every debugger will trip over.
            if ($text === '' || $who === null) { $skip++; continue; }
            // Same floor, same drop. A memory is the more direct of the two
            // leaks: it is written INTO somebody's head and rendered into their
            // system prompt from turn one.
            if (xeric_age_floor($template, [$who], [$text]) !== null) { $skip++; continue; }

            xeric_memory_add($db, $who, $text, 'seed', ['seeded' => true],
                xeric_seed_epoch($nowEpoch, $m['days_ago'] ?? 7), $at);
            $mems++;
        }

        $counts = ['events' => $events, 'memories' => $mems, 'skipped' => $skip, 'at' => $at, 'now' => $nowEpoch];
        xeric_world_state_set($db, XERIC_SEED_MARKER, json_encode($counts, JSON_UNESCAPED_SLASHES), $at);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('xeric: seed could not be applied, ' . $e->getMessage(), 0, $e);
    }

    return ['events' => $events, 'memories' => $mems, 'skipped' => $skip, 'applied' => true];
}

/**
 * Does this seeded line carry what somebody in this world must not learn?
 *
 * The same content-word overlap the forge measures its own seed output with,
 * kept here as well because a seed.json can be hand written, hand edited, or
 * older than the check. Fails closed: a false positive costs one event its
 * place in a walled reader's ALREADY HAPPENED list, which is a smaller loss
 * than the alternative by a wide margin.
 */
function xeric_seed_touches_secret(array $template, string $text): bool
{
    $words = static function (string $s): array {
        $stop = ['about', 'after', 'again', 'been', 'being', 'could', 'does', 'doing', 'from', 'have',
                 'into', 'just', 'more', 'much', 'other', 'over', 'said', 'same', 'some', 'still',
                 'than', 'that', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'very',
                 'were', 'what', 'when', 'which', 'while', 'with', 'would', 'your'];
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($s)) ?? '';
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
            if (mb_strlen($w) >= 4 && !in_array($w, $stop, true)) $out[rtrim($w, 's')] = true;
        }
        return $out;
    };

    $said = $words($text);
    if ($said === []) return false;
    foreach ((array)($template['cast']['special_roles'] ?? []) as $sr) {
        $secret = $words((string)($sr['must_not_know'] ?? ''));
        if ($secret === []) continue;
        $shared = count(array_intersect_key($said, $secret));
        if ($shared >= (count($secret) > 1 ? 2 : 1)) return true;
    }
    return false;
}

/**
 * days_ago → a world epoch behind $nowEpoch.
 *
 * Fractions are honoured (0.5 = twelve hours ago) because a sweep will one day
 * write this shape too. Anything in the future is clamped to now: a "memory" of
 * something that has not happened is the one thing a memory cannot be.
 */
function xeric_seed_epoch(int $nowEpoch, $daysAgo): int
{
    $d = is_numeric($daysAgo) ? (float)$daysAgo : 7.0;
    if ($d < 0) $d = 0.0;
    return $nowEpoch - (int)round($d * 86400);
}

/**
 * name → handle, for everyone a seed row can point at.
 *
 * @param bool $charactersOnly fixtures are scenery: they can be AT an event but
 *                             they have no interior for a memory to live in.
 * @param bool $exactOnly      leave out the first-name entries, so a row naming
 *                             somebody who has left the cast resolves to nobody
 *                             instead of to their namesake. What a memory needs.
 * @return array<string,string> lookup key => handle
 */
function xeric_seed_index(array $template, bool $charactersOnly = false, bool $exactOnly = false): array
{
    $idx = [];
    $add = function (string $key, string $handle) use (&$idx): void {
        $key = xeric_seed_norm($key);
        // First writer wins: a display name that collides with somebody else's
        // first name must not steal the person who owns it outright.
        if ($key !== '' && !isset($idx[$key])) $idx[$key] = $handle;
    };

    foreach ((array)($template['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $add($h, $h);
        $add(str_replace('_', ' ', $h), $h);
        $name = (string)($c['display_name'] ?? '');
        if ($name !== '') $add($name, $h);
    }
    if (!$charactersOnly) {
        foreach ((array)($template['cast']['fixtures'] ?? []) as $f) {
            $k = (string)($f['key'] ?? '');
            if ($k === '') continue;
            // A fixture that IS a cast member resolves to the person, not the prop.
            $target = (string)($f['same_as'] ?? '') !== '' ? (string)$f['same_as'] : $k;
            $add($k, $target);
            $add(str_replace('_', ' ', $k), $target);
            $name = (string)($f['name'] ?? '');
            if ($name !== '') $add($name, $target);
        }
    }

    // First names last, so "Ruth Amberg" claims "ruth amberg" before "Ruth" is
    // handed to whoever happens to be first in the cast list. Omitted entirely
    // for an exact index — see xeric_seed_resolve()'s $strict.
    if (!$exactOnly) {
        foreach ((array)($template['cast']['characters'] ?? []) as $c) {
            $h    = (string)($c['handle'] ?? '');
            $name = (string)($c['display_name'] ?? '');
            if ($h === '' || $name === '') continue;
            $first = preg_split('/\s+/u', trim($name))[0] ?? '';
            if ($first !== '') $add($first, $h);
        }
    }

    return $idx;
}

/**
 * One name as written by a model → a handle, or null when it names nobody.
 *
 * $strict refuses the first-name guess. A name in a participant list is a name
 * in a participant list, and guessing there costs at worst an event about the
 * wrong Ruth — but a MEMORY is somebody's private history being written into a
 * head. A cast reroll leaves seed rows addressed to people who have left, and
 * the loose match hands a departed, possibly protected character's memories to
 * whoever in the new cast happens to share their first name, behind every wall
 * in the world, because at that point it IS their memory. So the memory side
 * asks for an exact handle or an exact display name, and files the rest under
 * "names nobody" — which is what a departed person is.
 */
function xeric_seed_resolve(string $who, array $index, bool $strict = false): ?string
{
    $k = xeric_seed_norm($who);
    if ($k === '') return null;
    if (isset($index[$k])) return $index[$k];

    // "Ruth Amberg said" and "ruth's" both still mean Ruth.
    $k = preg_replace('/\W+$/u', '', $k) ?? $k;
    if (isset($index[$k])) return $index[$k];

    if ($strict) return null;

    $first = preg_split('/\s+/u', $k)[0] ?? '';
    return ($first !== '' && isset($index[$first])) ? $index[$first] : null;
}

/** Lowercase, punctuation-free, single-spaced — the shape both sides compare in. */
function xeric_seed_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = str_replace('_', ' ', $s);
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', '', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** A title for an event that arrived with prose and no name. */
function xeric_seed_headline(string $prose): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $prose) ?? $prose);
    $cut = preg_split('/(?<=[.!?])\s/u', $s)[0] ?? $s;
    return mb_strlen($cut) > 90 ? rtrim(mb_substr($cut, 0, 90)) . '…' : $cut;
}
