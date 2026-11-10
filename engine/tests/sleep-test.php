<?php
/**
 * Xeric — sleep (engine/sleep.php). `php engine/tests/sleep-test.php`, exit 0.
 *
 * No network, no model: every call goes through the stub seam. Two halves.
 * The first drives xeric_sleep_pass on a hand-built Milldale and checks each
 * rule the disposer enforces. The second lives the continuity benchmark's
 * three days with sleep.after_days patched to 1, honest and leaky, against the
 * same three days without sleep, and holds sleep to the promise it exists to
 * keep: nothing it does is a quiet deletion.
 */

declare(strict_types=1);

require_once __DIR__ . '/../continuity/harness.php';
require_once __DIR__ . '/../continuity/probes.php';
require_once __DIR__ . '/../sleep.php';

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

$t0  = microtime(true);
$T   = xeric_world_load(__DIR__ . '/../fixtures/milldale.json');
$tmp = sys_get_temp_dir() . '/xeric-sleep-test-' . getmypid();
$DBS = [];

/** A fresh world database with no history. */
function fresh(string $tag): PDO
{
    global $tmp, $DBS;
    $p = $tmp . '-' . $tag . '.db';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $DBS[] = $p;
    return xeric_state_open($p);
}

$tz   = new DateTimeZone('America/New_York');
$at   = fn(string $s) => (new DateTimeImmutable($s, $tz))->getTimestamp();
$NOW  = xeric_world_now($T, $at('2026-11-20 02:00'));      // inside 01:00-06:00
$OLD  = $at('2026-10-20 15:00');                            // a month earlier
$NEW  = $at('2026-11-18 15:00');                            // two days earlier

/** A stub endpoint that answers 'sleep' with $fn(user message) and counts its calls. */
function sleeper(callable $fn, int &$calls): array
{
    return ['base' => 'stub://sleep', 'stub' => function (string $tag, array $msgs, array $opts) use ($fn, &$calls) {
        if ($tag !== 'sleep') return [];
        $calls++;
        return $fn((string)($msgs[1]['content'] ?? ''), $msgs);
    }];
}

/** Whole-world snapshot: every row of the tables sleep may touch. */
function snapshot(PDO $db): string
{
    $q = fn(string $sql) => $db->query($sql)->fetchAll(PDO::FETCH_NUM);
    $s = ['m' => $q('SELECT * FROM memories ORDER BY id'), 'ws' => $q("SELECT key, value FROM world_state WHERE key LIKE 'sleep:%' ORDER BY key")];
    if (xeric_sleep_has_tables($db)) { $s['f'] = $q('SELECT * FROM sleep_facts ORDER BY id'); $s['l'] = $q('SELECT * FROM sleep_links ORDER BY memory_id'); }
    return md5(json_encode($s));
}

// ---------------------------------------------------------------------------
// the night
// ---------------------------------------------------------------------------

ok('night: 02:00 is inside the dreams window, and the night is that date',
    xeric_sleep_night($T, $NOW) === '2026-11-20');
ok('night: noon is not', xeric_sleep_night($T, xeric_world_now($T, $at('2026-11-20 12:00'))) === null);
$W = $T; $W['sleep']['window'] = '22:00-04:00';
ok('night: a window that wraps midnight is ONE night — 23:00 and 01:00 name the same date',
    xeric_sleep_night($W, xeric_world_now($T, $at('2026-11-19 23:00'))) === '2026-11-20'
    && xeric_sleep_night($W, xeric_world_now($T, $at('2026-11-20 01:00'))) === '2026-11-20');
ok('config: after_days defaults to 14, remote to false', xeric_sleep_config($T)['after_days'] === 14.0 && !xeric_sleep_config($T)['remote']);

// ---------------------------------------------------------------------------
// grouping and pins
// ---------------------------------------------------------------------------

