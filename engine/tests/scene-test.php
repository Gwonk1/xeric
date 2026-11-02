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
 *
 * Then the second half: where things sit (bearings that never move a pie
 * case for a visitor), the picture that rides along against drift, the sound
 * channel and its own refusals (a count, never a name; voices, never words;
 * the floor last), drawing ahead only when the world or the owner's own
 * electricity says so, and one room through its day.
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
    && array_keys($b['who'][0]) === ['handle', 'name', 'doing', 'at_home', 'appearance', 'build', 'wears', 'bearing']);
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
ok('and so is LOCALHOST, or [::1], however it is written — the budget\'s own answer too',
    !xeric_scene_costly(['base' => 'http://LOCALHOST:8080', 'kind' => 'image'])
    && !xeric_scene_costly(['base' => 'http://[::1]:8080', 'kind' => 'image'])
    && !xeric_budget_costly_at('http://LOCALHOST:8080') && !xeric_budget_costly_at('http://[::1]:8080'));
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
echo "\n# where things sit\n";
// ---------------------------------------------------------------------------

$world = (string)$T['meta']['name'];
$rawB  = fn(string $key, string $n): int => crc32($world . '|' . $key . '|' . mb_strtolower(trim($n))) % 360;
$dist  = function (int $a, int $c): int { $d = abs($a - $c); return min($d, 360 - $d); };

$bb  = xeric_scene_brief($Ti, $db, 'bluebird', $mon0710);    // the pie case, a jukebox, and Dot
$all = array_merge(array_column($bb['interior'], 'bearing'), array_column($bb['who'], 'bearing'));
ok('every person and every named thing has a bearing, a whole degree on the circle',
    count($all) === 3 && array_filter($all, fn($x) => !is_int($x) || $x < 0 || $x > 359) === [], json_encode($all));
$ib = array_column($bb['interior'], 'bearing', 'text');
ok('the first thing placed sits exactly where its name puts it', $ib['a jukebox'] === $rawB('bluebird', 'a jukebox'));
$bb40 = xeric_scene_brief($Ti, $db, 'bluebird', $mon0740);
ok('derived, never stored: the same room half an hour on puts everything in the same place',
    array_column($bb40['interior'], 'bearing') === array_column($bb['interior'], 'bearing')
    && array_column($bb40['who'], 'bearing') === array_column($bb['who'], 'bearing'));

// Two names whose raw bearings collide, found rather than hoped for.
$pair = null;
for ($i = 0; $i < 80 && $pair === null; $i++) {
    for ($j = $i + 1; $j < 80; $j++) {
        if ($dist($rawB('the_mill', "stool $i"), $rawB('the_mill', "stool $j")) < XERIC_SCENE_BEARING_GAP) {
            $pair = ["stool $i", "stool $j"];
            break;
        }
    }
}
$Tc = $T;
foreach ($Tc['places'] as &$p) if ($p['key'] === 'the_mill') $p['interior'] = (array)$pair;
unset($p);
$mc = array_column(xeric_scene_brief($Tc, $db, 'the_mill', $mon0710)['interior'], 'bearing', 'text');
$sorted = (array)$pair;
sort($sorted, SORT_STRING);
[$first, $second] = $sorted + ['', ''];
$moved = $pair === null ? 0 : ($mc[$second] - $rawB('the_mill', $second) + 360) % 360;
ok('two things within twenty degrees: the earlier name keeps its place, the later moves on by forty',
    $pair !== null && $mc[$first] === $rawB('the_mill', $first) && $moved > 0 && $moved % 40 === 0
    && $dist($mc[$first], $mc[$second]) >= 20, json_encode([$pair, $mc]));

$Tn = $T;
foreach ($Tn['places'] as &$p) {
    if ($p['key'] === 'bluebird') {
        $p['interior'] = ['the counter', 'the pie case', 'a jukebox', 'the grill', 'eight booths',
                          'nine stools', 'the register', 'a coat rack'];
    }
}
unset($p);
$nb     = xeric_scene_brief($Tn, $db, 'bluebird', $mon0710);    // eight things and Dot: nine on the circle
$circle = array_merge(array_column($nb['interior'], 'bearing'), array_column($nb['who'], 'bearing'));
$tight  = 360;
for ($i = 0; $i < count($circle); $i++) {
    for ($j = $i + 1; $j < count($circle); $j++) $tight = min($tight, $dist($circle[$i], $circle[$j]));
}
ok('nine things on one circle always fit, every pair at least twenty degrees apart',
    count($circle) === 9 && $tight >= 20, json_encode([$circle, $tight]));
$nbEmpty = xeric_scene_brief($Tn, $db, 'bluebird', $at('2026-11-02 23:30'));
ok('furniture never moves because somebody walked in',
    $nbEmpty['who'] === []
    && array_column($nbEmpty['interior'], 'bearing', 'text') === array_column($nb['interior'], 'bearing', 'text'));
$Tnr = $Tn;
foreach ($Tnr['cast']['characters'] as &$c) {
    if ($c['handle'] === 'ruth') $c['week'][] = ['days' => [1], 'from' => '07:00', 'to' => '08:00',
                                                 'where' => 'bluebird', 'doing' => 'counter, one egg'];
}
unset($c);
$nbr = xeric_scene_brief($Tnr, $db, 'bluebird', $mon0710);
ok('and the earliest handle keeps its place when somebody else walks in',
    array_column($nbr['who'], 'bearing', 'handle')['dot'] === array_column($nb['who'], 'bearing', 'handle')['dot']
    && array_column($nbr['interior'], 'bearing', 'text') === array_column($nb['interior'], 'bearing', 'text'));
$shifted = $bb;
foreach ($shifted['who'] as &$w) $w['bearing'] = ($w['bearing'] + 90) % 360;
unset($w);
foreach ($shifted['interior'] as &$it) $it['bearing'] = ($it['bearing'] + 90) % 360;
unset($it);
ok('bearings are not in the fingerprint',
    xeric_scene_fingerprint($shifted, $bb['exterior']['description'], array_column($bb['interior'], 'text'),
                            xeric_scene_config($Ti)) === $bb['fingerprint']);

// ---------------------------------------------------------------------------
echo "\n# the contract's body\n";
// ---------------------------------------------------------------------------

$body = xeric_scene_body($bb, ['kinds' => ['pano', 'image'], 'quality' => 'draft', 'negative' => 'no text in frame']);
ok('a look asks for what the world wants, at the pano size, with its negative and its seed',
    $body['channel'] === 'look' && $body['kind_wanted'] === ['pano', 'image'] && $body['size'] === [4096, 2048]
    && $body['quality'] === 'draft' && $body['negative'] === 'no text in frame'
    && $body['seed'] === $bb['seeds']['place']['interior'] && $body['fingerprint'] === $bb['fingerprint']
    && !isset($body['reference_image']) && !isset($body['reference_fingerprint']));
ok('the structured brief rides along whole, bearings and all',
    $body['brief']['who'][0]['bearing'] === $bb['who'][0]['bearing'] && isset($body['brief']['interior'][1]['bearing']));
$withRef = xeric_scene_body($bb, ['reference' => ['bytes' => 'PNGDATA', 'mime' => 'image/png', 'fingerprint' => 'abc']]);
ok('a reference rides as an image, with the look it was of',
    $withRef['reference_image'] === ['mime' => 'image/png', 'b64' => base64_encode('PNGDATA')]
    && $withRef['reference_fingerprint'] === 'abc');
