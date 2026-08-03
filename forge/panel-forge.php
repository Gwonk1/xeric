<?php
/**
 * Xeric — building a panel out of a problem. EXPERIMENTAL.
 *
 * The ordinary forge asks twenty questions about a place and the people in it.
 * This asks one: what is the problem. Then it makes a room to hold it and
 * three to five people who will not agree about it, and hands the whole thing
 * to the same engine that runs everything else — so the panel is watchable
 * (watch.php), interruptible (walk in and say something), and remembers what
 * was said, because rooms already do all of that.
 *
 * ── THE HARD PART IS MAKING THEM ACTUALLY DISAGREE ────────────────────────
 *
 * A model asked for "five conflicting experts" produces five reasonable
 * people who are all correct in slightly different ways and who converge by
 * the third exchange. That is not a prompting problem to be solved with a
 * firmer adjective, it is what the objective does: agreeable text is likelier
 * text. So the disagreement is made STRUCTURAL rather than requested.
 *
 * Every expert declares one RED LINE — a sentence naming what they will not
 * accept. Not a value, not a priority: a refusal, with a subject. And the
 * builder rejects a panel whose red lines overlap (engine/panel.php's
 * xeric_panel_check), because two people refusing the same thing are one
 * person twice, and a panel of one person always reaches consensus.
 *
 * WHAT THE SETTING IS FOR. A conference room, a ski lodge, a bar at closing:
 * the place is not decoration. People argue differently standing up than
 * sitting down, and differently again at two in the morning with a drink in
 * front of them, and the model writes them differently because the room is in
 * the prompt. The forge picks the room from the problem — a redundancy plan
 * gets a conference table, a marriage gets a kitchen.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/forge.php';
require_once __DIR__ . '/../engine/panel.php';

/** How many times the builder will re-ask for a panel that actually disagrees. */
const XERIC_PANEL_TRIES = 3;

/**
 * A problem in, a whole world out.
 *
 * One model call, retried while the panel it wrote agrees with itself. The
 * retry carries the reason back, which is the only feedback that reliably
 * moves a model off consensus: not "try harder", but "these two of yours
 * refuse the same thing, here are their sentences."
 */
function xeric_forge_panel(string $problem, array $endpoint, array $opts = [],
                           ?callable $onNote = null): array
{
    $note    = $onNote ?? static function (string $s): void {};
    $problem = trim($problem);
    if ($problem === '') throw new RuntimeException('panel: there is no problem to put in the room');

    $sys = 'You build a room and the people who will argue in it. You are not solving the '
         . 'problem and you are not writing a story: you are casting a disagreement. Reply with '
         . 'ONE JSON object and nothing else.';

    $again = '';
    $raw   = null;
    for ($try = 0; $try < XERIC_PANEL_TRIES; $try++) {
        $user = "THE PROBLEM\n" . mb_substr($problem, 0, 4000) . "\n\n"
            . "Make a room to hold it and three to five people who will not agree about it.\n\n"
            . "THE ROOM. Somewhere this argument would really happen: a conference room, a ski\n"
            . "lodge after dinner, a hospital corridor, a bar at closing, a kitchen table. Pick\n"
            . "from the problem, not from a list — people argue differently sitting down than\n"
            . "standing up, and the room is in the prompt.\n\n"
            . "THE PEOPLE. Each one is competent and each one is RIGHT ABOUT SOMETHING. None of\n"
            . "them is the fool who exists to be corrected. They disagree because they are\n"
            . "protecting different things, not because one of them is stupid.\n\n"
            . "THE RED LINE is the important field. One sentence naming what that person will\n"
            . "NOT ACCEPT, with a subject: \"I will not accept anything that puts the cost on\n"
            . "people who did not choose it.\" Not a value, not a priority, not a preference — a\n"
            . "refusal you could hold a proposal up against and answer yes or no.\n\n"
            . "EVERY RED LINE MUST REFUSE A DIFFERENT THING. If two of them could be satisfied by\n"
            . "the same concession they are one person twice, and a panel of one person always\n"
            . "agrees with itself.\n"
            . $again
            . "\nReply exactly:\n{\n"
            . '  "question": "the one question the room is in there to answer, under 20 words",' . "\n"
            . '  "room": { "name": "the Ridgeline lodge, back room", "what": "one line about the place and the hour" },' . "\n"
            . '  "people": [ { "name": "full name", "was": "what they do, six words",' . "\n"
            . '                "how": "how they argue, one line — loud, slow, by asking questions",' . "\n"
            . '                "stake": "what they are protecting, one line",' . "\n"
            . '                "red_line": "I will not accept ..." } ]' . "\n}";

        $raw = xeric_chat_json($endpoint, 'panel-forge', [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.95, 'timeout' => (int)($opts['timeout'] ?? 180)] + $opts);

        $t = xeric_forge_panel_world($problem, is_array($raw) ? $raw : []);
        $bad = xeric_panel_check($t);
        if ($bad === []) { $note('the room disagrees with itself, which is the point'); return $t; }

        $note('that panel agreed with itself — ' . $bad[0]);
        $again = "\nYOUR LAST ATTEMPT FAILED: " . implode('; ', $bad)
               . ". Give those people genuinely different refusals or replace them.\n";
    }

    // Three tries and they still agree. This is reported rather than papered
    // over: a panel that secretly agrees runs fine, reads fine, and returns a
    // consensus that means nothing, which is the worst failure this can have.
    throw new RuntimeException('panel: could not cast a room that actually disagrees about this — '
        . 'the problem may already be settled, or it may need to be asked more sharply');
}

