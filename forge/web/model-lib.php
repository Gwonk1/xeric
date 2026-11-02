<?php
/**
 * model-lib.php — the machines: which ones answer, who they are, which are wired.
 *
 * PART OF play-lib.php, moved out of it whole: every function here kept its
 * name, signature and body. play-lib.php require_once's this file, so every
 * page that requires play-lib.php has it; requiring this file alone pulls
 * play-lib.php in as well (the two include each other once). The map of which
 * part lives where is at the top of play-lib.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

// ---------------------------------------------------------------------------
// The machines — one list, read by every screen that shows one
// ---------------------------------------------------------------------------
//
// WHO ANSWERS, NOT JUST WHETHER. An address and a green lamp say something is
// there; they do not say it is the thing you meant. Every server in this space
// speaks the same OpenAI wire format, so "127.0.0.1:8080" is llama.cpp or Ollama
// or LM Studio or vLLM with equal plausibility — and the failure that costs an
// afternoon is talking confidently to the wrong one, or to the right one with a
// model loaded that nobody meant to load.
//
// NAMED, NEVER LOGOED. What is printed is a name we choose and a model id the
// server volunteered. No third-party marks are shipped: an app that draws
// somebody else's logo is making a claim about a relationship it does not have,
// and it is one more thing to keep current when a company restyles.
//
// EVERY ONE OF THESE IS BEST-EFFORT AND SHORT. Identification is decoration on
// top of the liveness probe; a server that will not say what it is still works
// perfectly, so nothing here may add a second of latency or throw.

/**
 * The ports people actually run a model on, in likelihood order.
 *
 * Shared with xeric_web_probe_local(), which wants the first one open; this list
 * is also walked in full by the scan. A SHORT LIST ON PURPOSE: every entry costs
 * a quarter-second when nothing is listening, and walking everything above 1024
 * to find a model is a port scan of somebody's own machine on every cold start.
 */
function xeric_model_ports(): array
{
    return [
        11434 => 'Ollama',
        8080  => 'llama.cpp',
        1234  => 'LM Studio',
        18080 => 'a tunnel',
        5000  => 'text-generation-webui',
        8000  => 'vLLM',
        4891  => 'GPT4All',
        11435 => 'Ollama',            // a second Ollama, which is how two models get served at once
        8081  => 'llama.cpp',         // and the same for llama-server
        8082  => 'llama.cpp',
    ];
}

/** Is anything listening there at all? The cheap half of the scan. */
function xeric_model_open(int $port, float $wait = 0.25): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, $wait);
    if ($fp === false) return false;
    fclose($fp);
    return true;
}

/**
 * Everything running on this machine that Xeric could talk to, sorted into what
 * writes and what draws.
 *
 * WHY ALL OF THEM AND NOT THE FIRST. Somebody serving a 26B for prose and a 4B
 * for the fast jobs has two servers up, and a scan that stops at the first tells
 * them they have one machine — so the second gets typed in by hand, from memory,
 * including the port. The list is the thing that makes more than one model an
 * ordinary setup rather than an expert one.
 *
 * ONE PASS OVER THE UNION OF PORTS, and the picture test runs FIRST. This was
 * found the hard way on the author's own machine: stable-diffusion.cpp's
 * `sd-server` answers /v1/models like any chat server, so it was listed as a
 * text model called "sd-cpp-local" — an address that would have been attached,
 * chosen to forge with, and failed three minutes later. Whatever is more
 * specific decides, and /sdapi/v1/sd-models is more specific than being
 * OpenAI-shaped.
 *
 * @param array $have addresses already on the visitor's list, which are skipped
 * @return array{models:array<int,array{base:string,who:array}>,art:array<int,array{base:string,who:array}>}
 */
