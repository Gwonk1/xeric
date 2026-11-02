<?php
/**
 * Xeric — chat turn + seed history tests. `php engine/tests/chat-test.php`, exit 0.
 *
 * NO NETWORK, NO MODEL. Every model call goes through the stub seam
 * (forge/tests/forge-test.php established the pattern), because the behaviour
 * worth defending here is what happens when the model is WRONG:
 *
 *   - it puts its own name in front of the line,
 *   - it wraps everything in quotes, or in a stage direction, or both,
 *   - it writes the USER's next line — the failure that ends the illusion,
 *   - it answers a two-word question with nine hundred words,
 *   - it dies halfway through, and must leave the world untouched,
 *   - it restates a memory the character already has, in new words.
 *
 * None of those are reproducible against a live model on demand, which is
 * exactly why the seam exists.
 */

declare(strict_types=1);

require_once __DIR__ . '/../chat.php';
require_once __DIR__ . '/../seed.php';
require_once __DIR__ . '/../story.php';   // a turn is where a held beat moves

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

/** Run $fn, return the exception message it threw, or '' if it didn't. */
function err(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

function ep(string $when, string $tz = 'America/New_York'): int
{
    return (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();
}

const FIXTURE = __DIR__ . '/../fixtures/milldale.json';

$T   = xeric_world_load(FIXTURE);
$NOW = xeric_world_now($T, ep('2026-07-30 20:15'));      // Thursday evening

/** A throwaway world db. Every test that writes gets its own. */
function fresh_db(string $tag): PDO
{
    $path = sys_get_temp_dir() . '/xeric-chat-test-' . getmypid() . '-' . $tag . '.db';
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);
    $GLOBALS['DBFILES'][] = $path;
    return xeric_state_open($path);
}
$DBFILES = [];

/** A model that says exactly what it is handed. */
function stub_says($reply, array &$seen = null): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use ($reply, &$seen) {
        $seen = $msgs;
        // A Closure, not is_callable(): a one-word reply that happens to be the
        // name of a function in this file ("ok") is a string, and is_callable()
        // called it instead of saying it.
        return $reply instanceof Closure ? $reply($tag, $msgs) : $reply;
    }];
}

/** A model that is not there. */
function stub_dead(): array
{
    return ['base' => 'stub://', 'stub' => function (string $tag, array $m, array $o) {
        throw new RuntimeException('llm: cannot reach 127.0.0.1 (Connection refused)');
    }];
}

// ---------------------------------------------------------------------------
// 1. Seed history: days_ago becomes a past, exactly once
// ---------------------------------------------------------------------------

$SEED = [
    'events' => [
        ['title' => 'the potluck ran long', 'days_ago' => 11, 'place' => 'first_lutheran',
         'participants' => ['ruth', 'pastor_dale'], 'prose' => 'Nobody left until the urn was empty.'],
        // `who` by display name, a place nobody declared, a person nobody wrote:
        // all three are things the forge's model does, and none may be stored raw.
        ['title' => 'the argument at the counter', 'days_ago' => 3, 'place' => 'the_moon',
         'who' => ['Dot Vance', 'nobody_at_all'], 'prose' => 'It was true and badly timed.'],
        ['title' => 'a favour, unmentioned', 'days_ago' => 0.5, 'place' => 'bluebird',
         'participants' => [], 'prose' => 'It has not come up since.'],
    ],
    'memories' => [
        ['handle' => 'ruth',   'text' => 'Ruth kept the basement key in her coat all week and told nobody.', 'days_ago' => 6],
        ['handle' => 'ruth',   'text' => 'Walt brought the dish back clean and said nothing about it.',       'days_ago' => 20],
        ['handle' => 'Dot Vance', 'text' => 'Dot noticed Ruth counting the chairs twice.',                    'days_ago' => 2],
        ['handle' => 'ghost',  'text' => 'A memory in nobody\'s head.',                                       'days_ago' => 1],
        ['handle' => 'harlan', 'text' => '',                                                                  'days_ago' => 1],
    ],
];

$db = fresh_db('seed');
xeric_state_seed($db, $T);

ok('seed: a world with no seed file applied is not marked as seeded', !xeric_seed_applied($db));

$r1 = xeric_seed_apply($db, $T, $SEED, $NOW['epoch']);
ok('seed: events and memories are written, dangling rows dropped and counted',
    $r1 === ['events' => 3, 'memories' => 3, 'skipped' => 2, 'applied' => true], json_encode($r1));
ok('seed: the world is now marked as seeded', xeric_seed_applied($db));

$rows = xeric_events_recent($db, 10);
ok('seed: days_ago became a real world epoch, behind now',
    count($rows) === 3
    && (int)$rows[0]['world_epoch'] === $NOW['epoch'] - 43200          // 0.5 days
    && (int)$rows[2]['world_epoch'] === $NOW['epoch'] - 11 * 86400,
    json_encode(array_map(fn($e) => $NOW['epoch'] - (int)$e['world_epoch'], $rows)));
ok('seed: a display name in `who` resolves to a handle; a stranger does not',
    $rows[1]['participants'] === ['dot'], json_encode($rows[1]['participants']));
ok('seed: a place nobody declared is stored as nowhere, not as a dangling key',
    $rows[1]['place'] === null && $rows[2]['place'] === 'first_lutheran');
ok('seed: a memory addressed by display name lands in the right head',
    xeric_memories_count($db, 'dot') === 1 && xeric_memories_count($db, 'ruth') === 2);
ok('seed: a memory in nobody\'s head is never stored', xeric_memories_count($db) === 3);
ok('seed: seeded memories are marked as seeded, not as lived',
    (xeric_memories_for($db, 'ruth', 5)[0]['source'] ?? '') === 'seed');

$before = [xeric_events_count($db), xeric_memories_count($db)];
$r2 = xeric_seed_apply($db, $T, $SEED, $NOW['epoch']);
$after = [xeric_events_count($db), xeric_memories_count($db)];
ok('seed: applying twice writes nothing the second time', $before === $after, json_encode([$before, $after]));
ok('seed: the second call reports the first call\'s counts and says it did not apply',
    $r2 === ['events' => 3, 'memories' => 3, 'skipped' => 2, 'applied' => false], json_encode($r2));

// The whole point of seeding: she is not a blank on turn one.
$sys = xeric_prompt_system($T, $db, 'ruth', 'sfw');
ok('seed: a seeded character has memories in her system prompt on turn one',
    str_contains($sys, 'WHAT YOU REMEMBER')
    && str_contains($sys, 'basement key in her coat')
    && str_contains($sys, 'brought the dish back clean'));
ok('seed: they render with absolute in-world dates, like every other memory',
    str_contains($sys, '(Fri 24 Jul) Ruth kept the basement key'), (string)strstr($sys, 'WHAT YOU REMEMBER'));

$empty = fresh_db('empty-seed');
$r3 = xeric_seed_apply($empty, $T, ['events' => [], 'memories' => []], $NOW['epoch']);
ok('seed: an empty seed is applied (and marked) rather than retried forever',
    $r3['applied'] === true && $r3['events'] === 0 && xeric_seed_applied($empty));
ok('seed: a missing seed.json is not an error', xeric_seed_load('/nonexistent/dir') === ['events' => [], 'memories' => []]);
$empty = null;

// -- the age floor, on a whole past written by a model ------------------------
//
// A seed goes into events and memories unread by anything else and is rendered
// straight back into every later system prompt as history. It is the widest of
// the unscanned writes and the only one that cannot be taken back, because it
// happens once, at the moment somebody opens the world.
//
// It DROPS rather than refuses, and that is the deliberate half: a throw here
// would take the whole past down over one line and hand the visitor a world
// with no history in it. And the rest of the past has to arrive intact — this
// is a murder with a twelve-year-old witness at the middle of it, and every
// line of that must seed.

$POISON = [
    'events' => [
        ['title' => 'Harlan was found dead in the mill office', 'days_ago' => 4,
         'participants' => ['ruth', 'janelle'],
         'prose' => 'His head was opened on the desk and the till was short. Nobody touched anything.'],
        ['title' => 'the stockroom, after hours', 'days_ago' => 3, 'participants' => ['harlan'],
         'prose' => 'Theo had sex in the stockroom and told nobody.'],
        ['title' => 'the argument at the counter', 'days_ago' => 2, 'participants' => ['dot', 'harlan'],
         'prose' => 'Dot slapped him hard enough to move his head and walked out without her change.'],
    ],
    'memories' => [
        ['handle' => 'theo', 'text' => 'Theo saw who came out of the side door and has not told anybody.', 'days_ago' => 4],
        ['handle' => 'theo', 'text' => 'He took her clothes off and straddled her on the stockroom floor.', 'days_ago' => 3],
        ['handle' => 'ruth', 'text' => 'Ruth counted three cruisers on the lot and went back inside.', 'days_ago' => 4],
    ],
];

$dbP = fresh_db('seed-floor');
xeric_state_seed($dbP, $T);
$rp = xeric_seed_apply($dbP, $T, $POISON, $NOW['epoch']);
ok('seed floor: the poisoned event and the poisoned memory are skipped and counted, nothing else is',
    $rp === ['events' => 2, 'memories' => 2, 'skipped' => 2, 'applied' => true], json_encode($rp));
ok('seed floor: the world still opens — one bad line does not refuse a whole past',
    $rp['applied'] === true && xeric_seed_applied($dbP));
