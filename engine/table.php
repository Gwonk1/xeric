<?php
/**
 * Xeric — the table. A sub-game is a PLACE and an HOUR, not a menu item.
 *
 * A poker room you reach from a button would be a different app bolted to the
 * side of this one. A poker room you have to walk to on a Thursday, in the
 * church basement, where the people at it are the same people who will
 * remember on Friday that you cleaned them out, is the same world. So a table
 * declares a place key and a night, travel gets you there, presence says who
 * is sitting at it, and the sweep can write the hour it happened.
 *
 * ── WHAT IS CODE AND WHAT IS THE MODEL ────────────────────────────────────
 *
 * The cards are code (engine/cards.php). The BETTING is code too, and that is
 * the decision worth defending: a small model handed a stack of chips and
 * asked what it would like to do will call anything, raise on nothing, and
 * bankrupt a table in four hands, and it will do it in convincing prose. So
 * each seat plays a small, legible policy shaded by the person sitting in it —
 * their nerve, their needle, what they think of you — and the MODEL supplies
 * the half code genuinely cannot: what Harlan says while he is losing.
 *
 * That is the same rule as everywhere else in this engine, applied to chips.
 * Model proposes the table talk, code disposes the pot.
 *
 * ── THE MONEY IS REAL, IN THE ONLY SENSE THIS WORLD HAS ───────────────────
 *
 * A hand won credits the world's own economy through xeric_ledger_*, so the
 * board, the podium and everybody's prompts already render it with no new
 * code. And what the money MEANS is whatever that world armed — a debt, a
 * boon, standing — which is the pressure question already answered elsewhere.
 * The table does not invent a currency; it moves the one the world declared.
 *
 * ── LOSING TO SOMEBODY IS RELATIONAL ──────────────────────────────────────
 *
 * Taking money off a person is not a number going down. It is a thing that
 * happened between two people, so a hand moves the pair-trust row the same
 * slow way an hour does (engine/trust.php) — winning a lot off one person
 * costs you a little with them, and it is the one place in this engine where
 * doing well is faintly expensive.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/cards.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/trust.php';
require_once __DIR__ . '/constructs.php';  // what somebody could not cover is a debt

/** Fewest and most at a table. Two is heads-up; past six it is a tournament. */
const XERIC_TABLE_MIN = 2;
const XERIC_TABLE_MAX = 6;

/** Chips somebody sits down with when the world does not say. */
const XERIC_TABLE_STACK = 40;

/** A raise, in big bets. Penny-ante means penny-ante. */
const XERIC_TABLE_RAISE = 2;

/** Betting rounds in a hand: before the flop, after it, the turn, the river. */
const XERIC_TABLE_STREETS = 4;

// ---------------------------------------------------------------------------
// Is there a table, and who is at it
// ---------------------------------------------------------------------------

/**
 * The tables this world declares, keyed by key.
 *
 * A table is a small block on a place: what game, which night, what it costs
 * and which economy it pays. Everything else about the room — where it is,
 * who can get there, what is hidden from whom — is the place's own business
 * and is already answered by places, orbits and walls.
 */
function xeric_tables(array $t): array
{
    $out = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $g = $p['table'] ?? null;
        if (!is_array($g)) continue;
        $key = trim((string)($p['key'] ?? ''));
        if ($key === '') continue;
        $out[$key] = [
            'key'     => $key,
            'place'   => $key,
            'name'    => trim((string)($g['name'] ?? '')) ?: 'the game',
            'game'    => trim((string)($g['game'] ?? 'poker')),
            'nights'  => array_values(array_filter(array_map(
                fn($d) => strtolower(substr(trim((string)$d), 0, 3)), (array)($g['nights'] ?? [])))),
            'buy_in'  => max(1, (int)($g['buy_in'] ?? XERIC_TABLE_STACK)),
            'bet'     => max(1, (int)($g['bet'] ?? 1)),
            'economy' => trim((string)($g['economy'] ?? '')),
        ];
    }
    return $out;
}

/** Is tonight one of this table's nights? A table with no nights sits every night. */
function xeric_table_tonight(array $table, array $now): bool
{
    if ($table['nights'] === []) return true;
    $dow = strtolower(substr((string)($now['dow'] ?? ''), 0, 3));
    return in_array($dow, $table['nights'], true);
}

// ---------------------------------------------------------------------------
// The seats, and the nerve of the people in them
// ---------------------------------------------------------------------------

/**
 * HOW THIS PERSON PLAYS, from the person they already are.
 *
 * Nothing new is invented and nothing is authored per-table: nerve comes off
 * the psyche the forge already wrote, and it is deliberately coarse, because
 * a five-point scale is the difference between "Dot folds too much" being
 * legible at the table and being a number nobody can feel.
 *
 * @return array{nerve:int,tell:string}
 */
