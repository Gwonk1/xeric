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
// XERIC_CHROME names one explicitly. Otherwise chrome-headless-shell is tried
// FIRST: it is the headless-only build, it needs no D-Bus and no zygote, and
// in a sandbox without a session bus it is the one that answers — full Chrome
// there starts, logs D-Bus errors and never dumps the DOM (every visit times
// out), whatever flags it is given.
$chrome = '';
$named = (string)getenv('XERIC_CHROME');
if ($named !== '' && is_executable($named)) $chrome = $named;
foreach (['chrome-headless-shell', 'google-chrome', 'chromium', 'chromium-browser', 'google-chrome-stable', 'chrome'] as $c) {
    if ($chrome !== '') break;
    $p = trim((string)@shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null'));
    if ($p !== '') { $chrome = $p; break; }
}
if ($chrome === '') {
    // A SKIP, SAID AS ONE. This used to print "ok - (skipped ...)", which a CI
    // log counts as a pass: the suite went green on a machine that never ran
    // it. The SKIP line is what a reader (and the CI grep) looks for.
    // XERIC_REQUIRE_BROWSER=1 (CI sets it) turns the skip into a failure, so
    // a runner that lost its browser goes red instead of quietly green.
    if (getenv('XERIC_REQUIRE_BROWSER') === '1') {
        echo "FAIL - smoke-test: no browser, and XERIC_REQUIRE_BROWSER=1 says there must be one\n\n1 failed\n";
        exit(1);
    }
    echo "SKIP - smoke-test: 0 of its checks ran (no chrome, chromium or chrome-headless-shell; set XERIC_CHROME to point at one)\n";
    echo "\nskipped\n";
    exit(0);
}
echo "# browser: $chrome\n";

// CAN IT DUMP A PAGE AT ALL. One trivial page first, with a short fuse: a
// browser that starts and never answers (full Chrome in a container with no
// D-Bus does exactly that, whatever flags it is given) would otherwise time
// out on every page below, one after another, for minutes — and then read as
// twenty broken pages instead of one missing piece of plumbing.
$probeDir = sys_get_temp_dir() . '/xeric-smoke-probe-' . getmypid();
@mkdir($probeDir, 0775, true);
$probe = (string)@shell_exec('timeout 20 ' . escapeshellarg($chrome)
    . ' --headless=new --disable-gpu --no-sandbox --disable-dbus --disable-dev-shm-usage --no-first-run'
    . ' --user-data-dir=' . escapeshellarg($probeDir)
    . ' --dump-dom ' . escapeshellarg('data:text/html,<p>x</p><script>document.body.append("ran")</script>')
    . ' 2>/dev/null');
@shell_exec('rm -rf ' . escapeshellarg($probeDir));
if (!str_contains($probe, '</script>ran')) {           // the text node the script added, not its source
    echo "FAIL - the browser can load a trivial page and run its script (it did not answer in 20 s: "
       . "headless Chrome without a session bus hangs like this; point XERIC_CHROME at chrome-headless-shell)\n";
    echo "\n1 failed\n";
    exit(1);
}
ok('the browser loads a trivial page and runs its script', true);

// -- a throwaway everything --------------------------------------------------
$tmp = sys_get_temp_dir() . '/xeric-smoke-' . getmypid();
@mkdir($tmp . '/data/worlds/smoke-town', 0775, true);
@mkdir($tmp . '/profile', 0775, true);

// A REAL WORLD, BUILT FROM THE TRACKED FIXTURE. The pages are being tested, not
// the forge, so this needs something real to look at — and it used to take
// whatever the developer running the suite happened to have forged in
// `<repo>/worlds/`. That is a coincidence, not a fixture: it differs per
// machine, it is absent on a fresh clone, and it assumed xerics live inside the
// checkout, which they do not (see xeric_web_worlds_default). Generated in a
// subprocess so this file stays a browser driver and loads no app code.
$mkPath = $tmp . '/make-world.php';
file_put_contents($mkPath, "<?php\n"
    . 'require ' . var_export($ROOT . '/forge/forge.php', true) . ";\n"
    . '$t = xeric_world_load(' . var_export($ROOT . '/engine/fixtures/milldale.json', true) . ");\n"
    . '$d = ' . var_export($tmp . '/data/worlds/smoke-town', true) . ";\n"
    . '$j = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;' . "\n"
    . 'file_put_contents($d . "/world-template.json", json_encode($t, $j));' . "\n"
    . 'file_put_contents($d . "/seed.json", json_encode(xeric_forge_default_seed($t), $j));' . "\n");
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mkPath) . ' 2>&1');
ok('a world to look at was built from the tracked fixture',
    is_file($tmp . '/data/worlds/smoke-town/world-template.json')
    && is_file($tmp . '/data/worlds/smoke-town/seed.json'));