function xeric_model_scan(array $have = []): array
{
    $seen = [];
    foreach ($have as $b) $seen[rtrim((string)$b, '/')] = true;

    $ports = array_values(array_unique(array_merge(
        array_keys(xeric_model_ports()), xeric_model_art_ports()
    )));
    sort($ports);

    $out = ['models' => [], 'art' => []];
    foreach ($ports as $port) {
        if (!xeric_model_open((int)$port)) continue;
        $base = 'http://127.0.0.1:' . $port;

        // Something is LISTENING, which is not the same as something answering.
        // A dev server, a database, a printer — anything can hold a port, and a
        // list that offered them all as models would be worse than no list.
        $who = xeric_model_who($base, 'local');
        if ($who['name'] === '') continue;

        if (!empty($who['art'])) { $out['art'][] = ['base' => $base, 'who' => $who]; continue; }
        if (isset($seen[$base])) continue;              // already on the list, nothing to offer
        $out['models'][] = ['base' => $base, 'who' => $who];
        $seen[$base] = true;
    }

    // Biggest first, so the list reads in the order somebody would choose from
    // it and xeric_model_best() is simply the top of what is on screen.
    usort($out['models'], fn(array $a, array $b): int =>
        xeric_model_size((string)($b['who']['model'] ?? ''))
        <=> xeric_model_size((string)($a['who']['model'] ?? '')));
    return $out;
}

// ---------------------------------------------------------------------------
// Pictures — found and named, not yet wired to anything
// ---------------------------------------------------------------------------
//
// NOTHING IN THE ENGINE DRAWS ANYTHING YET. This is here because the machines
// screen is where somebody sets up what Xeric can reach, and an image server is
// the same kind of thing as a model server: an address on your own machine that
// something answers at. Finding them now means the day the engine can use one,
// the setup already exists — and it means the screen can be honest that it found
// something rather than silently ignoring it.
//
// The tiles on the shelf are the obvious first customer (they are drawn from a
// seeded palette today), then a face for a character, then a place.

/** Where the picture servers live. */
function xeric_model_art_ports(): array
{
    return [7860, 8188, 9090, 7801, 3000, 5001];
}

/** What kind of picture server is at this address, if any. */
function xeric_model_art_who(string $base): array
{
    $base = rtrim($base, '/');

    // ComfyUI names its own hardware, which is the most useful line on the card.
    $s = xeric_model_peek($base . '/system_stats');
    if ($s !== null && isset($s['devices'])) {
        $d = (string)($s['devices'][0]['name'] ?? '');
        return ['name' => 'ComfyUI', 'model' => xeric_model_trim($d)];
    }

    // Automatic1111 and everything that copied its API: SD.Next, Forge, reForge.
    $m = xeric_model_peek($base . '/sdapi/v1/sd-models');
    if ($m !== null && isset($m[0]['title'])) {
        return ['name' => 'Stable Diffusion (A1111 API)',
                'model' => xeric_model_trim((string)($m[0]['model_name'] ?? $m[0]['title']))];
    }

    $v = xeric_model_peek($base . '/api/v1/app/version');
    if ($v !== null && isset($v['version'])) {
        return ['name' => 'InvokeAI ' . xeric_model_trim((string)$v['version']), 'model' => ''];
    }

    return ['name' => '', 'model' => ''];
}

/** The API behind a public hostname, or '' when it is not one we know. */
function xeric_model_vendor(string $host): string
{
    $host = strtolower($host);
    // Longest-suffix wins, so api.moonshot.cn and moonshot.cn agree.
    $known = [
        'anthropic.com' => 'Claude', 'openai.com' => 'OpenAI', 'azure.com' => 'Azure OpenAI',
        'moonshot.cn' => 'Kimi', 'moonshot.ai' => 'Kimi', 'deepseek.com' => 'DeepSeek',
        'mistral.ai' => 'Mistral', 'groq.com' => 'Groq', 'together.xyz' => 'Together',
        'together.ai' => 'Together', 'openrouter.ai' => 'OpenRouter', 'x.ai' => 'xAI',
        'googleapis.com' => 'Gemini', 'cohere.ai' => 'Cohere', 'cohere.com' => 'Cohere',
        'perplexity.ai' => 'Perplexity', 'fireworks.ai' => 'Fireworks', 'deepinfra.com' => 'DeepInfra',
        'anyscale.com' => 'Anyscale', 'hyperbolic.xyz' => 'Hyperbolic', 'lambdalabs.com' => 'Lambda',
        'novita.ai' => 'Novita', 'cerebras.ai' => 'Cerebras', 'sambanova.ai' => 'SambaNova',
        'nvidia.com' => 'NVIDIA NIM', 'z.ai' => 'Z.ai', 'bigmodel.cn' => 'Zhipu',
        'qwen.ai' => 'Qwen', 'aliyuncs.com' => 'Qwen', 'minimax.chat' => 'MiniMax',
        'baseten.co' => 'Baseten', 'replicate.com' => 'Replicate', 'nebius.com' => 'Nebius',
        'inference.net' => 'Inference.net', 'featherless.ai' => 'Featherless',
    ];
    $best = '';
    foreach ($known as $suffix => $name) {
        if (($host === $suffix || str_ends_with($host, '.' . $suffix))
            && strlen($suffix) > strlen($best === '' ? '' : $best)) {
            $best = $suffix;
        }
    }
    return $best === '' ? '' : $known[$best];
}

