<?php
/**
 * Xeric — idle chatter. What people say in a room when nothing is happening.
 *
 * WHAT THIS IS NOT. It is not room.php, and the difference is the whole point.
 * A room is a SCENE: three to five people, N beats, a floor draw, diaries at the
 * close, and a transcript that is streamed and then thrown away. It is a thing
 * you watch, it happens because you asked for it, and it leaves one event behind.
 *
 * This is a CHANNEL. The heartbeat fills it whether or not anybody is looking;
 * every line is saved like any other message; you walk in and read what was said
 * while you were gone, including what was said about you. Then you type, and
 * they answer you, because you are in the room and not in the audience.
 *
 * ── FREE WILL, EXCEPT NO STORY LINE ───────────────────────────────────────
 *
 * The owner's phrase, and it is the specification. The sweep hands people a
 * SHAPE — "they ended up eating together, what was brought, who paid, who
 * stayed after" — and a shape is a small plot. Chatter hands them the room and
 * nothing else. Jokes, the weather, a memory about somebody, a rumour about
 * what happened last week, complaining about the shift. None of it advances
 * anything. That is not a limitation of the feature, it IS the feature: a town
 * where every conversation is load-bearing is a town nobody lives in.
 *
 * So the material below is offered, never assigned. What each head gets is what
 * it would actually have — its own memories, the hours it was present for, the
 * sky over the building — and no instruction about what to do with any of it.
 *
 * ── ONE CALL PER SPEAKER, INHERITED VERBATIM ──────────────────────────────
 *
 * room.php's first law and it carries over whole: THE ONE-CALL SHORTCUT IS
 * FATAL AND WILL LOOK RIGHT. One prompt producing four voices is four
 * assemblies collapsed into one, which is four sets of walls failing at once,
 * and it will read beautifully while it does it. Every line here is its own
 * call from that speaker's own assembly, and the only thing crossing between
 * heads is what was actually SAID.
 *
 * Idle talk is cheaper per line than a scene — no diary extraction, no
 * why-trail, no close — but it is not free, and the pace setting is the dial.
 *
 * ── THE PACE SETTING IS THE SPEND ─────────────────────────────────────────
 *
 * calm     1-2 lines every 10 minutes, per room
 * steady   2-3 lines every 5 minutes
 * busy     5-6 lines every 5 minutes
 *
 * And a room you are TALKING IN is busy whatever the world is set to, because
 * the alternative is a calm world answering you every forty seconds while you
 * stand there. Presence is not what makes a room busy — talking is. Somebody
 * reading the scrollback of a quiet bar should get a quiet bar.
 *
 * ── WHY THE PLAYER CHANGES THE ASSEMBLY ───────────────────────────────────
 *
 * room.php tells every speaker, in as many words: "<user> is not here. Nothing
 * said here is addressed to <user> and nobody reads it back to <user>." That is
 * exactly right for a scene nobody saved and exactly wrong for a channel with
 * scrollback. Here they are told the truth instead — that this is a room in
 * their town, that the person may be standing in it, and that what is said in
 * it stays said. Which is what makes badmouthing somebody mean anything.
 *
 * Zero dependencies beyond the engine. PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/clock.php';    // xeric_chatter_run defaults to xeric_clock_now
require_once __DIR__ . '/room.php';     // the floor draw, the reply hygiene, the walls idiom
require_once __DIR__ . '/learn.php';
require_once __DIR__ . '/death.php';
require_once __DIR__ . '/weather.php';

/** You spoke in this room this recently, so it is busy whatever the world says. */
const XERIC_CHATTER_WARM = 900;

/** Scrollback fed back into each head. Enough to answer, not a biography. */
const XERIC_CHATTER_KEEP = 24;

/** Nobody talks to themselves: a room needs this many people standing in it. */
const XERIC_CHATTER_MIN = 2;

/** A hard ceiling per fire, whatever the arithmetic below says. */
const XERIC_CHATTER_CAP = 6;

/**
 * How often, and how many.
 *
 * @return array{0:int,1:int,2:int} seconds between fires, fewest lines, most
 */
function xeric_chatter_rate(string $pace): array
{
    return match (mb_strtolower(trim($pace))) {
        'eventful' => [300, 5, 6],
        'calm'     => [600, 1, 2],
        default    => [300, 2, 3],
    };
}

/** This world's pace, as the one field that already holds it. */
function xeric_chatter_pace(array $t): string
{
    $p = mb_strtolower(trim((string)($t['events']['pace'] ?? 'steady')));
    return in_array($p, ['eventful', 'steady', 'calm'], true) ? $p : 'steady';
}

