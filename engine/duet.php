<?php
/**
 * Xeric — the duet. Two characters talk while you watch.
 *
 * This is the first piece of "the room" (ROADMAP.md), cut down on purpose to
 * the two-person case, and its first law is the roadmap's, verbatim: THE
 * ONE-CALL SHORTCUT IS FATAL AND WILL LOOK RIGHT. A single call asked for the
 * whole exchange would return perfect pacing, cheap, and every wall in the
 * world would have collapsed into it silently — one prompt means one point of
 * view, and a transcript with one point of view is one character doing two
 * voices. So: every spoken line below is its OWN model call, built from that
 * speaker's OWN assembly — their bible through their walls with their memories,
 * the exact same xeric_prompt_system() every chat turn uses — and the only
 * thing that ever crosses from one head to the other is what was actually SAID,
 * carried across as conversation turns. Two people, N lines, N calls, plus one
 * cheap diary call each at the close. That is not an optimisation to revisit.
 *
 * ── WHO MAY DUET ──────────────────────────────────────────────────────────
 *
 * People who are plausibly together, and nobody else. Together means
 * xeric_world_who_is_where() puts both in the same place at the world's now —
 * which covers a shared home for free, because the home fallback IS presence:
 * two residents whose week says nothing else both resolve to their kitchen.
 * The refusal names where each of them actually is, because "no" with no
 * geography reads as the engine being moody, and the whole point of schedules
 * is that she is off at two and you cannot get there by two. This function
 * does not move anybody: putting two people in a room is the Director's job
 * (ROADMAP), and a duet that teleported its own participants would be a chat
 * window wearing a map.
 *
 * ── WHAT SEEDS THE SCENE ──────────────────────────────────────────────────
 *
 * Three sources, chosen because each is something the engine already knows to
 * be true rather than something a model is invited to invent:
 *
 *   1. WHERE THEY ARE. The place, and what each of them is there doing, from
 *      the same presence read that admitted them. A scene grows out of a room.
 *   2. WHAT IS UNRESOLVED BETWEEN THEM: each speaker's own stored memories
 *      that MENTION the other, newest first, capped small. These are PRIVATE
 *      — they ride only their owner's volatile tail, never the partner's,
 *      because a memory is the textbook case of what a per-speaker call
 *      exists to keep on its own side of the table.
 *   3. WHAT THE TOWN ALREADY KNOWS: the most recent event both attended,
 *      title only, screened per reader — a spine-marked hour is withheld from
 *      a protected character here exactly as xeric_sweep_prompt() withholds
 *      it, because a seed is a prompt by another name.
 *
 * What is deliberately NOT a source: the constructs. An expectation in this
 * engine is something the USER owes a character (constructs.php), so there is
 * no character↔character expectation to select — when that construct grows a
 * second party, this list grows a fourth line. And nothing here asks a model
 * what is unresolved: the material is rows, not vibes, so the inspector can
 * answer "why did that scene exist?" with the same rows.
 *
 * ── THE CEILING, THE FLOOR, THE WALLS ─────────────────────────────────────
 *
 * The duet is written at the ROOM's ceiling: one minor in it clamps BOTH
 * speakers' calls to the weakest rating, the same fold xeric_sweep_prompt()
 * does for an hour, because the two calls are two halves of one room. The age
 * floor then reads every spoken line the way chat reads a turn — the new line
 * with the line it answers, as one piece — and refuses the whole duet rather
 * than trimming it. And the wall post-check is the sweep's: a line that puts
 * a protected listener next to the thing they must not know is refused after
 * the model too, because gossip that does not run through the walls is a leak
 * engine (ROADMAP's words), and this file is the first gossip the engine has.
 *
 * ── READ-ONLY UNTIL THE CLOSE ─────────────────────────────────────────────
 *
 * Every model call happens against a database nothing here has written to.
 * The close then writes it all in ONE transaction: each speaker's own diary
 * (their memory extraction — the CHANGE rule, one cheap call each, through
 * chat.php's extraction seam), ONE event row saying that they talked and
 * where — commons prose by construction, engine-written, no model near it —
 * and the why-trail under the key the inspector already reads. A duet that
 * dies at line four therefore leaves the world exactly as it found it, and
 * "the model was wrong" and "the world changed" never overlap. No messages
 * rows: the transcript is returned to the caller (and streamed through
 * $opts['on_line']); a watching surface that wants to keep it can, and the
 * canonical record of the hour is the event, like every other hour.
 *
 * Zero dependencies beyond the engine. PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/chat.php';     // clean, floor, dedupe, aliases, the model seam
require_once __DIR__ . '/sweeps.php';   // the protected-secret test, the room-ceiling idiom
require_once __DIR__ . '/learn.php';    // the weighted shuffle, and reach
require_once __DIR__ . '/death.php';    // the dead do not talk, even to each other
require_once __DIR__ . '/constructs.php'; // a promise made across a table is a promise

/** Spoken lines in a duet, total, both voices. ~Three exchanges reads as a scene. */
const XERIC_DUET_TURNS = 6;

