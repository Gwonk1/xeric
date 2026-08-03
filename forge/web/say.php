<?php
/**
 * say.php — one chat turn. You say something; somebody answers.
 *
 * The only synchronous model call in this app, and it is synchronous because a
 * turn is small: measured against the local model, two to twenty seconds. That
 * fits inside a normal request with room to spare, and a detached worker plus an
 * SSE stream to deliver one sentence would be machinery in the way of the thing
 * it was built to carry.
 *
 * What it still has to get right:
 *
 *  • THE SAME LINE AS EVERYTHING ELSE. One GPU, one slot. If the forge is
 *    building somebody's world, or another visitor is mid-skip, this WAITS its
 *    turn — briefly, and out loud. Twenty-five seconds is long enough to swallow
 *    most of somebody else's chat turn, so the ordinary case is that the visitor
 *    never learns there was a line at all; past that it answers with a position
 *    and an estimate, which the page shows where the typing dots were. It never
 *    queues silently and it never spins.
 *
 *  • A CEILING UNDER THE PROXY'S. Cloudflare gives up on an origin at about a
 *    hundred seconds and mod_php's default max_execution_time is thirty. Both
 *    would surface as "the page broke", which is a lie: the model was thinking.
 *    So the turn carries its own timeout, well under the first, and the request
 *    is given room to reach it.
 *
 *  • NOTHING HALF-WRITTEN. That is xeric_chat_turn()'s promise, not this file's:
 *    the model is called before anything is stored and both messages land in one
 *    transaction. A failure here leaves the world exactly as it was found.
 *
 * The memory harvest rides along when the engine says enough has been said and
 * the turn was quick enough to afford a second call. It is never allowed to fail
 * a turn that already happened — she answered; whether we wrote down what she
 * would remember is our problem, not hers.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    xeric_web_json(['error' => 'say.php takes a POST, {xeric, handle, text}'], 405);
}

// mod_php stops a request at 30 seconds by default and a turn may legitimately
// take longer than that. Long enough for the model, short enough that a hung
// endpoint frees the slot before anybody gives up on the page.
@set_time_limit(150);
ignore_user_abort(false);

$in     = xeric_web_input();
$slug   = xeric_web_slug((string)($in['world'] ?? $in['w'] ?? ''));
$handle = trim((string)($in['handle'] ?? ''));
$text   = trim((string)($in['text'] ?? ''));
$sid    = xeric_session_id();

if ($text === '')                 xeric_web_json(['error' => 'there is nothing to send'], 400);
if (mb_strlen($text) > 2000)      $text = mb_substr($text, 0, 2000);

// Opening it is also what forks it: somebody else's world is answered from this
// visitor's own copy of the database (session.php), made on first entry.
try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}
$T  = $w['template'];
$db = $w['db'];

// Fail on a handle nobody answers to HERE rather than in the engine: a UI that
// sent the wrong person deserves a 400, not a 500 out of chat.php.
if (xeric_world_character($T, $handle) === null) {
    xeric_web_json(['error' => count((array)($T['cast']['characters'] ?? [])) === 0
        ? 'There is nobody in this xeric to talk to. Every pass that writes people came back empty, which '
          . 'should not be possible, the cast can be rerolled from the review step.'
        : 'Nobody in this xeric answers to that name any more. If somebody was rerolled while this page was '
          . 'open, reload it, the cast has changed under you. Nothing you typed was lost.'], 400);
}

// -- has this visitor an hour's worth of talking left? -----------------------
xeric_limit_guard(xeric_limit_check('message', ['sid' => $sid]));

// -- one model at a time, in the order people asked ---------------------------
// Waits its turn rather than losing a race. Past the wait it answers with the
// position, which the page prints where the typing dots were — "you are 2nd in
// line — about 40 seconds" is a fact somebody can decide about; a 409 is not.
$got = xeric_queue_take('say', XERIC_QUEUE_SAY_WAIT, $sid);
if (!$got['ok']) {
    $kind = (string)($got['kind'] ?? 'queued');
    $retry = (int)($got['retry_after'] ?? 15);
    if ($retry > 0 && !headers_sent()) header('Retry-After: ' . $retry);
    // 429 is "not yet, and it is about your turn"; 503 is "not right now, and it
    // is not about you at all" — the owner has the GPU, the line is full for
    // everybody, or this machine cannot get at its own slot file.
    xeric_web_json([
        'error'       => (string)$got['message'],
        'kind'        => $kind,
        'ahead'       => (int)($got['ahead'] ?? 0),
        'eta'         => (int)($got['eta'] ?? 0),
        'phrase'      => (string)($got['phrase'] ?? ''),
        'retry_after' => $retry,
    ], in_array($kind, ['drained', 'full', 'broken'], true) ? 503 : 429);
}
$lock = $got['hold'];

// Every answer below leaves through here. xeric_web_json() ends in exit(), which
// does NOT run a finally block — so the slot is handed back explicitly, on every
// path, rather than being left to PHP's end-of-request cleanup to do quietly.
$done = function (array $body, int $status = 200) use ($lock): void {
    xeric_queue_release($lock);
    xeric_web_json($body, $status);
};

$speakerName = xeric_world_name($T, $handle);

try {
    // Per-speaker: the pinned voice machine when one stands, else the engine
    // (xeric_voice_endpoint falls back, never fails — a tuning choice must
    // not silence somebody).
    $endpoint = xeric_voice_endpoint($T, $db, $handle, $sid);
} catch (Throwable $e) {
    // Nothing attached. Said as the state it is rather than as a failure, and
    // with the fix in the sentence — this is the one error on this screen whose
    // answer is a link and not "try again". Through $done, NOT xeric_web_json:
    // the queue hold was already taken above, and answering around the release
    // left the GPU slot pinned for the full 420s reap on exactly the path
    // where no model was ever going to use it (found by the watch build).
    $done(['error' => $e->getMessage(), 'kind' => 'detached'], 409);
}
if (!xeric_llm_up($endpoint, 6)) {
    $done([
        'error' => 'The model this xeric runs on is not answering, so nobody can reply just now. It is one '
            . 'GPU on somebody\'s desk and it is sometimes doing something else, your xeric and everything '
            . 'in it is untouched. Try again in a minute.',
        'kind'  => 'model_down',
    ], 503);
}

$now = xeric_clock_now($db, $T);
$t0  = microtime(true);

// The slot is held and the model is up, so this turn is genuinely happening:
// charged here rather than at the door, where a 404 or a dead endpoint would
// have taken one of the visitor's thirty for nothing.
xeric_limit_note('message', ['sid' => $sid]);

try {
    // The overlays go in as well as the composed template ($T): a held beat opens
    // and spills in a CONVERSATION and an accusation closes in one, so a turn
    // that did not carry them is a story that never advances (play-lib.php).
    $out = xeric_chat_turn($T, $db, $handle, $text, $now, $endpoint, [
        'temperature' => 0.85,
        'timeout'     => XERIC_PLAY_CHAT_TIMEOUT,
        'stories'     => (array)($w['stories'] ?? []),
    ]);
} catch (Throwable $e) {
    // Never the engine's own sentence: it is written for a log and its tail is
    // sometimes the model's raw JSON, which is exactly what this app promises
    // never to put on a screen (play-lib.php).
    //
    // A RULE IS NOT AN OUTAGE. The age floor refusing is the engine working, and
    // it left here as a 502 with kind `model` — which told the page to draw an
    // outage, told the visitor to try again (it will refuse again), and put a
    // rule in the same bucket the model-health numbers are counted out of. So it
    // gets its own kind and a 4xx: the request was understood and will not be
    // carried out, which is a fact about the message and not about the machine.
    $refused = xeric_play_say_refused($e->getMessage());
    $done([
        'error' => xeric_play_say_error($e->getMessage(), $speakerName),
        'kind'  => $refused ? 'refused' : 'model',
    ], $refused ? 422 : 502);
    return;
}
$took = microtime(true) - $t0;

// The provenance canary answered instead of the world: nothing was written,
// there is no conversation to mark, no thread for the story to speak into and
// nothing to harvest. The engine's sentence goes to the screen and the world
// never hears about any of it — see xeric_chat_canary() for what this is.
if (!empty($out['canary'])) {
    $done(['ok' => true, 'text' => (string)$out['text'], 'canary' => true]);
    return;
}

// -- and what the world itself says about that --------------------------------
// The sentence a dead wrong lead leaves behind, and the one line that says a
// story has ended, written into the thread in the `narrator` role — the voice
// state.php has always carried and prompt.php has always rendered as "(what
// happened)". WHY IT IS WRITTEN AT ALL rather than only drawn: the transcript is
// the one durable surface on that screen, and "it was not kids that night"
// disappearing on a refresh would lose the single most load-bearing sentence in
// the story. Which voice, and why it is not the speaker's own, is argued where
// the lines are composed (xeric_play_story_lines).
//
// After the turn and never inside it. She answered; whether the world got its
// line in afterwards is our problem, exactly like the memory harvest below.
foreach (xeric_play_story_lines($w, (array)($out['story'] ?? [])) as $line) {
    try {
        xeric_message_append($db, (int)$out['conversation_id'], 'narrator', null, $line, (int)$now['epoch']);
    } catch (Throwable $e) {
        // The turn happened and is written. This one sentence is ours to lose.
    }
}

// She answered into a thread the visitor is staring at, so the reply is not a
// notification. Without this every conversation lights its own unread dot the
// moment it ends, and the dot stops meaning "somebody reached out to you".
xeric_play_mark_read($db, (int)$out['conversation_id']);

// -- what she would still know in a week -------------------------------------
// Policy is the engine's (xeric_chat_should_extract); the wall clock is ours. A
// turn that already took twenty-five seconds does not also get to spend a second
// call before this request has to be over.
$harvested = null;
$convId    = (int)$out['conversation_id'];
if ($took < XERIC_PLAY_EXTRACT_UNDER && xeric_chat_should_extract($db, $handle, $convId, 6)) {
    try {
        $harvested = xeric_chat_extract($T, $db, $handle, $convId, $endpoint, $now);
    } catch (Throwable $e) {
        $harvested = null;                  // she answered. the bookkeeping is ours to lose.
    }
}

$done([
    'ok'        => true,
    'handle'    => $handle,
    'name'      => xeric_world_name($T, $handle),
    'text'      => (string)$out['text'],
    'when'      => xeric_play_stamp($T, (int)$now['epoch']),
    'seconds'   => round($took, 1),
    'waited'    => (float)($got['waited'] ?? 0),     // seconds spent in the line, honestly reported
    'mine'      => (bool)$w['mine'],
    'harvested' => $harvested,
    // What an overlay made of the turn, relayed rather than rendered. The scene
    // question — who says the line a dead lead leaves behind — is answered above
    // and written down; this is the same movement handed to the page so it can
    // put it on the screen in the moment it happened, without re-reading the
    // thread. Empty for every world carrying no story.
    'story'     => (array)($out['story'] ?? []),
    'state'     => xeric_play_state($w, $sid),
]);
