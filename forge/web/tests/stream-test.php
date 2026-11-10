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
          'XERIC_IMAGE_BASE', 'XERIC_IMAGE_KEY', 'XERIC_SOLO'] as $k) putenv($k);   // solo: the server is this machine

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
ok('HLS is an empty slot in the live box: the session in data attributes, the stored room as its poster',
    str_contains($h, 'class="lookmain lookstreambox"') && str_contains($h, '<div class="lookstreamslot"></div>')
    && str_contains($h, 'data-url="https://scenes.example:8443/live/s-hls/index.m3u8"') && str_contains($h, 'data-protocol="hls"')
    && str_contains($h, 'data-fallback="https://scenes.example:8443/live/s-hls.mjpg"') && str_contains($h, 'data-poster="scene.php?')
    && !str_contains($h, "lookpano") && !str_contains($h, "data-golive") && str_contains($h, "data-liveend")
    && str_contains($h, 'data-fov="90" data-proj="perspective"'), $h);
// A repaint while live must not open a second connection before the client
// moves the one it holds: no <video>, no <img> of the stream, no src to it.
$noSrc = fn(string $h): bool => !preg_match('#src="https://scenes\.example:8443#', $h) && !str_contains($h, '<video')
    && !str_contains($h, 'lookstreamimg');
ok('the HTML of a repaint while live holds no src to the stream, and no live element', $noSrc($h), $h);
ok('the repaint is stable while live (nothing ticks in the markup)',
    $h === xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true));
$v = xeric_play_look_view($live['template'], $live['db'], 'live-town', null, false);
ok('the JSON a client reads carries the session', ($v['stream']['live']['protocol'] ?? '') === 'hls'
    && ($v['stream']['live']['beat'] ?? '') === 'stream.php' && ($v['stream']['live']['beat_s'] ?? 0) === 10);
$api = xeric_api_shape_look($v, 'live-town');
ok('and the API passes it through untouched', ($api['stream']['live']['url'] ?? '') === $hls['url']);
$liveRow = xeric_stream_live($live['db']);
xeric_stream_end($live['db'], xeric_scene_endpoint(), (int)$liveRow['id'], 'you left the Bluebird Diner');
$h = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
ok('when it ends, the stored panorama is back with a line saying why',
    str_contains($h, 'lookpano') && str_contains($h, 'the live view ended: you left the Bluebird Diner') && !str_contains($h, '<video'), $h);
ok('and go live is offered again', str_contains($h, 'data-golive'));

$stubCalls = [];
$session = ['id' => 's-rtc', 'url' => 'https://scenes.example:8443/whep/s-rtc', 'protocol' => 'webrtc-whep'];
xeric_stream_start($live['template'], $live['db'], xeric_scene_endpoint());
$h = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
ok('WebRTC is the same empty slot: the url as data-url, no src, no element',
    str_contains($h, 'data-protocol="webrtc-whep"') && str_contains($h, 'data-url="https://scenes.example:8443/whep/s-rtc"')
    && $noSrc($h), $h);
xeric_stream_end($live['db'], xeric_scene_endpoint(), (int)xeric_stream_live($live['db'])['id'], 'you ended it');
$session = ['id' => 's-mj', 'url' => 'https://scenes.example:8443/mj/s-mj.mjpg', 'protocol' => 'mjpeg'];
xeric_stream_start($live['template'], $live['db'], xeric_scene_endpoint());
$h = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
ok('MJPEG too: the picture is the client\'s to make, once', str_contains($h, 'data-protocol="mjpeg"') && $noSrc($h), $h);
xeric_stream_end($live['db'], xeric_scene_endpoint(), (int)xeric_stream_live($live['db'])['id'], 'you ended it');
ok('every end told the machine on the session\'s row', $stubCalls === ['stream', 'end', 'stream', 'end'], json_encode($stubCalls));
xeric_scene_source(fn() => null);

echo "\n# the people over every view\n";