/** One short GET, decoded, or null. Never throws, never waits long. */
function xeric_model_peek(string $url, int $timeout = 2, string $key = ''): ?array
{
    $headers = "Accept: application/json\r\n";
    if ($key !== '') {
        $safe = function_exists('xeric_llm_header_safe') ? xeric_llm_header_safe($key) : $key;
        if ($safe !== '') $headers .= 'Authorization: Bearer ' . $safe . "\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => $headers,
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 1,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    if (!is_string($out) || $out === '') return null;
    $j = json_decode($out, true);
    return is_array($j) ? $j : null;
}

/**
 * Does this image machine answer /v1/images/edits — will it take a likeness?
 *
 * THE HEURISTIC, asked once on connect: POST an EMPTY multipart form (no
 * prompt, no image) to the route. A server that has it fails validation
 * before it renders anything — sd-server answers 400 for a missing prompt or
 * image, OpenAI-shaped servers 400 or 422 — while a server without it is a
 * router's 404 (cpp-httplib, a1111's FastAPI) or a 405. So 2xx/400/415/422
 * is "has the route", and anything else — 404, 405, 401 (cannot tell, so
 * no), a closed port — is no. A GET cannot tell the two apart (httplib
 * answers 404 for a GET to a POST-only route) and an OPTIONS is answered by
 * routers that serve nothing on the path, which is why the probe is a POST
 * that is guaranteed to be refused. Never throws, never waits long.
 */
function xeric_model_image_edits(string $base, int $timeout = 3, string $key = ''): bool
{
    $base = rtrim($base, '/');
    if ($base === '') return false;
    $b = '----xericprobe' . bin2hex(random_bytes(6));
    $headers = "Content-Type: multipart/form-data; boundary=$b\r\nAccept: application/json\r\n";
    if ($key !== '') {
        $safe = function_exists('xeric_llm_header_safe') ? xeric_llm_header_safe($key) : $key;
        if ($safe !== '') $headers .= 'Authorization: Bearer ' . $safe . "\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers, 'content' => "--$b--\r\n",
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $http_response_header = [];
    $out = @file_get_contents($base . '/v1/images/edits', false, $ctx);
    if ($out === false) return false;
    $code = preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($http_response_header[0] ?? ''), $m) ? (int)$m[1] : 0;
    return ($code >= 200 && $code < 300) || in_array($code, [400, 415, 422], true);
}

/**
 * Does this model id belong to something that makes PICTURES rather than words?
 *
 * A NAME TEST, BEHIND THE ENDPOINT TESTS AND NOT INSTEAD OF THEM. Endpoint
 * fingerprints are the real answer; this catches the case where a picture or
 * video server exposes nothing but the OpenAI shape and its own model id —
 * which is exactly how stable-diffusion.cpp presents itself, and how a video
 * server dressed as an inference endpoint would present itself too.
 *
 * Wrong in the safe direction: a chat model that trips this is skipped when
 * choosing a default, which costs one click. A video server that does NOT trip
 * it gets attached, and every world stops answering.
 */
function xeric_model_art_id(string $id): bool
{
    $s = strtolower($id);
    foreach (['stable-diffusion', 'stablediffusion', 'sd-cpp', 'sd3', 'sdxl', 'sd15', 'sd-turbo',
              'flux', 'wan2', 'wan-', 'wanx', 'svd', 'animatediff', 'cogvideo', 'hunyuan-video',
              'hunyuanvideo', 'ltx-video', 'ltxvideo', 'mochi', 'zeroscope', 'modelscope',
              'dreamshaper', 'juggernaut', 'realvis', 'pony', 'illustrious', 'noobai',
              'comfy', 'vae', 'controlnet', 'inpaint', 'img2img', 'txt2img', 'text2video',
              'image2video', 'i2v', 't2v', 'upscal', 'esrgan', 'lora'] as $mark) {
        if (str_contains($s, $mark)) return true;
    }
    // "…-diffusion" and bare "diffusion" checkpoints, without catching a chat
    // model that merely mentions it in a fine-tune name.
    return (bool)preg_match('/(^|[^a-z])diffusion([^a-z]|$)/', $s);
}

/**
 * How big is this model, in billions of parameters, read off its name.
 *
 * THE ONLY SIGNAL ANY OF THESE SERVERS ACTUALLY GIVES. None of them report a
 * parameter count; every one of them puts it in the file name, because that is
 * how the files are published. "gemma-4-26B-A4B" is a 26B mixture with 4B
 * active — the TOTAL is what makes it the better writer, so the largest number
 * in the name wins.
 *
 * Returns 0.0 when the name says nothing, which sorts below anything that does
 * without ever beating it.
 */
function xeric_model_size(string $id): float
{
    $s = strtolower($id);

    // "8x7b" is a mixture of eight 7B experts and weighs 56B on the card and in
    // the GPU. Read as one number it is a 7B, which sorts a Mixtral below a
    // 26B it should beat.
    if (preg_match('/(\d+)\s*x\s*(\d+(?:\.\d+)?)\s*b(?![a-z])/', $s, $x)) {
        $total = (float)$x[1] * (float)$x[2];
        if ($total > 0 && $total < 2000) return $total;
    }

    if (!preg_match_all('/(\d+(?:\.\d+)?)\s*b(?![a-z])/i', $s, $m)) return 0.0;

    $best = 0.0;
    foreach ($m[1] as $n) {
        $v = (float)$n;
        if ($v > 0 && $v < 2000 && $v > $best) $best = $v;   // 2000B is past any real model
    }
    return $best;
}

/**
 * The best local model to start with: the biggest one that actually writes.
 *
 * WHY BIGGEST AND WHY NOT THE FIRST OPEN PORT. A person running several servers
 * is usually running one to write with and the others for jobs it would be
 * wasteful to spend it on — and the first port to answer is decided by the port
 * NUMBER, which has nothing to do with any of that. Worse, it put a picture
 * server ahead of a 26B on this machine, purely because 8081 sorts after 8080.
 *
 * @param array $scan the result of xeric_model_scan(), or null to run one
 */
function xeric_model_best(?array $scan = null): string
{
    $scan = $scan ?? xeric_model_scan();
    // The scan already sorts biggest-first, so this is the top of the list a
    // person is looking at — one ordering, not two that can disagree.
    $rows = (array)($scan['models'] ?? []);
    return $rows === [] ? '' : (string)$rows[0]['base'];
}

/** A model id as a person would read it: the file name, not the path to it. */
function xeric_model_trim(string $id): string
{
    $id = trim($id);
    if ($id === '') return '';
    if (str_contains($id, '/') && !preg_match('#^[\w.-]+/[\w.-]+$#', $id)) {
        $id = (string)substr($id, (int)strrpos($id, '/') + 1);   // a path, not an org/model handle
    }
    $id = (string)preg_replace('/\.(gguf|safetensors|bin)$/i', '', $id);
    return mb_substr($id, 0, 48);
}

/**
 * What is at this address: a name for it, and the model it says it has loaded.
 *
 * @return array{name:string,model:string,local:bool,art?:bool,router?:bool,models?:array}
 */
/**
 * The key a PROBE may carry to this address: the operator's, or nothing.
 *
 * XERIC_LOCAL_KEY is llama-server's --api-key on the one machine the operator
 * named in XERIC_LOCAL_BASE, and a loopback box is the operator's own as well.
 * Every other address on the machines screen was typed by a visitor, and the
 * private-network gate lets a public host through on purpose — that is how a
 * hosted API gets added — so a probe that carried the key there would hand the
 * operator's secret to whoever owns the hostname. Pure, so the rule is tested.
 */
function xeric_model_probe_key(string $base): string
{
    if (!function_exists('xeric_llm_default_key') || !function_exists('xeric_llm_default_base')) return '';
    $here = xeric_llm_normalize_base($base);
    if ($here === '') return '';
    $host = strtolower((string)(parse_url($here, PHP_URL_HOST) ?? ''));
    if ($here === xeric_llm_default_base() || in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
        return xeric_llm_default_key();
    }
    return '';
}

function xeric_model_who(string $base, string $kind = ''): array
{
    $base = rtrim(trim($base), '/');
    $kind = $kind !== '' ? $kind : xeric_model_kind($base);
    $host = strtolower((string)(parse_url($base, PHP_URL_HOST) ?? ''));

    // SOMEBODY ELSE'S API IS IDENTIFIED WITHOUT ASKING IT ANYTHING. The key is
    // never stored here, so every request this app could make unauthenticated
    // would come back 401 — and a 401 tells you nothing except that the URL was
    // spelled right. The hostname already says whose it is.
    if ($kind !== 'local') {
        return ['name' => xeric_model_vendor($host) ?: 'OpenAI-compatible API',
                'model' => '', 'local' => false, 'router' => false, 'models' => []];
    }

    // WHAT IT IS AND WHAT IT HAS LOADED ARE TWO QUESTIONS, and the second one
    // decides. A video server run behind llama.cpp answers /props like any
    // llama.cpp — because it IS one — so a branch that returned as soon as it
    // recognised the SERVER filed wan2.2-i2v-a14b as a 14B chat model, which is
    // the whole failure this function exists to prevent. Every branch now falls
    // through to the same last line, and the last line looks at the checkpoint.
    $name = '';
    $model = '';
    $art = false;
    $router = false;
    $routerModels = [];
    // THE KEY GOES TO ONE BOX. XERIC_LOCAL_KEY is llama-server's --api-key on
    // the machine the operator named in XERIC_LOCAL_BASE, and that is the only
    // address it may be sent to. This function also probes every address a
    // visitor adds on the machines screen — the private-network gate lets a
    // public host through on purpose, because that is how a hosted API gets
    // added — and a probe that carried the key there would hand the operator's
    // secret to whoever owns the hostname. A loopback box is the operator's
    // own too, so it keeps the key; everything else is probed anonymously.
    $key = xeric_model_probe_key($base);

    $v = xeric_model_peek($base . '/api/version', 2, $key);
    if ($v !== null && isset($v['version'])) {
        $name = trim('Ollama ' . xeric_model_trim((string)$v['version']));
        $tags = xeric_model_peek($base . '/api/tags', 2, $key);
        $model = xeric_model_trim((string)((array)($tags['models'][0] ?? []))['name'] ?? '');
    }

    if ($name === '') {
        $p = xeric_model_peek($base . '/props', 2, $key);
        if ($p !== null && (isset($p['default_generation_settings']) || isset($p['model_path']))) {
            $name  = 'llama.cpp';
            $model = xeric_model_trim((string)($p['model_path'] ?? ''));
            if ($model === '') $model = xeric_model_trim((string)($p['default_generation_settings']['model'] ?? ''));
        }
    }

    // ROUTER MODE is llama.cpp without a single -m: GET /models lists every
    // GGUF with a lifecycle status. /props often has no model_path then, so
    // this check runs even when the block above stayed quiet.
    if ($name === '' || $name === 'llama.cpp') {
        $cat = function_exists('xeric_llm_router_catalog')
            ? xeric_llm_router_catalog(xeric_model_peek($base . '/models', 2, $key))
            : [];
        if ($cat !== []) {
            $name = 'llama.cpp router';
            $router = true;
            $routerModels = $cat;
            $pick = function_exists('xeric_llm_router_pick')
                ? xeric_llm_router_pick($cat)
                : (string)$cat[0]['id'];
            $model = xeric_model_trim($pick);
        }
    }

    if ($name === '') {
        $l = xeric_model_peek($base . '/api/v0/models', 2, $key);
        if ($l !== null && isset($l['data'][0]['type'])) {
            $name  = 'LM Studio';
            $model = xeric_model_trim((string)($l['data'][0]['id'] ?? ''));
        }
    }

    if ($name === '') {
        $k = xeric_model_peek($base . '/api/extra/version', 2, $key);
        if ($k !== null && isset($k['result'])) $name = 'KoboldCpp';
    }

    if ($name === '') {
        $vl = xeric_model_peek($base . '/version', 2, $key);
        $models = xeric_model_peek($base . '/v1/models', 2, $key);
        $first  = xeric_model_trim((string)($models['data'][0]['id'] ?? ''));
        if ($vl !== null && isset($vl['version'])) { $name = 'vLLM'; $model = $first; }
        elseif ($models !== null)                  { $name = 'OpenAI-compatible'; $model = $first; }
    }

    // THE ENDPOINT TEST, which is the strong one: a server that answers
    // /sdapi/v1/sd-models or /system_stats is a picture server whatever else it
    // said about itself.
    $pic = xeric_model_art_who($base);
    if ($pic['name'] !== '') {
        $art  = true;
        $name = $name === '' || $name === 'OpenAI-compatible' ? $pic['name'] : $name;
        // Its own API names the CHECKPOINT; /v1/models on the same server tends
        // to name the service ("sd-cpp-local"). The checkpoint is the useful one
        // — it is what somebody chose and what they would recognise.
        if ($pic['model'] !== '') $model = $pic['model'];
    }

    // AND THE NAME TEST, which catches everything that presents as an ordinary
    // inference server and is holding a checkpoint that draws.
    if (!$art && $model !== '' && xeric_model_art_id($model)) {
        $art = true;
        if ($name === '') $name = 'Pictures or video';
    }

    return ['name' => $name, 'model' => $model, 'local' => true, 'art' => $art,
            'router' => $router, 'models' => $routerModels];
}

// These lived in model.php until the forge needed the same list, to let somebody
// pick which machine forges. Two screens rendering "your machines" from two
// definitions is the exact disagreement model.php was built to end, so the
// definition moved here and both screens ask it.

/** Every machine this visitor knows about. Row 0 is the install's own. */
function xeric_model_list(?string $sid = null): array
{
    $sess = xeric_web_session_read($sid ?? xeric_session_id());
    // Row 0 is this install's address — a STARTING POINT, not a constant. Once
    // somebody has retyped it, theirs is the one that counts; the config value
    // is what a fresh visitor is handed.
    $own  = rtrim(trim((string)($sess['local_base'] ?? '')), '/');
    if ($own === '') $own = rtrim((string)xeric_web_config()['local_base'], '/');

    // ROW 0 REFUSES TO BE AN IMAGING SERVER. It is the one row that cannot be
    // forgotten, so an address that lands here wrong is stuck at the top of the
    // list — and one did: a probe from before the ranking existed picked 8081,
    // stable-diffusion.cpp, and wrote it here. From then on the first machine
    // the screen offered, on every visit, was a thing that draws pictures.
    //
    // Checked rather than trusted, and it is nearly free: identification of a
    // remote address makes no request at all, and a local one is four short
    // calls to loopback that a wrong answer here would cost somebody an
    // afternoon of.
    if ($own !== '' && !empty(xeric_model_who($own)['art'])) {
        $better = xeric_model_best();
        // Only if there is somewhere better to point. With nothing else running,
        // the wrong address stays visible and correctable rather than being
        // swapped for a guess.
        if ($better !== '') $own = $better;
    }

    $out  = [['base' => $own, 'fixed' => true]];
    foreach ((array)($sess['machines'] ?? []) as $m) {
        $b = rtrim(trim((string)($m['base'] ?? '')), '/');
        // NEVER THE SAME ADDRESS TWICE. Row 0 can move — it self-corrects off an
        // imaging server, and it is editable — so it can land on an address the
        // visitor had also added by hand. Two cards for one machine means two
        // meters counting the same tokens and two lamps that can disagree.
        if ($b !== '' && $b !== $own) $out[] = ['base' => $b, 'fixed' => false];
    }
    return $out;
}

/** What a machine is, in the words the card prints. */
function xeric_model_says(string $kind): string
{
    return $kind === 'local'
        ? 'Local AI (all data stored and processed locally)'
        : 'External Provider';
}

/**
 * Read off the host, so nobody has to be asked.
 *
 * SYNTACTIC, NOT RESOLVED. This used to ask xeric_web_host_open(), which does a
 * live DNS lookup — so api.openai.com was labelled "a machine of your own" and
 * probed keylessly on any afternoon the resolver was slow, and reported red for
 * being down when it was neither down nor local. What kind of address something
 * IS does not depend on the weather.
 *
 * The real guard still resolves: xeric_web_endpoint() refuses a private host for
 * any remote kind, and model.php refuses to STORE one unless the request came
 * from the machine the server is on. This decides a label and a probe strategy.
 */
function xeric_model_kind(string $base): string
{
    $host = strtolower((string)(parse_url($base, PHP_URL_HOST) ?? ''));
    if ($host === '') return 'local';
    if (str_ends_with($host, 'anthropic.com')) return 'anthropic';

    if ($host === 'localhost' || str_ends_with($host, '.local')
        || str_ends_with($host, '.localhost') || !str_contains($host, '.')) {
        return 'local';
    }
    // A literal address is private or it is not; a NAME is somebody else's.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return filter_var($host, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false ? 'local' : 'openai';
    }
    return 'openai';
}

