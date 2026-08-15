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
 * WHAT A TRIP NOW DOES ABOUT SWEEPS. Travel time is lived through the way
 * skipped time is: when a landed trip crossed a sweep window, this POST also
 * starts the existing tick-worker over the walked stretch (walked mode — the
 * clock already moved, so the worker advances nothing and simply lives the
 * hours behind it), and the response carries the job under `sweep` so a client
 * can stream them exactly as it streams a skip. The WALK itself never waits:
 * the trip answers inline as it always did, the sweep takes the normal ticket
 * path behind whoever is talking, and a refused or queued sweep is a note in
 * the stream — a sweep the skip budget declines is a note on the response
 * (`sweep.note`, xeric_play_trip_job) — never a blocked trip. Sweeps are
 * idempotent per window, so racing the heart — or a second walk — is already
 * safe.
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

// WHOSE FEET. Anybody who came through a pairing code walks their own body
// (the suffix discipline); the map's 'here', the trip, and the arrival all
// belong to whoever is actually asking.
$player = xeric_session_player($slug, $sid) ?? XERIC_PLAYER_FIRST;

if (!$post) {
    xeric_web_json(['ok' => true] + xeric_travel_map($w['template'], $w['db'], null, $player));
}

// Reading the map is everybody's. WALKING used to be the owner's alone, and
// the rule was right when it was written: there was ONE body, so a guest
// walking would have moved the owner's feet. Every seat has its own body now
// (travel.php's suffix discipline) — a guest walks their own, a stranger
// still moves around their own fork freely. And the METER sits where the
// spend actually is: walking itself calls no model (the header above), so
// nothing is checked here — the skip budget is consulted inside
// xeric_play_trip_job, for EVERYBODY alike, on exactly the walks that would
// spawn the sweep worker (real model calls, the same spend tick.php meters
// unconditionally). The old shape metered guests only, at this door: owners
// and strangers on their own fork spawned workers with no limit consulted,
// while a guest could be refused the WALK itself — and movement is never the
// thing being rationed. Over budget, the trip still lands; the hours pass
// quietly and the response's `sweep.note` says so.

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

$trip = xeric_travel_go($w['template'], $w['db'], $to === '' ? null : $to, $player);

if (!$trip['ok']) {
    xeric_web_json(['error' => (string)$trip['error'], 'kind' => 'travel'], 400);
}

// -- the hours the walk burned, lived ----------------------------------------
// The trip has already landed; this only STARTS the job (xeric_play_trip_job
// owns every refusal) and reports it so the page can stream the walked hours
// the way it streams a skip. Null when the walk never crossed a window, or
// nothing is attached — the map below is the answer either way. A walk the
// skip budget declines comes back as {note} instead of {job}: the walk stood,
// the sweep did not, and the sentence rides under `sweep` so a client can say
// so.
$sweep = xeric_play_trip_job($w, $trip, $sid);

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
] + ($sweep !== null ? ['sweep' => $sweep] : [])
  + xeric_travel_map($w['template'], $w['db'], $trip['now'], $player));
