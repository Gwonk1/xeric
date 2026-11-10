<?php
/**
 * where.php — the town, as data, and the one verb that changes it.
 *
 *     GET  where.php?w=<slug>        → the map at this moment
 *     POST where.php {world, to}     → go there; burns the minutes; returns the map
 *
 * Both for the CURRENT player — whoever this session is in this world (the
 * owner, or a guest who joined on a code): `you` is them, the other players
 * stand in `who` with `player: true`, and a trip moves their body alone.
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
// belong to whoever is actually asking. Resolved through the engine's seam
// (play-map.php's xeric_play_player_of), which reads it off WHICH FILE IS
// OPEN: a visitor's own fork is theirs and they are its first person.
$player = xeric_player_current(null, $w['db']);

if (!$post) {
    xeric_web_json(['ok' => true] + xeric_travel_map($w['template'], $w['db'], null, $player));
}

// Reading the map is everybody's, and so, now, is WALKING — each person their
// own body. Walking was the owner's alone while a world had one body, because
// a guest walking it was a guest walking the owner's character across town.
// With a body each (engine/travel.php, FUTURE.md M4), a guest who came in on
// a pairing code walks THEMSELVES: the trip moves only the current player
// (xeric_player_current, resolved from this session in play-map.php) and
// spends the shared clock everybody in the world lives on. A stranger on a
// shared host still walks only their own fork, which is all they ever had
// open. What stays the owner's — fate, the power switch, photos — stays
// refused at those doors.
//
// A guest on the canonical file with no seat in its roster is not a joined
// player, it is a hole, and is refused as the guest it looks like.
if (xeric_play_is_guest($w) && xeric_session_player((string)$w['slug'], $sid) === null) {
    xeric_play_owner_only($w, 'walking about the place');
}

// THE METER SITS WHERE THE SPEND ACTUALLY IS. Walking itself calls no model
// (the header above), so nothing is checked here — the skip budget is
// consulted inside xeric_play_trip_job, for EVERYBODY alike, on exactly the
// walks that would spawn the sweep worker (real model calls, the same spend
// tick.php meters unconditionally). Metering guests only, at this door, would
// let owners and strangers on their own fork spawn workers with no limit
// consulted while a guest could be refused the WALK itself — and movement is
// never the thing being rationed. Over budget, the trip still lands; the hours
// pass quietly and the response's `sweep.note` says so.

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

// WALKING OUT OF THE ROOM (engine/room.php). Only the walker's own seat: a
// person who never sat at the table walking anywhere touches nothing. With
// somebody else still standing at it, the walker "heads out" — a narrator row,
// their seat given up, the conversation going on without them. The last one
// out seals it, the moment the body moves, so the room cannot answer somebody
// who has left; its close (the diaries, one event) lands in a worker, never in
// this request.
if (($trip['to'] ?? null) !== null || ($trip['from'] ?? null) !== null) {
    try {
        $walker = $player;
        $nowW   = xeric_clock_now($w['db'], $w['template']);
        $sealed = xeric_room_live_edit($w['db'], function (?array &$live) use ($w, $walker, $nowW, $trip): ?int {
            if ($live === null || xeric_room_player_of($live, $walker) === null) return null;
            if ((string)$live['place'] === (string)($trip['to'] ?? '')) return null;
            return xeric_play_room_unseat($w['template'], $w['db'], $live, $walker, $nowW, 'heads out',
                count(xeric_room_players_ever($live)) > 1 ? 'everyone left' : 'you left');
        });
        if ($sealed !== null) {
            xeric_web_spawn(xeric_web_job_new(), ['slug' => (string)$w['slug'], 'sid' => xeric_session_id(),
                'db' => (string)$w['db_path'], 'adult' => xeric_session_adult(), 'lease' => ''], 'room-worker.php');
        }
    } catch (Throwable $e) { /* the heart lands what this could not */ }
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
    ] + (isset($trip['players']) ? ['players' => $trip['players']] : []),   // the other players there, if any
] + ($sweep !== null ? ['sweep' => $sweep] : [])
  + xeric_travel_map($w['template'], $w['db'], $trip['now'], $player));
