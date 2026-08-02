<?php
/**
 * queue.php — one model slot, shared honestly.
 *
 * The constraint that shapes everything: one GPU runs one model at a time, and
 * on a self-hosted instance it is shared with whatever else that machine is for.
 * So a shared instance must serialize — a global in-flight lock, a short queue,
 * and an honest "someone else is talking — you're next" state. Never let the
 * web app starve the machine it is running on.
 *
 * What was here before was half of that: an flock, and a 409 for whoever lost
 * the race. A 409 is not a queue — it is a race the fastest tab wins, over and
 * over, and it reads to the visitor as "the demo is broken" rather than "the
 * demo is busy". This file is the other half.
 *
 * THE SHAPE, and why it is two files rather than one:
 *
 *  • THE SLOT IS STILL AN flock (data/model.lock). That is not tradition, it is
 *    the one property no bookkeeping can fake: the kernel releases it when the
 *    process dies. A worker killed mid-build, an apache restart, a segfault —
 *    the slot is free the instant the process is gone, with nothing to time out
 *    and nothing to clean up. A pid file or a "holder" row alone would wedge the
 *    whole demo the first time something died badly.
 *
 *  • THE ORDER IS A FILE (data/queue/line.json). flock has no fairness: whoever
 *    happens to ask first after a release wins, so a busy demo can starve
 *    somebody indefinitely. So a request joins a line, and may only try the
 *    flock when it is at the head of it. FIFO, and — the part the visitor
 *    actually sees — a position and an estimate while they wait, because
 *    "you're 2nd in line, about 40 seconds" is a fact and a spinner is a lie.
 *    A depth cap alone was not fairness — eight tabs of one visitor filled the
 *    line and everybody else met an instant refusal — so the line also holds at
 *    most XERIC_QUEUE_PER_VISITOR things per visitor at a time.
 *
 *  • AND IF THE LINE FILE IS GONE, THE FLOCK STILL DECIDES. An unwritable
 *    data dir used to swallow every mutation silently and turn the whole demo
 *    into "your place in the line timed out". It now degrades: no order, no
 *    positions, one logged complaint, and the one property correctness rests on
 *    (see above) intact. An unopenable SLOT file is the opposite — that is not a
 *    queue at all, and it is said as its own refusal rather than as a wait.
 *
 *  • THE ETA IS MEASURED, NOT GUESSED. Each release folds its own duration into
 *    a rolling average per kind of work, so the estimate tracks whatever the
 *    model is doing today rather than whatever it was doing when this was
 *    written.
 *
 *  • TWO WAYS THE OWNER TAKES THE MACHINE BACK. A hard cap on any single hold
 *    (XERIC_QUEUE_HOLD_MAX), which long jobs check between steps and stop; and a
 *    flag file, `data/queue.drained` — `touch` it and the demo stops asking for
 *    the model at all, in mid-skip, without a deploy, a restart, or a wait:
 *
 *        ssh <host> touch "$XERIC_DATA/queue.drained"     # take it back
 *        ssh <host> rm    "$XERIC_DATA/queue.drained"     # give it back
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';

// ===========================================================================
// THE TUNABLES
// ===========================================================================

/** Longest a request may sit in line before being told, honestly, to come back. */
const XERIC_QUEUE_WAIT_MAX = 180;

/**
 * How long the synchronous chat turn waits in-request.
 *
 * Well under any proxy's patience (Cloudflare gives up on this origin at about
 * 100s) and under mod_php's ceiling, because a browser watching a request that
 * the proxy kills learns nothing. Past this the turn answers with a position.
 */
const XERIC_QUEUE_SAY_WAIT = 25;

/**
 * THE HARD CAP ON ONE HOLD. The demo may never own the GPU longer than this in
 * one go, whatever it is in the middle of. Long jobs check it between steps and
 * stop cleanly; a build (2–3 minutes) fits inside it with room, a runaway skip
 * does not.
 */
const XERIC_QUEUE_HOLD_MAX = 420;

