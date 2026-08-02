<?php
/**
 * Xeric — constructs tests: expectations. `php engine/tests/constructs-test.php`.
 *
 * The promise test is a gate on VERBATIM WORDS, the fuse burns in world time,
 * a miss is an event written in observables, and repair returns the trust
 * notch. Everything here runs with no model: formation takes the extractor's
 * proposal as a plain array, which is exactly the seam chat.php feeds.
 */

declare(strict_types=1);

require_once __DIR__ . '/../world.php';
require_once __DIR__ . '/../state.php';
require_once __DIR__ . '/../prompt.php';
require_once __DIR__ . '/../constructs.php';

$FAILED = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $name\n"; return; }
    echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    $FAILED++;
}

function ep(string $when, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();
}

$T = xeric_world_load(__DIR__ . '/../fixtures/milldale.json');

// ---------------------------------------------------------------------------
// 1. The weasel-word gate, on the words themselves
// ---------------------------------------------------------------------------

ok('promise: a plain commitment is a promise',
    !xeric_promise_hedged("I'll be there Thursday"));
ok('promise: "try" makes a non-promise',
    xeric_promise_hedged("I'll try to be there Thursday"));
ok('promise: "maybe" makes a non-promise', xeric_promise_hedged('maybe Saturday'));
ok('promise: "I think" makes a non-promise', xeric_promise_hedged("I think I'll get there"));
ok('promise: "might" makes a non-promise', xeric_promise_hedged('I might come by the market'));
ok('promise: "probably" makes a non-promise', xeric_promise_hedged("probably see you at the diner"));
ok('promise: hedge words match whole words only — "mighty" is not "might"',
    !xeric_promise_hedged('the mighty fine pie can expect me Sunday'));

// ---------------------------------------------------------------------------
// 2. The when-phrase lands on the world's own calendar
// ---------------------------------------------------------------------------

$wedAm = xeric_world_now($T, ep('2026-07-29 10:00'));      // a Wednesday morning

$thu = xeric_promise_when('Thursday', $T, $wedAm);
ok('when: "Thursday" said on Wednesday is tomorrow',
    $thu !== null && (new DateTimeImmutable('@' . $thu))->setTimezone(new DateTimeZone('America/New_York'))->format('D') === 'Thu'
    && $thu > $wedAm['epoch'] && $thu < $wedAm['epoch'] + 2 * 86400);

$satAm = xeric_promise_when('Saturday morning', $T, $wedAm);
ok('when: "Saturday morning" carries its hour',
    $satAm !== null && (new DateTimeImmutable('@' . $satAm))->setTimezone(new DateTimeZone('America/New_York'))->format('D H') === 'Sat 09');

$tonight = xeric_promise_when('tonight', $T, $wedAm);
ok('when: "tonight" is this evening, not next week',
    $tonight !== null && $tonight > $wedAm['epoch'] && $tonight < $wedAm['epoch'] + 86400);

ok('when: a Wednesday promise of "Wednesday" means NEXT Wednesday, promises face forward',
    ($w = xeric_promise_when('Wednesday', $T, $wedAm)) !== null && $w > $wedAm['epoch'] + 5 * 86400);

ok('when: gibberish forms nothing', xeric_promise_when('banana o\'clock', $T, $wedAm) === null);
ok('when: an empty when forms nothing', xeric_promise_when('', $T, $wedAm) === null);

// ---------------------------------------------------------------------------
// 3. Formation — the gate is a property of the listener
// ---------------------------------------------------------------------------

$dbPath = sys_get_temp_dir() . '/xeric-constructs-test-' . getmypid() . '.db';
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) @unlink($f);
$db = xeric_state_open($dbPath);

$firm  = ['quote' => "I'll be there Thursday", 'what' => 'come to the kitchen', 'when' => 'Thursday'];
$hedge = ['quote' => "I'll try to come by Saturday", 'what' => 'come by', 'when' => 'Saturday'];

$k1 = xeric_expect_form($T, $db, 'ruth', $firm, $wedAm);
ok('form: a firm promise with a when forms an expectation', $k1 !== null);
$rows = xeric_expects_for($db, 'ruth');
ok('form: and it reads back open with its due on the calendar',
    count($rows) === 1 && $rows[0]['state'] === 'open' && $rows[0]['due'] === $thu);

ok('form: the same day cannot be promised twice',
    xeric_expect_form($T, $db, 'ruth', $firm, $wedAm) === null);

ok('form: a hedged promise forms nothing in a seventy-one-year-old',
    xeric_expect_form($T, $db, 'ruth', $hedge, $wedAm) === null);

