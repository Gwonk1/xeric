<?php
/**
 * Xeric — voices. The cast, out loud.
 *
 * A HOLODECK TALKS. Everything else this engine makes is read: a thread, a
 * caption standing where a photograph would, a room described for a machine
 * that can draw it. This file gives every person in the world a voice of their
 * own on whatever text-to-speech machine the owner chose, and turns one of
 * their lines into audio — the same voice on Tuesday as on Monday, and the same
 * line costing one render however many times it is played. Until a machine
 * exists the whole feature is dormant and costs nothing: no machine, no clips,
 * no errors.
 *
 * ONE WIRE, NO VENDOR. The speech call every OpenAI-compatible server already
 * answers — Kokoro-FastAPI, openedai-speech, LocalAI, and the hosted ones — is
 * POST {base}/v1/audio/speech {model, input, voice, response_format, speed},
 * answered with raw audio and a Content-Type. That is the only thing this file
 * ever asks a machine to DO (xeric_voice_render), and its inventory is the only
 * thing it ever asks a machine to SAY (xeric_voice_list). No vendor's name
 * decides anything below; the few that appear are named as examples of shapes.
 *
 * THE WRITTEN VOICE IS NOT THE SPOKEN ONE. A character's `voice` is prose — a
 * style guide the model writes to ("short sentences, warm ones, and a long
 * pause before the sentence that actually matters"). It is not read here, not
 * once. A timbre guessed from adjectives would be the engine deciding what
 * "warm" sounds like, and it would be the same guess for everybody who was ever
 * described that way. The spoken voice is an ID on a machine, and there are
 * exactly two ways to come by one:
 *
 *   - THE OWNER NAMES IT. `speech.voice` on a character (or a fixture) names a
 *     voice on the machine, by its name THERE — "nova", "af_bella", a Kokoro
 *     blend like "af_bella+af_sky". It wins whenever the machine has it, and
 *     whenever the machine cannot say what it has. `speech.speed` rides beside
 *     it, clamped to 0.7–1.3, because past either end a voice stops sounding
 *     like a person talking and starts sounding like a tape doing something.
 *   - A SEED DEALS IT. Otherwise the voice is derived, never stored, from
 *     crc32(world|voice|handle) — xeric_photo_seed, the engine's one seed — so
 *     the same person draws the same voice on every call, on every surface,
 *     with no row, no sidecar and no migration for two of them to disagree in.
 *
 * NOTHING ABOUT A PERSON PICKS THEIR VOICE BUT THEIR NAME. Not their pronouns,
 * not their age, not the prose `voice`, and not the gender labels a machine's
 * inventory may carry (or spell into its very names — Kokoro's af_ and am_).
 * The engine writes no gendered prose of its own: pronouns are template data,
 * said where the template says them, and choosing a timbre FROM them would be
 * the same act in a different medium — the engine deciding what a "she" sounds
 * like, and deciding it identically for every she in every world. A seed
 * cannot be wrong about anybody in a way that says something about them. An
 * owner who cares picks by name, and the labels ride along on the inventory so
 * the picker can show them to the OWNER; an owner who does not care gets a fair
 * deal. The one thing the deal reads is the WORLD's language (meta.language),
 * because an English line handed to a Japanese voice is not casting, it is
 * noise — and a language belongs to the world, not to the person.
 *
 * WHAT IS SPOKEN IS WHAT WAS SHOWN. The words are the line already in the
 * thread, less what a voice cannot say: stage directions, markdown, links, the
 * photo caption that stands where a picture would (xeric_voice_text). The
 * rating decision was made when the line was written — chat.php's gates, the
 * age floor, the world's effective rating — and the speech layer adds nothing
 * to it, takes nothing from it, and has no rating of its own. That is why there
 * is no minor check in this file: a voice reading words that were already
 * allowed cannot make them less allowed, and the deal reads no age to have an
 * opinion with.
 *
 * MODEL PROPOSES, CODE DISPOSES — and here no model is even asked. Which voice,
 * which words, which file: code, from canon and the machine's own inventory.
 *
 * NO QUEUE, AND NO OFFER. Photos wait behind the conversation and ask once
 * before the first render because a forge enqueues a whole cast's worth of
 * pictures nobody individually asked for. A clip is asked for by somebody
 * pressing play on one line; it is seconds of audio and they are waiting, so
 * it renders now or not at all. Choosing the machine was the consent, exactly
 * as it is for scenes, and xeric_voice_costly() is the one bit every surface
 * that offers to speak branches its cost warning on.
 *
 * KEYED BY WHAT IT SOUNDS LIKE. One render per fingerprint — sha1 of the voice,
 * the speed, the model, the format and the spoken words, and of nothing else
 * (xeric_voice_fingerprint). Two messages that both say "Morning." in the same
 * voice share one render and one file; each still gets its own row, so a
 * thread can ask after any message by id (xeric_voice_of).
 *
 * THE KEY IS NEVER STORED. XERIC_VOICE_KEY lives in the environment (or in
 * whatever the web layer's resolver reads it from) and nowhere else: not in a
 * row, not in the fingerprint, not on the meter, not in an error message.
 *
 * THE TABLE LIVES WITH ITS ONLY READER. voice_clips is created here, by
 * xeric_voice_schema(), idempotently, at the top of every function that touches
 * it — so a database written by any older build needs nothing run against it
 * first, and a world grows the table the first time anything asks after a
 * voice. state.php's discipline holds all the same: CREATE IF NOT EXISTS, and
 * never a DROP.
 *
 * PHP 8.1+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';    // the cast, the fixtures; walls.php's xeric_text rides in with it
require_once __DIR__ . '/photo.php';    // the one seed, the one cost bit, the one liveness probe
require_once __DIR__ . '/state.php';    // the clock rows are stamped with
require_once __DIR__ . '/llm.php';      // the one header-safe key

/**
 * The deal when a machine cannot say what it has: the six voices every
 * OpenAI-compatible server answers to.
 *
 * OpenAI's own list is longer now — ash, ballad, coral, sage, verse — but the
 * newer names depend on the model even on OpenAI's own API, and the
 * compatibility layers that map OpenAI names onto voices of their own were
 * written against these six. A dealt voice the machine refuses is a person who
 * never speaks, every single time, because the deal does not change; a voice
 * shared with a neighbour is only a voice shared. The owner who wants the
 * newer ones names them, per character, in `speech.voice`.
 */
