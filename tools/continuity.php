<?php
/**
 * tools/continuity.php — the continuity benchmark, any size.
 *
 *     php tools/continuity.php                         3 days, honest + leaky stand-ins, against the baseline
 *     php tools/continuity.php --days=7 --seed=4       a longer week, another seed
 *     php tools/continuity.php --model=leaky           one personality only
 *     php tools/continuity.php --model=http://127.0.0.1:8080/v1   a real model (XERIC_LLM_KEY, XERIC_LLM_MODEL)
 *     php tools/continuity.php --accept                write what this run found as the new baseline
 *     php tools/continuity.php --judge=<url>           (advisory judged class: not implemented yet)
 *     php tools/continuity.php --scenario=path.json --out=dir --probe-every=3
 *
 * Writes scorecard.json, report.md and world.db per model under
 * data/continuity/<scenario>-<model>-s<seed>-d<days>/ (gitignored). Exits 1
 * when the CI rule fails against the baseline (docs/CONTINUITY.md).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../engine/continuity/harness.php';
require_once __DIR__ . '/../engine/continuity/probes.php';
require_once __DIR__ . '/../engine/continuity/report.php';

$opt = ['days' => null, 'model' => 'standin,leaky', 'seed' => 1, 'accept' => false, 'judge' => null,
        'scenario' => __DIR__ . '/../engine/fixtures/milldale-continuity.json',
        'baseline' => __DIR__ . '/../engine/fixtures/continuity-baseline.json',
        'out' => __DIR__ . '/../data/continuity', 'probe-every' => 3];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--accept') { $opt['accept'] = true; continue; }
    if ($a === '--judge')  { $opt['judge'] = ''; continue; }
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) { $opt[$m[1]] = $m[2]; continue; }
    fwrite(STDERR, "continuity: unknown argument $a\n");
    exit(2);
}
if ($opt['judge'] !== null) {
    fwrite(STDERR, "continuity: --judge is not implemented yet. The judged class is advisory (FUTURE.md Appendix B: temperature 0, "
        . "majority of three, cached by hash, calibrated on ten hand-labelled transcripts) and never gates CI. Running without it.\n");
}

$L     = cb_scenario_load((string)$opt['scenario']);
$days  = (int)($opt['days'] ?? $L['sc']['days'] ?? 3);
$seed  = (int)$opt['seed'];
$base  = cb_baseline_read((string)$opt['baseline']);
$comparable = $base !== null && (int)($base['days'] ?? 0) === $days && (int)($base['seed'] ?? 0) === $seed;

$cards = [];
$bad   = false;
foreach (array_filter(explode(',', (string)$opt['model'])) as $model) {
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $model);
    $dir  = rtrim((string)$opt['out'], '/') . '/' . $L['sc']['name'] . '-' . trim((string)$slug, '-') . '-s' . $seed . '-d' . $days;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $run  = cb_run($L, ['days' => $days, 'seed' => $seed, 'model' => $model, 'db' => $dir . '/world.db',
                        'probe_every' => (int)$opt['probe-every']]);
    $card = cb_scorecard($run, cb_probes($run));
    $cards[$model] = $card;
    $cmp  = $comparable && in_array($model, ['standin', 'leaky'], true) ? cb_compare($card, $base['runs'][$model] ?? null) : null;
    cb_write($dir, $card, $cmp);

    $fails = array_filter($card['probes'], fn($p) => !$p['pass']);
    printf("%-8s %d days, seed %d: %d probes, %d failing (%s), %d calls, ~%d tokens, %.1f s → %s/report.md\n",
        $model, $days, $seed, count($card['probes']), count($fails), implode(', ', array_column($fails, 'id')) ?: 'none',
        $card['cost']['total']['calls'], $card['cost']['total']['tokens'], (float)$card['seconds'], $dir);
    if ($cmp !== null && !$opt['accept']) {
        foreach ($cmp['fail'] as $f) echo "  FAIL  $f\n";
        foreach ($cmp['improved'] as $f) echo "  +     $f\n";
        foreach ($cmp['notes'] as $f) echo "  note  $f\n";
        if ($cmp['fail'] !== []) $bad = true;
    } elseif ($cmp === null && !$opt['accept'] && in_array($model, ['standin', 'leaky'], true)) {
        echo "  (no comparable baseline for $days days, seed $seed — the checked-in one is "
            . ($base ? (int)$base['days'] . ' days, seed ' . (int)$base['seed'] : 'missing') . ")\n";
    }
}

if ($opt['accept']) {
    $keep = array_filter($cards, fn($c) => in_array($c['model'], ['standin', 'leaky'], true));
    if ($keep === []) { fwrite(STDERR, "continuity: only stand-in runs can be accepted as the baseline\n"); exit(2); }
    cb_baseline_write((string)$opt['baseline'], $keep, $L['sc'], ['days' => $days, 'seed' => $seed]);
    echo "accepted → " . realpath((string)$opt['baseline']) . "\n";
}
exit($bad ? 1 : 0);
