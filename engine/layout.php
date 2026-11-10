<?php
/**
 * Xeric — the layout: the room drawn as a box.
 *
 * WHY THIS EXISTS. The world models that hold still best are conditioned on
 * geometry — a low-poly proxy, a blockout, a depth map — and not on a
 * paragraph. Xeric already knows where everything in a room is: the bearings
 * (scene.php) say which way each thing lies from where you stand. This file
 * turns them into a rough room a machine can be shown: a box, a vantage, and
 * every named thing and every person present standing in it as a box of its
 * own, at its bearing, at a distance that suits what it is.
 *
 * DERIVED, NEVER STORED, AND NOT IN THE FINGERPRINT. Everything here is a
 * function of the brief (the interior, the company, their bearings and
 * seeds, the vantage) and of the place's optional `layout` block in the
 * template. Same brief, same bytes — the JSON and both PNGs.
 *
 * NO SECRETS BY CONSTRUCTION. The only words in a layout are labels, and a
 * label is an interior item's own text or a present person's name — both
 * already in the brief, which carries only observables.
 *
 * Coordinates: metres; x to the right, y up, z straight ahead. The origin is
 * the point the bearings are measured from, on the floor — the player's feet
 * for first_person, the middle of the room for establishing — so for every
 * object atan2(x, z) is its bearing, clockwise from straight ahead.
 *
 * Pure: no I/O, no clock, no database.
 */

declare(strict_types=1);

const XERIC_LAYOUT_VERSION = 1;
const XERIC_LAYOUT_ROOM    = ['width' => 6.0, 'depth' => 4.0, 'height' => 3.0];
const XERIC_LAYOUT_EYE     = 1.6;    // the camera's height, metres
const XERIC_LAYOUT_INSIDE  = 1.0;    // first_person: how far in from the door you stand
const XERIC_LAYOUT_MARGIN  = 0.05;   // what keeps a box off the wall it is pushed against

/**
 * What a thing is, by the first keyword in its name that the table knows:
 * [size class, width, depth, height, base height]. A window is a pane on a
 * wall, a door a slab in one; anything unnamed is a medium box.
 */
const XERIC_LAYOUT_KINDS = [
    'counter' => ['large',  3.0, 0.7, 1.1, 0.0],
    'booth'   => ['large',  1.8, 1.2, 1.2, 0.0],
    'bar'     => ['large',  3.0, 0.7, 1.1, 0.0],
    'piano'   => ['large',  1.5, 0.7, 1.3, 0.0],
    'table'   => ['medium', 1.2, 0.8, 0.75, 0.0],
    'case'    => ['medium', 1.0, 0.6, 1.3, 0.0],
    'stool'   => ['small',  0.4, 0.4, 0.75, 0.0],
    'window'  => ['large',  1.6, 0.1, 1.2, 0.9],
    'door'    => ['large',  0.9, 0.1, 2.1, 0.0],
];
const XERIC_LAYOUT_DEFAULT = ['medium', 0.8, 0.8, 1.0, 0.0];
const XERIC_LAYOUT_PERSON  = ['small',  0.5, 0.5, 1.7, 0.0];
const XERIC_LAYOUT_ON_WALL = ['window', 'door'];

/** Hues, degrees: things 0–249, people 300–339, nothing else ever uses 300+. */
const XERIC_LAYOUT_HUE_THINGS = [0, 250];
const XERIC_LAYOUT_HUE_PEOPLE = [300, 340];

/** The room's size: the place's `layout` block, each number 2–50 m, else the default. */
function xeric_layout_room(array $t, string $key): array
{
    $room = XERIC_LAYOUT_ROOM;
    foreach ((array)($t['places'] ?? []) as $p) {
        if (!is_array($p) || (string)($p['key'] ?? '') !== $key || !is_array($p['layout'] ?? null)) continue;
        foreach ($room as $k => $v) {
            $n = $p['layout'][$k] ?? null;
            if ((is_int($n) || is_float($n)) && $n >= 2 && $n <= 50) $room[$k] = (float)$n;
        }
    }
    return $room;
}

