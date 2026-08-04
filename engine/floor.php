<?php
/**
 * Xeric — the floor. What a room does in the seconds after you say something
 * in it.
 *
 * WHAT THIS REPLACES. The first wiring of "you type into a place" force-fired
 * the idle-chatter machinery at three lines, so every word said into a room got
 * exactly three replies — a vending machine with a cast list. Three answers to
 * "morning" is wrong the same way zero answers to "did any of you take it?" is
 * wrong: the NUMBER of people who answer you is itself a fact about the room,
 * and a fixed number says nothing. This file makes the count an outcome.
 *
 * WHAT THIS IS NOT. Not chatter.php: that fills a channel on a cadence whether
 * or not anybody is looking, and it stays exactly as it was. Not room.php: that
 * is a scene the user watches from outside; here the user is IN the room, and
 * every register decision below follows from that. One law is inherited from
 * both, verbatim, because it is load-bearing everywhere: THE ONE-CALL SHORTCUT
 * IS FATAL AND WILL LOOK RIGHT. Every spoken line is its own call from that
 * speaker's own assembly; the only thing that crosses between heads is what
 * was actually said.
 *
 * ── WHO IS ASKED ──────────────────────────────────────────────────────────
 *
 * The scene's own draw (xeric_room_draw's rules, re-derived here with the user
 * as the last speaker): being named outranks everything, and among the unnamed
 * the person with the most unsettled material about the user is likeliest —
 * counted as rows in their own memories, never paraphrased. The weights ride
 * the same weighted shuffle every floor in this engine uses, so the loudest
 * head buys a better chance of going first and nothing more. The whole order
 * lands in the trail: "why did she answer?" has a numeric answer with names.
 *
 * ── PERMISSION TO PASS ────────────────────────────────────────────────────
 *
 * A drawn head is INVITED, not conscripted. Anyone reached by the draw may
 * answer with the literal marker `(stays quiet)` and the floor moves on to the
 * next candidate — because the character deciding to stay out of it, from
 * inside their own walls and their own mood, is the only honest place that
 * decision can live. An engine that picked the "right" responder from outside
 * would need to read every head at once, which is the one-call shortcut wearing
 * a clipboard.
 *
 * The single exception: the FIRST head the user addressed BY NAME must say
 * something. A question with your name on it is not a suggestion — that is the
 * scene's oldest rule — and the escape from it lives in the prose: the
 * addressed may deflect, refuse, or change the subject, out loud. A marker
 * from an unlicensed mouth is treated as an empty reply and the floor moves
 * on; the balk is recorded.
 *
 * ── SILENCE IS AN ANSWER ──────────────────────────────────────────────────
 *
 * Everyone passing is not a failure state, it is the room declining to pick it
 * up — real rooms do it to real people every day. It lands in the channel as a
 * narrator line (names only, never a pronoun), so the scrollback shows the
 * beat happening instead of a question hanging unanswered by machinery. The
 * next head to speak in this channel reads that line as "(what happened)" and
 * knows the room let it sit.
 *
 * ── DISCRETION, BEFORE THE WALL ───────────────────────────────────────────
 *
 * The channel already had the wall POST-check: a line that puts a protected
 * listener next to the thing they must not know is dropped after the model
 * writes it. This file adds the instruction a person would actually have —
 * "she is standing right here, don't" — BEFORE the call, in the volatile
 * block, so the model steers instead of being censored. Gated fail-closed on
 * one test: the discretion line is injected ONLY when the speaker's own
 * assembled system already carries the secret (xeric_sweep_touches over their
 * own prompt). A speaker whose walls hide the secret must not learn it from
 * the instruction telling them to keep it — for them the post-check alone
 * stands, and it always stands for everybody; discretion is steering, never
 * the fence.
 *
 * ── ONE OR TWO VOICES, NEVER A QUOTA ──────────────────────────────────────
 *
 * The first landed line usually ends the beat. Sometimes — a rolled chance,
 * seedable, inspectable — one more head is invited to pile on, always with
 * license to pass. Two voices is a room reacting; three on a timer was the
 * vending machine this file exists to retire. The heartbeat's idle chatter
 * keeps the room going afterwards at its own pace, warmed by the fact you
 * spoke (chatter.php's WARM rule reads the user line this beat answered).
 *
 * Zero dependencies beyond the engine. PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/chatter.php';   // the channel: presence, system, scrollback, conversation
require_once __DIR__ . '/clock.php';     // the default `now` when the caller brings none
require_once __DIR__ . '/learn.php';     // the weighted shuffle, and reach
require_once __DIR__ . '/death.php';

/** Most heads asked per beat — the model-call ceiling, whatever the passes do. */
const XERIC_FLOOR_ASKS = 3;

