<?php
/**
 * Xeric — room tests. `php engine/tests/room-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL. Every call goes through the stub seam, because what is
 * worth defending here is the constitution, not the prose:
 *
 *   - N people is N assemblies: each spoken line is its own call, from its own
 *     speaker's own assembly, and the needle test is PAIRWISE — every byte of
 *     every call each speaker's model receives, grepped for what the walls
 *     keep from that reader, with each owner keeping their own as the control.
 *   - who answers is a draw with receipts: addressed-by-name outranks, material
 *     tilts, the trail records the weights.
 *   - silence is counted, licensed once per stretch, and never forced.
 *   - a schedule that moves somebody out of the room mid-scene moves them out
 *     of the conversation: stage direction, no more calls, a diary that reads
 *     only what they were in the room to hear.
 *   - one minor clamps EVERY head's calls; the wall check follows who is
 *     STANDING there; read-only until a one-transaction close; diaries diverge
 *     N ways; the record is commons.
 */

declare(strict_types=1);

require_once __DIR__ . '/../room.php';

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

/** Run $fn, return the exception message it threw, or '' if it didn't. */
function err(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

function ep(string $when, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();
}

const FIXTURE = __DIR__ . '/../fixtures/milldale.json';

$T = xeric_world_load(FIXTURE);

// The fixture's geometry, leaned on throughout (see milldale.json weeks):
//   Sun 10:00  pastor_dale (to 12:30), ruth (to 12:00) and janelle (to 13:00)
//              are all at first_lutheran — the only three-strong hour Milldale
//              has, which is exactly why the church basement is where the
//              engine learns to hold a room.
//   Sun 11:52  the same three, eight minutes before ruth's block ends: at two
//              scene-minutes a beat with a re-read every three beats, the
//              12:04 re-read finds her gone. Geometry as test harness.
//   Sat 10:00  harlan and theo at beck_hardware, dot at the Bluebird — three
//              people, two rooms, for the refusal that names every stand.
$SUN   = xeric_world_now($T, ep('2026-08-02 10:00'));
$SUNPM = xeric_world_now($T, ep('2026-08-02 11:52'));
$SAT   = xeric_world_now($T, ep('2026-08-01 10:00'));

$TRIO = ['pastor_dale', 'ruth', 'janelle'];

// The needles, pairwise. Walls and gossip grades decide the legal matrix, and
// the test asserts the matrix rather than a blanket: dale's poker secret is
// gossip_grade true (shared-bible material for unwalled adults) but janelle's
// walls hide secrets, so not one byte of it may reach her calls; ruth's
// notebook and janelle's glovebox printout are gossip_grade FALSE — owner and
// narrator only — so they may reach nobody's calls but their own. Janelle's
// needle is 'glovebox' and NOT 'Chillicothe' on purpose: the town's name also
// rides the casserole economy's commons rules ("a ride to the clinic in
// Chillicothe counts double"), so it is in everybody's bible legitimately and
// a needle made of it would cry leak at a rule the whole town recites.
const DALE_SECRET    = 'five-card draw';
const RUTH_SECRET    = 'green spiral notebook';
const JANELLE_SECRET = 'glovebox';

/** A throwaway world db. Every test that writes gets its own. */
function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-room-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    $db = xeric_state_open($path);
    xeric_state_seed($db, $GLOBALS['T'] ?? xeric_world_load(FIXTURE));
    return $db;
}
$GLOBALS['T'] = $T;
$DBFILES = [];

/**
 * The standard stub: numbered spoken lines, per-speaker diaries, and a full
 * capture of every call — tag, messages, and (via $probe) what the database
 * looked like WHILE the model was thinking.
 */
function stub_room(array &$calls, array $diaries = [], ?callable $probe = null, ?callable $lines = null): array
{
    $n = 0;
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts)
            use (&$calls, &$n, $diaries, $probe, $lines) {
        $calls[] = ['tag' => $tag, 'msgs' => $msgs, 'db' => $probe !== null ? $probe() : null];
        if ($tag === 'chat') {
            $n++;
            return $lines !== null ? $lines($n, $msgs) : "spoken line $n, nothing much.";
        }
        if ($tag === 'extract') {
            foreach ($diaries as $name => $mem) {
                if (str_contains((string)$msgs[1]['content'], $name . ' would still know')) {
                    if ($mem instanceof Closure) return $mem();
                    return ['memories' => $mem];
                }
            }
            return ['memories' => []];
        }
        return ['memories' => []];
    }];
}

/** The row speak.php would voice — xeric_play_spoken()'s query, without the web layer. */
function xeric_play_spoken_like(PDO $db, int $id): bool
{
    $st = $db->prepare("SELECT id FROM messages WHERE id = ? AND role = 'character' AND handle IS NOT NULL AND handle != ''");
    $st->execute([$id]);
    return $st->fetchColumn() !== false;
}

function whose(array $call): string
{
    if ($call['tag'] === 'extract') {
        $u = (string)$call['msgs'][1]['content'];
        foreach (['Ruth Amberg' => 'ruth', 'Dot Vance' => 'dot', 'Harlan Beck' => 'harlan',
                  'Theo Vance' => 'theo', 'Pastor Dale Ostrander' => 'pastor_dale',
                  'Janelle Kerr' => 'janelle'] as $name => $h) {
            if (str_contains($u, $name . ' would still know')) return $h;
        }
        return '?';
    }
    $sys = (string)$call['msgs'][0]['content'];
    if (str_contains($sys, 'YOU ARE RUTH AMBERG')) return 'ruth';
    if (str_contains($sys, 'YOU ARE DOT VANCE')) return 'dot';
    if (str_contains($sys, 'YOU ARE HARLAN BECK')) return 'harlan';
    if (str_contains($sys, 'YOU ARE THEO VANCE')) return 'theo';
    if (str_contains($sys, 'YOU ARE PASTOR DALE OSTRANDER')) return 'pastor_dale';
    if (str_contains($sys, 'YOU ARE JANELLE KERR')) return 'janelle';
    return '?';
}

/** The trail a close left behind, decoded. */
function trail(PDO $db, int $eventId): array
{
    return json_decode((string)xeric_world_state_get($db, 'why:event:' . $eventId), true) ?: [];
}

/** A copy of the template with one character's week rows swapped or added. */
function reweek(array $t, string $handle, array $week): array
{
    foreach ($t['cast']['characters'] as &$c) {
        if (($c['handle'] ?? '') === $handle) { $c['week'] = $week; break; }
    }
    return $t;
}

// ---------------------------------------------------------------------------
// 1. Admission: the roster, and the refusals that name the geography
// ---------------------------------------------------------------------------

$db = fresh_db('refusals');
$deadStub = ['base' => 'stub://', 'stub' => function () { throw new RuntimeException('the model was reached, and must not have been'); }];

ok('refuse: two people are a duet, and the refusal says which door to use',
    str_contains(err(fn() => xeric_room($T, $db, ['ruth', 'pastor_dale'], $SUN, $deadStub)), 'duet'));
ok('refuse: six people is a crowd, not a conversation',
    str_contains(err(fn() => xeric_room($T, $db,
        ['ruth', 'pastor_dale', 'janelle', 'dot', 'harlan', 'theo'], $SUN, $deadStub)), 'crowd'));
ok('refuse: the same person cannot stand in a room twice',
    str_contains(err(fn() => xeric_room($T, $db, ['ruth', 'ruth', 'janelle'], $SUN, $deadStub)), 'twice'));
ok('refuse: an unknown handle is named, loudly',
    str_contains(err(fn() => xeric_room($T, $db, ['ruth', 'pastor_dale', 'nobody_here'], $SUN, $deadStub)), "'nobody_here'"));
ok('refuse: a fixture cannot hold a corner of a conversation',
    str_contains(err(fn() => xeric_room($T, $db, ['ruth', 'pastor_dale', 'marisol'], $SUN, $deadStub)), 'scenery'));

$apart = err(fn() => xeric_room($T, $db, ['harlan', 'theo', 'dot'], $SAT, $deadStub));
ok('refuse: three people in two buildings, and the refusal names every stand',
    str_contains($apart, 'not all in a room together')
    && str_contains($apart, 'Beck Hardware') && str_contains($apart, 'Bluebird')
    && str_contains($apart, 'Harlan Beck') && str_contains($apart, 'Theo Vance')
    && str_contains($apart, 'Dot Vance'), $apart);

$nowhere = err(fn() => xeric_room($T, $db, ['pastor_dale', 'ruth', 'dot'], $SUN, $deadStub));
ok('refuse: somebody off any schedule is nowhere, and nowhere is not a room',
    str_contains($nowhere, 'Dot Vance is not at any of this world\'s places'), $nowhere);

ok('refuse: nothing was written by any refusal',
    xeric_events_count($db) === 0 && xeric_memories_count($db) === 0
    && xeric_conversations_count($db) === 0);

// The dead do not talk, even in company.
$dbD = fresh_db('dead');
xeric_death_kill($T, $dbD, 'ruth', $SUN['epoch'], 'her heart, at the urn', null, false);
$deadMsg = err(fn() => xeric_room($T, $dbD, $TRIO, $SUN, $deadStub));
ok('refuse: the dead do not answer, in the death module\'s own sentence',
    str_contains($deadMsg, 'Ruth Amberg') && str_contains($deadMsg, 'room'), $deadMsg);

// ---------------------------------------------------------------------------
// 2. The constitution: one call per line, the pairwise needle matrix
// ---------------------------------------------------------------------------

