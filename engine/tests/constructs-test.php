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

// ---------------------------------------------------------------------------
// 7. The gossip ripple — the gates, on the words themselves
// ---------------------------------------------------------------------------

ok('gossip: armed by default in a world with no systems record', xeric_gossip_armed($T));
ok('gossip: a world that armed no rumors ripples nothing',
    !xeric_gossip_armed(['forge' => ['armed' => ['daily_rhythms']]] + $T));
ok('gossip: and arming rumors switches it on',
    xeric_gossip_armed(['forge' => ['armed' => ['rumors']]] + $T));

ok('charged: a slammed pot is worth repeating', xeric_gossip_charged('Dot slammed the coffee pot down'));
ok('charged: a quiet morning is not', !xeric_gossip_charged('a slow morning, coffee poured, rain kept on'));
ok('charged: whole words only — a crash course is not a crash',
    !xeric_gossip_charged('the crash course in pie went fine'));

ok('line: the coarse retelling carries no clock',
    !preg_match('/\d/', xeric_gossip_line('she waited at the diner past 21:00')));

ok('commons: naming the diner is a true sentence anybody may repeat',
    xeric_gossip_commons($T, 'a pie left on the porch rail at the diner'));
ok('commons: quoting a secret is not',
    !xeric_gossip_commons($T, "He still carries his father's till key on his ring, folks say"));

// ---------------------------------------------------------------------------
// 8. Formation and spread — news moves only through shared hours
// ---------------------------------------------------------------------------

$db2Path = sys_get_temp_dir() . '/xeric-constructs-gossip-' . getmypid() . '.db';
foreach ([$db2Path, $db2Path . '-wal', $db2Path . '-shm'] as $f) @unlink($f);
$db2 = xeric_state_open($db2Path);

// Ruth hears the Thursday promise; Thursday passes unkept.
xeric_expect_form($T, $db2, 'ruth', $firm, $wedAm);
$r1 = xeric_constructs_tick($T, $db2, xeric_world_now($T, ep('2026-07-30 20:00')));
$items = xeric_gossip_items($db2);
$item1 = $items['gossip.1'] ?? null;
ok('ripple: the miss becomes an item the same tick it fires',
    $r1['missed'] === 1 && $r1['gossip_born'] === 1 && $item1 !== null
    && $item1['kind'] === 'missed_promise', json_encode($r1));
ok('ripple: firsthand is whoever lived the hour — Ruth, alone, at hop zero',
    $item1 !== null && count($item1['knowers']) === 1
    && $item1['knowers'][0] === ['who' => 'ruth', 'hop' => 0], json_encode($item1));

// Two more hours land at the diner: one boils over, one is just a morning.
xeric_event_add($db2, 'the morning the diner boiled over', ep('2026-07-31 07:30'), 'bluebird', ['dot'],
    'Dot slammed the coffee pot down and shouted at a stranger, and the whole counter went still.');
xeric_event_add($db2, 'a slow morning at the diner', ep('2026-07-31 07:30'), 'bluebird', ['dot'],
    'Dot poured coffee and the rain kept on.');

$friAm = xeric_world_now($T, ep('2026-07-31 07:30'));      // Ruth and Dot share the counter
$r2 = xeric_constructs_tick($T, $db2, $friAm);
$items = xeric_gossip_items($db2);
ok('ripple: the charged hour forms an item and the quiet one does not',
    $r2['gossip_born'] === 1 && count($items) === 2, json_encode($r2));
ok('ripple: the shared hour carries the news one hop — Dot hears it from Ruth',
    $r2['gossip_spread'] === 1
    && in_array(['who' => 'dot', 'hop' => 1, 'from' => 'ruth'], $items['gossip.1']['knowers'], true),
    json_encode($items['gossip.1']));
$knowsAny = static function (array $items, string $h): bool {
    foreach ($items as $it) {
        foreach ((array)$it['knowers'] as $k) if ($k['who'] === $h) return true;
    }
    return false;
};
ok('ripple: no teleporting news — Harlan, a room away all morning, knows nothing',
    !$knowsAny($items, 'harlan') && !$knowsAny($items, 'theo') && !$knowsAny($items, 'janelle'));

$blockDot = xeric_expect_block($T, $db2, 'dot', $friAm);
$r3 = xeric_constructs_tick($T, $db2, xeric_world_now($T, ep('2026-07-31 07:40')));
ok('block: byte-stable between ticks — a tick that changes nothing changes no bytes',
    $r3['gossip_spread'] === 0
    && xeric_expect_block($T, $db2, 'dot', $friAm) === $blockDot, $blockDot);
