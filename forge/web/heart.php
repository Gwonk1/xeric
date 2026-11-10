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
 * FIVE THINGS IT WILL NOT DO.
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
 *  4. Spend a metered scene machine unless the world's template asked for
 *     prerender. Drawing a room before anybody looks at it is a courtesy on the
 *     owner's own electricity — the room the player is standing in, by default,
 *     on a loopback machine — and a spend on anything that bills, so on a
 *     machine like that it draws nothing until the template says
 *     `media.scene.prerender` out loud (xeric_scene_prerender). A glance at a
 *     room is consent to draw it; a heartbeat is consent to nothing.
 *  5. Spend past the world's budget (engine/budget.php, the template's
 *     `budget` block). A world whose token ceiling for today is spent stops
 *     living hours at the window where it ran out — the guards say which was
 *     the last one lived, the clock keeps moving, and world_state
 *     'budget.stopped' says why, so the inspector can. A world out of renders
 *     draws nothing ahead. A ceiling of 0 is no ceiling, and under the
 *     default `metered_only` the owner's own machine is never rationed.
 *
 * AND IT LEARNS, THE WAY A SKIP DOES. tick-worker.php has always wrapped the
 * hours it lives in the learn pass — settle what the last stretch left hanging,
 * pend what this one offered, distil when the crumbs are due — and this file
 * never did. A week lived only here judged no hour, counted no reply, and so
 * never turned a friendly conversation into trust: replies become trust only
 * in the distil pass's counting layer (trust.php). Now each pass that lives at
 * least one window runs xeric_learn_pass() (engine/learn.php, the same steps
 * in the same order as the skip) at the end of its hold, after the hours and
 * the phone, so what it pends includes whoever just texted. It runs INSIDE the
 * queue slot the hours took, so it never takes the model from a person, and
 * the model half is skipped (`no_model`, counting still runs) once that hold
 * has expired or the owner has drained the GPU — the skip's own courtesy. A
 * pass that lived no window learns nothing, and the helper refuses a second
 * run for the same landed moment, so it is idempotent per stretch like the
 * sweep guards it sits beside.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/chatter.php';   // the rooms talk on the beat
require_once XERIC_WEB_LIB . '/engine/director.php';   // the set, never the performance
// Sleep is loaded here, not discovered. It used to be called behind a
// function_exists() test with nothing on this include path
// loading it — only a lazy require deep in prompt recall — so a world slept
// when some earlier build happened to have pulled the file in, and otherwise
// never. A pass the heart owes every tick is a dependency, not a rumour.
require_once XERIC_WEB_LIB . '/engine/sleep.php';

const XERIC_HEART_MAX_WINDOWS = 6;    // hours lived per world per tick
const XERIC_HEART_MODEL_WAIT  = 2.0;  // seconds to wait for the model before giving up
const XERIC_HEART_SCENES      = 2;    // views drawn ahead per world per tick, when a world draws ahead
const XERIC_HEART_CHATTER_LINES = 8;  // model calls spent on idle room talk, per world per tick

/**
 * Idle talk in every room that has two people standing in it — or one, when
 * one of the people at the centre is standing there with them (chatter.php's
 * two-to-gossip-one-to-answer rule; the bodies are counted before the floor).
 *
 * ITS OWN PHASE, ABOVE THE SWEEP GATE. The sweep only runs when a whole world
 * HOUR has passed, and the heart ticks every sixty seconds — so a chatter pass
 * hung below that gate would fire once an hour at best and the rooms would sit
 * silent through fifty-nine ticks out of sixty. Rooms keep their own clock
 * (chatter:<place>, real seconds, set by the pace), and this phase exists to
 * give that clock a chance to come due.
 *
 * BOUNDED, AND ROTATED. Six rooms at busy is thirty-six calls, which is a tick
 * that never ends on a machine with one model slot. So there is a line budget
 * per world per tick, and the room the budget starts on ROTATES — without that,
 * the first two places in the list would take every line forever and the far
 * end of town would never say a word.
 *
 * Never fatal. A room that throws is a quiet room; the tick carries on.
 *
 * $endpointFor is the pinned-voice resolver (handle → endpoint, built below on
 * xeric_voice_endpoint) — threaded into the run so a pinned character's idle
 * line goes to THEIR machine, exactly as their chat turns already do.
 */