/** A spoken line, not a text message and not a speech. Collapsed at a full stop. */
const XERIC_DUET_MAX_CHARS = 500;

/** Room for a long line; the trimmer decides what is kept. */
const XERIC_DUET_MAX_TOKENS = 260;

/** How many of a speaker's memories of the other seed their side of the scene. */
const XERIC_DUET_MATERIAL = 3;

// ---------------------------------------------------------------------------
// The duet
// ---------------------------------------------------------------------------

/**
 * Two characters talk, N lines, one model call per line, one close.
 *
 * @param array $t    the template, story-composed if the caller carries overlays
 * @param array $now  from xeric_world_now() — injected, never fetched here
 * @param array $opts turns, say_first (a|b handle), seed, temperature, timeout,
 *                    max_tokens, max_chars, memory_limit, effective_rating,
 *                    model_rating, on_line (fn(handle,name,text) as lines land)
 * @return array{lines:array,event_id:int,title:string,prose:string,place:?string,
 *               memories:array<string,array>,spoke_first:string,turns:int,
 *               usage:array,notes:array}
 * @throws RuntimeException on an unknown or dead or absent speaker, two people
 *         not in a room together, a dead model, a line the floor or a wall
 *         refuses, or a close that cannot be stored. In every case except the
 *         last, NOTHING has been written; the last rolls back whole.
 */
