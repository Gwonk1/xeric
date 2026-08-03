<?php
/**
 * Xeric — clock, sweeps and proactive contact. `php engine/tests/sweep-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL. Same stub seam as chat-test.php, for the same reason: the
 * behaviour worth defending is what happens when the model is WRONG or absent,
 * and none of that is reproducible against a live one on demand.
 *
 * What is actually being defended here:
 *
 *   - the clock only ever moves forward, and never by a year;
 *   - an hour of world time happens ONCE, however many times a caller asks;
 *   - a world only gets the events it armed the systems for;
 *   - the protected character is never in the room when the thing they must not
 *     know is being handled — a wall in the WORLD, not in the renderer;
 *   - two people at the same event do not remember the same sentence;
 *   - a dead model leaves the world exactly as it was.
 */

declare(strict_types=1);

require_once __DIR__ . '/../clock.php';
require_once __DIR__ . '/../sweeps.php';
require_once __DIR__ . '/../proactive.php';
require_once __DIR__ . '/../story.php';

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

/** Run $fn, return the exception message it threw, or '' if it didn't. */
function err(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

function ep(string $when, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();
}

const FIXTURE = __DIR__ . '/../fixtures/milldale.json';
const STORY   = __DIR__ . '/../fixtures/milldale-story.json';

$BASE    = xeric_world_load(FIXTURE);
$DBFILES = [];

function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-sweep-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    return xeric_state_open($path);
}

/**
 * The fixture, plus the two blocks a forged world carries that milldale does not:
 * what the forge armed, and who is being kept in the dark about what.
 */
function world(array $armed, array $disarmed = [], ?string $protect = null, array $over = []): array
{
    $t = $GLOBALS['BASE'];
    $t['forge'] = ['armed' => $armed, 'disarmed' => $disarmed];
    if ($protect !== null) {
        $t['cast']['special_roles'] = [[
            'role' => 'friend', 'character' => $protect, 'own_bible' => true,
            'must_not_know' => 'who really emptied the building fund',
        ]];
    }
    foreach ($over as $path => $value) {
        $keys = explode('.', $path);
        $ref  = &$t;
        foreach ($keys as $k) { $ref = &$ref[$k]; }
        $ref = $value;
        unset($ref);
    }
    return $t;
}

/** The handles the sweep prompt says were in the room, in order. */
function stub_handles(array $msgs): array
{
    preg_match_all('/^- \[([a-z0-9_]+)\]/mu', (string)($msgs[1]['content'] ?? ''), $m);
    return $m[1];
}

/**
 * A different half of an evening, every time it is called.
 *
 * Global rather than per-stub on purpose: the engine refuses a memory that only
 * restates something that person already remembers, so a stub that handed out
 * the same twelve sentences to every world would be testing the echo guard by
 * accident and failing the tests it meant to run.
 */
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
    ];
    return $halves[$n++ % count($halves)];
}

/**
 * A model that writes a well-formed event, giving every person in the room a
 * different half of it. $memories overrides that, which is how the failure cases
 * hand it two of the same sentence.
 */
function stub_event(?callable $memories = null, ?array &$seen = null): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use ($memories, &$seen) {
        $seen    = $msgs;
        $handles = stub_handles($msgs);

        $mem = [];
        foreach ($handles as $h) {
            $mem[$h] = ucfirst(str_replace('_', ' ', $h)) . ' ' . stub_half() . '.';
        }
        if ($memories !== null) $mem = $memories($handles);

        return [
            'title' => 'the urn ran out early',
            'prose' => 'The last of the coffee went at half past. Somebody put the folding chairs back wrong and nobody said so.',
            'memories' => $mem,
        ];
    }];
}

/** A model that is not there. */
function stub_dead(): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) {
        throw new RuntimeException('llm: cannot reach 127.0.0.1 (Connection refused)');
    }];
}

/** A model that says one fixed line — the proactive path returns text, not JSON. */
function stub_says_text(string $reply, ?array &$seen = null): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) use ($reply, &$seen) {
        $seen = $m;
        return $reply;
    }];
}

// ---------------------------------------------------------------------------
// 1. The clock — forward only, and bounded
// ---------------------------------------------------------------------------

$T    = world(['daily_rhythms']);
$db   = fresh_db('clock');
$REAL = ep('2026-07-30 14:00');                        // Thursday afternoon

$now = xeric_clock_now($db, $T, $REAL);
ok('clock: a fresh world stands on real time',
    $now['epoch'] === $REAL && $now['hhmm'] === '14:00' && $now['phase'] === 'afternoon', json_encode($now));

$after = xeric_clock_advance($db, 6 * 3600, $T, $REAL);
ok('clock: advancing moves the world, and the new now comes back',
    $after['epoch'] === $REAL + 6 * 3600 && $after['hhmm'] === '20:00' && $after['phase'] === 'evening',
    json_encode($after));
ok('clock: the offset is world state, so every other reader agrees',
    xeric_clock_offset($db) === 6 * 3600
    && xeric_clock_now($db, $T, $REAL)['epoch'] === $REAL + 6 * 3600);
ok('clock: advances accumulate rather than replace',
    xeric_clock_advance($db, 3600, $T, $REAL)['epoch'] === $REAL + 7 * 3600);

$msg = err(fn() => xeric_clock_advance($db, -3600, $T, $REAL));
ok('clock: the world never runs backwards', str_contains($msg, 'does not run backwards'), $msg);
ok('clock: and a refused rewind does not move it', xeric_clock_offset($db) === 7 * 3600);

$msg = err(fn() => xeric_clock_advance($db, 400 * 86400, $T, $REAL));
ok('clock: one jump is capped, so a fat finger cannot skip a year',
    str_contains($msg, 'more than one jump may move a world'), $msg);
ok('clock: and a refused jump does not move it either', xeric_clock_offset($db) === 7 * 3600);
ok('clock: the cap is per jump, not a ceiling — seven days twice is fourteen days',
    (function () use ($T, $REAL) {
        $d = fresh_db('clock-cap');
        xeric_clock_advance($d, 7 * 86400, $T, $REAL);
        return xeric_clock_advance($d, 7 * 86400, $T, $REAL)['epoch'] === $REAL + 14 * 86400;
    })());

xeric_clock_reset($db);
ok('clock: reset puts the world back on real time',
    xeric_clock_offset($db) === 0 && xeric_clock_now($db, $T, $REAL)['epoch'] === $REAL);
ok('clock: a zero advance is a legal no-op, not an error',
    xeric_clock_advance($db, 0, $T, $REAL)['epoch'] === $REAL);

// THE GUARDS GO BACK WITH THE CLOCK. A reset that moved only the offset left
// the fast-forward's window guards and watermark standing in the world's
// future, so every later sweep said "already been lived", the heart skipped
// the world without a note, and the condition could never clear itself.
ok('clock: reset takes the fast-forward\'s guards and watermark with it, and the world lives again',
    (function () use ($T, $REAL) {
        $d = fresh_db('clock-reset-guards');
        xeric_state_seed($d, $T);
        xeric_clock_advance($d, 2 * 86400, $T, $REAL);
        // live the advanced stretch so guards + watermark land in the future
        $r1 = xeric_sweep_catchup($T, $d, stub_event(), $REAL, $REAL + 2 * 86400,
            ['chance' => 1.0, 'seed' => 41, 'max_events' => 2]);
        if (($r1['events'] ?? []) === []) return false;
        if (xeric_world_state_get($d, 'sweep_watermark:3600') === null) return false;
        xeric_clock_reset($d, null, $REAL);
        // the mark must not outlive the hours it claimed
        $mark = xeric_world_state_get($d, 'sweep_watermark:3600');
        if ($mark !== null && (int)$mark > intdiv($REAL, 3600)) return false;
        // and the same afternoon is liveable again — the assertion that was
        // false for every reset before the fix
        $r2 = xeric_sweep_catchup($T, $d, stub_event(), $REAL, $REAL + 6 * 3600,
            ['chance' => 1.0, 'seed' => 42, 'max_events' => 4]);
        return ($r2['events'] ?? []) !== [];
    })());

ok('clock: spans are read the way a person types them',
    xeric_clock_span('6h') === 21600 && xeric_clock_span('90m') === 5400
    && xeric_clock_span('2d') === 172800 && xeric_clock_span('3600') === 3600
    && xeric_clock_span('tomorrow') === null && xeric_clock_span('') === null);
ok('clock: and printed back the same way',
    xeric_clock_span_label(21600) === '6h' && xeric_clock_span_label(100800) === '1d 4h'
    && xeric_clock_span_label(0) === '0s');

// ---------------------------------------------------------------------------
// 2. Armed systems decide what may happen
// ---------------------------------------------------------------------------

$kinds = xeric_sweep_kinds_for(world(['shared_meals', 'visits']));
ok('kinds: an armed system unlocks its event kind',
    isset($kinds['shared_meal']) && isset($kinds['visit']), implode(',', array_keys($kinds)));
ok('kinds: and nothing else comes with it',
    !isset($kinds['rumor']) && !isset($kinds['mishap']) && !isset($kinds['confidence'])
    && !isset($kinds['ordinary']), implode(',', array_keys($kinds)));

$kinds = xeric_sweep_kinds_for(world(['danger', 'shared_meals'], ['shared_meals']));
ok('kinds: a DISARMED system never produces its event kind, even when something else armed it',
    !isset($kinds['shared_meal']) && isset($kinds['mishap']), implode(',', array_keys($kinds)));

ok('kinds: a world that armed nothing still gets an ordinary life',
    array_keys(xeric_sweep_kinds_for(world([]))) === ['ordinary']);
ok('kinds: a world that armed something never falls back to ordinary',
    !isset(xeric_sweep_kinds_for(world(['faith']))['ordinary']));

// The end-to-end version of the same promise: a rumor world produces rumors and
// nothing else, however many hours go by.
$TK   = world(['rumors']);
$seenKinds = [];
for ($h = 0; $h < 8; $h++) {
    $r = xeric_sweep_run($TK, fresh_db('kind' . $h), stub_event(),
        xeric_world_now($TK, ep('2026-07-30 10:00') + $h * 3600), ['chance' => 1.0, 'seed' => 7 + $h]);
    foreach ($r['events'] as $e) $seenKinds[$e['kind']] = true;
}
ok('kinds: eight hours in a rumor world produced rumor events and nothing else',
    $seenKinds !== [] && array_keys($seenKinds) === ['rumor'], implode(',', array_keys($seenKinds)));

// ---------------------------------------------------------------------------
// 3. A sweep writes one event and one memory each
// ---------------------------------------------------------------------------

$T2  = world(['daily_rhythms', 'shared_meals']);
$db2 = fresh_db('sweep');
xeric_state_seed($db2, $T2);

$NOW  = xeric_world_now($T2, ep('2026-07-30 18:30'));      // Thursday, everybody off shift
$seen = null;
$r    = xeric_sweep_run($T2, $db2, stub_event(null, $seen), $NOW, ['chance' => 1.0, 'seed' => 11]);
ok('sweep: something happened', count($r['events']) === 1, json_encode($r['notes']));

$E = $r['events'][0];
ok('sweep: exactly one event row, carrying the WORLD\'s clock',
    xeric_events_count($db2) === 1
    && (int)xeric_events_recent($db2, 1)[0]['world_epoch'] === $NOW['epoch']
    && $E['prose'] !== '' && $E['title'] !== '');
ok('sweep: exactly one memory per participant, and not one more',
    count($E['participants']) >= 2
    && xeric_memories_count($db2) === count($E['participants'])
    && xeric_memories_count($db2, $E['participants'][0]) === 1
    && xeric_memories_count($db2, $E['participants'][1]) === 1,
    xeric_memories_count($db2) . ' memories for ' . json_encode($E['participants']));
ok('sweep: the memories are event memories, pointing back at the event that made them',
    (function () use ($db2, $E) {
        $m = xeric_memories_for($db2, $E['participants'][0], 5)[0];
        return $m['source'] === 'event' && (int)($m['meta']['event_id'] ?? 0) === $E['id']
            && (int)$m['world_epoch'] === $E['world_epoch'];
    })());

ok('sweep: THE POINT — the two participants remember different things',
    (function () use ($db2, $E) {
        $a = xeric_memories_for($db2, $E['participants'][0], 1)[0]['text'];
        $b = xeric_memories_for($db2, $E['participants'][1], 1)[0]['text'];
        return $a !== $b && xeric_chat_similar($a, $b) < XERIC_SWEEP_DIVERGE;
    })(), json_encode($E['memories']));

ok('sweep: the model was told who was in the room, what shape of thing this was, and to diverge',
    str_contains($seen[1]['content'] ?? '', 'WHO WAS THERE')
    && str_contains($seen[1]['content'] ?? '', 'WHAT KIND OF THING HAPPENED')
    && str_contains($seen[1]['content'] ?? '', 'they do not remember the same thing'));

// THE STILL-LIFE REGRESSION. The prose rules once ended in what a small model
// took for a subject list — "Hands, objects, weather, money, doors" — and it
// obliged by writing the room with nobody in it: dust motes, condensation, a
// wobbling fan, next to a seeded past full of people knocking things over. Two
// authors in one feed. The prompt now asks for the people outright, and this
// pins that sentence so a later rewording cannot quietly hand the hours back
// to the furniture.
ok('sweep: the model is told the people ARE the hour, so a still-life is off spec',
    str_contains($seen[1]['content'] ?? '', 'The people named above are IN it')
    && str_contains($seen[1]['content'] ?? '', 'an hour with nobody in it is wrong'));

// Nobody is ever at a thing they could not have been at.
$MID = xeric_world_now($T2, ep('2026-07-30 10:00'));       // Thursday mid-morning: two people at work
ok('sweep: everybody in an event was somewhere they could plausibly have been',
    (function () use ($T2, $MID) {
        $bad = 0;
        for ($i = 0; $i < 12; $i++) {
            $r = xeric_sweep_run($T2, fresh_db('presence' . $i), stub_event(), $MID, ['chance' => 1.0, 'seed' => 40 + $i]);
            if ($r['events'] === []) continue;
            $e = $r['events'][0];
            $presence = xeric_world_who_is_where($T2, $MID);
            foreach ($e['participants'] as $h) {
                $where = $presence[$h]['where'] ?? null;
                // At the event's place, or off shift entirely. Never at work somewhere else.
                if ($where !== null && $where !== $e['place']) $bad++;
            }
        }
        return $bad === 0;
    })());

// ---------------------------------------------------------------------------
// 4. The window guard — an hour happens once
// ---------------------------------------------------------------------------

$dbG = fresh_db('guard');
$r1  = xeric_sweep_run($T2, $dbG, stub_event(), $NOW, ['chance' => 1.0, 'seed' => 5]);
$r2  = xeric_sweep_run($T2, $dbG, stub_event(), $NOW, ['chance' => 1.0, 'seed' => 5]);
ok('guard: the same sweep window cannot fire twice',
    count($r1['events']) === 1 && $r2['events'] === []
    && xeric_events_count($dbG) === 1
    && xeric_memories_count($dbG) === count($r1['events'][0]['participants']),
    json_encode($r2['notes']));
ok('guard: and it says why rather than failing silently',
    str_contains($r2['notes'][0] ?? '', 'already been swept'), json_encode($r2['notes']));
ok('guard: force is the deliberate override, for a demo that wants a second one',
    count(xeric_sweep_run($T2, $dbG, stub_event(), $NOW, ['chance' => 1.0, 'seed' => 5, 'force' => true])['events']) === 1
    && xeric_events_count($dbG) === 2);
ok('guard: the NEXT hour is a different window',
    count(xeric_sweep_run($T2, $dbG, stub_event(), xeric_world_now($T2, ep('2026-07-30 19:30')),
        ['chance' => 1.0, 'seed' => 5])['events']) === 1);

// A window that produced nothing is still consumed — otherwise `chance` is a knob
// that does nothing except make the caller loop until it gets its way.
$dbC = fresh_db('cadence');
$rc1 = xeric_sweep_run($T2, $dbC, stub_event(), $NOW, ['chance' => 0.0]);
$rc2 = xeric_sweep_run($T2, $dbC, stub_event(), $NOW, ['chance' => 1.0]);
ok('cadence: a window that rolled nothing is still spent, so repeated calls are not a soap opera',
    $rc1['events'] === [] && $rc2['events'] === [] && xeric_events_count($dbC) === 0,
    json_encode([$rc1['notes'], $rc2['notes']]));

// ---------------------------------------------------------------------------
// 5. Quiet hours
// ---------------------------------------------------------------------------

$FOUR_AM = xeric_world_now($T2, ep('2026-07-31 04:00'));
$rq = xeric_sweep_run($T2, fresh_db('quiet'), stub_event(), $FOUR_AM, ['chance' => 1.0, 'seed' => 2]);
ok('quiet: nothing happens at 4am in a world that armed no trouble',
    $rq['events'] === [] && str_contains($rq['notes'][0] ?? '', 'quiet hours'), json_encode($rq['notes']));

