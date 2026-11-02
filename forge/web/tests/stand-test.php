<?php
/**
 * Xeric — the stand page. `php forge/web/tests/stand-test.php`, exit 0 on pass.
 *
 * stand.php is a SHELL: it must carry no world (so its service worker can keep
 * it whatever the nonce), it must be the one page whose policy lets it run a
 * worker, name a manifest and ask for the headset, and everything it does it
 * must do through api.php/v1. What is defended, over a real `php -S` (headers
 * do not exist under the CLI SAPI) serving a real world:
 *
 *   1. The page renders, every <script> carries the response's nonce, the
 *      inline one parses, and the viewer is loaded by its content hash.
 *   2. No world data in the HTML: not the slug, not the place, not a name —
 *      and the page for one world is byte-for-byte the page for another once
 *      the nonce is taken out. A source check holds the same line on the file.
 *   3. The headers: play's policy plus worker-src and manifest-src 'self'; the
 *      Permissions-Policy that grants microphone and xr-spatial-tracking —
 *      on this page, and not on the others.
 *   4. The manifest and the service worker are served with the right types,
 *      the worker is registered scoped to stand.php, and the icon is a PNG of
 *      the size the manifest says.
 *   5. stand-test.js runs the viewer's stand parts (tags at bearings, the ray,
 *      the hold's target, the panner, offline, step inside with and without
 *      layers) and the service worker itself, against fakes.
 *
 * NEEDS NODE for 1's parse and for 5, and SKIPS those (said as SKIP) without it.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-stand-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');

require_once __DIR__ . '/../play-lib.php';

$FAILED = 0;
$WEB = dirname(__DIR__);
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $name\n"; return; }
    $FAILED++;
    echo "FAIL - $name" . ($detail !== '' ? " (" . mb_substr($detail, 0, 400) . ")" : '') . "\n";
}
function rmtree(string $d): void
{
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $p) {
        if (in_array(basename($p), ['.', '..'], true)) continue;
        is_dir($p) ? rmtree($p) : @unlink($p);
    }
    @rmdir($d);
}

echo "# the stand page\n";

// -- a world, so there is something the page must NOT print ---------------------
$slug = 'stand-town';
$t = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$d = xeric_web_worlds_dir() . '/' . $slug;
@mkdir($d, 0775, true);
$j = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents($d . '/world-template.json', json_encode($t, $j));
file_put_contents($d . '/seed.json', json_encode(xeric_forge_default_seed($t), $j));
$sid = xeric_web_sid();                               // solo: the id the server will key this machine to
xeric_session_use($sid);
xeric_session_claim($slug, $sid);
$w = xeric_play_open($slug, null, true);
xeric_player_move($w['db'], 'bluebird', xeric_clock_epoch($w['db']));

// -- a server ------------------------------------------------------------------
$port = 0;
for ($try = 9031; $try < 9099; $try++) {
    $s = @fsockopen('127.0.0.1', $try, $e1, $e2, 0.2);
    if ($s === false) { $port = $try; break; }
    fclose($s);
}
ok('a free port', $port > 0);
$env = 'XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
     . ' XERIC_LOCAL_BASE=http://127.0.0.1:1 XERIC_PORT=' . $port . ' PHP_CLI_SERVER_WORKERS=2';
$pid = (int)trim((string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($WEB) . ' ' . escapeshellarg($WEB . '/router.php')
    . ' > ' . escapeshellarg($tmp . '/server.log') . ' 2>&1 & echo $!'));
$up = false;
for ($i = 0; $i < 60 && !$up; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s !== false) { fclose($s); $up = true; } else usleep(150000);
}
ok('the server answers', $up, (string)@file_get_contents($tmp . '/server.log'));

/** [status, headers (lower-case name => value), body] */
$get = function (string $path) use ($port): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20,
        'header' => "Host: 127.0.0.1:$port\r\n"]]);
    $body = (string)@file_get_contents('http://127.0.0.1:' . $port . $path, false, $ctx);
    $hs = []; $st = 0;
    foreach ($http_response_header ?? [] as $l) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $l, $m)) { $st = (int)$m[1]; $hs = []; continue; }
        $p = strpos($l, ':');
        if ($p !== false) $hs[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
    }
    return [$st, $hs, $body];
};

$node = (string)getenv('XERIC_NODE');
if ($node === '' || !is_executable($node)) $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '' && getenv('XERIC_REQUIRE_NODE') === '1') { ok('node is here (XERIC_REQUIRE_NODE=1)', false); }

// == 1. the page ===================================================================
[$st, $hs, $html] = $get('/stand.php?w=' . $slug);
ok('stand.php renders', $st === 200 && str_contains($html, 'id="stand"') && str_starts_with($hs['content-type'] ?? '', 'text/html'),
    $st . ' ' . mb_substr($html, 0, 200));
