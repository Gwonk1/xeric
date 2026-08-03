<?php
/**
 * Xeric — forge tests. `php forge/tests/forge-test.php`, exit 0 on pass.
 *
 * NO NETWORK, NO MODEL. Every pass is driven through the `stub` endpoint seam,
 * which is why that seam exists: the interesting behaviour of this file is what
 * happens when the model is WRONG, and that is not reproducible against a real
 * one.
 *
 * The five things being defended here, four from FORGE.md and one from the
 * punchlist's AGE AND CONSENT section:
 *
 *   1. Surprise-me draws ONE concept. A mixed answer set ("the world stage" +
 *      "found family" + a feed store) is the failure this feature exists to
 *      prevent, so the assertion is not "it filled the gaps" but "every filled
 *      field came from the same row".
 *   2. A world is ALWAYS launchable. Each pass is fed garbage, then an
 *      exception, and must still hand back something the validator accepts.
 *   3. Nothing generated is load-bearing until it validates — so a model that
 *      names a place that does not exist gets corrected, not shipped.
 *   4. The user's workplace is pinned. It survives a model that ignores it.
 *   5. The age floor, which is TWO claims and fails if either one does: a town
 *      has children in it — in the cast, in orbits, on schedules, in the bible
 *      — and a child is never in the desire economy. Half of that section
 *      exists to catch the over-broad fix that quietly empties a world of
 *      everybody under eighteen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../forge.php';
require_once __DIR__ . '/../../engine/renderers/bible.php';   // the walls check below renders one

// THE SHELF IS PINNED EMPTY. The naming gates read the worlds directory to
// keep a new world from reusing an existing one's names, which means an
// unpinned test run would change its answers every time somebody forges a
// world into the repo. Pointed at a directory that does not exist, the gates
// see an empty shelf and every assertion below is about the code, not about
// whatever happens to be on disk. The cross-world section further down builds
// its own shelf, on purpose, and pins back afterward.
xeric_forge_shelf(sys_get_temp_dir() . '/xeric-forge-test-no-shelf-' . getmypid());

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

function validates(array $t): string
{
    return err(fn() => xeric_world_validate($t, 'test'));
}

// ---------------------------------------------------------------------------
// The stubs — three models: a good one, a broken one, a dead one.
// ---------------------------------------------------------------------------

/** A model that answers every pass in the shape the prompts ask for. */
function stub_good(): array
{
    $n = 0;
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use (&$n): array {
        switch ($tag) {
            case 'fill':
                return ['scale' => 'city', 'name' => 'Rae', 'job' => 'night editor',
                        'hours' => '18:00-02:00', 'motivation' => 'mystery', 'rating' => 'mature',
                        'themes' => ['intrigue', 'nightlife', 'money'], 'circle' => 'coworkers'];
            case 'concept':
                return [
                    'name' => 'Ostrander', 'description' => 'A press town that stopped printing and never said so.',
                    'locale' => 'a printing town on a cold river', 'era' => 'present day',
                    'texture' => ['ink and wet paper', 'a siren nobody looks up for'],
                    'canon_rules' => ['The presses run at eleven.', 'Nobody admits to reading the paper.'],
                    'mood_high' => 'reckless — the late edition is wrong on purpose',
                    'mood_low' => 'kind — somebody kept the coffee on',
                    'motifs_dark' => ['a light on in the composing room'],
                    'motifs_light' => ['a stack of papers left free on a step'],
                    'themes' => ['newsroom', 'nightlife'],
                ];
            case 'places':
                return [
                    'workplace' => ['name' => 'the Ostrander Ledger', 'kind' => 'office', 'open' => '09:00',
                                    'close' => '18:00', 'description' => 'Two floors of desks and one working lift.'],
                    'places' => [
                        ['name' => 'the Anchor', 'kind' => 'bar', 'open' => '4pm', 'close' => '2am', 'late' => true, 'alcohol' => true, 'description' => 'Dark, loud, forgiving.'],
                        ['name' => 'Verna\'s', 'kind' => 'diner', 'open' => '05:00', 'close' => '15:00', 'description' => 'Eggs and opinions.'],
                        ['name' => 'the depot', 'kind' => 'station', 'open' => '05:00', 'close' => '23:00', 'description' => 'Concrete and wind.'],
                        ['name' => 'St Brigid', 'kind' => 'church', 'open' => '07:00', 'close' => '19:00', 'description' => 'Cold stone, warm basement.'],
                        ['name' => 'the lockup', 'kind' => 'site', 'open' => '00:00', 'close' => '23:59', 'description' => 'Nobody goes in.'],
                    ],
                ];
            case 'cast':
                $n++;
                return [
                    'display_name' => "Person $n Quill", 'age' => 30 + $n,
                    'one_line' => "The $n th person anybody in town would name.",
                    'appearance' => 'Coat too thin for the weather.',
                    'voice' => 'Short sentences. Longer ones when tired.',
                    'sore_spot' => 'being asked twice', 'jealousy' => 'people who sleep',
                    'self_soothe' => 'walking the long block', 'praise' => 'being told it read well',
                    'tells' => ['taps a pen', 'answers before you finish', 'leaves coats on chairs'],
                    'pull' => 'to be the one who knew first',
                    // Small models answer this with a place key rather than prose.
                    'solace' => 'anchor',
                    // A place key nobody declared: the pass must correct this, not ship it.
                    'work_place' => $n === 2 ? 'a_place_that_does_not_exist' : 'ostrander_ledger',
                    'work_days' => 'weekdays', 'work_from' => '9am', 'work_to' => '18:00',
                    'work_doing' => 'copy, coffee, complaining',
                    'hangout_place' => 'anchor', 'hangout_days' => 'weekends',
                    'hangout_from' => '20:00', 'hangout_to' => '01:00', 'hangout_doing' => 'the usual',
                ];
            case 'seed_events':
                return ['events' => [
                    // `who` as a display name, which is what models actually send.
                    ['title' => 'the retraction nobody printed', 'days_ago' => 9, 'place' => 'anchor',
                     'who' => ['Person 1 Quill'], 'prose' => 'It got argued about and then it got dropped.'],
                    ['title' => 'the late shift that went long', 'days_ago' => 3, 'place' => 'not_a_place',
                     'who' => ['nobody_at_all'], 'prose' => 'Two people and a machine that would not stop.'],
                    ['title' => 'a favour, unmentioned', 'days_ago' => 21, 'place' => 'vernas',
                     'who' => [], 'prose' => 'It has not come up since.'],
                ]];
            case 'seed_memories':
                return ['memories' => ['One thing already known.', 'Another thing already owed.', 'A third, smaller.']];
        }
        return [];
    }];
}

/** A model that returns valid JSON containing nothing anybody asked for. */
function stub_junk(): array
{
    return ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o): array
        => ['sorry' => 'here is your world!', 'places' => 'the diner, the church', 'events' => 'lots']];
}

/** A model that is not there at all. */
function stub_dead(): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o): array {
        throw new RuntimeException('llm: cannot reach stub');
    }];
}

const ANSWERS = [
    'scale' => 'city', 'name' => 'Ada', 'job' => 'tend bar at a hotel',
    'hours' => '16:00-00:00', 'motivation' => 'romance', 'rating' => 'mature',
];

// ---------------------------------------------------------------------------
// The interview, as data
// ---------------------------------------------------------------------------

$iv = xeric_forge_interview();
$keys = xeric_forge_step_keys($iv);

ok('interview.json loads', $iv !== []);
// `around` and `pace` joined the slice on 2026-07-30: quiet hours are a
// property of the PERSON (a night-owl's world must sleep by day), and event
// density is normalised per visit rather than per hour.
// `story_shape` joined on 2026-08-02: pace is how MUCH happens, shape is WHEN —
// the rhythm a plot would be paced against, `none` included and defaulted to.
ok('slice steps are all present (scale, you, motivation, around, pace, centrality, story_shape, rating)',
    $keys === ['scale', 'name', 'job', 'hours', 'motivation', 'around', 'pace', 'centrality',
               'story_shape', 'rating'],
    implode(',', $keys));
ok('interview carries a themes vocabulary', count((array)($iv['themes'] ?? [])) >= 6);

// A concept that cannot answer every step would leave a gap after a ✨ fill.
$incomplete = [];
foreach ((array)$iv['surprise_concepts'] as $c) {
    foreach (array_merge($keys, ['themes', 'circle']) as $k) {
        if (!isset($c['answers'][$k])) $incomplete[] = $c['key'] . '.' . $k;
    }
}
ok('every surprise concept answers every step', $incomplete === [], implode(' ', $incomplete));

// ---------------------------------------------------------------------------
// ✨ Surprise-me draws ONE concept
// ---------------------------------------------------------------------------

/**
 * Which concept rows does this answer set match COMPLETELY?
 *
 * $ignore is for fields the USER answered: those are supposed to survive the
 * fill, so they are not evidence of mixing.
 */
function whole_concepts(array $answers, array $iv, array $ignore = []): array
{
    $hits = [];
    foreach ((array)$iv['surprise_concepts'] as $c) {
        $all = true;
        foreach ((array)$c['answers'] as $k => $v) {
            if (in_array($k, $ignore, true)) continue;
            $got = $answers[$k] ?? null;
            if (is_array($v)) { if ($got !== $v) $all = false; }
            elseif ((string)$got !== (string)$v) $all = false;
        }
        if ($all) $hits[] = (string)$c['key'];
    }
    return $hits;
}

$mixed = 0;
$seenConcepts = [];
for ($i = 0; $i < 40; $i++) {
    $filled = xeric_forge_answers_fill([], $iv);
    $hits = whole_concepts($filled, $iv);
    if ($hits === []) $mixed++;
    foreach ($hits as $h) $seenConcepts[$h] = true;
}
ok('surprise-me from nothing is always one whole concept, never a mix', $mixed === 0, "$mixed of 40 were mixed");
ok('surprise-me actually varies between concepts', count($seenConcepts) > 1, implode(',', array_keys($seenConcepts)));

// Given answers steer the draw AND survive it.
$partial = xeric_forge_answers_fill(['scale' => 'small_town', 'name' => 'Walt'], $iv);
ok('a given answer is never overwritten', $partial['name'] === 'Walt' && $partial['scale'] === 'small_town');
ok('the drawn concept agrees with the given answer',
    in_array($partial['motivation'] ?? '', ['company', 'redemption'], true), (string)($partial['motivation'] ?? ''));
$hits = whole_concepts($partial, $iv, ['name']);
ok('a partly-answered fill still comes from one concept', $hits !== [], implode(',', array_keys($partial)));

// With an endpoint the model gets to answer — and its answer is used whole.
$filledByModel = xeric_forge_answers_fill([], $iv, stub_good());
ok('✨ with a model uses the model\'s coherent set',
    ($filledByModel['scale'] ?? '') === 'city' && ($filledByModel['motivation'] ?? '') === 'mystery');
$filledDead = xeric_forge_answers_fill([], $iv, stub_dead());
ok('✨ falls back to the table when the model is down', whole_concepts($filledDead, $iv) !== []);

// ---------------------------------------------------------------------------
// Each pass, fed garbage, must still produce its section
// ---------------------------------------------------------------------------

$notes = [];
$note = function (string $m) use (&$notes): void { $notes[] = $m; };

$conceptJunk = xeric_forge_pass_concept(ANSWERS, stub_junk(), $note);
ok('concept pass survives junk', ($conceptJunk['meta']['name'] ?? '') !== '' && count($conceptJunk['setting']['texture']) >= 3);
ok('concept junk is reported, not hidden', (bool)array_filter($notes, fn($n) => str_contains($n, 'built-in default')));
ok('the model cannot raise the rating ceiling', $conceptJunk['meta']['rating'] === 'mature');

$placesJunk = xeric_forge_pass_places(ANSWERS, $conceptJunk, stub_junk(), 6, $note);
ok('places pass survives junk', count($placesJunk) === 6, (string)count($placesJunk));
ok('the workplace survives a junk places pass', xeric_forge_workplace_key($placesJunk) !== null);

$castJunk = xeric_forge_pass_cast(ANSWERS, $conceptJunk, $placesJunk, stub_junk(), 4, $note);
ok('cast pass survives junk', count($castJunk['characters']) === 4);
$handles = array_map(fn($c) => $c['handle'], $castJunk['characters']);
ok('junk cast still has unique handles', count(array_unique($handles)) === 4, implode(',', $handles));

$deadBuild = xeric_forge_build(ANSWERS, stub_dead(), ['places' => 6, 'cast' => 4]);
ok('a dead model still produces a world', validates($deadBuild['template']) === '', validates($deadBuild['template']));
ok('a dead model still produces seed history',
    count($deadBuild['seed']['events']) >= 2 && count($deadBuild['seed']['memories']) >= 4);

$junkBuild = xeric_forge_build(ANSWERS, stub_junk(), ['places' => 6, 'cast' => 4]);
ok('a junk model still produces a valid world', validates($junkBuild['template']) === '', validates($junkBuild['template']));
ok('the notes say what fell back', (bool)array_filter($junkBuild['notes'], fn($n) => str_contains($n, 'built-in default')));

// ---------------------------------------------------------------------------
// The happy path: a model that answers properly
// ---------------------------------------------------------------------------

$built = xeric_forge_build(ANSWERS, stub_good(), ['places' => 6, 'cast' => 4]);
$t = $built['template'];

ok('a forged world validates', validates($t) === '', validates($t));
ok('the model\'s concept is the world', $t['meta']['name'] === 'Ostrander');
ok('nothing fell back on the happy path',
    array_filter($built['notes'], fn($n) => str_contains($n, 'built-in default')) === [],
    implode(' | ', $built['notes']));

// Homes ride along since 2026-08-02, so "how many places" is two questions:
// the places PASS still delivers exactly what was asked for, and the homes
// pass adds a roof per household on top.
$tPublic = array_filter($t['places'], fn($p) => ($p['kind'] ?? '') !== 'home');
ok('six public places', count($tPublic) === 6, (string)count($tPublic));
ok('and everybody sleeps somewhere the map knows',
    count($t['places']) > count($tPublic));
