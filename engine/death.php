<?php
/**
 * death.php — dying, and whether it can be undone.
 *
 * DEATH IS A ROW, NEVER A DELETION. A dead character stays in
 * world-template.json, stays resolvable by handle, stays in every memory that
 * names them, every event they stood at, every wall they stood behind. What
 * changes is one row in this world's database saying they are gone. Delete them
 * from the template instead and you break every memory, event and wall that
 * points at them — and you throw away the only thing death is FOR, which is that
 * the rest of the cast goes on remembering somebody who is not there.
 *
 * THIS IS WHAT LETS A STORY KILL SOMEBODY YOU KNOW. docs/STORY.md declares a
 * murder victim as a phantom — a name, an age, a one-line, no dossier, nobody
 * anyone ever spoke to — and says exactly why: killing a cast member "would mean
 * deleting somebody from world-template.json when the story is injected and
 * putting them back when it closes, which is the one thing an overlay may not
 * do." That reasoning was right, and it stops applying the moment death is state
 * instead of an edit. An overlay can now kill a character without touching the
 * template at all, which is the rule STORY.md was protecting, kept.
 *
 * REVIVAL IS NOT A REWIND. Bringing somebody back leaves the history exactly
 * where it was: the death still happened, the events still happened, and every
 * person who has a memory of it still has it. People who watched somebody die
 * and then see them at the diner is not a bug to paper over — it is the best
 * scene this engine can produce, and it is the holodeck question asked properly.
 * xeric_clock_reset() already refuses arbitrary rewinds and says why; nothing
 * here reopens that.
 *
 * WHICH WAY THIS FAILS, AND WHY IT IS THE OPPOSITE OF EVERYWHERE ELSE. An
 * unreadable `deaths.mode` resolves to REVIVABLE — the recoverable state — where
 * an unreadable age resolves to "minor" and an unresolvable viewer resolves to
 * "protect more". The difference is who the rule is for. The age floor protects
 * a THIRD PARTY, so doubt has to fall on the side of protection. Permadeath is a
 * constraint an author imposes on themselves, and failing closed into somebody
 * else's self-imposed constraint — deleting a world's cast for good over a typo
 * in a settings key — is not caution. It is damage.
 *
 * AND IT IS NOT DRM. The world is a JSON file and a SQLite database its owner
 * owns. Anybody with a text editor can resurrect anybody, forever, and the docs
 * say so. What `permanent` means is that THE ENGINE WILL NOT DO IT — no button,
 * no command, no endpoint. That is a real thing and it is enough, because the
 * stakes were never enforced by the software. They were enforced by the author
 * having decided.
 *
 * KIDS DIE. The age floor is about sexual content and has nothing whatever to
 * say about mortality. A guard here that spared a child from a story that killed
 * him would be the same wrong rule the punchlist has already had to write down
 * twice.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';

/** Death happens and can be undone by the engine. The default, and the fallback. */
const XERIC_DEATH_REVIVABLE = 'revivable';

/** Death happens and the engine will not undo it. Frozen at the first death. */
const XERIC_DEATH_PERMANENT = 'permanent';

/** How many of the dead the prompt will name before it stops counting them out. */
const XERIC_DEATH_NAMED = 6;

// ---------------------------------------------------------------------------
// The setting, and when it stops being a setting
// ---------------------------------------------------------------------------

/**
 * Whether this world's deaths can be undone.
 *
 * THE SNAPSHOT IS THE LOCK. Before anybody has died the answer comes from the
 * template, where an author can change it as freely as any other line. The first
 * death copies it into the world's own database and from then on the database is
 * the only thing read. That implements "editable until it has cost somebody,
 * then frozen" without a single UI having to cooperate, it survives the template
 * being edited afterwards, and it travels with a fork because a fork copies the
 * database.
 *
 * Anything that is not exactly `permanent` is revivable. See the header: this is
 * the one dial in the engine that resolves doubt toward the recoverable state.
 */
function xeric_death_mode(array $t, PDO $db): string
{
    $frozen = trim((string)(xeric_world_state_get($db, 'deaths.mode') ?? ''));
    if ($frozen !== '') return $frozen === XERIC_DEATH_PERMANENT ? XERIC_DEATH_PERMANENT : XERIC_DEATH_REVIVABLE;

    return xeric_death_mode_of($t);
}

/** What the TEMPLATE asks for, before any death has frozen it. */
function xeric_death_mode_of(array $t): string
{
    $m = strtolower(trim((string)($t['deaths']['mode'] ?? '')));
    return $m === XERIC_DEATH_PERMANENT ? XERIC_DEATH_PERMANENT : XERIC_DEATH_REVIVABLE;
}

