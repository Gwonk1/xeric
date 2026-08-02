<?php
/**
 * Xeric — engine core tests. `php engine/tests/engine-test.php`, exit 0 on pass.
 *
 * Same shape as render-test.php: one line per check, negative assertions where
 * the failure would be invisible. Three things here are load-bearing and get
 * tested by POSITION, not by presence:
 *
 *   - validation messages must name the offending path, because a template
 *     author reading "invalid template" learns nothing;
 *   - the bible must be in the SYSTEM message and the clock must be in the LAST
 *     USER message, because that split is what makes local inference affordable;
 *   - a walled character's prompt must omit exactly what her bible omits — the
 *     prompt is a second surface for the same leak.
 */

declare(strict_types=1);

require_once __DIR__ . '/../world.php';
require_once __DIR__ . '/../state.php';
require_once __DIR__ . '/../prompt.php';
require_once __DIR__ . '/../story.php';
require_once __DIR__ . '/../enter.php';

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

/** A milldale clone with one path replaced, for the malformed-template table. */
function mutate(array $t, array $path, $value): array
{
    $ref = &$t;
    foreach ($path as $step) {
        if (!isset($ref[$step]) && !array_key_exists($step, $ref)) $ref[$step] = [];
        $ref = &$ref[$step];
    }
    $ref = $value;
    unset($ref);
    return $t;
}

function drop(array $t, string $key): array
{
    unset($t[$key]);
    return $t;
}

/** A fixed local epoch, so every clock assertion below is reproducible. */
function ep(string $when, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();
}

function leaks(string $hay, array $needles): array
{
    $found = [];
    foreach ($needles as $n) if (stripos($hay, $n) !== false) $found[] = $n;
    return $found;
}

const FIXTURE = __DIR__ . '/../fixtures/milldale.json';

// Other people's private business. None of it may reach the walled daughter.
const OTHERS_SECRETS = [
    'five-card draw',
    'green spiral notebook',
    "comping Harlan's lunch",
    "father's till key",
    'The pot never leaves the basement',
    'standing seat at the table',
    'being thanked in front of people',      // Ruth's sore spot
    'reorganizing a bin that did not need it',
];

// ---------------------------------------------------------------------------
// 1. Loading and validation
// ---------------------------------------------------------------------------

$T = xeric_world_load(FIXTURE);
ok('world: the fixture loads and validates', isset($T['cast']['characters'][0]['handle']));
ok('world: load rejects a missing file', str_contains(err(fn() => xeric_world_load('/nonexistent/nope.json')), 'cannot read template'));

$badJson = tempnam(sys_get_temp_dir(), 'xeric') . '.json';
file_put_contents($badJson, "{ not json, ");
ok('world: load rejects bad JSON, naming the file', (function () use ($badJson) {
    $m = err(fn() => xeric_world_load($badJson));
    return str_contains($m, 'bad JSON') && str_contains($m, basename($badJson));
})());
@unlink($badJson);

$cases = [
    // [name, mutated template, substring the message must contain]
    ['a missing required top-level key', drop($T, 'user'), 'user is a required top-level key'],
    ['a character in an undeclared orbit',
        mutate($T, ['cast', 'characters', 2, 'orbit'], 'firm'),
        "cast.characters[2].orbit 'firm' is not a declared orbit"],
    ['a wall aimed at a role nobody holds',
        mutate($T, ['knowledge_walls', 0, 'audience'], ['role' => 'spouse']),
        "knowledge_walls[0].audience.role 'spouse' is not a role declared in cast.special_roles"],
    ['a wall aimed at an undeclared orbit',
        mutate($T, ['knowledge_walls', 1, 'audience'], ['orbit' => 'extra']),
        "knowledge_walls[1].audience.orbit 'extra' is not a declared orbit"],
    ['a wall aimed at an undeclared circle',
        mutate($T, ['knowledge_walls', 2, 'audience'], ['circle' => 'euchre']),
        "knowledge_walls[2].audience.circle 'euchre' is not a declared circle"],
    ['a wall using a selector the engine does not understand',
        mutate($T, ['knowledge_walls', 0, 'audience'], ['group' => 'anybody']),
        'knowledge_walls[0].audience.group is not a selector the engine understands'],
    ['a special role pointing at a missing character',
        mutate($T, ['cast', 'special_roles', 0, 'character'], 'nobody'),
        "cast.special_roles[0].character 'nobody' is not a declared character"],
    ['a special role naming an undeclared wall',
        mutate($T, ['cast', 'special_roles', 0, 'walls'], ['family_innocence', 'no_such_wall']),
        "cast.special_roles[0].walls[1] 'no_such_wall' is not a declared knowledge wall"],
    ['residents pointing at nobody',
        mutate($T, ['places', 0, 'residents'], ['ghost']),
        "places[0].residents[0] 'ghost' names neither a character nor a fixture"],
    ['same_as pointing at nothing',
        mutate($T, ['cast', 'fixtures', 2, 'same_as'], 'harlon'),
        "cast.fixtures[2].same_as 'harlon' is not a declared character"],
    ['a boon paying into an unknown economy',
        mutate($T, ['boons', 1, 'payout', 'economy'], 'thursday_pit'),
        "boons[1].payout.economy 'thursday_pit' is not a declared economy"],
    ['a board shown to an undeclared orbit',
        mutate($T, ['economies', 0, 'board', 'visible_to'], ['orbit:the_firm']),
        "economies[0].board.visible_to[0] 'the_firm' is not a declared orbit"],
    ['a bad template rating',
        mutate($T, ['meta', 'rating'], 'wide-open'),
        "meta.rating 'wide-open' is not one of sfw|pg|teen|mature|explicit"],
    ['a bad rating_min deep in the tree',
        mutate($T, ['cast', 'characters', 3, 'drives', 'rating_min'], 'nsfw'),
        "cast.characters[3].drives.rating_min 'nsfw' is not one of sfw|pg|teen|mature|explicit"],
    ['a schedule pointing at a place that does not exist',
        mutate($T, ['cast', 'characters', 0, 'week', 0, 'where'], 'the_moon'),
        "cast.characters[0].week[0].where 'the_moon' is not a declared place"],
    ['a circle drawn from an undeclared orbit',
        mutate($T, ['cast', 'circles', 0, 'members_from_orbits'], ['first_lutheran', 'nightshift']),
        "cast.circles[0].members_from_orbits[1] 'nightshift' is not a declared orbit"],
    ['a duplicate character handle',
        mutate($T, ['cast', 'characters', 4, 'handle'], 'ruth'),
        "cast.characters[4].handle 'ruth' is declared twice"],
    ['an unusable timezone',
        mutate($T, ['user', 'timezone'], 'Mars/Olympus'),
        "user.timezone 'Mars/Olympus' is not a timezone PHP knows"],
    ['a character with no orbit at all',
        mutate($T, ['cast', 'characters', 1, 'orbit'], ''),
        'cast.characters[1].orbit is required'],
    ['a wall nobody is handed and nobody matches',
        mutate($T, ['knowledge_walls', 2, 'audience'], null),
        "knowledge_walls[2] 'circle_discretion' has no audience and no special role names it"],
    ['quiet hours written the way a person says them out loud',
        mutate($T, ['user', 'quiet_hours'], '11pm-7am'),
        "user.quiet_hours '11pm-7am' is not an HH:MM-HH:MM range"],
    ['quiet hours pasted back with an en dash in them',
        mutate($T, ['user', 'quiet_hours'], '21:30–06:00'),
        'is not an HH:MM-HH:MM range'],
    ['quiet hours that name an hour no clock has',
        mutate($T, ['user', 'quiet_hours'], '25:00-06:00'),
        'is not an HH:MM-HH:MM range'],

    // Age. The field is required because xeric_is_minor() reads it and nothing
    // else, and answers "minor" for anything it cannot read: a template that
    // forgot it would load as an all-child cast rather than fail.
    ['a character with no age at all',
        (function (array $t) { unset($t['cast']['characters'][3]['age']); return $t; })($T),
        "cast.characters[3].age is required and must be an integer, 'harlan' has null"],
    ['an age the model typed as a string',
        mutate($T, ['cast', 'characters', 0, 'age'], '71'),
        "cast.characters[0].age is required and must be an integer, 'ruth' has \"71\""],
    ['an age given as a float',
        mutate($T, ['cast', 'characters', 2, 'age'], 68.0),
        "cast.characters[2].age is required and must be an integer, 'dot' has 68"],

    // The desire economy, closed to a minor at load time rather than at render
    // time. Everything else about the child is left alone.
    ['a minor armed with a flirt style',
        mutate($T, ['cast', 'characters', 5, 'flirt_style'], 'playful'),
        "cast.characters[5].flirt_style is set on 'theo', who is a minor, minors are not in the desire economy"],
    ['a minor carrying an attraction seed',
        mutate($T, ['cast', 'characters', 5, 'relationships', 'attraction_seeds'], ['dot' => 4]),
        "cast.characters[5].relationships.attraction_seeds is set on 'theo', who is a minor"],
    ['an adult whose attraction seed points at a minor',
        mutate($T, ['cast', 'characters', 3, 'relationships', 'attraction_seeds'], ['theo' => 3]),
        "cast.characters[3].relationships.attraction_seeds.theo aims at 'theo', who is a minor"],
    ['content gated above sfw inside a minor\'s dossier',
        mutate($T, ['cast', 'characters', 5, 'drives', 'rating_min'], 'mature'),
        "cast.characters[5].drives.rating_min gates content above sfw on 'theo', who is a minor"],
];

foreach ($cases as [$name, $bad, $needle]) {
    $msg = err(fn() => xeric_world_validate($bad, 'milldale.json'));
    ok("validate: catches $name", $msg !== '' && str_contains($msg, $needle), $msg === '' ? 'no exception thrown' : $msg);
}
ok('validate: ' . count($cases) . ' distinct malformed templates rejected', count($cases) >= 5);

// The two shapes that look like the failures above and are not.
ok('validate: a wall with no audience is fine when a special role hands it out by name',
    err(fn() => xeric_world_validate(mutate($T, ['knowledge_walls', 0, 'audience'], null), 'milldale.json')) === '');
ok('validate: empty quiet hours are legal — a world is allowed never to sleep',
    err(fn() => xeric_world_validate(mutate($T, ['user', 'quiet_hours'], ''), 'milldale.json')) === '');

// ---------------------------------------------------------------------------
// 2. Resolvers
// ---------------------------------------------------------------------------

ok('world: character resolver finds and misses correctly',
    (xeric_world_character($T, 'ruth')['display_name'] ?? '') === 'Ruth Amberg'
    && xeric_world_character($T, 'ruthie') === null);
ok('world: place resolver finds and misses correctly',
    (xeric_world_place($T, 'bluebird')['name'] ?? '') === 'the Bluebird Diner'
    && xeric_world_place($T, 'the_moon') === null);
ok('world: cast lists everyone in template order', array_map(fn($c) => $c['handle'], xeric_world_cast($T))
    === ['ruth', 'pastor_dale', 'dot', 'harlan', 'janelle', 'theo']);
ok('world: cast filters by orbit', array_map(fn($c) => $c['handle'], xeric_world_cast($T, 'main_street')) === ['dot', 'harlan', 'theo']);
ok('world: cast filter on an unknown orbit is empty, not everybody', xeric_world_cast($T, 'nope') === []);
ok('world: names resolve through the same_as link',
    xeric_world_name($T, 'harlan_counter') === 'Harlan Beck'
    && xeric_world_name($T, 'cy') === 'Cy Loomis'
    && xeric_world_name($T, 'stranger') === 'stranger');
ok('world: effective rating is the floor of template and model',
    xeric_world_rating($T) === 'sfw'
    && xeric_world_rating($T, 'explicit') === 'sfw'
    && xeric_world_rating(mutate($T, ['meta', 'rating'], 'explicit'), 'mature') === 'mature');

// ---------------------------------------------------------------------------
// 3. The clock
// ---------------------------------------------------------------------------

$wed = xeric_world_now($T, ep('2026-07-29 08:15'));
ok('clock: a fixed epoch resolves day-of-week, time and phase',
    $wed['dow'] === 3 && $wed['hhmm'] === '08:15' && $wed['phase'] === 'morning',
    json_encode($wed));
ok('clock: iso carries the template timezone offset, not UTC',
    str_starts_with($wed['iso'], '2026-07-29T08:15:00-04:00') && $wed['tz'] === 'America/New_York', $wed['iso']);
ok('clock: winter epochs get the winter offset (DST is not hardcoded)',
    str_contains(xeric_world_now($T, ep('2026-01-15 12:00'))['iso'], '-05:00'));

$phases = [
    '2026-07-30 03:00' => ['night',     4],   // Thursday
    '2026-07-30 04:59' => ['night',     4],
    '2026-07-30 05:00' => ['morning',   4],
    '2026-07-30 11:59' => ['morning',   4],
    '2026-07-30 12:00' => ['afternoon', 4],
    '2026-07-30 16:59' => ['afternoon', 4],
    '2026-07-30 17:00' => ['evening',   4],
    '2026-07-30 21:59' => ['evening',   4],
    '2026-07-30 22:00' => ['night',     4],
    '2026-08-02 13:00' => ['afternoon', 0],   // Sunday
    '2026-08-01 23:30' => ['night',     6],   // Saturday
];
$phaseOk = true;
$phaseBad = '';
foreach ($phases as $when => [$phase, $dow]) {
    $n = xeric_world_now($T, ep($when));
    if ($n['phase'] !== $phase || $n['dow'] !== $dow) { $phaseOk = false; $phaseBad = "$when → {$n['phase']}/{$n['dow']}"; }
}
ok('clock: every phase boundary and weekday lands where it should', $phaseOk, $phaseBad);

ok('clock: the epoch is injectable — nothing reads the wall clock behind your back',
    xeric_world_now($T, 0)['epoch'] === 0 && xeric_world_now($T, 0)['hhmm'] === '19:00'
    && xeric_world_now($T, 0)['dow'] === 3);
ok('clock: the default epoch is now, within a second',
    abs(xeric_world_now($T)['epoch'] - time()) <= 1);
ok('clock: day names line up with dow', xeric_world_day_name(0) === 'Sunday' && xeric_world_day_name(4) === 'Thursday');

// ---------------------------------------------------------------------------
// 4. Who is where
// ---------------------------------------------------------------------------

$thuEve = xeric_world_now($T, ep('2026-07-30 20:15'));
$wedAm  = xeric_world_now($T, ep('2026-07-29 10:00'));
$thu3am = xeric_world_now($T, ep('2026-07-30 03:00'));

$p1 = xeric_world_who_is_where($T, $thuEve);
ok('presence: the pastor is in the basement on a Thursday night',
    $p1['pastor_dale']['where'] === 'first_lutheran'
    && $p1['pastor_dale']['doing'] === 'the basement, door closed');