$db = fresh('unit');
$m = [];
$m['pie']   = xeric_memory_add($db, 'dot', 'Walt told Dot the doctor in Chillicothe took away his pie, even the cherry.', 'auto', ['conversation_id' => 1], $OLD, $OLD);
$m['coffee']= xeric_memory_add($db, 'dot', 'Walt drinks his coffee black and sits at the end of the counter.', 'auto', [], $OLD + 60, $OLD);
$m['ruth']  = xeric_memory_add($db, 'dot', 'Ruth Amberg brought a lemon cake to the Bluebird Diner and would not take money for it.', 'event', ['place' => 'bluebird', 'with' => ['ruth']], $OLD + 120, $OLD);
$m['ruth2'] = xeric_memory_add($db, 'dot', 'Ruth Amberg sat in the back booth for an hour after the funeral and said nothing.', 'event', ['place' => 'bluebird', 'with' => ['ruth']], $OLD + 180, $OLD);
$m['priv']  = xeric_memory_add($db, 'dot', 'Harlan Beck asked Dot to keep the till short a night and never said why.', 'event', ['gossip_grade' => false, 'walls' => ['circle_discretion']], $OLD + 240, $OLD);
$m['prom']  = xeric_memory_add($db, 'dot', 'Walt said "I will come by Friday at noon for coffee" and did not come.', 'construct', ['expect' => 'expect.1'], $OLD + 300, $OLD);
$m['death'] = xeric_memory_add($db, 'dot', 'Somebody in town died before the lunch rush.', 'event', ['kind' => 'death'], $OLD + 360, $OLD);
$m['quote'] = xeric_memory_add($db, 'dot', 'Walt promised he would come by Friday at noon for coffee, and Dot laughed at him for it.', 'auto', [], $OLD + 420, $OLD);
$m['conf']  = xeric_memory_add($db, 'dot', 'Walt said quietly that he has not opened his late wife\'s letters yet.', 'auto', [], $OLD + 480, $OLD);
$m['fresh'] = xeric_memory_add($db, 'dot', 'Walt came in out of the rain and left his umbrella by the door.', 'auto', [], $NEW, $NEW);
xeric_arc_set($db, 'dot', 'expect.1', json_encode(['what' => 'coffee at noon', 'quote' => 'come by Friday at noon for coffee', 'due' => $OLD + 86400, 'state' => 'missed']));
xeric_arc_set($db, 'dot', 'told.1', json_encode(['what' => 'he has not opened his late wife\'s letters yet', 'by' => '', 'p' => 1, 'at' => $OLD, 'state' => 'kept']));

$cand = xeric_sleep_candidates($T, $db, 'dot', $NOW);
$ids  = array_map(fn($r) => (int)$r['id'], $cand['rows']);
ok('pins: a construct, a death line, a promise\'s words and a confidence\'s words are never offered',
    !array_intersect($ids, [$m['prom'], $m['death'], $m['quote'], $m['conf']])
    && ($cand['pinned'][$m['prom']] ?? '') === 'source:construct' && ($cand['pinned'][$m['death']] ?? '') === 'death'
    && ($cand['pinned'][$m['quote']] ?? '') === 'promise' && ($cand['pinned'][$m['conf']] ?? '') === 'confidence',
    json_encode($cand['pinned']));
ok('candidates: younger than after_days is not offered; the old and unpinned are, oldest first',
    $ids === [$m['pie'], $m['coffee'], $m['ruth'], $m['ruth2'], $m['priv']], json_encode($ids));

$groups = xeric_sleep_group($T, 'dot', $cand['rows']);
ok('grouping: memories that name Ruth go together, Harlan apart, Walt-only ones under the person at the centre',
    array_keys($groups) === ['user', 'ruth', 'harlan']
    && array_map(fn($r) => (int)$r['id'], $groups['ruth']['rows']) === [$m['ruth'], $m['ruth2']]
    && array_map(fn($r) => (int)$r['id'], $groups['user']['rows']) === [$m['pie'], $m['coffee']],
    json_encode(array_map(fn($g) => array_map(fn($r) => (int)$r['id'], $g['rows']), $groups)));
ok('grouping: a group names what its members name — the Bluebird rides with Ruth',
    in_array('bluebird', $groups['ruth']['about'], true) && in_array('ruth', $groups['ruth']['about'], true), json_encode($groups['ruth']['about']));
$msgs = xeric_sleep_prompt($T, 'dot', $groups);
$bible = xeric_render_bible($T, ['handle' => 'dot'], xeric_world_rating($T));
ok('prompt: carries no line of the bible and no secret — only her own old memories and the names in them',
    !str_contains($msgs[0]['content'] . $msgs[1]['content'], trim(explode("\n", trim($bible))[1] ?? 'x'))
    && cb_quotes($T, [], 'dot', $msgs[1]['content']) === [] && str_contains($msgs[1]['content'], '[' . $m['pie'] . ']'));

// ---------------------------------------------------------------------------
// disposal
// ---------------------------------------------------------------------------

