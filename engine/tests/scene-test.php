<?php
/**
 * Xeric — scene tests.  `php engine/tests/scene-test.php`, exit 0 on pass.
 *
 * The viewport's two promises, tested from both sides. The brief is canon
 * and only canon: deterministic, keyed by what the room looks like rather
 * than by the minute, and carrying nothing a body in the doorway could not
 * see — so most of the interesting checks are negative, every secret string
 * in the fixture against every place at several hours. And the queue is
 * photo.php's queue: dormant without a machine, idempotent per look, three
 * tries and failed, metered, and patient with a machine that says "later".
 */

declare(strict_types=1);

require_once __DIR__ . '/../scene.php';

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

/** Which of $needles leaked into $hay. Case-insensitive: a leak is a leak. */
function leaks(string $hay, array $needles): array
{
    $found = [];
    foreach ($needles as $n) {
        if ($n !== '' && stripos($hay, $n) !== false) $found[] = $n;
    }
    return $found;
}

// render-test.php's list, verbatim: every one lives behind a wall in milldale.
const SECRETS = [
    'five-card draw',
    'poker',
    'green spiral notebook',
    'glovebox',
    "comping Harlan's lunch",
    'moves from window to window',
    'The pot never leaves the basement',
    'standing seat at the table',
];

$T  = xeric_template_load(__DIR__ . '/../fixtures/milldale.json');
$TZ = new DateTimeZone((string)$T['user']['timezone']);
$at = fn(string $local) => xeric_world_now($T, (new DateTimeImmutable($local, $TZ))->getTimestamp());

$dir  = sys_get_temp_dir() . '/xeric-scene-test-' . getmypid();
$dbP  = $dir . '-db.sqlite';
foreach ([$dbP, $dbP . '-wal', $dbP . '-shm'] as $f) @unlink($f);
$db = xeric_state_open($dbP);
xeric_clock_begin($db, $T);

// Nothing from the environment may leak into the dormant checks below.
$envKeep = [];
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_SCENE_KIND', 'XERIC_SCENE_AUTH',
          'XERIC_IMAGE_BASE', 'XERIC_IMAGE_KEY'] as $k) {
    $envKeep[$k] = getenv($k);
    putenv($k);
}

// ---------------------------------------------------------------------------
echo "# where the brief is\n";
// ---------------------------------------------------------------------------

$mon0710 = $at('2026-11-02 07:10');   // a Monday: Dot alone at the Bluebird
$mon0740 = $at('2026-11-02 07:40');
$mon1600 = $at('2026-11-02 16:00');   // Theo in the back booth, Dot gone home

ok('nowhere is a real state: no position, no brief', xeric_scene_brief($T, $db, null, $mon0710) === null);
xeric_player_move($db, 'bluebird', $mon0710['epoch'] - 600);
$b = xeric_scene_brief($T, $db, null, $mon0710);
ok('after a move the brief is the player\'s own room',
    $b !== null && $b['key'] === 'bluebird' && $b['name'] === 'the Bluebird Diner');
ok('an unknown key fails closed', xeric_scene_brief($T, $db, 'nowhere_at_all', $mon0710) === null);
ok('the brief carries the clock, the light, the sky the cast was told, and the room\'s state',
    $b['now']['hhmm'] === '07:10' && $b['now']['dow'] === 1 && $b['light'] === 'morning light'
    && $b['weather'] === xeric_weather_line($T, $mon0710, $db) && $b['open'] === true && $b['dark'] === false);
ok('who is here is presence, with only what can be seen',
    array_column($b['who'], 'handle') === ['dot']
    && array_keys($b['who'][0]) === ['handle', 'name', 'doing', 'at_home', 'appearance', 'build', 'wears']);
ok('you are named and timed', $b['you']['name'] === 'Walt' && $b['you']['since'] === $mon0710['epoch'] - 600);
ok('seeds are photo.php\'s seeds', $b['seeds']['place'] === xeric_photo_place_seeds($T, 'bluebird')
    && $b['seeds']['faces']['dot'] === xeric_photo_identity($T, 'dot')['face_seed']
    && $b['exterior']['seed'] === $b['seeds']['place']['exterior']);
