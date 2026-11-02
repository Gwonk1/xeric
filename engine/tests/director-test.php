<?php
/**
 * Xeric — the Director. `php engine/tests/director-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL. What is defended:
 *
 *   - it is opt-in: no rules, no stagings, no table, presence unchanged;
 *   - a staging places two people, and the map and the sweep's groups see it;
 *   - the dead, the on-shift and a minor bound for an adult room refuse;
 *   - the day's cap, and no person in two stagings at once;
 *   - the apart rule fires after N days of the events table and not before;
 *   - a narrator intent becomes a staging (the intent read is stubbed);
 *   - the player rule follows standings moving apart;
 *   - the inspector's why carries reasons, and the owner can cancel.
 */

declare(strict_types=1);

require_once __DIR__ . '/../director.php';
require_once __DIR__ . '/../sweeps.php';

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

function err(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

function ep(string $when): int
{
    return (new DateTimeImmutable($when, new DateTimeZone('America/New_York')))->getTimestamp();
}

$DBFILES = [];
function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-director-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    return xeric_state_open($path);
}

function has_table(PDO $db): bool
{
    return (bool)$db->query("SELECT 1 FROM sqlite_master WHERE name = 'director_stagings'")->fetchColumn();
}

$BASE = xeric_world_load(__DIR__ . '/../fixtures/milldale.json');
// A bar, so there is an adult-rated room to keep a minor out of.
$BASE['places'][] = ['key' => 'tap', 'name' => 'the Tap', 'kind' => 'bar', 'serves_alcohol' => true,
                     'description' => 'A long bar and a short menu.'];

function world(array $director = []): array
{
    $t = $GLOBALS['BASE'];
    if ($director !== []) $t['director'] = $director;
    return $t;
}

// Tuesday 2026-08-04, 10:00. Dot is on her shift at the Bluebird (a resident:
// her job), Harlan at the hardware store (the man behind the register is him),
// Ruth, Janelle and Theo have nothing on.
$TUE10 = ep('2026-08-04 10:00');
$now10 = xeric_world_now(world(), $TUE10 + 600);

// -- opt-in -------------------------------------------------------------------
$T  = world();
$db = fresh_db('off');
$tick = xeric_director_tick($T, $db, $now10);
ok('no director block: the tick stages nothing', $tick['staged'] === []);
ok('no director block: no table is created', !has_table($db));
ok('no director block: world_state untouched', xeric_world_state_get($db, 'director.hour') === null);
ok('no stagings: presence with the db is the schedule exactly',
    xeric_world_who_is_where($T, $now10, [], $db) === xeric_world_who_is_where($T, $now10));
ok('no stagings: dressing returns the template unchanged', xeric_director_dress($T, $db) === $T);
ok('config: unknown rules are dropped, defaults hold',
    xeric_director_config(world(['rules' => ['apart', 'mischief']])) ['rules'] === ['apart']
    && xeric_director_config($T)['per_day'] === 2 && xeric_director_config($T)['apart_days'] === 7);

// -- a staging places two people ---------------------------------------------
$db = fresh_db('stage');
$s = xeric_director_stage($T, $db, ['who' => ['ruth', 'janelle'], 'where' => 'bluebird',
    'from' => $TUE10, 'to' => $TUE10 + 3600, 'why' => 'the owner wants them to talk'], $TUE10 - 3600);
ok('stage: a row, planned before its window', $s['state'] === 'planned' && $s['source'] === 'owner');
$p = xeric_world_who_is_where($T, $now10, [], $db);
ok('stage: both are at the Bluebird, staged',
    $p['ruth']['where'] === 'bluebird' && !empty($p['ruth']['staged'])
    && $p['janelle']['where'] === 'bluebird' && !empty($p['janelle']['staged']));
