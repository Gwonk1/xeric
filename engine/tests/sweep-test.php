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

$db = $db2 = $dbG = $dbC = $dbF = $dbS = $dbCU = $dbPR = $dbN = $dbB = $dbB2 = $dbC2 = $dbC3 = $dbQ2 = $dbR = $dbCD = $dbX = $dbL = null;
$dbEdge = $dbHour = $dbSpine = $dbOrd = $dbDefer = $dbKill = $dbSex = null;
$hour = $hourX = null;
gc_collect_cycles();
foreach ($DBFILES as $p2) foreach ([$p2, $p2 . '-wal', $p2 . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
