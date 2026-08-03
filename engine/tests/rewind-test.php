<?php
/**
 * Xeric — rewind. `php engine/tests/rewind-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL. Same stub seam as sweep-test.php, and the same reason:
 * what is being defended here is bookkeeping under fire, and none of it needs a
 * real model to fail interestingly.
 *
 * What is actually being defended:
 *
 *   - a skip leaves behind a manifest that tells the truth about what it wrote;
 *   - a rewind deletes EXACTLY the manifest's rows and nothing else — counted
 *     across every table, because "and nothing else" is the entire feature;
 *   - the offset and the watermark go back, and the un-happened windows become
 *     sweepable again — the world re-lives them rather than remembering them;
 *   - a rewound skip can be skipped again, freshly, with a fresh manifest;
 *   - no manifest, a stale manifest, and a second rewind all refuse politely,
 *     in words, writing nothing;
 *   - hours the heart lives on its own produce NO manifest, and hours the heart
 *     lives after a skip close that skip's rewind window;
 *   - a death that landed inside a skip un-happens with it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../clock.php';
require_once __DIR__ . '/../sweeps.php';
require_once __DIR__ . '/../proactive.php';
require_once __DIR__ . '/../constructs.php';
require_once __DIR__ . '/../rewind.php';

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

function ep(string $when, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();
}

const FIXTURE = __DIR__ . '/../fixtures/milldale.json';

$BASE    = xeric_world_load(FIXTURE);
$DBFILES = [];

function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-rewind-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    return xeric_state_open($path);
}

function world(array $armed): array
{
    $t = $GLOBALS['BASE'];
    $t['forge'] = ['armed' => $armed, 'disarmed' => []];
    return $t;
}

/** The handles the sweep prompt says were in the room, in order. */
function stub_handles(array $msgs): array
{
    preg_match_all('/^- \[([a-z0-9_]+)\]/mu', (string)($msgs[1]['content'] ?? ''), $m);
    return $m[1];
}

/** A different half of an evening every call — sweep-test's trick, same reason:
 *  the echo and divergence guards must never trip on the stub's own repetition. */
function stub_half(): string
{
    static $n = 0;
    $halves = [
        'counted the folding chairs twice on the way out',
        'left the urn switched on until nearly midnight',
        'found the side door unlatched and said nothing about it',
        'wrote the wrong date on the noticeboard in pen',
        'carried a box of hymnals up two flights alone',
        'paid for the milk out of a coat pocket',
        'stood in the rain rather than share an umbrella',
        'let the phone ring out four times before answering',
        'burned the bottom of the tray bake and served it anyway',
        'swapped the good chair for the wobbly one',
        'took the long way home past the old mill',
        'fixed a hinge with the wrong size screw',
        'lost an argument about a fence nobody owns',
        'put a fiver in an envelope with no note on it',
        'drank cold coffee rather than make a fresh pot',
        'stayed until the lights were already off',
        'forgot a name they have known for thirty years',
        'left a window open on the far side of the hall',
        'propped the noticeboard with a brick from the yard',
        'kept the receipt folded in a shirt pocket for no reason',
        'walked the long fence line before breakfast in the wet',
        'stacked the crates against the wrong wall on purpose',
        'let the kettle boil dry and opened a window over it',
        'signed the card last and pressed too hard on the pen',
    ];
    return $halves[$n++ % count($halves)];
}

/**
 * One endpoint for a whole skip, the way production has one: the sweep tag gets
 * a well-formed event with divergent memories, and anything else (proactive's
 * 'chat') gets a text line that varies per call so the dedupe guards stay out
 * of the way of what this file is testing.
 */
function stub_world(): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) {
        if ($tag === 'sweep') {
            $handles = stub_handles($msgs);
            $mem = [];
            foreach ($handles as $h) {
                $mem[$h] = ucfirst(str_replace('_', ' ', $h)) . ' ' . stub_half() . '.';
            }
            return [
                'title'    => 'the urn ran out early',
                'prose'    => 'The last of the coffee went at half past. Somebody put the folding chairs back wrong and nobody said so.',
                'memories' => $mem,
            ];
        }
        static $n = 0;
        return 'four chairs short. i counted ' . (++$n) . ' times.';
    }];
}

