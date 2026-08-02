<?php
/**
 * Xeric — the learning loop. `php engine/tests/learn-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL. Same stub seam as chat-test.php and sweep-test.php, for
 * the same reason, plus one that is particular to this file: the half of the
 * learning loop that matters most is the half that works when the model is GONE,
 * and the only way to test that is to make sure it is.
 *
 * What is actually being defended here:
 *
 *   - a crumb is written down once and read once, and a second pass over the
 *     same batch changes nothing;
 *   - the arithmetic is right — reply rates, engagement per kind, reply length —
 *     because it is the layer everything else leans on;
 *   - a lesson that is a rewording of a lesson is not a second lesson;
 *   - the notebook is capped, because a notebook that grows forever eventually
 *     IS the prompt;
 *   - a kind nobody has ever followed up on is weighted DOWN and never to zero:
 *     a world that stopped doing the things you ignored would have stopped being
 *     able to surprise you, which is the only thing it was for;
 *   - somebody who is never answered reaches out less and somebody who always is
 *     reaches out more, both clamped, because neither extreme is a decision
 *     anybody made;
 *   - and a dead model still leaves the world knowing more than it did.
 */

declare(strict_types=1);

require_once __DIR__ . '/../learn.php';
require_once __DIR__ . '/../sweeps.php';
require_once __DIR__ . '/../proactive.php';

$FAILED = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) {
        echo "ok   - $name\n";
    } else {
        $FAILED++;
        echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

function ep(string $when, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();
}

const FIXTURE = __DIR__ . '/../fixtures/milldale.json';

$T   = xeric_world_load(FIXTURE);
$NOW = xeric_world_now($T, ep('2026-07-30 20:15'));       // Thursday evening

$DBFILES = [];

function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-learn-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    return xeric_state_open($path);
}

/** A model that answers with whatever it is handed. */
function stub_says($reply, array &$seen = null): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use ($reply, &$seen) {
        $seen = $msgs;
        return is_callable($reply) ? $reply($tag, $msgs) : $reply;
    }];
}

/** A model that is not there. */
function stub_dead(): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) {
        throw new RuntimeException('llm: cannot reach 127.0.0.1 (Connection refused)');
    }];
}

/** The fixture with systems armed, as a forged world would carry them. */
function world(array $armed): array
{
    $t = $GLOBALS['T'];
    $t['forge'] = ['armed' => $armed, 'disarmed' => []];
    return $t;
}

// ---------------------------------------------------------------------------
// 1. Crumbs: written once, read once
// ---------------------------------------------------------------------------

$db = fresh_db('signals');

ok('signals: every kind the engine claims to record can actually be recorded',
    (function () use ($db) {
        foreach (array_keys(xeric_learn_kinds()) as $k) {
            if (xeric_signal_add($db, $k, ['handle' => 'ruth', 'subject' => 'x']) <= 0) return false;
        }
        return xeric_signals_count($db) === count(xeric_learn_kinds());
    })());

ok('signals: a kind nobody defined is silence, not an exception',
    xeric_signal_add($db, 'vibes', ['handle' => 'ruth']) === 0
    && xeric_signals_count($db) === count(xeric_learn_kinds()));

ok('signals: they arrive unprocessed and in the order they happened',
    (function () use ($db) {
        $rows = xeric_signals_unprocessed($db, 50);
        return count($rows) === count(xeric_learn_kinds())
            && (int)$rows[0]['id'] < (int)$rows[1]['id']
            && (int)$rows[0]['processed'] === 0;
    })());

$open = xeric_signals_unprocessed($db, 2);
xeric_signals_mark($db, array_map(fn($r) => (int)$r['id'], $open));
ok('signals: marking takes exactly those crumbs out of the queue and leaves the rest',
    xeric_signals_count($db, null, true) === count(xeric_learn_kinds()) - 2
    && xeric_signals_count($db) === count(xeric_learn_kinds()),
    (string)xeric_signals_count($db, null, true));

xeric_signals_mark($db, array_map(fn($r) => (int)$r['id'], $open));
ok('signals: marking the same crumbs twice changes nothing',
    xeric_signals_count($db, null, true) === count(xeric_learn_kinds()) - 2);

ok('signals: a world with nothing in it is not due a pass, and one with crumbs is',
    !xeric_learn_due(fresh_db('due-empty')) && xeric_learn_due($db, 1));

