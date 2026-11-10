<?php
/**
 * Xeric — the client API's read half and the pulse. `php forge/web/tests/api-test.php`,
 * exit 0 on pass.
 *
 * Two halves. The library (tokens, the envelope, the seams in boot.php) is
 * called in-process; the routes are asked over HTTP, of a real `php -S` on a
 * free port serving forge/web through router.php — because what is being
 * defended is mostly headers (Xeric-Api, ETag, 304, the status of a refusal)
 * and PATH_INFO, and none of those exist under the CLI SAPI. NO NETWORK beyond
 * 127.0.0.1, NO MACHINE: rows are written by hand as the reapers would leave
 * them.
 *
 * What is being defended, in the order it would hurt:
 *
 *   1. A BAD TOKEN IS A 401, NEVER SOLO, and a cookie or solo identity is not
 *      honoured from somebody else's page on any method.
 *   2. THE CONTRACT HOLDS: every answer is {api, ok, …} with an Xeric-Api
 *      header, and no key a client has been promised disappears within v1
 *      (the snapshot below).
 *   3. THE PULSE IS CHEAP AND HONEST: a 304 while nothing changed, a new ETag
 *      the moment something did.
 *   4. A KIDS' HEADSET IS ONE FLAG: a token with adult=false clamps the
 *      rating whatever the session affirmed.
 *
 * THE CONTRACT SNAPSHOT. forge/web/tests/api-contract.json holds the sorted
 * key paths of each route's body. A key in the snapshot that a route no
 * longer answers with is a failure (removal is v2). A key a route answers
 * with that the snapshot does not hold is printed as a note — additive is
 * legal within v1 — and the snapshot is regenerated with
 *
 *     XERIC_API_CONTRACT=write php forge/web/tests/api-test.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-api-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called
putenv('XERIC_SOLO=0');
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_SCENE_KIND', 'XERIC_SCENE_AUTH', 'XERIC_IMAGE_BASE',
          'XERIC_IMAGE_KEY', 'XERIC_VOICE_BASE', 'XERIC_VOICE_KEY', 'XERIC_EARS_BASE', 'XERIC_EARS_KEY'] as $k) putenv($k);

require_once __DIR__ . '/../api-lib.php';

$FAILED = 0;
$WEB = dirname(__DIR__);

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

/** Any *_html key, at any depth. */
function has_html_key(array $a): bool
{
    foreach ($a as $k => $v) {
        if (is_string($k) && str_ends_with($k, '_html')) return true;
        if (is_array($v) && has_html_key($v)) return true;
    }
    return false;
}

/**
 * A body's key paths, sorted: `clock.epoch`, `places[].who[].handle`. Three
 * levels of object deep — enough to hold a client's promises, shallow enough
 * that the engine's own objects (a brief, a machine's meta) are not frozen.
 */