ok('presence: everybody off shift is nowhere, not somewhere by default',
    $p1['ruth']['where'] === null && $p1['dot']['where'] === null
    && $p1['harlan']['where'] === null && $p1['janelle']['where'] === null);

$p2 = xeric_world_who_is_where($T, $wedAm);
ok('presence: a Wednesday morning puts four people in three rooms',
    $p2['ruth']['where'] === 'first_lutheran' && $p2['ruth']['doing'] === 'kitchen, quilting circle'
    && $p2['pastor_dale']['where'] === 'first_lutheran'
    && $p2['dot']['where'] === 'bluebird'
    && $p2['harlan']['where'] === 'beck_hardware');
ok('presence: who_is_at reads the room, in cast order',
    xeric_world_who_is_at($p2, 'first_lutheran') === ['ruth', 'pastor_dale']);

$p3 = xeric_world_who_is_where($T, $thu3am);
ok('presence: at three in the morning nobody is anywhere',
    array_values(array_filter(array_map(fn($r) => $r['where'], $p3))) === []);
ok('presence: every character is accounted for, even when off shift',
    array_keys($p3) === ['ruth', 'pastor_dale', 'dot', 'harlan', 'janelle', 'theo']);

// A shift that wraps midnight — no such thing in Milldale, but a bar world is
// made of them, and half-open bounds are the difference between a clean handoff
// and two blocks claiming the same minute.
$night = mutate($T, ['cast', 'characters', 0, 'week'], [
    ['days' => [5], 'from' => '22:00', 'to' => '02:00', 'where' => 'bluebird', 'doing' => 'the late shift'],
]);
ok('presence: a shift that wraps midnight covers both sides of it',
    xeric_world_who_is_where($night, xeric_world_now($T, ep('2026-07-31 23:00')))['ruth']['where'] === 'bluebird'
    && xeric_world_who_is_where($night, xeric_world_now($T, ep('2026-08-01 01:00')))['ruth']['where'] === 'bluebird'
    && xeric_world_who_is_where($night, xeric_world_now($T, ep('2026-08-01 03:00')))['ruth']['where'] === null
    && xeric_world_who_is_where($night, xeric_world_now($T, ep('2026-07-31 21:59')))['ruth']['where'] === null);

$edge = mutate($T, ['cast', 'characters', 0, 'week'], [
    ['days' => [3], 'from' => '09:00', 'to' => '12:00', 'where' => 'bluebird',       'doing' => 'first'],
    ['days' => [3], 'from' => '12:00', 'to' => '15:00', 'where' => 'beck_hardware',  'doing' => 'second'],
]);
ok('presence: bounds are half-open, so back-to-back blocks hand off cleanly',
    xeric_world_who_is_where($edge, xeric_world_now($T, ep('2026-07-29 12:00')))['ruth']['where'] === 'beck_hardware'
    && xeric_world_who_is_where($edge, xeric_world_now($T, ep('2026-07-29 11:59')))['ruth']['where'] === 'bluebird');

// ---------------------------------------------------------------------------
// 4b. Homes, and OUT of the story
// ---------------------------------------------------------------------------
// Milldale ships without homes on purpose — the assertions above prove nothing
// regresses for a home-less world. These clones prove the other half: a home
// catches every hour the week does not claim, and OUT removes a person from
// the map entirely rather than leaving them somewhere by default.

$ruthHome = ['key' => 'ruth_and_dots', 'name' => "Ruth and Dot's place",
             'kind' => 'home', 'description' => 'A two-bedroom over the pharmacy.',
             'residents' => ['ruth', 'dot']];
$TH = mutate($T, ['places'], array_merge($T['places'], [$ruthHome]));

ok('homes: a world with a home in it still validates',
    err(fn() => xeric_world_validate($TH, 'milldale.json')) === '');

$h1 = xeric_world_who_is_where($TH, $thuEve);
ok('homes: off shift resolves to home, and says so',
    $h1['ruth']['where'] === 'ruth_and_dots' && !empty($h1['ruth']['at_home'])
    && $h1['dot']['where'] === 'ruth_and_dots'
    && $h1['harlan']['where'] === null);                    // no home, unchanged

ok('homes: three in the morning is asleep at home, not nowhere',
    xeric_world_who_is_where($TH, $thu3am)['ruth']['where'] === 'ruth_and_dots');

$h2 = xeric_world_who_is_where($TH, $wedAm);
ok('homes: the week always outranks the home',
    $h2['ruth']['where'] === 'first_lutheran' && empty($h2['ruth']['at_home']));

$TO = mutate($TH, ['cast', 'characters', 0, 'out'], true);
$o1 = xeric_world_who_is_where($TO, $thuEve);
ok('out: an unentered character is absent from the map, not unplaced on it',
    !array_key_exists('ruth', $o1)
    && xeric_world_who_is_at($o1, 'ruth_and_dots') === ['dot']);

ok('out: the flag must be a real boolean',
    str_contains(err(fn() => xeric_world_validate(
        mutate($TH, ['cast', 'characters', 0, 'out'], 'yes'), 'milldale.json')), 'out'));

ok('homes: a home with no residents does not load',
    str_contains(err(fn() => xeric_world_validate(
        mutate($TH, ['places'], array_merge($T['places'],
            [['key' => 'empty_house', 'kind' => 'home', 'residents' => []]])), 'milldale.json')),
        'residents'));

ok('homes: one person, one home',
    str_contains(err(fn() => xeric_world_validate(
        mutate($TH, ['places'], array_merge($TH['places'],
            [['key' => 'second_house', 'kind' => 'home', 'residents' => ['ruth']]])), 'milldale.json')),
        'one person, one home'));

ok('first_contact: names a real character or the world does not load',
    str_contains(err(fn() => xeric_world_validate(
        mutate($TH, ['cast', 'first_contact'], 'nobody_here'), 'milldale.json')),
        'first_contact'));

ok('first_contact: a valid one validates clean',
    err(fn() => xeric_world_validate(mutate($TH, ['cast', 'first_contact'], 'ruth'), 'milldale.json')) === '');

ok('first_contact: can never be OUT of the story',
    str_contains(err(fn() => xeric_world_validate(
        mutate($TO, ['cast', 'first_contact'], 'ruth'), 'milldale.json')),
        'OUT'));

// ---------------------------------------------------------------------------
// 4c. What is scheduled next
// ---------------------------------------------------------------------------
// The map above answers NOW; xeric_world_next_change() answers NEXT, standing
// on the same two readers, so these assertions are the fixture's own timetable
// read back: Thursday evening the pastor's basement hour is the only thing
// left in the day, and Friday runs the diner's whole arc from Dot's five
// o'clock start to Theo's half-past-five booth.

$thu6 = xeric_world_now($T, ep('2026-07-30 18:00'));
$nx   = xeric_world_next_change($T, $thu6);
$row  = function (array $rows, string $kind, string $key): ?array {
    foreach ($rows as $r) if ($r['kind'] === $kind && $r['key'] === $key) return $r;
    return null;
};

ok('next: the soonest change is the pastor arriving for his basement hour, sixty minutes out',
    ($nx[0]['in'] ?? -1) === 60 && $nx[0]['kind'] === 'arrives' && $nx[0]['key'] === 'pastor_dale'
    && $nx[0]['label'] === 'Pastor Dale Ostrander arrives at First Lutheran'
    && $nx[0]['epoch'] === $thu6['epoch'] + 3600, json_encode($nx[0] ?? null));

ok('next: rows come soonest first, and every epoch agrees with its own offset',
    (function () use ($nx, $thu6) {
        $last = -1;
        foreach ($nx as $r) {
            if ($r['in'] < $last || $r['epoch'] !== $thu6['epoch'] + $r['in'] * 60) return false;
            $last = $r['in'];
        }
        return count($nx) >= 12;                 // Friday is a full day in Milldale
    })(), json_encode(array_map(fn($r) => $r['in'], $nx)));

// The whole point of the walk: "skip to when the bar opens" now has an answer.
ok('next: the diner opens at half past five, said as a thing a button could offer',
    ($row($nx, 'opens', 'bluebird')['in'] ?? 0) === 690
    && $row($nx, 'opens', 'bluebird')['label'] === 'the Bluebird Diner opens');

ok('next: a one-hour counter shift produces both of its ends',
    ($row($nx, 'arrives', 'ruth')['in'] ?? 0) === 780
    && ($row($nx, 'leaves', 'ruth')['in'] ?? 0) === 840
    && $row($nx, 'leaves', 'ruth')['label'] === 'Ruth Amberg leaves the Bluebird Diner');

// A place with no readable hours is always open, and a place closed for good
// is never open: neither ever CHANGES, so neither is ever news.
ok('next: the mill (closed since 1998) and the always-open church are never news',
    (function () use ($nx) {
        foreach ($nx as $r) {
            if (in_array($r['key'], ['the_mill', 'first_lutheran'], true)
                && in_array($r['kind'], ['opens', 'closes'], true)) return false;
        }
        return true;
    })());

ok('next: the window is honoured, two hours ahead holds only the pastor',
    count(xeric_world_next_change($T, $thu6, 2)) === 1);

ok('next: the dead have no next — handed in like who_is_where takes them',
    (function () use ($T, $thu6, $row) {
        $nx = xeric_world_next_change($T, $thu6, 24, ['pastor_dale']);
        return $row($nx, 'arrives', 'pastor_dale') === null
            && ($nx[0]['key'] ?? '') === 'dot' && ($nx[0]['in'] ?? 0) === 660;
    })());

ok('next: an OUT character is absent from the future too, but their workplace still keeps its hours',
    (function () use ($TO, $T, $thu6, $row) {
        $nx = xeric_world_next_change($TO, xeric_world_now($TO, $thu6['epoch']));
        return $row($nx, 'arrives', 'ruth') === null && $row($nx, 'leaves', 'ruth') === null
            && ($row($nx, 'opens', 'bluebird')['in'] ?? 0) === 690;
    })());

// The home fallback is where the week's silence puts you, so coming off a
// shift reads as LEAVING the shift, never as an appointment at home.
ok('next: going home is the shift ending, not an arrival',
    (function () use ($TH, $thu6, $row) {
        $nx = xeric_world_next_change($TH, xeric_world_now($TH, $thu6['epoch']));
        $off = $row($nx, 'leaves', 'ruth');
        return ($row($nx, 'arrives', 'ruth')['in'] ?? 0) === 780
            && ($off['in'] ?? 0) === 840
            && $off['label'] === 'Ruth Amberg leaves the Bluebird Diner';
    })());

ok('next: back-to-back blocks hand off as one move, not a leave and an arrive',
    (function () use ($edge, $row) {
        $nx = xeric_world_next_change($edge, xeric_world_now($edge, ep('2026-07-29 08:00')), 8);
        $mv = $row($nx, 'moves', 'ruth');
        return ($row($nx, 'arrives', 'ruth')['in'] ?? 0) === 60
            && ($mv['in'] ?? 0) === 240
            && $mv['label'] === 'Ruth Amberg leaves the Bluebird Diner for Beck Hardware'
            && ($row($nx, 'leaves', 'ruth')['in'] ?? 0) === 420;
    })());

// ---------------------------------------------------------------------------
// 4d. The entrance — an OUT character comes into the story
// ---------------------------------------------------------------------------
// The flip and the memory of it are one operation: the template changes AND
// the town remembers, or neither happens. The lived world here is the homes
// clone with Dot benched, so the entrance also proves the event lands on her
// own doorstep rather than in a guessed public room.

$entDir = sys_get_temp_dir() . '/xeric-enter-test-' . getmypid();
@mkdir($entDir, 0777, true);
$entFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents($entDir . '/world-template.json',
    json_encode(mutate($TH, ['cast', 'characters', 2, 'out'], true), $entFlags) . "\n");
$entDb = xeric_state_open($entDir . '/world.db');
$entEp = ep('2026-07-30 18:00');

$rEnt = xeric_enter($entDir, $entDb, 'dot', $entEp);
$entOnDisk = json_decode((string)file_get_contents($entDir . '/world-template.json'), true);
ok('enter: the flip lands on disk, still a real boolean, and the world still validates',
    $rEnt['entered'] === true
    && $entOnDisk['cast']['characters'][2]['out'] === false
    && err(fn() => xeric_world_validate($entOnDisk, 'entered')) === '');

ok('enter: the last good copy was kept first, with the bench still in it',
    json_decode((string)file_get_contents($entDir . '/world-template.prev.json'),
        true)['cast']['characters'][2]['out'] === true);

$entEv = xeric_events_recent($entDb, 1)[0] ?? [];
ok('enter: the town remembers — one event, hers, at the world hour, on her own doorstep',
    (int)$entEv['id'] === (int)$rEnt['event_id']
    && $entEv['title'] === 'Dot Vance turned up today'
    && (int)$entEv['world_epoch'] === $entEp
    && $entEv['participants'] === ['dot']
    && $entEv['place'] === 'ruth_and_dots'
    && str_contains((string)$entEv['prose'], 'counted Dot Vance among the people who are here'),
    json_encode($entEv));

ok('enter: a second press is a no-op, not a second arrival',
    xeric_enter($entDir, $entDb, 'dot', $entEp)['entered'] === false
    && xeric_events_count($entDb) === 1);

ok('enter: nobody by that name refuses loudly, and nothing changes',
    str_contains(err(fn() => xeric_enter($entDir, $entDb, 'norbert')), 'not a character')
    && xeric_events_count($entDb) === 1);

// A world never opened has no past for an entrance to land in. The caller
// says so by passing null, and the flip still goes through the same door —
// and must not conjure a world.db into being, because the file existing is
// what "lived in" means to everything that reads the shelf.
$entDir2 = $entDir . '-unopened';
@mkdir($entDir2, 0777, true);
file_put_contents($entDir2 . '/world-template.json',
    json_encode(mutate($T, ['cast', 'characters', 2, 'out'], true), $entFlags) . "\n");
$rEnt2 = xeric_enter($entDir2, null, 'dot');
ok('enter: a world never opened gets the flip, no event, and no database conjured into being',
    $rEnt2['entered'] === true && $rEnt2['event_id'] === null
    && json_decode((string)file_get_contents($entDir2 . '/world-template.json'),
        true)['cast']['characters'][2]['out'] === false
    && !is_file($entDir2 . '/world.db'), json_encode($rEnt2));

$entDb = null;
foreach ([$entDir . '/world.db', $entDir . '/world.db-wal', $entDir . '/world.db-shm',
          $entDir . '/world-template.json', $entDir . '/world-template.prev.json',
          $entDir2 . '/world-template.json'] as $f) @unlink($f);
