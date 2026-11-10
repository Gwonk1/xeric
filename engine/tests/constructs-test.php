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

$nyc = new DateTimeZone('America/New_York');
ok('when: a bare day is due at the day\'s close, so the grace ends at its midnight',
    $thu !== null && (new DateTimeImmutable('@' . $thu))->setTimezone($nyc)->format('D H:i') === 'Thu 20:00'
    && (new DateTimeImmutable('@' . ($thu + XERIC_EXPECT_GRACE)))->setTimezone($nyc)->format('D H:i') === 'Fri 00:00');
ok('when: "tomorrow" is tomorrow\'s close too, not its midnight',
    xeric_promise_when('tomorrow', $T, $wedAm) === $thu);
ok('when: "today" said in the morning is this evening',
    ($td = xeric_promise_when('today', $T, $wedAm)) !== null
    && (new DateTimeImmutable('@' . $td))->setTimezone($nyc)->format('D H:i') === 'Wed 20:00');
$wedLate = xeric_world_now($T, ep('2026-07-29 21:30'));
ok('when: "today" said late is still tonight, not next week',
    ($tl = xeric_promise_when('today', $T, $wedLate)) !== null
    && $tl > $wedLate['epoch'] && $tl < ep('2026-07-30 00:00'));

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

// The day it named has to be over first. Thursday afternoon is still Thursday.
$rThu = xeric_constructs_tick($T, $db, xeric_world_now($T, ep('2026-07-30 15:00')));
ok('tick: a bare-day promise is not missed while its day is still running',
    $rThu['missed'] === 0 && $counts() === $before);

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
$r1 = xeric_constructs_tick($T, $db2, xeric_world_now($T, ep('2026-07-31 00:30')));   // Thursday is over
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

// ---------------------------------------------------------------------------
// 12a. CAST TO CAST. Expectations pointed only at the person at the centre,
// because v1's reasoning was that they were the only one who could let
// anybody down. But a duet and a room are the town talking to itself, and
// "I'll bring the truck round Thursday" said across a table is the same
// sentence with the same fuse — read by the same code gate.
// ---------------------------------------------------------------------------

echo "\n# promises between two people in the town\n";

$c2Path = sys_get_temp_dir() . '/xeric-c2c-' . getmypid() . '.db';
foreach ([$c2Path, $c2Path . '-wal', $c2Path . '-shm'] as $f) @unlink($f);
$c2 = xeric_state_open($c2Path);
xeric_state_migrate($c2);

$c2Keys = xeric_expect_from_scene($T, $c2, [
    ['handle' => 'harlan', 'text' => 'I will bring the truck round Thursday and we will get it done.'],
    ['handle' => 'ruth',   'text' => 'You always say that.'],
], $wedAm);
ok('cast: a promise across a table forms, and the row knows who made it',
    $c2Keys !== [] && (xeric_expects_for($c2, 'ruth')[0]['of'] ?? '') === 'harlan');
ok('cast: and it is owed to the person who was told, not to the player',
    xeric_expects_for($c2, 'harlan') === []);

// The same two gates as the player's promises, on the verbatim words.
$c2Hedge = xeric_expect_from_scene($T, $c2, [
    ['handle' => 'dot',  'text' => 'I might come by Friday if the car starts.'],
    ['handle' => 'theo', 'text' => 'Okay.'],
], $wedAm);
ok('cast: a hedge across a table is still not a promise', $c2Hedge === []);
$c2NoWhen = xeric_expect_from_scene($T, $c2, [
    ['handle' => 'dot',  'text' => 'I will fix that gate for you.'],
    ['handle' => 'theo', 'text' => 'Okay.'],
], $wedAm);
ok('cast: and a promise with no when is a sentiment', $c2NoWhen === []);

// The prompt block names whoever actually promised — a line that said the
// player's name about a promise Harlan made is the one sentence in that
// block that could not be true.
$c2Owed = xeric_expect_owed($T, $c2, 'ruth');
ok('cast: what Ruth is owed names HARLAN, not the player',
    str_contains($c2Owed, 'Harlan') && !str_contains($c2Owed, (string)($T['user']['name'] ?? 'Neil')), $c2Owed);

// And the miss says the same thing: her memory, and the trail, name him.
$c2Fired = xeric_constructs_tick($T, $c2, xeric_world_now($T, $thu + XERIC_EXPECT_GRACE + 3600));
$c2Mem   = xeric_memories_for($c2, 'ruth', 1);
ok('cast: the miss fires and her memory names the man who did not come',
    ($c2Fired['missed'] ?? 0) === 1
    && str_contains((string)($c2Mem[0]['text'] ?? ''), 'Harlan'), json_encode($c2Mem[0] ?? null));
// THE ROW THAT MOVES IS THE RIGHT ONE. Ruth's opinion OF HARLAN drops, and
// her opinion of the player does not — "Ruth trusts you less because Harlan
// stood her up" is a sentence no engine should be able to write.
ok('cast: her standing with HIM drops', xeric_trust_of($c2, 'ruth', 'harlan') < 0,
    (string)xeric_trust_of($c2, 'ruth', 'harlan'));
ok('cast: and the player, who did nothing, is untouched by it',
    xeric_trust_of($c2, 'ruth') === 0, (string)xeric_trust_of($c2, 'ruth'));
ok('cast: and it is a pair, not a number — his opinion of her is his own',
    xeric_trust_of($c2, 'harlan', 'ruth') === 0);

// The player cannot apologise for somebody else's broken promise.
ok('cast: explaining repairs the player\'s own misses and nobody else\'s',
    xeric_expect_repair($T, $c2, 'ruth', $wedAm) === null);

// ---------------------------------------------------------------------------
// 12c. A DEBT. `favor`'s shape has said "and it is now owed" since the kinds
// were written, and nothing kept the owing. Not a counter: a row that knows
// what it was for, so it can be rendered in somebody's own voice and squared
// by a favour going the other way.
// ---------------------------------------------------------------------------

echo "\n# what one of them owes the other\n";

$Tdebt = $T;
$Tdebt['forge']['armed'] = ['favors', 'daily_rhythms'];

ok('debt: a world that never armed favours keeps no account of them',
    xeric_debt_form($Toff, $c2, 'harlan', 'ruth', 'the ride home', $wedAm) === null
    && xeric_debts_for($c2, 'harlan') === []);

$dKey = xeric_debt_form($Tdebt, $c2, 'harlan', 'ruth', 'the ride home', $wedAm);
ok('debt: a favour done is owed by the one it was done for', $dKey !== null);
$d = xeric_debts_for($c2, 'harlan')[0] ?? [];
ok('debt: and the row knows what it was for, not just how much',
    ($d['to'] ?? '') === 'ruth' && ($d['what'] ?? '') === 'the ride home' && ($d['state'] ?? '') === 'open');
