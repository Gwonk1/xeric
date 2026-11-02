<?php
/**
 * scene-worker.php — the room, developed, detached.
 *
 * photo-worker.php's twin. Spawned by scene.php the moment somebody presses
 * "look", it drains this world's scene jobs through xeric_scene_reap and
 * leaves. The same detached shape, for the same reason — a still of a room is
 * seconds and a panorama is minutes, and no proxy holds a response that long.
 *
 * ONE THING PHOTOS NEVER NEEDED: PATIENCE. A generic scene machine may answer
 * 202 "later" and hand back a job id; the reaper records it and asks after it
 * on its NEXT pass, never blocking. So this does not stop when a pass makes
 * nothing. It keeps passing — five seconds apart — for as long as any row of
 * this world is pending or is waiting on the machine, and gives up after
 * fifteen minutes, saying so. Giving up loses nothing: the row keeps its
 * remote job, the engine's own thirty-minute window decides when a wait was a
 * failed try, and the next press starts a worker that asks after it again.
 *
 * And it SPEAKS while it waits. progress.php calls a job that has said nothing
 * for four minutes dead; a machine that takes six to build a room is not dead,
 * so a line goes in the log once a minute while it is being waited on.
 *
 * The engine's gates travel with it (xeric_scene_reap): the brief is
 * re-composed at render time from canon, the minor floor and the rating clause
 * are inside the prompt, and every render lands on the meter through the sink
 * boot.php registered. Nothing spends quietly.
 *
 * Usage (never a URL): php scene-worker.php <job-id>  with payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("scene-worker.php is not a page\n"); }

require_once __DIR__ . '/review-lib.php';   // → play-lib → ui → boot; and the plain() errors ride

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "scene: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'the worker was given no room to develop']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

/** Rows still owed: pending, or working on a machine that said "later". */
function xeric_scene_worker_owed(PDO $db): int
{
    return (int)$db->query("SELECT COUNT(*) FROM scene_jobs WHERE status = 'pending'
                            OR (status = 'working' AND remote_job IS NOT NULL)")->fetchColumn();
}

try {
    $slug = xeric_web_slug((string)($payload['slug'] ?? ''));
    $w    = xeric_play_open($slug);
    // The fork's own folder for a fork, the world's for its owner — the same
    // place scene.php's bytes GET looks, so what is written is what is served.
    $dir  = dirname((string)$w['db_path']) . '/scenes';

    xeric_web_job_append($job, ['k' => 'hello', 'message' => 'developing the room']);

    $start = time();
    $said  = $start;
    $done  = 0;
    $seen  = [];
    while (true) {
        $r = xeric_scene_reap($w['template'], $w['db'], $dir, null, 1);
        $done += $r['done'];
        // The same note every five seconds is not news; each is said once.
        foreach ($r['notes'] as $n) {
            if (isset($seen[$n])) continue;
            $seen[$n] = true;
            xeric_web_job_append($job, ['k' => 'note', 'message' => $n]);
            $said = time();
        }
        if ($r['done'] > 0) {
            xeric_web_job_append($job, ['k' => 'note', 'message' => 'the room is in']);
            $said = time();
        }
        // A pass that could not even ask is the end of this worker: no machine,
        // scenes turned off, nowhere to write. Waiting would wait on nothing.
        $stuck = false;
        foreach ($r['notes'] as $n) {
            if (in_array($n, ['no scene machine is answering', 'scenes are turned off for this world',
                              'the scenes directory cannot be created'], true)) $stuck = true;
        }
        if ($stuck) break;

        $owed = xeric_scene_worker_owed($w['db']);
        if ($owed === 0) break;
        if ($r['done'] > 0 || $r['failed'] > 0) continue;      // progress: straight on to the next

        if (time() - $start >= 900) {
            xeric_web_job_append($job, ['k' => 'note', 'message' => 'gave up waiting after fifteen minutes; '
                . 'the machine may still finish it, and the next look will ask after it']);
            break;
        }
        if (time() - $said >= 60) {
            xeric_web_job_append($job, ['k' => 'note', 'message' => 'still being made, '
                . (int)floor((time() - $start) / 60) . ' min so far']);
            $said = time();
        }
        sleep(5);
    }

    xeric_web_job_append($job, ['k' => 'done', 'message' => $done === 1 ? 'the room is developed'
        : ($done . ' developed')]);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => xeric_review_plain($e->getMessage())]);
    exit(1);
}
