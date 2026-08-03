<?php
/**
 * Xeric — the panel. A problem, a room, and nobody obliged to agree.
 *
 * EXPERIMENTAL. This is a different use of the same engine and it is marked so
 * everywhere it surfaces: a xeric is ordinarily a place you live in, and this
 * is a place you put a question and watch three to five people fail to settle
 * it. Everything under it is the ordinary machinery — room.php seats them,
 * walls keep them honest, memory keeps them consistent — so the experiment is
 * in the framing, not in a second engine nobody maintains.
 *
 * ── WHY THIS IS NOT A COMMITTEE OF LANGUAGE MODELS ────────────────────────
 *
 * The known failure of every "panel of experts" prompt is that a model asked
 * for five conflicting views writes five mild variations that all agree by the
 * third paragraph, then declares consensus. Two rules stop that here, and both
 * are code:
 *
 *   1. EVERY EXPERT DECLARES A RED LINE — one sentence naming what they will
 *      not accept. Not a preference, not a priority: a refusal. Red lines must
 *      be DISTINCT, checked at build time with the ledger's own word stemmer,
 *      because two people with the same refusal are one person twice.
 *
 *   2. NOBODY IS EVER ASKED WHETHER THERE IS CONSENSUS. A proposal goes to
 *      each expert separately and each answers exactly one question — does
 *      this cross MY red line — about one sentence they wrote themselves.
 *      Code tallies. A model that would happily narrate a room into agreement
 *      is never given the chance to, because it is never holding the whole
 *      room.
 *
 * ── AND A HUNG PANEL IS A RESULT ──────────────────────────────────────────
 *
 * Consensus is nice and it is not the point. If nothing proposed clears
 * everybody, the verdict is not "failed" — it is the PAIR: which two refusals
 * no proposal ever satisfied at once. That is a real answer to a real
 * question, and it is the answer most contested problems actually have. A tool
 * that always returns a synthesis is a tool that lies about hard problems.
 *
 * Computed rather than narrated: for each pair of experts, code asks whether
 * ANY proposal cleared both of them, across the whole session. A pair that was
 * never jointly cleared is where the problem actually lives.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/chat.php';     // the model seam
require_once __DIR__ . '/ledger.php';   // its word stemmer, for the distinctness check

/** Fewest and most people a panel may hold — a room's own limits. */
const XERIC_PANEL_MIN = 3;
const XERIC_PANEL_MAX = 5;

/** How alike two red lines may be before they are one red line. */
const XERIC_PANEL_SAME = 0.6;

/** How many proposals one session keeps. Past this, the pattern is the answer. */
const XERIC_PANEL_KEEP = 24;

// ---------------------------------------------------------------------------
// Is this that kind of xeric
// ---------------------------------------------------------------------------

/** The panel block, or null for an ordinary world. */
function xeric_panel(array $t): ?array
{
    $p = $t['panel'] ?? null;
    if (!is_array($p)) return null;
    $ex = xeric_panel_experts($t);
    return $ex === [] ? null : ['question' => trim((string)($p['question'] ?? '')),
                                'problem'  => trim((string)($p['problem'] ?? '')),
                                'experts'  => $ex];
}

