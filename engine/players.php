<?php
/**
 * Xeric — who is at the centre. Groundwork, and deliberately not a feature.
 *
 * A xeric has always had exactly ONE person at the centre, and that has never
 * been written down anywhere — it is an assumption baked into a dozen places
 * that each look harmless on their own. `work.wages` is a single world-state
 * row. The poker seat is a single reserved key. A character's bare `trust` row
 * means "what they think of the person at the centre", singular, definite
 * article. None of those are wrong today and all of them would have to be
 * found and changed by hand the day a second person joined.
 *
 * So: THE PERSON AT THE CENTRE IS A ROW NOW. Nothing user-visible changes,
 * single-player behaves identically down to the bytes in the database, and
 * afterwards "a second person" is a schema question rather than a rewrite.
 *
 * ── THE BARE KEY BELONGS TO WHOEVER WAS THERE FIRST ───────────────────────
 *
 * The whole design rests on one rule, and it is the same rule engine/trust.php
 * already uses for `trust` versus `trust.of.<handle>`: the FIRST player keeps
 * the bare key, and everybody after them gets a suffix.
 *
 *     work.wages          the first person's purse — the row that already exists
 *     work.wages.p2       the second person's
 *     trust               what Ruth thinks of the first person
 *     trust.p2            what Ruth thinks of the second
 *     trust.of.harlan     what Ruth thinks of somebody in the town
 *
 * That is not tidiness. It means every world that exists right now is already
 * correct under the new scheme, with no migration, no rewrite pass, and no
 * moment where a half-migrated database is missing somebody's money. A
 * migration you do not have to run cannot go wrong at three in the morning.
 *
 * ── WHAT IS PER-PERSON AND WHAT IS NOT ────────────────────────────────────
 *
 * Per-person: what you own, what you owe, what somebody thinks of you, what
 * you have been promised. Those are facts about a relationship with a
 * particular human.
 *
 * NOT per-person: the clock, the weather, the town's mood, the pace dial, the
 * money dial, whether the Thursday game is on. Those are facts about the
 * WORLD, and two people standing in the same world at the same hour had
 * better agree about what time it is.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/state.php';

/** The first person at the centre. Their rows are the bare ones. */
const XERIC_PLAYER_FIRST = 1;

/** How many people one xeric will seat. A world, not a lobby. */
const XERIC_PLAYERS_MAX = 6;

/**
 * The suffix discipline, in one place so nobody has to remember it.
 *
 * Every per-person key in the engine goes through here. The first player gets
 * the bare key — which is the key that is already in every database on disk —
 * and everybody after them gets `.p<n>`.
 */
function xeric_player_key(string $base, int $player = XERIC_PLAYER_FIRST): string
{
    return $player <= XERIC_PLAYER_FIRST ? $base : $base . '.p' . $player;
}

/**
 * Everybody at the centre of this world, in the order they arrived.
 *
 * A world that has never heard of this returns the one implicit player the
 * template describes — which is every world that exists today, and is why
 * nothing had to be migrated.
 */
function xeric_players(PDO $db, array $t = []): array
{
    $raw = xeric_world_state_get($db, 'players');
    $rows = $raw === null ? [] : json_decode((string)$raw, true);

    if (!is_array($rows) || $rows === []) {
        return [XERIC_PLAYER_FIRST => [
            'id'       => XERIC_PLAYER_FIRST,
            'name'     => trim((string)($t['user']['name'] ?? '')) ?: 'you',
            'pronouns' => trim((string)($t['user']['pronouns'] ?? '')) ?: 'they/them',
            'implicit' => true,          // never written down; the template IS the record
        ]];
    }

    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $id = (int)($r['id'] ?? 0);
        if ($id < XERIC_PLAYER_FIRST) continue;
        $out[$id] = ['id' => $id,
                     'name' => trim((string)($r['name'] ?? '')) ?: 'somebody',
                     'pronouns' => trim((string)($r['pronouns'] ?? '')) ?: 'they/them',
                     'implicit' => false];
    }
    ksort($out);
    return $out === [] ? xeric_players($db) : $out;
}

/** One of them, or null. */
function xeric_player(PDO $db, int $id, array $t = []): ?array
{
    return xeric_players($db, $t)[$id] ?? null;
}

/** What to call somebody, for a prompt or a page. */
function xeric_player_name(PDO $db, int $id, array $t = []): string
{
    return (string)(xeric_player($db, $id, $t)['name'] ?? 'you');
}

/**
 * A second person joins. Returns their id.
 *
 * WRITING THE ROSTER FOR THE FIRST TIME WRITES THE FIRST PERSON INTO IT, from
 * the template, before anybody else is added. Until that moment the template
 * IS the record and the roster row would be a duplicate of it; from that
 * moment on there is more than one of them and the file cannot say which is
 * which, so it has to be written down. Doing it here rather than at seed time
 * is what keeps every existing world byte-identical until somebody actually
 * invites a friend.
 */
function xeric_player_add(PDO $db, array $t, string $name, string $pronouns = '',
                          ?int $at = null): int
{
    $name = trim($name);
    if ($name === '') throw new RuntimeException('players: somebody joining needs a name');

    $now = xeric_players($db, $t);
    if (($now[XERIC_PLAYER_FIRST]['implicit'] ?? false) === true) {
        $now[XERIC_PLAYER_FIRST]['implicit'] = false;    // the template, made explicit
    }
    if (count($now) >= XERIC_PLAYERS_MAX) {
        throw new RuntimeException('players: a xeric seats ' . XERIC_PLAYERS_MAX
            . ' at the centre — past that it is a lobby, not a world');
    }

    $id = max(array_keys($now)) + 1;
    $now[$id] = ['id' => $id, 'name' => $name,
                 'pronouns' => trim($pronouns) ?: 'they/them', 'implicit' => false];

    xeric_world_state_set($db, 'players', json_encode(array_values($now), JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
    return $id;
}

/**
 * Somebody leaves, and their rows STAY.
 *
 * Removing them from the roster is not the same as deleting them from the
 * world, and this engine should never do the second: Ruth's opinion of
 * somebody who stopped coming round is a real thing she still holds, and a
 * promise they broke is still broken. So the row goes and the history does
 * not, and if they come back their id is theirs again.
 */
function xeric_player_drop(PDO $db, array $t, int $id, ?int $at = null): bool
{
    if ($id === XERIC_PLAYER_FIRST) return false;      // the world is theirs
    $now = xeric_players($db, $t);
    if (!isset($now[$id])) return false;
    unset($now[$id]);
    xeric_world_state_set($db, 'players', json_encode(array_values($now), JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
    return true;
}