function key_paths(array $a, string $at = '', int $depth = 0): array
{
    $out = [];
    if (array_is_list($a)) {
        foreach ($a as $v) if (is_array($v) && !array_is_list($v)) $out = array_merge($out, key_paths($v, $at . '[]', $depth));
        return $out;
    }
    foreach ($a as $k => $v) {
        // A job record's own fields are whatever the worker wrote; its id,
        // kind and time are the contract.
        if ($at === 'records[]' && !in_array($k, ['id', 'kind', 'at'], true)) continue;
        $p = $at === '' ? (string)$k : $at . '.' . $k;
        $out[] = $p;
        if (is_array($v) && $depth < 2 && $k !== 'meta') $out = array_merge($out, key_paths($v, $p, $depth + 1));
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

// -- a world ----------------------------------------------------------------

$slug = 'api-town';
$fixT = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$fixT['meta']['rating'] = 'mature';             // above the floor, so a clamp shows
$wdir = xeric_web_worlds_dir() . '/' . $slug;
@mkdir($wdir, 0775, true);
$J = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents($wdir . '/world-template.json', json_encode($fixT, $J));
file_put_contents($wdir . '/seed.json', json_encode(xeric_forge_default_seed($fixT), $J));

$A = bin2hex(random_bytes(16));
xeric_session_use($A);
xeric_session_claim($slug, $A);
ok('the owner affirms', xeric_session_affirm(true, $A) === true);
$w  = xeric_play_open($slug, null, true);
$T  = $w['template'];
$db = $w['db'];

// A FIXED WORLD MOMENT, so who is where (and so the snapshot) does not
// depend on what time the suite runs: a Tuesday at ten, the clock stopped.
if (!xeric_clock_is_paused($db)) xeric_clock_pause($db);
$tz = (string)(xeric_clock_now($db, $T)['tz'] ?? 'UTC');
$target = (new DateTimeImmutable('2026-11-03 10:00:00', new DateTimeZone($tz ?: 'UTC')))->getTimestamp();
xeric_clock_offset_set($db, $target - xeric_clock_paused_at($db));
ok('the world reads a fixed Tuesday morning', (string)xeric_clock_now($db, $T)['hhmm'] === '10:00',
    (string)xeric_clock_now($db, $T)['hhmm']);
xeric_player_move($db, 'bluebird', xeric_clock_epoch($db));

$conv = xeric_conversation_for($db, 'dot');
$m1 = xeric_message_append($db, $conv, 'user', null, 'Morning, Dot.', $target - 120);
$m2 = xeric_message_append($db, $conv, 'character', 'dot', 'Coffee is on. Sit anywhere.', $target - 60);
$db->prepare('UPDATE conversations SET unread = 1 WHERE id = ?')->execute([$conv]);

// A still of the room, and a line already read aloud.
$brief = xeric_scene_brief($T, $db);
$fp = (string)$brief['fingerprint'];
$scenes = dirname((string)$w['db_path']) . '/scenes';
@mkdir($scenes, 0775, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($scenes . '/scene-bluebird.png', $png);
$db->prepare("INSERT INTO scene_jobs (key, fingerprint, status, tries, kind, file, url, mime, prompt, meta,
              created_at, done_at) VALUES ('bluebird', ?, 'done', 1, 'image', 'scene-bluebird.png', NULL,
              'image/png', '', '{}', ?, ?)")->execute([$fp, time(), time()]);
$clips = dirname((string)$w['db_path']) . '/voice';
@mkdir($clips, 0775, true);
xeric_voice_schema($db);
file_put_contents($clips . '/clip-m2.mp3', 'ID3' . str_repeat("\x00", 40));
$db->prepare("INSERT INTO voice_clips (handle, message_id, fingerprint, file, mime, created_at)
              VALUES ('dot', ?, ?, 'clip-m2.mp3', 'audio/mpeg', ?)")->execute([$m2, str_repeat('c', 40), time()]);

// A job, as a worker writes one.
$job = xeric_web_job_new();
xeric_web_job_append($job, ['k' => 'note', 'text' => 'reading the room']);
xeric_web_job_append($job, ['k' => 'note', 'message' => 'drawing it']);
xeric_web_job_append($job, ['k' => 'done', 'ok' => 1]);

echo "# tokens\n";

$tokAdult = xeric_api_token_mint($A, 'owner', 'laptop', true);
$tokKid   = xeric_api_token_mint($A, 'owner', "kids' headset", false);
ok('a token is xd1_ and 32 random bytes', preg_match('/^xd1_[a-f0-9]{64}$/', $tokAdult) === 1);
$raw = (string)file_get_contents($tmp . '/api-tokens.db');
ok('and only its hash is kept', !str_contains($raw, substr($tokAdult, 4)) && str_contains($raw, hash('sha256', $tokAdult)));
$r = xeric_api_token_resolve($tokKid);
ok('a good token resolves to its session, role and bit', $r !== null && $r['sid'] === $A && $r['role'] === 'owner'
    && $r['adult'] === false);
ok('a malformed one does not', xeric_api_token_resolve('xd1_nope') === null
    && xeric_api_token_resolve('xd1_' . str_repeat('0', 64)) === null);
$tokGone = xeric_api_token_mint(str_repeat('e', 32), 'guest', 'swept', true);
ok('a token whose session is gone is gone with it', xeric_api_token_resolve($tokGone) === null);
$tokRevoked = xeric_api_token_mint($A, 'guest', 'lost phone', true);
xeric_api_tokens_db()->prepare('UPDATE api_tokens SET revoked_at = ? WHERE hash = ?')
    ->execute([time(), hash('sha256', $tokRevoked)]);
ok('a revoked one is refused', xeric_api_token_resolve($tokRevoked) === null);
$threw = false;
try { xeric_api_token_mint($A, 'admin', 'x', true); } catch (InvalidArgumentException $e) { $threw = true; }
ok('there are two roles and no third', $threw);

echo "\n# the envelope\n";

$e = xeric_api_envelope(['ok' => 1, 'mid' => 7, 'w' => 'x', 'cast_html' => '<b>', 'nest' => ['a_html' => 1, 'b' => 2]]);
ok('ok:1 is true, mid is id, w is world, *_html is gone at any depth',
    $e['api'] === XERIC_API_VERSION && $e['ok'] === true && $e['id'] === 7 && $e['world'] === 'x'
    && !isset($e['mid'], $e['w'], $e['cast_html']) && $e['nest'] === ['b' => 2]);
$e = xeric_api_envelope(['why' => 'nothing to take back'], 409);
ok('why is error, and a refusal with no kind gets one by its status',
    $e['ok'] === false && $e['error'] === 'nothing to take back' && $e['kind'] === 'conflict' && !isset($e['why']));
$e = xeric_api_envelope(['error' => 'You are a guest in this xeric', 'kind' => 'guest'], 403);
ok('a refusal keeps its own kind', $e['ok'] === false && $e['kind'] === 'guest');
$e = xeric_api_envelope(['message' => 'it stopped'], 500, 'failed');
ok('a failed frame says text, error and ok:false', $e['ok'] === false && $e['text'] === 'it stopped'
    && $e['kind'] === 'job' && !isset($e['message']));

echo "\n# the seams in boot.php\n";

// THE SESSION RECORD IS READ UNDER A SHARED LOCK (the api-test flake: a read
// between an edit's truncate and its write read "no machine"), and an edit's
// own callback reading the record it holds must not wait on itself.
$lockSid = bin2hex(random_bytes(16));
xeric_web_session_edit(function (array &$s): void { $s['model'] = ['kind' => 'local']; }, $lockSid);
$inside = xeric_web_session_edit(fn(array &$s) => xeric_web_session_read($lockSid)['model']['kind'] ?? '', $lockSid);
ok('a session read takes the shared lock, and an edit reading its own record does not deadlock',
    $inside === 'local' && !xeric_web_session_held(xeric_web_session_path($lockSid))
    && str_contains((string)file_get_contents(dirname(__DIR__) . '/boot.php'), '@flock($fh, LOCK_SH)'));
@unlink(xeric_web_session_path($lockSid));

ok('no shaper is registered unless api.php registers one', xeric_web_json_shaper() === null);
xeric_web_json_shaper('xeric_api_envelope');
ok('registering one is read back', xeric_web_json_shaper() === 'xeric_api_envelope');
xeric_web_json_shaper(null);
ok('and an explicit null clears it', xeric_web_json_shaper() === null);

$_SERVER['REQUEST_METHOD'] = 'POST';
xeric_web_input_set(['to' => 'diner']);
ok('a body set by the router is what xeric_web_input() reads', xeric_web_input() === ['to' => 'diner']);
xeric_web_input_set(null);
ok('and cleared, input is read as before', xeric_web_input() === []);
$_SERVER['REQUEST_METHOD'] = 'GET';

/** Run a few lines of PHP after boot in a child, and hand back what it printed. */
$child = function (string $code) use ($tmp, $WEB): string {
    $f = $tmp . '/child-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($f, "<?php\nrequire " . var_export($WEB . '/api-lib.php', true) . ";\n" . $code . "\n");
    $env = 'XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
         . ' XERIC_LOCAL_BASE=http://127.0.0.1:1';
    return (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1');
};
$plain = json_decode($child("xeric_limit_guard(xeric_limit_no('slow down', 5));"), true);
ok('without a shaper a refusal raised deep inside is exactly as it was',
    is_array($plain) && !isset($plain['api']) && ($plain['kind'] ?? '') === 'limit', json_encode($plain));
$shaped = json_decode($child("xeric_web_json_shaper('xeric_api_envelope');\n"
    . "xeric_limit_guard(xeric_limit_no('slow down', 5));"), true);
ok('with api.php\'s shaper the same refusal comes out enveloped',
    is_array($shaped) && ($shaped['api'] ?? '') === XERIC_API_VERSION && $shaped['ok'] === false
    && $shaped['kind'] === 'limit' && $shaped['error'] === 'slow down', json_encode($shaped));
$guest = json_decode($child("xeric_web_json_shaper('xeric_api_envelope');\n"
    . "xeric_play_owner_only(['mine' => false, 'dir' => '/w', 'db_path' => '/w/world.db'], 'walking');"), true);
ok('and so does an owner refusal', is_array($guest) && $guest['ok'] === false && $guest['kind'] === 'guest'
    && $guest['api'] === XERIC_API_VERSION, json_encode($guest));

// -- the servers ----------------------------------------------------------------
//
// Two app servers (one cookie, one solo) and a THIRD, the machine: a stub that
// speaks the OpenAI wire for a model, a voice and a pair of ears, on loopback,
// logging every call — because the verbs are the real scripts, and the real
// scripts (and the workers they detach) reach their machines over HTTP. It is
// written into the scratch dir and served from there, never from the tree.

$machine = $tmp . '/machine';
@mkdir($machine, 0775, true);
file_put_contents($machine . '/stub.php', <<<'PHP'
<?php
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$in   = json_decode((string)file_get_contents('php://input'), true);
file_put_contents(__DIR__ . '/calls.log', ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path . "\n", FILE_APPEND | LOCK_EX);
if ($path === '/v1/audio/speech') {
    header('Content-Type: audio/mpeg');
    echo 'ID3' . str_repeat("\x00", 64) . 'stub-voice';
    return true;
}
header('Content-Type: application/json');
if ($path === '/v1/audio/transcriptions') { echo json_encode(['text' => 'Is the pie fresh today?']); return true; }
if ($path === '/v1/chat/completions') {
    $json = is_array($in) && isset($in['response_format']);
    $say  = $json ? '{"memories": [], "events": [], "beats": []}' : 'Coffee is on. Sit anywhere you like.';
    echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $say]]],
                      'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]);
    return true;
}
if ($path === '/v1/audio/voices' || $path === '/v1/voices') { http_response_code(404); echo '{}'; return true; }
echo json_encode(['object' => 'list', 'data' => [['id' => 'stub']]]);
return true;
PHP);

// A PORT THE KERNEL HANDS OUT, AND A SERVER THAT IS PROVABLY OURS. This
// probed a random port in 8700-9199 by connecting to it and called "nobody
// answered" free, then took any connection on it as readiness — so two suites
// (or two runs) picking the same port both "started", and one of them asked
// the other's server questions about a world it did not have. Port 0 asks the
// kernel for one nobody holds; readiness is OUR process's own log saying it
// started, and a "Failed to listen" (somebody bound it in the gap) is a fresh
// port, never a connection to whoever won.
$freePort = function (): int {
    $srv = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2);
    if ($srv === false) return 0;
    $name = (string)stream_socket_get_name($srv, false);
    fclose($srv);
    return (int)substr($name, strrpos($name, ':') + 1);
};
$listen = function (int $port, string $env, string $docroot, string $router) use ($tmp, $freePort): array {
    for ($attempt = 0; $attempt < 5; $attempt++, $port = $freePort()) {
        $log = $tmp . '/server-' . $port . '.log';
        $pid = (int)trim((string)shell_exec($env . ' exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
            . ' -t ' . escapeshellarg($docroot) . ' ' . escapeshellarg($router)
            . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!'));
        for ($i = 0; $i < 100 && $pid > 0; $i++) {
            $out = (string)@file_get_contents($log);
            if (str_contains($out, 'Failed to listen')) break;
            if (str_contains($out, '127.0.0.1:' . $port . ') started')) {
                $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.5);
                if ($s !== false) { fclose($s); return [$port, $pid]; }
            }
            if (!@posix_kill($pid, 0)) break;
            usleep(50000);
        }
        if ($pid > 0) @posix_kill($pid, 15);
    }
    return [0, 0];
};
[$mport, $mpid] = $listen($freePort(), 'PHP_CLI_SERVER_WORKERS=4', $machine, $machine . '/stub.php');
$MACHINE = 'http://127.0.0.1:' . $mport;

$serve = function (string $solo) use ($tmp, $WEB, $freePort, $listen, $MACHINE): array {
    $env = 'XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
         . ' XERIC_LOCAL_BASE=http://127.0.0.1:1 XERIC_SOLO=' . $solo . ' PHP_CLI_SERVER_WORKERS=4'
         . ' XERIC_VOICE_BASE=' . $MACHINE . ' XERIC_EARS_BASE=' . $MACHINE;
    return $listen($freePort(), $env, $WEB, $WEB . '/router.php');
};
[$port, $pid]   = $serve('0');
[$sport, $spid] = $serve('1');
register_shutdown_function(function () use ($pid, $spid, $mpid, $tmp): void {
    foreach ([$pid, $spid, abs($mpid)] as $p) if ($p > 0) @posix_kill($p, 15) || @shell_exec('kill ' . $p);
    usleep(300000);
    rmtree($tmp);
});
ok('two test servers answer, one cookie and one solo, and a stub machine beside them',
    $port > 0 && $sport > 0 && $mpid > 0);
if ($port === 0 || $sport === 0 || $mpid <= 0) { echo "\nno server\n"; exit(1); }

/** One request. [status, headers (lowercased), body, decoded]. */
function req(int $port, string $path, array $headers = [], string $method = 'GET', ?string $content = null): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 60, 'follow_location' => 0,
        'header' => implode("\r\n", $headers),
    ] + ($content !== null ? ['content' => $content] : [])]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $ctx);
    $hs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $status = 0;
    $h = [];
    foreach ($hs as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; $h = []; continue; }
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            $h[$k] = isset($h[$k]) && $k === 'set-cookie' ? $h[$k] . "\n" . trim($v) : trim($v);
        }
    }
    $body = $body === false ? '' : $body;
    $d = json_decode($body, true);
    return [$status, $h, $body, is_array($d) ? $d : null];
}

