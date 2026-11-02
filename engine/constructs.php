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
 * ── KEEPING IT ───────────────────────────────────────────────────────────
 * An expectation used to have only bad endings: open could become missed (and
 * then repaired or hardened) or, for the dead, released. Nothing ever closed
 * one because the promised thing HAPPENED, so the user could walk into the
 * Bluebird on Friday morning with Ruth's casserole dish in hand, and four
 * hours later the fuse burned anyway: Ruth "waited", lost a notch of trust,
 * and said nothing about it in small ways. A ledger that can only record
 * failure is not a relationship, it is a grievance generator.
 *
 * So `kept` is a fourth terminal state, reached two ways, both gated in code:
 *   1. THE BODY WAS THERE. On the day the promise was for (from that local
 *      midnight to the miss), the person who promised stands where the
 *      listener is: the player's body (`player.where`, arrived AFTER the
 *      promise was made, so a promise said across the counter is not kept by
 *      the act of saying it) or, for a promise between two townspeople, their
 *      own week putting them in the same room. This is read off position and
 *      schedule — a fact, no model.
 *   2. THE EXTRACTOR HEARD IT DONE. The same harvest that reports `explained`
 *      reports `kept`: the user did, in this conversation, the thing they had
 *      promised. The model spots it; this file decides whether there is an
 *      open expectation of THAT player, formed before this turn, whose miss has
 *      not fired, and closes the soonest one. Symmetric with repair.
 * Keeping it is the mirror of missing it, by the same path: one notch of trust
 * through xeric_trust_earn (bounded at the far ends — trust.php: past the
 * contact band "only the things that cost something move it: a promise kept"),
 * against whoever actually promised, and one ordinary memory. No event: a
 * person turning up as they said is not something the town retells, and
 * gossip feeds on what was charged. The block says so in one coarse line, so
 * the listener's prompt and the inspector read "kept", never "expecting".
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
require_once __DIR__ . '/players.php'; // and whose promise it was

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
function xeric_expect_form(array $t, PDO $db, string $listener, ?array $p, array $now,
                           ?callable $onNote = null, ?string $of = null,
                           int $player = XERIC_PLAYER_FIRST): ?string
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
    // BORN MISSED IS NOT A PROMISE. A due already behind the moment it forms —
    // the hour it was said, or the later moment a caller is reading it at
    // (`not_before`) — would be fired as a miss by the next fuse tick for a
    // wait nobody did. Refused, not clamped: nobody was let down.
    $floor = max((int)($now['epoch'] ?? 0), (int)($p['not_before'] ?? 0));
    if ($due <= $floor) { $note('expectations: "' . $whenS . '" is already past — nothing formed'); return null; }

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
      + ($of !== null && $of !== '' ? ['of' => $of] : [])
        // WHOSE promise, when there is more than one person at the centre. The
        // first player is the bare case and writes nothing, so every row already
        // on disk is already correct (engine/players.php).
        + ($player > XERIC_PLAYER_FIRST ? ['p' => $player] : []), JSON_UNESCAPED_UNICODE));
    $note('expectations: ' . xeric_world_name($t, $listener) . ' now expects — ' . $what);
    // AND SHE LOOKS FORWARD TO IT. The first sibling rides the same row: a plan
    // the person at the centre made is something to anticipate as well as
    // something to wait on. The town's own promises do not — anticipation is
    // the warm half of being expected, and it is the player who is expected.
    if (($of === null || $of === '') && xeric_sibling_armed($t, 'anticipation')) {
        xeric_anticipate_form($t, $db, $listener, $key, $now);
    }
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
 * @param int $notBefore a due at or before this epoch forms nothing (the cast
 *                       tick passes its own now; 0 means the scene's hour only)
 * @return string[] the arc keys formed
 */
function xeric_expect_from_scene(array $t, PDO $db, array $lines, array $now, ?callable $onNote = null,
                                 int $notBefore = 0): array
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
        //
        // THE PLAYER IS NEVER THE LISTENER. Expectations are rows a CHARACTER
        // holds; '__you' (the room's and the watch's handle for the person at
        // the table) holds nothing. A promise the player made, on the other
        // hand, is exactly the v1 row — of nobody, which means the user.
        // Every person at the centre wears a '__you' handle ('__you' for the
        // first, '__you.p<n>' after — room.php); none of them is a listener.
        $isYou  = static fn(string $h): bool => $h === '__you' || str_starts_with($h, '__you.p');
        $others = array_values(array_filter(array_keys($present), fn($h) => $h !== $speaker && !$isYou((string)$h)));
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
                    $first = xeric_constructs_first_name($n);
                    if ($first !== '' && preg_match('/(?<![\p{L}])' . preg_quote($first, '/') . '(?![\p{L}])/ui', $text)) {
                        $listener = $o; break 2;
                    }
                }
            }
        }
        if ($listener === '') continue;   // said to the room is said to nobody in particular

        // THROUGH THE WALLS. A promise is a quote, and a quote renders verbatim
        // into the listener's prompt for as long as the row lives. A line that
        // carries something the listener's walls remove forms nothing for them —
        // the same needle and quotation test the ripple uses, failing closed.
        if (xeric_gossip_wall_blocked($t, ['line' => $text], $listener)) continue;

        $key = xeric_expect_form($t, $db, $listener, [
            'quote' => $text,
            'what'  => mb_substr($text, 0, 60),
            'when'  => xeric_promise_when_phrase($text),
        ] + ($notBefore > 0 ? ['not_before' => $notBefore] : []), $now, $onNote, $isYou($speaker) ? null : $speaker,
            // WHICH person at the centre promised: the line's own player.
            $isYou($speaker) ? max(XERIC_PLAYER_FIRST, (int)($l['player'] ?? XERIC_PLAYER_FIRST)) : XERIC_PLAYER_FIRST);
        if ($key !== null) $formed[] = $key;
    }
    return $formed;
}

/**
 * The name a person is called by in a sentence: the first word of their name,
 * past an honorific — "Pastor Dale Ostrander" is Dale across a table.
 */