// A debt is between two people in the TOWN. What the person at the centre
// owes anybody is the expectation machinery above, which has a promise and a
// due date; they are not in the cast, so the same guard that rejects an
// invented name rejects them, and this asserts it by their actual name.
$centre = trim((string)($T['user']['name'] ?? 'Neil'));
ok('debt: it never points at the person at the centre, who is not in the cast',
    xeric_debt_form($Tdebt, $c2, 'harlan', $centre, 'x', $wedAm) === null
    && xeric_debt_form($Tdebt, $c2, $centre, 'ruth', 'x', $wedAm) === null
    && xeric_debt_form($Tdebt, $c2, 'harlan', 'nobody_here', 'x', $wedAm) === null);
ok('debt: and nobody owes themselves anything',
    xeric_debt_form($Tdebt, $c2, 'harlan', 'harlan', 'x', $wedAm) === null);

// One account per pair. A second favour before the first is squared deepens
// what is owed rather than opening a second book.
xeric_debt_form($Tdebt, $c2, 'harlan', 'ruth', 'the loan of the ladder', $wedAm);
ok('debt: a second favour deepens the one account, it does not open two',
    count(xeric_debts_for($c2, 'harlan')) === 1
    && (int)(xeric_debts_for($c2, 'harlan')[0]['times'] ?? 0) === 2);

ok('debt: he carries it in his own prompt, in his own words',
    str_contains(xeric_debt_block($Tdebt, $c2, 'harlan'), 'You owe')
    && str_contains(xeric_debt_block($Tdebt, $c2, 'harlan'), 'the loan of the ladder'));
ok('debt: and she carries the same fact from the other side, which reads differently',
    str_contains(xeric_debt_block($Tdebt, $c2, 'ruth'), 'owes you')
    && !str_contains(xeric_debt_block($Tdebt, $c2, 'ruth'), 'You owe'));

// THE POINT OF A RELATIONSHIP OVER A TALLY: a favour the other way makes them
// square, not two-all.
ok('debt: a favour the other way squares it rather than starting a rival account',
    xeric_debt_form($Tdebt, $c2, 'ruth', 'harlan', 'sat with his mother', $wedAm) === null
    && (xeric_debts_for($c2, 'harlan')[0]['state'] ?? '') === 'settled'
    && xeric_debts_for($c2, 'ruth') === []);
ok('debt: and squared means it leaves both prompts',
    xeric_debt_block($Tdebt, $c2, 'harlan') === '' && xeric_debt_block($Tdebt, $c2, 'ruth') === '');

// Carried long enough, it stops being an account. Not forgiven, not repaid.
xeric_debt_form($Tdebt, $c2, 'dot', 'theo', 'the night in the storm', $wedAm);
$muchLater = ['epoch' => (int)$wedAm['epoch'] + XERIC_DEBT_FADE + 3600];
ok('debt: a debt carried long enough becomes history rather than an account',
    xeric_debt_fade($Tdebt, $c2, $muchLater) === 1
    && (xeric_debts_for($c2, 'dot')[0]['state'] ?? '') === 'faded'
    && xeric_debt_block($Tdebt, $c2, 'dot') === '');
ok('debt: and it does not fade twice',
    xeric_debt_fade($Tdebt, $c2, $muchLater) === 0);

// Day-coarse like every other construct: no dates, no counts, no clock.
xeric_debt_form($Tdebt, $c2, 'harlan', 'ruth', 'the ride home', $wedAm);
ok('debt: the block is byte-stable while nothing changes, so the cache survives',
    xeric_expect_block($Tdebt, $c2, 'harlan', $wedAm)
    === xeric_expect_block($Tdebt, $c2, 'harlan', ['epoch' => (int)$wedAm['epoch'] + 7200]));

foreach ([$c2Path, $c2Path . '-wal', $c2Path . '-shm'] as $f) @unlink($f);

// ---------------------------------------------------------------------------
// 12b. Death settles the ledger — no fuse survives its holder.
//
// The tick used to SKIP a dead holder, which left an open fuse `open` forever:
// after a revive, the next tick fired the miss backdated to the due hour —
// "she waited at the diner" stamped after her own death event, a memory of an
// evening spent dead, a permanent trust mark. Now death releases the open
// expectation quietly, so time travel through the grave writes no ghosts.
// ---------------------------------------------------------------------------

echo "\n# death and the open fuse\n";

$db3Path = sys_get_temp_dir() . '/xeric-constructs-death-' . getmypid() . '.db';
foreach ([$db3Path, $db3Path . '-wal', $db3Path . '-shm'] as $f) @unlink($f);
$db3 = xeric_state_open($db3Path);
xeric_state_migrate($db3);

// Ruth hears the Thursday promise on Wednesday…
$kD = xeric_expect_form($T, $db3, 'ruth', $firm, $wedAm);
ok('death: the promise forms while she lives', $kD !== null);

// …and dies Thursday evening, before the fuse burns.
xeric_death_kill($T, $db3, 'ruth', ep('2026-07-30 18:10'), 'a fall on the mill stairs');
$evBefore = xeric_events_count($db3);
$rDead = xeric_constructs_tick($T, $db3, xeric_world_now($T, ep('2026-07-30 20:00')));
ok('death: the tick fires no miss for the dead — the fuse is released, not paused',
    $rDead['missed'] === 0
    && (xeric_expects_for($db3, 'ruth')[0]['state'] ?? '') === 'released',
    json_encode(xeric_expects_for($db3, 'ruth')));
ok('death: and the release writes nothing — no event, no memory, no grudge',
    xeric_events_count($db3) === $evBefore && xeric_memories_count($db3, 'ruth') === 0);

// Four world-days later she is revived. The next tick must find nothing owed.
xeric_death_revive($T, $db3, 'ruth');
$rBack = xeric_constructs_tick($T, $db3, xeric_world_now($T, ep('2026-08-03 12:00')));
ok('death: a revive does not resurrect the fuse — no backdated miss, ever',
    $rBack['missed'] === 0 && xeric_events_count($db3) === $evBefore,
    json_encode(xeric_expects_for($db3, 'ruth')));
ok('death: her trust is untouched by the promise she was dead for',
    xeric_arc_get($db3, 'ruth', 'trust') === null);

foreach ([$db3Path, $db3Path . '-wal', $db3Path . '-shm'] as $f) @unlink($f);

// ---------------------------------------------------------------------------
// 13. The gate honours every wall path, not just the room and the literal quote
//
// The ripple is character-facing: what passes this gate lands in somebody's
// byte-stable system prompt and, when the item fades, in a permanent memory.
// It used to check places.<key> plus the six-word quotation test and nothing
// else — three ways past it, each asserted here so none can quietly reopen.
// ---------------------------------------------------------------------------

echo "\n# the gossip wall gate, all four paths\n";

// (a) THE PROTECTED-SECRET NEEDLE. A paraphrase carries the secret without a
// six-word literal run — the quotation test alone cannot see it, the loose
// word-overlap needle can. Same helper, same threshold, as every other surface.
$Tn = $T;
$Tn['cast']['special_roles'] = [[
    'role' => 'unaware', 'character' => 'pastor_dale',
    'must_not_know' => 'who was on the landing at the mill',
]];
$needleItem = ['line' => 'harlan shouted about the mill landing', 'place' => ''];
ok('gate: a paraphrase of the protected secret is blocked for the protected head',
    xeric_gossip_wall_blocked($Tn, $needleItem, 'pastor_dale'));