// A crumb that has been read and is a month old is evidence nobody needs.
$stale = xeric_signal_add($db, 'reply', ['handle' => 'ruth', 'at' => xeric_state_time() - 40 * 86400]);
xeric_signals_mark($db, [$stale]);
$before = xeric_signals_count($db);
xeric_learn_prune($db);
ok('signals: read crumbs age out; unread ones never do',
    xeric_signals_count($db) === $before - 1
    && xeric_signals_count($db, null, true) === count(xeric_learn_kinds()) - 2);

// ---------------------------------------------------------------------------
// 2. The arithmetic — the layer that cannot fail
// ---------------------------------------------------------------------------

$db2 = fresh_db('tally');

// Ruth: answered four times out of five, in long messages. Dot: never answered.
foreach ([120, 80, 200, 160] as $i => $chars) {
    xeric_signal_add($db2, 'reply', ['handle' => 'ruth', 'n' => $chars, 'lag' => 300 * ($i + 1)]);
}
xeric_signal_add($db2, 'ignored', ['handle' => 'ruth']);
for ($i = 0; $i < 4; $i++) xeric_signal_add($db2, 'ignored', ['handle' => 'dot']);
xeric_signal_add($db2, 'dwell', ['handle' => 'ruth', 'n' => 2]);

/** Fold every open crumb in, and mark it — what a distil pass does, without one. */
function count_them(PDO $db): void
{
    $rows = xeric_signals_unprocessed($db, 500);
    xeric_learn_tally_apply($db, $rows);
    xeric_signals_mark($db, array_map(fn($r) => (int)$r['id'], $rows));
}

count_them($db2);

$ruth = xeric_learn_tally($db2, 'ruth');
ok('tally: replies, misses and reads are counted per person',
    $ruth['replies'] === 4 && $ruth['ignored'] === 1 && $ruth['reads'] === 1, json_encode($ruth));
ok('tally: the reply rate is the share of what was offered that got an answer',
    abs((float)$ruth['reply_rate'] - 0.8) < 0.0001, json_encode($ruth['reply_rate']));
ok('tally: average reply length is the average of the replies, not of everything',
    $ruth['avg_reply_chars'] === 140, (string)$ruth['avg_reply_chars']);
ok('tally: and how long they took, in seconds', $ruth['avg_reply_lag'] === 750, (string)$ruth['avg_reply_lag']);

$dot = xeric_learn_tally($db2, 'dot');
ok('tally: somebody who is never answered has a rate of zero, not a missing one',
    $dot['replies'] === 0 && $dot['ignored'] === 4 && $dot['reply_rate'] === 0.0,
    json_encode($dot));

$never = xeric_learn_tally($db2, 'harlan');
ok('tally: never ANSWERED and never ASKED are different facts',
    $never['reply_rate'] === null && $never['avg_reply_chars'] === null);

// Counters are cumulative, so the crumbs can be thrown away afterwards.
xeric_signal_add($db2, 'reply', ['handle' => 'ruth', 'n' => 100, 'lag' => 0]);
count_them($db2);
ok('tally: a second batch adds to the first rather than replacing it',
    xeric_learn_tally($db2, 'ruth')['replies'] === 5);

// ---------------------------------------------------------------------------
// 3. Reach — who has earned another message
// ---------------------------------------------------------------------------

ok('reach: somebody nobody has watched yet reaches out exactly as often as before',
    xeric_learn_reach($db2, 'harlan') === 1.0 && xeric_learn_reach($db2, '') === 1.0);

$reachRuth = xeric_learn_reach($db2, 'ruth');       // 5 answered, 1 not
$reachDot  = xeric_learn_reach($db2, 'dot');        // 0 answered, 4 not
ok('reach: the one who gets answered reaches out MORE',
    $reachRuth > 1.0 && $reachRuth <= XERIC_LEARN_REACH_CEIL, (string)$reachRuth);
ok('reach: the one who is left waiting reaches out LESS',
    $reachDot < 1.0 && $reachDot >= XERIC_LEARN_REACH_FLOOR, (string)$reachDot);

$dbClamp = fresh_db('clamp');
for ($i = 0; $i < 60; $i++) xeric_signal_add($dbClamp, 'reply',   ['handle' => 'ruth', 'n' => 10]);
for ($i = 0; $i < 60; $i++) xeric_signal_add($dbClamp, 'ignored', ['handle' => 'dot']);
count_them($dbClamp);
ok('reach: sixty answers in a row is still clamped — nobody becomes a stalker',
    xeric_learn_reach($dbClamp, 'ruth') === XERIC_LEARN_REACH_CEIL, (string)xeric_learn_reach($dbClamp, 'ruth'));