function xeric_heart_chatter(array $t, PDO $db, array $endpoint, callable $say, string $slug,
                             ?callable $endpointFor = null): int
{
    $now     = xeric_clock_now($db, $t);
    $realNow = time();
    // With the database, so a Director staging stands in the room it was put in.
    $present = xeric_world_who_is_where($t, $now, xeric_dead_handles($db), $db);

    $rooms = [];
    foreach ($present as $h => $row) {
        $where = (string)($row['where'] ?? '');
        if ($where !== '') $rooms[$where] = ($rooms[$where] ?? 0) + 1;
    }

    // Every body at the centre, not just the first: a room with ANY of them
    // standing in it opens the way a room with the owner in it always did.
    // Counted BEFORE the cast threshold below, because a present body is what
    // makes one shopkeeper enough — and by NAME, because the run tells each
    // head who is actually standing there (xeric_chatter_system's playersHere).
    $bodies = [];
    foreach (array_keys(xeric_players($db, $t)) as $pid) {
        $at = xeric_player_where($t, $db, (int)$pid);
        if ($at !== null) $bodies[$at][] = xeric_player_name($db, (int)$pid, $t);
    }

    // TWO TO GOSSIP, ONE TO ANSWER — chatter.php's own rule, finally reachable
    // from its one production caller. The cast floor used to be applied before
    // the bodies were counted, so a room holding one shopkeeper and a present
    // player was dropped from the rota here and the one-plus-you rule in
    // xeric_chatter_run could never fire. A room with a body in it stays on
    // one cast member; a room empty of players keeps the two-cast floor.
    // Public places only — see xeric_chatter_public. Filtered here as well as
    // in the run so the rota walks the rooms that can actually talk: with
    // homes in the list the budget's starting point spent most of its turns on
    // houses that were going to refuse.
    $rooms = array_keys(array_filter($rooms,
        fn($n, $p) => $n >= XERIC_CHATTER_MIN || ($n >= 1 && isset($bodies[$p])),
        ARRAY_FILTER_USE_BOTH));
    $rooms = array_values(array_filter($rooms, fn($p) => xeric_chatter_public($t, (string)$p)));
    if ($rooms === []) return 0;
    sort($rooms);                                    // a stable order for the rota to walk

    // Where the budget starts this tick. Stored so it survives the process,
    // which lives for one pass and dies.
    $rota = (int)(xeric_world_state_get($db, 'chatter_rota', '0') ?? 0);
    xeric_world_state_set($db, 'chatter_rota', (string)(($rota + 1) % max(1, count($rooms))), $realNow);

    $budget = XERIC_HEART_CHATTER_LINES;
    $said   = 0;

    for ($i = 0; $i < count($rooms) && $budget > 0; $i++) {
        $place = (string)$rooms[($rota + $i) % count($rooms)];
        try {
            $r = xeric_chatter_run($t, $db, $place, $endpoint, [
                'now'       => $now,
                'real_now'  => $realNow,
                'user_here' => isset($bodies[$place]),
                // WHO, by name — the presence data, so the cast talks to the
                // person actually standing there and not to the absent owner.
                'players_here' => $bodies[$place] ?? [],
                'max_lines' => $budget,
            ] + ($endpointFor !== null ? ['endpoint_for' => $endpointFor] : []));
        } catch (Throwable $e) {
            $say($slug . ': ' . $place . ' stayed quiet, ' . $e->getMessage());
            continue;
        }
        $n = (int)($r['said'] ?? 0);
        if ($n > 0) {
            $budget -= $n;
            $said   += $n;
            $say($slug . ': ' . $n . ' said at ' . xeric_world_place_name($t, $place)
                 . ' (' . (string)$r['pace'] . ')');
        }
    }
    return $said;
}

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

