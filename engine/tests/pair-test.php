<?php
/**
 * pair-test.php — two people, one house, two phones.
 *
 * The security properties here are modest ON PURPOSE and the tests say which
 * ones they are, because the failure mode of a file like this is somebody
 * later assuming it does more than it does. It defends a xeric on somebody's
 * own wifi against the neighbour's phone, the smart telly, a link pasted into
 * a group chat, and somebody's kid. It is not authentication over the internet
 * and nothing here should ever be reused as though it were.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/pair.php';

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
    $p = sys_get_temp_dir() . '/xeric-pair-' . $tag . '-' . getmypid() . '.db';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $DBS[] = $p;
    $d = xeric_state_open($p);
    xeric_state_migrate($d);
    return $d;
}

$T = [
    'meta' => ['name' => 'Milldale'],
    'user' => ['name' => 'Neil'],
    'cast' => ['characters' => [
        ['handle' => 'ruth',   'display_name' => 'Ruth Amberg'],
        ['handle' => 'harlan', 'display_name' => 'Harlan Beck'],
    ]],
];

// ---------------------------------------------------------------------------
// 1. THE CODE ITSELF. Somebody is reading this off a screen across a kitchen
// and typing it with their thumbs.
// ---------------------------------------------------------------------------

echo "\n# the code\n";

$codes = [];
for ($i = 0; $i < 400; $i++) $codes[] = xeric_pair_code();

ok('code: eight characters, every time',
    count(array_filter($codes, fn($c) => strlen($c) === XERIC_PAIR_LEN)) === 400);
ok('code: nothing in it can be misread — no O or 0, no I or 1 or L',
    !preg_match('/[O0I1L]/', implode('', $codes)));
ok('code: and it is not the same code twice', count(array_unique($codes)) === 400);
ok('code: every character is one somebody can find on a phone keyboard',
    strspn(implode('', $codes), XERIC_PAIR_ALPHABET) === strlen(implode('', $codes)));

// STORED HASHED. A stolen world database is not a stack of door keys.
$db = fresh('store');
$made = xeric_pair_new($db, 'Corey');
$stored = (string)xeric_world_state_get($db, 'pair.codes');
ok('code: the plaintext is never written down anywhere',
    !str_contains($stored, $made['code']) && str_contains($stored, xeric_pair_hash($made['code'])));

// ---------------------------------------------------------------------------
// 2. WHAT MAKES IT SAFE ENOUGH FOR A HOUSE: short-lived, single-use, rate
// limited, and narrow.
// ---------------------------------------------------------------------------

echo "\n# short-lived, single-use, rate limited\n";

$a = fresh('claim');
$one = xeric_pair_new($a, 'Corey');
$id = xeric_pair_claim($a, $T, $one['code']);
ok('claim: the code lets somebody in', $id === 2);
ok('claim: and they arrive as somebody\'s guest, not as a stranger',
    (xeric_guest($a, $id)['way'] ?? '') === 'guest'
    && (xeric_guest($a, $id)['via'] ?? 0) === XERIC_PLAYER_FIRST);
ok('claim: they are on the roster under the name they were given',
    xeric_player_name($a, $id, $T) === 'Corey');

// SINGLE USE. A second scan of the same photograph of the same screen gets
// nothing, which is the property that makes a leaked picture harmless.
$twice = '';
try { xeric_pair_claim($a, $T, $one['code']); } catch (Throwable $e) { $twice = $e->getMessage(); }
ok('claim: a code works once — a photograph of the screen is worth nothing after',
    $twice !== '' && count(xeric_players($a, $T)) === 2);

// FAILS THE SAME WAY FOR EVERY WRONG ANSWER. Used, expired and never-existed
// are three different facts and there is no reason to hand any of them over.
$b = fresh('same');
$never = '';
try { xeric_pair_claim($b, $T, 'ZZZZZZZZ'); } catch (Throwable $e) { $never = $e->getMessage(); }
ok('claim: a code that never existed and one already used say the same thing',
    $never === $twice, $never . ' | ' . $twice);

// SHORT-LIVED.
$c = fresh('ttl');
$old = xeric_pair_new($c, 'Corey');
ok('ttl: a code is worth something now', count(xeric_pair_open($c)) === 1);
ok('ttl: and nothing at all five minutes later',
    xeric_pair_open($c, time() + XERIC_PAIR_TTL + 1) === []);
// And the DOOR has to read that same clock, not just the list. Written straight
// into the row with a `dies` in the past, because the alternative is a test that
// waits five minutes or one that proves nothing.
$dead = xeric_pair_code();
xeric_world_state_set($c, 'pair.codes', json_encode([[
    'h' => xeric_pair_hash($dead), 'dies' => time() - 1, 'name' => 'Corey', 'way' => 'guest']]));
$expired = '';
try { xeric_pair_claim($c, $T, $dead); } catch (Throwable $e) { $expired = $e->getMessage(); }
ok('ttl: and the door turns away a code that has run out',
    $expired !== '' && count(xeric_players($c, $T)) === 1);
ok('ttl: saying exactly what it says to a code that never existed',
    $expired === $twice, $expired);

// RATE LIMITED. Forty bits is plenty when you only get a handful of tries.
$d = fresh('lock');
xeric_pair_new($d, 'Corey');
for ($i = 0; $i < XERIC_PAIR_TRIES; $i++) {
    try { xeric_pair_claim($d, $T, 'WRONG' . $i . 'X'); } catch (Throwable $e) { /* expected */ }
}
ok('lock: past a handful of wrong codes the door stops answering',
    xeric_pair_locked($d) > 0);
