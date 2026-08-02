<?php
/**
 * llm.php — the model adapter. One function every other part of Xeric calls.
 *
 * Xeric never talks to a specific vendor. It talks to an ENDPOINT DESCRIPTOR:
 *
 *   ['kind' => 'openai',    'base' => 'https://api.openai.com/v1',
 *    'model' => 'gpt-…',    'key' => 'sk-…']
 *   ['kind' => 'anthropic', 'base' => 'https://api.anthropic.com',
 *    'model' => 'claude-…', 'key' => 'sk-ant-…']
 *   ['kind' => 'local',     'base' => 'http://127.0.0.1:18080',
 *    'model' => 'whatever the server has loaded']     ← llama.cpp / ollama / vLLM
 *
 * 'local' and 'openai' are the same wire format (/v1/chat/completions); they
 * differ only in whether a key is sent. Anthropic gets its own shape because
 * its API puts the system prompt in a top-level field.
 *
 * DESIGN CONSTRAINTS, all learned the hard way:
 *  • The FORGE may run on a frontier model the user pays for, while daily life
 *    runs on a local 12B. Same call site, different descriptor — nothing above
 *    this file may care which.
 *  • A local model is slow. Timeouts are generous and configurable, and a
 *    caller that wants progress passes an on_idle callback.
 *  • JSON is how the forge gets structure back, and small models are bad at
 *    it. xeric_llm_json() owns the retry-with-the-parse-error dance so no
 *    caller reimplements it.
 *  • Keys never get logged. Errors carry status + body, never the Authorization
 *    header.
 */

const XERIC_LLM_TIMEOUT = 600;   // a 12B writing a world pass is not fast

/**
 * One chat completion. Returns the assistant's text.
 *
 * @param array  $endpoint  descriptor (see above)
 * @param array  $messages  [['role'=>'system'|'user'|'assistant','content'=>string], …]
 * @param array  $opts      temperature, max_tokens, stop, timeout, json (bool)
 * @param array|null $usage OUT: token counts as the endpoint reported them, [] if
 *                          it reported none. Optional and by reference so that
 *                          every existing three-argument call site is unaffected —
 *                          a caller that wants to show "1,400 tokens, 8s" should
 *                          not have to unwrap a whole response envelope for it.
 * @throws RuntimeException on transport or API error
 */
function xeric_llm_chat(array $endpoint, array $messages, array $opts = [], ?array &$usage = null): string
{
    $kind = strtolower((string)($endpoint['kind'] ?? 'local'));
    $base = rtrim((string)($endpoint['base'] ?? ''), '/');
    $model = (string)($endpoint['model'] ?? '');
    $key = (string)($endpoint['key'] ?? '');
    $timeout = (int)($opts['timeout'] ?? XERIC_LLM_TIMEOUT);

    if ($base === '') throw new RuntimeException('llm: endpoint has no base url');

    if ($kind === 'anthropic') {
        // Anthropic hoists the system prompt out of the message list.
        $system = '';
        $rest = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system .= ($system === '' ? '' : "\n\n") . (string)$m['content'];
            } else {
                $rest[] = ['role' => $m['role'], 'content' => (string)$m['content']];
            }
        }
        $payload = [
            'model' => $model ?: 'claude-sonnet-5',
            'max_tokens' => (int)($opts['max_tokens'] ?? 2048),
            'messages' => $rest,
        ];
        if ($system !== '') $payload['system'] = $system;
        if (isset($opts['temperature'])) $payload['temperature'] = (float)$opts['temperature'];
        $res = xeric_llm_post("$base/v1/messages", $payload, [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ], $timeout);
        $usage = xeric_llm_usage($res);
        xeric_llm_tally($usage, $base);
        foreach (($res['content'] ?? []) as $blk) {
            if (($blk['type'] ?? '') === 'text') return (string)$blk['text'];
        }
        throw new RuntimeException('llm: anthropic returned no text block');
    }

    // openai-compatible: hosted OpenAI, OpenRouter, llama.cpp, ollama, vLLM…
    $payload = [
        'model' => $model ?: 'local',
        'messages' => array_map(
            fn($m) => ['role' => (string)$m['role'], 'content' => (string)$m['content']],
            $messages
        ),
    ];
    if (isset($opts['temperature'])) $payload['temperature'] = (float)$opts['temperature'];
    if (isset($opts['max_tokens'])) $payload['max_tokens'] = (int)$opts['max_tokens'];
    if (!empty($opts['stop'])) $payload['stop'] = $opts['stop'];
    // Ask for JSON when the caller wants structure. llama.cpp honours
    // response_format on recent builds; when it doesn't, the prompt and the
    // repair loop in xeric_llm_json() still carry it.
    if (!empty($opts['json'])) $payload['response_format'] = ['type' => 'json_object'];

    $headers = [];
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
    $res = xeric_llm_post("$base/v1/chat/completions", $payload, $headers, $timeout);
    $usage = xeric_llm_usage($res);
    xeric_llm_tally($usage, $base);
    $txt = $res['choices'][0]['message']['content'] ?? null;
    if (!is_string($txt)) throw new RuntimeException('llm: no completion in response');
    return $txt;
}

