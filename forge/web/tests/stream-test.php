<?php
/**
 * Xeric — the live view's web half. `php forge/web/tests/stream-test.php`,
 * exit 0 on pass.
 *
 * engine/tests/scene-test.php proves the session, the events and the ends;
 * this proves what a browser is handed and what the endpoint lets through:
 *
 *   1. OFF BY DEFAULT: no "go live" without the template saying so, and a
 *      page that cannot go live has exactly the policy it always had.
 *   2. THE CSP NAMES ONE REMOTE ORIGIN, the scene machine's, on a page that
 *      can show a live view, and none on any other (a real server's headers).
 *   3. THE VIEW: <video> for HLS, the WHEP client's <video> for WebRTC, a
 *      picture for MJPEG, and the stored room with "the live view ended:"
 *      when it is over.
 *   4. THE BEAT METERS: seconds into the world's ledger as kind `stream`,
 *      the owner's only, a POST only, refused cross-site, ended at the ceiling.
 *   5. THE WHEP CLIENT, run in Node against a fake RTCPeerConnection; and the
 *      play view's stream mount against a fake DOM (HLS unplayable → MJPEG
 *      fallback, or an honest end).
 *
 * Node for 5 (SKIP without it, the viewer test's rule).
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-stream-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_SCENE_KIND', 'XERIC_SCENE_AUTH',
          'XERIC_IMAGE_BASE', 'XERIC_IMAGE_KEY'] as $k) putenv($k);

require_once __DIR__ . '/../play-lib.php';
require_once __DIR__ . '/../api-lib.php';

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

$fixT = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$J = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
$sid = xeric_web_sid();                               // solo: the server keys this machine to the same id
xeric_session_use($sid);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

/** A world in the bluebird with a stored panorama; $live turns the live view on. */
$world = function (string $slug, bool $live) use ($fixT, $J, $sid, $png): array {
    $t = $fixT;
    if ($live) $t['media']['scene']['stream'] = ['enabled' => true];
    $d = xeric_web_worlds_dir() . '/' . $slug;
    @mkdir($d, 0775, true);
    file_put_contents($d . '/world-template.json', json_encode($t, $J));
    file_put_contents($d . '/seed.json', json_encode(xeric_forge_default_seed($t), $J));
    xeric_session_claim($slug, $sid);
    $w = xeric_play_open($slug, null, true);
    xeric_player_move($w['db'], 'bluebird', xeric_clock_epoch($w['db']));
    $b = xeric_scene_brief($w['template'], $w['db']);
    $dir = dirname((string)$w['db_path']) . '/scenes';
    @mkdir($dir, 0775, true);
    $file = 'scene-bluebird-' . substr($b['fingerprint'], 0, 12) . '.png';
    file_put_contents($dir . '/' . $file, $png);
    $w['db']->prepare("INSERT INTO scene_jobs (key, fingerprint, status, tries, kind, file, mime, prompt, meta, created_at, done_at)
                       VALUES ('bluebird', ?, 'done', 1, 'pano', ?, 'image/png', '', '{}', ?, ?)")
           ->execute([$b['fingerprint'], $file, time(), time()]);
    return $w;
};

echo "# off by default\n";

$still = $world('still-town', false);
$live  = $world('live-town', true);
$hls = ['id' => 's-hls', 'url' => 'https://scenes.example:8443/live/s-hls/index.m3u8', 'protocol' => 'hls',
        'fallback' => ['protocol' => 'mjpeg', 'url' => 'https://scenes.example:8443/live/s-hls.mjpg']];
$session = $hls;
$stubCalls = [];
xeric_scene_source(function () use (&$session, &$stubCalls) {
    return ['base' => 'https://scenes.example:8443', 'stub' => function (string $tag, $b, $o) use (&$session, &$stubCalls) {
        $stubCalls[] = $tag;
        return $tag === 'stream' ? ['kind' => 'stream', 'session' => $session] : [];
    }];
});
$hs = xeric_play_look_html($still['template'], $still['db'], null, 'still-town', true);
ok('a world that never said stream.enabled has no go live button', str_contains($hs, 'lookpano') && !str_contains($hs, 'data-golive'), $hs);
ok('and its page names no remote origin', xeric_play_stream_origin($still['template'], $still['db']) === '');
ok('and its look says nothing can be offered', xeric_play_look_view($still['template'], $still['db'], 'still-town', null, false)['stream']['offer'] === false);
$hl = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
ok('a world that did gets "go live" on its panorama, metered by the second', str_contains($hl, 'data-golive')
    && str_contains($hl, 'metered by the second'), $hl);
ok('a guest gets none', !str_contains(xeric_play_look_html($live['template'], $live['db'], null, 'live-town', false), 'data-golive'));
ok('the page that can go live names exactly the machine\'s origin', xeric_play_stream_origin($live['template'], $live['db']) === 'https://scenes.example:8443');

echo "\n# the view, live\n";

$r = xeric_stream_start($live['template'], $live['db'], xeric_scene_endpoint(),
                        ['scenes_dir' => xeric_play_scenes_dir($live['db'])]);
ok('go live starts a session', $r['ok'] && $stubCalls === ['stream'], $r['why']);
$h = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
ok('HLS is a <video> on the session url, in the live box, with the stored room as its poster',
    str_contains($h, 'class="lookmain lookstreambox"') && str_contains($h, '<video class="lookstream"')
    && str_contains($h, 'src="https://scenes.example:8443/live/s-hls/index.m3u8"') && str_contains($h, 'data-protocol="hls"')
    && str_contains($h, 'data-fallback="https://scenes.example:8443/live/s-hls.mjpg"') && str_contains($h, 'data-poster="scene.php?')
    && !str_contains($h, "lookpano") && !str_contains($h, "data-golive") && str_contains($h, "data-liveend"), json_encode([str_contains($h, "lookpano"), str_contains($h, "data-golive"), str_contains($h, "data-liveend"), str_contains($h, "data-poster=\"scene.php?")]));
ok('the repaint is stable while live (nothing ticks in the markup)',
    $h === xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true));
