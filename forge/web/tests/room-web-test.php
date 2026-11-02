<?php
/**
 * Xeric — the room on the web. `php forge/web/tests/room-web-test.php`, exit 0 on pass.
 *
 * engine/tests/room-test.php proves the session's rules; this proves the web
 * half that carries them (play-lib's room functions, room.php, room-worker.php
 * and the doors around them). NO NETWORK, NO MODEL: every call goes through
 * the engine's stub seam, the worker's loop is driven in-process with a sleep
 * that records instead of sleeping, and the pages that must refuse are run as
 * real PHP requests in a child process.
 *
 * What is being defended, in the order it would hurt:
 *
 *   1. A GUEST READS THE ROOM AND NEVER SPEAKS INTO IT — 403, before anything
 *      is opened, written or spent.
 *   2. THE ROWS ARE THE THREAD'S OWN: user, character, narrator; a cast row is
 *      one speak.php can voice, the player's is not.
 *   3. THE MODEL SLOT IS HELD FOR ONE CALL AND NEVER ACROSS A PAUSE, and no
 *      line exists before its due time — so no client can show one early.
 *   4. A CAST BEAT SPENDS, on the owner, as a reroll; a player line as a message.
 *   5. WALKING OUT SEALS; A SKIP REFUSES MID-TURN; A FORK INHERITS NO TABLE.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-room-web-test-' . getmypid();
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

// A world where three adults are always at the Bluebird, the other three
// elsewhere — so whatever the wall clock says, the room has company.
$fixT = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$all  = [0, 1, 2, 3, 4, 5, 6];
foreach ($fixT['cast']['characters'] as &$c) {
    $c['week'] = match ((string)$c['handle']) {
        'ruth', 'pastor_dale', 'dot' => [['days' => $all, 'where' => 'bluebird', 'doing' => 'at the counter']],
        'janelle'                    => [['days' => $all, 'where' => 'first_lutheran', 'doing' => 'setting up']],
        default                      => [['days' => $all, 'where' => 'beck_hardware', 'doing' => 'at the till']],
    };
}
unset($c);
$slug = 'room-town';
$wdir = xeric_web_worlds_dir() . '/' . $slug;
@mkdir($wdir, 0775, true);
file_put_contents($wdir . '/world-template.json',
    json_encode($fixT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($wdir . '/seed.json',
    json_encode(xeric_forge_default_seed($fixT), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$A = bin2hex(random_bytes(16));                      // the owner
xeric_session_use($A);
xeric_session_claim($slug, $A);
// A machine is attached (never called — every call below is the stub), so
// opening the world does not stop its clock out from under the room.
xeric_web_session_edit(function (array &$s): void {
    $s['model'] = ['kind' => 'local', 'local' => 'http://127.0.0.1:1', 'base' => '', 'model' => ''];
}, $A);
$w  = xeric_play_open($slug);
$T  = $w['template'];
$db = $w['db'];
if (xeric_clock_is_paused($db)) xeric_clock_resume($db);
$now = xeric_clock_now($db, $T);
xeric_player_move($db, 'bluebird', (int)$now['epoch']);

$G = bin2hex(random_bytes(16));                      // a guest, in on a code, on the owner's db
xeric_session_use($G);
xeric_session_join($slug, 2, $G);
// No machine of the guest's own: a guest's open never reconciles the owner's
// clock (xeric_play_open), which "# together" below pins.
xeric_session_use($A);

// ---------------------------------------------------------------------------
echo "\n# the door\n";

$before = (int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$gs = page('room.php', $G, ['w' => $slug, 'a' => 'say'], ['text' => 'Hello, everybody.']);
ok('a guest saying something to the room is refused, 403, kind guest',
    $gs['status'] === 403 && ($gs['json']['kind'] ?? '') === 'guest', $gs['status'] . ' ' . mb_substr($gs['raw'], 0, 200));
ok('and the refusal wrote nothing', (int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn() === $before
    && xeric_room_live_get($db) === null);
$gc = page('room.php', $G, ['w' => $slug, 'a' => 'carry']);
ok('nor may a guest ask the room to talk on its own', $gc['status'] === 403, (string)$gc['status']);
$gl = page('room.php', $G, ['w' => $slug, 'a' => 'leave']);
ok('nor end somebody else\'s conversation', $gl['status'] === 403, (string)$gl['status']);
$rp = (string)file_get_contents(dirname(__DIR__) . '/room.php');
ok('room.php asks before every write — three doors, three owner checks', substr_count($rp, 'xeric_play_owner_only(') === 3);

// ---------------------------------------------------------------------------
echo "\n# a line, and the turn it buys\n";

$say = xeric_play_room_say($w, 'Morning. Dot, is the pie any good today?');
$live = xeric_room_live_get($db);
ok('the first line opens the session and lands as its row at once',
    $say['opened'] && $live !== null && (int)$live['said'] === 1 && $say['lease'] !== null
    && count(xeric_room_rows($db, (int)$live['conv'])) === 1);
ok('the session is seated from the schedule: the three at the Bluebird, and you',
    $live['present'] === ['ruth', 'pastor_dale', 'dot'] && $live['names']['__you'] === 'Walt', json_encode($live['present']));
$conv = xeric_conversation_get($db, (int)$live['conv']);
ok('one conversation of kind room, handle empty, the place as its title',
    (string)$conv['kind'] === 'room' && (string)$conv['handle'] === '' && (string)$conv['title'] === 'bluebird');
$pub = xeric_play_room_public($T, $live, true);
ok('the page\'s summary is names and state, never a prompt',
    $pub['turn'] === true && count($pub['present']) === 3 && !str_contains(json_encode($pub), 'YOU ARE'));
ok('the play state carries the summary so the strip opens', (xeric_play_state($w, $A)['room']['conv'] ?? 0) === (int)$live['conv']);
$look = xeric_play_look_html($T, $db, null, $slug, true);
ok('the look block becomes the way back to the room, and says no beat of its own',
    str_contains($look, 'back to the room') && !str_contains($look, 'lookbeat'));

$second = xeric_play_room_say($w, 'And the coffee, Dot?');
ok('a second line while the turn is live books itself and starts nobody new', $second['lease'] === null
    && (int)xeric_room_live_get($db)['said'] === 2);

// -- the worker, in-process ----------------------------------------------------
$calls = []; $sleeps = []; $busyInGap = false; $earlyRows = [];
$hitsBefore = (int)xeric_limit_hits('reroll-' . $A, XERIC_LIMIT_HOUR, time())['count'];
$msgsBefore = (int)xeric_limit_hits('msg-' . $A, XERIC_LIMIT_HOUR, time())['count'];
$r = xeric_play_room_run($w, $A, (string)$say['lease'], [
    'endpoint' => function (string $h) use (&$calls): array {
        return stub($calls, fn(int $n) => 'Best in the county, Walt.');
    },
    'up'       => false,
    'wait'     => 2.0,
    'seed'     => 'web',
    'sleep'    => function (float $s) use (&$sleeps, &$busyInGap, &$earlyRows, $db, &$calls): void {
        $sleeps[] = $s;
        // BETWEEN generating a line and showing it: the slot is free, and the
        // line is not a row yet — there is nothing for any client to show.
        if (xeric_queue_busy()) $busyInGap = true;
        $live = xeric_room_live_get($db);
        $earlyRows[] = count(xeric_room_rows($db, (int)$live['conv']));
    },
]);
$live  = xeric_room_live_get($db);
$rows  = xeric_room_rows($db, (int)$live['conv']);
$chat  = array_values(array_filter($calls, fn($c) => $c['tag'] === 'chat'));
ok('the worker answered, one call a beat', $r['beats'] >= 1 && count($chat) === $r['beats'],
    json_encode($r) . ' / ' . count($chat));
ok('the model slot is never held across the pause between a line and its showing', $sleeps !== [] && !$busyInGap);
ok('no line is a row before its due time — each sleep happened with the line not yet written',
    $earlyRows === array_map(fn($i) => 2 + $i, range(0, count($earlyRows) - 1)), json_encode($earlyRows));
ok('the slot is free when the turn is done', !xeric_queue_busy());
ok('the lease is handed back, and nobody is typing', $live['lease'] === null && $live['typing'] === null);
ok('a line naming Walt hands the floor back', count($chat) === 1 && (string)$rows[2]['content'] === 'Best in the county, Walt.'
    && (string)$rows[2]['handle'] === 'dot', json_encode(array_column($rows, 'content')));
$hitsAfter = (int)xeric_limit_hits('reroll-' . $A, XERIC_LIMIT_HOUR, time())['count'];
ok('each cast beat spent one reroll of the owner\'s hour', $hitsAfter - $hitsBefore === $r['beats'],
    ($hitsAfter - $hitsBefore) . ' vs ' . $r['beats']);
ok('and none of the player\'s messages', (int)xeric_limit_hits('msg-' . $A, XERIC_LIMIT_HOUR, time())['count'] === $msgsBefore);

// -- roles and the speaker ------------------------------------------------------
$byRole = array_count_values(array_map(fn($x) => (string)$x['role'], $rows));
ok('the rows are the thread\'s three roles', ($byRole['user'] ?? 0) === 2 && ($byRole['character'] ?? 0) === $r['beats']);
ok('a cast row is one speak.php can voice, with the speaker\'s handle', xeric_play_spoken($db, (int)$rows[2]['id'])['handle'] === 'dot');
ok('the player\'s own row is not', xeric_play_spoken($db, (int)$rows[0]['id']) === null);
$drawn = xeric_play_room_rows($T, $live, $rows);
ok('the page draws them in its lanes, named', $drawn[0]['who'] === 'me' && $drawn[0]['name'] === 'Walt'
    && $drawn[2]['who'] === 'them' && $drawn[2]['name'] === 'Dot Vance');

// -- a guest reads -----------------------------------------------------------
$gr = page('room.php', $G, ['w' => $slug, 'after' => '0'], [], 'GET');
ok('a guest may read the room: the lines, and a summary that is not theirs to speak into',
    $gr['status'] === 200 && count((array)($gr['json']['rows'] ?? [])) === count($rows)
    && ($gr['json']['live']['mine'] ?? true) === false && ($gr['json']['guest'] ?? false) === true,
    $gr['status'] . ' ' . mb_substr($gr['raw'], 0, 200));
$after = page('room.php', $A, ['w' => $slug, 'after' => (string)$rows[1]['id']], [], 'GET');
ok('the cursor is a message id: only what came after it', count((array)($after['json']['rows'] ?? [])) === count($rows) - 2,
    mb_substr($after['raw'], 0, 200));

// -- the player speaks first ---------------------------------------------------
$s3 = xeric_play_room_say($w, 'Ruth, and you?');
$dropCalls = []; $saidDuring = false;
$r3 = xeric_play_room_run($w, $A, (string)$s3['lease'], [
    'endpoint' => function (string $h) use (&$dropCalls, &$saidDuring, $w): array {
        // The model is thinking — and the player says something else.
        if (!$saidDuring) { $saidDuring = true; xeric_play_room_say($w, 'Actually, never mind that.'); }
        return stub($dropCalls, fn(int $n) => 'Plain again, ' . $n . '.');
    },
    'up' => false, 'wait' => 2.0, 'seed' => 'drop', 'sleep' => fn(float $s) => null,
]);
$live3 = xeric_room_live_get($db);
ok('a line written while the player spoke again is dropped, and the trail says so',
    $r3['dropped'] >= 1 && ($live3['dropped'][0]['why'] ?? '') === 'you spoke first', json_encode($r3));
ok('and the room answers the newer line instead', $r3['beats'] >= 1);

// ---------------------------------------------------------------------------
echo "\n# the skip and the walk\n";

xeric_room_live_edit($db, function (?array &$l): void {
    $l['lease'] = ['id' => 'held', 'until' => microtime(true) + 60]; $l['budget'] = 1;
});
ok('mid-turn is visible to the time control', xeric_play_room_midturn($db));
$tk = page('tick.php', $A, ['w' => $slug], ['w' => $slug, 'span' => 'hour']);
ok('a skip while somebody is answering is refused, plainly', $tk['status'] === 409 && ($tk['json']['kind'] ?? '') === 'room',
    $tk['status'] . ' ' . mb_substr($tk['raw'], 0, 200));
ok('and the room is untouched by the refusal', xeric_room_live_get($db) !== null);
xeric_room_live_edit($db, function (?array &$l): void { $l['lease'] = null; $l['budget'] = 0; });
ok('between turns there is no mid-turn', !xeric_play_room_midturn($db));

$convW = (int)xeric_room_live_get($db)['conv'];
$wk = page('where.php', $A, ['w' => $slug], ['w' => $slug, 'to' => 'beck_hardware']);
ok('the walk itself goes', $wk['status'] === 200 && !empty($wk['json']['ok']), $wk['status'] . ' ' . mb_substr($wk['raw'], 0, 200));
ok('walking out seals the conversation at once', xeric_room_live_get($db) === null);
$landed = false;
for ($i = 0; $i < 40 && !$landed; $i++) {
    if (isset(xeric_room_seals($db)[$convW])) { $landed = true; break; }
    foreach (xeric_events_recent($db, 5) as $e) {
        $tr = json_decode((string)(xeric_world_state_get($db, 'why:event:' . (int)$e['id']) ?? ''), true);
        if (is_array($tr) && (int)($tr['conversation'] ?? 0) === $convW) { $landed = true; break 2; }
    }
    usleep(150000);
}
ok('and it is either waiting for its close or already closed, for the reason that it was walked out of',
    $landed && (!isset(xeric_room_seals($db)[$convW]) || xeric_room_seals($db)[$convW]['ended'] === 'you left'));
$look2 = xeric_play_look_html($T, $db, null, $slug, true);
ok('in the next room, with two people at the till, the door is offered again', str_contains($look2, 'talk to the room'));

// -- the close lands through the engine ---------------------------------------
for ($i = 0; $i < 40 && isset(xeric_room_seals($db)[$convW]); $i++) usleep(150000);   // let the worker finish, if it is landing
$land = xeric_play_room_land($w, null);
$ev = null;
foreach (xeric_events_recent($db, 10) as $e) {
    $tr = json_decode((string)(xeric_world_state_get($db, 'why:event:' . (int)$e['id']) ?? ''), true);
    if (is_array($tr) && (int)($tr['conversation'] ?? 0) === $convW) { $ev = $e + ['trail' => $tr]; break; }
}
ok('the close landed one event for the conversation, with the trail the inspector reads',
    $ev !== null && ($ev['trail']['kind'] ?? '') === 'room' && ($ev['trail']['ended'] ?? '') === 'you left',
    json_encode($land));
ok('the book has a line for the room', (static function () use ($T, $db, $ev): bool {
    if ($ev === null) return false;
    foreach (xeric_book_days($T, $db, xeric_clock_now($db, $T))['days'] as $d) {
        foreach ($d['items'] as $it) if ($it['kind'] === 'room' && (int)$it['event']['id'] === (int)$ev['id']) return true;
    }
    return false;
})());
require_once dirname(__DIR__) . '/why-lib.php';
$whyHtml = $ev !== null ? xeric_why_room_html($T, $ev['trail']) : '';
ok('the inspector says who answered, how, with the weights, and why it ended',
    str_contains($whyHtml, 'the room, beat by beat') && str_contains($whyHtml, 'Dot Vance')
    && str_contains($whyHtml, 'message #') && str_contains($whyHtml, 'you walked out')
    && str_contains($whyHtml, 'set aside'), mb_substr(strip_tags($whyHtml), 0, 300));

// ---------------------------------------------------------------------------
echo "\n# talking without you\n";

xeric_player_move($db, 'bluebird', (int)xeric_clock_now($db, $T)['epoch']);
$s4 = xeric_play_room_say($w, 'Back again.');
xeric_play_room_run($w, $A, (string)$s4['lease'], ['endpoint' => function (string $h) use (&$calls): array { return stub($calls); },
    'up' => false, 'wait' => 2.0, 'sleep' => fn(float $s) => null]);
$c1 = xeric_play_room_carry($w, $A);
ok('an idle room on a local machine may take one beat', $c1['ok'] && $c1['lease'] !== null, $c1['why']);
xeric_play_room_run($w, $A, (string)$c1['lease'], ['endpoint' => function (string $h) use (&$calls): array { return stub($calls); },
    'up' => false, 'wait' => 2.0, 'sleep' => fn(float $s) => null]);
$c2 = xeric_play_room_carry($w, $A);
xeric_play_room_run($w, $A, (string)($c2['lease'] ?? ''), ['endpoint' => function (string $h) use (&$calls): array { return stub($calls); },
    'up' => false, 'wait' => 2.0, 'sleep' => fn(float $s) => null]);
$c3 = xeric_play_room_carry($w, $A);
ok('two in a row, and then it is your turn', $c2['ok'] && !$c3['ok'] && str_contains($c3['why'], 'your turn'), $c3['why']);
$liveC = xeric_room_live_get($db);
ok('the beats said alone are marked in the trail', count(array_filter((array)$liveC['trail'], fn($b) => !empty($b['alone']))) === 2);

// ---------------------------------------------------------------------------
echo "\n# a fork inherits no table\n";

$copy = $tmp . '/fork-copy.db';
$db->exec("VACUUM INTO " . $db->quote($copy));
$cdb = xeric_state_open($copy);
xeric_memory_add($cdb, 'ruth', 'Ruth heard Walt ask after the pie.', 'room.you', ['you' => 'Walt']);
xeric_world_state_set($cdb, XERIC_ROOM_SEAL . '99', json_encode(['conv' => 99]));
$cdb = null;
ok('the fork scrub runs', xeric_session_fork_clear($copy));
$cdb = xeric_state_open($copy);
ok('a fork has no live room and no sealed one waiting',
    xeric_room_live_get($cdb) === null && xeric_room_seals($cdb) === []
    && (int)$cdb->query("SELECT COUNT(*) FROM world_state WHERE key LIKE 'room:%'")->fetchColumn() === 0);
ok('nor any diary a room of the owner\'s wrote', (int)$cdb->query("SELECT COUNT(*) FROM memories WHERE source LIKE 'room%'")->fetchColumn() === 0);

// ---------------------------------------------------------------------------
echo "\n# the close waits for a model\n";

/** The diary machine: extracts answer with one memory per head, named for them. */
function diarist(): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs): array|string {
        if ($tag !== 'extract') return 'A line.';
        preg_match('/Write 0-3 things (.+?) would still know/', (string)$msgs[1]['content'], $m);
        return ['memories' => [($m[1] ?? 'Somebody') . ' sat a while at the counter with the visitor and the coffee.']];
    }];
}
$roomEvent = function (PDO $db, int $conv): ?array {
    foreach (xeric_events_recent($db, 20) as $e) {
        $tr = json_decode((string)(xeric_world_state_get($db, 'why:event:' . (int)$e['id']) ?? ''), true);
        if (is_array($tr) && (int)($tr['conversation'] ?? 0) === $conv) return $e + ['trail' => $tr];
    }
    return null;
};
$roomDiaries = fn(PDO $db): int => (int)$db->query("SELECT COUNT(*) FROM memories WHERE source = 'room.you'")->fetchColumn();