ok('reach: and sixty silences never switch anybody off — being ignored is not being deleted',
    xeric_learn_reach($dbClamp, 'dot') === XERIC_LEARN_REACH_FLOOR
    && XERIC_LEARN_REACH_FLOOR > 0.0, (string)xeric_learn_reach($dbClamp, 'dot'));

// And it actually reaches proactive.php: the ignored one is passed over.
$EVENT = ['id' => 0, 'title' => 'the urn went on early', 'prose' => 'Walt was there when it did.',
          'participants' => ['dot', 'ruth'], 'memories' => ['dot' => 'Dot saw the urn.', 'ruth' => 'Ruth put it on.']];

$dbP = fresh_db('proactive');
xeric_state_seed($dbP, $T);
// Both have a thread, so neither is refused for being a stranger.
foreach (['ruth', 'dot'] as $h) {
    $c = xeric_conversation_for($dbP, $h, 'chat');
    xeric_message_append($dbP, $c, 'user', null, 'hello', $NOW['epoch'] - 86400);
}
for ($i = 0; $i < 40; $i++) xeric_signal_add($dbP, 'ignored', ['handle' => 'dot']);
for ($i = 0; $i < 40; $i++) xeric_signal_add($dbP, 'reply', ['handle' => 'ruth', 'n' => 20]);
count_them($dbP);

$whoTexted = [];
for ($i = 0; $i < 24; $i++) {
    $d = fresh_db('proactive-run-' . $i);
    // A world that has learned the same thing, one run at a time.
    xeric_state_seed($d, $T);
    foreach (['ruth', 'dot'] as $h) {
        $c = xeric_conversation_for($d, $h, 'chat');
        xeric_message_append($d, $c, 'user', null, 'hello', $NOW['epoch'] - 86400);
    }
    for ($j = 0; $j < 40; $j++) xeric_signal_add($d, 'ignored', ['handle' => 'dot']);
    for ($j = 0; $j < 40; $j++) xeric_signal_add($d, 'reply', ['handle' => 'ruth', 'n' => 20]);
    count_them($d);

    $p = xeric_proactive_check($T, $d, stub_says('the urn is on.'), $NOW,
        ['event' => $EVENT, 'chance' => 1.0, 'involves_user' => true, 'seed' => 100 + $i]);
    if ($p !== null) $whoTexted[] = $p['handle'];
    $d = null;
}
ok('reach: over two dozen runs the answered one does most of the talking',
    count(array_filter($whoTexted, fn($h) => $h === 'ruth')) > count(array_filter($whoTexted, fn($h) => $h === 'dot')),
    implode(',', $whoTexted));
ok('reach: and the ignored one is still in the world — she has not been switched off',
    in_array('dot', $whoTexted, true), implode(',', $whoTexted));

// ---------------------------------------------------------------------------
// 4. Kinds — weighted down, never out
// ---------------------------------------------------------------------------

$dbK = fresh_db('kinds');
// Rumours land every time; friction is walked past every time.
for ($i = 0; $i < 6; $i++) xeric_signal_add($dbK, 'dwell',   ['handle' => 'ruth', 'subject' => 'rumor']);
for ($i = 0; $i < 6; $i++) xeric_signal_add($dbK, 'skipped', ['handle' => 'dot',  'subject' => 'friction']);
count_them($dbK);

$rates = xeric_learn_kind_rates($dbK);
ok('kinds: engagement is counted per kind of hour',
    (int)$rates['rumor']['seen'] === 6 && (int)$rates['rumor']['engaged'] === 6
    && (int)$rates['friction']['seen'] === 6 && (int)$rates['friction']['engaged'] === 0,
    json_encode($rates));

$weights = xeric_learn_kind_weights($dbK);
ok('kinds: the one that lands is weighted up', ($weights['rumor'] ?? 0) > 1.0, json_encode($weights));
ok('kinds: the one nobody follows up on is weighted DOWN but never to zero',
    ($weights['friction'] ?? -1) === XERIC_LEARN_KIND_FLOOR && XERIC_LEARN_KIND_FLOOR > 0.0,
    json_encode($weights));
