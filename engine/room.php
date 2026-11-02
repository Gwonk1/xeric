<?php
/**
 * Xeric — the room. Three to five characters talk while you watch.
 *
 * This is the duet grown to the size the ROADMAP always meant, and its first
 * law is inherited verbatim because growing is exactly when it breaks: THE
 * ONE-CALL SHORTCUT IS FATAL AND WILL LOOK RIGHT. It looks even better at
 * five than at two — one call, five voices, perfect overlap, a fifth of the
 * price — and it is even more wrong, because five assemblies collapsing into
 * one prompt is five sets of walls failing at once. So: every spoken line is
 * its OWN model call from that speaker's OWN assembly, and the only thing
 * that crosses between heads is what was actually SAID, carried as labeled
 * conversation turns. N people, B beats, B calls, plus one cheap diary call
 * per participant at the close. Still not an optimisation to revisit.
 *
 * What three-and-up adds that two never had is the floor itself: with two
 * people, whoever is not talking is answering next, and the duet rightly
 * hard-codes that. With three, EVERY beat asks WHO ANSWERS, and the honest
 * answer is also who does not. The three design questions of this file:
 *
 * ── WHO ANSWERS ───────────────────────────────────────────────────────────
 *
 * A weighted draw per beat, among everyone still in the room except whoever
 * just spoke — nobody answers themselves. Two tiers, then a thumb:
 *
 *   1. ADDRESSED BY NAME OUTRANKS. If the last spoken line names somebody
 *      present, the draw happens among the named and nobody else — this is
 *      conversation analysis' oldest rule (the current speaker selects the
 *      next one, and a name in the sentence is the selection), and it is a
 *      TIER rather than a bonus because a question with your name on it is
 *      not a suggestion. The test is xeric_age_mentions(), the engine's one
 *      "is this text about that person" scan, which deliberately makes being
 *      talked ABOUT as strong as being talked TO — true in real rooms too:
 *      say somebody's name and they answer whether or not you asked them to.
 *      The escape from a direct address lives in the PROSE (the addressed
 *      person may deflect, refuse, change the subject); the floor itself
 *      follows the name.
 *   2. MATERIAL HEAT. Among the un-addressed (or among several addressed),
 *      the person with the most unsettled material about the last speaker is
 *      likeliest next: their own stored memories that mention them, counted
 *      as rows, never paraphrased — plus one notch for an open expectation
 *      naming the last speaker. Expectations between townspeople carry `of`
 *      (the cast handle who promised), formed by constructs.php's cast-to-cast
 *      pass since milestone three, so this notch is live; the seam was tested
 *      before it lit up so the day it did was not the day it broke.
 *   3. THE THUMB. Everything is multiplied by xeric_learn_reach(), exactly
 *      as the duet's opener draw, and resolved by xeric_learn_order() — a
 *      weighted SHUFFLE, never a ranking, because the hottest person taking
 *      every beat is how a room becomes one character and some furniture.
 *
 * Every beat's candidate weights are written into the why-trail. The draw is
 * dice, but the dice are inspectable: "why did she answer?" has a numeric
 * answer with names on it, which is the other half of this design.
 *
 * ── WHO STAYS SILENT ──────────────────────────────────────────────────────
 *
 * Silence is not an error state; with three or more it is a seat at the
 * table. One speaker per beat means everyone else held their tongue, and the
 * engine counts it: a per-person streak of beats present-and-silent. When a
 * streak reaches XERIC_ROOM_QUIET_BEATS, the NEXT speaker's coaching gains
 * one licensed line — "X has not said a word in a while. You may notice
 * that, or let it be." — once per stretch of silence, and never anything
 * stronger. The quiet person is never forced to speak, never handed a turn
 * they did not draw, and never spoken FOR: being noticed as quiet IS their
 * contribution to the beat, delivered through somebody else's mouth, which
 * is the only place an engine can honestly put it. The streak deliberately
 * does NOT raise their draw weight — a world that rewards silence with the
 * floor teaches its quietest people to perform silence.
 *
 * ── WHO LEAVES ────────────────────────────────────────────────────────────
 *
 * A scene takes time, and schedules do not pause for it. Each beat advances
 * a scene clock by XERIC_ROOM_BEAT_SECONDS, and every XERIC_ROOM_RECHECK
 * beats presence is re-read — through xeric_world_who_is_where(), the ONE
 * reader of the week, at the advanced clock — and anyone the schedule has
 * moved out of this place departs: an engine-written stage direction lands
 * in the transcript ("Ruth Amberg gets up and goes."), their assembly stops
 * being called, they leave the draw, and their diary at the close reads ONLY
 * the lines they were in the room to hear. The stage direction is
 * OBSERVABLES ONLY — everyone saw them leave; where they went is schedule
 * knowledge a wall may be hiding, so it goes to the why-trail (the
 * inspector's, not a character's) and never into the room. The prompt clock
 * steps only when the schedule is re-read — the volatile block reads the
 * same moment the scheduling last read, so "Also there:" and a departure the
 * transcript just narrated can never contradict each other. Nobody
 * arrives mid-scene: who may join a room is the Director's question
 * (ROADMAP), and a room that seated its own newcomers would be a chat
 * window growing a guest list. If departures leave fewer than two, the
 * scene ends early — a room of one is somebody standing in a room.
 *
 * ── THE CEILING, THE FLOOR, THE WALLS ─────────────────────────────────────
 *
 * The ceiling is the sweep's sentence made literal: the room's ceiling is
 * the lowest one standing in it. One minor among five clamps ALL five sets
 * of calls to the weakest rating, folded once at admission and never
 * re-raised — not even after the child leaves, because the hour is one
 * record and the child was at it. The age floor reads every spoken line
 * with the line it answers, against EVERYONE admitted, present or departed,
 * at the sweep's roomful scope. The wall post-check is per-line over who is
 * PRESENT: a line that puts a protected listener next to the thing they
 * must not know is refused — but the same words spoken after that listener
 * walked out are just words in a room they are not in, which is precisely
 * how people actually handle a secret and a doorway; the departed one's
 * diary slice keeps the leak out of their head at the close too.
 *
 * ── READ-ONLY UNTIL THE CLOSE ─────────────────────────────────────────────
 *
 * Every model call runs against a database nothing here has written to. The
 * close writes it all in ONE transaction: every participant's own diary
 * (one extraction call each, echo-screened against every earlier diary so N
 * people cannot file the same sentence N times — divergent memories are the
 * point), ONE commons event saying they talked and where, and the why-trail
 * under the inspector's own key, kind 'room': per beat, who spoke and why
 * (with the weights), who held silence, who was noticed for it, who left
 * and where they went. A room that dies at beat four leaves the world
 * exactly as it found it. No messages rows: the transcript is returned and
 * streamed ($opts['on_line']); the record of the hour is the event.
 *
 * Zero dependencies beyond the engine. PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/chat.php';       // clean pieces, floor, dedupe, the model seam
require_once __DIR__ . '/sweeps.php';     // the protected-secret test, the ceiling idiom
require_once __DIR__ . '/learn.php';      // the weighted shuffle, and reach
require_once __DIR__ . '/death.php';      // the dead do not talk, even in company
require_once __DIR__ . '/constructs.php'; // expectations — the cast-to-cast seam, live since M3
require_once __DIR__ . '/panel.php';      // and, in a discussion room, the open record
require_once __DIR__ . '/clock.php';      // the live room stops when the world does
require_once __DIR__ . '/travel.php';     // where you are standing, and the doorway's beat

/** A room, bounded: two is a duet (use xeric_duet), six is a crowd (the sweep writes crowds). */
const XERIC_ROOM_MIN = 3;
const XERIC_ROOM_MAX = 5;

/** Spoken lines per person, by default. Beats total = this × people, capped. */
const XERIC_ROOM_BEATS_EACH = 3;
const XERIC_ROOM_BEATS_CAP  = 24;

/** A spoken line, not a speech. The duet's numbers: same register, same room. */
const XERIC_ROOM_MAX_CHARS  = 500;
const XERIC_ROOM_MAX_TOKENS = 260;

/** How many of a speaker's memories of the others seed their side of the scene. */
const XERIC_ROOM_MATERIAL = 3;

/** Heat per unsettled thing: weight = reach × (1 + HEAT × hot things about the last speaker). */
const XERIC_ROOM_HEAT = 0.75;

/** Beats of held silence before "X has been quiet" is licensed into the next prompt. */
const XERIC_ROOM_QUIET_BEATS = 3;

/** Scene seconds per beat, and how often the schedule is re-read against them. */
const XERIC_ROOM_BEAT_SECONDS = 120;
const XERIC_ROOM_RECHECK      = 3;

// -- the room with you in it (the live session, at the end of this file) ----

/** The handle the player's lines wear. Never a cast handle; the watch's own. */
const XERIC_ROOM_YOU = '__you';

/**
 * A PLAYER WITH NO NAME. The template may leave `user.name` empty, and the
 * session used to seat them as the literal word 'you' — which the mention
 * test then found in nearly every line ("what do you think?" handed the floor
 * back), in every memory (heat), and in the prompts ("you are not texting
 * you"). So the two jobs a name does are split: mention tests read the
 * SENTINEL, which holds no letter and so can never be found in a line
 * (xeric_age_mentions skips a needle without one), and prose reads the
 * VISITOR — what a bystander would call somebody they had not been introduced
 * to. The template's own pronouns still carry the base prompt.
 */
const XERIC_ROOM_NAMELESS = '···';
const XERIC_ROOM_VISITOR  = 'the visitor';

/** One live session per world copy — the player has one body. */
const XERIC_ROOM_LIVE = 'room:live';

/** A session that has ended and is waiting for its close: room:seal:<conversation id>. */
const XERIC_ROOM_SEAL = 'room:seal:';

/** Cast at the table, besides you: two is the least that is a room, five the most. */
const XERIC_ROOM_LIVE_MIN = 2;

/** Nothing said for this many WORLD seconds, and the session is over. */
const XERIC_ROOM_QUIET_SECONDS = 1200;

/** Answering beats one player line buys, and the ceiling a line naming several may raise it to. */
const XERIC_ROOM_ANSWERS     = 3;
const XERIC_ROOM_ANSWERS_CAP = 4;

/** Talking without you: beats in a row, and beats in a session. */
const XERIC_ROOM_CARRY_RUN     = 2;
const XERIC_ROOM_CARRY_SESSION = 6;

/** The carry roll: p = FLOOR_P × 0.5^k after the k-th answering beat (k from 0). */
const XERIC_ROOM_FLOOR_P = 0.55;

/** Seconds a worker's lease holds the floor without renewing. */
const XERIC_ROOM_LEASE = 120;

// ---------------------------------------------------------------------------
// The room
// ---------------------------------------------------------------------------

/**
 * Three to five characters talk, one model call per spoken line, one close.
 *
 * @param array $t       the template, story-composed if the caller carries overlays
 * @param array $handles 3..5 character handles, no duplicates
 * @param array $now     from xeric_world_now() — injected, never fetched here
 * @param array $opts    beats, say_first (handle), seed, temperature, timeout,
 *                       max_tokens, max_chars, memory_limit, effective_rating,
 *                       model_rating, on_line (fn(handle,name,text,kind) as lines
 *                       land — kind is 'line' or 'exit'), endpoint_for
 *                       (fn(handle): array — the speaker's pinned machine, the
 *                       same resolver a sweep takes; absent, every line goes to
 *                       $endpoint as it always did; the close stays on $endpoint)
 * @return array{lines:array,event_id:int,title:string,prose:string,place:?string,
 *               place_name:string,memories:array<string,array>,spoke_first:string,
 *               last_word:string,turns:int,beats:int,departures:array,
 *               never_spoke:array,usage:array,notes:array}
 * @throws RuntimeException on a bad roster, people not in a room together, a dead
 *         model, a line the floor or a wall refuses, or a close that cannot be
 *         stored. In every case except the last, NOTHING has been written; the
 *         last rolls back whole.
 */