ok('no pano wanted, no pano size', !isset(xeric_scene_body($bb, ['kinds' => ['image']])['size']));
$sbody = xeric_scene_body(xeric_sound_brief($T, $db, 'bluebird', $mon0710));
ok('a sound asks for audio, with its own seed and no picture size',
    $sbody['channel'] === 'sound' && $sbody['kind_wanted'] === ['audio'] && !isset($sbody['size'])
    && $sbody['seed'] === xeric_photo_seed($T, 'place.sound', 'bluebird'));

// ---------------------------------------------------------------------------
echo "\n# drift, and the picture that rides along\n";
// ---------------------------------------------------------------------------

$db->exec('DELETE FROM scene_jobs');
$refSeen = [];
$refStub = ['stub' => function (string $tag, array $brief, array $opts) use (&$refSeen) {
    $refSeen[] = $opts['reference'] ?? null;
    return ['bytes' => 'ROOM-' . md5((string)$brief['fingerprint']), 'mime' => 'image/png', 'kind' => 'image'];
}];
$h1 = xeric_scene_brief($T, $db, 'beck_hardware', $at('2026-11-02 09:00'));   // Harlan at the register
$h2 = xeric_scene_brief($T, $db, 'beck_hardware', $at('2026-11-02 18:00'));   // shut, golden hour
xeric_scene_enqueue($db, $h1);
xeric_scene_reap($T, $db, $dir, $refStub, 1);
xeric_scene_enqueue($db, $h2);
xeric_scene_reap($T, $db, $dir, $refStub, 1);
$row1 = xeric_scene_of($db, 'beck_hardware', $h1['fingerprint']);
$row2 = xeric_scene_of($db, 'beck_hardware', $h2['fingerprint']);
ok('the first render of a place goes alone',
    count($refSeen) === 2 && $refSeen[0] === null
    && !isset(json_decode((string)$row1['meta'], true)['reference_fingerprint']), json_encode($refSeen));
ok('the second takes the first picture along: its bytes, its type, and which look it was',
    is_array($refSeen[1]) && $refSeen[1]['fingerprint'] === $h1['fingerprint'] && $refSeen[1]['mime'] === 'image/png'
    && $refSeen[1]['bytes'] === file_get_contents($dir . '/' . $row1['file']));
ok('and its row says which picture rode along',
    (json_decode((string)$row2['meta'], true)['reference_fingerprint'] ?? '') === $h1['fingerprint']);

$len1 = strlen((string)file_get_contents($dir . '/' . $row1['file']));
ok('the newest picture is the one chosen',
    (xeric_scene_reference($db, 'beck_hardware', $dir)['fingerprint'] ?? '') === $h2['fingerprint']);
file_put_contents($dir . '/' . $row2['file'], str_repeat('x', $len1 + 100));
ok('one heavier than the cap is passed over for the one before it',
    (xeric_scene_reference($db, 'beck_hardware', $dir, $len1 + 50)['fingerprint'] ?? '') === $h1['fingerprint']);
ok('and when every picture is too heavy, none rides', xeric_scene_reference($db, 'beck_hardware', $dir, 4) === null);
@unlink($dir . '/' . $row2['file']);
ok('a picture missing from this disk is passed over too',
    (xeric_scene_reference($db, 'beck_hardware', $dir)['fingerprint'] ?? '') === $h1['fingerprint']);
ok('a place never drawn has nothing to send', xeric_scene_reference($db, 'first_lutheran', $dir) === null);

ok('an images call takes no reference; any other machine may',
    !xeric_scene_takes_reference(['kind' => 'image', 'base' => 'x'])
    && xeric_scene_takes_reference(['kind' => 'generic', 'base' => 'x'])
    && xeric_scene_takes_reference(['stub' => fn() => []]));
$h3 = xeric_scene_brief($T, $db, 'beck_hardware', $at('2026-11-02 12:30'));
xeric_scene_enqueue($db, $h3);
xeric_scene_reap($T, $db, $dir, ['kind' => 'image'] + $refStub, 1);
ok('so a look drawn by an image machine goes alone, though a picture of the room exists',
    count($refSeen) === 3 && $refSeen[2] === null
    && !isset(json_decode((string)xeric_scene_of($db, 'beck_hardware', $h3['fingerprint'])['meta'], true)['reference_fingerprint']));

$asyncRef = [];
$lateRef  = ['stub' => function (string $tag, array $brief, array $opts) use (&$asyncRef) {
    if (($opts['job'] ?? '') === '') { $asyncRef[] = $opts['reference'] ?? null; return ['pending' => true, 'job' => 'r1']; }
    return ['bytes' => 'LATE-ROOM', 'mime' => 'image/png', 'kind' => 'pano', 'meta' => ['seed_used' => 3]];
}];
$h4 = xeric_scene_brief($T, $db, 'beck_hardware', $at('2026-11-02 20:00'));   // dusk
xeric_scene_enqueue($db, $h4);
xeric_scene_reap($T, $db, $dir, $lateRef, 1);
$w4 = xeric_scene_of($db, 'beck_hardware', $h4['fingerprint']);
xeric_scene_reap($T, $db, $dir, $lateRef, 1);
$d4 = xeric_scene_of($db, 'beck_hardware', $h4['fingerprint']);
ok('a machine that says later still gets the picture, and the row remembers which until the answer comes',
    is_array($asyncRef[0] ?? null) && $asyncRef[0]['fingerprint'] === $h3['fingerprint']
    && $w4['status'] === 'working' && json_decode((string)$w4['meta'], true) === ['reference_fingerprint' => $h3['fingerprint']]
    && $d4['status'] === 'done' && $d4['kind'] === 'pano'
    && json_decode((string)$d4['meta'], true) === ['reference_fingerprint' => $h3['fingerprint'], 'seed_used' => 3],
    json_encode([$w4['meta'] ?? null, $d4['meta'] ?? null]));

$flaky = ['stub' => function (string $tag, array $brief, array $opts) {
    if (($opts['job'] ?? '') === '') return ['pending' => true, 'job' => 'f1'];
    throw new RuntimeException('the machine lost the job');
}];
$h5 = xeric_scene_brief($T, $db, 'beck_hardware', $at('2026-11-02 05:30'));   // pre-dawn
xeric_scene_enqueue($db, $h5);
xeric_scene_reap($T, $db, $dir, $flaky, 1);
$s5 = xeric_scene_of($db, 'beck_hardware', $h5['fingerprint']);
xeric_scene_reap($T, $db, $dir, $flaky, 1);
$f5 = xeric_scene_of($db, 'beck_hardware', $h5['fingerprint']);
ok('a try that fails takes what was written down when it was sent with it',
    isset(json_decode((string)$s5['meta'], true)['reference_fingerprint'])
    && $f5['status'] === 'pending' && (int)$f5['tries'] === 1 && $f5['meta'] === '{}' && $f5['remote_job'] === null,
    json_encode($f5));

// ---------------------------------------------------------------------------
echo "\n# what you hear\n";
// ---------------------------------------------------------------------------

$db->exec('DELETE FROM scene_jobs');
$s = xeric_sound_brief($T, $db, null, $mon0710);
ok('the sound brief is the player\'s own room, on its own channel, asking for audio',
    $s !== null && $s['key'] === 'bluebird' && $s['channel'] === 'sound' && $s['kinds'] === ['audio']
    && $s['name'] === 'the Bluebird Diner' && $s['now']['hhmm'] === '07:10' && $s['phase'] === 'morning');
ok('an unknown key fails closed', xeric_sound_brief($T, $db, 'nowhere_at_all', $mon0710) === null);
xeric_player_move($db, null);
ok('and nowhere is still nowhere', xeric_sound_brief($T, $db, null, $mon0710) === null);
xeric_player_move($db, 'bluebird', $mon0710['epoch'] - 600);