/**
 * Token counts, in one shape, from either wire format. [] when none were sent —
 * a zero would claim the call was free, which is a different statement.
 */
/**
 * Every token this process has spent, and a place to send them as they land.
 *
 * A COUNTER INSIDE llm.php RATHER THAN A PARAMETER THREADED THROUGH TWELVE CALL
 * SITES. `xeric_llm_chat()` already reported usage to whoever asked, and almost
 * nobody asked — `xeric_llm_json()`, which is what the forge's passes and every
 * sweep go through, had no way to report it at all. Adding an out param to both
 * and plumbing it up through chat.php, sweeps.php, story.php, proactive.php and
 * forge.php would touch every one of those files and still miss the next caller
 * somebody writes. Both functions here do the HTTP, so both simply count.
 *
 * WHERE the tokens went travels with them. A total is a number; a total per
 * machine is the answer to "is the free one carrying this or am I paying for
 * it", which is the only question anybody actually asks of a meter.
 *
 * @param array|null $add   a xeric_llm_usage() shape, or null to just read
 * @param string     $where the endpoint base they were spent at
 * @return array{in:int,out:int,calls:int}
 */
function xeric_llm_tally(?array $add = null, string $where = ''): array
{
    static $t = ['in' => 0, 'out' => 0, 'calls' => 0];

    if ($add !== null && $add !== []) {
        $t['in']  += (int)($add['prompt_tokens'] ?? 0);
        $t['out'] += (int)($add['completion_tokens'] ?? 0);
        $t['calls']++;

        // A sink that throws must not take the answer down with it. Whatever it
        // is doing — writing a counter, touching a session — is bookkeeping, and
        // bookkeeping does not get to fail a completion the model already spent
        // the tokens on and returned.
        $sink = xeric_llm_meter();
        if ($sink !== null) {
            try { $sink($add, $where); } catch (Throwable $e) { }
        }
    }
    return $t;
}

/**
 * Where the tally goes as it accumulates, for a caller that wants it kept.
 *
 * The engine holds no state across requests and is not about to start; a web
 * layer that wants a running total registers a function here and does its own
 * storing. Pass a callable to set one, false to clear, nothing to read.
 */
function xeric_llm_meter($sink = null): ?callable
{
    static $fn = null;
    if ($sink === false)         $fn = null;
    elseif (is_callable($sink))  $fn = $sink;
    return $fn;
}

function xeric_llm_usage(array $res): array
{
    $u = (array)($res['usage'] ?? []);
    if ($u === []) return [];
    $in  = (int)($u['prompt_tokens']     ?? $u['input_tokens']  ?? 0);
    $out = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? 0);
    return [
        'prompt_tokens'     => $in,
        'completion_tokens' => $out,
        'total_tokens'      => (int)($u['total_tokens'] ?? ($in + $out)),
    ];
}

/**
 * A completion that MUST return a JSON object.
 *
 * Small models fail this constantly — fenced blocks, a preamble, a trailing
 * apology, a truncated brace. So: strip the usual wrappers, and on a real
 * parse failure hand the model its own broken output plus the parser's
 * complaint and ask for the object again. One repair attempt by default,
 * because a model that fails twice is not going to succeed on the third try
 * and the caller has a deterministic fallback for exactly this.
 *
 * @param callable|null $onNote  optional progress sink: fn(string $note)
 * @return array  decoded object
 * @throws RuntimeException when even the repair round fails
 */
function xeric_llm_json(array $endpoint, array $messages, array $opts = [], ?callable $onNote = null): array
{
    // The same test seam xeric_forge_ask() and xeric_chat_ask() carry, for the
    // same reason: the callers that reach a model through THIS function rather
    // than through one of those two — the walls pass, the protagonist pass, the
    // armed-systems pass — were untestable without it, and they are the passes
    // that decide who is allowed to know what.
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
        $out = ($endpoint['stub'])((string)($opts['tag'] ?? 'json'), $messages, $opts);
        if (!is_array($out)) throw new RuntimeException('llm: stub returned no object');
        return $out;
    }

    $tries = max(1, (int)($opts['repairs'] ?? 1) + 1);
    $raw = '';
    $err = '';
    for ($i = 0; $i < $tries; $i++) {
        if ($i > 0) {
            $onNote && $onNote("model returned unparseable JSON, asking it to repair (attempt " . ($i + 1) . ")");
            $messages[] = ['role' => 'assistant', 'content' => mb_substr($raw, 0, 4000)];
            $messages[] = ['role' => 'user', 'content' =>
                "That was not valid JSON ({$err}). Return ONLY the JSON object, "
                . "no prose, no code fences, no explanation. Start with { and end with }."];
        }
        $raw = xeric_llm_chat($endpoint, $messages, ['json' => true] + $opts);
        $decoded = xeric_llm_decode($raw, $err);
        if ($decoded !== null) return $decoded;
    }
    throw new RuntimeException('llm: could not get valid JSON (' . $err . ')');
}