ok('block: and carries no clock', !preg_match('/\d{1,2}:\d{2}/', $blockDot));

// ---------------------------------------------------------------------------
// 9. Attribution decays with the hops, and hop three tells nobody
// ---------------------------------------------------------------------------

ok('attribution: second-hand still has a name on it',
    str_contains($blockDot, 'Ruth Amberg told you Ruth Amberg waited'));
ok('attribution: an eyewitness names what they saw',
    str_contains(xeric_expect_block($T, $db2, 'ruth', $friAm), 'You saw it yourself: the morning the diner boiled over'));
ok('attribution: your own hour is not gossip to you — Dot gets no line about her own scene',
    !str_contains($blockDot, 'boiled over'));

// Two hand-laid items: one held at hop two (a teller with one hop left in it),
// one already at hop three (spent).
xeric_arc_set($db2, xeric_arc_world(), 'gossip.3', json_encode([
    'kind' => 'charged', 'event' => 0, 'line' => 'somebody flooded the school pond', 'place' => null,
    'participants' => [], 'born' => ep('2026-07-31 20:00'), 'state' => 'live',
    'knowers' => [['who' => 'theo', 'hop' => 2, 'from' => 'dot']],
], JSON_UNESCAPED_UNICODE));
xeric_arc_set($db2, xeric_arc_world(), 'gossip.4', json_encode([
    'kind' => 'charged', 'event' => 0, 'line' => 'a stranger asked after the mill', 'place' => null,
    'participants' => [], 'born' => ep('2026-07-31 20:00'), 'state' => 'live',
    'knowers' => [['who' => 'harlan', 'hop' => 3, 'from' => 'theo']],
], JSON_UNESCAPED_UNICODE));

ok('attribution: past the named teller it is only what people are saying',
    str_contains(xeric_expect_block($T, $db2, 'theo', $friAm), 'People are saying somebody flooded the school pond'));

// Two hours at the hardware store that must NOT ripple: one quotes a secret,
// one is a spine hour. Both otherwise pass every gate.
xeric_event_add($db2, 'a scene at the hardware store', ep('2026-08-01 09:00'), 'beck_hardware', ['harlan'],
    "Somebody shouted that he still carries his father's till key on his ring, and the till has been gone eleven years.");
xeric_event_add($db2, 'the hour the mill light moved', ep('2026-08-01 09:00'), 'beck_hardware', ['harlan'],
    'Somebody shouted about the light in the mill office.', null, true);

$satAm2 = xeric_world_now($T, ep('2026-08-01 09:30'));     // Theo and Harlan share the Saturday shift
$r4 = xeric_constructs_tick($T, $db2, $satAm2);
$items = xeric_gossip_items($db2);
ok('walls: a secret never becomes an item, and neither does a spine hour',
    $r4['gossip_born'] === 0 && count($items) === 4, json_encode($r4));
ok('reach: hop two still tells — Harlan hears the pond story third-hand-and-then-some',
    in_array(['who' => 'harlan', 'hop' => 3, 'from' => 'theo'], $items['gossip.3']['knowers'], true),
    json_encode($items['gossip.3']));
ok('reach: hop three tells nobody — the mill question dies in Harlan\'s keeping',
    count($items['gossip.4']['knowers']) === 1, json_encode($items['gossip.4']));

// ---------------------------------------------------------------------------
// 10. A death ripples from its scene; an entrance from its doorstep
// ---------------------------------------------------------------------------

$kill = xeric_death_kill($T, $db2, 'harlan', ep('2026-08-01 10:00'), 'the ladder in the stockroom');
$r5 = xeric_constructs_tick($T, $db2, xeric_world_now($T, ep('2026-08-01 10:05')));
$items = xeric_gossip_items($db2);
$item5 = $items['gossip.5'] ?? null;
ok('death: the hour becomes an item known firsthand by whoever shared the room',
    $kill['ok'] && $r5['gossip_born'] === 1 && $item5 !== null && $item5['kind'] === 'death'
    && in_array(['who' => 'theo', 'hop' => 0], $item5['knowers'], true), json_encode($item5));
ok('death: the dead are not among the knowers of their own hour',
    $item5 !== null && !in_array('harlan', array_column($item5['knowers'], 'who'), true));
ok('death: and the witness block says so plainly',
    str_contains(xeric_expect_block($T, $db2, 'theo', $satAm2), 'You saw it yourself: Harlan Beck died'));