$placeKeys = array_map(fn($p) => (string)$p['key'], $t['places']);
$wk = (string)$t['user']['occupation']['workplace_key'];
ok('the workplace from the answers is a real place', in_array($wk, $placeKeys, true), $wk);
ok('the workplace is marked for later passes', xeric_forge_workplace_key($t['places']) === $wk);

$badWeek = [];
foreach ($t['cast']['characters'] as $c) {
    foreach ((array)$c['week'] as $w) {
        $where = (string)($w['where'] ?? '');
        if (!in_array($where, $placeKeys, true)) $badWeek[] = $c['handle'] . ' → ' . $where;
    }
    if ((array)$c['week'] === []) $badWeek[] = $c['handle'] . ' → no week at all';
}
ok('every character\'s week points at a real place', $badWeek === [], implode(', ', $badWeek));
ok('the model\'s invented place key was corrected, not shipped',
    !in_array('a_place_that_does_not_exist', array_map(fn($c) => (string)$c['week'][0]['where'], $t['cast']['characters']), true));

$orbitKeys = array_map(fn($o) => (string)$o['key'], $t['cast']['orbits']);
ok('2-3 orbits for a small world', count($orbitKeys) >= 2 && count($orbitKeys) <= 3, implode(',', $orbitKeys));
$atWork = array_filter($t['cast']['characters'], fn($c) => (string)$c['orbit'] === $wk);
ok('somebody is in the workplace orbit', count($atWork) >= 1);

ok('motivation armed the right systems',
    in_array('attraction', (array)$t['forge']['armed'], true), implode(',', (array)$t['forge']['armed']));
ok('the answers are kept for reroll', ($t['forge']['answers']['job'] ?? '') === ANSWERS['job']);

// Seed history: a past, with every reference real.
$seed = $built['seed'];
ok('seed history has events', count($seed['events']) >= 2, (string)count($seed['events']));
ok('seed history has 2-4 memories per character',
    count($seed['memories']) >= 2 * count($t['cast']['characters']), (string)count($seed['memories']));
$badRefs = [];
foreach ($seed['events'] as $e) {
    if ($e['place'] !== null && !in_array((string)$e['place'], $placeKeys, true)) $badRefs[] = 'place ' . $e['place'];
    foreach ((array)$e['participants'] as $p) {
        if (!in_array((string)$p, $handlesOf ?? array_map(fn($c) => (string)$c['handle'], $t['cast']['characters']), true)) {
            $badRefs[] = 'who ' . $p;
        }
    }
}
foreach ($seed['memories'] as $m) {
    if (!in_array((string)$m['handle'], array_map(fn($c) => (string)$c['handle'], $t['cast']['characters']), true)) {
        $badRefs[] = 'memory ' . $m['handle'];
    }
}
ok('seed history never references anything that does not exist', $badRefs === [], implode(', ', $badRefs));
ok('a participant named in prose is resolved to a handle, not dropped',
    ($seed['events'][0]['participants'][0] ?? '') === 'person_1_quill',
    implode(',', (array)($seed['events'][0]['participants'] ?? [])));
ok('a place key answered where prose was asked for reads as prose',
    (string)$t['cast']['characters'][0]['solace'] === 'the Anchor', (string)$t['cast']['characters'][0]['solace']);
ok('seed history is NOT inside the template', !isset($t['seed']) && !isset($t['events']['history']));

// ---------------------------------------------------------------------------
// The two things the web layer hands a build so it can be stopped
//
// A build is eight straight passes against a model that may take ten minutes a
// call, and the queue hands the GPU on after seven. Without a per-call ceiling
// and a hook between passes, `touch queue.drained` is a request the build never
// hears — which is the one guarantee that command exists to make.
// ---------------------------------------------------------------------------

$sawTimeout = [];
$timedStub = ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) use (&$sawTimeout) {
    $sawTimeout[$tag] = $o['timeout'] ?? null;
    return (stub_good()['stub'])($tag, $m, $o);
}];
xeric_forge_build(ANSWERS, $timedStub, ['places' => 3, 'cast' => 2, 'seed' => false, 'timeout' => 240]);
$unbounded = array_keys(array_filter($sawTimeout, fn($v) => $v !== 240));
ok('every pass of a build is called with the ceiling it was given',
    $sawTimeout !== [] && $unbounded === [], implode(',', $unbounded));

$asked = 0;
$guarded = function () use (&$asked): void {
    if (++$asked >= 3) throw new RuntimeException('THE OWNER TOOK THE GPU BACK');
};
$stopped = '';
try {
    xeric_forge_build(ANSWERS, stub_good(), ['places' => 3, 'cast' => 2, 'guard' => $guarded]);
} catch (Throwable $e) {
    $stopped = $e->getMessage();
}
ok('a build stops when the guard says to, and is not retried into a fallback',
    $stopped === 'THE OWNER TOOK THE GPU BACK', $stopped === '' ? 'it ran to completion' : $stopped);
ok('and the guard is asked between passes, not once at the door', $asked >= 3, (string)$asked);
ok('a build with no guard is exactly as it was',
    isset(xeric_forge_build(ANSWERS, stub_good(), ['places' => 3, 'cast' => 2, 'seed' => false])['template']['meta']['name']));

// ---------------------------------------------------------------------------
// Walls: what the forge writes must not undo what the walls promise
//
// The model half of the walls pass and of the protagonist pass call
// xeric_llm_json() directly, which has no stub seam — so these exercise the
// deterministic halves, which are the halves that always ship.
// ---------------------------------------------------------------------------

// Two different promises, and conflating them costs the feature. Every wall
// takes the arc, because the arc is an interior and rides `drives` with the
// rest of them. Only the wall over a protected relationship takes the SECTION,
// because "something is moving around her" is the one thing the cast is
// supposed to feel — and the one thing the person being kept in the dark must
// not.
$noArc = $noSection = [];
foreach ((array)$t['knowledge_walls'] as $w) {
    $hidden = (array)($w['hidden'] ?? []);
    $key    = (string)$w['key'];
    if (!in_array('drives', $hidden, true)) $noArc[] = $key;
    if (str_starts_with($key, 'protects_') && !in_array('protagonist', $hidden, true)) $noSection[] = $key;
    if (str_starts_with($key, 'privacy_') && in_array('protagonist', $hidden, true)) $noSection[] = $key . ' (takes the framing too)';
}
ok('every forged wall takes the protagonist\'s arc with the other interiors', $noArc === [], implode(',', $noArc));
ok('and only a protected relationship\'s wall takes the whole section', $noSection === [], implode(',', $noSection));

// The baseline leaves the framing standing, which is the whole point of not
// putting `protagonist` on it: the cast feels the lean without reading the arc.
$w1 = $t;
$w1['cast']['protagonist'] = ['handle' => (string)$t['cast']['characters'][0]['handle'],
                              'arc' => 'ARC_CANARY_ONE', 'pressure' => 'PRESSURE_CANARY_ONE'];
$member = xeric_render_bible($w1, ['handle' => (string)$t['cast']['characters'][1]['handle']], 'sfw');
ok('a forged cast member still feels the world leaning somebody\'s way',
    str_contains($member, 'WHOSE STORY THIS IS')
    && !str_contains($member, 'ARC_CANARY_ONE') && !str_contains($member, 'PRESSURE_CANARY_ONE'));

// The arc is printed to everyone the section is shown to. It may not BE the
// protagonist's unspoken pull — that is the one field the baseline exists to hide.
$side = $t;
$side['user']['centrality'] = 'side';
$prot = xeric_forge_pass_protagonist($side, []);        // no endpoint: the deterministic half
$pulls = array_map(fn($c) => trim((string)($c['drives']['pull'] ?? '')), $side['cast']['characters']);
$castHandles = array_map(fn($c) => (string)$c['handle'], $t['cast']['characters']);
ok('a protagonist is named when the user steps out of the centre',
    $prot !== null && ($prot['arc'] ?? '') !== '' && in_array((string)($prot['handle'] ?? ''), $castHandles, true));
ok('the deterministic arc is not somebody\'s private pull, byte for byte',
    !in_array((string)$prot['arc'], $pulls, true), (string)$prot['arc']);
$echoed = array_filter($pulls, fn($p) => $p !== '' && str_contains((string)$prot['arc'], $p));
ok('the deterministic arc does not quote a private pull at all', $echoed === [], (string)$prot['arc']);

// And the model half, which is the half that reads every pull in the cast
// immediately before being asked what this person is driving toward.
$star = $side;
$star['cast']['characters'][0]['drives']['pull'] = 'to be forgiven by the brother who stopped writing back';
$starHandle = (string)$star['cast']['characters'][0]['handle'];
$echoEndpoint = ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o): array =>
    $tag === 'protagonist'
        ? ['handle' => $starHandle,
           'arc' => 'She wants to be forgiven by the brother who stopped writing back.',
           'pressure' => 'His birthday.']
        : (stub_good()['stub'])($tag, $m, $o)];
$echoProt = xeric_forge_pass_protagonist($star, $echoEndpoint);
ok('a model arc that repeats the pull it was shown is refused',
    ($echoProt['source'] ?? '') === 'fallback'
    && !str_contains((string)$echoProt['arc'], 'forgiven by the brother'),
    ($echoProt['source'] ?? '?') . ': ' . (string)($echoProt['arc'] ?? ''));

$cleanEndpoint = ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o): array =>
    $tag === 'protagonist'
        ? ['handle' => $starHandle, 'arc' => 'She is running out of reasons to stay.', 'pressure' => 'The lease.']
        : (stub_good()['stub'])($tag, $m, $o)];
$cleanProt = xeric_forge_pass_protagonist($star, $cleanEndpoint);
ok('and an arc that says something the town could say is kept',
    ($cleanProt['source'] ?? '') === 'model'
    && (string)$cleanProt['arc'] === 'She is running out of reasons to stay.',
    ($cleanProt['source'] ?? '?') . ': ' . (string)($cleanProt['arc'] ?? ''));

// The walls pass's model half, reachable for the first time now that
// xeric_llm_json carries the same stub seam xeric_forge_ask always had.
$wallsEndpoint = ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o): array =>
    $tag === 'walls'
        ? ['protected' => [['handle' => $starHandle, 'role' => 'child',
                            'must_not_know' => 'where the second job actually is',
                            'why' => 'She is fifteen.']]]
        : (stub_good()['stub'])($tag, $m, $o)];
$wOut = xeric_forge_pass_walls($star, $wallsEndpoint);
$protects = null;
foreach ($wOut['knowledge_walls'] as $w) if ($w['key'] === 'protects_' . $starHandle) $protects = $w;
ok('a protected relationship gets a wall that takes the whole of WHOSE STORY THIS IS',
    $protects !== null && in_array('protagonist', (array)$protects['hidden'], true),
    $protects === null ? 'no wall written' : implode(',', (array)$protects['hidden']));
ok('and the special role that makes it a separate world',
    ($wOut['special_roles'][0]['own_bible'] ?? false) === true
    && ($wOut['special_roles'][0]['character'] ?? '') === $starHandle);

// Seed memories for a protected character are written knowing what they must
// not learn, and are checked again on the way back.
$protHandle = (string)$t['cast']['characters'][0]['handle'];
$secret = 'the money that comes out of the lockup';
$protT = $t;
$protT['cast']['special_roles'] = [[
    'role' => 'child', 'character' => $protHandle, 'walls' => ['protects_' . $protHandle],
    'own_bible' => true, 'must_not_know' => $secret,
]];
$seenPrompts = [];
$leaky = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use (&$seenPrompts): array {
    $seenPrompts[$tag][] = (string)($msgs[1]['content'] ?? '');
    if ($tag !== 'seed_memories') return (stub_good()['stub'])($tag, $msgs, $opts);
    return ['memories' => [
        'She heard about the money that comes out of the lockup and said nothing.',
        'She fixed the back door at the depot and never mentioned it.',
        'She owed Ada an answer and gave it two days late.',
    ]];
}];
$protSeed = xeric_forge_pass_seed($protT, $leaky, $note);
$leaked = $kept = [];
foreach ($protSeed['memories'] as $m) {
    if ((string)$m['handle'] !== $protHandle) continue;
    $kept[] = (string)$m['text'];
    if (str_contains(mb_strtolower((string)$m['text']), 'lockup')) $leaked[] = (string)$m['text'];
}
ok('a seeded memory that walks into the secret is dropped', $leaked === [], implode(' | ', $leaked));
ok('the memories that were clean are kept', count($kept) >= 2, implode(' | ', $kept));
$told = array_filter($seenPrompts['seed_memories'] ?? [], fn($p) => str_contains($p, $secret));
ok('the protected character\'s memory prompt states the exclusion', $told !== []);
$others = array_filter($protSeed['memories'], fn($m) => (string)$m['handle'] !== $protHandle);
ok('an unprotected character keeps every memory the model wrote',
    count($others) === 3 * (count($t['cast']['characters']) - 1), (string)count($others));

ok('a sentence that shares two words with the secret trips the wall',
    xeric_forge_trips_wall('She heard about the money that comes out of the lockup.', $secret));
ok('an unrelated sentence does not', !xeric_forge_trips_wall('She fixed the back door at the depot.', $secret));
ok('an empty must_not_know cannot refuse everything', !xeric_forge_trips_wall('Anything at all.', ''));

// ---------------------------------------------------------------------------
// Repair: an assembled world with dangling references is fixed, not thrown
// ---------------------------------------------------------------------------

$broken = $t;
$broken['cast']['characters'][0]['orbit'] = 'an_orbit_nobody_declared';
$broken['cast']['characters'][1]['week'][0]['where'] = 'a_place_nobody_built';
$broken['places'][2]['residents'][] = 'a_person_nobody_wrote';
$broken['user']['occupation']['workplace_key'] = 'gone';
$broken['user']['timezone'] = 'Mars/Olympus';
ok('the broken template really is broken', validates($broken) !== '');
$fixed = xeric_forge_repair($broken);
ok('repair makes it valid again', validates($fixed) === '', validates($fixed));
ok('repair kept the cast it could keep', count($fixed['cast']['characters']) === count($t['cast']['characters']));

