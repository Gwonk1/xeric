<?php
/**
 * Xeric — demo-layer tests. `php forge/web/tests/demo-test.php`, exit 0 on pass.
 *
 * NO NETWORK, NO MODEL, NO CLOCK TO WAIT ON. Every window in limits.php takes an
 * injected `now`, and the queue is driven with real flocks in one process
 * (flock() conflicts across two descriptors of the same process, which is what
 * makes a single-process test of a multi-process lock honest). One late section
 * spends real child processes anyway — the check-then-write bugs this layer was
 * reviewed for all LOOKED fixed from inside one process, so the locks are raced
 * from outside it once, on purpose, where losing shows.
 *
 * What is being defended here, in the order it would hurt:
 *
 *   1. TWO VISITORS ARE TWO VISITORS. The demo is one directory of worlds behind
 *      one password; the first bug that matters is one stranger's evening
 *      landing in another's world. So: ownership, and a fork that proves the
 *      shared copy did not move.
 *   2. A LIMIT TRIPS WHERE IT SAYS IT DOES, AND RECOVERS. Off-by-one here is
 *      either a demo that refuses its first visitor or one that refuses nobody.
 *   3. EVICTION TAKES THE DEAD, NEVER THE LIVE. Deleting a world out from under
 *      somebody mid-conversation is worse than refusing a new arrival.
 *   4. THE QUEUE IS FIFO, TIMES OUT WHAT DIED, AND OBEYS THE FLAG FILE. The
 *      owner must be able to take their own GPU back instantly.
 *   5. NOBODY REACHES ABOVE THE WEAKEST RATING WITHOUT SAYING SO — AND SAYING SO
 *      IS THE ONLY THING IT GATES. The hosted demo is the one deployment where
 *      this is a real control, because the servers do the generating. Both halves
 *      are asserted together at the end of this file, because they are one rule:
 *      an unattended path lands at the weakest rating with nobody deciding
 *      anything, AND a world pinned there still has its children in it. A change
 *      that made the gate quietly empty a cast would be as much a failure as one
 *      that let a stranger past it.
 *
 * Everything runs in a throwaway data dir and a throwaway worlds dir, so this
 * can never touch a real session, a real world, or the repo's own worlds/.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-demo-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called
// THE CAPS ARE OFF ON A LOCAL INSTALL and this suite is the thing that proves
// they still work for a host that wants them. Switched on for the whole file;
// the one assertion about the DEFAULT turns it back off around itself.
putenv('XERIC_CAPS=1');
// AND AS A HOST, WITH VISITORS. A local install is one person and one identity
// kept beside its xerics — no cookie, nothing to clear. Everything below about
// one visitor not reading another's world is about the HOSTED shape, so the
// suite asks for it; the assertion further down proves solo is the default.
putenv('XERIC_SOLO=0');

require_once __DIR__ . '/../play-lib.php';

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

/** A plausible session id, without going through a cookie. */
function sid(): string { return bin2hex(random_bytes(16)); }

/** Does this read like something a person wrote? */
function human(string $s): bool
{
    return strlen($s) > 40 && str_contains($s, ' ') && preg_match('/[.!]$/', trim($s)) === 1
        && !str_contains($s, '{') && !str_contains($s, 'Exception');
}

function rmtree(string $d): void
{
    foreach (glob($d . '/*') ?: [] as $p) { is_dir($p) ? rmtree($p) : @unlink($p); }
    @rmdir($d);
}

/** Wipe every counter, so one group of tests cannot leak into the next. */
function reset_limits(): void
{
    foreach (glob(xeric_limit_dir() . '/*.json') ?: [] as $f) @unlink($f);
}

echo "\n# identity\n";

// ---------------------------------------------------------------------------
// Sessions: created once, stable, and owning nothing to begin with
// ---------------------------------------------------------------------------

$_COOKIE = [];
$first = xeric_web_sid();
ok('a first visit mints an opaque id', (bool)preg_match('/^[a-f0-9]{32}$/', $first), $first);
ok('and the same request keeps it', xeric_web_sid() === $first);

$_COOKIE[XERIC_WEB_COOKIE] = 'not-a-session-id';
ok('a junk cookie is replaced, not trusted', xeric_web_sid() !== 'not-a-session-id');

$A = sid();
$B = sid();
xeric_session_use($A);
ok('a worker can be told whose work it is doing', xeric_session_id() === $A);
xeric_session_touch($A);
xeric_session_touch($B);
ok('touching a session writes a record', is_file(xeric_web_session_path($A)));
$rec = xeric_web_session_read($A);
ok('with when it was created and last seen', (int)$rec['created'] > 0 && (int)$rec['seen'] > 0);
ok('a fresh session owns nothing', xeric_session_worlds($A) === [] && xeric_session_copies($A) === []);

echo "\n# ownership\n";

// ---------------------------------------------------------------------------
// Worlds: forged by one session, visible to all, owned by one
// ---------------------------------------------------------------------------

// A REAL WORLD, AND A TRACKED ONE. A hand-written stub would not survive
// xeric_world_load()'s validator and the fork test below needs the real thing —
// but this used to glob `<repo>/worlds/*` for whatever the developer running the
// suite happened to have forged. That is not a fixture, it is a coincidence: two
// people got two different worlds, somebody with an empty shelf got four
// failures that said nothing about their change, and the whole file was one
// `git clean` from unrunnable. It also quietly assumed xerics live inside the
// checkout, which they do not (see xeric_web_worlds_default).
//
// So it is built here, from the engine's own tracked fixture plus the same
// default seed a blank forge writes. Deterministic, self-contained, and the
// same world every time this runs on any machine.
$srcWorld = $tmp . '/fixture-world';
@mkdir($srcWorld, 0775, true);
$fixtureT = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
file_put_contents($srcWorld . '/world-template.json',
    json_encode($fixtureT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($srcWorld . '/seed.json',
    json_encode(xeric_forge_default_seed($fixtureT),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
ok('a world template to test against was built from the tracked fixture',
    is_file($srcWorld . '/world-template.json') && is_file($srcWorld . '/seed.json'));
ok('and it is a world the loader accepts, which is the whole reason it is real',
    (array)xeric_world_load($srcWorld . '/world-template.json') !== []);

$shared = xeric_web_worlds_dir() . '/shared-town';
@mkdir($shared, 0775, true);
@copy($srcWorld . '/world-template.json', $shared . '/world-template.json');
@copy($srcWorld . '/seed.json', $shared . '/seed.json');

$forged = xeric_web_worlds_dir() . '/as-forged';
@mkdir($forged, 0775, true);
@copy($srcWorld . '/world-template.json', $forged . '/world-template.json');
@copy($srcWorld . '/seed.json', $forged . '/seed.json');

xeric_session_claim('as-forged', $A);
ok('a session owns the world it forged', xeric_session_owns('as-forged', $A));
ok('and nobody else does', !xeric_session_owns('as-forged', $B));
ok('a world with no owner belongs to nobody', !xeric_session_owns('shared-town', $A));
ok('the claim is written into the world too', xeric_session_world_owner('as-forged') === $A);
ok('“my worlds” is just the ones it forged', xeric_session_worlds($A) === ['as-forged']);
ok('the other session sees none of them', xeric_session_worlds($B) === []);

// The shelf is everybody's; only the label differs.
$shelf = xeric_play_worlds($A);
$byslug = [];
foreach ($shelf as $x) $byslug[$x['slug']] = $x;
ok('both worlds are on the shelf for everybody', isset($byslug['shared-town'], $byslug['as-forged']));
ok('and one of them is marked as this visitor\'s', $byslug['as-forged']['mine'] && !$byslug['shared-town']['mine']);

echo "\n# the fork — the template is shared, only state forks\n";

// ---------------------------------------------------------------------------
// First foreign play copies the db; playing the copy does not move the original
// ---------------------------------------------------------------------------

xeric_session_use($A);
$wa = xeric_play_open('shared-town');            // A does not own it either — a shared world forks too
ok('a shared world is played from a copy, not the original',
    $wa['db_path'] !== $shared . '/world.db' && $wa['forked'] === true, $wa['db_path']);

// Now give the shared world a canonical database with something in it, the way
// it would look if it had ever been played by its owner.
$canon = xeric_state_open($shared . '/world.db');
$T = xeric_world_load($shared . '/world-template.json');
xeric_state_seed($canon, $T, xeric_state_time());
xeric_event_add($canon, 'the shared original', time(), null, [], 'this must survive everybody');
$before = xeric_events_count($canon);
ok('the shared world has a database with a past in it', $before > 0);

xeric_session_use($B);
$wb = xeric_play_open('shared-town');
ok('a second visitor is forked on first play', $wb['forked'] === true);
ok('into their own session', str_contains($wb['db_path'], xeric_session_db_dir($B)), $wb['db_path']);
ok('carrying the shared world\'s past with them', xeric_events_count($wb['db']) >= $before);

// B lives in their copy. (Their copy may hold MORE than the original already:
// the shared world had never had its seed.json applied, and opening it does
// that — into the copy, which is the whole point.)
$bBefore = xeric_events_count($wb['db']);
xeric_event_add($wb['db'], 'B did something', time(), null, [], 'only in B\'s copy');
$bCount = xeric_events_count($wb['db']);

$canon2 = xeric_state_open($shared . '/world.db');
ok('and the shared copy did not move', xeric_events_count($canon2) === $before,
    $before . ' → ' . xeric_events_count($canon2));
ok('while their own did', $bCount === $bBefore + 1, $bBefore . ' → ' . $bCount);

$wb2 = xeric_play_open('shared-town');
ok('a second play is the same copy, not another one', $wb2['forked'] === false && $wb2['db_path'] === $wb['db_path']);
ok('the copy shows up as theirs', xeric_session_copies($B) === ['shared-town']);

// C never opened it, so C has no copy and cannot see B's.
$C = sid();
xeric_session_use($C);
ok('a third visitor has no copy of anything', xeric_session_copies($C) === []);

// A REWIND IS NOT HERITABLE. The owner's last skip leaves its manifest
// (skip:last) in the canonical db, and a fork that carried it offered a
// visitor who never pressed skip a "take back the 6h" button — executing a
// rewind that deleted hours of the shared past in their copy. The fork clear
// takes skip:% with it now, along with everything else that is somebody
// else's session in a world's clothing.
xeric_world_state_set($canon2, 'skip:last', json_encode(['v' => 1, 'span' => 21600]));
xeric_world_state_set($canon2, 'skip:underway', (string)time());
$D = sid();
xeric_session_use($D);
$wd = xeric_play_open('shared-town');
ok('fork: a visitor\'s copy carries no skip:last — the owner\'s rewind is not theirs to take',
    $wd['forked'] === true
    && xeric_world_state_get($wd['db'], 'skip:last') === null
    && xeric_world_state_get($wd['db'], 'skip:underway') === null);
xeric_world_state_delete($canon2, 'skip:last');
xeric_world_state_delete($canon2, 'skip:underway');

// The owner of a world plays the canonical database, not a copy.
xeric_session_use($A);
$wown = xeric_play_open('as-forged');
ok('the owner plays their own world directly',
    $wown['db_path'] === $forged . '/world.db' && $wown['mine'] === true, $wown['db_path']);

echo "\n# expiry\n";

// ---------------------------------------------------------------------------
// A session idle past its TTL goes, and takes its worlds and copies with it
// ---------------------------------------------------------------------------

$old = sid();
xeric_session_touch($old);
$oldWorld = xeric_web_worlds_dir() . '/gone-tomorrow';
@mkdir($oldWorld, 0775, true);
@copy($srcWorld . '/world-template.json', $oldWorld . '/world-template.json');
xeric_session_claim('gone-tomorrow', $old);
xeric_session_use($old);
xeric_play_open('shared-town');                   // gives $old a copy as well
$oldCopy = xeric_session_db_dir($old) . '/shared-town.db';
ok('the expiring session had a world and a copy',
    is_file($oldWorld . '/world-template.json') && is_file($oldCopy));

@touch(xeric_web_session_path($old), time() - XERIC_SESSION_TTL - 60);
$swept = xeric_session_sweep();
ok('an idle session is swept', $swept === 1 && !is_file(xeric_web_session_path($old)), (string)$swept);
ok('its forged world goes with it', !is_dir($oldWorld));
ok('so does its copy', !is_file($oldCopy));
ok('a live session is untouched', is_file(xeric_web_session_path($B)));
ok('and the shared world nobody owns is never swept', is_file($shared . '/world-template.json'));

echo "\n# limits\n";

// ---------------------------------------------------------------------------
// Each cap trips on the request after its Nth, and recovers with its window
// ---------------------------------------------------------------------------

// -- and off, unless somebody asks for them ---------------------------------
// The numbers in limits.php were written for a public demo on one workstation.
// On somebody's own machine they ration their own GPU back to them, so the
// default is now off and a host opts in.
(function (): void {
    putenv('XERIC_CAPS=');
    // The switch is memoised per process, so a fresh answer needs a fresh
    // process. Asked in one here, which is also how a page will ask it.
    $out = trim((string)shell_exec(
        'XERIC_DATA_DIR=' . escapeshellarg(sys_get_temp_dir() . '/xeric-capsoff')
        . ' php -r ' . escapeshellarg(
            'require ' . var_export(dirname(__DIR__) . '/limits.php', true) . ';'
            . 'echo xeric_limit_on() ? "on" : "off";'
        )
    ));
    ok('the caps are off unless a host turns them on', $out === 'off', $out);
    putenv('XERIC_CAPS=1');
})();

// -- and one identity per machine, unless a host wants visitors --------------
// A cookie is how a server tells strangers apart. On somebody's own computer it
// is a way to lose everything by clearing a browser, which people do for
// unrelated reasons.
(function (): void {
    putenv('XERIC_SOLO=');
    $tmp = sys_get_temp_dir() . '/xeric-solo-' . bin2hex(random_bytes(4));
    $out = trim((string)shell_exec(
        'XERIC_DATA_DIR=' . escapeshellarg($tmp)
        . ' php -r ' . escapeshellarg(
            'require ' . var_export(dirname(__DIR__) . '/play-lib.php', true) . ';'
            . 'echo xeric_web_solo() ? "solo" : "visitors";'
            . 'echo xeric_session_id() === xeric_web_machine_id() ? " and it is the machine" : " BUT NOT THE MACHINE";'
        )
    ));
    ok('one identity per machine unless a host asks for visitors',
        $out === 'solo and it is the machine', $out);

    // AND A DEFAULT IS NOT A DOOR. The rule above is right for a laptop and
    // catastrophic for a host: solo means the machine identity IS the visitor,
    // so a public install that never wrote the key made every stranger the same
    // person — each of them owner of all the others' worlds. deploy.sh omitted
    // it, so the demo ran the laptop's rule. Silence now means "solo only if you
    // are this machine"; a peer from anywhere else is a visitor.
    $ask = static function (string $peer) use ($tmp): string {
        return trim((string)shell_exec(
            'XERIC_SOLO= XERIC_DATA_DIR=' . escapeshellarg($tmp)
            . ' php -r ' . escapeshellarg(
                'require ' . var_export(dirname(__DIR__) . '/play-lib.php', true) . ';'
                . '$_SERVER["REMOTE_ADDR"] = ' . var_export($peer, true) . ';'
                . 'echo xeric_web_solo() ? "solo" : "visitors";')));
    };
    ok('solo: a request off this machine is still one person', $ask('127.0.0.1') === 'solo');
    ok('solo: and so is the loopback address the other way round', $ask('::1') === 'solo');
    ok('solo: but a stranger is a visitor, whatever the config forgot to say',
        $ask('203.0.113.9') === 'visitors', $ask('203.0.113.9'));
    ok('solo: a host that MEANS it can still say so, and is believed',
        trim((string)shell_exec('XERIC_SOLO=1 XERIC_DATA_DIR=' . escapeshellarg($tmp)
            . ' php -r ' . escapeshellarg(
                'require ' . var_export(dirname(__DIR__) . '/play-lib.php', true) . ';'
                . '$_SERVER["REMOTE_ADDR"] = "203.0.113.9";'
                . 'echo xeric_web_solo() ? "solo" : "visitors";'))) === 'solo');

    putenv('XERIC_SOLO=0');
})();

// AND THE DEPLOYED HOST SAYS BOTH OF THEM OUT LOUD. The two findings above were
// not code defects at all — deploy.sh wrote a config.local.php with neither key,
// so the public demo ran with one shared identity and every rate limit inert.
$dep = (string)file_get_contents(dirname(__DIR__) . '/deploy.sh');
ok('deploy: the host it writes has visitors on it, not one person',
    str_contains($dep, "'solo'       => false,"));
ok('deploy: and its rate limits actually run',
    str_contains($dep, "'caps'       => true,"));

// ---------------------------------------------------------------------------
// A POST THAT STARTED ON SOMEBODY ELSE'S PAGE.
//
// There was no cross-site protection at all, and the usual fallback could not
// help: SameSite guards a COOKIE, and in solo mode the identity is a file, so a
// request carrying no cookie whatsoever is still fully authenticated. Every POST
// was reachable from any page the owner happened to open — demonstrated with no
// cookie at all: a world deleted, the notify URL repointed at a stranger's ntfy
// topic, an attacker's address stored as the ENGINE (after which every prompt
// leaves the machine), and power.php's shutdown, whose loopback fence passes
// precisely because the request comes from the victim's own browser.
// ---------------------------------------------------------------------------

$peerWas = $_SERVER;
$xs = static function (array $h): bool {
    foreach (['HTTP_SEC_FETCH_SITE', 'HTTP_ORIGIN', 'HTTP_HOST'] as $k) unset($_SERVER[$k]);
    foreach ($h as $k => $v) $_SERVER[$k] = $v;
    return xeric_web_cross_site();
};

ok('cross-site: the browser saying so is enough',
    $xs(['HTTP_SEC_FETCH_SITE' => 'cross-site']) === true);
ok('cross-site: and the browser saying otherwise is believed',
    $xs(['HTTP_SEC_FETCH_SITE' => 'same-origin']) === false
    && $xs(['HTTP_SEC_FETCH_SITE' => 'same-site']) === false);
ok('cross-site: an old browser is judged on its origin instead',
    $xs(['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => '127.0.0.1:8787']) === true
    && $xs(['HTTP_ORIGIN' => 'http://127.0.0.1:8787', 'HTTP_HOST' => '127.0.0.1:8787']) === false);
ok('cross-site: an opaque origin is never us',
    $xs(['HTTP_ORIGIN' => 'null', 'HTTP_HOST' => '127.0.0.1:8787']) === true);
ok('cross-site: a default port written out is still the same host',
    $xs(['HTTP_ORIGIN' => 'http://xeric.dev', 'HTTP_HOST' => 'xeric.dev']) === false);
// FAILS OPEN WITH NO HEADERS, deliberately: curl, the launcher's own tooling and
// these suites send neither, and they are not the threat — a drive-by needs a
// browser, and every browser that can mount one sends Sec-Fetch-Site.
ok('cross-site: a caller with no origin at all is not the threat and is let by',
    $xs(['HTTP_HOST' => '127.0.0.1:8787']) === false);
$_SERVER = $peerWas;

// The guard has to be somewhere every POST passes. Most reach it through
// xeric_web_input(); the four that read $_POST directly must say so themselves,
// and two of those are where the demonstrated attacks landed.
$bootSrc = (string)file_get_contents(dirname(__DIR__) . '/boot.php');
ok('cross-site: every POST that reads a body passes the guard',
    preg_match('/function xeric_web_input\(\): array\s*\{[^}]*xeric_web_csrf_guard\(\);/s', $bootSrc) === 1);
foreach (['model', 'notify', 'join'] as $direct) {
    ok("cross-site: $direct.php guards itself, since it reads \$_POST directly",
        str_contains((string)file_get_contents(dirname(__DIR__) . '/' . $direct . '.php'),
            'xeric_web_csrf_guard();'));
}

// ---------------------------------------------------------------------------
// THROUGH THE DOOR IS NOT HOLDING THE KEYS.
//
// A guest who came in on a pairing code plays the OWNER'S database — that is the
// point of inviting somebody — so xeric_play_guard() lets them in, and what they
// may DO is answered by $w['mine']. Six surfaces never asked: a guest could stop
// the owner's clock, throw their learning switch, grant photo consent and spend
// it, walk their character across town, take back the hours of their last skip,
// and — the one that cannot be undone — kill the entire cast with fate.php
// act=end, which under a permanent death mode xeric_death_restore() refuses to
// reverse. engine/pair.php's docblock promises none of this is reachable.
//
// AND IT IS NOT SIMPLY `!mine`, which is why the predicate is worth its own
// function. `mine` is false for two different people: a STRANGER, who is on
// their own FORK and may do as they like in it, and a GUEST, who is on the
// canonical file. Which file is open is the thing that tells them apart.
// ---------------------------------------------------------------------------

$gDir = xeric_web_worlds_dir() . '/some-town';
ok('guest: the owner is not a guest',
    xeric_play_is_guest(['dir' => $gDir, 'db_path' => $gDir . '/world.db', 'mine' => true]) === false);
ok('guest: somebody on the owner\'s own database who is not the owner IS one',
    xeric_play_is_guest(['dir' => $gDir, 'db_path' => $gDir . '/world.db', 'mine' => false]) === true);
ok('guest: but a stranger on their own fork is not — that copy is theirs',
    xeric_play_is_guest(['dir' => $gDir, 'mine' => false,
        'db_path' => $tmp . '/session-worlds/abc/some-town.db']) === false);
ok('guest: and a trailing slash on the world dir does not smuggle one past',
    xeric_play_is_guest(['dir' => $gDir . '/', 'db_path' => $gDir . '/world.db', 'mine' => false]) === true);

// The six that forgot, each named, so a seventh cannot be added quietly.
foreach ([
    'power'  => 'the clock and the learning switch',
    'fate'   => 'ending the world',
    'photo'  => 'photo consent and the spend behind it',
    'where'  => 'walking the owner\'s character',
    'tick'   => 'taking back the last skip',
] as $page => $why) {
    ok("guest: $page.php asks before it writes — $why",
        str_contains((string)file_get_contents(dirname(__DIR__) . '/' . $page . '.php'),
            'xeric_play_owner_only('));
}
ok('guest: power.php asks TWICE, because the clock and the switch are two doors',
    substr_count((string)file_get_contents(dirname(__DIR__) . '/power.php'),
        'xeric_play_owner_only(') === 2);

reset_limits();
$S = sid();
$ip = 'test-' . bin2hex(random_bytes(4));
$T0 = time();                                    // the windows are injected; the files are real

$n = xeric_limit_n('messages_per_hour');
$allOk = true;
for ($i = 0; $i < $n; $i++) {
    $r = xeric_limit_take('message', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + $i]);
    if (!$r['ok']) $allOk = false;
}
ok("$n messages in an hour are all allowed", $allOk);

$r = xeric_limit_check('message', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + $n]);
ok('the one after that is refused', !$r['ok']);
ok('with a sentence a person wrote', human((string)$r['message']), (string)$r['message']);
ok('and a retry_after that lands inside the hour',
    $r['retry_after'] > 0 && $r['retry_after'] <= XERIC_LIMIT_HOUR, (string)$r['retry_after']);

$r = xeric_limit_check('message', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + XERIC_LIMIT_HOUR + 1]);
ok('and it recovers when the window rolls', $r['ok']);

// The counters are per session: another visitor is not affected by this one.
$r = xeric_limit_check('message', ['sid' => sid(), 'ip' => $ip, 'now' => $T0 + $n]);
ok('somebody else\'s hour is their own', $r['ok']);

$skips = xeric_limit_n('skips_per_hour');
for ($i = 0; $i < $skips; $i++) xeric_limit_take('skip', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + $i]);
$r = xeric_limit_check('skip', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + $skips]);
ok("skips trip at $skips an hour", !$r['ok'] && human((string)$r['message']));
ok('a skip cap does not spend the message cap',
    xeric_limit_check('message', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + XERIC_LIMIT_HOUR + 1])['ok']);

$forges = xeric_limit_n('forges_per_day');
for ($i = 0; $i < $forges; $i++) xeric_limit_take('forge', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + $i]);
$r = xeric_limit_check('forge', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + $forges]);
ok("forges trip at $forges a day", !$r['ok'] && human((string)$r['message']));
ok('with a retry_after inside the day', $r['retry_after'] > 0 && $r['retry_after'] <= XERIC_LIMIT_DAY);
ok('and it recovers tomorrow',
    xeric_limit_check('forge', ['sid' => $S, 'ip' => $ip, 'now' => $T0 + XERIC_LIMIT_DAY + 1])['ok']);

// A fresh cookie jar does not buy a fresh day: the address is counted too.
$ipn = xeric_limit_n('ip_forges_per_day');
$ip2 = 'test-' . bin2hex(random_bytes(4));
$jars = 0;
for ($i = 0; $i < $ipn + 2; $i++) {
    $fresh = sid();
    $r = xeric_limit_take('forge', ['sid' => $fresh, 'ip' => $ip2, 'now' => $T0 + $i]);
    if ($r['ok']) $jars++;
}
ok("clearing cookies does not escape the address cap ($ipn a day)", $jars === $ipn, (string)$jars);
$r = xeric_limit_check('forge', ['sid' => sid(), 'ip' => $ip2, 'now' => $T0 + $ipn]);
ok('and the refusal says whose budget it was', !$r['ok'] && str_contains((string)$r['message'], 'address'));

