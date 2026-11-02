<?php
/**
 * stream.php — the live view's three verbs (engine/stream.php is the rest).
 *
 *     POST stream.php {w, a: 'start'}                       go live in the room you stand in
 *     POST stream.php {w, a: 'beat', id, hidden_for?}       still watching; counts the seconds
 *     POST stream.php {w, a: 'end',  id, why?}              stop watching
 *
 * ALL THREE ARE THE OWNER'S. A live view bills by the second on the owner's
 * machine; a guest reads the room and never runs its meter. The play page
 * reaches this with its cookie, the stand page with its device token (the
 * API's own identify, so a Bearer is honoured and a cross-site request is
 * not) — and both pass the same door afterwards.
 *
 * THE BEAT IS THE METER. Every ten seconds the page says it is still showing
 * the view; the engine counts the seconds since the last beat, capped, and
 * they are written here into the world's ledger as kind `stream`
 * (engine/budget.php, `stream_seconds_per_day`), marked costly by where the
 * machine is. Spend-guarded twice: the start asks the ceiling before a
 * session exists, and every beat asks it again after counting, ending the
 * view the moment the day's seconds are gone. A page that stops beating is
 * swept out after thirty seconds and is not billed for them.
 *
 * `why` on an end is the page's own word for why it stopped (the tab was
 * hidden, the person pressed the button), chosen from a short list here — a
 * page cannot write a sentence into the row another page will read out.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';
require_once __DIR__ . '/api-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    xeric_web_json(['error' => 'the live view is POST only: a GET never spends'], 405);
}

// A device token, when one is presented, is who this is; otherwise the
// cookie, through the same guard every POST passes.
if (xeric_api_authorization($_SERVER) !== '') {
    $who = xeric_api_identify($_SERVER);
    if (!$who['ok']) xeric_web_json(['error' => $who['error'], 'kind' => $who['kind']], (int)$who['status']);
}
$in   = xeric_web_input();
$slug = xeric_web_slug((string)($in['w'] ?? ''));
$a    = (string)($in['a'] ?? '');
if ($slug === '') xeric_web_json(['error' => 'which xeric?'], 404);
if (!in_array($a, ['start', 'beat', 'end'], true)) xeric_web_json(['error' => 'a is start, beat or end'], 400);

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}
xeric_play_owner_only($w, 'the live view');

$t  = $w['template'];
$db = $w['db'];
$ep = xeric_scene_endpoint();

if ($a === 'start') {
    // Asked, not assumed — the look's rule: a press is where the machine is
    // asked whether it is there, before anything is kept.
    if ($ep === null || !xeric_scene_up($ep, 4)) {
        xeric_web_json(['error' => 'no scene machine is answering, so there is nothing to go live with'], 409);
    }
    $r = xeric_stream_start($t, $db, $ep, ['scenes_dir' => xeric_play_scenes_dir($db)]);
    if (!$r['ok']) {
        $body = ['error' => ucfirst((string)$r['why']) . '.'];
        if ($r['status'] === 429) $body['kind'] = 'budget';
        xeric_web_json($body, (int)$r['status']);
    }
    xeric_web_json(['ok' => 1, 'stream' => xeric_play_stream_public($r['row'])]);
}

$id = (int)($in['id'] ?? 0);
$row = $id > 0 ? xeric_stream_get($db, $id) : null;
if ($row === null) xeric_web_json(['error' => 'there is no such live view'], 404);

if ($a === 'end') {
    $why = match ((string)($in['why'] ?? '')) {
        'hidden' => 'the tab was hidden for more than ' . XERIC_STREAM_AWAY . ' seconds',
        'closed' => 'you ended it',
        default  => 'the page stopped it',
    };
    xeric_stream_end($db, $ep, $id, $why);
    $row = xeric_stream_get($db, $id);
    xeric_web_json(['ok' => 1, 'live' => false, 'reason' => (string)$row['reason']]);
}

// The beat: counted, written to the world's ledger, and judged.
$now = xeric_clock_now($db, $t);
$r = xeric_stream_beat($t, $db, $ep, $id, [
    'now'        => $now,
    'hidden_for' => max(0, (int)($in['hidden_for'] ?? 0)),
    'meter'      => function (int $s, bool $costly) use ($db, $now): void {
        xeric_budget_note($db, $now, 'stream', $s, $costly);
    },
]);
xeric_web_json(['ok' => 1, 'live' => $r['live'], 'reason' => $r['reason'], 'counted' => $r['counted'],
                'seconds' => $r['seconds'], 'events' => count($r['events']), 'ends_at' => $r['ends_at']]);