function xeric_duet(array $t, PDO $db, string $a, string $b, array $now, array $endpoint, array $opts = []): array
{
    if (isset($opts['seed'])) mt_srand((int)$opts['seed']);   // the sweep's own idiom

    // Absence, not sign: a pre-1970 world's epoch is negative and real.
    if (!isset($now['epoch'])) throw new RuntimeException('duet: a duet needs a moment, pass xeric_world_now()');
    $epoch = (int)$now['epoch'];

    // -- who, resolved loudly (chat.php's posture: a caller that named the
    //    wrong person deserves to hear so) --------------------------------
    if ($a === $b) throw new RuntimeException("duet: a conversation needs two people, '$a' twice is one");
    $world = (string)($t['meta']['name'] ?? 'this xeric');
    $names = [];
    foreach ([$a, $b] as $h) {
        if (xeric_world_character($t, $h) === null) {
            if (xeric_world_fixture($t, $h) !== null) {
                throw new RuntimeException("duet: '$h' is scenery, a fixture cannot carry half a conversation");
            }
            throw new RuntimeException("duet: nobody in $world answers to '$h'");
        }
        $names[$h] = xeric_world_name($t, $h);
        if (xeric_is_dead($db, $h)) throw new RuntimeException(xeric_death_refusal('duet', $names[$h]));
        $c = xeric_world_character($t, $h);
        if (!empty($c['out'])) throw new RuntimeException('duet: refused, ' . $names[$h] . ' has not entered the story');
    }

    // -- are they in a room together? -------------------------------------
    $room = xeric_duet_together($t, $db, $a, $b, $now);     // throws the geographic refusal

    // -- the room's ceiling: one minor clamps both calls -------------------
    // The sweep's fold exactly (xeric_sweep_prompt): start from world∧model,
    // then let every viewer standing in the room pull it down. Passing the
    // result INTO xeric_prompt_system() below is safe in both directions —
    // for a minor it re-clamps to the same floor, for an adult it is a no-op.
    $eff = (string)($opts['effective_rating'] ?? xeric_world_rating($t, $opts['model_rating'] ?? null));
    $minor = false;
    foreach ([$a, $b] as $h) {
        $who = xeric_viewer($t, ['handle' => $h]);
        if ($who['is_minor']) { $minor = true; $eff = xeric_viewer_rating($eff, $who); }
    }

    // -- who speaks first, who closes --------------------------------------
    $order = xeric_duet_order($db, $a, $b, $opts);
    $first = $order['first'];
    $turns = $order['turns'];

    // -- each side's fixed assembly, resolved once --------------------------
    // One wall resolution and one system string per speaker for the whole
    // duet: byte-stable across their ~turns/2 calls, which is prompt.php's
    // cache discipline doing its job in a new room.
    $walls = $system = $tails = [];
    $protected = xeric_sweep_protected($t);
    $material  = [];
    foreach ([[$a, $b], [$b, $a]] as [$me, $other]) {
        $walls[$me]    = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $me]));
        $system[$me]   = xeric_duet_system($t, $db, $me, $other, $eff, $epoch, $walls[$me],
                                           (int)($opts['memory_limit'] ?? 12));
        $material[$me] = xeric_duet_material($t, $db, $me, $other, $protected);
        $tails[$me]    = xeric_duet_scene($t, $me, $other, $room, $walls[$me], $material[$me]);
    }

    // -- the exchange: one call per line, nothing written -------------------
    $lines  = [];
    $notes  = [];
    $usage  = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'calls' => 0, 'ms' => 0];
    $deaths = xeric_deaths($db);
    $onLine = isset($opts['on_line']) && is_callable($opts['on_line']) ? $opts['on_line'] : null;

    for ($i = 0; $i < $turns; $i++) {
        // Strict alternation. Who is silent being information is the ROOM's
        // question (three people and up); with two, a turn skipped is just a
        // conversation that stopped, so the duet does not model it.
        $speaker = ($i % 2 === 0) ? $first : ($first === $a ? $b : $a);
        $partner = $speaker === $a ? $b : $a;

        $messages = xeric_duet_messages($t, $speaker, $partner, $system[$speaker], $lines,
            $tails[$speaker], $now, $walls[$speaker], $deaths,
            $i === 0, $i === $turns - 1);

        $u  = [];
        $t0 = microtime(true);
        try {
            $raw = xeric_chat_say($endpoint, $messages, [
                'temperature' => (float)($opts['temperature'] ?? 0.85),
                'max_tokens'  => (int)($opts['max_tokens'] ?? XERIC_DUET_MAX_TOKENS),
            ] + array_intersect_key($opts, ['timeout' => 1]), $u);
        } catch (Throwable $e) {
            throw new RuntimeException('duet: ' . $names[$speaker] . ' did not answer, ' . $e->getMessage(), 0, $e);
        }
        $usage['ms'] += (int)round((microtime(true) - $t0) * 1000);
        $usage['prompt_tokens']     += (int)($u['prompt_tokens'] ?? 0);
        $usage['completion_tokens'] += (int)($u['completion_tokens'] ?? 0);
        $usage['calls']++;

        // The same hygiene as a chat reply, with the PARTNER in the user's
        // seat: a model that starts writing the other voice gets cut at the
        // moment of the theft, which in a duet is the difference between two
        // calls and one call wearing two hats.
        $text = xeric_chat_clean($raw, $names[$speaker], $names[$partner],
            ['max_chars' => (int)($opts['max_chars'] ?? XERIC_DUET_MAX_CHARS)]);
        if ($text === '') {
            throw new RuntimeException('duet: ' . $names[$speaker] . ' said nothing usable ('
                . mb_substr(trim($raw), 0, 120) . ')');
        }

        // THE FLOOR, per line, read as a turn: the new line and the one it
        // answers are one piece (chat.php scans question and answer together
        // for the same reason). Handles are BOTH people in the room — the
        // sweep's posture, so an hour with a child in it refuses sex whoever
        // said the sentence.
        $prev    = $lines !== [] ? (string)$lines[count($lines) - 1]['text'] : '';
        $refused = xeric_age_floor($t, [$a, $b], [$prev, $text]);
        if ($refused !== null) throw new RuntimeException(xeric_age_refusal('duet', $refused));

        // THE WALL, after the model, the sweep's own post-check: a spoken
        // line the protected party is standing next to IS the leak, whoever
        // spoke it — including themselves, since a model that hands them the
        // secret to say has already put it in their head.
        foreach ($protected as $ph => $secret) {
            if (($ph === $a || $ph === $b) && xeric_sweep_touches($text, $secret)) {
                throw new RuntimeException('duet: refused, the conversation put ' . $names[$ph]
                    . ' next to the thing they must not know');
            }
        }

        $lines[] = ['handle' => $speaker, 'name' => $names[$speaker], 'text' => $text];
        if ($onLine !== null) $onLine($speaker, $names[$speaker], $text);
    }

    // -- the close: both diaries, one event, one trail, ONE transaction -----
    // The diary calls run BEFORE the transaction (chat.php's discipline: the
    // model is called before anything is written). A diary call that fails
    // costs that speaker their harvest and a note, never the duet: the talk
    // happened across N witnessed calls, and learning is garnish (learn.php's
    // rule) — refusing a lived scene over its bookkeeping would be the tail
    // wagging the dog.
    $kept = [];
    foreach ([$first, $first === $a ? $b : $a] as $me) {
        $other = $me === $a ? $b : $a;
        try {
            $kept[$me] = xeric_duet_extract($t, $db, $me, $other, $lines, $endpoint, $room,
                [$a, $b], $protected, $kept, $opts);
        } catch (Throwable $e) {
            $kept[$me] = [];
            $notes[] = 'could not harvest a diary for ' . $names[$me] . ', ' . $e->getMessage();
        }
    }

    $title = xeric_duet_title($names[$a], $names[$b]);
    $prose = xeric_duet_prose($t, $names[$a], $names[$b], $room, $now);
    $last  = (string)$lines[count($lines) - 1]['handle'];

    $at = xeric_state_time();
    $db->beginTransaction();
    try {
        $eventId = xeric_event_add($db, $title, $epoch, $room['where'], [$a, $b], $prose, $at, false);

        foreach ([$a, $b] as $me) {
            foreach ($kept[$me] as $text) {
                xeric_memory_add($db, $me, $text, 'duet', [
                    'event_id' => $eventId,
                    'with'     => [$me === $a ? $b : $a],
                    'place'    => $room['where'],
                ], $epoch, $at);
            }
        }

        // A PROMISE MADE ACROSS A TABLE IS A PROMISE. The construct that has
        // only ever pointed at the person at the centre reads these lines with
        // the same code gate — a hedge is not a promise, a promise with no
        // when is a sentiment — and the town starts owing itself things. In
        // the close's own transaction, because an expectation formed over an
        // hour that was rolled back would be a debt from a scene that never
        // happened.
        xeric_expect_from_scene($t, $db, $lines, $now);

        // The trail, under the key the inspector and the book already read
        // (why:event:<id>), kind decided here and only here — the same seam
        // proactive.php claims for its dreams. It names who spoke and why the
        // scene existed, and it never carries the transcript: what was said
        // is the participants', and the record of the hour is commons.
        xeric_world_state_set($db, 'why:event:' . $eventId, json_encode([
            'kind'       => 'duet',
            'why'        => $names[$a] . ' and ' . $names[$b] . ' were both at '
                          . ($room['place_name'] !== '' ? $room['place_name'] : 'the same place')
                          . ' (' . $room['why'] . '); '
                          . xeric_duet_material_why($names, $material, $a, $b) . ' '
                          . $names[$first] . ' spoke first and ' . $names[$last] . ' had the last word.',
            'place'      => (string)($room['where'] ?? ''),
            'people'     => [$a, $b],
            'spoke_first'=> $first,
            'last_word'  => $last,
            'turns'      => count($lines),
            'extended'   => $order['extended'],
            'minor_clamp'=> $minor,
            'rating'     => $eff,
            'ms'         => $usage['ms'],
            'notes'      => $notes,
            'at'         => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('duet: could not store the close, ' . $e->getMessage(), 0, $e);
    }

    return [
        'lines'       => $lines,
        'event_id'    => $eventId,
        'title'       => $title,
        'prose'       => $prose,
        'place'       => $room['where'],
        'place_name'  => $room['place_name'],
        'memories'    => $kept,
        'spoke_first' => $first,
        'last_word'   => $last,
        'turns'       => count($lines),
        'usage'       => $usage,
        'notes'       => $notes,
    ];
}

// ---------------------------------------------------------------------------
// Admission: the same room, or no duet
// ---------------------------------------------------------------------------

/**
 * Both in one place per the ONE presence resolver, or a refusal that says
 * where each actually is.
 *
 * xeric_world_who_is_where() is the only reader of the week (world.php says a
 * second derivation would disagree with the first inside a month), and its
 * home fallback is what makes "a shared home" the same test as "the same
 * shift": two residents off the clock both resolve to the house. `where ===
 * null` is somebody the world gave neither a block nor a home — nowhere is a
 * real position, and nowhere is not a room.
 *
 * @return array{where:string,place_name:string,why:string,doing:array<string,?string>,at_home:bool}
 */
function xeric_duet_together(array $t, PDO $db, string $a, string $b, array $now): array
{
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));

    $stand = function (string $h) use ($t, $presence): string {
        $row = $presence[$h] ?? null;
        if ($row === null || $row['where'] === null) {
            return xeric_world_name($t, $h) . ' is not at any of this world\'s places right now';
        }
        return xeric_world_name($t, $h) . ' is at ' . xeric_world_place_name($t, (string)$row['where']);
    };

    $wa = $presence[$a]['where'] ?? null;
    $wb = $presence[$b]['where'] ?? null;
    if ($wa === null || $wb === null || $wa !== $wb) {
        throw new RuntimeException('duet: refused, ' . $stand($a) . ' and ' . $stand($b)
            . ', they are not in a room together');
    }

    $atHome = !empty($presence[$a]['at_home']) && !empty($presence[$b]['at_home']);
    $doing  = [$a => $presence[$a]['doing'] ?? null, $b => $presence[$b]['doing'] ?? null];

    // The why, in the chooser's register (sweeps' groups say "both on shift
    // at…"), kept for the trail so "why did that scene exist?" has a first
    // clause that is geography rather than mood.
    if ($atHome) {
        $why = 'home for both of them';
    } else {
        $bits = [];
        foreach ([$a, $b] as $h) {
            $d = trim((string)($doing[$h] ?? ''));
            if ($d !== '') $bits[] = xeric_world_name($t, $h) . ': ' . $d;
        }
        $why = $bits !== [] ? 'both there on their own schedules — ' . implode('; ', $bits)
                            : 'both there on their own schedules';
    }

    return [
        'where'      => (string)$wa,
        'place_name' => xeric_world_place_name($t, (string)$wa),
        'why'        => $why,
        'doing'      => $doing,
        'at_home'    => $atHome,
    ];
}

