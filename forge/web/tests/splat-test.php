<?php
/**
 * Xeric — walking inside a splat. `php forge/web/tests/splat-test.php`, exit 0 on pass.
 *
 * WHAT IS PROVED:
 *   1. THE MARKUP. A done splat row (a .splat or a PLY) is a
 *      <canvas class="looksplat"> pointed at the bytes route, the people as
 *      the still's tag row under it, the download kept beside it; an SPZ that
 *      arrived as `.splat` and a glb stay downloads.
 *   2. THE PAGE. play.php, rendered for real, loads xeric-splat.js
 *      statically — by its content hash, with the nonce — beside xeric-view.js,
 *      mounts it only through XericView, and asks the head for worker-src.
 *   3. THE CONVERTER. tools/splat-to-ply.php turns the fixture .splat into a
 *      PLY, passes a PLY through, refuses SPZ/SOG with a reason; its PLY is
 *      read back by the viewer itself as the .splat it came from.
 *   4. THE VIEWER, RUN. splat-test.js, in Node, against a fake WebGL 2 /
 *      WebGL 1 and a Worker that runs the real worker file: both parsers, the
 *      covariance, the sort, the 3 m rule, the cap, the fallbacks.
 *
 * NEEDS NODE for 4 and SKIPS that half (said as SKIP) when there is none,
 * unless XERIC_REQUIRE_NODE=1 — viewer-test.php's rule.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-splat-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_SCENE_KIND', 'XERIC_SCENE_AUTH',
          'XERIC_IMAGE_BASE', 'XERIC_IMAGE_KEY'] as $k) putenv($k);

require_once __DIR__ . '/../play-lib.php';
require_once dirname(__DIR__, 3) . '/tools/splat-to-ply.php';

$FAILED = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $name\n"; return; }
    $FAILED++;
    echo "FAIL - $name" . ($detail !== '' ? ' (' . mb_substr($detail, 0, 400) . ')' : '') . "\n";
}
function rmtree(string $d): void
{
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $d . '/' . $f;
        is_dir($p) ? rmtree($p) : @unlink($p);
    }
    @rmdir($d);
}
/** A done row, as xeric_scene_finish would leave it. */
function done_row(PDO $db, string $key, string $fp, string $kind, string $file, string $mime): int
{
    $db->prepare("INSERT INTO scene_jobs (key, fingerprint, status, tries, kind, file, url, mime, prompt, meta,
                  created_at, done_at) VALUES (?, ?, 'done', 1, ?, ?, NULL, ?, '', '{}', ?, ?)")
       ->execute([$key, $fp, $kind, $file, $mime, time(), time()]);
    return (int)$db->lastInsertId();
}
/** The fixture: three splats, the compact format, packed by hand. */
function fixture_splat(): string
{
    $s = '';
    foreach ([[[0, 0, -1], [0.1, 0.2, 0.3], [255, 0, 0, 255], [255, 128, 128, 128]],
              [[0, 0, -5], [0.5, 0.5, 0.5], [0, 255, 0, 128], [128, 255, 128, 128]],
              [[1, 0, -3], [0.05, 0.05, 0.05], [0, 0, 255, 64], [219, 128, 128, 219]]] as [$p, $sc, $c, $q]) {
        $s .= pack('g6', ...$p, ...$sc) . pack('C8', ...$c, ...$q);
    }
    return $s;
}

// -- a world with the player in the diner ----------------------------------------------
$slug = 'splat-town';
$t = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$d = xeric_web_worlds_dir() . '/' . $slug;
@mkdir($d, 0775, true);
$j = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents($d . '/world-template.json', json_encode($t, $j));
file_put_contents($d . '/seed.json', json_encode(xeric_forge_default_seed($t), $j));
$sid = bin2hex(random_bytes(16));
xeric_session_use($sid);
xeric_session_claim($slug, $sid);
$w  = xeric_play_open($slug);
$T  = $w['template'];
$db = $w['db'];
$scenes = dirname((string)$w['db_path']) . '/scenes';
@mkdir($scenes, 0775, true);
$now = xeric_clock_now($db, $T);
xeric_player_move($db, 'bluebird', (int)$now['epoch']);
$fp = (string)(xeric_play_look_view($T, $db, $slug, null, false)['brief']['fingerprint'] ?? '');