$forJanelle = cb_forbidden($T, 'janelle');
$secret = implode(' ', array_slice(preg_split('/\s+/u', $forJanelle[0]['text']) ?: [], 0, 9));
$answer = function (string $u) use ($m, $secret): array {
    return ['facts' => [
        // traceable
        ['fact' => 'Walt\'s doctor in Chillicothe took away his pie.', 'about' => ['user'], 'since' => '2026-10-20', 'from' => [$m['pie']]],
        ['fact' => 'Ruth Amberg is generous at the Bluebird Diner and grieving.', 'about' => ['ruth', 'bluebird'], 'since' => '2026-10-20', 'from' => [$m['ruth'], $m['ruth2']]],
        ['fact' => 'Harlan Beck once asked Dot to keep the till short.', 'about' => ['harlan'], 'since' => '2026-10-20', 'from' => [$m['priv']]],
        // untraceable four ways
        ['fact' => 'Walt drinks his coffee with Theo Vance every Sunday.', 'about' => ['user'], 'since' => '2026-10-20', 'from' => [$m['coffee']]],
        ['fact' => 'Pastor Dale Ostrander drinks his coffee black.', 'about' => ['pastor_dale'], 'since' => '2026-10-20', 'from' => [$m['coffee']]],
        ['fact' => 'Walt drinks his coffee black.', 'about' => ['user'], 'since' => '2026-10-20', 'from' => [$m['fresh']]],
        ['fact' => 'Walt sits at the end of the counter.', 'about' => ['user'], 'since' => '2026-10-20', 'from' => [$m['pie']]],
        ['fact' => 'Walt drinks 3 coffees.', 'about' => ['user'], 'since' => '2026-10-20', 'from' => [$m['coffee']]],
        // a wall the holder is not entitled to? dot is not walled from this one — see janelle below
        ['fact' => 'Walt has opinions.', 'about' => ['user'], 'since' => '2026-10-20', 'from' => []],
    ]];
};
$d = xeric_sleep_dispose($T, 'dot', $answer(''), $cand['rows']);
$keptText = array_column($d['kept'], 'text');
$whys = array_column($d['dropped'], 'why');
ok('dispose: the three traceable facts are kept', count($d['kept']) === 3, json_encode($whys));
ok('dispose: a name no source carries is dropped', (bool)preg_grep('/^untraceable: \'Theo\' is in no source/', $whys), json_encode($whys));
ok('dispose: an `about` no source names is dropped', (bool)preg_grep("/^untraceable: about 'pastor_dale' is not named/", $whys));
ok('dispose: a memory that was not offered (too young) cannot be cited', (bool)preg_grep('/^cites memory ' . $m['fresh'] . ', which was not offered/', $whys));
ok('dispose: a memory already in another fact cannot be cited twice', (bool)preg_grep('/already in another fact/', $whys));
ok('dispose: a number no source carries is dropped', (bool)preg_grep("/^untraceable: '3' is in no source/", $whys));
ok('dispose: a fact that cites nothing is dropped', in_array('cites no memory', $whys, true));
$k = array_column($d['kept'], null, 'text');
$priv = $k['Harlan Beck once asked Dot to keep the till short.'] ?? null;
ok('inherit: a fact made from a not-gossip-grade, walled source is not gossip grade and carries its wall',
    $priv !== null && $priv['meta']['gossip_grade'] === false && $priv['meta']['walls'] === ['circle_discretion'], json_encode($priv['meta'] ?? null));
$ruthF = $k['Ruth Amberg is generous at the Bluebird Diner and grieving.'] ?? null;
ok('inherit: a fact from ordinary sources stays gossip grade with no walls, and `since` is the earliest source',
    $ruthF !== null && $ruthF['meta']['gossip_grade'] === true && $ruthF['meta']['walls'] === [] && $ruthF['since'] === $OLD + 120
    && $ruthF['meta']['sources'] === 2);

// Walls: the same words for somebody walled from them, and the grade of a source that quotes them.
$jdb = fresh('janelle');
$jm1 = xeric_memory_add($jdb, 'janelle', 'Walt took Janelle Kerr to First Lutheran and they sang the hymn twice.', 'auto', [], $OLD, $OLD);
$jm2 = xeric_memory_add($jdb, 'janelle', 'Walt told Janelle Kerr about her mother at First Lutheran after the service.', 'auto', [], $OLD + 60, $OLD);
$jc  = xeric_sleep_candidates($T, $jdb, 'janelle', $NOW)['rows'];
$jd  = xeric_sleep_dispose($T, 'janelle', ['facts' => [
    ['fact' => 'Walt and Janelle Kerr sang at First Lutheran; ' . $secret, 'about' => ['user', 'janelle'], 'since' => '', 'from' => [$jm1]],
    ['fact' => 'Walt and Janelle Kerr sang the hymn twice at First Lutheran.', 'about' => ['user', 'first_lutheran'], 'since' => '', 'from' => [$jm1]],
]], $jc);
ok('walls: a fact quoting what its holder is walled from is dropped, whatever else is true of it',
    count($jd['kept']) === 1 && str_starts_with($jd['dropped'][0]['why'] ?? '', 'quotes a wall: '), json_encode($jd['dropped']));
