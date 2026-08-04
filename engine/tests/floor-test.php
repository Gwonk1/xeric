<?php
/**
 * Xeric — floor tests: the answer beat. `php engine/tests/floor-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL — every call goes through the stub seam. What this file
 * defends, in the order the design argued it:
 *
 *   - The count is an outcome, not a quota. One line said into a room may get
 *     one answer, two, or a shrug — never three-on-a-timer.
 *   - A name in the sentence is the selection: the addressed head is reached
 *     first and may not pass; everyone else may, with the literal marker.
 *   - Everyone passing lands in the channel as a narrator line, because a
 *     question hanging unanswered by machinery reads as a broken page.
 *   - Discretion is injected fail-closed: only into a head whose own system
 *     already carries the secret, never into the protected head, and never
 *     into a walled head that would LEARN the secret from the instruction.
 *   - The wall post-check and the age floor still drop what they always
 *     dropped; a dropped line is a beat of silence, not a leak.
 *   - The trail has names and numbers on every die the beat rolled.
 */

declare(strict_types=1);

require_once __DIR__ . '/../floor.php';

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

function ep(string $when, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();
}

const FIXTURE = __DIR__ . '/../fixtures/milldale.json';
$T = xeric_world_load(FIXTURE);
$GLOBALS['T'] = $T;

$DBFILES = [];
function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-floor-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    $db = xeric_state_open($path);
    xeric_state_seed($db, $GLOBALS['T']);
    return $db;
}

/**
 * A stub whose script is per-call: hand it a list of replies and it deals them
 * out in order, recording every prompt it was shown. A reply past the end of
 * the script fails loudly — a beat that calls more than its script is a beat
 * that broke its own ask ceiling.
 */
function stub_script(array $replies, array &$calls): array
{
    $n = 0;
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts)
            use (&$calls, &$n, $replies) {
        $calls[] = ['tag' => $tag, 'msgs' => $msgs];
        if ($tag !== 'chat') return ['memories' => []];
        if (!array_key_exists($n, $replies)) {
            throw new RuntimeException('the beat asked for call ' . ($n + 1)
                . ' and the script only had ' . count($replies));
        }
        return $replies[$n++];
    }];
}

/** An endpoint that FAILS THE TEST if anything reaches it. */
function stub_never(): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) {
        throw new RuntimeException('the model was called and should not have been');
    }];
}

/** The system message of the nth chat call, for prompt archaeology. */
function call_system(array $calls, int $i): string
{
    foreach ($calls[$i]['msgs'] as $m) {
        if ($m['role'] === 'system') return (string)$m['content'];
    }
    return '';
}

/** The last user message of the nth chat call — where the volatile block rides. */
function call_volatile(array $calls, int $i): string
{
    $last = '';
    foreach ($calls[$i]['msgs'] as $m) {
        if ($m['role'] === 'user') $last = (string)$m['content'];
    }
    return $last;
}

// Sun 10:00 is Milldale's three-strong hour: pastor_dale, ruth and janelle are
// all at first_lutheran (the same geometry room-test and chatter-test stand on).
$SUN = xeric_world_now($T, ep('2026-08-02 10:00'));
// Sat 10:00: dot alone at the Bluebird; harlan and theo at the hardware store.
$SAT = xeric_world_now($T, ep('2026-08-01 10:00'));

/** Land the user's line the way play.php does, so the beat reads a real channel. */
function say_into(array $t, PDO $db, string $place, string $text, array $now): void
{
    $conv = xeric_chatter_conversation($t, $db, $place);
    xeric_message_append($db, $conv, 'user', null, $text, (int)$now['epoch']);
}

// ---------------------------------------------------------------------------
// 1. The refusals — before any model call
// ---------------------------------------------------------------------------

$db = fresh_db('refusals');

$r = xeric_floor_answer($T, $db, 'the_mill', 'anybody here?', stub_never(), ['now' => $SUN]);
ok('empty room: silence of the empty kind, no calls, nothing written',
    $r['silence'] && $r['said'] === 0 && $r['asked'] === []);
