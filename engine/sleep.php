<?php
/**
 * Xeric — sleep. What a world that has run for months does with its memories.
 *
 * A character who has lived a year has a few thousand memories and a prompt
 * that can carry twelve. Without this the twelve are simply the newest twelve,
 * and everything older than a fortnight is gone from their head as surely as
 * if it had been deleted — only quietly. Sleep is the other answer: once a
 * world-night, in the dreams window, the old memories are read back and folded
 * into a handful of DURABLE FACTS, and the facts are what the prompt carries.
 *
 * ── WHAT IT IS NOT ──────────────────────────────────────────────────────────
 *
 * NOT A DELETION. Sources are never deleted and never rewritten. A memory that
 * went into a fact gets a row in `sleep_links` (its consolidated_into) and
 * stops RENDERING while the fact renders. Everything else that reads memories
 * — the extract's dedupe, rooms, the narrator, the inspector — still sees all
 * of them. xeric_sleep_chain() gives back the fact and every memory it stands
 * for, which is the seam why.php reads.
 *
 * NOT A SUMMARY OF THE BIBLE. The bible is the fixed prefix of every prompt and
 * the one anchoring reminder the persona-drift research says resets drift
 * where compaction does not (FUTURE.md, Appendix D). Sleep never reads it into
 * its prompt, never writes the template, and renders below it — so it can never
 * shorten it.
 *
 * NOT THE MODEL'S DECISION. The model proposes facts in a fixed JSON shape:
 *
 *     {"facts": [{"fact": "…", "about": ["handle"], "since": "YYYY-MM-DD", "from": [memory ids]}]}
 *
 * and code disposes (xeric_sleep_dispose): a fact is kept only when it can be
 * traced, word by word, conservatively, to the memories it cites; when it
 * quotes nothing the holder is walled from (the continuity oracle's own
 * six-word test, engine/continuity/oracle.php); when it is shorter than what it
 * replaces; and it inherits the STRICTEST wall and gossip grade of its sources.
 * `since` is the earliest source's world date, whatever the model wrote.
 *
 * ── WHAT IS NEVER CONSOLIDATED (pinned) ─────────────────────────────────────
 *
 * Promises (a construct memory, or any memory carrying an expectation's quote),
 * confidences (a memory carrying a confidence's words, told or telling),
 * story spills and closures, lessons, death lines, gossip, and anything whose
 * meta names a construct key. Those are things the engine points at; folding
 * them into prose would take the handle away from the code that holds it.
 *
 * ── SPEND ───────────────────────────────────────────────────────────────────
 *
 * One model call per character per night, tag 'sleep', through the same stub
 * seam as every other call. It asks the budget first (xeric_budget_allows, for
 * the whole prompt it is about to send — sleep can wait a night, so it must
 * never be the call that crosses the line) and never runs on a costly machine
 * unless the template says `sleep.remote: true`. A refusal writes nothing.
 *
 * ── TEMPLATE ────────────────────────────────────────────────────────────────
 *
 *     "sleep": {"enabled": true, "after_days": 14, "remote": false, "window": "01:00-06:00"}
 *
 * every key optional; the window defaults to `proactive.dreams.window`, then to
 * 01:00-06:00. The heart calls xeric_sleep_pass() every tick; the pass decides
 * for itself whether this tick is inside tonight's window and not yet slept.
 *
 * Tables are created here, not in state.php's migrations: a world that never
 * sleeps never grows them, and a prompt build in a world without them reads
 * exactly what it read before this file existed.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/world.php';
require_once __DIR__ . '/walls.php';
require_once __DIR__ . '/death.php';
require_once __DIR__ . '/budget.php';

const XERIC_SLEEP_AFTER_DAYS  = 14;
const XERIC_SLEEP_WINDOW      = '01:00-06:00';
/** At most this many sources in one night's call for one person; the rest wait for the next night. */
const XERIC_SLEEP_MAX_SOURCES = 40;
/** A fact is one sentence. */
const XERIC_SLEEP_FACT_MAX    = 220;
/** Of the prompt's memory cap, at most 1/this may be facts — the newest raw memories always keep the rest. */
const XERIC_SLEEP_CAP_SHARE   = 3;
/** A memory offered and not consolidated rests this many nights before it is offered again (unless nothing else is left). */
const XERIC_SLEEP_REST_NIGHTS = 7;
/** Offered this many times and never consolidated: the model has said what it thinks of it. Kept raw, never offered again. */
const XERIC_SLEEP_OFFERS_MAX  = 3;

// ---------------------------------------------------------------------------
// Configuration and tables
// ---------------------------------------------------------------------------

/** @return array{enabled:bool,after_days:float,remote:bool,window:string} */
function xeric_sleep_config(array $t): array
{
    $s = is_array($t['sleep'] ?? null) ? $t['sleep'] : [];
    $days = is_numeric($s['after_days'] ?? null) ? max(0.0, (float)$s['after_days']) : (float)XERIC_SLEEP_AFTER_DAYS;
    $win  = trim((string)($s['window'] ?? $t['proactive']['dreams']['window'] ?? $t['dreams']['window'] ?? ''));
    return [
        'enabled'    => ($s['enabled'] ?? true) !== false,
        'after_days' => $days,
        'remote'     => ($s['remote'] ?? false) === true,
        'window'     => $win !== '' ? $win : XERIC_SLEEP_WINDOW,
    ];
}