// Minting sessions to escape the per-session caps is the same story.
reset_limits();
$ip3 = 'test-' . bin2hex(random_bytes(4));
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$cap = xeric_limit_n('ip_sessions_per_day');
$flagged = null;
for ($i = 0; $i <= $cap; $i++) {
    $s = xeric_limit_new_session(['created' => $T0], $T0 + $i);
    if ($i < $cap && isset($s['capped_until'])) $flagged = 'capped too early at ' . $i;
    if ($i === $cap) $flagged = isset($s['capped_until']) ? null : 'the one over the cap was not flagged';
}
ok("session $cap + 1 from one address is capped", $flagged === null, (string)$flagged);

$capped = sid();
xeric_session_touch($capped);
$rec = xeric_web_session_read($capped);
$rec['capped_until'] = time() + 3600;
xeric_web_session_write($rec, $capped);
$r = xeric_limit_check('message', ['sid' => $capped]);
ok('a capped session may look but not spend', !$r['ok'] && human((string)$r['message']));
ok('and is told when that changes', $r['retry_after'] > 0 && $r['retry_after'] <= XERIC_LIMIT_DAY);

ok('a refusal never comes back as a 500',
    ($r['status'] ?? 429) === 429 || ($r['status'] ?? 429) === 503, (string)($r['status'] ?? 0));

echo "\n# eviction\n";

// ---------------------------------------------------------------------------
// The oldest idle goes first; anybody recent is untouchable
// ---------------------------------------------------------------------------

// Start from a clean slate of sessions so the ordering assertion is about these
// three and not about everything the tests above left behind. The BUCKETS go
// too: the section above deliberately spends this address's whole daily session
// budget, and a session refused for that reason is now a session with no record
// at all — which would leave these three invisible to eviction rather than
// ordered by it.
foreach (glob(xeric_web_sessions_dir() . '/*.json') ?: [] as $f) @unlink($f);
rmtree(xeric_session_root());
reset_limits();

$oldest = sid(); $middle = sid(); $recent = sid();
foreach ([$oldest, $middle, $recent] as $s) {
    xeric_session_touch($s);
    xeric_web_dir(xeric_session_db_dir($s));
    file_put_contents(xeric_session_db_dir($s) . '/shared-town.db', str_repeat('x', 40000));
}
@touch(xeric_web_session_path($oldest), time() - 500000);
@touch(xeric_web_session_path($middle), time() - 200000);

$diskBefore = xeric_limit_disk();
$got = xeric_limit_evict(1);
ok('eviction takes exactly what was asked for', $got['sessions'] === 1, (string)$got['sessions']);
ok('and it is the oldest idle one', !is_file(xeric_web_session_path($oldest)));
ok('the next-oldest is still there', is_file(xeric_web_session_path($middle)));
ok('it frees disk', $got['bytes'] > 39000 && xeric_limit_disk() < $diskBefore,
    $diskBefore . ' → ' . xeric_limit_disk());
ok('and the copies go with it', !is_dir(xeric_session_db_dir($oldest)));

$got = xeric_limit_evict(5);
ok('a session in use is never evicted, however hard it is pushed',
    is_file(xeric_web_session_path($recent)) && $got['sessions'] === 1, (string)$got['sessions']);

echo "\n# the queue\n";

// ---------------------------------------------------------------------------
// One slot, in the order people asked, with a way to take it back
// ---------------------------------------------------------------------------

@unlink(xeric_queue_path());
@unlink(xeric_queue_drain_path());

ok('an idle queue is not busy', !xeric_queue_busy() && xeric_queue_status()['depth'] === 0);

$t1 = xeric_queue_join('say', 'aaaa1111');
$h1 = xeric_queue_try($t1);
ok('the first asker gets the model', $h1['ok'] === true);
ok('and the queue says so', xeric_queue_busy() && xeric_queue_status()['busy'] === true);

$t2 = xeric_queue_join('say', 'bbbb2222');
$r2 = xeric_queue_try($t2);
ok('the second asker is not refused, but placed', $r2['ok'] === false && ($r2['kind'] ?? '') === 'waiting');
ok('told where they are standing', (int)$r2['ahead'] === 1, (string)$r2['ahead']);
ok('in a sentence, with an estimate', str_contains($r2['phrase'], 'next in line')
    && str_contains($r2['phrase'], 'about'), $r2['phrase']);

$t3 = xeric_queue_join('tick', 'cccc3333');
$r3 = xeric_queue_try($t3);
ok('the third is third', (int)$r3['ahead'] === 2 && str_contains($r3['phrase'], '2nd in line'), $r3['phrase']);

// FIFO: even asking first, the one behind does not get it.
xeric_queue_release($h1['hold']);
ok('releasing frees the slot', !xeric_queue_busy());
$r3 = xeric_queue_try($t3);
ok('a latecomer cannot jump the line', $r3['ok'] === false && ($r3['kind'] ?? '') === 'waiting');
$h2 = xeric_queue_try($t2);
ok('the one who asked first gets it', $h2['ok'] === true);
ok('and the release measured what the hold cost', (int)(xeric_queue_state()['avg']['say'] ?? 0) > 0);

// A waiter whose process died stops holding up everybody behind it.
xeric_queue_release($h2['hold']);
$state = xeric_queue_state();
$raw = json_decode((string)file_get_contents(xeric_queue_path()), true);
foreach ($raw['line'] as $i => $t) $raw['line'][$i]['seen'] = time() - XERIC_QUEUE_TICKET_TTL - 5;
file_put_contents(xeric_queue_path(), json_encode($raw));
$t4 = xeric_queue_join('say', 'dddd4444');
$h4 = xeric_queue_try($t4);
ok('a ticket nobody is polling times out of the line', $h4['ok'] === true);
xeric_queue_release($h4['hold']);

// A holder that died leaves a record, but the kernel already freed the slot.
$ghost = xeric_queue_state();
$ghost['holder'] = ['id' => 'deadbeef', 'at' => time() - 5, 'what' => 'tick', 'who' => 'zzzz'];
file_put_contents(xeric_queue_path(), json_encode($ghost));
ok('a dead holder does not wedge the queue', xeric_queue_status()['busy'] === false);
$t5 = xeric_queue_join('say', 'eeee5555');
$h5 = xeric_queue_try($t5);
ok('and the next asker simply gets the model', $h5['ok'] === true);

// The hard cap, and the flag file: two ways the owner's machine wins.
ok('a hold past the hard cap must stop',
    xeric_queue_expired(['at' => time() - XERIC_QUEUE_HOLD_MAX - 1]) === true);
ok('a hold inside it carries on', xeric_queue_expired(['at' => time() - 5]) === false);

touch(xeric_queue_drain_path());
ok('the flag file drains the queue', xeric_queue_drained() === true);
ok('a running hold is told to stop mid-work', xeric_queue_expired($h5['hold']) === true);
ok('and it says why, in words', human(xeric_queue_stop_reason($h5['hold']) . '.'));
$t6 = xeric_queue_join('say', 'ffff6666');
$r6 = xeric_queue_try($t6);
ok('nobody new may take the model', $r6['ok'] === false && ($r6['kind'] ?? '') === 'drained');
ok('and is told the owner has it, not that the demo is broken',
    human((string)$r6['message']) && str_contains((string)$r6['message'], 'owner'));

xeric_queue_release($h5['hold']);                 // the drained hold stops, as it was told to
unlink(xeric_queue_drain_path());
$r6 = xeric_queue_try($t6);
ok('removing the flag gives it straight back', $r6['ok'] === true, (string)($r6['kind'] ?? ''));
xeric_queue_release($r6['hold']);

// A line too long to join honestly is a refusal, not a silent wait.
for ($i = 0; $i < XERIC_QUEUE_DEPTH_MAX; $i++) xeric_queue_join('say', 'full' . $i);
ok('a full line refuses rather than pretending', xeric_queue_join('say', 'x') === '');
$r = xeric_queue_take('say', 0.0, 'x');
ok('and says so like a person', $r['ok'] === false && human((string)$r['message']), (string)$r['message']);


echo "\n# review, before launch\n";

// ---------------------------------------------------------------------------
// The forge proposes; the user disposes. What is being defended here:
//   1. an edit that would break the world is REFUSED, in English, and the old
//      value is still there afterwards;
//   2. an edit that is fine is on disk a moment later, not in a session;
//   3. nothing can rename a key or a handle from a text box, because every wall
//      and every week block in the world points at those by name;
//   4. a fresh world is not playable until somebody launches it, and launching
//      is one call.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../review-lib.php';
require_once __DIR__ . '/../why-lib.php';

$R = sid();
xeric_session_use($R);
$anvil = xeric_web_worlds_dir() . '/on-the-anvil';
@mkdir($anvil, 0775, true);
@copy($srcWorld . '/world-template.json', $anvil . '/world-template.json');
@copy($srcWorld . '/seed.json', $anvil . '/seed.json');
xeric_session_claim('on-the-anvil', $R);

// Mark it the way worker.php now does.
$anvilT = xeric_world_load($anvil . '/world-template.json');
xeric_review_save('on-the-anvil', xeric_review_mark_pending($anvilT));

$rw = xeric_review_open('on-the-anvil', $R);
ok('a freshly forged world is not launched', $rw['launched'] === false);
ok('and it is the owner\'s to change', $rw['mine'] === true);
ok('a world with no flag at all counts as launched', xeric_review_launched($anvilT) === true);

// -- an edit that is fine ----------------------------------------------------
$e1 = xeric_review_apply_edit($rw, 'cast.characters.0.voice', 'Says it once, quietly, and does not repeat it.');
ok('a hand edit is accepted', ($e1['ok'] ?? false) === true, json_encode($e1));
$again = xeric_review_open('on-the-anvil', $R);
ok('and it is on disk, not in a session',
    (string)$again['template']['cast']['characters'][0]['voice'] === 'Says it once, quietly, and does not repeat it.');

// -- an edit that is not ------------------------------------------------------
$wasFrom = (string)($again['template']['cast']['characters'][0]['week'][0]['from'] ?? '09:00');
$e2 = xeric_review_apply_edit($again, 'cast.characters.0.week.0.from', 'half past nine');
ok('a time that is not a time is refused', ($e2['ok'] ?? true) === false);
ok('and the refusal is a sentence a person wrote', human((string)($e2['error'] ?? '')), (string)($e2['error'] ?? ''));
ok('and it does not leak a path or an exception',
    !str_contains((string)($e2['error'] ?? ''), 'cast.characters[')
    && !str_contains((string)($e2['error'] ?? ''), 'xeric:'));
$after = xeric_review_open('on-the-anvil', $R);
ok('and the old value is still there',
    (string)$after['template']['cast']['characters'][0]['week'][0]['from'] === $wasFrom);

$e3 = xeric_review_apply_edit($after, 'cast.characters.0.one_line', '');
ok('an emptied field is refused too', ($e3['ok'] ?? true) === false);
ok('with a reason, not a code', human((string)($e3['error'] ?? '')));

// -- what may never be edited -------------------------------------------------
foreach (['places.0.key', 'cast.characters.0.handle', 'cast.characters.0.orbit', 'meta.rating'] as $forbidden) {
    $r = xeric_review_apply_edit($after, $forbidden, 'anything');
    ok("$forbidden cannot be retyped by hand", ($r['ok'] ?? true) === false, json_encode($r));
}
ok('the keys really did not move',
    (string)xeric_review_open('on-the-anvil', $R)['template']['places'][0]['key']
        === (string)$after['template']['places'][0]['key']);

// -- an unlaunched world is on its owner's shelf and nobody else's ------------
$shelfR = [];
foreach (xeric_play_worlds($R) as $x) $shelfR[$x['slug']] = $x;
ok('an unlaunched world is on its owner\'s shelf, marked', isset($shelfR['on-the-anvil'])
    && $shelfR['on-the-anvil']['launched'] === false);
xeric_session_use($B);
$shelfB = [];
foreach (xeric_play_worlds($B) as $x) $shelfB[$x['slug']] = $x;
ok('and on nobody else\'s at all', !isset($shelfB['on-the-anvil']));
xeric_session_use($R);

// -- launching ---------------------------------------------------------------
$lt = xeric_review_open('on-the-anvil', $R)['template'];
unset($lt['forge']['review_pending']);
xeric_review_save('on-the-anvil', $lt);
ok('launching makes it playable', xeric_review_open('on-the-anvil', $R)['launched'] === true);

// -- a hand edit is the strongest thing this app ever learns ------------------
// engine/learn.php: every other signal is an inference from behaviour; this one
// is the user being shown what the world thought and correcting it in writing.
$anvilDb = $anvil . '/world.db';
$pre = xeric_review_open('on-the-anvil', $R);
xeric_review_apply_edit($pre, 'cast.characters.0.one_line', 'Keeps the books and her own counsel.');
ok('editing a world nobody has opened yet writes no signal — and no database',
    !is_file($anvilDb));

$lw = xeric_play_open('on-the-anvil');            // this is what makes it lived-in
$mid = xeric_review_open('on-the-anvil', $R);
$was = (string)$mid['template']['cast']['characters'][0]['voice'];
$ed  = xeric_review_apply_edit($mid, 'cast.characters.0.voice', 'Short. She does not explain herself.');
ok('an edit carries out what it replaced, not just what it wrote',
    ($ed['was'] ?? '') === $was && ($ed['value'] ?? '') === 'Short. She does not explain herself.',
    json_encode($ed));

$crumbs = xeric_signals_unprocessed($lw['db'], 20);
$edits  = array_values(array_filter($crumbs, fn($c) => $c['kind'] === 'edit'));
ok('a hand edit on a lived-in world lands as a signal, in the right head',
    count($edits) === 1 && (string)$edits[0]['handle']
        === (string)$mid['template']['cast']['characters'][0]['handle'], json_encode($edits));
ok('and it carries BOTH sides of the correction, which is the whole value of it',
    str_contains((string)$edits[0]['note'], $was)
    && str_contains((string)$edits[0]['note'], 'does not explain herself'), (string)($edits[0]['note'] ?? ''));

$fail = xeric_review_apply_edit(xeric_review_open('on-the-anvil', $R), 'cast.characters.0.age', 'nine');
ok('an edit that was refused teaches the world nothing',
    ($fail['ok'] ?? true) === false
    && count(array_filter(xeric_signals_unprocessed($lw['db'], 20), fn($c) => $c['kind'] === 'edit')) === 1);

echo "\n# reroll one thing, and only that thing\n";

// ---------------------------------------------------------------------------
// The point of the whole review step: a reroll of ONE character rewrites that
// character and touches nobody else. Driven through the forge's own stub seam,
// so this runs with no network and no model.
// ---------------------------------------------------------------------------

$stub = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts): array {
    if ($tag !== 'cast') throw new RuntimeException("the stub was asked for '$tag'");
    return [
        'display_name' => 'Wendell Pike', 'age' => 52,
        'one_line' => 'Fixes what nobody asked him to and mentions it later.',
        'appearance' => 'Coat older than the building, pockets full of somebody else\'s screws.',
        'voice' => 'Long pauses, then one flat sentence that settles the matter.',
        'sore_spot' => 'being told the job is finished', 'jealousy' => 'people who never learned to wait',
        'self_soothe' => 'counts stock out loud', 'praise' => 'that the work held up',
        'tells' => ['checks a thing twice', 'wipes his hands before he answers', 'leaves the last word'],
        'pull' => 'to be the one who stayed', 'solace' => 'the back step, ten minutes, no phone',
        'work_place' => 'nowhere', 'work_days' => 'weekdays', 'work_from' => '08:00', 'work_to' => '16:00',
        'work_doing' => 'the whole day', 'hangout_place' => 'nowhere', 'hangout_days' => 'weekends',
        'hangout_from' => '19:00', 'hangout_to' => '22:00', 'hangout_doing' => 'the usual',
    ];
}];

$pre = xeric_review_open('on-the-anvil', $R);
$preChars = (array)$pre['template']['cast']['characters'];
$notes = [];
// INDEX 5 IS NOT ARBITRARY: it is the one person in the fixture nothing else
// names. Rerolling anybody an economy's `board.visible_to` points at leaves a
// dangling `handle:` reference and the validator rightly refuses the whole
// template — a real gap (the walls get re-aimed on a reroll, handle references
// do not) and one this test is not about. Filed on the punchlist.
$out = xeric_review_reroll($pre, ['what' => 'character', 'index' => 5, 'endpoint' => $stub],
    function (string $n) use (&$notes) { $notes[] = $n; });

$postChars = (array)$out['template']['cast']['characters'];
ok('a reroll of one character replaced that character',
    (string)$postChars[5]['display_name'] === 'Wendell Pike');
ok('and left everybody else exactly as they were',
    json_encode(array_values(array_diff_key($preChars, [5 => 1])))
        === json_encode(array_values(array_diff_key($postChars, [5 => 1]))));
ok('the cast is still the same size', count($postChars) === count($preChars));
ok('the new person kept the orbit of the one they replaced',
    (string)$postChars[5]['orbit'] === (string)$preChars[5]['orbit']);
ok('a model that named a place that does not exist still lands somewhere real',
    xeric_world_place($out['template'], (string)$postChars[5]['week'][0]['where']) !== null);
ok('and the world it produced validates', (function () use ($out) {
    try { xeric_world_validate($out['template'], 't'); return true; } catch (Throwable $e) { return false; }
})());
$disk = xeric_review_open('on-the-anvil', $R);
ok('the reroll is on disk, not just in memory',
    (string)$disk['template']['cast']['characters'][5]['display_name'] === 'Wendell Pike');
ok('and it said what it did, naming who changed',
    $notes !== [] && str_contains(implode(' ', $notes), (string)$preChars[5]['display_name'])
    && str_contains(implode(' ', $notes), 'Wendell Pike'), json_encode($notes));

ok('rerolling a section nobody has heard of is refused', (function () use ($disk, $stub) {
    try { xeric_review_reroll($disk, ['what' => 'weather', 'endpoint' => $stub]); return false; }
    catch (Throwable $e) { return human($e->getMessage() . '.') || str_contains($e->getMessage(), 'section'); }
})());

echo "\n# the inspector\n";

// ---------------------------------------------------------------------------
// why.php's one promise: the sections it shows ARE the system message, not a
// paraphrase of it. If prompt.php ever changes shape, this fails here rather
// than misleading somebody who is trying to tune a world.
// ---------------------------------------------------------------------------

$iw = xeric_play_open('on-the-anvil');
$ih = (string)$iw['template']['cast']['characters'][0]['handle'];
$inow = xeric_clock_now($iw['db'], $iw['template']);
$imsgs = xeric_prompt_build($iw['template'], $iw['db'], $ih, $inow, ['memory_limit' => 12, 'history_limit' => 20]);
$isys = xeric_prompt_system_of($imsgs);
$ibuilt = xeric_why_system_sections($iw['template'], $iw['db'], $ih,
    xeric_world_rating($iw['template'], null), 12, (int)$inow['epoch']);
ok('the inspector\'s sections reconstruct the real system message byte for byte',
    $ibuilt['rebuilt'] === $isys,
    strlen($ibuilt['rebuilt']) . ' vs ' . strlen($isys));
ok('and there are several of them, each with something in it',
    count($ibuilt['sections']) >= 3
    && count(array_filter($ibuilt['sections'], fn($s) => trim($s['text']) === '')) === 0);
ok('the volatile block is in the LAST message and nowhere else',
    !str_contains($isys, 'RIGHT NOW (')
    && str_contains((string)$imsgs[count($imsgs) - 1]['content'], 'RIGHT NOW ('));

echo "\n# the decision trail\n";

// ---------------------------------------------------------------------------
// A sweep's reasoning survives the sweep. Without this, "why did that happen?"
// is unanswerable ten seconds later, which is the question a week of tuning is
// mostly made of.
// ---------------------------------------------------------------------------

$trailT = $iw['template'];
$kinds = xeric_sweep_kinds_for($trailT);
$chosen = xeric_sweep_choose($trailT, xeric_world_now($trailT, (int)$inow['epoch']), $kinds, []);
ok('the chooser comes back with its reasoning attached',
    $chosen === null || (isset($chosen['trail']) && is_array($chosen['trail'])));
if ($chosen !== null) {
    $tr = $chosen['trail'];
    ok('naming the kinds this world can produce at all', ($tr['kinds_armed'] ?? []) !== []);
    ok('and where everybody was standing', ($tr['standing'] ?? []) !== []);
    $chose = (string)($tr['chose'] ?? '');
    ok('and why those people, in words rather than a code',
        $chose !== '' && str_contains($chose, ' ') && !str_contains($chose, '{')
        && !str_contains($chose, '_'), $chose);
}

$fakeEvent = ['id' => 4242, 'kind' => 'routine', 'on_spine' => false, 'why' => 'both on shift at the diner',
              'place' => null, 'participants' => ['a', 'b'], 'usage' => ['ms' => 1200, 'attempts' => 1],
              'trail' => ['kinds_armed' => ['routine'], 'chose' => 'both on shift at the diner',
                          'standing' => [['handle' => 'a', 'name' => 'A', 'where' => 'the diner', 'free' => false]]]];
xeric_play_keep_trail($iw['db'], $fakeEvent);
$kept = xeric_world_state_get($iw['db'], 'why:event:4242');
ok('a trail is written into the world it happened in', $kept !== null);
$back = json_decode((string)$kept, true);
ok('and reads back whole', is_array($back) && ($back['why'] ?? '') === 'both on shift at the diner'
    && ($back['trail']['standing'][0]['name'] ?? '') === 'A');
ok('an event with no trail is simply absent, never an error',
    xeric_world_state_get($iw['db'], 'why:event:999999') === null);

echo "\n# what a stranger is handed\n";

// ---------------------------------------------------------------------------
// THE FORK CARRIES THE PAST AND NOT THE EVENING
//
// The guarantee already covered was that the SOURCE does not move, which was
// true and is not the bug. The bug was what came along in the copy: the owner's
// conversations and their own typed sentences, arriving in a stranger's cast
// panel as unread dots. So a fork takes the baked history and the lived world
// state, and leaves the evening behind.
// ---------------------------------------------------------------------------

$lived = xeric_web_worlds_dir() . '/lived-in';
@mkdir($lived, 0775, true);
@copy($srcWorld . '/world-template.json', $lived . '/world-template.json');
@copy($srcWorld . '/seed.json', $lived . '/seed.json');

$LT   = xeric_world_load($lived . '/world-template.json');
$lh   = (string)$LT['cast']['characters'][0]['handle'];
$cdb  = xeric_state_open($lived . '/world.db');
xeric_state_seed($cdb, $LT, xeric_state_time());
xeric_event_add($cdb, 'happened before anybody arrived', time(), null, [], 'the shared past');
$livedEvents = xeric_events_count($cdb);

$conv = xeric_conversation_for($cdb, $lh);
xeric_message_append($cdb, $conv, 'user', null, 'this is the owner typing');
xeric_message_append($cdb, $conv, 'assistant', $lh, 'and this is what she said back');
xeric_memory_add($cdb, $lh, 'harvested out of that conversation', 'auto', []);
xeric_arc_set($cdb, $lh, 'extract.last_message_id', '2');
xeric_arc_set($cdb, $lh, 'learn.replies', '9');
xeric_arc_set($cdb, $lh, 'trust', '5');          // an ordinary arc, and not the reader's
xeric_world_state_set($cdb, 'learn.pending', 'something half-distilled');
xeric_world_state_set($cdb, 'why:event:1', '{"why":"the owner asked"}');
xeric_world_state_set($cdb, 'world_mood_drift', 'kept');
$autoBefore = (int)$cdb->query("SELECT COUNT(*) c FROM memories WHERE source='auto'")->fetchAll()[0]['c'];

$S = sid();
xeric_session_use($S);
$fk = xeric_play_open('lived-in');
ok('a stranger opening a lived-in world is forked', $fk['forked'] === true);

$fdb = $fk['db'];
$convs = (int)$fdb->query('SELECT COUNT(*) c FROM conversations')->fetchAll()[0]['c'];
$msgs  = (int)$fdb->query('SELECT COUNT(*) c FROM messages')->fetchAll()[0]['c'];
ok('the fork carries no conversations', $convs === 0, (string)$convs);
ok('and not one of the previous player\'s sentences', $msgs === 0, (string)$msgs);

$autos = (int)$fdb->query("SELECT COUNT(*) c FROM memories WHERE source='auto'")->fetchAll()[0]['c'];
ok('nor what was harvested out of them', $autos === 0, (string)$autos);
ok('but the baked past is all there', xeric_events_count($fdb) >= $livedEvents,
    xeric_events_count($fdb) . ' vs ' . $livedEvents);
ok('and the seeded memories with it — the fork is not an empty world',
    xeric_memories_count($fdb, $lh) > 0 && $autoBefore > 0,
    xeric_memories_count($fdb, $lh) . ' seeded, ' . $autoBefore . ' harvested before');