/**
 * Which row is attached, or -1.
 *
 * Matched on the ADDRESS rather than on a stored index, so forgetting a machine
 * cannot silently slide the selection onto a different one.
 */
function xeric_model_active(array $list, ?string $sid = null): int
{
    $m = xeric_web_model($sid);
    if (!xeric_web_connected($m)) return -1;

    $at = rtrim(trim((string)($m['local'] ?? '')), '/');
    if ($at === '') $at = rtrim(trim((string)($m['base'] ?? '')), '/');
    if ($at === '') $at = rtrim((string)xeric_web_config()['local_base'], '/');

    foreach ($list as $i => $row) if ($row['base'] === $at) return $i;
    return -1;
}

// ---------------------------------------------------------------------------
// Connected is a SET; one of them is the engine
// ---------------------------------------------------------------------------
//
// It used to be one attachment, so choosing a machine meant un-choosing the last
// one — and the second machine somebody sets up is usually not the one they want
// running their worlds. It is the big one they will forge with on Sunday, or the
// small fast one they are trying out. Making that choice cost them the first
// machine turned "I have two models" into a toggle.
//
// So: any number CONNECTED, exactly one of them the ENGINE.
//
//   engine     runs your worlds — every chat turn, every hour the heart lives,
//              every proactive ping. There is one because a world has one voice,
//              and because the prompt cache is per-model: spreading
//              a world's turns across machines multiplies its cold prefixes.
//   connected  set up, reachable, and available to forge with. Costs nothing
//              until it is used, which is the whole point of setting it up now.
//
// The engine is still stored as `model` — the single descriptor every other
// screen and the daemon already read — so nothing downstream learns a new shape.
// `wired` is the set around it.