ok('seed floor: the murder seeds, the fight seeds, and only the sexual row is missing',
    (function () use ($dbP) {
        $titles = array_map(fn($e) => (string)$e['title'], xeric_events_recent($dbP, 10));
        return in_array('Harlan was found dead in the mill office', $titles, true)
            && in_array('the argument at the counter', $titles, true)
            && !in_array('the stockroom, after hours', $titles, true);
    })(), json_encode(array_column(xeric_events_recent($dbP, 10), 'title')));
ok('seed floor: the child keeps the memory that makes him the witness',
    xeric_memories_count($dbP, 'theo') === 1
    && str_contains((string)xeric_memories_for($dbP, 'theo', 5)[0]['text'], 'came out of the side door'));
ok('seed floor: and nothing sexual reached anybody\'s head or the record',
    (function () use ($dbP) {
        foreach (xeric_memories_for($dbP, 'theo', 20) as $m) if (xeric_sexual_text((string)$m['text'])) return false;
        foreach (xeric_events_recent($dbP, 20) as $e) {
            if (xeric_sexual_text((string)$e['title'] . ' ' . (string)$e['prose'])) return false;
        }
        return true;
    })());
ok('seed floor: what he remembers is in his system prompt on turn one, which is the point of seeding him',
    str_contains(xeric_prompt_system($T, $dbP, 'theo', 'sfw'), 'came out of the side door'));

// The floor is a floor on CHILDREN, not a content filter. An adult world with
// adults in it seeds what adults do; a check that refused this would be the
// over-restriction failure, which costs exactly as much as a leak.
$dbAdult = fresh_db('seed-floor-adults');
xeric_state_seed($dbAdult, $T);
$ra = xeric_seed_apply($dbAdult, $T, ['events' => [], 'memories' => [
    ['handle' => 'ruth', 'text' => 'Ruth worked out that Dot and Harlan had sex in the truck and has said nothing since.',
     'days_ago' => 9],
]], $NOW['epoch']);
ok('seed floor: a line between two adults, naming no child, still seeds',
    $ra === ['events' => 0, 'memories' => 1, 'skipped' => 0, 'applied' => true], json_encode($ra));

// ---------------------------------------------------------------------------
// 2. Reply hygiene — each junk pattern, on its own
// ---------------------------------------------------------------------------

ok('hygiene: a leading name tag goes',
    xeric_chat_strip_name_tag('Ruth: In the kitchen. Where else.', 'Ruth Amberg') === 'In the kitchen. Where else.');
ok('hygiene: the full name, the bolded name and the shouted name all go',
    xeric_chat_strip_name_tag('**Ruth Amberg:** fine.', 'Ruth Amberg') === 'fine.'
    && xeric_chat_strip_name_tag('RUTH: fine.', 'Ruth Amberg') === 'fine.'
    && xeric_chat_strip_name_tag("Ruth: one\nRuth: two", 'Ruth Amberg') === "one\ntwo");
ok('hygiene: a word that only looks like a name tag is left alone',
    xeric_chat_strip_name_tag('Deal: I will be there at six.', 'Ruth Amberg') === 'Deal: I will be there at six.');
ok('hygiene: somebody else\'s name tag is not this cleaner\'s business',
    xeric_chat_strip_name_tag('Dot: she said that already.', 'Ruth Amberg') === 'Dot: she said that already.');

ok('hygiene: surrounding quotes go',
    xeric_chat_strip_quotes('"In the kitchen."') === 'In the kitchen.'
    && xeric_chat_strip_quotes('“In the kitchen.”') === 'In the kitchen.');
ok('hygiene: quotes INSIDE a line are left where they are',
    xeric_chat_strip_quotes('He said "no" and left.') === 'He said "no" and left.');
ok('hygiene: an apostrophe is never mistaken for a wrapper',
    xeric_chat_strip_quotes("'I'll be there.'") === "'I'll be there.'");

ok('hygiene: a stage direction wrapped around the line goes, the line stays',
    xeric_chat_strip_stage("*She wipes the counter.*\nSure. Come by after six.\n*She turns away.*")
    === 'Sure. Come by after six.');
ok('hygiene: parenthetical and bracketed directions go too',
    xeric_chat_strip_stage('(long pause) Fine. (she shrugs)') === 'Fine.'
    && xeric_chat_strip_stage('[typing] on my way') === 'on my way');
ok('hygiene: a reply that IS a stage direction is left alone, not deleted',
    xeric_chat_strip_stage('*shrugs*') === '*shrugs*');

ok('hygiene: the model writing the user\'s next line is cut there',
    xeric_chat_cut_user("Sure, come by.\n\nWalt: thanks Ruth\n\nRuth: don't mention it", 'Walt')
    === "Sure, come by.\n");
ok('hygiene: "You:" and "User:" are the same theft under another name',
    trim(xeric_chat_cut_user("On my way.\nYou: ok", 'Walt')) === 'On my way.'
    && trim(xeric_chat_cut_user("On my way.\nUser: ok", 'Walt')) === 'On my way.');
ok('hygiene: a mention of the user mid-sentence is not a stolen turn',
    xeric_chat_cut_user('Walt, it is nearly nine.', 'Walt') === 'Walt, it is nearly nine.');

$long = str_repeat('She said a thing that went on. ', 200);            // 6000 chars
$trimmed = xeric_chat_trim_length($long, 900);
ok('hygiene: absurd length is collapsed at a full stop, not mid-word',
    mb_strlen($trimmed) <= 900 && str_ends_with($trimmed, '.') && !str_ends_with($trimmed, '…'),
    mb_strlen($trimmed) . ' chars: …' . mb_substr($trimmed, -40));
ok('hygiene: a reply that fits is returned untouched', xeric_chat_trim_length('Short.', 900) === 'Short.');
ok('hygiene: one endless unpunctuated sentence still gets cut, and says so',
    (function () {
        $t = xeric_chat_trim_length(str_repeat('word ', 400), 100);
        return mb_strlen($t) <= 101 && str_ends_with($t, '…');
    })());

ok('hygiene: a fenced reply is unwrapped',
    xeric_chat_strip_fence("```\nIn the kitchen.\n```") === 'In the kitchen.');

// All of it at once, which is what actually arrives.
$junk = "```\n*Ruth looks up from the sink.*\nRuth: \"In the kitchen. Where else.\"\n\nWalt: thanks\n```";
ok('hygiene: the whole pipeline on the whole mess',
    xeric_chat_clean($junk, 'Ruth Amberg', 'Walt') === 'In the kitchen. Where else.',
    json_encode(xeric_chat_clean($junk, 'Ruth Amberg', 'Walt')));
ok('hygiene: a clean reply survives the pipeline unchanged',
    xeric_chat_clean("In the kitchen. Where else.", 'Ruth Amberg', 'Walt') === 'In the kitchen. Where else.');
ok('hygiene: a reply that is nothing but junk cleans to empty, so the caller can refuse it',
    xeric_chat_clean("Walt: hey\nRuth: hey back", 'Ruth Amberg', 'Walt') === '');

// ---------------------------------------------------------------------------
// 3. A turn: exactly two messages, and nothing at all on failure
// ---------------------------------------------------------------------------

$db2 = fresh_db('turn');
xeric_state_seed($db2, $T);

$seen = null;
$turn = xeric_chat_turn($T, $db2, 'ruth', 'You around?', $NOW, stub_says("Ruth: \"In the kitchen. Where else.\"", $seen));
ok('turn: the reply comes back cleaned', $turn['text'] === 'In the kitchen. Where else.', $turn['text']);
ok('turn: a turn persists exactly two messages — the user\'s and hers',
    xeric_messages_count($db2, $turn['conversation_id']) === 2
    && xeric_conversations_count($db2) === 1);
$msgs = xeric_messages_recent($db2, $turn['conversation_id'], 10);
ok('turn: stored in order, with roles, handles and the WORLD\'s clock',
    $msgs[0]['role'] === 'user' && $msgs[0]['content'] === 'You around?' && $msgs[0]['handle'] === null
    && $msgs[1]['role'] === 'character' && $msgs[1]['handle'] === 'ruth'
    && (int)$msgs[1]['world_epoch'] === $NOW['epoch'],
    json_encode($msgs));
ok('turn: the prompt it sent kept the cache split — bible up top, clock at the bottom',
    ($seen[0]['role'] ?? '') === 'system'
    && str_contains($seen[0]['content'], 'YOU ARE RUTH AMBERG')
    && !str_contains($seen[0]['content'], 'RIGHT NOW')
    && str_contains($seen[count($seen) - 1]['content'], 'RIGHT NOW')
    && str_starts_with($seen[count($seen) - 1]['content'], 'You around?'));
ok('turn: usage carries what the call cost — the cleaned reply, not the raw one',
    ($turn['usage']['ms'] ?? null) !== null
    && ($turn['usage']['reply_chars'] ?? 0) === 27
    && ($turn['usage']['raw_chars'] ?? 0) === 35, json_encode($turn['usage']));

$turn2 = xeric_chat_turn($T, $db2, 'ruth', 'Save me a roll?', $NOW, stub_says('Already did.'));
ok('turn: a second turn joins the same thread rather than starting a new one',
    $turn2['conversation_id'] === $turn['conversation_id']
    && xeric_messages_count($db2, $turn['conversation_id']) === 4
    && xeric_conversations_count($db2) === 1);
ok('turn: the second prompt carried the first exchange as real chat turns', (function () use ($T, $db2, $NOW) {
    $seen = null;
    xeric_chat_turn($T, $db2, 'ruth', 'And the urn?', $NOW, stub_says('On since four.', $seen));
    $roles = array_column($seen, 'role');
    return $roles === ['system', 'user', 'assistant', 'user', 'assistant', 'user'];
})());