const XERIC_VOICE_FALLBACK = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];

/** The formats the speech API names that this engine will keep on disk. */
const XERIC_VOICE_FORMATS = ['mp3', 'opus', 'wav'];

/**
 * How much of one line is ever spoken, in characters. A line longer than this
 * is a speech, not a line, and on a metered machine it bills like one. The cut
 * lands on a sentence (xeric_voice_cap): somebody who stopped talking, not a
 * recording that broke.
 */
const XERIC_VOICE_MAX_CHARS = 1200;

/** The speed range a person still sounds like a person inside. */
const XERIC_VOICE_SPEED_MIN = 0.7;
const XERIC_VOICE_SPEED_MAX = 1.3;

// ---------------------------------------------------------------------------
// The machine
// ---------------------------------------------------------------------------

/**
 * The voice machine, if this install has one. Null is dormant, and dormant is
 * the shipping state and never an error.
 *
 * Read in order: the web layer's resolver (the machines screen, read at CALL
 * time so a worker under a forced session hears that session's choice; false
 * means "explicitly none" and stops here), then the environment —
 * XERIC_VOICE_BASE, XERIC_VOICE_KEY, XERIC_VOICE_MODEL (default tts-1, the name
 * every compatible server answers to) and XERIC_VOICE_FORMAT (mp3, opus or wav;
 * default mp3). Nothing stands in past that, where scene.php would fall back to
 * the image machine: a picture machine cannot speak, and a model machine that
 * happens to serve /v1/audio/speech is still not a machine anybody chose for it.
 */
function xeric_voice_endpoint(): ?array
{
    $fn = xeric_voice_source();
    if ($fn !== null) {
        $ep = $fn();
        if (is_array($ep) && trim((string)($ep['base'] ?? '')) !== '') return $ep;
        if (is_array($ep) && isset($ep['stub']) && is_callable($ep['stub'])) return $ep;
        if ($ep === false) return null;   // the resolver says: explicitly none
    }
    $base = trim((string)(getenv('XERIC_VOICE_BASE') ?: ''));
    if ($base === '') return null;
    return ['base' => $base, 'key' => (string)(getenv('XERIC_VOICE_KEY') ?: '')]
         + xeric_voice_settings(['model'  => (string)(getenv('XERIC_VOICE_MODEL') ?: ''),
                                 'format' => (string)(getenv('XERIC_VOICE_FORMAT') ?: '')]);
}

/** The resolver seam, set once by the web layer, like xeric_scene_source. */
function xeric_voice_source($fn = null): ?callable
{
    static $src = null;
    if ($fn !== null) $src = $fn;
    return $src;
}

/**
 * The two knobs a request takes from its machine, defaulted and checked: the
 * model (tts-1 when nobody named one) and the format (mp3 unless it is one of
 * the three this engine keeps). An unknown format is the default, not an error
 * — a typo in an environment variable should not cost anybody their voice —
 * and both are read here and only here, so the request that is sent and the
 * fingerprint it is filed under cannot disagree about what was asked for.
 *
 * @return array{model:string,format:string}
 */
function xeric_voice_settings(array $ep): array
{
    $model  = trim((string)($ep['model'] ?? ''));
    $format = strtolower(trim((string)($ep['format'] ?? '')));
    return ['model'  => $model !== '' ? $model : 'tts-1',
            'format' => in_array($format, XERIC_VOICE_FORMATS, true) ? $format : 'mp3'];
}

/**
 * IS the voice machine answering? The probe xeric_image_up makes — a GET on the
 * base, a short timeout, never a throw — because a machine that is configured
 * but switched off should read as "no voices here", not as a play button that
 * breaks. A stub is always up. The key passes llm.php's header filter on the
 * way, because it may have come out of a browser.
 */
function xeric_voice_up(?array $ep = null, int $timeout = 3): bool
{
    $ep ??= xeric_voice_endpoint();
    if ($ep === null) return false;
    if (isset($ep['stub']) && is_callable($ep['stub'])) return true;
    if (trim((string)($ep['base'] ?? '')) === '') return false;
    return xeric_image_up(['key' => xeric_llm_header_safe((string)($ep['key'] ?? ''))] + $ep, $timeout);
}

/** Does speaking here spend money? xeric_image_costly's one bit, for voices. */
function xeric_voice_costly(?array $ep = null): bool
{
    $ep ??= xeric_voice_endpoint();
    if ($ep === null) return false;
    return xeric_image_costly($ep);
}

/**
 * The machine's voice inventory, read off the machine.
 *
 * Asked in two places, in order: GET {base}/v1/audio/voices (Kokoro-FastAPI
 * answers {"voices": [...]}) and GET {base}/v1/voices (the other common
 * spelling, objects with ids and labels). The first non-empty answer is the
 * inventory. [] when neither answers usefully — and [] is not a failure: a
 * machine with no listable voices still speaks, and the deal falls back to the
 * six names every compatible server knows (XERIC_VOICE_FALLBACK). A machine
 * that does not answer the first question at all is not asked the second.
 *
 * A stub is asked ('voices', []) and whatever it hands back is parsed the way
 * a wire answer would be; a stub that knows nothing about voices lists none.
 *
 * @return array<int,array{id:string,name:string,gender:string,labels:array,language:string}>
 */