/**
 * The rooms, drawn ahead of being looked at (thing 4 above).
 *
 * Whether to spend at all is the engine's to say — xeric_scene_ahead reads
 * the world's prerender, the machine's cost and whether it answers — and the
 * one rule here is the heart's own: a person waiting on a reply outranks a
 * picture of a room, and on a local machine the picture and the reply are the
 * same GPU, so a busy queue skips the rooms and the next tick draws them. The
 * machine is resolved under the session this pass is acting for, because the
 * machines screen is per session and one owner's key must never draw another
 * owner's room; $up remembers, for the length of the pass, which machines
 * answered, so twelve worlds on one machine are one probe and not twelve.
 *
 * MOST MINUTES THIS IS A FEW READS. The heart calls this every tick for every
 * world, and composing every room's look and sound to INSERT OR IGNORE rows
 * that already exist would be the most expensive way to do nothing. So
 * xeric_scene_ahead stops at a cheap gate first: a key over what can move a
 * fingerprint (where the player stands, the light, who is where, the sky, the
 * dark rooms — xeric_scene_ahead_key, kept as world_state 'scene.ahead') that
 * has not changed, with no row pending or working for this machine, is the
 * end of it — no brief, no probe, no reap.
 */
function xeric_heart_rooms(string $slug, array $w, callable $say, array &$up): int
{
    if (xeric_queue_busy()) return 0;
    try {
        $r = xeric_scene_ahead($w['template'], $w['db'], dirname((string)$w['db_path']) . '/scenes',
                               null, XERIC_HEART_SCENES, $up);
    } catch (Throwable $e) {
        $say($slug . ': rooms, ' . $e->getMessage());
        return 0;
    }
    if ($r['mode'] === 'off') return 0;
    // Out of renders: one quiet line, the engine's own sentence, below.
    if ($r['enqueued'] > 0) $say($slug . ': ' . $r['enqueued'] . ' to draw ahead (' . implode(', ', $r['keys']) . ')');
    if ($r['done'] > 0)     $say($slug . ': drew ' . $r['done'] . ' ' . ($r['done'] === 1 ? 'view' : 'views'));
    foreach ($r['notes'] as $n) $say($slug . ': ' . $n);
    return $r['done'];
}

/**
 * One world's live views, on the heart's pass (xeric_stream_pass): the
 * abandoned ones ended and metered with no network, then up to $left of the
 * DELETEs owed sent, short. Returns how many were sent.
 */
function xeric_heart_streams(string $slug, array $w, callable $say, int $left): int
{
    try {
        $ep = null;
        try { $ep = xeric_scene_endpoint(); } catch (Throwable $e) { $ep = null; }
        $r = xeric_stream_pass($w['template'], $w['db'], $ep, $left);
        if ($r['told'] > 0) {
            $say($slug . ': told ' . $r['told'] . ' scene ' . ($r['told'] === 1 ? 'machine' : 'machines') . ' a live view had ended');
        }
        return $r['told'];
    } catch (Throwable $e) {
        $say($slug . ': live views, ' . $e->getMessage());
        return 0;
    }
}

// ---------------------------------------------------------------------------

// Not when included by a test that wants the functions without the pass —
// the same door mcp.php holds, for the same reason: the pass below ends in
// exit(0), and a suite that required this file for xeric_heart_chatter()
// would die mid-test without it.
if (realpath($argv[0] ?? '') !== __FILE__) return;

$ticked  = 0;
$lived   = 0;
$drawn   = 0;
$sceneUp = [];   // which scene machines answered, asked once per machine per pass
$tellLeft = XERIC_STREAM_TELL_PER_TICK;   // owed stream DELETEs this pass may still send, every world together

