<?php
/**
 * opening-test.php — the first page. `php engine/tests/opening-test.php`, exit 0.
 *
 * NO MODEL: everything here is the dead-battery half — the scene the schedules
 * pick, the default prose in the owner's shape ("You are Judy, a ____. You
 * live in ____. Recently, ____."), the concrete start moment launch derives,
 * and the once-and-only-once apply that puts the curtain line in the record.
 * The one model-shaped check uses the forge's stub seam.
 */

declare(strict_types=1);

require_once __DIR__ . '/../opening.php';
require_once __DIR__ . '/../clock.php';
require_once dirname(__DIR__, 2) . '/forge/forge.php';

$FAILED = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $name\n"; return; }
    $FAILED++;
    echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
}

$T = xeric_world_load(__DIR__ . '/../fixtures/milldale.json');
// A fixed wall clock, so the scan and the starts are the same every run:
// a Tuesday evening in the world's own timezone.
$FROM = (new DateTimeImmutable('2026-08-04 21:00', new DateTimeZone('America/New_York')))->getTimestamp();

$DBFILES = [];
function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-opening-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    $db = xeric_state_open($path);
    xeric_state_seed($db, $GLOBALS['T']);
    return $db;
}
$GLOBALS['T'] = $T;

// ---------------------------------------------------------------------------
// Reading a template's opening
// ---------------------------------------------------------------------------

ok('no opening key is no opening', xeric_opening($T) === null);
ok('an opening with no prose is no opening',
    xeric_opening($T + ['opening' => ['prose' => '  ', 'scene' => ['place' => 'bluebird']]]) === null);

$o = xeric_opening($T + ['opening' => ['prose' => 'You are Walt.',
    'scene' => ['place' => 'a_room_that_was_rerolled_away', 'hhmm' => '19:00']]]);
ok('a scene in a ghost room degrades to prose-only, not to a modal about nowhere',
    $o !== null && $o['scene'] === null && $o['prose'] === 'You are Walt.');

$o = xeric_opening($T + ['opening' => ['prose' => 'You are Walt.',
    'scene' => ['place' => 'bluebird', 'hhmm' => 'not a time', 'dow' => 99,
                'who' => ['ruth', 'nobody_of_that_name', 'ruth']]]]);
ok('a scene is normalised: bad hour to the fallback, bad day to any, who to real cast, once each',
    $o !== null && $o['scene'] !== null
    && $o['scene']['hhmm'] === XERIC_OPENING_HHMM && $o['scene']['dow'] === null
    && $o['scene']['who'] === ['ruth']);

// ---------------------------------------------------------------------------
// The scene the schedules pick
// ---------------------------------------------------------------------------

$s = xeric_opening_scene_pick($T, $FROM);
ok('milldale opens where its week is genuinely fullest: Sunday service',
    $s !== null && $s['place'] === 'first_lutheran' && $s['dow'] === 0 && $s['hhmm'] === '10:00',
    json_encode($s));
ok('the curtain line names who is actually in the room',
    $s !== null && $s['who'] !== [] && str_contains((string)$s['line'], 'Ruth'));
ok('the pick is deterministic for a fixed moment',
    xeric_opening_scene_pick($T, $FROM) === $s);

$empty = $T;
foreach ($empty['cast']['characters'] as &$c) $c['week'] = [];
unset($c);
$se = xeric_opening_scene_pick($empty, $FROM);
ok('a week that puts nobody anywhere public still opens SOMEWHERE, at the fallback hour',
    $se !== null && $se['place'] !== '' && $se['hhmm'] === XERIC_OPENING_HHMM);

// ---------------------------------------------------------------------------
// The default page — the owner's shape, on a dead battery
// ---------------------------------------------------------------------------

$d = xeric_opening_default($T, ['events' => [
    ['title' => 'A pie went missing from the fair'], ['title' => 'Harlan missed church twice']]], $FROM);
$p = (string)$d['prose'];
ok('you are …', str_contains($p, 'You are Walt, a retired line foreman'));
ok('you live in …', str_contains($p, 'You live in Milldale, Ohio'));
ok('recently, …', str_contains($p, 'Recently:') && str_contains($p, 'A pie went missing'));
ok('the town talk rides along', str_contains($p, 'a light in the mill office'));
ok('and the scene it opens on', str_contains($p, 'It begins on Sunday at First Lutheran'));
ok('the default carries the scene for launch to resolve', ($d['scene']['place'] ?? '') === 'first_lutheran');

// ---------------------------------------------------------------------------
// The concrete start moment
// ---------------------------------------------------------------------------

