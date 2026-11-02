<?php
/**
 * Xeric — ears. The player, out loud.
 *
 * voice.php gave the cast a voice; this gives the player one going the other
 * way. Somebody holds a button, says a line, lets go, and the line comes back
 * as text in the box they would have typed it into. They read it, fix it if
 * the machine misheard, and press send — so THE TRANSCRIPT IS A SAY, AND
 * NOTHING ELSE CHANGES: no new kind of message, no new path into a turn, no
 * rating decision of its own. Everything a typed line passes through, a
 * spoken one passes through, because by the time it reaches say.php it IS a
 * typed line. Until a machine exists the whole feature is dormant and costs
 * nothing: no machine, no button, no errors.
 *
 * ONE WIRE, NO VENDOR. The transcription call every OpenAI-compatible server
 * answers — LocalAI, Speaches, parakeet.cpp's server, whisper.cpp's (with
 * --inference-path /v1/audio/transcriptions), and the hosted ones — is a
 * multipart POST {base}/v1/audio/transcriptions with `file`, `model`,
 * `response_format` and, optionally, `language` and `prompt`, answered with
 * {"text": "..."}. That is the only thing this file ever asks a machine
 * (xeric_ears_transcribe).
 *
 * THE ROOM'S NAMES ARE THE PROMPT. Whisper's `prompt` is read as the text
 * that came BEFORE the audio — a context, not an instruction — and the one
 * thing it reliably does is bias spelling toward words it has just "seen".
 * A town full of people called Dot, Harlan and Ruth Okonkwo-Bell is a town
 * Whisper would otherwise write as "dot", "Harlem" and "Ruth Oconco Bell".
 * So the caller passes the names of the people present where the player
 * stands, plus whoever the thread is with ($opts['names']), and they ride as
 * a comma-separated prompt. Names only — observables a body in the doorway
 * would know — never a wall, never a plot, never anything the machine could
 * turn around and put into the player's mouth.
 *
 * THE RECORDING IS NEVER STORED. The bytes arrive, go to the machine, and are
 * dropped with the request. No row, no file, no cache, no fingerprint: the
 * same sentence said twice is two different recordings of somebody's voice,
 * and keeping either would be keeping something nobody asked us to keep.
 * That is also why there is no table in this file.
 *
 * WHAT IS SENT IS WHAT IT SAYS IT IS. The browser names a type; the first
 * bytes are checked against it (xeric_ears_sniff) before anything is spent,
 * and a mismatch is refused. A WAV's own header also says how long it is, and
 * one longer than XERIC_EARS_MAX_SECONDS is refused before it is sent. The
 * other containers a MediaRecorder makes — WebM, Ogg, MP4 — do not say their
 * length cheaply (a live WebM recording usually carries no duration at all),
 * so for those the 25 MB ceiling and the page's own 30-second stop are the
 * fence, and no parser is written here to pretend otherwise.
 *
 * WHISPER HEARS THINGS IN SILENCE. Trained on subtitled video, it fills a
 * quiet clip with what subtitles say when nobody is talking: "Thank you.",
 * "Thanks for watching!", "you", whisper.cpp's "[BLANK_AUDIO]". A transcript
 * that is ONLY one of those (xeric_ears_hallucination) is dropped to '' —
 * the cost is that a player who genuinely said just "thank you" gets an empty
 * box and types it, which is a much smaller wrong than a line they never said
 * appearing in the box under their own name.
 *
 * THE KEY IS NEVER STORED. XERIC_EARS_KEY lives in the environment (or in
 * whatever the web layer's resolver reads it from) and nowhere else: not on
 * the meter, not in an error message. It passes llm.php's header filter on
 * the way out.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/voice.php';    // the cap, the refusal's words; photo.php's cost bit and probe; llm.php's header filter

/** The most audio this engine will forward, in bytes: the OpenAI audio API's own ceiling. */
const XERIC_EARS_MAX_BYTES = 25 * 1024 * 1024;

/** The longest line, in seconds, where the container says (a WAV does; see the header). */
const XERIC_EARS_MAX_SECONDS = 30;

/** The longest transcript handed back, in characters — the same budget a spoken line has. */
const XERIC_EARS_MAX_CHARS = 1200;

