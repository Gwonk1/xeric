<?php
/**
 * Xeric — a confidence. The last needle, and not the one it looked like.
 *
 * `closeness` has sat in the kinds since they were written — "the distance
 * between them changed by a small, deniable amount" — armed by `attraction`,
 * `arcs` and `private_history`, and accumulating nothing. It was filed as the
 * intimacy economy and left alone for months because that reading makes it
 * delicate: an attraction counter is a thing the age floor has to stand in
 * front of forever, and a needle that needs a guard that badly is usually the
 * wrong needle.
 *
 * SO IT IS THE OTHER READING. Trust is "do I rely on you". Closeness is HOW
 * MUCH OF MYSELF YOU HAVE SEEN, which is a different axis entirely and a
 * better one: it is made of things told, it is asymmetric, it deepens by being
 * kept, and it has an obvious and devastating failure mode that this engine
 * already implements. A confidence is a thing one person told another and
 * nobody else. What happens when it stops being that is gossip, and gossip has
 * been here since constructs.php was written.
 *
 * ── WHY THIS IS A CONSTRUCT AND NOT A NUMBER ──────────────────────────────
 *
 * "Closeness 4" is unactionable. A row that says WHAT was told, WHO told it,
 * and whether it is still theirs can be rendered in either of their voices,
 * deepened by silence, and broken by a sentence — and when it breaks, the
 * thing that broke it is nameable. Same argument as every other construct
 * here, and this is the case that makes it hardest to argue with.
 *
 * ── THE FLOOR IS STRUCTURAL, AND IT IS NOT ABOUT SEX ──────────────────────
 *
 * Both parties must be adults, checked from the integer age by the engine's
 * own xeric_is_minor(), which fails closed on a missing or malformed age. That
 * is NOT because a confidence is sexual — it is not, and nothing in this file
 * is — it is because `closeness` is armed by `attraction`, and a system reached
 * through that door stays behind the same fence whatever it is used for. A
 * gate that is only correct while nobody repurposes the thing behind it is not
 * a gate.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/players.php';
require_once __DIR__ . '/trust.php';

/** How long a confidence stays fresh enough to be worth mentioning. */
const XERIC_CONFIDE_FRESH = 21 * 24 * 3600;

/** How long a confidence the person at the centre let out stays mendable, world seconds. */
const XERIC_CONFIDE_REPAIR = 7 * 24 * 3600;

/** What keeping one is worth, and what breaking one costs. */
const XERIC_CONFIDE_KEPT   = 1;
const XERIC_CONFIDE_BROKEN = 3;

/** Does this world keep private histories at all? */
function xeric_confide_armed(array $t): bool
{
    $armed = (array)($t['forge']['armed'] ?? []);
    if ($armed === []) return true;                  // predates arming, same as the others
    foreach (['attraction', 'private_history', 'arcs', 'confidences', 'secrets'] as $s) {
        if (in_array($s, $armed, true)) return true;
    }
    return false;
}

/** Is this person old enough to be either party? Fails closed. */
function xeric_confide_adult(array $t, string $handle): bool
{
    $c = xeric_world_character($t, $handle);
    return $c !== null && !xeric_is_minor($c);
}

/** Everything one person is carrying for somebody else, oldest first. */
function xeric_confides_for(PDO $db, string $handle): array
{
    $out = [];
    foreach (xeric_arcs_for($db, $handle) as $k => $v) {
        if (!str_starts_with((string)$k, 'told.')) continue;
        $row = json_decode((string)$v, true);
        if (!is_array($row) || !isset($row['what'], $row['state'])) continue;
        $row['key'] = (string)$k;
        $out[] = $row;
    }
    usort($out, fn($a, $b) => (int)($a['at'] ?? 0) <=> (int)($b['at'] ?? 0));
    return $out;
}

/**
 * One of them told the other something. Returns the arc key, or null.
 *
 * Stored on the person who was TOLD, because that is where it is a burden: the
 * teller knows what they said, and the one carrying it is the one who can give
 * it away. `by` is the teller — a handle, or a player number when it was
 * somebody at the centre, which is the one asymmetry this file needs.
 */