ok('the light is deterministic and reads the hour',
    xeric_scene_light($at('2026-11-02 04:30')) === 'pre-dawn'
    && xeric_scene_light($at('2026-11-02 12:30')) === 'noon'
    && xeric_scene_light($at('2026-11-02 18:00')) === 'golden hour'
    && xeric_scene_light($at('2026-11-02 20:00')) === 'dusk'
    && xeric_scene_light($at('2026-11-02 23:30')) === 'lamplit night'
    && xeric_scene_light($at('2026-11-02 01:00')) === 'lamplit night');

// ---------------------------------------------------------------------------
echo "\n# determinism and the fingerprint\n";
// ---------------------------------------------------------------------------

$b2 = xeric_scene_brief($T, $db, 'bluebird', $mon0710);
ok('same inputs, byte-identical prompt and fingerprint',
    $b['prompt'] === $b2['prompt'] && $b['fingerprint'] === $b2['fingerprint']);
$b40 = xeric_scene_brief($T, $db, 'bluebird', $mon0740);
ok('half an hour later in the same light, the same fingerprint', $b40['fingerprint'] === $b['fingerprint']);

xeric_world_state_set($db, 'weather:2026-11-02', 'Rain coming sideways off the river.');
$bw = xeric_scene_brief($T, $db, 'bluebird', $mon0710);
ok('the narrator\'s sky changes the scene', $bw['fingerprint'] !== $b['fingerprint']
    && str_contains($bw['prompt'], 'Rain coming sideways'));
$db->exec("DELETE FROM world_state WHERE key = 'weather:2026-11-02'");

$Tr = $T;
foreach ($Tr['cast']['characters'] as &$c) {
    if ($c['handle'] === 'ruth') $c['week'][] = ['days' => [1], 'from' => '07:00', 'to' => '08:00',
                                                 'where' => 'bluebird', 'doing' => 'counter, one egg'];
}
unset($c);
$br = xeric_scene_brief($Tr, $db, 'bluebird', $mon0710);
ok('somebody else walking in changes the scene', $br['fingerprint'] !== $b['fingerprint']
    && array_column($br['who'], 'handle') === ['ruth', 'dot']);

$Ti = $T;
foreach ($Ti['places'] as &$p) if ($p['key'] === 'bluebird') $p['interior'] = ['the pie case', 'a jukebox'];
unset($p);
$bi  = xeric_scene_brief($Ti, $db, 'bluebird', $mon0710);
$Ti2 = $Ti;
foreach ($Ti2['places'] as &$p) if ($p['key'] === 'bluebird') $p['interior'] = ['the pie case', 'a cigarette machine'];
unset($p);
$bi2 = xeric_scene_brief($Ti2, $db, 'bluebird', $mon0710);
ok('the interior is in the prompt, seeded per item',
    str_contains($bi['prompt'], 'in the room: the pie case, a jukebox')
    && count($bi['interior']) === 2 && is_int($bi['interior'][0]['seed']));
ok('and changing one item changes the scene', $bi['fingerprint'] !== $bi2['fingerprint']
    && $bi['fingerprint'] !== $b['fingerprint']);

// ---------------------------------------------------------------------------
echo "\n# only observables\n";
// ---------------------------------------------------------------------------

$interiors = [];
foreach (xeric_wall_interiors($T) as $strings) foreach ($strings as $s) $interiors[] = $s;
$interiors = array_values(array_unique($interiors));
$hours = ['2026-11-01 08:30', '2026-11-01 10:00', '2026-11-02 07:10', '2026-11-02 16:00',
          '2026-11-05 20:00', '2026-11-07 10:00', '2026-11-03 23:30'];