$C = ['Cookie: ' . XERIC_WEB_COOKIE . '=' . $A];
$W = '/api.php/v1/worlds/' . $slug;

echo "\n# every route, enveloped\n";

$routes = [
    'index'   => '/api.php/v1/',
    'worlds'  => '/api.php/v1/worlds',
    'world'   => $W,
    'pulse'   => $W . '/pulse',
    'map'     => $W . '/map',
    'look'    => $W . '/look',
    'thread'  => $W . '/threads/dot',
    'job'     => '/api.php/v1/jobs/' . $job,
];
$bodies = [];
foreach ($routes as $name => $path) {
    [$st, $h, $raw, $d] = req($port, $path, $C);
    $bodies[$name] = $d ?? [];
    ok("$name answers 200 with the envelope and the header", $st === 200 && ($h['xeric-api'] ?? '') === '1.0'
        && is_array($d) && ($d['api'] ?? '') === '1.0' && ($d['ok'] ?? null) === true,
        "$st " . substr($raw, 0, 300));
}

[$st, $h, $raw] = req($port, $W . '/look/bluebird/' . $fp, $C);
ok('a look\'s bytes are served as what they sniff as', $st === 200 && ($h['content-type'] ?? '') === 'image/png'
    && $raw === $png && ($h['xeric-api'] ?? '') === '1.0' && ($h['x-content-type-options'] ?? '') === 'nosniff');
[$st, $h, $raw] = req($port, $W . '/messages/' . $m2 . '/audio', $C);
ok('a line already read aloud is served', $st === 200 && ($h['content-type'] ?? '') === 'audio/mpeg'
    && str_starts_with($raw, 'ID3') && ($h['xeric-api'] ?? '') === '1.0');

echo "\n# what the routes say\n";

ok('the index is the version, the modes and the machines, as booleans',
    ($bodies['index']['version'] ?? '') === '1.0' && in_array('bearer', $bodies['index']['auth']['modes'] ?? [], true)
    && in_array('cookie', $bodies['index']['auth']['modes'] ?? [], true)
    && array_filter((array)($bodies['index']['features'] ?? []), fn($v) => !is_bool($v)) === []
    && ($bodies['index']['auth']['via'] ?? '') === 'cookie');
ok('the shelf lists the world', in_array($slug, array_column((array)($bodies['worlds']['worlds'] ?? []), 'slug'), true));
ok('the world carries no *_html key at any depth', !has_html_key($bodies['world']));
ok('and is the state: clock, cast, where, the rewind', isset($bodies['world']['clock'], $bodies['world']['cast'],
    $bodies['world']['where']) && array_key_exists('rewind', $bodies['world']));
ok('the clock carries an epoch, the offset and the server\'s wall clock',
    is_int($bodies['world']['clock']['epoch'] ?? null) && is_int($bodies['world']['clock']['offset'] ?? null)
    && is_int($bodies['world']['clock']['server'] ?? null)
    && $bodies['world']['clock']['epoch'] === $target);
ok('it names its world as `world`, never `w`', ($bodies['world']['world'] ?? '') === $slug && !isset($bodies['world']['w']));
ok('the owner, affirmed, by cookie reads the world\'s own rating', ($bodies['world']['rating'] ?? '') === 'mature');
ok('the map is xeric_travel_map', ($bodies['map']['you']['where'] ?? '') === 'bluebird'
    && is_array($bodies['map']['places'] ?? null));
$msgs = (array)($bodies['thread']['messages'] ?? []);
ok('a thread carries every message with its id and its world epoch, and keeps the display string',
    count($msgs) === 2 && $msgs[0]['id'] === $m1 && $msgs[1]['id'] === $m2
    && $msgs[0]['epoch'] === $target - 120 && $msgs[1]['epoch'] === $target - 60
    && is_string($msgs[1]['when'] ?? null), json_encode($msgs));
[, , , $d] = req($port, $W . '/threads/dot?since=' . $m1, $C);
ok('?since= answers only what came after', count((array)($d['messages'] ?? [])) === 1
    && ($d['messages'][0]['id'] ?? 0) === $m2 && ($d['last'] ?? 0) === $m2 && ($d['since'] ?? -1) === $m1);
ok('the look has the brief, and every src is a v1 link', ($bodies['look']['brief']['key'] ?? '') === 'bluebird'
    && ($bodies['look']['scene']['src'] ?? '') === '/api.php/v1/worlds/' . $slug . '/look/bluebird/' . $fp
    && ($bodies['look']['machine']['up'] ?? null) === false, json_encode($bodies['look']['scene'] ?? null));
$recs = (array)($bodies['job']['records'] ?? []);
ok('a job reads as records with ids and kinds, `message` normalised to `text`',
    ($bodies['job']['done'] ?? false) === true && count($recs) === 3 && $recs[1]['id'] === 1
    && $recs[1]['kind'] === 'note' && ($recs[1]['text'] ?? '') === 'drawing it' && !isset($recs[1]['message'])
    && $recs[2]['kind'] === 'done');
[, , , $d] = req($port, '/api.php/v1/jobs/' . $job . '?from=2', $C);
ok('?from= reads from that line', count((array)($d['records'] ?? [])) === 1 && ($d['records'][0]['kind'] ?? '') === 'done');
[$st, $h, $raw] = req($port, '/api.php/v1/jobs/' . $job, array_merge($C, ['Accept: text/event-stream']));
ok('Accept: text/event-stream streams the same job through progress.php, enveloped',
    $st === 200 && str_starts_with($h['content-type'] ?? '', 'text/event-stream') && ($h['xeric-api'] ?? '') === '1.0'
    && str_contains($raw, "event: done") && str_contains($raw, '"api":"1.0"') && str_contains($raw, '"text":"drawing it"'),
    substr($raw, -400));

echo "\n# the pulse\n";

$p = $bodies['pulse'];
ok('the pulse is small and says the cursor\'s parts', isset($p['cursor'], $p['offset'], $p['last_message'], $p['scenes'])
    && $p['where'] === 'bluebird' && $p['last_message'] === $m2 && $p['paused'] === true
    && $p['unread'] === [['handle' => 'dot', 'n' => 1]] && array_key_exists('room', $p), json_encode($p));
[$st, $h] = req($port, $W . '/pulse', $C);
$etag = (string)($h['etag'] ?? '');
[, , , $p2] = req($port, $W . '/pulse', $C);
ok('with an ETag that is the cursor', $etag === '"' . ($p2['cursor'] ?? '') . '"' && str_contains($h['cache-control'] ?? '', 'no-cache'));
ok('reading the thread put its dot out, and the cursor saw it', ($p2['unread'] ?? null) === [] && $p2['cursor'] !== $p['cursor']);
$t0 = microtime(true);
[$st, $h, $raw] = req($port, $W . '/pulse', array_merge($C, ['If-None-Match: ' . $etag]));
$ms = (int)round((microtime(true) - $t0) * 1000);
ok('nothing changed: 304, no body, the header still there', $st === 304 && $raw === '' && ($h['xeric-api'] ?? '') === '1.0'
    && ($h['etag'] ?? '') === $etag, "$st in {$ms}ms");
echo "     (the 304 took {$ms}ms)\n";
[$st] = req($port, $W . '/pulse', array_merge($C, ['If-None-Match: W/' . $etag . ', "zzz"']));
ok('a weak or listed match is a match too', $st === 304);