/** Has the mode been frozen — i.e. has this world lost anybody yet? */
function xeric_death_locked(PDO $db): bool
{
    return trim((string)(xeric_world_state_get($db, 'deaths.mode') ?? '')) !== '';
}

/** Does the engine refuse to bring people back here? */
function xeric_death_permanent(array $t, PDO $db): bool
{
    return xeric_death_mode($t, $db) === XERIC_DEATH_PERMANENT;
}

// ---------------------------------------------------------------------------
// Who is gone
// ---------------------------------------------------------------------------

/**
 * Everybody this world has lost, oldest first.
 *
 * Not filtered against the template. A handle whose character was rerolled away
 * is still a death that happened, and silently dropping it would let a reroll
 * quietly resurrect somebody. Callers that need a living cast intersect against
 * the template themselves; callers that need the record get the record.
 *
 * @return array<string,array{handle:string,world_epoch:int,how:string,by:?string}>
 */
function xeric_deaths(PDO $db): array
{
    $rows = $db->query('SELECT handle, world_epoch, how, by_handle FROM deaths ORDER BY world_epoch, handle')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['handle']] = [
            'handle'      => (string)$r['handle'],
            'world_epoch' => (int)$r['world_epoch'],
            'how'         => (string)($r['how'] ?? ''),
            'by'          => ($r['by_handle'] ?? null) !== null ? (string)$r['by_handle'] : null,
        ];
    }
    return $out;
}

/** Just the handles, for the presence and sweep readers that only need a set. */
function xeric_dead_handles(PDO $db): array
{
    return array_keys(xeric_deaths($db));
}

function xeric_is_dead(PDO $db, string $handle): bool
{
    if ($handle === '') return false;
    $q = $db->prepare('SELECT 1 FROM deaths WHERE handle = ?');
    $q->execute([$handle]);
    $hit = (bool)$q->fetchColumn();
    $q->closeCursor();                 // WAL: an unclosed cursor pins the snapshot
    return $hit;
}

/** How many of the cast are still alive. Zero is a real answer and means the world ended. */
function xeric_death_living(array $t, PDO $db): array
{
    $dead = xeric_deaths($db);
    $out  = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '' && !isset($dead[$h])) $out[] = $h;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Dying
// ---------------------------------------------------------------------------

/**
 * Somebody dies.
 *
 * `permanent` does NOT gate this — it gates coming back. A world where death is
 * permanent is not a world where death is rarer.
 *
 * $how is COMMONS text: it is printed to anybody who can see the cast, so it
 * carries what the town would say ("the truck on the river road"), never what
 * only the narrator knows. A wall cannot protect a sentence that is written into
 * the roster.
 *
 * @param ?string $by a handle, when somebody did it. Recorded, never rendered by
 *                    this file — who killed whom is exactly the kind of fact a
 *                    story overlay exists to hand out one beat at a time.
 * @return array{ok:bool,error:?string,handle:string,mode:string,first:bool}
 */
function xeric_death_kill(array $t, PDO $db, string $handle, int $worldEpoch,
                          string $how = '', ?string $by = null, bool $notice = true): array
{
    $handle = trim($handle);
    $no = fn(string $why): array => ['ok' => false, 'error' => $why, 'handle' => $handle,
                                     'mode' => xeric_death_mode($t, $db), 'first' => false];

    if ($handle === '' || xeric_world_character($t, $handle) === null) {
        return $no('Nobody in this world answers to that name.');
    }
    if (xeric_is_dead($db, $handle)) {
        return $no(xeric_world_name($t, $handle) . ' is already dead.');
    }

    // The freeze, before the row, so a crash between them leaves a world whose
    // rules are settled rather than one that lost somebody under no rules at all.
    $first = !xeric_death_locked($db);
    if ($first) xeric_world_state_set($db, 'deaths.mode', xeric_death_mode_of($t));

    $q = $db->prepare('INSERT INTO deaths (handle, world_epoch, how, by_handle, created_at) VALUES (?,?,?,?,?)');
    $q->execute([$handle, $worldEpoch, xeric_text($how), ($by !== null && trim($by) !== '') ? trim($by) : null,
                 xeric_state_time()]);

    if ($notice) xeric_death_notice($t, $db, [$handle], $worldEpoch, $how);

    return ['ok' => true, 'error' => null, 'handle' => $handle,
            'mode' => xeric_death_mode($t, $db), 'first' => $first];
}

