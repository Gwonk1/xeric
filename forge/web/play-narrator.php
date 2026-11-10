<?php
/**
 * play-narrator.php — the narrator's pane: ASK and WRITE AHEAD in the play
 * view, and INVESTIGATE for the inspector (why.php).
 *
 * PART OF play-lib.php, in the same arrangement as the other parts: play-lib
 * require_once's this file and this file requires play-lib, so either include
 * loads the whole. The engine is engine/narrator.php; nothing here re-decides
 * anything it decides. What lives here is the web's half:
 *
 *   • ASK, stored. The engine's ask is a read and stays one. The web keeps the
 *     exchange as a conversation of kind `narrator` with handle '' — the empty
 *     handle the book and the sidebar already pass over, so the narrator's
 *     thread is never mistaken for a scene with somebody — and shows the last
 *     few. One question, one model call: the history rides the user message,
 *     never a second turn.
 *
 *   • THE RAW TEMPLATE. xeric_play_open() hands back a COMPOSED template (an
 *     overlay's held pieces written into dossiers). The narrator's contract is
 *     the uncomposed one, so every call here re-reads the world file and clamps
 *     its rating exactly as the open did, and passes the raw overlays beside it.
 *
 *   • WRITE AHEAD, owner only. The intent's words are shown back to the owner
 *     here and nowhere else; what crosses to the sweeps is handles and a kind.
 *
 *   • THE AUDIT, cached per world-hour in a file under the data dir — never in
 *     the world's database, because the inspector is read-only to the row and
 *     a cache is not a reason to stop being so.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/narrator.php';

/** Exchanges the pane shows, newest last. */
const XERIC_PLAY_NARRATOR_SHOWN = 6;

/** A question is one breath, the same ceiling a chat line lives under. */
const XERIC_PLAY_NARRATOR_ASK_MAX = 600;

/** What an empty box asks: the question the old hint answered. */
const XERIC_PLAY_NARRATOR_COLD = 'I am lost. What is worth my attention right now?';

/**
 * The template the narrator reads: the world file as forged, rating-clamped
 * the way xeric_play_open() clamped it, NOT composed. Falls back to stripping
 * the composed block when the file will not read — xeric_narrator_canon()
 * does that much defensively anyway.
 */
function xeric_play_narrator_canon(array $w, ?bool $adult = null): array
{
    try {
        $t = xeric_world_load(rtrim((string)$w['dir'], '/') . '/world-template.json');
        return xeric_world_clamp_rating($t, $adult ?? xeric_session_adult());
    } catch (Throwable $e) {
        return (array)$w['template'];
    }
}

/** The narrator's conversation id, or null when nobody has asked yet. */
function xeric_play_narrator_conv(PDO $db): ?int
{
    $c = xeric_conversation_find($db, '', 'narrator');
    return $c !== null ? (int)$c['id'] : null;
}

/**
 * The last few exchanges, oldest first, as pairs. A question with no answer
 * after it (a crash between the two writes cannot happen — they are one
 * transaction — but a hand-edited db can) is dropped rather than shown open.
 *
 * @return array<int,array{q:string,a:string,when:int}>
 */
function xeric_play_narrator_history(PDO $db, int $n = XERIC_PLAY_NARRATOR_SHOWN): array
{
    $id = xeric_play_narrator_conv($db);
    if ($id === null) return [];
    $rows = xeric_messages_recent($db, $id, max(2, $n * 2), 0, xeric_state_rating());
    $out  = [];
    $q    = null;
    foreach ($rows as $r) {
        if ((string)$r['role'] === 'user') {
            $q = $r;
        } elseif ($q !== null && (string)$r['role'] === 'narrator') {
            $out[] = ['q' => (string)$q['content'], 'a' => (string)$r['content'],
                      'when' => (int)($r['world_epoch'] ?? $r['created_at'])];
            $q = null;
        }
    }
    return array_slice($out, -$n);
}