/**
 * A skip, in tick-worker.php's exact order: settle → MARK → advance → catchup
 * window-range → ping → pend → COMMIT. The worker is a loop, a lock and a list
 * of frames around precisely these calls, so this is the engine-level contract
 * the demo rides on — and heart.php is the same catchup WITHOUT the mark and
 * the commit, which is test 7.
 */
function skip(array $t, PDO $db, array $endpoint, int $realNow, int $span, array $opts = [], bool $ping = true): array
{
    xeric_learn_settle($db, (int)xeric_clock_now($db, $t, $realNow)['epoch']);
    $mark   = xeric_rewind_mark($db);
    $before = xeric_clock_now($db, $t, $realNow);
    $after  = xeric_clock_advance($db, $span, $t, $realNow);

    $r = xeric_sweep_catchup($t, $db, $endpoint, (int)$before['epoch'], (int)$after['epoch'],
        ['chance' => 1.0, 'clock' => (int)$after['epoch']] + $opts);

    $p = null;
    if ($ping && $r['events'] !== []) {
        try {
            $p = xeric_proactive_check($t, $db, $endpoint, $after, [
                'event' => $r['events'][count($r['events']) - 1],
                'chance' => 1.0, 'involves_user' => true,
            ]);
        } catch (Throwable $e) { /* a quiet phone is an ordinary outcome */ }
    }
    xeric_learn_pend($db, $r['events'], $p, (int)$after['epoch']);

    return ['events' => $r['events'], 'ping' => $p, 'manifest' => xeric_rewind_commit($db, $mark)];
}

/** Every count and both stores, whole. Equality of two of these IS the rewind
 *  working: same rows, same values, same dots, and not one thing else. */
function snap(PDO $db): array
{
    $n = fn(string $t) => (int)$db->query("SELECT COUNT(*) c FROM $t")->fetchAll()[0]['c'];
    $arcs = [];
    foreach ($db->query('SELECT handle, key, value FROM arcs ORDER BY handle, key')->fetchAll() as $r) {
        $arcs[$r['handle'] . '/' . $r['key']] = (string)$r['value'];
    }
    $convs = [];
    foreach ($db->query('SELECT id, updated_at, unread FROM conversations ORDER BY id')->fetchAll() as $r) {
        $convs[(int)$r['id']] = $r['updated_at'] . '/' . $r['unread'];
    }
    return [
        'events' => $n('events'), 'memories' => $n('memories'), 'messages' => $n('messages'),
        'conversations' => $n('conversations'), 'signals' => $n('signals'),
        'deaths' => $n('deaths'), 'reminders' => $n('reminders'),
        'offset' => xeric_clock_offset($db),
        'unread' => xeric_conversation_unread_total($db),
        'world_state' => xeric_world_state_all($db),
        'arcs' => $arcs, 'convs' => $convs,
    ];
}

// ---------------------------------------------------------------------------
// 1. A skip leaves a manifest that tells the truth
// ---------------------------------------------------------------------------

$T    = world(['daily_rhythms', 'shared_meals']);
$REAL = ep('2026-07-30 14:00');                       // Thursday afternoon

$db = fresh_db('skip');
xeric_state_seed($db, $T);

// A lived pre-skip world: threads with everybody (so a mid-skip ping lands in
// an EXISTING thread and has to be un-bumped, not just un-created), and a
// memory that was already somebody's before any of this.
$preConvs = [];
foreach ($T['cast']['characters'] as $c) {
    $cid = xeric_conversation_for($db, (string)$c['handle']);
    xeric_message_append($db, $cid, 'user', null, 'you around?', $REAL - 86400);
    $preConvs[(string)$c['handle']] = $cid;
}
xeric_memory_add($db, 'ruth', 'Ruth kept the key to the back door all winter.', 'seed', [], $REAL - 86400);

$PRE = snap($db);
$s1  = skip($T, $db, stub_world(), $REAL, 6 * 3600);

ok('skip: the hours produced events', count($s1['events']) >= 1, json_encode($s1['events']));
ok('skip: and somebody texted about them, into a thread that already existed',
    $s1['ping'] !== null && xeric_conversation_unread_total($db) === 1, json_encode($s1['ping']));

$m1  = $s1['manifest'];
$raw = xeric_world_state_get($db, XERIC_REWIND_KEY);
ok('skip: one manifest row landed, keyed skip:last, and the commit returned the same thing',
    $raw !== null && is_array($m1) && json_decode($raw, true) == $m1);

