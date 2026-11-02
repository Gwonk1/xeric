<?php
/**
 * play-book.php — the book: the lived record grouped into world days (book.php).
 *
 * PART OF play-lib.php, moved out of it whole: every function here kept its
 * name, signature and body. play-lib.php require_once's this file, so every
 * page that requires play-lib.php has it; requiring this file alone pulls
 * play-lib.php in as well (the two include each other once). The map of which
 * part lives where is at the top of play-lib.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

// ---------------------------------------------------------------------------
// The book — what book.php reads
// ---------------------------------------------------------------------------
//
// The world writing its own novel: the lived record grouped into world days,
// newest day first, each day holding what the USER could have seen of it —
// events as the commons prose they already are on every other screen, the
// user's own conversations reduced to scene lines, dreams when an hour was one,
// and what is owed (docs/CONSTRUCTS.md). Nothing below reads a memory or an
// interior: the book is the player's view bound in boards, and a page that
// printed what somebody privately took away from an hour would be the leak the
// walls exist to stop. When in doubt, less.
//
// Everything here is a read shaped for one page. It writes nothing, calls no
// model, and tolerates any database an older build left behind — a book that
// fell over on a column it did not recognise would be a diary that burns itself.

/** A page of the book, in days. */
const XERIC_BOOK_DAYS = 7;

/** The most days one URL may ask for — months of history is a shelf, not a page. */
const XERIC_BOOK_DAYS_MAX = 31;