/** A waiting ticket nobody has polled for this long has died with its process. */
const XERIC_QUEUE_TICKET_TTL = 45;

/** When this many are already waiting, the honest answer is "not today". */
const XERIC_QUEUE_DEPTH_MAX = 8;

/**
 * How many things ONE visitor may have on the model at once.
 *
 * A depth cap alone is not fairness: eight tabs of one person fill the line and
 * own the next hour of GPU while everybody else meets an immediate refusal. Two
 * is deliberate rather than one — a build running while its owner talks to a
 * character in another world is an ordinary thing to be doing, and refusing it
 * would be the cap punishing normal use. The third is the one that is somebody
 * else's turn.
 */
const XERIC_QUEUE_PER_VISITOR = 2;

/** Seconds between polls while waiting. Short enough to feel handed over, cheap enough to spin. */
const XERIC_QUEUE_POLL = 250000;      // microseconds

/** What a hold costs before anything has been measured, per kind of work. */
const XERIC_QUEUE_GUESS = ['forge' => 150, 'tick' => 50, 'say' => 10, 'reroll' => 35];

// ---------------------------------------------------------------------------
// Where it lives
// ---------------------------------------------------------------------------

function xeric_queue_dir(): string   { return xeric_web_dir((string)xeric_web_config()['data_dir'] . '/queue'); }
function xeric_queue_path(): string  { return xeric_queue_dir() . '/line.json'; }
function xeric_queue_slot(): string  { return xeric_web_dir((string)xeric_web_config()['data_dir']) . '/model.lock'; }

/** The flag file. Its presence means: the machine's owner wants the GPU back. */
function xeric_queue_drain_path(): string
{
    return xeric_web_dir((string)xeric_web_config()['data_dir']) . '/queue.drained';
}

function xeric_queue_drained(): bool { return is_file(xeric_queue_drain_path()); }

// ---------------------------------------------------------------------------
// The line
// ---------------------------------------------------------------------------

/**
 * The line file cannot be opened, so there is no line — only the flock.
 *
 * This happens for one real reason: a CLI tool run as the wrong uid left the
 * data dir owned by somebody else. It used to be swallowed, and every mutation
 * with it, which turned every build, skip, reroll and chat turn into "your place
 * in the line timed out" — a sentence describing the exact opposite of what had
 * happened. So it is latched, logged once, said out loud on the status line, and
 * then DEGRADED RATHER THAN REFUSED: fairness is bookkeeping and can be lost,
 * but the flock is the only property correctness depends on, and it still works.
 * A degraded queue serialises the GPU honestly and simply cannot promise anybody
 * a position while it lasts.
 */
function xeric_queue_degraded(bool $set = false): bool
{
    static $bad = false;
    if ($set && !$bad) {
        $bad = true;
        error_log('xeric: ' . xeric_queue_path() . ' is not writable, the queue is running on the flock alone');
    }
    return $bad;
}

/**
 * Read-modify-write the line under its own lock.
 *
 * Every mutation goes through here so there is exactly one place that can leave
 * the file half-written, and it does not. The reap runs on the way in, which is
 * why nothing else has to remember to run it.
 */
function xeric_queue_edit(callable $fn, ?int $now = null)
{
    $now = $now ?? time();
    $fh = @fopen(xeric_queue_path(), 'c+');
    if (!$fh) {                                    // no line to keep; see xeric_queue_degraded()
        xeric_queue_degraded(true);
        $state = xeric_queue_blank();
        return $fn($state, $now);
    }

    @flock($fh, LOCK_EX);
    $raw = (string)stream_get_contents($fh);
    $state = json_decode($raw, true);
    if (!is_array($state)) $state = xeric_queue_blank();
    $state = xeric_queue_reap($state, $now);

    $ret = $fn($state, $now);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state, JSON_UNESCAPED_SLASHES));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $ret;
}

function xeric_queue_blank(): array
{
    return ['line' => [], 'holder' => null, 'avg' => []];
}