/** The containers a browser's MediaRecorder makes, or a file a person might hand over. */
const XERIC_EARS_TYPES = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/wav', 'audio/mpeg'];

/**
 * What Whisper writes over silence, normalized (xeric_ears_norm: lower case,
 * no punctuation, single spaces). Dropped only when it is the WHOLE
 * transcript. Kept short on purpose: every entry is a sentence a person can
 * no longer say on its own, so only the well-documented ones are here —
 * the subtitle sign-offs, the lone "you", the music and silence tags.
 */
const XERIC_EARS_HALLUCINATIONS = [
    'you', 'thank you', 'thanks', 'thank you very much', 'thank you so much',
    'thanks for watching', 'thank you for watching', 'thanks for listening',
    'thank you for listening', 'please subscribe', 'like and subscribe',
    'subtitles by the amaraorg community', 'bye', 'music', 'silence', 'blank audio',
];

// ---------------------------------------------------------------------------
// The machine
// ---------------------------------------------------------------------------

/**
 * The ears machine, if this install has one. Null is dormant, and dormant is
 * the shipping state and never an error.
 *
 * Read in order: the web layer's resolver (the machines screen, read at CALL
 * time; false means "explicitly none" and stops here), then the environment —
 * XERIC_EARS_BASE, XERIC_EARS_KEY, XERIC_EARS_MODEL (default whisper-1, the
 * name every compatible server answers to) and XERIC_EARS_LANGUAGE (an ISO
 * code; empty is "let the machine tell"). Nothing stands in past that: a
 * voice machine that happens to serve transcriptions is still not a machine
 * anybody chose to listen with.
 */
function xeric_ears_endpoint(): ?array
{
    $fn = xeric_ears_source();
    if ($fn !== null) {
        $ep = $fn();
        if (is_array($ep) && trim((string)($ep['base'] ?? '')) !== '') return $ep;
        if (is_array($ep) && isset($ep['stub']) && is_callable($ep['stub'])) return $ep;
        if ($ep === false) return null;   // the resolver says: explicitly none
    }
    $base = trim((string)(getenv('XERIC_EARS_BASE') ?: ''));
    if ($base === '') return null;
    return ['base' => $base, 'key' => (string)(getenv('XERIC_EARS_KEY') ?: '')]
         + xeric_ears_settings(['model'    => (string)(getenv('XERIC_EARS_MODEL') ?: ''),
                                'language' => (string)(getenv('XERIC_EARS_LANGUAGE') ?: '')]);
}

/** The resolver seam, set once by the web layer, like xeric_voice_source. */
function xeric_ears_source($fn = null): ?callable
{
    static $src = null;
    if ($fn !== null) $src = $fn;
    return $src;
}

/**
 * The two knobs a request takes from its machine, defaulted and checked: the
 * model (whisper-1 when nobody named one) and the language (a language tag,
 * or '' — a typo is "auto", never an error that costs somebody their ears).
 *
 * @return array{model:string,language:string}
 */
function xeric_ears_settings(array $ep): array
{
    $model = trim((string)($ep['model'] ?? ''));
    $lang  = xeric_voice_lang_tag((string)($ep['language'] ?? ''));
    return ['model' => $model !== '' ? $model : 'whisper-1', 'language' => $lang];
}

/** IS the ears machine answering? xeric_voice_up's probe, for listening. A stub is always up. */
function xeric_ears_up(?array $ep = null, int $timeout = 3): bool
{
    $ep ??= xeric_ears_endpoint();
    if ($ep === null) return false;
    if (isset($ep['stub']) && is_callable($ep['stub'])) return true;
    if (trim((string)($ep['base'] ?? '')) === '') return false;
    return xeric_image_up(['key' => xeric_llm_header_safe((string)($ep['key'] ?? ''))] + $ep, $timeout);
}

/** Does listening here spend money? xeric_image_costly's one bit, for ears. */
function xeric_ears_costly(?array $ep = null): bool
{
    $ep ??= xeric_ears_endpoint();
    if ($ep === null) return false;
    return xeric_image_costly($ep);
}

/**
 * The listening spend's sink. Each transcription is handed over as
 * (['hearings' => 1, 'seconds' => n] + usage, base|'stub'); seconds are what
 * a hosted transcriber bills by, taken from the machine's own usage when it
 * reports one, else from the container when it says, else 0.
 */