/**
 * The channel for one place. Keyed by the PLACE, which is why no migration was
 * needed: `conversations.handle` has never been checked against the cast, and
 * `messages.handle` already says who spoke per line. A group chat was
 * representable in this schema the whole time; nothing had written one.
 */
function xeric_chatter_conversation(array $t, PDO $db, string $place): int
{
    return xeric_conversation_for($db, $place, 'room', xeric_world_place_name($t, $place));
}

/** Did the person say something here lately? Then the room is busy. */
function xeric_chatter_warm(PDO $db, int $conversationId, int $realNow): bool
{
    $st = $db->prepare("SELECT created_at FROM messages
                        WHERE conversation_id = ? AND role = 'user'
                        ORDER BY id DESC LIMIT 1");
    $st->execute([$conversationId]);
    $at = $st->fetchColumn();
    $st->closeCursor();
    return $at !== false && ($realNow - (int)$at) <= XERIC_CHATTER_WARM;
}

/**
 * Is this room due, and for how many lines?
 *
 * The watermark is REAL time, like the chat cooldown and for the same reason:
 * the wait is a person's experience of a room being quiet, and a paused world
 * would otherwise leave it quiet forever. `force` is for a page that has just
 * been walked into and wants the room to say something now.
 *
 * @return array{due:bool,lines:int,pace:string,in:int}
 */
function xeric_chatter_due(array $t, PDO $db, string $place, int $realNow, array $opts = []): array
{
    $conv = xeric_chatter_conversation($t, $db, $place);
    $pace = xeric_chatter_warm($db, $conv, $realNow) ? 'eventful' : xeric_chatter_pace($t);
    [$every, $lo, $hi] = xeric_chatter_rate($pace);

    $last = (int)(xeric_world_state_get($db, 'chatter:' . $place, '0') ?? 0);
    $since = $realNow - $last;
    $due = !empty($opts['force']) || $last === 0 || $since >= $every;

    return [
        'due'   => $due,
        'lines' => min(XERIC_CHATTER_CAP, random_int($lo, $hi)),
        'pace'  => $pace,
        'in'    => $due ? 0 : max(0, $every - $since),
    ];
}

/**
 * May a room be a channel at all?
 *
 * NOT SOMEBODY'S HOUSE. The first live beat filled six home conversations —
 * Bragg cottage, Drubb house, the room over the blacksmith — because at 21:39
 * that is genuinely where everybody was, and two people standing together was
 * the only test. It read correctly and was completely wrong: a couple in their
 * own kitchen is not a channel you walk into and scroll back through. What this
 * feature is for is the bar, the post office, the diner — the places where a
 * town overhears itself.
 *
 * A home is also where the walls matter most and where nobody consented to an
 * audience. The private conversation between two people who live together is a
 * thing the engine already has: it is a duet, and it is theirs.
 */
function xeric_chatter_public(array $t, string $place): bool
{
    foreach ((array)($t['places'] ?? []) as $p) {
        if ((string)($p['key'] ?? '') !== $place) continue;
        return mb_strtolower(trim((string)($p['kind'] ?? ''))) !== 'home';
    }
    return false;                                    // a place not on the map is not a room
}

/** Is this even somewhere? A key nobody's map has is not a room to anybody. */
function xeric_chatter_is_place(array $t, string $place): bool
{
    if ($place === '') return false;
    foreach ((array)($t['places'] ?? []) as $p) {
        if ((string)($p['key'] ?? '') === $place) return true;
    }
    return false;
}

/**
 * May this person open the room at all?
 *
 * A PUBLIC ROOM, ALWAYS — standing in it or not. That is the whole point of a
 * channel: you were at home asleep, the bar was talking, and you scroll back
 * through it in the morning like anybody who missed a group chat.
 *
 * A HOME, ONLY FROM INSIDE IT. The beat still never chatters in a house, for
 * every reason in xeric_chatter_public — but at 23:14 the entire cast is at
 * home, and that rule made the room you were personally standing in refuse to
 * open. "I am here, two people are here with me, and I cannot say a word to
 * either of them" is not privacy, it is a dead end. Being in the room is the
 * license; you still cannot read somebody's kitchen from across town.
 */
function xeric_chatter_readable(array $t, string $place, ?string $youAt): bool
{
    if (!xeric_chatter_is_place($t, $place)) return false;
    return xeric_chatter_public($t, $place) || ($youAt !== null && $youAt === $place);
}

/** Everyone alive and standing in this place right now. */
function xeric_chatter_present(array $t, PDO $db, string $place, array $now): array
{
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));
    $here = [];
    foreach ($presence as $handle => $row) {
        if ((string)($row['where'] ?? '') === $place) $here[] = (string)$handle;
    }
    return $here;
}

