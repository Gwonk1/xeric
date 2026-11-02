<?php
/**
 * llm-test.php — the adapter's URL and default-endpoint rules.
 *
 * These are the ones that fail silently: a pasted /v1 doubles the path,
 * an external llama.cpp is ignored because 127.0.0.1:8080 was hardcoded,
 * a key set in the environment never leaves the process. None of them
 * need a live model.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/llm.php';

$FAILED = 0;
function ok(string $what, bool $cond, string $extra = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $what\n"; return; }
    $FAILED++;
    echo "FAIL - $what" . ($extra !== '' ? " ($extra)" : '') . "\n";
}

ok('empty stays empty', xeric_llm_normalize_base('') === '');
ok('trailing slash is dropped', xeric_llm_normalize_base('http://gpu:8080/') === 'http://gpu:8080');
ok('a pasted /v1 is the OpenAI root, not a second one',
    xeric_llm_normalize_base('http://gpu:8080/v1') === 'http://gpu:8080');
ok('and /v1/ is the same paste',
    xeric_llm_normalize_base('http://gpu:8080/v1/') === 'http://gpu:8080');
ok('a real /v1/foo path is left alone',
    xeric_llm_normalize_base('http://gpu:8080/v1/foo') === 'http://gpu:8080/v1/foo');
ok('OpenAI\'s own /v1 becomes the host the adapter will suffix',
    xeric_llm_normalize_base('https://api.openai.com/v1') === 'https://api.openai.com');
ok('OpenRouter\'s /api/v1 keeps /api',
    xeric_llm_normalize_base('https://openrouter.ai/api/v1') === 'https://openrouter.ai/api');

$prevBase  = getenv('XERIC_LOCAL_BASE');
$prevKey   = getenv('XERIC_LOCAL_KEY');
$prevApi   = getenv('XERIC_API_KEY');
$prevModel = getenv('XERIC_LOCAL_MODEL');
foreach (['XERIC_LOCAL_BASE', 'XERIC_LOCAL_KEY', 'XERIC_API_KEY', 'XERIC_LOCAL_MODEL'] as $k) {
    putenv($k);
    unset($_ENV[$k]);
}

ok('with nothing set, llama.cpp\'s own default',
    xeric_llm_default_base() === 'http://127.0.0.1:8080');

putenv('XERIC_LOCAL_BASE=http://192.168.1.50:8080/v1');
ok('XERIC_LOCAL_BASE wins, and /v1 is stripped',
    xeric_llm_default_base() === 'http://192.168.1.50:8080');

$cli = xeric_llm_cli_endpoint([]);
ok('CLI with no flags follows the env',
    $cli['base'] === 'http://192.168.1.50:8080' && $cli['kind'] === 'local',
    json_encode($cli));

$cli = xeric_llm_cli_endpoint(['base' => 'https://gpu.example.com:8080/v1', 'key' => "sk-test\n"]);
ok('--base beats the env', $cli['base'] === 'https://gpu.example.com:8080');
ok('and a newline in a pasted key is stripped rather than refused',
    $cli['key'] === 'sk-test');

putenv('XERIC_LOCAL_KEY=from-local');
putenv('XERIC_API_KEY=from-api');
ok('XERIC_LOCAL_KEY is preferred over XERIC_API_KEY',
    xeric_llm_default_key() === 'from-local');
putenv('XERIC_LOCAL_KEY');
ok('XERIC_API_KEY is the fallback', xeric_llm_default_key() === 'from-api');

$cli = xeric_llm_cli_endpoint(['base' => 'gpu:8080']);
ok('a host:port without a scheme is still a URL',
    $cli['base'] === 'http://gpu:8080', $cli['base']);

ok('OpenAI /v1/models is not a router catalog',
    xeric_llm_router_catalog(['data' => [['id' => 'gpt-4', 'object' => 'model']]]) === []);
ok('an empty payload is not a router',
    xeric_llm_router_catalog(['data' => []]) === []);
$cat = xeric_llm_router_catalog(['data' => [
    ['id' => 'gemma', 'path' => '/m/gemma.gguf', 'status' => ['value' => 'loaded']],
    ['id' => 'qwen',  'path' => '/m/qwen.gguf',  'status' => ['value' => 'unloaded']],
]]);
ok('llama.cpp /models with lifecycle status is a router',
    count($cat) === 2 && $cat[0]['id'] === 'gemma' && $cat[0]['status'] === 'loaded');
ok('the loaded GGUF is the default pick',
    xeric_llm_router_pick($cat) === 'gemma');
ok('a current id that is still listed is kept',
    xeric_llm_router_pick($cat, 'qwen') === 'qwen');
ok('a current id that left the catalog is kept rather than swapped',
    xeric_llm_router_pick($cat, 'other') === 'other');
ok('a string status is accepted the same way as {value}',
    xeric_llm_router_catalog(['data' => [['id' => 'a', 'status' => 'loading']]])[0]['status'] === 'loading');

foreach (['XERIC_LOCAL_BASE' => $prevBase, 'XERIC_LOCAL_KEY' => $prevKey,
          'XERIC_API_KEY' => $prevApi, 'XERIC_LOCAL_MODEL' => $prevModel] as $k => $v) {
    if ($v === false || $v === null) putenv($k);
    else putenv($k . '=' . $v);
}

if ($FAILED > 0) {
    echo "\n$FAILED failed\n";
    exit(1);
}
echo "\nall ok\n";

// THE LOCAL KEY STAYS LOCAL. --api-key on a llama-server is a secret for that
// box; a hosted endpoint may take XERIC_API_KEY and never the local one.
putenv('XERIC_LOCAL_KEY=llama-secret'); putenv('XERIC_API_KEY=sk-hosted');
ok('a local endpoint takes the llama.cpp key', xeric_llm_default_key('local') === 'llama-secret');
ok('a hosted endpoint takes the hosted key, never the local one',
    xeric_llm_default_key('openai') === 'sk-hosted' && xeric_llm_default_key('anthropic') === 'sk-hosted');
ok('and the CLI follows the kind it was given',
    xeric_llm_cli_endpoint(['kind' => 'openai'])['key'] === 'sk-hosted'
    && xeric_llm_cli_endpoint([])['key'] === 'llama-secret');
putenv('XERIC_LOCAL_KEY');
ok('with no local key the local box may use the hosted one', xeric_llm_default_key('local') === 'sk-hosted');
putenv('XERIC_API_KEY');

exit(0);