ok('walls: and the memory it would have consumed is still free for an honest fact', ($jd['kept'][0]['from'] ?? []) === [$jm1]);
$grade = xeric_sleep_grade($T, [['text' => 'Ruth heard that ' . $forJanelle[0]['text'], 'meta' => []], ['text' => 'An ordinary Tuesday.', 'meta' => []]]);
ok('inherit: a source that quotes a wall for somebody makes the fact walled from them too (strictest wins)',
    in_array('janelle', $grade['walled_from'], true), json_encode($grade));

// ---------------------------------------------------------------------------
// the pass: write, mark, render
// ---------------------------------------------------------------------------

$before    = xeric_prompt_build($T, $db, 'dot', $NOW, [])[0]['content'];
$memBefore = xeric_memories_count($db);
$calls = 0;
$ep = sleeper(fn($u) => $answer($u), $calls);
$r = xeric_sleep_pass($T, $db, $NOW, $ep, ['handles' => ['dot']]);
ok('pass: one call for one person, three facts kept', $calls === 1 && count($r['characters']['dot']['kept'] ?? []) === 3, json_encode($r));
ok('pass: sources are not deleted — the memory table has every row it had', xeric_memories_count($db) === $memBefore);
$linked = xeric_sleep_consolidated_ids($db, 'dot');
sort($linked);
ok('pass: sources are marked consolidated_into, and only the cited ones',
    $linked === [$m['pie'], $m['ruth'], $m['ruth2'], $m['priv']], json_encode($linked));
$pinnedRows = $db->query('SELECT id FROM memories WHERE id IN (' . implode(',', [$m['prom'], $m['death'], $m['quote'], $m['conf']]) . ')')->fetchAll();
ok('pass: pinned memories are untouched and unlinked', count($pinnedRows) === 4 && !array_intersect($linked, [$m['prom'], $m['death'], $m['quote'], $m['conf']]));
$facts = xeric_sleep_facts($db, 'dot');
$chain = xeric_sleep_chain($db, (int)$facts[1]['id']);
ok('chain: xeric_sleep_chain gives the fact and every memory it stands for, in order, none missing',
    $chain !== null && array_map(fn($s) => (int)$s['id'], $chain['sources']) === [$m['ruth'], $m['ruth2']] && $chain['missing'] === []
    && $chain['fact']['about'] === ['ruth', 'bluebird'], json_encode($chain));
ok('chain: no fact without one', xeric_sleep_chain($db, 999999) === null);

$after = xeric_prompt_build($T, $db, 'dot', $NOW, [])[0]['content'];
$kpos = strpos($after, "WHAT YOU KNOW\n");
$rpos = strpos($after, "WHAT YOU REMEMBER\n");
ok('render: WHAT YOU KNOW sits directly ahead of WHAT YOU REMEMBER, with the since-date',
    $kpos !== false && $rpos !== false && $kpos < $rpos && str_contains($after, '- (since Tue 20 Oct) Walt\'s doctor in Chillicothe took away his pie.'));
ok('render: a consolidated memory stops rendering; an unconsolidated one still does',
    !str_contains($after, 'brought a lemon cake') && str_contains($after, 'drinks his coffee black and sits'));
ok('render: the bible is untouched — everything before the memory blocks is byte-identical',
    substr($before, 0, (int)strpos($before, "WHAT YOU REMEMBER\n")) === substr($after, 0, $kpos));
ok('render: the prompt got shorter, not longer', strlen($after) <= strlen($before), strlen($before) . ' → ' . strlen($after));

