<?php
/**
 * notify.php — telling somebody who is not looking at the screen.
 *
 * ntfy, because it is the smallest thing that works: a POST to a URL with the
 * message as the body. No account, no SDK, no key, no callback, and it reaches a
 * phone. `https://ntfy.sh/<something-nobody-will-guess>` is a complete
 * configuration, and a self-hosted ntfy is the same URL with a different host —
 * which matters for a project whose whole claim is that it runs on your machine.
 *
 * THREE THINGS ARE WORTH KNOWING BEFORE ADDING A TRIGGER.
 *
 *  1. A NOTIFICATION IS NOT A LOG LINE. Every one of these interrupts somebody
 *     who is doing something else, and the fastest way to make a world stop
 *     mattering is to have it buzz about an ordinary Tuesday. Nothing here fires
 *     by default; every trigger is named, off, and switched on one at a time.
 *
 *  2. IT MUST NEVER COST A TURN. A dead ntfy host, a wrong URL, a phone on a
 *     plane — none of those may fail a chat turn or roll back a sweep. Every
 *     call is short-timeout, best-effort, and swallowed. The world happened;
 *     whether the phone heard about it is a separate and lesser question.
 *
 *  3. IT LEAVES THE MACHINE. This is the one part of Xeric that talks to a
 *     third party during ordinary use, so what goes out is deliberately thin —
 *     a world's name, an hour's title, a reminder in the words it was asked for.
 *     No prose, no memories, no cast dossiers, and never anything a wall is
 *     holding. A notification body is COMMONS TEXT, held to the same rule as a
 *     place description.
 */

declare(strict_types=1);

/** Everything that can ring a phone. Nothing here is on unless it is listed. */
function xeric_notify_kinds(): array
{
    return [
        'reminder' => 'you asked somebody to remind you',
        'tokens'   => 'the meter passed another mark',
        'ping'     => 'somebody in a xeric reached for their phone first',
        'death'    => 'a xeric lost somebody',
        'spine'    => 'an hour that was about the thing the xeric is keeping quiet',
        'story'    => 'a story opened, broke, or resolved',
        'hour'     => 'any hour at all, loud, and here for completeness',
    ];
}

/**
 * Is this worth ringing a phone about?
 *
 * @param array $cfg ['url' => …, 'on' => ['reminder','death',…]]
 */
function xeric_notify_on(array $cfg, string $kind): bool
{
    $url = trim((string)($cfg['url'] ?? ''));
    if ($url === '') return false;

    $on = array_map('strval', (array)($cfg['on'] ?? []));
    return in_array($kind, $on, true);
}

/**
 * What an hour is allowed to say to a phone.
 *
 * THE RULE IS CODE, NOT CALLER DISCIPLINE. Rule 3 above says a body is commons
 * text and nothing a wall is holding — and for one evening heart.php shipped
 * raw spine titles under the 'spine' trigger, the one kind of title written to
 * circle the thing the xeric keeps quiet, to a third-party push host. The room
 * block will not read an on_spine title to the cast; a push host is not a
 * better audience. So the decision of what leaves lives HERE, where the rule
 * does: a spine hour says THAT it happened, in a fixed sentence, and an
 * ordinary title — commons by construction — rides as itself.
 */
function xeric_notify_hour_body(array $e): string
{
    if (!empty($e['on_spine'])) return 'Something happened close to the heart of it.';
    $ti = trim((string)($e['title'] ?? ''));
    return $ti !== '' ? $ti : 'something happened';
}

/**
 * What a ping is allowed to say to a phone: the doorbell, not the letter.
 *
 * A ping's text is a model-written private message — rating-shaped, sometimes
 * intimate, addressed to the player and not to a lock screen. The name and the
 * fact of it is everything a pocket needs; the message waits where it belongs.
 */
function xeric_notify_ping_body(array $ping): string
{
    $name = trim((string)($ping['name'] ?? ''));
    return ($name !== '' ? $name : 'Somebody') . ' texted you.';
}

/**
 * Send one. Returns whether it went, and nobody has to care.
 *
 * SHORT TIMEOUT AND SWALLOWED. A caller that waited on this would hand a chat
 * turn's latency to a third party, and a caller that let it throw would let a
 * phone being unreachable roll back an hour that already happened.
 *
 * @param array $opts title, tags (ntfy emoji shortcodes), priority 1–5, click url
 */