ok('stage: doing is the place\'s observable pretext', $p['ruth']['doing'] === 'waiting on a coffee');
ok('stage: somebody already there is not marked staged', $p['dot']['where'] === 'bluebird' && empty($p['dot']['staged']));
ok('stage: outside the window, the schedule again',
    xeric_world_who_is_where($T, xeric_world_now($T, $TUE10 + 3600), [], $db)['ruth']['where'] === null);
ok('stage: pretext for a shop is walking past', xeric_director_pretext($T, 'beck_hardware') === 'walking past');

// the map shows it like any other presence, with staged true
$map = xeric_travel_map($T, $db, $now10);
$blue = array_values(array_filter($map['places'], fn($pl) => $pl['key'] === 'bluebird'))[0];
$byH  = array_column($blue['who'], null, 'handle');
ok('map: the staged pair is in the Bluebird\'s who with staged true',
    isset($byH['ruth'], $byH['janelle']) && ($byH['ruth']['staged'] ?? false) === true
    && !array_key_exists('staged', $byH['dot']));

// the sweep's groups form out of it (dressed template, since the sweep takes no db)
$groups = xeric_sweep_groups(xeric_director_dress($T, $db), $now10);
$pair = array_filter($groups, fn($g) => $g['where'] === 'bluebird'
    && in_array('ruth', $g['handles'], true) && in_array('janelle', $g['handles'], true) && $g['weight'] === 4);
ok('sweep: the groups include the staged pair, in the same room already', $pair !== []);
$plain = array_filter(xeric_sweep_groups($T, $now10), fn($g) => $g['weight'] === 4
    && in_array('ruth', $g['handles'], true) && in_array('janelle', $g['handles'], true));
ok('sweep: without the staging they are not in a room together', $plain === []);

// why, and the heart's state walk
xeric_director_ensure($db);
xeric_director_advance($db, $now10['epoch']);
$why = xeric_director_why($db, $now10);
ok('why: the live staging with its reason', count($why['live']) === 1
    && $why['live'][0]['why'] === 'the owner wants them to talk' && $why['live'][0]['who'] === ['ruth', 'janelle']);

// overlaps and the cap
ok('overlap: nobody in two stagings at once', str_contains(err(fn() => xeric_director_stage($T, $db,
    ['who' => ['ruth', 'theo'], 'where' => 'bluebird', 'from' => $TUE10 + 1800, 'to' => $TUE10 + 5400], $TUE10)),
    'already staged'));
xeric_director_stage($T, $db, ['who' => ['theo', 'pastor_dale'], 'where' => 'bluebird',
    'from' => $TUE10 + 7200, 'to' => $TUE10 + 9000], $TUE10);
ok('cap: a third staging the same day is refused', str_contains(err(fn() => xeric_director_stage($T, $db,
    ['who' => ['ruth', 'janelle'], 'where' => 'bluebird', 'from' => $TUE10 + 10800, 'to' => $TUE10 + 12600], $TUE10)),
    'director.per_day'));
ok('window: more than two hours is refused', str_contains(err(fn() => xeric_director_stage($T, fresh_db('long'),
    ['who' => ['ruth', 'janelle'], 'where' => 'bluebird', 'from' => $TUE10, 'to' => $TUE10 + 7201], $TUE10)), 'two hours'));

// cancel
$c = xeric_director_cancel($db, $s['id'], $now10['epoch']);
ok('cancel: a live staging is taken back', $c !== null && $c['state'] === 'cancelled');
ok('cancel: from then on they are where the schedule says',
    xeric_world_who_is_where($T, xeric_world_now($T, $now10['epoch'] + 60), [], $db)['ruth']['where'] === null);
ok('cancel: the half before it was cancelled still happened',
    !empty(xeric_world_who_is_where($T, xeric_world_now($T, $TUE10 + 60), [], $db)['ruth']['staged']));
ok('cancel: twice is nothing', xeric_director_cancel($db, $s['id'], $now10['epoch']) === null);
$why = xeric_director_why($db, $now10['epoch'] + 60);
ok('why: the cancelled one is in recent', in_array($s['id'], array_column($why['recent'], 'id'), true));