// ---------------------------------------------------------------------------
// THE AGE FLOOR
//
// Read the assertions here as a pair, because either one alone is the wrong
// product. A town HAS children: Billy's son is in the cast, in an orbit, on a
// schedule, in the bible and in the room — every test below that names him is
// checking he was not tidied away. And a child is never in the desire economy:
// no attraction seed at either end of him, no attraction in what he is armed
// with, no node under him above the weakest rating.
//
// A change that makes the first group fail is the rule written backwards, and
// it guts the world. A change that makes the second group fail is the rule not
// written at all.
// ---------------------------------------------------------------------------

/** stub_good, except that the cast it writes is a town: adults, an old man, two kids. */
function stub_town(): array
{
    $good = stub_good()['stub'];
    $ages = [34, 15, 67, 9];
    $n = 0;
    return ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) use ($good, $ages, &$n): array {
        $out = $good($tag, $m, $o);
        if ($tag === 'cast') { $out['age'] = $ages[$n % count($ages)]; $n++; }
        return $out;
    }];
}

// ANSWERS asks for romance, so this world arms `attraction` and the exclusion
// has something to actually exclude.
$ageBuilt = xeric_forge_build(ANSWERS, stub_town(), ['places' => 6, 'cast' => 4, 'seed' => false]);
$ageT = $ageBuilt['template'];
$ageCast = (array)$ageT['cast']['characters'];

ok('a world with children in it validates', validates($ageT) === '', validates($ageT));
ok('the desire economy is armed at all, or this section proves nothing',
    in_array('attraction', (array)$ageT['forge']['armed'], true), implode(',', (array)$ageT['forge']['armed']));

$noAge = [];
foreach ($ageCast as $c) if (!is_int($c['age'] ?? null)) $noAge[] = (string)$c['handle'] . '=' . json_encode($c['age'] ?? null);
ok('every forged character has an integer age', $noAge === [], implode(', ', $noAge));

$ageMinors = [];
foreach ($ageCast as $c) if (xeric_is_minor($c)) $ageMinors[(string)$c['handle']] = $c;
ok('a child the model wrote is in the cast, not filtered out of it', count($ageMinors) >= 1,
    implode(',', array_map(fn($c) => $c['age'], $ageCast)));

$ageOrbits = array_map(fn($o) => (string)$o['key'], (array)$ageT['cast']['orbits']);
$agePlaces = array_map(fn($p) => (string)$p['key'], (array)$ageT['places']);
$stranded = [];
foreach ($ageMinors as $h => $c) {
    if (!in_array((string)$c['orbit'], $ageOrbits, true)) $stranded[] = "$h has no orbit";
    if ((array)($c['week'] ?? []) === []) $stranded[] = "$h has no week";
    foreach ((array)$c['week'] as $w) {
        if (!in_array((string)($w['where'] ?? ''), $agePlaces, true)) $stranded[] = "$h is nowhere";
    }
    if (!($c['photos']['enabled'] ?? false)) $stranded[] = "$h has no portrait";
    if (trim((string)($c['one_line'] ?? '')) === '') $stranded[] = "$h is not a person";
}
ok('a child stands in an orbit, on a schedule, in a real place, with a portrait',
    $stranded === [], implode(', ', $stranded));

// The shelf test. The shared bible is what everybody in the world can read, and
// a child who is missing from it is a child who has been removed from the town.
$ageAdult = null;
foreach ($ageCast as $c) if (!xeric_is_minor($c)) { $ageAdult = $c; break; }
$ageBible = xeric_render_bible($ageT, ['handle' => (string)$ageAdult['handle']], 'sfw');
$missing = [];
foreach ($ageMinors as $h => $c) if (!str_contains($ageBible, (string)$c['display_name'])) $missing[] = $h;
ok('a child is in the bible the rest of the town reads', $missing === [], implode(',', $missing));

// ---- and now the one thing that IS gated -----------------------------------

$armedWrong = [];
foreach ($ageMinors as $h => $c) {
    foreach (XERIC_DESIRE_SYSTEMS as $sys) {
        if (in_array($sys, (array)($c['armed'] ?? []), true)) $armedWrong[] = "$h armed with $sys";
    }
}
ok('no child is armed with a desire system', $armedWrong === [], implode(', ', $armedWrong));

$edges = [];
foreach ($ageCast as $c) {
    $seeds = (array)($c['relationships']['attraction_seeds'] ?? []);
    if (xeric_is_minor($c) && $seeds !== []) $edges[] = (string)$c['handle'] . ' wants somebody';
    foreach (array_keys($seeds) as $who) {
        if (isset($ageMinors[(string)$who])) $edges[] = (string)$c['handle'] . ' → ' . (string)$who;
    }
}
ok('no attraction seed touches a child, from either end', $edges === [], implode(', ', $edges));

ok('the desire pool is exactly the adults',
    (array)$ageT['forge']['desire_pool'] === array_values(array_diff(
        array_map(fn($c) => (string)$c['handle'], $ageCast), array_keys($ageMinors))),
    implode(',', (array)$ageT['forge']['desire_pool']));
ok('and the children are named as excluded, not hidden',
    (array)$ageT['forge']['desire_excluded'] === array_keys($ageMinors));

// Everything that is NOT sex stays switched on. This is the assertion that
// catches the over-broad fix: grief, rivalry, secrets and a friendship that
// moves over a year are all ordinary things to happen to a thirteen-year-old.
$kid = $ageMinors[array_key_first($ageMinors)];
$lost = [];
foreach ((array)$ageT['forge']['armed'] as $sys) {
    if (in_array($sys, XERIC_DESIRE_SYSTEMS, true)) continue;
    if (!in_array($sys, (array)$kid['armed'], true)) $lost[] = $sys;
}
ok('a child keeps every system that is not the desire economy', $lost === [], implode(',', $lost));
ok('and the adults keep the desire economy',
    in_array('attraction', (array)$ageAdult['armed'], true), implode(',', (array)$ageAdult['armed']));

// The rating half: a child in an explicit world is still rendered at the
// weakest rating there is, and nothing under him may ask for more.
$loud = $ageT;
$loud['meta']['rating'] = 'explicit';
$k = array_key_first($ageMinors);
foreach ($loud['cast']['characters'] as $i => $c) {
    if ((string)$c['handle'] !== $k) continue;
    $loud['cast']['characters'][$i]['drives']['rating_min'] = 'explicit';
    $loud['cast']['characters'][$i]['packs'] = ['sfw' => ['banter' => ['Sit down.']], 'explicit' => ['banter' => ['no']]];
    $loud['cast']['characters'][$i]['relationships']['attraction_seeds'] = [(string)$ageAdult['handle'] => 6];
}
foreach ($loud['cast']['characters'] as $i => $c) {
    if ((string)$c['handle'] === (string)$ageAdult['handle']) {
        $loud['cast']['characters'][$i]['relationships']['attraction_seeds'] = [$k => 7, (string)$ageCast[2]['handle'] => 3];
    }
}
$floored = xeric_forge_age_floor($loud);
$fKid = null; $fAdult = null;
foreach ($floored['cast']['characters'] as $c) {
    if ((string)$c['handle'] === $k) $fKid = $c;
    if ((string)$c['handle'] === (string)$ageAdult['handle']) $fAdult = $c;
}
ok('a child in an explicit world renders at the weakest rating',
    xeric_effective_rating('explicit', $fKid) === xeric_ratings()[0]);
$gates = [];
foreach (xeric_world_find_ratings($fKid, '') as [$path, $value]) {
    if ($value !== xeric_ratings()[0]) $gates[] = "$path=$value";
}
ok('no node under a child asks for more than that', $gates === [], implode(', ', $gates));
ok('and a content pool above it is gone rather than gated',
    array_keys((array)($fKid['packs'] ?? [])) === ['sfw'], implode(',', array_keys((array)($fKid['packs'] ?? []))));
ok('a seed written onto a child is emptied', (array)$fKid['relationships']['attraction_seeds'] === []);
ok('an adult\'s seed pointed at a child is dropped',
    !array_key_exists($k, (array)$fAdult['relationships']['attraction_seeds']));
ok('and the adult\'s seed pointed at another adult survives',
    array_key_exists((string)$ageCast[2]['handle'], (array)$fAdult['relationships']['attraction_seeds']));
$hard = (array)($fKid['limits']['hard'] ?? []);
ok('a child\'s own limits say so in words as well', $hard !== [] && str_contains(implode(' ', $hard), 'minor'));

// A cast with no adults in it has nobody the desire systems could fire over.
$allKids = $ageT;
foreach ($allKids['cast']['characters'] as $i => $c) $allKids['cast']['characters'][$i]['age'] = 12;
$kidWorld = xeric_forge_age_floor($allKids);
ok('a world whose whole cast is children disarms the desire systems',
    !in_array('attraction', (array)$kidWorld['forge']['armed'], true)
    && in_array('attraction', (array)$kidWorld['forge']['disarmed'], true),
    implode(',', (array)$kidWorld['forge']['armed']));
ok('and disarms nothing else on the way past',
    in_array('jealousy', (array)$kidWorld['forge']['armed'], true)
    && in_array('private_history', (array)$kidWorld['forge']['armed'], true),
    implode(',', (array)$kidWorld['forge']['armed']));

// ---- ages the model did not write ------------------------------------------

$kidSlot = ['index' => 3, 'orbit' => 'outside'];
$workSlot = ['index' => 0, 'orbit' => 'diner'];
ok('a missing age takes the slot\'s deterministic default', xeric_forge_age(null, $kidSlot) === 14);
ok('and the default is never quietly an adult',
    xeric_forge_age(null, $kidSlot) < 18 && xeric_forge_age(null, $workSlot) >= 18);
ok('a whole number the model wrote is honoured, child or not',
    xeric_forge_age(15, $kidSlot) === 15 && xeric_forge_age('72', $workSlot) === 72);
$junkAges = [xeric_forge_age('thirties', $kidSlot), xeric_forge_age(12.5, $kidSlot),
             xeric_forge_age(240, $kidSlot), xeric_forge_age(0, $kidSlot), xeric_forge_age([], $kidSlot)];
ok('a range, a fraction and an age nobody has fall back to the default',
    array_unique($junkAges) === [14], implode(',', $junkAges));

// The hand-written cast is a town too: a dead model must not produce four
// colleagues in their thirties.
$deadTown = xeric_forge_build(ANSWERS, stub_dead(), ['places' => 6, 'cast' => 4, 'seed' => false])['template'];
$deadAges = array_map(fn($c) => (int)$c['age'], (array)$deadTown['cast']['characters']);
sort($deadAges);
ok('the hand-written cast has a child in it', $deadAges[0] < 18, implode(',', $deadAges));
ok('and it is not all children either', end($deadAges) >= 40, implode(',', $deadAges));
$deadKid = null;
foreach ((array)$deadTown['cast']['characters'] as $c) if (xeric_is_minor($c)) $deadKid = $c;
ok('the hand-written child has a school-shaped week, not a shift',
    (string)$deadKid['week'][0]['from'] > '12:00', (string)$deadKid['week'][0]['from']);
ok('and is out of the desire economy like any other child',
    !in_array('attraction', (array)$deadKid['armed'], true));

// A template that arrives with no age at all: fail closed, and say so.
$ageless = $ageT;
$ageless['cast']['characters'][0]['age'] = 'about thirty';
ok('a character without an integer age does not validate', validates($ageless) !== '');
$agedUp = xeric_forge_repair($ageless);
ok('repair fills the age rather than dropping the person',
    count($agedUp['cast']['characters']) === count($ageT['cast']['characters']));
ok('and what it fills is a minor, because unknown fails closed',
    is_int($agedUp['cast']['characters'][0]['age']) && xeric_is_minor($agedUp['cast']['characters'][0]),
    json_encode($agedUp['cast']['characters'][0]['age']));
ok('the repaired world validates', validates($agedUp) === '', validates($agedUp));

// ---------------------------------------------------------------------------
// The rating: answered, clamped from outside, and never climbed back out of
// ---------------------------------------------------------------------------

ok('an illegal rating is the weakest one', xeric_forge_rating(['rating' => 'gore']) === 'sfw');
ok('an unanswered rating is the weakest one', xeric_forge_rating([]) === 'sfw');
ok('a ceiling lowers the answer', xeric_forge_rating(['rating' => 'explicit'], 'mature') === 'mature');
ok('a ceiling can never raise it', xeric_forge_rating(['rating' => 'sfw'], 'explicit') === 'sfw');
ok('an unreadable ceiling is the weakest one, not no ceiling',
    xeric_forge_rating(['rating' => 'explicit'], 'nsfw') === 'sfw');

$pinned = xeric_forge_build(ANSWERS, stub_good(),
    ['places' => 3, 'cast' => 2, 'seed' => false, 'rating_ceiling' => 'sfw'])['template'];
ok('a session ceiling pins the world it forges', $pinned['meta']['rating'] === 'sfw', $pinned['meta']['rating']);
ok('and the passes were prompted with the clamped rating, not the answered one',
    $pinned['forge']['answers']['rating'] === 'sfw', (string)$pinned['forge']['answers']['rating']);

// ✨ from nothing draws a whole concept, and some of those concepts are mature.
// It still cannot draw its way past the ceiling.
$surprised = xeric_forge_build([], stub_dead(),
    ['places' => 3, 'cast' => 2, 'seed' => false, 'rating_ceiling' => 'sfw'])['template'];
ok('✨ cannot draw a rating past the ceiling', $surprised['meta']['rating'] === 'sfw', $surprised['meta']['rating']);

$unpinned = xeric_forge_build(ANSWERS, stub_good(), ['places' => 3, 'cast' => 2, 'seed' => false])['template'];
ok('and with no ceiling the answer is what ships', $unpinned['meta']['rating'] === ANSWERS['rating'],
    $unpinned['meta']['rating']);

// ---------------------------------------------------------------------------
// Writing: slugs and collisions
// ---------------------------------------------------------------------------