/** Every machine this visitor has connected, engine included. */
function xeric_model_wired(?string $sid = null): array
{
    $sid = $sid ?? xeric_session_id();
    $s   = xeric_web_session_read($sid);

    $out = [];
    foreach ((array)($s['wired'] ?? []) as $b) {
        $b = rtrim(trim((string)$b), '/');
        if ($b !== '' && !in_array($b, $out, true)) $out[] = $b;
    }

    // THE ENGINE IS CONNECTED WHETHER OR NOT THE SET SAYS SO. A session written
    // before `wired` existed has an engine and no set, and a screen that read
    // the set alone would show the machine running every world as "not
    // connected" — with a Connect button under it.
    $m = xeric_web_model($sid);
    if (xeric_web_connected($m)) {   // engine, whatever it is — it is still connected
        $at = rtrim(trim((string)($m['local'] ?? '')), '/');
        if ($at === '') $at = rtrim(trim((string)($m['base'] ?? '')), '/');
        if ($at === '') $at = rtrim((string)xeric_web_config()['local_base'], '/');
        if ($at !== '' && !in_array($at, $out, true)) array_unshift($out, $at);
    }
    return $out;
}

/** Which rows of a machine list are connected. */
function xeric_model_wired_at(array $list, ?string $sid = null): array
{
    $on = xeric_model_wired($sid);
    $out = [];
    foreach ($list as $i => $row) if (in_array((string)$row['base'], $on, true)) $out[] = $i;
    return $out;
}

