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
 *
 * ── THE SECOND CONSTRUCT: THE GOSSIP RIPPLE ──────────────────────────────
 * Gossip feeds on what was SEEN — "she waited outside till nine, I saw her" —
 * never on what was felt (docs/CONSTRUCTS.md). That one rule is the entire
 * safety argument: a ripple that spreads only observables is walls-safe BY
 * CONSTRUCTION, where a ripple that could spread interiors would be a leak
 * engine with a folksy name. So what travels is the event's own COMMONS
 * words (the title, with any stray clock scrubbed out — a coarser retelling
 * derived in code, never enriched), and an hour whose words quote anybody's
 * interior never becomes an item at all.
 *
 * The shape is the construct shape, again: trigger (a gossip-worthy hour
 * lands in the events table) → ONE ARC PER ITEM, world-scoped — per-knower
 * arcs would copy the fuse and the source into every head, and the
 * inspector's "who saw, and how it traveled" answer should ride one record,
 * not a join → coarse rendered state (at most one line per fresh item, no
 * clocks, byte-stable between ticks) → residue (a knower who cared keeps one
 * ordinary memory when the talk dies down).
 *
 * HOW IT TRAVELS. Whoever stood at the event knows it firsthand; each tick,
 * a knower tells whoever shares their place-hour, one hop at a time, all of
 * it read off the week/presence data — deterministic, no model, and no
 * teleporting news: people who never share a room never trade a word. The
 * attribution thins as it travels (you saw it; she told you; people are
 * saying), a hop past third-hand has nothing left to pass on, and the fuse
 * puts old news down after six world-days. A wall that hides the underlying
 * domain from a would-be knower blocks their hop — the existing wall reads,
 * failing closed — so the ripple flows AROUND the protected, never through.
 *
 * Arms as `rumors` ("stories that travel and distort" — XERIC_SYSTEMS); a
 * world without a systems record predates arming and gets it, same as
 * expectations.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/death.php';   // the dead wait for nothing, and tell nobody anything
require_once __DIR__ . '/trust.php';   // a promise broken, and a promise explained

/** Grace past the promised hour before a wait becomes a miss, world seconds. */
const XERIC_EXPECT_GRACE = 4 * 3600;

/** How long a miss stays repairable before it hardens, world seconds. */
const XERIC_EXPECT_REPAIR_WINDOW = 7 * 24 * 3600;

/** Under this age, a hedged promise may still be heard as a real one. */
const XERIC_EXPECT_BELIEVER_AGE = 8;

/** How long an item travels before it is old news, world seconds. */
const XERIC_GOSSIP_FUSE = 6 * 24 * 3600;

/** Hops before a retelling is too thin to pass on: a hop-3 knower tells nobody. */
const XERIC_GOSSIP_REACH = 3;

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
function xeric_expect_form(array $t, PDO $db, string $listener, ?array $p, array $now, ?callable $onNote = null, ?string $of = null): ?string
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
    ] + ($heardAs !== '' ? ['heard_as' => $heardAs] : [])
      // WHO PROMISED. Absent means the user, which is every expectation this
      // engine has ever written — v1 pointed only at the person at the centre
      // because they were the only one who could let anybody down. A cast
      // handle here is a promise between two people in the town, which the
      // room's draw has had a dormant, tested notch waiting for.
      + ($of !== null && $of !== '' ? ['of' => $of] : []), JSON_UNESCAPED_UNICODE));
    $note('expectations: ' . xeric_world_name($t, $listener) . ' now expects — ' . $what);
    return $key;
}

/**
 * Promises made between two people in the town, out of a scene they had.
 *
 * v1 pointed only at the user, and the reason was honest: they were the only
 * one who could let anybody down. But a duet and a room are the town talking
 * to itself, and "I'll bring the truck round Thursday" said across a table is
 * the same sentence with the same fuse — so the same code gate reads it. The
 * model is never asked whether a promise was made; the words are, exactly as
 * they are for the person at the centre.
 *
 * WHO HEARS IT is everybody else who was in the room, because a promise made
 * in front of three people is owed to the one it was said to and remembered
 * by all of them — but only the ADDRESSED party gets the expectation, and
 * addressing is the room's own test (the name in the sentence). With two
 * people there is no ambiguity and the other one is the listener.
 *
 * Nothing here is asked of a model and nothing here can throw: a scene that
 * produced a promise is a better scene, and a scene that did not is a scene.
 *
 * @param array $lines [{handle, name, text}, …] as the duet and the room emit
 * @return string[] the arc keys formed
 */