$t2 = $T;
$t2['opening'] = $d;
$starts = xeric_opening_starts($t2, $FROM);
ok('starts lands on the scene day and hour, in the future',
    $starts !== null
    && (new DateTimeImmutable($starts, new DateTimeZone('America/New_York')))->format('w H:i') === '0 10:00'
    && (new DateTimeImmutable($starts, new DateTimeZone('America/New_York')))->getTimestamp() >= $FROM + 3600,
    (string)$starts);
ok('what launch writes is what the clock reads back',
    xeric_clock_starts(array_merge($t2, ['setting' => ['starts' => $starts] + $t2['setting']]))
    === (new DateTimeImmutable($starts, new DateTimeZone('America/New_York')))->getTimestamp());
ok('no scene, no starts', xeric_opening_starts($T, $FROM) === null);

// ---------------------------------------------------------------------------
// The apply: once, in the record, and the player at the door of it
// ---------------------------------------------------------------------------

$db = fresh_db('apply');
$now = xeric_world_now($t2, $FROM);
$r = xeric_opening_apply($db, $t2, $now);
ok('applied writes the curtain event at the world\'s first moment',
    $r['applied'] && $r['event']
    && count(xeric_events_recent($db, 5)) === 1
    && (int)xeric_events_recent($db, 1)[0]['world_epoch'] === $now['epoch']);
ok('the player is stood at the door of it',
    $r['placed'] && xeric_world_state_get($db, 'player.where') === 'first_lutheran');
$r2 = xeric_opening_apply($db, $t2, $now);
ok('a second apply writes nothing twice',
    !$r2['applied'] && count(xeric_events_recent($db, 5)) === 1);

$db2 = fresh_db('walked');
xeric_world_state_set($db2, 'player.where', 'bluebird');
$r3 = xeric_opening_apply($db2, $t2, $now);
ok('feet already chosen are not moved', !$r3['placed']
    && xeric_world_state_get($db2, 'player.where') === 'bluebird');

$db3 = fresh_db('floor');
$t3 = $t2;
$t3['opening']['scene']['who']  = ['theo'];
$t3['opening']['scene']['line'] = 'We made love till three and Theo saw it all.';
$r4 = xeric_opening_apply($db3, $t3, $now);
ok('the age floor holds on the curtain line: dropped, marker still set',
    $r4['applied'] && !$r4['event'] && xeric_events_recent($db3, 5) === []
    && xeric_world_state_get($db3, XERIC_OPENING_MARKER) !== null);

$db4 = fresh_db('proseonly');
$r5 = xeric_opening_apply($db4, ['opening' => ['prose' => 'You are Walt.']] + $T, $now);
ok('prose with no scene applies clean: no event, no feet, marker set',
    $r5['applied'] && !$r5['event'] && !$r5['placed']);

// ---------------------------------------------------------------------------
// The forge pass, through the stub seam
// ---------------------------------------------------------------------------

$calls = [];
$ep = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use (&$calls) {
    $calls[] = ['tag' => $tag, 'msgs' => $msgs];
    return ['prose' => str_repeat('You are Walt, and the river is up tonight. ', 8),
            'curtain' => 'The urn is going and half the pews are still warm.'];
}];
$fp = xeric_forge_pass_opening($T, ['events' => [
    ['title' => 'A pie went missing', 'prose' => 'Nobody owned up.'],
]], $ep);
ok('the model writes the page, the code keeps the curtain place',
    mb_strlen((string)$fp['prose']) > 200 && ($fp['scene']['place'] ?? '') === 'first_lutheran');
ok('the model\'s curtain line is adopted',
    str_contains((string)$fp['scene']['line'], 'urn is going'));
$prompt = implode("\n", array_column($calls[0]['msgs'], 'content'));
ok('the prompt is fed the commons: roster, the recent past, the talk, the scene',
    str_contains($prompt, 'Ruth Amberg') && str_contains($prompt, 'A pie went missing')
    && str_contains($prompt, 'light in the mill office') && str_contains($prompt, 'First Lutheran'));
ok('the prompt starves the walls: no drives, no secrets ride in',
    !str_contains(mb_strtolower($prompt), 'secret'));

$short = ['base' => 'stub://', 'stub' => fn() => ['prose' => 'Too short.', 'curtain' => '']];
try { xeric_forge_pass_opening($T, [], $short); $threw = false; }
catch (Throwable $e) { $threw = true; }
ok('a page that came back thin is thrown to the fallback arm, not shipped', $threw);

foreach ($GLOBALS['DBFILES'] as $f) { foreach ([$f, $f . '-wal', $f . '-shm'] as $x) @unlink($x); }

echo $FAILED === 0 ? "\nall opening tests passed\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
