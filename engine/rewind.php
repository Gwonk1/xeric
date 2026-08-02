<?php
/**
 * Xeric — rewind. The one sanctioned way a world runs backwards.
 *
 * A skip is real time travel (docs/CONSTRUCTS.md): the fuse burns whether or not
 * you are watching, and a player who fast-forwarded past their own appointment
 * missed it in the world's own hour. This file is the mercy that decision earns:
 * the LAST SKIP, and only the last skip, can be taken back WHOLE. Its events,
 * memories, messages and clock movement un-happen; the world returns to the
 * moment before the button; the player lives those hours differently — and
 * differently is the point, because the windows go back to being unswept and the
 * world re-lives them on a fresh roll.
 *
 * THE PHYSICS: the world forgets the undone future; the player does not. Nothing
 * here touches the player's head — they rewind precisely BECAUSE they remember
 * what happened when they missed Thursday. What this file implements is the
 * world's amnesia, and amnesia has to be total or it is worse than memory: an
 * event deleted while its why-trail survives, a death undone while the deaths
 * ledger still holds the body, a text about an evening that no longer happened —
 * every one of those is a ghost, and a world with ghosts in it tells the player
 * the simulation is lying. So the whole file is organised around one rule:
 * NOTHING IS DELETED THAT A MANIFEST DID NOT NAME, AND NOTHING NAMED IS MISSED.
 *
 * ── THE MANIFEST IS A DIFF, NEVER A LEDGER KEPT BY HAND ──────────────────
 *
 * The tempting shape is a list the skip appends to as it goes: wrote an event,
 * note the id. That shape lies the first time anybody adds a write path and
 * forgets the note — and a manifest that lies produces a rewind that leaves
 * ghosts, which is worse than no rewind at all. So the manifest is computed, at
 * the end of the skip, by DIFFING the database against a snapshot taken before
 * the clock moved (xeric_rewind_mark → xeric_rewind_commit). A diff cannot
 * forget: whatever landed is what it names, including writes made by code that
 * has never heard of this file. The row tables diff by AUTOINCREMENT id — ids
 * are never reissued, so "id > mark" is exactly "written during the skip" — and
 * the two key/value stores (arcs, world_state) diff by full before/after maps,
 * which also hands back the exact inverse: added rows are deleted, changed rows
 * are restored, removed rows are put back.
 *
 * ── CONVERSATIONS DURING THE SKIP GO; THE TRANSCRIPT BEFORE IT STAYS ─────
 *
 * A message written mid-skip is part of the undone future. She texted you about
 * the evening at the Bluebird; if the evening un-happens and the text survives,
 * she is describing an hour that no longer exists — the text IS a memory of the
 * undone span, just one that lives in a thread instead of in her head. So
 * mid-skip messages go, their thread goes too if the skip is what opened it, and
 * an old thread merely bumped by one gets its unread dot and its place in the
 * list put back. The player's pre-skip transcript is untouched: its ids are
 * below the mark and the diff cannot reach it.
 *
 * The boundary has a third side. If the player has SPOKEN since the skip —
 * answered her text, opened a thread, said anything — the rewind refuses.
 * Deleting her ping while keeping the player's reply to it would orphan half a
 * conversation, and deleting the player's own typed words would be the world
 * reaching into the one memory that is not its to erase. Talking to the world
 * about the skipped hours is how they stop being undoable; the mercy is for the
 * moment after the button, not for a week later.
 *
 * ── HEART-LIVED HOURS ARE NOT REWINDABLE, ON PURPOSE ─────────────────────
 *
 * heart.php lives hours too, and writes no manifest — only an explicit skip
 * does. The distinction is who moved: a skip is the PLAYER compressing time, and
 * the mercy exists because a button was pressed that a person can regret; a
 * heart tick is the WORLD living at its own pace, which is the product's whole
 * claim, and you cannot regret Tuesday into not having happened. Mechanically
 * the same line does double duty: a heart tick after a skip writes events past
 * the manifest's high-water marks, so the staleness check below refuses the
 * rewind — the world moving on its own is exactly what "the world has moved on"
 * means. If the heart wrote manifests, "the last skip" would usually be some
 * background hour instead of the thing the player regrets, and the feature
 * would undo the wrong week.
 *
 * ── THE CONSTRUCTS SEAM ──────────────────────────────────────────────────
 *
 * Expectations (engine/constructs.php) keep their whole life in stores this
 * file already diffs: the fuse is an `expect.N` arc on the listener, and a
 * miss is an ordinary event, a memory, a trust bump and a why-row. So a fuse
 * that fires inside a skip un-fires on rewind with zero code here — the arc
 * goes back to OPEN, the waiting-event and its memory go, the trust point
 * comes home — and that restoration is not a side effect, it is the feature:
 * you rewind precisely to make the appointment, so the promise has to still
 * be a promise when you arrive. Today xeric_constructs_tick runs from the
 * heart, and heart-lived misses are correctly not rewindable (below); the day
 * the skip path calls it too — CONSTRUCTS.md says fuses burn during skips —
 * nothing in this file changes. THE SEAM IS TABLES: if a construct (or
 * anything else) ever grows a row table of its own, this file must learn it
 * in three places — the mark, the commit diff, and the rewind transaction —
 * or a rewind will leave that table remembering an un-happened week. deaths
 * is the worked example: it has no AUTOINCREMENT id, so it diffs by handle
 * set. Grep for XERIC-REWIND-TABLES to find every place a new table must be
 * added.
 *
 * Two stores are OUTSIDE the manifest, deliberately:
 *  - reminders run on REAL time (state.php is emphatic about it), and real time
 *    is the one clock a rewind does not command. A reminder asked for on
 *    Thursday is still owed on Thursday, whatever the world un-lived.
 *  - the player's signals from BEFORE the skip are evidence about hours that
 *    still happened. But the distil pass that ran mid-skip may have marked them
 *    read, and its lessons are being reverted with the arcs — so those marks
 *    are lifted (signals_reread) and the evidence goes back in the tray to be
 *    read again by a pass whose conclusions get to stick.
 *
 * Refusals are RETURN VALUES, not exceptions. "There is no skip to take back"
 * is an ordinary answer to an ordinary question — a button that throws is a
 * bug report, a button that explains is a UI — and every refusal names its
 * reason in words the player can act on. Nothing is written on any refusal.
 *
 * Zero dependencies beyond the store and the clock. PHP 8.2+.
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/clock.php';

/** The world_state key one skip's manifest lives under. One row, whole, replaced
 *  by the next skip — which is what "a rewind is consumed by the next skip"
 *  means: there is no stack, no branch, and no reach past the most recent one. */