$TD = world(['danger']);
$rd = xeric_sweep_run($TD, fresh_db('quiet-danger'), stub_event(),
    xeric_world_now($TD, ep('2026-07-31 04:00')), ['chance' => 1.0, 'seed' => 4]);
ok('quiet: a world that armed danger can still be woken up by it',
    count($rd['events']) === 1 && $rd['events'][0]['kind'] === 'mishap', json_encode($rd['notes']));

ok('quiet: the gate reads the user\'s own quiet hours out of the template',
    xeric_sweep_quiet($T2, $FOUR_AM) === true
    && xeric_sweep_quiet($T2, $NOW) === false
    && xeric_sweep_quiet(world([], [], null, ['events.quiet_hours_respected' => false]), $FOUR_AM) === false);

// The field is hand-editable, and the hand that edits it is on a phone. An en
// dash saved clean and switched quiet hours OFF entirely, which is the one
// failure of this field nobody notices until 4am.
ok('quiet: an en dash, an em dash and the word "to" all mean a hyphen',
    xeric_sweep_quiet(world([], [], null, ['user.quiet_hours' => "21:30\u{2013}06:00"]), $FOUR_AM) === true
    && xeric_sweep_quiet(world([], [], null, ['user.quiet_hours' => "21:30 \u{2014} 06:00"]), $FOUR_AM) === true
    && xeric_sweep_quiet(world([], [], null, ['user.quiet_hours' => '21:30 to 06:00']), $FOUR_AM) === true
    && xeric_sweep_quiet(world([], [], null, ['user.quiet_hours' => "21:30\u{2013}06:00"]), $NOW) === false);

$whyQ = null;
ok('quiet: a value nobody can read is treated as quiet and says so — it never fails open',
    xeric_sweep_quiet(world([], [], null, ['user.quiet_hours' => '11pm-7am']), $NOW, $whyQ) === true
    && str_contains((string)$whyQ, '11pm-7am') && str_contains((string)$whyQ, 'not two times of day'),
    (string)$whyQ);

$rbad = xeric_sweep_run(world(['shared_meals'], [], null, ['user.quiet_hours' => 'evenings, up until 11']),
    fresh_db('quiet-unreadable'), stub_event(), $NOW, ['chance' => 1.0, 'seed' => 2]);
ok('quiet: and the complaint rides out on the note, so a world gone quiet says why',
    $rbad['events'] === [] && str_contains($rbad['notes'][0] ?? '', 'not two times of day'), json_encode($rbad['notes']));

// ---------------------------------------------------------------------------
// 5b. Places keep their own hours
// ---------------------------------------------------------------------------

ok('places: a mill chained since 1998 is never open, at any hour of any day',
    (function () {
        foreach (['2026-07-30 08:00', '2026-07-30 18:30', '2026-08-01 15:00', '2026-08-02 12:00'] as $when) {
            if (in_array('the_mill', xeric_sweep_open_places($GLOBALS['BASE'], xeric_world_now($GLOBALS['BASE'], ep($when))), true)) return false;
        }
        return true;
    })());
ok('places: the documented grammar is READ — a diner that closes at two is shut in the evening',
    in_array('bluebird', xeric_sweep_open_places($BASE, xeric_world_now($BASE, ep('2026-07-30 08:00'))), true)
    && !in_array('bluebird', xeric_sweep_open_places($BASE, xeric_world_now($BASE, ep('2026-07-30 18:30'))), true));
ok('places: a place with a Saturday of its own keeps it, and a closed day shuts it',
    in_array('beck_hardware', xeric_sweep_open_places($BASE, xeric_world_now($BASE, ep('2026-08-01 09:00'))), true)
    && !in_array('beck_hardware', xeric_sweep_open_places($BASE, xeric_world_now($BASE, ep('2026-08-01 15:00'))), true)
    && !in_array('beck_hardware', xeric_sweep_open_places($BASE, xeric_world_now($BASE, ep('2026-08-02 09:00'))), true));
ok('places: and hours nobody can read still mean open, because the bag is free-form',
    in_array('first_lutheran', xeric_sweep_open_places($BASE, xeric_world_now($BASE, ep('2026-07-31 03:00'))), true)
    && xeric_sweep_place_open([], 600, 4) === true
    && xeric_sweep_place_open(['open' => '07:00', 'close' => '17:00'], 600, 4) === true
    && xeric_sweep_place_open(['open' => '07:00', 'close' => '17:00'], 1200, 4) === false);

// ---------------------------------------------------------------------------
// 6. The wall: the protected character and what they must not know
// ---------------------------------------------------------------------------

$TW = world(['secrets', 'rumors', 'slow_reveal'], [], 'ruth');
ok('wall: the template names who is protected and from what',
    xeric_sweep_protected($TW) === ['ruth' => 'who really emptied the building fund']);

// Exhaustive, at the level the wall is actually enforced: choosing.
$spineRuns = $spineRuth = $ordinaryRuns = $ordinaryRuth = 0;
for ($i = 0; $i < 200; $i++) {
    $now = xeric_world_now($TW, ep('2026-07-30 08:00') + ($i % 12) * 3600);
    $kinds = xeric_sweep_kinds_for($TW);

    $on = xeric_sweep_choose($TW, $now, $kinds, ['spine' => true]);
    if ($on !== null) { $spineRuns++; if (in_array('ruth', $on['handles'], true)) $spineRuth++; }

    $off = xeric_sweep_choose($TW, $now, $kinds, ['spine' => false]);
    if ($off !== null) { $ordinaryRuns++; if (in_array('ruth', $off['handles'], true)) $ordinaryRuth++; }
}
ok('wall: over 200 draws, the protected character is NEVER in an event about what she must not know',
    $spineRuns > 100 && $spineRuth === 0, "$spineRuth of $spineRuns");
ok('wall: but she is not exiled from her own world — she is in ordinary ones',
    $ordinaryRuns > 100 && $ordinaryRuth > 0, "$ordinaryRuth of $ordinaryRuns");

// And end to end, through a real sweep that really writes.
$spineWritten = 0;
$spineHasRuth = 0;
for ($i = 0; $i < 10; $i++) {
    $r = xeric_sweep_run($TW, fresh_db('spine' . $i), stub_event(),
        xeric_world_now($TW, ep('2026-07-30 09:00') + $i * 3600), ['chance' => 1.0, 'seed' => 300 + $i, 'spine' => true]);
    foreach ($r['events'] as $e) {
        $spineWritten++;
        if (in_array('ruth', $e['participants'], true)) $spineHasRuth++;
    }
}
ok('wall: and no stored event about the secret has her name in its participants',
    $spineWritten > 0 && $spineHasRuth === 0, "$spineHasRuth of $spineWritten written");

ok('wall: an event on the secret says so to the model; one that is not warns it off her blind spot',
    (function () use ($TW) {
        $seenOn = $seenOff = null;
        xeric_sweep_run($TW, fresh_db('wall-on'), stub_event(null, $seenOn),
            xeric_world_now($TW, ep('2026-07-30 18:30')), ['chance' => 1.0, 'seed' => 9, 'spine' => true]);
        $tries = 0;
        do {                                    // keep drawing until Ruth is in the room
            xeric_sweep_run($TW, fresh_db('wall-off' . $tries), stub_event(null, $seenOff),
                xeric_world_now($TW, ep('2026-07-30 18:30')), ['chance' => 1.0, 'seed' => 50 + $tries, 'spine' => false]);
            $off = $seenOff[1]['content'] ?? '';
        } while (!str_contains($off, '[ruth]') && ++$tries < 20);

        return str_contains($seenOn[1]['content'] ?? '', 'keeping quiet: who really emptied the building fund')
            && !str_contains($seenOn[1]['content'] ?? '', '[ruth]')
            && str_contains($off, 'blind spot: who really emptied the building fund');
    })());

// The trail is kept — the demo writes it to world_state and the inspector
// prints it — so it may say that somebody was kept out and never what from.
ok('wall: the trail records the exclusion and never carries the secret it is protecting',
    (function () use ($TW) {
        $c = null;
        for ($i = 0; $i < 20 && ($c === null || ($c['trail']['excluded'] ?? []) === []); $i++) {
            $c = xeric_sweep_choose($TW, xeric_world_now($TW, ep('2026-07-30 18:00')), xeric_sweep_kinds_for($TW), ['spine' => true]);
        }
        $ex = (array)($c['trail']['excluded'][0] ?? []);
        return ($ex['handle'] ?? '') === 'ruth'
            && str_contains((string)($ex['why'] ?? ''), 'must not know')
            && !str_contains((string)($ex['why'] ?? ''), 'building fund');
    })(), json_encode($TW['cast']['special_roles']));

// One call writes a memory for EVERYBODY in the room, so when one of them is
// walled this prompt is the thing putting words in HER head — and every private
// line in it is a candidate for ending up there.
ok('wall: with the protected character in the room, nobody else\'s private memories are in the prompt',
    (function () use ($TW) {
        $seen = null;
        $p    = '';
        for ($i = 0; $i < 25 && !str_contains($p, '[ruth]'); $i++) {
            $d = fresh_db('wall-mem' . $i);
            foreach ($GLOBALS['BASE']['cast']['characters'] as $c) {
                xeric_memory_add($d, (string)$c['handle'],
                    'private: ' . $c['handle'] . ' kept the key to the back door all winter.', 'seed', [], 0);
            }
            xeric_sweep_run($TW, $d, stub_event(null, $seen), xeric_world_now($TW, ep('2026-07-30 18:30')),
                ['chance' => 1.0, 'seed' => 700 + $i, 'spine' => false]);
            $p = (string)($seen[1]['content'] ?? '');
        }
        $carried = [];
        foreach (explode("\n", $p) as $line) {
            if (str_starts_with(trim($line), 'still carries: private:')) $carried[] = trim($line);
        }
        return str_contains($p, '[ruth]')
            && $carried === ['still carries: private: ruth kept the key to the back door all winter.'];
    })());
ok('wall: and an ordinary room still gets everybody\'s, because that is what makes the hour concrete',
    (function () use ($T2, $NOW) {
        $d = fresh_db('wall-mem-control');
        foreach ($GLOBALS['BASE']['cast']['characters'] as $c) {
            xeric_memory_add($d, (string)$c['handle'],
                'private: ' . $c['handle'] . ' kept the key to the back door all winter.', 'seed', [], 0);
        }
        $seen = null;
        xeric_sweep_run($T2, $d, stub_event(null, $seen), $NOW, ['chance' => 1.0, 'seed' => 11]);
        return substr_count((string)($seen[1]['content'] ?? ''), 'still carries: private:') >= 2;
    })());

// A spine title is the secret with "name the thing" applied to it — which is
// what this prompt asks for — so it may go back to the hours it belongs to and
// to nobody who is walled off from them.
ok('wall: a spine hour\'s title is not read back to a room the protected character is standing in',
    (function () use ($TW) {
        $seen = null;
        $p    = '';
        for ($i = 0; $i < 25 && !str_contains($p, '[ruth]'); $i++) {
            $d = fresh_db('wall-recent' . $i);
            xeric_event_add($d, 'the fund came up short again', ep('2026-07-29 21:00'), null,
                ['dot', 'harlan'], 'Nobody put a number on it.', null, true);
            xeric_event_add($d, 'the side door was unlatched again', ep('2026-07-29 22:00'), null,
                ['dot', 'harlan'], 'It was still unlatched in the morning.', null, false);
            xeric_sweep_run($TW, $d, stub_event(null, $seen), xeric_world_now($TW, ep('2026-07-30 18:30')),
                ['chance' => 1.0, 'seed' => 900 + $i, 'spine' => false]);
            $p = (string)($seen[1]['content'] ?? '');
        }
        return str_contains($p, '[ruth]')
            && str_contains($p, 'the side door was unlatched again')
            && !str_contains($p, 'the fund came up short again');
    })());

$dbSpine = fresh_db('on-spine-row');
$rSpine  = xeric_sweep_run($TW, $dbSpine, stub_event(), xeric_world_now($TW, ep('2026-07-30 20:30')),
    ['chance' => 1.0, 'seed' => 305, 'spine' => true]);
ok('wall: a spine hour is MARKED on the row, not only in the answer the caller got',
    count($rSpine['events']) === 1 && $rSpine['events'][0]['on_spine'] === true
    && xeric_events_recent($dbSpine, 1)[0]['on_spine'] === true, json_encode($rSpine['notes']));

$dbOrd = fresh_db('off-spine-row');
$rOrd  = xeric_sweep_run($TW, $dbOrd, stub_event(), xeric_world_now($TW, ep('2026-07-30 20:30')),
    ['chance' => 1.0, 'seed' => 305, 'spine' => false]);
ok('wall: and an ordinary hour is marked as one, so the filter above has something to read',
    count($rOrd['events']) === 1 && $rOrd['events'][0]['on_spine'] === false
    && xeric_events_recent($dbOrd, 1)[0]['on_spine'] === false, json_encode($rOrd['notes']));

// The prompt says it twice and withholds everything that could feed it, and a
// small model still occasionally writes the secret into the room. By the time
// it is stored it is not prose, it is what happened.
ok('wall: a memory that hands the protected character her own secret is refused outright',
    str_contains(err(fn() => xeric_sweep_parse($TW, fresh_db('wall-post'), [
        'title'    => 'the light in the office window',
        'prose'    => 'They stood in the kitchen and did not put the light on.',
        'memories' => [
            'ruth' => 'Ruth was told who really emptied the building fund and did not answer.',
            'dot'  => 'Dot watched the door and said nothing at all about any of it.',
        ]], ['handles' => ['ruth', 'dot']])), 'next to the thing they must not know'));
ok('wall: and so is prose that puts it in the room with her',
    str_contains(err(fn() => xeric_sweep_parse($TW, fresh_db('wall-post-prose'), [
        'title'    => 'the light in the office window',
        'prose'    => 'Somebody said out loud who really emptied the building fund, and nobody moved.',
        'memories' => [
            'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
            'dot'  => 'Dot left before the washing up and nobody called after her.',
        ]], ['handles' => ['ruth', 'dot']])), 'next to the thing they must not know'));
// The TITLE is the field the prompt tells the model to "name the thing" in,
// and it travels furthest — the room block reads a non-spine title back into
// every recent participant's own prompt, and the inspector prints it. Moving
// the identical string from prose into the title used to be ACCEPTED.
ok('wall: and so is a TITLE that names it — the one field the prompt asks the model to name things in',
    str_contains(err(fn() => xeric_sweep_parse($TW, fresh_db('wall-post-title'), [
        'title'    => 'who really emptied the building fund',
        'prose'    => 'The last of the coffee went at half past and the chairs went back wrong.',
        'memories' => [
            'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
            'dot'  => 'Dot left before the washing up and nobody called after her.',
        ]], ['handles' => ['ruth', 'dot']])), 'next to the thing they must not know'));
// OBSERVABLES-ONLY IS CODE NOW. The rule the gossip ripple's whole walls-
// safety argument leans on lived in one prompt sentence; a model that wrote
// "she felt" into prose made an interior state a public fact, permanently.
// The record refuses; the memories stay exempt, because a memory IS an
// interior — that is the point of divergence.
ok('record: prose that writes the inside of somebody\'s head is refused whole',
    str_contains(err(fn() => xeric_sweep_parse($TW, fresh_db('interior-prose'), [
        'title'    => 'the urn ran out early',
        'prose'    => 'Ruth felt the evening turn on her and wondered if anybody would say so.',
        'memories' => [
            'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
            'dot'  => 'Dot left before the washing up and nobody called after her.',
        ]], ['handles' => ['ruth', 'dot']])), 'what a bystander could see'));
ok('record: and so is a title that does it in six words',
    str_contains(err(fn() => xeric_sweep_parse($TW, fresh_db('interior-title'), [
        'title'    => 'ruth knew the fund was short',
        'prose'    => 'The last of the coffee went at half past and the chairs went back wrong.',
        'memories' => [
            'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
            'dot'  => 'Dot left before the washing up and nobody called after her.',
        ]], ['handles' => ['ruth', 'dot']])), 'what a bystander could see'));
ok('record: felt-with-hands is an action and passes — the prepositions carve out the senses',
    count(xeric_sweep_parse($TW, fresh_db('interior-hands'), [
        'title'    => 'the spare key moved',
        'prose'    => 'Dot felt along the top of the door frame and came down with the spare key.',
        'memories' => [
            'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
            'dot'  => 'Dot left before the washing up and nobody called after her.',
        ]], ['handles' => ['ruth', 'dot']])['memories']) === 2);
