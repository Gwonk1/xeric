<?php
/**
 * fate.php — who this world has lost, and whether it gets them back.
 *
 *     GET  fate.php?w=<slug>                    → the ledger, and the rule
 *     POST fate.php {world, act:"kill",    who, how}
 *     POST fate.php {world, act:"revive",  who}
 *     POST fate.php {world, act:"end",     how}   the bomb: everybody at once
 *     POST fate.php {world, act:"restore"}        everybody back, history intact
 *
 * Named for the rule rather than the event, because the rule is what this
 * endpoint is really about. `kill` and `revive` are two lines each; everything
 * hard here is `permanent`, and every branch below exists to make sure the
 * engine's refusal is the only thing that decides.
 *
 * THIS CALLS NO MODEL, so it answers inline like where.php rather than
 * detaching a worker like tick.php: it writes one row and reads a ledger back.
 * Nothing here takes a place in the queue, and nothing is metered — dying is not
 * a thing a person should have to wait their turn for.
 *
 * REVIVAL IS NOT A REWIND, and the response says so in the only way that
 * matters: it carries no clock. The death still happened, every memory of it
 * stays, and everyone who watched still remembers. If a caller ever wants to
 * undo the hours as well, that is a different feature and it is one
 * xeric_clock_reset() already has an opinion about.
 *
 * WHY THERE IS A KILL BUTTON AT ALL. Sweeps and story overlays are where deaths
 * are SUPPOSED to come from, and neither of them writes one yet. Until they do,
 * this is how a death happens — and it stays afterwards, because "computer, end
 * this world" is half of what the owner asked the feature for.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in   = $post ? xeric_web_input() : $_GET;
$slug = xeric_web_slug((string)($in['world'] ?? $in['w'] ?? ''));
$sid  = xeric_session_id();

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}

$T  = $w['template'];
$db = $w['db'];

/** The ledger, and the rule, in the shape every response here ends with. */
$ledger = function () use ($T, $db): array {
    $rows = [];
    foreach (xeric_deaths($db) as $h => $d) {
        $rows[] = ['handle' => $h, 'name' => xeric_world_name($T, $h),
                   'how' => $d['how'], 'world_epoch' => $d['world_epoch']];
    }
    return [
        'mode'    => xeric_death_mode($T, $db),
        // Whether the rule is still an author's to change. False the moment
        // somebody has died — which is exactly when it stops being a setting and
        // starts being what happened.
        'locked'  => xeric_death_locked($db),
        'dead'    => $rows,
        'living'  => count(xeric_death_living($T, $db)),
        'ended'   => xeric_death_living($T, $db) === [] && $rows !== [],
    ];
};

if (!$post) xeric_web_json(['ok' => true] + $ledger());

$act = strtolower(trim((string)($in['act'] ?? '')));
$who = is_string($in['who'] ?? null) ? trim($in['who']) : '';
$how = mb_substr(trim((string)($in['how'] ?? '')), 0, 200);
$at  = (int)xeric_clock_now($db, $T)['epoch'];

// `how` is COMMONS TEXT — the roster prints it to anybody who can see the cast —
// so it is capped and taken as written and never used to reason about anything.
// What the town says happened is not the same as what happened, and this
// endpoint is not the place that knows the difference.

switch ($act) {
    case 'kill':
        $r = xeric_death_kill($T, $db, $who, $at, $how);
        break;

    case 'revive':
        $r = xeric_death_revive($T, $db, $who);
        break;

    case 'end':
        // The bomb. One `how` across the whole cast, because a catastrophe is one
        // thing that happened and the roster should read like it.
        $r = xeric_death_catastrophe($T, $db, $at, $how !== '' ? $how : 'the xeric ended');
        break;

    case 'restore':
        $r = xeric_death_restore($T, $db);
        break;

    default:
        xeric_web_json(['error' => "that is not something that can happen to somebody "
            . "(kill, revive, end, restore)"], 400);
}

if (empty($r['ok'])) {
    // 409, not 400: nothing about the request was malformed. It asked for
    // something this world does not allow, which is a state, and a caller that
    // told somebody to check their spelling would be lying about a rule.
    xeric_web_json(['error' => (string)($r['error'] ?? 'that cannot happen here'), 'kind' => 'fate'], 409);
}

xeric_web_json(['ok' => true, 'act' => $act, 'result' => $r] + $ledger());
