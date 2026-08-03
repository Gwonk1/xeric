<?php
/**
 * Xeric — proactive contact. The message nobody asked for.
 *
 * A sweep is invisible until somebody mentions it. This file is where the world
 * stops being a database and starts being a phone that buzzes: one of the people
 * who was actually there opens a thread and says the part of it she cannot stop
 * thinking about. That single message is the product demo. Everything below is
 * about not ruining it.
 *
 * ── THE FOUR RULES, ALL LEARNED THE HARD WAY ──────────────────────────────
 *
 *  1. NEVER COLD-OPEN A STRANGER. A character the user has never spoken to,
 *     texting first about her evening, reads as spam from an NPC. She may open a
 *     thread only when the event genuinely involves the user — otherwise she
 *     waits until she has been spoken to once, like a person would.
 *
 *  2. NEVER NAG. If she already texted first and nobody answered, she does not
 *     text first again — the unanswered line stays the newest thing in the thread
 *     until it is answered. A character who double-texts into silence is the
 *     fastest way to make a world feel like a mailing list. (The template's
 *     `double_texts` knob is a separate, deliberate feature; this is not it.)
 *
 *     The naive version of this rule — "her line is the newest, so say nothing" —
 *     was wrong, and wrong in the way that kills the product: after any ordinary
 *     conversation HER reply is the newest line, so the rule silenced exactly the
 *     people the user actually talks to. What makes it nagging is that she is
 *     talking into a silence she has already filled. So the block is her own
 *     unanswered ping, plus a beat of world time after anything she said, so she
 *     does not answer you at 19:58 and text you unprompted at 19:59.
 *
 *  3. ONE PER RUN. A sweep that produced one event produces at most one ping. A
 *     phone that lights up three times because the engine ran once is a bug the
 *     user experiences as "these people are not real".
 *
 *  4. SHE COMES IN SIDEWAYS. "Hello, I am reporting that X happened" is what a
 *     model writes unless it is told, at length, not to. People text mid-thought:
 *     the detail first, the context never, and often no greeting at all.
 *
 * ── AND THE ONE THING A STORY ADDS ────────────────────────────────────────
 *
 * A wrong lead nobody ever volunteers is not a wrong lead, it is a database row
 * the player has to go and dig for. So when a story has given somebody something
 * to be sure of, or left them holding a piece they have not said yet, they are
 * likelier to be the one who picks up the phone. That is the ONLY thing an
 * overlay changes in here, and it is a thumb on WHO — never on whether, never on
 * how often, and never a line of the story in the tail. What she is sure of is
 * already in her own voice block (xeric_story_compose writes it there, once, and
 * it stays in cache between turns); the piece she is holding is not, deliberately,
 * because a piece has to be told in a CONVERSATION for the spill detector to see
 * it, and a ping that let it out sideways would strand the story with nobody
 * having told anybody anything.
 *
 * The pace is not touched here at all: the snake modulates `sweep_chance` and
 * the kind weights and nothing else, so the false calm is a quiet WORLD and not
 * a world that has stopped texting.
 *
 * ── AND THE ONE HOUR THAT IGNORES THE QUIET ───────────────────────────────
 *
 * The dream rung (bottom of this file). Inside the template's dream window —
 * `proactive.dreams.window`, and it is usually INSIDE the user's quiet hours,
 * that is what makes a 3am text a 3am text — somebody who genuinely knows the
 * user may wake and text a dream, at the ladder's own weight
 * (`proactive.pings.ladder.dream`). The dream is made ONLY of material she
 * already legitimately holds: her own recent memories of the user, hours she
 * was actually at. Never the bible's protected sections — a dream that reveals
 * a secret is a wall breach wearing pajamas, and the needle at the bottom
 * refuses it whole. It lands as a text received, never a question asked: the
 * no-nag arc swallows it like any ping, so it sits unanswered until morning,
 * which is the entire point of it.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/sweeps.php';
require_once __DIR__ . '/learn.php';     // who has been answered, and who has not

/** How likely an event is followed by somebody mentioning it, absent a template. */
const XERIC_PROACTIVE_CHANCE = 0.7;

/** Hours before the same person may open a thread unprompted again. */
const XERIC_PROACTIVE_COOLDOWN_HOURS = 20;

/** How many of the cast may text first in one world day. */
const XERIC_PROACTIVE_PER_DAY = 2;

/**
 * How long an hour nobody has spoken about is still worth speaking about. This
 * is what makes the quiet-hours deferral real: the 3am event is offered again
 * at breakfast, and is stale by the following night.
 */
const XERIC_PROACTIVE_DEFER_HOURS = 24;

/**
 * Minutes of WORLD time she leaves after saying anything before she says
 * something unprompted. Not a cooldown — a beat. Answering a question and then
 * texting out of the blue a minute later is the tell of a machine on a timer.
 */
const XERIC_PROACTIVE_BEAT_MINUTES = 60;

/** A ping is one or two lines. Anything longer is a letter, and nobody texts letters. */
const XERIC_PROACTIVE_MAX_CHARS = 320;

/**
 * How much likelier somebody a story has left holding something is to be the
 * one who texts first — and the ceiling that keeps it a thumb. A guarantee here
 * would turn every unanswered evening into the plot knocking, which is the same
 * failure as a world with one character in it.
 */
const XERIC_PROACTIVE_CARRY     = 2.0;
const XERIC_PROACTIVE_CARRY_MAX = 3.0;

/**
 * The dream rung's weight when the ladder does not name one — deliberately the
 * number the forge writes into `proactive.pings.ladder.dream`, so a hand-built
 * template and a forged one dream at the same rate. Consumed the way the
 * aftermath rung consumes its weight: the world's own default is scaled by
 * narrative gravity and clamped; an explicit `dream_chance` opt is an
 * instruction and passes through untouched. It is its OWN opt on purpose —
 * `chance` stays the waking rung's instruction, because the demo's time
 * control forces THAT one to 1.0 on every press and a forced nightly dream
 * would be an alarm clock, not a person.
 */
