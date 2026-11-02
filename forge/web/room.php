<?php
/**
 * room.php — talk to the room.  GET ?w=<slug>&after=<message id>, POST a=say|carry|leave
 *
 * The play view's room strip is a client of this and nothing else. The
 * session is engine/room.php's (who sits, who answers, when the room keeps
 * talking, how a line is spaced, what the close writes); the turn is carried
 * by room-worker.php under a lease; this page is the doors.
 *
 *  • GET is the poll. Lines are message rows of the room's conversation, and
 *    the cursor is a message id — it survives a reload and a second device,
 *    which a progress stream would not (a room spans many jobs, and
 *    progress.php caps a visitor at four streams). The page asks every 700 ms
 *    while a turn is live and every four seconds otherwise, never while the
 *    tab is hidden. A line is a row only from its due time on, so no client
 *    can show one early: there is nothing to show.
 *
 *  • a=say — the owner's door (a guest reads the thread and never speaks
 *    into it), the player's position, one of the hour's thirty messages; the
 *    line is heard (floor, wall) and lands as its row at once; the worker is
 *    started unless one already holds the floor.
 *
 *  • a=carry — TALKING WITHOUT YOU, asked by a page that has sat idle with
 *    the tab visible. The engine's gate answers (two in a row, six a
 *    session, an idle queue, a local machine unless the template opts in).
 *    A 409 is not an error, it is the room keeping quiet.
 *
 *  • a=leave — stepping back from the table. The session seals; its close
 *    (the diaries, one event, the trail) lands in the worker.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$sid = xeric_session_id();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    @set_time_limit(60);
    $in     = xeric_web_input();
    $slug   = xeric_web_slug((string)($in['w'] ?? $_GET['w'] ?? ''));
    $action = (string)($_GET['a'] ?? $in['a'] ?? '');
} else {
    $slug   = xeric_web_slug((string)($_GET['w'] ?? ''));
    $action = '';
}

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_json(['error' => $e->getMessage()], 404);
}
$T     = $w['template'];
$db    = $w['db'];
$guest = xeric_play_is_guest($w);

/** The worker, told everything a process without a cookie needs. */
$spawn = function (string $lease) use ($w, $sid): void {
    xeric_web_spawn(xeric_web_job_new(), ['slug' => (string)$w['slug'], 'sid' => $sid,
        'db' => (string)$w['db_path'], 'adult' => xeric_session_adult($sid), 'lease' => $lease], 'room-worker.php');
};

/** The world's token ceiling (engine/budget.php), asked before a beat is started. */
$affords = function () use ($T, $db, $sid): void {
    try { $ep = xeric_play_endpoint($sid); } catch (Throwable $e) { return; }   // no model, no spend
    $bv = xeric_budget_allows($T, $db, xeric_clock_now($db, $T), 'tokens', 1, xeric_budget_llm_costly($ep));
    if (!$bv['ok']) xeric_web_json(xeric_budget_body($bv), 429);
};

/** The engine's sentences are for a log; its refusals are for people, minus the address. */
$bare = fn(string $m): string => preg_replace('/^room:\s*(refused,\s*)?/', '', $m) ?? $m;

// ---------------------------------------------------------------------------
// The poll
// ---------------------------------------------------------------------------
if ($action === '') {
    $after = max(0, (int)($_GET['after'] ?? 0));

    // Housekeeping is the owner's: who came and went, and whether it is over.
    // No model in it — but a seal it finds is a close somebody owes, and the
    // close spends, so the spend door stands in front of the spawn.
    if (!$guest) {
        try {
            $sealed = xeric_play_room_tick($w);
            if ($sealed !== [] && !xeric_web_cross_site()) $spawn('');
        } catch (Throwable $e) { /* the poll still answers */ }
    }

    $live = xeric_room_live_get($db);
    $conv = $live !== null ? (int)$live['conv'] : 0;
    // A thread that has closed may still be read — the page that was showing
    // it asks by id, and only a room's own thread answers.
    $askC = (int)($_GET['c'] ?? 0);
    $names = $live;
    if ($askC > 0 && $askC !== $conv) {
        $c = xeric_conversation_get($db, $askC);
        if ($c !== null && (string)$c['kind'] === 'room') {
            $conv  = $askC;
            $seal  = xeric_room_seals($db)[$askC] ?? null;
            $names = $seal ?? ['names' => [XERIC_ROOM_YOU => xeric_play_room_player($w)['name']]];
        }
    }
    $rows = $conv > 0 ? xeric_room_rows($db, $conv, $after) : [];
    $pub  = xeric_play_room_public($T, $live, !$guest);
    xeric_web_json([
        'ok'    => true,
        'live'  => $pub,
        'conv'  => $conv,
        'rows'  => $names !== null ? xeric_play_room_rows($T, $names, $rows) : [],
        'last'  => $rows !== [] ? (int)$rows[count($rows) - 1]['id'] : $after,
        'poll'  => $pub !== null && $pub['turn'] ? 700 : 4000,
        'guest' => $guest,
    ]);
}