/** The line, read only. */
function xeric_queue_state(?int $now = null): array
{
    $raw = @file_get_contents(xeric_queue_path());
    $s = $raw === false ? null : json_decode($raw, true);
    if (!is_array($s)) $s = xeric_queue_blank();
    return xeric_queue_reap($s, $now ?? time());
}

/**
 * Drop what is no longer real.
 *
 *  • A waiter nobody has polled for XERIC_QUEUE_TICKET_TTL: its process is gone
 *    (closed tab, killed worker, dropped connection). It must not hold the head
 *    of the line against everybody behind it — this is the "stale tickets time
 *    out" rule, and it is why a dead holder cannot wedge the queue.
 *  • A holder record with the slot lock free: the process died, the kernel
 *    already released the slot, and the record is a ghost. The FLOCK is the
 *    truth; the record is only there so the queue can say what is happening.
 *
 * WHICH IS WHY AN OVERRUN IS MARKED AND NOT DELETED. A hold past the hard cap
 * whose process is STILL HOLDING THE FLOCK is not a ghost, it is a problem: the
 * record used to be dropped anyway, so the queue advertised "the model is free"
 * while nobody on earth could take it, and the ticket at the head of the line
 * was told busy=false, eta=0 and then failed to get the lock. The kernel says
 * what is true; this only decides how loudly to say it.
 */
function xeric_queue_reap(array $state, int $now): array
{
    $line = [];
    foreach ((array)($state['line'] ?? []) as $t) {
        if (!is_array($t) || (string)($t['id'] ?? '') === '') continue;
        if ((int)($t['seen'] ?? 0) < $now - XERIC_QUEUE_TICKET_TTL) continue;
        $line[] = $t;
    }
    $state['line'] = $line;

    $h = $state['holder'] ?? null;
    if (is_array($h)) {
        if (!xeric_queue_busy()) {
            $state['holder'] = null;
        } elseif ((int)($h['at'] ?? 0) < $now - (XERIC_QUEUE_HOLD_MAX + 60)) {
            $state['holder']['over'] = true;
        }
    }
    $state['avg'] = (array)($state['avg'] ?? []);
    return $state;
}

/**
 * What this holder still owes the person behind it, in seconds.
 *
 * A hold past its own cap and still on the flock has stopped telling us anything
 * about when it will be done, so the honest floor is "not now, ask again
 * shortly" rather than an estimate the measurement no longer supports.
 */
function xeric_queue_holder_eta(array $state, array $holder, int $now): int
{
    if (!empty($holder['over'])) return 30;
    $spent = max(0, $now - (int)($holder['at'] ?? $now));
    return max(5, xeric_queue_avg($state, (string)($holder['what'] ?? 'say')) - $spent);
}

/** Is the model slot held right now? The kernel's answer, not ours. */
function xeric_queue_busy(): bool
{
    $fh = @fopen(xeric_queue_slot(), 'c+');
    if (!$fh) return false;
    $free = @flock($fh, LOCK_EX | LOCK_NB);
    if ($free) @flock($fh, LOCK_UN);
    fclose($fh);
    return !$free;
}

// ---------------------------------------------------------------------------
// Tickets
// ---------------------------------------------------------------------------

/** What the demo calls each kind of hold, for a sentence about it. */
function xeric_queue_noun(string $what): string
{
    return ['forge'  => 'a xeric building',
            'tick'   => 'a skip running',
            'reroll' => 'a reroll running',
            'say'    => 'a message being answered'][$what] ?? 'something on the model';
}

/**
 * A refusal from the door, in the shape every endpoint already answers with.
 *
 * Built HERE rather than by re-entering the queue for its sentence: the old way
 * asked for another ticket purely to read the message off it, which on a line
 * that had just emptied could WIN the model and then walk away holding it.
 */