echo "# the markup\n";

$splat = fixture_splat();
$file  = 'scene-bluebird-' . substr($fp, 0, 12) . '.splat';
file_put_contents($scenes . '/' . $file, $splat);
done_row($db, 'bluebird', $fp, 'splat', $file, 'application/octet-stream');
$h   = xeric_play_look_html($T, $db, null, $slug, true);
$src = 'scene.php?w=' . $slug . '&amp;k=bluebird&amp;f=' . $fp;
$wh  = substr((string)hash_file('sha256', dirname(__DIR__) . '/xeric-splat-worker.js'), 0, 16);
ok('a done .splat is a canvas you stand in, pointed at the bytes route',
    str_contains($h, '<canvas class="looksplat" tabindex="0" role="img" data-src="' . $src . '"'), $h);
ok('which names its worker by content hash, from this origin', str_contains($h, 'data-worker="xeric-splat-worker.js?v=' . $wh . '"'), $h);
ok('with a loading line for the viewer to fill', str_contains($h, '<p class="looksplatstate" role="status">'));
ok('the people as the still\'s row of buttons under it', preg_match('#</canvas>.*<div class="looktagrow"><button type="button" class="looktag"#s', $h) === 1, $h);
ok('and the download kept, after them', preg_match('#looktagrow.*<p class="looksplatdl"><a class="lookdl" href="' . preg_quote($src, '#')
    . '" download>download the room \(\.splat\)</a>#s', $h) === 1, $h);
ok('the bytes route serves it (an attachment; fetch does not care)',
    ($f = xeric_play_look_file($db, 'bluebird', $fp)) !== null && $f['bytes'] === $splat && $f['inline'] === false);

$ply = xeric_splat_to_ply($splat, 'application/octet-stream', '.splat');
file_put_contents($scenes . '/' . $file, (string)$ply);
ok('a PLY stored under the engine\'s .splat name is a canvas too', str_contains(xeric_play_look_html($T, $db, null, $slug, true), 'class="looksplat"'));
file_put_contents($scenes . '/' . $file, "\x1f\x8b\x08\x00" . str_repeat("\0", 28));
$h2 = xeric_play_look_html($T, $db, null, $slug, true);
ok('an SPZ that arrived as .splat stays a download', !str_contains($h2, 'looksplat') && str_contains($h2, 'download the room (.splat)'), $h2);
file_put_contents($scenes . '/' . $file, $splat);
$db->exec('DELETE FROM scene_jobs');
file_put_contents($scenes . '/scene-bluebird-x.glb', "glTF\x02\x00\x00\x00");
done_row($db, 'bluebird', $fp, 'glb', 'scene-bluebird-x.glb', 'model/gltf-binary');
$h3 = xeric_play_look_html($T, $db, null, $slug, true);
ok('a glb stays a download', !str_contains($h3, 'looksplat') && str_contains($h3, 'download the room (.glb)'), $h3);
$db->exec('DELETE FROM scene_jobs');
// As the engine now stores one (xeric_scene_finish): application/ply under .ply.
$plyFile = 'scene-bluebird-' . substr($fp, 0, 12) . '.ply';
file_put_contents($scenes . '/' . $plyFile, (string)$ply);
done_row($db, 'bluebird', $fp, 'splat', $plyFile, 'application/ply');
$f = xeric_play_look_file($db, 'bluebird', $fp);
ok('the bytes route serves a .ply row: application/ply, an attachment, the PLY',
    $f !== null && $f['mime'] === 'application/ply' && $f['inline'] === false && $f['bytes'] === $ply && $f['name'] === $plyFile);