/** Does this world ask for the depth and segmentation PNGs to ride in the request? */
function xeric_layout_images(array $t): bool
{
    return !empty($t['media']['scene']['layout_images']);
}

/** The table row for a thing's name. */
function xeric_layout_kind(string $label): array
{
    $l = mb_strtolower($label);
    $best = null; $at = PHP_INT_MAX;
    foreach (XERIC_LAYOUT_KINDS as $word => $row) {
        // A word, not a substring: "barber's chair" is not a bar.
        if (preg_match('/\b' . preg_quote($word, '/') . '(e?s)?\b/u', $l, $m, PREG_OFFSET_CAPTURE)
            && $m[0][1] < $at) {
            $best = $word; $at = $m[0][1];
        }
    }
    return $best === null ? ['', XERIC_LAYOUT_DEFAULT] : [$best, XERIC_LAYOUT_KINDS[$best]];
}

/** A seed's jitter, 0..1, deterministic. */
function xeric_layout_jitter(int $seed): float
{
    return (abs($seed) % 1001) / 1000;
}

/**
 * The stretch of the ray t*(dx, dz), t >= 0, that lies inside the rectangle
 * [x0,x1]×[z0,z1]: [from, to], or null when the ray misses it.
 */
function xeric_layout_span(float $dx, float $dz, float $x0, float $x1, float $z0, float $z1): ?array
{
    $lo = 0.0; $hi = INF;
    foreach ([[$dx, $x0, $x1], [$dz, $z0, $z1]] as [$d, $a, $b]) {
        if ($a > $b) return null;
        if (abs($d) < 1e-9) { if ($a > 0 || $b < 0) return null; continue; }
        $ta = $a / $d; $tb = $b / $d;
        if ($ta > $tb) [$ta, $tb] = [$tb, $ta];
        $lo = max($lo, $ta); $hi = min($hi, $tb);
    }
    return $lo <= $hi && is_finite($hi) ? [$lo, $hi] : null;
}

/**
 * Where along its bearing a w×d box goes: as near `want` as the room lets it,
 * clear of the player, wholly inside the walls. Tries the box both ways round
 * (its preferred way first) and, in a room too small for it either way,
 * shrinks it until it fits — the bearing is the one thing never given up.
 *
 * @return array{0:float,1:float,2:float} distance, w, d
 */
function xeric_layout_fit(float $dx, float $dz, float $w, float $d, float $want, bool $toWall, array $box): array
{
    $m = XERIC_LAYOUT_MARGIN;
    for ($shrink = 0; $shrink < 8; $shrink++) {
        $best = null;
        foreach ([[$w, $d], [$d, $w]] as [$bw, $bd]) {
            $span = xeric_layout_span($dx, $dz, $box['x0'] + $bw / 2 + $m, $box['x1'] - $bw / 2 - $m,
                                                $box['z0'] + $bd / 2 + $m, $box['z1'] - $bd / 2 - $m);
            // Clear of you: the box's own half-length along the ray, and a body's width.
            $clear = abs($dx) * $bw / 2 + abs($dz) * $bd / 2 + 0.35;
            if ($span === null || $span[1] < $clear) continue;
            $lo   = max($span[0], $clear);
            $dist = $toWall ? $span[1] : max($lo, min($want, $span[1]));
            $miss = $toWall ? 0.0 : abs($dist - $want);
            if ($best === null || $miss < $best[3] - 1e-9) $best = [$dist, $bw, $bd, $miss];
        }
        if ($best !== null) return [$best[0], $best[1], $best[2]];
        $w *= 0.8; $d *= 0.8;
    }
    return [max(0.3, $want), $w, $d];   // a room smaller than any furniture: an honest overlap
}

/**
 * The room as a box, from the brief.
 *
 * @return array{version:int,units:string,room:array,vantage:array,objects:array}
 */