/**
 * The world notices. An hour in the feed, and a memory in everybody left.
 *
 * A death that only changed a flag would be a world where somebody stops
 * answering and nobody mentions it — the flag would be right and the town would
 * be wrong. The event puts it in the history beside every other hour; the
 * memories put it where the prompt actually reads from, which is the difference
 * between the cast KNOWING and the cast being told once at the top of a message.
 *
 * These memories are FACTS, not experiences, and they read like it — one line,
 * what happened, in the town's words. sweeps.php writes divergent memories
 * because it has a model to write them with; this has none and must not
 * pretend. When a sweep or a story kills somebody, the memories that hour writes
 * are the real ones and this only records the fact underneath them.
 *
 * @param string[] $handles everybody who died in this one thing
 */
function xeric_death_notice(array $t, PDO $db, array $handles, int $worldEpoch, string $how = ''): void
{
    $names = [];
    foreach ($handles as $h) {
        if (xeric_world_character($t, (string)$h) !== null) $names[] = xeric_world_name($t, (string)$h);
    }
    if ($names === []) return;

    $how   = xeric_text($how);
    $who   = xeric_join_list(array_slice($names, 0, XERIC_DEATH_NAMED))
           . (count($names) > XERIC_DEATH_NAMED ? ', and ' . (count($names) - XERIC_DEATH_NAMED) . ' more' : '');
    $title = count($names) === 1 ? $names[0] . ' died' : 'the day ' . $who . ' died';
    $line  = $who . (count($names) === 1 ? ' died' : ' died') . ($how !== '' ? ', ' . $how : '') . '.';

    try {
        xeric_event_add($db, $title, $worldEpoch, null, array_values(array_map('strval', $handles)), $line);
        // Everybody still standing. Not the dead: a memory is what somebody
        // carries forward, and they are not carrying anything forward.
        foreach (xeric_death_living($t, $db) as $h) {
            xeric_memory_add($db, $h, $line, 'event', ['kind' => 'death'], $worldEpoch);
        }
    } catch (Throwable $e) {
        // The death happened. Whether the town wrote it down is our problem.
    }
}

// ---------------------------------------------------------------------------
// Places that go with them
// ---------------------------------------------------------------------------

/**
 * Rooms the world has lost. Shut, not deleted — same rule as people.
 *
 * A bomb that emptied the diner and left it open for business would be a world
 * that lost its cast and kept its opening hours. Kept in world_state rather than
 * in a table because unlike a death it carries nothing: a dark place is a set of
 * keys and a place that is lit is simply not in it.
 */
function xeric_dark_places(PDO $db): array
{
    $raw = (string)(xeric_world_state_get($db, 'places.dark') ?? '');
    if ($raw === '') return [];
    $out = json_decode($raw, true);
    return is_array($out) ? array_values(array_unique(array_map('strval', $out))) : [];
}

function xeric_dark_set(PDO $db, array $keys): void
{
    $keys = array_values(array_unique(array_map('strval', $keys)));
    sort($keys);                          // stable, so two identical worlds hash alike
    xeric_world_state_set($db, 'places.dark', json_encode($keys));
}

function xeric_is_dark(PDO $db, string $key): bool
{
    return $key !== '' && in_array($key, xeric_dark_places($db), true);
}

/**
 * Somebody comes back. The history does not.
 *
 * Nothing is deleted, unwound or apologised for: the death is removed from the
 * ledger of who is currently gone, and every memory, event and message about it
 * stays exactly where it was. That is the feature, not a shortcut around one.
 *
 * @return array{ok:bool,error:?string,handle:string,mode:string}
 */
function xeric_death_revive(array $t, PDO $db, string $handle): array
{
    $handle = trim($handle);
    $mode   = xeric_death_mode($t, $db);
    $no = fn(string $why): array => ['ok' => false, 'error' => $why, 'handle' => $handle, 'mode' => $mode];

    if ($mode === XERIC_DEATH_PERMANENT) {
        // Named, and said as a rule of this world rather than as a fault. The
        // person reading it chose this, possibly weeks ago, and deserves to be
        // told which decision is speaking.
        return $no('Death is permanent in this world. '
            . ($handle !== '' ? xeric_world_name($t, $handle) : 'They') . ' is not coming back.');
    }
    if (!xeric_is_dead($db, $handle)) {
        return $no(($handle !== '' ? xeric_world_name($t, $handle) : 'They') . ' is not dead.');
    }

    $q = $db->prepare('DELETE FROM deaths WHERE handle = ?');
    $q->execute([$handle]);

    return ['ok' => true, 'error' => null, 'handle' => $handle, 'mode' => $mode];
}