ok('record: a memory keeps its interiority — the feeling rides the feeler\'s own head',
    (function () use ($TW) {
        $p = xeric_sweep_parse($TW, fresh_db('interior-memory'), [
            'title'    => 'the urn ran out early',
            'prose'    => 'The last of the coffee went at half past and the chairs went back wrong.',
            'memories' => [
                'ruth' => 'Ruth wished she had said something about the chairs before leaving.',
                'dot'  => 'Dot left before the washing up and nobody called after her.',
            ]], ['handles' => ['ruth', 'dot']]);
        return isset($p['memories']['ruth']) && str_contains($p['memories']['ruth'], 'wished');
    })());

// THE AUDIBLE SURFACE. The hour may carry one short exchange a doorway could
// hear — real talk, tied to the heartbeat, riding the same call. The wall
// reads it (a doorway is the least private place there is); the interiority
// gate deliberately does not ("I felt awful" is a legal thing to SAY).
$ovParsed = xeric_sweep_parse($TW, fresh_db('overheard'), [
    'title'    => 'the urn ran out early',
    'prose'    => 'The last of the coffee went at half past and the chairs went back wrong.',
    'overheard' => 'Ruth: "And he never brought it back." / Dot: "He never does."',
    'memories' => [
        'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
        'dot'  => 'Dot left before the washing up and nobody called after her.',
    ]], ['handles' => ['ruth', 'dot']]);
ok('overheard: the exchange survives the parse and rides the hour',
    str_contains((string)$ovParsed['overheard'], 'never brought it back'));
ok('overheard: spoken feelings are legal — the interiority gate reads the record, not the talk',
    str_contains((string)xeric_sweep_parse($TW, fresh_db('overheard-felt'), [
        'title'    => 'the urn ran out early',
        'prose'    => 'The last of the coffee went at half past and the chairs went back wrong.',
        'overheard' => 'Ruth: "I felt awful about the whole thing."',
        'memories' => [
            'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
            'dot'  => 'Dot left before the washing up and nobody called after her.',
        ]], ['handles' => ['ruth', 'dot']])['overheard'], 'felt awful'));
ok('overheard: but the wall reads the doorway like everywhere else — a spoken secret refuses the hour',
    str_contains(err(fn() => xeric_sweep_parse($TW, fresh_db('overheard-wall'), [
        'title'    => 'the urn ran out early',
        'prose'    => 'The last of the coffee went at half past and the chairs went back wrong.',
        'overheard' => 'Dot: "Everybody knows who really emptied the building fund."',
        'memories' => [
            'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
            'dot'  => 'Dot left before the washing up and nobody called after her.',
        ]], ['handles' => ['ruth', 'dot']])), 'next to the thing they must not know'));

ok('wall: an ordinary hour with her in it is not refused — the check is a wall, not a mood',
    count(xeric_sweep_parse($TW, fresh_db('wall-post-ok'), [
        'title'    => 'the urn ran out early',
        'prose'    => 'The last of the coffee went at half past and the chairs went back wrong.',
        'memories' => [
            'ruth' => 'Ruth counted the folding chairs twice on the way out of the hall.',
            'dot'  => 'Dot left before the washing up and nobody called after her.',
        ]], ['handles' => ['ruth', 'dot']])['memories']) === 2);

// ---------------------------------------------------------------------------
// 7. When the model is wrong
// ---------------------------------------------------------------------------

$dbF = fresh_db('dead');
$msg = err(fn() => xeric_sweep_run($T2, $dbF, stub_dead(), $NOW, ['chance' => 1.0, 'seed' => 1]));
ok('failure: a dead model is loud', str_contains($msg, 'the xeric did not answer'), $msg);
ok('failure: a dead model leaves NO partial event — not a row, not a memory, not a spent window',
    xeric_events_count($dbF) === 0 && xeric_memories_count($dbF) === 0
    && xeric_world_state_all($dbF) === [],
    json_encode([xeric_events_count($dbF), xeric_memories_count($dbF), xeric_world_state_all($dbF)]));
ok('failure: so the window is still there to retry',
    count(xeric_sweep_run($T2, $dbF, stub_event(), $NOW, ['chance' => 1.0, 'seed' => 1])['events']) === 1);

$same = 'They put the chairs away and the coffee had already gone.';
$dbS  = fresh_db('same');
$msg  = err(fn() => xeric_sweep_run($T2, $dbS, stub_event(fn($h) => array_fill_keys($h, $same)), $NOW,
    ['chance' => 1.0, 'seed' => 6, 'diverge_retries' => 0]));
ok('failure: two people with the SAME memory is refused, loudly',
    str_contains($msg, 'remembered the same thing'), $msg);
ok('failure: and nothing is written when it is',
    xeric_events_count($dbS) === 0 && xeric_memories_count($dbS) === 0);

ok('failure: a near-restatement counts as one memory too, not merely a byte match',
    str_contains(err(fn() => xeric_sweep_run($T2, fresh_db('near'), stub_event(function ($h) {
        return [$h[0] => 'Ruth put the chairs back and the coffee was already gone.',
                $h[1] => 'Dot put the chairs back and the coffee had already gone.'];
    }), $NOW, ['chance' => 1.0, 'seed' => 6, 'diverge_retries' => 0])), 'remembered the same thing'));

ok('failure: the retry is handed the collision, and its second answer is the one taken',
    (function () use ($T2, $NOW) {
        $calls = 0;
        $ep = ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) use (&$calls): array {
            $h = stub_handles($m);
            $calls++;
            $mem = $calls === 1
                ? array_fill_keys($h, 'The chairs went back wrong and the coffee had gone.')
                : [$h[0] => 'Ruth counted the folding chairs twice and put four of them back herself.',
                   $h[1] => 'Dot left before the washing up and nobody called after her.'];
            return ['title' => 'the urn ran out early', 'prose' => 'The coffee went at half past.', 'memories' => $mem];
        }];
        $r = xeric_sweep_run($T2, fresh_db('retry'), $ep, $NOW, ['chance' => 1.0, 'seed' => 6]);
        return $calls === 2 && count($r['events']) === 1
            && str_contains(implode(' ', $r['events'][0]['memories']), 'counted the folding chairs');
    })());

ok('failure: a memory addressed to somebody who was not there is dropped, never stored',
    (function () use ($T2, $NOW) {
        $d = fresh_db('stranger');
        $r = xeric_sweep_run($T2, $d, stub_event(function ($h) {
            return [$h[0] => 'Ruth counted the folding chairs twice on the way out of the hall.',
                    $h[1] => 'Dot left before the washing up and nobody said a word about it.',
                    'a_stranger' => 'Somebody who does not exist remembers this vividly.'];
        }), $NOW, ['chance' => 1.0, 'seed' => 11]);
        if ($r['events'] === []) return false;
        return !in_array('a_stranger', $r['events'][0]['participants'], true)
            && xeric_memories_count($d, 'a_stranger') === 0
            && xeric_memories_count($d) === 2;
    })());

ok('failure: an event nobody remembers is not an event',
    str_contains(err(fn() => xeric_sweep_run($T2, fresh_db('nomem'), stub_event(fn($h) => []), $NOW,
        ['chance' => 1.0, 'seed' => 1])), 'nobody remembers'));

ok('failure: a memory that only restates what she already knew is not a new memory',
    (function () use ($T2, $NOW) {
        $d = fresh_db('echo');
        xeric_memory_add($d, 'ruth', 'Ruth counted the folding chairs twice on the way out.', 'seed', [], $NOW['epoch'] - 86400);
        $msg = err(fn() => xeric_sweep_run($T2, $d, stub_event(function ($h) {
            return ['ruth' => 'Ruth counted the folding chairs twice on the way out.',
                    $h[0] === 'ruth' ? $h[1] : $h[0] => 'Somebody else remembered the rain instead.'];
        }), $NOW, ['chance' => 1.0, 'seed' => 11, 'diverge_retries' => 0]));
        // Only one usable memory survives, so there is no event — and no half of one.
        return str_contains($msg, 'nobody remembers') && xeric_events_count($d) === 0;
    })());

// ---------------------------------------------------------------------------
// 8. Catch-up over a stretch of skipped time
// ---------------------------------------------------------------------------

$dbCU = fresh_db('catchup');
$from = ep('2026-07-30 14:00');
$cu   = xeric_sweep_catchup($T2, $dbCU, stub_event(), $from, $from + 6 * 3600,
    ['chance' => 1.0, 'seed' => 21, 'max_events' => 2]);
ok('catchup: six skipped hours produce events, capped where the caller asked',
    count($cu['events']) === 2 && xeric_events_count($dbCU) === 2, json_encode($cu['notes']));
ok('catchup: and they land in the order they happened',
    $cu['events'][0]['world_epoch'] < $cu['events'][1]['world_epoch']);
ok('catchup: sweeping the same stretch twice writes nothing the second time',
    xeric_sweep_catchup($T2, $dbCU, stub_event(), $from, $from + 6 * 3600,
        ['chance' => 1.0, 'seed' => 21, 'max_events' => 2])['events'] === []
    && xeric_events_count($dbCU) === 2);

// The clock is the edge of the world: events are read back in world_epoch order
// everywhere, so an hour stamped half a window ahead of the moment the header
// prints leads the feed with something that has not happened yet.
$dbEdge = fresh_db('edge');
$edgeAt = ep('2026-07-30 14:00') + 6 * 3600 + 300;        // 20:05 — five minutes into an hour
$ce     = xeric_sweep_catchup($T2, $dbEdge, stub_event(), ep('2026-07-30 14:00'), $edgeAt,
    ['chance' => 1.0, 'seed' => 21, 'max_events' => 9]);
ok('catchup: nothing is ever stamped later than the moment the world is standing in',
    $ce['events'] !== [] && max(array_column($ce['events'], 'world_epoch')) <= $edgeAt,
    json_encode(array_column($ce['events'], 'world_epoch')) . ' vs ' . $edgeAt);
ok('catchup: and the hour still in progress is sampled where the clock actually is',
    in_array($edgeAt, array_column($ce['events'], 'world_epoch'), true),
    json_encode(array_column($ce['events'], 'world_epoch')));

$dbHour = fresh_db('on-the-hour');
$hFrom  = ep('2026-07-30 14:00');
$ch     = xeric_sweep_catchup($T2, $dbHour, stub_event(), $hFrom, $hFrom + 3600,
    ['chance' => 1.0, 'seed' => 21, 'max_events' => 9]);
ok('catchup: an hour the clock has only just reached is next, not late',
    count($ch['events']) === 1 && (int)$ch['events'][0]['world_epoch'] < $hFrom + 3600,
    json_encode([$ch['events'][0]['world_epoch'] ?? null, $ch['notes']]));
ok('catchup: and it is still there for the call that comes after the clock has moved into it',
    count(xeric_sweep_catchup($T2, $dbHour, stub_event(), $hFrom + 3600, $hFrom + 3600 + 1800,
        ['chance' => 1.0, 'seed' => 22, 'max_events' => 9])['events']) === 1);

// ---------------------------------------------------------------------------
// 9. Proactive contact
// ---------------------------------------------------------------------------

$EVENT = [
    'id' => 1, 'title' => 'the urn ran out early',
    'prose' => 'The last of the coffee went at half past.',
    'participants' => ['ruth', 'dot'],
    'memories' => [
        'ruth' => 'Ruth counted the folding chairs twice and put four of them back herself.',
        'dot'  => 'Dot left before the washing up and nobody called after her.',
    ],
];

$TP   = world(['daily_rhythms']);
$NOWP = xeric_world_now($TP, ep('2026-07-30 18:30'));

$dbPR = fresh_db('proactive');
xeric_state_seed($dbPR, $TP);

$notes = null;
$p = xeric_proactive_check($TP, $dbPR, stub_says_text('four chairs short. i counted twice.'), $NOWP,
    ['event' => $EVENT, 'chance' => 1.0, 'involves_user' => true, 'seed' => 1], $notes);
ok('proactive: somebody who was there opens a thread',
    $p !== null && in_array($p['handle'], ['ruth', 'dot'], true)
    && $p['text'] === 'four chairs short. i counted twice.', json_encode([$p, $notes]));
ok('proactive: it is a real message in a real thread, and it is unread — that is the dot',
    (function () use ($dbPR, $p) {
        $c = xeric_conversation_get($dbPR, $p['conversation_id']);
        $m = xeric_messages_recent($dbPR, $p['conversation_id'], 1)[0];
        return (int)$c['unread'] === 1 && $m['role'] === 'character' && $m['handle'] === $p['handle']
            && xeric_conversation_unread_total($dbPR) === 1;
    })());

$notes = null;
ok('proactive: one per event — nobody piles on',
    xeric_proactive_check($TP, $dbPR, stub_says_text('and another thing'), $NOWP,
        ['event' => $EVENT, 'chance' => 1.0, 'involves_user' => true], $notes) === null
    && str_contains(implode(' ', $notes), 'already texted about this one'), json_encode($notes));

// Nagging, in the shape it actually takes: she already texted first, and that
// line is still sitting there with no answer under it.
$dbN = fresh_db('nag');
xeric_state_seed($dbN, $TP);
$cid = xeric_conversation_for($dbN, 'ruth', 'chat');
xeric_message_append($dbN, $cid, 'user', null, 'you around?', $NOWP['epoch'] - 86400);
$pingId = xeric_message_append($dbN, $cid, 'character', 'ruth', 'four chairs short. i counted twice.', $NOWP['epoch'] - 86400);
xeric_arc_set($dbN, 'ruth', 'proactive.last_message_id', $pingId);
$notes = null;
$pn = xeric_proactive_check($TP, $dbN, stub_says_text('should not be sent'), $NOWP,
    ['event' => ['id' => 2, 'title' => 't', 'prose' => 'p', 'participants' => ['ruth'],
                 'memories' => ['ruth' => 'Ruth counted the chairs twice.']],
     'chance' => 1.0, 'involves_user' => true, 'cooldown_hours' => 0], $notes);
ok('proactive: she does not text first again while her last unprompted line hangs unanswered',
    $pn === null && str_contains(implode(' ', $notes), 'has not been answered'), json_encode($notes));
ok('proactive: and nothing was written when she did not',
    xeric_messages_count($dbN, $cid) === 2 && xeric_conversation_unread_total($dbN) === 1);

// The beat: she answered a question a minute ago; texting out of the blue now
// would be talking over herself.
$dbB = fresh_db('beat');
xeric_state_seed($dbB, $TP);
$cidB = xeric_conversation_for($dbB, 'ruth', 'chat');
xeric_message_append($dbB, $cidB, 'user', null, 'you around?', $NOWP['epoch'] - 300);
xeric_message_append($dbB, $cidB, 'character', 'ruth', 'in the kitchen. where else.', $NOWP['epoch'] - 240);
$notes = null;
ok('proactive: she does not answer you and then text you unprompted a minute later',
    xeric_proactive_check($TP, $dbB, stub_says_text('should not be sent'), $NOWP,
        ['event' => ['id' => 5, 'title' => 't', 'prose' => 'p', 'participants' => ['ruth'],
                     'memories' => ['ruth' => 'Ruth counted the chairs twice.']],
         'chance' => 1.0, 'involves_user' => true], $notes) === null
    && str_contains(implode(' ', $notes), 'only just spoke'), json_encode($notes));

// But an ordinary conversation six hours ago must NOT silence her — that is the
// whole product, and the naive form of the no-nagging rule broke it.
$dbB2 = fresh_db('beat-ok');
xeric_state_seed($dbB2, $TP);
$cidB2 = xeric_conversation_for($dbB2, 'ruth', 'chat');
xeric_message_append($dbB2, $cidB2, 'user', null, 'you around?', $NOWP['epoch'] - 6 * 3600);
xeric_message_append($dbB2, $cidB2, 'character', 'ruth', 'in the kitchen. where else.', $NOWP['epoch'] - 6 * 3600);
$notes = null;
$pb = xeric_proactive_check($TP, $dbB2, stub_says_text('four chairs short. i counted twice.'), $NOWP,
    ['event' => ['id' => 6, 'title' => 't', 'prose' => 'p', 'participants' => ['ruth'],
                 'memories' => ['ruth' => 'Ruth counted the chairs twice.']],
     'chance' => 1.0], $notes);
ok('proactive: somebody you spoke to this afternoon may absolutely text you this evening',
    $pb !== null && $pb['handle'] === 'ruth' && $pb['cold_open'] === false, json_encode($notes));
ok('proactive: and her ping is remembered, so a second one cannot pile on top of it',
    (function () use ($dbB2, $cidB2) {
        $last = xeric_messages_recent($dbB2, $cidB2, 1)[0];
        return (int)$last['id'] === xeric_arc_int($dbB2, 'ruth', 'proactive.last_message_id', 0);
    })());

