<?php
/**
 * Xeric — budget tests.  `php engine/tests/budget-test.php`, exit 0 on pass.
 *
 * The per-world ceiling (engine/budget.php): a zero is no ceiling, a day is
 * the world's date, metered_only leaves the owner's own electricity alone,
 * a broken ledger says no, the sink writes every kind into the world it was
 * bound to — and the things that spend wait or refuse when the day is out:
 * the scene reaper and the photo reaper leave rows pending, the voice clip
 * and the ears refuse, and the heart's draw-ahead skips and says why.
 */

declare(strict_types=1);

require_once __DIR__ . '/../budget.php';
require_once __DIR__ . '/../scene.php';
require_once __DIR__ . '/../voice.php';
require_once __DIR__ . '/../ears.php';

$FAILED = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) {
        echo "ok   - $name\n";
    } else {
        $FAILED++;
        echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_IMAGE_BASE', 'XERIC_IMAGE_KEY', 'XERIC_VOICE_BASE',
          'XERIC_EARS_BASE'] as $k) putenv($k);

$T  = xeric_template_load(__DIR__ . '/../fixtures/milldale.json');
$TZ = new DateTimeZone((string)$T['user']['timezone']);
$at = fn(string $local) => xeric_world_now($T, (new DateTimeImmutable($local, $TZ))->getTimestamp());
$withBudget = fn(array $b): array => ['budget' => $b] + $T;

$dir  = sys_get_temp_dir() . '/xeric-budget-test-' . getmypid();
@mkdir($dir, 0775, true);
$paths = [];
$fresh = function (string $tag) use ($dir, &$paths): PDO {
    $p = $dir . '/' . $tag . '.sqlite';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $paths[] = $p;
    return xeric_state_open($p);
};
register_shutdown_function(function () use ($dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
             RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
});

// ---------------------------------------------------------------------------
echo "# defaults are no ceiling\n";
// ---------------------------------------------------------------------------

$none = ['tokens_per_day' => 0, 'renders_per_day' => 0, 'clips_per_day' => 0, 'hearings_per_day' => 0,
         'metered_only' => true];
ok('a template without the block is every ceiling at zero, metered only', xeric_budget($T) === $none);
ok('the block as WORLD_TEMPLATE.md prints it is the same', xeric_budget($withBudget($none)) === $none);
ok('nonsense is zero, a negative is zero, only an explicit false rations the owner\'s machine',
    xeric_budget($withBudget(['tokens_per_day' => 'lots', 'renders_per_day' => -4, 'metered_only' => 0])) === $none
    && xeric_budget($withBudget(['metered_only' => false]))['metered_only'] === false);
ok('and no ceiling set is no ceiling anywhere', !xeric_budget_any($T) && xeric_budget_any($withBudget(['clips_per_day' => 1])));

$db = $fresh('defaults');
$now = xeric_clock_now($db, $T);
xeric_budget_note($db, $now, 'tokens', 10_000_000, true);
$v = xeric_budget_allows($T, $db, $now, 'tokens', 1, true);
ok('no ceiling lets a costly spend through however much was spent', $v['ok'] && $v['ceiling'] === 0
    && $v['left'] === PHP_INT_MAX && $v['reason'] === '', json_encode($v));
ok('and the meter line is empty, so the meter is unchanged', xeric_budget_line($T, $db, $now) === '');

// ---------------------------------------------------------------------------
echo "\n# a day is the world's date\n";
// ---------------------------------------------------------------------------

$late  = $at('2026-11-02 23:59');
$early = $at('2026-11-03 00:01');
ok('the day is the world\'s local date, not UTC',
    xeric_budget_day($late) === '2026-11-02' && xeric_budget_day($early) === '2026-11-03');
$db = $fresh('dates');
xeric_budget_note($db, $late, 'tokens', 7);
xeric_budget_note($db, $early, 'tokens', 5);
ok('a spend at 23:59 and one at 00:01 land in different days',
    xeric_budget_spent($db, $late)['tokens'] === 7 && xeric_budget_spent($db, $early)['tokens'] === 5);
xeric_budget_note($db, $late, 'tokens', 3);
ok('and the same day accumulates in one row', xeric_budget_spent($db, $late)['tokens'] === 10
    && (int)$db->query('SELECT COUNT(*) FROM spend')->fetchColumn() === 2);