const XERIC_REWIND_KEY = 'skip:last';

/** Manifest format. Bumped only if the shape changes; a manifest written by a
 *  newer engine is refused rather than half-read, because a half-read manifest
 *  is a lying one and this file's whole contract is that it never lies. */
const XERIC_REWIND_V = 1;

// ---------------------------------------------------------------------------
// The mark: everything the diff will need, read before the clock moves
// ---------------------------------------------------------------------------

/**
 * Snapshot the world on the near side of a skip.
 *
 * Taken immediately before xeric_clock_advance() and nowhere else: everything
 * between the mark and the commit is, by definition, the skip. (The learn
 * settle that judges the PREVIOUS span runs before the mark on both callers —
 * its verdicts are about hours that stay lived, so they must not ride the
 * manifest and get un-judged.)
 *
 * The row tables need only their high-water id — AUTOINCREMENT means every row
 * written after this moment has a larger one, and none of these tables is ever
 * deleted from outside a rewind. The two key/value stores are copied whole,
 * because a value can CHANGE mid-skip (a guard overwritten under --force, a
 * proactive day counter bumped, a fuse arc moved) and restoring a change needs
 * the prior value, not the fact of one. Both stores are small — a long-lived
 * world carries one guard row per hour lived, and reading a few thousand short
 * rows twice per skip is noise next to one model call.
 *
 * XERIC-REWIND-TABLES: deaths has no id column, so it snapshots as a handle
 * set. A new row table gets its line here first.
 */