// -- refusals ---------------------------------------------------------------
$db = fresh_db('refuse');
ok('on shift: Harlan is not pulled off the register', str_contains(err(fn() => xeric_director_stage($T, $db,
    ['who' => ['harlan', 'ruth'], 'where' => 'bluebird', 'from' => $TUE10, 'to' => $TUE10 + 3600], $TUE10)),
    'cannot be missed'));
ok('minor: Theo is never staged into the Tap', str_contains(err(fn() => xeric_director_stage($T, $db,
    ['who' => ['theo', 'ruth'], 'where' => 'tap', 'from' => $TUE10, 'to' => $TUE10 + 3600], $TUE10)),
    'adult-rated'));
ok('adults may be staged into the Tap', err(fn() => xeric_director_stage($T, fresh_db('tap'),
    ['who' => ['janelle', 'ruth'], 'where' => 'tap', 'from' => $TUE10, 'to' => $TUE10 + 3600], $TUE10)) === '');

// the same refusals hold at read time, against rows that bypassed the check
$fake = function (array $who, string $where) use ($TUE10): array {
    return ['id' => 99, 'who' => $who, 'where' => $where, 'from' => $TUE10, 'to' => $TUE10 + 3600,
            'why' => 'x', 'source' => 'owner', 'state' => 'live', 'made' => $TUE10, 'ended' => null];
};
$Tf = $T; $Tf['_director'] = ['stagings' => [$fake(['harlan', 'ruth'], 'bluebird')]];
$pf = xeric_world_who_is_where($Tf, $now10);
ok('read time: on shift stays on shift, and one person alone is not moved',
    $pf['harlan']['where'] === 'beck_hardware' && $pf['ruth']['where'] === null);
$Tf['_director'] = ['stagings' => [$fake(['theo', 'ruth', 'janelle'], 'tap')]];
$pf = xeric_world_who_is_where($Tf, $now10);
ok('read time: the minor stays out of the Tap while the adults go',
    $pf['theo']['where'] === null && $pf['ruth']['where'] === 'tap' && $pf['janelle']['where'] === 'tap');

// the dead
$db = fresh_db('dead');
$s = xeric_director_stage($T, $db, ['who' => ['ruth', 'janelle'], 'where' => 'bluebird',
    'from' => $TUE10, 'to' => $TUE10 + 3600], $TUE10 - 60);
xeric_death_kill($T, $db, 'janelle', $TUE10 - 30, 'test', null, false);
$pd = xeric_world_who_is_where($T, $now10, xeric_dead_handles($db), $db);
ok('dead: the dead are in no room, staged or not, and the survivor is not moved alone',
    !isset($pd['janelle']) && $pd['ruth']['where'] === null);
ok('dead: a new staging with the dead is refused', str_contains(err(fn() => xeric_director_stage($T, $db,
    ['who' => ['janelle', 'theo'], 'where' => 'bluebird', 'from' => $TUE10 + 7200, 'to' => $TUE10 + 9000], $TUE10)),
    'in no room'));

// -- rule: apart --------------------------------------------------------------
$TA = world(['rules' => ['apart'], 'apart_days' => 7]);
$db = fresh_db('apart');
$day0 = ep('2026-07-28 10:30');
xeric_event_add($db, 'the quilting circle', $day0, 'first_lutheran', ['ruth', 'janelle', 'pastor_dale'], 'they sat');
xeric_event_add($db, 'the counter', $day0 + 3600, 'bluebird', ['dot', 'harlan'], 'eggs');
$r6 = xeric_director_tick($TA, $db, xeric_world_now($TA, $day0 + 6 * 86400 - 1800));
ok('apart: six days is not seven, nothing staged', $r6['staged'] === []);
$r8 = xeric_director_tick($TA, $db, xeric_world_now($TA, $day0 + 8 * 86400 + 1800));
$ap = array_values(array_filter($r8['staged'], fn($x) => $x['source'] === 'apart'));
ok('apart: after eight days a pair from one orbit gets an hour together', $ap !== []
    && array_diff($ap[0]['who'], ['ruth', 'janelle', 'pastor_dale']) === []
    && str_contains($ap[0]['why'], 'not been in a room together for eight days'), json_encode($r8));
