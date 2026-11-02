<?php
/**
 * Xeric — the viewport's web half. `php forge/web/tests/scene-web-test.php`,
 * exit 0 on pass.
 *
 * engine/tests/scene-test.php proves the brief and the queue; this proves what
 * the browser is handed about them. NO NETWORK, NO MACHINE: rows are written
 * into scene_jobs by hand exactly as the reaper would leave them, and the
 * machine, where one is needed, is the engine's own stub seam.
 *
 * What is being defended, in the order it would hurt:
 *
 *   1. NOTHING IS SERVED THAT IS NOT WHAT IT SAYS. A still is bytes that
 *      sniff as an image or a 404; a splat or glb is an attachment off an
 *      allowlist; a viewer is a link and never a frame.
 *   2. THE VIEW SAYS WHEN IT IS OUT OF DATE, and a view whose file is not on
 *      this disk is not a view at all.
 *   3. SPENDING IS THE OWNER'S: no look button for a guest, and a disabled one,
 *      with the way to fix it, when there is no machine.
 *   4. A FORK DOES NOT INHERIT ROOMS IT CANNOT SHOW.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-scene-web-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_SCENE_KIND', 'XERIC_SCENE_AUTH',
          'XERIC_IMAGE_BASE', 'XERIC_IMAGE_KEY'] as $k) putenv($k);

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
    foreach (glob($d . '/*') ?: [] as $p) { is_dir($p) ? rmtree($p) : @unlink($p); }
    @rmdir($d);
}

/** A done row, as xeric_scene_finish would leave it. */
function done_row(PDO $db, string $key, string $fp, string $kind, string $file, ?string $url, string $mime): int
{
    $db->prepare("INSERT INTO scene_jobs (key, fingerprint, status, tries, kind, file, url, mime, prompt, meta,
                  created_at, done_at) VALUES (?, ?, 'done', 1, ?, ?, ?, ?, '', '{}', ?, ?)")
       ->execute([$key, $fp, $kind, $file, $url, $mime, time(), time() + (int)$db->query(
           'SELECT COUNT(*) FROM scene_jobs')->fetchColumn()]);
    return (int)$db->lastInsertId();
}