function xeric_confide_form(array $t, PDO $db, string $told, string $what, array $now,
                            string $byHandle = '', int $byPlayer = 0, ?int $at = null): ?string
{
    if (!xeric_confide_armed($t)) return null;
    $what = trim(preg_replace('/\s+/u', ' ', $what) ?? '');
    if ($told === '' || $what === '') return null;
    if (!xeric_confide_adult($t, $told)) return null;

    // The teller: somebody in the town, or somebody at the centre. Never both,
    // and never neither.
    if ($byHandle !== '') {
        if ($byHandle === $told || !xeric_confide_adult($t, $byHandle)) return null;
        $byPlayer = 0;
    } elseif ($byPlayer < XERIC_PLAYER_FIRST) {
        return null;
    }

    // ONE ROW PER THING, not per telling. Somebody who says the same thing
    // twice has not told you two secrets, and a count that went up every time
    // it came up in conversation would make repetition the way to be close to
    // people — which is the failure mode every affection meter has.
    foreach (xeric_confides_for($db, $told) as $c) {
        if (mb_strtolower((string)$c['what']) === mb_strtolower($what)
            && (string)($c['by'] ?? '') === $byHandle
            && (int)($c['p'] ?? 0) === $byPlayer) {
            return (string)$c['key'];
        }
    }

    $key = 'told.' . (1 + count(xeric_confides_for($db, $told)));
    xeric_arc_set($db, $told, $key, json_encode(
        ['what' => mb_substr($what, 0, 120), 'by' => $byHandle, 'p' => $byPlayer,
         'at' => (int)($now['epoch'] ?? 0), 'state' => 'kept'] , JSON_UNESCAPED_UNICODE), $at);

    // Being told something is itself the closeness moving. Warmth, not trust:
    // one confidence is not a friendship, and four of them are a point.
    if ($byHandle !== '') xeric_trust_rub($db, $told, $byHandle, XERIC_CONFIDE_KEPT, $at);
    else                  xeric_trust_contact($db, $told, XERIC_CONFIDE_KEPT, $at, $byPlayer);

    return $key;
}

/**
 * IT GOT OUT. Somebody repeated it, and the person who told them finds out.
 *
 * This is the whole reason a confidence is worth modelling: a thing that can
 * only be kept or broken is a thing with stakes, and the breaking is nameable
 * — "you told Dot about the letter" — rather than a number quietly falling.
 *
 * Costs more than keeping it earns, by a lot, and through the EARNED path
 * rather than warmth: ordinary conversation cannot undo this. That asymmetry
 * is the point. Trust is slow to build and quick to lose because that is true.
 *
 * @return bool whether there was a confidence to break
 */
function xeric_confide_break(array $t, PDO $db, string $told, string $key, array $now,
                             ?int $at = null): bool
{
    $raw = xeric_arc_get($db, $told, $key);
    if ($raw === null) return false;
    $row = json_decode((string)$raw, true);
    if (!is_array($row) || (string)($row['state'] ?? '') !== 'kept') return false;

    $row['state']  = 'broken';
    $row['out_at'] = (int)($now['epoch'] ?? 0);
    xeric_arc_set($db, $told, $key, json_encode($row, JSON_UNESCAPED_UNICODE), $at);

    $by = (string)($row['by'] ?? '');
    if ($by !== '') {
        // The teller thinks less of the person who let it out.
        xeric_trust_earn($db, $by, -XERIC_CONFIDE_BROKEN, $at, $told);
    }
    // AND WHEN THE TELLER WAS SOMEBODY AT THE CENTRE, NOTHING ON THIS AXIS
    // MOVES — which is a correction, not an omission.
    //
    // This branch used to write `xeric_trust_earn($db, $told, -3, …, null, $p)`,
    // and the comment beside it said that was "the carrier's standing with the
    // person they betrayed". It is not. That row is keyed `trust.p<N>` ON THE
    // CARRIER, and trust.php defines it as what THIS PERSON HAS DECIDED ABOUT
    // YOU. So the sentence the engine was actually writing was: Ruth told
    // everybody your secret, therefore Ruth trusts you three less. The branch
    // three lines up does the opposite of that, correctly, and the two disagreed
    // about direction for as long as both existed.
    //
    // The row it wanted — what the PLAYER now thinks of Ruth — does not exist,
    // and should not: the engine keeps the minds of the town, and the one mind
    // it must never presume to keep is the mind of the person playing. You do
    // think less of her. That is yours, and it happens in your head, not in a
    // column.
    //
    // So the cost is not a number here. It is the row itself, which is now
    // `broken` and which her own prompt reads back to her every turn — "you know
    // how that looks" — and it is the town, which is repeating the thing she was
    // trusted with, because that is how this was detected in the first place.
    // Reputation is emergent in this engine by design, and this is exactly the
    // case that design was for.
    return true;
}