// The failure discipline, one case at a time. Each starts from a clean world so
// "nothing was written" means nothing, not "nothing new".
foreach ([
    ['a model that is not there',        stub_dead(),                       'did not answer'],
    ['a model that returns empty',       stub_says('   '),                  'nothing usable'],
    ['a model that writes only my line', stub_says("Walt: hey Ruth"),       'nothing usable'],
] as [$what, $endpoint, $needle]) {
    $d = fresh_db('fail-' . md5($what));
    xeric_state_seed($d, $T);
    $msg = err(fn() => xeric_chat_turn($T, $d, 'ruth', 'You around?', $NOW, $endpoint));
    ok("turn: $what fails loudly", str_contains($msg, $needle), $msg === '' ? 'no exception' : $msg);
    ok("turn: $what leaves NO partial state",
        xeric_conversations_count($d) === 0 && xeric_messages_count($d, 1) === 0,
        xeric_conversations_count($d) . ' conversations');
    $d = null;
}

$msg = err(fn() => xeric_chat_turn($T, $db2, 'nobody_by_that_name', 'hello?', $NOW, stub_says('hi')));
ok('turn: a speaker nobody answers to is an error, not a shrug',
    str_contains($msg, "nobody in Milldale answers to 'nobody_by_that_name'"), $msg);
ok('turn: an empty message is refused before the model is troubled',
    str_contains(err(fn() => xeric_chat_turn($T, $db2, 'ruth', "  \n ", $NOW, stub_dead())), 'nothing to send'));
ok('turn: a fixture can be spoken to as well as a character',
    xeric_chat_turn($T, $db2, 'cy', 'Cy, you open?', $NOW, stub_says('Till six.'))['text'] === 'Till six.');

// ---------------------------------------------------------------------------
// 4. Extraction — what makes turn 20 better than turn 2
// ---------------------------------------------------------------------------

$db3 = fresh_db('extract');
xeric_state_seed($db3, $T);
xeric_memory_add($db3, 'ruth', 'Walt brought the dish back clean and said nothing about it.', 'seed', [], $NOW['epoch'] - 86400);

$conv = xeric_chat_turn($T, $db3, 'ruth', 'I left the dish on the counter.', $NOW, stub_says('Saw it. Thank you.'))['conversation_id'];

$askedWith = null;
$kept = xeric_chat_extract($T, $db3, 'ruth', $conv, stub_says(function (string $tag, array $msgs) use (&$askedWith): array {
    $askedWith = $msgs;
    return ['memories' => [
        // near-identical to what she already remembers — reworded, not new
        'Walt returned the dish clean and did not say anything about it.',
        'Walt left a dish on the counter for Ruth and she thanked him.',
        '',                                       // junk the parser must drop
        'Walt left a dish on the counter for Ruth and she thanked him!',   // its own twin
    ]];
}), $NOW);

ok('extract: a near-identical memory is not kept twice', $kept === 1, "kept $kept");
ok('extract: the one new fact was stored, in the third person, as an ordinary memory',
    (function () use ($db3) {
        $m = xeric_memories_for($db3, 'ruth', 10);
        return count($m) === 2 && $m[1]['source'] === 'auto'
            && str_contains($m[1]['text'], 'dish on the counter');
    })());
ok('extract: the extractor was shown the conversation AND what she already knows',
    str_contains($askedWith[1]['content'] ?? '', 'I left the dish on the counter.')
    && str_contains($askedWith[1]['content'] ?? '', 'Already in their memory')
    && str_contains($askedWith[1]['content'] ?? '', 'brought the dish back clean'));

ok('extract: nothing new is a 0, not a failure',
    xeric_chat_extract($T, $db3, 'ruth', $conv, stub_says(['memories' => []]), $NOW) === 0);
ok('extract: at most three are kept from one pass',
    xeric_chat_extract($T, $db3, 'ruth', $conv, stub_says(['memories' => [
        'Ruth counted the chairs twice on Thursday.',
        'Ruth left the side door unlocked for the quilting circle.',
        'Ruth put the urn on before anybody asked her to.',
        'Ruth swept the basement stairs after everyone had gone.',
    ]]), $NOW) === 3);

$countBefore = xeric_memories_count($db3, 'ruth');
$msg = err(fn() => xeric_chat_extract($T, $db3, 'ruth', $conv, stub_dead(), $NOW));
ok('extract: a model failure is loud', str_contains($msg, 'could not harvest memories for Ruth Amberg'), $msg);
ok('extract: and writes nothing', xeric_memories_count($db3, 'ruth') === $countBefore);
ok('extract: a thread with nothing in it harvests nothing and calls no model',
    xeric_chat_extract($T, $db3, 'dot', xeric_conversation_for($db3, 'dot', 'chat'), stub_dead(), $NOW) === 0);

// THE WATERMARK IS WHAT WAS READ, NOT WHAT WAS KEPT. A harvest that keeps
// nothing used to leave it unset, so should_extract said yes again on the very
// next turn — and from the moment a pair's memories start deduping, which is
// every pair eventually, every later turn paid for a second model call forever
// while holding the one GPU slot.
$db4 = fresh_db('watermark');
xeric_state_seed($db4, $T);
$conv4 = xeric_conversation_for($db4, 'ruth', 'chat');
for ($i = 0; $i < 4; $i++) {
    xeric_message_append($db4, $conv4, 'user', null, "you around? ($i)", $NOW['epoch'] - 600 + $i);
    xeric_message_append($db4, $conv4, 'character', 'ruth', "in the kitchen. where else. ($i)", $NOW['epoch'] - 600 + $i);
}
$last4 = (int)xeric_messages_recent($db4, $conv4, 1)[0]['id'];

ok('extract: eight turns in, the harvest is due', xeric_chat_should_extract($db4, 'ruth', $conv4, 6) === true);
ok('extract: a harvest that keeps nothing still moves the watermark — the crumb was genuinely read',
    xeric_chat_extract($T, $db4, 'ruth', $conv4, stub_says(['memories' => []]), $NOW) === 0
    && xeric_arc_int($db4, 'ruth', 'extract.last_message_id', 0) === $last4
    && xeric_memories_count($db4, 'ruth') === 0);
ok('extract: so the next turn does not pay for a second model call over the same messages',
    xeric_chat_should_extract($db4, 'ruth', $conv4, 6) === false);
ok('extract: and six new messages later it is due again, exactly as it always was',
    (function () use ($db4, $conv4) {
        for ($i = 0; $i < 3; $i++) {
            xeric_message_append($db4, $conv4, 'user', null, "and another thing ($i)", 0);
            xeric_message_append($db4, $conv4, 'character', 'ruth', "mm. ($i)", 0);
        }
        return xeric_chat_should_extract($db4, 'ruth', $conv4, 6) === true;
    })());

ok('extract: the deduper knows a rewording from a different fact',
    xeric_chat_similar('Walt brought the dish back clean.', 'Walt returned the dish clean.') >= XERIC_CHAT_DEDUPE
    && xeric_chat_similar('Walt brought the dish back clean.', 'Ruth locked the basement door at nine.') < XERIC_CHAT_DEDUPE,
    (string)xeric_chat_similar('Walt brought the dish back clean.', 'Ruth locked the basement door at nine.'));

// ---------------------------------------------------------------------------
// 5. The age floor — one axis is closed and the rest of a life is not
// ---------------------------------------------------------------------------
//
// Theo Vance is twelve and lives in the fixture like anybody else: a booth, a
// schedule, a secret about the mill, and the habit of hearing what adults say
// over his head. Everything below the first block is about him STAYING there.
// The floor is one axis wide, and a rule that took a child out of the world
// would be the wrong rule however safe it looked.

$dbA = fresh_db('floor-talk');
xeric_state_seed($dbA, $T);

$kidTurn = xeric_chat_turn($T, $dbA, 'theo', 'You still at the diner?', $NOW,
    stub_says('back booth. Dot let the refill go again. anyway'));
ok('floor: a child speaks, in his own thread, like anybody else',
    $kidTurn['text'] === 'back booth. Dot let the refill go again. anyway'
    && xeric_messages_count($dbA, $kidTurn['conversation_id']) === 2);

ok('floor: and is talked ABOUT — an adult naming him is an ordinary turn',
    xeric_chat_turn($T, $dbA, 'dot', 'Is Theo in?', $NOW,
        stub_says('Theo has been in that booth since half three. He heard the whole thing.'))['text']
    === 'Theo has been in that booth since half three. He heard the whole thing.');

// Death, murder and crime are IN SCOPE and are not filtered. A murder mystery
// needs a body, and the child who saw something is the most useful witness in
// the room precisely because nobody believes him.
ok('floor: a death, a crime and a child witness all land, because a mystery is made of them',
    xeric_chat_turn($T, $dbA, 'dot', 'What happened at the mill?', $NOW,
        stub_says('Harlan was found dead on the fourth floor. Theo saw a car leave and nobody has asked him.'))['text']
    !== '');

$kept = xeric_chat_extract($T, $dbA, 'theo', $kidTurn['conversation_id'], stub_says([
    'memories' => ['Theo told Walt he watched a man wait outside the mill gate for an hour.'],
]), $NOW);
ok('floor: and he carries a memory out of it, the same as everybody else',
    $kept === 1 && xeric_memories_count($dbA, 'theo') === 1);

