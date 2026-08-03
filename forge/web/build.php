<?php
/**
 * build.php — start a build, and say honestly if it cannot be started.
 *
 * POST answers here; get back a job id. The build itself runs in worker.php,
 * detached, because a world is two to three minutes of model calls and no proxy
 * on the public internet will hold a streaming response that long (Cloudflare
 * cuts this host at ~120 seconds, however often it is flushed). progress.php
 * then streams the job's notes as SSE in short, resumable stretches.
 *
 * Everything that can be answered NOW is answered now and synchronously — a
 * missing key, a model that is not answering, a limit that has run out — so the
 * user never watches a progress screen that was doomed before it started. The
 * endpoint is checked with xeric_llm_up() first, exactly as forge-cli.php does.
 *
 * A BUSY FORGE IS NO LONGER AN ERROR. It used to be a 409: whoever asked second
 * was told to come back, which is a race the fastest tab wins. Now the request
 * takes a place in line (queue.php) and hands it to the worker, which waits its
 * turn and streams the position down the same SSE the build itself uses. The
 * only synchronous refusals left are the honest ones: a line too long to join,
 * a drained queue, or a limit this visitor has genuinely spent.
 *
 * THE KEY IS NEVER WRITTEN. It arrives in this request's body, goes to the
 * worker down a pipe on its stdin, and is never put in argv, a file, the
 * session, or a log.
 */

declare(strict_types=1);

// play-lib.php, not boot.php, and this file was calling functions it did not
// have. `xeric_web_model()` and `xeric_web_connected()` live there, and have
// since the machine stopped being taken off the wire — so every build POST died
// on "Call to undefined function" before it ever reached the queue. The page
// showed "Building your world", the first pass never lit, and nothing in the
// jobs directory existed to look at, because the job was never created.
//
// It is a pure library with no top-level statements; a dozen other pages already
// include it. boot.php is what it includes first, so nothing is lost.
require_once __DIR__ . '/play-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    xeric_web_json(['error' => 'the forge is started by POSTing your answers here'], 405);
}

$in = xeric_web_input();
$interview = xeric_forge_interview(XERIC_WEB_LIB . '/forge/interview.json');
$answers = xeric_web_clean_answers((array)($in['answers'] ?? []), $interview);
$fill = ((string)($in['fill'] ?? 'presets') === 'model') ? 'model' : 'presets';

try {
    // THE MACHINE COMES FROM THE SESSION, THE KEY COMES FROM THE REQUEST.
    // It used to take the whole descriptor off the wire, which meant the page
    // could name any endpoint it liked and the machines screen was advisory.
    // Now the only thing the browser contributes is the one thing that is
    // deliberately never stored anywhere.
    $chosen = xeric_web_model();
    if (!xeric_web_connected($chosen)) {
        xeric_web_json(['error' => 'No machine is attached, so there is nothing to build with.',
                        'kind'  => 'detached'], 409);
    }
    $posted = (array)($in['model'] ?? []);

    // WHICH MACHINE, AS AN INDEX INTO THIS VISITOR'S OWN LIST. The forge page
    // lets somebody pick a machine other than the attached one for a single
    // build — a 26B and a 4B write different towns from identical answers, and
    // that is a per-build decision, not a setting.
    //
    // AN INDEX AND NOT AN ADDRESS, for the same reason the descriptor stopped
    // coming off the wire: a page that could post a URL makes the machines
    // screen advisory, and its private-network boundary along with it. The worst
    // a tampered request can do here is name a different machine its own owner
    // already stored.
    if (isset($posted['i'])) {
        $rows = xeric_model_list();
        $i = (int)$posted['i'];
        if (isset($rows[$i])) {
            $base = (string)$rows[$i]['base'];
            $kind = xeric_model_kind($base);
            $chosen = $kind === 'local'
                ? ['kind' => 'local', 'base' => '', 'local' => $base, 'model' => '']
                : ['kind' => $kind, 'base' => $base, 'local' => '', 'model' => ''];
        }
    }
    $endpoint = xeric_web_endpoint($chosen + ['key' => (string)($posted['key'] ?? '')]);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 400);
}
$isLocal = $endpoint['kind'] === 'local';
if (!$isLocal && trim((string)$endpoint['key']) === '') {
    xeric_web_json(['error' => 'that endpoint needs an API key, and none was given'], 400);
}

// -- may this visitor build one? ---------------------------------------------
// Checked here and charged below, when the worker is demonstrably alive: a
// visitor whose endpoint was unreachable has not spent one of their five worlds.
$sid = xeric_session_id();
xeric_limit_guard(xeric_limit_check('forge', ['sid' => $sid]));

