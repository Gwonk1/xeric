<?php
/**
 * hear.php — one line, heard.
 *
 *     POST hear.php  multipart {w, audio (a recording), h (the open thread, optional)}
 *                    → {ok:true, text} · 400 · 403 guest · 404 no machine · 413 · 415 · 429 · 503
 *
 * The hold-to-talk button beside play.php's composer posts here. What comes
 * back is TEXT, AND ONLY TEXT: this does not post the say. The page puts the
 * words in the box, the player reads them — the machine mishears names,
 * swallows a "not", hears a cough as a word — and presses send, which is
 * say.php like any typed line. "The transcript is a say; nothing else
 * changes" (docs/FUTURE.md): no new door into a turn, nothing a typed line
 * does not also pass through.
 *
 * THE OWNER'S EARS. A guest in the owner's real world may not spend the
 * owner's ears machine, exactly as they may not make the owner's voice
 * machine speak a new line (speak.php) or draw a new room (scene.php); a
 * visitor in their own copy is on their own machine and may. Then the
 * spend guard (a POST from another site is refused before a byte is read,
 * by xeric_web_input), then the `hear` rate bucket, spent only when the
 * machine actually listened.
 *
 * THE ROOM'S NAMES GO WITH IT, so Whisper spells them right: the people
 * present where the player stands, and whoever the thread is with. Names,
 * read from presence — observables, never a wall (engine/ears.php).
 *
 * NOTHING IS KEPT. The upload is read from PHP's temporary file, which PHP
 * deletes when the request ends; nothing here writes it anywhere, and the
 * transcript goes back to the page that asked and to nobody else.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

/**
 * The names a transcriber should be ready for, in this world, right now: the
 * people present where the player stands (presence, the dead taken out, as
 * the scene brief reads it), then the person the thread is with. Display
 * names, distinct, in that order.
 *
 * @return string[]
 */
function xeric_hear_names(array $T, PDO $db, string $handle = ''): array
{
    $names = [];
    try {
        $key = xeric_player_where($T, $db);
        if ($key !== null) {
            $presence = xeric_world_who_is_where($T, xeric_clock_now($db, $T), xeric_dead_handles($db));
            foreach (xeric_world_who_is_at($presence, $key) as $h) {
                $names[] = xeric_world_name($T, (string)$h);
            }
        }
    } catch (Throwable $e) {
        // Nowhere to stand is no room to name; the thread's person still counts.
    }
    if ($handle !== '' && xeric_world_character($T, $handle) !== null) $names[] = xeric_world_name($T, $handle);
    return array_values(array_unique(array_filter($names, fn($n) => trim($n) !== '')));
}

/**
 * One hearing, as a status and a body — every refusal decided here, so the
 * request below only has to read the upload and print what this says.
 *
 * @return array{0:int,1:array}
 */
function xeric_hear(array $w, string $sid, string $bytes, string $mime, string $handle = ''): array
{
    if (xeric_play_is_guest($w)) {
        return [403, ['error' => 'You are a guest in this xeric — talking instead of typing is the owner\'s to do.',
                      'kind'  => 'guest']];
    }
    $ep = xeric_ears_endpoint();
    if ($ep === null) return [404, ['error' => 'There is no ears machine set up here.']];
    // A line is seconds of audio and somebody is waiting with their thumb
    // still warm: a minute is generous, and longer is a button that spins.
    $ep['timeout'] = 60;

    $lim = xeric_limit_check('hear', ['sid' => $sid]);
    if (!($lim['ok'] ?? false)) {
        return [(int)($lim['status'] ?? 429), ['error' => (string)($lim['message'] ?? 'that is enough listening for now'),
                                                'kind' => (string)($lim['kind'] ?? 'limit'),
                                                'retry_after' => (int)($lim['retry_after'] ?? 0)]];
    }

    $names = xeric_hear_names($w['template'], $w['db'], $handle);
    try {
        $r = xeric_ears_hear($bytes, $mime, ['names' => $names], $ep);
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        $why  = xeric_web_note_safe((string)preg_replace('/^ears: /', '', $e->getMessage()));
        return [in_array($code, [400, 413, 415], true) ? $code : 503, ['error' => $why]];
    }
    if ($r === null) return [503, ['error' => 'the ears machine is not answering right now']];

    xeric_limit_note('hear', ['sid' => $sid]);   // it listened: the seat is spent
    return [200, ['ok' => true, 'text' => $r['text']]];
}

// ---------------------------------------------------------------------------
// The request. Only when this file IS the request — the suite loads the two
// functions above without running it.
// ---------------------------------------------------------------------------

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    xeric_web_json(['error' => 'hear.php takes a POST, multipart {w, audio, h}'], 405);
}
@set_time_limit(90);

// A body past post_max_size arrives as nothing at all: no fields, no file.
if ($_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    xeric_web_json(['error' => 'that recording is longer than one line can be'], 413);
}
$in   = xeric_web_input();                          // the cross-site guard, as every POST passes it
$slug = xeric_web_slug((string)($in['w'] ?? $in['world'] ?? ''));
$h    = trim((string)($in['h'] ?? ''));
$sid  = xeric_session_id();

$f   = $_FILES['audio'] ?? null;
$err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    xeric_web_json(['error' => 'that recording is longer than one line can be'], 413);
}
if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
    xeric_web_json(['error' => 'there was no recording in that'], 400);
}
if ((int)($f['size'] ?? 0) > XERIC_EARS_MAX_BYTES) {
    xeric_web_json(['error' => 'that recording is longer than one line can be'], 413);
}

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}

$bytes = (string)file_get_contents((string)$f['tmp_name']);
[$status, $body] = xeric_hear($w, $sid, $bytes, (string)($f['type'] ?? ''), $h);
unset($bytes);
if ($status === 429 && (int)($body['retry_after'] ?? 0) > 0) header('Retry-After: ' . (int)$body['retry_after']);
xeric_web_json($body, $status);
