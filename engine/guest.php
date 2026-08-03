<?php
/**
 * Xeric — somebody else came. How a town takes a person who is not in it.
 *
 * A second person at the centre is not in the cast, not in anybody's memory,
 * not in a single knowledge wall, and has no history with a soul in the place.
 * The engine's habit everywhere else is to fail closed, and failing closed here
 * would mean every character treating a friend who just walked in as a hole in
 * the room. That is correct for a secret and wrong for a person.
 *
 * ── THEY ARE NOT A STRANGER. THEY ARE SOMEBODY'S FRIEND ───────────────────
 *
 * This is the whole idea and it comes from how they actually got here: they
 * scanned a code somebody showed them, or followed a link somebody sent. They
 * came through a particular person's door. Nobody arrives at a xeric off the
 * street, so the town should not treat them as though they did.
 *
 * WHICH MEANS THEY ARRIVE WITH BORROWED STANDING. Ruth is warm to Neil, so
 * Ruth is POLITE to Neil's friend — not warm, polite, and only a fraction of
 * it, and it is marked as borrowed rather than earned. That is how vouching
 * works everywhere outside software: you get somebody's benefit of the doubt
 * before you have done anything to deserve it, and it is thinner than the real
 * thing.
 *
 * AND IT IS SPENDABLE, IN BOTH DIRECTIONS. A guest who behaves badly costs the
 * person who brought them, because that is also how it works: Ruth thinks a
 * little less of NEIL for the friend he turned up with. That single rule is
 * what makes a guest a social fact rather than a second login, and it is the
 * only place in this engine where one person's behaviour moves another
 * person's standing.
 *
 * ── HOW THE TOWN ACCOUNTS FOR THEM ────────────────────────────────────────
 *
 *   guest       accepted, no history, and everybody knows whose friend they
 *               are. The default, and the one that needs no model call.
 *   stranger    the town does not know them and is not minded to. Nothing is
 *               borrowed. For a world where that is the story.
 *   written_in  LITERARY MODE: the world is asked, once, who this person is to
 *               it — a cousin, a locum, somebody's army friend passing through
 *               — and from then on that is simply true. One model call, stored,
 *               never re-rolled, because a person whose place in the world
 *               changes every session is not a person.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/players.php';
require_once __DIR__ . '/trust.php';
require_once __DIR__ . '/chat.php';

/** How they stand with the town. */
const XERIC_GUEST_WAYS = ['guest', 'stranger', 'written_in'];

/**
 * How much of somebody's standing a guest arrives holding.
 *
 * A third, floored at nothing and capped low: being vouched for gets you in the
 * door and past nobody's guard. The far end of trust is for things that cost
 * something, and arriving is not one of them.
 */
const XERIC_GUEST_SHARE = 3;      // divisor
const XERIC_GUEST_CAP   = 2;      // and no more than this, however loved they are

/** What a guest's rudeness costs the person who brought them. */
const XERIC_GUEST_SPLASH = 1;

/** Everything known about how somebody arrived, or null for the first person. */
function xeric_guest(PDO $db, int $player): ?array
{
    if ($player <= XERIC_PLAYER_FIRST) return null;
    $raw = xeric_world_state_get($db, 'guest.p' . $player);
    // An empty string is somebody who was shown out: the row is cleared rather
    // than deleted, because world_state has no delete and a blank is the same
    // answer as never-having-arrived.
    $row = ($raw === null || trim((string)$raw) === '') ? null : json_decode((string)$raw, true);
    if (!is_array($row)) return null;
    return ['via'     => (int)($row['via'] ?? XERIC_PLAYER_FIRST),
            'way'     => in_array((string)($row['way'] ?? ''), XERIC_GUEST_WAYS, true)
                            ? (string)$row['way'] : 'guest',
            'as'      => trim((string)($row['as'] ?? '')),
            'vouched' => (bool)($row['vouched'] ?? false)];
}