function xeric_voice_list(array $ep, int $timeout = 5): array
{
    if (isset($ep['stub']) && is_callable($ep['stub'])) {
        try {
            return xeric_voice_parse_list(($ep['stub'])('voices', []));
        } catch (Throwable $e) {
            return [];
        }
    }
    $base = rtrim(trim((string)($ep['base'] ?? '')), '/');
    if ($base === '') return [];
    foreach (['/v1/audio/voices', '/v1/voices'] as $path) {
        $r = xeric_voice_http('GET', $base . $path, $ep, null, $timeout);
        if ($r === null) return [];                       // nothing there to ask twice
        if ($r['status'] < 200 || $r['status'] >= 300) continue;
        $list = xeric_voice_parse_list($r['body']);
        if ($list !== []) return $list;
    }
    return [];
}

/**
 * An inventory, in whatever shape a machine chose, as one shape. PURE, so the
 * shapes are testable without a server.
 *
 * Accepted: a JSON string or the decoded value; a bare list or one wrapped in
 * {"voices": …} or {"data": …}; a map of id => details; entries that are
 * strings, or objects naming themselves by voice_id, id, name or voice (in that
 * order — where a machine gives both, voice_id/id is what goes on the wire and
 * name is what a person reads). Duplicates, blanks, control characters and
 * anything that is not a voice are dropped; garbage is [].
 *
 * Carried for the OWNER's picker, never for the deal: `gender` and `labels`
 * when the machine supplied them (nothing is inferred into them). Carried for
 * the deal: `language`, from the entry's own language field when it has one,
 * else from the first letter of a Kokoro-shaped id — Kokoro names its voices
 * <language><mark>_<name> (af_bella: American English), and that one letter is
 * the only part of anybody's name this engine reads.
 *
 * @return array<int,array{id:string,name:string,gender:string,labels:array,language:string}>
 */
function xeric_voice_parse_list($json): array
{
    if (is_string($json)) $json = json_decode($json, true);
    if (!is_array($json)) return [];

    if (!array_is_list($json)) {
        $inner = $json['voices'] ?? $json['data'] ?? null;
        if (!is_array($inner)) return [];
        if (!array_is_list($inner)) {
            // A map of id => details (or id => display name).
            $rows = [];
            foreach ($inner as $k => $v) {
                if (is_array($v)) $rows[] = $v + ['id' => (string)$k];
                elseif (is_string($v)) $rows[] = ['id' => (string)$k, 'name' => $v];
            }
            $inner = $rows;
        }
        $json = $inner;
    }

    $str = fn($v): string => (is_string($v) || is_int($v)) ? trim((string)$v) : '';
    $out  = [];
    $seen = [];
    foreach ($json as $v) {
        if (is_string($v) || is_int($v)) $v = ['id' => (string)$v];
        if (!is_array($v)) continue;

        $id = '';
        foreach (['voice_id', 'id', 'name', 'voice'] as $k) {
            $id = $str($v[$k] ?? null);
            if ($id !== '') break;
        }
        if ($id === '' || isset($seen[$id]) || !mb_check_encoding($id, 'UTF-8')
                || mb_strlen($id) > 128 || preg_match('/\p{C}/u', $id)) {
            continue;
        }
        $seen[$id] = true;

        $labels = [];
        $raw = $v['labels'] ?? [];
        foreach (is_array($raw) ? $raw : [$raw] as $lk => $lv) {
            if (is_scalar($lv) && trim((string)$lv) !== '') $labels[$lk] = trim((string)$lv);
        }
        $lang = '';
        foreach (['language', 'lang', 'lang_code', 'locale'] as $k) {
            $lang = $str($v[$k] ?? null);
            if ($lang !== '') break;
        }
        if ($lang === '') $lang = (string)($labels['language'] ?? '');

        $name = $str($v['name'] ?? null);
        $out[] = [
            'id'       => $id,
            'name'     => $name !== '' ? $name : $id,
            'gender'   => strtolower($str($v['gender'] ?? null) ?: (string)($labels['gender'] ?? '')),
            'labels'   => $labels,
            'language' => xeric_voice_lang_tag($lang) ?: xeric_voice_kokoro_lang($id),
        ];
    }
    return $out;
}

/** 'en-US', 'en_us', 'EN' → 'en-us', 'en'; anything that is not a language tag → ''. */
function xeric_voice_lang_tag(string $s): string
{
    $s = strtolower(str_replace('_', '-', trim($s)));
    return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $s) ? $s : '';
}

/**
 * The language a Kokoro-shaped voice id spells in its first letter, or ''. The
 * second letter is Kokoro's own mark and is not read: the shape is matched,
 * the language is taken, and nothing downstream ever sees the rest.
 */
function xeric_voice_kokoro_lang(string $id): string
{
    static $lang = ['a' => 'en-us', 'b' => 'en-gb', 'e' => 'es', 'f' => 'fr-fr', 'h' => 'hi',
                    'i' => 'it', 'j' => 'ja', 'p' => 'pt-br', 'z' => 'zh'];
    return preg_match('/^([abefhijpz])[a-z]_[a-z0-9]+$/', $id, $m) ? $lang[$m[1]] : '';
}

/**
 * One HTTP round trip to the machine, never a throw. Null when nothing
 * answered at all; otherwise the status, the content type and the body, so the
 * caller can tell "refused" from "answered" from "absent". The key is filtered
 * for line breaks and travels as a Bearer token; redirects are not followed,
 * for llm.php's reason (a validated address must not be walked somewhere else
 * by a Location header).
 *
 * @return ?array{status:int,type:string,body:string}
 */