/** The world's own timezone, with the same quiet fallback every formatter takes. */
function xeric_book_tz(array $t): DateTimeZone
{
    try { return new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { return new DateTimeZone('UTC'); }
}

/**
 * "Thursday, the 14th of March" — a chapter heading, never a timestamp.
 *
 * No year on purpose: the era's year is a fact about the world (the sidebar's
 * calendar prints it once, via xeric_play_era_year), and a novel does not date
 * its chapters. The weekday and the month are the real clock's, so this heading
 * and every schedule in the world agree.
 */
function xeric_book_heading(array $t, int $epoch): string
{
    $d = (new DateTimeImmutable('@' . $epoch))->setTimezone(xeric_book_tz($t));
    return $d->format('l') . ', the ' . $d->format('jS') . ' of ' . $d->format('F');
}

/** "15:04" in the world's timezone — the hour a line of the day carries. */
function xeric_book_hour(array $t, int $epoch): string
{
    return (new DateTimeImmutable('@' . $epoch))->setTimezone(xeric_book_tz($t))->format('H:i');
}

/**
 * Which days one URL asks for, newest first, and where the page turns are.
 *
 * ?from=YYYY-MM-DD names the NEWEST day on the page and ?days= how far it reads
 * back; absent, the page is the last XERIC_BOOK_DAYS lived days ending today.
 * A `from` in the future is today — the book prints what has been lived, and
 * asking it for next week is asking it to make something up.
 *
 * `earlier` and `later` are `from` values for the neighbouring pages, or '' at
 * either cover. Earlier is only offered while there is still material back
 * there to read (the earliest stamped event or message), so the pager cannot
 * walk somebody into a century of blank days one week at a time.
 *
 * @return array{days:array<int,array{date:string,start:int,end:int}>,
 *               today:string,from:string,n:int,earlier:string,later:string}
 */
function xeric_book_range(array $t, PDO $db, array $now, string $from = '', int $days = 0): array
{
    $tz    = xeric_book_tz($t);
    $today = (new DateTimeImmutable('@' . (int)($now['epoch'] ?? 0)))->setTimezone($tz)->setTime(0, 0);
    $n     = $days > 0 ? min($days, XERIC_BOOK_DAYS_MAX) : XERIC_BOOK_DAYS;

    $top = $today;
    if ($from !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        if ($d instanceof DateTimeImmutable && $d < $today) $top = $d;
    }

    $list = [];
    $cur  = $top;
    for ($i = 0; $i < $n; $i++) {
        // The bounds are DATES, not midnight plus 86400: on the two nights a
        // year the offset moves, a day is 23 or 25 hours long, and an hour of
        // somebody's evening falling between two chapters is the kind of bug a
        // reader notices before a test does.
        $list[] = ['date' => $cur->format('Y-m-d'), 'start' => $cur->getTimestamp(),
                   'end' => $cur->modify('+1 day')->getTimestamp()];
        $cur = $cur->modify('-1 day');
    }

    // The earliest thing anybody wrote down, so the pager knows where the story
    // actually begins. Both stamped tables, tolerating either being empty.
    $first = null;
    foreach (['SELECT MIN(world_epoch) m FROM events',
              'SELECT MIN(world_epoch) m FROM messages WHERE world_epoch IS NOT NULL'] as $q) {
        try { $v = $db->query($q)->fetchAll()[0]['m'] ?? null; } catch (Throwable $e) { $v = null; }
        if ($v !== null && $v !== '' && ($first === null || (int)$v < $first)) $first = (int)$v;
    }

    $oldest  = $list[count($list) - 1];
    $earlier = ($first !== null && $first < $oldest['start'])
        ? (new DateTimeImmutable('@' . $oldest['start']))->setTimezone($tz)->modify('-1 day')->format('Y-m-d')
        : '';
    $later = '';
    if ($top < $today) {
        $next  = $top->modify('+' . $n . ' days');
        $later = ($next > $today ? $today : $next)->format('Y-m-d');
    }

    return ['days' => $list, 'today' => $today->format('Y-m-d'), 'from' => $top->format('Y-m-d'),
            'n' => $n, 'earlier' => $earlier, 'later' => $later];
}

/**
 * Everything that happened between two moments, oldest first — a chapter reads
 * forward even though the book reads back. Same decode as xeric_events_recent,
 * bounded by the day instead of by a count, which is what keeps a world with
 * months behind it from arriving as megabytes.
 */
function xeric_book_events_between(PDO $db, int $a, int $b): array
{
    $st = $db->prepare('SELECT * FROM events WHERE world_epoch >= ? AND world_epoch < ? ORDER BY world_epoch, id');
    $st->execute([$a, $b]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['participants'] = $r['participants'] !== null ? (json_decode((string)$r['participants'], true) ?: []) : [];
        $r['on_spine'] = (int)($r['on_spine'] ?? 0) === 1;
        // An hour written above this viewer's rating is in the book as an hour
        // and nothing of what happened in it (xeric_state_event_shown).
        $r = xeric_state_event_shown($r);
    }
    return $rows;
}

/**
 * What kind of hour the sweep called an event, read off the decision trail
 * why.php keeps. '' for seed history (nothing chose it) and for a trail that
 * does not say. The book only asks so a dream can be set in its own register —
 * the trail's REASONING stays on the inspector, where the owner reads it as an
 * owner rather than as a reader.
 */
function xeric_book_event_kind(PDO $db, int $eventId): string
{
    $raw = xeric_world_state_get($db, 'why:event:' . $eventId);
    if ($raw === null) return '';
    $why = json_decode($raw, true);
    return is_array($why) ? (string)($why['kind'] ?? '') : '';
}

/**
 * The user's conversations in a window, reduced to scene lines: who, and when.
 *
 * A BOOK IS NOT A CHAT LOG. The transcripts are the threads' to keep and the
 * player has read them once already; what the day's page owes is the fact that
 * a scene happened — "you and Marta talked, ten to eleven" — which is exactly
 * what a novel keeps of most conversations. So this counts lines and keeps
 * hours and returns not one sentence of what was said.
 *
 * The narrator's thread is not a scene: asking the machine where things stand
 * is reading the book, not living in the world, and a book that recorded its
 * own being read would never end.
 *
 * @return array<int,array{handle:string,name:string,first:int,last:int,yours:int,theirs:int}>
 */
function xeric_book_scenes(array $t, PDO $db, int $a, int $b): array
{
    $st = $db->prepare('SELECT c.handle AS handle, m.role AS role, m.world_epoch AS we
                          FROM messages m JOIN conversations c ON c.id = m.conversation_id
                         WHERE m.world_epoch >= ? AND m.world_epoch < ?
                         ORDER BY m.world_epoch, m.id');
    $st->execute([$a, $b]);
    $rows = $st->fetchAll();

    $by = [];
    foreach ($rows as $r) {
        $h = (string)$r['handle'];
        if ($h === '' || $h === XERIC_NARRATOR) continue;
        $we = (int)$r['we'];
        if (!isset($by[$h])) {
            $n = xeric_world_name($t, $h);
            $by[$h] = ['handle' => $h, 'name' => $n !== '' ? $n : $h,
                       'first' => $we, 'last' => $we, 'yours' => 0, 'theirs' => 0];
        }
        $by[$h]['first'] = min($by[$h]['first'], $we);
        $by[$h]['last']  = max($by[$h]['last'], $we);
        $role = (string)$r['role'];
        // 'character' is the schema's word and 'assistant' an older build's; a
        // narrator line inside a thread is the world talking, which is neither
        // side of the scene and counts for nobody.
        if ($role === 'user') $by[$h]['yours']++;
        elseif ($role !== 'narrator') $by[$h]['theirs']++;
    }

    // A window that only ever heard the world's own voice held no scene at all.
    return array_values(array_filter($by, fn(array $s): bool => $s['yours'] + $s['theirs'] > 0));
}

/**
 * One expectation arc's value, believed only as far as it parses.
 *
 * The first-class shape is engine/constructs.php's, which landed the same day
 * this page did: {what, quote, when_said, due, formed, state} with state one of
 * open | missed | repaired | hardened (xeric_expect_form / _tick / _repair).
 * The fallbacks under it are deliberate slack — a sibling construct, an older
 * db, or a schema that moved gets read as far as it is honest to and SKIPPED
 * past that, because a book that guessed at what somebody owes somebody would
 * be worse than one page short.
 *
 * @return array{what:string,quote:string,due:?int,status:string}|null
 *         null = not renderable; status is open|missed|repaired|hardened
 */
function xeric_book_expect_parse(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') return null;

    $v = json_decode($raw, true);
    if (is_string($v)) $v = ['what' => $v];
    if (!is_array($v)) {
        // Not JSON. A sentence is a usable coarse state on its own; a bare
        // number or a lone word is a counter wearing the wrong key prefix.
        if (preg_match('/[a-z]/i', $raw) !== 1 || !str_contains($raw, ' ')) return null;
        $v = ['what' => $raw];
    }

    $what = '';
    foreach (['what', 'text', 'promise', 'line', 'said', 'coarse'] as $k) {
        if (is_string($v[$k] ?? null) && trim((string)$v[$k]) !== '') { $what = trim((string)$v[$k]); break; }
    }
    $quote = is_string($v['quote'] ?? null) ? trim((string)$v['quote']) : '';
    if ($what === '' && $quote === '') return null;

    $due = null;
    foreach (['due', 'due_epoch', 'when', 'by', 'epoch', 'at'] as $k) {
        if (is_numeric($v[$k] ?? null) && (int)$v[$k] > 0) { $due = (int)$v[$k]; break; }
    }

    $s = strtolower(trim((string)($v['state'] ?? $v['status'] ?? '')));
    if ($s === '') {
        if (!empty($v['missed'])) $s = 'missed';
        elseif (!empty($v['kept']) || !empty($v['met'])) $s = 'kept';
    }
    if (in_array($s, ['open', 'missed', 'repaired', 'hardened'], true)) {
        $status = $s;
    } elseif (str_contains($s, 'miss') || str_contains($s, 'broke')) {
        $status = 'missed';
    } elseif (str_contains($s, 'repair') || str_contains($s, 'explain') || str_contains($s, 'mend')) {
        $status = 'repaired';
    } elseif (str_contains($s, 'kept') || str_contains($s, 'met') || str_contains($s, 'done')
        || str_contains($s, 'closed') || str_contains($s, 'honour') || str_contains($s, 'honor')) {
        // A kept promise is an ordinary memory now — not the ledger's to print.
        return null;
    } elseif ($s === '') {
        $status = 'open';
    } else {
        // A state this page has never heard of: render it only if it is still
        // plainly ahead of us, and let a past one go unclaimed rather than
        // called a miss on a guess.
        if ($due === null) return null;
        $status = 'open';
    }

    return ['what' => $what, 'quote' => $quote, 'due' => $due, 'status' => $status];
}

/**
 * Every expectation this world is carrying that the book can honestly print.
 *
 * Read across all handles because the arc lives in the LISTENER's row (the
 * person expecting you — engine/constructs.php, v1 scope: they all point at
 * the user). A database from before the construct existed has no rows and no
 * opinion, which is the whole defensive posture: zero arcs, a schema that
 * differs, a value that will not parse — each is a quiet absence, never an
 * error on a page.
 *
 * @return array<int,array{handle:string,key:string,what:string,quote:string,due:?int,status:string}>
 */
function xeric_book_expectations(PDO $db): array
{
    try {
        $st = $db->prepare("SELECT handle, key, value FROM arcs WHERE key LIKE 'expect.%' ORDER BY handle, key");
        $st->execute();
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $p = xeric_book_expect_parse((string)$r['value']);
        if ($p === null) continue;
        $out[] = ['handle' => (string)$r['handle'], 'key' => (string)$r['key']] + $p;
    }
    return $out;
}

/**
 * The sentence the book prints for one expectation — the reader's own promise,
 * in the second person, because the ledger's v1 scope is promises made TO the
 * cast BY the person reading this page. The verbatim quote wins when the arc
 * kept one ("You told Thi: 'I'll be at the market Saturday morning'"); the
 * label stands in when it did not; a foreign shape falls back to whatever
 * sentence it carried.
 */
function xeric_book_expect_line(array $t, array $x): string
{
    $name  = xeric_world_name($t, (string)($x['handle'] ?? ''));
    $quote = (string)($x['quote'] ?? '');
    $what  = (string)($x['what'] ?? '');
    if ($name !== '' && $quote !== '') return 'You told ' . $name . ': "' . $quote . '"';
    if ($name !== '' && $what !== '')  return 'You told ' . $name . ' — ' . $what . '.';
    return $what !== '' ? $what : ('"' . $quote . '"');
}

/**
 * The page, assembled: days newest first, each day's material oldest first.
 *
 * A day earns its heading by holding something — events, scenes, a dream, a
 * promise that broke that day — and days that hold nothing are simply not
 * chapters, because seven headings over seven silences is a calendar, not a
 * book. The one exception is today, which is kept whenever promises stand,
 * since "what is owed" belongs on the current page (docs/CONSTRUCTS.md) even
 * when nothing has happened yet this morning.
 *
 * Dreams are events the sweep filed as `dream` hours, pulled into their own
 * register. The proactive dream rung produces that kind since 2026-08-02 (the ladder weight in
 * proactive.pings is authored but unconsumed), so the register renders empty —
 * which is an absence, not a fault, and the day the engine dreams its first
 * dream this page prints it without being edited.
 *
 * @return array{days:array,pager:array{today:string,from:string,n:int,earlier:string,later:string}}
 */
function xeric_book_days(array $t, PDO $db, array $now, string $from = '', int $days = 0): array
{
    $range = xeric_book_range($t, $db, $now, $from, $days);

    $open = $missed = [];
    foreach (xeric_book_expectations($db) as $x) {
        if ($x['status'] === 'open') $open[] = $x;
        elseif ($x['status'] === 'missed' && $x['due'] !== null) $missed[] = $x;
        // A miss with no due epoch has no day to land on, and a kept promise is
        // an ordinary memory now — neither is the book's to place.
    }

    $out = [];
    foreach ($range['days'] as $day) {
        $items = [];
        foreach (xeric_book_events_between($db, $day['start'], $day['end']) as $e) {
            $kind = xeric_book_event_kind($db, (int)$e['id']);
            // A ROOM YOU WERE IN is a scene of yours, not the town's hour: the
            // book says it the way it says a thread — who, where, when, how
            // much — and keeps not one line of what was said.
            if ($kind === 'room') {
                $why = json_decode((string)(xeric_world_state_get($db, 'why:event:' . (int)$e['id']) ?? ''), true);
                if (is_array($why) && !empty($why['live'])) {
                    $items[] = ['kind' => 'room', 'epoch' => (int)$e['world_epoch'], 'event' => $e,
                                'lines' => (int)($why['turns'] ?? 0) + (int)($why['said'] ?? 0)];
                    continue;
                }
            }
            $items[] = ['kind' => $kind === 'dream' ? 'dream' : 'event',
                        'epoch' => (int)$e['world_epoch'], 'event' => $e];
        }
        foreach (xeric_book_scenes($t, $db, $day['start'], $day['end']) as $s) {
            $items[] = ['kind' => 'scene', 'epoch' => (int)$s['first'], 'scene' => $s];
        }
        foreach ($missed as $x) {
            if ($x['due'] >= $day['start'] && $x['due'] < $day['end']) {
                $items[] = ['kind' => 'miss', 'epoch' => (int)$x['due'], 'expect' => $x];
            }
        }
        usort($items, fn(array $a, array $b): int => [$a['epoch'], $a['kind']] <=> [$b['epoch'], $b['kind']]);

        $promises = $day['date'] === $range['today'] ? $open : [];
        if ($items === [] && $promises === []) continue;

        $out[] = ['date' => $day['date'], 'label' => xeric_book_heading($t, $day['start']),
                  'start' => $day['start'], 'end' => $day['end'],
                  'items' => $items, 'promises' => $promises];
    }

    return ['days' => $out, 'pager' => ['today' => $range['today'], 'from' => $range['from'],
                                        'n' => $range['n'], 'earlier' => $range['earlier'],
                                        'later' => $range['later']]];
}
