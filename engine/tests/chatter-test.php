<?php
/**
 * Xeric — idle room chatter tests. `php engine/tests/chatter-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL — every model call goes through the stub seam, and most
 * of what is worth defending here never reaches one anyway, because it is about
 * WHO MAY OPEN A ROOM AND WHO MAY SPEAK IN IT.
 *
 * The rules, and the live bugs each one is standing on:
 *
 *   - The beat never chatters in somebody's house. The first live heartbeat
 *     filled six home conversations at 21:39, because two people standing
 *     together was the only test and at that hour every pair in town is a
 *     couple in their own kitchen.
 *   - A HOME IS STILL A ROOM TO SOMEBODY STANDING IN IT. The fix for the above
 *     went one further than it had to and made the room you were personally
 *     standing in refuse to open — at 23:14, with the whole cast at home, that
 *     was every room in the world.
 *   - Two to gossip, one to answer. Idle chatter needs a pair; a person talking
 *     to the one shopkeeper on shift does not, and the pair rule was refusing
 *     that with "nobody is in there to hear it" while she stood at the counter.
 *   - The pace is the user's, in the user's numbers: calm 1–2 lines per ten
 *     minutes, steady 2–3 per five, busy 5–6 per five.
 *   - The ceiling is the lowest person standing in the room, folded once.
 */

declare(strict_types=1);

require_once __DIR__ . '/../chatter.php';

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

// Milldale is all public rooms, which is the wrong shape for the one rule this
// file exists to pin down. One house, and somebody who lives in it.
$T['places'][] = ['key' => 'ruth_house', 'name' => "Ruth's house", 'kind' => 'home',
                  'hours' => [], 'minutes' => 6];

$DBFILES = [];
function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-chatter-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    $db = xeric_state_open($path);
    xeric_state_seed($db, $GLOBALS['T']);
    return $db;
}
$GLOBALS['T'] = $T;

/** A stub that records every call and hands back one plain spoken line. */
function stub_chat(array &$calls): array
{
    $n = 0;
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts)
            use (&$calls, &$n) {
        $calls[] = ['tag' => $tag, 'msgs' => $msgs];
        if ($tag === 'chat') { $n++; return "line $n, about the weather."; }
        return ['memories' => []];
    }];
}

/** An endpoint that FAILS THE TEST if anything reaches it. */
function stub_never(): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) {
        throw new RuntimeException('the model was called and should not have been');
    }];
}

// ---------------------------------------------------------------------------
// 1. What counts as a room, and to whom
// ---------------------------------------------------------------------------

ok('public: a diner is a room',        xeric_chatter_public($T, 'bluebird'));
ok('public: a shop is a room',         xeric_chatter_public($T, 'beck_hardware'));
ok('public: a house is not',           !xeric_chatter_public($T, 'ruth_house'));
ok('public: a key nobody has is not',  !xeric_chatter_public($T, 'the_moon'));
ok('public: the empty key is not',     !xeric_chatter_public($T, ''));

ok('is_place: a place on the map',     xeric_chatter_is_place($T, 'the_mill'));
ok('is_place: a house is still a place', xeric_chatter_is_place($T, 'ruth_house'));
ok('is_place: nowhere is not',         !xeric_chatter_is_place($T, 'the_moon'));

// The channel you missed. Reading a public room from across town is the whole
// point of the feature — you were asleep, the diner was talking, you scroll
// back through it in the morning.
ok('readable: a public room, from anywhere',
    xeric_chatter_readable($T, 'bluebird', null)
    && xeric_chatter_readable($T, 'bluebird', 'the_mill')
    && xeric_chatter_readable($T, 'bluebird', 'bluebird'));

// The rule the 23:14 bug was standing on, from both sides.
ok('readable: somebody\'s house, from across town, is not',
    !xeric_chatter_readable($T, 'ruth_house', null)
    && !xeric_chatter_readable($T, 'ruth_house', 'bluebird'));
