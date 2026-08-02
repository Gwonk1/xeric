<?php
/**
 * tick.php — the endpoint behind the time control.
 *
 * POST {world, span} and get back a job id. The world moving happens in
 * tick-worker.php, detached, and the browser watches it through progress.php —
 * the forge's own SSE tail, unchanged, because a job file is a job file and
 * duplicating a hundred lines of resumable stream to rename the events would be
 * a worse idea than reusing it.
 *
 *     POST tick.php            → { ok, job, stream, span, to }
 *     GET  progress.php?job=…  → hello · note · event · ping · quiet · done
 *
 * WHY NOT JUST DO THE WORK HERE. A six-hour skip is two to four model calls; a
 * skip to tomorrow morning is more. Cloudflare cuts this host at ~120 seconds
 * and mod_php's max_execution_time is 30. Either one turns "watch the world move
 * without you" into a truncated response and a world half-swept. Detached worker
 * plus append-only progress file plus SSE resumed by Last-Event-ID is the shape
 * that survives all of it, and the build already proved it.
 *
 * Everything answerable NOW is answered now, synchronously, so nobody watches a
 * feed that was doomed before it opened: no such world, a model that is not
 * answering at all, an hour's worth of skips already spent.
 *
 * A BUSY MODEL IS NOT ONE OF THOSE ANY MORE. It used to be a 409. Now this takes
 * a place in the line (queue.php) and hands it to the worker, which waits its
 * turn and streams the position down the same SSE as everything else — so the
 * second visitor to press the button sees "you are next in line — about 40
 * seconds" and then watches their evening happen, instead of being told to try
 * again by a machine that could simply have remembered they asked.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    xeric_web_json(['error' => 'the time control is a POST, {xeric, span}'], 405);
}

$in   = xeric_web_input();
$slug = xeric_web_slug((string)($in['world'] ?? $in['w'] ?? ''));
$sid  = xeric_session_id();

// Opening it is also what forks it: a world this visitor did not forge is played
// against their own copy of the database, made here on first entry (session.php).
try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}

// -- how far, and is that a real distance ------------------------------------
// The span is computed HERE from the world's own clock, never trusted from the
// page: "skip to evening" is four hours at three in the afternoon and twenty-two
// at nine at night, and only the server knows which. A caller may also name a
// raw span ("6h", "90m") the way the CLI does; xeric_clock_span() is the one
// reader of both, and xeric_clock_advance() is the one thing that enforces the
// ceiling.
// A STOPPED WORLD IS NOT SKIPPABLE, and it is refused here rather than deeper
// down: xeric_clock_advance() would throw anyway, but by then this request has
// probed the model, taken a place in the queue and detached a worker to
// discover it. Everything answerable now is answered now (see the header).
if (xeric_clock_is_paused($w['db'])) {
    xeric_web_json([
        'error' => 'This xeric is stopped, so there are no hours to live through. Attach a machine '
            . 'and it starts again on the second it stopped.',
        'kind'  => 'paused',
    ], 409);
}

$now   = xeric_clock_now($w['db'], $w['template']);
$spans = xeric_play_spans($now);
$want  = (string)($in['span'] ?? 'hour');

if (isset($spans[$want])) {
    $seconds = (int)$spans[$want]['seconds'];
    $label   = (string)$spans[$want]['label'];
} else {
    $seconds = xeric_clock_span($want) ?? 0;
    $label   = xeric_clock_span_label($seconds);
    if ($seconds <= 0) {
        xeric_web_json(['error' => "'" . mb_substr($want, 0, 40) . "' is not a stretch of time"], 400);
    }
    if ($seconds > XERIC_CLOCK_MAX_JUMP) {
        xeric_web_json(['error' => 'that is more than one press may move a xeric (max '
            . xeric_clock_span_label(XERIC_CLOCK_MAX_JUMP) . ')'], 400);
    }
}

// -- has this visitor an hour's worth of skips left? -------------------------
// Checked before the model is even probed, and charged below when the worker is
// actually away: a skip that could not start has not been spent.
xeric_limit_guard(xeric_limit_check('skip', ['sid' => $sid]));

try {
    $endpoint = xeric_play_endpoint();
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409);
}
if (!xeric_llm_up($endpoint, 8)) {
    xeric_web_json([
        'error' => 'The model this xeric runs on is not answering, so its hours cannot be lived through yet. '
            . 'The xeric is still exactly where you left it and its clock has not moved. Try again shortly.',
        'kind'  => 'model_down',
    ], 503);
}

// -- take a place in line ----------------------------------------------------
if (xeric_queue_drained()) {
    $r = xeric_queue_drained_no();
    xeric_web_json(['error' => (string)$r['message'], 'kind' => 'drained',
                    'retry_after' => (int)$r['retry_after']], 503);
}
$why = null;
$ticket = xeric_queue_join('tick', $sid, $why);
if ($ticket === '') {
    // Built, not fetched: asking the queue for a second ticket purely to read
    // its refusal off it can take the model and never give it back.
    $r = is_array($why) ? $why : xeric_queue_no('full', 'tick');
    $retry = (int)($r['retry_after'] ?? 60);
    if (!headers_sent()) header('Retry-After: ' . $retry);
    xeric_web_json(['error' => (string)$r['message'], 'kind' => (string)$r['kind'], 'retry_after' => $retry],
                   ($r['kind'] ?? '') === 'yours' ? 429 : 503);
}

// -- start it ----------------------------------------------------------------
$job = xeric_web_job_new();
xeric_web_job_sweep();

try {
    // The worker is told WHICH database to move: it has no cookie, and this
    // visitor may be playing their own copy of somebody else's world.
    xeric_web_spawn($job, ['slug' => $w['slug'], 'span' => $seconds, 'sid' => $sid,
                           'db' => $w['db_path'], 'ticket' => $ticket,
                           // The worker has no cookie; the affirmation travels
                           // with the job, exactly like the database path.
                           'adult' => xeric_session_adult($sid)], 'tick-worker.php');
} catch (Throwable $e) {
    xeric_queue_leave($ticket);
    xeric_web_json(['error' => 'the xeric could not be moved: ' . $e->getMessage()], 500);
}

xeric_limit_note('skip', ['sid' => $sid]);

$first = xeric_web_job_await($job, 8.0);
if ($first !== null && $first['k'] === 'error') {
    $kind = (string)($first['rec']['kind'] ?? 'tick');
    xeric_web_json(['error' => (string)$first['rec']['message'], 'kind' => $kind],
                   in_array($kind, ['queued', 'drained', 'full'], true) ? 503 : 500);
}
// Slow to speak but demonstrably alive: hand over the job rather than call a
// running skip dead. Only an empty job file after eight seconds is a worker that
// never got off the ground.
if ($first === null && !is_file(xeric_web_job_path($job))) {
    xeric_web_json(['error' => 'the xeric did not move, the process never answered'], 500);
}

// A skip this browser started is rejoinable after a reload, exactly like a build.
xeric_web_session_edit(function (array &$s) use ($w, $job): void {
    $s['tick'] = (array)($s['tick'] ?? []);
    $s['tick'][$w['slug']] = $job;
}, $sid);

xeric_web_json([
    'ok'     => true,
    'job'    => $job,
    'stream' => 'progress.php?job=' . rawurlencode($job),
    'span'   => xeric_clock_span_label($seconds),
    'label'  => $label,
    'from'   => xeric_play_when($now),
]);