function xeric_rewind_mark(PDO $db): array
{
    $top = static function (string $table) use ($db): int {
        // Table names are engine literals, never user input (state.php's rule).
        return (int)$db->query("SELECT COALESCE(MAX(id), 0) n FROM $table")->fetchAll()[0]['n'];
    };

    $deaths = [];
    foreach ($db->query('SELECT handle FROM deaths')->fetchAll() as $r) {
        $deaths[] = (string)$r['handle'];
    }

    // The crumbs still waiting to be read. If the mid-skip distil marks any of
    // them read, the rewind reverts its lessons with the arcs — so the marks
    // must lift too, or the evidence is spent on conclusions that no longer exist.
    $open = [];
    foreach ($db->query('SELECT id FROM signals WHERE processed = 0')->fetchAll() as $r) {
        $open[] = (int)$r['id'];
    }

    $arcs = [];
    foreach ($db->query('SELECT handle, key, value FROM arcs')->fetchAll() as $r) {
        $arcs[(string)$r['handle'] . "\x1f" . (string)$r['key']] = (string)$r['value'];
    }

    // Threads the skip merely touches (a ping into an old conversation) change
    // updated_at and unread without adding a row the id diff could see — and a
    // rewound world with a leftover unread dot is advertising a message that no
    // longer exists.
    $convs = [];
    foreach ($db->query('SELECT id, updated_at, unread FROM conversations')->fetchAll() as $r) {
        $convs[(int)$r['id']] = [(int)$r['updated_at'], (int)$r['unread']];
    }

    return [
        'offset'       => xeric_clock_offset($db),
        'top'          => [
            'events'        => $top('events'),
            'memories'      => $top('memories'),
            'messages'      => $top('messages'),
            'conversations' => $top('conversations'),
            'signals'       => $top('signals'),
        ],
        'deaths'       => $deaths,
        'signals_open' => $open,
        'world_state'  => xeric_world_state_all($db),
        'arcs'         => $arcs,
        'convs'        => $convs,
    ];
}

// ---------------------------------------------------------------------------
// The commit: the diff, written down as the one row a rewind may trust
// ---------------------------------------------------------------------------

/**
 * Write the skip's manifest, by diffing the world against its mark.
 *
 * Called at the END of a skip — after the sweeps, the ping, the pend and the
 * distil — and on the error path too (the caller's finally), because a skip
 * that died halfway still moved the clock and still landed hours, and those
 * are exactly as regrettable as a finished skip's. The diff names what
 * ACTUALLY landed, however far the skip got, which is the atomicity that
 * matters: the manifest is read from the same database it will later undo, in
 * the same moment, so it cannot describe a world that is not there.
 *
 * Returns the manifest, or null when the clock never moved — a skip that
 * failed before its advance, or a plain sweep of the current hour, is not time
 * travel and must not overwrite the manifest of the skip that was. (A hard
 * kill between the advance and this call leaves NO manifest, which fails in
 * the safe direction: an unrewindable skip is a missing mercy; a wrong
 * manifest is a lying world.)
 */