function xeric_layout(array $brief, array $t): array
{
    $key  = (string)($brief['key'] ?? '');
    $dims = xeric_layout_room($t, $key);
    [$W, $D, $H] = [$dims['width'], $dims['depth'], $dims['height']];
    $wide = ($brief['vantage'] ?? 'first_person') === 'establishing';
    $r3   = fn(float $v): float => round($v, 3) + 0.0;   // + 0.0: never a "-0"

    // The box, around the origin the bearings are measured from.
    if ($wide) {
        $box = ['x0' => -$W / 2, 'x1' => $W / 2, 'z0' => -$D / 2, 'z1' => $D / 2];
        $vx  = $box['x0'] + 0.4;
        $vz  = $box['z0'] + 0.4;
        $yaw = fmod(rad2deg(atan2(-$vx, -$vz)) + 360, 360);   // looking at the middle
    } else {
        $box = ['x0' => -$W / 2, 'x1' => $W / 2, 'z0' => -XERIC_LAYOUT_INSIDE, 'z1' => $D - XERIC_LAYOUT_INSIDE];
        $vx  = 0.0; $vz = 0.0; $yaw = 0.0;
    }

    $objects = [];
    $place = function (string $id, string $label, string $kind, int $bearing, int $seed, array $row,
                       string $word, float $near, float $far) use (&$objects, $box, $r3): void {
        [$size, $w, $d, $h, $y] = $row;
        $rad = deg2rad($bearing);
        $dx  = sin($rad); $dz = cos($rad);
        // Long side along the wall it is nearest: something off to the side
        // turns its length to run front to back.
        if (abs($dx) > abs($dz)) [$w, $d] = [$d, $w];
        $want = $near + ($far - $near) * xeric_layout_jitter($seed);
        // A window or a door is pushed back to the wall; anything else is as
        // near its distance as the room lets it be.
        [$dist, $w, $d] = xeric_layout_fit($dx, $dz, $w, $d, $want, in_array($word, XERIC_LAYOUT_ON_WALL, true), $box);
        $objects[] = [
            'id' => $id, 'label' => $label, 'kind' => $kind, 'bearing' => $bearing,
            'distance' => $r3($dist), 'x' => $r3($dist * $dx), 'z' => $r3($dist * $dz),
            'y' => $r3($y), 'w' => $r3($w), 'd' => $r3($d), 'h' => $r3($h),
            'size' => $size, 'seed' => $seed,
        ];
    };

    foreach ((array)($brief['interior'] ?? []) as $i => $it) {
        $label = (string)($it['text'] ?? '');
        if ($label === '') continue;
        [$word, $row] = xeric_layout_kind($label);
        $place('thing:' . $i, $label, 'thing', (int)($it['bearing'] ?? 0), (int)($it['seed'] ?? crc32($label)),
               $row, $word, 1.5, 2.5);
    }
    $faces = (array)($brief['seeds']['faces'] ?? []);
    foreach ((array)($brief['who'] ?? []) as $w) {
        $h = (string)($w['handle'] ?? '');
        if ($h === '') continue;
        $seed = (int)($faces[$h] ?? crc32($key . '|' . $h));
        $place('person:' . $h, (string)($w['name'] ?? $h), 'person', (int)($w['bearing'] ?? 0), $seed,
               XERIC_LAYOUT_PERSON, '', 1.2, 2.0);
    }
    // You, at the vantage. The camera is you, so you are never in the picture.
    [, $pw, $pd, $ph] = XERIC_LAYOUT_PERSON;
    $objects[] = ['id' => 'player:you', 'label' => 'you', 'kind' => 'player',
                  'bearing' => (int)round($yaw) % 360, 'distance' => $r3(hypot($vx, $vz)),
                  'x' => $r3($vx), 'z' => $r3($vz), 'y' => 0.0, 'w' => $pw, 'd' => $pd, 'h' => $ph,
                  'size' => 'small', 'seed' => (int)crc32($key . '|you')];

    return [
        'version' => XERIC_LAYOUT_VERSION,
        'units'   => 'm',
        'room'    => ['width' => $W, 'depth' => $D, 'height' => $H,
                      'x0' => $r3($box['x0']), 'x1' => $r3($box['x1']),
                      'z0' => $r3($box['z0']), 'z1' => $r3($box['z1'])],
        'vantage' => ['mode' => $wide ? 'establishing' : 'first_person',
                      'x' => $r3($vx), 'y' => XERIC_LAYOUT_EYE, 'z' => $r3($vz), 'yaw' => $r3($yaw),
                      'projection' => 'equirectangular'],
        'objects' => $objects,
    ];
}