$convD = (int)xeric_room_live_get($db)['conv'];
xeric_room_seal($db, 'stepped back', xeric_clock_now($db, $T));
$downLand = xeric_play_room_land_up($w, ['base' => 'http://127.0.0.1:1', 'kind' => 'local']);
ok('a sealed room with the model down keeps its seal — no event, no forfeited diaries',
    $downLand === null && isset(xeric_room_seals($db)[$convD]) && $roomEvent($db, $convD) === null);
$diariesD = $roomDiaries($db);
$upLand = xeric_play_room_land_up($w, diarist());
ok('and with the model up it lands: one event, the diaries, the seal gone',
    is_array($upLand) && !isset(xeric_room_seals($db)[$convD]) && $roomEvent($db, $convD) !== null
    && $roomDiaries($db) > $diariesD, json_encode($upLand));
ok('the heart and the room worker both land only through it',
    str_contains((string)file_get_contents(dirname(__DIR__) . '/heart.php'), 'xeric_play_room_land_up($w, $endpoint)')
    && str_contains((string)file_get_contents(dirname(__DIR__) . '/room-worker.php'), 'xeric_play_room_land_up($w, $endpoint)')
    && !preg_match('/xeric_play_room_land\(\$w, [^)]*\? \$endpoint : null\)/',
        file_get_contents(dirname(__DIR__) . '/heart.php') . file_get_contents(dirname(__DIR__) . '/room-worker.php')));