function xeric_expect_from_scene(array $t, PDO $db, array $lines, array $now, ?callable $onNote = null): array
{
    if (!xeric_expect_armed($t)) return [];

    $present = [];
    foreach ($lines as $l) {
        $h = (string)($l['handle'] ?? '');
        if ($h !== '') $present[$h] = true;
    }
    if (count($present) < 2) return [];

    $formed = [];
    foreach ($lines as $l) {
        $speaker = (string)($l['handle'] ?? '');
        $text    = trim((string)($l['text'] ?? ''));
        if ($speaker === '' || $text === '') continue;

        // The same two gates, in the same order, on the verbatim words: a
        // hedge is not a promise, and a promise with no when is a sentiment.
        if (xeric_promise_hedged($text)) continue;
        if (xeric_promise_when_phrase($text) === '') continue;

        // Who it was said TO. Named in the sentence outranks; with two people
        // in the room the other one is the answer and no test is needed.
        $others = array_values(array_diff(array_keys($present), [$speaker]));
        if ($others === []) continue;
        $listener = '';
        if (count($others) === 1) {
            $listener = $others[0];
        } else {
            // Named in the sentence. Tested locally rather than through
            // chat.php's xeric_age_mentions(), because chat.php requires THIS
            // file and a construct reaching back up into the chat layer would
            // invert the dependency for one word match.
            foreach ($others as $o) {
                $c = xeric_world_character($t, $o) ?? [];
                $names = array_filter([xeric_world_name($t, $o), (string)($c['short_name'] ?? ''), $o]);
                foreach ($names as $n) {
                    $n = trim((string)$n);
                    if ($n === '') continue;
                    $first = (string)(preg_split('/\s+/u', $n)[0] ?? '');
                    if ($first !== '' && preg_match('/(?<![\p{L}])' . preg_quote($first, '/') . '(?![\p{L}])/ui', $text)) {
                        $listener = $o; break 2;
                    }
                }
            }
        }
        if ($listener === '') continue;   // said to the room is said to nobody in particular

        $key = xeric_expect_form($t, $db, $listener, [
            'quote' => $text,
            'what'  => mb_substr($text, 0, 60),
            'when'  => xeric_promise_when_phrase($text),
        ], $now, $onNote, $speaker);
        if ($key !== null) $formed[] = $key;
    }
    return $formed;
}

/**
 * The when-phrase inside a spoken line, or '' — the half of the promise test
 * that chat gets handed by the extractor and a scene has to find for itself.
 *
 * Deliberately the same vocabulary xeric_promise_when() can already place on
 * a calendar: anything it cannot place forms nothing, so this never promises
 * the fuse a date the fuse cannot burn to.
 */