// ---------------------------------------------------------------------------
// The pictures: one ray per pixel from the vantage
// ---------------------------------------------------------------------------

/**
 * Cast the equirectangular grid. Column 0's left edge is directly behind,
 * the middle column is the vantage's yaw; row 0 is straight up.
 *
 * @return array{0:float[],1:string[]} per pixel, the nearest distance and
 *         what it hit: 'floor' | 'wall' | 'ceiling' | an object index
 */
function xeric_layout_cast(array $layout, int $w, int $h): array
{
    xeric_layout_casts(xeric_layout_casts() + 1);
    $room = $layout['room'];
    $v    = $layout['vantage'];
    [$ox, $oy, $oz] = [(float)$v['x'], (float)$v['y'], (float)$v['z']];
    [$x0, $x1, $z0, $z1, $y1] = [(float)$room['x0'], (float)$room['x1'], (float)$room['z0'], (float)$room['z1'],
                                 (float)$room['height']];

    // Each object's box, and the yaw/pitch window it can be seen in: the
    // bounding angles first, so a pixel tries only what could be there.
    $boxes = [];
    foreach ($layout['objects'] as $i => $o) {
        if ($o['kind'] === 'player') continue;
        $b = [(float)$o['x'] - $o['w'] / 2, (float)$o['y'], (float)$o['z'] - $o['d'] / 2,
              (float)$o['x'] + $o['w'] / 2, (float)$o['y'] + $o['h'], (float)$o['z'] + $o['d'] / 2];
        $cx = max($b[0], min($ox, $b[3])); $cz = max($b[2], min($oz, $b[5]));
        $dmin = hypot($cx - $ox, $cz - $oz);
        if ($dmin < 1e-6) { $boxes[$i] = $b + [6 => -INF, 7 => INF, 8 => -M_PI, 9 => M_PI, 10 => true]; continue; }
        $mid = atan2(((float)$o['x']) - $ox, ((float)$o['z']) - $oz);
        $lo = INF; $hi = -INF; $dmax = 0.0;
        foreach ([[$b[0], $b[2]], [$b[0], $b[5]], [$b[3], $b[2]], [$b[3], $b[5]]] as [$px, $pz]) {
            $a = atan2($px - $ox, $pz - $oz) - $mid;
            $a = atan2(sin($a), cos($a));
            $lo = min($lo, $a); $hi = max($hi, $a);
            $dmax = max($dmax, hypot($px - $ox, $pz - $oz));
        }
        $ratios = [($b[4] - $oy) / $dmin, ($b[4] - $oy) / $dmax, ($b[1] - $oy) / $dmin, ($b[1] - $oy) / $dmax];
        $boxes[$i] = $b + [6 => atan(min($ratios)), 7 => atan(max($ratios)), 8 => $mid + $lo, 9 => $mid + $hi, 10 => false];
    }

    $yaw0 = deg2rad((float)$v['yaw']);
    $pad  = 2 * M_PI / $w;   // a pixel's slack on the bounding angles
    $cols = [];
    for ($u = 0; $u < $w; $u++) {
        $a = $yaw0 - M_PI + ($u + 0.5) * 2 * M_PI / $w;
        $cand = [];
        foreach ($boxes as $i => $b) {
            if ($b[10]) { $cand[] = $i; continue; }
            $rel = atan2(sin($a - ($b[8] + $b[9]) / 2), cos($a - ($b[8] + $b[9]) / 2));
            if (abs($rel) <= ($b[9] - $b[8]) / 2 + $pad) $cand[] = $i;
        }
        $cols[$u] = [sin($a), cos($a), $cand];
    }
    $rows = [];
    for ($r = 0; $r < $h; $r++) {
        $p = M_PI / 2 - ($r + 0.5) * M_PI / $h;
        $rows[$r] = [sin($p), cos($p), $p];
    }

    $dist = []; $hit = [];
    $ppad = M_PI / $h;
    for ($r = 0; $r < $h; $r++) {
        [$sp, $cp, $pitch] = $rows[$r];
        $dy = $sp;
        $ty = $dy > 1e-9 ? ($y1 - $oy) / $dy : ($dy < -1e-9 ? -$oy / $dy : INF);
        for ($u = 0; $u < $w; $u++) {
            [$sa, $ca, $cand] = $cols[$u];
            $dx = $cp * $sa; $dz = $cp * $ca;
            $tx = $dx > 1e-9 ? ($x1 - $ox) / $dx : ($dx < -1e-9 ? ($x0 - $ox) / $dx : INF);
            $tz = $dz > 1e-9 ? ($z1 - $oz) / $dz : ($dz < -1e-9 ? ($z0 - $oz) / $dz : INF);
            if ($ty <= $tx && $ty <= $tz) { $t = $ty; $what = $dy > 0 ? 'ceiling' : 'floor'; }
            else { $t = min($tx, $tz); $what = 'wall'; }
            foreach ($cand as $i) {
                $b = $boxes[$i];
                if ($pitch < $b[6] - $ppad || $pitch > $b[7] + $ppad) continue;
                // The slab test, from outside the box.
                $tn = -INF; $tf = INF;
                foreach ([[$dx, $ox, $b[0], $b[3]], [$dy, $oy, $b[1], $b[4]], [$dz, $oz, $b[2], $b[5]]] as [$dd, $oo, $lo, $hi]) {
                    if (abs($dd) < 1e-12) { if ($oo < $lo || $oo > $hi) { $tn = INF; break; } continue; }
                    $ta = ($lo - $oo) / $dd; $tb = ($hi - $oo) / $dd;
                    if ($ta > $tb) [$ta, $tb] = [$tb, $ta];
                    if ($ta > $tn) $tn = $ta;
                    if ($tb < $tf) $tf = $tb;
                    if ($tn > $tf) break;
                }
                if ($tn <= $tf && $tn > 0 && $tn < $t) { $t = $tn; $what = $i; }
            }
            $dist[] = $t; $hit[] = $what;
        }
    }
    return [$dist, $hit];
}

