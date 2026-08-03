<?php
/**
 * heart.php — the world running when nobody is looking.
 *
 *     php heart.php            one pass, then exit
 *     php heart.php --loud     say what it did
 *
 * ONE PASS AND EXIT, NOT A LOOP. The caller loops it (`./xeric` does, a cron
 * would). A long-lived PHP process has to be supervised, restarted, and trusted
 * not to leak across a week of ticks; a process that does one thing and dies is
 * crash-safe by construction, testable by running it once, and identical whether
 * a shell loop, systemd or crontab is driving it.
 *
 * WHY THIS EXISTS. Until now the only things that could make a world live
 * through an hour were the skip button and the CLI. The clock ran — world time
 * is real time plus an offset — but nothing happened in the time it passed, so a
 * week away left the date moved and the week empty. Not a delay: a deletion. And
 * "come back after a week and a week has gone by", which is the headline claim
 * of the whole project, was being delivered by a button.
 *
 * SAFE TO RUN EVERY MINUTE, and that rests on one thing already in the engine: a
 * sweep is idempotent per WINDOW. `sweep:<size>:<n>` is written whether the hour
 * produced an event or was skipped, so a tick that finds no new window does no
 * work and costs no tokens. Nothing here needs to know when it last ran.
 *
 * THREE THINGS IT WILL NOT DO.
 *
 *  1. Run a world that is stopped. Detaching the model pauses every world, and a
 *     paused world is paused for this too — otherwise "stop the clock" would
 *     mean "stop the clock unless something else is asking", which is not what
 *     anybody means by it.
 *  2. Take the model from somebody who is using it. A person waiting on a reply
 *     outranks a town having a Tuesday, so a tick that finds the queue busy
 *     skips and tries again next minute. Nothing is lost: the window is still
 *     unswept and still there.
 *  3. Live an unbounded gap in one tick. A month off is ~240 model calls; doing
 *     them in one pass makes a tick that never ends and a GPU that never frees.
 *     A handful per world per tick, and a long gap fills over several minutes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/play-lib.php';

const XERIC_HEART_MAX_WINDOWS = 6;    // hours lived per world per tick
const XERIC_HEART_MODEL_WAIT  = 2.0;  // seconds to wait for the model before giving up

$loud = in_array('--loud', $argv, true);
$say  = function (string $s) use ($loud): void {
    if ($loud) fwrite(STDOUT, date('H:i:s') . '  ' . $s . "\n");
};

/**
 * The last sweep window this world has a guard for.
 *
 * Read from the guards rather than from a watermark of its own, because the
 * guards are what actually decide whether an hour gets lived — a second number
 * saying the same thing would be one more place for the two to disagree, and
 * the one that is wrong would be the one that skipped somebody's evening.
 */
function xeric_heart_last_window(PDO $db, int $size): int
{
    $pre = 'sweep:' . $size . ':';
    $max = 0;
    foreach (xeric_world_state_all($db) as $k => $_) {
        if (!str_starts_with((string)$k, $pre)) continue;
        $n = (int)substr((string)$k, strlen($pre));
        if ($n > $max) $max = $n;
    }
    return $max;
}

// ---------------------------------------------------------------------------

$ticked = 0;
$lived  = 0;