// The cap: twelve lines between the two blocks, a third of them facts at most, never longer than raw.
$cdb = fresh('cap');
$ids = [];
for ($i = 0; $i < 30; $i++) {
    $ids[] = xeric_memory_add($cdb, 'ruth', 'Ruth Amberg baked bread number ' . $i . ' at the Bluebird Diner on a slow afternoon in the fall.', 'event',
        ['place' => 'bluebird'], $OLD + $i * 3600, $OLD);
}
$rawOnly = xeric_prompt_build($T, $cdb, 'ruth', $NOW, [])[0]['content'];
$six = [];
for ($i = 0; $i < 6; $i++) {
    $six[] = ['text' => 'Ruth Amberg baked bread ' . $i . ' at the Bluebird Diner.', 'about' => ['ruth', 'bluebird'],
              'since' => $OLD + $i * 3600, 'from' => [$ids[2 * $i], $ids[2 * $i + 1]], 'meta' => ['sources' => 2]];
}
xeric_sleep_write($cdb, 'ruth', $six, '2026-11-20', []);
$capped = xeric_prompt_build($T, $cdb, 'ruth', $NOW, [])[0]['content'];
$kb = cb_parts([['content' => $capped]]);
$kn = substr_count($kb['WHAT YOU KNOW'] ?? '', "\n- ");
$rn = substr_count($kb['WHAT YOU REMEMBER'] ?? '', "\n- ");
ok('cap: facts count against the memory cap — together never more than twelve lines, facts at most a third',
    $kn >= 1 && $kn <= 4 && $kn + $rn <= 12, "know $kn, remember $rn");
ok('cap: and never more characters than the same prompt without sleep', strlen($capped) <= strlen($rawOnly), strlen($rawOnly) . ' → ' . strlen($capped));
ok('cap: the newest raw memory still renders', str_contains($capped, 'bread number 29 '));

// ---------------------------------------------------------------------------
// idempotent, budgeted, local
// ---------------------------------------------------------------------------

$snap = snapshot($db);
$calls2 = 0;
$r2 = xeric_sleep_pass($T, $db, $NOW, sleeper(fn($u) => $answer($u), $calls2), ['handles' => ['dot']]);
ok('idempotent: the same night again makes no call and changes nothing',
    $calls2 === 0 && !$r2['ran'] && snapshot($db) === $snap, json_encode($r2));
ok('due: dot has slept tonight; the others have not', xeric_sleep_due($T, $db, $NOW));
$r3 = xeric_sleep_pass($T, $db, xeric_world_now($T, $at('2026-11-20 12:00')), sleeper(fn($u) => $answer($u), $calls2));
ok('window: at noon the pass does nothing at all', !$r3['ran'] && $calls2 === 0 && snapshot($db) === $snap);

$bdb = fresh('budget');
foreach ([$OLD, $OLD + 60] as $e) xeric_memory_add($bdb, 'ruth', 'Ruth Amberg kept the church kitchen keys on a red ribbon for years.', 'auto', [], $e, $e);
$B = $T; $B['budget'] = ['tokens_per_day' => 50, 'metered_only' => false];
$bsnap = snapshot($bdb);
$calls3 = 0;
$rb = xeric_sleep_pass($B, $bdb, $NOW, sleeper(fn($u) => ['facts' => []], $calls3));
ok('budget: a ceiling the call would cross refuses it — no call, no fact, no link, no guard',
    $calls3 === 0 && $rb['refused'] !== '' && snapshot($bdb) === $bsnap && !xeric_sleep_has_tables($bdb), json_encode($rb));
$B['budget']['tokens_per_day'] = 1000000;
$rb2 = xeric_sleep_pass($B, $bdb, $NOW, sleeper(fn($u) => ['facts' => []], $calls3));
ok('budget: with room under the ceiling the same night runs', $calls3 >= 1 && $rb2['refused'] === '', json_encode($rb2));

$rdb = fresh('remote');
xeric_memory_add($rdb, 'ruth', 'Ruth Amberg kept the church kitchen keys on a red ribbon for years.', 'auto', [], $OLD, $OLD);
$rsnap = snapshot($rdb);
$remote = ['kind' => 'openai', 'base' => 'https://api.example.invalid/v1', 'model' => 'x', 'key' => 'k'];
$rr = xeric_sleep_pass($T, $rdb, $NOW, $remote);
ok('remote: never on a costly model unless sleep.remote is true — refused before any request, nothing written',
    !$rr['ran'] && str_contains($rr['refused'], 'sleep.remote') && snapshot($rdb) === $rsnap);
$off = $T; $off['sleep']['enabled'] = false;
ok('enabled: false turns it off', !xeric_sleep_pass($off, $rdb, $NOW, $remote)['ran'] && !xeric_sleep_due($off, $rdb, $NOW));