/**
 * How many times the grid has been cast in this process — the tests' seam,
 * so "one cast per body" is a number and not a hope. $set resets it.
 */
function xeric_layout_casts(?int $set = null): int
{
    static $n = 0;
    if ($set !== null) $n = $set;
    return $n;
}

/**
 * Both pictures from ONE cast: the depth map and the segmentation map are the
 * same rays read two ways, and a 512 x 256 cast is the expensive half of
 * either. What a request carries both of asks here.
 *
 * @return array{depth:string,seg:string}
 */
function xeric_layout_pngs(array $layout, int $w = 512, int $h = 256): array
{
    $cast = xeric_layout_cast($layout, $w, $h);
    return ['depth' => xeric_layout_depth_png($layout, $w, $h, $cast),
            'seg'   => xeric_layout_seg_png($layout, $w, $h, $cast)];
}

/** The farthest any ray can go: the room's own diagonal. */
function xeric_layout_far(array $layout): float
{
    $r = $layout['room'];
    return sqrt($r['width'] ** 2 + $r['depth'] ** 2 + $r['height'] ** 2);
}

/**
 * An equirectangular depth map, 8-bit grayscale PNG: near is bright, the far
 * diagonal black. $cast is xeric_layout_cast's answer for this layout at this
 * size, when the caller already has it (xeric_layout_pngs).
 */
