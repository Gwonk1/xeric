<?php
/**
 * Xeric — bet 1, tested.  `php engine/tests/bet-test.php`, exit 0 on pass.
 *
 * FUTURE.md M5, done when: "the same room, described by the same brief, is
 * drawn by a stored panorama, a splat and a stream, and a person cannot tell
 * from the diner which machine drew it" — and "Xeric does not change shape
 * for this. If it has to, bet 1 lost."
 *
 * So: ONE brief, composed once, and THREE real machines on loopback, each
 * driven through Xeric's own adapter and nothing hand-rolled —
 *
 *   pano    tools/proxies/images.py --pano, in front of fake_upstream.py's
 *           images server; the reaper asks, the row lands as a pano.
 *   splat   a generic machine written here (a php -S router) that answers a
 *           look with three splats in the compact .splat format; the reaper
 *           asks with splat first, the row lands as PLY.
 *   stream  tools/proxies/worldmodel.py; "go live" (xeric_stream_start), three
 *           MJPEG parts read off the session, one engine event (somebody
 *           walks in), and the next frame is not the last one.
 *
 * Every request every machine was given is written down by one recording
 * tee in front of all three (tools/proxies/bet_harness.py), so what each of
 * them RECEIVED is read the same way for all three. The bet's own checks are
 * then: the fingerprint, the layout and the prompt are byte-identical across
 * the three; the three bodies carry the same prompt, brief, layout and seed,
 * and differ ONLY where the owner's ask differs (what kind is wanted; and a
 * live view is a different channel the owner chose before any machine
 * answered) — never by which machine it is; and what is stored is one look
 * per kind under the one fingerprint, and one session.
 *
 * Each machine has a store of its own — the same world, cloned at the same
 * instant — because a world keeps ONE look per fingerprint (scene_jobs is
 * UNIQUE(key, fingerprint)): the room is the key, not the machine that drew
 * it, which is the bet put as a schema.
 *
 * `--keep DIR` leaves the three stores and a manifest.json in DIR, for
 * forge/web/tests/bet-web-test.php, which renders all three in #look.
 *
 * SKIPS (a SKIP line, exit 0) when python3 or php -S cannot start here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../scene.php';
require_once __DIR__ . '/../stream.php';

$T0     = microtime(true);
$FAILED = 0;
$KEEP   = '';
foreach ($argv as $i => $a) if ($a === '--keep' && isset($argv[$i + 1])) $KEEP = (string)$argv[$i + 1];

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

function bet_rmtree(string $d): void
{
    foreach (glob($d . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $p) { is_dir($p) ? bet_rmtree($p) : @unlink($p); }
    @rmdir($d);
}

function bet_port(): int
{
    $s = @stream_socket_server('tcp://127.0.0.1:0');
    if (!$s) return 0;
    $p = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $p;
}

function bet_listening(int $port, float $within = 6.0): bool
{
    $until = microtime(true) + $within;
    while (microtime(true) < $until) {
        $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($c) { fclose($c); return true; }
        usleep(40000);
    }
    return false;
}

/** sha1 of a value as JSON — the one way a layout is compared here. */
function bet_sha(mixed $v): string
{
    return sha1((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** The top-level keys on which two request bodies differ, either way. */
function bet_diff(array $a, array $b): array
{
    $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
    $out  = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $a) || !array_key_exists($k, $b) || bet_sha($a[$k]) !== bet_sha($b[$k])) $out[] = $k;
    }
    sort($out);
    return $out;
}

/** A GET through the tee, as JSON. */
function bet_get(string $url): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : [];
}

/** One MJPEG part off an open multipart/x-mixed-replace stream, or '' when it ends. */
function bet_part($fp): string
{
    $len = -1;
    while (($line = fgets($fp)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '' && $len >= 0) break;
        if (stripos($line, 'Content-Length:') === 0) $len = (int)trim(substr($line, 15));
    }
    if ($len < 0) return '';
    $buf = '';
    while (strlen($buf) < $len && !feof($fp)) {
        $chunk = fread($fp, $len - strlen($buf));
        if ($chunk === false || $chunk === '') break;
        $buf .= $chunk;
    }
    fgets($fp);   // the CRLF after the part
    return $buf;
}

// ---------------------------------------------------------------------------
echo "# one room, one brief\n";
// ---------------------------------------------------------------------------

