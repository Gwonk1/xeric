<?php
/**
 * Xeric — sweeps. The world doing things while nobody is watching.
 *
 * This is the file the whole product is selling. A chat box answers when spoken
 * to; a world has a Tuesday evening whether or not anyone logs in, and then
 * somebody mentions it. Everything here exists to make that second sentence true
 * without it reading as a press release from the simulation.
 *
 * ── WHAT MAY HAPPEN IS NOT UP TO THE MODEL ────────────────────────────────
 *
 * A model asked "what happened tonight?" will write a wedding, a death, and a
 * stranger from out of town, in that order, and none of it will belong to the
 * world the user forged. So the SHAPE of an event is chosen here, from the
 * subsystems the forge armed (`template.forge.armed`), and the model is only ever
 * asked to fill in a shape that the world already permits. A world that armed
 * `shared_meals` gets people eating together. A world that armed nothing of the
 * kind never does, no matter how much the model would like to.
 *
 * ── WHO MAY BE IN IT IS NOT UP TO THE MODEL EITHER ────────────────────────
 *
 * Participants come from xeric_world_who_is_where(): people who are ON SHIFT are
 * at work and mostly unavailable, people off-shift are free, and an event needs
 * either two people in the same room or two people with nothing else on. This is
 * what stops the 3pm event where the night-shift clerk, who is asleep, has coffee
 * with the mechanic, who is under a truck.
 *
 * ── DIVERGENT MEMORIES ARE THE POINT ──────────────────────────────────────
 *
 * The single best thing about the private engine this was extracted from: two
 * people who were at the same thing do NOT remember the same thing. She remembers
 * what he did with his hands; he remembers that she left early. When both
 * memories are the same sentence twice, the world collapses into an omniscient
 * narrator wearing two hats, and every later conversation gives it away. So the
 * divergence is measured, not hoped for, and an event whose memories are one
 * memory is refused outright — which is a better failure than a flat world.
 *
 * ── GUARDS ────────────────────────────────────────────────────────────────
 *
 * A sweep is idempotent per WINDOW: the hour is the unit, the guard is a
 * world_state key, and a caller that runs the sweep six times in one minute gets
 * one hour's worth of life. The cadence knob on top of that (`chance`) is what
 * keeps a lived-in week from turning into a soap opera; quiet hours are what keep
 * the cast from having a dinner party at four in the morning.
 *
 * The age floor is the third refusal in here, alongside the wall and the
 * collision, and it works the same way: checked before the transaction opens,
 * thrown rather than corrected, nothing written. It gates sex and nothing else —
 * a child is at the thing, remembers his own half of it, and is a witness like
 * anybody else, which in a mystery is usually the most useful thing in the room.
 *
 * ── AND WHEN THE WORLD IS CARRYING A STORY ────────────────────────────────
 *
 * An overlay does not get a scheduler. It gets a thumb on the two knobs this
 * file already turns every window — how much happens (`sweep_chance`) and what
 * kind of thing it is (the `weight` float on every surviving kind) — plus one
 * exemption: a BEAT is not rolled for. Everything else about a sweep is
 * unchanged, and a world with no overlay pays nothing and behaves exactly as it
 * did before overlays existed. That is the whole integration, and it is the
 * reason a story can end by simply no longer being passed in.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/death.php';    // the dead are at no hour that happens
require_once __DIR__ . '/seed.php';      // name → handle resolution, shared with the seeder
require_once __DIR__ . '/chat.php';      // the model seam, the deduper, reply hygiene
require_once __DIR__ . '/learn.php';     // what the person living here actually engages with

// story.php is deliberately NOT required here. It requires THIS file, for the
// kind names its thumb is allowed to push on, and that is the right way round:
// sweeps are the lower layer and a world with no overlay must not pay for the
// overlay engine. Overlays arrive already loaded, in $opts['stories'], and
// nothing below reaches for a xeric_story_* function until one actually has.
//
// shape.php IS required, and the distinction is the whole reason it is a
// separate file. A world has a rhythm whether or not any story is laid on it —
// the curve is world data, not overlay data — so the arithmetic that reads a
// curve lives one layer down where a shapeless world can reach it for the price
// of a function call. What stayed in story.php is what is genuinely about
// overlays: beats, walls, victims, resolution, composition. A world running no
// shape still pays nothing: xeric_story_ambient() returns [] on the first line.
require_once __DIR__ . '/shape.php';
require_once __DIR__ . '/weather.php'; // the day's sky, derived, never stored
require_once __DIR__ . '/mood.php';    // and the town's own needle, which its hours move
require_once __DIR__ . '/ledger.php';  // and the ledgers those hours earn

/** The unit of offscreen life. One hour: short enough to place an event in a real
 *  shift, long enough that a day is not 1,440 chances to interrupt somebody. */
const XERIC_SWEEP_WINDOW = 3600;

/** How often a window that COULD produce an event actually does. */
const XERIC_SWEEP_CHANCE = 0.35;

/**
 * Which window an epoch falls in. FLOOR division, not intdiv().
 *
 * intdiv() truncates toward zero, so below 1970 the two disagree: epoch −100
 * lives in window −1, and intdiv says 0 — the hour walked and the guard written
 * would name different windows, off by one for every pre-1970 world. Every
 * window index in this file comes through here so the walker, the guard and the
 * watermark can never disagree about which hour an epoch belongs to.
 */
function xeric_sweep_windex(int $epoch, int $size): int
{
    $q = intdiv($epoch, $size);
    return ($epoch % $size < 0) ? $q - 1 : $q;
}

/** Two, or three. Four people at a thing is a scene, and a scene needs dialogue. */
const XERIC_SWEEP_MAX_PARTICIPANTS = 3;

/**
 * How alike two participants' memories may be before they are one memory.
 * Generous on purpose — two accounts of the same hour SHOULD share nouns. What
 * this catches is the failure where the model writes one sentence and reuses it.
 */
const XERIC_SWEEP_DIVERGE = 0.72;

/** How alike a new memory may be to something that person already remembers. */
const XERIC_SWEEP_ECHO = 0.75;

/** How often an event that COULD be about the world's protected secret is. */
const XERIC_SWEEP_SPINE = 0.45;

// ---------------------------------------------------------------------------
// The catalogue: armed systems → what can happen
// ---------------------------------------------------------------------------

/**
 * Every shape of event the engine knows, and the subsystems that unlock it.
 *
 * `systems`  any one of these being armed makes the kind available. A kind whose
 *            systems are all unarmed can never be generated — that is the whole
 *            contract with the forge: arming `visits` is what buys you visits.
 * `spine`    this kind CAN be about the thing the world is keeping from its
 *            protected character. Only these kinds ever exclude that character.
 * `quiet`    may happen during the user's quiet hours (see xeric_sweep_quiet).
 *            Only trouble gets to wake somebody up.
 * `shape`    what the model is asked to fill in. Written as a constraint on the
 *            event, never as a plot.
 */
function xeric_sweep_kinds(): array
{
    return [
        'shared_meal' => [
            'systems' => ['shared_meals'],
            'shape'   => 'they ended up eating together, what was brought, who paid, who stayed after',
        ],
        'visit' => [
            'systems' => ['visits', 'gentle_proactive_contact'],
            'shape'   => 'one of them turned up where the other was, unasked, and stayed a while',
        ],
        'routine' => [
            'systems' => ['daily_rhythms'],
            'shape'   => 'the ordinary business of the hour, done in each other\'s company, with one thing about it that was not ordinary',
        ],
        'craft' => [
            'systems' => ['craft'],
            'shape'   => 'work got done, well, or badly, and somebody was there to see which',
        ],
        'mishap' => [
            'systems' => ['danger', 'scarcity'],
            'shape'   => 'something went wrong: a shortage, a near miss, a cost somebody now has to carry',
            'quiet'   => true,
        ],
        'rumor' => [
            'systems' => ['rumors', 'unreliable_witnesses'],
            'shape'   => 'a story passed between them and came out the other side changed',
            'spine'   => true,
        ],
        'confidence' => [
            'systems' => ['secrets'],
            'shape'   => 'something held back nearly came out, or came out, to the wrong person',
            'spine'   => true,
        ],
        'glimpse' => [
            'systems' => ['slow_reveal', 'strange_place'],
            'shape'   => 'the world gave up one more piece of itself: small, concrete, and not explained',
            'spine'   => true,
        ],
        'friction' => [
            'systems' => ['rivals', 'jealousy', 'standings', 'the_ladder'],
            'shape'   => 'an edge showed, who was measured against whom, and who noticed it happening',
        ],
        'closeness' => [
            'systems' => ['attraction', 'arcs', 'private_history'],
            'shape'   => 'the distance between them changed by a small, deniable amount',
        ],
        'favor' => [
            'systems' => ['favors', 'alliances_that_cost', 'a_debt'],
            'shape'   => 'one of them did the other a real favour, and it is now owed',
        ],
        'ritual' => [
            'systems' => ['faith'],
            'shape'   => 'something was kept, an obligation, a rite, an hour that had to be sat through',
        ],
        'absence' => [
            'systems' => ['grief'],
            'shape'   => 'somebody who is not here was present anyway, in a small physical way',
        ],
        'ease' => [
            'systems' => ['comfort_systems'],
            'shape'   => 'a small undeserved comfort: nothing happened, and it was good',
        ],
        'recognition' => [
            'systems' => ['people_who_remember', 'remembering', 'a_chance_to_be_different'],
            'shape'   => 'the past came up, and somebody was recognised as who they used to be',
        ],
        'chase' => [
            'systems' => ['boons'],
            'shape'   => 'somebody moved on the thing worth having, and it cost them something',
        ],
        // THE ONLY KIND THAT CHANGES THE CAST, and the only one that cannot be
        // undone in a world that said death is permanent. Its system is
        // `mortality` and NOTHING arms that by default — not the forge, not the
        // interview, not the built-in tables — so a world produces this only
        // because somebody went and switched it on, which is the correct amount
        // of friction in front of a generator that removes people.
        //
        // The ENGINE picks who, before the model is asked, and hands it down
        // (xeric_sweep_lethal). A model told "somebody dies, you choose" will
        // reliably choose whoever is most narratively convenient, which over a
        // month is whoever the player talks to most.
        'loss' => [
            'systems' => ['mortality'],
            // RARE EVEN WHEN ARMED, and this number is the difference between a
            // world with mortality in it and a world being emptied. Every other
            // kind sits at 1.0 and there are seventeen of them; at parity a
            // fortnight of skipping would bury a small town. The weight is a base
            // that learn.php's thumb multiplies rather than replaces, so a person
            // who engages with these hours will see slightly more of them and
            // still not many.
            'base'    => 0.12,
            'shape'   => 'somebody died, and this is the hour the others found out, where they were '
                       . 'standing, who said it, and what was in their hands at the time',
            'quiet'   => true,
            'lethal'  => true,
        ],
        // The floor. Available ONLY to a world that armed nothing at all — a
        // hand-built template with no forge block still has to be alive.
        'ordinary' => [
            'systems' => [],
            'shape'   => 'an ordinary hour that still left a mark: one small, specific thing done, said, or noticed',
        ],
    ];
}

/** What this world switched on. `forge.armed` is where the forge writes it. */
function xeric_sweep_armed(array $t): array
{
    $armed = [];
    foreach ((array)($t['forge']['armed'] ?? []) as $s) {
        $s = (string)$s;
        if ($s !== '') $armed[] = $s;
    }
    return array_values(array_unique($armed));
}

