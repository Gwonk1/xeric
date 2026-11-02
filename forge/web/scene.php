<?php
/**
 * scene.php — the room you are standing in, as data, as bytes, and on request.
 *
 *     GET  scene.php?w=<slug>                  → the brief, the newest view, the machine
 *     GET  scene.php?w=<slug>&k=<key>&f=<fp>   → that view's bytes, or 404
 *     GET  scene.php?w=<slug>&k=<key>&f=sound:<fp> → that sound's bytes, or 404
 *     POST scene.php {w, a: 'look'}            → ask for the room as it looks (and sounds) now
 *
 * where.php's sibling, and built on the same bet. Everything a viewport ever
 * needs — who is in the room, what the light is doing, which view of it is
 * already on disk and whether that view is still true — is answered by the
 * engine (engine/scene.php) and read here through one function
 * (xeric_play_look) that the sidebar uses too. A client that wanted to know
 * something about a room that is not in this response would be asking for a
 * fix in the engine, never for one in the client.
 *
 * READING IS EVERYBODY'S, like the map. The brief is observables only — the
 * engine's own promise, tested string by string against every wall in the
 * fixture — so a guest may read it as freely as they read who is at the diner.
 * What a guest may not do is SPEND: the look is the owner's, or a visitor's in
 * their own copy, exactly the door photo.php's `go` keeps.
 *
 * THE SERVING HALF is photo.php's hole punched a third time, with the same
 * refusal discipline and one widening, argued in xeric_play_look_file: a
 * splat or a glb is served as an attachment off a four-type allowlist and
 * never inline. A viewer row has no bytes here at all — it is a link, and the
 * page opens it in a new tab, never in a frame.
 *
 * PER COPY, like photos. A fork's views live beside the fork's db, so the open
 * below resolves this session's copy, and the bytes GET will only ever find
 * files in that copy's own scenes dir. A row the fork inherited whose file is
 * beside somebody else's db is a 404 here, which is the honest answer.
 *
 * WHY THE LOOK IS A JOB. A still is seconds; a panorama or a room a world
 * model builds is minutes, and a generic machine may answer "later" and be
 * asked after for a quarter of an hour. No request is held that long: the
 * POST enqueues, spawns scene-worker.php detached, and hands back a job the
 * page follows over progress.php like every other long thing in this app.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in   = $post ? xeric_web_input() : $_GET;
$slug = xeric_web_slug((string)($in['w'] ?? $in['world'] ?? ''));
if ($slug === '') {
    if ($post || !isset($_GET['k'])) xeric_web_json(['error' => 'which xeric?'], 404);
    http_response_code(404); exit;
}

// -- the look ----------------------------------------------------------------
if ($post) {
    $sid = xeric_session_id();
    try {
        $w = xeric_play_open($slug);
    } catch (Throwable $e) {
        xeric_web_json(['error' => $e->getMessage()], 404);
    }
    if ((string)($in['a'] ?? '') !== 'look') xeric_web_json(['error' => 'a is look'], 400);

    // THE MACHINE IS THE OWNER'S, and so is its bill. A guest in the real
    // world reads the room; they do not get to order it drawn on somebody
    // else's key.
    xeric_play_owner_only($w, 'asking for the room to be drawn');

    $t     = $w['template'];
    $db    = $w['db'];
    $brief = xeric_scene_brief($t, $db);
    if ($brief === null) {
        xeric_web_json(['error' => 'You are nowhere in particular, so there is no room to look at.'], 409);
    }
    if (empty($brief['enabled'])) {
        xeric_web_json(['error' => 'This world does not draw its rooms.'], 409);
    }
    // WHAT THIS PRESS OWES: the look, unless it is already drawn as it looks
    // now and on this disk; and the sound, when this machine can make one
    // (xeric_scene_channels — an image machine draws and cannot hear) and the
    // room does not already sound like this on disk. Reading the endpoint is a
    // session read, not a call, so this is decided before anybody waits.
    $ep    = xeric_scene_endpoint();
    $owe   = xeric_play_look_owed($t, $db, $brief, $ep);
    $sound = $owe['sound_brief'];
    unset($owe['sound_brief']);
    if (!in_array(true, $owe, true)) {
        // Already drawn and heard. Nothing to spend, nothing to wait for.
        xeric_web_json(['ok' => 1, 'job' => '', 'fingerprint' => (string)$brief['fingerprint'], 'pending' => false]);
    }

    // ASKED, NOT ASSUMED. The sidebar lights the button on a CONFIGURED
    // machine without calling it (it renders on every turn); this is the one
    // place a press is turned into spend, so this is where the machine is
    // asked whether it is there at all — BEFORE anything is queued, because a
    // pending row with no machine behind it is a "developing…" that never ends.
    if ($ep === null || !xeric_scene_up($ep, 4)) {
        xeric_web_json(['error' => 'no scene machine is answering; the brief is still readable'], 409);
    }

    // ONE DETACHED PROCESS PER PRESS, so a budget as well as an owner —
    // photo.php's reasoning verbatim: a render is closer to redrawing
    // something than to saying a sentence, and it must not eat the allowance
    // somebody needs to talk to the cast.
    // AND THE WORLD'S OWN CEILING (engine/budget.php): a world out of renders
    // for today says so, naming the ceiling, before a row is queued.
    $bv = xeric_budget_allows($t, $db, xeric_clock_now($db, $t), 'renders', 1, xeric_scene_costly($ep));
    if (!$bv['ok']) xeric_web_json(xeric_budget_body($bv), 429);
    xeric_limit_guard(xeric_limit_check('reroll', ['sid' => $sid]));

    // ASKING AGAIN IS A REAL ASK. The queue is one row per look, so a look
    // that failed three times, or a done row whose file is not on this disk
    // any more, would otherwise answer every later press with the same dead
    // row. Somebody pressing the button IS the retry; it is put back to
    // pending with its tries cleared, and only from those two states — a row
    // that is pending or working is left exactly as it is.
    $retry = $db->prepare("UPDATE scene_jobs SET status = 'pending', tries = 0, done_at = NULL, remote_job = NULL
                           WHERE id = ? AND status IN ('failed', 'done')");
    foreach (['look' => $brief, 'sound' => $sound] as $ch => $br) {
        if (empty($owe[$ch]) || $br === null) continue;
        $id = xeric_scene_enqueue($db, $br);
        if ($id === 0 && $ch === 'look') xeric_web_json(['error' => 'This room cannot be asked for.'], 409);
        if ($id !== 0) $retry->execute([$id]);
    }

    $job = xeric_web_job_new();
    xeric_web_spawn($job, ['slug' => (string)$w['slug'], 'sid' => $sid], 'scene-worker.php');
    xeric_web_json(['ok' => 1, 'job' => $job, 'fingerprint' => (string)$brief['fingerprint'],
                    'pending' => $owe['look'], 'sound' => !empty($owe['sound'])]);
}

// -- the bytes -----------------------------------------------------------------
if (isset($_GET['k']) || isset($_GET['f'])) {
    try {
        $w = xeric_play_open($slug);
    } catch (Throwable $e) {
        http_response_code(404); exit;
    }
    $file = xeric_play_look_file($w['db'], (string)($_GET['k'] ?? ''), (string)($_GET['f'] ?? ''));
    if ($file === null) { http_response_code(404); exit; }

    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . strlen($file['bytes']));
    // An attachment is saved, never rendered: the name is the reaper's own
    // file name, which is [a-z0-9_-] and an extension, and nothing else.
    header('Content-Disposition: ' . ($file['inline'] ? 'inline'
        : 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) . '"'));
    header('X-Content-Type-Options: nosniff');
    // Keyed by what the room looks like, so a cached view is never wrong —
    // and private, because it is a picture of where somebody is.
    header('Cache-Control: private, max-age=3600');
    echo $file['bytes'];
    exit;
}

// -- the brief -----------------------------------------------------------------
try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}
xeric_web_json(xeric_play_look_view($w['template'], $w['db'], (string)$w['slug'], null, true, !xeric_play_is_guest($w)));