$dir = sys_get_temp_dir() . '/xeric-forge-test-' . getmypid();
@mkdir($dir, 0775, true);

$p1 = xeric_forge_write($t, $seed, $dir);
$p2 = xeric_forge_write($t, $seed, $dir);
$p3 = xeric_forge_write($t, $seed, $dir);
ok('slug comes from meta.name', $p1 === $dir . '/ostrander/world-template.json', $p1);
ok('a second world of the same name does not overwrite the first', $p2 === $dir . '/ostrander-2/world-template.json', $p2);
ok('and a third', $p3 === $dir . '/ostrander-3/world-template.json', $p3);
ok('seed.json is written next to it', is_file($dir . '/ostrander/seed.json'));
ok('what was written loads and validates', err(fn() => xeric_world_load($p1)) === '', err(fn() => xeric_world_load($p1)));

$odd = $t;
$odd['meta']['name'] = "  The  Ninth-Ward '77  ";
$p4 = xeric_forge_write($odd, $seed, $dir);
ok('an awkward name still slugs', $p4 === $dir . '/the-ninth-ward-77/world-template.json', $p4);

foreach (glob($dir . '/*') ?: [] as $d) {
    foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
    @rmdir($d);
}
@rmdir($dir);

// ---------------------------------------------------------------------------
// THE STORY OVERLAY — injection
//
// The forge's half of a feature whose central claim is that closing a story is
// a SUBTRACTION: an overlay never writes into world-template.json, so the
// first assertion here is that the pass hands back the template it was given,
// byte for byte, however hard the model pushes.
//
// Everything else in this section is the same two disciplines as the rest of
// the file, pointed at a plot. An overlay is ALWAYS launchable — a dead model
// produces one, and it validates. And nothing a model says is load-bearing
// until it has been checked against the real cast: a beat held by somebody who
// does not exist, a wrong lead pointing at the murderer, a piece that restates
// an interior this world does not render, a beat gated above a child's
// ceiling. All four are dropped rather than repaired, because a repaired
// sentence about the secret is still a sentence about the secret.
//
// Milldale is the world under test rather than a forged one, because it has
// the three things this pass needs to be wrong about: a twelve-year-old, a
// character who already carries a special_role, and a rating-gated drive that
// an sfw world does not render.
// ---------------------------------------------------------------------------

// The forge does NOT depend on the engine's story runtime — it drafts overlays
// for worlds that are not running yet. But when engine/story.php is on the box
// this suite holds every overlay the pass writes to the real validator, which
// is the only assertion here that is worth more than its own restatement of
// the rules.
if (is_file(__DIR__ . '/../../engine/story.php')) require_once __DIR__ . '/../../engine/story.php';

$MILL = xeric_world_load(__DIR__ . '/../../engine/fixtures/milldale.json');

/**
 * The rules the FORGE owes the validator, checked here so this suite fails on
 * its own terms when engine/story.php is not present yet. When it is, the real
 * xeric_story_validate() runs too and this list is belt and braces.
 */
function story_problems(array $s, array $t): array
{
    $bad = [];
    $chars = [];
    foreach ($t['cast']['characters'] as $c) $chars[(string)$c['handle']] = $c;
    $places = [];
    foreach ($t['places'] as $p) $places[(string)$p['key']] = true;
    $roles = [];
    foreach ((array)($t['cast']['special_roles'] ?? []) as $r) $roles[(string)$r['character']] = true;

    if (preg_match('/^[a-z0-9_]+$/', (string)($s['key'] ?? '')) !== 1) $bad[] = 'key is not a slug';
    if ((int)($s['story_version'] ?? 0) !== 1) $bad[] = 'story_version';
    foreach (['logline', 'truth', 'title'] as $f) if (trim((string)($s[$f] ?? '')) === '') $bad[] = "$f is empty";
    if (xeric_rating_rank((string)($s['rating_min'] ?? '')) > xeric_rating_rank(xeric_world_rating($t))) {
        $bad[] = 'rating_min is above the world ceiling';
    }

    $culprit = (string)($s['cast']['culprit'] ?? '');
    if (!isset($chars[$culprit])) $bad[] = "culprit '$culprit' is not in the cast";
    if (!is_int($s['cast']['victim']['age'] ?? null)) $bad[] = 'victim has no integer age';
    if (trim((string)($s['cast']['victim']['name'] ?? '')) === '') $bad[] = 'victim has no name';

    $wallKeys = [];
    foreach ((array)($s['walls'] ?? []) as $w) {
        if (!str_starts_with((string)$w['key'], 'story.' . $s['key'] . '.')) $bad[] = 'wall key is not namespaced';
        $wallKeys[(string)$w['key']] = true;
    }
    $protect = (array)($s['cast']['protect'] ?? []);
    if (count($protect) > intdiv(count($chars), 2)) $bad[] = 'protects more than half the cast';
    foreach ($protect as $p) {
        if (!isset($chars[(string)$p['character']])) $bad[] = 'protects somebody who does not exist';
        if (isset($roles[(string)$p['character']])) $bad[] = 'protects somebody who already has a special_role';
        if ((string)$p['character'] === $culprit) $bad[] = 'protects the culprit';
        if (!isset($wallKeys[(string)($p['wall'] ?? '')])) $bad[] = 'protection names no wall of this overlay';
    }

    $fc = (array)($s['snake']['false_calm'] ?? []);
    $beatKeys = [];
    $prev = -1.0;
    $herringKeys = [];
    foreach ((array)($s['red_herrings'] ?? []) as $h) $herringKeys[(string)$h['key']] = true;
    foreach ((array)($s['beats'] ?? []) as $i => $b) {
        $k = (string)($b['key'] ?? '');
        if ($k === '' || isset($beatKeys[$k])) $bad[] = "beat key '$k' is missing or repeated";
        $at = (float)($b['at'] ?? -1);
        if ($at <= $prev && $i > 0) $bad[] = "beat '$k' does not move forward";
        if ($at < 0 || $at > 1) $bad[] = "beat '$k' is off the curve";
        if ($at > (float)$fc[0] && $at < (float)$fc[1]) $bad[] = "beat '$k' opens inside the false calm";
        $prev = $at;
        if ($b['holder'] === null) {
            if (!isset($b['as_event'])) $bad[] = "beat '$k' has no holder and no event";
            $pl = (string)($b['as_event']['place'] ?? '');
            if ($pl !== '' && !isset($places[$pl])) $bad[] = "beat '$k' happens nowhere";
        } else {
            if (!isset($chars[(string)$b['holder']])) $bad[] = "beat '$k' is held by nobody who exists";
            foreach (['piece', 'while_locked', 'when_open', 'spilled_as'] as $f) {
                if (trim((string)($b[$f] ?? '')) === '') $bad[] = "beat '$k' has no $f";
            }
            if (xeric_is_minor($chars[(string)$b['holder']] ?? ['age' => null])
                && xeric_rating_rank((string)($b['rating_min'] ?? 'sfw')) > 0) {
                $bad[] = "beat '$k' gates content above sfw on a minor";
            }
        }
        foreach ((array)($b['opens_when']['after'] ?? []) as $a) {
            if (!isset($beatKeys[(string)$a])) $bad[] = "beat '$k' waits on '$a', which is not an earlier beat";
        }
        foreach ((array)($b['kills_herring'] ?? []) as $hk) {
            if (!isset($herringKeys[(string)$hk])) $bad[] = "beat '$k' kills a lead that does not exist";
        }
        $beatKeys[$k] = true;
    }
    if ($beatKeys === []) $bad[] = 'no beats';

    $killed = [];
    foreach ((array)($s['beats'] ?? []) as $b) foreach ((array)($b['kills_herring'] ?? []) as $hk) $killed[(string)$hk][] = (string)$b['key'];
    foreach ((array)($s['red_herrings'] ?? []) as $h) {
        $hk = (string)$h['key'];
        if (($h['is_false'] ?? null) !== true) $bad[] = "lead '$hk' is not marked false";
        if (!isset($chars[(string)$h['believer']])) $bad[] = "lead '$hk' is believed by nobody who exists";
        foreach (['belief', 'because', 'actually'] as $f) {
            if (trim((string)($h[$f] ?? '')) === '') $bad[] = "lead '$hk' has no $f";
        }
        if (!in_array((string)($h['sincerity'] ?? ''), ['certain', 'fairly_sure', 'wondering'], true)) {
            $bad[] = "lead '$hk' has no sincerity";
        }
        $pa = $h['points_at'] ?? null;
        if ($pa !== null) {
            if (!isset($chars[(string)$pa])) $bad[] = "lead '$hk' points at nobody who exists";
            if ((string)$pa === $culprit) $bad[] = "lead '$hk' points at the culprit";
            if ((string)$pa === (string)$h['believer']) $bad[] = "lead '$hk' points at its own believer";
        }
        $on = (string)($h['collapses_on'] ?? '');
        if ($on !== 'resolution' && !isset($beatKeys[$on])) $bad[] = "lead '$hk' collapses on nothing";
        if ($on !== 'resolution' && !in_array($on, (array)($killed[$hk] ?? []), true)) {
            $bad[] = "lead '$hk' and the beat that kills it disagree";
        }
        if (count((array)($killed[$hk] ?? [])) > 1) $bad[] = "lead '$hk' is killed twice";
        if (xeric_is_minor($chars[(string)$h['believer']] ?? ['age' => null])
            && xeric_rating_rank((string)($h['rating_min'] ?? 'sfw')) > 0) {
            $bad[] = "lead '$hk' gates content above sfw on a minor";
        }
    }

    $r = (array)($s['resolution'] ?? []);
    if ((string)($r['kind'] ?? '') === 'accusation') {
        if ((string)($r['answer'] ?? '') !== $culprit) $bad[] = 'the answer is not the culprit';
        if ((array)($r['requires_beats'] ?? []) === []) $bad[] = 'requires no beats — a guess is not a solution';
        foreach ((array)($r['requires_beats'] ?? []) as $rb) {
            if (!isset($beatKeys[(string)$rb])) $bad[] = "requires '$rb', which is not a beat";
        }
        if ((array)($r['accept']['to'] ?? []) === []) $bad[] = 'an accusation is said to nobody';
        foreach ((array)($r['accept']['to'] ?? []) as $to) {
            if (!isset($chars[(string)$to])) $bad[] = "accepted by '$to', who does not exist";
        }
        if (($r['on_wrong']['closes'] ?? true) !== false) $bad[] = 'a wrong accusation ends the story';
    } else {
        $bad[] = 'the forge writes accusations';
    }
    if (($t['mystery']['enabled'] ?? false) && ($t['mystery']['rumor_pays_out'] ?? true) === false
        && !in_array('mystery.rumor', (array)($r['never'] ?? []), true)) {
        $bad[] = 'this story may explain the strange place';
    }

    foreach ((array)($s['snake']['kind_thumb'] ?? []) as $stage => $row) {
        if (!in_array($stage, ['opening', 'rising', 'taper', 'false_calm', 'crescendo', 'closing'], true)) {
            $bad[] = "kind_thumb names stage '$stage', which the curve does not produce";
        }
        foreach ((array)$row as $kind => $mult) if ((float)$mult <= 0) $bad[] = "thumb deletes '$kind'";
    }
    foreach ((array)($s['on_close']['memories'] ?? []) as $h => $m) {
        if (!isset($chars[(string)$h])) $bad[] = "closing memory for '$h', who does not exist";
        if (trim((string)$m) === '') $bad[] = "closing memory for '$h' is empty";
    }
    $cp = (string)($s['on_close']['event']['place'] ?? '');
    if ($cp !== '' && !isset($places[$cp])) $bad[] = 'the close happens nowhere';

    // The engine's own validator when it is on the box; the rules above are
    // this suite's floor, not its ceiling.
    if (function_exists('xeric_story_validate')) {
        $e = err(fn() => xeric_story_validate($s, $t, 'test'));
        if ($e !== '') $bad[] = 'xeric_story_validate: ' . $e;
    }
    return $bad;
}

/** Every string in an overlay, for the sweeps that have to look at all of them. */
function story_strings(array $s): array
{
    $out = [];
    array_walk_recursive($s, function ($v) use (&$out) { if (is_string($v) && mb_strlen($v) > 24) $out[] = $v; });
    return $out;
}

// ---- the central claim, from the forge's side -----------------------------

$millBefore = $MILL;
$offline = xeric_forge_pass_story($MILL, ['prose' => 'somebody came home to sell the mill and went down the stairs'], []);
ok('a dead model still lays a story over the world', $offline !== [] && ($offline['source'] ?? '') === 'authored');
ok('and the world it was laid over is byte-identical afterward', $MILL === $millBefore);
ok('the offline overlay is a valid one', story_problems($offline, $MILL) === [],
    implode(' | ', story_problems($offline, $MILL)));
ok('it names a real culprit and a victim of its own invention',
    isset($MILL['cast']['characters'][0]) && in_array($offline['cast']['culprit'],
        array_map(fn($c) => $c['handle'], $MILL['cast']['characters']), true)
    && !in_array($offline['cast']['victim']['name'],
        array_map(fn($c) => $c['display_name'], $MILL['cast']['characters']), true));
ok('the whole overlay is namespaced under its own key',
    array_filter((array)$offline['walls'], fn($w) => !str_starts_with((string)$w['key'], 'story.' . $offline['key'] . '.')) === []
    && str_starts_with((string)$offline['resolution']['on_wrong']['arc'], 'story:' . $offline['key'] . ':'));

// ---- the snake, which is data and not vibes -------------------------------

$snake = xeric_forge_story_snake();
$flat = array_values(array_filter($snake['curve'], fn($p) => (float)$p[1] === 0.5));
ok('the false calm and the flat of the curve are the same two numbers',
    (float)$snake['false_calm'][0] === (float)$flat[0][0] && (float)$snake['false_calm'][1] === (float)$flat[1][0],
    json_encode($snake['false_calm']));