function xeric_sweep_disarmed(array $t): array
{
    $out = [];
    foreach ((array)($t['forge']['disarmed'] ?? []) as $s) {
        $s = (string)$s;
        if ($s !== '') $out[] = $s;
    }
    return array_values(array_unique($out));
}

/**
 * The kinds this world may produce, keyed by name.
 *
 * A kind survives when at least one of its systems is armed and none of the
 * systems it would fire is disarmed. `ordinary` is the floor and appears only
 * when the world armed nothing — otherwise an armed world would keep producing
 * generic hours instead of the life it asked for.
 *
 * WHAT IS ARMED IS A HARD GATE; WHAT IS ENGAGED WITH IS A THUMB. Pass a database
 * and each surviving kind also carries a `weight` from learn.php — the kinds this
 * particular person actually followed up on are tried sooner, the ones they have
 * walked past every time are tried later. It NEVER removes a kind: the weight has
 * a floor (XERIC_LEARN_KIND_FLOOR) precisely so that a world cannot narrow itself
 * to the four things you liked last week and stop being able to surprise you.
 * Without a database — and in a world with nothing learned yet — every weight is
 * 1.0 and the ordering below falls back to the plain shuffle it always was.
 */
function xeric_sweep_kinds_for(array $t, ?PDO $db = null): array
{
    $armed    = xeric_sweep_armed($t);
    $disarmed = xeric_sweep_disarmed($t);

    $out = [];
    foreach (xeric_sweep_kinds() as $name => $k) {
        $systems = (array)$k['systems'];
        if ($systems === []) continue;                                  // the floor, handled below
        if (array_intersect($systems, $disarmed) !== []) continue;      // explicitly switched off
        if (array_intersect($systems, $armed) === []) continue;         // never switched on
        $out[$name] = $k + ['key' => $name, 'spine' => false, 'quiet' => false, 'lethal' => false];
    }

    if ($out === []) {
        $k = xeric_sweep_kinds()['ordinary'];
        $out['ordinary'] = $k + ['key' => 'ordinary', 'spine' => false, 'quiet' => false, 'lethal' => false];
    }

    // A kind's `base` is how rare it is BY NATURE; learn.php's weight is how much
    // this particular person engages with it. They multiply — the thumb never
    // promotes a rare kind to an ordinary one, and never demotes an ordinary kind
    // below the floor it already has.
    $weights = $db !== null ? xeric_learn_kind_weights($db, array_keys($out)) : [];
    foreach ($out as $name => $k) {
        $out[$name]['weight'] = (float)($k['base'] ?? 1.0) * (float)($weights[$name] ?? 1.0);
    }

    return $out;
}

/**
 * The order kinds are tried in.
 *
 * A world that has learned nothing shuffles, which is exactly what this used to
 * do and is byte-for-byte the same sequence of rolls — a seeded caller gets the
 * behaviour it had before learning existed. Once something IS known, it becomes a
 * weighted draw without replacement: a favoured kind is more likely to be tried
 * first, and every kind is still somewhere in the list.
 */
function xeric_sweep_kind_order(array $kinds): array
{
    $byKey   = [];
    $weights = [];
    foreach ($kinds as $k) {
        $key = (string)($k['key'] ?? '');
        if ($key === '') continue;
        $byKey[$key]   = $k;
        $weights[$key] = (float)($k['weight'] ?? 1.0);
    }

    $out = [];
    foreach (xeric_learn_order(array_keys($byKey), $weights) as $key) $out[] = $byKey[$key];
    return $out;
}

/**
 * The characters this world is keeping something from.
 *
 * `special_roles[].must_not_know` is a wall, not a rendering note: a sweep that
 * put the protected character in the room while the secret was being handled
 * would have made the thing true in the world, and no prompt-side redaction can
 * un-happen it afterwards.
 *
 * @return array<string,string> handle => what they must not know
 */
function xeric_sweep_protected(array $t): array
{
    $out = [];
    foreach ((array)($t['cast']['special_roles'] ?? []) as $sr) {
        $h    = (string)($sr['character'] ?? '');
        $what = trim((string)($sr['must_not_know'] ?? ''));
        if ($h !== '' && $what !== '') $out[$h] = $what;
    }
    return $out;
}

/**
 * Does this line carry the thing somebody must not know?
 *
 * Not a redactor — a refusal, and one that is deliberately wrong in the safe
 * direction. A secret in this engine is written the way a person would say it
 * out loud ("who really emptied the building fund"), so a line that hands most
 * of its content words back is a line that put it in somebody's head, whatever
 * order they came back in. A line that merely shares one noun with it survives.
 */
function xeric_sweep_touches(string $text, string $secret): bool
{
    $words = static function (string $s): array {
        $s   = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)) ?? $s;
        $out = [];
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
            if (mb_strlen($w) >= 4) $out[$w] = true;         // who, the, and, out
        }
        return $out;
    };

    $want = $words($secret);
    // A secret with no words in it is one this test cannot help with; fall back
    // to the literal string rather than answering "no" to everything.
    if ($want === []) return str_contains(mb_strtolower($text), mb_strtolower(trim($secret)));

    $need = count($want) <= 2 ? count($want) : (int)ceil(count($want) / 2);
    return count(array_intersect_key($want, $words($text))) >= $need;
}

// ---------------------------------------------------------------------------
// The overlay: what a story does to a sweep, and what it may never do
// ---------------------------------------------------------------------------

/**
 * The overlays this window is running under: the ones the caller composed with,
 * minus any that have closed.
 *
 * A story that resolved between two windows must stop pacing this one, and it
 * stops by SUBTRACTION — the `closed` arc is the entire state change and its
 * absence is what live means. An empty list short-circuits before story.php is
 * touched at all, which is what keeps a world with no overlay paying nothing.
 */
function xeric_sweep_stories(array $t, PDO $db, array $opts): array
{
    $given = (array)($opts['stories'] ?? []);
    return $given === [] ? [] : xeric_story_active($given, $db, $t);
}

/**
 * What the open stories did to this window — a record, not a decision.
 *
 * why.php prints this, so THE FALSE CALM has to be legible in it. That stretch is
 * the one part of the shape a tuning user will read as the world having gone
 * slack, and "mill_stairwell is in the false calm — the town at exactly its own
 * pace" is the difference between a feature and a bug report. Everything in here
 * is already decided by the time it is written, and none of it decides anything.
 */
function xeric_sweep_story_trail(array $stories, PDO $db, int $epoch, float $worldChance, float $rolled, array $before, array $after, ?array $beat): array
{
    $why = [
        'opening'    => 'the opening, introductions and texture',
        'rising'     => 'rising, the complications are coming faster',
        'taper'      => 'the taper, coming off the build',
        'false_calm' => 'the false calm, the town at exactly its own pace',
        'crescendo'  => 'the crescendo',
        'closing'    => 'closing, coming down to the end of it',
    ];

    $rows = [];
    $said = [];
    foreach ($stories as $s) {
        $p     = xeric_story_progress($s, $db, $epoch);
        $stage = (string)$p['stage'];
        $rows[] = [
            'key'       => xeric_story_key($s),
            'title'     => xeric_story_title($s),
            'stage'     => $stage,
            'p'         => round((float)$p['p'], 3),
            'intensity' => round((float)$p['intensity'], 3),
            'pace'      => round((float)$p['m'], 3),
            'beats'     => (int)$p['opened'] . ' of ' . (int)$p['total'] . ' opened, ' . (int)$p['spilled'] . ' told',
            'why'       => $why[$stage] ?? $stage,
        ];
        $said[] = xeric_story_key($s) . ' is in ' . ($why[$stage] ?? $stage);
    }

    // Only the kinds that actually moved. A thumb that changed nothing — which is
    // every thumb in a world that armed nothing at all — says so by being absent.
    $thumb = [];
    foreach ($after as $name => $k) {
        $was = (float)($before[$name]['weight'] ?? 1.0);
        $now = (float)($k['weight'] ?? 1.0);
        if ($was > 0.0 && abs($now - $was) > 1e-9) $thumb[$name] = round($now / $was, 3);
    }

    return [
        'live'   => $rows,
        'chance' => ['world' => round($worldChance, 4), 'rolled' => round($rolled, 4)],
        'pace'   => $worldChance > 0.0 ? round($rolled / $worldChance, 3) : 1.0,
        'thumb'  => $thumb,
        'beat'   => $beat !== null ? (string)($beat['key'] ?? '') : null,
        'why'    => implode('; ', $said),
    ];
}

/**
 * What the WORLD'S OWN shape did to this window. The same record, one level down.
 *
 * Written in the same fields as the story trail so why.php needs no second
 * reader: `live` carries one row, the world itself, with the stage it is in and
 * where on its cycle it stands. The one thing it must never do is read as a
 * story — a person tuning a shapeless xeric who sees "the crescendo" in the
 * inspector will go looking for a plot that does not exist — so the row is
 * titled with the shape rather than a story name, and a world running `none`
 * produces no trail at all rather than a row that says nothing is happening.
 */
function xeric_sweep_shape_trail(array $t, PDO $db, int $epoch, float $worldChance, float $rolled, array $before, array $after): array
{
    $a = xeric_story_ambient($t, $db, $epoch);
    if ($a === []) return [];                          // shapeless: there is nothing to explain

    $why = [
        'opening'    => 'the front of its cycle, introductions and texture',
        'rising'     => 'rising, things coming faster than usual',
        'taper'      => 'coming off the busy stretch',
        'false_calm' => 'at exactly its own pace, which is where this shape rests',
        'crescendo'  => 'the loud part of its cycle',
        'closing'    => 'settling back down',
    ];
    $stage = (string)$a['stage'];
    $shape = xeric_story_shape($t);
    $key   = xeric_story_shape_key($t);
    $cycle = (int)($shape['cycle_days'] ?? 0);

    $thumb = [];
    foreach ($after as $name => $k) {
        $was = (float)($before[$name]['weight'] ?? 1.0);
        $now = (float)($k['weight'] ?? 1.0);
        if ($was > 0.0 && abs($now - $was) > 1e-9) $thumb[$name] = round($now / $was, 3);
    }

    return [
        'live'   => [[
            'key'       => 'world:' . $key,
            'title'     => 'this xeric\'s own shape — ' . (string)($shape['label'] ?? $key),
            'stage'     => $stage,
            'p'         => round((float)$a['p'], 3),
            'intensity' => round((float)$a['intensity'], 3),
            'pace'      => round((float)$a['m'], 3),
            'beats'     => 'no beats — a ' . $cycle . '-day cycle, walked on the calendar',
            'why'       => $why[$stage] ?? $stage,
        ]],
        'chance' => ['world' => round($worldChance, 4), 'rolled' => round($rolled, 4)],
        'pace'   => $worldChance > 0.0 ? round($rolled / $worldChance, 3) : 1.0,
        'thumb'  => $thumb,
        'beat'   => null,
        'why'    => 'the xeric is ' . ($why[$stage] ?? $stage),
    ];
}

/**
 * Anything the WORLD itself can finish, finished. Checked after every window.
 *
 * An accusation is said to somebody's face and closes inside that conversation,
 * so there is nothing here for a sweep to notice. A `possession` resolution is
 * the other shape — it closes when the thing worth having is in somebody's hands
 * and nobody has to say a word for that to have happened — so the sweep is what
 * stands there and notices. Both doors are xeric_story_resolve(), which is also
 * what keeps `requires_beats` on the possession case: a boon nobody worked for
 * is a guess with a receipt.
 *
 * THE WORLD KEEPS RUNNING. Closing is one arc row and then the overlay stops
 * composing: the walls come down, the beliefs go, sweep_chance returns to this
 * world's own number, and the next window is an ordinary window in the same town
 * with the same six people in it.
 *
 * @return array notes, in words, because a story that ends silently is
 *         indistinguishable from a story that broke.
 */
