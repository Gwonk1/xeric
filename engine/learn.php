<?php
/**
 * Xeric — learning. The world getting better at being itself.
 *
 * Everything else in this engine is fixed at forge time. The template decides who
 * these people are, what may happen to them and how often anybody's phone buzzes,
 * and none of it ever moves again: a world on its ninetieth day behaves exactly
 * like a world on its first. Meanwhile the engine already knows a great deal about
 * the person living in it — which of the cast they answer, whose messages sit
 * unread until the next skip, which kinds of hour they never once went and asked
 * about, how long their replies are, and above all which sentences they went back
 * and retyped by hand — and throws every bit of it away.
 *
 * ── TWO LAYERS, AND THE ORDER IS THE POINT ────────────────────────────────
 *
 * A learning loop built entirely on a model is a learning loop that stops the
 * moment the GPU is busy, which on this hardware is most of the time. So the
 * distil pass has a floor under it:
 *
 *   COUNTING, which needs nothing and cannot fail. Per-character reply rate,
 *   per-event-kind engagement, average reply length. It is arithmetic over rows
 *   this file already has, it is stored in arcs like everything else the engine
 *   counts, and it is what actually moves behaviour: sweeps.php weights the kinds
 *   of hour it produces, proactive.php decides who has earned another message.
 *
 *   WORDS, which need a model and are allowed to be absent. One cheap pass over
 *   the raw crumbs — mostly the hand edits, because a retyped sentence is the
 *   only correction in the whole system that is not an inference — producing one
 *   to three short lessons in plain language that ride the prompt afterwards.
 *
 * A world whose model is down still learns. It just learns numbers.
 *
 * ── WHAT KEEPS THIS FROM EATING THE WORLD ─────────────────────────────────
 *
 *  1. LESSONS ARE CAPPED. A notebook that grows forever eventually IS the
 *     prompt, and a character whose system message is nine paragraphs of advice
 *     about the user has stopped being a person. Six per bucket, one line each,
 *     oldest pushed out.
 *  2. NOTHING IS EVER EXTINGUISHED. Every weight has a floor. A world that only
 *     ever does the thing you liked last week is a world that has stopped being
 *     able to surprise you, which is the only thing it was for.
 *  3. A CRUMB IS READ ONCE. Signals are marked processed by the pass that reads
 *     them, including a pass whose model call failed — the counting already
 *     consumed them, and a batch that retries forever blocks every later one.
 *  4. LEARNING IS GARNISH. Every write in here is allowed to fail quietly. A
 *     chat turn that died because the world could not write down that it had
 *     happened would be the tail wagging the dog.
 *  5. A LESSON CAN BE STRUCK, NEVER REWRITTEN. Eviction is by age — six newer
 *     lessons push out a seventh — so a habit that stopped in week two used to
 *     ride the prompt until week five buried it. Now the distil pass may also
 *     remove ONE lesson the evidence in front of it contradicts, and that is
 *     the whole of its editorial power (xeric_lessons_strike says why the
 *     other shape, rewriting the notebook each pass, was refused). A strike
 *     leaves a trace so the inspector can say what went, and why.
 *  6. THERE IS A SWITCH, AND OFF STOPS THE WRITER, NOT THE DIARY. One
 *     world_state key, read engine-side (xeric_learn_enabled) so every caller
 *     honours the same hand on it. Off, the world behaves like one that never
 *     learned — no distil, default weights, default reach, no lessons in any
 *     prompt — while the raw crumbs keep landing unread, so the week it sat
 *     out is still on the record the day it is switched back on.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/world.php';
require_once __DIR__ . '/chat.php';      // the model seam, and the deduper
require_once __DIR__ . '/trust.php';     // what these same crumbs mean to the person in them

/** How many lessons one bucket (the world, or one character) may hold. */
const XERIC_LEARN_MAX_LESSONS = 6;

/** One lesson is one line. Anything longer is an essay nobody reads twice. */
const XERIC_LEARN_MAX_CHARS = 140;

/** How alike two lessons may be before the newer one is the older one again. */
const XERIC_LEARN_DEDUPE = 0.55;

/** Crumbs read by one distil pass. */
const XERIC_LEARN_BATCH = 24;

/** Fewer unprocessed crumbs than this and the model pass is not worth a call. */
const XERIC_LEARN_MIN_BATCH = 3;

/**
 * Observations before a tally is allowed to move anything.
 *
 * Two skips is not a preference. Under this, every weight below is exactly 1.0
 * and every caller behaves precisely as it did before this file existed.
 */
const XERIC_LEARN_CONFIDENT = 3;

/** The floor and ceiling on how far engagement may bend what happens. */
const XERIC_LEARN_KIND_FLOOR = 0.25;
const XERIC_LEARN_KIND_CEIL  = 2.5;

/** The same, for how often one person reaches out. */
const XERIC_LEARN_REACH_FLOOR = 0.5;
const XERIC_LEARN_REACH_CEIL  = 1.5;

/** How long a crumb that has been read is kept. Evidence, not history. */
const XERIC_LEARN_KEEP_DAYS = 30;

/** world_state key: what the last skip left hanging (see xeric_learn_settle). */
const XERIC_LEARN_PENDING = 'learn.pending';

/** world_state key: the kill switch. Present and non-empty = learning is off. */
const XERIC_LEARN_OFF = 'learn.off';

/** signals.kind of a struck-lesson trace. Not a crumb — see xeric_lessons_strike(). */
const XERIC_LEARN_STRUCK = 'struck';

// ---------------------------------------------------------------------------
// The kill switch
// ---------------------------------------------------------------------------

/**
 * May this world learn right now? On unless its owner has said otherwise.
 *
 * WHAT "OFF" MEANS, PRECISELY — because the obvious reading is wrong. The fear
 * this switch answers (power.php flips it; sweep-cli's --no-learn is its older
 * sibling) is a bad distil pass WRITING into prompts and a bad weight steering
 * the sweeps. So off stops exactly the parts that APPLY what was learned:
 *
 *   - the distil refuses whole (xeric_lessons_distil): no counting, no model
 *     call, not one crumb marked read;
 *   - the weights come back empty and the reach comes back 1.0, so sweeps.php
 *     and proactive.php behave as they did before this file existed;
 *   - the lessons leave every prompt (xeric_lessons_for) — a switch that only
 *     stopped the NEXT bad write would leave the last one steering.
 *
 * What off does NOT do is blind the world. xeric_signal_add and the
 * pend/settle pair are deliberately ungated: the crumbs keep landing, unread,
 * because a paused learner that also went blind could never explain the week
 * it missed. Nothing is deleted in either direction — flip it back and the
 * lessons are where they were and the backlog is read.
 */