$scanned = 0;
$found   = [];
foreach ($hours as $h) {
    foreach ($T['places'] as $p) {
        $sb = xeric_scene_brief($T, $db, (string)$p['key'], $at($h));
        if ($sb === null) continue;
        $scanned++;
        $hay = $sb['prompt'] . "\n" . json_encode($sb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (leaks($hay, array_merge(SECRETS, $interiors)) as $l) $found[$p['key'] . '@' . $h] = $l;
    }
}
ok('no secret, wall interior or psyche string reaches any brief at any hour',
    $found === [] && $scanned >= 20, $scanned . ' briefs; ' . json_encode($found));
ok('the generic scan actually had something to look for', count($interiors) >= 10, (string)count($interiors));

$Td = $T;
$dot = xeric_scene_brief($Td, $db, 'bluebird', $mon0710);
$db->prepare('INSERT INTO deaths (handle, world_epoch, how, created_at) VALUES (?, ?, ?, ?)')
   ->execute(['dot', $mon0710['epoch'] - 86400, '', time()]);
$gone = xeric_scene_brief($Td, $db, 'bluebird', $mon0710);
ok('the dead stand in no room', array_column($dot['who'], 'handle') === ['dot'] && $gone['who'] === []
    && str_contains($gone['prompt'], 'nobody else is here') && $gone['fingerprint'] !== $dot['fingerprint']);
$db->exec("DELETE FROM deaths");

xeric_dark_set($db, ['bluebird']);
$dk = xeric_scene_brief($T, $db, 'bluebird', $mon0710);
ok('a dark room says so and is not open', $dk['dark'] === true && $dk['open'] === false
    && str_contains($dk['prompt'], 'gone dark') && $dk['fingerprint'] !== $b['fingerprint']);
xeric_dark_set($db, []);

// ---------------------------------------------------------------------------
echo "\n# the age rule\n";
// ---------------------------------------------------------------------------

$Tx = $T;
$Tx['meta']['rating'] = 'explicit';
$kid = xeric_scene_brief($Tx, $db, 'bluebird', $mon1600);
$adult = xeric_scene_brief($Tx, $db, 'bluebird', $mon0710);
ok('the fixture puts a minor in the booth at four', array_column($kid['who'], 'handle') === ['theo']);
ok('a minor in the room forces the floor whatever the world says',
    $kid['minor'] === true && $kid['rating'] === 'sfw' && $adult['rating'] === 'explicit' && $adult['minor'] === false);
ok('the prompt ends with photo.php\'s own wholesome words',
    str_ends_with($kid['prompt'], XERIC_PHOTO_FLOOR_FRAMING . '.'), substr($kid['prompt'], -120));
$sentences = explode('. ', rtrim($kid['prompt'], '.'));
ok('the floor is the LAST sentence, and era and locale come before it',
    end($sentences) === XERIC_PHOTO_FLOOR_FRAMING
    && strpos($kid['prompt'], 'period-true to present day') < strpos($kid['prompt'], XERIC_PHOTO_FLOOR_FRAMING)
    && strpos($kid['prompt'], 'southern Ohio') < strpos($kid['prompt'], 'period-true'));
$kidAsk = xeric_scene_brief($Tx, $db, 'bluebird', $mon1600, ['ask' => 'something adult and suggestive']);
ok('an ask cannot argue its way past the floor',
    str_ends_with($kidAsk['prompt'], XERIC_PHOTO_FLOOR_FRAMING . '.') && $kidAsk['rating'] === 'sfw'
    && strpos($kidAsk['prompt'], 'something adult') < strpos($kidAsk['prompt'], XERIC_PHOTO_FLOOR_FRAMING)
    && $kidAsk['fingerprint'] !== $kid['fingerprint']);
ok('the adult room gets the world\'s rating, last',
    str_ends_with($adult['prompt'], 'a scene in keeping with the world\'s adult rating.'));

// ---------------------------------------------------------------------------
echo "\n# vantage and style\n";
// ---------------------------------------------------------------------------

ok('the default vantage is yours', $b['vantage'] === 'first_person'
    && str_contains($b['prompt'], 'seen from where you stand, just inside the door'));
$Te = $T;
$Te['media']['scene'] = ['vantage' => 'establishing', 'style' => 'shot on expired film, soft grain'];
$be = xeric_scene_brief($Te, $db, 'bluebird', $mon0710);
ok('establishing has no "you" in its vantage',
    $be['vantage'] === 'establishing' && !str_contains($be['prompt'], 'where you stand')
    && str_contains($be['prompt'], 'wide establishing view'));
ok('the style clause lands before the rating clause',
    strpos($be['prompt'], 'expired film') !== false
    && strpos($be['prompt'], 'expired film') < strpos($be['prompt'], 'a wholesome everyday scene')
    && str_ends_with($be['prompt'], 'a wholesome everyday scene, nothing suggestive.'));
ok('vantage and style are part of what the room looks like', $be['fingerprint'] !== $b['fingerprint']);
$Tj = $T;
$Tj['media']['scene'] = 'not a block';
ok('a malformed block is the defaults, and nothing throws',
    xeric_scene_brief($Tj, $db, 'bluebird', $mon0710)['fingerprint'] === $b['fingerprint']);

// ---------------------------------------------------------------------------
echo "\n# dormant\n";
// ---------------------------------------------------------------------------

ok('no scene machine and no image machine: dormant', xeric_scene_endpoint() === null);
ok('dormant is down, and free', xeric_scene_up() === false && xeric_scene_costly() === false);
$jid = xeric_scene_enqueue($db, $b);
ok('enqueue files one row per look', $jid > 0 && xeric_scene_enqueue($db, $b) === $jid
    && xeric_scene_enqueue($db, $b40) === $jid && count(xeric_scene_jobs($db)) === 1);
ok('the row keeps the prompt as asked', (string)xeric_scene_of($db, 'bluebird', $b['fingerprint'])['prompt'] === $b['prompt']);
$r = xeric_scene_reap($T, $db, $dir);
ok('a dormant reap says why and does nothing',
    $r['done'] === 0 && $r['failed'] === 0 && $r['notes'] !== [] && !is_dir($dir)
    && xeric_scene_jobs($db, 'pending')[0]['tries'] === 0, json_encode($r));
$Toff = $T;
$Toff['media']['scene'] = ['enabled' => false];
$boff = xeric_scene_brief($Toff, $db, 'bluebird', $mon0710);
ok('a world that turned scenes off still describes the room, and queues nothing',
    $boff !== null && $boff['enabled'] === false && xeric_scene_enqueue($db, $boff) === 0);

// ---------------------------------------------------------------------------
echo "\n# the fallback\n";
// ---------------------------------------------------------------------------

putenv('XERIC_IMAGE_BASE=http://127.0.0.1:9');
$fb = xeric_scene_endpoint();
ok('with only an image machine, the scene machine is that machine, kind image',
    $fb !== null && $fb['kind'] === 'image' && $fb['base'] === 'http://127.0.0.1:9', json_encode($fb));
ok('and loopback is the owner\'s electricity', xeric_scene_costly($fb) === false);
putenv('XERIC_SCENE_BASE=https://scenes.example');
putenv('XERIC_SCENE_AUTH=x-api-key');
putenv('XERIC_SCENE_KEY=k1');
$sc = xeric_scene_endpoint();
ok('a scene machine of its own wins, generic by default, and costs',
    $sc['base'] === 'https://scenes.example' && $sc['kind'] === 'generic' && xeric_scene_costly($sc) === true);
ok('the auth header is configurable, and a bad name falls back to bearer',
    xeric_scene_auth_headers($sc) === ['x-api-key: k1']
    && xeric_scene_auth_headers(['key' => 'k', 'auth' => "x\r\nEvil: 1"]) === ['Authorization: Bearer k']
    && xeric_scene_auth_headers(['key' => 'k']) === ['Authorization: Bearer k']
    && xeric_scene_auth_headers(['key' => '']) === []);
xeric_scene_source(fn() => false);
ok('the resolver saying "explicitly none" beats the environment', xeric_scene_endpoint() === null);
xeric_scene_source(fn() => null);
ok('a resolver with no opinion leaves the environment in charge', xeric_scene_endpoint()['base'] === 'https://scenes.example');
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_AUTH', 'XERIC_SCENE_KEY', 'XERIC_IMAGE_BASE'] as $k) putenv($k);
ok('and with the environment put back, dormant again', xeric_scene_endpoint() === null);

// ---------------------------------------------------------------------------
echo "\n# the stub round trip\n";
// ---------------------------------------------------------------------------

$metered = [];
xeric_scene_meter(function (array $u, string $who) use (&$metered) { $metered[] = [$u, $who]; });
$asked = [];
$stub = ['stub' => function (string $tag, array $brief, array $opts) use (&$asked) {
    $asked[] = [$tag, $brief['fingerprint'] ?? '', $opts, (string)($brief['prompt'] ?? '')];
    return ['bytes' => 'SCENE-' . md5((string)$brief['prompt']), 'mime' => 'image/jpeg',
            'kind' => 'pano', 'meta' => ['seed_used' => 7], 'usage' => ['cost_units' => 2]];
}];
// The reaper runs against the world's own clock; pin it to the asked minute
// by asking afresh for the room as it is NOW, so the fresh fingerprint agrees.
$nowB = xeric_scene_brief($T, $db);
$nid  = xeric_scene_enqueue($db, $nowB);
$db->prepare('DELETE FROM scene_jobs WHERE id != ?')->execute([$nid]);
$r = xeric_scene_reap($T, $db, $dir, $stub, 3);
$row = xeric_scene_of($db, (string)$nowB['key'], $nowB['fingerprint']);
ok('a reap renders the row, writes the file, records kind, mime and meta',
    $r['done'] === 1 && $row['status'] === 'done' && $row['kind'] === 'pano' && $row['mime'] === 'image/jpeg'
    && $row['file'] === 'scene-bluebird-' . substr($nowB['fingerprint'], 0, 12) . '.jpg'
    && is_file($dir . '/' . $row['file']) && $row['url'] === null && $row['remote_job'] === null
    && json_decode((string)$row['meta'], true) === ['seed_used' => 7], json_encode([$r, $row]));
ok('the stub saw the fresh brief and the world\'s kinds',
    count($asked) === 1 && $asked[0][0] === 'scene' && $asked[0][1] === $nowB['fingerprint']
    && $asked[0][2]['kinds'] === ['pano', 'image'] && $asked[0][2]['quality'] === 'full');
ok('the meter heard it once', count($metered) === 1 && $metered[0][0]['scenes'] === 1
    && $metered[0][0]['cost_units'] === 2 && $metered[0][1] === 'stub');
ok('latest is that row', (int)xeric_scene_latest($db, 'bluebird')['id'] === $nid
    && xeric_scene_latest($db, 'beck_hardware') === null);
$r2 = xeric_scene_reap($T, $db, $dir, $stub, 3);
ok('a second reap of the same look does nothing', $r2['done'] === 0 && count($asked) === 1 && count($metered) === 1);

// A changed room: the row is what was asked for. Filed under a look the room
// no longer has (another fingerprint, another paragraph), it renders as filed.
$oldB = ['prompt' => 'THE ROOM AS IT WAS ASKED FOR.', 'fingerprint' => sha1('an earlier look')] + $nowB;
xeric_scene_enqueue($db, $oldB);
$r = xeric_scene_reap($T, $db, $dir, $stub, 1);
$orow = xeric_scene_of($db, 'bluebird', $oldB['fingerprint']);
ok('a room that changed since it was asked renders as asked, filed as asked, with a note',
    $orow['status'] === 'done' && $asked[count($asked) - 1][1] === $oldB['fingerprint']
    && $asked[count($asked) - 1][3] === 'THE ROOM AS IT WAS ASKED FOR.'
    && str_contains((string)$orow['file'], substr($oldB['fingerprint'], 0, 12))
    && preg_grep('/changed since/', $r['notes']) !== [], json_encode([$r, $orow]));

// A viewer scene: a url, no bytes, no file.
$vB = xeric_scene_brief($T, $db, 'beck_hardware');
xeric_scene_enqueue($db, $vB);
$r = xeric_scene_reap($T, $db, $dir, ['stub' => fn() => ['kind' => 'viewer', 'url' => 'https://view.example/r/1']], 1);
$vrow = xeric_scene_of($db, 'beck_hardware', $vB['fingerprint']);
ok('a viewer scene stores its link and no file',
    $vrow['status'] === 'done' && $vrow['kind'] === 'viewer' && $vrow['file'] === ''
    && $vrow['url'] === 'https://view.example/r/1', json_encode([$r, $vrow]));
ok('a reply naming a local file is refused', (function () use ($vB) {
    try { xeric_scene_render(['stub' => fn() => ['kind' => 'viewer', 'url' => 'file:///etc/passwd']], $vB); }
    catch (RuntimeException $e) { return true; }
    return false;
})());
ok('a reply with both b64 and url is refused', (function () use ($vB) {
    try { xeric_scene_render(['stub' => fn() => ['kind' => 'image', 'b64' => 'QQ==', 'url' => 'https://x.example']], $vB); }
    catch (RuntimeException $e) { return true; }
    return false;
})());

// ---------------------------------------------------------------------------
echo "\n# failure\n";
// ---------------------------------------------------------------------------

$fB = xeric_scene_brief($T, $db, 'first_lutheran');
$fid = xeric_scene_enqueue($db, $fB);
$boom = ['stub' => function () { throw new RuntimeException('the machine is on fire'); }];
$tries = [];
$last  = null;
for ($i = 0; $i < 3; $i++) {
    $last = xeric_scene_reap($T, $db, $dir, $boom, 1);
    $frow = xeric_scene_of($db, 'first_lutheran', $fB['fingerprint']);
    $tries[] = [(string)$frow['status'], (int)$frow['tries']];
}
ok('a stub that throws burns a try and goes back to pending',
    $tries[0] === ['pending', 1] && $tries[1] === ['pending', 2], json_encode($tries));
ok('three failures and it is failed', $tries[2] === ['failed', 3] && $last['failed'] === 1
    && preg_grep('/on fire/', $last['notes']) !== [], json_encode($last));

// A dead reaper's claim: twenty minutes on, a pass takes it back.
$sB  = xeric_scene_brief($T, $db, 'the_mill');
$sid = xeric_scene_enqueue($db, $sB);
$db->prepare("UPDATE scene_jobs SET status = 'working', done_at = ? WHERE id = ?")
   ->execute([time() - 1300, $sid]);
xeric_scene_reap($T, $db, $dir, $stub, 5);
ok('a stale claim is reclaimed and finished', xeric_scene_of($db, 'the_mill', $sB['fingerprint'])['status'] === 'done');

// ---------------------------------------------------------------------------
echo "\n# a machine that says later\n";
// ---------------------------------------------------------------------------

$db->exec('DELETE FROM scene_jobs');
$calls = 0;
$later = ['stub' => function (string $tag, array $brief, array $opts) use (&$calls) {
    $calls++;
    if (($opts['job'] ?? '') === '') return ['pending' => true, 'job' => 'x'];
    return ['bytes' => 'LATE-BYTES', 'mime' => 'image/png', 'kind' => 'image', 'meta' => ['vendor_id' => 'x']];
}];
$lB  = xeric_scene_brief($T, $db, 'bluebird');
$lid = xeric_scene_enqueue($db, $lB);
$metered = [];
$r = xeric_scene_reap($T, $db, $dir, $later, 1);
$lrow = xeric_scene_of($db, 'bluebird', $lB['fingerprint']);
ok('a 202 leaves the row working with the machine\'s job id, unmetered',
    $r['done'] === 0 && $lrow['status'] === 'working' && $lrow['remote_job'] === 'x' && $metered === []
    && $calls === 1, json_encode([$r, $lrow]));
$db->prepare("UPDATE scene_jobs SET done_at = ? WHERE id = ?")->execute([time() - 1300, $lid]);
$r = xeric_scene_reap($T, $db, $dir, $later, 1);
$lrow = xeric_scene_of($db, 'bluebird', $lB['fingerprint']);
ok('the stale-claim reset leaves a waiting row alone, and the next pass asks after it',
    $r['done'] === 1 && $calls === 2 && $lrow['status'] === 'done' && $lrow['remote_job'] === null
    && is_file($dir . '/' . $lrow['file']) && file_get_contents($dir . '/' . $lrow['file']) === 'LATE-BYTES'
    && json_decode((string)$lrow['meta'], true) === ['vendor_id' => 'x'] && count($metered) === 1,
    json_encode([$r, $lrow]));

$db->exec('DELETE FROM scene_jobs');
$never = ['stub' => fn() => ['pending' => true, 'job' => 'y']];
$nid = xeric_scene_enqueue($db, $lB);
xeric_scene_reap($T, $db, $dir, $never, 1);
$db->prepare("UPDATE scene_jobs SET done_at = ? WHERE id = ?")->execute([time() - 1900, $nid]);
$r = xeric_scene_reap($T, $db, $dir, $never, 0);
$nrow = xeric_scene_of($db, 'bluebird', $lB['fingerprint']);
ok('a machine that never finishes: past thirty minutes the wait is a failed try',
    (int)$nrow['tries'] === 1 && preg_grep('/never finished/', $r['notes']) !== []
    && in_array($nrow['status'], ['pending', 'working'], true), json_encode([$r, $nrow]));

// ---------------------------------------------------------------------------

foreach ($envKeep as $k => $v) { if ($v !== false) putenv($k . '=' . $v); }
foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
@rmdir($dir);
foreach ([$dbP, $dbP . '-wal', $dbP . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