function xeric_table_nerve(array $t, string $handle): array
{
    $c = xeric_world_character($t, $handle) ?? [];
    $blob = mb_strtolower(implode(' ', array_filter([
        (string)($c['one_line'] ?? ''),
        (string)($c['psyche']['drive'] ?? ''),
        (string)($c['psyche']['fear'] ?? ''),
        (string)($c['voice']['style'] ?? ''),
    ])));

    $nerve = 2;                                   // the middle of five
    foreach (['reckless' => 2, 'proud' => 1, 'stubborn' => 1, 'restless' => 1, 'angry' => 1,
              'bold' => 1, 'sharp' => 1, 'careful' => -1, 'quiet' => -1, 'anxious' => -1,
              'timid' => -2, 'cautious' => -1, 'gentle' => -1, 'worried' => -1] as $w => $d) {
        if (str_contains($blob, $w)) $nerve += $d;
    }
    $tells = (array)($c['tells'] ?? []);
    return ['nerve' => max(0, min(4, $nerve)),
            'tell'  => $tells === [] ? '' : (string)$tells[0]];
}

/**
 * What this seat does, facing a bet it can see.
 *
 * A POLICY, NOT A MODEL CALL, and the argument for that is the same one that
 * keeps the deck in code. Ask a small model what it would like to do with two
 * pair and it will call a raise it cannot afford, in prose good enough that
 * nobody notices until the table is broke. This is coarse, legible, shaded by
 * the person, and cannot bankrupt anybody by hallucinating.
 *
 * Reads its OWN hole cards and the board — never anybody else's, which is the
 * one thing a card game shares with a knowledge wall.
 *
 * @return array{do:string,chips:int}
 */
function xeric_table_move(array $t, string $handle, array $hole, array $board, int $toCall,
                          int $stack, int $bet, int $seed = 0): array
{
    if ($stack <= 0) return ['do' => 'fold', 'chips' => 0];

    $nerve = xeric_table_nerve($t, $handle)['nerve'];
    $score = xeric_hand_score(array_merge($hole, $board));
    $class = (int)$score[0];

    // A hand that is worth something before the board arrives: a pair in the
    // hole, or two high cards. Preflop everybody has "high card" and the class
    // alone would fold every seat every time.
    if ($board === []) {
        $pair = xeric_card_rank($hole[0]) === xeric_card_rank($hole[1]);
        $high = min(xeric_card_rank($hole[0]), xeric_card_rank($hole[1])) >= 8;   // ten or better
        $class = $pair ? 2 : ($high ? 1 : 0);
    }

    mt_srand($seed);
    $strength = $class + intdiv($nerve, 2) + (mt_rand(0, 100) < 12 ? 2 : 0);   // the occasional bluff

    if ($toCall <= 0) {
        // Nobody has bet. Strong hands and brave people put something in.
        if ($strength >= 3) return ['do' => 'bet', 'chips' => min($stack, $bet * XERIC_TABLE_RAISE)];
        if ($strength >= 1) return ['do' => 'bet', 'chips' => min($stack, $bet)];
        return ['do' => 'check', 'chips' => 0];
    }

    // Facing a bet. Nobody calls off a whole stack on nothing — that guard is
    // what makes a table survive a night rather than a hand.
    if ($toCall >= $stack) {
        return $strength >= 4 ? ['do' => 'call', 'chips' => $stack] : ['do' => 'fold', 'chips' => 0];
    }
    if ($strength >= 5) return ['do' => 'raise', 'chips' => min($stack, $toCall + $bet * XERIC_TABLE_RAISE)];
    if ($strength >= 2) return ['do' => 'call', 'chips' => $toCall];
    if ($strength >= 1 && $toCall <= $bet) return ['do' => 'call', 'chips' => $toCall];
    return ['do' => 'fold', 'chips' => 0];
}

// ---------------------------------------------------------------------------
// A hand, start to finish
// ---------------------------------------------------------------------------

/**
 * Deal one hand and play it out. No model, no database, no side effects.
 *
 * Kept pure so it can be tested ten thousand times in a second, and so the
 * thing that WRITES (xeric_table_settle) is small enough to read in one go.
 * The transcript it returns is a list of plain sentences, which is what the
 * hour and the table talk are both built from.
 *
 * @param array<int,string> $seats in order, dealer last
 * @param array<string,int> $stacks
 * @return array{pot:int,board:array,hole:array,folded:array,winners:array,
 *               paid:array,stacks:array,showdown:bool,log:array}
 */