// ---------------------------------------------------------------------------
// Turn order: the thumb, never a ranking
// ---------------------------------------------------------------------------

/**
 * Who opens, how many lines, and whether the count stretches by one so the
 * weighted closer actually closes.
 *
 * Both draws ride xeric_learn_order() — the weighted shuffle, exactly as the
 * sweep kinds do — with xeric_learn_reach() as the thumb: the character who
 * reaches out more in this world opens (and closes) a little more often, and
 * a world that has learned nothing shuffles plain, byte-for-byte the rolls it
 * would have made before learning existed. A heavier weight buys a better
 * chance and nothing more; neither seat is ever anybody's by right, because
 * the same person opening every scene is how a world ends up with one
 * character and some scenery (learn.php's words).
 *
 * AN EXPLICIT INSTRUCTION IS NEVER STRETCHED. `say_first` pins the opener and
 * `turns` pins the count, and when the count is pinned the closer is whoever
 * parity says — the sweep learned this the hard way: silently scaling an
 * instruction is how a forced 1.0 became a 0.9 and a deterministic test
 * became flaky. The one-line stretch happens only when the caller left the
 * count to the engine.
 *
 * @return array{first:string,turns:int,extended:bool}
 */
function xeric_duet_order(PDO $db, string $a, string $b, array $opts): array
{
    $weights = [$a => xeric_learn_reach($db, $a), $b => xeric_learn_reach($db, $b)];

    $first = (string)($opts['say_first'] ?? '');
    if ($first !== $a && $first !== $b) {
        $first = (string)xeric_learn_order([$a, $b], $weights)[0];
    }

    $explicit = array_key_exists('turns', $opts);
    $turns    = max(2, min(24, (int)($opts['turns'] ?? XERIC_DUET_TURNS)));

    $extended = false;
    if (!$explicit) {
        // Under strict alternation, even counts hand the last word to the
        // responder and odd counts to the opener. Draw who SHOULD close, and
        // pay one extra line when parity disagrees — the last word is worth a
        // turn, and a scene of six lines and a scene of seven are the same scene.
        $closer  = (string)xeric_learn_order([$a, $b], $weights)[0];
        $natural = ($turns % 2 === 0) ? ($first === $a ? $b : $a) : $first;
        if ($closer !== $natural) { $turns++; $extended = true; }
    }

    return ['first' => $first, 'turns' => $turns, 'extended' => $extended];
}

