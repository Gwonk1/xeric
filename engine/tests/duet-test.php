<?php
/**
 * Xeric — duet tests. `php engine/tests/duet-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL. Every call goes through the stub seam, because what is
 * worth defending here is the constitution, not the prose:
 *
 *   - N people is N calls: each spoken line is its own call, from its own
 *     speaker's own assembly, and NOTHING of the partner's interior ever
 *     appears in it. The needle test below is the whole feature: one secret
 *     per speaker, grepped for in every byte the other speaker's calls carry.
 *   - two people not in a room together are refused, by name, with geography.
 *   - one minor in the room clamps BOTH speakers' calls to the floor.
 *   - the world is read-only until the close, and the close is one transaction:
 *     row counts are probed FROM INSIDE the model calls and must sit at the
 *     baseline until the last call has returned.
 *   - the diaries diverge, and the second harvest may not restate the first.
 *   - the record is commons: one event that says they talked, a trail that
 *     says why, and not one spoken word in either.
 */

declare(strict_types=1);

require_once __DIR__ . '/../duet.php';

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
//   Tue 07:30  ruth and dot are both at the bluebird (ruth's coffee hour).
//   Mon 10:00  pastor_dale is at first_lutheran, dot at the bluebird, ruth nowhere.
//   Sat 10:00  harlan and theo (12) are both at beck_hardware.
//   Sun 10:00  pastor_dale, ruth and janelle are all at first_lutheran.
$TUE = xeric_world_now($T, ep('2026-07-28 07:30'));
$MON = xeric_world_now($T, ep('2026-07-27 10:00'));
$SAT = xeric_world_now($T, ep('2026-08-01 10:00'));
$SUN = xeric_world_now($T, ep('2026-08-02 10:00'));

// The needles. WALLS are the engine's privacy mechanism, not dossiers per se:
// an unwalled cast member reads the whole bible, secrets included, in chat
// today — so ruth's prompt legitimately carries dot's secret, and the duet
// guarantee is not "nobody sees anything" but "each speaker gets exactly their
// OWN legal assembly". The pair that proves it is the walled one: janelle
// (own_bible + family_innocence, which hide cast_dossiers and secrets) must
// never receive a byte of dale's, while dale keeps his own. Asserted as bytes
// rather than trusted as architecture.
const DALE_SECRET    = 'five-card draw';
const JANELLE_SECRET = 'Chillicothe';

/** A throwaway world db. Every test that writes gets its own. */
function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-duet-test-' . getmypid() . '-' . $tag . '.db';
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
function stub_duet(array &$calls, array $diaries = [], ?callable $probe = null, ?callable $lines = null): array
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

/** Every byte a set of captured calls put on the wire, one string per call. */
function call_bytes(array $calls): array
{
    return array_map(fn($c) => json_encode($c['msgs'], JSON_UNESCAPED_UNICODE), $calls);
}

function whose(array $call): string
{
    $sys = (string)$call['msgs'][0]['content'];
    if (str_contains($sys, 'YOU ARE RUTH AMBERG')) return 'ruth';
    if (str_contains($sys, 'YOU ARE DOT VANCE')) return 'dot';
    if (str_contains($sys, 'YOU ARE HARLAN BECK')) return 'harlan';
    if (str_contains($sys, 'YOU ARE THEO VANCE')) return 'theo';
    if (str_contains($sys, 'YOU ARE PASTOR DALE OSTRANDER')) return 'pastor_dale';
    if (str_contains($sys, 'YOU ARE JANELLE KERR')) return 'janelle';
    return '?';
}

// ---------------------------------------------------------------------------
// 1. Admission: who may duet, and the refusals that name the geography
// ---------------------------------------------------------------------------

$db = fresh_db('refusals');
$deadStub = ['base' => 'stub://', 'stub' => function () { throw new RuntimeException('the model was reached, and must not have been'); }];

ok('refuse: the same person twice is not a conversation',
    str_contains(err(fn() => xeric_duet($T, $db, 'ruth', 'ruth', $TUE, $deadStub)), 'two people'));
ok('refuse: an unknown handle is named, loudly',
    str_contains(err(fn() => xeric_duet($T, $db, 'ruth', 'nobody_here', $TUE, $deadStub)), "'nobody_here'"));