function xeric_learn_enabled(PDO $db): bool
{
    try {
        return trim((string)xeric_world_state_get($db, XERIC_LEARN_OFF, '')) === '';
    } catch (Throwable $e) {
        // Fail closed. A learner that cannot check whether it is allowed to
        // write does not write — and every caller treats "off" as "behave as
        // if nothing was ever learned", which is the one safe default here.
        return false;
    }
}

/** Flip it. Returns the state the world now stands in (true = learning). */
function xeric_learn_switch(PDO $db, bool $on, ?int $at = null): bool
{
    if ($on) {
        // Deleted, not blanked: absent and deleted must be the same state
        // (state.php says why), and "on" is the absence of the switch.
        xeric_world_state_delete($db, XERIC_LEARN_OFF);
    } else {
        xeric_world_state_set($db, XERIC_LEARN_OFF, '1', $at);
    }
    return xeric_learn_enabled($db);
}

// ---------------------------------------------------------------------------
// Raw signals
// ---------------------------------------------------------------------------

/**
 * The crumbs a world is allowed to write down, and what each one means.
 *
 * Deliberately short. Every kind here is something the user DID, observed where
 * it happened; nothing in this list is an opinion about them.
 */
function xeric_learn_kinds(): array
{
    return [
        'reply'   => 'the user answered somebody, who, how long the answer was, how long they took',
        'ignored' => 'somebody texted first and the world moved on without an answer',
        'skipped' => 'an event went by and the user never went and asked anybody about it',
        'dwell'   => 'the user opened a thread and read it',
        'edit'    => 'the user retyped something by hand, the one correction that is not a guess',
    ];
}

/**
 * Record one raw signal. Returns its id, or 0 when nothing was written.
 *
 * NEVER THROWS. This is called from inside a chat turn and from the tail of a
 * skip, and a world that cannot write down what just happened is still a world —
 * a turn that failed because of the bookkeeping around it is not. An unknown
 * kind is a 0 for the same reason: a caller inventing a sixth kind gets silence
 * here rather than an exception on somebody's screen.
 *
 * @param array $data handle, subject, n, lag, note, world_epoch, at
 */