/** Most voices that land per beat. One is an answer; two is a reaction. */
const XERIC_FLOOR_VOICES = 2;

/** After the first voice lands, the chance one more is invited to pile on. */
const XERIC_FLOOR_INTERJECT = 0.35;

/** The pass marker, exactly. A model saying it gives the floor back. */
const XERIC_FLOOR_PASS = '(stays quiet)';

/** A spoken line, not a speech — the channel's own ceiling. */
const XERIC_FLOOR_MAX_CHARS = 400;

// ---------------------------------------------------------------------------
// The beat
// ---------------------------------------------------------------------------

/**
 * The room answers one line said into it. The user's line is ALREADY in the
 * channel — the caller lands it before asking, because a model that will not
 * answer does not un-say what a person said — and this reads it back from the
 * scrollback like every other head does.
 *
 * Saves as it goes (the channel's discipline, not the scene's): a beat that
 * dies after one landed line has still genuinely had one thing said in it.
 *
 * @param string $userText what was just said, verbatim — the addressed scan
 *                         and the age floor read it; it is never re-written
 * @param array  $opts     now, real_now, seed, temperature, timeout, max_chars,
 *                         interject (0..1), effective_rating, model_rating,
 *                         on_line(handle,name,text), on_why(kind,handle,detail)
 * @return array{said:int,lines:array<int,array{handle:string,name:string,text:string}>,
 *               silence:bool,note:string,place:string,rating:string,
 *               asked:array<int,string>,passed:array<int,string>,trail:array}
 */