/** Tolerant decode: fences, preambles, trailing prose. Returns null on failure. */
function xeric_llm_decode(string $raw, ?string &$err = null): ?array
{
    $s = trim($raw);
    // ```json … ``` or ``` … ```
    if (preg_match('/^```[a-z]*\s*(.+?)\s*```$/is', $s, $m)) $s = trim($m[1]);
    // a preamble before the object ("Here is the world: { … }")
    if ($s !== '' && $s[0] !== '{' && $s[0] !== '[') {
        $p = strpos($s, '{');
        $b = strpos($s, '[');
        $cut = ($p === false) ? $b : (($b === false) ? $p : min($p, $b));
        if ($cut !== false) $s = substr($s, $cut);
    }
    // trailing prose after the object — walk back to the last closer
    $lastObj = strrpos($s, '}');
    $lastArr = strrpos($s, ']');
    $end = max($lastObj === false ? -1 : $lastObj, $lastArr === false ? -1 : $lastArr);
    if ($end >= 0 && $end < strlen($s) - 1) $s = substr($s, 0, $end + 1);

    $d = json_decode($s, true);
    if (is_array($d)) { $err = null; return $d; }
    $err = json_last_error_msg();
    return null;
}

/**
 * Raw POST with JSON body. Never logs credentials.
 *
 * `follow_location => 0` is load-bearing, not tidiness. xeric_web_endpoint()
 * resolves a bring-your-own-key base URL and refuses private address space, but
 * PHP's http:// wrapper follows a 302 without asking anybody, so a public host
 * answering `Location: http://127.0.0.1:8080/` would walk a validated endpoint
 * straight back into the loopback the check exists to keep it out of. A model
 * API does not redirect its own POST body; anything that does is not one.
 */
function xeric_llm_post(string $url, array $payload, array $headers, int $timeout): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hdr = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $hdr),
        'content' => $body,
        'timeout' => $timeout,
        'ignore_errors' => true,   // read 4xx/5xx bodies instead of throwing blind
        'follow_location' => 0,
        'max_redirects' => 1,      // 1 means "this request and no hop"
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) {
        $e = error_get_last()['message'] ?? 'unknown transport error';
        // strip anything header-ish out of the message; keys live in headers
        throw new RuntimeException('llm: cannot reach ' . parse_url($url, PHP_URL_HOST) . ' (' . $e . ')');
    }
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    $decoded = json_decode($out, true);
    if ($status >= 400) {
        $msg = $decoded['error']['message'] ?? $decoded['message'] ?? mb_substr($out, 0, 300);
        throw new RuntimeException("llm: HTTP $status, $msg");
    }
    if (!is_array($decoded)) throw new RuntimeException('llm: non-JSON response: ' . mb_substr($out, 0, 200));
    return $decoded;
}

/**
 * Is this endpoint answering right now? Cheap, never throws.
 *
 * "Answering" means answering like a model API: 2xx with a JSON body. It used
 * to mean any HTTP response at all, which made this a port scanner somebody
 * could drive through the bring-your-own-key box — point it at a host, read
 * true/false, learn what is listening. A closed port and an nginx 404 are both
 * "no model here" and there is no reason for the caller to tell them apart.
 */
function xeric_llm_up(array $endpoint, int $timeout = 5): bool
{
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) return true;

    $base = rtrim((string)($endpoint['base'] ?? ''), '/');
    if ($base === '') return false;
    $kind = strtolower((string)($endpoint['kind'] ?? 'local'));
    $url = $kind === 'anthropic' ? "$base/v1/models" : "$base/v1/models";
    $headers = ['Accept: application/json'];
    $key = (string)($endpoint['key'] ?? '');
    if ($key !== '') {
        $headers[] = $kind === 'anthropic'
            ? 'x-api-key: ' . $key . "\r\nanthropic-version: 2023-06-01"
            : 'Authorization: Bearer ' . $key;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $headers),
        'timeout' => $timeout, 'ignore_errors' => true,
        'follow_location' => 0, 'max_redirects' => 1,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) return false;

    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int)$m[1]; break; }
    }
    if ($status < 200 || $status >= 300) return false;
    return is_array(json_decode($out, true));
}