function xeric_sweep_story_settle(PDO $db, array $now, array $stories): array
{
    $notes = [];
    foreach ($stories as $s) {
        $r = (array)($s['resolution'] ?? []);
        if ((string)($r['kind'] ?? '') !== 'possession') continue;

        // `boon.<key>` on anybody is the store's own word for the boon having
        // landed somewhere (state.php: the arc holds the epoch it goes stale at).
        $boon = (string)($r['boon'] ?? $r['answer'] ?? '');
        if ($boon === '' || xeric_arcs_by_key($db, 'boon.' . $boon) === []) continue;

        $done = xeric_story_resolve($s, $db, $boon, (int)($now['epoch'] ?? 0));
        if (empty($done['closed'])) continue;

        $notes[] = '"' . xeric_story_title($s)
                 . '" is over, the walls come down, the pace goes back to '
                 . "this world's own, and nothing else about the world changes";
    }
    return $notes;
}

// ---------------------------------------------------------------------------
// The entry point
// ---------------------------------------------------------------------------

/**
 * Decide whether something happens in this window, and if so make it happen.
 *
 * @param array $now      from xeric_world_now()/xeric_clock_now() — injected
 * @param array $opts     chance, window, force, seed, spine, max_participants,
 *                        model_rating, temperature, timeout, diverge_retries,
 *                        stories (the overlays the caller composed with)
 * @return array{events:array,notes:array}  `notes` is why nothing happened, in
 *         words a human can act on; an empty `events` is an ordinary outcome.
 * @throws RuntimeException when the model fails or answers with nothing usable.
 *         NOTHING is written in that case — not the event, not one memory, not
 *         the window guard, so the caller may simply try the window again.
 */
function xeric_sweep_run(array $t, PDO $db, array $endpoint, array $now, array $opts = []): array
{
    $stories = xeric_sweep_stories($t, $db, $opts);

    try {
        $out = xeric_sweep_window($t, $db, $endpoint, $now, $stories, $opts);
    } finally {
        // THE RESOLUTION IS CHECKED AFTER THE WINDOW, WHATEVER THE WINDOW DID.
        // What solved it happened in a conversation, between two windows — the
        // sweep is the world's heartbeat, not the story's, and it is simply the
        // thing standing there when the question stops being open. A refused
        // hour does not get to hold a finished story open either, which is why
        // this runs on the way out of a throw as well.
        $closed = xeric_sweep_story_settle($db, $now, $stories);
    }
    foreach ($closed as $n) $out['notes'][] = $n;
    return $out;
}

/**
 * One window, decided and lived. Split out of xeric_sweep_run() so that the
 * story settlement above happens on every path out of it, including the ones
 * that refuse the hour.
 */
function xeric_sweep_window(array $t, PDO $db, array $endpoint, array $now, array $stories, array $opts = []): array
{
    // ABSENCE, NOT SIGN. A world whose `setting.starts` is before 1970 stands
    // at a legitimately negative epoch — xeric_world_now() builds a perfectly
    // good 1873 morning from one — and the old `<= 0` read every hour of such
    // a world as "no moment passed", so its entire offscreen life threw while
    // chat kept answering. The sentinel for a missing moment is the missing key.
    if (!isset($now['epoch'])) throw new RuntimeException('sweep: a sweep needs a moment, pass xeric_world_now()');
    $epoch = (int)$now['epoch'];

    if (isset($opts['seed'])) mt_srand((int)$opts['seed']);

    $windowSize = max(60, (int)($opts['window'] ?? XERIC_SWEEP_WINDOW));
    $window     = xeric_sweep_windex($epoch, $windowSize);
    $guard      = 'sweep:' . $windowSize . ':' . $window;

    $prior = xeric_world_state_get($db, $guard);
    if ($prior !== null && empty($opts['force'])) {
        return xeric_sweep_nothing('this window has already been swept (' . $prior . ')');
    }

    // -- the gates, cheapest first ----------------------------------------
    // The db goes in so the kinds carry what this world has learned about which
    // of them land. It is still the template that decides what may happen at all.
    $kinds = xeric_sweep_kinds_for($t, $db);
    $plain = $kinds;

    // THE SNAKE'S SECOND KNOB. A story re-weights what this world has already
    // armed and may never arm anything: xeric_story_thumb() multiplies the same
    // `weight` float learn.php writes, so two thumbs on one scale compose the way
    // two thumbs should, and a kind_thumb naming a kind this world never armed
    // hits nothing at all. That invariant is what keeps an overlay out of the
    // attraction economy — it cannot reach a kind the forge did not switch on.
    // And the WORLD'S OWN shape is the same knob one level down. A world with
    // no overlay still has a rhythm if it was forged with one — it walks its
    // curve on the calendar instead of on beats — and the thumb obeys the same
    // rule for exactly the same reason: it re-weights what the forge armed and
    // can neither arm nor delete. A world forged shapeless returns $kinds
    // untouched, which is what every world did before shapes existed.
    if ($stories !== []) $kinds = xeric_story_thumb($kinds, $stories, $db, $epoch);
    else                 $kinds = xeric_story_ambient_thumb($kinds, $t, $db, $epoch);

    $quietWhy = null;
    if (xeric_sweep_quiet($t, $now, $quietWhy)) {
        // Quiet hours are the user's, not the cast's. The world may still hurt
        // somebody at 4am — that is what `danger` means — but it may not have a
        // dinner party, and it may certainly not text about one.
        $kinds = array_filter($kinds, fn($k) => !empty($k['quiet']));
        if ($kinds === []) {
            // An unreadable quiet-hours line rides out on the note, because the
            // only thing worse than a world that has gone quiet is one that has
            // gone quiet for a reason nobody is ever told.
            return xeric_sweep_skip($db, $guard, ($quietWhy ?? 'quiet hours')
                . ', nothing this world arms happens at ' . (string)($now['hhmm'] ?? ''));
        }
    }

    $phase  = (string)($now['phase'] ?? '');
    $events = (array)($t['events'] ?? []);
    if ($phase === 'night' && array_key_exists('night_events', $events) && !$events['night_events']) {
        return xeric_sweep_skip($db, $guard, 'this world does not have night events');
    }
    if ($phase !== 'night' && array_key_exists('day_events', $events) && !$events['day_events']) {
        return xeric_sweep_skip($db, $guard, 'this world does not have day events');
    }

    // -- the beat, which is not rolled for --------------------------------
    // Checked here, after the gates that decide whether this window can carry an
    // hour at all and before the one that decides whether it feels like it. A
    // beat fires in the first ELIGIBLE window: quiet hours and a world that does
    // not have night events still hold, because those are the user's and the
    // world's, and a story does not get to overrule either.
    $beat = $beatOf = null;
    foreach ($stories as $s) {
        $b = xeric_story_due($s, $db, $epoch);
        if ($b !== null) { $beat = $b; $beatOf = $s; break; }
    }

    // Cadence precedence: an explicit opt (a test, or the demo's time control)
    // beats the world's own derived rate, which beats the engine default. The
    // world's rate comes from the forge and is normalised PER VISIT, not per
    // hour — someone who looks in weekly needs a far lower hourly chance than
    // someone who looks in hourly to experience 'a few things happened'.
    //
    // THE SNAKE'S FIRST KNOB sits between those two: it scales the WORLD'S rate
    // and never an explicit one, because an explicit chance is an instruction
    // and silently scaling an instruction is how a forced 1.0 became a 0.9 and
    // a deterministic test became flaky. In the false calm the scale is exactly
    // 1.0 and this line is arithmetically the line it always was.
    $worldChance = (float)($t['events']['sweep_chance'] ?? XERIC_SWEEP_CHANCE);
    // A world's own shape sits in the same slot a story's would and obeys the
    // same precedence: an explicit chance is an instruction and is never scaled.
    // With no shape declared, xeric_story_ambient_chance() hands the world's own
    // number straight back, so this line is arithmetically the line it was.
    $chance      = isset($opts['chance'])
        ? (float)$opts['chance']
        : ($stories !== [] ? xeric_story_chance($t, $stories, $db, $epoch)
                           : xeric_story_ambient_chance($t, $db, $epoch));

    $snake = $stories !== []
        ? xeric_sweep_story_trail($stories, $db, $epoch, $worldChance, $chance, $plain, $kinds, $beat)
        : xeric_sweep_shape_trail($t, $db, $epoch, $worldChance, $chance, $plain, $kinds);

    if ($beat === null && !xeric_sweep_roll($chance)) {
        // The window is still burned. A caller that rerolled until it got an
        // event would have a cadence knob that does nothing but cost time.
        return xeric_sweep_skip($db, $guard, 'nothing happened this hour (rolled against ' . $chance . ')'
            . ($snake !== [] ? ', ' . (string)$snake['why'] : ''));
    }

    // -- who, and what shape ----------------------------------------------
    // A beat says what kind of hour it wants. It is a preference and not a new
    // scheduler: if this world armed that kind the chooser is given only it, and
    // if nobody can be at that shape of thing the ordinary order runs instead.
    $order = $kinds;
    if ($beat !== null) {
        $want = (string)($beat['as_event']['wants_kind'] ?? '');
        if ($want !== '' && isset($kinds[$want])) $order = [$want => $kinds[$want]];
    }

    // Read once, here, where the database is: an hour and the choice that made it
    // have to agree about who was alive for it.
    $opts['dead'] = xeric_dead_handles($db);

    $chosen = xeric_sweep_choose($t, $now, $order, $opts);
    if ($chosen === null && $order !== $kinds) $chosen = xeric_sweep_choose($t, $now, $kinds, $opts);
    if ($chosen === null) {
        return xeric_sweep_skip($db, $guard, 'nobody was plausibly together at ' . (string)($now['hhmm'] ?? ''));
    }

    if ($beat !== null) {
        // The story said where it happened. An overlay that names a place and
        // then has the engine overrule it is an overlay the author cannot aim.
        $where = (string)($beat['as_event']['place'] ?? '');
        if ($where !== '' && xeric_world_place($t, $where) !== null) $chosen['where'] = $where;
        $chosen['story_beat'] = ['story' => xeric_story_key($beatOf), 'beat' => $beat];
    }
    if ($snake !== []) $chosen['trail']['story'] = $snake;

    // A LETHAL HOUR NEEDS A BODY, chosen before the model is asked. No eligible
    // subject and the kind simply does not fire — dropped rather than softened
    // into an ordinary evening wearing a `loss` label, because the trail has to
    // say what actually happened.
    if (!empty($chosen['kind']['lethal'])) {
        $dying = xeric_sweep_lethal($t, $chosen, $opts['dead'], $opts);
        if ($dying === null) {
            return xeric_sweep_skip($db, $guard, 'the hour that came up was a death and there was nobody '
                . 'left outside the room for it to be about');
        }
        $chosen['dying'] = $dying;
        $chosen['trail']['dying'] = ['handle' => $dying, 'name' => xeric_world_name($t, $dying),
                                     'why' => 'this world armed mortality'];
    }

    // -- the one model call ------------------------------------------------
    // The live overlays ride along so the room can carry what the people in it
    // are sure of. They are the filtered set — a closed story composes nothing.
    $written = xeric_sweep_compose($t, $db, $endpoint, $now, $chosen, ['stories' => $stories] + $opts);

    if ($beat !== null) {
        $title = trim((string)($beat['as_event']['title'] ?? ''));
        if ($title !== '') {
            // The authored title takes the model's place, so it is scanned in the
            // model's place too. Safety code does not get an exemption for text
            // that arrived from a file rather than from an endpoint.
            $floor = xeric_age_floor($t, $chosen['handles'], [$title]);
            if ($floor !== null) throw new RuntimeException(xeric_age_refusal('sweep', $floor));
            $written['title'] = $title;
        }
    }

    // -- and only now does anything land ----------------------------------
    $at = xeric_state_time();
    $db->beginTransaction();
    try {
        // on_spine goes down with the row. The title was written to NAME the
        // thing, and an unmarked spine event replays into any later prompt that
        // has the protected character standing in it.
        $eventId = xeric_event_add($db, $written['title'], $epoch, $chosen['where'],
            array_keys($written['memories']), $written['prose'], $at, (bool)$chosen['on_spine'],
            (string)($written['overheard'] ?? ''));

        // THE TOWN'S MOOD MOVES WITH ITS HOURS. Inside the transaction, so a
        // needle can never record an hour that did not land — and the step
        // reverts toward ordinary as it pushes, which is what stops a world
        // from sitting at the end of its own range forever. Costs one row.
        xeric_mood_step($db, $t, (string)$chosen['kind']['key'], $at);

        // AND THE LEDGERS THE HOUR EARNED. `economies` has been fully
        // specified and never once written to — a casserole ledger nobody is
        // on, with three rules about how it works. The hour's own words
        // decide (engine/ledger.php), everybody who was in it is credited,
        // and xeric_state_counters() has been ready to read the result since
        // the day boards existed.
        xeric_ledger_step($db, $t, array_keys($written['memories']),
            $written['title'] . ' ' . $written['prose'], $at);

        foreach ($written['memories'] as $handle => $text) {
            xeric_memory_add($db, $handle, $text, 'event', [
                'event_id' => $eventId,
                'kind'     => $chosen['kind']['key'],
                'place'    => $chosen['where'],
                'with'     => array_values(array_diff(array_keys($written['memories']), [$handle])),
            ], $epoch, $at);
        }

        // The beat opened BECAUSE this hour landed, so it is written down inside
        // the same transaction or not at all: a beat marked open over an event
        // that was never stored is a story standing one step ahead of its own
        // world, and nothing downstream can tell that from a beat the player
        // actually reached. It opens and spills in one motion — nobody held it
        // back, so the hour IS the telling, and the beats gated behind it are free.
        if ($beat !== null) xeric_story_fire($beatOf, $db, (string)$beat['key'], $epoch, $at);

        // AND THE DEATH LANDS WITH THE HOUR, in the same transaction, or neither
        // does. `notice: false` — the hour IS the notice, and it carries what
        // each person in the room actually took away from hearing it, which is
        // better than anything death.php could write without a model.
        if (($chosen['dying'] ?? '') !== '') {
            xeric_death_kill($t, $db, (string)$chosen['dying'], $epoch, $written['title'], null, false);
        }

        xeric_world_state_set($db, $guard, 'event ' . $eventId, $at);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('sweep: could not store the event, ' . $e->getMessage(), 0, $e);
    }

    return [
        'events' => [[
            'id'           => $eventId,
            'title'        => $written['title'],
            'prose'        => $written['prose'],
            'place'        => $chosen['where'],
            'place_name'   => xeric_world_place_name($t, $chosen['where']),
            'participants' => array_keys($written['memories']),
            'memories'     => $written['memories'],
            'kind'         => $chosen['kind']['key'],
            'died'         => ($chosen['dying'] ?? '') !== '' ? (string)$chosen['dying'] : null,
            'on_spine'     => $chosen['on_spine'],
            'world_epoch'  => $epoch,
            'usage'        => $written['usage'],
            // Why this, why them, why here. The decision is already made and
            // stored; this is the reasoning behind it, carried out so a caller
            // can keep it (the demo writes it to world_state, keyed by event id)
            // and answer "why did that happen?" a week later.
            'why'          => $chosen['why'],
            'trail'        => (array)($chosen['trail'] ?? []),
            // Which beat this hour was, when it was one. A caller that shows the
            // shelf reads it; everything else ignores it.
            'story'        => $beat !== null
                ? ['key' => xeric_story_key($beatOf), 'beat' => (string)($beat['key'] ?? '')]
                : null,
        ]],
        'notes' => $written['notes'],
    ];
}

