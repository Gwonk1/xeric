<?php
/**
 * story-worker.php — the mystery, written off the end of the request.
 *
 * One model call, but a big one — a story is two thousand tokens of thinking
 * about a town — so it runs detached and the browser watches a file, the same
 * shape the build, the skip and the add-character use.
 *
 * WHAT LANDS is one file beside the world: story-<key>.json, which the next
 * open discovers by itself (xeric_story_files) and composes onto the template.
 * Nothing about the world is edited — that is the overlay's whole promise, and
 * it is why injecting a murder into a town somebody has been living in for a
 * week is safe: close the story later and the composed template is byte-for-
 * byte the one that was always there.
 *
 * Usage (never a URL): php story-worker.php <job-id>  with payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("story-worker.php is not a page\n"); }

require_once __DIR__ . '/review-lib.php';
require_once dirname(__DIR__) . '/story-forge.php';

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "story: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'the story was given nothing to work from']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

$ticket = (string)($payload['ticket'] ?? '');
$lock   = null;

/**
 * STOP, AND HAND THE MODEL BACK FIRST. PHP does not run a `finally` on
 * `exit()`, so the exit in the catch below stranded the queue on a process that
 * was already gone — and the next person in line waited out the whole hold for
 * a GPU that was free. The `finally` stays as the backstop; this cannot
 * double-release.
 */
$done = function (int $code) use (&$lock, &$ticket): void {
    if ($lock !== null)     { xeric_queue_release($lock); $lock = null; }
    elseif ($ticket !== '') { xeric_queue_leave($ticket); $ticket = ''; }
    exit($code);
};

try {
    $slug = xeric_web_slug((string)($payload['slug'] ?? ''));
    $w    = xeric_play_open($slug);
    $ask  = trim((string)($payload['ask'] ?? ''));

    xeric_web_job_append($job, ['k' => 'hello', 'message' => 'reading the town for something to hide']);

    if ($ticket === '') $ticket = xeric_queue_join('reroll', $sid);
    $got = xeric_queue_wait($ticket, XERIC_QUEUE_WAIT_MAX,
        function (int $ahead, int $eta, string $phrase) use ($job): void {
            xeric_web_job_append($job, ['k' => 'queue', 'ahead' => $ahead, 'eta' => $eta,
                                        'text' => ucfirst($phrase)]);
        });
    if (!$got['ok']) {
        xeric_queue_leave($ticket);
        xeric_web_job_append($job, ['k' => 'error', 'kind' => (string)($got['kind'] ?? 'queued'),
                                    'message' => (string)$got['message']]);
        exit(0);
    }
    $lock   = $got['hold'];
    $ticket = '';

    $endpoint = (array)($payload['endpoint'] ?? []);
    $story = xeric_forge_story($w['template'], $ask, $endpoint, function (string $n) use ($job): void {
        xeric_web_job_append($job, ['k' => 'note', 'message' => $n]);
    });

    $path = xeric_forge_story_save($story, (string)$w['dir']);
    xeric_web_job_append($job, ['k' => 'done', 'message' => 'written: ' . basename($path),
                                'story' => ['key' => (string)$story['key'],
                                            'title' => (string)$story['title'],
                                            'logline' => (string)$story['logline'],
                                            'beats' => count($story['beats'])]]);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => xeric_review_plain($e->getMessage())]);
    $done(1);
} finally {
    if ($lock !== null) xeric_queue_release($lock);
    elseif ($ticket !== '') xeric_queue_leave($ticket);
}