ok('refuse: a fixture cannot carry half a conversation',
    str_contains(err(fn() => xeric_duet($T, $db, 'ruth', 'cy', $TUE, $deadStub)), 'scenery'));

$apart = err(fn() => xeric_duet($T, $db, 'pastor_dale', 'dot', $MON, $deadStub));
ok('refuse: two people in two buildings, and the refusal names both rooms',
    str_contains($apart, 'not in a room together')
    && str_contains($apart, 'First Lutheran') && str_contains($apart, 'Bluebird'), $apart);

$offshift = err(fn() => xeric_duet($T, $db, 'ruth', 'dot', $MON, $deadStub));
ok('refuse: somebody off any schedule is nowhere, and nowhere is not a room',
    str_contains($offshift, 'Ruth Amberg is not at any of this world\'s places'), $offshift);

ok('refuse: nothing was written by any refusal',
    xeric_events_count($db) === 0 && xeric_memories_count($db) === 0
    && xeric_conversations_count($db) === 0);

// The dead do not talk, even to each other.
$dbD = fresh_db('dead');
xeric_death_kill($T, $dbD, 'dot', $TUE['epoch'], 'her heart, at the grill', null, false);
$deadMsg = err(fn() => xeric_duet($T, $dbD, 'ruth', 'dot', $TUE, $deadStub));
ok('refuse: the dead do not answer, in the death module\'s own sentence',
    str_contains($deadMsg, 'Dot Vance') && str_contains($deadMsg, 'duet'), $deadMsg);

// ---------------------------------------------------------------------------
// 2. The constitution: alternation, per-speaker assembly, the needle test
// ---------------------------------------------------------------------------

$db2    = fresh_db('walls');
$calls  = [];
$seen   = [];
$stub   = stub_duet($calls, [
    'Ruth Amberg' => ['Ruth heard Dot say the freezer was on its way out.'],
    'Dot Vance'   => ['Dot noticed Ruth folding the same napkin twice while they talked.'],
]);

$out = xeric_duet($T, $db2, 'ruth', 'dot', $TUE, $stub, [
    'say_first' => 'ruth', 'turns' => 6,
    'on_line'   => function (string $h, string $n, string $t2) use (&$seen) { $seen[] = [$h, $t2]; },
]);

$chat    = array_values(array_filter($calls, fn($c) => $c['tag'] === 'chat'));
$extract = array_values(array_filter($calls, fn($c) => $c['tag'] === 'extract'));

ok('duet: six lines is six model calls, plus one diary call each',
    count($chat) === 6 && count($extract) === 2, count($chat) . ' chat, ' . count($extract) . ' extract');
ok('duet: the speakers strictly alternate, opener honoured',
    array_map('whose', $chat) === ['ruth', 'dot', 'ruth', 'dot', 'ruth', 'dot'],
    implode(',', array_map('whose', $chat)));

// THE NEEDLE TEST, on the walled pair. Every byte of every call janelle's
// model receives — system, transcript, tail, diary prompt — grepped for the
// thing her walls exist to keep out. Sunday morning puts her and dale in the
// same building, so the duet itself is legal; only the knowledge is fenced.
$dbW = fresh_db('needle');
$cW  = [];
xeric_duet($T, $dbW, 'pastor_dale', 'janelle', $SUN, stub_duet($cW, [
    'Pastor Dale Ostrander' => ['Dale heard the gutters need doing before the frost.'],
    'Janelle Kerr'          => ['Janelle said the drive in felt shorter than usual.'],
]), ['say_first' => 'pastor_dale', 'turns' => 6]);

$leak = '';
$ownJ = $ownD = false;
foreach ($cW as $i => $c) {
    $bytes = json_encode($c['msgs'], JSON_UNESCAPED_UNICODE);
    $who   = $c['tag'] === 'chat' ? whose($c)
           : (str_contains($bytes, 'Janelle Kerr would still know') ? 'janelle' : 'pastor_dale');
    if ($who === 'janelle' && str_contains($bytes, DALE_SECRET)) $leak = 'call ' . $i . ' (' . $c['tag'] . ')';
    if ($who === 'janelle' && $c['tag'] === 'chat' && str_contains($bytes, JANELLE_SECRET)) $ownJ = true;
    if ($who === 'pastor_dale' && $c['tag'] === 'chat' && str_contains($bytes, DALE_SECRET)) $ownD = true;
}
ok('WALLS: not one byte of dale\'s secret ever reaches a call of janelle\'s',
    $leak === '', $leak);
