<?php
/**
 * Xeric — the economy renderer.
 *
 * Economies and boons are the generalization of a scoreboard nobody admits to
 * keeping: a counter, earn rules, payout rules, a visibility wall, and a
 * framing that says whether anyone will say it out loud. A casserole ledger, a
 * Thursday poker pot, dig permits, billable hours — one machine, different
 * nouns.
 *
 * The hard rule here is louder than in the bible renderer: a viewer walled off
 * an economy gets NOTHING for it. Not a redacted header, not "there is
 * something you don't know about", not a shorter list with a gap in it. The
 * block simply does not exist in their prompt, and if every block is walled the
 * whole render is the empty string.
 *
 * Deterministic: no clock, no rand, no LLM. Boards are re-sorted on every
 * render so an unsorted state array can't move the output around.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/../walls.php';

/**
 * @param array  $template        world-template.json, decoded
 * @param array  $viewer          ['handle' => …] — economies are always somebody's
 * @param array  $state           ['counters' => ['key' => ['viewer_count' => int,
 *                                'board' => [['name'=>…,'handle'=>…,'n'=>int], …]]],
 *                                'boons_due' => [ 'key' | ['key'=>…,'note'=>…,'expires_in_hours'=>…] ]]
 * @param string $effectiveRating sfw | mature | explicit
 */
function xeric_render_economy(array $template, array $viewer, array $state, string $effectiveRating): string
{
    $v     = xeric_viewer($template, $viewer);
    $walls = xeric_viewer_walls($template, $v);
    $eff   = $effectiveRating;

    $visible = [];   // economy key => economy, in template order
    $blocks  = [];

    if (!xeric_hidden($walls, 'economies')) {
        foreach ($template['economies'] ?? [] as $eco) {
            $key = (string)($eco['key'] ?? '');
            if ($key === '') continue;
            if (!xeric_rating_allows($eff, $eco)) continue;
            if (xeric_hidden($walls, 'economies.' . $key)) continue;
            $visible[$key] = $eco;
            $blocks[] = xeric_economy_block($eco, $v, (array)($state['counters'][$key] ?? []), $eff);
        }
    }

    $boons = xeric_economy_boons($template, $walls, $v, $state, $eff, $visible);
    if ($boons) $blocks[] = $boons;

    $out = xeric_blocks($blocks);
    return $out ? implode("\n", $out) . "\n" : '';
}

// ---------------------------------------------------------------------------

function xeric_economy_block(array $eco, array $v, array $counter, string $eff): array
{
    $key   = (string)$eco['key'];
    $label = trim((string)($eco['label'] ?? '')) ?: str_replace('_', ' ', $key);

    $b   = [];
    $b[] = strtoupper($label);

    // Subconscious framing goes first so the model reads the posture before the
    // numbers. Either an explicit flag or the doc's prose `framing` field.
    if (xeric_economy_subconscious($eco)) {
        $b[] = 'None of this is ever said out loud. Nobody names it, nobody admits to counting, and anybody asked directly would honestly deny it. It is still exactly true, and it still decides how people move.';
    } elseif (($f = xeric_text($eco['framing'] ?? '')) !== '') {
        $b[] = xeric_sentence($f);
    }

    // Ground truth: flat declarative canon. No hedging, no "it is said that".
    foreach (xeric_rating_filter((array)($eco['ground_truth'] ?? []), $eff) as $g) {
        $t = xeric_text($g);
        if ($t !== '') $b[] = xeric_sentence($t);
    }

    $rules = [];
    foreach (xeric_rating_filter((array)($eco['rules'] ?? []), $eff) as $r) {
        $t = xeric_text($r);
        if ($t !== '') $rules[] = '- ' . xeric_sentence($t);
    }
    foreach (xeric_economy_earn_lines($eco) as $line) $rules[] = '- ' . $line;
    if (!empty($eco['daily_system'])) {
        $rules[] = '- It moves every day whether or not anyone touches it.';
    }
    if (!empty($eco['board']['answer_keys'])) {
        $rules[] = '- What worked is remembered word for word, and repeated on purpose.';
    }
    if ($rules) {
        $b[] = 'How it moves:';
        foreach ($rules as $r) $b[] = $r;
    }

    if (array_key_exists('viewer_count', $counter) && $counter['viewer_count'] !== null) {
        $b[] = 'Where you stand right now: ' . (int)$counter['viewer_count'] . '.';
    }

    $board = xeric_economy_board($eco, $v, $counter);
    if ($board) foreach ($board as $line) $b[] = $line;

    return $b;
}

/**
 * The doc types `framing` as prose ("subconscious pride, never spoken") while
 * the engine wants a flag. Accept both: an explicit boolean wins, otherwise the
 * word "subconscious" in the prose is the signal.
 */
function xeric_economy_subconscious(array $eco): bool
{
    if (array_key_exists('subconscious', $eco)) return (bool)$eco['subconscious'];
    return str_contains(strtolower((string)($eco['framing'] ?? '')), 'subconscious');
}