$v = xeric_play_look_view($live['template'], $live['db'], 'live-town', null, false);
ok('the JSON a client reads carries the session', ($v['stream']['live']['protocol'] ?? '') === 'hls'
    && ($v['stream']['live']['beat'] ?? '') === 'stream.php' && ($v['stream']['live']['beat_s'] ?? 0) === 10);
$api = xeric_api_shape_look($v, 'live-town');
ok('and the API passes it through untouched', ($api['stream']['live']['url'] ?? '') === $hls['url']);
$liveRow = xeric_stream_live($live['db']);
xeric_stream_end($live['db'], null, (int)$liveRow['id'], 'you left the Bluebird Diner');
$h = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
ok('when it ends, the stored panorama is back with a line saying why',
    str_contains($h, 'lookpano') && str_contains($h, 'the live view ended: you left the Bluebird Diner') && !str_contains($h, '<video'), $h);
ok('and go live is offered again', str_contains($h, 'data-golive'));

$session = ['id' => 's-rtc', 'url' => 'https://scenes.example:8443/whep/s-rtc', 'protocol' => 'webrtc-whep'];
xeric_stream_start($live['template'], $live['db'], xeric_scene_endpoint());
$h = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
ok('WebRTC is a <video> for the WHEP client: the url as data-whep, no src',
    str_contains($h, '<video class="lookstream" playsinline muted autoplay data-whep="https://scenes.example:8443/whep/s-rtc">')
    && !str_contains($h, ' src="https://scenes.example:8443/whep'), $h);
xeric_stream_end($live['db'], null, (int)xeric_stream_live($live['db'])['id'], 'you ended it');
$session = ['id' => 's-mj', 'url' => 'https://scenes.example:8443/mj/s-mj.mjpg', 'protocol' => 'mjpeg'];
xeric_stream_start($live['template'], $live['db'], xeric_scene_endpoint());
$h = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
ok('MJPEG is a picture that keeps arriving', str_contains($h, '<img class="lookstreamimg"') && !str_contains($h, '<video'), $h);
xeric_stream_end($live['db'], null, (int)xeric_stream_live($live['db'])['id'], 'you ended it');
xeric_scene_source(fn() => null);