/**
 * The scrollback, as the floor draw and the assemblies both want it.
 *
 * One read, two shapes: `lines` for room.php's draw (which wants handle+text),
 * and the rows themselves for the turn builder. The player's own lines are in
 * here as a speaker like anybody else — that is the point of the whole file.
 *
 * @return array<int,array{handle:?string,name:string,text:string,role:string}>
 */
function xeric_chatter_scrollback(array $t, PDO $db, int $conversationId, int $limit): array
{
    $rows = xeric_messages_recent($db, $conversationId, $limit);
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'you';
    $out = [];
    foreach ($rows as $r) {
        $role = (string)$r['role'];
        if ($role === 'user') {
            $out[] = ['handle' => null, 'name' => $userName, 'text' => (string)$r['content'], 'role' => 'user'];
        } elseif ($role === 'character') {
            $h = (string)($r['handle'] ?? '');
            if ($h === '') continue;
            $out[] = ['handle' => $h, 'name' => xeric_world_name($t, $h), 'text' => (string)$r['content'],
                      'role' => 'character'];
        } else {
            $out[] = ['handle' => null, 'name' => '', 'text' => (string)$r['content'], 'role' => 'narrator'];
        }
    }
    return $out;
}

/**
 * One head's assembly for idle talk.
 *
 * room.php's system block is right about almost everything and wrong about one
 * sentence, so this composes rather than forks: the base assembly and the walls
 * are prompt.php's, unchanged, and what follows says where they are standing
 * instead of what scene they are in.
 */
function xeric_chatter_system(array $t, PDO $db, string $me, array $others, string $place,
                              string $eff, int $epoch, array $walls, int $memoryLimit,
                              bool $userHere): string
{
    $base     = xeric_prompt_system($t, $db, $me, $eff, $memoryLimit, $epoch, $walls);
    $company  = $others === []
        ? 'nobody in particular'
        : xeric_join_list(array_map(fn($h) => xeric_world_name($t, $h), $others));
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'them';
    $where    = xeric_world_place_name($t, $place);

    $out = [rtrim($base), ''];
    $out[] = 'WHERE YOU ARE';
    $out[] = '- You are at ' . $where . '. So is ' . $company . '. You are all just here —'
           . ' nobody called a meeting and nothing is happening.';
    $out[] = '- Lines from the others arrive labeled, like "Ruth: …". That is them talking out loud.';

    // THE ONE SENTENCE THAT CHANGES. A scene nobody saved could honestly say the
    // player was absent and would never hear it. A channel with scrollback
    // cannot: the room is a place in their town, the person may be standing in
    // it, and what is said in it stays said. Which is what makes a thing said
    // behind somebody's back cost anything at all.
    $out[] = $userHere
        ? '- ' . $userName . ' is here too, in the room with you. Lines from ' . $userName
          . ' arrive labeled the same way. Talk to them like somebody standing there.'
        : '- ' . $userName . ' is not in the room at the moment, but this is a room in your town'
          . ' and not a private one. What gets said here is said. People repeat things.';

    $out[] = '';
    $out[] = 'HOW PEOPLE TALK WHEN NOTHING IS HAPPENING';
    // FREE WILL, EXCEPT NO STORY LINE. Everything here is permission and none of
    // it is an assignment. A list of topics read as a checklist produces four
    // people dutifully covering the weather, which is worse than saying nothing.
    $out[] = '- This is idle talk. Jokes, complaining, the weather, what somebody heard,'
           . ' something that happened last week, a thing you remember about one of them.'
           . ' Or nothing much at all.';
    $out[] = '- It does not have to go anywhere. No news, no announcement, no decision, no'
           . ' confrontation unless one genuinely lands in front of you. Most talk is filler'
           . ' and filler is what a room sounds like.';
    $out[] = '- ONE short turn. A sentence, maybe two. Not a paragraph and not a speech.';
    $out[] = '- You can answer the last thing, ignore it, change the subject, or say something'
           . ' to nobody in particular. A room is not a queue.';
    $out[] = '- Speak only as yourself. Never write anyone else\'s line, never answer for them,'
           . ' never put a name and a colon in front of anything.';
    $out[] = '- Only the words you say out loud. No stage directions, no asterisks, no'
           . ' narrating your hands or the room.';
    $out[] = '- Everything above this section is still true: who you are, what you hold back,'
           . ' what you remember, and everything you were told never to do.';
    return implode("\n", $out) . "\n";
}