ok('apart: at a shared haunt, not a home', $ap !== [] && in_array($ap[0]['where'], ['bluebird', 'first_lutheran'], true));
$perDay = array_count_values(array_map(fn($x) => xeric_director_day($TA, $x['from']), $r8['staged']));
ok('apart: never more than director.per_day stagings on one world-day', $perDay !== [] && max($perDay) <= 2);
ok('apart: every staging moves somebody', array_filter($r8['staged'], function ($x) use ($TA) {
    $pres = xeric_world_who_is_where($TA, xeric_world_now($TA, $x['from']));
    return count(array_filter($x['who'], fn($h) => ($pres[$h]['where'] ?? null) !== $x['where'])) === 0;
}) === []);
$again = xeric_director_tick($TA, $db, xeric_world_now($TA, $day0 + 8 * 86400 + 1800));
ok('apart: once per world-hour', $again['staged'] === []);

// -- rule: narrator -----------------------------------------------------------
$TN = world(['rules' => ['narrator']]);
$db = fresh_db('narrator');
$stub = [['n' => 3, 'participants' => ['ruth', 'harlan'], 'kind' => 'friction', 'place' => null],
         ['n' => 4, 'participants' => ['dot', 'theo'], 'kind' => 'glimpse', 'place' => null],
         ['n' => 5, 'participants' => ['ruth'], 'kind' => 'ease', 'place' => null]];
$rn = xeric_director_tick($TN, $db, xeric_world_now($TN, ep('2026-08-04 16:10')), ['intents' => $stub]);
ok('narrator: a meeting intent becomes a staging', count($rn['staged']) === 1
    && $rn['staged'][0]['source'] === 'narrator:3' && $rn['staged'][0]['who'] === ['ruth', 'harlan'], json_encode($rn));
ok('narrator: the why says so', ($rn['staged'][0]['why'] ?? '') === 'the narrator intends a friction hour between Ruth Amberg and Harlan Beck');
ok('narrator: at the next hour both are free (Harlan off the register)', ($rn['staged'][0]['from'] ?? 0) >= ep('2026-08-04 17:00'));
$rn2 = xeric_director_tick($TN, $db, xeric_world_now($TN, ep('2026-08-04 16:10')), ['intents' => $stub, 'force' => true]);
ok('narrator: one staging per intent', $rn2['staged'] === []);

// -- rule: player -------------------------------------------------------------
$TP = world(['rules' => ['player']]);
$db = fresh_db('player');
xeric_player_move($db, 'bluebird', $TUE10);
$t0 = xeric_director_tick($TP, $db, xeric_world_now($TP, ep('2026-08-04 07:10')));
ok('player: the first reading only takes a reference', $t0['staged'] === []);
xeric_trust_earn($db, 'ruth', 3);
xeric_trust_earn($db, 'janelle', -1);
$t1 = xeric_director_tick($TP, $db, xeric_world_now($TP, ep('2026-08-04 08:10')));
ok('player: standing moving apart stages them where the player is', count($t1['staged']) === 1
    && $t1['staged'][0]['where'] === 'bluebird' && $t1['staged'][0]['who'] === ['ruth', 'janelle']
    && $t1['staged'][0]['why'] === "the player's standing with both is moving apart", json_encode($t1));