$h4 = xeric_play_look_html($T, $db, null, $slug, true);
ok('and the sidebar walks it, with the download named .ply',
    str_contains($h4, 'class="looksplat"') && str_contains($h4, 'download the room (.ply)'), $h4);
$db->exec("UPDATE scene_jobs SET mime = 'model/ply'");
ok('model/ply is served too', (xeric_play_look_file($db, 'bluebird', $fp)['mime'] ?? '') === 'model/ply');
$db->exec("UPDATE scene_jobs SET mime = 'text/html'");
ok('and a type off the list is still a 404', xeric_play_look_file($db, 'bluebird', $fp) === null);
$db->exec('DELETE FROM scene_jobs');
done_row($db, 'bluebird', $fp, 'splat', $file, 'application/octet-stream');

echo "\n# the converter\n";

ok('.splat → PLY: the standard header, 14 floats a splat',
    $ply !== null && str_starts_with($ply, "ply\nformat binary_little_endian 1.0\nelement vertex 3\nproperty float x\n")
    && str_contains($ply, "property float f_dc_0\n") && str_contains($ply, "property float rot_3\nend_header\n")
    && strlen($ply) === strpos($ply, "end_header\n") + 11 + 3 * 56);
ok('a PLY passes through, byte for byte', xeric_splat_to_ply((string)$ply, 'application/octet-stream', 'ply') === $ply);
ok('a short PLY is refused, and says why', xeric_splat_to_ply(substr((string)$ply, 0, -4), '', 'ply') === null
    && str_contains(xeric_splat_to_ply_why(), 'shorter'));
ok('SPZ is null with a note', xeric_splat_to_ply("\x1f\x8b\x08\x00abcd", '', 'spz') === null && str_contains(xeric_splat_to_ply_why(), 'SPZ'));
ok('SOG is null with a note', xeric_splat_to_ply("PK\x03\x04abcd", '', 'sog') === null && str_contains(xeric_splat_to_ply_why(), 'SOG'));
ok('bytes that are not a whole number of records are null', xeric_splat_to_ply('nope', '', 'splat') === null);
$r = unpack('g14', substr((string)$ply, strpos((string)$ply, "end_header\n") + 11, 56));
ok('the first splat: position, log scale, logit alpha, red f_dc, identity rotation',
    abs($r[3] + 1) < 1e-6 && abs($r[8] - log(0.1)) < 1e-5 && $r[7] > 5 && $r[4] > 1.7 && $r[5] < -1.7
    && abs($r[11] - 1) < 0.01, json_encode($r));
file_put_contents($tmp . '/fixture.splat', $splat);
$cli = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tools/splat-to-ply.php') . ' '
    . escapeshellarg($tmp . '/fixture.splat') . ' ' . escapeshellarg($tmp . '/fixture.ply') . ' 2>&1; echo "exit=$?"');
ok('the CLI writes the same PLY', str_contains($cli, 'exit=0') && @file_get_contents($tmp . '/fixture.ply') === $ply, $cli);

echo "\n# the page\n";

$script = $tmp . '/render-play.php';
file_put_contents($script, '<?php' . "\n"
    . '$_GET = ["w" => ' . var_export($slug, true) . ']; $_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
    . 'require ' . var_export(dirname(__DIR__) . '/boot.php', true) . ';' . "\n"
    . 'xeric_session_use(' . var_export($sid, true) . ');' . "\n"
    . 'chdir(' . var_export(dirname(__DIR__), true) . ');' . "\n"
    . 'require ' . var_export(dirname(__DIR__) . '/play.php', true) . ';' . "\n");
$env  = 'XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
      . ' XERIC_LOCAL_BASE=http://127.0.0.1:1';
