<?php
/**
 * forge-cli.php — the forge without a UI. This is the acceptance test for the
 * whole vertical slice: answers in, a validated world on disk, out loud.
 *
 *   php forge/forge-cli.php --local
 *   php forge/forge-cli.php --local --surprise
 *   php forge/forge-cli.php --local --answers=scale=city,name=Ada,job=tend bar,motivation=romance
 *   php forge/forge-cli.php --base=https://api.openai.com/v1 --key=sk-… --model=gpt-…
 *
 * --local means the llama.cpp server on this box (127.0.0.1:8080). It is
 * checked with xeric_llm_up() BEFORE any work happens, because a forge that
 * discovers the model is down four passes in has wasted the user's minutes.
 *
 * Progress notes print as passes run — a local 12B takes minutes, and silence
 * for minutes is indistinguishable from a hang.
 */

declare(strict_types=1);

// A TERMINAL PROGRAM, AND ONLY THAT. The deploy puts this whole tree inside the
// docroot (web/lib/), where a host with register_argc_argv on builds $argv out
// of the query string — which turns this file into a world builder anybody can
// drive from a URL, spending the machine's GPU and writing worlds nobody asked
// for. The .htaccess denies lib/ as well; that one depends on a server being
// configured the way we left it, and this one does not.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/forge.php';

$args = xeric_cli_args(array_slice($argv, 1));

if (isset($args['help']) || isset($args['h'])) {
    fwrite(STDOUT, xeric_cli_usage());
    exit(0);
}

// -- endpoint ---------------------------------------------------------------
$endpoint = [
    'kind'  => (string)($args['kind'] ?? 'local'),
    'base'  => (string)($args['base'] ?? ''),
    'model' => (string)($args['model'] ?? ''),
    'key'   => (string)($args['key'] ?? getenv('XERIC_API_KEY') ?: ''),
];
if (isset($args['local']) || $endpoint['base'] === '') {
    $endpoint['kind'] = 'local';
    $endpoint['base'] = $endpoint['base'] !== '' ? $endpoint['base'] : 'http://127.0.0.1:8080';
}

fwrite(STDOUT, "xeric forge\n");
fwrite(STDOUT, '  endpoint : ' . $endpoint['base'] . ' (' . $endpoint['kind'] . ")\n");
fwrite(STDOUT, "  checking : ");
if (!xeric_llm_up($endpoint)) {
    fwrite(STDOUT, "no answer\n\n");
    fwrite(STDERR, "The model at {$endpoint['base']} is not answering.\n"
        . "Start it (llama-server --host 127.0.0.1 --port 8080 …) or pass --base=…\n");
    exit(1);
}
fwrite(STDOUT, "up\n");

// -- answers ----------------------------------------------------------------
$answers = xeric_cli_answers((string)($args['answers'] ?? ''));

// SURPRISE ME IS SFW, HERE TOO. An unanswered rating is a GAP, and the routes
// that fill gaps will happily choose for you — the concept table alone asks for
// `mature` in four of its five rows. The web wizard closes this by pre-selecting
// the weakest rating beside its ✨ button; there is no screen out here to put a
// selector on, so the default IS the selector.
//
// It is not a ceiling and it does not gate anything: this is a local tool run by
// whoever owns the machine, and `--rating=mature` (or `--answers=rating=mature`)
// takes effect immediately with nothing to affirm. What it refuses to do is pick
// an adult world for somebody who never said one word about it.
$ratingArg = strtolower(trim((string)($args['rating'] ?? '')));
if ($ratingArg !== '') {
    if (!in_array($ratingArg, xeric_ratings(), true)) {
        fwrite(STDERR, 'forge-cli: --rating must be one of ' . implode(', ', xeric_ratings()) . "\n");
        exit(2);
    }
    $answers['rating'] = $ratingArg;
}
if (!isset($answers['rating']) || trim((string)$answers['rating']) === '') {
    $answers['rating'] = xeric_ratings()[0];
}
$opts = [
    'places' => (int)($args['places'] ?? 6),
    // TWELVE, NOT FOUR (owner, 2026-08-02). Four people is a writers' room, not
    // a town: every sweep draws from the same three pairings and the world runs
    // out of strangers in a week. Twelve gives the sweeps real choice and makes
    // "two of them talked about you" plausible. The cost is honest — a longer
    // forge and a bigger bible — and it is paid once, at build time.
    'cast'   => (int)($args['cast'] ?? 12),
    'seed'   => !isset($args['no-seed']),
    // ✨ surprise-me asks the model for ONE coherent set; without the flag the
    // gaps are filled from interview.json's hand-written concepts.
    'fill'   => isset($args['surprise']) ? 'model' : 'presets',
];
$worldsDir = (string)($args['worlds'] ?? dirname(__DIR__) . '/worlds');

fwrite(STDOUT, '  answers  : ' . ($answers ? xeric_cli_kv($answers) : '(none — everything gets filled)') . "\n");
fwrite(STDOUT, '  filling  : ' . $opts['fill'] . ($opts['fill'] === 'model' ? ' (✨ surprise me)' : '') . "\n\n");

// -- forge ------------------------------------------------------------------
$t0 = microtime(true);
$out = xeric_forge_build($answers, $endpoint, $opts, function (string $note) use ($t0): void {
    fwrite(STDOUT, sprintf("  [%5.1fs] %s\n", microtime(true) - $t0, $note));
});
$secs = microtime(true) - $t0;