/**
 * Run the sweeps for a stretch of world time that has just gone by.
 *
 * This is what a time control calls after it advances the clock, and what a cron
 * calls after the machine was asleep: the world owes the user every hour it
 * skipped. Windows are walked oldest first so the events land in the order they
 * happened, and each one carries its own guard — a stretch that is swept twice
 * produces nothing the second time.
 *
 * @param array $opts as xeric_sweep_run, plus max_events, max_windows, and
 *              `clock` — the world epoch the world is standing on, when the
 *              stretch itself does not say. No event is ever stamped past it.
 *              `stories` rides through untouched, so a stretch of skipped hours
 *              paces to the snake window by window and a beat owed to the world
 *              lands in the hour it was owed rather than all at the far end.
 */
function xeric_sweep_catchup(array $t, PDO $db, array $endpoint, int $fromEpoch, int $toEpoch, array $opts = []): array
{
    $windowSize = max(60, (int)($opts['window'] ?? XERIC_SWEEP_WINDOW));
    $maxEvents  = (int)($opts['max_events'] ?? 3);
    $maxWindows = (int)($opts['max_windows'] ?? 48);

    $first = xeric_sweep_windex(min($fromEpoch, $toEpoch), $windowSize);
    $last  = xeric_sweep_windex(max($fromEpoch, $toEpoch), $windowSize);

    // THE CLOCK IS THE EDGE OF THE WORLD. Events are ordered by world_epoch
    // everywhere they are read, so an hour sampled half a window past the moment
    // the world is standing in leads the feed under a header that says 20:05 —
    // the simulation telling on itself. A caller handing over a stretch has
    // already said where its far end is; one naming a single window by its start
    // epoch has said nothing about the clock, and passes `clock` when it can.
    // Null is "nobody told us", the way the epoch sentinel works: an 1873 world
    // hands over a real, negative clock, and a zero-as-absent convention would
    // silently disable both clamps below for every pre-1970 world.
    $clock = $opts['clock'] ?? ($fromEpoch !== $toEpoch ? max($fromEpoch, $toEpoch) : null);
    if ($clock !== null) $clock = (int)$clock;
    // An hour the clock has not entered at all is not late, it is next: left
    // alone, and left out of the watermark, so the following call still has it.
    if ($clock !== null && $clock <= $last * $windowSize) $last--;

    // The per-window guard makes one HOUR happen once; this makes one STRETCH
    // happen once. Without it, a catch-up that stopped at max_events would leave
    // the tail of the stretch unswept, and pressing "skip six hours" a second
    // time would mine the same afternoon again instead of moving on.
    $mark = xeric_world_state_get($db, 'sweep_watermark:' . $windowSize);
    if ($mark !== null && empty($opts['force']) && (int)$mark >= $first) $first = (int)$mark + 1;

    $out    = ['events' => [], 'notes' => []];
    $seen   = 0;
    $misses = 0;
    if ($first > $last) {
        $out['notes'][] = 'that stretch of the world has already been lived';
        return $out;
    }

    for ($w = $first; $w <= $last; $w++) {
        if ($seen++ >= $maxWindows) { $out['notes'][] = "stopped after $maxWindows windows"; break; }
        if ($maxEvents > 0 && count($out['events']) >= $maxEvents) break;

        // Mid-window, not the edge: shifts hand over on the hour (half-open
        // [from,to)), and a sweep standing exactly on the boundary would keep
        // finding an empty room at the moment everybody swaps places. The hour
        // still in progress is the exception — it is sampled where the world
        // actually stands, because there is no such thing as later than now.
        $at  = $w * $windowSize + intdiv($windowSize, 2);
        if ($clock !== null && $at > $clock) $at = $clock;
        $now = xeric_world_now($t, $at);

        try {
            $r = xeric_sweep_run($t, $db, $endpoint, $now, $opts);
            $misses = 0;
        } catch (Throwable $e) {
            // One refused hour is ordinary — a model repeats itself, an event is
            // rejected for being two of the same memory, the world simply has a
            // quiet hour instead. Several in a row means the model is gone, and
            // grinding through 48 windows against a dead endpoint helps nobody.
            $out['notes'][] = $now['hhmm'] . ', ' . $e->getMessage();
            if (++$misses >= (int)($opts['max_misses'] ?? 2)) {
                $out['notes'][] = 'gave up after ' . $misses . ' hours in a row that came back wrong';
                $gaveUp = true;
                break;
            }
            continue;
        }
        foreach ($r['events'] as $e) $out['events'][] = $e;
        foreach ($r['notes'] as $n) $out['notes'][] = $now['hhmm'] . ', ' . $n;
    }

    // THE WATERMARK MAY NOT CLAIM HOURS NOBODY LIVED. The early exits above are
    // three different promises. Stopping at max_events or max_windows buries the
    // tail on purpose — one stretch happens once, and pressing skip twice must
    // not mine the same afternoon again. GIVING UP IS NOT THAT: the failed
    // windows deliberately left their guards unwritten so they could be retried
    // (xeric_sweep_run's own contract), and a watermark stamped past them made
    // a six-hour model outage permanently end a world's offscreen life — every
    // later catchup computed first > last and returned "already been lived"
    // without a single model call. So on the give-up path the mark stops at the
    // last window BEFORE the failing streak, and it only ever moves forward:
    // going backward would re-open hours an earlier call legitimately buried.
    if (empty($opts['force'])) {
        $upTo = ($gaveUp ?? false) ? $w - $misses : $last;
        $prev = xeric_world_state_get($db, 'sweep_watermark:' . $windowSize);
        if ($prev === null || (int)$prev < $upTo) {
            xeric_world_state_set($db, 'sweep_watermark:' . $windowSize, $upTo);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Choosing what happens, and to whom
// ---------------------------------------------------------------------------

/**
 * A kind, a cast, a place, and whether this one is about the world's secret.
 *
 * Kind first, people second, because whether the protected character may be in
 * the room depends on what the event is ABOUT. Kinds are tried in random order
 * until one of them has somebody available: a world whose only armed system is
 * `secrets` still gets ordinary evenings when its protected character is the
 * only person awake, rather than a silent night.
 *
 * ── THE TRAIL ─────────────────────────────────────────────────────────────
 *
 * Everything decided in here used to be forgotten the moment it was decided,
 * which made "why did THAT happen?" — the question anybody tuning a world asks
 * first — unanswerable after the fact. So the reasoning comes back with the
 * choice, in `trail`: which kinds this world could produce, which were tried
 * and why they came to nothing, who was standing where, who was kept out and
 * on what grounds, and which of the plausible groupings won. It DECIDES
 * nothing; it is a record of a decision already made, and a caller that ignores
 * it gets exactly the behaviour it always got.
 *
 * @return array{kind:array,handles:array,where:?string,on_spine:bool,why:string,trail:array}|null
 */
function xeric_sweep_choose(array $t, array $now, array $kinds, array $opts = []): ?array
{
    $protected  = xeric_sweep_protected($t);
    $maxPeople  = max(2, (int)($opts['max_participants'] ?? XERIC_SWEEP_MAX_PARTICIPANTS));

    // WHO IS DEAD, handed in rather than read: this function takes a template and
    // a moment and no database, which is what makes the chooser testable against
    // a world that does not exist. xeric_sweep_run() fills it in.
    $dead = array_values(array_map('strval', (array)($opts['dead'] ?? [])));

    // THE NARRATOR'S LEAN (write-ahead, docs/NARRATOR.md §3), handed in the way
    // the dead are. `$opts['intents']` is xeric_narrator_leans()'s hint list —
    // handles, maybe a kind key, maybe a place key, and by construction NEVER an
    // intent's text: every word this function touches ends up in a trail or a
    // prompt eventually, and a hint made of handles cannot spoil a beat however
    // it is echoed. The lean is bounded at ×2, beside the protagonist's ×3 —
    // enough to tilt a draw toward an hour that could realize an intended beat,
    // never enough to force one. It moves the CASTING and nothing after it: the
    // kind tried sooner, the room more likely, and the performance untouched,
    // which is the whole difference between a pull and a script. A world no
    // intent fits consumes no extra roll and draws exactly the draw it always
    // drew; the EVENT prompt never learns a lean happened at all.
    $intents = [];
    foreach ((array)($opts['intents'] ?? []) as $in) {
        if (!is_array($in)) continue;
        $intents[] = [
            'n'            => (int)($in['n'] ?? 0),
            'participants' => array_values(array_map('strval', (array)($in['participants'] ?? []))),
            'kind'         => ($in['kind'] ?? null) !== null && (string)$in['kind'] !== '' ? (string)$in['kind'] : null,
            'place'        => ($in['place'] ?? null) !== null && (string)$in['place'] !== '' ? (string)$in['place'] : null,
        ];
    }

    // A kind an intent asks for by name is tried sooner: ×2 once, however many
    // intents ask, and only a kind this world already produces — the lean casts
    // from what is armed, it arms nothing.
    $leanKindNs = [];
    foreach ($intents as $in) {
        if ($in['kind'] !== null && isset($kinds[$in['kind']])) $leanKindNs[$in['kind']][$in['n']] = true;
    }
    foreach (array_keys($leanKindNs) as $lk) {
        $kinds[$lk]['weight'] = (float)($kinds[$lk]['weight'] ?? 1.0) * 2.0;
    }

    $order = xeric_sweep_kind_order($kinds);

    // Where everybody actually was, in the words the chooser itself uses. Read
    // once: it is the same read xeric_sweep_groups() makes, and the trail must
    // agree with the decision rather than describe a second, later world.
    $presence = xeric_world_who_is_where($t, $now, $dead);
    $standing = [];
    foreach ($presence as $h => $row) {
        $standing[] = [
            'handle' => (string)$h,
            'name'   => xeric_world_name($t, (string)$h),
            'where'  => $row['where'] !== null ? xeric_world_place_name($t, (string)$row['where']) : '',
            'doing'  => (string)($row['doing'] ?? ''),
            'free'   => $row['where'] === null,
        ];
    }

    $tried = [];

    foreach ($order as $kind) {
        $canSpine = !empty($kind['spine']) && $protected !== [];
        $onSpine  = $canSpine && (array_key_exists('spine', $opts)
            ? (bool)$opts['spine']
            : xeric_sweep_roll(XERIC_SWEEP_SPINE));

        // THE WALL. An event about what they must not know happens somewhere
        // they are not. Everything else they may attend like anybody else.
        // The dead are excluded from every hour, spine or not. A wall keeps
        // somebody out of ONE kind of evening; being dead keeps them out of all
        // of them, which is why this is unioned in rather than branched on.
        $exclude = array_values(array_unique(array_merge($onSpine ? array_keys($protected) : [], $dead)));

        // The trail is KEPT — the demo writes it to world_state and the inspector
        // prints it — so the reason names the wall and never its payload. An
        // inspector that answers "why was she not there?" by printing the secret
        // she is being kept from is a wall with a window in it.
        $kept = [];
        foreach ($exclude as $h) {
            $kept[] = ['handle' => $h, 'name' => xeric_world_name($t, (string)$h),
                       // Two reasons land in one list and the inspector must not
                       // conflate them: "kept out because of what they know" and
                       // "not there because they are dead" are different answers
                       // to "why was she not there?", and only one of them is a wall.
                       'why' => in_array($h, $dead, true)
                           ? 'dead'
                           : 'this one touches what they must not know'];
        }

        $groups = xeric_sweep_groups($t, $now, $exclude, $maxPeople);
        if ($groups === []) {
            $tried[] = ['kind' => (string)$kind['key'], 'on_spine' => $onSpine,
                        'why_not' => $exclude === []
                            ? 'nobody was plausibly together, no two people in one room and no two both free'
                            : 'the only people who could have been there are kept out of this kind of hour'];
            continue;
        }

        // WHOSE STORY THIS IS. When the user stepped out of the centre the forge
        // named a protagonist, and the world's motion should be theirs — a
        // side-character world where the plot never visits the person carrying
        // it is just weather. Their groups get a heavier thumb on the scale;
        // they are never guaranteed, because a world that is ONLY about one
        // person stops being a world.
        $star = (string)($t['cast']['protagonist']['handle'] ?? '');
        if ($star !== '') {
            foreach ($groups as $i => $g2) {
                if (in_array($star, (array)($g2['handles'] ?? []), true)) {
                    $groups[$i]['weight'] = max(1, (int)($g2['weight'] ?? 1)) * 3;
                }
            }
        }

        // The lean's other half, beside the protagonist's thumb: a grouping
        // that holds everyone a live intent names — at its place and for its
        // kind of hour, when it names them — is twice as likely to be the
        // room. ×2 once, however many intents agree.
        $leanGroups = 0;
        $leanNs     = $leanKindNs[(string)$kind['key']] ?? [];
        foreach ($groups as $i => $g2) {
            foreach ($intents as $in) {
                if ($in['participants'] === []) continue;   // a kind-only intent leans the kind, not the room
                if ($in['kind'] !== null && $in['kind'] !== (string)$kind['key']) continue;
                if ($in['place'] !== null && ($g2['where'] ?? null) !== null
                    && (string)$g2['where'] !== $in['place']) continue;
                if (array_diff($in['participants'], array_map('strval', (array)($g2['handles'] ?? []))) !== []) continue;
                $groups[$i]['weight'] = max(1, (int)($g2['weight'] ?? 1)) * 2;
                $groups[$i]['lean']   = true;
                $leanNs[$in['n']]     = true;
                $leanGroups++;
                break;
            }
        }

        $g = xeric_sweep_weighted($groups);
        return [
            'kind'     => $kind,
            'handles'  => $g['handles'],
            'where'    => $g['where'],
            'on_spine' => $onSpine,
            'why'      => $g['why'],
            'trail'    => [
                'kinds_armed'  => array_keys($kinds),
                'kinds_tried'  => $tried,
                'kind'         => (string)$kind['key'],
                // 1.0 unless this world has learned that this kind of hour lands
                // (or does not). Part of the trail because "why does it keep doing
                // that?" now has a second possible answer.
                'kind_weight'  => round((float)($kind['weight'] ?? 1.0), 3),
                'shape'        => (string)($kind['shape'] ?? ''),
                'could_spine'  => $canSpine,
                'on_spine'     => $onSpine,
                'excluded'     => $kept,
                'standing'     => $standing,
                'groups'       => count($groups),
                'chose'        => (string)$g['why'],
                'weight'       => (int)($g['weight'] ?? 1),
                'protagonist'  => $star !== '' && in_array($star, (array)$g['handles'], true),
                // The lean, when one touched this hour — by NUMBER, never by
                // text. The trail is kept and printed, and an intent's words in
                // it would be the plan riding beside the record it is a plan
                // for; the owner holds the words already, at --intents.
                'lean'         => $leanNs === [] ? null : [
                    'why'     => 'leaning toward an intended beat',
                    'intents' => array_keys($leanNs),
                    'kind'    => isset($leanKindNs[(string)$kind['key']]),
                    'groups'  => $leanGroups,
                    'chose'   => !empty($g['lean']),
                ],
                'max_people'   => $maxPeople,
            ],
        ];
    }
    return null;
}

/**
 * Every set of people who could plausibly be at the same thing right now.
 *
 * Three shapes, and no others:
 *   together — two or three people whose shift has them in the same room
 *   visit    — somebody off-shift turning up where somebody else is working
 *   free     — two people with nothing on, somewhere that is open
 *
 * Somebody at work alone is not an event; two people at work in different
 * buildings are not an event. That is the whole rule, and it is why the world's
 * offscreen life reads as the world's rather than as a random pairing generator.
 *
 * @param array $exclude handles the caller has already ruled out (a wall)
 * @return array<int,array{handles:array,where:?string,why:string,weight:int}>
 */
function xeric_sweep_groups(array $t, array $now, array $exclude = [], int $maxPeople = XERIC_SWEEP_MAX_PARTICIPANTS): array
{
    // The dead arrive here the same way a wall does: in $exclude, put there by
    // xeric_sweep_choose(). This function has no database and should not grow one
    // — "who cannot be at this hour" is one question whatever the reason, and two
    // separate exclusion lists is how one of them eventually gets forgotten.
    $presence = xeric_world_who_is_where($t, $now, $exclude);

    $atWork = [];
    $free   = [];
    foreach ($presence as $handle => $row) {
        $handle = (string)$handle;
        if (in_array($handle, $exclude, true)) continue;
        $where = $row['where'] ?? null;
        if ($where !== null) $atWork[(string)$where][] = $handle;
        else                 $free[] = $handle;
    }

    $groups = [];

    // -- in the same room already ------------------------------------------
    foreach ($atWork as $place => $handles) {
        if (count($handles) < 2) continue;
        $groups[] = [
            'handles' => array_slice($handles, 0, $maxPeople),
            'where'   => (string)$place,
            'why'     => 'both on shift at ' . xeric_world_place_name($t, (string)$place),
            'weight'  => 4,
        ];
    }

    // -- somebody dropping in on somebody who is working -------------------
    foreach ($atWork as $place => $handles) {
        foreach ($free as $visitor) {
            $set = array_slice($handles, 0, $maxPeople - 1);
            $set[] = $visitor;
            $groups[] = [
                'handles' => $set,
                'where'   => (string)$place,
                'why'     => xeric_world_name($t, $visitor) . ' had nothing on and '
                             . xeric_world_place_name($t, (string)$place) . ' was open',
                'weight'  => 3,
            ];
        }
    }

    // -- both off shift ----------------------------------------------------
    $open = xeric_sweep_open_places($t, $now);
    $n    = count($free);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $where = $open !== [] ? $open[array_rand($open)] : null;
            $groups[] = [
                'handles' => [$free[$i], $free[$j]],
                'where'   => $where,
                'why'     => 'neither of them was working',
                'weight'  => 2,
            ];
        }
    }

    return $groups;
}

/**
 * Places whose hours cover this moment. A place with no hours is always open.
 *
 * `hours.open` + `hours.close` is what the forge writes and what the schema doc
 * calls normative. Everything else in here is tolerance, because `hours` is a
 * free-form bag the bible prints mechanically and a hand-written world uses the
 * words it likes: `close_weekday`, `close_weeknight`, `open_weekday`,
 * `open_saturday`, and a whole day in one string ("07:00-17:00"). Reading only
 * the two normative keys meant every place that spoke any of the others was
 * open at every hour — including a mill that has been chained since 1998, which
 * then hosted evenings.
 */
function xeric_sweep_open_places(array $t, array $now): array
{
    $mins = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    $dow  = (int)($now['dow'] ?? 0);

    $out = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $key = (string)($p['key'] ?? '');
        if ($key === '') continue;
        if (xeric_sweep_place_open((array)($p['hours'] ?? []), $mins, $dow)) $out[] = $key;
    }
    return $out;
}