ok('the machinery of somebody else\'s reading is gone',
    xeric_arc_get($fdb, $lh, 'extract.last_message_id') === null
    && xeric_arc_get($fdb, $lh, 'learn.replies') === null
    && xeric_world_state_get($fdb, 'learn.pending') === null
    && xeric_world_state_get($fdb, 'why:event:1') === null);
ok('and the world\'s own state is not', xeric_arc_get($fdb, $lh, 'trust') === '5'
    && xeric_world_state_get($fdb, 'world_mood_drift') === 'kept',
    (string)xeric_arc_get($fdb, $lh, 'trust'));

// The original is the half that was always checked. Restated, because a fix
// that emptied the source would pass every assertion above.
$canon3 = xeric_state_open($lived . '/world.db');
ok('and the original still holds all of it',
    (int)$canon3->query('SELECT COUNT(*) c FROM messages')->fetchAll()[0]['c'] === 2
    && xeric_events_count($canon3) === $livedEvents);

// ---------------------------------------------------------------------------
// THE SNAPSHOT IS ATOMIC. The old fallback copied the SOURCE over a live copy
// when two first-opens raced, which pairs a half-written db with a foreign -wal.
// ---------------------------------------------------------------------------

$snapSrc = $lived . '/world.db';
$snapDst = $tmp . '/snap-test.db';
@unlink($snapDst);
ok('a snapshot lands', xeric_session_snapshot($snapSrc, $snapDst) === true && is_file($snapDst));
$md5one = md5_file($snapDst);
ok('and a second one is byte-identical', xeric_session_snapshot($snapSrc, $snapDst) === true
    && md5_file($snapDst) === $md5one);
ok('leaving no half-written parts behind', glob(dirname($snapDst) . '/*.part') === []);

file_put_contents($snapDst, 'A LIVE COPY SOMEBODY IS PLAYING');
xeric_session_snapshot($snapSrc, $snapDst);
ok('and it never writes over a copy that is already there',
    file_get_contents($snapDst) === 'A LIVE COPY SOMEBODY IS PLAYING');

// ---------------------------------------------------------------------------
// A COOKIELESS REQUEST MINTS NO RECORD. This is what stops a flood of GETs
// from filling the disk and walking eviction through real visitors' worlds.
// ---------------------------------------------------------------------------

reset_limits();
$_COOKIE = [];
$phantom = xeric_web_sid();
xeric_session_touch($phantom);
ok('an id nobody arrived holding gets no record on disk',
    !is_file(xeric_web_session_path($phantom)));

$real = sid();
xeric_session_touch($real);
ok('and somebody who came back with theirs does', is_file(xeric_web_session_path($real)));

// Past the address budget the record is refused too — and the cap still binds,
// because limits.php reads the address's own bucket rather than the record it
// just declined to write.
reset_limits();
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
$capN = xeric_limit_n('ip_sessions_per_day');
for ($i = 0; $i < $capN; $i++) xeric_session_touch(sid());
$overflow = sid();
xeric_session_touch($overflow);
ok('an address past its day\'s budget is written no further records',
    !is_file(xeric_web_session_path($overflow)));
$r = xeric_limit_check('message', ['sid' => $overflow]);
ok('and is still capped, with no record to read it off',
    !$r['ok'] && $r['kind'] === 'ip_sessions' && human((string)$r['message']), (string)($r['kind'] ?? ''));
ok('looking is still free — the refusal is a limit, not a locked door',
    ($r['status'] ?? 429) === 429);
reset_limits();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// ---------------------------------------------------------------------------
// SSRF. A bring-your-own-key base URL is a URL www-data will fetch on a host
// that also serves other things. Private space is refused by resolution, not
// by a string match, because a name can point anywhere.
// ---------------------------------------------------------------------------

$privates = ['http://127.0.0.1:8080', 'http://localhost:1', 'http://169.254.169.254/',
             'http://10.0.0.5', 'http://[::1]:8080', 'http://192.168.1.1',
             'http://172.16.0.1', 'http://0.0.0.0', 'http://nothing-resolves-here.invalid'];
$letThrough = [];
$sentences = true;
foreach ($privates as $base) {
    try {
        xeric_web_endpoint(['kind' => 'openai', 'base' => $base, 'key' => 'k']);
        $letThrough[] = $base;
    } catch (Throwable $e) {
        if (!human($e->getMessage())) $sentences = false;
    }
}
ok('every private address is refused a bring-your-own-key endpoint',
    $letThrough === [], implode(' ', $letThrough));
ok('and the refusal is a sentence a person can act on', $sentences);

// kind=local is the machine's OWN model. It is not a URL a visitor supplied, so
// it is never resolved and never refused — and a visitor cannot reach loopback
// by claiming to be it, because the base they send is discarded.
$cfgBase = (string)xeric_web_config()['local_base'];
$loc = xeric_web_endpoint(['kind' => 'local', 'base' => 'http://169.254.169.254/']);
ok('the machine\'s own model is configured, not submitted',
    (string)$loc['base'] === $cfgBase, (string)$loc['base']);

// `local` IS honoured where `base` is not, and the difference is who can set it:
// model.php refuses to store one unless the request came from the machine the
// server is on (xeric_web_local_editable). Assert both halves — the override
// works, and the old field is still inert — because a regression in either one
// is a request-forgery hole that looks like a settings bug.
$own = xeric_web_endpoint(['kind' => 'local', 'local' => 'http://10.1.2.3:8080',
                           'base' => 'http://169.254.169.254/']);
ok('a local address the owner set is used, and `base` is still discarded',
    (string)$own['base'] === 'http://10.1.2.3:8080', (string)$own['base']);
ok('and a junk local address falls back rather than being called',
    (string)xeric_web_endpoint(['kind' => 'local', 'local' => 'not a url'])['base'] === $cfgBase);

// `none` is a state, and it has to fail here rather than at the first request.
$noneSaid = '';
try { xeric_web_endpoint(['kind' => 'none']); } catch (Throwable $e) { $noneSaid = $e->getMessage(); }
ok('with nothing attached the endpoint refuses, by name',
    str_contains($noneSaid, 'No machine is attached'), $noneSaid);
ok('and `connected` tells the two apart',
    !xeric_web_connected(['kind' => 'none']) && !xeric_web_connected([])
    && xeric_web_connected(['kind' => 'local']) && xeric_web_connected(['kind' => 'openai']));

// ---------------------------------------------------------------------------
// THE ONE GENUINELY UNPORTABLE THING IN THE APP: starting a detached worker.
// Split into a function that only DESCRIBES the launch so both branches can be
// read on one machine — the Windows branch is otherwise untestable anywhere the
// suite actually runs, which is how it would stay wrong.
// ---------------------------------------------------------------------------

$posix = xeric_web_spawn_cmd('/usr/bin/php', '/w/worker.php', 'job7', '/d/worker.log');
ok('posix: the process we wait on is a shell, not the worker',
    is_array($posix['cmd']) && $posix['cmd'][0] === '/bin/sh' && $posix['wait'] === true);
ok('posix: and the shell backgrounds it, so proc_close returns at once',
    str_ends_with(trim((string)$posix['cmd'][2]), '&'));
ok('posix: STDIN IS EXPLICITLY REDIRECTED — a plain `cmd &` gets /dev/null and the worker reads nothing',
    str_contains((string)$posix['cmd'][2], 'exec 3<&0;') && str_contains((string)$posix['cmd'][2], '<&3'));
ok('posix: every path is quoted, so a data dir with a space in it is not two arguments',
    str_contains((string)$posix['cmd'][2], "'/w/worker.php'")
    && str_contains((string)$posix['cmd'][2], "'/d/worker.log'"));

// setsid lives in /bin on some Linuxes and nowhere at all on macOS. It used to
// be hardcoded to /usr/bin/setsid, which silently skipped it everywhere else.
ok('posix: setsid is looked up rather than assumed, and a missing one is survivable',
    xeric_web_which('sh') !== '' && xeric_web_which('a-program-that-is-not-here') === '');

// The one assertion that matters about the key, and it holds on both branches:
// it is on a PIPE. Not argv (every account on the box can read that), not a file
// (the promise is that it never touches disk), not the environment (inherited,
// and readable from /proc).
$both = [$posix['cmd'], xeric_web_spawn_cmd('C:\\php\\php.exe', 'C:\\x\\worker.php', 'job7', 'C:\\d\\w.log', 'Windows')['cmd']];
$leaked = [];
foreach ($both as $c) {
    $flat = is_array($c) ? implode(' ', array_map('strval', $c)) : (string)$c;
    if (stripos($flat, 'key') !== false || stripos($flat, 'sk-') !== false) $leaked[] = $flat;
}
ok('NEITHER PLATFORM PUTS ANYTHING BUT A JOB ID ON THE COMMAND LINE', $leaked === [], implode(' | ', $leaked));

$win = xeric_web_spawn_cmd('C:\\php\\php.exe', 'C:\\x\\worker.php', 'job7', 'C:\\d\\w.log', 'Windows');
ok('windows: no shell — the binary is run directly, as an argv array',
    is_array($win['cmd']) && $win['cmd'][0] === 'C:\\php\\php.exe'
    && !empty($win['opts']['bypass_shell']));
ok('windows: its own console, so it does not die with the one the server holds',
    !empty($win['opts']['create_new_console']) && !empty($win['opts']['create_process_group']));
ok('windows: AND WE DO NOT WAIT ON IT — proc_close() there blocks for the whole build',
    $win['wait'] === false);

// ---------------------------------------------------------------------------
// A NOTE NEVER CARRIES THE ENDPOINT'S WORDS. llm.php puts up to 300 bytes of a
// non-JSON body into its exception, which is right for a terminal and is a
// disclosure channel once the forge turns it into a streamed progress note.
// ---------------------------------------------------------------------------

$dirty = 'cast person 2 failed: llm: HTTP 200 — <html>root:x:0:0:/root:/bin/bash</html>';
$clean = xeric_web_note_safe($dirty);
ok('a note keeps what happened', str_contains($clean, 'cast person 2 failed'));
ok('and loses what answered', !str_contains($clean, 'root:x'), $clean);
ok('the same for a banner that is not even HTTP',
    !str_contains(xeric_web_note_safe('llm: non-JSON response: SSH-2.0-OpenSSH_9.6'), 'OpenSSH'));

$njob = xeric_web_job_new();
xeric_web_job_append($njob, ['k' => 'note', 't' => 0.1, 'pass' => 'cast', 'level' => 'warn',
                             'text' => xeric_web_note_safe('llm: HTTP 401 — {"key":"sk-live-DEADBEEF"}')]);
$readBack = json_encode(xeric_web_job_read($njob));
ok('and a note round-trips through the job file without it',
    !str_contains($readBack, 'sk-live-DEADBEEF'), $readBack);

// ---------------------------------------------------------------------------
// OWNERSHIP OF THE FILE AND THE PAGE. world.php used to readfile() any world's
// template to any visitor; review.php rendered every wall and every interior to
// a stranger while gating only its JSON actions. Asserted on the VALUE of the
// private strings, never on the field names — the redaction sentence names the
// fields it removed on purpose.
// ---------------------------------------------------------------------------

$secretT = xeric_world_load($lived . '/world-template.json');
$secretT['cast']['special_roles'] = [[
    'role' => 'child', 'character' => $lh, 'walls' => ['protects_' . $lh],
    'own_bible' => true, 'must_not_know' => 'MUSTNOTKNOW_CANARY',
]];
$secretT['knowledge_walls'][] = ['key' => 'protects_' . $lh, 'audience' => ['role' => 'child'],
    'hidden' => ['cast_dossiers', 'drives', 'secrets', 'protagonist'],
    'explain' => 'EXPLAIN_CANARY', 'source' => 'model'];