ok('gate: and only for the protected head — the same line rides to anybody else',
    !xeric_gossip_wall_blocked($Tn, $needleItem, 'dot'));

// (b) THE SCHEDULES WALL. A line seating a name in a room is who-is-where in
// its Sunday clothes; a viewer the now-block refuses to name a room to may not
// hear it as gossip either. One wall, one tick, one answer.
$Ts = $T;
$Ts['knowledge_walls'][] = ['key' => 'shut_in', 'audience' => ['handle' => 'theo'],
                            'hidden' => ['schedules']];
$placedItem = ['line' => 'Ruth Amberg waited at the diner', 'place' => 'bluebird'];
ok('gate: a schedules wall blocks a placed item the way a places wall does',
    xeric_gossip_wall_blocked($Ts, $placedItem, 'theo'));
ok('gate: but not a placeless one — a death has no room to give away',
    !xeric_gossip_wall_blocked($Ts, ['line' => 'Harlan Voss died', 'place' => ''], 'theo'));

// (c) THE WALLED LEDGER. economies.* hides the entire render, so its ground
// truth is an interior — and until it was indexed, the quotation test read it
// as commons and delivered a walled economy's exact prose to the person the
// wall named.
$Te = $T;
$Te['knowledge_walls'][] = ['key' => 'family_innocence', 'audience' => ['handle' => 'janelle'],
                            'hidden' => ['economies.thursday_pot']];
$gt = (string)($Te['economies'][1]['ground_truth'][0] ?? '');
ok('gate: the fixture still carries the thursday pot ground truth this drives', $gt !== '');
$ledgerItem = ['line' => 'They say ' . $gt, 'place' => ''];
ok('gate: a line quoting a walled economy\'s ground truth is blocked for the walled viewer',
    xeric_gossip_wall_blocked($Te, $ledgerItem, 'janelle'));
ok('gate: and rides freely to a viewer with no such wall',
    !xeric_gossip_wall_blocked($Te, $ledgerItem, 'dot'));

// The mystery rumor is the same shape of hole, same fix.
$Tm = $T;
$Tm['knowledge_walls'][] = ['key' => 'no_rumor', 'audience' => ['handle' => 'theo'],
                            'hidden' => ['mystery.rumor']];
$rumor = (string)($Tm['mystery']['rumor'] ?? '');
ok('gate: a line quoting the mystery rumor is blocked for a viewer walled from it',
    $rumor !== ''
    && xeric_gossip_wall_blocked($Tm, ['line' => 'Folks keep saying ' . $rumor, 'place' => ''], 'theo'));

// (d) THE PATHS THAT ALWAYS WORKED still do — the gate grew, it did not move.
ok('gate: the places wall still blocks its room',
    xeric_gossip_wall_blocked($Tw, $placedItem, 'janelle'));
ok('gate: and a wall-less viewer is still blocked by nothing',
    !xeric_gossip_wall_blocked($T, $placedItem, 'ruth'));

// ---------------------------------------------------------------------------
// THE FLOOR, WHERE A RIPPLE CROSSES ROOMS.
//
// Everything else in a character's prompt is filtered at THEIR OWN ceiling. A
// gossip line arrives by a different road: it was written into an hour whose
// ceiling was that ROOM's — clamped by whoever stood in it, which is precisely
// the set of people it will never be shown to — and then it travels. A child
// who was nowhere near it hears it third-hand.
//
// Own database on purpose: the items here are hand-laid, and the numbering
// above is asserted against by name.
// ---------------------------------------------------------------------------

$dbFPath = sys_get_temp_dir() . '/xeric-constructs-floor-' . getmypid() . '.db';
foreach ([$dbFPath, $dbFPath . '-wal', $dbFPath . '-shm'] as $f) @unlink($f);
$dbF = xeric_state_open($dbFPath);
xeric_state_migrate($dbF);

$both = [['who' => 'theo', 'hop' => 1, 'from' => 'dot'],
         ['who' => 'harlan', 'hop' => 1, 'from' => 'dot']];
$lay = static function (string $key, string $line) use ($dbF, $both): void {
    xeric_arc_set($dbF, xeric_arc_world(), $key, json_encode([
        'kind' => 'charged', 'event' => 0, 'line' => $line, 'place' => null,
        'participants' => [], 'born' => ep('2026-07-31 20:00'), 'state' => 'live',
        'knowers' => $both,
    ], JSON_UNESCAPED_UNICODE));
};
$lay('gossip.1', 'the coffee pot went over at the diner');
$lay('gossip.2', 'there were condoms in the church parking lot');
$lay('gossip.3', 'Theo Vance was seen out past midnight');

$fNow    = xeric_world_now($T, ep('2026-08-01 09:30'));
$adult   = xeric_expect_block($T, $dbF, 'harlan', $fNow);
$child   = xeric_expect_block($T, $dbF, 'theo', $fNow);

ok('floor: an ordinary line reaches everybody who heard it',
    str_contains($adult, 'the coffee pot went over')
    && str_contains($child, 'the coffee pot went over'));
ok('floor: the adult line reaches the adult',
    str_contains($adult, 'church parking lot'));
ok('floor: and never lands in the child\'s prompt',
    !str_contains($child, 'church parking lot'), $child);
ok('floor: an ordinary line about a child is still ordinary',
    str_contains($adult, 'Theo Vance was seen out past midnight')
    && str_contains($child, 'Theo Vance was seen out past midnight'));

// DROPPED FOR THE READER, NOT KILLED FOR THE TOWN. An hour is refused whole
// because half an hour is not a smaller hour; the town's talk is a list, and a
// list with one thing missing from it is what not being told something is.
ok('floor: the item stays live — the town has not stopped saying it',
    (xeric_gossip_items($dbF)['gossip.2']['state'] ?? '') === 'live');
ok('floor: and the child still gets a block, not silence',
    str_starts_with(trim($child), 'WHAT PEOPLE ARE SAYING'));

$dbF = null;

// ---------------------------------------------------------------------------
// 14. KEPT. A promise used to have only bad endings: missed, repaired,
// hardened, or released by a death. Walk into the Bluebird on Friday morning
// with Ruth's dish and four hours later the fuse burned anyway. Now the body
// being there, or the extractor hearing it done, closes it as kept — gated in
// code, the mirror of the miss: one notch of trust, one memory, no event.
// ---------------------------------------------------------------------------

echo "\n# a promise kept\n";

require_once __DIR__ . '/../travel.php';
$dbKPath = sys_get_temp_dir() . '/xeric-constructs-kept-' . getmypid() . '.db';
foreach ([$dbKPath, $dbKPath . '-wal', $dbKPath . '-shm'] as $f) @unlink($f);
$dbK = xeric_state_open($dbKPath);