ok('kinds: and never past the ceiling either', ($weights['rumor'] ?? 0) <= XERIC_LEARN_KIND_CEIL);

ok('kinds: a world that has learned nothing hands back nothing, so nothing changes',
    xeric_learn_kind_weights(fresh_db('kinds-empty')) === []);

$dbThin = fresh_db('kinds-thin');
xeric_signal_add($dbThin, 'skipped', ['subject' => 'friction']);
count_them($dbThin);
ok('kinds: one bad night is not a preference — under the confidence floor nothing moves',
    xeric_learn_kind_weights($dbThin) === [], json_encode(xeric_learn_kind_weights($dbThin)));

// The weights reach sweeps.php, and the kind that is never engaged with survives.
$TW = world(['rumors', 'rivals', 'shared_meals']);
$kinds = xeric_sweep_kinds_for($TW, $dbK);
ok('sweeps: the learned weight rides on the kind itself',
    ($kinds['rumor']['weight'] ?? 0) > 1.0 && ($kinds['friction']['weight'] ?? 0) === XERIC_LEARN_KIND_FLOOR,
    json_encode(array_map(fn($k) => $k['weight'], $kinds)));
ok('sweeps: a world with no database at all is exactly as it was — every weight 1.0',
    (function () use ($TW) {
        foreach (xeric_sweep_kinds_for($TW) as $k) if ((float)$k['weight'] !== 1.0) return false;
        return true;
    })());

$seenFirst = [];
for ($i = 0; $i < 200; $i++) {
    mt_srand(900 + $i);
    $seenFirst[] = (string)xeric_sweep_kind_order($kinds)[0]['key'];
}
$counts = array_count_values($seenFirst);
ok('sweeps: over two hundred orderings the favoured kind leads most often',
    ($counts['rumor'] ?? 0) > ($counts['friction'] ?? 0), json_encode($counts));
ok('sweeps: and the ignored kind still leads sometimes — nothing is extinguished',
    ($counts['friction'] ?? 0) > 0, json_encode($counts));
ok('sweeps: every kind is still in the order, every time',
    count(xeric_sweep_kind_order($kinds)) === count($kinds));

// ---------------------------------------------------------------------------
// 5. Lessons — deduped, capped, and in the prompt
// ---------------------------------------------------------------------------

$dbL = fresh_db('lessons');

xeric_lessons_add($dbL, xeric_arc_world(), ['Keep her answers under three lines — he replies to short ones.']);
xeric_lessons_add($dbL, xeric_arc_world(), ['Keep her replies under three lines; he answers the short ones.']);
ok('lessons: the same lesson in new words is not a second lesson',
    count(xeric_lessons_read($dbL, xeric_arc_world())) === 1,
    json_encode(xeric_lessons_read($dbL, xeric_arc_world())));

xeric_lessons_add($dbL, xeric_arc_world(), ['Do not bring up the building fund; he changes the subject.']);
ok('lessons: a genuinely different lesson is kept',
    count(xeric_lessons_read($dbL, xeric_arc_world())) === 2);

// Ten lessons that share no subject with each other, so nothing here is dropped
// as a rewording and the CAP is the only thing under test.
$ten = [
    'Answer within the hour or he stops waiting for it.',
    'Never mention the fire; he leaves the room.',
    'Weekday mornings are wasted — nobody is read before noon.',
    'Bring up money and the whole evening turns into arithmetic.',
    'He will follow a rumour further than a favour.',
    'Somebody else\'s bad news lands better than your own.',
    'Ask about tools, machinery, engines: those get three paragraphs.',
    'A question about feelings gets one word back, every time.',
    'Weather is a dead end and always has been.',
    'The dog is the fastest way into any conversation at all.',
];
foreach ($ten as $line) xeric_lessons_add($dbL, xeric_arc_world(), [$line]);

$capped = xeric_lessons_read($dbL, xeric_arc_world());
ok('lessons: the notebook is capped — a block that grows forever becomes the prompt',
    count($capped) === XERIC_LEARN_MAX_LESSONS, count($capped) . ': ' . json_encode($capped));
ok('lessons: and it is the oldest that goes, so the newest evidence survives',
    $capped[count($capped) - 1] === $ten[count($ten) - 1]
    && !in_array('Do not bring up the building fund; he changes the subject.', $capped, true),
    json_encode($capped));