@rmdir($entDir);
@rmdir($entDir2);

// ---------------------------------------------------------------------------
// 5. State: migrations, seeding, round-trips
// ---------------------------------------------------------------------------

$dbPath = sys_get_temp_dir() . '/xeric-engine-test-' . getmypid() . '.db';
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) @unlink($f);

$db = xeric_state_open($dbPath);
ok('state: open sets WAL', strtolower((string)$db->query('PRAGMA journal_mode')->fetchAll()[0]['journal_mode']) === 'wal');
ok('state: every table exists after the first open', (function () use ($db) {
    $want = ['arcs', 'conversations', 'events', 'memories', 'messages', 'world_state'];
    $have = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(), 'name');
    return array_values(array_intersect($want, $have)) === $want;
})());

xeric_state_seed($db, $T);
ok('state: seeding sets every character\'s economy counters to their start value',
    xeric_arc_int($db, 'ruth', 'economy.casserole_ledger') === 0
    && xeric_arc_int($db, 'janelle', 'economy.thursday_pot') === 0
    && xeric_arc_int($db, 'ruth', 'trust') === 0);
ok('state: seeding sets the world needle from the template', xeric_arc_int($db, xeric_arc_world(), 'needle') === 0);
ok('state: the clock offset starts at zero and is world state, not a constant',
    xeric_clock_offset($db) === 0 && xeric_world_state_get($db, 'seeded_at') !== null);

$convId = xeric_conversation_create($db, 'ruth', 'chat', 'Thursday', ep('2026-07-30 19:00'));
xeric_message_append($db, $convId, 'user', null, 'You around?', ep('2026-07-30 19:00'));
xeric_message_append($db, $convId, 'character', 'ruth', 'In the kitchen. Where else.', ep('2026-07-30 19:02'));
xeric_message_append($db, $convId, 'user', null, 'Save me a roll?', ep('2026-07-30 19:05'));
xeric_memory_add($db, 'ruth', 'Walt brought the dish back clean and did not say anything about it.', 'event',
    ['event_id' => 1, 'place' => 'first_lutheran'], ep('2026-07-12 11:00'));
xeric_memory_add($db, 'ruth', 'Janelle asked about the winter and Ruth changed the subject.', 'auto', [], ep('2026-07-26 10:00'));
xeric_event_add($db, 'The potluck ran long', ep('2026-07-26 18:00'), 'first_lutheran', ['ruth', 'pastor_dale'], 'Nobody left until the urn was empty.');
xeric_arc_set($db, 'ruth', 'trust', 5);
xeric_arc_bump($db, 'ruth', 'economy.casserole_ledger', 14);

$before = [
    'conversations' => xeric_conversations_count($db),
    'messages'      => xeric_messages_count($db, $convId),
    'memories'      => xeric_memories_count($db, 'ruth'),
    'events'        => xeric_events_count($db),
    'arcs'          => xeric_arcs_count($db),
];
$cols = xeric_state_columns($db, 'messages');
$db = null;

// Reopen: migrations must be a no-op on an existing world, and re-seeding must
// not walk back over anything that has since been lived.
$db2 = xeric_state_open($dbPath);
xeric_state_seed($db2, $T);
$after = [
    'conversations' => xeric_conversations_count($db2),
    'messages'      => xeric_messages_count($db2, $convId),
    'memories'      => xeric_memories_count($db2, 'ruth'),
    'events'        => xeric_events_count($db2),
    'arcs'          => xeric_arcs_count($db2),
];
ok('state: reopening runs migrations again with no error and no data loss', $before === $after,
    json_encode(['before' => $before, 'after' => $after]));
ok('state: migrations do not rewrite an existing table', xeric_state_columns($db2, 'messages') === $cols);
ok('state: re-seeding never clobbers a lived value',
    xeric_arc_int($db2, 'ruth', 'trust') === 5 && xeric_arc_int($db2, 'ruth', 'economy.casserole_ledger') === 14);

$mem = xeric_memories_for($db2, 'ruth', 10);
ok('state: memories round-trip, oldest first, with meta decoded',
    count($mem) === 2
    && str_starts_with($mem[0]['text'], 'Walt brought the dish back')
    && $mem[0]['source'] === 'event'
    && ($mem[0]['meta']['place'] ?? '') === 'first_lutheran'
    && (int)$mem[0]['world_epoch'] === ep('2026-07-12 11:00')
    && $mem[1]['meta'] === []);
ok('state: the memory limit takes the newest, not the oldest',
    count(xeric_memories_for($db2, 'ruth', 1)) === 1
    && str_starts_with(xeric_memories_for($db2, 'ruth', 1)[0]['text'], 'Janelle asked'));
ok('state: memories are per-character', xeric_memories_for($db2, 'dot', 10) === [] && xeric_memories_count($db2, 'dot') === 0);

$msgs = xeric_messages_recent($db2, $convId, 10);
ok('state: messages round-trip oldest-first with roles and world epochs intact',
    count($msgs) === 3 && $msgs[0]['content'] === 'You around?'
    && $msgs[1]['role'] === 'character' && $msgs[1]['handle'] === 'ruth'
    && (int)$msgs[2]['world_epoch'] === ep('2026-07-30 19:05'));
ok('state: a character speaking marks the thread unread; the user reading it clears it', (function () use ($db2) {
    $c = xeric_conversation_create($db2, 'dot', 'chat');
    xeric_message_append($db2, $c, 'character', 'dot', 'You forgot your hat.');
    $a = (int)xeric_conversation_get($db2, $c)['unread'];
    xeric_message_append($db2, $c, 'user', null, 'Keep it.');
    return $a === 1 && (int)xeric_conversation_get($db2, $c)['unread'] === 0;
})());
ok('state: find-or-create returns the same thread twice',
    xeric_conversation_for($db2, 'ruth', 'chat') === $convId
    && xeric_conversation_for($db2, 'ruth', 'chat') === $convId);
ok('state: an event round-trips with its participants list',
    (function () use ($db2) {
        $e = xeric_events_recent($db2, 5);
        return count($e) === 1 && $e[0]['participants'] === ['ruth', 'pastor_dale'] && $e[0]['place'] === 'first_lutheran';
    })());
ok('state: arcs read back by person and by key',
    xeric_arcs_for($db2, 'ruth')['trust'] === '5'
    && (xeric_arcs_by_key($db2, 'economy.casserole_ledger')['ruth'] ?? '') === '14'
    && xeric_arc_get($db2, 'ruth', 'no_such_arc', 'fallback') === 'fallback');
ok('state: the world clock offset is stored, readable, and added to real time', (function () use ($db2) {
    $base = xeric_clock_epoch($db2, 1000);
    xeric_clock_offset_set($db2, 3600);
    $after = xeric_clock_epoch($db2, 1000);
    xeric_clock_offset_set($db2, 0);
    return $base === 1000 && $after === 4600 && xeric_clock_offset($db2) === 0;
})());

// ---------------------------------------------------------------------------
// 6. Prompt assembly — the cache split, by position
// ---------------------------------------------------------------------------

xeric_arc_set($db2, 'dot',     'economy.casserole_ledger', 11);
xeric_arc_set($db2, 'harlan',  'economy.casserole_ledger', 6);
xeric_arc_set($db2, 'janelle', 'economy.casserole_ledger', 2);

$now  = xeric_world_now($T, ep('2026-07-30 20:15'));      // Thursday evening
$P    = xeric_prompt_build($T, $db2, 'ruth', $now, ['conversation_id' => $convId]);
$sys  = $P[0]['content'];
$last = $P[count($P) - 1]['content'];

ok('prompt: the first message is the system message', $P[0]['role'] === 'system');
ok('prompt: the bible is IN the system message',
    str_contains($sys, 'MILLDALE') && str_contains($sys, 'the church basement crowd')
    && str_contains($sys, 'WHERE THEY ARE'));
ok('prompt: the bible is ONLY in the system message', (function () use ($P) {
    for ($i = 1; $i < count($P); $i++) if (str_contains($P[$i]['content'], 'the church basement crowd')) return false;
    return true;
})());
ok('prompt: her own voice opens the system message',
    str_starts_with($sys, 'YOU ARE RUTH AMBERG')
    && str_contains($sys, 'You talk like this: Short sentences'));
ok('prompt: the static blocks are in cache order — voice, rules, bible, economies, memories', (function () use ($sys) {
    $order = ['YOU ARE RUTH AMBERG', 'HOW YOU ANSWER', 'MILLDALE', 'WHAT IS BEING COUNTED', 'WHAT YOU REMEMBER'];
    $at = -1;
    foreach ($order as $marker) {
        $p = strpos($sys, $marker);
        if ($p === false || $p < $at) return false;
        $at = $p;
    }
    return true;
})());
ok('prompt: economy counters come from arcs, not from a caller-built array',
    str_contains($sys, 'Where you stand right now: 14.')
    && str_contains($sys, "Standing:\n  1. Ruth Amberg (you) — 14")
    && str_contains($sys, '2. Dot Vance — 11'));
ok('prompt: memories render with absolute in-world dates, not relative ones',
    str_contains($sys, '(Sun 12 Jul) Walt brought the dish back clean')
    && !str_contains($sys, 'ago'));

ok('prompt: the LAST message is a user message', $P[count($P) - 1]['role'] === 'user');
ok('prompt: the clock is IN the last user message',
    str_contains($last, 'RIGHT NOW') && str_contains($last, 'Thursday evening, 20:15'));
ok('prompt: the clock is NOWHERE in the system message',
    !str_contains($sys, '20:15') && !str_contains($sys, 'RIGHT NOW') && !str_contains($sys, 'Thursday evening'));
ok('prompt: the last user message carries the user\'s actual words too',
    str_contains($last, 'Save me a roll?') && str_starts_with($last, 'Save me a roll?'));
ok('prompt: history becomes real chat turns in order',
    count($P) === 4
    && $P[1]['role'] === 'user'      && $P[1]['content'] === 'You around?'
    && $P[2]['role'] === 'assistant' && $P[2]['content'] === 'In the kitchen. Where else.'
    && $P[3]['role'] === 'user');

$later = xeric_prompt_build($T, $db2, 'ruth', xeric_world_now($T, ep('2026-07-31 07:30')), ['conversation_id' => $convId]);
ok('prompt: moving the clock leaves the system message byte-identical (the whole point)',
    $later[0]['content'] === $sys);
ok('prompt: moving the clock does change the last user message',
    $later[count($later) - 1]['content'] !== $last
    && str_contains($later[count($later) - 1]['content'], 'Friday morning, 07:30')
    && str_contains($later[count($later) - 1]['content'], 'You are at the Bluebird Diner, counter, one egg, two coffees.')
    && str_contains($later[count($later) - 1]['content'], 'Also there: Dot Vance.'));

$coached = xeric_prompt_build($T, $db2, 'ruth', $now, ['conversation_id' => $convId, 'tail' => 'She is tired and it is late.']);
ok('prompt: per-turn coaching rides the last user message, never the system prompt',
    str_contains($coached[count($coached) - 1]['content'], 'She is tired and it is late.')
    && !str_contains($coached[0]['content'], 'She is tired')
    && $coached[0]['content'] === $sys);

$incoming = xeric_prompt_build($T, $db2, 'ruth', $now, ['conversation_id' => $convId, 'user_message' => 'Is the urn on?']);
ok('prompt: an incoming message becomes a new last user turn, clock attached',
    count($incoming) === 5
    && $incoming[4]['role'] === 'user'
    && str_starts_with($incoming[4]['content'], 'Is the urn on?')
    && str_contains($incoming[4]['content'], 'RIGHT NOW'));

$fresh = xeric_prompt_build($T, $db2, 'pastor_dale', $now);
ok('prompt: with no thread at all there is still a last user message with the clock',
    count($fresh) === 2 && $fresh[1]['role'] === 'user' && str_contains($fresh[1]['content'], 'RIGHT NOW'));
ok('prompt: presence puts the speaker in the room and names who else is there',
    str_contains($fresh[1]['content'], 'You are at First Lutheran, the basement, door closed.'));
ok('prompt: building a prompt writes nothing', (function () use ($T, $db2, $now) {
    $before = xeric_conversations_count($db2) . '/' . xeric_memories_count($db2);
    xeric_prompt_build($T, $db2, 'harlan', $now);
    return $before === xeric_conversations_count($db2) . '/' . xeric_memories_count($db2);
})());

// A new memory must invalidate only the tail of the system prompt.
$cut = strpos($sys, 'WHAT YOU REMEMBER');
xeric_memory_add($db2, 'ruth', 'The pastor stayed late and washed the urn himself.', 'event', [], ep('2026-07-30 22:30'));
$grown = xeric_prompt_system($T, $db2, 'ruth', 'sfw');
ok('prompt: a new memory changes only the tail — everything above it still prefix-matches',
    $cut !== false && $grown !== $sys && str_starts_with($grown, substr($sys, 0, $cut))
    && str_contains($grown, 'washed the urn himself'));

// ---------------------------------------------------------------------------
// 7. Prompt assembly under a wall
// ---------------------------------------------------------------------------

$J    = xeric_prompt_build($T, $db2, 'janelle', $now);
$jAll = implode("\n", array_column($J, 'content'));
$jBible = xeric_render_bible($T, ['handle' => 'janelle'], 'sfw');

$leaked = leaks($jAll, OTHERS_SECRETS);
ok('wall: the walled daughter\'s PROMPT leaks none of the walled strings', $leaked === [], implode(' | ', $leaked));
ok('wall: the prompt omits exactly what the bible omits', (function () use ($jAll, $jBible) {
    foreach (OTHERS_SECRETS as $s) {
        if ((stripos($jBible, $s) !== false) !== (stripos($jAll, $s) !== false)) return false;
    }
    return true;
})());
ok('wall: she still knows her own head — the wall governs others, not herself',
    leaks($jAll, ['glovebox', 'being told she worries too much', 'forgiven for living forty minutes away'])
    === ['glovebox', 'being told she worries too much', 'forgiven for living forty minutes away']);
ok('wall: the walled economy is absent from her prompt while the open one renders',
    !str_contains($jAll, 'THURSDAY POT') && str_contains($jAll, 'THE CASSEROLE LEDGER'));
ok('wall: her framing survives into the prompt',
    str_contains($jAll, 'exactly and only what they look like'));
ok('wall: a fixture speaker gets a job and a room, not souls',
    (function () use ($T, $db2, $now) {
        $cy = implode("\n", array_column(xeric_prompt_build($T, $db2, 'cy', $now), 'content'));
        return leaks($cy, OTHERS_SECRETS) === []
            && str_contains($cy, 'YOU ARE CY LOOMIS')
            && str_contains($cy, 'You are at Beck Hardware')
            && str_contains($cy, 'the Bluebird Diner');
    })());