function xeric_voice_http(string $method, string $url, array $ep, ?array $json, int $timeout): ?array
{
    $headers = [$json !== null ? 'Content-Type: application/json' : 'Accept: application/json'];
    $key = xeric_llm_header_safe((string)($ep['key'] ?? ''));
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
    $http = ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => max(1, $timeout),
             'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 1];
    if ($json !== null) {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                  | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($body)) return null;
        $http['content'] = $body;
    }
    $got  = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    $hdrs = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    if (!is_string($got)) return null;

    $status = 0;
    $type   = '';
    foreach ((array)$hdrs as $h) {
        $h = (string)$h;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int)$m[1]; $type = ''; }
        elseif (stripos($h, 'content-type:') === 0)     { $type = trim(substr($h, 13)); }
    }
    return ['status' => $status, 'type' => $type, 'body' => $got];
}

/** What a refusing machine said about itself, as a short clause for an error. */
function xeric_voice_why(string $body): string
{
    $j   = json_decode($body, true);
    $s   = fn($v): string => is_string($v) ? $v : '';
    $msg = '';
    if (is_array($j)) {
        $e   = $j['error'] ?? null;
        $msg = is_array($e) ? $s($e['message'] ?? null) : $s($e);
        if ($msg === '') $msg = $s($j['detail'] ?? null);
        if ($msg === '' && is_array($j['detail'] ?? null)) {
            $d   = $j['detail'];
            $msg = $s($d['message'] ?? null) ?: (is_array($d[0] ?? null) ? $s($d[0]['msg'] ?? null) : '');
        }
        if ($msg === '') $msg = $s($j['message'] ?? null);
    }
    $msg = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($msg, 'UTF-8')));
    return $msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '';
}

// ---------------------------------------------------------------------------
// Who sounds like whom
// ---------------------------------------------------------------------------

/**
 * Who a handle is, for the purpose of sounding like somebody: the character,
 * or the fixture — and a fixture that IS somebody (same_as, the two-Harlans
 * case) is that somebody, so the man behind the register speaks in Harlan's
 * voice and never in a stranger's. Null for a handle nobody answers to.
 *
 * @return ?array{self:string,node:array} self is whose seed the voice is dealt from
 */
function xeric_voice_person(array $t, string $handle): ?array
{
    if (trim($handle) === '') return null;
    $c = xeric_world_character($t, $handle);
    if ($c !== null) return ['self' => $handle, 'node' => $c];
    $f = xeric_world_fixture($t, $handle);
    if ($f === null) return null;
    $same = (string)($f['same_as'] ?? '');
    $sc   = $same !== '' ? xeric_world_character($t, $same) : null;
    return $sc !== null ? ['self' => $same, 'node' => $sc] : ['self' => $handle, 'node' => $f];
}

/**
 * WHICH voice this person has — the identity every clip of them shares.
 *
 * $voices is the machine's inventory, in any shape xeric_voice_parse_list
 * reads (xeric_voice_list's answer, or plain ids); [] is a machine that cannot
 * say. The owner's `speech.voice` wins when the machine has it — matched
 * exactly, then without regard to case (an owner who wrote "Nova" meant nova),
 * and a Kokoro-style blend when every voice in it is on the machine — or when
 * the inventory is empty, because then the owner knows the machine better than
 * the engine can. Otherwise the seed deals (xeric_voice_deal) from the voices
 * that speak the world's language, falling back to the whole inventory when
 * none is known to, and to XERIC_VOICE_FALLBACK when there is no inventory.
 * `chosen` says which of the two happened, so a picker can tell an owner whose
 * pick did not take.
 *
 * Read from the person: their handle (through the seed) and their `speech`
 * block. Nothing else — see the header for why pronouns, age, the prose voice
 * and the inventory's gender labels are not among them. Null for a handle
 * nobody answers to: fail closed, like every resolver in the engine.
 *
 * @return ?array{handle:string,name:string,voice:string,speed:float,seed:int,chosen:bool}
 */
function xeric_voice_identity(array $t, string $handle, array $voices): ?array
{
    $who = xeric_voice_person($t, $handle);
    if ($who === null) return null;

    $speech = is_array($who['node']['speech'] ?? null) ? $who['node']['speech'] : [];
    $seed   = xeric_photo_seed($t, 'voice', $who['self']);
    $list   = xeric_voice_parse_list($voices);

    $voice  = xeric_voice_chosen(is_string($speech['voice'] ?? null) ? $speech['voice'] : '', $list);
    $chosen = $voice !== null;
    $voice ??= xeric_voice_deal($seed, xeric_voice_pool($t, $list));

    return [
        'handle' => $handle,
        'name'   => xeric_world_name($t, $handle) ?: $handle,
        'voice'  => $voice,
        'speed'  => xeric_voice_speed($speech['speed'] ?? null),
        'seed'   => $seed,
        'chosen' => $chosen,
    ];
}

/**
 * The owner's pick as the machine spells it, or null when it does not take.
 * Exact first, then case-blind; then, for a blend (Kokoro's "a+b" and
 * "a(2)+b(1)"), every part on its own terms, weights kept as written.
 */
function xeric_voice_chosen(string $want, array $list): ?string
{
    $want = trim($want);
    if ($want === '' || !mb_check_encoding($want, 'UTF-8') || mb_strlen($want) > 200
            || preg_match('/\p{C}/u', $want)) {
        return null;
    }
    if ($list === []) return $want;   // the machine cannot say what it has; the owner can

    $ids   = array_column($list, 'id');
    $spell = function (string $name) use ($ids): ?string {
        if (in_array($name, $ids, true)) return $name;
        foreach ($ids as $id) {
            if (mb_strtolower($id) === mb_strtolower($name)) return $id;
        }
        return null;
    };
    if (($one = $spell($want)) !== null) return $one;

    if (!str_contains($want, '+')) return null;
    $parts = [];
    foreach (explode('+', $want) as $part) {
        if (!preg_match('/^\s*(.+?)\s*(\(\s*\d+(?:\.\d+)?\s*\))?\s*$/u', $part, $m)) return null;
        $name = $spell($m[1]);
        if ($name === null) return null;
        $parts[] = $name . (isset($m[2]) ? (string)preg_replace('/\s+/', '', $m[2]) : '');
    }
    return implode('+', $parts);
}

