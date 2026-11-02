<?php
/**
 * speak.php — one line, out loud.
 *
 *     GET speak.php?w=<slug>&m=<message id>   → that line's audio, or 404 / 503
 *
 * The speaker beside a character's bubble asks here. THE MESSAGE IS THE ONLY
 * INPUT: who is speaking, and what they said, are read off the row in THIS
 * world's database (xeric_play_spoken), never off the request — a URL that
 * could name the speaker or carry the words would let anybody put any
 * sentence in any character's mouth, in that character's voice. The player's
 * own lines and the narrator's have no voice here; they are 404, as is an id
 * this world never wrote (which includes every id from another world: a
 * world's messages live in its own db).
 *
 * THE SAME DOOR AS THE ROOM. Opening resolves this visitor's copy, like
 * scene.php and photo.php, so a fork's reader hears the fork's thread from
 * the fork's own clips folder, and nobody hears anybody else's.
 *
 * RENDERED ON FIRST REQUEST, AND ONCE. A sentence is seconds of audio and
 * somebody is waiting with their thumb on the button, so this is synchronous
 * — no job, no stream — with a sixty-second ceiling on the machine. The
 * engine files every clip by what it sounds like (xeric_voice_clip), so the
 * second press, and every press after, is a file read that costs nothing; the
 * rate limit is spent on a MISS and only on a miss, because charging somebody
 * a render they did not cause would teach them not to press play. A miss is
 * also the owner's door: a guest in the owner's real world hears every line
 * already spoken, and cannot make the owner's key speak a new one.
 *
 * WHAT IS SERVED IS WHAT IT SAYS IT IS. scene.php's rule for sound: the
 * stored type must be on the audio allowlist and the first bytes must agree,
 * or it is a 404 — a server that repeats a machine's claim as a Content-Type
 * is a stored-XSS delivery service with a play button on it.
 *
 * A MACHINE THAT REFUSES SAYS WHY. "voice not found" is the one sentence an
 * owner needs to read, so a refusal is a 503 carrying the engine's own words
 * (which never carry the key), shown once beside the bubble by play.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$slug = xeric_web_slug((string)($_GET['w'] ?? ''));
$mid  = (int)($_GET['m'] ?? 0);
if ($slug === '' || $mid <= 0) { http_response_code(404); exit; }

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    http_response_code(404); exit;
}

$msg = xeric_play_spoken($w['db'], $mid);
if ($msg === null) { http_response_code(404); exit; }

$ep = xeric_voice_endpoint();
if ($ep === null) { http_response_code(404); exit; }
// Sixty seconds for one sentence is generous, and a request that outlives it
// is a page whose speaker spins forever.
$ep['timeout'] = 60;
set_time_limit(90);

// The deal is made from the machine's own list, harvested on connect and
// kept in the session (model.php); a machine set from the environment has
// none there, and the engine asks it.
$stored = xeric_web_session_read()['voice']['voices'] ?? null;
$voices = is_array($stored) ? array_values(array_filter($stored, 'is_string')) : null;

$sid = xeric_session_id();
try {
    $clip = xeric_play_voice_clip($w['template'], $w['db'], dirname((string)$w['db_path']) . '/voice', $msg,
        $ep, $voices,
        // A MISS ONLY: the door and the budget. What has been said aloud is
        // anybody's who can read the thread; making the owner's machine say
        // something new is the owner's — or a visitor's in their own copy,
        // which is their own machine — exactly the door scene.php's look
        // keeps. Then the rate limit, metered as a `reroll` like the look:
        // closer to redrawing something than to saying a sentence.
        function () use ($w, $sid): void {
            xeric_web_spend_guard();              // a GET that renders is a GET that spends
            xeric_play_owner_only($w, 'having a new line read aloud');
            xeric_limit_guard(xeric_limit_check('reroll', ['sid' => $sid]));
        });
} catch (XericBudgetOut $e) {
    // THE WORLD'S OWN CEILING (engine/budget.php), asked by the engine after
    // the cache and before the machine: "not right now, and not about you" —
    // the owner chose how many lines a day this world says aloud.
    xeric_web_json(xeric_budget_body($e->verdict), 503);
} catch (Throwable $e) {
    xeric_web_json(['error' => xeric_web_note_safe(preg_replace('/^voice: /', '', $e->getMessage()) ?? '')], 503);
}
if ($clip === null) { http_response_code(404); exit; }

header('Content-Type: ' . $clip['mime']);
header('Content-Length: ' . strlen($clip['bytes']));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
// A line never changes once said, but it is a recording of somebody's
// thread: private, and kept by this browser only.
header('Cache-Control: private, max-age=86400');
echo $clip['bytes'];
