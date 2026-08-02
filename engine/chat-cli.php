<?php
/**
 * chat-cli.php — one turn against a real world and a real model. No web app.
 *
 *   php engine/chat-cli.php --world=worlds/port-saltwater --speaker=elias_thorne --say="you around?"
 *   php engine/chat-cli.php --world=worlds/port-saltwater --cast
 *   php engine/chat-cli.php --world=worlds/port-saltwater --speaker=elias_thorne --extract
 *   php engine/chat-cli.php --world=worlds/port-saltwater --speaker=elias_thorne --memories
 *
 * This is the acceptance test for the chat turn the way forge-cli.php is for the
 * forge: if a forged character does not sound like a person here, no amount of
 * web UI will fix it.
 *
 * The world's SQLite file lives next to its template (world.db, gitignored with
 * every other lived world). The first run seeds it — arcs from the template,
 * history from seed.json — and says so; every later run is a turn in a world
 * that already happened.
 */

declare(strict_types=1);

// A TERMINAL PROGRAM, AND ONLY THAT. The deploy puts the engine inside the
// docroot (web/lib/engine/), where a host with register_argc_argv on builds
// $argv out of the query string — which turns this file into a chat turn anybody
// can drive from a URL, against any world on the disk and at the machine's
// expense. The .htaccess denies lib/ as well; that one depends on a server being
// configured the way we left it, and this one does not.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/story.php';    // the overlays beside the template, if there are any

$args = xeric_chat_cli_args(array_slice($argv, 1));

if (isset($args['help']) || isset($args['h'])) {
    fwrite(STDOUT, xeric_chat_cli_usage());
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
// --epoch takes a unix time or anything strtotime reads, so a run can be pinned
// to a Friday night without waiting for one.
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
// The raw overlays go to the turn (they are what watches the exchange) and the
// COMPOSED template goes to the prompt (it is what puts a held piece and a wrong
// lead in somebody's own voice). One without the other is a character who has
// nothing to say about it, or one who says it and is never heard.
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
    $presence = xeric_world_who_is_where($T, $now);
    foreach ($T['cast']['characters'] as $c) {
        $h     = (string)$c['handle'];
        $where = xeric_world_place_name($T, $presence[$h]['where'] ?? null);
        fwrite(STDOUT, sprintf("  %-16s %-16s %-22s %s\n", $h, $c['display_name'],
            $where !== '' ? $where : '(off shift)', (string)($c['one_line'] ?? '')));
    }
    exit(0);
}

$speaker = (string)($args['speaker'] ?? '');
if ($speaker === '') {
    fwrite(STDERR, "--speaker is required (try --cast)\n");
    exit(2);
}

// -- what she remembers -----------------------------------------------------
if (isset($args['memories'])) {
    $tz = new DateTimeZone((string)($T['user']['timezone'] ?? 'UTC'));
    foreach (xeric_memories_for($db, $speaker, 50) as $m) {
        $when = (int)($m['world_epoch'] ?? $m['created_at']);
        fwrite(STDOUT, sprintf("  %-10s %-6s %s\n",
            (new DateTimeImmutable('@' . $when))->setTimezone($tz)->format('D j M'),
            (string)$m['source'], (string)$m['text']));
    }
    fwrite(STDOUT, '  (' . xeric_memories_count($db, $speaker) . " total)\n");
    if (!isset($args['say']) && !isset($args['extract'])) exit(0);
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

// -- the turn ---------------------------------------------------------------
if (isset($args['say'])) {
    $said = (string)$args['say'];
    fwrite(STDOUT, "\n  " . (string)($T['user']['name'] ?? 'you') . ': ' . $said . "\n");

    try {
        $out = xeric_chat_turn($T, $db, $speaker, $said, $now, $endpoint, [
            'temperature' => isset($args['temp']) ? (float)$args['temp'] : 0.85,
            'timeout'     => (int)($args['timeout'] ?? 180),
            'stories'     => $stories,
        ]);
    } catch (Throwable $e) {
        fwrite(STDERR, "\n" . $e->getMessage() . "\n");
        exit(1);
    }

    $name = xeric_chat_speaker_name($T, $speaker) ?? $speaker;
    foreach (explode("\n", $out['text']) as $i => $line) {
        fwrite(STDOUT, '  ' . ($i === 0 ? $name . ': ' : str_repeat(' ', mb_strlen($name) + 2)) . $line . "\n");
    }
    $u = $out['usage'];
    fwrite(STDOUT, sprintf("  [%.1fs · %s prompt / %s reply tokens · %d chars]\n",
        ($u['ms'] ?? 0) / 1000, (string)($u['prompt_tokens'] ?? '?'),
        (string)($u['completion_tokens'] ?? '?'), (int)($u['reply_chars'] ?? 0)));

    // -- and what the story made of it --------------------------------------
    // Progress only. The piece itself was in what she just said, which is the
    // whole point — this says that she said it, never what it was.
    $st = (array)($out['story'] ?? []);
    foreach ((array)($st['opened'] ?? []) as $k)  fwrite(STDOUT, "  ~ she is willing to talk about $k now\n");
    foreach ((array)($st['spilled'] ?? []) as $k) fwrite(STDOUT, "  ~ she told you $k, and she knows she did\n");
    // The wrong lead is dead and the world may say so out loud. Printed here
    // because a terminal has no scene to put it in; a play view has.
    foreach ((array)($st['said'] ?? []) as $line) fwrite(STDOUT, '  (' . $line . ")\n");
    foreach ((array)($st['resolved'] ?? []) as $r) {
        fwrite(STDOUT, '  ~ you named ' . xeric_world_name($T, (string)$r['named']) . ', '
            . (string)$r['why'] . (!empty($r['costs']) ? '; ' . (string)$r['costs'] : '') . "\n");
    }
}

// -- the harvest ------------------------------------------------------------
if (isset($args['extract'])) {
    $conv = xeric_conversation_find($db, $speaker, 'chat');
    if (!$conv) { fwrite(STDERR, "nothing to harvest, no conversation with $speaker yet\n"); exit(1); }

    $before = xeric_memories_count($db, $speaker);
    try {
        $kept = xeric_chat_extract($T, $db, $speaker, (int)$conv['id'], $endpoint, $now);
    } catch (Throwable $e) {
        fwrite(STDERR, "\n" . $e->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, "\n  harvested: $kept new " . ($kept === 1 ? 'memory' : 'memories') . "\n");
    foreach (array_slice(xeric_memories_for($db, $speaker, 50), $before) as $m) {
        fwrite(STDOUT, '  + ' . (string)$m['text'] . "\n");
    }
}

exit(0);

// ---------------------------------------------------------------------------

/** --flag, --key=value. Everything else is ignored. */
function xeric_chat_cli_args(array $argv): array
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

function xeric_chat_cli_usage(): string
{
    return <<<TXT
    php engine/chat-cli.php --world=DIR --speaker=HANDLE --say="…"

      --world=DIR          a world directory (world-template.json [+ seed.json])
      --speaker=HANDLE     who you are talking to  (--cast lists them)
      --say="…"            what you say
      --extract            harvest memories from the thread afterwards
      --memories           print what this character remembers
      --cast               print the cast and where they are right now
      --reset              delete the world db and start the world over
      --epoch=WHEN         unix time, or anything strtotime reads
      --db=PATH            default <world>/world.db
      --temp=N             sampling temperature (default 0.85)
      --timeout=SECS       default 180

      --base=URL           any OpenAI-compatible endpoint (default 127.0.0.1:8080)
      --kind=local|openai|anthropic
      --model=NAME  --key=SECRET   (or set XERIC_API_KEY)

    TXT;
}