// ---------------------------------------------------------------------------
// Each side's assembly
// ---------------------------------------------------------------------------

/**
 * One speaker's system message for the whole duet: their ENTIRE ordinary
 * assembly, then one block that reseats the conversation.
 *
 * xeric_prompt_system() is reused whole, not imitated. Every wall behaviour
 * the engine has funnels through it — the viewer clamp, the bible through
 * their walls, the lessons screened for walled quotes, the owed block, the
 * story lines, the memories — and a hand-copied assembly here would be the
 * drift this codebase keeps warning about: two gates that eventually disagree
 * about who may know what. The price of reuse is one wrong sentence — the
 * rules block says "You are texting <user>" — and the block below spends its
 * first lines overruling exactly that, by name, in the same system message.
 * The rules themselves establish the authority to do it: they tell the model
 * to trust the bottom of the prompt over anything it remembers, and the
 * volatile block repeats the reseating where the model pays the most
 * attention. If prompt.php ever grows a face-to-face register, this block
 * shrinks to nothing; until then, a correction is cheaper than a fork.
 *
 * Byte-stable per (speaker, partner, rating, memory set): the whole thing is
 * assembled once per duet and reused for every one of this speaker's calls,
 * which is the cache discipline the header of prompt.php calls the entire
 * design.
 */