// ---------------------------------------------------------------------------
echo "\n# the lease outlives a slow beat\n";

$sL = xeric_play_room_say($w, 'Slow morning, Ruth?');
$freshLater = [];
$rL = xeric_play_room_run($w, $A, (string)$sL['lease'], [
    'endpoint' => function (string $h) use ($db): array {
        return ['base' => 'stub://', 'stub' => function (string $tag) use ($db): string {
            // The model took most of the lease: by the time it answers, half a
            // second of it is left (a 108-second beat on a 120-second lease).
            xeric_room_live_edit($db, function (?array &$l): void { $l['lease']['until'] = microtime(true) + 0.5; });
            return 'Slow enough.';
        }];
    },
    'up' => false, 'wait' => 2.0, 'seed' => 'slow',
    'sleep' => function (float $s) use ($db, &$freshLater): void {
        // The reading-time gap, and a line the player might send in it: would
        // it find the turn still carried, or start a second worker?
        $freshLater[] = xeric_room_lease_fresh(xeric_room_live_get($db), microtime(true) + 60);
    },
]);
ok('a slow call renews the lease the moment it returns: one worker, nothing discarded as not ours',
    $freshLater !== [] && !in_array(false, $freshLater, true) && $rL['beats'] >= 1 && $rL['dropped'] === 0
    && $rL['stopped'] !== 'not ours', json_encode([$rL, $freshLater]));