$csp = $hs['content-security-policy'] ?? '';
preg_match("/'nonce-([^']+)'/", $csp, $nm);
$nonce = $nm[1] ?? '';
preg_match_all('#<script\b([^>]*)>(.*?)</script>#s', $html, $sm, PREG_SET_ORDER);
ok('every <script> carries this response\'s nonce', $nonce !== '' && count($sm) >= 3
    && array_filter($sm, fn($x) => !str_contains($x[1], 'nonce="' . $nonce . '"')) === [], count($sm) . ' scripts');
$hash = substr((string)hash_file('sha256', $WEB . '/xeric-view.js'), 0, 16);
ok('the viewer is xeric-view.js by its content hash, before the page\'s own script',
    str_contains($html, 'src="xeric-view.js?v=' . $hash . '"') && strpos($html, 'xeric-view.js?v=') < strpos($html, 'XericView({ world: W })'));
if ($node !== '') {
    $bad = [];
    foreach ($sm as $i => $x) {
        if (trim($x[2]) === '') continue;
        $f = $tmp . '/block-' . $i . '.js';
        file_put_contents($f, $x[2]);
        $out = trim((string)shell_exec(escapeshellarg($node) . ' --check ' . escapeshellarg($f) . ' 2>&1'));
        if ($out !== '') $bad[] = $out;
    }
    ok('and every inline block parses', $bad === [], implode(' | ', $bad));
} else {
    echo "SKIP - the inline blocks' parse (no node)\n";
}

// == 2. no world in it =================================================================
$names = ['stand-town', 'Milldale', 'Bluebird', 'Dot Vance', 'Ruth Amberg', 'bluebird'];
$found = array_values(array_filter($names, fn($n) => str_contains($html, $n)));
ok('no world data in the HTML: not the slug, the town, the place or a person', $found === [], implode(', ', $found));
[, , $other] = $get('/stand.php?w=another-world');
[, , $bare]  = $get('/stand.php');
$strip = fn(string $h): string => preg_replace('/nonce="[^"]*"/', '', $h) ?? $h;
ok('the page for one world is the page for any other (and for none), nonce aside',
    $strip($html) === $strip($other) && $strip($html) === $strip($bare));
$src = (string)file_get_contents($WEB . '/stand.php');
$page = substr($src, (int)strpos($src, "xeric_web_head('Xeric: stand'"));
preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $page, $echoes);
$allowed = ['h(xeric_web_nonce())', "h(xeric_play_asset('xeric-view.js'))"];
ok('source: the page prints nothing but its nonce and its script\'s address',
    array_diff($echoes[1], $allowed) === [] && count($echoes[1]) >= 3, implode(' | ', array_diff($echoes[1], $allowed)));
$forbidden = ['xeric_play_open', 'xeric_world_load', 'xeric_play_state', 'xeric_session_id', 'xeric_play_look',
              'xeric_play_thread', '$_SESSION', '$_COOKIE', 'world.db', 'xeric_scene_brief'];
$hits = array_values(array_filter($forbidden, fn($f) => str_contains($src, $f)));
ok('source: stand.php never opens a world, a session or a database', $hits === [], implode(', ', $hits));
ok('source: the only PHP after the manifest is the head, so nothing else can print',
    substr_count($page, '<?php') === 0);
ok('source: the page asks the API for everything', str_contains($src, "var API = 'api.php/v1/'")
    && str_contains($src, "WP + 'pulse'") && str_contains($src, "WP + 'look'") && str_contains($src, "'/read'")
    && str_contains($src, "WP + 'hear'") && str_contains($src, "'/say'") && str_contains($src, "'room?after='")
    && str_contains($src, "'/audio'") && str_contains($src, "API + 'link'"));
ok('source: the pulse is conditional, and a 304 is honoured', str_contains($src, "h['If-None-Match']")
    && str_contains($src, 'r.status === 304'));
ok('source: the token is stored only on a secure context or loopback, and the page says why',
    str_contains($src, 'var KEEP = SECURE || LOOP;') && str_contains($src, 'if (KEEP) { try { localStorage.setItem(TK, token)')
    && str_contains($src, 'kept in memory only'));
ok('source: off a secure context the banner names the no-TLS setups and tailscale, and hides the verbs',
    str_contains($src, "if (!SECURE && !LOOP) {") && str_contains($src, 'adb reverse tcp:') && str_contains($src, 'Quest Link')
    && str_contains($src, 'tailscale serve --bg') && str_contains($src, 'body.insecure #talk{display:none}')
    && str_contains($src, 'body.insecure #view .lookxr'));
ok('source: the microphone is asked for on the 2D page, kept, and switched by track.enabled',
    str_contains($src, 'getUserMedia({ audio: true })') && substr_count($src, 'getUserMedia(') === 1
    && str_contains($src, 't.enabled = true') && str_contains($src, 't.enabled = false'));
ok('source: heard, then shown 1.5 s with a cancel, then said by the page',
    str_contains($src, "caption('“' + text + '”', 1500, true, b)") && str_contains($src, 'said(target, text, b)'));
