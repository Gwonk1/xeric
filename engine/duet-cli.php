<?php
/**
 * duet-cli.php — two characters talk, live, in a terminal. No web app.
 *
 *   php engine/duet-cli.php --world=worlds/port-saltwater --a=elias_thorne --b=maren_voss
 *   php engine/duet-cli.php --world=worlds/port-saltwater --cast
 *   php engine/duet-cli.php --world=worlds/port-saltwater --a=x --b=y --turns=4 --say-first=b
 *
 * This is the acceptance test for the duet the way chat-cli.php is for a turn:
 * if two forged characters do not sound like two different people here, no
 * watching surface will fix it. Lines print as they land (the engine streams
 * them through on_line), because a conversation you can only read after it is
 * over is a log, and the whole feature is that you get to watch.
 *
 * A refusal is printed and exits 1 — including the geographic one, which names
 * where each of them actually is, so the fix ("try --epoch=…", or wait) is in
 * the message. --cast prints who is where right now for exactly that reason.
 */

declare(strict_types=1);

// A TERMINAL PROGRAM, AND ONLY THAT — the same fence chat-cli.php carries and
// for the same reason: a host with register_argc_argv on builds $argv from the
// query string, and this file drives up to a couple dozen model calls per run.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/duet.php';
require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/story.php';    // the overlays beside the template, if there are any

$args = xeric_duet_cli_args(array_slice($argv, 1));

if (isset($args['help']) || isset($args['h'])) {
    fwrite(STDOUT, xeric_duet_cli_usage());
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

$dbPath = (string)($args['db'] ?? ($dir . '/world.db'));
if (isset($args['reset'])) {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) @unlink($f);
}

$db = xeric_state_open($dbPath);

// -- the clock --------------------------------------------------------------
$epoch = null;
if (isset($args['epoch'])) {
    $raw = (string)$args['epoch'];
    $epoch = ctype_digit($raw) ? (int)$raw : (int)(strtotime($raw, xeric_state_time()) ?: 0);
    if ($epoch <= 0) { fwrite(STDERR, "--epoch: cannot read '$raw'\n"); exit(2); }
}
$now = xeric_world_now($T, xeric_clock_epoch($db, $epoch));