if (!is_file($tmp . '/data/worlds/smoke-town/seed.json')) { echo "\n1 failed\n"; exit(1); }

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
    // AND A MACHINE, or /forge.php below is not the forge at all. With nothing
    // attached that page redirects to the machines screen, and the needle it was
    // checked against — XERIC — is on both, so the forge landing page has been
    // passing this suite without ever being rendered by it.
    . 'xeric_web_session_edit(function (array &$x): void {'
    . ' $x["model"] = ["kind" => "local", "base" => "", "model" => ""]; }, $s);'
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
         // --headless=new is ignored by chrome-headless-shell and selects the
         // modern headless mode in full Chrome; --disable-dbus and
         // --disable-dev-shm-usage are for containers with no session bus and
         // a tiny /dev/shm (CI runners, sandboxes).
         . ' --headless=new --disable-gpu --no-sandbox --disable-dbus --disable-dev-shm-usage'
         . ' --no-first-run --disable-extensions'
         . ' --user-data-dir=' . escapeshellarg($tmp . '/profile')
         . ' --virtual-time-budget=8000 --enable-logging=stderr --v=0 --dump-dom '
         . escapeshellarg($url) . ' 2> ' . escapeshellarg($err);
    $dom = (string)@shell_exec('timeout 45 ' . $cmd);
    $log = (string)@file_get_contents($err);

    // Only genuine page errors: Chrome's own stderr is full of unrelated
    // noise about GCM registration and sandboxes on a headless box.
    $bad = [];
    foreach (explode("\n", $log) as $line) {
        if (stripos($line, 'CONSOLE') === false) continue;
        // A REFUSED SCRIPT IS A PAGE ERROR. The Content-Security-Policy grants
        // no 'unsafe-inline', so every inline block in the app carries a nonce —
        // and a block that is added without one does not throw, it simply never
        // runs. Chrome says "Refused to execute inline script" and nothing else
        // does, so this line is the only thing standing between a missed nonce
        // and a page that is quietly half dead.
        if (stripos($line, 'Refused to') !== false || stripos($line, 'Content Security Policy') !== false) {
            $bad[] = trim($line);
            continue;
        }
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
    ['/forge.php',                      'the forge',        'data-way'],
    // data-stand is set by the page's own script, so this one proves it ran
    // (and the service worker it registers is served and parses).
    ['/stand.php?w=smoke-town',         'the stand page',   'data-stand="ready"'],
];

// AND ONE PAGE PROVED TO HAVE ACTUALLY RUN ITS SCRIPTS.
//
// The needles above are STATIC markup — `chipbar`, `storygo`, `mlist` are in the
// served HTML whether the JS ran or not — so those assertions prove the page
// rendered, never that it works. An audit demonstrated the gap: a world named
// `Mill <!--<script>` drove the HTML tokenizer into script-data-double-escaped
// state, swallowed the rest of the document into script text, and left the play
// view's entire client side dead WITH NO CONSOLE ERROR. Every assertion here
// passed.
//
// `#chips` is empty in the served bytes and is filled by paintChips(). A button
// inside it is a thing only running JavaScript can have put there.
[$chipDom, $chipErrs] = $visit('/play.php?w=smoke-town');
ok('the play view actually RAN its scripts, not merely rendered them',
    preg_match('/id="chips"[^>]*>\s*<button/', $chipDom) === 1,
    'chips: ' . mb_substr((string)(preg_split('/id="chips"/', $chipDom)[1] ?? '(no #chips)'), 0, 160));
ok('and it did so with nothing refused by the content policy',
    $chipErrs === [], implode(' | ', $chipErrs));