ok('wall: an unresolvable speaker fails closed in the prompt too',
    (function () use ($T, $db2, $now) {
        $x = implode("\n", array_column(xeric_prompt_build($T, $db2, 'nobody_by_that_name', $now), 'content'));
        return leaks($x, OTHERS_SECRETS) === [] && str_contains($x, 'RIGHT NOW');
    })());

// The protagonist's arc is somebody's interior with a spotlight on it, and the
// prompt is the second surface it can leak through.
$star = mutate($T, ['cast', 'protagonist'], [
    'handle'   => 'harlan',
    'arc'      => 'He is deciding whether to sell the hardware store before it decides for him.',
    'pressure' => 'A second letter from the bank he has not opened.',
]);
$starAll = fn(string $h) => implode("\n", array_column(xeric_prompt_build($star, $db2, $h, $now), 'content'));
ok('wall: the protagonist\'s arc reaches an unwalled speaker and not the walled daughter',
    str_contains($starAll('ruth'), 'before it decides for him')
    && leaks($starAll('janelle'), ['before it decides for him', 'second letter from the bank']) === []
    && leaks($starAll('nobody_by_that_name'), ['before it decides for him']) === []);

// THE VOLATILE TAIL READS THE WALLS. It is the block the model is told, four
// lines earlier, to trust over everything above it, so a wall the bible honoured
// and this block did not would be undone in the loudest place in the prompt.
$fri     = xeric_world_now($T, ep('2026-07-31 07:30'));      // Ruth and Dot at the diner
$tailOf  = fn(array $t) => (function (array $m) { return $m[count($m) - 1]['content']; })(
    xeric_prompt_build($t, $db2, 'ruth', $fri, ['conversation_id' => $convId]));
$walled  = fn(array $hidden) => mutate($T, ['knowledge_walls', 3],
    ['key' => 'ruth_alone', 'audience' => ['handle' => 'ruth'], 'hidden' => $hidden]);

ok('wall: RIGHT NOW keeps the room and names nobody in it when cast_lines is walled',
    (function () use ($tailOf, $walled) {
        $tail = $tailOf($walled(['cast_lines']));
        return str_contains($tail, 'You are at the Bluebird Diner')
            && !str_contains($tail, 'Also there') && !str_contains($tail, 'Dot Vance');
    })());
ok('wall: RIGHT NOW gives up the room entirely when the schedules are walled',
    (function () use ($tailOf, $walled) {
        $tail = $tailOf($walled(['schedules']));
        return !str_contains($tail, 'You are at') && !str_contains($tail, 'Dot Vance')
            && str_contains($tail, 'it is your own time');
    })());
ok('wall: and when it is one room rather than the whole roster',
    (function () use ($tailOf, $walled) {
        $tail = $tailOf($walled(['places.bluebird']));
        return !str_contains($tail, 'Bluebird') && str_contains($tail, 'it is your own time');
    })());
ok('wall: the fallback is the same sentence anybody off shift gets — no hole where the room was',
    $tailOf($walled(['schedules'])) === $tailOf($walled(['places.bluebird'])));

// ---------------------------------------------------------------------------
// 8. Boons owed, from arcs
// ---------------------------------------------------------------------------

xeric_arc_set($db2, 'ruth', 'boon.potluck_lead', $now['epoch'] + 40 * 3600);
xeric_arc_set($db2, 'ruth', 'boon.basement_key', $now['epoch'] - 3600);      // gone stale
$owed     = xeric_prompt_system($T, $db2, 'ruth', 'sfw', 12, $now['epoch']);
$owedList = substr($owed, (int)strpos($owed, 'Owed right now, unclaimed:'));
ok('state: an owed boon reaches the prompt from arcs alone',
    str_contains($owed, 'Owed right now, unclaimed:') && str_contains($owedList, '- The potluck lead.'));
ok('state: a boon past its ttl drops off the owed list (its catalogue entry stays)',
    !str_contains($owedList, 'basement') && stripos($owed, 'a key to the basement door') !== false);
ok('state: the owed list carries no countdown — a ticking number would cost the cache',
    !str_contains($owed, 'hours left'));

// ---------------------------------------------------------------------------
// 9. Lessons are prose, and prose carries walled words out with it
// ---------------------------------------------------------------------------

// A lesson is written by a model that was shown a hand edit, and a hand edit
// quotes the field it changed verbatim. This one carries Ruth's sore spot.
xeric_lessons_add($db2, xeric_arc_world(),
    ['Never thank Ruth out loud: being thanked in front of people is the one thing she cannot sit through.']);
xeric_lessons_add($db2, 'janelle',
    ['Keep it short with her — he answers short messages and lets long ones sit.']);

$ruthSys = xeric_prompt_system($T, $db2, 'ruth', 'sfw');
$janSys  = xeric_prompt_system($T, $db2, 'janelle', 'sfw');
ok('lessons: a world lesson reaches the reader whose walls do not cover what it quotes',
    str_contains($ruthSys, 'being thanked in front of people'));
ok('lessons: and is dropped for the reader whose wall took that interior away',
    !str_contains($janSys, 'being thanked in front of people') && !str_contains($janSys, 'Never thank Ruth'));
ok('lessons: her own bucket still stands — the walls govern other people, not her own notes',
    str_contains($janSys, 'WHAT YOU HAVE WORKED OUT') && str_contains($janSys, 'lets long ones sit'));
ok('lessons: dropping one is silent — her block is her own line and nothing else',
    (function () use ($janSys) {
        $block = substr($janSys, (int)strpos($janSys, 'WHAT YOU HAVE WORKED OUT'));
        $block = substr($block, 0, (int)strpos($block, 'You do these quietly'));
        return substr_count($block, "\n- ") === 1;
    })());

// ---------------------------------------------------------------------------
// 10. Age — the child is an ordinary character, and the sex gate is arithmetic
//
// Both halves are load-bearing, and the FIRST one is the easier to break. Theo
// is twelve: he is in the cast, in a room on a schedule, in an adult's bible,
// answerable in a conversation, and holding the one secret a mystery here would
// be built on. A rule that quietly removed him would be the wrong rule.
//
// The second half is the desire economy, and it is closed to him by arithmetic
// and by the validator rather than by asking a renderer to remember: his
// ceiling is the weakest rating in a world of any rating, and a template that
// tries to arm him with romance, seed an attraction at him, or hang a gate
// above sfw on his dossier does not load at all.
// ---------------------------------------------------------------------------

$kid   = xeric_world_character($T, 'theo');
$adult = xeric_world_character($T, 'harlan');
$gated = ['text' => 'a node behind a gate', 'rating_min' => 'mature'];

ok('age: the minor flag is derived from an integer age and the boundary is 18',
    xeric_is_minor(['age' => 12]) && xeric_is_minor(['age' => 17])
    && !xeric_is_minor(['age' => 18]) && !xeric_is_minor(['age' => 71]));
ok('age: an age the engine cannot read is a minor — missing, null, string, float',
    xeric_is_minor([]) && xeric_is_minor(['age' => null]) && xeric_is_minor(['age' => '19'])
    && xeric_is_minor(['age' => 19.5]) && xeric_is_minor(['age' => true]));
ok('age: the flag is computed, so a template that declares itself adult is ignored',
    xeric_is_minor(['age' => 12, 'is_minor' => false, 'adult' => true, 'minor' => false])
    && !xeric_is_minor(['age' => 40, 'is_minor' => true]));

ok('age: an adult renders at the world rating, whatever it is',
    xeric_effective_rating('explicit', $adult) === 'explicit'
    && xeric_effective_rating('mature', $adult) === 'mature'
    && xeric_effective_rating('sfw', $adult) === 'sfw');
ok('age: a minor renders at the weakest rating, whatever the world asked for',
    xeric_effective_rating('explicit', $kid) === 'sfw'
    && xeric_effective_rating('mature', $kid) === 'sfw'
    && xeric_effective_rating('explicit', []) === 'sfw');
ok('age: no world rating and no gate combination lets a minor past the floor',
    (function () use ($kid, $adult) {
        foreach (xeric_ratings() as $world) {
            foreach (['mature', 'explicit'] as $min) {
                if (xeric_rating_allows($world, ['text' => 'x', 'rating_min' => $min], $kid)) return false;
            }
        }
        return xeric_rating_allows('explicit', ['text' => 'x', 'rating_min' => 'explicit'], $adult);
    })());
ok('age: the same node an adult may read is refused for the child, at the same world rating',
    xeric_rating_allows('mature', $gated, $adult) && !xeric_rating_allows('mature', $gated, $kid));
ok('age: the filter drops it for him and keeps it for everybody else',
    xeric_rating_filter([['text' => 'plain'], $gated], 'mature', $kid) === [['text' => 'plain']]
    && count(xeric_rating_filter([['text' => 'plain'], $gated], 'mature', $adult)) === 2
    && count(xeric_rating_filter([['text' => 'plain'], $gated], 'mature')) === 2);

ok('age: the viewer carries the derived flag — narrator no, adult no, child yes, stranger yes',
    xeric_viewer($T, null)['is_minor'] === false
    && xeric_viewer($T, ['handle' => 'harlan'])['is_minor'] === false
    && xeric_viewer($T, ['handle' => 'theo'])['is_minor'] === true
    && xeric_viewer($T, ['handle' => 'nobody_by_that_name'])['is_minor'] === true);
ok('age: a fixture inherits the age of the character it is the scenery form of',
    xeric_viewer($T, ['handle' => 'harlan_counter'])['is_minor'] === false
    && xeric_viewer($T, ['handle' => 'cy'])['is_minor'] === true);
ok('age: a caller cannot declare its viewer an adult',
    xeric_viewer($T, ['handle' => 'theo', 'is_minor' => false, 'age' => 40])['is_minor'] === true);
ok('age: a viewer\'s ceiling clamps for the child and for a viewer array with no flag at all',
    xeric_viewer_rating('explicit', xeric_viewer($T, ['handle' => 'theo'])) === 'sfw'
    && xeric_viewer_rating('explicit', xeric_viewer($T, ['handle' => 'harlan'])) === 'explicit'
    && xeric_viewer_rating('explicit', []) === 'sfw');

ok('age: an unaffirmed session is pinned to the floor, and affirming raises nothing',
    xeric_rating_clamp('explicit', false) === 'sfw'
    && xeric_rating_clamp('mature', false) === 'sfw'
    && xeric_rating_clamp('explicit', true) === 'explicit'
    && xeric_rating_clamp('sfw', true) === 'sfw');
ok('age: clamping a template moves meta.rating and nothing else', (function () use ($T) {
    $x    = mutate($T, ['meta', 'rating'], 'explicit');
    $down = xeric_world_clamp_rating($x, false);
    $up   = xeric_world_clamp_rating($x, true);
    return $down['meta']['rating'] === 'sfw' && xeric_world_rating($down) === 'sfw'
        && $up['meta']['rating'] === 'explicit' && xeric_world_rating($up) === 'explicit'
        && $down['cast'] === $x['cast'] && $down['places'] === $x['places'] && $down['user'] === $x['user']
        && xeric_world_clamp_rating(['meta' => []], false)['meta']['rating'] === 'sfw';
})());

// And the half that matters more: he is a person in this town.
ok('age: the child is on the roster, in his orbit, with his age in plain view',
    in_array('theo', array_map(fn($c) => (string)$c['handle'], xeric_world_cast($T)), true)
    && in_array('theo', array_map(fn($c) => (string)$c['handle'], xeric_world_cast($T, 'main_street')), true)
    && $kid['age'] === 12);
ok('age: he is in a room on a schedule, and the room knows he is in it', (function () use ($T) {
    $after = xeric_world_who_is_where($T, xeric_world_now($T, ep('2026-07-29 16:00')));   // Wednesday, after school
    return $after['theo']['where'] === 'bluebird'
        && $after['theo']['doing'] === 'the back booth, homework, one refill he did not pay for'
        && in_array('theo', xeric_world_who_is_at($after, 'bluebird'), true);
})());
ok('age: he holds a secret, and it is the one a mystery here would be built on',
    count((array)($kid['secrets'] ?? [])) === 1
    && str_contains(xeric_text($kid['secrets'][0]), 'fourth-floor stairwell'));
ok('age: you can talk to him — his prompt builds in his own voice, with the clock', (function () use ($T, $db2) {
    $p    = xeric_prompt_build($T, $db2, 'theo', xeric_world_now($T, ep('2026-07-29 16:00')));
    $sys  = $p[0]['content'];
    $tail = $p[count($p) - 1]['content'];
    return str_starts_with($sys, 'YOU ARE THEO VANCE')
        && str_contains($sys, 'Fast, factual')
        && str_contains($sys, 'be believed the first time')
        && str_contains($tail, 'RIGHT NOW')
        && str_contains($tail, 'You are at the Bluebird Diner');
})());
ok('age: nothing in his own prompt puts him in the desire economy', (function () use ($T, $db2, $now) {
    $his = implode("\n", array_column(xeric_prompt_build($T, $db2, 'theo', $now), 'content'));
    return !str_contains($his, 'When you flirt') && !str_contains($his, 'Drawn to');
})());
ok('age: the adults know he is there — he is in their bible like anybody else',
    str_contains(xeric_prompt_system($T, $db2, 'ruth', 'sfw'), 'Theo Vance')
    && str_contains(xeric_prompt_system($T, $db2, 'harlan', 'sfw'), 'the last booth'));

// ---------------------------------------------------------------------------
// 11. Story overlays — a story that ends, laid over a world that does not
//
// Three claims here are the feature, and they are tested in the order they are
// load-bearing:
//
//   1. CLOSING IS A SUBTRACTION. Compose, resolve, compose again, and the
//      template is === the one that was never touched. Everything else in the
//      design exists to keep that assertion true, so it is written first.
//   2. THE FALSE CALM IS ×1.0 EXACTLY. Not approximately: the design's claim is
//      that the town carries on at its own pace, and an intensity of 0.5 that
//      came back as 0.49999999999999994 would make that a rounding error.
//   3. THE CHILD IS LOAD-BEARING. Theo is twelve, he holds the piece that kills
//      a red herring, and the story cannot be closed without him. The assertions
//      below are that he is never kept out of an hour, an event or a
//      conversation on account of his age — and that the one thing that IS
//      closed to him, a node gated above sfw, refuses to load.
// ---------------------------------------------------------------------------

$storyPath = __DIR__ . '/../fixtures/milldale-story.json';
$S         = xeric_story_read($storyPath);

$storyDb = sys_get_temp_dir() . '/xeric-story-test-' . getmypid() . '.db';
$fresh   = function () use ($storyDb, $T) {
    foreach ([$storyDb, $storyDb . '-wal', $storyDb . '-shm'] as $f) @unlink($f);
    $db = xeric_state_open($storyDb);
    xeric_state_seed($db, $T);
    return $db;
};
$db3 = $fresh();