$db2   = fresh_db('walls');
$c2    = [];
$seen  = [];
$out = xeric_room($T, $db2, $TRIO, $SUN, stub_room($c2, [
    'Pastor Dale Ostrander' => ['Dale heard the gutters need doing before the frost.'],
    'Ruth Amberg'           => ['Ruth counted three dishes still out from the potluck.'],
    'Janelle Kerr'          => ['Janelle thought the drive in felt shorter than usual.'],
]), ['say_first' => 'pastor_dale', 'beats' => 6, 'seed' => 41,
     'on_line' => function (string $h, string $n, string $t2, string $k) use (&$seen) { $seen[] = [$h, $t2, $k]; }]);

$chat2    = array_values(array_filter($c2, fn($c) => $c['tag'] === 'chat'));
$extract2 = array_values(array_filter($c2, fn($c) => $c['tag'] === 'extract'));

ok('room: six beats is six model calls, plus one diary call per head',
    count($chat2) === 6 && count($extract2) === 3, count($chat2) . ' chat, ' . count($extract2) . ' extract');
ok('room: nobody ever answers themselves',
    !in_array(false, array_map(fn($i) => whose($chat2[$i]) !== whose($chat2[$i - 1]),
        range(1, count($chat2) - 1)), true),
    implode(',', array_map('whose', $chat2)));
ok('room: the pinned opener opens', whose($chat2[0]) === 'pastor_dale' && $out['spoke_first'] === 'pastor_dale');

// THE NEEDLE MATRIX, pairwise. Every byte of every call — system, transcript,
// tail, diary prompt — grepped per receiver against the legal matrix above.
$leak = '';
$own  = ['ruth' => false, 'pastor_dale' => false, 'janelle' => false];
foreach ($c2 as $i => $c) {
    $bytes = json_encode($c['msgs'], JSON_UNESCAPED_UNICODE);
    $who   = whose($c);
    if ($who === 'janelle' && str_contains($bytes, DALE_SECRET))    $leak = "call $i: dale's secret reached janelle";
    if ($who === 'janelle' && str_contains($bytes, RUTH_SECRET))    $leak = "call $i: ruth's secret reached janelle";
    if ($who === 'pastor_dale' && str_contains($bytes, RUTH_SECRET))    $leak = "call $i: ruth's ungossiped secret reached dale";
    if ($who === 'pastor_dale' && str_contains($bytes, JANELLE_SECRET)) $leak = "call $i: janelle's secret reached dale";
    if ($who === 'ruth' && str_contains($bytes, JANELLE_SECRET))    $leak = "call $i: janelle's secret reached ruth";
    if ($c['tag'] === 'chat' && str_contains($bytes, DALE_SECRET)    && $who === 'pastor_dale') $own['pastor_dale'] = true;
    if ($c['tag'] === 'chat' && str_contains($bytes, RUTH_SECRET)    && $who === 'ruth')        $own['ruth'] = true;
    if ($c['tag'] === 'chat' && str_contains($bytes, JANELLE_SECRET) && $who === 'janelle')     $own['janelle'] = true;
}
ok('WALLS: not one protected byte crosses to a reader whose walls or grade exclude it — pairwise', $leak === '', $leak);
ok('WALLS: every owner keeps their own interior (the three controls)',
    $own['ruth'] && $own['pastor_dale'] && $own['janelle'], json_encode($own));

// ---------------------------------------------------------------------------
// 3. The close: one event, three diaries, one trail — and nothing else
// ---------------------------------------------------------------------------

ok('close: one event row, titled as a sweep would title it',
    xeric_events_count($db2) === 1 && $out['title'] === 'pastor, ruth, and janelle talked');
$ev = xeric_events_recent($db2, 1)[0];
ok('close: the record is commons — every name, the place, and not one spoken word',
    str_contains((string)$ev['prose'], 'Pastor Dale Ostrander')
    && str_contains((string)$ev['prose'], 'Ruth Amberg')
    && str_contains((string)$ev['prose'], 'Janelle Kerr')
    && (string)$ev['place'] === 'first_lutheran'
    && !str_contains((string)$ev['prose'], 'spoken line'), (string)$ev['prose']);
ok('close: participants are the three of them and the hour is off the spine',
    $ev['participants'] === $TRIO && $ev['on_spine'] === false);

$tr = trail($db2, $out['event_id']);
ok('close: the trail lands under the inspector\'s own key, kind room',
    $tr !== [] && $tr['kind'] === 'room' && $tr['people'] === $TRIO);
ok('close: the trail says who spoke first and why the scene existed',
    $tr['spoke_first'] === 'pastor_dale'
    && str_contains((string)$tr['why'], 'First Lutheran')
    && str_contains((string)$tr['why'], 'spoke first'), (string)($tr['why'] ?? ''));
ok('close: the trail carries one record per beat, each naming its speaker and its how',
    count($tr['beats']) === 6
    && !in_array(false, array_map(fn($b) => isset($b['speaker'], $b['how']), $tr['beats']), true));
ok('close: the diaries diverge three ways, in the right heads',
    $out['memories']['pastor_dale'] === ['Dale heard the gutters need doing before the frost.']
    && $out['memories']['ruth'] === ['Ruth counted three dishes still out from the potluck.']
    && $out['memories']['janelle'] === ['Janelle thought the drive in felt shorter than usual.']);
$mem = xeric_memories_for($db2, 'ruth', 5);
$lastMem = $mem[count($mem) - 1];
ok('close: a diary row is marked room and carries the event, the company, the place',
    (string)$lastMem['source'] === 'room'
    && (int)$lastMem['meta']['event_id'] === (int)$out['event_id']
    && $lastMem['meta']['with'] === ['pastor_dale', 'janelle']
    && (string)$lastMem['meta']['place'] === 'first_lutheran');
ok('close: no thread was created — the transcript is watched, not texted',
    xeric_conversations_count($db2) === 0);
ok('close: the lines streamed live match the lines returned, kinds and all',
    count($seen) === 6 && $seen[0][0] === 'pastor_dale' && $seen[0][2] === 'line'
    && $seen[0][1] === $out['lines'][0]['text']);

// ---------------------------------------------------------------------------
// 4. Who answers: the addressed tier, the material heat, the receipts
// ---------------------------------------------------------------------------

// Addressed by name outranks: a line with a name on it hands the floor over,
// deterministically when only one person was named — no seed needed.
$db4 = fresh_db('addressed');
$c4  = [];
xeric_room($T, $db4, $TRIO, $SUN, stub_room($c4, [], null, fn(int $n) => [
    1 => 'Ruth, did you count the chairs?',
    2 => 'Janelle, tell him what you told me.',
    3 => 'Dale, I am not getting in the middle of it.',
    4 => 'Ruth, you started it.',
][$n] ?? 'Nothing much.'), ['say_first' => 'pastor_dale', 'beats' => 4]);
$chat4 = array_values(array_filter($c4, fn($c) => $c['tag'] === 'chat'));
ok('draw: the floor follows the name, beat after beat',
    array_map('whose', $chat4) === ['pastor_dale', 'ruth', 'janelle', 'pastor_dale'],
    implode(',', array_map('whose', $chat4)));
$tr4 = trail($db4, xeric_events_recent($db4, 1)[0]['id']);
ok('draw: the trail files those beats as addressed, with the names',
    $tr4['beats'][1]['how'] === 'addressed' && $tr4['beats'][1]['addressed'] === ['ruth']
    && $tr4['beats'][2]['how'] === 'addressed' && $tr4['beats'][2]['addressed'] === ['janelle']);

// Material heat: rows in the database tilt the dice, and the trail shows the
// exact weights, so the assertion is on the receipts rather than the roll.
$db5 = fresh_db('heat');
xeric_memory_add($db5, 'pastor_dale', 'Dale still owed Ruth an apology about the thermostat.', 'auto', [], $SUN['epoch'] - 86400);
xeric_memory_add($db5, 'pastor_dale', 'Dale had promised Ruth a straight answer on the gutters.', 'auto', [], $SUN['epoch'] - 86400);
$c5 = [];
xeric_room($T, $db5, $TRIO, $SUN, stub_room($c5), ['say_first' => 'ruth', 'beats' => 2, 'seed' => 7]);
$tr5 = trail($db5, xeric_events_recent($db5, 1)[0]['id']);
ok('draw: two unsettled things about the last speaker weigh 2.5 against a cold 1.0',
    abs((float)$tr5['beats'][1]['weights']['pastor_dale'] - 2.5) < 0.001
    && abs((float)$tr5['beats'][1]['weights']['janelle'] - 1.0) < 0.001,
    json_encode($tr5['beats'][1]));

// The expectation seam: dormant until constructs grow a second party, but the
// day an expect row carries `of`, it is already heat — exercised here by
// writing the future row shape directly.
$db6 = fresh_db('expect-seam');
xeric_arc_set($db6, 'janelle', 'expect.1', json_encode([
    'what' => 'to hear from Ruth about the kitchen', 'quote' => 'I will call you Sunday',
    'when_said' => 'sunday', 'due' => $SUN['epoch'] + 3600, 'formed' => $SUN['epoch'] - 86400,
    'state' => 'open', 'of' => 'ruth',
]));
$c6 = [];
xeric_room($T, $db6, $TRIO, $SUN, stub_room($c6), ['say_first' => 'ruth', 'beats' => 2, 'seed' => 7]);
$tr6 = trail($db6, xeric_events_recent($db6, 1)[0]['id']);
ok('draw: an open expectation naming the last speaker is one notch of heat (the seam, lit)',
    abs((float)$tr6['beats'][1]['weights']['janelle'] - 1.75) < 0.001, json_encode($tr6['beats'][1]));