ok('empty room: no narrator shrug either — the caller says the room is empty',
    xeric_conversation_find($db, 'the_mill', 'room') === null);

$r = xeric_floor_answer($T, $db, 'the_moon', 'hello?', stub_never(), ['now' => $SUN]);
ok('nowhere: a place nobody has a map for answers nobody', $r['silence'] && $r['said'] === 0);

$threw = false;
try { xeric_floor_answer($T, $db, 'bluebird', '   ', stub_never(), ['now' => $SUN]); }
catch (RuntimeException $e) { $threw = str_contains($e->getMessage(), 'nothing to answer'); }
ok('an empty line is refused loudly, not answered quietly', $threw);

// ---------------------------------------------------------------------------
// 2. One voice is an answer — the vending machine is retired
// ---------------------------------------------------------------------------

$db = fresh_db('one-voice');
say_into($T, $db, 'first_lutheran', 'morning, all.', $SUN);

$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'morning, all.',
    stub_script(['Morning, Walt. Coffee is still warm.'], $calls),
    ['now' => $SUN, 'seed' => 7, 'interject' => 0.0]);
ok('one line lands and the beat ends', $r['said'] === 1 && count($r['lines']) === 1,
   'said=' . $r['said']);
ok('exactly one model call — not three', count($calls) === 1, 'calls=' . count($calls));
ok('the reply is not a fixed roster walk', !$r['silence'] && $r['note'] === '');

$conv = xeric_chatter_conversation($T, $db, 'first_lutheran');
$back = xeric_chatter_scrollback($T, $db, $conv, 10);
$last = end($back);
ok('the line is in the channel, spoken by whoever the draw reached',
    $last !== false && $last['role'] === 'character' && $last['text'] === 'Morning, Walt. Coffee is still warm.');
ok('the beat stamped the chatter watermark so the idle beat does not pile on',
    (int)(xeric_world_state_get($db, 'chatter:first_lutheran', '0') ?? 0) > 0);
ok('the channel is marked read — the person was staring at it', (function () use ($db, $conv) {
    $st = $db->prepare('SELECT unread FROM conversations WHERE id = ?');
    $st->execute([$conv]);
    return (int)$st->fetchColumn() === 0;
})());

// ---------------------------------------------------------------------------
// 3. The interject: a second voice is a rolled chance, never a quota
// ---------------------------------------------------------------------------

$db = fresh_db('interject');
say_into($T, $db, 'first_lutheran', 'you will not believe what I saw at the mill.', $SUN);

$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'you will not believe what I saw at the mill.',
    stub_script(['At the mill? Go on.', 'Now this I want to hear.'], $calls),
    ['now' => $SUN, 'seed' => 7, 'interject' => 1.0]);
ok('interject forced on: two voices land', $r['said'] === 2, 'said=' . $r['said']);
ok('two voices is the ceiling even with the chance at 1.0', count($calls) === 2);
ok('the two lines are from two different heads',
    $r['lines'][0]['handle'] !== $r['lines'][1]['handle'],
    $r['lines'][0]['handle'] . ' twice');

$db = fresh_db('no-interject');
say_into($T, $db, 'first_lutheran', 'quiet in here today.', $SUN);
$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'quiet in here today.',
    stub_script(['It is. Suits me.'], $calls),
    ['now' => $SUN, 'seed' => 7, 'interject' => 0.0]);
ok('interject forced off: one voice, one call', $r['said'] === 1 && count($calls) === 1);

// ---------------------------------------------------------------------------
// 4. Addressed by name: reached first, and may not pass
// ---------------------------------------------------------------------------

$db = fresh_db('addressed');
say_into($T, $db, 'first_lutheran', 'Ruth, is that true?', $SUN);

$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, is that true?',
    stub_script(['Depends who told you.'], $calls),
    ['now' => $SUN, 'seed' => 11, 'interject' => 0.0]);
ok('the named head answers', $r['said'] === 1 && $r['lines'][0]['handle'] === 'ruth',
   'spoke=' . ($r['lines'][0]['handle'] ?? 'nobody'));