/**
 * What this head has to talk ABOUT, offered and never assigned.
 *
 * Their own memories and the hours they were present for — the same material
 * the scene builds from, minus the scene's instruction to use it. The weather
 * comes through prompt.php's volatile block below rather than from here.
 *
 * @return array<int,string>
 */
function xeric_chatter_material(array $t, PDO $db, string $me, array $others): array
{
    $mem = xeric_memories_for($db, $me, 8);
    $out = [];
    $names = [];
    foreach ($others as $h) $names[$h] = xeric_world_name($t, $h);

    $mine = [];
    foreach (array_reverse($mem) as $m) {
        $text = trim((string)($m['text'] ?? ''));
        if ($text !== '') $mine[] = $text;
    }
    if ($mine !== []) {
        $out[] = 'Things you remember, if any of them come to mind: ' . implode(' | ', array_slice($mine, 0, 5));
    }
    return $out;
}

/**
 * Fill one room with idle talk and save every line.
 *
 * Read-only until each line lands: unlike the scene, there is no close and no
 * transaction to hold, because a channel that dies halfway through has still
 * genuinely had three things said in it. Saving as it goes is what makes a
 * heartbeat that gets killed mid-fire harmless.
 *
 * @return array{said:int,place:string,pace:string,lines:array<int,array{handle:string,name:string,text:string}>}
 */
