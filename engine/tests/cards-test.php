<?php
/**
 * cards-test.php — the arithmetic under a sub-game.
 *
 * This suite exists because hand evaluation is where the bugs live and none of
 * them are visible: a wheel that ranks as an ace-high straight, a two-pair
 * kicker taken from the wrong group, a seven-card flush that quietly picks the
 * wrong five. Every one of those produces a plausible winner every time and is
 * wrong maybe one hand in a few hundred, which is exactly long enough to ship.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/cards.php';

$FAILED = 0;
function ok(string $what, bool $cond, string $extra = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $what\n"; return; }
    $FAILED++;
    echo "FAIL - $what" . ($extra !== '' ? " ($extra)" : '') . "\n";
}

/** "Qh 2c" → card ints, so the tests read like cards. */
function hand(string $s): array
{
    $out = [];
    foreach (preg_split('/\s+/', trim($s)) as $t) {
        $suit = substr($t, -1);
        $rank = substr($t, 0, -1);
        $r = array_search($rank, XERIC_RANKS, true);
        $u = array_search($suit, XERIC_SUITS, true);
        if ($r === false || $u === false) throw new RuntimeException("no such card: $t");
        $out[] = $r * 4 + $u;
    }
    return $out;
}

function nameOf(string $s): string { return xeric_hand_name(xeric_hand_score(hand($s))); }
function beats(string $a, string $b): bool { return xeric_hand_score(hand($a)) > xeric_hand_score(hand($b)); }

// ---------------------------------------------------------------------------
// 1. EVERY CLASS, NAMED. The ladder in the order everybody at a table knows.
// ---------------------------------------------------------------------------

echo "\n# what a hand is called\n";

ok('cards: high card', nameOf('Ad 2c 5h 9s Jd') === 'high card');
ok('cards: a pair', nameOf('Ad Ac 5h 9s Jd') === 'a pair');
ok('cards: two pair', nameOf('Ad Ac 5h 5s Jd') === 'two pair');
ok('cards: three of a kind', nameOf('Ad Ac Ah 5s Jd') === 'three of a kind');
ok('cards: a straight', nameOf('9d 10c Jh Qs Kd') === 'a straight');
ok('cards: a flush', nameOf('2d 7d Jd Qd Kd') === 'a flush');
ok('cards: a full house', nameOf('Ad Ac Ah 5s 5d') === 'a full house');
ok('cards: four of a kind', nameOf('Ad Ac Ah As 5d') === 'four of a kind');
ok('cards: a straight flush', nameOf('5d 6d 7d 8d 9d') === 'a straight flush');
ok('cards: and the one it is worth naming properly',
    nameOf('10d Jd Qd Kd Ad') === 'a royal flush');

// ---------------------------------------------------------------------------
// 2. THE WHEEL. A-2-3-4-5 is a straight and the ace plays LOW in it, so it is
// the WEAKEST straight there is. Every naive implementation gets this wrong in
// the same direction — it ranks as ace-high and beats everything.
// ---------------------------------------------------------------------------

echo "\n# the wheel, which every naive version gets wrong\n";

ok('wheel: A-2-3-4-5 is a straight at all', nameOf('Ad 2c 3h 4s 5d') === 'a straight');
ok('wheel: and it is the WEAKEST one, not the strongest',
    beats('2d 3c 4h 5s 6d', 'Ad 2c 3h 4s 5d'));
ok('wheel: it does not beat a six-high straight by carrying an ace',
    !beats('Ad 2c 3h 4s 5d', '2d 3c 4h 5s 6d'));
ok('wheel: the steel wheel is a straight flush and still the weakest one',
    nameOf('Ad 2d 3d 4d 5d') === 'a straight flush'
    && beats('2c 3c 4c 5c 6c', 'Ad 2d 3d 4d 5d'));
ok('wheel: and A-2-3-4-6 is not a straight at all', nameOf('Ad 2c 3h 4s 6d') === 'high card');
ok('wheel: nor is K-A-2-3-4 — a straight does not wrap round the top',
    nameOf('Kd Ac 2h 3s 4d') === 'high card');

// ---------------------------------------------------------------------------
// 3. SEVEN CARDS. The real case, where the best five have to be found among 21
// possible ones — and where the interesting failures are.
// ---------------------------------------------------------------------------

echo "\n# seven cards, and finding the five that matter\n";

