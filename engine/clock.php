<?php
/**
 * Xeric — the world clock, as state.
 *
 * world.php owns the SHAPE of a moment (xeric_world_now: phase, dow, hh:mm in the
 * template's timezone) and reads the wall clock exactly once, in a default
 * argument. This file owns WHICH moment the world is standing in: real time plus
 * a stored offset, so "skip to evening" moves the whole world at once instead of
 * dressing one screen up in an evening it does not believe in.
 *
 * Three rules, and they are the reason this is a file and not two lines inline:
 *
 *  1. THE WORLD DOES NOT RUN BACKWARDS. Memories, messages and events are stamped
 *     with the world epoch at which they were written. Rewinding the offset would
 *     leave a character remembering a Tuesday that has not happened yet, and no
 *     amount of prose can talk a model out of a date. A rewind is therefore an
 *     exception, never a shrug — there are exactly two ways back, and both pay
 *     the debt the stamps create. xeric_clock_reset() is the blunt one: "this
 *     world's fast-forward was a mistake", back to its own start. xeric_rewind()
 *     (engine/rewind.php) is the surgical one: the last skip's clock movement
 *     comes back OFF, but only together with every memory, message and event
 *     that was stamped inside it — the manifest is what makes moving the offset
 *     backwards not leave anybody remembering an unhappened Tuesday, because
 *     the remembering goes with the Tuesday.
 *
 *  2. ONE JUMP IS BOUNDED. A demo button that reads "6h" from a query string is
 *     one typo away from "6000h", and a world that skipped a year would have a
 *     cast whose entire memory is ancient and whose schedules never fired. The
 *     cap is a fat-finger fence, not a policy about how long a world may run:
 *     advance seven days seven times and the world is a week and a week older.
 *
 *  3. THE OFFSET IS THE WORLD'S, NOT THE SESSION'S. It lives in world_state next
 *     to the sweep guards, so a cron sweep, a web request and a CLI run all agree
 *     about what time it is without passing anything to each other.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';

/**
 * The most one call may move the world. Seven days is long enough for every
 * demo gesture ("skip to evening", "skip a weekend") and short enough that a
 * mistyped span fails loudly instead of quietly ageing a cast out of its own life.
 */
const XERIC_CLOCK_MAX_JUMP = 7 * 86400;

/**
 * What time it is in this world.
 *
 * @param array    $t       the world template — the timezone comes from it
 * @param int|null $realNow inject the wall clock in tests; production passes null
 * @return array same shape as xeric_world_now(): epoch, iso, dow, hhmm, phase, tz
 */
function xeric_clock_now(PDO $db, array $t, ?int $realNow = null): array
{
    return xeric_world_now($t, xeric_clock_epoch($db, $realNow));
}

/**
 * Move the world forward and say where it landed.
 *
 * @param int        $seconds  forward only; 0 is a legal no-op, negative is a bug
 * @param array|null $t        the template, so the returned moment is in the
 *                             world's timezone. Omitted (the demo's time control
 *                             does not always have one to hand) the moment comes
 *                             back in UTC — the epoch is right either way, which
 *                             is the part callers store.
 * @return array the new now
 * @throws RuntimeException on a rewind or on a jump past XERIC_CLOCK_MAX_JUMP.
 *         Nothing is written in either case: the world stays where it was.
 */
function xeric_clock_advance(PDO $db, int $seconds, ?array $t = null, ?int $realNow = null): array
{
    if ($seconds < 0) {
        throw new RuntimeException('clock: a xeric does not run backwards ('
            . xeric_clock_span_label($seconds) . '), use xeric_clock_reset() to undo a fast-forward');
    }
    if ($seconds > XERIC_CLOCK_MAX_JUMP) {
        throw new RuntimeException('clock: ' . xeric_clock_span_label($seconds)
            . ' is more than one jump may move a world (max ' . xeric_clock_span_label(XERIC_CLOCK_MAX_JUMP) . ')');
    }
    // A stopped world cannot be fast-forwarded. Not because the arithmetic would
    // fail — it would work fine — but because the result is a world that skipped
    // six hours it was not running for, which is the lie pausing exists to stop
    // telling. Refused rather than silently resumed: the caller decides.
    if ($seconds > 0 && xeric_clock_is_paused($db)) {
        throw new RuntimeException('clock: this xeric is stopped, start it again before moving it');
    }

    xeric_clock_offset_set($db, xeric_clock_offset($db) + $seconds);
    return xeric_clock_now($db, $t ?? [], $realNow);
}

// ---------------------------------------------------------------------------
// Stopping it
// ---------------------------------------------------------------------------
//
// A world runs on wall-clock time whether or not anybody is here. That is the
// product — and it is also the thing that makes going away for a fortnight cost
// you a fortnight you never saw. Pausing is the answer, and it has exactly one
// hard requirement: coming back must land on the SAME SECOND you left, not near
// it. Anything else and the feature is a rounding error with a button.
//
// The arithmetic is one line and it is worth stating. World time is
// `real_now + offset`. Stop at real time P and the world reads P + offset
// forever (xeric_clock_epoch freezes on `clock_paused_at`). Start again at real
// time R and the world must still read P + offset, so:
//
//     P + offset  =  R + offset'      →      offset' = offset − (R − P)
//
// The offset goes NEGATIVE, and that is correct rather than a bug to guard
// against: a negative offset is a world running behind real time, which is
// precisely what a world that has been asleep is.

/**
 * Is this world stopped? The one question every caller should ask, rather than
 * reading the stored moment and comparing it to something.
 */