/** The people in the room, with their stakes and their refusals. */
function xeric_panel_experts(array $t): array
{
    $out = [];
    foreach ((array)($t['panel']['experts'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $h = trim((string)($e['handle'] ?? ''));
        $r = trim((string)($e['red_line'] ?? ''));
        if ($h === '' || $r === '') continue;
        if (xeric_world_character($t, $h) === null) continue;
        $out[$h] = ['handle' => $h, 'red_line' => $r,
                    'stake' => trim((string)($e['stake'] ?? '')),
                    'name' => xeric_world_name($t, $h) ?: $h];
    }
    return $out;
}

/**
 * Are these two refusals the same refusal wearing different words?
 *
 * Crude and deliberately so — this is a build-time smell test, not a semantic
 * judgement, and the failure it catches is gross: a model handed "five
 * conflicting experts" writing "I won't accept anything that hurts the staff"
 * five times. Uses the ledger's stemmer so "hurting" and "hurts" are one word,
 * for the same reason it does there.
 */
function xeric_panel_alike(string $a, string $b, array $frame = []): bool
{
    // KEYS, not values: xeric_ledger_words() returns stem => length, and
    // intersecting the values compares word LENGTHS, which finds every pair of
    // sentences alike and is a silent, total failure of this check.
    $wa = array_diff(array_keys(xeric_ledger_words($a)), $frame);
    $wb = array_diff(array_keys(xeric_ledger_words($b)), $frame);
    if ($wa === [] || $wb === []) return false;
    $shared = count(array_intersect($wa, $wb));
    return $shared / min(count($wa), count($wb)) >= XERIC_PANEL_SAME;
}

/**
 * The words every refusal in the room shares, which carry no information.
 *
 * Red lines are written to a template — "I will not accept anything that ..." —
 * and a naive overlap check measures that template rather than the refusals
 * under it, calling three genuinely opposed people one person three times.
 * Anything said by ALL of them is the frame by construction, so it is dropped
 * before the comparison. Same idea as an inverse document frequency, done in
 * four lines because a panel is never more than five documents.
 */
function xeric_panel_frame(array $redLines): array
{
    if (count($redLines) < 2) return [];
    $sets = array_map(fn($l) => array_keys(xeric_ledger_words((string)$l)), $redLines);
    $all  = array_shift($sets);
    foreach ($sets as $s) $all = array_intersect($all, $s);
    return array_values($all);
}

/**
 * What is wrong with this panel, as a list of sentences, or [] for a good one.
 *
 * Run at build time and at load, because a panel whose experts secretly agree
 * is not a broken world — it validates, it runs, it reads fine, and it returns
 * a consensus that means nothing. That is the worst kind of failure this tool
 * can have and the only place to catch it is before anybody trusts the answer.
 */
function xeric_panel_check(array $t): array
{
    $bad = [];
    $ex  = xeric_panel_experts($t);
    $n   = count($ex);
    if ($n < XERIC_PANEL_MIN) $bad[] = "a panel is at least " . XERIC_PANEL_MIN . " people, this has $n";
    if ($n > XERIC_PANEL_MAX) $bad[] = "a panel is at most " . XERIC_PANEL_MAX . " people, this has $n";

    $list  = array_values($ex);
    $frame = xeric_panel_frame(array_column($list, 'red_line'));
    for ($i = 0; $i < count($list); $i++) {
        for ($j = $i + 1; $j < count($list); $j++) {
            if (xeric_panel_alike($list[$i]['red_line'], $list[$j]['red_line'], $frame)) {
                $bad[] = $list[$i]['name'] . ' and ' . $list[$j]['name']
                    . ' refuse the same thing, so they are one person twice';
            }
        }
    }
    if (trim((string)($t['panel']['question'] ?? '')) === '') {
        $bad[] = 'a panel needs the one question it is in the room to answer';
    }
    return $bad;
}

// ---------------------------------------------------------------------------
// Proposals, and what each refusal made of them
// ---------------------------------------------------------------------------

/** Everything proposed so far, oldest first. */
function xeric_panel_proposals(PDO $db): array
{
    $raw = xeric_world_state_get($db, 'panel.proposals');
    $out = $raw === null ? [] : json_decode((string)$raw, true);
    return is_array($out) ? $out : [];
}

/**
 * Put something on the table. Returns its index.
 *
 * A proposal is text and nothing else: no score, no author's own verdict, no
 * claim about who will accept it. Those are read off the room afterwards.
 */
function xeric_panel_propose(PDO $db, string $text, string $by = '', ?int $at = null): int
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') return -1;
    $all = xeric_panel_proposals($db);
    $all[] = ['text' => mb_substr($text, 0, 600), 'by' => $by, 'clears' => [], 'crosses' => []];
    if (count($all) > XERIC_PANEL_KEEP) $all = array_slice($all, -XERIC_PANEL_KEEP);
    xeric_world_state_set($db, 'panel.proposals', json_encode($all, JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
    return count($all) - 1;
}

/**
 * One expert, one proposal, one question about one sentence they wrote.
 *
 * THE NARROWNESS IS THE WHOLE DESIGN. A model asked to chair a panel writes a
 * chairman's summary; a model asked "does this cross the line you drew, yes or
 * no" answers about the line. It never sees the other experts' verdicts, never
 * sees the tally, and is never told how many have already agreed — so there is
 * no bandwagon to join, which is the failure that makes multi-agent panels
 * agree with themselves.
 *
 * Fails CLOSED: an unreadable answer is a cross, not a clear. An expert who
 * could not be asked has not accepted anything.
 */
function xeric_panel_ask(array $t, array $expert, string $proposal, array $endpoint,
                         array $opts = []): array
{
    $sys = 'You are ' . $expert['name'] . '. You are in a room about one question and you have '
         . 'drawn one line you will not cross. You are shown a proposal. You answer about YOUR '
         . 'line and nothing else — not whether the proposal is wise, not whether you like it, '
         . 'not what the others think. Reply with one JSON object and nothing else.';

    $user = "THE QUESTION IN THE ROOM\n" . (string)($t['panel']['question'] ?? '')
          . "\n\nWHAT YOU WILL NOT ACCEPT\n" . $expert['red_line']
          . ($expert['stake'] !== '' ? "\n\nWHY IT MATTERS TO YOU\n" . $expert['stake'] : '')
          . "\n\nTHE PROPOSAL\n" . $proposal
          . "\n\nWRITE ONE JSON OBJECT\n"
          . '{ "crosses": true|false, "because": "one sentence, under 25 words" }' . "\n"
          . "- crosses: true if this proposal breaks the line above. Nothing else makes it true.\n"
          . "- A proposal you dislike but which does not break your line does NOT cross it.\n"
          . '- because: in your own voice, naming the part of the proposal you mean.';

    try {
        $raw = xeric_chat_json($endpoint, 'panel', [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.4, 'timeout' => (int)($opts['timeout'] ?? 60)] + $opts);
    } catch (Throwable $e) {
        return ['crosses' => true, 'because' => 'could not be reached, so has agreed to nothing'];
    }
    if (!is_array($raw) || !array_key_exists('crosses', $raw)) {
        return ['crosses' => true, 'because' => 'gave no readable answer, so has agreed to nothing'];
    }
    $why = trim((string)($raw['because'] ?? ''));
    return ['crosses' => (bool)$raw['crosses'],
            'because' => $why !== '' ? mb_substr($why, 0, 200) : ''];
}

/**
 * Put a proposal to the whole room and write down what came back.
 *
 * Code tallies. Nobody in the room is told the tally, and nothing in here ever
 * asks a model whether the room agrees.
 */
function xeric_panel_table(array $t, PDO $db, int $index, array $endpoint, array $opts = [],
                           ?callable $onNote = null): array
{
    $note = $onNote ?? static function (string $s): void {};
    $all  = xeric_panel_proposals($db);
    if (!isset($all[$index])) throw new RuntimeException('panel: no such proposal');

    $clears = [];
    $crosses = [];
    foreach (xeric_panel_experts($t) as $h => $e) {
        $said = xeric_panel_ask($t, $e, (string)$all[$index]['text'], $endpoint, $opts);
        if ($said['crosses']) {
            $crosses[$h] = $said['because'];
            $note($e['name'] . ' will not have it: ' . $said['because']);
        } else {
            $clears[] = $h;
            $note($e['name'] . ' can live with it.');
        }
    }
    $all[$index]['clears']  = $clears;
    $all[$index]['crosses'] = $crosses;
    xeric_world_state_set($db, 'panel.proposals', json_encode($all, JSON_UNESCAPED_UNICODE),
                          xeric_state_time());
    return $all[$index];
}

// ---------------------------------------------------------------------------
// The verdict, which is arithmetic
// ---------------------------------------------------------------------------

/**
 * Where the room got to. Never a summary — a count and a pair.
 *
 *   nothing    nobody has put anything on the table yet
 *   consensus  something cleared every refusal in the room
 *   hung       things were tabled and none of them cleared everybody, and
 *              here is the pair of refusals that were never satisfied at once
 *
 * A HUNG PANEL IS THE USEFUL ANSWER MOST OF THE TIME, and it is why this
 * returns the pair rather than an apology. "Nothing you can do satisfies both
 * her insistence on X and his on Y" is a finding. A tool that always returns a
 * synthesis is a tool that lies about hard problems.
 *
 * @return array{state:string,proposal:?array,cleared:int,of:int,tensions:array}
 */
function xeric_panel_verdict(array $t, PDO $db): array
{
    $ex   = xeric_panel_experts($t);
    $of   = count($ex);
    $all  = array_values(array_filter(xeric_panel_proposals($db),
        fn($p) => is_array($p) && ($p['clears'] ?? null) !== null && ($p['crosses'] ?? []) !== null));
    $put  = array_values(array_filter($all, fn($p) => $p['clears'] !== [] || $p['crosses'] !== []));

    if ($of === 0 || $put === []) {
        return ['state' => 'nothing', 'proposal' => null, 'cleared' => 0, 'of' => $of, 'tensions' => []];
    }

    $best = null; $bestN = -1;
    foreach ($put as $p) {
        $n = count((array)$p['clears']);
        if ($n > $bestN) { $bestN = $n; $best = $p; }
    }
    if ($bestN >= $of) {
        return ['state' => 'consensus', 'proposal' => $best, 'cleared' => $bestN, 'of' => $of,
                'tensions' => []];
    }

    // THE PAIR NOBODY EVER SATISFIED AT ONCE. Across every proposal put to the
    // room, which two people were never cleared together? That is not a
    // narrative judgement and it is not a model's opinion — it is a walk over
    // a table of yes and no, and it is where the problem actually lives.
    $handles  = array_keys($ex);
    $tensions = [];
    for ($i = 0; $i < count($handles); $i++) {
        for ($j = $i + 1; $j < count($handles); $j++) {
            $a = $handles[$i]; $b = $handles[$j];
            $together = false;
            foreach ($put as $p) {
                $c = (array)$p['clears'];
                if (in_array($a, $c, true) && in_array($b, $c, true)) { $together = true; break; }
            }
            if ($together) continue;
            $tensions[] = [
                'between' => [$ex[$a]['name'], $ex[$b]['name']],
                'lines'   => [$ex[$a]['red_line'], $ex[$b]['red_line']],
            ];
        }
    }
    return ['state' => 'hung', 'proposal' => $best, 'cleared' => $bestN, 'of' => $of,
            'tensions' => $tensions];
}

/** The verdict as one paragraph, for somebody who asked a question and wants an answer. */
function xeric_panel_say(array $t, PDO $db): string
{
    $v = xeric_panel_verdict($t, $db);
    $q = trim((string)($t['panel']['question'] ?? ''));
    if ($v['state'] === 'nothing') return 'Nothing has been put to the room yet.';
    if ($v['state'] === 'consensus') {
        return 'The room got there. On "' . $q . '": ' . (string)$v['proposal']['text']
             . ' Nobody\'s line was crossed.';
    }
    $out = 'The room did not get there, and that is the answer. On "' . $q . '", the closest '
         . 'anybody came was ' . $v['cleared'] . ' of ' . $v['of'] . ': '
         . (string)$v['proposal']['text'];
    foreach (array_slice($v['tensions'], 0, 3) as $tn) {
        $out .= "\n\nNothing satisfied " . $tn['between'][0] . ' and ' . $tn['between'][1]
             . ' at the same time. She will not have it that ' . rtrim($tn['lines'][0], '.')
             . '; he will not have it that ' . rtrim($tn['lines'][1], '.') . '.';
    }
    return $out;
}

/**
 * What an expert carries into the room, in their own prompt.
 *
 * Their OWN line and their OWN stake, never the others' — a panellist who
 * could read everybody's refusal would write around them, which is exactly the
 * chairman's-summary failure this file exists to avoid. What the others will
 * not accept is something they have to find out by talking, like anybody else
 * in a room.
 */
function xeric_panel_block(array $t, string $handle): string
{
    $p = xeric_panel($t);
    if ($p === null || !isset($p['experts'][$handle])) return '';
    $e = $p['experts'][$handle];
    $out = "WHY YOU ARE IN THIS ROOM\n- The question is: " . $p['question'];
    if ($e['stake'] !== '') $out .= "\n- What you are protecting: " . $e['stake'];
    $out .= "\n- The line you will not cross: " . $e['red_line']
          . "\n- You are not here to be agreeable. If a thing crosses that line you say so, "
          . "plainly, and you do not soften it to keep the room comfortable."
          . "\n- You do not know what anybody else in here refuses. You find that out by talking.";
    return $out;
}