function xeric_rewind_commit(PDO $db, array $mark, ?int $at = null): ?array
{
    $offsetNow = xeric_clock_offset($db);
    $wasOffset = (int)($mark['offset'] ?? 0);
    if ($offsetNow === $wasOffset) return null;

    $newIds = static function (string $table, int $above) use ($db): array {
        $out = [];
        foreach ($db->query("SELECT id FROM $table WHERE id > " . (int)$above . ' ORDER BY id')->fetchAll() as $r) {
            $out[] = (int)$r['id'];
        }
        return $out;
    };

    $top = (array)($mark['top'] ?? []);
    $ids = [
        'events'        => $newIds('events',        (int)($top['events'] ?? 0)),
        'memories'      => $newIds('memories',      (int)($top['memories'] ?? 0)),
        'messages'      => $newIds('messages',      (int)($top['messages'] ?? 0)),
        'conversations' => $newIds('conversations', (int)($top['conversations'] ?? 0)),
        'signals'       => $newIds('signals',       (int)($top['signals'] ?? 0)),
    ];

    // XERIC-REWIND-TABLES: deaths, by handle. A lethal hour that landed inside
    // the skip put a body in the ledger, and a rewind that gave the hours back
    // while keeping the body would be the worst ghost this file can make.
    $wasDead = array_flip(array_map('strval', (array)($mark['deaths'] ?? [])));
    $deaths  = [];
    foreach ($db->query('SELECT handle FROM deaths')->fetchAll() as $r) {
        if (!isset($wasDead[(string)$r['handle']])) $deaths[] = (string)$r['handle'];
    }

    // Which of the crumbs that were waiting at the mark did the mid-skip distil
    // spend? (Pruned ones simply do not select — an UPDATE on a missing id is a
    // no-op, and recording it would be the manifest naming a row that is not there.)
    $reread = [];
    $open   = array_values(array_filter(array_map('intval', (array)($mark['signals_open'] ?? [])), fn($i) => $i > 0));
    if ($open !== []) {
        $in = implode(',', $open);
        foreach ($db->query("SELECT id FROM signals WHERE processed = 1 AND id IN ($in)")->fetchAll() as $r) {
            $reread[] = (int)$r['id'];
        }
    }

    // The two stores, as exact inverses. `added` is deleted on rewind, `changed`
    // is restored to its prior value, `removed` is put back whole — nothing
    // deletes from either store mid-skip today, but the diff records it anyway,
    // because the day something does (a construct clearing a spent fuse, say)
    // must not be the day the manifest starts lying by omission.
    $wsWas = (array)($mark['world_state'] ?? []);
    $wsNow = xeric_world_state_all($db);
    $ws    = ['added' => [], 'changed' => [], 'removed' => []];
    foreach ($wsNow as $k => $v) {
        if ($k === XERIC_REWIND_KEY) continue;      // the manifest is not part of the world it describes
        if (!array_key_exists($k, $wsWas))          $ws['added'][] = (string)$k;
        elseif ((string)$wsWas[$k] !== (string)$v)  $ws['changed'][(string)$k] = (string)$wsWas[$k];
    }
    foreach ($wsWas as $k => $v) {
        if ($k === XERIC_REWIND_KEY) continue;
        if (!array_key_exists($k, $wsNow)) $ws['removed'][(string)$k] = (string)$v;
    }

    $arcWas = (array)($mark['arcs'] ?? []);
    $arcNow = [];
    foreach ($db->query('SELECT handle, key, value FROM arcs')->fetchAll() as $r) {
        $arcNow[(string)$r['handle'] . "\x1f" . (string)$r['key']] = (string)$r['value'];
    }
    $arcs = ['added' => [], 'changed' => [], 'removed' => []];
    foreach ($arcNow as $hk => $v) {
        [$h, $k] = explode("\x1f", (string)$hk, 2);
        if (!array_key_exists($hk, $arcWas))           $arcs['added'][]   = [$h, $k];
        elseif ((string)$arcWas[$hk] !== (string)$v)   $arcs['changed'][] = [$h, $k, (string)$arcWas[$hk]];
    }
    foreach ($arcWas as $hk => $v) {
        if (array_key_exists($hk, $arcNow)) continue;
        [$h, $k] = explode("\x1f", (string)$hk, 2);
        $arcs['removed'][] = [$h, $k, (string)$v];
    }

    // Old threads the skip touched without adding: prior updated_at and unread,
    // so the dot and the sort order go back where they were.
    $convWas = (array)($mark['convs'] ?? []);
    $touched = [];
    $newConv = array_flip($ids['conversations']);
    foreach ($db->query('SELECT id, updated_at, unread FROM conversations')->fetchAll() as $r) {
        $id = (int)$r['id'];
        if (isset($newConv[$id]) || !isset($convWas[$id])) continue;
        [$u, $n] = $convWas[$id];
        if ((int)$r['updated_at'] !== (int)$u || (int)$r['unread'] !== (int)$n) $touched[] = [$id, (int)$u, (int)$n];
    }

    $manifest = [
        'v'      => XERIC_REWIND_V,
        'at'     => $at ?? xeric_state_time(),
        'span'   => $offsetNow - $wasOffset,
        'before' => ['offset' => $wasOffset],
        // The far edge, for the staleness check: a rewind is legal only while
        // the world still stands exactly where this skip left it. The
        // watermarks are here because a heart tick whose hours were all QUIET
        // moves nothing the id high-waters can see — no event, no memory, no
        // message — and still burns windows; without this line such a tick
        // would leave its guards standing in the rewound world's future, and
        // those hours would arrive pre-lived and silently produce nothing.
        'after'  => [
            'offset'     => $offsetNow,
            'events'     => (int)$db->query('SELECT COALESCE(MAX(id),0) n FROM events')->fetchAll()[0]['n'],
            'memories'   => (int)$db->query('SELECT COALESCE(MAX(id),0) n FROM memories')->fetchAll()[0]['n'],
            'messages'   => (int)$db->query('SELECT COALESCE(MAX(id),0) n FROM messages')->fetchAll()[0]['n'],
            'watermarks' => xeric_rewind_watermarks($db),
        ],
        'ids'            => $ids,
        'deaths'         => $deaths,
        'signals_reread' => $reread,
        'world_state'    => $ws,
        'arcs'           => $arcs,
        'convs_touched'  => $touched,
    ];

    xeric_world_state_set($db, XERIC_REWIND_KEY,
        json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);
    return $manifest;
}

/**
 * Every sweep watermark, whatever its window size. A map rather than one value
 * because the window size is the template's to choose (heart.php reads
 * `events.window_seconds`), and a staleness check that assumed one size would
 * go blind the day a world picked another.
 */