/**
 * Whatever of these the gossip ripple has just started repeating is broken.
 *
 * The seam that makes this real rather than a second bookkeeping system: the
 * ripple already spreads OBSERVABLES only, and a confidence turning up in one
 * means somebody said it out loud. Matched on the substantial words, because
 * gossip retells rather than quotes.
 *
 * @param array<int,string> $talk what the town is currently saying
 * @return int how many got out
 */
function xeric_confide_sweep(array $t, PDO $db, array $talk, array $now, ?int $at = null): int
{
    if (!xeric_confide_armed($t) || $talk === []) return 0;
    require_once __DIR__ . '/ledger.php';                 // its stemmer, again

    $said = [];
    foreach ($talk as $line) foreach (array_keys(xeric_ledger_words((string)$line)) as $w) $said[$w] = true;
    if ($said === []) return 0;

    $n = 0;
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        foreach (xeric_confides_for($db, $h) as $row) {
            if ((string)$row['state'] !== 'kept') continue;
            $mine = array_keys(xeric_ledger_words((string)$row['what']));
            if ($mine === []) continue;
            // ALL of the distinctive words, not any: "the letter" turning up in
            // a sentence about a letter is a coincidence, and a coincidence
            // that costs three points of trust is worse than no feature.
            $hit = true;
            foreach ($mine as $w) if (!isset($said[$w])) { $hit = false; break; }
            if ($hit && xeric_confide_break($t, $db, $h, (string)$row['key'], $now, $at)) $n++;
        }
    }
    return $n;
}

/**
 * What this person is carrying, in their own prompt.
 *
 * Coarse and clockless like every construct block. Two halves that read
 * completely differently from the inside: what somebody trusted you with, and
 * what you trusted somebody with and watched get out.
 */
