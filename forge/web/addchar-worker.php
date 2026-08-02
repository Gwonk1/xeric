<?php
/**
 * addchar-worker.php — write one person into a town that has been running without them.
 *
 * Same shape as tick-worker.php and reroll-worker.php: detached, one JSON line
 * per note, progress.php tails it. What is different is what it does after the
 * model call that everybody expects.
 *
 * A CHARACTER IS NOT A ROW. Appending somebody to cast.characters gives a world
 * a name in the roster and nothing else: no hour they arrived, nobody who has
 * met them, no memory of a place they are supposed to have spent the last year
 * in. Every other person in the world has six weeks of baked past and they have
 * none, and it shows in the first sentence they say. So this is four passes, not
 * one:
 *
 *   1. WHO — the forge's own person-writer, given the user's brief.
 *   2. THE HOUR THEY WALKED IN — an event, on the world's clock, in a real room,
 *      with whoever is standing in it. It lands in "while you were away" and it
 *      carries a why-trail like anything else that happened.
 *   3. WHAT THEY CARRY — their own memories, so their first line is not their
 *      dossier read aloud.
 *   4. WHO KNOWS THEM — one memory each for the people most likely to have met
 *      them, so the town's side of the acquaintance exists too.
 *
 * WALLS HOLD THROUGH ALL OF IT. A protected character's memory of the newcomer
 * goes through the same gate the seed pass uses: a sentence that walks into what
 * they must not know is dropped, never rewritten, because a tidied-up sentence
 * about the secret is still a sentence about the secret.
 *
 * Usage (never a URL): php addchar-worker.php <job-id>  with the payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("addchar-worker.php is not a page\n"); }

require_once __DIR__ . '/review-lib.php';
require_once __DIR__ . '/play-lib.php';

/** One pass, one ceiling — the same the reroll and a proactive ping get. */
const XERIC_WEB_ADDCHAR_CALL_TIMEOUT = 180;

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "addchar: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'the add was given nothing to work on']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$t0 = microtime(true);
$el = fn() => round(microtime(true) - $t0, 1);

$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

$lock = null;

