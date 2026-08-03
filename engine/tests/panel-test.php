<?php
/**
 * panel-test.php — a problem, a room, and nobody obliged to agree.
 *
 * The two things worth proving are the two things every "panel of experts"
 * tool gets wrong: that the panel actually disagrees, and that a hung room
 * returns a FINDING rather than an apology.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/panel.php';
require_once dirname(__DIR__, 2) . '/forge/panel-forge.php';

$FAILED = 0;
function ok(string $what, bool $cond, string $extra = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $what\n"; return; }
    $FAILED++;
    echo "FAIL - $what" . ($extra !== '' ? " ($extra)" : '') . "\n";
}

$DBS = [];
function fresh(string $tag): PDO
{
    global $DBS;
    $p = sys_get_temp_dir() . '/xeric-panel-' . $tag . '-' . getmypid() . '.db';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $DBS[] = $p;
    $d = xeric_state_open($p);
    xeric_state_migrate($d);
    return $d;
}

/** A room, built the way the forge builds one. */
function panel_of(array $people, string $q = 'who goes, and who decides'): array
{
    return xeric_forge_panel_world('the plant is losing money and somebody has to go', [
        'question' => $q,
        'room' => ['name' => 'the boardroom', 'what' => 'Late, and the coffee is cold.'],
        'people' => $people,
    ]);
}

$ADA   = ['name' => 'Ada Reyes',   'red_line' => 'I will not accept anything that puts the cost on people who did not choose it'];
$TOM   = ['name' => 'Tom Vance',   'red_line' => 'I will not accept a plan that leaves the company insolvent by spring'];
$PRIYA = ['name' => 'Priya Nandi', 'red_line' => 'I will not accept a decision made without telling the people it lands on'];

// ---------------------------------------------------------------------------
// 1. A PANEL THAT AGREES WITH ITSELF IS NOT A PANEL.
//
// This is the failure the whole file exists to catch, and it is the worst kind
// available: a room of five people who all refuse the same thing validates,
// runs, reads fine, and returns a consensus that means nothing.
// ---------------------------------------------------------------------------

echo "\n# whether they actually disagree\n";

ok('panel: three people refusing three different things is a panel',
    xeric_panel_check(panel_of([$ADA, $TOM, $PRIYA])) === []);

ok('panel: two people refusing the same thing are one person twice',
    xeric_panel_check(panel_of([
        $ADA,
        ['name' => 'Tom Vance', 'red_line' => 'I will not accept anything that puts the cost on people who never chose it'],
        $PRIYA,
    ])) !== []);

// THE FRAME IS NOT THE REFUSAL. Every red line is written to the same template,
// and a check that counts shared words counts the template — calling three
// genuinely opposed people identical. Dropping what they ALL say is what makes
// the comparison about the refusals underneath.
ok('panel: the shared "I will not accept" frame is not mistaken for agreement',
    xeric_panel_frame([$ADA['red_line'], $TOM['red_line'], $PRIYA['red_line']]) !== []
    && !xeric_panel_alike($ADA['red_line'], $TOM['red_line'],
           xeric_panel_frame([$ADA['red_line'], $TOM['red_line'], $PRIYA['red_line']])));
// Where it actually bites is SHORT refusals, where the frame is most of the
// sentence: "I will not accept layoffs" and "I will not accept debt" share two
// of their three words and oppose each other completely.
$SHORT = ['I will not accept layoffs', 'I will not accept debt', 'I will not accept secrecy'];
ok('panel: without that correction two short opposite refusals read as identical',
    xeric_panel_alike($SHORT[0], $SHORT[1]));
ok('panel: and with it they read as what they are',
    !xeric_panel_alike($SHORT[0], $SHORT[1], xeric_panel_frame($SHORT))
    && xeric_panel_check(panel_of([
        ['name' => 'Ada Reyes',   'red_line' => $SHORT[0]],
        ['name' => 'Tom Vance',   'red_line' => $SHORT[1]],
        ['name' => 'Priya Nandi', 'red_line' => $SHORT[2]],
    ])) === []);

ok('panel: two people are not a panel', xeric_panel_check(panel_of([$ADA, $TOM])) !== []);
ok('panel: and a room with no question is not one either',
    xeric_panel_check(panel_of([$ADA, $TOM, $PRIYA], '')) !== []);

// A panel is a world like any other, which is the whole architectural bet: the
// rooms, the walls, the memory and the watch page are already written.
$T = panel_of([$ADA, $TOM, $PRIYA]);
ok('panel: what comes out is an ordinary xeric the engine can run',
    (function () use ($T) { try { xeric_world_validate($T); return true; }
                            catch (Throwable $e) { return false; } })());
ok('panel: and it is marked experimental in the template, not only in the UI',
    (string)($T['meta']['experimental'] ?? '') === 'discussion');
ok('panel: ambient life is off — a panel is one conversation, not a town',
    (float)($T['events']['sweep_chance'] ?? 1) === 0.0 && (array)($T['forge']['armed'] ?? []) === []);

// ---------------------------------------------------------------------------
// 2. NOBODY IS EVER ASKED WHETHER THERE IS CONSENSUS.
//
// Each expert answers one question about one sentence they wrote themselves,
// never sees the tally, and never sees anybody else's verdict. Code counts.
// ---------------------------------------------------------------------------

echo "\n# what the room made of it\n";

