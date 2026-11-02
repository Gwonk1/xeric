<?php
/**
 * tools/sleep.php — one night's sleep on a world, and what it did (docs/SLEEP.md).
 *
 *     php tools/sleep.php --world=worlds/milldale                 tonight, if it is night there
 *     php tools/sleep.php --world=worlds/milldale --force         now, whatever the hour
 *     php tools/sleep.php --world=worlds/milldale --force --dry-run   dispose, print, write nothing
 *     php tools/sleep.php --world=worlds/milldale --handle=dot,ruth
 *     php tools/sleep.php --world=worlds/milldale --facts[=dot]   what has been consolidated so far
 *     php tools/sleep.php --world=worlds/milldale --chain=12      one fact and every memory it stands for
 *
 * --world is a directory holding world-template.json (and world.db), or the
 * template itself; --db names another database. The model is the CLI default
 * (XERIC_LOCAL_BASE) or --base / --model / --key / --kind, the same flags
 * every engine CLI takes. A costly model is refused unless the template says
 * `sleep.remote: true`, and the world's budget is asked first, as the heart's
 * own pass would.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../engine/clock.php';
require_once __DIR__ . '/../engine/llm.php';
require_once __DIR__ . '/../engine/sleep.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { fwrite(STDERR, "sleep: unknown argument $a\n"); exit(2); }
    $args[$m[1]] = $m[2] ?? true;
}
if (isset($args['help']) || !isset($args['world'])) {
    fwrite(isset($args['help']) ? STDOUT : STDERR, "usage: php tools/sleep.php --world=DIR|TEMPLATE [--db=PATH] [--force] [--dry-run] "
        . "[--handle=a,b] [--facts[=handle]] [--chain=ID] [--base=URL --model=M --key=K --kind=local|openai|anthropic]\n");
    exit(isset($args['help']) ? 0 : 2);
}

$w   = (string)$args['world'];
$w   = $w[0] === '/' ? $w : getcwd() . '/' . $w;
$dir = is_dir($w) ? rtrim($w, '/') : dirname($w);
try { $T = xeric_world_load(is_dir($w) ? $dir . '/world-template.json' : $w); }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(2); }
$db  = xeric_state_open((string)($args['db'] ?? $dir . '/world.db'));
$now = xeric_clock_now($db, $T);

$cfg = xeric_sleep_config($T);
printf("%s — %s %s; sleeps after %s days, in %s%s\n", (string)($T['meta']['name'] ?? basename($dir)), $now['iso'], $now['hhmm'],
    rtrim(rtrim(number_format($cfg['after_days'], 2, '.', ''), '0'), '.'), $cfg['window'], $cfg['enabled'] ? '' : ' (disabled)');

$tz   = new DateTimeZone((string)($T['user']['timezone'] ?? 'UTC'));
$date = fn(int $e) => (new DateTimeImmutable('@' . $e))->setTimezone($tz)->format('D j M Y');

if (isset($args['chain'])) {
    $c = xeric_sleep_chain($db, (int)$args['chain']);
    if ($c === null) { fwrite(STDERR, "no fact #" . (int)$args['chain'] . "\n"); exit(1); }
    $f = $c['fact'];
    printf("\nfact #%d  %s  (since %s, night of %s)\n  %s\n  about: %s\n  grade: %s\n", $f['id'], $f['handle'], $date((int)$f['since']), $f['night'],
        $f['text'], implode(', ', $f['about']), json_encode($f['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    foreach ($c['sources'] as $s) printf("  <- memory #%d (%s, %s) %s\n", $s['id'], $s['source'], $date((int)($s['world_epoch'] ?? $s['created_at'])), $s['text']);
    foreach ($c['missing'] as $id) printf("  <- memory #%d MISSING\n", $id);
    exit(0);
}

if (isset($args['facts'])) {
    $who = is_string($args['facts']) ? [$args['facts']] : xeric_sleep_cast($T, $db);
    foreach ($who as $h) {
        $fs = xeric_sleep_facts($db, $h);
        printf("\n%s: %d facts standing for %d memories\n", $h, count($fs), array_sum(array_column($fs, 'sources')));
        foreach ($fs as $f) printf("  #%d (since %s, %d) %s\n", $f['id'], $date((int)$f['since']), $f['sources'], $f['text']);
    }
    exit(0);
}

$opts = ['force' => isset($args['force']), 'dry_run' => isset($args['dry-run'])];
if (isset($args['handle'])) $opts['handles'] = array_filter(array_map('trim', explode(',', (string)$args['handle'])));
$ep = null;
foreach (['base', 'model', 'key', 'kind', 'local'] as $k) if (isset($args[$k])) { $ep = xeric_llm_cli_endpoint($args); break; }

$r = xeric_sleep_pass($T, $db, $now, $ep, $opts);
if (!$r['ran']) {
    echo "\nnothing done: " . ($r['refused'] !== '' ? $r['refused'] : $r['why']) . "\n";
    exit($r['refused'] !== '' ? 1 : 0);
}
printf("\nnight of %s, %d model call%s%s\n", $r['night'], $r['calls'], $r['calls'] === 1 ? '' : 's', $opts['dry_run'] ? ' (dry run: nothing written)' : '');
foreach ($r['characters'] as $h => $c) {
    printf("\n%s: %d old enough, %d pinned, %d groups — kept %d, dropped %d%s\n", $h, $c['candidates'], $c['pinned'], $c['groups'],
        count($c['kept']), count($c['dropped']), $c['note'] !== '' ? ' — ' . $c['note'] : '');
    foreach ($c['kept'] as $k) {
        if (is_int($k)) {
            $ch = xeric_sleep_chain($db, $k);
            printf("  + #%d %s  <- %s\n", $k, $ch['fact']['text'], implode(',', array_map(fn($s) => '#' . $s['id'], $ch['sources'])));
        } else {
            printf("  + %s\n", $k);
        }
    }
    foreach ($c['dropped'] as $d) printf("  - %s  (%s)\n", $d['fact'], $d['why']);
}
if ($r['refused'] !== '') { echo "\nstopped: " . $r['refused'] . "\n"; exit(1); }
exit(0);
