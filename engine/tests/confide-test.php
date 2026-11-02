<?php
/**
 * confide-test.php — the last needle, and not the one it looked like.
 *
 * `closeness` was filed for months as the intimacy economy, which is the
 * reading that makes it delicate. This is the other one: closeness is how much
 * of yourself somebody has seen, it is made of things told, it deepens by
 * being kept, and it breaks by being repeated — which the gossip ripple
 * already models.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/confide.php';

$FAILED = 0;
function ok(string $what, bool $cond, string $extra = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $what\n"; return; }
    $FAILED++;
    echo "FAIL - $what" . ($extra !== '' ? " ($extra)" : '') . "\n";
}

$DBS = [];
function fresh(string $tag): PDO
{
    global $DBS;
    $p = sys_get_temp_dir() . '/xeric-confide-' . $tag . '-' . getmypid() . '.db';
    foreach ([$p, $p . '-wal', $p . '-shm'] as $f) @unlink($f);
    $DBS[] = $p;
    $d = xeric_state_open($p);
    xeric_state_migrate($d);
    return $d;
}

$T = [
    'meta' => ['name' => 'Milldale'],
    'user' => ['name' => 'Neil'],
    'forge' => ['armed' => ['private_history']],
    'cast' => ['characters' => [
        ['handle' => 'ruth',   'display_name' => 'Ruth Amberg', 'age' => 52],
        ['handle' => 'harlan', 'display_name' => 'Harlan Beck', 'age' => 61],
        ['handle' => 'dot',    'display_name' => 'Dot Feeney',  'age' => 44],
        ['handle' => 'theo',   'display_name' => 'Theo Amberg', 'age' => 15],
        ['handle' => 'nobody_knows', 'display_name' => 'Ambiguous'],   // no age at all
    ]],
];
$NOW  = ['epoch' => 1785974400];
$OFF  = array_merge($T, ['forge' => ['armed' => ['daily_rhythms']]]);

// ---------------------------------------------------------------------------
// 1. THE FLOOR IS STRUCTURAL. Both parties adults, read off the integer age by
// the engine's own test, which fails closed.
// ---------------------------------------------------------------------------

echo "\n# who may be either party\n";

$db = fresh('floor');
ok('floor: two adults may tell each other things',
    xeric_confide_form($T, $db, 'ruth', 'about the letter', $NOW, 'harlan') !== null);
ok('floor: a child is never told one',
    xeric_confide_form($T, $db, 'theo', 'about the letter', $NOW, 'harlan') === null);
ok('floor: and never tells one',
    xeric_confide_form($T, $db, 'ruth', 'about the shed', $NOW, 'theo') === null);
// FAILS CLOSED. A character with no age at all is a minor by xeric_is_minor,
// and that is the behaviour this depends on rather than merely tolerates.
ok('floor: somebody with no age at all is treated as a child, not as an adult',
    xeric_confide_form($T, $db, 'nobody_knows', 'about the letter', $NOW, 'harlan') === null
    && xeric_confide_form($T, $db, 'ruth', 'about the mill', $NOW, 'nobody_knows') === null);
ok('floor: and nobody confides in themselves',
    xeric_confide_form($T, $db, 'ruth', 'about the car', $NOW, 'ruth') === null);

ok('armed: a world with no private history keeps none',
    xeric_confide_form($OFF, fresh('off'), 'ruth', 'about the letter', $NOW, 'harlan') === null);
ok('armed: and a world that predates arming keeps them, like every other construct',
    xeric_confide_armed(['cast' => []]));

// ---------------------------------------------------------------------------
// 2. A CONSTRUCT, NOT A COUNTER. And one row per THING, not per telling.
// ---------------------------------------------------------------------------

echo "\n# what is actually stored\n";

$c = xeric_confides_for($db, 'ruth')[0] ?? [];
ok('row: it knows what was told and who told it',
    ($c['what'] ?? '') === 'about the letter' && ($c['by'] ?? '') === 'harlan');
ok('row: and it is stored on the person carrying it, where it is a burden',
    xeric_confides_for($db, 'harlan') === []);

// THE FAILURE MODE OF EVERY AFFECTION METER: repetition as a way to be close
// to somebody. Saying the same thing twice is not two secrets.
$again = xeric_confide_form($T, $db, 'ruth', 'About The Letter', $NOW, 'harlan');
ok('row: the same thing told twice is one thing, whatever the capitals',
    count(xeric_confides_for($db, 'ruth')) === 1 && $again === $c['key']);
ok('row: a different thing is a different row',
    xeric_confide_form($T, $db, 'ruth', 'about the mill fund', $NOW, 'harlan') !== null
    && count(xeric_confides_for($db, 'ruth')) === 2);

// Being told something IS the closeness moving — warmth, not trust, so one
// confidence is not a friendship.
ok('row: being trusted with something warms them, slowly',
    xeric_arc_int($db, 'ruth', 'trust.warmth.of.harlan', 0) > 0
    && xeric_trust_of($db, 'ruth', 'harlan') === 0);

// ---------------------------------------------------------------------------
// 3. IT GOT OUT. The reason this is worth modelling at all: a thing that can
// only be kept or broken is a thing with stakes.
// ---------------------------------------------------------------------------

echo "\n# it got out\n";

$b = fresh('broke');
$k = xeric_confide_form($T, $b, 'ruth', 'about the letter from the bank', $NOW, 'harlan');
ok('broken: before anybody says anything, it is kept',
    (xeric_confides_for($b, 'ruth')[0]['state'] ?? '') === 'kept');

ok('broken: and repeating it breaks it', xeric_confide_break($T, $b, 'ruth', $k, $NOW) === true);
ok('broken: the person who told them thinks a great deal less of them',
    xeric_trust_of($b, 'harlan', 'ruth') === -XERIC_CONFIDE_BROKEN);
// THE ASYMMETRY IS THE POINT. Trust is slow to build and quick to lose because
// that is true, so breaking one costs far more than keeping one earns — and it
// goes through the EARNED path, which ordinary conversation cannot undo.
ok('broken: it costs more than keeping it ever earned',
    XERIC_CONFIDE_BROKEN > XERIC_CONFIDE_KEPT
    && abs(xeric_trust_of($b, 'harlan', 'ruth')) > XERIC_TRUST_BAND - 1);
ok('broken: and it only breaks once', xeric_confide_break($T, $b, 'ruth', $k, $NOW) === false);
ok('broken: something nobody ever said cannot break',
    xeric_confide_break($T, $b, 'ruth', 'told.99', $NOW) === false);

// ---------------------------------------------------------------------------
// 4. AND THE TOWN IS WHAT BREAKS IT. Detected rather than declared: the gossip
// ripple already spreads only what a bystander could have heard.
// ---------------------------------------------------------------------------

echo "\n# the town is what breaks it\n";

$g = fresh('gossip');
xeric_confide_form($T, $g, 'ruth', 'the letter from the bank', $NOW, 'harlan');
xeric_confide_form($T, $g, 'dot', 'the money in the coffee can', $NOW, 'harlan');

ok('sweep: an hour about nothing in particular breaks nothing',
    xeric_confide_sweep($T, $g, ['the chairs went back wrong', 'Nobody said much.'], $NOW) === 0);

// ALL the distinctive words, not any: a coincidence that costs three points of
// trust is worse than no feature at all.
ok('sweep: one word in common is a coincidence, not a betrayal',
    xeric_confide_sweep($T, $g, ['She read the letter twice and put it down.'], $NOW) === 0);

ok('sweep: the town saying the whole of it is the betrayal',
    xeric_confide_sweep($T, $g,
        ['everybody knows about the letter from the bank now'], $NOW) === 1);
ok('sweep: and only the one that got out',
    (xeric_confides_for($g, 'ruth')[0]['state'] ?? '') === 'broken'
    && (xeric_confides_for($g, 'dot')[0]['state'] ?? '') === 'kept');
ok('sweep: the same talk tomorrow does not break it again',
    xeric_confide_sweep($T, $g, ['everybody knows about the letter from the bank now'], $NOW) === 0);
ok('sweep: a world with no private history is swept for nothing',
    xeric_confide_sweep($OFF, $g, ['the money in the coffee can is gone'], $NOW) === 0);

// ---------------------------------------------------------------------------
// 5. SOMEBODY AT THE CENTRE CAN BE EITHER PARTY.
// ---------------------------------------------------------------------------

echo "\n# the person at the centre\n";

$p = fresh('player');
$pk = xeric_confide_form($T, $p, 'ruth', 'what happened in Omaha', $NOW, '', XERIC_PLAYER_FIRST);
ok('centre: they can tell somebody something', $pk !== null);
ok('centre: and it warms that person toward THEM, not toward a handle',
    xeric_arc_int($p, 'ruth', 'trust.warmth', 0) > 0);
$warmWas = xeric_arc_int($p, 'ruth', 'trust.warmth', 0);
$trustWas = xeric_trust_of($p, 'ruth', null, XERIC_PLAYER_FIRST);
ok('centre: it breaks like any other', xeric_confide_break($T, $p, 'ruth', $pk, $NOW) === true);
ok('centre: and the row says so', (xeric_confides_for($p, 'ruth')[0]['state'] ?? '') === 'broken');

// THE CORRECTION. This used to assert "costs the carrier their standing with
// them" and the code wrote `trust.p1` ON RUTH — which trust.php defines as what
// RUTH HAS DECIDED ABOUT YOU. So the engine was writing: Ruth told everybody
// your secret, therefore Ruth trusts you three less. The NPC branch does the
// opposite, correctly, and the two disagreed for as long as both existed.
//
// The row it wanted — what YOU now think of Ruth — does not exist and should
// not. The engine keeps the minds of the town; the one mind it must not presume
// to keep is the mind of the person playing. You do think less of her; that
// happens in your head, not in a column.
ok('centre: no number moves, because the mind that changed is not the town\'s',
    xeric_trust_of($p, 'ruth', null, XERIC_PLAYER_FIRST) === $trustWas
    && xeric_arc_int($p, 'ruth', 'trust.warmth', 0) === $warmWas);
ok('centre: and it never says she thinks less of YOU because SHE talked',
    xeric_trust_of($p, 'ruth', null, XERIC_PLAYER_FIRST) >= 0,
    (string)xeric_trust_of($p, 'ruth', null, XERIC_PLAYER_FIRST));
// The cost is the row and the town: her own prompt reads it back to her, and the
// ripple that detected it is already repeating the thing she was trusted with.
ok('centre: what it costs her is the sentence she now carries',
    str_contains(xeric_confide_block($T, $p, 'ruth'), 'it got out')
    && str_contains(xeric_confide_block($T, $p, 'ruth'), 'how that looks'));

$p2 = fresh('player2');
xeric_player_add($p2, $T, 'Corey');
xeric_confide_form($T, $p2, 'ruth', 'about the letter', $NOW, '', 2);
ok('centre: a guest\'s confidence is theirs, not the owner\'s',
    xeric_arc_int($p2, 'ruth', 'trust.warmth.p2', 0) > 0
    && xeric_arc_int($p2, 'ruth', 'trust.warmth', 0) === 0);

// ---------------------------------------------------------------------------
// 6. WHAT IT READS LIKE FROM THE INSIDE. Two halves, and they read completely
// differently.
// ---------------------------------------------------------------------------

echo "\n# from the inside\n";

$blk = xeric_confide_block($T, $g, 'dot');
ok('block: what you are keeping says nobody else has it from you',
    str_contains($blk, 'the money in the coffee can')
    && str_contains($blk, 'Nobody else has it from you'));

$broke = xeric_confide_block($T, $g, 'ruth');
ok('block: and what got out says so, without saying how it feels',
    str_contains($broke, 'it got out') && str_contains($broke, 'how that looks'));

// The other side, read off the carriers rather than stored twice — so there is
// one row per confidence and no way for the two halves to disagree.
$his = xeric_confide_block($T, $g, 'harlan');
ok('block: the person who told it sees their own side of it',
    str_contains($his, 'You told Ruth Amberg') && str_contains($his, 'going round'));
ok('block: and not the one that is still being kept for him',
    !str_contains($his, 'coffee can'));

ok('block: no numbers anywhere in it, so the prefix cache survives',
    !preg_match('/\d/', $blk . $broke . $his), $blk);
ok('block: a world with no private history says nothing at all',
    xeric_confide_block($OFF, $g, 'ruth') === ''
    && xeric_confide_block($T, fresh('quiet'), 'ruth') === '');

// ---------------------------------------------------------------------------
// 7. THE PERSON AT THE CENTRE IS TOLD SOMETHING. A user-created wall: the fact,
// who told it, who must not hear it. Formed from the extractor, code-checked,
// rendered into exactly one prompt, broken by repeating it.
// ---------------------------------------------------------------------------

echo "\n# what you were asked to keep\n";

require_once dirname(__DIR__) . '/chat.php';

ok('ask: an explicit ask is an ask',
    xeric_confide_marked("Don't tell Harlan, but the bank wrote about the house")
    && xeric_confide_marked('This stays between us.') && xeric_confide_marked('Keep this to yourself, Neil.'));
ok('ask: a hint is not', !xeric_confide_marked('The bank wrote about the house.')
    && !xeric_confide_marked('I told Harlan everything.'));

$c = fresh('told-you');
$said = "Something I have not told anyone. The bank wrote about the house. Don't tell Harlan.";
$prop = ['quote' => "Don't tell Harlan.", 'what' => 'the bank wrote about the house', 'not' => []];
ok('form: no ask in her words, no confidence',
    xeric_confide_to_player($T, $c, 'ruth', ['quote' => 'The bank wrote about the house.', 'what' => 'the bank letter'], $NOW,
                            XERIC_PLAYER_FIRST, $said) === null);
ok('form: words she never said are not her words',
    xeric_confide_to_player($T, $c, 'ruth', ['quote' => "Don't tell Dot.", 'what' => 'the bank letter'], $NOW,
                            XERIC_PLAYER_FIRST, $said) === null);
ok('form: a minor cannot be the teller', xeric_confide_to_player($T, $c, 'theo',
    ['quote' => 'Keep this to yourself.', 'what' => 'the shed'], $NOW, XERIC_PLAYER_FIRST, 'Keep this to yourself.') === null);
$ck = xeric_confide_to_player($T, $c, 'ruth', $prop, $NOW, XERIC_PLAYER_FIRST, $said);
$crow = xeric_confide_told_player($c, 'ruth')[0] ?? [];
ok('form: the ask in her own words forms one, naming who must not hear',
    $ck !== null && ($crow['not'] ?? null) === ['harlan'] && ($crow['state'] ?? '') === 'kept', json_encode($crow));
ok('form: told twice is one confidence', xeric_confide_to_player($T, $c, 'ruth', $prop, $NOW, XERIC_PLAYER_FIRST, $said) === $ck);

$rb = xeric_expect_block($T, $c, 'ruth', $NOW);
ok('block: the teller carries it — "you told Neil … and asked them to keep it"',
    str_contains($rb, 'You told Neil the bank wrote about the house and asked them to keep it'), $rb);
ok('block: and nobody else\'s prompt is built from it',
    !str_contains(xeric_expect_block($T, $c, 'harlan', $NOW), 'bank') && !str_contains(xeric_expect_block($T, $c, 'dot', $NOW), 'bank'));
ok('player side: the narrator-facing line says it from the player\'s side',
    (xeric_confide_player_lines($T, $c)[0] ?? '') === 'Ruth Amberg told you the bank wrote about the house and asked you to keep it.');

ok('break: saying it to somebody who was not named breaks nothing',
    xeric_confide_repeat($T, $c, 'dot', ['The bank wrote to Ruth about her house.'], $NOW) === 0);
ok('break: a sentence that only brushes it breaks nothing',
    xeric_confide_repeat($T, $c, 'harlan', ['Ruth was at the bank today.'], $NOW) === 0);
$tr0 = xeric_trust_of($c, 'ruth', null, XERIC_PLAYER_FIRST);
ok('break: telling Harlan breaks it — off the formed row',
    xeric_confide_repeat($T, $c, 'harlan', ['Did you know the bank wrote to Ruth about the house?'], $NOW) === 1
    && (xeric_confide_told_player($c, 'ruth')[0]['heard_by'] ?? '') === 'harlan');
ok('break: and her trust in the player takes the hard notch, on the right row',
    xeric_trust_of($c, 'ruth', null, XERIC_PLAYER_FIRST) === $tr0 - XERIC_CONFIDE_BROKEN);
ok('break: her prompt knows it got back to her', str_contains(xeric_confide_block($T, $c, 'ruth'), 'It got back to you that Neil told Harlan Beck'));
ok('break: and it breaks once', xeric_confide_repeat($T, $c, 'harlan', ['the bank wrote about the house'], $NOW) === 0);

// Never owned up to inside the window: it hardens into a grudge.
$later = ['epoch' => $NOW['epoch'] + XERIC_CONFIDE_REPAIR + 3600];
xeric_constructs_tick($T, $c, $later);
ok('grudge: an unrepaired breach hardens, and Ruth is cold with the player',
    (xeric_confide_told_player($c, 'ruth')[0]['state'] ?? '') === 'hardened'
    && str_contains(xeric_siblings_block($T, $c, 'ruth'), 'You have not forgiven Neil for telling Harlan Beck'));
ok('inspector: the confidence lists, with who it was kept from and where it went',
    str_contains(implode("\n", xeric_constructs_why($T, $c, 'ruth')), 'confidence (hardened): Ruth Amberg told Neil the bank wrote about the house and asked them to keep it from Harlan Beck; it reached Harlan Beck'));

$m = fresh('mend');
xeric_confide_to_player($T, $m, 'ruth', $prop, $NOW, XERIC_PLAYER_FIRST, $said);
xeric_confide_repeat($T, $m, 'harlan', ['the bank wrote about the house'], $NOW);
ok('mend: owning up inside the window mends it', xeric_confide_mend($T, $m, 'ruth', $NOW) !== null
    && str_contains(xeric_confide_block($T, $m, 'ruth'), 'owned up to it'));

// END TO END, through the extraction hook: the model reports her words, code
// checks them, a later harvest reads what the player said to Harlan.
$e = fresh('extract');
$cr = xeric_conversation_for($e, 'ruth', 'chat');
xeric_message_append($e, $cr, 'user', null, 'You look tired.', $NOW['epoch']);
xeric_message_append($e, $cr, 'character', 'ruth', $said, $NOW['epoch'] + 30);
$stubX = fn(array $out) => ['stub' => fn(string $tag, array $msgs, array $o = []) => $out, 'model' => 'stub'];
xeric_chat_extract($T, $e, 'ruth', $cr, $stubX(['memories' => [], 'confided' => $prop]), $NOW);
ok('extract: the harvest forms the confidence', count(xeric_confide_told_player($e, 'ruth')) === 1);
$ch = xeric_conversation_for($e, 'harlan', 'chat');
xeric_message_append($e, $ch, 'user', null, 'Between you and me, the bank wrote about the house. Ruth told me.', $NOW['epoch'] + 600);
xeric_message_append($e, $ch, 'character', 'harlan', 'Did they now.', $NOW['epoch'] + 630);
xeric_chat_extract($T, $e, 'harlan', $ch, $stubX(['memories' => []]), $NOW);
ok('extract: and repeating it to Harlan, read by his harvest, breaks it',
    (xeric_confide_told_player($e, 'ruth')[0]['state'] ?? '') === 'broken');
ok('extract: a confidence the model invented about the PLAYER\'s words forms nothing',
    (function () use ($T, $NOW, $stubX) {
        $d = fresh('extract2');
        $k = xeric_conversation_for($d, 'dot', 'chat');
        xeric_message_append($d, $k, 'user', null, "Don't tell anyone, but I'm thinking of leaving.", $NOW['epoch']);
        xeric_message_append($d, $k, 'character', 'dot', 'Oh, honey.', $NOW['epoch'] + 30);
        xeric_chat_extract($T, $d, 'dot', $k, $stubX(['memories' => [], 'confided' => ['quote' => "Don't tell anyone", 'what' => 'leaving']]), $NOW);
        return xeric_confide_told_player($d, 'dot') === [];
    })());

foreach ($DBS as $p3) foreach ([$p3, $p3 . '-wal', $p3 . '-shm'] as $f) @unlink($f);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