$Tb = $withBudget(['tokens_per_day' => 10]);
ok('a world spent out at 23:59 has its whole allowance again at 00:01',
    !xeric_budget_allows($Tb, $db, $late, 'tokens', 1, true)['ok']
    && xeric_budget_allows($Tb, $db, $early, 'tokens', 1, true)['left'] === 5);

// ---------------------------------------------------------------------------
echo "\n# metered only\n";
// ---------------------------------------------------------------------------

$db = $fresh('metered');
$now = $at('2026-11-02 12:00');
xeric_budget_note($db, $now, 'tokens', 100, false);   // the owner's own GPU
xeric_budget_note($db, $now, 'tokens', 5, true);      // a hosted model
$Tm = $withBudget(['tokens_per_day' => 10]);
ok('the ledger keeps both, and the costly half on its own',
    xeric_budget_spent($db, $now)['tokens'] === 105 && xeric_budget_spent($db, $now, true)['tokens'] === 5);
$v = xeric_budget_allows($Tm, $db, $now, 'tokens', 1, false);
ok('local spend is never rationed under metered_only', $v['ok'] && $v['left'] === PHP_INT_MAX, json_encode($v));
$v = xeric_budget_allows($Tm, $db, $now, 'tokens', 5, true);
ok('a costly spend counts only costly spend against the ceiling', $v['ok'] && $v['left'] === 5, json_encode($v));
$Tall = $withBudget(['tokens_per_day' => 10, 'metered_only' => false]);
$v = xeric_budget_allows($Tall, $db, $now, 'tokens', 1, false);
ok('metered_only false rations everything, local included', !$v['ok'] && $v['left'] === 0, json_encode($v));
ok('the meter line counts what the ceiling counts',
    xeric_budget_line($Tm, $db, $now) === 'today: 5 of 10 tokens'
    && xeric_budget_line($Tall, $db, $now) === 'today: 105 of 10 tokens');

// ---------------------------------------------------------------------------
echo "\n# allows, left, and the sentence\n";
// ---------------------------------------------------------------------------

$db = $fresh('math');
$now = $at('2026-11-02 12:00');
$Tr = $withBudget(['renders_per_day' => 10, 'clips_per_day' => 40]);
xeric_budget_note($db, $now, 'renders', 7);
$a3 = xeric_budget_allows($Tr, $db, $now, 'renders', 3, true);
$a4 = xeric_budget_allows($Tr, $db, $now, 'renders', 4, true);
ok('seven of ten: three more fit, four do not', $a3['ok'] && $a3['left'] === 3 && $a3['ceiling'] === 10
    && !$a4['ok'] && $a4['left'] === 3, json_encode([$a3, $a4]));
xeric_budget_note($db, $now, 'renders', 5);
$v = xeric_budget_allows($Tr, $db, $now, 'renders', 1, true);
ok('overspent is zero left, never negative', !$v['ok'] && $v['left'] === 0);
ok('the refusal names the kind and the ceiling',
    str_contains($v['reason'], "this world's render budget for today is spent")
    && str_contains($v['reason'], '10 renders a day'), $v['reason']);
ok('a zero amount still asks for one', !xeric_budget_allows($Tr, $db, $now, 'renders', 0, true)['ok']);
ok('kinds with no ceiling are left alone', xeric_budget_allows($Tr, $db, $now, 'tokens', 1, true)['ok']);
ok('a kind that does not exist is a no, never a pass', !xeric_budget_allows($Tr, $db, $now, 'gold', 1, true)['ok']);
ok('the meter line lists only the ceilings that exist',
    xeric_budget_line($Tr, $db, $now) === 'today: 12 of 10 renders, 0 of 40 clips');
$body = xeric_budget_body($v);
ok('the body an endpoint answers with', $body['kind'] === 'budget' && $body['ceiling'] === 10
    && str_starts_with($body['error'], "This world's render budget"));

// ---------------------------------------------------------------------------
echo "\n# fail closed\n";
// ---------------------------------------------------------------------------