ok('WALLS: her own interior survives her walls (a person\'s own head was never governed)',
    $ownJ);
ok('WALLS: and dale keeps his own secret in his own assembly (the control)',
    $ownD);

// The transcript crosses as speech: own lines assistant, the other's user.
$third = $chat[2]['msgs'];
ok('duet: the third call reads system, own line as assistant, reply as user',
    count($third) === 3
    && $third[1]['role'] === 'assistant' && str_contains($third[1]['content'], 'spoken line 1')
    && $third[2]['role'] === 'user' && str_contains($third[2]['content'], 'spoken line 2'));
ok('duet: the clock rides the bottom of the last user message, like every turn',
    str_contains($third[2]['content'], 'RIGHT NOW'));
ok('duet: the reseating block names the partner and sends the user away',
    str_contains((string)$third[0]['content'], 'THIS ONE CONVERSATION IS DIFFERENT')
    && str_contains((string)$third[0]['content'], 'Dot Vance')
    && str_contains((string)$third[0]['content'], 'Walt is not here'));
ok('duet: the scene seats them in the room the schedule agreed on',
    str_contains((string)$chat[0]['msgs'][count($chat[0]['msgs']) - 1]['content'], 'Bluebird'));

// ---------------------------------------------------------------------------
// 3. The close: one event, two diaries, one trail — and nothing else
// ---------------------------------------------------------------------------

ok('close: one event row, titled as a sweep would title it',
    xeric_events_count($db2) === 1 && $out['title'] === 'ruth and dot talked');
$ev = xeric_events_recent($db2, 1)[0];
ok('close: the record is commons — both names, the place, and not one spoken word',
    str_contains((string)$ev['prose'], 'Ruth Amberg and Dot Vance')
    && (string)$ev['place'] === 'bluebird'
    && !str_contains((string)$ev['prose'], 'spoken line'), (string)$ev['prose']);
ok('close: participants are the two of them and the hour is off the spine',
    $ev['participants'] === ['ruth', 'dot'] && $ev['on_spine'] === false);

$trail = json_decode((string)xeric_world_state_get($db2, 'why:event:' . $out['event_id']), true);
ok('close: the trail lands under the inspector\'s own key, kind duet',
    is_array($trail) && $trail['kind'] === 'duet' && $trail['people'] === ['ruth', 'dot']);
ok('close: the trail says who spoke and why the scene existed',
    $trail['spoke_first'] === 'ruth' && $trail['last_word'] === 'dot'
    && str_contains((string)$trail['why'], 'Bluebird')
    && str_contains((string)$trail['why'], 'spoke first'), (string)($trail['why'] ?? ''));

ok('close: the diaries diverge, one memory each, in the right heads',
    $out['memories']['ruth'] === ['Ruth heard Dot say the freezer was on its way out.']
    && $out['memories']['dot'] === ['Dot noticed Ruth folding the same napkin twice while they talked.']);
$mem = xeric_memories_for($db2, 'ruth', 5);
$last = $mem[count($mem) - 1];
ok('close: a diary row is marked duet and carries the event, the partner, the place',
    (string)$last['source'] === 'duet'
    && (int)$last['meta']['event_id'] === (int)$out['event_id']
    && $last['meta']['with'] === ['dot'] && (string)$last['meta']['place'] === 'bluebird');

ok('close: no thread was created — the transcript is watched, not texted',
    xeric_conversations_count($db2) === 0);
ok('close: the lines streamed live match the lines returned',
    count($seen) === 6 && $seen[0][0] === 'ruth' && $seen[0][1] === $out['lines'][0]['text']);

// ---------------------------------------------------------------------------
// 4. Read-only until the close, probed from inside the model's own calls
// ---------------------------------------------------------------------------

$db3   = fresh_db('readonly');
$base3 = [xeric_events_count($db3), xeric_memories_count($db3),
          (int)$db3->query('SELECT COUNT(*) c FROM world_state')->fetchAll()[0]['c']];