/** A stub room where each expert's answer is fixed by handle. */
function says(array $verdicts): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $o) use ($verdicts) {
        $sys = (string)($msgs[0]['content'] ?? '');
        foreach ($verdicts as $name => $crosses) {
            if (str_contains($sys, $name)) {
                return ['crosses' => $crosses, 'because' => $crosses ? 'that is my line' : 'I can live with it'];
            }
        }
        return ['crosses' => false, 'because' => ''];
    }];
}

$db = fresh('table');
$i  = xeric_panel_propose($db, 'Cut the executive floor first, publish the numbers, and nobody below band four goes.');
ok('panel: a proposal is text and nothing else — no score, no author\'s own verdict',
    $i === 0 && xeric_panel_proposals($db)[0]['clears'] === []);

$r = xeric_panel_table($T, $db, $i, says(['Ada' => false, 'Tom' => false, 'Priya' => false]));
ok('panel: a proposal nobody refuses clears the room', count($r['clears']) === 3 && $r['crosses'] === []);
$v = xeric_panel_verdict($T, $db);
ok('panel: and code calls that consensus — nobody was asked whether there was one',
    $v['state'] === 'consensus' && $v['cleared'] === 3 && $v['tensions'] === []);

// FAILS CLOSED. An expert who could not be reached has agreed to nothing,
// which is the only safe reading: silence is not assent about a refusal.
$dead = fresh('dead');
$j = xeric_panel_propose($dead, 'Something nobody got to see.');
xeric_panel_table($T, $dead, $j, ['base' => 'stub://', 'stub' => function () {
    throw new RuntimeException('the model is not answering');
}]);
ok('panel: an expert who could not be reached has accepted nothing',
    xeric_panel_verdict($T, $dead)['state'] === 'hung');
$garbled = fresh('garbled');
$k = xeric_panel_propose($garbled, 'Something they answered badly.');
xeric_panel_table($T, $garbled, $k, ['base' => 'stub://', 'stub' => fn() => ['thoughts' => 'hmm']]);
ok('panel: and neither has one who gave no readable answer',
    xeric_panel_verdict($T, $garbled)['state'] === 'hung');

// ---------------------------------------------------------------------------
// 3. A HUNG PANEL IS THE RESULT, NOT THE FAILURE.
//
// The verdict is not an apology, it is the PAIR: which two refusals no
// proposal ever satisfied at the same time. That is arithmetic over a table of
// yes and no, and it is where the problem actually lives.
// ---------------------------------------------------------------------------

echo "\n# the pair nobody ever satisfied at once\n";

$hung = fresh('hung');
// Two proposals. Ada and Priya are cleared together by the first; Tom is
// cleared by the second along with Priya. Ada and Tom are never cleared at
// once, and that is the finding.
$p1 = xeric_panel_propose($hung, 'Nobody goes, and we borrow against next year.');
xeric_panel_table($T, $hung, $p1, says(['Ada' => false, 'Tom' => true, 'Priya' => false]));
$p2 = xeric_panel_propose($hung, 'Twelve go on Friday, announced the same morning.');
xeric_panel_table($T, $hung, $p2, says(['Ada' => true, 'Tom' => false, 'Priya' => false]));

$hv = xeric_panel_verdict($T, $hung);
ok('panel: nothing cleared everybody, so the room is hung', $hv['state'] === 'hung' && $hv['cleared'] === 2);
ok('panel: and the verdict names the pair no proposal ever satisfied at once',
    count($hv['tensions']) === 1
    && $hv['tensions'][0]['between'] === ['Ada Reyes', 'Tom Vance'],
    json_encode(array_column($hv['tensions'], 'between')));
ok('panel: the pair that WAS satisfied together is not reported as a tension',
    !in_array(['Ada Reyes', 'Priya Nandi'], array_column($hv['tensions'], 'between'), true)
    && !in_array(['Tom Vance', 'Priya Nandi'], array_column($hv['tensions'], 'between'), true));
ok('panel: and it is said as a finding, not an apology',
    str_contains(xeric_panel_say($T, $hung), 'that is the answer')
    && str_contains(xeric_panel_say($T, $hung), 'Ada Reyes'));

ok('panel: a room nobody has proposed anything to says so plainly',
    xeric_panel_verdict($T, fresh('quiet'))['state'] === 'nothing');

// ---------------------------------------------------------------------------
// 4. WHAT AN EXPERT CARRIES IN.
//
// Their own line and nobody else's. A panellist who could read everybody's
// refusal would write around them, which is the chairman's-summary failure
// this whole design exists to avoid.
// ---------------------------------------------------------------------------

echo "\n# what they know walking in\n";

$blk = xeric_panel_block($T, 'ada_reyes');
ok('panel: an expert carries their own refusal into the room',
    str_contains($blk, 'puts the cost on people'));
ok('panel: and not anybody else\'s, so they cannot write around it',
    !str_contains($blk, 'insolvent') && !str_contains($blk, 'without telling'));
ok('panel: they are told plainly not to soften it for the room',
    str_contains($blk, 'not here to be agreeable'));
ok('panel: somebody who is not on the panel carries nothing',
    xeric_panel_block($T, 'nobody_here') === '');
ok('panel: and an ordinary world has no panel at all',
    xeric_panel(['cast' => ['characters' => []]]) === null);

foreach ($DBS as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
