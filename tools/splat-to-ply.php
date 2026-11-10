<?php
/**
 * splat-to-ply.php — a gaussian splat, in the one form this app keeps.
 *
 *     php tools/splat-to-ply.php room.splat [room.ply]     (stdout when no out)
 *
 * WHY PLY. docs/FUTURE.md (M2, "Walking inside a splat") decides the stored,
 * canonical form of a splat is binary PLY — the standard 3DGS layout (x y z,
 * f_dc_0..2, opacity, scale_0..2, rot_0..3) every tool reads, so a room kept
 * today is a room any viewer opens tomorrow. The browser viewer
 * (forge/web/xeric-splat.js) reads both PLY and the compact .splat directly;
 * this is for what goes on disk, not for what can be shown.
 *
 * WHAT IT DOES:
 *   .splat (32 bytes a splat)  converted: colour → f_dc by the zeroth spherical
 *                              harmonic, alpha → a logit, scale → its log,
 *                              the byte quaternion → floats (w x y z), unit.
 *   binary PLY                 validated (little-endian, a vertex element with
 *                              the 3DGS properties, as long as it says) and
 *                              passed through byte for byte.
 *   .spz / .sog                null, and xeric_splat_to_ply_why() says so: SPZ is
 *                              gzip around a packed layout, SOG a zip of WebP
 *                              tiles, and decoding either is a decoder this
 *                              repo does not carry yet (a follow-up — the
 *                              viewer refuses the same bytes the same way).
 *
 * THE FUNCTIONS LIVE IN engine/splat.php (tools/ is not deployed, and the
 * engine converts every splat on arrival in xeric_scene_finish); this file is
 * the command line over them, for a splat already on disk.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/engine/splat.php';

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $in = (string)($argv[1] ?? '');
    if ($in === '' || !is_file($in)) { fwrite(STDERR, "usage: php tools/splat-to-ply.php <in.splat|in.ply> [out.ply]\n"); exit(2); }
    $bytes = (string)file_get_contents($in);
    $ply = xeric_splat_to_ply($bytes, 'application/octet-stream', (string)pathinfo($in, PATHINFO_EXTENSION));
    if ($ply === null) { fwrite(STDERR, 'splat-to-ply: ' . xeric_splat_to_ply_why() . "\n"); exit(1); }
    if (isset($argv[2])) { file_put_contents($argv[2], $ply); fwrite(STDERR, 'splat-to-ply: ' . strlen($ply) . " bytes\n"); }
    else echo $ply;
    exit(0);
}