// -- seed on first run ------------------------------------------------------
$fresh = !xeric_seed_applied($db);
xeric_state_seed($db, $T, xeric_state_time());
try {
    $seedCounts = xeric_seed_apply($db, $T, xeric_seed_load($dir), $now['epoch']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

// -- the overlays this world is carrying ------------------------------------
// Composed the way chat-cli composes them: a speaker whose story has left them
// holding a piece carries it into the duet in their own voice block, which is
// exactly where a scene between two holders gets interesting.
try {
    $stories = xeric_story_for($dir, $T, function (string $n): void { fwrite(STDOUT, '  · ' . $n . "\n"); });
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
$T = xeric_story_compose($T, $stories, $db);

fwrite(STDOUT, $T['meta']['name'] . ', ' . xeric_world_day_name($now['dow']) . ' ' . $now['phase']
    . ', ' . $now['hhmm'] . '  ·  ' . basename($dbPath) . "\n");
if ($fresh) {
    fwrite(STDOUT, '  seeded: ' . $seedCounts['events'] . ' events, ' . $seedCounts['memories'] . ' memories'
        . ($seedCounts['skipped'] ? ', ' . $seedCounts['skipped'] . ' unusable rows dropped' : '') . "\n");
}

// -- the cast, for a caller who does not know the handles -------------------
if (isset($args['cast'])) {
    $presence = xeric_world_who_is_where($T, $now, xeric_dead_handles($db));
    foreach ($T['cast']['characters'] as $c) {
        $h     = (string)$c['handle'];
        $where = xeric_world_place_name($T, $presence[$h]['where'] ?? null);
        fwrite(STDOUT, sprintf("  %-16s %-16s %-22s %s\n", $h, $c['display_name'],
            $where !== '' ? $where : '(off shift)', (string)($c['one_line'] ?? '')));
    }
    exit(0);
}

$a = (string)($args['a'] ?? '');
$b = (string)($args['b'] ?? '');
if ($a === '' || $b === '') {
    fwrite(STDERR, "--a and --b are required (try --cast to see who is where)\n");
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

// -- options ----------------------------------------------------------------
$opts = [
    'temperature' => isset($args['temp']) ? (float)$args['temp'] : 0.85,
    'timeout'     => (int)($args['timeout'] ?? 180),
    'on_line'     => function (string $h, string $name, string $text): void {
        // Live, as each call lands: the watching is the feature.
        foreach (explode("\n", $text) as $i => $line) {
            fwrite(STDOUT, '  ' . ($i === 0 ? $name . ': ' : str_repeat(' ', mb_strlen($name) + 2)) . $line . "\n");
        }
    },
];
if (isset($args['turns'])) $opts['turns'] = (int)$args['turns'];
if (isset($args['seed'])) $opts['seed'] = (int)$args['seed'];
if (isset($args['say-first'])) {
    $sf = (string)$args['say-first'];
    // --say-first=a|b means the flag, not a handle; a handle works too.
    $opts['say_first'] = $sf === 'a' ? $a : ($sf === 'b' ? $b : $sf);
}

// -- the duet ---------------------------------------------------------------
fwrite(STDOUT, "\n");
try {
    $out = xeric_duet($T, $db, $a, $b, $now, $endpoint, $opts);
} catch (Throwable $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");
    exit(1);
}

// -- the close, said out loud ------------------------------------------------
fwrite(STDOUT, "\n  — " . $out['title']
    . ($out['place_name'] !== '' ? ', at ' . $out['place_name'] : '') . " —\n");
foreach ($out['memories'] as $h => $kept) {
    $name = xeric_world_name($T, (string)$h);
    if ($kept === []) { fwrite(STDOUT, "  $name kept nothing new of it\n"); continue; }
    fwrite(STDOUT, "  $name will remember:\n");
    foreach ($kept as $m) fwrite(STDOUT, '    + ' . $m . "\n");
}
foreach ($out['notes'] as $n) fwrite(STDOUT, '  (' . $n . ")\n");

$u = $out['usage'];
fwrite(STDOUT, sprintf("  [event %d · %d lines · %.1fs · %s prompt / %s reply tokens]\n",
    (int)$out['event_id'], (int)$out['turns'], ($u['ms'] ?? 0) / 1000,
    (string)($u['prompt_tokens'] ?? '?'), (string)($u['completion_tokens'] ?? '?')));

exit(0);

// ---------------------------------------------------------------------------

/** --flag, --key=value. Everything else is ignored. */
function xeric_duet_cli_args(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '-')) continue;
        $arg = ltrim($arg, '-');
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = true;
        }
    }
    return $out;
}

function xeric_duet_cli_usage(): string
{
    return <<<TXT
    php engine/duet-cli.php --world=DIR --a=HANDLE --b=HANDLE

      --world=DIR          a world directory (world-template.json [+ seed.json])
      --a=HANDLE           one voice          (--cast lists them, and where they are)
      --b=HANDLE           the other voice
      --turns=N            total spoken lines (default ~6; the engine may add one
                           for the last word unless you pin this)
      --say-first=a|b      who opens (default: a weighted draw)
      --cast               print the cast and where they are right now
      --reset              delete the world db and start the world over
      --epoch=WHEN         unix time, or anything strtotime reads
      --db=PATH            default <world>/world.db
      --seed=N             pin the dice, for a reproducible run
      --temp=N             sampling temperature (default 0.85)
      --timeout=SECS       per call (default 180)

      --base=URL           any OpenAI-compatible endpoint (default 127.0.0.1:8080)
      --kind=local|openai|anthropic
      --model=NAME  --key=SECRET   (or set XERIC_API_KEY)

    TXT;
}
