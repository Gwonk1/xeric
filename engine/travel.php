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
 * SIX DECISIONS WORTH KNOWING BEFORE EDITING THIS:
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
 *  5. AN UNSTAGED HOME IS NOT A DESTINATION. A home whose every resident is OUT
 *     of the story is a house nobody in your story lives in yet
 *     (WORLD_TEMPLATE.md: "their home not visitable"). It is the one refusal in
 *     this file that is not about geometry or existence — refused by STAGING,
 *     never by hours — and it is symmetric: the map does not list the place, the
 *     trip is refused in story terms, and a player left standing in one (a
 *     reroll flipped the resident out under them) resolves to nowhere, exactly
 *     like a room that stopped existing. A shared roof stays visitable while
 *     anybody under it is staged; the day the resident ENTERS (enter.php), the
 *     house appears on the map with them.
 *
 *  6. A MOVING WORLD CANNOT BE WALKED ACROSS. Stopped is stopped (the pause
 *     check below), and mid-skip is MOVING: a trip that lands its minutes while
 *     tick-worker.php is walking a six-hour fast-forward would shove the clock
 *     out from under the sweep windows and smuggle its own writes into the
 *     skip's rewind manifest. The worker stamps `skip:underway` in world_state
 *     while the clock is in its hands; travel refuses while the stamp is fresh
 *     and ignores a stale one, because a worker that died mid-skip must not
 *     brick walking forever.
 *
 * Not here, deliberately: the multi-speaker room turn, adjacency (roads, walls,
 * one-way), vehicles, and any notion of a drawn map. The map is a client of
 * xeric_travel_map(); this file has no opinion about pixels.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/players.php';   // a body each: the roster, its suffixes, the seam
require_once __DIR__ . '/clock.php';
require_once __DIR__ . '/death.php';    // the dead are in no room, on no map
require_once __DIR__ . '/players.php';  // more than one body: the suffix discipline
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

/** How long a `skip:underway` stamp is believed, in real seconds. The tick
 *  worker stamps when it takes the clock and REFRESHES the stamp at every
 *  between-window checkpoint, again the moment the window loop ends, and
 *  between the proactive candidates that follow it (tick-worker.php) — never
 *  mid-call. So a live skip's stamp is never older than the longest stretch
 *  between two of those checkpoints: one window's worth of work (at most two
 *  240-second model calls, the event and its one diverge retry), or, in the
 *  post-loop tail, one 180-second proactive candidate plus the distil pass's
 *  60 — both inside this margin. The tail matters: the worker is still inside
 *  its rewind-mark-to-commit span there, and a walk waved in by an aged-out
 *  stamp would land its writes in the skip's manifest. Anything older than
 *  this IS a worker that died without its finally, and a dead worker must
 *  not leave a world nobody can walk across. (The queue's own 420s hold cap,
 *  forge/web/queue.php, is checked at the same checkpoints; it bounds how
 *  long the whole skip may run, not how stale a live stamp can get.) */
const XERIC_TRAVEL_SKIP_STALE = 600;

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

/**
 * Is this place a home nobody in the story lives in yet? (Decision 5.)
 *
 * True only for a `kind: "home"` whose every resident is a character with
 * `out: true`. One staged resident — or one fixture, which cannot be out —
 * stages the whole roof: the shared home of a marriage where one spouse has
 * not entered is still the other spouse's front door. A home with no residents
 * at all is the validator's problem (it refuses to load one), not travel's to
 * re-litigate, so it reads as staged here rather than silently vanishing from
 * a world that was hand-edited past the rules.
 */
function xeric_travel_unstaged(array $t, string $key): bool
{
    $p = xeric_world_place($t, $key);
    if ($p === null || (string)($p['kind'] ?? '') !== 'home') return false;

    $residents = (array)($p['residents'] ?? []);
    if ($residents === []) return false;

    foreach ($residents as $r) {
        $c = xeric_world_character($t, (string)$r);
        if ($c === null || empty($c['out'])) return false;   // a fixture, or somebody staged
    }
    return true;
}