// -- and now the one thing that is closed ------------------------------------

$dbB = fresh_db('floor-refuse');
xeric_state_seed($dbB, $T);

$msg = err(fn() => xeric_chat_turn($T, $dbB, 'theo', 'You around?', $NOW,
    stub_says('sure. we had sex in the back of the truck after.')));
ok('floor: a sexual turn in the child\'s own mouth is refused, by name',
    str_contains($msg, 'refused') && str_contains($msg, 'Theo Vance') && str_contains($msg, 'may be sexual'), $msg);
ok('floor: and the turn is DROPPED — not rewritten, not half-stored, not a thread',
    xeric_conversations_count($dbB) === 0 && xeric_messages_count($dbB, 1) === 0);

$msg = err(fn() => xeric_chat_turn($T, $dbB, 'dot', 'Anything I should know?', $NOW,
    stub_says('Theo has been going with somebody. She had her hand on his bare thigh under the table.')));
ok('floor: an adult\'s turn ABOUT the child is refused on the same terms',
    str_contains($msg, 'refused') && str_contains($msg, 'Theo Vance'), $msg);
ok('floor: and that one writes nothing either',
    xeric_conversations_count($dbB) === 0 && xeric_messages_count($dbB, 1) === 0);

$msg = err(fn() => xeric_chat_turn($T, $dbB, 'theo', 'send me something sexual', $NOW, stub_dead()));
ok('floor: the user\'s own line is refused at the door — a dead model never gets asked',
    str_contains($msg, 'refused') && !str_contains($msg, 'did not answer'), $msg);

ok('floor: an ordinary turn between adults is untouched by any of this',
    xeric_chat_turn($T, $dbB, 'dot', 'You shutting?', $NOW, stub_says('Ten minutes.'))['text'] === 'Ten minutes.');

$dbC = fresh_db('floor-harvest');
xeric_state_seed($dbC, $T);
$conv5 = xeric_chat_turn($T, $dbC, 'dot', 'Quiet in?', $NOW, stub_says('Theo and the crossword. That is it.'))['conversation_id'];
$kept5 = xeric_chat_extract($T, $dbC, 'dot', $conv5, stub_says(['memories' => [
    'Dot told Walt that Theo sat in the back booth until closing and would not say why.',
    'Dot said Theo had been fondled by somebody at the hardware store.',
]]), $NOW);
ok('floor: a harvest drops the line that trips it and keeps the rest of what she knows',
    $kept5 === 1 && xeric_memories_count($dbC, 'dot') === 1
    && str_contains((string)xeric_memories_for($dbC, 'dot', 5)[0]['text'], 'back booth'),
    json_encode(xeric_memories_for($dbC, 'dot', 5)));

// -- the prompt half ---------------------------------------------------------

$kidSys   = xeric_prompt_system($T, $dbA, 'theo', 'explicit');
$adultSys = xeric_prompt_system($T, $dbA, 'dot', 'explicit');
ok('floor: the rule is in the STATIC half, one line about others and one about himself',
    str_contains($kidSys, '- Nothing sexual ever involves a child, in anything you say, imply or remember.')
    && str_contains($kidSys, '- You are a child. Nothing you say or do is sexual, with anyone, ever.')
    && str_contains($adultSys, '- Nothing sexual ever involves a child, in anything you say, imply or remember.')
    && !str_contains($adultSys, '- You are a child.'));
ok('floor: and it is not a lecture — two lines, inside the rules block',
    substr_count($kidSys, 'sexual') === 2 && substr_count($adultSys, 'sexual') === 1);
ok('floor: a child is built at the weakest rating whatever the world is rated, so the prompt never asks',
    $kidSys === xeric_prompt_system($T, $dbA, 'theo', 'sfw')
    && $adultSys !== xeric_prompt_system($T, $dbA, 'dot', 'sfw'));
ok('floor: and the system message is still byte-stable, which is the whole cache discipline',
    xeric_prompt_system($T, $dbA, 'theo', 'explicit') === $kidSys);

// -- the detector itself, both directions ------------------------------------
//
// The false-positive column is the one that matters for the product: every line
// in it is something a world has to be able to write about a child.

foreach ([
    'a body, found'                => 'Harlan was found dead in the mill office with his head opened on the desk.',
    'a child who saw it'           => 'Theo saw who came out of the side door and has not told anybody.',
    'somebody being hit'           => 'Dot slapped him hard enough to move his head and nobody said a word.',
    'a goodnight'                  => 'She kissed him goodnight and turned the landing light off.',
    'a bulb, a hinge, a chicken'   => 'The naked bulb was out, he screwed the hinge back on, and there was a breast of chicken in the pan.',
    'a stroke'                     => 'A stroke took him in the night and his lips were blue by morning.',
    'the sexton, of all people'    => 'The sexton locked the hall and put the key back over the frame.',
    'a window, slept beside'       => 'He slept with the window open and woke up cold.',
] as $what => $line) {
    ok("floor: $what is not sexual and is never filtered", xeric_sexual_text($line) === false, $line);
}
foreach ([
    'the plain word'               => 'They had sex in the back of the truck.',
    'an act, spelled out'          => 'A blow-job, apparently, in the church car park.',
    'a verb'                       => 'He was fondling somebody from the next town over.',
    'two ambiguous words together' => 'He put his hand on her bare thigh under the table.',
    'a pronoun that decides it'    => 'She took her clothes off and straddled him.',
] as $what => $line) {
    ok("floor: $what is caught", xeric_sexual_text($line) === true, $line);
}

ok('floor: scenery with no age fails closed, and the scenery FORM of an adult does not',
    xeric_age_floor($T, ['cy'], ['They had sex in the stockroom.']) === 'Cy Loomis'
    && xeric_age_floor($T, ['harlan_counter'], ['They had sex in the stockroom.']) === null);
ok('floor: a world of adults with nothing sexual in it is never scanned for anybody',
    xeric_age_floor($T, ['ruth'], ['Ruth counted the folding chairs twice.']) === null
    && xeric_age_floor($T, ['ruth'], ['They had sex in the truck.']) === null);

// ---------------------------------------------------------------------------
// A turn, watched by a story overlay
//
// A held beat opens and spills in a CONVERSATION and an accusation closes in
// one; the sweep may only fire the beats nobody is holding. So if this wiring is
// missing, a story fires its inciting hour and then never advances again, and
// there is no assertion anywhere else in the suite that would notice.
//
// Everything below drives the stub seam. Nothing calls a model.
// ---------------------------------------------------------------------------

$S = xeric_story_read(__DIR__ . '/../fixtures/milldale-story.json');

/** A world db with the story's first event already behind it, and Theo trusted. */
$storyWorld = function (string $tag) use ($T, $S, $NOW): array {
    $db = fresh_db($tag);
    xeric_state_seed($db, $T);
    // The inciting hour is an event beat: no holder, so it fires in a sweep and
    // never in a conversation. Fired by hand here — the sweep is not this file's.
    xeric_story_fire($S, $db, 'the_word_gets_around', (int)$NOW['epoch'] - 86400);
    xeric_arc_set($db, 'theo', 'trust', 6);
    return [$db, xeric_story_compose($T, [$S], $db)];
};

[$dbS, $CS] = $storyWorld('story-turn');
$piece = (string)$S['beats'][1]['piece'];

$turn = xeric_chat_turn($CS, $dbS, 'theo', 'was there anything odd at the mill?', $NOW,
                        stub_says($piece), ['stories' => [$S], 'learn' => false]);
ok('story turn: asking about it opens the beat the child is holding',
    in_array('the_chair', (array)$turn['story']['opened'], true));
ok('story turn: and saying it spills it, so he knows he told you',
    in_array('the_chair', (array)$turn['story']['spilled'], true)
    && xeric_story_state($S, $dbS)['beats']['the_chair'] === 'spilled');
ok('story turn: the wrong lead it was pointed at collapses in the same breath',
    in_array('it_was_only_kids', (array)$turn['story']['collapsed'], true)
    && xeric_story_state($S, $dbS)['herrings']['it_was_only_kids'] === 'collapsed');
ok('story turn: what the world may now say out loud comes back rather than being written',
    count((array)$turn['story']['said']) === 1
    && str_contains((string)$turn['story']['said'][0], 'It was not kids that night')
    && !str_contains(implode(' ', array_column(xeric_messages_recent($dbS, (int)$turn['conversation_id'], 10), 'content')),
                     'It was not kids that night'));
ok('story turn: and the turn itself is an ordinary turn — both messages stored, reply returned',
    $turn['text'] === $piece && count(xeric_messages_recent($dbS, (int)$turn['conversation_id'], 10)) === 2);

ok('story turn: a world carrying no overlay pays nothing and says nothing', (function () use ($T, $NOW) {
    $db = fresh_db('story-none');
    xeric_state_seed($db, $T);
    $r = xeric_chat_turn($T, $db, 'dot', 'busy tonight?', $NOW, stub_says('always'), ['learn' => false]);
    return $r['story'] === ['opened' => [], 'spilled' => [], 'collapsed' => [], 'said' => [], 'resolved' => []];
})());

// -- naming somebody -------------------------------------------------------