xeric_event_add($db2, 'Janelle Kerr turned up today', ep('2026-08-02 09:30'), null, ['janelle'],
    'Janelle Kerr turned up in Milldale today. Nobody made a ceremony of it.');

// ---------------------------------------------------------------------------
// 11. A wall blocks the hop, and only the hop it governs
// ---------------------------------------------------------------------------

$Tw = $T;
$Tw['knowledge_walls'][] = ['key' => 'no_diner_talk', 'audience' => ['handle' => 'janelle'],
                            'hidden' => ['places.bluebird']];

$sunAm = xeric_world_now($T, ep('2026-08-02 10:00'));      // Ruth, Dale and Janelle share the church hour
$r6 = xeric_constructs_tick($Tw, $db2, $sunAm);
$items = xeric_gossip_items($db2);
$who = fn(array $it): array => array_column((array)$it['knowers'], 'who');
ok('entrance: the arrival ripples from the scene the week data names',
    $r6['gossip_born'] === 1 && ($items['gossip.6']['kind'] ?? '') === 'entrance'
    && in_array('ruth', $who($items['gossip.6']), true), json_encode($items['gossip.6'] ?? null));
ok('walls: the hidden room blocks the hop — Janelle, standing with the teller, never hears the diner item',
    !in_array('janelle', $who($items['gossip.2']), true)
    && in_array('pastor_dale', $who($items['gossip.2']), true), json_encode($items['gossip.2']));
ok('walls: and only that hop — the placeless item reaches her through the same shared hour',
    in_array('janelle', $who($items['gossip.1']), true), json_encode($items['gossip.1']));

$r7 = xeric_constructs_tick($T, $db2, $sunAm);             // same hour, wall gone
$items = xeric_gossip_items($db2);
ok('walls: take the wall down and the same shared hour carries it to her',
    $r7['gossip_spread'] >= 1 && in_array('janelle', $who($items['gossip.2']), true),
    json_encode($items['gossip.2']));

$blockDale = xeric_expect_block($T, $db2, 'pastor_dale', $sunAm);
ok('block: at most one coarse line per fresh item',
    substr_count($blockDale, '- ') === 3
    && substr_count($blockDale, 'Ruth Amberg waited') === 1, $blockDale);

ok('why: the item answers for itself — who saw, and the path of hops',
    str_contains(xeric_gossip_why($T, $items['gossip.1']), 'saw it firsthand')
    && str_contains(xeric_gossip_why($T, $items['gossip.1']), 'heard it from'));

// ---------------------------------------------------------------------------
// 12. The fuse: old news leaves the prompt, and only the caring keep a residue
// ---------------------------------------------------------------------------

xeric_memory_add($db2, 'dot', 'Ruth Amberg skipped her Friday egg.', 'auto', [], ep('2026-07-31 08:00'));
$memCount = fn(string $h): int => xeric_memories_count($db2, $h);
$dotWas  = $memCount('dot');
$daleWas = $memCount('pastor_dale');
$theoWas = $memCount('theo');

$r8 = xeric_constructs_tick($T, $db2, xeric_world_now($T, ep('2026-08-10 12:00')));
$items = xeric_gossip_items($db2);
ok('fuse: six world-days and every item has gone quiet',
    $r8['gossip_faded'] === 6
    && count(array_filter($items, fn($it) => $it['state'] === 'live')) === 0, json_encode($r8));
ok('fuse: the line leaves the prompt',
    xeric_expect_block($T, $db2, 'dot', $sunAm) === '');
ok('residue: a knower who cared keeps one ordinary memory, stamped when the talk died down',
    $memCount('dot') === $dotWas + 1
    && ($m = xeric_memories_for($db2, 'dot', 1)[0])['text'] === 'It went around for days that Ruth Amberg waited.'
    && (int)$m['world_epoch'] === $thu + XERIC_GOSSIP_FUSE, json_encode($m ?? null));
ok('residue: a knower who never mentioned her keeps nothing', $memCount('pastor_dale') === $daleWas);
ok('residue: the boy who watched the town lose Harlan keeps the talk of it',
    $memCount('theo') === $theoWas + 1
    && str_contains(xeric_memories_for($db2, 'theo', 1)[0]['text'], 'went around for days'));

ok('block: byte-stable after the fade too',
    xeric_expect_block($T, $db2, 'theo', $sunAm) === xeric_expect_block($T, $db2, 'theo', $satAm2));

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm',
          $db2Path, $db2Path . '-wal', $db2Path . '-shm'] as $f) @unlink($f);

echo $FAILED === 0 ? "\nPASS\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