$landedEvents = [];
foreach ($db->query('SELECT id FROM events ORDER BY id')->fetchAll() as $r) $landedEvents[] = (int)$r['id'];
ok('skip: the manifest names EXACTLY the events that landed — a diff cannot forget one',
    (array)$m1['ids']['events'] === $landedEvents
    && count((array)$m1['ids']['memories']) === snap($db)['memories'] - $PRE['memories']
    && count((array)$m1['ids']['messages']) === 1,
    json_encode($m1['ids']));
ok('skip: and it remembers both edges of the clock',
    (int)$m1['before']['offset'] === $PRE['offset']
    && (int)$m1['after']['offset'] === xeric_clock_offset($db)
    && (int)$m1['span'] === 6 * 3600);

// THE OFFER, WORDED. peek() is what the play view reads to draw the button, and
// it is the only place the manifest is turned into something a person sees. The
// manifest stores seconds; a button reading "take back the 21600" is a button
// nobody presses, and it is the sort of defect no test catches because every
// assertion around it is about the numbers being right — which they were.
$pk = xeric_rewind_peek($T, $db);
ok('peek: it offers the same span the manifest recorded',
    $pk !== null && $pk['span'] === 6 * 3600);
ok('peek: worded the way a person reads a duration, not the way a clock stores one',
    ($pk['label'] ?? '') === '6h', json_encode($pk));
ok('peek: through the same labeller the receipt uses, so the offer and the receipt agree',
    ($pk['label'] ?? '') === xeric_clock_span_label((int)$m1['span']));
ok('peek: and the counts it shows are the manifest\'s own',
    $pk['events'] === count((array)$m1['ids']['events'])
    && $pk['memories'] === count((array)$m1['ids']['memories'])
    && $pk['messages'] === count((array)$m1['ids']['messages']));

// ---------------------------------------------------------------------------
// 2–3. The rewind: exactly the manifest's rows, and the clock and watermark back
// ---------------------------------------------------------------------------

$r1 = xeric_rewind($T, $db);
ok('rewind: it worked, and the summary says what un-happened',
    $r1['ok'] === true
    && $r1['events'] === count((array)$m1['ids']['events'])
    && $r1['memories'] === count((array)$m1['ids']['memories'])
    && $r1['messages'] === 1
    && $r1['hours'] === 6.0 && $r1['label'] === '6h', json_encode($r1));

$POST = snap($db);
ok('rewind: every table counts exactly what it counted before the skip',
    $POST['events'] === $PRE['events'] && $POST['memories'] === $PRE['memories']
    && $POST['messages'] === $PRE['messages'] && $POST['conversations'] === $PRE['conversations']
    && $POST['signals'] === $PRE['signals'] && $POST['deaths'] === $PRE['deaths']
    && $POST['reminders'] === $PRE['reminders'],
    json_encode([$PRE, $POST]));
ok('rewind: world_state is byte-for-byte the pre-skip world — guards gone, watermark back, trails gone, manifest gone',
    $POST['world_state'] == $PRE['world_state'],
    json_encode([array_diff_assoc($POST['world_state'], $PRE['world_state']),
                 array_diff_assoc($PRE['world_state'], $POST['world_state'])]));
ok('rewind: the arcs are the pre-skip arcs — the ping\'s bookkeeping un-happened with the ping',
    $POST['arcs'] == $PRE['arcs'],
    json_encode([array_diff_assoc($POST['arcs'], $PRE['arcs']), array_diff_assoc($PRE['arcs'], $POST['arcs'])]));
ok('rewind: the touched thread lost its dot and its bump — updated_at and unread restored',
    $POST['convs'] == $PRE['convs'] && $POST['unread'] === 0, json_encode([$PRE['convs'], $POST['convs']]));
ok('rewind: the offset is home and the watermark row is simply absent again',
    $POST['offset'] === $PRE['offset']
    && xeric_world_state_get($db, 'sweep_watermark:' . XERIC_SWEEP_WINDOW) === null);
ok('rewind: the pre-skip transcript was not touched — those words were lived, not skipped',
    (int)$db->query('SELECT COUNT(*) c FROM messages WHERE conversation_id = ' . (int)$preConvs['ruth'])->fetchAll()[0]['c'] === 1
    && xeric_memories_count($db, 'ruth') === 1);

// ---------------------------------------------------------------------------
// 4. The same hours, lived again: fresh span, fresh manifest
// ---------------------------------------------------------------------------