function xeric_duet_system(array $t, PDO $db, string $me, string $other, string $eff,
                           int $epoch, array $walls, int $memoryLimit): string
{
    $base     = xeric_prompt_system($t, $db, $me, $eff, $memoryLimit, $epoch, $walls);
    $partner  = xeric_world_name($t, $other);
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'anyone';

    // The partner is named even for a speaker whose walls hide cast_lines:
    // walls govern KNOWLEDGE — schedules, dossiers, what somebody is told —
    // and the person standing in front of you is eyesight, not knowledge. The
    // admission gate already made them be in one room; a wall that could
    // unsee that would make the duet a séance. What the walls DO keep out of
    // this prompt they keep out above, in the base assembly, untouched.
    $out = [rtrim($base), ''];
    $out[] = 'THIS ONE CONVERSATION IS DIFFERENT';
    $out[] = '- Right now you are not texting ' . $userName . '. You are with ' . $partner
           . ', talking out loud, face to face.';
    $out[] = '- Every line that comes to you in this conversation is ' . $partner
           . ' speaking to you. None of it is ' . $userName . '.';
    $out[] = '- ' . $userName . ' is not here. Nothing said here is addressed to '
           . $userName . ' and nobody reads it back to ' . $userName . '.';
    $out[] = '- Speak as yourself and only yourself. Never write ' . $partner
           . '\'s half, never answer for ' . $partner . '.';
    $out[] = '- Talk the way people talk in a room: short turns, plain words. You can trail off,'
           . ' change the subject, or let something sit.';
    // Learned against the stand-in on day one: a local model seated in a scene
    // reverts to its roleplay dataset — *I polish the compass* between every
    // sentence, quotes around each spoken paragraph. The cleaner only strips
    // wrappers (chat.php: a cleaner that eats a real line is worse than an
    // asterisk let through), so the interior ones have to be talked out of
    // existence rather than cut out of it.
    $out[] = '- Only the words you say out loud. No stage directions, no asterisks, no'
           . ' quotation marks, no narrating your hands or the room.';
    $out[] = '- Everything above this section is still true: who you are, what you hold back,'
           . ' what you remember, and everything you were told never to do.';
    return implode("\n", $out) . "\n";
}

/**
 * What seeds one speaker's side: their own memories of the other, and the
 * last hour the two of them were both at.
 *
 * The memory scan reuses xeric_age_mentions() — it is the engine's one
 * "is this text about that person" test (handle, full name, the parts of a
 * name, generic words dropped), and a second copy grown here would drift.
 * Newest first, capped at XERIC_DUET_MATERIAL: what is still unresolved is
 * by definition recent, and a tail that recites the whole history is a
 * biography, not an itch.
 *
 * The shared event is screened per READER: a spine-marked title is the
 * world's secret with "name the thing" applied to it (sweeps.php), so a
 * protected reader's seed simply skips those rows — the same filter
 * xeric_sweep_prompt() applies to its ALREADY HAPPENED block, for the same
 * head.
 *
 * @return array{memories:array<int,string>,event:?string}
 */
function xeric_duet_material(array $t, PDO $db, string $me, string $other, array $protected): array
{
    $otherName = xeric_world_name($t, $other);

    $memories = [];
    foreach (array_reverse(xeric_memories_for($db, $me, 40)) as $m) {
        $text = trim((string)$m['text']);
        if ($text === '' || !xeric_age_mentions($text, $other, $otherName)) continue;
        $memories[] = $text;
        if (count($memories) >= XERIC_DUET_MATERIAL) break;
    }

    $shared = null;
    foreach (xeric_events_recent($db, 12) as $e) {
        $who = (array)($e['participants'] ?? []);
        if (!in_array($me, $who, true) || !in_array($other, $who, true)) continue;
        if (!empty($e['on_spine']) && isset($protected[$me])) continue;
        $shared = trim((string)$e['title']);
        break;
    }

    return ['memories' => $memories, 'event' => $shared !== '' ? $shared : null];
}

/** The material, summarised for the trail. Counts and a title — never a memory's words. */
function xeric_duet_material_why(array $names, array $material, string $a, string $b): string
{
    $bits = [];
    foreach ([$a, $b] as $h) {
        $n = count((array)($material[$h]['memories'] ?? []));
        if ($n > 0) $bits[] = $names[$h] . ' carried ' . $n . ' unsettled thing' . ($n === 1 ? '' : 's')
                            . ' about ' . $names[$h === $a ? $b : $a];
    }
    $ev = (string)($material[$a]['event'] ?? $material[$b]['event'] ?? '');
    if ($ev !== '') $bits[] = 'the town remembers "' . $ev . '"';
    return $bits !== [] ? ucfirst(implode('; ', $bits)) . '.' : 'Nothing was on record between them yet.';
}

/**
 * The static half of one speaker's volatile tail: the scene, and what they
 * carry into it. Constant for this speaker across the whole duet.
 *
 * The place is named only when this speaker's own walls would let the room
 * line say it (xeric_prompt_now_block suppresses the room under a schedules
 * or per-place wall, and a seed that named it anyway would hand back exactly
 * what the wall removed). The partner is still named — the eyesight rule the
 * system block argues — so a fully walled speaker still knows WHO they are
 * talking to, just not what the map calls where they are standing.
 */
function xeric_duet_scene(array $t, string $me, string $other, array $room, array $walls, array $material): array
{
    $partner = xeric_world_name($t, $other);
    $key     = (string)$room['where'];
    $sayable = !xeric_hidden($walls, 'schedules') && !xeric_hidden($walls, 'places.' . $key);

    $lines = ['THE SCENE'];
    $lines[] = $sayable && $room['place_name'] !== ''
        ? 'You and ' . $partner . ' are both at ' . $room['place_name'] . ' right now, in the same room.'
        : 'You and ' . $partner . ' are in the same room right now.';
    $d = trim((string)($room['doing'][$other] ?? ''));
    if ($d !== '' && $sayable) $lines[] = $partner . ' is here because: ' . $d . '.';

    if ($material['memories'] !== []) {
        $lines[] = 'Between you, still sitting there (yours alone — ' . $partner
                 . ' does not know you are chewing on these):';
        foreach ($material['memories'] as $m) $lines[] = '- ' . xeric_sentence($m);
    }
    if (($material['event'] ?? null) !== null) {
        $lines[] = 'The last thing you two were both at, as the town tells it: "'
                 . (string)$material['event'] . '".';
    }
    return $lines;
}