/**
 * One place's hours bag, read against one moment.
 *
 * Unreadable hours are open, as they always were — the bag is free-form and a
 * reader that shut everything it did not understand would empty a world. A
 * `closed` note is the exception, and the only key here that can shut a place
 * on its own: it names the days it applies to ("Sundays"), or it names none at
 * all ("since 1998"), which is how a place says it is not coming back.
 */
function xeric_sweep_place_open(array $hours, int $mins, int $dow): bool
{
    if (isset($hours['closed']) && xeric_sweep_closed_today($hours['closed'], $dow)) return false;

    // Most specific first: this day by name, then the band it falls in.
    $day   = strtolower(xeric_world_day_name($dow));
    $bands = $day !== '' ? [$day] : [];
    if ($dow === 0 || $dow === 6) { $bands[] = $dow === 6 ? 'saturday' : 'sunday'; $bands[] = 'weekend'; }
    else                          { $bands[] = 'weekday'; }
    if ($dow <= 4) $bands[] = 'weeknight';        // Sunday through Thursday: a night before work
    $bands = array_values(array_unique(array_filter($bands)));

    $open = $close = null;
    foreach ($bands as $b) {
        // A single key can carry the whole day: "open_weekday": "07:00-17:00".
        $span = xeric_sweep_hhmm_span((string)($hours['open_' . $b] ?? ''));
        if ($span !== null) { [$open, $close] = $span; break; }
        if ($open === null)  $open  = xeric_world_minutes((string)($hours['open_' . $b] ?? ''));
        if ($close === null) $close = xeric_world_minutes((string)($hours['close_' . $b] ?? ''));
    }
    if ($open === null) {
        $span = xeric_sweep_hhmm_span((string)($hours['open'] ?? ''));
        if ($span !== null) [$open, $close] = $span;
        else                $open = xeric_world_minutes((string)($hours['open'] ?? ''));
    }
    if ($close === null) $close = xeric_world_minutes((string)($hours['close'] ?? ''));

    if ($open === null || $close === null) return true;

    // A bar that closes at 03:00 is open at 01:00 and shut at 16:00.
    return $close > $open ? ($mins >= $open && $mins < $close) : ($mins >= $open || $mins < $close);
}