$root = $KEEP !== '' ? rtrim($KEEP, '/') : sys_get_temp_dir() . '/xeric-bet-test-' . getmypid();
if (is_dir($root) && $KEEP === '') bet_rmtree($root);
@mkdir($root, 0775, true);
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_SCENE_KIND', 'XERIC_SCENE_AUTH',
          'XERIC_IMAGE_BASE', 'XERIC_IMAGE_KEY'] as $k) putenv($k);

$T  = xeric_template_load(__DIR__ . '/../fixtures/milldale.json');
$TZ = new DateTimeZone((string)$T['user']['timezone']);
// The Bluebird furnished, so the layout has something to lay out: a case, a
// jukebox, the counter, the window and the door the walk-ins use.
foreach ($T['places'] as &$p) {
    if ($p['key'] === 'bluebird') $p['interior'] = ['the pie case', 'a jukebox', 'the long counter',
                                                     'the front window', 'the door'];
}
unset($p);
// The three owners' templates: the same world, each asking its own machine
// for what that machine is for. None of these words is in the brief.
$Tw = [
    'pano'   => $T,
    'splat'  => array_replace_recursive($T, ['media' => ['scene' => ['kinds' => ['splat', 'pano', 'image']]]]),
    'stream' => array_replace_recursive($T, ['media' => ['scene' => ['stream' => ['enabled' => true]]]]),
];

// A fixed world moment: Monday 07:10 at the Bluebird, Dot alone behind the
// counter. The clock is stopped there, so the reaper's own re-composition
// (it asks the world's clock, not ours) lands on the same minute.
$target = (new DateTimeImmutable('2026-11-02 07:10', $TZ))->getTimestamp();
$db0P   = $root . '/brief.sqlite';
foreach ([$db0P, $db0P . '-wal', $db0P . '-shm'] as $f) @unlink($f);
$db0 = xeric_state_open($db0P);
xeric_clock_begin($db0, $T);
$real = time();
xeric_clock_pause($db0, $real);
xeric_clock_offset_set($db0, $target - $real);
xeric_player_move($db0, 'bluebird', $target - 600);