ok('a flat frame says how wide it sees: ninety unless the answer names its own, and a strip says so',
    xeric_play_look_fov_attrs([]) === ' data-fov="90" data-proj="perspective"'
    && xeric_play_look_fov_attrs(['fov' => 360, 'projection' => 'equirectangular']) === ' data-fov="360" data-proj="equirectangular"'
    && xeric_play_look_fov_attrs(['hfov' => '70.5']) === ' data-fov="70.5" data-proj="perspective"'
    && xeric_play_look_fov_attrs(['fov' => 'wide']) === ' data-fov="90" data-proj="perspective"');
$pb = xeric_scene_brief($live['template'], $live['db']);
$pl = json_decode(xeric_play_look_places($pb), true);
$ok = is_array($pl) && $pb['who'] !== [];
foreach ($pb['who'] as $p) {
    $q = $pl[(string)$p['handle']] ?? null;
    // In the splat's frame (-z ahead, x right): the bearing back out of it is the engine's own.
    $bear = $q === null ? null : (int)round(fmod(rad2deg(atan2($q[0], -$q[2])) + 360, 360));
    if ($q === null || abs((($bear - (int)$p['bearing'] + 540) % 360) - 180) > 1 || $q[1] >= 0) $ok = false;
}
ok('a splat is handed each person at their bearing from the layout, in its own frame, below the eye', $ok,
    json_encode([$pl, array_column($pb['who'], 'bearing', 'handle')]));

echo "\n# the sweep, off the read path\n";

