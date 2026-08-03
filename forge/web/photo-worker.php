<?php
/**
 * photo-worker.php — the reaper, detached.
 *
 * Spawned by photo.php the moment an owner says yes to the first-hookup offer
 * (or presses "develop the film" later), and it does the one thing the reaper
 * exists to do: drain this world's photo jobs while the image machine keeps
 * answering. The same detached shape addchar-worker and the skip use, for the
 * same reason — a render is seconds-to-minutes and no proxy holds a response
 * that long — and it dies quietly the moment the machine stops answering,
 * leaving the remaining rows pending for the next yes. A crashed reaper wedges
 * nothing: every job it did not finish is exactly as pending as it was.
 *
 * The engine's gates travel with it (xeric_photo_reap): consent is read from
 * world_state, prompts are composed at render time, the minor floor and the
 * rating caps are already inside xeric_photo_prompt, and every render lands on
 * the meter through the sink boot.php registered. Nothing spends quietly.
 *
 * Usage (never a URL): php photo-worker.php <job-id>  with payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("photo-worker.php is not a page\n"); }

require_once __DIR__ . '/review-lib.php';   // → play-lib → ui → boot; and the plain() errors ride

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "photo: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'the reaper was given nothing to develop']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

try {
    $slug = xeric_web_slug((string)($payload['slug'] ?? ''));
    $w    = xeric_play_open($slug);
    $dir  = dirname((string)$w['db_path']) . '/photos';

    $pending = count(xeric_photo_jobs($w['db'], 'pending'));
    xeric_web_job_append($job, ['k' => 'hello', 'message' => "developing $pending photograph"
        . ($pending === 1 ? '' : 's')]);

    // One at a time, re-checking the machine between frames: a provider that
    // dies mid-roll costs one try on one job, not a burst of failures across
    // the queue — and each pass reports, so progress.php has something to say.
    $done = 0;
    while (true) {
        $r = xeric_photo_reap($w['template'], $w['db'], $dir, null, 1);
        if ($r['done'] === 0 && $r['failed'] === 0) {
            foreach ($r['notes'] as $n) xeric_web_job_append($job, ['k' => 'note', 'message' => $n]);
            break;
        }
        $done += $r['done'];
        foreach ($r['notes'] as $n) xeric_web_job_append($job, ['k' => 'note', 'message' => $n]);
        if ($r['done'] > 0) {
            $left = count(xeric_photo_jobs($w['db'], 'pending'));
            xeric_web_job_append($job, ['k' => 'note', 'message' => "$left to go"]);
        }
        if (xeric_photo_jobs($w['db'], 'pending') === []) break;
    }

    // THE TILE, once: if the deterministic pick (the workplace, else the first
    // place) has developed, the world's cover art stops being pixel-drawn.
    // Owner's canonical world only — a fork's photos are the fork's, and cover
    // art is a property of the WORLD (tile.php's own words).
    if (!empty($w['mine'])) {
        $pick = xeric_photo_tile_place($w['template']);
        $row  = $pick !== null ? xeric_photo_of($w['db'], 'place', $pick) : null;
        if ($row !== null && (string)$row['status'] === 'done'
                && is_file($dir . '/' . (string)$row['file'])) {
            $tile = xeric_web_worlds_dir() . '/' . $slug . '/tile.png';
            if (!is_file($tile)) {
                @copy($dir . '/' . (string)$row['file'], $tile);
                xeric_web_job_append($job, ['k' => 'note',
                    'message' => 'the cover art is a photograph now']);
            }
        }
    }

    xeric_web_job_append($job, ['k' => 'done', 'message' => $done . ' developed']);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => xeric_review_plain($e->getMessage())]);
    exit(1);
}