$dish = ['quote' => "I'll bring your dish back Friday morning", 'what' => 'the casserole dish', 'when' => 'Friday morning'];
$sat  = ['quote' => "I'll be by Saturday morning", 'what' => 'come by Saturday', 'when' => 'Saturday morning'];
$kDish = xeric_expect_form($T, $dbK, 'ruth', $dish, $wedAm);
$kSat  = xeric_expect_form($T, $dbK, 'ruth', $sat, $wedAm);
$friEarly = xeric_world_now($T, ep('2026-07-31 07:30'));    // Ruth at the Bluebird counter
$stateOf  = fn(PDO $d, string $h, string $k) => (array_values(array_filter(xeric_expects_for($d, $h),
                fn($e) => $e['key'] === $k))[0]['state'] ?? '');

// Standing there since before the promise was made is not keeping it: the
// promise and its keeping cannot be the same visit.
xeric_player_move($dbK, 'bluebird', (int)$wedAm['epoch'] - 60);
xeric_constructs_tick($T, $dbK, $friEarly);
ok('kept: a body that was already there when the promise was made keeps nothing',
    $stateOf($dbK, 'ruth', (string)$kDish) === 'open');

$memBefore = (int)$dbK->query("SELECT COUNT(*) FROM memories WHERE handle = 'ruth'")->fetchColumn();
$evBefore  = (int)$dbK->query('SELECT COUNT(*) FROM events')->fetchColumn();
xeric_player_move($dbK, 'bluebird', ep('2026-07-31 07:15'));
xeric_constructs_tick($T, $dbK, $friEarly);
ok('kept: walking in on the morning promised closes it as kept',
    $stateOf($dbK, 'ruth', (string)$kDish) === 'kept');
ok('kept: and only the soonest — Saturday\'s promise is not kept by Friday\'s visit',
    $stateOf($dbK, 'ruth', (string)$kSat) === 'open', json_encode([$kSat, xeric_expects_for($dbK, 'ruth')]));
ok('kept: trust takes the notch a miss would have taken, the other way', xeric_trust_of($dbK, 'ruth') === 1);
ok('kept: one ordinary memory, and no event — turning up as you said is not news',
    (int)$dbK->query("SELECT COUNT(*) FROM memories WHERE handle = 'ruth'")->fetchColumn() === $memBefore + 1
    && (int)$dbK->query('SELECT COUNT(*) FROM events')->fetchColumn() === $evBefore);
$kBlock = xeric_expect_block($T, $dbK, 'ruth', $friEarly);
ok('kept: the block reads kept, not waiting',
    str_contains($kBlock, 'and did, Friday') && str_contains($kBlock, 'Nothing is owed on it')
    && !str_contains($kBlock, "dish back Friday morning\" — you are expecting"), $kBlock);
ok('kept: and still carries no clock', !preg_match('/\d{1,2}:\d{2}/', $kBlock));
$rLate = xeric_constructs_tick($T, $dbK, xeric_world_now($T, ep('2026-07-31 20:00')));
ok('kept: the fuse never burns on a kept promise — no miss that evening', $rLate['missed'] === 0
    && $stateOf($dbK, 'ruth', (string)$kDish) === 'kept' && xeric_trust_of($dbK, 'ruth') === 1);
xeric_constructs_tick($T, $dbK, $friEarly);
ok('kept: idempotent — a second tick in the same room keeps nothing twice', xeric_trust_of($dbK, 'ruth') === 1);

// The extractor's half, and its gates.
$kDot = xeric_expect_form($T, $dbK, 'dot', $dish, $wedAm);
ok('kept: a promise cannot be kept in the same breath it is made',
    xeric_expect_keep($T, $dbK, 'dot', $wedAm, null, XERIC_PLAYER_FIRST, 'said') === null);
ok('kept: the extractor hearing it done keeps it, early or not',
    xeric_expect_keep($T, $dbK, 'dot', xeric_world_now($T, ep('2026-07-30 12:00')), null, XERIC_PLAYER_FIRST, 'said') === $kDot
    && $stateOf($dbK, 'dot', (string)$kDot) === 'kept');
ok('kept: nothing open, nothing kept',
    xeric_expect_keep($T, $dbK, 'dot', xeric_world_now($T, ep('2026-07-30 13:00')), null, XERIC_PLAYER_FIRST, 'said') === null);
ok('kept: an unknown way of keeping fails closed',
    xeric_expect_keep($T, $dbK, 'ruth', $friEarly, null, XERIC_PLAYER_FIRST, 'probably') === null);

// A miss is mended by explaining, never by turning up late.
$kH = xeric_expect_form($T, $dbK, 'harlan', $dish, $wedAm);
xeric_constructs_tick($T, $dbK, xeric_world_now($T, ep('2026-07-31 20:00')));
ok('kept: a promise already missed cannot be kept after the fact',
    $stateOf($dbK, 'harlan', (string)$kH) === 'missed'
    && xeric_expect_keep($T, $dbK, 'harlan', xeric_world_now($T, ep('2026-07-31 21:00')), null, XERIC_PLAYER_FIRST, 'said') === null);

// The town's own promises, kept by the week: Dot promised Ruth Friday morning,
// and Friday morning their schedules put them at the same counter.
$kC = xeric_expect_form($T, $dbK, 'ruth', ['quote' => 'I will have the pie for you Friday morning',
    'what' => 'the pie', 'when' => 'Friday morning'], xeric_world_now($T, ep('2026-07-30 09:00')), null, 'dot');
xeric_player_move($dbK, null, ep('2026-07-31 06:00'));
xeric_constructs_tick($T, $dbK, $friEarly);
ok('kept: a promise between two townspeople is kept when their week seats them together',
    $kC !== null && $stateOf($dbK, 'ruth', (string)$kC) === 'kept' && xeric_trust_of($dbK, 'ruth', 'dot') === 1,
    json_encode(xeric_expects_for($dbK, 'ruth')));
ok('kept: and the player is not credited for it', xeric_trust_of($dbK, 'ruth') === 1);
ok('kept: the chat extractor is asked to watch for it, and keeps before it forms',
    str_contains((string)file_get_contents(dirname(__DIR__) . '/chat.php'), 'actually DID something they had')
    && strpos((string)file_get_contents(dirname(__DIR__) . '/chat.php'), "xeric_expect_keep(\$template")
       < strpos((string)file_get_contents(dirname(__DIR__) . '/chat.php'), "xeric_expect_form(\$template"));

// ONE READ OF THE TOWN PER TICK: who is where (the whole cast against the
// deaths) and the body's position are the same for every holder at one
// instant, so the keep is handed them, never recomputes them per handle.
foreach (['dot', 'harlan', 'janelle'] as $hh) xeric_expect_form($T, $dbK, $hh, $sat, $wedAm);
$holders = count(array_filter(array_map(fn($c) => (string)$c['handle'], $T['cast']['characters']),
    fn($hh) => array_filter(xeric_expects_for($dbK, $hh), fn($e) => $e['state'] === 'open') !== []));
$reads0 = xeric_constructs_presence_reads();
xeric_constructs_tick($T, $dbK, $friEarly);
ok('kept: presence is resolved once a tick, however many holders have something open',
    $holders >= 3 && xeric_constructs_presence_reads() - $reads0 === 1, $holders . ' holders, '
    . (xeric_constructs_presence_reads() - $reads0) . ' reads');