// "Possibly differently" is the design and a deterministic stub cannot show it;
// what CAN be shown is that the windows really are unlived (they produce again
// rather than answering "already been swept") and that nothing of the first
// life leaks into the second — AUTOINCREMENT never reissues the undone ids.
$s2 = skip($T, $db, stub_world(), $REAL, 6 * 3600);
$m2 = $s2['manifest'];
ok('re-skip: the un-happened windows produced events again',
    count($s2['events']) >= 1, json_encode($s2['events']));
ok('re-skip: with a fresh manifest, not the ghost of the old one',
    is_array($m2) && (array)$m2['ids']['events'] !== (array)$m1['ids']['events']
    && min(array_map('intval', (array)$m2['ids']['events'])) > max(array_map('intval', (array)$m1['ids']['events'])),
    json_encode([$m1['ids']['events'] ?? null, $m2['ids']['events'] ?? null]));

// ---------------------------------------------------------------------------
// 5–6. The refusals: polite, specific, and they write nothing
// ---------------------------------------------------------------------------

$dbN = fresh_db('none');
xeric_state_seed($dbN, $T);
$rn = xeric_rewind($T, $dbN);
ok('refusal: a world with no skip behind it says so instead of throwing',
    $rn['ok'] === false && str_contains((string)$rn['why'], 'no skip'), json_encode($rn));

$r2 = xeric_rewind($T, $db);
$r3 = xeric_rewind($T, $db);
ok('refusal: a rewind consumes the manifest, so a second one has nothing to stand on',
    $r2['ok'] === true && $r3['ok'] === false && str_contains((string)$r3['why'], 'no skip'),
    json_encode($r3));
ok('refusal: and the double-rewind changed nothing — the world stands where the first one left it',
    snap($db) == $POST);

// Words said since the skip close the window: deleting her ping while keeping
// the answer to it would orphan half a conversation.
$dbW = fresh_db('worn');
xeric_state_seed($dbW, $T);
$cidW = xeric_conversation_for($dbW, 'ruth');
xeric_message_append($dbW, $cidW, 'user', null, 'you around?', $REAL - 86400);
skip($T, $dbW, stub_world(), $REAL, 3 * 3600);
xeric_message_append($dbW, $cidW, 'user', null, 'wait — was I meant to be somewhere tonight?', $REAL + 3 * 3600);
$rw = xeric_rewind($T, $dbW);
ok('refusal: talking to the world since the skip makes those hours lived-in, and lived-in hours stay',
    $rw['ok'] === false && str_contains((string)$rw['why'], 'said'), json_encode($rw));
ok('refusal: and nothing was deleted on the way to saying no',
    xeric_events_count($dbW) > 0 && xeric_world_state_get($dbW, XERIC_REWIND_KEY) !== null);

// ---------------------------------------------------------------------------
// 7. The heart: no manifest of its own, and it closes the skip's window
// ---------------------------------------------------------------------------

// heart.php is xeric_sweep_catchup with no mark and no commit — the world
// living at its own pace is not a button anybody pressed, so there is nothing
// to regret and nothing to record. This asserts the engine side of that
// contract: catchup alone leaves no skip:last behind, however many hours land.
$dbH = fresh_db('heart');
xeric_state_seed($dbH, $T);
$hFrom = ep('2026-07-30 14:00');
$hr = xeric_sweep_catchup($T, $dbH, stub_world(), $hFrom, $hFrom + 3 * 3600, ['chance' => 1.0]);
ok('heart: hours lived at the world\'s own pace leave NO manifest — only a skip writes one',
    count($hr['events']) >= 1 && xeric_world_state_get($dbH, XERIC_REWIND_KEY) === null,
    json_encode($hr['notes']));

// And after a skip, a heart tick is the world moving on: even one whose hours
// were ALL quiet (no event, no memory, no message — only guards and the
// watermark) must close the rewind window, or its burned windows would stand
// in the rewound world's future and arrive pre-lived.
$dbHQ = fresh_db('heart-quiet');
xeric_state_seed($dbHQ, $T);
$sq = skip($T, $dbHQ, stub_world(), $REAL, 3 * 3600, [], false);
$afterEpoch = (int)xeric_clock_now($dbHQ, $T, $REAL)['epoch'];
$hq = xeric_sweep_catchup($T, $dbHQ, stub_world(), $afterEpoch, $afterEpoch + 2 * 3600, ['chance' => 0.0]);
$rq = xeric_rewind($T, $dbHQ);
ok('heart: a quiet tick after the skip still closes the window — the watermark is the tell',
    $hq['events'] === [] && count($sq['events']) >= 1
    && $rq['ok'] === false && str_contains((string)$rq['why'], 'lived hours of its own'),
    json_encode($rq));