/**
 * May the app put this machine in the engine slot BY ITSELF?
 *
 * ANYTHING CAN BE THE ENGINE IF SOMEBODY SAYS SO. Nothing here is a ban: an API
 * is a perfectly reasonable thing to run your worlds on, and picking one is a
 * choice this screen offers.
 *
 * WHAT IT MAY NEVER BE IS AUTOMATIC. The engine is not a slot that is merely
 * filled — it is the thing every world calls, every hour the heart lives, and
 * every ping at three in the morning while nobody is watching. Sliding that onto
 * a metered API because it happened to be the next connected machine in a list
 * turns "I disconnected my local model" into a bill. The local ones are free and
 * already on the desk; those are the only ones this app promotes on its own.
 *
 * Anything else waits to be chosen, deliberately, by somebody who knows what it
 * costs per token.
 */
function xeric_model_auto_engine(string $base): bool
{
    return xeric_model_kind($base) === 'local';
}

/** The descriptor a machine gets when it becomes the engine. */
function xeric_model_descriptor(string $base, string $model = ''): array
{
    $base  = rtrim(trim($base), '/');
    $model = str_replace(["\r", "\n"], '', trim($model));
    return xeric_model_kind($base) === 'local'
        ? ['kind' => 'local', 'base' => '', 'local' => $base, 'model' => $model]
        : ['kind' => xeric_model_kind($base), 'base' => $base, 'local' => '', 'model' => $model];
}