xeric_lessons_add($dbL, xeric_arc_world(), ['', '   ', 'tiny']);
ok('lessons: empty and one-word lessons are not lessons',
    count(xeric_lessons_read($dbL, xeric_arc_world())) === XERIC_LEARN_MAX_LESSONS);

$long = str_repeat('a very long sentence about how he likes things done ', 20);
xeric_lessons_add($dbL, 'ruth', [$long]);
ok('lessons: one lesson is one line',
    mb_strlen(xeric_lessons_read($dbL, 'ruth')[0]) <= XERIC_LEARN_MAX_CHARS + 1,
    (string)mb_strlen(xeric_lessons_read($dbL, 'ruth')[0]));

ok('lessons: a character carries the world\'s lessons and then their own',
    count(xeric_lessons_for($dbL, 'ruth')) === XERIC_LEARN_MAX_LESSONS + 1
    && count(xeric_lessons_for($dbL)) === XERIC_LEARN_MAX_LESSONS
    && count(xeric_lessons_for($dbL, 'dot')) === XERIC_LEARN_MAX_LESSONS);

// -- and it is actually in the prompt, on the right side of the cache line ---
$dbPr = fresh_db('prompt');
xeric_state_seed($dbPr, $T);
xeric_memory_add($dbPr, 'ruth', 'Walt brought the dish back clean.', 'seed', [], $NOW['epoch'] - 86400);

$plain = xeric_prompt_system($T, $dbPr, 'ruth', 'sfw');
ok('prompt: a world that has learned nothing carries no lessons block at all',
    !str_contains($plain, 'WHAT YOU HAVE WORKED OUT'));

xeric_lessons_add($dbPr, xeric_arc_world(), ['Keep it short — he answers short messages and lets long ones sit.']);
xeric_lessons_add($dbPr, 'ruth', ['Never open with a question; he answers the second one and not the first.']);

$sys = xeric_prompt_system($T, $dbPr, 'ruth', 'sfw');
ok('prompt: the lessons are in her system message, world first and hers second',
    str_contains($sys, 'WHAT YOU HAVE WORKED OUT ABOUT WALT')
    && str_contains($sys, 'he answers short messages')
    && mb_strpos($sys, 'lets long ones sit') < mb_strpos($sys, 'Never open with a question'));
ok('prompt: and she is told not to say any of it out loud',
    str_contains($sys, 'You never say any of it'));
ok('prompt: the block sits ABOVE the memories — the memories are what grows',
    mb_strpos($sys, 'WHAT YOU HAVE WORKED OUT') < mb_strpos($sys, 'WHAT YOU REMEMBER'));
ok('prompt: nobody else\'s private lesson leaks into a different head',
    !str_contains(xeric_prompt_system($T, $dbPr, 'dot', 'sfw'), 'Never open with a question'));
ok('prompt: two calls a minute apart are still byte-identical',
    xeric_prompt_system($T, $dbPr, 'ruth', 'sfw') === $sys);

$msgs = xeric_prompt_build($T, $dbPr, 'ruth', $NOW, ['user_message' => 'You around?']);
ok('prompt: the lessons are static and the clock is still the last thing read',
    str_contains($msgs[0]['content'], 'WHAT YOU HAVE WORKED OUT')
    && !str_contains($msgs[count($msgs) - 1]['content'], 'WHAT YOU HAVE WORKED OUT')
    && str_contains($msgs[count($msgs) - 1]['content'], 'RIGHT NOW'));

// ---------------------------------------------------------------------------
// 6. The distil pass — both layers, and one of them without a model
// ---------------------------------------------------------------------------

$dbD = fresh_db('distil');
for ($i = 0; $i < 3; $i++) xeric_signal_add($dbD, 'reply', ['handle' => 'ruth', 'n' => 40, 'lag' => 120]);
xeric_signal_add($dbD, 'ignored', ['handle' => 'dot']);
xeric_signal_add($dbD, 'edit', ['handle' => 'ruth', 'subject' => 'a character (cast.characters.0.voice)',
    'note' => 'was “long, winding, fond of a story” — now “short. she does not explain herself”']);

$asked = null;
$r = xeric_lessons_distil($dbD, $T, stub_says(function (string $tag, array $m) use (&$asked) {
    $asked = $m;
    return ['lessons' => [
        ['about' => '',     'lesson' => 'Keep it short — he answers short messages and lets long ones sit.'],
        ['about' => 'ruth', 'lesson' => 'Ruth does not explain herself; she says the thing and stops.'],
        ['about' => 'nobody_at_all', 'lesson' => 'He is a thoughtful and interesting person.'],
    ]];
}, $asked));

