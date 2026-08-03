<?php
/**
 * reroll-worker.php — one pass, run again, off the end of the request that asked.
 *
 * Same shape as worker.php and tick-worker.php, for the same reason: a reroll of
 * the seed history is five model calls and two minutes, and every proxy in front
 * of this app cuts a held response at about a hundred seconds. So the work is
 * detached, it appends one JSON line per note, and progress.php tails it.
 *
 * The one thing this worker does that the others do not: its `done` frame
 * carries the section's HTML, rendered from the file it has just SAVED, by the
 * same function that drew the page in the first place. A reroll therefore cannot
 * show you something other than what is on disk — which is the failure mode that
 * would make the whole review step untrustworthy.
 *
 * Usage (never a URL): php reroll-worker.php <job-id>  with the payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("reroll-worker.php is not a page\n"); }

require_once __DIR__ . '/review-lib.php';

/** One pass, one ceiling — the same 180s a proactive ping gets in tick-worker. */
const XERIC_WEB_REROLL_CALL_TIMEOUT = 180;

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "reroll: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'the reroll was given nothing to work on']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$t0 = microtime(true);
$el = fn() => round(microtime(true) - $t0, 1);

$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

$lock = null;

/**
 * STOP, AND HAND THE MODEL BACK FIRST. PHP does not run a `finally` on
 * `exit()`, so an early exit taken after the hold was granted left the queue
 * pointing at a dead worker until the hold timed out. The paths that do this
 * are the refusals — the drained machine, the missing input — which is exactly
 * when the slot is under most pressure. The `finally` stays as the backstop;
 * this cannot double-release.
 */
$done = function (int $code) use (&$lock): void {
    if (is_array($lock)) { xeric_queue_release($lock); $lock = null; }
    exit($code);
};

try {
    $slug = xeric_web_slug((string)($payload['slug'] ?? ''));
    $what = (string)($payload['what'] ?? '');
    $index = (int)($payload['index'] ?? -1);

    $w = xeric_review_open($slug, $sid);
    if (!$w['mine']) throw new RuntimeException('that xeric was forged in a different browser');

    xeric_web_job_append($job, ['k' => 'hello', 't' => 0.0, 'what' => $what,
        'text' => 'rerolling ' . (xeric_review_sections()[$what] ?? $what)]);

    // The endpoint arrives in the payload (stdin) so a bring-your-own key never
    // touches argv, the job file, or a log. Falls back to the local model, which
    // is what a reroll used before keys were offered.
    $endpoint = (array)($payload['endpoint'] ?? []);
    if (($endpoint['base'] ?? '') === '') $endpoint = xeric_play_endpoint();

    // -- the one model slot ---------------------------------------------------
    $ticket = (string)($payload['ticket'] ?? '');
    if ($ticket === '') $ticket = xeric_queue_join('reroll', $sid);

    $got = xeric_queue_wait($ticket, XERIC_QUEUE_WAIT_MAX,
        function (int $ahead, int $eta, string $phrase) use ($job, $el): void {
            xeric_web_job_append($job, ['k' => 'queue', 't' => $el(), 'ahead' => $ahead,
                'eta' => $eta, 'text' => ucfirst($phrase)]);
        });

    if (!$got['ok']) {
        xeric_queue_leave($ticket);
        xeric_web_job_append($job, ['k' => 'error', 't' => $el(),
            'kind' => (string)($got['kind'] ?? 'queued'), 'message' => (string)$got['message']]);
        exit(0);
    }
    $lock = $got['hold'];

    $say = function (string $note) use ($job, $el): void {
        xeric_web_job_append($job, ['k' => 'note', 't' => $el(), 'text' => $note,
            'level' => xeric_web_note_warn($note) ? 'warn' : 'info']);
    };

    // THE OWNER'S MACHINE WINS here too. A reroll is one pass, so unlike a
    // build there is nowhere mid-job to ask — this is the last moment before the
    // model is called, and after it the only thing left is the save.
    if (xeric_queue_expired($lock)) {
        xeric_web_job_append($job, ['k' => 'error', 't' => $el(), 'kind' => 'drained',
            'message' => ucfirst(xeric_queue_stop_reason($lock))
                . '. Your xeric is exactly as you left it, press it again in a few minutes.']);
        $done(0);
    }

    // A per-call ceiling on the endpoint, matching the skip's. llm.php otherwise
    // waits ten minutes and then retries, which is twenty against a queue that
    // hands the GPU on after seven.
    $endpoint['timeout'] = XERIC_WEB_REROLL_CALL_TIMEOUT;

    $out = xeric_review_reroll($w, [
        'what' => $what, 'index' => $index, 'endpoint' => $endpoint,
    ], $say);

    // Repaint from the SAVED file, not from what we think we just wrote.
    $fresh = xeric_review_open($slug, $sid);

    xeric_web_job_append($job, [
        'k' => 'done', 't' => $el(),
        'what'    => $what,
        'name'    => (string)$fresh['template']['meta']['name'],
        'seconds' => round(microtime(true) - $t0, 1),
        'notes'   => array_values((array)$out['notes']),
        // A draft-again changed every section at once; the page reloads whole
        // rather than being handed one section's worth of the new world.
        'html'    => $what === 'draft' ? ''
                   : xeric_review_section_html($what === 'character' ? 'cast' : $what, $fresh),
        // A reroll that came back identical, or that never reached a model, is
        // not saved (review-lib.php) — so it invalidated nothing, and repainting
        // a section it did not change would only take a box out from under
        // somebody's cursor. The notes already say what happened.
        'saved'   => (bool)($out['saved'] ?? true),
        // What this reroll invalidated elsewhere on the page. The browser asks
        // for each of these again rather than reloading under somebody's cursor.
        'stale'   => empty($out['saved']) ? [] : xeric_review_stale($what),
    ]);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 't' => $el(), 'kind' => 'reroll',
        'message' => $e->getMessage()]);
} finally {
    if (is_array($lock)) xeric_queue_release($lock);
}
exit(0);