function xeric_constructs_first_name(string $name): string
{
    $forms = xeric_world_name_forms($name);     // the one honorific list (world.php)
    return (string)($forms[1] ?? $forms[0] ?? '');
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
        if (!preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/u', $t)) continue;
        // A day with a time of day said beside it carries the hour, so a
        // town's "Wednesday morning" is due at nine, not at midnight.
        if (str_ends_with($w, 'day') && $w !== 'today'
            && preg_match('/(?<![a-z])' . $w . '\s+(?:in the\s+|at\s+)?(morning|noon|afternoon|evening|night)(?![a-z])/u', $t, $m)) {
            return $w . ' ' . $m[1];
        }
        return $w;
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
function xeric_expect_block(array $t, PDO $db, string $handle, array $now,
                            int $player = XERIC_PLAYER_FIRST): string
{
    $blocks = [];
    $owed   = xeric_expect_owed($t, $db, $handle);
    if ($owed !== '') $blocks[] = $owed;

    // What this person is keeping for somebody, and what they asked somebody
    // to keep. Their own rows, so no wall applies to the reader — and a
    // confidence renders ONLY here, in the prompts of the two people it is
    // between: nobody else's prompt is built from these rows.
    require_once __DIR__ . '/confide.php';
    $kept = xeric_confide_block($t, $db, $handle, $player);
    if ($kept !== '') $blocks[] = $kept;

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
    $job = xeric_work_block($db, $t, $player);
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
    // Excluding whoever this prompt is FOR: a character texting Corey is told
    // who else is standing about, not told that Corey is here — she is the one
    // they are talking to.
    $else = xeric_guest_block($db, $t, $player);
    if ($else !== '') $blocks[] = $else;

    // The siblings: what is on their mind — a plan ahead, news not yet told,
    // a worry, a grudge, a habit of yours, a count crossed. Coarse state only.
    $mind = xeric_siblings_block($t, $db, $handle, $player);
    if ($mind !== '') $blocks[] = $mind;

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
            case 'kept':
                // Said once and plainly. Not gratitude on cue — a person who
                // turned up as they said is just a person who turned up, and the
                // weight is that nothing here is owed any more.
                $lines[] = '- ' . $who($e) . ' said "' . $e['quote'] . '" and did, ' . $day($e['due'])
                    . ' (' . $e['what'] . '). Nothing is owed on it.';
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
    $presence = $body = null;                         // resolved once, on first need (below)

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
        // KEPT BEFORE MISSED, in the same tick: the body being where it said
        // it would be is checked first, so a tick that finds the user standing
        // at the diner closes the promise rather than burning it.
        //
        // ONE READ OF THE TOWN PER TICK. Who is where (the whole cast against
        // the deaths) and where the body is are the same for every holder at
        // one instant, so they are resolved once, the first time a holder has
        // anything open, and handed down with the rows already read — the
        // keep used to recompute both, and re-read the rows, once per handle.
        $rows = xeric_expects_for($db, $h);
        $open = array_values(array_filter($rows, fn($e) => $e['state'] === 'open'));
        $kept = [];
        if ($open !== []) {
            $presence ??= xeric_constructs_presence($t, $now, $gone);
            $body     ??= xeric_constructs_body($t, $db);
            $kept = xeric_expect_keep_present($t, $db, $h, $now, $note, $open, $presence, $body);
        }
        // A keep changed a row's state; only then is there anything to re-read.
        foreach ($kept === [] ? $rows : xeric_expects_for($db, $h) as $e) {
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
                    // AND AGAINST THE PERSON WHO ACTUALLY PROMISED. With two
                    // people at the centre, a guest breaking their word must not
                    // cost the owner their standing — the same sentence the
                    // cast-to-cast fix was about, one row further out.
                    xeric_trust_earn($db, $h, -1, null, $of !== '' ? $of : null,
                                     (int)($e['p'] ?? XERIC_PLAYER_FIRST));
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
                // Whoever actually missed it: this said the player's name about
                // Harlan's promise once cast-to-cast rows could harden.
                $of2  = trim((string)($e['of'] ?? ''));
                $who2 = $of2 === '' ? $user : (xeric_world_name($t, $of2) ?: $of2);
                xeric_memory_add($db, $h,
                    'It sat with ' . $name . ' that ' . $who2 . ' never said anything about ' . $e['what'] . '.',
                    'construct', ['expect' => $e['key']], $epoch);
                $e['state'] = 'hardened';
                $row = $e; unset($row['key']);
                xeric_arc_set($db, $h, $e['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
                // THE GRUDGE. What a hardened miss becomes, as a construct with a
                // temperature rather than a line that never changes.
                if (xeric_sibling_armed($t, 'grudge')) {
                    xeric_grudge_form($t, $db, $h, $of2, (int)($e['p'] ?? XERIC_PLAYER_FIRST),
                        'missing ' . $e['what'], $e['key'], $now);
                }
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

    // THE SIBLINGS, after the fuses (an anticipation reads its expectation's
    // fate) and on the same read of the body.
    $out['siblings'] = xeric_siblings_tick($t, $db, $now, $gone, $note, $body);
    return $out;
}

// ---------------------------------------------------------------------------
// Keeping it
// ---------------------------------------------------------------------------

/**
 * Close the soonest open expectation of this promiser as KEPT, or decline.
 *
 * $of null is the person at the centre (player $player); a cast handle is one
 * townsperson's promise to another. $how is 'there' (the body or the week put
 * them in the room — the window opens at local midnight of the due day) or
 * 'said' (the extractor heard it done — the window opens the moment after the
 * promise was formed, since bringing the dish back early is still bringing it
 * back). Either way the window CLOSES where the miss fires, due + grace: a
 * promise that has already been missed is repaired by explaining, not kept by
 * turning up late, and this function never touches a row that is not `open`.
 *
 * Fail closed throughout: no open row, the wrong promiser, the wrong player, a
 * row formed this very moment (the promise and its keeping cannot be the same
 * sentence), or a window not yet open — nothing changes and null comes back.
 * $formedBefore narrows it further: only a promise made before that moment is
 * eligible (the body's arrival, for 'there').
 *
 * @return string|null the arc key kept
 */
function xeric_expect_keep(array $t, PDO $db, string $handle, array $now, ?callable $onNote = null,
                           int $player = XERIC_PLAYER_FIRST, string $how = 'said', ?string $of = null,
                           ?int $formedBefore = null): ?string
{
    $note  = $onNote ?? static function (string $s): void {};
    $epoch = (int)($now['epoch'] ?? 0);
    if (!in_array($how, ['there', 'said'], true)) return null;
    $of = ($of === null || trim($of) === '') ? '' : trim($of);

    $tz = null;
    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); } catch (Throwable $e) {}
    $dayStart = static function (int $due) use ($tz): int {
        try {
            return (new DateTimeImmutable('@' . $due))->setTimezone($tz ?? new DateTimeZone('UTC'))
                ->setTime(0, 0)->getTimestamp();
        } catch (Throwable $e) { return $due; }
    };

    $best = null;
    foreach (xeric_expects_for($db, $handle) as $e) {
        if ($e['state'] !== 'open') continue;
        if (trim((string)($e['of'] ?? '')) !== $of) continue;
        if ($of === '' && (int)($e['p'] ?? XERIC_PLAYER_FIRST) !== $player) continue;
        if ((int)($e['formed'] ?? 0) >= min($formedBefore ?? $epoch, $epoch)) continue;
        if ($epoch > $e['due'] + XERIC_EXPECT_GRACE) continue;
        if ($how === 'there' && $epoch < $dayStart($e['due'])) continue;
        if ($best === null || $e['due'] < $best['due']) $best = $e;
    }
    if ($best === null) return null;

    $name    = xeric_world_name($t, $handle);
    $whoName = $of !== '' ? (xeric_world_name($t, $of) ?: $of)
             : ($player > XERIC_PLAYER_FIRST ? xeric_player_name($db, $player, $t)
                                              : (trim((string)($t['user']['name'] ?? '')) ?: 'they'));
    $key = $best['key'];
    $row = $best; unset($row['key']);
    $row['state'] = 'kept'; $row['kept_at'] = $epoch; $row['kept_how'] = $how;

    $db->beginTransaction();
    try {
        xeric_arc_set($db, $handle, $key, json_encode($row, JSON_UNESCAPED_UNICODE));
        // The mirror of the miss, by the same path and against the same row.
        xeric_trust_earn($db, $handle, +1, null, $of !== '' ? $of : null,
                         $of !== '' ? XERIC_PLAYER_FIRST : (int)($best['p'] ?? XERIC_PLAYER_FIRST));
        xeric_memory_add($db, $handle, $whoName . ' said "' . $best['quote'] . '" and did.',
            'construct', ['expect' => $key, 'kept' => $how] + ($of !== '' ? ['of' => $of] : []), $epoch);
        $db->commit();
    } catch (Throwable $ex) {
        if ($db->inTransaction()) $db->rollBack();
        throw $ex;
    }
    $note('expectations: ' . $whoName . ' kept ' . $best['what'] . ' with ' . $name);
    // A promise kept is the repair path a grudge has: it thaws.
    xeric_grudge_thaw($t, $db, $handle, $of, $of !== '' ? XERIC_PLAYER_FIRST : (int)($best['p'] ?? XERIC_PLAYER_FIRST), $now);
    return $key;
}

/**
 * Who is where, for the keep: the whole cast against the deaths, at $now.
 * Once per tick (xeric_constructs_tick); the count is for the test that says so.
 */
function xeric_constructs_presence(array $t, array $now, array $gone): array
{
    xeric_constructs_presence_reads(1);
    return xeric_world_who_is_where($t, $now, array_keys($gone));
}

/** How many times presence has been resolved in this process. A seam for the suite; +$n to count. */
function xeric_constructs_presence_reads(int $n = 0): int
{
    static $reads = 0;
    return $reads += $n;
}

/**
 * The bodies, for the keep: where the current player stands and since when
 * (the ritual pass's one body), and — under `by` — every person at the
 * centre's own, keyed by player id, so a promise is kept by the body of the
 * person who made it rather than whoever's request ran the tick.
 */
function xeric_constructs_body(array $t, PDO $db): array
{
    // Read lazily: travel.php sits above this file in the require graph (it
    // pulls the sweeps, which pull chat, which pulls this).
    require_once __DIR__ . '/travel.php';
    $by = [];
    foreach (array_keys(xeric_players($db, $t)) as $id) {
        $by[(int)$id] = ['where' => xeric_player_where($t, $db, (int)$id), 'since' => xeric_player_since($db, (int)$id)];
    }
    return ['where' => xeric_player_where($t, $db), 'since' => xeric_player_since($db), 'by' => $by];
}

/**
 * The 'there' half: is whoever promised this listener something standing in
 * the listener's room right now? Read off position and the week, no model.
 *
 * A person at the centre's promise is kept by THEIR OWN body (`player.where`,
 * `player.where.p<n>` — a body each since M4), and only if it ARRIVED after
 * the promise was formed (`player.where_since[.p<n>]`): a promise made in
 * person is otherwise kept by the act of making it. A townsperson's promise is kept when
 * their own week seats them where the listener is. A listener at no place, a
 * position that names no place, nothing to keep: nothing happens.
 *
 * $open, $where and $body are what the tick already read (the open rows, the
 * town's presence, ['where' => …, 'since' => …]); a caller without them gets
 * them read here, the same reads.
 *
 * @return string[] the arc keys kept
 */
function xeric_expect_keep_present(array $t, PDO $db, string $handle, array $now, ?callable $onNote = null,
                                   ?array $open = null, ?array $where = null, ?array $body = null): array
{
    $open ??= array_filter(xeric_expects_for($db, $handle), fn($e) => $e['state'] === 'open');
    if ($open === []) return [];

    $where ??= xeric_constructs_presence($t, $now, xeric_deaths($db));
    $here  = $where[$handle]['where'] ?? null;
    if ($here === null || $here === '') return [];

    $kept = [];
    // THE BODY OF WHOEVER PROMISED. A promise from a person at the centre names
    // them in `p` (absent: the first); it is kept by THEIR body arriving, read
    // off their own position — never the position of whoever's request ran
    // this tick, which in a world with company is somebody else's.
    $body ??= xeric_constructs_body($t, $db);
    $owers = [];
    foreach ($open as $e) {
        if (trim((string)($e['of'] ?? '')) === '') $owers[max(XERIC_PLAYER_FIRST, (int)($e['p'] ?? XERIC_PLAYER_FIRST))] = true;
    }
    ksort($owers);
    foreach (array_keys($owers) as $pid) {
        $b = $body['by'][$pid] ?? ($pid === XERIC_PLAYER_FIRST && !isset($body['by'])
            ? ['where' => $body['where'] ?? null, 'since' => $body['since'] ?? 0]
            : ['where' => xeric_player_where($t, $db, $pid), 'since' => xeric_player_since($db, $pid)]);
        $since = (int)($b['since'] ?? 0);
        $at    = $b['where'] ?? null;
        if ($at === null || $at !== (string)$here || $since <= 0) continue;
        // One arrival keeps one promise, the soonest made before it.
        $k = xeric_expect_keep($t, $db, $handle, $now, $onNote, $pid, 'there', null, $since);
        if ($k !== null) $kept[] = $k;
    }
    // The town's own promises, kept by the week.
    $seen = [];
    foreach ($open as $e) {
        $of = trim((string)($e['of'] ?? ''));
        if ($of === '' || isset($seen[$of])) continue;
        $seen[$of] = true;
        if (($where[$of]['where'] ?? null) !== $here) continue;
        $k = xeric_expect_keep($t, $db, $handle, $now, $onNote, XERIC_PLAYER_FIRST, 'there', $of);
        if ($k !== null) $kept[] = $k;
    }
    return $kept;
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
function xeric_expect_repair(array $t, PDO $db, string $handle, array $now, ?callable $onNote = null,
                             int $player = XERIC_PLAYER_FIRST): ?string
{
    $note = $onNote ?? static function (string $s): void {};
    $user = $player > XERIC_PLAYER_FIRST
        ? xeric_player_name($db, $player, $t)
        : (trim((string)($t['user']['name'] ?? '')) ?: 'they');
    $best = null;
    foreach (xeric_expects_for($db, $handle) as $e) {
        if ($e['state'] !== 'missed') continue;
        // THEIR OWN, and only their own. This is the user explaining
        // themselves; a miss owed by somebody in the town is not theirs to
        // apologise for, and repairing it here would hand the player credit
        // for Harlan turning up.
        if (trim((string)($e['of'] ?? '')) !== '') continue;
        // AND THEIR OWN AMONG THE PEOPLE AT THE CENTRE, for the same reason one
        // row further out: with a guest in the world, explaining yourself must
        // not repair a promise somebody else broke.
        if ((int)($e['p'] ?? XERIC_PLAYER_FIRST) !== $player) continue;
        if ($best === null || ($e['missed_at'] ?? $e['due']) > ($best['missed_at'] ?? $best['due'])) $best = $e;
    }
    if ($best === null) return null;

    $name = xeric_world_name($t, $handle);
    $best['state'] = 'repaired'; $best['explained_at'] = (int)($now['epoch'] ?? 0);
    $key = $best['key']; $row = $best; unset($row['key']);
    xeric_arc_set($db, $handle, $key, json_encode($row, JSON_UNESCAPED_UNICODE));
    xeric_trust_earn($db, $handle, +1, null, null, (int)($best['p'] ?? XERIC_PLAYER_FIRST));
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
 *
 * ── AND THE FLOOR, BECAUSE A RIPPLE CROSSES ROOMS ─────────────────────────
 *
 * Every other thing in a character's prompt is filtered at THEIR OWN ceiling:
 * xeric_prompt_system() clamps to the floor for a child and screens tells,
 * moods and secrets through it. A gossip line arrives by a different road. It
 * was written into an hour whose ceiling was that ROOM's — clamped by whoever
 * was standing in it, which is exactly the set of people this line will never
 * be shown to — and then it travels, and lands wherever the ripple reaches. A
 * child who was nowhere near it hears it third-hand.
 *
 * So it is screened here, per line, against the reader, with the engine's one
 * reader-for-sex. An adult with no children in the world pays nothing: the
 * floor returns early before it scans a byte.
 */
function xeric_gossip_block(array $t, PDO $db, string $handle): string
{
    require_once __DIR__ . '/chat.php';    // xeric_age_floor — the one floor

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
        // Dropped, not refused: an hour is refused whole because half an hour is
        // not a smaller hour, but the town's talk is a list, and a list with one
        // thing missing from it is exactly what a child not being told something
        // looks like. The item stays live for everybody else.
        if (xeric_age_floor($t, [$handle], [$line]) !== null) continue;
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

// ---------------------------------------------------------------------------
// THE SIBLINGS, by fuse length
// ---------------------------------------------------------------------------
//
// The roadmap's reusable shape, six more times: trigger → a small arc with
// epochs → COARSE rendered state (never a timer) → a licensed line → residue
// that becomes an ordinary memory. Each has a name `forge.armed` can carry
// and an inspector line (xeric_constructs_why). Sorted by how long the fuse is:
//
//   anticipation  a plan the player made          fuse: until the day     licence: the day before
//   news          an hour they were in, untold    fuse: 24–72 hours       licence: a text-first
//   worry         no word for longer than usual   fuse: their usual gap   licence: a text-first
//   grudge        a miss or a breach unrepaired   fuse: the repair window licence: none, it renders
//   ritual        three visits, same place/hour   fuse: the missed hour   licence: a line when missed
//   milestone     a count crossed                 fuse: a few days        licence: a line, once
//
// Every row is an arc on the character who holds it, so rewind's arc diff
// un-happens a sibling with everything else, and the dead are skipped by the
// tick like they are for every other fuse. Everything here is code and the
// week: no model is asked anything.
//
// News, worry, ritual and milestone read the thread and the body, which
// belong to the first person at the centre (one body, and messages carry no
// player id yet), so they point at player one. Anticipation and grudge carry
// the `p` of the promise they came from.

/** How long news stays worth a text, and when it stops being news, world seconds. */
const XERIC_NEWS_LICENCE = 24 * 3600;
const XERIC_NEWS_STALE   = 72 * 3600;

/** Worry: the gaps a usual gap is measured from, the floor on it, and when worry goes sore. */
const XERIC_WORRY_GAPS     = 3;
const XERIC_WORRY_SESSION  = 3 * 3600;      // a gap shorter than this is the same conversation
const XERIC_WORRY_FLOOR    = 24 * 3600;
const XERIC_WORRY_SORE     = 4;             // × the usual gap

/** A grudge is cold, then cool; thawing settles. */
const XERIC_GRUDGE_COOL   = 14 * 24 * 3600;
const XERIC_GRUDGE_SETTLE = 7 * 24 * 3600;

/** Ritual: visits, the window they fall in, and the grace past the hour before a miss. */
const XERIC_RITUAL_VISITS = 3;
const XERIC_RITUAL_WINDOW = 14 * 24 * 3600;
const XERIC_RITUAL_GRACE  = 2 * 3600;

/** Milestone: the counts worth noticing, a month of knowing, and how long it stays on the mind. */
const XERIC_MILESTONE_COUNTS = [10 => 'tenth', 25 => 'twenty-fifth', 50 => 'fiftieth', 100 => 'hundredth'];
const XERIC_MILESTONE_MONTH  = 30 * 24 * 3600;
const XERIC_MILESTONE_FADE   = 3 * 24 * 3600;

/**
 * Is this sibling armed? By its own name in `forge.armed` (singular or plural)
 * or by the motivation presets' existing systems, the way debts arm with
 * `favors` and confidences with `private_history`. A world with no systems
 * record predates arming and gets it, the rule every construct here keeps.
 */
function xeric_sibling_armed(array $t, string $name): bool
{
    $armed = $t['forge']['armed'] ?? null;
    if (!is_array($armed)) return true;
    $armed = array_map('strval', $armed);
    $by = [
        'anticipation' => ['anticipation', 'expectations', 'visits'],
        'news'         => ['news', 'gentle_proactive_contact', 'remembering'],
        'worry'        => ['worry', 'gentle_proactive_contact'],
        'grudge'       => ['grudge', 'grudges', 'expectations', 'people_who_remember', 'private_history'],
        'ritual'       => ['ritual', 'rituals', 'daily_rhythms', 'faith'],
        'milestone'    => ['milestone', 'milestones', 'arcs', 'remembering'],
    ][$name] ?? [$name];
    return array_intersect($by, $armed) !== [];
}

/** A moment in the world's own timezone. */
function xeric_sibling_local(array $t, int $epoch): DateTimeImmutable
{
    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    return (new DateTimeImmutable('@' . $epoch))->setTimezone($tz);
}

/** Local midnight of the day $epoch falls on. */
function xeric_sibling_midnight(array $t, int $epoch): int
{
    return xeric_sibling_local($t, $epoch)->setTime(0, 0)->getTimestamp();
}

/** One sibling row, parsed, or null. */
function xeric_sibling_get(PDO $db, string $handle, string $key): ?array
{
    $raw = xeric_arc_get($db, $handle, $key);
    $row = $raw === null ? null : json_decode($raw, true);
    return is_array($row) ? $row : null;
}

/** Write one sibling row. */
function xeric_sibling_put(PDO $db, string $handle, string $key, array $row): void
{
    unset($row['key']);
    xeric_arc_set($db, $handle, $key, json_encode($row, JSON_UNESCAPED_UNICODE));
}

/** Every row this person holds under a prefix, keyed. */
function xeric_sibling_rows(PDO $db, string $handle, string $prefix): array
{
    $out = [];
    foreach (xeric_arcs_prefixed($db, $handle, $prefix) as $k => $v) {
        $row = json_decode((string)$v, true);
        if (!is_array($row) || !isset($row['state'])) continue;
        $row['key'] = (string)$k;
        $out[(string)$k] = $row;
    }
    return $out;
}

/** The person at the centre, by name. */
function xeric_sibling_user(array $t, PDO $db, int $player = XERIC_PLAYER_FIRST): string
{
    if ($player > XERIC_PLAYER_FIRST) return xeric_player_name($db, $player, $t);
    return trim((string)($t['user']['name'] ?? '')) ?: 'they';
}

/**
 * The thread with the person at the centre, as world epochs: every line with
 * its role, oldest first. Lines with no world time are not on the calendar
 * and are skipped. The one raw SELECT, for the reason the gossip scan gives.
 */
function xeric_sibling_thread(PDO $db, string $handle): array
{
    $conv = xeric_conversation_find($db, $handle, 'chat');
    if ($conv === null) return [];
    $st = $db->prepare('SELECT role, world_epoch FROM messages WHERE conversation_id = ? AND world_epoch IS NOT NULL ORDER BY id');
    $st->execute([(int)$conv['id']]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[] = ['role' => (string)$r['role'], 'at' => (int)$r['world_epoch']];
    return $out;
}

/** A thread in which BOTH of them have said something — the dream rung's test for knowing somebody. */
function xeric_sibling_knows(array $thread): bool
{
    $u = $c = false;
    foreach ($thread as $m) {
        if ($m['role'] === 'user') $u = true;
        elseif ($m['role'] === 'character') $c = true;
    }
    return $u && $c;
}

// -- anticipation ------------------------------------------------------------

/**
 * She looks forward to it. Formed beside the expectation, keyed to it, and
 * resolved by its fate: kept is "it happened", missed is "she had been looking
 * forward to it". Epochs are DAYS — ahead, the eve, the day — so the line
 * changes three times at most and never per turn.
 */
function xeric_anticipate_form(array $t, PDO $db, string $handle, string $expectKey, array $now): ?string
{
    $e = xeric_sibling_get($db, $handle, $expectKey);
    if ($e === null || ($e['state'] ?? '') !== 'open') return null;
    $key = 'anticipate.' . $expectKey;
    if (xeric_sibling_get($db, $handle, $key) !== null) return null;
    xeric_sibling_put($db, $handle, $key, [
        'expect' => $expectKey, 'what' => (string)$e['what'], 'due' => (int)$e['due'],
        'formed' => (int)($now['epoch'] ?? 0), 'state' => 'ahead',
    ] + (isset($e['p']) ? ['p' => (int)$e['p']] : []));
    return $key;
}

/** One step of every anticipation this person holds. @return int transitions */
function xeric_anticipate_step(array $t, PDO $db, string $handle, int $epoch): int
{
    $n = 0;
    $name = xeric_world_name($t, $handle);
    foreach (xeric_sibling_rows($db, $handle, 'anticipate.') as $key => $a) {
        if (!in_array($a['state'], ['ahead', 'eve', 'today'], true)) continue;
        $e = xeric_sibling_get($db, $handle, (string)$a['expect']);
        $fate = (string)($e['state'] ?? 'released');
        $user = xeric_sibling_user($t, $db, (int)($a['p'] ?? XERIC_PLAYER_FIRST));
        if ($fate !== 'open') {
            // The residue, either way — for a plan she had a day ahead to look
            // forward to (one that reached the eve with a licence). A plan made
            // on the eve is only the expectation, whose own memory is enough.
            if (!isset($a['licence'])) {
                $a['state'] = $fate === 'kept' ? 'happened' : (in_array($fate, ['missed', 'repaired', 'hardened'], true) ? 'let_down' : 'gone');
                xeric_sibling_put($db, $handle, $key, $a);
                $n++;
                continue;
            }
            if ($fate === 'kept') {
                $a['state'] = 'happened';
                xeric_memory_add($db, $handle, $name . ' had been looking forward to ' . $a['what']
                    . ' with ' . $user . ', and it happened.', 'construct', ['anticipate' => $key], $epoch);
            } elseif (in_array($fate, ['missed', 'repaired', 'hardened'], true)) {
                $a['state'] = 'let_down';
                xeric_memory_add($db, $handle, $name . ' had been looking forward to ' . $a['what']
                    . ' with ' . $user . '.', 'construct', ['anticipate' => $key], $epoch);
            } else {
                $a['state'] = 'gone';
            }
            if (($a['licence'] ?? '') === 'pending') $a['licence'] = 'lapsed';
            xeric_sibling_put($db, $handle, $key, $a);
            $n++;
            continue;
        }
        $day = xeric_sibling_midnight($t, (int)$a['due']);
        $eve = xeric_sibling_local($t, $day)->modify('-1 day')->getTimestamp();
        $want = $epoch >= $day ? 'today' : ($epoch >= $eve ? 'eve' : 'ahead');
        if ($want === $a['state']) continue;
        // The licence: the day before, once, and only for a plan made before
        // that day began — a promise made on the eve needs no reminder of itself.
        if ($want === 'eve' && (int)$a['formed'] < $eve) $a['licence'] = 'pending';
        if ($want === 'today' && ($a['licence'] ?? '') === 'pending') $a['licence'] = 'lapsed';
        $a['state'] = $want;
        xeric_sibling_put($db, $handle, $key, $a);
        $n++;
    }
    return $n;
}

// -- news --------------------------------------------------------------------

/** The hours that are not news: the spine, a dream, a construct's own record. */
function xeric_news_unfit(PDO $db, array $ev): bool
{
    if (!empty($ev['on_spine'])) return true;
    $why = json_decode((string)(xeric_world_state_get($db, 'why:event:' . (int)$ev['id']) ?? ''), true);
    return is_array($why) && in_array((string)($why['kind'] ?? ''), ['dream', 'missed_promise'], true);
}

/** One step of this person's news. @return int transitions */
function xeric_news_step(array $t, PDO $db, string $handle, int $epoch, array $thread): int
{
    if (!xeric_sibling_knows($thread)) return 0;      // a stranger has nothing to tell you
    $last = (int)end($thread)['at'];
    $row  = xeric_sibling_get($db, $handle, 'news');
    $name = xeric_world_name($t, $handle);
    $user = xeric_sibling_user($t, $db);

    if ($row !== null && $row['state'] === 'fresh') {
        $told = $last > (int)$row['at']
             || xeric_world_state_get($db, 'proactive:event:' . (int)$row['event']) === $handle;
        if ($told) {
            // The conversation that told it is its own record (the harvest
            // reads it); a memory here would only crowd the window.
            $row['state'] = 'told'; unset($row['licence']);
            xeric_sibling_put($db, $handle, 'news', $row);
            return 1;
        }
        if ($epoch > (int)$row['at'] + XERIC_NEWS_STALE) {
            $row['state'] = 'stale'; unset($row['licence']);
            xeric_sibling_put($db, $handle, 'news', $row);
            return 1;
        }
        if ($epoch > (int)$row['at'] + XERIC_NEWS_LICENCE && !isset($row['licence'])) {
            $row['licence'] = 'pending';
            xeric_sibling_put($db, $handle, 'news', $row);
            return 1;
        }
        return 0;
    }

    // Something new to tell: the newest hour they were in, since the thread
    // last moved, with somebody else in it, still young enough to be news.
    foreach (xeric_events_recent($db, 12) as $ev) {
        $at = (int)$ev['world_epoch'];
        if ($at <= $last || $at > $epoch || $epoch - $at > XERIC_NEWS_STALE) continue;
        $who = array_map('strval', (array)$ev['participants']);
        if (!in_array($handle, $who, true) || count($who) < 2) continue;
        if ($row !== null && (int)$row['event'] === (int)$ev['id']) return 0;
        if (xeric_news_unfit($db, $ev)) continue;
        if (xeric_world_state_get($db, 'proactive:event:' . (int)$ev['id']) === $handle) continue;
        xeric_sibling_put($db, $handle, 'news', [
            'event' => (int)$ev['id'], 'title' => xeric_gossip_line((string)$ev['title']),
            'at' => $at, 'state' => 'fresh',
        ]);
        return 1;
    }
    return 0;
}

// -- worry -------------------------------------------------------------------

/**
 * The usual gap between the person at the centre's visits to this thread:
 * the median of the gaps between their lines, counting only gaps long enough
 * to be a new conversation. Null until there are enough to call usual.
 */
function xeric_worry_usual(array $thread): ?int
{
    $at = [];
    foreach ($thread as $m) if ($m['role'] === 'user') $at[] = (int)$m['at'];
    $gaps = [];
    for ($i = 1; $i < count($at); $i++) {
        $g = $at[$i] - $at[$i - 1];
        if ($g >= XERIC_WORRY_SESSION) $gaps[] = $g;
    }
    if (count($gaps) < XERIC_WORRY_GAPS) return null;
    sort($gaps);
    return $gaps[intdiv(count($gaps), 2)];
}

/** One step of this person's worry. @return int transitions */
function xeric_worry_step(array $t, PDO $db, string $handle, int $epoch, array $thread): int
{
    $usual = xeric_worry_usual($thread);
    $lastU = 0;
    foreach ($thread as $m) if ($m['role'] === 'user') $lastU = (int)$m['at'];
    $row  = xeric_sibling_get($db, $handle, 'worry');
    $name = xeric_world_name($t, $handle);
    $user = xeric_sibling_user($t, $db);

    if ($row !== null && in_array($row['state'], ['worried', 'sore'], true)) {
        if ($lastU > (int)$row['since']) {
            // Relief, which is its own residue: you came back.
            $was = $row['state'];
            $row['state'] = 'relieved'; $row['back'] = $lastU; unset($row['licence']);
            xeric_memory_add($db, $handle, $was === 'sore'
                ? $user . ' got back in touch with ' . $name . ' after going quiet for a long while.'
                : $name . ' had started to wonder about ' . $user . ', and then heard from them.',
                'construct', ['worry' => $was], $epoch);
            xeric_sibling_put($db, $handle, 'worry', $row);
            return 1;
        }
        if ($row['state'] === 'worried' && $epoch - (int)$row['since'] > XERIC_WORRY_SORE * (int)$row['usual']) {
            // The grudge seed: not a grudge, the thing a grudge could grow from.
            $row['state'] = 'sore'; unset($row['licence']);
            xeric_memory_add($db, $handle, $user . ' went quiet on ' . $name . ' for a long while and never said why.',
                'construct', ['worry' => 'sore'], $epoch);
            xeric_sibling_put($db, $handle, 'worry', $row);
            return 1;
        }
        return 0;
    }
    if ($usual === null || $lastU === 0) return 0;
    if ($row !== null && (int)($row['since'] ?? 0) >= $lastU) return 0;   // this silence was already worried over
    $fuse = max(XERIC_WORRY_FLOOR, 2 * $usual);
    if ($epoch - $lastU <= $fuse) return 0;
    xeric_sibling_put($db, $handle, 'worry', [
        'state' => 'worried', 'since' => $lastU, 'usual' => max(XERIC_WORRY_FLOOR / 2, $usual),
        'at' => $epoch, 'licence' => 'pending',
    ]);
    return 1;
}

// -- grudge ------------------------------------------------------------------

/**
 * A miss or a breach that was never repaired, hardened into a temperature.
 * $of '' is the person at the centre (player $player); a handle is somebody
 * in the town. One grudge per source row.
 */
function xeric_grudge_form(array $t, PDO $db, string $handle, string $of, int $player, string $what,
                           string $from, array $now): ?string
{
    foreach (xeric_sibling_rows($db, $handle, 'grudge.') as $g) {
        if ((string)($g['from'] ?? '') === $from) return null;
    }
    $key = 'grudge.' . (1 + count(xeric_sibling_rows($db, $handle, 'grudge.')));
    xeric_sibling_put($db, $handle, $key, [
        'of' => $of, 'what' => mb_substr($what, 0, 120), 'from' => $from,
        'at' => (int)($now['epoch'] ?? 0), 'state' => 'cold',
    ] + ($of === '' && $player > XERIC_PLAYER_FIRST ? ['p' => $player] : []));
    return $key;
}

/**
 * The repair path: an explanation that came too late for the window, or a
 * promise kept since. Cold and cool both thaw; thawing settles on its own.
 */
function xeric_grudge_thaw(array $t, PDO $db, string $handle, string $of, int $player, array $now): int
{
    $n = 0;
    foreach (xeric_sibling_rows($db, $handle, 'grudge.') as $key => $g) {
        if (!in_array($g['state'], ['cold', 'cool'], true)) continue;
        if ((string)($g['of'] ?? '') !== $of) continue;
        if ($of === '' && (int)($g['p'] ?? XERIC_PLAYER_FIRST) !== $player) continue;
        $g['state'] = 'thawing'; $g['thaw_at'] = (int)($now['epoch'] ?? 0);
        xeric_sibling_put($db, $handle, $key, $g);
        $n++;
    }
    return $n;
}

/** One step of every grudge this person holds, and the breaches that become one. @return int transitions */
function xeric_grudge_step(array $t, PDO $db, string $handle, int $epoch, array $now): int
{
    $n = 0;
    // A confidence the person at the centre let out, never owned up to inside
    // the window, hardens on the teller.
    require_once __DIR__ . '/confide.php';
    foreach (xeric_confide_told_player($db, $handle) as $c) {
        if ((string)$c['state'] !== 'broken' || $epoch <= (int)($c['out_at'] ?? 0) + XERIC_CONFIDE_REPAIR) continue;
        $c['state'] = 'hardened';
        xeric_sibling_put($db, $handle, (string)$c['key'], $c);
        $hearer = xeric_world_name($t, (string)($c['heard_by'] ?? '')) ?: 'somebody';
        xeric_memory_add($db, $handle, xeric_sibling_user($t, $db, (int)($c['p'] ?? XERIC_PLAYER_FIRST))
            . ' never said a word about telling ' . $hearer . ' what ' . xeric_world_name($t, $handle) . ' had asked them to keep.',
            'construct', ['confided' => (string)$c['key']], $epoch);
        if (xeric_sibling_armed($t, 'grudge')) {
            xeric_grudge_form($t, $db, $handle, '', (int)($c['p'] ?? XERIC_PLAYER_FIRST),
                'telling ' . $hearer . ' what you asked them to keep', (string)$c['key'], $now);
        }
        $n++;
    }
    foreach (xeric_sibling_rows($db, $handle, 'grudge.') as $key => $g) {
        if ($g['state'] === 'cold' && $epoch > (int)$g['at'] + XERIC_GRUDGE_COOL) {
            $g['state'] = 'cool';
        } elseif ($g['state'] === 'thawing' && $epoch > (int)($g['thaw_at'] ?? 0) + XERIC_GRUDGE_SETTLE) {
            $g['state'] = 'settled';
            $who = (string)($g['of'] ?? '') === '' ? xeric_sibling_user($t, $db, (int)($g['p'] ?? XERIC_PLAYER_FIRST))
                 : (xeric_world_name($t, (string)$g['of']) ?: (string)$g['of']);
            xeric_memory_add($db, $handle, xeric_world_name($t, $handle) . ' let it go with ' . $who
                . ' over ' . $g['what'] . '.', 'construct', ['grudge' => $key], $epoch);
        } else {
            continue;
        }
        xeric_sibling_put($db, $handle, $key, $g);
        $n++;
    }
    return $n;
}

// -- ritual ------------------------------------------------------------------

/**
 * Note where the body arrived, once per arrival. A world arc rather than a
 * table, capped, so rewind's arc diff un-happens a visit with the rest of the
 * undone hours. @return bool whether this arrival was new
 */
function xeric_ritual_visit(PDO $db, string $place, int $since): bool
{
    if ($place === '' || $since <= 0) return false;
    $log = json_decode((string)(xeric_arc_get($db, xeric_arc_world(), 'ritual.visits') ?? '[]'), true);
    if (!is_array($log)) $log = [];
    foreach ($log as $v) if ((string)$v[0] === $place && (int)$v[1] === $since) return false;
    $log[] = [$place, $since];
    $log = array_slice($log, -60);
    xeric_arc_set($db, xeric_arc_world(), 'ritual.visits', json_encode($log, JSON_UNESCAPED_UNICODE));
    return true;
}

/** Morning, afternoon or evening — the coarsest true thing about an hour. */
function xeric_ritual_part(int $hour): string
{
    return $hour < 12 ? 'mornings' : ($hour < 17 ? 'afternoons' : 'evenings');
}

/**
 * The ritual pass, world-wide: log the body's arrival; a third arrival at the
 * same place in the same hour on a different day forms a ritual on whoever
 * the week put there for at least two of them; and a day whose hour passed
 * with no arrival is noticed by whoever was there to notice.
 * @return int transitions
 */
function xeric_ritual_step(array $t, PDO $db, int $epoch, array $gone, ?array $body): int
{
    $n = 0;
    $body ??= xeric_constructs_body($t, $db);
    $where = (string)($body['where'] ?? '');
    $since = (int)($body['since'] ?? 0);
    $log   = json_decode((string)(xeric_arc_get($db, xeric_arc_world(), 'ritual.visits') ?? '[]'), true) ?: [];
    if ($where !== '' && $since > 0 && $since <= $epoch && xeric_ritual_visit($db, $where, $since)) {
        $log[] = [$where, $since];
        $hour  = (int)xeric_sibling_local($t, $since)->format('G');
        $days  = [];
        foreach ($log as $v) {
            if ((string)$v[0] !== $where || $since - (int)$v[1] > XERIC_RITUAL_WINDOW) continue;
            if (abs((int)xeric_sibling_local($t, (int)$v[1])->format('G') - $hour) > 1) continue;
            $days[xeric_sibling_local($t, (int)$v[1])->format('Y-m-d')] = (int)$v[1];
        }
        $today = xeric_sibling_local($t, $since)->format('Y-m-d');
        // Every holder of an existing ritual here: kept again.
        foreach ((array)($t['cast']['characters'] ?? []) as $c) {
            $h = (string)($c['handle'] ?? '');
            $r = $h === '' ? null : xeric_sibling_get($db, $h, 'ritual.' . $where);
            if ($r === null || abs((int)$r['hour'] - $hour) > 1) continue;
            $r['state'] = 'kept'; $r['last'] = $today; unset($r['licence'], $r['missed']);
            xeric_sibling_put($db, $h, 'ritual.' . $where, $r);
            $n++;
        }
        if (count($days) >= XERIC_RITUAL_VISITS) {
            $seen = [];
            foreach ($days as $at) {
                foreach (xeric_world_who_is_where($t, xeric_world_now($t, $at), array_keys($gone)) as $h => $p) {
                    if (($p['where'] ?? null) === $where) $seen[(string)$h] = ($seen[(string)$h] ?? 0) + 1;
                }
            }
            foreach ($seen as $h => $count) {
                if ($count < 2 || xeric_world_character($t, $h) === null) continue;
                if (xeric_sibling_get($db, $h, 'ritual.' . $where) !== null) continue;
                xeric_sibling_put($db, $h, 'ritual.' . $where, [
                    'place' => $where, 'hour' => $hour, 'state' => 'kept', 'last' => $today, 'formed' => $since,
                ]);
                $n++;
            }
        }
    }

    // The miss: a day after the last one whose hour, plus grace, has passed
    // with no arrival — noticed only by a holder the week had there then.
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || isset($gone[$h])) continue;
        foreach (xeric_sibling_rows($db, $h, 'ritual.') as $key => $r) {
            if ($r['state'] !== 'kept') continue;
            $local = xeric_sibling_local($t, $epoch);
            $dueAt = $local->setTime((int)$r['hour'], 0)->getTimestamp();
            $day   = $local->format('Y-m-d');
            if ($day <= (string)$r['last'] || $epoch < $dueAt + XERIC_RITUAL_GRACE) continue;
            if ((string)($r['checked'] ?? '') === $day) continue;
            $r['checked'] = $day;
            $there = xeric_world_who_is_where($t, xeric_world_now($t, $dueAt), array_keys($gone))[$h]['where'] ?? null;
            if ($there === (string)$r['place']) {
                $r['state'] = 'missed'; $r['missed'] = $dueAt; $r['licence'] = 'pending';
                xeric_memory_add($db, $h, xeric_sibling_user($t, $db) . ' did not come into '
                    . xeric_world_place_name($t, (string)$r['place']) . ' on ' . $local->format('l')
                    . ', the way ' . xeric_sibling_user($t, $db) . ' usually does.', 'construct', ['ritual' => $key], $dueAt);
                $n++;
            }
            xeric_sibling_put($db, $h, $key, $r);
        }
    }
    return $n;
}

// -- milestone ---------------------------------------------------------------

/** One step of this person's milestones. @return int transitions */
function xeric_milestone_step(array $t, PDO $db, string $handle, int $epoch, array $thread): int
{
    $n = 0;
    $name = xeric_world_name($t, $handle);
    // On the mind for a few days, then simply history.
    foreach (xeric_sibling_rows($db, $handle, 'milestone.') as $key => $m) {
        if ($m['state'] !== 'due' || $epoch <= (int)$m['at'] + XERIC_MILESTONE_FADE) continue;
        xeric_milestone_mark($t, $db, $handle, $key, $epoch);
        $n++;
    }
    // A month of knowing the person at the centre.
    if (xeric_sibling_knows($thread) && $epoch - (int)$thread[0]['at'] >= XERIC_MILESTONE_MONTH
        && xeric_sibling_get($db, $handle, 'milestone.month') === null) {
        xeric_sibling_put($db, $handle, 'milestone.month', [
            'what' => 'a month since you and ' . xeric_sibling_user($t, $db) . ' first got talking',
            'at' => $epoch, 'state' => 'due', 'licence' => 'pending',
        ]);
        $n++;
    }
    // A ledger crossing a round number — only ledgers this person may see.
    $walls = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $handle]));
    foreach ((array)($t['economies'] ?? []) as $eco) {
        $k = (string)($eco['key'] ?? '');
        if ($k === '' || xeric_hidden($walls, 'economies.' . $k)) continue;
        $have = xeric_arc_int($db, $handle, 'economy.' . $k, 0);
        $top  = null;
        foreach (XERIC_MILESTONE_COUNTS as $count => $word) if ($have >= $count) $top = [$count, $word];
        if ($top === null) continue;
        $mk = 'milestone.' . $k . '.' . $top[0];
        if (xeric_sibling_get($db, $handle, $mk) !== null) continue;
        $label = trim((string)($eco['label'] ?? $k));
        xeric_sibling_put($db, $handle, $mk, [
            'what' => 'your ' . $top[1] . ' on ' . $label, 'at' => $epoch, 'state' => 'due', 'licence' => 'pending',
        ]);
        $n++;
    }
    return $n;
}

/** The line was said, or the days passed: noted once, and it becomes a memory. */
function xeric_milestone_mark(array $t, PDO $db, string $handle, string $key, int $epoch): void
{
    $m = xeric_sibling_get($db, $handle, $key);
    if ($m === null || $m['state'] !== 'due') return;
    $m['state'] = 'marked'; unset($m['licence']);
    xeric_sibling_put($db, $handle, $key, $m);
    xeric_memory_add($db, $handle, xeric_world_name($t, $handle) . ' noticed it: '
        . str_replace(['your ', 'you and '], ['their ', xeric_world_name($t, $handle) . ' and '], (string)$m['what']) . '.',
        'construct', ['milestone' => $key], $epoch);
}

// -- the tick, the block, the licences, the inspector -------------------------

/**
 * Every sibling one step, for every living character. Called from the end of
 * xeric_constructs_tick, after the fuses, so an anticipation reads the fate
 * its expectation met this same tick. Idempotent: a second call at the same
 * moment changes nothing.
 *
 * @return array<string,int> transitions per sibling
 */
function xeric_siblings_tick(array $t, PDO $db, array $now, array $gone, ?callable $onNote = null, ?array $body = null): array
{
    $note  = $onNote ?? static function (string $s): void {};
    $epoch = (int)($now['epoch'] ?? 0);
    $out   = ['anticipation' => 0, 'news' => 0, 'worry' => 0, 'grudge' => 0, 'ritual' => 0, 'milestone' => 0];
    $on    = [];
    foreach (array_keys($out) as $s) $on[$s] = xeric_sibling_armed($t, $s);

    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || !empty($c['out']) || isset($gone[$h])) continue;
        $thread = ($on['news'] || $on['worry'] || $on['milestone']) ? xeric_sibling_thread($db, $h) : [];
        if ($on['anticipation']) $out['anticipation'] += xeric_anticipate_step($t, $db, $h, $epoch);
        if ($on['news'] && $thread !== []) $out['news'] += xeric_news_step($t, $db, $h, $epoch, $thread);
        if ($on['worry'] && $thread !== []) $out['worry'] += xeric_worry_step($t, $db, $h, $epoch, $thread);
        $out['grudge'] += xeric_grudge_step($t, $db, $h, $epoch, $now);
        if ($on['milestone']) $out['milestone'] += xeric_milestone_step($t, $db, $h, $epoch, $thread);
    }
    if ($on['ritual']) $out['ritual'] = xeric_ritual_step($t, $db, $epoch, $gone, $body);
    foreach ($out as $s => $k) if ($k > 0) $note('siblings: ' . $s . ' moved ' . $k);
    return $out;
}