// ---------------------------------------------------------------------------
// The doors
// ---------------------------------------------------------------------------
if ($action === 'say') {
    xeric_play_owner_only($w, 'speaking into the room');
    $text = trim((string)($in['text'] ?? ''));
    if ($text === '') xeric_web_json(['error' => 'there is nothing to say'], 400);
    if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);

    $affords();
    // One of the hour's thirty, like any line said — taken at the door and
    // given back by itself if the line is refused below.
    xeric_limit_guard(xeric_limit_check('message', ['sid' => $sid]));

    try {
        $r = xeric_play_room_say($w, $text);
    } catch (Throwable $e) {
        $m = $e->getMessage();
        $rule = str_contains($m, 'refused,') || xeric_play_say_refused($m);
        xeric_web_json(['error' => $rule ? ucfirst($bare($m)) : xeric_play_say_error($m, ''),
                        'kind' => $rule ? 'refused' : 'bad'], $rule ? 409 : 400);
    }
    xeric_limit_note('message', ['sid' => $sid]);

    if ($r['lease'] !== null) {
        try {
            $spawn((string)$r['lease']);
        } catch (Throwable $e) {
            // The line is said and stays said; nobody can answer it. Free the
            // floor so the next line can try again.
            xeric_room_live_edit($db, function (?array &$live) use ($r): void {
                if ($live !== null && (string)($live['lease']['id'] ?? '') === (string)$r['lease']) {
                    $live['lease'] = null; $live['budget'] = 0;
                }
            });
            xeric_web_json(['ok' => true, 'mid' => $r['mid'], 'text' => $r['text'],
                'warn' => 'Nobody could be started to answer, ' . $e->getMessage()]);
        }
    }
    xeric_web_json(['ok' => true, 'mid' => (int)$r['mid'], 'text' => (string)$r['text'],
                    'opened' => (bool)$r['opened'], 'conv' => (int)$r['conv'],
                    'live' => xeric_play_room_public($T, xeric_room_live_get($db), true)]);
}

if ($action === 'carry') {
    xeric_play_owner_only($w, 'letting the room talk');
    $affords();
    $r = xeric_play_room_carry($w, $sid);
    if (!$r['ok']) xeric_web_json(['ok' => false, 'why' => $r['why'], 'kind' => 'quiet'], 409);
    try {
        $spawn((string)$r['lease']);
    } catch (Throwable $e) {
        xeric_room_live_edit($db, function (?array &$live) use ($r): void {
            if ($live !== null && (string)($live['lease']['id'] ?? '') === (string)$r['lease']) {
                $live['lease'] = null; $live['budget'] = 0;
            }
        });
        xeric_web_json(['ok' => false, 'why' => 'Nobody could be started to talk.', 'kind' => 'quiet'], 409);
    }
    xeric_web_json(['ok' => true]);
}

if ($action === 'leave') {
    xeric_play_owner_only($w, 'ending the conversation');
    $conv = xeric_room_seal($db, 'stepped back', xeric_clock_now($db, $T));
    if ($conv !== null) {
        try { $spawn(''); } catch (Throwable $e) { /* the heart lands it */ }
    }
    xeric_web_json(['ok' => true, 'conv' => $conv]);
}

xeric_web_json(['error' => 'no such action'], 400);