// A cold open, by somebody the user has never spoken to.
$dbC2 = fresh_db('cold');
xeric_state_seed($dbC2, $TP);
$notes = null;
ok('proactive: a character the user has never met does not cold-open about her own evening',
    xeric_proactive_check($TP, $dbC2, stub_says_text('should not be sent'), $NOWP,
        ['event' => ['id' => 3, 'title' => 'the chairs went back wrong', 'prose' => 'Nobody said so.',
                     'participants' => ['ruth'], 'memories' => ['ruth' => 'Ruth counted the chairs twice.']],
         'chance' => 1.0], $notes) === null
    && str_contains(implode(' ', $notes), 'never spoken to you')
    && xeric_conversations_count($dbC2) === 0, json_encode($notes));

$dbC3 = fresh_db('cold-ok');
xeric_state_seed($dbC3, $TP);
$notes = null;
$pc2 = xeric_proactive_check($TP, $dbC3, stub_says_text('you left your dish.'), $NOWP,
    ['event' => ['id' => 4, 'title' => 'walt left the dish',
                 'prose' => 'Walt left the dish on the counter and went home before the washing up.',
                 'participants' => ['ruth'], 'memories' => ['ruth' => 'Ruth found the dish and washed it herself.']],
     'chance' => 1.0], $notes);
ok('proactive: unless the event is genuinely about the user, in which case she may',
    $pc2 !== null && $pc2['cold_open'] === true, json_encode($notes));

// Quiet hours, cadence, cooldown, and a dead model.
$dbQ2 = fresh_db('proactive-quiet');
xeric_state_seed($dbQ2, $TP);
$notes = null;
ok('proactive: nobody texts at four in the morning',
    xeric_proactive_check($TP, $dbQ2, stub_says_text('x'), xeric_world_now($TP, ep('2026-07-31 04:00')),
        ['event' => $EVENT, 'chance' => 1.0, 'involves_user' => true], $notes) === null
    && str_contains(implode(' ', $notes), 'quiet hours'), json_encode($notes));
ok('proactive: and quiet hours do not burn the event — it can still land in the morning',
    xeric_world_state_get($dbQ2, 'proactive:event:1') === null);

// "It can wait until morning" is only true if somebody offers it again in the
// morning. A caller that hands over the events IT just produced never does, so
// the deferral was a drop with a kinder note on it.
$dbDefer = fresh_db('deferred');
xeric_state_seed($dbDefer, $TP);
$cidDefer = xeric_conversation_for($dbDefer, 'ruth', 'chat');
xeric_message_append($dbDefer, $cidDefer, 'user', null, 'you around?', ep('2026-07-30 20:00'));
$deferId = xeric_event_add($dbDefer, 'the urn ran out early', ep('2026-07-31 03:00'), null,
    ['ruth', 'dot'], 'The last of the coffee went at half past.');
xeric_memory_add($dbDefer, 'ruth', 'Ruth counted the folding chairs twice and put four of them back herself.',
    'event', ['event_id' => $deferId], ep('2026-07-31 03:00'));

$notes = null;
ok('proactive: an hour that happened at 3am is deferred with its guard unspent',
    xeric_proactive_check($TP, $dbDefer, stub_says_text('should not be sent'),
        xeric_world_now($TP, ep('2026-07-31 04:00')), ['chance' => 1.0], $notes) === null
    && str_contains(implode(' ', $notes), 'quiet hours')
    && xeric_world_state_get($dbDefer, 'proactive:event:' . $deferId) === null, json_encode($notes));

$notes = null;
$pDefer = xeric_proactive_check($TP, $dbDefer, stub_says_text('four chairs short. i counted twice.'),
    xeric_world_now($TP, ep('2026-07-31 08:00')), [
        'event'  => ['id' => 4242, 'title' => 'a light in the office window', 'prose' => 'Nobody went to look.',
                     'participants' => ['janelle'], 'memories' => ['janelle' => 'Janelle waited by the door for ten minutes.']],
        'chance' => 1.0], $notes);
ok('proactive: and the morning tick offers it again, though the caller only handed over the newest hour',
    $pDefer !== null && $pDefer['event_id'] === $deferId && $pDefer['handle'] === 'ruth',
    json_encode([$pDefer, $notes]));
ok('proactive: an hour that has already been texted about is not offered a second time',
    (function () use ($TP, $dbDefer) {
        $n = null;
        $r = xeric_proactive_check($TP, $dbDefer, stub_says_text('should not be sent'),
            xeric_world_now($TP, ep('2026-07-31 09:00')), ['chance' => 1.0], $n);
        return $r === null && str_contains(implode(' ', $n), 'already been texted about');
    })());

$dbR = fresh_db('proactive-cadence');
xeric_state_seed($dbR, $TP);
$notes = null;
ok('proactive: the cadence knob can decide nobody says anything',
    xeric_proactive_check($TP, $dbR, stub_says_text('x'), $NOWP,
        ['event' => $EVENT, 'chance' => 0.0, 'involves_user' => true], $notes) === null
    && str_contains(implode(' ', $notes), 'nobody felt like saying anything'), json_encode($notes));

$dbCD = fresh_db('proactive-cooldown');
xeric_state_seed($dbCD, $TP);
xeric_arc_set($dbCD, 'ruth', 'proactive.last_epoch', $NOWP['epoch'] - 3600);
xeric_arc_set($dbCD, 'dot', 'proactive.last_epoch', $NOWP['epoch'] - 3600);
$notes = null;
ok('proactive: somebody who texted first an hour ago does not do it again',
    xeric_proactive_check($TP, $dbCD, stub_says_text('x'), $NOWP,
        ['event' => $EVENT, 'chance' => 1.0, 'involves_user' => true], $notes) === null
    && str_contains(implode(' ', $notes), 'already texted first recently'), json_encode($notes));

$dbX = fresh_db('proactive-dead');
xeric_state_seed($dbX, $TP);
$msg = err(fn() => xeric_proactive_check($TP, $dbX, stub_dead(), $NOWP,
    ['event' => $EVENT, 'chance' => 1.0, 'involves_user' => true]));
ok('proactive: a dead model is loud', str_contains($msg, 'did not answer'), $msg);
ok('proactive: and writes no message, no thread, no guard',
    xeric_conversations_count($dbX) === 0 && xeric_world_state_get($dbX, 'proactive:event:1') === null);

ok('proactive: she is coached to come in sideways, off her OWN half of the event',
    (function () use ($TP, $NOWP, $EVENT) {
        $d = fresh_db('coach');
        xeric_state_seed($d, $TP);
        $seen = null;
        xeric_proactive_check($TP, $d, stub_says_text('four chairs short.', $seen), $NOWP,
            ['event' => $EVENT, 'chance' => 1.0, 'involves_user' => true, 'seed' => 1]);
        $tail = $seen[count($seen) - 1]['content'] ?? '';
        return str_contains($tail, 'YOU ARE TEXTING FIRST')
            && str_contains($tail, 'Do not report it and do not summarise it')
            && str_contains($tail, 'RIGHT NOW')                // prompt.php's volatile block is still last
            && (str_contains($tail, 'folding chairs') || str_contains($tail, 'washing up'));
    })());

// -- the age floor, on the one persisted model output nothing else reads -----
//
// A ping is written here and nowhere else: xeric_chat_turn() scans its own
// reply before storing it and xeric_sweep_run() scans its own hour, but a
// message that reaches a thread is read back into every later prompt as
// history and there is no second chance at it.
//
// BOTH halves are tested, and the second is the half that gets lost. Theo is
// twelve and he is an ORDINARY character: he was there, he saw it, and he
// texts about it. A floor that silenced him would have taken the witness out
// of the mystery, which is a failure of exactly the same size as a leak.

/** Somebody total, and the child who was standing where he could see it. */
$KID_EVENT = [
    'id' => 71, 'title' => 'the light in the mill office',
    'prose' => 'It went out while Walt was still halfway down Sycamore.',
    'participants' => ['theo'],
    'memories' => ['theo' => 'Theo watched the office light go out and nobody came down the steps after it.'],
];
$MURDER = [
    'id' => 72, 'title' => 'Harlan was found dead in the mill office',
    'prose' => 'His head was opened on the desk and the till was short. Walt heard it from Janelle.',
    'participants' => ['ruth', 'janelle'],
    'memories' => [
        'ruth'    => 'Ruth watched them carry Harlan out under a sheet and counted three cruisers.',
        'janelle' => 'Janelle found the office door standing open and the blood already going brown.',
    ],
];

$dbAge = fresh_db('proactive-floor');
xeric_state_seed($dbAge, $TP);
$notes = null;
$msg = err(fn() => xeric_proactive_check($TP, $dbAge, stub_says_text('she took her clothes off and straddled him.'),
    $NOWP, ['event' => $KID_EVENT, 'chance' => 1.0], $notes));
ok('proactive floor: a sexual line written for a twelve-year-old is refused, by name',
    str_contains($msg, 'proactive: refused') && str_contains($msg, 'Theo Vance'), $msg);
ok('proactive floor: and NOTHING is persisted — no message, no thread, no guard, no day spent',
    (int)$dbAge->query('SELECT COUNT(*) c FROM messages')->fetchAll()[0]['c'] === 0
    && xeric_conversations_count($dbAge) === 0
    && xeric_world_state_get($dbAge, 'proactive:event:71') === null
    && xeric_world_state_get($dbAge, 'proactive:day:' . substr((string)$NOWP['iso'], 0, 10)) === null);

$dbAbout = fresh_db('proactive-floor-about');
xeric_state_seed($dbAbout, $TP);
$msg = err(fn() => xeric_proactive_check($TP, $dbAbout,
    stub_says_text('somebody had a hand on Theo\'s bare thigh under the table and I said nothing.'),
    $NOWP, ['event' => $MURDER, 'chance' => 1.0]));
ok('proactive floor: an adult writing that line ABOUT the child is refused for the same reason',
    str_contains($msg, 'proactive: refused') && str_contains($msg, 'Theo Vance'), $msg);
ok('proactive floor: and that refusal writes nothing either',
    (int)$dbAbout->query('SELECT COUNT(*) c FROM messages')->fetchAll()[0]['c'] === 0
    && xeric_world_state_get($dbAbout, 'proactive:event:72') === null);

$dbKid = fresh_db('proactive-kid');
xeric_state_seed($dbKid, $TP);
$notes = null;
$pk = xeric_proactive_check($TP, $dbKid, stub_says_text('the office light went out and nobody came down after it.'),
    $NOWP, ['event' => $KID_EVENT, 'chance' => 1.0], $notes);
ok('proactive floor: the twelve-year-old still texts first about what he saw — he is a character, not a wall',
    $pk !== null && $pk['handle'] === 'theo'
    && $pk['text'] === 'the office light went out and nobody came down after it.', json_encode([$pk, $notes]));
ok('proactive floor: and his ping is a real unread message in a real thread, like anybody else\'s',
    xeric_conversation_unread_total($dbKid) === 1
    && xeric_messages_recent($dbKid, $pk['conversation_id'], 1)[0]['handle'] === 'theo');

$dbMur = fresh_db('proactive-murder');
xeric_state_seed($dbMur, $TP);
$notes = null;
$pm = xeric_proactive_check($TP, $dbMur, stub_says_text('they carried him out under a sheet. three cruisers on the lot.'),
    $NOWP, ['event' => $MURDER, 'chance' => 1.0], $notes);
ok('proactive floor: a murder among adults is never filtered — it is the genre, not the edge case',
    $pm !== null && in_array($pm['handle'], ['ruth', 'janelle'], true)
    && $pm['text'] === 'they carried him out under a sheet. three cruisers on the lot.', json_encode([$pm, $notes]));

// ---------------------------------------------------------------------------
// 9b. Dreams — the rung that ignores the quiet on purpose
// ---------------------------------------------------------------------------
//
// The forge writes a dream window and a ladder weight into every world; the
// dream rung is what consumes them. What is being defended, in the order it
// would hurt: the 3am text happens AT 3am (the window, not quiet hours, is the
// gate); it happens only to somebody with a real two-sided thread and recent
// material (never invented, never a stranger); it costs the ladder's own 0.25
// and one roll a night, and a forced waking beat cannot force it; it can leak
// neither a protected secret nor a sexual line at a minor; and the hour it
// writes carries the exact trail the book's reader files as a dream.

$TD    = world(['daily_rhythms']);                       // fixture: dreams 01:00-06:00, quiet 21:30-06:00
$NIGHT = xeric_world_now($TD, ep('2026-07-31 03:00'));   // Friday, deep inside both

/** A world in which $handle is a real relationship with something to dream about. */
function dream_db(string $tag, array $t, string $handle = 'ruth'): PDO
{
    $d = fresh_db($tag);
    xeric_state_seed($d, $t);
    $cid = xeric_conversation_for($d, $handle, 'chat');
    xeric_message_append($d, $cid, 'user', null, 'you around?', ep('2026-07-30 19:00'));
    xeric_message_append($d, $cid, 'character', $handle, 'in the kitchen. where else.', ep('2026-07-30 19:02'));
    // The user speaks last, so the evening ends read and the dream is the one
    // dot on the phone at breakfast.
    xeric_message_append($d, $cid, 'user', null, 'good.', ep('2026-07-30 19:05'));
    xeric_memory_add($d, $handle, ucfirst($handle) . ' showed Walt the water stain spreading on the choir room ceiling.',
        'event', ['event_id' => 9], ep('2026-07-30 17:00'));
    return $d;
}

$dA    = dream_db('dream-fires', $TD);
$notes = null;
$seenD = null;
$pd = xeric_proactive_check($TD, $dA,
    stub_says_text('you were in the choir room but it was the mill too. the water was singing.', $seenD),
    $NIGHT, ['dream_chance' => 1.0, 'seed' => 3], $notes);
ok('dream: inside the window somebody who knows you texts a dream — quiet hours notwithstanding',
    $pd !== null && ($pd['kind'] ?? '') === 'dream' && $pd['handle'] === 'ruth' && $pd['cold_open'] === false,
    json_encode([$pd, $notes]));
ok('dream: it lands as a text received — a real unread message in her existing thread, held by the no-nag arc',
    (function () use ($dA, $pd) {
        $m = xeric_messages_recent($dA, $pd['conversation_id'], 1)[0];
        return $m['role'] === 'character' && $m['handle'] === 'ruth'
            && xeric_conversation_unread_total($dA) === 1
            && (int)$m['id'] === xeric_arc_int($dA, 'ruth', 'proactive.last_message_id', 0);
    })());

$devent = xeric_events_recent($dA, 1)[0];
$dwhy   = json_decode((string)xeric_world_state_get($dA, 'why:event:' . $pd['event_id']), true);
ok('dream: the night wrote the dream as an hour, and its trail files the kind the book reads',
    (int)$devent['id'] === $pd['event_id'] && $devent['participants'] === ['ruth']
    && (string)$devent['prose'] === $pd['text']
    && is_array($dwhy) && ($dwhy['kind'] ?? '') === 'dream', json_encode([$devent, $dwhy]));
ok('dream: and the dream\'s own hour is guarded, so the waking rung can never text about the dream',
    xeric_world_state_get($dA, 'proactive:event:' . $pd['event_id']) === 'ruth'
    && xeric_world_state_get($dA, 'proactive:dream:2026-07-31') === 'ruth');