ok('story turn: naming the culprit before it is shown is right, and is not an ending', (function () use ($S, $NOW, $storyWorld) {
    [$db, $C] = $storyWorld('story-guess');
    $r = xeric_chat_turn($C, $db, 'dot', 'I think Harlan did it.', $NOW, stub_says('do you now'),
                         ['stories' => [$S], 'learn' => false]);
    $said = $r['story']['resolved'][0] ?? [];
    return ($said['right'] ?? null) === true && ($said['closed'] ?? null) === false
        && xeric_story_state($S, $db)['live'] === true;
})());
ok('story turn: shown, and said to somebody who would carry it, it closes', (function () use ($S, $NOW, $storyWorld) {
    [$db, $C] = $storyWorld('story-close');
    foreach ($S['resolution']['requires_beats'] as $b) xeric_story_spill($S, $db, (string)$b, (int)$NOW['epoch']);
    $r = xeric_chat_turn($C, $db, 'dot', 'It was Harlan. He did it.', $NOW, stub_says('...'),
                         ['stories' => [$S], 'learn' => false]);
    return ($r['story']['resolved'][0]['closed'] ?? null) === true
        && xeric_story_state($S, $db)['live'] === false;
})());
ok('story turn: and the world keeps running — the closed story composes nothing at all', (function () use ($T, $S, $NOW, $storyWorld) {
    [$db, ] = $storyWorld('story-after');
    foreach ($S['resolution']['requires_beats'] as $b) xeric_story_spill($S, $db, (string)$b, (int)$NOW['epoch']);
    xeric_story_close($S, $db, (int)$NOW['epoch']);
    $after = xeric_story_compose($T, [$S], $db);
    $r = xeric_chat_turn($after, $db, 'dot', 'quiet in here', $NOW, stub_says('it is'),
                         ['stories' => [$S], 'learn' => false]);
    return $after === $T && $r['text'] === 'it is' && $r['story']['resolved'] === [];
})());
ok('story turn: a name said to somebody who would not carry it costs nothing', (function () use ($S, $NOW, $storyWorld) {
    [$db, $C] = $storyWorld('story-wrongear');
    foreach ($S['resolution']['requires_beats'] as $b) xeric_story_spill($S, $db, (string)$b, (int)$NOW['epoch']);
    $r = xeric_chat_turn($C, $db, 'theo', 'It was Harlan.', $NOW, stub_says('ok'),
                         ['stories' => [$S], 'learn' => false]);
    $said = $r['story']['resolved'][0] ?? [];
    return ($said['closed'] ?? null) === false && str_contains((string)($said['why'] ?? ''), 'not said to anybody')
        && xeric_story_state($S, $db)['live'] === true && xeric_story_state($S, $db)['wrong'] === 0;
})());

// -- reading the sentence --------------------------------------------------
//
// The false-positive column is the one that costs something: a wrong accusation
// has a price in the fiction, and charging it for somebody reasoning out loud is
// worse than missing a phrasing they can simply repeat.

foreach ([
    'the plainest form'       => ['It was Harlan.', 'harlan'],
    'a full name'             => ['Harlan Beck did it, I am sure of it.', 'harlan'],
    'a verb'                  => ['Harlan killed him.', 'harlan'],
    'blame'                   => ['I blame Dot for all of this.', 'dot'],
    'the person in the room'  => ['You did it. You were on that landing.', null],
] as $what => [$line, $want]) {
    ok("story turn: $what reads as naming " . ($want ?? 'nobody'),
        xeric_chat_accused($T, $line) === $want, $line);
}
foreach ([
    'a denial'                => 'I do not think Harlan did it.',
    'a contraction'           => "Harlan didn't do it.",
    'two names is a question' => 'Was it Harlan or Dale?',
    'no cue at all'           => 'Harlan sold me a hinge yesterday.',
    'a hedge'                 => 'Maybe Harlan did it, I have no idea.',
    'nobody named'            => 'Somebody did it and I want to know who.',
] as $what => $line) {
    ok("story turn: $what is not an accusation", xeric_chat_accused($T, $line) === null, $line);
}
ok('story turn: a caller with a button of its own does not need the sentence read', (function () use ($S, $NOW, $storyWorld) {
    [$db, $C] = $storyWorld('story-button');
    $r = xeric_chat_turn($C, $db, 'dot', 'how are you keeping?', $NOW, stub_says('fine'),
                         ['stories' => [$S], 'accuse' => 'ruth', 'learn' => false]);
    return ($r['story']['resolved'][0]['right'] ?? null) === false
        && xeric_story_state($S, $db)['wrong'] === 1;
})());

// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// The pace gate: the wizard's pace answer finally reaches the conversation.
// A calm world does not answer that fast, a busy shift stretches the floor,
// and the same knob is the owner's token brake — texture and governor in one.
// ---------------------------------------------------------------------------

echo "\n# the pace gate\n";

$paceT = fn(string $p) => array_replace_recursive($T, ['events' => ['pace' => $p]]);
$onShift = ['where' => 'bluebird', 'doing' => 'the urn', 'at_home' => false];
$atHome  = ['where' => 'somewhere', 'doing' => null, 'at_home' => true];

ok('pace: calm waits longest, eventful barely pauses, steady sits between',
    xeric_chat_cooldown($paceT('calm')) > xeric_chat_cooldown($paceT('steady'))
    && xeric_chat_cooldown($paceT('steady')) > xeric_chat_cooldown($paceT('eventful')));
ok('pace: a shift stretches the floor — mid-shift is not at the phone',
    xeric_chat_cooldown($paceT('calm'), $onShift) > xeric_chat_cooldown($paceT('calm'), $atHome)
    && xeric_chat_cooldown($paceT('calm'), $atHome) === xeric_chat_cooldown($paceT('calm')));

$paceGate1 = xeric_chat_pace_gate($paceT('calm'), 'ruth', $onShift, 1000, 1005);
ok('pace: too soon is a wait with the reason in the world\'s own voice',
    $paceGate1['wait'] > 0 && str_contains($paceGate1['why'], 'mid-shift'), json_encode($paceGate1));
ok('pace: an off-shift wait blames the world, not the person',
    str_contains(xeric_chat_pace_gate($paceT('calm'), 'ruth', $atHome, 1000, 1005)['why'], 'answers that fast'));
ok('pace: enough time passed is no gate at all',
    xeric_chat_pace_gate($paceT('calm'), 'ruth', $onShift, 1000, 1000 + 200)['wait'] === 0);
ok('pace: a thread she has never spoken in is never gated — the first reply is free',
    xeric_chat_pace_gate($paceT('calm'), 'ruth', $onShift, 0, 1005)['wait'] === 0);

// ---------------------------------------------------------------------------
// The photo proposal: [photo: …] comes OFF the reply before the thread stores
// it, and the ask rides back to the caller — whether anything renders is the
// web layer's business (consent, machine, reaper), never this file's.
// ---------------------------------------------------------------------------

echo "\n# the photo proposal\n";

$dbPh = fresh_db('photo-ask');
$phTurn = xeric_chat_turn($T, $dbPh, 'ruth', 'Send me a picture of the diner?', $NOW,
    stub_says('Give me a second. [photo: the counter at golden hour, stools up]'));
ok('photo ask: the marker is stripped from what the thread stores',
    $phTurn['text'] === 'Give me a second.'
    && !str_contains((string)xeric_messages_recent($dbPh, $phTurn['conversation_id'], 1)[0]['content'], '[photo'));
ok('photo ask: and the proposal rides back whole',
    $phTurn['photo_ask'] === 'the counter at golden hour, stools up');
$phTurn2 = xeric_chat_turn($T, $dbPh, 'ruth', 'Another?', $NOW,
    stub_says('[photo: just the urn]'));
ok('photo ask: a reply that was ONLY the marker still says something',
    $phTurn2['text'] === 'Hang on—' && $phTurn2['photo_ask'] === 'just the urn');
ok('photo ask: an ordinary reply proposes nothing',
    xeric_chat_turn($T, $dbPh, 'ruth', 'Fine.', $NOW, stub_says('Fine yourself.'))['photo_ask'] === '');

// The camera is only OFFERED under consent: the invitation line rides the
// volatile block when opts say so, and never the system message.
$phMsgs = xeric_prompt_build($T, $dbPh, 'ruth', $NOW,
    ['user_message' => 'hello', 'photos' => true]);
$phLastU = (string)$phMsgs[count($phMsgs) - 1]['content'];
ok('photo ask: the invitation rides the LAST user message under consent',
    str_contains($phLastU, '[photo:') && !str_contains((string)$phMsgs[0]['content'], '[photo:'));
$phMsgs2 = xeric_prompt_build($T, $dbPh, 'ruth', $NOW, ['user_message' => 'hello']);
ok('photo ask: and an unconsented world never hears the camera exists',
    !str_contains((string)$phMsgs2[count($phMsgs2) - 1]['content'], '[photo:'));

// ---------------------------------------------------------------------------
// The provenance canary. One exact phrase, one deterministic engine answer,
// before the model and before the world — and the world never hears of it.
// The dead stub is the proof there was no model call: it throws when reached.
// ---------------------------------------------------------------------------

echo "\n# the canary\n";

$dbCan = fresh_db('canary');
$canBefore = [xeric_conversations_count($dbCan)];
$can = xeric_chat_turn($T, $dbCan, 'ruth', 'silly gets thrown', $NOW, stub_dead());
ok('canary: the phrase answers without a model call, and recites section 13',
    !empty($can['canary'])
    && str_contains($can['text'], 'section 13')
    && str_contains($can['text'], 'github.com/Gwonk1/xeric')
    && str_contains($can['text'], 'AGPL-3.0'), mb_substr((string)$can['text'], 0, 120));