function xeric_ears_meter($sink = null): ?callable
{
    static $fn = null;
    if ($sink !== null) $fn = $sink;
    return $fn;
}

// ---------------------------------------------------------------------------
// The bytes
// ---------------------------------------------------------------------------

/**
 * A type as a browser said it, in one spelling, or '' when it is not one of
 * XERIC_EARS_TYPES. Parameters go (audio/webm;codecs=opus is audio/webm);
 * the video/ spellings a MediaRecorder sometimes uses for an audio-only
 * recording fold to audio/; the common aliases fold.
 */
function xeric_ears_type(string $said): string
{
    $m = strtolower(trim(explode(';', $said)[0]));
    $m = match ($m) {
        'video/webm'                                              => 'audio/webm',
        'video/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac'      => 'audio/mp4',
        'video/ogg', 'audio/opus', 'application/ogg'              => 'audio/ogg',
        'audio/x-wav', 'audio/wave', 'audio/vnd.wave'             => 'audio/wav',
        'audio/mp3', 'audio/mpeg3', 'audio/x-mpeg', 'audio/x-mp3' => 'audio/mpeg',
        default                                                   => $m,
    };
    return in_array($m, XERIC_EARS_TYPES, true) ? $m : '';
}

/**
 * What the first bytes say a recording is, or ''. A COPY of play-lib.php's
 * xeric_play_audio_sniff, kept here because the engine never reaches up into
 * the web layer; the rules are the same five magic numbers, so the two
 * cannot disagree about what a sound file looks like.
 */
function xeric_ears_sniff(string $head): string
{
    if (str_starts_with($head, 'ID3')) return 'audio/mpeg';
    if (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) return 'audio/mpeg';
    if (str_starts_with($head, 'OggS')) return 'audio/ogg';
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') return 'audio/wav';
    if (str_starts_with($head, "\x1A\x45\xDF\xA3")) return 'audio/webm';
    if (substr($head, 4, 4) === 'ftyp') return 'audio/mp4';
    return '';
}

/**
 * How long a recording is, in seconds, when that is cheap to know — a WAV's
 * fmt chunk gives the byte rate and its data chunk the length — else null.
 * Nothing else is parsed; see the header for why.
 */
function xeric_ears_seconds(string $bytes, string $type): ?float
{
    if ($type !== 'audio/wav' || strlen($bytes) < 44) return null;
    $rate = 0;
    $at   = 12;
    $n    = strlen($bytes);
    for ($i = 0; $i < 32 && $at + 8 <= $n; $i++) {
        $id   = substr($bytes, $at, 4);
        $size = unpack('V', substr($bytes, $at + 4, 4))[1];
        if ($id === 'fmt ' && $at + 20 <= $n) $rate = unpack('V', substr($bytes, $at + 16, 4))[1];
        if ($id === 'data') return $rate > 0 ? $size / $rate : null;
        $at += 8 + $size + ($size & 1);
    }
    return null;
}

/** The file name the multipart body gives a recording, from its type: servers read the extension. */
function xeric_ears_filename(string $type): string
{
    return match ($type) {
        'audio/ogg'  => 'audio.ogg',
        'audio/mp4'  => 'audio.mp4',
        'audio/wav'  => 'audio.wav',
        'audio/mpeg' => 'audio.mp3',
        default      => 'audio.webm',
    };
}

// ---------------------------------------------------------------------------
// The words
// ---------------------------------------------------------------------------

/** Lower case, letters, digits and single spaces: the shape the hallucination list is written in. */
function xeric_ears_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/** Is this whole transcript something Whisper says over silence? '' counts. */
function xeric_ears_hallucination(string $text): bool
{
    $n = xeric_ears_norm(str_replace('_', ' ', $text));
    if ($n === '') return true;
    return in_array($n, XERIC_EARS_HALLUCINATIONS, true);
}

/**
 * A transcript as the player's composer receives it: whisper.cpp's bracketed
 * tags ([BLANK_AUDIO], [MUSIC], (silence)) taken out wherever they stand,
 * control characters gone, whitespace folded, a silence hallucination
 * dropped to '', and the rest capped at XERIC_EARS_MAX_CHARS on a sentence
 * (xeric_voice_cap: the same cut a spoken line gets). PURE.
 */