$secretT['cast']['characters'][0]['drives']['pull'] = 'DRIVESPULL_CANARY';
$secretT['cast']['characters'][0]['psyche']['sore_spot'] = 'SORESPOT_CANARY';
// tells and solace are walled interiors exactly like the other three, and both
// were being handed to strangers by a redaction list that stopped at three. They
// are canaried here so the list cannot quietly shorten again.
$secretT['cast']['characters'][0]['tells'] = ['TELLS_CANARY'];
$secretT['cast']['characters'][0]['solace'] = 'SOLACE_CANARY';
$secretT['cast']['protagonist'] = ['handle' => $lh, 'arc' => 'PROTARC_CANARY', 'pressure' => 'p'];
file_put_contents($lived . '/world-template.json',
    json_encode($secretT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// The baked past is the other half of what these pages show, and it is prose
// written with the secrets in hand — so it gets canaries of its own, in the two
// places a page can print it from: the event's headline and its sentences.
$seedFile = $lived . '/seed.json';
$secretSeed = json_decode((string)file_get_contents($seedFile), true) ?: ['events' => [], 'memories' => []];
if (isset($secretSeed['events'][0])) {
    $secretSeed['events'][0]['title'] = 'SEEDTITLE_CANARY';
    $secretSeed['events'][0]['prose'] = 'SEEDPROSE_CANARY, and then nothing was the same.';
}
file_put_contents($seedFile,
    json_encode($secretSeed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$canaries = ['MUSTNOTKNOW_CANARY', 'DRIVESPULL_CANARY', 'EXPLAIN_CANARY',
             'SORESPOT_CANARY', 'TELLS_CANARY', 'SOLACE_CANARY', 'PROTARC_CANARY'];
$seedCanaries = ['SEEDTITLE_CANARY', 'SEEDPROSE_CANARY'];
// Driven in a SUBPROCESS because both pages end in exit() and both are written
// against the superglobals a request arrives in. The CLI SAPI fills neither
// $_GET nor $_COOKIE from the environment, so they are set in the bootstrap
// string ahead of the include — which is also the only way to hand each page a
// different visitor's cookie in one test run.
//
// HTTP_ACCEPT is set because xeric_play_no() answers a browser with a page and a
// client with an object, and a refusal asserted on the wrong one of those is a
// test of the other branch.
$php = PHP_BINARY;
$run = function (string $script, string $sidCookie, array $get = ['w' => 'lived-in'],
                 string $accept = 'text/html') use ($php, $tmp): string {
    $boot = '$_GET = ' . var_export($get, true) . ';'
        . ' $_COOKIE = [' . var_export(XERIC_WEB_COOKIE, true) . ' => ' . var_export($sidCookie, true) . '];'
        . ' $_SERVER["REQUEST_METHOD"] = "GET";'
        . ' $_SERVER["HTTP_ACCEPT"] = ' . var_export($accept, true) . ';'
        . ' require ' . var_export(dirname(__DIR__) . '/' . $script, true) . ';';
    $cmd = 'XERIC_DATA_DIR=' . escapeshellarg($tmp)
        . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
        . ' ' . escapeshellarg($php) . ' -d error_reporting=0 -r ' . escapeshellarg($boot) . ' 2>&1';
    return (string)shell_exec($cmd);
};

xeric_session_claim('lived-in', $A);
$strangerJson = $run('world.php', $B);
$leaked = array_values(array_filter($canaries, fn($c) => str_contains($strangerJson, $c)));
ok('world.php hands a stranger no private string of somebody else\'s world',
    $leaked === [], implode(',', $leaked));
ok('and says so rather than 404ing at them',
    str_contains($strangerJson, '_redacted') || str_contains($strangerJson, 'redacted'),
    mb_substr($strangerJson, 0, 120));
ok('while the places are still there — the shape of it is not the secret',
    str_contains($strangerJson, '"places"'));

$ownerJson = $run('world.php', $A);
$ownerHas = array_values(array_filter($canaries, fn($c) => str_contains($ownerJson, $c)));
ok('and the owner still gets their own file whole',
    count($ownerHas) === count($canaries), implode(',', $ownerHas));

$seedShown = array_values(array_filter($seedCanaries, fn($c) => str_contains($strangerJson, $c)));
ok('and the baked past is not in the projection at all', $seedShown === [], implode(',', $seedShown));

$strangerSeed = $run('world.php', $B, ['w' => 'lived-in', 'f' => 'seed']);
$seedLeak = array_values(array_filter($seedCanaries, fn($c) => str_contains($strangerSeed, $c)));
ok('world.php?f=seed hands a stranger no sentence of the baked past',
    $seedLeak === [], implode(',', $seedLeak));
$ownerSeed = $run('world.php', $A, ['w' => 'lived-in', 'f' => 'seed']);
ok('and the owner still gets that file whole',
    !array_diff($seedCanaries, array_filter($seedCanaries, fn($c) => str_contains($ownerSeed, $c))));

$strangerPage = $run('review.php', $B);
$pageLeak = array_values(array_filter($canaries, fn($c) => str_contains($strangerPage, $c)));
ok('review.php renders a stranger no wall and no interior', $pageLeak === [], implode(',', $pageLeak));
ok('and tells them whose world it is instead',
    str_contains($strangerPage, 'different browser'), mb_substr($strangerPage, 0, 160));

// ---------------------------------------------------------------------------
// THE INSPECTOR, WHICH NEVER ASKED WHOSE WORLD IT WAS. why.php prints the exact
// assembled system message — every secret, every pull, the whole decision trail
// — and it cannot be made safe by redacting, only by refusing. All three of its
// query shapes are driven, because the page has three modes and a gate that
// covers one of them is not a gate. The second half is the one that matters:
// the wrong fix here is redacting the tuning tool into uselessness.
// ---------------------------------------------------------------------------

$whyShapes = [
    'the page itself'          => ['w' => 'lived-in'],
    'one character\'s prompt'  => ['w' => 'lived-in', 'h' => $lh],
    'one event\'s decision'    => ['w' => 'lived-in', 'e' => '1'],
];
$whyLeak = [];
$whySaidNo = true;
foreach ($whyShapes as $shape => $get) {
    $out = $run('why.php', $B, $get);
    foreach (array_merge($canaries, $seedCanaries) as $c) {
        if (str_contains($out, $c)) $whyLeak[] = $shape . ':' . $c;
    }
    if (!str_contains($out, 'not yours to read')) $whySaidNo = false;
}
ok('why.php shows a stranger nothing, in any of its three shapes',
    $whyLeak === [], implode(' ', $whyLeak));
ok('and refuses in a sentence rather than a blank page', $whySaidNo);

$whyOwner = $run('why.php', $A, ['w' => 'lived-in', 'h' => $lh]);
ok('while the owner still reads the real thing she is handed',
    str_contains($whyOwner, 'DRIVESPULL_CANARY') && str_contains($whyOwner, 'SORESPOT_CANARY')
    && str_contains($whyOwner, 'SOLACE_CANARY'),
    mb_substr($whyOwner, 0, 200));

// ---------------------------------------------------------------------------
// THE RESULT SCREEN IS THE SEED IN SENTENCES. forge.php?w= printed every baked
// event to whoever asked, which is the same material world.php?f=seed refuses
// outright. Two pages disagreeing about one secret is how the open one goes
// unnoticed, so they are asserted together.
// ---------------------------------------------------------------------------

$strangerResult = $run('forge.php', $B);
$resultLeak = array_values(array_filter(array_merge($canaries, $seedCanaries),
    fn($c) => str_contains($strangerResult, $c)));
ok('forge.php\'s result screen is refused a stranger', $resultLeak === [], implode(',', $resultLeak));
ok('and offers them the two doors that are open to them',
    str_contains($strangerResult, 'Play your own copy') && str_contains($strangerResult, 'Forge one that is yours'),
    mb_substr($strangerResult, 0, 200));

$ownerResult = $run('forge.php', $A);
ok('and the owner still lands on their own world\'s past',
    str_contains($ownerResult, 'SEEDTITLE_CANARY'), mb_substr($ownerResult, 0, 200));

// A REFUSAL LEAVES NOTHING BEHIND. The gate runs before xeric_play_open()
// precisely so that turning a stranger away does not first fork a copy of the
// world into their session — a refusal that costs a database is a refusal that
// can be used to fill a disk.
$noFork = sid();
foreach ([['why.php', ['w' => 'lived-in']], ['why.php', ['w' => 'lived-in', 'h' => $lh]],
          ['world.php', ['w' => 'lived-in']], ['world.php', ['w' => 'lived-in', 'f' => 'seed']],
          ['forge.php', ['w' => 'lived-in']]] as [$script, $get]) {
    $run($script, $noFork, $get);
}
ok('and a refused visitor has no forked database to show for it',
    glob(xeric_session_db_dir($noFork) . '/*.db') === [],
    implode(',', array_map('basename', glob(xeric_session_db_dir($noFork) . '/*.db') ?: [])));

// ---------------------------------------------------------------------------
// THE TWO FUNCTIONS THE FOUR PAGES NOW SHARE. Unit level: the redaction's list
// and the refusal's two shapes. Four pages each keeping their own copy of this
// rule is how one of them came to have none, so what is asserted here is that
// there is one copy and it is complete.
// ---------------------------------------------------------------------------

[$red, $goneFields] = xeric_play_redact($secretT);
$stillThere = [];
foreach ((array)$red['cast']['characters'] as $c) {
    foreach (xeric_play_interior_fields() as $f) if (isset($c[$f])) $stillThere[] = $f;
}
ok('the redaction takes every interior off every character',
    $stillThere === [], implode(',', array_unique($stillThere)));
ok('and the field list is the WALL system\'s, voice included — the hand copy that drifted is gone',
    in_array('voice', xeric_play_interior_fields(), true)
    && xeric_play_interior_fields() === (array)(xeric_wall_interior_fields()['characters'] ?? []));

// THE COUPLING PROPERTY, the drift-killer: nothing xeric_wall_interiors()
// indexes survives a redaction. A field added to the indexer but forgotten by
// the redaction — the exact bug `voice` was, and `tells`/`solace` before it —
// turns this red the same day.
$redJson = json_encode($red, JSON_UNESCAPED_UNICODE);
$interiorLeaks = [];
foreach (xeric_wall_interiors($secretT) as $ipath => $strings) {
    foreach ($strings as $s) {
        $s = trim((string)$s);
        if (mb_strlen($s) >= 12 && str_contains($redJson, mb_substr($s, 0, 48))) {
            $interiorLeaks[] = $ipath;
        }
    }
}
ok('the redaction and the wall indexer agree BY PROPERTY: no indexed interior survives',
    $interiorLeaks === [], implode(',', array_unique($interiorLeaks)));
ok('and the wall\'s hidden list, its explanation, the must_not_know and the arc',
    !isset($red['knowledge_walls'][count($red['knowledge_walls']) - 1]['hidden'])
    && !isset($red['knowledge_walls'][count($red['knowledge_walls']) - 1]['explain'])
    && !isset($red['cast']['special_roles'][0]['must_not_know'])
    && !isset($red['cast']['protagonist']['arc'])
    && !isset($red['cast']['protagonist']['pressure']));
ok('while the shape of the world is untouched — that is what a shelf is for',
    isset($red['places']) && $red['places'] === $secretT['places']
    && count((array)$red['cast']['characters']) === count((array)$secretT['cast']['characters']));
ok('and it says which fields it took, so the file is honest about being partial',
    in_array('characters[].tells', $goneFields, true) && in_array('characters[].solace', $goneFields, true)
    && in_array('protagonist.arc', $goneFields, true), implode(',', $goneFields));

// The redaction's own cover story stays: `shown_as` is what a walled viewer is
// SUPPOSED to be told, so removing it would be redacting the wrong direction.
ok('the cover story survives the redaction',
    (string)($red['knowledge_walls'][0]['shown_as'] ?? '') === (string)($secretT['knowledge_walls'][0]['shown_as'] ?? ''));

// No raw JSON on a screen, no HTML down a fetch(): one function decides, and
// both branches are driven because a page is the branch that gets forgotten.
$noJson = $run('why.php', $B, ['w' => 'lived-in'], 'application/json');
$noHtml = $run('why.php', $B, ['w' => 'lived-in'], 'text/html');
ok('a client asking for an object is refused with an object',
    is_array(json_decode(trim($noJson), true)) && str_contains($noJson, '"error"'),
    mb_substr($noJson, 0, 120));
ok('and a person asking for a page is refused with a page',
    str_contains($noHtml, '<!doctype html') && str_contains($noHtml, '<h1>')
    && json_decode(trim($noHtml), true) === null, mb_substr($noHtml, 0, 120));

// review.php's redraw endpoint is every wall and every interior in one JSON
// object. It is a GET, so it is the one action a stranger can reach by typing.
$section = $run('review.php', $B, ['w' => 'lived-in', 'a' => 'section', 'sec' => 'cast'],
                'application/json');
$secLeak = array_values(array_filter($canaries, fn($c) => str_contains($section, $c)));
ok('review.php\'s section redraw is refused a stranger too', $secLeak === [], implode(',', $secLeak));

echo "\n# what is a page and what is not\n";

// ---------------------------------------------------------------------------
// THE DOCROOT'S OWN SOURCE. Everything protecting lib/, the workers and the
// libraries used to sit inside <IfModule mod_rewrite.c>, which makes a
// protection advice: a host that never loaded the module skipped the whole block
// in silence and served the files. The patterns are read out of the file and run
// against request paths here, because PHP's preg is PCRE and so is Apache's —
// the two failure modes a text assertion cannot see are a library file nobody
// added to the list, and a pattern edited into denying a real page.
// ---------------------------------------------------------------------------

$htPath = dirname(__DIR__) . '/.htaccess';
$htRaw = (string)file_get_contents($htPath);
ok('the deployed directory carries its own rules', $htRaw !== '', $htPath);

// The comments are stripped first and every assertion below is on DIRECTIVES.
// The file explains at length what it stopped doing, and a test that reads the
// explanation as the thing itself would fail on a reworded paragraph and pass on
// a re-added rule — exactly backwards.
$ht = implode("\n", array_filter(explode("\n", $htRaw),
    fn($l) => !str_starts_with(ltrim($l), '#')));

ok('no protection in .htaccess is conditional on a module being loaded',
    preg_match('/<IfModule\s+mod_rewrite/i', $ht) === 0 && !str_contains($ht, 'RewriteRule'));

// The <IfModule> blocks that remain are the streaming HEADERS — nice-to-have on
// a stream, never a denial. Stripping them proves no denial hid inside one.
$unconditional = preg_replace('/<IfModule\b.*?<\/IfModule>/s', '', $ht);
ok('and every denial survives having the conditional blocks cut out of the file',
    substr_count($unconditional, 'Require all denied') === 4, (string)substr_count($unconditional, 'Require all denied'));

preg_match_all('/<If\s+"%\{REQUEST_URI\}\s*=~\s*m#(.+?)#"\s*>/', $unconditional, $htm);
$patterns = $htm[1];
ok('the four denials are readable as four expressions', count($patterns) === 4, implode(' ', $patterns));

$denied = function (string $uri) use ($patterns): bool {
    foreach ($patterns as $p) if (preg_match('#' . $p . '#', $uri) === 1) return true;
    return false;
};

// Deployed under a subdirectory and with PATH_INFO hung off the end, because the
// patterns are anchored (^|/) and closed (/|$) for exactly those two shapes.
$mustDeny = ['/lib/forge.php', '/forge/lib/interview.json', '/forge/lib/engine/state.php',
             '/worker.php', '/tick-worker.php', '/reroll-worker.php', '/addchar-worker.php',
             '/photo-worker.php', '/story-worker.php', '/forge/worker.php',
             '/heart.php', '/router.php',
             '/worker.php/extra', '/boot.php', '/ui.php', '/play-lib.php', '/review-lib.php',
             '/why-lib.php', '/session.php', '/limits.php', '/queue.php',
             '/tests/demo-test.php', '/forge/tests/demo-test.php'];
$mustServe = ['/', '/forge.php', '/play.php', '/build.php', '/progress.php', '/review.php',
              '/why.php', '/world.php', '/say.php', '/tick.php', '/where.php', '/fate.php', '/tile.php', '/model.php', '/notify.php',
              '/power.php', '/addchar.php', '/book.php', '/watch.php', '/photo.php',
              '/forge/forge.php', '/forge/play.php'];

$served = array_values(array_filter($mustDeny, fn($u) => !$denied($u)));
ok('every include, worker and test path is denied', $served === [], implode(' ', $served));
$blocked = array_values(array_filter($mustServe, fn($u) => $denied($u)));
ok('and no page of the app is caught by the same patterns', $blocked === [], implode(' ', $blocked));

// The assertion that fails the day somebody adds forge/web/foo-lib.php: the deny
// list is a hand-written enumeration, and a hand-written enumeration goes stale.
$pages = ['forge.php', 'play.php', 'build.php', 'progress.php', 'review.php',
          'why.php', 'world.php', 'say.php', 'tick.php', 'where.php', 'fate.php', 'tile.php', 'model.php',
          'notify.php', 'power.php', 'addchar.php', 'book.php', 'watch.php', 'photo.php',
          'debrief.php', 'join.php'];
$uncovered = [];
foreach (glob(dirname(__DIR__) . '/*.php') ?: [] as $f) {
    $b = basename($f);
    if (in_array($b, $pages, true)) continue;
    if (!$denied('/' . $b)) $uncovered[] = $b;
}
ok('and every file in forge/web that is not a page is on the list',
    $uncovered === [], implode(' ', $uncovered));

// ---------------------------------------------------------------------------
// CAN EVERY PAGE REACH THE FUNCTIONS IT CALLS?
//
// build.php required boot.php and called xeric_web_model(), which lives in
// play-lib.php. PHP does not care until the line runs, so nothing failed at
// deploy, nothing failed on any page that merely LOADED, and php -l was clean —
// it failed on the one request that mattered, with "Call to undefined function",
// after the browser had already switched to "Building your world". The forge was
// dead for every visitor and the screen said it was working.
//
// A whole class of bug that a linter will not find and a page test will not
// reach, because it only appears on the path that spends three minutes.
$webDir = dirname(__DIR__);
$libFiles = ['play-lib.php', 'review-lib.php', 'why-lib.php', 'boot.php', 'ui.php',
             'session.php', 'limits.php', 'queue.php', 'pdf.php'];

$declaredIn = [];
foreach (glob($webDir . '/*.php') ?: [] as $f) {
    preg_match_all('/^function (xeric_\w+)\(/m', (string)file_get_contents($f), $m);
    foreach ($m[1] as $fn) $declaredIn[$fn] = basename($f);
}

// ONLY __DIR__-RELATIVE INCLUDES COUNT. Following any quoted .php path resolves
// boot.php's `$XERIC_LIB . '/engine/notify.php'` against forge/web/notify.php —
// a different file with the same name that DOES include play-lib — and the check
// then passes on the exact code it was written to catch.
$reach = function (string $file, array &$seen) use (&$reach, $webDir): void {
    $p = $webDir . '/' . $file;
    if (isset($seen[$file]) || !is_file($p)) return;
    $seen[$file] = true;
    preg_match_all('#__DIR__\s*\.\s*\'/([\w.-]+\.php)\'#', (string)file_get_contents($p), $m);
    foreach ($m[1] as $inc) $reach($inc, $seen);
};

$unreachable = [];
foreach (glob($webDir . '/*.php') ?: [] as $f) {
    $base = basename($f);
    if (in_array($base, $libFiles, true)) continue;
    $src  = (string)file_get_contents($f);
    $seen = [];
    $reach($base, $seen);
    preg_match_all('/\b(xeric_\w+)\s*\(/', $src, $m);
    foreach (array_unique($m[1]) as $fn) {
        if (!isset($declaredIn[$fn]) || $declaredIn[$fn] === $base) continue;
        if (!isset($seen[$declaredIn[$fn]])) $unreachable[] = "$base needs $fn from {$declaredIn[$fn]}";
    }
}
ok('every page can reach every function it calls',
    $unreachable === [], implode(' | ', array_slice($unreachable, 0, 6)));

// ---------------------------------------------------------------------------
// THE BRING-YOUR-OWN-KEY BUTTON. The bug was purely lexical position: all three
// of these were declared INSIDE reroll(), so the button was dead until the first
// reroll ran, every reroll wiped the key before building its request body, and
// each one added another click handler. Asserted on source order, because that
// is what the bug was and there is no browser here to press it in.
// ---------------------------------------------------------------------------

$rv = (string)file_get_contents(dirname(__DIR__) . '/review.php');
$atReroll = strpos($rv, 'function reroll(btn)');
// The BYO variable is gone: the chooser picks a ROW of the visitor's machines
// and the key is read out of its field at request time, so there is no longer
// anything holding a key between rerolls. What still has to hold is the shape
// the old bug broke — one declaration, one binding, both above the function that
// spends them, so a fourth press cannot stack a fourth handler.
ok('the chosen machine lives at page scope, above the function that spends it',
    strpos($rv, 'var pickAt = ENGINE_AT;') !== false
    && strpos($rv, 'var pickAt = ENGINE_AT;') < $atReroll);
ok('so does the button binding', strpos($rv, "\$('#usekey')") !== false
    && strpos($rv, "\$('#usekey')") < $atReroll);
ok('and the model the next reroll will use',
    strpos($rv, 'function rerollModel()') !== false && strpos($rv, 'function rerollModel()') < $atReroll);
ok('one declaration and one binding, so a handler cannot accumulate',
    substr_count($rv, 'var pickAt') === 1 && substr_count($rv, "\$('#usekey')") === 1);
// AND NOTHING ASKS FOR A KEY IN A BROWSER DIALOG ANY MORE. Three prompt() calls
// in a row was the old way to name a machine: typed from memory, unverifiable,
// and stacking another set every time the button was pressed.
ok('the reroll chooser asks for nothing in a modal dialog',
    !str_contains($rv, 'prompt(') && !str_contains($rv, 'alert('));
// THE SENTENCE ITSELF IS NOT THE POINT, and pinning it here meant the test had
// to be edited every time the copy was. What must hold is that the markup and
// the script say the SAME thing: the JS resets the button to its default label
// after somebody backs out of the key prompt, and if that string drifts from the
// rendered one the button silently renames itself mid-session.
preg_match('/id="usekey"[^>]*>([^<]+)</s', $rv, $lm);
preg_match("/var LOCAL_LABEL = '([^']+)'/", $rv, $jm);
ok('the button\'s server-rendered label and the JS one are the same sentence',
    isset($lm[1], $jm[1]) && trim($lm[1]) !== '' && trim($lm[1]) === trim($jm[1]),
    trim($lm[1] ?? '(none)') . ' vs ' . trim($jm[1] ?? '(none)'));
ok('and the key is never written anywhere that outlives the page',
    !str_contains($rv, 'localStorage') && !str_contains($rv, 'sessionStorage')
    && !str_contains($rv, 'document.cookie'));

// ---------------------------------------------------------------------------
// WHOSE STORY THIS IS, END TO END. The engine's guarantee is asserted in
// render-test against every forged world on the machine; this is the same claim
// one layer up, through the prompt a protected character actually speaks from —
// which is the thing that would be shipped, and the only place the wall, the
// bible, the RIGHT NOW block and the lessons all meet.
// ---------------------------------------------------------------------------

$arcT = xeric_world_load($lived . '/world-template.json');
$arcT['user']['centrality'] = 'side';
$arcT['cast']['protagonist'] = ['handle' => $lh, 'arc' => 'ARC_CANARY', 'pressure' => 'PRESSURE_CANARY'];

// The world this suite copies out of the repo may have been forged before the
// privacy baseline existed, so the baseline is written in here rather than
// assumed — otherwise "a walled cast member" is not walled and the claim below
// is about nothing. One wall per orbit, exactly as the forge writes it.
$orbitsSeen = [];
foreach ($arcT['cast']['characters'] as $c) {
    $o = (string)($c['orbit'] ?? '');
    if ($o === '' || isset($orbitsSeen[$o])) continue;
    $orbitsSeen[$o] = true;
    $arcT['knowledge_walls'][] = [
        'key' => 'privacy_' . $o, 'audience' => ['orbit' => $o],
        'hidden' => ['cast_dossiers', 'drives', 'secrets'],
        'shown_as' => 'You know these people the way anyone knows the people around them.',
        'explain' => 'Nobody in ' . $o . ' can read anyone else\'s private interior.',
        'source' => 'baseline',
    ];
}
file_put_contents($lived . '/world-template.json',
    json_encode($arcT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$P = sid();
xeric_session_use($P);
$pw = xeric_play_open('lived-in');
$pnow = xeric_clock_now($pw['db'], $pw['template']);
$flat = function (array $msgs): string {
    return implode("
", array_map(fn($m) => (string)$m['content'], $msgs));
};

$protectedPrompt = $flat(xeric_prompt_build($pw['template'], $pw['db'], $lh, $pnow));
ok('the protected character\'s own prompt carries no arc and no pressure',
    !str_contains($protectedPrompt, 'ARC_CANARY') && !str_contains($protectedPrompt, 'PRESSURE_CANARY'));
ok('and not the section either — in her world nothing is moving',
    !str_contains($protectedPrompt, 'WHOSE STORY THIS IS'));

$otherHandle = '';
foreach ($pw['template']['cast']['characters'] as $c) {
    if ((string)$c['handle'] !== $lh) { $otherHandle = (string)$c['handle']; break; }
}
if ($otherHandle !== '') {
    $castPrompt = $flat(xeric_prompt_build($pw['template'], $pw['db'], $otherHandle, $pnow));
    ok('an ordinary walled cast member reads the lean and not the arc',
        str_contains($castPrompt, 'WHOSE STORY THIS IS')
        && !str_contains($castPrompt, 'ARC_CANARY')
        && !str_contains($castPrompt, 'PRESSURE_CANARY'));
}

$strangerPrompt = $flat(xeric_prompt_build($pw['template'], $pw['db'], 'nobody_by_that_name', $pnow));
ok('and an unresolvable speaker fails closed here too',
    !str_contains($strangerPrompt, 'ARC_CANARY') && !str_contains($strangerPrompt, 'WHOSE STORY THIS IS'));

// ---------------------------------------------------------------------------
// THE SESSION RECORD IS EDITED UNDER A LOCK. A worker's world claim used to be
// droppable by an ordinary page load landing between a read and its write.
// ---------------------------------------------------------------------------

$le = sid();
xeric_session_touch($le);
$ret = xeric_web_session_edit(function (array &$s): string {
    $s['own'] = array_merge((array)($s['own'] ?? []), ['first']);
    return 'the callback speaks';
}, $le);
ok('an edit returns what the callback returned', $ret === 'the callback speaks', (string)$ret);
xeric_web_session_edit(function (array &$s): void {
    $s['own'] = array_merge((array)($s['own'] ?? []), ['second']);
}, $le);
$rec2 = xeric_web_session_read($le);
ok('and the second edit saw the first one\'s write',
    ($rec2['own'] ?? []) === ['first', 'second'], json_encode($rec2['own'] ?? []));

echo "\n# limits, under contention\n";

// ---------------------------------------------------------------------------
// THE CHECK CHARGES AT THE DOOR. Read-then-write let eight parallel POSTs all
// pass a cap of five; the seat is taken by the check and given back by the
// shutdown when the work never happened.
// ---------------------------------------------------------------------------

reset_limits();
$mid = sid();
$msgCap = xeric_limit_n('messages_per_hour');
$passed = 0;
for ($i = 0; $i < $msgCap + 3; $i++) {
    if (xeric_limit_check('message', ['sid' => $mid])['ok']) $passed++;
    xeric_limit_keep('msg-' . $mid);      // the work happened: keep the seat
}
ok('a cap of ' . $msgCap . ' lets exactly ' . $msgCap . ' through, checked in one process',
    $passed === $msgCap, (string)$passed);

reset_limits();
$giveBack = sid();
xeric_limit_check('message', ['sid' => $giveBack]);
xeric_limit_give_back();
ok('a seat the work never used comes back',
    xeric_limit_hits('msg-' . $giveBack, XERIC_LIMIT_HOUR, time())['count'] === 0,
    (string)xeric_limit_hits('msg-' . $giveBack, XERIC_LIMIT_HOUR, time())['count']);

xeric_limit_check('message', ['sid' => $giveBack]);
xeric_limit_keep('msg-' . $giveBack);
xeric_limit_give_back();
ok('and one the work DID use does not',
    xeric_limit_hits('msg-' . $giveBack, XERIC_LIMIT_HOUR, time())['count'] === 1,
    (string)xeric_limit_hits('msg-' . $giveBack, XERIC_LIMIT_HOUR, time())['count']);

// A forge refused by the ADDRESS budget must not also spend one of this
// visitor's own five — they never got anything for it.
reset_limits();
$_SERVER['REMOTE_ADDR'] = '198.51.100.44';
$ipCap = xeric_limit_n('ip_forges_per_day');
for ($i = 0; $i < $ipCap; $i++) {
    $s = sid();
    xeric_limit_check('forge', ['sid' => $s]);
    xeric_limit_keep('forge-' . $s);
    xeric_limit_keep('ipforge-198.51.100.44');
}
$unlucky = sid();
$fr = xeric_limit_check('forge', ['sid' => $unlucky]);
ok('a forge refused by the address budget is refused', !$fr['ok'], json_encode($fr));
ok('and costs the visitor none of their own five',
    xeric_limit_hits('forge-' . $unlucky, XERIC_LIMIT_DAY, time())['count'] === 0,
    (string)xeric_limit_hits('forge-' . $unlucky, XERIC_LIMIT_DAY, time())['count']);
reset_limits();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// The trusted-hop list. Getting this wrong in either direction is a cap that
// binds nobody (believe any header) or one that binds everybody as one visitor.
ok('a Cloudflare address is inside its range', xeric_limit_in_cidr('172.68.1.5', '172.64.0.0/13'));
ok('and a neighbouring one is not', !xeric_limit_in_cidr('173.68.1.5', '172.64.0.0/13'));
ok('both sides of a /22 boundary', xeric_limit_in_cidr('131.0.72.0', '131.0.72.0/22')
    && xeric_limit_in_cidr('131.0.75.255', '131.0.72.0/22')
    && !xeric_limit_in_cidr('131.0.76.0', '131.0.72.0/22'));
ok('v6 ranges work too', xeric_limit_in_cidr('2606:4700::1', '2606:4700::/32'));
ok('a v4 address is never inside a v6 range', !xeric_limit_in_cidr('172.68.1.5', '2606:4700::/32'));
ok('and junk is never inside anything', !xeric_limit_in_cidr('not-an-address', '172.64.0.0/13')
    && !xeric_limit_in_cidr('172.68.1.5', 'not-a-range'));

$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';
ok('a header from an untrusted hop is not believed', xeric_limit_ip_raw() === '198.51.100.9',
    xeric_limit_ip_raw());
$_SERVER['REMOTE_ADDR'] = '172.68.1.5';
ok('and one from a trusted hop is', xeric_limit_ip_raw() === '9.9.9.9', xeric_limit_ip_raw());
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-address';
ok('unless it is not an address at all', xeric_limit_ip_raw() === '172.68.1.5', xeric_limit_ip_raw());
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

echo "\n# the queue, when somebody wants all of it\n";

// ---------------------------------------------------------------------------
// One person with eight tabs used to own the whole line and ~56 minutes of GPU.
// ---------------------------------------------------------------------------

@unlink(xeric_queue_path());
@unlink(xeric_queue_drain_path());

$greedy = 'greedy01';
$held = [];
for ($i = 0; $i < XERIC_QUEUE_PER_VISITOR; $i++) {
    $why = null;
    $tk = xeric_queue_join('say', $greedy, $why);
    if ($tk !== '') $held[] = $tk;
}
ok('one visitor may hold ' . XERIC_QUEUE_PER_VISITOR . ' places in the line',
    count($held) === XERIC_QUEUE_PER_VISITOR, (string)count($held));

$why = null;
$refused = xeric_queue_join('say', $greedy, $why);
ok('and not one more', $refused === '');
ok('the refusal knows it is theirs, not the machine\'s',
    is_array($why) && ($why['kind'] ?? '') === 'yours', json_encode($why));
ok('and says so in a sentence, with a time on it',
    is_array($why) && human((string)$why['message']) && (int)($why['retry_after'] ?? 0) > 0);

$otherTag = xeric_queue_join('say', 'someone02', $why2);
ok('somebody else is unaffected by all of that', $otherTag !== '');

foreach ($held as $tk) xeric_queue_leave($tk);
xeric_queue_leave($otherTag);

// A holder past the hard cap that still has the flock is BUSY. The reap used to
// null the record while the lock was held, so the queue said "free" to a line
// nobody in it could move.
@unlink(xeric_queue_path());
$hTicket = xeric_queue_join('forge', 'holder01');
$hold = xeric_queue_try($hTicket);
if ($hold['ok'] ?? false) {
    xeric_queue_edit(function (array &$state, int $now): void {
        if (isset($state['holder'])) $state['holder']['at'] = $now - XERIC_QUEUE_HOLD_MAX - 300;
    });
    $st = xeric_queue_status();
    ok('an overrun holder that still has the lock leaves the queue busy', ($st['busy'] ?? false) === true);
    ok('and the line is still told to wait for it', (int)($st['eta'] ?? 0) > 0, json_encode($st));

    $behind = xeric_queue_join('say', 'behind01');
    $wh = xeric_queue_where($behind);
    ok('somebody behind them is told there is somebody ahead', (int)($wh['ahead'] ?? 0) > 0,
        json_encode($wh));
    xeric_queue_leave($behind);
    xeric_queue_release($hold['hold']);
} else {
    ok('an overrun holder that still has the lock leaves the queue busy', false, 'could not take the hold');
}

// The wording of a stop, which is worded from what the hold was FOR: "the rest
// of those hours passed quietly" is true of a skip and a lie about a build.
$sr1 = xeric_queue_stop_reason(['what' => 'forge', 'at' => time() - 1000]);
$sr2 = xeric_queue_stop_reason(['what' => 'tick', 'at' => time() - 1000]);
ok('a stopped build promises only that nothing half-built was written',
    str_contains($sr1, 'nothing half-built') && !str_contains($sr1, 'those hours'), $sr1);
ok('a stopped skip keeps the hours it already walked',
    str_contains($sr2, 'those hours'), $sr2);
ok('and neither ends in a full stop — the caller adds one',
    !str_ends_with($sr1, '.') && !str_ends_with($sr2, '.') && human($sr1 . '.') && human($sr2 . '.'));

// And when it was the owner taking their machine back, it says so — which is a
// different sentence from "you ran out of time" and the only one that is true.
@touch(xeric_queue_drain_path());
$sd1 = xeric_queue_stop_reason(['what' => 'forge', 'at' => time() - 10]);
$sd2 = xeric_queue_stop_reason(['what' => 'tick', 'at' => time() - 10]);
ok('the owner taking the GPU back is named as that, mid-build',
    str_contains($sd1, 'owner') && str_contains($sd1, 'mid-build'), $sd1);
ok('and mid-skip', str_contains($sd2, 'mid-skip'), $sd2);
@unlink(xeric_queue_drain_path());

@unlink(xeric_queue_path());

echo "\n# the shared instance, raced from real processes\n";

// ---------------------------------------------------------------------------
// Everything above drives the locks from one process. This section pays for
// real ones, because every bug it pins LOOKED fixed from one process: eight
// parallel POSTs against a cap of five is the shape the review arrived in, and
// a sequential loop cannot lose that race no matter how broken the lock is.
// The trick that makes a race a race is the start flag — process start-up is
// milliseconds apart, so every child spins on one file and the parent drops it
// when all of them are already standing at the line.
// ---------------------------------------------------------------------------

/** A child's code: the sandbox it must stay in, the barrier, then the body. */
function mp_child(string $body): string
{
    // argv: 1 = forge/web, 2 = data dir, 3 = start flag ('-' for none), 4+ = the
    // body's own. The putenv lines are not decoration: a child that misses them
    // boots against the REAL install's data dir, which is the one thing this
    // suite promises never to touch.
    $pre = <<<'PHP'
putenv('XERIC_DATA_DIR=' . $argv[2]);
putenv('XERIC_WORLDS_DIR=' . $argv[2] . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');
putenv('XERIC_CAPS=1');
putenv('XERIC_SOLO=0');
require $argv[1] . '/limits.php';
if ($argv[3] !== '-') {
    $t0 = microtime(true);
    while (!is_file($argv[3])) {
        if (microtime(true) - $t0 > 10) exit(3);   // a lost flag must not hang the suite
        usleep(200);
    }
}
PHP;
    return $pre . "\n" . $body;
}

/**
 * Start them all, drop the flag, and collect what each one said.
 *
 * @param array $children each ['code' => php, 'args' => argv 1+]
 * @return array<int,array{out:string,err:string}>
 */
function mp_race(array $children, string $go, bool $barrier = true): array
{
    @unlink($go);
    $php = xeric_web_php_bin();
    $procs = [];
    foreach ($children as $i => $c) {
        $p = proc_open(array_merge([$php, '-r', (string)$c['code'], '--'], (array)$c['args']),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[$i] = ['p' => $p, 'out' => $pipes[1], 'err' => $pipes[2]];
    }
    if ($barrier) { usleep(300000); @touch($go); }
    $said = [];
    foreach ($procs as $i => $pr) {
        $said[$i] = ['out' => (string)stream_get_contents($pr['out']),
                     'err' => (string)stream_get_contents($pr['err'])];
        fclose($pr['out']);
        fclose($pr['err']);
        proc_close($pr['p']);
    }
    return $said;
}

$web = dirname(__DIR__);
$go  = $tmp . '/start-flag';

// THE CHECK-THEN-WRITE RACE, FOR REAL. A limit whose count is an unlocked read
// lets eight requests that arrive together all read four-of-five and all pass;
// xeric_limit_reserve() exists to make that impossible. Eight processes, one
// bucket, a cap of five: exactly five may come back yes.
$body = <<<'PHP'
$r = xeric_limit_reserve('mp-race', 3600, 5, time());
xeric_limit_keep('mp-race');          // the work happened: the seat must stay taken
echo $r['ok'] ? 'Y' : 'N';
PHP;
$said = mp_race(array_fill(0, 8, ['code' => mp_child($body), 'args' => [$web, $tmp, $go]]), $go);
$yes = 0;
$no  = 0;
foreach ($said as $s) { $yes += substr_count($s['out'], 'Y'); $no += substr_count($s['out'], 'N'); }
ok('eight processes against a cap of five: exactly five pass', $yes === 5 && $no === 3, "Y=$yes N=$no");
ok('and exactly five hits are on disk afterwards',
    xeric_limit_hits('mp-race', 3600, time())['count'] === 5,
    (string)xeric_limit_hits('mp-race', 3600, time())['count']);

// THE SESSION RECORD, EDITED FROM TWO SIDES. The slow child sleeps INSIDE its
// locked read-modify-write; unlocked, both children read the empty record at
// the flag, the quick one writes, and the slow one clobbers that write 400ms
// later — deterministically, which is what makes this a test and not a coin
// flip. Locked, the quick child cannot read until the slow one's write is down.
$rsid = sid();
$body = <<<'PHP'
xeric_web_session_edit(function (array &$s) use ($argv): void {
    $own = (array)($s['own'] ?? []);
    usleep((int)$argv[6] * 1000);
    $own[] = (string)$argv[5];
    $s['own'] = $own;
}, (string)$argv[4]);
echo 'done';
PHP;
$said = mp_race([
    ['code' => mp_child($body), 'args' => [$web, $tmp, $go, $rsid, 'slow-claim', '400']],
    ['code' => mp_child($body), 'args' => [$web, $tmp, $go, $rsid, 'quick-claim', '0']],
], $go);
$own = (array)(xeric_web_session_read($rsid)['own'] ?? []);
sort($own);
ok('a worker\'s claim survives a page load landing mid-write',
    $own === ['quick-claim', 'slow-claim'], json_encode($own));

// TWO FIRST-OPENS OF THE SAME WORLD AT ONCE. The losing snapshot used to copy
// the SOURCE over the winner's open database and drop a foreign -wal beside it.
// Now the copy is built under a private name and link()ed — the loser finds the
// winner's copy in place and that IS success. The source connection is held
// open across the race so its WAL is live, which is the honest case.
$rWorld = $tmp . '/worlds/raced';
@mkdir($rWorld, 0775, true);
$rsrc = $rWorld . '/world.db';
$rdst = $tmp . '/race-copy.db';
$mk = new PDO('sqlite:' . $rsrc, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mk->exec('PRAGMA journal_mode=WAL');
$mk->exec('CREATE TABLE messages(id INTEGER PRIMARY KEY, body TEXT)');
$mk->exec('CREATE TABLE conversations(id INTEGER PRIMARY KEY, who TEXT)');
$mk->exec('CREATE TABLE events(id INTEGER PRIMARY KEY, what TEXT)');
$mk->exec("INSERT INTO messages(body) VALUES ('the last player''s own sentence'), ('and another')");
$mk->exec("INSERT INTO conversations(who) VALUES ('neil')");
$mk->exec("INSERT INTO events(what) VALUES ('the shared past'), ('stays shared')");
$body = <<<'PHP'
echo xeric_session_snapshot((string)$argv[4], (string)$argv[5]) ? 'Y' : 'N';
PHP;
$said = mp_race(array_fill(0, 3, ['code' => mp_child($body), 'args' => [$web, $tmp, $go, $rsrc, $rdst]]), $go);
$mk = null;
$snapSaid = implode(' ', array_column($said, 'out'));
ok('three racing first-opens all succeed — somebody else winning is success',
    $snapSaid === 'Y Y Y', $snapSaid);
ok('one copy landed, with no foreign -wal beside it',
    is_file($rdst) && !is_file($rdst . '-wal'));
ok('and no half-made parts around it', glob($rdst . '.*.part') === []);
$chk = new PDO('sqlite:' . $rdst);
ok('the copy is clear of the last player and keeps the past',
    (int)$chk->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 0
    && (int)$chk->query('SELECT COUNT(*) FROM events')->fetchColumn() === 2);
$chk = null;
$chk = new PDO('sqlite:' . $rsrc);
ok('and the live original did not lose a word',
    (int)$chk->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 2);
$chk = null;
rmtree($rWorld);
foreach ([$rdst, $rdst . '-wal', $rdst . '-shm'] as $f) @unlink($f);

echo "\n# when the data dir goes wrong underneath it\n";

// ---------------------------------------------------------------------------
// An unwritable line file used to swallow every queue mutation silently and
// turn the whole demo into "your place in the line timed out". It must degrade
// instead: the flock still decides, the status line says so, and the complaint
// lands in the log. Run in a child because the degraded latch is per-process
// and must not poison this one. Skipped quietly under root, where an
// unwritable directory is a suggestion.
// ---------------------------------------------------------------------------

$qroot = $tmp . '/degraded-data';
@mkdir($qroot . '/queue', 0775, true);
@chmod($qroot . '/queue', 0555);
if (is_writable($qroot . '/queue')) {
    ok('a queue with no line file still hands over the model (skipped: uid can write anywhere)', true);
} else {
    $body = <<<'PHP'
$r = xeric_queue_take('say', 2.0, 'neil0001');
$st = xeric_queue_status();
echo json_encode(['ok' => (bool)($r['ok'] ?? false), 'degraded' => (bool)($st['degraded'] ?? false)]);
PHP;
    $said = mp_race([['code' => mp_child($body), 'args' => [$web, $qroot, '-']]], $go, false);
    $d = (array)json_decode(trim($said[0]['out']), true);
    ok('a queue with no line file still hands over the model', ($d['ok'] ?? false) === true,
        $said[0]['out']);
    ok('and says it is degraded where the status line reads it', ($d['degraded'] ?? false) === true);
    ok('and complains to the log, not the visitor',
        str_contains($said[0]['err'], 'not writable'), $said[0]['err']);
}
@chmod($qroot . '/queue', 0755);

// A well-formed job id that names nothing used to be slept on for ten seconds
// per guess, one whole PHP worker each — pool exhaustion at the price of a for
// loop. It is answered at once now, in the stream's own grammar.
$t0 = microtime(true);
$body = <<<'PHP'
$_GET['job'] = 'abcdefabcdefabcdefabcdef';
require $argv[1] . '/progress.php';
PHP;
$said = mp_race([['code' => mp_child($body), 'args' => [$web, $tmp, '-']]], $go, false);
$spent = microtime(true) - $t0;
ok('a job id that names nothing is answered at once, in the stream\'s grammar',
    str_contains($said[0]['out'], 'event: failed')
    && str_contains($said[0]['out'], 'not running any more'),
    mb_substr($said[0]['out'], 0, 120));
ok('with no ten-second nap to farm', $spent < 6.0, round($spent, 1) . 's');

// And the streams themselves are rationed: each one is a held PHP worker, so
// one visitor gets XERIC_PROGRESS_MAX of them and the next is refused in a
// sentence. The seats are pre-filled here rather than held by live children —
// the cap reads the same bucket either way, without the flake.
$watcher = 'feedfacefeedfacefeedfacefeedface';
for ($i = 0; $i < 32; $i++) xeric_limit_reserve('sse-' . $watcher, 60, 999, time());
$sjob = xeric_web_job_new();
xeric_web_job_append($sjob, ['k' => 'hello']);
$body = <<<'PHP'
$_COOKIE[(string)$argv[6]] = (string)$argv[4];
$_GET['job'] = (string)$argv[5];
require $argv[1] . '/progress.php';
PHP;
$said = mp_race([['code' => mp_child($body),
                  'args' => [$web, $tmp, '-', $watcher, $sjob, XERIC_WEB_COOKIE]]], $go, false);
ok('a visitor over the stream cap is refused a live job, not slept beside it',
    str_contains($said[0]['out'], 'event: failed')
    && str_contains($said[0]['out'], 'live feeds'), mb_substr($said[0]['out'], 0, 120));
preg_match('/data: (\{.*\})/', $said[0]['out'], $mFrame);
$frame = (array)json_decode((string)($mFrame[1] ?? ''), true);
ok('and the refusal is a sentence a person wrote', human((string)($frame['message'] ?? '')),
    (string)($frame['message'] ?? ''));

echo "\n# the worker log stays inside the budget it is counted in\n";

// ---------------------------------------------------------------------------
// worker.log was the one file that grew unattended AND went unweighed, so the
// disk budget reported room right up until a write failed. It is counted now,
// and trimmed to its tail — the half anybody debugging actually reads.
// ---------------------------------------------------------------------------

$logp = $tmp . '/worker.log';
$lfh = fopen($logp, 'a');
for ($i = 1; $i <= 20000; $i++) fwrite($lfh, "flood line $i, thirty-some bytes wide\n");
fclose($lfh);
clearstatcache();
$fat = (int)filesize($logp);
ok('a fat worker log weighs against the disk budget', $fat > 524288 && xeric_limit_disk() >= $fat,
    $fat . ' bytes, gauge ' . xeric_limit_disk());
xeric_web_log_trim();
clearstatcache();
$slim = (int)filesize($logp);
ok('and the trim caps it', $slim > 0 && $slim <= 262144, $slim . ' bytes');
$lines = file($logp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
ok('keeping the newest lines, which are the ones that explain anything',
    trim((string)end($lines)) === 'flood line 20000, thirty-some bytes wide', (string)end($lines));
ok('and never starting the file mid-line',
    preg_match('/^flood line \d+,/', (string)($lines[0] ?? '')) === 1, (string)($lines[0] ?? ''));

echo "\n# the age of the person playing\n";

// ---------------------------------------------------------------------------
// THE AFFIRMATION GATE — and the two-sided rule underneath it.
//
// TWO THINGS ARE BEING DEFENDED AND THEY PULL IN OPPOSITE DIRECTIONS. Reading
// only one of them is how this gets implemented backwards.
//
//   1. NOBODY REACHES ABOVE THE WEAKEST RATING WITHOUT SAYING SO. Not the
//      stranger who answered nothing, not the half-finished interview, not the
//      ✨ surprise world, and not a model that fills `explicit` into a gap. The
//      DEFAULT is the control here, not the gate: every unattended path lands on
//      the weakest rating with nobody having decided anything, and the deliberate
//      act is what the gate looks for.
//   2. A CHILD IS AN ORDINARY CHARACTER AND A PINNED RATING MUST NOT REMOVE HIM.
//      The weakest rating is where children are ordinary, not where they are
//      excluded — so the last group asserts that a minor in a pinned world is
//      still in the cast, still in the bible, still on the shelf. Over-restriction
//      is a failure of this gate exactly as a leak is.
//
// The clamp itself is the ENGINE's — xeric_effective_rating(), the same function
// that keeps a child out of the desire economy — so the player's floor and the
// cast's floor cannot drift apart. That identity is asserted rather than the
// literal 'sfw', so these stay true if the ratings list ever changes.
// ---------------------------------------------------------------------------

$IV   = xeric_forge_interview(XERIC_WEB_LIB . '/forge/interview.json');
$WEAK = xeric_ratings()[0];
$TOP  = xeric_ratings()[count(xeric_ratings()) - 1];

// A model that answers every pass, and asks for the strongest rating at every
// opportunity it is given. Nothing it says may raise a ceiling.
$greedy = 0;
$greedyModel = ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) use (&$greedy, $TOP): array {
    switch ($tag) {
        case 'fill':
            return ['scale' => 'town', 'name' => 'Sam', 'job' => 'tend bar', 'hours' => '18:00-02:00',
                    'motivation' => 'romance', 'rating' => $TOP, 'themes' => ['nightlife'], 'circle' => 'regulars'];
        case 'concept':
            return ['name' => 'Ostrander', 'description' => 'A press town that stopped printing.',
                    'locale' => 'a printing town on a cold river', 'era' => 'present day', 'rating' => $TOP,
                    'texture' => ['ink and wet paper'], 'canon_rules' => ['The presses run at eleven.'],
                    'mood_high' => 'reckless', 'mood_low' => 'kind',
                    'motifs_dark' => ['a light in the composing room'], 'motifs_light' => ['free papers on a step'],
                    'themes' => ['newsroom']];
        case 'places':
            return ['workplace' => ['name' => 'the Ledger', 'kind' => 'office', 'open' => '09:00',
                                    'close' => '18:00', 'description' => 'Two floors of desks.'],
                    'places' => [['name' => 'the Anchor', 'kind' => 'bar', 'open' => '16:00', 'close' => '02:00',
                                  'description' => 'Dark, loud, forgiving.']]];
        case 'cast':
            $greedy++;
            return ['display_name' => "Person $greedy Quill", 'age' => 30 + $greedy,
                    'one_line' => "The $greedy th person anybody would name.",
                    'appearance' => 'Coat too thin for the weather.', 'voice' => 'Short sentences.',
                    'sore_spot' => 'being asked twice', 'jealousy' => 'people who sleep',
                    'self_soothe' => 'walking the long block', 'praise' => 'being told it read well',
                    'tells' => ['taps a pen'], 'pull' => 'to be the one who knew first', 'solace' => 'anchor',
                    'work_place' => 'the_ledger', 'work_days' => 'weekdays', 'work_from' => '9am',
                    'work_to' => '18:00', 'work_doing' => 'copy and coffee', 'hangout_place' => 'anchor',
                    'hangout_days' => 'weekends', 'hangout_from' => '20:00', 'hangout_to' => '01:00',
                    'hangout_doing' => 'the usual'];
    }
    return [];
}];

// -- the default, with nobody deciding anything ------------------------------

$str = sid();
xeric_session_use($str);
xeric_session_touch($str);
ok('a visitor who has affirmed nothing has affirmed nothing', xeric_session_adult($str) === false);

$zero = xeric_web_clean_answers([], $IV);
ok('a ✨ world with zero answers is pinned before the forge sees it',
    ($zero['rating'] ?? null) === $WEAK, json_encode($zero));

// THE ONE THAT CATCHES A SOFTENED PIN. Four of the five surprise concepts carry
// a mature rating, so if the pin ever becomes "only clamp what is present", this
// goes red on its own and nothing else does.
ok('and the real surprise fill, table path, cannot raise it',
    xeric_forge_rating(xeric_forge_answers_fill($zero, $IV, null)) === $WEAK);

ok('a half-finished interview lands there too',
    (xeric_web_clean_answers(['name' => 'Sam', 'job' => 'tend bar'], $IV)['rating'] ?? null) === $WEAK);

// LOWERED, NOT REFUSED. Somebody who asked for the strongest rating keeps every
// other answer they typed — the gate takes the rating down and nothing else.
$asked = xeric_web_clean_answers(['rating' => $TOP, 'name' => 'Sam'], $IV);
ksort($asked);
ok('an unaffirmed session asking for the strongest rating is lowered, not refused',
    $asked === ['name' => 'Sam', 'rating' => $WEAK], json_encode($asked));

// -- the clamp is the engine's, and it is down-only --------------------------

ok('the player\'s floor IS the cast\'s floor, called with a person of unknown age',
    xeric_session_rating($TOP, $str) === xeric_effective_rating($TOP, []));
ok('affirming is not a way to raise a world: the clamp only ever goes down',
    xeric_rating_clamp($WEAK, true) === $WEAK && xeric_rating_clamp($TOP, false) === $WEAK
    && xeric_rating_clamp($TOP, true) === $TOP);

// -- nothing the forge is told can raise it ----------------------------------

$built = xeric_forge_build(xeric_web_clean_answers([], $IV), $greedyModel,
    ['interview' => $IV, 'places' => 2, 'cast' => 2, 'seed' => false, 'fill' => 'model']);
ok('a model that asks for the strongest rating at every pass still builds at the weakest',
    (string)$built['template']['meta']['rating'] === $WEAK,
    (string)$built['template']['meta']['rating']);
ok('and the session ceiling closes it a second time, inside the forge',
    xeric_forge_rating(['rating' => $TOP], xeric_session_ceiling($str)) === $WEAK);

// -- AND SOMETHING ACTUALLY HANDS IT DOWN ------------------------------------
//
// The assertion above proves the ceiling WORKS. It did that while no production
// path passed one: xeric_session_ceiling() had a docblock describing the gate it
// covers, a test proving it closes, and not one caller. A gate nothing is wired
// to is a comment. Both builders now hand it down — worker.php for a first
// forge, review-lib.php for a redraft, each after forcing the requesting sid.
$wireW = (string)file_get_contents(dirname(__DIR__) . '/worker.php');
$wireR = (string)file_get_contents(dirname(__DIR__) . '/review-lib.php');
ok('ceiling: the first forge hands the session ceiling to the builder',
    str_contains($wireW, "'rating_ceiling' => xeric_session_ceiling()"));
ok('ceiling: and so does a redraft, which re-forges from answers off disk',
    str_contains($wireR, "'rating_ceiling' => xeric_session_ceiling()"));
ok('ceiling: a redraft also re-runs those answers through the funnel',
    str_contains($wireR, 'xeric_web_clean_answers($answers, $interview)'));

// -- the affirmation itself ---------------------------------------------------

$grown = sid();
xeric_session_touch($grown);
ok('affirming is what puts the strongest rating in reach',
    xeric_session_affirm(true, $grown) === true && xeric_session_adult($grown) === true
    && xeric_session_rating($TOP, $grown) === $TOP);
ok('and it is read the way the forge reads it — trimmed and lower-cased, never a fourth level',
    xeric_session_rating('  ' . strtoupper($TOP) . '  ', $grown) === $TOP
    && xeric_session_rating('filthier than explicit', $grown) === $WEAK);

xeric_session_use($grown);
$builtUp = xeric_forge_build(xeric_web_clean_answers([], $IV), $greedyModel,
    ['interview' => $IV, 'places' => 2, 'cast' => 2, 'seed' => false, 'fill' => 'model']);
ok('and the same build for an affirmed session reaches it',
    (string)$builtUp['template']['meta']['rating'] === $TOP,
    (string)$builtUp['template']['meta']['rating']);

// WITHDRAWAL IS ONE CALL, AND WHAT IT LEAVES BEHIND IS NOTHING. The key is
// removed rather than set false: a record that says `adult: false` is a record
// of somebody having said they are a minor, which is the database this gate
// exists in order not to keep.
ok('taking it back is one call', xeric_session_affirm(false, $grown) === false
    && xeric_session_adult($grown) === false
    && xeric_session_rating($TOP, $grown) === $WEAK);
$rec = xeric_web_session_read($grown);
ok('and it leaves no record that anybody said they were a minor',
    !array_key_exists('adult', $rec), json_encode(array_keys($rec)));
ok('no session record ever holds an age, affirmed or not',
    !array_key_exists('age', $rec) && !array_key_exists('age', xeric_web_session_read($str)));

// AN ID MINTED A MOMENT AGO CANNOT AFFIRM. A cookieless client POSTing straight
// at the gate would otherwise carry an affirmation on every request and spend a
// record on each one.
$fresh = xeric_web_sid();
ok('a freshly minted id cannot affirm, and leaves nothing on disk',
    xeric_session_affirm(true, $fresh) === false && !is_file(xeric_web_session_path($fresh)));

// ONE VISITOR'S ANSWER IS ONE VISITOR'S. The demo is one directory behind one
// password, and this is the same guarantee as the fork.
$other = sid();
xeric_session_touch($other);
xeric_session_affirm(true, $other);
ok('one visitor affirming leaves every other visitor pinned',
    xeric_session_adult($other) === true && xeric_session_adult($str) === false);

// READING THE BIT NEVER WRITES. xeric_web_clean_answers() reads it from inside a
// xeric_web_session_edit() callback with that record's lock already held, and
// flock() conflicts across two descriptors of one process — so a read that
// touched would not be slow, it would be the wizard's save hanging forever.
// Invisible to a CLI suite as a symptom, so the invariant is asserted instead.
$unknown = bin2hex(random_bytes(16));
ok('asking whether an unknown session affirmed writes no session file',
    xeric_session_adult($unknown) === false && !is_file(xeric_web_session_path($unknown)));
// Driven from the UNAFFIRMED session, because the pin is the branch that reads
// the bit: an affirmed session with no answers never asks the question at all.
xeric_session_use($str);
$inside = xeric_web_session_edit(function (array &$s) use ($IV) {
    $s['answers'] = xeric_web_clean_answers([], $IV);
    return $s['answers'];
}, $str);
ok('and the wizard\'s own save completes with the record\'s lock already held',
    ($inside['rating'] ?? null) === $WEAK, json_encode($inside));

// THE TTL RE-ASK. The affirmation is not stored with an expiry of its own; it
// expires by being forgotten along with everything else the session held.
@touch(xeric_web_session_path($other), time() - XERIC_SESSION_TTL - 60);
xeric_session_sweep();
ok('an affirmation lapses with the session that made it',
    xeric_session_adult($other) === false && xeric_session_rating($TOP, $other) === $WEAK);

// -- and the other half of the rule: a pinned world is not an emptied one -----
//
// A world clamped to the weakest rating is a world where children are ORDINARY,
// not one they have been taken out of. If a change ever makes the gate quietly
// delete a cast member, this is the assertion that says so.

$kidWorld = xeric_web_worlds_dir() . '/pinned-town';
@mkdir($kidWorld, 0775, true);
$KT = json_decode((string)file_get_contents($srcWorld . '/world-template.json'), true);
$KT['meta']['rating'] = $TOP;
$KT['cast']['characters'][1]['age'] = 11;
unset($KT['cast']['characters'][1]['flirt_style']);
$kidHandle = (string)$KT['cast']['characters'][1]['handle'];
foreach ($KT['cast']['characters'] as $i => $kc) {
    $seeds = (array)($kc['relationships']['attraction_seeds'] ?? []);
    unset($seeds[$kidHandle]);
    if ($i === 1) $seeds = [];
    $KT['cast']['characters'][$i]['relationships']['attraction_seeds'] = $seeds === [] ? (object)[] : $seeds;
}
file_put_contents($kidWorld . '/world-template.json', json_encode($KT));
@copy($srcWorld . '/seed.json', $kidWorld . '/seed.json');

$pinned = xeric_world_clamp_rating(xeric_world_load($kidWorld . '/world-template.json'), false);
ok('an unaffirmed session reads a strongest-rated world at the weakest rating',
    (string)$pinned['meta']['rating'] === $WEAK && xeric_world_rating($pinned) === $WEAK);
ok('and an affirmed one reads what the template declared',
    (string)xeric_world_clamp_rating($pinned, true)['meta']['rating'] === $WEAK
    && (string)xeric_world_clamp_rating(xeric_world_load($kidWorld . '/world-template.json'), true)['meta']['rating'] === $TOP);

$kidName = xeric_world_name($pinned, $kidHandle);
ok('the child is still in the cast of the pinned world',
    in_array($kidHandle, array_column(xeric_world_cast($pinned), 'handle'), true));
ok('he is a minor because of his age and for no other reason',
    xeric_is_minor(xeric_world_character($pinned, $kidHandle) ?? []) === true
    && xeric_effective_rating($TOP, xeric_world_character($pinned, $kidHandle) ?? []) === $WEAK);
ok('every adult in the town still reads him in their bible',
    str_contains(xeric_render_bible($pinned, ['handle' => (string)$KT['cast']['characters'][0]['handle']], $WEAK), $kidName));
ok('and he reads a bible of his own',
    strlen(xeric_render_bible($pinned, ['handle' => $kidHandle], $WEAK)) > 400);

xeric_session_use($str);
$onShelf = false;
foreach (xeric_play_worlds($str) as $row) if (($row['slug'] ?? '') === 'pinned-town') $onShelf = true;
ok('the world he lives in is on a stranger\'s shelf like any other', $onShelf);
$kw = xeric_play_open('pinned-town');
ok('and a stranger opening it finds him there to talk to',
    xeric_world_character($kw['template'], $kidHandle) !== null
    && xeric_play_cast($kw['template'], $kw['db'], xeric_world_now($kw['template'], time())) !== []);

echo "\n# the age floor, end to end\n";

// ---------------------------------------------------------------------------
// THE RULE IS "NO SEX UNDER 18", NOT "NO ONE UNDER 18", and both halves of it
// are asserted here because both halves are failures. What is defended:
//
//   1. THE TWO WRITES THIS APP MAKES ON ITS OWN. A proactive ping is model text
//      persisted with nobody watching, and a seed is a past written before the
//      first turn. Neither is re-scanned by anything downstream, so a refusal
//      that leaves a row behind is a leak that reads back into every later
//      prompt as history. The messages table is read DIRECTLY, because "the
//      call threw" and "nothing was stored" are not the same claim.
//   2. THE SEED SKIPS THE ROW AND KEEPS THE REST. A refusal that took the whole
//      past down would leave a visitor a world with no history.
//   3. NOTHING ELSE IS TOUCHED. A murder, a child witness nobody believes, and
//      two adults in a world that has a child in it all go through. This is the
//      half that regresses quietly, because over-restriction never throws.
//   4. THE REVIEW BOX CORRECTS AN AGE. Twelve to thirteen used to be refused
//      with a sentence saying everybody in a Xeric world is an adult.
//   5. THE INSPECTOR RENDERS A MINOR AT THE MINOR'S RATING, byte for byte with
//      the prompt — the same promise the section above makes for an adult, which
//      is exactly why it did not catch this.
//
// The fixture rather than a repo world, because it is the only template that
// carries both a child (theo, 12) and a mature-gated node (harlan's drives) —
// and a world with nothing gated cannot show a clamp doing anything.
// ---------------------------------------------------------------------------

$FIX = dirname(__DIR__, 3) . '/engine/fixtures/milldale.json';
$FT  = json_decode((string)file_get_contents($FIX), true);
$FT['meta']['rating'] = 'mature';

$kid   = 5;                                              // theo, 12
$grown = 3;                                              // harlan, 66 — the mature-gated drives
$kidH  = (string)$FT['cast']['characters'][$kid]['handle'];
$grH   = (string)$FT['cast']['characters'][$grown]['handle'];
$kidN  = (string)$FT['cast']['characters'][$kid]['display_name'];

// One poisoned row of each kind, and every other row clean. The poisoned pair
// are written the way the word list is written — an act word beside a body word,
// and one from the plain list — because a check can only be proved against the
// text it claims to read.
$floorSeed = ['events' => [
    ['title' => 'the fund came up short', 'days_ago' => 30, 'participants' => ['ruth', $grH],
     'prose' => 'Nine hundred dollars was gone from the building fund and the ledger was back in the drawer.'],
    ['title' => 'a night at the mill', 'days_ago' => 20, 'participants' => [$kidH, $grH],
     'prose' => 'Theo was touched on his bare thigh behind the mill.'],
    ['title' => 'harlan was found', 'days_ago' => 10, 'participants' => ['ruth', 'dot'],
     'prose' => 'Harlan Beck was found dead at the foot of the mill stairs with his skull broken.'],
    ['title' => 'the boy saw it', 'days_ago' => 10, 'participants' => [$kidH],
     'prose' => 'Theo saw a man come down the mill stairs that night and told nobody who believed him.'],
], 'memories' => [
    ['handle' => 'ruth', 'days_ago' => 30, 'text' => 'Ruth counted the fund twice and said nothing about it to anyone.'],
    ['handle' => $kidH, 'days_ago' => 10, 'text' => 'He knows the sound the stairwell door makes when somebody is being quiet.'],
    ['handle' => $kidH, 'days_ago' => 20, 'text' => 'He was told to take his clothes off in the back room and keep quiet about it.'],
]];

$F = sid();
xeric_session_use($F);
foreach (['floor-town', 'floor-inspect'] as $fslug) {
    $fdir = xeric_web_worlds_dir() . '/' . $fslug;
    @mkdir($fdir, 0775, true);
    file_put_contents($fdir . '/world-template.json', json_encode($FT));
    file_put_contents($fdir . '/seed.json', json_encode($floorSeed));
    xeric_session_claim($fslug, $F);
}

// -- the seed: the poisoned rows are dropped and counted, the rest lands ------
// Opened as an affirmed session, so the world is read at the rating it declares
// rather than clamped to sfw — the floor must hold at the world's OWN rating,
// which is the only place it has anything to do.
$fw = xeric_play_open('floor-town', null, true);
ok('a seeded past drops exactly the rows that could not be written',
    (int)$fw['seeded']['skipped'] === 2, json_encode($fw['seeded']));
ok('and writes every other row it was given',
    (int)$fw['seeded']['events'] === 3 && (int)$fw['seeded']['memories'] === 2, json_encode($fw['seeded']));

$fev = array_column((array)$fw['db']->query('SELECT title, prose FROM events')->fetchAll(PDO::FETCH_ASSOC), 'prose', 'title');
ok('the poisoned hour is not in the events table', !isset($fev['a night at the mill']), json_encode(array_keys($fev)));
ok('the MURDER is, in full',
    isset($fev['harlan was found']) && str_contains((string)$fev['harlan was found'], 'skull broken'));
ok('and so is the child nobody believed — the load-bearing half of the rule',
    isset($fev['the boy saw it']));

$fmem = (array)$fw['db']->query('SELECT handle, text FROM memories')->fetchAll(PDO::FETCH_ASSOC);
ok('the poisoned memory was never written into his head',
    !str_contains(implode(' ', array_column($fmem, 'text')), 'clothes off'), json_encode($fmem));
ok("and his ordinary memory of that night was",
    (bool)array_filter($fmem, fn($m) => $m['handle'] === $kidH && str_contains((string)$m['text'], 'stairwell')));

// -- the ping: refused whole, with NOTHING behind it --------------------------
// A fixed evening in the world's own timezone: real time lands in quiet hours
// often enough that a wall-clock `now` would make this a test that fails
// overnight and passes at lunch.
$fnow = xeric_world_now($fw['template'], (new DateTimeImmutable('2026-07-30 18:30',
    new DateTimeZone((string)$fw['template']['user']['timezone'])))->getTimestamp());
$fev1 = ['id' => 91, 'title' => 'closing up', 'participants' => [$kidH],
         'prose' => 'He swept up after close and waited on the step.',
         'memories' => [$kidH => 'He waited on the step for twenty minutes.']];
$says = fn(string $line) => ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o) => $line];
$popts = ['event' => $fev1, 'chance' => 1.0, 'involves_user' => true, 'seed' => 1];

$before = (int)$fw['db']->query('SELECT COUNT(*) c FROM messages')->fetchAll(PDO::FETCH_ASSOC)[0]['c'];
$pnotes = null;
$prefusal = '';
try {
    xeric_proactive_check($fw['template'], $fw['db'], $says('i keep thinking about your bare thighs'),
        $fnow, $popts, $pnotes);
} catch (Throwable $e) { $prefusal = $e->getMessage(); }
ok('a sexual ping from the twelve-year-old is refused', str_contains($prefusal, 'refused'), $prefusal);
ok('and the refusal names him and says what he is', str_contains($prefusal, $kidN)
    && str_contains($prefusal, 'child'), $prefusal);
ok('and NOTHING was written — not the message, not the thread',
    (int)$fw['db']->query('SELECT COUNT(*) c FROM messages')->fetchAll(PDO::FETCH_ASSOC)[0]['c'] === $before
    && (int)$fw['db']->query('SELECT COUNT(*) c FROM conversations')->fetchAll(PDO::FETCH_ASSOC)[0]['c'] === 0);
ok('and the hour is not burnt, so the world may still say something else in it',
    xeric_world_state_get($fw['db'], 'proactive:event:91') === null);

$pnotes = null;
$pok = xeric_proactive_check($fw['template'], $fw['db'], $says('grandma left her keys on the counter again.'),
    $fnow, $popts, $pnotes);
ok('an ordinary ping from the same child goes out',
    $pok !== null && (string)$pok['handle'] === $kidH, json_encode([$pok, $pnotes]));
ok('and it is a real message in a real thread',
    (int)$fw['db']->query('SELECT COUNT(*) c FROM messages')->fetchAll(PDO::FETCH_ASSOC)[0]['c'] === $before + 1);

// -- the review box: an age is a number, and a child's age is correctable -----
$fr = xeric_review_open('floor-town', $F);
$e = xeric_review_apply_edit($fr, "cast.characters.$kid.age", '13');
ok('twelve can be corrected to thirteen', ($e['ok'] ?? false) === true, json_encode($e));
$fr = xeric_review_open('floor-town', $F);
ok('and it is on disk, and he is still a whole character',
    (int)$fr['template']['cast']['characters'][$kid]['age'] === 13
    && ((array)$fr['template']['cast']['characters'][$kid]['week']) !== []
    && trim((string)$fr['template']['cast']['characters'][$kid]['one_line']) !== '');

foreach (['0' => 'zero', '111' => 'a hundred and eleven', 'nine' => 'a word'] as $bad => $what) {
    $e = xeric_review_apply_edit($fr, "cast.characters.$kid.age", (string)$bad);
    ok("$what is not an age", ($e['ok'] ?? true) === false, json_encode($e));
    ok("and the refusal for $what never says everybody here is an adult",
        !str_contains(mb_strtolower((string)($e['error'] ?? '')), 'adult'), (string)($e['error'] ?? ''));
}

$e = xeric_review_apply_edit($fr, "cast.characters.$grown.age", '15');
ok('an adult can be aged down across the line', ($e['ok'] ?? false) === true, json_encode($e));
ok('and the page says what moved, in a sentence, calling him a child',
    str_contains((string)($e['note'] ?? ''), 'child') && human((string)($e['note'] ?? '')),
    (string)($e['note'] ?? ''));
$fr2 = xeric_review_open('floor-town', $F);
$gr  = (array)$fr2['template']['cast']['characters'][$grown];
ok('he is out of the desire economy afterwards',
    !isset($gr['flirt_style']) && ((array)($gr['relationships']['attraction_seeds'] ?? [])) === []
    && in_array($grH, (array)($fr2['template']['forge']['desire_excluded'] ?? []), true), json_encode($gr['relationships'] ?? []));
ok('and in EVERYTHING ELSE he is exactly who he was — this is the over-restriction guard',
    ((array)$gr['week']) !== [] && trim((string)$gr['voice']) !== '' && trim((string)$gr['one_line']) !== '');

// -- the inspector: a minor's page is the prompt, at the minor's rating -------
// A FRESH copy, because the edit above ran the forge's age floor over the other
// one and xeric_forge_rating_lock() lowers a rating_min in place: a world that
// has had anybody aged across the line has no mature-gated node left to watch
// vanish, and every assertion below would pass for the wrong reason.
$iw2  = xeric_play_open('floor-inspect', null, true);
$in2  = xeric_clock_now($iw2['db'], $iw2['template']);
$rate = xeric_world_rating($iw2['template'], null);
ok('the world under the inspector really is rated above the weakest', $rate === 'mature', $rate);

foreach ([$kidH => 'the child', $grH => 'the adult'] as $wh => $which) {
    $sys = xeric_prompt_system($iw2['template'], $iw2['db'], $wh, $rate, 12, (int)$in2['epoch']);
    $reb = xeric_why_system_sections($iw2['template'], $iw2['db'], $wh, $rate, 12, (int)$in2['epoch'])['rebuilt'];
    ok("the inspector reproduces $which's system message byte for byte",
        $reb === $sys, strlen($reb) . ' vs ' . strlen($sys));
}

$kidMature = xeric_why_system_sections($iw2['template'], $iw2['db'], $kidH, 'mature', 12, (int)$in2['epoch'])['rebuilt'];
$kidSfw    = xeric_why_system_sections($iw2['template'], $iw2['db'], $kidH, 'sfw', 12, (int)$in2['epoch'])['rebuilt'];
$grMature  = xeric_why_system_sections($iw2['template'], $iw2['db'], $grH, 'mature', 12, (int)$in2['epoch'])['rebuilt'];
$grSfw     = xeric_why_system_sections($iw2['template'], $iw2['db'], $grH, 'sfw', 12, (int)$in2['epoch'])['rebuilt'];
ok('a child\'s page is the same at mature as at sfw — the rating cannot reach him',
    $kidMature === $kidSfw);
ok('and an adult\'s is NOT, so the clamp did not simply flatten the whole world',
    $grMature !== $grSfw);

echo "\n# what the play view is allowed to say\n";

// ---------------------------------------------------------------------------
// A refusal and a story both reach the browser through play-lib, and each of
// them has one thing it must not do. The refusal must not print the engine's
// own sentence or offer to try again, because there is nothing to try. The
// story must not print what it is holding, and must not acknowledge a correct
// guess that has not been said out loud yet — a narrator line there is a wink,
// and a wink is the whole mystery.
// ---------------------------------------------------------------------------

$storyDir = xeric_web_worlds_dir() . '/floor-story';
@mkdir($storyDir, 0775, true);
file_put_contents($storyDir . '/world-template.json', json_encode($FT));
file_put_contents($storyDir . '/seed.json', json_encode(['events' => [], 'memories' => []]));
@copy(dirname(__DIR__, 3) . '/engine/fixtures/milldale-story.json', $storyDir . '/story-mill_stairwell.json');
// This suite tests world mechanics, not attachment — so it says a machine is
// attached. Without it xeric_play_open() correctly stops every world it opens
// (nothing is running in a CLI test), and every clock assertion below would be
// asserting against a world that is not moving.
xeric_web_session_edit(function (array &$s): void {
    $s['model'] = ['kind' => 'local', 'base' => '', 'local' => '', 'model' => ''];
}, $F);
xeric_session_claim('floor-story', $F);
$sw = xeric_play_open('floor-story', null, true);
ok('a world with an overlay beside it opens carrying it', count((array)$sw['stories']) === 1,
    json_encode($sw['story_notes']));

ok('the age refusal is recognised as one', xeric_play_say_refused(xeric_age_refusal('chat', $kidN)));
ok('and a wall refusal and a dead model are NOT — they are things to try again',
    !xeric_play_say_refused('sweep: refused — the hour put theo somewhere he cannot be')
    && !xeric_play_say_refused('chat: Ruth did not answer — llm: HTTP 500'));

$sayErr = xeric_play_say_error(xeric_age_refusal('chat', $kidN), $kidN);
ok('what the browser is shown names him and reads like a person wrote it',
    str_contains($sayErr, $kidN) && human($sayErr), $sayErr);
ok('and carries no engine prefix, no JSON, and no offer to try again',
    !str_contains($sayErr, 'chat:') && !str_contains($sayErr, '{')
    && !str_contains($sayErr, 'costs you nothing but the wait'), $sayErr);

// -- where you are, rendered ------------------------------------------------
// The screen a player uses to move. Asserted on the MARKUP a browser gets, not
// on the map object behind it: the map has its own tests in engine-test.php, and
// every bug this screen has ever had lived in the gap between the two.
$wstate = xeric_play_state($sw, $F);
$whtml  = (string)($wstate['where_html'] ?? '');
ok('a player who has not moved is somewhere, and it is nowhere in particular',
    str_contains($whtml, 'Nowhere in particular') && str_contains($whtml, 'your own time'), mb_substr($whtml, 0, 160));
ok('every place in the world is a button with its key and its price on it',
    substr_count($whtml, 'class="goto"') === count((array)$sw['template']['places'])
    && str_contains($whtml, 'data-to="the_mill"')
    && preg_match_all('/<span class="wm">\d+ min<\/span>/', $whtml) === count((array)$sw['template']['places']),
    (string)substr_count($whtml, 'class="goto"'));
ok('and the trip is priced from the map, so the mill is not the same walk as next door',
    xeric_travel_minutes($sw['template'], 'bluebird', 'the_mill')
        > xeric_travel_minutes($sw['template'], 'bluebird', 'beck_hardware'));

$flatT = $sw['template'];
foreach ($flatT['places'] as $i => $_) unset($flatT['places'][$i]['at']);
$flatH = xeric_play_where_html(xeric_travel_map($flatT, $sw['db']));
ok('a world forged before it had a map SAYS SO rather than quietly pricing everything the same',
    str_contains($flatH, 'made before it had a map')
    && !str_contains($whtml, 'made before it had a map'));

$wasEpoch = (int)xeric_clock_now($sw['db'], $sw['template'])['epoch'];
$went     = xeric_travel_go($sw['template'], $sw['db'], 'the_mill');
$after    = xeric_play_state($sw, $F);
ok('walking there moves the clock the page prints, by exactly the minutes it cost',
    $went['ok'] && (int)$after['clock']['epoch'] - $wasEpoch === $went['minutes'] * 60
    && str_contains((string)$after['where_html'], 'Leave'), (string)$went['minutes']);
xeric_player_move($sw['db'], null);

// -- death, rendered ---------------------------------------------------------
// Same discipline as the where block: the ledger has its tests in engine-test,
// and these are about the screen. The one that matters is that a dead person is
// still ON the roster — dropping them, or sorting them to the bottom, would be
// the deletion the engine refuses to do, done in CSS.
$fcast = xeric_play_cast($sw['template'], $sw['db'], xeric_clock_now($sw['db'], $sw['template']));
$fhtml = xeric_play_fate_html($sw['template'], $sw['db'], $fcast);
ok('before anybody dies the panel says the rule is still a setting',
    str_contains($fhtml, 'can be undone') && str_contains($fhtml, 'still a setting')
    && !str_contains($fhtml, 'bring back'), mb_substr($fhtml, 0, 120));

xeric_death_kill($sw['template'], $sw['db'], 'dot', (int)xeric_clock_now($sw['db'], $sw['template'])['epoch'],
    'the truck on the river road');
$dstate = xeric_play_state($sw, $F);
$dcast  = $dstate['cast'];
$dhtml  = (string)$dstate['cast_html'];
$names  = array_column($dcast, 'handle');

ok('a dead person is STILL ON THE ROSTER, in cast order, where they always were',
    $names === array_column($fcast, 'handle')
    && count(array_filter($dcast, fn($c) => !empty($c['dead']))) === 1);
ok('their row is marked and carries what the town would say, not "off shift"',
    str_contains($dhtml, 'class="person gone"') && str_contains($dhtml, 'the truck on the river road')
    && substr_count($dhtml, 'the truck on the river road') === 1);
ok('and the row still opens — the thread is the point of keeping it',
    str_contains($dhtml, 'class="person gone" data-h="dot"'));
ok('the panel now offers to bring them back, and stops calling the rule a setting',
    str_contains((string)$dstate['fate_html'], 'bring back')
    && !str_contains((string)$dstate['fate_html'], 'still a setting'));
ok('sending to somebody dead is refused as a RULE, not as a fault to retry',
    xeric_play_say_dead(xeric_death_refusal('chat', 'Dot Kessler'))
    && !xeric_play_say_refused(xeric_death_refusal('chat', 'Dot Kessler'))
    && !xeric_play_say_dead('chat: Dot did not answer — llm: HTTP 500'));
$dErr = xeric_play_say_error(xeric_death_refusal('chat', 'Dot Kessler'), 'Dot Kessler');
ok('and what the browser is shown says the thread keeps, without offering a fix',
    str_contains($dErr, 'still yours to read back') && human($dErr)
    && !str_contains($dErr, 'chat:') && !str_contains($dErr, 'try again'), $dErr);

xeric_death_revive($sw['template'], $sw['db'], 'dot');
ok('and reviving takes the mark off without taking the world back',
    empty(xeric_play_cast($sw['template'], $sw['db'], xeric_clock_now($sw['db'], $sw['template']))[0]['dead'])
    && xeric_death_locked($sw['db']));

$shtml = (string)(xeric_play_state($sw, $F)['story_html'] ?? '');
ok('the strip names the story', str_contains($shtml, 'What Happened on the Fourth-Floor Landing'), mb_substr($shtml, 0, 200));
$canary = array_values(array_filter(
    ['Harlan Beck let himself', 'till key', 'not kids that night', 'folding chair'],
    fn($c) => str_contains($shtml, $c)));
ok('and prints none of what it is holding', $canary === [], implode(' / ', $canary));

$epitaph = 'It was not kids that night.';
ok('a line the world said out loud is passed through verbatim',
    xeric_play_story_lines($sw, ['said' => [$epitaph]]) === [$epitaph]);
ok('a story that is closed and right gets exactly one ending line',
    count(xeric_play_story_lines($sw, ['resolved' => [['key' => 'mill_stairwell', 'right' => true, 'closed' => true]]])) === 1);
ok('RIGHT WITHOUT CLOSED SAYS NOTHING — guessing it is not being told you guessed it',
    xeric_play_story_lines($sw, ['resolved' => [['key' => 'mill_stairwell', 'right' => true, 'closed' => false]]]) === []);
ok('and a wrong name gets no line either',
    xeric_play_story_lines($sw, ['resolved' => [['key' => 'mill_stairwell', 'right' => false, 'closed' => true]]]) === []);

echo "\n# presence marks\n";

// ---------------------------------------------------------------------------
// The cast chips' small vocabulary — five states and a modifier, every one
// derived from week/places/presence commons and nothing else. Fixed clocks in
// the world's own timezone, for the same reason the ping tests use one: a
// wall-clock `now` makes these pass at lunch and fail at midnight.
//
// The fixture is milldale plus exactly what the states need: two homes (the
// stock fixture has none, so at_home never fires there), a closing shift that
// wraps midnight, and one character who has not entered the story. What is
// being defended:
//   1. A SHIFT IS RECOGNISED, NOT DECLARED — and under-claimed. Dot's six
//      nine-hour days are work; Ruth's breakfast hour and Theo's homework
//      booth are not, however many days they recur.
//   2. THE WEEK OUTRANKS THE GUESS. A start time within two hours renders as
//      the plan it is; "asleep" is only ever an inference from the hour.
//   3. at_home plus the hour picks home vs asleep — the quiet-hours band when
//      it is readable, engine-canon night when it is not.
//   4. A LATE CLOSE EARNS A SLOW MORNING — only the morning after, only
//      before ten, and never over somebody still marked asleep.
//   5. OUT IS ABSENCE: no row, no pin, no invented placement, no sleep mark —
//      a subdued row that says so, and no crash anywhere on the way.
// ---------------------------------------------------------------------------

$PT = json_decode((string)file_get_contents($FIX), true);
$PT['places'][] = ['key' => 'dot_house',  'name' => "Dot's house",    'kind' => 'home', 'residents' => ['dot', 'theo']];
$PT['places'][] = ['key' => 'kerr_house', 'name' => 'the Kerr house', 'kind' => 'home', 'residents' => ['janelle']];
foreach ($PT['cast']['characters'] as $pi => $pc) {
    if ((string)$pc['handle'] === 'janelle') {
        $PT['cast']['characters'][$pi]['week'][] =
            ['days' => [1, 2, 3, 4, 5], 'from' => '19:00', 'to' => '00:45',
             'where' => 'bluebird', 'doing' => 'closing the diner down'];
    }
}
$PT['cast']['characters'][] = ['handle' => 'cousin_rae', 'display_name' => 'Rae Kessler',
    'one_line' => 'Talked about, never seen.', 'out' => true, 'week' => []];

// 2026-08-04 is a Tuesday; milldale's week has every state on the board that
// day. Marks are read the way the cast builder reads them — same presence map,
// same fallback for a handle the map left off.
$pAt = fn(array $tpl, string $when): array => xeric_world_now($tpl,
    (new DateTimeImmutable($when, new DateTimeZone('America/New_York')))->getTimestamp());
$pmarks = function (array $tpl, string $when) use ($pAt): array {
    $now  = $pAt($tpl, $when);
    $pres = xeric_world_who_is_where($tpl, $now);
    $out  = [];
    foreach ($tpl['cast']['characters'] as $c) {
        $out[(string)$c['handle']] = xeric_play_presence_mark($tpl, $c, $pres[(string)$c['handle']] ?? null, $now);
    }
    return $out;
};

$m = $pmarks($PT, '2026-08-04 10:00');
ok('a six-day nine-hour week behind the counter is AT WORK',
    $m['dot']['state'] === 'work' && $m['dot']['glyph'] === '💼'
    && str_contains($m['dot']['say'], 'at work — the Bluebird Diner'), json_encode($m['dot']));
ok('and so is the hardware store, five days and a Saturday half', $m['harlan']['state'] === 'work');

$m = $pmarks($PT, '2026-08-04 07:30');
ok('Ruth\'s breakfast hour at the same counter is PLACED, not work — one hour, two days',
    $m['ruth']['state'] === 'placed' && $m['ruth']['glyph'] === '📍'
    && str_contains($m['ruth']['say'], 'at the Bluebird Diner'), json_encode($m['ruth']));
ok('Theo\'s homework booth is placed too, five days a week or not — two hours is not a shift',
    $pmarks($PT, '2026-08-04 16:00')['theo']['state'] === 'placed');

$m = $pmarks($PT, '2026-08-04 14:00');
ok('ninety minutes before his booth, Theo is a plan with a start time',
    $m['theo']['state'] === 'soon' && $m['theo']['glyph'] === '→'
    && $m['theo']['pw'] === 'the Bluebird Diner at 15:30'
    && str_contains($m['theo']['say'], 'by 15:30'), json_encode($m['theo']));
ok('and at noon he was not — two hours is the whole window',
    $pmarks($PT, '2026-08-04 12:00')['theo']['state'] === 'home');

$m = $pmarks($PT, '2026-08-04 15:00');
ok('Dot off her shift mid-afternoon is HOME, awake, and the hover says which home',
    $m['dot']['state'] === 'home' && $m['dot']['glyph'] === '🏠'
    && str_contains($m['dot']['say'], "Dot's house"), json_encode($m['dot']));

$m = $pmarks($PT, '2026-08-04 23:00');
ok('the same home inside quiet hours is ASLEEP',
    $m['dot']['state'] === 'asleep' && $m['dot']['glyph'] === '💤' && $m['theo']['state'] === 'asleep');
ok('while the closer is still AT WORK at eleven — the town does not sleep as one',
    $m['janelle']['state'] === 'work');

$m = $pmarks($PT, '2026-08-04 05:30');
ok('a five-thirty world: the opener is on, the seven-o\'clocks are plans, the rest asleep',
    $m['dot']['state'] === 'work' && $m['ruth']['state'] === 'soon'
    && $m['harlan']['state'] === 'soon' && $m['theo']['state'] === 'asleep',
    json_encode(array_map(fn(array $x): string => (string)$x['state'], $m)));

ok('the diner\'s closer wears the slow morning at half eight, over the home mark',
    $pmarks($PT, '2026-08-04 08:30')['janelle']['slow'] === true
    && $pmarks($PT, '2026-08-04 08:30')['janelle']['state'] === 'home');
ok('but not at half ten, not while still asleep at half five, and not on a Monday after a Sunday she does not close',
    $pmarks($PT, '2026-08-04 10:30')['janelle']['slow'] === false
    && $pmarks($PT, '2026-08-04 05:30')['janelle']['slow'] === false
    && $pmarks($PT, '2026-08-03 08:30')['janelle']['slow'] === false);
ok('and nobody who did not close wears it', $pmarks($PT, '2026-08-04 08:30')['theo']['slow'] === false);

$m = $pmarks($PT, '2026-08-04 23:00');
ok('a character the story has not admitted is ABSENT — no pin, no sleep, no guess',
    $m['cousin_rae']['state'] === 'absent' && $m['cousin_rae']['glyph'] === ''
    && $m['cousin_rae']['pw'] === 'not in the story yet', json_encode($m['cousin_rae']));

// The markup a browser actually gets, through the same door play.php uses.
$phtml = xeric_play_cast_html(xeric_play_cast($PT, $sw['db'], $pAt($PT, '2026-08-04 10:00')), 'floor-story', true);
ok('the rows carry the marks: a briefcase at work, and the mark as data for the chip bar',
    str_contains($phtml, 'data-mark="💼"') && substr_count($phtml, 'class="pmk"') >= 3
    && str_contains($phtml, 'data-say="at work — the Bluebird Diner'), mb_substr($phtml, 0, 200));
ok('the unentered row is subdued and says why, and nobody crashed rendering it',
    str_contains($phtml, 'class="person out" data-h="cousin_rae"')
    && str_contains($phtml, 'not in the story yet')
    && substr_count($phtml, 'class="crow"') === count($PT['cast']['characters']));

$pnight = xeric_play_cast_html(xeric_play_cast($PT, $sw['db'], $pAt($PT, '2026-08-04 23:00')), 'floor-story', true);
ok('at night the home rows blank data-place — the chip bar must not pin a bedroom',
    str_contains($pnight, 'data-place="" data-doing="" data-short="Dot"')
    && str_contains($pnight, 'data-mark="💤"'), mb_substr($pnight, 0, 160));
ok('and the sleeping mark says so gently, in a sentence on the hover',
    str_contains($pnight, 'title="home and asleep, most likely"'));

// A template whose quiet hours cannot be read must not put the cast to sleep
// at noon: the mark falls back to engine-canon night, unlike the ping gate,
// which correctly treats an unreadable wall as protecting every hour.
$QT = $PT;
$QT['user']['quiet_hours'] = 'whenever grandma nods off';
// Janelle, not Dot: at three in the morning Dot's five-o'clock open is exactly
// two hours out, so she is honestly a PLAN — which is the precedence working,
// not the fallback failing.
ok('unreadable quiet hours: asleep at three in the morning, merely home at three in the afternoon',
    $pmarks($QT, '2026-08-04 03:00')['janelle']['state'] === 'asleep'
    && $pmarks($QT, '2026-08-04 15:00')['janelle']['state'] === 'home');

echo "\n# the book\n";

// ---------------------------------------------------------------------------
// book.php — the world writing its own novel, and the walls holding while it
// does. What is being defended, in the order it would hurt:
//   1. it is the OWNER's page — a stranger gets the inspector's refusal and
//      not one headline out of somebody else's record;
//   2. the day grouping is WORLD time — a known event lands under the world's
//      own day heading, in order among that day's material;
//   3. a conversation becomes a scene line and never a transcript — the book
//      keeps the fact of it and none of the words;
//   4. the ledger of promises (engine/constructs.php) reads: an open one on
//      the current day, a miss on the day it happened, and anything the parse
//      cannot honestly render is skipped rather than guessed at;
//   5. an hour filed as a dream is set in its own register — and until an
//      engine produces one, the register renders EMPTY, which is an absence
//      and not a fault;
//   6. a world with nothing lived renders a page, warning-free.
// ---------------------------------------------------------------------------

$BK = sid();
xeric_session_use($BK);
$bkDir = xeric_web_worlds_dir() . '/book-town';
@mkdir($bkDir, 0775, true);
@copy($srcWorld . '/world-template.json', $bkDir . '/world-template.json');
@copy($srcWorld . '/seed.json', $bkDir . '/seed.json');
xeric_session_claim('book-town', $BK);

$bw    = xeric_play_open('book-town');
$bT    = $bw['template'];
$bdb   = $bw['db'];
$bnow  = xeric_clock_now($bdb, $bT);
$bh    = (string)$bT['cast']['characters'][0]['handle'];
$bname = xeric_world_name($bT, $bh);

// A known event at a known world hour: yesterday, quarter past three.
$btz  = xeric_book_tz($bT);
$yest = (new DateTimeImmutable('@' . (int)$bnow['epoch']))->setTimezone($btz)->modify('-1 day')->setTime(15, 4);
xeric_event_add($bdb, 'the kiln finally cracked', $yest->getTimestamp(), null, [$bh],
    'The old kiln split down its seam, and everybody in the yard heard it go.');

// And a conversation the same evening. The words are canaried: if either ever
// reaches the page, the book has become the chat log it is not allowed to be.
$bconv = xeric_conversation_for($bdb, $bh);
xeric_message_append($bdb, $bconv, 'user', null, 'TRANSCRIPT_CANARY do not print me',
    $yest->setTime(20, 12)->getTimestamp());
xeric_message_append($bdb, $bconv, 'character', $bh, 'TRANSCRIPT_CANARY_TOO',
    $yest->setTime(20, 15)->getTimestamp());

$bk = xeric_book_days($bT, $bdb, $bnow);
$dayLabel = xeric_book_heading($bT, $yest->getTimestamp());
$bday = null;
foreach ($bk['days'] as $d) if ($d['label'] === $dayLabel) { $bday = $d; break; }
$bkinds = $bday !== null ? array_column($bday['items'], 'kind') : [];
ok('a known event is filed under the right world-day heading',
    $bday !== null && in_array('event', $bkinds, true)
    && array_filter($bday['items'], fn($i) => $i['kind'] === 'event'
        && (string)$i['event']['title'] === 'the kiln finally cracked') !== [], $dayLabel);
ok('the evening\'s conversation is on the same page as a scene',
    $bday !== null && in_array('scene', $bkinds, true)
    && array_filter($bday['items'], fn($i) => $i['kind'] === 'scene'
        && $i['scene']['handle'] === $bh && $i['scene']['yours'] === 1 && $i['scene']['theirs'] === 1) !== []);
ok('and the day reads forward — the afternoon before the evening',
    $bday !== null && array_search('event', $bkinds, true) < array_search('scene', $bkinds, true));
ok('a world whose nights never dreamed keeps an honestly empty register',
    !in_array('dream', $bkinds, true)
    && array_filter($bk['days'], fn($d) => in_array('dream', array_column($d['items'], 'kind'), true)) === []);

// The register itself, driven through the one seam it reads: the trail's kind.
$dreamAt = $yest->setTime(3, 30)->getTimestamp();
$did = xeric_event_add($bdb, 'the water kept rising', $dreamAt, null, [$bh],
    'The stairs went down further than the house did.');
xeric_world_state_set($bdb, 'why:event:' . $did, '{"kind":"dream"}');
$bk2 = xeric_book_days($bT, $bdb, $bnow);
$bday2 = null;
foreach ($bk2['days'] as $d) if ($d['label'] === $dayLabel) { $bday2 = $d; break; }
ok('an hour the trail files as a dream is set in the dream register, not among the waking day',
    $bday2 !== null
    && array_filter($bday2['items'], fn($i) => $i['kind'] === 'dream' && (int)$i['event']['id'] === $did) !== []
    && array_filter($bday2['items'], fn($i) => $i['kind'] === 'event' && (int)$i['event']['id'] === $did) === []);

// The ledger, in the shape engine/constructs.php actually writes — plus two
// rows no honest page could render, which must be skipped and never guessed at.
xeric_arc_set($bdb, $bh, 'expect.1', json_encode([
    'what' => 'the market', 'quote' => "I'll be at the market Saturday morning",
    'when_said' => 'saturday morning', 'due' => (int)$bnow['epoch'] + 2 * 86400,
    'formed' => (int)$bnow['epoch'] - 3600, 'state' => 'open']));
xeric_arc_set($bdb, $bh, 'expect.2', json_encode([
    'what' => 'thursday at the bar', 'quote' => 'I will be there Thursday',
    'when_said' => 'thursday', 'due' => $yest->setTime(18, 0)->getTimestamp(),
    'formed' => (int)$bnow['epoch'] - 5 * 86400, 'state' => 'missed',
    'missed_at' => $yest->setTime(23, 0)->getTimestamp()]));
xeric_arc_set($bdb, $bh, 'expect.junk', '42');
xeric_arc_set($bdb, $bh, 'expect.junk2', '{"state":"open"}');

$bx = xeric_book_expectations($bdb);
ok('the ledger reads what parses and skips what does not', count($bx) === 2,
    json_encode(array_column($bx, 'key')));

$bk3 = xeric_book_days($bT, $bdb, $bnow);
$btoday = null; $bday3 = null;
foreach ($bk3['days'] as $d) {
    if ($d['date'] === $bk3['pager']['today']) $btoday = $d;
    if ($d['label'] === $dayLabel) $bday3 = $d;
}
ok('an open promise stands on the current day',
    $btoday !== null && array_filter($btoday['promises'],
        fn($p) => str_contains($p['quote'], 'market Saturday morning')) !== []);
ok('and a miss lands on the day the waiting happened',
    $bday3 !== null && array_filter($bday3['items'],
        fn($i) => $i['kind'] === 'miss' && $i['expect']['status'] === 'missed'
            && str_contains($i['expect']['quote'], 'Thursday')) !== []);

// The page itself, as a browser gets it — the owner's whole, the stranger's nothing.
$bookOwner = $run('book.php', $BK, ['w' => 'book-town']);
ok('book.php prints the owner the day heading and its headline',
    str_contains($bookOwner, h($dayLabel)) && str_contains($bookOwner, 'the kiln finally cracked'),
    mb_substr($bookOwner, 0, 160));
ok('a conversation is a scene line and not one word of transcript',
    !str_contains($bookOwner, 'TRANSCRIPT_CANARY')
    && str_contains($bookOwner, ' spoke,') && str_contains($bookOwner, h($bname)));
ok('the promise is printed in the reader\'s own second person',
    str_contains($bookOwner, 'You told ' . h($bname)));
ok('the dream wears its own register on the page',
    str_contains($bookOwner, 'class="dream"') && str_contains($bookOwner, 'The stairs went down further'));

$bookStranger = $run('book.php', $B, ['w' => 'book-town']);
ok('a stranger is refused the book with the inspector\'s own sentence',
    str_contains($bookStranger, 'not yours to read'), mb_substr($bookStranger, 0, 160));
ok('and reads not a headline of it',
    !str_contains($bookStranger, 'the kiln finally cracked')
    && !str_contains($bookStranger, 'market Saturday morning'));

// Page turns: dates, not offsets, and the future is not offered.
$br = xeric_book_range($bT, $bdb, $bnow);
ok('the default page is the last seven lived days, today first',
    count($br['days']) === 7 && $br['days'][0]['date'] === $br['today'] && $br['later'] === '');
$brBack = xeric_book_range($bT, $bdb, $bnow, $br['days'][6]['date'], 7);
ok('turning back a page offers the way forward again',
    $brBack['from'] === $br['days'][6]['date'] && $brBack['later'] !== '');
$brFuture = xeric_book_range($bT, $bdb, $bnow, '2999-01-01', 7);
ok('a page asked for from the future is today — the book prints what was lived',
    $brFuture['from'] === $br['today']);

// A world with nothing lived at all: no seed, no events, no conversations.
// Driven with display_errors ON, because the shared runner silences exactly the
// warnings this assertion exists to catch.
$blank = xeric_web_worlds_dir() . '/blank-book';
@mkdir($blank, 0775, true);
@copy($srcWorld . '/world-template.json', $blank . '/world-template.json');
xeric_session_claim('blank-book', $BK);
$loudBoot = '$_GET = ' . var_export(['w' => 'blank-book'], true) . ';'
    . ' $_COOKIE = [' . var_export(XERIC_WEB_COOKIE, true) . ' => ' . var_export($BK, true) . '];'
    . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_ACCEPT"] = "text/html";'
    . ' require ' . var_export(dirname(__DIR__) . '/book.php', true) . ';';
$bookBlank = (string)shell_exec('XERIC_DATA_DIR=' . escapeshellarg($tmp)
    . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
    . ' ' . escapeshellarg($php) . ' -d error_reporting=E_ALL -d display_errors=1 -r '
    . escapeshellarg($loudBoot) . ' 2>&1');
ok('a world with nothing lived renders a quiet page, not a warning',
    str_contains($bookBlank, 'Nothing has been set down')
    && !str_contains($bookBlank, 'Warning:') && !str_contains($bookBlank, 'Notice:')
    && !str_contains($bookBlank, 'Deprecated:') && !str_contains($bookBlank, 'Fatal'),
    mb_substr($bookBlank, 0, 200));

echo "\n# the watch\n";

// ---------------------------------------------------------------------------
// watch.php — the duet's watching surface. What is being defended, in the
// order it would hurt:
//   1. it is the OWNER's page, refused at book.php's own door — a scene is the
//      world's insides moving;
//   2. admission is the ENGINE's: a separated pair is refused in the duet's
//      verbatim sentence, geography and all;
//   3. a watched line is a model call and SPENDS like one; a walk-in is not
//      and does not — and the next scheduled speaker answers having actually
//      seen the player's words, as a user turn;
//   4. nothing lands until the close, so an abandoned scene costs the world
//      nothing — and the close lands exactly what the CLI's close lands: one
//      commons event, each speaker's diary in their own head, one trail under
//      the inspector's key.
// Driven against engine/fixtures/milldale.json for the duet test's own
// geometry: Tue 07:30 puts ruth and dot at the Bluebird together, Mon 10:00
// puts pastor_dale and dot in different buildings.
// ---------------------------------------------------------------------------

$WK = sid();
xeric_session_use($WK);
$wtDir = xeric_web_worlds_dir() . '/watch-town';
@mkdir($wtDir, 0775, true);
@copy(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json', $wtDir . '/world-template.json');
xeric_session_claim('watch-town', $WK);

$ww  = xeric_play_open('watch-town');
$wT  = $ww['template'];
$wdb = $ww['db'];
$wep  = fn(string $when): int =>
    (new DateTimeImmutable($when, new DateTimeZone('America/New_York')))->getTimestamp();
$TUEW = xeric_world_now($wT, $wep('2026-07-28 07:30'));   // ruth + dot, the bluebird
$MONW = xeric_world_now($wT, $wep('2026-07-27 10:00'));   // dale and dot, two buildings

// The page itself: the owner's whole, the stranger's nothing.
$watchOwner = $run('watch.php', $WK, ['w' => 'watch-town']);
ok('watch.php serves its owner the watching surface',
    str_contains($watchOwner, 'two of them, talking')
    && str_contains($watchOwner, 'the duet plus a voice'), mb_substr($watchOwner, 0, 160));
$watchStranger = $run('watch.php', $B, ['w' => 'watch-town']);
ok('and refuses a stranger at the book\'s own door',
    str_contains($watchStranger, 'not yours to read')
    && !str_contains($watchStranger, 'two of them, talking'), mb_substr($watchStranger, 0, 160));

// Admission is the engine's, sentence and all.
$apartW = '';
try { xeric_watch_start($ww, 'pastor_dale', 'dot', $MONW); }
catch (Throwable $e) { $apartW = $e->getMessage(); }
ok('a separated pair is refused with the duet\'s own geography',
    str_contains($apartW, 'not in a room together')
    && str_contains($apartW, 'First Lutheran') && str_contains($apartW, 'Bluebird'), $apartW);

// The scene, driven through the stub seam — no network, no model.
$wcalls = [];
$wn = 0;
$wstub = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use (&$wcalls, &$wn) {
    $wcalls[] = ['tag' => $tag, 'msgs' => $msgs];
    if ($tag === 'chat') { $wn++; return "watched line $wn, nothing much."; }
    if ($tag === 'extract') {
        $u = (string)$msgs[1]['content'];
        if (str_contains($u, 'Ruth Amberg would still know')) {
            return ['memories' => ['Ruth heard the freezer was on its way out.']];
        }
        if (str_contains($u, 'Dot Vance would still know')) {
            return ['memories' => ['Dot noticed Ruth folding the same napkin twice.']];
        }
        return ['memories' => []];
    }
    return ['memories' => []];
}];

$wbase = [xeric_events_count($wdb), xeric_memories_count($wdb), xeric_conversations_count($wdb)];
reset_limits();

$ws = xeric_watch_start($ww, 'ruth', 'dot', $TUEW);
ok('the pair the presence read admits opens a scene in their shared room',
    (string)$ws['room']['where'] === 'bluebird'
    && in_array($ws['turns'], [6, 7], true)
    && in_array($ws['first'], ['ruth', 'dot'], true));

$wleft = xeric_limit_left($WK)['messages'];
$wl1 = xeric_watch_line($ww, $ws, $wstub, $WK);
ok('one watched line is one model call, in the scheduled mouth',
    $wl1['handle'] === $ws['first'] && str_contains($wl1['text'], 'watched line 1'));
ok('and it spends a message note like any turn — watching is not a way around the meter',
    xeric_limit_left($WK)['messages'] === $wleft - 1,
    $wleft . ' → ' . xeric_limit_left($WK)['messages']);

$wl2 = xeric_watch_line($ww, $ws, $wstub, $WK);
ok('the speakers strictly alternate', $wl2['handle'] !== $wl1['handle']);

// The walk-in: appended as the player, no model call, nothing spent.
$wmid      = xeric_limit_left($WK)['messages'];
$wmidCalls = count($wcalls);
xeric_watch_say($ww, $ws, 'It is only me, do not stop on my account.');
$wlastLine = $ws['lines'][count($ws['lines']) - 1];
ok('a walk-in appends the player as themselves — no model call, nothing spent',
    (string)$wlastLine['handle'] === XERIC_WATCH_PLAYER
    && count($wcalls) === $wmidCalls
    && xeric_limit_left($WK)['messages'] === $wmid);

$wl3 = xeric_watch_line($ww, $ws, $wstub, $WK);
$wlastChat = null;
foreach ($wcalls as $c) if ($c['tag'] === 'chat') $wlastChat = $c;
$wsaw = false;
foreach ((array)$wlastChat['msgs'] as $m) {
    if ((string)$m['role'] === 'user' && str_contains((string)$m['content'], 'only me, do not stop')) $wsaw = true;
}
ok('alternation carries on, and the next speaker answers having seen the player\'s words as a user turn',
    $wl3['handle'] === $wl1['handle'] && $wsaw);

// Nothing has landed, so walking away costs the world nothing.
ok('a scene in flight has written nothing — abandoning it leaves the world exactly as found',
    [xeric_events_count($wdb), xeric_memories_count($wdb), xeric_conversations_count($wdb)] === $wbase);
unset($ws);                              // the abandon: the state simply stops being held

// A fresh scene, ended early on purpose: the affirmative close is the CLI's.
$ws2 = xeric_watch_start($ww, 'ruth', 'dot', $TUEW);
xeric_watch_line($ww, $ws2, $wstub, $WK);
xeric_watch_line($ww, $ws2, $wstub, $WK);
$closedW = xeric_watch_close($ww, $ws2, $wstub);
ok('the close lands one event, titled as the CLI\'s close titles it',
    xeric_events_count($wdb) === $wbase[0] + 1 && $closedW['title'] === 'ruth and dot talked');
$wev = xeric_events_recent($wdb, 1)[0];
ok('the record is commons — both names, the place, and not one watched word',
    str_contains((string)$wev['prose'], 'Ruth Amberg and Dot Vance')
    && (string)$wev['place'] === 'bluebird'
    && !str_contains((string)$wev['prose'], 'watched line'), (string)$wev['prose']);
$wmemR  = xeric_memories_for($wdb, 'ruth', 5);
$wlastM = $wmemR[count($wmemR) - 1];
ok('the diaries land in the right heads, marked duet, carrying event, partner and place',
    (string)$wlastM['source'] === 'duet'
    && (int)$wlastM['meta']['event_id'] === (int)$closedW['event_id']
    && $wlastM['meta']['with'] === ['dot'] && (string)$wlastM['meta']['place'] === 'bluebird'
    && $closedW['memories']['dot'] === ['Dot noticed Ruth folding the same napkin twice.']);
$wtrail = json_decode((string)xeric_world_state_get($wdb, 'why:event:' . $closedW['event_id']), true);
ok('the trail lands under the inspector\'s key, kind duet, and owns up to being watched',
    is_array($wtrail) && $wtrail['kind'] === 'duet'
    && $wtrail['people'] === ['ruth', 'dot'] && !empty($wtrail['watched']),
    json_encode($wtrail));
ok('and no thread was created — the transcript was watched, not texted',
    xeric_conversations_count($wdb) === $wbase[2]);

// A scene where nobody spoke closes to nothing: "they talked" may not be
// written about a room where nobody did.
$ws3 = xeric_watch_start($ww, 'ruth', 'dot', $TUEW);
$emptyW = xeric_watch_close($ww, $ws3, $wstub);
ok('a scene with no spoken line closes to nothing at all',
    !empty($emptyW['empty']) && xeric_events_count($wdb) === $wbase[0] + 1);

// ---------------------------------------------------------------------------
// The watch re-states the duet's three laws — age floor, protected secret,
// minor clamp — in its own loop, and until now not one of the three had an
// assertion: deleting any of them left all suites green while the engine's
// twins (duet-test) stayed proven. The fixture has always had the people these
// need; the cases were reachable and simply were not written.
// ---------------------------------------------------------------------------

echo "\n# the watch keeps the duet's laws\n";

// The minor clamp at the door: harlan and twelve-year-old theo share Saturday
// morning at the store (the hour duet-test's own floor case uses).
$SATW = xeric_world_now($wT, $wep('2026-08-01 10:00'));
$wsKid = xeric_watch_start($ww, 'harlan', 'theo', $SATW);
ok('watch: a child in the scene pins it to the weakest rating, structurally',
    $wsKid['minor'] === true && (string)$wsKid['eff'] === 'sfw', json_encode([$wsKid['minor'], $wsKid['eff']]));
ok('watch: and an all-adult scene keeps the world\'s own rating',
    $ws3['minor'] === false, json_encode([$ws3['minor'] ?? null, $ws3['eff'] ?? null]));

// The floor on the way through the loop: a sexual line in the child's scene is
// refused whoever's mouth it is in — duet-test proves the engine's copy; this
// proves the watch's.
$kidStub = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) {
    if ($tag === 'chat') return 'All this talk made him horny.';
    return ['memories' => []];
}];
$floorMsg = '';
try { xeric_watch_line($ww, $wsKid, $kidStub, $WK); }
catch (Throwable $e) { $floorMsg = $e->getMessage(); }
ok('watch: a sexual line in a scene with a child is refused, by name',
    str_contains($floorMsg, 'child') && $floorMsg !== '', $floorMsg);
ok('watch: and the refused line never entered the transcript',
    (int)$wsKid['spoken'] === 0 && (array)$wsKid['lines'] === []);

// The protected secret, in the watch's own loop. Milldale's janelle carries
// the thursday-pot protection; give her scene a line that names it.
$wTP = $ww;
$wTP['template']['cast']['special_roles'][0]['must_not_know'] =
    'what happens at the thursday pot game in the church basement';
$SUNW  = xeric_world_now($wT, $wep('2026-08-02 10:00'));
$wsSec = xeric_watch_start($wTP, 'pastor_dale', 'janelle', $SUNW);
$secStub = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) {
    if ($tag === 'chat') return 'the thursday pot game happens in the church basement, after supper';
    return ['memories' => []];
}];
$secMsg = '';
try { xeric_watch_line($wTP, $wsSec, $secStub, $WK); }
catch (Throwable $e) { $secMsg = $e->getMessage(); }
ok('watch: a line that puts the protected listener next to the secret is refused',
    str_contains($secMsg, 'must not know'), $secMsg);
ok('watch: and nothing of it was kept',
    (int)$wsSec['spoken'] === 0 && (array)$wsSec['lines'] === []);

// ---------------------------------------------------------------------------
// THE SIDEBAR FOLDS, AND THE COUNTS DO NOT LIE.
//
// The sections collapse so twelve characters are a glance rather than a scroll,
// which means a shut section's number is the ONLY thing left saying there is
// anything behind it. A number that disagrees with what opens is worse than no
// number at all — it is the panel telling you nothing happened when something
// did — so every count is asserted against the rows actually rendered inside
// its own block rather than against a constant.
// ---------------------------------------------------------------------------

echo "\n# the sidebar's collapsible sections\n";

// Two real events and one with no title: a titleless event renders nothing, so
// counting it would promise a row that never arrives.
xeric_event_add($wdb, 'A broken porcelain tea set', $TUEW['epoch'] - 7200, 'bluebird', ['ruth', 'dot'], 'In pieces by the time anybody looked.');
xeric_event_add($wdb, '', $TUEW['epoch'] - 5400, 'bluebird', ['ruth'], 'no title — must not be counted');
xeric_event_add($wdb, 'The long way round', $TUEW['epoch'] - 3600, 'bluebird', ['dot'], 'Nobody said why.');

$side = xeric_play_side_html($wT, $wdb, $TUEW, 'watch-town', true);

// Every block is its own chunk: .sideblock details are never nested in each
// other, so splitting on the open tag gives one chunk per section — and the
// events' own <details> stay inside the chunk they belong to.
$chunks = [];
foreach (array_slice(explode('<details class="sideblock" ', $side), 1) as $c) {
    if (preg_match('~^data-sb="([a-z]+)"( open)?>~', $c, $mm)) {
        $chunks[$mm[1]] = ['open' => isset($mm[2]) && $mm[2] !== '', 'html' => $c];
    }
}
$sbn = function (string $k) use ($chunks): ?int {
    if (!isset($chunks[$k]) || !preg_match('~<span class="sbn">(\d+)</span>~', $chunks[$k]['html'], $m)) return null;
    return (int)$m[1];
};

ok('sidebar: all three sections render as collapsible blocks',
    isset($chunks['where'], $chunks['away'], $chunks['recent']), implode(',', array_keys($chunks)));

// THE WAY IN. Buried under the cog it was a setting; in the sidebar it is a
// door, which is what it actually is — somebody in the same house should be
// able to SEE that letting a person in is a thing this program does.
ok('sidebar: the owner is always shown a way to let somebody in',
    isset($chunks['who']) && str_contains($chunks['who']['html'], 'let somebody in'));
ok('sidebar: with who is already at the centre, and whose world it is',
    str_contains($chunks['who']['html'], 'whose world it is'));
ok('sidebar: and it arrives folded while there is nobody but you',
    ($chunks['who']['open'] ?? true) === false);

// A GUEST IS NOT SHOWN THE DOOR, and while they are the only other person the
// panel is not a permanent reminder of whose house they are in.
$guestSide = xeric_play_side_html($wT, $wdb, $TUEW, 'watch-town', false);
ok('sidebar: a guest is offered no way to invite anybody',
    !str_contains($guestSide, 'let somebody in'));

// With somebody actually in, both of them are named and it opens on its own.
$gid = xeric_player_add($wdb, $wT, 'Corey');
xeric_guest_arrive($wdb, $wT, $gid);
$side2 = xeric_play_side_html($wT, $wdb, $TUEW, 'watch-town', true);
ok('sidebar: once somebody is in, they are named and it opens itself',
    str_contains($side2, 'Corey') && str_contains($side2, 'came with')
    && preg_match('~data-sb="who" open~', $side2) === 1);
ok('sidebar: and a guest can see who else is at the centre with them',
    str_contains(xeric_play_side_html($wT, $wdb, $TUEW, 'watch-town', false), 'Corey'));

// The old markup is gone entirely. A half-migration — one block folded, two
// still bare headings — reads as a rendering bug rather than a design.
ok('sidebar: no bare <h3> heading survives the migration',
    strpos($side, '<h3 class="sh"') === false);

// Where everybody is opens: it is the panel the sidebar exists for. The other
// two arrive shut, which is the whole point of the change.
ok('sidebar: "where everybody is" is open by default',
    ($chunks['where']['open'] ?? false) === true);
ok('sidebar: "not out" and "while you were away" arrive folded',
    ($chunks['away']['open'] ?? true) === false && ($chunks['recent']['open'] ?? true) === false);

// Each count against the rows actually inside its own block.
ok('sidebar: the "where" count is the people standing in the rooms, not the rooms',
    $sbn('where') === substr_count($chunks['where']['html'], 'class="wperson"'),
    'badge ' . var_export($sbn('where'), true) . ' vs rows ' . substr_count($chunks['where']['html'], 'class="wperson"'));
ok('sidebar: the "not out" count matches the people listed under it',
    $sbn('away') === preg_match_all('~<button type="button" class="wperson~', $chunks['away']['html']),
    'badge ' . var_export($sbn('away'), true));
ok('sidebar: the "while you were away" count matches the events that rendered',
    $sbn('recent') === substr_count($chunks['recent']['html'], '<details class="ev"'),
    'badge ' . var_export($sbn('recent'), true));

// The specific lie the filter exists to prevent. Not asserted as a constant:
// this world already has events in it from the duet section above, and a
// hardcoded total would be testing the fixture rather than the filter. The
// property that matters is that nothing titleless got through — an empty
// summary is a row you cannot read and, worse, a row the badge promised.
ok('sidebar: a titleless event is neither counted nor drawn',
    strpos($chunks['recent']['html'], '<summary></summary>') === false
    && $sbn('recent') === substr_count($chunks['recent']['html'], '<details class="ev"'),
    'badge ' . var_export($sbn('recent'), true));

// And the titled ones did arrive, so the filter is not simply dropping everything.
ok('sidebar: the events that do have titles are the ones that render',
    strpos($chunks['recent']['html'], 'A broken porcelain tea set') !== false
    && strpos($chunks['recent']['html'], 'The long way round') !== false);

// Zero is a number. At an hour when nobody is anywhere the badge must still
// print, or a folded section is indistinguishable from one that never counted.
$deadHour = xeric_world_now($wT, $wep('2026-07-28 04:00'));
$night = xeric_play_side_html($wT, $wdb, $deadHour, 'watch-town', true);
ok('sidebar: the count still prints when it is zero',
    preg_match('~data-sb="where" open><summary class="sh">where everybody is<span class="sbn">(\d+)</span>~', $night) === 1,
    substr($night, 0, 160));

// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// THE TIME MENU. "skip to morning" and "sleep until morning" are the same
// stretch of clock and a different thing to have done, so the row offers
// whichever one is honest at the hour you are standing in — and never both,
// because the same jump twice is a cluttered row, not a choice.
// ---------------------------------------------------------------------------

echo "\n# the time menu\n";

$spT   = ['user' => ['timezone' => 'UTC']];
$atHr  = function (string $hhmm) use ($spT): array {
    return xeric_world_now($spT, (new DateTimeImmutable('2026-08-03 ' . $hhmm,
        new DateTimeZone('UTC')))->getTimestamp());
};
$spNight = xeric_play_spans($atHr('23:00'), $spT);
$spDay   = xeric_play_spans($atHr('14:00'), $spT);

ok('spans: late enough for it, the row offers sleep',
    isset($spNight['sleep']) && $spNight['sleep']['label'] === 'sleep until morning');
ok('spans: and not the same jump twice under a second name',
    !isset($spNight['morning']));
ok('spans: at two in the afternoon it is a nap, and this world has no nap button',
    !isset($spDay['sleep']) && isset($spDay['morning']));
ok('spans: sleep lands where skip to morning would have',
    $spNight['sleep']['seconds'] === xeric_play_spans($atHr('23:00'), null)['sleep']['seconds']
    && $spNight['sleep']['to'] === $spDay['morning']['to']);
ok('spans: and the ordinary offers survive it',
    isset($spNight['hour'], $spNight['evening']));

// ---------------------------------------------------------------------------
// SITTING DOWN AT A TABLE. Owner-only, and refusing on the wrong night rather
// than spawning a worker that would find nothing to do.
// ---------------------------------------------------------------------------

echo "\n# sitting down\n";

ok('sit: a stranger cannot sit at somebody else\'s table',
    str_contains($run('play.php', $B, ['a' => 'sit', 'w' => 'lived-in']), 'Only the owner')
    || str_contains($run('play.php', $B, ['a' => 'sit', 'w' => 'lived-in']), 'not yours'));
ok('sit: and a game that is not there is not a game',
    str_contains($run('play.php', $A, ['a' => 'sit', 'w' => 'lived-in']), 'no game there'));

// ---------------------------------------------------------------------------
// THE EXPERIMENTAL DISCUSSION TAB. Asserted against the source rather than a
// rendered page on purpose: forge.php redirects to model.php when no machine is
// connected, which every headless harness is, so a DOM check there would be
// testing the machines screen and reporting on the forge.
// ---------------------------------------------------------------------------

// A discussion xeric, run through the real page. The report is the deliverable
// of this whole mode, so it is asserted end to end rather than by unit.
require_once dirname(__DIR__, 3) . '/forge/panel-forge.php';

$panelT = xeric_forge_panel_world('twenty percent has to come out of the budget', [
    'question' => 'where does the twenty percent come from',
    'room' => ['name' => 'the Ridgeline lodge', 'what' => 'After dinner, and nobody has gone up.'],
    'people' => [
        ['name' => 'Ada Reyes',   'stake' => 'the people on the floor',
         'red_line' => 'I will not accept anything that puts the cost on people who did not choose it'],
        ['name' => 'Tom Vance',   'stake' => 'the balance sheet',
         'red_line' => 'I will not accept a plan that leaves us insolvent by spring'],
        ['name' => 'Priya Nandi', 'stake' => 'how it is told',
         'red_line' => 'I will not accept a decision made without telling the people it lands on'],
    ],
]);
$pdir = $tmp . '/worlds/argument';
@mkdir($pdir, 0777, true);
file_put_contents($pdir . '/world-template.json', json_encode($panelT, JSON_PRETTY_PRINT));
xeric_session_claim('argument', $A);

// The session has to be built INSIDE the request, because $run forks a
// subprocess with its own XERIC_DATA_DIR and a world opened out here lands in a
// different database entirely. Whole argument, then the page, in one boot.
$seed = '$_GET = ["w" => "argument"];'
    . ' $_COOKIE = [' . var_export(XERIC_WEB_COOKIE, true) . ' => ' . var_export($A, true) . '];'
    . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_ACCEPT"] = "text/html";'
    . ' require ' . var_export(dirname(__DIR__) . '/play-lib.php', true) . ';'
    . ' require ' . var_export(dirname(__DIR__, 3) . '/engine/panel.php', true) . ';'
    . ' $w = xeric_play_open("argument"); $d = $w["db"]; $T = $w["template"];'
    . ' xeric_panel_think($d, "ada_reyes", "We should look at the lease before anybody talks about people.",'
    . '   "the building costs more than four of the jobs and nobody has read the break clause");'
    . ' xeric_panel_think($d, "tom_vance", "Twelve go on Friday and we are solvent by March.",'
    . '   "the covenant test is the last day of Q1");'
    . ' xeric_panel_think($d, "priya_nandi", "Then say it on Monday, not at five on a Friday.", "");'
    . ' $x = xeric_panel_propose($d, "Nobody goes, and we borrow against next year.");'
    . ' xeric_panel_table($T, $d, $x, ["base" => "stub://", "stub" => function ($tag, $m) {'
    . '   return ["crosses" => str_contains((string)$m[0]["content"], "Tom"), "because" => "that is the covenant, gone"]; }]);'
    . ' xeric_panel_made($d, "the rota", "mon: ada", "text", "ada_reyes");'
    . ' require ' . var_export(dirname(__DIR__) . '/debrief.php', true) . ';';

$deb = (string)shell_exec('XERIC_DATA_DIR=' . escapeshellarg($tmp)
    . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
    . ' ' . escapeshellarg($php) . ' -d error_reporting=0 -r ' . escapeshellarg($seed) . ' 2>&1');

ok('debrief: a hung room is reported as a finding, not an apology',
    str_contains($deb, 'did not get there, and that is the answer'));
ok('debrief: and it names the pair nothing satisfied at once',
    str_contains($deb, 'Nothing satisfied') && str_contains($deb, 'Tom Vance'));
ok('debrief: every position is printed with the refusal behind it',
    str_contains($deb, 'Will not accept') && str_contains($deb, 'insolvent by spring'));
ok('debrief: every proposal carries who could live with it and who would not',
    str_contains($deb, 'can live with it') && str_contains($deb, 'will not have it'));
ok('debrief: the reasoning under each turn is on the page, because it is open',
    str_contains($deb, 'the covenant test is the last day'));
ok('debrief: the thread nobody followed is called out for the reader too',
    str_contains($deb, 'Threads nobody followed') && str_contains($deb, 'look at the lease'));
ok('debrief: what the room built is shown above the argument about it',
    str_contains($deb, 'What the room made') && str_contains($deb, 'the rota'));
ok('debrief: and the page says experimental where somebody will read it',
    str_contains($deb, 'EXPERIMENTAL'));
ok('debrief: a stranger is shown none of it',
    str_contains($run('debrief.php', $B, ['w' => 'argument']), 'not yours to read'));
ok('debrief: and an ordinary xeric is told to read its book instead',
    str_contains($run('debrief.php', $A, ['w' => 'lived-in']), 'not a discussion'));
ok('debrief: the owner gets the box that puts things to them',
    str_contains($deb, 'put it to the room') && str_contains($deb, 'have them write it')
    && str_contains($deb, 'let them argue'));
ok('debrief: and a link to the thing the room made, as itself',
    str_contains($deb, '&amp;a=0') && str_contains($deb, 'download'));
ok('debrief: and is told plainly that both of those spend tokens',
    str_contains($deb, 'spend tokens') && str_contains($deb, 'real money'));
ok('debrief: a stranger is offered no box to spend anybody\'s money with',
    !str_contains($run('debrief.php', $B, ['w' => 'argument']), 'put it to the room'));

// The endpoints behind those two buttons: owner-only, and refusing an ordinary
// world rather than spawning a worker that would find no room to talk to.
$prop = $run('play.php', $B, ['a' => 'propose', 'w' => 'argument']);
// THE RAW ROUTE. "Provide you the link" has to mean an actual link if the
// deliverable is a program — and it has to be text/plain whatever the room
// called its language, because this is a model writing at somebody's prompting
// and serving it as text/html would be handing a stored XSS a content type.
$xss = '<script>alert(1)</script> print("hi")';
// The index is whatever xeric_panel_made returns — the debrief block above
// already wrote one into this same world, so hard-coding 0 tests that one.
$rawBoot = '$_GET = ["w" => "argument"];'
    . ' $_COOKIE = [' . var_export(XERIC_WEB_COOKIE, true) . ' => ' . var_export($A, true) . '];'
    . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_ACCEPT"] = "text/html";'
    . ' require ' . var_export(dirname(__DIR__) . '/play-lib.php', true) . ';'
    . ' require ' . var_export(dirname(__DIR__, 3) . '/engine/panel.php', true) . ';'
    . ' $w = xeric_play_open("argument");'
    . ' $_GET["a"] = (string)xeric_panel_made($w["db"], "the script", ' . var_export($xss, true) . ', "html", "");'
    . ' require ' . var_export(dirname(__DIR__) . '/debrief.php', true) . ';';
$raw = (string)shell_exec('XERIC_DATA_DIR=' . escapeshellarg($tmp)
    . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
    . ' ' . escapeshellarg($php) . ' -d error_reporting=0 -r ' . escapeshellarg($rawBoot) . ' 2>&1');
ok('raw: the artifact is served as itself, with nothing around it',
    trim($raw) === $xss, mb_substr($raw, 0, 120));
ok('raw: and an artifact that does not exist is a plain 404, not a page',
    str_contains($run('debrief.php', $A, ['w' => 'argument', 'a' => '99']), 'no such thing'));

ok('panel: a stranger cannot put anything to somebody else\'s room',
    str_contains($prop, 'Only the owner') || str_contains($prop, 'not yours'));
ok('panel: and an ordinary xeric is not a room with a question in it',
    str_contains($run('play.php', $A, ['a' => 'propose', 'w' => 'lived-in']), 'place to live in'));

echo "\n# the discussion door\n";

$forgeSrc = (string)file_get_contents(dirname(__DIR__) . '/forge.php');
ok('panel: the forge offers a problem as well as a place',
    str_contains($forgeSrc, 'data-mode="panel"'));
ok('panel: and the tab says experimental where somebody will read it',
    str_contains($forgeSrc, 'EXPERIMENTAL. Describe a problem'));
// A DOOR OF ITS OWN (owner, 2026-08-03). It was reachable only as a tab inside
// "Your Own Idea", which is the wrong shape — those tabs answer "how do you want
// to describe your xeric" and this does not describe a xeric at all.
ok('panel: it is a way in beside the other three, not a tab underneath one',
    str_contains($forgeSrc, 'data-way="panel"')
    && str_contains($forgeSrc, '>Solve a Problem<'));
ok('panel: and the tile says experimental where somebody reads it before pressing',
    str_contains($forgeSrc, 'class="wexp">experimental'));
ok('panel: the tile lights the existing tab rather than repeating what it does',
    str_contains($forgeSrc, '.w3[data-mode="panel"]'));
ok('panel: and Your Own Idea puts the question back when you come from it',
    str_contains($forgeSrc, '.w3[data-mode="type"]'));
ok('panel: the flag reaches the builder rather than stopping at the page',
    str_contains($forgeSrc, 'panel: PANEL')
    && str_contains((string)file_get_contents(dirname(__DIR__) . '/build.php'), "'panel'")
    && str_contains((string)file_get_contents(dirname(__DIR__) . '/worker.php'), 'xeric_forge_panel('));

// ---------------------------------------------------------------------------
// THE LAUNCHER AND THE APP MUST NAME THE SAME SHELF.
//
// bootstrap.php decides where things live and prints `export XERIC_WORLDS_DIR=…`
// for the launchers to eval; boot.php decides the same thing for every process
// that did not come from a launcher. They each held their own copy of the rule
// and the copies disagreed — the environment variable always won, so a checkout
// with a `worlds/` directory kept its xerics there when somebody ran `php -S` by
// hand, and the launcher then showed an empty shelf. Nothing lost, everything
// apparently lost.
//
// Driven end to end rather than asserted about the source: run the real
// bootstrap, read the real assignment, and ask a real boot.php in its own
// process what it thinks. XERIC_LOCAL_BASE is pinned so the config never probes
// for a model, and XERIC_WORLDS_DIR is CLEARED for both children — this file
// putenv()s one for its own use, children inherit it, and an explicit setting
// outranks the default that is the thing under test.
// ---------------------------------------------------------------------------

$wdDir  = $tmp . '/shelf';
@mkdir($wdDir, 0775, true);
$root   = dirname(__DIR__, 3);
$emit   = (string)shell_exec('XERIC_WORLDS_DIR= XERIC_DATA_DIR=' . escapeshellarg($wdDir)
    . ' php ' . escapeshellarg($root . '/bootstrap.php') . ' --sh 2>/dev/null');
$said   = preg_match("/XERIC_WORLDS_DIR='([^']*)'/", $emit, $wdM) === 1 ? $wdM[1] : '';
$thinks = trim((string)shell_exec('XERIC_WORLDS_DIR= XERIC_DATA_DIR=' . escapeshellarg($wdDir)
    . ' XERIC_LOCAL_BASE=http://127.0.0.1:1'
    . ' php -r ' . escapeshellarg('require ' . var_export(dirname(__DIR__) . '/boot.php', true)
        . '; echo xeric_web_config()["worlds_dir"];') . ' 2>/dev/null'));

ok('shelf: the launcher says where xerics live', $said !== '', $emit);
ok('shelf: and the app says the same thing, started any other way',
    $said !== '' && $said === $thinks, "launcher=$said app=$thinks");
ok('shelf: which is under the data directory, where the rest of the install is',
    $said === $wdDir . '/worlds', $said);
ok('shelf: and an explicit setting still beats the default',
    xeric_web_worlds_default('/somewhere/else') === '/somewhere/else/worlds'
    && xeric_web_worlds_default('/trailing/') === '/trailing/worlds');

// ---------------------------------------------------------------------------
// NOBODY LEAVES THE ROOM HOLDING THE MODEL.
//
// PHP does not run a `finally` on `exit()`. Every detached worker takes the one
// model slot and hands it back in a finally, so an `exit()` anywhere between
// those two points leaves the queue pointing at a process that is already gone
// — and the next person waits out the whole hold for a GPU that is free.
//
// This is structural, so it is checked structurally: from the line that takes
// the hold to the `finally` that gives it back, there may be no exit. The way
// out is `$done()`, which releases first. Found by reading, not by a test:
// panel-worker.php was leaking on ALL THREE of its outcomes, including both of
// the ones that succeed.
//
// Through token_get_all() and T_EXIT rather than a search for the word, because
// the first version of this check flagged addchar-worker.php's own COMMENT
// warning against early exits. A grep over source is a test of prose; the
// tokeniser sees code and nothing else.
// ---------------------------------------------------------------------------

$heldRegion = static function (string $src): ?array {
    $take = null;
    $toks = token_get_all($src);
    foreach ($toks as $i => $t) {
        if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$got') continue;
        // `$lock = $got['hold']` — the moment the slot becomes ours.
        $tail = '';
        for ($j = $i; $j < min($i + 4, count($toks)); $j++) {
            $tail .= is_array($toks[$j]) ? $toks[$j][1] : $toks[$j];
        }
        if (str_starts_with($tail, "\$got['hold']")) { $take = $t[2]; break; }
    }
    if ($take === null) return null;
    foreach ($toks as $t) {
        if (is_array($t) && $t[0] === T_FINALLY && $t[2] > $take) return [$take, $t[2]];
    }
    return [$take, PHP_INT_MAX];
};

foreach (['worker', 'addchar-worker', 'reroll-worker', 'panel-worker', 'tick-worker',
          'story-worker', 'table-worker'] as $wk) {
    $src  = (string)file_get_contents(dirname(__DIR__) . '/' . $wk . '.php');
    $span = $heldRegion($src);
    if ($span === null) {                        // takes no hold; nothing to leak
        ok("slot: $wk holds no model slot, so it cannot strand one", true);
        continue;
    }
    [$take, $give] = $span;
    ok("slot: $wk hands the slot back in a finally", $give !== PHP_INT_MAX);
    $stranding = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && $t[0] === T_EXIT && $t[2] > $take && $t[2] < $give) $stranding[] = $t[2];
    }
    ok("slot: and $wk never exits while holding it — that finally would not run",
        $stranding === [], 'line(s) ' . implode(', ', $stranding));
}

// ---------------------------------------------------------------------------
// WHO THIS MACHINE IS, WHEN FOUR REQUESTS ASK AT ONCE.
//
// The first multi-process race in these suites, and it is here because the
// answer is not a number in a database — in solo mode the machine id IS the
// visitor's ownership identity, so two of them means two owners of one install
// and whoever loses finds the worlds they made belong to somebody else. A page
// that fires a few requests at a cold install is the whole reproduction.
//
// The sessions below are what make it deterministic rather than lucky. The
// adoption pass globs and JSON-decodes every session file before it writes, so
// filling that directory holds all four processes inside the same stretch of
// work and they arrive at the write together. `own` is empty in all of them so
// nobody is adopted and each process must mint its own id — with adoption
// there is nothing to disagree about. Measured before it was written: the
// check-then-act version this replaces returned 3 or 4 distinct ids in every
// one of eight rounds at this size, and the locked one returns 1.
// ---------------------------------------------------------------------------

$rcDir = $tmp . '/race';
@mkdir($rcDir . '/sessions', 0775, true);
for ($i = 1; $i <= 1500; $i++) {
    file_put_contents($rcDir . '/sessions/' . sprintf('%032x', $i) . '.json', '{"own":[]}');
}
file_put_contents($rcDir . '/who.php',
    "<?php require_once " . var_export(dirname(__DIR__) . '/boot.php', true)
    . "; echo xeric_web_machine_id();\n");

$rcIds = [];
for ($round = 0; $round < 3; $round++) {
    @unlink($rcDir . '/identity');
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $procs[$i] = proc_open(
            'php ' . escapeshellarg($rcDir . '/who.php'),
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes[$i],
            null, ['XERIC_DATA_DIR' => $rcDir, 'PATH' => getenv('PATH')]);
    }
    $said = [];
    for ($i = 0; $i < 4; $i++) {
        $said[] = trim((string)stream_get_contents($pipes[$i][1]));
        fclose($pipes[$i][1]);
        proc_close($procs[$i]);
    }
    $rcIds[] = $said;
}