// A view abandoned a minute ago on a real address nobody answers at. The
// sidebar ends it and meters it, and must not wait on that address to do so.
$net = [];
xeric_scene_http_seam(function (string $url, array $http) use (&$net) {
    $net[] = [(string)($http['method'] ?? 'GET'), $url, (int)($http['timeout'] ?? 0)];
    return ['', 204];
});
xeric_stream_schema($live['db']);
$live['db']->prepare("INSERT INTO scene_streams (key, machine, session, url, protocol, started_at, last_beat, ends_at)
                      VALUES ('bluebird', 'http://127.0.0.1:9', 'gone-1', 'http://127.0.0.1:9/gone-1.m3u8', 'hls', ?, ?, ?)")
           ->execute([time() - 70, time() - 60, time() - 60]);   // its end its last beat: no tail to meter into the ledger below
$goneId = (int)$live['db']->lastInsertId();
$hg = xeric_play_look_html($live['template'], $live['db'], null, 'live-town', true);
$gone = xeric_stream_get($live['db'], $goneId);
ok('the sidebar render ends a view nobody beats for, and makes no network call doing it',
    $net === [] && $gone['status'] === 'ended' && str_contains($hg, 'the page went away')
    && (json_decode((string)$gone['meta'], true)['tell_owed'] ?? null) === true, json_encode([$net, $gone]));
$pass = xeric_stream_pass($live['template'], $live['db'], null, 4);
ok('the heart\'s pass sends the DELETE it owed, to the address on the row, short',
    $pass['told'] === 1 && count($net) === 1 && $net[0][0] === 'DELETE' && $net[0][1] === 'http://127.0.0.1:9/v1/scenes/gone-1'
    && $net[0][2] === XERIC_STREAM_TELL_TIMEOUT
    && (json_decode((string)xeric_stream_get($live['db'], $goneId)['meta'], true)['end_confirmed'] ?? null) === true,
    json_encode([$pass, $net]));
ok('and only once', xeric_stream_pass($live['template'], $live['db'], null, 4)['told'] === 0 && count($net) === 1);
// Bounded per pass: five owed, four sent, the fifth next time.
foreach (range(1, 5) as $i) {
    $live['db']->prepare("INSERT INTO scene_streams (key, machine, session, url, protocol, started_at, last_beat, ends_at, status, ended_at, meta)
                          VALUES ('bluebird', 'http://127.0.0.1:9', ?, 'u', 'hls', ?, ?, ?, 'ended', ?, '{\"tell_owed\":true}')")
               ->execute(['owed-' . $i, time() - 99, time() - 99, time() + 600, time() - 90 + $i]);
}
$net = [];
ok('the pass is bounded: XERIC_STREAM_TELL_PER_TICK at most, then the rest next pass',
    xeric_stream_pass($live['template'], $live['db'], null)['told'] === XERIC_STREAM_TELL_PER_TICK
    && count($net) === XERIC_STREAM_TELL_PER_TICK && xeric_stream_pass($live['template'], $live['db'], null)['told'] === 1);
xeric_scene_http_seam(false);
$live['db']->exec("DELETE FROM scene_streams WHERE session = 'gone-1' OR session LIKE 'owed-%'");
$heartSrc  = (string)file_get_contents($WEB . '/heart.php');
$streamSrc = (string)file_get_contents($WEB . '/stream.php');
ok('the heart runs the pass for every world it opens, with one bound across the pass, before it asks for a model',
    str_contains($heartSrc, 'xeric_stream_pass(') && str_contains($heartSrc, '$tellLeft -= xeric_heart_streams(')
    && strpos($heartSrc, 'xeric_heart_streams($slug') < strpos($heartSrc, 'if (!$thinks) continue;'));
ok('and the owner\'s own beat and end send what is owed; the sidebar sends nothing',
    substr_count($streamSrc, '$owed();') === 2 && str_contains($streamSrc, 'xeric_stream_tell_owed(')
    && !str_contains((string)file_get_contents($WEB . '/play-view.php'), 'xeric_stream_tell_owed('));

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
// A session as a start would leave it, ten seconds into its life — INCLUDING
// what it was conditioned on. A row with the column's bare '{}' reads as a
// session begun in a shut, unlit room, so the first beat ended it ("the room
// has opened") at every hour the diner's door is open: the test passed or
// failed by the time of day it was run.
$mk = function (PDO $db, int $ago) use ($live) {
    xeric_stream_schema($db);
    $cond = xeric_stream_conditions((array)xeric_scene_brief($live['template'], $db, 'bluebird'));
    $db->prepare("INSERT INTO scene_streams (key, machine, session, url, protocol, started_at, last_beat, ends_at, cond)
                  VALUES ('bluebird', 'http://127.0.0.1:9', 'sx', 'http://127.0.0.1:9/x.m3u8', 'hls', ?, ?, ?, ?)")
       ->execute([time() - $ago, time() - $ago, time() + 600, json_encode($cond, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
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

// The page's codes, each the sentence the row keeps — a client-side end is
// never stored as "you ended it" unless the person pressed the button.
foreach (['unplayable' => 'this browser cannot play', 'connect_failed' => 'could not be connected',
          'closed' => 'you ended it', 'hidden' => 'hidden for more than 30 seconds'] as $code => $says) {
    $idc = $mk($live['db'], 3);
    [$sc, , $bcd] = $req('/stream.php', ['w' => 'live-town', 'a' => 'end', 'id' => $idc, 'why' => $code]);
    ok("an end the page codes `$code` is kept as its own sentence", $sc === 200
        && str_contains((string)xeric_stream_get($live['db'], $idc)['reason'], $says), $bcd);
}
ok('and only the button is "you ended it"', !str_contains(xeric_stream_why('unplayable') . xeric_stream_why('connect_failed')
    . xeric_stream_why('hidden') . xeric_stream_why('page'), 'you ended it'));
$idt = $mk($live['db'], 6);
$spentT = xeric_budget_spent($live['db'], $now)['stream'];
$req('/stream.php', ['w' => 'live-town', 'a' => 'end', 'id' => $idt, 'why' => 'closed']);
$rowT = xeric_stream_get($live['db'], $idt);
ok('an end meters the seconds since the last beat, onto the row and into the ledger',
    in_array((int)$rowT['seconds'], [6, 7], true)
    && in_array(xeric_budget_spent($live['db'], $now)['stream'] - $spentT, [6, 7], true), json_encode($rowT));
ok('and DELETEs the machine on its row; nobody listens there, so the end is not confirmed',
    json_decode((string)$rowT['meta'], true)['end_confirmed'] === false, (string)$rowT['meta']);

// GO LIVE IS OFFERED BY ONE ANSWER: the API's look says what the page's
// button does — the owner's, never a guest's, never while the room is drawn.
$live['db']->exec("UPDATE scene_streams SET status = 'ended', ended_at = " . (time() - 3600) . " WHERE status = 'live'");
file_put_contents(xeric_web_worlds_dir() . '/live-town/world-template.json', json_encode($live['template'], $J));
xeric_scene_source(fn() => ['base' => 'https://scenes.example:8443', 'stub' => fn() => []]);
$apiLook = function (string $who, string $role) {
    xeric_session_use($who);
    $r = xeric_api_route('GET', ['v1', 'worlds', 'live-town', 'look'], [], [], ['ok' => true, 'sid' => $who, 'role' => $role,
                         'adult' => true, 'via' => 'bearer']);
    return [$r['status'], (array)($r['body'] ?? [])];
};
[$so, $lo] = $apiLook($sid, 'owner');
ok('the API look offers go live to the owner, with the origin it is for', $so === 200
    && ($lo['stream']['offer'] ?? null) === true && ($lo['stream']['origin'] ?? '') === 'https://scenes.example:8443', json_encode($lo['stream'] ?? $lo));
[$sg2, $lg] = $apiLook($G, 'guest');
ok('and not to a guest token, who never runs the meter', $sg2 === 200 && ($lg['stream']['offer'] ?? null) === false
    && ($lg['stream']['origin'] ?? null) === '', json_encode($lg['stream'] ?? $lg));
xeric_session_use($sid);
$wo = xeric_play_open('live-town');
$fpNow = (string)xeric_scene_brief($wo['template'], $wo['db'])['fingerprint'];
$wo['db']->prepare("UPDATE scene_jobs SET fingerprint = ? WHERE key = 'bluebird' AND status = 'done'")->execute([sha1('an earlier hour')]);
$wo['db']->prepare("INSERT INTO scene_jobs (key, fingerprint, status, prompt, created_at) VALUES ('bluebird', ?, 'working', '', ?)")
         ->execute([$fpNow, time()]);
[, $lp] = $apiLook($sid, 'owner');
xeric_session_use($sid);
ok('and not while the room is being drawn — the page\'s rule, now the API\'s too', ($lp['stream']['offer'] ?? null) === false
    && ($lp['pending'] ?? null) === true && !str_contains(xeric_play_look_html($wo['template'], $wo['db'], null, 'live-town', true), 'data-golive'),
    json_encode($lp['stream'] ?? $lp));
xeric_scene_source(fn() => null);
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
let HLS = false;                              // does this fake browser play HLS natively?
const made = [];                              // every element document.createElement made
class El {
  constructor(tag) { this.tagName = tag.toUpperCase(); this.children = []; this.parentNode = null; this.attrs = {};
    this.dataset = {}; this.className = ''; this.textContent = ''; this.srcObject = null; this.paused = true;
    this.on = {}; this.loads = 0; this.naturalWidth = 0; this.hidden = false; this.style = {};
    this.clientWidth = 640; this.clientHeight = 360; this.offsetLeft = 0; this.offsetTop = 0;
    if (this.tagName === 'VIDEO') this.canPlayType = (t) => HLS && t === 'application/vnd.apple.mpegurl' ? 'maybe' : ''; }
  setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'src') { this._src = String(v); this.everSrc = true; } }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  removeAttribute(k) { delete this.attrs[k]; if (k === 'src') this._src = ''; }
  get src() { return this._src || ''; }
  set src(v) { this.attrs.src = String(v); this._src = String(v); this.everSrc = true; }
  addEventListener(t, f) { (this.on[t] = this.on[t] || []).push(f); }
  removeEventListener(t, f) { this.on[t] = (this.on[t] || []).filter((g) => g !== f); }
  fire(t) { (this.on[t] || []).slice().forEach((f) => f({ type: t })); }
  appendChild(c) { if (c.parentNode) c.remove(); c.parentNode = this; this.children.push(c); return c; }
  replaceWith(n) { const p = this.parentNode; if (!p) return; if (n.parentNode) n.remove(); const i = p.children.indexOf(this); p.children[i] = n; n.parentNode = p; this.parentNode = null; }
  remove() { const p = this.parentNode; if (p) p.children.splice(p.children.indexOf(this), 1); this.parentNode = null; }
  all() { return this.children.reduce((a, c) => a.concat([c], c.all()), []); }
  querySelector(sel) {
    return this.all().find((e) => sel.split(',').some((s) => { s = s.trim();
      const [t, c] = s.split('.'); return (!t || e.tagName === t.toUpperCase()) && (!c || e.className.split(' ').includes(c)); })) || null;
  }
  querySelectorAll(sel) {
    return this.all().filter((e) => sel.split(',').some((s) => { s = s.trim();
      const [t, c] = s.split('.'); return (!t || e.tagName === t.toUpperCase()) && (!c || e.className.split(' ').includes(c)); }));
  }
  play() { this.paused = false; return Promise.resolve(); }
  pause() { this.paused = true; }
  load() { this.loads++; }
}
global.window = global;
global.document = { hidden: false, createElement: (t) => { const e = new El(t); made.push(e); return e; },
                    addEventListener() {}, removeEventListener() {}, querySelector: () => null, querySelectorAll: () => [] };
const store = {};
global.sessionStorage = { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); } };
const posts = [];
global.fetch = (url, init) => { posts.push([url, init]); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: 1, live: true }) }); };
const bodies = (a) => posts.filter((p) => p[0] === 'stream.php').map((p) => JSON.parse(p[1].body)).filter((b) => !a || b.a === a);
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
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
  // The server's box is EMPTY (play-view.php): a slot, the session in data
  // attributes, no element and no src. The client makes the one element.
  const mkBox = (proto, url, fb, id) => {
    const page = new El('div');
    const box = page.appendChild(new El('div'));
    box.className = 'lookmain lookstreambox';
    Object.assign(box.dataset, { stream: id || '7', protocol: proto, url, fallback: fb || '', beat: 'stream.php', poster: 'scene.php?w=x' });
    const sl = box.appendChild(new El('div')); sl.className = 'lookstreamslot';
    const st = box.appendChild(new El('p')); st.className = 'lookstreamstate';
    return { page, box };
  };
  posts.length = 0; HLS = false; made.length = 0;
  let ended = null;
  let b = mkBox('hls', 'https://m.example/a.m3u8', 'https://m.example/a.mjpg');
  let s = XV.mount({ kind: 'stream', box: b.box, beatS: 0.02, onEnd: (r) => { ended = r; } });
  const im = b.box.querySelector('img.lookstreamimg');
  ok('stream: HLS on a browser that cannot play it falls back to the MJPEG the session offers, made here, in the slot',
    s.how === 'mjpeg' && im && im.src === 'https://m.example/a.mjpg' && im.parentNode === b.box.querySelector('.lookstreamslot'));
  await wait(60);
  ok('stream: and it does NOT beat before the first picture has arrived', bodies('beat').length === 0 && !s.beating,
    JSON.stringify(bodies()));
  im.naturalWidth = 640; im.fire('load');
  await wait(60);
  const beats = posts.filter((p) => JSON.parse(p[1].body).a === 'beat');
  ok('stream: once it shows a picture it beats, POSTing {w, id, a: beat, hidden_for} to stream.php', beats.length >= 1 && beats[0][0] === 'stream.php'
    && beats[0][1].method === 'POST' && JSON.parse(beats[0][1].body).w === 'live-town' && JSON.parse(beats[0][1].body).id === 7
    && JSON.parse(beats[0][1].body).hidden_for === 0, JSON.stringify(beats[0] || null));
  const b2 = mkBox('hls', 'https://m.example/a.m3u8', 'https://m.example/a.mjpg');
  const same = XV.mount({ kind: 'stream', box: b2.box });
  const b2b = mkBox('hls', 'https://m.example/a.m3u8', 'https://m.example/a.mjpg');
  XV.mount({ kind: 'stream', box: b2b.box });
  const b2c = mkBox('hls', 'https://m.example/a.m3u8', 'https://m.example/a.mjpg');
  XV.mount({ kind: 'stream', box: b2c.box });
  // Every element that was ever given a source or put on the page: the one
  // bare <video> the page keeps for asking canPlayType is neither.
  const live = made.filter((e) => (e.tagName === 'IMG' || e.tagName === 'VIDEO') && (e.parentNode || e.everSrc));
  ok('stream: three repaints of the same live view move the one element into each new slot; it is made once a session',
    same === s && b2c.box.querySelector('img.lookstreamimg') === s.media && !b2.box.querySelector('img.lookstreamimg')
    && live.length === 1 && live[0] === s.media && s.media.src === 'https://m.example/a.mjpg', live.length + ' elements');
  global.fetch = (url, init) => { posts.push([url, init]);
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: 1, live: false, reason: 'you left the Bluebird Diner' }) }); };
  await wait(60);
  ok('stream: when the server says it is over, the stored room comes back with the reason',
    ended === 'you left the Bluebird Diner' && b2c.page.children[0].className === 'lookmain lookstill'
    && b2c.page.children[0].querySelector('p.lookliveend').textContent === 'the live view ended: you left the Bluebird Diner'
    && b2c.page.children[0].querySelector('img.lookimg').src === 'scene.php?w=x', ended);
  ok('stream: and the picture is put down: its src taken away, so the MJPEG connection closes', live[0].getAttribute('src') === null);
  XV.unmount();
  global.fetch = (url, init) => { posts.push([url, init]); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: 1, live: true }) }); };

  // Unplayable: the real reason, and this tab remembers the machine.
  posts.length = 0; ended = null;
  const b3 = mkBox('hls', 'https://m.example/b.m3u8', '');
  XV.mount({ kind: 'stream', box: b3.box, onEnd: (r) => { ended = r; } });
  await tick();
  ok('stream: HLS with no fallback on such a browser ends at once, says plainly why, and never beats',
    /does not play HLS by itself \(Safari, Vision Pro and Quest do\)/.test(ended || '') && bodies('beat').length === 0, ended);
  ok('stream: and the end it sends is coded `unplayable` — never "closed", which the row would keep as "you ended it"',
    bodies('end').length === 1 && bodies('end')[0].why === 'unplayable', JSON.stringify(bodies('end')));
  const gA = new El('button'); gA.dataset.origin = 'https://m.example'; const pA = new El('p'); pA.appendChild(gA);
  const nA = pA.appendChild(new El('span')); nA.className = 'note';
  const gB = new El('button'); gB.dataset.origin = 'https://other.example';
  ok('stream: this tab will not offer go live again for that machine, and still will for another',
    XV.liveCannot('play', 'https://m.example') && XV.liveGate([gA, gB], 'play') === 1 && gA.hidden === true && !gB.hidden
    && /cannot play/.test(nA.textContent) && !XV.liveCannot('stand', 'https://m.example'), JSON.stringify(store));

  // Native HLS: the <video> the client made, beating only once it plays.
  posts.length = 0; HLS = true;
  const b4 = mkBox('hls', 'https://m.example/c.m3u8', '', '8');
  const s4 = XV.mount({ kind: 'stream', box: b4.box, beatS: 0.02 });
  const v4 = s4.media;
  await wait(50);
  ok('stream: where HLS plays natively, the <video> it made plays the session itself — and is not billed until it plays',
    s4.how === 'video' && v4.tagName === 'VIDEO' && !v4.paused && v4.getAttribute('src') === 'https://m.example/c.m3u8'
    && bodies('beat').length === 0);
  v4.fire('playing');
  await wait(50);
  ok('stream: the playing event starts the meter', bodies('beat').length >= 1 && bodies('beat')[0].id === 8);
  s4.end();
  ok('stream: every end puts the video down: paused, its src removed, loaded with nothing',
    v4.paused && v4.getAttribute('src') === null && v4.loads >= 1 && bodies('end').slice(-1)[0].why === 'closed');
  XV.unmount();
  posts.length = 0;
  const b4b = mkBox('hls', 'https://m.example/d.m3u8', '', '9');
  XV.mount({ kind: 'stream', box: b4b.box, connectS: 0.03, onEnd: (r) => { ended = r; } });
  await wait(80);
  ok('stream: a session that never shows a picture is ended as connect_failed, unbilled by any beat',
    bodies('beat').length === 0 && bodies('end').length === 1 && bodies('end')[0].why === 'connect_failed', JSON.stringify(bodies()));
  XV.unmount(); HLS = false;

  global.RTCPeerConnection = FakePC;
  global.fetch = (url, init) => { posts.push([url, init]); return Promise.resolve({ status: 201, ok: true, headers: { get: () => null },
    text: () => Promise.resolve('v=0 answer'), json: () => Promise.resolve({ ok: 1, live: true }) }); };
  posts.length = 0;
  const b5 = mkBox('webrtc-whep', 'https://m.example/whep/d', '');
  const s5 = XV.mount({ kind: 'stream', box: b5.box, beatS: 0.02 });
  await wait(40);
  ok('stream: WebRTC goes through the WHEP client onto a <video> made in the slot', s5.how === 'video' && FakePC.last.remote
    && FakePC.last.remote.sdp === 'v=0 answer' && s5.media.parentNode === b5.box.querySelector('.lookstreamslot'));
  ok('stream: and is not billed before its first frame', bodies('beat').length === 0);
  s5.media.fire('playing');
  await wait(40);
  ok('stream: its first frame starts the meter', bodies('beat').length >= 1);
  XV.unmount();
  ok('stream: and is closed when the view is thrown away', FakePC.last.closed);
  posts.length = 0;
  global.fetch = (url, init) => { posts.push([url, init]);
    if (init && init.headers && init.headers['Content-Type'] === 'application/sdp')
      return Promise.resolve({ status: 500, ok: false, headers: { get: () => null }, text: () => Promise.resolve('') });
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: 1, live: true }) }); };
  const b6 = mkBox('webrtc-whep', 'https://m.example/whep/e', '');
  XV.mount({ kind: 'stream', box: b6.box });
  await wait(40);
  ok('stream: a WHEP the machine refuses ends the view as connect_failed, never beaten',
    bodies('beat').length === 0 && bodies('end').length === 1 && bodies('end')[0].why === 'connect_failed', JSON.stringify(bodies()));
  XV.unmount();

  // -- the stand page's live view (stand.php → XV.standLive) --
  global.fetch = (url, init) => { posts.push([url, init]); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: 1, live: true }) }); };
  const sess = (proto, url, id) => ({ id: id || 21, live: true, protocol: proto, url, beat: 'stream.php', beat_s: 0.02 });
  for (const [proto, url, hls, what] of [['mjpeg', 'https://mj.example/x.mjpg', false, 'MJPEG'],
                                         ['hls', 'https://hl.example/x.m3u8', false, 'HLS without native support']]) {
    posts.length = 0; HLS = hls;
    let why = null, told = null;
    const L = XV.standLive(sess(proto, url), { onEnd: (r, t) => { why = r; told = t; } });
    await wait(50);
    ok('stand: ' + what + ' cannot be the sphere\'s texture: no video, the session ENDED as unplayable before the pano, never beaten',
      L.video === null && !L.alive && why && told === false && bodies('beat').length === 0
      && bodies('end').length === 1 && bodies('end')[0].why === 'unplayable' && bodies('end')[0].id === 21, JSON.stringify(bodies()));
    ok('stand: and this tab will not offer go live again for that machine', XV.liveCannot('stand', new URL(url).origin));
  }
  posts.length = 0; HLS = true;
  let sEnd = null;
  const L1 = XV.standLive(sess('hls', 'https://hl2.example/y.m3u8', 22), { onEnd: (r, t, c) => { sEnd = [r, t, c]; } });
  await wait(50);
  ok('stand: native HLS gets the detached <video>, and no beat until it plays',
    L1.video && L1.video.getAttribute('src') === 'https://hl2.example/y.m3u8' && bodies('beat').length === 0);
  L1.video.fire('playing');
  await wait(50);
  ok('stand: playing, it beats', bodies('beat').length >= 1 && bodies('beat')[0].id === 22);
  const v1 = L1.video;
  L1.off();
  const nb = bodies('beat').length;
  await wait(50);
  ok('stand: liveOff puts the detached video down — paused, src removed, load() — and stops the beat, telling no one',
    v1.paused && v1.getAttribute('src') === null && v1.loads >= 1 && bodies('beat').length === nb && bodies('end').length === 0);
  posts.length = 0;
  const L2 = XV.standLive(sess('hls', 'https://cors.example/z.m3u8', 23), { onEnd: (r, t, c) => { sEnd = [r, t, c]; } });
  L2.video.fire('playing');
  L2.fail('unplayable', 'it will not draw in here');
  ok('stand: a texture the browser refuses (videoFail) ENDS the session as unplayable and puts the video down',
    bodies('end').length === 1 && bodies('end')[0].why === 'unplayable' && L2.video.getAttribute('src') === null
    && L2.video.paused && sEnd && sEnd[2] === 'unplayable' && XV.liveCannot('stand', 'https://cors.example'));
  posts.length = 0;
  global.fetch = (url, init) => { posts.push([url, init]);
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: 1, live: false, reason: 'it reached its limit' }) }); };
  const L3 = XV.standLive(sess('hls', 'https://hl3.example/w.m3u8', 24), { onEnd: (r, t, c) => { sEnd = [r, t, c]; } });
  L3.video.fire('playing');
  await wait(60);
  ok('stand: the server\'s end is every end: the video put down too', sEnd && sEnd[0] === 'it reached its limit' && sEnd[1] === true
    && L3.video.getAttribute('src') === null && L3.video.loads >= 1 && bodies('end').length === 0);
  HLS = false; posts.length = 0;
  global.fetch = (url, init) => { posts.push([url, init]);
    if (init && init.headers && init.headers['Content-Type'] === 'application/sdp') return Promise.reject(new Error('no'));
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: 1, live: true }) }); };
  const L4 = XV.standLive(sess('webrtc-whep', 'https://w.example/whep/1', 25), { PC: FakePC, onEnd: (r, t, c) => { sEnd = [r, t, c]; } });
  await wait(40);
  ok('stand: a WHEP that fails ends the session as connect_failed before the pano, never beaten',
    !L4.alive && bodies('beat').length === 0 && bodies('end').length === 1 && bodies('end')[0].why === 'connect_failed'
    && sEnd[2] === 'connect_failed', JSON.stringify(bodies()));

  // -- the people over the live picture, at their bearings --
  // The row the server sends under the stream becomes the overlay once there
  // is a picture, through the field of view the machine said (here 90°).
  XV.unmount(); posts.length = 0;
  global.fetch = (url, init) => { posts.push([url, init]); return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: 1, live: true }) }); };
  const bt = mkBox('mjpeg', 'https://m.example/t.mjpg', '', '31');
  Object.assign(bt.box.dataset, { fov: '90', proj: 'perspective' });
  const trow = bt.box.appendChild(new El('div')); trow.className = 'looktagrow';
  [['ruth', 30], ['theo', 60]].forEach(([h, b]) => { const t = trow.appendChild(new El('button')); t.className = 'looktag';
    t.dataset.h = h; t.dataset.b = String(b); });
  const st31 = XV.mount({ kind: 'stream', box: bt.box, beatS: 0.02 });
  const tim = bt.box.querySelector('img.lookstreamimg');
  tim.naturalWidth = 640; tim.fire('load');
  await wait(20);
  const ruthT = trow.children[0], theoT = trow.children[1];
  ok('stream tags: laid over the live picture, Ruth at 30° right of centre, Theo at 60° past a 90° frame and hidden',
    trow.className === 'looktags' && !ruthT.hidden && parseFloat(ruthT.style.left) > 320 && theoT.hidden,
    JSON.stringify([trow.className, ruthT.style, theoT.hidden]));
  const bt2 = mkBox('mjpeg', 'https://m.example/t.mjpg', '', '31');
  Object.assign(bt2.box.dataset, { fov: '360', proj: 'equirectangular' });
  const trow2 = bt2.box.appendChild(new El('div')); trow2.className = 'looktagrow';
  const t2 = trow2.appendChild(new El('button')); t2.className = 'looktag'; t2.dataset.h = 'theo'; t2.dataset.b = '60';
  XV.mount({ kind: 'stream', box: bt2.box });
  ok('stream tags: a repaint moves the picture and re-lays the tags; a strip the machine says is 360° shows Theo at 60°',
    trow2.className === 'looktags' && !t2.hidden && Math.abs(parseFloat(t2.style.left) - 640 * (1 + 60 / 180) / 2) < 1,
    JSON.stringify(t2.style));
  st31.destroy();
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
    ok('the harness reported', $n >= 35, $res);
}

rmtree($tmp);
echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
exit($FAILED === 0 ? 0 : 1);
