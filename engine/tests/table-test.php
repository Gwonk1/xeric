<?php
/**
 * table-test.php — the sub-game seam.
 *
 * Most of this is one invariant asserted a great many times: CHIPS ARE
 * CONSERVED. A betting loop leaks quietly — an off-by-one in what somebody
 * owes, a call that pays the pot twice, a fold that forgets to leave the money
 * behind — and none of it is visible in a transcript that reads perfectly
 * well. So the test plays thousands of hands and counts.
 */

declare(strict_types=1);

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
    $p = sys_get_temp_dir() . '/xeric-table-' . $tag . '-' . getmypid() . '.db';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $DBS[] = $p;
    $d = xeric_state_open($p);
    xeric_state_migrate($d);
    return $d;
}

$T = ['cast' => ['characters' => [
    ['handle' => 'harlan', 'display_name' => 'Harlan Beck', 'one_line' => 'proud and stubborn',
     'tells' => ['counts his change twice']],
    ['handle' => 'ruth',   'display_name' => 'Ruth Amberg', 'one_line' => 'careful, quiet'],
    ['handle' => 'dot',    'display_name' => 'Dot Feeney',  'one_line' => 'reckless'],
    ['handle' => 'theo',   'display_name' => 'Theo Amberg', 'one_line' => 'anxious, timid'],
]]];
$TABLE = ['key' => 'basement', 'place' => 'basement', 'name' => 'the Thursday game',
          'game' => 'poker', 'nights' => ['thu'], 'buy_in' => 40, 'bet' => 1,
          'economy' => 'thursday_pot'];

// ---------------------------------------------------------------------------
// 1. A TABLE IS A PLACE AND A NIGHT. A poker room you reach from a button is a
// different app bolted on the side; one you walk to on a Thursday is the world.
// ---------------------------------------------------------------------------

echo "\n# a table is a place and a night\n";

$W = ['places' => [
    ['key' => 'basement', 'name' => 'the church basement',
     'table' => ['name' => 'the Thursday game', 'game' => 'poker', 'nights' => ['thu'],
                 'buy_in' => 40, 'bet' => 1, 'economy' => 'thursday_pot']],
    ['key' => 'diner', 'name' => 'the Bluebird'],
]] + $T;

$tables = xeric_tables($W);
ok('table: a place that declares a game has one', isset($tables['basement']));
ok('table: and a place that does not, does not', !isset($tables['diner']) && count($tables) === 1);
ok('table: it knows what it costs and what it pays into',
    $tables['basement']['buy_in'] === 40 && $tables['basement']['economy'] === 'thursday_pot');
ok('table: a world with no tables at all is fine', xeric_tables($T) === []);

ok('night: the Thursday game is on a Thursday',
    xeric_table_tonight($tables['basement'], ['dow' => 'Thursday']));
ok('night: and not on a Tuesday',
    !xeric_table_tonight($tables['basement'], ['dow' => 'Tuesday']));
ok('night: a table that names no nights sits every night',
    xeric_table_tonight(['nights' => []], ['dow' => 'Monday']));
// THE ONE THAT BIT. xeric_world_now() puts `dow` in PHP's `w` form — an INT,
// Sunday is zero — and engine/work.php computes rosters in ISO `N`, Monday is
// one. Read through the wrong convention a table never sits, or sits on the
// wrong night, silently and forever.
ok('night: the engine\'s own numeric dow is what a real caller passes',
    xeric_table_tonight($tables['basement'], ['dow' => 4])
    && !xeric_table_tonight($tables['basement'], ['dow' => 2]));
ok('night: and it is the `w` convention, where Sunday is nothing and not seven',
    xeric_table_tonight(['nights' => ['sun']], ['dow' => 0])
    && !xeric_table_tonight(['nights' => ['sun']], ['dow' => 7 % 7 + 1]));
ok('night: a garbled day sits at no table rather than at every one',
    !xeric_table_tonight($tables['basement'], ['dow' => 'whenever'])
    && !xeric_table_tonight($tables['basement'], []));

// ---------------------------------------------------------------------------
// 2. WHO IS SITTING THERE. Nerve comes off the psyche the forge already wrote —
// nothing is authored per-table, because a poker personality separate from the
// person would be a second character with the same name.
// ---------------------------------------------------------------------------

echo "\n# the nerve of the people in the seats\n";

ok('nerve: a reckless person has more of it than a timid one',
    xeric_table_nerve($T, 'dot')['nerve'] > xeric_table_nerve($T, 'theo')['nerve']);
ok('nerve: and a careful one has less than a proud one',
    xeric_table_nerve($T, 'ruth')['nerve'] < xeric_table_nerve($T, 'harlan')['nerve']);
ok('nerve: it stays inside the scale, however the psyche reads',
    xeric_table_nerve($T, 'dot')['nerve'] <= 4 && xeric_table_nerve($T, 'theo')['nerve'] >= 0);
ok('nerve: a tell the schema already had is a poker tell verbatim',
    xeric_table_nerve($T, 'harlan')['tell'] === 'counts his change twice');