$pvSrc = (string)file_get_contents(dirname(__DIR__) . '/play-view.php');
ok('and every queue update while it waits in line renews it too',
    (bool)preg_match('/function \(int \$ahead, int \$eta, string \$phrase\).{0,400}xeric_play_room_renew\(\$live\)/s', $pvSrc));
$probe = ['lease' => ['id' => 'x', 'until' => microtime(true) + 1]];
xeric_play_room_renew($probe);
ok('the renewal is a full lease from now', $probe['lease']['until'] > microtime(true) + XERIC_ROOM_LEASE - 2
    && $probe['lease']['id'] === 'x');

// ---------------------------------------------------------------------------
echo "\n# the pin: whose machine speaks, and the slot on it\n";

// A second machine, connected but never dialled: reachability and discovery
// go through the resolver's seams, so nothing here touches a network.
$pinBase = 'http://10.0.0.9:8080';
xeric_web_session_edit(function (array &$s) use ($pinBase): void {
    $s['wired'] = array_values(array_unique(array_merge((array)($s['wired'] ?? []), [$pinBase])));
}, $A);
xeric_play_speaker_set($db, 'dot', $pinBase, $A);
$llamaOf = fn(string $b): array => $b === $pinBase ? ['server' => 'llama.cpp', 'slots' => 4] : ['server' => 'Ollama 0.5', 'slots' => 4];
$defEp   = ['kind' => 'local', 'base' => 'http://127.0.0.1:1', 'model' => ''];
$res = xeric_play_sweep_endpoints(['db' => $db, 'sid' => $A, 'endpoint' => $defEp,
                                    'up' => fn(array $ep): bool => true, 'server' => $llamaOf]);