$while = '';
try { xeric_pair_claim($d, $T, 'ANYTHING'); } catch (Throwable $e) { $while = $e->getMessage(); }
ok('lock: and says so, rather than silently refusing a good code',
    str_contains($while, 'too many wrong codes'));

// A GOOD CODE CLEARS THE COUNT. Somebody who fat-fingers it twice and then
// gets it right has not spent anybody's budget.
$e2 = fresh('reset');
$good = xeric_pair_new($e2, 'Corey');
try { xeric_pair_claim($e2, $T, 'NOPENOPE'); } catch (Throwable $x) { /* expected */ }
xeric_pair_claim($e2, $T, $good['code']);
ok('lock: getting it right clears the wrong guesses behind it',
    (int)(xeric_world_state_get($e2, 'pair.tries') ?? 0) === 0);

// A HOUSE, NOT A CONFERENCE.
$f = fresh('many');
for ($i = 0; $i < XERIC_PAIR_MAX; $i++) xeric_pair_new($f, 'p' . $i);
$full = '';
try { xeric_pair_new($f, 'one more'); } catch (Throwable $x) { $full = $x->getMessage(); }
ok('open: only so many invitations may be out at once', str_contains($full, 'already'));
xeric_pair_clear($f);
ok('open: and the owner can put them all out at once', xeric_pair_open($f) === []);

// ---------------------------------------------------------------------------
// 3. WHAT THEY WALK IN HOLDING. The pairing is also the vouching: they came
// through somebody's door and the town takes them accordingly.
// ---------------------------------------------------------------------------

echo "\n# what they walk in holding\n";

$g = fresh('vouched');
xeric_trust_earn($g, 'ruth', 6, null, null, XERIC_PLAYER_FIRST);
$code = xeric_pair_new($g, '');
$gid = xeric_pair_claim($g, $T, $code['code'], 'Corey');
ok('arrive: joining IS being vouched for — the town already half-knows them',
    xeric_trust_of($g, 'ruth', null, $gid) === 2);
ok('arrive: and the room can see them',
    str_contains(xeric_guest_block($g, $T), 'Corey')
    && str_contains(xeric_guest_block($g, $T), 'not a stranger'));

$h = fresh('named');
$stranger = xeric_pair_new($h, 'Sam', 'stranger');
$sid2 = xeric_pair_claim($h, $T, $stranger['code']);
ok('arrive: an invitation can say how the town should take them',
    (xeric_guest($h, $sid2)['way'] ?? '') === 'stranger');
ok('arrive: and a name on the invitation stands in when they do not give one',
    xeric_player_name($h, $sid2, $T) === 'Sam');

// TWO PHONES, ONE SCREEN, ONE INSTANT. The burn and the join are one
// transaction, so a race cannot let both in on one code.
$i2 = fresh('race');
$one2 = xeric_pair_new($i2, 'Corey');
$got = 0;
for ($k = 0; $k < 3; $k++) {
    try { xeric_pair_claim($i2, $T, $one2['code']); $got++; } catch (Throwable $x) { /* expected */ }
}
ok('race: one code lets exactly one person in, however many try it',
    $got === 1 && count(xeric_players($i2, $T)) === 2);

foreach ($DBS as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