ok('nerve: somebody who is not in the cast still has a nerve rather than a crash',
    is_int(xeric_table_nerve($T, 'nobody')['nerve']));

// ---------------------------------------------------------------------------
// 3. NOBODY BETS WHAT THEY DO NOT HAVE. The guard that lets a table survive a
// night rather than a hand.
// ---------------------------------------------------------------------------

echo "\n# what a seat will and will not do\n";

$aces = [48, 49];   // Ac Ad — rank 12, the best two cards there are
$rags = [0, 5];     // 2c 3d — the worst

ok('move: a broke seat folds, whatever it is holding',
    xeric_table_move($T, 'dot', $aces, [], 0, 0, 1)['do'] === 'fold');
$allin = xeric_table_move($T, 'dot', $rags, [], 50, 10, 1);
ok('move: nobody calls off a whole stack on nothing',
    $allin['do'] === 'fold' && $allin['chips'] === 0);
$m = xeric_table_move($T, 'harlan', $aces, [], 2, 40, 1);
ok('move: and a real hand does not fold to a small bet', $m['do'] !== 'fold');
$bad = xeric_table_move($T, 'theo', $rags, [], 8, 40, 1);
ok('move: a timid seat on rags gets out of the way', $bad['do'] === 'fold');

$leak = 0;
for ($s = 0; $s < 500; $s++) {
    $mv = xeric_table_move($T, 'dot', [$s % 52, ($s * 7 + 3) % 52], [], $s % 5, 20, 1, $s);
    if ($mv['chips'] > 20 || $mv['chips'] < 0) $leak++;
    if (!in_array($mv['do'], ['fold', 'check', 'call', 'bet', 'raise'], true)) $leak++;
}
ok('move: five hundred spots, never a chip more than the stack and never a nonsense move',
    $leak === 0);

// ---------------------------------------------------------------------------
// 4. THE INVARIANT. Chips are conserved. A betting loop leaks quietly and the
// transcript still reads perfectly well, so this is asserted by counting.
// ---------------------------------------------------------------------------

echo "\n# chips are conserved\n";

$bad = 0; $negative = 0; $noWinner = 0; $dealt = 0;
for ($seed = 0; $seed < 800; $seed++) {
    $seats = ['harlan', 'ruth', 'dot', 'theo'];
    $stacks = array_fill_keys($seats, 40);
    $before = array_sum($stacks);
    $r = xeric_table_hand($T, $seats, $stacks, 1, $seed);
    if (array_sum($r['stacks']) !== $before) $bad++;
    foreach ($r['stacks'] as $n) if ($n < 0) $negative++;
    if ($r['pot'] > 0 && $r['winners'] === []) $noWinner++;
    $dealt++;
}
ok('hand: eight hundred hands, and not one chip made or lost', $bad === 0);
ok('hand: nobody ever goes below nothing', $negative === 0);
ok('hand: and a pot always goes to somebody', $noWinner === 0);

// The same over a whole night, where stacks carry between hands and people
// bust out — which is where an off-by-one actually shows up.
$nightBad = 0;
for ($seed = 0; $seed < 200; $seed++) {
    $seats = ['harlan', 'ruth', 'dot'];
    $stacks = array_fill_keys($seats, 40);
    for ($i = 0; $i < 20; $i++) {
        $standing = array_values(array_filter($seats, fn($h) => $stacks[$h] > 0));
        if (count($standing) < 2) break;
        $before = array_sum($stacks);
        $r = xeric_table_hand($T, $standing, $stacks, 1, $seed * 100 + $i);
        $stacks = $r['stacks'];
        if (array_sum($stacks) !== $before) { $nightBad++; break; }
    }
}
ok('night: two hundred nights of twenty hands, and the money is all still there',
    $nightBad === 0);

// The seed is the point: a rewind must put back the hand you actually lost.
$a = xeric_table_hand($T, ['harlan', 'ruth', 'dot'], ['harlan' => 40, 'ruth' => 40, 'dot' => 40], 1, 99);
$b = xeric_table_hand($T, ['harlan', 'ruth', 'dot'], ['harlan' => 40, 'ruth' => 40, 'dot' => 40], 1, 99);
ok('hand: the same seed deals the same hand, so a rewind is honest', $a === $b);
ok('hand: and a different seed is a different hand',
    xeric_table_hand($T, ['harlan', 'ruth', 'dot'],
        ['harlan' => 40, 'ruth' => 40, 'dot' => 40], 1, 100) !== $a);

ok('hand: a table nobody can still play at says so rather than dealing',
    xeric_table_hand($T, ['harlan'], ['harlan' => 40], 1, 1)['winners'] === []);

// A transcript somebody could actually read.
ok('hand: it narrates itself in sentences, not in a data structure',
    str_contains(implode(' ', $a['log']), 'antes')
    && (str_contains(implode(' ', $a['log']), 'folds')
        || str_contains(implode(' ', $a['log']), 'calls')
        || str_contains(implode(' ', $a['log']), 'checks')));

// ---------------------------------------------------------------------------
// 5. AND WHAT A NIGHT DID TO THE WORLD. The one function here that writes.
// ---------------------------------------------------------------------------

