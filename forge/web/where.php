<?php
/**
 * where.php — the town, as data, and the one verb that changes it.
 *
 *     GET  where.php?w=<slug>        → the map at this moment
 *     POST where.php {world, to}     → go there; burns the minutes; returns the map
 *
 * THIS FILE IS THE ARCHITECTURE, not a feature. Everything spatial anybody ever
 * builds on top of Xeric — a clickable map, a first-person view, a headset, the
 * row of buttons play.php draws today — is a CLIENT of this endpoint. The bet
 * being made is that the surface is the cheap, replaceable, one-shottable part
 * and the world model is not, and that bet only pays as long as no renderer ever
 * has to work out for itself who is standing where or what a walk costs. If a
 * client needs a fact about position that is not in this response, the fix is in
 * engine/travel.php, never in the client.
 *
 * WHY GO IS A POST AND SKIPPING TIME IS A JOB. The time control detaches a
 * worker (tick.php) because a six-hour skip is several model calls and the host
 * cuts long requests. Travelling calls no model at all: it moves the clock
 * offset, writes one world_state row, and reads presence back out. That is a
 * few milliseconds, so it answers inline — and it costs nothing metered, takes
 * no place in the model queue, and cannot fail because somebody else is busy.
 * Walking across town should never be something a person has to wait in line for.
 *
 * WHAT A TRIP DOES NOT DO: run sweeps. Twenty minutes of walking is twenty
 * minutes nothing happened in. That is a deliberate cheap version — the honest
 * one is that travel time should be lived through the way skipped time is, and
 * when that changes, it changes in tick-worker.php and this file stays as it is.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in   = $post ? xeric_web_input() : $_GET;
$slug = xeric_web_slug((string)($in['world'] ?? $in['w'] ?? ''));
$sid  = xeric_session_id();

// Opening it is also what forks it: somebody else's world is walked around in
// this visitor's own copy, and their position is theirs (session.php).
try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}

if (!$post) {
    xeric_web_json(['ok' => true] + xeric_travel_map($w['template'], $w['db']));
}

// -- go -----------------------------------------------------------------------
// `to` absent, null or empty means leaving the map: going off on your own time
// is a trip like any other and costs what getting home costs. It is the only
// destination that is not a place, and a client does not have to know that.
//
// NOT run through xeric_web_slug(): that is for world slugs and it rewrites
// anything outside [a-z0-9-], which turns `the_mill` into `the-mill` — a place
// that does not exist, refused with a message about a mill that plainly does.
// The template is the whitelist here. A key that is not in it is refused by
// xeric_travel_go() by name, which is both stricter than a character filter and
// the only check that can actually be right.
$to = array_key_exists('to', $in) ? $in['to'] : null;
$to = is_string($to) ? trim($to) : null;
if ($to !== null && strlen($to) > 64) {
    xeric_web_json(['error' => 'There is no such place in this world.', 'kind' => 'travel'], 400);
}

$trip = xeric_travel_go($w['template'], $w['db'], $to === '' ? null : $to);

if (!$trip['ok']) {
    xeric_web_json(['error' => (string)$trip['error'], 'kind' => 'travel'], 400);
}

// The arrival is reported from the moment AFTER the clock moved, which is the
// whole point of the feature: the twenty minutes it took to get out there are
// twenty minutes in which she left. The map is recomputed rather than patched
// for the same reason play.php sends rendered HTML back after a turn — one
// reader, so what you walked into and what you are looking at cannot disagree.
xeric_web_json([
    'ok'      => true,
    'went'    => [
        'from'    => $trip['from'],
        'to'      => $trip['to'],
        'minutes' => (int)$trip['minutes'],
        'open'    => (bool)$trip['open'],
        'who'     => array_map(
            fn(string $h): array => ['handle' => $h, 'name' => xeric_world_name($w['template'], $h)],
            (array)$trip['who']
        ),
        // The narrator's arrival beat: assembled from observables in the
        // engine (xeric_travel_scene), never asked of a model. '' when there
        // is nothing to say, and the client falls back to its plain line.
        'scene'   => (string)($trip['scene'] ?? ''),
    ],
] + xeric_travel_map($w['template'], $w['db'], $trip['now']));