foreach (glob(xeric_web_sessions_dir() . '/*.json') ?: [] as $file) {
    $sid = basename($file, '.json');

    // The worker pattern: a process with no cookie is TOLD who it is acting for,
    // and everything downstream — which model, which fork, whose notifications —
    // follows from that one call. It validates the shape and returns '' for
    // anything that is not a session id, which is also the filter for whatever
    // else happens to be sitting in that directory.
    if (xeric_session_use($sid) === '') continue;

    $model = xeric_web_model($sid);
    // NOTHING TO THINK WITH is not nothing to do: a live view needs no model,
    // and one left running bills whether or not anybody can talk. Such a
    // session's worlds get their live views seen to and nothing else.
    $thinks = xeric_web_connected($model);
    $endpoint = null;
    if ($thinks) {
        try { $endpoint = xeric_play_endpoint($sid); }
        catch (Throwable $e) { $thinks = false; }
    }

    $notify = xeric_web_notify($sid);

    foreach (xeric_play_my_dbs($sid) as $slug => $path) {
        try {
            $w = xeric_play_open($slug, $path);
        } catch (Throwable $e) {
            continue;                                     // a world that will not open is not this tick's problem
        }
        $T  = $w['template'];
        $db = $w['db'];

        // LIVE VIEWS NOBODY IS WATCHING: ended here too (a world nobody opens
        // still has its abandoned sessions closed), and the DELETEs the read
        // path owed sent — bounded across the whole pass, short each, because
        // a dead machine must not hold up every other world's tick.
        $tellLeft -= xeric_heart_streams($slug, $w, $say, $tellLeft);
        if (!$thinks) continue;

        // WHOSE MODEL SPEAKS FOR WHOM. say.php and watch.php already route a
        // pinned character's turns to their own machine (xeric_voice_endpoint);
        // until this resolver existed, the heart was the one speaker that did
        // not — a pinned character's pings, dreams and idle chatter came off
        // the world's engine, wrong-voiced and paying a cold prefix. A
        // RESOLVER, NOT A ROUND-ROBIN: handle → endpoint, memoized for the
        // tick, so each character keeps landing on the one machine where their
        // prefix is warm. Unpinned characters resolve to the play endpoint
        // exactly as before, and sweeps never come through here at all —
        // an hour has no single voice to route (play-lib.php, deliberate).
        $voiceCache = [];
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

        // Fuses next to reminders, and for the same reason: no model, cheap,
        // and a promise must miss on time whether or not the queue is busy.
        // The tick is idempotent, and a miss detected here is stamped at the
        // hour the person actually waited (constructs.php).
        try { xeric_constructs_tick($T, $db, xeric_clock_now($db, $T)); } catch (Throwable $e) { /* next tick retries */ }
        // Cast-to-cast fuses (the constructs agent's seam), when that lands.
        if (function_exists('xeric_constructs_cast_tick')) {
            try { xeric_constructs_cast_tick($T, $db, xeric_clock_now($db, $T)); } catch (Throwable $e) { /* next tick retries */ }
        }

        // Reminders first, and whatever else happens. They need no model, they
        // are the thing somebody explicitly asked for, and they must not be
        // behind a queue that is busy or a world that has nothing to sweep.
        // The timezone is the PERSON's (user.timezone), because a reminder is
        // the one clock in the engine that is not the world's — it feeds the
        // "you asked to be reminded on Thursday at 09:00" phrasing the fired
        // notification now carries (xeric_notify_remind_body).
        foreach (xeric_remind_fire($db, $notify, (string)($T['meta']['name'] ?? $slug), null,
                                   (string)($T['user']['timezone'] ?? 'UTC')) as $r) {
            $say($slug . ': reminded, ' . (string)$r['text']);
        }

        // -- the film develops ---------------------------------------------
        // One frame per world per tick, before the pause gate on purpose: a
        // stopped clock is a fact about world-time and a darkroom runs on
        // real time. This was the missing half of the reaper story — backfill
        // enqueues every portrait and place on open, but only the banner's
        // one press and a character sending a photo ever developed anything,
        // so a consented world with a live image machine sat on its film
        // forever. xeric_photo_reap gates itself on consent and on the image
        // machine answering, so a world that never said yes spends nothing
        // here; the text-queue courtesy is the GPU being one card.
        if (!xeric_queue_busy()) {
            try {
                $pr = xeric_photo_reap($T, $db, dirname((string)$w['db_path']) . '/photos', null, 1);
                if ((int)$pr['done'] > 0) $say($slug . ': developed a photograph');
            } catch (Throwable $e) {
                // Photos owe the heart nothing.
            }
        }

        // THE ROOMS NOBODY IS IN ANY MORE, before the hours: a live room that
        // has gone quiet, lost its player or been stopped is sealed (no model,
        // free), so the sweep below is not holding anybody at a table that
        // is not there. The heart NEVER starts a beat — a room nobody is
        // watching says nothing — but it may spend the diary calls a sealed
        // room's close owes, under the usual courtesy: only on an idle line,
        // and only if the slot comes quickly.
        // What this world can still afford today, in tokens, on this model.
        $tokCostly = xeric_budget_llm_costly($endpoint);
        $tokens = function () use ($T, $db, $tokCostly): array {
            return xeric_budget_allows($T, $db, xeric_clock_now($db, $T), 'tokens', 1, $tokCostly);
        };

        try {
            if (xeric_play_room_tick($w) !== []) $say($slug . ': a conversation went quiet');
            if (xeric_room_seals($db) !== [] && !xeric_queue_busy() && $tokens()['ok']) {
                $rh = xeric_queue_take('say', XERIC_HEART_MODEL_WAIT, 'heart:' . $slug);
                if (($rh['ok'] ?? false) === true) {
                    try {
                        // A model that is not answering keeps the seal for the
                        // next pass; it never lands the close without its diaries.
                        $n = count(xeric_play_room_land_up($w, $endpoint) ?? []);
                        if ($n > 0) $say($slug . ': closed ' . $n . ' conversation' . ($n === 1 ? '' : 's'));
                    } finally {
                        xeric_queue_release($rh['hold'] ?? $rh);
                    }
                }
            }
        } catch (Throwable $e) {
            $say($slug . ': the room, ' . $e->getMessage());
        }

        if (xeric_clock_is_paused($db)) continue;         // stopped means stopped

        // -- the rooms talk ------------------------------------------------
        // Before the sweep gate, on purpose: see xeric_heart_chatter. A person
        // waiting on a reply still outranks a town making small talk, which is
        // what the busy check is for.
        // And small talk is spend like any other: a world that is out of tokens
        // for the day has quiet rooms until tomorrow (budget.php).
        if (!xeric_queue_busy() && $tokens()['ok']) {
            $chold = xeric_queue_take('tick', XERIC_HEART_MODEL_WAIT, 'chatter:' . $slug);
            if (($chold['ok'] ?? false) === true) {
                try { xeric_heart_chatter($T, $db, $endpoint, $say, $slug, $endpointFor); }
                catch (Throwable $e) { $say($slug . ': chatter, ' . $e->getMessage()); }
                finally { xeric_queue_release($chold); }
            }
        }

        // THE DIRECTOR (engine/director.php), before the hours, so a staging it
        // makes for the next hour is standing there when the sweep reaches it.
        // No model, once per world-hour, and nothing at all unless the
        // template's `director.rules` lists a rule — it is opt-in.
        try {
            foreach (xeric_director_tick($T, $db, xeric_clock_now($db, $T))['staged'] as $st) {
                $say($slug . ': staged ' . implode(' and ', $st['who']) . ' at ' . $st['where'] . ', ' . $st['why']);
            }
        } catch (Throwable $e) {
            $say($slug . ': the director, ' . $e->getMessage());
        }

        $size = (int)($T['events']['window_seconds'] ?? XERIC_SWEEP_WINDOW);
        $now  = xeric_clock_now($db, $T);
        $here = intdiv((int)$now['epoch'], $size);
        $last = xeric_heart_last_window($db, $size);

        // A world nobody has ever swept has no guards, so there is no gap to
        // measure — start it at the window it is standing in rather than at the
        // epoch, which would try to live through 1970.
        if ($last === 0) { $last = $here - 1; }
        if ($here <= $last) {                             // no whole window has passed
            // The rooms do not wait for an hour: who is standing where turns
            // on the half-hour as often as on the hour.
            $drawn += xeric_heart_rooms($slug, $w, $say, $sceneUp);
            continue;
        }

        // A WORLD THAT CANNOT AFFORD ITS TUESDAY PAUSES WHERE IT RAN OUT. Not
        // the clock — the clock is the world's date, and the date turning over
        // is what gives the budget back — only the living: the windows stay
        // unswept, and the next world date picks them up.
        $afford = $tokens();
        if (!$afford['ok']) {
            xeric_budget_stopped($db, $now, $afford);
            $say($slug . ': not living hours, ' . $afford['reason']);
            $drawn += xeric_heart_rooms($slug, $w, $say, $sceneUp);
            continue;
        }
        xeric_world_state_delete($db, 'budget.stopped');

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
            $sweepOpts = ['window' => $size, 'clock' => (int)$now['epoch'], 'stories' => $w['stories']];
            // EACH HOUR IN ITS CENTRE'S OWN VOICE: a character pinned to a
            // machine has the hours they are at the centre of written there,
            // in their own llama.cpp slot; everyone else the world default.
            $sweepOpts['endpoint_for'] = xeric_play_sweep_endpoints($w + ['sid' => $sid, 'endpoint' => $endpoint]);
            // UNDER A CEILING, ASKED BEFORE EACH WINDOW, so the world stops at
            // the hour it ran out rather than six hours past it — and asked
            // INSIDE the one catchup call, not by walking windows here: the
            // give-up after a dead model, the event cap and the watermark live
            // in xeric_sweep_catchup and nowhere else. A walk out here buried
            // every window it handed in, failed or not, and capped differently
            // from the one call, so a budgeted world lived more hours than an
            // unbudgeted one.
            $b = xeric_budget($T);
            $short = null;
            if (!($b['tokens_per_day'] <= 0 || ($b['metered_only'] && !$tokCostly))) {
                $sweepOpts['afford'] = function (int $win) use ($tokens, &$short): bool {
                    $a = $tokens();
                    if (!$a['ok']) $short = $a;
                    return (bool)$a['ok'];
                };
            }
            // The sweep reads presence with no database, so the stagings ride
            // in on the template; a world with none gets $T back untouched.
            // From the first unswept window on: what ended before it is history
            // the catchup will never ask about.
            $r = xeric_sweep_catchup(xeric_director_dress($T, $db, (int)$from), $db, $endpoint, $from, $to, $sweepOpts);
            if (($r['stopped'] ?? '') === 'budget' && is_array($short)) {
                xeric_budget_stopped($db, xeric_clock_now($db, $T), $short);
                $say($slug . ': stopped living hours, ' . (string)($short['reason'] ?? 'budget'));
            }
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
            $broke = !$tokens()['ok'];          // spent mid-pass: no texts, no model half of the learn pass
            $ping  = $broke ? null : xeric_proactive_check($T, $db, $endpoint, $after,
                ['stories' => $w['stories'], 'endpoint_for' => $endpointFor]);
            $livedEvents = (array)($r['events'] ?? []);
            if ($ping !== null) {
                $say($slug . ': ' . (string)($ping['name'] ?? 'somebody') . ' texted you');
                if (xeric_notify_on($notify, 'ping')) {
                    // The doorbell, not the letter — see xeric_notify_ping_body.
                    xeric_notify_send($notify, xeric_notify_ping_body($ping),
                        ['title' => (string)($T['meta']['name'] ?? $slug), 'tags' => 'speech_balloon',
                         'priority' => 4]);
                }
            }

            // THE LEARN PASS, last thing under the hold (header). Same order
            // as the skip: judge the last stretch, write down this one —
            // including the text just sent — and distil when due.
            $lp = xeric_learn_pass($db, $T, $endpoint, (int)$now['epoch'], $livedEvents,
                                   is_array($ping) ? $ping : null, (int)$after['epoch'],
                                   ['no_model' => xeric_queue_expired($hold['hold'] ?? $hold) || $broke, 'timeout' => 60]);
            if ($lp['learned'] > 0) $say($slug . ': learned ' . $lp['learned'] . ' thing' . ($lp['learned'] === 1 ? '' : 's'));

            // Sleep, the consolidation pass, under the same hold. Required at
            // the top of this file, so it runs every tick; the pass itself
            // decides whether this tick is inside tonight's window.
            try { xeric_sleep_pass($T, $db, $after, $endpoint); }
            catch (Throwable $e) { $say($slug . ': sleep, ' . $e->getMessage()); }
        } catch (Throwable $e) {
            $say($slug . ': ' . $e->getMessage());
        } finally {
            // The slot itself is under 'hold' — the flock handle and the hour
            // it was taken at live there, not on the take's envelope, which
            // carries only the ticket. Released by the envelope, the lock file
            // stayed held until the process exited and the queue's average
            // learned that every heart hold took one second.
            xeric_queue_release($hold['hold'] ?? $hold);
        }

        // THE ROOMS, AFTER THE HOURS. Drawn once the sweep has lived them, so
        // a picture shows the world the hours left behind — the death, the
        // narrator's rain — and not the one they found.
        $drawn += xeric_heart_rooms($slug, $w, $say, $sceneUp);
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
    json_encode(['at' => time(), 'moved' => $ticked, 'lived' => $lived, 'drawn' => $drawn])
);

$say('tick: ' . $ticked . ' xerics moved, ' . $lived . ' hours lived, ' . $drawn . ' views drawn');
exit(0);