/** Machine earn tokens → one prose line each. Prose `rules` are preferred. */
function xeric_economy_earn_lines(array $eco): array
{
    $tokens = $eco['earned_by'] ?? [];
    if (is_string($tokens)) $tokens = [$tokens];

    $events = [];
    $lines  = [];
    foreach ((array)$tokens as $t) {
        $t    = (string)$t;
        $kind = $t;
        $arg  = '';
        if (str_contains($t, ':')) [$kind, $arg] = explode(':', $t, 2);
        $arg = str_replace('_', ' ', $arg);
        switch ($kind) {
            case 'user_event': $events[] = $arg; break;
            case 'boon':       $lines[] = 'Paid out by the boon "' . $arg . '".'; break;
            case 'user_grant': $lines[] = 'He can simply hand it over, and sometimes does.'; break;
            default:           $lines[] = xeric_sentence(str_replace('_', ' ', $t)); break;
        }
    }
    if ($events) array_unshift($lines, 'It counts when: ' . xeric_join_list($events) . '.');
    return $lines;
}

/**
 * Podium rendering. The board is a separate permission from the economy: you
 * can know the ledger exists and be nowhere near allowed to see the standings.
 */
function xeric_economy_board(array $eco, array $v, array $counter): array
{
    $cfg = (array)($eco['board'] ?? []);
    if (!$cfg) return [];

    $rows = [];
    foreach ((array)($counter['board'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $rows[] = [
            'name'   => (string)($row['name'] ?? $row['handle'] ?? ''),
            'handle' => (string)($row['handle'] ?? ''),
            'n'      => (int)($row['n'] ?? $row['count'] ?? 0),
        ];
    }
    if (!$rows) return [];

    $seen = array_key_exists('visible_to', $cfg) ? xeric_selector_any((array)$cfg['visible_to'], $v) : true;
    if (!$seen) return [];

    // Deterministic regardless of how state handed it over: count desc, name asc.
    usort($rows, function ($a, $b) {
        if ($a['n'] !== $b['n']) return $b['n'] <=> $a['n'];
        return strcmp($a['name'], $b['name']);
    });

    $podium = (int)($cfg['podium'] ?? count($rows));
    if ($podium <= 0) $podium = count($rows);
    $rows = array_slice($rows, 0, $podium);

    $out   = ['Standing:'];
    $place = 0;
    foreach ($rows as $r) {
        $place++;
        $me = ($r['handle'] !== '' && $r['handle'] === $v['handle']) ? ' (you)' : '';
        $out[] = '  ' . $place . '. ' . $r['name'] . $me . ' — ' . $r['n'];
    }
    return $out;
}

/**
 * Boons. A boon that pays into a walled economy is itself a leak — if the
 * viewer can't see the ledger, they can't see the prize that fills it.
 */
function xeric_economy_boons(array $template, array $walls, array $v, array $state, string $eff, array $visibleEconomies): array
{
    if (xeric_hidden($walls, 'boons')) return [];

    $shown = [];
    $b     = [];
    foreach ($template['boons'] ?? [] as $boon) {
        $key = (string)($boon['key'] ?? '');
        if ($key === '') continue;
        if (!xeric_rating_allows($eff, $boon)) continue;
        if (xeric_hidden($walls, 'boons.' . $key)) continue;

        $payEco = (string)($boon['payout']['economy'] ?? '');
        if ($payEco !== '' && !isset($visibleEconomies[$payEco])) continue;

        $label = trim((string)($boon['label'] ?? '')) ?: str_replace('_', ' ', $key);
        $line  = $label;
        $text  = xeric_text($boon['text'] ?? '');
        if ($text !== '') $line .= ' — ' . $text;
        $b[] = xeric_sentence($line);

        $detail = [];
        $amount = xeric_text($boon['payout']['amount'] ?? '');
        if ($amount !== '') {
            $ecoLabel = trim((string)($visibleEconomies[$payEco]['label'] ?? '')) ?: str_replace('_', ' ', $payEco);
            $detail[] = xeric_sentence('Worth ' . $amount . ($payEco !== '' ? ', against ' . $ecoLabel : ''));
        }
        if (($boon['claim'] ?? '') === 'in_conversation') {
            $detail[] = 'It has to be claimed face to face; nobody hands it over in absentia.';
        }
        if (!empty($boon['ttl_hours'])) {
            $detail[] = 'It goes stale after ' . (int)$boon['ttl_hours'] . ' hours and is simply gone.';
        }
        if ($detail) $b[] = '  ' . implode(' ', $detail);

        $shown[$key] = $label;
    }

    $due = [];
    foreach ((array)($state['boons_due'] ?? []) as $d) {
        $key  = is_array($d) ? (string)($d['key'] ?? '') : (string)$d;
        if ($key === '' || !isset($shown[$key])) continue;   // never hint at a walled boon
        $line = $shown[$key];
        if (is_array($d)) {
            $note = xeric_text($d['note'] ?? '');
            if ($note !== '') $line .= ' (' . $note . ')';
            if (!empty($d['expires_in_hours'])) $line .= ', ' . (int)$d['expires_in_hours'] . ' hours left';
        }
        $due[] = '- ' . xeric_sentence($line);
    }
    if ($due) {
        $b[] = 'Owed right now, unclaimed:';
        foreach ($due as $d) $b[] = $d;
    }

    if (!$b) return [];
    array_unshift($b, 'BOONS');
    return $b;
}
