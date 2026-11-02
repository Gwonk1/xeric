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
require_once dirname(__DIR__) . '/scene.php';     // travel.php, the bodies, and the look brief

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

// ---------------------------------------------------------------------------
// 6. WARMTH GOES TO WHOEVER EARNED IT. Ordinary contact was the one path that
// could move trust without a promise, and it was charging every reply to the
// first person however many people were talking.
// ---------------------------------------------------------------------------

echo "\n# warmth goes to whoever earned it\n";

require_once dirname(__DIR__) . '/learn.php';

/** Fold every crumb that is waiting, the way the engine does between skips. */
$fold = function (PDO $db): void {
    $rows = xeric_signals_unprocessed($db, 500);
    xeric_learn_tally_apply($db, $rows);
    xeric_signals_mark($db, array_map(fn($r) => (int)$r['id'], $rows));
};

$w = fresh('warm');
xeric_player_add($w, $Tp, 'Corey');
for ($i = 0; $i < 8; $i++) {
    xeric_signal_add($w, 'reply', ['handle' => 'ruth', 'subject' => 'chat',
        'n' => 40, 'lag' => 60, 'p' => 2, 'world_epoch' => 100 + $i]);
}
$fold($w);
ok('warm: eight replies from a guest warm Ruth toward the GUEST',
    xeric_trust_of($w, 'ruth', null, 2) > 0, (string)xeric_trust_of($w, 'ruth', null, 2));
ok('warm: and toward the owner, who said nothing, not at all',
    xeric_trust_of($w, 'ruth', null, 1) === 0);

// A crumb with no person on it is the first player's, so every signal already
// on disk keeps meaning what it meant.
$old = fresh('warm-old');
for ($i = 0; $i < 8; $i++) {
    xeric_signal_add($old, 'reply', ['handle' => 'ruth', 'subject' => 'chat',
        'n' => 40, 'lag' => 60, 'world_epoch' => 100 + $i]);
}
$fold($old);
ok('warm: a crumb written before any of this is still the first person\'s',
    xeric_trust_of($old, 'ruth', null, 1) > 0);

// AND A SILENCE WITH TWO PEOPLE IN THE ROOM IS NOBODY'S. A ping goes out to the
// world rather than to a named person; charging the owner for a message a guest
// also saw and also did not answer would be inventing a fact.
$sil = fresh('silence');
xeric_player_add($sil, $Tp, 'Corey');
for ($i = 0; $i < 8; $i++) {
    xeric_signal_add($sil, 'ignored', ['handle' => 'ruth', 'p' => -1, 'world_epoch' => 100 + $i]);
}
$fold($sil);
ok('silence: nobody\'s standing moves on a silence nobody can be blamed for',
    xeric_trust_of($sil, 'ruth', null, 1) === 0 && xeric_trust_of($sil, 'ruth', null, 2) === 0);
ok('silence: but the world still learns that it was walked past',
    xeric_arc_int($sil, 'ruth', 'learn.ignored', 0) === 8);

// THE MIGRATION THAT DOES NOT NEED RUNNING. A world written before any of this
// existed has no `player` column until it is opened, gains one on the next
// open, and every row in it keeps meaning what it meant — because NULL reads as
// the first person everywhere.
$mig = fresh('migrate');
ok('migrate: the column is there after an ordinary open',
    in_array('player', xeric_state_columns($mig, 'signals'), true));
