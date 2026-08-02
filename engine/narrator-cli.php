<?php
/**
 * narrator-cli.php — ask the world why. The author's console for ASK,
 * INVESTIGATE and WRITE AHEAD.
 *
 *   php engine/narrator-cli.php --world=worlds/port-saltwater --ask="why has Maren gone quiet?"
 *   php engine/narrator-cli.php --world=worlds/port-saltwater --context
 *   php engine/narrator-cli.php --world=worlds/port-saltwater --investigate --days=5
 *   php engine/narrator-cli.php --world=worlds/port-saltwater --intend="Maren finds the letter"
 *   php engine/narrator-cli.php --world=worlds/port-saltwater --intents
 *
 * This is the acceptance surface for the narrator the way chat-cli.php is for
 * the chat turn: if the world cannot explain itself here, no web panel will
 * make it articulate.
 *
 * DELIBERATELY MISSING, next to chat-cli:
 *   --reset   A tool whose contract on asking is "changes nothing" does not
 *             ship a flag that deletes the world. If you want the world gone,
 *             that is a decision to make somewhere that admits it makes
 *             decisions.
 *   seeding   chat-cli seeds a fresh world because a first conversation needs
 *             arcs to move. The narrator moves nothing; against an unseeded
 *             world it reads empty ledgers and says so, which is the truth.
 *
 * Asking and auditing write nothing. The write-ahead flags are the deliberate
 * exception and they write exactly one shape of row — an intent, in
 * world_state — which no prompt ever reads (engine/narrator.php, the third
 * power's banner). Beyond that, the one write this program performs is opening
 * the SQLite file, which will create an empty, schema-only ledger where none
 * exists. An empty ledger contains nothing and changes no answer.
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

// -- write ahead: record, list, retire ---------------------------------------
// The third power. An intent is a pull, never a script: the sweeps lean toward
// hours that could realize it, and nothing is ever ordered. Its words print
// HERE, to the owner who wrote them, and reach no prompt anywhere.
if (isset($args['intend'])) {
    $iopts = ['epoch' => (int)$now['epoch']];
    if (isset($args['fade-days'])) $iopts['fade_days'] = (int)$args['fade-days'];
    try {
        $made = xeric_narrator_intend($T, $db, (string)$args['intend'], $iopts);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(2);
    }
    $in = $made['intent'];
    fwrite(STDOUT, "\n  intent #" . $in['n'] . ' — "' . $in['text'] . "\"\n");
    fwrite(STDOUT, '  ' . xeric_narrator_cli_intent_reading($T, $in) . "\n");
    fwrite(STDOUT, '  It fades ' . xeric_narrator_date($T, (int)$in['fades'])
        . " if the world never finds room, and comes back to you in the audit.\n");
    foreach ($made['notes'] as $note) fwrite(STDOUT, '  note: ' . $note . "\n");
    exit(0);
}

if (isset($args['intents'])) {
    $settled = xeric_narrator_intents_settle($T, $db);
    fwrite(STDOUT, "\n" . xeric_narrator_cli_intents($T, xeric_narrator_intents($db),
        $settled['notes'], (int)$now['epoch']) . "\n");
    exit(0);
}

if (isset($args['retire'])) {
    $n    = (int)$args['retire'];
    $gone = xeric_narrator_intent_retire($db, $n, (int)$now['epoch']);
    if ($gone === null) {
        fwrite(STDERR, "--retire: there is no live intent #$n (see --intents)\n");
        exit(2);
    }
    fwrite(STDOUT, "\n  intent #$n retired — \"" . $gone['text'] . "\". The world stops leaning.\n");
    exit(0);
}

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

// -- the audit ---------------------------------------------------------------
// The second power. Deterministic checks always; the transcript read rides
// the endpoint unless --no-model asks for arithmetic alone. Grouped by kind,
// every line carrying the row ids it stands on — the citations are the
// assembly's, and for the model's findings they are VALIDATED, never taken
// on its word (engine/narrator.php, the investigate banner).
if (isset($args['investigate'])) {
    $days  = max(1, (int)($args['days'] ?? 3));
    $iopts = ['now' => $now, 'days' => $days, 'timeout' => (int)($args['timeout'] ?? 180)];
    if (isset($args['events']))   $iopts['events']   = (int)$args['events'];
    if (isset($args['memories'])) $iopts['memories'] = (int)$args['memories'];

    try {
        $audit = xeric_narrator_investigate($T, $db,
            isset($args['no-model']) ? null : xeric_narrator_cli_endpoint($args), $iopts);
    } catch (Throwable $e) {
        fwrite(STDERR, "\n" . $e->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDOUT, "\n" . xeric_narrator_cli_audit($audit, $days) . "\n");
    exit(0);
}

$question = trim((string)($args['ask'] ?? ''));
if ($question === '') {
    fwrite(STDERR, "--ask=\"…\" is required (or --context, or --investigate)\n");
    exit(2);
}

$endpoint = xeric_narrator_cli_endpoint($args);

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

/** The endpoint, from flags. Local :8080 when nothing names another. */
function xeric_narrator_cli_endpoint(array $args): array
{
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
    return $endpoint;
}