function xeric_signal_add(PDO $db, string $kind, array $data): int
{
    if (!isset(xeric_learn_kinds()[$kind])) return 0;

    $at   = (int)($data['at'] ?? xeric_state_time());
    $note = trim(preg_replace('/\s+/u', ' ', (string)($data['note'] ?? '')) ?? '');

    try {
        $st = $db->prepare('INSERT INTO signals (kind, handle, subject, n, lag, note, processed, created_at, world_epoch)
                            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)');
        $st->execute([
            $kind,
            mb_substr((string)($data['handle'] ?? ''), 0, 80),
            mb_substr((string)($data['subject'] ?? ''), 0, 120),
            max(0, (int)($data['n'] ?? 0)),
            max(0, (int)($data['lag'] ?? 0)),
            mb_substr($note, 0, 400),
            $at,
            isset($data['world_epoch']) ? (int)$data['world_epoch'] : null,
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/** The crumbs nobody has read yet, oldest first. */
function xeric_signals_unprocessed(PDO $db, int $limit = XERIC_LEARN_BATCH): array
{
    $st = $db->prepare('SELECT * FROM signals WHERE processed = 0 ORDER BY id LIMIT ?');
    $st->execute([max(1, $limit)]);
    return $st->fetchAll();
}

/**
 * The newest crumbs, read or not, newest first. The inspector's window.
 *
 * Strike traces are excluded: they are about the notebook rather than the
 * user, they read badly beside "Walt answered Ruth", and they have a reader of
 * their own (xeric_lessons_struck).
 */
function xeric_signals_recent(PDO $db, int $limit = 20): array
{
    $st = $db->prepare('SELECT * FROM signals WHERE kind != ? ORDER BY id DESC LIMIT ?');
    $st->execute([XERIC_LEARN_STRUCK, max(1, $limit)]);
    return $st->fetchAll();
}

/** Read once, and only once. */
function xeric_signals_mark(PDO $db, array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if ($ids === []) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("UPDATE signals SET processed = 1 WHERE id IN ($in)");
    $st->execute($ids);
}

function xeric_signals_count(PDO $db, ?string $kind = null, bool $openOnly = false): int
{
    $sql  = 'SELECT COUNT(*) c FROM signals WHERE 1=1';
    $args = [];
    if ($kind !== null)  { $sql .= ' AND kind = ?'; $args[] = $kind; }
    if ($openOnly)       { $sql .= ' AND processed = 0'; }
    $st = $db->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchAll()[0]['c'];
}

/** Is there enough new evidence to be worth a pass? */
function xeric_learn_due(PDO $db, int $min = XERIC_LEARN_MIN_BATCH): bool
{
    if (!xeric_learn_enabled($db)) return false;    // a pass that would refuse is not due
    return xeric_signals_count($db, null, true) >= max(1, $min);
}

/** Crumbs that have been read and have aged out. Evidence, not history. */
function xeric_learn_prune(PDO $db, ?int $at = null): int
{
    $at = $at ?? xeric_state_time();
    $st = $db->prepare('DELETE FROM signals WHERE processed = 1 AND created_at < ?');
    $st->execute([$at - XERIC_LEARN_KEEP_DAYS * 86400]);
    return $st->rowCount();
}

// ---------------------------------------------------------------------------
// The two signals that can only be known in hindsight
// ---------------------------------------------------------------------------

/**
 * Write down what this skip left hanging, to be judged at the start of the next.
 *
 * "Ignored" and "skipped" are not events; they are the ABSENCE of events, and an
 * absence cannot be observed at the moment it starts. So the tail of a skip
 * records what was on offer — the hours that happened, and whoever picked up
 * their phone — with a watermark on the message table, and xeric_learn_settle()
 * decides in hindsight whether any of it was taken up.
 *
 * @param array      $events from xeric_sweep_run()/xeric_sweep_catchup()
 * @param array|null $ping   from xeric_proactive_check(), or null
 */
function xeric_learn_pend(PDO $db, array $events, ?array $ping, int $epoch, ?int $at = null): void
{
    $rows = [];
    foreach ($events as $e) {
        $kind = (string)($e['kind'] ?? '');
        $who  = (array)($e['participants'] ?? array_keys((array)($e['memories'] ?? [])));
        $who  = array_values(array_filter(array_map('strval', $who), fn($h) => $h !== ''));
        if ($kind === '' || $who === []) continue;
        $rows[] = ['id' => (int)($e['id'] ?? 0), 'kind' => $kind, 'who' => $who];
    }

    $pending = ['epoch' => $epoch, 'mark' => xeric_learn_mark($db), 'events' => $rows];

    $handle = (string)($ping['handle'] ?? '');
    if ($handle !== '') {
        // proactive.php already remembers the exact line that, left unanswered,
        // blocks the next one. The same message id is what makes "ignored"
        // measurable, so it is read rather than passed in.
        $pending['ping'] = ['handle' => $handle,
                            'message_id' => xeric_arc_int($db, $handle, 'proactive.last_message_id', 0)];
    }

    try {
        xeric_world_state_set($db, XERIC_LEARN_PENDING,
            json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);
    } catch (Throwable $e) {
        // garnish
    }
}

/**
 * Judge what the last skip left hanging, now that hindsight is available.
 *
 * An event whose people were never spoken to afterwards was skipped past; one
 * that sent the user to somebody's thread was engaged with. A ping that is still
 * the newest thing in its thread was ignored. Clears the pending row either way:
 * an hour is judged once.
 *
 * @return array{engaged:int,skipped:int,ignored:int}
 */
function xeric_learn_settle(PDO $db, ?int $epoch = null): array
{
    $out = ['engaged' => 0, 'skipped' => 0, 'ignored' => 0];

    $raw = (string)xeric_world_state_get($db, XERIC_LEARN_PENDING, '');
    if (trim($raw) === '') return $out;
    $p = json_decode($raw, true);
    xeric_world_state_set($db, XERIC_LEARN_PENDING, '');
    if (!is_array($p)) return $out;

    $mark = (int)($p['mark'] ?? 0);

    foreach ((array)($p['events'] ?? []) as $e) {
        $kind = (string)($e['kind'] ?? '');
        if ($kind === '') continue;
        $who = array_values(array_map('strval', (array)($e['who'] ?? [])));

        $spoke = '';
        foreach ($who as $h) {
            if (xeric_learn_said_since($db, $h, $mark) > 0) { $spoke = $h; break; }
        }

        if ($spoke !== '') {
            xeric_signal_add($db, 'dwell', ['handle' => $spoke, 'subject' => $kind,
                'world_epoch' => $epoch, 'note' => 'went and asked somebody who was there']);
            $out['engaged']++;
        } else {
            xeric_signal_add($db, 'skipped', ['handle' => (string)($who[0] ?? ''), 'subject' => $kind,
                'world_epoch' => $epoch, 'note' => 'nobody who was there was spoken to afterwards']);
            $out['skipped']++;
        }
    }

    $ping = (array)($p['ping'] ?? []);
    $h    = (string)($ping['handle'] ?? '');
    $mid  = (int)($ping['message_id'] ?? 0);
    if ($h !== '' && $mid > 0 && xeric_learn_said_since($db, $h, $mid) === 0) {
        xeric_signal_add($db, 'ignored', ['handle' => $h, 'world_epoch' => $epoch,
            'note' => 'texted first and was still waiting when the world moved on']);
        $out['ignored']++;
    }

    return $out;
}

/** The newest message in the world. The watermark a pending skip is judged against. */
function xeric_learn_mark(PDO $db): int
{
    return (int)$db->query('SELECT COALESCE(MAX(id), 0) m FROM messages')->fetchAll()[0]['m'];
}

/** How many times the user has said anything to this character since $afterId. */
function xeric_learn_said_since(PDO $db, string $handle, int $afterId): int
{
    if ($handle === '') return 0;
    $st = $db->prepare('SELECT COUNT(*) c FROM messages m
                        JOIN conversations c2 ON c2.id = m.conversation_id
                        WHERE c2.handle = ? AND m.role = \'user\' AND m.id > ?');
    $st->execute([$handle, $afterId]);
    return (int)$st->fetchAll()[0]['c'];
}

/** A thread opened and read. The cheapest thing a person does that means anything. */
function xeric_learn_read(PDO $db, string $handle, int $unread = 0, ?int $epoch = null, ?int $at = null): int
{
    return xeric_signal_add($db, 'dwell', ['handle' => $handle, 'n' => $unread,
        'world_epoch' => $epoch, 'at' => $at]);
}

// ---------------------------------------------------------------------------
// Layer one: counting
// ---------------------------------------------------------------------------

/**
 * Fold a batch of crumbs into the running counters. No model, so no failure mode.
 *
 * Counters are cumulative and live in arcs, which is where everything else the
 * engine counts lives. That is what lets the batch be small and the crumbs be
 * thrown away afterwards: a rate is not recomputed from history, it is carried.
 *
 * @return array{characters:array<string,int>,kinds:array<string,int>}
 */
function xeric_learn_tally_apply(PDO $db, array $rows, ?int $at = null): array
{
    $at    = $at ?? xeric_state_time();
    $chars = [];
    $kinds = [];

    foreach ($rows as $r) {
        $kind = (string)($r['kind'] ?? '');
        $h    = (string)($r['handle'] ?? '');
        $sub  = (string)($r['subject'] ?? '');

        switch ($kind) {
            // THE SAME CRUMBS, READ TWICE. learn.php has always read these as
            // evidence about what the PLAYER engages with, which is what
            // re-weights the kinds. They are also evidence about how somebody
            // is being treated, and that half was never collected: trust moved
            // only through promises, so a hundred good conversations moved it
            // nothing (engine/trust.php). One fold, two meanings.
            case 'reply':
                if ($h === '') break;
                xeric_arc_bump($db, $h, 'learn.replies', 1, $at);
                xeric_arc_bump($db, $h, 'learn.reply_chars', max(0, (int)($r['n'] ?? 0)), $at);
                xeric_arc_bump($db, $h, 'learn.reply_lag', max(0, (int)($r['lag'] ?? 0)), $at);
                xeric_trust_contact($db, $h, xeric_trust_signal('reply'), $at);
                $chars[$h] = ($chars[$h] ?? 0) + 1;
                break;

            case 'ignored':
                if ($h === '') break;
                xeric_arc_bump($db, $h, 'learn.ignored', 1, $at);
                xeric_trust_contact($db, $h, xeric_trust_signal('ignored'), $at);
                $chars[$h] = ($chars[$h] ?? 0) + 1;
                break;

            case 'dwell':
                if ($h !== '') {
                    xeric_arc_bump($db, $h, 'learn.reads', 1, $at);
                    xeric_trust_contact($db, $h, xeric_trust_signal('dwell'), $at);
                    $chars[$h] = ($chars[$h] ?? 0) + 1;
                }
                // A dwell that names an event kind came from the settle pass and
                // is the only place a kind is credited with having landed. A bare
                // dwell is somebody opening a thread, which says who they care
                // about and nothing at all about what kind of hour it was.
                if ($sub !== '') {
                    xeric_arc_bump($db, xeric_arc_world(), 'learn.kind.' . $sub . '.seen', 1, $at);
                    xeric_arc_bump($db, xeric_arc_world(), 'learn.kind.' . $sub . '.engaged', 1, $at);
                    $kinds[$sub] = ($kinds[$sub] ?? 0) + 1;
                }
                break;

            case 'skipped':
                if ($sub === '') break;
                xeric_arc_bump($db, xeric_arc_world(), 'learn.kind.' . $sub . '.seen', 1, $at);
                $kinds[$sub] = ($kinds[$sub] ?? 0) + 1;
                break;

            case 'edit':
                xeric_arc_bump($db, $h !== '' ? $h : xeric_arc_world(), 'learn.edits', 1, $at);
                if ($h !== '') $chars[$h] = ($chars[$h] ?? 0) + 1;
                break;
        }
    }

    return ['characters' => $chars, 'kinds' => $kinds];
}

/**
 * One person's numbers.
 *
 * `reply_rate` is null rather than 0 when nobody has ever offered them anything:
 * "never answered" and "never asked" are different facts and a caller that
 * confused them would silence a character who has done nothing wrong.
 *
 * @return array{replies:int,ignored:int,reads:int,edits:int,
 *               reply_rate:?float,avg_reply_chars:?int,avg_reply_lag:?int}
 */
function xeric_learn_tally(PDO $db, string $handle): array
{
    $replies = xeric_arc_int($db, $handle, 'learn.replies', 0);
    $ignored = xeric_arc_int($db, $handle, 'learn.ignored', 0);
    $offered = $replies + $ignored;

    return [
        'replies'         => $replies,
        'ignored'         => $ignored,
        'reads'           => xeric_arc_int($db, $handle, 'learn.reads', 0),
        'edits'           => xeric_arc_int($db, $handle, 'learn.edits', 0),
        'reply_rate'      => $offered > 0 ? (float)$replies / (float)$offered : null,
        'avg_reply_chars' => $replies > 0 ? (int)round(xeric_arc_int($db, $handle, 'learn.reply_chars', 0) / $replies) : null,
        'avg_reply_lag'   => $replies > 0 ? (int)round(xeric_arc_int($db, $handle, 'learn.reply_lag', 0) / $replies) : null,
    ];
}

/**
 * How each kind of hour has landed. kind => {seen, engaged, rate}.
 */
function xeric_learn_kind_rates(PDO $db): array
{
    $raw = [];
    foreach (xeric_arcs_prefixed($db, xeric_arc_world(), 'learn.kind.') as $key => $value) {
        $rest = substr($key, strlen('learn.kind.'));
        $dot  = strrpos($rest, '.');                 // kind names contain underscores, never dots
        if ($dot === false || $dot === 0) continue;
        $name  = substr($rest, 0, $dot);
        $field = substr($rest, $dot + 1);
        if ($field !== 'seen' && $field !== 'engaged') continue;
        $raw[$name][$field] = (int)$value;
    }

    $out = [];
    foreach ($raw as $name => $row) {
        $seen = (int)($row['seen'] ?? 0);
        $eng  = (int)($row['engaged'] ?? 0);
        $out[$name] = ['seen' => $seen, 'engaged' => $eng, 'rate' => $seen > 0 ? (float)$eng / (float)$seen : null];
    }
    ksort($out);
    return $out;
}

/**
 * The thumb on the scale for what kind of hour happens next. name => weight.
 *
 * Relative to the world's OWN average engagement, not to an absolute: a user who
 * follows up on one thing in ten still has favourites, and a scale anchored to
 * some fixed idea of "engaged" would flatten every one of them to the floor.
 *
 * Returns [] — meaning "nothing learned, behave exactly as you always did" —
 * until there is enough to be worth acting on. Callers rely on that: an empty
 * result is what keeps a fresh world's behaviour byte-identical to a world built
 * before this file existed.
 *
 * @param array $names the kinds the caller actually has; [] for all of them
 */
function xeric_learn_kind_weights(PDO $db, array $names = []): array
{
    // The kill switch reads as "nothing learned": the same empty answer a
    // fresh world gives, and every caller already knows what to do with it.
    if (!xeric_learn_enabled($db)) return [];

    $rates = xeric_learn_kind_rates($db);
    if ($rates === []) return [];

    $seen = 0;
    $eng  = 0;
    foreach ($rates as $r) { $seen += (int)$r['seen']; $eng += (int)$r['engaged']; }
    if ($seen < XERIC_LEARN_CONFIDENT) return [];

    $base = $seen > 0 ? $eng / $seen : 0.0;

    $out = [];
    foreach (($names !== [] ? $names : array_keys($rates)) as $name) {
        $name = (string)$name;
        $r = $rates[$name] ?? null;
        // A kind nobody has seen enough of keeps the default. Weighting a kind
        // down off one bad night is how a world talks itself into silence.
        if ($r === null || (int)$r['seen'] < XERIC_LEARN_CONFIDENT || $r['rate'] === null) continue;

        $w = $base > 0.0 ? ((float)$r['rate'] / $base) : 1.0;
        // THE FLOOR IS NOT A ROUNDING DETAIL. A kind that has never once landed
        // still happens, a quarter as often. A world that has pruned itself down
        // to the four things you engaged with last week has stopped being able to
        // surprise you, and being surprised is the entire product.
        $out[$name] = round(max(XERIC_LEARN_KIND_FLOOR, min(XERIC_LEARN_KIND_CEIL, $w)), 3);
    }
    return $out;
}

/**
 * A shuffle with a thumb on the scale. Every key goes in and every key comes
 * out, in a weighted order, without replacement.
 *
 * A WEIGHTED SHUFFLE AND NOT A RANKING, everywhere this is used. Sorting by
 * weight would mean the same kind of hour every time and the same person
 * speaking up first every time, which is how a world ends up with one character
 * and some scenery. A heavier weight buys a better chance of going first and
 * nothing more.
 *
 * When nothing is tilted it falls straight through to shuffle(): the same rolls,
 * in the same order, as its callers made before any of this existed. That is
 * what keeps a seeded test deterministic and a world that has learned nothing
 * behaving exactly as it always did.
 */
function xeric_learn_order(array $keys, array $weights): array
{
    $keys = array_values($keys);

    $tilted = false;
    foreach ($keys as $k) {
        if (abs((float)($weights[(string)$k] ?? 1.0) - 1.0) > 0.001) { $tilted = true; break; }
    }
    if (!$tilted) { shuffle($keys); return $keys; }

    $out = [];
    while ($keys !== []) {
        $total = 0.0;
        foreach ($keys as $k) $total += max(0.01, (float)($weights[(string)$k] ?? 1.0));

        $roll = (mt_rand() / mt_getrandmax()) * $total;
        $pick = count($keys) - 1;
        foreach ($keys as $i => $k) {
            $roll -= max(0.01, (float)($weights[(string)$k] ?? 1.0));
            if ($roll <= 0) { $pick = $i; break; }
        }
        $out[] = $keys[$pick];
        array_splice($keys, $pick, 1);
    }
    return $out;
}

/**
 * How much more, or less, this person reaches out. 1.0 until it is earned.
 *
 * Half of never-answered to one-and-a-half of always: clamped at both ends
 * because neither extreme is a decision anybody made. Somebody who is never
 * answered still speaks up sometimes — being ignored is not the same as being
 * gone, and a character who goes permanently quiet is a character the world has
 * deleted on the user's behalf.
 */
function xeric_learn_reach(PDO $db, string $handle): float
{
    if ($handle === '') return 1.0;
    if (!xeric_learn_enabled($db)) return 1.0;      // the switch: everybody back to default
    $t = xeric_learn_tally($db, $handle);
    if ($t['reply_rate'] === null || ($t['replies'] + $t['ignored']) < XERIC_LEARN_CONFIDENT) return 1.0;
    return round(max(XERIC_LEARN_REACH_FLOOR, min(XERIC_LEARN_REACH_CEIL, 0.5 + (float)$t['reply_rate'])), 3);
}

// ---------------------------------------------------------------------------
// Layer two: the lessons themselves
// ---------------------------------------------------------------------------

/** One bucket's lessons, oldest first. */
function xeric_lessons_read(PDO $db, string $handle): array
{
    $raw = xeric_arc_get($db, $handle, 'learn.lessons');
    if ($raw === null || trim($raw) === '') return [];
    $out = json_decode($raw, true);
    if (!is_array($out)) return [];

    $clean = [];
    foreach ($out as $l) {
        $l = trim((string)$l);
        if ($l !== '') $clean[] = $l;
    }
    return $clean;
}

/**
 * What a character should be carrying: the world's lessons, then their own.
 *
 * World first because a world-level lesson is about the person on the other end
 * of the phone and is true of everybody; a per-character one is about this
 * particular pair and gets the last word.
 */
function xeric_lessons_for(PDO $db, string $handle = ''): array
{
    // The one prompt-side gate. Switched off, the lessons LEAVE the prompt
    // rather than merely stop growing: the fear behind the switch is a bad
    // lesson already steering the cast, and a kill switch that keeps the
    // patient on the drug is a label, not a switch. The notebook itself is
    // untouched — flip it back on and every line is exactly where it was.
    if (!xeric_learn_enabled($db)) return [];

    $out = xeric_lessons_read($db, xeric_arc_world());
    if ($handle !== '' && $handle !== xeric_arc_world()) {
        foreach (xeric_lessons_read($db, $handle) as $l) $out[] = $l;
    }
    return $out;
}

/**
 * Add lessons to a bucket. Deduped against what is there, capped, oldest evicted.
 *
 * @return array the bucket as it now stands
 */
function xeric_lessons_add(PDO $db, string $handle, array $new, ?int $at = null): array
{
    $have = xeric_lessons_read($db, $handle);

    foreach ($new as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', (string)$line) ?? '');
        if ($line === '' || mb_strlen($line) < 8) continue;
        if (mb_strlen($line) > XERIC_LEARN_MAX_CHARS) {
            $line = rtrim(mb_substr($line, 0, XERIC_LEARN_MAX_CHARS)) . '…';
        }
        // The same lesson in new words is the same lesson. Without this the
        // notebook fills up with six ways of saying "he likes it short" and the
        // cap then evicts everything that was not about message length.
        if (xeric_chat_is_dupe($line, $have, XERIC_LEARN_DEDUPE)) continue;
        $have[] = $line;
    }

    if (count($have) > XERIC_LEARN_MAX_LESSONS) {
        $have = array_slice($have, count($have) - XERIC_LEARN_MAX_LESSONS);
    }

    xeric_arc_set($db, $handle, 'learn.lessons',
        json_encode(array_values($have), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);
    return $have;
}

/**
 * Strike one lesson out of a bucket, on the strength of the recent record.
 *
 * THE EXIT THAT IS NOT EVICTION. The cap evicts by AGE — six newer lessons push
 * out a seventh — so a lesson the evidence has stopped supporting used to ride
 * the prompt until enough unrelated ones happened to bury it. This is the other
 * exit: the distil pass, re-reading the notebook against the crumbs in front of
 * it, may remove a line those crumbs contradict. Deliberately narrow: the line
 * must be THERE, matched verbatim, and one strike is all a pass gets.
 *
 * WHY STRIKE, AND NOT A NOTEBOOK REWRITE. The other shape — hand the model all
 * six lines and the fresh evidence, keep whatever comes back — was considered
 * and refused. The notebook idiom is append-mostly so the prompt stays stable:
 * six unchanged lines cost one prefix-cache re-read a week, where a rewritten
 * block costs one per pass, and a single bad model call could replace six true
 * things with prose. A strike can lose the world at most one line per pass,
 * and the trace says which line, and why.
 *
 * THE TRACE is a `struck` row in the signals table, carrying the lesson and
 * the reason, written already-processed so it can never enter a distil batch
 * or a tally — it is about the notebook, not the user, which is also why it is
 * not in xeric_learn_kinds(). It ages out with the rest of the read evidence
 * (XERIC_LEARN_KEEP_DAYS): evidence, not history.
 */
function xeric_lessons_strike(PDO $db, string $handle, string $line, string $why, ?int $at = null): bool
{
    $at   = $at ?? xeric_state_time();
    $have = xeric_lessons_read($db, $handle);
    $idx  = array_search($line, $have, true);
    if ($idx === false) return false;               // striking what is not there is not a strike

    array_splice($have, $idx, 1);
    xeric_arc_set($db, $handle, 'learn.lessons',
        json_encode(array_values($have), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);
    xeric_lessons_earned_note($db, $handle, [], [], $at);   // the provenance map follows the notebook

    try {
        $st = $db->prepare('INSERT INTO signals (kind, handle, subject, n, lag, note, processed, created_at, world_epoch)
                            VALUES (?, ?, ?, 0, 0, ?, 1, ?, NULL)');
        $st->execute([XERIC_LEARN_STRUCK, $handle, mb_substr($line, 0, 200),
            mb_substr(trim(preg_replace('/\s+/u', ' ', $why) ?? ''), 0, 400), $at]);
    } catch (Throwable $e) {
        // garnish — the strike itself has happened; the trace is for the inspector
    }
    return true;
}

/**
 * The strikes, newest first: whose bucket, the lesson as it stood, and why.
 * Lives as long as read evidence does (XERIC_LEARN_KEEP_DAYS) and no longer.
 */
function xeric_lessons_struck(PDO $db, int $limit = 12): array
{
    $st = $db->prepare('SELECT handle, subject AS lesson, note AS why, created_at FROM signals
                        WHERE kind = ? ORDER BY id DESC LIMIT ?');
    $st->execute([XERIC_LEARN_STRUCK, max(1, $limit)]);
    return $st->fetchAll();
}

/**
 * What earned each lesson: lesson => {at, evidence}. The inspector's half.
 *
 * WHERE DERIVABLE, AND NO FURTHER. A lesson is a string in a capped list;
 * making it an object would drag the dedupe, the prompt and every caller along
 * for the sake of a tuning page, so provenance lives in a sidecar arc instead:
 * when the model pass keeps a lesson, the evidence lines it was shown THAT
 * PASS are written beside it — the model is not asked to cite, it is watched.
 * Filtered against the living notebook on the way out, so an evicted or struck
 * lesson takes its trace with it, and a lesson written before the sidecar
 * existed (or added by hand) simply has none — the inspector says so rather
 * than inventing one.
 */
function xeric_lessons_earned(PDO $db, string $handle): array
{
    $raw = xeric_arc_get($db, $handle, 'learn.lessons.earned');
    $map = $raw !== null ? json_decode($raw, true) : null;
    if (!is_array($map)) $map = [];
    $live = xeric_lessons_read($db, $handle);
    return $live === [] ? [] : array_intersect_key($map, array_flip($live));
}

/** Keep the sidecar walking in step with the notebook. Garnish: fails quietly. */
function xeric_lessons_earned_note(PDO $db, string $handle, array $lessons, array $evidence, ?int $at = null): void
{
    try {
        $at  = $at ?? xeric_state_time();
        $map = xeric_lessons_earned($db, $handle);  // the read is already pruned to the living
        foreach ($lessons as $l) {
            $map[(string)$l] = ['at' => $at, 'evidence' => array_slice(array_values($evidence), 0, 4)];
        }
        xeric_arc_set($db, $handle, 'learn.lessons.earned',
            json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);
    } catch (Throwable $e) {
        // garnish
    }
}

/**
 * The periodic pass. Read the crumbs, count them, and try to put words to them.
 *
 * There is no cron in this engine — the demo runs this off the back of a skip,
 * a private install off whatever it likes — so this is written to be safe to call
 * often and cheap when there is nothing to do.
 *
 * @param array $opts batch, no_model (counting only), temperature, timeout, at
 * @return array{signals:int,tallies:array,lessons:array<string,array>,struck:array,notes:array}
 */
function xeric_lessons_distil(PDO $db, array $t, array $endpoint, array $opts = []): array
{
    $at = (int)($opts['at'] ?? xeric_state_time());

    // THE KILL SWITCH, HONOURED HERE SO EVERY CALLER HONOURS IT. Off means no
    // counting, no marking, no model — the crumbs stay unread and pile up, so
    // the day the switch flips back the backlog is read and the world can say
    // what happened while it was not learning. (Recording never stopped:
    // xeric_signal_add is ungated, on purpose — see xeric_learn_enabled.)
    if (!xeric_learn_enabled($db)) {
        return ['signals' => 0, 'tallies' => [], 'lessons' => [], 'struck' => [],
                'notes' => ['learning is switched off for this world — the crumbs pile up unread until it is switched back on']];
    }

    $rows = xeric_signals_unprocessed($db, (int)($opts['batch'] ?? XERIC_LEARN_BATCH));

    if ($rows === []) {
        return ['signals' => 0, 'tallies' => [], 'lessons' => [], 'struck' => [],
                'notes' => ['nothing new has happened to learn from']];
    }

    // -- layer one: arithmetic, which cannot fail -----------------------------
    //
    // ONE TRANSACTION, AND MARKED EITHER WAY. Counting the rows and marking them
    // read are the same fact — these crumbs have been looked at — and they used
    // to be forty arc bumps apart with a model call in between. A machine that
    // died in that minute woke up with the arithmetic applied and the evidence
    // still open, and the next pass counted the same forty crumbs again. The
    // batch is also not retried until the model comes back: a batch that waits
    // for words blocks every crumb behind it, including the easy ones.
    $notes   = [];
    $written = [];
    $struck  = [];

    $db->beginTransaction();
    try {
        $tallies = xeric_learn_tally_apply($db, $rows, $at);
        xeric_signals_mark($db, array_map(fn($r) => (int)$r['id'], $rows));
        xeric_learn_prune($db, $at);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('learn: could not count what happened, ' . $e->getMessage(), 0, $e);
    }

    // -- layer two: words, which may fail, and is therefore outside -----------
    // Up to a minute in the model is a minute nobody else may write, and the
    // words are the half this pass can afford to lose: the counting has already
    // moved the weights and the reach, which is where behaviour actually lives.
    if (empty($opts['no_model']) && count($rows) >= (int)($opts['min_batch'] ?? XERIC_LEARN_MIN_BATCH)) {
        try {
            $lm      = xeric_lessons_model($db, $t, $endpoint, $rows, $opts, $notes);
            $written = $lm['written'];
            $struck  = $lm['struck'];
        } catch (Throwable $e) {
            $notes[] = 'the model had nothing to say about it, ' . $e->getMessage();
        }
    } elseif (empty($opts['no_model'])) {
        $notes[] = 'too few crumbs for a model pass, counted, and kept for next time';
    }

    return ['signals' => count($rows), 'tallies' => $tallies, 'lessons' => $written, 'struck' => $struck, 'notes' => $notes];
}

/**
 * One model call: crumbs and counters in, one to three short lessons out —
 * and, when the evidence contradicts a lesson already held, one strike.
 *
 * Mirrors xeric_chat_extract() deliberately — same JSON-object seam, same
 * refuse-everything-questionable parse, same dedupe against what is already
 * held — because this is the same job as memory extraction with the subject
 * changed from a character to the person they are talking to.
 *
 * The strike can only reach the buckets this batch put in front of the model —
 * the world's, and those of the people the crumbs are about — which is not a
 * limitation worth fixing: a lesson about somebody absent from the batch has
 * nothing in the batch to be contradicted BY.
 *
 * @return array{written:array<string,array>,struck:array} written is bucket
 *         handle ('' = the world) => lessons; struck is {bucket,lesson,why}
 */
function xeric_lessons_model(PDO $db, array $t, array $endpoint, array $rows, array $opts, array &$notes): array
{
    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'the person this world is for';

    // WHAT THE MODEL IS SHOWN, AND WHAT IT IS NOT.
    //
    // A crumb whose entire meaning is a counter is withheld. `skipped` and
    // `ignored` already move the sweep weights and the reach, in numbers, in a
    // way that is exact and that keeps moving as the evidence does. A model
    // shown them writes a second, worse copy of the same decision in English —
    // "do not bring up glimpses", "do not expect a response from him" — and
    // unlike the number, that sentence then rides the prompt for weeks, telling
    // a character to expect to be ignored long after they stopped being.
    // (Both of those came back from the real model, 2026-07-30.)
    //
    // What is left is the evidence a counter cannot hold: the WORDS of a hand
    // edit, how long an answer was, and whose thread somebody went to.
    $shown = array_values(array_filter($rows,
        fn($r) => in_array((string)($r['kind'] ?? ''), ['edit', 'reply', 'dwell'], true)));

    // What is already known, so the model is not asked to discover it twice.
    $buckets = [xeric_arc_world()];
    foreach ($shown as $r) {
        $h = (string)($r['handle'] ?? '');
        if ($h !== '' && !in_array($h, $buckets, true)) $buckets[] = $h;
    }

    // Numbered, so a strike can point at a line without retyping it: a model
    // asked to quote a lesson back paraphrases it, and a paraphrase matches
    // nothing. The number is the id and the map is the truth.
    $known    = [];
    $knownMap = [];                                  // number => [bucket, lesson]
    foreach ($buckets as $h) {
        foreach (xeric_lessons_read($db, $h) as $l) {
            $n = count($knownMap) + 1;
            $knownMap[$n] = [$h, $l];
            $known[] = '[' . $n . '] ' . ($h === xeric_arc_world() ? '(everybody) ' : '(' . xeric_world_name($t, $h) . ') ') . $l;
        }
    }

    $evLines = [];
    foreach ($shown as $r) {
        $line = xeric_learn_evidence_line($t, $r, $userName);
        if ($line !== '') $evLines[] = $line;
    }
    if ($evLines === []) {
        $notes[] = 'nothing in this batch says anything a number has not already said';
        return ['written' => [], 'struck' => []];
    }

    // The counting, handed over as fact. A model is far better at putting a
    // sentence to a number it has been given than at inferring the number from
    // twenty rows, and this is the half that is true whether or not it answers.
    //
    // EVERY LINE NAMES THE USER AS THE SUBJECT. Handed "answered 5, ignored 3"
    // a model reads it as a complaint about the CAST and writes "reply to his
    // messages" — advice to the wrong party, filed forever (seen against the
    // real model, 2026-07-30). Whose behaviour a number describes has to be in
    // the sentence.
    //
    // The per-kind engagement rates are deliberately NOT shown. Those are the
    // deterministic layer's business — they already move what happens, through
    // the sweep weights — and a model shown them writes lessons instructing the
    // cast to do more of the thing the user just walked past, which argues with
    // the weight that has this moment gone the other way.
    $counted = [];
    foreach ($buckets as $h) {
        if ($h === xeric_arc_world()) continue;
        $tal = xeric_learn_tally($db, $h);
        if ($tal['replies'] + $tal['ignored'] + $tal['reads'] === 0) continue;
        $name = xeric_world_name($t, $h);
        $bits = [$userName . ' has answered ' . $name . ' ' . $tal['replies'] . ' time(s) and left '
                 . $tal['ignored'] . ' of her messages sitting'];
        if ($tal['avg_reply_chars'] !== null) $bits[] = 'his replies to her average ' . $tal['avg_reply_chars'] . ' characters';
        if ($tal['avg_reply_lag'] !== null && $tal['avg_reply_lag'] > 0) {
            $bits[] = 'he usually answers her within ' . max(1, (int)round($tal['avg_reply_lag'] / 60)) . ' minutes';
        }
        $counted[] = '- ' . implode('; ', $bits);
    }

    $lines = [];
    $lines[] = 'WHO THIS IS ABOUT';
    $lines[] = $userName . ', the person this world is being run for.';
    if ($known !== []) {
        $lines[] = '';
        $lines[] = 'ALREADY WRITTEN DOWN (do not write any of these again)';
        foreach ($known as $k) $lines[] = '- ' . $k;
    }
    if ($counted !== []) {
        $lines[] = '';
        $lines[] = 'WHAT THE COUNTING KNOWS';
        foreach ($counted as $c) $lines[] = $c;
    }
    $lines[] = '';
    $lines[] = 'WHAT ' . strtoupper($userName) . ' DID';
    foreach ($evLines as $e) $lines[] = '- ' . $e;
    $lines[] = '';
    $lines[] = 'WRITE ONE JSON OBJECT';
    $lines[] = $knownMap === []
        ? '{ "lessons": [ { "about": "", "lesson": "…" } ] }'
        : '{ "lessons": [ { "about": "", "lesson": "…" } ], "strike": [ { "n": 1, "why": "…" } ] }';
    $lines[] = '';
    $lines[] = '- 0 to 3 lessons. Fewer is better. An empty list is a correct answer.';
    $lines[] = '- "about": "" when it is true of everybody in this world, or ONE HANDLE, the name in square';
    $lines[] = '  brackets, not the display name, when it is only about that person.';
    $lines[] = '- "lesson": ONE line, under ' . XERIC_LEARN_MAX_CHARS . ' characters, written as an instruction to';
    $lines[] = '  the people in this world about how to be with ' . $userName . '. "Keep her answers under three';
    $lines[] = '  lines, he replies to short ones and lets long ones sit."';
    // Everything above is something the USER did. Left to itself a model turns
    // each line into an instruction aimed at whoever it mentions — "answer his
    // messages", "follow up on that" — which is advice to the wrong party,
    // filed permanently. Seen against the real model, 2026-07-30.
    $lines[] = '- Every line above is something ' . $userName . ' did or did not do. A lesson NEVER tells anybody';
    $lines[] = '  to do more of what he walked past, and never tells him anything: it says how the people here';
    $lines[] = '  should behave, given what he is like.';
    // Without this a model writes flattery — "he is a thoughtful person who
    // values authenticity" — which is unfalsifiable, unusable, and permanent.
    $lines[] = '- About BEHAVIOUR only: length, timing, how often to reach out, what to bring up, what to leave';
    $lines[] = '  alone. Never about his character, never praise, never a compliment.';
    $lines[] = '- Only what the evidence above actually shows. If it shows nothing, return an empty list.';
    $lines[] = '- A hand-retyped line is the strongest evidence here: he was shown what this world thought and';
    $lines[] = '  went and changed it in writing. Take it literally.';
    if ($knownMap !== []) {
        $lines[] = '- "strike": AT MOST ONE, and usually none. Only when what ' . $userName . ' just did says a numbered';
        $lines[] = '  line under ALREADY WRITTEN DOWN is now WRONG — not stale, not unproven: contradicted by the';
        $lines[] = '  evidence above. "n" is that number, "why" is one line naming the evidence that contradicts it.';
    }
    $lines[] = 'No prose outside the JSON.';

    $raw = xeric_chat_json($endpoint, 'lessons', [
        ['role' => 'system', 'content' =>
            'You keep the notes a living world uses to get better at the one person it is being run for. '
            . 'You read what they actually did, what they answered, what they left sitting, what they went '
            . 'back and retyped, and write down the few things that should change how the world behaves. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' => implode("\n", $lines)],
    ], ['temperature' => (float)($opts['temperature'] ?? 0.4), 'max_tokens' => (int)($opts['max_tokens'] ?? 400)]
        + array_intersect_key($opts, ['timeout' => 1]));

    $take = [];
    $taken = 0;
    foreach ((array)($raw['lessons'] ?? $raw['notes'] ?? []) as $item) {
        $text  = '';
        $about = '';
        if (is_array($item)) {
            $text  = (string)($item['lesson'] ?? $item['note'] ?? $item['text'] ?? '');
            $about = (string)($item['about'] ?? $item['handle'] ?? '');
        } else {
            $text = (string)$item;
        }
        $text = trim($text);
        if ($text === '') continue;

        // A display name where a handle was asked for is what a model does; the
        // seeder and the sweep parser both resolve it through the same index, so
        // "Maren Voss" and "maren_voss" mean one person in every file. A bucket
        // that still cannot be found is the world's — dropping the lesson would
        // throw away a true thing over a mistyped name.
        if ($about !== '' && xeric_world_character($t, $about) === null) {
            $found = xeric_seed_resolve($about, xeric_seed_index($t, true));
            if ($found !== null && xeric_world_character($t, $found) !== null) {
                $about = $found;
            } else {
                $notes[] = "a lesson was filed under '$about', who is nobody, kept for the world instead";
                $about = '';
            }
        }
        $take[$about][] = $text;
        if (++$taken >= 3) break;                    // three at a time, however many it wrote
    }

    // The strike, applied BEFORE the adds: a pass that swaps a dead lesson for
    // a live one should spend the dead one's slot, not evict the oldest
    // survivor to make room. One per pass, however many it asked for — the cap
    // on its editorial power IS the design (xeric_lessons_strike says why).
    $struckOut = [];
    if ($knownMap !== []) {
        foreach (array_slice((array)($raw['strike'] ?? $raw['strikes'] ?? []), 0, 3) as $s) {
            if (!is_array($s)) continue;
            $sn = (int)($s['n'] ?? 0);
            if (!isset($knownMap[$sn])) continue;    // struck a number that names nothing
            [$bh, $bl] = $knownMap[$sn];
            $why = trim((string)($s['why'] ?? $s['reason'] ?? ''));
            if ($why === '') $why = 'the recent record contradicted it';
            if (xeric_lessons_strike($db, $bh, $bl, $why, (int)($opts['at'] ?? xeric_state_time()))) {
                $struckOut[] = ['bucket' => $bh, 'lesson' => $bl, 'why' => $why];
                $notes[]     = 'struck a lesson' . ($bh === xeric_arc_world() ? '' : ' about ' . xeric_world_name($t, $bh))
                             . ': "' . $bl . '" — ' . $why;
                break;
            }
        }
    }

    $written = [];
    foreach ($take as $bucket => $lessons) {
        $before = xeric_lessons_read($db, (string)$bucket);
        $after  = xeric_lessons_add($db, (string)$bucket, $lessons, (int)($opts['at'] ?? xeric_state_time()));
        $kept   = array_values(array_diff($after, $before));
        if ($kept !== []) {
            $written[(string)$bucket] = $kept;
            // The inspector's half: which evidence this pass was looking at
            // when it wrote these (xeric_lessons_earned).
            xeric_lessons_earned_note($db, (string)$bucket, $kept, $evLines, (int)($opts['at'] ?? xeric_state_time()));
        }
    }
    if ($written === [] && $struckOut === []) $notes[] = 'nothing came back that was not already written down';

    return ['written' => $written, 'struck' => $struckOut];
}

/**
 * One crumb, in the words the model reads. '' when it says nothing out loud.
 *
 * The user is named as the subject of every line, and the person a line is about
 * carries their HANDLE in brackets — the same `- [handle] Name` shape
 * xeric_sweep_prompt() uses, and for the same reason: a model asked to file
 * something under a person will write the display name unless the handle is what
 * it is looking at.
 */
function xeric_learn_evidence_line(array $t, array $r, string $userName = 'they'): string
{
    $kind = (string)($r['kind'] ?? '');
    $h    = (string)($r['handle'] ?? '');
    $who  = $h !== '' ? '[' . $h . '] ' . xeric_world_name($t, $h) : '';
    $sub  = (string)($r['subject'] ?? '');
    $note = trim((string)($r['note'] ?? ''));
    $n    = (int)($r['n'] ?? 0);
    $lag  = (int)($r['lag'] ?? 0);

    switch ($kind) {
        case 'edit':
            // The one crumb whose words matter more than its count.
            return 'RETYPED BY HAND, ' . $userName . ' rewrote '
                 . ($sub !== '' ? $sub : 'something')
                 . ($who !== '' ? ', which belongs to ' . $who : '')
                 . ($note !== '' ? ', ' . $note : '');
        case 'reply':
            if ($who === '') return '';
            return $userName . ' answered ' . $who . ' with ' . $n . ' characters'
                 . ($lag > 60 ? ', ' . max(1, (int)round($lag / 60)) . ' minutes later' : ', straight away');
        case 'ignored':
            return $who === '' ? '' : $who . ' texted first and ' . $userName . ' never answered';
        case 'skipped':
            return $sub === '' ? ''
                : $userName . ' walked past an hour of the "' . $sub . '" kind without asking anybody about it';
        case 'dwell':
            if ($who === '') return '';
            return $sub !== ''
                ? $userName . ' went straight to ' . $who . ' after an hour of the "' . $sub . '" kind'
                : $userName . ' opened ' . $who . '\'s thread and read it';
    }
    return '';
}
