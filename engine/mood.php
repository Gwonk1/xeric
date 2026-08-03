<?php
/**
 * Xeric — the world's mood. One number, in the world's own vocabulary.
 *
 * `world_mood` has been in the schema since the beginning: an AXIS with the
 * world's own words at each end, a RANGE, MOTIFS for either mood, DRIVERS
 * naming what pushes it, and a REVERSION rule. Everything except the number
 * itself, because nothing ever wrote one — narrator.php reads
 * `arcs['']['needle']` and prints "the town's needle sits at 0", forever, in
 * every world that has ever existed. A mood that cannot move is a mood that
 * is not there, and it has been printing a zero at people for weeks.
 *
 * SO THE HOURS MOVE IT. Every event a sweep lands pushes the needle a little,
 * and the push comes from two places in this order:
 *
 *   1. the WORLD'S OWN drivers, when one names this kind — a world that says
 *      a funeral is worth +3 gets +3, because that is the world describing
 *      itself and no default has any business overruling it;
 *   2. otherwise the engine's reading of what the kind IS — a mishap raises
 *      the temperature, a shared meal lowers it, and an ordinary Tuesday
 *      moves nothing.
 *
 * AND IT COMES BACK. `reversion: mean-toward-ordinary` is in the schema and
 * it is the part that makes the number mean anything: without it, a world
 * accumulates whatever happened most and sits at the end of its range
 * forever. Every step drifts one point toward the world's own `ordinary`, so
 * the needle answers "what has this town been like LATELY" rather than "what
 * has this town ever been".
 *
 * THE AXIS WORDS ARE THE WORLD'S. Milldale calls one end "reckless — the
 * river is up and the mill is awake" and the other "light — people are kind
 * for no reason at all", which is not a scale anybody else would have written
 * and is exactly why the reading is rendered in those words rather than as a
 * number with a label bolted on.
 *
 * IT NEVER REACHES A SYSTEM MESSAGE. Like weather and the shape, this is a
 * thing the world knows and the prompts do not: it moves hourly, and a
 * hourly number in a byte-stable block would drag every prompt out of cache.
 * The narrator may read it (the narrator sees the board) and the sidebar may
 * show it. Characters simply live in it.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';

/** The world's own range, defaulted to the schema's [-10, 10]. */
function xeric_mood_range(array $t): array
{
    $r = (array)($t['world_mood']['range'] ?? []);
    $lo = isset($r[0]) ? (int)$r[0] : -10;
    $hi = isset($r[1]) ? (int)$r[1] : 10;
    return $hi > $lo ? [$lo, $hi] : [-10, 10];
}

/** Where this world calls "ordinary" — the point reversion pulls toward. */
function xeric_mood_ordinary(array $t): int
{
    return (int)($t['world_mood']['axis']['ordinary'] ?? 0);
}

/**
 * What one hour of this kind does to the needle.
 *
 * The world's own drivers win outright; the fallback is the engine's reading
 * of the kind, and a kind nobody has an opinion about moves nothing at all.
 * Deliberately small numbers: an hour is one hour, and a needle that swung
 * to its stop on a single bad afternoon would be a mood ring, not a town.
 */
function xeric_mood_delta(array $t, string $kind): int
{
    foreach ((array)($t['world_mood']['drivers'] ?? []) as $d) {
        if ((string)($d['on'] ?? '') === $kind) return (int)($d['delta'] ?? 0);
    }
    // Toward the positive end is toward TENSION; the negative end is warmth.
    // Which words those ends carry is the world's business, not this table's.
    static $fallback = [
        'mishap' => 2, 'friction' => 2, 'chase' => 2, 'loss' => 3, 'absence' => 1,
        'rumor' => 1, 'confidence' => 1, 'glimpse' => 1,
        'shared_meal' => -2, 'ease' => -2, 'ritual' => -1, 'favor' => -1,
        'closeness' => -1, 'recognition' => -1, 'visit' => -1,
        'routine' => 0, 'craft' => 0, 'ordinary' => 0,
    ];
    return (int)($fallback[$kind] ?? 0);
}

/** The needle right now. */
function xeric_mood_now(PDO $db, array $t): int
{
    [$lo, $hi] = xeric_mood_range($t);
    return max($lo, min($hi, xeric_arc_int($db, xeric_arc_world(), 'needle', xeric_mood_ordinary($t))));
}

/**
 * One hour's worth of mood: a push, OR the drift home.
 *
 * Still one call per hour, so reversion stays a fact about hours rather than
 * about how often somebody happened to call this — that part of the original
 * rule was right and is unchanged.
 *
 * ── BUT NOT BOTH IN THE SAME HOUR, WHICH IS WHAT IT USED TO DO ────────────
 *
 * Push and drift were applied one after the other, so they cancelled exactly
 * whenever the push was ±1 — and ±1 is what NINE of the seventeen kinds are
 * worth. Measured over forty consecutive hours of a single kind: rumor,
 * confidence, glimpse and absence all finished at 0; visit, recognition, favor,
 * ritual and closeness all finished at 0. A world whose armed kinds were all ±1
 * had a needle nailed to ordinary forever, which is the exact complaint this
 * file was written to fix — "it has been printing a zero at people for weeks".
 *
 * It was worse than inert for the case the docblock cares most about: a WORLD
 * that declares `drivers: {funeral: +1}` was overruled by the reversion, in the
 * one place the file promises "no default has any business overruling it". And
 * at |delta| ≥ 2 the reversion did not do its job either — the needle just ran
 * to the end of the range and pinned there, which is what reversion exists to
 * prevent.
 *
 * SO AN HOUR IS ONE THING OR THE OTHER. An hour where something happened is an
 * hour that moves the needle by what happened. An hour where nothing did — a
 * routine, a craft, an ordinary Tuesday, all of which are worth exactly 0 — is
 * the town going quietly back to being itself. That is what "lately" means, and
 * it is a truer reading than a number that shrinks while things are still
 * happening: a week of nothing but rumors SHOULD leave a town sitting at the
 * reckless end, because that is what that town has been like.
 *
 * @return int the needle after this hour
 */
