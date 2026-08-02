<?php
/**
 * travel.php — the player's body, and what it costs to move it.
 *
 * Everything spatial in Xeric already existed before this file. `places[]` has
 * carried keys, kinds, hours and residents since the first template;
 * `xeric_world_who_is_where()` has resolved every character to a room at any
 * minute of the world clock; prompt.php has been telling people "You are at the
 * Bluebird Diner. Also there: Ruth, Dot." for as long as there have been
 * prompts. The engine has always known Jim is in the bar.
 *
 * What it did not know is where YOU are. `user.location` is a town — "Milldale,
 * Ohio" — and a town is not a room. The player was a phone hovering over the
 * county, able to text anybody from nowhere at all. This file is the missing
 * field and the three things that field makes possible.
 *
 * THE POINT IS THE COST, NOT THE MAP. Carmen Sandiego and Drug Wars did not have
 * geography because they had pictures of it; they had it because moving burned a
 * turn. A place you click and arrive at instantly is a menu with a nicer
 * background — worse than nothing, because it teaches a player that distance is
 * decoration. Here a trip advances the world clock by the minutes it takes, on
 * the same offset the time control writes, which is what turns a schedule the
 * engine has always computed into something you can miss: she is off at two, and
 * you cannot get there by two.
 *
 * FOUR DECISIONS WORTH KNOWING BEFORE EDITING THIS:
 *
 *  1. NULL IS A REAL POSITION. The player being nowhere on the map is the same
 *     state as a character being off shift, and it renders with the same
 *     sentence — "wherever you are, it is your own time." No synthetic `home`
 *     place, no reserved key, no special case anywhere downstream. The engine's
 *     vocabulary already had a word for not being anywhere and this reuses it.
 *
 *  2. YOU MAY WALK TO A CLOSED PLACE. Travel is never refused on hours. You go
 *     over and it is shut, and the arrival says so. Refusing the trip is a menu
 *     telling you what the world would have contained; making the trip and
 *     finding the chain still on the gate is the world. It is also the only way
 *     anyone ever stands at the mill.
 *
 *  3. UNKNOWN GEOMETRY COSTS THE DEFAULT, NEVER ZERO. A template whose places
 *     have no coordinates is a flat world, not a free one: every trip is
 *     XERIC_TRAVEL_UNKNOWN minutes. Rounding an unknown distance down to nothing
 *     would silently hand back the teleporting menu this file exists to prevent,
 *     and it would do it on exactly the worlds that were forged before `at`
 *     existed.
 *
 *  4. DISTANCE DOES NOT GATE THE PHONE. Nothing here is consulted by chat.php to
 *     decide whether you may text somebody. The phone is the product and it
 *     works from anywhere. The only thing position changes about a conversation
 *     is that the person can now know you are standing in front of them.
 *
 * Not here, deliberately: the multi-speaker room turn, adjacency (roads, walls,
 * one-way), vehicles, and any notion of a drawn map. The map is a client of
 * xeric_travel_map(); this file has no opinion about pixels.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/clock.php';
require_once __DIR__ . '/death.php';    // the dead are in no room, on no map
// For xeric_sweep_place_open() alone — the one reader of the free-form hours bag,
// and duplicating it here is how the map and the sweeps would eventually
// disagree about whether the diner is open. sweeps.php pulls the model seam in
// behind it, which costs nothing anywhere travel is actually used (the web app
// has loaded all of it before the first request line is read).
require_once __DIR__ . '/sweeps.php';

/** Corner to corner across a world that did not say. Twenty minutes is a town. */
const XERIC_TRAVEL_ACROSS = 20;

/** The floor. Two places at the same coordinates are still two places, and you
 *  still have to stand up, put a coat on and walk in. Zero-minute travel is the
 *  teleporting menu. */
const XERIC_TRAVEL_MIN = 2;

/** One or both ends have no coordinates: a flat world where everything is the
 *  same middling distance from everything else. Deliberately not the average of
 *  a real layout — a made-up number that is obviously uniform reads as "this
 *  world has no map yet", where plausible-looking varied numbers would read as a
 *  map that is wrong. */
const XERIC_TRAVEL_UNKNOWN = 10;

/** A ceiling on one trip, so a template that writes `minutes_across: 100000`
 *  strands nobody and a coordinate typo cannot age a cast out of its own week. */
const XERIC_TRAVEL_MAX = 240;

/** The grid is 0–100 on both axes, so the longest possible trip is its diagonal. */
const XERIC_TRAVEL_SPAN = 141.4213562373095;

