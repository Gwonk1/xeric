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

foreach ($DBFILES as $f) {
    foreach ([$f, $f . '-wal', $f . '-shm'] as $p) @unlink($p);
}

echo $FAILED === 0 ? "\nall room tests passed\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