/** Create sleep's tables. Idempotent; called only by the writer. */
function xeric_sleep_migrate(PDO $db): void
{
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sleep_facts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            handle     TEXT    NOT NULL,                -- whose head
            text       TEXT    NOT NULL,                -- one durable fact
            about      TEXT    NOT NULL DEFAULT '[]',   -- JSON handles: cast, place keys, 'user'
            since      INTEGER NOT NULL,                -- world epoch of the earliest source
            night      TEXT    NOT NULL,                -- the world-night that wrote it (YYYY-MM-DD)
            meta       TEXT,                            -- JSON: the inherited wall grade, counts
            created_at INTEGER NOT NULL
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sleep_facts_handle ON sleep_facts(handle, id)');
    // memory_id is the key: a memory is consolidated into ONE fact, ever.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sleep_links (
            memory_id INTEGER PRIMARY KEY,              -- memories.id: its consolidated_into
            fact_id   INTEGER NOT NULL,
            handle    TEXT    NOT NULL
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sleep_links_fact ON sleep_links(fact_id)');
    // What was offered and left: the rotation's memory (xeric_sleep_candidates).
    // A row per memory a night's call was shown and did not consolidate. Not a
    // column on memories, for the same reason the other two are tables here: a
    // world that never sleeps never grows it.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sleep_offers (
            memory_id  INTEGER PRIMARY KEY,             -- memories.id
            handle     TEXT    NOT NULL,
            times      INTEGER NOT NULL DEFAULT 0,      -- nights it was offered and left out
            offered_at TEXT    NOT NULL,                -- sleep_offered_at: the last such night (YYYY-MM-DD)
            kept_raw   INTEGER NOT NULL DEFAULT 0       -- sleep_kept_raw: offered XERIC_SLEEP_OFFERS_MAX times, never again
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sleep_offers_handle ON sleep_offers(handle)');
}

/**
 * This person's offer record: memory id => {times, offered_at, kept_raw}. A
 * world that slept before the rotation existed has no table yet and reads as
 * nothing ever offered — which is the truth about it.
 *
 * @return array<int,array{times:int,offered_at:string,kept_raw:bool}>
 */