$db = $fresh('broken');
$now = xeric_clock_now($db, $T);
$db->exec('DROP TABLE spend');
$v = xeric_budget_allows($withBudget(['tokens_per_day' => 1000]), $db, $now, 'tokens', 1, true);
ok('a ledger that cannot be read refuses a spend a ceiling applies to',
    !$v['ok'] && $v['left'] === 0 && str_contains($v['reason'], 'cannot be read'), json_encode($v));
ok('but with no ceiling it is never even read', xeric_budget_allows($T, $db, $now, 'tokens', 1, true)['ok']);
ok('and local spend under metered_only is never read either',
    xeric_budget_allows($withBudget(['tokens_per_day' => 1000]), $db, $now, 'tokens', 1, false)['ok']);
ok('a broken ledger draws no meter line rather than a wrong one',
    xeric_budget_line($withBudget(['tokens_per_day' => 1000]), $db, $now) === '');

// ---------------------------------------------------------------------------
echo "\n# the sink\n";
// ---------------------------------------------------------------------------

$db = $fresh('sink');
$seen = [];
$meter = function (array $u, string $where) use (&$seen): void { $seen[] = $where; };
xeric_budget_bind(null);
xeric_budget_sink('tokens', $meter)(['prompt_tokens' => 3, 'completion_tokens' => 4], 'https://api.example');
ok('unbound, the session meter still runs and no world is written', $seen === ['https://api.example']
    && xeric_budget_spent($db, xeric_clock_now($db, $T))['tokens'] === 0);
xeric_budget_bind($db, $T);
$now = xeric_clock_now($db, $T);
xeric_budget_sink('tokens', $meter)(['prompt_tokens' => 3, 'completion_tokens' => 4], 'https://api.example');
xeric_budget_sink('tokens')(['prompt_tokens' => 100, 'completion_tokens' => 20], 'http://127.0.0.1:8080');
xeric_budget_sink('renders')(['scenes' => 1, 'cost_units' => 2], 'https://scenes.example');
xeric_budget_sink('renders')(['images' => 1], 'http://localhost:7860');
xeric_budget_sink('clips')(['clips' => 1, 'chars' => 40], 'https://voice.example');
xeric_budget_sink('hearings')(['hearings' => 1, 'seconds' => 2.5], 'https://ears.example');
xeric_budget_sink('renders')(['scenes' => 1], 'stub');
ok('every kind lands in the bound world\'s ledger',
    xeric_budget_spent($db, $now) === ['tokens' => 127, 'renders' => 3, 'clips' => 1, 'hearings' => 1],
    json_encode(xeric_budget_spent($db, $now)));
ok('marked costly by where it went: loopback and a stub are the owner\'s own',
    xeric_budget_spent($db, $now, true) === ['tokens' => 7, 'renders' => 1, 'clips' => 1, 'hearings' => 1],
    json_encode(xeric_budget_spent($db, $now, true)));
ok('an empty usage is no spend', (function () use ($db, $now) {
    xeric_budget_sink('tokens')([], 'https://api.example');
    return xeric_budget_spent($db, $now)['tokens'] === 127;
})());
$db2 = $fresh('sink-broken');
$db2->exec('DROP TABLE spend');
xeric_budget_bind($db2, $T);
$threw = false;
try { xeric_budget_sink('clips')(['clips' => 1], 'https://voice.example'); } catch (Throwable $e) { $threw = true; }
ok('a ledger that will not take the write never fails the call it counts', !$threw);
ok('costliness: a stub model is free, a hosted one is not, loopback is the owner\'s',
    !xeric_budget_llm_costly(['stub' => fn() => []]) && xeric_budget_llm_costly(['base' => 'https://api.openai.com'])
    && !xeric_budget_llm_costly(['base' => 'http://127.0.0.1:8080']) && !xeric_budget_llm_costly(null));
xeric_budget_bind(null);

// ---------------------------------------------------------------------------
echo "\n# the reapers wait\n";
// ---------------------------------------------------------------------------