ok('distil: every crumb in the batch was read', $r['signals'] === 5, json_encode($r['signals']));
ok('distil: and none of them is ever read again',
    xeric_signals_count($dbD, null, true) === 0 && xeric_lessons_distil($dbD, $T, stub_dead())['signals'] === 0);
ok('distil: the counting layer ran', xeric_learn_tally($dbD, 'ruth')['replies'] === 3
    && xeric_learn_tally($dbD, 'dot')['ignored'] === 1);
ok('distil: the words layer wrote into the world and into one head',
    isset($r['lessons']['']) && isset($r['lessons']['ruth']), json_encode($r['lessons']));
ok('distil: a lesson filed under nobody is kept for the world rather than thrown away',
    count(xeric_lessons_read($dbD, xeric_arc_world())) === 2, json_encode(xeric_lessons_read($dbD, xeric_arc_world())));
ok('distil: the model was shown the hand edit, in the user\'s own words',
    str_contains($asked[1]['content'] ?? '', 'RETYPED BY HAND')
    && str_contains($asked[1]['content'] ?? '', 'she does not explain herself'));
ok('distil: and what the counting already knows, so it is not asked to infer a number',
    str_contains($asked[1]['content'] ?? '', 'WHAT THE COUNTING KNOWS')
    && str_contains($asked[1]['content'] ?? '', 'Walt has answered Ruth Amberg'),
    (string)strstr((string)($asked[1]['content'] ?? ''), 'WHAT THE COUNTING KNOWS'));

// The division of labour, which is what stops the two layers arguing: a crumb
// whose whole meaning is a counter never reaches the model, because the English
// version of it would outlive the number and go on being true after it stopped.
ok('distil: what is already a number is not also handed to the model as a sentence',
    str_contains($asked[1]['content'] ?? '', 'RETYPED BY HAND')
    && !str_contains($asked[1]['content'] ?? '', 'texted first and Walt never answered'),
    (string)strstr((string)($asked[1]['content'] ?? ''), 'WHAT WALT DID'));
ok('distil: but it is still counted — the deterministic layer sees every crumb',
    xeric_learn_tally($dbD, 'dot')['ignored'] === 1);

$dbCount = fresh_db('counters-only');
for ($i = 0; $i < 4; $i++) xeric_signal_add($dbCount, 'skipped', ['subject' => 'friction']);
$rc = xeric_lessons_distil($dbCount, $T, stub_says(['lessons' => [['about' => '', 'lesson' => 'Stop doing friction entirely.']]]));
ok('distil: a batch that is nothing but counters never troubles the model at all',
    $rc['lessons'] === [] && xeric_lessons_read($dbCount, xeric_arc_world()) === []
    && (bool)preg_grep('/a number has not already said/', $rc['notes']), json_encode($rc['notes']));
ok('distil: and the counting still happened',
    (int)xeric_learn_kind_rates($dbCount)['friction']['seen'] === 4);

// THE ONE THAT MATTERS MOST: the model is gone and the world still learns.
$dbDead = fresh_db('dead');
for ($i = 0; $i < 4; $i++) xeric_signal_add($dbDead, 'reply', ['handle' => 'ruth', 'n' => 30]);
for ($i = 0; $i < 4; $i++) xeric_signal_add($dbDead, 'skipped', ['subject' => 'friction']);
for ($i = 0; $i < 4; $i++) xeric_signal_add($dbDead, 'dwell', ['handle' => 'ruth', 'subject' => 'rumor']);

$rd = xeric_lessons_distil($dbDead, $T, stub_dead());
ok('dead model: the pass comes back rather than throwing', $rd['signals'] === 12);
ok('dead model: and says so, in words', (bool)preg_grep('/nothing to say about it/', $rd['notes']),
    json_encode($rd['notes']));
ok('dead model: the tallies are there anyway',
    xeric_learn_tally($dbDead, 'ruth')['replies'] === 4
    && xeric_learn_reach($dbDead, 'ruth') === XERIC_LEARN_REACH_CEIL);