ok('seven: the best five are taken, not the first five',
    nameOf('2c 7d Ad Ac Ah As 3s') === 'four of a kind');
ok('seven: three pairs is TWO pair — the third is a kicker at best',
    nameOf('Ad Ac Kd Kc 5h 5s 2d') === 'two pair');
ok('seven: and the two pairs taken are the top two',
    xeric_hand_score(hand('Ad Ac Kd Kc 5h 5s 2d'))[1] === 12
    && xeric_hand_score(hand('Ad Ac Kd Kc 5h 5s 2d'))[2] === 11);
ok('seven: with three pairs the kicker is the best card outside them, not the third pair',
    xeric_hand_score(hand('Ad Ac Kd Kc 5h 5s Qd'))[3] === 10);
ok('seven: two trips is a full house, using the better one as the trip',
    nameOf('Ad Ac Ah Kd Kc Ks 2d') === 'a full house'
    && xeric_hand_score(hand('Ad Ac Ah Kd Kc Ks 2d'))[1] === 12);
ok('seven: a flush picks its own best five, not any five of the suit',
    xeric_hand_score(hand('2d 3d 7d Jd Ad Kc Qc'))[1] === 12
    && xeric_hand_score(hand('2d 3d 7d Jd Ad Kc Qc'))[4] === 1);
ok('seven: a straight and a flush in the same hand is the flush, unless they are the same five',
    nameOf('2d 3d 4d 5h 6d 9d Kc') === 'a flush');
ok('seven: and when they ARE the same five it is a straight flush',
    nameOf('2d 3d 4d 5d 6d 9c Kc') === 'a straight flush');

// ---------------------------------------------------------------------------
// 4. THE COMPARISONS THAT ACTUALLY DECIDE POTS.
// ---------------------------------------------------------------------------

echo "\n# who wins\n";

ok('rank: aces up beats kings up', beats('Ad Ac 3h 3s Kd', 'Kd Kc Qh Qs Jd'));
ok('rank: same pair, better kicker takes it', beats('Ad Ac Kh 4s 3d', 'As Ah Qc 4d 3c'));
ok('rank: same two pair, the kicker decides', beats('Ad Ac Kh Ks Qd', 'As Ah Kd Kc Jd'));
ok('rank: a flush is read all the way down', beats('Ad Kd Qd Jd 9d', 'Ac Kc Qc Jc 8c'));
ok('rank: trips beat two pair, however good the two pair',
    beats('2d 2c 2h 3s 4d', 'Ad Ac Kh Ks Qd'));
ok('rank: a full house beats a flush', beats('2d 2c 2h 3s 3d', 'Ad Kd Qd Jd 9d'));
// THE ONE THAT ACTUALLY BIT. PHP compares arrays BY LENGTH before contents, so
// a full house at [6,0,1] sorted BELOW a flush at [5,12,11,10,9,7] — three
// elements is less than six. Every flush beat every full house and every four
// of a kind, silently, in every hand, while each hand still NAMED itself
// correctly. Asserted as the whole ladder rather than one pair, because the
// next person to add a class will pad it wrong too.
$LADDER = [
    'high card'        => '2d 7c 9h Js Ac',
    'a pair'           => '2d 2c 9h Js Ac',
    'two pair'         => '2d 2c 9h 9s Ac',
    'three of a kind'  => '2d 2c 2h Js Ac',
    'a straight'       => '5d 6c 7h 8s 9c',
    'a flush'          => '2d 7d 9d Jd Kd',
    'a full house'     => '2d 2c 2h Js Jc',
    'four of a kind'   => '2d 2c 2h 2s Ac',
    'a straight flush' => '5d 6d 7d 8d 9d',
];
$rungs = array_keys($LADDER);
$ladderOk = true;
$why = '';
for ($i = 0; $i < count($rungs); $i++) {
    if (nameOf($LADDER[$rungs[$i]]) !== $rungs[$i]) {
        $ladderOk = false; $why = $rungs[$i] . ' is not called that'; break;
    }
    for ($j = 0; $j < $i; $j++) {
        if (!beats($LADDER[$rungs[$i]], $LADDER[$rungs[$j]])) {
            $ladderOk = false; $why = $rungs[$j] . ' is not beaten by ' . $rungs[$i]; break 2;
        }
    }
}
ok('rank: every class beats every weaker class, all thirty-six pairs of them',
    $ladderOk, $why);