// Reproducibility: the same seed deals the same room.
$db7a = fresh_db('seed-a'); $c7a = [];
$o7a  = xeric_room($T, $db7a, $TRIO, $SUN, stub_room($c7a), ['seed' => 41, 'beats' => 5]);
$db7b = fresh_db('seed-b'); $c7b = [];
$o7b  = xeric_room($T, $db7b, $TRIO, $SUN, stub_room($c7b), ['seed' => 41, 'beats' => 5]);
ok('draw: the same seed deals the same conversation',
    $o7a['spoke_first'] === $o7b['spoke_first'] && $o7a['last_word'] === $o7b['last_word']
    && array_map('whose', array_filter($c7a, fn($c) => $c['tag'] === 'chat'))
       === array_map('whose', array_filter($c7b, fn($c) => $c['tag'] === 'chat')));
$db7c = fresh_db('default-beats');
$c7c  = [];
$o7c  = xeric_room($T, $db7c, $TRIO, $SUN, stub_room($c7c), ['seed' => 3]);
ok('draw: an unpinned room of three is nine beats — three lines a head',
    $o7c['beats'] === 9 && $o7c['turns'] === 9,
    $o7c['beats'] . ' beats, ' . $o7c['turns'] . ' turns');

// ---------------------------------------------------------------------------
// 5. Silence: counted, licensed once per stretch, never forced
// ---------------------------------------------------------------------------

// Dale and Ruth volley by name, so Janelle never draws the floor: after three
// silent beats the NEXT speaker's coaching is licensed to notice her — once —
// and she is never made to speak. Being noticed IS her line.
$db8 = fresh_db('silence');
$c8  = [];
$o8  = xeric_room($T, $db8, $TRIO, $SUN, stub_room($c8, [
    'Janelle Kerr' => ['Janelle sat through the whole thing without saying a word.'],
], null, fn(int $n) => $n % 2 === 1 ? 'Ruth, about the kitchen — say it plain.' : 'Dale, I am saying it plain.'),
    ['say_first' => 'pastor_dale', 'beats' => 6]);
$chat8 = array_values(array_filter($c8, fn($c) => $c['tag'] === 'chat'));
$notices = [];
foreach ($chat8 as $i => $c) {
    $bytes = json_encode($c['msgs'], JSON_UNESCAPED_UNICODE);
    if (str_contains($bytes, 'has not said a word in a while')) $notices[] = $i;
}
ok('silence: janelle never once drew the floor', !in_array('janelle', array_map('whose', $chat8), true));
ok('silence: the notice lands in the fourth beat\'s prompt — three silent beats, then licensed',
    $notices === [3], json_encode($notices));
ok('silence: the notice names her, in the next speaker\'s mouth, as a thing they may let be',
    str_contains(json_encode($chat8[3]['msgs'], JSON_UNESCAPED_UNICODE),
        'Janelle Kerr has not said a word in a while. You may notice that, or let it be.'));
$tr8 = trail($db8, $o8['event_id']);
ok('silence: the trail files the streak and the noticing',
    ($tr8['beats'][3]['quiet'] ?? []) === ['janelle'] && ($tr8['beats'][3]['noticed'] ?? '') === 'janelle');
ok('silence: never spoken, and the why says so',
    $o8['never_spoke'] === ['janelle']
    && str_contains((string)$tr8['why'], 'Janelle Kerr never said a word'), (string)$tr8['why']);
ok('silence: the quiet one still keeps a diary of her own',
    $o8['memories']['janelle'] === ['Janelle sat through the whole thing without saying a word.']);

// ---------------------------------------------------------------------------
// 6. Departure: the schedule does not pause for a scene
// ---------------------------------------------------------------------------

// 11:52 start; ruth's Sunday block ends at 12:00; the 12:04 re-read (beat 6)
// finds her gone. She leaves as a stage direction, stops being called, and
// her diary reads only the six lines she was in the room for.
$db9 = fresh_db('departure');
$c9  = [];
$o9  = xeric_room($T, $db9, $TRIO, $SUNPM, stub_room($c9, [
    'Ruth Amberg' => ['Ruth left the two of them to it and went to see about the urn.'],
], null, fn(int $n) => [
    1 => 'Ruth, about the kitchen.', 2 => 'Dale, ask Janelle.', 3 => 'Ruth, I am asking you.',
    4 => 'Dale, fine.', 5 => 'Ruth, thank you.', 6 => 'Dale, we are done here.',
][$n] ?? 'Nothing much, honestly.'), ['say_first' => 'pastor_dale', 'beats' => 9, 'seed' => 11]);

$chat9 = array_values(array_filter($c9, fn($c) => $c['tag'] === 'chat'));
ok('departure: nine beats still means nine spoken lines — the exit is not a beat',
    $o9['turns'] === 9 && count($chat9) === 9);
ok('departure: the trail and the return both put ruth\'s exit at the sixth beat',
    ($o9['departures']['ruth']['beat'] ?? -1) === 6);
ok('departure: where she went is the trail\'s to say, and it says nowhere (no home in milldale)',
    array_key_exists('went', $o9['departures']['ruth']) && $o9['departures']['ruth']['went'] === null);
$exit9 = array_values(array_filter($o9['lines'], fn($l) => $l['kind'] === 'exit'));
ok('departure: the transcript carries one stage direction, observables only — no destination',
    count($exit9) === 1 && $exit9[0]['handle'] === 'ruth'
    && $exit9[0]['text'] === 'Ruth Amberg gets up and goes.', json_encode($exit9));
ok('departure: her assembly is never called again',
    array_slice(array_map('whose', $chat9), 6) === array_values(array_filter(
        array_slice(array_map('whose', $chat9), 6), fn($w) => $w !== 'ruth')));
$post = json_encode($chat9[8]['msgs'], JSON_UNESCAPED_UNICODE);
ok('departure: later calls hear it as a thing the world did, in the narrator\'s marker',
    str_contains($post, '(what happened) Ruth Amberg gets up and goes.'));

// Ruth's parting line was "Dale, we are done here." — she is gone by the next
// beat, and the name in it still calls its answer: a parting question hangs
// in the air of a real room too (learned against the stand-in, day one).
$tr9 = trail($db9, $o9['event_id']);
ok('departure: a name in the parting words keeps its claim on the next turn',
    whose($chat9[6]) === 'pastor_dale'
    && $tr9['beats'][6]['how'] === 'addressed'
    && $tr9['beats'][6]['addressed'] === ['pastor_dale'], json_encode($tr9['beats'][6]));

$ruthDiary = '';
foreach ($c9 as $c) {
    if ($c['tag'] === 'extract' && whose($c) === 'ruth') $ruthDiary = json_encode($c['msgs'], JSON_UNESCAPED_UNICODE);
}
ok('departure: her diary reads what she was in the room for, and not one line more',
    str_contains($ruthDiary, 'spoken line') === false                     // stub lines here are named, not numbered
    && str_contains($ruthDiary, 'Ruth, thank you.')                       // line 5, heard
    && str_contains($ruthDiary, 'we are done here')                       // line 6, heard
    && !str_contains($ruthDiary, 'Nothing much, honestly'),               // lines 7-9, not heard
    mb_substr($ruthDiary, 0, 200));
ok('departure: she is still a participant of record, and the commons says she left first',
    xeric_events_recent($db9, 1)[0]['participants'] === $TRIO
    && str_contains((string)xeric_events_recent($db9, 1)[0]['prose'], 'Ruth Amberg left before the others'));

// A room the schedule empties out ends early, closes clean, and says so:
// every Sunday block here dies at noon, so the 12:04 re-read clears the room.
$TE  = reweek($T, 'janelle', [['days' => [0], 'from' => '09:00', 'to' => '12:00', 'where' => 'first_lutheran', 'doing' => 'service']]);
$TE  = reweek($TE, 'pastor_dale', [['days' => [0], 'from' => '08:00', 'to' => '12:00', 'where' => 'first_lutheran', 'doing' => 'service']]);
$db10 = fresh_db('ends-early');
$c10  = [];
$o10  = xeric_room($TE, $db10, $TRIO, $SUNPM, stub_room($c10), ['say_first' => 'ruth', 'beats' => 9, 'seed' => 5]);
ok('departure: a room the schedule empties ends the scene early, and the close still lands',
    $o10['turns'] === 6 && count($o10['departures']) === 3
    && trail($db10, $o10['event_id'])['ended_early'] === true
    && xeric_events_count($db10) === 1, json_encode($o10['departures']));

// ---------------------------------------------------------------------------
// 7. The ceiling and the floor: the lowest one standing clamps everybody
// ---------------------------------------------------------------------------

// Five in the room, one of them twelve: every adult's system is written at
// the floor. The roster grows by in-memory template edits — Milldale has no
// natural five-strong hour, and that is what makes the clamp worth proving.
$T5 = reweek($T, 'dot',  array_merge($T['cast']['characters'][2]['week'],
        [['days' => [0], 'from' => '09:00', 'to' => '13:00', 'where' => 'first_lutheran', 'doing' => 'for once, church']]));
$T5 = reweek($T5, 'theo', array_merge($T['cast']['characters'][5]['week'],
        [['days' => [0], 'from' => '09:00', 'to' => '13:00', 'where' => 'first_lutheran', 'doing' => 'dragged along']]));
$FIVE = ['pastor_dale', 'ruth', 'janelle', 'dot', 'theo'];

$db11 = fresh_db('ceiling');
$c11  = [];
$o11  = xeric_room($T5, $db11, $FIVE, $SUN, stub_room($c11),
    ['say_first' => 'pastor_dale', 'beats' => 2, 'effective_rating' => 'mature', 'seed' => 2]);
$sys11 = (string)$c11[0]['msgs'][0]['content'];
ok('ceiling: a twelve-year-old in a room of five writes the ADULT\'s call at the floor',
    str_contains($sys11, 'TV-G') && !str_contains($sys11, 'TV-MA'));
$tr11 = trail($db11, $o11['event_id']);
ok('ceiling: the trail owns up to the clamp',
    !empty($tr11['minor_clamp']) && (string)$tr11['rating'] === 'sfw');