ok('source: one AudioContext; decodeAudioData and createImageBitmap, never a blob: URL',
    substr_count($src, 'new AC()') === 1 && str_contains($src, 'decodeAudioData') && str_contains($src, 'createImageBitmap(b)')
    && !str_contains($src, 'createObjectURL'));

// == 3. the headers ======================================================================
ok('CSP: play\'s policy, plus worker-src and manifest-src \'self\'',
    str_contains($csp, "default-src 'none'") && str_contains($csp, "connect-src 'self'") && str_contains($csp, "worker-src 'self'")
    && str_contains($csp, "manifest-src 'self'") && !str_contains($csp, 'blob:') && !str_contains($csp, 'unsafe-eval'), $csp);
ok('Permissions-Policy on stand: microphone and the headset, no camera',
    ($hs['permissions-policy'] ?? '') === 'microphone=(self), xr-spatial-tracking=(self), camera=()', $hs['permissions-policy'] ?? '');
[, $lh] = $get('/link.php');
ok('and not on another page: no microphone, no manifest',
    ($lh['permissions-policy'] ?? '') === 'microphone=(), camera=()'
    && !str_contains($lh['content-security-policy'] ?? '', 'manifest-src'), ($lh['permissions-policy'] ?? '') . ' / ' . ($lh['content-security-policy'] ?? ''));

// == 4. the manifest, the worker, the icon ===================================================
[$st, $mh, $mb] = $get('/stand.php?manifest&w=' . $slug);
$man = json_decode($mb, true);
ok('the manifest is served as application/manifest+json', $st === 200 && str_starts_with($mh['content-type'] ?? '', 'application/manifest+json'),
    ($mh['content-type'] ?? '') . ' ' . $st);
ok('with a name, standalone, start_url opening this world, scoped to the page',
    is_array($man) && ($man['name'] ?? '') !== '' && ($man['display'] ?? '') === 'standalone'
    && ($man['start_url'] ?? '') === 'stand.php?w=' . $slug && ($man['scope'] ?? '') === 'stand.php', $mb);
$icon = $man['icons'][0] ?? [];
[$st, $ih, $ib] = $get('/' . ($icon['src'] ?? 'x'));
$sz = @getimagesizefromstring($ib) ?: [0, 0];
ok('its icon is stand-icon.png, a PNG of the size it claims', ($icon['src'] ?? '') === 'stand-icon.png' && $st === 200
    && str_starts_with($ib, "\x89PNG\r\n\x1a\n") && ($icon['sizes'] ?? '') === $sz[0] . 'x' . $sz[1] && ($icon['type'] ?? '') === 'image/png');
[$st, , $mb2] = $get('/stand.php?manifest&w=' . rawurlencode('../<x>'));
ok('a manifest for a strange slug is a slug, not markup', $st === 200 && !str_contains($mb2, '<x>') && !str_contains($mb2, '..'));

[$st, $wh, $sw] = $get('/stand-sw.php');
ok('the service worker is served as JavaScript, never cached long', $st === 200
    && str_starts_with($wh['content-type'] ?? '', 'text/javascript') && ($wh['cache-control'] ?? '') === 'no-cache', ($wh['content-type'] ?? ''));
ok('and keeps the viewer by the same hash the page loads', str_contains($sw, 'xeric-view.js?v=' . $hash));
ok('the page registers it scoped to stand.php only', str_contains($src, "register('stand-sw.php', { scope: 'stand.php' })")
    && str_contains($src, "l.href = 'stand.php?manifest'"));
file_put_contents($tmp . '/sw.js', $sw);
if ($node !== '') {
    $out = trim((string)shell_exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp . '/sw.js') . ' 2>&1'));
    ok('and it parses', $out === '', $out);
}

// == 5. run it ================================================================================
if ($node === '') {
    echo "SKIP - stand-test.js: 0 of its checks ran (no node; set XERIC_NODE)\n";
} else {
    file_put_contents($tmp . '/viewer.js', (string)file_get_contents($WEB . '/xeric-view.js'));
    $out = (string)shell_exec('cd ' . escapeshellarg($tmp) . ' && ' . escapeshellarg($node) . ' '
        . escapeshellarg(__DIR__ . '/stand-test.js') . ' ' . escapeshellarg($tmp . '/viewer.js') . ' '
        . escapeshellarg($tmp . '/sw.js') . ' 2>&1');
    $ran = 0;
    foreach (explode("\n", $out) as $line) {
        if (!str_starts_with($line, "R\t")) continue;
        $p = explode("\t", $line, 4);
        ok($p[2] ?? '?', ($p[1] ?? '0') === '1', $p[3] ?? '');
        $ran++;
    }
    ok('the harness reported back (' . $ran . ' checks)', $ran >= 60, mb_substr($out, 0, 600));
}

if ($pid > 0) @shell_exec('kill ' . $pid . ' 2>/dev/null');
usleep(200000);
rmtree($tmp);
echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
exit($FAILED === 0 ? 0 : 1);