/**
 * One speaker's messages for one line: their system, the spoken transcript
 * from their side of the table, and the volatile block at the very bottom.
 *
 * The transcript mapping IS the wall: a speaker's own lines come back as
 * assistant turns, the partner's as user turns, and nothing else of the
 * partner exists in this array. Information crosses the table as speech or
 * not at all — which is the entire reason each line is its own call.
 *
 * The volatile block is xeric_prompt_now_block() — the same clock, room,
 * walls, and deaths every chat turn gets, so a death that landed an hour ago
 * is present-tense-proof here too — with the duet's scene and one line of
 * turn coaching riding as the tail. lastSpoke stays 0: the gap line narrates
 * distance between conversations, and a duet is one continuous scene.
 */
function xeric_duet_messages(array $t, string $me, string $other, string $system, array $lines,
                             array $scene, array $now, array $walls, array $deaths,
                             bool $opening, bool $closing, ?string $playerWhere = null): array
{
    $partner = xeric_world_name($t, $other);

    $coach = $scene;
    if ($opening) {
        $coach[] = 'You speak first. Start in the middle of the moment, off the thing in front of you'
                 . ' or the thing you have been meaning to say to ' . $partner . '. A line or three, out loud.';
    } elseif ($closing) {
        $coach[] = 'Answer ' . $partner . '. This is the last thing said before you both get back to it,'
                 . ' so let it land — do not wrap the conversation up neatly, do not say goodbye like a scene ending.';
    } else {
        $coach[] = 'Answer ' . $partner . '. A line or three, out loud. It is a conversation, not a speech.';
    }

    // The player is nowhere in this block BY DEFAULT (null): the duet is the
    // world talking to itself, and "X is here, in the room with you" about
    // somebody who is not part of the scene would put the user in it. The
    // WALK-IN is the exception the watch surface asked for: when the player
    // has genuinely entered the room, their position threads through so the
    // now-block seats them — and the speaker is told a third person is
    // standing there, which is the difference between answering your partner
    // and answering your partner in front of company.
    if ($playerWhere !== null && $playerWhere !== '') {
        $coach[] = 'A third voice in the transcript is the person standing here with you both — '
                 . 'answer whoever the moment calls for.';
    }
    $volatile = xeric_prompt_now_block($t, $me, $now, implode("\n", $coach), $walls, $playerWhere, $deaths, 0, $db);

    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($lines as $l) {
        $messages[] = [
            'role'    => (string)$l['handle'] === $me ? 'assistant' : 'user',
            'content' => (string)$l['text'],
        ];
    }

    // prompt.php's own seating for the clock: ride the last user message when
    // there is one, stand alone when the speaker opens.
    $lastIdx = count($messages) - 1;
    if ($messages[$lastIdx]['role'] === 'user') {
        $messages[$lastIdx]['content'] .= "\n\n" . $volatile;
    } else {
        $messages[] = ['role' => 'user', 'content' => $volatile];
    }
    return $messages;
}

// ---------------------------------------------------------------------------
// The close: two diaries
// ---------------------------------------------------------------------------

/**
 * One speaker's memory of the duet: one cheap call through chat.php's
 * extraction seam (xeric_chat_json, tag 'extract'), the CHANGE rule verbatim.
 *
 * This mirrors xeric_chat_extract() rather than calling it, and the
 * difference is per-conversation, not taste: that function reads a stored
 * thread whose 'character' rows are all one voice and whose other party is
 * the user — both wrong here, where the transcript is in hand, both voices
 * are cast, and the subject line must name the partner. What IS reused is
 * everything that made extraction safe: the seam and its tag, the third-
 * person CHANGE framing, the dedupe against what is already carried, the age
 * floor on every line on its way in, and — new here, because both parties
 * are cast — the sweep's protected-secret screen and its echo threshold
 * across the TWO diaries: the second harvest is deduped against the first at
 * XERIC_SWEEP_ECHO, because two people who were at the same thing must not
 * remember the same sentence (divergent memories are the point of a sweep,
 * and of this).
 *
 * The promise watch does not ride this call: constructs are what the USER
 * owes a character, and neither voice here is the user. When the constructs
 * grow a character↔character shape, it rides here the way it rides chat.
 *
 * WRITES NOTHING. Returns what the close should store.
 */
