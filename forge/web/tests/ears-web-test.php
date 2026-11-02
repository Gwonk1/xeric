<?php
/**
 * Xeric — push to talk, the web half. `php forge/web/tests/ears-web-test.php`,
 * exit 0 on pass.
 *
 * engine/tests/ears-test.php proves the wire, the sniff and the silence
 * filter; this proves what the browser can ask hear.php for and what it is
 * handed. NO NETWORK: the machine is the engine's stub seam, registered
 * through the same resolver the session's machine goes through, and it
 * keeps what it was asked.
 *
 * What is being defended, in the order it would hurt:
 *
 *   1. A GUEST CANNOT SPEND THE OWNER'S EARS.
 *   2. THE TRANSCRIPT IS TEXT FOR THE BOX, NEVER A SAY — hear.php posts
 *      nothing, and the page puts the words where the player reads them.
 *   3. WHAT IS SENT IS WHAT IT SAYS IT IS — magic bytes against the type,
 *      before the machine is asked.
 *   4. THE ROOM'S NAMES RIDE AS THE PROMPT — and only names.
 *   5. THE BUTTON IS OFFERED ONLY WITH A MACHINE, never probed to decide it,
 *      and only play.php may ask for the microphone.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-ears-web-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called
putenv('XERIC_CAPS=1');                             // the rate buckets are counting in this suite
foreach (['XERIC_EARS_BASE', 'XERIC_EARS_KEY', 'XERIC_EARS_MODEL', 'XERIC_EARS_LANGUAGE'] as $k) putenv($k);

require_once __DIR__ . '/../play-lib.php';
require_once __DIR__ . '/../hear.php';              // the two functions; the request block does not run

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

function make_world(string $slug): void
{
    $t = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
    $d = xeric_web_worlds_dir() . '/' . $slug;
    @mkdir($d, 0775, true);
    $j = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    file_put_contents($d . '/world-template.json', json_encode($t, $j));
    file_put_contents($d . '/seed.json', json_encode(xeric_forge_default_seed($t), $j));
}

/** play.php, rendered in a process of its own as this visitor (voice-web-test's way). */
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

$slug = 'ears-town';
make_world($slug);

$A = bin2hex(random_bytes(16));
xeric_session_use($A);
xeric_session_claim($slug, $A);
$w  = xeric_play_open($slug);
$T  = $w['template'];
$db = $w['db'];

// Stand the player somewhere with company, so the room has names to send.
// The clock is walked forward an hour at a time until somebody's week puts
// them somewhere (the fixture's town is empty in the small hours).
$place = '';
for ($i = 0; $i < 168 && $place === ''; $i++) {
    if ($i === 1) xeric_clock_resume($db);
    if ($i > 0) xeric_clock_advance($db, 3600, $T);
    $now      = xeric_clock_now($db, $T);
    $presence = xeric_world_who_is_where($T, $now, xeric_dead_handles($db));
    foreach ($presence as $row) {
        $k = (string)($row['where'] ?? '');
        if ($k !== '' && xeric_world_place($T, $k) !== null && !xeric_travel_unstaged($T, $k)) { $place = $k; break; }
    }
}
xeric_player_move($db, $place, (int)$now['epoch']);
$here = array_map(fn($h) => xeric_world_name($T, (string)$h), xeric_world_who_is_at($presence, $place));
$dot  = xeric_world_name($T, 'dot');

$WEBM = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 200);
$OGG  = 'OggS' . str_repeat("\x00", 200);

echo "# no machine\n";

ok('with no ears machine set the engine has none', xeric_ears_endpoint() === null);
[$st, $body] = xeric_hear($w, $A, $WEBM, 'audio/webm', 'dot');
ok('hear.php is a 404 with no machine, and says so', $st === 404 && str_contains((string)($body['error'] ?? ''), 'no ears machine'),
    json_encode([$st, $body]));
$h0 = render_play($tmp, $A, $slug);
ok('play.php renders', str_contains($h0, 'id="composer"'), substr(strip_tags($h0), 0, 300));
ok('and offers no hold-to-talk button', !str_contains($h0, 'id="hear"') && !str_contains($h0, 'aria-label="hold to talk"'));

echo "\n# a machine in the session\n";

xeric_web_session_edit(function (array &$s): void { $s['ears'] = ['base' => 'http://127.0.0.1:9']; }, $A);
ok('an ears machine in the session is the engine\'s machine', (xeric_ears_endpoint()['base'] ?? '') === 'http://127.0.0.1:9');
ok('and the key is the environment\'s, never the session\'s', (string)(xeric_ears_endpoint()['key'] ?? 'x') === '');
ok('the model is the default', (xeric_ears_endpoint()['model'] ?? '') === 'whisper-1');
$h1 = render_play($tmp, $A, $slug);
ok('play.php now carries the hold-to-talk button, without asking the machine (port 9 never answers)',
    str_contains($h1, 'id="hear"') && str_contains($h1, 'aria-label="hold to talk"'));