/**
 * ON YOUR MIND — the siblings in this person's own prompt. Reads stored
 * state only and never the clock, so it is byte-stable between ticks and
 * changes only when a state does. No digits, no hours, no countdowns.
 */
function xeric_siblings_block(array $t, PDO $db, string $handle, int $player = XERIC_PLAYER_FIRST): string
{
    $lines = [];
    $user  = xeric_sibling_user($t, $db, $player);
    $first = $player === XERIC_PLAYER_FIRST;

    foreach (xeric_sibling_rows($db, $handle, 'anticipate.') as $a) {
        if ((int)($a['p'] ?? XERIC_PLAYER_FIRST) !== $player) continue;
        $day = xeric_sibling_local($t, (int)$a['due'])->format('l');
        if ($a['state'] === 'ahead') $lines[] = '- You are looking forward to ' . $a['what'] . ' with ' . $user . ', ' . $day . '. You would not make a fuss about it.';
        if ($a['state'] === 'eve')   $lines[] = '- Tomorrow is ' . $a['what'] . ' with ' . $user . ', and you are looking forward to it.';
        if ($a['state'] === 'today') $lines[] = '- Today is ' . $a['what'] . ' with ' . $user . '. You have been looking forward to it.';
    }
    if ($first) {
        $news = xeric_sibling_get($db, $handle, 'news');
        // Only once it has waited a day: inside that, the aftermath rung is the
        // one that texts about an hour, and the line would ride every prompt.
        if ($news !== null && $news['state'] === 'fresh' && isset($news['licence'])) {
            $lines[] = '- Something happened that ' . $user . ' has not heard from you yet: ' . $news['title'] . '. It is on your mind to mention.';
        }
        $w = xeric_sibling_get($db, $handle, 'worry');
        if ($w !== null && $w['state'] === 'worried') $lines[] = '- You have not heard from ' . $user . ' in longer than usual. You would not call it worry.';
        if ($w !== null && $w['state'] === 'sore')    $lines[] = '- ' . $user . ' went quiet on you for a long while. You noticed, and you have not decided what you think of it.';
        foreach (xeric_sibling_rows($db, $handle, 'ritual.') as $r) {
            $place = xeric_world_place_name($t, (string)$r['place']);
            if ($r['state'] === 'kept')   $lines[] = '- ' . $user . ' comes into ' . $place . ' most ' . xeric_ritual_part((int)$r['hour']) . '. It is part of how your day goes.';
            if ($r['state'] === 'missed') $lines[] = '- ' . $user . ' did not come into ' . $place . ' ' . xeric_sibling_local($t, (int)$r['missed'])->format('l') . ', the way ' . $user . ' usually does. You noticed.';
        }
        foreach (xeric_sibling_rows($db, $handle, 'milestone.') as $m) {
            if ($m['state'] === 'due') $lines[] = '- It is ' . $m['what'] . '. You noticed, and you might say so once.';
        }
    }
    foreach (xeric_sibling_rows($db, $handle, 'grudge.') as $g) {
        $of = (string)($g['of'] ?? '');
        if ($of === '' && (int)($g['p'] ?? XERIC_PLAYER_FIRST) !== $player) continue;
        $who = $of === '' ? $user : (xeric_world_name($t, $of) ?: $of);
        if ($g['state'] === 'cold')    $lines[] = '- You have not forgiven ' . $who . ' for ' . $g['what'] . '. You are cold with them, and you would deny it.';
        if ($g['state'] === 'cool')    $lines[] = '- ' . $who . ' and ' . $g['what'] . ': it still sits with you, though less than it did.';
        if ($g['state'] === 'thawing') $lines[] = '- Things with ' . $who . ' over ' . $g['what'] . ' are thawing. Not mended. Thawing.';
    }
    if ($lines === []) return '';
    return "ON YOUR MIND\n" . implode("\n", $lines) . "\nYou never narrate any of this; it shows, if it shows, in what you say and what you leave out.";
}