/**
 * Ask, answer, keep. The one model call is xeric_narrator_ask(); the answer is
 * put in the narrator's voice by the engine, and both lines land in one
 * transaction or neither does — a failed call stores nothing (fail closed).
 *
 * @return array{text:string,question:string,stored:bool}
 */
function xeric_play_narrator_ask(array $w, string $question, array $endpoint, ?bool $adult = null): array
{
    $question = trim((string)preg_replace('/\s+/u', ' ', $question));
    if (mb_strlen($question) > XERIC_PLAY_NARRATOR_ASK_MAX) {
        $question = rtrim(mb_substr($question, 0, XERIC_PLAY_NARRATOR_ASK_MAX)) . '…';
    }
    $asked = $question !== '' ? $question : XERIC_PLAY_NARRATOR_COLD;

    $db  = $w['db'];
    $t   = xeric_play_narrator_canon($w, $adult);
    $now = xeric_clock_now($db, $t);

    $out  = xeric_narrator_ask($t, $db, $asked, $now, $endpoint, [
        'stories' => (array)($w['stories'] ?? []),
        'history' => xeric_play_narrator_history($db, XERIC_NARRATOR_HISTORY),
    ]);
    $text = trim((string)$out['text']);
    if ($text === '') throw new RuntimeException('nothing came back');

    $at    = xeric_state_time();
    $epoch = (int)($now['epoch'] ?? 0);
    $db->beginTransaction();
    try {
        $conv = xeric_conversation_for($db, '', 'narrator', 'the narrator', $at);
        xeric_message_append($db, $conv, 'user', null, $asked, $epoch, $at);
        xeric_message_append($db, $conv, 'narrator', null, $text, $epoch, $at);
        // Read the moment it is written: the asker is looking at it, and an
        // unread count on the narrator's thread would light the badge that
        // means somebody in the world said something to you.
        xeric_conversation_touch($db, $conv, 0, $at);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['text' => $text, 'question' => $asked, 'stored' => false];
    }
    return ['text' => $text, 'question' => $asked, 'stored' => true];
}

/**
 * Write one intended beat. The engine validates and parses; this adds the
 * clock and nothing else.
 *
 * @return array{intent:array,notes:array}
 * @throws RuntimeException with the engine's own sentence when it refuses
 */
function xeric_play_narrator_intend(array $w, string $text): array
{
    $t = xeric_play_narrator_canon($w);
    return xeric_narrator_intend($t, $w['db'], $text, ['epoch' => xeric_clock_epoch($w['db'])]);
}

/** Take a live intent back. Null when there is no live #$n. */
function xeric_play_narrator_retire(array $w, int $n): ?array
{
    return xeric_narrator_intent_retire($w['db'], $n, xeric_clock_epoch($w['db']));
}

/**
 * The owner's listing: settles first (the CLI's --intents does the same), so
 * a beat an hour already realized shows struck through the moment anybody
 * looks. Newest first.
 */
function xeric_play_narrator_intents(array $w): array
{
    $t = xeric_play_narrator_canon($w);
    try { xeric_narrator_intents_settle($t, $w['db']); } catch (Throwable $e) { /* a listing is still a listing */ }
    return array_reverse(xeric_narrator_intents($w['db']), true);
}