const XERIC_PROACTIVE_DREAM_CHANCE = 0.25;

/** How far back sleep reaches for material, in world days. A dream is made of
 *  residue, and residue older than a week is furniture. */
const XERIC_PROACTIVE_DREAM_DAYS = 7;

/**
 * Does anybody open a thread about what just happened?
 *
 * @param array $now   from xeric_world_now()/xeric_clock_now()
 * @param array $opts  event (a row from xeric_sweep_run), chance, involves_user,
 *                     cooldown_hours, per_day, temperature, timeout, force,
 *                     learn (false to ignore what this world has learned),
 *                     stories (the overlays the caller composed with),
 *                     dream_chance (the dream rung's explicit instruction —
 *                     `chance` deliberately does not reach it)
 * @param array|null $notes OUT: why nobody did, in words. Optional and by
 *                     reference so the ordinary call site stays one line — a
 *                     cron does not care, a CLI transcript very much does.
 * @return array{handle:string,name:string,text:string,conversation_id:int,event_id:int,usage:array}|null
 * @throws RuntimeException when the model fails. Nothing is written in that case.
 */
function xeric_proactive_check(array $t, PDO $db, array $endpoint, array $now, array $opts = [], ?array &$notes = null): ?array
{
    $notes = [];
    // Absence, not sign: a pre-1970 world's epoch is negative and real.
    if (!isset($now['epoch'])) throw new RuntimeException('proactive: needs a moment, pass xeric_world_now()');
    $epoch = (int)$now['epoch'];

    if (isset($opts['seed'])) mt_srand((int)$opts['seed']);

    if (array_key_exists('enabled', (array)($t['proactive']['pings'] ?? []))
        && !$t['proactive']['pings']['enabled']) {
        $notes[] = 'this world has proactive pings switched off';
        return null;
    }

    // -- the night, first --------------------------------------------------
    // The dream rung runs before the waking one and never passes through the
    // quiet-hours gate below, because its window (proactive.dreams.window) is
    // usually INSIDE those hours on purpose: the 3am text is the feature, not
    // the leak. Outside the window it is a no-op that consumes nothing — not
    // even a random draw, which is what keeps every seeded daytime caller
    // deterministic exactly as it was. One per run still holds: a night that
    // dreamed does not also text about the evening.
    $dreamNotes = [];
    $dream = xeric_proactive_dream($t, $db, $endpoint, $now, $opts, $dreamNotes);
    if ($dream !== null) {
        $notes = $dreamNotes;
        return $dream;
    }

    // -- what would she be texting about? ---------------------------------
    // CANDIDATES, not one event. Looking only at the newest hour made the world
    // go quiet exactly when it should not: a skip often ends on an hour between
    // two people the visitor has never spoken to, rule 1 correctly refuses to
    // cold-open a stranger, and somebody they DID talk to an hour earlier never
    // gets considered (found when the play view kept silent after real skips,
    // 2026-07-30). So: try each candidate newest-first and stop at the first one
    // that produces a message. At most one is ever sent — the per-run cap is
    // unchanged, it just is not decided by the accident of ordering.
    $candidates = [];
    if (isset($opts['events']) && is_array($opts['events'])) {
        $candidates = array_values($opts['events']);
    } elseif (isset($opts['event'])) {
        $candidates = [$opts['event']];
    }

    // DEFERRED, NOT DROPPED. Quiet hours deliberately leave the guard unset so
    // the message can arrive in the morning instead of never — but "later" only
    // exists if somebody offers the hour again, and a caller that hands over the
    // events IT just produced never does, so the deferral was a drop with a
    // kinder note on it. Every recent hour nobody has spoken about yet goes on
    // the end of the list: still newest first, still at most one message sent,
    // and an hour older than a day has stopped being the reason to pick up a
    // phone.
    $known = [];
    foreach ($candidates as $c) {
        $id = (int)($c['id'] ?? 0);
        if ($id > 0) $known[$id] = true;
    }
    $stale = 0;
    foreach (xeric_events_recent($db, (int)($opts['look_back'] ?? 4)) as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0 || isset($known[$id])) continue;
        if ($epoch - (int)($row['world_epoch'] ?? 0) > XERIC_PROACTIVE_DEFER_HOURS * 3600) continue;
        if (empty($opts['force']) && xeric_world_state_get($db, 'proactive:event:' . $id) !== null) { $stale++; continue; }
        $candidates[] = $row;
    }

    if ($candidates === []) {
        $notes[] = $stale > 0
            ? 'everything that has happened lately has already been texted about'
            : 'nothing has happened yet';
        $notes = array_merge($dreamNotes, $notes);
        return null;
    }

    // Walk the candidates; the loop body below is the original single-event
    // decision, and `continue 1` on a refusal simply tries the next one.
    $lastNotes = [];
    foreach ($candidates as $event) {
        $notes = [];
        $r = xeric_proactive_try($t, $db, $endpoint, $now, $opts, $event, $notes);
        if ($r !== null) return $r;
        $lastNotes = array_merge($lastNotes, $notes);
    }
    $notes = array_merge($dreamNotes, $lastNotes);
    return null;
}

/**
 * One candidate event, decided. Split out of xeric_proactive_check() so the
 * candidate walk above stays readable; every rule below is unchanged.
 */
