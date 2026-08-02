<?php
/**
 * sweep-cli.php — move the world forward and watch it live without you.
 *
 *   php engine/sweep-cli.php --world=worlds/the-neon-rest --advance=6h
 *   php engine/sweep-cli.php --world=worlds/the-neon-rest --advance=6h --sweeps=2
 *   php engine/sweep-cli.php --world=worlds/the-neon-rest --status
 *
 * This is the acceptance test for the thing the whole product is selling, the way
 * chat-cli.php is for a turn and forge-cli.php is for a world: advance the clock,
 * run the sweeps for the hours that went by, and print what happened, what each
 * person took away from it, and the message that arrived unprompted. If that
 * transcript does not read like a world, no amount of web UI will make it one.
 *
 * The clock it moves is the REAL one — the same world_state offset the demo's
 * time control writes — so a world advanced here stays advanced for chat-cli.php,
 * and the character you talk to next genuinely is six hours older than the one
 * you left.
 */

declare(strict_types=1);

// A TERMINAL PROGRAM, AND ONLY THAT. The deploy puts the engine inside the
// docroot (web/lib/engine/), where a host with register_argc_argv on builds
// $argv out of the query string — which turns this file into a clock anybody can
// wind from a URL, moving somebody else's world forward hours at a time and
// holding the GPU while it does. The .htaccess denies lib/ as well; that one
// depends on a server being configured the way we left it, and this one does not.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/clock.php';
require_once __DIR__ . '/sweeps.php';
require_once __DIR__ . '/proactive.php';
require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/story.php';    // the overlays beside the template, if there are any
require_once __DIR__ . '/rewind.php';   // the mark + manifest around a skip, and the way back

$args = xeric_sweep_cli_args(array_slice($argv, 1));