// -- is anybody home? --------------------------------------------------------
if (!xeric_llm_up($endpoint, 8)) {
    xeric_web_json([
        'error' => $isLocal
            ? 'The local model at ' . $endpoint['base'] . ' is not answering. It may be loading, '
              . 'stopped, or busy with something else. You can bring your own key instead.'
            : 'Nothing answered at ' . $endpoint['base'] . '. Check the base URL and the key.',
        'kind'  => 'model_down',
        'offer_key' => $isLocal,
    ], 503);
}

// -- take a place in line ----------------------------------------------------
// The TICKET is taken here, in the request, so the line is in the order people
// actually asked; the WAITING is done by the worker, which streams its position
// as it moves up. A build that brings its own key still queues: the local model
// is one slot, but the forge itself writing worlds is also one thing at a time.
if (xeric_queue_drained()) {
    $r = xeric_queue_drained_no();
    xeric_web_json(['error' => (string)$r['message'], 'kind' => 'drained',
                    'retry_after' => (int)$r['retry_after']], 503);
}
$why = null;
$ticket = xeric_queue_join('forge', $sid, $why);
if ($ticket === '') {
    // The sentence is BUILT here, never fetched by asking the queue for a second
    // ticket. That is what this used to do, and on a line that had just emptied
    // that second request could WIN the model and then walk away still holding
    // it — while answering 503 with an undefined key and an empty message.
    $r = is_array($why) ? $why : xeric_queue_no('full', 'forge');
    $retry = (int)($r['retry_after'] ?? 60);
    if (!headers_sent()) header('Retry-After: ' . $retry);
    xeric_web_json(['error' => (string)$r['message'], 'kind' => (string)$r['kind'], 'retry_after' => $retry],
                   ($r['kind'] ?? '') === 'yours' ? 429 : 503);
}

// -- start the worker --------------------------------------------------------
$job = xeric_web_job_new();
xeric_web_job_sweep();

try {
    xeric_web_spawn($job, [
        'answers' => $answers,
        'fill'    => $fill,
        // EXPERIMENTAL discussion mode. Carried as a flag rather than a second
        // endpoint, because everything downstream of the build — the queue, the
        // meter, the job feed, the world that lands on disk — is identical. What
        // changes is which builder the worker calls.
        'panel'   => !empty($in['panel']),
        'model'   => $endpoint,          // includes the key — pipe only, never disk
        'sid'     => $sid,
        'ticket'  => $ticket,
    ]);
} catch (Throwable $e) {
    xeric_queue_leave($ticket);
    xeric_web_json(['error' => 'the forge could not be started: ' . $e->getMessage()], 500);
}

// The world is genuinely being built now, so this is when it is charged for.
xeric_limit_note('forge', ['sid' => $sid]);

// Wait briefly for the worker to say it is alive. This is what turns a detached
// build back into an honest synchronous answer: if it lost the lock race, or
// died on the way up, the user is told here instead of staring at a screen.
// The worker says hello BEFORE it waits for the model slot, so a full line shows
// up as a position on the progress screen rather than as eight seconds of
// nothing followed by a guess.
$first = xeric_web_job_await($job, 8.0);
if ($first !== null && $first['k'] === 'error') {
    $kind = (string)($first['rec']['kind'] ?? 'forge');
    xeric_web_json(['error' => (string)$first['rec']['message'], 'kind' => $kind],
                   in_array($kind, ['queued', 'drained', 'full'], true) ? 503 : 500);
}
if ($first !== null && $first['k'] === 'hello') {
    xeric_web_session_edit(function (array &$s) use ($job): void { $s['job'] = $job; }, $sid);
    xeric_web_json(['ok' => true, 'job' => $job, 'endpoint' => (string)($first['rec']['endpoint'] ?? '')]);
}
// Slow to speak but demonstrably alive: hand over the job rather than call a
// running build dead. Only an empty job file after eight seconds means the
// worker never got off the ground.
if (is_file(xeric_web_job_path($job))) {
    xeric_web_session_edit(function (array &$s) use ($job): void { $s['job'] = $job; }, $sid);
    xeric_web_json(['ok' => true, 'job' => $job, 'endpoint' => xeric_web_endpoint_label($endpoint)]);
}
xeric_web_json(['error' => 'the forge did not start, the build process never answered'], 500);

// xeric_web_spawn() lives in boot.php: the play view's time control starts a
// detached worker exactly the same way, and the shell incantation that keeps the
// payload pipe alive is far too subtle to keep two copies of.