/**
 * Is a skip moving this world's clock right now? (Decision 6.)
 *
 * Reads the `skip:underway` real-time stamp the tick worker holds while the
 * clock is in its hands. Fresh means refuse; stale means the worker died and
 * the stamp is a ghost, so it is ignored. $realNow is injectable for tests,
 * the same way the clock's is.
 */
function xeric_travel_skipping(PDO $db, ?int $realNow = null): bool
{
    $at = (int)(xeric_world_state_get($db, 'skip:underway') ?? '0');
    if ($at <= 0) return false;
    return (($realNow ?? xeric_state_time()) - $at) <= XERIC_TRAVEL_SKIP_STALE;
}

// ---------------------------------------------------------------------------
// The body
// ---------------------------------------------------------------------------

/**
 * Where the player is standing, as the database has it — unvalidated.
 *
 * Almost every caller wants xeric_player_where() instead. This one exists so the
 * validating reader has something to validate.
 *
 * A BODY EACH (FUTURE.md M4). $player null is the current player
 * (xeric_player_current — the web layer's seam, the first player everywhere
 * else). The first player's row is the bare `player.where` every world on disk
 * already has; the second person's is `player.where.p2`, by the same suffix
 * discipline as every per-person row (players.php), so no world was migrated
 * to grow a second body.
 */