// ---------------------------------------------------------------------------
// The geometry
// ---------------------------------------------------------------------------

/**
 * One place's coordinates, or null.
 *
 * Accepts `{"x": 62, "y": 30}` and `[62, 30]`, because the schema says the first
 * and a model asked for two numbers will hand back the second often enough that
 * refusing it would throw away real geography over punctuation. Anything that is
 * not two finite numbers is null rather than (0,0): a place at the origin is a
 * place in the corner of the map, and quietly putting every malformed record
 * there would build a world where half the town shares an address.
 */
function xeric_travel_at(array $t, ?string $key): ?array
{
    if ($key === null || $key === '') return null;
    $p = xeric_world_place($t, $key);
    if ($p === null) return null;

    $at = $p['at'] ?? null;
    if (!is_array($at)) return null;

    $x = $at['x'] ?? $at[0] ?? null;
    $y = $at['y'] ?? $at[1] ?? null;
    if (!is_numeric($x) || !is_numeric($y)) return null;

    $x = (float)$x;
    $y = (float)$y;
    if (!is_finite($x) || !is_finite($y)) return null;

    return ['x' => max(0.0, min(100.0, $x)), 'y' => max(0.0, min(100.0, $y))];
}

/**
 * Where a POSITION sits, which is not quite where a place sits.
 *
 * null — the player is not anywhere on the map — is a position and has to cost
 * something to leave. It anchors to `user.home_key` when the template names one,
 * and otherwise to the middle of the places the world does have: a person's own
 * time is spent SOMEWHERE, and in a world of one main street that somewhere is
 * about as far from everything as anything else is. The alternative is charging
 * XERIC_TRAVEL_UNKNOWN to step out of the diner in a world that knows precisely
 * how big it is — a ten-minute walk home across a town you can cross in nine.
 */
function xeric_travel_anchor(array $t, ?string $key): ?array
{
    if ($key !== null && $key !== '') return xeric_travel_at($t, $key);

    $home = trim((string)($t['user']['home_key'] ?? ''));
    if ($home !== '') {
        $at = xeric_travel_at($t, $home);
        if ($at !== null) return $at;
    }
    return xeric_travel_centre($t);
}

/** The middle of everywhere this world has put on the map. null when it has not. */
function xeric_travel_centre(array $t): ?array
{
    $x = $y = 0.0;
    $n = 0;
    foreach ((array)($t['places'] ?? []) as $p) {
        $at = xeric_travel_at($t, (string)($p['key'] ?? ''));
        if ($at === null) continue;
        $x += $at['x'];
        $y += $at['y'];
        $n++;
    }
    return $n === 0 ? null : ['x' => $x / $n, 'y' => $y / $n];
}

/** How far across this world is, in minutes, clamped to something survivable. */
function xeric_travel_across(array $t): int
{
    $m = $t['setting']['travel']['minutes_across'] ?? null;
    if (!is_numeric($m)) return XERIC_TRAVEL_ACROSS;
    return max(1, min(XERIC_TRAVEL_MAX, (int)round((float)$m)));
}

/** How the world says people get about — printed, never parsed. */
function xeric_travel_how(array $t): string
{
    return xeric_text($t['setting']['travel']['how'] ?? '');
}

/**
 * What it costs to get from one position to another, in whole minutes.
 *
 * Straight line, because a town of nine hundred does not need a road graph and a
 * road graph is the kind of thing that has to be right to be worth anything. The
 * shape of the answer — near things are cheap, the place out past the tracks is
 * not — is what makes a schedule into a puzzle, and a straight line has that
 * shape. Adjacency can replace the two lines in the middle of this function
 * whenever a world needs a river you cannot walk across.
 */
function xeric_travel_minutes(array $t, ?string $from, ?string $to): int
{
    $from = ($from === '' ? null : $from);
    $to   = ($to === '' ? null : $to);
    if ($from === $to) return 0;

    $a = xeric_travel_anchor($t, $from);
    $b = xeric_travel_anchor($t, $to);
    if ($a === null || $b === null) return XERIC_TRAVEL_UNKNOWN;

    $d = hypot($a['x'] - $b['x'], $a['y'] - $b['y']);
    $m = (int)round($d / XERIC_TRAVEL_SPAN * xeric_travel_across($t));

    return max(XERIC_TRAVEL_MIN, min(XERIC_TRAVEL_MAX, $m));
}

/** Does this world have a layout at all, or is it flat? Two placed rooms is the
 *  minimum at which any distance means anything. */