/**
 * The model's object → a world the ordinary engine can run.
 *
 * Everything the model got wrong is dropped, never stored: a person with no
 * refusal is not on the panel, a sixth person is not seated, and a room with
 * no name gets one. What comes out here is a normal Xeric template — cast,
 * places, meta — so nothing downstream needs to know this room was built from
 * a question rather than from an interview.
 */
function xeric_forge_panel_world(string $problem, array $raw): array
{
    $roomName = trim((string)($raw['room']['name'] ?? '')) ?: 'the back room';
    $roomWhat = trim((string)($raw['room']['what'] ?? '')) ?: 'A table, and nowhere else to be.';
    $question = trim((string)($raw['question'] ?? ''));

    $chars = [];
    $experts = [];
    $used = [];
    foreach ((array)($raw['people'] ?? []) as $p) {
        if (!is_array($p) || count($chars) >= XERIC_PANEL_MAX) continue;
        $name = trim((string)($p['name'] ?? ''));
        $red  = trim((string)($p['red_line'] ?? ''));
        if ($name === '' || $red === '') continue;

        $h = preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?? '';
        $h = trim((string)$h, '_');
        if ($h === '' || isset($used[$h])) continue;
        $used[$h] = true;

        $chars[] = [
            'handle'       => $h,
            'display_name' => $name,
            'age'          => 44,          // adults, all of them: a panel is not a place for a child
            'one_line'     => trim((string)($p['was'] ?? '')) ?: 'in the room because they know something',
            'voice'        => ['style' => trim((string)($p['how'] ?? '')) ?: 'plain, and does not hedge'],
            'orbit'        => 'the_room',
            'arcs'         => [],
        ];
        $experts[] = ['handle' => $h, 'red_line' => $red,
                      'stake' => trim((string)($p['stake'] ?? ''))];
    }

    $key = 'the_room';
    return [
        'meta' => [
            'name'        => $roomName,
            'description' => $question !== '' ? $question : mb_substr($problem, 0, 200),
            'rating'      => 'sfw',
            // MARKED, and marked in the template rather than only in the UI, so
            // anything that reads a world knows what it is holding.
            'experimental' => 'discussion',
        ],
        'setting' => ['locale' => $roomName, 'era' => 'now'],
        'places'  => [[
            'key' => $key, 'name' => $roomName, 'kind' => 'room',
            'what' => $roomWhat, 'residents' => [],
        ]],
        // One orbit, because there is one room and everybody is in it. The
        // engine requires every character to declare one and it is right to:
        // an orbit is how anybody knows who could plausibly be talking to whom.
        'cast' => ['characters' => $chars, 'fixtures' => [],
                   'orbits' => [['key' => 'the_room', 'label' => 'in the room']]],
        'user' => [
            'name' => 'you', 'pronouns' => 'they/them',
            'timezone' => date_default_timezone_get() ?: 'UTC',
            'occupation' => ['title' => 'the one who asked', 'workplace_key' => null, 'hours' => 'none'],
            'motivation' => 'an answer, or an honest account of why there is not one',
        ],
        // Ambient life is switched OFF. Nobody in this room has a Tuesday, a
        // shift, or a casserole ledger — hours would fill the transcript with
        // weather while the argument waits, and a panel is one conversation,
        // not a town.
        'forge' => ['armed' => [], 'disarmed' => ['daily_rhythms', 'gossip', 'economies']],
        'events' => ['pace' => 'calm', 'sweep_chance' => 0.0, 'expected_gap_hours' => 0],
        'panel' => [
            'problem'  => mb_substr($problem, 0, 4000),
            'question' => $question,
            'experts'  => $experts,
            'room'     => $key,
        ],
    ];
}