try {
    $slug  = xeric_web_slug((string)($payload['slug'] ?? ''));
    $want  = trim((string)($payload['name'] ?? ''));
    $brief = trim((string)($payload['about'] ?? ''));
    $orbit = (string)($payload['orbit'] ?? 'outside');
    // 'stranger' stops after the person is written: nobody here has met them,
    // so there is no hour they walked in that the town noticed and nothing in
    // anybody's memory about them. Everything they become, they become in front
    // of you. One model call instead of four, and a different story.
    $woven = (string)($payload['mode'] ?? 'woven') !== 'stranger';

    $w = xeric_review_open($slug, $sid);
    if (!$w['mine']) throw new RuntimeException('that xeric was forged in a different browser');

    xeric_web_job_append($job, ['k' => 'hello', 't' => 0.0, 'what' => 'addchar',
        'text' => $want !== '' ? 'writing ' . $want . ' into ' . (string)$w['template']['meta']['name']
                               : 'writing somebody new into ' . (string)$w['template']['meta']['name']]);

    $endpoint = xeric_play_endpoint();

    $ticket = (string)($payload['ticket'] ?? '');
    if ($ticket === '') $ticket = xeric_queue_join('reroll', $sid);

    $got = xeric_queue_wait($ticket, XERIC_QUEUE_WAIT_MAX,
        function (int $ahead, int $eta, string $phrase) use ($job, $el): void {
            xeric_web_job_append($job, ['k' => 'queue', 't' => $el(), 'ahead' => $ahead,
                'eta' => $eta, 'text' => ucfirst($phrase)]);
        });

    if (!$got['ok']) {
        xeric_queue_leave($ticket);
        xeric_web_job_append($job, ['k' => 'error', 't' => $el(),
            'kind' => (string)($got['kind'] ?? 'queued'), 'message' => (string)$got['message']]);
        exit(0);
    }
    $lock = $got['hold'];

    $say = function (string $note) use ($job, $el): void {
        xeric_web_job_append($job, ['k' => 'note', 't' => $el(), 'text' => $note,
            'level' => xeric_web_note_warn($note) ? 'warn' : 'info']);
    };

    if (xeric_queue_expired($lock)) {
        xeric_web_job_append($job, ['k' => 'error', 't' => $el(), 'kind' => 'drained',
            'message' => ucfirst(xeric_queue_stop_reason($lock))
                . '. Your xeric is exactly as you left it, press it again in a few minutes.']);
        exit(0);
    }

    $endpoint['timeout'] = XERIC_WEB_ADDCHAR_CALL_TIMEOUT;

    // ---- 1. who ------------------------------------------------------------
    $t      = $w['template'];
    $chars  = array_values((array)($t['cast']['characters'] ?? []));
    $places = (array)($t['places'] ?? []);
    $sofar  = $taken = [];
    foreach ($chars as $c) {
        $sofar[] = '- ' . (string)$c['display_name'] . ', ' . (string)($c['one_line'] ?? '');
        $taken[(string)$c['handle']] = true;
    }

    $new = xeric_forge_person(
        (array)($t['forge']['answers'] ?? []), xeric_review_concept_of($t), $places, $endpoint,
        count($chars), $orbit, $sofar, $taken, $say,
        $want !== '' ? 'writing ' . $want : 'writing somebody new', [],
        // The brief the model works from: the name if one was typed, and
        // whatever the user said about them.
        trim(($want !== '' ? $want . '. ' : '') . $brief));

    // THE TYPED NAME WINS, ALWAYS. The model is asked for it and usually obliges,
    // but a dead model falls through to the hand-written archetype table, and
    // somebody who typed "Dorothy" and got "Nell Farrow" has been ignored by
    // their own machine. The handle follows the name it belongs to.
    if ($want !== '' && mb_strtolower((string)$new['display_name']) !== mb_strtolower($want)) {
        $say('the name you typed stands: ' . $want);
        $new['display_name'] = $want;
        $new['handle']       = xeric_forge_key($want, $taken);
    }

    // The de-duper rewrites the LATER of two matching interiors, so the newcomer
    // goes last: adding somebody must never quietly rewrite a person you already
    // know and did not ask about.
    $deduped = xeric_forge_dedupe_cast(array_merge($chars, [$new]), $places, $say);
    $new     = $deduped[count($deduped) - 1];

    $t['cast']['characters'] = array_values(array_merge($chars, [$new]));
    $t = xeric_review_reseat_residents($t);
    xeric_review_save($slug, $t, null);

    $handle = (string)$new['handle'];
    $name   = (string)$new['display_name'];
    $say($name . ' is in the cast, in ' . ($orbit === 'outside' ? 'the world around you' : 'your daily rooms'));

    // ---- the live world ----------------------------------------------------
    $dbPath = (string)($payload['db'] ?? (rtrim((string)$w['dir'], '/') . '/world.db'));
    $db     = xeric_state_open($dbPath);
    // Their trust, their counters — every arc the template says a character
    // starts with. Idempotent by construction, so it cannot disturb anybody.
    xeric_state_seed($db, $t);

    $now   = xeric_clock_now($db, $t);
    $epoch = (int)$now['epoch'];
    $world = (string)($t['meta']['name'] ?? 'here');
    $user  = (string)($t['user']['name'] ?? 'you');

    $placeName = function (string $k) use ($t): string { return xeric_world_place_name($t, $k); };

    // Where they are standing this minute, by their own week; failing that, the
    // room they work in. A newcomer with nowhere to be arrives nowhere.
    $presence = xeric_world_who_is_where($t, $now, array_keys(xeric_deaths($db)));
    $where    = (string)($presence[$handle]['where'] ?? '');
    if ($where === '') $where = (string)($new['week'][0]['where'] ?? '');

    $withThem = [];
    foreach ($presence as $h => $p) {
        if ($h === $handle || (string)($p['where'] ?? '') !== $where) continue;
        $withThem[] = (string)$h;
        if (count($withThem) === 3) break;
    }

    $byHandle = [];
    foreach ($t['cast']['characters'] as $c) $byHandle[(string)$c['handle']] = $c;
    $nameOf = fn(string $h): string => (string)($byHandle[$h]['display_name'] ?? $h);

    // What each character must never learn, so nothing written below hands it
    // to them in a memory.
    $blind = [];
    foreach ((array)($t['cast']['special_roles'] ?? []) as $r) {
        $h = (string)($r['character'] ?? '');
        $x = trim((string)($r['must_not_know'] ?? ''));
        if ($h !== '' && $x !== '') $blind[$h] = $x;
    }

    $notes = [];

    // A STRANGER SKIPS ALL THREE. Not a cheaper version of the same thing — a
    // different one. Nobody here has met them, so an event where the town
    // noticed them, or a memory in a head that has never seen their face, would
    // be manufacturing exactly the shared past this mode is choosing not to
    // have. The town still learns them: they are in the roster, in a room, on a
    // schedule, and every sweep from here puts them in front of somebody. What
    // they get is nothing BEFORE now — which is what a stranger is.
    //
    // (No early exit: `exit()` skips the `finally` below, and the finally is
    // what hands the model back to whoever is next in line.)
    if (!$woven) {
        $say('nobody here has met them yet — no arrival, no shared past. '
           . 'The cast learns them from here, the way anyone learns anyone.');
        $notes[] = 'a stranger — the town starts finding out about them tonight';
    }

    // ---- 2. the hour they walked in ----------------------------------------
    if ($woven) {
    $say('the hour ' . $name . ' walked in');
    $arrival = xeric_forge_attempt('arrival', function () use (
        $endpoint, $world, $user, $name, $new, $where, $withThem, $nameOf, $placeName, $say
    ) {
        $room = $where !== '' ? $placeName($where) : 'wherever people end up';
        // AN EMPTY ROOM IS EMPTY. "Also there: nobody in particular" reads as an
        // invitation, and the model duly put a character in the room who was
        // demonstrably asleep — an event whose participants and whose prose
        // disagree, which is exactly the contradiction the repass exists to
        // catch and should never have been written in the first place.
        $who  = $withThem === []
            ? "Nobody else is there — the room is empty at this hour. Do not name anybody else."
            : 'Also there: ' . implode(', ', array_map($nameOf, $withThem))
              . '. Nobody else is in the room; do not name anybody who is not on that list.';
        $raw  = xeric_forge_ask($endpoint, 'arrival', [
            ['role' => 'system', 'content' =>
                'You write one small thing that just happened in a story world. Past tense, concrete, '
                . 'no summary and no welcome speech. Reply with ONE JSON object and nothing else.'],
            ['role' => 'user', 'content' =>
                "World: $world. The person at the centre is $user.\n"
                . "$name — " . (string)($new['one_line'] ?? '') . "\n"
                . (string)($new['appearance'] ?? '') . "\n\n"
                . "$name has just turned up at $room. $who\n\n"
                . "Write the moment the room noticed them. Ordinary and specific — what they were "
                . "doing, what somebody said or did not say. Nobody makes a speech and nobody "
                . "explains who they are.\n"
                . "{ \"title\": \"5 words\", \"prose\": \"2-3 past-tense sentences\" }\nNo prose outside the JSON."],
        ], ['temperature' => 1.0, 'max_tokens' => 400], $say);
        $title = xeric_forge_str($raw['title'] ?? '', '', 120);
        $prose = xeric_forge_str($raw['prose'] ?? '', '', 800);
        if ($title === '' || $prose === '') throw new RuntimeException('no usable arrival');
        return ['title' => $title, 'prose' => $prose];
    }, fn() => [
        'title' => $name . ' turned up',
        'prose' => $name . ' came in out of the weather and stood a moment before anybody said anything, '
                 . 'which around here is most of an introduction.',
    ], $say);

    xeric_event_add($db, (string)$arrival['title'], $epoch, $where !== '' ? $where : null,
        array_merge([$handle], $withThem), (string)$arrival['prose']);
    $notes[] = $arrival['title'] . ($where !== '' ? ' — ' . $placeName($where) : '');

    // ---- 3. what they carry -------------------------------------------------
    $say('what ' . $name . ' already carries');
    $castLines = [];
    foreach ($chars as $c) {
        $castLines[] = '- ' . (string)$c['display_name'] . ' — ' . (string)($c['one_line'] ?? '');
    }
    $mine = xeric_forge_attempt("memories for $handle", function () use (
        $endpoint, $world, $user, $name, $new, $castLines, $say
    ) {
        $raw = xeric_forge_ask($endpoint, 'seed_memories', [
            ['role' => 'system', 'content' =>
                'You write what one person already remembers. Concrete, small, past tense. '
                . 'Reply with ONE JSON object and nothing else.'],
            ['role' => 'user', 'content' =>
                "World: $world. $name — " . (string)($new['one_line'] ?? '') . "\n"
                . "The person at the centre is $user.\n\nOthers here:\n" . implode("\n", $castLines)
                . "\n\nWrite 3 things $name already knows, did, or owes — from before now, from their own "
                . "life. One sentence each, third person, past tense. They are not a stranger to this "
                . "place; they are somebody who has been at the edge of it.\n"
                . "{ \"memories\": [\"…\", \"…\", \"…\"] }\nNo prose outside the JSON."],
        ], ['temperature' => 1.0, 'max_tokens' => 450], $say);
        $out = xeric_forge_list($raw['memories'] ?? [], 4, 400);
        if (count($out) < 2) throw new RuntimeException('only ' . count($out) . ' usable memories');
        return $out;
    }, fn() => [
        $name . ' has been coming through here long enough to know which door sticks.',
        $name . ' owes somebody an answer and has been slow about giving it.',
    ], $say);
    foreach ($mine as $i => $m) {
        xeric_memory_add($db, $handle, (string)$m, 'event', ['added' => true],
                         $epoch - (($i + 1) * 86400 * 4));
    }
    $notes[] = count($mine) . ' ' . (count($mine) === 1 ? 'memory' : 'memories') . ' of their own';

    // ---- 4. who knows them --------------------------------------------------
    // The people in the room with them first, then whoever shares their orbit —
    // an acquaintance the world can back up is worth more than four it cannot.
    $meet = $withThem;
    foreach ($chars as $c) {
        if (count($meet) >= 3) break;
        $h = (string)$c['handle'];
        if ($h !== $handle && !in_array($h, $meet, true) && (string)($c['orbit'] ?? '') === $orbit) $meet[] = $h;
    }
    foreach ($chars as $c) {
        if (count($meet) >= 2) break;
        $h = (string)$c['handle'];
        if ($h !== $handle && !in_array($h, $meet, true)) $meet[] = $h;
    }

    $knew = 0;
    foreach ($meet as $h) {
        $them = $nameOf($h);
        $say("what $them makes of " . $name);
        // xeric_forge_attempt() hands back an array, so a one-sentence answer
        // travels in one rather than tripping the return type.
        $line = (string)(xeric_forge_attempt("$h on $handle", function () use (
            $endpoint, $world, $them, $h, $byHandle, $name, $new, $arrival, $blind, $say
        ) {
            $wall = $blind[$h] ?? '';
            $raw = xeric_forge_ask($endpoint, 'seed_memories', [
                ['role' => 'system', 'content' =>
                    'You write one thing one person remembers about another. One sentence, past tense, '
                    . 'third person, concrete. Reply with ONE JSON object and nothing else.'],
                ['role' => 'user', 'content' =>
                    "World: $world.\n$them — " . (string)($byHandle[$h]['one_line'] ?? '') . "\n"
                    . "$name — " . (string)($new['one_line'] ?? '') . "\n\n"
                    . "Just now: " . (string)$arrival['prose'] . "\n\n"
                    . "Write the one thing $them will remember about $name. Not an assessment — a "
                    . "detail, a small exchange, something they noticed and have not said out loud.\n"
                    . ($wall !== ''
                        ? "$them does not know about $wall and must not find out here. Write around it "
                        . "as though it were not there.\n"
                        : '')
                    . "{ \"memory\": \"one sentence\" }\nNo prose outside the JSON."],
            ], ['temperature' => 1.0, 'max_tokens' => 200], $say);
            $m = xeric_forge_str($raw['memory'] ?? '', '', 400);
            if ($m === '') throw new RuntimeException('nothing usable');
            return ['m' => $m];
        }, fn() => ['m' => $them . ' has not decided about ' . $name
                         . ' yet, which is not the same as not noticing.'], $say)['m'] ?? '');

        // Dropped, never rewritten. A protected person with one memory fewer is
        // a smaller loss than one who starts out already knowing.
        if (($blind[$h] ?? '') !== '' && xeric_forge_trips_wall((string)$line, (string)$blind[$h])) {
            $say("dropped what $them remembered, it walked into what they must not know");
            continue;
        }
        xeric_memory_add($db, $h, (string)$line, 'event', ['about' => $handle], $epoch);
        $knew++;
    }
    if ($knew > 0) $notes[] = $knew . ' ' . ($knew === 1 ? 'person' : 'people') . ' now remember meeting them';
    }   // end of the woven half

    $face = xeric_play_face($new);

    xeric_web_job_append($job, [
        'k' => 'done', 't' => $el(),
        'handle'  => $handle,
        'name'    => $name,
        'one_line' => (string)($new['one_line'] ?? ''),
        'hue'     => (int)$face['hue'],
        'txt'     => (string)$face['txt'],
        'where'   => $where !== '' ? $placeName($where) : '',
        'seconds' => round(microtime(true) - $t0, 1),
        'notes'   => $notes,
    ]);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 't' => $el(), 'kind' => 'addchar',
        'message' => $e->getMessage()]);
} finally {
    if (is_array($lock)) xeric_queue_release($lock);
}
exit(0);