function xeric_confide_block(array $t, PDO $db, string $handle,
                             int $player = XERIC_PLAYER_FIRST): string
{
    if (!xeric_confide_armed($t)) return '';
    $now  = [];
    $keep = [];
    $out  = [];
    foreach (xeric_confides_for($db, $handle) as $c) {
        $who = (string)($c['by'] ?? '') !== ''
            ? (xeric_world_name($t, (string)$c['by']) ?: (string)$c['by'])
            : xeric_player_name($db, (int)($c['p'] ?? XERIC_PLAYER_FIRST), $t);
        if ((string)$c['state'] === 'kept') {
            $keep[] = '- ' . $who . ' told you ' . (string)$c['what']
                    . '. Nobody else has it from you.';
        } else {
            $out[] = '- ' . $who . ' told you ' . (string)$c['what']
                   . ', and it got out. You know how that looks.';
        }
    }

    // AND WHAT THEY TOLD SOMEBODY ELSE THAT GOT OUT — the other side, and the
    // one with the feeling in it. Read off the carriers rather than stored
    // twice, so there is one row per confidence and no way for the two halves
    // to disagree.
    $mine = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c2) {
        $h2 = (string)($c2['handle'] ?? '');
        if ($h2 === '' || $h2 === $handle) continue;
        foreach (xeric_confides_for($db, $h2) as $c) {
            if ((string)($c['by'] ?? '') !== $handle) continue;
            if ((string)$c['state'] !== 'broken') continue;
            $mine[] = '- You told ' . (xeric_world_name($t, $h2) ?: $h2) . ' ' . (string)$c['what']
                    . ', and it is going round. You have not said anything about it.';
        }
    }

    // AND WHAT THEY ASKED THE PERSON AT THE CENTRE TO KEEP. Stored on the
    // teller, because the player has no head this engine keeps; rendered here
    // and nowhere else, so the thing itself reaches exactly one prompt.
    foreach (xeric_confide_told_player($db, $handle) as $c) {
        if ((int)($c['p'] ?? XERIC_PLAYER_FIRST) !== $player) continue;
        $user = xeric_player_name($db, $player, $t);
        $head = '- You told ' . $user . ' ' . (string)$c['what'] . ' and asked them to keep it';
        $hearer = xeric_world_name($t, (string)($c['heard_by'] ?? '')) ?: 'somebody';
        switch ((string)$c['state']) {
            case 'kept':     $mine[] = $head . '. As far as you know, they have.'; break;
            case 'broken':   $mine[] = $head . '. It got back to you that ' . $user . ' told ' . $hearer
                                     . '. You have not said anything to ' . $user . ' about it.'; break;
            case 'mended':   $mine[] = $head . '. It got out, and ' . $user . ' owned up to it. Where that leaves you is yours.'; break;
            case 'hardened': $mine[] = $head . '. It got out, and ' . $user . ' never said a word about it.'; break;
        }
    }

    $blocks = [];
    if ($keep !== [] || $out !== []) $blocks[] = "WHAT YOU ARE KEEPING FOR SOMEBODY\n"
        . implode("\n", array_merge($keep, $out));
    if ($mine !== []) $blocks[] = "WHAT YOU TOLD SOMEBODY\n" . implode("\n", $mine);
    return implode("\n\n", $blocks);
}


// ---------------------------------------------------------------------------
// THE PERSON AT THE CENTRE IS TOLD SOMETHING — a user-created wall
// ---------------------------------------------------------------------------
//
// The highest-stakes thing this engine can offer: somebody tells you a thing
// and asks you to keep it, and you can. Or not. The row is a wall the PLAYER
// made by listening: the fact, who told it, and who must not hear it (named,
// or — when nobody was named — anybody at all). Formed from the chat
// extractor's proposal and code-checked twice: the character's own words must
// carry an explicit ask ("don't tell", "between us", "keep this to
// yourself"), and those words must actually be in what they said. Broken when
// the player repeats it, in their own words, to somebody who must not hear it;
// mended by owning up inside the window; hardened into a grudge when not.

/** Does this line ask for it to be kept? Explicit asks only — a hint is not an ask. */
function xeric_confide_marked(string $quote): bool
{
    $q = ' ' . mb_strtolower(str_replace(['’', '‘'], "'", $quote)) . ' ';
    foreach ([
        "don't tell", 'do not tell', "don't say anything", "don't repeat", 'do not repeat',
        'between us', 'between you and me', 'between ourselves',
        'keep this to yourself', 'keep it to yourself', 'keep that to yourself', 'keep this quiet',
        'keep it quiet', "you didn't hear it from me", "you didn't hear this from me",
        'not a word to', "promise you won't tell", 'just between',
    ] as $w) {
        if (str_contains($q, $w)) return true;
    }
    return false;
}

/** What a teller asked the person at the centre to keep, oldest first. */
function xeric_confide_told_player(PDO $db, string $teller): array
{
    $out = [];
    foreach (xeric_arcs_prefixed($db, $teller, 'confided.') as $k => $v) {
        $row = json_decode((string)$v, true);
        if (!is_array($row) || !isset($row['what'], $row['state'])) continue;
        $row['key'] = (string)$k;
        $out[] = $row;
    }
    usort($out, fn($a, $b) => (int)($a['at'] ?? 0) <=> (int)($b['at'] ?? 0));
    return $out;
}

