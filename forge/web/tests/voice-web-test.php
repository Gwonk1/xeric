<?php
/**
 * Xeric — the cast out loud, the web half. `php forge/web/tests/voice-web-test.php`,
 * exit 0 on pass.
 *
 * engine/tests/voice-test.php proves the deal, the words and the cache; this
 * proves what the browser can ask for and what it is handed. NO NETWORK: the
 * machine is the engine's stub seam, registered through the same resolver the
 * session's machine goes through, and it counts its own calls.
 *
 * What is being defended, in the order it would hurt:
 *
 *   1. NOBODY CAN BE MADE TO SAY ANYTHING. The line is a row in this world, a
 *      character's, found by id — the player's own lines, the narrator's, and
 *      an id this world never wrote are all refused.
 *   2. A LINE IS RENDERED ONCE. The second press is the engine's cache, and
 *      the rate limit is spent on the render only.
 *   3. WHAT IS SERVED IS WHAT IT SAYS IT IS — magic bytes against the type.
 *   4. THE SPEAKER IS OFFERED ONLY WITH A MACHINE, and the machine is never
 *      probed to decide it.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-voice-web-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called
foreach (['XERIC_VOICE_BASE', 'XERIC_VOICE_KEY', 'XERIC_VOICE_MODEL', 'XERIC_VOICE_FORMAT'] as $k) putenv($k);

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

function rmtree(string $d): void
{
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $p) {
        if (in_array(basename($p), ['.', '..'], true)) continue;
        is_dir($p) ? rmtree($p) : @unlink($p);
    }
    @rmdir($d);
}

/** A world from the tracked fixture, the way the other web suites build one. */
function make_world(string $slug): void
{
    $t = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
    $d = xeric_web_worlds_dir() . '/' . $slug;
    @mkdir($d, 0775, true);
    $j = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    file_put_contents($d . '/world-template.json', json_encode($t, $j));
    file_put_contents($d . '/seed.json', json_encode(xeric_forge_default_seed($t), $j));
}

/**
 * play.php, rendered in a process of its own as this visitor — the page
 * exits when it is done, which an in-process require would take the suite
 * down with.
 */