$db = $fresh('scene');
xeric_clock_begin($db, $T);
xeric_player_move($db, 'bluebird');
$calls = 0;
$stub = ['stub' => function (string $tag, array $brief, array $opts) use (&$calls) {
    $calls++;
    return ['bytes' => 'PNG-' . md5((string)($brief['fingerprint'] ?? '')), 'mime' => 'image/png', 'kind' => 'image'];
}];
$Ts = $withBudget(['renders_per_day' => 1, 'metered_only' => false]);
$id = xeric_scene_enqueue($db, xeric_scene_brief($Ts, $db));
$now = xeric_clock_now($db, $Ts);
xeric_budget_note($db, $now, 'renders', 1, false);
$r = xeric_scene_reap($Ts, $db, $dir . '/scenes', $stub, 3, true);
$row = $db->query('SELECT status, tries FROM scene_jobs WHERE id = ' . $id)->fetch();
ok('out of renders, a scene row waits: pending, no try burned, the machine never asked',
    $row['status'] === 'pending' && (int)$row['tries'] === 0 && $calls === 0 && $r['failed'] === 0
    && preg_grep('/render budget for today is spent/', $r['notes']) !== [], json_encode([$row, $r]));
$r = xeric_scene_reap($withBudget(['renders_per_day' => 2, 'metered_only' => false]), $db, $dir . '/scenes', $stub, 3, true);
ok('and with room under the ceiling the same row is drawn', $r['done'] === 1 && $calls === 1, json_encode($r));
$id2 = xeric_scene_enqueue($db, xeric_scene_brief($Ts, $db, 'first_lutheran'));
$r = xeric_scene_reap($withBudget(['renders_per_day' => 1]), $db, $dir . '/scenes', $stub, 3, true);
ok('a stub is the owner\'s own machine: metered_only leaves it alone', $r['done'] === 1 && $calls === 2,
    json_encode($r));

$db = $fresh('photo');
xeric_clock_begin($db, $T);
xeric_world_state_set($db, 'photos.approved', '1');
xeric_photo_backfill($T, $db);
$pending = count(xeric_photo_jobs($db, 'pending'));
$pcalls = 0;
$pstub = ['stub' => function () use (&$pcalls) { $pcalls++; return ['bytes' => 'PNG', 'mime' => 'image/png']; }];
xeric_budget_note($db, xeric_clock_now($db, $T), 'renders', 1, false);
$r = xeric_photo_reap($withBudget(['renders_per_day' => 1, 'metered_only' => false]), $db, $dir . '/photos', $pstub, 3);
ok('out of renders, the photo reaper leaves every row pending and asks nothing',
    $pending > 0 && count(xeric_photo_jobs($db, 'pending')) === $pending && $pcalls === 0
    && preg_grep('/render budget/', $r['notes']) !== [], json_encode($r));

// ---------------------------------------------------------------------------
echo "\n# the voice and the ears refuse\n";
// ---------------------------------------------------------------------------

$db = $fresh('voice');
xeric_clock_begin($db, $T);
$vcalls = 0;
$vstub = ['stub' => function (string $what, array $req) use (&$vcalls): ?array {
    if ($what === 'voices') return ['af_bella', 'am_adam', 'bf_emma'];
    $vcalls++;
    return ['bytes' => 'FAKE-AUDIO:' . $req['input'], 'mime' => 'audio/mpeg'];
}];
$INV = ['af_bella', 'am_adam', 'bf_emma'];
$Tv = $withBudget(['clips_per_day' => 1, 'metered_only' => false]);
$c1 = xeric_voice_clip($Tv, $db, $dir . '/voice', 'ruth', 1, 'Morning. Sit down.', $vstub, $INV);
ok('under the ceiling a clip is made', is_array($c1) && $vcalls === 1);
xeric_budget_note($db, xeric_clock_now($db, $Tv), 'clips', 1, false);
$err = null;
try { xeric_voice_clip($Tv, $db, $dir . '/voice', 'ruth', 2, 'Pie is gone.', $vstub, $INV); }
catch (XericBudgetOut $e) { $err = $e; }
ok('out of clips, the clip refuses in the world\'s words, as a 503, and the machine is not asked',
    $err !== null && $err->getCode() === 503 && $vcalls === 1
    && str_contains($err->getMessage(), "this world's clip budget for today is spent"),
    $err ? $err->getMessage() : 'no refusal');
$again = xeric_voice_clip($Tv, $db, $dir . '/voice', 'ruth', 3, 'Morning. Sit down.', $vstub, $INV);
ok('but a clip already made is free and stays free', is_array($again) && $again['cached'] && $vcalls === 1);