function xeric_proactive_try(array $t, PDO $db, array $endpoint, array $now, array $opts, array $event, ?array &$notes = null): ?array
{
    $notes = [];
    $epoch = (int)($now['epoch'] ?? 0);

    $eventId = (int)($event['id'] ?? 0);
    $guard   = 'proactive:event:' . $eventId;
    if ($eventId > 0 && xeric_world_state_get($db, $guard) !== null && empty($opts['force'])) {
        $notes[] = 'somebody already texted about this one';
        return null;
    }

    // Quiet hours do NOT burn the guard. She is not awake; she will be later,
    // and xeric_proactive_check() offers the hour again for as long as it is
    // still worth mentioning, which is what makes that sentence true.
    $quietWhy = null;
    if (xeric_sweep_quiet($t, $now, $quietWhy)) {
        $notes[] = $quietWhy ?? 'quiet hours, it can wait until morning';
        return null;
    }

    // An EXPLICIT chance is an instruction — a test, or the demo's time control
    // forcing a beat — and must pass through untouched. Only the world's own
    // default is scaled by narrative gravity: a main character gets reached for
    // more often, a side character rarely and only when it truly concerns them.
    // (Scaling the explicit value silently turned a forced 1.0 into 0.9 and made
    // a deterministic test flaky — caught 2026-07-30.)
    if (isset($opts['chance'])) {
        $chance = (float)$opts['chance'];
    } else {
        $chance = (float)($t['proactive']['pings']['ladder']['aftermath'] ?? XERIC_PROACTIVE_CHANCE);
        $chance = min(0.9, max(0.02, $chance * (float)($t['events']['proactive_reach'] ?? 1.0)));
    }
    if (!xeric_sweep_roll($chance)) {
        if ($eventId > 0) xeric_world_state_set($db, $guard, 'nobody');
        $notes[] = 'nobody felt like saying anything (rolled against ' . $chance . ')';
        return null;
    }

    $day    = substr((string)($now['iso'] ?? ''), 0, 10);
    $perDay = (int)($opts['per_day'] ?? $t['proactive']['pings']['caps']['cast_per_day'] ?? XERIC_PROACTIVE_PER_DAY);
    $today  = (int)xeric_world_state_get($db, 'proactive:day:' . $day, '0');
    if ($perDay > 0 && $today >= $perDay) {
        $notes[] = "the cast has already texted first $today times today";
        return null;
    }

    // -- who -------------------------------------------------------------
    $picked = xeric_proactive_pick($t, $db, $event, $now, $opts, $notes);
    if ($picked === null) return null;

    $handle = $picked['handle'];
    $name   = xeric_world_name($t, $handle);

    // -- the message ------------------------------------------------------
    $convId   = $picked['conversation_id'];
    $messages = xeric_prompt_build($t, $db, $handle, $now, [
        'conversation_id' => $convId,
        'tail'            => xeric_proactive_tail($t, $event, $picked['memory']),
        'history_limit'   => (int)($opts['history_limit'] ?? 12),
        'memory_limit'    => (int)($opts['memory_limit'] ?? 12),
    ] + array_intersect_key($opts, ['effective_rating' => 1, 'model_rating' => 1]));

    $usage = [];
    $t0    = microtime(true);
    try {
        $raw = xeric_chat_say($endpoint, $messages, [
            'temperature' => (float)($opts['temperature'] ?? 0.95),
            'max_tokens'  => (int)($opts['max_tokens'] ?? 180),
        ] + array_intersect_key($opts, ['timeout' => 1]), $usage);
    } catch (Throwable $e) {
        throw new RuntimeException("proactive: $name did not answer, " . $e->getMessage(), 0, $e);
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);

    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'you';
    $text     = xeric_chat_clean($raw, $name, $userName, ['max_chars' => (int)($opts['max_chars'] ?? XERIC_PROACTIVE_MAX_CHARS)]);
    if ($text === '') {
        throw new RuntimeException("proactive: $name wrote nothing usable (" . mb_substr(trim($raw), 0, 120) . ')');
    }

    // THE AGE FLOOR, between the model and the write — the same place and the
    // same sentence as chat.php. A ping is the one piece of model output this
    // engine persists that no other floor reads: xeric_chat_turn() scans its own
    // reply before storing it, and nothing downstream re-scans a message once it
    // is in the thread, where it is read back into every later prompt as history.
    // Refused whole, the way a turn is: no rewrite, no second call, and the roll
    // and the guard both stay unspent, so the hour is simply one nobody said
    // anything about. The tail is not scanned with it — an event's memory is
    // floored where it is written, and re-refusing it here would silence a
    // character over a line that is not the one being stored.
    $refused = xeric_age_floor($t, [$handle], [$text]);
    if ($refused !== null) throw new RuntimeException(xeric_age_refusal('proactive', $refused));

    // -- one write, or none ------------------------------------------------
    $at = xeric_state_time();
    $db->beginTransaction();
    try {
        if ($convId === null) $convId = xeric_conversation_create($db, $handle, 'chat', null, $at);
        // Character role → xeric_message_append bumps unread, which is the dot.
        $messageId = xeric_message_append($db, $convId, 'character', $handle, $text, $epoch, $at);
        // Remembered so that this exact line, left unanswered, blocks the next one.
        xeric_arc_set($db, $handle, 'proactive.last_message_id', $messageId, $at);
        xeric_arc_set($db, $handle, 'proactive.last_epoch', $epoch, $at);
        if ($eventId > 0) xeric_world_state_set($db, $guard, $handle, $at);
        xeric_world_state_set($db, 'proactive:day:' . $day, $today + 1, $at);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('proactive: could not store the message, ' . $e->getMessage(), 0, $e);
    }

    return [
        'handle'          => $handle,
        'name'            => $name,
        'text'            => $text,
        'conversation_id' => (int)$convId,
        'event_id'        => $eventId,
        'cold_open'       => $picked['cold_open'],
        'usage'           => $usage + ['ms' => $ms, 'reply_chars' => mb_strlen($text)],
    ];
}