/** Cast handles named in these words (first name, full name or handle). */
function xeric_confide_named(array $t, string $text, string $except = ''): array
{
    $out = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || $h === $except) continue;
        $words = preg_split('/\s+/u', trim(xeric_world_name($t, $h))) ?: [];
        if (count($words) > 1 && in_array(mb_strtolower(rtrim((string)$words[0], '.')), ['pastor', 'father', 'reverend', 'mr', 'mrs', 'ms', 'dr', 'doc'], true)) array_shift($words);
        $first = (string)($words[0] ?? '');
        foreach (array_filter([$first, $h]) as $n) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($n, '/') . '(?![\p{L}])/ui', $text)) { $out[] = $h; break; }
        }
    }
    return $out;
}

/**
 * The teller asked the person at the centre to keep something. Returns the
 * arc key, or null.
 *
 * $p is the extractor's proposal: ['quote' => the CHARACTER's exact words,
 * 'what' => the thing itself, short, 'not' => [names who must not hear]].
 * $said is everything the teller actually said in the window: the quote has
 * to be in it. Who must not hear is read off the quote first ("don't tell
 * Harlan") and then off the proposal, and only names that are people here
 * count; nobody named means nobody.
 */
function xeric_confide_to_player(array $t, PDO $db, string $teller, ?array $p, array $now,
                                 int $player = XERIC_PLAYER_FIRST, string $said = ''): ?string
{
    if (!is_array($p) || !xeric_confide_armed($t)) return null;
    $quote = trim((string)($p['quote'] ?? ''));
    $what  = trim(preg_replace('/\s+/u', ' ', (string)($p['what'] ?? '')) ?? '');
    if ($quote === '' || $what === '') return null;
    if (!xeric_confide_marked($quote)) return null;                    // no ask, no confidence
    $norm = fn(string $s): string => mb_strtolower(trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s)));
    if ($said !== '' && !str_contains(' ' . $norm($said) . ' ', ' ' . $norm($quote) . ' ')) return null;
    if (!xeric_confide_adult($t, $teller)) return null;                // the same floor, the same reason

    $not = [];
    if (preg_match_all('/(?:tell|to|from|word to)\s+([\p{Lu}][\p{L}]+)/u', $quote, $mm)) {
        $not = xeric_confide_named($t, implode(' ', $mm[1]), $teller);
    }
    foreach ((array)($p['not'] ?? []) as $n) $not = array_merge($not, xeric_confide_named($t, (string)$n, $teller));
    $not = array_values(array_unique($not));

    foreach (xeric_confide_told_player($db, $teller) as $c) {
        if (mb_strtolower((string)$c['what']) === mb_strtolower($what) && (int)($c['p'] ?? 0) === $player) {
            return (string)$c['key'];
        }
    }
    $key = 'confided.' . (1 + count(xeric_confide_told_player($db, $teller)));
    xeric_arc_set($db, $teller, $key, json_encode([
        'what' => mb_substr($what, 0, 120), 'quote' => mb_substr($quote, 0, 200), 'p' => $player,
        'not' => $not, 'at' => (int)($now['epoch'] ?? 0), 'state' => 'kept',
    ], JSON_UNESCAPED_UNICODE));
    // Being trusted with something is itself the closeness moving.
    xeric_trust_contact($db, $teller, XERIC_CONFIDE_KEPT, null, $player);
    return $key;
}

/**
 * The person at the centre said it to somebody. Every kept confidence whose
 * distinctive words ALL turn up in what they said to $hearer — and $hearer is
 * somebody who must not hear it (named, or anybody but the teller when
 * nobody was) — is broken. The teller's trust in them takes the hit through
 * the earned path, which is the right row this time: trust.p<N> ON THE TELLER
 * is what the teller has decided about you.
 *
 * @param string[] $userLines what the person at the centre said to $hearer
 * @return int how many broke
 */