function xeric_rewind_watermarks(PDO $db): array
{
    $out = [];
    foreach (xeric_world_state_all($db) as $k => $v) {
        if (str_starts_with((string)$k, 'sweep_watermark:')) $out[(string)$k] = (string)$v;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// The rewind itself
// ---------------------------------------------------------------------------

/**
 * Take back the last skip, whole.
 *
 * One transaction: everything the manifest names is deleted or restored, the
 * offset and the watermark go back (they are world_state rows, so the store
 * diff carries them like anything else), the window guards for the un-happened
 * hours are deleted — which is what makes those hours SWEEPABLE AGAIN; the
 * world will re-live them, possibly differently, and that is the point — and
 * the manifest itself is cleared, so a second rewind has nothing to stand on
 * and refuses. AUTOINCREMENT never reissues the deleted ids, so anything still
 * holding one (a browser tab on a why-link) dangles honestly instead of
 * pointing at some later, different evening.
 *
 * @param array $t the template — only for saying where the world now stands
 * @return array{ok:bool, why:?string, events:int, memories:int, messages:int,
 *               hours:float, label:string, now:?array}
 *         `ok:false` writes NOTHING; `why` says so in words a screen can show.
 */
/**
 * The guard chain, shared by the rewind and the button that offers it.
 *
 * Returns [manifest, null] when the last skip is still takeable-back, or
 * [null, why] in a human sentence when it is not. One home for the checks so
 * the play view's "is the button lit" and the rewind's "may I actually" can
 * never drift into disagreeing — a button that offers what the engine then
 * refuses is the exact lie this extraction prevents.
 */
function xeric_rewind_check(PDO $db): array
{
    $raw = xeric_world_state_get($db, XERIC_REWIND_KEY);
    if ($raw === null || trim((string)$raw) === '') {
        return [null, 'there is no skip to take back — the last one was already rewound, or the world has only ever moved on its own'];
    }
    $m = json_decode((string)$raw, true);
    if (!is_array($m) || (int)($m['v'] ?? 0) !== XERIC_REWIND_V) {
        return [null, 'the last skip\'s record was written by a different build of the engine, and a rewind that half-understands its manifest leaves ghosts — this one stays lived'];
    }

    // ── the staleness check ────────────────────────────────────────────────
    // "The most recent movement" is enforced here, and it is three questions
    // because the world moves three ways. The clock: another skip (or a pause
    // resumed) shifted the offset. The world's own life: a heart tick lived
    // hours past this manifest, and hours the world lived at its own pace are
    // not the player's to unwind. And the player themselves: words said since
    // the skip would be orphaned by deleting the ping they answered — talking
    // about the skipped span is how it stops being undoable.
    $after = (array)($m['after'] ?? []);
    if (xeric_clock_offset($db) !== (int)($after['offset'] ?? PHP_INT_MIN)) {
        return [null, 'the clock has moved again since that skip — only the most recent movement can be taken back'];
    }
    $topNow = static fn(string $tbl): int =>
        (int)$db->query("SELECT COALESCE(MAX(id),0) n FROM $tbl")->fetchAll()[0]['n'];
    if ($topNow('messages') !== (int)($after['messages'] ?? -1)) {
        return [null, 'words have been said since that skip — those hours are lived-in now, and the world keeps them'];
    }
    // Loose == on purpose: two maps with the same keys and values are the same
    // watermarks whatever order json_decode handed them back in.
    if ($topNow('events') !== (int)($after['events'] ?? -1)
        || $topNow('memories') !== (int)($after['memories'] ?? -1)
        || xeric_rewind_watermarks($db) != (array)($after['watermarks'] ?? [])) {
        return [null, 'the world has lived hours of its own since that skip — it has moved on, and moved-on hours stay'];
    }
    return [$m, null];
}

/**
 * What a rewind WOULD take back, or null when the window is closed. This is
 * the play view's read: label + counts for the button and its warning card,
 * from the same guards the rewind itself runs.
 */
function xeric_rewind_peek(array $t, PDO $db): ?array
{
    [$m, $why] = xeric_rewind_check($db);
    if ($m === null) return null;
    $ids = (array)($m['ids'] ?? []);
    return [
        'span'     => (string)($m['span'] ?? ''),
        'events'   => count((array)($ids['events'] ?? [])),
        'memories' => count((array)($ids['memories'] ?? [])),
        'messages' => count((array)($ids['messages'] ?? [])),
    ];
}

function xeric_rewind(array $t, PDO $db): array
{
    $no = static fn(string $why): array => [
        'ok' => false, 'why' => $why,
        'events' => 0, 'memories' => 0, 'messages' => 0, 'hours' => 0.0, 'label' => '0s', 'now' => null,
    ];

    [$m, $why] = xeric_rewind_check($db);
    if ($m === null) return $no((string)$why);

    $ids   = (array)($m['ids'] ?? []);
    $list  = static fn(string $k): array =>
        array_values(array_filter(array_map('intval', (array)($ids[$k] ?? [])), fn($i) => $i > 0));

    $events   = $list('events');
    $memories = $list('memories');
    $messages = $list('messages');
    $convs    = $list('conversations');
    $signals  = $list('signals');

    $db->beginTransaction();
    try {
        $kill = static function (string $table, array $rows) use ($db): void {
            if ($rows === []) return;
            $db->exec("DELETE FROM $table WHERE id IN (" . implode(',', $rows) . ')');
        };
        // Messages before their conversations only by tidiness — the FK would
        // cascade — but a rewind that leans on a PRAGMA being on is a rewind
        // that ghosts on the one install where it is not.
        // XERIC-REWIND-TABLES: every row table the manifest names, deleted here.
        $kill('events',        $events);
        $kill('memories',      $memories);
        $kill('messages',      $messages);
        $kill('conversations', $convs);
        $kill('signals',       $signals);

        $undead = array_values(array_filter(array_map('strval', (array)($m['deaths'] ?? [])), fn($h) => $h !== ''));
        if ($undead !== []) {
            $q = $db->prepare('DELETE FROM deaths WHERE handle = ?');
            foreach ($undead as $h) $q->execute([$h]);
        }

        $reread = array_values(array_filter(array_map('intval', (array)($m['signals_reread'] ?? [])), fn($i) => $i > 0));
        if ($reread !== []) {
            $db->exec('UPDATE signals SET processed = 0 WHERE id IN (' . implode(',', $reread) . ')');
        }

        foreach ((array)($m['convs_touched'] ?? []) as $row) {
            $q = $db->prepare('UPDATE conversations SET updated_at = ?, unread = ? WHERE id = ?');
            $q->execute([(int)($row[1] ?? 0), (int)($row[2] ?? 0), (int)($row[0] ?? 0)]);
        }

        // The stores, restored as the exact inverse the commit recorded. This
        // is where the offset returns, the watermark returns, the window
        // guards vanish (they are `added` keys) and the why-trails of the
        // undone hours go with their events. It is also THE CONSTRUCTS SEAM
        // working: an `expect.N` fuse constructs.php flipped mid-skip is a
        // `changed` arc, put back to OPEN here without this file knowing what
        // a promise is — which is the whole reason the manifest is a diff.
        $ws = (array)($m['world_state'] ?? []);
        foreach ((array)($ws['added'] ?? []) as $k)        xeric_world_state_delete($db, (string)$k);
        foreach ((array)($ws['changed'] ?? []) as $k => $v) xeric_world_state_set($db, (string)$k, (string)$v);
        foreach ((array)($ws['removed'] ?? []) as $k => $v) xeric_world_state_set($db, (string)$k, (string)$v);

        $arcs = (array)($m['arcs'] ?? []);
        foreach ((array)($arcs['added'] ?? []) as $row)    xeric_arc_clear($db, (string)($row[0] ?? ''), (string)($row[1] ?? ''));
        foreach ((array)($arcs['changed'] ?? []) as $row)  xeric_arc_set($db, (string)($row[0] ?? ''), (string)($row[1] ?? ''), (string)($row[2] ?? ''));
        foreach ((array)($arcs['removed'] ?? []) as $row)  xeric_arc_set($db, (string)($row[0] ?? ''), (string)($row[1] ?? ''), (string)($row[2] ?? ''));

        // And the manifest goes last, inside the same transaction: a rewind
        // half-done with its manifest intact could run twice, and a rewind
        // done with its manifest gone is what "consumed" means.
        xeric_world_state_delete($db, XERIC_REWIND_KEY);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return $no('the rewind could not be completed and nothing was changed — ' . $e->getMessage());
    }

    $span = max(0, (int)($m['span'] ?? 0));
    return [
        'ok'       => true,
        'why'      => null,
        'events'   => count($events),
        'memories' => count($memories),
        'messages' => count($messages),
        'hours'    => round($span / 3600, 1),
        'label'    => xeric_clock_span_label($span),
        'now'      => xeric_clock_now($db, $t),
    ];
}
