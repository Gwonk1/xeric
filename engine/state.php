<?php
/**
 * Xeric — persistence. SQLite, world-agnostic.
 *
 * The lived world: threads, what was said, what people remember, what they are
 * counting, what happened offscreen, and the handful of singletons (the clock
 * offset, sweep guards) that belong to the world rather than to anybody in it.
 *
 * Disciplines:
 *
 *  1. MIGRATIONS NEVER DROP. CREATE TABLE IF NOT EXISTS plus guarded ALTERs,
 *     forever. A world is somebody's months of continuity; there is no schema
 *     change worth a DROP. Every ALTER checks PRAGMA table_info first rather
 *     than trusting a version number that a half-finished migration may have
 *     already bumped.
 *  2. FETCH IT ALL, THEN LET GO. Every read here uses fetchAll(). A PDO+SQLite
 *     cursor left half-consumed pins the WAL snapshot and turns the next write
 *     into "database is locked" — which shows up an hour later, in a sweep, on
 *     somebody else's machine.
 *  3. TIME COMES FROM THE CALLER. Nothing here reads time() except
 *     xeric_state_time(), and every function that stores a timestamp takes one.
 *     The demo runs the world on a shifted clock; rows written by a sweep must
 *     carry the WORLD's epoch, not the server's.
 *
 * Zero dependencies. PHP 8.2+.
 */

// ---------------------------------------------------------------------------
// Open + migrate
// ---------------------------------------------------------------------------

/**
 * Open (creating if needed) a world database, and bring its schema up to date.
 * Idempotent: safe to call on a fresh file, on a current one, and on one written
 * by an older build of the engine.
 */
function xeric_state_open(string $dbPath): PDO
{
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // WAL: sweeps write while somebody is reading a thread. busy_timeout is the
    // difference between "a sweep and a chat turn overlapped" and a 500.
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA synchronous  = NORMAL');
    $db->exec('PRAGMA foreign_keys = ON');

    xeric_state_migrate($db);
    return $db;
}

/** Wall clock, in one place, so tests and the demo can reason about it. */
function xeric_state_time(): int
{
    return time();
}

/**
 * Idempotent schema. Add columns at the bottom of xeric_state_alters(); never
 * edit a CREATE TABLE that has shipped, because existing worlds will not re-run it.
 */