function xeric_queue_no(string $kind, string $what, int $retry = 60): array
{
    if ($kind === 'yours') {
        $message = 'You already have ' . xeric_queue_noun($what) . ' on this machine. There is one GPU here '
            . 'and it is shared with everybody else in the demo, so it takes ' . XERIC_QUEUE_PER_VISITOR
            . ' things per visitor at a time, this one is yours again the moment the first is done.';
        $phrase = 'you are already in the line';
    } elseif ($kind === 'drained') {
        $message = 'The machine\'s owner has taken the GPU back for a while, this demo runs on their own '
            . 'workstation, and that comes first. Nothing is lost; try again shortly.';
        $phrase = 'the model has been taken back';
    } else {
        $message = 'There are already ' . XERIC_QUEUE_DEPTH_MAX . ' people waiting for this one GPU. '
            . 'Rather than put you at the back of a line that long, the honest answer is: give it a minute.';
        $phrase = 'the line is full';
    }
    return ['ok' => false, 'kind' => $kind, 'ahead' => $kind === 'full' ? XERIC_QUEUE_DEPTH_MAX : 0,
            'eta' => 0, 'waited' => 0.0,
            'retry_after' => $retry, 'phrase' => $phrase, 'message' => $message];
}

/** The owner has the GPU: the same sentence wherever it is said. */
function xeric_queue_drained_no(): array { return xeric_queue_no('drained', '', 120); }

/**
 * Take a ticket.
 *
 * @param string $what  forge · tick · say — what the hold will be spent on, which
 *                      is how the estimate knows what to promise.
 * @param string $who   a short, non-identifying tag (the first bytes of a session
 *                      id) so two tabs of one visitor can be told apart in the
 *                      line. Never the whole id and never an address.
 * @param array|null $why OUT: which refusal this was, as a sentence, when the
 *                      ticket comes back empty. Two different noes — "everybody
 *                      is waiting" and "you are already waiting twice" — and a
 *                      visitor deserves to be told which one they met.
 * @return string ticket id, or '' when the queue cannot honestly be joined
 */
function xeric_queue_join(string $what, string $who = '', ?array &$why = null): string
{
    $id = bin2hex(random_bytes(8));
    $tag = substr($who, 0, 8);
    $refused = '';

    $out = (string)xeric_queue_edit(function (array &$state, int $now) use ($id, $what, $tag, &$refused) {
        if (count($state['line']) >= XERIC_QUEUE_DEPTH_MAX) { $refused = 'full'; return ''; }

        // Per-visitor fairness. An empty tag is a hand-run worker with no session
        // to be fair between, so it is not counted against anybody.
        if ($tag !== '') {
            $mine = 0;
            foreach ($state['line'] as $t) if ((string)($t['who'] ?? '') === $tag) $mine++;
            $h = $state['holder'] ?? null;
            if (is_array($h) && (string)($h['who'] ?? '') === $tag) $mine++;
            if ($mine >= XERIC_QUEUE_PER_VISITOR) { $refused = 'yours'; return ''; }
        }

        $state['line'][] = ['id' => $id, 'at' => $now, 'seen' => $now,
                            'what' => $what, 'who' => $tag];
        return $id;
    });

    if ($out === '') $why = xeric_queue_no($refused ?: 'full', $what, $refused === 'yours' ? 30 : 60);
    return $out;
}

/** Give up a place in the line. Safe to call twice, and on a ticket that was never real. */
function xeric_queue_leave(string $ticket): void
{
    if ($ticket === '') return;
    xeric_queue_edit(function (array &$state) use ($ticket) {
        $state['line'] = array_values(array_filter($state['line'],
            fn($t) => (string)($t['id'] ?? '') !== $ticket));
        return null;
    });
}

/**
 * Where a ticket stands, and how long that probably is.
 *
 * Polling is also how a waiter proves it is still alive, so this refreshes
 * `seen` — a ticket that stops asking stops being in the line.
 *
 * @return array{known:bool,ahead:int,depth:int,eta:int,busy:bool,phrase:string}
 */