/**
 * What the siblings license right now: one text-first each, by fuse length.
 * proactive.php reads this, decides whether anybody may actually text, and
 * spends the one it used.
 *
 * @return array<int,array{handle:string,kind:string,key:string,hint:string}>
 */
function xeric_constructs_licences(array $t, PDO $db): array
{
    $gone = xeric_deaths($db);
    $user = xeric_sibling_user($t, $db);
    $out  = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || !empty($c['out']) || isset($gone[$h])) continue;
        foreach (xeric_sibling_rows($db, $h, 'anticipate.') as $k => $a) {
            if (($a['licence'] ?? '') === 'pending' && (int)($a['p'] ?? XERIC_PLAYER_FIRST) === XERIC_PLAYER_FIRST) {
                $out[] = ['handle' => $h, 'kind' => 'anticipation', 'key' => $k, 'rank' => 1,
                          'hint' => 'Tomorrow is ' . $a['what'] . ' with ' . $user . '. You are looking forward to it; you might make sure it is still on, sideways.'];
            }
        }
        $n = xeric_sibling_get($db, $h, 'news');
        if ($n !== null && ($n['licence'] ?? '') === 'pending' && $n['state'] === 'fresh') {
            $out[] = ['handle' => $h, 'kind' => 'news', 'key' => 'news', 'rank' => 2,
                      'hint' => 'Something happened that ' . $user . ' has not heard from you: ' . $n['title'] . '. Your half of it, not a report.'];
        }
        $w = xeric_sibling_get($db, $h, 'worry');
        if ($w !== null && ($w['licence'] ?? '') === 'pending' && $w['state'] === 'worried') {
            $out[] = ['handle' => $h, 'kind' => 'worry', 'key' => 'worry', 'rank' => 3,
                      'hint' => 'You have not heard from ' . $user . ' in longer than usual. You are not going to make a thing of it.'];
        }
        foreach (xeric_sibling_rows($db, $h, 'ritual.') as $k => $r) {
            if (($r['licence'] ?? '') !== 'pending' || $r['state'] !== 'missed') continue;
            $out[] = ['handle' => $h, 'kind' => 'ritual', 'key' => $k, 'rank' => 4,
                      'hint' => $user . ' did not come into ' . xeric_world_place_name($t, (string)$r['place'])
                              . ' the way ' . $user . ' usually does. You noticed.'];
        }
        foreach (xeric_sibling_rows($db, $h, 'milestone.') as $k => $m) {
            if (($m['licence'] ?? '') !== 'pending' || $m['state'] !== 'due') continue;
            $out[] = ['handle' => $h, 'kind' => 'milestone', 'key' => $k, 'rank' => 5,
                      'hint' => 'It is ' . $m['what'] . '. You noticed. Say so once, lightly, or not quite.'];
        }
    }
    usort($out, fn($a, $b) => $a['rank'] <=> $b['rank']);
    return $out;
}