/**
 * The audit, grouped by kind, every observation over its citations. The
 * headers are the report's own vocabulary; an empty audit says so in the
 * narrator's voice rather than printing six empty shelves.
 */
function xeric_narrator_cli_audit(array $out, int $days): string
{
    $heads = [
        'unspoken'         => 'NOT SPOKEN TO IN ' . $days . ' WORLD-DAYS',
        'dropped_question' => 'QUESTIONS ASKED, NEVER ANSWERED',
        'unpaid_debt'      => 'DEBTS OPEN PAST THEIR FADE',
        'faded_intent'     => 'INTENDED BEATS THE WORLD NEVER FOUND ROOM FOR',
        'idle_pressure'    => 'PRESSURE PRODUCING NOTHING',
        'never_lived'      => 'SEEDED, NEVER LIVED',
        'contradiction'    => 'WHAT THE RECORD CONTRADICTS',
    ];

    $lines = [];
    if ($out['observations'] === []) {
        $lines[] = '  Nothing to report. The record is keeping up with itself.';
    }
    $cur = '';
    foreach ($out['observations'] as $o) {
        if ($o['kind'] !== $cur) {
            if ($cur !== '') $lines[] = '';
            $lines[] = '  ' . ($heads[$o['kind']] ?? strtoupper((string)$o['kind']));
            $cur = (string)$o['kind'];
        }
        $lines[] = '  - ' . $o['text'];
        $c = xeric_narrator_cli_cites((array)$o['cites']);
        if (($o['found_by'] ?? 'code') === 'model') {
            $c .= ($c !== '' ? '; ' : '') . 'read by the model';
        }
        if ($c !== '') $lines[] = '      [' . $c . ']';
    }

    $lines[] = '';
    $m = (array)($out['model'] ?? []);
    if (!empty($m['asked'])) {
        $lines[] = '  the model read ' . count((array)($out['sources']['messages'] ?? []))
            . ' thread(s): ' . (int)$m['claims'] . ' claim(s), ' . (int)$m['kept'] . ' kept, '
            . (int)$m['dropped'] . ' dropped'
            . (isset($m['usage']['ms']) ? sprintf(' [%.1fs]', ((int)$m['usage']['ms']) / 1000) : '');
    } else {
        $lines[] = '  no model was asked; every line above is arithmetic.';
    }
    $lines[] = '  ' . xeric_narrator_cli_sources((array)($out['sources'] ?? []));
    return implode("\n", $lines);
}

/** One observation's citations, in the record's own ids. */
function xeric_narrator_cli_cites(array $c): string
{
    $bits = [];
    foreach ((array)($c['events'] ?? []) as $id)   $bits[] = 'event #' . (int)$id;
    foreach ((array)($c['messages'] ?? []) as $id) $bits[] = 'message #' . (int)$id;
    foreach ((array)($c['memories'] ?? []) as $id) $bits[] = 'memory #' . (int)$id;
    foreach ((array)($c['arcs'] ?? []) as $a)      $bits[] = 'arc ' . str_replace(':', '/', (string)$a);
    foreach ((array)($c['intents'] ?? []) as $n)   $bits[] = 'intent #' . (int)$n;
    foreach ((array)($c['deaths'] ?? []) as $h)    $bits[] = 'the deaths ledger (' . (string)$h . ')';
    return implode('; ', $bits);
}

/**
 * How an intent reads to the machine: the hints code parsed out of its words,
 * never a guess. This is what the lean will actually use, so it is what the
 * owner should see confirmed.
 */