function xeric_queue_where(string $ticket, ?int $now = null): array
{
    // No line is being kept, so nobody is ahead of anybody and the flock decides
    // on its own. Saying "your place timed out" here would be a lie about a
    // ticket that was never written down (xeric_queue_degraded).
    if (xeric_queue_degraded()) {
        $busy = xeric_queue_busy();
        $eta = $busy ? 30 : 0;               // a guess, because nothing is being measured
        return ['known' => $ticket !== '', 'ahead' => $busy ? 1 : 0, 'depth' => 0, 'eta' => $eta,
                'busy' => $busy, 'phrase' => xeric_queue_phrase($busy ? 1 : 0, $eta)];
    }

    return (array)xeric_queue_edit(function (array &$state, int $now) use ($ticket) {
        $ahead = 0;
        $known = false;
        $eta = 0;

        // Whatever is running now has to finish before anybody's turn starts.
        $h = $state['holder'] ?? null;
        if (is_array($h)) $eta += xeric_queue_holder_eta($state, $h, $now);

        foreach ($state['line'] as $i => $t) {
            if ((string)($t['id'] ?? '') === $ticket) {
                $known = true;
                $state['line'][$i]['seen'] = $now;
                break;
            }
            $ahead++;
            $eta += xeric_queue_avg($state, (string)($t['what'] ?? 'say'));
        }
        if (!$known) $ahead = count($state['line']);

        $busy = is_array($h);
        $inFront = $ahead + ($busy ? 1 : 0);
        return ['known' => $known, 'ahead' => $inFront, 'depth' => count($state['line']),
                'eta' => (int)$eta, 'busy' => $busy,
                'phrase' => xeric_queue_phrase($inFront, (int)$eta)];
    }, $now);
}

/** The measured cost of one hold of this kind, or the built-in guess. */
function xeric_queue_avg(array $state, string $what): int
{
    $a = (int)($state['avg'][$what] ?? 0);
    if ($a > 0) return $a;
    return (int)(XERIC_QUEUE_GUESS[$what] ?? 20);
}

/**
 * Try to take the slot, right now, without waiting.
 *
 * Two gates before the flock: the drain flag (the owner's), and the head of the
 * line (everybody else's). Only the ticket at the front may even attempt it, so
 * the lock cannot be won out of turn.
 *
 * @return array{ok:bool,hold?:array,ahead:int,eta:int,phrase:string,kind?:string,message?:string}
 */
function xeric_queue_try(string $ticket, ?int $now = null): array
{
    if (xeric_queue_drained()) return ['ahead' => 0, 'eta' => 0] + xeric_queue_drained_no();

    $where = xeric_queue_where($ticket, $now);
    if (!$where['known']) {
        return ['ok' => false, 'kind' => 'lost', 'ahead' => (int)$where['ahead'], 'eta' => (int)$where['eta'],
                'phrase' => $where['phrase'],
                'message' => 'Your place in the line timed out while nothing was listening. Ask again and '
                    . 'you will get a fresh one.'];
    }
    if ((int)$where['ahead'] > 0) {
        return ['ok' => false, 'kind' => 'waiting'] + $where;
    }

    $fh = @fopen(xeric_queue_slot(), 'c+');
    if (!$fh) {
        // The slot file itself cannot be opened, which is not a queue at all —
        // it is a data directory this process may not write. Waiting for it
        // would spin to the deadline and then blame the line, so say the true
        // thing instead and let the owner find it in the log.
        error_log('xeric: cannot open the model slot ' . xeric_queue_slot() . ', the data dir is not writable');
        return ['ok' => false, 'kind' => 'broken', 'ahead' => 0, 'eta' => 0, 'retry_after' => 300,
                'phrase' => 'the model slot cannot be opened',
                'message' => 'This machine cannot get at the file it uses to take turns on the GPU, so nothing '
                    . 'can be asked of the model until somebody looks at it. Nothing of yours is lost or '
                    . 'changed. It is worth trying again in a few minutes in case it was momentary.'];
    }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        // At the head of the line and the slot is still held: whoever has it is
        // not in the line (the previous holder's release has not landed yet).
        return ['ok' => false, 'kind' => 'waiting'] + $where;
    }

    $what = 'say';
    xeric_queue_edit(function (array &$state, int $now) use ($ticket, &$what) {
        foreach ($state['line'] as $i => $t) {
            if ((string)($t['id'] ?? '') !== $ticket) continue;
            $what = (string)($t['what'] ?? 'say');
            $who  = (string)($t['who'] ?? '');
            unset($state['line'][$i]);
            $state['line'] = array_values($state['line']);
            $state['holder'] = ['id' => $ticket, 'at' => $now, 'what' => $what, 'who' => $who];
            return null;
        }
        $state['holder'] = ['id' => $ticket, 'at' => $now, 'what' => $what, 'who' => ''];
        return null;
    }, $now);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)($now ?? time()));
    fflush($fh);

    return ['ok' => true, 'ahead' => 0, 'eta' => 0, 'phrase' => 'the model is yours',
            'hold' => ['ticket' => $ticket, 'what' => $what, 'at' => $now ?? time(), 'handle' => $fh]];
}