/** The licence was used. A milestone said is a milestone marked. */
function xeric_constructs_licence_spend(array $t, PDO $db, string $handle, string $key, int $epoch): void
{
    $row = xeric_sibling_get($db, $handle, $key);
    if ($row === null) return;
    if (str_starts_with($key, 'milestone.')) { xeric_milestone_mark($t, $db, $handle, $key, $epoch); return; }
    $row['licence'] = 'spent';
    if ($key === 'news') {
        // A text about it IS telling it.
        $row['state'] = 'told';
        xeric_memory_add($db, $handle, xeric_world_name($t, $handle) . ' told ' . xeric_sibling_user($t, $db)
            . ' about ' . lcfirst((string)$row['title']) . '.', 'construct', ['news' => (int)$row['event']], $epoch);
    }
    xeric_sibling_put($db, $handle, $key, $row);
}

/**
 * The inspector's one line per construct this person holds: why she is the
 * way she is with you, in words, with the row it came from — so a grudge
 * explains itself instead of reading as the model being moody.
 *
 * @return string[]
 */
function xeric_constructs_why(array $t, PDO $db, string $handle): array
{
    $name = xeric_world_name($t, $handle);
    $user = xeric_sibling_user($t, $db);
    $out  = [];
    foreach (xeric_sibling_rows($db, $handle, 'anticipate.') as $a) {
        $out[] = 'anticipation (' . $a['state'] . '): ' . $name . ' is looking forward to ' . $a['what']
               . ', from the promise in ' . $a['expect'] . '; licenses one text the day before.';
    }
    if ($n = xeric_sibling_get($db, $handle, 'news')) {
        $out[] = 'news (' . $n['state'] . '): ' . $name . ' was in "' . $n['title'] . '" (event ' . (int)$n['event']
               . ') and has not talked to ' . $user . ' since; licenses a text after a day, stale after three.';
    }
    if ($w = xeric_sibling_get($db, $handle, 'worry')) {
        $out[] = 'worry (' . $w['state'] . '): no word from ' . $user . ' for longer than twice the usual gap ('
               . (int)round((int)$w['usual'] / 3600) . 'h, the median between visits to the thread).';
    }
    foreach (xeric_sibling_rows($db, $handle, 'grudge.') as $g) {
        $who = (string)($g['of'] ?? '') === '' ? $user : (xeric_world_name($t, (string)$g['of']) ?: (string)$g['of']);
        $out[] = 'grudge (' . $g['state'] . '): ' . $name . ' is ' . ($g['state'] === 'cold' ? 'cold' : $g['state'])
               . ' with ' . $who . ' over ' . $g['what'] . ' (' . $g['from'] . ', never repaired inside the window). '
               . 'Thaws on an explanation or a promise kept.';
    }
    foreach (xeric_sibling_rows($db, $handle, 'ritual.') as $r) {
        $out[] = 'ritual (' . $r['state'] . '): ' . $user . ' came into ' . xeric_world_place_name($t, (string)$r['place'])
               . ' around ' . (int)$r['hour'] . ':00 on three different days; ' . $name . ' was there for it, and notices a miss.';
    }
    foreach (xeric_sibling_rows($db, $handle, 'milestone.') as $m) {
        $out[] = 'milestone (' . $m['state'] . '): ' . $m['what'] . '; licenses one line, once.';
    }
    require_once __DIR__ . '/confide.php';
    foreach (xeric_confide_told_player($db, $handle) as $c) {
        $out[] = 'confidence (' . $c['state'] . '): ' . $name . ' told ' . xeric_sibling_user($t, $db, (int)($c['p'] ?? XERIC_PLAYER_FIRST))
               . ' ' . $c['what'] . ' and asked them to keep it'
               . (($c['not'] ?? []) !== [] ? ' from ' . xeric_join_list(array_map(fn($h) => xeric_world_name($t, (string)$h), (array)$c['not'])) : '')
               . ((string)($c['heard_by'] ?? '') !== '' ? '; it reached ' . xeric_world_name($t, (string)$c['heard_by']) : '') . '.';
    }
    return $out;
}