echo "\n# the policy, as a real server sends it\n";

// One machine for the server: a generic one, on loopback, never answering.
$port = 0;
for ($try = 9131; $try < 9199; $try++) {
    $s = @fsockopen('127.0.0.1', $try, $e1, $e2, 0.2);
    if ($s === false) { $port = $try; break; }
    fclose($s);
}
$env = 'XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
     . ' XERIC_LOCAL_BASE=http://127.0.0.1:1 XERIC_SCENE_BASE=http://127.0.0.1:9 XERIC_PORT=' . $port
     . ' PHP_CLI_SERVER_WORKERS=2';
$pid = (int)trim((string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($WEB) . ' ' . escapeshellarg($WEB . '/router.php')
    . ' > ' . escapeshellarg($tmp . '/server.log') . ' 2>&1 & echo $!'));
$up = false;
for ($i = 0; $i < 60 && !$up; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s !== false) { fclose($s); $up = true; } else usleep(150000);
}
ok('the server answers', $up, (string)@file_get_contents($tmp . '/server.log'));
/** [status, headers, body] */
$req = function (string $path, ?array $json = null, array $hdr = []) use ($port): array {
    $h = "Host: 127.0.0.1:$port\r\n" . implode('', array_map(fn($x) => $x . "\r\n", $hdr));
    $o = ['ignore_errors' => true, 'timeout' => 20, 'header' => $h];
    if ($json !== null) { $o['method'] = 'POST'; $o['header'] .= "Content-Type: application/json\r\n"; $o['content'] = json_encode($json); }
    $body = (string)@file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http' => $o]));
    $hs = []; $st = 0;
    foreach ($http_response_header ?? [] as $l) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $l, $m)) { $st = (int)$m[1]; $hs = []; continue; }
        $p = strpos($l, ':');
        if ($p !== false) $hs[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
    }
    return [$st, $hs, $body];
};
$noNonce = fn(string $c): string => (string)preg_replace("/'nonce-[^']+'/", "'nonce-x'", $c);
[$s1, $h1, $b1] = $req('/play.php?w=still-town');
[$s2, $h2, $b2] = $req('/play.php?w=live-town');
$c1 = $noNonce($h1['content-security-policy'] ?? '');
$c2 = $noNonce($h2['content-security-policy'] ?? '');
ok('both play pages render', $s1 === 200 && $s2 === 200 && str_contains($b2, 'data-golive'), $s1 . ' ' . $s2);
ok('a page that cannot go live: img, media and connect are self and nothing remote',
    str_contains($c1, "img-src 'self' data:;") && str_contains($c1, "media-src 'self';") && str_contains($c1, "connect-src 'self';")
    && !str_contains($c1, 'http://'), $c1);
ok('a page that can: those three gain the machine\'s own origin, and the policy differs by nothing else',
    str_replace(' http://127.0.0.1:9', '', $c2) === $c1 && substr_count($c2, 'http://127.0.0.1:9') === 3
    && str_contains($c2, "media-src 'self' http://127.0.0.1:9;") && str_contains($c2, "connect-src 'self' http://127.0.0.1:9;"), $c2);
[, $h3] = $req('/stand.php?w=live-town');
ok('the stand shell names the same one origin, and no other', substr_count($h3['content-security-policy'] ?? '', 'http://127.0.0.1:9') === 3
    && !preg_match('#https?://(?!127\.0\.0\.1:9)#', $h3['content-security-policy'] ?? ''), $h3['content-security-policy'] ?? '');
ok('the play page mounts the live view and wires go live',
    str_contains($b2, "XV.mount({ kind: 'stream', box: live") && str_contains($b2, "fetch('stream.php'"));

echo "\n# the beat\n";

