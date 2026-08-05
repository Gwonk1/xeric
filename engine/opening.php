<?php
/**
 * Xeric — the opening. The first page of the book, and the moment it starts on.
 *
 * A world used to begin wherever the wall clock happened to be standing when its
 * owner first opened it — which, in practice, was a town asleep at one in the
 * morning and a player alone in the dark with a roster of strangers. The owner's
 * ruling (2026-08-04): you are not dropped into a world to figure EVERYTHING
 * out. You get a setup — who you are, where you live, what has recently
 * happened, what you want — and the world's first moment is one where SOMETHING
 * is happening. A book does not open on the protagonist asleep.
 *
 * Three pieces, and who owns them:
 *
 *  1. THE PROSE lives in the template, at `opening.prose` — second person, a
 *     page, written by the forge's model when there is one and by
 *     xeric_opening_default() when there is not. play.php shows it once per
 *     player, as a page before the play UI; book.php prints it as front matter.
 *     It is COMMONS TEXT: built from what the town already says out loud (the
 *     roster lines, the rumor, the seeded past) and never from walls, drives or
 *     secrets — the same starvation discipline as the back-cover teaser.
 *
 *  2. THE SCENE lives at `opening.scene` — a place key, a day-of-week and an
 *     hh:mm, RELATIVE like everything else the forge writes (seed.php,
 *     discipline 1: the forge has no idea when the world will be launched).
 *     Launch resolves it to a concrete `setting.starts`, which clock.php
 *     already knows how to begin a world on. The scene is picked where the
 *     week's own schedules put the most people in one public room, so "when
 *     something is happening" is a fact about the world, not a wish about it.
 *
 *  3. THE APPLY writes the scene into the record exactly once, at first open —
 *     an event stamped at the world's first moment, so the cast can talk about
 *     the evening they are all standing in, and the player is placed at the
 *     door of it. Idempotent by marker, like the seed; guarded by the same age
 *     floor, because it lands in the permanent record like the seed.
 *
 * Zero model calls in this file. The model half lives in forge/forge.php
 * (xeric_forge_pass_opening); everything here must work on a dead battery.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/players.php';

/** The world_state key that says this world's opening scene is in the record. */
const XERIC_OPENING_MARKER = 'opening_applied';

/** The fallback curtain-up: Friday evening, when a week says nothing louder. */
const XERIC_OPENING_HHMM = '19:00';

/**
 * The template's opening, normalised, or null when it has none worth showing.
 *
 * Prose is the admission ticket: an opening with no prose is no opening, while
 * a scene that names a place the template no longer has (a reroll can do that)
 * degrades to prose-only rather than to a modal about a ghost room.
 */
function xeric_opening(array $t): ?array
{
    $o = $t['opening'] ?? null;
    if (!is_array($o)) return null;
    $prose = trim((string)($o['prose'] ?? ''));
    if ($prose === '') return null;

    $scene = null;
    $s = $o['scene'] ?? null;
    if (is_array($s)) {
        $place = (string)($s['place'] ?? '');
        $known = false;
        foreach ((array)($t['places'] ?? []) as $p) {
            if ((string)($p['key'] ?? '') === $place) { $known = true; break; }
        }
        if ($place !== '' && $known) {
            $dow  = $s['dow'] ?? null;
            $dow  = (is_numeric($dow) && (int)$dow >= 0 && (int)$dow <= 6) ? (int)$dow : null;
            $hhmm = (string)($s['hhmm'] ?? '');
            if (xeric_world_minutes($hhmm) === null) $hhmm = XERIC_OPENING_HHMM;
            $who = [];
            foreach ((array)($s['who'] ?? []) as $h) {
                $h = (string)$h;
                if ($h !== '' && xeric_world_character($t, $h) !== null && !in_array($h, $who, true)) $who[] = $h;
            }
            $scene = ['place' => $place, 'dow' => $dow, 'hhmm' => $hhmm,
                      'line' => trim((string)($s['line'] ?? '')), 'who' => $who];
        }
    }
    return ['prose' => $prose, 'scene' => $scene];
}

/**
 * Where and when this world is fullest — the curtain-up the schedules already
 * wrote. Scans the next seven days from $fromEpoch, at the hours a town is
 * plausibly out (evening first, midday second), counts the cast standing in
 * each PUBLIC room via xeric_world_who_is_where(), and takes the fullest,
 * earliest one. Homes are excluded twice over: as candidate rooms (an opening
 * scene in somebody else's kitchen is a break-in) and from the count.
 *
 * Never returns null for a world with a public place: a world whose week puts
 * nobody anywhere still opens SOMEWHERE, at the fallback hour, because the
 * alternative is the 1 a.m. arrival this file exists to end.
 */
