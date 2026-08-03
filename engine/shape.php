<?php
/**
 * Xeric — shapes. The arithmetic of a rhythm, under both the world and its stories.
 *
 * WHY THIS IS ITS OWN FILE. sweeps.php is the lower layer and says so out loud:
 * a world with no overlay must not pay for the overlay engine. But a world CAN
 * have a rhythm of its own without any story on it at all, and that rhythm is
 * the same curve arithmetic a story's plot snake runs on. So the curve lives
 * here, where both can reach it, and story.php keeps the part that is genuinely
 * about overlays — beats, walls, victims, resolution, composition.
 *
 * The split is the dependency drawn the right way round: shapes know nothing
 * about stories, stories are shapes plus a plot, and sweeps pays only for the
 * arithmetic. Nothing in here reads the wall clock, opens a file, or writes.
 *
 * THE NAMES DID NOT CHANGE when these moved out of story.php. They are still
 * xeric_story_* because they are still the story engine's vocabulary from the
 * outside, and renaming a tested function to record a refactor is a diff that
 * costs every caller and buys nothing.
 *
 * PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/sweeps.php';   // the kind names a thumb may push on

/** The only overlay schema this engine reads. */
const XERIC_STORY_VERSION = 1;

/** The stages the curve produces. Derived from the snake, never declared beside it. */
function xeric_story_stages(): array
{
    return ['opening', 'rising', 'taper', 'false_calm', 'crescendo', 'closing'];
}

// ---------------------------------------------------------------------------
// Shapes — the library a world picks its rhythm from
// ---------------------------------------------------------------------------

/**
 * The named shapes a world can run on, and the one it can refuse.
 *
 * THE PLOT SNAKE IS A SHAPE, NOT THE SHAPE. It is a good one — an English
 * professor's curve, and the false calm in the middle of it is the part every
 * generative system gets wrong — but it is one dramatic tradition, and a world
 * that wants to be a place rather than a plot should not have to wear it.
 *
 * THROWING IT AWAY COSTS NOTHING, and that is not a convenience, it is the
 * arithmetic. The modulation is m = 1 + swing·(2i − 1), so intensity 0.5 is
 * ×1.0 exactly, whatever the swing. A curve held flat at 0.5 for its whole
 * length is therefore a world running at precisely its own declared rate,
 * forever — which is what "no plot" means — and `xeric_story_snake()` reads it
 * as one endless `false_calm`, which is the true name for it: the town carrying
 * on the way it would have if nobody had died. There is no bypass in this file
 * and no `if (shape === none)` anywhere downstream. NONE IS A SHAPE.
 *
 * EVERY SHAPE HERE IS A LEGAL OVERLAY SNAKE. They are validated by exactly the
 * rules in xeric_story_validate() — four points, strictly increasing progress,
 * a false calm that is genuinely flat at 0.5 across its declared window — so a
 * shape can be dropped onto a story overlay without a second schema, and the
 * one the model invents is checked against the same rules rather than trusted.
 *
 * `cycle_days` is how long the shape takes to run once when it is pacing a
 * WORLD rather than a story. A story's progress is its beats; a world has no
 * beats, so it walks the curve on its own calendar and begins again. A world
 * that ran its shape once and stopped would be a world that is over.
 */
