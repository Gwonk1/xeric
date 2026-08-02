<?php
/**
 * tick-worker.php — the world moving, off the end of the request that asked.
 *
 * WHY THIS EXISTS, again. A six-hour skip is a handful of model calls: two to
 * four for the events, one for whoever picks up their phone about it. On the
 * local model that is fifteen to sixty seconds, and a skip to tomorrow morning
 * is minutes. Cloudflare cuts a streaming response on dev.xeric.dev at about 120
 * seconds however often it is flushed, so the work does NOT live in an HTTP
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
 * Usage (never a URL): php tick-worker.php <job-id>  with the payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("tick-worker.php is not a page\n"); }

require_once __DIR__ . '/play-lib.php';

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

$lock = null;

try {
    $slug = (string)($payload['slug'] ?? '');
    $span = (int)($payload['span'] ?? 0);
    if ($span <= 0) throw new RuntimeException('that is not a stretch of time');

    $w  = xeric_play_open($slug, (string)($payload['db'] ?? '') ?: null,
                          array_key_exists('adult', $payload) ? (bool)$payload['adult'] : false);
    $T  = $w['template'];
    $db = $w['db'];
    $endpoint = xeric_play_endpoint();

    $before = xeric_clock_now($db, $T);

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

    // -- the clock -----------------------------------------------------------
    $after = xeric_clock_advance($db, $span, $T);
    $say(xeric_clock_span_label($span) . ', ' . xeric_play_when($before) . ' → ' . xeric_play_when($after));

    // -- the hours that went by ----------------------------------------------
    // chance is 1.0 on purpose: sweeps.php's precedence note blesses an explicit
    // opt from "the demo's time control". Somebody who pressed *skip to evening*
    // asked to SEE the evening. XERIC_PLAY_MAX_EVENTS is what keeps that from
    // becoming eleven model calls.
    $opts = [
        'chance'      => 1.0,
        'max_events'  => 1,          // per window — the ceiling below is the real one
        'max_windows' => 1,
        'temperature' => 0.9,
        'timeout'     => 240,
        // The raw overlays, alongside the composed $T xeric_play_open() handed
        // back. These are what the snake paces the hours with and what makes a
        // beat fire without being rolled for; $T is what puts the walls and the
        // voices in. Both, or the story is half live.
        'stories'     => (array)($w['stories'] ?? []),
    ];

    $ws     = XERIC_SWEEP_WINDOW;
    $firstW = intdiv((int)$before['epoch'], $ws);
    $lastW  = intdiv((int)$after['epoch'], $ws);
    $events = [];
    $notes  = [];
    $misses = 0;
    $walked = 0;

    for ($win = $firstW; $win <= $lastW; $win++) {
        if (count($events) >= XERIC_PLAY_MAX_EVENTS) {
            $notes[] = 'that is enough for one skip, the rest of those hours passed quietly';
            break;
        }
        if ($walked++ >= XERIC_PLAY_MAX_WINDOWS) { $notes[] = 'stopped after ' . XERIC_PLAY_MAX_WINDOWS . ' hours'; break; }

        // THE OWNER'S MACHINE WINS. Between windows — never mid-call — this asks
        // whether the demo is still allowed the GPU: the drain flag landing, or
        // this hold running past the hard cap. Stopping here keeps every event
        // that already happened; the world simply stands where it got to.
        if (xeric_queue_expired($lock)) { $notes[] = xeric_queue_stop_reason($lock); break; }

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
            try {
                $ping = xeric_proactive_check($T, $db, $endpoint, $after, [
                    'event'       => $candidate,
                    'chance'      => 1.0,    // an explicit chance is an instruction — proactive.php
                    'temperature' => 0.95,
                    'timeout'     => 180,
                    // A thumb on WHO picks up the phone and nothing else: somebody
                    // holding an opened piece, or sure of something wrong, is
                    // likelier to be the one who texts (engine/proactive.php).
                    'stories'     => (array)($w['stories'] ?? []),
                ], $notesOut);
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
    // The slot goes back with a measurement of what this skip cost, which is what
    // the next visitor's "about 40 seconds" is made of.
    if (is_array($lock)) xeric_queue_release($lock);
}
exit(0);