$dotEp = $res('dot'); $ruthEp = $res('ruth');
ok('the resolver sends a pinned speaker to her machine, tagged llama.cpp with its slots',
    str_starts_with((string)($dotEp['local'] ?? $dotEp['base'] ?? ''), $pinBase) && ($dotEp['server'] ?? '') === 'llama.cpp'
    && ($dotEp['pin']['machine'] ?? '') === '10.0.0.9:8080' && empty($dotEp['pin']['down']), json_encode($dotEp));
ok('and an unpinned one to the world\'s default, with no pin on it',
    ($ruthEp['base'] ?? '') === 'http://127.0.0.1:1' && !isset($ruthEp['pin']));
ok('only the llama.cpp machine hands out a slot, the same one every time',
    xeric_llm_slot($dotEp, 'dot') === crc32('dot') % 4 && xeric_llm_slot($res('dot'), 'dot') === crc32('dot') % 4
    && xeric_llm_slot($ruthEp, 'ruth') === null);
$resDown = xeric_play_sweep_endpoints(['db' => $db, 'sid' => $A, 'endpoint' => $defEp,
                                        'up' => fn(array $ep): bool => false, 'server' => $llamaOf]);
$dotDown = $resDown('dot');
ok('a pinned machine that is not answering hands back the default, marked down',
    ($dotDown['base'] ?? '') === 'http://127.0.0.1:1' && !empty($dotDown['pin']['down']), json_encode($dotDown));

// THE ROOM USES THE PIN: its default resolution is the speaker endpoint, and
// a resolver handed in reaches every beat with that beat's speaker — the
// pinned one's lines on her machine, with her slot, the rest on the default.
$pvSrc2 = (string)file_get_contents(dirname(__DIR__) . '/play-view.php');
ok('the room resolves each speaker through the pin by default',
    str_contains($pvSrc2, "\$o['endpoint'] ?? fn(string \$h): array => xeric_play_speaker_endpoint(\$t, \$db, \$h, \$sid)"));
// Pastor Dale is the pinned one here: this seed's room opens on him.
$pinned = 'pastor_dale';
$sP = xeric_play_room_say($w, 'Well, what does everybody think of the new pastor\'s sermons?');
$seenP = [];
$machine = function (string $name, array $tags) use (&$seenP): array {
    $n = 0;
    return ['base' => 'stub://' . $name, 'stub' => function (string $tag, array $msgs, array $opts = []) use ($name, &$seenP, &$n) {
        if ($tag === 'extract') return ['memories' => []];
        $seenP[] = ['machine' => $name, 'slot' => $opts['id_slot'] ?? null];
        $n++;
        return 'Hard to say, line ' . $name . $n . '.';
    }] + $tags;
};
$mB = $machine('B', ['server' => 'llama.cpp', 'slots' => 4]);
$mA = $machine('A', ['server' => 'Ollama 0.5', 'slots' => 4]);
$whoSpoke = [];
$rP = xeric_play_room_run($w, $A, (string)$sP['lease'], [
    'endpoint' => function (string $h) use ($mA, $mB, &$whoSpoke, $pinned): array { $whoSpoke[] = $h; return $h === $pinned ? $mB : $mA; },
    'up' => false, 'wait' => 2.0, 'seed' => 'pin', 'sleep' => fn(float $s) => null,
]);
$pairs = [];
foreach ($seenP as $i => $c) $pairs[] = [$whoSpoke[$i] ?? '?', $c['machine'], $c['slot']];
$pinRight = $pairs !== [];
foreach ($pairs as [$h, $m, $sl]) {
    if ($m !== ($h === $pinned ? 'B' : 'A')) $pinRight = false;
    if ($sl !== ($h === $pinned ? crc32($pinned) % 4 : null)) $pinRight = false;
}
$machinesSeen = array_values(array_unique(array_column($pairs, 1)));
sort($machinesSeen);
ok('each beat goes to its speaker\'s machine, and only the llama.cpp one carries a slot',
    $rP['beats'] >= 1 && $pinRight && $machinesSeen === ['A', 'B'], json_encode([$rP['beats'], $pairs]));
xeric_room_live_edit($db, function (?array &$l): void { if ($l !== null) { $l['lease'] = null; $l['budget'] = 0; } });
xeric_play_speaker_set($db, 'dot', '', $A);

// ---------------------------------------------------------------------------
echo "\n# a skip lands its own close\n";

