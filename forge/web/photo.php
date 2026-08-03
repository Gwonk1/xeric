<?php
/**
 * photo.php — a world's photographs, served and consented to.
 *
 *     GET  photo.php?w=<slug>&k=portrait|place&s=<subject>  → image bytes, or 404
 *     POST photo.php {w, a: approve|decline|go}             → the offer's answer
 *
 * THE SERVING HALF is tile.php's hole punched a second time, with the same
 * refusal discipline: photos live beside the database, outside the docroot,
 * because a world folder is nobody's to browse. The path is built from a
 * sanitised slug and the FILE COLUMN OF THE JOB ROW — never from a user
 * string — and the bytes are sniffed before they are served, because a
 * docroot that echoes unverified bytes under an image type is a stored-XSS
 * delivery service. Unlike the tile, photos are per-COPY: a fork's photos
 * live beside the fork's db, so the open below resolves whichever copy is
 * this session's, and two visitors can never see each other's film.
 *
 * THE CONSENT HALF is the first-hookup offer's back end. `approve` stamps
 * photos.approved and spawns the reaper; `decline` stamps photos.asked and
 * nothing else — asking is not consent, and a no costs nothing and is never
 * asked again; `go` re-spawns the reaper for a world that already said yes
 * (the "develop the film" button, for jobs that arrived later).
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$slug = xeric_web_slug((string)($_GET['w'] ?? $_POST['w'] ?? ''));
if ($slug === '') { http_response_code(404); exit; }

// -- the consent half --------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $in  = xeric_web_input();
    $a   = (string)($in['a'] ?? '');
    $sid = xeric_session_id();
    try {
        $w = xeric_play_open(xeric_web_slug((string)($in['w'] ?? $slug)));
    } catch (Throwable $e) {
        xeric_web_json(['error' => $e->getMessage()], 404);
    }

    if ($a === 'approve' || $a === 'go') {
        if ($a === 'approve') {
            xeric_world_state_set($w['db'], 'photos.asked', '1');
            xeric_world_state_set($w['db'], 'photos.approved', '1');
        }
        if ((string)(xeric_world_state_get($w['db'], 'photos.approved') ?? '') !== '1') {
            xeric_web_json(['error' => 'this world has not said yes to photographs'], 409);
        }
        $job = xeric_web_job_new();
        xeric_web_spawn($job, ['slug' => (string)$w['slug'], 'sid' => $sid], 'photo-worker.php');
        xeric_web_json(['ok' => 1, 'job' => $job,
                        'pending' => count(xeric_photo_jobs($w['db'], 'pending'))]);
    }
    if ($a === 'decline') {
        // A no is a no: asked is stamped, approved is not, and the offer never
        // interrupts this world again. The rows stay pending — a later "go"
        // from the machines screen is a change of mind, not a nag.
        xeric_world_state_set($w['db'], 'photos.asked', '1');
        xeric_web_json(['ok' => 1]);
    }
    xeric_web_json(['error' => 'a is approve, decline, or go'], 400);
}

// -- the serving half --------------------------------------------------------
$kind = (string)($_GET['k'] ?? '');
$subj = (string)($_GET['s'] ?? '');
if (!in_array($kind, ['portrait', 'place'], true) || $subj === '' || strlen($subj) > 64) {
    http_response_code(404); exit;
}

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    http_response_code(404); exit;
}

$row = xeric_photo_of($w['db'], $kind, $subj);
if ($row === null || (string)$row['status'] !== 'done' || (string)$row['file'] === '') {
    http_response_code(404); exit;
}

// The file column is the reaper's own write, never a user string — and it is
// still basename()d, because a database row is an input like any other.
$path = dirname((string)$w['db_path']) . '/photos/' . basename((string)$row['file']);
if (!is_file($path)) { http_response_code(404); exit; }

$bytes = (string)file_get_contents($path);
$mime  = (string)(@getimagesizefromstring($bytes)['mime'] ?? '');
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    // Not an image is not served as one, whatever wrote it.
    http_response_code(404); exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
echo $bytes;