$reads0 = xeric_constructs_presence_reads();
xeric_expect_keep_present($T, $dbK, 'janelle', $friEarly);
ok('kept: and a caller with nothing in hand still gets it read for them', xeric_constructs_presence_reads() - $reads0 === 1);

// THE BODY OF WHOEVER PROMISED (M4, a body each). Ann promised Ruth the dish;
// the tick runs as the first player, whose body is the one the keep used to
// read. Walt walking in keeps nothing of Ann's; Ann walking in keeps it, and
// the trust it earns is Ann's.
$dbK2Path = sys_get_temp_dir() . '/xeric-constructs-kept2-' . getmypid() . '.db';
foreach ([$dbK2Path, $dbK2Path . '-wal', $dbK2Path . '-shm'] as $f) @unlink($f);
$dbK2 = xeric_state_open($dbK2Path);
$annId = xeric_player_add($dbK2, $T, 'Ann');
$kAnn = xeric_expect_form($T, $dbK2, 'ruth', $dish, $wedAm, null, null, $annId);
ok('kept, two: Ann\'s promise is filed as hers', $kAnn !== null
    && (int)(array_values(array_filter(xeric_expects_for($dbK2, 'ruth'), fn($e) => $e['key'] === $kAnn))[0]['p'] ?? 0) === $annId);
xeric_player_move($dbK2, 'beck_hardware', (int)$wedAm['epoch'] - 60, $annId);
xeric_player_move($dbK2, 'bluebird', ep('2026-07-31 07:15'), 1);
xeric_player_current(fn() => 1);
xeric_constructs_tick($T, $dbK2, $friEarly);
ok('kept, two: Walt walking into the diner does not keep the promise Ann made',
    $stateOf($dbK2, 'ruth', (string)$kAnn) === 'open' && xeric_trust_of($dbK2, 'ruth') === 0);
xeric_player_move($dbK2, 'bluebird', ep('2026-07-31 07:20'), $annId);
xeric_player_move($dbK2, 'beck_hardware', ep('2026-07-31 07:21'), 1);
xeric_constructs_tick($T, $dbK2, $friEarly);
ok('kept, two: Ann\'s own body walking in keeps it — read off her position, not the current player\'s',
    $stateOf($dbK2, 'ruth', (string)$kAnn) === 'kept'
    && xeric_trust_of($dbK2, 'ruth', null, $annId) === 1 && xeric_trust_of($dbK2, 'ruth') === 0,
    json_encode(xeric_expects_for($dbK2, 'ruth')));
xeric_player_current(fn() => 1);
$dbK2 = null;
foreach ([$dbK2Path, $dbK2Path . '-wal', $dbK2Path . '-shm'] as $f) @unlink($f);

$dbK = null;
foreach ([$dbKPath, $dbKPath . '-wal', $dbKPath . '-shm'] as $f) @unlink($f);

foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm',
          $db2Path, $db2Path . '-wal', $db2Path . '-shm',
          $dbFPath, $dbFPath . '-wal', $dbFPath . '-shm'] as $f) @unlink($f);

// ---------------------------------------------------------------------------
// 14. THE SIBLINGS, by fuse length. Each: a trigger, coarse epochs, a licence
// (or none), a residue, a name the engine arms, and an inspector line.
// ---------------------------------------------------------------------------

echo "\n# the siblings\n";

require_once __DIR__ . '/../proactive.php';
require_once __DIR__ . '/../travel.php';

$sibDbs = [];
$sibDb = function (string $tag) use (&$sibDbs): PDO {
    $p = sys_get_temp_dir() . '/xeric-sib-' . $tag . '-' . getmypid() . '.db';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $sibDbs[] = $p;
    $d = xeric_state_open($p);
    xeric_state_migrate($d);
    return $d;
};
$at = fn(string $w) => xeric_world_now($T, ep($w));
$talk = function (PDO $d, string $h, array $times) {
    $cid = xeric_conversation_for($d, $h, 'chat');
    foreach ($times as $w) {
        xeric_message_append($d, $cid, 'user', null, 'Morning.', ep($w));
        xeric_message_append($d, $cid, 'character', $h, 'Morning yourself.', ep($w) + 60);
    }
    return $cid;
};
$noDigits = fn(string $s) => !preg_match('/\d/', (string)preg_replace('/^.*?ON YOUR MIND/s', '', $s));
$mind = fn(PDO $d, string $h) => xeric_siblings_block($T, $d, $h);
$stub = ['stub' => fn(string $tag, array $m, array $o = []) => ['text' => 'You still on for it?'], 'model' => 'stub'];

// -- arming, by name and by preset -------------------------------------------
ok('arm: a world with no systems record gets every sibling',
    xeric_sibling_armed($T, 'anticipation') && xeric_sibling_armed($T, 'milestone'));
ok('arm: each arms by its own name in forge.armed',
    xeric_sibling_armed(['forge' => ['armed' => ['rituals']]], 'ritual')
    && xeric_sibling_armed(['forge' => ['armed' => ['worry']]], 'worry')
    && !xeric_sibling_armed(['forge' => ['armed' => ['rituals']]], 'worry'));
ok('arm: and by the motivation presets\' systems, as debts arm with favors',
    xeric_sibling_armed(['forge' => ['armed' => ['gentle_proactive_contact']]], 'news')
    && xeric_sibling_armed(['forge' => ['armed' => ['daily_rhythms']]], 'ritual')
    && xeric_sibling_armed(['forge' => ['armed' => ['expectations']]], 'grudge'));

// -- anticipation: Dot, "Friday at noon" -------------------------------------
$dA = $sibDb('ant');
$mon = $at('2026-08-03 10:00');                                        // a Monday
$kA = xeric_expect_form($T, $dA, 'dot', ['quote' => "I'll be there Friday at noon", 'what' => 'lunch at the counter',
                                          'when' => 'Friday at noon'], $mon);
$antKey = 'anticipate.' . $kA;
ok('anticipation: a promise made days ahead is also something to look forward to',
    (xeric_sibling_get($dA, 'dot', $antKey)['state'] ?? '') === 'ahead');
$bA = $mind($dA, 'dot');
ok('anticipation: coarse — the day, never a clock or a countdown',
    str_contains($bA, 'looking forward to lunch at the counter') && str_contains($bA, 'Friday') && $noDigits($bA), $bA);
xeric_constructs_tick($T, $dA, $at('2026-08-04 10:00'));
ok('anticipation: byte-stable until the epoch moves', $mind($dA, 'dot') === $bA);
xeric_constructs_tick($T, $dA, $at('2026-08-06 10:00'));            // Thursday, the eve
$lic = array_values(array_filter(xeric_constructs_licences($T, $dA), fn($l) => $l['kind'] === 'anticipation'));
ok('anticipation: the day before it licenses one line', count($lic) === 1 && $lic[0]['handle'] === 'dot');
ok('anticipation: and the block says tomorrow', str_contains($mind($dA, 'dot'), 'Tomorrow is lunch at the counter'));
xeric_constructs_tick($T, $dA, $at('2026-08-07 08:00'));
ok('anticipation: on the day the unused licence lapses',
    xeric_constructs_licences($T, $dA) === [] && str_contains($mind($dA, 'dot'), 'Today is lunch'));
