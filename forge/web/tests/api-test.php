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

// -- the server ---------------------------------------------------------------

$serve = function (string $solo) use ($tmp, $WEB): array {
    $port = 0;
    for ($try = 8700 + random_int(0, 150); $try < 8999; $try++) {
        $probe = @fsockopen('127.0.0.1', $try, $e1, $e2, 0.2);
        if ($probe === false) { $port = $try; break; }
        fclose($probe);
    }
    $env = 'XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
         . ' XERIC_LOCAL_BASE=http://127.0.0.1:1 XERIC_SOLO=' . $solo . ' PHP_CLI_SERVER_WORKERS=2';
    $pid = (int)trim((string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
        . ' -t ' . escapeshellarg($WEB) . ' ' . escapeshellarg($WEB . '/router.php')
        . ' > ' . escapeshellarg($tmp . '/server-' . $port . '.log') . ' 2>&1 & echo $!'));
    for ($i = 0; $i < 60; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
        if ($s !== false) { fclose($s); return [$port, $pid]; }
        usleep(150000);
    }
    return [0, $pid];
};
[$port, $pid]   = $serve('0');
[$sport, $spid] = $serve('1');
register_shutdown_function(function () use ($pid, $spid, $tmp): void {
    if ($pid > 0) @posix_kill($pid, 15) || @shell_exec('kill ' . $pid);
    if ($spid > 0) @posix_kill($spid, 15) || @shell_exec('kill ' . $spid);
    usleep(200000);
    rmtree($tmp);
});
ok('two test servers answer, one cookie and one solo', $port > 0 && $sport > 0);
if ($port === 0 || $sport === 0) { echo "\nno server\n"; exit(1); }

/** One request. [status, headers (lowercased), body, decoded]. */
function req(int $port, string $path, array $headers = [], string $method = 'GET'): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0,
        'header' => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $ctx);
    $hs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $status = 0;
    $h = [];
    foreach ($hs as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; $h = []; continue; }
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $h[strtolower(trim($k))] = trim($v);
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