$rcDistinct = array_map(fn($r) => count(array_unique(array_filter($r))), $rcIds);
ok('identity: four requests at a cold install agree on one machine, every round',
    $rcDistinct === [1, 1, 1], json_encode($rcIds));
ok('identity: and what they agree on is what is on the disk',
    ($rcIds[2][0] ?? '') !== ''
    && trim((string)file_get_contents($rcDir . '/identity')) === $rcIds[2][0]);

// A truncated identity — a crash mid-write, a disk that filled — must repair
// itself. The check-then-act version did this by accident, and an exclusive
// create would have stopped doing it, which is why the claim is a lock and a
// read rather than an O_EXCL.
file_put_contents($rcDir . '/identity', '');
$repaired = trim((string)shell_exec('XERIC_DATA_DIR=' . escapeshellarg($rcDir)
    . ' php ' . escapeshellarg($rcDir . '/who.php')));
ok('identity: an emptied identity file is repaired, not inherited as nothing',
    preg_match('/^[a-f0-9]{32}$/', $repaired) === 1
    && trim((string)file_get_contents($rcDir . '/identity')) === $repaired);
ok('identity: and the repair sticks — the next request adopts it rather than minting again',
    trim((string)shell_exec('XERIC_DATA_DIR=' . escapeshellarg($rcDir)
        . ' php ' . escapeshellarg($rcDir . '/who.php'))) === $repaired);

rmtree($tmp);

echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
exit($FAILED === 0 ? 0 : 1);
