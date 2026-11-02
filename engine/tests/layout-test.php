<?php
/**
 * Xeric — layout tests.  `php engine/tests/layout-test.php`, exit 0 on pass.
 *
 * The room as a box (engine/layout.php): deterministic to the byte, every
 * thing at the bearing the brief dealt it, two PNGs that decode and look like
 * what they say (near is bright; a person is a hue no thing ever wears), no
 * word in it the brief did not already carry, nothing it adds to the
 * fingerprint — and the generic request carrying it always, its pictures
 * only when the world asks.
 */

declare(strict_types=1);

require_once __DIR__ . '/../scene.php';

$FAILED = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) {
        echo "ok   - $name\n";
    } else {
        $FAILED++;
        echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

// render-test.php's list, verbatim: every one lives behind a wall in milldale.
const SECRETS = [
    'five-card draw',
    'poker',
    'green spiral notebook',
    'glovebox',
    "comping Harlan's lunch",
    'moves from window to window',
    'The pot never leaves the basement',
    'standing seat at the table',
];

/**
 * A PNG, read back by hand: signature, every chunk's CRC, IHDR, and the
 * IDATs re-inflated. Returns null on anything malformed.
 *
 * @return ?array{w:int,h:int,type:int,depth:int,raw:string}
 */
function png_read(string $png): ?array
{
    if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $at = 8; $ihdr = null; $idat = ''; $end = false;
    while ($at + 12 <= strlen($png)) {
        $len  = unpack('N', substr($png, $at, 4))[1];
        $type = substr($png, $at + 4, 4);
        $data = substr($png, $at + 8, $len);
        $crc  = unpack('N', substr($png, $at + 8 + $len, 4))[1];
        if ($crc !== crc32($type . $data)) return null;
        if ($type === 'IHDR') $ihdr = unpack('Nw/Nh/Cdepth/Ctype', $data);
        if ($type === 'IDAT') $idat .= $data;
        if ($type === 'IEND') { $end = true; break; }
        $at += 12 + $len;
    }
    if ($ihdr === null || !$end) return null;
    $raw = @gzuncompress($idat);
    if ($raw === false) return null;
    return ['w' => $ihdr['w'], 'h' => $ihdr['h'], 'type' => $ihdr['type'], 'depth' => $ihdr['depth'], 'raw' => $raw];
}

/** One pixel of a decoded filter-0 PNG: [v] or [r, g, b]. */
function png_px(array $p, int $u, int $r): array
{
    $n = $p['type'] === 2 ? 3 : 1;
    $o = $r * (1 + $p['w'] * $n) + 1 + $u * $n;
    return array_values(unpack('C' . $n, substr($p['raw'], $o, $n)));
}

/** The column and row a yaw/pitch (degrees) lands on. */
function px_at(array $layout, float $bearing, float $pitch, int $w, int $h): array
{
    $rel = fmod($bearing - (float)$layout['vantage']['yaw'] + 180 + 720, 360);
    return [min($w - 1, (int)floor($rel / 360 * $w)), min($h - 1, (int)floor((90 - $pitch) / 180 * $h))];
}

$T  = xeric_template_load(__DIR__ . '/../fixtures/milldale.json');
$TZ = new DateTimeZone((string)$T['user']['timezone']);
$at = fn(string $local) => xeric_world_now($T, (new DateTimeImmutable($local, $TZ))->getTimestamp());

$dbP = sys_get_temp_dir() . '/xeric-layout-test-' . getmypid() . '.sqlite';
foreach ([$dbP, $dbP . '-wal', $dbP . '-shm'] as $f) @unlink($f);
$db = xeric_state_open($dbP);
xeric_clock_begin($db, $T);

$mon0710 = $at('2026-11-02 07:10');   // Dot alone at the Bluebird
$mon1600 = $at('2026-11-02 16:00');   // Theo in the back booth

// The fixture's Bluebird has no interior; give it one, as an author would.
$Ti = $T;
foreach ($Ti['places'] as &$p) {
    if ($p['key'] === 'bluebird') $p['interior'] = ['the counter', 'a back booth', 'the pie case',
                                                    'a stool by the register', 'the front window', 'a jukebox'];
}
unset($p);

// ---------------------------------------------------------------------------
echo "# the shape\n";
// ---------------------------------------------------------------------------

$b  = xeric_scene_brief($Ti, $db, 'bluebird', $mon0710);
$L  = $b['layout'];
ok('the brief carries a layout, version 1, in metres',
    is_array($L) && $L['version'] === 1 && $L['units'] === 'm'
    && array_keys($L) === ['version', 'units', 'room', 'vantage', 'objects']);
ok('the default room is 6 x 4 x 3 and the vantage is just inside the door, facing in',
    $L['room']['width'] == 6 && $L['room']['depth'] == 4 && $L['room']['height'] == 3
    && $L['vantage']['mode'] === 'first_person' && $L['vantage']['yaw'] == 0
    && $L['room']['z0'] == -1 && $L['room']['z1'] == 3);
$kinds = array_count_values(array_column($L['objects'], 'kind'));
ok('every item, every person present and you',
    ($kinds['thing'] ?? 0) === 6 && ($kinds['person'] ?? 0) === 1 && ($kinds['player'] ?? 0) === 1,
    json_encode($kinds));
$keys = ['id', 'label', 'kind', 'bearing', 'distance', 'x', 'z', 'y', 'w', 'd', 'h', 'size', 'seed'];
ok('each object has the documented fields',
    array_filter($L['objects'], fn($o) => array_keys($o) !== $keys) === []);
$by = array_column($L['objects'], null, 'label');
ok('the keyword table: a counter is large, a stool small, a jukebox (unknown) medium',
    $by['the counter']['size'] === 'large' && $by['a stool by the register']['size'] === 'small'
    && $by['a jukebox']['size'] === 'medium' && $by['the pie case']['size'] === 'medium');
ok('a word, not a substring: a barber\'s chair is not a bar, a bar stool is a bar',
    xeric_layout_kind("the barber's chair")[0] === '' && xeric_layout_kind('a bar stool')[0] === 'bar'
    && xeric_layout_kind('two tables')[0] === 'table');

// ---------------------------------------------------------------------------
echo "\n# where things stand\n";
// ---------------------------------------------------------------------------

$bear = array_column($b['interior'], 'bearing', 'text') + array_column($b['who'], 'bearing', 'name');
$off = [];
foreach ($L['objects'] as $o) {
    if ($o['kind'] === 'player') continue;
    $a = fmod(rad2deg(atan2($o['x'], $o['z'])) + 360, 360);
    $d = abs($a - $o['bearing']); $d = min($d, 360 - $d);
    if ($d > 1 || $o['bearing'] !== $bear[$o['label']]) $off[] = $o['label'] . '@' . round($a, 2);
}
ok('every thing and person stands at the bearing the brief dealt it, within a degree', $off === [], implode(', ', $off));
$out = [];
foreach ($L['objects'] as $o) {
    $r = $L['room'];
    if ($o['x'] - $o['w'] / 2 < $r['x0'] - 1e-6 || $o['x'] + $o['w'] / 2 > $r['x1'] + 1e-6
        || $o['z'] - $o['d'] / 2 < $r['z0'] - 1e-6 || $o['z'] + $o['d'] / 2 > $r['z1'] + 1e-6) $out[] = $o['label'];
}
ok('and wholly inside the walls', $out === [], implode(', ', $out));
$who = $by['Dot Vance'];
ok('a person is a body, 1.2-2 m out, unless a wall says nearer',
    $who['h'] == 1.7 && $who['distance'] <= 2.0 && $who['distance'] > 0.3);
$furn = array_filter($L['objects'], fn($o) => $o['kind'] === 'thing' && !str_contains($o['label'], 'window'));
ok('furniture is never further than 2.5 m and never on top of you',
    array_filter($furn, fn($o) => $o['distance'] > 2.5 || $o['distance'] < 0.3) === []);
$win = $by['the front window'];
ok('a window is a pane on the wall, raised off the floor',
    $win['y'] == 0.9 && min($win['w'], $win['d']) == 0.1
    && (abs(abs($win['x']) + min($win['w'], $win['d']) / 2 + XERIC_LAYOUT_MARGIN - 3) < 0.01
        || abs($win['z'] - $win['d'] / 2 - XERIC_LAYOUT_MARGIN + 1) < 0.01 || abs($win['z'] + $win['d'] / 2 + XERIC_LAYOUT_MARGIN - 3) < 0.01),
    json_encode($win));

$Tl = $Ti;
foreach ($Tl['places'] as &$p) if ($p['key'] === 'bluebird') $p['layout'] = ['width' => 12, 'depth' => 9, 'height' => 'tall'];
unset($p);
$Ll = xeric_scene_brief($Tl, $db, 'bluebird', $mon0710)['layout'];
ok('the place\'s own layout block sizes the room; a bad number is the default',
    $Ll['room']['width'] == 12 && $Ll['room']['depth'] == 9 && $Ll['room']['height'] == 3);

$Te = $Ti;
$Te['media']['scene'] = ['vantage' => 'establishing'];
$Le = xeric_scene_brief($Te, $db, 'bluebird', $mon0710)['layout'];
$v  = $Le['vantage'];
ok('establishing: a corner, looking across the room at its middle',
    $v['mode'] === 'establishing' && $v['x'] < -2 && $v['z'] < -1
    && abs(fmod(rad2deg(atan2(-$v['x'], -$v['z'])) + 360, 360) - $v['yaw']) < 0.01);

// ---------------------------------------------------------------------------
echo "\n# determinism\n";
// ---------------------------------------------------------------------------

$b2 = xeric_scene_brief($Ti, $db, 'bluebird', $mon0710);
$t0 = microtime(true);
$D1 = xeric_layout_depth_png($L);
$tD = microtime(true) - $t0;
$t0 = microtime(true);
$S1 = xeric_layout_seg_png($L);
$tS = microtime(true) - $t0;
ok('same brief, byte-identical layout', json_encode($b2['layout']) === json_encode($L));
ok('and byte-identical pictures', xeric_layout_depth_png($b2['layout']) === $D1 && xeric_layout_seg_png($b2['layout']) === $S1);
ok('512 x 256 in under a second each', $tD < 1.0 && $tS < 1.0, sprintf('depth %.3fs, seg %.3fs', $tD, $tS));
ok('pure: a layout from the brief alone equals the brief\'s', json_encode(xeric_layout($b, $Ti)) === json_encode($L));

// ---------------------------------------------------------------------------
echo "\n# the pictures\n";
// ---------------------------------------------------------------------------

$D = png_read($D1);
$S = png_read($S1);
ok('the depth map decodes: signature, CRCs, 512 x 256 8-bit grey, every scanline there',
    $D !== null && $D['w'] === 512 && $D['h'] === 256 && $D['type'] === 0 && $D['depth'] === 8
    && strlen($D['raw']) === 256 * (1 + 512));
ok('the segmentation map decodes: 512 x 256 8-bit RGB',
    $S !== null && $S['w'] === 512 && $S['h'] === 256 && $S['type'] === 2 && strlen($S['raw']) === 256 * (1 + 512 * 3));
ok('a size asked for is the size drawn', ($q = png_read(xeric_layout_depth_png($L, 64, 32))) !== null
    && $q['w'] === 64 && $q['h'] === 32 && strlen($q['raw']) === 32 * 65);

// The nearest thing, looked straight at, is brighter than the far wall
// behind it and than any wall at the horizon.
$things = array_values(array_filter($L['objects'], fn($o) => $o['kind'] !== 'player'));
usort($things, fn($a, $c) => $a['distance'] <=> $c['distance']);
$near  = $things[0];
$pitch = rad2deg(atan2($near['y'] + $near['h'] / 2 - XERIC_LAYOUT_EYE, $near['distance']));
[$nu, $nr] = px_at($L, $near['bearing'], $pitch, 512, 256);
[, $hit] = xeric_layout_cast($L, 512, 256);
$wallMax = 0;
for ($u = 0; $u < 512; $u++) if ($hit[128 * 512 + $u] === 'wall') $wallMax = max($wallMax, png_px($D, $u, 128)[0]);
$nv = png_px($D, $nu, $nr)[0];
ok('the nearest thing, at its bearing, is brighter than every wall at the horizon',
    is_int($hit[$nr * 512 + $nu]) && $nv > $wallMax, "{$near['label']} $nv vs wall $wallMax");
[$fu, $fr] = px_at($L, 0, 0, 512, 256);
ok('straight ahead at eye level, past nothing, the far wall is darker than the nearest thing',
    png_px($D, $fu, $fr)[0] < $nv);
ok('the ceiling is above, the floor below',
    $hit[0] === 'ceiling' && $hit[255 * 512] === 'floor');

$bad = []; $people = 0; $thingPx = 0;
foreach ($hit as $k => $what) {
    $rgb = png_px($S, $k % 512, intdiv($k, 512));
    [$hue, $sat] = xeric_layout_hue($rgb);
    if (is_string($what)) { if ($sat > 0.01) $bad[] = "$what saturated"; continue; }
    $kind = $L['objects'][$what]['kind'];
    if ($kind === 'person') {
        $people++;
        if ($hue < XERIC_LAYOUT_HUE_PEOPLE[0] - 1 || $hue >= XERIC_LAYOUT_HUE_PEOPLE[1] + 1 || $sat < 0.5) $bad[] = "person hue $hue";
    } else {
        $thingPx++;
        if ($hue >= XERIC_LAYOUT_HUE_THINGS[1] + 1 || $sat < 0.5) $bad[] = "thing hue $hue";
    }
}
ok('people wear only the reserved hues, things never do, the room is grey',
    $bad === [] && $people > 50 && $thingPx > 500, count($bad) . ' bad; ' . implode(', ', array_slice(array_unique($bad), 0, 5)));
$cols = [];
foreach ($L['objects'] as $o) if ($o['kind'] === 'thing') $cols[] = implode(',', xeric_layout_colour($o));
ok('each thing its own colour', count(array_unique($cols)) === count($cols));

// ---------------------------------------------------------------------------
echo "\n# nothing it should not\n";
// ---------------------------------------------------------------------------

// The solo Bluebird's fingerprint as cd2eb94 computed it, before there was a
// layout — the key every view on disk was filed under: the layout added nothing.
$solo = xeric_scene_brief($T, $db, 'bluebird', $mon0710);
ok('a solo world\'s fingerprint is unchanged', $solo['fingerprint'] === '7aa64a6a5a0ef21442b8506caa554147a7606a9e',
    $solo['fingerprint']);
$noL = $solo; unset($noL['layout']);
$cfg = xeric_scene_config($T);
$items = array_column($solo['interior'], 'text');
ok('the fingerprint does not read the layout',
    xeric_scene_fingerprint($noL, $solo['exterior']['description'], $items, $cfg) === $solo['fingerprint']
    && xeric_scene_brief($Tl, $db, 'bluebird', $mon0710)['fingerprint'] === $b['fingerprint']);

$hours = ['2026-11-01 08:30', '2026-11-02 07:10', '2026-11-02 16:00', '2026-11-05 20:00', '2026-11-03 23:30'];
$found = []; $scanned = 0; $stray = [];
foreach ($hours as $h) {
    foreach ($T['places'] as $p) {
        $sb = xeric_scene_brief($T, $db, (string)$p['key'], $at($h));
        if ($sb === null) continue;
        $scanned++;
        $hay = json_encode($sb['layout'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (SECRETS as $s) if (stripos($hay, $s) !== false) $found[] = $p['key'] . '@' . $h . ': ' . $s;
        $own = array_merge(array_column($sb['interior'], 'text'), array_column($sb['who'], 'name'), ['you']);
        foreach ($sb['layout']['objects'] as $o) if (!in_array($o['label'], $own, true)) $stray[] = $o['label'];
    }
}
ok('no secret in any layout at any hour', $found === [] && $scanned >= 15, $scanned . ' layouts; ' . implode(' | ', $found));
ok('every label is the brief\'s own words', $stray === [], implode(', ', $stray));

// ---------------------------------------------------------------------------
echo "\n# the envelope\n";
// ---------------------------------------------------------------------------

$body = xeric_scene_body($b, ['kinds' => ['pano', 'image']]);
ok('the generic body carries the layout, once', ($body['layout'] ?? null) === $L && !isset($body['brief']['layout']));
ok('and no pictures unless asked', !isset($body['layout_depth']) && !isset($body['layout_seg']));
ok('the world does not ask by default', xeric_layout_images($T) === false);
$Tq = $Ti;
$Tq['media']['scene'] = ['layout_images' => true];
$bodyQ = xeric_scene_body($b, ['layout_images' => xeric_layout_images($Tq)]);
ok('asked, both pictures ride as {mime, b64} and are the same bytes',
    xeric_layout_images($Tq) === true
    && ($bodyQ['layout_depth']['mime'] ?? '') === 'image/png'
    && base64_decode($bodyQ['layout_depth']['b64']) === $D1
    && base64_decode($bodyQ['layout_seg']['b64']) === $S1);
ok('the body is still JSON', json_encode($bodyQ) !== false);
xeric_layout_casts(0);
$bodyQ1 = xeric_scene_body($b, ['layout_images' => true]);
ok('ONE cast per body: depth and segmentation are the same rays read twice, and the bytes have not changed',
    xeric_layout_casts() === 1 && base64_decode($bodyQ1['layout_depth']['b64']) === $D1
    && base64_decode($bodyQ1['layout_seg']['b64']) === $S1, (string)xeric_layout_casts());
xeric_layout_casts(0);
$pp = xeric_layout_pngs($L, 64, 32);
ok('and xeric_layout_pngs is that one cast, at any size', xeric_layout_casts() === 1
    && $pp['depth'] === xeric_layout_depth_png($L, 64, 32) && $pp['seg'] === xeric_layout_seg_png($L, 64, 32));

// THE LAYOUT IT WAS ASKED WITH. A look queued while Mabel was in the room and
// reaped after she left is rendered as asked — its prompt, its fingerprint,
// and its box with her in it, the control images drawn from that box.
$Tm = $Ti;
$Tm['media']['scene']['layout_images'] = true;
$bNow = xeric_scene_brief($Tm, $db, 'bluebird');
$asked = $bNow;
$mabel = ['id' => 'mabel', 'label' => 'Mabel', 'kind' => 'person', 'bearing' => 90, 'distance' => 1.5, 'x' => 1.5, 'z' => 0.0,
          'y' => 0.0, 'w' => 0.5, 'd' => 0.5, 'h' => 1.7, 'size' => 'small', 'seed' => 4242];
$asked['layout']['objects'][] = $mabel;
$asked['fingerprint'] = sha1('the bluebird, with mabel in it');
$asked['prompt'] = 'The Bluebird Diner, Mabel at the counter.';
$jid = xeric_scene_enqueue($db, $asked);
$db->prepare('DELETE FROM scene_jobs WHERE id != ?')->execute([$jid]);
$sentBody = null;
$scenesDir = sys_get_temp_dir() . '/xeric-layout-scenes-' . getmypid();
$rp = xeric_scene_reap($Tm, $db, $scenesDir, ['stub' => function (string $tag, array $br, array $o) use (&$sentBody) {
    $sentBody = xeric_scene_body($br, $o);
    return ['kind' => 'image', 'bytes' => "\x89PNG\r\n\x1a\nx", 'mime' => 'image/png'];
}], 1, true);
$sentL = $sentBody['layout'] ?? [];
ok('the room has moved on since: the reaper says so', preg_grep('/changed since/', $rp['notes']) !== [], json_encode($rp));
ok('and sends the stored layout with the stored prompt — Mabel still in it',
    in_array('Mabel', array_column((array)($sentL['objects'] ?? []), 'label'), true)
    && ($sentBody['prompt'] ?? '') === $asked['prompt'] && ($sentBody['fingerprint'] ?? '') === $asked['fingerprint']
    && !in_array('Mabel', array_column($bNow['layout']['objects'], 'label'), true), json_encode(array_column((array)($sentL['objects'] ?? []), 'label')));
ok('its control images are drawn from that stored box, not from the room now',
    base64_decode((string)($sentBody['layout_seg']['b64'] ?? '')) === xeric_layout_seg_png($asked['layout'])
    && base64_decode((string)($sentBody['layout_seg']['b64'] ?? '')) !== xeric_layout_seg_png($bNow['layout']));
$db->prepare("UPDATE scene_jobs SET status = 'pending', layout = '', fingerprint = ? WHERE id = ?")->execute([sha1('older'), $jid]);
$sentBody = null;
xeric_scene_reap($Tm, $db, $scenesDir, ['stub' => function (string $tag, array $br, array $o) use (&$sentBody) {
    $sentBody = xeric_scene_body($br, $o);
    return ['kind' => 'image', 'bytes' => "\x89PNG\r\n\x1a\nx", 'mime' => 'image/png'];
}], 1, true);
ok('a changed room queued before layouts were kept goes with none, never with today\'s',
    is_array($sentBody) && !isset($sentBody['layout']) && !isset($sentBody['layout_seg']), json_encode(array_keys((array)$sentBody)));
foreach (glob($scenesDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($scenesDir);
$sound = xeric_sound_brief($Ti, $db, 'bluebird', $mon0710);
ok('a sound has no room to lay out', !isset(xeric_scene_body($sound, ['channel' => 'sound'])['layout']));

// ---------------------------------------------------------------------------
$db = null;
foreach ([$dbP, $dbP . '-wal', $dbP . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