ok('readable: somebody\'s house, standing in it, is',
    xeric_chatter_readable($T, 'ruth_house', 'ruth_house'));
ok('readable: nowhere is nowhere, even if you claim to be in it',
    !xeric_chatter_readable($T, 'the_moon', 'the_moon'));

// ---------------------------------------------------------------------------
// 2. The pace, in the numbers it was asked for
// ---------------------------------------------------------------------------

[$every, $lo, $hi] = xeric_chatter_rate('calm');
ok('pace: calm is 1-2 lines every ten minutes', $every === 600 && $lo === 1 && $hi === 2,
   "$every/$lo/$hi");
[$every, $lo, $hi] = xeric_chatter_rate('steady');
ok('pace: steady is 2-3 every five', $every === 300 && $lo === 2 && $hi === 3, "$every/$lo/$hi");
[$every, $lo, $hi] = xeric_chatter_rate('eventful');
ok('pace: busy is 5-6 every five', $every === 300 && $lo === 5 && $hi === 6, "$every/$lo/$hi");
[$every, $lo, $hi] = xeric_chatter_rate('  CALM  ');
ok('pace: the setting is read loosely, cased and spaced', $every === 600 && $lo === 1);
[$every, $lo, $hi] = xeric_chatter_rate('whatever nobody set');
ok('pace: an unset pace is steady, not silent', $every === 300 && $lo === 2 && $hi === 3);

// ---------------------------------------------------------------------------
// 3. Who is standing in it
// ---------------------------------------------------------------------------

// Sun 10:00 is Milldale's only three-strong hour: pastor_dale, ruth and janelle
// are all at first_lutheran (see room-test, same fixture geometry).
$db  = fresh_db('present');
$SUN = xeric_world_now($T, ep('2026-08-02 10:00'));
$here = xeric_chatter_present($T, $db, 'first_lutheran', $SUN);
sort($here);
ok('present: the three the schedule agreed on', $here === ['janelle', 'pastor_dale', 'ruth'],
   implode(',', $here));
ok('present: an empty room is empty, not absent',
    xeric_chatter_present($T, $db, 'the_mill', $SUN) === []);

// ---------------------------------------------------------------------------
// 4. The refusals — every one of these returns BEFORE a model call
// ---------------------------------------------------------------------------

$r = xeric_chatter_run($T, $db, 'ruth_house', stub_never(),
    ['now' => $SUN, 'force' => true]);
ok('run: the beat does not chatter in a house', (int)$r['said'] === 0);

$r = xeric_chatter_run($T, $db, 'the_moon', stub_never(), ['now' => $SUN, 'force' => true]);
ok('run: a place nobody has a map for says nothing', (int)$r['said'] === 0);

$r = xeric_chatter_run($T, $db, 'the_mill', stub_never(), ['now' => $SUN, 'force' => true]);
ok('run: an empty room says nothing', (int)$r['said'] === 0);

// Sat 10:00: dot is alone at the Bluebird, harlan and theo are at the hardware.
$SAT = xeric_world_now($T, ep('2026-08-01 10:00'));
$alone = xeric_chatter_present($T, $db, 'bluebird', $SAT);
ok('run: one person alone is the setup for the pair rule', count($alone) === 1,
   implode(',', $alone));

$r = xeric_chatter_run($T, $db, 'bluebird', stub_never(), ['now' => $SAT, 'force' => true]);
ok('run: one person alone does not talk to herself', (int)$r['said'] === 0);

// ...but she answers somebody who walks in and speaks. THE BUG THIS PINS: the
// pair rule was refusing a person standing at the counter with the one
// shopkeeper on shift, which is a conversation by any reading.
$calls = [];
$r = xeric_chatter_run($T, $db, 'bluebird', stub_chat($calls),
    ['now' => $SAT, 'force' => true, 'user_here' => true]);
ok('run: one person answers somebody who is standing there', (int)$r['said'] > 0,
   'said=' . (int)$r['said']);
ok('run: and it was her voice, once per line', count($calls) === (int)$r['said']);