function xeric_story_shapes(): array
{
    return [
        // The refusal, and it is first because it is the honest default for a
        // world that was forged as a place to live rather than a story to
        // finish. Flat at 0.5 end to end: ×1.0 forever, no stage thumb, nothing
        // building toward anything. The false calm spans the whole life of it.
        'none' => [
            'label' => 'nothing is building',
            'hint'  => 'no arc at all — the place just runs, at its own pace, indefinitely',
            'curve' => [[0.00, 0.50], [0.33, 0.50], [0.67, 0.50], [1.00, 0.50]],
            'false_calm' => [0.00, 1.00],
            'pace_swing' => 0.0,
            'kind_thumb' => [],
            'cycle_days' => 0,
        ],

        // The professor's curve, exactly as docs/STORY.md draws it.
        'snake' => [
            'label' => 'the plot snake',
            'hint'  => 'a steep rise, a build, a long false calm at halfway, then a crescendo',
            'curve' => [[0.00, 0.00], [0.08, 0.55], [0.35, 0.80], [0.50, 0.50],
                        [0.72, 0.50], [0.92, 1.00], [1.00, 0.15]],
            'false_calm' => [0.50, 0.72],
            'pace_swing' => 0.60,
            'kind_thumb' => [
                'opening'    => ['routine' => 1.4, 'visit' => 1.3, 'recognition' => 1.3],
                'rising'     => ['rumor' => 1.4, 'confidence' => 1.3, 'glimpse' => 1.3, 'friction' => 1.2],
                'false_calm' => ['ease' => 1.5, 'shared_meal' => 1.4, 'routine' => 1.3,
                                 'rumor' => 0.6, 'confidence' => 0.6, 'glimpse' => 0.6],
                'crescendo'  => ['confidence' => 1.5, 'mishap' => 1.4, 'friction' => 1.4, 'chase' => 1.3],
                'closing'    => ['recognition' => 1.5, 'ease' => 1.3],
            ],
            'cycle_days' => 28,
        ],

        // Nothing, for a long time, and then it will not stop. The long middle
        // sits AT the world's own rate rather than below it: a slow burn is not
        // a quiet world, it is an ordinary one with something coming.
        'slow_burn' => [
            'label' => 'a slow burn',
            'hint'  => 'ordinary for a long time, then it turns and does not let up',
            'curve' => [[0.00, 0.10], [0.25, 0.30], [0.45, 0.50], [0.70, 0.50],
                        [0.90, 0.95], [1.00, 0.35]],
            'false_calm' => [0.45, 0.70],
            'pace_swing' => 0.55,
            'kind_thumb' => [
                'opening'    => ['routine' => 1.5, 'ordinary' => 1.4, 'craft' => 1.2],
                'rising'     => ['glimpse' => 1.3, 'absence' => 1.3, 'rumor' => 1.2],
                'false_calm' => ['routine' => 1.3, 'shared_meal' => 1.2],
                'crescendo'  => ['confidence' => 1.5, 'friction' => 1.4, 'loss' => 1.3],
                'closing'    => ['recognition' => 1.4, 'ease' => 1.2],
            ],
            'cycle_days' => 60,
        ],

        // A small arc, then another, and nothing accumulates. Two bumps and a
        // flat between them: the week has a shape, the year does not.
        'episodic' => [
            'label' => 'episodic',
            'hint'  => 'a small arc every week or so, and nothing carries over',
            'curve' => [[0.00, 0.50], [0.12, 0.85], [0.25, 0.50], [0.45, 0.50],
                        [0.57, 0.85], [0.70, 0.50], [0.88, 0.90], [1.00, 0.50]],
            'false_calm' => [0.25, 0.45],
            'pace_swing' => 0.45,
            'kind_thumb' => [
                'opening'    => ['visit' => 1.3, 'routine' => 1.2],
                'false_calm' => ['ease' => 1.3, 'routine' => 1.2],
                'crescendo'  => ['mishap' => 1.3, 'friction' => 1.3, 'favor' => 1.2],
                'closing'    => ['ease' => 1.3, 'recognition' => 1.2],
            ],
            'cycle_days' => 7,
        ],

        // Seasons. It rises and falls and never shouts — the loudest hour of a
        // tidal world is somebody's difficult afternoon, not a crescendo.
        'tidal' => [
            'label' => 'tidal',
            'hint'  => 'seasons — it swells and settles on its own, and never peaks hard',
            'curve' => [[0.00, 0.35], [0.20, 0.50], [0.45, 0.50], [0.62, 0.72],
                        [0.85, 0.45], [1.00, 0.35]],
            'false_calm' => [0.20, 0.45],
            'pace_swing' => 0.35,
            'kind_thumb' => [
                'false_calm' => ['routine' => 1.3, 'ritual' => 1.2],
                'crescendo'  => ['friction' => 1.2, 'shared_meal' => 1.2, 'absence' => 1.2],
                'closing'    => ['ease' => 1.3, 'ritual' => 1.2],
            ],
            'cycle_days' => 90,
        ],

        // Kishōtenketsu: introduction, development, TWIST, reconciliation — a
        // four-act shape with no conflict driving it. The turn near the end is
        // not a confrontation, it is a thing you did not know, and everything
        // before it re-reads. Hence the peak with no build under it.
        'turn' => [
            'label' => 'a turn, not a fight',
            'hint'  => 'kishōtenketsu — it builds nothing, then something you did not know arrives',
            'curve' => [[0.00, 0.30], [0.25, 0.45], [0.42, 0.50], [0.62, 0.50],
                        [0.78, 0.95], [1.00, 0.40]],
            'false_calm' => [0.42, 0.62],
            'pace_swing' => 0.50,
            'kind_thumb' => [
                'opening'    => ['routine' => 1.3, 'craft' => 1.3, 'ordinary' => 1.2],
                'rising'     => ['visit' => 1.2, 'shared_meal' => 1.2],
                'false_calm' => ['routine' => 1.3, 'ease' => 1.2],
                'crescendo'  => ['recognition' => 1.6, 'glimpse' => 1.4, 'confidence' => 1.3],
                'closing'    => ['ease' => 1.4, 'shared_meal' => 1.2],
            ],
            'cycle_days' => 21,
        ],
    ];
}