/**
 * Which of the people who were there texts first — or nobody.
 *
 * Candidates are shuffled rather than ranked: a world where the same person
 * always speaks up first has one character and some scenery.
 *
 * ── WHO HAS EARNED ANOTHER MESSAGE ────────────────────────────────────────
 *
 * On top of the shuffle, and only once this world has watched somebody long
 * enough to know (learn.php), each candidate carries a REACH: somebody who is
 * consistently answered is likelier to be the one who speaks up, somebody whose
 * last several messages were left sitting sometimes decides not to bother. Still
 * a shuffle and not a ranking — a heavier thumb, never a guarantee, because the
 * rule above about one character and some scenery does not stop applying just
 * because the world has an opinion now. Clamped hard at both ends, because being
 * ignored for a week is not the same as being deleted, and a character who goes
 * permanently silent is one the world has removed on the user's behalf.
 *
 * It lives HERE, per person, rather than on the world's chance roll above — that
 * roll takes an explicit chance as an instruction (a test, or the demo's time
 * control forcing a beat) and must keep passing it through untouched.
 *
 * @return array{handle:string,conversation_id:?int,memory:string,cold_open:bool}|null
 */
function xeric_proactive_pick(array $t, PDO $db, array $event, array $now, array $opts, array &$notes): ?array
{
    $epoch     = (int)($now['epoch'] ?? 0);
    $eventId   = (int)($event['id'] ?? 0);
    $cooldown  = (int)($opts['cooldown_hours'] ?? $t['proactive']['pings']['caps']['per_character_hours'] ?? XERIC_PROACTIVE_COOLDOWN_HOURS) * 3600;
    $beat      = (int)($opts['beat_minutes'] ?? XERIC_PROACTIVE_BEAT_MINUTES) * 60;
    $involves  = !empty($opts['involves_user']) || xeric_proactive_involves_user($t, $event);
    $learn     = !array_key_exists('learn', $opts) || (bool)$opts['learn'];   // as xeric_chat_turn()

    $who = (array)($event['participants'] ?? []);
    if ($who === [] && isset($event['memories'])) $who = array_keys((array)$event['memories']);

    // The shuffle, with a thumb on it: somebody this world has learned is
    // answered is likelier to go first, never certain to. Everybody at 1.0 is a
    // plain shuffle, down to the same rolls in the same order.
    $reach = [];
    if ($learn) foreach ($who as $h) $reach[(string)$h] = xeric_learn_reach($db, (string)$h);

    // AND A SECOND THUMB, ON THE SAME SCALE: somebody a story has left holding
    // something — a piece that has opened and not been told, a wrong lead they
    // still believe — is who reaches for the phone. It rides the reach rather
    // than replacing it, so being ignored for a fortnight still counts, and it
    // is clamped for the same reason the reach is: a world where the plot always
    // texts first has one character and some scenery. Applied whether or not
    // learning is on, because this is not something the world learned.
    $stories = xeric_sweep_stories($t, $db, $opts);
    if ($stories !== []) {
        foreach (xeric_proactive_carrying($db, $stories) as $h) {
            if (!in_array($h, $who, true)) continue;
            $reach[$h] = min(XERIC_PROACTIVE_CARRY_MAX, (float)($reach[$h] ?? 1.0) * XERIC_PROACTIVE_CARRY);
        }
    }

    $who = xeric_learn_order($who, $reach);

    foreach ($who as $handle) {
        $handle = (string)$handle;
        // Fixtures are scenery. Scenery does not text.
        if (xeric_world_character($t, $handle) === null) continue;

        // NOR DO THE DEAD, and this is checked HERE — at the moment somebody is
        // about to reach for a phone — rather than when the hour that gave them
        // something to say was written. An event and the ping it earns are not
        // the same moment: a sweep can write six hours in one press, and somebody
        // who was at the diner in hour one can be dead by hour five. Filtering
        // the participants upstream would have looked correct and shipped the bug.
        if (xeric_is_dead($db, $handle)) continue;

        $r = (float)($reach[$handle] ?? 1.0);
        if ($r < 1.0 && !xeric_sweep_roll($r)) {
            $notes[] = xeric_world_name($t, $handle) . ' has been leaving you alone, the last few things '
                     . 'they said went unanswered';
            continue;
        }

        $conv   = xeric_conversation_find($db, $handle, 'chat');
        $convId = $conv ? (int)$conv['id'] : null;

        if ($convId === null && !$involves) {
            $notes[] = xeric_world_name($t, $handle) . ' has never spoken to you and this was not about you';
            continue;
        }

        if ($convId !== null) {
            $last = xeric_messages_recent($db, $convId, 1);
            $row  = $last !== [] ? $last[0] : null;

            // She already texted first and it is still sitting there unanswered.
            if ($row !== null && (int)$row['id'] === xeric_arc_int($db, $handle, 'proactive.last_message_id', 0)) {
                $notes[] = xeric_world_name($t, $handle) . ' already texted first and has not been answered';
                continue;
            }
            // Or she spoke a moment ago and would be talking over herself.
            if ($row !== null && (string)($row['role'] ?? '') !== 'user') {
                $spoke = (int)($row['world_epoch'] ?? 0);
                if ($spoke > 0 && ($epoch - $spoke) < $beat) {
                    $notes[] = xeric_world_name($t, $handle) . ' only just spoke, that would be piling on';
                    continue;
                }
            }
        }

        $lastPing = xeric_arc_int($db, $handle, 'proactive.last_epoch', 0);
        if ($lastPing > 0 && $cooldown > 0 && ($epoch - $lastPing) < $cooldown) {
            $notes[] = xeric_world_name($t, $handle) . ' already texted first recently';
            continue;
        }

        return [
            'handle'          => $handle,
            'conversation_id' => $convId,
            'memory'          => xeric_proactive_memory($db, $handle, $eventId, $event),
            'cold_open'       => $convId === null,
        ];
    }

    if ($notes === []) $notes[] = 'nobody who was there could open a thread';
    return null;
}