// And the house opens for the person standing in it — the 23:14 case, where
// every soul in town is at home and the room you are in is one of them.
$calls = [];
$T2 = $T;
foreach ($T2['cast']['characters'] as &$c) {
    if (($c['handle'] ?? '') === 'ruth') { $c['home'] = 'ruth_house'; break; }
}
unset($c);
$r = xeric_chatter_run($T2, $db, 'ruth_house', stub_chat($calls),
    ['now' => $SUN, 'force' => true, 'user_here' => true]);
ok('run: a house is a room to whoever is standing in it',
    xeric_chatter_readable($T2, 'ruth_house', 'ruth_house'));

// ---------------------------------------------------------------------------
// 5. The scrollback is the point — it persists, and it is one channel
// ---------------------------------------------------------------------------

$db2   = fresh_db('scrollback');
$calls = [];
$r1 = xeric_chatter_run($T, $db2, 'first_lutheran', stub_chat($calls),
    ['now' => $SUN, 'force' => true, 'max_lines' => 2]);
ok('scrollback: the first pass said something', (int)$r1['said'] > 0);

$conv = xeric_chatter_conversation($T, $db2, 'first_lutheran');
$back = xeric_chatter_scrollback($T, $db2, $conv, 50);
ok('scrollback: what was said is on disk', count($back['lines'] ?? $back) >= (int)$r1['said']);

$calls2 = [];
$r2 = xeric_chatter_run($T, $db2, 'first_lutheran', stub_chat($calls2),
    ['now' => $SUN, 'force' => true, 'max_lines' => 2]);
$conv2 = xeric_chatter_conversation($T, $db2, 'first_lutheran');
ok('scrollback: a second pass joins the SAME channel, it does not start one',
    $conv2 === $conv, "$conv vs $conv2");

$back2 = xeric_chatter_scrollback($T, $db2, $conv, 50);
$n1 = count($back['lines'] ?? $back);
$n2 = count($back2['lines'] ?? $back2);
ok('scrollback: it grows, it does not replace', $n2 > $n1, "$n1 -> $n2");

// A ROOM NOBODY HAS TALKED IN YET IS NOT AN ERROR. It is a channel with nothing
// in it, and the play screen has to be able to open it and say so.
$db3 = fresh_db('quiet');
ok('scrollback: a room nobody has talked in has no conversation yet',
    xeric_conversation_find($db3, 'the_mill', 'room') === null);

// ---------------------------------------------------------------------------
// 6. The ceiling follows who is standing in the room
// ---------------------------------------------------------------------------

// Every walls-bearing screen in the engine folds the rating down to the lowest
// person present, and chatter is a screen like any other. Asserted on the run's
// own RECEIPT rather than by grepping the prompt: what a rating does to an
// assembly is prompt.php's business and this fixture is tame enough that adult
// and sfw produce byte-identical text, so a grep here would pass while proving
// nothing at all.
$TM = $T;
foreach ($TM['cast']['characters'] as &$c) {
    if (($c['handle'] ?? '') === 'janelle') { $c['age'] = 15; break; }
}
unset($c);
$dbM   = fresh_db('ceiling');
$calls = [];
$rA = xeric_chatter_run($T, $dbM, 'first_lutheran', stub_chat($calls),
    ['now' => $SUN, 'force' => true, 'max_lines' => 1, 'effective_rating' => 'adult']);
ok('ceiling: a room of adults runs at the ceiling it was given',
    (string)$rA['rating'] === 'adult', (string)$rA['rating']);

$dbM2  = fresh_db('ceiling-minor');
$calls = [];
$rM = xeric_chatter_run($TM, $dbM2, 'first_lutheran', stub_chat($calls),
    ['now' => $SUN, 'force' => true, 'max_lines' => 1, 'effective_rating' => 'adult']);
ok('ceiling: one fifteen-year-old in the room folds it for everybody',
    (string)$rM['rating'] === xeric_viewer_rating('adult', xeric_viewer($TM, ['handle' => 'janelle'])),
    (string)$rM['rating']);