// ---------------------------------------------------------------------------
// 8. A death inside the skip un-happens with it
// ---------------------------------------------------------------------------

$TM  = world(['mortality']);
$dbD = fresh_db('death');
xeric_state_seed($dbD, $TM);
$PRED = snap($dbD);

$sd = skip($TM, $dbD, stub_world(), $REAL, 2 * 3600, ['seed' => 13, 'max_events' => 1], false);
$dead = array_map(fn($r) => (string)$r['handle'], $dbD->query('SELECT handle FROM deaths')->fetchAll());
ok('death: the skipped hours killed somebody, and the manifest names the body',
    count($sd['events']) === 1 && count($dead) === 1
    && (array)($sd['manifest']['deaths'] ?? []) === $dead,
    json_encode([$dead, $sd['manifest']['deaths'] ?? null]));

$rd = xeric_rewind($TM, $dbD);
$POSTD = snap($dbD);
ok('death: and the rewind gives them back — the ledger is empty and the world is the pre-skip world',
    $rd['ok'] === true && $POSTD['deaths'] === 0
    && $POSTD['world_state'] == $PRED['world_state'] && $POSTD['arcs'] == $PRED['arcs'],
    json_encode([$rd, array_diff_assoc($POSTD['world_state'], $PRED['world_state'])]));

// ---------------------------------------------------------------------------
// 8b. The diff runs BOTH directions: a revive inside the skip is recorded,
// and the rewind puts the body back — with its own hour and its own story.
//
// The one-directional diff walked the current ledger for handles absent from
// the mark and could not see the converse. The half-reverted catastrophe was
// the worst case: fate.php's restore mid-skip emptied the ledger, the diff
// recorded nothing, and the rewind restored `places.dark` (a world_state key,
// faithfully diffed) over a cast walking around alive — every place dark for a
// catastrophe with no bodies, the exact ghost this file's header forbids.
// ---------------------------------------------------------------------------

$dbR = fresh_db('revive-mid-skip');
xeric_state_seed($dbR, $TM);

// Pre-skip: the world has ended. Whole cast in the ledger, every place dark.
$cat = xeric_death_catastrophe($TM, $dbR, (int)xeric_clock_now($dbR, $TM, $REAL)['epoch'], 'the dam went');
$deadPre = (int)$dbR->query('SELECT COUNT(*) c FROM deaths')->fetchAll()[0]['c'];
$darkPre = (string)(xeric_world_state_get($dbR, 'places.dark') ?? '');
ok('revive: the catastrophe stands before the mark — bodies and dark places agree',
    $deadPre > 0 && $darkPre !== '' && $darkPre !== '[]');

// Mid-skip: mark taken, then "everybody back" lands while the feed streams —
// fate.php takes no queue slot, so this interleaving is one click away.
$markR = xeric_rewind_mark($dbR);
xeric_clock_advance($dbR, 2 * 3600, $TM, $REAL);
xeric_death_restore($TM, $dbR);
$mR = xeric_rewind_commit($dbR, $markR);
ok('revive: the manifest records the removed deaths, whole rows and all',
    count((array)($mR['deaths_removed'] ?? [])) === $deadPre
    && (($mR['deaths_removed'][array_key_first((array)$mR['deaths_removed'])]['how'] ?? '') === 'the dam went'),
    json_encode($mR['deaths_removed'] ?? null));

// Take it back: the two halves of one player action must revert TOGETHER.
$rR = xeric_rewind($TM, $dbR);
$deadPost = (int)$dbR->query('SELECT COUNT(*) c FROM deaths')->fetchAll()[0]['c'];
$darkPost = (string)(xeric_world_state_get($dbR, 'places.dark') ?? '');
ok('revive: the rewind restores the bodies WITH the dark — no catastrophe of empty rooms over a living cast',
    $rR['ok'] === true && $deadPost === $deadPre && $darkPost === $darkPre,
    json_encode(['dead' => [$deadPre, $deadPost], 'dark' => [$darkPre, $darkPost]]));
