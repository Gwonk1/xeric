<?php
/**
 * Xeric — pairing. Two people, one house, one xeric, two phones.
 *
 * ── THE THREAT MODEL, WRITTEN DOWN BECAUSE IT DECIDES EVERYTHING ──────────
 *
 * This is a program on somebody's own machine, answering their own wifi, so
 * the thing being defended against is NOT a determined attacker with a
 * botnet. It is:
 *
 *   - the neighbour's phone that is still associated with this wifi,
 *   - the smart telly and whatever else is on the LAN with an HTTP client,
 *   - a link that gets pasted into a group chat by accident,
 *   - and somebody's kid, who is the most likely of the four.
 *
 * Against that, the right answer is not a password anybody has to invent,
 * remember, or type on a phone keyboard. It is a code that is SHORT-LIVED,
 * SINGLE-USE, and has to be SEEN — which is what a QR code on a screen in the
 * same room already is. Being in the room is the authentication. Everything
 * here is in service of making that true and keeping it true for five minutes.
 *
 *   SHORT-LIVED   a code is dead in XERIC_PAIR_TTL. A link pasted into a group
 *                 chat is worthless by the time anybody reads it.
 *   SINGLE-USE    the first person through burns it. A second scan of the same
 *                 photograph of the same screen gets nothing.
 *   RATE-LIMITED  wrong guesses are counted per world, and past a handful the
 *                 door simply stops answering for a while. Forty bits of code
 *                 is plenty when you only get ten tries a minute.
 *   NARROW        a code lets somebody PLAY a world. It never makes them its
 *                 owner: no shutting the server down, no editing the world, no
 *                 deleting anything. The person whose machine it is stays the
 *                 person whose machine it is.
 *
 * WHAT THIS IS NOT: it is not authentication over the internet, and nothing
 * here should ever be reused as though it were. A xeric on a public address is
 * a different problem with a different answer, and this file would be the
 * wrong one.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/players.php';
require_once __DIR__ . '/guest.php';

/** How long a code is worth anything. Long enough to walk across a room. */
const XERIC_PAIR_TTL = 300;

/** How many codes may be waiting at once. A house, not a conference. */
const XERIC_PAIR_MAX = 4;

/** Wrong guesses before the door stops answering, and for how long. */
const XERIC_PAIR_TRIES = 8;
const XERIC_PAIR_LOCK  = 120;

/**
 * The alphabet a code is written in.
 *
 * No 0/O, no 1/I/L. Somebody is reading this off a screen across a kitchen and
 * typing it with their thumbs, and every character that can be misread is a
 * failed join that looks like a broken program.
 */
const XERIC_PAIR_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

/** How long a code is. 8 of 31 symbols is about forty bits. */
const XERIC_PAIR_LEN = 8;

/** A fresh code, from the best randomness the machine has. */
function xeric_pair_code(): string
{
    $a = XERIC_PAIR_ALPHABET;
    $n = strlen($a);
    $out = '';
    for ($i = 0; $i < XERIC_PAIR_LEN; $i++) $out .= $a[random_int(0, $n - 1)];
    return $out;
}

/** Codes are stored hashed: a stolen database is not a stack of door keys. */
function xeric_pair_hash(string $code): string
{
    return hash('sha256', 'xeric-pair:' . strtoupper(trim($code)));
}

/** Every code still waiting, expired ones dropped on the way past. */
function xeric_pair_open(PDO $db, ?int $now = null): array
{
    $now = $now ?? time();
    $raw = xeric_world_state_get($db, 'pair.codes');
    $all = $raw === null ? [] : json_decode((string)$raw, true);
    if (!is_array($all)) return [];
    $out = [];
    foreach ($all as $r) {
        if (!is_array($r) || (int)($r['dies'] ?? 0) <= $now) continue;
        $out[] = $r;
    }
    return $out;
}

/**
 * Make a code somebody can scan. Returns the plaintext ONCE.
 *
 * The plaintext is never stored and never recoverable — if the screen showing
 * it is closed the code is gone and the owner makes another, which costs
 * nothing and is the correct behaviour rather than a limitation.
 */