ok('rank: and every score is the same width, which is what makes that true',
    count(array_unique(array_map(fn($h) => count(xeric_hand_score(hand($h))), $LADDER))) === 1);

ok('rank: and identical hands in different suits are identical',
    xeric_hand_score(hand('Ad Kd Qd Jd 9d')) === xeric_hand_score(hand('Ac Kc Qc Jc 9c')));

// ---------------------------------------------------------------------------
// 5. THE SHOWDOWN AND THE POT. A tie is a SPLIT, and the odd chip is a real
// rule somebody at the table knows.
// ---------------------------------------------------------------------------

echo "\n# the showdown, and the pot\n";

$sd = xeric_showdown([
    'harlan' => hand('Ad Ac Kh Ks Qd 2c 3c'),
    'ruth'   => hand('As Ah Kd Kc Jd 4c 5c'),
    'dot'    => hand('2d 2h 3d 3h 4d 5s 7c'),
]);
ok('showdown: the best hand takes it', $sd['winners'] === ['harlan']);
ok('showdown: and everybody\'s hand is scored, not only the winner\'s',
    count($sd['scores']) === 3 && xeric_hand_name($sd['scores']['dot']) === 'two pair');

$tie = xeric_showdown([
    'harlan' => hand('Ad Ac Kh Ks Qd 2c 3c'),
    'ruth'   => hand('As Ah Kd Kc Qc 4d 5d'),
]);
ok('showdown: two identical hands is a split, not a coin toss',
    count($tie['winners']) === 2);

ok('pot: an even split is even', xeric_pot_split(10, ['a', 'b']) === ['a' => 5, 'b' => 5]);
ok('pot: and an odd chip goes left of the dealer, which is a rule somebody knows',
    xeric_pot_split(10, ['a', 'b', 'c']) === ['a' => 4, 'b' => 3, 'c' => 3]);
ok('pot: every chip is paid out, always',
    array_sum(xeric_pot_split(7, ['a', 'b', 'c'])) === 7
    && array_sum(xeric_pot_split(101, ['a', 'b', 'c', 'd'])) === 101);
ok('pot: nothing to split pays nobody',
    xeric_pot_split(0, ['a']) === [] && xeric_pot_split(10, []) === []);

// ---------------------------------------------------------------------------
// 6. THE DECK. Seeded, so the same night deals the same cards — which is what
// makes a rewind put back the hand you actually lost.
// ---------------------------------------------------------------------------

echo "\n# the deck\n";

$d1 = xeric_deck(7);
ok('deck: fifty-two cards, each exactly once',
    count($d1) === 52 && count(array_unique($d1)) === 52
    && min($d1) === 0 && max($d1) === 51);
ok('deck: the same seed deals the same cards, so a rewind is honest',
    xeric_deck(7) === $d1);
ok('deck: and a different seed is a different night', xeric_deck(8) !== $d1);
ok('deck: it is actually shuffled', $d1 !== range(0, 51));

// The one property worth proving by exhaustion rather than by example: nothing
// in the evaluator can crash or return an unranked class, on any real hand.
echo "\n# nothing the deck can produce breaks it\n";
$seen = array_fill(0, 9, 0);
$bad = 0;
for ($s = 0; $s < 400; $s++) {
    $d = xeric_deck($s);
    for ($t = 0; $t + 7 <= 52; $t += 7) {
        $sc = xeric_hand_score(array_slice($d, $t, 7));
        if (!isset($sc[0]) || $sc[0] < 0 || $sc[0] > 8) { $bad++; continue; }
        $seen[$sc[0]]++;
        // A score must compare against itself as equal — the property every
        // sort in the showdown depends on.
        if (!($sc === xeric_hand_score(array_slice($d, $t, 7)))) $bad++;
    }
}
ok('deck: two thousand eight hundred real hands, none of them unrankable', $bad === 0);
ok('deck: and the common classes all actually turn up',
    $seen[0] > 0 && $seen[1] > 0 && $seen[2] > 0 && $seen[3] > 0 && $seen[6] > 0,
    json_encode($seen));
// Pairs and two pair are the bread and butter of seven-card hands; if high
// card outnumbered them the evaluator would be missing pairs somewhere.
ok('deck: and pairs are commoner than nothing-at-all, as they must be',
    $seen[1] + $seen[2] > $seen[0], json_encode($seen));

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