// m = 1 + swing * (2i - 1). The whole point of halfway is that it is ×1.0 —
// not approximately: the false calm is the world at its own ordinary pace.
$m = 1 + (float)$snake['pace_swing'] * (2 * 0.5 - 1);
ok('an intensity of 0.5 multiplies sweep_chance by exactly 1.0', $m === 1.0, (string)$m);
ok('the swing is symmetric and the ends are 0.4x and 1.6x',
    1 + (float)$snake['pace_swing'] * (2 * 1.0 - 1) === 1.6
    && 1 + (float)$snake['pace_swing'] * (2 * 0.0 - 1) === 0.4);
$negative = [];
foreach ($snake['kind_thumb'] as $stage => $row) foreach ((array)$row as $k => $v) if ((float)$v <= 0) $negative[] = "$stage.$k";
ok('no thumb deletes a kind — a thumb is not a gate', $negative === [], implode(',', $negative));
ok('the story arms nothing',
    !isset($offline['forge']) && !isset($offline['armed']) && !isset($offline['systems']));

$ladder = xeric_forge_story_ats(6);
ok('six beats land where the worked fixture puts them',
    $ladder === [0.0, 0.15, 0.3, 0.45, 0.78, 0.92], implode(',', $ladder));
$inCalm = [];
for ($n = 1; $n <= 12; $n++) {
    $prev = -1.0;
    foreach (xeric_forge_story_ats($n) as $at) {
        if ($at > 0.5 && $at < 0.72) $inCalm[] = "n=$n at=$at";
        if ($at <= $prev) $inCalm[] = "n=$n went backwards at $at";
        $prev = $at;
    }
}
ok('no beat count puts a beat inside the false calm, or out of order', $inCalm === [], implode(', ', $inCalm));

// ---- the model, wrong in every way it is usually wrong ---------------------

/** A model that answers with display names, a stranger, and the wrong pointer. */
function stub_story(array $over = []): array
{
    $base = [
        'title' => 'Harlan Beck and the Fourth-Floor Landing',
        'logline' => 'Harlan Beck did it and everybody is about to find out.',
        'truth' => 'He let himself through the gate with his father\'s key and it went wrong on the landing.',
        'victim' => ['name' => 'Ellis Chandler', 'age' => 74,
                     'one_line' => 'The last Chandler, home to sell the block.', 'found' => 'at the bottom of the stairwell'],
        'culprit' => 'Harlan Beck',
        'protect' => ['handle' => 'Janelle', 'must_not_know' => 'who was on the landing at the mill'],
        'opening' => ['title' => 'They found him at the mill', 'place' => 'the mill',
                      'prose' => 'The ambulance came down Water Street without its siren, because it did not need to hurry.'],
        'pieces' => [
            ['handle' => 'Theo', 'piece' => 'He got through the gate in June and there was a folding chair in the stairwell, facing the door.',
             'asks_about' => ['MILL', 'Chair'], 'trust_gate' => 3],
            ['handle' => 'a_person_who_is_not_here', 'piece' => 'Somebody who does not exist saw the whole thing.'],
            ['handle' => 'Ruth', 'piece' => 'Ellis asked her in her own kitchen who held the keys to the mill now.'],
            ['handle' => 'Dot', 'piece' => 'The store has not cleared its own rent since March and he has told nobody, not even Dot.'],
        ],
        'herrings' => [
            ['believer' => 'Dot', 'belief' => 'Pastor Dale\'s car was down by the mill that night; she saw the cap through the windshield.',
             'because' => 'She was closing up and she looked out and she saw it.', 'points_at' => 'Harlan Beck',
             'actually' => 'It was Dale driving forty minutes the other way, to the county hospital.',
             'known_false_to' => ['Pastor Dale']],
            ['believer' => 'Ruth', 'belief' => 'It was only kids, the way it has been all summer.',
             'because' => '', 'actually' => 'It was not kids.'],
        ],
    ];
    foreach ($over as $k => $v) $base[$k] = $v;
    return ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o): array => $base];
}

$sNotes = [];
$drafted = xeric_forge_pass_story($MILL, ['prose' => 'a man came home to sell the mill'], stub_story(),
    function (string $m) use (&$sNotes) { $sNotes[] = $m; });
$sNoteText = implode("\n", $sNotes);
ok('a model draft is a valid overlay too', story_problems($drafted, $MILL) === [],
    implode(' | ', story_problems($drafted, $MILL)));
ok('and it says so', ($drafted['source'] ?? '') === 'model');

$holders = [];
foreach ($drafted['beats'] as $b) if ($b['holder'] !== null) $holders[] = (string)$b['holder'];
ok('display names land on the people who actually exist',
    in_array('theo', $holders, true) && in_array('ruth', $holders, true), implode(',', $holders));
ok('a handle nobody in this world answers to is dropped, not invented',
    !in_array('a_person_who_is_not_here', $holders, true));
ok('the culprit is coerced to a handle', $drafted['cast']['culprit'] === 'harlan');
ok('the person who did it holds the last beat',
    end($drafted['beats'])['holder'] === 'harlan', (string)end($drafted['beats'])['holder']);

// A wrong lead that points at the guilty party is the answer, not a lead. It
// is kept — as a wrong THEORY, which is the other honest shape — rather than
// thrown away, because the belief itself was fine.
$lead = $drafted['red_herrings'][0] ?? [];
ok('a lead pointing at the culprit becomes a wrong theory',
    $lead !== [] && array_key_exists('points_at', $lead) && $lead['points_at'] === null
    && ($lead['is_false'] ?? null) === true, json_encode($lead['points_at'] ?? '(absent)'));
ok('and something still disposes of it',
    ($lead['collapses_on'] ?? '') === 'resolution' || isset(array_flip(array_map(fn($b) => (string)$b['key'], $drafted['beats']))[$lead['collapses_on']]));
ok('a wrong lead with no grounds does not survive one question, or this pass',
    count($drafted['red_herrings']) === 1 && str_contains($sNoteText, 'no grounds'));

// ---- what a story may not restate -----------------------------------------

// Harlan's money trouble is rating_min 'mature' in this sfw world, so it never
// renders. An overlay states what a holder can OBSERVE and never re-tells
// somebody's gated interior at a lower rating.
ok('a piece that restates a rating-gated interior is dropped',
    !in_array('dot', $holders, true) && str_contains($sNoteText, 'harlan.drives.pull'), $sNoteText);
$gatedPull = '';
foreach ($MILL['cast']['characters'] as $c) if ($c['handle'] === 'harlan') $gatedPull = (string)$c['drives']['pull'];
$restated = array_filter(story_strings($drafted),
    fn($s) => xeric_wall_quotes(xeric_wall_words($s), xeric_wall_words($gatedPull)));
ok('and nothing else in the overlay quotes it either', $restated === [], implode(' | ', $restated));

// The logline is the ONE string a player may be shown before it resolves.
ok('a logline that names who did it is replaced, and the replacement does not',
    !str_contains(mb_strtolower($drafted['logline']), 'harlan')
    && !str_contains(mb_strtolower($drafted['title']), 'harlan')
    && str_contains($drafted['logline'], 'Ellis Chandler'),
    $drafted['logline']);
ok('the narrator\'s copy is still allowed to say it', str_contains($drafted['truth'], 'landing'));

// ---- protections have teeth ------------------------------------------------

ok('an overlay may not put a second role on somebody who already has one',
    $drafted['cast']['protect'] === [] && str_contains($sNoteText, 'special_role'), $sNoteText);

$protStub = stub_story(['protect' => ['handle' => 'Pastor Dale', 'must_not_know' => 'who was on the landing at the mill']]);
$protNotes = [];
$protected = xeric_forge_pass_story($MILL, ['prose' => 'a man came home to sell the mill'], $protStub,
    function (string $m) use (&$protNotes) { $protNotes[] = $m; });
ok('a protection on somebody free to take one is kept, with its wall',
    ($protected['cast']['protect'][0]['character'] ?? '') === 'pastor_dale'
    && ($protected['cast']['protect'][0]['wall'] ?? '') === ($protected['walls'][0]['key'] ?? '!'),
    json_encode($protected['cast']['protect']));
ok('the protection is still valid', story_problems($protected, $MILL) === [],
    implode(' | ', story_problems($protected, $MILL)));

// A piece written FOR the protected person that walks into the thing they must
// not know hands it to them on day one, in their own system message, and
// nothing downstream can take it back. It is dropped, never rewritten.
$leakStub = stub_story([
    'protect' => ['handle' => 'Pastor Dale', 'must_not_know' => 'who was on the landing at the mill'],
    'pieces' => [
        ['handle' => 'Pastor Dale', 'piece' => 'He was up on the landing at the mill and he saw exactly who else was there.'],
        ['handle' => 'Theo', 'piece' => 'He got through the gate in June and there was a folding chair in the stairwell.'],
    ],
]);
$leakNotes = [];
$leaked = xeric_forge_pass_story($MILL, ['prose' => 'a man came home to sell the mill'], $leakStub,
    function (string $m) use (&$leakNotes) { $leakNotes[] = $m; });
$leakHolders = [];
foreach ($leaked['beats'] as $b) if ($b['holder'] !== null) $leakHolders[] = (string)$b['holder'];
ok('a beat that hands the protected person the thing they must not know is dropped',
    !in_array('pastor_dale', $leakHolders, true)
    && str_contains(implode("\n", $leakNotes), 'must not know'), implode(',', $leakHolders));
$blind = (string)($leaked['cast']['protect'][0]['must_not_know'] ?? '');
$trips = [];
foreach ($leaked['beats'] as $b) {
    if ((string)($b['holder'] ?? '') !== 'pastor_dale') continue;
    foreach (['piece', 'while_locked', 'when_open', 'spilled_as'] as $f) {
        if (xeric_forge_trips_wall((string)($b[$f] ?? ''), $blind)) $trips[] = "$f";
    }
}
ok('nothing composed into the protected person\'s own prompt trips their wall', $trips === [], implode(',', $trips));

// ---- THE AGE FLOOR, both halves --------------------------------------------
//
// Read these as a pair. The first group is the load-bearing one: a
// twelve-year-old holds the piece that the story turns on, is named in the
// resolution, and is in the room when it comes out. If any of those fail, the
// rule has been written backwards and it has gutted the mystery — a child
// witness nobody believes is the oldest working part in the genre.
//
// The second group is the rule itself, which is about sex and nothing else: a
// node that gates content above a child's ceiling does not load. Not clamped,
// not softened. Content that can never render is content nobody should be
// writing about a child.

$theo = null;
foreach ($MILL['cast']['characters'] as $c) if ($c['handle'] === 'theo') $theo = $c;
ok('the world under test really does have a child in it', $theo !== null && xeric_is_minor($theo));

$kidBeat = null;
foreach ($drafted['beats'] as $b) if (($b['holder'] ?? null) === 'theo') $kidBeat = $b;
ok('a twelve-year-old holds a piece of the truth', $kidBeat !== null);
ok('with everything a beat needs — he is not a lesser kind of holder',
    $kidBeat !== null && trim((string)$kidBeat['while_locked']) !== '' && trim((string)$kidBeat['when_open']) !== ''
    && trim((string)$kidBeat['spilled_as']) !== '' && (int)$kidBeat['opens_when']['trust_gate'] > 0);
ok('and the memory that gets written when he tells you is his own',
    $kidBeat !== null && str_contains((string)$kidBeat['spilled_as'], 'Theo'), (string)($kidBeat['spilled_as'] ?? ''));
ok('he is somebody the accusation can be said to',
    in_array('theo', (array)$drafted['resolution']['accept']['to'], true),
    implode(',', (array)$drafted['resolution']['accept']['to']));
ok('his beat is one the resolution actually requires',
    in_array((string)$kidBeat['key'], (array)$drafted['resolution']['requires_beats'], true),
    implode(',', (array)$drafted['resolution']['requires_beats']));
ok('he is in the room when it comes out', trim((string)($drafted['on_close']['memories']['theo'] ?? '')) !== '');
ok('and no wall in this overlay was built to keep the child out',
    array_filter((array)$drafted['walls'], fn($w) => str_contains((string)$w['explain'], 'Theo')) === []);
ok('a child may be sure of something and be wrong about it',
    xeric_forge_pass_story($MILL, [], [])['red_herrings'][0]['believer'] === 'theo');

$gatedKid = stub_story(['pieces' => [
    ['handle' => 'Theo', 'piece' => 'He saw the chair in the stairwell.', 'rating_min' => 'explicit'],
    ['handle' => 'Ruth', 'piece' => 'Ellis asked her who held the keys to the mill now.'],
]]);
$kidNotes = [];
$gated = xeric_forge_pass_story($MILL, ['prose' => 'a man came home'], $gatedKid,
    function (string $m) use (&$kidNotes) { $kidNotes[] = $m; });
$gatedHolders = [];
foreach ($gated['beats'] as $b) if ($b['holder'] !== null) $gatedHolders[] = (string)$b['holder'];
ok('a beat gated above a child\'s ceiling does not load at all',
    !in_array('theo', $gatedHolders, true) && str_contains(implode("\n", $kidNotes), 'can never be reached'),
    implode(',', $gatedHolders));
$aboveSfw = [];
foreach (array_merge($gated['beats'], $gated['red_herrings']) as $node) {
    $who = (string)($node['holder'] ?? $node['believer'] ?? '');
    if ($who === 'theo' && xeric_rating_rank((string)($node['rating_min'] ?? 'sfw')) > 0) $aboveSfw[] = $who;
}
ok('and nothing anywhere under the child is gated above sfw', $aboveSfw === []);
ok('the story still stands up without him', story_problems($gated, $MILL) === [],
    implode(' | ', story_problems($gated, $MILL)));

// ---- the doors -------------------------------------------------------------

$reshaped = xeric_forge_pass_story($MILL,
    ['from' => $offline, 'change' => 'this time it is Dot', 'taken' => [$offline['key']]],
    ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o): array
        => ['title' => 'The Second Time It Happened', 'culprit' => 'dot']]);
ok('a reshape keeps every field the model did not answer',
    count($reshaped['beats']) === count($offline['beats'])
    && $reshaped['cast']['victim']['name'] === $offline['cast']['victim']['name']);