xeric_player_move($dA, 'bluebird', ep('2026-08-07 11:50'));
xeric_constructs_tick($T, $dA, $at('2026-08-07 12:10'));
ok('anticipation: kept, it resolves — and the residue is a memory',
    (xeric_sibling_get($dA, 'dot', $antKey)['state'] ?? '') === 'happened'
    && str_contains((string)(xeric_memories_for($dA, 'dot', 3)[1]['text'] ?? '') . (xeric_memories_for($dA, 'dot', 3)[0]['text'] ?? ''), 'looking forward to lunch'));
ok('anticipation: and leaves the block', !str_contains($mind($dA, 'dot'), 'looking forward'));
ok('anticipation: inspector line', str_contains(implode("\n", xeric_constructs_why($T, $dA, 'dot')), 'anticipation (happened)'));
$dA2 = $sibDb('ant2');
$kA2 = xeric_expect_form($T, $dA2, 'dot', ['quote' => "I'll be there Friday at noon", 'what' => 'lunch', 'when' => 'Friday at noon'], $mon);
xeric_constructs_tick($T, $dA2, $at('2026-08-06 10:00'));
xeric_constructs_tick($T, $dA2, $at('2026-08-08 10:00'));            // nobody came
ok('anticipation: missed, the residue is a memory too',
    (xeric_sibling_get($dA2, 'dot', 'anticipate.' . $kA2)['state'] ?? '') === 'let_down');

// -- news: Ruth was in an hour, and has not seen you since ------------------
$dN = $sibDb('news');
$talk($dN, 'ruth', ['2026-08-03 09:00']);
$evN = xeric_event_add($dN, 'The hymn boards came down in the vestry', ep('2026-08-03 14:00'), 'first_lutheran',
                       ['ruth', 'pastor_dale'], 'Ruth held the ladder.');
xeric_constructs_tick($T, $dN, $at('2026-08-03 15:00'));
ok('news: an hour she was in, since the thread last moved, is something to tell',
    (xeric_sibling_get($dN, 'ruth', 'news')['state'] ?? '') === 'fresh');
ok('news: inside the first day it rides no prompt — the aftermath rung has it',
    !str_contains($mind($dN, 'ruth'), 'hymn boards'));
ok('news: a stranger has nothing to tell you', xeric_sibling_get($dN, 'pastor_dale', 'news') === null);
xeric_constructs_tick($T, $dN, $at('2026-08-04 15:30'));
ok('news: after a day it licenses a text-first, and renders',
    count(array_filter(xeric_constructs_licences($T, $dN), fn($l) => $l['kind'] === 'news')) === 1
    && str_contains($mind($dN, 'ruth'), 'has not heard from you yet: The hymn boards came down'));
$pN = xeric_proactive_check($T, $dN, $stub, $at('2026-08-04 15:40'), ['events' => []]);
ok('news: the proactive rung uses the licence', ($pN['kind'] ?? '') === 'construct:news' && ($pN['handle'] ?? '') === 'ruth',
    json_encode($pN));
ok('news: and the residue is told', (xeric_sibling_get($dN, 'ruth', 'news')['state'] ?? '') === 'told'
    && xeric_constructs_licences($T, $dN) === []);
ok('news: inspector line', str_contains(implode("\n", xeric_constructs_why($T, $dN, 'ruth')), 'news (told)'));

// -- worry: Dot, after a scripted silence -----------------------------------
$dW = $sibDb('worry');
$talk($dW, 'dot', ['2026-08-03 09:00', '2026-08-04 09:00', '2026-08-05 09:00', '2026-08-06 09:00']);
xeric_constructs_tick($T, $dW, $at('2026-08-07 12:00'));
ok('worry: a day and a bit of quiet is inside the usual — no worry', xeric_sibling_get($dW, 'dot', 'worry') === null);
xeric_constructs_tick($T, $dW, $at('2026-08-08 12:00'));
ok('worry: past twice the usual gap, she worries, and it licenses a text',
    (xeric_sibling_get($dW, 'dot', 'worry')['state'] ?? '') === 'worried'
    && count(array_filter(xeric_constructs_licences($T, $dW), fn($l) => $l['kind'] === 'worry')) === 1);
ok('worry: coarse in the block', str_contains($mind($dW, 'dot'), 'longer than usual') && $noDigits($mind($dW, 'dot')));
$talk($dW, 'dot', ['2026-08-08 18:00']);
xeric_constructs_tick($T, $dW, $at('2026-08-08 18:30'));
ok('worry: you came back — relief, and the relief is a memory',
    (xeric_sibling_get($dW, 'dot', 'worry')['state'] ?? '') === 'relieved'
    && str_contains((string)(xeric_memories_for($dW, 'dot', 1)[0]['text'] ?? ''), 'heard from them'));
$dW2 = $sibDb('worry2');
$talk($dW2, 'dot', ['2026-08-03 09:00', '2026-08-04 09:00', '2026-08-05 09:00', '2026-08-06 09:00']);
xeric_constructs_tick($T, $dW2, $at('2026-08-08 12:00'));
xeric_constructs_tick($T, $dW2, $at('2026-08-12 12:00'));
ok('worry: a silence long past it goes sore — the grudge seed',
    (xeric_sibling_get($dW2, 'dot', 'worry')['state'] ?? '') === 'sore' && str_contains($mind($dW2, 'dot'), 'went quiet on you'));
ok('worry: inspector line', str_contains(implode("\n", xeric_constructs_why($T, $dW2, 'dot')), 'worry (sore)'));

// -- grudge: an unrepaired miss hardens -------------------------------------
$dG = $sibDb('grudge');
xeric_expect_form($T, $dG, 'harlan', ['quote' => "I'll be at the store Tuesday morning", 'what' => 'the paint order',
                                       'when' => 'Tuesday morning'], $mon);
xeric_constructs_tick($T, $dG, $at('2026-08-04 18:00'));            // missed
ok('grudge: a fresh miss is not a grudge — the repair window is open', xeric_sibling_rows($dG, 'harlan', 'grudge.') === []);
xeric_constructs_tick($T, $dG, $at('2026-08-12 18:00'));            // hardened
$g = array_values(xeric_sibling_rows($dG, 'harlan', 'grudge.'));
ok('grudge: never explained inside the window, it hardens cold',
    count($g) === 1 && $g[0]['state'] === 'cold' && str_contains($mind($dG, 'harlan'), 'You have not forgiven Walt for missing the paint order'));
ok('grudge: it licenses nothing', xeric_constructs_licences($T, $dG) === []);
ok('grudge: and it explains itself', str_contains(implode("\n", xeric_constructs_why($T, $dG, 'harlan')), 'grudge (cold)'));
xeric_constructs_tick($T, $dG, $at('2026-08-27 18:00'));
ok('grudge: cold goes cool', str_contains($mind($dG, 'harlan'), 'though less than it did'));
ok('grudge: the repair path — an explanation, late, thaws it',
    xeric_grudge_thaw($T, $dG, 'harlan', '', XERIC_PLAYER_FIRST, $at('2026-08-27 19:00')) === 1
    && str_contains($mind($dG, 'harlan'), 'are thawing'));