function xeric_room(array $t, PDO $db, array $handles, array $now, array $endpoint, array $opts = []): array
{
    if (isset($opts['seed'])) mt_srand((int)$opts['seed']);   // the sweep's own idiom

    // Absence, not sign: a pre-1970 world's epoch is negative and real.
    if (!isset($now['epoch'])) throw new RuntimeException('room: a room needs a moment, pass xeric_world_now()');
    $epoch = (int)$now['epoch'];

    // -- the roster, resolved loudly (chat.php's posture) ------------------
    $handles = array_values(array_map('strval', $handles));
    if (count($handles) !== count(array_unique($handles))) {
        throw new RuntimeException('room: the same person cannot stand in a room twice');
    }
    if (count($handles) < XERIC_ROOM_MIN) {
        throw new RuntimeException('room: ' . count($handles) . ' is not a room — two people are a duet (xeric_duet), one is a monologue');
    }
    if (count($handles) > XERIC_ROOM_MAX) {
        throw new RuntimeException('room: ' . count($handles) . ' people is a crowd, not a conversation — a room holds ' . XERIC_ROOM_MAX);
    }
    $world = (string)($t['meta']['name'] ?? 'this xeric');
    $names = [];
    foreach ($handles as $h) {
        if (xeric_world_character($t, $h) === null) {
            if (xeric_world_fixture($t, $h) !== null) {
                throw new RuntimeException("room: '$h' is scenery, a fixture cannot hold a corner of a conversation");
            }
            throw new RuntimeException("room: nobody in $world answers to '$h'");
        }
        $names[$h] = xeric_world_name($t, $h);
        if (xeric_is_dead($db, $h)) throw new RuntimeException(xeric_death_refusal('room', $names[$h]));
        $c = xeric_world_character($t, $h);
        if (!empty($c['out'])) throw new RuntimeException('room: refused, ' . $names[$h] . ' has not entered the story');
    }

    // -- are they all in one room? -----------------------------------------
    $room = xeric_room_together($t, $db, $handles, $now);   // throws the geographic refusal

    // -- the ceiling: the lowest one standing in the room -------------------
    // The sweep's fold exactly, over N: start from world∧model, then let every
    // viewer standing in the room pull it down. Folded ONCE, here — a departure
    // never re-raises it, because the hour is one record (header).
    $eff = (string)($opts['effective_rating'] ?? xeric_world_rating($t, $opts['model_rating'] ?? null));
    $minor = false;
    foreach ($handles as $h) {
        $who = xeric_viewer($t, ['handle' => $h]);
        if ($who['is_minor']) { $minor = true; $eff = xeric_viewer_rating($eff, $who); }
    }

    // -- beats, and who opens ----------------------------------------------
    // AN EXPLICIT INSTRUCTION IS NEVER STRETCHED (the duet's law): a pinned
    // count is exact. The unpinned default scales with the roster — three
    // lines a head reads as a scene at any size — and there is no parity
    // stretch here because there is no alternation: the last word falls where
    // the draw puts it, which for a room is the honest answer.
    $explicit = array_key_exists('beats', $opts);
    $beats    = $explicit
        ? max(1, min(XERIC_ROOM_BEATS_CAP, (int)$opts['beats']))
        : min(XERIC_ROOM_BEATS_CAP, XERIC_ROOM_BEATS_EACH * count($handles));

    $reach = [];
    foreach ($handles as $h) $reach[$h] = xeric_learn_reach($db, $h);

    // -- each head's fixed assembly, resolved once ---------------------------
    // One wall resolution, one system string, one scene tail per speaker for
    // the whole room: byte-stable across all their calls (prompt.php's cache
    // discipline), which is why the system block names the roster AS ADMITTED
    // and departures ride the transcript instead — a system prompt that
    // tracked the head-count would pay a full re-read per exit.
    $protected = xeric_sweep_protected($t);
    $recent    = xeric_events_recent($db, 12);
    $walls = $system = $tails = $material = $memrows = $expects = [];
    foreach ($handles as $me) {
        $others         = array_values(array_diff($handles, [$me]));
        $walls[$me]     = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $me]));
        $system[$me]    = xeric_room_system($t, $db, $me, $others, $eff, $epoch, $walls[$me],
                                            (int)($opts['memory_limit'] ?? 12));
        $memrows[$me]   = xeric_memories_for($db, $me, 40);
        $expects[$me]   = xeric_expects_for($db, $me);
        $material[$me]  = xeric_room_material($t, $me, $others, $names, $memrows[$me], $recent, $protected);
        $tails[$me]     = xeric_room_scene($t, $me, $others, $room, $walls[$me], $material[$me]);
    }

    // -- the exchange: one call per spoken line, nothing written -------------
    $lines   = [];
    $notes   = [];
    $usage   = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'calls' => 0, 'ms' => 0];
    $deaths  = xeric_deaths($db);
    $onLine  = isset($opts['on_line']) && is_callable($opts['on_line']) ? $opts['on_line'] : null;

    $present    = $handles;                       // who is still in the room
    $roomNow    = $now;                           // steps forward at each schedule re-read
    $departures = [];                             // handle => [beat, went]
    $heard      = [];                             // handle => line count at exit
    $streak     = array_fill_keys($handles, 0);   // beats present-and-silent
    $noticed    = array_fill_keys($handles, false);
    $spoke      = array_fill_keys($handles, 0);
    $last       = null;                           // last speaker's handle
    $lastText   = '';
    $trailBeats = [];
    $endedEarly = false;
    $first      = null;

    $sayFirst = (string)($opts['say_first'] ?? '');
    if (!in_array($sayFirst, $handles, true)) $sayFirst = '';   // the duet's posture: draw instead

    for ($i = 0; $i < $beats; $i++) {

        // THE SCHEDULE DOES NOT PAUSE. Re-read presence at the advanced scene
        // clock; whoever the week has moved out of this place departs, as a
        // stage direction everybody saw. In admission order, like every other
        // roster walk here, so two simultaneous exits land deterministically.
        if ($i > 0 && $i % XERIC_ROOM_RECHECK === 0) {
            $roomNow = xeric_world_now($t, $epoch + $i * XERIC_ROOM_BEAT_SECONDS);
            $map     = xeric_world_who_is_where($t, $roomNow, array_keys($deaths));
            foreach ($present as $h) {
                $where = $map[$h]['where'] ?? null;
                if ($where === $room['where']) continue;
                $present = array_values(array_diff($present, [$h]));
                $departures[$h] = ['beat' => $i, 'went' => $where !== null ? (string)$where : null];
                $heard[$h] = count($lines) + 1;      // they know they left; nothing after
                $exit = ['handle' => $h, 'name' => $names[$h],
                         'text' => $names[$h] . ' gets up and goes.', 'kind' => 'exit'];
                $lines[] = $exit;
                if ($onLine !== null) $onLine($h, $names[$h], $exit['text'], 'exit');
            }
            if (count($present) < 2) { $endedEarly = true; break; }
            if ($last !== null && !in_array($last, $present, true)) {
                // The last speaker walked out. Nobody is excluded from the
                // next draw — but their words remain the last thing said, and
                // a name in them still calls its answer (the addressed scan
                // below reads $lastText, not $last): a parting question hangs
                // in the air of a real room too.
                $last = null;
            }
        }

        // -- who answers --------------------------------------------------
        if ($i === 0 && $sayFirst !== '') {
            $speaker = $sayFirst;
            $draw    = ['how' => 'pinned', 'addressed' => [], 'weights' => []];
        } else {
            $draw    = xeric_room_draw($present, $last, $lastText, $names, $reach, $memrows, $expects);
            $speaker = $draw['speaker'];
        }
        if ($first === null) $first = $speaker;

        // -- who has been quiet, said once per stretch, through this mouth --
        $quiet = [];
        foreach ($present as $h) {
            if ($h !== $speaker && $streak[$h] >= XERIC_ROOM_QUIET_BEATS) $quiet[] = $h;
        }
        $notice = null;
        usort($quiet, fn($a, $b) => $streak[$b] <=> $streak[$a]);
        foreach ($quiet as $h) {
            if (!$noticed[$h]) { $notice = $h; $noticed[$h] = true; break; }
        }

        $messages = xeric_room_messages($t, $speaker, array_values(array_diff($handles, [$speaker])),
            $system[$speaker], $lines, $tails[$speaker], $roomNow, $walls[$speaker], $deaths, $names,
            $lines === [], $i === $beats - 1,
            $notice !== null ? $names[$notice] . ' has not said a word in a while. You may notice that, or let it be.' : '',
            $db);

        // THE SPEAKER'S OWN MACHINE, when the caller pinned one, and the
        // speaker's own llama.cpp slot on it (xeric_llm_slot): five system
        // prompts through one slot re-read every prefix every beat.
        $sep = $endpoint;
        if (isset($opts['endpoint_for']) && is_callable($opts['endpoint_for'])) {
            try { $sep = ($opts['endpoint_for'])($speaker); } catch (Throwable $e) { $sep = $endpoint; }
            if (!is_array($sep) || $sep === []) $sep = $endpoint;
        }
        $sopts = [
            'temperature' => (float)($opts['temperature'] ?? 0.85),
            'max_tokens'  => (int)($opts['max_tokens'] ?? XERIC_ROOM_MAX_TOKENS),
        ] + array_intersect_key($opts, ['timeout' => 1]);
        $slot = xeric_llm_slot($sep, $speaker);
        if ($slot !== null) $sopts['id_slot'] = $slot;

        $u  = [];
        $t0 = microtime(true);
        try {
            $raw = xeric_chat_say($sep, $messages, $sopts, $u);
        } catch (Throwable $e) {
            throw new RuntimeException('room: ' . $names[$speaker] . ' did not answer, ' . $e->getMessage(), 0, $e);
        }
        $usage['ms'] += (int)round((microtime(true) - $t0) * 1000);
        $usage['prompt_tokens']     += (int)($u['prompt_tokens'] ?? 0);
        $usage['completion_tokens'] += (int)($u['completion_tokens'] ?? 0);
        $usage['calls']++;

        $text = xeric_room_clean($raw, $names[$speaker],
            array_map(fn($h) => $names[$h], array_values(array_diff($handles, [$speaker]))),
            (int)($opts['max_chars'] ?? XERIC_ROOM_MAX_CHARS));
        if ($text === '') {
            throw new RuntimeException('room: ' . $names[$speaker] . ' said nothing usable ('
                . mb_substr(trim($raw), 0, 120) . ')');
        }

        // THE FLOOR, per line, read as a turn — the new line with the one it
        // answers — against EVERYONE admitted, present or departed: the hour
        // is one record, and the sweep's roomful scope is the scope.
        $refused = xeric_age_floor($t, $handles, [$lastText, $text]);
        if ($refused !== null) throw new RuntimeException(xeric_age_refusal('room', $refused));

        // THE WALL, after the model, over who is PRESENT: a spoken line the
        // protected party is standing next to IS the leak, whoever spoke it —
        // and the same words after they have left the room are not (header).
        foreach ($protected as $ph => $secret) {
            if (in_array($ph, $present, true) && xeric_sweep_touches($text, $secret)) {
                throw new RuntimeException('room: refused, the conversation put ' . $names[$ph]
                    . ' next to the thing they must not know');
            }
        }

        $lines[] = ['handle' => $speaker, 'name' => $names[$speaker], 'text' => $text, 'kind' => 'line'];
        if ($onLine !== null) $onLine($speaker, $names[$speaker], $text, 'line');

        $spoke[$speaker]++;
        foreach ($present as $h) {
            if ($h === $speaker) { $streak[$h] = 0; $noticed[$h] = false; }
            else                 { $streak[$h]++; }
        }
        $trailBeats[] = array_filter([
            'beat'      => $i,
            'speaker'   => $speaker,
            'how'       => $draw['how'],
            'addressed' => $draw['addressed'] !== [] ? $draw['addressed'] : null,
            'weights'   => $draw['weights'] !== [] ? $draw['weights'] : null,
            'quiet'     => $quiet !== [] ? array_values($quiet) : null,
            'noticed'   => $notice,
        ], fn($v) => $v !== null);
        $last     = $speaker;
        $lastText = $text;
    }

    if ($lines === [] || $first === null) {
        throw new RuntimeException('room: no beats were spoken, nothing to close');
    }

    $neverSpoke = array_values(array_filter($handles, fn($h) => $spoke[$h] === 0));
    $lastSpoken = null;
    foreach (array_reverse($lines) as $l) {
        if ($l['kind'] === 'line') { $lastSpoken = (string)$l['handle']; break; }
    }
    $title = xeric_room_title(array_map(fn($h) => $names[$h], $handles));
    $prose = xeric_room_prose(array_map(fn($h) => $names[$h], $handles), $room, $now,
                              array_map(fn($h) => $names[$h], array_keys($departures)));
    $turns = count(array_filter($lines, fn($l) => $l['kind'] === 'line'));

    // -- the close: every diary, one event, one trail, ONE transaction -------
    // xeric_room_land() is the close this loop always had, lifted out so the
    // room with the player in it (below) lands through the SAME code: diary
    // calls before the transaction (chat.php's discipline), each a note when
    // it fails, never the room; each diary reads ONLY the slice its owner was
    // in the room to hear — a departed head does not get the rest of the scene.
    $slices = [];
    foreach ($handles as $me) $slices[$me] = array_slice($lines, 0, $heard[$me] ?? count($lines));

    $land = xeric_room_land($t, $db, [
        'handles'   => $handles,
        'names'     => $names,
        'slices'    => $slices,
        'lines'     => $lines,
        'memrows'   => $memrows,
        'room'      => $room,
        'protected' => $protected,
        'endpoint'  => $endpoint,
        'opts'      => $opts,
        'epoch'     => $epoch,
        'now'       => $now,
        'title'     => $title,
        'prose'     => $prose,
        'source'    => 'room',
        'notes'     => $notes,
        // The trail, under the key the inspector already reads, kind decided
        // here and only here. It carries who spoke and why — WITH the weights,
        // per beat — who held silence and who was noticed for it, who left and
        // where they actually went (which the transcript deliberately does
        // not say). It never carries the transcript itself: what was said is
        // the participants', and the record of the hour is commons.
        'trail'     => fn(array $notes): array => [
            'kind'        => 'room',
            'why'         => xeric_room_why($names, $handles, $room, $material, $first, $lastSpoken,
                                            $neverSpoke, array_keys($departures)),
            'place'       => (string)($room['where'] ?? ''),
            'people'      => $handles,
            'spoke_first' => $first,
            'last_word'   => $lastSpoken,
            'turns'       => $turns,
            'beats'       => $trailBeats,
            'departures'  => (object)$departures,
            'never_spoke' => $neverSpoke,
            'ended_early' => $endedEarly,
            'minor_clamp' => $minor,
            'rating'      => $eff,
            'ms'          => $usage['ms'],
            'notes'       => $notes,
            'at'          => time(),
        ],
    ]);
    $eventId = (int)$land['event_id'];
    $kept    = $land['memories'];
    $notes   = $land['notes'];

    return [
        'lines'       => $lines,
        'event_id'    => $eventId,
        'title'       => $title,
        'prose'       => $prose,
        'place'       => $room['where'],
        'place_name'  => $room['place_name'],
        'memories'    => $kept,
        'spoke_first' => $first,
        'last_word'   => (string)$lastSpoken,
        'turns'       => $turns,
        'beats'       => $beats,
        'departures'  => $departures,
        'never_spoke' => $neverSpoke,
        'usage'       => $usage,
        'notes'       => $notes,
    ];
}

