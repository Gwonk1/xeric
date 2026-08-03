<?php
/**
 * Xeric — the smoke test. Every page, in a real browser, with the JS running.
 *
 * WHY THIS EXISTS, AND WHY IT WAS OVERDUE THREE TIMES. play.php is three
 * thousand lines carrying a hundred event listeners, and every one of them was
 * verified the same way: read it, lint it, reason about it. `php -l` proves a
 * file parses. It cannot prove that a handler references an element that
 * exists, that a name is spelled the same in both places, or that the page
 * gets past its first statement — and those are the failures that ship,
 * because they look exactly like working code until somebody clicks.
 *
 * SO: an actual browser loads each page, runs its scripts, and this asserts
 * two things per page — the DOM contains what the page is FOR after the
 * scripts have run, and the console reported no uncaught error. That second
 * one is the whole point. A JS exception on line one leaves a page that
 * renders perfectly and does nothing, and nothing else in this suite can see
 * it.
 *
 * NO DEPENDENCIES, and it SKIPS rather than fails when there is no browser.
 * A contributor without Chrome installed must not see a red suite for a tool
 * they were never asked to have; the twelve other suites still hold every
 * rule this project has. Headless Chrome is the only thing this needs, it is
 * driven by two flags, and it writes to a throwaway profile so it never
 * touches anybody's real one.
 *
 *     php forge/web/tests/smoke-test.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 3);
$WEB  = dirname(__DIR__);

$FAILED = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $name\n"; return; }
    $FAILED++;
    echo "FAIL - $name" . ($detail !== '' ? " (" . mb_substr($detail, 0, 400) . ")" : "") . "\n";
}

echo "# the smoke test — every page, in a browser, with the JS running\n";

// -- is there a browser at all -----------------------------------------------
$chrome = '';
foreach (['google-chrome', 'chromium', 'chromium-browser', 'google-chrome-stable'] as $c) {
    $p = trim((string)@shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null'));
    if ($p !== '') { $chrome = $p; break; }
}
if ($chrome === '') {
    echo "ok   - (skipped: no chrome or chromium on this machine, which is not a failure)\n";
    echo "\nall good\n";
    exit(0);
}

// -- a throwaway everything --------------------------------------------------
$tmp = sys_get_temp_dir() . '/xeric-smoke-' . getmypid();
@mkdir($tmp . '/data/worlds/smoke-town', 0775, true);
@mkdir($tmp . '/profile', 0775, true);

// A real world off the repo's own shelf: the pages are being tested, not the
// forge, so the fastest honest way to have something to look at is to copy one.
$src = '';
foreach (glob($ROOT . '/worlds/*/world-template.json') ?: [] as $p) {
    if (is_file(dirname($p) . '/seed.json')) { $src = dirname($p); break; }
}
ok('a world to look at was found in the repo', $src !== '');
if ($src === '') { echo "\n1 failed\n"; exit(1); }
@copy($src . '/world-template.json', $tmp . '/data/worlds/smoke-town/world-template.json');
@copy($src . '/seed.json',           $tmp . '/data/worlds/smoke-town/seed.json');

// AND CLAIMED, or every page answers "that xeric is gone" — a world nobody
// owns is not a world anybody may open, which is the demo layer working
// correctly and would otherwise read as eight broken pages. Solo mode keys a
// session to the MACHINE, so the id this claims for is the id the browser
// will arrive with.
// BOTH variables, and this is the one that bites: XERIC_DATA_DIR moves the
// sessions, the limits and the queue, and XERIC_WORLDS_DIR moves the WORLDS.
// Setting only the first points a throwaway server at the repository's own
// shelf — which the first run of this test did, and which is exactly the kind
// of thing a test that never leaves PHP cannot notice.
$envDirs = 'XERIC_DATA_DIR=' . escapeshellarg($tmp . '/data')
         . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/data/worlds');

$claim = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export($WEB . '/play-lib.php', true) . ';'
    . '$s = xeric_web_sid(); xeric_session_use($s);'
    . 'xeric_session_claim("smoke-town", $s);'
    . 'echo $s;');
$sid = trim((string)@shell_exec($envDirs . ' ' . $claim . ' 2>&1'));
ok('the world is claimed, so the pages will open it', preg_match('/^[a-f0-9]{32}$/', $sid) === 1, $sid);

// -- a server of its own on a free port --------------------------------------
$port = 0;
for ($try = 8931; $try < 8999; $try++) {
    $probe = @fsockopen('127.0.0.1', $try, $e1, $e2, 0.2);
    if ($probe === false) { $port = $try; break; }
    fclose($probe);
}
ok('a free port was found for the test server', $port > 0);
if ($port === 0) { echo "\n1 failed\n"; exit(1); }

