<?php
/**
 * narrator-cli.php — ask the world why. The author's console for ASK mode.
 *
 *   php engine/narrator-cli.php --world=worlds/port-saltwater --ask="why has Maren gone quiet?"
 *   php engine/narrator-cli.php --world=worlds/port-saltwater --context
 *
 * This is the acceptance surface for the narrator the way chat-cli.php is for
 * the chat turn: if the world cannot explain itself here, no web panel will
 * make it articulate.
 *
 * DELIBERATELY MISSING, next to chat-cli:
 *   --reset   A tool whose whole contract is "changes nothing" does not ship a
 *             flag that deletes the world. If you want the world gone, that is
 *             a decision to make somewhere that admits it makes decisions.
 *   seeding   chat-cli seeds a fresh world because a first conversation needs
 *             arcs to move. The narrator moves nothing; against an unseeded
 *             world it reads empty ledgers and says so, which is the truth.
 *
 * The one write this program performs is opening the SQLite file, which will
 * create an empty, schema-only ledger where none exists. An empty ledger
 * contains nothing and changes no answer.
 */

declare(strict_types=1);

// A TERMINAL PROGRAM, AND ONLY THAT — same fence as the other CLIs: a host
// with register_argc_argv on would otherwise let a URL drive this against any
// world on the disk.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/narrator.php';

$args = xeric_narrator_cli_args(array_slice($argv, 1));

if (isset($args['help']) || isset($args['h'])) {
    fwrite(STDOUT, xeric_narrator_cli_usage());
    exit(0);
}

// -- the world --------------------------------------------------------------
$worldArg = (string)($args['world'] ?? '');
if ($worldArg === '') {
    fwrite(STDERR, "--world is required (a directory with world-template.json, or the template itself)\n");
    exit(2);
}
$worldPath = $worldArg[0] === '/' ? $worldArg : getcwd() . '/' . $worldArg;
$dir       = is_dir($worldPath) ? rtrim($worldPath, '/') : dirname($worldPath);
$tplPath   = is_dir($worldPath) ? $dir . '/world-template.json' : $worldPath;

