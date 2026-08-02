<?php
/**
 * room-cli.php — three to five characters talk, live, in a terminal. No web app.
 *
 *   php engine/room-cli.php --world=worlds/lafittes-reach --who=a,b,c
 *   php engine/room-cli.php --world=worlds/lafittes-reach --cast
 *   php engine/room-cli.php --world=worlds/lafittes-reach --who=a,b,c,d --beats=8 --say-first=b
 *
 * This is the acceptance test for the room the way duet-cli.php is for the
 * duet: if three forged characters do not sound like three different people
 * holding one conversation here, no watching surface will fix it. Lines print
 * as they land (the engine streams them through on_line), departures print as
 * stage directions in the same stream, and the close says who kept what, who
 * never spoke, and who walked out — because those ARE the feature, and a
 * surface that hid them would be showing a group chat.
 *
 * A refusal is printed and exits 1 — including the geographic one, which
 * names where every single one of them actually is, so the fix ("try
 * --epoch=…", or pick different people) is in the message. --cast prints who
 * is where right now for exactly that reason.
 */

declare(strict_types=1);

// A TERMINAL PROGRAM, AND ONLY THAT — the same fence duet-cli.php carries and
// for the same reason: a host with register_argc_argv on builds $argv from the
// query string, and this file drives a couple dozen model calls per run.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/room.php';
require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/story.php';    // the overlays beside the template, if there are any

$args = xeric_room_cli_args(array_slice($argv, 1));

if (isset($args['help']) || isset($args['h'])) {
    fwrite(STDOUT, xeric_room_cli_usage());
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
// Composed the way duet-cli composes them: any speaker whose story has left
// them holding a piece carries it into the room in their own voice block —
// and a room is where two holders finally stand next to each other.
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

$who = array_values(array_filter(array_map('trim', explode(',', (string)($args['who'] ?? '')))));
if ($who === []) {
    fwrite(STDERR, "--who=a,b,c is required, three to five handles (try --cast to see who is where)\n");
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
    'on_line'     => function (string $h, string $name, string $text, string $kind): void {
        // Live, as each call lands: the watching is the feature. A departure
        // prints in the same stream as a stage direction, because that is
        // what everyone in the room saw.
        if ($kind === 'exit') {
            fwrite(STDOUT, "  — " . $text . " —\n");
            return;
        }
        foreach (explode("\n", $text) as $i => $line) {
            fwrite(STDOUT, '  ' . ($i === 0 ? $name . ': ' : str_repeat(' ', mb_strlen($name) + 2)) . $line . "\n");
        }
    },
];
if (isset($args['beats'])) $opts['beats'] = (int)$args['beats'];
if (isset($args['seed'])) $opts['seed'] = (int)$args['seed'];
if (isset($args['say-first'])) $opts['say_first'] = (string)$args['say-first'];

// -- the room ----------------------------------------------------------------
fwrite(STDOUT, "\n");
try {
    $out = xeric_room($T, $db, $who, $now, $endpoint, $opts);
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
foreach ($out['never_spoke'] as $h) {
    fwrite(STDOUT, '  ' . xeric_world_name($T, (string)$h) . " never said a word, and was there for all of it\n");
}
foreach ($out['departures'] as $h => $d) {
    fwrite(STDOUT, '  ' . xeric_world_name($T, (string)$h) . ' left at beat ' . (int)$d['beat'] . "\n");
}
foreach ($out['notes'] as $n) fwrite(STDOUT, '  (' . $n . ")\n");

$u = $out['usage'];
fwrite(STDOUT, sprintf("  [event %d · %d lines · %.1fs · %s prompt / %s reply tokens]\n",
    (int)$out['event_id'], (int)$out['turns'], ($u['ms'] ?? 0) / 1000,
    (string)($u['prompt_tokens'] ?? '?'), (string)($u['completion_tokens'] ?? '?')));

exit(0);

// ---------------------------------------------------------------------------

/** --flag, --key=value. Everything else is ignored. */
function xeric_room_cli_args(array $argv): array
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

function xeric_room_cli_usage(): string
{
    return <<<TXT
    php engine/room-cli.php --world=DIR --who=HANDLE,HANDLE,HANDLE[,...]

      --world=DIR          a world directory (world-template.json [+ seed.json])
      --who=a,b,c          three to five handles (--cast lists them, and where they are)
      --beats=N            spoken lines total (default three per person; a departure
                           does not spend one)
      --say-first=HANDLE   who opens (default: a weighted draw)
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