xeric_web_session_edit(function (array &$s): void { $s['ears'] = ['off' => true]; }, $A);
putenv('XERIC_EARS_BASE=http://127.0.0.1:10');
ok('off is off, even with a machine in the environment', xeric_ears_endpoint() === null);
ok('and the button goes with it', !str_contains(render_play($tmp, $A, $slug), 'id="hear"'));
putenv('XERIC_EARS_BASE');

echo "\n# the inline script\n";

$js = '';
if (preg_match_all('#<script nonce="[^"]+">(.*?)</script>#s', $h1, $m)) {
    foreach ($m[1] as $block) if (str_contains($block, "getElementById('hear')")) $js = $block;
}
ok('the hold-to-talk code is in a nonce\'d inline script', $js !== '');
$jsf = $tmp . '/play-inline.js';
file_put_contents($jsf, $js);
$out = []; $rc = 1;
exec('node --check ' . escapeshellarg($jsf) . ' 2>&1', $out, $rc);
ok('and node --check accepts it', $rc === 0, implode("\n", $out));
ok('the microphone is asked for on a press, never on load',
    preg_match('/function start\(\) \{.*?getUserMedia/s', $js) === 1 && substr_count($js, 'getUserMedia(') === 1);
ok('it stops at thirty seconds, and the keyboard can hold it',
    str_contains($js, 'MAX_MS = 30000') && str_contains($js, "e.key !== ' '") && str_contains($js, "'keyup'"));
ok('the words go into the composer, and nothing posts to say.php from here',
    preg_match("/fetch\('hear\.php'.*?box\.value = .*?box\.focus\(\)/s", $js) === 1
    && preg_match("/\(function \(\) \{\s*var hb = document\.getElementById\('hear'\);(?:(?!fetch\('say\.php').)*\}\)\(\);/s", $js) === 1);
ok('a refusal is shown once and the button stands down', str_contains($js, 'hb.disabled = true;'));

echo "\n# the stub machine, through the resolver\n";

$CALLS = [];
$stub  = ['stub' => function (string $what, array $req) use (&$CALLS): array {
    $CALLS[] = $req;
    return ['text' => ' Morning, Dot. Thank you. ', 'usage' => ['seconds' => 2]];
}];
xeric_ears_source(fn() => $stub);
$metered = [];
xeric_ears_meter(function (array $u, string $where = '') use (&$metered): void { $metered[] = [$u, $where]; });

[$st, $body] = xeric_hear($w, $A, $WEBM, 'audio/webm;codecs=opus', 'dot');
ok('a recording comes back as text for the box', $st === 200 && ($body['ok'] ?? false) === true
    && ($body['text'] ?? '') === 'Morning, Dot. Thank you.', json_encode([$st, $body]));
ok('the machine was asked once, with the bytes', count($CALLS) === 1 && $CALLS[0]['bytes'] === $WEBM);
$prompt = (string)($CALLS[0]['prompt'] ?? '');
$want   = array_values(array_unique(array_merge($here, [$dot])));
ok('the names present where the player stands, and the thread\'s person, ride as the prompt',
    $prompt === implode(', ', $want), json_encode([$prompt, $want, $place]));
ok('and the room had company to name, so the check above is not vacuous', $place !== '' && count($here) >= 1,
    json_encode([$place, $here]));
ok('the hearing reached the meter, as a hearing', count($metered) === 1 && (int)$metered[0][0]['hearings'] === 1
    && $metered[0][1] === 'stub');
$n = (int)$db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
ok('and nothing was said: hear.php writes no message', $n === 0);
[$st, $body] = xeric_hear($w, $A, $WEBM, 'audio/webm', 'nobody-at-all');
ok('a handle nobody answers to adds no name, and the room still does',
    $st === 200 && (string)(end($CALLS)['prompt'] ?? '') === implode(', ', array_values(array_unique($here))));

echo "\n# what is sent is what it says it is\n";

$before = count($CALLS);
[$st, $body] = xeric_hear($w, $A, $OGG, 'audio/webm', 'dot');
ok('bytes whose magic disagrees with the type are a 415, and the machine is never asked',
    $st === 415 && count($CALLS) === $before, json_encode([$st, $body]));