foreach (glob(xeric_web_sessions_dir() . '/*.json') ?: [] as $file) {
    $sid = basename($file, '.json');

    // The worker pattern: a process with no cookie is TOLD who it is acting for,
    // and everything downstream — which model, which fork, whose notifications —
    // follows from that one call. It validates the shape and returns '' for
    // anything that is not a session id, which is also the filter for whatever
    // else happens to be sitting in that directory.
    if (xeric_session_use($sid) === '') continue;

    $model = xeric_web_model($sid);
    if (!xeric_web_connected($model)) continue;          // nothing to think with

    try { $endpoint = xeric_play_endpoint($sid); }
    catch (Throwable $e) { continue; }

    $notify = xeric_web_notify($sid);

    foreach (xeric_play_my_dbs($sid) as $slug => $path) {
        try {
            $w = xeric_play_open($slug, $path);
        } catch (Throwable $e) {
            continue;                                     // a world that will not open is not this tick's problem
        }
        $T  = $w['template'];
        $db = $w['db'];

        // Fuses next to reminders, and for the same reason: no model, cheap,
        // and a promise must miss on time whether or not the queue is busy.
        // The tick is idempotent, and a miss detected here is stamped at the
        // hour the person actually waited (constructs.php).
        try { xeric_constructs_tick($T, $db, xeric_clock_now($db, $T)); } catch (Throwable $e) { /* next tick retries */ }

        // Reminders first, and whatever else happens. They need no model, they
        // are the thing somebody explicitly asked for, and they must not be
        // behind a queue that is busy or a world that has nothing to sweep.
        foreach (xeric_remind_fire($db, $notify, (string)($T['meta']['name'] ?? $slug)) as $r) {
            $say($slug . ': reminded, ' . (string)$r['text']);
        }

        if (xeric_clock_is_paused($db)) continue;         // stopped means stopped

        $size = (int)($T['events']['window_seconds'] ?? XERIC_SWEEP_WINDOW);
        $now  = xeric_clock_now($db, $T);
        $here = intdiv((int)$now['epoch'], $size);
        $last = xeric_heart_last_window($db, $size);

        // A world nobody has ever swept has no guards, so there is no gap to
        // measure — start it at the window it is standing in rather than at the
        // epoch, which would try to live through 1970.
        if ($last === 0) { $last = $here - 1; }
        if ($here <= $last) continue;                     // no whole window has passed

        // A person waiting on a reply outranks a town having a Tuesday.
        if (xeric_queue_busy()) { $say($slug . ': model busy, next time'); continue; }

        $hold = xeric_queue_take('tick', XERIC_HEART_MODEL_WAIT, 'heart:' . $slug);
        if (($hold['ok'] ?? false) !== true) { $say($slug . ': did not get the model'); continue; }

        try {
            $from = ($last + 1) * $size;
            $to   = min($here, $last + XERIC_HEART_MAX_WINDOWS) * $size;

            // THE SAME WINDOW AT BOTH ENDS. This loop reads the world's own
            // `events.window_seconds` into $size, walks in it, and reads its
            // guards back at `sweep:$size:` — and catchup, handed no `window`,
            // defaults to XERIC_SWEEP_WINDOW and stamps `sweep:3600:`. Any world
            // that set its own window would then write guards in a namespace
            // xeric_heart_last_window() never looks in: $last stays 0 forever,
            // every tick re-derives from scratch, and the heart is permanently
            // blind to hours it has already lived.
            $r = xeric_sweep_catchup($T, $db, $endpoint, $from, $to,
                                     ['window' => $size, 'clock' => (int)$now['epoch'],
                                      'stories' => $w['stories']]);
            $n = count((array)($r['events'] ?? []));
            $lived += $n;
            $ticked++;
            if ($n > 0) $say($slug . ': lived ' . $n . ' ' . ($n === 1 ? 'hour' : 'hours'));

            foreach ((array)($r['events'] ?? []) as $e) {
                if (xeric_notify_on($notify, 'hour')
                    || (xeric_notify_on($notify, 'spine') && !empty($e['on_spine']))) {
                    // What may leave the machine is notify.php's decision, not
                    // this loop's: a spine title never ships, an ordinary one
                    // rides as itself. See xeric_notify_hour_body.
                    xeric_notify_send($notify, xeric_notify_hour_body($e),
                        ['title' => (string)($T['meta']['name'] ?? $slug), 'tags' => 'hourglass']);
                }
            }

            // The phone. Same call the skip button makes, on the moment the
            // world actually landed on.
            $after = xeric_clock_now($db, $T);
            $ping  = xeric_proactive_check($T, $db, $endpoint, $after, ['stories' => $w['stories']]);
            if ($ping !== null) {
                $say($slug . ': ' . (string)($ping['name'] ?? 'somebody') . ' texted you');
                if (xeric_notify_on($notify, 'ping')) {
                    // The doorbell, not the letter — see xeric_notify_ping_body.
                    xeric_notify_send($notify, xeric_notify_ping_body($ping),
                        ['title' => (string)($T['meta']['name'] ?? $slug), 'tags' => 'speech_balloon',
                         'priority' => 4]);
                }
            }
        } catch (Throwable $e) {
            $say($slug . ': ' . $e->getMessage());
        } finally {
            xeric_queue_release($hold);
        }
    }
}

// THE PROOF THAT IT RAN. One file, stamped every pass, whether or not the pass
// found anything to do — because "nothing happened" and "nothing is running" are
// the two states this app most needs to tell apart, and from the outside they
// look identical.
//
// It is what the shelf's lamps flicker on: a heartbeat somebody can see, tied to
// the real thing rather than to a timer in the browser pretending.
@file_put_contents(
    (string)xeric_web_config()['data_dir'] . '/heart.tick',
    json_encode(['at' => time(), 'moved' => $ticked, 'lived' => $lived])
);

$say('tick: ' . $ticked . ' xerics moved, ' . $lived . ' hours lived');
exit(0);