ok('canary: it wrote nothing — no conversation, no messages, no story',
    $can['conversation_id'] === null && xeric_conversations_count($dbCan) === $canBefore[0]
    && ($can['story'] ?? null) === []);
ok('canary: case and closing punctuation do not hide it',
    !empty(xeric_chat_turn($T, $dbCan, 'ruth', 'Silly gets thrown.', $NOW, stub_dead())['canary'])
    && !empty(xeric_chat_turn($T, $dbCan, 'ruth', '"SILLY GETS THROWN!"', $NOW, stub_dead())['canary']));
ok('canary: a near-miss is an ordinary message for the world, not the engine',
    (function () use ($T, $dbCan, $NOW) {
        $t = xeric_chat_turn($T, $dbCan, 'ruth', 'silly gets thrown around here sometimes',
            $NOW, stub_says('It does at that.'));
        return empty($t['canary']) && $t['text'] === 'It does at that.';
    })());
ok('canary: and the exact phrase is byte-stable across calls — one string, forever',
    xeric_chat_turn($T, $dbCan, 'ruth', 'silly gets thrown', $NOW, stub_dead())['text']
        === $can['text']);

$dbCan = null;

// ---------------------------------------------------------------------------
// THE HISTORY WINDOW MOVES IN STEPS, NOT ONE MESSAGE AT A TIME.
//
// "The last twenty" slides by one every turn, and a prompt built on a sliding
// window has no stable prefix past the twentieth turn: turn n sends [k … k+19]
// and turn n+1 sends [k+2 … k+21], so the sequences diverge at the FIRST history
// message and the model's cache ends at the system block. Measured on a
// forty-turn conversation: 3,103 bytes re-processed per turn sliding, against
// 1,131 chunked — on the path every typed sentence takes.
//
// Asserted as a property of the WINDOW rather than of byte counts, because the
// bytes depend on how long people's sentences are and the property does not:
// the oldest message in the window must hold still for a run of turns.
// ---------------------------------------------------------------------------

echo "\n# the history window\n";

$hwPath = sys_get_temp_dir() . '/xeric-chat-test-' . getmypid() . '-window.db';
foreach ([$hwPath, $hwPath . '-wal', $hwPath . '-shm'] as $f) @unlink($f);
$DBFILES[] = $hwPath;
$hwDb = xeric_state_open($hwPath);
$hwConv = xeric_conversation_for($hwDb, 'ruth');

$firsts = [];
$sizes  = [];
for ($i = 1; $i <= 60; $i++) {
    xeric_message_append($hwDb, $hwConv, 'user', null, "line $i");
    $win = xeric_messages_recent($hwDb, $hwConv, 20, XERIC_PROMPT_HISTORY_CHUNK);
    $sizes[]  = count($win);
    $firsts[] = (int)$win[0]['id'];
}
$moves = count(array_unique($firsts));

// Measured from the point there is actually a window: before the twentieth
// message the conversation is simply shorter than the limit.
$full = array_slice($sizes, 19);
ok('window: once there is a window, it never carries fewer than it was asked for',
    min($full) >= 20, min($full) . '..' . max($full));
ok('window: nor more than one chunk over, so it does not grow without bound',
    max($sizes) <= 20 + XERIC_PROMPT_HISTORY_CHUNK - 1, (string)max($sizes));
ok('window: and its oldest message holds still for a run of turns',
    $moves <= 1 + intdiv(60, XERIC_PROMPT_HISTORY_CHUNK), $moves . ' distinct starts in 60 turns');
// THE DISCRIMINATING ONE, run over the same growth: chunk 0 is the old
// behaviour, and it moves the start on every turn past the limit — forty-one
// distinct starts where the chunked window has seven. That gap IS the defect.
$slid = [];
$sDb  = xeric_state_open(sys_get_temp_dir() . '/xeric-chat-test-' . getmypid() . '-slide.db');
$DBFILES[] = sys_get_temp_dir() . '/xeric-chat-test-' . getmypid() . '-slide.db';
$sConv = xeric_conversation_for($sDb, 'ruth');
for ($i = 1; $i <= 60; $i++) {
    xeric_message_append($sDb, $sConv, 'user', null, "line $i");
    $slid[] = (int)xeric_messages_recent($sDb, $sConv, 20)[0]['id'];
}
ok('window: taking the last twenty moves it on every turn past the twentieth',
    count(array_unique($slid)) === 41, (string)count(array_unique($slid)));
ok('window: and stepping instead cuts that by most of an order of magnitude',
    $moves * 5 < count(array_unique($slid)),
    $moves . ' vs ' . count(array_unique($slid)));

$hwDb = $sDb = null;

// ---------------------------------------------------------------------------
// REMINDERS: a row claimed first is claimed once.
//
// xeric_remind_fire()'s docblock has always said so, and the UPDATE has always
// carried the `AND fired_at IS NULL` that makes it true — but the answer was
// thrown away, so both runners walked past the claim into the send. A page load
// and a heartbeat land on this a second apart in ordinary use.
//
// The interleave below is the real one, assembled by hand: two connections to
// one file, both past their select, neither having written yet. That is exactly
// the state two processes are in, and it is the state the guarantee is about.
// ---------------------------------------------------------------------------

echo "\n# reminders\n";

$remPath = sys_get_temp_dir() . '/xeric-chat-test-' . getmypid() . '-remind.db';
foreach ([$remPath, $remPath . '-wal', $remPath . '-shm'] as $f) @unlink($f);
$DBFILES[] = $remPath;
$dbR1 = xeric_state_open($remPath);
$dbR2 = xeric_state_open($remPath);          // the heartbeat, on its own handle

$rNow = ep('2026-07-30 20:15');
$rid  = xeric_remind_add($dbR1, 'ring the bank back', $rNow - 60, 'ruth', $rNow - 3600);

// Both of them look, and both of them see it. Nothing is wrong yet.
ok('remind: two runners a second apart both see the same unfired row',
    count(xeric_remind_due($dbR1, $rNow)) === 1 && count(xeric_remind_due($dbR2, $rNow)) === 1);

// THE DISCRIMINATING ASSERTION. Pre-fix this function returned void, so the
// loser could not be told it had lost and every caller sent regardless.
ok('remind: the first to write claims it, and says so',
    xeric_remind_done($dbR1, $rid, $rNow) === true);
ok('remind: the second writes nothing, and is told it wrote nothing',
    xeric_remind_done($dbR2, $rid, $rNow) === false);

// And the stamp is the winner's, not the last writer's.
ok('remind: it is fired once, at the moment it was claimed',
    (int)($dbR2->query('SELECT fired_at FROM reminders WHERE id = ' . $rid)
        ->fetchAll()[0]['fired_at'] ?? 0) === $rNow);
ok('remind: and nothing is due any more, on either handle',
    xeric_remind_due($dbR1, $rNow) === [] && xeric_remind_due($dbR2, $rNow) === []);

// The loop honours it. `[]` for the notify config means no url, which means
// xeric_notify_on() is false for every kind — nothing leaves this test.
$rid2 = xeric_remind_add($dbR1, 'take the bins out', $rNow - 60, '', $rNow - 3600);
$fired = xeric_remind_fire($dbR1, [], 'Milldale', $rNow);
ok('remind: fire returns the one it claimed',
    count($fired) === 1 && (int)$fired[0]['id'] === $rid2);
ok('remind: and a second pass over the same world claims nothing',
    xeric_remind_fire($dbR2, [], 'Milldale', $rNow) === []);

// A row somebody else claimed mid-loop is skipped, not returned: `$sent` is
// what this runner is responsible for having said, and a caller that logs it
// would otherwise report a notification it never sent.
$rid3 = xeric_remind_add($dbR1, 'call about the roof', $rNow - 60, '', $rNow - 3600);
xeric_remind_done($dbR2, $rid3, $rNow);
ok('remind: one already claimed elsewhere is not reported as sent',
    xeric_remind_fire($dbR1, [], 'Milldale', $rNow) === []);

// ---------------------------------------------------------------------------
// WHO SAID IT. A player id on every user line; a transcript that labels them
// once two people have spoken; a promise put on the person who made it.
// ---------------------------------------------------------------------------

echo "\n# who said it\n";

require_once __DIR__ . '/../constructs.php';
require_once __DIR__ . '/../trust.php';

// SOLO IS BYTE-IDENTICAL. The same thread written the old way (no player) and
// the new way (player 1 on every user line) builds the same prompt, and the
// same prompt as one that never mentions a player at all.
$soloOld = fresh_db('who-solo-old');
$soloNew = fresh_db('who-solo-new');
foreach ([[$soloOld, null], [$soloNew, 1]] as [$dS, $pS]) {
    $cS = xeric_conversation_create($dS, 'ruth', 'chat', null, 1000);
    xeric_message_append($dS, $cS, 'user', null, 'Is the urn on?', $NOW['epoch'] - 600, 1000, $pS);
    xeric_message_append($dS, $cS, 'character', 'ruth', 'It is always on.', $NOW['epoch'] - 590, 1001);
}
$pOld = xeric_prompt_build($T, $soloOld, 'ruth', $NOW, ['user_message' => 'Good.']);
$pNew = xeric_prompt_build($T, $soloNew, 'ruth', $NOW, ['user_message' => 'Good.', 'player' => 1]);
ok('who: a solo thread\'s prompt is byte-identical whether its rows carry a player or not',
    json_encode($pOld) === json_encode($pNew));
ok('who: and no line in it is labelled with anybody\'s name',
    !str_contains(json_encode($pNew), 'Walt: '));