$s2 = xeric_sound_brief($T, $db, 'bluebird', $mon0710);
ok('same inputs, byte-identical line, prompt and fingerprint',
    $s['line'] === $s2['line'] && $s['prompt'] === $s2['prompt'] && $s['fingerprint'] === $s2['fingerprint']);
ok('one person busy at the grill is heard as work, not talk',
    $s['heard'] === 1 && $s['voiced'] === false && $s['line'] === 'Somebody busy, not talking, cups and plates knocking.',
    $s['line']);
ok('the paragraph is written for the ear, era clause and all',
    str_contains($s['prompt'], 'period-true to present day, nothing anachronistic in earshot')
    && str_contains($s['prompt'], 'heard from where you stand, just inside the door')
    && str_ends_with($s['prompt'], 'wholesome everyday sound, nothing suggestive.'), $s['prompt']);

$castNames = array_map(fn($c) => (string)$c['display_name'], $T['cast']['characters']);
$found = [];
$long  = [];
$named = [];
$scanned = 0;
foreach ($hours as $h) {
    foreach ($T['places'] as $p) {
        $sb = xeric_sound_brief($T, $db, (string)$p['key'], $at($h));
        if ($sb === null) continue;
        $scanned++;
        $hay = $sb['line'] . "\n" . $sb['prompt'] . "\n" . json_encode($sb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (leaks($hay, array_merge(SECRETS, $interiors)) as $l) $found[$p['key'] . '@' . $h] = $l;
        if (count(preg_split('/\s+/u', trim($sb['line']))) > XERIC_SOUND_LINE_WORDS
            || substr_count($sb['line'], '.') !== 1 || !str_ends_with($sb['line'], '.')) $long[] = $sb['line'];
        foreach (leaks($sb['line'], array_merge($castNames, [(string)$p['name']])) as $l) $named[] = $l;
        foreach (leaks($hay, $castNames) as $l) $named[] = $l;
    }
}
ok('no secret or wall interior reaches a sound brief at any hour',
    $found === [] && $scanned >= 20, $scanned . ' briefs; ' . json_encode($found));
ok('every line is one sentence of fourteen words or fewer', $long === [], json_encode($long));
ok('and no sound brief names anybody: a count, never a name, and a line from a fixed vocabulary',
    $named === [], json_encode($named));
$basement = xeric_sound_brief($T, $db, 'first_lutheran', $at('2026-11-05 20:00'));   // Thursday night
ok('a closed basement door is heard as one body at work, never as a table',
    $basement['heard'] === 1 && !$basement['voiced'] && !str_contains($basement['line'], 'voice'), $basement['line']);
$office = xeric_sound_brief($T, $db, 'first_lutheran', $at('2026-11-02 10:00'));    // office hours, visits
ok('one person whose week is talk is one voice, and voices are never words',
    $office['heard'] === 1 && $office['voiced'] && str_starts_with($office['line'], 'One voice, low')
    && str_contains($office['prompt'], 'no words that can be made out'), $office['line']);

xeric_world_state_set($db, 'weather:2026-11-02', 'Rain coming sideways off the river.');
$sw = xeric_sound_brief($T, $db, 'bluebird', $mon0710);
$db->exec("DELETE FROM world_state WHERE key = 'weather:2026-11-02'");
ok('the narrator\'s rain is heard on the roof, and is a different sound',
    str_starts_with($sw['line'], 'Rain on the roof') && $sw['fingerprint'] !== $s['fingerprint'], $sw['line']);
ok('outdoors rain is on everything, and rain that never comes is not heard',
    str_starts_with(xeric_sound_line(['kind' => 'site', 'weather' => 'A cold rain that settles in for the day.',
                                      'heard' => 0, 'voiced' => false, 'open' => true, 'dark' => false]),
                    'Rain on everything')
    && xeric_sound_weather('Close and heavy, rain that keeps threatening and never comes.', false) === '');

$s40 = xeric_sound_brief($T, $db, 'bluebird', $mon0740);
ok('half an hour later, the same sound', str_starts_with($s['fingerprint'], 'sound:')
    && strlen($s['fingerprint']) === 46 && $s40['fingerprint'] === $s['fingerprint']);
$sR = xeric_sound_brief($Tr, $db, 'bluebird', $mon0710);    // Ruth at the counter too
ok('a second person is a murmur, and a different sound',
    $sR['heard'] === 2 && $sR['voiced'] && str_contains($sR['line'], 'murmur') && $sR['fingerprint'] !== $s['fingerprint']);
$Trp = $Tr;
foreach ($Trp['cast']['characters'] as &$c) {
    if ($c['handle'] === 'pastor_dale') $c['week'] = [['days' => [1], 'from' => '07:00', 'to' => '08:00',
                                                       'where' => 'bluebird', 'doing' => 'a booth, the paper']];
}
unset($c);
ok('and three are the same murmur as two, rendered once',
    xeric_sound_brief($Trp, $db, 'bluebird', $mon0710)['fingerprint'] === $sR['fingerprint']);
$db->prepare('INSERT INTO deaths (handle, world_epoch, how, created_at) VALUES (?, ?, ?, ?)')
   ->execute(['dot', $mon0710['epoch'] - 86400, '', time()]);
$s0 = xeric_sound_brief($T, $db, 'bluebird', $mon0710);
$db->exec('DELETE FROM deaths');
ok('nobody is the room\'s own noise, and a third sound',
    $s0['heard'] === 0 && !$s0['voiced'] && $s0['line'] === 'A cooling pan ticking.'
    && $s0['fingerprint'] !== $s['fingerprint'] && $s0['fingerprint'] !== $sR['fingerprint'], $s0['line']);

$sk = xeric_sound_brief($Tx, $db, 'bluebird', $mon1600);
$sa = xeric_sound_brief($Tx, $db, 'bluebird', $mon0710);
$skBits = explode('. ', rtrim($sk['prompt'], '.'));
ok('a minor in the room forces the floor on the sound too, last, after the era',
    $sk['minor'] && $sk['rating'] === 'sfw' && end($skBits) === XERIC_SOUND_FLOOR_FRAMING
    && strpos($sk['prompt'], 'nothing anachronistic in earshot') < strpos($sk['prompt'], XERIC_SOUND_FLOOR_FRAMING)
    && $sa['rating'] === 'explicit' && str_ends_with($sa['prompt'], 'sound in keeping with the world\'s adult rating.'),
    $sk['prompt']);
$Tgrown = $Tx;
foreach ($Tgrown['cast']['characters'] as &$c) if ($c['handle'] === 'theo') $c['age'] = 30;
unset($c);
$sg = xeric_sound_brief($Tgrown, $db, 'bluebird', $mon1600);
ok('and the rating is in the fingerprint: a room with a child in it is never served from the grown-up row',
    $sg['heard'] === $sk['heard'] && $sg['voiced'] === $sk['voiced'] && $sg['rating'] === 'explicit'
    && $sg['fingerprint'] !== $sk['fingerprint']);
$kidAskS = xeric_sound_brief($Tx, $db, 'bluebird', $mon1600, ['ask' => 'something adult and suggestive']);
ok('an ask cannot argue its way past the floor of a sound either',
    str_ends_with($kidAskS['prompt'], XERIC_SOUND_FLOOR_FRAMING . '.')
    && strpos($kidAskS['prompt'], 'something adult') < strpos($kidAskS['prompt'], XERIC_SOUND_FLOOR_FRAMING));

$Tss = $T;
$Tss['media']['scene'] = ['sound' => ['style' => 'recorded on a cassette, a little hiss']];
$ss = xeric_sound_brief($Tss, $db, 'bluebird', $mon0710);
ok('the sound style lands before the rating, and is part of the sound',
    strpos($ss['prompt'], 'cassette') !== false
    && strpos($ss['prompt'], 'cassette') < strpos($ss['prompt'], 'wholesome everyday sound')
    && str_ends_with($ss['prompt'], 'wholesome everyday sound, nothing suggestive.')
    && $ss['fingerprint'] !== $s['fingerprint'] && $ss['line'] === $s['line']);

$Tmute = $T;
$Tmute['media']['scene'] = ['sound' => ['enabled' => false]];
ok('a world that turned its sound off still says what it would sound like, and queues none of it',
    xeric_sound_brief($Tmute, $db, 'bluebird', $mon0710)['enabled'] === false
    && xeric_scene_enqueue($db, xeric_sound_brief($Tmute, $db, 'bluebird', $mon0710)) === 0
    && xeric_scene_brief($Tmute, $db, 'bluebird', $mon0710)['enabled'] === true);
ok('and a world that turned scenes off has turned its sound off with them',
    xeric_sound_brief($Toff, $db, 'bluebird', $mon0710)['enabled'] === false);
$lie  = $s;
$lie['channel'] = 'look';
$lie2 = $b;
$lie2['channel'] = 'sound';
ok('no brief can be filed under the other channel',
    xeric_scene_enqueue($db, $lie) === 0 && xeric_scene_enqueue($db, $lie2) === 0 && xeric_scene_jobs($db) === []);

$heardAsk = [];
$audio = ['stub' => function (string $tag, array $brief, array $opts) use (&$heardAsk) {
    $heardAsk[] = [$tag, $opts];
    return ['bytes' => 'OggS-' . md5((string)$brief['fingerprint']), 'mime' => 'audio/ogg', 'kind' => 'audio',
            'meta' => ['seconds' => 30]];
}];
$sNow = xeric_sound_brief($T, $db);    // the room as it sounds now, so the fresh brief agrees
$sid  = xeric_scene_enqueue($db, $sNow);
$metered = [];
$r = xeric_scene_reap($T, $db, $dir, $audio, 2);
$srow = xeric_scene_of($db, 'bluebird', $sNow['fingerprint'], 'sound');
ok('a sound round trip stores an ogg file, on the sound channel',
    $sid > 0 && $r['done'] === 1 && $srow['status'] === 'done' && $srow['channel'] === 'sound'
    && $srow['kind'] === 'audio' && $srow['mime'] === 'audio/ogg' && $srow['light'] === ''
    && $srow['file'] === 'sound-bluebird-' . substr($sNow['fingerprint'], 6, 12) . '.ogg'
    && is_file($dir . '/' . $srow['file']) && json_decode((string)$srow['meta'], true) === ['seconds' => 30],
    json_encode([$r, $srow]));
ok('the machine was asked for audio, as a sound, with no picture along',
    $heardAsk[0][0] === 'sound' && $heardAsk[0][1]['kinds'] === ['audio'] && $heardAsk[0][1]['channel'] === 'sound'
    && !isset($heardAsk[0][1]['reference']) && !isset($heardAsk[0][1]['negative']));
ok('the ledger counts a scene that was a sound', count($metered) === 1 && $metered[0][0]['scenes'] === 1
    && ($metered[0][0]['sounds'] ?? 0) === 1);
ok('a sound is found on its own channel, and never in the picture frame',
    (int)xeric_scene_latest($db, 'bluebird', 'sound')['id'] === $sid && xeric_scene_latest($db, 'bluebird') === null
    && xeric_scene_of($db, 'bluebird', $sNow['fingerprint']) === null);

// A machine that cannot make sound says so, and is believed: DETECTION, not
// configuration. Each stub below has an address of its own, because the mark
// is written against the machine that said it.
$deafCalls = 0;
$deafA = ['base' => 'http://machine-a.example', 'stub' => function (string $tag) use (&$deafCalls) {
    $deafCalls++;
    return ['bytes' => 'PNG', 'mime' => 'image/png', 'kind' => 'image'];
}];
$sx = xeric_sound_brief($T, $db, 'beck_hardware');
xeric_scene_enqueue($db, $sx);
$r   = xeric_scene_reap($T, $db, $dir, $deafA, 1);
$sxr = xeric_scene_of($db, 'beck_hardware', $sx['fingerprint'], 'sound');
ok('a sound answered with a picture is the machine saying it cannot: no try spent, the row waits',
    $r['failed'] === 0 && $sxr['status'] === 'pending' && (int)$sxr['tries'] === 0 && $sxr['done_at'] === null
    && xeric_world_state_get($db, 'scene.sound.unsupported') === 'http://machine-a.example'
    && preg_grep('/machine-a\.example cannot make sound/', $r['notes']) !== [], json_encode([$r, $sxr]));
$r = xeric_scene_reap($T, $db, $dir, $deafA, 1);
ok('and that machine is not asked for sound again',
    $deafCalls === 1 && (int)xeric_scene_of($db, 'beck_hardware', $sx['fingerprint'], 'sound')['tries'] === 0
    && in_array('sound is waiting for a machine that can make it', $r['notes'], true)
    && !in_array('sound', xeric_scene_channels($T, $deafA, $db), true));
$deafB = ['base' => 'http://machine-b.example', 'stub' => fn() => ['_http' => 404, 'error' => 'no such route']];
$r = xeric_scene_reap($T, $db, $dir, $deafB, 1);
ok('another machine wipes the mark and is asked; a 4xx naming no asset is the same plain no',
    xeric_world_state_get($db, 'scene.sound.unsupported') === 'http://machine-b.example'
    && (int)xeric_scene_of($db, 'beck_hardware', $sx['fingerprint'], 'sound')['tries'] === 0
    && $r['failed'] === 0, json_encode($r));
$lx = xeric_scene_brief($T, $db, 'the_mill');
xeric_scene_enqueue($db, $lx);
$r = xeric_scene_reap($T, $db, $dir, $deafB, 1);
ok('while the same 4xx to a look is an ordinary failed try',
    (int)xeric_scene_of($db, 'the_mill', $lx['fingerprint'])['tries'] === 1
    && preg_grep('/refused the request \(HTTP 404\)/', $r['notes']) !== [], json_encode($r));
$hearsC = ['base' => 'http://machine-c.example', 'stub' => function (string $tag, array $brief, array $opts) {
    return $tag === 'sound' ? ['bytes' => 'OggS', 'mime' => 'audio/ogg', 'kind' => 'audio']
                            : ['bytes' => 'OggS', 'mime' => 'audio/ogg', 'kind' => 'audio'];
}];
$r = xeric_scene_reap($T, $db, $dir, $hearsC, 2);
ok('a machine that can make it makes it, and a room answered with a sound is still a failed try',
    xeric_scene_of($db, 'beck_hardware', $sx['fingerprint'], 'sound')['status'] === 'done'
    && (int)xeric_scene_of($db, 'the_mill', $lx['fingerprint'])['tries'] === 2
    && preg_grep('/asked for a room/', $r['notes']) !== [], json_encode($r));
// A sound that arrives wipes the mark, even one written against this machine:
// a job it was already making, finished on a poll.
$sp2 = xeric_sound_brief($T, $db, 'first_lutheran');
$pid = xeric_scene_enqueue($db, $sp2);
$db->prepare("UPDATE scene_jobs SET status = 'working', remote_job = 'p1', done_at = ? WHERE id = ?")->execute([time(), $pid]);
xeric_world_state_set($db, 'scene.sound.unsupported', 'http://machine-c.example');
xeric_scene_reap($T, $db, $dir, $hearsC, 1);
ok('and a sound that does arrive wipes what was written down',
    xeric_scene_of($db, 'first_lutheran', $sp2['fingerprint'], 'sound')['status'] === 'done'
    && (string)(xeric_world_state_get($db, 'scene.sound.unsupported') ?? '') === '');
ok('a sound of a type this engine will not keep is refused', (function () use ($s) {
    try { xeric_scene_render(['stub' => fn() => ['kind' => 'audio', 'mime' => 'audio/flac', 'bytes' => 'fLaC']], $s); }
    catch (RuntimeException $e) { return true; }
    return false;
})());
ok('the common aliases fold in, and each sound keeps its own extension',
    xeric_scene_audio_mime('audio/x-wav') === 'audio/wav' && xeric_scene_audio_mime('audio/mp3') === 'audio/mpeg'
    && xeric_scene_audio_mime('audio/ogg; codecs=opus') === 'audio/ogg'
    && xeric_scene_ext('audio/mpeg') === '.mp3' && xeric_scene_ext('audio/mp4') === '.m4a'
    && xeric_scene_ext('audio/wav') === '.wav' && xeric_scene_ext('audio/webm') === '.weba');
ok('an answer naming no kind is audio when a sound was asked for',
    xeric_scene_render(['stub' => fn() => ['bytes' => 'ID3', 'mime' => 'audio/mpeg']], $s)['kind'] === 'audio');

$db->exec('DELETE FROM scene_jobs');
$imgSeen = [];
$imgStub = ['kind' => 'image', 'stub' => function (string $tag, array $brief, array $opts) use (&$imgSeen) {
    $imgSeen[] = $tag;
    return ['bytes' => 'PNG-' . md5((string)$brief['fingerprint']), 'mime' => 'image/png', 'kind' => 'image'];
}];
$hs = xeric_sound_brief($T, $db, 'bluebird', $mon0710);
$hl = xeric_scene_brief($T, $db, 'bluebird', $mon0710);
xeric_scene_enqueue($db, $hs);    // the sound first: the oldest thing in the queue
xeric_scene_enqueue($db, $hl);
$r   = xeric_scene_reap($T, $db, $dir, $imgStub, 1);
$hsr = xeric_scene_of($db, 'bluebird', $hs['fingerprint'], 'sound');
ok('an image machine draws the look and leaves the sound waiting, untried',
    $r['done'] === 1 && $imgSeen === ['scene'] && $hsr['status'] === 'pending' && (int)$hsr['tries'] === 0
    && xeric_scene_of($db, 'bluebird', $hl['fingerprint'])['status'] === 'done'
    && in_array('sound is waiting for a machine that can make it', $r['notes'], true), json_encode($r));
ok('and the channels say why',
    xeric_scene_channels($T, ['kind' => 'image', 'base' => 'x']) === ['look']
    && xeric_scene_channels($T, ['kind' => 'generic', 'base' => 'x']) === ['look', 'sound']
    && xeric_scene_channels($T, null) === [] && xeric_scene_channels($Toff, ['kind' => 'generic', 'base' => 'x']) === []
    && xeric_scene_channels($Tmute, ['kind' => 'generic', 'base' => 'x']) === ['look']);


// ---------------------------------------------------------------------------
echo "\n# where an answer may send us\n";
// ---------------------------------------------------------------------------

$far = ['base' => 'https://scenes.example', 'kind' => 'generic'];
ok('the cloud metadata address, a LAN box and loopback are never fetched for a machine elsewhere',
    !xeric_scene_url_ok('http://169.254.169.254/latest/meta-data/', $far)
    && !xeric_scene_url_ok('http://10.0.0.5/admin', $far) && !xeric_scene_url_ok('http://192.168.1.1/', $far)
    && !xeric_scene_url_ok('http://127.0.0.1:8080/x', $far) && !xeric_scene_url_ok('http://100.64.0.9/x', $far));
ok('nor their IPv6 and IPv4-mapped spellings',
    !xeric_scene_url_ok('http://[::1]/x', $far) && !xeric_scene_url_ok('http://[fe80::1]/x', $far)
    && !xeric_scene_url_ok('http://[fd00::5]/x', $far) && !xeric_scene_url_ok('http://[::ffff:169.254.169.254]/x', $far)
    && !xeric_scene_url_ok('http://[::ffff:7f00:1]/x', $far));
ok('a name that does not resolve is refused, and so is anything not http',
    !xeric_scene_url_ok('https://assets.nowhere.invalid/room.glb', $far)
    && !xeric_scene_url_ok('ftp://93.184.216.34/x', $far) && !xeric_scene_url_ok('http:///x', $far));
ok('a public address is fine, and so is the machine\'s own host, loopback or not',
    xeric_scene_url_ok('https://93.184.216.34/room.png', $far)
    && xeric_scene_url_ok('http://127.0.0.1:9001/files/room.png', ['base' => 'http://127.0.0.1:8000'])
    && xeric_scene_url_ok('http://LOCALHOST/r.png', ['base' => 'http://localhost:8000']));
$db->exec('DELETE FROM scene_jobs');
$ssrfB = xeric_scene_brief($T, $db, 'beck_hardware');
xeric_scene_enqueue($db, $ssrfB);
$r = xeric_scene_reap($T, $db, $dir, ['stub' => fn() => ['kind' => 'splat',
                                                          'url' => 'http://169.254.169.254/latest/meta-data/']], 1);
$ssr = xeric_scene_of($db, 'beck_hardware', $ssrfB['fingerprint']);
ok('a machine that answers with the metadata address gets a failed try and a note naming the host, and no file',
    (int)$ssr['tries'] === 1 && $ssr['status'] === 'pending' && $ssr['file'] === ''
    && preg_grep('/refused to fetch from 169\.254\.169\.254/', $r['notes']) !== [], json_encode($r));

// A SPLAT IS KEPT AS PLY: a stub answers three splats in the compact format,
// and what lands on disk is the PLY engine/splat.php makes of them.
ok('a PLY type is stored as .ply, whichever spelling',
    xeric_scene_ext('application/ply', 'splat') === '.ply' && xeric_scene_ext('model/ply') === '.ply'
    && xeric_scene_ext('application/octet-stream', 'splat') === '.splat');
$db->exec('DELETE FROM scene_jobs');
$three = '';
foreach ([[0, 0, -1], [0, 0, -5], [1, 0, -3]] as $p) $three .= pack('g6', ...$p, ...[0.1, 0.1, 0.1]) . pack('C8', 200, 100, 50, 255, 255, 128, 128, 128);
$splB = xeric_scene_brief($T, $db, 'beck_hardware');
xeric_scene_enqueue($db, $splB);
xeric_scene_reap($T, $db, $dir, ['stub' => fn() => ['kind' => 'splat', 'bytes' => $three, 'mime' => 'application/octet-stream']], 1);
$spl = xeric_scene_of($db, 'beck_hardware', $splB['fingerprint']);
$onDisk = (string)@file_get_contents($dir . '/' . (string)$spl['file']);
ok('a .splat that arrives is stored as PLY: .ply, application/ply, the converted bytes, and the meta says so',
    $spl['status'] === 'done' && str_ends_with((string)$spl['file'], '.ply') && $spl['mime'] === 'application/ply'
    && $onDisk === xeric_splat_to_ply($three, 'application/octet-stream', '.splat')
    && str_starts_with($onDisk, "ply\nformat binary_little_endian 1.0\nelement vertex 3\n")
    && (json_decode((string)$spl['meta'], true)['ply'] ?? '') === 'converted from .splat', json_encode($spl));
$db->exec('DELETE FROM scene_jobs');
xeric_scene_enqueue($db, $splB);
$spz = "\x1f\x8b\x08\x00" . str_repeat("\0", 28);
xeric_scene_reap($T, $db, $dir, ['stub' => fn() => ['kind' => 'splat', 'bytes' => $spz, 'mime' => 'application/octet-stream']], 1);
$spl = xeric_scene_of($db, 'beck_hardware', $splB['fingerprint']);
ok('an SPZ is kept exactly as it came, under .splat, and the meta says why it was not converted',
    $spl['status'] === 'done' && str_ends_with((string)$spl['file'], '.splat')
    && (string)@file_get_contents($dir . '/' . (string)$spl['file']) === $spz
    && str_contains((string)(json_decode((string)$spl['meta'], true)['ply'] ?? ''), 'SPZ'), json_encode($spl));
$db->exec('DELETE FROM scene_jobs');

// ---------------------------------------------------------------------------
echo "\n# ahead of being looked at\n";
// ---------------------------------------------------------------------------

$local  = ['stub' => fn() => ['bytes' => 'x', 'mime' => 'image/png']];      // costs nothing: the owner's own
$billed = ['base' => 'https://scenes.example', 'kind' => 'generic'];
$Tpre   = fn(string $mode): array => array_replace_recursive($T, ['media' => ['scene' => ['prerender' => $mode]]]);
ok('unsaid, the owner\'s own machine draws the room you are in', xeric_scene_prerender($T, $local) === 'here');
ok('and a machine that bills draws nothing nobody asked for', xeric_scene_prerender($T, $billed) === 'off');
ok('the template\'s own word is final, even on a machine that bills',
    xeric_scene_prerender($Tpre('all'), $billed) === 'all' && xeric_scene_prerender($Tpre('off'), $local) === 'off');
ok('a word it does not know is no word at all', xeric_scene_prerender($Tpre('everything'), $local) === 'here');
ok('no machine, or scenes turned off, is off',
    xeric_scene_prerender($T, null) === 'off' && xeric_scene_prerender($Toff, $local) === 'off');

$db->exec('DELETE FROM scene_jobs');
$mon1000 = $at('2026-11-02 10:00');
$bf = xeric_scene_backfill($T, $db, $mon1000, $local);
ok('here: the player\'s own room, looked at and listened to',
    $bf === ['enqueued' => 2, 'keys' => ['bluebird']]
    && count(xeric_scene_jobs($db, 'pending', 'look')) === 1 && count(xeric_scene_jobs($db, 'pending', 'sound')) === 1,
    json_encode($bf));
ok('the look written down with the light it was asked in',
    xeric_scene_jobs($db, null, 'look')[0]['light'] === 'morning light');
ok('and the same offer a minute later adds nothing', xeric_scene_backfill($T, $db, $mon1000, $local)['enqueued'] === 0);
$bo = xeric_scene_backfill($Tpre('occupied'), $db, $mon1000, $local);
ok('occupied: and every room with somebody in it now (the mill stands empty)',
    $bo['keys'] === ['bluebird', 'first_lutheran', 'beck_hardware'] && $bo['enqueued'] === 4, json_encode($bo));
$Tghost = $Tpre('all');
$Tghost['cast']['characters'][] = ['handle' => 'ghost', 'display_name' => 'Nobody Yet', 'age' => 40, 'out' => true];
$Tghost['places'][] = ['key' => 'ghost_house', 'name' => 'the Empty House', 'kind' => 'home', 'residents' => ['ghost']];
$ba = xeric_scene_backfill($Tghost, $db, $mon1000, $local);
ok('all: every room in the story, and not a house whose people have not arrived',
    $ba['keys'] === ['bluebird', 'first_lutheran', 'beck_hardware', 'the_mill'] && $ba['enqueued'] === 2,
    json_encode($ba));

$db->exec('DELETE FROM scene_jobs');
ok('off is off: nothing queued, not even the room you are in',
    xeric_scene_backfill($Tpre('off'), $db, $mon1000, $local) === ['enqueued' => 0, 'keys' => []]
    && xeric_scene_backfill($T, $db, $mon1000, $billed) === ['enqueued' => 0, 'keys' => []]
    && xeric_scene_jobs($db) === []);
ok('an image machine is offered no sound to make',
    xeric_scene_backfill($T, $db, $mon1000, ['kind' => 'image'] + $local)['enqueued'] === 1
    && xeric_scene_jobs($db, null, 'sound') === []);
$db->exec('DELETE FROM scene_jobs');
ok('nor is a world that turned its sound off',
    xeric_scene_backfill($Tmute, $db, $mon1000, $local)['enqueued'] === 1 && xeric_scene_jobs($db, null, 'sound') === []);
xeric_player_move($db, null);
$db->exec('DELETE FROM scene_jobs');
ok('a player who is nowhere has no room to draw ahead',
    xeric_scene_backfill($T, $db, $mon1000, $local) === ['enqueued' => 0, 'keys' => []]);
xeric_player_move($db, 'bluebird', $mon0710['epoch'] - 600);

$both = ['stub' => function (string $tag, array $brief, array $opts) {
    return $tag === 'sound'
        ? ['bytes' => 'OggS', 'mime' => 'audio/ogg', 'kind' => 'audio']
        : ['bytes' => 'PNG-' . md5((string)$brief['fingerprint']), 'mime' => 'image/png', 'kind' => 'image'];
}];
$up = [];
$a1 = xeric_scene_ahead($T, $db, $dir, $both, 2, $up);
ok('one pass ahead: the room you are in, drawn and heard, with nobody looking',
    $a1['mode'] === 'here' && $a1['enqueued'] === 2 && $a1['done'] === 2 && $a1['keys'] === ['bluebird']
    && count($up) === 1, json_encode($a1));
$a2 = xeric_scene_ahead($T, $db, $dir, $both, 2, $up);
ok('and the next pass, the room unchanged, stops at the cheap gate: no brief, no probe, no reap',
    $a2['unchanged'] === true && $a2['enqueued'] === 0 && $a2['done'] === 0 && count($up) === 1
    && xeric_world_state_get($db, 'scene.ahead') !== null);
$k0 = xeric_scene_ahead_key($T, $db, $mon0710);
ok('the gate\'s key holds still across the minute, and moves with the light, the company and the sky',
    $k0 === xeric_scene_ahead_key($T, $db, $mon0740)
    && $k0 !== xeric_scene_ahead_key($T, $db, $at('2026-11-02 12:30'))
    && $k0 !== xeric_scene_ahead_key($Tr, $db, $mon0710)
    && (function () use ($T, $db, $mon0710, $k0) {
        xeric_world_state_set($db, 'weather:2026-11-02', 'Rain coming sideways off the river.');
        $k = xeric_scene_ahead_key($T, $db, $mon0710);
        $db->exec("DELETE FROM world_state WHERE key = 'weather:2026-11-02'");
        return $k !== $k0;
    })());
$gid = (int)xeric_scene_enqueue($db, xeric_scene_brief($T, $db, 'the_mill', $mon0710));
$a2b = xeric_scene_ahead($T, $db, $dir, $both, 2, $up);
ok('a pending row opens the gate whatever the key says: it still needs drawing',
    $a2b['unchanged'] === false && $gid > 0 && xeric_scene_jobs($db, 'pending') === [], json_encode($a2b));
xeric_player_move($db, 'beck_hardware', $mon0710['epoch']);
$a2c = xeric_scene_ahead($T, $db, $dir, $both, 2, $up);
xeric_player_move($db, 'bluebird', $mon0710['epoch'] - 600);
ok('and so does walking into another room', $a2c['unchanged'] === false && $a2c['keys'] === ['beck_hardware'],
    json_encode($a2c));
$upB = [];
$a3  = xeric_scene_ahead($T, $db, $dir, $billed, 2, $upB);
ok('a machine that bills is never even asked whether it is up, unless the world said so',
    $a3['mode'] === 'off' && $upB === [] && $a3['enqueued'] === 0);
$down = ['base' => 'http://127.0.0.1:9', 'kind' => 'generic'];    // loopback, so it would draw; nothing listens
$upD  = [];
$a4   = xeric_scene_ahead($T, $db, $dir, $down, 2, $upD);
$a5   = xeric_scene_ahead($T, $db, $dir, $down, 2, $upD);
ok('a machine that does not answer is asked once a pass, and nothing is queued for it',
    $a4['mode'] === 'here' && $a4['enqueued'] === 0 && array_values($upD) === [false]
    && in_array('no scene machine is answering', $a5['notes'], true), json_encode([$a4, $upD]));
$db->exec('DELETE FROM scene_jobs');
$upS = [json_encode(['http://127.0.0.1:9', 'generic', false]) => true];
$a6  = xeric_scene_ahead($T, $db, $dir, $down, 2, $upS);
ok('and the pass\'s answer is trusted: the reap is told, and does not ask again',
    $a6['enqueued'] === 2 && preg_grep('/did not answer in JSON/', $a6['notes']) !== []
    && !in_array('no scene machine is answering', $a6['notes'], true), json_encode($a6));

// ---------------------------------------------------------------------------
echo "\n# the room through its day\n";
// ---------------------------------------------------------------------------

$db->exec('DELETE FROM scene_jobs');
foreach (['2026-11-02 18:00', '2026-11-02 07:10', '2026-11-02 12:30'] as $h) {
    xeric_scene_enqueue($db, xeric_scene_brief($T, $db, 'bluebird', $at($h)));
}
xeric_scene_enqueue($db, xeric_sound_brief($T, $db, 'bluebird', $mon0710));    // a sound is not a light
$r   = xeric_scene_reap($T, $db, $dir, $both, 4);
$day = xeric_scene_day($db, 'bluebird');
ok('three renders at three hours are the room through its day, in the day\'s own order',
    $r['done'] === 4 && array_keys($day) === ['morning light', 'noon', 'golden hour']
    && array_column($day, 'status') === ['done', 'done', 'done']
    && array_column($day, 'light') === ['morning light', 'noon', 'golden hour'], json_encode([$r, array_keys($day)]));
$noon2 = xeric_scene_brief($Ti, $db, 'bluebird', $at('2026-11-02 12:45'));    // a jukebox since: same light, new look
$nid2  = xeric_scene_enqueue($db, $noon2);
xeric_scene_reap($T, $db, $dir, $both, 1);
$day2 = xeric_scene_day($db, 'bluebird');
ok('a newer view in the same light takes its place in the strip',
    count($day2) === 3 && (int)$day2['noon']['id'] === $nid2);
$db->prepare("INSERT INTO scene_jobs (key, fingerprint, status, kind, file, mime, created_at, done_at)
              VALUES ('bluebird', ?, 'done', 'image', 'old.png', 'image/png', 1, ?)")
   ->execute([sha1('before the light was written down'), time() + 60]);
ok('a view from before the light was written down sits the strip out',
    count(xeric_scene_day($db, 'bluebird')) === 3 && xeric_scene_day($db, 'first_lutheran') === []);

// ---------------------------------------------------------------------------
echo "\n# an older world's table\n";
// ---------------------------------------------------------------------------

$oldP = $dir . '-old.sqlite';
foreach ([$oldP, $oldP . '-wal', $oldP . '-shm'] as $f) @unlink($f);
$old = new PDO('sqlite:' . $oldP);
$old->exec("CREATE TABLE scene_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL,
    fingerprint TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', tries INTEGER NOT NULL DEFAULT 0,
    kind TEXT NOT NULL DEFAULT '', file TEXT NOT NULL DEFAULT '', url TEXT, mime TEXT NOT NULL DEFAULT '',
    prompt TEXT NOT NULL DEFAULT '', meta TEXT NOT NULL DEFAULT '{}', remote_job TEXT,
    created_at INTEGER NOT NULL, done_at INTEGER, UNIQUE(key, fingerprint))");
$old->exec("INSERT INTO scene_jobs (key, fingerprint, status, kind, file, mime, created_at, done_at)
            VALUES ('bluebird', 'f00d', 'done', 'image', 'scene-bluebird-f00d.png', 'image/png', 1, 2)");
$old = null;
$mig  = xeric_state_open($oldP);
$cols = xeric_state_columns($mig, 'scene_jobs');
$was  = xeric_scene_latest($mig, 'bluebird');
ok('a table from before channels grows them, and every old row reads as a look of unrecorded light',
    in_array('channel', $cols, true) && in_array('light', $cols, true)
    && $was !== null && $was['channel'] === 'look' && $was['light'] === '', json_encode($cols));
$mig = null;
foreach ([$oldP, $oldP . '-wal', $oldP . '-shm'] as $f) @unlink($f);

// ---------------------------------------------------------------------------
echo "\n# the contract, over a real socket\n";
// ---------------------------------------------------------------------------
//
// Everything above speaks to stubs. This speaks the generic contract to a
// machine on loopback — a throwaway `php -S` that answers the way the header
// of xeric_scene_render says a machine may — so the bytes on the wire are the
// documented bytes. SKIPS rather than fails when no server can be started:
// the stubs above still hold every rule; this holds the wire.

$fake = $dir . '-machine';
@mkdir($fake, 0775, true);
file_put_contents($fake . '/router.php', <<<'ROUTER'
<?php
// A scene machine that keeps a log of what it was asked and answers in kind.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string)file_get_contents('php://input');
$hdrs = array_change_key_case(getallheaders(), CASE_LOWER);
file_put_contents(__DIR__ . '/asked.jsonl', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path,
    'key' => $hdrs['x-api-key'] ?? '', 'auth' => $hdrs['authorization'] ?? '',
    'body' => json_decode($body, true)]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
$port = (int)$_SERVER['SERVER_PORT'];
if ($path === '/') { echo '{"ok":true}'; return; }
if ($path === '/files/hum.ogg') {
    if (($hdrs['x-api-key'] ?? '') !== 'k1') { http_response_code(401); echo '{}'; return; }
    header('Content-Type: audio/ogg'); echo 'OggS-THE-HUM'; return;
}
if ($path === '/v1/images/generations') { echo json_encode(['data' => [['b64_json' => base64_encode('STILL')]]]); return; }
if ($path === '/v1/scenes/j1') {
    echo json_encode(['kind' => 'image', 'mime' => 'image/png', 'b64' => base64_encode('LATER-STILL')]); return;
}
if ($path === '/v1/scenes') {
    $j = json_decode($body, true) ?: [];
    if (str_contains((string)($j['prompt'] ?? ''), 'ASK ME LATER')) {
        http_response_code(202); echo json_encode(['status' => 'pending', 'job' => 'j1', 'retry_after_s' => 1]); return;
    }
    if (($j['channel'] ?? '') === 'sound') {
        echo json_encode(['kind' => 'audio', 'mime' => 'audio/ogg', 'url' => 'http://127.0.0.1:' . $port . '/files/hum.ogg']);
        return;
    }
    echo json_encode(['kind' => 'pano', 'mime' => 'image/jpeg', 'b64' => base64_encode('PANO-' . ($j['seed'] ?? 0)),
                      'meta' => ['seed_used' => $j['seed'] ?? 0, 'vendor_id' => 'v-1'], 'usage' => ['credits' => 2]]);
    return;
}
http_response_code(404); echo '{}';
ROUTER);
$sock = @stream_socket_server('tcp://127.0.0.1:0');
$port = $sock ? (int)substr((string)strrchr((string)stream_socket_get_name($sock, false), ':'), 1) : 0;
if ($sock) fclose($sock);
$proc = $port > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $fake . '/router.php'],
                                [0 => ['pipe', 'r'], 1 => ['file', $fake . '/server.log', 'a'], 2 => ['file', $fake . '/server.log', 'a']],
                                $pipes) : false;
$live = false;
for ($i = 0; is_resource($proc) && $i < 60 && !$live; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($c) { fclose($c); $live = true; } else usleep(50000);
}
$asked = function () use ($fake): array {
    $out = [];
    foreach (@file($fake . '/asked.jsonl', FILE_IGNORE_NEW_LINES) ?: [] as $l) $out[] = json_decode($l, true);
    return $out;
};

if (!$live) {
    echo "ok   - SKIPPED: no loopback server could be started here; the stubs above still hold every rule\n";
} else {
    $machine = ['base' => 'http://127.0.0.1:' . $port, 'kind' => 'generic', 'key' => 'k1', 'auth' => 'x-api-key'];
    ok('a real machine on loopback is up, and is the owner\'s own electricity',
        xeric_scene_up($machine) && !xeric_scene_costly($machine));

    $wb = xeric_scene_brief($Ti, $db, 'bluebird', $mon0710);
    $wr = xeric_scene_render($machine, $wb, ['kinds' => ['pano', 'image'], 'quality' => 'draft',
                                             'reference' => ['bytes' => 'LAST-TIME', 'mime' => 'image/png',
                                                             'fingerprint' => 'feed']]);
    $sent = array_values(array_filter($asked(), fn($a) => $a['path'] === '/v1/scenes'))[0] ?? [];
    ok('the request is the documented body, the key in the header the machine asked for',
        ($sent['method'] ?? '') === 'POST' && ($sent['key'] ?? '') === 'k1' && ($sent['auth'] ?? '') === ''
        && $sent['body']['channel'] === 'look' && $sent['body']['kind_wanted'] === ['pano', 'image']
        && $sent['body']['size'] === [4096, 2048] && $sent['body']['quality'] === 'draft'
        && $sent['body']['prompt'] === $wb['prompt'] && $sent['body']['fingerprint'] === $wb['fingerprint']
        && $sent['body']['seed'] === $wb['seeds']['place']['interior'] && $sent['body']['timeout_s'] === 300,
        json_encode($sent));
    ok('with the brief whole, bearings and all, and the last picture of the room',
        $sent['body']['brief']['who'][0]['bearing'] === $wb['who'][0]['bearing']
        && $sent['body']['reference_image'] === ['mime' => 'image/png', 'b64' => base64_encode('LAST-TIME')]
        && $sent['body']['reference_fingerprint'] === 'feed');
    ok('and the answer read back: kind, type, bytes, meta and usage as the machine said them',
        $wr['kind'] === 'pano' && $wr['mime'] === 'image/jpeg' && $wr['bytes'] === 'PANO-' . $wb['seeds']['place']['interior']
        && $wr['meta'] === ['seed_used' => $wb['seeds']['place']['interior'], 'vendor_id' => 'v-1']
        && $wr['usage'] === ['credits' => 2] && $wr['pending'] === null, json_encode($wr));

    $later = $wb;
    $later['prompt'] .= ' ASK ME LATER';
    $wl = xeric_scene_render($machine, $later);
    $wp = xeric_scene_render($machine, ['key' => 'bluebird', 'fingerprint' => $wb['fingerprint']], ['job' => (string)$wl['pending']]);
    $polls = array_values(array_filter($asked(), fn($a) => $a['path'] === '/v1/scenes/j1'));
    ok('a 202 is "later" with the job named, and the poll is a GET on the job',
        $wl['pending'] === 'j1' && $wl['bytes'] === '' && count($polls) === 1 && $polls[0]['method'] === 'GET'
        && ($polls[0]['key'] ?? '') === 'k1' && $wp['kind'] === 'image' && $wp['bytes'] === 'LATER-STILL');

    $ws = xeric_scene_render($machine, xeric_sound_brief($T, $db, 'bluebird', $mon0710), ['kinds' => ['audio']]);
    $sentS = array_values(array_filter($asked(), fn($a) => $a['path'] === '/v1/scenes'
                                                            && ($a['body']['channel'] ?? '') === 'sound'))[0] ?? [];
    $fetch = array_values(array_filter($asked(), fn($a) => $a['path'] === '/files/hum.ogg'));
    ok('a sound is asked for as audio, and a url answer is fetched at once with the same key',
        $sentS['body']['kind_wanted'] === ['audio'] && !isset($sentS['body']['size'])
        && $ws['kind'] === 'audio' && $ws['mime'] === 'audio/ogg' && $ws['bytes'] === 'OggS-THE-HUM'
        && count($fetch) === 1 && ($fetch[0]['key'] ?? '') === 'k1', json_encode([$sentS['body']['kind_wanted'] ?? null, $ws['kind']]));
    $bare = ['auth' => 'bearer', 'key' => ''] + $machine;
    ok('and a url that will not open without the key is a failure, not an empty sound', (function () use ($bare, $T, $db, $mon0710) {
        try { xeric_scene_render($bare, xeric_sound_brief($T, $db, 'bluebird', $mon0710)); }
        catch (RuntimeException $e) { return str_contains($e->getMessage(), 'could not be fetched'); }
        return false;
    })());

    $still = ['kind' => 'image'] + $machine;
    $wi = xeric_scene_render($still, $wb, ['reference' => ['bytes' => 'LAST-TIME', 'mime' => 'image/png', 'fingerprint' => 'feed']]);
    $sentI = array_values(array_filter($asked(), fn($a) => $a['path'] === '/v1/images/generations'))[0] ?? [];
    ok('an image machine is spoken to in the images shape, with the room\'s seed and nowhere to put a reference',
        $wi['kind'] === 'image' && $wi['bytes'] === 'STILL' && $sentI['body']['prompt'] === $wb['prompt']
        && $sentI['body']['seed'] === $wb['seeds']['place']['interior'] && !isset($sentI['body']['reference_image'])
        && $sentI['body']['response_format'] === 'b64_json', json_encode($sentI));
    ok('and is never asked to make a sound or to look up a job', (function () use ($still, $T, $db, $mon0710, $wb) {
        $n = 0;
        try { xeric_scene_render($still, xeric_sound_brief($T, $db, 'bluebird', $mon0710)); } catch (RuntimeException $e) { $n++; }
        try { xeric_scene_render($still, $wb, ['job' => 'j1']); } catch (RuntimeException $e) { $n++; }
        return $n === 2;
    })());
}
if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
foreach (glob($fake . '/*') ?: [] as $f) @unlink($f);
@rmdir($fake);

// ---------------------------------------------------------------------------

foreach ($envKeep as $k => $v) { if ($v !== false) putenv($k . '=' . $v); }
foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
@rmdir($dir);
foreach ([$dbP, $dbP . '-wal', $dbP . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
