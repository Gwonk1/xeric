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
 *      naming the last speaker. Expectations today point only at the user
 *      (constructs.php v1), so that notch reads a field no current row
 *      carries (`of`, a cast handle) and stays dormant until cast-to-cast
 *      expectations exist; the seam is tested so the day it lights up is not
 *      the day it is discovered broken.
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
require_once __DIR__ . '/constructs.php'; // expectations — the dormant cast-to-cast seam

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
 *                       land — kind is 'line' or 'exit')
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
            $notice !== null ? $names[$notice] . ' has not said a word in a while. You may notice that, or let it be.' : '');

        $u  = [];
        $t0 = microtime(true);
        try {
            $raw = xeric_chat_say($endpoint, $messages, [
                'temperature' => (float)($opts['temperature'] ?? 0.85),
                'max_tokens'  => (int)($opts['max_tokens'] ?? XERIC_ROOM_MAX_TOKENS),
            ] + array_intersect_key($opts, ['timeout' => 1]), $u);
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

    // -- the close: every diary, one event, one trail, ONE transaction -------
    // Diary calls run BEFORE the transaction (chat.php's discipline). A diary
    // call that fails costs that head its harvest and a note, never the room:
    // the talk happened across witnessed calls, and learning is garnish
    // (learn.php's rule). Each diary reads ONLY the slice its owner was in the
    // room to hear — a departed head does not get the rest of the scene.
    $kept = [];
    foreach ($handles as $me) {
        $slice = array_slice($lines, 0, $heard[$me] ?? count($lines));
        try {
            $kept[$me] = xeric_room_extract($t, $me, array_values(array_diff($handles, [$me])),
                $names, $memrows[$me], $slice, $endpoint, $room, $handles, $protected, $kept, $opts);
        } catch (Throwable $e) {
            $kept[$me] = [];
            $notes[] = 'could not harvest a diary for ' . $names[$me] . ', ' . $e->getMessage();
        }
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

    $at = xeric_state_time();
    $db->beginTransaction();
    try {
        $eventId = xeric_event_add($db, $title, $epoch, $room['where'], $handles, $prose, $at, false);

        foreach ($handles as $me) {
            foreach ($kept[$me] as $text) {
                xeric_memory_add($db, $me, $text, 'room', [
                    'event_id' => $eventId,
                    'with'     => array_values(array_diff($handles, [$me])),
                    'place'    => $room['where'],
                ], $epoch, $at);
            }
        }

        // The trail, under the key the inspector already reads, kind decided
        // here and only here. It carries who spoke and why — WITH the weights,
        // per beat — who held silence and who was noticed for it, who left and
        // where they actually went (which the transcript deliberately does
        // not say). It never carries the transcript itself: what was said is
        // the participants', and the record of the hour is commons.
        xeric_world_state_set($db, 'why:event:' . $eventId, json_encode([
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
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('room: could not store the close, ' . $e->getMessage(), 0, $e);
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
 * last speaker — dormant until constructs.php grows cast-to-cast rows
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

    $weights = [];
    foreach ($tier as $h) {
        $hot = 0;
        if ($last !== null) {
            foreach (array_reverse($memrows[$h] ?? []) as $m) {
                $text = trim((string)$m['text']);
                if ($text !== '' && xeric_age_mentions($text, $last, $names[$last])) $hot++;
                if ($hot >= XERIC_ROOM_MATERIAL) break;
            }
            foreach ($expects[$h] ?? [] as $e) {
                if (($e['state'] ?? '') === 'open' && (string)($e['of'] ?? '') === $last) { $hot++; break; }
            }
        }
        $weights[$h] = round((float)$reach[$h] * (1.0 + XERIC_ROOM_HEAT * $hot), 3);
    }

    return [
        'speaker'   => (string)xeric_learn_order($tier, $weights)[0],
        'how'       => $how,
        'addressed' => $addressed,
        'weights'   => $weights,
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
                           int $epoch, array $walls, int $memoryLimit): string
{
    $base     = xeric_prompt_system($t, $db, $me, $eff, $memoryLimit, $epoch, $walls);
    $company  = xeric_join_list(array_map(fn($h) => xeric_world_name($t, $h), $others));
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'anyone';

    // The company is named even for a walled speaker: walls govern KNOWLEDGE,
    // and the people standing in front of you are eyesight (the duet's rule).
    // What the walls keep out of this prompt they keep out above, in the base.
    $out = [rtrim($base), ''];
    $out[] = 'THIS ONE CONVERSATION IS DIFFERENT';
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
                             bool $opening, bool $closing, string $notice): array
{
    $coach = $scene;
    if ($notice !== '') $coach[] = $notice;
    if ($opening) {
        $coach[] = 'You speak first. Start in the middle of the moment, off the thing in front of you'
                 . ' or the thing you have been meaning to say to one of them. A line or three, out loud.';
    } elseif ($closing) {
        $coach[] = 'This is the last thing said before everybody gets back to it, so let it land —'
                 . ' do not wrap the conversation up neatly, do not say goodbye like a scene ending.';
    } else {
        $coach[] = 'Take your turn — answer if it was yours to answer, or take the room somewhere'
                 . ' else. A line or three, out loud. It is a conversation, not a speech.';
    }

    $volatile = xeric_prompt_now_block($t, $me, $now, implode("\n", $coach), $walls, null, $deaths, 0);

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
        } elseif ($l['kind'] === 'exit') {
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
        $spoken[] = $l['kind'] === 'exit'
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