// A world directory with two overlays in it, one of which this world may not show.
$storyDir = sys_get_temp_dir() . '/xeric-story-dir-' . getmypid();
@mkdir($storyDir);
file_put_contents($storyDir . '/story-mill_stairwell.json', file_get_contents($storyPath));
$rated = mutate(mutate($S, ['key'], 'after_hours'), ['rating_min'], 'mature');
file_put_contents($storyDir . '/story-after_hours.json', json_encode($rated));

ok('story: the fixture validates against the world it was written for',
    err(fn() => xeric_story_validate($S, $T, 'milldale-story.json')) === '');
ok('story: overlays are discovered beside the template, in filename order',
    array_map('basename', xeric_story_files($storyDir)) === ['story-after_hours.json', 'story-mill_stairwell.json']
    && count(xeric_story_load($storyDir)) === 2);
ok('story: an overlay this world may not show is skipped with a note, not a refusal', (function () use ($storyDir, $T) {
    $notes = [];
    $live  = xeric_story_for($storyDir, $T, function (string $n) use (&$notes) { $notes[] = $n; });
    return count($live) === 1 && xeric_story_key($live[0]) === 'mill_stairwell'
        && count(array_filter($notes, fn($n) => str_contains($n, 'story-after_hours.json is rated above this world'))) === 1;
})());
ok('story: a mismatched for_world is a warning and never a refusal', (function () use ($S, $T) {
    $away = mutate($S, ['for_world'], 'Blackwood Creek');
    return err(fn() => xeric_story_validate($away, $T)) === ''
        && count(array_filter(xeric_story_warnings($away, $T), fn($w) => str_contains($w, "written for 'Blackwood Creek'"))) === 1;
})());
ok('story: bad JSON in an overlay names the file',
    (function () {
        $stem = tempnam(sys_get_temp_dir(), 'xeric');
        $p    = $stem . '.json';
        file_put_contents($p, '{ nope');
        $m = err(fn() => xeric_story_read($p));
        @unlink($p);
        @unlink($stem);
        return str_contains($m, 'bad JSON') && str_contains($m, basename($p));
    })());

// -- 11.1 the validator names the offending path, like the world's does ------
$storyCases = [
    ['a wrong lead that is not wrong',
        mutate($S, ['red_herrings', 0, 'is_false'], false),
        'red_herrings[0].is_false must be exactly true'],
    ['a wrong lead pointing at the culprit',
        mutate($S, ['red_herrings', 0, 'points_at'], 'harlan'),
        'red_herrings[0].points_at names the culprit'],
    ['a wrong lead pointing at the person holding it',
        mutate($S, ['red_herrings', 0, 'points_at'], 'dot'),
        'red_herrings[0].points_at names the believer themselves'],
    ['a wrong lead with no grounds',
        mutate($S, ['red_herrings', 1, 'because'], ''),
        'red_herrings[1].because is required'],
    ['a wrong lead nothing explains',
        mutate($S, ['red_herrings', 1, 'actually'], ''),
        'red_herrings[1].actually is required'],
    ['a wrong lead nothing disposes of',
        mutate($S, ['red_herrings', 1, 'collapses_on'], 'the_till_key'),
        "red_herrings[1].collapses_on names 'the_till_key', which does not list this herring in kills_herring"],
    ['scenery believing something',
        mutate($S, ['red_herrings', 0, 'believer'], 'cy'),
        "red_herrings[0].believer 'cy' is not a declared character, scenery has no interior to be wrong in"],
    ['a beat opening inside the false calm',
        mutate($S, ['beats', 4, 'at'], 0.6),
        'beats[4].at falls inside the false calm'],
    ['a beat that waits on a later beat',
        mutate($S, ['beats', 1, 'opens_when', 'after'], ['the_till_key']),
        "beats[1].opens_when.after[0] 'the_till_key' is not an EARLIER beat"],
    ['a piece held by nobody in this world',
        mutate($S, ['beats', 2, 'holder'], 'nobody'),
        "beats[2].holder 'nobody' is not a declared character"],
    ['a second role on a character who already has one',
        mutate($S, ['cast', 'protect', 0, 'character'], 'janelle'),
        "cast.protect[0].character 'janelle' already carries special_role 'child'"],
    ['more of the cast protected than can be spared',
        mutate($S, ['cast', 'protect'], array_fill(0, 4, $S['cast']['protect'][0])),
        'cast.protect protects 4 of 6, the cap is 3'],
    ['a wall an overlay did not namespace',
        mutate($S, ['walls', 0, 'key'], 'dale_unaware'),
        "walls[0].key 'dale_unaware' must be namespaced 'story.mill_stairwell.'"],
    ['a key that could write into another story\'s arcs',
        mutate($S, ['key'], 'mill:stairwell'),
        "key 'mill:stairwell' must be a lowercase slug"],
    ['an answer that is not the culprit the overlay declared',
        mutate($S, ['resolution', 'answer'], 'ruth'),
        "resolution.answer 'ruth' is not the culprit this overlay declared"],
    ['a guess offered as a solution',
        mutate($S, ['resolution', 'requires_beats'], []),
        'resolution.requires_beats is required and must be non-empty, a guess is not a solution'],
    ['a wrong accusation that ends the story',
        mutate($S, ['resolution', 'on_wrong', 'closes'], true),
        'resolution.on_wrong.closes must be false, a wrong accusation is a beat, never an ending'],
    ['a story that would explain the light in the mill',
        mutate($S, ['resolution', 'never'], []),
        "resolution.never must contain 'mystery.rumor'"],
    ['a false calm the curve is not flat across',
        mutate($S, ['snake', 'false_calm'], [0.35, 0.72]),
        'snake.false_calm starts at intensity 0.8'],
    ['a thumb that deletes a kind instead of weighting it',
        mutate($S, ['snake', 'kind_thumb', 'rising', 'rumor'], 0),
        'snake.kind_thumb.rising.rumor must be positive, a thumb never deletes a kind'],
    ['a thumb on a stage the curve never produces',
        mutate($S, ['snake', 'kind_thumb', 'denouement'], ['ease' => 2.0]),
        'snake.kind_thumb.denouement is not a stage the curve produces'],
    ['a victim with no age',
        (function (array $s) { unset($s['cast']['victim']['age']); return $s; })($S),
        'cast.victim.age is required and must be an integer, has null'],
    ['an overlay rated above the world it sits on',
        mutate($S, ['rating_min'], 'explicit'),
        "rating_min 'explicit' is above this world's 'sfw', it would never compose"],
    // The age floor, in the only direction it points.
    ['a node gated above sfw on a twelve-year-old',
        mutate($S, ['beats', 1, 'rating_min'], 'mature'),
        "beats[1].rating_min gates content above sfw on 'theo', who is a minor"],
    ['a wrong lead gated above sfw on a twelve-year-old',
        mutate(mutate($S, ['red_herrings', 1, 'believer'], 'theo'), ['red_herrings', 1, 'rating_min'], 'mature'),
        "red_herrings[1].rating_min gates content above sfw on 'theo', who is a minor"],
    // An overlay states what a holder can OBSERVE. Harlan's money trouble is
    // rating_min mature in this world and therefore never renders in it.
    ['an overlay restating a rating-gated interior at a lower rating',
        mutate($S, ['beats', 3, 'piece'],
            "Everyone knows the store hasn't cleared its own rent since March and he has told nobody, not even Dot."),
        'restates rating-gated cast.characters.harlan.drives.pull at a lower rating'],
];
foreach ($storyCases as [$name, $mutated, $want]) {
    $msg = err(fn() => xeric_story_validate($mutated, $T, 'story-x.json'));
    ok("story validation rejects $name", str_contains($msg, $want), $msg !== '' ? $msg : 'it was accepted');
}
ok('story: the same beat gated above sfw on an ADULT loads without complaint',
    err(fn() => xeric_story_validate(mutate($S, ['beats', 5, 'rating_min'], 'mature'), $T)) === '');

// -- 11.2 THE CENTRAL CLAIM: closing is a subtraction ------------------------
$composed = xeric_story_compose($T, [$S], $db3);
ok('story: composing a live overlay changes the template it was handed', $composed !== $T);
ok('story: what it composes is still a valid world', err(fn() => xeric_world_validate($composed, 'composed')) === '');
ok('story: the world it was handed was not touched — compose returns a new array',
    xeric_world_character($T, 'theo') === $kid && count($T['knowledge_walls']) === 3
    && count($T['cast']['special_roles']) === 1 && !isset($T['story']));

$storyEpoch = ep('2026-07-28 09:00');
xeric_story_close($S, $db3, $storyEpoch);
ok('story: after it closes, composing again is === the untouched template',
    xeric_story_compose($T, [$S], $db3) === $T);
ok('story: a closed overlay leaves its residue in memory and one event, and nothing else',
    xeric_memories_count($db3, 'harlan') === 1 && xeric_memories_count($db3, 'theo') === 1
    && xeric_events_count($db3) === 1
    && str_contains(xeric_memories_for($db3, 'theo')[0]['text'], 'believed him about the chair'));
ok('story: closing twice does not give the town the same Tuesday twice', (function () use ($S, $db3, $storyEpoch) {
    $before = xeric_memories_count($db3) . '/' . xeric_events_count($db3);
    xeric_story_close($S, $db3, $storyEpoch + 7200);
    return $before === xeric_memories_count($db3) . '/' . xeric_events_count($db3)
        && xeric_story_state($S, $db3)['closed'] === $storyEpoch;
})());
ok('story: the close is one arc row, and the world reads it as closed',
    xeric_arc_get($db3, xeric_arc_world(), 'story:mill_stairwell:closed') === (string)$storyEpoch
    && xeric_story_state($S, $db3)['live'] === false
    && xeric_story_active([$S], $db3, $T) === []);

// -- 11.3 the snake ----------------------------------------------------------
$db3 = $fresh();
ok('story: the false calm multiplies the world\'s own rate by EXACTLY 1.0', (function () use ($S) {
    $worst = 0.0;
    for ($p = 0.50; $p <= 0.7200001; $p += 0.0025) {
        $x = xeric_story_snake($S['snake'], $p);
        if ($x['stage'] !== 'false_calm') return false;
        $worst = max($worst, abs($x['m'] - 1.0));
    }
    return $worst === 0.0;
})());
ok('story: the stage is derived from the curve, and nothing declares it', (function () use ($S) {
    $stage = fn(float $p) => xeric_story_snake($S['snake'], $p)['stage'];
    return $stage(0.02) === 'opening' && $stage(0.20) === 'rising' && $stage(0.45) === 'taper'
        && $stage(0.50) === 'false_calm' && $stage(0.72) === 'false_calm'
        && $stage(0.80) === 'crescendo' && $stage(0.92) === 'crescendo' && $stage(1.00) === 'closing';
})());
ok('story: the band is 0.4x to 1.6x of the world\'s own number, and nothing else moves it',
    round(xeric_story_snake($S['snake'], 0.92)['m'], 12) === 1.6
    && round(xeric_story_snake($S['snake'], 0.0)['m'], 12) === 0.4);
ok('story: a live story modulates sweep_chance and a closed one hands it straight back', (function () use ($T, $S, $db3, $fresh) {
    $base = XERIC_SWEEP_CHANCE;                                   // milldale keeps the engine's own
    $rest = xeric_story_chance($T, [$S], $db3);                   // nothing opened: p = 0
    xeric_story_close($S, $db3, 1);
    $after = xeric_story_chance($T, [$S], $db3);
    return round($rest, 3) === round($base * 0.4, 3) && $after === $base
        && xeric_story_chance($T, [], $db3) === $base;
})());
ok('story: a thumb re-weights a kind the world armed and never adds one it did not', (function () use ($S, $db3, $fresh) {
    $db = $fresh();
    xeric_story_open($S, $db, 'the_word_gets_around', 100);       // p = 1/6 → rising
    $kinds  = ['rumor' => ['key' => 'rumor', 'weight' => 1.0], 'ordinary' => ['key' => 'ordinary', 'weight' => 2.0]];
    $thumbed = xeric_story_thumb($kinds, [$S], $db);
    return $thumbed['rumor']['weight'] === 2.0                    // rising: rumor 2.0
        && $thumbed['ordinary']['weight'] === 2.0                 // untouched
        && !isset($thumbed['ease']) && count($thumbed) === 2;
})());

// -- 11.4 state: beats, spills, herrings, wrong answers ----------------------
$db3  = $fresh();
$hour = ep('2026-07-28 09:00');

ok('story: everything starts locked and live, and the state machine is one read',
    xeric_story_state($S, $db3)['beats'] === [
        'the_word_gets_around' => 'locked', 'the_chair' => 'locked', 'the_locked_gate' => 'locked',
        'the_ledger_of_lunches' => 'locked', 'the_hospital_run' => 'locked', 'the_till_key' => 'locked',
    ]
    && xeric_story_state($S, $db3)['live'] === true
    && xeric_story_state($S, $db3)['herrings'] === ['the_reds_cap_at_the_mill' => 'live', 'it_was_only_kids' => 'live']);
ok('story: the inciting hour is owed to the world and is not rolled for',
    (xeric_story_due($S, $db3, $hour)['key'] ?? '') === 'the_word_gets_around');
ok('story: a held beat is not owed as an event',
    (function () use ($S, $db3, $hour) {
        xeric_story_fire($S, $db3, 'the_word_gets_around', $hour);
        return xeric_story_due($S, $db3, $hour + 99 * 3600) === null;
    })());
ok('story: opening a beat is idempotent — it never moves the dwell everything after is measured against',
    xeric_story_open($S, $db3, 'the_chair', $hour + 3600) === true
    && xeric_story_open($S, $db3, 'the_chair', $hour + 9999) === false
    && xeric_story_state($S, $db3)['opened_at']['the_chair'] === $hour + 3600);
ok('story: a beat waits on the beats before it having been SPILLED, and on world hours',
    (function () use ($S, $db3, $hour) {
        $gate  = xeric_story_beat($S, 'the_locked_gate');
        $early = xeric_story_ready($S, $db3, $gate, $hour + 3600);          // the_chair is open, not spilled
        xeric_story_spill($S, $db3, 'the_chair', $hour + 3600);
        return $early === false
            && xeric_story_ready($S, $db3, $gate, $hour + 2 * 3600) === false      // 18 hours not passed
            && xeric_story_ready($S, $db3, $gate, $hour + 19 * 3600) === true;
    })());
