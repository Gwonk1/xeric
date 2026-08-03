<?php
/**
 * Xeric — the cards. THE GAME IS CODE, THE PEOPLE ARE THE MODEL.
 *
 * That rule is the whole file, and it is not fussiness. A model asked to deal
 * its own cards deals itself good ones — not by cheating, by being a language
 * model: the likeliest continuation of "she looked at her hand" is a hand
 * worth looking at. Ask it to run a showdown and the person it has been
 * writing sympathetically wins. So nothing in here consults a model, ever.
 * The deck is shuffled from a seed, the hands are ranked by arithmetic, and
 * the pot goes where the arithmetic says.
 *
 * What the model is for is the half code cannot do: what Harlan SAYS while he
 * is losing, and whether he looks at his chips too long before he does it. His
 * tells are already in the schema — `tells[]` is "three things they do without
 * noticing", which is a poker tell verbatim — and they are walled interior
 * data, so somebody who has earned trust with him may be told one. The
 * relational layer becomes a game mechanic without inventing anything.
 *
 * THIS FILE IS THE SUB-GAME SEAM, not a poker feature. A table is a place, a
 * session is an hour, the result is a ledger write and an event. Dominoes,
 * darts, pennies, the pinball machine on the bar all reuse it. Poker is only
 * the first one, and it is first because Milldale's fixture already declares a
 * Thursday pot with `earned_by: user_event:hand_won` — the one phrase the
 * ledger matcher deliberately refuses to credit from prose, because "hand_won"
 * reduces to "hand" and the still-life rule puts hands in every hour. A real
 * table closes that gap exactly: the game reports the win as a FACT, not as
 * words to be matched.
 *
 * Zero dependencies. Deterministic given a seed. PHP 8.2+.
 */

declare(strict_types=1);

/** Ranks, weakest first. Index is the value: deuce 0 … ace 12. */
const XERIC_RANKS = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];

/** Suits. Order is fixed so a seeded deck is the same deck everywhere. */
const XERIC_SUITS = ['c', 'd', 'h', 's'];

/** Hand classes, weakest first — the index IS the class rank. */
const XERIC_HANDS = ['high card', 'a pair', 'two pair', 'three of a kind', 'a straight',
                     'a flush', 'a full house', 'four of a kind', 'a straight flush'];

/** A card is an int 0..51. Rank is intdiv 4, suit is mod 4. */
function xeric_card_rank(int $c): int { return intdiv($c, 4); }
function xeric_card_suit(int $c): int { return $c % 4; }

/** "Qh", for a transcript a person could read. */
function xeric_card_name(int $c): string
{
    return XERIC_RANKS[xeric_card_rank($c)] . XERIC_SUITS[xeric_card_suit($c)];
}

/** A whole hand, named. */
function xeric_cards_name(array $cards): string
{
    return implode(' ', array_map('xeric_card_name', $cards));
}

/**
 * A shuffled deck, from a seed.
 *
 * Fisher-Yates against a seeded generator, NOT shuffle(): the point of a seed
 * is that the same night deals the same cards, so a rewind puts back the hand
 * you actually lost rather than a fresh one you might win. mt_srand is the
 * engine's own idiom for exactly this (sweeps.php, room.php).
 */
function xeric_deck(int $seed): array
{
    $d = range(0, 51);
    mt_srand($seed);
    for ($i = 51; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$d[$i], $d[$j]] = [$d[$j], $d[$i]];
    }
    return $d;
}

/**
 * THE BEST FIVE OUT OF ANY HAND, as a comparable score.
 *
 * Returns [class, t1, t2, t3, t4, t5] — ALWAYS SIX ELEMENTS, padded with
 * zeroes, and the padding is load-bearing rather than tidy.
 *
 * PHP compares arrays BY LENGTH FIRST and only then element by element. A full
 * house scores [6, 0, 1] and a flush scores [5, 12, 11, 10, 9, 7], and with
 * ragged lengths the flush wins that comparison on count alone — three
 * elements is less than six. Every flush would have beaten every full house
 * and every four of a kind, silently, in every hand, and the hands would still
 * have LOOKED right because each was named correctly. Padded to a fixed width
 * the comparison is element-wise, which is what it always claimed to be: two
 * pair beats a pair on element 0, queens-up beats jacks-up on element 1.
 *
 * Handles five cards or seven. With seven it does NOT enumerate the 21
 * five-card subsets — every class can be read straight off the rank and suit
 * counts, which is both faster and much easier to be sure of.
 */
