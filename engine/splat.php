<?php
/**
 * splat.php — a gaussian splat, in the one form this engine keeps.
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
 * CALLED FROM xeric_scene_finish (engine/scene.php) for every splat that
 * arrives: a PLY is kept as `application/ply` under `.ply`, and a null keeps
 * the bytes as they came, with the reason in the row's meta. The CLI is
 * tools/splat-to-ply.php.
 */

declare(strict_types=1);

const XERIC_SPLAT_SH_C0 = 0.28209479177387814;

/** Why the last xeric_splat_to_ply() call answered null ('' when it did not). */
function xeric_splat_to_ply_why(?string $set = null): string
{
    static $why = '';
    if ($set !== null) $why = $set;
    return $why;
}

/**
 * The bytes as binary PLY, or null with a reason in xeric_splat_to_ply_why().
 * $mime and $ext are the row's (ext with or without its dot); the bytes'
 * own first bytes decide, because the engine names every splat `.splat`
 * whatever arrived (xeric_scene_ext).
 */
function xeric_splat_to_ply(string $bytes, string $mime, string $ext): ?string
{
    xeric_splat_to_ply_why('');
    $ext = strtolower(ltrim($ext, '.'));
    if ($bytes === '') { xeric_splat_to_ply_why('no bytes'); return null; }
    if (str_starts_with($bytes, "ply\n") || str_starts_with($bytes, "ply\r\n")) {
        $why = xeric_splat_ply_check($bytes);
        if ($why !== '') { xeric_splat_to_ply_why($why); return null; }
        return $bytes;
    }
    if (str_starts_with($bytes, "\x1f\x8b") || $ext === 'spz') {
        xeric_splat_to_ply_why('an SPZ splat (gzip): not converted here yet — a follow-up');
        return null;
    }
    if (str_starts_with($bytes, 'PK') || $ext === 'sog') {
        xeric_splat_to_ply_why('a SOG splat (zipped WebP): not converted here yet — a follow-up');
        return null;
    }
    if (strlen($bytes) % 32 !== 0) {
        xeric_splat_to_ply_why('not a .splat: ' . strlen($bytes) . ' bytes is not a whole number of 32-byte records');
        return null;
    }

    $n = intdiv(strlen($bytes), 32);
    $out = "ply\nformat binary_little_endian 1.0\nelement vertex $n\n";
    foreach (['x', 'y', 'z', 'f_dc_0', 'f_dc_1', 'f_dc_2', 'opacity', 'scale_0', 'scale_1', 'scale_2',
              'rot_0', 'rot_1', 'rot_2', 'rot_3'] as $p) $out .= "property float $p\n";
    $out .= "end_header\n";
    $body = [];
    for ($i = 0; $i < $n; $i++) {
        $r = unpack('g6f/C4c/C4q', substr($bytes, $i * 32, 32));
        $q = [($r['q1'] - 128) / 128, ($r['q2'] - 128) / 128, ($r['q3'] - 128) / 128, ($r['q4'] - 128) / 128];
        $l = sqrt($q[0] ** 2 + $q[1] ** 2 + $q[2] ** 2 + $q[3] ** 2) ?: 1.0;
        $a = min(max($r['c4'] / 255, 0.5 / 255), 254.5 / 255);
        $body[] = pack('g14',
            $r['f1'], $r['f2'], $r['f3'],
            ($r['c1'] / 255 - 0.5) / XERIC_SPLAT_SH_C0, ($r['c2'] / 255 - 0.5) / XERIC_SPLAT_SH_C0, ($r['c3'] / 255 - 0.5) / XERIC_SPLAT_SH_C0,
            log($a / (1 - $a)),
            log(max($r['f4'], 1e-12)), log(max($r['f5'], 1e-12)), log(max($r['f6'], 1e-12)),
            $q[0] / $l, $q[1] / $l, $q[2] / $l, $q[3] / $l);
    }
    return $out . implode('', $body);
}

/** '' when $bytes is a binary PLY a 3DGS viewer can read, else why not. */
function xeric_splat_ply_check(string $bytes): string
{
    $end = strpos(substr($bytes, 0, 65536), "end_header\n");
    if ($end === false) return 'the PLY header never ends';
    $head = substr($bytes, 0, $end);
    $size = ['char' => 1, 'uchar' => 1, 'int8' => 1, 'uint8' => 1, 'short' => 2, 'ushort' => 2, 'int16' => 2,
             'uint16' => 2, 'int' => 4, 'uint' => 4, 'int32' => 4, 'uint32' => 4, 'float' => 4, 'float32' => 4,
             'double' => 8, 'float64' => 8];
    $fmt = ''; $n = -1; $in = false; $props = []; $stride = 0;
    foreach (preg_split('/\r?\n/', $head) as $ln) {
        $w = preg_split('/\s+/', trim($ln));
        if ($w[0] === 'format') $fmt = (string)($w[1] ?? '');
        elseif ($w[0] === 'element') { $in = ($w[1] ?? '') === 'vertex' && $n < 0; if ($in) $n = (int)($w[2] ?? -1); }
        elseif ($w[0] === 'property' && $in) {
            if (($w[1] ?? '') === 'list' || !isset($size[$w[1] ?? ''])) return 'a vertex property this viewer cannot read';
            $props[] = (string)($w[2] ?? '');
            $stride += $size[$w[1]];
        }
    }
    if ($fmt !== 'binary_little_endian') return 'only binary little-endian PLY is kept';
    if ($n < 0) return 'the PLY has no vertex element';
    $need = ['x', 'y', 'z', 'opacity', 'scale_0', 'scale_1', 'scale_2', 'rot_0', 'rot_1', 'rot_2', 'rot_3'];
    $miss = array_diff($need, $props);
    if ($miss !== []) return 'the PLY is not a gaussian splat (no ' . implode(', ', $miss) . ')';
    if (array_diff(['f_dc_0', 'f_dc_1', 'f_dc_2'], $props) !== [] && array_diff(['red', 'green', 'blue'], $props) !== []) {
        return 'the PLY has no colour';
    }
    if ($end + 11 + $n * $stride > strlen($bytes)) return 'the PLY is shorter than its header says';
    return '';
}