function xeric_opening_scene_pick(array $t, ?int $fromEpoch = null): ?array
{
    $public = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $k = (string)($p['key'] ?? '');
        if ($k !== '' && (string)($p['kind'] ?? '') !== 'home') $public[] = $k;
    }
    if ($public === []) return null;

    // The circle's hangout is the tiebreak, not the answer: it is where this
    // town SAYS it gathers, which beats alphabetical order and loses to a count.
    $hangout = '';
    foreach ((array)($t['cast']['circles'] ?? []) as $c) {
        $hp = (string)($c['hangout_place'] ?? '');
        if ($hp !== '' && in_array($hp, $public, true)) { $hangout = $hp; break; }
    }

    $from = $fromEpoch ?? time();
    $best = null;      // [count, dayIndex, hourIndex, placeKey, dow, hhmm, who]
    foreach (range(0, 6) as $d) {
        foreach ([XERIC_OPENING_HHMM, '12:30', '17:00', '10:00'] as $hi => $hhmm) {
            $now = xeric_world_now($t, $from + $d * 86400
                + xeric_opening_seconds_to($t, $from + $d * 86400, $hhmm));
            $counts = [];
            foreach (xeric_world_who_is_where($t, $now) as $row) {
                $wk = (string)($row['where'] ?? '');
                if ($wk === '' || !in_array($wk, $public, true)) continue;
                $counts[$wk][] = (string)$row['handle'];
            }
            foreach ($counts as $pk => $who) {
                $n = count($who) + (($pk === $hangout) ? 0.5 : 0);
                if ($best === null || $n > $best[0]) {
                    $best = [$n, $d, $hi, $pk, (int)$now['dow'], $hhmm, $who];
                }
            }
        }
    }

    if ($best === null) {
        // A week that puts nobody anywhere public. Curtain up at the hangout,
        // or the first public room, tomorrow evening.
        $pk  = $hangout !== '' ? $hangout : $public[0];
        $now = xeric_world_now($t, $from + 86400);
        $best = [0, 1, 0, $pk, ((int)$now['dow']) % 7, XERIC_OPENING_HHMM, []];
    }

    [, , , $place, $dow, $hhmm, $who] = $best;
    return ['place' => $place, 'dow' => $dow, 'hhmm' => $hhmm,
            'line' => xeric_opening_scene_line($t, $place, $who), 'who' => array_slice($who, 0, 4)];
}

/** Seconds from $epoch to the next time the world's own clock reads $hhmm. */
function xeric_opening_seconds_to(array $t, int $epoch, string $hhmm): int
{
    $now  = xeric_world_now($t, $epoch);
    $want = xeric_world_minutes($hhmm) ?? 0;
    $at   = xeric_world_minutes((string)$now['hhmm']) ?? 0;
    $d    = ($want - $at) * 60;
    return $d >= 0 ? $d : $d + 86400;
}

/** One sentence of curtain-up, from who the schedules actually put in the room. */
function xeric_opening_scene_line(array $t, string $place, array $who): string
{
    $pn = xeric_world_place_name($t, $place);
    $names = [];
    foreach (array_slice($who, 0, 2) as $h) {
        $c = xeric_world_character($t, $h);
        if ($c !== null) $names[] = (string)($c['display_name'] ?? $h);
    }
    if ($names === []) {
        return "The lights are on at $pn, and the evening is just finding its feet.";
    }
    $list = implode(' and ', $names);
    $verb = count($names) > 1 ? 'are' : 'is';
    return "$list $verb already at $pn when you arrive"
        . (count($who) > count($names) ? ', and they are not the only ones' : '')
        . '.';
}

/**
 * The hand-written first page — what a dead battery still opens on.
 *
 * "You are Judy, a ____. You live in ____. Recently, ____." — the owner's
 * shape, verbatim: who you are, where you live, what pulls you, what has
 * recently happened, who you see most days, and the scene this all starts on.
 * Commons only: roster lines, the rumor, the seeded past's titles. Flat next
 * to what a model writes, and that is fine; flat and true beats vivid and
 * absent.
 */