/** Is this world running no shape at all? Unreadable and unknown both say yes. */
function xeric_story_shapeless(array $t): bool
{
    return xeric_story_shape_key($t) === 'none';
}

/**
 * Which shape this world declared. Anything unreadable lands on `none`.
 *
 * Not fail-closed in the safety sense — a shape governs pacing and pacing is
 * not a safety property (the file header says so about progress generally).
 * It is fail-QUIET, which is the right default for a knob about drama: a world
 * whose shape nobody can read runs at the rate it declared for itself, which is
 * the behaviour every world had before shapes existed.
 */
function xeric_story_shape_key(array $t): string
{
    $v = $t['events']['story_shape'] ?? null;
    if (is_array($v)) return trim((string)($v['key'] ?? '')) !== '' ? (string)$v['key'] : 'custom';
    $k = trim((string)($v ?? ''));
    if ($k === '') return 'none';
    return isset(xeric_story_shapes()[$k]) ? $k : 'none';
}

/**
 * The world's shape as a snake, ready to hand to xeric_story_snake().
 *
 * A world may name one from the library or carry its own — a curve a model
 * invented at forge time, stored inline. An inline shape that does not validate
 * is not repaired and not half-used: it is dropped for `none`, because a
 * half-read curve paces a world by accident.
 */
function xeric_story_shape(array $t): array
{
    $lib = xeric_story_shapes();
    $v   = $t['events']['story_shape'] ?? null;

    if (is_array($v)) {
        // Model proposes, code disposes. This is the disposal.
        return xeric_story_shape_check($v) === [] ? $v : $lib['none'];
    }
    $k = trim((string)($v ?? ''));
    return $lib[$k] ?? $lib['none'];
}

/**
 * Everything wrong with a shape, in the words xeric_story_validate() would use.
 *
 * THE SAME RULES, DELIBERATELY. A shape is a snake with a label on it, so the
 * checks here are the snake half of xeric_story_validate() applied to a bare
 * array — which is what lets a forge-time curve, a library entry and an overlay
 * all be judged by one standard. A shape that passes here can be dropped onto
 * an overlay and will not fail validation there.
 *
 * @return string[] empty when the shape is sound
 */
function xeric_story_shape_check(array $shape): array
{
    $bad   = [];
    $curve = (array)($shape['curve'] ?? []);
    if (count($curve) < 4) $bad[] = 'curve needs at least four control points';

    $prev = -1.0;
    foreach ($curve as $i => $pt) {
        if (!is_array($pt) || count($pt) !== 2) { $bad[] = "curve[$i] must be [progress, intensity]"; continue; }
        if ((float)$pt[0] <= $prev) $bad[] = "curve[$i] progress must strictly increase";
        if ((float)$pt[1] < 0.0 || (float)$pt[1] > 1.0) $bad[] = "curve[$i] intensity must be 0..1";
        $prev = (float)$pt[0];
    }
    if ($bad !== []) return $bad;                     // no point measuring a curve that is not one

    if ((float)$curve[0][0] !== 0.0 || (float)$curve[count($curve) - 1][0] !== 1.0) {
        $bad[] = 'curve must span progress 0..1';
    }

    $fc = array_map('floatval', (array)($shape['false_calm'] ?? []));
    if (count($fc) !== 2 || $fc[0] >= $fc[1] || $fc[0] < 0.0 || $fc[1] > 1.0) {
        $bad[] = 'false_calm must be an ordered window inside 0..1';
    } else {
        // The window and the flat are the same two numbers on purpose, so that
        // the ×1.0 claim stays arithmetic rather than aspirational.
        if (xeric_story_intensity($curve, $fc[0]) !== 0.5) $bad[] = 'false_calm does not start at intensity 0.5';
        if (xeric_story_intensity($curve, $fc[1]) !== 0.5) $bad[] = 'the curve is not flat at 0.5 across the false calm';
        foreach ($curve as $i => $pt) {
            if ((float)$pt[0] > $fc[0] && (float)$pt[0] < $fc[1] && (float)$pt[1] !== 0.5) {
                $bad[] = "curve[$i] sits inside the false calm at an intensity other than 0.5";
            }
        }
    }

    $swing = (float)($shape['pace_swing'] ?? 0.6);
    if ($swing < 0.0 || $swing > 0.9) $bad[] = 'pace_swing must be 0..0.9';

    $kinds = array_keys(xeric_sweep_kinds());
    foreach ((array)($shape['kind_thumb'] ?? []) as $stage => $thumb) {
        if (!in_array((string)$stage, xeric_story_stages(), true)) {
            $bad[] = "kind_thumb.$stage is not a stage the curve produces";
            continue;
        }
        foreach ((array)$thumb as $k => $m) {
            if (!in_array((string)$k, $kinds, true)) $bad[] = "kind_thumb.$stage.$k is not a kind the engine knows";
            // A thumb is not a gate: it re-weights what is armed and may never
            // arm or delete. Zero and below would delete.
            if ((float)$m <= 0.0) $bad[] = "kind_thumb.$stage.$k must be a positive multiplier";
        }
    }

    $cyc = (int)($shape['cycle_days'] ?? 0);
    if ($cyc < 0 || $cyc > 3650) $bad[] = 'cycle_days must be 0..3650';

    return $bad;
}