// ---------------------------------------------------------------------------
// CAST TO CAST — the town owing itself things, out of its own hours
// ---------------------------------------------------------------------------

/**
 * The town's promises, out of the hours the sweep wrote, and the residue on
 * the side of whoever made one. Called by the heart beside
 * xeric_constructs_tick, which already keeps and misses these rows (the
 * week seating both in a room keeps one; otherwise the fuse burns).
 *
 * A duet and a room already hand their lines to xeric_expect_from_scene. A
 * sweep writes prose, so its quoted speech is read here: a quotation whose
 * sentence names exactly one of the hour's people is that person's line, and
 * everybody else in the hour is in the room for it. The same gates follow —
 * a hedge is not a promise, no when is a sentiment, and a line the
 * listener's walls remove forms nothing for them.
 *
 * AND WHAT THEY ASK EACH OTHER TO KEEP. The same quoted line, read the same
 * way, is also where a confidence between two townspeople is said out loud —
 * "between us, don't tell Harlan" — and xeric_confide_from_line forms it on
 * the one it was said to, code-checked against the words. Only an hour of
 * exactly two people: a thing said in front of a third is not a confidence to
 * either of them. The gossip sweep can then break it, which until now it could
 * only do to rows its own tests wrote.
 *
 * @return array{formed:int,residue:int,confided:int}
 */