function render_play(string $tmp, string $sid, string $slug): string
{
    $script = $tmp . '/render-play.php';
    file_put_contents($script, '<?php' . "\n"
        . '$_GET = ["w" => ' . var_export($slug, true) . ']; $_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
        . 'require ' . var_export(dirname(__DIR__) . '/boot.php', true) . ';' . "\n"
        . 'xeric_session_use(' . var_export($sid, true) . ');' . "\n"
        . 'chdir(' . var_export(dirname(__DIR__), true) . ');' . "\n"
        . 'require ' . var_export(dirname(__DIR__) . '/play.php', true) . ';' . "\n");
    $env = 'XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
         . ' XERIC_LOCAL_BASE=http://127.0.0.1:1';
    return (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
}

$slug = 'voice-town';
make_world($slug);
make_world('other-town');

$A = bin2hex(random_bytes(16));
xeric_session_use($A);
xeric_session_claim($slug, $A);
xeric_session_claim('other-town', $A);
$w  = xeric_play_open($slug);
$T  = $w['template'];
$db = $w['db'];
$clips = dirname((string)$w['db_path']) . '/voice';

// A thread with Dot: the player's line, her answer, a narrator's line, and a
// photo caption — every kind of row the speaker has to tell apart.
$conv   = xeric_conversation_for($db, 'dot');
$mine   = xeric_message_append($db, $conv, 'user', null, 'Morning, Dot.');
$hers   = xeric_message_append($db, $conv, 'character', 'dot', 'Coffee is on. Sit anywhere.');
$narr   = xeric_message_append($db, $conv, 'narrator', null, 'The bell over the door rang.');
$bare   = xeric_message_append($db, $conv, 'character', 'dot', '*wipes the counter*');

$o  = xeric_play_open('other-town');
$oc = xeric_conversation_for($o['db'], 'theo');
xeric_message_append($o['db'], $oc, 'character', 'theo', 'filler one');
xeric_message_append($o['db'], $oc, 'character', 'theo', 'filler two');
xeric_message_append($o['db'], $oc, 'character', 'theo', 'filler three');
xeric_message_append($o['db'], $oc, 'character', 'theo', 'filler four');
$theirs = xeric_message_append($o['db'], $oc, 'character', 'theo', 'Only in the other town.');

echo "# which lines have a voice\n";

ok('a character line in this world is found, with its own handle',
    (xeric_play_spoken($db, $hers)['handle'] ?? '') === 'dot');
ok('the player\'s own line has no voice here', xeric_play_spoken($db, $mine) === null);
ok('nor does the narrator\'s', xeric_play_spoken($db, $narr) === null);
ok('an id this world never wrote is refused', xeric_play_spoken($db, 999999) === null && xeric_play_spoken($db, 0) === null);
ok('and a line from another world is not this world\'s to speak',
    $theirs > $bare && xeric_play_spoken($db, $theirs) === null && xeric_play_spoken($o['db'], $theirs) !== null);

echo "\n# no machine\n";

ok('with no voice machine set the page offers no speaker', xeric_play_voice_on() === false);
ok('and a line asked for anyway has nothing to come back with',
    xeric_play_voice_clip($T, $db, $clips, xeric_play_spoken($db, $hers), xeric_voice_endpoint(), null) === null);
$h = render_play($tmp, $A, $slug);
ok('play.php renders, and says no voice', str_contains($h, 'var VOICE = false;'), substr(strip_tags($h), 0, 300));
ok('the speaker is built only behind that flag, and never on the player\'s bubble',
    str_contains($h, "if (VOICE && who === 'them' && mid") && str_contains($h, 'aria-label="hear '));

echo "\n# a machine in the session\n";

xeric_web_session_edit(function (array &$s): void {
    $s['voice'] = ['base' => 'http://127.0.0.1:9', 'voices' => ['af_bella', 'am_adam']];
}, $A);
ok('a voice machine in the session turns the speaker on, without asking the machine', xeric_play_voice_on() === true);
$h = render_play($tmp, $A, $slug);
ok('play.php says so', str_contains($h, 'var VOICE = true;'));
ok('the session machine is the engine\'s machine', (xeric_voice_endpoint()['base'] ?? '') === 'http://127.0.0.1:9'
    && !array_key_exists('voices', xeric_voice_endpoint()));
ok('and the key is the environment\'s, never the session\'s', (string)(xeric_voice_endpoint()['key'] ?? 'x') === '');
xeric_web_session_edit(function (array &$s): void { $s['voice'] = ['off' => true]; }, $A);
putenv('XERIC_VOICE_BASE=http://127.0.0.1:10');
ok('off is off, even with a machine in the environment', xeric_voice_endpoint() === null && !xeric_play_voice_on());
putenv('XERIC_VOICE_BASE');
ok('the thread\'s rows carry their ids for the speaker',
    in_array($hers, array_column(xeric_play_thread($T, $db, 'dot')['messages'], 'id'), true));

echo "\n# the stub machine: rendered once, then the cache\n";

$mp3   = 'ID3' . "\x04\x00\x00\x00\x00\x00\x00" . str_repeat("\x00", 40);
$calls = 0;
$said  = [];
$stub  = ['stub' => function (string $what, array $req) use (&$calls, &$said, $mp3) {
    if ($what === 'voices') return ['voices' => ['af_bella', 'am_adam']];
    $calls++;
    $said[] = $req;
    return ['bytes' => $mp3, 'mime' => 'audio/mpeg'];
}];
xeric_voice_source(fn() => $stub);
$metered = [];
xeric_voice_meter(function (array $u, string $where = '') use (&$metered): void { $metered[] = $u; });

$spends = 0;
$spend  = function () use (&$spends): void { $spends++; };
$msg = xeric_play_spoken($db, $hers);
$c1  = xeric_play_voice_clip($T, $db, $clips, $msg, xeric_voice_endpoint(), ['af_bella', 'am_adam'], $spend);
ok('a character line yields audio bytes, under the right type',
    $c1 !== null && $c1['mime'] === 'audio/mpeg' && $c1['bytes'] === $mp3 && $c1['cached'] === false);
ok('the machine was asked for exactly her words, in a voice from its own list',
    $calls === 1 && ($said[0]['input'] ?? '') === 'Coffee is on. Sit anywhere.'
    && in_array($said[0]['voice'] ?? '', ['af_bella', 'am_adam'], true));
ok('the render spent the rate limit once, and reached the meter once',
    $spends === 1 && count($metered) === 1 && (int)$metered[0]['clips'] === 1);
$c2 = xeric_play_voice_clip($T, $db, $clips, $msg, xeric_voice_endpoint(), ['af_bella', 'am_adam'], $spend);
ok('the second request is a cache hit: the machine was called once', $c2 !== null && $c2['cached'] === true && $calls === 1);
ok('and a cache hit costs nothing — no rate limit, no meter', $spends === 1 && count($metered) === 1);
ok('a line that is only a stage direction has nothing to say',
    xeric_play_voice_clip($T, $db, $clips, xeric_play_spoken($db, $bare), xeric_voice_endpoint(), [], $spend) === null
    && $calls === 1);

echo "\n# what is served is what it says it is\n";

$row = xeric_voice_of($db, $hers);
file_put_contents($clips . '/' . $row['file'], 'OggS' . str_repeat("\x00", 40));
ok('bytes whose magic disagrees with the stored type are a 404',
    xeric_play_voice_clip($T, $db, $clips, $msg, xeric_voice_endpoint(), ['af_bella', 'am_adam'], $spend) === null);
file_put_contents($clips . '/' . $row['file'], "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 40));
ok('a picture under a clip is never served', xeric_play_voice_clip($T, $db, $clips, $msg, xeric_voice_endpoint(),
    ['af_bella', 'am_adam'], $spend) === null);
file_put_contents($clips . '/' . $row['file'], $mp3);

echo "\n# a machine that refuses\n";

$db->exec('DELETE FROM voice_clips');
foreach (glob($clips . '/*') ?: [] as $f) @unlink($f);
xeric_voice_source(fn() => ['stub' => function (string $what) {
    if ($what === 'voices') return [];
    throw new RuntimeException('voice: the voice machine said HTTP 400: voice not found');
}]);
$threw = '';
try {
    xeric_play_voice_clip($T, $db, $clips, $msg, xeric_voice_endpoint(), [], $spend);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
ok('a refusal is thrown in the machine\'s own words, for speak.php to send as a 503', str_contains($threw, 'voice not found'));
$sp = (string)file_get_contents(dirname(__DIR__) . '/speak.php');
ok('speak.php answers a refusal with 503 and a plain message', str_contains($sp, "xeric_web_json(['error' => ") && str_contains($sp, '], 503);'));
ok('speak.php takes the speaker from the row, never the query', !str_contains($sp, "\$_GET['h']") && str_contains($sp, 'xeric_play_spoken('));
ok('and spends its rate limit only through the render hook',
    preg_match("/function \(\) use \(\\\$w, \\\$sid\): void \{.*?xeric_limit_guard\(xeric_limit_check\('reroll'/s", $sp) === 1
    && substr_count($sp, 'xeric_limit_check(') === 1);
ok('the speaker refuses once and stands down', str_contains((string)file_get_contents(dirname(__DIR__) . '/play.php'),
    'btn.disabled = true;'));

echo "\n# the door: what is said aloud is anybody's, a new line is the owner's\n";

// A guest's press, modelled as speak.php's hook answering for itself: the
// door refuses and the request ends there. Here it throws, so the suite can
// watch what happened before it.
$refused = 0;
$guest = function () use (&$refused): void { $refused++; throw new RuntimeException('guest'); };
xeric_voice_source(fn() => $stub);
$calls = 0;
$db->exec('DELETE FROM voice_clips');
foreach (glob($clips . '/*') ?: [] as $f) @unlink($f);
$threw = '';
try { xeric_play_voice_clip($T, $db, $clips, $msg, xeric_voice_endpoint(), ['af_bella', 'am_adam'], $guest); }
catch (Throwable $e) { $threw = $e->getMessage(); }
ok('a line never voiced is a miss, and a guest is stopped at the door before the machine is asked',
    $threw === 'guest' && $refused === 1 && $calls === 0);
$owner = xeric_play_voice_clip($T, $db, $clips, $msg, xeric_voice_endpoint(), ['af_bella', 'am_adam'], $spend);
ok('the owner\'s press renders it', $owner !== null && $owner['cached'] === false && $calls === 1);
$heard = xeric_play_voice_clip($T, $db, $clips, $msg, xeric_voice_endpoint(), ['af_bella', 'am_adam'], $guest);
ok('and then the guest hears it: a hit never reaches the door', $heard !== null && $heard['cached'] === true
    && $refused === 1 && $calls === 1);
ok('speak.php\'s door is the owner-only one scene.php\'s look keeps, inside the miss hook',
    preg_match("/function \(\) use \(\\\$w, \\\$sid\): void \{\s*xeric_play_owner_only\(\\\$w, /", $sp) === 1);

echo "\n# the reply knows its own row\n";

$turnDb = xeric_play_open($slug)['db'];
$turn = xeric_chat_turn($T, $turnDb, 'dot', 'Is the pie fresh?', xeric_clock_now($turnDb, $T),
    ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o) => 'Baked at five.'], ['remind' => false]);
$row = $turnDb->query('SELECT id, role, content FROM messages ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ok('xeric_chat_turn hands back the id of the reply it wrote',
    (int)($turn['message_id'] ?? 0) === (int)$row['id'] && $row['role'] === 'character');
ok('and that id is a line the speaker can ask after', (xeric_play_spoken($turnDb, (int)$turn['message_id'])['handle'] ?? '') === 'dot');
ok('say.php hands the turn\'s own id to the page, rather than finding the row again',
    str_contains((string)file_get_contents(dirname(__DIR__) . '/say.php'), "'mid'       => (int)(\$out['message_id'] ?? 0),")
    && !str_contains((string)file_get_contents(dirname(__DIR__) . '/say.php'), 'AND content = ?'));

echo "\n# the meter\n";

ok('lines spoken are on the meter\'s tooltip', str_contains((string)file_get_contents(dirname(__DIR__) . '/ui.php'), "' lines spoken'"));

rmtree($tmp);

echo "\n" . ($FAILED === 0 ? "all voice web tests passed\n" : "$FAILED voice web test(s) FAILED\n");
exit($FAILED === 0 ? 0 : 1);