function xeric_ears_clean(string $text): string
{
    $t = mb_scrub($text, 'UTF-8');
    $t = (string)preg_replace('/\[(?:[A-Z_ ]+|music|silence|inaudible|blank_audio)\]|\((?:silence|music|inaudible)\)/iu', ' ', $t);
    $t = (string)preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $t);
    $t = trim((string)preg_replace('/\s+/u', ' ', $t));
    if (xeric_ears_hallucination($t)) return '';
    return xeric_voice_cap($t, XERIC_EARS_MAX_CHARS);
}

/** The names, as Whisper's prompt: distinct, clean, comma-joined, short. */
function xeric_ears_prompt(array $names): string
{
    $out  = [];
    $seen = [];
    foreach ($names as $n) {
        if (!is_string($n)) continue;
        $n = trim((string)preg_replace('/[\p{C}]+/u', ' ', mb_scrub($n, 'UTF-8')));
        if ($n === '' || mb_strlen($n) > 80 || isset($seen[mb_strtolower($n)])) continue;
        $seen[mb_strtolower($n)] = true;
        $out[] = $n;
    }
    // Whisper reads at most 224 prompt tokens; a few hundred characters of
    // names is well inside that, and a longer list is a room, not a scene.
    $s = implode(', ', $out);
    return mb_strlen($s) > 600 ? mb_substr($s, 0, (int)mb_strrpos(mb_substr($s, 0, 600), ',')) : $s;
}

// ---------------------------------------------------------------------------
// The call
// ---------------------------------------------------------------------------

/**
 * Transcribe one recording. THE ADAPTER — the one function that asks a
 * machine to listen. $type is a type from XERIC_EARS_TYPES; $opts takes
 * `names` (string[], the prompt — see the header) and `language` (overrides
 * the machine's).
 *
 * A stub endpoint is called ('transcribe', $req) — $req is the multipart
 * fields as a map plus `bytes` and `filename` — and answers {text, usage?}:
 * the tests' seam. The live path POSTs multipart to
 * {base}/v1/audio/transcriptions with Bearer auth; a non-2xx is a refusal and
 * its own words ride on the error; a 2xx whose body has no `text` is an error
 * page wearing a success code. Throws on nothing usable — including a
 * machine that did not answer, which the wrapper (xeric_ears_hear) has
 * already ruled out by asking whether it is up.
 *
 * @return array{text:string,usage:array,meta:array}
 */
function xeric_ears_transcribe(array $ep, string $bytes, string $type, array $opts = []): array
{
    $set  = xeric_ears_settings($ep);
    $lang = xeric_voice_lang_tag((string)($opts['language'] ?? '')) ?: $set['language'];
    $fields = ['model' => $set['model'], 'response_format' => 'json'];
    if ($lang !== '') $fields['language'] = $lang;
    $prompt = xeric_ears_prompt((array)($opts['names'] ?? []));
    if ($prompt !== '') $fields['prompt'] = $prompt;
    $file = xeric_ears_filename($type);
    $meta = ['model' => $set['model'], 'language' => $lang, 'prompt' => $prompt, 'filename' => $file];

    if (isset($ep['stub']) && is_callable($ep['stub'])) {
        $out = ($ep['stub'])('transcribe', $fields + ['bytes' => $bytes, 'filename' => $file, 'mime' => $type]);
        if (!is_array($out) || !is_string($out['text'] ?? null)) {
            throw new RuntimeException('ears: the stub returned no text');
        }
        return ['text' => $out['text'], 'usage' => (array)($out['usage'] ?? []), 'meta' => $meta];
    }

    $base = rtrim(trim((string)($ep['base'] ?? '')), '/');
    if ($base === '') throw new RuntimeException('ears: no ears machine is configured');
    $r = xeric_ears_http($base . '/v1/audio/transcriptions', $ep, $fields, $bytes, $file, $type,
                         (int)($ep['timeout'] ?? 60));
    if ($r === null) throw new RuntimeException('ears: the ears machine did not answer');
    if ($r['status'] < 200 || $r['status'] >= 300) {
        throw new RuntimeException('ears: the ears machine said HTTP ' . $r['status'] . xeric_voice_why($r['body']));
    }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || !is_string($j['text'] ?? null)) {
        throw new RuntimeException('ears: the ears machine answered without a transcript' . xeric_voice_why($r['body']));
    }
    $usage = is_array($j['usage'] ?? null) ? $j['usage'] : [];
    if (!isset($usage['seconds']) && is_numeric($j['duration'] ?? null)) $usage['seconds'] = (float)$j['duration'];
    return ['text' => $j['text'], 'usage' => $usage, 'meta' => $meta];
}