xeric_constructs_tick($T, $dG, $at('2026-09-05 19:00'));
ok('grudge: and thawing settles into a memory',
    (array_values(xeric_sibling_rows($dG, 'harlan', 'grudge.'))[0]['state'] ?? '') === 'settled'
    && !str_contains($mind($dG, 'harlan'), 'paint order'));

// -- ritual: three mornings at the Bluebird ---------------------------------
$dR = $sibDb('ritual');
foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $d) {
    xeric_player_move($dR, 'bluebird', ep($d . ' 08:15'));
    xeric_constructs_tick($T, $dR, $at($d . ' 08:20'));
    xeric_player_move($dR, null, ep($d . ' 09:00'));
}
$rit = xeric_sibling_get($dR, 'dot', 'ritual.bluebird');
ok('ritual: the third morning at the same place and hour is a ritual, held by whoever was there',
    ($rit['state'] ?? '') === 'kept' && xeric_sibling_get($dR, 'harlan', 'ritual.bluebird') === null, json_encode($rit));
ok('ritual: coarse — mornings, not a time', str_contains($mind($dR, 'dot'), 'comes into the Bluebird most mornings')
    || str_contains($mind($dR, 'dot'), 'most mornings'), $mind($dR, 'dot'));
xeric_constructs_tick($T, $dR, $at('2026-08-06 09:30'));
ok('ritual: inside the grace nothing is missed', (xeric_sibling_get($dR, 'dot', 'ritual.bluebird')['state'] ?? '') === 'kept');
xeric_constructs_tick($T, $dR, $at('2026-08-06 10:30'));
ok('ritual: a morning without you is noticed, and licenses a line',
    (xeric_sibling_get($dR, 'dot', 'ritual.bluebird')['state'] ?? '') === 'missed'
    && count(array_filter(xeric_constructs_licences($T, $dR), fn($l) => $l['kind'] === 'ritual')) === 1);
xeric_player_move($dR, 'bluebird', ep('2026-08-07 08:10'));
xeric_constructs_tick($T, $dR, $at('2026-08-07 08:15'));
ok('ritual: and turning up again keeps it', (xeric_sibling_get($dR, 'dot', 'ritual.bluebird')['state'] ?? '') === 'kept'
    && xeric_constructs_licences($T, $dR) === []);
ok('ritual: inspector line', str_contains(implode("\n", xeric_constructs_why($T, $dR, 'dot')), 'ritual (kept)'));

// A BODY EACH, for the ritual too: the owner's three mornings at the Bluebird,
// two of whose ticks ran on a guest's request while the guest stood at the
// hardware store. The pass reads every body, so the ritual is still the
// owner's — and the guest's own two visits to Beck's form nothing of theirs.
$dR2 = $sibDb('ritual-two');
$rAnn = xeric_player_add($dR2, $T, 'Ann');
xeric_player_move($dR2, 'beck_hardware', ep('2026-08-03 06:00'), $rAnn);
foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $i => $d) {
    xeric_player_move($dR2, 'bluebird', ep($d . ' 08:15'), 1);
    xeric_player_current(fn() => $i === 1 ? 1 : $rAnn);
    xeric_constructs_tick($T, $dR2, $at($d . ' 08:20'));
    xeric_player_move($dR2, null, ep($d . ' 09:00'), 1);
}
xeric_player_current(fn() => 1);
$rit2 = xeric_sibling_get($dR2, 'dot', 'ritual.bluebird');
ok('ritual, two: the owner\'s three mornings form the ritual even when a guest\'s request ran two of the ticks',
    ($rit2['state'] ?? '') === 'kept' && !isset($rit2['p'])
    && xeric_sibling_rows($dR2, 'dot', 'ritual.bluebird.p') === [] && xeric_sibling_rows($dR2, 'harlan', 'ritual.') === [],
    json_encode([$rit2, xeric_arc_get($dR2, xeric_arc_world(), 'ritual.visits'), xeric_arc_get($dR2, xeric_arc_world(), 'ritual.visits.p' . $rAnn)]));
ok('ritual, two: each body keeps its own visit log', count(json_decode((string)xeric_arc_get($dR2, xeric_arc_world(), 'ritual.visits'), true) ?: []) === 3
    && count(json_decode((string)xeric_arc_get($dR2, xeric_arc_world(), 'ritual.visits.p' . $rAnn), true) ?: []) === 1);
// And the miss is the owner's, named as the owner, on a morning the guest's tick runs.
xeric_player_current(fn() => $rAnn);
xeric_constructs_tick($T, $dR2, $at('2026-08-06 10:30'));
xeric_player_current(fn() => 1);
ok('ritual, two: the morning the owner does not come is the owner\'s miss, whoever\'s request noticed it',
    (xeric_sibling_get($dR2, 'dot', 'ritual.bluebird')['state'] ?? '') === 'missed'
    && str_contains((string)(xeric_memories_for($dR2, 'dot', 1)[0]['text'] ?? ''), 'Walt did not come into'),
    (string)(xeric_memories_for($dR2, 'dot', 1)[0]['text'] ?? ''));

// -- milestone: the tenth on a ledger, once ---------------------------------
$dM = $sibDb('mile');
xeric_arc_set($dM, 'ruth', 'economy.casserole_ledger', 10);
xeric_constructs_tick($T, $dM, $mon);
$ms = xeric_sibling_get($dM, 'ruth', 'milestone.casserole_ledger.10');
ok('milestone: a count crossing ten is noticed, and licenses one line',
    ($ms['state'] ?? '') === 'due' && str_contains($mind($dM, 'ruth'), 'your tenth on the casserole ledger')
    && $noDigits($mind($dM, 'ruth')), $mind($dM, 'ruth'));
xeric_constructs_licence_spend($T, $dM, 'ruth', 'milestone.casserole_ledger.10', (int)$mon['epoch']);
xeric_arc_set($dM, 'ruth', 'economy.casserole_ledger', 11);
xeric_constructs_tick($T, $dM, $at('2026-08-04 10:00'));
ok('milestone: once — said, it is a memory and never again',
    (xeric_sibling_get($dM, 'ruth', 'milestone.casserole_ledger.10')['state'] ?? '') === 'marked'
    && !str_contains($mind($dM, 'ruth'), 'tenth') && xeric_constructs_licences($T, $dM) === []);
ok('milestone: a ledger her walls hide is never counted for her',
    (function () use ($T, $sibDb) {
        $d = $sibDb('mile2');
        xeric_arc_set($d, 'janelle', 'economy.thursday_pot', 12);
        xeric_constructs_tick($T, $d, xeric_world_now($T, ep('2026-08-03 10:00')));
        return xeric_sibling_get($d, 'janelle', 'milestone.thursday_pot.10') === null;
    })());
ok('milestone: inspector line', str_contains(implode("\n", xeric_constructs_why($T, $dM, 'ruth')), 'milestone (marked)'));

