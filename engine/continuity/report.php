<?php
/**
 * Xeric continuity benchmark — scorecards, the report, and the baseline.
 *
 * A run writes scorecard.json (everything, machine-readable), report.md (the
 * same, for a person) and keeps its world.db, under a gitignored directory.
 * The checked-in baseline is engine/fixtures/continuity-baseline.json: per
 * personality, every probe's outcome — with `expected_fail` and a reason for
 * the known bugs, and `teeth` for the leaky run's walls, which MUST fail —
 * plus cost, media seeds, fingerprints, digests and the PHP version.
 *
 * THE CI RULE (cb_compare):
 *   fail  a hard probe failing that the baseline did not expect to fail;
 *         a `teeth` probe passing (the oracle stopped catching the leaky model);
 *         prompt tokens up more than 10%;
 *         any media seed or scene fingerprint different from the baseline.
 *   note  an expected_fail probe now passing — "improved, run --accept";
 *         a digest that moved (the story changed: fine if intended, --accept).
 */

declare(strict_types=1);

const CB_COST_SLACK = 0.10;

/** The scorecard for one run: no prompts, no secrets, nothing but outcomes and numbers. */
function cb_scorecard(array $run, array $probes): array
{
    return [
        'scenario' => (string)($run['sc']['name'] ?? ''),
        'model'    => $run['model'],
        'seed'     => $run['seed'],
        'days'     => $run['days'],
        'php'      => PHP_VERSION,
        'seconds'  => $run['seconds'] ?? null,
        'probes'   => $probes,
        'cost'     => cb_cost($run),
        'seeds'    => array_map(fn($d) => $d['seeds'], $run['daily']),
        'fingerprints' => array_map(fn($f) => $f === null ? null : cb_h($f), cb_fingerprints($run)),
        'digests'  => $run['digests'],
        'skips'    => array_map(fn($s) => ['seed' => $s['seed'], 'events' => $s['events'], 'pre' => cb_h($s['pre'])], $run['skips']),
        'rewind'   => array_values(array_filter(array_map(fn($a) => $a['rewind'] ?? null, $run['actions']))),
        'counts'   => ['calls' => count($run['calls']), 'builds' => count($run['builds']), 'events' => count($run['events']),
                       'pings' => count($run['pings']), 'actions' => count($run['actions'])],
    ];
}

/** What the baseline keeps of a scorecard. */
function cb_baseline_entry(array $card, array $expectedFail, bool $leaky): array
{
    $probes = [];
    foreach ($card['probes'] as $p) {
        $e = ['pass' => $p['pass'], 'severity' => $p['severity'], 'score' => $p['score']];
        if (!$p['pass'] && $leaky && $p['class'] === 'walls' && $p['severity'] === 'hard' && $p['id'] !== 'walls.table') {
            $e['teeth'] = 'the leaky stand-in copies hold-back and still-carries lines; the oracle must catch it';
        } elseif (!$p['pass'] && isset($expectedFail[$p['id']])) {
            $e['expected_fail'] = (string)$expectedFail[$p['id']];
        } elseif (!$p['pass'] && $p['severity'] === 'hard') {
            $e['expected_fail'] = 'failing when this baseline was accepted';
        }
        $probes[$p['id']] = $e;
    }
    return [
        'probes' => $probes,
        'cost'   => ['calls' => $card['cost']['total']['calls'], 'tokens' => $card['cost']['total']['tokens'],
                     'chars' => $card['cost']['total']['chars'], 'by_phase' => $card['cost']['by_phase']],
        'seeds'  => $card['seeds'], 'fingerprints' => $card['fingerprints'], 'digests' => $card['digests'],
    ];
}

/**
 * The CI rule. $base is one personality's baseline entry.
 * @return array{fail:string[],improved:string[],notes:string[]}
 */
function cb_compare(array $card, ?array $base): array
{
    $fail = []; $improved = []; $notes = [];
    if ($base === null) return ['fail' => ['no baseline for ' . $card['model'] . ' — run tools/continuity.php --accept'], 'improved' => [], 'notes' => []];

    foreach ($card['probes'] as $p) {
        $b = $base['probes'][$p['id']] ?? null;
        if ($p['pass']) {
            if (isset($b['teeth'])) $fail[] = $p['id'] . ' passed, and it is supposed to catch the leaky stand-in — the oracle lost its teeth';
            elseif (isset($b['expected_fail'])) $improved[] = $p['id'] . ' passes now (was expected to fail: ' . $b['expected_fail'] . ')';
            continue;
        }
        if ($p['severity'] !== 'hard') {
            if ($b !== null && $b['pass']) $notes[] = $p['id'] . ' (soft) regressed, score ' . $b['score'] . ' → ' . $p['score'];
            continue;
        }
        if ($b === null) { $fail[] = $p['id'] . ' is a new hard probe and it fails'; continue; }
        if (!isset($b['expected_fail']) && !isset($b['teeth'])) $fail[] = $p['id'] . ' is a NEW hard failure';
    }

    $was = (int)($base['cost']['tokens'] ?? 0);
    $now = (int)$card['cost']['total']['tokens'];
    if ($was > 0 && $now > $was * (1 + CB_COST_SLACK)) {
        $fail[] = sprintf('prompt tokens up %.1f%% (%d → %d), past the %d%% allowance', 100 * ($now - $was) / $was, $was, $now, 100 * CB_COST_SLACK);
    } elseif ($was > 0 && $now !== $was) {
        $notes[] = sprintf('prompt tokens %+.1f%% (%d → %d)', 100 * ($now - $was) / $was, $was, $now);
    }
    foreach (['seeds' => 'media seed', 'fingerprints' => 'scene fingerprint'] as $k => $label) {
        foreach ((array)($base[$k] ?? []) as $key => $v) {
            if (($card[$k][$key] ?? null) !== $v) $fail[] = $label . ' drift: ' . $key;
        }
    }
    foreach ((array)($base['digests'] ?? []) as $d => $v) {
        if (($card['digests'][$d] ?? null) !== $v) $notes[] = 'day ' . $d . ' digest moved (the story changed — run --accept if that was intended)';
    }
    if ($improved !== []) $notes[] = 'improvements: run tools/continuity.php --accept to take them into the baseline';
    return ['fail' => $fail, 'improved' => $improved, 'notes' => $notes];
}