xeric_clock_resume($db);
$trip = xeric_travel_go($T, $db, 'beck_hardware');
xeric_clock_pause($db);
ok('a trip is taken in the world', $trip['ok'] === true, (string)($trip['error'] ?? ''));
[$st, $h, , $d] = req($port, $W . '/pulse', array_merge($C, ['If-None-Match: ' . $etag]));
ok('after a travel the old ETag is a 200 with a new one', $st === 200 && ($h['etag'] ?? '') !== ''
    && ($h['etag'] ?? '') !== $etag && ($d['where'] ?? '') === 'beck_hardware', "$st " . ($h['etag'] ?? ''));
$etag2 = (string)($h['etag'] ?? '');
xeric_message_append($db, $conv, 'character', 'dot', 'You left your hat.');
[$st] = req($port, $W . '/pulse', array_merge($C, ['If-None-Match: ' . $etag2]));
ok('and a new line changes it again', $st === 200);

echo "\n# who is asking\n";

[$st, $h, , $d] = req($port, $W, array_merge($C, ['Sec-Fetch-Site: cross-site']));
ok('a cross-site GET with a cookie identity is 403, enveloped', $st === 403 && ($d['kind'] ?? '') === 'cross_site'
    && ($d['ok'] ?? null) === false && ($d['api'] ?? '') === '1.0' && ($h['xeric-api'] ?? '') === '1.0');
[$st, , , $d] = req($port, $W, array_merge($C, ['Origin: https://evil.example']));
ok('and so is one that names a foreign Origin', $st === 403 && ($d['kind'] ?? '') === 'cross_site');
[$st, , , $d] = req($sport, $W, ['Sec-Fetch-Site: cross-site']);
ok('and so is a solo-identified one', $st === 403 && ($d['kind'] ?? '') === 'cross_site');
[$st, , , $d] = req($sport, '/api.php/v1/', ['Sec-Fetch-Site: same-origin']);
ok('a same-origin solo read is answered as solo', $st === 200 && ($d['auth']['via'] ?? '') === 'solo'
    && in_array('solo', $d['auth']['modes'] ?? [], true));

foreach (['Authorization: Bearer xd1_' . str_repeat('0', 64), 'Authorization: Bearer nonsense',
          'Authorization: Basic YTpi', 'Authorization: Bearer ' . $tokRevoked] as $bad) {
    [$st, $h, , $d] = req($sport, $W, [$bad]);
    ok('a bad credential on the solo machine is 401, never solo (' . substr($bad, 15, 12) . '…)',
        $st === 401 && ($d['kind'] ?? '') === 'token' && ($d['ok'] ?? null) === false
        && str_starts_with($h['www-authenticate'] ?? '', 'Bearer'));
}
[$st, , , $d] = req($sport, '/api.php/v1/?token=' . $tokKid . '&access_token=' . $tokKid);
ok('a token in the URL is not a credential: the request is whoever it would have been without it',
    $st === 200 && ($d['auth']['via'] ?? '') === 'solo', json_encode([$st, $d['auth'] ?? null]));
[$st, , , $d] = req($sport, $W, ['Authorization: Bearer ' . $tokAdult]);
ok('a good token is its session: the owner, on the canonical db', $st === 200 && ($d['mine'] ?? null) === true
    && ($d['rating'] ?? '') === 'mature', json_encode([$st, $d['mine'] ?? null, $d['rating'] ?? null]));
[$st, , , $d] = req($sport, $W, ['Authorization: Bearer ' . $tokKid, 'Sec-Fetch-Site: cross-site']);
ok('a token with adult=false clamps the rating to the floor, whatever the session affirmed',
    $st === 200 && ($d['rating'] ?? '') === xeric_rating_clamp('mature', false) && ($d['rating'] ?? '') !== 'mature',
    (string)($d['rating'] ?? ''));
ok('which is xeric_play_open\'s own clamp', xeric_world_rating(xeric_play_open($slug, null, false)['template'])
    === ($d['rating'] ?? '?'));
[, , , $d] = req($port, '/api.php/v1/', ['Authorization: Bearer ' . $tokKid]);
ok('and the index says how this request was identified', ($d['auth']['via'] ?? '') === 'bearer');

echo "\n# a render above the viewer\n";

$db->prepare("UPDATE scene_jobs SET rating = 'mature' WHERE key = 'bluebird' AND fingerprint = ?")->execute([$fp]);
[$st, , , $d] = req($port, $W . '/look/bluebird/' . $fp, ['Authorization: Bearer ' . $tokKid]);
ok('a render stamped above a kids\' headset is a 404 on the bytes route', $st === 404 && ($d['kind'] ?? '') === 'no_look');
[$st, $h, $raw] = req($port, $W . '/look/bluebird/' . $fp, ['Authorization: Bearer ' . $tokAdult]);
ok('and served to the adult one', $st === 200 && $raw === $png);
$lookRow = $db->query("SELECT * FROM scene_jobs WHERE key = 'bluebird' AND fingerprint = " . $db->quote($fp))->fetch(PDO::FETCH_ASSOC);
$prev = xeric_state_rating();
xeric_state_rating(xeric_rating_clamp('mature', false));
ok('the look reads past it for the floor (the brief\'s prose is what is left)',
    xeric_play_look_back($db, 'bluebird', $scenes) === null && xeric_play_look_file($db, 'bluebird', $fp) === null);
xeric_state_rating('mature');
ok('and finds it at the rating it was made under', (int)(xeric_play_look_back($db, 'bluebird', $scenes)['id'] ?? 0)
    === (int)$lookRow['id']);
xeric_state_rating($prev);
$db->prepare("UPDATE scene_jobs SET rating = '' WHERE key = 'bluebird' AND fingerprint = ?")->execute([$fp]);

echo "\n# the edges\n";

[$st, $h, , $d] = req($port, '/api.php?r=' . rawurlencode('/v1/worlds/' . $slug . '/look'), $C);
ok('?r= reaches the same route', $st === 200 && ($d['brief']['key'] ?? '') === 'beck_hardware',
    (string)$st);
ok('and hands out links in the shape that worked', $d === null || $d['scene'] === null
    || str_starts_with((string)$d['scene']['src'], '/api.php?r='));
[$st, , , $d] = req($port, '/api.php?r=' . rawurlencode('/v1/worlds/' . $slug . '/map'), $C);
ok('?r= map', $st === 200 && ($d['you']['where'] ?? '') === 'beck_hardware');

file_put_contents($scenes . '/scene-bluebird.png', '<script>alert(1)</script>');
[$st, $h, $raw, $d] = req($port, $W . '/look/bluebird/' . $fp, $C);
ok('a look whose bytes are not an image is a 404, enveloped', $st === 404 && ($d['kind'] ?? '') === 'no_look'
    && !str_contains($raw, '<script>'));
[$st, , , $d] = req($port, $W . '/look/bluebird/..%2F..%2Fetc', $C);
ok('a fingerprint that is not one is refused', $st === 404);
[$st, , , $d] = req($port, $W . '/messages/' . $m1 . '/audio', $C);
ok('the player\'s own line has no voice', $st === 404 && ($d['kind'] ?? '') === 'no_message');
$m3 = (int)$db->query("SELECT MAX(id) FROM messages")->fetchColumn();
[$st, , , $d] = req($port, $W . '/messages/' . $m3 . '/audio', $C);
ok('a line never read aloud is a 404 on the read API: rendering is a verb', $st === 404
    && ($d['kind'] ?? '') === 'not_rendered');
[$st, , , $d] = req($port, $W . '/threads/nobody', $C);
ok('a thread with nobody is 404', $st === 404 && ($d['kind'] ?? '') === 'no_character');
[$st, , , $d] = req($port, '/api.php/v1/worlds/no-such-world', $C);
ok('a world that is not there is 404', $st === 404 && ($d['kind'] ?? '') === 'no_world' && ($d['ok'] ?? null) === false);
[$st, , , $d] = req($port, '/api.php/v1/jobs/' . str_repeat('a', 24), $C);
ok('a job that is not there is 404', $st === 404 && ($d['kind'] ?? '') === 'no_job');
[$st, , , $d] = req($port, '/api.php/v1/nowhere', $C);
ok('an unknown route is 404 no_route', $st === 404 && ($d['kind'] ?? '') === 'no_route');
[$st, , , $d] = req($port, '/api.php/v2/worlds', $C);
ok('another major version is 404 version', $st === 404 && ($d['kind'] ?? '') === 'version');
[$st, $h, , $d] = req($port, $W, $C, 'POST');
ok('a POST to the read half is 405 with Allow', $st === 405 && ($d['kind'] ?? '') === 'method'
    && str_contains($h['allow'] ?? '', 'GET'));
[$st, $h, , $d] = req($port, '/api.php', $C);
ok('the bare script names its versions', $st === 200 && ($d['versions'] ?? []) === ['v1']);
ok('no answer carries CORS headers', !isset($h['access-control-allow-origin']));