ok('ceiling: and that is lower than what was asked for',
    (string)$rM['rating'] !== (string)$rA['rating']);

// ---------------------------------------------------------------------------
// 7. The age floor — the same belt the scene and the floor wear
// ---------------------------------------------------------------------------

// Sat 10:00 at the hardware store: harlan (66) and theo (12). An idle line
// that turns sexual is dropped with a child standing in the room, whoever
// spoke it — this was the one line-writing path of the three without the belt.
$dbA  = fresh_db('age-floor');
$whys = [];
$lewd = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) {
    return $tag === 'chat' ? 'We made love till three, if you want the details.' : ['memories' => []];
}];
$rF = xeric_chatter_run($T, $dbA, 'beck_hardware', $lewd,
    ['now' => $SAT, 'force' => true, 'max_lines' => 2,
     'on_why' => function ($k, $h, $d) use (&$whys) { $whys[] = $k; }]);
ok('age floor: the line is dropped with a child in the room',
    (int)$rF['said'] === 0 && in_array('age', $whys, true),
    'said=' . (int)$rF['said'] . ' whys=' . implode(',', $whys));
$convA = xeric_conversation_find($dbA, 'beck_hardware', 'room');
ok('age floor: nothing reached the channel',
    $convA === null || xeric_messages_recent($dbA, (int)$convA['id'], 10) === []);

// ---------------------------------------------------------------------------
// 8. The opener tic — a head drawn twice in one fire is coached off its opener
// ---------------------------------------------------------------------------

// Two people in the room means strict alternation (the draw excludes the last
// speaker), so line three is line one's speaker again — and their second call
// must carry the ban naming their first opener. A user line first makes the
// room warm (eventful) so three lines are guaranteed under max_lines.
$dbT  = fresh_db('tic');
$convT = xeric_chatter_conversation($T, $dbT, 'beck_hardware');
xeric_message_append($dbT, $convT, 'user', null, 'howdy.', (int)$SAT['epoch']);
$callsT = [];
$rT = xeric_chatter_run($T, $dbT, 'beck_hardware', stub_chat($callsT),
    ['now' => $SAT, 'force' => true, 'max_lines' => 3]);
$volT = function (int $i) use (&$callsT): string {
    $last = '';
    foreach ($callsT[$i]['msgs'] as $m) if ($m['role'] === 'user') $last = (string)$m['content'];
    return $last;
};
ok('tic: three lines landed in a two-person room', (int)$rT['said'] === 3,
   'said=' . (int)$rT['said']);
ok('tic: the first two calls carry no ban',
    !str_contains($volT(0), 'do not start with those words')
    && !str_contains($volT(1), 'do not start with those words'));
ok('tic: the third call names the first line\'s opener and retires it',
    str_contains($volT(2), 'started "line 1, about"')
    && str_contains($volT(2), 'do not start with those words'));

// ---------------------------------------------------------------------------
// 9. More than one person at the centre — user rows carry who typed them
// ---------------------------------------------------------------------------

$dbP  = fresh_db('players');
$neil = xeric_player_add($dbP, $T, 'Neil');
$convP = xeric_chatter_conversation($T, $dbP, 'bluebird');
xeric_message_append($dbP, $convP, 'user', null, 'first person talking.', 0);
xeric_message_append($dbP, $convP, 'user', xeric_player_key('@you', $neil), 'second person talking.', 0);
$backP = xeric_chatter_scrollback($T, $dbP, $convP, 10);
ok('players: the bare user row is the first person\'s — the no-migration rule',
    $backP[0]['role'] === 'user' && $backP[0]['name'] === 'Walt', $backP[0]['name']);
ok('players: a marked user row is labeled with that person\'s own name',
    $backP[1]['role'] === 'user' && $backP[1]['name'] === 'Neil', $backP[1]['name']);

