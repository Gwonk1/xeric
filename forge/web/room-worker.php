<?php
/**
 * room-worker.php — the room's turn, carried off the end of the request.
 *
 * room.php writes the player's line and hands back at once; the people at the
 * table answer here, one beat at a time, each its own model call from its own
 * speaker's head (engine/room.php's first law). Detached for the reason every
 * worker in this directory is: three answers on a local model is a minute,
 * and no proxy holds a request open that long — and a turn that died with the
 * tab would be a room that stopped mid-sentence because somebody scrolled.
 *
 * What it holds and for how long:
 *
 *  • THE LEASE, for the whole turn: one row of world_state says which worker
 *    is carrying this room, renewed every beat, compare-and-set under BEGIN
 *    IMMEDIATE. A second POST while this runs books its line and leaves the
 *    answering to the worker already here.
 *
 *  • THE MODEL SLOT, for ONE CALL AT A TIME and never across the pause
 *    between lines. A room that held the GPU through its own reading-time
 *    gaps would make every other visitor wait on a conversation they cannot
 *    hear. xeric_play_room_run takes and releases it per beat.
 *
 *  • THE CLOSE, last: when the turn ended the session (you walked out, a
 *    child came in, twenty quiet minutes), the diaries are model calls too, so
 *    they wait their turn in the same line — and if the line is long, the
 *    session stays sealed for the heart or the next worker, losing nothing.
 *
 * Usage (never a URL): php room-worker.php <job-id>  with the payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("room-worker.php is not a page\n"); }

require_once __DIR__ . '/play-lib.php';

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "room: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'the room was given nothing to say']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

try {
    $w = xeric_play_open((string)($payload['slug'] ?? ''), (string)($payload['db'] ?? '') ?: null,
                         array_key_exists('adult', $payload) ? (bool)$payload['adult'] : false);

    $lease = (string)($payload['lease'] ?? '');
    if ($lease !== '') {
        $r = xeric_play_room_run($w, $sid, $lease);
        xeric_web_job_append($job, ['k' => 'note', 'message' => $r['beats'] . ' answered'
            . ($r['dropped'] > 0 ? ', ' . $r['dropped'] . ' set aside because you spoke first' : '')
            . ($r['stopped'] !== '' ? ' (' . $r['stopped'] . ')' : '')]);
    }

    // THE CLOSE, under the same courtesy as everything else: in line, for the
    // diary calls, and only if the line lets us in soon.
    if (xeric_room_seals($w['db']) !== []) {
        $endpoint = null;
        try { $endpoint = xeric_play_endpoint($sid); } catch (Throwable $e) { $endpoint = null; }
        $got = xeric_queue_take('say', XERIC_QUEUE_SAY_WAIT, $sid);
        if ($got['ok'] ?? false) {
            $lock = $got['hold'];
            try {
                // Only with the model up: a probe that fails is a seal kept for
                // the next pass, never diaries forfeited (xeric_play_room_land_up).
                $landed = xeric_play_room_land_up($w, $endpoint);
                xeric_web_job_append($job, ['k' => 'note', 'message' => $landed === null
                    ? 'the model is not answering; the close waits, nothing is lost'
                    : count($landed) . ' conversation(s) closed']);
            } finally {
                xeric_queue_release($lock);
            }
        } else {
            xeric_web_job_append($job, ['k' => 'note', 'message' => 'the close waits for the model; nothing is lost']);
        }
    }
    xeric_web_job_append($job, ['k' => 'done', 'message' => 'the room is quiet']);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => xeric_web_note_safe($e->getMessage())]);
    exit(1);
}