echo "\n# the old pages are untouched\n";

[$st, $h, , $d] = req($port, '/where.php?w=' . $slug, $C);
ok('where.php answers as it always has: no envelope, no header', $st === 200 && !isset($d['api'])
    && !isset($h['xeric-api']) && ($d['ok'] ?? null) === true);
[$st, $h, , $d] = req($port, '/play.php?w=' . $slug . '&a=state', $C);
ok('play.php a=state still carries its HTML', $st === 200 && isset($d['cast_html']) && !isset($d['api']));

// ===========================================================================
// THE WRITE HALF
// ===========================================================================

echo "\n# the write half: a world that is running, a machine that answers\n";

// A second world, running, with three people always at the Bluebird so the
// room has company whatever the wall clock says (room-web-test's fixture),
// and the owner's model pointed at the stub machine.
$vslug = 'api-verbs';
$vT = $fixT;
$every = [0, 1, 2, 3, 4, 5, 6];
foreach ($vT['cast']['characters'] as &$vc) {
    $vc['week'] = match ((string)$vc['handle']) {
        'ruth', 'pastor_dale', 'dot' => [['days' => $every, 'where' => 'bluebird', 'doing' => 'at the counter']],
        'janelle'                    => [['days' => $every, 'where' => 'first_lutheran', 'doing' => 'setting up']],
        default                      => [['days' => $every, 'where' => 'beck_hardware', 'doing' => 'at the till']],
    };
}
unset($vc);
$vdir = xeric_web_worlds_dir() . '/' . $vslug;
@mkdir($vdir, 0775, true);
file_put_contents($vdir . '/world-template.json', json_encode($vT, $J));
file_put_contents($vdir . '/seed.json', json_encode(xeric_forge_default_seed($vT), $J));
xeric_session_use($A);
xeric_session_claim($vslug, $A);
xeric_web_session_edit(function (array &$s) use ($MACHINE): void {
    $s['model'] = ['kind' => 'local', 'local' => $MACHINE, 'base' => '', 'model' => ''];
}, $A);
$vw  = xeric_play_open($vslug, null, true);
$vdb = $vw['db'];
if (xeric_clock_is_paused($vdb)) xeric_clock_resume($vdb);
xeric_player_move($vdb, 'bluebird', xeric_clock_epoch($vdb));
$V   = '/api.php/v1/worlds/' . $vslug;
$JS  = 'Content-Type: application/json';
$calls = fn(): string => (string)@file_get_contents($machine . '/calls.log');
ok('the owner\'s world is running and they stand at the Bluebird',
    !xeric_clock_is_paused($vdb) && xeric_player_where($vT, $vdb) === 'bluebird');

echo "\n# the link ceremony\n";

[$st, , $html] = req($port, '/link.php', $C, 'POST', 'a=mint&label=' . rawurlencode('the headset'));
$code = preg_match('#id="code">([A-Z0-9]{8})<#', $html, $mm) ? $mm[1] : '';
ok('the owner mints a link code on link.php, shown once', $st === 200 && strlen($code) === 8, substr(strip_tags($html), 0, 200));
[$st, $h, , $d] = req($port, '/api.php/v1/link', [$JS], 'POST', json_encode(['code' => strtolower($code)]));
$OT = (string)($d['token'] ?? '');
$bodies['link'] = $d ?? [];
$ODEV = (int)($d['device'] ?? 0);
ok('redeemed over loopback it is a token for the SAME person, role owner, enveloped',
    $st === 200 && ($d['api'] ?? '') === '1.0' && ($d['ok'] ?? null) === true && ($d['role'] ?? '') === 'owner'
    && preg_match(XERIC_API_TOKEN_RE, $OT) === 1 && ($d['label'] ?? '') === 'the headset' && $ODEV > 0
    && ($d['adult'] ?? null) === true, json_encode($d));
ok('and it resolves to the owner\'s session', (xeric_api_token_resolve($OT)['sid'] ?? '') === $A);
[$st, , , $d] = req($port, '/api.php/v1/link', [$JS], 'POST', json_encode(['code' => $code]));
ok('the same code a second time is spent: 410', $st === 410 && ($d['kind'] ?? '') === 'code' && !isset($d['token']));

$good = xeric_api_link_new($A, 'spare')['code'];
$locked = 0;
for ($i = 1; $i <= 5; $i++) {
    [$st, $h, , $d] = req($port, '/api.php/v1/link', [$JS], 'POST', json_encode(['code' => 'ZZZZ' . $i . '222']));
    if ($st === 429) { $locked = $i; break; }
    if ($st !== 410) break;
}
ok('wrong codes, five at most, and the door shuts: 429 locked with Retry-After',
    $locked > 0 && $locked <= 5 && ($d['kind'] ?? '') === 'locked' && (int)($h['retry-after'] ?? 0) > 0, "$st after $i");
[$st, , , $d] = req($port, '/api.php/v1/link', [$JS], 'POST', json_encode(['code' => $good]));
ok('and while it is shut even a good code is refused', $st === 429 && !isset($d['token']));
xeric_api_tokens_db()->exec('DELETE FROM api_door');
[$st, , , $d] = req($port, '/api.php/v1/link', [$JS], 'POST', json_encode(['code' => $good]));
ok('a good code that waited out the lock still works (it was not burned)', $st === 200 && isset($d['token']));
$SPARE = (string)($d['token'] ?? '');

[$st, $h, , $d] = req($port, '/api.php/v1/link', $C);
ok('a GET of link is 405 with Allow', $st === 405 && str_contains($h['allow'] ?? '', 'POST'));
[$st, , , $d] = req($port, '/api.php/v1/link', [$JS, 'Sec-Fetch-Site: cross-site'], 'POST', json_encode(['code' => 'X']));
ok('a ceremony from another site is refused', $st === 403 && ($d['kind'] ?? '') === 'cross_site');

echo "\n# the verbs, as the owner's device\n";

$B = ['Authorization: Bearer ' . $OT];
[$st, $h, , $d] = req($port, $V . '/go', array_merge($B, [$JS]), 'POST', json_encode(['to' => 'beck_hardware']));
$bodies['go'] = $d ?? [];
ok('go moves the player: enveloped, the map after, and the world agrees',
    $st === 200 && ($d['ok'] ?? null) === true && ($d['world'] ?? '') === $vslug
    && ($d['went']['to'] ?? '') === 'beck_hardware' && ($d['you']['where'] ?? '') === 'beck_hardware'
    && xeric_player_where($vT, $vdb) === 'beck_hardware', "$st " . json_encode($d));
ok('a Bearer answer sets no cookie: the device never holds the owner\'s sid', !isset($h['set-cookie']),
    (string)($h['set-cookie'] ?? ''));
$seen = xeric_api_tokens_db()->query('SELECT seen_at FROM api_tokens WHERE id = ' . $ODEV)->fetchColumn();
ok('and the device is seen', (int)$seen >= time() - 120);
[$st, , , $d] = req($port, $V . '/go', array_merge($B, [$JS]), 'POST', json_encode(['to' => 'the_moon']));
ok('a place that is not there is the script\'s own refusal, enveloped', $st === 400 && ($d['ok'] ?? null) === false
    && ($d['kind'] ?? '') === 'travel');
req($port, $V . '/go', array_merge($B, [$JS]), 'POST', json_encode(['to' => 'bluebird']));
ok('and back to the Bluebird', xeric_player_where($vT, $vdb) === 'bluebird');

$before = (int)$vdb->query('SELECT COUNT(*) FROM messages')->fetchColumn();
[$st, , , $d] = req($port, $V . '/threads/dot/say', array_merge($B, [$JS]), 'POST',
    json_encode(['text' => 'Is there coffee?', 'world' => 'api-town']));
$bodies['say'] = $d ?? [];
$after = (int)$vdb->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$REPLY = (int)($d['id'] ?? 0);
ok('say to the stub model appends the turn and answers with it', $st === 200 && ($d['ok'] ?? null) === true
    && $after === $before + 2 && ($d['text'] ?? '') === 'Coffee is on. Sit anywhere you like.'
    && $REPLY > 0 && !isset($d['mid']), "$st " . substr(json_encode($d), 0, 300));
ok('in the world the path named, never the body\'s', ($d['world'] ?? '') === $vslug
    && (int)$db->query("SELECT COUNT(*) FROM messages WHERE content = 'Is there coffee?'")->fetchColumn() === 0);
ok('with the reply\'s epoch beside its when, and the state as v1 reads it',
    is_int($d['epoch'] ?? null) && $d['epoch'] > 0 && is_int($d['state']['clock']['offset'] ?? null)
    && is_int($d['state']['clock']['server'] ?? null) && !has_html_key($d));