ok('dead model: and so are the weights, which is where behaviour actually changes',
    (xeric_learn_kind_weights($dbDead)['friction'] ?? -1) === XERIC_LEARN_KIND_FLOOR
    && (xeric_learn_kind_weights($dbDead)['rumor'] ?? 0) > 1.0,
    json_encode(xeric_learn_kind_weights($dbDead)));
ok('dead model: no lesson was invented out of a failed call',
    xeric_lessons_read($dbDead, xeric_arc_world()) === []);
ok('dead model: the crumbs are still marked read — a batch that retries forever blocks the next one',
    xeric_signals_count($dbDead, null, true) === 0);

// The counting and the mark are ONE fact — these crumbs have been looked at —
// and they used to be forty arc bumps apart with a model call in between. A
// machine that died in that minute woke up with the arithmetic applied and the
// evidence still open, and the next pass counted the same forty crumbs again.
$dbTx = fresh_db('distil-atomic');
for ($i = 0; $i < 4; $i++) xeric_signal_add($dbTx, 'reply', ['handle' => 'ruth', 'n' => 40, 'lag' => 60]);
xeric_signal_add($dbTx, 'edit', ['handle' => 'ruth', 'subject' => 'a character (cast.characters.0.voice)',
    'note' => 'short. she does not explain herself']);

$during = null;
$rtx = xeric_lessons_distil($dbTx, $T, stub_says(function () use ($dbTx, &$during) {
    // Read from inside the model call: this is the window the machine dies in.
    $during = ['open'    => xeric_signals_count($dbTx, null, true),
               'replies' => xeric_learn_tally($dbTx, 'ruth')['replies']];
    throw new RuntimeException('llm: the machine went away mid-call');
}));
ok('distil: the counting and the mark are committed together, before the model is ever called',
    $during === ['open' => 0, 'replies' => 4], json_encode($during));
ok('distil: so a death in the model window cannot make the next pass count the same crumbs twice',
    xeric_learn_tally($dbTx, 'ruth')['replies'] === 4
    && $rtx['signals'] === 5
    && xeric_lessons_distil($dbTx, $T, stub_dead())['signals'] === 0
    && xeric_learn_tally($dbTx, 'ruth')['replies'] === 4);
ok('distil: and the model still gets its turn — the transaction is closed, not the pass',
    (bool)preg_grep('/went away mid-call/', $rtx['notes']), json_encode($rtx['notes']));

ok('distil: nothing to learn from is an ordinary answer, not a failure',
    xeric_lessons_distil(fresh_db('distil-empty'), $T, stub_dead())['signals'] === 0);

$dbThin2 = fresh_db('distil-thin');
xeric_signal_add($dbThin2, 'reply', ['handle' => 'ruth', 'n' => 10]);
$rt = xeric_lessons_distil($dbThin2, $T, stub_says(['lessons' => [['about' => '', 'lesson' => 'Something confidently wrong.']]]));
ok('distil: one crumb is counted but never worth a model call',
    $rt['lessons'] === [] && xeric_learn_tally($dbThin2, 'ruth')['replies'] === 1
    && xeric_lessons_read($dbThin2, xeric_arc_world()) === []);

// ---------------------------------------------------------------------------
// 7. Hindsight — the two signals nobody can observe as they happen
// ---------------------------------------------------------------------------

$dbH = fresh_db('settle');
xeric_state_seed($dbH, $T);

$conv = xeric_conversation_for($dbH, 'ruth', 'chat');
xeric_message_append($dbH, $conv, 'user', null, 'evening', $NOW['epoch'] - 7200);

// Two hours happened; ruth texted about one of them.
$events = [
    ['id' => 1, 'kind' => 'rumor',    'participants' => ['ruth', 'dot']],
    ['id' => 2, 'kind' => 'friction', 'participants' => ['harlan', 'janelle']],
];
$pingId = xeric_message_append($dbH, $conv, 'character', 'ruth', 'the urn was already on.', $NOW['epoch']);
xeric_arc_set($dbH, 'ruth', 'proactive.last_message_id', $pingId);

xeric_learn_pend($dbH, $events, ['handle' => 'ruth'], $NOW['epoch']);

// …and the visitor went and answered Ruth, and never mentioned the other hour.
xeric_message_append($dbH, $conv, 'user', null, 'who put it on?', $NOW['epoch'] + 600);

$settled = xeric_learn_settle($dbH, $NOW['epoch'] + 3600);
ok('hindsight: the hour they followed up on is engaged with, the other is skipped past',
    $settled === ['engaged' => 1, 'skipped' => 1, 'ignored' => 0], json_encode($settled));