// Milldale's youngest is twelve; the believer window is three to seven. A
// six-year-old clone proves the exception, and twelve proves its edge.
$T6 = $T;
foreach ($T6['cast']['characters'] as $i => $c) {
    if ($c['handle'] === 'theo') $T6['cast']['characters'][$i]['age'] = 6;
}
ok('form: a six-year-old hears the hedged promise as a real one',
    ($k = xeric_expect_form($T6, $db, 'theo', $hedge, $wedAm)) !== null
    && str_contains((string)json_encode(xeric_expects_for($db, 'theo')[0]), 'because'));
ok('form: a twelve-year-old already knows what "try" means',
    xeric_expect_form($T, $db, 'janelle', $hedge, $wedAm) === null);

$Toff = $T;
$Toff['forge']['armed'] = ['daily_rhythms'];
ok('form: a world that armed no expectations forms none',
    xeric_expect_form($Toff, $db, 'dot', $firm, $wedAm) === null);
ok('form: a world with no systems record predates arming and forms freely',
    xeric_expect_form($T, $db, 'dot', $firm, $wedAm) !== null);

// ---------------------------------------------------------------------------
// 4. The block is coarse, and the prompt carries it
// ---------------------------------------------------------------------------

$block = xeric_expect_block($T, $db, 'ruth', $wedAm);
ok('block: open state names the day and quotes the words',
    str_contains($block, 'WHAT YOU ARE OWED') && str_contains($block, 'Thursday')
    && str_contains($block, "I'll be there Thursday"));
ok('block: and carries no clock — coarse means day-coarse',
    !preg_match('/\d{1,2}:\d{2}/', $block));

$sys = xeric_prompt_system($T, $db, 'ruth', 'sfw', 12, (int)$wedAm['epoch']);
ok('prompt: the system message carries what she is owed', str_contains($sys, 'WHAT YOU ARE OWED'));

// ---------------------------------------------------------------------------
// 5. The fuse burns in world time, and a miss is observables only
// ---------------------------------------------------------------------------

$counts = fn() => [
    'events'   => (int)$db->query('SELECT COUNT(*) c FROM events')->fetchAll()[0]['c'],
    'memories' => (int)$db->query('SELECT COUNT(*) c FROM memories')->fetchAll()[0]['c'],
];
$before = $counts();
$friPm  = xeric_world_now($T, ep('2026-07-31 20:00'));     // due Thursday + grace long past

$r = xeric_constructs_tick($T, $db, $friPm);
ok('tick: the overdue promise misses', $r['missed'] >= 1);
$after = $counts();
ok('tick: a miss writes one event and one memory per waiter',
    $after['events'] === $before['events'] + $r['missed']
    && $after['memories'] === $before['memories'] + $r['missed']);

$ev = $db->query('SELECT * FROM events ORDER BY id DESC LIMIT 1')->fetchAll()[0];
ok('tick: the event is stamped at the hour she actually waited, not at detection',
    abs((int)$ev['world_epoch'] - $thu) < 6 * 3600);
ok('tick: the event states what anyone could see and nothing she felt',
    str_contains((string)$ev['title'], 'waited')
    && !preg_match('/felt|hurt|sad|stung|disappoint/i', (string)$ev['prose']));
ok('tick: trust took its notch, silently', xeric_arc_int($db, 'ruth', 'trust', 0) === -1);
ok('tick: the inspector can answer for it',
    ($why = xeric_world_state_get($db, 'why:event:' . (int)$ev['id'])) !== null
    && str_contains((string)$why, 'expected'));

$r2 = xeric_constructs_tick($T, $db, $friPm);
ok('tick: idempotent — the same miss cannot land twice', $r2['missed'] === 0);

ok('block: a missed promise reads as a sting that shows in small ways',
    str_contains(xeric_expect_block($T, $db, 'ruth', $friPm), 'small ways'));

// ---------------------------------------------------------------------------
// 6. Repair returns the notch; silence hardens
// ---------------------------------------------------------------------------

ok('repair: an explanation lands on the newest miss',
    xeric_expect_repair($T, $db, 'ruth', $friPm) !== null);
ok('repair: the trust notch comes back', xeric_arc_int($db, 'ruth', 'trust', 0) === 0);
ok('repair: the block softens but does not forget',
    str_contains(xeric_expect_block($T, $db, 'ruth', $friPm), 'told you why'));
ok('repair: with nothing missed there is nothing to repair',
    xeric_expect_repair($T, $db, 'janelle', $friPm) === null);

// Dot's promise misses and is never explained; the window closes.
$farFuture = xeric_world_now($T, ep('2026-08-20 12:00'));
xeric_constructs_tick($T, $db, $farFuture);
$hard = xeric_constructs_tick($T, $db, xeric_world_now($T, ep('2026-09-15 12:00')));
ok('harden: silence past the window becomes its own memory', $hard['hardened'] >= 1);
ok('harden: and the block carries the weight',
    str_contains(xeric_expect_block($T, $db, 'dot', $farFuture), 'stopped waiting'));

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) @unlink($f);

echo $FAILED === 0 ? "\nPASS\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