$pc = xeric_conversation_for($vdb, 'pastor_dale');
xeric_message_append($vdb, $pc, 'character', 'pastor_dale', 'Sunday, then.');
xeric_message_append($vdb, $pc, 'character', 'pastor_dale', 'Bring the casserole dish back.');
$vdb->prepare('UPDATE conversations SET unread = 2 WHERE id = ?')->execute([$pc]);
$sig0 = (int)$vdb->query("SELECT COUNT(*) FROM signals WHERE kind = 'dwell' AND handle = 'pastor_dale'")->fetchColumn();
[$st, , , $d] = req($port, $V . '/threads/pastor_dale/read', $B, 'POST');
$bodies['read'] = $d ?? [];
$sig1 = (int)$vdb->query("SELECT COUNT(*) FROM signals WHERE kind = 'dwell' AND handle = 'pastor_dale'")->fetchColumn();
ok('read clears unread and records the learn signal, as play.php\'s a=thread does',
    $st === 200 && ($d['unread'] ?? -1) === 2 && ($d['read'] ?? null) === true && ($d['learned'] ?? null) === true
    && (int)xeric_conversation_get($vdb, $pc)['unread'] === 0 && $sig1 === $sig0 + 1, json_encode($d));
[$st, , , $d] = req($port, $V . '/threads/nobody/read', $B, 'POST');
ok('read of nobody is 404', $st === 404 && ($d['kind'] ?? '') === 'no_character');

[$st, , , $d] = req($port, $V . '/look', $B, 'POST');
ok('look POST is scene.php\'s look: with no scene machine, its own refusal, enveloped',
    $st === 409 && ($d['ok'] ?? null) === false && ($d['api'] ?? '') === '1.0' && ($d['kind'] ?? '') !== '',
    "$st " . json_encode($d));

$off0 = xeric_clock_offset($vdb);
[$st, , , $d] = req($port, $V . '/skip', array_merge($B, [$JS]), 'POST', json_encode(['span' => '1h', 'rewind' => 1]));
$bodies['skip'] = $d ?? [];
$sjob = (string)($d['job'] ?? '');
ok('skip starts tick.php\'s worker and points at the v1 job route', $st === 200 && $sjob !== ''
    && ($d['stream'] ?? '') === '/api.php/v1/jobs/' . $sjob, "$st " . json_encode($d));
$done = false;
for ($i = 0; $i < 120 && $sjob !== ''; $i++) {
    [, , , $jd] = req($port, '/api.php/v1/jobs/' . $sjob, $B);
    if (($jd['done'] ?? false) === true) { $done = true; break; }
    usleep(250000);
}
$off1 = xeric_clock_offset($vdb);
ok('and the clock advanced an hour', $done && $off1 - $off0 >= 3500, ($off1 - $off0) . ' ' . json_encode(array_slice((array)($jd['records'] ?? []), -2)));
[$st, , , $d] = req($port, $V . '/rewind', $B, 'POST');
ok('rewind is tick.php\'s rewind, enveloped either way', in_array($st, [200, 409], true)
    && ($d['api'] ?? '') === '1.0' && is_bool($d['ok'] ?? null) && !isset($d['why']), "$st " . json_encode($d));
if ($st === 200) ok('and it took the hour back', xeric_clock_offset($vdb) < $off1);

$bnd = 'xericapitest' . bin2hex(random_bytes(4));
$mp = "--$bnd\r\nContent-Disposition: form-data; name=\"h\"\r\n\r\ndot\r\n"
    . "--$bnd\r\nContent-Disposition: form-data; name=\"audio\"; filename=\"line.webm\"\r\nContent-Type: audio/webm\r\n\r\n"
    . "\x1A\x45\xDF\xA3" . str_repeat("\x00", 200) . "\r\n--$bnd--\r\n";
[$st, , , $d] = req($port, $V . '/hear', array_merge($B, ['Content-Type: multipart/form-data; boundary=' . $bnd]), 'POST', $mp);
$bodies['hear'] = $d ?? [];
ok('hear sends the recording to the stub ears and answers with text', $st === 200
    && ($d['text'] ?? '') === 'Is the pie fresh today?' && str_contains($calls(), 'POST /v1/audio/transcriptions'),
    "$st " . json_encode($d));

[$st, , , $d] = req($port, $V . '/messages/' . $REPLY . '/audio', $B);
ok('the reply has not been read aloud: GET is cache-only, 404 not_rendered', $st === 404
    && ($d['kind'] ?? '') === 'not_rendered');
[$st, $h, $raw] = req($port, $V . '/messages/' . $REPLY . '/audio', $B, 'POST');
ok('POST is a miss that renders through the stub voice', $st === 200 && ($h['content-type'] ?? '') === 'audio/mpeg'
    && str_ends_with($raw, 'stub-voice') && substr_count($calls(), 'POST /v1/audio/speech') === 1, "$st " . substr($raw, 0, 200));
[$st, $h, $raw] = req($port, $V . '/messages/' . $REPLY . '/audio', $B);
ok('and a GET then hits, with no second render', $st === 200 && str_ends_with($raw, 'stub-voice')
    && substr_count($calls(), 'POST /v1/audio/speech') === 1);
$umid = (int)$vdb->query("SELECT MAX(id) FROM messages WHERE role = 'user'")->fetchColumn();
[$st, , , $d] = req($port, $V . '/messages/' . $umid . '/audio', $B, 'POST');
ok('the player\'s own line has no voice to render', $st === 404 && ($d['kind'] ?? '') === 'no_message');

echo "\n# the room\n";

[, $h0] = req($port, $V . '/pulse', $B);
[$st, , , $d] = req($port, $V . '/room/say', array_merge($B, [$JS]), 'POST', json_encode(['text' => 'Morning, all.']));
$bodies['room_say'] = $d ?? [];
$said = (int)($d['id'] ?? 0);
$saidAt = time();
ok('room/say lands the line and opens the table', $st === 200 && $said > 0 && (int)($d['conv'] ?? 0) > 0
    && is_int($d['epoch'] ?? null), "$st " . json_encode($d));
[$st, $h1, , $p] = req($port, $V . '/pulse', array_merge($B, ['If-None-Match: ' . ($h0['etag'] ?? '')]));
ok('the pulse\'s room slot is filled, and its cursor moved', $st === 200 && is_array($p['room'] ?? null)
    && ($p['room']['conv'] ?? 0) === ($d['conv'] ?? -1) && ($p['room']['last'] ?? 0) >= $said, json_encode($p['room'] ?? null));
$cast = null;
for ($i = 0; $i < 160; $i++) {
    [$st, , , $rd] = req($port, $V . '/room?after=' . $said, $B);
    foreach ((array)($rd['rows'] ?? []) as $row) if (($row['who'] ?? '') === 'them') { $cast = $row; break 2; }
    usleep(250000);
}
$bodies['room'] = $rd ?? [];
$castAt = $cast !== null ? (int)$vdb->query('SELECT created_at FROM messages WHERE id = ' . (int)$cast['id'])->fetchColumn() : 0;
ok('the poll yields a cast row from the stub model, after the line it answers',
    $cast !== null && (int)$cast['id'] > $said && is_int($cast['epoch'] ?? null) && ($rd['ok'] ?? null) === true,
    json_encode($rd));
ok('and the row was written at or after it was due: never before the line it answers', $castAt >= $saidAt - 1,
    "$castAt vs $saidAt");
[, , , $p2] = req($port, $V . '/pulse', $B);
ok('the pulse\'s room carries the newest row', ($p2['room']['last'] ?? 0) >= (int)($cast['id'] ?? PHP_INT_MAX));
[$st, , , $d] = req($port, $V . '/room/carry', $B, 'POST');
ok('carry answers enveloped (a quiet room is a 409, not an error)', in_array($st, [200, 409], true)
    && ($d['api'] ?? '') === '1.0');
for ($i = 0; $i < 60; $i++) {
    [$st, , , $d] = req($port, $V . '/room/leave', $B, 'POST');
    if ($st === 200) break;
    usleep(250000);
}
ok('leave seals the table', $st === 200 && ($d['ok'] ?? null) === true, "$st " . json_encode($d));

echo "\n# join: a new player\n";

xeric_state_migrate($vdb);
$inv = xeric_pair_new($vdb, 'Sam')['code'];
[$st, , , $d] = req($port, '/api.php/v1/join', [$JS], 'POST', json_encode(['world' => $vslug, 'code' => $inv, 'name' => 'Sam']));
$bodies['join'] = $d ?? [];
$GT = (string)($d['token'] ?? '');
$gres = xeric_api_token_resolve($GT);
ok('join is join.php\'s ceremony: a guest token for a new session, a new player', $st === 200
    && ($d['role'] ?? '') === 'guest' && (int)($d['player'] ?? 0) >= 2 && $gres !== null && $gres['sid'] !== $A
    && $gres['adult'] === false && xeric_session_player($vslug, $gres['sid']) === (int)$d['player'], "$st " . json_encode($d));