function xeric_travel_mapped(array $t): bool
{
    $n = 0;
    foreach ((array)($t['places'] ?? []) as $p) {
        if (xeric_travel_at($t, (string)($p['key'] ?? '')) !== null && ++$n >= 2) return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// The body
// ---------------------------------------------------------------------------

/**
 * Where the player is standing, as the database has it — unvalidated.
 *
 * Almost every caller wants xeric_player_where() instead. This one exists so the
 * validating reader has something to validate.
 */
function xeric_player_where_raw(PDO $db): ?string
{
    $v = trim((string)(xeric_world_state_get($db, 'player.where') ?? ''));
    return $v === '' ? null : $v;
}

/**
 * Where the player is standing, checked against the world they are standing in.
 *
 * A place can stop existing under somebody: review.php rerolls, a template gets
 * edited, a world is forked from one that has moved on. A stored key with no
 * place behind it resolves to null — nowhere — rather than to a ghost room,
 * because every consumer of position downstream (the prompt's room line, the
 * arrival report, the map) is about who else is present, and being present in a
 * room that does not exist would put the player in company with nobody, forever,
 * with no way to notice.
 */
function xeric_player_where(array $t, PDO $db): ?string
{
    $k = xeric_player_where_raw($db);
    if ($k === null) return null;
    return xeric_world_place($t, $k) !== null ? $k : null;
}

/**
 * Put the player somewhere. Writes position only — never the clock.
 *
 * Splitting this from xeric_travel_go() is what lets a world start somebody at
 * home, a test place them without burning six hours, and a future room-turn move
 * them mid-scene, all without either of those having to know what a trip costs.
 */
function xeric_player_move(PDO $db, ?string $key, ?int $worldEpoch = null): void
{
    $key = ($key === null) ? '' : trim($key);
    xeric_world_state_set($db, 'player.where', $key);
    if ($worldEpoch !== null) xeric_world_state_set($db, 'player.where_since', (string)$worldEpoch);
}

/** When the player got where they are, on the world clock. 0 when never set. */
function xeric_player_since(PDO $db): int
{
    return (int)(xeric_world_state_get($db, 'player.where_since') ?? '0');
}

// ---------------------------------------------------------------------------
// The trip
// ---------------------------------------------------------------------------

/**
 * Go somewhere. Burns the minutes, moves the body, and reports the arrival.
 *
 * The clock moves FIRST and the position moves second, in that order and not the
 * other, so a world that dies between the two statements has a player who is
 * still where they were rather than one who teleported. Both are single
 * world_state writes; there is no window worth a transaction here, only an order
 * worth getting right.
 *
 * $to may be null: leaving the map is a trip like any other, it costs what
 * getting home costs, and it is how you stop being in the room.
 *
 * @return array{ok:bool,error:?string,from:?string,to:?string,minutes:int,
 *                now:array,open:bool,who:string[],place:?array}
 *         `who` is who is standing there when you walk in, which is computed
 *         AFTER the clock moves — the whole point being that the twenty minutes
 *         it took to drive out there are twenty minutes in which she left.
 */
function xeric_travel_go(array $t, PDO $db, ?string $to): array
{
    $to   = ($to === null || trim($to) === '') ? null : trim($to);
    $from = xeric_player_where($t, $db);

    $fail = function (string $why) use ($t, $db, $from): array {
        return ['ok' => false, 'error' => $why, 'from' => $from, 'to' => $from, 'minutes' => 0,
                'now' => xeric_clock_now($db, $t), 'open' => true, 'who' => [],
                'place' => $from !== null ? xeric_world_place($t, $from) : null];
    };

    if ($to !== null && xeric_world_place($t, $to) === null) {
        return $fail('There is no such place in this world.');
    }
    if ($to === $from) {
        return $fail($to === null
            ? 'You are already on your own time.'
            : 'You are already at ' . xeric_world_place_name($t, $to) . '.');
    }

    // A STOPPED WORLD CANNOT BE WALKED ACROSS. Travel calls no model, so it is
    // tempting to allow it — but a trip is ten minutes of world time, and a
    // world that is stopped is stopped. Refused as a state rather than thrown,
    // because every other refusal in this function is.
    if (xeric_clock_is_paused($db)) {
        return $fail('This world is stopped, so nothing takes any time. Attach a machine and it '
            . 'starts again where it was.');
    }

    $minutes = xeric_travel_minutes($t, $from, $to);

    // Never let a template's arithmetic reach the clock unchecked: xeric_travel_minutes()
    // already clamps, and this is the assertion that the clamp held.
    if ($minutes < 0 || $minutes > XERIC_TRAVEL_MAX) return $fail('That is not a distance.');

    $now = $minutes > 0 ? xeric_clock_advance($db, $minutes * 60, $t) : xeric_clock_now($db, $t);
    xeric_player_move($db, $to, (int)$now['epoch']);

    return [
        'ok'      => true,
        'error'   => null,
        'from'    => $from,
        'to'      => $to,
        'minutes' => $minutes,
        'now'     => $now,
        'open'    => $to === null ? true : xeric_travel_open($t, $to, $now, xeric_dark_places($db)),
        'who'     => $to === null ? [] : xeric_world_who_is_at(xeric_world_who_is_where($t, $now, xeric_dead_handles($db)), $to),
        'place'   => $to !== null ? xeric_world_place($t, $to) : null,
    ];
}

/**
 * Is this place open at this moment? Unreadable hours are open (sweeps.php).
 *
 * $dark is the set of rooms the world has lost, and it beats the hours outright:
 * a diner whose town is gone is not open at 05:30 because its template still
 * says 05:30. Passed rather than read for the same reason the dead are — this
 * takes a template and a moment, and what a world has LOST is state.
 */
function xeric_travel_open(array $t, string $key, array $now, ?array $dark = null): bool
{
    $p = xeric_world_place($t, $key);
    if ($p === null) return false;
    if ($dark !== null && in_array($key, $dark, true)) return false;

    return xeric_sweep_place_open(
        (array)($p['hours'] ?? []),
        xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0,
        (int)($now['dow'] ?? 0)
    );
}

// ---------------------------------------------------------------------------
// The read model
// ---------------------------------------------------------------------------

/**
 * The town, as data, at this moment. THE thing to build clients against.
 *
 * A map is a client of this. So is a first-person view, so is a headset, so is
 * the list of buttons the play screen draws today. That is the whole
 * architectural bet: the surface is the cheap, replaceable, one-shottable part,
 * and it stays cheap only for as long as nothing in it has to re-derive who is
 * standing where or what a trip costs. Everything a renderer could possibly want
 * about position is in here, and anything it wants that is NOT in here belongs
 * in here rather than in the renderer.
 *
 * Coordinates travel even when the world is flat (as nulls) so a client can tell
 * "this world has no layout" from "this place is at the origin" — `mapped` says
 * which case it is in one boolean so nothing has to count nulls to find out.
 */
function xeric_travel_map(array $t, PDO $db, ?array $now = null): array
{
    $now      ??= xeric_clock_now($db, $t);
    $here      = xeric_player_where($t, $db);
    $presence  = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));
    $dark      = xeric_dark_places($db);

    $places = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $key = (string)($p['key'] ?? '');
        if ($key === '') continue;

        $at  = xeric_travel_at($t, $key);
        $who = [];
        foreach (xeric_world_who_is_at($presence, $key) as $h) {
            $who[] = ['handle' => $h, 'name' => xeric_world_name($t, $h),
                      'doing'  => xeric_text($presence[$h]['doing'] ?? '')];
        }

        $places[] = [
            'key'         => $key,
            'name'        => (string)($p['name'] ?? $key),
            'kind'        => (string)($p['kind'] ?? ''),
            'description' => xeric_text($p['description'] ?? ''),
            'at'          => $at,
            'open'        => xeric_travel_open($t, $key, $now, $dark),
            'dark'        => in_array($key, $dark, true),
            'here'        => $key === $here,
            'minutes'     => $key === $here ? 0 : xeric_travel_minutes($t, $here, $key),
            'who'         => $who,
        ];
    }

    return [
        'now' => [
            'epoch' => (int)$now['epoch'],
            'hhmm'  => (string)($now['hhmm'] ?? ''),
            'dow'   => (int)($now['dow'] ?? 0),
            'phase' => (string)($now['phase'] ?? ''),
        ],
        'you' => [
            'name'    => xeric_text($t['user']['name'] ?? '') ?: 'you',
            'where'   => $here,
            'place'   => $here !== null ? xeric_world_place_name($t, $here) : '',
            'since'   => xeric_player_since($db),
            // Going home is a place on the list like any other, and the only one
            // that is not a place. A client renders it as a button; it does not
            // have to know it is special.
            'leave'   => ['minutes' => $here === null ? 0 : xeric_travel_minutes($t, $here, null)],
        ],
        'mapped' => xeric_travel_mapped($t),
        'how'    => xeric_travel_how($t),
        'across' => xeric_travel_across($t),
        'places' => $places,
    ];
}