function xeric_layout_depth_png(array $layout, int $w = 512, int $h = 256, ?array $cast = null): string
{
    [$dist] = $cast ?? xeric_layout_cast($layout, $w, $h);
    $far = xeric_layout_far($layout);
    $raw = '';
    for ($r = 0; $r < $h; $r++) {
        $row = [0];
        for ($u = 0, $k = $r * $w; $u < $w; $u++, $k++) {
            $row[] = max(0, min(255, (int)round(255 * (1 - $dist[$k] / $far))));
        }
        $raw .= pack('C*', ...$row);
    }
    return xeric_layout_png($w, $h, 0, $raw);
}

/**
 * The same projection as flat colour: floor, walls and ceiling three greys
 * (no saturation at all), each thing its own colour from its seed in hues
 * 0–249, each person theirs in 300–339 — so a model, or a test, can tell a
 * person from a pie case by hue alone.
 */
function xeric_layout_seg_png(array $layout, int $w = 512, int $h = 256, ?array $cast = null): string
{
    [, $hit] = $cast ?? xeric_layout_cast($layout, $w, $h);
    $pal = ['floor' => [64, 64, 64], 'wall' => [160, 160, 160], 'ceiling' => [224, 224, 224]];
    foreach ($layout['objects'] as $i => $o) $pal[$i] = xeric_layout_colour($o);
    $raw = '';
    for ($r = 0; $r < $h; $r++) {
        $row = [0];
        for ($u = 0, $k = $r * $w; $u < $w; $u++, $k++) {
            $c = $pal[$hit[$k]];
            $row[] = $c[0]; $row[] = $c[1]; $row[] = $c[2];
        }
        $raw .= pack('C*', ...$row);
    }
    return xeric_layout_png($w, $h, 2, $raw);
}

/** An object's flat colour, [r, g, b]. */
function xeric_layout_colour(array $o): array
{
    $s = abs((int)$o['seed']);
    [$lo, $hi] = $o['kind'] === 'person' ? XERIC_LAYOUT_HUE_PEOPLE : XERIC_LAYOUT_HUE_THINGS;
    $hue = $lo + $s % ($hi - $lo);
    $sat = $o['kind'] === 'person' ? 0.9 : 0.6 + (intdiv($s, 997) % 30) / 100;
    $val = $o['kind'] === 'person' ? 0.95 : 0.65 + (intdiv($s, 7919) % 30) / 100;
    return xeric_layout_hsv($hue, $sat, $val);
}

function xeric_layout_hsv(float $h, float $s, float $v): array
{
    $c = $v * $s; $hp = fmod($h, 360) / 60; $x = $c * (1 - abs(fmod($hp, 2) - 1)); $m = $v - $c;
    [$r, $g, $b] = match ((int)floor($hp)) {
        0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x], 3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x],
    };
    return [(int)round(($r + $m) * 255), (int)round(($g + $m) * 255), (int)round(($b + $m) * 255)];
}

/** Hue in degrees, saturation 0..1, of an [r, g, b]. */
function xeric_layout_hue(array $rgb): array
{
    [$r, $g, $b] = array_map(fn($c) => $c / 255, $rgb);
    $mx = max($r, $g, $b); $mn = min($r, $g, $b); $c = $mx - $mn;
    if ($c == 0) return [0.0, 0.0];
    $h = match ($mx) { $r => fmod(($g - $b) / $c + 6, 6), $g => ($b - $r) / $c + 2, default => ($r - $g) / $c + 4 };
    return [$h * 60, $c / $mx];
}

/**
 * A PNG, by hand: signature, IHDR, one IDAT of zlib-deflated scanlines (each
 * already led by its filter byte), IEND. Colour type 0 is grey, 2 is RGB;
 * 8 bits a sample. No GD, so it runs wherever PHP and zlib do.
 */
function xeric_layout_png(int $w, int $h, int $colourType, string $raw): string
{
    $chunk = fn(string $type, string $data): string
        => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, $colourType, 0, 0, 0))
        . $chunk('IDAT', (string)gzcompress($raw, 6))
        . $chunk('IEND', '');
}