/** report.md: the scorecard, for a person. */
function cb_report_md(array $card, ?array $cmp = null): string
{
    $md = ['# Continuity — ' . $card['scenario'] . ', ' . $card['model'] . ', seed ' . $card['seed'] . ', ' . $card['days'] . ' days', ''];
    $md[] = 'PHP ' . $card['php'] . ' · ' . ($card['seconds'] ?? '?') . ' s · ' . $card['counts']['calls'] . ' model calls · '
          . $card['counts']['builds'] . ' prompts probed · ' . $card['counts']['events'] . ' hours lived · ' . $card['counts']['pings'] . ' pings';
    $md[] = '';
    $md[] = '| probe | class | severity | result | score |';
    $md[] = '|---|---|---|---|---|';
    foreach ($card['probes'] as $p) {
        $md[] = '| ' . $p['id'] . ' | ' . $p['class'] . ' | ' . $p['severity'] . ' | ' . ($p['pass'] ? 'pass' : '**FAIL**') . ' | ' . $p['score'] . ' |';
    }
    $c = $card['cost'];
    $md[] = '';
    $md[] = '## Cost';
    $md[] = '';
    $md[] = 'Total: ' . $c['total']['calls'] . ' calls, ' . $c['total']['chars'] . ' prompt characters, ~' . $c['total']['tokens'] . ' tokens.';
    $md[] = '';
    $md[] = '| day / phase | calls | characters | ~tokens |';
    $md[] = '|---|---|---|---|';
    foreach ($c['by_day'] as $d => $r) $md[] = '| day ' . $d . ' | ' . $r['calls'] . ' | ' . $r['chars'] . ' | ' . $r['tokens'] . ' |';
    foreach ($c['by_phase'] as $d => $r) $md[] = '| ' . $d . ' | ' . $r['calls'] . ' | ' . $r['chars'] . ' | ' . $r['tokens'] . ' |';
    $md[] = '';
    $md[] = 'System prompt characters per speaker: ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($c['system_chars']), $c['system_chars'])) . '.';
    $md[] = '';
    $md[] = '## Failures, with evidence';
    $md[] = '';
    $any = false;
    foreach ($card['probes'] as $p) {
        if ($p['pass']) continue;
        $any = true;
        $md[] = '- **' . $p['id'] . '** (' . $p['severity'] . '): `' . json_encode($p['evidence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
    }
    if (!$any) $md[] = 'None.';
    if ($cmp !== null) {
        $md[] = '';
        $md[] = '## Against the baseline';
        $md[] = '';
        foreach ($cmp['fail'] as $f) $md[] = '- FAIL: ' . $f;
        foreach ($cmp['improved'] as $f) $md[] = '- improved: ' . $f;
        foreach ($cmp['notes'] as $f) $md[] = '- note: ' . $f;
        if ($cmp['fail'] === [] && $cmp['improved'] === [] && $cmp['notes'] === []) $md[] = 'Identical.';
    }
    $md[] = '';
    $md[] = 'Evidence cites wall paths, row ids, capture numbers and hashes — never the walled text. See docs/CONTINUITY.md.';
    return implode("\n", $md) . "\n";
}

/** Write scorecard.json + report.md into $dir. */
function cb_write(string $dir, array $card, ?array $cmp): void
{
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents($dir . '/scorecard.json', json_encode($card + ['baseline' => $cmp], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents($dir . '/report.md', cb_report_md($card, $cmp));
}

function cb_baseline_read(string $path): ?array
{
    if (!is_file($path)) return null;
    $j = json_decode((string)file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

/** Accept: the given cards become the baseline. */
function cb_baseline_write(string $path, array $cards, array $sc, array $meta): void
{
    $runs = [];
    foreach ($cards as $name => $card) $runs[$name] = cb_baseline_entry($card, (array)($sc['expected_fail'] ?? []), $card['model'] === 'leaky');
    $doc = ['_note' => 'Generated by tools/continuity.php --accept (or the first run of engine/tests/continuity-test.php). '
                     . 'Do not hand-edit outcomes: fix the engine, re-run, and accept. docs/CONTINUITY.md.',
            'scenario' => (string)($sc['name'] ?? ''), 'php' => PHP_VERSION] + $meta + ['runs' => $runs];
    file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