function xeric_chatter_run(array $t, PDO $db, string $place, array $endpoint, array $opts = []): array
{
    $realNow = (int)($opts['real_now'] ?? time());
    $now     = $opts['now'] ?? xeric_clock_now($db, $t, $realNow);
    $epoch   = (int)($now['epoch'] ?? 0);
    // `rating` is a RECEIPT, not a setting: the ceiling this pass actually ran
    // at, after the room was folded down to the lowest person standing in it.
    // The sweep and the scene both keep one, for the same reason — a ceiling
    // nobody can inspect after the fact is a mood, not a wall.
    $out     = ['said' => 0, 'place' => $place, 'pace' => 'steady', 'rating' => '', 'lines' => []];

    // The beat only ever runs public rooms — heart.php filters the rota before
    // it gets here, and this is the belt to that brace. `user_here` is the one
    // thing that opens a house: somebody is standing in it and has just spoken.
    $userHere = !empty($opts['user_here']);
    if (!xeric_chatter_readable($t, $place, $userHere ? $place : null)) return $out;

    // TWO TO GOSSIP, ONE TO ANSWER. Idle chatter needs a pair — one person
    // alone in a room is not a conversation, it is a soliloquy. But a person
    // standing there talking to the one shopkeeper on shift is, and the pair
    // rule was refusing that with "there is nobody in there to hear it" while
    // she stood at the counter.
    $present = xeric_chatter_present($t, $db, $place, $now);
    if (count($present) < ($userHere ? 1 : XERIC_CHATTER_MIN)) return $out;

    $due = xeric_chatter_due($t, $db, $place, $realNow, $opts);
    $out['pace'] = $due['pace'];
    if (!$due['due']) return $out;

    $conv = xeric_chatter_conversation($t, $db, $place);

    // THE CEILING IS THE LOWEST PERSON STANDING IN IT — the sweep's sentence,
    // the room's fold, copied verbatim. Folded ONCE, before a single call, and
    // never re-raised: somebody walking out does not make the hour older.
    $eff = (string)($opts['effective_rating'] ?? xeric_world_rating($t, $opts['model_rating'] ?? null));
    foreach ($present as $h) {
        $who = xeric_viewer($t, ['handle' => $h]);
        if ($who['is_minor']) $eff = xeric_viewer_rating($eff, $who);
    }
    $out['rating'] = $eff;
    $protected = xeric_sweep_protected($t);

    $names = [];
    foreach ($present as $h) $names[$h] = xeric_world_name($t, $h);

    $back = xeric_chatter_scrollback($t, $db, $conv, XERIC_CHATTER_KEEP);
    $lastHandle = null;
    $lastText   = '';
    for ($i = count($back) - 1; $i >= 0; $i--) {
        if ($back[$i]['text'] === '') continue;
        $lastHandle = $back[$i]['handle'];
        $lastText   = $back[$i]['text'];
        break;
    }

    $deaths = xeric_dead_handles($db);
    // The caller's budget caps the pace's own answer, never raises it: the heart
    // is saying how many calls it can afford across ALL its rooms this tick, and
    // a room that wanted two lines does not want eight because the town is quiet.
    $want   = (int)$due['lines'];
    if (isset($opts['max_lines'])) $want = max(0, min($want, (int)$opts['max_lines']));
    $said   = 0;

    for ($n = 0; $n < $want; $n++) {
        // The floor draw, borrowed whole from the scene. Its first rule is that
        // being named outranks everything, which is why "Hester, is that true?"
        // routes to Hester with nothing written here to make it.
        $reach = [];
        foreach ($present as $h) $reach[$h] = xeric_learn_reach($db, $h);
        $memrows = [];
        foreach ($present as $h) $memrows[$h] = xeric_memories_for($db, $h, 12);

        $draw = xeric_room_draw($present, $lastHandle, $lastText, $names, $reach, $memrows, []);
        $me = (string)($draw['speaker'] ?? '');
        if ($me === '' || !in_array($me, $present, true)) break;

        $others = array_values(array_diff($present, [$me]));
        $walls  = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $me]));

        $system = xeric_chatter_system($t, $db, $me, $others, $place, $eff, $epoch, $walls,
                                       XERIC_CHATTER_KEEP, $userHere);
        $coach  = xeric_chatter_material($t, $db, $me, $others);
        $coach[] = 'Say one thing. Short. It does not have to be about anything.';

        // $db IS FOR THE WEATHER — it carries the narrator's told sky
        // (xeric_weather_told), the one thing allowed to beat the derivation.
        // The duet and the scene both once read it out of thin air; all three
        // builders now hand it down.
        $volatile = xeric_prompt_now_block($t, $me, $now, implode("\n", $coach), $walls, null,
                                           $deaths, 0, $db);

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

        try {
            $raw = xeric_chat_say($endpoint, $messages, ['temperature' => 0.95, 'max_tokens' => 160]);
        } catch (Throwable $e) {
            // A quiet room is not an error and never fails a heartbeat — but a
            // room that is quiet because every call is throwing looks exactly
            // like a room that is quiet, which is how this would rot unnoticed.
            if (!empty($opts['on_why'])) ($opts['on_why'])('call', $me, $e->getMessage());
            break;
        }

        $otherNames = array_map(fn($h) => $names[$h], $others);
        if ($userHere) $otherNames[] = trim((string)($t['user']['name'] ?? '')) ?: 'you';
        $text = xeric_room_clean((string)$raw, $names[$me], $otherNames, 400);
        if ($text === '') {
            if (!empty($opts['on_why'])) ($opts['on_why'])('empty', $me, mb_substr((string)$raw, 0, 200));
            continue;
        }

        // THE AGE FLOOR, the line with the one it answers, over everyone
        // standing in the room — the same belt the scene and the floor wear.
        // An idle room with a child in it is still a room with a child in it,
        // and this was the one line-writing path of the three without it.
        if (xeric_age_floor($t, $present, [$lastText, $text]) !== null) {
            if (!empty($opts['on_why'])) ($opts['on_why'])('age', $me, mb_substr($text, 0, 120));
            continue;
        }

        // THE WALLS, PER LINE, OVER WHO IS PRESENT. A line that puts a protected
        // listener next to the thing they must not know is dropped — and unlike
        // the scene, which could re-draw within its beat budget, a dropped line
        // here is simply a beat of silence. Silence is a legal thing for a room
        // to do; a leak is not.
        $leaks = false;
        foreach ($protected as $ph => $secret) {
            if (in_array($ph, $present, true) && xeric_sweep_touches($text, $secret)) { $leaks = true; break; }
        }
        if ($leaks) {
            if (!empty($opts['on_why'])) ($opts['on_why'])('wall', $me, mb_substr($text, 0, 120));
            continue;
        }

        xeric_message_append($db, $conv, 'character', $me, $text, $epoch, $realNow);
        $out['lines'][] = ['handle' => $me, 'name' => $names[$me], 'text' => $text];
        $said++;

        $lastHandle = $me;
        $lastText   = $text;
        $back[] = ['handle' => $me, 'name' => $names[$me], 'text' => $text, 'role' => 'character'];
        if (count($back) > XERIC_CHATTER_KEEP) array_shift($back);

        if (!empty($opts['on_line'])) ($opts['on_line'])($me, $names[$me], $text);
    }

    // Stamped whether or not anything was said, so a room where every line was
    // refused does not get re-asked sixty seconds later and refused again.
    xeric_world_state_set($db, 'chatter:' . $place, (string)$realNow, $realNow);
    if ($said > 0 && !$userHere) xeric_conversation_touch($db, $conv, 1, $realNow);

    $out['said'] = $said;
    return $out;
}