/**
 * The voices the deal draws from: the inventory's voices in the world's
 * language (meta.language, en when unsaid) or in no stated language, else the
 * whole inventory — something speaking beats nothing — else the fallback six.
 *
 * @return string[]
 */
function xeric_voice_pool(array $t, array $list): array
{
    if ($list === []) return XERIC_VOICE_FALLBACK;
    $want = explode('-', xeric_voice_lang_tag((string)($t['meta']['language'] ?? '')) ?: 'en')[0];
    $fits = [];
    foreach ($list as $v) {
        $lang = explode('-', (string)$v['language'])[0];
        if ($lang === '' || $lang === $want) $fits[] = (string)$v['id'];
    }
    return $fits !== [] ? $fits : array_column($list, 'id');
}

/**
 * THE DEAL. Every voice in the pool is scored against the person's seed and
 * the highest score wins — rendezvous hashing rather than seed % count, for
 * the one reason that matters in a world whose product is continuity. A modulo
 * re-deals EVERYBODY the day the machine gains or loses a single voice (Kokoro
 * ships voices in point releases; an owner installs a pack), and the whole
 * town wakes up sounding like strangers. Highest-score moves only the people
 * whose best is the new voice — about one in N of them — and taking a voice
 * away moves only the people who had it. It is blind, too, to the ORDER a
 * machine lists its voices in, which no API promises to keep. Ties, which sha1
 * makes theoretical, go to the lower name, so even they are deterministic.
 */
function xeric_voice_deal(int $seed, array $pool): string
{
    $best = '';
    $top  = -1;
    foreach ($pool as $v) {
        $v = (string)$v;
        if ($v === '') continue;
        $score = (int)hexdec(substr(sha1($seed . '|' . $v), 0, 12));
        if ($score > $top || ($score === $top && strcmp($v, $best) < 0)) {
            $best = $v;
            $top  = $score;
        }
    }
    return $best !== '' ? $best : XERIC_VOICE_FALLBACK[0];
}

/** `speech.speed`, read: a number (or a numeric string), clamped; 1.0 otherwise. */
function xeric_voice_speed($v): float
{
    if (is_string($v) && is_numeric(trim($v))) $v = (float)trim($v);
    if (!is_int($v) && !is_float($v)) return 1.0;
    $f = (float)$v;
    if (!is_finite($f)) return 1.0;
    return round(max(XERIC_VOICE_SPEED_MIN, min(XERIC_VOICE_SPEED_MAX, $f)), 2);
}

// ---------------------------------------------------------------------------
// What is said
// ---------------------------------------------------------------------------

/**
 * What gets SPOKEN out of a message: the words, and nothing a voice cannot say.
 * PURE and deterministic — the result is part of the fingerprint, so the same
 * line must clean to the same bytes forever.
 *
 * Out, in this order:
 *
 *   - the PHOTO CAPTION: a line that begins with the camera ("📷 Ruth by the
 *     urn", the way say.php stores a photo in a thread) or with "photo:" (the
 *     way photo.php describes one), and a [photo: …] marker anywhere. A
 *     caption is a picture's stand-in; reading it aloud would be a narrator
 *     nobody hired.
 *   - a (parenthesis) or a [bracket] that is a WHOLE LINE on its own. One
 *     inside a line is left alone and spoken: "I saw Walt (the deacon) at the
 *     store" is a person talking, and so, in a text message, is "See you at
 *     six. (Bring the pie.)" — parentheses are how people write asides, and
 *     chat.php's cleaner already took the ones that wrapped a whole reply.
 *   - links. A markdown link keeps its words; a bare address is not speech.
 *   - MARKDOWN EMPHASIS, markers only: **bold**, __this__, _this_, ~~this~~,
 *     `this` all keep their words.
 *   - a *single-asterisk span* that STANDS WHERE A SENTENCE WOULD — at the
 *     start of a line or after a full stop, and followed by the end, a new
 *     line, or a new sentence. That is a stage direction in every chat dialect
 *     this cast was ever trained on (duet.php met "*I polish the compass*"
 *     between every sentence on its first day). A span INSIDE a sentence is
 *     emphasis and keeps its words, and so does one followed by a lowercase
 *     word ("*Not* now."): the rule leans toward speaking, for chat.php's own
 *     reason — reading out a stray "nods" is a hiccup, but dropping a "not"
 *     says the opposite of what she said.
 *   - pictographs. A heart is not a word, and a voice reading "red heart" is
 *     worse than one that says nothing.
 *
 * Then whitespace collapses, and the result is capped at XERIC_VOICE_MAX_CHARS
 * on a sentence boundary. A line with no letter or digit left in it — "*nods*",
 * "(she's typing)", "..." — is '' and is never sent anywhere.
 */