[$st, , , $d] = req($port, '/api.php/v1/join', [$JS], 'POST', json_encode(['world' => $vslug, 'code' => $inv, 'name' => 'Sam']));
ok('the invite is spent', $st === 410 && ($d['kind'] ?? '') === 'code');
$G = ['Authorization: Bearer ' . $GT];
// The guest's own machine choice: their turn is answered on the machine their
// session names (xeric_play_speaker_endpoint). Not the clock's reason any more —
// a guest's open never reconciles the owner's clock (xeric_play_open).
if ($gres !== null) {
    xeric_web_session_edit(function (array &$s) use ($MACHINE): void {
        $s['model'] = ['kind' => 'local', 'local' => $MACHINE, 'base' => '', 'model' => ''];
    }, $gres['sid']);
}
$gb = (int)$vdb->query('SELECT COUNT(*) FROM messages')->fetchColumn();
[$st, , , $d] = req($port, $V . '/threads/janelle/say', array_merge($G, [$JS]), 'POST', json_encode(['text' => 'Hello, I am Sam.']));
ok('the guest can say, into the owner\'s own evening', $st === 200 && ($d['ok'] ?? null) === true
    && (int)$vdb->query('SELECT COUNT(*) FROM messages')->fetchColumn() === $gb + 2, "$st " . json_encode($d));
[$st, , , $d] = req($port, $V . '/go', array_merge($G, [$JS]), 'POST', json_encode(['to' => 'beck_hardware']));
// A BODY EACH (docs/MULTIPLAYER.md): go was the owner's door while there was
// one body; now a joined guest walks their own, and the owner's stays put.
ok('and go walks the guest\'s own body, never the owner\'s', $st === 200
    && ($d['you']['where'] ?? '') === 'beck_hardware' && ($d['you']['name'] ?? '') === 'Sam'
    && xeric_player_where($vT, $vdb, 1) === 'bluebird'
    && xeric_player_where($vT, $vdb, (int)xeric_session_player($vslug, (string)($gres['sid'] ?? ''))) === 'beck_hardware',
    "$st " . mb_substr(json_encode($d), 0, 300));
// THE PULSE MOVES WHEN THE OTHER PLAYER WALKS. The owner's cursor hashes
// every body once there is more than one, so a friend's walk repaints the
// owner's screen (their tag, their ring) — moved here with no clock spent, so
// the body is the only thing that changed.
$gidW = (int)xeric_session_player($vslug, (string)($gres['sid'] ?? ''));
[, $hw0] = req($port, $V . '/pulse', $B);
[$stW0] = req($port, $V . '/pulse', array_merge($B, ['If-None-Match: ' . ($hw0['etag'] ?? '')]));
xeric_player_move($vdb, 'first_lutheran', null, $gidW);
[$stW1, $hw1, , $pw1] = req($port, $V . '/pulse', array_merge($B, ['If-None-Match: ' . ($hw0['etag'] ?? '')]));
ok('the pulse moves when the other player walks: the owner\'s old ETag is a 200, the friend\'s body in it',
    $stW0 === 304 && $stW1 === 200 && ($hw1['etag'] ?? '') !== ($hw0['etag'] ?? '')
    && (($pw1['players'] ?? [])[(string)$gidW] ?? '') === 'first_lutheran' && ($pw1['where'] ?? '') === 'bluebird',
    "$stW0 $stW1 " . json_encode($pw1['players'] ?? null));
ok('and a world of one carries no bodies key, so its cursor is the one it always was',
    !array_key_exists('players', $bodies['pulse']));
xeric_player_move($vdb, 'beck_hardware', null, $gidW);
[$st, , , $d] = req($port, $V . '/hear', array_merge($G, ['Content-Type: multipart/form-data; boundary=' . $bnd]), 'POST', $mp);
ok('and on hear: the ears are the owner\'s', $st === 403 && ($d['kind'] ?? '') === 'guest');
[$st, , , $d] = req($port, $V, $G);
ok('a guest token carries adult=false, and it clamps', $st === 200 && ($d['guest'] ?? null) === true
    && ($d['rating'] ?? '') === xeric_rating_clamp('mature', false), (string)($d['rating'] ?? ''));

echo "\n# who said it, and who may read it\n";