[$sg] = $req('/stream.php?w=live-town&a=beat');
ok('a GET never spends: 405', $sg === 405);
[$ss, , $sb] = $req('/stream.php', ['w' => 'live-town', 'a' => 'start']);
ok('go live with no machine answering says so, and keeps nothing', $ss === 409 && str_contains($sb, 'no scene machine is answering')
    && xeric_stream_live($live['db']) === null, $ss . ' ' . $sb);
// A session as a start would leave it, ten seconds into its life.
$mk = function (PDO $db, int $ago) {
    xeric_stream_schema($db);
    $db->prepare("INSERT INTO scene_streams (key, machine, session, url, protocol, started_at, last_beat, ends_at)
                  VALUES ('bluebird', 'http://127.0.0.1:9', 'sx', 'http://127.0.0.1:9/x.m3u8', 'hls', ?, ?, ?)")
       ->execute([time() - $ago, time() - $ago, time() + 600]);
    return (int)$db->lastInsertId();
};
$id = $mk($live['db'], 10);
[$sb1, , $bb1] = $req('/stream.php', ['w' => 'live-town', 'a' => 'beat', 'id' => $id]);
$j1 = json_decode($bb1, true);
$now = xeric_clock_now($live['db'], $live['template']);
$spent = xeric_budget_spent($live['db'], $now);
ok('the owner\'s beat counts the seconds since the last', $sb1 === 200 && ($j1['live'] ?? null) === true
    && in_array($j1['counted'] ?? 0, [10, 11], true), $bb1);
ok('and they land in the world\'s ledger as kind stream', in_array($spent['stream'], [10, 11], true), json_encode($spent));
[$sx, , $bx] = $req('/stream.php', ['w' => 'live-town', 'a' => 'beat', 'id' => $id], ['Sec-Fetch-Site: cross-site']);
ok('a beat from another site is refused, and counts nothing', $sx === 403
    && xeric_budget_spent($live['db'], $now)['stream'] === $spent['stream'], $sx . ' ' . $bx);
[$sn, , $bn] = $req('/stream.php', ['w' => 'live-town', 'a' => 'beat', 'id' => 99999]);
ok('a beat for no such view is a 404', $sn === 404, $bn);
[$se, , $be] = $req('/stream.php', ['w' => 'live-town', 'a' => 'end', 'id' => $id, 'why' => 'hidden']);
ok('the page may end it, with one of its own few words', $se === 200 && str_contains($be, 'hidden for more than 30 seconds')
    && xeric_stream_get($live['db'], $id)['status'] === 'ended', $be);
[$se2, , $be2] = $req('/stream.php', ['w' => 'live-town', 'a' => 'end', 'id' => $id, 'why' => 'a dragon ate it']);
ok('and never write its own sentence into the row', !str_contains($be2, 'dragon') && str_contains($be2, 'hidden'), $be2);

// The ceiling: ten seconds a day, on the owner's own machine (metered_only false).
$tB = $live['template'];
$tB['budget'] = ['stream_seconds_per_day' => 12, 'metered_only' => false];
file_put_contents(xeric_web_worlds_dir() . '/live-town/world-template.json', json_encode($tB, $J));
$id2 = $mk($live['db'], 10);
[, , $bc] = $req('/stream.php', ['w' => 'live-town', 'a' => 'beat', 'id' => $id2]);
$jc = json_decode($bc, true);
ok('a beat past the world\'s stream seconds ends the view, naming the ceiling', ($jc['live'] ?? null) === false
    && str_contains((string)($jc['reason'] ?? ''), 'seconds of live view a day'), $bc);

// The guest: in on a code, on the owner's db.
$G = bin2hex(random_bytes(16));
xeric_session_use($G);
xeric_session_join('live-town', 2, $G);
xeric_session_use($sid);
$id3 = $mk($live['db'], 10);
$guest = $tmp . '/guest.php';
file_put_contents($guest, '<?php' . "\n"
    . '$_SERVER["REQUEST_METHOD"] = "POST";' . "\n"
    . 'require ' . var_export($WEB . '/boot.php', true) . ';' . "\n"
    . 'xeric_session_use(' . var_export($G, true) . ');' . "\n"
    . 'xeric_web_input_set(["w" => "live-town", "a" => "beat", "id" => ' . $id3 . ']);' . "\n"
    . 'register_shutdown_function(function () { echo "\nSTATUS " . http_response_code(); });' . "\n"
    . 'chdir(' . var_export($WEB, true) . ');' . "\n"
    . 'require ' . var_export($WEB . '/stream.php', true) . ';' . "\n");
$go = (string)shell_exec('XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
    . ' XERIC_LOCAL_BASE=http://127.0.0.1:1 XERIC_MODE=demo ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($guest) . ' 2>&1');
ok('a guest\'s beat is refused: the live view is the owner\'s', str_contains($go, '"kind":"guest"') && str_contains($go, 'STATUS 403')
    && (int)xeric_stream_get($live['db'], $id3)['seconds'] === 0, $go);
if ($pid > 0) @exec('kill ' . $pid . ' 2>/dev/null');

echo "\n# the client, run\n";

$node = (string)getenv('XERIC_NODE');
if ($node === '' || !is_executable($node)) $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '' && getenv('XERIC_REQUIRE_NODE') === '1') ok('node is here (XERIC_REQUIRE_NODE=1)', false);
if ($node === '') {
    echo "SKIP - the WHEP client and the stream mount (no node)\n";
} else {
    $js = $tmp . '/stream-harness.js';
    file_put_contents($js, <<<'JS'
const fs = require('fs');
const out = [];
const ok = (n, c, d) => out.push([n, !!c, d === undefined ? '' : String(d)]);
const tick = () => new Promise((r) => setTimeout(r, 5));
// -- the smallest DOM the stream mount touches --
class El {
  constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.parentNode = null; this.attrs = {};
    this.dataset = {}; this.className = ''; this.textContent = ''; this.srcObject = null; this.paused = true; }
  setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'src') this.src = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  removeAttribute(k) { delete this.attrs[k]; if (k === 'src') this.src = ''; }
  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
  replaceWith(n) { const p = this.parentNode; if (!p) return; const i = p.children.indexOf(this); p.children[i] = n; n.parentNode = p; this.parentNode = null; }
  remove() { const p = this.parentNode; if (p) p.children.splice(p.children.indexOf(this), 1); this.parentNode = null; }
  all() { return this.children.reduce((a, c) => a.concat([c], c.all()), []); }
  querySelector(sel) {
    return this.all().find((e) => sel.split(',').some((s) => { s = s.trim();
      const [t, c] = s.split('.'); return (!t || e.tagName === t.toUpperCase()) && (!c || e.className.split(' ').includes(c)); })) || null;
  }
  play() { this.paused = false; return Promise.resolve(); }
  pause() { this.paused = true; }
}
global.window = global;
global.document = { hidden: false, createElement: (t) => new El(t), addEventListener() {}, removeEventListener() {},
                    querySelector: () => null, querySelectorAll: () => [] };
const posts = [];
global.fetch = (url, init) => { posts.push([url, init]); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: 1, live: true }) }); };
eval(fs.readFileSync(process.argv[2], 'utf8'));
const XV = window.XericView({ world: 'live-town' });