/**
 * One multipart POST, never a throw. Null when nothing answered at all. The
 * key is filtered for line breaks and travels as a Bearer token; redirects
 * are not followed (llm.php's reason).
 *
 * @return ?array{status:int,body:string}
 */
function xeric_ears_http(string $url, array $ep, array $fields, string $bytes, string $file, string $type,
                         int $timeout): ?array
{
    $b    = '----xeric' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n"
               . str_replace(["\r", "\n"], ' ', (string)$v) . "\r\n";
    }
    $body .= "--$b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$file\"\r\n"
           . "Content-Type: $type\r\n\r\n" . $bytes . "\r\n--$b--\r\n";

    $headers = ['Content-Type: multipart/form-data; boundary=' . $b, 'Accept: application/json'];
    $key = xeric_llm_header_safe((string)($ep['key'] ?? ''));
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
    $http = ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
             'timeout' => max(1, $timeout), 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 1];
    $got  = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    $hdrs = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    if (!is_string($got)) return null;
    $status = 0;
    foreach ((array)$hdrs as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $m)) $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => $got];
}

/**
 * Hear one line: the whole path a recording takes, cheapest refusals first.
 *
 *   1. the bytes. None is a 400; more than XERIC_EARS_MAX_BYTES is a 413;
 *   2. the type. One this file does not take, or first bytes that disagree
 *      with the type claimed, is a 415 — before anything is spent;
 *   3. the length, where the container says (a WAV): past
 *      XERIC_EARS_MAX_SECONDS is a 413;
 *   4. the machine. None, or one configured but not answering, is null —
 *      dormant, never an error;
 *   5. the call (xeric_ears_transcribe; a refusal throws in its own words,
 *      with no code), the meter, and the words (xeric_ears_clean).
 *
 * Refusals in 1–3 throw a RuntimeException whose code is the HTTP status the
 * web layer answers with. Nothing is kept: the bytes go out of scope with
 * the call.
 *
 * @return ?array{text:string,seconds:float,meta:array} text may be '' (silence, or Whisper's idea of it)
 */
function xeric_ears_hear(string $bytes, string $mime, array $opts = [], ?array $ep = null): ?array
{
    if ($bytes === '') throw new RuntimeException('ears: there was nothing in the recording', 400);
    if (strlen($bytes) > XERIC_EARS_MAX_BYTES) {
        throw new RuntimeException('ears: that recording is longer than one line can be', 413);
    }
    $type = xeric_ears_type($mime);
    if ($type === '') throw new RuntimeException('ears: that is not a kind of recording this can hear', 415);
    if (xeric_ears_sniff(substr($bytes, 0, 16)) !== $type) {
        throw new RuntimeException('ears: the recording is not what it says it is', 415);
    }
    $secs = xeric_ears_seconds($bytes, $type);
    if ($secs !== null && $secs > XERIC_EARS_MAX_SECONDS + 0.5) {
        throw new RuntimeException('ears: that recording is longer than ' . XERIC_EARS_MAX_SECONDS . ' seconds', 413);
    }

    $ep ??= xeric_ears_endpoint();
    if ($ep === null || !xeric_ears_up($ep)) return null;

    $r = xeric_ears_transcribe($ep, $bytes, $type, $opts);
    $seconds = is_numeric($r['usage']['seconds'] ?? null) ? (float)$r['usage']['seconds'] : (float)($secs ?? 0);

    if (($fn = xeric_ears_meter()) !== null) {
        // A sink that throws must not take the line down with it: the
        // machine has already listened. llm.php's rule, for hearing.
        try {
            $fn(['hearings' => 1, 'seconds' => $seconds] + $r['usage'], (string)($ep['base'] ?? 'stub'));
        } catch (Throwable $e) {
        }
    }
    return ['text' => xeric_ears_clean($r['text']), 'seconds' => $seconds, 'meta' => $r['meta']];
}