try {
    $T = xeric_world_load($tplPath);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$db = xeric_state_open((string)($args['db'] ?? ($dir . '/world.db')));

// -- the clock --------------------------------------------------------------
$epoch = null;
if (isset($args['epoch'])) {
    $raw   = (string)$args['epoch'];
    $epoch = ctype_digit($raw) ? (int)$raw : (int)(strtotime($raw, xeric_state_time()) ?: 0);
    if ($epoch <= 0) { fwrite(STDERR, "--epoch: cannot read '$raw'\n"); exit(2); }
}
$now = xeric_world_now($T, xeric_clock_epoch($db, $epoch));

// -- the overlays, read and NEVER composed ----------------------------------
// Composition writes (the inciting death lands on first compose), and this
// program answers questions. The narrator reads a story's status through its
// arcs and the world through the raw template; see engine/narrator.php.
try {
    $stories = xeric_story_for($dir, $T, function (string $n): void { fwrite(STDOUT, '  · ' . $n . "\n"); });
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

fwrite(STDOUT, $T['meta']['name'] . ', ' . xeric_world_day_name($now['dow']) . ' ' . $now['phase']
    . ', ' . $now['hhmm'] . "\n");

$opts = ['stories' => $stories];
if (isset($args['events']))   $opts['events']   = (int)$args['events'];
if (isset($args['memories'])) $opts['memories'] = (int)$args['memories'];

// -- the assembled prompt, without a model ----------------------------------
// The debugger for the debugger: what would the narrator be shown? Also the
// honest way to eyeball the discretion fences without spending a token.
if (isset($args['context'])) {
    $built = xeric_narrator_prompt($T, $db, $now, (string)($args['ask'] ?? '(no question)'), $opts);
    foreach ($built['messages'] as $m) {
        fwrite(STDOUT, "\n===== " . strtoupper((string)$m['role']) . " =====\n" . $m['content'] . "\n");
    }
    fwrite(STDOUT, "\n" . xeric_narrator_cli_sources($built['sources']) . "\n");
    exit(0);
}

$question = trim((string)($args['ask'] ?? ''));
if ($question === '') {
    fwrite(STDERR, "--ask=\"…\" is required (or --context to see what the narrator would read)\n");
    exit(2);
}

// -- endpoint ---------------------------------------------------------------
$endpoint = [
    'kind'  => (string)($args['kind'] ?? 'local'),
    'base'  => (string)($args['base'] ?? ''),
    'model' => (string)($args['model'] ?? ''),
    'key'   => (string)($args['key'] ?? getenv('XERIC_API_KEY') ?: ''),
];
if ($endpoint['base'] === '') {
    $endpoint['kind'] = 'local';
    $endpoint['base'] = 'http://127.0.0.1:8080';
}

// -- the ask ----------------------------------------------------------------
fwrite(STDOUT, "\n  " . (trim((string)($T['user']['name'] ?? '')) ?: 'you') . ': ' . $question . "\n");

try {
    $out = xeric_narrator_ask($T, $db, $question, $now, $endpoint, $opts + [
        'temperature' => isset($args['temp']) ? (float)$args['temp'] : 0.6,
        'timeout'     => (int)($args['timeout'] ?? 180),
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");
    exit(1);
}

foreach (explode("\n", $out['text']) as $i => $line) {
    fwrite(STDOUT, '  ' . ($i === 0 ? 'the narrator: ' : '              ') . $line . "\n");
}

$u = $out['usage'];
fwrite(STDOUT, sprintf("  [%.1fs · %s prompt / %s reply tokens]\n",
    ($u['ms'] ?? 0) / 1000, (string)($u['prompt_tokens'] ?? '?'), (string)($u['completion_tokens'] ?? '?')));
fwrite(STDOUT, '  ' . xeric_narrator_cli_sources($out['sources']) . "\n");

exit(0);

// ---------------------------------------------------------------------------

/**
 * The citations line: what the answer was drawn from, from the assembly
 * manifest rather than from the model's mouth — a model asked to cite invents.
 */
function xeric_narrator_cli_sources(array $s): string
{
    $bits = ['the bible'];
    $ev = (array)($s['events'] ?? []);
    if ($ev !== []) {
        $tr = count((array)($s['trails'] ?? []));
        $bits[] = count($ev) . ' event' . (count($ev) === 1 ? '' : 's')
            . ($tr > 0 ? " ($tr with trails)" : '');
    }
    $mem = (array)($s['memories'] ?? []);
    if ($mem !== []) $bits[] = 'memories of ' . xeric_join_list(array_keys($mem));
    $th = (array)($s['threads'] ?? []);
    if ($th !== []) $bits[] = count($th) . ' thread' . (count($th) === 1 ? '' : 's');
    $st = (array)($s['stories'] ?? []);
    if ($st !== []) $bits[] = count($st) . ' stor' . (count($st) === 1 ? 'y' : 'ies') . ' on the shelf';
    $dd = (array)($s['deaths'] ?? []);
    if ($dd !== []) $bits[] = 'the deaths ledger';
    return 'drawn from: ' . implode('; ', $bits);
}

/** --flag, --key=value. Everything else is ignored. Same parser as chat-cli. */
function xeric_narrator_cli_args(array $argv): array
{
    $out = [];
    foreach ($argv as $a) {
        if (!str_starts_with($a, '-')) continue;
        $a = ltrim($a, '-');
        if (str_contains($a, '=')) {
            [$k, $v] = explode('=', $a, 2);
            $out[$k] = $v;
        } else {
            $out[$a] = true;
        }
    }
    return $out;
}

function xeric_narrator_cli_usage(): string
{
    return <<<TXT
    php engine/narrator-cli.php --world=DIR --ask="why has Maren gone quiet?"

      --world=DIR          a world directory (world-template.json [+ world.db, story-*.json])
      --ask="…"            the question. The narrator answers within its discretion:
                           straight about the machine, discreet about the story.
      --context            print what the narrator would read instead of asking
      --epoch=WHEN         unix time, or anything strtotime reads
      --db=PATH            default <world>/world.db
      --events=N           how many recent events to hand it (default 10)
      --memories=N         memories per head (default 5)
      --temp=N             sampling temperature (default 0.6)
      --timeout=SECS       default 180

      --base=URL           any OpenAI-compatible endpoint (default 127.0.0.1:8080)
      --kind=local|openai|anthropic
      --model=NAME  --key=SECRET   (or set XERIC_API_KEY)

    TXT;
}