/**
 * Where a world is on its own shape, and what that does to its pace.
 *
 * A STORY'S PROGRESS IS ITS BEATS; A WORLD HAS NONE. So the world walks its
 * curve on the calendar instead — days lived since it was seeded, wrapped by
 * `cycle_days` — and when it reaches the end it begins again. That loop is the
 * point rather than a compromise: a world is not over when its rhythm finishes,
 * and a shape that ran once and flattened would be a world that had.
 *
 * TIME COMES FROM THE CALLER, the same discipline the rest of this file keeps.
 * Nothing here reads the wall clock; the world epoch is passed in.
 *
 * AND IT NEVER REACHES A PROMPT. prompt.php:271 is explicit that no progress, no
 * intensity and no beat count is composed into a system message, which is what
 * keeps the prefix cache intact — this is a thumb on which hours happen and what
 * kind they are, decided in code, and a character is never told the weather of
 * the plot they are in.
 *
 * @return array{} when the world runs no shape, else {p, intensity, stage, m}
 */
function xeric_story_ambient(array $t, PDO $db, ?int $epoch = null): array
{
    $shape = xeric_story_shape($t);
    $cycle = (int)($shape['cycle_days'] ?? 0);
    if ($cycle <= 0) return [];                        // `none`, and anything shapeless

    $seeded = (int)(xeric_world_state_get($db, 'seeded_at') ?? 0);
    if ($seeded <= 0 || $epoch === null || $epoch <= $seeded) {
        // Day one is the front of the curve, which is where a shape starts.
        $p = 0.0;
    } else {
        $days = ($epoch - $seeded) / 86400.0;
        $p    = fmod($days, (float)$cycle) / (float)$cycle;
    }

    $read = xeric_story_snake($shape, $p);
    return ['p' => $p, 'intensity' => $read['intensity'], 'stage' => $read['stage'], 'm' => $read['m']];
}

/**
 * The world's ambient rate, its shape applied. The world's own number when it
 * has no shape, which is what every world did before this existed.
 */
function xeric_story_ambient_chance(array $t, PDO $db, ?int $epoch = null): float
{
    $base = (float)($t['events']['sweep_chance'] ?? XERIC_SWEEP_CHANCE);
    $a    = xeric_story_ambient($t, $db, $epoch);
    if ($a === [] || $a['m'] === 1.0) return $base;
    return max(0.05, min(0.9, $base * $a['m']));
}

/**
 * The kind weights with the world's own shape on them. Same thumb rules as a
 * story's: it may re-weight what is armed and may never arm or delete.
 */
function xeric_story_ambient_thumb(array $kinds, array $t, PDO $db, ?int $epoch = null): array
{
    $a = xeric_story_ambient($t, $db, $epoch);
    if ($a === []) return $kinds;
    $shape = xeric_story_shape($t);
    foreach ((array)($shape['kind_thumb'][$a['stage']] ?? []) as $k => $mul) {
        $k   = (string)$k;
        $mul = (float)$mul;
        if (!isset($kinds[$k]) || $mul <= 0.0) continue;
        $kinds[$k]['weight'] = (float)($kinds[$k]['weight'] ?? 1.0) * $mul;
    }
    return $kinds;
}

