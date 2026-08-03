<?php
/**
 * Xeric — the shift, and how much money is allowed to matter.
 *
 * MONEY PRESSURE IS A DIAL, and it starts at nothing. Most worlds are not
 * about money: a xeric about a wake, a road trip, a house share, or a bum on
 * the street is one where a wage counter is noise at best and a nag at worst.
 * So this file does nothing at all unless a world turns it on, and a world
 * that turns it on can turn it back down mid-play from the cog — same as the
 * pace dial, and for the same reason, which is that the person playing is the
 * one who knows what they came for.
 *
 *     none   the engine has no opinion about your job. Nothing accrues,
 *            nothing is missed, no block reaches any prompt. The default.
 *     light  your shifts exist and the town notices when you did not go in.
 *            No money, no consequence, just the fact of it — which is what
 *            most worlds with a job in them actually want.
 *     real   the hours pay, the missed ones do not, and enough missed ones in
 *            a row cost you the job.
 *
 * YOU CAN ALWAYS SKIP YOUR SHIFT. That is not a loophole to be closed, it is
 * the point: the time control is how this engine moves and nothing may hold it
 * hostage. Sleep through a Tuesday if you want to. At `real` it costs you
 * money and eventually the job, and both of those are things a story can be
 * about. At `none` nobody mentions it.
 *
 * WHAT COUNTS AS MISSING ONE is a rule about the JUMP, not about where you
 * stood, because the person at the centre of a xeric has no location — they
 * talk to people, they do not occupy a room. A shift is missed when a single
 * press of the time control swallows MORE THAN HALF of it. Walking an eight
 * hour shift an hour at a time never misses it; you are there, living it.
 * Pressing "skip to evening" at seven in the morning does, and should.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';

/** The dial, weakest first. */
const XERIC_MONEY_DIALS = ['none', 'light', 'real'];

/** Missed shifts in a row that cost you the job, at `real` only. */
const XERIC_WORK_STRIKES = 3;

/** What a shift pays when the world does not say. */
const XERIC_WORK_PAY = 1;

/**
 * The longest press that still counts as BEING somewhere.
 *
 * The person at the centre of a xeric has no location — they talk to people,
 * they do not occupy a room — so presence at work cannot be looked up, only
 * inferred from how somebody moved the clock. Walking an hour at a time is
 * living those hours; a press that jumps half a day is not. Two hours is the
 * line, and it is generous on purpose: the cost of getting this wrong in the
 * lenient direction is a shift somebody skipped that nobody minded, and in the
 * strict direction it is being docked for a Tuesday you sat through.
 */
const XERIC_WORK_STEP = 2 * 3600;

// ---------------------------------------------------------------------------
// The dial
// ---------------------------------------------------------------------------

/**
 * Where a world starts, before anybody touches the cog.
 *
 * A world that armed no economies and gave the person at the centre no shifts
 * is not a world about money, and starting it anywhere but `none` would be the
 * engine deciding what somebody's story is about. A world that did both gets
 * `light` — the town notices, nothing costs — because `real` is a choice
 * somebody should make on purpose rather than inherit from a forge answer.
 */
function xeric_money_default(array $t): string
{
    $armed = (array)($t['forge']['armed'] ?? []);
    $eco   = in_array('economies', $armed, true) || (array)($t['economies'] ?? []) !== [];
    return ($eco && xeric_shifts($t) !== []) ? 'light' : 'none';
}

/** The dial as it stands, world override first. */
function xeric_money_dial(PDO $db, array $t): string
{
    $set = xeric_world_state_get($db, 'money_pressure');
    $set = $set === null ? '' : trim((string)$set);
    return in_array($set, XERIC_MONEY_DIALS, true) ? $set : xeric_money_default($t);
}

/** Turn it up or down, mid-play. Returns where it landed. */
function xeric_money_set(PDO $db, string $dial, ?int $at = null): string
{
    $dial = in_array($dial, XERIC_MONEY_DIALS, true) ? $dial : 'none';
    xeric_world_state_set($db, 'money_pressure', $dial, $at ?? xeric_state_time());
    return $dial;
}

// ---------------------------------------------------------------------------
// The shifts themselves
// ---------------------------------------------------------------------------

/** Day names the schema accepts, Monday first because rosters are. */
function xeric_work_days(): array
{
    return ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
}