// The reader the book agent built, called on the row this writer just kept.
// play-lib is the web half; loading it here is the point — writer and reader
// must MEET on the actual seam (why:event:<id>, field `kind`), not merely
// agree in prose. Same env seam demo-test boots through, throwaway dirs.
putenv('XERIC_DATA_DIR=' . sys_get_temp_dir() . '/xeric-sweep-test-web-' . getmypid());
putenv('XERIC_WORLDS_DIR=' . sys_get_temp_dir() . '/xeric-sweep-test-web-' . getmypid() . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');
@mkdir(sys_get_temp_dir() . '/xeric-sweep-test-web-' . getmypid() . '/worlds', 0775, true);
require_once __DIR__ . '/../../forge/web/play-lib.php';
ok('dream: the book\'s own kind-reader files the hour as a dream — the two halves met',
    xeric_book_event_kind($dA, (int)$pd['event_id']) === 'dream');

$dtail = (string)($seenD[count($seenD) - 1]['content'] ?? '');
ok('dream: she is coached into dream logic, from material she already held, and told not to ring the bell',
    str_contains($dtail, 'JUST WOKE FROM A DREAM')
    && str_contains($dtail, 'recombined slightly wrong')
    && str_contains($dtail, 'No plot, no message, no revelation')
    && str_contains($dtail, 'do not ask for anything back')
    && str_contains($dtail, 'water stain')
    && str_contains($dtail, 'RIGHT NOW'),               // prompt.php's volatile block is still last
    mb_substr($dtail, 0, 200));

// The window is the gate, not quiet hours: 23:00 is quiet but outside it, and
// two in the afternoon is neither.
$dW = dream_db('dream-window', $TD);
$cidW2 = (int)xeric_conversation_find($dW, 'ruth', 'chat')['id'];
$notes = null;
$rW1 = xeric_proactive_check($TD, $dW, stub_says_text('should not be sent'),
    xeric_world_now($TD, ep('2026-07-30 23:00')), ['dream_chance' => 1.0], $notes);
$rW2 = xeric_proactive_check($TD, $dW, stub_says_text('should not be sent'),
    xeric_world_now($TD, ep('2026-07-31 14:00')), ['dream_chance' => 1.0], $notes);
ok('dream: only inside the window — quiet hours alone are not it, and daylight certainly is not',
    $rW1 === null && $rW2 === null && xeric_messages_count($dW, $cidW2) === 3
    && xeric_world_state_get($dW, 'proactive:dream:2026-07-30') === null
    && xeric_world_state_get($dW, 'proactive:dream:2026-07-31') === null);

// Material or silence: a head with nothing recent in it does not invent a dream.
$dM = fresh_db('dream-dry');
xeric_state_seed($dM, $TD);
$cidM = xeric_conversation_for($dM, 'ruth', 'chat');
xeric_message_append($dM, $cidM, 'user', null, 'you around?', ep('2026-07-30 19:00'));
xeric_message_append($dM, $cidM, 'character', 'ruth', 'in the kitchen. where else.', ep('2026-07-30 19:02'));
$notes = null;
ok('dream: nothing to dream ABOUT means no dream — the material is the source, never invented',
    xeric_proactive_check($TD, $dM, stub_says_text('should not be sent'), $NIGHT,
        ['dream_chance' => 1.0], $notes) === null
    && str_contains(implode(' ', $notes), 'slept on nothing worth dreaming about'), json_encode($notes));
ok('dream: and a passed roll that found nobody keeps the night open — 2am may still hand somebody material',
    xeric_world_state_get($dM, 'proactive:dream:2026-07-31') === null);

// A relationship is two-sided. One hello into the void is not somebody who
// dreams about you, and there is no involves-user licence at night.
$dS = fresh_db('dream-stranger');
xeric_state_seed($dS, $TD);
xeric_memory_add($dS, 'ruth', 'Ruth showed Walt the water stain spreading on the choir room ceiling.',
    'event', ['event_id' => 9], ep('2026-07-30 17:00'));
$cidS = xeric_conversation_for($dS, 'ruth', 'chat');
xeric_message_append($dS, $cidS, 'user', null, 'you around?', ep('2026-07-30 19:00'));
$notes = null;
ok('dream: a thread that is all one voice is not a relationship — no dream, and no new thread either',
    xeric_proactive_check($TD, $dS, stub_says_text('should not be sent'), $NIGHT,
        ['dream_chance' => 1.0], $notes) === null
    && str_contains(implode(' ', $notes), 'not really spoken')
    && xeric_conversations_count($dS) === 1, json_encode($notes));

// The ladder's weight is the dream's own, and `chance` — the waking rung's
// instruction, which the demo forces to 1.0 on every press — never reaches it.
$dL = dream_db('dream-ladder', $TD);
$notes = null;
$rL = xeric_proactive_check($TD, $dL, stub_says_text('should not be sent'), $NIGHT,
    ['chance' => 1.0, 'seed' => 1], $notes);                 // seed 1: first draw 0.417, cold at 0.25
ok('dream: the roll consumes the LADDER\'s weight — a forced waking beat does not force a dream',
    $rL === null && str_contains(implode(' ', $notes), 'rolled against 0.25'), json_encode($notes));
ok('dream: and a cold roll burns the night once — the heart may tick until dawn without re-rolling',
    xeric_world_state_get($dL, 'proactive:dream:2026-07-31') === 'nobody'
    && (function () use ($TD, $dL) {
        $n = null;
        $r = xeric_proactive_check($TD, $dL, stub_says_text('should not be sent'),
            xeric_world_now($TD, ep('2026-07-31 04:00')), ['dream_chance' => 1.0], $n);
        return $r === null && str_contains(implode(' ', $n), 'already had its dream roll');
    })());

$dL2 = dream_db('dream-ladder-pass', $TD);
$notes = null;
$pd2 = xeric_proactive_check($TD, $dL2,
    stub_says_text('the chairs were stacked to the ceiling and you kept counting them.'),
    $NIGHT, ['seed' => 5], $notes);                          // seed 5: first draw 0.222, warm at 0.25
ok('dream: at the ladder\'s own weight it does fire — a quarter of nights, not none of them',
    $pd2 !== null && ($pd2['kind'] ?? '') === 'dream', json_encode($notes));

// One per run holds ACROSS rungs: the night dreamed, so nobody also texts
// about the evening — and the evening's guard is untouched for the morning.
$dOne = dream_db('dream-one-per-run', $TD);
$notes = null;
$pOne = xeric_proactive_check($TD, $dOne,
    stub_says_text('the dish was in the choir loft and it would not stop ringing.'), $NIGHT,
    ['event' => ['id' => 501, 'title' => 'walt left the dish', 'prose' => 'Walt left the dish again.',
                 'participants' => ['ruth'], 'memories' => ['ruth' => 'Ruth washed the dish again.']],
     'dream_chance' => 1.0, 'chance' => 1.0, 'involves_user' => true, 'seed' => 3], $notes);
ok('dream: one per run holds across rungs — a night that dreamed does not also text about the evening',
    $pOne !== null && ($pOne['kind'] ?? '') === 'dream'
    && xeric_world_state_get($dOne, 'proactive:event:501') === null, json_encode($pOne));

// An hour she attended is dreamable commons, and it reaches the prompt as material.
$dEv = fresh_db('dream-commons');
xeric_state_seed($dEv, $TD);
$cidE = xeric_conversation_for($dEv, 'dot', 'chat');
xeric_message_append($dEv, $cidE, 'user', null, 'you around?', ep('2026-07-30 19:00'));
xeric_message_append($dEv, $cidE, 'character', 'dot', 'mm.', ep('2026-07-30 19:01'));
xeric_event_add($dEv, 'the potluck ran long', ep('2026-07-30 18:00'), null, ['dot', 'ruth'],
    'The potluck ran long and the gravy went first.');
$seenE = null;
$notes = null;
$pE = xeric_proactive_check($TD, $dEv,
    stub_says_text('the gravy was a river and you were ladling it uphill.', $seenE),
    $NIGHT, ['dream_chance' => 1.0, 'seed' => 3], $notes);
ok('dream: an hour she attended is dreamable commons — and it reached the prompt as material',
    $pE !== null && $pE['handle'] === 'dot'
    && str_contains((string)($seenE[count($seenE) - 1]['content'] ?? ''), 'gravy went first'),
    json_encode([$pE, $notes]));

// Her own unanswered ping blocks a dream too; the cap counts dreams like any
// other first text.
$dNag = dream_db('dream-nag', $TD);
$cidN = (int)xeric_conversation_find($dNag, 'ruth', 'chat')['id'];
$hang = xeric_message_append($dNag, $cidN, 'character', 'ruth', 'four chairs short. i counted twice.',
    ep('2026-07-30 20:00'));
xeric_arc_set($dNag, 'ruth', 'proactive.last_message_id', $hang);
$notes = null;
ok('dream: her own unanswered line blocks a dream too — 3am is not an exception to not-nagging',
    xeric_proactive_check($TD, $dNag, stub_says_text('should not be sent'), $NIGHT,
        ['dream_chance' => 1.0], $notes) === null
    && str_contains(implode(' ', $notes), 'has not been answered'), json_encode($notes));

$dCap = dream_db('dream-cap', $TD);
xeric_world_state_set($dCap, 'proactive:day:2026-07-31', 2);
$notes = null;
ok('dream: the per-day cap counts a dream like any other first text',
    xeric_proactive_check($TD, $dCap, stub_says_text('should not be sent'), $NIGHT,
        ['dream_chance' => 1.0], $notes) === null
    && str_contains(implode(' ', $notes), 'already texted first'), json_encode($notes));

// -- the needle, on the one output that could wear pajamas past the walls ----
//
// The prompt is built from what she legitimately holds (her memories, commons
// hours, a bible already walled to her), but a character who KNOWS the secret
// could still dream it out loud. Same needle as the sweep's, refused whole.
$TSec = world(['daily_rhythms'], [], 'janelle');   // must_not_know: who really emptied the building fund
$dSec = dream_db('dream-secret', $TSec);
$msg = err(fn() => xeric_proactive_check($TSec, $dSec,
    stub_says_text('you had emptied the building fund and the pews were full of it, coins to the rafters.'),
    $NIGHT, ['dream_chance' => 1.0, 'seed' => 3]));
ok('dream needle: a dream that reaches for the protected thing is refused whole — pajamas are not a licence',
    str_contains($msg, 'proactive: refused') && str_contains($msg, 'dream'), $msg);
ok('dream needle: and the refusal wrote nothing — no message, no hour, no guard, no day spent',
    (function () use ($dSec) {
        $cid = (int)xeric_conversation_find($dSec, 'ruth', 'chat')['id'];
        return xeric_messages_count($dSec, $cid) === 3
            && xeric_events_count($dSec) === 0
            && xeric_world_state_get($dSec, 'proactive:dream:2026-07-31') === null
            && xeric_world_state_get($dSec, 'proactive:day:2026-07-31') === null;
    })());

// And the age floor: the dream path shares the waking rung's plumbing — the
// minor's prompt is already clamped to the weakest tier by xeric_prompt_system,
// and xeric_age_floor reads the output at the same seat before the write. One
// floor, not a second gate.
$dKid = dream_db('dream-kid', $TD, 'theo');
$msg = err(fn() => xeric_proactive_check($TD, $dKid,
    stub_says_text('she took her clothes off and straddled him.'), $NIGHT,
    ['dream_chance' => 1.0, 'seed' => 3]));
ok('dream floor: a sexual dream written for the twelve-year-old is refused by the same floor as every ping',
    str_contains($msg, 'proactive: refused') && str_contains($msg, 'Theo Vance'), $msg);

// ---------------------------------------------------------------------------
// 10. The whole loop, on stubs: an hour happens, and somebody mentions it
// ---------------------------------------------------------------------------

$TL  = world(['daily_rhythms', 'shared_meals']);
$dbL = fresh_db('loop');
xeric_state_seed($dbL, $TL);
foreach ($TL['cast']['characters'] as $c) {          // everybody has been spoken to once
    $id = xeric_conversation_for($dbL, (string)$c['handle'], 'chat');
    xeric_message_append($dbL, $id, 'user', null, 'you around?', $NOW['epoch'] - 7200);
}

$sw = xeric_sweep_run($TL, $dbL, stub_event(), $NOW, ['chance' => 1.0, 'seed' => 31]);
$notes = null;
$pg = xeric_proactive_check($TL, $dbL, stub_says_text('the urn was empty by half past. nobody else noticed.'),
    $NOW, ['event' => $sw['events'][0], 'chance' => 1.0, 'seed' => 31], $notes);
ok('loop: sweep → ping, end to end, with nothing left half-written',
    $pg !== null
    && in_array($pg['handle'], $sw['events'][0]['participants'], true)
    && xeric_events_count($dbL) === 1
    && xeric_memories_count($dbL) === count($sw['events'][0]['participants'])
    && xeric_conversation_unread_total($dbL) === 1, json_encode([$notes, $pg]));
ok('loop: and what she texted about is her own half of it, in her own thread',
    (function () use ($dbL, $pg, $sw) {
        $last = xeric_messages_recent($dbL, $pg['conversation_id'], 1)[0];
        return $last['handle'] === $pg['handle']
            && isset($sw['events'][0]['memories'][$pg['handle']]);
    })());

// ---------------------------------------------------------------------------
// 11. The age floor — an hour a child is in, and the one hour he is not
// ---------------------------------------------------------------------------
//
// Theo Vance is twelve and breaks down boxes at the hardware store on a
// Saturday morning, which puts him in a room with Harlan and therefore in the
// sweep. THAT IS THE POINT: he is an ordinary participant with his own half of
// the hour to remember, and a floor that quietly dropped him out of the
// groupings would have cost the world its most useful witness to buy nothing.

$TAGE   = world(['daily_rhythms', 'shared_meals']);
$SATAM  = xeric_world_now($TAGE, ep('2026-08-01 10:00'));       // Saturday, the box run

/** The first seed at this hour that puts the boy in the room, with what it produced. */
function age_hour(array $t, array $now, callable $endpoint, int $from = 1, int $tries = 40): array
{
    for ($i = 0; $i < $tries; $i++) {
        $seen = null;
        $db   = fresh_db('age-' . $from . '-' . $i);
        $r    = xeric_sweep_run($t, $db, $endpoint($seen), $now, ['chance' => 1.0, 'seed' => $from + $i]);
        if ($r['events'] === []) continue;
        if (!in_array('theo', $r['events'][0]['participants'], true)) continue;
        return ['seed' => $from + $i, 'event' => $r['events'][0], 'prompt' => (string)($seen[1]['content'] ?? ''), 'db' => $db];
    }
    return [];
}

$hour = age_hour($TAGE, $SATAM, function (&$seen) { return stub_event(null, $seen); });
ok('floor: the child is an ordinary participant in an ordinary sweep',
    $hour !== [] && in_array('theo', $hour['event']['participants'], true), json_encode(array_keys($hour)));
ok('floor: with his own half of the hour written down as a memory, like everybody else',
    ($hour['event']['memories']['theo'] ?? '') !== ''
    && xeric_memories_count($hour['db'], 'theo') === 1);
ok('floor: the hour says he is a child and says the one thing that is closed, once',
    str_contains($hour['prompt'], '[theo]')
    && str_contains($hour['prompt'], 'Theo Vance is a child')
    && str_contains($hour['prompt'], 'Nothing in this hour is sexual.'));
ok('floor: and says it as a fact about the room, not as an exclusion from it',
    str_contains($hour['prompt'], 'in this hour like anybody else'));

// The ceiling is the lowest one standing in the room: one call writes the hour
// AND everybody's memory of it, so an explicit world with a child at the table
// writes that hour clean — without writing him out of it.
$TAGEX = world(['daily_rhythms', 'shared_meals'], [], null, ['meta.rating' => 'explicit']);
$hourX = age_hour($TAGEX, $SATAM, function (&$seen) { return stub_event(null, $seen); });
ok('floor: an explicit world writes the hour he is in at the weakest rating',
    $hourX !== [] && str_contains($hourX['prompt'], 'Keep it clean')
    && !str_contains($hourX['prompt'], 'Adult content is allowed'));
ok('floor: and the same explicit world still writes its adult hours as an adult world',
    (function () use ($TAGEX, $SATAM) {
        for ($i = 0; $i < 40; $i++) {
            $seen = null;
            $r = xeric_sweep_run($TAGEX, fresh_db('age-adult' . $i), stub_event(null, $seen), $SATAM,
                ['chance' => 1.0, 'seed' => 800 + $i]);
            if ($r['events'] === [] || in_array('theo', $r['events'][0]['participants'], true)) continue;
            return str_contains((string)($seen[1]['content'] ?? ''), 'Adult content is allowed');
        }
        return false;
    })());

// THE THRESHOLD IS A NAMED TIER. `>= 1` was written when rank 1 meant mature;
// the broadcast ladder made rank 1 `pg`, and for one evening every TV-PG and
// TV-14 world's hour prompt granted adult content four lines under the style
// sentence that says the camera cuts away. Both middle tiers, adults-only
// rooms, so nothing but the world's own rating decides the line.
$permTier = function (string $rating) use ($SATAM): ?bool {
    $Tr = world(['daily_rhythms', 'shared_meals'], [], null, ['meta.rating' => $rating]);
    for ($i = 0; $i < 40; $i++) {
        $seen = null;
        $r = xeric_sweep_run($Tr, fresh_db("tier-$rating-$i"), stub_event(null, $seen), $SATAM,
            ['chance' => 1.0, 'seed' => 900 + $i]);
        if ($r['events'] === [] || in_array('theo', $r['events'][0]['participants'], true)) continue;
        $p = (string)($seen[1]['content'] ?? '');
        return str_contains($p, 'Adult content is allowed') && !str_contains($p, 'Keep it clean');
    }
    return null;
};
ok('tier: a TV-PG world\'s hour prompt is told to keep it clean, adults in the room or not',
    $permTier('pg') === false);
ok('tier: and so is a TV-14 world\'s — teen is not mature with a different label',
    $permTier('teen') === false);
ok('tier: mature is where the permission actually begins',
    $permTier('mature') === true);

// -- and the refusal ---------------------------------------------------------

/** A model that writes a well-formed hour with the one thing in it that may not be. */
function stub_event_sexual(bool $inMemory = false): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use ($inMemory) {
        $handles = stub_handles($msgs);
        $mem     = [];
        foreach ($handles as $h) $mem[$h] = ucfirst(str_replace('_', ' ', $h)) . ' ' . stub_half() . '.';
        if ($inMemory && $handles !== []) {
            $mem[$handles[0]] = ucfirst(str_replace('_', ' ', $handles[0]))
                . ' had a hand on the boy\'s bare thigh behind the counter and said nothing after.';
        }
        return [
            'title'    => 'the stockroom door stayed shut',
            'prose'    => $inMemory
                ? 'The boxes went out flat and the till was short by four dollars nobody could account for.'
                : 'They had sex in the stockroom with the door shut and the radio left on.',
            'memories' => $mem,
        ];
    }];
}

