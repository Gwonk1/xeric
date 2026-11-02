<?php
/**
 * Xeric — the narrator on the web. `php forge/web/tests/narrator-web-test.php`, exit 0 on pass.
 *
 * engine/tests/narrator-test.php proves the narrator's rules; this proves the
 * web half (play-narrator.php, the play view's pane and doors, why.php's
 * audit). NO NETWORK, NO MODEL: in-process calls go through the engine's stub
 * seam, and the pages that must refuse or render are real PHP requests in a
 * child process (whose machine is an address nothing answers at).
 *
 *   1. A GUEST NEVER ASKS AND NEVER WRITES AHEAD — 403, nothing stored.
 *   2. ASK IS KEPT: one conversation of kind narrator, handle '', read on arrival.
 *   3. THE PANE OPENS ON THE LAST FEW, and on the owner's intents.
 *   4. WRITE AHEAD STORES AND LISTS, and strikes what an hour delivered.
 *   5. THE AUDIT RENDERS FOR THE OWNER in why.php, linked to what it names.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-narrator-web-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called
putenv('XERIC_CAPS=1');                              // the meter is on: spend is asserted
putenv('XERIC_SOLO=0');                              // visitors, so an owner and a guest are two people
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_VOICE_BASE', 'XERIC_IMAGE_BASE'] as $k) putenv($k);

require_once __DIR__ . '/../play-lib.php';

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

function rmtree(string $d): void
{
    foreach (glob($d . '/*') ?: [] as $p) { is_dir($p) ? rmtree($p) : @unlink($p); }
    @rmdir($d);
}

/** A page, run as a real request in a child process, as the session named. */
function page(string $script, string $sid, array $get, array $post = [], string $method = 'POST'): array
{
    global $tmp;
    $boot = '$_GET = ' . var_export($get, true) . ';'
        . ' $_POST = ' . var_export($post, true) . ';'
        . ' $_COOKIE = [' . var_export(XERIC_WEB_COOKIE, true) . ' => ' . var_export($sid, true) . '];'
        . ' $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';'
        . ' $_SERVER["HTTP_ACCEPT"] = "application/json";'
        . ' register_shutdown_function(function () { echo "\n@@STATUS " . http_response_code(); });'
        . ' require ' . var_export(dirname(__DIR__) . '/' . $script, true) . ';';
    $cmd = 'XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
         . ' XERIC_LOCAL_BASE=http://127.0.0.1:1 XERIC_CAPS=1 XERIC_SOLO=0'
         . ' ' . escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($boot) . ' 2>&1';
    $raw = (string)shell_exec($cmd);
    $status = preg_match('/@@STATUS (\d+)\s*$/', $raw, $m) ? (int)$m[1] : 0;
    $body = trim((string)preg_replace('/@@STATUS \d+\s*$/', '', $raw));
    $json = json_decode($body, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $body];
}

/** The stub machine: one numbered line per call, every call captured. */
function stub(array &$calls, ?callable $line = null): array
{
    $n = 0;
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs) use (&$calls, &$n, $line) {
        $calls[] = ['tag' => $tag, 'msgs' => $msgs];
        if ($tag === 'extract') return ['memories' => []];
        $n++;
        return $line !== null ? $line($n) : 'A plain line, number ' . $n . '.';
    }];
}