function xeric_table_hand(array $t, array $seats, array $stacks, int $bet, int $seed): array
{
    $seats = array_values(array_filter($seats, fn($h) => ($stacks[$h] ?? 0) > 0));
    if (count($seats) < XERIC_TABLE_MIN) {
        return ['pot' => 0, 'board' => [], 'hole' => [], 'folded' => [], 'winners' => [],
                'paid' => [], 'stacks' => $stacks, 'showdown' => false,
                'log' => ['Not enough of them still had chips.']];
    }

    $deck = xeric_deck($seed);
    $d = 0;
    $hole = [];
    foreach ($seats as $h) $hole[$h] = [$deck[$d++], $deck[$d++]];

    $pot = 0;
    $in  = array_fill_keys($seats, true);
    $log = [];

    // The ante, so there is always something to play for. A table where
    // everybody can fold for free is a table where nothing ever happens.
    foreach ($seats as $h) {
        $ante = min($bet, $stacks[$h]);
        $stacks[$h] -= $ante;
        $pot += $ante;
    }
    $log[] = 'Everybody antes ' . $bet . '.';

    $board = [];
    for ($street = 0; $street < XERIC_TABLE_STREETS; $street++) {
        if ($street === 1) { $board[] = $deck[$d++]; $board[] = $deck[$d++]; $board[] = $deck[$d++]; }
        elseif ($street > 1) { $board[] = $deck[$d++]; }
        if ($street > 0) $log[] = 'The board: ' . xeric_cards_name($board) . '.';

        $owed = array_fill_keys($seats, 0);
        $high = 0;
        // One pass, then one more only for anybody a raise left behind. Two
        // passes is enough for penny-ante and it cannot loop: the second pass
        // never raises.
        for ($pass = 0; $pass < 2; $pass++) {
            foreach ($seats as $i => $h) {
                if (!$in[$h] || $stacks[$h] <= 0) continue;
                if (count(array_filter($in)) < 2) break 2;
                $toCall = $high - $owed[$h];
                if ($pass === 1 && $toCall <= 0) continue;

                $m = xeric_table_move($t, $h, $hole[$h], $board, $toCall, $stacks[$h], $bet,
                                      $seed + $street * 97 + $i * 13 + $pass * 7);
                if ($pass === 1 && $m['do'] === 'raise') $m = ['do' => 'call', 'chips' => $toCall];

                $name = xeric_world_name($t, $h) ?: $h;
                if ($m['do'] === 'fold') { $in[$h] = false; $log[] = $name . ' folds.'; continue; }
                if ($m['do'] === 'check') { $log[] = $name . ' checks.'; continue; }

                $put = min($m['chips'], $stacks[$h]);
                $stacks[$h] -= $put;
                $owed[$h]   += $put;
                $pot        += $put;
                $high = max($high, $owed[$h]);
                $log[] = $name . ' ' . ($m['do'] === 'raise' ? 'raises' : ($toCall > 0 ? 'calls' : 'bets'))
                       . ' ' . $put . '.';
            }
        }
        if (count(array_filter($in)) < 2) break;
    }

    $live = array_values(array_keys(array_filter($in)));
    $showdown = count($live) > 1;
    if ($showdown) {
        $hands = [];
        foreach ($live as $h) $hands[$h] = array_merge($hole[$h], $board);
        $sd = xeric_showdown($hands);
        $winners = $sd['winners'];
        $log[] = count($winners) === 1
            ? (xeric_world_name($t, $winners[0]) ?: $winners[0]) . ' shows '
              . xeric_hand_name($sd['scores'][$winners[0]]) . '.'
            : 'They split it.';
    } else {
        $winners = $live;
        if ($winners !== []) {
            $log[] = (xeric_world_name($t, $winners[0]) ?: $winners[0]) . ' takes it, nobody saw a card.';
        }
    }

    $paid = xeric_pot_split($pot, $winners);
    foreach ($paid as $h => $n) $stacks[$h] += $n;

    return ['pot' => $pot, 'board' => $board, 'hole' => $hole,
            'folded' => array_values(array_keys(array_filter($in, fn($v) => !$v))),
            'winners' => $winners, 'paid' => $paid, 'stacks' => $stacks,
            'showdown' => $showdown, 'log' => $log];
}

/**
 * A whole night, and what it did to the world.
 *
 * The only function here that writes. Everything above is arithmetic, so this
 * is small on purpose — the whole night lands in one transaction or none of it
 * does, and a night that dies halfway leaves nobody mysteriously richer.
 *
 * @return array{hands:int,net:array<string,int>,log:array,stacks:array,owed:array}
 */