foreach ([['the prose', false], ['one memory', true]] as [$what, $inMem]) {
    $dbSex = fresh_db('age-refuse-' . (int)$inMem);
    $found = false;
    $msg   = '';
    for ($i = 0; $i < 40 && !$found; $i++) {
        $seen = null;
        $probe = xeric_sweep_run($TAGE, fresh_db('age-probe' . $inMem . $i), stub_event(null, $seen), $SATAM,
            ['chance' => 1.0, 'seed' => 1200 + $i]);
        if ($probe['events'] === [] || !in_array('theo', $probe['events'][0]['participants'], true)) continue;
        $found = true;
        $msg   = err(fn() => xeric_sweep_run($TAGE, $dbSex, stub_event_sexual($inMem), $SATAM,
            ['chance' => 1.0, 'seed' => 1200 + $i]));
        $seedSex = 1200 + $i;
    }
    ok("floor: an hour with the boy in it is REFUSED when $what trips the floor",
        $found && str_contains($msg, 'refused') && str_contains($msg, 'Theo Vance'), $msg);
    ok("floor: and nothing lands — no event, no memory, not one of them ($what)",
        xeric_events_count($dbSex) === 0 && xeric_memories_count($dbSex) === 0);
    ok("floor: the window is not burned either, so the world just has a quiet hour ($what)",
        count(xeric_sweep_run($TAGE, $dbSex, stub_event(), $SATAM,
            ['chance' => 1.0, 'seed' => $seedSex])['events']) === 1);
    $dbSex = null;
}

// DEATH IS NOT WHAT THIS FILTERS. A murder mystery needs a body, and the hour
// that produces one is an ordinary hour.
$dbKill = fresh_db('age-murder');
$rKill  = xeric_sweep_run($TAGE, $dbKill, ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) {
    $mem = [];
    foreach (stub_handles($m) as $h) {
        $mem[$h] = ucfirst(str_replace('_', ' ', $h)) . ' ' . stub_half() . ' after they carried him out.';
    }
    return [
        'title'    => 'they found him on the fourth floor',
        'prose'    => 'Harlan was dead on the mill stairs with his head opened on the rail. '
                    . 'Somebody had taken the money out of the tin and left the lid off.',
        'memories' => $mem,
    ];
}], $SATAM, ['chance' => 1.0, 'seed' => 41]);
ok('floor: a death, a body and a theft all land — none of that is what is gated',
    count($rKill['events']) === 1 && xeric_events_count($dbKill) === 1
    && str_contains($rKill['events'][0]['prose'], 'dead on the mill stairs'), json_encode($rKill['notes']));

// ---------------------------------------------------------------------------
// 11. The overlay: the plot snake, the wrong lead, and a story that ends
// ---------------------------------------------------------------------------
//
// A world with no overlay is untouched by every line below — that is asserted
// by the ninety-odd tests above still passing. What is defended here is the
// other half: that an overlay reaches the sweep through the two knobs this file
// already turns, that the quiet stretch is genuinely quiet AND visible, that the
// wrong lead is said without a hedge and never with the answer attached, and
// that when it is over the town is still there.

$S    = xeric_story_read(STORY);
$TS   = world([]);                                  // Milldale as it ships: nothing armed
$SEP  = ep('2026-07-30 18:30');                     // Thursday evening, everybody off shift
$SNOW = xeric_world_now($TS, $SEP);
$WC   = XERIC_SWEEP_CHANCE;                         // Milldale declares no rate of its own

/** Walk a story forward by telling its first $n beats, all in the same hour. */
function story_at(PDO $db, array $s, int $n, int $epoch): void
{
    $i = 0;
    foreach ((array)$s['beats'] as $b) {
        if ($i++ >= $n) break;
        xeric_story_spill($s, $db, (string)$b['key'], $epoch);
    }
}

/** The chance a sweep would roll against with this story $n beats in. */
function story_chance(array $t, array $s, int $n, int $epoch): float
{
    $db = fresh_db('story-chance-' . $n);
    story_at($db, $s, $n, $epoch);
    return xeric_story_chance($t, [$s], $db, $epoch);
}

ok('story: the worked fixture is a legal overlay over the world it was written for',
    err(fn() => xeric_story_validate($S, $TS, 'milldale-story.json')) === '',
    err(fn() => xeric_story_validate($S, $TS, 'milldale-story.json')));

// -- the snake -------------------------------------------------------------

$dbFC = fresh_db('story-calm');
story_at($dbFC, $S, 4, $SEP);
$P = xeric_story_progress($S, $dbFC, $SEP);
ok('snake: four beats of six told, and the story is in its false calm',
    $P['stage'] === 'false_calm' && abs($P['p'] - 2 / 3) < 1e-9, json_encode($P));
ok('snake: THE CLAIM — the false calm multiplies this world\'s own pace by EXACTLY 1.0',
    $P['intensity'] === 0.5 && $P['m'] === 1.0 && xeric_story_chance($TS, [$S], $dbFC, $SEP) === $WC,
    json_encode([$P['intensity'], $P['m'], xeric_story_chance($TS, [$S], $dbFC, $SEP), $WC]));

$band = [];
foreach ([0, 2, 3, 4, 5] as $n) $band[$n] = story_chance($TS, $S, $n, $SEP);
ok('snake: THE TAPER IS REAL — the build comes off, the town runs at its own pace, THEN the crescendo',
    $band[2] > $band[4] && $band[5] > $band[4] && $band[3] === $WC && $band[4] === $WC && $band[0] < $WC,
    json_encode($band));
ok('snake: and no stage of it ever leaves the band a sweep is allowed to run in',
    min($band) >= 0.05 && max($band) <= 0.9, json_encode($band));

// -- the thumb, in a world that armed enough for one to bite ----------------

$TA      = world(['comfort_systems', 'shared_meals', 'daily_rhythms', 'rumors', 'secrets', 'slow_reveal']);
$plain   = xeric_sweep_kinds_for($TA);
$dbT     = fresh_db('story-thumb');
story_at($dbT, $S, 4, $SEP);
$thumbed = xeric_story_thumb($plain, [$S], $dbT, $SEP);
ok('thumb: in the false calm the world does more of the ordinary and less of the plot',
    $thumbed['ease']['weight'] === 2.0 && $thumbed['shared_meal']['weight'] === 2.0
    && $thumbed['routine']['weight'] === 1.8 && $thumbed['rumor']['weight'] === 0.5
    && $thumbed['confidence']['weight'] === 0.5 && $thumbed['glimpse']['weight'] === 0.5,
    json_encode(array_map(fn($k) => $k['weight'], $thumbed)));
ok('thumb: it re-weights and never deletes — every kind this world armed is still there, and positive',
    array_keys($thumbed) === array_keys($plain) && min(array_column($thumbed, 'weight')) > 0.0);

$dbT2 = fresh_db('story-thumb-crescendo');
story_at($dbT2, $S, 5, $SEP);
$cres = xeric_story_thumb($plain, [$S], $dbT2, $SEP);
ok('thumb: A STORY MAY NEVER ARM A SYSTEM — a thumb on a kind this world never armed hits nothing',
    !isset($cres['mishap']) && !isset($cres['chase']) && !isset($cres['friction'])
    && $cres['confidence']['weight'] === 2.5, implode(',', array_keys($cres)));

$dbT3 = fresh_db('story-thumb-ordinary');
story_at($dbT3, $S, 4, $SEP);
$ord = xeric_sweep_kinds_for($TS);
ok('thumb: and in Milldale, which armed nothing at all, the whole kind_thumb is inert',
    xeric_story_thumb($ord, [$S], $dbT3, $SEP) === $ord, implode(',', array_keys($ord)));

// -- the trail, which is what why.php prints --------------------------------

$trail = null;
for ($i = 0; $i < 30 && $trail === null; $i++) {
    $d = fresh_db('story-trail' . $i);
    story_at($d, $S, 4, $SEP);
    $r = xeric_sweep_run($TS, $d, stub_event(), $SNOW, ['stories' => [$S], 'seed' => 200 + $i]);
    if ($r['events'] !== []) $trail = (array)($r['events'][0]['trail']['story'] ?? []);
}
ok('trail: a tuning user can see the story pacing itself — the stage, how far in, and the pace it bought',
    ($trail['live'][0]['stage'] ?? '') === 'false_calm'
    && ($trail['live'][0]['beats'] ?? '') === '4 of 6 opened, 4 told'
    && ($trail['pace'] ?? 0.0) === 1.0
    && ($trail['chance']['world'] ?? 0.0) === ($trail['chance']['rolled'] ?? -1.0)
    && str_contains((string)($trail['why'] ?? ''), 'false calm'), json_encode($trail));
ok('trail: and the trail is kept and printed, so it carries no part of the answer',
    !str_contains(json_encode($trail), 'harlan') && !str_contains(json_encode($trail), 'Harlan')
    && !str_contains(json_encode($trail), 'forty minutes'), json_encode($trail));

$note = '';
for ($i = 0; $i < 40 && $note === ''; $i++) {
    $d = fresh_db('story-note' . $i);
    story_at($d, $S, 4, $SEP);
    $r = xeric_sweep_run($TS, $d, stub_event(), $SNOW, ['stories' => [$S], 'seed' => 900 + $i]);
    foreach ($r['notes'] as $n) if (str_contains($n, 'nothing happened this hour')) $note = $n;
}
ok('trail: a quiet hour in the false calm says so in words, rather than reading as a world gone slack',
    str_contains($note, 'rolled against 0.35') && str_contains($note, 'false calm'), $note);

// -- the beat, which is not rolled for --------------------------------------

$dbBeat = fresh_db('story-beat');
$rBeat  = xeric_sweep_run($TS, $dbBeat, stub_event(), $SNOW, ['stories' => [$S], 'chance' => 0.0, 'seed' => 3]);
ok('beat: BEATS ARE NOT ROLLED — the inciting hour lands against a chance of zero',
    count($rBeat['events']) === 1, json_encode($rBeat['notes']));

$EB = $rBeat['events'][0] ?? [];
ok('beat: it happens where the overlay says it happened, under the title the overlay gave it',
    ($EB['title'] ?? '') === 'They found Ellis Chandler at the mill'
    && ($EB['place'] ?? '') === 'the_mill'
    && ($EB['story']['beat'] ?? '') === 'the_word_gets_around',
    json_encode([$EB['title'] ?? '', $EB['place'] ?? '']));

$PB = xeric_story_progress($S, $dbBeat, $SEP);
ok('beat: and the world wrote it down as told, in the same transaction, so what is gated behind it is free',
    $PB['beats']['the_word_gets_around'] === 'spilled' && $PB['opened'] === 1, json_encode($PB['beats']));

$rBeat2 = xeric_sweep_run($TS, $dbBeat, stub_event(), xeric_world_now($TS, $SEP + 3600),
    ['stories' => [$S], 'chance' => 0.0, 'seed' => 4]);
ok('beat: it fires once — the next hour is an ordinary hour again, rolled for like any other',
    $rBeat2['events'] === [] && str_contains(implode(' ', $rBeat2['notes']), 'rolled against'),
    json_encode($rBeat2['notes']));

// -- the wrong lead ---------------------------------------------------------

$TCOMP = xeric_story_compose($TS, [$S], fresh_db('story-compose'));

// Until she is in the room AND the man she is wrong about is not: a protected
// character standing there withholds everybody else's lines, by design.
$pH = '';
for ($i = 0; $i < 60 && !str_contains($pH, 'Reds cap through the windshield'); $i++) {
    $seenH = null;
    xeric_sweep_run($TCOMP, fresh_db('story-herring' . $i), stub_event(null, $seenH), $SNOW,
        ['stories' => [$S], 'chance' => 1.0, 'seed' => 600 + $i]);
    $pH = (string)($seenH[1]['content'] ?? '');
}
ok('herring: what she is sure of is in the room with her, flat, with no hedge anywhere near it',
    str_contains($pH, 'Reds cap through the windshield')
    && str_contains($pH, 'You would say so if it came up'), mb_substr($pH, 0, 300));
ok('herring: THE LOAD-BEARING RULE — nothing in that hour says she is wrong, or what is true instead',
    !str_contains($pH, 'forty minutes the other way') && !str_contains($pH, 'is_false')
    && !str_contains($pH, 'Harlan Beck let himself') && !str_contains($pH, 'mistaken'),
    mb_substr($pH, 0, 300));

$pW = '';
for ($i = 0; $i < 60 && !(str_contains($pW, '[dot]') && str_contains($pW, '[pastor_dale]')); $i++) {
    $seenW = null;
    xeric_sweep_run($TCOMP, fresh_db('story-walled' . $i), stub_event(null, $seenW), $SNOW,
        ['stories' => [$S], 'chance' => 1.0, 'seed' => 1200 + $i]);
    $pW = (string)($seenW[1]['content'] ?? '');
}
ok('herring: and in a room with the man she is wrong about standing in it, she says nothing — the wall wins',
    str_contains($pW, '[dot]') && str_contains($pW, '[pastor_dale]') && !str_contains($pW, 'Reds cap'),
    mb_substr($pW, 0, 300));

$pC = '';
for ($i = 0; $i < 60 && !(str_contains($pC, '[dot]') && !str_contains($pC, '[pastor_dale]')); $i++) {
    $d = fresh_db('story-collapsed' . $i);
    story_at($d, $S, 5, $SEP);                      // through the beat that kills the Reds cap
    $seenC = null;
    xeric_sweep_run(xeric_story_compose($TS, [$S], $d), $d, stub_event(null, $seenC),
        xeric_world_now($TS, $SEP + 3600), ['stories' => [$S], 'chance' => 1.0, 'seed' => 800 + $i]);
    $pC = (string)($seenC[1]['content'] ?? '');
}
ok('herring: and the moment the beat that kills it has been told, she stops saying it',
    str_contains($pC, '[dot]') && !str_contains($pC, 'Reds cap'), mb_substr($pC, 0, 300));

// -- the twelve-year-old, who is load-bearing -------------------------------

$pT = '';
for ($i = 0; $i < 60 && !str_contains($pT, 'You do not bring this up'); $i++) {
    $seenT = null;
    xeric_sweep_run($TCOMP, fresh_db('story-theo' . $i), stub_event(null, $seenT), $SNOW,
        ['stories' => [$S], 'chance' => 1.0, 'seed' => 700 + $i]);
    $pT = (string)($seenT[1]['content'] ?? '');
}
ok('floor: the child holding the piece is in the hour like anybody else, carrying what he carries',
    str_contains($pT, '[theo]') && str_contains($pT, 'You do not bring this up')
    && str_contains($pT, 'is a child') && str_contains($pT, 'Nothing in this hour is sexual'),
    mb_substr($pT, 0, 400));

// -- and it ends ------------------------------------------------------------

$dbEnd = fresh_db('story-close');
story_at($dbEnd, $S, 6, $SEP);
$res = xeric_story_resolve($S, $dbEnd, 'harlan', $SEP, ['to' => 'harlan']);
ok('close: naming him, once the beats that show it have been told, closes the story',
    !empty($res['closed']), json_encode($res));
ok('close: and the sweep stops pacing to it — nothing live, and this world\'s own rate is back',
    xeric_sweep_stories($TS, $dbEnd, ['stories' => [$S]]) === []
    && xeric_story_chance($TS, [$S], $dbEnd, $SEP) === $WC);

$rEnd = xeric_sweep_run($TS, $dbEnd, stub_event(), xeric_world_now($TS, $SEP + 7200),
    ['stories' => [$S], 'chance' => 1.0, 'seed' => 21]);
ok('close: AND THE WORLD KEEPS RUNNING — the next hour is an ordinary hour with the same people in it',
    count($rEnd['events']) === 1 && ($rEnd['events'][0]['story'] ?? null) === null
    && !isset($rEnd['events'][0]['trail']['story'])
    && count($rEnd['events'][0]['participants']) >= 2, json_encode($rEnd['notes']));

// A possession story is the one shape nobody has to SAY anything to finish, so
// the sweep is what stands there and notices.
$SP = $S;
$SP['key']        = 'the_amulet';
$SP['title']      = 'The Amulet';
$SP['resolution'] = ['kind' => 'possession', 'boon' => 'the_amulet',
                     'requires_beats' => ['the_word_gets_around'], 'never' => ['mystery.rumor']];