$db = $fresh('ears');
$WEBM = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 60);
$ecalls = 0;
$estub = ['stub' => function () use (&$ecalls): array { $ecalls++; return ['text' => 'Morning, Dot.']; }];
$Te = $withBudget(['hearings_per_day' => 1, 'metered_only' => false]);
xeric_budget_bind($db, $Te);
ok('under the ceiling the ears listen', (xeric_ears_hear($WEBM, 'audio/webm', [], $estub)['text'] ?? '') === 'Morning, Dot.');
xeric_budget_note($db, xeric_clock_now($db, $Te), 'hearings', 1, false);
$err = null;
try { xeric_ears_hear($WEBM, 'audio/webm', [], $estub); } catch (XericBudgetOut $e) { $err = $e; }
ok('out of hearings, the ears refuse as a 429 and the machine is not asked',
    $err !== null && $err->getCode() === 429 && $ecalls === 1 && str_contains($err->getMessage(), 'hearing budget'));
xeric_budget_bind(null);
ok('and with no world bound there is nothing to ration', (xeric_ears_hear($WEBM, 'audio/webm', [], $estub)['text'] ?? '') !== ''
    && $ecalls === 2);

// ---------------------------------------------------------------------------
echo "\n# the heart's draw-ahead\n";
// ---------------------------------------------------------------------------

$db = $fresh('ahead');
xeric_clock_begin($db, $T);
xeric_player_move($db, 'bluebird');
$calls = 0;
$stub = ['stub' => function (string $tag, array $brief, array $opts) use (&$calls) {
    $calls++;
    return $tag === 'sound' ? ['bytes' => 'OggS', 'mime' => 'audio/ogg', 'kind' => 'audio']
                            : ['bytes' => 'PNG-' . md5((string)($brief['fingerprint'] ?? '')), 'mime' => 'image/png',
                               'kind' => 'image'];
}];
$Ta = $withBudget(['renders_per_day' => 2, 'metered_only' => false]);
$Ta['media']['scene']['prerender'] = 'all';
xeric_budget_note($db, xeric_clock_now($db, $Ta), 'renders', 2, false);
$up = [];
$a = xeric_scene_ahead($Ta, $db, $dir . '/scenes', $stub, 2, $up);
ok('out of renders, drawing ahead skips the whole pass and says why',
    $a['spent'] === true && $a['mode'] === 'all' && $a['enqueued'] === 0 && $a['done'] === 0 && $calls === 0
    && $up === [] && xeric_scene_jobs($db) === []
    && preg_grep('/^not drawing ahead, this world\'s render budget/', $a['notes']) !== [], json_encode($a));
$Ta['budget']['renders_per_day'] = 4;
$a = xeric_scene_ahead($Ta, $db, $dir . '/scenes', $stub, 2, $up);
ok('and with room it draws, at most its limit', $a['spent'] === false && $a['done'] === 2 && $calls === 2,
    json_encode($a));
$a = xeric_scene_ahead($T, $db, $dir . '/scenes', $stub, 2, $up);
ok('a world with no block draws ahead as it always did', $a['spent'] === false && $a['mode'] === 'here');

// ---------------------------------------------------------------------------
echo "\n# the heart stops and says why\n";
// ---------------------------------------------------------------------------

$db = $fresh('why');
$now = $at('2026-11-02 14:10');
ok('nothing stopped is nothing to say', xeric_budget_why($db, $now) === null);
xeric_budget_stopped($db, $now, xeric_budget_allows($withBudget(['tokens_per_day' => 5]), $db, $now, 'tokens', 9, true));
ok('a world stopped for its tokens says when and why',
    str_contains((string)xeric_budget_why($db, $now), 'stopped living hours at 14:10')
    && str_contains((string)xeric_budget_why($db, $now), 'token budget'));
ok('and it is a fact about that day only', xeric_budget_why($db, $at('2026-11-03 09:00')) === null);

$heart = (string)file_get_contents(dirname(__DIR__, 2) . '/forge/web/heart.php');
ok('the heart asks the token ceiling before it lives an hour, and writes down why it stopped',
    ($p = strpos($heart, "xeric_budget_allows(\$T, \$db, xeric_clock_now(\$db, \$T), 'tokens'")) !== false
    && ($q = strpos($heart, 'xeric_budget_stopped(')) !== false && $q < (int)strpos($heart, 'xeric_sweep_catchup('));

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