// A model on loopback, so the skip is let through and the close has diaries
// to write — the heart is never asked. SKIPS when no server can be started.
$fake = $tmp . '/fake-model';
@mkdir($fake, 0775, true);
file_put_contents($fake . '/router.php', <<<'ROUTER'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/v1/models') { echo '{"data":[{"id":"fake"}]}'; return; }
if ($path === '/v1/chat/completions') {
    $j = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $all = json_encode($j['messages'] ?? []);
    $name = preg_match('/Write 0-3 things (.+?) would still know/', $all, $m) ? $m[1] : 'Somebody';
    $content = json_encode(['memories' => [$name . ' heard the hour go by at the counter, mid-conversation.']]);
    echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
                      'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]);
    return;
}
http_response_code(404); echo '{}';
ROUTER);
$sock = @stream_socket_server('tcp://127.0.0.1:0');
$mport = $sock ? (int)substr((string)strrchr((string)stream_socket_get_name($sock, false), ':'), 1) : 0;
if ($sock) fclose($sock);
$mproc = $mport > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $mport, $fake . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $fake . '/server.log', 'a'], 2 => ['file', $fake . '/server.log', 'a']], $mpipes) : false;
$mlive = false;
for ($i = 0; is_resource($mproc) && $i < 60 && !$mlive; $i++) {
    $c = @fsockopen('127.0.0.1', $mport, $en, $es, 0.2);
    if ($c) { fclose($c); $mlive = true; } else usleep(50000);
}
if (!$mlive) {
    echo "ok   - SKIPPED: no loopback model could be started here; the land-only-when-up checks above still hold\n";
} else {
    xeric_web_session_edit(function (array &$s) use ($mport): void {
        $s['model'] = ['kind' => 'local', 'local' => 'http://127.0.0.1:' . $mport, 'base' => '', 'model' => ''];
    }, $A);
    $sK = xeric_play_room_say($w, 'One more cup and then I am off.');
    xeric_play_room_run($w, $A, (string)$sK['lease'], ['endpoint' => fn(string $h): array => stub($calls),
        'up' => false, 'wait' => 2.0, 'sleep' => fn(float $s) => null]);
    xeric_room_live_edit($db, function (?array &$l): void { $l['lease'] = null; $l['budget'] = 0; });
    $convK = (int)xeric_room_live_get($db)['conv'];
    $diariesK = $roomDiaries($db);
    $skip = page('tick.php', $A, ['w' => $slug], ['w' => $slug, 'span' => 'hour']);
    ok('the skip goes, and seals the room it interrupts',
        $skip['status'] === 200 && xeric_room_live_get($db) === null, $skip['status'] . ' ' . mb_substr($skip['raw'], 0, 200));
    $evK = null;
    for ($i = 0; $i < 150 && ($evK === null || isset(xeric_room_seals($db)[$convK])); $i++) {
        usleep(200000);
        $evK = $roomEvent($db, $convK);
    }
    ok('and a room worker of its own lands the close — event and diaries, no heart',
        $evK !== null && ($evK['trail']['ended'] ?? '') === 'skip' && !isset(xeric_room_seals($db)[$convK])
        && $roomDiaries($db) > $diariesK, json_encode([$evK['trail']['ended'] ?? null, $roomDiaries($db) - $diariesK]));
    // Let the skip itself finish before anything else touches this database.
    for ($i = 0; $i < 150 && (xeric_queue_busy() || (array)(xeric_queue_state()['line'] ?? []) !== []); $i++) usleep(200000);
    xeric_web_session_edit(function (array &$s): void {
        $s['model'] = ['kind' => 'local', 'local' => 'http://127.0.0.1:1', 'base' => '', 'model' => ''];
    }, $A);
}
if (is_resource($mproc)) { proc_terminate($mproc); proc_close($mproc); }
ok('tick.php sends a room worker after the seal, as where.php and the leave do',
    (bool)preg_match("/xeric_room_seal\(\\\$w\['db'\], 'skip', \\\$now\) !== null\).{0,300}'lease' => ''\], 'room-worker\.php'/s",
        (string)file_get_contents(dirname(__DIR__) . '/tick.php')));

// ---------------------------------------------------------------------------
echo "\n# who came and went, in the view\n";

xeric_room_seal($db, 'stepped back', xeric_clock_now($db, $T));
$nowV = xeric_clock_now($db, $T);
$hm = fn(int $epoch): string => xeric_world_now($T, $epoch)['hhmm'];
$TV = $T;
foreach ($TV['cast']['characters'] as &$c) {
    if ($c['handle'] === 'janelle') {
        $c['week'] = [['days' => $all, 'from' => $hm((int)$nowV['epoch'] - 300), 'to' => $hm((int)$nowV['epoch'] + 3600),
                       'where' => 'bluebird', 'doing' => 'in for a coffee']];
    }
}
unset($c);
xeric_player_move($db, 'bluebird', (int)$nowV['epoch'] - 3600);
$lookV = xeric_play_look_html($TV, $db, $nowV, $slug, true);
ok('somebody who walked in a few minutes ago is a beat in the view, from observables',
    str_contains($lookV, '<p class="lookbeat">Janelle Kerr comes in'), $lookV);
ok('and the beat names nobody by pronoun', preg_match('/lookbeat">[^<]*\b(he|she|him|her)\b/i', $lookV) === 0);
$sV = xeric_play_room_say(['template' => $TV] + $w, 'Janelle! Sit down.');
ok('inside a live room the view says no beat — the room\'s own narrator row is where it goes',
    !str_contains(xeric_play_look_html($TV, $db, $nowV, $slug, true), 'lookbeat'));
xeric_room_live_edit($db, function (?array &$l): void { $l['lease'] = null; $l['budget'] = 0; });

// ---------------------------------------------------------------------------
echo "\n# the page\n";

$pp = (string)file_get_contents(dirname(__DIR__) . '/play.php');
ok('the strip lives beside #look, never inside it',
    strpos($pp, '<section id="room"') > strpos($pp, '<div id="look">') && str_contains($pp, '</div>' . "\n" . '    <!-- THE ROOM'));
ok('the strip polls by cursor, and never while the tab is hidden',
    str_contains($pp, "'room.php?w=' + encodeURIComponent(W) + '&after=' + ROOM.after")
    && str_contains($pp, 'if (document.hidden) return;'));