function xeric_notify_send(array $cfg, string $body, array $opts = []): bool
{
    $url = trim((string)($cfg['url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) return false;

    $body = trim(mb_substr(trim($body), 0, 900));
    if ($body === '') return false;

    $headers = ['Content-Type: text/plain; charset=utf-8'];
    // ntfy takes its metadata as headers, and every one of them must be a single
    // line: a newline in a title is a header injection into somebody's own
    // notification server.
    $one = fn(string $s): string => trim((string)preg_replace('/[\r\n]+/', ' ', $s));

    $title = $one((string)($opts['title'] ?? ''));
    if ($title !== '') $headers[] = 'Title: ' . mb_substr($title, 0, 120);

    $tags = $one((string)($opts['tags'] ?? ''));
    if ($tags !== '') $headers[] = 'Tags: ' . mb_substr($tags, 0, 80);

    $pri = (int)($opts['priority'] ?? 0);
    if ($pri >= 1 && $pri <= 5) $headers[] = 'Priority: ' . $pri;

    $click = $one((string)($opts['click'] ?? ''));
    if ($click !== '' && preg_match('#^https?://#i', $click)) $headers[] = 'Click: ' . $click;

    $ctx = stream_context_create(['http' => [
        'method'          => 'POST',
        'header'          => implode("\r\n", $headers),
        'content'         => $body,
        'timeout'         => (int)($opts['timeout'] ?? 4),
        'ignore_errors'   => true,
        'follow_location' => 0,
    ]]);

    $out = @file_get_contents($url, false, $ctx);
    return $out !== false;
}

// ---------------------------------------------------------------------------
// The meter mark
// ---------------------------------------------------------------------------

/**
 * Has the meter passed another mark since it last said so?
 *
 * A WATERMARK, NOT A COMPARISON. The obvious build is "notify when total >
 * threshold", which fires on every single completion once the total is past it.
 * What a person wants is one buzz per hundred thousand, so what is stored is the
 * last mark ALREADY ANNOUNCED and the question is whether the total has reached
 * the next one.
 *
 * @param int $every the size of a mark, 0 to never
 * @param int $said  the last mark announced
 * @return int the mark to announce now, or 0 for nothing
 */
function xeric_notify_mark(int $total, int $every, int $said): int
{
    if ($every <= 0 || $total <= 0) return 0;

    $mark = intdiv($total, $every) * $every;
    return $mark > $said ? $mark : 0;
}

/** 100000 → "100k". The number on a notification is read at a glance or not at all. */
function xeric_notify_round(int $n): string
{
    if ($n < 1000)    return (string)$n;
    if ($n < 1000000) return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.') . 'k';
    return rtrim(rtrim(number_format($n / 1000000, 2, '.', ''), '0'), '.') . 'M';
}

// ---------------------------------------------------------------------------
// "remind me"
// ---------------------------------------------------------------------------

/**
 * Does this sound like somebody asking to be reminded?
 *
 * A CHEAP GATE IN FRONT OF AN EXPENSIVE READER. Working out WHEN "a week on
 * Thursday" is takes a model; working out whether a sentence is asking for a
 * reminder at all does not, and running the model on every line anybody ever
 * types would double the cost of a conversation to catch something that happens
 * once a week.
 *
 * Deliberately loose. A false positive costs one small call and returns null; a
 * false negative silently loses somebody's reminder, which is the failure that
 * makes a feature untrustworthy.
 */
function xeric_remind_asked(string $text): bool
{
    $s = mb_strtolower($text);
    foreach (['remind me', 'remind us', 'reminder', 'don\'t let me forget', 'dont let me forget',
              'do not let me forget', 'make sure i', 'nudge me', 'tell me when', 'ping me',
              'wake me', 'let me know when'] as $cue) {
        if (str_contains($s, $cue)) return true;
    }
    return false;
}

/**
 * The prompt that turns a sentence into a time and a thing.
 *
 * REAL TIME, NOT WORLD TIME, and this is the one place in the engine where that
 * is true. Every other clock here belongs to the world — but a reminder is for
 * the person, and a person who asked to be reminded on Thursday means the
 * Thursday they are going to live through, not the one the world's offset
 * happens to be pointing at.
 */
function xeric_remind_prompt(string $said, string $nowIso): array
{
    return [
        ['role' => 'system', 'content' =>
            'You extract reminders. Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "It is now $nowIso.\n\nSomebody said: \"$said\"\n\n"
            . "If they are asking to be reminded of something, return:\n"
            . '{"is_reminder": true, "what": "the thing, in their words, under 12 words", '
            . '"in_minutes": 0}' . "\n"
            . "in_minutes is how long from NOW, as a whole number. \"tomorrow morning\" is the "
            . "next 09:00. \"in an hour\" is 60. A day is 1440. If they named no time at all, "
            . "use 1440.\n"
            . "If they are not asking for a reminder, return {\"is_reminder\": false}.\n"
            . 'No prose outside the JSON.'],
    ];
}

/** The extracted object, cleaned into something storable. null when it is not one. */
function xeric_remind_clean(array $raw, int $now): ?array
{
    if (empty($raw['is_reminder'])) return null;

    $what = trim((string)($raw['what'] ?? ''));
    if ($what === '') return null;

    // Clamped to a year. A model that returns 525600000 because it read "in a
    // minute" as milliseconds should not book a reminder for the year 3000.
    $in = (int)($raw['in_minutes'] ?? 0);
    $in = max(1, min(60 * 24 * 365, $in));

    return ['what' => mb_substr($what, 0, 200), 'at' => $now + $in * 60, 'in_minutes' => $in];
}

/** "in 40 minutes" · "in 3 hours" · "on Thursday" — how a confirmation reads. */
function xeric_remind_when(int $minutes, int $at, string $tz = 'UTC'): string
{
    if ($minutes < 90) return 'in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    if ($minutes < 60 * 20) {
        $h = (int)round($minutes / 60);
        return 'in ' . $h . ' hour' . ($h === 1 ? '' : 's');
    }
    try { $d = (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone($tz)); }
    catch (Throwable $e) { $d = new DateTimeImmutable('@' . $at); }

    return 'on ' . $d->format('l') . ' at ' . $d->format('H:i');
}