/**
 * Her own memory of the event — not the narrator's prose.
 *
 * This is the whole reason sweeps write divergent memories: the ping is written
 * from ONE person's half of the hour, so two characters pinging about the same
 * event would say genuinely different things. Falls back to the event prose only
 * when no memory was stored, which is a state the sweep does not produce.
 */
function xeric_proactive_memory(PDO $db, string $handle, int $eventId, array $event): string
{
    if (isset($event['memories'][$handle])) return (string)$event['memories'][$handle];

    foreach (array_reverse(xeric_memories_for($db, $handle, 8)) as $m) {
        $meta = (array)($m['meta'] ?? []);
        if ($eventId > 0 && (int)($meta['event_id'] ?? 0) === $eventId) return (string)$m['text'];
    }
    return trim((string)($event['prose'] ?? ''));
}

/**
 * Is the user actually in this? The only thing that licenses a cold open.
 *
 * Deliberately literal: their NAME in the title or the prose. A looser test
 * ("it happened at their workplace") would license a stranger texting about a
 * building, which is exactly the cold open this rule exists to prevent.
 */
function xeric_proactive_involves_user(array $t, array $event): bool
{
    $name = trim((string)($t['user']['name'] ?? ''));
    if ($name === '' || mb_strlen($name) < 3) return false;

    $hay = (string)($event['title'] ?? '') . ' ' . (string)($event['prose'] ?? '');
    return (bool)preg_match('/\b' . preg_quote($name, '/') . '\b/iu', $hay);
}

/**
 * Who is sitting on something a story gave them.
 *
 * Two shapes, and both of them mean "this person has a sentence in their mouth":
 * a beat that has OPENED and not yet been told, and a red herring they still
 * believe. Nothing about either leaves this function — what comes out is a list
 * of handles, and all it decides is who reaches for the phone.
 *
 * It reads state, never the file's engine-side half: `is_false` and `actually`
 * are not touched here, the way they are not touched anywhere a prompt can see.
 */
function xeric_proactive_carrying(PDO $db, array $stories): array
{
    $out = [];
    foreach ($stories as $s) {
        $st = xeric_story_state($s, $db);
        if (!$st['live']) continue;

        foreach ((array)($s['beats'] ?? []) as $b) {
            $h = (string)($b['holder'] ?? '');
            if ($h !== '' && ($st['beats'][(string)($b['key'] ?? '')] ?? 'locked') === 'open') $out[$h] = true;
        }
        foreach ((array)($s['red_herrings'] ?? []) as $r) {
            $h = (string)($r['believer'] ?? '');
            if ($h !== '' && ($st['herrings'][(string)($r['key'] ?? '')] ?? 'live') === 'live') $out[$h] = true;
        }
    }
    return array_keys($out);
}

/**
 * The coaching that rides the last user message.
 *
 * It goes in the VOLATILE tail (prompt.php's rule: anything that can change
 * between two messages lives at the bottom), and it is long because every line
 * of it is a failure mode that showed up in testing. "Do not summarise" is the
 * load-bearing one: a model handed an event will write a minute of the meeting.
 *
 * Nothing a story is holding goes in here. A conviction is already in her own
 * voice block, where it stays in cache; a piece she has not told yet stays where
 * it is until somebody asks her for it.
 */