/** "07:00-17:00" → both ends in minutes. null when it is not a span. */
function xeric_sweep_hhmm_span(string $s): ?array
{
    $s = trim(preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $s) ?? $s);
    if (!str_contains($s, '-')) return null;

    [$a, $b] = array_map('trim', explode('-', $s, 2));
    $from = xeric_world_minutes($a);
    $to   = xeric_world_minutes($b);
    return ($from === null || $to === null) ? null : [$from, $to];
}

/** Is a `closed` note about today? A note that names no day at all is about every day. */
function xeric_sweep_closed_today($note, int $dow): bool
{
    if (is_bool($note)) return $note;
    $s = mb_strtolower(trim((string)$note));
    if ($s === '' || $s === '0' || $s === 'no' || $s === 'never') return false;

    $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    $named = false;
    foreach ($days as $i => $name) {
        $short = mb_substr($name, 0, 3);
        if (!preg_match('/\b(' . $name . 's?|' . $short . 's?)\b/u', $s)) continue;
        $named = true;
        if ($i === $dow) return true;
    }
    foreach (['weekend' => [0, 6], 'weekday' => [1, 2, 3, 4, 5], 'weeknight' => [0, 1, 2, 3, 4]] as $band => $dows) {
        if (!preg_match('/\b' . $band . 's?\b/u', $s)) continue;
        $named = true;
        if (in_array($dow, $dows, true)) return true;
    }

    // "since 1998", "for good", "permanently" — nothing to come back for.
    return !$named;
}

/** Pick one group, favouring the more plausible shapes. */
function xeric_sweep_weighted(array $groups): array
{
    $total = 0;
    foreach ($groups as $g) $total += max(1, (int)($g['weight'] ?? 1));

    $roll = mt_rand(1, max(1, $total));
    foreach ($groups as $g) {
        $roll -= max(1, (int)($g['weight'] ?? 1));
        if ($roll <= 0) return $g;
    }
    return $groups[count($groups) - 1];
}

/** A probability, honestly. 0 never fires, 1 always does. */
function xeric_sweep_roll(float $chance): bool
{
    if ($chance >= 1.0) return true;
    if ($chance <= 0.0) return false;
    return (mt_rand() / mt_getrandmax()) < $chance;
}

/**
 * The user's quiet hours as two minute-marks. null when they have none.
 *
 * `HH:MM-HH:MM` is the written form and the one the forge produces; this reader
 * is deliberately more forgiving than that, because the field is hand-editable
 * and the hand that edits it is on a phone. An en dash, an em dash or the word
 * "to" all mean a hyphen, and the old reader — which asked only whether the
 * string contained a '-' — read `21:30–06:00` as a world with NO quiet hours at
 * all. That is the one failure of this field nobody notices until 4am.
 *
 * A value that still cannot be read is not shrugged off: $why comes back with
 * the complaint, and the caller treats the hour as quiet. A wall nobody can read
 * protects more, not less.
 *
 * @param string|null $why OUT: what is wrong with the value, when something is
 * @return array{0:int,1:int}|null
 */
function xeric_sweep_quiet_window(string $spec, ?string &$why = null): ?array
{
    $why = null;
    $raw = trim($spec);
    if ($raw === '') return null;

    $s = preg_replace('/[\x{2010}-\x{2015}\x{2212}\x{FE58}\x{FF0D}]/u', '-', $raw) ?? $raw;
    $s = preg_replace('/\s+(?:to|until|till)\s+/iu', '-', $s) ?? $s;
    $s = preg_replace('/\s*-+\s*/u', '-', trim($s)) ?? $s;

    $parts = explode('-', $s, 2);
    if (count($parts) === 2) {
        $from = xeric_world_minutes(trim($parts[0]));
        $to   = xeric_world_minutes(trim($parts[1]));
        if ($from !== null && $to !== null) return [$from, $to];
    }

    $why = 'quiet hours read "' . $raw . '", which is not two times of day (21:30-06:00), '
         . 'until that is fixed the world is treating every hour as quiet';
    return null;
}

/**
 * Is this moment inside the user's quiet hours?
 *
 * The user's, not the cast's: a night-shift world still has people awake at
 * 3am, and this gate is about what may reach the person holding the phone.
 *
 * @param string|null $why OUT: set only when the field is there and unreadable,
 *        in which case this returns true — the caller says so in its notes
 *        rather than quietly deciding this world sleeps at no hour at all.
 */
function xeric_sweep_quiet(array $t, array $now, ?string &$why = null): bool
{
    $why = null;
    if (array_key_exists('quiet_hours_respected', (array)($t['events'] ?? []))
        && !$t['events']['quiet_hours_respected']) return false;

    $window = xeric_sweep_quiet_window((string)($t['user']['quiet_hours'] ?? ''), $why);
    if ($window === null) return $why !== null;

    [$f, $to] = $window;
    $mins = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    return $to > $f ? ($mins >= $f && $mins < $to) : ($mins >= $f || $mins < $to);
}

// ---------------------------------------------------------------------------
// The model call
// ---------------------------------------------------------------------------

/**
 * One call: the event, its prose, and one memory per person from where they stood.
 *
 * Returns what SHOULD be written; writes nothing itself. Everything that can be
 * refused is refused here, before the transaction opens, so "the model was wrong"
 * and "the world changed" never overlap.
 *
 * @return array{title:string,prose:string,memories:array<string,string>,usage:array,notes:array}
 * @throws RuntimeException on a dead model, an unusable answer, or memories that
 *         are the same memory twice.
 */
function xeric_sweep_compose(array $t, PDO $db, array $endpoint, array $now, array $chosen, array $opts = []): array
{
    $notes    = [];
    $retries  = max(0, (int)($opts['diverge_retries'] ?? 1));
    $messages = xeric_sweep_prompt($t, $db, $now, $chosen, $opts);

    $t0   = microtime(true);
    $best = null;

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        try {
            $raw = xeric_chat_json($endpoint, 'sweep', $messages, [
                'temperature' => (float)($opts['temperature'] ?? 0.9),
                'max_tokens'  => (int)($opts['max_tokens'] ?? 700),
            ] + array_intersect_key($opts, ['timeout' => 1]));
        } catch (Throwable $e) {
            throw new RuntimeException('sweep: the xeric did not answer, ' . $e->getMessage(), 0, $e);
        }

        $parsed = xeric_sweep_parse($t, $db, $raw, $chosen);
        if ($parsed['memories'] === [] || count($parsed['memories']) < 2) {
            throw new RuntimeException('sweep: the model wrote an event nobody remembers ('
                . implode('; ', $parsed['notes']) . ')');
        }

        $collision = xeric_sweep_collision($parsed['memories']);
        if ($collision === null) { $best = $parsed; break; }

        $best   = $parsed;
        $notes[] = 'attempt ' . ($attempt + 1) . ': ' . $collision['why'];
        if ($attempt === $retries) break;

        // Hand it back its own two sentences and say what is wrong with them.
        // Cheaper and far more effective than asking harder up front.
        $messages[] = ['role' => 'assistant', 'content' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        $messages[] = ['role' => 'user', 'content' =>
            $collision['names'] . ' remembered the same thing in the same words. Two people at the same '
            . "hour do not do that.\nWrite the whole object again. Keep the event. Give each of them a "
            . "DIFFERENT PART of it, different detail, different thing noticed, different thing they were "
            . 'left holding. Neither memory may contain the other one.'];
    }

    $collision = xeric_sweep_collision($best['memories']);
    if ($collision !== null) {
        // A flat world is worse than a quiet one. Refusing costs an hour; two
        // people with one shared memory costs the illusion, permanently.
        throw new RuntimeException('sweep: refused, ' . $collision['why']
            . ' (divergent memories are the point of a sweep)');
    }

    $ms = (int)round((microtime(true) - $t0) * 1000);
    return [
        'title'    => $best['title'],
        'prose'    => $best['prose'],
        'memories' => $best['memories'],
        'usage'    => ['ms' => $ms, 'attempts' => $attempt + 1],
        'notes'    => array_merge($notes, $best['notes']),
    ];
}

/**
 * Who dies in a lethal hour. The ENGINE decides, before the model is asked.
 *
 * From the living cast MINUS the people standing in the hour, because the shape
 * of a `loss` hour is the others finding out — somebody cannot be at the scene
 * of learning they are dead. Null when there is nobody left outside the room,
 * and the caller drops the kind rather than reaching into it: a two-person world
 * that armed mortality gets ordinary evenings, not a bloodbath.
 *
 * Never the protagonist by accident. They can die, but not as the incidental
 * subject of a background hour the player did not ask for; a world ends its own
 * protagonist through the button or through a story, deliberately, or not at all.
 *
 * The pick is a plain shuffle with no thumb on it. Every weighting the engine
 * has — reach, story carry, learned kinds — pushes toward the people the player
 * engages with most, and pointing any of those at a corpse would quietly make
 * this the generator that kills your favourite character first.
 */