$who = fn(string $text): array => (array)($vdb->query('SELECT player, rating FROM messages WHERE content = '
    . $vdb->quote($text) . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: []);
$gid = (int)xeric_session_player($vslug, (string)($gres['sid'] ?? ''));
ok('the owner\'s line through the API is player 1\'s', (int)($who('Is there coffee?')['player'] ?? 0) === 1);
ok('and the guest\'s is the guest\'s own number', $gid >= 2 && (int)($who('Hello, I am Sam.')['player'] ?? 0) === $gid);
ok('each stamped with the rating its own request ran at: the adult headset\'s, the guest\'s floor',
    ($who('Is there coffee?')['rating'] ?? '') === 'mature'
    && ($who('Hello, I am Sam.')['rating'] ?? '') === xeric_rating_clamp('mature', false));
[$st, , , $d] = req($port, $V . '/threads/dot', $G);
$gm = (array)($d['messages'] ?? []);
$asked = array_values(array_filter($gm, fn($m) => (int)($m['player'] ?? 0) === 1));
ok('the guest reads the owner\'s adult-stamped lines as gaps: no words, `redacted`',
    $st === 200 && $asked !== [] && $asked[0]['redacted'] === true && $asked[0]['text'] === XERIC_STATE_UNSHOWN
    && !str_contains(json_encode($d), 'Is there coffee?'), "$st " . substr(json_encode($gm), 0, 300));
[$st, , , $d] = req($port, $V . '/threads/dot', $B);
$om = array_values(array_filter((array)($d['messages'] ?? []), fn($m) => (int)($m['player'] ?? 0) === 1));
ok('while the adult headset reads them whole, `player` and `redacted` on every row',
    $st === 200 && $om !== [] && $om[0]['text'] === 'Is there coffee?' && $om[0]['redacted'] === false
    && array_filter((array)$d['messages'], fn($m) => !array_key_exists('player', $m) || !array_key_exists('redacted', $m)) === [],
    substr(json_encode($d['messages'] ?? null), 0, 300));
[$st, , , $d] = req($port, $V . '/threads/janelle', $B);
$jm = (array)($d['messages'] ?? []);
ok('a line stamped at the floor is shown to everybody', $st === 200 && in_array('Hello, I am Sam.', array_column($jm, 'text'), true));

echo "\n# the devices, on link.php\n";

[$st, , $html] = req($port, '/link.php', $C);
ok('link.php lists this session\'s devices: label, role, adult, last seen',
    $st === 200 && str_contains($html, 'data-device="' . $ODEV . '"') && str_contains($html, 'the headset'));
[$st, , $html] = req($port, '/link.php', array_merge($C, ['Authorization: Bearer ' . $OT]));
ok('and refuses a device token outright', $st === 403 && !str_contains($html, 'the headset'));
[$st] = req($port, '/link.php', array_merge($C, ['Sec-Fetch-Site: cross-site']));
ok('and anything from another site', $st === 403);
[$st, , , $d] = req($port, $V, $B);
ok('the owner\'s headset reads the world at its rating', ($d['rating'] ?? '') === 'mature');
req($port, '/link.php', $C, 'POST', 'a=lower&id=' . $ODEV);
[$st, , , $d] = req($port, $V, $B);
ok('lowered on link.php, the same token is a kids\' headset', $st === 200
    && ($d['rating'] ?? '') === xeric_rating_clamp('mature', false));
ok('and nothing raises it back', !function_exists('xeric_api_device_raise')
    && (int)xeric_api_tokens_db()->query('SELECT adult FROM api_tokens WHERE id = ' . $ODEV)->fetchColumn() === 0);
req($port, '/link.php', $C, 'POST', 'a=revoke&id=' . $ODEV);
[$st, , , $d] = req($port, $V . '/pulse', $B);
ok('revoked on link.php, the token is a 401', $st === 401 && ($d['kind'] ?? '') === 'token');
[$st] = req($port, '/link.php', $C, 'POST', 'a=revoke&id=' . (int)xeric_api_token_resolve($GT)['id']);
[$st, , , $d] = req($port, $V . '/pulse', $G);
ok('a guest\'s device is not the owner\'s to revoke from their own list (it is the guest\'s session)', $st === 200);

echo "\n# the doors around the verbs\n";

$S = ['Authorization: Bearer ' . $SPARE];
[$st, $h, , $d] = req($port, $V . '/go', $S);
ok('a GET of a verb is 405 with Allow: POST', $st === 405 && ($h['allow'] ?? '') === 'POST' && ($d['kind'] ?? '') === 'method');
[$st, $h] = req($port, $V . '/threads/dot', $S, 'POST');
ok('a POST to a read is 405 with Allow: GET', $st === 405 && str_contains($h['allow'] ?? '', 'GET'));
[$st, $h] = req($port, $V . '/room', $S, 'POST');
ok('the room poll is a GET', $st === 405);
[$st, , , $d] = req($port, $V . '/go', array_merge($C, [$JS, 'Sec-Fetch-Site: cross-site']), 'POST', json_encode(['to' => 'beck_hardware']));
ok('a cookie-identified cross-site go is refused', $st === 403 && ($d['kind'] ?? '') === 'cross_site'
    && xeric_player_where($vT, $vdb) === 'bluebird');
[$st, , , $d] = req($sport, $V . '/go', ['Origin: https://evil.example', $JS], 'POST', json_encode(['to' => 'beck_hardware']));
ok('and a solo-identified one', $st === 403 && ($d['kind'] ?? '') === 'cross_site');
[$st, , , $d] = req($port, $V . '/go', array_merge($S, [$JS, 'Sec-Fetch-Site: cross-site']), 'POST', json_encode(['to' => 'beck_hardware']));
ok('a Bearer-identified one passes: no browser sends that header on its own', $st === 200
    && xeric_player_where($vT, $vdb) === 'beck_hardware', "$st " . json_encode($d));
[$st, , , $d] = req($port, $V . '/go', array_merge($C, [$JS]), 'POST', json_encode(['to' => 'bluebird']));
ok('and the cookie owner, same-site, walks too', $st === 200 && xeric_player_where($vT, $vdb) === 'bluebird', "$st " . json_encode($d));

echo "\n# DNS rebinding: the Host pin\n";

[$st, , , $d] = req($sport, '/api.php/v1/', ['Host: evil.example:' . $sport]);
ok('a solo request naming a foreign Host is 403 host', $st === 403 && ($d['kind'] ?? '') === 'host'
    && ($d['api'] ?? '') === '1.0');
[$st, , , $d] = req($sport, $V . '/go', ['Host: rebound.example', $JS], 'POST', json_encode(['to' => 'beck_hardware']));
ok('verbs included', $st === 403 && ($d['kind'] ?? '') === 'host' && xeric_player_where($vT, $vdb) === 'bluebird');
// (A GET by `localhost` is boot.php's one-name redirect to 127.0.0.1 before
// anything else runs, so the pin is asked by a POST.)
[$st, , , $d] = req($sport, $V . '/threads/dot/read', ['Host: localhost:' . $sport], 'POST');
ok('localhost on this port is the owner', $st === 200 && ($d['read'] ?? null) === true, "$st " . json_encode($d));
[$st] = req($sport, '/api.php/v1/', ['Host: 127.0.0.1:1']);
ok('a port this server is not listening on is refused too', $st === 403);
[$st, , , $d] = req($sport, '/api.php/v1/', ['Host: evil.example', 'Authorization: Bearer ' . $SPARE]);
ok('a Bearer request is not ambient and is not pinned', $st === 200 && ($d['auth']['via'] ?? '') === 'bearer');
[$st] = req($sport, '/link.php', ['Host: evil.example:' . $sport]);
ok('and link.php, whose code a rebound page could otherwise read, pins it as well', $st === 403);
$pin = fn(string $host, array $more = []) => xeric_api_host_pinned(['HTTP_HOST' => $host, 'SERVER_PORT' => '8787'] + $more);
ok('the pin, unit by unit: [::1], an IP literal, the bound name; not a lookalike',
    $pin('[::1]:8787') && $pin('192.168.1.20:8787') && $pin('LOCALHOST') && !$pin('localhost.evil.example')
    && !$pin('127.0.0.1.nip.io:8787') && !$pin('evil.example'));

echo "\n# a token over plain http is a key in the clear\n";

/** api.php, run as one request in a child with the peer and environment given. */
$asPeer = function (string $peer, array $post, string $route, string $env = '') use ($tmp, $WEB): array {
    $boot = '$_SERVER["REMOTE_ADDR"] = ' . var_export($peer, true) . '; $_SERVER["REQUEST_METHOD"] = "POST";'
        . ' $_SERVER["PATH_INFO"] = ' . var_export($route, true) . '; $_SERVER["HTTP_HOST"] = "192.168.1.5:8787";'
        . ' $_POST = ' . var_export($post, true) . ';'
        . ' register_shutdown_function(function () { echo "\n@@STATUS " . http_response_code(); });'
        . ' require ' . var_export($WEB . '/api.php', true) . ';';
    $cmd = $env . ' XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
         . ' XERIC_LOCAL_BASE=http://127.0.0.1:1 XERIC_SOLO=0 ' . escapeshellarg(PHP_BINARY)
         . ' -d error_reporting=0 -r ' . escapeshellarg($boot) . ' 2>&1';
    $raw = (string)shell_exec($cmd);
    $status = preg_match('/@@STATUS (\d+)\s*$/', $raw, $m) ? (int)$m[1] : 0;
    $j = json_decode(trim((string)preg_replace('/@@STATUS \d+\s*$/', '', $raw)), true);
    return [$status, is_array($j) ? $j : null, $raw];
};
$lan = xeric_api_link_new($A, 'phone on the wifi')['code'];
[$st, $d, $raw] = $asPeer('192.168.1.20', ['code' => $lan], '/v1/link');
ok('POST link from a LAN peer over plain http is refused: 403 insecure', $st === 403 && ($d['kind'] ?? '') === 'insecure'
    && !isset($d['token']), "$st " . substr($raw, 0, 200));
ok('and the refusal names the two no-TLS setups and the tailscale recipe', str_contains((string)($d['error'] ?? ''), 'adb reverse')
    && str_contains((string)($d['error'] ?? ''), 'Quest Link') && str_contains((string)($d['error'] ?? ''), 'tailscale serve'));
$inv2 = xeric_pair_new($vdb, '')['code'];
[$st, $d] = $asPeer('192.168.1.20', ['world' => $vslug, 'code' => $inv2, 'name' => 'Ann'], '/v1/join');
ok('so is POST join', $st === 403 && ($d['kind'] ?? '') === 'insecure');
[$st, $d] = $asPeer('192.168.1.20', ['code' => $lan], '/v1/link', 'XERIC_LAN_TOKENS=1');
ok('with XERIC_LAN_TOKENS=1 the operator has said yes, and the same code (never burned) mints',
    $st === 200 && ($d['role'] ?? '') === 'owner' && isset($d['token']), "$st " . json_encode($d));
ok('TLS is enough on its own', xeric_api_mint_refusal(['HTTPS' => 'on', 'REMOTE_ADDR' => '10.0.0.9']) === null
    && xeric_api_mint_refusal(['REMOTE_ADDR' => '::1']) === null
    && xeric_api_mint_refusal(['HTTPS' => 'off', 'REMOTE_ADDR' => '10.0.0.9']) !== null);


echo "\n# the contract\n";

$snapPath = __DIR__ . '/api-contract.json';
$now = [];
foreach ($bodies as $name => $b) $now[$name] = key_paths($b);
if (getenv('XERIC_API_CONTRACT') === 'write') {
    file_put_contents($snapPath, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "     (snapshot written to $snapPath)\n";
}
$snap = json_decode((string)@file_get_contents($snapPath), true);
ok('the snapshot is in the repo', is_array($snap) && $snap !== []);
foreach ((array)$snap as $name => $keys) {
    $gone = array_values(array_diff((array)$keys, $now[$name] ?? []));
    $new  = array_values(array_diff($now[$name] ?? [], (array)$keys));
    ok("$name keeps every key it promised", $gone === [], implode(' ', array_slice($gone, 0, 8)));
    if ($new !== []) echo "     note: $name answers with keys the snapshot does not hold (additive; regenerate "
        . "with XERIC_API_CONTRACT=write): " . implode(' ', array_slice($new, 0, 8)) . "\n";
}
ok('every route is in the snapshot', is_array($snap) && array_diff(array_keys($bodies), array_keys($snap)) === []);
ok('and every snapshot names the envelope', is_array($snap) && array_filter($snap,
    fn($k) => !in_array('api', $k, true) || !in_array('ok', $k, true)) === []);

echo "\n" . ($FAILED === 0 ? "all api tests passed\n" : "$FAILED api test(s) FAILED\n");
exit($FAILED === 0 ? 0 : 1);
