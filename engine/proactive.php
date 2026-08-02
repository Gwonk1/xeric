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
 * Does anybody open a thread about what just happened?
 *
 * @param array $now   from xeric_world_now()/xeric_clock_now()
 * @param array $opts  event (a row from xeric_sweep_run), chance, involves_user,
 *                     cooldown_hours, per_day, temperature, timeout, force,
 *                     learn (false to ignore what this world has learned),
 *                     stories (the overlays the caller composed with)
 * @param array|null $notes OUT: why nobody did, in words. Optional and by
 *                     reference so the ordinary call site stays one line — a
 *                     cron does not care, a CLI transcript very much does.
 * @return array{handle:string,name:string,text:string,conversation_id:int,event_id:int,usage:array}|null
 * @throws RuntimeException when the model fails. Nothing is written in that case.
 */
function xeric_proactive_check(array $t, PDO $db, array $endpoint, array $now, array $opts = [], ?array &$notes = null): ?array
{
    $notes = [];
    $epoch = (int)($now['epoch'] ?? 0);
    if ($epoch <= 0) throw new RuntimeException('proactive: needs a moment, pass xeric_world_now()');

    if (isset($opts['seed'])) mt_srand((int)$opts['seed']);

    if (array_key_exists('enabled', (array)($t['proactive']['pings'] ?? []))
        && !$t['proactive']['pings']['enabled']) {
        $notes[] = 'this world has proactive pings switched off';
        return null;
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
    $notes = $lastNotes;
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