ok('the trail says she was addressed', $r['trail']['addressed'] === ['ruth']);
ok('an addressed head is told silence is not on the menu',
    str_contains(call_volatile($calls, 0), 'your name on it'));
ok('an addressed head is NOT offered the pass marker',
    !str_contains(call_volatile($calls, 0), XERIC_FLOOR_PASS));

// The addressed head balks anyway: the marker from an unlicensed mouth moves
// the floor on, and the balk is on the record.
$db = fresh_db('balk');
say_into($T, $db, 'first_lutheran', 'Ruth, look at me.', $SUN);
$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, look at me.',
    stub_script(['(stays quiet)', 'Give Ruth a minute, Walt.'], $calls),
    ['now' => $SUN, 'seed' => 11, 'interject' => 0.0]);
ok('a balk moves the floor to the next head', $r['said'] === 1
    && $r['lines'][0]['handle'] !== 'ruth', 'spoke=' . ($r['lines'][0]['handle'] ?? 'nobody'));
ok('the balk is recorded, not counted as a pass',
    $r['trail']['balked'] === ['ruth'] && $r['passed'] === []);

// ---------------------------------------------------------------------------
// 5. The pass: license, the marker, and hygiene order
// ---------------------------------------------------------------------------

$db = fresh_db('pass');
say_into($T, $db, 'first_lutheran', 'anyone want to split a sandwich?', $SUN);

$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'anyone want to split a sandwich?',
    stub_script(['(stays quiet)', 'Split it how?'], $calls),
    ['now' => $SUN, 'seed' => 3, 'interject' => 0.0]);
ok('an unaddressed head may pass and the next is reached',
    $r['said'] === 1 && count($r['passed']) === 1 && count($calls) === 2,
    'passed=' . implode(',', $r['passed']));
ok('an unaddressed head IS offered the marker',
    str_contains(call_volatile($calls, 0), XERIC_FLOOR_PASS));
ok('the pass is in the trail with the speaker who then landed',
    $r['trail']['passed'] === $r['passed'] && count($r['trail']['spoke']) === 1);

// The marker survives sloppy wrapping, and speech containing the words does not
// read as a pass.
ok('pass: bare',            xeric_floor_passed('(stays quiet)'));
ok('pass: cased + period',  xeric_floor_passed('  (Stays Quiet). '));
ok('pass: quoted',          xeric_floor_passed('"stays quiet"'));
ok('pass: stage-wrapped',   xeric_floor_passed('*stays quiet*'));
ok('speech is not a pass',  !xeric_floor_passed('He stays quiet about the mill, always has.'));
ok('empty is not a pass',   !xeric_floor_passed(''));

// ---------------------------------------------------------------------------
// 6. Silence is an answer, and it is written down
// ---------------------------------------------------------------------------

$db = fresh_db('silence');
say_into($T, $db, 'first_lutheran', 'so. taxes.', $SUN);

$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'so. taxes.',
    stub_script(['(stays quiet)', '(stays quiet)', '(stays quiet)'], $calls),
    ['now' => $SUN, 'seed' => 5, 'interject' => 0.0]);
ok('everyone passes: silence, said so', $r['said'] === 0 && $r['silence'],
   'said=' . $r['said']);
ok('the ask ceiling held', count($calls) <= XERIC_FLOOR_ASKS, 'calls=' . count($calls));
ok('the shrug is the room\'s, names only', $r['note'] === 'Nobody picks it up.');

$conv = xeric_chatter_scrollback($T, $db,
    xeric_chatter_conversation($T, $db, 'first_lutheran'), 10);
$last = end($conv);
ok('the shrug landed in the channel as a narrator line',
    $last !== false && $last['role'] === 'narrator' && $last['text'] === 'Nobody picks it up.');

// One person in the room: the shrug is theirs, by name, no pronouns.
$db = fresh_db('silence-solo');
say_into($T, $db, 'bluebird', 'slow day, Dot?', $SAT);
$r = xeric_floor_answer($T, $db, 'bluebird', 'slow day, Dot?',
    stub_script(['(stays quiet)', '(stays quiet)', '(stays quiet)'], $calls),
    ['now' => $SAT, 'seed' => 5, 'interject' => 0.0]);