function xeric_proactive_tail(array $t, array $event, string $memory): string
{
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'them';

    $lines = [];
    $lines[] = 'YOU ARE TEXTING FIRST. Nobody asked you anything, you picked up your phone.';
    if (trim($memory) !== '') $lines[] = 'What you are still chewing on: ' . xeric_sentence(trim($memory));
    $lines[] = 'Write the message you would actually send ' . $userName . ' right now.';
    $lines[] = '- One or two lines. Mid-thought. No greeting, no "hey, so".';
    $lines[] = '- Do not report it and do not summarise it. Say the one part of it that is stuck.';
    $lines[] = '- Never explain why you are texting. Never say you wanted to tell them something.';
    $lines[] = '- It is fine to say something sideways, or to ask them a small unrelated thing first.';
    $lines[] = '- Only what YOU saw. You were there for your half of it, not all of it.';
    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// The dream rung
// ---------------------------------------------------------------------------

/**
 * Does somebody wake in the small hours and text a dream?
 *
 * The forge writes a dream window into every world and a `dream` weight into
 * the ladder, and until this function existed nothing consumed either. It is
 * the one rung that does not defer to quiet hours, BY DESIGN: the window is
 * usually inside them, and the message stamped 03:12 that is still sitting
 * there at breakfast is the whole feature. What keeps it from being spam is
 * that every other rule in this file applies anyway — the no-nag arc, the
 * beat, the cooldown, the per-day cap, one per run — plus two of its own:
 *
 *   WHO. Only somebody with a real relationship: a thread in which BOTH of
 *   them have actually said something. The cold-open licence does not exist
 *   here — an event can genuinely be about the user, a stranger's dream about
 *   them is just creepy — so rule 1 lands harder at night than it does by day.
 *
 *   ABOUT WHAT. Only material she already legitimately holds: her own recent
 *   memories that involve the user or that an hour left her, and the commons
 *   prose of events she actually attended. Nothing is invented from nothing,
 *   and nothing reaches into the bible — the prompt is built by
 *   xeric_prompt_build() behind her own walls, exactly like every other line
 *   she has ever said, and the needle below refuses the dream whole if the
 *   model reaches for a protected secret anyway.
 *
 * ONE ROLL A NIGHT. Callers arrive many times inside a five-hour window — the
 * heart ticks every minute, the worker walks candidates — and a rung that
 * rolled 0.25 per arrival would dream almost every night. So the roll itself
 * is guarded, the way the aftermath rung burns `proactive:event:` on a failed
 * roll: `proactive:dream:<day>` is written 'nobody' when the night rolls cold,
 * and the handle when it doesn't. (A window that crosses midnight spans two
 * day-keys and can therefore roll twice; the forge writes 01:00-06:00, which
 * doesn't. Noted so the day it does, this comment is where the look starts.)
 * A night whose roll PASSED but found nobody with a thread and material burns
 * nothing — the user may talk to somebody at 2am, and the next hour of the
 * same night is allowed to notice.
 *
 * @param array $notes OUT, same contract as xeric_proactive_check()
 * @return array|null the ping shape, plus kind:'dream' and the dream's own event_id
 * @throws RuntimeException when the model fails or the dream is refused.
 *         Nothing is written in either case, and the roll stays spent only by
 *         its own guard rules above.
 */
function xeric_proactive_dream(array $t, PDO $db, array $endpoint, array $now, array $opts, array &$notes): ?array
{
    $epoch = (int)($now['epoch'] ?? 0);

    // The window, read with the same forgiving reader as quiet hours — it is
    // the same hand that edits both fields, on the same phone. No window, no
    // dreams: a world that never named the hours does not get a default one.
    $spec = (string)($t['proactive']['dreams']['window'] ?? '');
    $win  = xeric_sweep_quiet_window($spec);
    if ($win === null) return null;

    [$f, $to] = $win;
    $mins = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    if (!($to > $f ? ($mins >= $f && $mins < $to) : ($mins >= $f || $mins < $to))) return null;

    $day   = substr((string)($now['iso'] ?? ''), 0, 10);
    $guard = 'proactive:dream:' . $day;
    if (empty($opts['force']) && xeric_world_state_get($db, $guard) !== null) {
        $notes[] = 'tonight has already had its dream roll';
        return null;
    }

    $perDay = (int)($opts['per_day'] ?? $t['proactive']['pings']['caps']['cast_per_day'] ?? XERIC_PROACTIVE_PER_DAY);
    $today  = (int)xeric_world_state_get($db, 'proactive:day:' . $day, '0');
    if ($perDay > 0 && $today >= $perDay) {
        $notes[] = "the cast has already texted first $today times today";
        return null;
    }

    // The ladder's own weight, consumed the way the aftermath rung consumes
    // its own: explicit is an instruction, the default is scaled by narrative
    // gravity and clamped. `chance` is deliberately NOT read here — see the
    // constant's comment for why a forced beat must not be a forced dream.
    if (isset($opts['dream_chance'])) {
        $chance = (float)$opts['dream_chance'];
    } else {
        $chance = (float)($t['proactive']['pings']['ladder']['dream'] ?? XERIC_PROACTIVE_DREAM_CHANCE);
        $chance = min(0.9, max(0.02, $chance * (float)($t['events']['proactive_reach'] ?? 1.0)));
    }
    if (!xeric_sweep_roll($chance)) {
        xeric_world_state_set($db, $guard, 'nobody');
        $notes[] = 'nobody dreamed anything worth waking for (rolled against ' . $chance . ')';
        return null;
    }

    // -- who wakes ---------------------------------------------------------
    $picked = xeric_proactive_dreamer($t, $db, $now, $opts, $notes);
    if ($picked === null) return null;              // guard unspent, see header

    $handle   = $picked['handle'];
    $name     = xeric_world_name($t, $handle);
    $convId   = $picked['conversation_id'];
    $material = $picked['material'];

    // -- the dream ---------------------------------------------------------
    // Built exactly like every other line she says: her prompt, her walls, her
    // effective rating (xeric_prompt_system clamps a minor to the weakest tier
    // before one block is assembled — the floor below is the second read, not
    // the first). Only the volatile tail is the night's.
    $messages = xeric_prompt_build($t, $db, $handle, $now, [
        'conversation_id' => $convId,
        'tail'            => xeric_proactive_dream_tail($t, $material),
        'history_limit'   => (int)($opts['history_limit'] ?? 12),
        'memory_limit'    => (int)($opts['memory_limit'] ?? 12),
    ] + array_intersect_key($opts, ['effective_rating' => 1, 'model_rating' => 1]));

    $usage = [];
    $t0    = microtime(true);
    try {
        $raw = xeric_chat_say($endpoint, $messages, [
            'temperature' => (float)($opts['temperature'] ?? 0.95),
            'max_tokens'  => (int)($opts['max_tokens'] ?? 180),
        ] + array_intersect_key($opts, ['timeout' => 1]), $usage);
    } catch (Throwable $e) {
        throw new RuntimeException("proactive: $name did not answer, " . $e->getMessage(), 0, $e);
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);

    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'you';
    $text     = xeric_chat_clean($raw, $name, $userName, ['max_chars' => (int)($opts['max_chars'] ?? XERIC_PROACTIVE_MAX_CHARS)]);
    if ($text === '') {
        throw new RuntimeException("proactive: $name wrote nothing usable (" . mb_substr(trim($raw), 0, 120) . ')');
    }

    // A DREAM THAT REVEALS A SECRET IS A WALL BREACH WEARING PAJAMAS. The
    // prompt was built from what she legitimately holds, but a character who
    // KNOWS the thing this world is keeping quiet could still dream it out
    // loud into a thread — the one place the spill detector never looks, and
    // a message is read back into every later prompt as history. Dreams have
    // no revelations, so the needle reads for EVERY protected secret, not just
    // one she is being kept from, and refuses whole: no rewrite, no second
    // call, nothing written. Same blunt needle as the sweep's, wrong in the
    // same safe direction.
    foreach (xeric_sweep_protected($t) as $ph => $secret) {
        if (xeric_sweep_touches($text, $secret)) {
            throw new RuntimeException('proactive: refused, ' . $name
                . "'s dream reached for what this world is keeping quiet");
        }
    }

    // THE AGE FLOOR, between the model and the write, the same place and the
    // same sentence as the waking rung above. Refused whole; roll and guards
    // stay where the header says they stay.
    $refused = xeric_age_floor($t, [$handle], [$text]);
    if ($refused !== null) throw new RuntimeException(xeric_age_refusal('proactive', $refused));

    // -- one write, or none ------------------------------------------------
    $at = xeric_state_time();
    $db->beginTransaction();
    try {
        // The text lands as a message RECEIVED — character role, so the unread
        // dot rises — and the no-nag arc swallows it like any ping: this exact
        // line, left unanswered until breakfast, is what blocks the next one.
        $messageId = xeric_message_append($db, $convId, 'character', $handle, $text, $epoch, $at);
        xeric_arc_set($db, $handle, 'proactive.last_message_id', $messageId, $at);
        xeric_arc_set($db, $handle, 'proactive.last_epoch', $epoch, $at);

        // The dream is also an HOUR. The book reads days out of the events
        // table and sets an hour whose trail says `dream` in its own italic
        // register (forge/web/book.php), so the night writes one: her dream,
        // as prose, at the epoch she woke.
        $eventId = xeric_event_add($db, $name . ' dreamed', $epoch, null, [$handle], $text, $at);

        // The trail is normally the installation's to keep (play-lib.php's
        // xeric_play_keep_trail, on the caller's side of the line) — but the
        // KIND is decided here and only here, no caller can re-derive it after
        // the fact, and `kind` is the one field the book's reader
        // (xeric_book_event_kind) asks the trail for. Same key, same shape, so
        // why.php reads this one like any hour the sweep kept.
        xeric_world_state_set($db, 'why:event:' . $eventId, json_encode([
            'kind'      => 'dream',
            'on_spine'  => false,
            'why'       => $name . ' woke inside the dream window (' . $spec . ') with '
                         . count($material) . ' recent thing(s) to dream about, and texted before it went',
            'place'     => '',
            'people'    => [$handle],
            'ms'        => $ms,
            'attempts'  => 1,
            'notes'     => [],
            'trail'     => ['rung' => 'dream', 'window' => $spec, 'chance' => $chance,
                            'material' => count($material)],
            'at'        => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);

        // Her own ping guard on the hour she just wrote, so the waking rung
        // never offers the dream back as something to text about — the dream
        // IS the text about it.
        xeric_world_state_set($db, 'proactive:event:' . $eventId, $handle, $at);
        xeric_world_state_set($db, $guard, $handle, $at);
        xeric_world_state_set($db, 'proactive:day:' . $day, $today + 1, $at);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('proactive: could not store the dream, ' . $e->getMessage(), 0, $e);
    }

    return [
        'handle'          => $handle,
        'name'            => $name,
        'text'            => $text,
        'conversation_id' => (int)$convId,
        'event_id'        => $eventId,
        'kind'            => 'dream',
        'cold_open'       => false,
        'usage'           => $usage + ['ms' => $ms, 'reply_chars' => mb_strlen($text)],
    ];
}

/**
 * Who wakes — or nobody.
 *
 * The same shuffle-with-a-thumb as the waking rung (reach, when this world has
 * learned any), walked over the whole cast rather than an event's participants,
 * because a dream's roster is everyone who is load-bearing: a real character,
 * alive, with a genuine two-sided thread. Fixtures are scenery and scenery
 * does not dream; the dead do not either, and it is checked here at the moment
 * of waking for the same reason the waking rung checks it at the phone.
 *
 * @return array{handle:string,conversation_id:int,material:array<int,string>}|null
 */
function xeric_proactive_dreamer(array $t, PDO $db, array $now, array $opts, array &$notes): ?array
{
    $epoch    = (int)($now['epoch'] ?? 0);
    $cooldown = (int)($opts['cooldown_hours'] ?? $t['proactive']['pings']['caps']['per_character_hours'] ?? XERIC_PROACTIVE_COOLDOWN_HOURS) * 3600;
    $beat     = (int)($opts['beat_minutes'] ?? XERIC_PROACTIVE_BEAT_MINUTES) * 60;
    $learn    = !array_key_exists('learn', $opts) || (bool)$opts['learn'];

    $who = [];
    foreach (xeric_world_cast($t) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '') $who[] = $h;
    }

    $reach = [];
    if ($learn) foreach ($who as $h) $reach[$h] = xeric_learn_reach($db, $h);
    $who = xeric_learn_order($who, $reach);

    foreach ($who as $handle) {
        if (xeric_is_dead($db, $handle)) continue;

        // Rule 1, with no sideways licence: strangers pass in silence rather
        // than in a note apiece — six "has never spoken to you" lines a night
        // would be the notes nagging instead of the cast.
        $conv = xeric_conversation_find($db, $handle, 'chat');
        if ($conv === null) continue;
        $convId = (int)$conv['id'];

        $rows   = xeric_messages_recent($db, $convId, 40);
        $theirs = false;
        $yours  = false;
        foreach ($rows as $m) {
            $role = (string)($m['role'] ?? '');
            if ($role === 'user') $yours = true;
            elseif ($role === 'character' || $role === 'assistant') $theirs = true;
        }
        // A REAL relationship is two-sided: a thread that is all one voice —
        // an unanswered hello, or her own line hanging — is not somebody who
        // dreams about you yet.
        if (!$yours || !$theirs) {
            $notes[] = xeric_world_name($t, $handle) . ' and you have not really spoken';
            continue;
        }

        $r = (float)($reach[$handle] ?? 1.0);
        if ($r < 1.0 && !xeric_sweep_roll($r)) {
            $notes[] = xeric_world_name($t, $handle) . ' has been leaving you alone, the last few things '
                     . 'they said went unanswered';
            continue;
        }

        $newest = $rows !== [] ? $rows[count($rows) - 1] : null;
        if ($newest !== null && (int)$newest['id'] === xeric_arc_int($db, $handle, 'proactive.last_message_id', 0)) {
            $notes[] = xeric_world_name($t, $handle) . ' already texted first and has not been answered';
            continue;
        }
        if ($newest !== null && (string)($newest['role'] ?? '') !== 'user') {
            $spoke = (int)($newest['world_epoch'] ?? 0);
            if ($spoke > 0 && ($epoch - $spoke) < $beat) {
                $notes[] = xeric_world_name($t, $handle) . ' only just spoke, that would be piling on';
                continue;
            }
        }

        $lastPing = xeric_arc_int($db, $handle, 'proactive.last_epoch', 0);
        if ($lastPing > 0 && $cooldown > 0 && ($epoch - $lastPing) < $cooldown) {
            $notes[] = xeric_world_name($t, $handle) . ' already texted first recently';
            continue;
        }

        $material = xeric_proactive_dream_material($t, $db, $handle, $epoch);
        if ($material === []) {
            $notes[] = xeric_world_name($t, $handle) . ' slept on nothing worth dreaming about';
            continue;
        }

        return ['handle' => $handle, 'conversation_id' => $convId, 'material' => $material];
    }

    if ($notes === []) $notes[] = 'nobody close enough to you had a dream to send';
    return null;
}

/**
 * What the dream is made of — and the entire wall-safety argument, in one
 * place: everything returned here is something this character ALREADY holds.
 *
 * Two sources and only two, both dated, both hers:
 *
 *   HER OWN MEMORIES, when they involve the user by name (the same literal
 *   test as xeric_proactive_involves_user — looser would dream about a
 *   building) or when an hour wrote them (`meta.event_id`, which is how a
 *   sweep stamps the half of an evening she carried out of it).
 *
 *   THE COMMONS of events she attended: title and prose are what every player
 *   view already prints, and she was in the participants list.
 *
 * Nothing here reads the template at all, so no bible section — protected or
 * otherwise — can leak through this path; the bible reaches the prompt only
 * through xeric_prompt_build's walls, same as every waking line. And nothing
 * is invented: an empty list means no dream tonight, never a dream about
 * nothing.
 *
 * @return array<int,string> at most three fragments, oldest first
 */
function xeric_proactive_dream_material(array $t, PDO $db, string $handle, int $epoch): array
{
    $horizon  = $epoch - XERIC_PROACTIVE_DREAM_DAYS * 86400;
    $userName = trim((string)($t['user']['name'] ?? ''));
    $named    = $userName !== '' && mb_strlen($userName) >= 3;

    $out = [];
    foreach (xeric_memories_for($db, $handle, 12) as $m) {
        // Undated memories are seeded dispositions, not residue; recency is
        // what makes it a dream and not a theme.
        $we = (int)($m['world_epoch'] ?? 0);
        if ($we <= 0 || $we < $horizon || $we > $epoch) continue;

        $text = trim((string)($m['text'] ?? ''));
        if ($text === '') continue;

        $meta      = (array)($m['meta'] ?? []);
        $aboutUser = $named && preg_match('/\b' . preg_quote($userName, '/') . '\b/iu', $text);
        if ($aboutUser || (int)($meta['event_id'] ?? 0) > 0) $out[] = $text;
    }

    foreach (array_reverse(xeric_events_recent($db, 8)) as $e) {
        if (!in_array($handle, (array)($e['participants'] ?? []), true)) continue;
        $we = (int)($e['world_epoch'] ?? 0);
        if ($we <= 0 || $we < $horizon || $we > $epoch) continue;
        $line = trim((string)($e['prose'] ?? ''));
        if ($line === '') $line = trim((string)($e['title'] ?? ''));
        if ($line !== '') $out[] = $line;
    }

    return array_slice(array_values(array_unique($out)), 0, 3);
}

/**
 * The coaching that makes it a dream and not a report.
 *
 * Volatile tail, same seat as xeric_proactive_tail() and long for the same
 * reason: every line is a failure mode. "No revelation" is the load-bearing
 * one — a model handed fragments will try to make them MEAN something, and a
 * dream that means something is a plot beat sneaking in through the window.
 * "Do not ask for anything back" is the second: the message must land as a
 * text received, not a bell rung — it is the middle of the night and the
 * whole shape of the thing is that it can sit there until morning.
 */
function xeric_proactive_dream_tail(array $t, array $material): string
{
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'them';

    $lines = [];
    $lines[] = 'IT IS THE MIDDLE OF THE NIGHT AND YOU JUST WOKE FROM A DREAM. Nobody asked you '
             . 'anything — you picked up your phone to text ' . $userName . ' before it goes.';
    $lines[] = 'The dream was made of these, and only these:';
    foreach ($material as $m) $lines[] = '- ' . xeric_sentence(trim((string)$m));
    $lines[] = 'Write the one or two lines you would actually send, half awake.';
    $lines[] = '- Dream logic: the people and places above, recombined slightly wrong. A door where '
             . 'there was none, somebody wearing the wrong voice.';
    $lines[] = '- No plot, no message, no revelation. The dream does not mean anything and you know it.';
    $lines[] = '- Do not explain that you were dreaming about them, and do not ask for anything back. '
             . 'It is not a question. It can sit unanswered until morning.';
    $lines[] = '- Nothing that happened in the dream happened. Do not report real news.';
    $lines[] = '- Just the message. No timestamp, no place line, no sign-off.';   // the stand-in
                        // liked to echo the RIGHT NOW block's clock back as a signature
    return implode("\n", $lines);
}