$mig->exec("INSERT INTO signals (kind, handle, subject, n, lag, note, processed, created_at)
            VALUES ('reply', 'ruth', 'chat', 40, 60, '', 0, 1)");
$row = $mig->query('SELECT player FROM signals')->fetchAll()[0];
ok('migrate: a row written without one carries nothing, not a wrong number',
    $row['player'] === null);
xeric_state_migrate($mig);
ok('migrate: and migrating twice is not an error',
    count(array_filter(xeric_state_columns($mig, 'signals'), fn($c) => $c === 'player')) === 1);

// ---------------------------------------------------------------------------
// A ROSTER NOBODY CAN READ IS A WORLD WITH ONE PERSON IN IT, not a dead worker.
//
// xeric_players() used to end `return $out === [] ? xeric_players($db) : $out;`
// — a self-call that re-read the same bad row, produced the same empty result,
// and recursed until the process ran out of memory. Nothing in the tree writes
// that shape today, so this is about POSTURE: this function is on the
// prompt-assembly path, the engine fails closed everywhere else, and a fatal is
// not closed — it is just dead. The self-call also dropped $t, so even a
// terminating version would have lost the template's own name for the person at
// the centre.
// ---------------------------------------------------------------------------

echo "\n# a roster nobody can read\n";

$T2 = ['user' => ['name' => 'Neil', 'pronouns' => 'he/him']];
foreach ([
    'an id below the first one'  => '[{"id":0,"name":"nobody"}]',
    'rows that are not rows'     => '["just a string", 7]',
    'an object where a list goes'=> '{"nope":true}',
    'nothing at all'             => '',
    'not even json'              => '{oh dear',
] as $what => $bad) {
    $d = fresh('bad-' . substr(md5($what), 0, 6));
    xeric_world_state_set($d, 'players', $bad);
    $got = xeric_players($d, $T2);
    ok("roster: $what leaves one person at the centre",
        count($got) === 1 && isset($got[XERIC_PLAYER_FIRST]), json_encode($got));
    ok("roster: and it is still the one the template named",
        ($got[XERIC_PLAYER_FIRST]['name'] ?? '') === 'Neil', json_encode($got));
}

// ---------------------------------------------------------------------------
// A BODY EACH (FUTURE.md M4). Position per player; the read model's `you` is
// the current player; the others stand in `who` with a flag. And the load-
// bearing half again: a world of one reads EXACTLY what the one-body engine
// read, to the byte.
// ---------------------------------------------------------------------------

echo "\n# a body each\n";

$M   = xeric_template_load(dirname(__DIR__) . '/fixtures/milldale.json');
$MTZ = new DateTimeZone((string)$M['user']['timezone']);
$mAt = fn(string $local) => xeric_world_now($M, (new DateTimeImmutable($local, $MTZ))->getTimestamp());
$m0710 = $mAt('2026-11-02 07:10');     // a Monday: Dot alone at the Bluebird
$enc   = fn($v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// The one-body engine at 7278df7, same fixture, same moment, the player at the
// Bluebird since ten minutes before: the map's JSON and the look's fingerprint
// it produced. A world of one must still produce exactly these.
const ONE_BODY_MAP_SHA1   = '0861118171de854be0f155230ef957b4f0f3ffac';
const ONE_BODY_FPRINT     = '7aa64a6a5a0ef21442b8506caa554147a7606a9e';
const ONE_BODY_BRIEF_SHA1 = 'dce4432a5564df7714c294488e7a25181ebebbe6';

$solo = fresh('body-solo');
xeric_player_move($solo, 'bluebird', (int)$m0710['epoch'] - 600);
$soloMap = xeric_travel_map($M, $solo, $m0710);
ok('solo: the map is byte-identical to the one-body engine\'s',
    sha1($enc($soloMap)) === ONE_BODY_MAP_SHA1, sha1($enc($soloMap)));
ok('solo: no `players` key, no flagged rows, the template\'s name',
    !array_key_exists('players', $soloMap) && $soloMap['you']['name'] === 'Walt'
    && array_filter(array_merge(...array_column($soloMap['places'], 'who')), fn($w) => isset($w['player'])) === []);
$soloBrief = xeric_scene_brief($M, $solo, null, $m0710);
ok('solo: the look brief and its fingerprint are unchanged — no view on disk goes stale',
    // (less `layout`, which M5 added beside everything and is derived from it)
    $soloBrief['fingerprint'] === ONE_BODY_FPRINT
    && sha1($enc(array_diff_key($soloBrief, ['layout' => true]))) === ONE_BODY_BRIEF_SHA1,
    $soloBrief['fingerprint']);
ok('solo: and no new row is written to say where anybody came from',
    xeric_world_state_get($solo, 'player.where_from') === null
    && xeric_world_state_get($solo, 'players') === null);

// -- two of them --------------------------------------------------------------
$two = fresh('body-two');
xeric_player_move($two, 'bluebird', (int)$m0710['epoch'] - 600);
$ann = xeric_player_add($two, $M, 'Ann Price', 'she/her');
ok('two: the second person is player two', $ann === 2);
ok('two: and starts nowhere, while the first stays where they stood',
    xeric_player_where($M, $two, 2) === null && xeric_player_where($M, $two, 1) === 'bluebird');

xeric_player_move($two, 'beck_hardware', (int)$m0710['epoch'] - 300, 2);
ok('two: each body is its own row — the bare key is the first person\'s, `.p2` the second\'s',
    xeric_world_state_get($two, 'player.where') === 'bluebird'
    && xeric_world_state_get($two, 'player.where.p2') === 'beck_hardware'
    && xeric_player_since($two, 1) === (int)$m0710['epoch'] - 600
    && xeric_player_since($two, 2) === (int)$m0710['epoch'] - 300);
xeric_player_move($two, 'first_lutheran', null, 1);
ok('two: moving one moves only that one',
    xeric_player_where($M, $two, 1) === 'first_lutheran' && xeric_player_where($M, $two, 2) === 'beck_hardware');
xeric_player_move($two, 'bluebird', (int)$m0710['epoch'] - 600, 1);

ok('seam: with nobody installed the current player is the first',
    xeric_player_current(null, $two) === 1 && xeric_player_where($M, $two) === 'bluebird');
xeric_player_current(fn(?PDO $d) => 2);
ok('seam: install one and every default read is that person\'s body',
    xeric_player_current(null, $two) === 2 && xeric_player_where($M, $two) === 'beck_hardware'
    && xeric_player_since($two) === (int)$m0710['epoch'] - 300);
xeric_player_current(fn() => 0);
ok('seam: anything below the first person is the first person', xeric_player_current(null, $two) === 1);
xeric_player_current(fn() => throw new RuntimeException('no session'));
ok('seam: and so is a resolver that throws', xeric_player_current(null, $two) === 1);
xeric_player_current(fn() => 1);

// -- the read model -------------------------------------------------------------
xeric_player_move($two, 'bluebird', (int)$m0710['epoch'] - 300, 2);   // Ann walks over to the diner
$byKey = fn(array $map): array => array_column($map['places'], null, 'key');
$m1 = xeric_travel_map($M, $two, $m0710);
$blue1 = $byKey($m1)['bluebird']['who'];
ok('map: the other player stands in `who` with the flag and nothing else',
    in_array(['handle' => 'p2', 'name' => 'Ann Price', 'player' => true], $blue1, true)
    && in_array('dot', array_column($blue1, 'handle'), true));
ok('map: you are never in your own `who`',
    !in_array('p1', array_column($blue1, 'handle'), true));
ok('map: `players` lists every body, you marked',
    array_column($m1['players'], 'handle') === ['p1', 'p2']
    && $m1['players'][0]['you'] === true && $m1['players'][1]['where'] === 'bluebird');
ok('presence: and never in the cast\'s presence — a person is not a part',
    !array_key_exists('p2', xeric_world_who_is_where($M, $m0710, [], $two))
    && !in_array('p2', xeric_world_who_is_at(xeric_world_who_is_where($M, $m0710, [], $two), 'bluebird'), true));

xeric_player_current(fn() => 2);
$m2 = xeric_travel_map($M, $two, $m0710);
ok('map: as Ann, `you` is Ann — her name off the roster, not the template\'s',
    $m2['you']['name'] === 'Ann Price' && $m2['you']['where'] === 'bluebird'
    && $m2['you']['since'] === (int)$m0710['epoch'] - 300);
ok('map: and Walt is the other player in her diner',
    in_array(['handle' => 'p1', 'name' => 'Walt', 'player' => true], $byKey($m2)['bluebird']['who'], true));
xeric_player_current(fn() => 1);

// -- the look -------------------------------------------------------------------
$b2 = xeric_scene_brief($M, $two, null, $m0710);
$pRows = array_values(array_filter($b2['who'], fn($w) => !empty($w['player'])));
ok('look: the other player is in the brief\'s `who`, a name and where they stand, nothing invented',
    count($pRows) === 1 && $pRows[0]['handle'] === 'p2' && $pRows[0]['name'] === 'Ann Price'
    && array_keys($pRows[0]) === ['handle', 'name', 'player', 'bearing']);
ok('look: the cast in the room is untouched by her being there',
    array_values(array_filter($b2['who'], fn($w) => empty($w['player']))) === $soloBrief['who']);
ok('look: a second human in the room is a different room',
    $b2['fingerprint'] !== ONE_BODY_FPRINT && str_contains($b2['prompt'], 'Ann Price'));
xeric_player_move($two, 'beck_hardware', (int)$m0710['epoch'] - 300, 2);
ok('look: and when she goes it is the room it was',
    xeric_scene_brief($M, $two, null, $m0710)['fingerprint'] === ONE_BODY_FPRINT);

// -- the sound: another person standing in it is somebody a doorway hears ------
// The one-body engine's sound of the Bluebird at 07:10 (Dot alone at the
// counter), brief and fingerprint, pinned: a world of one still makes it.
const ONE_BODY_SOUND_FPRINT = 'sound:2794a047d77b9924bfdc352bee5b8c8ab131bc87';
const ONE_BODY_SOUND_SHA1   = '03fc502517dccfcf3e25e44ded0fb58e7a3bd731';
$soloSound = xeric_sound_brief($M, $solo, null, $m0710);
ok('sound: a world of one sounds exactly as it did — the same headcount, the same fingerprint',
    $soloSound['fingerprint'] === ONE_BODY_SOUND_FPRINT && sha1($enc($soloSound)) === ONE_BODY_SOUND_SHA1
    && $soloSound['heard'] === 1, $soloSound['fingerprint']);
ok('sound: with Ann across town, Walt\'s diner is the sound it was', xeric_sound_brief($M, $two, null, $m0710)['fingerprint'] === ONE_BODY_SOUND_FPRINT);
xeric_player_move($two, 'bluebird', (int)$m0710['epoch'] - 300, 2);
$s2 = xeric_sound_brief($M, $two, null, $m0710);
ok('sound: Ann walking in counts in the headcount bucket — one at the counter becomes several, and voiced',
    $s2['heard'] === 2 && $s2['voiced'] === true && $s2['fingerprint'] !== ONE_BODY_SOUND_FPRINT, json_encode([$s2['heard'], $s2['voiced']]));
xeric_player_move($two, 'beck_hardware', (int)$m0710['epoch'] - 300, 2);
ok('sound: and she is never counted in her own hearing — from the hardware store, the cast there is all there is',
    (function () use ($M, $two, $m0710): bool {
        xeric_player_current(fn() => 2);
        $b = xeric_sound_brief($M, $two, null, $m0710);
        xeric_player_current(fn() => 1);
        $solo2 = fresh('sound-solo-hw');
        xeric_player_move($solo2, 'beck_hardware', (int)$m0710['epoch'] - 300);
        return $b['heard'] === xeric_sound_brief($M, $solo2, null, $m0710)['heard'];
    })());

// -- comings and goings ----------------------------------------------------------
// Walt at the diner since 06:00; Ann arrives at 07:05; at 07:10 the doorway
// says so, and when she leaves at 07:12 it says that too.
$cw = fresh('body-comings');
xeric_player_add($cw, $M, 'Ann Price');
xeric_player_move($cw, 'bluebird', $mAt('2026-11-02 06:00')['epoch'], 1);
xeric_player_move($cw, 'bluebird', $mAt('2026-11-02 07:05')['epoch'], 2);
$cm = xeric_travel_comings($M, $cw, $m0710, 'bluebird');
ok('comings: another player walking in is a beat, named off the roster',
    in_array('p2', $cm['arrived'], true) && str_contains($cm['line'], 'Ann Price comes in'), $cm['line']);
xeric_player_move($cw, 'beck_hardware', $mAt('2026-11-02 07:12')['epoch'], 2);
$cm2 = xeric_travel_comings($M, $cw, $mAt('2026-11-02 07:15'), 'bluebird');
ok('comings: and walking out is one too',
    in_array('p2', $cm2['left'], true) && str_contains($cm2['line'], 'Ann Price gets up and goes'), $cm2['line']);
xeric_player_current(fn() => 2);
ok('comings: you are never news to yourself',
    !in_array('p2', xeric_travel_comings($M, $cw, $mAt('2026-11-02 07:15'), 'beck_hardware')['arrived'], true));
xeric_player_current(fn() => 1);

// Two moves inside one stretch: Ann at the diner since 06:00; Walt there too,
// then off to the mill at 07:03 and on to Beck's at 07:07. At 07:10 her doorway
// must say he went — where he was at 07:00 is the diner, not his last stop.
$cw2 = fresh('body-comings-twice');
xeric_player_add($cw2, $M, 'Ann Price');
xeric_player_move($cw2, 'bluebird', $mAt('2026-11-02 06:00')['epoch'], 2);
xeric_player_move($cw2, 'bluebird', $mAt('2026-11-02 06:00')['epoch'], 1);
xeric_player_move($cw2, 'the_mill', $mAt('2026-11-02 07:03')['epoch'], 1);
xeric_player_move($cw2, 'beck_hardware', $mAt('2026-11-02 07:07')['epoch'], 1);
xeric_player_current(fn() => 2);
$cm3 = xeric_travel_comings($M, $cw2, $m0710, 'bluebird');
$cm3b = xeric_travel_comings($M, $cw2, $m0710, 'the_mill');
xeric_player_current(fn() => 1);
ok('comings: a friend who moved twice inside the stretch is read off their move log — Walt left the diner',
    in_array('p1', $cm3['left'], true) && str_contains($cm3['line'], 'Walt gets up and goes'), json_encode($cm3));
ok('comings: and the mill he passed through saw nothing come or go of him at the stretch\'s ends',
    !in_array('p1', $cm3b['arrived'], true) && !in_array('p1', $cm3b['left'], true), json_encode($cm3b));
$mv = json_decode((string)xeric_world_state_get($cw2, 'player.moves'), true);
ok('comings: the log is short, epoch-stamped, and a world of one never writes it',
    is_array($mv) && count($mv) === 3 && $mv[2] === [$mAt('2026-11-02 07:07')['epoch'], 'the_mill', 'beck_hardware']
    && xeric_world_state_get($solo, 'player.moves') === null);
for ($i = 0; $i < 12; $i++) xeric_player_move($cw2, $i % 2 ? 'bluebird' : 'the_mill', $mAt('2026-11-02 08:00')['epoch'] + $i * 60, 1);
ok('comings: trimmed to the last ' . XERIC_PLAYER_MOVES . ' moves',
    count(json_decode((string)xeric_world_state_get($cw2, 'player.moves'), true)) === XERIC_PLAYER_MOVES);

// -- the trip: one body, one clock ------------------------------------------------
$tr = fresh('body-trip');
xeric_clock_begin($tr, $M);
xeric_player_add($tr, $M, 'Ann Price');
xeric_player_move($tr, 'bluebird', null, 1);
xeric_player_move($tr, 'beck_hardware', null, 2);
xeric_player_current(fn() => 2);
$off  = xeric_clock_offset($tr);
$trip = xeric_travel_go($M, $tr, 'the_mill');
xeric_player_current(fn() => 1);
ok('trip: Ann\'s walk moves Ann',
    $trip['ok'] && xeric_player_where($M, $tr, 2) === 'the_mill' && $trip['from'] === 'beck_hardware');
ok('trip: and only Ann — Walt stands where he stood',
    xeric_player_where($M, $tr, 1) === 'bluebird');
ok('trip: but the minutes burn on the one clock everybody lives on',
    $trip['minutes'] > 0 && xeric_clock_offset($tr) - $off === $trip['minutes'] * 60);
xeric_player_current(fn() => 1);
$back = xeric_travel_go($M, $tr, 'the_mill');
ok('trip: walking in on another player names them in the doorway',
    $back['ok'] && ($back['players'][0]['name'] ?? '') === 'Ann Price'
    && str_contains((string)$back['scene'], 'Ann Price'), (string)$back['scene']);
xeric_world_state_set($tr, 'skip:underway', (string)time());
xeric_player_current(fn() => 2);
$mid = xeric_travel_go($M, $tr, 'bluebird');
xeric_player_current(fn() => 1);
ok('trip: a skip in flight stops everybody\'s walk alike', !$mid['ok'] && xeric_player_where($M, $tr, 2) === 'the_mill');

foreach ($DBS as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
