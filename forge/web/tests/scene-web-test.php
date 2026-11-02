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
