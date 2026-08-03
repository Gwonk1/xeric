<?php
/**
 * Xeric — trust. What one person has decided about you, and how.
 *
 * TRUST HAS ONLY EVER MOVED THROUGH PROMISES. constructs.php takes a point
 * off for a promise broken and gives one back for a promise explained, and
 * that is the whole of it — so a hundred good conversations move it nothing,
 * and a character you have never failed is exactly as guarded on the
 * hundredth night as the first. The dial existed, the narrator read it, and
 * daily life could not touch it.
 *
 * ORDINARY CONTACT COUNTS NOW, AND IT IS NOT A CHAT COUNTER. The failure mode
 * of every approval meter in every game is that talking is the way to raise
 * it, so talking becomes farming. Two rules stop that here:
 *
 *   1. CONTACT CONVERTS SLOWLY. Replies and attention accrue as WARMTH, and
 *      warmth becomes a point of trust only every few of them. Four exchanges
 *      is a point; one good evening is not a friendship.
 *   2. CONTACT HAS A CEILING. Warmth carries somebody from a stranger to
 *      warm and stops — the far end of trust is not for sale at any volume of
 *      conversation. Past the band, only the things that cost something move
 *      it: a promise kept, a promise repaired, a secret they chose to tell
 *      you. Those are constructs, they have reasons attached, and they are
 *      what the deep end is made of.
 *
 * BEING IGNORED COSTS MORE THAN BEING ANSWERED EARNS, which is not cynicism
 * but arithmetic about attention: somebody reached for their phone first and
 * nothing came back, and they noticed that harder than they noticed the
 * ordinary Tuesday you replied on.
 *
 * ASYMMETRIC BY CONSTRUCTION. This is a row per character, so Ruth's opinion
 * of you and Dot's are two different numbers that never have to agree — and
 * neither of them is shown to you, because a meter on screen is a resource to
 * farm and this one is supposed to be a person's mind.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/state.php';

/** How much warmth makes a point of trust. Four exchanges, not one. */
const XERIC_TRUST_STEP = 4;

/** How far ordinary contact alone can carry somebody, either way. */
const XERIC_TRUST_BAND = 3;

/** The far ends, which only the things that cost something reach. */
const XERIC_TRUST_MAX = 10;

/** What this person has decided about you. */
function xeric_trust_of(PDO $db, string $handle): int
{
    return xeric_arc_int($db, $handle, 'trust', 0);
}

/**
 * A thing that cost something: a promise kept, a repair, a secret told.
 * Moves trust directly and is bounded only by the far ends — this is the
 * half of the dial that ordinary conversation cannot reach.
 */
function xeric_trust_earn(PDO $db, string $handle, int $delta, ?int $at = null): int
{
    if ($handle === '' || $delta === 0) return xeric_trust_of($db, $handle);
    $now = max(-XERIC_TRUST_MAX, min(XERIC_TRUST_MAX, xeric_trust_of($db, $handle) + $delta));
    xeric_arc_set($db, $handle, 'trust', (string)$now, $at);
    return $now;
}

/**
 * Ordinary contact: warmth in, and a point of trust out every so often.
 *
 * The band is checked against where trust WOULD land, so contact can carry
 * somebody up to warm and no further, and can cool somebody to wary and no
 * further. Somebody already past the band in either direction keeps
 * accruing warmth and simply stops converting it — which means a friend you
 * have been ignoring cools back through the band the moment the warmth turns
 * negative, rather than being stuck at the top because they once liked you.
 *
 * @return int the trust after this contact
 */
function xeric_trust_contact(PDO $db, string $handle, int $warmth, ?int $at = null): int
{
    if ($handle === '' || $warmth === 0) return xeric_trust_of($db, $handle);

    $w = xeric_arc_int($db, $handle, 'trust.warmth', 0) + $warmth;
    $trust = xeric_trust_of($db, $handle);

    // Convert whole steps only, one direction at a time, and never past the
    // band contact is allowed to reach.
    while ($w >= XERIC_TRUST_STEP && $trust < XERIC_TRUST_BAND) {
        $w -= XERIC_TRUST_STEP;
        $trust++;
    }
    while ($w <= -XERIC_TRUST_STEP && $trust > -XERIC_TRUST_BAND) {
        $w += XERIC_TRUST_STEP;
        $trust--;
    }

    // Warmth is a remainder, not a reservoir: letting it pile up unbounded
    // would mean a year of small talk cashing out the day somebody dips back
    // under the ceiling.
    $w = max(-2 * XERIC_TRUST_STEP, min(2 * XERIC_TRUST_STEP, $w));

    xeric_arc_set($db, $handle, 'trust.warmth', (string)$w, $at);
    if ($trust !== xeric_trust_of($db, $handle)) xeric_arc_set($db, $handle, 'trust', (string)$trust, $at);
    return $trust;
}

/**
 * What the town's own signals are worth, per crumb learn.php folds.
 *
 * Deliberately small and deliberately asymmetric. A reply is an ordinary
 * kindness; a read is attention without an answer, which is worth less than
 * an answer; a ping that went nowhere is the one that stings.
 */
function xeric_trust_signal(string $kind): int
{
    return match ($kind) {
        'reply'   => 1,
        'dwell'   => 1,
        'ignored' => -2,
        default   => 0,
    };
}