function xeric_floor_answer(array $t, PDO $db, string $place, string $userText,
                            array $endpoint, array $opts = []): array
{
    if (isset($opts['seed'])) mt_srand((int)$opts['seed']);   // the scene's idiom

    $userText = trim($userText);
    if ($userText === '') {
        throw new RuntimeException('floor: there is nothing to answer');
    }

    $realNow = (int)($opts['real_now'] ?? time());
    $now     = $opts['now'] ?? xeric_clock_now($db, $t, $realNow);
    $epoch   = (int)($now['epoch'] ?? 0);
    $out     = ['said' => 0, 'lines' => [], 'silence' => false, 'note' => '',
                'place' => $place, 'rating' => '', 'asked' => [], 'passed' => [], 'trail' => []];

    // The beat only answers where a person may speak: standing in the place.
    // The caller enforces the standing; this is the belt to that brace.
    if (!xeric_chatter_readable($t, $place, $place)) { $out['silence'] = true; return $out; }

    $present = xeric_chatter_present($t, $db, $place, $now);
    if ($present === []) {
        // Nobody to answer is not silence chosen, it is silence of the empty
        // kind, and the caller already told the person the room was empty.
        $out['silence'] = true;
        return $out;
    }

    $conv  = xeric_chatter_conversation($t, $db, $place);
    $names = [];
    foreach ($present as $h) $names[$h] = xeric_world_name($t, $h);
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'you';

    // THE CEILING IS THE LOWEST PERSON STANDING IN IT — folded once, before a
    // single call, never re-raised (the sweep's sentence, every screen's fold).
    $eff = (string)($opts['effective_rating'] ?? xeric_world_rating($t, $opts['model_rating'] ?? null));
    foreach ($present as $h) {
        $who = xeric_viewer($t, ['handle' => $h]);
        if ($who['is_minor']) $eff = xeric_viewer_rating($eff, $who);
    }
    $out['rating'] = $eff;
    $protected = xeric_sweep_protected($t);
    $deaths    = xeric_dead_handles($db);

    // -- the order: named first, then heat, then the thumb -------------------
    $memrows = [];
    $reach   = [];
    foreach ($present as $h) {
        $memrows[$h] = xeric_memories_for($db, $h, 12);
        $reach[$h]   = xeric_learn_reach($db, $h);
    }
    $order = xeric_floor_order($present, $userText, $userName, $names, $reach, $memrows);

    $back = xeric_chatter_scrollback($t, $db, $conv, XERIC_CHATTER_KEEP);

    $asks      = 0;
    $balked    = [];
    $dropped   = [];
    $discreet  = [];
    $answers   = $userText;          // the line the next voice is answering
    $interject = (float)($opts['interject'] ?? XERIC_FLOOR_INTERJECT);
    $t0        = microtime(true);

    foreach ($order['order'] as $me) {
        if ($asks >= XERIC_FLOOR_ASKS || $out['said'] >= XERIC_FLOOR_VOICES) break;

        // The first ADDRESSED head must say something; everyone else may pass.
        $licensed = !($order['addressed'] !== [] && $me === $order['order'][0]
                      && in_array($me, $order['addressed'], true));

        $others = array_values(array_diff($present, [$me]));
        $walls  = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $me]));
        $system = xeric_chatter_system($t, $db, $me, $others, $place, $eff, $epoch, $walls,
                                       XERIC_CHATTER_KEEP, true);

        $coach = xeric_chatter_material($t, $db, $me, $others);

        // DISCRETION — the standing-right-here instruction, gated on the
        // speaker's own prompt already carrying the secret (header). Volatile
        // on purpose: it depends on who is present, and the system string must
        // stay byte-stable with the idle channel's so the cache holds.
        foreach (xeric_floor_discretion($t, $me, $present, $protected, $names, $system) as $ph => $line) {
            $coach[] = $line;
            $discreet[$me][] = $ph;
        }

        // THE OPENER TIC (xeric_chat_tic_line): the channel window is the very
        // transcript feeding the prior, so name the words this head came in on
        // last time and retire them.
        $prev = '';
        foreach (array_reverse($back) as $l) {
            if ($l['role'] === 'character' && $l['handle'] === $me) { $prev = (string)$l['text']; break; }
        }
        $tic = xeric_chat_tic_line($prev);
        if ($tic !== '') $coach[] = $tic;

        $coach[] = $userName . ' said that out loud, to the room, just now. This is the moment after.';
        $coach[] = $licensed
            ? 'Answer if it is yours to answer. If it genuinely is not — not aimed at you, nothing'
              . ' you would add — reply with exactly ' . XERIC_FLOOR_PASS . ' and nothing else,'
              . ' and the moment passes to somebody else or to nobody.'
            : 'That had your name on it. Answer it, deflect it, or push back — out loud, in words.'
              . ' Staying silent is not one of your choices this time.';
        $coach[] = 'One short turn, spoken. Not a paragraph.';

        $volatile = xeric_prompt_now_block($t, $me, $now, implode("\n", $coach), $walls, null,
                                           $deaths, 0, $db);

        // The transcript, from this head's side of the counter — the channel's
        // own mapping: my lines come back as assistant turns, everything else
        // arrives labeled as one merged user turn.
        $messages = [['role' => 'system', 'content' => $system]];
        $pending  = [];
        $flush = function () use (&$messages, &$pending): void {
            if ($pending !== []) {
                $messages[] = ['role' => 'user', 'content' => implode("\n", $pending)];
                $pending = [];
            }
        };
        foreach ($back as $l) {
            if ($l['role'] === 'character' && $l['handle'] === $me) {
                $flush();
                $messages[] = ['role' => 'assistant', 'content' => $l['text']];
            } elseif ($l['role'] === 'narrator') {
                $pending[] = '(what happened) ' . $l['text'];
            } else {
                $pending[] = $l['name'] . ': ' . $l['text'];
            }
        }
        $flush();
        $lastIdx = count($messages) - 1;
        if ($messages[$lastIdx]['role'] === 'user') {
            $messages[$lastIdx]['content'] .= "\n\n" . $volatile;
        } else {
            $messages[] = ['role' => 'user', 'content' => $volatile];
        }

        $out['asked'][] = $me;
        $asks++;
        try {
            $raw = xeric_chat_say($endpoint, $messages, [
                'temperature' => (float)($opts['temperature'] ?? 0.85),
                'max_tokens'  => 200,
            ] + array_intersect_key($opts, ['timeout' => 1]));
        } catch (Throwable $e) {
            // One dead call does not end the beat; the next head is its own call.
            if (!empty($opts['on_why'])) ($opts['on_why'])('call', $me, $e->getMessage());
            $dropped[] = ['who' => $me, 'why' => 'call'];
            continue;
        }

        // The pass, read RAW and before any cleaning — hygiene would strip the
        // parentheses and hand back the words as speech.
        if (xeric_floor_passed($raw)) {
            if ($licensed) {
                $out['passed'][] = $me;
                continue;
            }
            // An unlicensed mouth handing back the marker balked; the floor
            // moves on, and the trail says so — an addressed head that would
            // not answer is a fact the inspector should be able to show.
            $balked[] = $me;
            if (!empty($opts['on_why'])) ($opts['on_why'])('balk', $me, 'addressed, returned the pass marker');
            continue;
        }

        $otherNames   = array_map(fn($h) => $names[$h], $others);
        $otherNames[] = $userName;
        $text = xeric_room_clean($raw, $names[$me], $otherNames,
                                 (int)($opts['max_chars'] ?? XERIC_FLOOR_MAX_CHARS));
        if ($text === '') {
            if (!empty($opts['on_why'])) ($opts['on_why'])('empty', $me, mb_substr((string)$raw, 0, 200));
            $dropped[] = ['who' => $me, 'why' => 'empty'];
            continue;
        }

        // THE AGE FLOOR, the turn read as one piece — the line and the line it
        // answers — against everyone standing in the room (roomful scope).
        if (xeric_age_floor($t, $present, [$answers, $text]) !== null) {
            if (!empty($opts['on_why'])) ($opts['on_why'])('age', $me, mb_substr($text, 0, 120));
            $dropped[] = ['who' => $me, 'why' => 'age'];
            continue;
        }

        // THE WALL, after the model, over who is PRESENT — discretion steered,
        // this enforces. A dropped line is a beat of silence; a leak is not.
        $leaks = false;
        foreach ($protected as $ph => $secret) {
            if (in_array($ph, $present, true) && xeric_sweep_touches($text, $secret)) { $leaks = true; break; }
        }
        if ($leaks) {
            if (!empty($opts['on_why'])) ($opts['on_why'])('wall', $me, mb_substr($text, 0, 120));
            $dropped[] = ['who' => $me, 'why' => 'wall'];
            continue;
        }

        xeric_message_append($db, $conv, 'character', $me, $text, $epoch, $realNow);
        $out['lines'][] = ['handle' => $me, 'name' => $names[$me], 'text' => $text];
        $out['said']++;
        if (!empty($opts['on_line'])) ($opts['on_line'])($me, $names[$me], $text);

        $answers = $text;
        $back[]  = ['handle' => $me, 'name' => $names[$me], 'text' => $text, 'role' => 'character'];
        if (count($back) > XERIC_CHATTER_KEEP) array_shift($back);

        // One voice is an answer. A second is a rolled chance, never a quota.
        if ($out['said'] >= XERIC_FLOOR_VOICES) break;
        if ((mt_rand() / mt_getrandmax()) >= $interject) break;
    }

    // -- silence, landed as a thing that happened ----------------------------
    if ($out['said'] === 0) {
        $out['silence'] = true;
        $out['note']    = xeric_floor_silence($present, $names);
        try {
            xeric_message_append($db, $conv, 'narrator', null, $out['note'], $epoch, $realNow);
        } catch (Throwable $e) {
            // The beat happened; the room's shrug is ours to lose.
        }
    }

    // The channel was just answered; the idle beat should not pile on in the
    // same minute. Same watermark the chatter stamps, same reason.
    xeric_world_state_set($db, 'chatter:' . $place, (string)$realNow, $realNow);
    // The person is staring at the room; nothing said in front of them is news.
    xeric_conversation_touch($db, $conv, 0, $realNow);

    // -- the trail: dice with receipts, under the inspector's own key --------
    $out['trail'] = [
        'kind'       => 'floor',
        'place'      => $place,
        'present'    => $present,
        'addressed'  => $order['addressed'],
        'weights'    => $order['weights'],
        'asked'      => $out['asked'],
        'spoke'      => array_map(fn($l) => $l['handle'], $out['lines']),
        'passed'     => $out['passed'],
        'balked'     => $balked,
        'dropped'    => $dropped,
        'discretion' => array_map('array_values', $discreet),   // names only, never the secret
        'silence'    => $out['silence'],
        'rating'     => $eff,
        'ms'         => (int)round((microtime(true) - $t0) * 1000),
        'at'         => $realNow,
    ];
    xeric_world_state_set($db, 'why:floor:' . $place, json_encode($out['trail'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $realNow);

    return $out;
}

// ---------------------------------------------------------------------------
// The order: who is reached, and in what order
// ---------------------------------------------------------------------------

/**
 * Everyone present, in the order the floor will reach them: the addressed tier
 * first (a name in the sentence is the selection), each tier internally in
 * weighted-shuffle order — reach × material heat, where heat counts this
 * head's own stored memories that mention the user, as rows, capped. The same
 * arithmetic as the scene's draw with the user standing where the last speaker
 * stood; re-derived rather than called because xeric_room_draw() picks ONE
 * head and the floor needs the whole line of succession.
 *
 * @return array{order:array<int,string>,addressed:array<int,string>,weights:array<string,float>}
 */
function xeric_floor_order(array $present, string $userText, string $userName,
                           array $names, array $reach, array $memrows): array
{
    $addressed = [];
    foreach ($present as $h) {
        if (xeric_age_mentions($userText, $h, $names[$h])) $addressed[] = $h;
    }

    $weights = [];
    foreach ($present as $h) {
        $hot = 0;
        foreach (array_reverse($memrows[$h] ?? []) as $m) {
            $text = trim((string)($m['text'] ?? ''));
            if ($text !== '' && xeric_age_mentions($text, $userName, $userName)) $hot++;
            if ($hot >= XERIC_ROOM_MATERIAL) break;
        }
        $weights[$h] = round((float)($reach[$h] ?? 1.0) * (1.0 + XERIC_ROOM_HEAT * $hot), 3);
    }

    $rest  = array_values(array_diff($present, $addressed));
    $order = array_merge(
        $addressed !== [] ? xeric_learn_order($addressed, $weights) : [],
        $rest !== []      ? xeric_learn_order($rest, $weights)      : []
    );

    return ['order' => $order, 'addressed' => $addressed, 'weights' => $weights];
}

// ---------------------------------------------------------------------------
// The pass, the discretion, the shrug
// ---------------------------------------------------------------------------

/**
 * Did the model hand the floor back? Read raw, before hygiene — the cleaner
 * strips parentheses and would return the marker's words as a spoken line.
 * Generous about wrapping (quotes, a trailing period, case) and strict about
 * content: a line that SAYS something and also contains the words "stays
 * quiet" is speech, not a pass.
 */
function xeric_floor_passed(string $raw): bool
{
    $s = trim(xeric_chat_strip_fence($raw));
    return (bool)preg_match('/^[\s"\'()\[\]*_]*stays?\s+quiet[\s"\'()\[\]*_.!]*$/iu', $s);
}

/**
 * The standing-right-here lines for one speaker, keyed by the protected
 * handle. Injected ONLY when this speaker's own assembled system already
 * carries the secret (fail closed — an instruction that teaches the secret to
 * a walled head IS the leak it names), and never for the protected head
 * themselves, whose walls already did the work. The wording is the sweep's
 * blind-spot idiom with the one fact only this beat has: they are in the room.
 *
 * @return array<string,string> protected handle => the instruction
 */
function xeric_floor_discretion(array $t, string $me, array $present, array $protected,
                                array $names, string $system): array
{
    $out = [];
    foreach ($protected as $ph => $secret) {
        if ($ph === $me) continue;
        if (!in_array($ph, $present, true)) continue;
        if (!xeric_sweep_touches($system, (string)$secret)) continue;
        $who = $names[$ph] ?? xeric_world_name($t, $ph);
        $out[$ph] = $who . ' is standing right here, and NOTHING you say may touch '
                  . $who . '\'s blind spot: ' . $secret . '. They do not learn it, hear it,'
                  . ' or half-hear it from you. If the talk drifts near it, steer somewhere else.';
    }
    return $out;
}

/**
 * What the room's shrug looks like in the scrollback. Names only, never a
 * pronoun (pronouns are template data, and this line is engine prose); with
 * one person it is theirs by name, with several it belongs to the room.
 */
function xeric_floor_silence(array $present, array $names): string
{
    if (count($present) === 1) {
        return $names[$present[0]] . ' lets it sit.';
    }
    return 'Nobody picks it up.';
}