// Dot was addressed, so her first marker is a balk; with nobody else the beat
// ends in silence and the shrug carries her name.
ok('solo room, all quiet: the shrug is hers by name',
    $r['silence'] && $r['note'] === xeric_world_name($T, 'dot') . ' lets it sit.',
    $r['note']);
ok('and the balk is on the record', $r['trail']['balked'] === ['dot']);

// ---------------------------------------------------------------------------
// 7. Discretion: the standing-right-here instruction, gated fail-closed
// ---------------------------------------------------------------------------

// Milldale ships no must_not_know; give it one. Janelle's family_innocence wall
// already hides economies.thursday_pot from her, so the secret's own words are
// IN the adults' assemblies and NOT in hers — exactly the geometry the gate
// needs testing against.
$TP = $T;
$TP['cast']['special_roles'][] = [
    'role' => 'protected', 'character' => 'janelle',
    'must_not_know' => 'the penny-ante poker pot behind the folding chairs',
];

$db = fresh_db('discretion');
say_into($TP, $db, 'first_lutheran', 'good sermon today.', $SUN);

$calls = [];
$r = xeric_floor_answer($TP, $db, 'first_lutheran', 'good sermon today.',
    stub_script(['Thank you, Walt.', 'It was.', 'Mm.'], $calls),
    ['now' => $SUN, 'seed' => 2, 'interject' => 1.0]);
$sawInstruction = false; $protectedGotIt = false; $walledGotIt = false;
foreach ($calls as $i => $c) {
    if ($c['tag'] !== 'chat') continue;
    $sys = call_system($calls, $i);
    $vol = call_volatile($calls, $i);
    $hasLine = str_contains($vol, 'blind spot');
    // Whose head is this? Janelle's own assembly is the one that does not
    // carry the pot — her wall hides the economy — so match on that.
    $carries = xeric_sweep_touches($sys, 'the penny-ante poker pot behind the folding chairs');
    if ($hasLine && $carries)   $sawInstruction = true;
    if ($hasLine && !$carries)  $walledGotIt = true;     // taught the secret by the warning
    if ($hasLine && str_contains($vol, 'Janelle is standing right here')
        && !$carries)           $protectedGotIt = true;
}
ok('discretion: a head that carries the secret is told she is standing right here',
    $sawInstruction);
ok('discretion: never injected into a head whose walls hide the secret',
    !$walledGotIt);
ok('discretion: the protected head is never warned about her own blind spot',
    !$protectedGotIt);
ok('discretion: the trail carries names only, never the secret',
    !str_contains(json_encode($r['trail']), 'penny-ante'));

// With the protected head OUT of the room there is nothing to steer around.
$db = fresh_db('discretion-away');
say_into($TP, $db, 'beck_hardware', 'busy morning?', $SAT);
$calls = [];
$r = xeric_floor_answer($TP, $db, 'beck_hardware', 'busy morning?',
    stub_script(['Steady enough.'], $calls),
    ['now' => $SAT, 'seed' => 2, 'interject' => 0.0]);
ok('discretion: absent protected head, no instruction anywhere',
    !str_contains(call_volatile($calls, 0), 'blind spot'));

// And the POST-check still stands for everybody: a line that reaches the
// protected listener with the secret in it is dropped, whoever spoke it.
$db = fresh_db('wall-drop');
say_into($TP, $db, 'first_lutheran', 'what do you all get up to thursdays?', $SUN);
$calls = []; $whys = [];
$r = xeric_floor_answer($TP, $db, 'first_lutheran', 'what do you all get up to thursdays?',
    stub_script([
        'Oh, the penny-ante poker pot behind the folding chairs, same as always.',
        '(stays quiet)', '(stays quiet)',
    ], $calls),
    ['now' => $SUN, 'seed' => 2, 'interject' => 0.0,
     'on_why' => function ($k, $h, $d) use (&$whys) { $whys[] = $k; }]);
ok('the wall post-check drops the leak with the protected head standing there',
    in_array('wall', $whys, true) && $r['said'] === 0,
    'whys=' . implode(',', $whys) . ' said=' . $r['said']);