$calls3 = [];
$probe  = fn() => [xeric_events_count($db3), xeric_memories_count($db3),
                   (int)$db3->query('SELECT COUNT(*) c FROM world_state')->fetchAll()[0]['c']];
$out3 = xeric_duet($T, $db3, 'ruth', 'dot', $TUE,
    stub_duet($calls3, ['Ruth Amberg' => ['Ruth left with the freezer on her mind.']], $probe),
    ['say_first' => 'ruth', 'turns' => 4]);

$moved = '';
foreach ($calls3 as $i => $c) {
    if ($c['db'] !== $base3) { $moved = 'call ' . $i . ': ' . json_encode($c['db']); break; }
}
ok('read-only: every model call, speech and diary alike, saw the untouched world', $moved === '', $moved);
ok('read-only: and the close moved the counts in one step',
    xeric_events_count($db3) === $base3[0] + 1
    && xeric_memories_count($db3) === $base3[1] + 1
    && xeric_world_state_get($db3, 'why:event:' . $out3['event_id']) !== null);

// ---------------------------------------------------------------------------
// 5. The room's ceiling: one minor clamps both speakers' calls
// ---------------------------------------------------------------------------

$db4a = fresh_db('ceiling-adults');
$c4a  = [];
xeric_duet($T, $db4a, 'ruth', 'dot', $TUE, stub_duet($c4a),
    ['say_first' => 'ruth', 'turns' => 2, 'effective_rating' => 'mature']);
$db4b = fresh_db('ceiling-minor');
$c4b  = [];
$out4 = xeric_duet($T, $db4b, 'harlan', 'theo', $SAT, stub_duet($c4b),
    ['say_first' => 'harlan', 'turns' => 2, 'effective_rating' => 'mature']);

$adultSys = (string)$c4a[0]['msgs'][0]['content'];    // ruth, adult room
$clampSys = (string)$c4b[0]['msgs'][0]['content'];    // harlan, child in the room
ok('ceiling: an adult room at mature is written TV-MA',
    str_contains($adultSys, 'TV-MA') && !str_contains($adultSys, 'TV-G'));
ok('ceiling: a child in the room writes the ADULT\'s call at the floor',
    str_contains($clampSys, 'TV-G') && !str_contains($clampSys, 'TV-MA'));
$trail4 = json_decode((string)xeric_world_state_get($db4b, 'why:event:' . $out4['event_id']), true);
ok('ceiling: the trail owns up to the clamp',
    !empty($trail4['minor_clamp']) && (string)$trail4['rating'] === 'sfw');

// The floor reads every spoken line, at the sweep's roomful scope: sex in an
// hour with a child in it is refused whoever said the sentence, and the world
// is left exactly as it was.
$db5 = fresh_db('floor');
$c5  = [];
$msg5 = err(fn() => xeric_duet($T, $db5, 'harlan', 'theo', $SAT,
    stub_duet($c5, [], null, fn(int $n) => $n === 1 ? 'Slow morning. Boxes.' : 'All this talk made him horny.'),
    ['say_first' => 'harlan', 'turns' => 4]));
ok('floor: a sexual line in a room with a child refuses the whole duet, by name',
    str_contains($msg5, 'child') && str_contains($msg5, 'refused'), $msg5);
ok('floor: and the refusal wrote nothing',
    xeric_events_count($db5) === 0 && xeric_memories_count($db5) === 0);

// ---------------------------------------------------------------------------
// 6. The wall, after the model: a spoken leak is refused like a sweep's
// ---------------------------------------------------------------------------

$TP = $T;
$TP['cast']['special_roles'][0]['must_not_know'] =
    'what happens at the thursday pot game in the church basement';

$db6 = fresh_db('leak');
$c6  = [];
$msg6 = err(fn() => xeric_duet($TP, $db6, 'pastor_dale', 'janelle', $SUN,
    stub_duet($c6, [], null, fn(int $n) => $n === 1
        ? 'the thursday pot game happens in the church basement, after supper'
        : 'Morning. Good crowd today.'),
    ['say_first' => 'pastor_dale', 'turns' => 4]));
ok('wall: a line that puts the protected listener next to the secret refuses the duet',
    str_contains($msg6, 'next to the thing they must not know')
    && str_contains($msg6, 'Janelle'), $msg6);