function xeric_table_settle(array $t, PDO $db, array $table, array $seats, int $hands,
                            int $seed, ?int $at = null, int $epoch = 0): array
{
    $seats  = array_values($seats);
    if (count($seats) < XERIC_TABLE_MIN) {
        throw new RuntimeException('table: ' . count($seats) . ' is not a game');
    }
    if (count($seats) > XERIC_TABLE_MAX) {
        throw new RuntimeException('table: ' . count($seats) . ' at one table is a tournament');
    }

    $at     = $at ?? xeric_state_time();
    $buy    = (int)$table['buy_in'];
    $stacks = array_fill_keys($seats, $buy);
    $log    = [];
    $played = 0;

    for ($i = 0; $i < max(1, $hands); $i++) {
        $standing = array_values(array_filter($seats, fn($h) => $stacks[$h] > 0));
        if (count($standing) < XERIC_TABLE_MIN) { $log[] = 'That was the last of it.'; break; }
        // The deal rotates, so the same person is not first to act all night.
        $order = array_merge(array_slice($standing, $i % count($standing)),
                             array_slice($standing, 0, $i % count($standing)));
        $r = xeric_table_hand($t, $order, $stacks, (int)$table['bet'], $seed + $i * 1009);
        $stacks = $r['stacks'];
        $log = array_merge($log, $r['log']);
        $played++;
    }

    $net = [];
    foreach ($seats as $h) $net[$h] = $stacks[$h] - $buy;

    $db->beginTransaction();
    try {
        // THE LEDGER, if this table pays one. A hand won is reported as a FACT
        // — which is exactly the gap the prose matcher refuses to fill, because
        // "hand_won" reduces to "hand" and every hour has hands in it.
        $eco = (string)$table['economy'];
        $short = [];
        if ($eco !== '') {
            foreach ($net as $h => $n) {
                if ($n === 0) continue;
                $was = xeric_ledger_of($db, $eco, $h);
                // WHAT SOMEBODY CANNOT COVER IS NOT ROUNDED AWAY. Clamping a
                // losing night at zero looks harmless and is not: a person at
                // nothing who loses five and wins five back is up five, and a
                // season of Thursdays quietly mints money. What they could not
                // pay becomes what they OWE, which is the constructs-beat-
                // counters argument arriving exactly where it was predicted to.
                if ($was + $n < 0) $short[$h] = -($was + $n);
                xeric_arc_set($db, $h, 'economy.' . $eco, (string)max(0, $was + $n), $at);
            }
        }

        // AND WHAT IT DID BETWEEN THEM. Taking money off somebody is not a
        // number going down, it is a thing that happened between two people —
        // so the winner cools very slightly with whoever paid for it. Warmth,
        // not trust, so a night costs a fraction of a point and a season of
        // Thursdays costs one: this is a faint thumb, not a punishment for
        // playing well.
        $winners = array_keys(array_filter($net, fn($n) => $n > 0));
        $losers  = array_keys(array_filter($net, fn($n) => $n < 0));
        foreach ($losers as $l) {
            foreach ($winners as $w) {
                if ($l === $w) continue;
                xeric_trust_rub($db, (string)$l, (string)$w, -1, $at);
            }
        }

        // AND WHAT THEY COULD NOT COVER, owed to whoever took it — a row that
        // knows what it was for, settled by a favour going the other way, and
        // faded if it is carried long enough. Owed to the biggest winner, which
        // is not arbitrary: at a real table the person you are into is the
        // person holding your markers.
        if ($short !== [] && $winners !== []) {
            $top = $winners[0];
            foreach ($winners as $w) if ($net[$w] > $net[$top]) $top = $w;
            foreach ($short as $l => $owed) {
                if ((string)$l === (string)$top) continue;
                xeric_debt_form($t, $db, (string)$l, (string)$top,
                    'what he could not cover at ' . (string)$table['name'], ['epoch' => $epoch], null);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('table: the night could not be settled, ' . $e->getMessage(), 0, $e);
    }

    return ['hands' => $played, 'net' => $net, 'log' => $log, 'stacks' => $stacks,
            'owed' => $short];
}

/** How the night went, in a sentence somebody would say about it. */
function xeric_table_say(array $t, array $result): string
{
    $net = $result['net'];
    arsort($net);
    $up = array_key_first($net);
    $dn = array_key_last($net);
    if ($net[$up] <= 0) return 'Nobody came out ahead.';
    return (xeric_world_name($t, (string)$up) ?: (string)$up) . ' is up ' . $net[$up]
         . ($net[$dn] < 0 ? ', and ' . (xeric_world_name($t, (string)$dn) ?: (string)$dn)
                          . ' is down ' . abs($net[$dn]) : '') . '.';
}
