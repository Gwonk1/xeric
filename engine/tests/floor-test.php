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
ok('pass: curly-quoted',    xeric_floor_passed('“(stays quiet)”') && xeric_floor_passed('‘stays quiet’'));
ok('pass: trailing ellipsis', xeric_floor_passed('(stays quiet…)') && xeric_floor_passed('*stays quiet*…'));
ok('curly speech is not a pass', !xeric_floor_passed('“He stays quiet about the mill.”'));
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
// 11. More than one person at the centre: the beat names who actually spoke
// ---------------------------------------------------------------------------

$db   = fresh_db('guest');
$neil = xeric_player_add($db, $T, 'Neil');
$gcnv = xeric_chatter_conversation($T, $db, 'first_lutheran');
xeric_message_append($db, $gcnv, 'user', xeric_player_key('@you', $neil),
    'hello in here.', (int)$SUN['epoch']);
$calls = [];
xeric_floor_answer($T, $db, 'first_lutheran', 'hello in here.',
    stub_script(['Afternoon.'], $calls),
    ['now' => $SUN, 'seed' => 7, 'interject' => 0.0, 'player' => $neil]);
ok('guest: the coach names who actually spoke',
    str_contains(call_volatile($calls, 0), 'Neil said that out loud'));
ok('guest: the transcript labels the line with the guest\'s name',
    str_contains(call_volatile($calls, 0), 'Neil: hello in here.'));
ok('guest: the system says the centre is shared',
    str_contains(call_system($calls, 0), 'does not live here alone'));
// THE SYSTEM NAMES THE PERSON PRESENT, NOT THE TEMPLATE OWNER. It used to
// hard-code Walt into the here-line, so with a guest speaking the cast
// greeted somebody who was not in the room — and the clean guard shielded
// the wrong name while it happened.
ok('guest: the room is told the SPEAKER is standing in it',
    str_contains(call_system($calls, 0), 'Neil is here too, in the room with you'));
ok('guest: and not the absent owner',
    !str_contains(call_system($calls, 0), 'Walt is here too'));

// Two bodies standing in the same room are named together, off the presence
// data: the speaker first, then whoever else's body is here.
$db    = fresh_db('guest-both');
$neilB = xeric_player_add($db, $T, 'Neil');
xeric_player_move($db, 'first_lutheran', (int)$SUN['epoch'], $neilB);
say_into($T, $db, 'first_lutheran', 'both of us made it.', $SUN);
$calls = [];
xeric_floor_answer($T, $db, 'first_lutheran', 'both of us made it.',
    stub_script(['So you did.'], $calls),
    ['now' => $SUN, 'seed' => 7, 'interject' => 0.0]);
ok('bodies: the owner speaking with a guest\'s body in the room names them both',
    str_contains(call_system($calls, 0), 'Walt and Neil are here too, in the room with you'),
    mb_substr((string)strstr(call_system($calls, 0), 'here too'), 0, 120));

// And the bare row stays the first person's — the no-migration rule.
$db = fresh_db('owner-default');
say_into($T, $db, 'first_lutheran', 'me again.', $SUN);
$calls = [];
xeric_floor_answer($T, $db, 'first_lutheran', 'me again.',
    stub_script(['Hello, Walt.'], $calls),
    ['now' => $SUN, 'seed' => 7, 'interject' => 0.0]);
ok('owner: the default path still speaks for the first person',
    str_contains(call_volatile($calls, 0), 'Walt said that out loud')
    && str_contains(call_volatile($calls, 0), 'Walt: me again.'));
ok('owner: an unshared centre carries no companions sentence',
    !str_contains(call_system($calls, 0), 'does not live here alone'));

// ---------------------------------------------------------------------------
// 12. A promise made to somebody's face leaves an arc
// ---------------------------------------------------------------------------

$db = fresh_db('promise');
$say2 = 'Ruth, I\'ll bring the ladder back tomorrow.';
say_into($T, $db, 'first_lutheran', $say2, $SUN);
$r = xeric_floor_answer($T, $db, 'first_lutheran', $say2,
    stub_script(['Mind the rungs, Walt.'], $calls),
    ['now' => $SUN, 'seed' => 9, 'interject' => 0.0]);
$exR = array_values(array_filter(xeric_expects_for($db, 'ruth'), fn($e) => ($e['state'] ?? '') === 'open'));
ok('promise: the addressed head now expects the player',
    count($exR) === 1 && str_contains((string)$exR[0]['quote'], 'ladder'),
    json_encode($exR));
ok('promise: the first player writes no marker — the bare case',
    !isset($exR[0]['p']) && !isset($exR[0]['of']));
ok('promise: the trail carries the arc it formed',
    ($r['trail']['expects'] ?? []) !== []);

// A guest's promise is stamped to the guest.
$db = fresh_db('promise-guest');
$neil2 = xeric_player_add($db, $T, 'Neil');
$gcnv2 = xeric_chatter_conversation($T, $db, 'first_lutheran');
xeric_message_append($db, $gcnv2, 'user', xeric_player_key('@you', $neil2),
    'Ruth, I\'ll shovel your walk tomorrow.', (int)$SUN['epoch']);
xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, I\'ll shovel your walk tomorrow.',
    stub_script(['That is kind, Neil.'], $calls),
    ['now' => $SUN, 'seed' => 9, 'interject' => 0.0, 'player' => $neil2]);
$exG = array_values(array_filter(xeric_expects_for($db, 'ruth'), fn($e) => ($e['state'] ?? '') === 'open'));
ok('promise: a guest\'s promise carries the guest\'s number',
    count($exG) === 1 && (int)($exG[0]['p'] ?? 0) === $neil2, json_encode($exG));