ok('wall: nothing was written', xeric_events_count($db6) === 0 && xeric_memories_count($db6) === 0);

// And a clean duet under the same protection sails through — the wall is a
// wall, not a ban on the two of them ever talking.
$db6b = fresh_db('leak-clean');
$c6b  = [];
$out6 = xeric_duet($TP, $db6b, 'pastor_dale', 'janelle', $SUN, stub_duet($c6b),
    ['say_first' => 'pastor_dale', 'turns' => 2]);
ok('wall: the same pair talking about anything else lands normally',
    xeric_events_count($db6b) === 1 && $out6['turns'] === 2);

// THE EXTRACTOR CAN SYNTHESISE — the guard's own comment, finally asserted.
// Two spoken lines that individually clear the wall (one word of the secret,
// two words of the secret) can hand an extractor enough to assemble the whole
// thing, and the protected head must not get to write her own secret down.
// Deleting duet.php's one-line guard used to leave every suite green; this is
// the red it costs now.
$db6c = fresh_db('leak-synth');
$c6c  = [];
$out6c = xeric_duet($TP, $db6c, 'pastor_dale', 'janelle', $SUN,
    stub_duet($c6c, [
        'Janelle Kerr' => ['Janelle learned the thursday game is set up in the church basement after supper.',
                           'Janelle thought the coffee was better than last week.'],
    ], null, fn(int $n) => $n === 1 ? 'Cards again thursday, same crowd as always.'
           : ($n === 2 ? 'They set it up down in the church basement once supper is cleared.'
                       : "spoken line $n, nothing much.")),
    ['say_first' => 'pastor_dale', 'turns' => 4]);
ok('wall: the lines that fed the synthesis each pass on their own — the duet lands',
    xeric_events_count($db6c) === 1 && $out6c['turns'] === 4);
$janKept = array_map(fn($m) => (string)$m['text'], xeric_memories_for($db6c, 'janelle', 10));
ok('wall: the synthesised secret never reaches her diary',
    !array_filter($janKept, fn($m) => str_contains($m, 'church basement')), json_encode($janKept));
ok('wall: while her harmless memory of the same hour is kept — the guard drops a line, not a head',
    array_filter($janKept, fn($m) => str_contains($m, 'coffee')) !== [], json_encode($janKept));

// ---------------------------------------------------------------------------
// 7. Turn order: the thumb, the pin, and the last word
// ---------------------------------------------------------------------------

$db7 = fresh_db('order-a');
$c7  = [];
$o7  = xeric_duet($T, $db7, 'ruth', 'dot', $TUE, stub_duet($c7), ['seed' => 41]);
$db7b = fresh_db('order-b');
$c7b  = [];
$o7b  = xeric_duet($T, $db7b, 'ruth', 'dot', $TUE, stub_duet($c7b), ['seed' => 41]);
ok('order: the same seed deals the same conversation',
    $o7['spoke_first'] === $o7b['spoke_first'] && $o7['turns'] === $o7b['turns']);
ok('order: an unpinned count is six lines, or seven when the draw wants the other closer',
    in_array($o7['turns'], [6, 7], true));
ok('order: the last line really belongs to the trail\'s last word',
    $o7['lines'][count($o7['lines']) - 1]['handle'] === $o7['last_word']);

$db7c = fresh_db('order-pinned');
$c7c  = [];
$o7c  = xeric_duet($T, $db7c, 'ruth', 'dot', $TUE, stub_duet($c7c),
    ['say_first' => 'dot', 'turns' => 5, 'seed' => 41]);
ok('order: a pinned count is an instruction — exactly five lines, never stretched',
    $o7c['turns'] === 5 && count(array_filter($c7c, fn($c) => $c['tag'] === 'chat')) === 5);
ok('order: a pinned opener opens', $o7c['spoke_first'] === 'dot' && whose($c7c[0]) === 'dot');

// ---------------------------------------------------------------------------
// 8. The close survives its own bookkeeping
// ---------------------------------------------------------------------------