ok('hindsight: an answered ping is not an ignored one',
    xeric_signals_count($dbH, 'ignored') === 0);

count_them($dbH);
$rates = xeric_learn_kind_rates($dbH);
ok('hindsight: which lands where the weights can read it',
    (int)$rates['rumor']['engaged'] === 1 && (int)$rates['friction']['engaged'] === 0);

ok('hindsight: an hour is judged once — the pending row is spent',
    xeric_learn_settle($dbH) === ['engaged' => 0, 'skipped' => 0, 'ignored' => 0]);

// The same again, with nobody answering anything.
$dbH2 = fresh_db('settle-silent');
xeric_state_seed($dbH2, $T);
$c2 = xeric_conversation_for($dbH2, 'dot', 'chat');
xeric_message_append($dbH2, $c2, 'user', null, 'hello', $NOW['epoch'] - 7200);
$ping2 = xeric_message_append($dbH2, $c2, 'character', 'dot', 'you will not believe this.', $NOW['epoch']);
xeric_arc_set($dbH2, 'dot', 'proactive.last_message_id', $ping2);
xeric_learn_pend($dbH2, [['id' => 3, 'kind' => 'rumor', 'participants' => ['dot']]], ['handle' => 'dot'], $NOW['epoch']);

$s2 = xeric_learn_settle($dbH2, $NOW['epoch'] + 3600);
ok('hindsight: a message nobody answered before the world moved on is ignored',
    $s2 === ['engaged' => 0, 'skipped' => 1, 'ignored' => 1], json_encode($s2));

count_them($dbH2);
ok('hindsight: and it is her reach that pays for it, not the world\'s',
    xeric_learn_tally($dbH2, 'dot')['ignored'] === 1);

ok('hindsight: a world that was never pended settles to nothing',
    xeric_learn_settle(fresh_db('settle-empty')) === ['engaged' => 0, 'skipped' => 0, 'ignored' => 0]);

// ---------------------------------------------------------------------------
// 8. A turn writes itself down
// ---------------------------------------------------------------------------

$dbT = fresh_db('turn');
xeric_state_seed($dbT, $T);

xeric_chat_turn($T, $dbT, 'ruth', 'You around?', $NOW, stub_says('In the kitchen.'));
$sig = xeric_signals_unprocessed($dbT, 10);
ok('turn: answering somebody is written down as a crumb, with who and how long',
    count($sig) === 1 && $sig[0]['kind'] === 'reply' && $sig[0]['handle'] === 'ruth'
    && (int)$sig[0]['n'] === 11, json_encode($sig));

// The lag is measured against the line she left sitting, not against the clock.
$sleep = xeric_state_time();
$convT = xeric_conversation_find($dbT, 'ruth', 'chat')['id'];
xeric_message_append($dbT, (int)$convT, 'character', 'ruth', 'still here', $NOW['epoch'], $sleep - 900);
xeric_chat_turn($T, $dbT, 'ruth', 'sorry, was out', $NOW, stub_says('no bother.'));
$sig = xeric_signals_unprocessed($dbT, 10);
ok('turn: and how long they left her waiting',
    (int)$sig[count($sig) - 1]['lag'] >= 900, json_encode($sig[count($sig) - 1]));

$countBefore = xeric_signals_count($dbT);
try { xeric_chat_turn($T, $dbT, 'ruth', 'again?', $NOW, stub_dead()); } catch (Throwable $e) { /* expected */ }
ok('turn: a turn that never happened is never learned from',
    xeric_signals_count($dbT) === $countBefore);

xeric_chat_turn($T, $dbT, 'ruth', 'and now?', $NOW, stub_says('yes.'), ['learn' => false]);
ok('turn: a caller replaying history can answer without it counting as choosing to',
    xeric_signals_count($dbT) === $countBefore);

ok('turn: the world is otherwise exactly as it was — nothing learning does is load-bearing',
    xeric_messages_count($dbT, (int)$convT) === 7, (string)xeric_messages_count($dbT, (int)$convT));

// ---------------------------------------------------------------------------

$db = $db2 = $dbK = $dbL = $dbD = $dbH = $dbH2 = $dbT = $dbP = $dbPr = null;
$dbClamp = $dbDead = $dbThin = $dbThin2 = $dbTx = null;
foreach ($DBFILES as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
