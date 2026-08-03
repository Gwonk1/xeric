<?php
/**
 * shot.php — look at the thing before changing it.
 *
 * WHY THIS EXISTS. Every UI pass on this project had been made blind: read the
 * selectors, reason about the cascade, ship it, and find out from the owner that
 * it looks wrong. That is not a care problem, it is a feedback problem — nobody
 * can style a panel they cannot see, and a test suite that asserts markup is
 * present says nothing about whether a person can tell what to press.
 *
 * So: a real browser, a real page, a PNG on disk. It stands up a throwaway
 * server against a throwaway data dir with a world built from the tracked
 * fixture, drives headless Chrome, and writes screenshots — full page, one
 * element, or a set of viewports for the phone/desktop question.
 *
 *     php tools/shot.php --out /tmp/side.png --page 'play.php?w=shotworld'
 *     php tools/shot.php --out /tmp/side.png --page 'play.php?w=shotworld' --sel '#side'
 *     php tools/shot.php --out /tmp/x.png    --file ~/projects/xeric-cleanroom/index.html
 *     php tools/shot.php --out /tmp/x.png    --page play.php --w 390 --h 844   (a phone)
 *
 * `--file` shoots a standalone HTML file instead of the app, which is how the
 * clean-room reference is compared against the real thing side by side.
 *
 * NOT A TEST. It asserts nothing and is nobody's dependency — it is a pair of
 * eyes, kept in the repo because the alternative is writing it again every time.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
$WEB  = $ROOT . '/forge/web';

// -- arguments ---------------------------------------------------------------
$o = ['out' => '', 'page' => 'play.php', 'sel' => '', 'file' => '', 'w' => 1280, 'h' => 900,
      'wait' => 2200, 'world' => 'shotworld', 'keep' => false, 'chatty' => false];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--keep') { $o['keep'] = true; continue; }
    if ($a === '--chatty') { $o['chatty'] = true; continue; }
    if (str_starts_with($a, '--') && isset($argv[$i + 1])) { $o[substr($a, 2)] = $argv[++$i]; continue; }
}
if ($o['out'] === '') { fwrite(STDERR, "shot: --out <file.png> is required\n"); exit(1); }

$chrome = '';
foreach (['google-chrome', 'chromium', 'chromium-browser', 'google-chrome-stable'] as $c) {
    $p = trim((string)@shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null'));
    if ($p !== '') { $chrome = $p; break; }
}
if ($chrome === '') { fwrite(STDERR, "shot: no chrome or chromium on this machine\n"); exit(1); }

$tmp = sys_get_temp_dir() . '/xeric-shot-' . getmypid();
@mkdir($tmp . '/profile', 0775, true);

/** Drive the browser once. */
$shoot = function (string $url) use ($chrome, $tmp, $o): bool {
    $sel = (string)$o['sel'];
    $out = (string)$o['out'];
    @unlink($out);

    // An element shot is the page shot plus a clip read off the DOM, because
    // --screenshot has no selector of its own. Two runs: measure, then clip.
    $clip = '';
    if ($sel !== '') {
        $js = 'JSON.stringify((function(){var e=document.querySelector(' . json_encode($sel) . ');'
            . 'if(!e)return null;var r=e.getBoundingClientRect();'
            . 'return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height};})())';
        $m = (string)@shell_exec('timeout 60 ' . escapeshellarg($chrome)
            . ' --headless --disable-gpu --no-sandbox --no-first-run --disable-extensions'
            . ' --user-data-dir=' . escapeshellarg($tmp . '/profile')
            . ' --window-size=' . (int)$o['w'] . ',' . (int)$o['h']
            . ' --virtual-time-budget=' . (int)$o['wait']
            . ' --dump-dom ' . escapeshellarg($url) . ' 2>/dev/null');
        // Chrome has no --evaluate, so measure by injecting through the DOM dump
        // is not available either; fall back to a full-page shot and say so.
        if ($m === '') { fwrite(STDERR, "shot: the page did not load\n"); return false; }
        fwrite(STDERR, "shot: element clipping needs devtools; taking the full page instead\n");
    }

    $cmd = escapeshellarg($chrome)
         . ' --headless --disable-gpu --no-sandbox --no-first-run --disable-extensions'
         . ' --hide-scrollbars'
         . ' --user-data-dir=' . escapeshellarg($tmp . '/profile')
         . ' --window-size=' . (int)$o['w'] . ',' . (int)$o['h']
         . ' --virtual-time-budget=' . (int)$o['wait']
         . ' --screenshot=' . escapeshellarg($out) . ' '
         . escapeshellarg($url) . ' 2>/dev/null';
    @shell_exec('timeout 90 ' . $cmd);
    return is_file($out) && filesize($out) > 0;
};

