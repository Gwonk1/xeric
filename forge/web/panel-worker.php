<?php
/**
 * panel-worker.php — putting something to the room, off the end of the request.
 *
 * WHY DETACHED. A proposal is one short model call PER EXPERT, and the room
 * holds up to five of them: worst case that is five timeouts back to back,
 * which is past the edge's cut long before it is past the model's. Building a
 * deliverable is one call but a long one. Neither belongs in a request, and
 * both belong in the same worker because both are "the room does a piece of
 * work and the page watches" — same job feed, same queue slot, same shape as
 * the tick and the story.
 *
 * The verdict is NOT computed here. It is arithmetic over what is stored
 * (xeric_panel_verdict), so the debrief recomputes it from the rows every time
 * it is read, and a worker that died halfway leaves a room with fewer answers
 * rather than a room with a wrong one.
 *
 * Usage (never a URL): php panel-worker.php <job-id>  with the payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("panel-worker.php is not a page\n"); }

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/panel.php';

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "panel: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'the room was given nothing to consider']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

$ticket = (string)($payload['ticket'] ?? '');
$lock   = null;

try {
    $slug = xeric_web_slug((string)($payload['slug'] ?? ''));
    $w    = xeric_play_open($slug);
    $T    = $w['template'];
    $db   = $w['db'];
    $what = trim((string)($payload['text'] ?? ''));
    $mode = (string)($payload['mode'] ?? 'propose');

    if (xeric_panel($T) === null) throw new RuntimeException('this xeric is not a discussion room');
    if ($what === '') throw new RuntimeException('there is nothing here to put to them');

    xeric_web_job_append($job, ['k' => 'hello',
        'message' => $mode === 'build' ? 'the room is writing it' : 'putting it to the room']);

    if ($ticket === '') $ticket = xeric_queue_join('panel', $sid);
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

    $endpoint = xeric_play_endpoint();
    $note = function (string $n) use ($job): void {
        xeric_web_job_append($job, ['k' => 'note', 'message' => $n]);
    };

    if ($mode === 'build') {
        $i = xeric_panel_build($T, $db, $what, $endpoint, ['timeout' => 240]);
        if ($i < 0) throw new RuntimeException('nothing usable came back — try asking for it more plainly');
        $made = xeric_panel_artifacts($db)[$i];
        xeric_web_job_append($job, ['k' => 'done', 'message' => 'written: ' . (string)$made['title'],
                                    'artifact' => ['title' => (string)$made['title'],
                                                   'kind' => (string)$made['kind']]]);
        exit(0);
    }

    // ONE SHORT CALL PER PERSON, each about their own sentence and nothing
    // else. The notes stream as they land, so the page shows the room refusing
    // in real time rather than a spinner and a verdict.
    $ix = xeric_panel_propose($db, $what);
    if ($ix < 0) throw new RuntimeException('there is nothing here to put to them');
    $row = xeric_panel_table($T, $db, $ix, $endpoint, ['timeout' => 60], $note);

    $v = xeric_panel_verdict($T, $db);
    xeric_web_job_append($job, ['k' => 'done',
        'message' => $v['state'] === 'consensus'
            ? 'the room got there'
            : count($row['clears']) . ' of ' . count(xeric_panel_experts($T)) . ' could live with it',
        'verdict' => ['state' => $v['state'], 'cleared' => (int)$v['cleared'], 'of' => (int)$v['of'],
                      'tensions' => count($v['tensions'])]]);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => $e->getMessage()]);
    exit(1);
} finally {
    if ($lock !== null) xeric_queue_release($lock);
    elseif ($ticket !== '') xeric_queue_leave($ticket);
}