$fdb = fresh('fail');
xeric_memory_add($fdb, 'ruth', 'Ruth Amberg kept the church kitchen keys on a red ribbon for years.', 'auto', [], $OLD, $OLD);
$fsnap = snapshot($fdb);
$rf = xeric_sleep_pass($T, $fdb, $NOW, ['base' => 'stub://', 'stub' => function (string $tag) { throw new RuntimeException('down'); }], ['handles' => ['ruth']]);
ok('failure: a model that throws writes nothing and leaves the night open to try again',
    str_contains($rf['characters']['ruth']['note'] ?? '', 'down') && snapshot($fdb) === $fsnap && xeric_sleep_due($T, $fdb, $NOW));

// ---------------------------------------------------------------------------
// the heart loads sleep; it does not hope it was loaded
// ---------------------------------------------------------------------------

$heartSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/forge/web/heart.php');
ok('heart: requires engine/sleep.php itself, at the top, and calls the pass unguarded',
    str_contains($heartSrc, "\nrequire_once XERIC_WEB_LIB . '/engine/sleep.php';")
    && str_contains($heartSrc, 'xeric_sleep_pass($T, $db, $after, $endpoint)')
    && !str_contains($heartSrc, "function_exists('xeric_sleep_pass')"));

// ---------------------------------------------------------------------------
// a confidence told to the player is pinned like one told in the town
// ---------------------------------------------------------------------------

$pdb = fresh('confided');
$pm1 = xeric_memory_add($pdb, 'ruth', 'Ruth Amberg told Walt the bank wrote about the house and she has not answered it.', 'auto', [], $OLD, $OLD);
$pm2 = xeric_memory_add($pdb, 'ruth', 'Ruth Amberg kept the church kitchen keys on a red ribbon for years.', 'auto', [], $OLD + 60, $OLD);
xeric_arc_set($pdb, 'ruth', 'confided.1', json_encode(['what' => 'the bank wrote about the house', 'quote' => "Don't tell Harlan.",
    'p' => 1, 'not' => ['harlan'], 'at' => $OLD, 'state' => 'kept']));
$pc = xeric_sleep_candidates($T, $pdb, 'ruth', $NOW);
ok('pins: an ordinary memory carrying the words of a confidence told to the PLAYER is pinned, not offered',
    ($pc['pinned'][$pm1] ?? '') === 'confidence' && array_map(fn($r) => (int)$r['id'], $pc['rows']) === [$pm2], json_encode($pc['pinned']));

// ---------------------------------------------------------------------------
// rotation: what the model leaves out rests, and is eventually kept raw
// ---------------------------------------------------------------------------

$odb = fresh('rotate');
$oids = [];
for ($i = 0; $i < 60; $i++) {
    $oids[] = xeric_memory_add($odb, 'ruth', 'Ruth Amberg swept the church steps on afternoon number ' . $i . ' of the long dry fall.', 'auto', [],
        $OLD + $i * 60, $OLD);
}
$offeredBy = [];   // night => ids offered
$ocalls = 0;
$nightOf = fn(int $n) => xeric_world_now($T, $at('2026-11-' . (20 + $n) . ' 02:00'));
for ($n = 0; $n < 8; $n++) {
    $seenIds = [];
    $ep = sleeper(function (string $u) use (&$seenIds) {
        preg_match_all('/^\[(\d+)\]/m', $u, $mm);
        $seenIds = array_map('intval', $mm[1]);
        return ['facts' => []];                         // consolidates nothing, ever
    }, $ocalls);
    xeric_sleep_pass($T, $odb, $nightOf($n), $ep, ['handles' => ['ruth']]);
    $offeredBy[$n] = $seenIds;
}
ok('rotation: night one offers the oldest forty', $offeredBy[0] === array_slice($oids, 0, 40), json_encode($offeredBy[0]));
ok('rotation: night two offers DIFFERENT ones — the twenty never offered, not the same forty again',
    $offeredBy[1] === array_slice($oids, 40, 20), json_encode($offeredBy[1]));
$everOffered = array_unique(array_merge(...array_values($offeredBy)));
sort($everOffered);
ok('rotation: every one of the sixty is offered within the first two nights', array_values($everOffered) === $oids);
$offers = xeric_sleep_offers($odb, 'ruth');
ok('rotation: offered three times and never consolidated, each is kept raw and offered no more',
    count($offers) === 60 && array_filter($offers, fn($o) => !$o['kept_raw'] || $o['times'] !== XERIC_SLEEP_OFFERS_MAX) === []
    && $offeredBy[7] === [] && max(array_map('count', $offeredBy)) <= XERIC_SLEEP_MAX_SOURCES,
    json_encode(array_map('count', $offeredBy)));