function xeric_opening_default(array $t, array $seed = [], ?int $fromEpoch = null): array
{
    $u    = (array)($t['user'] ?? []);
    $name = trim((string)($u['name'] ?? '')) ?: 'you';
    $out  = [];

    $line = "You are $name";
    $job  = trim((string)($u['occupation']['title'] ?? ''));
    if ($job !== '') {
        $line .= ', ' . (preg_match('/^[aeiou]/i', $job) ? 'an' : 'a') . " $job";
        $wp = xeric_world_place_name($t, (string)($u['occupation']['workplace_key'] ?? ''));
        if ($wp !== '') $line .= " at $wp";
    }
    $where = trim((string)($u['location'] ?? '')) ?: trim((string)($t['setting']['locale'] ?? ''));
    $out[] = $line . '.' . ($where !== '' ? " You live in $where." : '');

    $want = trim((string)($u['motivation'] ?? ''));
    if ($want !== '') $out[] = "What you want, more than you would say out loud, is $want.";
    $goals = [];
    foreach ((array)($u['goals'] ?? []) as $g) {
        $g = trim((string)(is_array($g) ? ($g['text'] ?? '') : $g));
        if ($g !== '') $goals[] = $g;
    }
    if ($goals !== []) $out[] = 'You mean to ' . implode('; and someday, ', array_slice($goals, 0, 2)) . '.';

    $recent = [];
    foreach (array_slice((array)($seed['events'] ?? []), 0, 2) as $e) {
        $s = trim((string)($e['title'] ?? ''));
        if ($s !== '') $recent[] = rtrim($s, '.');
    }
    if ($recent !== []) $out[] = 'Recently: ' . implode('. ', $recent) . '.';
    $rumor = trim((string)($t['mystery']['rumor'] ?? ''));
    if ($rumor !== '') $out[] = "And there is the talk. $rumor";

    $fc = xeric_world_character($t, (string)($t['cast']['first_contact'] ?? ''));
    if ($fc !== null) {
        $out[] = trim((string)($fc['display_name'] ?? '')) . ' is the one you see most days.';
    }

    $scene = xeric_opening_scene_pick($t, $fromEpoch);
    if ($scene !== null) {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $out[] = 'It begins on ' . ($days[$scene['dow'] ?? 0] ?? 'the day') . ' at '
            . xeric_world_place_name($t, $scene['place']) . '. ' . $scene['line'];
    }

    return ['prose' => implode("\n\n", $out), 'scene' => $scene];
}

/**
 * The scene as a concrete first moment: the next time the world's calendar
 * reads that day and that hour, measured from $fromEpoch in the user's own
 * timezone. Shaped for `setting.starts` — xeric_clock_starts() parses exactly
 * this — so launch writes one string and clock.php does the rest.
 */
function xeric_opening_starts(array $t, ?int $fromEpoch = null): ?string
{
    $o = xeric_opening($t);
    $scene = $o['scene'] ?? null;
    if ($scene === null) return null;

    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }

    $mins = xeric_world_minutes((string)$scene['hhmm']) ?? 0;
    $d = (new DateTimeImmutable('@' . ($fromEpoch ?? time())))->setTimezone($tz)
        ->setTime(intdiv($mins, 60), $mins % 60);
    // Forward to the named weekday — or just forward, when any day will do.
    // Never backward, and never "in twenty minutes": an opening the player is
    // already late for reads as a bug, so anything nearer than an hour out
    // rolls a week (or a day) on.
    $target = $scene['dow'];
    for ($i = 0; $i <= 8; $i++) {
        $ok = ($target === null || (int)$d->format('w') === $target)
            && $d->getTimestamp() >= ($fromEpoch ?? time()) + 3600;
        if ($ok) break;
        $d = $d->modify('+1 day');
    }
    return $d->format('Y-m-d H:i');
}

/**
 * Write the opening scene into the record, once, at the world's first moment.
 *
 * The event is stamped at $now — which, for a world launched through the door
 * above, IS the scene's own hour — so the sweeps, the feed and every prompt
 * agree that this is happening, not that it happened. The player is stood at
 * the door of it (bare key: at first open there is only player one, and later
 * players choose their own feet), unless they have already walked somewhere.
 *
 * Same floor as the seed, same fail-closed drop: prose that lands in the
 * permanent record passes the age floor or does not land.
 *
 * @return array{applied:bool,event:bool,placed:bool}
 */
function xeric_opening_apply(PDO $db, array $t, array $now): array
{
    if (xeric_world_state_get($db, XERIC_OPENING_MARKER) !== null) {
        return ['applied' => false, 'event' => false, 'placed' => false];
    }

    $o = xeric_opening($t);
    $scene = $o['scene'] ?? null;
    $event = false;
    $placed = false;

    if ($scene !== null) {
        $line = (string)$scene['line'];
        if ($line === '') $line = xeric_opening_scene_line($t, (string)$scene['place'], (array)$scene['who']);
        $who = (array)$scene['who'];
        if (xeric_age_floor($t, $who, [$line]) === null) {
            xeric_event_add($db, xeric_seed_headline($line), (int)$now['epoch'],
                (string)$scene['place'], $who, $line);
            $event = true;
        }
        $pkey = xeric_player_key('player.where', XERIC_PLAYER_FIRST);
        if (xeric_world_state_get($db, $pkey) === null) {
            xeric_world_state_set($db, $pkey, (string)$scene['place']);
            $placed = true;
        }
    }

    xeric_world_state_set($db, XERIC_OPENING_MARKER,
        json_encode(['event' => $event, 'placed' => $placed], JSON_UNESCAPED_SLASHES));
    return ['applied' => true, 'event' => $event, 'placed' => $placed];
}