function xeric_voice_text(string $raw): string
{
    $s = str_replace(["\r\n", "\r"], "\n", mb_scrub($raw, 'UTF-8'));

    // Whole lines that are not speech: the caption, and a parenthesis or a
    // bracket (or a row of them) standing on a line of its own.
    $keep = [];
    foreach (explode("\n", $s) as $line) {
        $l = trim($line);
        if ($l !== '' && (preg_match('/^(?:\x{1F4F7}|\[?\s*photo\s*:)/iu', $l)
                || preg_match('/^(?:\((?:[^()]|\([^()]*\))*\)\s*)+$/u', $l)
                || preg_match('/^(?:\[(?:[^\[\]]|\[[^\[\]]*\])*\]\s*)+$/u', $l))) {
            continue;
        }
        $keep[] = $line;
    }
    $s = implode("\n", $keep);

    $s = (string)preg_replace('/\[\s*photo\s*:[^\]]*\]/iu', ' ', $s);
    $s = (string)preg_replace('/\[([^\]\n]+)\]\(\s*[^)\s]+\s*\)/u', '$1', $s);
    $s = (string)preg_replace('~<?(?:https?://|www\.)[^\s<>"]*[^\s<>".,;:!?\'")\]’”]>?~iu', ' ', $s);

    // Emphasis: the words stay, the markers go.
    $s = (string)preg_replace('/(\*\*\*|\*\*|__|~~)(?=\S)([^\n]+?)(?<=\S)\1/u', '$2', $s);
    $s = (string)preg_replace('/`+([^`\n]*)`+/u', '$1', $s);
    $s = (string)preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)([^_\n]+?)(?<=\S)_(?![\p{L}\p{N}_])/u', '$1', $s);

    // A single-asterisk span: a stage direction where a sentence would stand,
    // emphasis anywhere else. Judged against the text as it stands before any
    // span is touched, with a span's own end counting as a boundary for the
    // next one, so "*sighs* *sets the cup down* Fine." loses both.
    $subject = $s;
    $s = (string)preg_replace_callback('/\*([^*\n]{1,400})\*([.!?…]*)/u',
        function (array $m) use ($subject): string {
            [$whole, $at] = $m[0];
            $before = rtrim(substr($subject, 0, $at), " \t");
            $after  = substr($subject, $at + strlen($whole));
            $opens  = $before === '' || str_ends_with($before, "\n")
                   || preg_match('/[.!?…*]["\'”’)\]]*$/u', $before) === 1;
            $closes = $m[2][0] !== '' || preg_match('/^[ \t]*(?:\n|$|[^\p{Ll}\s])/u', $after) === 1;
            return ($opens && $closes) ? ' ' : $m[1][0] . $m[2][0];
        }, $s, -1, $n, PREG_OFFSET_CAPTURE);

    $s = str_replace('*', '', $s);   // whatever is left of an asterisk was never a pair
    $s = (string)preg_replace('/[\p{Extended_Pictographic}\x{1F3FB}-\x{1F3FF}\x{1F1E6}-\x{1F1FF}'
                            . '\x{FE0E}\x{FE0F}\x{200D}\x{20E3}]/u', '', $s);

    // Whitespace collapsed, and the seams closed: a space left before a stop,
    // a colon left in front of one ("Look: <link>." is "Look."), and whatever
    // punctuation a removal left at the very front.
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    $s = (string)preg_replace('/ (?=[.,;:!?…])/u', '', $s);
    $s = (string)preg_replace('/[,;:]+(?=[.!?…])/u', '', $s);
    $s = trim((string)preg_replace('/^[\s.,;:!?…]+/u', '', $s));
    if (!preg_match('/[\p{L}\p{N}]/u', $s)) return '';
    return xeric_voice_cap($s, XERIC_VOICE_MAX_CHARS);
}

/**
 * At most $max characters, cut after the last sentence that fits — any
 * sentence, however early, because a clip that ends on a full stop sounds like
 * somebody who stopped and one that ends mid-clause sounds broken. Only a first
 * sentence longer than the whole budget is cut at a word instead.
 */
function xeric_voice_cap(string $s, int $max = XERIC_VOICE_MAX_CHARS): string
{
    if ($max <= 0 || mb_strlen($s) <= $max) return $s;
    $limit = strlen(mb_substr($s, 0, $max));   // the budget, in bytes
    $cut   = 0;
    if (preg_match_all('/[.!?…]+["\'”’)\]]*(?= |$)/u', $s, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$stop, $at]) {
            $end = $at + strlen($stop);
            if ($end > $limit) break;
            $cut = $end;
        }
    }
    if ($cut > 0) return rtrim(substr($s, 0, $cut));
    $head = mb_substr($s, 0, $max);
    $sp   = mb_strrpos($head, ' ');
    return rtrim($sp !== false && $sp > 0 ? mb_substr($head, 0, $sp) : $head);
}

// ---------------------------------------------------------------------------
// The clip
// ---------------------------------------------------------------------------

/**
 * The table, if it is not there yet. Idempotent and cheap; every function that
 * reads or writes voice_clips calls it first.
 *
 * One row per (message, fingerprint): a message's clip in one voice. The FILE
 * is shared — two messages that say the same words in the same voice point at
 * one render — but the rows are not, because a thread asks after its messages
 * by id. message_id needs no foreign key: messages.id is AUTOINCREMENT, so an
 * id a rewind took away is never handed to a new message, and a row left
 * behind by one can never be mistaken for anybody else's clip.
 */