function xeric_pair_new(PDO $db, string $name = '', string $way = 'guest', ?int $at = null): array
{
    $now  = time();
    $open = xeric_pair_open($db, $now);
    if (count($open) >= XERIC_PAIR_MAX) {
        throw new RuntimeException('pair: there are already ' . count($open)
            . ' invitations out. Let them be used or let them run out.');
    }
    $code = xeric_pair_code();
    $open[] = ['h' => xeric_pair_hash($code), 'dies' => $now + XERIC_PAIR_TTL,
               'name' => mb_substr(trim($name), 0, 40),
               'way' => in_array($way, XERIC_GUEST_WAYS, true) ? $way : 'guest'];

    xeric_world_state_set($db, 'pair.codes', json_encode($open, JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
    return ['code' => $code, 'dies' => $now + XERIC_PAIR_TTL, 'ttl' => XERIC_PAIR_TTL];
}

/** Is the door refusing to answer right now, and for how much longer? */
function xeric_pair_locked(PDO $db, ?int $now = null): int
{
    $now = $now ?? time();
    $until = (int)(xeric_world_state_get($db, 'pair.locked') ?? 0);
    return $until > $now ? $until - $now : 0;
}

/**
 * Somebody scanned it. Returns the new player's id, or throws.
 *
 * FAILS THE SAME WAY FOR EVERY WRONG ANSWER. An expired code, a used code and
 * a code that never existed all get one sentence, because the difference
 * between them is information and there is no reason to hand it out.
 *
 * The burn and the join happen in ONE transaction: two phones scanning the
 * same screen at the same instant must not both get in on one code.
 */
function xeric_pair_claim(PDO $db, array $t, string $code, string $name = '',
                          ?int $at = null): int
{
    $now = time();
    if (($wait = xeric_pair_locked($db, $now)) > 0) {
        throw new RuntimeException('pair: too many wrong codes. Try again in '
            . max(1, (int)ceil($wait / 10) * 10) . ' seconds.');
    }

    $at   = $at ?? xeric_state_time();
    $want = xeric_pair_hash($code);
    $open = xeric_pair_open($db, $now);

    $found = null;
    $left  = [];
    foreach ($open as $r) {
        // hash_equals on every row, and no early break: a loop that returns the
        // moment it matches tells anybody counting microseconds where in the
        // list their guess landed.
        if ($found === null && hash_equals((string)$r['h'], $want)) { $found = $r; continue; }
        $left[] = $r;
    }

    if ($found === null) {
        $tries = (int)(xeric_world_state_get($db, 'pair.tries') ?? 0) + 1;
        if ($tries >= XERIC_PAIR_TRIES) {
            xeric_world_state_set($db, 'pair.locked', (string)($now + XERIC_PAIR_LOCK), $at);
            xeric_world_state_set($db, 'pair.tries', '0', $at);
        } else {
            xeric_world_state_set($db, 'pair.tries', (string)$tries, $at);
        }
        throw new RuntimeException('pair: that code is not good — it may have been used already, '
            . 'or run out. Ask for another one.');
    }

    $who = trim($name) !== '' ? trim($name) : ((string)$found['name'] !== '' ? (string)$found['name'] : 'somebody');

    $db->beginTransaction();
    try {
        // Burned FIRST and in the same transaction as the join, so two phones
        // scanning one screen at the same instant cannot both get in.
        xeric_world_state_set($db, 'pair.codes', json_encode($left, JSON_UNESCAPED_UNICODE), $at);
        xeric_world_state_set($db, 'pair.tries', '0', $at);

        $id = xeric_player_add($db, $t, $who, '', $at);
        xeric_guest_arrive($db, $t, $id, XERIC_PLAYER_FIRST, (string)$found['way'], $at);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('pair: ' . $e->getMessage(), 0, $e);
    }

    // The borrowed standing they walk in holding, outside the transaction on
    // purpose: they are in the world either way, and a vouch that failed should
    // not un-join somebody who is already standing in the room.
    try { xeric_guest_vouch($db, $t, $id, $at); } catch (Throwable $e) { /* they arrive cold */ }

    return $id;
}

/** Put every waiting code out, now. The owner changed their mind. */
function xeric_pair_clear(PDO $db, ?int $at = null): void
{
    xeric_world_state_set($db, 'pair.codes', '[]', $at ?? xeric_state_time());
}

/**
 * SHOWING SOMEBODY OUT. The other half of a door.
 *
 * A program that can let people in and not out is not a door, it is a hole,
 * and in a house the case is entirely ordinary: somebody has to go to bed, or
 * the evening is over, or a person is being a nuisance.
 *
 * WHAT LEAVING DOES AND DOES NOT DO. It removes them from the roster and burns
 * their way back in. It does NOT delete what happened while they were here —
 * their promises stand, their debts stand, and what the town decided about
 * them stays decided, because a person who stops coming round does not stop
 * having been here. If they are invited again they get a new number and start
 * over; if the owner would rather they picked up where they left off, that is
 * what NOT showing them out is for.
 *
 * @return bool whether there was anybody to show out
 */
function xeric_pair_show_out(PDO $db, array $t, int $player, ?int $at = null): bool
{
    if ($player <= XERIC_PLAYER_FIRST) return false;
    $at = $at ?? xeric_state_time();
    if (!xeric_player_drop($db, $t, $player, $at)) return false;

    // Their arrival row goes with them, so a second invitation is a fresh
    // arrival rather than a resurrection of a guest who is not on the roster.
    xeric_world_state_set($db, 'guest.p' . $player, '', $at);
    return true;
}