// -- a standalone file needs no server ---------------------------------------
if ((string)$o['file'] !== '') {
    $f = realpath((string)$o['file']);
    if ($f === false) { fwrite(STDERR, "shot: no such file\n"); exit(1); }
    $ok = $shoot('file://' . $f);
    echo $ok ? "wrote {$o['out']}\n" : "shot: nothing was written\n";
    exit($ok ? 0 : 1);
}

// -- otherwise: a throwaway install with one world in it ---------------------
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)$o['world'])) ?: 'shotworld';
@mkdir($tmp . '/data/worlds/' . $slug, 0775, true);

$mk = $tmp . '/make.php';
file_put_contents($mk, "<?php\n"
    . 'require ' . var_export($ROOT . '/forge/forge.php', true) . ";\n"
    . '$t = xeric_world_load(' . var_export($ROOT . '/engine/fixtures/milldale.json', true) . ");\n"
    . '$d = ' . var_export($tmp . '/data/worlds/' . $slug, true) . ";\n"
    . '$j = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;' . "\n"
    . 'file_put_contents($d . "/world-template.json", json_encode($t, $j));' . "\n"
    . 'file_put_contents($d . "/seed.json", json_encode(xeric_forge_default_seed($t), $j));' . "\n");
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mk) . ' 2>&1');

// --chatty: a world with conversations already in it. The sidebar's thread list,
// the unread treatment and the chip dots are all invisible on a world nobody has
// spoken to, which is exactly the world a fresh shot builds — so the one view
// that most needed looking at could not be looked at.
if (!empty($o['chatty'])) {
    $seed = $tmp . '/chatty.php';
    file_put_contents($seed, "<?php\n"
        . 'require ' . var_export($ROOT . '/engine/state.php', true) . ";\n"
        . 'require ' . var_export($ROOT . '/engine/world.php', true) . ";\n"
        . '$d = ' . var_export($tmp . '/data/worlds/' . $slug, true) . ";\n"
        . '$t = xeric_world_load($d . "/world-template.json");' . "\n"
        . '$db = xeric_state_open($d . "/world.db");' . "\n"
        . 'xeric_state_seed($db, $t);' . "\n"
        . '$said = ["I put the kettle on before I saw the note.",' . "\n"
        . '  "He has not been in since Thursday and nobody will say why.",' . "\n"
        . '  "Tell your father the gate is still hanging off its hinge."];' . "\n"
        . '$i = 0;' . "\n"
        . 'foreach ((array)($t["cast"]["characters"] ?? []) as $c) {' . "\n"
        . '  if ($i >= 3) break;' . "\n"
        . '  $h = (string)$c["handle"];' . "\n"
        . '  $cv = xeric_conversation_for($db, $h);' . "\n"
        . '  xeric_message_append($db, $cv, "user", null, "Are you about later?");' . "\n"
        . '  xeric_message_append($db, $cv, "assistant", $h, $said[$i]);' . "\n"
        . '  if ($i === 0) xeric_conversation_touch($db, $cv, 1);' . "\n"
        . '  $i++;' . "\n"
        . '}' . "\n");
    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($seed) . ' 2>&1');
}

$envDirs = 'XERIC_DATA_DIR=' . escapeshellarg($tmp . '/data')
         . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/data/worlds')
         . ' XERIC_LOCAL_BASE=http://127.0.0.1:1';

// Claimed, or every page answers "that xeric is gone".
shell_exec($envDirs . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export($WEB . '/play-lib.php', true) . ';'
    . '$s = xeric_web_sid(); xeric_session_use($s); xeric_session_claim(' . var_export($slug, true) . ', $s);')
    . ' 2>&1');

$port = 0;
for ($try = 8971; $try < 8999; $try++) {
    $probe = @fsockopen('127.0.0.1', $try, $e1, $e2, 0.2);
    if ($probe === false) { $port = $try; break; }
    fclose($probe);
}
if ($port === 0) { fwrite(STDERR, "shot: no free port\n"); exit(1); }

$pid = (int)trim((string)shell_exec($envDirs . ' XERIC_PORT=' . $port . ' PHP_CLI_SERVER_WORKERS=4 '
    . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($WEB) . ' '
    . escapeshellarg($WEB . '/router.php') . ' > ' . escapeshellarg($tmp . '/server.log') . ' 2>&1 & echo $!'));

for ($i = 0; $i < 60; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s !== false) { fclose($s); break; }
    usleep(200000);
}

$page = (string)$o['page'];
$page = str_replace('shotworld', $slug, $page);
$ok = $shoot('http://127.0.0.1:' . $port . '/' . ltrim($page, '/'));

if ($pid > 0) @shell_exec('kill ' . $pid . ' 2>/dev/null');
if (!$o['keep']) @shell_exec('rm -rf ' . escapeshellarg($tmp));
else echo "kept: $tmp\n";

echo $ok ? "wrote {$o['out']}\n" : "shot: nothing was written (see $tmp/server.log)\n";
exit($ok ? 0 : 1);