function xeric_narrator_cli_intent_reading(array $t, array $in): string
{
    $bits   = [];
    $people = (array)($in['participants'] ?? []);
    if ($people !== []) {
        $names = [];
        foreach ($people as $h) $names[] = xeric_world_name($t, (string)$h);
        $bits[] = xeric_join_list($names);
    }
    if (($in['place'] ?? null) !== null && (string)$in['place'] !== '') {
        $bits[] = 'at ' . xeric_world_place_name($t, (string)$in['place']);
    }
    if (($in['kind'] ?? null) !== null && (string)$in['kind'] !== '') {
        $bits[] = 'a ' . str_replace('_', ' ', (string)$in['kind']) . ' hour';
    }
    return $bits === []
        ? 'reads loose: no cast, place or kind — only its own words can realize it'
        : 'reads as: ' . implode('; ', $bits) . '. The sweeps lean; nothing is ordered.';
}

/**
 * The intent ledger, owner-facing. The ONE surface (with the trail's numbers
 * one step behind it) where an intent is spoken of at all, and the only one
 * that prints its words. A faded state is derived, never stored: live past its
 * fade IS faded, the same arithmetic the leans and the audit use.
 */
function xeric_narrator_cli_intents(array $t, array $rows, array $notes, int $epoch): string
{
    $lines = [];
    foreach ($notes as $n) $lines[] = '  ' . $n;
    if ($notes !== []) $lines[] = '';

    if ($rows === []) {
        $lines[] = '  No intended beats. The world is running on its own weather.';
        return implode("\n", $lines);
    }

    $lines[] = '  INTENDED BEATS (the world leans toward these; it is never ordered)';
    foreach ($rows as $n => $in) {
        $state = (string)($in['state'] ?? 'live');
        if ($state === 'live' && $epoch >= (int)($in['fades'] ?? 0)) {
            $head = 'faded ' . xeric_narrator_date($t, (int)$in['fades'])
                  . ' — the world never found room';
        } elseif ($state === 'live') {
            $head = 'live, fades ' . xeric_narrator_date($t, (int)$in['fades']);
        } elseif ($state === 'done') {
            $head = 'realized by event #' . (int)($in['done']['event'] ?? 0)
                  . ', matched on ' . (string)($in['done']['by'] ?? '');
        } else {
            $head = 'retired';
        }
        $lines[] = '  #' . (int)$n . '  ' . $head . ' — "' . (string)($in['text'] ?? '') . '"';
        $lines[] = '      ' . xeric_narrator_cli_intent_reading($t, $in);
    }
    return implode("\n", $lines);
}

/**
 * The citations line: what the answer was drawn from, from the assembly
 * manifest rather than from the model's mouth — a model asked to cite invents.
 * Serves both manifests: ASK's (which opens with the bible) and the audit's
 * (which never renders it, and says so by leaving it out).
 */
function xeric_narrator_cli_sources(array $s): string
{
    $bits = !empty($s['bible']) ? ['the bible'] : [];
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
    $ms = (array)($s['messages'] ?? []);
    if ($ms !== []) {
        $n = array_sum(array_map('count', $ms));
        $bits[] = $n . ' transcript line' . ($n === 1 ? '' : 's');
    }
    $ar = (array)($s['arcs'] ?? []);
    if ($ar !== []) $bits[] = count($ar) . ' ledger arc' . (count($ar) === 1 ? '' : 's');
    $it = (array)($s['intents'] ?? []);
    if ($it !== []) $bits[] = count($it) . ' intended beat' . (count($it) === 1 ? '' : 's');
    $st = (array)($s['stories'] ?? []);
    if ($st !== []) $bits[] = count($st) . ' stor' . (count($st) === 1 ? 'y' : 'ies') . ' on the shelf';
    $dd = (array)($s['deaths'] ?? []);
    if ($dd !== []) $bits[] = 'the deaths ledger';
    return 'drawn from: ' . ($bits !== [] ? implode('; ', $bits) : 'an empty ledger');
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
      --investigate        audit the lived record: dropped threads, the unheard-from,
                           debts past their fade, idle pressure, the seeded-never-lived,
                           and what the record contradicts. Observations, not fixes.
      --days=N             the audit's window in world-days (default 3)
      --no-model           audit by arithmetic alone; skip the transcript read
      --intend="…"         record an intended beat: a pull the sweeps lean toward,
                           never a script. Who, where and what kind of hour are
                           read from the words by code; loose is allowed.
      --intents            list intended beats, settling any the record realized
      --retire=N           retire intent #N; the world stops leaning
      --fade-days=N        how long an intent waits before it fades (default 14)
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
