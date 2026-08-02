<?php
/**
 * tile.php — a world's cover art.
 *
 *     GET tile.php?w=<slug>   → the image bytes, or 404
 *
 * WHY THIS EXISTS AT ALL. Worlds live in the data directory, which is deliberately
 * OUTSIDE the docroot — a world folder holds a real person's name, occupation and
 * location, and `owner.json` holds a session token, so not one byte of it is
 * reachable by URL. That protection is the reason a cover image cannot simply be
 * an `<img src>` pointing at the folder, and this file is the one hole punched
 * through it: one named file per world, image bytes only, nothing else.
 *
 * Which means the interesting code here is all refusal:
 *
 *   - The slug is sanitised and then used to build ONE path, which is checked
 *     against a fixed list of three filenames. No user string ever reaches the
 *     filesystem except as a directory name that xeric_web_slug() has already
 *     reduced to [a-z0-9-].
 *   - The bytes are sniffed, not trusted. A file called tile.jpg that is not an
 *     image is refused rather than served with an image content-type, because a
 *     docroot that will echo arbitrary bytes under a type it did not verify is a
 *     stored-XSS delivery service.
 *   - Content-Disposition: inline plus X-Content-Type-Options stops a browser
 *     from being talked into sniffing something else out of it.
 *
 * Nothing here writes, and nothing here is per-visitor: cover art is a property
 * of the WORLD, shared by every copy of it, so this needs no session and answers
 * the same bytes to everybody who can see the world on the shelf.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$slug = xeric_web_slug((string)($_GET['w'] ?? ''));
if ($slug === '') { http_response_code(404); exit; }

$path = xeric_play_tile_file($slug);
if ($path === null) { http_response_code(404); exit; }

// Sniffed, never inferred from the name. getimagesize() reads the header and
// returns the type it actually found; anything it cannot read is not an image.
$info = @getimagesize($path);
$type = is_array($info) ? (string)($info['mime'] ?? '') : '';
if (!in_array($type, ['image/webp', 'image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit;
}

$mtime = (int)@filemtime($path);
$etag  = '"' . substr(sha1($slug . '|' . $mtime . '|' . (int)@filesize($path)), 0, 20) . '"';

header('Content-Type: ' . $type);
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
// A world's art changes when somebody regenerates it, which is rare and
// deliberate — so cache it hard and let the ETag catch the rare change.
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . (int)@filesize($path));
readfile($path);