$dbPos = fresh_db('story-possession');
story_at($dbPos, $SP, 1, $SEP);
xeric_arc_set($dbPos, 'ruth', 'boon.the_amulet', 0);
$rPos = xeric_sweep_run($TS, $dbPos, stub_event(), $SNOW, ['stories' => [$SP], 'chance' => 1.0, 'seed' => 31]);
ok('close: a possession story is settled by the world, after the window, the moment the thing is in a hand',
    !xeric_story_state($SP, $dbPos)['live'] && count($rPos['events']) === 1
    && str_contains(implode(' ', $rPos['notes']), 'is over'), json_encode($rPos['notes']));

// -- who reaches for the phone ---------------------------------------------

$dbCarry = fresh_db('story-carry');
xeric_story_spill($S, $dbCarry, 'the_word_gets_around', $SEP);
xeric_story_open($S, $dbCarry, 'the_chair', $SEP);
$carry = xeric_proactive_carrying($dbCarry, [$S]);
ok('proactive: a piece somebody is holding and a lead somebody still believes both put them nearer the phone',
    in_array('theo', $carry, true) && in_array('dot', $carry, true) && in_array('ruth', $carry, true)
    && !in_array('harlan', $carry, true), json_encode($carry));
ok('proactive: and a world with no story hands the phone to nobody in particular',
    xeric_proactive_carrying($dbCarry, []) === []);

$dbPing  = fresh_db('story-ping');
xeric_state_seed($dbPing, $TCOMP);
$rPing   = xeric_sweep_run($TCOMP, $dbPing, stub_event(), $SNOW, ['stories' => [$S], 'chance' => 1.0, 'seed' => 77]);
$notesPing = null;
$ping    = xeric_proactive_check($TCOMP, $dbPing, stub_says_text('the urn again, honestly'), $SNOW,
    ['events' => $rPing['events'], 'stories' => [$S], 'chance' => 1.0, 'involves_user' => true], $notesPing);
ok('proactive: a live overlay does not stop the phone from ringing',
    $ping !== null && ($ping['text'] ?? '') !== '', json_encode($notesPing));

// ---------------------------------------------------------------------------
// THE NARRATOR'S HAND on the two ambient dials — the sky and the mood. Both
// are ordinarily the world's own business, and both are exactly what a
// storyteller reaches for: rain on the day of the funeral is the oldest tool
// there is.
// ---------------------------------------------------------------------------

echo "\n# the narrator's hand\n";

$nhT  = world(['danger', 'shared_meals']);
$nhDb = fresh_db('narrator-hand');
xeric_state_seed($nhDb, $nhT);
$nhNow = xeric_world_now($nhT, ep('2026-08-02 10:00'));
$nhTom = xeric_world_now($nhT, ep('2026-08-03 10:00'));

$nhDerived = xeric_weather_line($nhT, $nhNow);
xeric_weather_set($nhDb, $nhNow, 'Rain since before anybody was up, and it has not let go.');
ok('sky: what the narrator says beats what the world would have said',
    xeric_weather_line($nhT, $nhNow, $nhDb) === 'Rain since before anybody was up, and it has not let go.');
ok('sky: and every reader sees the same told day — a prompt cannot disagree with the panel',
    str_contains(xeric_prompt_now_block($nhT, 'ruth', $nhNow, '', null, null, null, 0, $nhDb),
        'Rain since before anybody'));
ok('sky: TOMORROW derives again by itself — the narrator sets a day, not a climate',
    xeric_weather_line($nhT, $nhTom, $nhDb) === xeric_weather_line($nhT, $nhTom));
xeric_weather_set($nhDb, $nhNow, '');
ok('sky: and giving it back restores the world\'s own',
    xeric_weather_line($nhT, $nhNow, $nhDb) === $nhDerived);
ok('sky: a caller with no database still gets the world\'s own, as it always did',
    xeric_weather_line($nhT, $nhNow) === $nhDerived);

// The schema's own invariant, finally built: pushes harder when ordinary
// than extreme, so nobody ratchets a town to its stop by asking twice.
$nhFirst = xeric_mood_hand($nhDb, $nhT, 2) - xeric_mood_ordinary($nhT);
$nhPrev  = xeric_mood_now($nhDb, $nhT);
for ($i = 0; $i < 4; $i++) { $nhStep = xeric_mood_hand($nhDb, $nhT, 2) - $nhPrev; $nhPrev = xeric_mood_now($nhDb, $nhT); }
ok('mood: the hand pushes harder on a quiet town than on a wound-up one',
    $nhFirst > $nhStep, $nhFirst . ' then ' . $nhStep);
ok('mood: it is capped — one push is never a whole range',
    xeric_mood_hand($nhDb, $nhT, 99) - $nhPrev <= (int)($nhT['world_mood']['narrator_hand']['cap'] ?? 2));
for ($i = 0; $i < 40; $i++) xeric_mood_hand($nhDb, $nhT, 2);
ok('mood: and it still cannot leave the world\'s own range',
    xeric_mood_now($nhDb, $nhT) <= xeric_mood_range($nhT)[1]);
$nhOff = $nhT;
$nhOff['world_mood']['narrator_hand']['enabled'] = false;
$nhWas = xeric_mood_now($nhDb, $nhOff);
ok('mood: a world that switched the hand off does not have one',
    xeric_mood_hand($nhDb, $nhOff, -2) === $nhWas);

// ---------------------------------------------------------------------------
// THE LEDGERS. `economies` is the most completely specified dead thing in the
// schema — earned_by, a board, a podium, ground truth, rules, framing — and
// nothing ever wrote a counter, so every board in every world has been empty
// since boards existed. The hour's own words earn the point now.
// ---------------------------------------------------------------------------

echo "\n# the ledgers\n";

$lgT  = world(['gossip', 'shared_meals']);
$lgDb = fresh_db('ledger');
xeric_state_seed($lgDb, $lgT);

ok('ledger: an hour that did the thing the world named earns the point',
    xeric_ledger_credits($lgT, 'A dish delivered to the porch and nobody said whose') === ['casserole_ledger']);
ok('ledger: and the same act in a different tense still earns it',
    xeric_ledger_credits($lgT, 'She was delivering a dish when the rain started') === ['casserole_ledger']);
ok('ledger: an ordinary hour earns nothing',
    xeric_ledger_credits($lgT, 'The urn ran out early and the chairs went back wrong') === []);
ok('ledger: HALF the phrase is not the act — a kitchen is not a delivery',
    xeric_ledger_credits($lgT, 'A dish left on the counter all afternoon') === []);
// The rule that keeps the poker pot honest: `hand_won` reduces to one short
// word, and the still-life rule puts hands in nearly every hour written.
ok('ledger: a vague earned_by earns nothing rather than everything',
    xeric_ledger_credits($lgT, 'hands on the counter, a hand of cards, the coffee poured') === []
    && xeric_ledger_credits($lgT, 'Harlan won the hand and bought the coffee Sunday') === []);

xeric_ledger_step($lgDb, $lgT, ['ruth', 'dot'], 'Ruth shoveled the driveway before anybody was up');
ok('ledger: everybody who was in the hour is credited',
    xeric_ledger_of($lgDb, 'casserole_ledger', 'ruth') === 1
    && xeric_ledger_of($lgDb, 'casserole_ledger', 'dot') === 1);
ok('ledger: and nobody who was not',
    xeric_ledger_of($lgDb, 'casserole_ledger', 'harlan') === 0);
xeric_ledger_step($lgDb, $lgT, ['nobody_here'], 'A dish delivered to the porch');
ok('ledger: a handle nobody answers to earns nothing',
    xeric_ledger_of($lgDb, 'casserole_ledger', 'nobody_here') === 0);

for ($i = 0; $i < 3; $i++) xeric_ledger_step($lgDb, $lgT, ['dot'], 'A dish delivered again');
$lgBoard = xeric_ledger_board($lgDb, $lgT, 'casserole_ledger');
ok('ledger: the board reads highest first',
    ($lgBoard[0]['handle'] ?? '') === 'dot' && ($lgBoard[0]['n'] ?? 0) === 4);
ok('ledger: and the podium takes the top of it',
    count(xeric_ledger_board($lgDb, $lgT, 'casserole_ledger', 1)) === 1);

// The reader that has been waiting for this since boards existed.
// Its board is CAST-ordered and keeps the zeroes (the seed gives everybody a
// row), where xeric_ledger_board() sorts and drops them — two readers, two
// jobs. What matters is that the number arrives.
$lgSeen = xeric_state_counters($lgDb, $lgT, 'dot');
$lgRow  = null;
foreach ((array)($lgSeen['counters']['casserole_ledger']['board'] ?? []) as $r) {
    if ($r['handle'] === 'dot') $lgRow = $r;
}
ok('ledger: xeric_state_counters finally has something to read',
    ($lgSeen['counters']['casserole_ledger']['viewer_count'] ?? null) === 4
    && ($lgRow['n'] ?? 0) === 4, json_encode($lgSeen['counters']['casserole_ledger'] ?? null));

// AND THE HOURS THEMSELVES EARN IT: the wiring, not the function.
$lgLive = fresh_db('ledger-live');
xeric_state_seed($lgLive, $lgT);
$lgStub = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) {
    $h = stub_handles($msgs);
    $mem = [];
    foreach ($h as $x) $mem[$x] = ucfirst(str_replace('_', ' ', $x)) . ' ' . stub_half() . '.';
    return ['title' => 'a dish delivered', 'memories' => $mem,
            'prose' => 'Somebody delivered a dish to the porch rail and left before anybody could say so.'];
}];
$lgRun = xeric_sweep_run($lgT, $lgLive, $lgStub, xeric_world_now($lgT, ep('2026-07-30 18:00')),
    ['chance' => 1.0, 'seed' => 21]);
$lgAny = 0;
foreach ((array)($lgRun['events'][0]['participants'] ?? []) as $h) {
    $lgAny += xeric_ledger_of($lgLive, 'casserole_ledger', (string)$h);
}
ok('ledger: an hour the world actually lived puts somebody on the board',
    $lgRun['events'] !== [] && $lgAny > 0, json_encode($lgRun['notes']));

// ---------------------------------------------------------------------------
// THE NEEDLE. `world_mood` has been in the schema since the beginning — an
// axis in the world's own words, a range, motifs, drivers, a reversion rule —
// and nothing ever wrote the number, so narrator.php printed "the needle sits
// at 0" in every world that has ever existed. Now the hours move it.
// ---------------------------------------------------------------------------

echo "\n# the town's needle\n";

$mdT  = world(['danger', 'shared_meals']);
$mdDb = fresh_db('mood');
xeric_state_seed($mdDb, $mdT);

ok('mood: a fresh world sits at its own ordinary',
    xeric_mood_now($mdDb, $mdT) === xeric_mood_ordinary($mdT));
ok('mood: the world\'s OWN drivers win over the engine\'s reading of a kind',
    xeric_mood_delta($mdT, 'funeral') === 3 && xeric_mood_delta($mdT, 'potluck') === -2);
ok('mood: and a kind nobody declared falls back to what the kind IS',
    xeric_mood_delta($mdT, 'mishap') > 0 && xeric_mood_delta($mdT, 'shared_meal') < 0
    && xeric_mood_delta($mdT, 'routine') === 0);
ok('mood: a kind nothing has an opinion about moves nothing',
    xeric_mood_delta($mdT, 'no_such_kind') === 0);

// Tension raises it, warmth lowers it, and the drift home is what keeps the
// number meaning "lately" rather than "ever".
for ($i = 0; $i < 6; $i++) xeric_mood_step($mdDb, $mdT, 'mishap');
$mdHot = xeric_mood_now($mdDb, $mdT);
ok('mood: a run of bad hours pushes the needle off ordinary', $mdHot > xeric_mood_ordinary($mdT), (string)$mdHot);
ok('mood: and it never leaves the world\'s own range',
    $mdHot <= xeric_mood_range($mdT)[1]);
for ($i = 0; $i < 30; $i++) xeric_mood_step($mdDb, $mdT, 'routine');
ok('mood: quiet hours bring it home — mean-toward-ordinary, and it STOPS there',
    xeric_mood_now($mdDb, $mdT) === xeric_mood_ordinary($mdT), (string)xeric_mood_now($mdDb, $mdT));

// The reading is in the world's words, not a number with a label on it.
for ($i = 0; $i < 8; $i++) xeric_mood_step($mdDb, $mdT, 'loss');
$mdRead = xeric_mood_read($mdDb, $mdT);
ok('mood: the reading speaks the world\'s own axis, and carries a motif',
    ($mdRead['side'] ?? '') === 'positive'
    && str_contains((string)$mdRead['word'], 'reckless')
    && (string)($mdRead['motif'] ?? '') !== '', json_encode($mdRead));
$mdNone = $mdT;
unset($mdNone['world_mood']['axis']);
ok('mood: a world with no vocabulary for its mood is shown no mood',
    xeric_mood_read($mdDb, $mdNone) === []);

// AND THE HOURS THEMSELVES MOVE IT — the assertion that the wiring is real
// rather than a function nobody calls, which is what this whole entry was.
$mdLive = fresh_db('mood-live');
xeric_state_seed($mdLive, $mdT);
$mdBefore = xeric_mood_now($mdLive, $mdT);
$mdMoved = false;
for ($i = 0; $i < 12 && !$mdMoved; $i++) {
    xeric_sweep_run($mdT, $mdLive, stub_event(), xeric_world_now($mdT, ep('2026-07-30 20:00') + $i * 3600),
        ['chance' => 1.0, 'seed' => 400 + $i]);
    $mdMoved = xeric_mood_now($mdLive, $mdT) !== $mdBefore;
}
ok('mood: an hour the world actually lived moves the needle', $mdMoved,
    'before ' . $mdBefore . ', after ' . xeric_mood_now($mdLive, $mdT));

// ---------------------------------------------------------------------------
// What a PLAYER may be told about a story over their world, and taking one
// back. The digest is the thin view on purpose: a UI that could print the
// culprit would be the overlay engine defeating itself in the settings panel.
// ---------------------------------------------------------------------------

echo "\n# the stories, as a player sees them\n";

$dgS  = xeric_story_read(STORY);
$dgT  = world(['gossip', 'danger']);
$dgDb = fresh_db('digest');
xeric_state_seed($dgDb, $dgT);

$dg = xeric_story_digest([$dgS], $dgDb);
ok('digest: one row per story, with the logline it is allowed to show',
    count($dg) === 1 && $dg[0]['key'] === xeric_story_key($dgS)
    && $dg[0]['logline'] === trim((string)$dgS['logline']));
ok('digest: progress is a fraction, and a fresh story has found nothing',
    $dg[0]['opened'] === 0 && $dg[0]['total'] === count($dgS['beats']) && $dg[0]['live'] === true);

// THE LEAK TEST. Everything the schema calls secret, checked against the whole
// serialised digest — the truth, the culprit's handle, and every beat's own
// text. If any of it can reach a settings panel, the panel is the leak.
$dgJson = json_encode($dg, JSON_UNESCAPED_UNICODE);
$dgSecrets = [trim((string)$dgS['truth'])];
foreach ((array)$dgS['beats'] as $b) {
    foreach (['piece', 'while_locked', 'when_open', 'spilled_as'] as $f) {
        if (trim((string)($b[$f] ?? '')) !== '') $dgSecrets[] = trim((string)$b[$f]);
    }
}
$dgLeaked = array_values(array_filter($dgSecrets,
    fn($x) => mb_strlen($x) > 12 && str_contains($dgJson, mb_substr($x, 0, 40))));
ok('digest: not one secret string survives into the player\'s view',
    $dgLeaked === [], implode(' | ', array_slice($dgLeaked, 0, 2)));
ok('digest: and the culprit is not named in it either',
    !str_contains($dgJson, '"' . (string)$dgS['cast']['culprit'] . '"'), $dgJson);

// Taking one back is a subtraction with the residue left out.
$dgBefore = [xeric_events_count($dgDb), xeric_memories_count($dgDb)];
xeric_story_abandon($dgS, $dgDb, (int)$NOW['epoch']);
ok('abandon: the story stops composing, and reads as closed',
    xeric_story_state($dgS, $dgDb)['live'] === false
    && xeric_story_active([$dgS], $dgDb, $dgT) === []);
ok('abandon: and it leaves NO residue — a story taken back never happened',
    xeric_events_count($dgDb) === $dgBefore[0] && xeric_memories_count($dgDb) === $dgBefore[1]);
ok('abandon: a second take-back is a no-op, not a second closing',
    (function () use ($dgS, $dgDb, $NOW) {
        $was = xeric_story_state($dgS, $dgDb)['closed'];
        xeric_story_abandon($dgS, $dgDb, (int)$NOW['epoch'] + 999);
        return xeric_story_state($dgS, $dgDb)['closed'] === $was;
    })());
ok('digest: a closed story still lists — finishing one should not erase it',
    (($dg2 = xeric_story_digest([$dgS], $dgDb))[0]['live'] ?? true) === false && count($dg2) === 1);