function xeric_sweep_lethal(array $t, array $chosen, array $dead, array $opts = []): ?string
{
    $inRoom = array_map('strval', (array)($chosen['handles'] ?? []));
    $star   = (string)($t['cast']['protagonist']['handle'] ?? '');

    $pool = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || $h === $star) continue;
        if (in_array($h, $inRoom, true) || in_array($h, $dead, true)) continue;
        // Never somebody who has not entered the story: dying offstage before
        // your first scene is not a death, it is a casting error.
        if (!empty($c['out'])) continue;
        $pool[] = $h;
    }
    if ($pool === []) return null;

    // Named outright when the caller already knows — a test, or a story beat
    // that decided this long before the hour came round.
    if (isset($opts['dying']) && in_array((string)$opts['dying'], $pool, true)) return (string)$opts['dying'];

    return $pool[array_rand($pool)];
}

/** The prompt. World, moment, room, people, shape, and the walls around it. */
function xeric_sweep_prompt(array $t, PDO $db, array $now, array $chosen, array $opts = []): array
{
    $eff        = (string)($opts['effective_rating'] ?? xeric_world_rating($t, $opts['model_rating'] ?? null));
    $worldName  = (string)($t['meta']['name'] ?? 'this world');
    $protected  = xeric_sweep_protected($t);

    // THE AGE FLOOR. One call writes the hour and everybody's memory of it, so
    // the room's ceiling is the lowest ceiling standing in it: one child present
    // and the hour is written to the weakest rating, whatever the world is
    // rated. It keeps nobody out — he is at the thing, he remembers his half of
    // it, and the shape of the hour is unchanged. It is the ONE thing in it that
    // is closed.
    $children = [];
    foreach ($chosen['handles'] as $h) {
        $who = xeric_viewer($t, ['handle' => (string)$h]);
        if (!$who['is_minor']) continue;
        $children[] = xeric_world_name($t, (string)$h);
        $eff = xeric_viewer_rating($eff, $who);
    }

    // WHAT A STORY HAS THEM CARRYING, in the words it was already composed in.
    // `$t['story']['lines']` is template data by the time this file sees it —
    // xeric_story_compose() wrote it, gated against each line's own subject, and
    // this reads the same sentences the speaker's own block reads. One source of
    // truth on purpose: a conviction that reads one way in a conversation and
    // another way in an offscreen hour is two characters.
    $carrying = (array)($t['story']['lines'] ?? []);

    $lines = ['THE WORLD'];
    $lines[] = $worldName . ($t['meta']['description'] ?? '' ? ', ' . (string)$t['meta']['description'] : '');
    // The hour is WRITTEN at the room's ceiling, not just gated by it — $eff is
    // already the lowest ceiling standing in the room (one child clamps it), and
    // the style sentence is the same one every chat turn carries.
    $lines[] = xeric_rating_style($eff);
    $loc = trim((string)($t['user']['location'] ?? ''));
    if ($loc !== '') $lines[] = 'It is ' . $loc . '.';

    $when = xeric_world_day_name((int)($now['dow'] ?? 0)) . ' ' . (string)($now['phase'] ?? '')
          . ', ' . (string)($now['hhmm'] ?? '');
    $place = $chosen['where'] !== null ? xeric_world_place($t, (string)$chosen['where']) : null;
    $whereLine = $place !== null
        ? 'At ' . (string)($place['name'] ?? $chosen['where'])
          . (trim((string)($place['description'] ?? '')) !== '' ? ', ' . (string)$place['description'] : '')
        : 'Somewhere neither of them had to be';
    $lines[] = '';
    $lines[] = 'WHEN AND WHERE';
    $lines[] = $when . '. ' . rtrim($whereLine, '.') . '.';
    // The day's sky, the same byte string every prompt derives for this date
    // (engine/weather.php). Scene-setting only — the still-life rule four
    // lines down still says weather may dress the hour and never carry it.
    $wx = xeric_weather_line($t, $now);
    if ($wx !== '') $lines[] = 'The weather, which is scenery and not the subject: ' . $wx;
    // And the room's own furniture, when the forge furnished it: the same list
    // the arrival beat reads, so an hour at the diner and a walk into the
    // diner touch the SAME chairs instead of each inventing their own. Props,
    // not subjects — the still-life rule covers these too.
    $furn = $place === null ? [] : array_values(array_filter(array_map(
        fn($i) => trim(xeric_text($i)), (array)($place['interior'] ?? [])), fn($s) => $s !== ''));
    if ($furn !== []) {
        $lines[] = 'In the room, if hands need something to hold: ' . implode('; ', $furn) . '.';
    }

    // WHOSE PROMPT THIS ALSO IS. One call writes a memory for everybody in the
    // room, so when one of them is protected this prompt is the thing that puts
    // words in THEIR head — and everything in it is a candidate for ending up
    // there. Somebody else's private memories, and the titles of hours they were
    // not at, are exactly what the wall exists to keep out of that head, and a
    // sentence asking the model not to look at them is not a wall. So they are
    // not printed at all. The protected character's own memories stay: they are
    // already hers.
    $walled = array_values(array_intersect(array_keys($protected), (array)$chosen['handles']));

    $lines[] = '';
    $lines[] = 'WHO WAS THERE';
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));
    foreach ($chosen['handles'] as $h) {
        $c    = xeric_world_character($t, $h);
        $name = xeric_world_name($t, $h);
        $bits = [$name];
        if ($c !== null && !empty($c['age']))      $bits[] = (string)$c['age'];
        $head = implode(', ', $bits);
        $one  = $c !== null ? xeric_text($c['one_line'] ?? '') : '';
        $lines[] = '- [' . $h . '] ' . $head . ($one !== '' ? ', ' . $one : '');

        $doing = (string)($presence[$h]['doing'] ?? '');
        if ($doing !== '') $lines[] = '    right then: ' . $doing;

        if ($walled !== [] && !in_array($h, $walled, true)) continue;

        // Said flat, with no hedge and no hint. A red herring reaches this prompt
        // as a conviction and nothing else — `is_false` and `actually` are not
        // composed anywhere and are not readable from here — because a model told
        // its character is mistaken hedges, and a hedged wrong lead is not a lead.
        //
        // Under the same guard as the memories below, and for the same reason: a
        // holder's line about a piece is a line about whether they bring it up,
        // and printing "you tell him, all of it" in a room with the person who
        // must not hear it is handing the model the one hour it may not write.
        foreach ((array)($carrying[$h] ?? []) as $line) {
            $line = trim((string)$line);
            if ($line !== '') $lines[] = '    in their own words: ' . $line;
        }

        foreach (array_slice(xeric_memories_for($db, $h, 3), -2) as $m) {
            $lines[] = '    still carries: ' . trim((string)$m['text']);
        }
    }

    $lines[] = '';
    $lines[] = 'WHAT KIND OF THING HAPPENED';
    $lines[] = (string)$chosen['kind']['shape'] . '.';
    $lines[] = 'They were together because ' . (string)$chosen['why'] . '.';

    // WHO DIED, named, as something already true. Not a question and not a
    // choice: the engine picked (xeric_sweep_lethal) and the hour is about the
    // people in the room hearing it. A model left to choose picks whoever is most
    // narratively convenient, which over a month is whoever you talk to most.
    $dying = (string)($chosen['dying'] ?? '');
    if ($dying !== '') {
        $lines[] = 'This is not in doubt and it is not the question: ' . xeric_world_name($t, $dying)
                 . ' is dead. Nobody in this room dies in it, this is the hour they hear about it. '
                 . 'Do not write the death, write the room.';
    }

    // A BEAT IS NOT A SUGGESTION. The overlay says what happened; the model's
    // job in this hour is the only part of it the overlay left open, which is
    // what each of them carried out of it. Written as the thing that is already
    // true rather than as a prompt, for the same reason the wall is.
    $beat = (array)($chosen['story_beat']['beat'] ?? []);
    $told = xeric_text($beat['as_event']['prose'] ?? '');
    if ($told !== '') $lines[] = 'This is the hour, and it is not in doubt: ' . $told;

    // Said once, in the room it applies to. The first half is not a courtesy: a
    // model told only what a child may not do writes him as a prop, and the
    // whole reason he is in the cast is that he does things nobody else does.
    if ($children !== []) {
        $lines[] = xeric_join_list($children) . (count($children) === 1 ? ' is a child' : ' are children')
            . ', in this hour like anybody else, with their own half of it to remember. '
            . 'Nothing in this hour is sexual.';
    }

    // The wall, stated as a constraint on the event rather than on the prose,
    // because the model is writing something that BECAME TRUE when it wrote it.
    if ($protected !== []) {
        if ($chosen['on_spine']) {
            $lines[] = 'This one touches what this world is keeping quiet: ' . implode('; ', array_values($protected)) . '.';
            $lines[] = 'Nobody named above finds out the whole of it. It stays a detail, not an explanation.';
        } elseif ($walled !== []) {
            foreach ($walled as $h) {
                $lines[] = 'NOTHING here may touch ' . xeric_world_name($t, $h) . '\'s blind spot: '
                    . $protected[$h] . '. They do not learn it, hear it, or half-hear it.';
            }
        }
    }

    // A spine title is the secret with the words "name the thing" applied to it,
    // which is what this prompt asks for. It may be handed back to the hours it
    // belongs to and to nobody who is walled off from them.
    $recent = xeric_events_recent($db, 3);
    if ($walled !== []) {
        $recent = array_values(array_filter($recent, fn($e) => empty($e['on_spine'])));
    }
    if ($recent !== []) {
        $lines[] = '';
        $lines[] = 'ALREADY HAPPENED (do not repeat, do not resolve)';
        foreach ($recent as $e) $lines[] = '- ' . trim((string)$e['title']);
    }

    $keys = [];
    foreach ($chosen['handles'] as $h) $keys[] = '"' . $h . '": "…"';

    $lines[] = '';
    $lines[] = 'WRITE ONE JSON OBJECT';
    $lines[] = '{ "title": "…", "prose": "…", "overheard": "…", "memories": { ' . implode(', ', $keys) . ' } }';
    $lines[] = '';
    $lines[] = '- title: six words or fewer, lower case, no full stop. Name the thing, do not summarise it.';
    // THE STILL-LIFE FAILURE. This list used to end with "Hands, objects,
    // weather, money, doors" — written to keep interiority out, and a small
    // model read it as a subject list: it evicted the cast and wrote the room.
    // Dust motes, condensation, a wobbling ceiling fan, and not one person in
    // the hour that two people are about to carry memories out of. Next to a
    // seeded past full of people knocking things over, those hours read as a
    // different author — the seed pass hands its model a cast and asks what
    // HAPPENED, so people arrive as the subjects for free. Here they have to be
    // asked for outright, and the scenery told its place in the same breath.
    $lines[] = '- prose: 2-4 sentences, past tense, third person, concrete.';
    $lines[] = '  The people named above are IN it, by name, doing things. This is their hour, not the room\'s.';
    $lines[] = '  No dialogue and no quotation marks, say what was DONE, not what was said.';
    $lines[] = '  No "she felt", no "he realised". Their hands on objects, weather, money, doors.';
    $lines[] = '  Weather and furniture may set the scene, never carry it: an hour with nobody in it is wrong.';
    // THE AUDIBLE SURFACE — the one exception to "no dialogue" above, fenced
    // into its own field so the prose stays observables. This is what an
    // arrival quotes when somebody walks in mid-hour: real talk, tied to the
    // heartbeat, one exchange, cheap because it rides this same call.
    $lines[] = '- overheard: ONE audible exchange from the hour, as it would reach a doorway —';
    $lines[] = '  \'Name: "…" / Name: "…"\', two short spoken lines at most, or "" for an hour';
    $lines[] = '  with no talk worth catching. Speech only, things a stranger could HEAR.';
    $lines[] = '- memories: one line for EACH handle above, keyed exactly as written in the brackets.';
    $lines[] = '  Third person, past tense, naming them. One sentence, under 25 words.';
    $lines[] = '  THE IMPORTANT PART: they do not remember the same thing. Give each of them a different';
    $lines[] = '  half, a different detail, a different thing noticed, a different thing they were left';
    $lines[] = '  holding. If one memory could be swapped for the other, both are wrong.';
    $lines[] = '- Invent no new people and no new places. Nothing is explained, resolved or concluded.';
    // Against a NAMED tier, never a bare integer. `>= 1` was written when rank
    // 1 meant `mature`; the broadcast ladder made rank 1 `pg`, and for one
    // evening every TV-PG and TV-14 world was told adult content is permitted
    // four lines under the style sentence saying the camera cuts away. A named
    // tier survives the next ladder change too.
    $lines[] = xeric_rating_rank($eff) >= xeric_rating_rank('mature')
        ? '- Adult content is allowed where the hour honestly calls for it; it is never the point.'
        : '- Keep it clean: nothing sexual, nothing graphic.';
    $lines[] = 'No prose outside the JSON.';

    return [
        ['role' => 'system', 'content' =>
            'You write down what happened in one small corner of a lived-in world while nobody was watching. '
            . 'You are not telling a story: you are recording an hour. Small, physical, unresolved. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' => implode("\n", $lines)],
    ];
}