// -- a year of finished stagings costs presence nothing ------------------------
// who_is_where is asked per person per prompt; it used to decode every staging
// the world ever made. Five hundred done ones behind the clock are read zero
// times, the one live staging once, and the answer is the same.
$db = fresh_db('history');
$T = world();
xeric_director_stage($T, $db, ['who' => ['ruth', 'janelle'], 'where' => 'bluebird',
    'from' => $TUE10, 'to' => $TUE10 + 3600, 'why' => 'the owner wants them to talk'], $TUE10 - 3600);
$fresh = xeric_world_who_is_where($T, $now10, [], $db);
$ins = $db->prepare("INSERT INTO director_stagings (who, place, epoch_from, epoch_to, why, source, state, made, ended, created_at)
                     VALUES (?, 'bluebird', ?, ?, 'old business', 'owner', 'done', ?, ?, 0)");
for ($i = 1; $i <= 500; $i++) {
    $f0 = $TUE10 - $i * 86400;
    $ins->execute([json_encode(['ruth', 'janelle']), $f0, $f0 + 3600, $f0 - 60, $f0 + 3600]);
}
$r0 = xeric_director_rows_read();
$p500 = xeric_world_who_is_where($T, $now10, [], $db);
$read = xeric_director_rows_read() - $r0;
ok('history: 500 done stagings are not read by who_is_where — only what can cover now', $read === 1, "$read rows read");
ok('history: and presence is the same answer it was before them', $p500 === $fresh);
ok('history: a past moment still sees the staging that held it',
    (xeric_world_who_is_where($T, xeric_world_now($T, $TUE10 - 3 * 86400 + 600), [], $db)['janelle']['staging'] ?? 0) > 0);
$r0 = xeric_director_rows_read();
$dressed = xeric_director_dress($T, $db);
ok('history: dressing carries the planned and live, not the year behind them',
    count($dressed['_director']['stagings'] ?? []) === 1 && xeric_director_rows_read() - $r0 === 1);
ok('history: dressed from a moment, it carries what ends after it',
    count(xeric_director_dress($T, $db, $TUE10 - 2 * 86400)['_director']['stagings']) === 3);
$idx = array_column($db->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'director_stagings'")->fetchAll(), 'name');
ok('history: the table carries the index the reader seeks on', in_array('idx_director_to', $idx, true), json_encode($idx));

// -- one honorific list ------------------------------------------------------
ok('names: Pastor Dale is Dale', xeric_world_name_forms('Pastor Dale') === ['Pastor Dale', 'Dale']
    && xeric_constructs_first_name('Pastor Dale Ostrander') === 'Dale');
ok('names: Dr. Ruth is Ruth', xeric_world_name_forms('Dr. Ruth') === ['Dr. Ruth', 'Ruth']);
ok('names: a name with no title comes back unchanged', xeric_world_name_forms('Ruth') === ['Ruth']
    && xeric_world_name_forms('Ruth Amberg') === ['Ruth Amberg', 'Ruth'] && xeric_world_name_forms('  ') === []);
ok('names: a name that is all title keeps itself', xeric_world_name_forms('Pastor') === ['Pastor']);
require_once __DIR__ . '/../sleep.php';
require_once __DIR__ . '/../confide.php';
ok('names: sleep, the promise reader and the confidence reader agree, because there is one copy',
    xeric_sleep_name_forms('Reverend Hale Moss') === ['Reverend Hale Moss', 'Hale']
    && xeric_constructs_first_name('Reverend Hale Moss') === 'Hale'
    && xeric_confide_named($BASE, "don't tell Dale", 'ruth') === ['pastor_dale']
    && xeric_sleep_name_forms('Dr. Al') === ['Dr. Al']);
$all = '';
foreach (['sleep', 'constructs', 'confide'] as $f) $all .= (string)file_get_contents(__DIR__ . '/../' . $f . '.php');
ok('names: and no other honorific list is left in them', !preg_match("/\\['pastor', 'father'/", $all));

foreach ($DBFILES as $f) foreach ([$f, $f . '-wal', $f . '-shm'] as $x) @unlink($x);
echo $FAILED === 0 ? "\nall ok\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
