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

/**
 * Which row holds one person's opinion of another.
 *
 * A bare `trust` is what somebody thinks of the person at the centre, which
 * is every trust row this engine has ever written. `trust.of.<handle>` is
 * what they think of somebody in the town — and the two must not be the same
 * row, because "Ruth trusts you less because Harlan stood her up" is a
 * sentence no engine should ever be able to write.
 */
function xeric_trust_key(?string $of = null): string
{
    $of = trim((string)$of);
    return $of === '' ? 'trust' : 'trust.of.' . $of;
}

/** What this person has decided about you — or about somebody in the town. */
function xeric_trust_of(PDO $db, string $handle, ?string $of = null): int
{
    return xeric_arc_int($db, $handle, xeric_trust_key($of), 0);
}

/**
 * A thing that cost something: a promise kept, a repair, a secret told.
 * Moves trust directly and is bounded only by the far ends — this is the
 * half of the dial that ordinary conversation cannot reach.
 *
 * ASYMMETRIC IN BOTH DIRECTIONS NOW. Ruth's opinion of you and Dot's were
 * always two numbers; Ruth's opinion of Harlan is a third, and Harlan's of
 * Ruth is a fourth that never has to agree with it. Which is the thing games
 * almost never do — one number per pair is the norm, and one number per pair
 * cannot hold a friendship somebody is wrong about.
 */
function xeric_trust_earn(PDO $db, string $handle, int $delta, ?int $at = null, ?string $of = null): int
{
    if ($handle === '' || $delta === 0) return xeric_trust_of($db, $handle, $of);
    $now = max(-XERIC_TRUST_MAX, min(XERIC_TRUST_MAX, xeric_trust_of($db, $handle, $of) + $delta));
    xeric_arc_set($db, $handle, xeric_trust_key($of), (string)$now, $at);
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
 * WHAT AN HOUR DID TO THE PAIR WHO WERE IN IT.
 *
 * The complaint at the top of this file was about the person at the centre: a
 * hundred good conversations moved trust nothing. The same hole is wider
 * between two people in the town, because they have no conversations at all —
 * only hours. A world could arm `standings` and `the_ladder`, produce friction
 * hour after friction hour for a year, and Ruth and Harlan would think exactly
 * what they thought on the first night.
 *
 * SO THE HOURS COUNT, at the same slow rate ordinary contact does — warmth,
 * not trust, so four rubs make a point and one bad Tuesday makes nothing. This
 * is standing, and it is deliberately NOT a fifth needle: standing in a small
 * town is just what everybody thinks of you, and this engine already has a
 * number for what one person thinks of another. A separate rank would have
 * been a leaderboard, which is the thing a town is least like.
 *
 * FRICTION IS MUTUAL AND A FAVOUR IS NOT. An edge that shows in a room cuts
 * both ways — neither of them is having a good time, and code cannot tell who
 * won without asking, and asking would be a leaderboard again. But a favour
 * has a direction the model already reported, and only one of them owes
 * anything for it: the person who got the good turn thinks better of the one
 * who did it, and the giver's opinion is unchanged, because doing somebody a
 * kindness is not a reason to like them more.
 *
 * @return array{mutual:int,to_giver:int} warmth, in the units contact uses
 */
function xeric_trust_hour(string $kind): array
{
    return match ($kind) {
        'friction'    => ['mutual' => -2, 'to_giver' => 0],
        'favor'       => ['mutual' => 0,  'to_giver' => 3],
        'recognition' => ['mutual' => 1,  'to_giver' => 0],
        'ordinary'    => ['mutual' => 1,  'to_giver' => 0],
        default       => ['mutual' => 0,  'to_giver' => 0],
    };
}

/**
 * The pair-warmth converter: contact between two people in the town.
 *
 * Same arithmetic as xeric_trust_contact() and same ceiling, on the pair row
 * instead of the bare one — hours alone carry two people from strangers to
 * warm, or to wary, and no further. The far ends stay reserved for the things
 * that cost something, which between two NPCs means a promise kept across a
 * table (constructs.php) or a debt squared.
 */
function xeric_trust_rub(PDO $db, string $who, string $about, int $warmth, ?int $at = null): int
{
    if ($who === '' || $about === '' || $who === $about || $warmth === 0) {
        return xeric_trust_of($db, $who, $about);
    }
    $wKey  = 'trust.warmth.of.' . $about;
    $w     = xeric_arc_int($db, $who, $wKey, 0) + $warmth;
    $trust = xeric_trust_of($db, $who, $about);
    $was   = $trust;

    while ($w >= XERIC_TRUST_STEP && $trust < XERIC_TRUST_BAND) { $w -= XERIC_TRUST_STEP; $trust++; }
    while ($w <= -XERIC_TRUST_STEP && $trust > -XERIC_TRUST_BAND) { $w += XERIC_TRUST_STEP; $trust--; }

    $w = max(-2 * XERIC_TRUST_STEP, min(2 * XERIC_TRUST_STEP, $w));
    xeric_arc_set($db, $who, $wKey, (string)$w, $at);
    if ($trust !== $was) xeric_trust_earn($db, $who, $trust - $was, $at, $about);
    return $trust;
}

/**
 * Everybody in the hour, rubbed against everybody else in it.
 *
 * Called from the sweep's own transaction, so an hour that rolls back moves
 * nobody's opinion of anybody. Costs at most a handful of rows and only for
 * the kinds that are actually about two people — an `ordinary` evening between
 * four people is six warm pairs, which is what an ordinary evening is.
 */
function xeric_trust_hour_apply(PDO $db, string $kind, array $handles, ?array $favor = null,
                                ?int $at = null): int
{
    $w = xeric_trust_hour($kind);
    $n = 0;
    if ($w['mutual'] !== 0) {
        foreach ($handles as $a) {
            foreach ($handles as $b) {
                if ($a === $b) continue;
                xeric_trust_rub($db, (string)$a, (string)$b, $w['mutual'], $at);
                $n++;
            }
        }
    }
    // And the one direction a favour has, which the model reported and the
    // sweep already checked both ends of.
    if ($w['to_giver'] !== 0 && is_array($favor)
        && ($favor['from'] ?? '') !== '' && ($favor['to'] ?? '') !== '') {
        xeric_trust_rub($db, (string)$favor['to'], (string)$favor['from'], $w['to_giver'], $at);
        $n++;
    }
    return $n;
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