// -- the forge's own front door ----------------------------------------------
//
// Checked here because it is the screen every world starts on and the one the
// owner keeps finding wrong. Three facts, all of them things a careless edit
// silently undoes: which box comes first, that Auto Generate's one question is
// on the page at all, and that the ratings needing eighteen are marked as such
// in the served bytes rather than only in a stylesheet.
(function () use ($visit): void {
    [$fdom, ] = $visit('/forge.php');
    $own  = strpos($fdom, 'data-way="own"');
    $auto = strpos($fdom, 'data-way="auto"');
    ok('the forge leads with Your Own Idea, not with Auto Generate',
        $own !== false && $auto !== false && $own < $auto,
        'own at ' . var_export($own, true) . ', auto at ' . var_export($auto, true));
    ok('and says so on the tile rather than only in a tooltip',
        str_contains($fdom, 'class="wrec"'));
    ok('Auto Generate has a door to ask its one question behind',
        str_contains($fdom, 'id="autowrap"') && str_contains($fdom, 'id="autogo"'));
    ok('the rating choices are in that door, not loose under the tiles',
        str_contains($fdom, 'rinline') && !str_contains($fdom, 'class="waysrate"'));
    ok('and the ones needing eighteen are marked as gated in the markup',
        substr_count($fdom, 'data-gated="1"') >= 1
        && str_contains($fdom, 'class="rate-affirm"'),
        substr_count($fdom, 'data-gated="1"') . ' gated');
})();

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
// Anchored to the FORM, not to prose: the first version of this matched the
// words "The code", which also appear in a source comment on the page it
// redirects to, so it failed on a page that was behaving perfectly.
ok('somebody already in this world is sent into it, not shown a code form',
    !str_contains($joinDom, 'class="joinform"'),
    mb_substr(preg_replace('/\s+/', ' ', strip_tags($joinDom)) ?? '', 0, 120));
[$joinGone, ] = $visit('/join.php?w=no-such-world&c=NOTACODE');
ok('and a code for a world this machine does not have says so plainly',
    str_contains($joinGone, 'no xeric here'));

[$playDom, ] = $visit('/play.php?w=smoke-town');
ok('the sidebar carries the way in', str_contains($playDom, 'data-invite')
    || str_contains($playDom, 'let somebody in'),
    'the door is only under the cog, where a setting goes');
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

// WHERE IT STANDS, THEN WHAT YOU CAN DO (owner, 2026-08-03). Three readings
// about right now — the hour, the sky, what this has cost — above the four
// things a person does to a xeric. They used to be scattered either side of the
// buttons, with the weather further down still, opening the sidebar body under
// the rooms it hangs over: one question, three separate notes to answer it.
//
// Asserted on the RENDERED PAGE and by POSITION, because that is the whole of
// the change — every one of these already existed and each was somewhere else.
$posStatus  = strpos($playDom, 'class="sstatus"');
$posQuick   = strpos($playDom, 'class="quickrow"');
$posWeather = strpos($playDom, 'id="sweather"');
$posMeter   = strpos($playDom, 'class="smeter"');
$posClock   = strpos($playDom, 'id="sclock"');

ok('the sidebar opens on where the world stands, not on the buttons',
    $posStatus !== false && $posQuick !== false && $posStatus < $posQuick,
    'the status strip is not above the quick row');
foreach (['the clock' => $posClock, 'the weather' => $posWeather,
          'the meter' => $posMeter] as $what => $pos) {
    ok("and $what reads above them too",
        $pos !== false && $posQuick !== false && $pos < $posQuick,
        "$what is below the buttons, or absent");
}
// All four, still, and still in the order the row was designed in.
$quickTail = $posQuick !== false ? substr($playDom, $posQuick, 1400) : '';
ok('the four things you can do are all still there',
    str_contains($quickTail, 'qadd') && str_contains($quickTail, 'qwhere')
    && str_contains($quickTail, 'qwatch') && str_contains($quickTail, 'qbook'),
    substr($quickTail, 0, 200));

// ONE SKY, NOT TWO. The weather moved rather than being copied — a second copy
// left behind in the sidebar body would repaint on a different pulse and the
// two would disagree with each other in front of the reader.
ok('and the sky is printed exactly once',
    substr_count($playDom, 'class="swx"') <= 1,
    substr_count($playDom, 'class="swx"') . ' copies of the weather line');

// -- and down -----------------------------------------------------------------
if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
usleep(300000);
@shell_exec('rm -rf ' . escapeshellarg($tmp) . ' 2>/dev/null');

echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
exit($FAILED === 0 ? 0 : 1);
