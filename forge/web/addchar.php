<?php
/**
 * addchar.php — the endpoint behind the + beside the narrator.
 *
 * POST {world, name, about, orbit} and get back a job id. Writing the person and
 * weaving them into a town that has been running without them happens in
 * addchar-worker.php, detached, watched through progress.php — the same shape
 * the skip and the reroll use, and for the same reason: this is three or four
 * model calls, and every proxy in front of this app cuts a held response long
 * before that.
 *
 *     POST addchar.php         → { ok, job }
 *     GET  progress.php?job=…  → hello · note · done
 *
 * ADDING SOMEBODY IS NOT AN EDIT. A cog saves a field; this writes a whole
 * person into a world that already has a past, and a person nobody has ever met
 * is not yet a character — they are a row in a file. So the worker does not stop
 * at the template: it writes the hour they walked in as an event the town can
 * see, gives them memories of a place they are supposed to have been living in,
 * and gives the people most likely to have met them a memory of doing so. That
 * is the literary half, and without it the newcomer stands in the roster with
 * six weeks of history behind everybody else and nothing behind them.
 *
 * Everything answerable NOW is answered now: no such world, somebody else's
 * world, a model that is not answering, an hour's worth of work already spent.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';
require_once __DIR__ . '/review-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    xeric_web_json(['error' => 'adding somebody is a POST, {world, name, about, orbit}'], 405);
}

$in   = xeric_web_input();
$slug = xeric_web_slug((string)($in['world'] ?? $in['w'] ?? ''));
$sid  = xeric_session_id();

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}

// THE TEMPLATE IS THE OWNER'S. A visitor plays their own copy of the database,
// but there is only ever one cast list on disk — so a stranger adding somebody
// would be writing a person into somebody else's town. Same refusal the cog and
// the pill make, for the same reason.
if (!$w['mine']) {
    xeric_web_json(['error' => 'This xeric was forged in a different browser, so its cast is not yours '
        . 'to add to. Forge your own and everyone in it is yours.'], 403);
}

$name  = trim(mb_substr((string)($in['name'] ?? ''), 0, 80));
$about = trim(mb_substr((string)($in['about'] ?? ''), 0, 240));
$orbit = trim((string)($in['orbit'] ?? ''));

// TWO WAYS TO PUT SOMEBODY IN A TOWN, and they are different stories.
//   woven    — they have been at the edge of this place all along. The hour
//              they walked in is written, they carry memories, and the people
//              who were standing there remember meeting them.
//   stranger — nobody has ever seen them before. One pass, no shared past, and
//              the first thing anybody learns about them they learn from you.
$mode = (string)($in['mode'] ?? 'woven') === 'stranger' ? 'stranger' : 'woven';

// The orbit has to be one this world actually has: it is what the privacy walls
// aim at, so a made-up key would put somebody in the cast behind no wall at all.
$orbits = [];
foreach ((array)($w['template']['cast']['orbits'] ?? []) as $o) {
    $k = (string)($o['key'] ?? '');
    if ($k !== '' && $k !== 'extras') $orbits[] = $k;
}
if ($orbits === []) $orbits = ['outside'];
if (!in_array($orbit, $orbits, true)) $orbit = $orbits[0];

// A cast has a ceiling for the same reason the forge does: every character is in
// every other character's prompt, and the bill for a skip is the square of this.
$have = count((array)($w['template']['cast']['characters'] ?? []));
if ($have >= 12) {
    xeric_web_json(['error' => 'This xeric already has ' . $have . ' people in it. Everybody is in everybody '
        . 'else\'s head here, so a bigger cast costs more per hour than it is worth. Reroll somebody '
        . 'you have gone off instead.'], 409);
}

xeric_limit_guard(xeric_limit_check('reroll', ['sid' => $sid]));

try {
    $endpoint = xeric_play_endpoint();
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409);
}
if (!xeric_llm_up($endpoint, 8)) {
    xeric_web_json([
        'error' => 'The model this xeric runs on is not answering, so nobody can be written into it yet. '
            . 'The cast is exactly as you left it. Try again shortly.',
        'kind'  => 'model_down',
    ], 503);
}

if (xeric_queue_drained()) {
    $r = xeric_queue_drained_no();
    xeric_web_json(['error' => (string)$r['message'], 'kind' => 'drained',
                    'retry_after' => (int)$r['retry_after']], 503);
}
$why = null;
$ticket = xeric_queue_join('reroll', $sid, $why);
if ($ticket === '') {
    $r = is_array($why) ? $why : xeric_queue_no('full', 'reroll');
    $retry = (int)($r['retry_after'] ?? 60);
    if (!headers_sent()) header('Retry-After: ' . $retry);
    xeric_web_json(['error' => (string)$r['message'], 'kind' => (string)$r['kind'], 'retry_after' => $retry],
                   ($r['kind'] ?? '') === 'yours' ? 429 : 503);
}

$job = xeric_web_job_new();
xeric_web_job_sweep();

try {
    xeric_web_spawn($job, ['slug' => $w['slug'], 'sid' => $sid, 'db' => $w['db_path'],
                           'ticket' => $ticket, 'name' => $name, 'about' => $about,
                           'orbit' => $orbit, 'mode' => $mode,
                           'adult' => xeric_session_adult($sid)],
                    'addchar-worker.php');
} catch (Throwable $e) {
    xeric_queue_leave($ticket);
    xeric_web_json(['error' => 'nobody could be written just now: ' . $e->getMessage()], 500);
}

xeric_limit_note('reroll', ['sid' => $sid]);

$first = xeric_web_job_await($job, 8.0);
if ($first !== null && $first['k'] === 'error') {
    $kind = (string)($first['rec']['kind'] ?? 'addchar');
    xeric_web_json(['error' => (string)$first['rec']['message'], 'kind' => $kind],
                   in_array($kind, ['queued', 'drained', 'full'], true) ? 503 : 500);
}
if ($first === null && !is_file(xeric_web_job_path($job))) {
    xeric_web_json(['error' => 'nobody was written, the process never answered'], 500);
}

xeric_web_json(['ok' => true, 'job' => $job]);