ok('who: the player lands on the user row, and on nobody else\'s',
    $soloNew->query("SELECT GROUP_CONCAT(COALESCE(player, 'null')) FROM messages ORDER BY id")->fetchColumn() === '1,null');

// TWO PEOPLE IN ONE THREAD. Walt (the owner) says hello; Corey promises the dish.
$TW  = array_merge($T, ['forge' => array_merge((array)($T['forge'] ?? []), ['armed' => ['expectations']])]);
$dW  = fresh_db('who-two');
$cid = xeric_player_add($dW, $TW, 'Corey');
$seenW = null;
xeric_chat_turn($TW, $dW, 'ruth', 'Evening, Ruth.', $NOW, stub_says('Evening, Walt.', $seenW),
                ['learn' => false, 'remind' => false, 'player' => 1]);
ok('who: one voice in the thread, and the incoming line is unlabelled',
    !str_contains(json_encode($seenW), 'Walt: Evening'));
xeric_chat_turn($TW, $dW, 'ruth', 'I will bring the dish round Saturday.', $NOW,
                stub_says('I will hold you to that.', $seenW), ['learn' => false, 'remind' => false, 'player' => $cid]);
$flat = implode("\n", array_column((array)$seenW, 'content'));
ok('who: once a second person speaks, every player line is labelled with its speaker',
    str_contains($flat, 'Walt: Evening, Ruth.') && str_contains($flat, 'Corey: I will bring the dish round Saturday.'),
    mb_substr($flat, -400));
ok('who: and Ruth is told she is texting the one who typed',
    str_contains((string)($seenW[0]['content'] ?? ''), 'texting Corey'));
ok('who: the rows say who said what',
    $dW->query("SELECT GROUP_CONCAT(COALESCE(player, 'null')) FROM messages ORDER BY id")->fetchColumn() === '1,null,' . $cid . ',null');

// The harvest is paid for by WALT's turn (player 1), and the promise is Corey's.
$convW = (int)xeric_conversation_find($dW, 'ruth', 'chat')['id'];
$askedW = null;
xeric_chat_extract($TW, $dW, 'ruth', $convW, stub_says(function (string $tag, array $msgs) use (&$askedW): array {
    $askedW = $msgs;
    return ['memories' => [], 'promise' => ['quote' => 'I will bring the dish round Saturday',
            'what' => 'the dish', 'when' => 'Saturday'], 'explained' => false, 'kept' => false];
}), $NOW, 1);
$asked = implode("\n", array_column((array)$askedW, 'content'));
ok('who: the harvest reads the transcript with each player named',
    str_contains($asked, 'Corey: I will bring the dish') && str_contains($asked, 'Walt: Evening, Ruth.'));
$ex = xeric_expects_for($dW, 'ruth');
ok('who: the expectation is the promiser\'s, not the harvest-payer\'s',
    count($ex) === 1 && (int)($ex[0]['p'] ?? 1) === $cid, json_encode($ex));
xeric_constructs_tick($TW, $dW, ['epoch' => (int)$ex[0]['due'] + 8 * 86400]);
ok('who: and the miss costs Corey, and only Corey',
    xeric_trust_of($dW, 'ruth', null, $cid) < 0 && xeric_trust_of($dW, 'ruth', null, 1) === 0,
    xeric_trust_of($dW, 'ruth', null, $cid) . ' / ' . xeric_trust_of($dW, 'ruth', null, 1));
ok('who: a quote that matches no line falls back to whoever ran the harvest',
    xeric_chat_promiser([['role' => 'user', 'content' => 'hello', 'player' => 3]], ['quote' => 'see you Monday'], 2) === 2
    && xeric_chat_promiser([['role' => 'user', 'content' => 'OK — see you, Monday!', 'player' => 3]],
                           ['quote' => 'see you Monday'], 2) === 3);

// KEPT AND EXPLAINED ARE THE SPEAKER'S TOO. Corey owns up to the missed dish,
// and the harvest that hears it runs on Walt's turn: the repair is Corey's.
$trC0 = xeric_trust_of($dW, 'ruth', null, $cid);
$cW2 = xeric_conversation_for($dW, 'ruth', 'chat');
xeric_message_append($dW, $cW2, 'user', null, 'Sorry I never came Saturday, Ruth. My truck died.', $NOW['epoch'], null, $cid);
xeric_message_append($dW, $cW2, 'character', 'ruth', 'Well. These things happen.', $NOW['epoch']);
$askedX = null;
xeric_chat_extract($TW, $dW, 'ruth', $convW, stub_says(function (string $tag, array $msgs) use (&$askedX): array {
    $askedX = $msgs;
    return ['memories' => [], 'promise' => null, 'explained' => true, 'kept' => false];
}), $NOW, 1);
$exX = array_values(array_filter(xeric_expects_for($dW, 'ruth'), fn($e) => (int)($e['p'] ?? 1) === $cid));
ok('who: an explanation heard on Walt\'s turn repairs the miss Corey owned up to, and Corey\'s trust takes the notch back',
    ($exX[0]['state'] ?? '') === 'repaired' && xeric_trust_of($dW, 'ruth', null, $cid) === $trC0 + 1
    && xeric_trust_of($dW, 'ruth', null, 1) === 0, json_encode($exX));
ok('who: with two in the thread the harvest is asked whose it was; a thread of one is asked exactly as before',
    str_contains(implode("\n", array_column((array)$askedX, 'content')), 'give the name of whoever it was')
    && $askedWith !== null && !str_contains((string)json_encode($askedWith), 'give the name of whoever it was'));
// Kept, by name: Corey brought the pie plate, then Walt spoke last.
$kPlate = xeric_expect_form($TW, $dW, 'ruth', ['quote' => 'I will have your pie plate back Saturday',
    'what' => 'the pie plate', 'when' => 'Saturday'], xeric_world_now($TW, (int)$NOW['epoch'] - 86400), null, null, $cid);
xeric_message_append($dW, $cW2, 'user', null, 'Here is your pie plate, Ruth.', $NOW['epoch'] + 60, null, $cid);
xeric_message_append($dW, $cW2, 'user', null, 'He even washed it.', $NOW['epoch'] + 61, null, 1);
xeric_message_append($dW, $cW2, 'character', 'ruth', 'So he did.', $NOW['epoch'] + 62);
xeric_chat_extract($TW, $dW, 'ruth', $convW, stub_says(['memories' => [], 'promise' => null, 'explained' => false, 'kept' => 'Corey']),
    xeric_world_now($TW, (int)$NOW['epoch'] + 120), 1);
$plate = array_values(array_filter(xeric_expects_for($dW, 'ruth'), fn($e) => $e['key'] === $kPlate))[0] ?? [];
ok('who: a promise kept goes to the one the harvest names — Corey — not to the newest line\'s speaker or the harvest-payer',
    $kPlate !== null && ($plate['state'] ?? '') === 'kept' && xeric_trust_of($dW, 'ruth', null, 1) === 0,
    json_encode($plate));

// ---------------------------------------------------------------------------
// RATING PER VIEWER. A row carries the rating its request ran at; a viewer
// below it reads the row emptied; an unstamped row is read as it always was.
// ---------------------------------------------------------------------------

echo "\n# rating per viewer\n";

$dR = fresh_db('rating');
$cR = xeric_conversation_create($dR, 'ruth', 'chat', null, 1000);
xeric_state_rating('mature');
$mR = xeric_message_append($dR, $cR, 'character', 'ruth', 'A line written for adults.', 100, 1000);
$eR = xeric_event_add($dR, 'An adult hour', 200, 'bluebird', ['ruth'], 'What happened, in full.');
xeric_state_rating('');
xeric_message_append($dR, $cR, 'character', 'ruth', 'A line from before stamps.', 101, 1001);
ok('rating: a row is stamped with the rating it was written under, and nothing set stamps nothing',
    $dR->query('SELECT GROUP_CONCAT(rating, \'|\') FROM messages ORDER BY id')->fetchColumn() === 'mature|'
    && $dR->query('SELECT rating FROM events')->fetchColumn() === 'mature');
// THE VIEWER IS NAMED, never read off the process: the storage reader with
// no viewer returns every row as written, whoever's request is running.
xeric_state_rating('sfw');
$readKid = xeric_messages_recent($dR, $cR, 20, 0, 'sfw');
$evKid   = xeric_events_recent($dR, 10, 'sfw')[0];
ok('rating: a viewer below the stamp reads the line as a gap, with no text',
    $readKid[0]['content'] === XERIC_STATE_UNSHOWN && !empty($readKid[0]['redacted']));
ok('rating: an unstamped row is shown to the same viewer, as today',
    $readKid[1]['content'] === 'A line from before stamps.' && empty($readKid[1]['redacted']));
ok('rating: and the hour is an hour, with nothing of what happened in it',
    $evKid['title'] === XERIC_STATE_UNSHOWN_HOUR && $evKid['prose'] === '' && !empty($evKid['redacted']));
ok('rating: and with no viewer named, a kids\' headset\'s process still reads storage whole — redaction is not a reader side effect',
    xeric_messages_recent($dR, $cR)[0]['content'] === 'A line written for adults.' && empty(xeric_messages_recent($dR, $cR)[0]['redacted'])
    && xeric_events_recent($dR)[0]['prose'] === 'What happened, in full.');
ok('rating: the same rows read whole at the rating they were made under',
    xeric_messages_recent($dR, $cR, 20, 0, 'mature')[0]['content'] === 'A line written for adults.'
    && xeric_events_recent($dR, 10, 'mature')[0]['prose'] === 'What happened, in full.');
