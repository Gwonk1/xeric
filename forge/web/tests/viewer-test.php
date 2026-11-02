<?php
/**
 * Xeric — the viewer's JavaScript, run. `php forge/web/tests/viewer-test.php`,
 * exit 0 on pass.
 *
 * The look-around (WebGL panorama, the 2D strip under it, the plain image under
 * that), the people's tags at their bearings, the headset button, the phone,
 * the day strip and the speaker live in xeric-view.js, one static file that
 * play.php loads with its nonce. scene-web-test.php and voice-web-test.php
 * prove what the SERVER hands over and grep the source for shapes; this runs
 * the code.
 *
 * HOW: play.php is rendered for a real world (the tracked fixture), exactly as
 * a browser would receive it. Every <script> must carry the nonce and every
 * inline one must parse; the page must load xeric-view.js by its content hash.
 * That file — the served bytes, not a copy — is checked for the viewer's
 * functions BY NAME, brace-matched, and evaluated whole in viewer-test.js
 * beside a fake DOM, WebGL, 2D canvas, WebXR and DeviceOrientationEvent that
 * record what was asked of them. Change the viewer and this runs the change.
 *
 * NEEDS NODE, and SKIPS (said as SKIP, exit 0) when there is none, the smoke
 * test's rule. Nothing is installed; any `node` on PATH, or XERIC_NODE.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-viewer-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called

$FAILED = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $name\n"; return; }
    $FAILED++;
    echo "FAIL - $name" . ($detail !== '' ? " (" . mb_substr($detail, 0, 400) . ")" : '') . "\n";
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
function done(string $tmp): never
{
    global $FAILED;
    rmtree($tmp);
    echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
    exit($FAILED === 0 ? 0 : 1);
}

echo "# the viewer, run\n";

$node = (string)getenv('XERIC_NODE');
if ($node === '' || !is_executable($node)) {
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
}
if ($node === '' && getenv('XERIC_REQUIRE_NODE') === '1') {
    echo "FAIL - viewer-test: no node, and XERIC_REQUIRE_NODE=1 says there must be one\n\n1 failed\n";
    rmtree($tmp);
    exit(1);
}
if ($node === '') {
    echo "SKIP - viewer-test: 0 of its checks ran (no node on this machine; set XERIC_NODE to point at one)\n";
    echo "\nskipped\n";
    rmtree($tmp);
    exit(0);
}

require_once __DIR__ . '/../play-lib.php';

// -- the page, as served -----------------------------------------------------
$slug = 'viewer-town';
$t = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$d = xeric_web_worlds_dir() . '/' . $slug;
@mkdir($d, 0775, true);
$j = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents($d . '/world-template.json', json_encode($t, $j));
file_put_contents($d . '/seed.json', json_encode(xeric_forge_default_seed($t), $j));
$sid = bin2hex(random_bytes(16));
xeric_session_use($sid);
xeric_session_claim($slug, $sid);
xeric_play_open($slug);

// In a process of its own: the page exits when it is done.
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

preg_match_all('#<script\b([^>]*)>(.*?)</script>#s', $html, $m, PREG_SET_ORDER);
ok('play.php renders, with its inline scripts', count($m) >= 1, mb_substr(strip_tags($html), 0, 300));
if ($m === []) done($tmp);
ok('every inline script carries the page\'s nonce',
    array_filter($m, fn($s) => !preg_match('/\bnonce="[^"]+"/', $s[1])) === []);

$bad = [];
foreach ($m as $i => $s) {
    $f = $tmp . '/block-' . $i . '.js';
    file_put_contents($f, $s[2]);
    $out = (string)shell_exec(escapeshellarg($node) . ' --check ' . escapeshellarg($f) . ' 2>&1');
    if (trim($out) !== '') $bad[] = "block $i: " . trim($out);
}
ok('and every one of them parses (' . count($m) . ' blocks)', $bad === [], implode(' | ', $bad));

// -- the viewer, cut out by name -------------------------------------------------
/**
 * `function NAME(...) { ... }` from $src, brace-matched. Strings and comments
 * are stepped over so a `{` in a shader string or an apostrophe in a comment
 * does not miscount. Null when the function is not there.
 */
function js_function(string $src, string $name): ?string
{
    if (!preg_match('/\bfunction ' . preg_quote($name, '/') . '\s*\(/', $src, $mm, PREG_OFFSET_CAPTURE)) return null;
    $start = $mm[0][1];
    $i = strpos($src, '{', $start);
    if ($i === false) return null;
    $depth = 0;
    $n = strlen($src);
    for (; $i < $n; $i++) {
        $c = $src[$i];
        if ($c === '/' && ($src[$i + 1] ?? '') === '/') { $e = strpos($src, "\n", $i); $i = $e === false ? $n : $e; continue; }
        if ($c === '/' && ($src[$i + 1] ?? '') === '*') { $e = strpos($src, '*/', $i + 2); $i = $e === false ? $n : $e + 1; continue; }
        if ($c === '"' || $c === "'" || $c === '`') {
            for ($i++; $i < $n && $src[$i] !== $c; $i++) if ($src[$i] === '\\') $i++;
            continue;
        }
        if ($c === '{') $depth++;
        if ($c === '}' && --$depth === 0) return substr($src, $start, $i - $start + 1);
    }
    return null;
}

$viewFile = dirname(__DIR__) . '/xeric-view.js';
$main = (string)@file_get_contents($viewFile);
$hash = substr((string)@hash_file('sha256', $viewFile), 0, 16);
$src  = array_values(array_filter($m, fn($s) => preg_match('/\bsrc="xeric-view\.js\?v=([0-9a-f]{16})"/', $s[1], $mm) === 1
    && $mm[1] === $hash && trim($s[2]) === ''));
ok('the viewer is the static file the page loads, by its hash, with the nonce, before the page\'s own script',
    $main !== '' && str_contains($main, 'function pano(') && count($src) === 1
    && strpos($html, 'src="xeric-view.js') < strpos($html, 'XericView({ world: W })'));
$out = (string)shell_exec(escapeshellarg($node) . ' --check ' . escapeshellarg($viewFile) . ' 2>&1');
ok('and it parses', trim($out) === '', trim($out));

$want = ['pano', 'xr', 'xrRay', 'mat4inv', 'panoProgram', 'gaze', 'phoneGaze', 'dayView', 'speak', 'unspeak',
         'refuseSpeak', 'hold'];
$missing = [];
foreach ($want as $fn) if (js_function($main, $fn) === null) $missing[] = $fn;
ok('each of the viewer\'s functions is found: ' . implode(', ', $want), $missing === [], 'missing: ' . implode(', ', $missing));
file_put_contents($tmp . '/viewer.js', $main);

// -- run it ---------------------------------------------------------------------
$out = (string)shell_exec('cd ' . escapeshellarg($tmp) . ' && ' . escapeshellarg($node) . ' '
    . escapeshellarg(__DIR__ . '/viewer-test.js') . ' ' . escapeshellarg($tmp . '/viewer.js') . ' 2>&1');
$ran = 0;
foreach (explode("\n", $out) as $line) {
    if (!str_starts_with($line, "R\t")) continue;
    $p = explode("\t", $line, 4);
    ok($p[2] ?? '?', ($p[1] ?? '0') === '1', $p[3] ?? '');
    $ran++;
}
ok('the harness reported back (' . $ran . ' checks)', $ran >= 90, mb_substr($out, 0, 600));

done($tmp);