/**
 * Wait for the slot, bounded, saying where you are while you do.
 *
 * $onWait is called whenever the position changes — once per change, never per
 * poll — which is what turns this into "you're 2nd in line" on a screen instead
 * of a progress bar that means nothing.
 *
 * @return array{ok:bool,hold?:array,waited:float,...}
 */
function xeric_queue_wait(string $ticket, float $seconds, ?callable $onWait = null): array
{
    $t0 = microtime(true);
    $deadline = $t0 + $seconds;
    $said = -1;

    while (true) {
        $r = xeric_queue_try($ticket);
        if ($r['ok'] || in_array((string)($r['kind'] ?? ''), ['drained', 'lost', 'broken'], true)) {
            $r['waited'] = round(microtime(true) - $t0, 1);
            return $r;
        }
        if ($onWait !== null && (int)$r['ahead'] !== $said) {
            $said = (int)$r['ahead'];
            $onWait((int)$r['ahead'], (int)$r['eta'], (string)$r['phrase']);
        }
        if (microtime(true) >= $deadline) {
            $r['waited'] = round(microtime(true) - $t0, 1);
            $r['kind'] = 'queued';
            $r['message'] = ucfirst($r['phrase']) . '. The demo runs on one GPU and takes one thing at a '
                . 'time, so nothing is broken, this is just the line.';
            $r['retry_after'] = max(5, min(120, (int)$r['eta']));
            return $r;
        }
        usleep(XERIC_QUEUE_POLL);
    }
}

/** Join, wait, and clean up the ticket if the wait was for nothing. */
function xeric_queue_take(string $what, float $seconds, string $who = '', ?callable $onWait = null): array
{
    $why = null;
    $ticket = xeric_queue_join($what, $who, $why);
    if ($ticket === '') return is_array($why) ? $why : xeric_queue_no('full', $what);
    $r = xeric_queue_wait($ticket, $seconds, $onWait);
    if (!($r['ok'] ?? false)) xeric_queue_leave($ticket);
    $r['ticket'] = $ticket;
    return $r;
}

/**
 * Hand the slot back, and remember what it cost.
 *
 * The rolling average is what makes the next visitor's estimate honest; it is
 * weighted towards recent holds because the same skip is a different length on
 * a warm model than a cold one.
 */
function xeric_queue_release(array $hold): void
{
    $took = max(1, time() - (int)($hold['at'] ?? time()));
    $what = (string)($hold['what'] ?? 'say');
    $ticket = (string)($hold['ticket'] ?? '');

    xeric_queue_edit(function (array &$state) use ($took, $what, $ticket) {
        $h = $state['holder'] ?? null;
        if (is_array($h) && (string)($h['id'] ?? '') === $ticket) $state['holder'] = null;
        $old = (int)($state['avg'][$what] ?? 0);
        $state['avg'][$what] = $old > 0 ? (int)round($old * 0.7 + $took * 0.3) : $took;
        return null;
    });

    if (isset($hold['handle']) && is_resource($hold['handle'])) {
        @flock($hold['handle'], LOCK_UN);
        @fclose($hold['handle']);
    }
}

