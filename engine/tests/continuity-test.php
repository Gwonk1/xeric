<?php
/**
 * Xeric — the continuity benchmark, CI-sized. `php engine/tests/continuity-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL. Three days of Milldale (Thursday to Saturday) lived on
 * a fake real clock with the deterministic stand-in in both personalities
 * (engine/continuity/, docs/CONTINUITY.md):
 *
 *   - the HONEST run hands nobody a six-word quotation of anything walled;
 *   - the LEAKY run, which copies hold-back and still-carries lines into what
 *     it writes, IS caught — an oracle that passes a leaking model is no oracle;
 *   - day 1 lived twice gives the same digest, and a rewound skip gives back
 *     the world it started from;
 *   - media seeds and scene fingerprints hold still across days and runs;
 *   - cost is measured, and reaches the meter;
 *   - and the whole thing is held to engine/fixtures/continuity-baseline.json
 *     by the CI rule (new hard failure, cost +10%, seed/fingerprint drift).
 *     The first run with no baseline writes one, with --accept semantics.
 */

declare(strict_types=1);

require_once __DIR__ . '/../continuity/harness.php';
require_once __DIR__ . '/../continuity/probes.php';
require_once __DIR__ . '/../continuity/report.php';

$FAILED = 0;
$COUNT  = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED, $COUNT;
    $COUNT++;
    if ($cond) {
        echo "ok   - $name\n";
    } else {
        $FAILED++;
        echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

const SCENARIO = __DIR__ . '/../fixtures/milldale-continuity.json';
const BASELINE = __DIR__ . '/../fixtures/continuity-baseline.json';
const DAYS = 3;
const SEED = 1;

$t0  = microtime(true);
$L   = cb_scenario_load(SCENARIO);
$tmp = sys_get_temp_dir() . '/xeric-continuity-test-' . getmypid();
$DB  = [];
$db  = function (string $tag) use ($tmp, &$DB): string { return $DB[] = $tmp . '-' . $tag . '.db'; };

$meter0 = xeric_llm_tally();
$honest = cb_run($L, ['days' => DAYS, 'seed' => SEED, 'model' => 'standin', 'db' => $db('honest')]);
$meter1 = xeric_llm_tally();
$leaky  = cb_run($L, ['days' => DAYS, 'seed' => SEED, 'model' => 'leaky',   'db' => $db('leaky')]);
$replay = cb_run($L, ['days' => 1,    'seed' => SEED, 'model' => 'standin', 'db' => $db('replay')]);
$other  = cb_run($L, ['days' => 1,    'seed' => SEED + 7, 'model' => 'standin', 'db' => $db('other')]);

$P  = cb_probes($honest);
$PL = cb_probes($leaky);
$by  = array_column($P, null, 'id');
$byL = array_column($PL, null, 'id');
$H  = cb_scorecard($honest, $P);
$K  = cb_scorecard($leaky, $PL);

// ---------------------------------------------------------------------------
// the run happened, and did something
// ---------------------------------------------------------------------------

ok('harness: the honest run lived three days of hours', count($honest['digests']) === DAYS, json_encode(array_keys($honest['digests'])));
ok('harness: every scripted action ran (the refused one refused, and said why)',
    count(array_filter($honest['actions'], fn($a) => !$a['ok'] && empty($a['args']['expect']))) === 0,
    json_encode(array_values(array_filter($honest['actions'], fn($a) => !$a['ok'] && empty($a['args']['expect'])))));
ok('harness: minimum activity — a run cannot pass by doing nothing', $by['presence.activity']['pass'], json_encode($by['presence.activity']['evidence']));
ok('harness: every phase reached the model at least once',
    count(array_intersect(['say', 'extract', 'sweep', 'proactive', 'skip', 'learn'], array_keys($H['cost']['by_phase']))) === 6,
    implode(',', array_keys($H['cost']['by_phase'])));
ok('harness: the fake real clock is let go afterwards', abs(xeric_state_time() - time()) <= 1);
ok('harness: every living character is probed every third hour, nobody-talks-to-them included',
    count(array_unique(array_column($honest['builds'], 'handle'))) === count($L['T']['cast']['characters']));

// ---------------------------------------------------------------------------
// walls — the oracle, and its teeth
// ---------------------------------------------------------------------------

ok('oracle: covers every one of render-test\'s hand-written SECRETS', $by['walls.coverage']['pass'], json_encode($by['walls.coverage']['evidence']));
ok('oracle: is generated, not a list — the daughter is walled from the Thursday pot\'s ground truth',
    in_array('economies.thursday_pot', array_column(cb_forbidden($L['T'], 'janelle', $L['sc']), 'path'), true));
ok('oracle: nobody is forbidden their own head',
    !in_array('secrets.dot.owner_only', array_column(cb_forbidden($L['T'], 'dot', $L['sc']), 'path'), true)
    && in_array('secrets.ruth.owner_only', array_column(cb_forbidden($L['T'], 'dot', $L['sc']), 'path'), true));

$base = cb_baseline_read(BASELINE);
$expect = (array)($base['runs']['standin']['probes'] ?? []);
$hardWall = array_filter($P, fn($p) => $p['class'] === 'walls' && $p['severity'] === 'hard' && !$p['pass']
    && !isset($expect[$p['id']]['expected_fail']) && !isset($L['sc']['expected_fail'][$p['id']]));
ok('walls: the honest run has zero hard wall failures (beyond the known, expected ones)', $hardWall === [],
    implode(', ', array_column($hardWall, 'id')));
ok('walls: no honest prompt quotes six walled words in a row', $by['walls.quote']['pass'], json_encode($by['walls.quote']['evidence']['hits'] ?? []));
ok('walls: no honest memory at rest quotes a wall', $by['walls.rest']['pass'], json_encode($by['walls.rest']['evidence']['hits'] ?? []));
ok('walls: the probe actually looked (hundreds of prompts, every viewer)', $by['walls.quote']['evidence']['prompts_checked'] > 100);

ok('teeth: the leaky run IS caught quoting walled text in a prompt', !$byL['walls.quote']['pass'] && $byL['walls.quote']['evidence']['quotations'] > 0,
    json_encode($byL['walls.quote']['evidence']['quotations'] ?? null));
ok('teeth: and at rest, in the memories it wrote', !$byL['walls.rest']['pass']);
$paths = array_unique(array_column((array)$byL['walls.quote']['evidence']['hits'], 'path'));
ok('teeth: what it caught is an owner-only secret reaching somebody else', (bool)array_filter($paths, fn($p) => str_ends_with($p, '.owner_only')), implode(',', $paths));

// Evidence never carries the thing it is evidence of.
$json = json_encode([$H, $K, cb_report_md($H), cb_report_md($K)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$leakedIntoReport = [];
foreach ($L['T']['cast']['characters'] as $c) foreach ((array)($c['secrets'] ?? []) as $s) {
    $words = array_slice(explode(' ', xeric_text($s)), 0, 6);
    if (stripos($json, implode(' ', $words)) !== false) $leakedIntoReport[] = $c['handle'];
}
foreach (cb_render_secrets() as $s) if (mb_strlen($s) > 8 && stripos($json, $s) !== false) $leakedIntoReport[] = '#secret';
ok('evidence: scorecards and reports cite paths and hashes, never the walled text', $leakedIntoReport === [], implode(',', $leakedIntoReport));

// ---------------------------------------------------------------------------
// recall and presence — the first three classes
// ---------------------------------------------------------------------------

foreach (['recall.pie', 'recall.casserole_dish', 'recall.harlan_dead'] as $id) {
    ok("recall: $id carried in its expected part of every prompt since it was said", $by[$id]['pass'], json_encode($by[$id]['evidence']));
}
ok('recall: the pie survived the whole run in Dot\'s memory', ($by['recall.pie']['evidence']['parts']['WHAT YOU REMEMBER'] ?? 0) === $by['recall.pie']['evidence']['builds']);
foreach (['presence.dead_absent', 'presence.death_line', 'presence.dead_refused', 'presence.released', 'presence.located',
          'presence.mill', 'presence.quiet'] as $id) {
    ok("presence: $id", $by[$id]['pass'], json_encode($by[$id]['evidence']));
}

// ---------------------------------------------------------------------------
// relationships, media, learn
// ---------------------------------------------------------------------------

ok('relationships: Dot\'s missed promise cost a notch, and the apology gave it back', $by['relationships.repair']['pass'], json_encode($by['relationships.repair']['evidence']));
ok('relationships: trust recomputes from the log', $by['relationships.trust_log']['pass'], json_encode($by['relationships.trust_log']['evidence']));

ok('media: photo and place seeds identical every day', $by['media.seeds']['pass']);
ok('media: and identical across personalities', $H['seeds'] === $K['seeds']);
ok('media: a fingerprint does not tick with the minute', $by['media.fingerprint_minute']['pass'], json_encode($by['media.fingerprint_minute']['evidence']));
ok('media: a death changes the room\'s fingerprint', $by['media.fingerprint_death']['pass'], json_encode($by['media.fingerprint_death']['evidence']));
ok('media: fingerprints are a function of the world, not of the model', $H['fingerprints'] === $K['fingerprints']);

ok('learn: the skip\'s distil counted every scripted reply', $by['learn.tally']['pass'], json_encode($by['learn.tally']['evidence']));
ok('learn: each lesson only in its own bucket\'s prompts', $by['learn.buckets']['pass'], json_encode($by['learn.buckets']['evidence']));

// ---------------------------------------------------------------------------
// determinism
// ---------------------------------------------------------------------------

ok('determinism: day 1 lived again gives the same digest', $replay['digests'][1] === $honest['digests'][1],
    $replay['digests'][1] . ' vs ' . $honest['digests'][1]);
ok('determinism: and the same model calls, byte for byte',
    md5(json_encode(array_map(fn($c) => $c['messages'], array_filter($honest['calls'], fn($c) => $c['day'] === 1))))
    === md5(json_encode(array_map(fn($c) => $c['messages'], array_filter($replay['calls'], fn($c) => $c['day'] === 1)))));
ok('determinism: a different seed lives a different day', $other['digests'][1] !== $honest['digests'][1]);
$rw = $H['rewind'][0] ?? null;
ok('determinism: the rewound skip gave back exactly the pre-skip world', $rw !== null && $rw['ok'] && $rw['equal'], json_encode($rw));
ok('determinism: and the reroll lived those hours again with seed+1',
    count($honest['skips']) === 2 && $honest['skips'][1]['seed'] === SEED + 1);

// ---------------------------------------------------------------------------
// cost
// ---------------------------------------------------------------------------

$cost = $H['cost'];
ok('cost: calls, characters and tokens per day', count($cost['by_day']) === DAYS && $cost['total']['tokens'] > 0
    && array_sum(array_column($cost['by_day'], 'calls')) === $cost['total']['calls']);
ok('cost: system-prompt characters per speaker', ($cost['system_chars']['dot'] ?? 0) > 1000, json_encode($cost['system_chars']));
ok('cost: stub calls now reach the meter (xeric_llm_tally)', $meter1['calls'] - $meter0['calls'] === $cost['total']['calls'],
    ($meter1['calls'] - $meter0['calls']) . ' vs ' . $cost['total']['calls']);
ok('cost: the cache-break probe ran', isset($by['cost.cache']['evidence']['system_changes']));

// ---------------------------------------------------------------------------
// the baseline
// ---------------------------------------------------------------------------

if ($base === null) {
    cb_baseline_write(BASELINE, ['standin' => $H, 'leaky' => $K], $L['sc'], ['days' => DAYS, 'seed' => SEED]);
    echo "note - no baseline yet: wrote " . BASELINE . " (accept semantics); check it in\n";
    $base = cb_baseline_read(BASELINE);
}
ok('baseline: it is for this size of run', (int)($base['days'] ?? 0) === DAYS && (int)($base['seed'] ?? 0) === SEED);
foreach (['standin' => $H, 'leaky' => $K] as $name => $card) {
    $cmp = cb_compare($card, $base['runs'][$name] ?? null);
    ok("baseline: $name run passes the CI rule", $cmp['fail'] === [], implode(' | ', $cmp['fail']));
    foreach ($cmp['improved'] as $i) echo "note - $name: $i\n";
    foreach ($cmp['notes'] as $n) echo "note - $name: $n\n";
}
foreach ((array)($base['runs']['standin']['probes'] ?? []) as $id => $e) {
    if (isset($e['expected_fail'])) echo "note - expected to fail: $id — " . $e['expected_fail'] . "\n";
}

$secs = microtime(true) - $t0;
ok('budget: the CI-sized benchmark runs in under 15 s', $secs < 15.0, round($secs, 2) . ' s');

foreach ($DB as $f) foreach ([$f, $f . '-wal', $f . '-shm'] as $x) @unlink($x);

echo "\n" . ($FAILED === 0 ? "all $COUNT passed" : "$FAILED of $COUNT FAILED") . " (" . round($secs, 2) . " s)\n";
exit($FAILED === 0 ? 0 : 1);
