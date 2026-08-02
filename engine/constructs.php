<?php
/**
 * Xeric — social constructs. The first one: EXPECTATIONS.
 *
 * A relationship that cannot be let down cannot be kept (docs/CONSTRUCTS.md —
 * the design decided with the owner, 2026-08-02; this file implements it).
 * Everything here is the reusable construct shape: trigger → a small arc with
 * epochs → COARSE rendered state → a licensed event or line → residue that
 * becomes an ordinary memory. No construct ever renders a countdown: a timer
 * would change every message and bust the one prefix cache the whole engine
 * is disciplined around.
 *
 * ── THE PROMISE TEST ──────────────────────────────────────────────────────
 * An expectation forms when a character hears the USER commit with no weasel
 * words and a real WHEN. "I'll be there Thursday" forms one; "I'll try to be
 * there" forms nothing — try, maybe, might, probably, any hedge makes it a
 * non-promise. The model proposes the quote (it is already reading the
 * conversation for memories); THE GATE IS CODE, run on the verbatim words,
 * because a linguistic rule enforced by instruction is a hope and enforced by
 * regex is a fact.
 *
 * THE CHILD EXCEPTION: a listener of three to seven sometimes hears every
 * promise as a real one, hedged or not. Implemented as: hedged commitments DO
 * form for an under-eight listener, marked heard_as-a-promise-because-she-is-
 * six. The gate is a property of the LISTENER — that is not a loophole in the
 * rule, it is the rule.
 *
 * ── WHAT A MISS DOES (the body is the tell) ──────────────────────────────
 * Nobody is told how anyone feels. A miss lands in three places:
 *   1. trust — the invisible ledger, one notch, silently;
 *   2. the body — the character's prompt gains a licensed line: it stung, it
 *      shows in small ways, you do not narrate it. Stage direction is the
 *      rendering of the subconscious;
 *   3. the record — an EVENT written in OBSERVABLES ONLY ("she kept an eye on
 *      the door past nine"), because gossip feeds on what was seen, never on
 *      what was felt. That one rule is what keeps a gossip system from being
 *      a leak engine.
 *
 * ── REPAIR, AND WHO FORGIVES ─────────────────────────────────────────────
 * Explaining is a real move: the extractor watches for it, and an explanation
 * inside the repair window moves the arc to `repaired` and returns the trust
 * notch. HOW WELL it lands is the person's, not the system's — the mechanic
 * is uniform and the PROSE is not: the character's own psyche shapes whether
 * the Omaha story gets a poured coffee or a private tally mark, because the
 * same explanation should not work on everybody. No explanation at all lets
 * the window close and the residue harden into an ordinary memory.
 *
 * ── TIME ─────────────────────────────────────────────────────────────────
 * Fuses burn in WORLD time and burn during skips — a skip is real time
 * passing (docs/CONSTRUCTS.md: "time travel is real"). Detection may happen
 * late (the next heart tick or chat turn after the due hour), but the miss
 * EVENT is stamped at the hour the person actually waited, so the book and
 * the record put it on the right day. The mercy for a fumbled skip is the
 * rewind, which lives elsewhere and un-happens the fuse-firing with the rest
 * of the undone future.
 *
 * v1 SCOPE: expectations point at the USER — characters expecting the person
 * at the center, who is the only one who can let them down. Cast-to-cast
 * expectations reuse this shape later, fed by sweeps instead of chat.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';

/** Grace past the promised hour before a wait becomes a miss, world seconds. */
const XERIC_EXPECT_GRACE = 4 * 3600;

/** How long a miss stays repairable before it hardens, world seconds. */
const XERIC_EXPECT_REPAIR_WINDOW = 7 * 24 * 3600;

/** Under this age, a hedged promise may still be heard as a real one. */
const XERIC_EXPECT_BELIEVER_AGE = 8;

// ---------------------------------------------------------------------------
// The promise test
// ---------------------------------------------------------------------------

/**
 * Does this quote weasel? Word-boundary, case-blind, on the verbatim words.
 *
 * The list is the owner's rule made concrete: anything that hedges the doing
 * or the coming makes a non-promise. Kept short and high-precision — a false
 * "promise" nags forever, a false "non-promise" costs one formed expectation,
 * so when in doubt this list should grow, not shrink.
 */