// -- a fake RTCPeerConnection, recording what it was asked --
class FakePC {
  constructor(cfg) { FakePC.last = this; this.cfg = cfg; this.trans = []; this.local = null; this.remote = null;
    this.iceGatheringState = 'gathering'; this.closed = false; }
  addTransceiver(kind, o) { this.trans.push([kind, o.direction]); }
  createOffer() { return Promise.resolve({ type: 'offer', sdp: 'v=0 offer' }); }
  setLocalDescription(o) { this.local = o; this.localDescription = o;
    setTimeout(() => { this.iceGatheringState = 'complete'; if (this.onicegatheringstatechange) this.onicegatheringstatechange(); }, 1);
    return Promise.resolve(); }
  setRemoteDescription(a) { this.remote = a; return Promise.resolve(); }
  close() { this.closed = true; }
}
global.MediaStream = class { constructor() { this.tracks = []; } addTrack(t) { this.tracks.push(t); } };
(async () => {
  const calls = [];
  const F = (url, init) => { calls.push([url, init]); return Promise.resolve({ status: 201, ok: true,
    headers: { get: (h) => h === 'Location' ? '/whep/s-rtc/resource/1' : null }, text: () => Promise.resolve('v=0 answer') }); };
  const video = new El('video');
  const w = XV.whep('https://scenes.example:8443/whep/s-rtc', video, { PC: FakePC, fetch: F, iceWait: 50 });
  await w.ready;
  const pc = FakePC.last;
  ok('whep: receive-only, video and audio', JSON.stringify(pc.trans) === '[["video","recvonly"],["audio","recvonly"]]', JSON.stringify(pc.trans));
  ok('whep: the offer is POSTed as application/sdp to the session url, after ICE gathered, with no credentials',
    calls.length === 1 && calls[0][0] === 'https://scenes.example:8443/whep/s-rtc' && calls[0][1].method === 'POST'
    && calls[0][1].headers['Content-Type'] === 'application/sdp' && calls[0][1].body === 'v=0 offer'
    && calls[0][1].credentials === 'omit', JSON.stringify(calls));
  ok('whep: the answer is set as the remote description', pc.remote && pc.remote.type === 'answer' && pc.remote.sdp === 'v=0 answer');
  const track = { kind: 'video' };
  pc.ontrack({ track, streams: [] });
  ok('whep: the track lands on the video', video.srcObject && video.srcObject.tracks[0] === track && !video.paused);
  const ms = { id: 'given' };
  pc.ontrack({ track, streams: [ms] });
  ok('whep: or the stream the track came in', video.srcObject === ms);
  w.close();
  ok('whep: close ends the connection and DELETEs the resource it was given, on the same origin',
    pc.closed && calls.length === 2 && calls[1][0] === 'https://scenes.example:8443/whep/s-rtc/resource/1' && calls[1][1].method === 'DELETE',
    JSON.stringify(calls[1] || null));
  const bad = XV.whep('https://scenes.example:8443/whep/x', new El('video'), { PC: FakePC, iceWait: 5,
    fetch: () => Promise.resolve({ status: 400, ok: false, headers: { get: () => null }, text: () => Promise.resolve('') }) });
  let refused = false; await bad.ready.catch(() => { refused = true; });
  ok('whep: a machine that refuses the offer rejects, so the view can say so', refused);
  const nopc = XV.whep('https://x/whep', new El('video'), { PC: null, fetch: F });
  let none = false; await nopc.ready.catch(() => { none = true; });
  ok('whep: no WebRTC in this browser is a refusal, not a throw', none);

  // -- the play view's stream mount --
  const mkBox = (proto, url, fb) => {
    const page = new El('div');
    const box = page.appendChild(new El('div'));
    box.className = 'lookmain lookstreambox';
    Object.assign(box.dataset, { stream: '7', protocol: proto, url, fallback: fb || '', beat: 'stream.php', poster: 'scene.php?w=x' });
    const v = box.appendChild(new El('video')); v.className = 'lookstream';
    v.canPlayType = () => '';
    if (proto === 'hls') v.setAttribute('src', url);
    const st = box.appendChild(new El('p')); st.className = 'lookstreamstate';
    return { page, box, v };
  };
  posts.length = 0;
  let ended = null;
  let b = mkBox('hls', 'https://m.example/a.m3u8', 'https://m.example/a.mjpg');
  let s = XV.mount({ kind: 'stream', box: b.box, beatS: 0.02, onEnd: (r) => { ended = r; } });
  ok('stream: HLS on a browser that cannot play it falls back to the MJPEG the session offers',
    s.how === 'mjpeg' && b.box.querySelector('img.lookstreamimg') && b.box.querySelector('img.lookstreamimg').src === 'https://m.example/a.mjpg');
  await new Promise((r) => setTimeout(r, 60));
  const beats = posts.filter((p) => JSON.parse(p[1].body).a === 'beat');
  ok('stream: and it beats, POSTing {w, id, a: beat, hidden_for} to stream.php', beats.length >= 1 && beats[0][0] === 'stream.php'
    && beats[0][1].method === 'POST' && JSON.parse(beats[0][1].body).w === 'live-town' && JSON.parse(beats[0][1].body).id === 7
    && JSON.parse(beats[0][1].body).hidden_for === 0, JSON.stringify(beats[0] || null));
  const b2 = mkBox('hls', 'https://m.example/a.m3u8', 'https://m.example/a.mjpg');
  const same = XV.mount({ kind: 'stream', box: b2.box });
  ok('stream: a repaint of the same live view moves the element across rather than starting again',
    same === s && b2.box.querySelector('img.lookstreamimg') === s.media);
  global.fetch = (url, init) => { posts.push([url, init]);
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: 1, live: false, reason: 'you left the Bluebird Diner' }) }); };
  await new Promise((r) => setTimeout(r, 60));
  ok('stream: when the server says it is over, the stored room comes back with the reason',
    ended === 'you left the Bluebird Diner' && b2.page.children[0].className === 'lookmain lookstill'
    && b2.page.children[0].querySelector('p.lookliveend').textContent === 'the live view ended: you left the Bluebird Diner'
    && b2.page.children[0].querySelector('img.lookimg').src === 'scene.php?w=x', ended);
  XV.unmount();
  posts.length = 0; ended = null;
  const b3 = mkBox('hls', 'https://m.example/b.m3u8', '');
  XV.mount({ kind: 'stream', box: b3.box, onEnd: (r) => { ended = r; } });
  await tick();
  ok('stream: HLS with no fallback on such a browser ends at once and says plainly why',
    /does not play HLS by itself \(Safari, Vision Pro and Quest do\)/.test(ended || '')
    && posts.some((p) => JSON.parse(p[1].body).a === 'end'), ended);
  const b4 = mkBox('hls', 'https://m.example/c.m3u8', '');
  b4.v.canPlayType = (t) => t === 'application/vnd.apple.mpegurl' ? 'maybe' : '';
  const s4 = XV.mount({ kind: 'stream', box: b4.box });
  ok('stream: where HLS plays natively, the <video> plays the session itself', s4.how === 'video' && !b4.v.paused
    && b4.v.getAttribute('src') === 'https://m.example/c.m3u8');
  XV.unmount();
  global.RTCPeerConnection = FakePC;
  global.fetch = (url, init) => { posts.push([url, init]); return Promise.resolve({ status: 201, ok: true, headers: { get: () => null },
    text: () => Promise.resolve('v=0 answer'), json: () => Promise.resolve({ ok: 1, live: true }) }); };
  const b5 = mkBox('webrtc-whep', 'https://m.example/whep/d', '');
  const s5 = XV.mount({ kind: 'stream', box: b5.box });
  await new Promise((r) => setTimeout(r, 40));
  ok('stream: WebRTC goes through the WHEP client onto the box\'s video', s5.how === 'video' && FakePC.last.remote
    && FakePC.last.remote.sdp === 'v=0 answer');
  XV.unmount();
  ok('stream: and is closed when the view is thrown away', FakePC.last.closed);
  for (const r of out) console.log('R\t' + (r[1] ? 1 : 0) + '\t' + r[0] + '\t' + r[2].replace(/\s+/g, ' '));
  process.exit(0);
})().catch((e) => { console.log('R\t0\tthe harness ran\t' + e.stack); process.exit(0); });
JS);
    $res = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($js) . ' ' . escapeshellarg($WEB . '/xeric-view.js') . ' 2>&1');
    $n = 0;
    foreach (explode("\n", $res) as $l) {
        if (!str_starts_with($l, "R\t")) continue;
        $p = explode("\t", $l, 4);
        ok($p[2], $p[1] === '1', $p[3] ?? '');
        $n++;
    }
    ok('the harness reported', $n >= 15, $res);
}

rmtree($tmp);
echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
exit($FAILED === 0 ? 0 : 1);