/**
 * Resolve a posted machine choice into a stored engine descriptor.
 *
 * The forge wizard sends `{i: N}` — an index into this visitor's own list —
 * and never an address. Returning null means "do not overwrite": a payload
 * that does not name a machine (empty, leftover `{kind: local}`, or a URL
 * stuffed into `base`) must leave the attached engine alone. Honouring a
 * visitor-supplied `local` here would be the SSRF hole xeric_web_endpoint()
 * exists to close; the address comes off the session list, or nowhere.
 *
 * The GGUF id rides along when the same address is already the engine, or
 * when that row stored one (a llama.cpp router). Wiping it would send the
 * next forge to whatever the router happens to have loaded.
 *
 * @return ?array the descriptor to store, or null to keep what is already there
 */
function xeric_model_choice(array $posted, ?string $sid = null): ?array
{
    if (!array_key_exists('i', $posted)) return null;
    $rows = xeric_model_list($sid);
    $i = (int)$posted['i'];
    if (!isset($rows[$i])) return null;
    $base = rtrim(trim((string)$rows[$i]['base']), '/');
    if ($base === '') return null;

    $keep = '';
    $cur = xeric_web_model($sid);
    $at  = rtrim(trim((string)($cur['local'] ?? '')), '/');
    if ($at === '') $at = rtrim(trim((string)($cur['base'] ?? '')), '/');
    if ($at === $base) $keep = str_replace(["\r", "\n"], '', trim((string)($cur['model'] ?? '')));

    if ($keep === '' && $i > 0) {
        $sess  = xeric_web_session_read($sid ?? xeric_session_id());
        $extra = array_values((array)($sess['machines'] ?? []));
        if (isset($extra[$i - 1]['model'])) {
            $keep = str_replace(["\r", "\n"], '', trim((string)$extra[$i - 1]['model']));
        }
    }

    return xeric_model_descriptor($base, $keep);
}