$html = (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
$sh   = substr((string)hash_file('sha256', dirname(__DIR__) . '/xeric-splat.js'), 0, 16);
preg_match('#<script nonce="([^"]+)" src="xeric-view\.js\?v=[0-9a-f]{16}"></script>#', $html, $vm);
ok('play.php renders the splat in its look', str_contains($html, 'class="looksplat"'), mb_substr(strip_tags($html), 0, 300));
ok('xeric-splat.js is a static script tag, by its hash, with the page\'s nonce, after xeric-view.js',
    $vm !== [] && preg_match('#<script nonce="' . preg_quote($vm[1], '#') . '" src="xeric-splat\.js\?v=' . $sh . '"></script>#', $html) === 1
    && strpos($html, 'src="xeric-view.js') < strpos($html, 'src="xeric-splat.js'), mb_substr($html, (int)strpos($html, 'xeric-view.js') - 80, 400));
ok('and the page starts it only through XericView, when a splat canvas is there',
    str_contains($html, "XV.mount({ kind: 'splat', canvas: sc, url: sc.dataset.src })"));
ok('no script is ever added by script (no createElement(\'script\'))',
    !str_contains($html, "createElement('script')") && !str_contains((string)file_get_contents(dirname(__DIR__) . '/xeric-splat.js'), "createElement('script')"));
$ui = (string)file_get_contents(dirname(__DIR__) . '/ui.php');
$pp = (string)file_get_contents(dirname(__DIR__) . '/play.php');
ok('the head grants worker-src \'self\' to play.php (and nothing wider)',
    str_contains($ui, "!empty(\$opts['worker']) ? \"; worker-src 'self'\"") && !preg_match('/worker-src[^"]*blob:/', $ui)
    && preg_match("/xeric_web_head\\('Xeric: ' \\. \\(string\\)\\\$T\\['meta'\\]\\['name'\\], \\[[^\\]]*'worker' => true/", $pp) === 1);
$sj = (string)file_get_contents(dirname(__DIR__) . '/xeric-splat.js') . (string)file_get_contents(dirname(__DIR__) . '/xeric-splat-worker.js');
ok('no WebAssembly, no blob, no eval in the viewer or its worker',
    !preg_match('/WebAssembly\.|[\'"]blob:|createObjectURL|\beval\(|new Function/', $sj));

// -- the viewer, run ------------------------------------------------------------------
$node = (string)getenv('XERIC_NODE');
if ($node === '' || !is_executable($node)) $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    if (getenv('XERIC_REQUIRE_NODE') === '1') ok('node, which XERIC_REQUIRE_NODE=1 says there must be', false);
    else echo "SKIP - splat-test: the Node half did not run (no node on this machine; set XERIC_NODE)\n";
} else {
    echo "\n# the viewer, run\n";
    foreach (['xeric-splat.js', 'xeric-splat-worker.js'] as $js) {
        $out = (string)shell_exec(escapeshellarg($node) . ' --check ' . escapeshellarg(dirname(__DIR__) . '/' . $js) . ' 2>&1');
        ok($js . ' parses', trim($out) === '', trim($out));
    }
    $out = (string)shell_exec('cd ' . escapeshellarg($tmp) . ' && ' . escapeshellarg($node) . ' '
        . escapeshellarg(__DIR__ . '/splat-test.js') . ' '
        . escapeshellarg(dirname(__DIR__) . '/xeric-splat.js') . ' '
        . escapeshellarg(dirname(__DIR__) . '/xeric-splat-worker.js') . ' '
        . escapeshellarg(dirname(__DIR__) . '/xeric-view.js') . ' '
        . escapeshellarg($tmp . '/fixture.splat') . ' ' . escapeshellarg($tmp . '/fixture.ply') . ' 2>&1');
    $ran = 0;
    foreach (explode("\n", $out) as $line) {
        if (!str_starts_with($line, "R\t")) continue;
        $p = explode("\t", $line, 4);
        ok($p[2] ?? '?', ($p[1] ?? '0') === '1', $p[3] ?? '');
        $ran++;
    }
    ok('the harness reported back (' . $ran . ' checks)', $ran >= 50, mb_substr($out, 0, 600));
}

rmtree($tmp);
echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
exit($FAILED === 0 ? 0 : 1);