// ---------------------------------------------------------------------------
// 15. CAST TO CAST, out of the sweep's own prose, through the walls.
// ---------------------------------------------------------------------------

echo "\n# the town's promises, out of its hours\n";

$dC = $sibDb('cast');
xeric_constructs_cast_tick($T, $dC, $mon);   // the reader arrives first: its watermark is set at the world's newest event
xeric_event_add($dC, 'The vestry after the service', ep('2026-08-03 11:00'), 'first_lutheran', ['ruth', 'pastor_dale'],
    'They stacked the chairs. "I will have the hymn boards done Wednesday morning," Ruth said. The pastor nodded.');
xeric_event_add($dC, 'Lunch at the counter', ep('2026-08-03 12:00'), 'bluebird', ['harlan', 'dot'],
    '"I will bring the ladder round Thursday morning," Harlan said, and finished his pie.');
$cr = xeric_constructs_cast_tick($T, $dC, $mon);
ok('cast: promises in a sweep\'s prose form expectations between the two of them',
    $cr['formed'] === 2 && (xeric_expects_for($dC, 'pastor_dale')[0]['of'] ?? '') === 'ruth'
    && (xeric_expects_for($dC, 'dot')[0]['of'] ?? '') === 'harlan', json_encode($cr));
ok('cast: and the read is once — a second tick forms nothing', xeric_constructs_cast_tick($T, $dC, $mon)['formed'] === 0);
xeric_constructs_tick($T, $dC, $at('2026-08-05 10:00'));          // their week puts them in the vestry
ok('cast: kept when their schedules put them in a room',
    (xeric_expects_for($dC, 'pastor_dale')[0]['state'] ?? '') === 'kept');
xeric_constructs_tick($T, $dC, $at('2026-08-06 14:00'));          // Harlan never comes by the Bluebird
ok('cast: missed otherwise', (xeric_expects_for($dC, 'dot')[0]['state'] ?? '') === 'missed');
$res = xeric_constructs_cast_tick($T, $dC, $at('2026-08-06 14:05'));
ok('cast: the residue is a memory each — the promiser keeps the other half',
    $res['residue'] === 2
    && str_contains((string)(xeric_memories_for($dC, 'ruth', 1)[0]['text'] ?? ''), 'and did.')
    && str_contains((string)(xeric_memories_for($dC, 'harlan', 1)[0]['text'] ?? ''), 'did not make it'));
ok('cast: and only once', xeric_constructs_cast_tick($T, $dC, $at('2026-08-06 14:10'))['residue'] === 0);

// THE WALL NEGATIVE. A promise between townspeople that quotes the Thursday
// pot — hidden from Janelle by family_innocence — never reaches her prompt:
// she is in the room for it and forms nothing, the one it was said to forms
// it, and the miss, the ripple and the residue all leave her prompt clean.
$dX = $sibDb('wall');
xeric_constructs_cast_tick($T, $dX, $mon);
xeric_event_add($dX, 'Setting up in the basement', ep('2026-08-03 18:00'), 'first_lutheran', ['harlan', 'pastor_dale', 'janelle'],
    '"Dale, I will put the chips back in the coffee can behind the folding chairs Thursday morning," Harlan said.');
xeric_event_add($dX, 'Stacking chairs', ep('2026-08-03 19:00'), 'first_lutheran', ['harlan', 'janelle'],
    '"I will put the chips back in the coffee can behind the folding chairs Thursday morning," Harlan said.');
xeric_constructs_cast_tick($T, $dX, $mon);
ok('wall: the entitled listener forms it', count(xeric_expects_for($dX, 'pastor_dale')) === 1);
ok('wall: the walled listener forms nothing, even said to her face', xeric_expects_for($dX, 'janelle') === []);
foreach (['2026-08-06 14:00', '2026-08-07 10:00', '2026-08-09 10:00', '2026-08-16 10:00'] as $w) {
    xeric_constructs_tick($T, $dX, $at($w));
    xeric_constructs_cast_tick($T, $dX, $at($w));
}
$jan = implode("\n", array_map(fn($m) => (string)$m['content'], xeric_prompt_build($T, $dX, 'janelle', $at('2026-08-16 10:00'), ['user_message' => 'hi'])));
ok('wall: and it never renders into her prompt — not the quote, not the can', !str_contains(mb_strtolower($jan), 'folding chairs')
    && !str_contains(mb_strtolower($jan), 'coffee can'));
ok('wall: while the one it was owed to still carries it',
    str_contains(xeric_expect_block($T, $dX, 'pastor_dale', $at('2026-08-16 10:00')), 'coffee can'));

// HISTORY IS NOT OWED. A world that lived three hundred hours before this
// reader existed — every one of them a quoted, datable promise between two
// people — must not replay them on its first cast tick: each would form an
// expectation due long ago, and the next fuse tick would fire them all as one
// mass of misses. The first tick sets the watermark and forms nothing; the
// next hour lived after it is read as usual.
$dH = $sibDb('history');
for ($i = 0; $i < 300; $i++) {
    xeric_event_add($dH, 'Lunch at the counter ' . $i, ep('2026-05-01 12:00') + $i * 3600, 'bluebird', ['harlan', 'dot'],
        '"I will bring the ladder round Thursday morning," Harlan said, and finished his pie.');
}
$hr = xeric_constructs_cast_tick($T, $dH, $mon);
ok('history: a world with 300 old hours of quoted promises forms nothing on its first cast tick',
    $hr['formed'] === 0 && xeric_expects_for($dH, 'dot') === []
    && (int)xeric_arc_get($dH, xeric_arc_world(), 'constructs.cast.seen') === (int)$dH->query('SELECT MAX(id) FROM events')->fetchColumn(),
    json_encode($hr));
ok('history: and the fuse has no born-missed promise to fire',
    (function () use ($T, $dH, $at) { xeric_constructs_tick($T, $dH, $at('2026-08-03 10:05'));
        return array_filter(xeric_expects_for($dH, 'dot'), fn($e) => $e['state'] === 'missed') === []; })());
xeric_event_add($dH, 'Lunch again', ep('2026-08-03 12:00'), 'bluebird', ['harlan', 'dot'],
    '"I will bring the ladder round Thursday morning," Harlan said, and finished his pie.');
ok('history: an hour lived after the watermark forms as usual',
    xeric_constructs_cast_tick($T, $dH, $mon)['formed'] === 1 && (xeric_expects_for($dH, 'dot')[0]['of'] ?? '') === 'harlan');
// And a backlog read late: an hour said on Monday, read on Saturday, whose
// Thursday is behind the reading, forms nothing.
xeric_event_add($dH, 'Lunch, Monday', ep('2026-08-10 12:00'), 'bluebird', ['harlan', 'ruth'],
    '"I will bring the ladder round Thursday morning," Harlan said, and finished his pie.');
ok('history: a due already past at the tick reading it forms nothing, even said in time',
    xeric_constructs_cast_tick($T, $dH, $at('2026-08-15 10:00'))['formed'] === 0 && xeric_expects_for($dH, 'ruth') === []);

foreach ($sibDbs as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo $FAILED === 0 ? "\nPASS\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