ok('story: telling you is a memory on the TELLER, and nobody else\'s',
    xeric_memories_count($db3, 'theo') === 1 && xeric_memories_count($db3, 'walt') === 0
    && xeric_memories_count($db3, 'ruth') === 0
    && xeric_memories_for($db3, 'theo')[0]['source'] === 'spill'
    && xeric_memories_for($db3, 'theo')[0]['meta'] === ['story' => 'mill_stairwell', 'beat' => 'the_chair', 'to' => 'user']);
ok('story: it is written once, however many times the state machine is asked',
    xeric_story_spill($S, $db3, 'the_chair', $hour + 5 * 3600)['spilled'] === false
    && xeric_memories_count($db3, 'theo') === 1);
ok('story: the beat that kills a wrong lead kills it, and the lead stops composing',
    xeric_story_state($S, $db3)['herrings']['it_was_only_kids'] === 'collapsed'
    && xeric_story_state($S, $db3)['herrings']['the_reds_cap_at_the_mill'] === 'live'
    && !str_contains(json_encode(xeric_story_compose($T, [$S], $db3)['story']['lines']['ruth'] ?? []), 'nothing here to solve'));
ok('story: progress is beats, never the calendar',
    (function () use ($S, $db3, $hour) {
        $p = xeric_story_progress($S, $db3, $hour + 3 * 3600);
        $later = xeric_story_progress($S, $db3, $hour + 3 * 3600 + 86400 * 30);
        return $p['opened'] === 2 && $p['total'] === 6 && $p['p'] > 2 / 6 && $p['p'] < 3 / 6
            && $later['opened'] === 2 && $later['p'] < 3 / 6;
    })());

ok('story: naming the right man before it can be shown does nothing at all', (function () use ($S, $db3, $hour) {
    $r = xeric_story_resolve($S, $db3, 'harlan', $hour, ['to' => 'dot']);
    return $r['right'] === true && $r['closed'] === false && $r['wrong'] === 0
        && str_contains($r['why'], 'a guess is not a solution')
        && xeric_story_state($S, $db3)['live'] === true;
})());
ok('story: naming the wrong man costs something and ends nothing', (function () use ($S, $db3, $hour) {
    $r = xeric_story_resolve($S, $db3, 'pastor_dale', $hour, ['to' => 'dot']);
    return $r['right'] === false && $r['closed'] === false && $r['wrong'] === 1
        && $r['costs'] === 'the one he named goes short with him for a day and says why, once'
        && xeric_arc_int($db3, xeric_arc_world(), 'story:mill_stairwell:wrong') === 1
        && xeric_story_state($S, $db3)['live'] === true;
})());
ok('story: an accusation said to somebody who would not carry it is not an accusation',
    xeric_story_resolve($S, $db3, 'pastor_dale', $hour, ['to' => 'janelle'])['wrong'] === 1);

// -- 11.5 composition: walls, protections, pieces, beliefs -------------------
$db3 = $fresh();
$C   = xeric_story_compose($T, [$S], $db3);

ok('story: a protection becomes a special role the sweeps already read',
    (xeric_sweep_protected($C)['pastor_dale'] ?? '') === 'who was on the landing at the mill'
    && !isset(xeric_sweep_protected($T)['pastor_dale']));
ok('story: its wall applies to the man it protects and to nobody else',
    in_array('story.mill_stairwell.dale_unaware',
        array_column(xeric_viewer_walls($C, xeric_viewer($C, ['handle' => 'pastor_dale'])), 'key'), true)
    && !in_array('story.mill_stairwell.dale_unaware',
        array_column(xeric_viewer_walls($C, xeric_viewer($C, ['handle' => 'dot'])), 'key'), true));
ok('story: a piece composes as a secret on its holder, not gossip, gated by trust',
    (function () use ($C, $T) {
        $ruth = xeric_world_character($C, 'ruth');
        $was  = xeric_world_character($T, 'ruth');
        $new  = array_slice((array)$ruth['secrets'], count((array)$was['secrets']));
        return count($new) === 1 && $new[0]['gossip_grade'] === false && $new[0]['trust_gate'] === 5
            && str_contains($new[0]['text'], 'who held the keys to the mill now');
    })());
// Three of the five pieces are already in this world, word for word — that is
// the design justifying itself. The overlay decides when they come down, and
// says nothing to the model that the dossier had already said.
ok('story: a piece the world already had is not said to the model twice',
    count((array)xeric_world_character($C, 'theo')['secrets']) === 1
    && count((array)xeric_world_character($C, 'dot')['secrets']) === 1
    && count((array)xeric_world_character($C, 'harlan')['secrets']) === 2);
ok('story: a locked beat and an open one are the two states of one sentence', (function () use ($S, $T, $db3, $hour) {
    $locked = xeric_story_lines(xeric_story_compose($T, [$S], $db3), 'theo');
    xeric_story_open($S, $db3, 'the_chair', $hour);
    $open   = xeric_story_lines(xeric_story_compose($T, [$S], $db3), 'theo');
    return count($locked) === 1 && count($open) === 1
        && str_starts_with($locked[0], 'You do not bring this up.')
        && str_starts_with($open[0], 'If he asks you about the mill');
})());
ok('story: a believer is told what she is sure of and never that she is wrong', (function () use ($C) {
    $dot = implode(' ', xeric_story_lines($C, 'dot'));
    return str_contains($dot, 'You are sure of this: Pastor Dale\'s car was down by the mill')
        && str_contains($dot, 'She is not guessing and she does not guess.')
        && str_contains($dot, 'You would say so if it came up.')
        // Tell a model its character is mistaken and you get hedging, and hedging
        // is the death of a wrong lead. She is certain, because the player has to
        // believe her: `actually` and `is_false` are engine-side and stay there.
        && !str_contains($dot, 'forty minutes the other way')
        && !str_contains($dot, 'county hospital') && !str_contains($dot, 'mistaken');
})());
ok('story: nothing the narrator alone may read is composed anywhere', (function () use ($C, $S) {
    $all = json_encode($C, JSON_UNESCAPED_UNICODE);
    return !str_contains($all, $S['truth'])
        && !str_contains($all, $S['red_herrings'][0]['actually'])
        && !str_contains($all, $S['red_herrings'][1]['actually'])
        && !str_contains($all, '"is_false"');
})());
ok('story: what is composed carries no number that ticks — the system prompt stays cacheable',
    !isset($C['story']['p']) && !isset($C['story']['stage'])
    && array_keys($C['story']) === ['keys', 'lines']);
ok('story: the holder\'s own prompt carries the piece and his line, and the clock still is not in it',
    (function () use ($C, $db3, $now) {
        $sys = xeric_prompt_system($C, $db3, 'ruth', 'sfw');
        return str_contains($sys, 'who held the keys to the mill now')
            && !str_contains($sys, 'RIGHT NOW');
    })());

// -- 11.6 the twelve-year-old is why this story is solvable ------------------
//
// Positive first, because it is the half that is easy to break: he holds a
// piece, he opens it in an ordinary conversation, he is in the room and in the
// events like anybody else, and the accusation cannot be shown without him.
$db3 = $fresh();
$evening = ep('2026-07-29 16:00');                                   // Wednesday, the back booth
xeric_story_fire($S, $db3, 'the_word_gets_around', $evening);
xeric_arc_set($db3, 'theo', 'trust', 4);

$told = xeric_story_observe($S, $db3, 'theo',
    'I got through the gate at the mill in June and there was a folding chair set up in the fourth-floor stairwell, facing the door, I told you.',
    $evening + 13 * 3600, ['asked' => 'What do you know about the mill?']);

ok('story age: the child opens his own beat by being asked, and tells you in the same turn',
    $told['opened'] === ['the_chair'] && $told['spilled'] === ['the_chair']);
ok('story age: the piece a twelve-year-old holds is what kills a red herring',
    $told['collapsed'] === ['it_was_only_kids']
    && str_starts_with($told['said'][0] ?? '', 'It was not kids that night.'));
ok('story age: he remembers telling you, in his own words, in his own memories',
    xeric_memories_count($db3, 'theo') === 1
    && str_contains(xeric_memories_for($db3, 'theo')[0]['text'], 'Walt did not laugh'));
ok('story age: nothing keeps him out of the hour, the room or the conversation', (function () use ($T, $S, $db3, $evening) {
    $C   = xeric_story_compose($T, [$S], $db3);
    $now = xeric_world_now($C, $evening);
    $who = xeric_world_who_is_where($C, $now);
    $his = implode("\n", array_column(xeric_prompt_build($C, $db3, 'theo', $now), 'content'));
    $inAnHour = false;
    foreach (xeric_sweep_groups($C, $now) as $g) {
        if (in_array('theo', (array)$g['handles'], true)) $inAnHour = true;
    }
    return !array_key_exists('theo', xeric_sweep_protected($C))              // no wall on the child
        && in_array('theo', xeric_world_who_is_at($who, 'bluebird'), true)   // in the room
        && $inAnHour                                                          // and in an offscreen hour
        && str_starts_with($his, 'YOU ARE THEO VANCE')                       // answerable
        && str_contains($his, 'fourth-floor stairwell')                      // holding his piece
        && str_starts_with(xeric_story_lines($C, 'theo')[0] ?? '', 'If he asks you about the mill');
})());
ok('story age: his material is sfw in this world and in any other', (function () use ($T, $S, $db3) {
    $explicit = mutate($T, ['meta', 'rating'], 'explicit');
    $C        = xeric_story_compose($explicit, [$S], $db3);
    foreach (xeric_world_find_ratings(xeric_world_character($C, 'theo'), 'theo') as [$path, $value]) {
        if (xeric_rating_rank($value) > 0) return false;
    }
    return xeric_story_lines($C, 'theo') !== [];
})());
ok('story age: the story cannot be shown without what the child told you', (function () use ($S, $db3, $evening) {
    $before = xeric_story_resolve($S, $db3, 'harlan', $evening, ['to' => 'harlan']);
    foreach (['the_ledger_of_lunches', 'the_till_key'] as $b) xeric_story_spill($S, $db3, $b, $evening);
    $after  = xeric_story_resolve($S, $db3, 'harlan', $evening, ['to' => 'harlan']);
    return $before['closed'] === false && $before['right'] === true && $after['closed'] === true;
})());
ok('story age: and when it closes, the child is in the residue like everybody else',
    xeric_story_state($S, $db3)['live'] === false
    && str_contains(implode(' ', array_column(xeric_memories_for($db3, 'theo'), 'text')), 'Somebody finally believed him'));

// -- 11.7 the shelf ----------------------------------------------------------
ok('story: the shelf lists a closed story as closed and never spoils a live one', (function () use ($storyDir, $db3, $S) {
    $shelf = xeric_story_shelf($storyDir, $db3);
    $mill  = null;
    foreach ($shelf as $row) if ($row['key'] === 'mill_stairwell') $mill = $row;
    $json = json_encode($shelf);
    return count($shelf) === 2 && $mill !== null && $mill['live'] === false
        && $mill['logline'] === $S['logline'] && str_contains($mill['world_keeps'], 'Beck Hardware opens at seven')
        && !str_contains($json, $S['truth']) && !str_contains($json, 'his father\'s till key on his ring');
})());

// ---------------------------------------------------------------------------
// 12. And the prompt renders it — the surface a beat and a wrong lead reach a
//     conversation through.
//
// Section 11 asserts that story.php COMPOSES lines into the template. This is
// the other half of that contract: prompt.php reads them into the speaker's own
// static block. Four things are load-bearing and each has a failure that is
// invisible without it —
//
//   1. HERS AND NOBODY ELSE'S. A block keyed by handle that leaked one handle's
//      line into another's prompt would hand every character the whole mystery.
//   2. THE TWO STATES OF ONE SENTENCE. Locked reads "you do not bring this up";
//      open reads "you tell him the whole thing". If the prompt does not move,
//      opening a beat changed nothing anybody can hear.
//   3. NOTHING SAYS SHE IS WRONG. `is_false`, `actually` and `truth` must not be
//      anywhere near a prompt — a model told its character is mistaken hedges,
//      and a hedged wrong lead is not a wrong lead.
//   4. STATIC, AND STILL BYTE-STABLE. The block is in the system message, so two
//      calls an hour apart must return the same bytes or the cache is gone.
// ---------------------------------------------------------------------------

// Its own database: the one above has this story CLOSED on it, and a closed
// story composes nothing, which is section 11's whole first assertion.
$promptDb = sys_get_temp_dir() . '/xeric-story-prompt-' . getmypid() . '.db';
foreach ([$promptDb, $promptDb . '-wal', $promptDb . '-shm'] as $f) @unlink($f);
$db4 = xeric_state_open($promptDb);
xeric_state_seed($db4, $T);
$C   = xeric_story_compose($T, [$S], $db4);

ok('story prompt: the holder carries her own line while it is locked, in the system message',
    str_contains(xeric_prompt_system($C, $db4, 'theo', 'sfw'), 'You do not bring this up.')
    && str_contains(xeric_prompt_system($C, $db4, 'theo', 'sfw'), 'WHERE YOU STAND'));
ok('story prompt: and nobody else does', (function () use ($C, $db4) {
    foreach (['ruth', 'dot', 'harlan', 'pastor_dale', 'janelle'] as $h) {
        if (str_contains(xeric_prompt_system($C, $db4, $h, 'sfw'), 'You do not bring this up.')) return false;
    }
    return true;
})());
ok('story prompt: the wrong lead is in the believer\'s block, stated as a certainty',
    str_contains(xeric_prompt_system($C, $db4, 'dot', 'sfw'), 'You are sure of this:')
    && str_contains(xeric_prompt_system($C, $db4, 'dot', 'sfw'), 'You would say so if it came up.'));
ok('story prompt: and nothing anywhere tells anybody it is wrong', (function () use ($C, $db4, $S) {
    // The VALUES, and the field names that would only appear if the overlay
    // itself had been handed over. Not the bare word "actually" — it is ordinary
    // English and it is in the fixture's own prose.
    $needles = [$S['truth'], $S['red_herrings'][0]['actually'], $S['red_herrings'][1]['actually'],
                'is_false', 'known_false_to', 'red herring', 'collapses_on'];
    foreach (['theo', 'ruth', 'dot', 'harlan', 'pastor_dale', 'janelle'] as $h) {
        if (leaks(xeric_prompt_system($C, $db4, $h, 'sfw'), $needles) !== []) return false;
    }
    return true;
})());
ok('story prompt: opening the beat moves the sentence, which is the only thing that does',
    (function () use ($T, $S, $db4, $evening) {
        $locked = xeric_prompt_system(xeric_story_compose($T, [$S], $db4), $db4, 'theo', 'sfw');
        xeric_story_open($S, $db4, 'the_chair', $evening);
        $open   = xeric_prompt_system(xeric_story_compose($T, [$S], $db4), $db4, 'theo', 'sfw');
        return str_contains($locked, 'You do not bring this up.')
            && !str_contains($open, 'You do not bring this up.')
            && str_contains($open, 'you tell him the whole thing');
    })());