ok('each cast bubble has a speaker through speak.php', str_contains($pp, "if (VOICE && r.who === 'them' && r.id)"));
ok('the people in the view light up as they talk', str_contains($pp, "b.classList.toggle('typing'")
    && str_contains($pp, "b.classList.toggle('speaking'"));
ok('a guest gets no composer', str_contains($pp, "if (ROOM.guest) { $('#rcompose').hidden = true;"));
$pollFn = preg_match('/function roomPoll\(\) \{.*?\n  \}\n/s', $pp, $mm) ? $mm[0] : '';
ok('a poll answered without ok:true asks again on the slow beat, never freezes the strip',
    str_contains($pollFn, 'if (!d || !d.ok) { ROOM.timer = setTimeout(roomPoll, 6000); return; }')
    && !str_contains($pollFn, 'if (!d || !d.ok) return;'), mb_substr($pollFn, 0, 120));
$sayFn = (string)(preg_match('/function xeric_play_room_say\(.*?\n\}\n/s', (string)file_get_contents(dirname(__DIR__) . '/play-view.php'), $mm) ? $mm[0] : '');
ok('a line opens with one tick and one edit — no third transaction resealing what the tick sealed',
    $sayFn !== '' && substr_count($sayFn, 'xeric_room_live_edit(') === 1 && substr_count($sayFn, 'xeric_play_room_tick(') === 1);
ok('every bubble is escaped on the way in', str_contains($pp, "esc(r.text)") && !preg_match('/innerHTML\s*=\s*r\.text/', $pp));

// ---------------------------------------------------------------------------
echo "\n# together: who said it, who may read it, and whose clock\n";