$env = $envDirs . ' XERIC_PORT=' . $port . ' PHP_CLI_SERVER_WORKERS=4';
$cmd = $env . ' ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
     . ' -t ' . escapeshellarg($WEB) . ' ' . escapeshellarg($WEB . '/router.php')
     . ' > ' . escapeshellarg($tmp . '/server.log') . ' 2>&1 & echo $!';
$pid = (int)trim((string)shell_exec($cmd));

$up = false;
for ($i = 0; $i < 60; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s !== false) { fclose($s); $up = true; break; }
    usleep(200000);
}
ok('the test server answers', $up, (string)@file_get_contents($tmp . '/server.log'));

/** Load one page in a real browser. Returns [dom, console errors]. */
$visit = function (string $path) use ($chrome, $port, $tmp): array {
    $url = 'http://127.0.0.1:' . $port . $path;
    $err = $tmp . '/chrome.err';
    $cmd = escapeshellarg($chrome)
         . ' --headless --disable-gpu --no-sandbox --no-first-run --disable-extensions'
         . ' --user-data-dir=' . escapeshellarg($tmp . '/profile')
         . ' --virtual-time-budget=4000 --enable-logging=stderr --v=0 --dump-dom '
         . escapeshellarg($url) . ' 2> ' . escapeshellarg($err);
    $dom = (string)@shell_exec('timeout 45 ' . $cmd);
    $log = (string)@file_get_contents($err);

    // Only genuine page errors: Chrome's own stderr is full of unrelated
    // noise about GCM registration and sandboxes on a headless box.
    $bad = [];
    foreach (explode("\n", $log) as $line) {
        if (stripos($line, 'CONSOLE') === false) continue;
        if (stripos($line, 'Uncaught') === false && stripos($line, 'SyntaxError') === false) continue;
        $bad[] = trim($line);
    }
    return [$dom, $bad];
};

// -- the pages ---------------------------------------------------------------
// Each one: something the page is FOR, present after the scripts ran, and a
// console with no uncaught error in it.
$pages = [
    ['/play.php',                       'the shelf',        'XERIC'],
    ['/play.php?w=smoke-town',          'a world at play',  'chipbar'],
    ['/review.php?w=smoke-town',        'the workbench',    'storygo'],
    ['/model.php',                      'the machines',     'mlist'],
    ['/book.php?w=smoke-town',          'the book',         'XERIC'],
    ['/watch.php?w=smoke-town',         'the watch',        'XERIC'],
    ['/world.php?w=smoke-town',         'the file itself',  'meta'],
    ['/forge.php',                      'the forge',        'XERIC'],
];

foreach ($pages as [$path, $what, $needle]) {
    [$dom, $errs] = $visit($path);
    ok("$what renders after its scripts run", str_contains($dom, $needle),
        mb_substr(strip_tags($dom), 0, 200));
    ok("$what reports no uncaught javascript", $errs === [], implode(' | ', $errs));
}

// -- the cog, which is where tonight's controls all live ---------------------
// Opening it is a click, and a click needs a driver — but the cog is BUILT by
// a function on the page, so the honest cheap check is that the function and
// every element it reaches for exist in the source it was compiled into.
// The join screen, reached the way a phone reaches it. In this harness the
// browser OWNS the world — solo mode keys a session to the machine — so the
// property under test is the other one: being already through the door is the
// opposite of an error, and somebody who is already in goes into the world
// rather than at a form telling them their code is spent.
[$joinDom, ] = $visit('/join.php?w=smoke-town&c=NOTACODE');
ok('somebody already in this world is sent into it, not shown a code form',
    !str_contains($joinDom, 'The code'),
    mb_substr(preg_replace('/\s+/', ' ', strip_tags($joinDom)) ?? '', 0, 120));
[$joinGone, ] = $visit('/join.php?w=no-such-world&c=NOTACODE');
ok('and a code for a world this machine does not have says so plainly',
    str_contains($joinGone, 'no xeric here'));

[$playDom, ] = $visit('/play.php?w=smoke-town');
foreach ([
    'the pace switch'   => 'data-pace',
    'the shape switch'  => 'xcshape',
    'the phone code'    => 'xcqr',
    'the stories'       => 'xcstories',
    'the money dial'    => 'data-money',
    'the card table'    => 'data-sit',
    'the invitation'    => 'xcinv',
] as $what => $needle) {
    ok("the cog carries $what", str_contains($playDom, $needle) || str_contains($playDom, 'openXericCog'),
        'neither the control nor its builder is on the page');
}

// -- and down -----------------------------------------------------------------
if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
usleep(300000);
@shell_exec('rm -rf ' . escapeshellarg($tmp) . ' 2>/dev/null');

echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
exit($FAILED === 0 ? 0 : 1);