// The gates: a hedge is not a promise, a promise with no when is a sentiment,
// and said to a full room with no name on it is said to nobody in particular.
foreach ([
    ['hedge',   'Ruth, I might come by tomorrow.'],
    ['no-when', 'Ruth, I\'ll fix it.'],
    ['no-name', 'I\'ll bring donuts tomorrow.'],
] as [$tag, $line]) {
    $db = fresh_db('promise-' . $tag);
    say_into($T, $db, 'first_lutheran', $line, $SUN);
    xeric_floor_answer($T, $db, 'first_lutheran', $line,
        stub_script(['Mm.'], $calls), ['now' => $SUN, 'seed' => 9, 'interject' => 0.0]);
    $none = array_filter(array_merge(
        xeric_expects_for($db, 'ruth'), xeric_expects_for($db, 'pastor_dale'),
        xeric_expects_for($db, 'janelle')), fn($e) => ($e['state'] ?? '') === 'open');
    ok("promise: $tag forms nothing", $none === [], json_encode(array_values($none)));
}

// Alone with one person, no name needed — and it forms even when she lets it
// sit, because a promise is heard whether or not it is answered.
$db = fresh_db('promise-solo');
say_into($T, $db, 'bluebird', 'I\'ll come back at nine tomorrow.', $SAT);
$r = xeric_floor_answer($T, $db, 'bluebird', 'I\'ll come back at nine tomorrow.',
    stub_script(['(stays quiet)', '(stays quiet)', '(stays quiet)'], $calls),
    ['now' => $SAT, 'seed' => 5, 'interject' => 0.0]);
$exD = array_values(array_filter(xeric_expects_for($db, 'dot'), fn($e) => ($e['state'] ?? '') === 'open'));
ok('promise: the only one in the room is the listener, even through her silence',
    $r['silence'] && count($exD) === 1, json_encode($exD));

// A promise between two of the cast, made in a landed reply, goes into the
// trail under the same shape the player's does: "<listener>:<key>". The key
// alone (expect.N, numbered per listener) does not say whose arc it is, and
// the inspector used to try it against everyone who was present.
$db = fresh_db('promise-cast');
say_into($T, $db, 'first_lutheran', 'Ruth, how is the roof fund?', $SUN);
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, how is the roof fund?',
    stub_script(['Dale, I\'ll bring the hymnals by tomorrow.', 'Good of you.', 'Mm.', 'Mm.'], $calls),
    ['now' => $SUN, 'seed' => 9, 'interject' => 1.0]);
$exC = array_values(array_filter(xeric_expects_for($db, 'pastor_dale'), fn($e) => ($e['state'] ?? '') === 'open'));
ok('promise: one of the cast promising another in a reply forms the arc',
    count($exC) === 1 && (string)($exC[0]['of'] ?? '') === 'ruth', json_encode([$exC, $r['trail']['spoke'] ?? null]));
ok('promise: the trail names whose arc it is, for a promise among the cast too',
    ($r['trail']['expects'] ?? []) === ['pastor_dale:' . (string)($exC[0]['key'] ?? '?')],
    json_encode($r['trail']['expects'] ?? null));

// ---------------------------------------------------------------------------
// 13. The pinned-voice resolver: the floor speaks off the SPEAKER's machine
// ---------------------------------------------------------------------------

// Every other speaks-as-themselves surface routes through the resolver seam
// (say, watch, chatter, pings, dreams); the floor was the one landed-reply
// path still hard-wired to the default endpoint. `endpoint_for` is handle →
// endpoint, resolved per SPEAKER — and the default endpoint here FAILS THE
// TEST if anything reaches it, which is the proof the seam carried the call.
$db = fresh_db('resolver');
say_into($T, $db, 'first_lutheran', 'Ruth, you still there?', $SUN);
$resolved = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, you still there?',
    stub_never(),
    ['now' => $SUN, 'seed' => 11, 'interject' => 0.0,
     'endpoint_for' => function (string $h) use (&$resolved): array {
         $resolved[] = $h;
         return ['base' => 'stub://' . $h, 'stub' => function (string $tag, array $msgs, array $opts) {
             return $tag === 'chat' ? 'Right here.' : ['memories' => []];
         }];
     }]);
ok('resolver: the landed line came off the resolved endpoint, not the default',
    $r['said'] === 1 && $r['lines'][0]['text'] === 'Right here.',
    json_encode($r['lines']));
ok('resolver: resolved per SPEAKER — the addressed head, by handle',
    $resolved === ['ruth'], implode(',', $resolved));

// A resolver that throws must not silence the room: the default endpoint
// speaks, exactly as chatter.php's seam behaves.
$db = fresh_db('resolver-throws');
say_into($T, $db, 'first_lutheran', 'Ruth, one more thing.', $SUN);
$calls = [];
$r = xeric_floor_answer($T, $db, 'first_lutheran', 'Ruth, one more thing.',
    stub_script(['Go on, then.'], $calls),
    ['now' => $SUN, 'seed' => 11, 'interject' => 0.0,
     'endpoint_for' => function (string $h): array {
         throw new RuntimeException('this pin is broken');
     }]);
ok('resolver: a failing resolver falls back to the room\'s own endpoint',
    $r['said'] === 1 && $r['lines'][0]['text'] === 'Go on, then.');

// ---------------------------------------------------------------------------

foreach ($DBFILES as $f) foreach ([$f, $f . '-wal', $f . '-shm'] as $x) @unlink($x);

echo $FAILED === 0 ? "\nfloor-test: all green\n" : "\nfloor-test: $FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
