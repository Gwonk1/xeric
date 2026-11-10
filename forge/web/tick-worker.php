<?php
/**
 * tick-worker.php — the world moving, off the end of the request that asked.
 *
 * WHY THIS EXISTS, again. A six-hour skip is a handful of model calls: two to
 * four for the events, one for whoever picks up their phone about it. On the
 * local model that is fifteen to sixty seconds, and a skip to tomorrow morning
 * is minutes. Cloudflare cuts a streaming response on a proxied host at about
 * 120 seconds however often it is flushed, so the work does NOT live in an HTTP
 * request: it lives here, detached, appending one JSON line per thing that
 * happens, and progress.php tails that file over SSE in short resumable
 * stretches. Same machinery as the forge's build, for the same reason.
 *
 * THE SEQUENCE IS sweep-cli.php's, IN ITS ORDER, and deliberately so:
 *
 *     advance the clock  →  sweep the hours that went by  →  let somebody text
 *
 * Nothing about what may happen, to whom, or whether anybody speaks up is
 * decided here. sweeps.php picks the shape and the room; proactive.php decides
 * whether a phone buzzes and refuses to cold-open a stranger. This file is a
 * loop, a lock, and a list of frames.
 *
 * ── WHY THE HOURS ARE WALKED ONE AT A TIME ────────────────────────────────
 *
 * xeric_sweep_catchup() takes a whole stretch and returns everything at the end.
 * That is the right shape for a cron and the wrong shape for a screen: the demo
 * has to show each event AS IT LANDS or the visitor watches a blank feed for a
 * minute and concludes it hung. There is no per-event callback in the engine and
 * inventing one in the web layer is not this file's business, so the stretch is
 * handed to catchup ONE WINDOW AT A TIME. That is inside its contract, not
 * around it: the per-window guard and the watermark both key off the window
 * index, and consecutive single-window calls walk the same indices in the same
 * order as one call over the range would.
 *
 * ── AND THE HOURS A WALK BURNED ───────────────────────────────────────────
 *
 * A trip advances the same shared clock a skip does (engine/travel.php), and
 * for a long time those minutes were minutes nothing happened in — where.php's
 * old header said so out loud. The payload's `walked` flag is that promise
 * being kept: the clock has ALREADY moved by the time this worker starts, so
 * the walked mode advances nothing and simply lives the stretch measured
 * BACKWARD from where the world now stands. Three things a skip does are
 * deliberately not done for a walk: no rewind mark (a walk is not a skip, and
 * --rewind takes back skips), no `skip:underway` stamp (the clock is not in
 * this worker's hands, so more walking stays legal while the hours stream),
 * and no forced `chance` (the walker asked to get somewhere, not to see an
 * evening — the world keeps its own cadence for hours nobody demanded).
 *
 * Usage (never a URL): php tick-worker.php <job-id>  with the payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("tick-worker.php is not a page\n"); }

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/rewind.php';   // the mark + manifest around the skip
require_once XERIC_WEB_LIB . '/engine/work.php';     // and what the jump did to the job

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "tick: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'the time control was given nothing to move']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$t0 = microtime(true);
$el = fn() => round(microtime(true) - $t0, 1);
$say = function (string $text, string $level = 'info') use ($job, $el): void {
    xeric_web_job_append($job, ['k' => 'note', 't' => $el(), 'level' => $level, 'text' => $text]);
};

// The visitor whose copy of the world this is. A detached process has no cookie;
// tick.php hands over both the id and the exact database to move.
$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

$lock    = null;
$mark    = null;
$stamped = false;

try {
    $slug   = (string)($payload['slug'] ?? '');
    $span   = (int)($payload['span'] ?? 0);
    $walked = !empty($payload['walked']);
    if ($span <= 0) throw new RuntimeException('that is not a stretch of time');

    $w  = xeric_play_open($slug, (string)($payload['db'] ?? '') ?: null,
                          array_key_exists('adult', $payload) ? (bool)$payload['adult'] : false);
    $T  = $w['template'];
    $db = $w['db'];
    $endpoint = xeric_play_endpoint();

    // WHOSE MODEL SPEAKS FOR WHOM — the heart's resolver, worn here too, so a
    // pinned character's after-the-hours text comes off THEIR machine, prefix
    // warm, exactly like their chat turns (play-lib.php's voice.machine arc).
    // Handed to proactive only: the sweep is deliberately unpinned — one call
    // writes a whole hour and every witness's memory, no single voice to route.
    $voiceCache  = [];
    $endpointFor = function (string $h) use ($T, $db, $sid, $endpoint, &$voiceCache): array {
        if (!isset($voiceCache[$h])) {
            try {
                $voiceCache[$h] = xeric_voice_machine($db, $h) === ''
                    ? $endpoint
                    : xeric_voice_endpoint($T, $db, $h, $sid);
            } catch (Throwable $e) {
                $voiceCache[$h] = $endpoint;   // a pin must not silence anybody
            }
        }
        return $voiceCache[$h];
    };

    // A walk's stretch is measured BACKWARD FROM ITS OWN LANDING, never from a
    // fresh clock read: where.php serializes the trip's landing epoch into the
    // payload, because a second walk landing before this worker reads the
    // clock would shift the stretch onto hours walk A never contained — and
    // the forward-only watermark would then bury walk A's real windows,
    // unlived, for good. A skip's `before` is simply now (the clock is still
    // in its hands). `landed` absent — a job written before it rode along —
    // falls back to the fresh read, which is exactly the old behavior.
    $landed = (int)($payload['landed'] ?? 0);
    if ($walked && $landed <= 0) $landed = (int)xeric_clock_now($db, $T)['epoch'];
    $before = $walked
        ? xeric_world_now($T, $landed - $span)
        : xeric_clock_now($db, $T);

    // WHAT THE LAST SKIP LEFT HANGING. "Nobody answered her" and "they walked
    // straight past that evening" are absences, and an absence cannot be seen at
    // the moment it begins — only later, by what did not happen in between. So
    // the previous skip's offers are judged HERE, at the top of the next one,
    // where hindsight is finally available (engine/learn.php).
    try { xeric_learn_settle($db, (int)$before['epoch']); } catch (Throwable $e) { /* learning is garnish */ }

    xeric_web_job_append($job, ['k' => 'hello', 't' => 0.0,
        'slug' => $w['slug'], 'span' => xeric_clock_span_label($span),
        'from' => xeric_play_when($before), 'endpoint' => xeric_web_endpoint_label($endpoint)]);

    // -- wait for the one model slot -----------------------------------------
    // Hello first, THEN the line, so a visitor who is third sees where they are
    // standing instead of a feed that has not said anything yet.
    $ticket = (string)($payload['ticket'] ?? '');
    if ($ticket === '') $ticket = xeric_queue_join('tick', $sid);

    $got = xeric_queue_wait($ticket, XERIC_QUEUE_WAIT_MAX,
        function (int $ahead, int $eta, string $phrase) use ($job, $el): void {
            xeric_web_job_append($job, ['k' => 'queue', 't' => $el(), 'ahead' => $ahead,
                'eta' => $eta, 'text' => ucfirst($phrase)]);
        });

    if (!$got['ok']) {
        xeric_queue_leave($ticket);
        xeric_web_job_append($job, ['k' => 'error', 't' => $el(),
            'kind' => (string)($got['kind'] ?? 'queued'), 'message' => (string)$got['message']]);
        exit(0);
    }
    $lock = $got['hold'];
    if ((float)($got['waited'] ?? 0) > 1.0) $say('the model is free, ' . (int)$got['waited'] . 's in line');

    // -- the mark ------------------------------------------------------------
    // The near side of the skip, photographed before the clock moves. Everything
    // between this line and the manifest commit in the finally below IS the
    // skip, and the diff of the two is what makes it rewindable
    // (engine/rewind.php). After the settle above on purpose: the settle judges
    // the PREVIOUS span, and its verdicts are about hours that stay lived.
    // A WALK TAKES NO MARK: the trip moved the clock before this worker ran,
    // a walk is not a thing --rewind offers to take back, and a mark here
    // would race any second trip into this worker's manifest window.
    if (!$walked) $mark = xeric_rewind_mark($db);

    // THE WORLD IS MOVING, said where travel can read it. Set AFTER the mark
    // and cleared BEFORE the commit — the ordering is load-bearing on both
    // edges: inside the mark/commit window the stamp would enter the manifest
    // diff, and a rewind would then resurrect it as a removed row and leave a
    // ghost "skip underway" on a world standing still. Real time, not world
    // time, because xeric_travel_skipping()'s staleness cutoff is derived from
    // the real-time queue hold cap: a dead worker's stamp goes stale and is
    // ignored rather than freezing travel forever.
    // A WALK NEVER STAMPS: the clock is not in this worker's hands, and the
    // whole contract of the trip sweep is that more walking stays legal while
    // the hours stream (sweeps are idempotent per window, so racing is safe).
    if (!$walked) {
        xeric_world_state_set($db, 'skip:underway', (string)time());
        $stamped = true;
    }

    // -- the clock -----------------------------------------------------------
    // Walked: the far end is the trip's OWN landing (anchored above), never
    // advanced and never a fresh read — the clock may already carry a second
    // walk's minutes by now, and those hours belong to that walk's worker.
    // Deriving rather than advancing is also what keeps a walk from
    // double-charging its minutes.
    $after = $walked ? xeric_world_now($T, $landed) : xeric_clock_advance($db, $span, $T);
    $say(($walked ? 'the ' . xeric_clock_span_label($span) . ' you walked, ' : xeric_clock_span_label($span) . ', ')
        . xeric_play_when($before) . ' → ' . xeric_play_when($after));

    // -- the hours that went by ----------------------------------------------
    // chance is 1.0 on purpose: sweeps.php's precedence note blesses an explicit
    // opt from "the demo's time control". Somebody who pressed *skip to evening*
    // asked to SEE the evening. XERIC_PLAY_MAX_EVENTS is what keeps that from
    // becoming eleven model calls.
    // A WALK CARRIES NO CHANCE OPT AT ALL: the walker asked to get somewhere,
    // not to see an evening, so the world's own cadence decides — most walks
    // stream "nothing happened in those hours", which is the honest answer.
    $opts = [
        'max_events'  => 1,          // per window — the ceiling below is the real one
        'max_windows' => 1,
        'temperature' => 0.9,
        'timeout'     => 240,
        // The raw overlays, alongside the composed $T xeric_play_open() handed
        // back. These are what the snake paces the hours with and what makes a
        // beat fire without being rolled for; $T is what puts the walls and the
        // voices in. Both, or the story is half live.
        'stories'     => (array)($w['stories'] ?? []),
        // The skipped hours in the pinned voices too, as the heart lives them.
        'endpoint_for' => xeric_play_sweep_endpoints($w + ['endpoint' => $endpoint]),
    ];
    if (!$walked) $opts['chance'] = 1.0;

    $ws     = XERIC_SWEEP_WINDOW;
    // FLOOR DIVISION, NOT intdiv: a period world's clock is a negative epoch,
    // and intdiv truncates toward zero — the same off-by-one-window disagreement
    // xeric_sweep_windex() exists to prevent (see its header in sweeps.php).
    $firstW = xeric_sweep_windex((int)$before['epoch'], $ws);
    $lastW  = xeric_sweep_windex((int)$after['epoch'], $ws);
    $events = [];
    $notes  = [];
    $misses = 0;
    // `stepped`, not `walked`: the windows-walked counter used to wear that
    // name, and the payload's walked-trip flag above now does — one variable
    // wearing both meanings silently un-forced a skip's chance the moment the
    // loop ran once.
    $stepped = 0;

    for ($win = $firstW; $win <= $lastW; $win++) {
        if (count($events) >= XERIC_PLAY_MAX_EVENTS) {
            $notes[] = 'that is enough for one skip, the rest of those hours passed quietly';
            break;
        }
        if ($stepped++ >= XERIC_PLAY_MAX_WINDOWS) { $notes[] = 'stopped after ' . XERIC_PLAY_MAX_WINDOWS . ' hours'; break; }

        // THE OWNER'S MACHINE WINS. Between windows — never mid-call — this asks
        // whether the demo is still allowed the GPU: the drain flag landing, or
        // this hold running past the hard cap. Stopping here keeps every event
        // that already happened; the world simply stands where it got to.
        if (xeric_queue_expired($lock)) { $notes[] = xeric_queue_stop_reason($lock); break; }

        // AND THE STAMP STAYS FRESH WHILE THE CLOCK IS HELD. One window can be
        // two 240-second model calls (the event and its one diverge retry), so
        // a stamp written once at the top could outlive its 600s believability
        // (XERIC_TRAVEL_SKIP_STALE) while the skip genuinely still held the
        // clock — and xeric_travel_skipping() would wave a walk into the middle
        // of it. Refreshed at every between-window checkpoint, never mid-call,
        // which is what keeps the constant's derivation true: one window's
        // worth of calls between refreshes, plus grace. After the expiry check
        // on purpose — a worker about to stop does not re-promise the clock —
        // and never on a walk, which never stamped in the first place.
        if ($stamped) {
            try { xeric_world_state_set($db, 'skip:underway', (string)time()); }
            catch (Throwable $e) { /* aged out instead, exactly as a dead worker's would be */ }
        }

        $at = $win * $ws + intdiv($ws, 2);
        // A frame before every model call, so the stream is never silent for
        // longer than one of them — progress.php calls four quiet minutes death.
        $say('looking in on ' . xeric_play_stamp($T, $at) . '…');

        // This loop names one window by its START epoch, which tells catchup
        // nothing about where the clock actually stands — so it says. Without
        // it the in-progress hour is sampled at its midpoint and the feed shows
        // 20:30 under a header that says 20:05.
        $r = xeric_sweep_catchup($T, $db, $endpoint, $win * $ws, $win * $ws,
                                 ['clock' => (int)$after['epoch']] + $opts);

        foreach ($r['events'] as $e) {
            $takes = [];
            foreach ((array)$e['memories'] as $handle => $text) {
                $takes[] = ['handle' => (string)$handle, 'name' => xeric_world_name($T, (string)$handle),
                            'text' => (string)$text];
            }
            $events[] = $e;

            // WHY THAT, AND WHY THEM — kept, not thrown away. sweeps.php works
            // all of this out to make the decision and used to forget it the
            // moment the decision was made, which left "why did that happen?"
            // unanswerable ten seconds later. It goes in the world's own
            // world_state, keyed by the event, so why.php can still answer it in
            // a week. Cheap (a few hundred bytes), never load-bearing: a world
            // whose trails are missing plays exactly the same.
            xeric_play_keep_trail($db, $e);
            xeric_web_job_append($job, [
                'k'        => 'event', 't' => $el(),
                'id'       => (int)$e['id'],
                'title'    => (string)$e['title'],
                'when'     => xeric_play_stamp($T, (int)$e['world_epoch']),
                'place'    => (string)($e['place_name'] ?? ''),
                'kind'     => (string)$e['kind'],
                'on_spine' => (bool)$e['on_spine'],
                'prose'    => (string)$e['prose'],
                'takeaways' => $takes,
                'seconds'  => round(((int)($e['usage']['ms'] ?? 0)) / 1000, 1),
                'why_url'  => 'why.php?w=' . rawurlencode($w['slug']) . '&e=' . (int)$e['id'],
            ]);

            // STORY MOVEMENT GETS ITS OWN FRAME. A beat opening used to reach
            // the page only through the final done-repaint of the strip, so a
            // story that moved in hour two of a six-hour skip sat invisible
            // until the whole skip landed. The sweep already says when an hour
            // WAS a beat (`$e['story']`, sweeps.php's return); this relays it.
            // SPOILER DISCIPLINE: the frame carries only what the player may
            // already see — the strip's own digest fields (title, stage) and
            // the story key, the same surface xeric_web_story_relay draws from.
            // Never `why`, never `truth`, never the beat's text or its key's
            // meaning: the movement is announced, the content stays where the
            // thread will earn it.
            if (($e['story'] ?? null) !== null) {
                $sk = (string)($e['story']['key'] ?? '');
                foreach (xeric_play_stories($w, (int)$after['epoch']) as $rowS) {
                    if ((string)$rowS['key'] !== $sk) continue;
                    xeric_web_job_append($job, [
                        'k'     => 'story', 't' => $el(),
                        'key'   => $sk,
                        'title' => (string)$rowS['title'],
                        'stage' => (string)$rowS['stage'],
                        'text'  => 'The story moved while you were away — "' . (string)$rowS['title']
                                 . '", ' . (string)$rowS['stage'] . '.',
                    ]);
                    break;
                }
            }
        }

        // catchup reports a refused hour as a note rather than throwing: a model
        // that repeats itself, or two memories that were one memory, is an
        // ordinary quiet hour. Several in a row means the model is gone.
        $bad = false;
        foreach ($r['notes'] as $n) {
            $notes[] = (string)$n;
            if (stripos((string)$n, 'did not answer') !== false || stripos((string)$n, 'refused') !== false) $bad = true;
        }
        if ($r['events'] === [] && $bad) {
            if (++$misses >= 3) { $notes[] = 'the xeric stopped answering, gave up after three hours in a row'; break; }
        } else {
            $misses = 0;
        }
    }

    // THE STAMP IS RE-PROMISED THE MOMENT THE LOOP ENDS, because the loop's
    // last refresh happened BEFORE the final window's sweep — up to two
    // 240-second calls ago — and everything below is still inside the
    // rewind-mark-to-commit span, where a walk waved in by an aged-out stamp
    // corrupts the manifest's meaning. Same discipline as the checkpoint
    // refresh: never mid-call, and never on a walk ($stamped guards both).
    // XERIC_TRAVEL_SKIP_STALE's derivation (engine/travel.php) counts on this
    // and on the between-candidates refresh in the phone phase below.
    if ($stamped) {
        try { xeric_world_state_set($db, 'skip:underway', (string)time()); }
        catch (Throwable $e) { /* aged out instead, exactly as a dead worker's would be */ }
    }

    $say(count($events) === 0
        ? 'nothing happened in those hours'
        : count($events) . ' thing' . (count($events) === 1 ? '' : 's') . ' happened while you were away');

    // -- and then somebody's phone -------------------------------------------
    //
    // The events are offered newest first and the FIRST message that lands wins.
    // That is not a way around proactive.php's one-ping-per-run rule, it is that
    // rule: at most one message is ever sent. It exists because the newest event
    // is often between two people the visitor has never spoken to, and rule 1 —
    // never cold-open a stranger — then correctly produces silence about an
    // evening in which somebody they DO know was standing in a different room an
    // hour earlier. Offering that hour too costs nothing when it is refused
    // (the refusal happens before any model call) and is the difference between
    // the demo landing and the demo being a paragraph about why it did not.
    //
    // A skip in which nothing new happened still asks, with no event of its
    // own: proactive.php holds an event it deferred for quiet hours, and this
    // is the only thing that ever re-offers it. Asking costs nothing when there
    // is nothing to offer — the refusal happens before any model call.
    $ping = null;
    $why  = [];
    if (!xeric_queue_expired($lock)) {
        $say('seeing if anybody picks up their phone…');
        $candidates = $events !== [] ? array_reverse($events) : [null];
        foreach ($candidates as $candidate) {
            // Between candidates, never mid-call: three 180-second offers back
            // to back would otherwise age the stamp past believability while
            // the clock is genuinely still this worker's — the post-loop tail
            // the staleness constant's derivation now accounts for.
            if ($stamped) {
                try { xeric_world_state_set($db, 'skip:underway', (string)time()); }
                catch (Throwable $e) { /* aged out instead */ }
            }
            try {
                $ping = xeric_proactive_check($T, $db, $endpoint, $after, [
                    'event'       => $candidate,
                    'temperature' => 0.95,
                    'timeout'     => 180,
                    // A thumb on WHO picks up the phone and nothing else: somebody
                    // holding an opened piece, or sure of something wrong, is
                    // likelier to be the one who texts (engine/proactive.php).
                    'stories'     => (array)($w['stories'] ?? []),
                    // And WHOSE machine says it — the pinned-voice resolver,
                    // consulted only when she speaks as herself.
                    'endpoint_for' => $endpointFor,
                    // An explicit chance is an instruction (proactive.php): a
                    // skip forces the beat, a walk leaves the world its own
                    // say about whether anybody bothers to text about it.
                ] + ($walked ? [] : ['chance' => 1.0]), $notesOut);
            } catch (Throwable $e) {
                $notesOut = [$e->getMessage()];
            }
            foreach ((array)$notesOut as $n) {
                if (!in_array((string)$n, $why, true)) $why[] = (string)$n;
            }
            if ($ping !== null) break;
        }
    }

    if ($ping !== null) {
        xeric_web_job_append($job, [
            'k' => 'ping', 't' => $el(),
            'handle' => (string)$ping['handle'], 'name' => (string)$ping['name'],
            'text' => (string)$ping['text'], 'cold_open' => (bool)$ping['cold_open'],
        ]);
    } elseif ($events !== []) {
        // Nobody texting is a real outcome with real reasons — "she has never
        // spoken to you and this was not about you" is the rule working, not a
        // failure — so it is shown rather than swallowed.
        xeric_web_job_append($job, ['k' => 'quiet', 't' => $el(), 'why' => array_values(array_map('strval', (array)$why))]);
    }

    // -- what the jump did to the job ----------------------------------------
    // Read against the roster AFTER the hours are lived, never before: the
    // skip has already happened and nothing here may block it. A world whose
    // money dial is at `none` — which is every world by default — returns
    // immediately and writes nothing at all.
    try {
        $shift = xeric_shift_walk($db, $T, (int)$before['epoch'], (int)$after['epoch']);
        foreach ($shift['lines'] as $line) {
            xeric_web_job_append($job, ['k' => 'note', 't' => $el(), 'text' => $line]);
        }
        if ($shift['paid'] > 0) {
            xeric_web_job_append($job, ['k' => 'note', 't' => $el(),
                'text' => 'The hours you worked were paid.']);
        }
    } catch (Throwable $e) { /* a job is not worth losing a skip over */ }

    // -- and what this skip is now owed an answer to -------------------------
    // The mirror of the settle at the top: what was put in front of the visitor
    // this time, to be judged by the next skip. Written whether or not anybody
    // texted — an evening nobody followed up on is a signal too.
    try { xeric_learn_pend($db, $events, $ping, (int)$after['epoch']); } catch (Throwable $e) { /* garnish */ }

    // -- the distil pass -----------------------------------------------------
    // There is no cron on this machine and this is the one moment the demo is
    // certainly allowed the GPU: the slot is in hand and the visitor is already
    // watching a progress feed. Deterministic counting happens regardless; the
    // model half is skipped entirely if the owner has taken the GPU back, and a
    // failure of either never touches the skip that carried it.
    if (xeric_learn_due($db)) {
        try {
            $lr = xeric_lessons_distil($db, $T, $endpoint, [
                'no_model' => xeric_queue_expired($lock),
                'timeout'  => 60,
            ]);
            $learned = 0;
            foreach ((array)$lr['lessons'] as $ls) $learned += count((array)$ls);
            if ($learned > 0) $say($learned . ' thing' . ($learned === 1 ? '' : 's') . ' learned about how you play');
        } catch (Throwable $e) {
            // A world that could not work out what it thinks is still a world.
        }
    }

    // The hours that produced NOTHING have reasons too — quiet hours, a roll
    // that went the other way, nobody plausibly in the same room — and "why does
    // nothing ever happen in my world?" is the first question anybody tuning one
    // asks. One row, overwritten each skip: the last answer is the useful one.
    xeric_world_state_set($db, 'why:last_tick', json_encode([
        'at'      => time(),
        'span'    => xeric_clock_span_label($span),
        'from'    => xeric_play_when($before),
        'to'      => xeric_play_when($after),
        'events'  => count($events),
        'notes'   => array_values(array_map('strval', $notes)),
        'quiet'   => array_values(array_map('strval', (array)($why ?? []))),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    xeric_web_job_append($job, [
        'k' => 'done', 't' => $el(),
        'events'  => count($events),
        'seconds' => round(microtime(true) - $t0, 1),
        'notes'   => array_values($notes),
        'state'   => xeric_play_state($w),
    ]);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 't' => $el(), 'kind' => 'tick',
        'message' => $e->getMessage()]);
} finally {
    // THE MANIFEST RIDES OUT ON EVERY PATH THE CLOCK MOVED ON — the happy one
    // and the one where something above threw. A skip that died halfway still
    // moved the clock and still landed hours, and those are exactly as
    // regrettable as a finished skip's; the commit diffs what ACTUALLY landed,
    // so a half-skip gets a manifest that tells the truth about half. When the
    // clock never moved (the advance itself refused), the commit declines to
    // write and the previous skip's manifest stands. Never fatal: a skip whose
    // rewind could not be recorded is still a skip that happened.
    // The stamp comes down BEFORE the commit for the same manifest-window
    // reason it went up after the mark. A worker killed between here and its
    // own death leaves a stamp that xeric_travel_skipping() ages out.
    // ONLY THE STAMP THIS WORKER SET: a walked worker never stamped, and it
    // must not take down a real skip's stamp on a world it happens to share.
    if ($stamped && isset($db)) {
        try { xeric_world_state_delete($db, 'skip:underway'); } catch (Throwable $e) { /* stale-aged instead */ }
    }
    if (is_array($mark) && isset($db)) {
        try { xeric_rewind_commit($db, $mark); } catch (Throwable $e) { /* the hours still happened */ }
    }
    // The slot goes back with a measurement of what this skip cost, which is what
    // the next visitor's "about 40 seconds" is made of.
    if (is_array($lock)) xeric_queue_release($lock);
}
exit(0);