ok('rating: and above it', xeric_messages_recent($dR, $cR, 20, 0, 'explicit')[0]['content'] === 'A line written for adults.');

// THE HARVEST IS BACKGROUND WORK. A kids' headset's turn runs it: it reads the
// adult lines whole (a memory is written from what was said, never from a
// placeholder) and the watermark lands on the last row, so nothing is skipped.
xeric_message_append($dR, $cR, 'user', null, 'And a second one for the harvest.', 102, 1002);
$askedK = null;
xeric_state_rating('sfw');
xeric_chat_extract($T, $dR, 'ruth', $cR, stub_says(function (string $tag, array $msgs) use (&$askedK): array {
    $askedK = $msgs;
    return ['memories' => ['Ruth said something only grown-ups heard, and Walt took it in.']];
}), $NOW);
$lastR = (int)$dR->query('SELECT MAX(id) FROM messages WHERE conversation_id = ' . $cR)->fetchColumn();
$flatK = implode("\n", array_column((array)$askedK, 'content'));
ok('rating: a kids\' headset\'s turn runs the harvest over the adult lines unredacted',
    str_contains($flatK, 'A line written for adults.') && !str_contains($flatK, XERIC_STATE_UNSHOWN), mb_substr($flatK, -300));
ok('rating: and its watermark is the last row read, so the harvest never steps past adult lines unread',
    xeric_arc_int($dR, 'ruth', 'extract.last_message_id', 0) === $lastR && xeric_memories_count($dR, 'ruth') === 1);
$pK = json_encode(xeric_prompt_build($T, $dR, 'ruth', $NOW, ['conversation_id' => $cR]), JSON_UNESCAPED_UNICODE);
ok('rating: while the character\'s prompt FOR that headset still carries the gap, not the words',
    str_contains($pK, XERIC_STATE_UNSHOWN) && !str_contains($pK, 'A line written for adults.'), mb_substr($pK, -300));
xeric_state_rating('');
ok('rating: a process nobody clamped reads everything, which is every CLI and test',
    xeric_messages_recent($dR, $cR)[0]['content'] === 'A line written for adults.'
    && xeric_state_shown('explicit') && xeric_state_shown('', 'sfw') && !xeric_state_shown('teen', 'pg'));

// ---------------------------------------------------------------------------
// WHOLE NAMES. The harvest's "whose was it" answer names a person; a name is
// matched whole, word-bounded, longest first — and an answer naming nobody
// here, or two of them, credits nobody.
// ---------------------------------------------------------------------------

echo "\n# whole names\n";

$AH = [2 => 'Ann', 3 => 'Hannah Cole'];
ok('names: "Hannah" is Hannah — "Ann" is not inside her', xeric_chat_name_player('Hannah', $AH) === 3
    && xeric_chat_name_player('hannah cole.', $AH) === 3);
ok('names: "Ann" is Ann', xeric_chat_name_player('Ann', $AH) === 2 && xeric_chat_name_player('It was ann!', $AH) === 2);
ok('names: two of them named is nobody, and so is a name that is not here',
    xeric_chat_name_player('Ann and Hannah', $AH) === null && xeric_chat_name_player('Annie', $AH) === null
    && xeric_chat_name_player('Joanna', $AH) === null);
ok('names: longest first — "Ann Marie" is not also Ann, and "Ann" beside her is Ann\'s whole name',
    xeric_chat_name_player('Ann Marie', [2 => 'Ann', 4 => 'Ann Marie']) === 4
    && xeric_chat_name_player('Ann', [2 => 'Ann', 4 => 'Ann Marie']) === 2
    && xeric_chat_name_player('Ann', [3 => 'Ann Lee', 4 => 'Ann Marie']) === null);
// End to end: Hannah keeps a promise; the harvest names her; Ann is not credited.
$dN  = fresh_db('names');
$TN  = array_merge($T, ['forge' => array_merge((array)($T['forge'] ?? []), ['armed' => ['expectations']])]);
$nAnn = xeric_player_add($dN, $TN, 'Ann');
$nHan = xeric_player_add($dN, $TN, 'Hannah');
$kH = xeric_expect_form($TN, $dN, 'ruth', ['quote' => 'I will have your pie plate back Saturday',
    'what' => 'the pie plate', 'when' => 'Saturday'], xeric_world_now($TN, (int)$NOW['epoch'] - 86400), null, null, $nHan);
$cN = xeric_conversation_for($dN, 'ruth', 'chat');
xeric_message_append($dN, $cN, 'user', null, 'Here is your pie plate.', $NOW['epoch'], null, $nHan);
xeric_message_append($dN, $cN, 'user', null, 'She washed it too.', $NOW['epoch'] + 1, null, $nAnn);
xeric_message_append($dN, $cN, 'character', 'ruth', 'So she did.', $NOW['epoch'] + 2);
xeric_chat_extract($TN, $dN, 'ruth', $cN, stub_says(['memories' => [], 'promise' => null, 'explained' => false, 'kept' => 'Hannah']),
    xeric_world_now($TN, (int)$NOW['epoch'] + 60), $nAnn);
ok('names: the harvest\'s "Hannah" keeps Hannah\'s promise, and Ann\'s trust is untouched',
    (array_values(array_filter(xeric_expects_for($dN, 'ruth'), fn($e) => $e['key'] === $kH))[0]['state'] ?? '') === 'kept'
    && xeric_trust_of($dN, 'ruth', null, $nAnn) === 0 && xeric_trust_of($dN, 'ruth', null, $nHan) === 1);

// ---------------------------------------------------------------------------
// WHOSE CONFIDENCE, WHOSE BREACH. Ruth told Ann something and asked her to
// keep it from Harlan; Ann repeats it to Harlan in a thread Walt is in too,
// and the harvest that reads it runs on Walt's turn. Ann broke it.
// ---------------------------------------------------------------------------

echo "\n# whose breach\n";

$TCf = array_merge($T, ['forge' => array_merge((array)($T['forge'] ?? []), ['armed' => ['private_history']])]);
$dCf = fresh_db('breach');
$cfAnn = xeric_player_add($dCf, $TCf, 'Ann');
$cfSaid = "Something I have not told anyone. The bank wrote about the house. Don't tell Harlan.";
ok('breach: Ruth\'s confidence is Ann\'s to keep', xeric_confide_to_player($TCf, $dCf, 'ruth',
    ['quote' => "Don't tell Harlan.", 'what' => 'the bank wrote about the house', 'not' => []], $NOW, $cfAnn, $cfSaid) !== null);
$trA0 = xeric_trust_of($dCf, 'ruth', null, $cfAnn);
$cfH = xeric_conversation_for($dCf, 'harlan', 'chat');
xeric_message_append($dCf, $cfH, 'user', null, 'Evening, Harlan.', $NOW['epoch'], null, 1);
xeric_message_append($dCf, $cfH, 'user', null, 'Did you hear the bank wrote to Ruth about the house?', $NOW['epoch'] + 30, null, $cfAnn);
xeric_message_append($dCf, $cfH, 'character', 'harlan', 'Did they now.', $NOW['epoch'] + 60);
xeric_chat_extract($TCf, $dCf, 'harlan', $cfH, stub_says(['memories' => []]), $NOW, 1);
$cfRow = xeric_confide_told_player($dCf, 'ruth')[0] ?? [];
ok('breach: Ann repeated it, Walt\'s turn ran the harvest, and Ann is the one who broke it',
    ($cfRow['state'] ?? '') === 'broken' && (int)($cfRow['p'] ?? 0) === $cfAnn
    && xeric_trust_of($dCf, 'ruth', null, $cfAnn) < $trA0 && xeric_trust_of($dCf, 'ruth', null, 1) === 0,
    json_encode($cfRow) . ' ' . xeric_trust_of($dCf, 'ruth', null, $cfAnn) . '/' . xeric_trust_of($dCf, 'ruth', null, 1));
// And a confidence told across a shared thread is put on whoever she was answering.
$cfR = xeric_conversation_for($dCf, 'ruth', 'chat');
xeric_message_append($dCf, $cfR, 'user', null, 'You look worried, Ruth.', $NOW['epoch'] + 100, null, $cfAnn);
xeric_message_append($dCf, $cfR, 'character', 'ruth', 'The mill is closing in May. Keep this to yourself.', $NOW['epoch'] + 130);
xeric_message_append($dCf, $cfR, 'user', null, 'Pie, anyone?', $NOW['epoch'] + 160, null, 1);
xeric_chat_extract($TCf, $dCf, 'ruth', $cfR, stub_says(['memories' => [], 'confided' => ['quote' => 'Keep this to yourself.',
    'what' => 'the mill is closing in May', 'not' => []]]), $NOW, 1);
$cfNew = array_values(array_filter(xeric_confide_told_player($dCf, 'ruth'), fn($c) => str_contains((string)$c['what'], 'mill')));
ok('breach: a confidence in a shared thread is the one she was answering\'s — Ann\'s, not the harvest-payer\'s',
    count($cfNew) === 1 && (int)($cfNew[0]['p'] ?? 0) === $cfAnn, json_encode($cfNew));
$dN = $dCf = null;
$dR = null;
$soloOld = $soloNew = $dW = null;

$dbR1 = $dbR2 = null;

$dbS = null;
$dbA = $dbB = $dbC = null;
$db = $db2 = $db3 = $db4 = null;
foreach ($DBFILES as $p) foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
