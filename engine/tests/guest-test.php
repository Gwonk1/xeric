<?php
/**
 * guest-test.php — somebody else came.
 *
 * The claim under all of it: a person who joins is NOT a stranger, because
 * nobody arrives at a xeric off the street. They came through somebody's door,
 * and the town should take them the way towns take a friend of a friend —
 * warily polite, on borrowed credit, with the person who brought them on the
 * hook for how it goes.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/guest.php';

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
    $p = sys_get_temp_dir() . '/xeric-guest-' . $tag . '-' . getmypid() . '.db';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $DBS[] = $p;
    $d = xeric_state_open($p);
    xeric_state_migrate($d);
    return $d;
}

$T = [
    'meta' => ['name' => 'Milldale'],
    'setting' => ['locale' => 'a river town of nine hundred'],
    'user' => ['name' => 'Neil', 'pronouns' => 'he/him'],
    'cast' => ['characters' => [
        ['handle' => 'ruth',   'display_name' => 'Ruth Amberg', 'one_line' => 'runs the diner'],
        ['handle' => 'harlan', 'display_name' => 'Harlan Beck', 'one_line' => 'the hardware store'],
        ['handle' => 'dot',    'display_name' => 'Dot Feeney',  'one_line' => 'counter, grill'],
    ]],
];

/** A world with somebody already invited, and Ruth fond of the person at the centre. */
function withGuest(string $tag, string $way = 'guest'): array
{
    global $T;
    $db = fresh($tag);
    $id = xeric_player_add($db, $T, 'Corey', 'she/her');
    xeric_guest_arrive($db, $T, $id, XERIC_PLAYER_FIRST, $way);
    return [$db, $id];
}

// ---------------------------------------------------------------------------
// 1. NOBODY ARRIVES OFF THE STREET.
// ---------------------------------------------------------------------------

echo "\n# who they are when they walk in\n";

[$db, $id] = withGuest('arrive');
$g = xeric_guest($db, $id);
ok('guest: they arrived through somebody\'s door, and the row says whose',
    $g !== null && $g['via'] === XERIC_PLAYER_FIRST && $g['way'] === 'guest');
ok('guest: the first person did not arrive — the world is theirs',
    xeric_guest($db, XERIC_PLAYER_FIRST) === null);

$threw = '';
try { xeric_guest_arrive($db, $T, XERIC_PLAYER_FIRST); } catch (Throwable $e) { $threw = $e->getMessage(); }
ok('guest: and they cannot be made to have arrived', str_contains($threw, 'world is theirs'));

$threw2 = '';
try { xeric_guest_arrive($db, $T, 7); } catch (Throwable $e) { $threw2 = $e->getMessage(); }
ok('guest: nobody who is not here can arrive', str_contains($threw2, 'nobody by that number'));

ok('guest: a way nobody has heard of is an ordinary guest, not an error',
    xeric_guest_arrive($db, $T, $id, 1, 'astronaut')['way'] === 'guest');

// ---------------------------------------------------------------------------
// 2. BORROWED STANDING. Ruth is warm to Neil, so Ruth is POLITE to Neil's
// friend — not warm, and only a fraction, and marked as borrowed.
// ---------------------------------------------------------------------------

echo "\n# what they arrive holding\n";

[$vDb, $vId] = withGuest('vouch');
xeric_trust_earn($vDb, 'ruth', 6, null, null, XERIC_PLAYER_FIRST);      // Ruth likes Neil
xeric_trust_earn($vDb, 'dot', -6, null, null, XERIC_PLAYER_FIRST);      // Dot does not

ok('vouch: before anybody vouches, the town thinks nothing of them',
    xeric_trust_of($vDb, 'ruth', null, $vId) === 0);

$moved = xeric_guest_vouch($vDb, $T, $vId);
ok('vouch: somebody\'s friend gets a thinner version of what that person has',
    xeric_trust_of($vDb, 'ruth', null, $vId) === 2 && $moved === 2);
ok('vouch: and it is a fraction, not a copy — you do not inherit a friendship',
    xeric_trust_of($vDb, 'ruth', null, $vId) < xeric_trust_of($vDb, 'ruth', null, XERIC_PLAYER_FIRST));
// The honest half of vouching that nobody enjoys.
ok('vouch: somebody the town dislikes lends their dislike too',
    xeric_trust_of($vDb, 'dot', null, $vId) === -2);
ok('vouch: a character with no opinion either way lends nothing',
    xeric_trust_of($vDb, 'harlan', null, $vId) === 0);
ok('vouch: and the person who brought them is untouched by the lending',
    xeric_trust_of($vDb, 'ruth', null, XERIC_PLAYER_FIRST) === 6);

// Leaving and coming back twice must not accumulate somebody's reputation.
ok('vouch: it happens once, however many times they come and go',
    xeric_guest_vouch($vDb, $T, $vId) === 0
    && xeric_trust_of($vDb, 'ruth', null, $vId) === 2);

// A world where being unknown IS the story gets to have that.
[$sDb, $sId] = withGuest('stranger', 'stranger');
xeric_trust_earn($sDb, 'ruth', 8, null, null, XERIC_PLAYER_FIRST);
ok('vouch: a stranger borrows nothing, because nobody vouched for them',
    xeric_guest_vouch($sDb, $T, $sId) === 0
    && xeric_trust_of($sDb, 'ruth', null, $sId) === 0);