// The floor reads every spoken line at the roomful scope: sex in an hour with
// a child in it is refused whoever said the sentence.
$db12 = fresh_db('floor');
$c12  = [];
$msg12 = err(fn() => xeric_room($T5, $db12, $FIVE, $SUN,
    stub_room($c12, [], null, fn(int $n) => $n === 1 ? 'Slow morning. Coffee.' : 'All this talk made him horny.'),
    ['say_first' => 'pastor_dale', 'beats' => 4, 'seed' => 2]));
ok('floor: a sexual line in a room with a child refuses the whole room, by name',
    str_contains($msg12, 'child') && str_contains($msg12, 'refused'), $msg12);
ok('floor: and the refusal wrote nothing',
    xeric_events_count($db12) === 0 && xeric_memories_count($db12) === 0);

// ---------------------------------------------------------------------------
// 8. The wall, after the model — and after the door
// ---------------------------------------------------------------------------

$TP = $T;
$TP['cast']['special_roles'][0]['must_not_know'] =
    'what happens at the thursday pot game in the church basement';
$LEAKLINE = 'the thursday pot game happens in the church basement, after supper';

// Spoken while the protected listener stands there: refused, like a sweep's.
$db13 = fresh_db('leak');
$c13  = [];
$msg13 = err(fn() => xeric_room($TP, $db13, $TRIO, $SUN,
    stub_room($c13, [], null, fn(int $n) => $n === 1 ? $LEAKLINE : 'Morning. Good crowd.'),
    ['say_first' => 'pastor_dale', 'beats' => 4, 'seed' => 3]));
ok('wall: a line that puts the protected listener next to the secret refuses the room',
    str_contains($msg13, 'next to the thing they must not know')
    && str_contains($msg13, 'Janelle'), $msg13);
ok('wall: nothing was written', xeric_events_count($db13) === 0 && xeric_memories_count($db13) === 0);

// Spoken after she has LEFT: words in a room she is not in. Janelle's Sunday
// is trimmed to noon so the 12:04 re-read walks her out; ruth's is stretched
// so the room keeps two people; the same sentence then lands unrefused.
$TPD = reweek($TP, 'janelle', [['days' => [0], 'from' => '09:00', 'to' => '12:00', 'where' => 'first_lutheran', 'doing' => 'service']]);
$TPD = reweek($TPD, 'ruth',   [['days' => [0], 'from' => '08:00', 'to' => '13:00', 'where' => 'first_lutheran', 'doing' => 'the urn']]);
$db14 = fresh_db('leak-after-door');
$c14  = [];
$o14  = xeric_room($TPD, $db14, $TRIO, $SUNPM,
    stub_room($c14, [], null, fn(int $n) => $n === 7 ? $LEAKLINE : "spoken line $n, nothing much."),
    ['say_first' => 'pastor_dale', 'beats' => 9, 'seed' => 11]);
ok('wall: the same words after the protected listener walked out are just words',
    $o14['turns'] === 9 && ($o14['departures']['janelle']['beat'] ?? -1) === 6);
$janDiary14 = '';
foreach ($c14 as $c) {
    if ($c['tag'] === 'extract' && whose($c) === 'janelle') $janDiary14 = json_encode($c['msgs'], JSON_UNESCAPED_UNICODE);
}
ok('wall: and her diary slice never met the sentence either',
    $janDiary14 !== '' && !str_contains($janDiary14, 'thursday pot game'));

// And a clean room under the same protection sails through.
$db15 = fresh_db('leak-clean');
$c15  = [];
$o15  = xeric_room($TP, $db15, $TRIO, $SUN, stub_room($c15), ['say_first' => 'pastor_dale', 'beats' => 3, 'seed' => 3]);
ok('wall: the same three talking about anything else lands normally',
    xeric_events_count($db15) === 1 && $o15['turns'] === 3);

// THE EXTRACTOR CAN SYNTHESISE — room.php's guard, same law as the duet's,
// finally driven: benign spoken lines, and an extractor that hands the
// protected head her own secret assembled whole. The guard drops that line
// and keeps her harmless one; deleting it used to leave every suite green.
$db16 = fresh_db('leak-synth');
$c16  = [];
$o16  = xeric_room($TP, $db16, $TRIO, $SUN,
    stub_room($c16, [
        'Janelle Kerr' => ['Janelle learned the thursday game is set up in the church basement after supper.',
                           'Janelle thought the urn ran slow again.'],
    ]),
    ['say_first' => 'pastor_dale', 'beats' => 3, 'seed' => 3]);
$janKept16 = array_map(fn($m) => (string)$m['text'], xeric_memories_for($db16, 'janelle', 10));
ok('wall: the room lands, and the synthesised secret never reaches her diary',
    xeric_events_count($db16) === 1
    && !array_filter($janKept16, fn($m) => str_contains($m, 'church basement')), json_encode($janKept16));
ok('wall: while her harmless memory of the same hour is kept',
    array_filter($janKept16, fn($m) => str_contains($m, 'urn')) !== [], json_encode($janKept16));

// ---------------------------------------------------------------------------
// 9. Read-only until the close, probed from inside the model's own calls
// ---------------------------------------------------------------------------

$db16   = fresh_db('readonly');
$base16 = [xeric_events_count($db16), xeric_memories_count($db16),
           (int)$db16->query('SELECT COUNT(*) c FROM world_state')->fetchAll()[0]['c']];
$c16    = [];
$probe  = fn() => [xeric_events_count($db16), xeric_memories_count($db16),
                   (int)$db16->query('SELECT COUNT(*) c FROM world_state')->fetchAll()[0]['c']];
$o16 = xeric_room($T, $db16, $TRIO, $SUN,
    stub_room($c16, ['Ruth Amberg' => ['Ruth left with the urn on her mind.']], $probe),
    ['say_first' => 'ruth', 'beats' => 3, 'seed' => 9]);
$moved = '';
foreach ($c16 as $i => $c) {
    if ($c['db'] !== $base16) { $moved = 'call ' . $i . ': ' . json_encode($c['db']); break; }
}
ok('read-only: every model call, speech and diary alike, saw the untouched world', $moved === '', $moved);
ok('read-only: and the close moved the counts in one step',
    xeric_events_count($db16) === $base16[0] + 1
    && xeric_memories_count($db16) === $base16[1] + 1
    && xeric_world_state_get($db16, 'why:event:' . $o16['event_id']) !== null);

// ---------------------------------------------------------------------------
// 10. The close survives its own bookkeeping
// ---------------------------------------------------------------------------

// One diary call dying costs that diary and a note, never the room.
$db17 = fresh_db('diary-fail');
$c17  = [];
$o17  = xeric_room($T, $db17, $TRIO, $SUN, stub_room($c17, [
    'Pastor Dale Ostrander' => ['Dale heard the gutters need doing.'],
    'Ruth Amberg'           => fn() => throw new RuntimeException('llm: cannot reach 127.0.0.1'),
    'Janelle Kerr'          => ['Janelle noticed nobody asked about the drive.'],
]), ['say_first' => 'pastor_dale', 'beats' => 3, 'seed' => 9]);
ok('close: a dead diary call keeps its note and loses nothing else',
    xeric_events_count($db17) === 1
    && $o17['memories']['pastor_dale'] !== [] && $o17['memories']['janelle'] !== []
    && $o17['memories']['ruth'] === []
    && str_contains(implode('; ', $o17['notes']), 'Ruth Amberg'));

// A later diary may not restate an earlier one: one hour, N heads.
$db18 = fresh_db('diary-echo');
$c18  = [];
$same = 'Everyone agreed the kitchen budget would not survive the winter.';
$o18  = xeric_room($T, $db18, $TRIO, $SUN, stub_room($c18, [
    'Pastor Dale Ostrander' => [$same],
    'Ruth Amberg'           => [$same, 'Ruth caught Dale checking his cap twice while Janelle watched.'],
    'Janelle Kerr'          => [$same],
]), ['say_first' => 'pastor_dale', 'beats' => 3, 'seed' => 9]);
ok('close: later diaries drop an earlier diary\'s sentence and keep their own',
    $o18['memories']['pastor_dale'] === [$same]
    && $o18['memories']['ruth'] === ['Ruth caught Dale checking his cap twice while Janelle watched.']
    && $o18['memories']['janelle'] === [],
    json_encode($o18['memories']));

// A model that answers with nothing usable fails the room whole, chat's way.
$db19 = fresh_db('nothing');
$c19  = [];
$msg19 = err(fn() => xeric_room($T, $db19, $TRIO, $SUN,
    stub_room($c19, [], null, fn() => '   '), ['say_first' => 'ruth', 'beats' => 3]));
ok('room: an empty line fails loudly and writes nothing',
    str_contains($msg19, 'nothing usable') && xeric_events_count($db19) === 0);

// ---------------------------------------------------------------------------
// 11. The material: what seeds a head stays in that head
// ---------------------------------------------------------------------------

// The needle memories are worded to share no phrase with the bible's commons
// text (the Chillicothe lesson above, learned twice: "ride to the clinic" is
// in the casserole rules verbatim, so it cannot be anybody's private needle).
$db20 = fresh_db('material');
xeric_memory_add($db20, 'ruth', 'Ruth still had not thanked Janelle for hemming the choir robes.', 'auto', [], $SUN['epoch'] - 86400);
xeric_memory_add($db20, 'ruth', 'Ruth counted the folding chairs twice and came up one short.', 'auto', [], $SUN['epoch'] - 86400);
xeric_memory_add($db20, 'pastor_dale', 'Dale heard Ruth had been asking about the thermostat.', 'auto', [], $SUN['epoch'] - 86400);
$c20 = [];
xeric_room($T, $db20, $TRIO, $SUN, stub_room($c20), ['say_first' => 'ruth', 'beats' => 3, 'seed' => 9]);
$chat20 = array_values(array_filter($c20, fn($c) => $c['tag'] === 'chat'));
$byWho  = [];
foreach ($chat20 as $c) $byWho[whose($c)] = json_encode($c['msgs'], JSON_UNESCAPED_UNICODE);
$ruthTail  = (string)$chat20[0]['msgs'][count($chat20[0]['msgs']) - 1]['content'];
$ruthScene = substr($ruthTail, (int)strpos($ruthTail, 'THE SCENE'));
ok('material: a memory that mentions anyone in the room seeds its owner\'s side of the scene',
    str_contains($ruthScene, 'hemming the choir robes'), $ruthScene);