ok('story prompt: it is in the STATIC half and the clock is not — two calls an hour apart, same bytes',
    (function () use ($C, $db4) {
        $a = xeric_prompt_build($C, $db4, 'dot', xeric_world_now($C, ep('2026-07-30 20:15')));
        $b = xeric_prompt_build($C, $db4, 'dot', xeric_world_now($C, ep('2026-07-30 21:15')));
        $sysA = xeric_prompt_system_of($a);
        $tailA = (string)$a[count($a) - 1]['content'];
        return $sysA === xeric_prompt_system_of($b)
            && str_contains($sysA, 'You are sure of this:')
            && !str_contains($tailA, 'You are sure of this:')
            && $tailA !== (string)$b[count($b) - 1]['content'];
    })());
ok('story prompt: a world carrying no story has no such block at all, and is untouched',
    !str_contains(xeric_prompt_system($T, $db4, 'dot', 'sfw'), 'WHERE YOU STAND')
    && xeric_story_compose($T, [], $db4) === $T);

$db4 = null;
$db3 = null;
foreach ([$promptDb, $promptDb . '-wal', $promptDb . '-shm'] as $f) @unlink($f);
foreach ([$storyDb, $storyDb . '-wal', $storyDb . '-shm'] as $f) @unlink($f);
foreach (glob($storyDir . '/*.json') ?: [] as $f) @unlink($f);
@rmdir($storyDir);

// ---------------------------------------------------------------------------
// Travel — the player's body, and what it costs to move it (travel.php)
// ---------------------------------------------------------------------------
//
// Two things here are tested by POSITION rather than by presence, for the same
// reason the bible is: a trip must move the clock by EXACTLY its minutes, and
// the room line it produces must be in the last user message. A trip that
// rounded time and a room line that got hoisted into the system prompt would
// both pass a test that only asked whether the feature worked.

$travelDb = sys_get_temp_dir() . '/xeric-travel-test-' . getmypid() . '.db';
foreach ([$travelDb, $travelDb . '-wal', $travelDb . '-shm'] as $f) @unlink($f);
$db5 = xeric_state_open($travelDb);
xeric_state_migrate($db5);

// -- geometry ---------------------------------------------------------------
ok('travel: a place reads its coordinates', xeric_travel_at($T, 'bluebird') === ['x' => 42.0, 'y' => 55.0]);
ok('travel: the pair form is accepted too — a model asked for two numbers gives two shapes',
    xeric_travel_at(mutate($T, ['places', 0, 'at'], [42, 55]), 'bluebird') === ['x' => 42.0, 'y' => 55.0]);
ok('travel: garbage is nowhere, NOT the origin — half a town must not share an address',
    xeric_travel_at(mutate($T, ['places', 0, 'at'], ['x' => 'yes', 'y' => null]), 'bluebird') === null
    && xeric_travel_at(mutate($T, ['places', 0, 'at'], 'over there'), 'bluebird') === null
    && xeric_travel_at($T, 'no_such_place') === null);
ok('travel: coordinates off the grid are pulled back onto it',
    xeric_travel_at(mutate($T, ['places', 0, 'at'], ['x' => 400, 'y' => -9]), 'bluebird') === ['x' => 100.0, 'y' => 0.0]);

// -- what it costs ----------------------------------------------------------
ok('travel: going nowhere costs nothing', xeric_travel_minutes($T, 'bluebird', 'bluebird') === 0
    && xeric_travel_minutes($T, null, null) === 0);
ok('travel: two doors down still costs the floor — nobody teleports across a street',
    xeric_travel_minutes($T, 'bluebird', 'beck_hardware') === XERIC_TRAVEL_MIN);
ok('travel: the mill is out past the tracks and priced like it',
    xeric_travel_minutes($T, 'bluebird', 'the_mill') === 6
    && xeric_travel_minutes($T, 'first_lutheran', 'the_mill') === 9);
ok('travel: distance is symmetric',
    xeric_travel_minutes($T, 'first_lutheran', 'the_mill') === xeric_travel_minutes($T, 'the_mill', 'first_lutheran'));
ok('travel: your own time is a position, and it costs to leave and to come back',
    xeric_travel_minutes($T, null, 'the_mill') > 0 && xeric_travel_minutes($T, 'the_mill', null) > 0);

$flat = $T;
foreach ($flat['places'] as $i => $_) unset($flat['places'][$i]['at']);
ok('travel: a world with no map is FLAT, not free — the one rounding that would undo the feature',
    !xeric_travel_mapped($flat) && xeric_travel_mapped($T)
    && xeric_travel_minutes($flat, 'bluebird', 'the_mill') === XERIC_TRAVEL_UNKNOWN
    && xeric_travel_minutes($flat, null, 'bluebird') === XERIC_TRAVEL_UNKNOWN);
$huge = mutate($T, ['setting', 'travel', 'minutes_across'], 99999);
ok('travel: a template that claims the world is a thousand hours across does not strand anybody',
    xeric_travel_across($huge) === XERIC_TRAVEL_MAX
    && xeric_travel_minutes($huge, 'bluebird', 'the_mill') <= XERIC_TRAVEL_MAX
    && xeric_travel_minutes($huge, 'bluebird', 'the_mill') > xeric_travel_minutes($T, 'bluebird', 'the_mill'));

// -- the body ---------------------------------------------------------------
ok('travel: a player starts nowhere in particular', xeric_player_where($T, $db5) === null);
xeric_player_move($db5, 'bluebird', 123);
ok('travel: and can be put somewhere without a trip', xeric_player_where($T, $db5) === 'bluebird'
    && xeric_player_since($db5) === 123);
xeric_player_move($db5, 'a_place_that_was_rerolled_away');
ok('travel: a room that stopped existing under somebody leaves them nowhere, not in a ghost',
    xeric_player_where($T, $db5) === null && xeric_player_where_raw($db5) === 'a_place_that_was_rerolled_away');
xeric_player_move($db5, null);

// -- the trip ---------------------------------------------------------------
$before = (int)xeric_clock_now($db5, $T)['epoch'];
$trip   = xeric_travel_go($T, $db5, 'the_mill');
ok('travel: a trip burns EXACTLY its minutes off the world clock',
    $trip['ok'] && $trip['minutes'] > 0
    && (int)$trip['now']['epoch'] - $before === $trip['minutes'] * 60,
    (string)((int)$trip['now']['epoch'] - $before));
ok('travel: and leaves the player standing there', xeric_player_where($T, $db5) === 'the_mill'
    && $trip['from'] === null && $trip['to'] === 'the_mill');
ok('travel: you may walk to a chained gate, and it tells you it is chained',
    $trip['open'] === false && $trip['who'] === []);

$at = (int)xeric_clock_now($db5, $T)['epoch'];
$no = xeric_travel_go($T, $db5, 'the_mill');
ok('travel: going where you already are is refused, and the clock does not move for it',
    !$no['ok'] && str_contains((string)$no['error'], 'already')
    && (int)xeric_clock_now($db5, $T)['epoch'] === $at
    && xeric_player_where($T, $db5) === 'the_mill');

$nope = xeric_travel_go($T, $db5, 'the_saloon_on_mars');
ok('travel: a place that is not in the world costs nothing and moves nobody',
    !$nope['ok'] && (int)xeric_clock_now($db5, $T)['epoch'] === $at
    && xeric_player_where($T, $db5) === 'the_mill');

$home = xeric_travel_go($T, $db5, null);
ok('travel: leaving the map is a trip like any other', $home['ok'] && $home['minutes'] > 0
    && $home['to'] === null && xeric_player_where($T, $db5) === null);

// -- the read model ---------------------------------------------------------
xeric_player_move($db5, 'bluebird');
$map = xeric_travel_map($T, $db5);
$byKey = [];
foreach ($map['places'] as $p) $byKey[$p['key']] = $p;
ok('travel: the map says where you are, and prices everywhere else from there',
    $map['you']['where'] === 'bluebird' && $map['mapped'] === true
    && $byKey['bluebird']['here'] === true && $byKey['bluebird']['minutes'] === 0
    && $byKey['the_mill']['minutes'] === 6 && $byKey['the_mill']['open'] === false);
ok('travel: and who is standing in each room, from the same read the prompt uses',
    count($map['places']) === count($T['places'])
    && array_column($byKey['the_mill']['who'], 'handle') === []);
ok('travel: a flat world says so in one boolean rather than making a client count nulls',
    xeric_travel_map($flat, $db5)['mapped'] === false
    && xeric_travel_map($flat, $db5)['places'][0]['at'] === null);

// -- the room line ----------------------------------------------------------
// Ruth is in the church basement on a Wednesday morning. Walk in on her and the
// prompt has to say so; text her from the diner and it must not.
$wedAmT = xeric_world_now($T, ep('2026-07-29 10:00'));
$inRoom = xeric_prompt_now_block($T, 'ruth', $wedAmT, '', null, 'first_lutheran');
$phone  = xeric_prompt_now_block($T, 'ruth', $wedAmT, '', null, 'bluebird');
ok('travel: a character is told when the player is standing in front of them',
    str_contains($inRoom, 'Walt is here, in the room with you')
    && !str_contains($phone, 'in the room with you')
    && !str_contains(xeric_prompt_now_block($T, 'ruth', $wedAmT, '', null, null), 'in the room with you'));
ok('travel: and a wall over the room takes that with it — fail closed, both halves or neither',
    !str_contains(
        xeric_prompt_now_block($T, 'ruth', $wedAmT, '', [['hidden' => ['schedules']]], 'first_lutheran'),
        'in the room with you')
    && !str_contains(
        xeric_prompt_now_block($T, 'ruth', $wedAmT, '', [['hidden' => ['places.first_lutheran']]], 'first_lutheran'),
        'in the room with you'));

xeric_player_move($db5, 'first_lutheran');
$built = xeric_prompt_build($T, $db5, 'ruth', $wedAmT);
ok('travel: the room line rides the LAST USER MESSAGE, never the system prompt — the cache is the product',
    str_contains((string)$built[count($built) - 1]['content'], 'in the room with you')
    && !str_contains(xeric_prompt_system_of($built), 'in the room with you'));

$db5 = null;
foreach ([$travelDb, $travelDb . '-wal', $travelDb . '-shm'] as $f) @unlink($f);

// ---------------------------------------------------------------------------
// Pause — stopping a world, and picking it up on the same second
// ---------------------------------------------------------------------------
//
// The whole feature is one assertion: resume lands EXACTLY where pause left off.
// Near enough is a rounding error with a button on it, and the failure is
// invisible — a world that comes back four seconds late looks fine and has
// silently lost four seconds every time anybody has ever closed the tab.

$pauseDb = sys_get_temp_dir() . '/xeric-pause-test-' . getmypid() . '.db';
foreach ([$pauseDb, $pauseDb . '-wal', $pauseDb . '-shm'] as $f) @unlink($f);
$dbP = xeric_state_open($pauseDb);

$P = 1000000;                    // a stop, in real time
$was = xeric_clock_epoch($dbP, $P);

ok('pause: a world starts running', !xeric_clock_is_paused($dbP) && xeric_clock_paused_at($dbP) === 0);
ok('pause: stopping it says so, and says it once',
    xeric_clock_pause($dbP, $P) && xeric_clock_is_paused($dbP) && !xeric_clock_pause($dbP, $P + 500));
ok('pause: A STOPPED WORLD DOES NOT MOVE, however long you are away',
    xeric_clock_epoch($dbP, $P + 60) === $was
    && xeric_clock_epoch($dbP, $P + 86400 * 14) === $was);
ok('pause: and re-stamping is refused — the second pause must not eat the fortnight',
    xeric_clock_paused_at($dbP) === $P);
ok('pause: a stopped world cannot be fast-forwarded, and says why',
    str_contains(err(fn() => xeric_clock_advance($dbP, 3600, $T)), 'stopped')
    && xeric_clock_epoch($dbP, $P + 99) === $was);

$away = xeric_clock_resume($dbP, $P + 86400 * 14);
ok('pause: RESUME LANDS ON THE EXACT SECOND, a fortnight later',
    $away === 86400 * 14
    && xeric_clock_epoch($dbP, $P + 86400 * 14) === $was
    && !xeric_clock_is_paused($dbP),
    (string)(xeric_clock_epoch($dbP, $P + 86400 * 14) - $was));
ok('pause: and a second after that, it is a second later',
    xeric_clock_epoch($dbP, $P + 86400 * 14 + 1) === $was + 1);
ok('pause: the offset went negative, which is what a world behind real time IS',
    xeric_clock_offset($dbP) === -86400 * 14);
ok('pause: resuming a running world is a no-op, not a jump',
    xeric_clock_resume($dbP, $P + 86400 * 14) === 0
    && xeric_clock_epoch($dbP, $P + 86400 * 14) === $was);

// Skipping still works afterwards, and from the resumed position — the two
// mechanisms share one offset and a bug in either shows up as the other drifting.
xeric_clock_advance($dbP, 3600, $T, $P + 86400 * 14);
ok('pause: and the time control still works, from where the world actually is',
    xeric_clock_epoch($dbP, $P + 86400 * 14) === $was + 3600);

$dbP = null;
foreach ([$pauseDb, $pauseDb . '-wal', $pauseDb . '-shm'] as $f) @unlink($f);

// ---------------------------------------------------------------------------
// Death — dying, and whether it can be undone (death.php)
// ---------------------------------------------------------------------------
//
// The assertions that matter here are the NEGATIVE ones. That somebody can be
// killed is one line of SQL; that killing them does not delete them, does not
// take their memories, does not empty their thread and does not silence the
// people who knew them is the whole design, and every one of those would fail
// invisibly. So is the freeze: a `permanent` that can be switched off after the
// fact is decoration, and nothing about the happy path would notice.

$deathDb = sys_get_temp_dir() . '/xeric-death-test-' . getmypid() . '.db';
foreach ([$deathDb, $deathDb . '-wal', $deathDb . '-shm'] as $f) @unlink($f);
$db6 = xeric_state_open($deathDb);

$P = mutate($T, ['deaths', 'mode'], 'permanent');
$noon = xeric_world_now($T, ep('2026-07-29 12:00'));

// -- the setting -------------------------------------------------------------
ok('death: a world says nothing and death can be undone',
    xeric_death_mode(drop($T, 'deaths'), $db6) === XERIC_DEATH_REVIVABLE
    && xeric_death_mode($T, $db6) === XERIC_DEATH_REVIVABLE
    && xeric_death_mode($P, $db6) === XERIC_DEATH_PERMANENT);