$B      = xeric_scene_brief($T, $db0, null);
$FP     = (string)$B['fingerprint'];
$PROMPT = (string)$B['prompt'];
$LAYOUT = bet_sha($B['layout']);
$SEED   = (int)$B['seeds']['place']['interior'];
$BJSON  = (string)json_encode($B, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$things = array_values(array_filter($B['layout']['objects'], fn($o) => $o['kind'] === 'thing'));
ok('the brief is the Bluebird at 07:10 on a Monday, Dot at the counter, the room furnished',
    $B['key'] === 'bluebird' && $B['now']['hhmm'] === '07:10' && array_column($B['who'], 'handle') === ['dot']
    && count($things) === 5, $B['now']['hhmm'] . ' ' . json_encode(array_column($B['who'], 'handle')));
echo "# fingerprint $FP\n# layout sha1 $LAYOUT (" . count($B['layout']['objects']) . " boxes)\n# prompt sha1 " . sha1($PROMPT) . "\n";

// One world, three machines: a store each, cloned from this one at this instant.
$db = [];
foreach (array_keys($Tw) as $k) {
    @mkdir($root . '/' . $k, 0775, true);
    $path = $root . '/' . $k . '/state.sqlite';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $db0->exec('VACUUM INTO ' . $db0->quote($path));
    $db[$k] = xeric_state_open($path);
}
$same = [];
foreach ($Tw as $k => $tk) {
    $same[$k] = json_encode(xeric_scene_brief($tk, $db[$k], null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $BJSON;
}
ok('each owner\'s template and store composes that same brief, byte for byte (what they ask a machine for is not in it)',
    $same === ['pano' => true, 'splat' => true, 'stream' => true], json_encode($same));

// ---------------------------------------------------------------------------
echo "\n# three machines on loopback\n";
// ---------------------------------------------------------------------------

$proxies = dirname(__DIR__, 2) . '/tools/proxies';
$py = trim((string)@shell_exec('command -v python3 2>/dev/null'));
$procs = [];
$spawn = function (array $cmd, string $log) use (&$procs) {
    $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (is_resource($p)) $procs[] = $p;
    return $p;
};
$stop = function () use (&$procs): void {
    foreach ($procs as $p) { @proc_terminate($p); }
    foreach ($procs as $p) { @proc_close($p); }
    $procs = [];
};

// The splat machine: a generic machine that keeps its own note of what it
// was asked, and answers a look that wants a splat with three of them.
$sm = $root . '/splat-machine';
@mkdir($sm, 0775, true);
file_put_contents($sm . '/router.php', <<<'ROUTER'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string)file_get_contents('php://input');
header('Content-Type: application/json');
if ($path === '/') { echo '{"machine":"splat","makes":["splat"]}'; return; }
if ($path === '/v1/scenes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $j = json_decode($body, true) ?: [];
    file_put_contents(__DIR__ . '/asked.jsonl', json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    if (!in_array('splat', (array)($j['kind_wanted'] ?? []), true)) {
        http_response_code(400); echo '{"error":"this machine makes only splat"}'; return;
    }
    $three = '';
    foreach ([[0, 1.2, 1.5], [1, 1.0, 2.0], [-1, 0.8, 2.5]] as $p) {
        $three .= pack('g6', ...$p, ...[0.2, 0.2, 0.2]) . pack('C8', 200, 120, 60, 255, 255, 128, 128, 128);
    }
    echo json_encode(['kind' => 'splat', 'mime' => 'application/octet-stream', 'b64' => base64_encode($three),
                      'meta' => ['vendor' => 'bet-splat', 'seed_used' => $j['seed'] ?? null]]);
    return;
}
http_response_code(404); echo '{"error":"not found"}';
ROUTER);

$port = ['up' => bet_port(), 'pano' => bet_port(), 'splat' => bet_port(), 'stream' => bet_port()];
$tee  = ['pano' => bet_port(), 'splat' => bet_port(), 'stream' => bet_port()];
$seenLog = $root . '/seen.jsonl';
@unlink($seenLog);
$live = $py !== '' && !in_array(0, $port, true) && !in_array(0, $tee, true);
if ($live) {
    $spawn([$py, '-I', $proxies . '/fake_upstream.py', '--port', (string)$port['up']], $root . '/up.log');
    $spawn([$py, '-I', $proxies . '/images.py', '--port', (string)$port['pano'],
            '--upstream', 'http://127.0.0.1:' . $port['up'], '--pano'], $root . '/images.log');
    $spawn([PHP_BINARY, '-S', '127.0.0.1:' . $port['splat'], $sm . '/router.php'], $root . '/splat.log');
    $spawn([$py, '-I', $proxies . '/worldmodel.py', '--port', (string)$port['stream'], '--fps', '10'], $root . '/worldmodel.log');
    $routes = [];
    foreach ($tee as $k => $tp) { $routes[] = '--route'; $routes[] = $tp . '=http://127.0.0.1:' . $port[$k]; }
    $spawn(array_merge([$py, '-I', $proxies . '/bet_harness.py', '--log', $seenLog], $routes), $root . '/tee.log');
    foreach (array_merge(array_values($port), array_values($tee)) as $p) $live = $live && bet_listening($p);
}
if (!$live) {
    $stop();
    echo "SKIP - bet-test: the three machines could not be started here (python3 " . ($py !== '' ? 'found' : 'missing')
       . ", or php -S / a loopback port refused); 0 of the machine checks ran\n\nskipped\n";
    if ($KEEP === '') bet_rmtree($root);
    exit(0);
}
$tStart = microtime(true);
$ep = [];
foreach ($tee as $k => $tp) $ep[$k] = ['base' => 'http://127.0.0.1:' . $tp, 'kind' => 'generic'];
ok('three machines answering on loopback, each the owner\'s own electricity',
    xeric_scene_up($ep['pano']) && xeric_scene_up($ep['splat']) && xeric_scene_up($ep['stream'])
    && !xeric_scene_costly($ep['pano']) && !xeric_scene_costly($ep['stream']));

// (a) the panorama — the queue, as the heart would drive it.
$scenes = fn(string $k): string => $root . '/' . $k . '/scenes';
xeric_scene_enqueue($db['pano'], $B);
$rp = xeric_scene_reap($Tw['pano'], $db['pano'], $scenes('pano'), $ep['pano'], 1, true);
$rowP = xeric_scene_of($db['pano'], 'bluebird', $FP);
ok('pano: the reaper asked images.py --pano and filed a panorama under the brief\'s fingerprint',
    $rp['done'] === 1 && $rowP !== null && $rowP['status'] === 'done' && $rowP['kind'] === 'pano'
    && is_file($scenes('pano') . '/' . $rowP['file']), json_encode($rp));

// (b) the splat — the same queue, a different machine, splat asked for first.
xeric_scene_enqueue($db['splat'], $B);
$rs = xeric_scene_reap($Tw['splat'], $db['splat'], $scenes('splat'), $ep['splat'], 1, true);
$rowS = xeric_scene_of($db['splat'], 'bluebird', $FP);
$plyS = $rowS !== null ? (string)@file_get_contents($scenes('splat') . '/' . $rowS['file']) : '';
ok('splat: the reaper asked the splat machine and filed three splats, kept as PLY, under the same fingerprint',
    $rs['done'] === 1 && $rowS !== null && $rowS['status'] === 'done' && $rowS['kind'] === 'splat'
    && str_ends_with((string)$rowS['file'], '.ply') && str_starts_with($plyS, "ply\nformat binary_little_endian 1.0\nelement vertex 3\n"),
    json_encode($rs));

// (c) the stream — go live, watch, and let the engine say something happened.
$st = xeric_stream_start($Tw['stream'], $db['stream'], $ep['stream'], ['scenes_dir' => $scenes('stream')]);
$sRow = $st['row'] ?? [];
ok('stream: go live started a worldmodel.py session, MJPEG, on the machine\'s own origin',
    $st['ok'] && ($sRow['protocol'] ?? '') === 'mjpeg'
    && xeric_stream_origin((string)($sRow['url'] ?? '')) === xeric_stream_machine_origin($ep['stream']), $st['why']);

$parts = [];
$after = [];
$sock  = null;
if ($st['ok']) {
    $u = parse_url((string)$sRow['url']);
    $sock = @fsockopen('127.0.0.1', (int)$u['port'], $en, $es, 2);
    if ($sock) {
        stream_set_timeout($sock, 5);
        fwrite($sock, 'GET ' . $u['path'] . " HTTP/1.0\r\nHost: 127.0.0.1:" . $u['port'] . "\r\n\r\n");
        $head = '';
        while (($l = fgets($sock)) !== false && rtrim($l, "\r\n") !== '') $head .= $l;
        for ($i = 0; $i < 3; $i++) $parts[] = bet_part($sock);
    }
}
ok('stream: three parts read off the session url, each a JPEG, and a room nobody changes holds still',
    count($parts) === 3 && count(array_filter($parts, fn($j) => str_starts_with($j, "\xFF\xD8\xFF") && str_ends_with($j, "\xFF\xD9"))) === 3
    && count(array_unique(array_map('sha1', $parts))) === 1, implode(',', array_map('strlen', $parts)));

// Somebody walks in: Ruth, at the counter on Mondays from seven (the
// template's week — the engine's world, which the picture then follows).
$Tarr = $Tw['stream'];
foreach ($Tarr['cast']['characters'] as &$c) {
    if ($c['handle'] === 'ruth') $c['week'][] = ['days' => [1], 'from' => '07:00', 'to' => '08:00',
                                                 'where' => 'bluebird', 'doing' => 'counter, one egg'];
}
unset($c);
$now  = xeric_clock_now($db['stream'], $Tarr);
$bArr = xeric_scene_brief($Tarr, $db['stream'], 'bluebird', $now);
$cond = json_decode((string)($sRow['cond'] ?? '{}'), true);
$comp = xeric_stream_compose($Tarr, $db['stream'], is_array($cond) ? $cond : [], $bArr, $now);
ok('stream: xeric_stream_compose says Ruth came in — an arrival, from observables, the rating clause last',
    array_column($comp['events'], 'type') === ['arrival'] && $comp['end'] === ''
    && str_ends_with((string)($comp['events'][0]['text'] ?? ''), xeric_stream_rating_clause($bArr) . '.'),
    json_encode($comp['events']));
$beat = xeric_stream_beat($Tarr, $db['stream'], $ep['stream'], (int)($sRow['id'] ?? 0));
ok('stream: the heartbeat sent exactly that event, and the view stays live',
    $beat['live'] && bet_sha($beat['events']) === bet_sha($comp['events']), json_encode($beat));
$changed = false;
if ($sock) {
    for ($i = 0; $i < 40 && !$changed; $i++) {
        $f = bet_part($sock);
        if ($f === '') break;
        $after[] = $f;
        $changed = sha1($f) !== sha1($parts[2] ?? '');
    }
    fclose($sock);
}
ok('stream: and the next frame is a different frame — the machine drew what the engine said', $changed,
    count($after) . ' parts after the event');
$sid  = (string)($sRow['session'] ?? '');
$wm   = bet_get($ep['stream']['base'] . '/v1/scenes/' . rawurlencode($sid));
ok('stream: worldmodel says it drew the envelope\'s own layout (every box but you), from the brief\'s seed, and heard one event',
    ($wm['meta']['layout_used'] ?? null) === true && ($wm['meta']['seed_used'] ?? null) === $SEED
    && ($wm['meta']['live']['boxes'] ?? -1) === count($B['layout']['objects']) - 1 + 1   // + the one who walked in
    && ($wm['meta']['live']['events'] ?? 0) === 1, json_encode($wm['meta'] ?? []));

// ---------------------------------------------------------------------------
echo "\n# the bet: one brief, three machines, no branch on which\n";
// ---------------------------------------------------------------------------

$seen = [];
foreach (@file($seenLog, FILE_IGNORE_NEW_LINES) ?: [] as $l) $seen[] = json_decode($l, true);
$byPort = array_flip($tee);
$posts  = [];
foreach ($seen as $s) {
    if ($s['method'] === 'POST' && $s['path'] === '/v1/scenes') $posts[$byPort[$s['port']]][] = (array)$s['body'];
}
$bodyP = $posts['pano'][0] ?? [];
$bodyS = $posts['splat'][0] ?? [];
$bodyL = $posts['stream'][0] ?? [];
ok('each machine was asked exactly once', count($posts['pano'] ?? []) === 1 && count($posts['splat'] ?? []) === 1
    && count($posts['stream'] ?? []) === 1, json_encode(array_map('count', $posts)));
$all = ['pano' => $bodyP, 'splat' => $bodyS, 'stream' => $bodyL];
$by  = fn(callable $f): array => array_map($f, $all);
ok('the three received the same prompt — the brief\'s, byte for byte',
    $by(fn($b) => ($b['prompt'] ?? null) === $PROMPT) === ['pano' => true, 'splat' => true, 'stream' => true]);
ok('the same layout (sha1 of its JSON)', $by(fn($b) => isset($b['layout']) ? bet_sha($b['layout']) : '') ===
    ['pano' => $LAYOUT, 'splat' => $LAYOUT, 'stream' => $LAYOUT]);
ok('the same seed, the same fingerprint, the same structured brief',
    $by(fn($b) => [($b['seed'] ?? null) === $SEED, ($b['fingerprint'] ?? null) === $FP,
                   bet_sha($b['brief'] ?? null) === bet_sha(array_diff_key($B, ['layout' => true]))])
    === ['pano' => [true, true, true], 'splat' => [true, true, true], 'stream' => [true, true, true]]);
$dPS = bet_diff($bodyP, $bodyS);
$dPL = bet_diff($bodyP, $bodyL);
echo "# pano vs splat differ on: " . implode(', ', $dPS) . "\n";
echo "# pano vs stream differ on: " . implode(', ', $dPL) . "\n";
ok('pano and splat bodies differ ONLY in kind_wanted — the owner\'s preference, read from the template',
    $dPS === ['kind_wanted'] && $bodyP['kind_wanted'] === ['pano', 'image'] && $bodyS['kind_wanted'] === ['splat', 'pano', 'image'],
    json_encode($dPS));
$liveOnly = ['bearings', 'channel', 'events', 'kind_wanted', 'max_seconds', 'protocols', 'size', 'timeout_s'];
ok('the stream body differs only where "go live" is a different ask: channel, kind_wanted, protocols and the session\'s terms '
   . '— no size (a session has none), its own 30 s call, the bearings flat (from the same brief)',
    $dPL === $liveOnly && ($bodyL['channel'] ?? '') === 'stream' && !isset($bodyL['size'])
    && ($bodyL['timeout_s'] ?? 0) === 30 && ($bodyP['timeout_s'] ?? 0) === 300
    && bet_sha($bodyL['bearings'] ?? null) === bet_sha(xeric_stream_bearings($B))
    && ($bodyL['protocols'] ?? []) === XERIC_STREAM_PROTOCOLS, json_encode($dPL));
$stripped = array_map(fn($b) => array_diff_key($b, array_flip($liveOnly)), $all);
ok('with the ask taken out, the three bodies are one body — nothing in them says which machine they went to',
    count(array_unique(array_map('bet_sha', $stripped))) === 1);
$own = json_decode((string)(@file($sm . '/asked.jsonl', FILE_IGNORE_NEW_LINES)[0] ?? ''), true);
ok('the splat machine\'s own note of what it got agrees with the tee', is_array($own) && bet_sha($own) === bet_sha($bodyS));

// What the machines passed on, in their own dialects — a proxy's translation, not the engine's.
$up   = bet_get('http://127.0.0.1:' . $port['up'] . '/_seen');
$gen  = array_values(array_filter($up, fn($r) => ($r['path'] ?? '') === '/v1/images/generations'))[0]['body'] ?? [];
ok('images.py passed the prompt and seed on, its pano trigger words first (its LoRA\'s, not the engine\'s)',
    ($gen['prompt'] ?? '') === 'equirectangular 360 degree panorama, ' . $PROMPT && ($gen['seed'] ?? null) === $SEED,
    json_encode(array_keys($gen)));
$events = array_values(array_filter($seen, fn($s) => $s['method'] === 'POST' && str_ends_with($s['path'], '/events')));
ok('the only thing said to the stream while live was the engine\'s arrival, {type, text, at}',
    count($events) === 1 && ($events[0]['body']['type'] ?? '') === 'arrival'
    && array_keys((array)$events[0]['body']) === ['type', 'text', 'at']);

// What is kept.
$jobs = fn(PDO $d): array => $d->query('SELECT key, fingerprint, channel, kind, prompt FROM scene_jobs')->fetchAll(PDO::FETCH_ASSOC);
$jp = $jobs($db['pano']);
$js = $jobs($db['splat']);
$jl = $jobs($db['stream']);
$ss = $db['stream']->query('SELECT * FROM scene_streams')->fetchAll(PDO::FETCH_ASSOC);
$sc = json_decode((string)($ss[0]['cond'] ?? '{}'), true);
ok('stored: one look per kind under the one fingerprint and the one prompt, and one live session conditioned on it',
    count($jp) === 1 && $jp[0]['kind'] === 'pano' && $jp[0]['fingerprint'] === $FP && $jp[0]['prompt'] === $PROMPT
    && count($js) === 1 && $js[0]['kind'] === 'splat' && $js[0]['fingerprint'] === $FP && $js[0]['prompt'] === $PROMPT
    && $jl === [] && count($ss) === 1 && $ss[0]['key'] === 'bluebird' && ($sc['fingerprint'] ?? '') === $FP
    && $ss[0]['status'] === 'live', json_encode([$jp, $js, count($ss)]));
ok('and the session row says it was given the layout', (json_decode((string)($ss[0]['meta'] ?? '{}'), true)['layout'] ?? null) === true);
$fpNow = array_map(fn($k) => xeric_scene_brief($Tw[$k], $db[$k], null)['fingerprint'], array_keys($Tw));
ok('after all three machines, every store still composes the one fingerprint (the arrival was a template\'s, not a store\'s)',
    $fpNow === [$FP, $FP, $FP]);

$took = microtime(true) - $T0;
echo "# machines: " . sprintf('%.1f', microtime(true) - $tStart) . " s; whole test: " . sprintf('%.1f', $took) . " s\n";
ok('under thirty seconds end to end', $took < 30, sprintf('%.1f s', $took));

if ($KEEP !== '') {
    file_put_contents($root . '/manifest.json', json_encode([
        'fingerprint' => $FP, 'layout_sha1' => $LAYOUT, 'prompt' => $PROMPT,
        'who' => array_map(fn($w) => ['handle' => $w['handle'], 'name' => $w['name'], 'bearing' => $w['bearing']], $B['who']),
        'stores' => array_map(fn($k) => $root . '/' . $k . '/state.sqlite', array_combine(array_keys($Tw), array_keys($Tw))),
        'interior' => array_map(fn($i) => $i['text'], $B['interior']),
        'target' => $target,
        'templates' => $Tw,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
} else {
    xeric_stream_end($db['stream'], $ep['stream'], (int)($sRow['id'] ?? 0), 'the test is over');
}
$stop();
$db = [];
$db0 = null;
if ($KEEP === '') bet_rmtree($root);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