/** "08:30" → minutes past midnight, or null. */
function xeric_work_minutes(string $hhmm): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) return null;
    $h = (int)$m[1]; $i = (int)$m[2];
    if ($h > 23 || $i > 59) return null;
    return $h * 60 + $i;
}

/**
 * The person at the centre's shifts, normalised, or [] for anybody without a
 * roster — which is most people, including everybody whose occupation is
 * prose ("retired line foreman, hours: none, and he has not gotten used to
 * it"). Prose stays prose; this reads only the structured field.
 */
function xeric_shifts(array $t): array
{
    $out = [];
    $days = xeric_work_days();
    foreach ((array)($t['user']['occupation']['shifts'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $from = xeric_work_minutes((string)($s['from'] ?? ''));
        $to   = xeric_work_minutes((string)($s['to'] ?? ''));
        if ($from === null || $to === null) continue;
        $on = [];
        foreach ((array)($s['days'] ?? []) as $d) {
            $d = strtolower(substr(trim((string)$d), 0, 3));
            if (isset($days[$d])) $on[$days[$d]] = true;
        }
        if ($on === []) continue;
        $out[] = ['days' => array_keys($on), 'from' => $from, 'to' => $to,
                  'pay' => (int)($s['pay'] ?? XERIC_WORK_PAY),
                  'label' => trim((string)($s['label'] ?? '')) ?: 'your shift'];
    }
    return $out;
}

/**
 * Every shift whose hours fall inside [$from, $to), as absolute epochs.
 *
 * A shift that ends before it starts is an overnight one and runs to the next
 * morning, which is the only way a night porter's roster can be written down.
 * Walks day by day over the span, so a week-long jump resolves every shift in
 * it rather than only the first.
 */
function xeric_shift_spans(array $t, int $from, int $to): array
{
    $shifts = xeric_shifts($t);
    if ($shifts === [] || $to <= $from) return [];

    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }

    $out  = [];
    $day  = (new DateTimeImmutable('@' . $from))->setTimezone($tz)->modify('-1 day')->setTime(0, 0);
    $stop = (new DateTimeImmutable('@' . $to))->setTimezone($tz)->modify('+1 day')->setTime(0, 0);

    while ($day->getTimestamp() <= $stop->getTimestamp()) {
        $dow = (int)$day->format('N');
        foreach ($shifts as $s) {
            if (!in_array($dow, $s['days'], true)) continue;
            $starts = $day->getTimestamp() + $s['from'] * 60;
            $ends   = $day->getTimestamp() + $s['to'] * 60;
            if ($ends <= $starts) $ends += 86400;               // an overnight
            if ($ends <= $from || $starts >= $to) continue;
            $out[] = ['starts' => $starts, 'ends' => $ends, 'pay' => $s['pay'],
                      'label' => $s['label']];
        }
        $day = $day->modify('+1 day');
    }
    usort($out, fn($a, $b) => $a['starts'] <=> $b['starts']);
    return $out;
}

/** The shift standing right now, or null. */
function xeric_shift_now(array $t, array $now): ?array
{
    $e = (int)($now['epoch'] ?? 0);
    foreach (xeric_shift_spans($t, $e - 1, $e + 1) as $s) {
        if ($s['starts'] <= $e && $e < $s['ends']) return $s;
    }
    return null;
}

/** The next one due, within a week, or null. */
function xeric_shift_next(array $t, array $now): ?array
{
    $e = (int)($now['epoch'] ?? 0);
    foreach (xeric_shift_spans($t, $e, $e + 7 * 86400) as $s) {
        if ($s['starts'] > $e) return $s;
    }
    return null;
}

// ---------------------------------------------------------------------------
// What a jump did to the job
// ---------------------------------------------------------------------------

/** Wages, strikes, and whether there is still a job to go to. */
function xeric_work_state(PDO $db): array
{
    return [
        'wages'  => (int)(xeric_world_state_get($db, 'work.wages')  ?? 0),
        'missed' => (int)(xeric_world_state_get($db, 'work.missed') ?? 0),
        'fired'  => (int)(xeric_world_state_get($db, 'work.fired')  ?? 0) === 1,
    ];
}

/**
 * One press of the time control, read against the roster.
 *
 * MORE THAN HALF IS MISSED, and the rest is worked. That single rule is what
 * makes the +1 hour button a way of BEING at work and the skip button a way of
 * not being — without anybody having to track where the person at the centre
 * is standing, which this engine deliberately does not do.
 *
 * Never blocks anything and never throws. The skip has already happened by the
 * time this is called; this only decides what it cost.
 *
 * @return array{missed:int,worked:int,paid:int,fired:bool,lines:array<int,string>}
 */
function xeric_shift_walk(PDO $db, array $t, int $from, int $to, ?int $at = null): array
{
    $out = ['missed' => 0, 'worked' => 0, 'paid' => 0, 'fired' => false, 'lines' => []];
    $dial = xeric_money_dial($db, $t);
    if ($dial === 'none') return $out;

    $state = xeric_work_state($db);
    if ($state['fired']) return $out;                  // no shifts to miss

    $spans = xeric_shift_spans($t, $from, $to);
    if ($spans === []) return $out;

    $at    = $at ?? xeric_state_time();
    $wages = $state['wages'];
    $run   = $state['missed'];

    // A SHIFT SETTLES ONCE, WHEN THE CLOCK PASSES ITS END, and what it settles
    // as depends on how much of it went by in presses too big to have been
    // there for. Eight +1 hour presses and one nine-hour skip cover the same
    // stretch of clock; only one of them is a day at work, and the accumulator
    // is what tells them apart. It is carried in world_state between presses
    // because a shift can be walked across a dozen of them.
    $accFor  = (int)(xeric_world_state_get($db, 'work.shift') ?? 0);
    $accSecs = (int)(xeric_world_state_get($db, 'work.away')  ?? 0);
    $touched = false;

    foreach ($spans as $s) {
        $len  = max(1, $s['ends'] - $s['starts']);
        $over = max(0, min($to, $s['ends']) - max($from, $s['starts']));
        if ($over <= 0) continue;

        if ($accFor !== $s['starts']) { $accFor = $s['starts']; $accSecs = 0; }
        // Only a jump too big to have been present for counts as away.
        if (($to - $from) > XERIC_WORK_STEP) $accSecs += $over;
        $touched = true;

        if ($to < $s['ends']) continue;                 // still in it; settle later

        if ($accSecs * 2 > $len) {
            $out['missed']++;
            $run++;
            $out['lines'][] = 'You did not go in.';
            // AND ONLY `real` KEEPS SCORE. At `light` the town notices and
            // that is the whole of it, which is what a world with a job in it
            // usually wants — the job is texture, not a resource system.
            if ($dial === 'real' && $run >= XERIC_WORK_STRIKES) {
                $out['fired'] = true;
                $out['lines'][] = 'That was the last one they were going to overlook.';
                $accFor = 0; $accSecs = 0;
                break;
            }
        } else {
            $out['worked']++;
            $run = 0;
            if ($dial === 'real') { $wages += $s['pay']; $out['paid'] += $s['pay']; }
        }
        $accFor = 0; $accSecs = 0;
    }

    if ($touched) {
        xeric_world_state_set($db, 'work.shift', (string)$accFor, $at);
        xeric_world_state_set($db, 'work.away',  (string)$accSecs, $at);
    }

    if ($dial === 'real') {
        if ($wages !== $state['wages']) xeric_world_state_set($db, 'work.wages', (string)$wages, $at);
        if ($out['fired']) {
            xeric_world_state_set($db, 'work.fired', '1', $at);
            $run = 0;
        }
    }
    if ($run !== $state['missed']) xeric_world_state_set($db, 'work.missed', (string)$run, $at);
    return $out;
}

/**
 * What the town knows about your job, for a character's own prompt.
 *
 * Coarse and clockless like every other block that reaches a prompt: no wage
 * total, no strike count, no next-shift countdown — those would rewrite the
 * static half of a system message on a schedule and drag it out of cache. What
 * survives is the only part anybody in a small town would actually have
 * noticed, which is whether you have been turning up.
 */
function xeric_work_block(PDO $db, array $t): string
{
    $dial = xeric_money_dial($db, $t);
    if ($dial === 'none' || xeric_shifts($t) === []) return '';
    $s = xeric_work_state($db);
    $who = trim((string)($t['user']['name'] ?? '')) ?: 'they';
    $job = trim((string)($t['user']['occupation']['title'] ?? '')) ?: 'the job';

    if ($s['fired']) return "THE JOB\n- $who does not have $job any more. Everybody knows why.";
    if ($s['missed'] >= 2) return "THE JOB\n- $who has missed more than one shift lately. It has been remarked on.";
    if ($s['missed'] === 1) return "THE JOB\n- $who missed a shift. Somebody noticed, nobody made anything of it.";
    return '';
}