function xeric_constructs_cast_tick(array $t, PDO $db, array $now, ?callable $onNote = null): array
{
    require_once __DIR__ . '/confide.php';
    $out = ['formed' => 0, 'residue' => 0, 'confided' => 0];
    $expect = xeric_expect_armed($t);
    $confide = xeric_confide_armed($t);
    if (!$expect && !$confide) return $out;
    // THE FIRST TICK READS NOTHING. A watermark that starts at 0 on a world
    // that already has months of hours replays all of them: every quoted
    // "Thursday morning" in its history forms an expectation dated to a
    // Thursday long gone, and the very next fuse tick fires the lot as one
    // mass of misses — a town that suddenly remembers being let down by
    // everybody at once. History lived before this reader existed was never
    // owed to it. So a world with no watermark gets one at its newest event,
    // and only hours lived after that are read.
    $mark = xeric_arc_get($db, xeric_arc_world(), 'constructs.cast.seen');
    if ($mark === null) {
        $max = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM events')->fetchColumn();
        xeric_arc_set($db, xeric_arc_world(), 'constructs.cast.seen', (string)$max);
        $mark = (string)$max;
    }
    $seen = (int)$mark;
    $st = $db->prepare('SELECT * FROM events WHERE id > ? ORDER BY id LIMIT 200');
    $st->execute([$seen]);
    $rows = $st->fetchAll();
    $last = $seen;
    foreach ($rows as $ev) {
        $last = max($last, (int)$ev['id']);
        $who = array_values(array_filter(array_map('strval', json_decode((string)($ev['participants'] ?? '[]'), true) ?: []),
                                         fn($h) => xeric_world_character($t, $h) !== null));
        if (count($who) < 2 || (int)($ev['on_spine'] ?? 0) === 1) continue;
        if (xeric_news_unfit($db, ['id' => (int)$ev['id'], 'on_spine' => 0])) continue;
        $lines = xeric_cast_lines($t, (string)($ev['prose'] ?? ''), $who);
        if ($lines === []) continue;
        if ($confide && count(array_unique($who)) === 2) {
            foreach ($lines as $l) {
                $listener = (string)(array_values(array_diff($who, [$l['handle']]))[0] ?? '');
                // Through the listener's walls, like a promise: a line that carries
                // what they are walled from forms nothing for them.
                if ($listener === '' || xeric_gossip_wall_blocked($t, ['line' => $l['text']], $listener)) continue;
                if (xeric_confide_from_line($t, $db, $l['handle'], $listener, $l['text'], xeric_world_now($t, (int)$ev['world_epoch'])) !== null) {
                    $out['confided']++;
                }
            }
        }
        if (!$expect) continue;
        foreach ($who as $h) $lines[] = ['handle' => $h, 'text' => ''];   // in the room, saying nothing more
        // Formed at the hour it was said, but never with a due already behind
        // the tick reading it: a backlog of hours read late (a long gap, a
        // capped batch) must not hand the fuse a promise that is born missed.
        $out['formed'] += count(xeric_expect_from_scene($t, $db, $lines, xeric_world_now($t, (int)$ev['world_epoch']), $onNote,
                                                        (int)($now['epoch'] ?? 0)));
    }
    if ($last !== $seen) xeric_arc_set($db, xeric_arc_world(), 'constructs.cast.seen', (string)$last);
    if (!$expect) return $out;

    // A memory EACH: the one who was owed already keeps theirs (the miss and
    // the keep write it); the one who promised keeps the other half.
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        foreach (xeric_expects_for($db, $h) as $e) {
            $of = trim((string)($e['of'] ?? ''));
            if ($of === '' || !empty($e['residue']) || !in_array($e['state'], ['kept', 'missed'], true)) continue;
            xeric_memory_add($db, $of, xeric_world_name($t, $of) . ' told ' . xeric_world_name($t, $h) . ' "' . $e['quote'] . '" and '
                . ($e['state'] === 'kept' ? 'did.' : 'did not make it.'), 'construct',
                ['expect' => $e['key'], 'to' => $h], (int)($e['kept_at'] ?? $e['due']));
            $e['residue'] = 1;
            $row = $e; unset($row['key']);
            xeric_arc_set($db, $h, $e['key'], json_encode($row, JSON_UNESCAPED_UNICODE));
            $out['residue']++;
        }
    }
    return $out;
}

/**
 * The quoted lines in a sweep's prose, attributed: [{handle, text}].
 * A quotation is somebody's only when the rest of its sentence names exactly
 * one of the hour's people. Anything less certain is nobody's line.
 */
function xeric_cast_lines(array $t, string $prose, array $who): array
{
    $out = [];
    if (!preg_match_all('/[^.!?"]*"([^"]{4,240})"[^.!?"]*[.!?]?/u', $prose, $m, PREG_SET_ORDER)) return [];
    foreach ($m as $hit) {
        $around = str_replace('"' . $hit[1] . '"', ' ', $hit[0]);
        $named = [];
        foreach ($who as $h) {
            $first = xeric_constructs_first_name(xeric_world_name($t, $h));
            if ($first !== '' && preg_match('/(?<![\p{L}])' . preg_quote($first, '/') . '(?![\p{L}])/u', $around)) $named[] = $h;
        }
        if (count($named) === 1) $out[] = ['handle' => $named[0], 'text' => trim($hit[1])];
    }
    return $out;
}