function xeric_confide_repeat(array $t, PDO $db, string $hearer, array $userLines, array $now,
                              int $player = XERIC_PLAYER_FIRST): int
{
    if (!xeric_confide_armed($t) || $userLines === []) return 0;
    require_once __DIR__ . '/ledger.php';
    $said = [];
    foreach ($userLines as $l) foreach (array_keys(xeric_ledger_words((string)$l)) as $w) $said[$w] = true;
    if ($said === []) return 0;

    $n = 0;
    $epoch = (int)($now['epoch'] ?? 0);
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $teller = (string)($c['handle'] ?? '');
        if ($teller === '' || $teller === $hearer) continue;
        foreach (xeric_confide_told_player($db, $teller) as $row) {
            if ((string)$row['state'] !== 'kept' || (int)($row['p'] ?? 0) !== $player) continue;
            $not = array_map('strval', (array)($row['not'] ?? []));
            if ($not !== [] && !in_array($hearer, $not, true)) continue;
            $mine = array_keys(xeric_ledger_words((string)$row['what']));
            if ($mine === []) continue;
            $hit = true;
            foreach ($mine as $w) if (!isset($said[$w])) { $hit = false; break; }
            if (!$hit) continue;

            $key = (string)$row['key'];
            unset($row['key']);
            $row['state'] = 'broken'; $row['heard_by'] = $hearer; $row['out_at'] = $epoch;
            xeric_arc_set($db, $teller, $key, json_encode($row, JSON_UNESCAPED_UNICODE));
            xeric_trust_earn($db, $teller, -XERIC_CONFIDE_BROKEN, null, null, $player);
            $user = xeric_player_name($db, $player, $t);
            xeric_memory_add($db, $teller, $user . ' told ' . xeric_world_name($t, $hearer) . ' what '
                . xeric_world_name($t, $teller) . ' had asked ' . $user . ' to keep.', 'construct',
                ['confided' => $key, 'heard_by' => $hearer], $epoch);
            // The inspector's answer for the highest-stakes event there is.
            xeric_world_state_set($db, 'why:confided:' . $teller . ':' . $key, json_encode([
                'kind' => 'broken_confidence',
                'why'  => xeric_world_name($t, $teller) . ' told ' . $user . ' ' . (string)$row['what']
                        . ' and asked them to keep it. ' . $user . ' repeated it to ' . xeric_world_name($t, $hearer) . '.',
            ], JSON_UNESCAPED_UNICODE));
            $n++;
        }
    }
    return $n;
}

/** Owning up, inside the window: the breach is mended and one notch comes back. */
function xeric_confide_mend(array $t, PDO $db, string $teller, array $now, int $player = XERIC_PLAYER_FIRST): ?string
{
    $epoch = (int)($now['epoch'] ?? 0);
    foreach (array_reverse(xeric_confide_told_player($db, $teller)) as $row) {
        if ((string)$row['state'] !== 'broken' || (int)($row['p'] ?? 0) !== $player) continue;
        if ($epoch > (int)($row['out_at'] ?? 0) + XERIC_CONFIDE_REPAIR) continue;
        $key = (string)$row['key'];
        unset($row['key']);
        $row['state'] = 'mended'; $row['mended_at'] = $epoch;
        xeric_arc_set($db, $teller, $key, json_encode($row, JSON_UNESCAPED_UNICODE));
        xeric_trust_earn($db, $teller, +1, null, null, $player);
        xeric_memory_add($db, $teller, xeric_player_name($db, $player, $t) . ' owned up to letting out what '
            . xeric_world_name($t, $teller) . ' had asked them to keep.', 'construct', ['confided' => $key], $epoch);
        return $key;
    }
    return null;
}

/**
 * The player's side, for a voice that speaks to the player and has full
 * canon (the Narrator, the book): "Ruth told you X and asked you to keep it."
 * Never for a character's prompt — that is what the teller's block is for.
 *
 * @return string[]
 */
function xeric_confide_player_lines(array $t, PDO $db, int $player = XERIC_PLAYER_FIRST): array
{
    $out = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        foreach (xeric_confide_told_player($db, $h) as $row) {
            if ((int)($row['p'] ?? 0) !== $player) continue;
            $line = xeric_world_name($t, $h) . ' told you ' . (string)$row['what'] . ' and asked you to keep it';
            if ((string)$row['state'] === 'kept') $out[] = $line . '.';
            elseif ((string)$row['state'] === 'broken') $out[] = $line . '. You told ' . xeric_world_name($t, (string)($row['heard_by'] ?? '')) . '.';
        }
    }
    return $out;
}