// A second world, rated above the floor so a clamp shows, owned by an
// affirmed owner with a machine attached and its clock RUNNING.
$tslug = 'together-town';
$tT = $fixT;
$tT['meta']['rating'] = 'mature';
$tdir = xeric_web_worlds_dir() . '/' . $tslug;
@mkdir($tdir, 0775, true);
file_put_contents($tdir . '/world-template.json', json_encode($tT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($tdir . '/seed.json', json_encode(xeric_forge_default_seed($tT), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$O = bin2hex(random_bytes(16));
xeric_session_use($O);
xeric_session_claim($tslug, $O);
xeric_web_session_edit(function (array &$s): void {
    $s['model'] = ['kind' => 'local', 'local' => 'http://127.0.0.1:1', 'base' => '', 'model' => ''];
}, $O);
ok('together: the owner affirms', xeric_session_affirm(true, $O) === true);
$ow  = xeric_play_open($tslug);
$odb = $ow['db'];
if (xeric_clock_is_paused($odb)) xeric_clock_resume($odb);
ok('together: the owner\'s open runs at the world\'s rating, and that is this process\'s stamp',
    xeric_state_rating() === 'mature' && !xeric_clock_is_paused($odb));

// THE GUEST PAUSE. A guest in on a code, whose session has NO machine attached.
$tid = xeric_player_add($odb, $ow['template'], 'Corey');
$TG = bin2hex(random_bytes(16));
xeric_session_use($TG);
xeric_session_join($tslug, $tid, $TG);
$gw = xeric_play_open($tslug);
ok('together: the guest is on the owner\'s own database', xeric_play_is_guest($gw));
ok('together: and opening it with nothing attached does NOT pause the owner\'s running clock',
    !xeric_clock_is_paused($odb) && !xeric_clock_is_paused($gw['db']));
ok('together: the guest reads at the floor', xeric_state_rating() === xeric_rating_clamp('mature', false));
// Nor does the guest's open RESUME a clock the owner stopped.
xeric_clock_pause($odb);
xeric_play_open($tslug);
ok('together: nor does a guest\'s open start a clock the owner stopped', xeric_clock_is_paused($odb));
xeric_clock_resume($odb);
// A stranger's fork is their own, and still reconciles.
$TS = bin2hex(random_bytes(16));
xeric_session_use($TS);
$sw = xeric_play_open($tslug);
ok('together: a stranger with nothing attached still stops their OWN fork\'s clock',
    !$sw['mine'] && !xeric_play_is_guest($sw) && xeric_clock_is_paused($sw['db']) && !xeric_clock_is_paused($odb));
$sw = null;
// WHO SAID IT, in the thread a page draws.
xeric_session_use($O);
$ow  = xeric_play_open($tslug);
$oT  = $ow['template'];
$tc  = xeric_conversation_for($odb, 'ruth');
xeric_message_append($odb, $tc, 'user', null, 'Evening, Ruth.', null, null, 1);
xeric_message_append($odb, $tc, 'character', 'ruth', 'Evening.', null);
$solo = xeric_play_thread($oT, $odb, 'ruth');
ok('together: one voice in a thread: the bubble is the owner, unlabelled, as today',
    $solo['messages'][0]['who'] === (trim((string)($oT['user']['name'] ?? '')) ?: 'you')
    && !array_key_exists('player', $solo['messages'][0]));
xeric_message_append($odb, $tc, 'user', null, 'I will bring the dish Saturday.', null, null, $tid);
$two = xeric_play_thread($oT, $odb, 'ruth');
$ownName = xeric_player_name($odb, 1, $oT);
ok('together: two voices: each player line carries its speaker\'s name and number',
    $two['messages'][0]['who'] === $ownName && $two['messages'][0]['player'] === 1
    && $two['messages'][2]['who'] === 'Corey' && $two['messages'][2]['player'] === $tid
    && !array_key_exists('player', $two['messages'][1]), json_encode($two['messages']));

// RATING PER VIEWER, through the thread reader. The owner's request stamps its
// lines at the world's rating; the guest reads them as gaps; the owner reads them.
$stamped = $odb->query("SELECT rating FROM messages WHERE content = 'Evening.'")->fetchColumn();
ok('together: the owner\'s rows are stamped with the rating their request ran at', $stamped === 'mature');
xeric_session_use($TG);
$gw = xeric_play_open($tslug);
$gth = xeric_play_thread($gw['template'], $gw['db'], 'ruth');
ok('together: the guest reads them redacted: the line, and none of its words',
    $gth['messages'][1]['text'] === XERIC_STATE_UNSHOWN && ($gth['messages'][1]['redacted'] ?? false) === true
    && !str_contains(json_encode($gth), 'Evening.'), json_encode($gth['messages']));
xeric_session_use($O);
$ow = xeric_play_open($tslug);
$oth = xeric_play_thread($ow['template'], $odb, 'ruth');
ok('together: and the adult owner reads them whole', $oth['messages'][1]['text'] === 'Evening.'
    && !isset($oth['messages'][1]['redacted']));
$odb->exec("UPDATE messages SET rating = '' WHERE content = 'Evening.'");
xeric_session_use($TG);
$gw = xeric_play_open($tslug);
ok('together: an unstamped row is read as it always was, by anybody',
    xeric_play_thread($gw['template'], $gw['db'], 'ruth')['messages'][1]['text'] === 'Evening.');

// THE HEART STAMPS: it opens each world by path, as its owner's session.
xeric_session_use($O);
$hw = xeric_play_open($tslug, (string)$ow['db_path']);
$hev = xeric_event_add($hw['db'], 'An hour the heart lived', xeric_clock_epoch($hw['db']), 'bluebird', ['ruth'], 'Prose.');
ok('together: the heart\'s open stamps what it writes with the world\'s rating',
    $hw['db']->query('SELECT rating FROM events WHERE id = ' . (int)$hev)->fetchColumn() === 'mature');
xeric_session_use($TG);
xeric_play_open($tslug);
$gev = array_values(array_filter(xeric_events_recent($odb, 50), fn($e) => (int)$e['id'] === (int)$hev))[0] ?? [];
ok('together: and the guest reads that hour without what happened in it',
    ($gev['title'] ?? '') === XERIC_STATE_UNSHOWN_HOUR && ($gev['prose'] ?? 'x') === '');

// A PHOTO stamped above the viewer is not served; the caption stands.
$phDir = dirname((string)$ow['db_path']) . '/photos';
@mkdir($phDir, 0775, true);
file_put_contents($phDir . '/portrait-ruth.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$odb->exec("UPDATE photo_jobs SET status = 'done', file = 'portrait-ruth.png', rating = 'mature'
            WHERE kind = 'portrait' AND subject = 'ruth'");
$pg = page('photo.php', $TG, ['w' => $tslug, 'k' => 'portrait', 's' => 'ruth'], [], 'GET');
$po = page('photo.php', $O,  ['w' => $tslug, 'k' => 'portrait', 's' => 'ruth'], [], 'GET');
ok('together: a photo stamped above the guest is a 404 to them', $pg['status'] === 404, (string)$pg['status']);
// (A CLI child never sets a 200 itself, so an untouched status reads as 0.)
ok('together: and served to the owner', in_array($po['status'], [0, 200], true) && str_contains($po['raw'], "\x89PNG"),
    (string)$po['status']);

// THE ROOM IS ITS OWN PLAYER'S. The owner sits down at the Bluebird; the guest
// stands across town and makes a request. Their position is not the room's.
xeric_session_use($O);
$ow   = xeric_play_open($tslug);
$odb  = $ow['db'];
$oNow = xeric_clock_now($odb, $ow['template']);
xeric_player_move($odb, 'bluebird', (int)$oNow['epoch'], 1);
xeric_player_move($odb, 'beck_hardware', (int)$oNow['epoch'], $tid);
$rs = xeric_play_room_say($ow, 'Evening, all.', $oNow);
xeric_room_live_edit($odb, function (?array &$l): void { $l['lease'] = null; $l['budget'] = 0; });
xeric_session_use($TG);
$gw = xeric_play_open($tslug);
ok('together: the guest really is elsewhere, by their own position',
    xeric_player_where($gw['template'], $gw['db']) === 'beck_hardware'
    && xeric_player_where($gw['template'], $gw['db'], 1) === 'bluebird');
$gLive = xeric_room_live_get($gw['db']);
ok('together: a second player\'s request from elsewhere does not read as the room\'s player leaving',
    $gLive !== null && xeric_room_over($gw['template'], $gw['db'], $gLive, xeric_clock_now($gw['db'], $gw['template'])) === null);
ok('together: and its tick seals nothing', xeric_play_room_tick($gw) === [] && xeric_room_live_get($gw['db']) !== null);
xeric_session_use($O);
xeric_player_move($odb, 'beck_hardware', (int)$oNow['epoch'], 1);
$ow = xeric_play_open($tslug);
ok('together: while the room\'s own player walking out still ends it',
    xeric_room_over($ow['template'], $odb, (array)xeric_room_live_get($odb), xeric_clock_now($odb, $ow['template'])) === 'you left');

$pp2 = (string)file_get_contents(dirname(__DIR__) . '/play.php');
ok('together: the page labels a player bubble with its speaker only when the row says whose it is',
    str_contains($pp2, "m.player ? m.who : ''") && str_contains($pp2, "(by ? '<span class=\"rwho\">' + esc(by) + '</span>' : '')"));
ok('together: and draws a redacted line as a gap with no speaker', str_contains($pp2, "who + (redacted ? ' redacted' : '')")
    && str_contains($pp2, "!photo && !redacted"));

xeric_state_rating('');
xeric_session_use($A);
$odb = null; $ow = null; $gw = null; $hw = null;

// ---------------------------------------------------------------------------

$db = null; $cdb = null; $w = null;
gc_collect_cycles();
rmtree($tmp);

echo $FAILED === 0 ? "\nall $COUNT room web tests passed\n" : "\n$FAILED of $COUNT room web test(s) FAILED\n";
exit($FAILED === 0 ? 0 : 1);