ok('and changes the one it did', $reshaped['cast']['culprit'] === 'dot'
    && $reshaped['resolution']['answer'] === 'dot' && end($reshaped['beats'])['holder'] === 'dot');
ok('a reshape is a NEW overlay, never the old one edited under a running world',
    $reshaped['key'] !== $offline['key'] && ($reshaped['source'] ?? '') === 'reshaped');
ok('and it is valid', story_problems($reshaped, $MILL) === [], implode(' | ', story_problems($reshaped, $MILL)));

$noModel = xeric_forge_pass_story($MILL, ['from' => $offline],
    ['base' => 'stub://', 'stub' => function (string $t, array $m, array $o): array {
        throw new LogicException('door 2 does not call a model');
    }]);
ok('an overlay handed straight back needs no model at all',
    $noModel['key'] !== $offline['key'] && story_problems($noModel, $MILL) === [],
    implode(' | ', story_problems($noModel, $MILL)));

$storyDir = sys_get_temp_dir() . '/xeric-story-test-' . getmypid();
@mkdir($storyDir, 0775, true);
foreach (glob($storyDir . '/story-*.json') ?: [] as $f) @unlink($f);
$wrote = xeric_forge_write_story($offline, $storyDir);
ok('an overlay is written beside the world as story-<key>.json',
    basename($wrote) === 'story-' . $offline['key'] . '.json' && is_file($wrote));
ok('and the world can be asked which stories it already has',
    xeric_forge_story_keys($storyDir) === [$offline['key']]);
ok('writing over a live overlay is refused',
    str_contains(err(fn() => xeric_forge_write_story($offline, $storyDir)), 'never rewritten'));
@unlink($wrote);
@rmdir($storyDir);

// ---- and a world that cannot have one --------------------------------------

$empty = $MILL;
$empty['cast']['characters'] = [];
ok('a world with nobody in it gets no story rather than a broken one',
    xeric_forge_pass_story($empty, ['prose' => 'somebody did something'], []) === []);

$one = $MILL;
$one['cast']['characters'] = [$theo];
$oneStory = xeric_forge_pass_story($one, [], []);
ok('a world with one person in it still gets a playable story',
    story_problems($oneStory, $one) === [], implode(' | ', story_problems($oneStory, $one)));
ok('and the child in it is the one who did it, because there is nobody else',
    $oneStory['cast']['culprit'] === 'theo' && $oneStory['resolution']['answer'] === 'theo');

// A junk model is the ordinary case, not the exotic one.
$junked = xeric_forge_pass_story($MILL, ['prose' => 'anything at all'], stub_junk());
ok('a model that answers with nothing anybody asked for still leaves a story',
    story_problems($junked, $MILL) === [] && ($junked['source'] ?? '') === 'authored',
    implode(' | ', story_problems($junked, $MILL)));
ok('the world survived every one of those passes untouched', $MILL === $millBefore);

// ---------------------------------------------------------------------------
// The small normalisers everything else leans on
// ---------------------------------------------------------------------------

// A place key is machine output wherever it lands, but prose that merely owns
// an underscore — or a key that is also an English word — must survive intact.
$dedupePlaces = [['key' => 'pier_9_station', 'name' => 'Pier 9 station'], ['key' => 'anchor', 'name' => 'the Anchor']];
$dedupeCast = [
    ['handle' => 'a', 'voice' => 'Talks around it, then stops.',
     'psyche' => ['sore_spot' => 'Goes quiet when the shift changes.', 'self_soothe' => 'Counts the float twice.',
                  'jealousy' => 'Anyone who got out early.', 'praise_that_lands' => 'That the till balanced.'],
     'drives' => ['pull' => 'To be told the truth the first time.'],
     'solace' => 'Goes to ground at Pier_9_station when it gets bad.'],
    ['handle' => 'b', 'voice' => 'Answers a question with the weather.',
     'psyche' => ['sore_spot' => 'Being asked about the lease.', 'self_soothe' => 'Rewinds the tape.',
                  'jealousy' => 'People with a spare key.', 'praise_that_lands' => 'That she called it.'],
     'drives' => ['pull' => 'Keeps an anchor tattoo she will not explain.'],
     'solace' => 'Pier_9_station'],
];
$deduped = xeric_forge_dedupe_cast($dedupeCast, $dedupePlaces);
ok('a place key buried in a sentence is swapped for the name',
    $deduped[0]['solace'] === 'Goes to ground at Pier 9 station when it gets bad.', (string)$deduped[0]['solace']);
ok('a place key that is the whole answer still reads as prose',
    $deduped[1]['solace'] === 'Pier 9 station', (string)$deduped[1]['solace']);
ok('a key that is also an ordinary word is left alone mid-sentence',
    $deduped[1]['drives']['pull'] === 'Keeps an anchor tattoo she will not explain.',
    (string)$deduped[1]['drives']['pull']);

// The systems table is vocabulary, not English: a name outside XERIC_SYSTEMS
// switches nothing and renders as a blank row in the review UI.
$strangers = [];
foreach (['company', 'romance', 'ambition', 'mystery', 'redemption', 'survival', '',
          'prove the mine is poisoning the river', 'I am lonely and want people around'] as $mot) {
    $r = xeric_forge_armed($mot);
    foreach (array_merge($r['armed'], $r['disarmed']) as $s) {
        if (!isset(XERIC_SYSTEMS[$s])) $strangers[] = $mot . ' → ' . $s;
    }
}
ok('nothing outside XERIC_SYSTEMS can reach armed or disarmed', $strangers === [], implode(', ', $strangers));
$company = xeric_forge_armed('company');
ok('the elderly-user case actually disarms something',
    count($company['disarmed']) >= 3 && in_array('daily_rhythms', $company['armed'], true),
    implode(',', $company['disarmed']));

// A night owl's world must sleep when THEY do. Reading a bare hour as 24-hour
// time put the quiet in the middle of the afternoon (2026-07-30).
ok('a bare hour after "until" is the hour a person means',
    xeric_forge_quiet_hours('evenings, up until 11') === '23:00-05:00'
    && xeric_forge_quiet_hours('I stay up till 11') === '23:00-05:00'
    && xeric_forge_quiet_hours('up till 2am most nights') === '02:00-08:00'
    && xeric_forge_quiet_hours('awake until 12') === '00:00-06:00'
    && xeric_forge_quiet_hours('up until 11pm') === '23:00-05:00',
    xeric_forge_quiet_hours('evenings, up until 11') . ' / ' . xeric_forge_quiet_hours('awake until 12'));
ok('an early riser and the presets are unchanged',
    xeric_forge_quiet_hours('6am before the kids wake') === '23:00-06:00'
    && xeric_forge_quiet_hours('nights') === '04:00-12:00'
    && xeric_forge_quiet_hours('') === '23:00-08:00',
    xeric_forge_quiet_hours('6am before the kids wake'));
$quietBad = [];
foreach (['evenings, up until 11', 'I stay up till 11', 'up till 1', 'until 10 most nights'] as $q) {
    // whatever the phrasing, a late night may not go quiet before 9pm
    [$from] = explode('-', xeric_forge_quiet_hours($q));
    $h = (int)substr($from, 0, 2);
    if ($h > 4 && $h < 21) $quietBad[] = "$q → $from";
}
ok('no late-night phrasing puts the quiet in the afternoon', $quietBad === [], implode(', ', $quietBad));

ok('hhmm reads what models actually write',
    xeric_forge_hhmm('9', 'x') === '09:00' && xeric_forge_hhmm('5pm', 'x') === '17:00'
    && xeric_forge_hhmm('17.30', 'x') === '17:30' && xeric_forge_hhmm('half past', 'x') === 'x');
ok('day phrases become day numbers',
    xeric_forge_days('weekdays', []) === [1, 2, 3, 4, 5] && xeric_forge_days('weekends', []) === [0, 6]
    && xeric_forge_days([1, 3, 9], [0]) === [1, 3] && xeric_forge_days('Tue and Thu', []) === [2, 4]);
ok('keys are unique and article-free',
    xeric_forge_key('the Bluebird Diner') === 'bluebird_diner'
    && xeric_forge_key('the Bluebird Diner', ['bluebird_diner' => true]) === 'bluebird_diner_2');
ok('scale reads free text', xeric_forge_scale(['scale' => 'a big city, I think']) === 'city'
    && xeric_forge_scale([]) === 'small_town');
ok('an illegal rating cannot get through', xeric_forge_rating(['rating' => 'gore']) === 'sfw');

// ---------------------------------------------------------------------------
// THE NAMING REGISTER
//
// Eight worlds were forged before this section existed and Elias Thorne lived
// in every one of them — a fog town, a Mojave junction, a space station. Two
// claims are defended here, the same two the vocal-shape fix proved: variety
// is ASSIGNED (a register, chosen deterministically from the answers), and
// then GUARANTEED (gates that replace banned and shelf-worn names whatever
// the model said). A prompt ban alone is a hope; these are the tests for the
// gate.
// ---------------------------------------------------------------------------

$regs = xeric_forge_registers();
ok('registers.json loads and is a real library', count($regs['registers']) >= 8);
$thin = [];
foreach ($regs['registers'] as $r) {
    $k = (string)($r['key'] ?? '?');
    if (count((array)($r['given'] ?? [])) < 12)      $thin[] = "$k.given";
    if (count((array)($r['family'] ?? [])) < 8)      $thin[] = "$k.family";
    if (count((array)($r['toponyms'] ?? [])) < 4)    $thin[] = "$k.toponyms";
    if (count((array)($r['businesses'] ?? [])) < 4)  $thin[] = "$k.businesses";
    if (trim((string)($r['church'] ?? '')) === '')   $thin[] = "$k.church";
}
ok('every register is stocked for a twelve-person cast', $thin === [], implode(', ', $thin));
$bankBanned = [];
foreach ($regs['registers'] as $r) {
    foreach (['given', 'family'] as $bank) {
        foreach ((array)($r[$bank] ?? []) as $name) {
            foreach ((array)($regs['banned'][$bank] ?? []) as $bad) {
                if (strcasecmp((string)$name, (string)$bad) === 0) $bankBanned[] = (string)$name;
            }
        }
    }
}
ok('no register bank contains a banned name — a gate that swaps a ban for a ban loops', $bankBanned === []);

// THE REGISTER IS CHOSEN, THEN PINNED — free once, fixed after.
//
// It used to be DERIVED from a crc32 of five answer fields, which made it a
// function of what somebody typed rather than something the world got to have:
// two similar premises produced the same register every time, and the ✨ path,
// drawing from a handful of canned concepts, could reach FIVE of the thirty.
// The owner pressed surprise twice and got the same register, which was not
// luck, it was arithmetic.
//
// What must NOT change is the physics: a character reroll lands in the register
// of the world it happens in. You cannot put a Klingon in 1873 Ireland.
// ── THE COORDINATE, AND THE REGISTER INVENTED ON IT ──────────────────────
//
// Thirty written registers is a menu: eleven hundred hand-written names bought
// thirty somewheres. The axes are the opposite trade — forty-eight lines for
// four thousand coordinates — and the model invents the names on one it did not
// choose. Not a seed: engine/llm.php passes no sampler seed, so llama.cpp drew
// a fresh one on every call that produced Elias Thorne eight times out of eight.
// A seed picks WITHIN a distribution; conditioning moves it.
$axes = xeric_forge_registers()['axes'];
ok('axes: the loader lets them through at all',
    ($axes['era'] ?? []) !== [] && ($axes['people'] ?? []) !== [] && ($axes['place'] ?? []) !== [],
    'a whitelisting loader dropped these once and every world silently took a written register');
$space = count($axes['era']) * count($axes['people']) * count($axes['place']);
ok('axes: they multiply into far more somewheres than the table enumerates',
    $space > 1000 && $space > count(xeric_forge_registers()['registers']) * 20, (string)$space);
$coords = [];
for ($i = 0; $i < 200; $i++) {
    $c = xeric_forge_coordinate();
    $coords[$c['era'] . '|' . $c['people'] . '|' . $c['place']] = true;
}
ok('axes: a coordinate is drawn freely, not derived from anything',
    count($coords) > 120, count($coords) . ' distinct in 200 draws');
ok('axes: and every draw is complete — a half coordinate invents nothing',
    (function () { for ($i = 0; $i < 50; $i++) { $c = xeric_forge_coordinate();
        if ($c['era'] === '' || $c['people'] === '' || $c['place'] === '') return false; } return true; })());

// AND THE BANNED LISTS STILL EARN THEIR KEEP. Run against a live model, the
// concrete coordinates produced Varga/Chen/Müller/O'Shea/Moretti — and the most
// ABSTRACT one ("no century anyone here would name") drifted straight back
// toward thriller names and produced Vane. The axes move the distribution; the
// gates catch what still lands on the old attractor. Two layers, both needed.
$bannedFam = array_map('mb_strtolower', xeric_forge_registers()['banned']['family'] ?? []);
ok('axes: the observed repeat offenders are still gated, invented register or not',
    in_array('vane', $bannedFam, true) && in_array('thorne', $bannedFam, true));

$regs = xeric_forge_registers()['registers'];
ok('register: a pin is honoured, whatever the answers around it say',
    xeric_forge_naming(ANSWERS + ['register' => 'rustbelt_polish'])['key'] === 'rustbelt_polish'
    && xeric_forge_naming(ANSWERS + ['register' => 'cajun_bayou'])['key'] === 'cajun_bayou');
ok('register: the same pin lands in the same place every time — a reroll stays home',
    xeric_forge_naming(ANSWERS + ['register' => 'iron_range_finn'])['key']
        === xeric_forge_naming(['register' => 'iron_range_finn'])['key']);
ok('register: a pin nobody ships is dropped rather than obeyed or fatal',
    xeric_forge_naming(ANSWERS + ['register' => 'no_such_place'])['key']
        === xeric_forge_naming(ANSWERS)['key']);