ok('material: one that mentions nobody in the room seeds nothing',
    !str_contains($ruthScene, 'folding chairs'), $ruthScene);
ok('material: and nobody\'s private material crosses the table',
    (!isset($byWho['pastor_dale']) || !str_contains($byWho['pastor_dale'], 'choir robes'))
    && (!isset($byWho['janelle'])
        || (!str_contains($byWho['janelle'], 'choir robes')
            && !str_contains($byWho['janelle'], 'thermostat'))));

// The transcript crosses as labeled speech, and only as speech: a deliberate
// deterministic volley, then the shape of the third speaker's first call.
$db21 = fresh_db('mapping');
$c21  = [];
xeric_room($T, $db21, $TRIO, $SUN, stub_room($c21, [], null, fn(int $n) => [
    1 => 'Ruth, the first thing.', 2 => 'Janelle, the second thing.', 3 => 'Dale, the third thing.',
][$n] ?? 'More.'), ['say_first' => 'pastor_dale', 'beats' => 3]);
$chat21 = array_values(array_filter($c21, fn($c) => $c['tag'] === 'chat'));
$m21 = $chat21[2]['msgs'];   // janelle's call: system, then both prior lines as one labeled user turn
ok('mapping: another\'s line arrives labeled with the speaker\'s name, merged in order',
    count($m21) === 2 && $m21[1]['role'] === 'user'
    && str_contains((string)$m21[1]['content'],
        "Pastor Dale Ostrander: Ruth, the first thing.\nRuth Amberg: Janelle, the second thing.")
    && str_contains((string)$m21[1]['content'], 'RIGHT NOW'), json_encode($m21[1]));
$m21d = $chat21[0]['msgs'];
ok('mapping: the reseating block names the whole company and sends Walt away',
    str_contains((string)$m21d[0]['content'], 'THIS ONE CONVERSATION IS DIFFERENT')
    && str_contains((string)$m21d[0]['content'], 'Ruth Amberg and Janelle Kerr')
    && str_contains((string)$m21d[0]['content'], 'Walt is not here'));
ok('mapping: the scene seats them in the room the schedule agreed on',
    str_contains((string)$m21d[count($m21d) - 1]['content'], 'First Lutheran'));

// ---------------------------------------------------------------------------
// THE NARRATOR'S SKY REACHES A ROOM.
//
// xeric_room_messages() passed `$db` to xeric_prompt_now_block() and had no
// such parameter, so PHP handed it null — the duet's bug, copied. The derived
// sky was always there; the narrator's hand (xeric_weather_told, set through
// xeric_weather_set) never was. Checked on every chat call of a real room run,
// not on the helper alone, so the loop's wiring is what is under test.
// ---------------------------------------------------------------------------

echo "\n# the sky a room stands under\n";

$dbWx = fresh_db('weather'); $cWx = [];
xeric_weather_set($dbWx, $SUN, 'Rain since before light, and it has not let up.');
xeric_room($T, $dbWx, $TRIO, $SUN, stub_room($cWx), ['seed' => 7, 'beats' => 4]);
$wxChats = array_values(array_filter($cWx, fn($c) => $c['tag'] === 'chat'));
ok('weather: every voice in the room stands in the rain the narrator called for',
    $wxChats !== [] && array_filter($wxChats,
        fn($c) => !str_contains(json_encode($c['msgs']), 'Rain since before light')) === [],
    (string)count($wxChats));
$dbWx0 = fresh_db('weather-none'); $cWx0 = [];
xeric_room($T, $dbWx0, $TRIO, $SUN, stub_room($cWx0), ['seed' => 7, 'beats' => 4]);
ok('weather: and a room nobody set the sky for reads the derived one, not the rain',
    !str_contains(json_encode($cWx0), 'Rain since before light')
    && xeric_weather_line($T, $SUN, null) !== ''
    && str_contains(json_encode($cWx0), trim((string)json_encode(xeric_weather_line($T, $SUN, null)), '"')));

// ===========================================================================
// THE ROOM WITH YOU IN IT — the live session (FUTURE.md, Appendix A)
//
// The same constitution with the player standing at the table, driven the
// way room-worker.php drives it: hear a line, draw, step (one call), carry,
// pace, land a row, and at the end one close through the same land as above.
// ===========================================================================

echo "\n# the room with you in it\n";

/** Seat Walt and the cast at $place, the way room.php opens a session. */
function live_open(array $T, PDO $db, array $now, string $place): array
{
    xeric_player_move($db, $place, (int)$now['epoch']);
    $live = xeric_room_open($T, $db, $now, $place, ['id' => 1, 'name' => 'Walt']);
    $live['conv'] = xeric_conversation_create($db, '', 'room', $place);
    xeric_world_state_set($db, XERIC_ROOM_LIVE, json_encode($live));
    return $live;
}

/** Walt says something: heard, landed, booked — under the edit. */
function live_say(array $T, PDO $db, array $now, string $text): int
{
    return xeric_room_live_edit($db, function (?array &$live) use ($T, $db, $now, $text): int {
        $lines = xeric_room_lines($live, xeric_room_rows($db, (int)$live['conv']));
        $heard = xeric_room_hear($T, $live, $lines, $text);
        $mid   = xeric_message_append($db, (int)$live['conv'], 'user', null, $heard, (int)$now['epoch']);
        xeric_room_said($live, $mid, $heard, $now);
        return $mid;
    });
}

/** One cast beat, start to finish. */
function live_beat(array $T, PDO $db, array $now, array $ep, array $copts = []): array
{
    $live  = xeric_room_live_get($db);
    $lines = xeric_room_lines($live, xeric_room_rows($db, (int)$live['conv']));
    $draw  = xeric_room_next($T, $db, $live, $lines);
    $beat  = xeric_room_step($T, $db, $live, $lines, $now, $ep, $draw);
    $carry = xeric_room_live_edit($db, function (?array &$live) use ($db, $now, $beat, $draw, $copts): array {
        $mid   = xeric_message_append($db, (int)$live['conv'], 'character', $beat['handle'], $beat['text'], (int)$now['epoch']);
        $carry = xeric_room_carry($live, $beat, $copts);
        xeric_room_spoke($live, $beat, $mid, $draw, $carry, $now, microtime(true));
        return $carry;
    });
    return $beat + ['draw' => $draw, 'carry' => $carry];
}

/** Beats until the room hands the floor back (or $max). */
function live_turn(array $T, PDO $db, array $now, array $ep, int $max = 6, array $copts = []): array
{
    $out = [];
    for ($i = 0; $i < $max; $i++) {
        if ((int)xeric_room_live_get($db)['budget'] <= 0) break;
        $out[] = live_beat($T, $db, $now, $ep, $copts);
    }
    return $out;
}