function xeric_voice_schema(PDO $db): void
{
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS voice_clips (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            handle      TEXT    NOT NULL,                  -- who said it
            message_id  INTEGER NOT NULL,                  -- messages.id the clip speaks
            fingerprint TEXT    NOT NULL,                  -- sha1 of what it sounds like
            file        TEXT    NOT NULL,                  -- relative to the world's clips dir
            mime        TEXT    NOT NULL,                  -- what the machine said it made
            created_at  INTEGER NOT NULL,
            UNIQUE(message_id, fingerprint)
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_clips_fp ON voice_clips(fingerprint)');
}

/**
 * What a clip SOUNDS like, hashed: the voice, the speed, the model, the format
 * and the spoken words. Nothing else is in it — not the person (two people
 * dealt the same voice saying the same words make the same sound), not the
 * machine's address (moving a machine to another port re-renders nothing),
 * and never the key.
 */
function xeric_voice_fingerprint(string $voice, float $speed, string $model, string $format, string $text): string
{
    return sha1(implode('|', [$voice, number_format($speed, 2, '.', ''), $model, $format, $text]));
}

/**
 * Render one line. THE ADAPTER — the one function that asks a machine to
 * speak, llm.php's discipline applied to sound. $req is the wire body:
 * {model, input, voice, response_format, speed}.
 *
 * A stub endpoint is called ('speech', $req) and answers {bytes, mime, usage?}
 * — the tests' seam. The live path POSTs {base}/v1/audio/speech and takes the
 * body as the audio, but only after the machine has said so: a non-2xx status
 * is a refusal (its own words ride on the error — "voice not found" is the one
 * an owner needs to read), and a 200 whose Content-Type is JSON or HTML is an
 * error page wearing a success code, never an mp3. Throws on nothing usable.
 *
 * @return array{bytes:string,mime:string,usage:array}
 */
function xeric_voice_render(array $ep, array $req): array
{
    $format = in_array((string)($req['response_format'] ?? ''), XERIC_VOICE_FORMATS, true)
        ? (string)$req['response_format'] : 'mp3';
    $body = [
        'model'           => (string)($req['model'] ?? 'tts-1'),
        'input'           => (string)($req['input'] ?? ''),
        'voice'           => (string)($req['voice'] ?? ''),
        'response_format' => $format,
        'speed'           => (float)($req['speed'] ?? 1.0),
    ];

    if (isset($ep['stub']) && is_callable($ep['stub'])) {
        $out = ($ep['stub'])('speech', $body);
        if (!is_array($out) || !is_string($out['bytes'] ?? null) || $out['bytes'] === '') {
            throw new RuntimeException('voice: the stub returned no audio');
        }
        $mime = xeric_voice_mime((string)($out['mime'] ?? ''), $format);
        if (!str_starts_with($mime, 'audio/')) {
            throw new RuntimeException('voice: the stub answered with ' . $mime . ', not audio');
        }
        return ['bytes' => $out['bytes'], 'mime' => $mime, 'usage' => (array)($out['usage'] ?? [])];
    }

    $base = rtrim(trim((string)($ep['base'] ?? '')), '/');
    if ($base === '') throw new RuntimeException('voice: no voice machine is configured');
    $r = xeric_voice_http('POST', $base . '/v1/audio/speech', $ep, $body, (int)($ep['timeout'] ?? 120));
    if ($r === null) throw new RuntimeException('voice: the voice machine did not answer');
    if ($r['status'] < 200 || $r['status'] >= 300) {
        throw new RuntimeException('voice: the voice machine said HTTP ' . $r['status'] . xeric_voice_why($r['body']));
    }
    $mime = xeric_voice_mime($r['type'], $format);
    if (!str_starts_with($mime, 'audio/')) {
        throw new RuntimeException('voice: the voice machine answered with ' . $mime . ', not audio'
                                 . xeric_voice_why($r['body']));
    }
    if ($r['body'] === '') throw new RuntimeException('voice: the voice machine answered with no audio');
    return ['bytes' => $r['body'], 'mime' => $mime, 'usage' => []];
}

/**
 * A media type as a machine said it, in one spelling. The common aliases fold
 * (audio/mp3 is audio/mpeg); a machine that said nothing, or only
 * octet-stream, is taken at the format it was asked for; anything else is
 * passed through as said, and the caller refuses what is not audio.
 */
function xeric_voice_mime(string $said, string $format): string
{
    $m = strtolower(trim(explode(';', $said)[0]));
    $m = match ($m) {
        'audio/mp3', 'audio/mpeg3', 'audio/x-mpeg', 'audio/x-mp3' => 'audio/mpeg',
        'audio/x-wav', 'audio/wave', 'audio/vnd.wave'             => 'audio/wav',
        default                                                   => $m,
    };
    if ($m === '' || $m === 'application/octet-stream' || $m === 'binary/octet-stream') {
        return match ($format) { 'opus' => 'audio/ogg', 'wav' => 'audio/wav', default => 'audio/mpeg' };
    }
    return preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $m) ? $m : 'application/octet-stream';
}

/** The file extension for what a clip is; the format asked for when the type says nothing more. */
function xeric_voice_ext(string $mime, string $format): string
{
    return match ($mime) {
        'audio/mpeg' => '.mp3',
        'audio/opus' => '.opus',
        'audio/ogg'  => $format === 'opus' ? '.opus' : '.ogg',
        'audio/wav'  => '.wav',
        'audio/aac'  => '.aac',
        'audio/flac' => '.flac',
        'audio/webm' => '.webm',
        'audio/mp4'  => '.m4a',
        default      => '.' . (in_array($format, XERIC_VOICE_FORMATS, true) ? $format : 'bin'),
    };
}

/**
 * The speech spend's sink — xeric_photo_meter's discipline, for sound. Each
 * render is handed over as (['clips' => 1, 'chars' => n] + usage, base), the
 * characters counted because that is what a hosted voice bills by; a cached
 * clip is not handed over, because it spent nothing.
 */
function xeric_voice_meter($sink = null): ?callable
{
    static $fn = null;
    if ($sink !== null) $fn = $sink;
    return $fn;
}

/**
 * The clip for one message, rendered now or found already made.
 *
 * In order, cheapest first, and each step is a way out:
 *
 *   1. the words (xeric_voice_text). Nothing to say is null, and touches
 *      neither the network nor the machine;
 *   2. the person. A handle nobody answers to is null;
 *   3. the machine. None is null — dormant, never an error;
 *   4. the voice: the identity, from $voices when the caller holds the
 *      inventory (a page voicing a thread should fetch it once and pass it),
 *      else from asking the machine;
 *   5. THE CACHE. A clip with this fingerprint whose file is still on disk is
 *      this message's clip too: no render, no network, and nothing metered;
 *   6. the machine again, asked whether it is answering. A machine that is
 *      configured but off is null, exactly like no machine at all;
 *   7. the render, the file (written aside and renamed into place, so a reader
 *      never finds half a clip), the row, and the meter — in that order, so
 *      nothing is filed that is not on disk, and the clips directory is made
 *      sure of BEFORE anything is paid for.
 *
 * Null is "there is no clip and there was nothing wrong". A machine that
 * answered badly — refused, or sent back something that is not audio — or a
 * disk that would not take the file throws instead: that is a fault, and the
 * caller decides whether the person pressing play hears about it.
 *
 * A machine that is down also cannot say what voices it has, so a caller that
 * did not pass $voices cannot know which clip is current; xeric_voice_of() is
 * how a thread replays what a message was last voiced with in the meantime.
 *
 * @return ?array{file:string,mime:string,cached:bool} file is relative to $clipsDir
 */