// No migration: a world forged before pins existed has none, derives the one it
// always did, and its rerolls keep matching.
ok('register: a world with no pin still derives the register it always had',
    xeric_forge_naming(ANSWERS)['key'] === xeric_forge_naming(ANSWERS)['key']
    && xeric_forge_naming(ANSWERS)['key'] !== '');
// And the free choice reaches all of them, which the derived one never could.
$reach = [];
for ($i = 0; $i < 300; $i++) $reach[(string)$regs[random_int(0, count($regs) - 1)]['key']] = true;
ok('register: choosing freely can reach every register that ships',
    count($reach) === count($regs), count($reach) . ' of ' . count($regs));

$nm = xeric_forge_naming(ANSWERS);
ok('the register is deterministic on the answers',
    $nm['key'] !== '' && $nm['key'] === xeric_forge_naming(ANSWERS)['key'], $nm['key']);

ok('a banned first name is replaced and the clean surname is kept',
    str_ends_with(xeric_forge_fresh_person_name('Elias Marsh', $nm, 0, []), ' Marsh')
    && !str_starts_with(xeric_forge_fresh_person_name('Elias Marsh', $nm, 0, []), 'Elias'),
    xeric_forge_fresh_person_name('Elias Marsh', $nm, 0, []));
ok('a banned surname is replaced and the clean first name is kept',
    str_starts_with(xeric_forge_fresh_person_name('Dora Thorne', $nm, 1, []), 'Dora ')
    && !str_ends_with(xeric_forge_fresh_person_name('Dora Thorne', $nm, 1, []), 'Thorne'),
    xeric_forge_fresh_person_name('Dora Thorne', $nm, 1, []));
ok('a clean name is not touched', xeric_forge_fresh_person_name('Wendell Pike', $nm, 0, []) === 'Wendell Pike');
ok('two renamed characters do not land on the same replacement',
    xeric_forge_fresh_person_name('Elias Marsh', $nm, 0, [])
    !== xeric_forge_fresh_person_name('Elias Grant', $nm, 3, []),
    xeric_forge_fresh_person_name('Elias Marsh', $nm, 0, []));
ok('a name the brief itself contains is kept whole, banned or not',
    xeric_forge_fresh_person_name('Elias Sanders', $nm, 0, [], 'my teacher Mr. Sanders, called Elias by nobody')
        === 'Elias Sanders');

ok('a world may not be Oakhaven, Blackwood, or end in Creek or Hollow',
    !xeric_forge_world_name_ok('Oakhaven Mills', $nm) && !xeric_forge_world_name_ok('Blackwood Rest', $nm)
    && !xeric_forge_world_name_ok('Cutter Creek', $nm) && !xeric_forge_world_name_ok('Fenn Hollow', $nm)
    && !xeric_forge_world_name_ok('The Low Crossing', $nm));
ok('and an ordinary name passes', xeric_forge_world_name_ok('Ostrander', $nm) && xeric_forge_world_name_ok('Creekside', $nm));
ok('a banned world name the premise itself contains is the user\'s choice and stands',
    xeric_forge_fresh_world_name('Blackwood', $nm, 'a town called Blackwood, like my grandmother\'s') === 'Blackwood');

$usedP = [];
$rusty = xeric_forge_fresh_place_name('The Rusty Anchor', 'bar', $nm, 0, $usedP);
ok('the Rusty <thing> shape is replaced', stripos($rusty, 'rusty') === false, $rusty);
$jude = xeric_forge_fresh_place_name("St. Jude's of the Mist", 'church', $nm, 1, $usedP);
ok('St. Jude finally retires, apostrophe and all', stripos($jude, 'jude') === false, $jude);
ok('and the parish that replaces him is the register\'s own', $jude === $nm['church'], "$jude vs {$nm['church']}");
ok('two replaced places in one world are two different places',
    !in_array($rusty, [$jude], true) && count($usedP) >= 2);

// ---- cross-world memory: the shelf itself is the ban list ------------------

$shelfDir = sys_get_temp_dir() . '/xeric-forge-test-shelf-' . getmypid();
@mkdir($shelfDir . '/testville', 0775, true);
@file_put_contents($shelfDir . '/testville/world-template.json', json_encode([
    'meta' => ['name' => 'Testville'],
    'cast' => ['characters' => [
        ['display_name' => 'Wendell Pike'],
        ['display_name' => 'Verna Blevins'],
    ]],
    'places' => [['name' => 'the Anchor'], ['name' => "Verna's"]],
]));
xeric_forge_shelf($shelfDir);
$shelfNm = xeric_forge_naming(ANSWERS);
ok('the shelf is read: names on it are spoken for',
    isset($shelfNm['used']['given']['wendell'], $shelfNm['used']['family']['pike'],
          $shelfNm['used']['worlds']['testville'], $shelfNm['used']['places']['anchor']));
$moved = xeric_forge_fresh_person_name('Wendell Pike', $shelfNm, 0, []);
ok('the second world cannot reuse the first world\'s cast',
    $moved !== 'Wendell Pike' && stripos($moved, 'Wendell') === false && stripos($moved, 'Pike') === false, $moved);
ok('the second world cannot reuse the first world\'s name',
    xeric_forge_fresh_world_name('Testville', $shelfNm) !== 'Testville');
$usedP2 = [];
ok('or its rooms', xeric_forge_fresh_place_name('the Anchor', 'bar', $shelfNm, 0, $usedP2) !== 'the Anchor');

// The whole build, against a model that answers with everything the shelf and
// the ban list forbid — which is not a hypothetical, it is a transcript.
$tired = ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o): array {
    static $n = 0;
    $good = (stub_good()['stub'])($tag, $m, $o);
    switch ($tag) {
        case 'concept':
            $good['name'] = 'Testville';
            return $good;
        case 'places':
            $good['places'][0]['name'] = 'The Rusty Anchor';
            return $good;
        case 'cast':
            $n++;
            $good['display_name'] = ['Elias Thorne', 'Silas Vane', 'Kaelen Voss', 'Wendell Pike'][($n - 1) % 4];
            $good['one_line'] = 'The only person in town who can ' . $n . ' things at once.';
            return $good;
    }
    return $good;
}];
$tiredBuild = xeric_forge_build(ANSWERS, $tired, ['places' => 6, 'cast' => 4, 'seed' => false]);
$tt = $tiredBuild['template'];
ok('a model that names the world after an existing one is overruled',
    (string)$tt['meta']['name'] !== 'Testville', (string)$tt['meta']['name']);
$tiredNames = array_map(fn($c) => (string)$c['display_name'], (array)$tt['cast']['characters']);
$offenders = array_filter($tiredNames, fn($x) =>
    preg_match('/\b(Elias|Silas|Kaelen|Wendell|Thorne|Vane|Voss|Pike)\b/i', $x) === 1);
ok('no banned or shelf-worn name survives a whole build', $offenders === [], implode(', ', $tiredNames));
ok('the renamed cast is still four different people', count(array_unique($tiredNames)) === 4, implode(', ', $tiredNames));
$tiredPlaces = array_map(fn($p) => (string)$p['name'], (array)$tt['places']);
ok('no Rusty anything survives either',
    array_filter($tiredPlaces, fn($x) => stripos($x, 'rusty') !== false) === [], implode(', ', $tiredPlaces));
$tiredLines = array_map(fn($c) => (string)$c['one_line'], (array)$tt['cast']['characters']);
ok('the "The only…" roster line is banned even once, and replaced past that',
    array_filter($tiredLines, fn($x) => stripos($x, 'the only') === 0) === [], implode(' | ', $tiredLines));
ok('the register that renamed them is on the record',
    is_array($tt['forge']['naming'] ?? null) && (string)$tt['forge']['naming']['register'] !== '');

@unlink($shelfDir . '/testville/world-template.json');
@rmdir($shelfDir . '/testville');
@rmdir($shelfDir);
xeric_forge_shelf(sys_get_temp_dir() . '/xeric-forge-test-no-shelf-' . getmypid());

// ---------------------------------------------------------------------------
// TWELVE IS A TOWN: every by-index bank has to cover the new default cast
// ---------------------------------------------------------------------------

// The hand-written cast, dealt twelve deep with no model: twelve different
// people, a spread of ages, and nobody wearing anybody else's name.
$bigDead = xeric_forge_build(ANSWERS, stub_dead(), ['places' => 6, 'cast' => 12, 'seed' => false])['template'];
$bigNames = array_map(fn($c) => (string)$c['display_name'], (array)$bigDead['cast']['characters']);
ok('a dead-model cast of twelve is twelve different people',
    count($bigNames) === 12 && count(array_unique($bigNames)) === 12, implode(', ', $bigNames));
$bigAges = array_map(fn($c) => (int)$c['age'], (array)$bigDead['cast']['characters']);
ok('and it is a town, not a staff meeting: children and old people included',
    min($bigAges) < 18 && max($bigAges) >= 60, implode(',', $bigAges));

// The age bands walk sixteen slots without handing two slots the same band.
$bandSeen = [];
$bandDup = [];
for ($slot = 0; $slot < 16; $slot++) {
    $orbit = $slot % 2 === 0 ? 'work' : 'outside';
    $b = xeric_forge_age_band($slot, $orbit);
    $sig = $orbit . ':' . $b['min'] . '-' . $b['max'];
    if (isset($bandSeen[$sig])) $bandDup[] = "slot $slot repeats $sig";
    $bandSeen[$sig] = true;
    if ($b['example'] < $b['min'] || $b['example'] > $b['max']) $bandDup[] = "slot $slot example outside band";
}
ok('sixteen slots, sixteen distinct age bands, every example inside its band', $bandDup === [], implode('; ', $bandDup));

// The dedupe banks are deep enough that sixteen identical interiors come out
// sixteen different — which is the direct test that no bank is shorter than
// the cast it serves.
$clones = [];
for ($i = 0; $i < 16; $i++) {
    $clones[] = ['handle' => 'c' . $i,
        'one_line' => 'Keeps to themselves, mostly.',
        'voice' => 'A low, gravelly rumble.',
        'psyche' => ['sore_spot' => 'being ignored', 'self_soothe' => 'walks', 'jealousy' => 'everyone',
                     'praise_that_lands' => 'thanks'],
        'drives' => ['pull' => 'to be seen'],
        'solace' => 'the porch'];
}
$uncloned = xeric_forge_dedupe_cast($clones, []);
$cloneDup = [];
foreach (['one_line', 'voice'] as $f) {
    $vals = array_map(fn($c) => (string)$c[$f], $uncloned);
    if (count(array_unique($vals)) !== 16) $cloneDup[] = $f . '=' . count(array_unique($vals));
}
foreach (['sore_spot', 'self_soothe', 'jealousy', 'praise_that_lands'] as $f) {
    $vals = array_map(fn($c) => (string)$c['psyche'][$f], $uncloned);
    if (count(array_unique($vals)) !== 16) $cloneDup[] = $f . '=' . count(array_unique($vals));
}
foreach (['pull' => fn($c) => (string)$c['drives']['pull'], 'solace' => fn($c) => (string)$c['solace']] as $f => $get) {
    $vals = array_map($get, $uncloned);
    if (count(array_unique($vals)) !== 16) $cloneDup[] = $f . '=' . count(array_unique($vals));
}
ok('sixteen identical interiors come out sixteen different on every field', $cloneDup === [], implode('; ', $cloneDup));

// The one-liner shape gate: same sentence in different words is a repeat.
$shaped = xeric_forge_dedupe_cast([
    ['handle' => 'a', 'one_line' => 'The only navigator who can thread a nebula without swearing.',
     'voice' => 'v1', 'psyche' => [], 'drives' => []],
    ['handle' => 'b', 'one_line' => 'The only mechanic who treats an engine better than kin.',
     'voice' => 'v2', 'psyche' => [], 'drives' => []],
    ['handle' => 'c', 'one_line' => 'The kid who knows which vent leads anywhere.',
     'voice' => 'v3', 'psyche' => [], 'drives' => []],
    ['handle' => 'd', 'one_line' => 'The kid who sees everything from the catwalk.',
     'voice' => 'v4', 'psyche' => [], 'drives' => []],
], []);
ok('"The only…" never survives, even its first appearance',
    array_filter($shaped, fn($c) => stripos((string)$c['one_line'], 'the only') === 0) === [],
    implode(' | ', array_map(fn($c) => $c['one_line'], $shaped)));
ok('the first wearer of an honest shape keeps it; the second is a repeat',
    (string)$shaped[2]['one_line'] === 'The kid who knows which vent leads anywhere.'
    && (string)$shaped[3]['one_line'] !== 'The kid who sees everything from the catwalk.',
    (string)$shaped[3]['one_line']);

// ---------------------------------------------------------------------------
// THE DEVELOPMENTAL LENS (repass.php)
//
// The repass gets a fourth read: flatness, not error. Three promises under
// test, and the third is the one that lets it ride the red-button sweep at
// all: (1) it reports with a rewrite on an editable path like every other
// lens, (2) it runs in sweep mode too — the owner's call — and (3) it
// CONVERGES: a line it has enriched is recorded on the world itself
// (forge.developed), marked settled on its sheet forever after, and refused
// by apply even if the model reports it anyway. Without that record the
// sweep would re-polish the same line every round, which is the exact churn
// the plot lens is excluded for.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../repass.php';

$devW = ['slug' => 'dev-lens', 'dir' => sys_get_temp_dir() . '/xeric-dev-lens-' . getmypid(),
         'template' => $deadBuild['template'], 'seed' => $deadBuild['seed']];
[$devItems, ] = xeric_repass_digest($devW['template'], $devW['seed']);
$devTarget = 0;
foreach ($devItems as $n => $it) {
    if ($it['path'] === 'cast.characters.0.one_line') { $devTarget = $n; break; }
}
ok('the digest carries the roster line the lens exists to improve', $devTarget > 0);