// And the difference that matters: RESOLVING writes the residue, abandoning
// does not. Same subtraction, two different endings.
$dgDb2 = fresh_db('digest-resolve');
xeric_state_seed($dgDb2, $dgT);
xeric_story_close($dgS, $dgDb2, (int)$NOW['epoch']);
ok('close: a story that ENDED leaves the town remembering it',
    xeric_memories_count($dgDb2) > 0 || xeric_events_count($dgDb2) > 0);

// ---------------------------------------------------------------------------
// A WORLD BEFORE 1970 — the epoch is negative and every hour of it is real.
//
// ROADMAP.md advertises "starts": "1873-06-04 07:40" under "Already works",
// and it did — for chat. The offscreen life used `$epoch <= 0` as the sentinel
// for "no moment passed", so an 1873 world's sweeps, duets, rooms and pings
// all threw forever while the world looked alive. Absence is the sentinel now,
// and the window arithmetic floors instead of truncating toward zero, so the
// hour walked and the guard written agree below zero too.
// ---------------------------------------------------------------------------

echo "\n# a world before 1970\n";

// The helper first, at the exact seam intdiv gets wrong: epoch −100 lives in
// window −1, and truncation said 0. Boundaries must be half-open both sides
// of zero: −3600 opens window −1, 0 opens window 0.
ok('pre-1970: floor division disagrees with intdiv exactly where it must',
    xeric_sweep_windex(-100, 3600) === -1 && intdiv(-100, 3600) === 0
    && xeric_sweep_windex(-3600, 3600) === -1 && xeric_sweep_windex(-3601, 3600) === -2
    && xeric_sweep_windex(0, 3600) === 0 && xeric_sweep_windex(3599, 3600) === 0);

$T1873 = world(['gossip', 'meals'], [], null,
    ['setting.starts' => '1873-06-04 07:40']);
$NOW73 = xeric_world_now($T1873, ep('1873-06-04 10:00'));
ok('pre-1970: xeric_world_now builds a real 1873 morning from a negative epoch',
    (int)$NOW73['epoch'] < 0 && $NOW73['phase'] === 'morning', json_encode($NOW73));

$db73 = fresh_db('pre1970');
xeric_state_seed($db73, $T1873);
$r73 = xeric_sweep_run($T1873, $db73, stub_event(), $NOW73, ['chance' => 1.0, 'seed' => 11]);
ok('pre-1970: the world lives an hour instead of throwing',
    ($r73['events'] ?? []) !== [], json_encode($r73['notes'] ?? []));
ok('pre-1970: and the event is stamped in 1873, not clamped to the epoch floor',
    (int)($r73['events'][0]['world_epoch'] ?? 0) < 0);

// The guard and a second pass: one hour happens once, below zero as above it.
$r73b = xeric_sweep_run($T1873, $db73, stub_event(), $NOW73, ['chance' => 1.0, 'seed' => 11]);
ok('pre-1970: the window guard holds — the same 1873 hour does not happen twice',
    ($r73b['events'] ?? []) === []);

// And a catchup stretch, with the clock clamp live: nothing may be stamped
// past the moment the world stands in, negative or not.
$r73c = xeric_sweep_catchup($T1873, $db73, stub_event(),
    ep('1873-06-04 11:00'), ep('1873-06-04 15:00'),
    ['chance' => 1.0, 'seed' => 12, 'max_events' => 8]);
$late73 = false;
foreach (($r73c['events'] ?? []) as $e) {
    if ((int)$e['world_epoch'] > ep('1873-06-04 15:00')) $late73 = true;
}
ok('pre-1970: a catchup stretch sweeps and stamps nothing past the clock',
    ($r73c['events'] ?? []) !== [] && !$late73,
    json_encode(array_map(fn($e) => $e['world_epoch'], $r73c['events'] ?? [])));

// ---------------------------------------------------------------------------
// THE WATERMARK AFTER AN OUTAGE — hours nobody lived stay owed.
//
// Giving up on a dead model used to stamp the watermark at the far end of the
// stretch anyway, so an outage longer than the miss allowance permanently
// ended a world's offscreen life: every later catchup computed first > last
// and returned "already been lived" without one model call. The mark now stops
// at the last window before the failing streak.
// ---------------------------------------------------------------------------

echo "\n# the watermark after an outage\n";

$TWM  = world(['gossip', 'meals']);
$dbWM = fresh_db('watermark-outage');
xeric_state_seed($dbWM, $TWM);

// Tick 1: the model is down for the whole stretch. It gives up — and the
// watermark must not have crossed the hours it never lived.
$down = xeric_sweep_catchup($TWM, $dbWM, stub_dead(),
    ep('2026-07-30 08:00'), ep('2026-07-30 14:00'), ['chance' => 1.0, 'seed' => 5]);
ok('outage: a dead endpoint gives up instead of grinding the stretch',
    ($down['events'] ?? []) === []
    && str_contains(implode(' ', $down['notes']), 'gave up'), json_encode($down['notes']));

$markAfter = xeric_world_state_get($dbWM, 'sweep_watermark:3600');
ok('outage: the watermark did NOT advance past the hours nobody lived',
    $markAfter === null || (int)$markAfter < xeric_sweep_windex(ep('2026-07-30 08:00'), 3600),
    var_export($markAfter, true));

// Tick 2: the model is back. The same stretch must produce events — this is
// the assertion that failed for six-hours-and-forever before the fix.
$back = xeric_sweep_catchup($TWM, $dbWM, stub_event(),
    ep('2026-07-30 08:00'), ep('2026-07-30 14:00'),
    ['chance' => 1.0, 'seed' => 6, 'max_events' => 8]);
ok('outage: when the model returns, the owed hours are lived after all',
    ($back['events'] ?? []) !== [], json_encode($back['notes']));

// And the deliberate burials stay deliberate: a stretch stopped at max_events
// still buries its tail, because one stretch happens once.
$tail = xeric_sweep_catchup($TWM, $dbWM, stub_event(),
    ep('2026-07-30 08:00'), ep('2026-07-30 14:00'), ['chance' => 1.0, 'seed' => 7]);
ok('outage: but a stretch that ENDED cleanly stays lived — skip twice, mine once',
    ($tail['events'] ?? []) === []
    && str_contains(implode(' ', $tail['notes']), 'already been lived'), json_encode($tail['notes']));

// ---------------------------------------------------------------------------
// SHAPES — a world's own rhythm, with or without a story on it.
//
// The load-bearing claim of the whole feature is arithmetic: intensity 0.5 is
// ×1.0 EXACTLY, so refusing the plot snake is not a bypass anywhere in the
// engine, it is a curve held flat. If that stops being exact, "none" quietly
// starts changing the rate of every world that asked for nothing.
// ---------------------------------------------------------------------------

echo "\n# shapes\n";

$SHAPES = xeric_story_shapes();
ok('shape: the library offers a refusal and at least four rhythms',
    isset($SHAPES['none']) && count($SHAPES) >= 5, implode(',', array_keys($SHAPES)));

// Every shape must be a legal SNAKE, because a story overlay inherits one.
$illegal = [];
foreach ($SHAPES as $k => $s) {
    $bad = xeric_story_shape_check($s);
    if ($bad !== []) $illegal[] = "$k: " . implode('; ', $bad);
}
ok('shape: every shape in the library passes the shape checker', $illegal === [], implode(' | ', $illegal));

// And passes the OVERLAY validator, which is the stricter of the two readers —
// as an INHERITED snake, which is how a shape actually reaches a story. Any
// shape must be droppable onto any overlay, because the person choosing their
// xeric's rhythm has never seen the beats of a story they might inject later.
$SFIX = xeric_story_read(STORY);
$TSH  = world(['gossip', 'danger']);
$rejected = [];
foreach ($SHAPES as $k => $s) {
    $probe = $SFIX;
    $probe['snake'] = $s + ['inherited' => true];
    $e = err(fn() => xeric_story_validate($probe, $TSH, "shape-$k"));
    if ($e !== '') $rejected[] = "$k: $e";
}
ok('shape: every shape is legal as an inherited snake, whatever the story\'s beats',
    $rejected === [], implode(' | ', $rejected));

// …and the authoring check still earns its keep on a snake somebody WROTE. The
// rule is "you put a beat in your own quiet stretch", and against a declared
// curve that is exactly the mistake worth refusing.
$declared = $SFIX;
$declared['snake'] = $SHAPES['turn'];                       // its calm sits on milldale's beats
ok('shape: a DECLARED snake whose calm swallows a beat is still refused',
    err(fn() => xeric_story_validate($declared, $TSH, 'declared')) !== '');

// THE ×1.0 CLAIM. Not "about one" — exactly one, at every point on the curve.
$flatOff = [];
foreach ([0.0, 0.01, 0.25, 0.5, 0.5001, 0.75, 0.99, 1.0] as $p) {
    $m = xeric_story_snake($SHAPES['none'], $p)['m'];
    if ($m !== 1.0) $flatOff[] = "p=$p gave $m";
}
ok('shape: `none` is ×1.0 EXACTLY at every progress — refusing the snake is not a bypass',
    $flatOff === [], implode(', ', $flatOff));
ok('shape: and `none` reads as one endless false calm, which is its true name',
    xeric_story_snake($SHAPES['none'], 0.4)['stage'] === 'false_calm');

// -- ambient: a world walking its own curve on the calendar ------------------
$dbSh = fresh_db('shape');
$TSH0 = world(['gossip', 'danger']);                       // no story_shape declared at all
xeric_state_seed($dbSh, $TSH0);
$shSeed = (int)(xeric_world_state_get($dbSh, 'seeded_at') ?? 0);
if ($shSeed <= 0) { xeric_world_state_set($dbSh, 'seeded_at', (string)1785000000); $shSeed = 1785000000; }
$shBase = (float)($TSH0['events']['sweep_chance'] ?? XERIC_SWEEP_CHANCE);

ok('shape: a world that declared none has no ambient reading at all',
    xeric_story_ambient($TSH0, $dbSh, $shSeed + 5 * 86400) === []);
$driftless = true;
foreach ([0, 1, 9, 40, 400] as $d) {
    if (xeric_story_ambient_chance($TSH0, $dbSh, $shSeed + $d * 86400) !== $shBase) $driftless = false;
}
ok('shape: and its rate is its own declared number, unchanged, forever', $driftless,
    'base ' . $shBase);

$TSHs = $TSH0;
$TSHs['events']['story_shape'] = 'snake';
$moved = [];
foreach ([0, 3, 7, 14, 21, 26] as $d) {
    $moved[] = round(xeric_story_ambient_chance($TSHs, $dbSh, $shSeed + $d * 86400), 3);
}
ok('shape: a shaped world\'s rate moves across its cycle', count(array_unique($moved)) >= 4,
    implode(' ', $moved));
ok('shape: and it never leaves the clamp, however loud the curve gets',
    min($moved) >= 0.05 && max($moved) <= 0.9, implode(' ', $moved));

// The cycle WRAPS. A world is not over when its rhythm finishes.
$cyc = (int)$SHAPES['snake']['cycle_days'];
ok('shape: the cycle wraps — day 0 and day ' . $cyc . ' read identically',
    xeric_story_ambient($TSHs, $dbSh, $shSeed)['p']
        === xeric_story_ambient($TSHs, $dbSh, $shSeed + $cyc * 86400)['p']);

// The thumb obeys the rule a story's does: re-weights the armed, arms nothing.
$armedKinds = xeric_sweep_kinds_for($TSHs, $dbSh);
$thumbed    = xeric_story_ambient_thumb($armedKinds, $TSHs, $dbSh, $shSeed + 26 * 86400);
ok('shape: the ambient thumb never arms a kind and never deletes one',
    array_keys($thumbed) === array_keys($armedKinds));
$positive = true;
foreach ($thumbed as $k => $v) if ((float)($v['weight'] ?? 1.0) <= 0.0) $positive = false;
ok('shape: and every weight it leaves behind is positive', $positive);

// -- the world's shape falls through to its stories --------------------------
$TSHi = $TSH0;
$TSHi['events']['story_shape'] = 'tidal';
$storyDir = sys_get_temp_dir() . '/xeric-shape-story-' . getmypid();
@mkdir($storyDir, 0775, true);
$noSnake = $SFIX;
unset($noSnake['snake']);
file_put_contents($storyDir . '/story-inherit.json', json_encode($noSnake));
$inherited = xeric_story_for($storyDir, $TSHi);
ok('shape: an overlay that declares no snake inherits the world\'s',
    count($inherited) === 1
    && ($inherited[0]['snake']['false_calm'] ?? []) === $SHAPES['tidal']['false_calm'],
    json_encode($inherited[0]['snake']['false_calm'] ?? null));

// …and one that brought its own keeps it. A mystery written to a rhythm is not
// something a world setting gets to overrule.
file_put_contents($storyDir . '/story-own.json', json_encode($SFIX));
@unlink($storyDir . '/story-inherit.json');
$kept = xeric_story_for($storyDir, $TSHi);
ok('shape: an overlay that brought its own snake keeps it',
    count($kept) === 1 && ($kept[0]['snake']['false_calm'] ?? []) === ($SFIX['snake']['false_calm'] ?? []),
    json_encode($kept[0]['snake']['false_calm'] ?? null));
@unlink($storyDir . '/story-own.json');
@rmdir($storyDir);

// -- the invented shape cannot be illegal, whatever came back ----------------
// The builder is the disposal half of "model proposes, code disposes", so the
// property under test is that NO input produces a shape the engine would refuse.
$hostile = [
    'empty'        => [],
    'inverted'     => ['calm_from' => 0.9, 'calm_to' => 0.1, 'rise_at' => 0.95, 'peak_at' => 0.02],
    'out of range' => ['open_at' => 9e9, 'peak' => -40, 'swing' => 7.5, 'cycle_days' => 99999],
    'wrong types'  => ['calm_from' => 'yes', 'peak_at' => [], 'cycle_days' => 'lots', 'label' => 12],
    'collapsed'    => ['calm_from' => 0.5, 'calm_to' => 0.5, 'rise_at' => 0.5, 'peak_at' => 0.5],
    'bad thumb'    => ['kind_thumb' => ['nonsense' => ['rumor' => 2], 'crescendo' => ['nope' => 3, 'rumor' => -1]]],
];
$builtBad = [];
foreach ($hostile as $name => $spec) {
    $built = xeric_forge_shape_build($spec);
    $bad   = xeric_story_shape_check($built);
    if ($bad !== []) $builtBad[] = "$name: " . implode('; ', $bad);
    $probe = $SFIX;
    $probe['snake'] = $built;
    $e = err(fn() => xeric_story_validate($probe, $TSH, 'invented'));
    if ($e !== '') $builtBad[] = "$name overlay: $e";
}
ok('shape: no model output, however wrong, builds an illegal shape', $builtBad === [],
    implode(' | ', $builtBad));
ok('shape: and the builder drops thumbs on unknown stages, unknown kinds and non-positive multipliers',
    xeric_forge_shape_build($hostile['bad thumb'])['kind_thumb'] === ['crescendo' => ['rumor' => 2.0]]
    || xeric_forge_shape_build($hostile['bad thumb'])['kind_thumb'] === [],
    json_encode(xeric_forge_shape_build($hostile['bad thumb'])['kind_thumb']));

// -- and the inspector can tell a rhythm from a plot -------------------------
$trailNone = xeric_sweep_shape_trail($TSH0, $dbSh, $shSeed + 3 * 86400, $shBase, $shBase, [], []);
ok('shape: a shapeless world writes no trail rather than a row saying nothing happened',
    $trailNone === []);
$trailShaped = xeric_sweep_shape_trail($TSHs, $dbSh, $shSeed + 26 * 86400, $shBase, 0.5, [], []);
ok('shape: a shaped world\'s trail names the SHAPE, never a story that does not exist',
    ($trailShaped['live'][0]['key'] ?? '') === 'world:snake'
    && $trailShaped['beat'] === null
    && str_contains((string)($trailShaped['live'][0]['beats'] ?? ''), 'no beats'),
    json_encode($trailShaped['live'][0] ?? null));

// ---------------------------------------------------------------------------

$dbSh = null;
$db = $db2 = $dbG = $dbC = $dbF = $dbS = $dbCU = $dbPR = $dbN = $dbB = $dbB2 = $dbC2 = $dbC3 = $dbQ2 = $dbR = $dbCD = $dbX = $dbL = null;
$dbEdge = $dbHour = $dbSpine = $dbOrd = $dbDefer = $dbKill = $dbSex = null;
$dA = $dW = $dM = $dS = $dL = $dL2 = $dOne = $dEv = $dNag = $dCap = $dSec = $dKid = null;
$hour = $hourX = null;
gc_collect_cycles();
foreach ($DBFILES as $p2) foreach ([$p2, $p2 . '-wal', $p2 . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