function xeric_promise_hedged(string $quote): bool
{
    $q = ' ' . mb_strtolower($quote) . ' ';
    foreach ([
        'try', 'maybe', 'might', 'perhaps', 'possibly', 'probably', 'hopefully',
        'i think', 'i guess', 'we\'ll see', 'we will see', 'i\'ll see', 'i will see',
        'should be able', 'if i can', 'if i get', 'if work', 'no promises',
        'can\'t promise', 'cannot promise', 'not sure', 'most likely', 'ideally',
    ] as $w) {
        if (preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/u', $q)) return true;
    }
    return false;
}

/**
 * A when-phrase → a world epoch, in the WORLD's timezone, or null.
 *
 * "Thursday", "tomorrow", "Saturday morning", "tonight" — the phrases people
 * actually promise in. Day words resolve forward (a promise is about the
 * future); a bare time-of-day means the next occurrence; anything this cannot
 * read returns null and NO expectation forms, because the design's own rule
 * is that a promise without a when is conversation, not commitment.
 */
function xeric_promise_when(string $when, array $t, array $now): ?int
{
    $when = mb_strtolower(trim($when));
    if ($when === '') return null;

    $tods = ['morning' => 9, 'noon' => 12, 'afternoon' => 15, 'evening' => 19,
             'tonight' => 20, 'night' => 21];
    $hour = null;
    foreach ($tods as $word => $h) {
        if (preg_match('/(?<![a-z])' . $word . '(?![a-z])/u', $when)) {
            $hour = $h;
            $when = trim((string)preg_replace('/(?<![a-z])(in the |this |at )?' . $word . '(?![a-z])/u', ' ', $when));
            break;
        }
    }
    if ($when === '' && $hour !== null) $when = 'today';
    if ($when === '') return null;

    try {
        $tz   = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC'));
        $base = (new DateTimeImmutable('@' . (int)($now['epoch'] ?? 0)))->setTimezone($tz);
        $due  = $base->modify($when);
        if ($due === false) return null;
        if ($hour !== null) $due = $due->setTime($hour, 0);
        // A promise is about the future. "Thursday" said on a Thursday evening
        // means next Thursday; a resolved past means the phrase pointed
        // backward and a week forward is what the speaker meant.
        if ($due->getTimestamp() <= (int)($now['epoch'] ?? 0)) $due = $due->modify('+1 week');
        if ($due === false) return null;
        $ts = $due->getTimestamp();
        // More than a month out is a plan, not a promise this system holds.
        if ($ts <= (int)($now['epoch'] ?? 0) || $ts > (int)($now['epoch'] ?? 0) + 31 * 86400) return null;
        return $ts;
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Formation
// ---------------------------------------------------------------------------

/** Is the expectations system armed in this world? Worlds without a systems record predate arming and get it. */
function xeric_expect_armed(array $t): bool
{
    $armed = $t['forge']['armed'] ?? null;
    if (!is_array($armed)) return true;                   // hand-built or pre-systems world
    return in_array('expectations', array_map('strval', $armed), true);
}

/**
 * Form an expectation from what the extractor heard, or decline.
 *
 * $p is the model's proposal: ['quote' => the user's verbatim words,
 * 'what' => a short label, 'when' => the words for when]. The model proposes;
 * this gate disposes — on the QUOTE, not on the model's opinion of it.
 * Returns the arc key formed, or null with a reason via $onNote.
 */
function xeric_expect_form(array $t, PDO $db, string $listener, ?array $p, array $now, ?callable $onNote = null): ?string
{
    $note = $onNote ?? static function (string $s): void {};
    if (!is_array($p)) return null;
    if (!xeric_expect_armed($t)) { $note('expectations: not armed in this world'); return null; }

    $quote = trim((string)($p['quote'] ?? ''));
    $what  = trim((string)($p['what'] ?? ''));
    $whenS = trim((string)($p['when'] ?? ''));
    if ($quote === '' || $whenS === '') return null;
    if ($what === '') $what = mb_substr($quote, 0, 60);

    $heardAs = '';
    if (xeric_promise_hedged($quote)) {
        // The child exception: the gate is a property of the listener.
        $c   = xeric_world_character($t, $listener);
        $age = is_array($c) ? ($c['age'] ?? null) : null;
        if (!is_int($age) || $age >= XERIC_EXPECT_BELIEVER_AGE || $age < 3) {
            $note('expectations: "' . mb_substr($quote, 0, 40) . '" hedges — no promise formed');
            return null;
        }
        $heardAs = 'a real promise, because ' . xeric_world_name($t, $listener) . ' is ' . $age;
    }

    $due = xeric_promise_when($whenS, $t, $now);
    if ($due === null) { $note('expectations: could not place "' . $whenS . '" on the calendar — nothing formed'); return null; }

    // One expectation per person per day: a second promise about the same
    // stretch replaces nothing and forms nothing — the first one is the date.
    foreach (xeric_expects_for($db, $listener) as $e) {
        if ($e['state'] === 'open' && abs($e['due'] - $due) < 12 * 3600) {
            $note('expectations: ' . $listener . ' already expects something then');
            return null;
        }
    }

    $n   = 1 + count(xeric_expects_for($db, $listener));
    $key = 'expect.' . $n;
    xeric_arc_set($db, $listener, $key, json_encode([
        'what' => mb_substr($what, 0, 80), 'quote' => mb_substr($quote, 0, 160),
        'when_said' => mb_substr($whenS, 0, 40), 'due' => $due,
        'formed' => (int)($now['epoch'] ?? 0), 'state' => 'open',
    ] + ($heardAs !== '' ? ['heard_as' => $heardAs] : []), JSON_UNESCAPED_UNICODE));
    $note('expectations: ' . xeric_world_name($t, $listener) . ' now expects — ' . $what);
    return $key;
}

/** Every expectation this character holds, parsed, oldest first. */
function xeric_expects_for(PDO $db, string $handle): array
{
    $out = [];
    foreach (xeric_arcs_for($db, $handle) as $k => $v) {
        if (!str_starts_with((string)$k, 'expect.')) continue;
        $row = json_decode((string)$v, true);
        if (!is_array($row) || !isset($row['due'], $row['state'])) continue;
        $row['key'] = (string)$k;
        $row['due'] = (int)$row['due'];
        $out[] = $row;
    }
    usort($out, fn($a, $b) => $a['due'] <=> $b['due']);
    return $out;
}

// ---------------------------------------------------------------------------
// The rendered state — coarse, always
// ---------------------------------------------------------------------------

/**
 * The block a character's system prompt carries. Day-coarse, no clocks, no
 * countdowns: the text changes only when the STATE changes, so the prefix
 * cache survives every ordinary turn.
 */
function xeric_expect_block(array $t, PDO $db, string $handle, array $now): string
{
    $rows = xeric_expects_for($db, $handle);
    if ($rows === []) return '';
    $user = trim((string)($t['user']['name'] ?? '')) ?: 'them';
    $tz   = null;
    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); } catch (Throwable $e) {}
    $day = static function (int $epoch) use ($tz): string {
        try { return (new DateTimeImmutable('@' . $epoch))->setTimezone($tz ?? new DateTimeZone('UTC'))->format('l'); }
        catch (Throwable $e) { return 'that day'; }
    };

    $lines = [];
    foreach ($rows as $e) {
        switch ($e['state']) {
            case 'open':
                $lines[] = '- ' . $user . ' said "' . $e['quote'] . '" — you are expecting them ' . $day($e['due'])
                    . ' (' . $e['what'] . ').' . (isset($e['heard_as']) ? ' You heard it as ' . $e['heard_as'] . '.' : '');
                break;
            case 'missed':
                $lines[] = '- ' . $user . ' said "' . $e['quote'] . '" and did not come ' . $day($e['due'])
                    . ', and has not said why. It stung more than you let on. You never narrate this; '
                    . 'it shows in small ways — what your hands do, what you leave unsaid, how long you take to answer.';
                break;
            case 'repaired':
                $lines[] = '- ' . $user . ' missed ' . $e['what'] . ' ' . $day($e['due'])
                    . ' but told you why. How settled that is between you is yours to carry, in your own way.';
                break;
            case 'hardened':
                $lines[] = '- ' . $user . ' never did say anything about missing ' . $e['what']
                    . '. You have stopped waiting for the explanation. That has a weight of its own.';
                break;
        }
    }
    if ($lines === []) return '';
    return "WHAT YOU ARE OWED\n" . implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// The tick — fuses burn in world time
// ---------------------------------------------------------------------------

/**
 * Advance every fuse in the world to $now. No model, cheap, idempotent —
 * safe from the heart every minute and from any chat turn.
 *
 * A miss writes its event AT THE DUE HOUR (backdated world_epoch), so however
 * late detection runs — the next heart tick, or a chat turn a day later — the
 * record and the book put the waiting on the day it actually happened. The
 * event is OBSERVABLES ONLY; the feeling went in the prompt block, not here.
 */
function xeric_constructs_tick(array $t, PDO $db, array $now, ?callable $onNote = null): array
{
    $note  = $onNote ?? static function (string $s): void {};
    $epoch = (int)($now['epoch'] ?? 0);
    $out   = ['missed' => 0, 'hardened' => 0];
    $user  = trim((string)($t['user']['name'] ?? '')) ?: 'they';

    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || !empty($c['out'])) continue;
        foreach (xeric_expects_for($db, $h) as $e) {
            if ($e['state'] === 'open' && $epoch > $e['due'] + XERIC_EXPECT_GRACE) {
                $name  = xeric_world_name($t, $h);
                $place = xeric_world_who_is_where($t, xeric_world_now($t, $e['due']))[$h]['where'] ?? null;
                $at    = $place !== null ? ' at ' . xeric_world_place_name($t, (string)$place) : '';
                $hhmm  = xeric_world_now($t, $e['due'])['hhmm'] ?? '';

                $db->beginTransaction();
                try {
                    $eid = xeric_event_add($db, $name . ' waited' . $at, $e['due'],
                        $place !== null ? (string)$place : null, [$h],
                        $name . ' kept half an eye on the door' . $at . ' past ' . $hhmm
                        . '. Left later than usual, and did not say why.');
                    xeric_memory_add($db, $h, $user . ' said "' . $e['quote'] . '" and did not come.',
                        'construct', ['expect' => $e['key']], $e['due']);
                    xeric_arc_bump($db, $h, 'trust', -1);
                    $e['state'] = 'missed'; $e['missed_at'] = $epoch;
                    $row = $e; unset($row['key']);
                    xeric_arc_set($db, $h, $e['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
                    // The inspector's answer, in the trail the why view already
                    // reads: a grudge that cannot explain itself is the model
                    // being moody; this one can.
                    xeric_world_state_set($db, 'why:event:' . $eid, json_encode([
                        'kind' => 'missed_promise',
                        'why'  => $name . ' expected ' . $user . ' (' . $e['what'] . ' — "' . $e['quote'] . '"). '
                                . $user . ' did not come. The event states only what anyone could see; '
                                . 'the feeling rides ' . $name . '\'s own prompt, not the record.',
                    ], JSON_UNESCAPED_UNICODE));
                    $db->commit();
                } catch (Throwable $ex) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $ex;
                }
                $out['missed']++;
                $note('expectations: ' . $name . ' waited, and ' . $user . ' did not come');
            } elseif ($e['state'] === 'missed' && $epoch > ($e['missed_at'] ?? $e['due']) + XERIC_EXPECT_REPAIR_WINDOW) {
                $name = xeric_world_name($t, $h);
                xeric_memory_add($db, $h,
                    'It sat with ' . $name . ' that ' . $user . ' never said anything about ' . $e['what'] . '.',
                    'construct', ['expect' => $e['key']], $epoch);
                $e['state'] = 'hardened';
                $row = $e; unset($row['key']);
                xeric_arc_set($db, $h, $e['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
                $out['hardened']++;
                $note('expectations: the window closed on ' . $e['what'] . ' — it hardened');
            }
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Repair
// ---------------------------------------------------------------------------

/**
 * The user explained. The newest repairable miss for this character is the one
 * being explained — people repair the freshest wound first — and the mechanic
 * is deliberately uniform: state moves, the trust notch returns, a memory
 * lands. WHO FORGIVES AND HOW MUCH is the character's, expressed in prose:
 * their psyche shapes the reply the model writes, and the block's own wording
 * ("how settled that is between you is yours to carry") hands them the room.
 * A scorekeeper and a forgiver read the same mechanic differently, which is
 * the owner's design: forgiveness is a property of the person.
 */
function xeric_expect_repair(array $t, PDO $db, string $handle, array $now, ?callable $onNote = null): ?string
{
    $note = $onNote ?? static function (string $s): void {};
    $user = trim((string)($t['user']['name'] ?? '')) ?: 'they';
    $best = null;
    foreach (xeric_expects_for($db, $handle) as $e) {
        if ($e['state'] !== 'missed') continue;
        if ($best === null || ($e['missed_at'] ?? $e['due']) > ($best['missed_at'] ?? $best['due'])) $best = $e;
    }
    if ($best === null) return null;

    $name = xeric_world_name($t, $handle);
    $best['state'] = 'repaired'; $best['explained_at'] = (int)($now['epoch'] ?? 0);
    $key = $best['key']; $row = $best; unset($row['key']);
    xeric_arc_set($db, $handle, $key, json_encode($row, JSON_UNESCAPED_UNICODE));
    xeric_arc_bump($db, $handle, 'trust', +1);
    xeric_memory_add($db, $handle, $user . ' told ' . $name . ' why they missed ' . $best['what'] . '.',
        'construct', ['expect' => $key], (int)($now['epoch'] ?? 0));
    $note('expectations: ' . $user . ' explained ' . $best['what'] . ' — repaired');
    return $key;
}