[$st] = xeric_hear($w, $A, "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 40), 'image/png', 'dot');
ok('a picture is not a recording', $st === 415 && count($CALLS) === $before);
[$st] = xeric_hear($w, $A, '', 'audio/webm', 'dot');
ok('nothing is a 400', $st === 400);
[$st] = xeric_hear($w, $A, $WEBM . str_repeat("\x00", XERIC_EARS_MAX_BYTES), 'audio/webm', 'dot');
ok('more than 25 MB is a 413', $st === 413 && count($CALLS) === $before);

echo "\n# a machine that refuses\n";

xeric_ears_source(fn() => ['stub' => function (): array {
    throw new RuntimeException('ears: the ears machine said HTTP 400: Audio file is too short');
}]);
[$st, $body] = xeric_hear($w, $A, $WEBM, 'audio/webm', 'dot');
ok('a refusal is a 503 in the machine\'s own words', $st === 503
    && ($body['error'] ?? '') === 'the ears machine said HTTP 400: Audio file is too short', json_encode([$st, $body]));
xeric_ears_source(fn() => $stub);

echo "\n# the door\n";

$guest = ['mine' => false, 'dir' => (string)$w['dir'], 'db_path' => rtrim((string)$w['dir'], '/') . '/world.db'] + $w;
ok('a guest in the owner\'s world is a guest', xeric_play_is_guest($guest));
$before = count($CALLS);
[$st, $body] = xeric_hear($guest, $A, $WEBM, 'audio/webm', 'dot');
ok('and may not spend the owner\'s ears: 403, before the machine is asked',
    $st === 403 && ($body['kind'] ?? '') === 'guest' && count($CALLS) === $before, json_encode([$st, $body]));
$hp = (string)file_get_contents(dirname(__DIR__) . '/hear.php');
ok('hear.php reads its POST through the input helper (the cross-site guard)', str_contains($hp, '$in   = xeric_web_input();'));
ok('hear.php never posts the say itself', !str_contains($hp, 'xeric_chat_turn') && !str_contains($hp, 'xeric_message_append'));
ok('hear.php stores nothing it was sent', !preg_match('/file_put_contents|move_uploaded_file|->exec\(|INSERT /', $hp));

echo "\n# the rate bucket\n";

xeric_limit_give_back();                            // the refused hearings above held seats until now
$left = XERIC_LIMIT_HEARS_PER_HOUR - xeric_limit_hits('hear-' . $A, XERIC_LIMIT_HOUR, time())['count'];
ok('every hearing that listened spent one seat of the hear bucket', $left === XERIC_LIMIT_HEARS_PER_HOUR - 2, (string)$left);
$st = 200;
for ($i = 0; $i < $left; $i++) [$st] = xeric_hear($w, $A, $WEBM, 'audio/webm', 'dot');
ok('the bucket\'s whole hour is usable', $st === 200);
$before = count($CALLS);
[$st, $body] = xeric_hear($w, $A, $WEBM, 'audio/webm', 'dot');
ok('and the next hearing is a 429 with a sentence and a time, before the machine is asked',
    $st === 429 && ($body['retry_after'] ?? 0) > 0 && str_contains((string)($body['error'] ?? ''), 'aloud')
    && count($CALLS) === $before, json_encode([$st, $body]));
ok('a hearing is not a message: the message bucket is untouched',
    xeric_limit_hits('msg-' . $A, XERIC_LIMIT_HOUR, time())['count'] === 0);

echo "\n# the chrome\n";

$ui = (string)file_get_contents(dirname(__DIR__) . '/ui.php');
ok('lines heard are on the meter\'s tooltip', str_contains($ui, "' lines heard'"));
ok('only a page that says so may ask for the microphone; every other page says no',
    str_contains($ui, "header('Permissions-Policy: microphone=' . (!empty(\$opts['microphone']) ? '(self)' : '()')"));
$pp = (string)file_get_contents(dirname(__DIR__) . '/play.php');
ok('and the room is the page that says so',
    str_contains($pp, "xeric_web_head('Xeric: ' . (string)\$T['meta']['name'], ['microphone' => true]);")
    && substr_count($pp, "'microphone' => true") === 1);
$mp = (string)file_get_contents(dirname(__DIR__) . '/model.php');
ok('the machines screen has an ears card, its lamp, and names the key it bills',
    str_contains($mp, 'Ears machine') && str_contains($mp, "model.php?a=earsup") && str_contains($mp, 'XERIC_EARS_KEY')
    && str_contains($mp, "\$act === 'earsoff'"));

rmtree($tmp);

echo "\n" . ($FAILED === 0 ? "all ears web tests passed\n" : "$FAILED ears web test(s) FAILED\n");
exit($FAILED === 0 ? 0 : 1);