// ---------------------------------------------------------------------------
// Admission: one room, everybody in it, or no room
// ---------------------------------------------------------------------------

/**
 * All of them in ONE place per the one presence resolver, or a refusal that
 * says where each of them actually is — the duet's geography grammar at N.
 * The place everyone must share is wherever the FIRST handle stands, which is
 * not a privilege: if any two of them differ the refusal fires regardless of
 * order, and the sentence names every single stand.
 *
 * @return array{where:string,place_name:string,why:string,doing:array<string,?string>,at_home:bool}
 */
function xeric_room_together(array $t, PDO $db, array $handles, array $now): array
{
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));

    $stand = function (string $h) use ($t, $presence): string {
        $row = $presence[$h] ?? null;
        if ($row === null || $row['where'] === null) {
            return xeric_world_name($t, $h) . ' is not at any of this world\'s places right now';
        }
        return xeric_world_name($t, $h) . ' is at ' . xeric_world_place_name($t, (string)$row['where']);
    };

    $wheres = [];
    foreach ($handles as $h) $wheres[$h] = $presence[$h]['where'] ?? null;

    $shared = $wheres[$handles[0]];
    foreach ($wheres as $w) {
        if ($w === null || $w !== $shared) {
            $bits = array_map($stand, $handles);
            throw new RuntimeException('room: refused, ' . xeric_join_list($bits)
                . ', they are not all in a room together');
        }
    }

    $atHome = true;
    $doing  = [];
    foreach ($handles as $h) {
        $atHome     = $atHome && !empty($presence[$h]['at_home']);
        $doing[$h]  = $presence[$h]['doing'] ?? null;
    }

    if ($atHome) {
        $why = 'home for all of them';
    } else {
        $bits = [];
        foreach ($handles as $h) {
            $d = trim((string)($doing[$h] ?? ''));
            if ($d !== '') $bits[] = xeric_world_name($t, $h) . ': ' . $d;
        }
        $why = $bits !== [] ? 'all there on their own schedules — ' . implode('; ', $bits)
                            : 'all there on their own schedules';
    }

    return [
        'where'      => (string)$shared,
        'place_name' => xeric_world_place_name($t, (string)$shared),
        'why'        => $why,
        'doing'      => $doing,
        'at_home'    => $atHome,
    ];
}

// ---------------------------------------------------------------------------
// The draw: two tiers, a thumb, and the receipts
// ---------------------------------------------------------------------------

/**
 * Who speaks this beat: the addressed tier if the last line named anybody
 * present, otherwise everybody present except the last speaker; weighted by
 * reach × material heat; resolved by the weighted shuffle. Returns the pick
 * AND the weights, because a draw the inspector cannot audit is a mood.
 *
 * The heat term counts ROWS, never content: the candidate's stored memories
 * that mention the last speaker (the engine's one mention test), capped at
 * XERIC_ROOM_MATERIAL, plus one for an open expectation whose `of` names the
 * last speaker — cast-to-cast rows, which constructs.php forms since M3
 * (header). No model is asked what is unresolved; the material is rows, so
 * the trail's numbers are checkable against the database that produced them.
 *
 * @return array{speaker:string,how:string,addressed:array,weights:array<string,float>}
 */
function xeric_room_draw(array $present, ?string $last, string $lastText, array $names,
                         array $reach, array $memrows, array $expects): array
{
    $candidates = $last === null ? $present : array_values(array_diff($present, [$last]));

    // The scan reads the TEXT, not the speaker: when the last speaker has
    // departed ($last null, their line still in $lastText), a name in their
    // parting words keeps its claim on the next turn.
    $addressed = [];
    if ($lastText !== '') {
        foreach ($candidates as $h) {
            if (xeric_age_mentions($lastText, $h, $names[$h])) $addressed[] = $h;
        }
    }
    $tier = $addressed !== [] ? $addressed : $candidates;
    $how  = $addressed !== [] ? 'addressed' : 'draw';

    $weights = $heat = $reachOf = [];
    foreach ($tier as $h) {
        $hot = 0;
        if ($last !== null) {
            // The player's handle is a sigil, not a name: '__you' would scan as
            // the word "you" and every memory ever written would be heat.
            $mh = $last === XERIC_ROOM_YOU ? (string)$names[$last] : $last;
            foreach (array_reverse($memrows[$h] ?? []) as $m) {
                $text = trim((string)$m['text']);
                if ($text !== '' && xeric_age_mentions($text, $mh, $names[$last])) $hot++;
                if ($hot >= XERIC_ROOM_MATERIAL) break;
            }
            // WHO OWES WHOM. A cast row names its promiser in `of`; a promise
            // the PLAYER made carries no `of` at all (constructs.php: absent
            // means the user), so when the last line was the player's the
            // notch is lit by exactly the rows the room's guest is on the
            // hook for — the same seam the cast-to-cast rows light from
            // the other side.
            foreach ($expects[$h] ?? [] as $e) {
                $of = (string)($e['of'] ?? '');
                $owes = $last === XERIC_ROOM_YOU ? $of === '' : $of === $last;
                if (($e['state'] ?? '') === 'open' && $owes) { $hot++; break; }
            }
        }
        $heat[$h]    = $hot;
        $reachOf[$h] = (float)$reach[$h];
        $weights[$h] = round((float)$reach[$h] * (1.0 + XERIC_ROOM_HEAT * $hot), 3);
    }

    return [
        'speaker'   => (string)xeric_learn_order($tier, $weights)[0],
        'how'       => $how,
        'addressed' => $addressed,
        'weights'   => $weights,
        // The breakdown, for the inspector: weight = reach × (1 + HEAT × heat).
        'heat'      => $heat,
        'reach'     => $reachOf,
    ];
}

// ---------------------------------------------------------------------------
// Each head's assembly
// ---------------------------------------------------------------------------

/**
 * One speaker's system message for the whole room: their ENTIRE ordinary
 * assembly, then one block that reseats the conversation — the duet's block
 * grown a roster and a labeling rule.
 *
 * xeric_prompt_system() is reused whole, not imitated, for the duet's exact
 * reason: every wall behaviour funnels through it, and a hand-copied assembly
 * would be two gates that eventually disagree. The one new rule the size
 * forces: with several voices in the transcript, the others' lines arrive
 * LABELED ("Ruth Amberg: …"), and the model is told the labels are speech it
 * heard, not a format it should produce. The roster named here is the roster
 * AS ADMITTED and never changes mid-scene — this string is byte-stable per
 * (speaker, roster, rating, memory set), which is the cache discipline; who
 * has since left the room is transcript, not system.
 */
function xeric_room_system(array $t, PDO $db, string $me, array $others, string $eff,
                           int $epoch, array $walls, int $memoryLimit, ?string $player = null): string
{
    $base     = xeric_prompt_system($t, $db, $me, $eff, $memoryLimit, $epoch, $walls);
    $company  = xeric_join_list(array_map(fn($h) => xeric_world_name($t, $h), $others));
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'anyone';

    // The company is named even for a walled speaker: walls govern KNOWLEDGE,
    // and the people standing in front of you are eyesight (the duet's rule).
    // What the walls keep out of this prompt they keep out above, in the base.
    $out = [rtrim($base), ''];
    $out[] = 'THIS ONE CONVERSATION IS DIFFERENT';

    // THE PLAYER VARIANT. Same base, same rules, one difference that changes
    // every line of the block: the person this assembly was built to text is
    // standing at the table. Named once, as admitted, like the rest of the
    // company — the string stays byte-stable for the whole session.
    if ($player !== null && trim($player) !== '') {
        $p = trim($player);
        $out[] = '- Right now you are not texting ' . $p . '. You are with '
               . xeric_join_list(array_merge(array_map(fn($h) => xeric_world_name($t, $h), $others), [$p]))
               . ', all of you in one room, talking out loud, face to face.';
        $out[] = '- Lines from the others arrive labeled with the speaker\'s name, like "Ruth: …". '
               . $p . '\'s lines arrive the same way. That is them talking, to you or to each other.';
        $out[] = '- ' . xeric_pronoun_cap($p) . ' is standing right here and hears every word said in this room. Nobody is on the phone.';
        $out[] = '- Speak as yourself and only yourself. Never write anyone else\'s line, ' . $p
               . '\'s included, never answer for them, and never put their name and a colon in front of anything.';
        $out[] = '- Not every line is yours to answer. When ' . $p . ' or one of the others is talking to somebody'
               . ' else you can cut in, stay out of it, or let it pass — a room is not a queue.';
        $out[] = '- Talk the way people talk in a room: short turns, plain words. You can trail off,'
               . ' change the subject, or let something sit.';
        $out[] = '- Only the words you say out loud. No stage directions, no asterisks, no'
               . ' quotation marks, no narrating your hands or the room.';
        $out[] = '- Everything above this section is still true: who you are, what you hold back,'
               . ' what you remember, and everything you were told never to do.';
        return implode("\n", $out) . "\n";
    }

    $out[] = '- Right now you are not texting ' . $userName . '. You are with ' . $company
           . ', all of you in one room, talking out loud, face to face.';
    $out[] = '- Lines from the others arrive labeled with the speaker\'s name, like "Ruth: …".'
           . ' That is them talking, to you or to each other. None of it is ' . $userName . '.';
    $out[] = '- ' . $userName . ' is not here. Nothing said here is addressed to '
           . $userName . ' and nobody reads it back to ' . $userName . '.';
    $out[] = '- Speak as yourself and only yourself. Never write anyone else\'s line, never'
           . ' answer for them, and never put their name and a colon in front of anything.';
    $out[] = '- Not every line is yours to answer. When two of the others are talking to each'
           . ' other you can cut in, stay out of it, or let it pass — a room is not a queue.';
    $out[] = '- Talk the way people talk in a room: short turns, plain words. You can trail off,'
           . ' change the subject, or let something sit.';
    // The duet learned this against the stand-in on day one; a bigger cast
    // makes the roleplay-dataset pull worse, not better.
    $out[] = '- Only the words you say out loud. No stage directions, no asterisks, no'
           . ' quotation marks, no narrating your hands or the room.';
    $out[] = '- Everything above this section is still true: who you are, what you hold back,'
           . ' what you remember, and everything you were told never to do.';
    return implode("\n", $out) . "\n";
}