function xeric_player_where_raw(PDO $db, ?int $player = null): ?string
{
    $player ??= xeric_player_current(null, $db);
    $v = trim((string)(xeric_world_state_get($db, xeric_player_key('player.where', $player)) ?? ''));
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
 *
 * An UNSTAGED home resolves to nowhere the same way (decision 5): a reroll can
 * flip a resident OUT under a player standing in their kitchen, and a position
 * inside a house that is not in the story yet is a position the map no longer
 * lists — the ghost-room problem wearing a front door.
 */
function xeric_player_where(array $t, PDO $db, ?int $player = null): ?string
{
    $k = xeric_player_where_raw($db, $player);
    if ($k === null) return null;
    if (xeric_world_place($t, $k) === null || xeric_travel_unstaged($t, $k)) return null;
    return $k;
}

/**
 * Put the player somewhere. Writes position only — never the clock.
 *
 * Splitting this from xeric_travel_go() is what lets a world start somebody at
 * home, a test place them without burning six hours, and a future room-turn move
 * them mid-scene, all without either of those having to know what a trip costs.
 *
 * IN A WORLD WITH COMPANY, where they came FROM is kept too (`player.where_from`,
 * suffixed like the rest): the one fact another player's doorway needs to say
 * "Neil gets up and goes", which presence cannot derive for a body the way it
 * derives a character's from the week. Only when the roster has more than one
 * person in it, so a world of one never grows the row and stays byte-identical.
 *
 * AND THE LAST FEW MOVES, with their epochs (`player.moves[.p<n>]`, the newest
 * XERIC_PLAYER_MOVES of [epoch|null, from, to]): `where_from` is the last stop
 * only, and a friend who went diner → mill → store inside one doorway's
 * stretch was read as having come from the mill — so the diner never saw them
 * leave. The log answers "where were they at $at" (xeric_player_where_at).
 * Same rule: written only in a world with company.
 */
function xeric_player_move(PDO $db, ?string $key, ?int $worldEpoch = null, ?int $player = null): void
{
    $player ??= xeric_player_current(null, $db);
    $key = ($key === null) ? '' : trim($key);
    if (xeric_players_many($db)) {
        $was = (string)(xeric_player_where_raw($db, $player) ?? '');
        xeric_world_state_set($db, xeric_player_key('player.where_from', $player), $was);
        $log = json_decode((string)(xeric_world_state_get($db, xeric_player_key('player.moves', $player)) ?? '[]'), true);
        if (!is_array($log)) $log = [];
        $log[] = [$worldEpoch, $was, $key];
        xeric_world_state_set($db, xeric_player_key('player.moves', $player),
                              json_encode(array_slice($log, -XERIC_PLAYER_MOVES), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    xeric_world_state_set($db, xeric_player_key('player.where', $player), $key);
    if ($worldEpoch !== null) {
        xeric_world_state_set($db, xeric_player_key('player.where_since', $player), (string)$worldEpoch);
    }
}

/** When the player got where they are, on the world clock. 0 when never set. */
function xeric_player_since(PDO $db, ?int $player = null): int
{
    $player ??= xeric_player_current(null, $db);
    return (int)(xeric_world_state_get($db, xeric_player_key('player.where_since', $player)) ?? '0');
}

/** How many moves a body's log keeps — far more than one doorway's stretch ever holds. */
const XERIC_PLAYER_MOVES = 8;

/**
 * Where a player's body stood at world moment $at, off their move log: the
 * destination of the newest move at or before $at, else where the oldest
 * logged move set out from. A body that has not moved since $at is where it is
 * now; a log that does not exist (a world from before it, a move with no
 * company yet) falls back to `where_from`, the one stop it had. '' is nowhere.
 */
function xeric_player_where_at(PDO $db, int $player, int $at, ?string $now = null): ?string
{
    if (xeric_player_since($db, $player) <= $at) return $now ?? xeric_player_where_raw($db, $player);
    $log = json_decode((string)(xeric_world_state_get($db, xeric_player_key('player.moves', $player)) ?? ''), true);
    if (!is_array($log) || $log === []) return xeric_player_where_from($db, $player);
    $hit = null;
    foreach ($log as $m) {
        if (!is_array($m) || ($m[0] ?? null) === null) continue;
        if ((int)$m[0] <= $at) $hit = $m;
    }
    $v = trim((string)($hit !== null ? ($hit[2] ?? '') : ($log[0][1] ?? '')));
    return $v === '' ? null : $v;
}

/** Where they were before they got where they are — null when unknown or nowhere. */
function xeric_player_where_from(PDO $db, int $player): ?string
{
    $v = trim((string)(xeric_world_state_get($db, xeric_player_key('player.where_from', $player)) ?? ''));
    return $v === '' ? null : $v;
}

/** A player's handle in every `who` list: `p<n>`, which no cast handle may be
 *  confused for in practice and which says plainly what it is. */
function xeric_player_handle(int $player): string
{
    return 'p' . max(XERIC_PLAYER_FIRST, $player);
}

/**
 * Everybody at the centre and where their body is — the roster, positioned.
 *
 * @return array<int,array{id:int,handle:string,name:string,where:?string,since:int}>
 */
function xeric_travel_players(array $t, PDO $db): array
{
    $out = [];
    foreach (xeric_players($db, $t) as $id => $p) {
        $out[$id] = ['id' => (int)$id, 'handle' => xeric_player_handle((int)$id),
                     'name' => (string)$p['name'],
                     'where' => xeric_player_where($t, $db, (int)$id),
                     'since' => xeric_player_since($db, (int)$id)];
    }
    return $out;
}

/**
 * The OTHER players standing in a place — rows a `who` list can carry beside
 * the cast: `{handle: 'p<n>', name, player: true}` and nothing else. A player
 * has no appearance in the template, no week and no errand, so there is
 * nothing more an observer could honestly say; inventing a coat for a real
 * person would be the engine dressing somebody it has never seen.
 *
 * NEVER IN PRESENCE. xeric_world_who_is_where() is the cast, and stays the
 * cast: the sweeps, the prompt's room line and every "who is in the diner"
 * a model is ever handed are about characters, and a human is not one.
 *
 * @param ?array $players xeric_travel_players(), when the caller has it
 * @return array<int,array{handle:string,name:string,player:true}>
 */
function xeric_travel_others_at(array $t, PDO $db, string $place, ?int $me = null, ?array $players = null): array
{
    $me ??= xeric_player_current(null, $db);
    $players ??= xeric_travel_players($t, $db);
    $out = [];
    foreach ($players as $id => $p) {
        if ((int)$id === $me || $p['where'] !== $place) continue;
        $out[] = ['handle' => $p['handle'], 'name' => $p['name'], 'player' => true];
    }
    return $out;
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
 * ONE BODY MOVES; EVERYBODY'S CLOCK DOES. With a body each, a trip moves the
 * CURRENT player (xeric_player_current) and nobody else — and burns its
 * minutes on the one world clock, because time is a fact about the world and
 * not about a person (players.php). Two people walking in one world spend one
 * clock: a twenty-minute drive by one of you is twenty minutes everybody in
 * the town lives through, the other player included, standing where they
 * stood. That is the honest version of co-op time rather than a limitation of
 * it — two clocks in one town would be two towns. A trip lands whole (the
 * advance and the move are two writes, never a span), so there is no such
 * thing as another player being half-way across; what CAN be in flight is a
 * skip, and the skipping guard below refuses everybody's trip alike while one
 * is walking the hours.
 *
 * @return array{ok:bool,error:?string,from:?string,to:?string,minutes:int,
 *                now:array,open:bool,who:string[],place:?array}
 *         `who` is who is standing there when you walk in, which is computed
 *         AFTER the clock moves — the whole point being that the twenty minutes
 *         it took to drive out there are twenty minutes in which she left.
 *         Cast handles only; the other players standing there ride in
 *         `players` (xeric_travel_others_at rows), present only when there are
 *         any, so a world of one answers exactly what it always did.
 */
function xeric_travel_go(array $t, PDO $db, ?string $to, ?int $player = null): array
{
    // Whose feet: the caller's word when it gives one (a seat that is not a
    // browser session — an agent, a test), else the current player's.
    $player ??= xeric_player_current(null, $db);
    $to   = ($to === null || trim($to) === '') ? null : trim($to);
    $from = xeric_player_where($t, $db, $player);

    $fail = function (string $why) use ($t, $db, $from): array {
        return ['ok' => false, 'error' => $why, 'from' => $from, 'to' => $from, 'minutes' => 0,
                'now' => xeric_clock_now($db, $t), 'open' => true, 'who' => [],
                'place' => $from !== null ? xeric_world_place($t, $from) : null];
    };

    if ($to !== null && xeric_world_place($t, $to) === null) {
        return $fail('There is no such place in this world.');
    }
    // THE ONE REFUSAL THAT IS NOT HOURS (decision 5). You may walk to a closed
    // place; you may not walk into the home of somebody who has not entered the
    // story. Refused in story terms, before any minute burns — the map does not
    // list this place, so only a client speaking raw keys ever gets this far.
    if ($to !== null && xeric_travel_unstaged($t, $to)) {
        return $fail('Whoever lives there has not come into your story yet, and that door '
            . 'does not open for you.');
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

    // AND NEITHER CAN A MOVING ONE (decision 6). While a skip is walking its
    // hours, a trip's advance would land inside the worker's sweep windows and
    // its writes inside the skip's rewind manifest.
    if (xeric_travel_skipping($db)) {
        return $fail('This world is fast-forwarding right now. Let those hours land, then set out.');
    }

    $minutes = xeric_travel_minutes($t, $from, $to);

    // Never let a template's arithmetic reach the clock unchecked: xeric_travel_minutes()
    // already clamps, and this is the assertion that the clamp held.
    if ($minutes < 0 || $minutes > XERIC_TRAVEL_MAX) return $fail('That is not a distance.');

    try {
        $now = $minutes > 0 ? xeric_clock_advance($db, $minutes * 60, $t) : xeric_clock_now($db, $t);
    } catch (Throwable $e) {
        // The pause landing between the guard above and the advance is a race,
        // not a bug, and the clamp keeps every other throw in clock.php out of
        // reach — so this is the stopped world again, refused as a state like
        // every refusal before it. Nothing was written; the player never left.
        return $fail('This world is stopped, so nothing takes any time. Attach a machine and it '
            . 'starts again where it was.');
    }
    // The WALKER's body moves; the clock that just advanced is everybody's
    // (header on xeric_player_where_raw — time is a world fact, feet are not).
    xeric_player_move($db, $to, (int)$now['epoch'], $player);

    $presence = $to === null ? [] : xeric_world_who_is_where($t, $now, xeric_dead_handles($db), $db);
    $who      = $to === null ? [] : xeric_world_who_is_at($presence, $to);
    $others   = $to === null ? [] : xeric_travel_others_at($t, $db, $to);

    return [
        'ok'      => true,
        'error'   => null,
        'from'    => $from,
        'to'      => $to,
        'minutes' => $minutes,
        'now'     => $now,
        'open'    => $to === null ? true : xeric_travel_open($t, $to, $now, xeric_dark_places($db)),
        'who'     => $who,
        'place'   => $to !== null ? xeric_world_place($t, $to) : null,
        'scene'   => $to !== null ? xeric_travel_scene($t, $to, $now, $presence, $db, $others) : '',
    ] + ($others !== [] ? ['players' => $others] : []);
}

/**
 * The narrator's line for walking into a room — assembled, never asked for.
 *
 * "*Jim and Tim are discussing stocks at the bar*" was the sketch, and every
 * word of it is available without a model: who is here is the presence read,
 * what they are doing is the week's own `doing` field, and the furniture is
 * the place's `interior` list. So the beat is DETERMINISTIC — built from
 * observables, byte-stable for a given room, hour and cast, and free.
 *
 * OBSERVABLES ONLY, at the wall's edge. What you get on entry is what a body
 * in a doorway gets: who, posture, props. When two people are mid-something,
 * you get the SURFACE of it — "the talk drops while the door swings shut" —
 * and never the topic, because topic-knowledge is exactly where the walls
 * begin, and "they went quiet when you came in" is both legal and delicious.
 *
 * The prop is picked by (place, date) the way the sky is (weather.php): one
 * detail per room per day, so every arrival that day agrees on which chair
 * matters, and a room with no `interior` list simply has no furniture worth
 * mentioning yet — absent, not invented.
 */
/**
 * The tail of the talk — the REAL conversation an arrival walked in on.
 *
 * The heartbeat writes each hour's audible surface into the event row
 * (events.overheard, the same sweep call that wrote the hour, so it costs
 * nothing extra). This reads the freshest one for this room and hands it to
 * the doorway — but only while it is still WARM: within two world-hours, and
 * only if somebody who was in that hour is still standing here. Stale talk
 * quoted to a fresh arrival would be a room haunted by its own transcript.
 * Wall- and floor-screened at generation like everything else in the record.
 */
function xeric_travel_overheard(PDO $db, string $to, array $now, array $here): string
{
    $epoch = (int)($now['epoch'] ?? 0);
    $st = $db->prepare("SELECT participants, overheard FROM events
                        WHERE place = ? AND overheard != '' AND world_epoch > ? AND world_epoch <= ?
                        ORDER BY world_epoch DESC LIMIT 1");
    $st->execute([$to, $epoch - 2 * 3600, $epoch]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    if ($rows === []) return '';

    $who = array_map('strval', (array)json_decode((string)$rows[0]['participants'], true));
    if (array_intersect($who, $here) === []) return '';   // the speakers have moved on
    return trim((string)$rows[0]['overheard']);
}

/**
 * $others are the other players standing there (xeric_travel_others_at rows):
 * named in the doorway like anybody, with nothing they are doing — a person
 * has no week for the narrator to read an errand off.
 */
function xeric_travel_scene(array $t, string $to, array $now, array $presence, ?PDO $db = null, array $others = []): string
{
    $place = xeric_world_place($t, $to);
    if ($place === null) return '';
    $pname = (string)($place['name'] ?? $to);

    // The day's prop, if the forge furnished the room.
    $props = array_values(array_filter(array_map(
        fn($i) => trim(xeric_text($i)), (array)($place['interior'] ?? [])), fn($s) => $s !== ''));
    $iso   = (string)($now['iso'] ?? '');
    $seed  = crc32($to . '|' . substr($iso, 0, 10));
    $prop  = $props !== [] ? $props[$seed % count($props)] : '';

    $here = [];
    foreach ($presence as $row) {
        if (($row['where'] ?? null) !== $to) continue;
        $h = (string)($row['handle'] ?? '');
        if ($h === '') continue;
        $here[] = ['handle' => $h, 'name' => xeric_world_name($t, $h) ?: $h,
                   'doing' => trim((string)($row['doing'] ?? ''))];
    }
    foreach ($others as $o) {
        $here[] = ['handle' => (string)$o['handle'], 'name' => (string)$o['name'], 'doing' => ''];
    }

    if ($here === []) {
        return $prop !== ''
            ? "Nobody is at $pname this hour. " . ucfirst($prop) . ' sits where it always sits.'
            : "Nobody is at $pname this hour.";
    }

    if (count($here) === 1) {
        $p = $here[0];
        $line = $p['doing'] !== ''
            ? $p['name'] . ' is here, ' . rtrim($p['doing'], '.') . '.'
            : $p['name'] . ' is here.';
        return $prop !== '' ? $line . ' ' . ucfirst($prop) . ' between you.' : $line;
    }

    // Two or more: names, then what each is at, then the surface of the room
    // noticing a door. The closers rotate by (place, hour) so a day of coming
    // and going does not read like a stamp, and every one of them is a thing
    // a bystander could see — none of them is a topic.
    $names = array_column($here, 'name');
    $line  = count($names) === 2
        ? $names[0] . ' and ' . $names[1] . ' are here.'
        : implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) . ' are here.';
    foreach ($here as $p) {
        if ($p['doing'] !== '') $line .= ' ' . $p['name'] . ' is ' . rtrim($p['doing'], '.') . '.';
    }
    if ($prop !== '') $line .= ' ' . ucfirst($prop) . '.';

    // THE REAL TALK FIRST. When the heartbeat wrote this room an audible hour
    // and its speakers are still standing here, the doorway gets the actual
    // words — you caught the tail of a conversation that genuinely happened
    // without you. Only then the generic closers, for rooms whose talk went
    // unrecorded or cold.
    if ($db !== null) {
        $heard = xeric_travel_overheard($db, $to, $now, array_column($here, 'handle'));
        if ($heard !== '') {
            return $line . ' You catch the tail of it — ' . $heard
                 . ' — and then the talk drops while the door swings shut behind you.';
        }
    }

    $closers = [
        'The talk drops while the door swings shut behind you.',
        'Whatever it was, it waits until you have found somewhere to stand.',
        'Nobody picks the conversation back up right away.',
        'The room takes its one look at the door and goes back to itself.',
    ];
    $hourSeed = crc32($to . '|' . substr($iso, 0, 13));
    return $line . ' ' . $closers[$hourSeed % count($closers)];
}

/**
 * Who came in and who went, in one line a bystander could have said — the
 * doorway's sibling. "Dot Vance comes in, shaking the rain off." "Harlan Beck
 * gets up and goes." Names, never pronouns; a verb that agrees with how many;
 * and the only flourish is the weather's own line (the narrator's sky when
 * one was set, the derived one otherwise), read for the one thing anybody
 * coming through a door carries in with them. Where somebody went is NOT
 * here: everyone saw them go, and where to is schedule knowledge a wall may
 * be hiding. Indoors-only places get no weather — nobody shakes the rain off
 * walking into a corridor of a station that has none.
 *
 * Used twice, so the two can never double up: the look block's beat, and
 * inside a live room (engine/room.php) the narrator row that replaces it.
 */
function xeric_travel_comings_line(array $t, array $now, string $place, array $arrived, array $left, ?PDO $db = null,
                                   array $names = []): string
{
    // $names: handle => name for anybody the template cannot name — the other
    // players, whose handles are `p<n>` and whose names are the roster's.
    $name  = fn(string $h): string => (string)($names[$h] ?? '') ?: (xeric_world_name($t, $h) ?: $h);
    $bits  = [];
    if ($arrived !== []) {
        $who = xeric_join_list(array_map($name, array_values($arrived)));
        $wx  = mb_strtolower(xeric_weather_line($t, $now, $db));
        $with = '';
        if (xeric_weather_climate($t) !== 'interior') {
            if (preg_match('/\b(rain|shower|drizzle|downpour|storm)/u', $wx) && !str_contains($wx, 'never'))      $with = ', shaking the rain off';
            elseif (preg_match('/\bsnow|sleet/u', $wx))                           $with = ', stamping the snow off';
            elseif (preg_match('/\b(frost|bitter|freez|cold|chill)/u', $wx))       $with = ', bringing the cold in';
            elseif (preg_match('/\b(heat|hot|close and heavy|shimmer)/u', $wx))    $with = ', out of the heat';
            elseif (preg_match('/\bfog/u', $wx))                                  $with = ', out of the fog';
            elseif (preg_match('/\bwind/u', $wx))                                 $with = ', the wind catching the door';
        }
        $bits[] = $who . (count($arrived) === 1 ? ' comes in' : ' come in') . $with . '.';
    }
    if ($left !== []) {
        $who = xeric_join_list(array_map($name, array_values($left)));
        $bits[] = $who . (count($left) === 1 ? ' gets up and goes.' : ' get up and go.');
    }
    return implode(' ', $bits);
}

/**
 * Who came and went at a place in the last stretch of the world's clock — the
 * observables the look block turns into a beat. Presence is read at two
 * moments through the one reader, now and the start of the stretch (the last
 * ten world-minutes, or since the player walked in, whichever is later — a
 * room you have just entered had nobody arriving into it, it had you), and
 * the difference is the news. PURE: two reads of the template's week, no
 * state kept between pulses, so every surface that asks gets the same answer.
 *
 * THE OTHER PLAYERS COME AND GO LIKE ANYBODY. A body has no week to read at
 * the start of the stretch, so it is read off what the moves wrote: standing
 * here since before the stretch began means they were here then; moved inside
 * it means they were wherever their move log puts them at its start
 * (xeric_player_where_at) — not merely their last stop, which misses a body
 * that walked out and on again inside one stretch. Their handles (`p<n>`)
 * ride in `arrived` / `left` after the cast's, and the line names them from
 * the roster. You are never news to yourself.
 *
 * @return array{arrived:string[],left:string[],line:string}
 */
function xeric_travel_comings(array $t, PDO $db, array $now, string $place, int $stretch = 600): array
{
    $epoch = (int)($now['epoch'] ?? 0);
    $me    = xeric_player_current(null, $db);
    $from  = max($epoch - max(60, $stretch), xeric_player_since($db, $me));
    $out   = ['arrived' => [], 'left' => [], 'line' => ''];
    if ($from >= $epoch) return $out;
    $dead   = xeric_dead_handles($db);
    $then   = xeric_world_who_is_at(xeric_world_who_is_where($t, xeric_world_now($t, $from), $dead, $db), $place);
    $hereNow = xeric_world_who_is_at(xeric_world_who_is_where($t, $now, $dead, $db), $place);
    $out['arrived'] = array_values(array_diff($hereNow, $then));
    $out['left']    = array_values(array_diff($then, $hereNow));

    $names = [];
    foreach (xeric_travel_players($t, $db) as $id => $p) {
        if ((int)$id === $me) continue;
        $nowHere  = $p['where'] === $place;
        $thenHere = $p['since'] <= $from ? $nowHere : xeric_player_where_at($db, (int)$id, $from, $p['where']) === $place;
        if ($nowHere === $thenHere) continue;
        $out[$nowHere ? 'arrived' : 'left'][] = $p['handle'];
        $names[$p['handle']] = $p['name'];
    }
    $out['line'] = xeric_travel_comings_line($t, $now, $place, $out['arrived'], $out['left'], $db, $names);
    return $out;
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
 *
 * A BODY EACH. `you` is the CURRENT player (xeric_player_current) — their
 * position, their prices, and their name off the roster. Every other player
 * stands in their place's `who` as a row with `player: true` (handle `p<n>`,
 * name, nothing else), so the list, the drawn map and the room's tags show
 * them without learning a new shape; and `players` at the top level lists
 * every body, you included, for a client that wants the company in one read.
 * A WORLD OF ONE produces exactly the map it always did, to the byte: no
 * `players` key, no rows, the template's name — continuity is not a
 * migration (engine-test asserts the JSON).
 */
function xeric_travel_map(array $t, PDO $db, ?array $now = null, ?int $player = null): array
{
    $now      ??= xeric_clock_now($db, $t);
    $me        = $player ?? xeric_player_current(null, $db);
    $roster    = xeric_travel_players($t, $db);
    $many      = count($roster) > 1;
    $here      = xeric_player_where($t, $db, $me);
    // With the database, so a Director staging stands on the map like anybody.
    $presence  = xeric_world_who_is_where($t, $now, xeric_dead_handles($db), $db);
    $dark      = xeric_dark_places($db);

    $places = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $key = (string)($p['key'] ?? '');
        if ($key === '') continue;
        // An unstaged home is not on the map at all (decision 5). Listing it
        // would advertise a character who has not entered the story by their
        // front door, and a button the engine then refuses is the lie the
        // rewind guards were extracted to prevent. The day the resident enters,
        // the house appears — the town grows, and the entrance event says why.
        if (xeric_travel_unstaged($t, $key)) continue;

        $at  = xeric_travel_at($t, $key);
        $who = [];
        foreach (xeric_world_who_is_at($presence, $key) as $h) {
            // `at_home` rides so a renderer can say "home" instead of dressing
            // a kitchen up as a shift — the same flag the narrator already
            // reads off presence, put where a client is allowed to look.
            $who[] = ['handle'  => $h, 'name' => xeric_world_name($t, $h),
                      'doing'   => xeric_text($presence[$h]['doing'] ?? ''),
                      'at_home' => !empty($presence[$h]['at_home'])]
                    // Only when true, so a world with no Director reads the
                    // same map it always did.
                    + (!empty($presence[$h]['staged']) ? ['staged' => true] : []);
        }
        // The company that is not cast, after the cast: never in presence,
        // always here. None at all in a world of one.
        if ($many) foreach (xeric_travel_others_at($t, $db, $key, $me, $roster) as $o) $who[] = $o;

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

    $map = [
        'now' => [
            'epoch' => (int)$now['epoch'],
            'hhmm'  => (string)($now['hhmm'] ?? ''),
            'dow'   => (int)($now['dow'] ?? 0),
            'phase' => (string)($now['phase'] ?? ''),
        ],
        'you' => [
            'name'    => xeric_travel_you_name($t, $db, $me, $roster),
            'where'   => $here,
            'place'   => $here !== null ? xeric_world_place_name($t, $here) : '',
            'since'   => xeric_player_since($db, $me),
            // Going home is a place on the list like any other, and the only one
            // that is not a place. A client renders it as a button; it does not
            // have to know it is special.
            'leave'   => ['minutes' => $here === null ? 0 : xeric_travel_minutes($t, $here, null)],
        ],
        'mapped' => xeric_travel_mapped($t),
        // A skip is moving the clock right now, so every trip below would be
        // refused — said here so a client greys its buttons instead of offering
        // walks the engine will not take (decision 6).
        'skipping' => xeric_travel_skipping($db),
        'how'    => xeric_travel_how($t),
        'across' => xeric_travel_across($t),
        'places' => $places,
    ];
    if ($many) {
        $map['players'] = [];
        foreach ($roster as $id => $p) {
            $map['players'][] = ['id' => (int)$id, 'handle' => $p['handle'], 'name' => $p['name'],
                                 'where' => $p['where'],
                                 'place' => $p['where'] !== null ? xeric_world_place_name($t, $p['where']) : '',
                                 'since' => $p['since'], 'you' => (int)$id === $me];
        }
    }
    return $map;
}

/**
 * What the current player is called. In a world of one, the template's own
 * words exactly as the map has always printed them; with company, the
 * roster's name for whoever this is — a guest is not the owner's character,
 * and a map greeting them by somebody else's name would be the one-body
 * assumption leaking back out.
 */
function xeric_travel_you_name(array $t, PDO $db, ?int $me = null, ?array $roster = null): string
{
    $me ??= xeric_player_current(null, $db);
    $roster ??= xeric_travel_players($t, $db);
    if (count($roster) <= 1) return xeric_text($t['user']['name'] ?? '') ?: 'you';
    return (string)($roster[$me]['name'] ?? '') ?: 'you';
}
