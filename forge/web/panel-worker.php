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
require_once XERIC_WEB_LIB . '/engine/room.php';   // a panel is three to five, which is a room

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

/**
 * STOP, AND HAND THE MODEL BACK FIRST.
 *
 * PHP does not run a `finally` on `exit()`, and every way out of the block
 * below was an exit — both the ones that succeed and the one in the catch. So
 * the finally that returns the model slot ran on none of them, and a panel held
 * the GPU after it had finished with it until the queue timed the hold out.
 * That is the worst possible thing to leak: the slot is the one resource the
 * whole app queues on, and the pressure is highest exactly when a worker is
 * bailing out early.
 *
 * Everything here is idempotent — the finally is still the backstop for a path
 * that forgets, and this cannot double-release.
 */
$done = function (int $code) use (&$lock, &$ticket): void {
    if ($lock !== null)      { xeric_queue_release($lock); $lock = null; }
    elseif ($ticket !== '')  { xeric_queue_leave($ticket); $ticket = ''; }
    exit($code);
};

try {
    $slug = xeric_web_slug((string)($payload['slug'] ?? ''));
    $w    = xeric_play_open($slug);
    $T    = $w['template'];
    $db   = $w['db'];
    $what = trim((string)($payload['text'] ?? ''));
    $mode = (string)($payload['mode'] ?? 'propose');

    if (xeric_panel($T) === null) throw new RuntimeException('this xeric is not a discussion room');
    // A round needs nothing said to it — the room already has a question.
    if ($what === '' && $mode !== 'round') throw new RuntimeException('there is nothing here to put to them');

    xeric_web_job_append($job, ['k' => 'hello',
        'message' => $mode === 'build' ? 'the room is writing it'
            : ($mode === 'round' ? 'letting them argue' : 'putting it to the room')]);

    if ($ticket === '') $ticket = xeric_queue_join('panel', $sid);
    $got = xeric_queue_wait($ticket, XERIC_QUEUE_WAIT_MAX,
        function (int $ahead, int $eta, string $phrase) use ($job): void {
            xeric_web_job_append($job, ['k' => 'queue', 'ahead' => $ahead, 'eta' => $eta,
                                        'text' => ucfirst($phrase)]);
        });
    if (!$got['ok']) {
        // (the ticket is given up by $done; $lock is not taken yet)
        xeric_web_job_append($job, ['k' => 'error', 'kind' => (string)($got['kind'] ?? 'queued'),
                                    'message' => (string)$got['message']]);
        $done(0);
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
        $done(0);
    }

    // LET THEM ARGUE. The room seats every expert and runs beats, which is what
    // xeric_room() was built for and what nothing in the web layer had ever
    // driven — watch.php is the DUET, two people in strict turns. A panel is
    // three to five, which is exactly the room's own range.
    //
    // The close records every line with the speaker's own memory as its
    // reasoning (engine/room.php), so an argument that runs here is an argument
    // the debrief can report on and the next round can read.
    if ($mode === 'round') {
        $handles = array_keys(xeric_panel_experts($T));
        $now = xeric_clock_now($db, $T);
        $r = xeric_room($T, $db, $handles, $now, $endpoint, [
            'beats'   => max(3, min(12, (int)($payload['beats'] ?? 6))),
            'timeout' => 90,
            'on_line' => function (string $h, string $name, string $text, string $kind) use ($job): void {
                if ($kind !== 'line') return;
                xeric_web_job_append($job, ['k' => 'note', 'message' => $name . ': ' . $text]);
            },
        ]);
        xeric_web_job_append($job, ['k' => 'done',
            'message' => 'they talked for ' . count((array)$r['lines']) . ' turns',
            'round' => ['lines' => count((array)$r['lines'])]]);
        $done(0);
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
    $done(1);
} finally {
    if ($lock !== null) xeric_queue_release($lock);
    elseif ($ticket !== '') xeric_queue_leave($ticket);
}