function xeric_duet_extract(array $t, PDO $db, string $me, string $other, array $lines,
                            array $endpoint, array $room, array $handles, array $protected,
                            array $priorKept, array $opts): array
{
    $name    = xeric_world_name($t, $me);
    $partner = xeric_world_name($t, $other);

    $existing = xeric_memories_for($db, $me, 40);
    $known    = array_map(fn($m) => (string)$m['text'], $existing);

    $spoken = [];
    foreach ($lines as $l) $spoken[] = (string)$l['name'] . ': ' . trim((string)$l['text']);

    $c   = xeric_world_character($t, $me);
    $who = $name . ($c && ($c['one_line'] ?? '') !== '' ? ', ' . (string)$c['one_line'] : '');

    $msgs = [
        ['role' => 'system', 'content' =>
            'You keep one person\'s memory for a story engine. You read a conversation they were part of '
            . 'and write down what THEY would still know about it in a week. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            $who . "\nThey were talking with {$partner}, face to face"
            . ($room['place_name'] !== '' ? ' at ' . $room['place_name'] : '') . ".\n\n"
            . ($known ? "Already in their memory, do not write any of these again:\n- " . implode("\n- ", $known) . "\n\n" : '')
            . "The conversation:\n" . implode("\n", $spoken) . "\n\n"
            . "Write 0-3 things {$name} would still know in a week.\n"
            . "- Third person, past tense, naming {$name} and {$partner}.\n"
            . "- One sentence each, under 25 words, concrete.\n"
            . "- Prefer what CHANGED, what was admitted, promised, refused, learned or agreed, "
            . "over the bare fact that something was said.\n"
            . "- Only what {$name} saw or heard above. Their own half and what {$partner} actually said. Invent nothing.\n"
            . "- Nothing already in the list above. If there is nothing new worth keeping, return an empty list.\n"
            . "{ \"memories\": [\"…\"] }\nNo prose outside the JSON."],
    ];

    $raw = xeric_chat_json($endpoint, 'extract', $msgs,
        ['temperature' => 0.4, 'max_tokens' => 400] + array_intersect_key($opts, ['timeout' => 1]));

    $across = [];
    foreach ($priorKept as $list) foreach ((array)$list as $line) $across[] = (string)$line;

    $keep = [];
    foreach ((array)($raw['memories'] ?? []) as $m) {
        if (is_array($m)) $m = $m['text'] ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', (string)$m) ?? '');
        if ($text === '' || mb_strlen($text) < 8) continue;
        if (mb_strlen($text) > 240) $text = rtrim(mb_substr($text, 0, 240)) . '…';
        if (xeric_chat_is_dupe($text, array_merge($known, $keep))) continue;
        // The floor on the way into a head, per line, room handles — the same
        // screening chat's extraction applies, at the sweep's roomful scope.
        if (xeric_age_floor($t, $handles, [$text]) !== null) continue;
        // A protected head does not get to write the secret down for itself:
        // the spoken lines were screened, but an extractor can synthesise.
        if (isset($protected[$me]) && xeric_sweep_touches($text, (string)$protected[$me])) continue;
        // And not the other diary's sentence again: one hour, two halves.
        if ($across !== [] && xeric_chat_is_dupe($text, $across, XERIC_SWEEP_ECHO)) continue;
        $keep[] = $text;
        if (count($keep) >= 3) break;
    }
    return $keep;
}

// ---------------------------------------------------------------------------
// The record: commons by construction
// ---------------------------------------------------------------------------

/**
 * The event title. Engine-written, deterministic, lower case like a sweep's:
 * it names the thing (they talked) and does not summarise it. No model comes
 * near the record of the hour, which is what "commons-prose only" costs.
 */
function xeric_duet_title(string $nameA, string $nameB): string
{
    $firstOf = fn(string $n): string => (string)(preg_split('/\s+/u', trim($n)) ?: [$n])[0];
    return mb_strtolower($firstOf($nameA) . ' and ' . $firstOf($nameB) . ' talked');
}

/**
 * The event prose: what anyone standing nearby could have seen, and not one
 * word more. Names only, never pronouns (engine-side prose is never gendered
 * — template data carries the pronouns, and this is not template data), and
 * nothing either of them SAID: the diaries carry what each took away, behind
 * their own handles, and the transcript belongs to whoever watched it happen.
 */
function xeric_duet_prose(array $t, string $nameA, string $nameB, array $room, array $now): string
{
    $when  = trim(xeric_world_day_name((int)($now['dow'] ?? 0)) . ' ' . (string)($now['phase'] ?? ''));
    $where = $room['place_name'] !== '' ? ' at ' . $room['place_name'] : '';
    return $nameA . ' and ' . $nameB . ' talked' . $where
         . ($when !== '' ? ' on ' . $when : '') . '. '
         . 'Anyone nearby could have seen the two of them at it; what was said stayed between them.';
}