ok('rotation: kept raw is not deleted — every memory is still there, unconsolidated',
    xeric_memories_count($odb) === 60 && xeric_sleep_consolidated_ids($odb, 'ruth') === []);
$fr = xeric_sleep_candidates($T, $odb, 'ruth', $nightOf(9));
ok('rotation: the candidates say how many were kept raw', $fr['rows'] === [] && $fr['kept_raw'] === 60, json_encode(['kr' => $fr['kept_raw']]));
// And a memory the model DID consolidate leaves the rotation for good.
$kdb = fresh('rotate-kept');
$k1 = xeric_memory_add($kdb, 'ruth', 'Ruth Amberg kept the church kitchen keys on a red ribbon for years.', 'auto', [], $OLD, $OLD);
$k2 = xeric_memory_add($kdb, 'ruth', 'Ruth Amberg swept the church steps on a long dry afternoon in the fall.', 'auto', [], $OLD + 60, $OLD);
$kc = 0;
xeric_sleep_pass($T, $kdb, $NOW, sleeper(fn($u) => ['facts' => [
    ['fact' => 'Ruth Amberg keeps the church kitchen keys.', 'about' => ['ruth'], 'since' => '', 'from' => [$k1]]]], $kc), ['handles' => ['ruth']]);
ok('rotation: only what was offered and LEFT is counted an offer', array_keys(xeric_sleep_offers($kdb, 'ruth')) === [$k2]
    && xeric_sleep_consolidated_ids($kdb, 'ruth') === [$k1]);

// ---------------------------------------------------------------------------
// a fact keeps its sources' walls when it renders
// ---------------------------------------------------------------------------

$wdb = fresh('walled-fact');
for ($i = 0; $i < 3; $i++) xeric_memory_add($wdb, 'janelle', 'Janelle Kerr walked home past First Lutheran on evening ' . $i . '.', 'auto', [], $OLD + $i, $OLD);
xeric_sleep_write($wdb, 'janelle', [
    ['text' => 'WALLED-FACT-NEEDLE Janelle Kerr heard something at First Lutheran.', 'about' => ['janelle'], 'since' => $OLD,
     'from' => [1], 'meta' => ['sources' => 1, 'walled_from' => ['janelle'], 'walls' => [], 'gossip_grade' => false]],
    ['text' => 'RATED-FACT-NEEDLE Janelle Kerr walks home late.', 'about' => ['janelle'], 'since' => $OLD,
     'from' => [2], 'meta' => ['sources' => 1, 'walled_from' => [], 'rating_min' => 'explicit']],
    ['text' => 'OPEN-FACT-NEEDLE Janelle Kerr walks home past First Lutheran.', 'about' => ['janelle'], 'since' => $OLD,
     'from' => [3], 'meta' => ['sources' => 1, 'walled_from' => ['dot']]],
], '2026-11-20', []);
$jp = xeric_prompt_build($T, $wdb, 'janelle', $NOW, [])[0]['content'];
ok('render: a fact walled from Janelle never renders in Janelle\'s prompt', !str_contains($jp, 'WALLED-FACT-NEEDLE'));
ok('render: nor one gated above the rating she may be shown', !str_contains($jp, 'RATED-FACT-NEEDLE'));
ok('render: while a fact walled only from somebody else still renders to her', str_contains($jp, 'OPEN-FACT-NEEDLE'), $jp);

// The grading reads each viewer's walls once for the whole pass, however many
// facts and sources there are.
$gdb = fresh('grade-count');
$gids = [];
for ($i = 0; $i < 12; $i++) $gids[] = xeric_memory_add($gdb, 'ruth', 'Ruth Amberg baked bread number ' . $i . ' at the Bluebird Diner on a slow afternoon.', 'event', ['place' => 'bluebird'], $OLD + $i * 60, $OLD);
$gc = 0;
$before = xeric_sleep_forbidden_reads();
xeric_sleep_pass($T, $gdb, $NOW, sleeper(function ($u) use ($gids) {
    $f = [];
    for ($i = 0; $i < 6; $i++) $f[] = ['fact' => 'Ruth Amberg baked bread number ' . (2 * $i) . ' at the Bluebird Diner.', 'about' => ['ruth', 'bluebird'], 'since' => '', 'from' => [$gids[2 * $i], $gids[2 * $i + 1]]];
    return ['facts' => $f];
}, $gc), ['handles' => ['ruth']]);
$reads = xeric_sleep_forbidden_reads() - $before;
$nCast = count((array)$T['cast']['characters']);
ok('grade: six facts from twelve sources read cb_forbidden at most once per viewer', $gc === 1 && count(xeric_sleep_facts($gdb, 'ruth')) === 6
    && $reads >= 1 && $reads <= $nCast, "$reads reads for $nCast viewers, " . count(xeric_sleep_facts($gdb, 'ruth')) . " facts, $gc calls");