/**
 * The model's object → what may be written.
 *
 * Everything a small model does wrong here is a dropped field, never a stored
 * one: a memory keyed to somebody who was not in the room, a display name where
 * a handle was asked for, an empty string, a paragraph where a sentence was
 * asked for, or a memory that is a rewording of something that person already
 * remembers (which is the model summarising its own prompt back at us).
 *
 * With one exception, and it is the reason this reads the template at all: an
 * hour that hands a protected character the thing they must not know is not a
 * field to drop, because the prose and the memories are two halves of the same
 * hour. That one is thrown, the hour is refused, and nothing is written.
 */
/**
 * Interiority in a public record. '' when clean, else the word that broke it.
 *
 * OBSERVABLES-ONLY IS THE ENGINE'S LAW, NOT THE PROMPT'S REQUEST. An event is
 * the town's shared record: the gossip ripple derives lines from it, the room
 * block reads its title back to whoever was near it, and the whole walls-
 * safety argument for spreading it is that it contains only what a bystander
 * could see. The prompt has always said so ("No she felt, no he realised") —
 * and the prompt is a request. A small model that writes "she felt the evening
 * turn on her" into prose makes an interior state a public fact the moment the
 * row lands, and nothing downstream can un-know it. So the rule gets the same
 * treatment the protected-secret rule got: said in the prompt, ENFORCED here.
 *
 * The verb list is deliberately unambiguous — verbs that can only report the
 * inside of a head. The one double-agent is "felt": felt ALONG the shelf is
 * hands (kept), felt ashamed is a heart (refused), so the physical senses are
 * carved out by their prepositions. Memories are exempt by design: a memory IS
 * an interior, that is the entire point of divergent per-witness memory.
 */
function xeric_sweep_interior(string $text): string
{
    static $verbs = 'realised|realized|wondered|hoped|feared|wished|believed|regretted|worried'
        . '|resented|dreaded|understood|suspected|doubted|envied|longed|yearned'
        . '|knew|thought|decided|remembered|considered|imagined|daydreamed|felt';
    if (!preg_match('/\b(' . $verbs . ')\b/iu', $text, $m)) return '';
    if (strcasecmp($m[1], 'felt') === 0
        && preg_match('/\bfelt\s+(along|for|under|around|through|across|beneath|behind|inside)\b/iu', $text)) {
        return '';
    }
    return $m[1];
}

function xeric_sweep_parse(array $t, PDO $db, array $raw, array $chosen): array
{
    $notes = [];

    $title = trim(preg_replace('/\s+/u', ' ', (string)($raw['title'] ?? '')) ?? '');
    $title = trim($title, " \t\n\"'“”.");
    $prose = trim((string)($raw['prose'] ?? $raw['what_happened'] ?? ''));
    $prose = trim(preg_replace('/\s*\R\s*/u', ' ', $prose) ?? $prose);

    if ($prose === '') throw new RuntimeException('sweep: the model wrote no prose');
    if ($title === '') $title = xeric_seed_headline($prose);
    if (mb_strlen($title) > 90) $title = rtrim(mb_substr($title, 0, 90)) . '…';
    if (mb_strlen($prose) > 900) $prose = xeric_chat_trim_length($prose, 900);

    // THE OVERHEARD LINE: the hour's audible surface, normalized to one short
    // exchange. Quoted SPEECH, so the interiority gate below deliberately does
    // not read it — "I felt awful about it" is a legal thing to say out loud —
    // but the wall and the floor read it like everything else, because a
    // doorway is the least private place in the world.
    $overheard = trim(preg_replace('/\s+/u', ' ', xeric_text($raw['overheard'] ?? '')) ?? '');
    if (mb_strlen($overheard) > 220) $overheard = rtrim(mb_substr($overheard, 0, 220)) . '…';
    if ($overheard !== '' && mb_strlen($overheard) < 8) $overheard = '';

    // The public record is observables only — refused whole, like the wall
    // and the floor below, because half an hour is not a smaller hour. The
    // memories are deliberately NOT screened here: they are interiors, and
    // divergent interiors are what a sweep exists to produce.
    foreach (['title' => $title, 'prose' => $prose] as $field => $text) {
        $verb = xeric_sweep_interior($text);
        if ($verb !== '') {
            throw new RuntimeException("sweep: refused, the $field wrote the inside of somebody's head "
                . "(\"$verb\") — the record states what a bystander could see, the feeling rides "
                . 'the feeler\'s own prompt');
        }
    }

    // Handles first; a display name is resolved through the same index the seeder
    // uses, so "Maren Voss" and "maren_voss" mean the same person in both files.
    $index = xeric_seed_index($t, true);
    $want  = array_flip($chosen['handles']);

    $memories = [];
    foreach ((array)($raw['memories'] ?? $raw['took_away'] ?? []) as $key => $value) {
        if (is_array($value)) $value = $value['text'] ?? $value['memory'] ?? '';
        $handle = isset($want[(string)$key]) ? (string)$key : xeric_seed_resolve((string)$key, $index);
        if ($handle === null || !isset($want[$handle])) { $notes[] = "memory for '$key' is nobody who was there"; continue; }
        if (isset($memories[$handle])) { $notes[] = "two memories for $handle"; continue; }

        $text = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
        if (mb_strlen($text) < 12) { $notes[] = "$handle remembered nothing usable"; continue; }
        if (mb_strlen($text) > 240) $text = rtrim(mb_substr($text, 0, 240)) . '…';

        $known = array_map(fn($m) => (string)$m['text'], xeric_memories_for($db, $handle, 12));
        if (xeric_chat_is_dupe($text, $known, XERIC_SWEEP_ECHO)) {
            $notes[] = "$handle's memory only restated something they already knew";
            continue;
        }
        // Cross-witness divergence is DELIBERATELY not checked here. It lives
        // one layer up, in xeric_sweep_collision() — pairwise, with a retry
        // that hands the model back its own two sentences and a refusal when
        // it cannot do better — and a parse-level drop would swallow the
        // collision before that loop could see it, turning refuse-and-retry
        // into silently-accept-a-thinner-hour. One law, one place.
        $memories[$handle] = $text;
    }

    // THE WALL, CHECKED AFTER THE FACT. The prompt says it twice and withholds
    // everything that could feed it, and a small model still occasionally writes
    // the secret into the room it was told to keep it out of. By the time it is
    // stored it is not prose, it is what happened, and nothing downstream can
    // un-happen it. Refusing costs an hour.
    // THE TITLE IS SCANNED TOO — it is the one field the prompt tells the model
    // to "name the thing" in, and it travels furthest: the room block reads a
    // non-spine title back into every recent participant's own prompt, and the
    // why-trail prints it in the inspector. The age floor eleven lines down
    // always measured title+prose together; this check now does the same.
    foreach (xeric_sweep_protected($t) as $h => $secret) {
        if (!in_array($h, $chosen['handles'], true)) continue;
        if (xeric_sweep_touches($title, $secret)
            || xeric_sweep_touches($prose, $secret)
            || xeric_sweep_touches($overheard, $secret)
            || (isset($memories[$h]) && xeric_sweep_touches($memories[$h], $secret))) {
            throw new RuntimeException('sweep: refused, the hour put ' . $h
                . ' next to the thing they must not know');
        }
    }

    // THE AGE FLOOR, checked the same way and for the same reason: an hour is
    // not prose by the time it is stored, it is what happened, and the memories
    // are what people carry out of it. Refused whole — the event and every
    // memory in it — because they are one hour and half of one is not a
    // smaller version of it. The window is not consumed, so the world simply
    // has a quiet hour, which is a great deal better than a wrong one.
    //
    // It reads for sex and only for sex. An hour where a child witnessed
    // something, kept something back, was frightened, or found the body is an
    // ordinary hour and lands like any other.
    $floor = xeric_age_floor($t, $chosen['handles'],
        array_merge([$title, $prose, $overheard], array_values($memories)));
    if ($floor !== null) throw new RuntimeException(xeric_age_refusal('sweep', $floor));

    // Cast order, not model order: a stable participants list makes two runs of
    // the same window comparable and keeps the UI from reshuffling names.
    $ordered = [];
    foreach ($chosen['handles'] as $h) {
        if (isset($memories[$h])) $ordered[$h] = $memories[$h];
    }

    return ['title' => $title, 'prose' => $prose, 'overheard' => $overheard,
            'memories' => $ordered, 'notes' => $notes];
}

/**
 * Are any two of these the same memory? null when they are genuinely different.
 *
 * @return array{why:string,names:string}|null
 */
function xeric_sweep_collision(array $memories): ?array
{
    $handles = array_keys($memories);
    for ($i = 0; $i < count($handles); $i++) {
        for ($j = $i + 1; $j < count($handles); $j++) {
            $a = $memories[$handles[$i]];
            $b = $memories[$handles[$j]];
            $score = xeric_chat_similar($a, $b);
            if ($score < XERIC_SWEEP_DIVERGE) continue;
            return [
                'names' => $handles[$i] . ' and ' . $handles[$j],
                'why'   => $handles[$i] . ' and ' . $handles[$j] . ' remembered the same thing ('
                           . number_format($score, 2) . ' alike)',
            ];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Small shared shapes
// ---------------------------------------------------------------------------

/** Nothing happened, and the window was not consumed. */
function xeric_sweep_nothing(string $note): array
{
    return ['events' => [], 'notes' => [$note]];
}

/** Nothing happened, and this window has now had its turn. */
function xeric_sweep_skip(PDO $db, string $guard, string $note): array
{
    xeric_world_state_set($db, $guard, 'nothing');
    return ['events' => [], 'notes' => [$note]];
}