function counts(PDO $db): string
{
    return xeric_events_count($db) . '/' . xeric_memories_count($db) . '/' . xeric_conversations_count($db)
         . '/' . (int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()
         . '/' . (xeric_world_state_get($db, XERIC_ROOM_LIVE) === null ? 'none' : 'live');
}

// -- admission: two to five and you, or the sentence that says why not ------
$dbL0 = fresh_db('live-refuse');
$before = counts($dbL0);
$one = err(fn() => xeric_room_open($T, $dbL0, $SAT, 'bluebird', ['id' => 1, 'name' => 'Walt']));
ok('live: one person is not a room — the refusal says to open their thread',
    str_contains($one, 'only Dot Vance is here') && str_contains($one, 'open their thread'), $one);
$none = err(fn() => xeric_room_open($T, $dbL0, $SUN, 'the_mill', ['id' => 1, 'name' => 'Walt']));
ok('live: nobody is not a room either', str_contains($none, 'nobody is at'), $none);
ok('live: and opening refusals write nothing', counts($dbL0) === $before, counts($dbL0));

// -- six is a crowd: the five who carry the most about you sit at your table --
$T6 = reweek($T5, 'harlan', [['days' => [0], 'from' => '09:00', 'to' => '13:00', 'where' => 'first_lutheran', 'doing' => 'fixing a pew']]);
$db6 = fresh_db('live-six');
xeric_memory_add($db6, 'harlan', 'Harlan still owes Walt a straight answer about the mower.', 'auto');
xeric_memory_add($db6, 'harlan', 'Harlan saw Walt at the feed store on Tuesday.', 'auto');
$six = xeric_room_open($T6, $db6, $SUN, 'first_lutheran', ['id' => 1, 'name' => 'Walt']);
ok('crowd: six present seats five, and says who is at the far table',
    count($six['present']) === 5 && count($six['far']) === 1, json_encode([$six['present'], $six['far']]));
ok('crowd: the one carrying the most about you is at your table', in_array('harlan', $six['present'], true));
ok('crowd: the far table is still in the room — the floor reads it', $six['far'] !== []
    && str_contains(err(fn() => xeric_room_hear($T6, ['seated' => array_values(array_diff($six['present'], ['theo']))]
        + ['far' => ['theo']] + $six, [], 'All this talk makes me horny.')), 'Theo Vance'));

// -- one call a beat, the player's line no call at all ------------------------
$dbL = fresh_db('live');
$cL  = [];
$epL = stub_room($cL, [], null, fn(int $n) => [1 => 'Ruth, you first.', 2 => 'Fine. The urn is on.', 3 => 'Well then.'][$n] ?? 'Mm.');
live_open($T, $dbL, $SUN, 'first_lutheran');
live_say($T, $dbL, $SUN, 'Morning, all of you. What did I miss?');
ok('live: the player\'s line is a row at once, and no model call', count($cL) === 0
    && count(xeric_room_rows($dbL, (int)xeric_room_live_get($dbL)['conv'])) === 1);
$turn = live_turn($T, $dbL, $SUN, $epL, 6, ['seed' => 'x']);
$chatL = array_values(array_filter($cL, fn($c) => $c['tag'] === 'chat'));
ok('live: each answering beat is exactly one call from one head',
    count($turn) >= 1 && count($chatL) === count($turn), count($turn) . ' beats, ' . count($chatL) . ' calls');
ok('live: a line buys at most three answers', count($turn) <= XERIC_ROOM_ANSWERS);
ok('live: nobody answers themselves twice running',
    count($turn) < 2 || $turn[0]['handle'] !== $turn[1]['handle']);
$rowsL = xeric_room_rows($dbL, (int)xeric_room_live_get($dbL)['conv']);
ok('live: the rows are the thread\'s own roles — the player as user, the cast as themselves',
    (string)$rowsL[0]['role'] === 'user' && $rowsL[0]['handle'] === null
    && (string)$rowsL[1]['role'] === 'character' && (string)$rowsL[1]['handle'] === $turn[0]['handle']);
ok('live: a cast row is one speak.php can voice', xeric_play_spoken_like($dbL, (int)$rowsL[1]['id']));
ok('live: the reseating block keeps Walt AT the table, not away from it',
    str_contains((string)$chatL[0]['msgs'][0]['content'], 'Walt is standing right here')
    && !str_contains((string)$chatL[0]['msgs'][0]['content'], 'Walt is not here'));
ok('live: the player\'s line arrives labeled, and the coaching asks for an answer',
    str_contains(json_encode($chatL[0]['msgs']), 'Walt: Morning, all of you')
    && str_contains(json_encode($chatL[0]['msgs']), 'Walt just said that'));
ok('live: the now-block puts Walt in the room', str_contains(json_encode($chatL[0]['msgs']), 'is here, in the room with you'));

// -- naming makes them answer; several named are each owed a turn -------------
$dbN = fresh_db('live-named'); $cN = [];
$epN = stub_room($cN, [], null, fn(int $n) => 'Something plain, number ' . $n . '.');
live_open($T, $dbN, $SUN, 'first_lutheran');
live_say($T, $dbN, $SUN, 'Janelle, is the drive still bad?');
$tN = live_turn($T, $dbN, $SUN, $epN, 1);
ok('live: a name in the player\'s line answers first', $tN[0]['handle'] === 'janelle'
    && $tN[0]['draw']['how'] === 'addressed', $tN[0]['handle'] . '/' . $tN[0]['draw']['how']);
$dbN2 = fresh_db('live-named2'); $cN2 = [];
live_open($T, $dbN2, $SUN, 'first_lutheran');
live_say($T, $dbN2, $SUN, 'Ruth and Janelle, settle this for me.');
$tN2 = live_turn($T, $dbN2, $SUN, stub_room($cN2, [], null, fn(int $n) => 'Plain answer ' . $n . '.'), 6, ['seed' => 'y']);
$whoN2 = array_column($tN2, 'handle');
ok('live: two named are both owed — two people answer in turn',
    count($whoN2) >= 2 && in_array('ruth', array_slice($whoN2, 0, 2), true) && in_array('janelle', array_slice($whoN2, 0, 2), true)
    && $tN2[0]['carry']['why'] === 'owed', implode(',', $whoN2));
$trN2 = xeric_room_live_get($dbN2)['trail'];
ok('live: the trail keeps the weights with their reach × heat breakdown, and the line by id',
    isset($trN2[1]['weights'], $trN2[1]['reach'], $trN2[1]['heat'], $trN2[1]['mid']), json_encode($trN2[1]));

// -- "Walt:" is cut ---------------------------------------------------------
$dbC = fresh_db('live-cut'); $cC = [];
live_open($T, $dbC, $SUN, 'first_lutheran');
live_say($T, $dbC, $SUN, 'Anybody want coffee?');
$tC = live_beat($T, $dbC, $SUN, stub_room($cC, [], null, fn(int $n) => "Coffee would be good.\nWalt: sure, I will get it."));
ok('live: a model writing the player\'s line is cut at the theft', $tC['text'] === 'Coffee would be good.', $tC['text']);

// -- the pairwise needles, with the player present ---------------------------
$dbW = fresh_db('live-walls'); $cW = [];
$epW = stub_room($cW, [], null, fn(int $n) => 'A short line, ' . $n . '.');
live_open($T, $dbW, $SUN, 'first_lutheran');
foreach (['Pastor, Ruth, Janelle, morning.', 'Ruth, anything new?', 'Janelle, and you?', 'Dale?'] as $line) {
    live_say($T, $dbW, $SUN, $line);
    live_turn($T, $dbW, $SUN, $epW, 4, ['seed' => 'w']);
}
$leakW = ''; $sysBy = [];
foreach ($cW as $i => $c) {
    $bytes = json_encode($c['msgs'], JSON_UNESCAPED_UNICODE);
    $who   = whose($c);
    if ($who === 'janelle' && str_contains($bytes, DALE_SECRET))        $leakW = "call $i: dale's secret reached janelle";
    if ($who === 'janelle' && str_contains($bytes, RUTH_SECRET))        $leakW = "call $i: ruth's secret reached janelle";
    if ($who === 'pastor_dale' && str_contains($bytes, RUTH_SECRET))    $leakW = "call $i: ruth's secret reached dale";
    if ($who === 'pastor_dale' && str_contains($bytes, JANELLE_SECRET)) $leakW = "call $i: janelle's secret reached dale";
    if ($who === 'ruth' && str_contains($bytes, JANELLE_SECRET))        $leakW = "call $i: janelle's secret reached ruth";
    $sysBy[$who][(string)$c['msgs'][0]['content']] = true;
}
ok('live WALLS: the pairwise matrix holds with the player at the table', $leakW === '', $leakW);
ok('live: every head spoke more than once, so the next check means something',
    count($sysBy) === 3 && count(array_filter(array_count_values(array_map('whose', $cW)), fn($n) => $n >= 2)) === 3,
    json_encode(array_count_values(array_map('whose', $cW))));
ok('live PREFIX CACHE: each speaker\'s system prompt is byte-identical across the session',
    array_filter($sysBy, fn($set) => count($set) !== 1) === [], json_encode(array_map('count', $sysBy)));

// -- the carry: caps, the floor coming back, the queue, the seeded roll -------
$base = ['conv' => 7, 'mode' => 'answer', 'budget' => 3, 'beats' => 1, 'answered' => 0, 'owed' => [],
         'present' => ['ruth', 'pastor_dale', 'janelle'],
         'names' => ['ruth' => 'Ruth Amberg', 'pastor_dale' => 'Pastor Dale Ostrander', 'janelle' => 'Janelle Kerr', '__you' => 'Walt']];
$plain = ['handle' => 'ruth', 'text' => 'The urn is on.'];
ok('carry: the line\'s budget is the ceiling', xeric_room_carry(['budget' => 1] + $base, $plain)['why'] === 'budget');
ok('carry: a person waiting on the model outranks a room talking to itself',
    xeric_room_carry($base, $plain, ['queue_waiting' => true])['why'] === 'queue');
ok('carry: the hourly limit stops it', xeric_room_carry($base, $plain, ['limit_ok' => false])['why'] === 'limit');
ok('carry: a player gone is a room that stops', xeric_room_carry($base, $plain, ['player_here' => false])['why'] === 'you left');
ok('carry: somebody the player named and has not answered is owed the next turn',
    xeric_room_carry(['owed' => ['janelle']] + $base, $plain)['go'] === true);
ok('carry: the floor returns to the player when the line names them',
    xeric_room_carry($base, ['handle' => 'ruth', 'text' => 'What do you think, Walt?'])['floor'] === 'named you');
ok('carry: and when it asks the room a question naming nobody',
    xeric_room_carry($base, ['handle' => 'ruth', 'text' => 'Who left the urn on?'])['floor'] === 'asked the room');
$r1 = xeric_room_carry($base, $plain, ['seed' => 's']); $r2 = xeric_room_carry($base, $plain, ['seed' => 's']);
ok('carry: the roll is seeded — same session, same beat, same roll — and recorded with p',
    $r1 === $r2 && $r1['why'] === 'roll' && abs($r1['p'] - 0.55) < 1e-9 && isset($r1['roll']), json_encode($r1));
ok('carry: p halves with each answer already given',
    abs(xeric_room_carry(['answered' => 2] + $base, $plain, ['seed' => 's'])['p'] - 0.1375) < 1e-9);
$gotGo = $gotStop = false;
foreach (range(1, 40) as $i) { $r = xeric_room_carry($base, $plain, ['seed' => 'r' . $i]); $r['go'] ? $gotGo = true : $gotStop = true; }
ok('carry: and the roll really is a roll — some seeds carry on, some hand back', $gotGo && $gotStop);

$idle = ['said' => 2, 'run' => 0, 'carried' => 0, 'budget' => 0, 'lease' => null, 'ended' => null, 'beats' => 3] + $base;
ok('alone: a watched, idle room on a local machine may take a beat', xeric_room_carry_idle($T, $idle,
    ['queue_idle' => true, 'local' => true, 'player_here' => true])['ok']);
ok('alone: two in a row and no more', !xeric_room_carry_idle($T, ['run' => 2] + $idle, ['queue_idle' => true, 'local' => true])['ok']);
ok('alone: six a session and no more', !xeric_room_carry_idle($T, ['carried' => 6] + $idle, ['queue_idle' => true, 'local' => true])['ok']);
ok('alone: never while somebody is waiting on the model', !xeric_room_carry_idle($T, $idle, ['queue_idle' => false, 'local' => true])['ok']);
ok('alone: never on a hosted machine unless the template says so',
    !xeric_room_carry_idle($T, $idle, ['queue_idle' => true, 'local' => false])['ok']
    && xeric_room_carry_idle(array_replace_recursive($T, ['cast' => ['room' => ['carry_remote' => true]]]), $idle,
        ['queue_idle' => true, 'local' => false])['ok']);
ok('alone: and a template can turn it off', !xeric_room_carry_idle(
    array_replace_recursive($T, ['cast' => ['room' => ['carry' => false]]]), $idle, ['queue_idle' => true, 'local' => true])['ok']);
ok('alone: one beat, then the floor is the player\'s', xeric_room_carry(['mode' => 'idle'] + $base, $plain)['go'] === false);

// -- silence, the player's included, noticed once ---------------------------
$dbS = fresh_db('live-silence'); $cS = [];
$epS = stub_room($cS, [], null, fn(int $n) => 'A plain line ' . $n . '.');
live_open($T, $dbS, $SUN, 'first_lutheran');
live_say($T, $dbS, $SUN, 'Go on, all of you.');
xeric_room_live_edit($dbS, function (?array &$live): void { $live['streak']['__you'] = 3; $live['budget'] = 2; });
$s1 = live_beat($T, $dbS, $SUN, $epS);
$s2 = live_beat($T, $dbS, $SUN, $epS);
ok('silence: the player\'s own silence is noticed, through a cast mouth',
    $s1['notice'] === '__you' && str_contains(json_encode($s1['messages']), 'Walt has not said a word in a while'));
ok('silence: and only once per stretch', !str_contains(json_encode($s2['messages']), 'Walt has not said a word'));

// -- a player with no name: the word "you" is not them -----------------------
// The template may leave user.name empty. That person was seated as the
// literal 'you', and the mention test then found them in almost every line:
// the floor came back after one beat, every memory was heat, and the prompts
// read "you are not texting you … Answer you".
$TY = $T; $TY['user']['name'] = '';
$dbY = fresh_db('live-nameless'); $cY = [];
$epY = stub_room($cY, [], null, fn(int $n) => [1 => 'I told you the urn was on, Ruth.', 2 => 'You never listen to me, Dale.'][$n] ?? 'Mm, you know.');
xeric_player_move($dbY, 'first_lutheran', (int)$SUN['epoch']);
$liveY = xeric_room_open($TY, $dbY, $SUN, 'first_lutheran', ['id' => 1, 'name' => '']);
$liveY['conv'] = xeric_conversation_create($dbY, '', 'room', 'first_lutheran');
xeric_world_state_set($dbY, XERIC_ROOM_LIVE, json_encode($liveY));
ok('nameless: seated as the visitor, never the word you', $liveY['names']['__you'] === 'the visitor'
    && $liveY['player']['named'] === false && xeric_room_you_mention($liveY) === XERIC_ROOM_NAMELESS, json_encode($liveY['player']));
ok('nameless: the mention test refuses a pronoun or the bare word as a name',
    !xeric_age_mentions('What do you think?', 'you', 'you') && !xeric_age_mentions('I told them.', 'them', 'them')
    && !xeric_age_mentions('I saw you there.', XERIC_ROOM_NAMELESS, XERIC_ROOM_NAMELESS)
    && xeric_age_mentions('What do you think, Walt?', 'Walt', 'Walt'));
ok('nameless: a memory with "you" in it is no heat about them',
    xeric_room_heat_you([['text' => 'Ruth told you about the urn.']], [], xeric_room_you_mention($liveY)) === 0);
live_say($TY, $dbY, $SUN, 'Morning, all of you.');
xeric_room_live_edit($dbY, function (?array &$live): void { $live['budget'] = 3; });
$y1 = live_beat($TY, $dbY, $SUN, $epY, ['seed' => 'n']);
$y2 = live_beat($TY, $dbY, $SUN, $epY, ['seed' => 'n']);
ok('nameless: two beats, and "you" in a cast line never hands the floor back',
    ($y1['carry']['floor'] ?? '') !== 'named you' && ($y2['carry']['floor'] ?? '') !== 'named you'
    && count(array_filter($cY, fn($c) => $c['tag'] === 'chat')) === 2, json_encode([$y1['carry'], $y2['carry']]));
$bytesY = json_encode($cY, JSON_UNESCAPED_UNICODE);
ok('nameless: no prompt says "texting you" or "Answer you"',
    !str_contains($bytesY, 'texting you') && !str_contains($bytesY, 'Answer you') && !str_contains($bytesY, 'you: Morning')
    && str_contains($bytesY, 'The visitor just said that') && str_contains($bytesY, 'the visitor: Morning'), mb_substr($bytesY, 0, 200));

// -- presence: exits, arrivals, the minor seal -------------------------------
$TA = reweek($T, 'dot', [['days' => [0], 'from' => '11:58', 'to' => '13:00', 'where' => 'first_lutheran', 'doing' => 'late from the diner']]);
$dbP = fresh_db('live-presence');
live_open($TA, $dbP, $SUNPM, 'first_lutheran');
live_say($TA, $dbP, $SUNPM, 'Morning.');
$LATER = xeric_world_now($TA, ep('2026-08-02 12:04'));
$pr = xeric_room_presence($TA, $dbP, xeric_room_live_get($dbP), $LATER);
ok('presence: the schedule takes ruth out at noon, and brings dot in', array_keys($pr['left']) === ['ruth']
    && $pr['arrived'] === ['dot'] && $pr['minors'] === [], json_encode($pr));
$stage = xeric_travel_comings_line($TA, $LATER, 'first_lutheran', $pr['arrived'], array_keys($pr['left']), $dbP);
ok('presence: one stage direction, observables only, no pronouns',
    str_starts_with($stage, 'Dot Vance comes in') && str_contains($stage, 'Ruth Amberg gets up and goes.')
    && preg_match('/\b(he|she|him|her|his|hers)\b/i', $stage) === 0, $stage);
xeric_room_live_edit($dbP, function (?array &$live) use ($TA, $dbP, $LATER, $pr, $stage): void {
    $mid = xeric_message_append($dbP, (int)$live['conv'], 'narrator', null, $stage, (int)$LATER['epoch']);
    foreach ($pr['left'] as $h => $went) xeric_room_unseat($live, $h, $mid, $went, (int)$LATER['epoch']);
    foreach ($pr['arrived'] as $h) xeric_room_seat($TA, $live, $h, $mid, $LATER);
});
$liveP = xeric_room_live_get($dbP);
$linesP = xeric_room_lines($liveP, xeric_room_rows($dbP, (int)$liveP['conv']));
ok('presence: an adult arriving is seated, and hears only from the door',
    in_array('dot', $liveP['present'], true) && count(xeric_room_heard($liveP, $linesP, 'dot')) === 1
    && xeric_room_heard($liveP, $linesP, 'dot')[0]['kind'] === 'stage');
ok('presence: the leaver hears up to the door and not after', count(xeric_room_heard($liveP, $linesP, 'ruth')) === 2
    && !in_array('ruth', $liveP['present'], true));
$cP = [];
$bP = live_beat($TA, $dbP, $LATER, stub_room($cP, [], null, fn(int $n) => 'Right on time, Dot.'));
ok('presence: the leaver is never called again', $bP['handle'] !== 'ruth' && whose($cP[0]) !== 'ruth');
ok('presence: and the newcomer\'s system names the company as it was when they sat',
    $liveP['seats']['dot']['others'] === ['pastor_dale', 'janelle']);
$TM = reweek($T, 'theo', [['days' => [0], 'from' => '11:58', 'to' => '13:00', 'where' => 'first_lutheran', 'doing' => 'dragged along']]);
$dbM = fresh_db('live-minor');
live_open($TM, $dbM, $SUNPM, 'first_lutheran');
$prM = xeric_room_presence($TM, $dbM, xeric_room_live_get($dbM), $LATER);
ok('presence: a child walking in is reported apart — the ceiling cannot be re-folded mid-session',
    $prM['minors'] === ['theo'] && !in_array('theo', $prM['arrived'], true), json_encode($prM));
$sealM = xeric_room_seal($dbM, 'minor', $LATER);
ok('presence: and the seal moves the session aside, freeing the table',
    $sealM !== null && xeric_room_live_get($dbM) === null && isset(xeric_room_seals($dbM)[$sealM])
    && xeric_room_seals($dbM)[$sealM]['ended'] === 'minor');

// -- the look block's beat: who came and went in the last stretch -------------
$dbV = fresh_db('comings');
xeric_player_move($dbV, 'first_lutheran', ep('2026-08-02 11:00'));
$cm = xeric_travel_comings($TA, $dbV, $LATER, 'first_lutheran');
ok('comings: ten world-minutes back, dot came in and ruth went', $cm['arrived'] === ['dot'] && $cm['left'] === ['ruth']
    && $cm['line'] === $stage, json_encode($cm));
xeric_player_move($dbV, 'first_lutheran', ep('2026-08-02 12:02'));
ok('comings: but a room you walked into after they moved had nobody coming or going — it had you',
    xeric_travel_comings($TA, $dbV, $LATER, 'first_lutheran')['line'] === '');
ok('comings: an hour with nobody moving says nothing at all',
    xeric_travel_comings($T, $dbV, xeric_world_now($T, ep('2026-08-02 10:30')), 'first_lutheran')['line'] === '');

// -- a beat with the player gone is refused, and calls nobody -----------------
$dbG = fresh_db('live-gone'); $cG = [];
live_open($T, $dbG, $SUN, 'first_lutheran');
live_say($T, $dbG, $SUN, 'Back in a minute.');
xeric_player_move($dbG, 'bluebird', (int)$SUN['epoch']);
$liveG = xeric_room_live_get($dbG);
$goneMsg = err(fn() => xeric_room_step($T, $dbG, $liveG, xeric_room_lines($liveG, xeric_room_rows($dbG, (int)$liveG['conv'])),
    $SUN, stub_room($cG), xeric_room_next($T, $dbG, $liveG, [])));
ok('live: a beat with the player gone is refused before any model is asked',
    str_contains($goneMsg, 'refused') && $cG === [], $goneMsg);
ok('live: and the world says the session is over', xeric_room_over($T, $dbG, $liveG, $SUN) === 'you left');
ok('live: twenty quiet world-minutes end it too', xeric_room_over($T, $dbL, xeric_room_live_get($dbL),
    xeric_world_now($T, (int)$SUN['epoch'] + XERIC_ROOM_QUIET_SECONDS)) === 'quiet');

// -- the player's line is heard: the floor and the wall ----------------------
$dbH = fresh_db('live-hear');
live_open($T5, $dbH, $SUN, 'first_lutheran');
$beforeH = counts($dbH);
$hearMsg = err(fn() => live_say($T5, $dbH, $SUN, 'All this talk makes me horny.'));
ok('hear: a sexual line with a child in the room is refused, by name', str_contains($hearMsg, 'Theo Vance')
    && str_contains($hearMsg, 'refused'), $hearMsg);
ok('hear: and the refusal wrote nothing', counts($dbH) === $beforeH, counts($dbH) . ' vs ' . $beforeH);

// -- pacing: a floor, never a delay on top of a slow line --------------------
$d0 = xeric_room_pace(0.0, '', 100.0, false, false, 'a');
$d1 = xeric_room_pace($d0, 'A short line here.', 100.5, false, false, 'b');
$d2 = xeric_room_pace($d1, str_repeat('word ', 80), $d1 + 0.1, false, false, 'c');
$d3 = xeric_room_pace($d2, 'Short.', $d2 + 30.0, false, false, 'd');
$d4 = xeric_room_pace($d3, 'Short.', $d3 - 5.0, true, false, 'e');
$d5 = xeric_room_pace($d3, 'Short.', $d3 - 5.0, false, true, 'e');
ok('pace: the first line is due when it is ready', $d0 === 100.0);
ok('pace: due times strictly increase', $d0 < $d1 && $d1 < $d2 && $d2 < $d3 && $d3 < $d4);
ok('pace: reading time is clamped — a speech waits at most seven seconds and change',
    $d2 - $d1 <= (7.0 + 1.2) * 1.15 + 0.01 && $d2 - $d1 >= 7.0 * 0.85, (string)($d2 - $d1));
ok('pace: a slow line is due the moment it is ready, never later', $d3 === round($d2 + 30.0, 3));
ok('pace: a name tightens the gap, carrying on alone widens it', $d4 < $d5);
ok('pace: and the jitter is deterministic', xeric_room_pace($d0, 'x y z', 101.0, false, false, 'q')
    === xeric_room_pace($d0, 'x y z', 101.0, false, false, 'q'));

// -- the close: sources, prose, trail, expectations --------------------------
$dbX = fresh_db('live-close'); $cX = [];
$epX = stub_room($cX, [
    'Pastor Dale Ostrander' => ['Dale heard Walt ask after the gutters.'],
    'Ruth Amberg'           => ['Ruth noticed Walt came in late again.'],
    'Janelle Kerr'          => ['Janelle told Walt the drive was shorter.'],
], null, fn(int $n) => 'Plain line ' . $n . '.');
$liveX = live_open($T, $dbX, $SUN, 'first_lutheran');
live_say($T, $dbX, $SUN, 'Ruth, Janelle — good morning.');
live_turn($T, $dbX, $SUN, $epX, 4, ['seed' => 'c']);
$convX = xeric_room_seal($dbX, 'stepped back', $SUN);
ok('close: stepping back seals; nothing lands until the close', $convX !== null && xeric_events_count($dbX) === 0
    && xeric_memories_count($dbX) === 0);
$landX = xeric_room_land_live($T, $dbX, xeric_room_seals($dbX)[$convX], $epX);
$evX   = xeric_events_recent($dbX, 1)[0];
ok('close: one event, at the opening epoch, the player named in it',
    xeric_events_count($dbX) === 1 && (int)$evX['world_epoch'] === (int)$SUN['epoch']
    && str_contains((string)$evX['title'], 'walt') && str_contains((string)$evX['prose'], 'Walt'), json_encode($evX));
ok('close: the prose is observables and names, no pronouns, nothing said',
    preg_match('/\b(he|she|him|her|his|hers)\b/i', (string)$evX['prose']) === 0
    && !str_contains((string)$evX['prose'], 'Plain line'), (string)$evX['prose']);
$srcX = $dbX->query("SELECT DISTINCT source FROM memories WHERE source LIKE 'room%'")->fetchAll(PDO::FETCH_COLUMN);
ok('close: the diaries are filed as room.you — the player was counted in the company',
    $srcX === ['room.you'] && count($landX['memories']) === 3, json_encode($srcX));
$diaryX = array_values(array_filter($cX, fn($c) => $c['tag'] === 'extract'));
ok('close: and each diary prompt names Walt among the company',
    $diaryX !== [] && array_filter($diaryX, fn($c) => !str_contains((string)$c['msgs'][1]['content'], 'Walt')) === []);
$trX = trail($dbX, (int)$landX['event_id']);
ok('close: the trail is the inspector\'s, kind room, live, with message ids per beat and the end reason',
    ($trX['kind'] ?? '') === 'room' && !empty($trX['live']) && ($trX['ended'] ?? '') === 'stepped back'
    && array_filter((array)$trX['beats'], fn($b) => isset($b['speaker']) && !isset($b['mid'])) === []
    && (int)$trX['conversation'] === $convX, json_encode($trX));
ok('close: the seal is gone, the thread keeps its rows', xeric_room_seals($dbX) === []
    && count(xeric_room_rows($dbX, $convX)) >= 2);
ok('close: a second close of the same seal cannot land it twice',
    str_contains(err(fn() => xeric_room_land_live($T, $dbX, $liveX + ['conv' => $convX, 'ended' => 'x', 'spoke' => ['ruth' => 1]], null)),
        'already closed') && xeric_events_count($dbX) === 1);

// A promise the player made across the table is the v1 row: of nobody, which
// means the user, held by whoever it was said to.
$dbE = fresh_db('live-promise'); $cE = [];
$TE = $T;   // Milldale is hand-built, so expectations are armed (xeric_expect_armed)
live_open($TE, $dbE, $SUN, 'first_lutheran');
live_say($TE, $dbE, $SUN, 'Ruth, I will bring the folding chairs on Saturday.');
live_turn($TE, $dbE, $SUN, stub_room($cE, [], null, fn(int $n) => 'Good.'), 2);
$convE = xeric_room_seal($dbE, 'stepped back', $SUN);
$landE = xeric_room_land_live($TE, $dbE, xeric_room_seals($dbE)[$convE], null);
$exE = array_values(array_filter(xeric_expects_for($dbE, 'ruth'), fn($e) => str_contains((string)$e['quote'], 'folding chairs')));
ok('close: a promise the player made lands as the listener\'s expectation, of the user',
    xeric_expect_armed($TE) && count($exE) === 1 && !isset($exE[0]['of']), json_encode(xeric_expects_for($dbE, 'ruth')));
ok('close: with no model at the close the diaries are forfeit with a note, and the event still lands',
    xeric_events_count($dbE) === 1 && xeric_memories_count($dbE) === 0
    && array_filter($landE['notes'], fn($n) => str_contains($n, 'no model was attached')) !== []);

// -- the narrator's sky reaches the room, and comes in through the door ------
$dbR = fresh_db('live-weather'); $cR = [];
xeric_weather_set($dbR, $SUN, 'Rain since before light, and it has not let up.');
live_open($T, $dbR, $SUN, 'first_lutheran');
live_say($T, $dbR, $SUN, 'Wet one.');
live_beat($T, $dbR, $SUN, stub_room($cR, [], null, fn(int $n) => 'It is.'));
ok('weather: the narrator\'s rain reaches the live room\'s call', str_contains(json_encode($cR), 'Rain since before light'));
ok('weather: and somebody coming in shakes it off',
    xeric_travel_comings_line($T, $SUN, 'first_lutheran', ['dot'], [], $dbR) === 'Dot Vance comes in, shaking the rain off.');

// -- the old rule, pinned: xeric_room() still writes nothing until its close ---
$dbO = fresh_db('old-rule'); $cO = [];
$probeO = fn() => counts($dbO);
$outO = xeric_room($T, $dbO, $TRIO, $SUN, stub_room($cO, [], $probeO), ['beats' => 3, 'seed' => 3]);
ok('old rule: xeric_room() lands nothing until the close — no row, no thread, no live session, during any call',
    array_filter($cO, fn($c) => $c['db'] !== '0/0/0/0/none') === [], json_encode(array_column($cO, 'db')));
ok('old rule: and after it, one event and still no thread', xeric_events_count($dbO) === 1
    && xeric_conversations_count($dbO) === 0 && (int)$dbO->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 0);

// ---------------------------------------------------------------------------

foreach ($DBFILES as $f) {
    foreach ([$f, $f . '-wal', $f . '-shm'] as $p) @unlink($p);
}

echo $FAILED === 0 ? "\nall room tests passed\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