function xeric_state_migrate(PDO $db): void
{
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS conversations (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            handle      TEXT    NOT NULL,
            kind        TEXT    NOT NULL DEFAULT 'chat',   -- chat | event
            title       TEXT,
            unread      INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL,
            updated_at  INTEGER NOT NULL
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_conversations_handle ON conversations(handle, updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_conversations_updated ON conversations(updated_at DESC)');

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS messages (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
            role            TEXT    NOT NULL,               -- user | character | narrator
            handle          TEXT,                           -- who spoke, when it wasn't the user
            content         TEXT    NOT NULL,
            created_at      INTEGER NOT NULL,               -- real time, for ordering
            world_epoch     INTEGER                         -- world time, for prose
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages(conversation_id, id)');
    // AND BY WHEN, for the book. xeric_book_scenes() reads a day at a time —
    // `WHERE world_epoch >= ? AND world_epoch < ? ORDER BY world_epoch, id` —
    // once per day rendered, up to XERIC_BOOK_DAYS_MAX of them on one page.
    // With only the conversation index that is a full table scan plus a temp
    // b-tree for the sort, per day: measured at 10.6ms for a seven-day page and
    // 54ms for a thirty-one-day one against 2,400 messages, and linear in both.
    // A year-old world would spend most of a second on the page. The same index
    // also turns the `SELECT MIN(world_epoch)` that finds where the book starts
    // into a seek instead of a second scan.
    $db->exec('CREATE INDEX IF NOT EXISTS idx_messages_epoch ON messages(world_epoch)');

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS memories (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            handle      TEXT    NOT NULL,                   -- whose head this is in
            text        TEXT    NOT NULL,
            source      TEXT    NOT NULL DEFAULT 'auto',    -- auto | event | diary
            meta        TEXT,                               -- JSON: event id, place, participants…
            created_at  INTEGER NOT NULL,
            world_epoch INTEGER
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_memories_handle ON memories(handle, id DESC)');

    // arcs: the generic per-person key/value store. Trust, heat-equivalents,
    // economy counters, boon debts, suspicion dials — anything the engine counts.
    // Values are TEXT because a "value" is sometimes 7, sometimes 7.5, sometimes
    // "warm"; the numeric accessors cast on the way out.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS arcs (
            handle     TEXT    NOT NULL,                    -- '' = the world itself
            key        TEXT    NOT NULL,
            value      TEXT    NOT NULL,
            updated_at INTEGER NOT NULL,
            PRIMARY KEY (handle, key)
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_arcs_key ON arcs(key)');

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS events (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            title        TEXT    NOT NULL,
            world_epoch  INTEGER NOT NULL,
            place        TEXT,
            participants TEXT,                              -- JSON array of handles
            prose        TEXT,
            created_at   INTEGER NOT NULL
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_events_when ON events(world_epoch DESC)');

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS world_state (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )
    SQL);

    // signals: what the person living in this world actually did — answered,
    // ignored, skipped past, read, retyped by hand. Raw crumbs only. What they
    // MEAN is learn.php's business (xeric_lessons_distil), and the distilled
    // lessons live in arcs like everything else the engine counts; this table is
    // the evidence, kept only until it has been read and then aged out.
    //
    // `n` and `lag` are the two numbers a crumb can carry and what they hold
    // depends on the kind — for a reply, how long the answer was and how long
    // they took to write it. learn.php documents the rest.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS signals (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            kind        TEXT    NOT NULL,               -- reply | ignored | skipped | dwell | edit
            handle      TEXT    NOT NULL DEFAULT '',    -- who it is about ('' = the world)
            subject     TEXT    NOT NULL DEFAULT '',    -- an event kind, a template path, kind decides
            n           INTEGER NOT NULL DEFAULT 0,
            lag         INTEGER NOT NULL DEFAULT 0,
            note        TEXT    NOT NULL DEFAULT '',    -- the words, when there are any
            processed   INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL,
            world_epoch INTEGER
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_signals_open ON signals(processed, id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_signals_kind ON signals(kind, handle)');

    // deaths: who this world has lost. One row per person, so revival is a
    // DELETE and the ledger cannot hold somebody twice. Death is state and never
    // a deletion from the template (death.php) — the character stays in the
    // world, resolvable, remembered, behind whatever walls they were behind.
    // `how` is COMMONS text: it is printed to anyone who can see the cast.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS deaths (
            handle      TEXT    PRIMARY KEY,
            world_epoch INTEGER NOT NULL,               -- when, on the world's clock
            how         TEXT    NOT NULL DEFAULT '',    -- what the town would say
            by_handle   TEXT,                           -- when somebody did it
            created_at  INTEGER NOT NULL
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_deaths_when ON deaths(world_epoch)');

    // photo_jobs: pictures the world owes itself. A forge (and every open, via
    // the idempotent backfill) enqueues the cast's portraits and the places'
    // establishing shots as ROWS, not files — rows the reaper drains when an
    // image machine answers, and rows the rewind and fork disciplines can
    // reason about. `caption` is the 6-8 words that stand where the image
    // would until it exists (engine/photo.php).
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS photo_jobs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            kind       TEXT    NOT NULL,                  -- portrait | place
            subject    TEXT    NOT NULL,                  -- a handle, or a place key
            status     TEXT    NOT NULL DEFAULT 'pending',-- pending | done | failed
            tries      INTEGER NOT NULL DEFAULT 0,
            file       TEXT    NOT NULL DEFAULT '',       -- relative to the world's photos dir
            caption    TEXT    NOT NULL DEFAULT '',       -- the 6-8 words that stand in meanwhile
            created_at INTEGER NOT NULL,
            done_at    INTEGER
        )
    SQL);
    // One job per (kind, subject), which is what makes the backfill idempotent:
    // every open may offer the whole cast and every place, and the table keeps
    // exactly one row each however many times they are offered.
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_photo_jobs_one ON photo_jobs(kind, subject)');

    // reminders: the one thing in this database that runs on REAL time. A world
    // keeps its own clock and everything else here is stamped with it — but
    // somebody who asked to be reminded on Thursday meant the Thursday they are
    // going to live through, not the one a paused or fast-forwarded world is
    // pointing at. `due` is a wall-clock epoch and nothing may offset it.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS reminders (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            handle     TEXT    NOT NULL DEFAULT '',   -- who they asked
            text       TEXT    NOT NULL,
            due        INTEGER NOT NULL,              -- REAL time, never the world's
            fired_at   INTEGER,                       -- real time, or null
            created_at INTEGER NOT NULL
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reminders_due ON reminders(fired_at, due)');

    foreach (xeric_state_alters() as [$table, $column, $decl]) {
        xeric_state_add_column($db, $table, $column, $decl);
    }
}

/**
 * Columns added after a table shipped. Append only.
 *
 * `events.on_spine` is here rather than in the CREATE TABLE above because worlds
 * that already exist would never re-run it. It carries whether an hour was about
 * the thing this world is keeping quiet, which the sweep decided and then threw
 * away — leaving a model-written title ("name the thing", says the prompt) free
 * to replay into a later prompt that has the protected character standing in it.
 *
 * @return array<array{0:string,1:string,2:string}> [table, column, declaration]
 */
/** Book one. Real time in, real time stored. */
function xeric_remind_add(PDO $db, string $text, int $due, string $handle = '', ?int $at = null): int
{
    $q = $db->prepare('INSERT INTO reminders (handle, text, due, created_at) VALUES (?,?,?,?)');
    $q->execute([$handle, $text, $due, $at ?? xeric_state_time()]);
    return (int)$db->lastInsertId();
}

/**
 * Everything due and not yet sent.
 *
 * Ordered oldest-first so a world that has been shut for a week delivers a
 * backlog in the order it was asked for rather than newest-first, which reads
 * as a list of things somebody already missed.
 */
function xeric_remind_due(PDO $db, ?int $now = null): array
{
    $q = $db->prepare('SELECT * FROM reminders WHERE fired_at IS NULL AND due <= ? ORDER BY due, id');
    $q->execute([$now ?? xeric_state_time()]);
    $rows = $q->fetchAll();
    $q->closeCursor();
    return $rows ?: [];
}

/**
 * Said. Stamped before the send, never after — see xeric_remind_fire().
 *
 * `AND fired_at IS NULL` is what makes the claim exclusive, and the RETURN is
 * what makes it useful: SQLite serializes the two writes, so of two runners
 * reaching the same row a second apart exactly one updates a row and the other
 * updates none. A caller that does not look at the answer gets the query's
 * safety and none of its meaning — both would go on to send.
 *
 * @return bool whether THIS call is the one that claimed it
 */
function xeric_remind_done(PDO $db, int $id, ?int $at = null): bool
{
    $q = $db->prepare('UPDATE reminders SET fired_at = ? WHERE id = ? AND fired_at IS NULL');
    $q->execute([$at ?? xeric_state_time(), $id]);
    $n = $q->rowCount();
    $q->closeCursor();
    return $n > 0;
}

/** What is still coming, for a screen that wants to show it. */
function xeric_remind_pending(PDO $db, int $limit = 20): array
{
    $q = $db->prepare('SELECT * FROM reminders WHERE fired_at IS NULL ORDER BY due LIMIT ?');
    $q->execute([$limit]);
    $rows = $q->fetchAll();
    $q->closeCursor();
    return $rows ?: [];
}

function xeric_state_alters(): array
{
    return [
        ['events', 'on_spine', 'INTEGER NOT NULL DEFAULT 0'],
        // The audible surface of an hour: one short exchange a body in the
        // doorway could have heard, written by the same sweep call that wrote
        // the hour. What an arrival quotes when the talk was real.
        ['events', 'overheard', "TEXT NOT NULL DEFAULT ''"],
        // Where a message-photo delivers, and the words it was asked with:
        // the reaper runs behind the conversation and needs both.
        ['photo_jobs', 'ask',  "TEXT NOT NULL DEFAULT ''"],
        ['photo_jobs', 'conv', 'INTEGER'],
        // WHICH person at the centre a crumb is about. NULL on every row that
        // predates two people ever being in a world, and NULL reads as the
        // first player — so nothing already on disk changes meaning and no
        // backfill is needed. -1 is a deliberate third answer: a silence with
        // two people in the room that nobody in particular can be blamed for.
        ['signals', 'player', 'INTEGER'],
    ];
}

/** Column names of a table, [] when it doesn't exist. */
function xeric_state_columns(PDO $db, string $table): array
{
    // Table names here are engine literals, never user input.
    $rows = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[] = (string)$r['name'];
    return $out;
}

/** ALTER only if the column is genuinely missing. Never widens, never drops. */
function xeric_state_add_column(PDO $db, string $table, string $column, string $decl): void
{
    $cols = xeric_state_columns($db, $table);
    if ($cols === [] || in_array($column, $cols, true)) return;
    $db->exec("ALTER TABLE $table ADD COLUMN $column $decl");
}

// ---------------------------------------------------------------------------
// Conversations
// ---------------------------------------------------------------------------

function xeric_conversation_create(PDO $db, string $handle, string $kind = 'chat', ?string $title = null, ?int $at = null): int
{
    $at = $at ?? xeric_state_time();
    $st = $db->prepare('INSERT INTO conversations (handle, kind, title, unread, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)');
    $st->execute([$handle, $kind, $title, $at, $at]);
    return (int)$db->lastInsertId();
}

function xeric_conversation_get(PDO $db, int $id): ?array
{
    $st = $db->prepare('SELECT * FROM conversations WHERE id = ?');
    $st->execute([$id]);
    $rows = $st->fetchAll();
    return $rows ? $rows[0] : null;
}

/** The most recently touched thread of this kind with this character. */
function xeric_conversation_find(PDO $db, string $handle, string $kind = 'chat'): ?array
{
    $st = $db->prepare('SELECT * FROM conversations WHERE handle = ? AND kind = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
    $st->execute([$handle, $kind]);
    $rows = $st->fetchAll();
    return $rows ? $rows[0] : null;
}

/** Find-or-create. The ordinary way to get a chat thread. */
function xeric_conversation_for(PDO $db, string $handle, string $kind = 'chat', ?string $title = null, ?int $at = null): int
{
    $found = xeric_conversation_find($db, $handle, $kind);
    if ($found) return (int)$found['id'];
    return xeric_conversation_create($db, $handle, $kind, $title, $at);
}

/** Newest first. $handle narrows to one character's threads. */
function xeric_conversations_recent(PDO $db, int $limit = 20, ?string $handle = null): array
{
    if ($handle === null) {
        $st = $db->prepare('SELECT * FROM conversations ORDER BY updated_at DESC, id DESC LIMIT ?');
        $st->execute([$limit]);
    } else {
        $st = $db->prepare('SELECT * FROM conversations WHERE handle = ? ORDER BY updated_at DESC, id DESC LIMIT ?');
        $st->execute([$handle, $limit]);
    }
    return $st->fetchAll();
}

function xeric_conversations_count(PDO $db, ?string $handle = null): int
{
    if ($handle === null) return (int)$db->query('SELECT COUNT(*) c FROM conversations')->fetchAll()[0]['c'];
    $st = $db->prepare('SELECT COUNT(*) c FROM conversations WHERE handle = ?');
    $st->execute([$handle]);
    return (int)$st->fetchAll()[0]['c'];
}

function xeric_conversation_touch(PDO $db, int $id, ?int $unread = null, ?int $at = null): void
{
    $at = $at ?? xeric_state_time();
    if ($unread === null) {
        $st = $db->prepare('UPDATE conversations SET updated_at = ? WHERE id = ?');
        $st->execute([$at, $id]);
        return;
    }
    $st = $db->prepare('UPDATE conversations SET updated_at = ?, unread = ? WHERE id = ?');
    $st->execute([$at, $unread, $id]);
}

function xeric_conversation_unread_total(PDO $db): int
{
    return (int)$db->query('SELECT COALESCE(SUM(unread), 0) n FROM conversations')->fetchAll()[0]['n'];
}

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------

/**
 * Append one turn and touch its thread.
 *
 * @param string $role 'user' | 'character' | 'narrator'
 * @param int|null $worldEpoch the WORLD's clock, not the server's — a message
 *                 written during a fast-forward belongs to the fast-forwarded hour.
 */
function xeric_message_append(PDO $db, int $conversationId, string $role, ?string $handle, string $content, ?int $worldEpoch = null, ?int $at = null): int
{
    $at = $at ?? xeric_state_time();
    $st = $db->prepare('INSERT INTO messages (conversation_id, role, handle, content, created_at, world_epoch) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$conversationId, $role, $handle, $content, $at, $worldEpoch]);
    $id = (int)$db->lastInsertId();

    // Unread is a property of the thread the character just spoke into.
    if ($role === 'user') {
        $u = $db->prepare('UPDATE conversations SET updated_at = ?, unread = 0 WHERE id = ?');
        $u->execute([$at, $conversationId]);
    } else {
        $u = $db->prepare('UPDATE conversations SET updated_at = ?, unread = unread + 1 WHERE id = ?');
        $u->execute([$at, $conversationId]);
    }
    return $id;
}

/** The tail of a thread, oldest → newest (the order a prompt wants). */
/**
 * The tail of a conversation.
 *
 * ── $chunk: WHY THE WINDOW HAS TO MOVE IN STEPS ───────────────────────────
 *
 * "The last N" slides by one message every turn, and a prompt built from a
 * sliding window has no stable prefix: turn n sends messages [k … k+19] and
 * turn n+1 sends [k+2 … k+21], so the two token sequences diverge at the FIRST
 * history message and the model's cache ends at the system block. Every turn
 * past the twentieth re-processes the entire window, forever.
 *
 * Measured over a forty-turn conversation: 3,070 bytes (~830 tokens)
 * re-prefilled per turn once the window starts sliding, against 518 (~140) when
 * it does not — six times the prefill on the app's hottest path, for nothing.
 *
 * With a chunk, the START is quantised: it holds still until the conversation
 * has grown a whole chunk past the limit, then drops that many at once. The
 * prefix breaks on one turn in $chunk instead of on every turn, and the window
 * carries between $limit and $limit + $chunk - 1 messages — never FEWER than
 * before, which matters because this is what a character remembers of the
 * conversation they are in.
 *
 * $chunk = 0 keeps the old exact-N behaviour for callers that want a bounded
 * read rather than a prompt.
 */
function xeric_messages_recent(PDO $db, int $conversationId, int $limit = 20, int $chunk = 0): array
{
    if ($chunk > 1) {
        $st = $db->prepare('SELECT COUNT(*) c FROM messages WHERE conversation_id = ?');
        $st->execute([$conversationId]);
        $total = (int)($st->fetchAll()[0]['c'] ?? 0);
        $st->closeCursor();

        $drop = intdiv(max(0, $total - $limit), $chunk) * $chunk;
        $st = $db->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id LIMIT -1 OFFSET ?');
        $st->execute([$conversationId, $drop]);
        $rows = $st->fetchAll();
        $st->closeCursor();
        return $rows ?: [];
    }

    $st = $db->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?');
    $st->execute([$conversationId, $limit]);
    return array_reverse($st->fetchAll());
}

function xeric_messages_count(PDO $db, int $conversationId): int
{
    $st = $db->prepare('SELECT COUNT(*) c FROM messages WHERE conversation_id = ?');
    $st->execute([$conversationId]);
    return (int)$st->fetchAll()[0]['c'];
}

// ---------------------------------------------------------------------------
// Memories
// ---------------------------------------------------------------------------

/**
 * One thing one person remembers.
 *
 * `source` is an open vocabulary and the column's comment names only the three
 * it shipped with: a sweep writes 'event', the seeder 'auto', a story overlay
 * writes 'spill' when a character has told the user something and 'story' for
 * the residue a closed one leaves. Nothing reads it as an enum — it is there so
 * a person looking at the table can tell a lived memory from a seeded one — so a
 * new kind of memory costs a string and never a migration.
 */
function xeric_memory_add(PDO $db, string $handle, string $text, string $source = 'auto', array $meta = [], ?int $worldEpoch = null, ?int $at = null): int
{
    $at = $at ?? xeric_state_time();
    $st = $db->prepare('INSERT INTO memories (handle, text, source, meta, created_at, world_epoch) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$handle, $text, $source, $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $at, $worldEpoch]);
    return (int)$db->lastInsertId();
}

/**
 * The last $limit things this character remembers, oldest → newest.
 *
 * Chronological on purpose: a memory list a model reads top-to-bottom should
 * run forward in time, and a stable order is what makes the block cacheable.
 */
function xeric_memories_for(PDO $db, string $handle, int $limit = 12): array
{
    $st = $db->prepare('SELECT * FROM memories WHERE handle = ? ORDER BY id DESC LIMIT ?');
    $st->execute([$handle, $limit]);
    $rows = array_reverse($st->fetchAll());
    foreach ($rows as &$r) {
        $r['meta'] = $r['meta'] !== null ? (json_decode((string)$r['meta'], true) ?: []) : [];
    }
    return $rows;
}

function xeric_memories_count(PDO $db, ?string $handle = null): int
{
    if ($handle === null) return (int)$db->query('SELECT COUNT(*) c FROM memories')->fetchAll()[0]['c'];
    $st = $db->prepare('SELECT COUNT(*) c FROM memories WHERE handle = ?');
    $st->execute([$handle]);
    return (int)$st->fetchAll()[0]['c'];
}

// ---------------------------------------------------------------------------
// Arcs
// ---------------------------------------------------------------------------

/** The handle world-scoped arcs live under. Not a legal character handle. */
function xeric_arc_world(): string
{
    return '';
}

function xeric_arc_get(PDO $db, string $handle, string $key, ?string $default = null): ?string
{
    $st = $db->prepare('SELECT value FROM arcs WHERE handle = ? AND key = ?');
    $st->execute([$handle, $key]);
    $rows = $st->fetchAll();
    return $rows ? (string)$rows[0]['value'] : $default;
}

function xeric_arc_int(PDO $db, string $handle, string $key, int $default = 0): int
{
    $v = xeric_arc_get($db, $handle, $key);
    return $v === null ? $default : (int)$v;
}

function xeric_arc_set(PDO $db, string $handle, string $key, $value, ?int $at = null): void
{
    $at = $at ?? xeric_state_time();
    $st = $db->prepare('INSERT INTO arcs (handle, key, value, updated_at) VALUES (?, ?, ?, ?)
                        ON CONFLICT(handle, key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at');
    $st->execute([$handle, $key, (string)$value, $at]);
}

/** Seed-if-absent. The idempotent half of xeric_state_seed(). */
function xeric_arc_init(PDO $db, string $handle, string $key, $value, ?int $at = null): void
{
    $at = $at ?? xeric_state_time();
    $st = $db->prepare('INSERT OR IGNORE INTO arcs (handle, key, value, updated_at) VALUES (?, ?, ?, ?)');
    $st->execute([$handle, $key, (string)$value, $at]);
}

function xeric_arc_bump(PDO $db, string $handle, string $key, int $delta = 1, ?int $at = null): int
{
    $now = xeric_arc_int($db, $handle, $key, 0) + $delta;
    xeric_arc_set($db, $handle, $key, $now, $at);
    return $now;
}

function xeric_arc_clear(PDO $db, string $handle, string $key): void
{
    $st = $db->prepare('DELETE FROM arcs WHERE handle = ? AND key = ?');
    $st->execute([$handle, $key]);
}

/** All of one person's arcs, key => value. */
function xeric_arcs_for(PDO $db, string $handle): array
{
    $st = $db->prepare('SELECT key, value FROM arcs WHERE handle = ? ORDER BY key');
    $st->execute([$handle]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string)$r['key']] = (string)$r['value'];
    return $out;
}

/** One arc across everybody, handle => value. How a board gets its rows. */
function xeric_arcs_by_key(PDO $db, string $key): array
{
    $st = $db->prepare('SELECT handle, value FROM arcs WHERE key = ? ORDER BY handle');
    $st->execute([$key]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string)$r['handle']] = (string)$r['value'];
    return $out;
}

/** Arcs whose key starts with a prefix, for one person. key => value. */
function xeric_arcs_prefixed(PDO $db, string $handle, string $prefix): array
{
    // ESCAPE is not optional: '_' is a LIKE wildcard, and arc keys are full of them.
    $st = $db->prepare("SELECT key, value FROM arcs WHERE handle = ? AND key LIKE ? ESCAPE '\\' ORDER BY key");
    $st->execute([$handle, str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefix) . '%']);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(string)$r['key']] = (string)$r['value'];
    return $out;
}

function xeric_arcs_count(PDO $db): int
{
    return (int)$db->query('SELECT COUNT(*) c FROM arcs')->fetchAll()[0]['c'];
}

// ---------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------

/**
 * @param bool $onSpine was this hour about what this world is keeping quiet?
 *        Stored, because a title written for a spine hour is not something the
 *        protected character may be shown a week later.
 */
function xeric_event_add(PDO $db, string $title, int $worldEpoch, ?string $place, array $participants, string $prose = '', ?int $at = null, bool $onSpine = false, string $overheard = ''): int
{
    $at = $at ?? xeric_state_time();
    $st = $db->prepare('INSERT INTO events (title, world_epoch, place, participants, prose, created_at, on_spine, overheard) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$title, $worldEpoch, $place, json_encode(array_values($participants), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $prose, $at, $onSpine ? 1 : 0, $overheard]);
    return (int)$db->lastInsertId();
}

/** Newest first — this is history being read backwards, not a prompt block. */
function xeric_events_recent(PDO $db, int $limit = 10): array
{
    $st = $db->prepare('SELECT * FROM events ORDER BY world_epoch DESC, id DESC LIMIT ?');
    $st->execute([$limit]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['participants'] = $r['participants'] !== null ? (json_decode((string)$r['participants'], true) ?: []) : [];
        // A bool on the way out, the same shape a sweep hands its caller, so a
        // wall that reads it does not have to know it came from a column.
        $r['on_spine'] = (int)($r['on_spine'] ?? 0) === 1;
    }
    return $rows;
}

function xeric_events_count(PDO $db): int
{
    return (int)$db->query('SELECT COUNT(*) c FROM events')->fetchAll()[0]['c'];
}

// ---------------------------------------------------------------------------
// world_state — singletons
// ---------------------------------------------------------------------------

function xeric_world_state_get(PDO $db, string $key, ?string $default = null): ?string
{
    $st = $db->prepare('SELECT value FROM world_state WHERE key = ?');
    $st->execute([$key]);
    $rows = $st->fetchAll();
    return $rows ? (string)$rows[0]['value'] : $default;
}

function xeric_world_state_set(PDO $db, string $key, $value, ?int $at = null): void
{
    $at = $at ?? xeric_state_time();
    $st = $db->prepare('INSERT INTO world_state (key, value, updated_at) VALUES (?, ?, ?)
                        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at');
    $st->execute([$key, (string)$value, $at]);
}

function xeric_world_state_all(PDO $db): array
{
    $out = [];
    foreach ($db->query('SELECT key, value FROM world_state ORDER BY key')->fetchAll() as $r) {
        $out[(string)$r['key']] = (string)$r['value'];
    }
    return $out;
}

/**
 * Remove a singleton outright. Absent and deleted must be the same state.
 *
 * The store went years without a delete because nothing legitimate un-happened:
 * guards accumulate, watermarks only rise. Rewind (engine/rewind.php) is what
 * changed that — un-happening an hour means its `sweep:<size>:<n>` guard and its
 * `why:event:<id>` trail must not merely be blanked but GONE, because half the
 * engine distinguishes "no row" from "a row that says nothing" (the sweep guard
 * check is `!== null`, and a blanked guard would keep the window unsweepable
 * forever). Deleting a key that is not there is a no-op, not an error: the
 * caller is declaring a state, not performing surgery.
 */
function xeric_world_state_delete(PDO $db, string $key): void
{
    $st = $db->prepare('DELETE FROM world_state WHERE key = ?');
    $st->execute([$key]);
}

/**
 * The world clock offset, in seconds. The demo's "skip to evening" writes here;
 * every other caller just asks for xeric_clock_epoch() and gets a world time.
 *
 * These three are the STORE, not the clock. They will move the offset anywhere,
 * including backwards and including a year, because a store that argued with its
 * caller would be unusable from a migration or a test. The policy — forward only,
 * one jump at a time — lives in clock.php, and everything that is not a test or a
 * repair tool goes through there. The one production caller that moves it
 * backwards is engine/rewind.php, and it earns the exception by moving
 * everything else with it: the offset only rewinds under a manifest that names
 * every event, memory and message being un-happened alongside it.
 */
function xeric_clock_offset(PDO $db): int
{
    return (int)xeric_world_state_get($db, 'clock_offset', '0');
}

function xeric_clock_offset_set(PDO $db, int $seconds, ?int $at = null): int
{
    xeric_world_state_set($db, 'clock_offset', $seconds, $at);
    return $seconds;
}

/**
 * When this world stopped, in REAL time, or 0 if it is running.
 *
 * The pause is stored as the wall-clock moment it began rather than as a flag,
 * because resuming has to know how long the world was away — and asking that
 * question of a boolean is how a paused world comes back a fortnight in the
 * future, which is the exact thing pausing was for.
 */
function xeric_clock_paused_at(PDO $db): int
{
    return (int)xeric_world_state_get($db, 'clock_paused_at', '0');
}

/**
 * Real time + the world's offset — unless the world is stopped, in which case
 * the moment it stopped, plus the offset.
 *
 * EVERY read of world time in the engine comes through here, which is what makes
 * pausing one function instead of an audit. Sweeps, prompts, the room line, the
 * story snake, proactive cooldowns and every world_epoch ever written all ask
 * this question, so a world that answers "it is still Saturday 19:04" answers it
 * to all of them at once and nothing downstream needs to know why.
 */
function xeric_clock_epoch(PDO $db, ?int $realNow = null): int
{
    $stopped = xeric_clock_paused_at($db);
    $now = $stopped > 0 ? $stopped : ($realNow ?? xeric_state_time());
    return $now + xeric_clock_offset($db);
}

// ---------------------------------------------------------------------------
// Seeding
// ---------------------------------------------------------------------------

/**
 * Bring a fresh world up to its template's starting values. Arcs only —
 * conversations, messages, memories and events are LIVED, and a seeder that
 * wrote them would be baking history, which is a world-content job, not this one.
 *
 * Idempotent by construction: every write is INSERT OR IGNORE, so a second call
 * on a world where trust has moved leaves trust where it moved to.
 *
 * The conventions it establishes (there is no `arcs` block in the schema doc,
 * so these are the engine's):
 *   arcs[handle]['trust']              character.trust_start ?? 0
 *   arcs[handle]['economy.<key>']      economy.start ?? 0, for per-character counters
 *   arcs[handle][<k>]                  character.arcs{} — explicit author overrides
 *   arcs['']['needle']                 world_mood.axis.ordinary ?? 0
 *   world_state['clock_offset']        0
 */
function xeric_state_seed(PDO $db, array $template, ?int $at = null): void
{
    $at = $at ?? xeric_state_time();

    $perCharacter = [];
    foreach ((array)($template['economies'] ?? []) as $eco) {
        $key = (string)($eco['key'] ?? '');
        if ($key === '') continue;
        // "per-character" is the doc's word; anything else is a world-level ledger.
        if ((string)($eco['counter'] ?? 'per-character') === 'per-character') {
            $perCharacter[$key] = (int)($eco['start'] ?? 0);
        } else {
            xeric_arc_init($db, xeric_arc_world(), 'economy.' . $key, (int)($eco['start'] ?? 0), $at);
        }
    }

    foreach ((array)($template['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        xeric_arc_init($db, $h, 'trust', (int)($c['trust_start'] ?? 0), $at);
        foreach ($perCharacter as $key => $start) {
            xeric_arc_init($db, $h, 'economy.' . $key, $start, $at);
        }
        foreach ((array)($c['arcs'] ?? []) as $k => $v) {
            xeric_arc_init($db, $h, (string)$k, $v, $at);
        }
    }

    xeric_arc_init($db, xeric_arc_world(), 'needle', (int)($template['world_mood']['axis']['ordinary'] ?? 0), $at);

    if (xeric_world_state_get($db, 'clock_offset') === null) xeric_world_state_set($db, 'clock_offset', 0, $at);
    if (xeric_world_state_get($db, 'seeded_at') === null)    xeric_world_state_set($db, 'seeded_at', $at, $at);
}

/**
 * Arcs → the shape xeric_render_economy() wants for $state.
 *
 * Lives here rather than in the renderer because it is a read of the store, and
 * because the renderer must stay a pure function of (template, viewer, state).
 * Boon debts follow one more arc convention: `boon.<key>` holds the world epoch
 * the boon goes stale at, or 0 for a boon that never does.
 *
 * $epoch drops boons that have already gone stale, and does nothing else. In
 * particular it never fills in `expires_in_hours`: this block is assembled into
 * the STATIC half of a prompt, and a countdown that ticks every turn would drag
 * a whole system prompt out of cache with it. A UI that wants to show hours-left
 * can compute it from the same arcs.
 */
function xeric_state_counters(PDO $db, array $template, string $viewerHandle, ?int $epoch = null): array
{
    $names = [];
    foreach ((array)($template['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '') $names[$h] = (string)($c['display_name'] ?? $h);
    }

    // THE LEDGERS THAT MOVE ON THEIR OWN, walked up to today before they are
    // read. `daily_system` has been rendered into system prompts as "It moves
    // every day whether or not anyone touches it" since economies existed, and
    // nothing moved it — a character was told, as canon, about motion that was
    // not happening. Read-triggered and idempotent by day index, same as the
    // fuses and the debt fade: nothing happens here because time passed, only
    // because somebody looked and time HAD passed.
    if ($epoch !== null) {
        require_once __DIR__ . '/ledger.php';
        xeric_ledger_day($db, $template, ['epoch' => $epoch]);
    }

    $counters = [];
    foreach ((array)($template['economies'] ?? []) as $eco) {
        $key = (string)($eco['key'] ?? '');
        if ($key === '') continue;
        $values = xeric_arcs_by_key($db, 'economy.' . $key);

        $board = [];
        foreach ($names as $h => $name) {                 // cast order, not row order
            if (!array_key_exists($h, $values)) continue;
            $board[] = ['handle' => $h, 'name' => $name, 'n' => (int)$values[$h]];
        }
        $counters[$key] = [
            'viewer_count' => array_key_exists($viewerHandle, $values) ? (int)$values[$viewerHandle] : null,
            'board'        => $board,
        ];
    }

    $due = [];
    foreach (xeric_arcs_prefixed($db, $viewerHandle, 'boon.') as $k => $v) {
        $expires = (int)$v;
        if ($expires > 0 && $epoch !== null && $expires <= $epoch) continue;   // stale, and simply gone
        $due[] = ['key' => substr($k, strlen('boon.'))];
    }

    return ['counters' => $counters, 'boons_due' => $due];
}