function xeric_mood_step(PDO $db, array $t, string $kind, ?int $at = null): int
{
    [$lo, $hi] = xeric_mood_range($t);
    $ord   = xeric_mood_ordinary($t);
    $was   = xeric_mood_now($db, $t);
    $delta = xeric_mood_delta($t, $kind);

    if ($delta !== 0) {
        $now = $was + $delta;
    } else {
        // mean-toward-ordinary, one point per quiet hour, and never past the
        // middle: a reversion that overshot would oscillate around ordinary
        // forever.
        if ($was > $ord)      $now = max($ord, $was - 1);
        elseif ($was < $ord)  $now = min($ord, $was + 1);
        else                  $now = $was;
    }

    $now = max($lo, min($hi, $now));
    if ($now !== $was) xeric_arc_set($db, xeric_arc_world(), 'needle', (string)$now, $at);
    return $now;
}

/**
 * The narrator's hand on the needle — the one part of world_mood that was
 * designed before it was built, and it was designed with a limp in it.
 *
 * `narrator_hand: {enabled, cap: 2, invariant: "pushes harder when ordinary
 * than extreme"}`. The cap is how far one push may move it. The invariant is
 * the interesting half: a storyteller leaning on a town that is already at
 * the end of its range should get less for the effort than one leaning on a
 * quiet Tuesday, because the first is pushing something that is already
 * falling and the second is starting weather.
 *
 * So the push is scaled by how far from ordinary the needle already sits:
 * full strength at ordinary, half strength at the far end. A narrator cannot
 * ratchet a town to its stop by asking twice.
 *
 * @return int the needle after the push
 */
function xeric_mood_hand(PDO $db, array $t, int $push, ?int $at = null): int
{
    $hand = (array)($t['world_mood']['narrator_hand'] ?? []);
    if (($hand['enabled'] ?? true) === false || $push === 0) return xeric_mood_now($db, $t);

    $cap = max(1, (int)($hand['cap'] ?? 2));
    $push = max(-$cap, min($cap, $push));

    [$lo, $hi] = xeric_mood_range($t);
    $ord = xeric_mood_ordinary($t);
    $was = xeric_mood_now($db, $t);

    // How far along its own range the needle already is, 0 at ordinary and 1
    // at either stop — and the push loses half its strength across that.
    $reach = $push > 0 ? max(1, $hi - $ord) : max(1, $ord - $lo);
    $out   = abs($was - $ord) / $reach;
    $scale = 1.0 - 0.5 * min(1.0, $out);

    $moved = (int)($push > 0 ? floor($push * $scale) : ceil($push * $scale));
    // A push that scaled to nothing still counts for one: the narrator asked.
    if ($moved === 0) $moved = $push > 0 ? 1 : -1;

    $now = max($lo, min($hi, $was + $moved));
    if ($now !== $was) xeric_arc_set($db, xeric_arc_world(), 'needle', (string)$now, $at);
    return $now;
}

/**
 * The needle in the world's own words, for anything that shows a person the
 * mood. '' when this world declared no axis — a world with no vocabulary for
 * its mood is a world that should be shown no mood, rather than a number.
 *
 * @return array{n:int,word:string,side:string,motif:string}|array{}
 */
function xeric_mood_read(PDO $db, array $t): array
{
    $axis = (array)($t['world_mood']['axis'] ?? []);
    $pos  = trim(xeric_text($axis['positive'] ?? ''));
    $neg  = trim(xeric_text($axis['negative'] ?? ''));
    if ($pos === '' && $neg === '') return [];

    $n    = xeric_mood_now($db, $t);
    $ord  = xeric_mood_ordinary($t);
    [$lo, $hi] = xeric_mood_range($t);

    // The axis words are whole phrases ("reckless — the river is up"), and the
    // head of one is what a sidebar has room for.
    $head = function (string $s): string {
        $s = trim((string)preg_split('/[—–\-:,]/u', $s, 2)[0]);
        return $s !== '' ? $s : '';
    };

    if ($n === $ord) return ['n' => $n, 'word' => 'ordinary', 'side' => 'ordinary', 'motif' => ''];

    $side = $n > $ord ? 'positive' : 'negative';
    $word = $head($n > $ord ? $pos : $neg);
    // Far from ordinary reads as itself; near it, as a lean.
    $reach = $n > $ord ? ($hi - $ord) : ($ord - $lo);
    $far   = $reach > 0 && abs($n - $ord) >= max(1, (int)round($reach / 2));

    $motifs = (array)($t['world_mood']['motifs'][$n > $ord ? 'dark' : 'light'] ?? []);
    $motif  = '';
    if ($motifs !== []) {
        // One motif per world-day, so the sidebar does not flicker between
        // four different images of the same mood while somebody reads it.
        $motif = trim(xeric_text($motifs[abs($n) % count($motifs)]));
    }

    return ['n' => $n, 'word' => ($far ? $word : 'a little ' . $word), 'side' => $side, 'motif' => $motif];
}