function xeric_voice_clip(array $t, PDO $db, string $clipsDir, string $handle, int $messageId, string $raw,
                          ?array $ep = null, ?array $voices = null): ?array
{
    xeric_voice_schema($db);

    $text = xeric_voice_text($raw);
    if ($text === '') return null;
    if (xeric_voice_person($t, $handle) === null) return null;

    $ep ??= xeric_voice_endpoint();
    if ($ep === null) return null;

    $id = xeric_voice_identity($t, $handle, $voices ?? xeric_voice_list($ep));
    if ($id === null) return null;
    $set = xeric_voice_settings($ep);
    $fp  = xeric_voice_fingerprint($id['voice'], $id['speed'], $set['model'], $set['format'], $text);

    $hit = xeric_voice_hit($db, $fp, $clipsDir);
    if ($hit !== null) {
        xeric_voice_file($db, $handle, $messageId, $fp, $hit['file'], $hit['mime']);
        return ['file' => $hit['file'], 'mime' => $hit['mime'], 'cached' => true];
    }

    // THE WORLD'S CEILING, after the cache (a clip already made is free, and
    // stays free) and before anything is asked: out of clips is a refusal in
    // the world's own words, thrown, so the caller can say it to a person.
    xeric_budget_enforce(xeric_budget_allows($t, $db, xeric_clock_now($db, $t), 'clips', 1,
                                             xeric_voice_costly($ep)), 503);
    if (!xeric_voice_up($ep)) return null;
    if (!is_dir($clipsDir) && !@mkdir($clipsDir, 0775, true) && !is_dir($clipsDir)) {
        throw new RuntimeException('voice: the clips directory cannot be created');
    }

    $r = xeric_voice_render($ep, ['model' => $set['model'], 'input' => $text, 'voice' => $id['voice'],
                                  'response_format' => $set['format'], 'speed' => $id['speed']]);
    $file = 'voice-' . preg_replace('/[^a-z0-9_-]+/i', '_', $handle) . '-' . substr($fp, 0, 12)
          . xeric_voice_ext($r['mime'], $set['format']);
    $part = $clipsDir . '/.' . $file . '.' . getmypid() . '.part';
    if (@file_put_contents($part, $r['bytes']) === false || !@rename($part, $clipsDir . '/' . $file)) {
        @unlink($part);
        throw new RuntimeException('voice: the clip could not be written to disk');
    }
    xeric_voice_file($db, $handle, $messageId, $fp, $file, $r['mime']);

    if (($fn = xeric_voice_meter()) !== null) {
        // A sink that throws must not take the clip down with it: the render is
        // already paid for and already on disk. llm.php's rule, for sound.
        try {
            $fn(['clips' => 1, 'chars' => mb_strlen($text)] + $r['usage'], (string)($ep['base'] ?? 'stub'));
        } catch (Throwable $e) {
        }
    }
    return ['file' => $file, 'mime' => $r['mime'], 'cached' => false];
}

/**
 * A finished clip with this fingerprint that is still on disk, newest first.
 * A row whose file has gone (an owner cleared the directory) is no hit — it is
 * rendered again rather than handed out as a link to nothing — and a file name
 * that is not one this file would have written is never trusted to a path.
 *
 * @return ?array{file:string,mime:string}
 */
function xeric_voice_hit(PDO $db, string $fp, string $clipsDir): ?array
{
    $q = $db->prepare('SELECT file, mime FROM voice_clips WHERE fingerprint = ? ORDER BY id DESC');
    $q->execute([$fp]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $file = (string)$row['file'];
        if (preg_match('/^voice-[A-Za-z0-9_-]+\.[a-z0-9]{2,5}$/', $file) && is_file($clipsDir . '/' . $file)) {
            return ['file' => $file, 'mime' => (string)$row['mime']];
        }
    }
    return null;
}

/**
 * File a clip under a message, as the NEWEST thing that message was voiced
 * with. A message re-voiced in a voice it once had (the owner tried another
 * and came back) has its old row replaced rather than left behind the new one,
 * so xeric_voice_of() always answers with the voice the message was last
 * asked for — REPLACE deletes the old row and inserts a fresh id.
 */
function xeric_voice_file(PDO $db, string $handle, int $messageId, string $fp, string $file, string $mime): void
{
    $q = $db->prepare('SELECT fingerprint, file FROM voice_clips WHERE message_id = ? ORDER BY id DESC LIMIT 1');
    $q->execute([$messageId]);
    $top = $q->fetchAll(PDO::FETCH_ASSOC)[0] ?? null;
    if ($top !== null && (string)$top['fingerprint'] === $fp && (string)$top['file'] === $file) return;
    $db->prepare('INSERT OR REPLACE INTO voice_clips (handle, message_id, fingerprint, file, mime, created_at)
                  VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$handle, $messageId, $fp, $file, $mime, xeric_state_time()]);
}

/**
 * The clip row for a message, if it has one: the voice it was last asked for
 * in. What a thread reads to put a play button that plays at once beside a
 * line, and what replays a line while the machine is away.
 */
function xeric_voice_of(PDO $db, int $messageId): ?array
{
    xeric_voice_schema($db);
    $q = $db->prepare('SELECT * FROM voice_clips WHERE message_id = ? ORDER BY id DESC LIMIT 1');
    $q->execute([$messageId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    return $rows ? $rows[0] : null;
}