// A real world, from the tracked fixture, the way demo-test.php builds one.
$fixT = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$slug = 'scene-town';
$wdir = xeric_web_worlds_dir() . '/' . $slug;
@mkdir($wdir, 0775, true);
file_put_contents($wdir . '/world-template.json',
    json_encode($fixT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($wdir . '/seed.json',
    json_encode(xeric_forge_default_seed($fixT), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$A = bin2hex(random_bytes(16));
xeric_session_use($A);
xeric_session_claim($slug, $A);
$w  = xeric_play_open($slug);
$T  = $w['template'];
$db = $w['db'];
$scenes = dirname((string)$w['db_path']) . '/scenes';
@mkdir($scenes, 0775, true);

echo "# nowhere\n";

$v = xeric_play_look_view($T, $db, $slug, null, false);
ok('the GET for a player who is nowhere answers, with no brief and no scene',
    $v['ok'] === true && $v['brief'] === null && $v['scene'] === null && $v['pending'] === false);
ok('and with no machine, the machine is not up', $v['machine']['up'] === false && $v['machine']['kind'] === null);
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('the sidebar says so in one line', str_contains($h, 'You are nowhere in particular. Walk somewhere'), $h);
ok('the state payload carries the view', array_key_exists('look_html', xeric_play_state($w)));

echo "\n# somewhere, nothing drawn yet\n";

$now = xeric_clock_now($db, $T);
xeric_player_move($db, 'bluebird', (int)$now['epoch']);
$v = xeric_play_look_view($T, $db, $slug, null, false);
ok('after a move the brief is the room you are in', ($v['brief']['key'] ?? '') === 'bluebird');
$fp = (string)$v['brief']['fingerprint'];
$h  = xeric_play_look_html($T, $db, null, $slug, true);
ok('and the sidebar names it', str_contains($h, 'the Bluebird Diner'), $h);
ok('in the brief\'s words, not the provider prompt', !str_contains($h, 'seen from where you stand')
    && !str_contains($h, 'wholesome'), $h);
ok('with no machine the look button is there but dark, and says where to fix it',
    str_contains($h, 'data-look disabled') && str_contains($h, 'no scene machine') && str_contains($h, 'model.php'), $h);
ok('the state payload carries the same view', str_contains((string)xeric_play_state($w)['look_html'], 'the Bluebird Diner'));
ok('a guest gets no button at all', !str_contains(xeric_play_look_html($T, $db, null, $slug, false), 'data-look'));

xeric_scene_source(fn() => ['stub' => fn() => []]);
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('with a machine configured the button is live', str_contains($h, 'data-look>') && !str_contains($h, 'disabled'), $h);
ok('and the GET says it is up', xeric_play_look_view($T, $db, $slug)['machine']['up'] === true);
xeric_scene_source(fn() => false);

echo "\n# a still\n";

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($scenes . '/scene-bluebird-' . substr($fp, 0, 12) . '.png', $png);
$id = done_row($db, 'bluebird', $fp, 'image', 'scene-bluebird-' . substr($fp, 0, 12) . '.png', null, 'image/png');
$h = xeric_play_look_html($T, $db, null, $slug, true);
$src = 'scene.php?w=' . $slug . '&amp;k=bluebird&amp;f=' . $fp;
ok('a done still is an <img> pointed at scene.php by key and fingerprint',
    str_contains($h, '<img class="lookimg" src="' . $src . '"'), $h);
ok('with its light under it', str_contains($h, 'class="lookmeta"'));
ok('and nothing else to press, because it is current', !str_contains($h, 'data-look') && !str_contains($h, 'earlier hour'));
$v = xeric_play_look_view($T, $db, $slug, null, false);
ok('the GET carries the row, not stale', ($v['scene']['id'] ?? 0) === $id && $v['scene']['stale'] === false
    && $v['scene']['kind'] === 'image' && $v['scene']['url'] === null);
$f = xeric_play_look_file($db, 'bluebird', $fp);
ok('the bytes GET serves it inline, sniffed', $f !== null && $f['mime'] === 'image/png' && $f['inline'] === true);
ok('a fingerprint that is not a sha1 is refused', xeric_play_look_file($db, 'bluebird', '../../etc') === null);
ok('an unknown look is refused', xeric_play_look_file($db, 'bluebird', str_repeat('a', 40)) === null);

echo "\n# stale\n";

$old = str_repeat('b', 40);
$db->prepare('UPDATE scene_jobs SET fingerprint = ? WHERE id = ?')->execute([$old, $id]);
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('a view of a room that has changed says it is from an earlier hour',
    str_contains($h, 'lookstale') && str_contains($h, 'from an earlier hour'), $h);
ok('and offers the look again', str_contains($h, 'data-look'));
ok('the GET says stale too', xeric_play_look_view($T, $db, $slug, null, false)['scene']['stale'] === true);

echo "\n# not an image\n";

file_put_contents($scenes . '/scene-bluebird-' . substr($old, 0, 12) . '.png', "<script>alert(1)</script>");
$db->prepare('UPDATE scene_jobs SET file = ? WHERE id = ?')
   ->execute(['scene-bluebird-' . substr($old, 0, 12) . '.png', $id]);
ok('the bytes GET refuses a non-image file under an image kind', xeric_play_look_file($db, 'bluebird', $old) === null);
ok('and the sidebar does not point an <img> at it', !str_contains(xeric_play_look_html($T, $db, null, $slug, true), '<img'));

$db->prepare('UPDATE scene_jobs SET file = ? WHERE id = ?')->execute(['../../world-template.json', $id]);
ok('a file column that climbs out is basename()d and finds nothing', xeric_play_look_file($db, 'bluebird', $old) === null);
$db->exec('DELETE FROM scene_jobs');

echo "\n# a viewer, a model, a pano\n";

done_row($db, 'bluebird', $fp, 'viewer', '', 'https://rooms.example/r/1', 'text/html');
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('a viewer is a link out, in a new tab, with no opener',
    str_contains($h, '<a class="lookview" href="https://rooms.example/r/1" target="_blank" rel="noopener'), $h);
ok('and never a frame', stripos($h, '<iframe') === false && stripos($h, '<embed') === false && stripos($h, '<object') === false);
ok('the GET hands the link over for a viewer', xeric_play_look_view($T, $db, $slug, null, false)['scene']['url']
    === 'https://rooms.example/r/1');
ok('a viewer has no bytes to serve', xeric_play_look_file($db, 'bluebird', $fp) === null);
$db->exec("UPDATE scene_jobs SET url = 'javascript:alert(1)'");
ok('a viewer link that is not http is not shown', !str_contains(xeric_play_look_html($T, $db, null, $slug, true), 'javascript:'));
$db->exec('DELETE FROM scene_jobs');

file_put_contents($scenes . '/scene-bluebird-x.glb', "glTF\x02\x00\x00\x00");
done_row($db, 'bluebird', $fp, 'glb', 'scene-bluebird-x.glb', null, 'model/gltf-binary');
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('a glb is a download, labelled with its format', str_contains($h, 'download the room (.glb)') && str_contains($h, ' download>'), $h);
$f = xeric_play_look_file($db, 'bluebird', $fp);
ok('served as an attachment, under its allowlisted type', $f !== null && $f['inline'] === false && $f['mime'] === 'model/gltf-binary');
$db->exec("UPDATE scene_jobs SET mime = 'text/html'");
ok('and refused under any type off the allowlist', xeric_play_look_file($db, 'bluebird', $fp) === null);
$db->exec('DELETE FROM scene_jobs');

file_put_contents($scenes . '/scene-bluebird-p.png', $png);
done_row($db, 'bluebird', $fp, 'pano', 'scene-bluebird-p.png', null, 'image/png');
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('a pano is a focusable, labelled canvas with its source beside it',
    str_contains($h, '<canvas class="lookpano" tabindex="0"') && str_contains($h, 'aria-label=')
    && str_contains($h, 'data-src="' . $src . '"'), $h);
$db->exec('DELETE FROM scene_jobs');

echo "\n# missing, pending\n";

done_row($db, 'bluebird', $fp, 'image', 'scene-bluebird-gone.png', null, 'image/png');
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('a done row whose file is not on this disk is not a view', !str_contains($h, '<img') && str_contains($h, 'lookprose'), $h);
$db->exec('DELETE FROM scene_jobs');

xeric_scene_enqueue($db, xeric_scene_brief($T, $db));
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('a look being made says developing, and the page knows to come back', str_contains($h, 'developing') && str_contains($h, 'data-pending'), $h);
ok('the GET says pending', xeric_play_look_view($T, $db, $slug, null, false)['pending'] === true);

echo "\n# the people in the room\n";

$db->exec('DELETE FROM scene_jobs');
// A second person at the counter, the way scene-test.php puts one there.
$Tr = $T;
foreach ($Tr['cast']['characters'] as &$c) {
    // Three of them, all day every day, so the clock this suite stands at
    // cannot empty the room: enough people for a row to have an order and for
    // the room's own conversation to be in question.
    if (in_array($c['handle'], ['ruth', 'dot', 'theo'], true)) {
        $c['week'] = [['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '00:00', 'to' => '23:59',
                       'where' => 'bluebird', 'doing' => $c['handle'] === 'ruth' ? 'counter, one egg' : 'in a booth']];
    }
}
unset($c);
$br  = xeric_scene_brief($Tr, $db);
$fpr = (string)$br['fingerprint'];
$byH = [];
foreach ($br['who'] as $p) $byH[$p['handle']] = $p;
ok('the brief puts three people in the diner for these tests', isset($byH['ruth']) && count($br['who']) === 3,
    json_encode(array_keys($byH)));

file_put_contents($scenes . '/scene-bluebird-pano.png', $png);
done_row($db, 'bluebird', $fpr, 'pano', 'scene-bluebird-pano.png', null, 'image/png');
$h = xeric_play_look_html($Tr, $db, null, $slug, true);
$r = $byH['ruth'];
ok('over a pano, each person is a real button carrying a handle and a bearing',
    str_contains($h, '<button type="button" class="looktag" data-h="ruth" data-b="' . (int)$r['bearing'] . '"'), $h);
ok('what they are doing is its title and its description',
    str_contains($h, 'title="counter, one egg" aria-description="counter, one egg"'));
ok('the overlay tags start hidden, for the viewer to place', preg_match('/class="looktags"><button[^>]*hidden>/', $h) === 1);
ok('the pano tags sit inside the viewer root', preg_match('/class="lookmain lookpanobox"[^>]*>.*<div class="looktags">/s', $h) === 1);
preg_match('/data-bearings="([^"]*)"/', $h, $m);
$bear = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
ok('every bearing rides on the viewer root, furniture included',
    is_array($bear) && array_column($bear['who'], 'bearing', 'handle')['ruth'] === (int)$r['bearing']
    && count($bear['items']) === count($br['interior']), $m[1] ?? '');
ok('furniture gets no button', substr_count($h, 'class="looktag"') === count($br['who']));

$db->exec('DELETE FROM scene_jobs');
done_row($db, 'bluebird', $fpr, 'image', 'scene-bluebird-pano.png', null, 'image/png');
$h = xeric_play_look_html($Tr, $db, null, $slug, true);
ok('under a still the people are a row of the same buttons, shown',
    str_contains($h, '<div class="looktagrow"><button type="button" class="looktag"') && !preg_match('/class="looktag"[^>]*hidden/', $h), $h);
preg_match_all('/class="looktag" data-h="([^"]+)" data-b="(\d+)"/', $h, $mm);
$signed = array_map(fn($b) => xeric_play_signed_bearing((int)$b), $mm[2]);
$sorted = $signed; sort($sorted);
ok('left to right, the way you would see them', $signed === $sorted && count($signed) === count($br['who']), json_encode($mm[2]));
ok('a bearing of 350 is ten degrees left, not most of the way round',
    xeric_play_signed_bearing(350) === -10 && xeric_play_signed_bearing(10) === 10 && xeric_play_signed_bearing(180) === 180);
$db->exec('DELETE FROM scene_jobs');

echo "\n# the room through its day\n";

$lights = [['dusk', str_repeat('1', 40)], ['morning light', str_repeat('2', 40)], ['noon', str_repeat('3', 40)]];
foreach ($lights as [$light, $lfp]) {
    file_put_contents($scenes . '/scene-day-' . $light[0] . '.png', $png);
    $id = done_row($db, 'bluebird', $lfp, 'image', 'scene-day-' . $light[0] . '.png', null, 'image/png');
    $db->prepare('UPDATE scene_jobs SET light = ? WHERE id = ?')->execute([$light, $id]);
}
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('two or more lights make a strip', str_contains($h, '<div class="lookday"') && substr_count($h, 'class="lookthumb') === 3, $h);
$pm = strpos($h, 'data-light="morning light"'); $pn = strpos($h, 'data-light="noon"'); $pd = strpos($h, 'data-light="dusk"');
ok('in day order, not in the order they were drawn', $pm !== false && $pm < $pn && $pn < $pd);
ok('each thumb is the bytes GET of its row', str_contains($h, '<img src="scene.php?w=' . $slug . '&amp;k=bluebird&amp;f=' . str_repeat('3', 40) . '"'));
ok('and the view on screen is marked', preg_match('/class="lookthumb cur"[^>]*aria-pressed="true"/', $h) === 1
    && substr_count($h, 'lookthumb cur') === 1);
ok('and only that thumb says it is NOW — the data-now dayView reads to clear "earlier:"',
    preg_match('/class="lookthumb cur"[^>]*data-now="1"/', $h) === 1
    && substr_count($h, 'data-now="1"') === 1 && substr_count($h, 'data-now="0"') === 2, $h);
ok('the "earlier" label waits, hidden, for a swap', str_contains($h, '<p class="lookearlier" hidden></p>'));
$v = xeric_play_look_view($T, $db, $slug, null, false);
ok('the GET carries the strip too', array_column($v['day'], 'light') === ['morning light', 'noon', 'dusk']
    && count(array_filter(array_column($v['day'], 'current'))) === 1);
$db->exec("DELETE FROM scene_jobs WHERE light != 'dusk'");
ok('one light is not a strip', !str_contains(xeric_play_look_html($T, $db, null, $slug, true), 'lookday'));
$db->exec('DELETE FROM scene_jobs');

echo "\n# what you hear\n";

$sb = xeric_sound_brief($T, $db);
$h  = xeric_play_look_html($T, $db, null, $slug, true);
ok('the sound line is under the view whenever you are somewhere',
    $sb !== null && str_contains($h, '<p class="looksound">' . h((string)$sb['line']) . '</p>'), $h);
ok('and no player until a recording exists', !str_contains($h, '<audio'));
$Tq = $T; $Tq['media']['scene']['sound'] = ['enabled' => false];
ok('a world that turned its sound off says nothing about it', !str_contains(xeric_play_look_html($Tq, $db, null, $slug, true), 'looksound'));

$mp3 = 'ID3' . "\x04\x00\x00\x00\x00\x00\x00" . str_repeat("\x00", 32);
file_put_contents($scenes . '/sound-bluebird-x.mp3', $mp3);
$db->prepare("INSERT INTO scene_jobs (key, fingerprint, channel, status, tries, kind, file, mime, prompt, meta, created_at, done_at)
              VALUES ('bluebird', ?, 'sound', 'done', 1, 'audio', 'sound-bluebird-x.mp3', 'audio/mpeg', '', '{}', 1, 1)")
   ->execute([(string)$sb['fingerprint']]);
$h = xeric_play_look_html($T, $db, null, $slug, true);
$asrc = 'scene.php?w=' . $slug . '&amp;k=bluebird&amp;f=' . rawurlencode((string)$sb['fingerprint']);
ok('a done sound for exactly this room is a looping player that loads nothing until asked',
    str_contains($h, '<audio loop preload="none" controls src="' . $asrc . '"></audio>'), $h);
ok('never autoplay', stripos($h, 'autoplay') === false);
ok('with a listen toggle', str_contains($h, 'data-listen aria-pressed="false">listen</button>'));
$f = xeric_play_look_file($db, 'bluebird', (string)$sb['fingerprint']);
ok('the bytes GET takes a sound: fingerprint and serves it as audio, inline',
    $f !== null && $f['mime'] === 'audio/mpeg' && $f['inline'] === true);
ok('the GET names the sound', xeric_play_look_view($T, $db, $slug, null, false)['sound']['mime'] === 'audio/mpeg');
ok('a sound fingerprint never finds a look row', xeric_play_look_file($db, 'bluebird', 'sound:' . $fp) === null);
ok('a prefix that is not sound: is refused', xeric_play_look_file($db, 'bluebird', 'look:' . substr((string)$sb['fingerprint'], 6)) === null);

file_put_contents($scenes . '/sound-bluebird-x.mp3', 'OggS' . str_repeat("\x00", 30));
ok('bytes whose magic disagrees with the row are refused', xeric_play_look_file($db, 'bluebird', (string)$sb['fingerprint']) === null);
ok('and the player is not offered for them', !str_contains(xeric_play_look_html($T, $db, null, $slug, true), '<audio'));
file_put_contents($scenes . '/sound-bluebird-x.mp3', $png);
ok('a picture under a sound row is never served, as anything', xeric_play_look_file($db, 'bluebird', (string)$sb['fingerprint']) === null);
file_put_contents($scenes . '/sound-bluebird-x.mp3', $mp3);
$db->exec("UPDATE scene_jobs SET mime = 'text/html' WHERE channel = 'sound'");
ok('a sound type off the allowlist is refused', xeric_play_look_file($db, 'bluebird', (string)$sb['fingerprint']) === null);
$db->exec("UPDATE scene_jobs SET mime = 'audio/mpeg' WHERE channel = 'sound'");
ok('the magic for each kept container is known',
    xeric_play_audio_sniff("\xFF\xFB\x90\x00") === 'audio/mpeg' && xeric_play_audio_sniff('OggS....') === 'audio/ogg'
    && xeric_play_audio_sniff('RIFF....WAVEfmt ') === 'audio/wav' && xeric_play_audio_sniff("\x1A\x45\xDF\xA3....") === 'audio/webm'
    && xeric_play_audio_sniff("\x00\x00\x00\x20ftypM4A ") === 'audio/mp4' && xeric_play_audio_sniff('<html>') === '');
ok('the page lets <audio> load from this server and nowhere else',
    str_contains((string)file_get_contents(dirname(__DIR__) . '/ui.php'), ". \"media-src 'self'; \""));
$db->exec('DELETE FROM scene_jobs');

echo "\n# what a press owes, and what the worker waits for\n";

$generic = ['stub' => fn() => [], 'kind' => 'generic'];
$still   = ['base' => 'http://127.0.0.1:9', 'kind' => 'image'];
$o = xeric_play_look_owed($T, $db, xeric_scene_brief($T, $db), $generic);
ok('a machine that can make sound is asked for the sound as well', $o['look'] && $o['sound'] && $o['sound_brief']['channel'] === 'sound');
$o = xeric_play_look_owed($T, $db, xeric_scene_brief($T, $db), $still);
ok('an image machine is asked for the look only', $o['look'] && !$o['sound'] && $o['sound_brief'] === null);
ok('the image machine\'s channels are the look alone', xeric_scene_channels($T, $still) === ['look']);
xeric_scene_enqueue($db, xeric_sound_brief($T, $db));
ok('a pending sound is owed to a machine that can make it', xeric_play_scene_owed($db, ['look', 'sound']) === 1);
ok('and not to one that cannot — the worker does not wait out fifteen minutes on it',
    xeric_play_scene_owed($db, ['look']) === 0);
$db->exec('DELETE FROM scene_jobs');

echo "\n# the room, the machines card, and stepping inside\n";

$h = xeric_play_look_html($Tr, $db, null, $slug, true);
ok('the counter test has company enough for a room', count(xeric_scene_brief($Tr, $db)['who']) >= 2,
    json_encode(array_column(xeric_scene_brief($Tr, $db)['who'], 'handle')));
ok('with two or more people present the look block offers to talk to the room',
    str_contains($h, '<button type="button" class="nbtn" data-room>talk to the room</button>'), $h);
ok('and the old "no web surface" placeholder is gone', !str_contains($h, 'has no web surface yet'));
ok('a guest, who may read the room and never speak into it, is not offered the door',
    !str_contains(xeric_play_look_html($Tr, $db, null, $slug, false), 'talk to the room'));
ok('with fewer, it says nothing about it', count(xeric_scene_brief($T, $db)['who']) >= 2
    || !str_contains(xeric_play_look_html($T, $db, null, $slug, true), 'data-room'));

ok('the machines card says a local machine draws the room you are in ahead',
    str_contains(xeric_play_scene_ahead_line(['base' => 'http://127.0.0.1:7860'], 'XERIC_SCENE_KEY'), 'the room you are standing in'));
$line = xeric_play_scene_ahead_line(['base' => 'https://scenes.example.com'], 'XERIC_SCENE_KEY');
ok('and a costly one draws nothing unless the template asks, naming the key',
    str_contains($line, 'nothing') && str_contains($line, 'XERIC_SCENE_KEY') && str_contains($line, 'media.scene.prerender'));

$pp = (string)file_get_contents(dirname(__DIR__) . '/play.php');
ok('step inside lives in one function beside the viewer', str_contains($pp, '  function xr(api) {')
    && strpos($pp, '  function xr(api) {') > strpos($pp, '  function pano(box) {'));
ok('the button is made only after the browser says an immersive session is supported',
    preg_match("/isSessionSupported\('immersive-vr'\)\.then\(function \(yes\) \{\s*if \(!yes[^}]*return;\s*btn = document\.createElement/s", $pp) === 1);
ok('it renders through the same context, made xr-compatible, in the local space',
    str_contains($pp, 'gl.makeXRCompatible') && str_contains($pp, 'new XRWebGLLayer(s, gl)') && str_contains($pp, "requestReferenceSpace('local')"));
ok('and the people open their threads the way the cast chips do',
    str_contains($pp, "if (t.classList.contains('looktag')) { openThread(t.dataset.h); return; }"));

echo "\n# the stale view walks back to one it can show\n";

$db->exec('DELETE FROM scene_jobs');
file_put_contents($scenes . '/scene-old-still.png', $png);
$old1 = done_row($db, 'bluebird', str_repeat('4', 40), 'image', 'scene-old-still.png', null, 'image/png');
$new1 = done_row($db, 'bluebird', str_repeat('5', 40), 'image', 'scene-gone.png', null, 'image/png');
$db->prepare('UPDATE scene_jobs SET done_at = ? WHERE id = ?')->execute([100, $old1]);
$db->prepare('UPDATE scene_jobs SET done_at = ? WHERE id = ?')->execute([200, $new1]);
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('a newest view whose file has gone does not hide an older still',
    str_contains($h, 'f=' . str_repeat('4', 40)) && str_contains($h, 'from an earlier hour') && !str_contains($h, 'lookprose'), $h);
file_put_contents($scenes . '/scene-new.splat', "splat\x00");
$db->prepare("UPDATE scene_jobs SET kind = 'splat', file = 'scene-new.splat', mime = 'application/octet-stream' WHERE id = ?")
   ->execute([$new1]);
$h = xeric_play_look_html($T, $db, null, $slug, true);
ok('nor does a newer splat the sidebar cannot show inline', str_contains($h, '<img class="lookimg" src="scene.php?w='
    . $slug . '&amp;k=bluebird&amp;f=' . str_repeat('4', 40)) && !str_contains($h, 'download the room'), $h);
$db->exec("DELETE FROM scene_jobs WHERE kind = 'image'");
ok('and with nothing else to show, the splat is still offered as a download',
    str_contains(xeric_play_look_html($T, $db, null, $slug, true), 'download the room (.splat)'));
$db->exec('DELETE FROM scene_jobs');
$keep = done_row($db, 'bluebird', str_repeat('6', 40), 'image', 'scene-old-still.png', null, 'image/png');
$db->prepare('UPDATE scene_jobs SET done_at = 1 WHERE id = ?')->execute([$keep]);
for ($i = 0; $i < 5; $i++) {
    $g = done_row($db, 'bluebird', str_repeat(dechex(7 + $i), 40), 'image', 'scene-gone-' . $i . '.png', null, 'image/png');
    $db->prepare('UPDATE scene_jobs SET done_at = ? WHERE id = ?')->execute([10 + $i, $g]);
}
ok('the walk is five deep and no deeper', !str_contains(xeric_play_look_html($T, $db, null, $slug, true), '<img class="lookimg"'));
$db->exec('DELETE FROM scene_jobs');

echo "\n# the fork\n";

done_row($db, 'mill', str_repeat('c', 40), 'viewer', '', 'https://rooms.example/r/2', 'text/html');
done_row($db, 'mill', str_repeat('d', 40), 'image', 'scene-mill-d.png', null, 'image/png');
$copy = $tmp . '/fork.db';
$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
copy((string)$w['db_path'], $copy);
ok('the fork clear passes', xeric_session_fork_clear($copy));
$fdb  = new PDO('sqlite:' . $copy);
$left = $fdb->query('SELECT kind, status FROM scene_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
ok('a fork keeps only views it can show without the owner\'s files: viewer links',
    $left === [['kind' => 'viewer', 'status' => 'done']], json_encode($left));
$fdb = null;

rmtree($tmp);

echo "\n" . ($FAILED === 0 ? "all scene web tests passed\n" : "$FAILED scene web test(s) FAILED\n");
exit($FAILED === 0 ? 0 : 1);