$template = $out['template'];
$seed = $out['seed'];
$path = xeric_forge_write($template, $seed, $worldsDir);

// -- what we made -----------------------------------------------------------
fwrite(STDOUT, "\n" . str_repeat('─', 72) . "\n");
fwrite(STDOUT, $template['meta']['name'] . ' — ' . $template['meta']['description'] . "\n");
fwrite(STDOUT, '  ' . $template['setting']['locale'] . ', ' . $template['setting']['era']
    . '  ·  rating ' . $template['meta']['rating']
    . '  ·  themes ' . implode(', ', (array)$template['meta']['themes']) . "\n");
fwrite(STDOUT, '  you: ' . $template['user']['name'] . ', ' . $template['user']['occupation']['title']
    . ' at ' . xeric_world_place_name($template, $template['user']['occupation']['workplace_key'])
    . ' (' . $template['user']['occupation']['hours'] . ')'
    . '  ·  here for ' . $template['user']['motivation'] . "\n");
fwrite(STDOUT, '  arms: ' . implode(', ', (array)$template['forge']['armed']) . "\n");

fwrite(STDOUT, "\nPLACES (" . count($template['places']) . ")\n");
foreach ($template['places'] as $p) {
    $mine = !empty($p['user_workplace']) ? '  ← yours' : '';
    fwrite(STDOUT, sprintf("  %-18s %s (%s)%s\n", $p['key'], $p['name'], $p['kind'], $mine));
}

fwrite(STDOUT, "\nCAST (" . count($template['cast']['characters']) . ")\n");
foreach ($template['cast']['characters'] as $c) {
    fwrite(STDOUT, sprintf("  %-14s %s, %s — %s\n", $c['handle'], $c['display_name'], $c['age'], $c['one_line']));
    $shifts = [];
    foreach ((array)$c['week'] as $w) {
        $shifts[] = xeric_days_phrase((array)$w['days']) . ' ' . ($w['from'] ?? '') . '-' . ($w['to'] ?? '')
            . ' @ ' . xeric_world_place_name($template, (string)($w['where'] ?? ''));
    }
    fwrite(STDOUT, '                 ' . implode('; ', $shifts) . "\n");
}

fwrite(STDOUT, "\nSEED (" . count($seed['events']) . ' events, ' . count($seed['memories']) . " memories)\n");
foreach ($seed['events'] as $e) {
    fwrite(STDOUT, sprintf("  %2dd ago  %s @ %s\n", $e['days_ago'], $e['title'],
        xeric_world_place_name($template, (string)($e['place'] ?? '')) ?: 'nowhere in particular'));
}

$fallbacks = array_values(array_filter($out['notes'], fn($n) => str_contains($n, 'built-in default') || str_contains($n, 'repair')));
fwrite(STDOUT, "\nHOW IT WENT\n");
fwrite(STDOUT, '  ' . count($out['notes']) . ' notes, ' . count($fallbacks) . " fallbacks/repairs\n");
foreach ($fallbacks as $f) fwrite(STDOUT, '  ! ' . $f . "\n");
fwrite(STDOUT, sprintf("  %.1fs total\n", $secs));
fwrite(STDOUT, '  written: ' . $path . "\n");

// The world the engine will load is the one on disk, so prove THAT one loads.
try {
    xeric_world_load($path);
    fwrite(STDOUT, "  validates: yes\n");
} catch (Throwable $e) {
    fwrite(STDERR, '  validates: NO — ' . $e->getMessage() . "\n");
    exit(2);
}
exit(0);

// ---------------------------------------------------------------------------

/** --flag, --key=value, -h. Everything else is ignored. */
function xeric_cli_args(array $argv): array
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

/**
 * --answers=scale=city,name=Ada,job=tend bar
 *
 * Comma-separated pairs. A value containing a comma is not expressible here on
 * purpose — this is a test runner, not the interview.
 */
function xeric_cli_answers(string $s): array
{
    $out = [];
    foreach (explode(',', $s) as $pair) {
        $pair = trim($pair);
        if ($pair === '' || !str_contains($pair, '=')) continue;
        [$k, $v] = explode('=', $pair, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === '') continue;
        $out[$k] = ($k === 'themes') ? array_map('trim', explode('|', $v)) : $v;
    }
    return $out;
}

function xeric_cli_kv(array $a): string
{
    $out = [];
    foreach ($a as $k => $v) $out[] = $k . '=' . (is_array($v) ? implode('|', $v) : (string)$v);
    return implode(' ', $out);
}

function xeric_cli_usage(): string
{
    return <<<TXT
    php forge/forge-cli.php [options]

      --local              use the llama.cpp server at 127.0.0.1:8080 (default)
      --base=URL           any OpenAI-compatible endpoint
      --kind=local|openai|anthropic
      --model=NAME         model id for hosted endpoints
      --key=SECRET         api key (or set XERIC_API_KEY)

      --answers=k=v,k=v    scale, name, job, hours, motivation, rating, themes (a|b|c)
      --rating=sfw|mature|explicit   how far this xeric may go (default sfw)
      --surprise           ask the model to fill the unanswered steps (✨)
      --places=N           how many places (6 for the slice)
      --cast=N             how many people (default 12; 4 makes a quick test world)
      --no-seed            skip the seed-history pass
      --worlds=DIR         where to write (default ./worlds)

    TXT;
}