// ---------------------------------------------------------------------------
// The plot snake
// ---------------------------------------------------------------------------

/**
 * Intensity at a point on the curve. Piecewise linear, and exact in the two
 * places exactness is load-bearing.
 *
 * A control point answers for itself rather than being interpolated toward: read
 * off the segment to its left, 0.5 comes back as 0.49999999999999994 and the
 * false calm's ×1.0 stops being arithmetic. Strictly inside a flat segment
 * i0 + (i1 - i0) * f is exact for free, because (i1 - i0) is zero.
 */
function xeric_story_intensity(array $curve, float $p): float
{
    $pts = [];
    foreach ($curve as $pt) {
        if (is_array($pt) && count($pt) === 2) $pts[] = [(float)$pt[0], (float)$pt[1]];
    }
    if ($pts === []) return 0.5;                       // no curve is the world at its own pace
    foreach ($pts as [$px, $pi]) if ($px === $p) return $pi;

    $last = count($pts) - 1;
    if ($p < $pts[0][0])     return $pts[0][1];
    if ($p > $pts[$last][0]) return $pts[$last][1];

    for ($i = 0; $i < $last; $i++) {
        [$p0, $i0] = $pts[$i];
        [$p1, $i1] = $pts[$i + 1];
        if ($p < $p0 || $p > $p1) continue;
        if ($p1 <= $p0) return $i1;
        return $i0 + ($i1 - $i0) * (($p - $p0) / ($p1 - $p0));
    }
    return $pts[$last][1];
}

/**
 * Where on the snake this progress lands: intensity, the stage it implies, and
 * the multiplier that stage does its work with.
 *
 * The stage is DERIVED here and declared nowhere. A second field naming it would
 * be a second timeline to keep in sync, and the first thing that would happen is
 * that the two would disagree.
 *
 * @return array{intensity:float,stage:string,m:float}
 */
function xeric_story_snake(array $snake, float $p): array
{
    $p     = max(0.0, min(1.0, $p));
    $curve = (array)($snake['curve'] ?? []);
    $i     = xeric_story_intensity($curve, $p);
    $swing = (float)($snake['pace_swing'] ?? 0.6);

    $fc = array_map('floatval', (array)($snake['false_calm'] ?? []));
    $fc0 = $fc[0] ?? 1.1;
    $fc1 = $fc[1] ?? -1.0;

    $knee = (float)($curve[1][0] ?? 0.0);
    $peakP = 1.0;
    $peakI = -1.0;
    foreach ($curve as $pt) {
        if (!is_array($pt) || count($pt) !== 2) continue;
        if ((float)$pt[1] > $peakI) { $peakI = (float)$pt[1]; $peakP = (float)$pt[0]; }
    }

    // Past the peak, not at it: the beat written to land on the crest is at the
    // crest, and a story whose last reveal read as `closing` would be paced by
    // an off-by-one.
    if ($p >= $fc0 && $p <= $fc1)      $stage = 'false_calm';
    elseif ($p > $peakP)               $stage = 'closing';
    elseif ($p <= $knee)               $stage = 'opening';
    elseif ($p > $fc1)                 $stage = 'crescendo';
    else                               $stage = xeric_story_slope($curve, $p) >= 0.0 ? 'rising' : 'taper';

    return [
        'intensity' => $i,
        'stage'     => $stage,
        // 0.5 → exactly 1.0: the false calm is not the story turning down, it is
        // the town carrying on the way it would have if nobody had died.
        'm'         => 1.0 + $swing * (2.0 * $i - 1.0),
    ];
}

/** The sign of the curve at a point: which side of a knee this progress is on. */
function xeric_story_slope(array $curve, float $p): float
{
    $prev = null;
    foreach ($curve as $pt) {
        if (!is_array($pt) || count($pt) !== 2) continue;
        $cur = [(float)$pt[0], (float)$pt[1]];
        if ($prev !== null && $p > $prev[0] && $p <= $cur[0]) {
            return $cur[0] > $prev[0] ? ($cur[1] - $prev[1]) / ($cur[0] - $prev[0]) : 0.0;
        }
        $prev = $cur;
    }
    return 0.0;
}