$devTags = [];
$devStub = ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) use (&$devTags, $devTarget): array {
    $devTags[] = $tag;
    if ($tag === 'repass-develop') {
        return ['findings' => [
            ['item' => $devTarget, 'say' => 'a job description where a person should be',
             'fix' => 'Pours the last round slow so the room ends gently.'],
            ['item' => 0, 'say' => 'the whole world is a bit beige', 'fix' => 'be better'],
        ]];
    }
    return ['findings' => []];
}];
$devR = xeric_repass($devW, $devStub);
$devFound = array_values(array_filter($devR['findings'], fn($f) => $f['kind'] === 'develop'));
ok('the developmental read runs beside consistency and plot',
    in_array('repass-develop', $devTags, true) && in_array('repass-plot', $devTags, true));
ok('a flatness finding carries its rewrite against the editable path',
    ($devFound[0]['path'] ?? '') === 'cast.characters.0.one_line' && ($devFound[0]['fix'] ?? '') !== '');
ok('a world-level flatness opinion carries no fix — there is no line for it to replace',
    ($devFound[1]['path'] ?? 'x') === '' && ($devFound[1]['fix'] ?? 'x') === '');

$devTags = [];
xeric_repass($devW, $devStub, null, ['mode' => 'sweep']);
ok('the developmental read rides the sweep, and the plot opinion still does not',
    in_array('repass-develop', $devTags, true) && !in_array('repass-plot', $devTags, true),
    implode(',', $devTags));

// Convergence: an enriched line is settled on the sheet and refused by apply.
$devDone = $devW;
$devDone['template']['forge']['developed'] = ['cast.characters.0.one_line'];
$devSheet = '';
xeric_repass($devDone, ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) use (&$devSheet): array {
    if ($tag === 'repass-develop') $devSheet = (string)($m[1]['content'] ?? '');
    return ['findings' => []];
}]);
ok('a line enriched on any earlier visit is marked settled on the develop sheet',
    str_contains($devSheet, 'settled, do not report'));
$devApplied = xeric_repass_apply($devDone, [['kind' => 'develop', 'about' => 'x', 'say' => 'flat',
    'path' => 'cast.characters.0.one_line', 'fix' => 'A second coat of polish.']]);
ok('and apply refuses to enrich it twice, whatever the model reported', $devApplied['fixed'] === 0);
$devUser = xeric_repass_apply($devW, [['kind' => 'develop', 'about' => 'you', 'say' => 'flat',
    'path' => 'user.motivation', 'fix' => 'A better reason to live.']]);
ok('the player\'s own lines are not the lens\'s to improve, in any mode', $devUser['fixed'] === 0);
// The consistency lens keeps its full reach: developed is a develop-only fence.
$devConsistency = xeric_repass_digest($devDone['template'], $devDone['seed'])[0];
ok('an enriched line is still on the consistency sheet un-settled — enrichment is not immunity',
    (bool)array_filter($devConsistency, fn($it) => $it['path'] === 'cast.characters.0.one_line'
        && !str_contains($it['label'], 'settled')));

// ---------------------------------------------------------------------------
// The repass floor. xeric_repass_apply is the ONE path that writes model prose
// permanently into world-template.json, and its age-floor guard had no
// assertion: deleting it left every suite green while the hand-edit box's twin
// stayed proven. The fix text below says "him" and never a name — the case
// that only survives because xeric_review_edit_handles hands the floor the
// field's SUBJECT rather than making it fish for names in the text.
// ---------------------------------------------------------------------------

$devKid = $devW;
$devKid['template']['cast']['characters'][0]['age'] = 12;
$kidHandle = (string)($devKid['template']['cast']['characters'][0]['handle'] ?? '');
ok('repass floor: the edit-handles map names the field\'s subject',
    $kidHandle !== '' && xeric_review_edit_handles($devKid, 'cast.characters.0.voice') === [$kidHandle]);
$kidApplied = xeric_repass_apply($devKid, [['kind' => 'develop', 'about' => 'x', 'say' => 'flat',
    'path' => 'cast.characters.0.voice', 'fix' => 'All this talk made him horny.']]);
ok('repass floor: a sexual rewrite aimed at a child\'s own field is dropped, and says why',
    $kidApplied['fixed'] === 0
    && str_contains((string)($kidApplied['findings'][0]['say'] ?? ''), 'who is a child here'),
    json_encode($kidApplied['findings'][0] ?? null));
// The same rewrite against an adult's field lands — the floor is a floor,
// not a ban on the lens: what it guards is WHO the field belongs to. The save
// resolves by SLUG under the worlds dir (xeric_review_save), so the world has
// to really be on the shelf; the refusal cases above never get that far.
$devGrown = $devW;
$devGrown['slug'] = 'dev-lens-grown';
$devGrown['template']['cast']['characters'][0]['age'] = 34;
$grownDir = xeric_web_worlds_dir() . '/dev-lens-grown';
@mkdir($grownDir, 0775, true);
file_put_contents($grownDir . '/world-template.json',
    json_encode($devGrown['template'], JSON_UNESCAPED_UNICODE));
$grownApplied = xeric_repass_apply($devGrown, [['kind' => 'develop', 'about' => 'x', 'say' => 'flat',
    'path' => 'cast.characters.0.voice', 'fix' => 'All this talk made him thirsty.']]);
ok('repass floor: the same field on an adult takes its rewrite — the guard reads the subject\'s age',
    $grownApplied['fixed'] === 1, json_encode($grownApplied['findings'][0] ?? null));

// ---------------------------------------------------------------------------
// Homes and the opening scene (owner, 2026-08-02)
// ---------------------------------------------------------------------------
// Everybody lives somewhere, the world opens onto somebody, and the pass
// cannot be talked into housing a person twice.

$hAns = ['scale' => 'a small town', 'name' => 'Vera', 'job' => 'run the night desk',
         'hours' => '22:00-06:00', 'motivation' => 'company', 'around' => 'late nights',
         'pace' => 'steady', 'centrality' => 'ensemble', 'rating' => 'sfw'];
$hOut = xeric_forge_build($hAns, [], ['places' => 6, 'cast' => 5, 'seed' => false, 'interview' => $iv]);
$hT   = $hOut['template'];
$hHomes = array_values(array_filter((array)$hT['places'], fn($p) => ($p['kind'] ?? '') === 'home'));
$hCast  = (array)$hT['cast']['characters'];

ok('homes: every forged character has one', count($hHomes) >= 1 && (function () use ($hT, $hCast): bool {
    foreach ($hCast as $c) if (xeric_world_home_of($hT, (string)$c['handle']) === null) return false;
    return true;
})());
ok('homes: the built world still validates', err(fn() => xeric_world_validate($hT, 'homes')) === '');
ok('homes: three in the morning is a town asleep at home, not a ghost town',
    (function () use ($hT, $hCast): bool {
        $p = xeric_world_who_is_where($hT, ['dow' => 2, 'hhmm' => '03:00']);
        foreach ($hCast as $c) {
            $row = $p[(string)$c['handle']] ?? [];
            if (($row['where'] ?? null) === null) return false;
        }
        return true;
    })());
ok('first_contact: the world opens onto somebody, chosen without a model',
    ($hT['cast']['first_contact'] ?? '') !== ''
    && (bool)array_filter($hOut['notes'], fn($n) => str_contains((string)$n, 'first contact')));

// The model path, adversarially: a stub that shares one household, invents a
// stranger, and double-books somebody. The pass keeps the share, drops the
// stranger, strips the duplicate, and houses whoever was forgotten.
$hStrip = $hT;
$hStrip['places'] = array_values(array_filter((array)$hT['places'], fn($p) => ($p['kind'] ?? '') !== 'home'));
$h0 = (string)$hCast[0]['handle']; $h1 = (string)$hCast[1]['handle']; $h2 = (string)$hCast[2]['handle'];
$hStub = ['base' => 'stub://', 'stub' => fn(string $tag, array $m, array $o) => ['households' => [
    ['who' => [$h0, $h1], 'name' => 'The rooms over the laundry', 'desc' => 'Two beds, one kettle.'],
    ['who' => ['ghost_handle'], 'name' => 'Nowhere house', 'desc' => 'x'],
    ['who' => [$h2, $h0], 'name' => 'Double-booked', 'desc' => 'dup'],
]]];
$hM = xeric_forge_pass_homes($hStrip, $hStub);
$hShared = array_values(array_filter($hM, fn($p) => count((array)$p['residents']) === 2));
ok('homes: a shared household survives and a shared roof is two residents',
    count($hShared) === 1 && $hShared[0]['residents'] === [$h0, $h1]);
ok('homes: an invented stranger houses nobody',
    !array_filter($hM, fn($p) => in_array('ghost_handle', (array)$p['residents'], true)));
ok('homes: a double-booked person keeps their first roof only',
    count(array_filter($hM, fn($p) => in_array($h0, (array)$p['residents'], true))) === 1);
ok('homes: everyone the model forgot is housed solo, and the set validates',
    (function () use ($hStrip, $hM, $hCast): bool {
        $t = $hStrip; $t['places'] = array_merge($t['places'], $hM);
        foreach ($hCast as $c) if (xeric_world_home_of($t, (string)$c['handle']) === null) return false;
        return err(fn() => xeric_world_validate($t, 'stub-homes')) === '';
    })());
ok('homes: the pass is idempotent — a housed world gets no second roofs',
    xeric_forge_pass_homes($hT, $hStub) === []);

// ---------------------------------------------------------------------------
// THE STORY DOOR. The engine has had a mystery in it for days and no way in;
// this is the way in, and it is the shape-builder's lesson at full size — the
// model proposes the STORY and code assembles the SCHEMA, so a model that
// writes a wonderful story and a malformed file still produces a world that
// runs. Every assertion here drives a spec that is wrong on purpose.
// ---------------------------------------------------------------------------

echo "\n# the story door\n";

require_once __DIR__ . '/../story-forge.php';
$stT = xeric_world_load(__DIR__ . '/../../engine/fixtures/milldale.json');

// A spec that breaks four rules at once: a CHILD as culprit, the culprit also
// listed as protected, a red herring pointing at the culprit, and half the
// required fields simply missing.
$stBad = [
    'title' => 'The Mill Gate', 'logline' => 'Somebody has been in the mill at night.',
    'truth' => 'Harlan has been letting himself in since the spring.',
    'culprit' => 'theo',
    'protect' => [['character' => 'theo', 'must_not_know' => 'who has the key'],
                  ['character' => 'harlan']],
    'beats' => [['title' => 'a light in the office', 'holder' => 'dot'],
                ['title' => 'the chain was cut', 'place' => 'mill'],
                ['title' => 'the key on his ring', 'holder' => 'ruth']],
    'red_herrings' => [['believer' => 'pastor_dale', 'belief' => 'Kids from the county',
                        'because' => 'He saw bikes', 'actually' => 'They were fishing']],
];
$stS = xeric_forge_story_build($stBad, $stT, 'the_mill_gate');

ok('story: a child named as the culprit is quietly replaced by an adult',
    $stS['cast']['culprit'] !== 'theo'
    && !xeric_is_minor(xeric_world_character($stT, $stS['cast']['culprit'])), $stS['cast']['culprit']);
ok('story: nobody is protected from their own guilt',
    !in_array($stS['cast']['culprit'], array_column($stS['cast']['protect'], 'character'), true));
ok('story: every protected head gets a namespaced wall that actually exists',
    count($stS['walls']) === count($stS['cast']['protect'])
    && (bool)array_filter($stS['walls'], fn($w) => str_starts_with((string)$w['key'], 'story.the_mill_gate.')));
ok('story: the beats are ordered, spaced, and chained so each waits for the last',
    count($stS['beats']) === 3
    && $stS['beats'][0]['at'] < $stS['beats'][1]['at'] && $stS['beats'][1]['at'] < $stS['beats'][2]['at']
    && ($stS['beats'][1]['opens_when']['after'] ?? []) === [$stS['beats'][0]['key']]);
ok('story: a held beat gets all four fields it needs, defaulted where the model was silent',
    ($stS['beats'][0]['piece'] ?? '') !== '' && ($stS['beats'][0]['while_locked'] ?? '') !== ''
    && ($stS['beats'][0]['when_open'] ?? '') !== '' && ($stS['beats'][0]['spilled_as'] ?? '') !== '');
ok('story: an unheld beat becomes an hour the world writes instead',
    isset($stS['beats'][1]['as_event']) && !isset($stS['beats'][1]['holder']));
ok('story: a wrong lead never points at the answer, and is disposed of exactly once',
    ($stS['red_herrings'][0]['points_at'] ?? '') !== $stS['cast']['culprit']
    && ($stS['red_herrings'][0]['collapses_on'] ?? '') !== '');
ok('story: the snake is OMITTED — the world\'s own shape paces it',
    !isset($stS['snake']));
ok('story: and a wrong accusation is never an ending',
    ($stS['resolution']['on_wrong']['closes'] ?? true) === false
    && $stS['resolution']['answer'] === $stS['cast']['culprit']);

// THE ASSERTION THAT MATTERS: it loads. Validated exactly the way
// xeric_story_for() will validate it, world shape filled in.
$stErr = err(fn() => xeric_story_validate(xeric_forge_story_probe($stS, $stT), $stT, 'built'));
ok('story: the built overlay validates the way the loader will load it', $stErr === '', $stErr);

// An empty spec is a story with no answer in it, and says so rather than
// writing an overlay nobody can solve.
ok('story: nothing to find out is refused outright',
    err(fn() => xeric_forge_story_build([], $stT, 'x')) !== '');

// And the round trip: written where the loader looks, read back, composed.
$stDir = sys_get_temp_dir() . '/xeric-story-door-' . getmypid();
@mkdir($stDir, 0775, true);
@copy(__DIR__ . '/../../engine/fixtures/milldale.json', $stDir . '/world-template.json');
xeric_forge_story_save($stS, $stDir);
$stLoaded = xeric_story_for($stDir, $stT);
ok('story: it lands where the loader looks and comes back whole',
    count($stLoaded) === 1 && (string)$stLoaded[0]['key'] === 'the_mill_gate'
    && !empty($stLoaded[0]['snake']['inherited']));
foreach (glob($stDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($stDir);

echo $FAILED === 0 ? "\nall good\n" : "\n$FAILED failed\n";
exit($FAILED === 0 ? 0 : 1);