/**
 * What seeds one head's side of the scene: their own memories that mention
 * ANY of the others, and the last hour they shared with at least one of them.
 *
 * The duet's material at N: same mention test (the engine's one), same cap —
 * an itch, not a biography — same per-READER spine screen on the shared
 * event. "Shared" relaxes from "both attended" to "I attended, with at least
 * one of these people", because an hour that three of the five were at is
 * still the town's last word on this company; whose names it carries is
 * already decided by the event row itself.
 *
 * @return array{memories:array<int,string>,about:array<string,int>,event:?string}
 */
function xeric_room_material(array $t, string $me, array $others, array $names,
                             array $memrows, array $recent, array $protected): array
{
    $memories = [];
    $about    = [];
    foreach (array_reverse($memrows) as $m) {
        $text = trim((string)$m['text']);
        if ($text === '') continue;
        $hit = null;
        foreach ($others as $o) {
            if (xeric_age_mentions($text, $o, $names[$o])) { $hit = $o; break; }
        }
        if ($hit === null) continue;
        $memories[]   = $text;
        $about[$hit]  = ($about[$hit] ?? 0) + 1;
        if (count($memories) >= XERIC_ROOM_MATERIAL) break;
    }

    $shared = null;
    foreach ($recent as $e) {
        $who = (array)($e['participants'] ?? []);
        if (!in_array($me, $who, true)) continue;
        if (array_intersect($others, $who) === []) continue;
        if (!empty($e['on_spine']) && isset($protected[$me])) continue;
        $shared = trim((string)$e['title']);
        break;
    }

    return ['memories' => $memories, 'about' => $about, 'event' => $shared !== '' ? $shared : null];
}

/**
 * The static half of one head's volatile tail: the scene, and what they carry
 * into it. Constant for this speaker across the whole room.
 *
 * The place is named only when this speaker's own walls would let the room
 * line say it (the duet's rule, via the same two wall paths); the company is
 * always named — eyesight again. What the others are here DOING is schedule
 * text, so it rides only under a sayable room.
 */
function xeric_room_scene(array $t, string $me, array $others, array $room, array $walls, array $material): array
{
    $company = xeric_join_list(array_map(fn($h) => xeric_world_name($t, $h), $others));
    $key     = (string)$room['where'];
    $sayable = !xeric_hidden($walls, 'schedules') && !xeric_hidden($walls, 'places.' . $key);

    $lines = ['THE SCENE'];
    $lines[] = $sayable && $room['place_name'] !== ''
        ? 'You and ' . $company . ' are all at ' . $room['place_name'] . ' right now, in the same room.'
        : 'You and ' . $company . ' are all in the same room right now.';
    if ($sayable) {
        foreach ($others as $o) {
            $d = trim((string)($room['doing'][$o] ?? ''));
            if ($d !== '') $lines[] = xeric_world_name($t, $o) . ' is here because: ' . $d . '.';
        }
    }

    if ($material['memories'] !== []) {
        $lines[] = 'Still sitting with you, unspoken (yours alone — nobody here knows you are chewing on these):';
        foreach ($material['memories'] as $m) $lines[] = '- ' . xeric_sentence($m);
    }
    if (($material['event'] ?? null) !== null) {
        $lines[] = 'The last thing some of you were together at, as the town tells it: "'
                 . (string)$material['event'] . '".';
    }
    return $lines;
}

/**
 * One speaker's messages for one line: their system, the transcript from
 * their side of the table, and the volatile block at the bottom.
 *
 * The transcript mapping IS the wall, at N: the speaker's own spoken lines
 * come back as assistant turns, bare; everything else — the others' speech,
 * labeled by name, and any departure, as the narrator's own "(what happened)"
 * marker (xeric_prompt_turn's idiom, because a departure is a thing the world
 * did, not a thing anybody said) — arrives as user turns, with consecutive
 * foreign lines merged into one message so the shape stays conversational.
 * Nothing else of anybody crosses. The departure line carries no destination:
 * everyone saw them go; where they went is schedule knowledge (header).
 *
 * The volatile block is xeric_prompt_now_block() with the room's scene and
 * the beat's coaching as the tail — the clock the scheduling last read, so
 * its presence line and the transcript agree about who is still here — and
 * lastSpoke 0, because a room is one continuous scene (header). The player
 * is deliberately nowhere in it: the room is the world talking to itself.
 */
function xeric_room_messages(array $t, string $me, array $others, string $system, array $lines,
                             array $scene, array $now, array $walls, array $deaths, array $names,
                             bool $opening, bool $closing, string $notice, ?PDO $db = null,
                             ?string $playerWhere = null, ?string $player = null): array
{
    // $db IS FOR THE WEATHER, and until now it was read out of thin air: the
    // call below passed `$db` to xeric_prompt_now_block() and this function had
    // no such parameter, so PHP handed it null — the bug the duet had and fixed
    // (duet.php, xeric_duet_messages). The derived sky never needed it; what
    // `$db` carries is the NARRATOR'S HAND, xeric_weather_told(), the one thing
    // allowed to beat the derivation. Without it a narrator could put rain over
    // the town and three people standing in it would talk about hazy heat.
    // Optional, so the signature stays back-compatible; the room loop passes its own.
    $coach = $scene;
    if ($notice !== '') $coach[] = $notice;
    // THE PLAYER, WHEN THERE IS ONE. Their position goes to the now-block (its
    // "is here, in the room with you" line, wall-gated there), and the beat's
    // coaching answers whoever spoke last: the person who walked up to the
    // table is owed an answer the way a name in a line is.
    $lastIsYou = $player !== null && $lines !== []
        && (string)($lines[count($lines) - 1]['handle'] ?? '') === XERIC_ROOM_YOU;
    if ($lastIsYou) {
        $coach[] = xeric_pronoun_cap($player) . ' just said that, out loud, to the room. Answer ' . $player
                 . ' — or whoever those words were really for — in your own way. A line or three, out loud.';
    } elseif ($opening) {
        $coach[] = 'You speak first. Start in the middle of the moment, off the thing in front of you'
                 . ' or the thing you have been meaning to say to one of them. A line or three, out loud.';
    } elseif ($closing) {
        $coach[] = 'This is the last thing said before everybody gets back to it, so let it land —'
                 . ' do not wrap the conversation up neatly, do not say goodbye like a scene ending.';
    } else {
        $coach[] = 'Take your turn — answer if it was yours to answer, or take the room somewhere'
                 . ' else. A line or three, out loud. It is a conversation, not a speech.';
    }

    $volatile = xeric_prompt_now_block($t, $me, $now, implode("\n", $coach), $walls, $playerWhere, $deaths, 0, $db);

    $messages = [['role' => 'system', 'content' => $system]];
    $pending  = [];
    $flush = function () use (&$messages, &$pending): void {
        if ($pending !== []) {
            $messages[] = ['role' => 'user', 'content' => implode("\n", $pending)];
            $pending = [];
        }
    };
    foreach ($lines as $l) {
        if ($l['kind'] === 'line' && (string)$l['handle'] === $me) {
            $flush();
            $messages[] = ['role' => 'assistant', 'content' => (string)$l['text']];
        } elseif ($l['kind'] !== 'line') {
            // A departure, an arrival: something the world did, never said.
            $pending[] = '(what happened) ' . (string)$l['text'];
        } else {
            $pending[] = (string)$l['name'] . ': ' . (string)$l['text'];
        }
    }
    $flush();

    // prompt.php's seating for the clock: ride the last user message when
    // there is one, stand alone when the speaker opens.
    $lastIdx = count($messages) - 1;
    if ($messages[$lastIdx]['role'] === 'user') {
        $messages[$lastIdx]['content'] .= "\n\n" . $volatile;
    } else {
        $messages[] = ['role' => 'user', 'content' => $volatile];
    }
    return $messages;
}

/**
 * The duet's reply hygiene at N. xeric_chat_clean() takes one counterpart, so
 * this composes the same pieces in the same order rather than forking any of
 * them: fence off, then the theft cut for EACH other voice in the room —
 * sequential cuts keep everything before the EARLIEST theft, which is the
 * multi-party generalisation of "the character keeps whatever she said before
 * the theft" — then the speaker's own tag, stage wrap, quote wrap, tidy, trim.
 */
function xeric_room_clean(string $raw, string $meName, array $otherNames, int $maxChars): string
{
    $s = xeric_chat_strip_fence($raw);
    foreach ($otherNames as $name) $s = xeric_chat_cut_user($s, (string)$name);
    $s = xeric_chat_strip_name_tag($s, $meName);
    $s = xeric_chat_strip_stage($s);
    $s = xeric_chat_strip_quotes($s);
    $s = xeric_chat_tidy($s);
    return xeric_chat_trim_length($s, $maxChars);
}

// ---------------------------------------------------------------------------
// The close: N diaries
// ---------------------------------------------------------------------------

/**
 * One head's memory of the room: one cheap call through chat.php's extraction
 * seam, the CHANGE rule verbatim — the duet's extractor with the roster and
 * one structural difference: the transcript handed in is the SLICE this
 * person was in the room to hear, so a departed head cannot remember what was
 * said after the door. Everything that made the duet's close safe is kept:
 * the dedupe against what is carried, the age floor at roomful scope, the
 * protected-secret screen on the way into a protected head, and the echo
 * screen against every EARLIER diary at XERIC_SWEEP_ECHO — N people at one
 * hour must come away with N memories, not one memory N times.
 *
 * WRITES NOTHING. Returns what the close should store.
 */