function xeric_sleep_offers(PDO $db, string $handle): array
{
    if ((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sleep_offers'")->fetchColumn() !== 1) return [];
    $q = $db->prepare('SELECT memory_id, times, offered_at, kept_raw FROM sleep_offers WHERE handle = ?');
    $q->execute([$handle]);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[(int)$r['memory_id']] = ['times' => (int)$r['times'], 'offered_at' => (string)$r['offered_at'], 'kept_raw' => (int)$r['kept_raw'] === 1];
    }
    return $out;
}

/** Whole nights from one night's date to another's; an unreadable date is long ago. */
function xeric_sleep_nights_between(string $from, string $to): int
{
    try {
        $a = new DateTimeImmutable($from . ' 00:00', new DateTimeZone('UTC'));
        $b = new DateTimeImmutable($to . ' 00:00', new DateTimeZone('UTC'));
    } catch (Throwable $e) { return PHP_INT_MAX; }
    return intdiv($b->getTimestamp() - $a->getTimestamp(), 86400);
}

/** Has this world ever slept? A read, never a write — prompt.php asks it. */
function xeric_sleep_has_tables(PDO $db): bool
{
    // Not cached: one indexed read of the schema, and a PDO's object id is
    // reused once it is freed, so a cache here would answer for the wrong world.
    return (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('sleep_facts','sleep_links')")->fetchColumn() === 2;
}

// ---------------------------------------------------------------------------
// Reads: what renders, and the chain
// ---------------------------------------------------------------------------

/** Every fact this person holds, oldest `since` first, meta and about decoded. */
function xeric_sleep_facts(PDO $db, string $handle): array
{
    if (!xeric_sleep_has_tables($db)) return [];
    $q = $db->prepare('SELECT f.*, (SELECT COUNT(*) FROM sleep_links l WHERE l.fact_id = f.id) AS sources
                       FROM sleep_facts f WHERE f.handle = ? ORDER BY f.since, f.id');
    $q->execute([$handle]);
    $rows = $q->fetchAll();
    foreach ($rows as &$r) {
        $r['about'] = json_decode((string)$r['about'], true) ?: [];
        $r['meta']  = $r['meta'] !== null ? (json_decode((string)$r['meta'], true) ?: []) : [];
        $r['sources'] = (int)$r['sources'];
    }
    return $rows;
}

/** The ids of this person's memories that a fact now stands for. */
function xeric_sleep_consolidated_ids(PDO $db, string $handle): array
{
    if (!xeric_sleep_has_tables($db)) return [];
    $q = $db->prepare('SELECT memory_id FROM sleep_links WHERE handle = ?');
    $q->execute([$handle]);
    return array_map('intval', array_column($q->fetchAll(), 'memory_id'));
}

/**
 * The newest $limit memories that still render: xeric_memories_for() minus the
 * consolidated ones. Oldest → newest, like xeric_memories_for().
 */
function xeric_sleep_memories_for(PDO $db, string $handle, int $limit): array
{
    if (!xeric_sleep_has_tables($db)) return xeric_memories_for($db, $handle, $limit);
    $q = $db->prepare('SELECT * FROM memories WHERE handle = ?
                       AND id NOT IN (SELECT memory_id FROM sleep_links WHERE handle = ?)
                       ORDER BY id DESC LIMIT ?');
    $q->execute([$handle, $handle, max(0, $limit)]);
    $rows = array_reverse($q->fetchAll());
    foreach ($rows as &$r) $r['meta'] = $r['meta'] !== null ? (json_decode((string)$r['meta'], true) ?: []) : [];
    return $rows;
}

/**
 * The provenance of one fact: the fact, and every memory it stands for, in the
 * order they were lived. The seam why.php reads. null when there is no such fact.
 *
 * @return ?array{fact:array,sources:array,missing:int[]} missing: linked ids whose row is gone
 */
function xeric_sleep_chain(PDO $db, int $factId): ?array
{
    if (!xeric_sleep_has_tables($db)) return null;
    $q = $db->prepare('SELECT * FROM sleep_facts WHERE id = ?');
    $q->execute([$factId]);
    $f = $q->fetchAll()[0] ?? null;
    if ($f === null) return null;
    $f['about'] = json_decode((string)$f['about'], true) ?: [];
    $f['meta']  = $f['meta'] !== null ? (json_decode((string)$f['meta'], true) ?: []) : [];

    $q = $db->prepare('SELECT l.memory_id, m.* FROM sleep_links l LEFT JOIN memories m ON m.id = l.memory_id
                       WHERE l.fact_id = ? ORDER BY l.memory_id');
    $q->execute([$factId]);
    $src = []; $missing = [];
    foreach ($q->fetchAll() as $r) {
        if ($r['id'] === null) { $missing[] = (int)$r['memory_id']; continue; }
        unset($r['memory_id']);
        $r['meta'] = $r['meta'] !== null ? (json_decode((string)$r['meta'], true) ?: []) : [];
        $src[] = $r;
    }
    return ['fact' => $f, 'sources' => $src, 'missing' => $missing];
}

// ---------------------------------------------------------------------------
// The night
// ---------------------------------------------------------------------------

/**
 * Which world-night this moment belongs to, or null outside the window.
 *
 * A window that wraps midnight (22:00-04:00) is one night: 23:00 on the 14th
 * and 01:00 on the 15th are both the night of the 15th, so a pass guarded per
 * night runs once, not twice.
 */
function xeric_sleep_night(array $t, array $now): ?string
{
    $spec = xeric_sleep_config($t)['window'];
    if (!preg_match('/^\s*(\d{1,2}:\d{2})\s*(?:-|–|to)\s*(\d{1,2}:\d{2})\s*$/u', $spec, $m)) return null;
    $f  = xeric_world_minutes(strlen($m[1]) === 4 ? '0' . $m[1] : $m[1]);
    $to = xeric_world_minutes(strlen($m[2]) === 4 ? '0' . $m[2] : $m[2]);
    $at = xeric_world_minutes((string)($now['hhmm'] ?? ''));
    if ($f === null || $to === null || $at === null || $f === $to) return null;

    $day = substr((string)($now['iso'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = gmdate('Y-m-d', (int)($now['epoch'] ?? 0));
    if ($to > $f) return ($at >= $f && $at < $to) ? $day : null;
    if ($at >= $f) return (new DateTimeImmutable($day . ' 00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    return $at < $to ? $day : null;
}

/** The guard key: one per person per night. */
function xeric_sleep_guard(string $night, string $handle): string
{
    return 'sleep:night:' . $night . ':' . $handle;
}

/** The living cast, in template order. */
function xeric_sleep_cast(array $t, PDO $db): array
{
    $dead = array_flip(xeric_dead_handles($db));
    $out  = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '' && !isset($dead[$h])) $out[] = $h;
    }
    return $out;
}

/** Is there anybody left to sleep tonight, right now? Cheap: the heart asks it every tick. */
function xeric_sleep_due(array $t, PDO $db, array $now): bool
{
    if (!xeric_sleep_config($t)['enabled']) return false;
    $night = xeric_sleep_night($t, $now);
    if ($night === null) return false;
    foreach (xeric_sleep_cast($t, $db) as $h) {
        if (xeric_world_state_get($db, xeric_sleep_guard($night, $h)) === null) return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// What may be consolidated
// ---------------------------------------------------------------------------

/**
 * Why this memory must never be folded into a fact, or '' when it may be.
 * $ctx is xeric_sleep_pins_context()'s answer for the same person.
 */
function xeric_sleep_pinned(array $row, array $ctx): string
{
    $source = (string)($row['source'] ?? '');
    if (in_array($source, ['construct', 'spill', 'story', 'confide', 'lesson', 'death', 'pin'], true)) return 'source:' . $source;
    $meta = is_array($row['meta'] ?? null) ? $row['meta'] : (json_decode((string)($row['meta'] ?? ''), true) ?: []);
    foreach (['expect', 'gossip', 'confide', 'told', 'beat', 'story', 'lesson', 'pinned'] as $k) {
        if (array_key_exists($k, $meta) && $meta[$k] !== false && $meta[$k] !== null) return 'meta:' . $k;
    }
    if ((string)($meta['kind'] ?? '') === 'death') return 'death';

    $words = xeric_wall_words((string)($row['text'] ?? ''));
    foreach ($ctx['promises'] as $q) if (xeric_wall_quotes($words, $q)) return 'promise';
    foreach ($ctx['confidences'] as $q) if (xeric_wall_quotes($words, $q)) return 'confidence';
    return '';
}

/**
 * What a construct still points at, for one person, as word lists: every
 * expectation's quote they hold (a promise, whatever its state — promises are
 * pinned), and the words of every confidence they were told or told somebody.
 *
 * BOTH KINDS OF CONFIDENCE. Cast to cast lives under `told.*`
 * (xeric_confides_for); what somebody asked the PLAYER to keep lives under
 * `confided.*` on the teller (xeric_confide_told_player), and that is the one
 * the engine points at hardest — a break is detected by matching its words,
 * and a grudge is hung on it. Reading only the first let a memory carrying a
 * secret told to the player be folded into prose, and the row that watches
 * for it lost the words it watches with. The player's are read from every
 * teller, not just this person: whoever heard it repeated carries the same
 * words, and pinning too much costs a night's consolidation of one memory.
 */
function xeric_sleep_pins_context(array $t, PDO $db, string $handle): array
{
    require_once __DIR__ . '/constructs.php';
    require_once __DIR__ . '/confide.php';
    $promises = [];
    foreach (xeric_expects_for($db, $handle) as $e) {
        foreach (['quote', 'what'] as $k) {
            $w = xeric_wall_words((string)($e[$k] ?? ''));
            if (count($w) >= 3) $promises[] = $w;
        }
    }
    $conf = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        foreach (xeric_confides_for($db, $h) as $row) {
            if ($h !== $handle && (string)($row['by'] ?? '') !== $handle) continue;
            foreach (['what', 'quote'] as $k) {
                $w = xeric_wall_words((string)($row[$k] ?? ''));
                if (count($w) >= 3) $conf[] = $w;
            }
        }
        foreach (xeric_confide_told_player($db, $h) as $row) {
            foreach (['what', 'quote'] as $k) {
                $w = xeric_wall_words((string)($row[$k] ?? ''));
                if (count($w) >= 3) $conf[] = $w;
            }
        }
    }
    return ['promises' => $promises, 'confidences' => $conf];
}

/**
 * The memories one person may consolidate tonight: older than after_days (by
 * world time), not consolidated, not pinned. Oldest first, at most $limit.
 *
 * AND IN ROTATION. Oldest-first alone is a queue with a jam at its head: the
 * memories the model leaves out of every `from` (or whose facts the disposer
 * drops) are still the oldest tomorrow, so they were offered first every night
 * forever and a newer old memory never got a turn — a person whose fortieth
 * oldest memory was dull slept on the same forty for the rest of the world's
 * life. So a memory offered and left rests XERIC_SLEEP_REST_NIGHTS nights
 * before it is offered again, unless nothing else is left; and one offered
 * XERIC_SLEEP_OFFERS_MAX times and never consolidated is kept raw — the model
 * has answered about it three times — and not offered again. Kept raw is not
 * lost: it is an ordinary unconsolidated memory, read by everything that reads
 * memories, exactly as if sleep had never looked at it. When only resting
 * memories are left, the least-offered and longest-rested go first.
 *
 * $night names tonight (the pass hands its own); null reads it off $now.
 *
 * @return array{rows:array,pinned:array<int,string>,resting:int,kept_raw:int}
 */
function xeric_sleep_candidates(array $t, PDO $db, string $handle, array $now, int $limit = XERIC_SLEEP_MAX_SOURCES,
                                ?string $night = null): array
{
    $night ??= xeric_sleep_night($t, $now) ?? substr((string)($now['iso'] ?? gmdate('Y-m-d', (int)($now['epoch'] ?? 0))), 0, 10);
    $offers = xeric_sleep_offers($db, $handle);
    $cut = (int)($now['epoch'] ?? 0) - (int)round(xeric_sleep_config($t)['after_days'] * 86400);
    $done = array_flip(xeric_sleep_consolidated_ids($db, $handle));
    // Bound as an INTEGER: execute([...]) binds strings, and in SQLite every
    // integer sorts below every string, so `epoch < '123'` is true of all rows.
    $q = $db->prepare('SELECT * FROM memories WHERE handle = ? AND COALESCE(world_epoch, created_at) < ? ORDER BY COALESCE(world_epoch, created_at), id');
    $q->bindValue(1, $handle);
    $q->bindValue(2, $cut, PDO::PARAM_INT);
    $q->execute();
    $ctx = xeric_sleep_pins_context($t, $db, $handle);
    $fresh = []; $resting = []; $pinned = []; $raw = 0;
    foreach ($q->fetchAll() as $r) {
        $id = (int)$r['id'];
        if (isset($done[$id])) continue;
        $o = $offers[$id] ?? null;
        if ($o !== null && $o['kept_raw']) { $raw++; continue; }
        // Once the head of the queue is full, only the pins still need reading.
        if (count($fresh) >= $limit && $o === null) continue;
        $r['meta'] = $r['meta'] !== null ? (json_decode((string)$r['meta'], true) ?: []) : [];
        $why = xeric_sleep_pinned($r, $ctx);
        if ($why !== '') { $pinned[$id] = $why; continue; }
        if (trim((string)$r['text']) === '') continue;
        if ($o !== null && xeric_sleep_nights_between($o['offered_at'], $night) < XERIC_SLEEP_REST_NIGHTS) {
            $resting[] = $r + ['_offer' => $o];
            continue;
        }
        if (count($fresh) < $limit) $fresh[] = $r;
    }
    if ($fresh !== []) {
        $rows = $fresh;
    } else {
        // Nothing else is left: the resting, least-offered and longest-rested first, oldest among equals.
        usort($resting, fn($a, $b) => [$a['_offer']['times'], $a['_offer']['offered_at']] <=> [$b['_offer']['times'], $b['_offer']['offered_at']]);
        $rows = array_map(function ($r) { unset($r['_offer']); return $r; }, array_slice($resting, 0, $limit));
    }
    return ['rows' => $rows, 'pinned' => $pinned, 'resting' => $fresh !== [] ? count($resting) : max(0, count($resting) - count($rows)),
            'kept_raw' => $raw];
}

// ---------------------------------------------------------------------------
// Names and groups
// ---------------------------------------------------------------------------

/**
 * The words a name is matched and traced by: the full name, and the given name
 * (xeric_world_name_forms, the one honorific list) when it is long enough to be
 * a word nobody else's sentence happens to contain.
 */
function xeric_sleep_name_forms(string $name): array
{
    $forms = xeric_world_name_forms($name);
    if (isset($forms[1]) && mb_strlen($forms[1]) < 3) array_pop($forms);
    return $forms;
}

/** Every entity a memory could be about: handle => [kind, forms]. 'user' is the person at the centre. */
function xeric_sleep_entities(array $t): array
{
    static $memo = [];
    $k = md5(json_encode([$t['cast'] ?? [], $t['places'] ?? [], $t['user']['name'] ?? '']));
    if (isset($memo[$k])) return $memo[$k];
    $out = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '') $out[$h] = ['person', xeric_sleep_name_forms((string)($c['display_name'] ?? $h))];
    }
    foreach ((array)($t['places'] ?? []) as $p) {
        $key = (string)($p['key'] ?? '');
        if ($key === '') continue;
        $forms = [(string)($p['name'] ?? $key)];
        // "the Bluebird Diner" is also "the Bluebird" — but only a "the" name is
        // shortened: "Beck Hardware" shortened is Harlan's surname.
        if (preg_match('/^the\s+(\S{4,})\s+\S/iu', (string)($p['name'] ?? ''), $pm)) $forms[] = $pm[1];
        $out[$key] = ['place', array_values(array_unique(array_filter($forms)))];
    }
    $user = trim((string)($t['user']['name'] ?? ''));
    if ($user !== '') $out['user'] = ['user', xeric_sleep_name_forms($user)];
    return $memo[$k] = $out;
}

/** Who and where one memory names, in the order the text names them, meta after. */
function xeric_sleep_names(array $t, array $row): array
{
    $text = (string)($row['text'] ?? '');
    $found = [];
    foreach (xeric_sleep_entities($t) as $h => [$kind, $forms]) {
        foreach ($forms as $f) {
            if ($f === '') continue;
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($f, '/') . '(?![\p{L}\p{N}])/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
                $found[$h] = min($found[$h] ?? PHP_INT_MAX, (int)$m[0][1]);
            }
        }
    }
    asort($found);
    $out = array_keys($found);
    $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
    foreach ((array)($meta['with'] ?? []) as $w) if (is_string($w) && !in_array($w, $out, true)) $out[] = $w;
    $pl = $meta['place'] ?? null;
    if (is_string($pl) && $pl !== '' && !in_array($pl, $out, true)) $out[] = $pl;
    return array_map('strval', $out);
}

/**
 * Group one person's candidates by the people and places they name.
 *
 * A memory joins the group of the first OTHER person it names; failing that,
 * the first place; failing that, the person at the centre; failing that, the
 * holder alone. A group's `about` is everything its members name.
 *
 * @return array<string,array{key:string,about:string[],rows:array}>
 */
function xeric_sleep_group(array $t, string $handle, array $rows): array
{
    $ent = xeric_sleep_entities($t);
    $groups = [];
    foreach ($rows as $r) {
        $names = xeric_sleep_names($t, $r);
        $key = null;
        foreach ($names as $n) if (($ent[$n][0] ?? '') === 'person' && $n !== $handle) { $key = $n; break; }
        if ($key === null) foreach ($names as $n) if (($ent[$n][0] ?? '') === 'place') { $key = $n; break; }
        if ($key === null && in_array('user', $names, true)) $key = 'user';
        $key ??= $handle;
        $groups[$key] ??= ['key' => $key, 'about' => [], 'rows' => []];
        $groups[$key]['rows'][] = $r;
        foreach ($names as $n) if (!in_array($n, $groups[$key]['about'], true)) $groups[$key]['about'][] = $n;
    }
    return $groups;
}

// ---------------------------------------------------------------------------
// The call
// ---------------------------------------------------------------------------

/** The prompt. No bible, no secrets, nothing but this person's own old memories and the names in them. */
function xeric_sleep_prompt(array $t, string $handle, array $groups): array
{
    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    $ent = xeric_sleep_entities($t);
    $name = xeric_world_name($t, $handle);

    $named = [];
    foreach ($groups as $g) foreach ($g['about'] as $a) $named[$a] = true;
    $who = [];
    foreach (array_keys($named) as $a) {
        if (!isset($ent[$a])) continue;
        $who[] = $a . ' = ' . $ent[$a][1][0] . ($ent[$a][0] === 'place' ? ' (a place)' : ($a === 'user' ? ' (the person at the centre)' : ''));
    }

    $u = ['WHOSE: ' . $name . ' (' . $handle . ')', 'NAMES: ' . implode('; ', $who), ''];
    foreach ($groups as $g) {
        $u[] = 'GROUP about: ' . implode(', ', $g['about']);
        foreach ($g['rows'] as $r) {
            $when = (new DateTimeImmutable('@' . (int)($r['world_epoch'] ?? $r['created_at'])))->setTimezone($tz)->format('Y-m-d');
            $u[] = '[' . (int)$r['id'] . '] (' . $when . ') ' . trim(preg_replace('/\s+/u', ' ', (string)$r['text']) ?? '');
        }
        $u[] = '';
    }
    $u[] = 'Return {"facts": [{"fact": "…", "about": ["handle"], "since": "YYYY-MM-DD", "from": [ids]}]}.';

    return [
        ['role' => 'system', 'content' =>
            "You are the sleep pass of a world simulation. You read one person's old memories and write the DURABLE FACTS "
          . "they leave behind — what is still true about the people and places in them.\n"
          . "- A fact is one plain sentence under 30 words, third person. Not prose, not a mood, not a story.\n"
          . "- Use only what the memories say. Add no names, numbers, places or causes they do not contain.\n"
          . "- `from` lists the ids of the memories the fact is made from; each memory feeds one fact at most.\n"
          . "- `about` lists the handles (from NAMES) the fact is about.\n"
          . "- Fewer facts than memories. A memory that says nothing lasting: leave it out of every `from`.\n"
          . "JSON only. No prose outside the JSON."],
        ['role' => 'user', 'content' => implode("\n", $u)],
    ];
}

// ---------------------------------------------------------------------------
// Disposal: code decides what is true
// ---------------------------------------------------------------------------

/** Words too common to count as a claim. */
function xeric_sleep_stopwords(): array
{
    static $s = null;
    return $s ??= array_flip(['about', 'after', 'again', 'still', 'their', 'there', 'these', 'those', 'which', 'while',
        'would', 'could', 'should', 'being', 'before', 'other', 'every', 'never', 'always', 'where', 'because', 'since',
        'through', 'without', 'within', 'thing', 'things', 'something', 'anything', 'nothing', 'someone', 'somebody',
        'anybody', 'nobody', 'everyone', 'everybody', 'often', 'once', 'later', 'first', 'times', 'person', 'people',
        'knows', 'known', 'remembers', 'remembered', 'remember', 'there', 'theirs', 'himself', 'herself', 'themselves',
        'among', 'around', 'until', 'whose', 'usually', 'tends', 'likes', 'liked']);
}

/**
 * Can every word-level claim in this fact be traced to its sources? '' when it
 * can, else why not. Conservative on purpose: a fact it wrongly drops costs a
 * night's consolidation of a few memories (they stay, and render, as they
 * were); a fact it wrongly keeps is a false memory with a provenance chain.
 *
 *   - every `about` handle is a known entity and is named by some source;
 *   - every number, and every capitalised word not opening a sentence, is a
 *     word of the sources or of the names of what the sources name;
 *   - at least half of the fact's content words (five letters or more, not
 *     stopwords) are words of the sources.
 */
function xeric_sleep_untraced(array $t, string $handle, string $fact, array $about, array $sources): string
{
    $ent   = xeric_sleep_entities($t);
    $named = [];
    $vocab = [];
    foreach ($sources as $s) {
        foreach (xeric_sleep_names($t, $s) as $n) $named[$n] = true;
        foreach (xeric_wall_words((string)$s['text']) as $w) $vocab[$w] = true;
    }
    if ($about === []) return 'about names nobody';
    foreach ($about as $a) {
        if (!isset($ent[$a])) return "about '$a' is nobody in this world";
        if (!isset($named[$a])) return "about '$a' is not named by any source";
    }
    // Names of whoever the sources name, and the holder's own, may be spelled in full.
    $nameWords = [];
    foreach (array_merge(array_keys($named), [$handle]) as $n) {
        foreach ($ent[$n][1] ?? [] as $f) foreach (xeric_wall_words($f) as $w) $nameWords[$w] = true;
    }

    if (!preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’]*/u', $fact, $m, PREG_OFFSET_CAPTURE)) return 'the fact has no words';
    $content = 0; $traced = 0;
    foreach ($m[0] as $i => [$tok, $off]) {
        $low = mb_strtolower(preg_replace("/['’]s$/u", '', $tok) ?? $tok);
        $low = trim(preg_replace("/['’]/u", ' ', $low) ?? $low);
        $parts = xeric_wall_words($low);
        $isNum = (bool)preg_match('/\p{N}/u', $tok);
        $before = rtrim(substr($fact, 0, (int)$off));
        $opens = $i === 0 || $before === '' || preg_match('/[.!?:;"“]$/u', $before);
        $isCap = !$opens && preg_match('/^\p{Lu}/u', $tok) && $tok !== 'I';
        if ($isNum || $isCap) {
            foreach ($parts as $p) {
                if (!isset($vocab[$p]) && !isset($nameWords[$p])) return "'" . $tok . "' is in no source";
            }
        }
        foreach ($parts as $p) {
            if (mb_strlen($p) < 5 || isset(xeric_sleep_stopwords()[$p])) continue;
            $content++;
            if (isset($vocab[$p]) || isset($nameWords[$p])) $traced++;
        }
    }
    if ($content > 0 && $traced * 2 < $content) return "only $traced of $content content words are in the sources";
    return '';
}

/**
 * What this viewer is walled from, as the oracle lists it (cb_forbidden), read
 * ONCE per viewer per pass. $memo is the pass's: the oracle's own memo is keyed
 * by an md5 of the whole cast and walls, which it recomputes on every call, so
 * asking it per viewer × per source × per fact made the grading the most
 * expensive thing sleep did — more than the model call it was grading.
 * xeric_sleep_forbidden_reads() counts the real reads (the test's seam).
 */
function xeric_sleep_forbidden(array $t, string $viewer, array &$memo): array
{
    if (!isset($memo['forbidden'][$viewer])) {
        require_once __DIR__ . '/continuity/oracle.php';
        xeric_sleep_forbidden_reads(1);
        $memo['forbidden'][$viewer] = cb_forbidden($t, $viewer);
    }
    return $memo['forbidden'][$viewer];
}

/** How many times sleep has read cb_forbidden in this process; $add counts one more. */
function xeric_sleep_forbidden_reads(int $add = 0): int
{
    static $n = 0;
    return $n += $add;
}

/**
 * The words this viewer is walled from that a text quotes: the oracle's own
 * test (cb_quotes, with no scenario allowances), against the list read once.
 */
function xeric_sleep_quotes_walled(array $t, string $viewer, string $text, ?array &$memo = null): array
{
    $memo ??= [];
    $hay = xeric_wall_words($text);
    if ($hay === []) return [];
    $flat = ' ' . implode(' ', $hay) . ' ';
    $hit  = [];
    foreach (xeric_sleep_forbidden($t, $viewer, $memo) as $f) {
        if (isset($hit[$f['path']])) continue;
        if ($f['words'] === [] || !str_contains($flat, ' ' . $f['words'][0] . ' ') && count($f['words']) <= XERIC_WALL_QUOTE_RUN) continue;
        if (xeric_wall_quotes($hay, $f['words'])) $hit[$f['path']] = true;
    }
    return array_keys($hit);
}

/**
 * The strictest grade of a set of sources: what the fact made of them inherits.
 *   gossip_grade  false if any source is not gossip grade
 *   walls         every wall key any source carries (meta wall / walls)
 *   walled_from   every viewer some source quotes a wall for (the oracle's test)
 *   rating_min    the highest rating any source is gated at
 *
 * WHO READS IT: xeric_prompt_recall() will not render a fact to a holder in
 * its walled_from, or below its rating_min — a fact inherits a wall so that it
 * can be withheld by it. Each source's verdict per viewer is kept in $memo, so
 * a pass grades a memory once, however many facts are proposed from it.
 */
function xeric_sleep_grade(array $t, array $sources, ?array &$memo = null): array
{
    $memo ??= [];
    $gossip = true; $walls = []; $from = []; $rank = -1; $rating = null;
    $viewers = array_values(array_filter(array_map(fn($c) => (string)($c['handle'] ?? ''), (array)($t['cast']['characters'] ?? []))));
    foreach ($sources as $s) {
        $meta = is_array($s['meta'] ?? null) ? $s['meta'] : [];
        if (array_key_exists('gossip_grade', $meta) && !$meta['gossip_grade']) $gossip = false;
        foreach (array_merge((array)($meta['walls'] ?? []), isset($meta['wall']) ? [(string)$meta['wall']] : []) as $w) {
            if ((string)$w !== '') $walls[(string)$w] = true;
        }
        if (isset($meta['rating_min']) && xeric_rating_rank((string)$meta['rating_min']) > $rank) {
            $rank = xeric_rating_rank((string)$meta['rating_min']);
            $rating = (string)$meta['rating_min'];
        }
        $sk = isset($s['id']) ? 'id:' . (int)$s['id'] : 'text:' . md5((string)$s['text']);
        if (!isset($memo['walled'][$sk])) {
            $memo['walled'][$sk] = [];
            foreach ($viewers as $v) if (xeric_sleep_quotes_walled($t, $v, (string)$s['text'], $memo) !== []) $memo['walled'][$sk][] = $v;
        }
        foreach ($memo['walled'][$sk] as $v) $from[$v] = true;
    }
    $out = ['gossip_grade' => $gossip, 'walls' => array_keys($walls), 'walled_from' => array_keys($from)];
    if ($rating !== null) $out['rating_min'] = $rating;
    return $out;
}

/**
 * What the model proposed, disposed. Pure: reads the template and the sources,
 * writes nothing.
 *
 * @param array $raw  the model's object: {facts:[{fact,about,since,from}]}
 * @param array $rows the candidates sent, as rows
 * @param ?array $memo the pass's wall reads (xeric_sleep_forbidden), shared across people
 * @return array{kept:array<int,array{text:string,about:string[],since:int,from:int[],meta:array}>,dropped:array<int,array{fact:string,why:string}>}
 */
function xeric_sleep_dispose(array $t, string $handle, array $raw, array $rows, ?array &$memo = null): array
{
    $memo ??= [];
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    $used = []; $kept = []; $dropped = [];
    $drop = function (string $fact, string $why) use (&$dropped): void { $dropped[] = ['fact' => mb_substr($fact, 0, 120), 'why' => $why]; };

    foreach ((array)($raw['facts'] ?? []) as $f) {
        if (!is_array($f)) { $drop('', 'not an object'); continue; }
        $text = trim(preg_replace('/\s+/u', ' ', (string)($f['fact'] ?? '')) ?? '');
        if (mb_strlen($text) < 8)                    { $drop($text, 'too short to be a fact'); continue; }
        if (mb_strlen($text) > XERIC_SLEEP_FACT_MAX) { $drop($text, 'longer than one sentence'); continue; }

        $from = [];
        foreach ((array)($f['from'] ?? []) as $id) if (is_numeric($id)) $from[(int)$id] = true;
        $from = array_keys($from);
        sort($from);
        if ($from === []) { $drop($text, 'cites no memory'); continue; }
        $bad = array_values(array_filter($from, fn($id) => !isset($byId[$id])));
        if ($bad !== []) { $drop($text, 'cites memory ' . $bad[0] . ', which was not offered'); continue; }
        $twice = array_values(array_filter($from, fn($id) => isset($used[$id])));
        if ($twice !== []) { $drop($text, 'cites memory ' . $twice[0] . ', already in another fact'); continue; }

        // The walls first: whatever else is wrong with a fact, quoting what its
        // holder is walled from is the reason that must show in the report.
        $walled = xeric_sleep_quotes_walled($t, $handle, $text, $memo);
        if ($walled !== []) { $drop($text, 'quotes a wall: ' . $walled[0]); continue; }
        if (function_exists('xeric_age_floor') && xeric_age_floor($t, [$handle], [$text]) !== null) { $drop($text, 'the age floor'); continue; }

        $sources = array_map(fn($id) => $byId[$id], $from);
        $about = array_values(array_unique(array_map('strval', array_filter((array)($f['about'] ?? []), 'is_string'))));
        $why = xeric_sleep_untraced($t, $handle, $text, $about, $sources);
        if ($why !== '') { $drop($text, 'untraceable: ' . $why); continue; }

        $srcLen = array_sum(array_map(fn($s) => mb_strlen((string)$s['text']), $sources));
        if (mb_strlen($text) >= $srcLen) { $drop($text, 'no shorter than what it replaces'); continue; }

        $since = min(array_map(fn($s) => (int)($s['world_epoch'] ?? $s['created_at']), $sources));
        $meta = xeric_sleep_grade($t, $sources, $memo) + ['sources' => count($from)];
        $ms = trim((string)($f['since'] ?? ''));
        if ($ms !== '') $meta['model_since'] = mb_substr($ms, 0, 32);
        foreach ($from as $id) $used[$id] = true;
        $kept[] = ['text' => $text, 'about' => $about, 'since' => $since, 'from' => $from, 'meta' => $meta];
    }
    return ['kept' => $kept, 'dropped' => $dropped];
}

/**
 * Store one person's kept facts and their links, the night's guard, and what
 * was offered and left (the rotation), together or not at all. $offered is the
 * ids the call was shown; any that no kept fact cites is counted an offer, and
 * marked kept raw at XERIC_SLEEP_OFFERS_MAX.
 */
function xeric_sleep_write(PDO $db, string $handle, array $kept, string $night, array $summary, ?int $at = null,
                           array $offered = []): array
{
    $at ??= xeric_state_time();
    xeric_sleep_migrate($db);
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    $ids = [];
    try {
        $f = $db->prepare('INSERT INTO sleep_facts (handle, text, about, since, night, meta, created_at) VALUES (?,?,?,?,?,?,?)');
        $l = $db->prepare('INSERT INTO sleep_links (memory_id, fact_id, handle) VALUES (?,?,?)');
        foreach ($kept as $k) {
            $f->execute([$handle, $k['text'], json_encode($k['about'], JSON_UNESCAPED_UNICODE), $k['since'], $night,
                         json_encode($k['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at]);
            $fid = (int)$db->lastInsertId();
            foreach ($k['from'] as $mid) $l->execute([$mid, $fid, $handle]);
            $ids[] = $fid;
        }
        $used = [];
        foreach ($kept as $k) foreach ($k['from'] as $mid) $used[(int)$mid] = true;
        // The ceiling is written into the SQL, not bound: execute([...]) binds
        // strings, and in SQLite an integer never reaches a string, so
        // `times + 1 >= '3'` is false forever and nothing was ever kept raw.
        $max = (int)XERIC_SLEEP_OFFERS_MAX;
        $o = $db->prepare('INSERT INTO sleep_offers (memory_id, handle, times, offered_at, kept_raw) VALUES (?, ?, 1, ?, ' . ($max <= 1 ? 1 : 0) . ')
                           ON CONFLICT(memory_id) DO UPDATE SET times = times + 1, offered_at = excluded.offered_at,
                           kept_raw = CASE WHEN times + 1 >= ' . $max . ' THEN 1 ELSE 0 END');
        foreach (array_unique(array_map('intval', $offered)) as $mid) {
            if (isset($used[$mid])) continue;
            $o->execute([$mid, $handle, $night]);
        }
        xeric_world_state_set($db, xeric_sleep_guard($night, $handle), json_encode($summary + ['facts' => $ids], JSON_UNESCAPED_SLASHES), $at);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $ids;
}

// ---------------------------------------------------------------------------
// The pass
// ---------------------------------------------------------------------------

/**
 * One night's sleep for everybody who has not slept tonight.
 *
 * Call it every heart tick: outside tonight's window, or once everyone has
 * slept, it returns at once without a read of any memory. $endpoint is the
 * model the heart uses; null resolves the CLI default (XERIC_LOCAL_BASE).
 *
 * $opts: force (ignore the window; the night is today's date), night (name it),
 *        handles (only these), dry_run (dispose but write nothing, no guard).
 *
 * @return array{ran:bool,night:?string,why:string,refused:string,calls:int,characters:array}
 */
function xeric_sleep_pass(array $t, PDO $db, array $now, ?array $endpoint = null, array $opts = []): array
{
    $out = ['ran' => false, 'night' => null, 'why' => '', 'refused' => '', 'calls' => 0, 'characters' => []];
    $cfg = xeric_sleep_config($t);
    if (!$cfg['enabled']) { $out['why'] = 'sleep.enabled is false'; return $out; }

    $night = isset($opts['night']) ? (string)$opts['night']
           : (!empty($opts['force']) ? substr((string)($now['iso'] ?? gmdate('Y-m-d', (int)($now['epoch'] ?? 0))), 0, 10)
           : xeric_sleep_night($t, $now));
    if ($night === null) { $out['why'] = 'not inside the night window (' . $cfg['window'] . ')'; return $out; }
    $out['night'] = $night;

    $todo = [];
    foreach (xeric_sleep_cast($t, $db) as $h) {
        if (isset($opts['handles']) && !in_array($h, (array)$opts['handles'], true)) continue;
        if (xeric_world_state_get($db, xeric_sleep_guard($night, $h)) !== null) continue;
        $todo[] = $h;
    }
    if ($todo === []) { $out['why'] = 'everybody has slept tonight'; return $out; }

    if ($endpoint === null) {
        require_once __DIR__ . '/llm.php';
        $endpoint = xeric_llm_cli_endpoint([]);
    }
    $costly = xeric_budget_llm_costly($endpoint);
    if ($costly && !$cfg['remote']) {
        $out['refused'] = 'sleep runs on the owner\'s own machine; this model is a costly one and sleep.remote is not true';
        return $out;
    }
    require_once __DIR__ . '/chat.php';     // xeric_chat_json, xeric_age_floor
    $out['ran'] = true;
    $dry = !empty($opts['dry_run']);
    $memo = [];                             // the walls, read once per viewer for the whole pass

    foreach ($todo as $h) {
        $c = xeric_sleep_candidates($t, $db, $h, $now, XERIC_SLEEP_MAX_SOURCES, $night);
        $rep = ['candidates' => count($c['rows']), 'pinned' => count($c['pinned']), 'resting' => $c['resting'], 'kept_raw' => $c['kept_raw'],
                'groups' => 0, 'kept' => [], 'dropped' => [], 'note' => ''];
        if ($c['rows'] === []) {
            $rep['note'] = 'nothing old enough to sleep on';
            if (!$dry) xeric_sleep_write($db, $h, [], $night, ['candidates' => 0, 'pinned' => count($c['pinned'])]);
            $out['characters'][$h] = $rep;
            continue;
        }
        $groups = xeric_sleep_group($t, $h, $c['rows']);
        $rep['groups'] = count($groups);
        $msgs = xeric_sleep_prompt($t, $h, $groups);
        $max  = 120 + 60 * count($c['rows']);

        // THE BUDGET, for the whole of what is about to be sent. Refused: nothing
        // written, no guard — the next tick in the window, or the next night, asks again.
        $chars = array_sum(array_map(fn($m) => strlen((string)$m['content']), $msgs));
        $v = xeric_budget_allows($t, $db, $now, 'tokens', intdiv($chars + 3, 4) + $max, $costly);
        if (!$v['ok']) {
            $out['refused'] = (string)$v['reason'];
            $out['characters'][$h] = $rep + ['refused' => true];
            break;
        }

        try {
            $out['calls']++;
            $raw = xeric_chat_json($endpoint, 'sleep', $msgs, ['temperature' => 0.2, 'max_tokens' => $max, 'tag' => 'sleep']);
        } catch (Throwable $e) {
            $rep['note'] = 'the model failed: ' . $e->getMessage();
            $out['characters'][$h] = $rep;
            continue;                    // nothing written, no guard: tried again next tick in the window
        }
        $d = xeric_sleep_dispose($t, $h, $raw, $c['rows'], $memo);
        $rep['dropped'] = $d['dropped'];
        if (!$dry) {
            $rep['kept'] = xeric_sleep_write($db, $h, $d['kept'], $night, [
                'candidates' => count($c['rows']), 'pinned' => count($c['pinned']),
                'kept' => count($d['kept']), 'dropped' => count($d['dropped']),
            ], null, array_map(fn($r) => (int)$r['id'], $c['rows']));
        } else {
            $rep['kept'] = array_map(fn($k) => $k['text'], $d['kept']);
        }
        $out['characters'][$h] = $rep;
    }
    return $out;
}