// ---------------------------------------------------------------------------
// 10. OUT is out of the room — an unentered character is not present, so the
// beat can never draw them and they never say a word (fail closed; the same
// door xeric_world_who_is_where holds for the sweeps and the map).
// ---------------------------------------------------------------------------

$TOut = $T;
foreach ($TOut['cast']['characters'] as $iC => $cC) {
    if ((string)$cC['handle'] === 'ruth') $TOut['cast']['characters'][$iC]['out'] = true;
}
$dbOut  = fresh_db('out');
$SUNout = xeric_world_now($TOut, ep('2026-08-02 10:00'));
$hereO  = xeric_chatter_present($TOut, $dbOut, 'first_lutheran', $SUNout);
ok('out: an unentered character is simply not standing in the room',
    !in_array('ruth', $hereO, true) && $hereO !== [], implode(',', $hereO));

// ---------------------------------------------------------------------------
// 11. The prompt names who is ACTUALLY standing there — never the absent owner
// ---------------------------------------------------------------------------

// The system used to hard-code the template owner as the person present, so
// with a guest at the counter the cast greeted Walt to Neil's face — and the
// name guard shielded Walt's name while Neil's went unguarded. The caller now
// threads the presence data through (`players_here`), and the owner remains
// the fallback so every pre-bodies caller stays byte-identical.
$sysOf = function (array $calls, int $i): string {
    foreach ($calls[$i]['msgs'] as $m) if ($m['role'] === 'system') return (string)$m['content'];
    return '';
};

$dbN  = fresh_db('names-owner');
$callsN = [];
xeric_chatter_run($T, $dbN, 'bluebird', stub_chat($callsN),
    ['now' => $SAT, 'force' => true, 'user_here' => true, 'max_lines' => 1]);
ok('names: no players_here still says the owner is in the room — the fallback',
    str_contains($sysOf($callsN, 0), 'Walt is here too, in the room with you'));

$dbN  = fresh_db('names-guest');
$callsN = [];
xeric_chatter_run($T, $dbN, 'bluebird', stub_chat($callsN),
    ['now' => $SAT, 'force' => true, 'user_here' => true, 'max_lines' => 1,
     'players_here' => ['Neil']]);
ok('names: the guest standing there is the one the room is told about',
    str_contains($sysOf($callsN, 0), 'Neil is here too, in the room with you'));
ok('names: and the absent owner is not claimed to be in it',
    !str_contains($sysOf($callsN, 0), 'Walt is here too'));

$dbN  = fresh_db('names-both');
$callsN = [];
xeric_chatter_run($T, $dbN, 'bluebird', stub_chat($callsN),
    ['now' => $SAT, 'force' => true, 'user_here' => true, 'max_lines' => 1,
     'players_here' => ['Walt', 'Neil']]);
ok('names: two bodies present are named together, off the presence data',
    str_contains($sysOf($callsN, 0), 'Walt and Neil are here too, in the room with you'));

// The clean guard follows the same list: a line that starts writing the
// PRESENT person's reply is cut at their name, whoever they are.
$dbN = fresh_db('names-guard');
$impersonate = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) {
    return $tag === 'chat' ? "Fine by me.\nNeil: sounds good" : ['memories' => []];
}];
$rN = xeric_chatter_run($T, $dbN, 'bluebird', $impersonate,
    ['now' => $SAT, 'force' => true, 'user_here' => true, 'max_lines' => 1,
     'players_here' => ['Neil']]);
ok('names: the guard cuts a line put in the present guest\'s mouth',
    (int)$rN['said'] === 1 && !str_contains((string)$rN['lines'][0]['text'], 'sounds good'),
    json_encode($rN['lines']));

// ---------------------------------------------------------------------------

foreach ($DBFILES as $f) foreach ([$f, $f . '-wal', $f . '-shm'] as $g) @unlink($g);

echo "\n" . ($FAILED === 0 ? "all chatter tests passed\n" : "$FAILED FAILED\n");
exit($FAILED === 0 ? 0 : 1);