function xeric_room_extract(array $t, string $me, array $others, array $names, array $memrows,
                            array $lines, array $endpoint, array $room, array $handles,
                            array $protected, array $priorKept, array $opts): array
{
    $name    = $names[$me];
    $company = xeric_join_list(array_map(fn($h) => $names[$h], $others));

    $known = array_map(fn($m) => (string)$m['text'], $memrows);

    $spoken = [];
    foreach ($lines as $l) {
        $spoken[] = $l['kind'] !== 'line'
            ? '(' . trim((string)$l['text']) . ')'
            : (string)$l['name'] . ': ' . trim((string)$l['text']);
    }

    $c   = xeric_world_character($t, $me);
    $who = $name . ($c && ($c['one_line'] ?? '') !== '' ? ', ' . (string)$c['one_line'] : '');

    $msgs = [
        ['role' => 'system', 'content' =>
            'You keep one person\'s memory for a story engine. You read a conversation they were part of '
            . 'and write down what THEY would still know about it in a week. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            $who . "\nThey were talking with {$company}, face to face"
            . ($room['place_name'] !== '' ? ' at ' . $room['place_name'] : '') . ".\n\n"
            . ($known ? "Already in their memory, do not write any of these again:\n- " . implode("\n- ", $known) . "\n\n" : '')
            . "The conversation, as much of it as {$name} was in the room for:\n" . implode("\n", $spoken) . "\n\n"
            . "Write 0-3 things {$name} would still know in a week.\n"
            . "- Third person, past tense, naming {$name} and whoever the memory is about.\n"
            . "- One sentence each, under 25 words, concrete.\n"
            . "- Prefer what CHANGED, what was admitted, promised, refused, learned or agreed, "
            . "over the bare fact that something was said.\n"
            . "- Only what {$name} saw or heard above. Their own half and what the others actually said. Invent nothing.\n"
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
        if (xeric_age_floor($t, $handles, [$text]) !== null) continue;
        // A protected head does not get to write the secret down for itself:
        // the spoken lines were screened while they stood there, but an
        // extractor can synthesise.
        if (isset($protected[$me]) && xeric_sweep_touches($text, (string)$protected[$me])) continue;
        // And not an earlier diary's sentence again: one hour, N heads.
        if ($across !== [] && xeric_chat_is_dupe($text, $across, XERIC_SWEEP_ECHO)) continue;
        $keep[] = $text;
        if (count($keep) >= 3) break;
    }
    return $keep;
}

// ---------------------------------------------------------------------------
// The record: commons by construction
// ---------------------------------------------------------------------------

/** The event title. Engine-written, deterministic, lower case, first names, like a sweep's. */
function xeric_room_title(array $displayNames): string
{
    $firstOf = fn(string $n): string => (string)(preg_split('/\s+/u', trim($n)) ?: [$n])[0];
    return mb_strtolower(xeric_join_list(array_map($firstOf, $displayNames)) . ' talked');
}

/**
 * The event prose: what anyone standing nearby could have seen, and not one
 * word more. Names only, never pronouns; nothing anybody SAID; a departure is
 * in it because a departure was visible from the street.
 */
function xeric_room_prose(array $displayNames, array $room, array $now, array $departedNames): string
{
    $when  = trim(xeric_world_day_name((int)($now['dow'] ?? 0)) . ' ' . (string)($now['phase'] ?? ''));
    $where = $room['place_name'] !== '' ? ' at ' . $room['place_name'] : '';
    $out   = xeric_join_list($displayNames) . ' talked' . $where
           . ($when !== '' ? ' on ' . $when : '') . '. ';
    if ($departedNames !== []) {
        $out .= xeric_join_list($departedNames) . ' left before the others. ';
    }
    return $out . 'Anyone nearby could have seen them at it; what was said stayed in the room.';
}

/**
 * The trail's opening sentence: geography first, material second, then the
 * floor's history — who opened, who never spoke, who left, who closed. Counts
 * and titles, never a memory's words (the duet's rule).
 */
function xeric_room_why(array $names, array $handles, array $room, array $material,
                        string $first, ?string $last, array $neverSpoke, array $departed): string
{
    $who = xeric_join_list(array_map(fn($h) => $names[$h], $handles));
    $out = $who . ' were all at '
         . ($room['place_name'] !== '' ? $room['place_name'] : 'the same place')
         . ' (' . $room['why'] . '); ';

    $bits = [];
    foreach ($handles as $h) {
        foreach (($material[$h]['about'] ?? []) as $o => $n) {
            $bits[] = $names[$h] . ' carried ' . $n . ' unsettled thing' . ($n === 1 ? '' : 's')
                    . ' about ' . $names[$o];
        }
    }
    $ev = null;
    foreach ($handles as $h) {
        if (($material[$h]['event'] ?? null) !== null) { $ev = (string)$material[$h]['event']; break; }
    }
    if ($ev !== null) $bits[] = 'the town remembers "' . $ev . '"';
    $out .= $bits !== [] ? ucfirst(implode('; ', $bits)) . '. ' : 'Nothing was on record between any of them yet. ';

    $out .= $names[$first] . ' spoke first';
    foreach ($departed as $h) $out .= '; ' . $names[$h] . ' left partway through';
    foreach ($neverSpoke as $h) $out .= '; ' . $names[$h] . ' never said a word';
    if ($last !== null) $out .= '; ' . $names[$last] . ' had the last word';
    return $out . '.';
}

// ===========================================================================
// THE ROOM WITH YOU IN IT
// ===========================================================================
//
// Everything above is the room the world has with itself: the cast talk, you
// watch (or a CLI does), nothing lands until the close. What follows is the
// same room with the player standing in it, and it departs from that on
// PURPOSE in exactly one place — LINES LAND ONE AT A TIME, as message rows of
// a conversation of kind `room` (handle '', the place key as title), so the
// thread, the book, speak.php and the inspector work on them unchanged. The
// close still lands once, through the same xeric_room_land() as above.
// xeric_room() itself keeps the old law, and room-test pins it.
//
// The bookkeeping is ONE world_state row, `room:live`, because the player has
// one body: who is seated and since which message, who left and the last line
// they heard, the silence streaks (the player's too), the turn's budget, the
// worker's lease, the due time of the last published line, who is typing, and
// the trail. Every edit runs inside BEGIN IMMEDIATE (xeric_room_live_edit), so
// a player line and a worker's line can never both believe they came second.
//
// The functions are the watch's shape — PURE AND WRITE-FREE: open admits,
// hear checks a line, next draws, step speaks (one model call, nothing
// written), carry decides whether the room keeps talking, pace spaces it. The
// writes are the caller's, under the edit, and the web layer is the caller.
//
// THE FIRST LAW STILL HOLDS AT THE TABLE: every cast line is its own call from
// that speaker's own assembly. N people answering is N calls. The player's
// line is no call at all.

/** The live session, or null. */
function xeric_room_live_get(PDO $db): ?array
{
    $raw = xeric_world_state_get($db, XERIC_ROOM_LIVE);
    if ($raw === null || $raw === '') return null;
    $d = json_decode($raw, true);
    return is_array($d) && (int)($d['conv'] ?? 0) > 0 ? $d : null;
}

/**
 * Read, change and write the live session as ONE step: BEGIN IMMEDIATE takes
 * the write lock before the read, so the read cannot go stale under the write
 * — the compare-and-set a lease race needs. $fn gets the session by reference
 * (null when there is none) and may set it to null to end it; its return
 * value is returned. Message rows appended inside $fn land in the same
 * transaction as the bookkeeping that counts them.
 */
function xeric_room_live_edit(PDO $db, callable $fn)
{
    static $depth = 0;
    $put = static function (PDO $db, ?array $was, ?array $live): void {
        if ($live === null) {
            if ($was !== null) xeric_world_state_delete($db, XERIC_ROOM_LIVE);
            return;
        }
        if ($live !== $was) {
            xeric_world_state_set($db, XERIC_ROOM_LIVE,
                json_encode($live, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    };
    if ($depth > 0) {                                  // already inside one: it is ours
        $live = xeric_room_live_get($db);
        $was  = $live;
        $r    = $fn($live);
        $put($db, $was, $live);
        return $r;
    }
    $db->exec('BEGIN IMMEDIATE');
    $depth++;
    try {
        $live = xeric_room_live_get($db);
        $was  = $live;
        $r    = $fn($live);
        $put($db, $was, $live);
        $db->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { /* nothing was begun */ }
        throw $e;
    } finally {
        $depth--;
    }
}

/** Is a worker holding the floor right now? */
function xeric_room_lease_fresh(?array $live, ?float $at = null): bool
{
    $l = is_array($live) ? ($live['lease'] ?? null) : null;
    return is_array($l) && (float)($l['until'] ?? 0) > ($at ?? microtime(true));
}

/**
 * End the live session and set it aside for its close: the row moves to
 * room:seal:<conversation>, and room:live is free for the next one at once —
 * walking out of the diner and into the bar must not wait on four diary
 * calls. Returns the conversation id sealed, or null when there was none.
 */
function xeric_room_seal(PDO $db, string $reason, ?array $now = null): ?int
{
    return xeric_room_live_edit($db, function (?array &$live) use ($db, $reason, $now): ?int {
        return xeric_room_seal_here($db, $live, $reason, $now);
    });
}

/** The seal, for a caller already inside xeric_room_live_edit(): sets $live to null. */
function xeric_room_seal_here(PDO $db, ?array &$live, string $reason, ?array $now = null): ?int
{
    if ($live === null) return null;
    $live['ended']    = $reason;
    $live['ended_at'] = time();
    if ($now !== null && isset($now['epoch'])) $live['ended_epoch'] = (int)$now['epoch'];
    $live['lease']  = null;
    $live['typing'] = null;
    $live['budget'] = 0;
    $conv = (int)$live['conv'];
    xeric_world_state_set($db, XERIC_ROOM_SEAL . $conv,
        json_encode($live, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $live = null;
    return $conv;
}

/** Every session waiting for its close, oldest first. */
function xeric_room_seals(PDO $db): array
{
    $out = [];
    foreach (xeric_world_state_all($db) as $k => $v) {
        if (!str_starts_with((string)$k, XERIC_ROOM_SEAL)) continue;
        $d = json_decode((string)$v, true);
        if (is_array($d) && (int)($d['conv'] ?? 0) > 0) $out[(int)$d['conv']] = $d;
    }
    ksort($out);
    return $out;
}

/** Who is talking with you right now — the people a sweep must leave be. */
function xeric_room_live_people(PDO $db): array
{
    $live = xeric_room_live_get($db);
    return $live === null ? [] : array_values(array_map('strval', (array)$live['present']));
}

/** The room's message rows, oldest first, after a cursor. */
function xeric_room_rows(PDO $db, int $conv, int $after = 0): array
{
    $st = $db->prepare('SELECT id, role, handle, content, world_epoch, created_at, player, rating FROM messages
                         WHERE conversation_id = ? AND id > ? ORDER BY id');
    $st->execute([$conv, $after]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    // `player` is who at the centre said a user row (NULL: the first); a row
    // stamped above whoever this process reads for comes back emptied
    // (xeric_state_message_shown), to the page and to the room's prompt alike.
    return array_map('xeric_state_message_shown', $rows ?: []);
}

/**
 * Rows as the room's lines: the player as '__you', the cast by handle, and
 * the narrator's rows (who came in, who went) as stage directions — kind
 * 'stage', which every mapper above renders as a thing the world did.
 */
function xeric_room_lines(array $live, array $rows): array
{
    $names = (array)$live['names'];
    $out = [];
    foreach ($rows as $r) {
        $role = (string)$r['role'];
        $h    = (string)($r['handle'] ?? '');
        if ($role === 'user') {
            // WHICH person at the centre — NULL is the first, as everywhere.
            $out[] = ['mid' => (int)$r['id'], 'handle' => XERIC_ROOM_YOU,
                      'name' => (string)($names[XERIC_ROOM_YOU] ?? 'you'), 'text' => (string)$r['content'], 'kind' => 'line',
                      'player' => max(1, (int)($r['player'] ?? 0))];
        } elseif ($role === 'character' && $h !== '') {
            $out[] = ['mid' => (int)$r['id'], 'handle' => $h, 'name' => (string)($names[$h] ?? $h),
                      'text' => (string)$r['content'], 'kind' => 'line'];
        } else {
            $out[] = ['mid' => (int)$r['id'], 'handle' => '', 'name' => '',
                      'text' => (string)$r['content'], 'kind' => 'stage'];
        }
    }
    return $out;
}

/**
 * What one person heard: from the row they were seated at (an arrival hears
 * only from the moment they came in) to the row that saw them leave.
 */
function xeric_room_heard(array $live, array $lines, string $h): array
{
    if ($h === XERIC_ROOM_YOU) return $lines;
    $from = (int)($live['seats'][$h]['from'] ?? 0);
    $to   = isset($live['left'][$h]) ? (int)($live['left'][$h]['mid'] ?? PHP_INT_MAX) : PHP_INT_MAX;
    return array_values(array_filter($lines, fn($l) => (int)$l['mid'] >= $from && (int)$l['mid'] <= $to));
}

/**
 * Was the player seated under a name? A session from before the flag was kept
 * is read off its names: the literal 'you' was the nameless fallback.
 */
function xeric_room_you_named(array $live): bool
{
    $p = (array)($live['player'] ?? []);
    if (array_key_exists('named', $p)) return (bool)$p['named'];
    $n = trim((string)($live['names'][XERIC_ROOM_YOU] ?? ''));
    return $n !== '' && strcasecmp($n, 'you') !== 0 && $n !== XERIC_ROOM_VISITOR;
}

/** The player as prose names them: their name, or the visitor. */
function xeric_room_you_name(array $live): string
{
    return xeric_room_you_named($live) ? trim((string)$live['names'][XERIC_ROOM_YOU]) : XERIC_ROOM_VISITOR;
}

/** The player as a mention test reads them: their name, or a sentinel no line can contain. */
function xeric_room_you_mention(array $live): string
{
    return xeric_room_you_named($live) ? trim((string)$live['names'][XERIC_ROOM_YOU]) : XERIC_ROOM_NAMELESS;
}

/**
 * Heat ABOUT THE PLAYER, the draw's unit: this person's own memories naming
 * the user (rows, never paraphrase, capped like the cast's) plus one notch
 * for a promise the player still owes them.
 */
function xeric_room_heat_you(array $memrows, array $expects, string $youName): int
{
    $hot = 0;
    foreach (array_reverse($memrows) as $m) {
        $text = trim((string)($m['text'] ?? ''));
        if ($text !== '' && $youName !== '' && xeric_age_mentions($text, $youName, $youName)) $hot++;
        if ($hot >= XERIC_ROOM_MATERIAL) break;
    }
    foreach ($expects as $e) {
        if (($e['state'] ?? '') === 'open' && (string)($e['of'] ?? '') === '') { $hot++; break; }
    }
    return $hot;
}

/**
 * Seat a room around the player. WRITES NOTHING.
 *
 * Two to five cast plus you. One is not a room — that is their thread, and
 * the refusal says so. Six or more is a crowd, and a crowd is not refused: the
 * five with the most reach × heat ABOUT YOU sit at your table, and the rest
 * are "at the far table" — in the room (the wall check still counts them, and
 * so does the age floor), never in the conversation. The ceiling is folded
 * once, here, over who sat down: the lowest one standing in it.
 *
 * @param array $player ['id' => int, 'name' => string]
 * @return array the live session, conv 0 until the caller files the thread
 * @throws RuntimeException 'room: refused, …' — nothing written
 */
function xeric_room_open(array $t, PDO $db, array $now, string $place, array $player, array $opts = []): array
{
    if (!isset($now['epoch'])) throw new RuntimeException('room: a room needs a moment, pass xeric_world_now()');
    if (xeric_world_place($t, $place) === null) throw new RuntimeException('room: refused, there is no such place');
    $pname    = xeric_world_place_name($t, $place);
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));
    $here     = [];
    foreach (xeric_world_who_is_at($presence, $place) as $h) {
        if (xeric_world_character($t, $h) !== null) $here[] = $h;
    }
    if ($here === []) throw new RuntimeException('room: refused, nobody is at ' . $pname . ' to talk to');
    if (count($here) === 1) {
        throw new RuntimeException('room: refused, only ' . xeric_world_name($t, $here[0])
            . ' is here — that is a conversation, not a room: open their thread');
    }
    $given = trim((string)($player['name'] ?? ''));
    if (strcasecmp($given, 'you') === 0) $given = '';    // the old fallback, read as no name at all
    $you   = $given !== '' ? $given : XERIC_ROOM_VISITOR;
    $needle = $given !== '' ? $given : XERIC_ROOM_NAMELESS;

    // THE FAR TABLE. Ranked by what each of them is carrying about you, the
    // ties broken by admission order so the same room seats the same five.
    $far = [];
    if (count($here) > XERIC_ROOM_MAX) {
        $score = [];
        foreach ($here as $i => $h) {
            $hot = xeric_room_heat_you(xeric_memories_for($db, $h, 40), xeric_expects_for($db, $h), $needle);
            $score[$h] = [xeric_learn_reach($db, $h) * (1.0 + XERIC_ROOM_HEAT * $hot), -$i];
        }
        $ranked = $here;
        usort($ranked, fn($a, $b) => $score[$b] <=> $score[$a]);
        $seat = array_slice($ranked, 0, XERIC_ROOM_MAX);
        $far  = array_values(array_diff($here, $seat));
        $here = array_values(array_intersect($here, $seat));
    }

    $room = xeric_room_together($t, $db, $here, $now);

    $eff   = (string)($opts['effective_rating'] ?? xeric_world_rating($t, $opts['model_rating'] ?? null));
    $minor = false;
    foreach ($here as $h) {
        $who = xeric_viewer($t, ['handle' => $h]);
        if ($who['is_minor']) { $minor = true; $eff = xeric_viewer_rating($eff, $who); }
    }

    $names = [];
    foreach (array_merge($here, $far) as $h) $names[$h] = xeric_world_name($t, $h);
    $names[XERIC_ROOM_YOU] = $you;

    $seats = [];
    foreach ($here as $h) {
        $seats[$h] = ['others' => array_values(array_diff($here, [$h])), 'epoch' => (int)$now['epoch'], 'from' => 0];
    }

    return [
        'v'          => 1,
        'conv'       => 0,
        'place'      => $place,
        'room'       => $room,
        'opened'     => (int)$now['epoch'],
        'opened_at'  => time(),
        'player'     => ['id' => (int)($player['id'] ?? 1), 'name' => $you, 'named' => $given !== ''],
        'seated'     => $here,
        'present'    => $here,
        'far'        => $far,
        'names'      => $names,
        'seats'      => $seats,
        'left'       => [],
        'arrived'    => [],
        'eff'        => $eff,
        'minor'      => $minor,
        'streak'     => array_fill_keys(array_merge($here, [XERIC_ROOM_YOU]), 0),
        'noticed'    => array_fill_keys(array_merge($here, [XERIC_ROOM_YOU]), false),
        'spoke'      => array_fill_keys($here, 0),
        'beats'      => 0,
        'said'       => 0,
        'seq'        => 0,
        'budget'     => 0,
        'owed'       => [],
        'answered'   => 0,
        'mode'       => '',
        'run'        => 0,
        'carried'    => 0,
        'last_epoch' => (int)$now['epoch'],
        'touched'    => time(),
        'lease'      => null,
        'due'        => 0.0,
        'typing'     => null,
        'trail'      => [],
        'dropped'    => [],
        'ended'      => null,
    ];
}

/**
 * Who has come and gone since the session last looked: re-read at every beat
 * and every player line, through the one presence reader. A minor walking in
 * is reported apart, because the caller's answer to it is to seal.
 *
 * @return array{left:array<string,?string>,arrived:string[],minors:string[],far_left:string[]}
 */
function xeric_room_presence(array $t, PDO $db, array $live, array $now): array
{
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));
    $here     = xeric_world_who_is_at($presence, (string)$live['place']);
    $left = [];
    foreach ((array)$live['present'] as $h) {
        if (!in_array($h, $here, true)) $left[$h] = isset($presence[$h]['where']) ? (string)$presence[$h]['where'] : null;
    }
    $farLeft = array_values(array_diff((array)$live['far'], $here));
    $known   = array_merge((array)$live['present'], (array)$live['far']);
    $arrived = $minors = [];
    foreach ($here as $h) {
        if (in_array($h, $known, true) || xeric_world_character($t, $h) === null) continue;
        if (xeric_viewer($t, ['handle' => $h])['is_minor']) $minors[] = $h;
        else                                                 $arrived[] = $h;
    }
    return ['left' => $left, 'arrived' => $arrived, 'minors' => $minors, 'far_left' => $farLeft];
}

/**
 * An adult sits down. They hear from $fromMid on — the row that saw them come
 * in — and their system names the company AS IT WAS when they sat, fixed for
 * the rest of the session like everybody else's. A sixth goes to the far table.
 */
function xeric_room_seat(array $t, array &$live, string $h, int $fromMid, array $now): void
{
    $live['names'][$h] = xeric_world_name($t, $h);
    if (count((array)$live['present']) >= XERIC_ROOM_MAX) {
        if (!in_array($h, (array)$live['far'], true)) $live['far'][] = $h;
        return;
    }
    $live['seats'][$h] = ['others' => array_values((array)$live['present']), 'epoch' => (int)$now['epoch'], 'from' => $fromMid];
    $live['present'][] = $h;
    if (!in_array($h, (array)$live['seated'], true)) $live['seated'][] = $h;
    $live['streak'][$h]  = 0;
    $live['noticed'][$h] = false;
    $live['spoke'][$h]   = (int)($live['spoke'][$h] ?? 0);
    $live['arrived'][$h] = $fromMid;
    unset($live['left'][$h]);
}

/** Somebody gets up and goes. Where they went is the trail's, never the room's. */
function xeric_room_unseat(array &$live, string $h, int $mid, ?string $went, int $epoch): void
{
    $live['present'] = array_values(array_diff((array)$live['present'], [$h]));
    $live['owed']    = array_values(array_diff((array)$live['owed'], [$h]));
    $live['left'][$h] = ['mid' => $mid, 'went' => $went, 'epoch' => $epoch];
}

/**
 * The player's line, heard: the age floor read as a turn (this line with the
 * one it answers) against everyone admitted, and the wall over everyone in
 * the room — the far table included, because a secret said in a diner is
 * said in all of it. Returns the line as it will land.
 *
 * @throws RuntimeException the engine's refusal sentences; nothing written
 */
function xeric_room_hear(array $t, array $live, array $lines, string $text): string
{
    $text = trim((string)(preg_replace('/\s+/u', ' ', $text) ?? ''));
    if ($text === '') throw new RuntimeException('room: there is nothing to say');
    if (mb_strlen($text) > XERIC_ROOM_MAX_CHARS) $text = rtrim(mb_substr($text, 0, XERIC_ROOM_MAX_CHARS));

    $prev = '';
    foreach (array_reverse($lines) as $l) { if ($l['kind'] === 'line') { $prev = (string)$l['text']; break; } }
    $everyone = array_values(array_unique(array_merge((array)$live['seated'], (array)$live['far'])));
    $refused  = xeric_age_floor($t, $everyone, [$prev, $text]);
    if ($refused !== null) throw new RuntimeException(xeric_age_refusal('room', $refused));

    $inRoom = array_merge((array)$live['present'], (array)$live['far']);
    foreach (xeric_sweep_protected($t) as $ph => $secret) {
        if (in_array($ph, $inRoom, true) && xeric_sweep_touches($text, (string)$secret)) {
            throw new RuntimeException('room: refused, the conversation put ' . xeric_world_name($t, (string)$ph)
                . ' next to the thing they must not know');
        }
    }
    return $text;
}

/** Book the player's line into the session, after its row is appended. */
function xeric_room_said(array &$live, int $mid, string $text, array $now): void
{
    $owed = [];
    foreach ((array)$live['present'] as $h) {
        if (xeric_age_mentions($text, $h, (string)($live['names'][$h] ?? $h))) $owed[] = $h;
    }
    $live['seq']      = (int)$live['seq'] + 1;
    $live['said']     = (int)$live['said'] + 1;
    $live['owed']     = $owed;
    $live['budget']   = min(XERIC_ROOM_ANSWERS_CAP, max(XERIC_ROOM_ANSWERS, count($owed)));
    $live['answered'] = 0;
    $live['mode']     = 'answer';
    $live['run']      = 0;
    foreach ((array)$live['present'] as $h) $live['streak'][$h] = (int)($live['streak'][$h] ?? 0) + 1;
    $live['streak'][XERIC_ROOM_YOU]  = 0;
    $live['noticed'][XERIC_ROOM_YOU] = false;
    $live['last_epoch'] = (int)$now['epoch'];
    $live['touched']    = time();
    $live['trail'][]    = array_filter(['mid' => $mid, 'speaker' => XERIC_ROOM_YOU, 'how' => 'you',
                                        'addressed' => $owed !== [] ? $owed : null], fn($v) => $v !== null);
}

/**
 * Who answers next: `xeric_room_draw` with the player as a speaker. A name in
 * the player's line answers first, and when it named several each is OWED a
 * turn — drawn among themselves until each has spoken, inside the budget.
 * Otherwise reach × heat, the heat being what each of them holds about
 * whoever spoke last (about the player, when it was the player).
 */
function xeric_room_next(array $t, PDO $db, array $live, array $lines): array
{
    $present = array_values((array)$live['present']);
    $names   = (array)$live['names'];
    $names[XERIC_ROOM_YOU] = xeric_room_you_mention($live);   // the draw only tests mentions with it
    $last = null; $lastText = '';
    foreach (array_reverse($lines) as $l) {
        if ($l['kind'] !== 'line') continue;
        $last = (string)$l['handle'];
        $lastText = (string)$l['text'];
        break;
    }
    if ($last !== null && $last !== XERIC_ROOM_YOU && !in_array($last, $present, true)) $last = null;

    $reach = $memrows = $expects = [];
    foreach ($present as $h) {
        $reach[$h]   = xeric_learn_reach($db, $h);
        $memrows[$h] = xeric_memories_for($db, $h, 40);
        $expects[$h] = xeric_expects_for($db, $h);
    }

    $owed = array_values(array_diff(array_intersect((array)$live['owed'], $present), [(string)$last]));
    $pool = $owed !== [] ? $owed : $present;
    if (array_diff($pool, [(string)$last]) === []) $pool = $present;   // nobody but the last speaker: let them
    $d = xeric_room_draw($pool, $last, $lastText, $names, $reach, $memrows, $expects);
    if ($owed !== [] && $d['how'] === 'draw') $d['how'] = 'owed';
    $d['last'] = $last;
    return $d;
}

/**
 * ONE BEAT: one model call from the drawn speaker's own assembly, cleaned,
 * floored and walled — the room loop's own steps, in its own order. WRITES
 * NOTHING; returns the line and the receipts, including the system string so
 * a test can hold it byte-identical across the session (the prefix cache).
 *
 * @throws RuntimeException a refusal, a dead model, an unusable line
 */
function xeric_room_step(array $t, PDO $db, array $live, array $lines, array $now, array $endpoint,
                         array $draw, array $opts = []): array
{
    $speaker = (string)$draw['speaker'];
    $names   = (array)$live['names'];
    $you     = xeric_room_you_name($live);
    $names[XERIC_ROOM_YOU] = $you;
    if (!empty($live['ended'])) throw new RuntimeException('room: refused, this conversation is over');
    if (!in_array($speaker, (array)$live['present'], true)) {
        throw new RuntimeException('room: refused, ' . ($names[$speaker] ?? $speaker) . ' is not in the room any more');
    }
    // A ROOM DOES NOT TALK TO SOMEBODY WHO HAS GONE. Read off the world, not
    // off the session: a walk the session has not heard about yet still ends it.
    // THE ROOM'S OWN PLAYER, never whoever made this request: a second person
    // standing elsewhere must not read as the room's player having left.
    if (xeric_player_where($t, $db, (int)($live['player']['id'] ?? 1)) !== (string)$live['place']) {
        throw new RuntimeException('room: refused, ' . $you . ' is not in the room, and nobody answers somebody who has left');
    }
    if (xeric_is_dead($db, $speaker)) throw new RuntimeException(xeric_death_refusal('room', (string)$names[$speaker]));

    $seat      = (array)($live['seats'][$speaker] ?? ['others' => [], 'epoch' => (int)$live['opened']]);
    $others    = array_values((array)$seat['others']);
    $walls     = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $speaker]));
    $system    = xeric_room_system($t, $db, $speaker, $others, (string)$live['eff'], (int)$seat['epoch'], $walls,
                                   (int)($opts['memory_limit'] ?? 12), $you);
    $protected = xeric_sweep_protected($t);
    $memrows   = xeric_memories_for($db, $speaker, 40);
    $named     = $names;
    foreach ($others as $o) $named[$o] = $named[$o] ?? xeric_world_name($t, $o);
    $material  = xeric_room_material($t, $speaker, $others, $named, $memrows, xeric_events_recent($db, 12), $protected);
    $tail      = xeric_room_scene($t, $speaker, $others, (array)$live['room'], $walls, $material);
    $heard     = xeric_room_heard($live, $lines, $speaker);

    // Who has been quiet — the player counted — said once per stretch,
    // through this mouth, and never stronger than "you may notice".
    $quiet = [];
    foreach (array_merge((array)$live['present'], [XERIC_ROOM_YOU]) as $h) {
        if ($h !== $speaker && (int)($live['streak'][$h] ?? 0) >= XERIC_ROOM_QUIET_BEATS) $quiet[] = $h;
    }
    usort($quiet, fn($a, $b) => (int)$live['streak'][$b] <=> (int)$live['streak'][$a]);
    $notice = null;
    foreach ($quiet as $h) { if (empty($live['noticed'][$h])) { $notice = $h; break; } }

    $messages = xeric_room_messages($t, $speaker, $others, $system, $heard, $tail, $now, $walls,
        xeric_deaths($db), $names, $heard === [], false,
        $notice !== null ? $names[$notice] . ' has not said a word in a while. You may notice that, or let it be.' : '',
        $db, (string)$live['place'], $you);

    // The caller resolved the speaker's machine (the pin); the slot on it is
    // the speaker's own when that machine is a llama.cpp with slots to spare.
    $sopts = [
        'temperature' => (float)($opts['temperature'] ?? 0.85),
        'max_tokens'  => (int)($opts['max_tokens'] ?? XERIC_ROOM_MAX_TOKENS),
    ] + array_intersect_key($opts, ['timeout' => 1]);
    $slot = xeric_llm_slot($endpoint, $speaker);
    if ($slot !== null) $sopts['id_slot'] = $slot;

    $u  = [];
    $t0 = microtime(true);
    try {
        $raw = xeric_chat_say($endpoint, $messages, $sopts, $u);
    } catch (Throwable $e) {
        throw new RuntimeException('room: ' . $names[$speaker] . ' did not answer, ' . $e->getMessage(), 0, $e);
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);

    // The theft cut for EVERY other voice in the room, the player's first:
    // a model that writes "Walt: …" has started writing the player's lines.
    $otherNames = [$you];
    foreach (array_merge((array)$live['present'], (array)$live['far']) as $h) {
        if ($h !== $speaker) $otherNames[] = (string)($names[$h] ?? xeric_world_name($t, $h));
    }
    $text = xeric_room_clean($raw, (string)$names[$speaker], $otherNames, (int)($opts['max_chars'] ?? XERIC_ROOM_MAX_CHARS));
    if ($text === '') {
        throw new RuntimeException('room: ' . $names[$speaker] . ' said nothing usable (' . mb_substr(trim($raw), 0, 120) . ')');
    }

    $prev = '';
    foreach (array_reverse($lines) as $l) { if ($l['kind'] === 'line') { $prev = (string)$l['text']; break; } }
    $everyone = array_values(array_unique(array_merge((array)$live['seated'], (array)$live['far'])));
    $refused  = xeric_age_floor($t, $everyone, [$prev, $text]);
    if ($refused !== null) throw new RuntimeException(xeric_age_refusal('room', $refused));
    $inRoom = array_merge((array)$live['present'], (array)$live['far']);
    foreach ($protected as $ph => $secret) {
        if (in_array($ph, $inRoom, true) && xeric_sweep_touches($text, (string)$secret)) {
            throw new RuntimeException('room: refused, the conversation put ' . xeric_world_name($t, (string)$ph)
                . ' next to the thing they must not know');
        }
    }

    return [
        'handle'   => $speaker,
        'name'     => (string)$names[$speaker],
        'text'     => $text,
        'quiet'    => $quiet,
        'notice'   => $notice,
        'system'   => $system,
        'messages' => $messages,
        'usage'    => ['prompt_tokens' => (int)($u['prompt_tokens'] ?? 0),
                       'completion_tokens' => (int)($u['completion_tokens'] ?? 0), 'ms' => $ms],
    ];
}

/** A seeded roll in [0, 1): the same session at the same beat rolls the same number. */
function xeric_room_roll(string $seed): float
{
    return (crc32($seed) % 100000) / 100000;
}

/**
 * After a cast beat: does the room keep talking? In order, the reasons it
 * stops — the budget the player's line bought (3, up to 4), the session's
 * 24 beats, the hourly limit, fewer than two cast, the player gone, SOMEBODY
 * WAITING ON THE MODEL (a person waiting outranks a room talking to itself),
 * pause. Then: anybody the player named who has not answered is owed a turn.
 * Then the floor RETURNS to the player when the line names them, or asks a
 * question that names nobody — a question put to the room is put to you too.
 * Otherwise p = 0.55 × 0.5^k, rolled and recorded.
 *
 * $opts: limit_ok, player_here, queue_waiting, paused (the caller's reads), seed.
 */
function xeric_room_carry(array $live, array $beat, array $opts = []): array
{
    $stop = fn(string $why, array $x = []): array => ['go' => false, 'why' => $why] + $x;
    if ((string)($live['mode'] ?? '') === 'idle') return $stop('one beat without you, then the floor is yours');
    if ((int)$live['budget'] - 1 <= 0)                 return $stop('budget');
    if ((int)$live['beats'] + 1 >= XERIC_ROOM_BEATS_CAP) return $stop('long');
    if (array_key_exists('limit_ok', $opts) && !$opts['limit_ok']) return $stop('limit');
    $present = array_values(array_diff((array)$live['present'], array_keys((array)($opts['gone'] ?? []))));
    if (count($present) < XERIC_ROOM_LIVE_MIN)          return $stop('thinned');
    if (array_key_exists('player_here', $opts) && !$opts['player_here']) return $stop('you left');
    if (!empty($opts['queue_waiting']))                 return $stop('queue');
    if (!empty($opts['paused']))                        return $stop('paused');

    $speaker = (string)$beat['handle'];
    $owed = array_values(array_diff(array_intersect((array)$live['owed'], $present), [$speaker]));
    if ($owed !== []) return ['go' => true, 'why' => 'owed', 'owed' => $owed];

    $text = (string)$beat['text'];
    $you  = xeric_room_you_mention($live);
    if (xeric_age_mentions($text, $you, $you)) return $stop('floor', ['floor' => 'named you']);
    if (preg_match('/\?\s*["\'”’)]*\s*$/u', $text)) {
        $namesAnybody = false;
        foreach ($present as $h) {
            if ($h !== $speaker && xeric_age_mentions($text, $h, (string)($live['names'][$h] ?? $h))) { $namesAnybody = true; break; }
        }
        if (!$namesAnybody) return $stop('floor', ['floor' => 'asked the room']);
    }

    $k    = (int)$live['answered'];
    $p    = XERIC_ROOM_FLOOR_P * (0.5 ** $k);
    $roll = xeric_room_roll((string)($opts['seed'] ?? $live['conv']) . '|' . (int)$live['beats']);
    return ['go' => $roll < $p, 'why' => 'roll', 'p' => round($p, 4), 'roll' => round($roll, 4)];
}

/**
 * TALKING WITHOUT YOU: may the room take one beat on its own? Asked by a
 * watching page after it has sat idle, and answered here: at most two in a row
 * and six a session, only while no turn is running, only on an idle queue,
 * and only on a LOCAL machine unless the template opts in
 * (`cast.room.carry_remote: true`) — a hosted key is not spent on a room
 * nobody asked to hear. `cast.room.carry: false` turns it off.
 *
 * $opts: queue_idle, local, player_here.
 */
function xeric_room_carry_idle(array $t, array $live, array $opts = []): array
{
    $cfg = (array)($t['cast']['room'] ?? []);
    $no  = fn(string $why): array => ['ok' => false, 'why' => $why];
    if (array_key_exists('carry', $cfg) && $cfg['carry'] === false) return $no('This xeric keeps its rooms waiting on you.');
    if (!empty($live['ended']))                         return $no('The conversation is over.');
    if ((int)$live['budget'] > 0 || xeric_room_lease_fresh($live)) return $no('Somebody is already talking.');
    if ((int)$live['said'] === 0)                       return $no('Nobody has said anything yet.');
    if ((int)$live['run'] >= XERIC_ROOM_CARRY_RUN)      return $no('The room has said its piece; it is your turn.');
    if ((int)$live['carried'] >= XERIC_ROOM_CARRY_SESSION) return $no('The room has talked on its own as much as it will this time.');
    if ((int)$live['beats'] >= XERIC_ROOM_BEATS_CAP)    return $no('This conversation has run its length.');
    if (count((array)$live['present']) < XERIC_ROOM_LIVE_MIN) return $no('There is nobody left to carry it.');
    if (array_key_exists('player_here', $opts) && !$opts['player_here']) return $no('You are not in the room.');
    if (array_key_exists('queue_idle', $opts) && !$opts['queue_idle']) return $no('Somebody is waiting on the model.');
    if (array_key_exists('local', $opts) && !$opts['local'] && empty($cfg['carry_remote'])) {
        return $no('A room only talks on its own on a local machine, unless this xeric says otherwise.');
    }
    return ['ok' => true, 'why' => ''];
}

/**
 * When a line may be shown: due = max(ready, previous due + reading time +
 * gap). Reading time is 900 ms + 230 ms a word of the PREVIOUS line, clamped
 * 1.5–7 s; the gap is 1.2 s, 300 ms shorter when somebody was named, 900 ms
 * longer when the room carries on by itself; ±15% deterministic jitter. A
 * FLOOR, never added on top of slow generation — a line that took nine
 * seconds to write is due the moment it is ready — and strictly increasing.
 * Seconds, as microtime(true) reads them.
 */
function xeric_room_pace(float $prevDue, string $prevText, float $ready, bool $named, bool $carrying, string $seed): float
{
    if ($prevDue <= 0) return round($ready, 3);
    $words = count(preg_split('/\s+/u', trim($prevText), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $read  = max(1.5, min(7.0, 0.9 + 0.23 * $words));
    $gap   = 1.2 - ($named ? 0.3 : 0.0) + ($carrying ? 0.9 : 0.0);
    $jit   = 0.85 + (crc32($seed) % 3001) / 10000;
    $due   = max($ready, $prevDue + ($read + $gap) * $jit);
    if ($due <= $prevDue) $due = $prevDue + 0.001;
    return round($due, 3);
}

/** Book a cast beat into the session, after its row is appended. */
function xeric_room_spoke(array &$live, array $beat, int $mid, array $draw, array $carry, array $now, float $due): void
{
    $sp = (string)$beat['handle'];
    $live['spoke'][$sp] = (int)($live['spoke'][$sp] ?? 0) + 1;
    $live['beats']      = (int)$live['beats'] + 1;
    foreach (array_merge((array)$live['present'], [XERIC_ROOM_YOU]) as $h) {
        if ($h === $sp) { $live['streak'][$h] = 0; $live['noticed'][$h] = false; }
        else            { $live['streak'][$h] = (int)($live['streak'][$h] ?? 0) + 1; }
    }
    if (($beat['notice'] ?? null) !== null) $live['noticed'][(string)$beat['notice']] = true;
    $live['owed']     = array_values(array_diff((array)$live['owed'], [$sp]));
    $live['answered'] = (int)$live['answered'] + 1;
    $live['budget']   = !empty($carry['go']) ? max(0, (int)$live['budget'] - 1) : 0;
    $live['last_epoch'] = (int)$now['epoch'];
    $live['touched']    = time();
    $live['due']        = $due;
    $live['typing']     = null;
    $live['trail'][] = array_filter([
        'mid'       => $mid,
        'speaker'   => $sp,
        'how'       => (string)($draw['how'] ?? 'draw'),
        'addressed' => ($draw['addressed'] ?? []) !== [] ? $draw['addressed'] : null,
        'weights'   => ($draw['weights'] ?? []) !== [] ? $draw['weights'] : null,
        'reach'     => ($draw['reach'] ?? []) !== [] ? $draw['reach'] : null,
        'heat'      => ($draw['heat'] ?? []) !== [] ? $draw['heat'] : null,
        'quiet'     => ($beat['quiet'] ?? []) !== [] ? array_values($beat['quiet']) : null,
        'noticed'   => $beat['notice'] ?? null,
        'carry'     => $carry,
        'alone'     => (string)($live['mode'] ?? '') === 'idle' ? true : null,
    ], fn($v) => $v !== null);
}

/**
 * Is the session over, read off the world? The player walked out, fewer than
 * two cast are left, nothing has been said for twenty world-minutes, it has
 * run its 24 beats, or the world is stopped. (A minor arriving, a skip and
 * stepping back are the callers' to say — they are events, not states.)
 */
function xeric_room_over(array $t, PDO $db, array $live, array $now): ?string
{
    if (xeric_clock_is_paused($db))                                return 'paused';
    // The room's own player (see xeric_room_step): a guest elsewhere seals nothing.
    if (xeric_player_where($t, $db, (int)($live['player']['id'] ?? 1)) !== (string)$live['place']) return 'you left';
    if (count((array)$live['present']) < XERIC_ROOM_LIVE_MIN)       return 'thinned';
    if ((int)$now['epoch'] - (int)$live['last_epoch'] >= XERIC_ROOM_QUIET_SECONDS) return 'quiet';
    if ((int)$live['beats'] >= XERIC_ROOM_BEATS_CAP && (int)$live['budget'] <= 0) return 'long';
    return null;
}

/** Why a session ended, as a clause. */
function xeric_room_end_words(string $reason): string
{
    return match ($reason) {
        'you left'     => 'you walked out',
        'stepped back' => 'you stepped back from the table',
        'thinned'      => 'too few were left to keep it going',
        'minor'        => 'a child walked in, and the room cannot change its ceiling mid-conversation',
        'quiet'        => 'nothing was said for twenty minutes',
        'long'         => 'it ran its length',
        'paused'       => 'the world was stopped',
        'skip'         => 'time was skipped',
        default        => $reason,
    };
}

/**
 * The close of a live session, through xeric_room_land(): each seated head's
 * diary of exactly what they were in the room for, with the player counted in
 * the company (source `room.you`); the event at the opening epoch, the player
 * in its title and prose; expectations from cast lines AND from promises the
 * player made; the trail at why:event:<id> with message ids per beat. A
 * session in which nobody answered lands nothing — the thread keeps its rows.
 * The seal row is deleted inside the same transaction.
 *
 * $endpoint null forfeits the diaries into notes; the event still lands.
 */
function xeric_room_land_live(array $t, PDO $db, array $s, ?array $endpoint, array $opts = []): array
{
    $conv  = (int)$s['conv'];
    $key   = XERIC_ROOM_SEAL . $conv;
    $lines = xeric_room_lines($s, xeric_room_rows($db, $conv));
    $names = (array)$s['names'];
    $you   = xeric_room_you_name($s);
    $names[XERIC_ROOM_YOU] = $you;
    $cast  = array_values(array_map('strval', (array)$s['seated']));
    $turns = count(array_filter($lines, fn($l) => $l['kind'] === 'line' && $l['handle'] !== XERIC_ROOM_YOU));
    if ($turns === 0) {
        xeric_world_state_delete($db, $key);
        return ['empty' => true, 'event_id' => 0, 'memories' => [], 'notes' => []];
    }

    $room = (array)$s['room'];
    $now  = xeric_world_now($t, (int)$s['opened']);
    $slices = $company = $memrows = [];
    foreach ($cast as $h) {
        $slices[$h]  = xeric_room_heard($s, $lines, $h);
        $company[$h] = array_merge(array_values(array_diff($cast, [$h])), [XERIC_ROOM_YOU]);
        $memrows[$h] = xeric_memories_for($db, $h, 40);
    }
    $first = $last = null;
    foreach ($lines as $l) {
        if ($l['kind'] !== 'line' || $l['handle'] === XERIC_ROOM_YOU) continue;
        $first ??= (string)$l['handle'];
        $last = (string)$l['handle'];
    }
    $departed   = array_keys((array)$s['left']);
    $neverSpoke = array_values(array_filter($cast, fn($h) => (int)($s['spoke'][$h] ?? 0) === 0));
    $castNames  = array_map(fn($h) => (string)$names[$h], $cast);
    $title = xeric_room_title(array_merge([$you], $castNames));
    $prose = xeric_room_prose(array_merge([$you], $castNames), $room, $now,
                              array_map(fn($h) => (string)$names[$h], $departed));
    $why = $you . ' came up to ' . xeric_join_list($castNames) . ' at '
         . ((string)($room['place_name'] ?? '') !== '' ? (string)$room['place_name'] : 'the same place')
         . ' (' . (string)($room['why'] ?? '') . '). ' . $names[$first] . ' answered first';
    foreach ($departed as $h)   $why .= '; ' . $names[$h] . ' left partway through';
    foreach (array_keys((array)$s['arrived']) as $h) $why .= '; ' . $names[$h] . ' came in partway through';
    foreach ($neverSpoke as $h) $why .= '; ' . $names[$h] . ' never said a word';
    $why .= '; ' . $names[$last] . ' had the last word. It ended because '
          . xeric_room_end_words((string)($s['ended'] ?? 'quiet')) . '.';

    return xeric_room_land($t, $db, [
        'handles'   => $cast,
        'names'     => $names,
        'company'   => $company,
        'slices'    => $slices,
        'lines'     => $lines,
        'memrows'   => $memrows,
        'room'      => $room,
        'protected' => xeric_sweep_protected($t),
        'endpoint'  => $endpoint,
        'opts'      => $opts,
        'epoch'     => (int)$s['opened'],
        'now'       => $now,
        'title'     => $title,
        'prose'     => $prose,
        'source'    => 'room.you',
        'meta'      => ['you' => $you],
        'trail'     => fn(array $notes): array => [
            'kind'         => 'room',
            'live'         => true,
            'why'          => $why,
            'place'        => (string)($room['where'] ?? ''),
            'people'       => $cast,
            'player'       => $you,
            'conversation' => $conv,
            'spoke_first'  => $first,
            'last_word'    => $last,
            'turns'        => $turns,
            'said'         => (int)$s['said'],
            'beats'        => array_values((array)$s['trail']),
            'departures'   => (object)(array)$s['left'],
            'arrivals'     => (object)(array)$s['arrived'],
            'far'          => array_values((array)$s['far']),
            'never_spoke'  => $neverSpoke,
            'dropped'      => array_values((array)$s['dropped']),
            'carried'      => (int)$s['carried'],
            'ended'        => (string)($s['ended'] ?? ''),
            'minor_clamp'  => (bool)$s['minor'],
            'rating'       => (string)$s['eff'],
            'notes'        => $notes,
            'at'           => time(),
        ],
        // Inside the close's own transaction: a seal another process already
        // landed is gone, and this close rolls back rather than land it twice.
        'inside'    => function (PDO $db) use ($key): void {
            if (xeric_world_state_get($db, $key) === null) throw new RuntimeException('that conversation has already closed');
            xeric_world_state_delete($db, $key);
        },
    ]);
}

/**
 * The close, shared by xeric_room() and the live room: diaries (model calls,
 * each allowed to fail into a note), then ONE transaction — the event, every
 * head's memories, expectations from what was promised, the panel's open
 * record when this is a discussion room, the trail, and the caller's own
 * last write. Rolled back whole if the store fails.
 */
function xeric_room_land(array $t, PDO $db, array $c): array
{
    $handles   = array_values(array_map('strval', (array)$c['handles']));
    $names     = (array)$c['names'];
    $room      = (array)$c['room'];
    $endpoint  = $c['endpoint'] ?? null;
    $protected = (array)($c['protected'] ?? []);
    $opts      = (array)($c['opts'] ?? []);
    $source    = (string)($c['source'] ?? 'room');
    $notes     = array_values((array)($c['notes'] ?? []));
    $lines     = (array)$c['lines'];
    $epoch     = (int)$c['epoch'];

    $kept = [];
    foreach ($handles as $me) {
        $others = array_values((array)($c['company'][$me] ?? array_diff($handles, [$me])));
        if ($endpoint === null) {
            $kept[$me] = [];
            $notes[]   = 'no model was attached at the close, so ' . $names[$me] . ' kept no diary of it';
            continue;
        }
        try {
            $kept[$me] = xeric_room_extract($t, $me, $others, $names, (array)($c['memrows'][$me] ?? []),
                (array)($c['slices'][$me] ?? $lines), $endpoint, $room, $handles, $protected, $kept, $opts);
        } catch (Throwable $e) {
            $kept[$me] = [];
            $notes[] = 'could not harvest a diary for ' . $names[$me] . ', ' . $e->getMessage();
        }
    }

    $at = xeric_state_time();
    $db->beginTransaction();
    try {
        $eventId = xeric_event_add($db, (string)$c['title'], $epoch, $room['where'], $handles, (string)$c['prose'], $at, false);

        foreach ($handles as $me) {
            foreach ($kept[$me] as $text) {
                xeric_memory_add($db, $me, $text, $source, [
                    'event_id' => $eventId,
                    'with'     => array_values(array_diff($handles, [$me])),
                    'place'    => $room['where'],
                ] + (array)($c['meta'] ?? []), $epoch, $at);
            }
        }

        // A promise made across a table is a promise. Same code gate, and in
        // a room of three the ADDRESSED party is the one who gets the
        // expectation — which is the same "named in the sentence outranks"
        // rule this file already uses to decide who answers a beat. The
        // dormant `of` notch in the draw weight above lights up from here.
        xeric_expect_from_scene($t, $db, $lines, (array)$c['now']);

        // AND IN A DISCUSSION ROOM, THE OPEN RECORD. Silent in every ordinary
        // world — xeric_panel() returns null and this costs one array lookup.
        // In a panel it is the point: what each of them said goes down with the
        // memory they took away from saying it, which is the closest thing this
        // engine has to the thinking behind a line, and it is what every other
        // person in the room reads before their next turn. A workshop where you
        // can see what somebody was considering is a workshop where you can
        // finish the idea they dropped.
        if (xeric_panel($t) !== null) {
            foreach ($lines as $ln) {
                if ((string)($ln['kind'] ?? '') !== 'line') continue;
                $h = (string)($ln['handle'] ?? '');
                if (!in_array($h, $handles, true)) continue;
                xeric_panel_think($db, $h, (string)($ln['text'] ?? ''),
                    (string)(($kept[$h] ?? [])[0] ?? ''), $at);
            }
        }

        xeric_world_state_set($db, 'why:event:' . $eventId,
            json_encode(($c['trail'])($notes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);

        if (isset($c['inside']) && is_callable($c['inside'])) ($c['inside'])($db);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('room: could not store the close, ' . $e->getMessage(), 0, $e);
    }
    return ['event_id' => $eventId, 'memories' => $kept, 'notes' => $notes];
}