$howBack = (string)($dbR->query('SELECT how FROM deaths LIMIT 1')->fetchAll()[0]['how'] ?? '');
ok('revive: and each body kept its story', $howBack === 'the dam went');

// ---------------------------------------------------------------------------
// 9. The constructs seam: a fuse that fires inside a skip un-fires with it
// ---------------------------------------------------------------------------

// This is the feature meeting its reason: you rewind to MAKE the appointment,
// so the promise must still be OPEN when you arrive. The fuse is an `expect.N`
// arc and the miss is an ordinary event + memory + trust hit + why-row — every
// one a store the manifest diffs — so nothing in rewind.php knows what a
// promise is and the whole thing un-fires anyway. The tick is called inside
// the marked span the way the skip path would call it; the heart's own calls
// live outside any mark and stay unrewindable, which test 7 already holds.
$dbX = fresh_db('fuse');
xeric_state_seed($dbX, $T);
$due = $REAL + 3600;                                   // promised for an hour from now
xeric_arc_set($dbX, 'ruth', 'expect.1', json_encode([
    'what' => 'company at the hall', 'quote' => 'I will be there at three',
    'when_said' => 'at three', 'due' => $due,
    'formed' => $REAL - 3600, 'state' => 'open',
], JSON_UNESCAPED_UNICODE));
$trustWas = xeric_arc_int($dbX, 'ruth', 'trust', 0);
$PREX = snap($dbX);

// The skip: mark, advance far past the fuse, let it burn, commit.
$markX = xeric_rewind_mark($dbX);
xeric_clock_advance($dbX, 6 * 3600, $T, $REAL);
$nowX  = xeric_clock_now($dbX, $T, $REAL);
$burnt = xeric_constructs_tick($T, $dbX, $nowX);
xeric_rewind_commit($dbX, $markX);

$missed = json_decode((string)xeric_arc_get($dbX, 'ruth', 'expect.1'), true);
ok('fuse: the skipped hours burned the fuse — she waited, and it is written down',
    (int)$burnt['missed'] === 1 && ($missed['state'] ?? '') === 'missed'
    && xeric_events_count($dbX) === $PREX['events'] + 1
    && xeric_arc_int($dbX, 'ruth', 'trust', 0) === $trustWas - 1, json_encode($burnt));

$rx = xeric_rewind($T, $dbX);
$openAgain = json_decode((string)xeric_arc_get($dbX, 'ruth', 'expect.1'), true);
ok('fuse: and the rewind un-fires it — the promise is OPEN again, to be kept this time',
    $rx['ok'] === true && ($openAgain['state'] ?? '') === 'open'
    && xeric_events_count($dbX) === $PREX['events']
    && xeric_arc_int($dbX, 'ruth', 'trust', 0) === $trustWas
    && snap($dbX) == $PREX, json_encode([$rx, $openAgain]));

// ---------------------------------------------------------------------------
// 10. The commit's own discipline
// ---------------------------------------------------------------------------

// A run that never moved the clock — a plain sweep of the current hour — is not
// time travel, and must not overwrite the manifest of the skip that was.
$dbP = fresh_db('plain');
xeric_state_seed($dbP, $T);
$sp = skip($T, $dbP, stub_world(), $REAL, 3600, [], false);
$mk = xeric_rewind_mark($dbP);
ok('commit: with the clock unmoved it declines to write, and the real skip\'s manifest stands',
    xeric_rewind_commit($dbP, $mk) === null
    && json_decode((string)xeric_world_state_get($dbP, XERIC_REWIND_KEY), true) == $sp['manifest']);

// A manifest from a build this engine does not understand is refused whole —
// half-reading one is how a rewind starts lying.
$dbV = fresh_db('version');
xeric_state_seed($dbV, $T);
xeric_world_state_set($dbV, XERIC_REWIND_KEY, json_encode(['v' => 99]));
$rv = xeric_rewind($T, $dbV);
ok('refusal: a manifest in a shape this build cannot fully read stays unread',
    $rv['ok'] === false && str_contains((string)$rv['why'], 'different build'), json_encode($rv));

// ---------------------------------------------------------------------------

$db = $dbN = $dbW = $dbH = $dbHQ = $dbD = $dbP = $dbV = $dbX = null;
gc_collect_cycles();
foreach ($DBFILES as $p2) foreach ([$p2, $p2 . '-wal', $p2 . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
