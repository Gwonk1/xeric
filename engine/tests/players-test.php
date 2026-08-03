<?php
/**
 * players-test.php — the person at the centre, as a row.
 *
 * The load-bearing assertion in this file is not "a second player works". It is
 * that a FIRST player is byte-identical to what a xeric wrote yesterday. Every
 * world on anybody's disk keeps working with no migration, or this groundwork
 * has made things worse rather than better — a migration you do not have to run
 * cannot go wrong at three in the morning.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/players.php';
require_once dirname(__DIR__) . '/trust.php';
require_once dirname(__DIR__) . '/work.php';
require_once dirname(__DIR__) . '/table.php';

$FAILED = 0;
function ok(string $what, bool $cond, string $extra = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $what\n"; return; }
    $FAILED++;
    echo "FAIL - $what" . ($extra !== '' ? " ($extra)" : '') . "\n";
}

$DBS = [];
function fresh(string $tag): PDO
{
    global $DBS;
    $p = sys_get_temp_dir() . '/xeric-players-' . $tag . '-' . getmypid() . '.db';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $DBS[] = $p;
    $d = xeric_state_open($p);
    xeric_state_migrate($d);
    return $d;
}

$T = [
    'user' => ['name' => 'Neil', 'pronouns' => 'he/him', 'timezone' => 'UTC',
               'occupation' => ['title' => 'the early shift', 'shifts' => [
                   ['days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                    'from' => '08:00', 'to' => '16:00', 'pay' => 2]]]],
    'economies' => [['key' => 'thursday_pot', 'counter' => 'per-character']],
    'forge' => ['armed' => ['economies']],
    'cast' => ['characters' => [
        ['handle' => 'harlan', 'display_name' => 'Harlan Beck', 'one_line' => 'proud'],
        ['handle' => 'ruth',   'display_name' => 'Ruth Amberg', 'one_line' => 'careful'],
        ['handle' => 'dot',    'display_name' => 'Dot Feeney',  'one_line' => 'reckless'],
    ]],
];

// ---------------------------------------------------------------------------
// 1. THE BARE KEY BELONGS TO WHOEVER WAS THERE FIRST.
//
// This is the whole design. Every world that exists right now is already
// correct under the new scheme, because the first person's keys ARE the keys
// that are already on disk.
// ---------------------------------------------------------------------------

echo "\n# the bare key belongs to whoever was there first\n";

ok('key: the first person keeps the key every database already has',
    xeric_player_key('work.wages') === 'work.wages'
    && xeric_player_key('work.wages', XERIC_PLAYER_FIRST) === 'work.wages');
ok('key: and everybody after them is suffixed',
    xeric_player_key('work.wages', 2) === 'work.wages.p2'
    && xeric_player_key('trust', 3) === 'trust.p3');
ok('key: a nonsense player id is the first one rather than a stray row',
    xeric_player_key('work.wages', 0) === 'work.wages'
    && xeric_player_key('work.wages', -4) === 'work.wages');

// The same rule, through trust, where it already existed for the town.
ok('trust: the bare row is still what they think of the person at the centre',
    xeric_trust_key() === 'trust');
ok('trust: somebody in the town has no player in the key at all',
    xeric_trust_key('harlan') === 'trust.of.harlan'
    && xeric_trust_key('harlan', 2) === 'trust.of.harlan');
ok('trust: and a second person at the centre is their own row',
    xeric_trust_key(null, 2) === 'trust.p2');

// ---------------------------------------------------------------------------
// 2. NOTHING MOVED. A single-player world writes exactly what it wrote before.
// ---------------------------------------------------------------------------

echo "\n# nothing moved\n";

$a = fresh('one');
xeric_trust_contact($a, 'ruth', 4);
xeric_trust_earn($a, 'ruth', 2);
xeric_money_set($a, 'real');
xeric_shift_walk($a, $T, (new DateTimeImmutable('2026-08-03 06:00', new DateTimeZone('UTC')))->getTimestamp(),
                        (new DateTimeImmutable('2026-08-03 20:00', new DateTimeZone('UTC')))->getTimestamp());

$rows = [];
foreach ($a->query('SELECT handle, key FROM arcs ORDER BY handle, key') as $r) {
    $rows[] = $r['handle'] . '/' . $r['key'];
}
$ws = [];
foreach ($a->query("SELECT key FROM world_state ORDER BY key") as $r) $ws[] = $r['key'];

ok('same: not one arc key gained a suffix',
    !in_array('ruth/trust.p1', $rows, true) && in_array('ruth/trust', $rows, true),
    implode(' ', $rows));
ok('same: and neither did the warmth row',
    in_array('ruth/trust.warmth', $rows, true) && !in_array('ruth/trust.warmth.p1', $rows, true));
ok('same: nor any of the work rows',
    in_array('work.missed', $ws, true) && !in_array('work.missed.p1', $ws, true),
    implode(' ', $ws));
ok('same: and a world nobody invited anybody to has no roster row at all',
    !in_array('players', $ws, true));

// ---------------------------------------------------------------------------
// 3. THE ROSTER. Implicit until somebody actually joins, because until then the
// template IS the record and a roster row would be a duplicate of it.
// ---------------------------------------------------------------------------

echo "\n# the roster\n";

$b = fresh('roster');
$one = xeric_players($b, $T);
ok('roster: a world has one person at the centre without being told so',
    count($one) === 1 && $one[XERIC_PLAYER_FIRST]['name'] === 'Neil');
ok('roster: and that person is implicit — the template is the record',
    $one[XERIC_PLAYER_FIRST]['implicit'] === true);

$id = xeric_player_add($b, $T, 'Corey', 'she/her');
ok('roster: somebody joining gets the next seat', $id === 2);
$two = xeric_players($b, $T);
ok('roster: and both of them are on it now', count($two) === 2
    && $two[1]['name'] === 'Neil' && $two[2]['name'] === 'Corey');
ok('roster: writing it down makes the first person explicit, not a second entry',
    $two[1]['implicit'] === false && count($two) === 2);
ok('roster: names come back for a prompt or a page',
    xeric_player_name($b, 2, $T) === 'Corey' && xeric_player_name($b, 9, $T) === 'you');

$threw = '';
try { xeric_player_add($b, $T, '   '); } catch (Throwable $e) { $threw = $e->getMessage(); }
ok('roster: somebody joining needs a name', str_contains($threw, 'needs a name'));

$full = fresh('full');
for ($i = 2; $i <= XERIC_PLAYERS_MAX; $i++) xeric_player_add($full, $T, 'p' . $i);
$threw2 = '';
try { xeric_player_add($full, $T, 'one too many'); } catch (Throwable $e) { $threw2 = $e->getMessage(); }
ok('roster: past a point it is a lobby rather than a world', str_contains($threw2, 'lobby'));

// SOMEBODY LEAVING IS NOT SOMEBODY BEING DELETED. Ruth's opinion of a person
// who stopped coming round is a real thing she still holds.
xeric_trust_earn($b, 'ruth', 5, null, null, 2);
ok('leave: their history stays behind them',
    xeric_player_drop($b, $T, 2) === true
    && xeric_trust_of($b, 'ruth', null, 2) === 5);
// A NUMBER IS NEVER HANDED TO ANYBODY ELSE. Every row in this engine is keyed
// by it, so recycling one gives the next person through the door a stranger's
// standing, wages and debts — in a house that is not hypothetical: one person
// leaves, another is invited an hour later.
$reuse = xeric_player_add($b, $T, 'Sam');
ok('leave: and the number they had is never given to anybody else',
    $reuse === 3 && xeric_trust_of($b, 'ruth', null, $reuse) === 0);

ok('leave: and the world is not the first person\'s to leave',
    xeric_player_drop($b, $T, XERIC_PLAYER_FIRST) === false);
ok('leave: dropping somebody who was never here changes nothing',
    xeric_player_drop($b, $T, 5) === false);

// ---------------------------------------------------------------------------
// 4. TWO PEOPLE, AND WHAT IS THEIRS ALONE.
// ---------------------------------------------------------------------------

echo "\n# two people at the centre\n";

$c = fresh('two');
xeric_player_add($c, $T, 'Corey');

ok('two: what Ruth thinks of one of them is not what she thinks of the other',
    xeric_trust_earn($c, 'ruth', 4, null, null, 1) === 4
    && xeric_trust_of($c, 'ruth', null, 2) === 0);
ok('two: and ordinary contact with one does not warm her to the other',
    xeric_trust_contact($c, 'ruth', 4, null, 2) === 1
    && xeric_trust_of($c, 'ruth', null, 1) === 4);

xeric_money_set($c, 'real');
$mon = (new DateTimeImmutable('2026-08-03 06:00', new DateTimeZone('UTC')))->getTimestamp();
xeric_shift_walk($c, $T, $mon, $mon + 14 * 3600, null, 1);          // the first one skips it
for ($e = $mon; $e < $mon + 14 * 3600; $e += 3600) {                // the second works it
    xeric_shift_walk($c, $T, $e, $e + 3600, null, 2);
}
ok('two: one of them missing a shift is not the other one missing it',
    xeric_work_state($c, 1)['missed'] === 1 && xeric_work_state($c, 2)['missed'] === 0);
ok('two: and the wages are two purses, not one',
    xeric_work_state($c, 1)['wages'] === 0 && xeric_work_state($c, 2)['wages'] === 2);

ok('two: the card table gives them a seat each, and neither is handle-shaped',
    xeric_table_seat(1) === XERIC_TABLE_YOU && xeric_table_seat(2) !== xeric_table_seat(1)
    && !preg_match('/^[a-z0-9_]+$/', xeric_table_seat(2)));
ok('two: and each plays from their own purse',
    xeric_table_purse($c, 1) === 0 && xeric_table_purse($c, 2) === 2);

// The town is the town. Two people standing in the same world at the same hour
// had better agree about what time it is, what the weather is, and whether the
// Thursday game is on — so those are NOT per-person and must not become so.
ok('two: the money dial is a way of PLAYING and belongs to the world, not a person',
    xeric_money_dial($c, $T) === 'real');
ok('two: and what Ruth thinks of Harlan has nobody at the centre in it',
    xeric_trust_key('harlan', 1) === xeric_trust_key('harlan', 2));

// ---------------------------------------------------------------------------
// 5. A TURN BELONGS TO WHOEVER TOOK IT.
//
// Shipping guests turned an old assumption into a live bug: expectations were
// written when there was one person at the centre and the definite article was
// safe, so a guest breaking their word charged the OWNER. Same sentence as the
// cast-to-cast fix, one row further out.
// ---------------------------------------------------------------------------

echo "\n# a turn belongs to whoever took it\n";

require_once dirname(__DIR__) . '/constructs.php';

// array_merge, not `+`: the union operator keeps the LEFT side's keys, so a
// `forge` override written with `+` over a template that already has one is
// silently ignored — which is how the first version of this section tested a
// world where expectations were never armed.
$Tp = array_merge($T, ['forge' => ['armed' => ['expectations']], 'meta' => ['name' => 'Milldale']]);
$d = fresh('turns');
xeric_player_add($d, $Tp, 'Corey');

$thu = ['epoch' => (new DateTimeImmutable('2026-08-03 09:00', new DateTimeZone('UTC')))->getTimestamp()];
$made = xeric_expect_form($Tp, $d, 'ruth',
    ['quote' => 'I will bring the truck round Thursday', 'what' => 'the truck', 'when' => 'Thursday'],
    $thu, null, null, 2);
ok('turn: a promise made by a guest forms', $made !== null);
ok('turn: and the row knows which of them said it',
    (int)(xeric_expects_for($d, 'ruth')[0]['p'] ?? 1) === 2);

// The miss must land on the person who actually promised.
// The fuse is xeric_constructs_tick, not the block — the block renders what is
// already known, the tick is what makes a miss happen.
$late = ['epoch' => $thu['epoch'] + 8 * 86400];
xeric_constructs_tick($Tp, $d, $late);
ok('turn: a guest breaking their word costs the GUEST their standing',
    xeric_trust_of($d, 'ruth', null, 2) < 0);
ok('turn: and costs the owner, who did nothing, exactly nothing',
    xeric_trust_of($d, 'ruth', null, 1) === 0);

// And explaining yourself repairs your own misses and nobody else's.
ok('turn: the owner cannot apologise for a promise the guest broke',
    xeric_expect_repair($Tp, $d, 'ruth', $late, null, 1) === null);
ok('turn: the person who broke it can',
    xeric_expect_repair($Tp, $d, 'ruth', $late, null, 2) !== null
    && xeric_trust_of($d, 'ruth', null, 2) === 0);

// A single-player world writes no player marker at all, so every expectation
// already on disk keeps meaning what it meant.
$solo = fresh('solo-turn');
xeric_expect_form($Tp, $solo, 'ruth',
    ['quote' => 'I will be there Thursday', 'what' => 'the thing', 'when' => 'Thursday'], $thu);
ok('turn: the first person\'s promises carry no marker, so old rows are unchanged',
    !array_key_exists('p', xeric_expects_for($solo, 'ruth')[0]));

// AND THE CHARACTER KNOWS WHICH OF THEM THEY ARE TALKING TO. The most visible
// half: a prompt that told Ruth she was texting the owner while a guest typed
// would put the wrong name in her mouth on the first line.
require_once dirname(__DIR__) . '/prompt.php';

$np = fresh('name');
xeric_player_add($np, $Tp, 'Corey');
$forOwner = xeric_prompt_rules($Tp, 'ruth', 'sfw');
$forGuest = xeric_prompt_rules($Tp, 'ruth', 'sfw', 'Corey');
ok('name: texting the owner names the owner',
    str_contains(implode(' ', $forOwner), 'texting Neil'));
ok('name: and texting a guest names the guest',
    str_contains(implode(' ', $forGuest), 'texting Corey')
    && !str_contains(implode(' ', $forGuest), 'texting Neil'));

// And the room is told who ELSE is about — which is everybody at the centre
// except the person being spoken to, because they are not "somebody else".
require_once dirname(__DIR__) . '/guest.php';
xeric_guest_arrive($np, $Tp, 2);
ok('name: talking to the guest, the room is told the owner is about too',
    str_contains(xeric_guest_block($np, $Tp, 2), 'Neil')
    && !str_contains(xeric_guest_block($np, $Tp, 2), 'Corey is here'));
ok('name: and talking to the owner, it is told about the guest',
    str_contains(xeric_guest_block($np, $Tp, 1), 'Corey'));

foreach ($DBS as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
