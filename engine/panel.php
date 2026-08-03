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
function xeric_panel_block(array $t, string $handle, ?PDO $db = null): string
{
    $p = xeric_panel($t);
    if ($p === null || !isset($p['experts'][$handle])) return '';
    $e = $p['experts'][$handle];
    $out = "WHY YOU ARE IN THIS ROOM\n- The question is: " . $p['question'];
    if ($e['stake'] !== '') $out .= "\n- What you are protecting: " . $e['stake'];
    $out .= "\n- The line you will not cross: " . $e['red_line']
          . "\n- You are not here to be agreeable. If a thing crosses that line you say so, "
          . "plainly, and you do not soften it to keep the room comfortable.";

    // AND WHAT EVERYBODY ELSE IS DEFENDING, which is the opposite of what this
    // block did when it was written, and the owner is right about the reversal.
    //
    // v1 hid the other refusals so nobody could write around them. That guards
    // the wrong thing: a room where the terms are secret is a negotiation, and
    // this is meant to be a workshop. People solving a hard problem together
    // need to know what the constraints ARE — half of real progress is somebody
    // saying "wait, if that is your actual line then here is a shape that
    // clears it," which is impossible when the line is hidden.
    //
    // THE BLINDNESS THAT MATTERED IS KEPT, and it was never here: it is in
    // xeric_panel_ask(), where a proposal is put to one person about one
    // sentence with no tally and no peer verdicts in front of them. The room is
    // OPEN and the judging is BLIND. Writing around somebody's stated line in
    // open conversation is not cheating, it is the work; agreeing with a tally
    // you can see is.
    $others = [];
    foreach ($p['experts'] as $h => $o) {
        if ($h === $handle) continue;
        $others[] = '- ' . $o['name'] . ' will not accept: ' . $o['red_line']
                  . ($o['stake'] !== '' ? ' (protecting: ' . $o['stake'] . ')' : '');
    }
    if ($others !== []) {
        $out .= "\n\nWHAT THE OTHERS WILL NOT ACCEPT\n" . implode("\n", $others)
              . "\n- These are on the table, not secret. If you can find a shape that clears "
              . "somebody else's line without crossing your own, say it — that is the work.";
    }

    // WHAT THEY SAID AND WHY THEY SAID IT. The reasoning is shared on purpose:
    // a room where you can see what somebody was thinking is a room where you
    // can pick up the half-idea they abandoned, which is where most of the
    // value in a working session actually is.
    if ($db !== null) {
        $think = xeric_panel_thinking($db, $handle);
        if ($think !== '') $out .= "\n\n" . $think;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// The open record: what was said, and what was behind it
// ---------------------------------------------------------------------------

/** How many turns of reasoning ride into a prompt before it is too much. */
const XERIC_PANEL_THINK_KEEP = 40;

/** Everything anybody has said and the thinking under it, oldest first. */
function xeric_panel_thoughts(PDO $db): array
{
    $raw = xeric_world_state_get($db, 'panel.thinking');
    $out = $raw === null ? [] : json_decode((string)$raw, true);
    return is_array($out) ? $out : [];
}

/**
 * Write down a turn: who, what they said, and what was behind it.
 *
 * The `why` is the point. A transcript tells you what a room concluded; the
 * reasoning tells you what it CONSIDERED, and the considered-and-dropped is
 * usually the more useful half — it is where the threads nobody followed are.
 */
function xeric_panel_think(PDO $db, string $handle, string $said, string $why = '',
                           ?int $at = null): void
{
    $said = trim(preg_replace('/\s+/u', ' ', $said) ?? '');
    if ($handle === '' || $said === '') return;
    $all = xeric_panel_thoughts($db);
    $all[] = ['who' => $handle, 'said' => mb_substr($said, 0, 900),
              'why' => mb_substr(trim(preg_replace('/\s+/u', ' ', $why) ?? ''), 0, 400)];
    if (count($all) > XERIC_PANEL_THINK_KEEP) $all = array_slice($all, -XERIC_PANEL_THINK_KEEP);
    xeric_world_state_set($db, 'panel.thinking', json_encode($all, JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
}

/** The shared record as it reaches one person's prompt. */
function xeric_panel_thinking(PDO $db, string $me = '', int $keep = 12): string
{
    $all = array_slice(xeric_panel_thoughts($db), -$keep);
    if ($all === []) return '';
    $lines = ['WHAT HAS BEEN SAID, AND WHAT WAS BEHIND IT'];
    foreach ($all as $r) {
        $who = (string)$r['who'] === $me ? 'You' : (string)$r['who'];
        $lines[] = '- ' . $who . ': ' . (string)$r['said'];
        if ((string)($r['why'] ?? '') !== '') {
            $lines[] = '  (thinking: ' . (string)$r['why'] . ')';
        }
    }
    $lines[] = '- Nothing here is private. Pick up anything somebody dropped.';
    return implode("\n", $lines);
}

/**
 * THREADS NOBODY FOLLOWED — the half-ideas that went nowhere.
 *
 * A thing somebody raised whose distinctive words never appear again, in
 * anybody's later turn or in any proposal. That is not a judgement about
 * quality: it is a record of what the room walked past, and in a working
 * session it is routinely the most valuable thing on the page, because a room
 * under pressure converges early and drops the idea it did not have time for.
 *
 * Computed with the same word machinery the red-line check uses, minus the
 * frame everybody shares, so "we should look at the lease" counts as a thread
 * and "I think that is right" does not.
 */
function xeric_panel_threads(PDO $db, int $min = 2): array
{
    $all = xeric_panel_thoughts($db);
    if (count($all) < 2) return [];

    $texts = array_map(fn($r) => (string)$r['said'] . ' ' . (string)($r['why'] ?? ''), $all);
    $frame = xeric_panel_frame($texts);
    $props = xeric_panel_proposals($db);

    // THE LAST THING SAID IS NEVER A LOOSE END. Nothing follows it yet, so by
    // construction it has been followed by nobody — and calling the sentence
    // still hanging in the air an abandoned thread would put the room's most
    // recent turn at the top of a report about what it walked past. A thread is
    // something the room moved PAST, and it cannot move past what it just said.
    $out  = [];
    $last = count($all) - 1;
    foreach ($all as $i => $r) {
        if ($i >= $last) break;
        $mine = array_diff(array_keys(xeric_ledger_words((string)$r['said'])), $frame);
        if (count($mine) < $min) continue;              // "I agree" raises nothing

        $later = '';
        for ($j = $i + 1; $j < count($all); $j++) $later .= ' ' . $texts[$j];
        foreach ($props as $p) $later .= ' ' . (string)($p['text'] ?? '');
        $seen = array_keys(xeric_ledger_words($later));

        $picked = array_intersect($mine, $seen);
        // More than half of what made it distinctive never came back.
        if (count($picked) * 2 > count($mine)) continue;
        $out[] = ['who' => (string)$r['who'], 'said' => (string)$r['said'],
                  'why' => (string)($r['why'] ?? '')];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// What the room built, if it built anything
// ---------------------------------------------------------------------------

/**
 * A deliverable the room produced: a plan, a script, a draft, a schedule.
 *
 * Kept apart from the proposals because it is a different kind of thing. A
 * proposal is a position to be held against four refusals; an artifact is the
 * work — and if somebody asked this room for a program, the program is what
 * they came for, not the argument about whether to write it.
 */
function xeric_panel_artifacts(PDO $db): array
{
    $raw = xeric_world_state_get($db, 'panel.artifacts');
    $out = $raw === null ? [] : json_decode((string)$raw, true);
    return is_array($out) ? $out : [];
}

/** Put something the room made on the record. Returns its index. */
function xeric_panel_made(PDO $db, string $title, string $body, string $kind = 'text',
                          string $by = '', ?int $at = null): int
{
    $body = trim($body);
    if ($body === '') return -1;
    $all = xeric_panel_artifacts($db);
    $all[] = ['title' => mb_substr(trim($title) ?: 'what the room made', 0, 120),
              'body' => mb_substr($body, 0, 40000),
              'kind' => preg_match('/^[a-z0-9+#.-]{1,20}$/', $kind) ? $kind : 'text',
              'by' => $by];
    xeric_world_state_set($db, 'panel.artifacts', json_encode($all, JSON_UNESCAPED_UNICODE),
                          $at ?? xeric_state_time());
    return count($all) - 1;
}

/**
 * ASK THE ROOM TO ACTUALLY BUILD IT.
 *
 * The argument is not always the deliverable. Somebody who came here with "we
 * need a rota that nobody hates" or "write me the script that does this" wants
 * the rota and the script — the disagreement was the method, not the product.
 * So this is one call that reads the whole open record and writes the thing.
 *
 * WRITTEN BY THE ROOM, NOT BY A NARRATOR. The prompt carries every refusal and
 * every piece of reasoning, and says so: what comes back has to be something
 * that survives this particular set of constraints, and where it cannot, it
 * says which constraint it broke rather than quietly picking a side. A
 * deliverable that pretends the disagreement was resolved is worse than no
 * deliverable, because it looks like an answer.
 *
 * Returns the artifact index, or -1 if nothing usable came back.
 */
function xeric_panel_build(array $t, PDO $db, string $ask, array $endpoint, array $opts = []): int
{
    $p = xeric_panel($t);
    if ($p === null) return -1;
    $ask = trim($ask);
    if ($ask === '') return -1;

    $lines = [];
    foreach ($p['experts'] as $e) {
        $lines[] = '- ' . $e['name'] . ' will not accept: ' . $e['red_line']
                 . ($e['stake'] !== '' ? ' (protecting: ' . $e['stake'] . ')' : '');
    }
    $record = xeric_panel_thinking($db, '', 20);
    $threads = xeric_panel_threads($db);
    $loose = [];
    foreach (array_slice($threads, 0, 5) as $th) $loose[] = '- ' . $th['who'] . ': ' . $th['said'];

    $sys = 'You write the thing a room full of people who disagree was asked for. You are not '
         . 'summarising their argument and you are not picking a winner: you are producing the '
         . 'work. Reply with ONE JSON object and nothing else.';

    $user = "THE QUESTION IN THE ROOM\n" . $p['question'] . "\n\n"
          . "WHAT EACH OF THEM WILL NOT ACCEPT\n" . implode("\n", $lines) . "\n\n"
          . ($record !== '' ? $record . "\n\n" : '')
          . ($loose !== [] ? "RAISED AND NEVER PICKED UP\n" . implode("\n", $loose) . "\n\n" : '')
          . "WHAT IS WANTED\n" . mb_substr($ask, 0, 2000) . "\n\n"
          . "Write it. Real and complete enough to use — if it is code it runs, if it is a plan it\n"
          . "has steps somebody could follow tomorrow, if it is a draft it is finished.\n\n"
          . "DO NOT PRETEND THE DISAGREEMENT IS RESOLVED. Where the refusals above genuinely\n"
          . "cannot all be honoured, say WHICH ONE this breaks and why you had to. A deliverable\n"
          . "that quietly picks a side is worse than none, because it looks like an answer.\n\n"
          . "WRITE ONE JSON OBJECT\n"
          . '{ "title": "four words", "kind": "text|python|php|js|sql|markdown|…", '
          . '"body": "the whole thing", "breaks": "which refusal this breaks, or \"\" if none" }';

    try {
        $raw = xeric_chat_json($endpoint, 'panel-build', [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $user],
        ], ['temperature' => 0.6, 'timeout' => (int)($opts['timeout'] ?? 240)] + $opts);
    } catch (Throwable $e) {
        return -1;
    }
    if (!is_array($raw)) return -1;

    $body = trim((string)($raw['body'] ?? ''));
    if ($body === '') return -1;

    // The honest footnote rides WITH the work, in the artifact, not in a note
    // beside it that a reader can scroll past.
    $breaks = trim((string)($raw['breaks'] ?? ''));
    if ($breaks !== '') {
        $body .= "\n\n---\nWHAT THIS BREAKS: " . mb_substr($breaks, 0, 400);
    }
    return xeric_panel_made($db, (string)($raw['title'] ?? $ask), $body,
        (string)($raw['kind'] ?? 'text'), '');
}