/** The intents as list items. Words are the owner's own, escaped. */
function xeric_play_narrator_intents_html(array $w, ?array $rows = null): string
{
    $rows ??= xeric_play_narrator_intents($w);
    $T     = $w['template'];
    $epoch = xeric_clock_epoch($w['db']);
    if ($rows === []) {
        return '<li class="quiet">Nothing written ahead. One sentence is a beat: who, and what kind of hour. '
             . 'The world leans toward it; it is never ordered to write it.</li>';
    }
    $out = '';
    foreach ($rows as $n => $in) {
        $state = (string)($in['state'] ?? '');
        $faded = $state === 'live' && $epoch >= (int)($in['fades'] ?? 0);
        $cls   = $state === 'done' ? 'done' : ($state === 'retired' || $faded ? 'off' : 'live');
        $text  = h((string)($in['text'] ?? ''));
        $line  = $state === 'done' ? '<s>' . $text . '</s>' : $text;
        $why   = match (true) {
            $state === 'done'    => 'delivered by <a href="why.php?w=' . h(rawurlencode((string)$w['slug']))
                . '&amp;e=' . (int)($in['done']['event'] ?? 0) . '">event #' . (int)($in['done']['event'] ?? 0)
                . '</a>, matched on ' . h((string)($in['done']['by'] ?? '')),
            $state === 'retired' => 'taken back',
            $faded               => 'faded: the world never found room for it',
            default              => 'leaning · fades ' . h(xeric_play_stamp($T, (int)($in['fades'] ?? 0))),
        };
        $out .= '<li class="intent ' . $cls . '" data-n="' . (int)$n . '"><span class="it">#' . (int)$n
              . ' ' . $line . '</span> <span class="quiet">' . $why . '</span>'
              . ($cls === 'live' ? ' <button type="button" class="nbtn iretire" data-n="' . (int)$n
                  . '">take back</button>' : '')
              . '</li>';
    }
    return $out;
}

/**
 * The pane's server-rendered half: the kept exchanges as message rows, and —
 * for the owner — the write-ahead list. The guest sees a sentence instead of
 * a box that would only answer 403.
 *
 * AND NOT THE OWNER'S ANSWERS EITHER. The kept exchanges are the narrator —
 * full canon, no walls — answering the owner; a guest on a pairing code who
 * may not ask it may not read what it said when asked, or the 403 on the ask
 * is a door with the room's contents painted on it. The same door as the ask
 * (xeric_play_is_guest): the owner and somebody in their own copy see their
 * thread; a guest sees nothing of it.
 *
 * @return array{msgs:string,intents:string}
 */
function xeric_play_narrator_pane_html(array $w): array
{
    if (xeric_play_is_guest($w)) return ['msgs' => '', 'intents' => ''];
    $msgs = '';
    foreach (xeric_play_narrator_history($w['db']) as $ex) {
        $msgs .= '<li class="me"><span class="b">' . h($ex['q']) . '</span></li>'
               . '<li class="narr"><span class="b">' . h($ex['a']) . '</span></li>';
    }
    return [
        'msgs'    => $msgs,
        'intents' => !empty($w['mine']) ? xeric_play_narrator_intents_html($w) : '',
    ];
}

// ---------------------------------------------------------------------------
// Investigate, for the inspector
// ---------------------------------------------------------------------------

/** Where one world-hour's audit is kept. One file per database, overwritten. */
function xeric_play_narrator_audit_path(array $w): string
{
    return xeric_web_audit_path((string)($w['db_path'] ?? $w['slug']));
}

/**
 * The audit, at most once per world-hour. A cached one for this hour is read
 * back whatever the endpoint; otherwise investigate runs — with the model
 * when one was handed in (the caller meters that), arithmetic alone when not.
 *
 * @return array{observations:array,model:array,hour:int,cached:bool,at:int}
 */
