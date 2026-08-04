<?php
/**
 * mcp-test.php — the agent wrapper's wire, with no xeric behind it.
 * `php tools/tests/mcp-test.php`, exit 0.
 *
 * NO NETWORK: XERIC_BASE points at a port nothing answers on, because half of
 * what this file defends is what the wrapper says when the instance is not
 * there — an agent deserves "is the xeric running?" and never a stack trace.
 * The protocol half runs the real server as a subprocess and speaks the real
 * wire: newline-delimited JSON-RPC, initialize first, notifications answered
 * with silence, junk answered with a parse error and a living loop.
 */

declare(strict_types=1);

$FAILED = 0;
function ok(string $what, bool $cond, string $extra = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $what\n"; return; }
    $FAILED++;
    echo "FAIL - $what" . ($extra !== '' ? " ($extra)" : '') . "\n";
}

$seats = sys_get_temp_dir() . '/xeric-mcp-test-' . getmypid() . '.json';
@unlink($seats);

// -- the whole conversation, in one pass -------------------------------------
$reqs = [
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
     'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass()]],
    ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
    ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
    ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
     'params' => ['name' => 'seat', 'arguments' => new stdClass()]],
    ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
     'params' => ['name' => 'look', 'arguments' => new stdClass()]],
    ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
     'params' => ['name' => 'join', 'arguments' =>
        ['world' => 'nowhere', 'code' => 'AAAAAAAA', 'name' => 'Scout']]],
    ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'no/such/method'],
    'this line is not json',
    ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'],
];

$proc = proc_open(
    [PHP_BINARY, dirname(__DIR__) . '/mcp.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes, null,
    ['XERIC_BASE' => 'http://127.0.0.1:1', 'XERIC_AGENT_SEATS' => $seats,
     'HOME' => sys_get_temp_dir()]
);
ok('wire: the server starts', is_resource($proc));

foreach ($reqs as $r) {
    fwrite($pipes[0], (is_string($r) ? $r : json_encode($r)) . "\n");
}
fclose($pipes[0]);
$outRaw = (string)stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

$byId = [];
$parseErr = null;
foreach (array_filter(array_map('trim', explode("\n", $outRaw))) as $line) {
    $d = json_decode($line, true);
    if (!is_array($d)) { ok('wire: the server spoke only JSON', false, $line); continue; }
    if (array_key_exists('id', $d) && $d['id'] !== null) $byId[(int)$d['id']] = $d;
    elseif (isset($d['error'])) $parseErr = $d;
}

// -- initialize ---------------------------------------------------------------
$init = $byId[1]['result'] ?? [];
ok('initialize: answers in the client\'s dialect when it names one',
    ($init['protocolVersion'] ?? '') === '2025-06-18');
ok('initialize: a tools server says so and signs its name',
    isset($init['capabilities']['tools']) && ($init['serverInfo']['name'] ?? '') === 'xeric');
ok('initialize: the notification got silence — nine lines in, eight answers out',
    count($byId) + ($parseErr !== null ? 1 : 0) === 7 + 1,
    'answers=' . count($byId));

// -- tools/list ---------------------------------------------------------------
$tools = array_column((array)($byId[2]['result']['tools'] ?? []), null, 'name');
ok('tools: the eight seats-eye tools, no more',
    count($tools) === 8
    && isset($tools['join'], $tools['seat'], $tools['look'], $tools['walk'],
             $tools['read_room'], $tools['say_in_room'], $tools['text_character'],
             $tools['read_thread']),
    implode(',', array_keys($tools)));
ok('tools: nothing here tunes a world — no forge, no review, no inspector, no skip',
    !isset($tools['forge'], $tools['review'], $tools['why'], $tools['skip'], $tools['tick']));
ok('tools: join names its required fields',
    ($tools['join']['inputSchema']['required'] ?? []) === ['world', 'code', 'name']);
ok('tools: every tool carries a schema and a description',
    array_reduce(array_values($tools), fn($c, $t) => $c
        && ($t['description'] ?? '') !== '' && isset($t['inputSchema']['type']), true));

// -- calls with nothing behind them -------------------------------------------
$text = fn(int $id): string => (string)($byId[$id]['result']['content'][0]['text'] ?? '');
ok('seat: an empty book says how to get a seat',
    str_contains($text(3), 'No seat') && str_contains($text(3), 'pair code'));
ok('look: without a seat, the answer is the door, not an error object',
    str_contains($text(4), 'join with a pair code first'));
ok('join: a dead instance gets a sentence about the instance',
    str_contains($text(5), 'The door did not open')
    && str_contains($text(5), 'is the xeric running?'), $text(5));
ok('methods: an unknown one is -32601', (int)($byId[6]['error']['code'] ?? 0) === -32601);
ok('junk: a non-JSON line is a parse error and the loop lives',
    $parseErr !== null && (int)$parseErr['error']['code'] === -32700);
ok('ping: pings pong', isset($byId[7]['result']));

@unlink($seats);
echo $FAILED === 0 ? "\nall mcp tests passed\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