// However loved somebody is, arriving gets you in the door and past no guard.
[$cDb, $cId] = withGuest('cap');
xeric_trust_earn($cDb, 'ruth', XERIC_TRUST_MAX, null, null, XERIC_PLAYER_FIRST);
xeric_guest_vouch($cDb, $T, $cId);
ok('vouch: being vouched for gets you in the door and past nobody\'s guard',
    xeric_trust_of($cDb, 'ruth', null, $cId) === XERIC_GUEST_CAP);

// ---------------------------------------------------------------------------
// 3. AND IT IS SPENDABLE, IN BOTH DIRECTIONS. The only place in this engine
// where one person's behaviour moves another person's standing — deliberately,
// because that is exactly what bringing somebody means.
// ---------------------------------------------------------------------------

echo "\n# what it costs the person who brought them\n";

[$pDb, $pId] = withGuest('splash');
xeric_trust_earn($pDb, 'ruth', 6, null, null, XERIC_PLAYER_FIRST);
xeric_guest_vouch($pDb, $T, $pId);

for ($i = 0; $i < 4; $i++) xeric_guest_splash($pDb, $T, $pId, 'ruth');
ok('splash: a guest who behaves badly cools the room toward them',
    xeric_trust_of($pDb, 'ruth', null, $pId) < XERIC_GUEST_CAP);
ok('splash: and it lands on the person who brought them, which is the point',
    xeric_trust_of($pDb, 'ruth', null, XERIC_PLAYER_FIRST) < 6);
ok('splash: it is warmth, so one bad evening is a wince and not a rupture',
    xeric_trust_of($pDb, 'ruth', null, XERIC_PLAYER_FIRST) >= 4);
ok('splash: nobody else in town hears about it by magic',
    xeric_trust_of($pDb, 'harlan', null, XERIC_PLAYER_FIRST) === 0);
ok('splash: and somebody who never arrived cannot splash anybody',
    (function () use ($pDb, $T) {
        $was = xeric_trust_of($pDb, 'ruth', null, XERIC_PLAYER_FIRST);
        xeric_guest_splash($pDb, $T, 9, 'ruth');
        return xeric_trust_of($pDb, 'ruth', null, XERIC_PLAYER_FIRST) === $was;
    })());

// ---------------------------------------------------------------------------
// 4. LITERARY MODE. Who is this person to this world? Once, and then true.
// ---------------------------------------------------------------------------

echo "\n# written in\n";

$saw = '';
$ep = ['base' => 'stub://', 'stub' => function (string $tag, array $m) use (&$saw) {
    // BOTH messages: the "they are a person who is here" instruction lives in
    // the system half, and asserting only the user half would have quietly
    // stopped covering the sentence that matters most.
    $saw = (string)$m[0]['content'] . "\n" . (string)$m[1]['content'];
    return ['as' => 'Corey is Ruth\'s cousin from Zanesville, down for the month.'];
}];

[$wDb, $wId] = withGuest('written', 'written_in');
$as = xeric_guest_write_in($wDb, $T, $wId, $ep);
ok('written: the world is asked who they are to it', str_contains($as, 'cousin'));
ok('written: and it is told they are real and standing in the room',
    str_contains($saw, 'came with Neil')
    && str_contains($saw, 'they are a person who is here')
    && str_contains($saw, 'not writing a character'));
ok('written: it has to survive being true for months', str_contains($saw, 'for months'));

$again = '';
$ep2 = ['base' => 'stub://', 'stub' => function () use (&$again) {
    $again = 'rolled'; return ['as' => 'Corey is somebody else entirely now.'];
}];
ok('written: it is never re-rolled — a person whose place changes is not a person',
    xeric_guest_write_in($wDb, $T, $wId, $ep2) === $as && $again === '');
ok('written: a model that will not answer leaves a guest, not a broken world',
    xeric_guest_write_in($wDb, $T, (function () use ($wDb, $T) {
        $n = xeric_player_add($wDb, $T, 'Sam');
        xeric_guest_arrive($wDb, $T, $n, 1, 'written_in');
        return $n;
    })(), ['base' => 'stub://', 'stub' => function () { throw new RuntimeException('down'); }]) === '');

// ---------------------------------------------------------------------------
// 5. WHAT A CHARACTER ACTUALLY SEES. A guest the cast cannot see is a person
// being talked past.
// ---------------------------------------------------------------------------

echo "\n# what the room can see\n";

ok('block: a world nobody was invited to says nothing at all',
    xeric_guest_block(fresh('alone'), $T) === '');

$blk = xeric_guest_block($vDb, $T);
ok('block: the room is told somebody is here and whose friend they are',
    str_contains($blk, 'Corey') && str_contains($blk, 'with Neil')
    && str_contains($blk, 'not a stranger'));
ok('block: and told plainly not to pretend to remember them',
    str_contains($blk, 'do not pretend to remember them'));
ok('block: no number, no arrival time, no count — it survives the prefix cache',
    !preg_match('/\d/', $blk), $blk);

ok('block: a stranger is a stranger, and the room is told nobody vouched',
    str_contains(xeric_guest_block($sDb, $T), 'Nobody vouched'));
ok('block: somebody written in has a place in the world rather than a hole',
    str_contains(xeric_guest_block($wDb, $T), 'cousin from Zanesville'));
ok('block: and a person reading it about themselves is not told they are there',
    !str_contains(xeric_guest_block($vDb, $T, $vId), 'Corey is here'));

foreach ($DBS as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