function xeric_play_narrator_audit(array $w, ?array $endpoint, bool $fresh = false): array
{
    $t    = xeric_play_narrator_canon($w);
    $now  = xeric_clock_now($w['db'], $t);
    $hour = intdiv((int)($now['epoch'] ?? 0), 3600);
    $path = xeric_play_narrator_audit_path($w);

    if (!$fresh) {
        $kept = json_decode((string)@file_get_contents($path), true);
        if (is_array($kept) && (int)($kept['hour'] ?? PHP_INT_MIN) === $hour && is_array($kept['observations'] ?? null)) {
            return ['cached' => true] + $kept;
        }
    }

    $r   = xeric_narrator_investigate($t, $w['db'], $endpoint, ['now' => $now]);
    $out = ['observations' => $r['observations'],
            'model' => ['asked' => $r['model']['asked'], 'kept' => $r['model']['kept'],
                        'dropped' => $r['model']['dropped']],
            'hour' => $hour, 'at' => time()];
    @file_put_contents($path, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return ['cached' => false] + $out;
}

/** Whether this world-hour's audit is already on file — free to show. */
function xeric_play_narrator_audit_cached(array $w): bool
{
    $now  = xeric_clock_now($w['db'], $w['template']);
    $kept = json_decode((string)@file_get_contents(xeric_play_narrator_audit_path($w)), true);
    return is_array($kept) && (int)($kept['hour'] ?? PHP_INT_MIN) === intdiv((int)($now['epoch'] ?? 0), 3600);
}

/** Every cite as a link into the inspector (events, heads, threads) or the pane (intents). */
function xeric_play_narrator_cites_html(array $T, array $cites, string $slug): string
{
    $base  = 'why.php?w=' . rawurlencode($slug);
    $links = [];
    foreach ((array)($cites['events'] ?? []) as $id) {
        $links[] = '<a href="' . h($base . '&e=' . (int)$id) . '">event #' . (int)$id . '</a>';
    }
    $heads = [];
    foreach ((array)($cites['arcs'] ?? []) as $a) $heads[(string)strtok((string)$a, ':')] = 'ledger';
    foreach ((array)($cites['deaths'] ?? []) as $hh) $heads[(string)$hh] = 'death';
    if (($cites['memories'] ?? []) !== []) {
        $links[] = h(count((array)$cites['memories']) . ' memor' . (count((array)$cites['memories']) === 1 ? 'y' : 'ies')
            . ' #' . implode(', #', array_map('intval', (array)$cites['memories'])));
    }
    if (($cites['messages'] ?? []) !== []) {
        $links[] = h('message #' . implode(', #', array_map('intval', (array)$cites['messages'])));
    }
    foreach ($heads as $hh => $what) {
        if ($hh === '' || xeric_world_character($T, $hh) === null) continue;
        $links[] = '<a href="' . h($base . '&h=' . rawurlencode($hh)) . '">' . h(xeric_world_name($T, $hh)) . '</a>';
    }
    foreach ((array)($cites['intents'] ?? []) as $n) {
        $links[] = '<a href="' . h('play.php?w=' . rawurlencode($slug)) . '">intent #' . (int)$n . '</a>';
    }
    return $links === [] ? '' : ' <span class="quiet">· ' . implode(' · ', $links) . '</span>';
}

/** The audit, grouped by kind, as the inspector's list. */
function xeric_play_narrator_audit_html(array $T, array $audit, string $slug): string
{
    $label = [
        'unspoken'         => 'nobody has heard from them',
        'dropped_question' => 'questions left hanging',
        'unpaid_debt'      => 'debts nobody collected',
        'faded_intent'     => 'beats the world never found room for',
        'idle_pressure'    => 'a pressure that pressed nothing',
        'never_lived'      => 'seeded, never lived',
        'contradiction'    => 'contradictions in the record',
    ];
    $obs = (array)($audit['observations'] ?? []);
    if ($obs === []) {
        return '<p class="quiet">Nothing to report: no dropped thread, nobody gone quiet, no contradiction '
             . 'in the record this hour.</p>';
    }
    $by = [];
    foreach ($obs as $o) $by[(string)$o['kind']][] = $o;
    $out = '';
    foreach (XERIC_NARRATOR_KINDS as $k) {
        if (empty($by[$k])) continue;
        $out .= '<h3>' . h($label[$k] ?? $k) . ' <span class="quiet">(' . count($by[$k]) . ')</span></h3><ul class="sys">';
        foreach ($by[$k] as $o) {
            $out .= '<li class="audit ' . h($k) . '"><span class="v">' . h((string)$o['text'])
                  . ((string)($o['found_by'] ?? '') === 'model' ? ' <span class="quiet">(read by the model)</span>' : '')
                  . xeric_play_narrator_cites_html($T, (array)($o['cites'] ?? []), $slug) . '</span></li>';
        }
        $out .= '</ul>';
    }
    return $out;
}