ok('death: AND A GARBLED SETTING IS REVIVABLE — the one dial that fails toward recovery',
    xeric_death_mode(mutate($T, ['deaths', 'mode'], 'PERMANANT'), $db6) === XERIC_DEATH_REVIVABLE
    && xeric_death_mode(mutate($T, ['deaths', 'mode'], ['on' => true]), $db6) === XERIC_DEATH_REVIVABLE
    && xeric_death_mode(mutate($T, ['deaths', 'mode'], null), $db6) === XERIC_DEATH_REVIVABLE);
ok('death: and it is still a setting while nobody has died',
    !xeric_death_locked($db6) && xeric_deaths($db6) === []);

// -- dying -------------------------------------------------------------------
$k = xeric_death_kill($T, $db6, 'harlan', (int)$noon['epoch'], 'the truck on the river road');
ok('death: somebody dies, and it is the first thing this world has lost',
    $k['ok'] && $k['first'] && xeric_is_dead($db6, 'harlan'));
ok('death: THEY ARE NOT DELETED — still in the template, still resolvable, still named',
    xeric_world_character($T, 'harlan') !== null
    && xeric_world_name($T, 'harlan') !== 'harlan'
    && xeric_deaths($db6)['harlan']['how'] === 'the truck on the river road');
ok('death: nobody dies twice, and a name nobody answers to is refused',
    !xeric_death_kill($T, $db6, 'harlan', (int)$noon['epoch'])['ok']
    && !xeric_death_kill($T, $db6, 'a_person_who_never_was', (int)$noon['epoch'])['ok']);

// -- what it changes ---------------------------------------------------------
ok('death: out of every room at once — one read, so nothing can forget',
    !array_key_exists('harlan', xeric_world_who_is_where($T, $noon, xeric_dead_handles($db6)))
    && array_key_exists('harlan', xeric_world_who_is_where($T, $noon)));
ok('death: the living are told, in the volatile tail, and told to use the past tense',
    str_contains(xeric_prompt_now_block($T, 'dot', $noon, '', null, null, xeric_deaths($db6)), 'past tense')
    && !str_contains(xeric_prompt_system($T, $db6, 'dot', 'sfw'), 'past tense'));
ok('death: and the line does not tell somebody they are dead',
    !str_contains(xeric_death_line($T, xeric_deaths($db6), 'harlan'), 'Dead')
    && str_contains(xeric_death_line($T, xeric_deaths($db6), 'dot'), 'Dead'));
ok('death: and it names nobody as the killer — a mystery is not closed by a prompt',
    (function () use ($T, $db6, $noon) {
        xeric_death_kill($T, $db6, 'janelle', (int)$noon['epoch'], 'found in the water', 'harlan');
        $line = xeric_death_line($T, xeric_deaths($db6), 'dot');
        $ok = !str_contains($line, 'Harlan') || !str_contains($line, 'found in the water');
        xeric_death_revive($T, $db6, 'janelle');
        return $ok && !str_contains($line, 'found in the water');
    })());

// -- coming back -------------------------------------------------------------
ok('death: revival puts them back and takes nothing away',
    xeric_death_revive($T, $db6, 'harlan')['ok']
    && !xeric_is_dead($db6, 'harlan')
    && array_key_exists('harlan', xeric_world_who_is_where($T, $noon, xeric_dead_handles($db6))));
ok('death: reviving somebody who is alive is refused rather than shrugged at',
    !xeric_death_revive($T, $db6, 'harlan')['ok']);

// -- THE FREEZE --------------------------------------------------------------
// The first death copied `revivable` into this database. From here the template
// is not read again, so an author who changes their mind afterwards changes
// nothing — which is the entire meaning of "permanent" being a real setting.
ok('death: THE MODE FROZE AT THE FIRST DEATH — the template no longer decides',
    xeric_death_locked($db6) && xeric_death_mode($P, $db6) === XERIC_DEATH_REVIVABLE);

$permDb = sys_get_temp_dir() . '/xeric-perm-test-' . getmypid() . '.db';
foreach ([$permDb, $permDb . '-wal', $permDb . '-shm'] as $f) @unlink($f);
$db7 = xeric_state_open($permDb);
xeric_death_kill($P, $db7, 'ruth', (int)$noon['epoch'], 'in her sleep, at ninety-one');
ok('death: a permanent world freezes permanent, and nothing takes her back',
    xeric_death_mode($T, $db7) === XERIC_DEATH_PERMANENT
    && !xeric_death_revive($P, $db7, 'ruth')['ok']
    && !xeric_death_revive(drop($T, 'deaths'), $db7, 'ruth')['ok']
    && xeric_is_dead($db7, 'ruth'));
ok('death: and permanence gates coming back, NEVER dying — it is not a rarer world',
    xeric_death_kill($P, $db7, 'dot', (int)$noon['epoch'])['ok']);

// -- the end of the world ----------------------------------------------------
$endDb = sys_get_temp_dir() . '/xeric-end-test-' . getmypid() . '.db';
foreach ([$endDb, $endDb . '-wal', $endDb . '-shm'] as $f) @unlink($f);
$db8 = xeric_state_open($endDb);
$cast = count((array)$T['cast']['characters']);

xeric_death_kill($T, $db8, 'theo', (int)$noon['epoch'], 'the flood');
$boom = xeric_death_catastrophe($T, $db8, (int)$noon['epoch'] + 3600, 'the mill went up');
ok('death: the bomb takes everybody, and leaves an earlier death its own sentence',
    count($boom['killed']) === $cast - 1
    && xeric_death_living($T, $db8) === []
    && xeric_deaths($db8)['theo']['how'] === 'the flood'
    && xeric_deaths($db8)['dot']['how'] === 'the mill went up');
ok('death: and nobody is in any room, because there is nobody',
    xeric_world_who_is_where($T, $noon, xeric_dead_handles($db8)) === []);

$back = xeric_death_restore($T, $db8);
ok('death: the world comes back whole', $back['ok'] && count($back['revived']) === $cast
    && xeric_deaths($db8) === [] && count(xeric_death_living($T, $db8)) === $cast);
ok('death: AND IT IS NOT A REWIND — the clock never moved for any of it',
    (int)xeric_clock_offset($db8) === 0);

ok('death: a permanent world refuses to restore ALL OR NOTHING, never half of it',
    (function () use ($P, $noon) {
        $p = sys_get_temp_dir() . '/xeric-endperm-' . getmypid() . '.db';
        foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
        $d = xeric_state_open($p);
        xeric_death_catastrophe($P, $d, (int)$noon['epoch'], 'the bomb');
        $r = xeric_death_restore($P, $d);
        $left = count(xeric_deaths($d));
        $d = null;
        foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
        return !$r['ok'] && $r['revived'] === [] && $left === count((array)$P['cast']['characters']);
    })());

// -- the world notices -------------------------------------------------------
$noticeDb = sys_get_temp_dir() . '/xeric-notice-test-' . getmypid() . '.db';
foreach ([$noticeDb, $noticeDb . '-wal', $noticeDb . '-shm'] as $f) @unlink($f);
$db9 = xeric_state_open($noticeDb);

xeric_death_kill($T, $db9, 'harlan', (int)$noon['epoch'], 'the truck on the river road');
$ev = xeric_events_recent($db9, 5);
ok('death: the world notices — an hour in the feed and a memory in everybody left',
    count($ev) === 1 && str_contains((string)$ev[0]['title'], 'died')
    && xeric_memories_count($db9) === count(xeric_death_living($T, $db9))
    && str_contains(xeric_memories_for($db9, 'ruth')[0]['text'], 'the truck on the river road'));
ok('death: and the dead carry nothing forward — no memory of their own death',
    xeric_memories_count($db9, 'harlan') === 0);

$boom2 = xeric_death_catastrophe($T, $db9, (int)$noon['epoch'] + 60, 'the mill went up');
ok('death: a catastrophe is ONE hour, not one per body',
    count(xeric_events_recent($db9, 20)) === 2
    && str_contains((string)xeric_events_recent($db9, 1)[0]['title'], 'the day '));

// -- the lights --------------------------------------------------------------
ok('death: the end of the world takes the rooms with it, and they read as dark not shut',
    count(xeric_dark_places($db9)) === count((array)$T['places'])
    && xeric_is_dark($db9, 'bluebird')
    && !xeric_travel_open($T, 'bluebird', xeric_world_now($T, ep('2026-07-29 08:00')), xeric_dark_places($db9))
    && xeric_travel_open($T, 'bluebird', xeric_world_now($T, ep('2026-07-29 08:00'))));
ok('death: and the map says which it is, so a client never has to guess',
    (function () use ($T, $db9) {
        $m = xeric_travel_map($T, $db9);
        foreach ($m['places'] as $p) if ($p['key'] === 'bluebird') return $p['dark'] === true && $p['open'] === false;
        return false;
    })());
xeric_death_restore($T, $db9);
ok('death: and the lights come back on with the people',
    xeric_dark_places($db9) === [] && xeric_travel_map($T, $db9)['places'][0]['dark'] === false);

// -- one death does not close the diner --------------------------------------
$oneDb = sys_get_temp_dir() . '/xeric-onedeath-' . getmypid() . '.db';
foreach ([$oneDb, $oneDb . '-wal', $oneDb . '-shm'] as $f) @unlink($f);
$db10 = xeric_state_open($oneDb);
xeric_death_kill($T, $db10, 'dot', (int)$noon['epoch'], 'quietly, at home');
ok('death: SOMEBODY dying does not darken a single room — only everybody does',
    xeric_dark_places($db10) === []);

// -- a story victim you had been texting -------------------------------------
// The whole point of death being a row: an overlay can now kill a declared
// character, and the template it composes is byte-identical to the one that
// would have been composed if it had not.
// Built off the shipped overlay so this exercises the victim change and nothing
// else: same beats, same walls, same snake, one field different.
$millStory = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/milldale-story.json'), true);
$vicStory  = mutate($millStory, ['cast', 'victim'],
    ['character' => 'janelle', 'found' => 'face down in the millrace']);
$vicDb = sys_get_temp_dir() . '/xeric-victim-' . getmypid() . '.db';
foreach ([$vicDb, $vicDb . '-wal', $vicDb . '-shm'] as $f) @unlink($f);
$db11 = xeric_state_open($vicDb);

ok('story: the shipped overlay still validates, and so does one whose victim is real',
    err(fn() => xeric_story_validate($millStory, $T)) === ''
    && err(fn() => xeric_story_validate($vicStory, $T)) === '',
    err(fn() => xeric_story_validate($vicStory, $T)));
ok('story: a victim who names nobody real is refused by name',
    str_contains(err(fn() => xeric_story_validate(
        mutate($millStory, ['cast', 'victim'], ['character' => 'nobody_here']), $T)),
        'cast.victim.character'));
ok('story: and the victim may not also be the culprit',
    str_contains(err(fn() => xeric_story_validate(
        mutate($millStory, ['cast', 'victim'],
            ['character' => (string)$millStory['cast']['culprit']]), $T)),
        'also the culprit'));
ok('story: a phantom victim still needs a name and an integer age',
    str_contains(err(fn() => xeric_story_validate(
        mutate($millStory, ['cast', 'victim'], ['name' => 'Ellis', 'age' => 'old']), $T)),
        'cast.victim.age'));

$before = count(xeric_events_recent($db11, 20));
$C1 = xeric_story_compose($T, [$vicStory], $db11);
ok('story: composing it kills him, and the town hears',
    xeric_is_dead($db11, 'janelle')
    && count(xeric_events_recent($db11, 20)) === $before + 1
    && xeric_deaths($db11)['janelle']['how'] === 'face down in the millrace');
ok('story: HE IS STILL IN THE CAST — the overlay touched no byte of the template',
    xeric_world_character($C1, 'janelle') !== null
    && count((array)$C1['cast']['characters']) === count((array)$T['cast']['characters']));
$C2 = xeric_story_compose($T, [$vicStory], $db11);
ok('story: and composing it again does nothing — idempotent by refusal, not by a flag',
    count(xeric_events_recent($db11, 20)) === $before + 1 && $C2 === $C1);
ok('story: he is out of every room and cannot be texted, like anybody else who died',
    !array_key_exists('janelle', xeric_world_who_is_where($C1, $noon, xeric_dead_handles($db11))));

// -- the one kind that removes people ----------------------------------------
ok('sweep: mortality is armed by NO world by default — not the fixture, not the forge',
    !array_key_exists('loss', xeric_sweep_kinds_for($T))
    && !in_array('mortality', xeric_sweep_armed($T), true)
    && array_key_exists('loss', xeric_sweep_kinds()));
$lethalT = mutate($T, ['forge', 'armed'], array_merge(xeric_sweep_armed($T), ['mortality']));
ok('sweep: and a world that switches it on gets it, marked lethal',
    !empty(xeric_sweep_kinds_for($lethalT)['loss']['lethal'])
    && empty(xeric_sweep_kinds_for($lethalT)['rumor']['lethal']));
ok('sweep: the body is chosen from OUTSIDE the room — nobody attends hearing they died',
    (function () use ($lethalT) {
        $star = (string)($lethalT['cast']['protagonist']['handle'] ?? '');
        $room = ['ruth', 'dot'];
        $picks = [];
        for ($i = 0; $i < 40; $i++) {
            $p = xeric_sweep_lethal($lethalT, ['handles' => $room], []);
            if ($p !== null) $picks[$p] = true;
        }
        return $picks !== []
            && array_intersect(array_keys($picks), $room) === []
            && ($star === '' || !isset($picks[$star]));
    })());
ok('sweep: and a room with nobody left outside it gets no death at all',
    xeric_sweep_lethal($lethalT, ['handles' => array_column((array)$lethalT['cast']['characters'], 'handle')], [])
        === null);
ok('sweep: the prompt states the death as settled and forbids writing it',
    (function () use ($lethalT, $db10, $noon) {
        $p = xeric_sweep_prompt($lethalT, $db10, $noon, [
            'kind' => xeric_sweep_kinds_for($lethalT)['loss'], 'handles' => ['ruth', 'pastor_dale'],
            'where' => 'first_lutheran', 'on_spine' => false, 'why' => 'they were both there',
            'dying' => 'janelle',
        ]);
        $all = implode("\n", array_column($p, 'content'));
        return str_contains($all, 'is dead') && str_contains($all, 'write the room')
            && str_contains($all, 'Nobody in this room dies in it');
    })());

$db11 = null; $db10 = null; $db9 = null;
foreach ([$noticeDb, $oneDb, $vicDb] as $p) {
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
}

$db8 = null; $db7 = null; $db6 = null;
foreach ([$deathDb, $permDb, $endDb] as $p) {
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
}

// ---------------------------------------------------------------------------

$db2 = null;
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