/**
 * Everybody at once. The bomb goes off.
 *
 * One `how` across the whole cast, because a catastrophe is ONE thing that
 * happened rather than a coincidence of separate deaths, and the roster should
 * read like it. Already-dead people are left alone rather than re-killed: their
 * death has its own hour and its own sentence, and overwriting it would rewrite
 * history the rest of the world remembers.
 *
 * @return array{ok:bool,killed:string[],mode:string}
 */
function xeric_death_catastrophe(array $t, PDO $db, int $worldEpoch, string $how = ''): array
{
    $killed = [];
    // `notice: false` on every one of them, and ONE notice at the end. Seven
    // separate "X died" hours in the feed would tell the story of seven
    // coincidences on the same afternoon, which is not what happened.
    foreach (xeric_death_living($t, $db) as $h) {
        $r = xeric_death_kill($t, $db, $h, $worldEpoch, $how, null, false);
        if ($r['ok']) $killed[] = $h;
    }
    if ($killed !== []) xeric_death_notice($t, $db, $killed, $worldEpoch, $how);

    // And the lights. A place is only shut by this when the world ENDED —
    // somebody dying does not close the diner, everybody dying does.
    if (xeric_death_living($t, $db) === []) {
        $keys = [];
        foreach ((array)($t['places'] ?? []) as $p) {
            $k = (string)($p['key'] ?? '');
            if ($k !== '') $keys[] = $k;
        }
        xeric_dark_set($db, $keys);
    }

    return ['ok' => true, 'killed' => $killed, 'mode' => xeric_death_mode($t, $db)];
}

/**
 * Bring the world back. Everyone remembers.
 *
 * Refuses ALL OR NOTHING under `permanent` rather than reviving whoever it can:
 * a world that came back with a hole in it, because the engine got halfway
 * through a rule it should not have started, is worse than a world that stayed
 * ended.
 *
 * @return array{ok:bool,error:?string,revived:string[],mode:string}
 */
function xeric_death_restore(array $t, PDO $db): array
{
    $mode = xeric_death_mode($t, $db);
    if ($mode === XERIC_DEATH_PERMANENT) {
        return ['ok' => false, 'error' => 'Death is permanent in this world. What happened here stands.',
                'revived' => [], 'mode' => $mode];
    }

    $revived = [];
    foreach (array_keys(xeric_deaths($db)) as $h) {
        $r = xeric_death_revive($t, $db, $h);
        if ($r['ok']) $revived[] = $h;
    }
    xeric_dark_set($db, []);          // the lights come back on with the people

    return ['ok' => true, 'error' => null, 'revived' => $revived, 'mode' => $mode];
}

// ---------------------------------------------------------------------------
// What the living are told
// ---------------------------------------------------------------------------

/**
 * The line a character's prompt carries about who is gone.
 *
 * In the VOLATILE tail rather than the system message, and the reason is not
 * cache economy — a death is exactly the kind of durable world fact the bible
 * carries. It is that a death is the one durable fact that can arrive between
 * two messages in a conversation, and a character still speaking of somebody in
 * the present tense because the system message was assembled ten minutes ago is
 * the single most obvious way this feature could look broken.
 *
 * Deliberately does not carry `how` or `by`. The roster says how, in the town's
 * words; who did it is a story's to hand out one beat at a time, and a line in
 * every prompt naming the killer would end a murder mystery before it opened.
 */
function xeric_death_line(array $t, array $deaths, string $speakerHandle = ''): string
{
    $dead = $deaths;
    unset($dead[$speakerHandle]);          // chat refuses the dead a turn; a sweep may not
    if ($dead === []) return '';

    $names = [];
    foreach (array_keys($dead) as $h) {
        if (xeric_world_character($t, $h) === null) continue;
        $names[] = xeric_world_name($t, $h);
    }
    if ($names === []) return '';

    $shown = array_slice($names, 0, XERIC_DEATH_NAMED);
    $rest  = count($names) - count($shown);

    return 'Dead, and everybody here knows it: ' . xeric_join_list($shown)
        . ($rest > 0 ? ', and ' . $rest . ' more' : '')
        . '. Speak of them in the past tense.';
}

/**
 * What a refusal says when the person being spoken to is dead.
 *
 * Same shape as xeric_age_refusal() — "<where>: refused — …" — because they are
 * the same kind of event: a rule of the world, not a fault, leaving everything
 * exactly as it was. play-lib.php turns both into sentences a person can read.
 */
function xeric_death_refusal(string $where, string $name): string
{
    return $where . ': refused, ' . $name . ' is dead and cannot answer';
}