$fixT = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$slug = 'narr-town';
$wdir = xeric_web_worlds_dir() . '/' . $slug;
@mkdir($wdir, 0775, true);
file_put_contents($wdir . '/world-template.json',
    json_encode($fixT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($wdir . '/seed.json',
    json_encode(xeric_forge_default_seed($fixT), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$machine = function (array &$s): void {
    $s['model'] = ['kind' => 'local', 'local' => 'http://127.0.0.1:1', 'base' => '', 'model' => ''];
};
$A = bin2hex(random_bytes(16));                      // the owner
xeric_session_use($A);
xeric_session_claim($slug, $A);
xeric_web_session_edit($machine, $A);
$w  = xeric_play_open($slug);
$T  = $w['template'];
$db = $w['db'];

$G = bin2hex(random_bytes(16));                      // a guest, on the owner's db
xeric_session_use($G);
xeric_session_join($slug, 2, $G);
xeric_web_session_edit($machine, $G);
xeric_session_use($A);

$seen = [];
$stub = function (string $reply) use (&$seen): array {
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs) use (&$seen, $reply) {
        $seen[] = ['tag' => $tag, 'msgs' => $msgs];
        return $reply;
    }];
};

// ---------------------------------------------------------------------------
echo "\n# the door\n";

$count = fn(): int => (int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$before = $count();
$gh = page('play.php', $G, ['w' => $slug, 'a' => 'hint', 'ask' => 'who did it?'], [], 'GET');
ok('a guest asking the narrator is refused, 403, kind guest',
    $gh['status'] === 403 && ($gh['json']['kind'] ?? '') === 'guest', $gh['status'] . ' ' . mb_substr($gh['raw'], 0, 200));
ok('and the refusal stored nothing', $count() === $before && xeric_play_narrator_conv($db) === null);
$gi = page('play.php', $G, ['w' => $slug, 'a' => 'intend'], ['text' => 'Dot and Theo share a meal']);
ok('nor may a guest write ahead', $gi['status'] === 403 && xeric_narrator_intents($db) === [], (string)$gi['status']);
$pp = (string)file_get_contents(dirname(__DIR__) . '/play.php');
ok('the ask is metered like a message and asks whose world it is first',
    preg_match("/action === 'hint'.*?xeric_play_owner_only\\(.*?xeric_limit_check\\('message'.*?xeric_limit_note\\('message'.*?xeric_play_narrator_ask\\(/s", $pp) === 1);

// ---------------------------------------------------------------------------
echo "\n# ask, kept\n";

$r1 = xeric_play_narrator_ask($w, 'Why has nothing happened at the mill?', $stub('Quiet hours, and nobody was free.'), true);
$conv = xeric_conversation_find($db, '', 'narrator');
ok('the answer comes back in the narrator\'s words', $r1['text'] === 'Quiet hours, and nobody was free.' && $r1['stored']);
ok('one conversation of kind narrator, handle empty', $conv !== null && (string)$conv['handle'] === ''
    && (string)$conv['kind'] === 'narrator');
$rows = xeric_messages_recent($db, (int)$conv['id'], 10);
ok('the question and the answer, as user and narrator rows', count($rows) === 2
    && $rows[0]['role'] === 'user' && $rows[1]['role'] === 'narrator');
ok('read on arrival: the narrator never lights the unread badge', (int)$conv['unread'] === 0
    && xeric_conversation_unread_total($db) === 0);
ok('one model call, through the narrator seam, full canon in the system message',
    count($seen) === 1 && $seen[0]['tag'] === 'narrator'
    && str_contains($seen[0]['msgs'][0]['content'], 'YOU ARE THE NARRATOR')
    && str_contains($seen[0]['msgs'][0]['content'], 'green spiral notebook'));

$seen = [];
$r2 = xeric_play_narrator_ask($w, 'And why?', $stub("I'm sorry, but I can't help with that."), true);
ok('a follow-up carries the last exchange ahead of the question',
    str_contains($seen[0]['msgs'][1]['content'], 'Why has nothing happened at the mill?')
    && str_contains($seen[0]['msgs'][1]['content'], 'WHAT YOU HAVE ALREADY BEEN ASKED'));
ok('a policy fallback is kept as the narrator\'s own decline', $r2['text'] === XERIC_NARRATOR_DECLINE
    && (array_slice(xeric_play_narrator_history($db), -1)[0]['a'] ?? '') === XERIC_NARRATOR_DECLINE);
ok('the book passes over the narrator\'s thread (handle empty is not a scene)',
    xeric_book_scenes($T, $db, PHP_INT_MIN, PHP_INT_MAX) === []);
$seen = [];
ok('the old hint door now delegates to the same ask',
    xeric_play_hint($T, $db, 'where is everyone?', $stub('At the Bluebird, most of them.')) === 'At the Bluebird, most of them.'
    && ($seen[0]['tag'] ?? '') === 'narrator');
$failBefore = $count();
$threw = false;
try { xeric_play_narrator_ask($w, 'Anything?', ['base' => 'stub://', 'stub' => function () { throw new RuntimeException('down'); }], true); }
catch (Throwable $e) { $threw = true; }
ok('fail closed: a model that is not there stores nothing', $threw && $count() === $failBefore);

// ---------------------------------------------------------------------------
echo "\n# write ahead\n";

$oi = page('play.php', $A, ['w' => $slug, 'a' => 'intend'], ['text' => 'INTENT-WEB-NEEDLE Dot Vance and Theo Vance end up at the same table']);
ok('the owner writes ahead: stored, and the listing comes back',
    $oi['status'] === 200 && ($oi['json']['ok'] ?? false) === true
    && str_contains((string)($oi['json']['html'] ?? ''), 'INTENT-WEB-NEEDLE'), $oi['status'] . ' ' . mb_substr($oi['raw'], 0, 300));
$ins = xeric_narrator_intents($db);
ok('the ledger holds it, with its people read off its words',
    count($ins) === 1 && ($ins[1]['participants'] ?? []) === ['dot', 'theo'] && $ins[1]['state'] === 'live');
$bad = page('play.php', $A, ['w' => $slug, 'a' => 'intend'], ['text' => '']);
ok('an empty beat is refused in a sentence', $bad['status'] === 422 && str_contains((string)($bad['json']['error'] ?? ''), 'needs words'));

$ev = xeric_event_add($db, 'Dot and Theo at the same table', xeric_clock_epoch($db) + 60, 'bluebird', ['dot', 'theo'],
    'Dot Vance and Theo Vance end up at the same table and the pie runs out.', null, false);
$deliv = xeric_narrator_intents_deliver($db, [1], ['id' => $ev, 'world_epoch' => xeric_clock_epoch($db) + 60,
    'place' => 'bluebird', 'participants' => ['dot', 'theo'], 'title' => 'Dot and Theo at the same table',
    'prose' => 'Dot Vance and Theo Vance end up at the same table and the pie runs out.'], 'shared_meal');
$list = xeric_play_narrator_intents_html($w);
ok('a delivered beat is struck through, linked to the hour that delivered it',
    $deliv === [1] && str_contains($list, '<s>#') === false && str_contains($list, '<s>INTENT-WEB-NEEDLE')
    && str_contains($list, 'e=' . $ev), $list);

// ---------------------------------------------------------------------------
echo "\n# the pane\n";

$own = page('play.php', $A, ['w' => $slug], [], 'GET');
ok('the owner\'s pane opens on the kept exchanges', str_contains($own['raw'], 'Why has nothing happened at the mill?')
    && str_contains($own['raw'], 'Quiet hours, and nobody was free.'), mb_substr($own['raw'], 0, 200));
ok('and on the write-ahead list, with its box', str_contains($own['raw'], 'id="nintents"')
    && str_contains($own['raw'], 'INTENT-WEB-NEEDLE') && str_contains($own['raw'], 'id="nintendgo"'));
$gp = page('play.php', $G, ['w' => $slug], [], 'GET');
ok('a guest\'s pane has no box and no intents', !str_contains($gp['raw'], 'id="ncomposer"')
    && !str_contains($gp['raw'], 'INTENT-WEB-NEEDLE') && str_contains($gp['raw'], 'You are a guest'),
    mb_substr($gp['raw'], 0, 200));
ok('every word of it escaped: the owner\'s own words go through h()',
    !str_contains(xeric_play_narrator_intents_html($w, [7 => ['text' => '<b>x</b>', 'state' => 'live', 'fades' => PHP_INT_MAX]]), '<b>x</b>'));

// ---------------------------------------------------------------------------
echo "\n# the audit, in the inspector\n";

$e1 = xeric_event_add($db, 'Harlan at the hardware', xeric_clock_epoch($db) - 7200, 'beck_hardware', ['harlan'], 'He counted screws.', null, false);
$e2 = xeric_event_add($db, 'Harlan at the diner', xeric_clock_epoch($db) - 7200 + 600, 'bluebird', ['harlan'], 'He had pie.', null, false);
$wa = page('why.php', $A, ['w' => $slug, 'audit' => '1'], [], 'GET');
ok('the owner\'s audit renders, with the contradiction linked to both hours',
    str_contains($wa['raw'], 'The narrator&#039;s audit') || str_contains($wa['raw'], "The narrator's audit"));
ok('…linked to both events it names', str_contains($wa['raw'], 'contradictions in the record')
    && str_contains($wa['raw'], 'e=' . $e1) && str_contains($wa['raw'], 'e=' . $e2), mb_substr(strip_tags($wa['raw']), 0, 600));
ok('the audit was kept for the world-hour, outside the world\'s database',
    is_file(xeric_play_narrator_audit_path($w)) && xeric_play_narrator_audit_cached($w));
ok('a GET never spends: the threads are read only from the button, a POST',
    str_contains($wa['raw'], 'Read the threads too') && str_contains($wa['raw'], 'method="post"'));
$cDot = xeric_conversation_for($db, 'dot', 'chat');
xeric_message_append($db, $cDot, 'user', null, 'Is the pie any good today?', xeric_clock_epoch($db) - 5 * 86400);
$msgLeft = (int)xeric_limit_left(xeric_session_id())['messages'];
$wp = page('why.php', $A, ['w' => $slug, 'audit' => '1'], ['go' => '1'], 'POST');
ok('the POST asks the machine; one that does not answer leaves the arithmetic, and charges nothing',
    str_contains($wp['raw'], 'did not answer') && str_contains($wp['raw'], 'e=' . $e1)
    && (int)xeric_limit_left(xeric_session_id())['messages'] === $msgLeft,
    xeric_limit_left(xeric_session_id())['messages'] . ' vs ' . $msgLeft);
$wi = page('why.php', $A, ['w' => $slug], [], 'GET');
ok('the inspector\'s index offers it to the owner', str_contains($wi['raw'], 'audit=1'));
$wg = page('why.php', $G, ['w' => $slug, 'audit' => '1'], [], 'GET');
ok('a guest reading the inspector is not handed the audit', !str_contains($wg['raw'], 'contradictions in the record'));

$w = null; $db = null;
gc_collect_cycles();
rmtree($tmp);

echo "\n" . ($FAILED === 0 ? "PASS ($COUNT)" : "FAIL ($FAILED of $COUNT)") . "\n";
exit($FAILED === 0 ? 0 : 1);
