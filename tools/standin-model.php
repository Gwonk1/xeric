<?php
/**
 * standin-model.php — a second model that is really the first one.
 *
 *     PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8082 tools/standin-model.php
 *
 * WHY THIS EXISTS. The machines screen has a lot of behaviour that only appears
 * with more than one model connected — the engine slot, "Make engine", the
 * handover when an engine is disconnected, the forge's own chooser, per-machine
 * token meters — and testing all of it by loading a second 15GB checkpoint costs
 * a GPU and several minutes every time. This answers like a separate llama.cpp
 * with its own name, and forwards the actual thinking to the model already
 * running. Two machines on screen; one set of weights in memory.
 *
 * IT IS REAL ENOUGH TO FORGE WITH. Everything except identity is proxied, so a
 * world built "on" this machine is a world genuinely built — which matters,
 * because a stand-in that could be selected and then failed would be testing the
 * error path rather than the feature.
 *
 * AND IT SAYS IT IS A STAND-IN, in the model id, on the one screen that prints
 * model ids. Nothing that reaches a user should be able to be mistaken for a
 * model they actually have — the point is to test the UI, not to fool it.
 *
 * Env:
 *   XERIC_STANDIN_UP     where the real model is       (default http://127.0.0.1:8080)
 *   XERIC_STANDIN_ID     the name to answer with       (default gemma-4-12B-stand-in-Q4_K_M)
 *
 * The default name carries "12B" on purpose: it sorts BELOW the 26B this box
 * runs, so the biggest-model-wins ranking is visible rather than assumed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    fwrite(STDERR, "run me with:  PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8082 " . __FILE__ . "\n");
    exit(1);
}

$up = rtrim((string)(getenv('XERIC_STANDIN_UP') ?: 'http://127.0.0.1:8080'), '/');
$id = (string)(getenv('XERIC_STANDIN_ID') ?: 'gemma-4-12B-stand-in-Q4_K_M');

$path   = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$say = function (array $body, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
};

// -- identity, which is the only thing this server decides for itself ---------

if ($path === '/v1/models' && $method === 'GET') {
    $say(['object' => 'list', 'data' => [['id' => $id, 'object' => 'model', 'owned_by' => 'stand-in']]]);
    exit;
}

// /props is what marks a server as llama.cpp to xeric_model_who(), and
// model_path is where it reads the checkpoint name from.
if ($path === '/props' && $method === 'GET') {
    $say(['default_generation_settings' => ['model' => $id],
          'model_path' => "/models/$id.gguf",
          'total_slots' => 1,
          'chat_template' => '']);
    exit;
}

if ($path === '/health' && $method === 'GET') { $say(['status' => 'ok']); exit; }

// -- everything else is the real model ---------------------------------------

$body = $method === 'POST' ? (string)file_get_contents('php://input') : '';

$headers = ['Accept: application/json'];
if ($body !== '') $headers[] = 'Content-Type: application/json';

// A GENEROUS TIMEOUT, because the thing on the other end is a 26B writing prose
// and a forge pass can legitimately think for minutes. A stand-in that gave up
// early would look exactly like a model that had crashed.
$ctx = stream_context_create(['http' => [
    'method'          => $method,
    'header'          => implode("\r\n", $headers),
    'content'         => $body,
    'timeout'         => 900,
    'ignore_errors'   => true,
    'follow_location' => 0,
]]);

$fh = @fopen($up . $path, 'rb', false, $ctx);
if ($fh === false) {
    $say(['error' => ['message' => "the stand-in could not reach $up$path"]], 502);
    exit;
}

$code = 200;
$type = 'application/json';
foreach (($http_response_header ?? []) as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; continue; }
    if (stripos($h, 'content-type:') === 0) $type = trim(substr($h, 13));
}
http_response_code($code);
header('Content-Type: ' . $type);

// Passed through as it arrives rather than buffered, so a streaming completion
// still streams and the caller sees the first token when the real model emits it.
while (!feof($fh)) {
    $chunk = fread($fh, 8192);
    if ($chunk === false) break;
    echo $chunk;
    flush();
}
fclose($fh);