if (isset($args['help']) || isset($args['h'])) {
    fwrite(STDOUT, xeric_sweep_cli_usage());
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

if (isset($args['reset-clock'])) {
    xeric_clock_reset($db);
    fwrite(STDOUT, "clock reset to real time\n");
}

// -- seed on first run, exactly as chat-cli does ----------------------------
$before = xeric_clock_now($db, $T);
$fresh  = !xeric_seed_applied($db);
xeric_state_seed($db, $T, xeric_state_time());
try {
    $seeded = xeric_seed_apply($db, $T, xeric_seed_load($dir), $before['epoch']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

// -- the overlays this world is carrying ------------------------------------
// BOTH HALVES OR NEITHER. The raw overlays are what the pace, the thumb and the
// beats read (they go in $opts below); the COMPOSED template is what the walls,
// the convictions and the held pieces read. Passing one without the other gives
// a half-live story — pace with nobody's voice in it, or voices at the wrong
// pace. xeric_story_for() is the door rather than xeric_story_load(): an overlay
// this world may not show is dropped with a note instead of refusing the world.
$storyNotes = [];
try {
    $stories = xeric_story_for($dir, $T, function (string $n) use (&$storyNotes): void { $storyNotes[] = $n; });
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
$T = xeric_story_compose($T, $stories, $db);

$armed = xeric_sweep_armed($T);
fwrite(STDOUT, "\n" . $T['meta']['name'] . '  ·  ' . basename($dbPath) . "\n");
fwrite(STDOUT, '  now: ' . xeric_sweep_cli_when($before)
    . '   offset ' . xeric_clock_span_label(xeric_clock_offset($db)) . "\n");
fwrite(STDOUT, '  armed: ' . ($armed ? implode(', ', $armed) : '(nothing, ordinary life only)') . "\n");
// The db goes in so a world that has learned something says so out loud: a ×
// next to a kind is learn.php's thumb on the scale, and "why does it keep doing
// that?" should be answerable from this line rather than from the database.
$canKinds = [];
foreach (xeric_sweep_kinds_for($T, $db) as $name => $k) {
    $w = (float)($k['weight'] ?? 1.0);
    $canKinds[] = $name . (abs($w - 1.0) > 0.001 ? ' ×' . $w : '');
}
fwrite(STDOUT, '  can happen here: ' . implode(', ', $canKinds) . "\n");
// The stage, and never a beat: this transcript is read while playing, and the
// shelf is the one surface that may say anything about a story out loud.
foreach (xeric_story_active($stories, $db, $T) as $s) {
    $pr = xeric_story_progress($s, $db, (int)$before['epoch']);
    fwrite(STDOUT, '  carrying: "' . xeric_story_title($s) . '", ' . (string)$pr['stage']
        . sprintf(' (%d%% through, pace ×%.2f)', (int)round(100 * (float)$pr['p']), (float)$pr['m']) . "\n");
}
foreach ($storyNotes as $n) fwrite(STDOUT, '  · ' . $n . "\n");
if ($fresh) {
    fwrite(STDOUT, '  seeded: ' . $seeded['events'] . ' events, ' . $seeded['memories'] . " memories\n");
}

// -- status only ------------------------------------------------------------
if (isset($args['status'])) {
    fwrite(STDOUT, "\n  " . xeric_events_count($db) . ' events · ' . xeric_memories_count($db)
        . ' memories · ' . xeric_conversation_unread_total($db) . " unread\n");
    foreach (array_reverse(xeric_events_recent($db, 8)) as $e) {
        fwrite(STDOUT, '    ' . xeric_sweep_cli_stamp($T, (int)$e['world_epoch']) . '  ' . (string)$e['title']
            . '  [' . implode(', ', array_map(fn($h) => xeric_world_name($T, (string)$h), (array)$e['participants'])) . "]\n");
    }
    exit(0);
}

// -- take back the last skip -------------------------------------------------
// The whole thing or none of it, and only the most recent one: rewind.php owns
// the rules and this only says what happened in the same voice --advance uses.
// A refusal is exit 1 rather than 2 — the operator asked a fair question and
// got a real answer, which is not the same failure as a flag nobody can read.
if (isset($args['rewind'])) {
    $r = xeric_rewind($T, $db);
    if (!$r['ok']) {
        fwrite(STDOUT, "\n  " . (string)$r['why'] . "\n\n");
        exit(1);
    }
    fwrite(STDOUT, "\n  ⏪ " . $r['label'] . ' un-happened: '
        . $r['events'] . ' event' . ($r['events'] === 1 ? '' : 's') . ', '
        . $r['memories'] . ' memor' . ($r['memories'] === 1 ? 'y' : 'ies') . ', '
        . $r['messages'] . ' message' . ($r['messages'] === 1 ? '' : 's') . "\n");
    fwrite(STDOUT, '  the world stands at ' . xeric_sweep_cli_when((array)$r['now'])
        . " again, and those hours are unlived — skip them a second time and they may go differently\n\n");
    exit(0);
}

// What the LAST run left hanging, judged now that hindsight exists: an evening
// nobody followed up on, a message nobody answered. The demo does this at the
// top of a skip for the same reason (learn.php) — a private install that only
// ever uses this CLI has to learn too, or learning is a demo feature.
//
// BEFORE the mark below, deliberately, and the demo's worker keeps the same
// order: the settle judges the PREVIOUS span, and verdicts about hours that
// stay lived must not ride this skip's manifest and get un-judged by a rewind.
$settled = xeric_learn_settle($db, (int)$before['epoch']);

// The near side of the skip, photographed before the clock moves. Everything
// from here to the manifest commit at the bottom IS the skip, and the diff of
// the two is what makes --rewind possible (engine/rewind.php). Taken even when
// there is no --advance: the commit declines to write when the clock never
// moved, so a sweep of the current hour cannot overwrite the manifest of the
// skip that was.
$mark = xeric_rewind_mark($db);

// -- the clock --------------------------------------------------------------
$span = 0;
if (isset($args['advance'])) {
    $span = xeric_clock_span((string)$args['advance']) ?? -1;
    if ($span < 0) { fwrite(STDERR, "--advance: cannot read '{$args['advance']}' (try 6h, 90m, 2d)\n"); exit(2); }
}

if ($span > 0) {
    try {
        $after = xeric_clock_advance($db, $span, $T);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
    fwrite(STDOUT, "\n  ⏩ " . xeric_clock_span_label($span) . ', ' . xeric_sweep_cli_when($before)
        . '  →  ' . xeric_sweep_cli_when($after) . "\n");
} else {
    $after = $before;
    fwrite(STDOUT, "\n  (no --advance: sweeping the current hour only)\n");
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

// -- the sweeps -------------------------------------------------------------
// chance defaults to 1: this is an acceptance tool, and an operator who typed
// --advance=6h asked to SEE the hours, not to roll for them. Production cadence
// is XERIC_SWEEP_CHANCE and a demo's time control passes its own.
$opts = [
    'chance'      => isset($args['chance']) ? (float)$args['chance'] : 1.0,
    'max_events'  => (int)($args['sweeps'] ?? 2),
    'temperature' => isset($args['temp']) ? (float)$args['temp'] : 0.9,
    'timeout'     => (int)($args['timeout'] ?? 300),
    'stories'     => $stories,
];
if (isset($args['force'])) $opts['force'] = true;
if (isset($args['spine'])) $opts['spine'] = $args['spine'] !== 'false';

$t0 = microtime(true);
$sw = xeric_sweep_catchup($T, $db, $endpoint, $before['epoch'], $after['epoch'], $opts);
$sweepMs = (int)round((microtime(true) - $t0) * 1000);

fwrite(STDOUT, "\n  ── WHILE YOU WERE AWAY " . str_repeat('─', 46) . "\n");
if ($sw['events'] === []) {
    fwrite(STDOUT, "\n  nothing happened.\n");
} else {
    foreach ($sw['events'] as $e) {
        fwrite(STDOUT, "\n  " . xeric_sweep_cli_stamp($T, (int)$e['world_epoch']) . '   ' . strtoupper((string)$e['title']) . "\n");
        fwrite(STDOUT, '  ' . str_repeat(' ', 12) . ($e['place_name'] !== '' ? $e['place_name'] . ' · ' : '')
            . $e['kind'] . ($e['on_spine'] ? ' · touches the secret' : '')
            . '  [' . ($e['usage']['ms'] ?? 0) / 1000 . "s]\n\n");
        fwrite(STDOUT, xeric_sweep_cli_wrap((string)$e['prose'], 6, 88) . "\n");

        fwrite(STDOUT, "\n      what each of them took away from it\n");
        foreach ($e['memories'] as $h => $text) {
            $name = xeric_world_name($T, (string)$h);
            fwrite(STDOUT, '        ' . $name . ":\n" . xeric_sweep_cli_wrap($text, 10, 84) . "\n");
        }
    }
}
foreach ($sw['notes'] as $n) fwrite(STDOUT, '  · ' . $n . "\n");

// -- the ping ---------------------------------------------------------------
$ping  = null;
$notes = null;
if (!isset($args['no-ping']) && $sw['events'] !== []) {
    $popts = [
        'event'       => $sw['events'][count($sw['events']) - 1],
        'chance'      => isset($args['ping-chance']) ? (float)$args['ping-chance'] : 1.0,
        'temperature' => 0.95,
        'timeout'     => (int)($args['timeout'] ?? 300),
        'stories'     => $stories,
    ];
    if (isset($args['force'])) $popts['force'] = true;

    $t1 = microtime(true);
    try {
        $ping = xeric_proactive_check($T, $db, $endpoint, $after, $popts, $notes);
    } catch (Throwable $e) {
        fwrite(STDERR, "\n  ping failed: " . $e->getMessage() . "\n");
    }
    $pingMs = (int)round((microtime(true) - $t1) * 1000);

    fwrite(STDOUT, "\n  ── AND THEN YOUR PHONE " . str_repeat('─', 46) . "\n\n");
    if ($ping !== null) {
        fwrite(STDOUT, '  ● ' . $ping['name'] . ($ping['cold_open'] ? ' (a new thread)' : '')
            . '  ' . sprintf('[%.1fs]', $pingMs / 1000) . "\n");
        fwrite(STDOUT, xeric_sweep_cli_wrap($ping['text'], 6, 88) . "\n");
    } else {
        fwrite(STDOUT, "  nobody said anything.\n");
        foreach ((array)$notes as $n) fwrite(STDOUT, '  · ' . $n . "\n");
    }
}

// -- what this run is owed an answer to, and what it made of the last one ----
xeric_learn_pend($db, $sw['events'], $ping, (int)$after['epoch']);

$learned = [];
if (!isset($args['no-learn']) && xeric_learn_due($db)) {
    $lr = xeric_lessons_distil($db, $T, $endpoint, ['timeout' => (int)($args['timeout'] ?? 300)]);
    foreach ((array)$lr['lessons'] as $bucket => $lines) {
        foreach ((array)$lines as $l) {
            $learned[] = ($bucket === '' ? 'everybody' : xeric_world_name($T, (string)$bucket)) . ': ' . $l;
        }
    }
    if ($learned !== [] || $lr['signals'] > 0) {
        fwrite(STDOUT, "\n  ── WHAT IT MADE OF YOU " . str_repeat('─', 46) . "\n\n");
        fwrite(STDOUT, '  ' . $lr['signals'] . ' thing' . ($lr['signals'] === 1 ? '' : 's')
            . ' you did, read once: ' . $settled['engaged'] . ' followed up, ' . $settled['skipped']
            . ' walked past, ' . $settled['ignored'] . " left waiting\n");
        foreach ($learned as $l) fwrite(STDOUT, '  · ' . $l . "\n");
        foreach ((array)$lr['notes'] as $n) fwrite(STDOUT, '  · ' . $n . "\n");
    }
}

// -- the way back, written down ----------------------------------------------
// After every write this run makes — the sweeps, the ping, the pend, the
// distil — so the manifest's diff names all of it. A run with no --advance
// never moved the clock and the commit declines to write (see rewind.php).
try { xeric_rewind_commit($db, $mark); } catch (Throwable $e) { /* the hours still happened */ }

// -- the ledger -------------------------------------------------------------
fwrite(STDOUT, "\n  " . count($sw['events']) . ' event' . (count($sw['events']) === 1 ? '' : 's')
    . ' in ' . sprintf('%.1fs', $sweepMs / 1000)
    . ($sw['events'] ? sprintf(' (%.1fs each)', $sweepMs / 1000 / count($sw['events'])) : '')
    . ' · ' . xeric_events_count($db) . ' total · ' . xeric_memories_count($db) . ' memories · '
    . xeric_conversation_unread_total($db) . " unread\n\n");

exit(0);

// ---------------------------------------------------------------------------

/** --flag, --key=value. Everything else is ignored. */
function xeric_sweep_cli_args(array $argv): array
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

/** "Thursday evening, 19:47" */
function xeric_sweep_cli_when(array $now): string
{
    return xeric_world_day_name((int)($now['dow'] ?? 0)) . ' ' . (string)($now['phase'] ?? '')
        . ', ' . (string)($now['hhmm'] ?? '');
}

/** "Fri 01:12", in the world's timezone. */
function xeric_sweep_cli_stamp(array $t, int $epoch): string
{
    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    return (new DateTimeImmutable('@' . $epoch))->setTimezone($tz)->format('D H:i');
}

/** Prose, indented and wrapped, so a transcript is readable in a terminal. */
function xeric_sweep_cli_wrap(string $s, int $indent, int $width): string
{
    $pad  = str_repeat(' ', $indent);
    $out  = [];
    foreach (explode("\n", wordwrap(trim($s), $width - $indent, "\n", false)) as $line) {
        $out[] = $pad . $line;
    }
    return implode("\n", $out);
}

function xeric_sweep_cli_usage(): string
{
    return <<<TXT
    php engine/sweep-cli.php --world=DIR --advance=6h [--sweeps=N]

      --world=DIR          a world directory (world-template.json [+ seed.json])
      --advance=SPAN       move the world forward: 6h, 90m, 2d (max 7d per call)
      --sweeps=N           at most N events out of the hours that went by (default 2)
      --rewind             take back the LAST skip, whole: its events, memories and
                           messages un-happen and the clock returns; the hours are
                           sweepable again and may go differently the second time
      --status             print the clock and the last few events, change nothing
      --reset-clock        put the world back on real time
      --reset              delete the world db and start the world over

      --chance=N           per-hour cadence, 0..1 (default 1 here, 0.35 in production)
      --ping-chance=N      how likely somebody texts about it (default 1)
      --no-ping            sweep only, do not let anybody open a thread
      --no-learn           do not distil what you have been doing into lessons
      --force              ignore the window guards (a demo wanting a second one)
      --spine=true|false   force the event onto / off the world's protected secret

      --db=PATH            default <world>/world.db
      --temp=N             sampling temperature (default 0.9)
      --timeout=SECS       default 300

      --base=URL           any OpenAI-compatible endpoint (default 127.0.0.1:8080)
      --kind=local|openai|anthropic
      --model=NAME  --key=SECRET   (or set XERIC_API_KEY)

    TXT;
}