function xeric_clock_is_paused(PDO $db): bool
{
    return xeric_clock_paused_at($db) > 0;
}

/**
 * Stop the world where it stands.
 *
 * Idempotent, and deliberately so: pausing an already-paused world must NOT
 * re-stamp the moment, because the stamp is what the resume subtracts and
 * re-stamping it would silently hand back the hours the world was away.
 *
 * @return bool true if this call is what stopped it
 */
function xeric_clock_pause(PDO $db, ?int $realNow = null): bool
{
    if (xeric_clock_is_paused($db)) return false;
    xeric_world_state_set($db, 'clock_paused_at', (string)($realNow ?? xeric_state_time()));
    return true;
}

/**
 * Start it again, on the second it stopped.
 *
 * The offset moves first and the stamp is cleared second. Between those two
 * statements the world is still frozen — so a crash in the gap leaves a paused
 * world with a corrected offset, and the next resume finds nothing to correct
 * and simply unfreezes. The other order would leave a world running with an
 * uncorrected offset, which is the fortnight this exists to prevent.
 *
 * @return int seconds the world was away — 0 if it was never stopped
 */
function xeric_clock_resume(PDO $db, ?int $realNow = null): int
{
    $stopped = xeric_clock_paused_at($db);
    if ($stopped <= 0) return 0;

    $away = max(0, ($realNow ?? xeric_state_time()) - $stopped);
    xeric_clock_offset_set($db, xeric_clock_offset($db) - $away);
    xeric_world_state_set($db, 'clock_paused_at', '0');
    return $away;
}

// ---------------------------------------------------------------------------
// When it starts
// ---------------------------------------------------------------------------
//
// A world does not have to be set now. World time is real time plus an offset,
// and the offset is a signed integer of seconds — so "it is the 8th of November
// 1973 and time is going" is one number, and everything downstream was already
// written against real datetimes rather than an hour counter: day names,
// schedules, place hours, the phase of the evening, pause, resume.
//
// It comes out more period-accurate than anybody asked for. A world set in 1873
// reports its timezone as −04:56, because United States standard time did not
// arrive until 1883 and the tz database knows. Nothing here did that on purpose.

/** The moment a template says it begins, or null for "now". */
function xeric_clock_starts(array $t): ?int
{
    $raw = trim((string)($t['setting']['starts'] ?? ''));
    if ($raw === '') return null;

    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }

    try { $d = new DateTimeImmutable($raw, $tz); }
    catch (Throwable $e) { return null; }        // unreadable is "now", never an error

    return $d->getTimestamp();
}

/**
 * Put a world at its declared start, ONCE.
 *
 * Applied like the seed and marked like the seed: a world that has begun has
 * begun, and re-applying it on the next page load would drag it back to its
 * first morning every time somebody looked at it — the offset is where all the
 * time that has passed since is being kept.
 *
 * @return bool true if this call is what started it
 */
function xeric_clock_begin(PDO $db, array $t, ?int $realNow = null): bool
{
    if (xeric_world_state_get($db, 'clock_began') !== null) return false;

    $starts = xeric_clock_starts($t);
    // Marked either way. A world with no start date has begun too — at the
    // moment somebody opened it — and leaving the mark unwritten would make
    // every later load ask the same question again.
    xeric_world_state_set($db, 'clock_began', (string)($starts ?? 0));
    if ($starts === null) return false;

    xeric_clock_offset_set($db, $starts - ($realNow ?? xeric_state_time()));
    return true;
}

/**
 * Put the world back on real time.
 *
 * Deliberately not "rewind by N": the guards in world_state, the sweep windows
 * and every world_epoch already written are all keyed to a monotonic clock, and
 * an arbitrary rewind would land the world in the middle of hours it has already
 * lived. Back to zero is the one rewind whose meaning is unambiguous.
 */
function xeric_clock_reset(PDO $db, ?array $t = null, ?int $realNow = null): void
{
    // BACK TO ITS OWN START, not to today. Offset 0 means "the world is now",
    // which is right for a world that is, and destructive for one that is not:
    // a reset would have hauled a town out of November 1973 into this afternoon
    // and called it undoing a fast-forward.
    $starts = $t !== null ? xeric_clock_starts($t) : null;
    xeric_clock_offset_set($db, $starts === null ? 0 : $starts - ($realNow ?? xeric_state_time()));
}

/**
 * "6h" | "90m" | "2d" | "45s" | "3600" → seconds. null when it is not a span.
 *
 * The demo's time control and the CLI both take a span from somebody's fingers,
 * and both need the same answer for "6h" — including the answer "that is not a
 * span", which must not silently become 0 and a world that did not move.
 */
function xeric_clock_span(string $s): ?int
{
    $s = strtolower(trim($s));
    if ($s === '') return null;
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([smhdw]?)$/', $s, $m)) return null;

    $n = (float)$m[1];
    $mult = match ($m[2]) {
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
        'w' => 604800,
        default => 1,          // a bare number is seconds, like every other unix tool
    };
    return (int)round($n * $mult);
}

/** Seconds → "6h" / "1d 4h" / "20m". For error messages and CLI output. */
function xeric_clock_span_label(int $seconds): string
{
    $sign = $seconds < 0 ? '-' : '';
    $s    = abs($seconds);
    if ($s === 0) return '0s';

    $parts = [];
    foreach (['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
        if ($s < $size) continue;
        $parts[] = intdiv($s, $size) . $unit;
        $s %= $size;
        if (count($parts) === 2) break;      // "1d 4h", never "1d 4h 3m 9s"
    }
    return $sign . implode(' ', $parts);
}