function xeric_promise_when_phrase(string $text): string
{
    $t = mb_strtolower($text);
    foreach (['tomorrow', 'tonight', 'this evening', 'in the morning', 'later today',
              'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $w) {
        if (preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/u', $t)) return $w;
    }
    return '';
}

// ---------------------------------------------------------------------------
// The third construct: A DEBT
// ---------------------------------------------------------------------------
//
// `favor`'s own shape has said it since the kinds were written: "one of them
// did the other a real favour, AND IT IS NOW OWED." The owing was the half
// nothing kept — the hour landed, the town forgot, and a system the forge
// armed (`favors`, `a_debt`, `alliances_that_cost`) accumulated nothing.
//
// A DEBT IS A CONSTRUCT, NOT A COUNTER, and this is the argument made
// concrete. "Harlan owes Ruth −1" is a number nobody can act on. A row that
// says WHAT it was for, WHEN it was done, and that it is still standing can
// be rendered into his prompt in his own voice, settled by a favour going the
// other way, faded when it has been carried long enough, and gossiped about
// by a town that saw one of them do the other a good turn.
//
// IT NEVER POINTS AT THE PLAYER. A debt is between two people in the town,
// formed from an hour they were both in. What the player owes anybody is the
// expectation machinery above, which has a promise and a due date; what the
// player is owed is a different construct nobody has asked for yet.

/** How long an unsettled debt stays sharp before it is simply history. */
const XERIC_DEBT_FADE = 30 * 24 * 3600;

/** Does this world keep score of favours? */
function xeric_debt_armed(array $t): bool
{
    $armed = (array)($t['forge']['armed'] ?? []);
    if ($armed === []) return true;                 // predates arming, same as the others
    foreach (['favors', 'a_debt', 'alliances_that_cost'] as $s) {
        if (in_array($s, $armed, true)) return true;
    }
    return false;
}

/** Every debt this person is carrying, oldest first. */
function xeric_debts_for(PDO $db, string $handle): array
{
    $out = [];
    foreach (xeric_arcs_for($db, $handle) as $k => $v) {
        if (!str_starts_with((string)$k, 'debt.')) continue;
        $row = json_decode((string)$v, true);
        if (!is_array($row) || !isset($row['to'], $row['state'])) continue;
        $row['key'] = (string)$k;
        $out[] = $row;
    }
    usort($out, fn($a, $b) => (int)($a['formed'] ?? 0) <=> (int)($b['formed'] ?? 0));
    return $out;
}

/**
 * One of them did the other a real favour. Returns the arc key, or null.
 *
 * A FAVOUR THE OTHER WAY SETTLES ONE FIRST. That is what makes this a
 * relationship rather than a tally: two people trading good turns end up
 * even, not two-all, and the settling is what a small town actually tracks.
 * Only then does a new debt form.
 */
function xeric_debt_form(array $t, PDO $db, string $ower, string $to, string $what, array $now,
                         ?callable $onNote = null): ?string
{
    $note = $onNote ?? static function (string $s): void {};
    if (!xeric_debt_armed($t)) return null;
    if ($ower === '' || $to === '' || $ower === $to) return null;
    if (xeric_world_character($t, $ower) === null || xeric_world_character($t, $to) === null) return null;

    $epoch = (int)($now['epoch'] ?? 0);
    $what  = trim($what) !== '' ? mb_substr(trim($what), 0, 80) : 'a good turn';

    // The other way round first: if $to already owes $ower, this squares it.
    foreach (xeric_debts_for($db, $to) as $d) {
        if ((string)$d['to'] !== $ower || (string)$d['state'] !== 'open') continue;
        $d['state'] = 'settled'; $d['settled_at'] = $epoch; $d['settled_by'] = $what;
        $row = $d; unset($row['key']);
        xeric_arc_set($db, $to, (string)$d['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
        $note('debts: ' . xeric_world_name($t, $to) . ' and ' . xeric_world_name($t, $ower) . ' are square');
        return null;
    }

    // One open debt per pair. A second favour before the first is repaid
    // deepens what is owed rather than opening a second account, which is how
    // people actually talk about it: "I owe her, twice over."
    foreach (xeric_debts_for($db, $ower) as $d) {
        if ((string)$d['to'] !== $to || (string)$d['state'] !== 'open') continue;
        $d['times'] = (int)($d['times'] ?? 1) + 1;
        $d['what']  = $what;
        $d['formed'] = $epoch;
        $row = $d; unset($row['key']);
        xeric_arc_set($db, $ower, (string)$d['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
        $note('debts: ' . xeric_world_name($t, $ower) . ' owes ' . xeric_world_name($t, $to) . ' again');
        return (string)$d['key'];
    }

    $key = 'debt.' . (1 + count(xeric_debts_for($db, $ower)));
    xeric_arc_set($db, $ower, $key, json_encode([
        'to' => $to, 'what' => $what, 'formed' => $epoch, 'state' => 'open', 'times' => 1,
    ], JSON_UNESCAPED_UNICODE));
    $note('debts: ' . xeric_world_name($t, $ower) . ' owes ' . xeric_world_name($t, $to) . ' — ' . $what);
    return $key;
}

/**
 * Debts carried long enough stop being debts and become history.
 *
 * Not forgiven and not repaid — FADED, which is the honest third state: the
 * favour still happened, both of them still know, and nobody is keeping the
 * account open any more. Run from the same tick the fuses burn on.
 */
function xeric_debt_fade(array $t, PDO $db, array $now): int
{
    $epoch = (int)($now['epoch'] ?? 0);
    $n = 0;
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        foreach (xeric_debts_for($db, $h) as $d) {
            if ((string)$d['state'] !== 'open') continue;
            if ($epoch <= (int)($d['formed'] ?? 0) + XERIC_DEBT_FADE) continue;
            $d['state'] = 'faded'; $d['faded_at'] = $epoch;
            $row = $d; unset($row['key']);
            xeric_arc_set($db, $h, (string)$d['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
            $n++;
        }
    }
    return $n;
}

/**
 * WHAT YOU OWE, in this person's own prompt — the construct's whole point.
 *
 * Coarse and clockless like every other construct block: what it was for and
 * who it is owed to, never a date and never a countdown, so it is byte-stable
 * between ticks and cannot drag a prompt out of cache.
 */
function xeric_debt_block(array $t, PDO $db, string $handle): string
{
    $lines = [];
    foreach (xeric_debts_for($db, $handle) as $d) {
        if ((string)$d['state'] !== 'open') continue;
        $to = xeric_world_name($t, (string)$d['to']) ?: (string)$d['to'];
        $lines[] = '- You owe ' . $to . ' for ' . (string)$d['what'] . '.'
                 . ((int)($d['times'] ?? 1) > 1 ? ' More than once, now.' : '')
                 . ' You have not squared it. You would not call it a debt out loud.';
    }
    // And what is owed TO them, which is the other half of the same fact and
    // reads completely differently from the inside.
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || $h === $handle) continue;
        foreach (xeric_debts_for($db, $h) as $d) {
            if ((string)$d['state'] !== 'open' || (string)$d['to'] !== $handle) continue;
            $lines[] = '- ' . (xeric_world_name($t, $h) ?: $h) . ' owes you for ' . (string)$d['what']
                     . '. You have never mentioned it and you would not.';
        }
    }
    return $lines === [] ? '' : "WHAT IS OWED\n" . implode("\n", $lines);
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
 *
 * THIS IS THE CONSTRUCTS' ONE DOOR INTO THE PROMPT. prompt.php and the why
 * inspector both call this function and nothing else from this file, so a
 * new construct's block adjoins here rather than asking every caller to
 * learn a second name. The gossip lines ride below what she is owed —
 * ledger first, talk second, which is also how a person holds them.
 */
function xeric_expect_block(array $t, PDO $db, string $handle, array $now): string
{
    $blocks = [];
    $owed   = xeric_expect_owed($t, $db, $handle);
    if ($owed !== '') $blocks[] = $owed;

    // The debts fade on the same read the fuses burn on, for the same reason:
    // there is no clock in this engine, only somebody looking. A favour carried
    // long enough stops being an account and becomes history, which is the
    // honest third state — not forgiven, not repaid, just no longer counted.
    xeric_debt_fade($t, $db, $now);
    $debts = xeric_debt_block($t, $db, $handle);
    if ($debts !== '') $blocks[] = $debts;

    // Whether the person at the centre has been turning up to work, which is
    // the kind of thing a small town holds without ever being told. Silent
    // unless a world has a roster AND somebody turned the money dial up off
    // `none`, so it costs nothing in every world that is not about a job.
    require_once __DIR__ . '/work.php';
    $job = xeric_work_block($db, $t);
    if ($job !== '') $blocks[] = $job;

    // AND, IN A DISCUSSION ROOM, WHY THEY ARE IN IT. Adjoins here rather than
    // asking prompt.php and the why inspector to learn a second name, per this
    // function's stated one-door contract. Silent in every ordinary world.
    require_once __DIR__ . '/panel.php';
    $why = xeric_panel_block($t, $handle, $db);
    if ($why !== '') $blocks[] = $why;

    // And whether there is anybody else at the centre — silent in every world
    // until somebody is actually invited, which is every world today. A guest
    // the cast cannot see is a person being talked past.
    require_once __DIR__ . '/guest.php';
    $else = xeric_guest_block($db, $t);
    if ($else !== '') $blocks[] = $else;

    $talk = xeric_gossip_block($t, $db, $handle);
    if ($talk !== '') $blocks[] = $talk;
    return implode("\n\n", $blocks);
}

/** The WHAT YOU ARE OWED half of the block, or '' when nothing is. */
function xeric_expect_owed(array $t, PDO $db, string $handle): string
{
    $rows = xeric_expects_for($db, $handle);
    if ($rows === []) return '';
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'them';
    // Whoever actually promised: the person at the centre, or somebody in
    // the town. A line that said "Neil" about a promise Harlan made would be
    // the one sentence in this block that could not be true.
    $who = function (array $e) use ($t, $userName): string {
        $of = trim((string)($e['of'] ?? ''));
        return $of === '' ? $userName : (xeric_world_name($t, $of) ?: $of);
    };
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
                $lines[] = '- ' . $who($e) . ' said "' . $e['quote'] . '" — you are expecting them ' . $day($e['due'])
                    . ' (' . $e['what'] . ').' . (isset($e['heard_as']) ? ' You heard it as ' . $e['heard_as'] . '.' : '');
                break;
            case 'missed':
                $lines[] = '- ' . $who($e) . ' said "' . $e['quote'] . '" and did not come ' . $day($e['due'])
                    . ', and has not said why. It stung more than you let on. You never narrate this; '
                    . 'it shows in small ways — what your hands do, what you leave unsaid, how long you take to answer.';
                break;
            case 'repaired':
                $lines[] = '- ' . $who($e) . ' missed ' . $e['what'] . ' ' . $day($e['due'])
                    . ' but told you why. How settled that is between you is yours to carry, in your own way.';
                break;
            case 'hardened':
                $lines[] = '- ' . $who($e) . ' never did say anything about missing ' . $e['what']
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
    $out   = ['missed' => 0, 'hardened' => 0, 'gossip_born' => 0, 'gossip_spread' => 0, 'gossip_faded' => 0];
    $user  = trim((string)($t['user']['name'] ?? '')) ?: 'they';
    $gone  = xeric_deaths($db);

    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || !empty($c['out'])) continue;
        // The dead wait for nothing — and DEATH SETTLES THE LEDGER. Skipping a
        // dead holder used to leave an open fuse `open` forever, which read as
        // safe right up until a revive: the next tick found the fuse burnt,
        // fired the miss, and backdated the whole package to the due hour —
        // "she waited at the diner" stamped fifty minutes after her own death
        // event, a memory of an evening spent dead, and a trust mark to go
        // with it. So an open expectation held by the dead is RELEASED, here,
        // quietly: a terminal state, no event, no memory, no trust — a promise
        // to the dead is released, not broken. A miss that already fired while
        // they lived keeps its history; that grudge really happened.
        if (isset($gone[$h])) {
            foreach (xeric_expects_for($db, $h) as $e) {
                if ($e['state'] !== 'open') continue;
                $e['state'] = 'released'; $e['released_at'] = $epoch;
                $row = $e; unset($row['key']);
                xeric_arc_set($db, $h, $e['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
                $note('expectations: ' . xeric_world_name($t, $h)
                    . ' died holding one, and it died with them');
            }
            continue;
        }
        foreach (xeric_expects_for($db, $h) as $e) {
            if ($e['state'] === 'open' && $epoch > $e['due'] + XERIC_EXPECT_GRACE) {
                $name  = xeric_world_name($t, $h);
                // WHO DID NOT COME. Absent means the person at the centre;
                // a cast handle means one of the town let another down, and
                // everything below has to say so — the memory the waiter
                // keeps, the trust that moves, and the why-trail the
                // inspector reads. Only the EVENT stays the same, because
                // what anybody saw is a person watching a door either way.
                $of      = trim((string)($e['of'] ?? ''));
                $whoName = $of === '' ? $user : (xeric_world_name($t, $of) ?: $of);
                $place = xeric_world_who_is_where($t, xeric_world_now($t, $e['due']))[$h]['where'] ?? null;
                $at    = $place !== null ? ' at ' . xeric_world_place_name($t, (string)$place) : '';
                $hhmm  = xeric_world_now($t, $e['due'])['hhmm'] ?? '';

                $db->beginTransaction();
                try {
                    $eid = xeric_event_add($db, $name . ' waited' . $at, $e['due'],
                        $place !== null ? (string)$place : null, [$h],
                        $name . ' kept half an eye on the door' . $at . ' past ' . $hhmm
                        . '. Left later than usual, and did not say why.');
                    xeric_memory_add($db, $h, $whoName . ' said "' . $e['quote'] . '" and did not come.',
                        'construct', ['expect' => $e['key']] + ($of !== '' ? ['of' => $of] : []), $e['due']);
                    // Through the earned path, which is bounded at the far
                    // ends: a broken promise is one of the things ordinary
                    // conversation cannot undo (engine/trust.php).
                    // Against the person who actually missed: a townsperson
                    // standing somebody up must never cost the PLAYER their
                    // standing (engine/trust.php's two rows).
                    xeric_trust_earn($db, $h, -1, null, $of !== '' ? $of : null);
                    $e['state'] = 'missed'; $e['missed_at'] = $epoch;
                    $row = $e; unset($row['key']);
                    xeric_arc_set($db, $h, $e['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
                    // The inspector's answer, in the trail the why view already
                    // reads: a grudge that cannot explain itself is the model
                    // being moody; this one can.
                    xeric_world_state_set($db, 'why:event:' . $eid, json_encode([
                        'kind' => 'missed_promise',
                        'why'  => $name . ' expected ' . $whoName . ' (' . $e['what'] . ' — "' . $e['quote'] . '"). '
                                . $whoName . ' did not come. The event states only what anyone could see; '
                                . 'the feeling rides ' . $name . '\'s own prompt, not the record.',
                    ], JSON_UNESCAPED_UNICODE));
                    $db->commit();
                } catch (Throwable $ex) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $ex;
                }
                $out['missed']++;
                $note('expectations: ' . $name . ' waited, and ' . $whoName . ' did not come');
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

    // The ripple rides the same heartbeat: scan for gossip-worthy hours AFTER
    // the fuses have fired (so a miss written this tick can be seen this
    // tick), then let every live item travel one hop and put the stale ones
    // down. All of it deterministic reads — no model, cheap, idempotent.
    if (xeric_gossip_armed($t)) {
        $out['gossip_born'] = xeric_gossip_scan($t, $db, $now, $gone, $note);
        $g = xeric_gossip_spread($t, $db, $now, $gone, $note);
        $out['gossip_spread'] = $g['spread'];
        $out['gossip_faded']  = $g['faded'];
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
        // THEIR OWN, and only their own. This is the user explaining
        // themselves; a miss owed by somebody in the town is not theirs to
        // apologise for, and repairing it here would hand the player credit
        // for Harlan turning up.
        if (trim((string)($e['of'] ?? '')) !== '') continue;
        if ($best === null || ($e['missed_at'] ?? $e['due']) > ($best['missed_at'] ?? $best['due'])) $best = $e;
    }
    if ($best === null) return null;

    $name = xeric_world_name($t, $handle);
    $best['state'] = 'repaired'; $best['explained_at'] = (int)($now['epoch'] ?? 0);
    $key = $best['key']; $row = $best; unset($row['key']);
    xeric_arc_set($db, $handle, $key, json_encode($row, JSON_UNESCAPED_UNICODE));
    xeric_trust_earn($db, $handle, +1);
    xeric_memory_add($db, $handle, $user . ' told ' . $name . ' why they missed ' . $best['what'] . '.',
        'construct', ['expect' => $key], (int)($now['epoch'] ?? 0));
    $note('expectations: ' . $user . ' explained ' . $best['what'] . ' — repaired');
    return $key;
}

// ---------------------------------------------------------------------------
// The gossip ripple — what the town does with what it saw
// ---------------------------------------------------------------------------

/** Is the ripple armed here? Same rule as expectations: no systems record predates arming and gets it. */
function xeric_gossip_armed(array $t): bool
{
    $armed = $t['forge']['armed'] ?? null;
    if (!is_array($armed)) return true;                   // hand-built or pre-systems world
    return in_array('rumors', array_map('strval', $armed), true);
}

/**
 * Is this hour's prose charged enough to be worth repeating?
 *
 * The promise test's twin, pointed the other way. Word-boundary, case-blind,
 * on the event's own words — a linguistic rule enforced by regex is a fact —
 * but where the hedge list should grow when in doubt, this one should
 * SHRINK: a charged hour missed costs one item of gossip, and a quiet hour
 * that ripples is a town that talks about the weather like a house fire.
 * Every word here is an OBSERVABLE — a thing a stranger at the next table
 * could report — because that is the only kind of hour the ripple carries.
 */
function xeric_gossip_charged(string $s): bool
{
    $q = ' ' . mb_strtolower($s) . ' ';
    foreach ([
        'shouted', 'screamed', 'screaming', 'slammed', 'stormed out', 'threw a punch',
        'swung at', 'punched', 'in tears', 'wept', 'sobbed', 'collapsed', 'fainted',
        'ambulance', 'police', 'sheriff', 'arrested', 'on fire', 'caught fire',
        'crashed', 'kissed', 'walked out on', 'went missing',
    ] as $w) {
        if (preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/u', $q)) return true;
    }
    return false;
}

/**
 * Are these words commons — or do they quote somebody's interior?
 *
 * The formation gate that makes "a wall-protected secret never becomes an
 * item" a property instead of a hope. Checked against EVERY interior string
 * the template holds, not one viewer's walls, because gossip has every
 * viewer: a line safe for the town is a line safe for the most protected
 * person in it, and anything less fails open somewhere. Reuses the walls'
 * own quotation test (a six-word run, or the whole of a short field), so
 * true sentences that merely name the diner pass and a sentence that
 * carries a secret out does not.
 */
function xeric_gossip_commons(array $t, string $s): bool
{
    $hay = xeric_wall_words($s);
    if ($hay === []) return true;
    foreach (xeric_wall_interiors($t) as $strings) {
        foreach ($strings as $needle) {
            if (xeric_wall_quotes($hay, xeric_wall_words($needle))) return false;
        }
    }
    return true;
}

/**
 * The line that travels: the event's own title, coarsened in code.
 *
 * Only ever REMOVES — a clock scrubbed out (coarse state never carries one),
 * whitespace collapsed, length capped. Nothing is added, reworded or
 * dressed up, because "a coarser retelling derived from it in code, never
 * enriched" is the entire license this construct has to put words in the
 * town's mouth.
 */
function xeric_gossip_line(string $title): string
{
    $line = (string)preg_replace('/\s*(?:past|at|by|until|till)?\s*(?<!\d)\d{1,2}:\d{2}(?!\d)\s*/u', ' ', $title);
    $line = trim((string)preg_replace('/\s+/u', ' ', $line));
    return mb_substr($line, 0, 120);
}

/**
 * Every gossip item, parsed, oldest first, keyed by arc key.
 *
 * Items are world-scoped arcs — gossip.1, gossip.2 — because an item is ONE
 * thing the town knows, however many heads hold it; the knower set lives
 * inside. gossip.seen is the scan watermark, not an item, and is skipped.
 */
function xeric_gossip_items(PDO $db): array
{
    $byN = [];
    foreach (xeric_arcs_prefixed($db, xeric_arc_world(), 'gossip.') as $k => $v) {
        $n = substr((string)$k, strlen('gossip.'));
        if (!ctype_digit($n)) continue;
        $row = json_decode((string)$v, true);
        if (!is_array($row) || !isset($row['line'], $row['state'])) continue;
        $row['key'] = (string)$k;
        $byN[(int)$n] = $row;
    }
    ksort($byN);                       // numeric, so gossip.10 does not outrank gossip.2
    $out = [];
    foreach ($byN as $row) $out[$row['key']] = $row;
    return $out;
}

/**
 * Does a wall keep this item from this would-be knower?
 *
 * The existing wall reads, consumed, never re-derived: a knower whose walls
 * hide the room it happened in never hears about the room, and a line that
 * quotes anything their walls remove is blocked for them by the same
 * loose-prose test the prompts use. An unresolvable handle picks up the
 * unknown-viewer floor on the way through xeric_viewer_walls(), which is
 * the fail-closed direction — nobody gossips their way past a typo.
 *
 * FOUR PATHS, NOT ONE. This gate used to check places.<key> plus the literal
 * quotation test and nothing else, which made the ripple the one character-
 * facing surface in the engine that skipped the protected-secret needle — so a
 * PARAPHRASE of somebody's must_not_know ("harlan shouted about the mill
 * landing", six words, no six-word run of the eight-word secret) rode a hop
 * straight into the protected head's own prompt and, when the item faded, into
 * a permanent memory. The `schedules` wall had the same hole one door over: a
 * line seating a name in a room is who-is-where in its Sunday clothes, and it
 * walked past a viewer the now-block refuses to name a single room to.
 */
function xeric_gossip_wall_blocked(array $t, array $item, string $handle): bool
{
    // Lazy, the way prompt.php pulls this file in for its gossip block: the
    // needle helpers live in sweeps.php and every real caller has it loaded —
    // this keeps the one that does not honest without a top-level cycle.
    require_once __DIR__ . '/sweeps.php';

    $line = (string)($item['line'] ?? '');

    // The needle first, before walls are even resolved: a protected head may
    // not receive its own secret, and the loose word-overlap test is the one
    // that survives a paraphrase. Same helper, same threshold, as the sweep,
    // the room, the duet and the prompt.
    $protected = xeric_sweep_protected($t);
    if (isset($protected[$handle]) && xeric_sweep_touches($line, $protected[$handle])) return true;

    $walls = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $handle]));
    if ($walls === []) return false;
    $place = (string)($item['place'] ?? '');
    if ($place !== '') {
        // The pair every other room-knowledge consumer checks together
        // (prompt.php, room.php, duet.php): the specific room, and `schedules`
        // — who is where at all.
        if (xeric_hidden($walls, 'places.' . $place)) return true;
        if (xeric_hidden($walls, 'schedules')) return true;
    }
    return xeric_quotes_walled($t, $walls, $line) !== '';
}

/**
 * Read the hours that landed since the last look, and turn the gossip-worthy
 * ones into items. Deterministic gates only:
 *
 *   - a miss (the why-trail this file writes says `missed_promise`);
 *   - a death (every participant of the hour is in the deaths ledger);
 *   - an entrance (enter.php's own fixed title, "<name> turned up today" —
 *     a literal shared with that file on purpose, it being the one seam an
 *     entrance leaves in the record);
 *   - any placed hour whose commons words are charged (xeric_gossip_charged).
 *
 * Never: a spine hour (the one kind of title written to NAME the thing a
 * wall protects), a dream (an interior wearing an event's clothes), an hour
 * whose words quote anybody's interior, an hour already older than the fuse
 * (news nobody told in six days was never news), or an hour nobody saw.
 *
 * Firsthand knowers are whoever the week data puts at the scene at the
 * event's own hour, plus the participants; for an hour with no place (a
 * death writes none) the scene is wherever each participant stood. The one
 * raw SELECT is here because state.php has no since-id reader and this file
 * may not grow one into it; fetchAll-then-let-go per its discipline.
 */
function xeric_gossip_scan(array $t, PDO $db, array $now, array $gone, ?callable $onNote = null): int
{
    $note  = $onNote ?? static function (string $s): void {};
    $epoch = (int)($now['epoch'] ?? 0);
    $seen  = (int)xeric_arc_get($db, xeric_arc_world(), 'gossip.seen', '0');

    $q = $db->prepare('SELECT * FROM events WHERE id > ? ORDER BY id');
    $q->execute([$seen]);
    $events = $q->fetchAll();
    if ($events === []) return 0;

    $n    = count(xeric_gossip_items($db));
    $born = 0;
    $last = $seen;

    foreach ($events as $ev) {
        $eid  = (int)$ev['id'];
        $last = max($last, $eid);
        if ((int)($ev['on_spine'] ?? 0) === 1) continue;   // spine hours never ripple

        $when  = (int)$ev['world_epoch'];
        if ($epoch > $when + XERIC_GOSSIP_FUSE) continue;  // stale before anybody told it

        $title = (string)$ev['title'];
        $prose = (string)($ev['prose'] ?? '');
        $place = ($ev['place'] ?? null) !== null && (string)$ev['place'] !== '' ? (string)$ev['place'] : null;
        $parts = json_decode((string)($ev['participants'] ?? '[]'), true);
        $parts = is_array($parts) ? array_values(array_map('strval', $parts)) : [];

        $whyKind = '';
        $whyRaw  = xeric_world_state_get($db, 'why:event:' . $eid);
        if ($whyRaw !== null) {
            $why = json_decode((string)$whyRaw, true);
            if (is_array($why)) $whyKind = (string)($why['kind'] ?? '');
        }
        if ($whyKind === 'dream') continue;                // an interior wearing an event's clothes

        $kind = '';
        if ($whyKind === 'missed_promise') {
            $kind = 'missed_promise';
        } elseif ($parts !== [] && array_diff($parts, array_keys($gone)) === []) {
            $kind = 'death';
        } elseif (count($parts) === 1 && str_ends_with($title, ' turned up today')) {
            $kind = 'entrance';
        } elseif ($place !== null && $parts !== [] && xeric_gossip_charged($title . ' ' . $prose)) {
            $kind = 'charged';
        }
        if ($kind === '') continue;

        if (!xeric_gossip_commons($t, $title . ' ' . $prose)) {
            $note('gossip: an hour at event ' . $eid . ' carried somebody\'s interior — it does not travel');
            continue;
        }
        $line = xeric_gossip_line($title);
        if ($line === '') continue;

        // Who was there. Presence at the event's OWN hour, dead list empty on
        // purpose: the now-dead were alive then, and for a death the dying
        // person's own placement is what names the scene.
        $then   = xeric_world_now($t, $when);
        $pres   = xeric_world_who_is_where($t, $then);
        $scenes = [];
        if ($place !== null) {
            $scenes[$place] = true;
        } else {
            foreach ($parts as $p) {
                $w = $pres[$p]['where'] ?? null;
                if ($w !== null) $scenes[(string)$w] = true;
            }
        }
        $cands = [];
        foreach ($parts as $p) $cands[$p] = true;
        foreach (array_keys($scenes) as $s) {
            foreach (xeric_world_who_is_at($pres, (string)$s) as $h) $cands[$h] = true;
        }

        $item = ['kind' => $kind, 'event' => $eid, 'line' => $line, 'place' => $place,
                 'participants' => $parts, 'born' => $when, 'state' => 'live', 'knowers' => []];
        foreach (array_keys($cands) as $h) {
            $h = (string)$h;
            $c = xeric_world_character($t, $h);
            if ($c === null || !empty($c['out']) || isset($gone[$h])) continue;
            // Firsthand passes the wall too. A wall says this world never
            // shows them that domain; the construct must not be the leak,
            // even about an hour the week data says they stood inside.
            if (xeric_gossip_wall_blocked($t, $item, $h)) continue;
            $item['knowers'][] = ['who' => $h, 'hop' => 0];
        }
        if ($item['knowers'] === []) continue;             // an hour nobody saw is an hour nobody can retell

        $n++;
        xeric_arc_set($db, xeric_arc_world(), 'gossip.' . $n, json_encode($item, JSON_UNESCAPED_UNICODE));
        $born++;
        $note('gossip: the town has hold of it — "' . $line . '"');
    }
    if ($last !== $seen) xeric_arc_set($db, xeric_arc_world(), 'gossip.seen', $last);
    return $born;
}

/**
 * One hop for every live item, and the fuse for the stale ones.
 *
 * The spread is the week data answering "who is standing with a knower right
 * now": each teller still fresh enough to retell (hop < REACH) reaches
 * exactly the people sharing their place this tick, walls willing. Tellers
 * are snapshotted before anybody new is added — news crosses one hop per
 * tick, which IS the per-hop fade made mechanical — and sorted freshest
 * first, so somebody standing between two knowers hears it from the one
 * closest to the event and the attribution stays as good as it can be.
 * Nobody is added twice, nothing here reads a model, and a tick that finds
 * nobody co-located moves nothing: news never teleports.
 */
function xeric_gossip_spread(array $t, PDO $db, array $now, array $gone, ?callable $onNote = null): array
{
    $note  = $onNote ?? static function (string $s): void {};
    $epoch = (int)($now['epoch'] ?? 0);
    $out   = ['spread' => 0, 'faded' => 0];
    $items = xeric_gossip_items($db);
    if ($items === []) return $out;

    $pres = null;                                          // resolved once, only if a live item needs it
    foreach ($items as $key => $item) {
        if (($item['state'] ?? '') !== 'live') continue;

        if ($epoch > (int)$item['born'] + XERIC_GOSSIP_FUSE) {
            $out['faded'] += xeric_gossip_fade($t, $db, (string)$key, $item, $gone, $note);
            continue;
        }

        $pres ??= xeric_world_who_is_where($t, $now, array_keys($gone));
        $known = [];
        foreach ((array)$item['knowers'] as $k) $known[(string)$k['who']] = true;
        $tellers = (array)$item['knowers'];                // snapshot: the newly told wait a tick to retell
        usort($tellers, fn($a, $b) => (int)($a['hop'] ?? 0) <=> (int)($b['hop'] ?? 0));

        $names = [];
        foreach ($tellers as $tell) {
            if ((int)($tell['hop'] ?? 0) >= XERIC_GOSSIP_REACH) continue;   // too thin to pass on
            $w = $pres[(string)$tell['who']]['where'] ?? null;
            if ($w === null) continue;
            foreach ($pres as $h => $row) {
                $h = (string)$h;
                if (($row['where'] ?? null) !== $w || $h === (string)$tell['who'] || isset($known[$h])) continue;
                if (xeric_gossip_wall_blocked($t, $item, $h)) continue;
                $item['knowers'][] = ['who' => $h, 'hop' => (int)$tell['hop'] + 1, 'from' => (string)$tell['who']];
                $known[$h] = true;
                $names[]   = xeric_world_name($t, $h);
                $out['spread']++;
            }
        }
        if ($names !== []) {
            $row = $item;
            unset($row['key']);
            xeric_arc_set($db, xeric_arc_world(), (string)$key, json_encode($row, JSON_UNESCAPED_UNICODE));
            $note('gossip: "' . $item['line'] . '" reached ' . xeric_join_list($names));
        }
    }
    return $out;
}

/**
 * The fuse: old news stops traveling, leaves every prompt, and a knower who
 * CARED — whose own memories already mention somebody the item is about —
 * keeps one ordinary memory of the talk. Participants keep nothing here:
 * they lived the hour, and the real memory of it is already theirs. The
 * residue is stamped at the hour the talk actually died down (born + fuse),
 * however late the tick that noticed — the same discipline as a miss.
 */
function xeric_gossip_fade(array $t, PDO $db, string $key, array $item, array $gone, ?callable $onNote = null): int
{
    $note  = $onNote ?? static function (string $s): void {};
    $done  = (int)$item['born'] + XERIC_GOSSIP_FUSE;
    $parts = array_map('strval', (array)($item['participants'] ?? []));

    foreach ((array)$item['knowers'] as $k) {
        $h = (string)$k['who'];
        if (in_array($h, $parts, true) || isset($gone[$h])) continue;
        if (!xeric_gossip_cared($t, $db, $h, $parts)) continue;
        xeric_memory_add($db, $h, 'It went around for days that ' . $item['line'] . '.',
            'construct', ['gossip' => $key, 'event' => (int)($item['event'] ?? 0)], $done);
    }

    $row = $item;
    unset($row['key']);
    $row['state']    = 'faded';
    $row['faded_at'] = $done;
    xeric_arc_set($db, xeric_arc_world(), $key, json_encode($row, JSON_UNESCAPED_UNICODE));
    $note('gossip: "' . $item['line'] . '" has gone quiet');
    return 1;
}

/**
 * Did this knower care? Their memories mention somebody the item is about —
 * any word of a participant's name, three letters or longer, whole-word and
 * case-blind, across what they carry. Deterministic and deliberately
 * generous to first names ("Ruth" carries as much as "Ruth Amberg"); a
 * common-word name over-matching costs one warm memory of the town talking,
 * which is the cheap direction to be wrong in.
 */
function xeric_gossip_cared(array $t, PDO $db, string $handle, array $participants): bool
{
    if ($participants === []) return false;
    $words = [];
    foreach ($participants as $p) {
        foreach (preg_split('/\s+/u', xeric_world_name($t, (string)$p)) ?: [] as $w) {
            $w = mb_strtolower(trim($w));
            if (mb_strlen($w) >= 3) $words[$w] = preg_quote($w, '/');
        }
    }
    if ($words === []) return false;
    $re = '/(?<![a-z])(?:' . implode('|', $words) . ')(?![a-z])/u';
    foreach (xeric_memories_for($db, $handle, 200) as $m) {
        if (preg_match($re, mb_strtolower((string)$m['text']))) return true;
    }
    return false;
}

/**
 * The lines a knower's prompt carries. At most ONE per fresh item, no
 * clocks, and byte-stable between ticks by construction: nothing here reads
 * the clock at all — an item leaves this block when the TICK fades it, so
 * the text changes only when the state does and the prefix cache pays one
 * rebuild per real transition, same as the ledger above it.
 *
 * The attribution thins with the hops, which is the honesty of the thing:
 * an eyewitness names what they saw, the first retelling still has a name
 * on it, and past that it is just what people are saying. Your own hours
 * are not gossip to you — a participant gets no line about themselves; the
 * ledger and their memories already carry the real thing.
 */
function xeric_gossip_block(array $t, PDO $db, string $handle): string
{
    $lines = [];
    foreach (xeric_gossip_items($db) as $item) {
        if (($item['state'] ?? '') !== 'live') continue;
        if (in_array($handle, array_map('strval', (array)($item['participants'] ?? [])), true)) continue;
        $mine = null;
        foreach ((array)$item['knowers'] as $k) {
            if ((string)$k['who'] === $handle) { $mine = $k; break; }
        }
        if ($mine === null) continue;

        $line = (string)$item['line'];
        $hop  = (int)($mine['hop'] ?? 0);
        $from = xeric_world_name($t, (string)($mine['from'] ?? ''));
        if ($hop === 0) {
            $lines[] = '- You saw it yourself: ' . $line . '.';
        } elseif ($hop === 1 && $from !== '') {
            $lines[] = '- ' . $from . ' told you ' . $line . '.';
        } else {
            $lines[] = '- People are saying ' . $line . '.';
        }
    }
    if ($lines === []) return '';
    return "WHAT PEOPLE ARE SAYING\n" . implode("\n", $lines) . "\n"
        . 'You may pass these along, chew them over together, or keep them to yourself. '
        . 'You know only what is written here — what was seen, never what anybody felt.';
}

/**
 * The inspector's answer, off the item's own arc: who saw it, and the path
 * every retelling took — so "the whole town is suddenly on about Ruth" reads
 * as a ripple with a source instead of the model being chatty.
 */
function xeric_gossip_why(array $t, array $item): string
{
    $saw  = [];
    $path = [];
    foreach ((array)($item['knowers'] ?? []) as $k) {
        $who = xeric_world_name($t, (string)$k['who']);
        if ((int)($k['hop'] ?? 0) === 0) {
            $saw[] = $who;
        } else {
            $path[] = $who . ' heard it from ' . xeric_world_name($t, (string)($k['from'] ?? ''))
                    . ' (hop ' . (int)$k['hop'] . ')';
        }
    }
    $s = 'gossip (' . (string)($item['kind'] ?? '') . ', event ' . (int)($item['event'] ?? 0) . '): '
       . ($saw !== [] ? xeric_join_list($saw) . ' saw it firsthand' : 'nobody saw it firsthand');
    if ($path !== []) $s .= '; ' . implode('; ', $path);
    return $s . '. What travels is the hour\'s own commons words — "' . (string)($item['line'] ?? '')
        . '" — never what anybody felt.';
}