/**
 * Must this hold stop now?
 *
 * The two ways the owner's machine wins: the flag file, checked live so a drain
 * lands mid-skip; and the hard cap, so a runaway job cannot own the GPU forever
 * even if nobody is watching. Long holders call this between steps.
 */
function xeric_queue_expired(array $hold, ?int $now = null): bool
{
    if (xeric_queue_drained()) return true;
    return ((int)($now ?? time()) - (int)($hold['at'] ?? 0)) > XERIC_QUEUE_HOLD_MAX;
}

/**
 * Why it stopped, in words the page can print.
 *
 * Worded from what the hold was FOR, because "the rest of those hours passed
 * quietly" is true of a skip and a lie about a build, and a stop reason that
 * promises the wrong thing is worse than no reason at all.
 */
function xeric_queue_stop_reason(array $hold, ?int $now = null): string
{
    $what = (string)($hold['what'] ?? '');
    $mid  = ['forge' => 'mid-build', 'tick' => 'mid-skip', 'reroll' => 'mid-reroll'][$what] ?? 'mid-turn';
    $tail = ['forge' => 'nothing half-built was written',
             'tick'  => 'the rest of those hours passed quietly',
             'reroll' => 'what was already on the page is untouched'][$what] ?? 'nothing was half-written';

    if (xeric_queue_drained()) {
        return 'the machine\'s owner took the GPU back ' . $mid . ', this demo runs on their own '
             . 'workstation and that comes first, so it stopped where it stood and ' . $tail;
    }
    return 'that is as long as the demo may hold the model in one go ('
         . (int)round(XERIC_QUEUE_HOLD_MAX / 60) . ' minutes), so it stopped where it stood and ' . $tail;
}

// ---------------------------------------------------------------------------
// For the screens
// ---------------------------------------------------------------------------

/** "you're 2nd in line — about 40 seconds", and the two truthful edges of that. */
function xeric_queue_phrase(int $ahead, int $eta): string
{
    if ($ahead <= 0) return 'the model is free';
    $when = xeric_queue_seconds($eta);
    if ($ahead === 1) return 'you are next in line, about ' . $when;

    $n = $ahead;
    $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
    return 'you are ' . $n . $suffix . ' in line, about ' . $when;
}

/** A duration a person can hold in their head. */
function xeric_queue_seconds(int $s): string
{
    if ($s < 15)  return 'ten seconds';
    if ($s < 100) return (string)(int)(round($s / 10) * 10) . ' seconds';
    return max(2, (int)round($s / 60)) . ' minutes';
}

/** What the model slot is doing, for the status line on any screen. */
function xeric_queue_status(?int $now = null): array
{
    $now = $now ?? time();
    $state = xeric_queue_state($now);
    $h = $state['holder'] ?? null;
    $busy = is_array($h);

    $eta = 0;
    if ($busy) $eta = xeric_queue_holder_eta($state, $h, $now);
    foreach ($state['line'] as $t) $eta += xeric_queue_avg($state, (string)($t['what'] ?? 'say'));

    return [
        'busy'    => $busy,
        'what'    => $busy ? (string)($h['what'] ?? '') : '',
        'for'     => $busy ? max(0, $now - (int)$h['at']) : 0,
        'depth'   => count($state['line']),
        'drained' => xeric_queue_drained(),
        // Said out loud rather than hidden: a queue with no line file is still
        // serialising the GPU, but it cannot promise anybody a position.
        'degraded' => xeric_queue_degraded(),
        'eta'     => (int)$eta,
        'phrase'  => xeric_queue_phrase(count($state['line']) + ($busy ? 1 : 0), (int)$eta),
    ];
}