function xeric_hand_score(array $cards): array
{
    $byRank = array_fill(0, 13, 0);
    $bySuit = [[], [], [], []];
    foreach ($cards as $c) {
        $byRank[xeric_card_rank($c)]++;
        $bySuit[xeric_card_suit($c)][] = xeric_card_rank($c);
    }

    // A flush needs five of one suit, and with seven cards at most one suit
    // can have five — so there is never a choice of which flush to take.
    $flush = null;
    foreach ($bySuit as $ranks) {
        if (count($ranks) >= 5) { rsort($ranks); $flush = $ranks; break; }
    }

    // The straight walk, high to low. THE WHEEL IS THE ONE SPECIAL CASE and it
    // is a real rule, not a quirk: A-2-3-4-5 is a straight and the ace plays
    // LOW in it, so it is the weakest straight there is. Written as an
    // explicit fifth card rather than a clever modulus, because a clever
    // modulus here is how you ship a bug that only fires on one hand in
    // twenty thousand.
    $straightHigh = static function (array $has): ?int {
        for ($hi = 12; $hi >= 4; $hi--) {
            $run = true;
            for ($k = 0; $k < 5; $k++) if (empty($has[$hi - $k])) { $run = false; break; }
            if ($run) return $hi;
        }
        // The wheel: 5-4-3-2-A, ranked by its five (index 3).
        if (!empty($has[3]) && !empty($has[2]) && !empty($has[1]) && !empty($has[0]) && !empty($has[12])) {
            return 3;
        }
        return null;
    };

    // Six wide, always. See the note above: ragged scores compare by length.
    $score = static fn(array $s): array => array_slice(array_pad($s, 6, 0), 0, 6);

    if ($flush !== null) {
        $suited = array_fill(0, 13, 0);
        foreach ($flush as $r) $suited[$r] = 1;
        $sf = $straightHigh($suited);
        if ($sf !== null) return $score([8, $sf]);
    }

    // Ranks by how many of each, then by rank — so quads before trips, and
    // aces before kings inside the same count. One sort answers four classes.
    $groups = [];
    for ($r = 12; $r >= 0; $r--) if ($byRank[$r] > 0) $groups[] = [$byRank[$r], $r];
    usort($groups, fn($a, $b) => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);

    $counts = array_column($groups, 0);
    $ranks  = array_column($groups, 1);

    if ($counts[0] === 4) return $score([7, $ranks[0], max(array_slice($ranks, 1))]);
    if ($counts[0] === 3 && ($counts[1] ?? 0) >= 2) return $score([6, $ranks[0], $ranks[1]]);
    if ($flush !== null) return $score(array_merge([5], array_slice($flush, 0, 5)));

    $st = $straightHigh(array_map(fn($n) => $n > 0 ? 1 : 0, $byRank));
    if ($st !== null) return $score([4, $st]);

    if ($counts[0] === 3) return $score(array_merge([3, $ranks[0]], array_slice(array_slice($ranks, 1), 0, 2)));
    if ($counts[0] === 2 && ($counts[1] ?? 0) === 2) {
        // Two pair takes the TOP two pairs and one kicker — with seven cards
        // there can be three pairs, and the third one is a kicker at best.
        $kick = [];
        foreach ($groups as [$n, $r]) if ($r !== $ranks[0] && $r !== $ranks[1]) $kick[] = $r;
        return $score([2, $ranks[0], $ranks[1], max($kick)]);
    }
    if ($counts[0] === 2) return $score(array_merge([1, $ranks[0]], array_slice(array_slice($ranks, 1), 0, 3)));

    return $score(array_merge([0], array_slice($ranks, 0, 5)));
}

/** What to call it, out loud. */
function xeric_hand_name(array $score): string
{
    $n = XERIC_HANDS[$score[0]] ?? 'nothing';
    if ($score[0] === 8 && ($score[1] ?? 0) === 12) return 'a royal flush';
    return $n;
}

/**
 * Who wins, given everybody's seven cards. Ties SPLIT — a list, not a winner.
 *
 * @param array<string,array> $hands handle => cards
 * @return array{winners:array<int,string>,best:array,scores:array<string,array>}
 */
function xeric_showdown(array $hands): array
{
    $scores = [];
    foreach ($hands as $h => $cards) $scores[$h] = xeric_hand_score($cards);

    $best = null;
    foreach ($scores as $s) if ($best === null || $s > $best) $best = $s;

    $winners = [];
    foreach ($scores as $h => $s) if ($s === $best) $winners[] = (string)$h;

    return ['winners' => $winners, 'best' => (array)$best, 'scores' => $scores];
}

/**
 * Split a pot between winners, in whole chips, with the odd chip going left.
 *
 * A three-way split of a pot of ten is not three-and-a-third: it is four,
 * three and three, and WHICH of them gets the four is not arbitrary at a real
 * table — the odd chip goes to the first player left of the dealer. That is a
 * rule somebody at the table will know, and getting it wrong is the kind of
 * small wrongness that tells a player nobody thought about this.
 *
 * @param array<int,string> $winners in seat order from the dealer's left
 */
function xeric_pot_split(int $pot, array $winners): array
{
    $n = count($winners);
    if ($n === 0 || $pot <= 0) return [];
    $each = intdiv($pot, $n);
    $odd  = $pot - $each * $n;
    $out  = [];
    foreach ($winners as $i => $h) $out[$h] = $each + ($i < $odd ? 1 : 0);
    return $out;
}