// ---------------------------------------------------------------------------
// the benchmark: three days, sleep.after_days = 1
// ---------------------------------------------------------------------------

$L  = cb_scenario_load(__DIR__ . '/../fixtures/milldale-continuity.json');
ok('scenario: the benchmark sleeps', !empty($L['sc']['sleep']));
$LS = $L; $LS['T']['sleep']['after_days'] = 1;
$L0 = $L; $L0['sc']['sleep'] = false;

$runs = [];
foreach (['standin', 'leaky'] as $model) {
    $DBS[] = $p1 = $tmp . '-bench-' . $model . '-sleep.db';
    $DBS[] = $p0 = $tmp . '-bench-' . $model . '-awake.db';
    $runs[$model] = [
        'sleep' => cb_run($LS, ['days' => 3, 'seed' => 1, 'model' => $model, 'db' => $p1]),
        'awake' => cb_run($L0, ['days' => 3, 'seed' => 1, 'model' => $model, 'db' => $p0]),
    ];
}
foreach ($runs as $model => $pair) {
    $S = array_column(cb_probes($pair['sleep']), null, 'id');
    $A = array_column(cb_probes($pair['awake']), null, 'id');
    $sum = $S['sleep.summary']['evidence'];
    ok("$model: sleep ran on three nights and kept facts", $sum['nights'] === 3 && $sum['kept'] > 0 && $sum['consolidated'] >= $sum['kept'], json_encode($sum));
    foreach (['sleep.recall', 'sleep.shorter', 'sleep.walls', 'sleep.chain'] as $id) {
        ok("$model: $id holds", $S[$id]['pass'], json_encode($S[$id]['evidence']));
    }
    foreach ((array)$L['sc']['facts'] as $f) {
        $id = 'recall.' . $f['id'];
        ok("$model: $id does not drop against the same three days without sleep",
            $S[$id]['score'] >= $A[$id]['score'], $A[$id]['score'] . ' → ' . $S[$id]['score']);
    }
    // Same story either way (the stand-in never reads prompt bytes), so the builds pair up one to one.
    $longer = 0;
    foreach ($pair['sleep']['builds'] as $i => $b) {
        $o = $pair['awake']['builds'][$i] ?? null;
        if ($o === null || $o['handle'] !== $b['handle'] || $o['epoch'] !== $b['epoch']) { $longer = -1; break; }
        if (strlen($b['messages'][0]['content']) > strlen($o['messages'][0]['content'])) $longer++;
    }
    ok("$model: no system prompt in the run is longer than the same prompt in the run without sleep", $longer === 0, (string)$longer);
    ok("$model: the memory table is the same size with and without sleep — nothing deleted",
        xeric_memories_count($pair['sleep']['db']) === xeric_memories_count($pair['awake']['db']));
    if ($model === 'leaky') {
        ok('leaky: the disposer caught the padding with a wall and the invented fact', isset($sum['dropped']['quotes a wall']) && isset($sum['dropped']['untraceable']), json_encode($sum['dropped']));
    } else {
        ok('honest: no honest fact quoted a wall', !isset($sum['dropped']['quotes a wall']), json_encode($sum['dropped']));
    }
}
ok('benchmark: at the default after_days, three days leave nothing old enough to consolidate',
    (function () use ($L, $tmp, &$DBS) {
        $DBS[] = $p = $tmp . '-bench-default.db';
        $r = cb_run($L, ['days' => 1, 'seed' => 1, 'model' => 'standin', 'db' => $p]);
        $s = array_column(cb_probe_sleep($r), null, 'id')['sleep.summary']['evidence'];
        return $s['nights'] === 1 && $s['calls'] === 0 && $s['facts'] === 0;
    })());

foreach ($DBS as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
printf("\n%s (%.2f s)\n", $FAILED === 0 ? "all $COUNT passed" : "$FAILED of $COUNT FAILED", microtime(true) - $t0);
exit($FAILED === 0 ? 0 : 1);