/**
 * Somebody arrives, through a particular person's door.
 *
 * The `via` is not decoration and not a default worth guessing at: it is the
 * whole reason they are not a stranger, and every borrowed point below is
 * borrowed from THEM specifically.
 */
function xeric_guest_arrive(PDO $db, array $t, int $player, int $via = XERIC_PLAYER_FIRST,
                            string $way = 'guest', ?int $at = null): array
{
    if ($player <= XERIC_PLAYER_FIRST) {
        throw new RuntimeException('guest: the first person did not arrive, the world is theirs');
    }
    if (xeric_player($db, $player, $t) === null) {
        throw new RuntimeException('guest: nobody by that number is here');
    }
    $way = in_array($way, XERIC_GUEST_WAYS, true) ? $way : 'guest';
    $row = ['via' => max(XERIC_PLAYER_FIRST, $via), 'way' => $way, 'as' => '', 'vouched' => false];
    xeric_world_state_set($db, 'guest.p' . $player, json_encode($row, JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
    return $row;
}

/**
 * BORROWED STANDING, handed over once when they walk in.
 *
 * Every character who thinks anything of the person who brought them starts
 * thinking a thinner version of it about the guest. A stranger borrows nothing;
 * a guest borrows a third, capped; somebody the town dislikes lends their
 * dislike too, which is the honest half of vouching that nobody enjoys.
 *
 * Idempotent: the row remembers it has been done, because a guest who leaves
 * and comes back twice should not accumulate their friend's reputation.
 *
 * @return int how many people it moved
 */
function xeric_guest_vouch(PDO $db, array $t, int $player, ?int $at = null): int
{
    $g = xeric_guest($db, $player);
    if ($g === null || $g['vouched'] || $g['way'] === 'stranger') return 0;

    $n = 0;
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $theirs = xeric_trust_of($db, $h, null, $g['via']);
        if ($theirs === 0) continue;
        $lent = (int)($theirs / XERIC_GUEST_SHARE);          // toward zero, both signs
        $lent = max(-XERIC_GUEST_CAP, min(XERIC_GUEST_CAP, $lent));
        if ($lent === 0) continue;
        xeric_trust_earn($db, $h, $lent, $at, null, $player);
        $n++;
    }

    $g['vouched'] = true;
    xeric_world_state_set($db, 'guest.p' . $player, json_encode($g, JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
    return $n;
}

/**
 * A guest behaves badly, and it lands on the person who brought them.
 *
 * The only place in this engine where one person's behaviour moves another
 * person's standing, and it is deliberate: that is exactly what bringing
 * somebody means. Small, because it should be a wince rather than a rupture —
 * and warmth rather than trust, so a bad evening costs a fraction and a pattern
 * costs a point.
 */
function xeric_guest_splash(PDO $db, array $t, int $player, string $handle,
                            int $delta = -XERIC_GUEST_SPLASH, ?int $at = null): void
{
    $g = xeric_guest($db, $player);
    if ($g === null || $handle === '' || $delta === 0) return;
    xeric_trust_contact($db, $handle, $delta, $at, $player);          // to them
    xeric_trust_contact($db, $handle, $delta, $at, $g['via']);        // and to whoever vouched
}

/**
 * LITERARY MODE: who is this person to this world?
 *
 * One model call, made once, stored forever. A cousin nobody has seen since the
 * funeral, the locum covering two weeks, somebody's army friend passing
 * through — a place in the world rather than a hole in it, invented ONCE
 * because a person whose place changes every session is not a person.
 *
 * The model is given the town and told plainly it is not writing a character
 * sheet: this person is real and is standing in the room, and the question is
 * only what the town would take them for.
 */
function xeric_guest_write_in(PDO $db, array $t, int $player, array $endpoint,
                              array $opts = [], ?int $at = null): string
{
    $g = xeric_guest($db, $player);
    if ($g === null) return '';
    if ($g['as'] !== '') return $g['as'];                     // never re-rolled

    $name = xeric_player_name($db, $player, $t);
    $via  = xeric_player_name($db, $g['via'], $t);
    $cast = [];
    foreach (array_slice((array)($t['cast']['characters'] ?? []), 0, 8) as $c) {
        $cast[] = '  ' . (string)($c['display_name'] ?? $c['handle'] ?? '')
                . (($c['one_line'] ?? '') !== '' ? ' — ' . (string)$c['one_line'] : '');
    }

    try {
        $raw = xeric_chat_json($endpoint, 'guest', [
            ['role' => 'system', 'content' =>
                'You place one real person in a world that already exists. You are not writing a '
                . 'character: they are a person who is here, and the only question is what this '
                . 'town takes them for. Reply with ONE JSON object and nothing else.'],
            ['role' => 'user', 'content' =>
                'THE PLACE: ' . (string)($t['meta']['name'] ?? 'this town') . ' — '
                . (string)($t['setting']['locale'] ?? '') . "\n\n"
                . "SOME OF THE PEOPLE:\n" . implode("\n", $cast) . "\n\n"
                . 'WHO ARRIVED: ' . $name . ', who came with ' . $via . ".\n\n"
                . "Give them a place in this world. A cousin, a locum, somebody's army friend\n"
                . "passing through, the new hire nobody has met. Ordinary and specific — not a\n"
                . "mystery, not a plot, not somebody with a secret. It has to survive being true\n"
                . "for months.\n\n"
                . "WRITE ONE JSON OBJECT\n"
                . '{ "as": "one sentence, under 20 words, third person, naming them" }'],
        ], ['temperature' => 0.9, 'timeout' => (int)($opts['timeout'] ?? 90)] + $opts);
    } catch (Throwable $e) {
        return '';                     // a guest with no story is still a guest
    }

    $as = trim(preg_replace('/\s+/u', ' ', (string)($raw['as'] ?? '')) ?? '');
    if ($as === '') return '';
    $as = mb_substr($as, 0, 200);

    $g['as'] = $as;
    xeric_world_state_set($db, 'guest.p' . $player, json_encode($g, JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
    return $as;
}

/**
 * WHO ELSE IS HERE, in a character's own prompt.
 *
 * Silent in every single-player world, which is every world until somebody is
 * actually invited — one world_state read and out. When there IS somebody, this
 * is the difference between a character talking to a person and a character
 * talking past a hole in the room.
 *
 * Coarse and clockless like every other block: who they are, whose friend they
 * are, and how the town is taking them. No trust number, no arrival time, no
 * count of anything, so it is byte-stable until somebody's standing actually
 * changes.
 */
function xeric_guest_block(PDO $db, array $t, int $speakerIsFor = XERIC_PLAYER_FIRST): string
{
    $lines = [];
    foreach (xeric_players($db, $t) as $id => $p) {
        if ($id === $speakerIsFor) continue;
        $g = xeric_guest($db, $id);
        if ($g === null) {
            // Another person at the centre with no arrival row: the world's own,
            // as much as the first one is.
            $lines[] = '- ' . $p['name'] . ' is here too, and belongs here as much as anybody.';
            continue;
        }
        $via = xeric_player_name($db, $g['via'], $t);
        if ($g['as'] !== '') {
            $lines[] = '- ' . $g['as'] . ' They came with ' . $via . '.';
        } elseif ($g['way'] === 'stranger') {
            $lines[] = '- ' . $p['name'] . ' is here and you do not know them. Nobody vouched for them.';
        } else {
            $lines[] = '- ' . $p['name'] . ' is here with ' . $via . '. You do not know them, but '
                     . $via . ' brought them, so they are not a stranger.';
        }
    }
    if ($lines === []) return '';
    return "SOMEBODY ELSE IS HERE\n" . implode("\n", $lines)
         . "\n- Talk to them like a person who is standing in front of you. Do not explain the town "
         . "to them unless they ask, and do not pretend to remember them.";
}