// One diary call dying costs that diary and a note, never the scene: the talk
// happened across four witnessed calls, and learning is garnish (learn.php).
$db8 = fresh_db('diary-fail');
$c8  = [];
$boom = stub_duet($c8, [
    'Ruth Amberg' => ['Ruth heard the freezer was on its way out.'],
    'Dot Vance'   => fn() => throw new RuntimeException('llm: cannot reach 127.0.0.1'),
]);
$out8 = xeric_duet($T, $db8, 'ruth', 'dot', $TUE, $boom, ['say_first' => 'ruth', 'turns' => 4]);
ok('close: a dead diary call keeps its note and loses nothing else',
    xeric_events_count($db8) === 1
    && $out8['memories']['ruth'] !== [] && $out8['memories']['dot'] === []
    && str_contains(implode('; ', $out8['notes']), 'Dot Vance'));

// The second diary may not restate the first: one hour, two halves.
$db9 = fresh_db('diary-echo');
$c9  = [];
$same = 'Ruth and Dot agreed the freezer would not last the month.';
$out9 = xeric_duet($T, $db9, 'ruth', 'dot', $TUE, stub_duet($c9, [
    'Ruth Amberg' => [$same],
    'Dot Vance'   => [$same, 'Dot caught Ruth eyeing the pie case like it owed money.'],
]), ['say_first' => 'ruth', 'turns' => 4]);
ok('close: the second diary drops the first diary\'s sentence and keeps its own',
    $out9['memories']['ruth'] === [$same]
    && $out9['memories']['dot'] === ['Dot caught Ruth eyeing the pie case like it owed money.'],
    json_encode($out9['memories']));

// A model that answers with nothing usable fails the duet whole, chat's way.
$db10 = fresh_db('nothing');
$c10  = [];
$msg10 = err(fn() => xeric_duet($T, $db10, 'ruth', 'dot', $TUE,
    stub_duet($c10, [], null, fn() => '   '), ['say_first' => 'ruth', 'turns' => 2]));
ok('duet: an empty line fails loudly and writes nothing',
    str_contains($msg10, 'nothing usable') && xeric_events_count($db10) === 0);

// ---------------------------------------------------------------------------
// 9. The material: what seeds a side stays on its side
// ---------------------------------------------------------------------------

$db11 = fresh_db('material');
xeric_memory_add($db11, 'ruth', 'Ruth still had not thanked Dot for covering the plate on Sunday.', 'auto', [], $TUE['epoch'] - 86400);
xeric_memory_add($db11, 'ruth', 'Ruth counted the folding chairs twice and came up one short.', 'auto', [], $TUE['epoch'] - 86400);
xeric_memory_add($db11, 'dot',  'Dot heard Ruth had been in on a Tuesday asking about the pot.', 'auto', [], $TUE['epoch'] - 86400);
$c11 = [];
xeric_duet($T, $db11, 'ruth', 'dot', $TUE, stub_duet($c11), ['say_first' => 'ruth', 'turns' => 2]);
$chat11 = array_values(array_filter($c11, fn($c) => $c['tag'] === 'chat'));
$ruthCall = json_encode($chat11[0]['msgs'], JSON_UNESCAPED_UNICODE);
$dotCall  = json_encode($chat11[1]['msgs'], JSON_UNESCAPED_UNICODE);
// The SCENE block specifically: a non-partner memory still rides the system
// prompt's WHAT YOU REMEMBER like any memory — the selection question is what
// the scene is seeded FROM, and that is only what sits between these two.
$ruthTail  = (string)$chat11[0]['msgs'][count($chat11[0]['msgs']) - 1]['content'];
$ruthScene = substr($ruthTail, (int)strpos($ruthTail, 'THE SCENE'));
ok('material: a memory that mentions the partner seeds its owner\'s side of the scene',
    str_contains($ruthScene, 'covering the plate on Sunday')
    && str_contains($dotCall, 'asking about the pot'));
ok('material: one that mentions nobody in the room seeds nothing',
    !str_contains($ruthScene, 'folding chairs'), $ruthScene);
ok('material: and neither speaker\'s private material crosses the table',
    !str_contains($dotCall, 'covering the plate on Sunday')
    && !str_contains($ruthCall, 'asking about the pot'));

// ---------------------------------------------------------------------------

foreach ($DBFILES as $f) {
    foreach ([$f, $f . '-wal', $f . '-shm'] as $p) @unlink($p);
}

echo $FAILED === 0 ? "\nall duet tests passed\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