echo "\n# what the night did to the world\n";

$db = fresh('settle');
foreach (['harlan', 'ruth', 'dot'] as $h) xeric_arc_init($db, $h, 'economy.thursday_pot', 0);

$res = xeric_table_settle($T, $db, $TABLE, ['harlan', 'ruth', 'dot'], 12, 4242);
ok('settle: a night is a number of hands, and it played them', $res['hands'] > 0);
ok('settle: what everybody won and lost adds to nothing, because it is one pot',
    array_sum($res['net']) === 0, json_encode($res['net']));

// THE LEDGER — the whole reason this table is worth building. `hand_won` is the
// one phrase the prose matcher refuses to credit, because it reduces to "hand"
// and the still-life rule puts hands in every hour. A real table reports it as
// a fact instead of as words to be matched.
$moved = 0;
foreach (['harlan', 'ruth', 'dot'] as $h) {
    if (xeric_ledger_of($db, 'thursday_pot', $h) !== 0) $moved++;
}
ok('settle: the pot lands in the world\'s own ledger, as a fact and not as prose',
    $moved > 0);
ok('settle: and nobody is ever carried below nothing in it',
    xeric_ledger_of($db, 'thursday_pot', 'harlan') >= 0
    && xeric_ledger_of($db, 'thursday_pot', 'ruth') >= 0
    && xeric_ledger_of($db, 'thursday_pot', 'dot') >= 0);

// LOSING TO SOMEBODY IS RELATIONAL. Taking money off a person is a thing that
// happened between two people, not a number going down.
$winner = array_key_first(array_filter($res['net'], fn($n) => $n > 0));
$loser  = array_key_first(array_filter($res['net'], fn($n) => $n < 0));
ok('settle: somebody who lost to somebody thinks a little less of them for it',
    $winner === null || $loser === null
    || xeric_arc_int($db, (string)$loser, 'trust.warmth.of.' . (string)$winner, 0) < 0,
    json_encode($res['net']));
ok('settle: and it is warmth, so one night is a fraction of a point rather than a grudge',
    $winner === null || $loser === null
    || xeric_trust_of($db, (string)$loser, (string)$winner) === 0);

// WHAT SOMEBODY COULD NOT COVER. Clamping a losing night at zero looks
// harmless and is not: a person at nothing who loses five and wins five back is
// up five, and a season of Thursdays quietly mints money. So the shortfall
// becomes a DEBT — a row that knows what it was for, settled by a favour the
// other way, faded if carried long enough. The constructs-beat-counters
// argument arriving exactly where the design predicted it would.
$dbOwe = fresh('owed');
$Towe = $T;
$Towe['forge'] = ['armed' => ['favors']];
foreach (['harlan', 'ruth', 'dot'] as $h) xeric_arc_init($dbOwe, $h, 'economy.thursday_pot', 0);
$owe = xeric_table_settle($Towe, $dbOwe, $TABLE, ['harlan', 'ruth', 'dot'], 12, 4242, null, 1000);

ok('owed: somebody who lost more than they had is short, not rounded to zero',
    $owe['owed'] !== [], json_encode($owe['net']));
$debtor = array_key_first($owe['owed']);
ok('owed: and what they could not cover is a row that knows what it was for',
    xeric_debts_for($dbOwe, (string)$debtor) !== []
    && str_contains((string)xeric_debts_for($dbOwe, (string)$debtor)[0]['what'], 'Thursday game'));
ok('owed: it is owed to the person who actually took it',
    (string)xeric_debts_for($dbOwe, (string)$debtor)[0]['to']
        === (string)array_key_first(array_filter($owe['net'], fn($n) => $n === max($owe['net']))));
ok('owed: and nobody owes themselves anything',
    (string)xeric_debts_for($dbOwe, (string)$debtor)[0]['to'] !== (string)$debtor);

$dbNo = fresh('no-eco');
$res2 = xeric_table_settle($T, $dbNo, ['buy_in' => 20, 'bet' => 1, 'economy' => ''],
    ['harlan', 'ruth'], 5, 7);
ok('settle: a table that pays no ledger still plays, it just does not pay one',
    array_sum($res2['net']) === 0 && xeric_ledger_of($dbNo, 'thursday_pot', 'harlan') === 0);

$threw = '';
try { xeric_table_settle($T, $db, $TABLE, ['harlan'], 1, 1); }
catch (Throwable $e) { $threw = $e->getMessage(); }
ok('settle: one person is not a game', str_contains($threw, 'not a game'));
$threw2 = '';
try { xeric_table_settle($T, $db, $TABLE,
    ['a', 'b', 'c', 'd', 'e', 'f', 'g'], 1, 1); }
catch (Throwable $e) { $threw2 = $e->getMessage(); }
ok('settle: and seven at one table is a tournament', str_contains($threw2, 'tournament'));

ok('settle: the night has a sentence somebody would actually say about it',
    str_contains(xeric_table_say($T, $res), 'up') || str_contains(xeric_table_say($T, $res), 'ahead'),
    xeric_table_say($T, $res));

foreach ($DBS as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
