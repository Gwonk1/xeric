<?php
/**
 * Xeric — the ledgers. The counters a world declared, finally counting.
 *
 * `economies` has been the most completely specified dead thing in the
 * schema: a key, a label, a counter mode, `earned_by` naming what earns it,
 * a board with a podium and who may see it, ground truth, rules, framing.
 * xeric_state_counters() reads those counters. renderers/economy.php renders
 * them into prompts. And nothing in the engine has ever written one, so every
 * board in every world has been empty since the day boards existed — a
 * casserole ledger nobody is on, with three rules about how it works.
 *
 * WHAT EARNS A POINT IS WHAT THE WORLD SAID EARNS ONE. `earned_by` is
 * phrases — `user_event:dish_delivered`, `user_event:ride_given` — and an
 * hour is prose about what happened, so the match is on WORDS: an hour whose
 * own words carry a distinctive word from an `earned_by` phrase credits that
 * ledger. No new schema, no migration, and it works on every world ever
 * forged rather than only on the ones built after tonight.
 *
 * GENEROUS ON PURPOSE, and this is the opposite of the walls' posture. A wall
 * that matches too loosely leaks a secret; a ledger that matches too loosely
 * credits somebody a casserole they only helped carry. One of those is a
 * catastrophe and the other is a small town. So the bar here is one
 * distinctive word, where the wall's bar is half the sentence.
 *
 * IT COUNTS PEOPLE, NOT THE PLAYER. The player has no handle and no dossier;
 * what they earn is the expectation machinery's business. This is the town
 * keeping score of itself, which is what makes a board worth reading — the
 * point of the casserole ledger is that Walt is winning it and would never
 * say so.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';

/**
 * The substantial words of a phrase, stemmed to four characters.
 *
 * Stemmed crudely on purpose: "delivered" in a ledger against "delivering" in
 * an hour is the same act, and a stemmer clever enough to know that would
 * also be clever enough to match "deliver" against "deliverance".
 */
function xeric_ledger_words(string $s): array
{
    $s = mb_strtolower((string)preg_replace('/^user_event:/i', '', trim($s)));
    $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $out = [];
    foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
        if (mb_strlen($w) >= 4) $out[mb_substr($w, 0, 4)] = mb_strlen($w);
    }
    return $out;
}

/**
 * Which of this world's ledgers an hour's words earn a point on.
 *
 * EVERY SUBSTANTIAL WORD OF THE PHRASE, not any of them. "dish delivered"
 * earns on an hour that has both, and not on every hour that mentions a dish
 * — the first is the act the world described and the second is a kitchen.
 *
 * And a phrase that reduces to ONE short word earns nothing at all. The
 * Thursday pot is earned by `hand_won`, which reduces to "hand", and the
 * still-life rule puts hands on objects in nearly every hour this engine
 * writes: crediting that would have the poker pot paying out for pouring
 * coffee. A vague `earned_by` is better unearned than wrong, and the fix for
 * it belongs in the world, not in a looser matcher here.
 *
 * @return string[] economy keys
 */
function xeric_ledger_credits(array $t, string $text): array
{
    $have = xeric_ledger_words($text);
    if ($have === []) return [];

    $out = [];
    foreach ((array)($t['economies'] ?? []) as $eco) {
        $key = (string)($eco['key'] ?? '');
        if ($key === '') continue;
        foreach ((array)($eco['earned_by'] ?? []) as $phrase) {
            $want = xeric_ledger_words((string)$phrase);
            if ($want === []) continue;
            if (count($want) === 1 && max($want) < 5) continue;   // one short word is not evidence

            $all = true;
            foreach (array_keys($want) as $stem) {
                if (!isset($have[$stem])) { $all = false; break; }
            }
            if ($all) { $out[] = $key; break; }
        }
    }
    return $out;
}

/** What one character stands at on one ledger. */
function xeric_ledger_of(PDO $db, string $key, string $handle): int
{
    return xeric_arc_int($db, $handle, 'economy.' . $key, 0);
}

/**
 * Credit an hour: every ledger its words earned, every person who was in it.
 *
 * ONE POINT PER LEDGER PER HOUR PER PERSON. An hour is one hour however many
 * of a ledger's phrases it happens to echo, and a ledger that could be earned
 * three times by one afternoon would be a ledger somebody could farm.
 *
 * @return array<string,int> the ledgers credited, and how many people got one
 */
function xeric_ledger_step(PDO $db, array $t, array $handles, string $text, ?int $at = null): array
{
    $credited = [];
    foreach (xeric_ledger_credits($t, $text) as $key) {
        $n = 0;
        foreach ($handles as $h) {
            $h = (string)$h;
            if ($h === '' || xeric_world_character($t, $h) === null) continue;
            xeric_arc_bump($db, $h, 'economy.' . $key, 1, $at);
            $n++;
        }
        if ($n > 0) $credited[$key] = $n;
    }
    return $credited;
}

/**
 * The board, in the order a board is read: highest first, ties by cast order
 * so two people on three casseroles do not swap places every repaint.
 *
 * @return array<int,array{handle:string,name:string,n:int}>
 */
function xeric_ledger_board(PDO $db, array $t, string $key, int $top = 0): array
{
    $rows = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $i => $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $n = xeric_ledger_of($db, $key, $h);
        if ($n <= 0) continue;
        $rows[] = ['handle' => $h, 'name' => (string)($c['display_name'] ?? $h), 'n' => $n, 'i' => $i];
    }
    usort($rows, fn($a, $b) => $b['n'] <=> $a['n'] ?: $a['i'] <=> $b['i']);
    foreach ($rows as $j => $r) unset($rows[$j]['i']);
    return $top > 0 ? array_slice($rows, 0, $top) : $rows;
}