ok('the drop is in the trail with its reason',
    array_column($r['trail']['dropped'], 'why') === ['wall']);
$back = xeric_chatter_scrollback($T, $db,
    xeric_chatter_conversation($TP, $db, 'first_lutheran'), 10);
$leaked = false;
foreach ($back as $l) if (str_contains($l['text'], 'penny-ante')) $leaked = true;
ok('the dropped line never reached the channel', !$leaked);

// ---------------------------------------------------------------------------
// 8. The age floor, roomful scope, the turn read as one piece
// ---------------------------------------------------------------------------

// Sat 10:00 at the hardware store: harlan (66) and theo (12). A reply that
// turns the user's line sexual is refused with a child standing in the room,
// whoever spoke it.
$db = fresh_db('age');
say_into($T, $db, 'beck_hardware', 'long night?', $SAT);
$calls = []; $whys = [];
$r = xeric_floor_answer($T, $db, 'beck_hardware', 'long night?',
    stub_script([
        'Long one. We made love till three, if you want the details.',
        '(stays quiet)', '(stays quiet)',
    ], $calls),
    ['now' => $SAT, 'seed' => 4, 'interject' => 0.0,
     'on_why' => function ($k, $h, $d) use (&$whys) { $whys[] = $k; }]);
ok('the age floor drops the line with a child in the room',
    in_array('age', $whys, true), 'whys=' . implode(',', $whys));
ok('the rating receipt folded to the child standing in it', $r['rating'] === 'sfw',
   $r['rating']);

// ---------------------------------------------------------------------------
// 9. The trail: names and numbers on every die
// ---------------------------------------------------------------------------

$db = fresh_db('trail');
say_into($T, $db, 'first_lutheran', 'Ruth, tell them what you told me.', $SUN);
$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, tell them what you told me.',
    stub_script(['It can wait.'], $calls),
    ['now' => $SUN, 'seed' => 9, 'interject' => 0.0]);
$trail = $r['trail'];
ok('trail: kind, place, and the roomful', $trail['kind'] === 'floor'
    && $trail['place'] === 'first_lutheran' && count($trail['present']) === 3);
ok('trail: a weight per head present', count($trail['weights']) === 3
    && array_diff(array_keys($trail['weights']), $trail['present']) === []);
ok('trail: asked order and who spoke', $trail['asked'] === $r['asked']
    && $trail['spoke'] === ['ruth']);
$stored = json_decode((string)xeric_world_state_get($db, 'why:floor:first_lutheran'), true);
ok('trail: stored under the inspector\'s key, and it matches',
    is_array($stored) && $stored['spoke'] === ['ruth'] && $stored['at'] === $trail['at']);

// ---------------------------------------------------------------------------
// 10. The opener tic: the second beat retires the first beat's opener
// ---------------------------------------------------------------------------

$db = fresh_db('tic');
say_into($T, $db, 'first_lutheran', 'Ruth, how are you?', $SUN);
$calls = [];
xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, how are you?',
    stub_script(['Well, sure, can\'t complain.'], $calls),
    ['now' => $SUN, 'seed' => 9, 'interject' => 0.0]);
ok('tic: a first line gets no coaching',
    !str_contains(call_volatile($calls, 0), 'do not start with those words'));

say_into($T, $db, 'first_lutheran', 'Ruth, you sure about that?', $SUN);
$calls2 = [];
xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, you sure about that?',
    stub_script(['No. But it passes the time.'], $calls2),
    ['now' => $SUN, 'seed' => 9, 'interject' => 0.0]);
ok('tic: the second beat names her first opener and retires it',
    str_contains(call_volatile($calls2, 0), 'started "Well, sure, can\'t"')
    && str_contains(call_volatile($calls2, 0), 'do not start with those words'));

// ---------------------------------------------------------------------------

foreach ($DBFILES as $f) foreach ([$f, $f . '-wal', $f . '-shm'] as $x) @unlink($x);

echo $FAILED === 0 ? "\nfloor-test: all green\n" : "\nfloor-test: $FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
