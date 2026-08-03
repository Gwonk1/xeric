<?php
/**
 * repass.php — the literary repass: an editor's read of a forged xeric.
 *
 * The forge writes a world in seven passes that never see each other's output
 * side by side; this is the pass that does. It reads the whole thing the way a
 * continuity editor reads a draft, and reports four kinds of trouble:
 *
 *   consistency — two facts that cannot both be true: a schedule that puts
 *                 somebody in two places, an age that contradicts a memory,
 *                 a name that drifts, prose that leaks what a wall hides.
 *   plot        — the through-line: does the motivation arm anything, does the
 *                 protagonist's pressure point at something that exists, do
 *                 the seeded events add up to the town the bible describes.
 *   develop     — the developmental read: not errors but FLATNESS. A roster
 *                 line that is a job description instead of a person, a voice
 *                 made of adjectives instead of habits, a place with no smell
 *                 and no economy, two interiors that could be swapped without
 *                 anyone noticing. Its rewrites keep every stated fact and add
 *                 the particular; and each line it improves is recorded on the
 *                 world (forge.developed) and never re-litigated — see the
 *                 sweep-mode comment in xeric_repass() for why that record is
 *                 what makes this lens safe to loop.
 *   snake       — the story overlay's pacing. The shape checks are arithmetic
 *                 and run without a model (a curve that never rises, a false
 *                 calm outside the story, a peak at the front door); the model
 *                 is only asked the one question arithmetic cannot answer —
 *                 whether each beat's PROSE belongs at the intensity its
 *                 position on the curve implies.
 *
 * FINDINGS, NEVER EDITS. Nothing here writes a byte of the world. A finding
 * that comes with a concrete rewrite carries it as `fix` against an editable
 * review path, and applying it is review.php's a=edit — the same door hand
 * edits use, so the age floor, the undo copy and the learning signal all hold.
 * An editor who rewrote the manuscript while reporting on it would be one more
 * source of the drift this pass exists to catch.
 *
 * THE DIGEST IS NUMBERED. Small models cite reliably by number and unreliably
 * by path, so every editable line goes in as "ITEM n", findings come back as
 * item numbers, and the mapping back to dotted paths happens here in PHP.
 * Item 0 is the world itself, for findings about no line in particular.
 */

declare(strict_types=1);

require_once __DIR__ . '/forge.php';
require_once __DIR__ . '/web/review-lib.php';   // xeric_review_field(): which paths a fix may ride
require_once dirname(__DIR__) . '/engine/story.php';

/**
 * Run the repass.
 *
 * @param array $w        xeric_review_open()'s shape: slug, dir, template, seed
 * @param array $endpoint a model endpoint (or a ['stub' => callable] for tests)
 * @return array{findings:array<int,array{kind:string,about:string,say:string,path:string,fix:string}>,
 *               checked:array{items:int,stories:int,calls:int}}
 */
function xeric_repass(array $w, array $endpoint, ?callable $onNote = null, array $opts = []): array
{
    $t    = (array)$w['template'];
    $seed = (array)($w['seed'] ?? ['events' => [], 'memories' => []]);

    // SWEEP MODE is the red button's. The plot lens stays out of it: an
    // OPINION re-asked ten times rewrites the same motivation ten different
    // ways, and a loop that exists to converge has no business holding a pen
    // that re-samples. `frozen` paths are lines an earlier pass in this loop
    // already corrected: they are marked settled on the sheet, so the editor
    // is told its own work is not up for re-litigation.
    //
    // The DEVELOPMENTAL lens rides the sweep anyway — the owner's call, and
    // it overrules the exclusion, not the churn argument. What makes it safe
    // where plot is not: an enrichment applies ONCE and its path goes into
    // forge.developed on the world itself (xeric_repass_apply), so iteration
    // N+1 sees the line marked settled whatever the caller remembered to pass
    // in `frozen`. A lens whose every fix permanently shrinks its own field
    // of complaint converges the same way the contradictions do; a lens that
    // re-samples taste against the same open field is the churn the plot
    // exclusion exists to keep out.
    $sweep  = ($opts['mode'] ?? '') === 'sweep';
    $frozen = array_fill_keys(array_map('strval', (array)($opts['frozen'] ?? [])), true);

    [$items, $context] = xeric_repass_digest($t, $seed);
    foreach ($items as $n => $it) {
        if (isset($frozen[$it['path']])) $items[$n]['label'] = 'settled, do not report: ' . $it['label'];
    }

    // The developmental read gets its own sheet: everything the caller froze,
    // PLUS every line this world has ever had enriched. Only for this lens —
    // an enriched line can still contradict a schedule, and the consistency
    // read must stay free to say so.
    $developed = array_fill_keys(array_map('strval', (array)($t['forge']['developed'] ?? [])), true);
    $devItems = $items;
    foreach ($devItems as $n => $it) {
        if (isset($developed[$it['path']]) && !str_starts_with($it['label'], 'settled, do not report')) {
            $devItems[$n]['label'] = 'settled, do not report: ' . $it['label'];
        }
    }
    $findings = [];
    $calls    = 0;

    // -- the literary reads -------------------------------------------------
    $reads = [['consistency', xeric_repass_ask_consistency($items, $context)]];
    if (!$sweep) $reads[] = ['plot', xeric_repass_ask_plot($items, $context, $t)];
    $reads[] = ['develop', xeric_repass_ask_develop($devItems, $context)];
    foreach ($reads as [$kind, $msgs]) {
        try {
            $calls++;
            $out = xeric_forge_ask($endpoint, 'repass-' . $kind, $msgs,
                ['temperature' => 0.4, 'max_tokens' => 900], $onNote);
        } catch (Throwable $e) {
            xeric_forge_note($onNote, "repass: the $kind read failed (" . $e->getMessage() . ') — skipped');
            continue;
        }
        foreach (xeric_repass_take($out, $items, $kind) as $f) $findings[] = $f;
    }

    // -- the snake ----------------------------------------------------------
    // Not in a sweep: pacing does not become truer by being asked ten times.
    $stories = [];
    if (!$sweep)
    try { $stories = xeric_story_for((string)$w['dir'], $t); }
    catch (Throwable $e) {
        // A story that will not validate is itself the finding.
        $findings[] = ['kind' => 'snake', 'about' => 'a story overlay', 'path' => '', 'fix' => '',
                       'say' => 'A story overlay in this xeric does not validate: ' . $e->getMessage()];
    }
    foreach ($stories as $s) {
        foreach (xeric_repass_snake_shape($s) as $f) $findings[] = $f;
        $msgs = xeric_repass_ask_snake($s);
        if ($msgs === null) continue;
        try {
            $calls++;
            $out = xeric_forge_ask($endpoint, 'repass-snake', $msgs,
                ['temperature' => 0.4, 'max_tokens' => 700], $onNote);
            foreach (xeric_repass_take($out, [], 'snake', xeric_story_key($s)) as $f) $findings[] = $f;
        } catch (Throwable $e) {
            xeric_forge_note($onNote, 'repass: the snake read failed (' . $e->getMessage() . ') — '
                . 'the shape checks above still stand');
        }
    }

    return ['findings' => array_slice($findings, 0, 24),
            'checked'  => ['items' => count($items), 'stories' => count($stories), 'calls' => $calls]];
}

// ---------------------------------------------------------------------------
// The digest: every editable line, numbered
// ---------------------------------------------------------------------------

/**
 * @return array{0:array<int,array{path:string,label:string,text:string}>,1:string}
 *         items keyed 1..n, and a context block the items are read against
 */
function xeric_repass_digest(array $t, array $seed): array
{
    $items = [];
    $n     = 0;
    $add   = function (string $path, string $label, $text) use (&$items, &$n): void {
        $text = trim((string)$text);
        if ($text === '') return;
        $n++;
        $items[$n] = ['path' => $path, 'label' => $label, 'text' => mb_substr($text, 0, 240)];
    };

    $add('meta.name',        'the xeric\'s name',  $t['meta']['name'] ?? '');
    $add('meta.description', 'what it says it is', $t['meta']['description'] ?? '');
    $add('user.motivation',  'why you are here',   $t['user']['motivation'] ?? '');

    foreach ((array)($t['places'] ?? []) as $i => $p) {
        $nm = (string)($p['name'] ?? ($p['key'] ?? ''));
        $add("places.$i.description", "$nm, the place", $p['description'] ?? '');
    }

    foreach ((array)($t['cast']['characters'] ?? []) as $i => $c) {
        $who = (string)($c['display_name'] ?? ($c['handle'] ?? "character $i"));
        $age = $c['age'] ?? null;
        $add("cast.characters.$i.one_line",   "$who, the roster line" . ($age !== null ? " (age $age)" : ''), $c['one_line'] ?? '');
        $add("cast.characters.$i.appearance", "$who, appearance", $c['appearance'] ?? '');
        $add("cast.characters.$i.voice",      "$who, voice",      $c['voice'] ?? '');
        $add("cast.characters.$i.solace",     "$who, solace",     $c['solace'] ?? '');
        $add("cast.characters.$i.drives.pull", "$who, the pull",  $c['drives']['pull'] ?? '');
    }

    if (($t['cast']['protagonist']['handle'] ?? '') !== '') {
        $add('cast.protagonist.arc',      'whose story this is, the arc',      $t['cast']['protagonist']['arc'] ?? '');
        $add('cast.protagonist.pressure', 'whose story this is, the pressure', $t['cast']['protagonist']['pressure'] ?? '');
    }

    foreach ((array)($seed['events'] ?? []) as $i => $e) {
        $ti = (string)($e['title'] ?? '');
        $add("seed.events.$i.prose", 'already happened: ' . ($ti !== '' ? $ti : "event $i"), $e['prose'] ?? '');
    }
    foreach ((array)($seed['memories'] ?? []) as $i => $m) {
        $who = (string)($m['holder'] ?? $m['handle'] ?? '');
        $add("seed.memories.$i.text", 'a memory' . ($who !== '' ? " $who carries" : ''), $m['text'] ?? '');
    }

    // What the items are read AGAINST but are not themselves on trial: the
    // walls (so a leak is checkable), the weeks (so two-places-at-once is),
    // the orbits, and the mystery if the world carries one. THE PLAYER GOES
    // FIRST: memories and walls name them constantly, and an editor who was
    // never told who the player is reports them as a missing character.
    $ctx = [];
    $you = trim((string)($t['user']['name'] ?? ''));
    if ($you !== '') {
        $ctx[] = 'THE PLAYER: ' . $you
               . ((string)($t['user']['occupation']['title'] ?? '') !== ''
                   ? ', ' . (string)$t['user']['occupation']['title'] : '')
               . ' — a real person in this world, not a character sheet. Anything naming '
               . $you . ' is about the player, and the player being absent from the roster is correct.';
    }
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $who = (string)($c['display_name'] ?? ($c['handle'] ?? ''));
        foreach ((array)($c['week'] ?? []) as $wk) {
            if (!is_array($wk)) continue;
            $ctx[] = $who . ' is at ' . (string)($wk['where'] ?? '?') . ' ' . (string)($wk['from'] ?? '')
                   . '-' . (string)($wk['to'] ?? '') . ' (days ' . implode(',', (array)($wk['days'] ?? [])) . ')'
                   . ((string)($wk['doing'] ?? '') !== '' ? ', ' . (string)$wk['doing'] : '');
        }
    }
    foreach ((array)($t['knowledge_walls'] ?? []) as $kw) {
        if (!is_array($kw)) continue;
        $ctx[] = 'WALL: ' . (string)($kw['explain'] ?? json_encode($kw));
    }
    if (isset($t['mystery']) && is_array($t['mystery'])) {
        $ctx[] = 'MYSTERY: ' . mb_substr((string)json_encode($t['mystery']), 0, 500);
    }

    return [$items, implode("\n", $ctx)];
}

/** The digest as prompt text. */
function xeric_repass_sheet(array $items): string
{
    $out = '';
    foreach ($items as $n => $it) $out .= 'ITEM ' . $n . ' [' . $it['label'] . "]: " . $it['text'] . "\n";
    return $out;
}

// ---------------------------------------------------------------------------
// The three asks
// ---------------------------------------------------------------------------

function xeric_repass_ask_consistency(array $items, string $context): array
{
    return [
        ['role' => 'system', 'content' =>
            'You are a continuity editor. You read a story world\'s sheet and report only REAL '
            . 'contradictions: two facts that cannot both be true. Not style, not taste, not things '
            . 'you would have written differently. Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "The sheet:\n" . xeric_repass_sheet($items)
            . "\nRead against (schedules, walls — not on trial themselves):\n" . $context
            . "\n\nFind contradictions: schedule impossibilities, an age a memory contradicts, a name "
            . "that drifts between spellings, prose in a COMMONS line that leaks what a WALL says is "
            . "hidden, a place described as open when its people are elsewhere. At most 6, only real ones; "
            . "an empty list is a fine answer.\n"
            . "A fix is the REPLACEMENT TEXT ITSELF, ready to sit in the world in the item's own voice. "
            . "Never advice, never an instruction, never 'e.g.'. If the cure is not a retype, fix is \"\".\n"
            . '{"findings":[{"item": 3, "say": "one sentence naming both facts", '
            . '"fix": "the corrected line itself, or \"\""}]}'],
    ];
}

function xeric_repass_ask_plot(array $items, string $context, array $t): array
{
    return [
        ['role' => 'system', 'content' =>
            'You are a story editor reading a world bible for its through-line. You report only holes a '
            . 'player would actually fall into. Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "The sheet:\n" . xeric_repass_sheet($items)
            . "\nRead against:\n" . $context
            . "\n\nQuestions to hold it to: does the stated motivation connect to anything on the sheet? "
            . "Does the protagonist's pressure point at a person or place that exists? Do the "
            . "already-happened events add up to the town the description promises, or do they belong to "
            . "a different story? Is anything armed that nothing on the sheet will ever fire? "
            . "At most 5 findings; item 0 means the world as a whole; an empty list is a fine answer.\n"
            . "A fix is the REPLACEMENT TEXT ITSELF, ready to sit in the world in the item's own voice. "
            . "Never advice, never an instruction, never 'e.g.'. If one line cannot close the hole, fix is \"\".\n"
            . '{"findings":[{"item": 0, "say": "one sentence, the hole and where it opens", '
            . '"fix": "the corrected line itself, or \"\""}]}'],
    ];
}

/**
 * The developmental read: flatness, not error.
 *
 * The narrow promise this ask has to keep is the same one the fix pipeline
 * enforces from the other side: a rewrite ADDS the particular and never
 * changes the true. An editor allowed to "improve" a fact is a reroll wearing
 * a nicer coat, and rerolls have their own button. Hence the read-against
 * block is framed as constraints on the rewrite, the sheet's settled markers
 * are load-bearing (they are how forge.developed reaches the model), and the
 * player's own lines are declared off the table — a machine polishing the
 * words somebody chose about themselves is not enrichment, whatever it thinks.
 */
function xeric_repass_ask_develop(array $items, string $context): array
{
    return [
        ['role' => 'system', 'content' =>
            'You are a developmental editor. You read a story world\'s sheet for FLATNESS, not error: '
            . 'a roster line that is a job description instead of a person, a voice made of adjectives '
            . 'instead of habits, a place with no smell and no economy to it, interiors two characters '
            . 'could swap without anyone noticing. You propose the better line itself. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "The sheet:\n" . xeric_repass_sheet($items)
            . "\nRead against (schedules, walls — facts your rewrite must not contradict):\n" . $context
            . "\n\nReport only lines that are genuinely flat, and never one marked settled. A line that "
            . "already carries a specific, concrete, surprising detail is DONE — polishing it again is "
            . "how a tea set becomes spilled juice. Keep every fact a line already states: same person, "
            . "same age, same job, same places, same relationships. You add the particular; you never "
            . "change the true, and you never touch the player's own lines. "
            . "At most 5, the flattest first; an empty list is a fine answer.\n"
            . "A fix is the REPLACEMENT TEXT ITSELF, ready to sit in the world in the item's own voice. "
            . "Never advice, never an instruction, never 'e.g.'. If the line cannot get better without "
            . "inventing a fact that might collide with the sheet, fix is \"\".\n"
            . '{"findings":[{"item": 3, "say": "one sentence naming what is flat", '
            . '"fix": "the better line itself, or \"\""}]}'],
    ];
}

/** Beats laid against the snake. null when the story has nothing to read. */
function xeric_repass_ask_snake(array $s): ?array
{
    $beats = array_values((array)($s['beats'] ?? []));
    if (count($beats) < 2) return null;

    $sheet = '';
    foreach ($beats as $i => $b) {
        if (!is_array($b)) continue;
        // Every beat declares WHERE it opens (`at`, validated 0..1 strictly
        // increasing), so it is judged at the intensity of its own position.
        // The words on trial are the ones a player meets: the piece a holder
        // carries and what it spills as; an event beat is its event.
        $at    = xeric_story_snake((array)($s['snake'] ?? []), (float)($b['at'] ?? 0.0));
        $prose = trim(implode(' — ', array_filter([
            (string)($b['piece'] ?? ''),
            (string)($b['spilled_as'] ?? ''),
            is_array($b['as_event'] ?? null)
                ? trim((string)($b['as_event']['title'] ?? '') . ' ' . (string)($b['as_event']['prose'] ?? ''))
                : '',
        ], fn(string $x): bool => trim($x) !== '')));
        if ($prose === '') continue;
        $sheet .= 'BEAT ' . ($i + 1) . ' [opens at ' . number_format((float)($b['at'] ?? 0), 2)
                . ', stage: ' . $at['stage'] . ', intensity '
                . number_format($at['intensity'], 2) . ']: ' . mb_substr($prose, 0, 220) . "\n";
    }
    if ($sheet === '') return null;

    return [
        ['role' => 'system', 'content' =>
            'You are a pacing editor. A story\'s beats each carry the stage and intensity their position '
            . 'on the arc implies. You report only beats whose CONTENT belongs at a different point: a '
            . 'reveal parked in the false calm, a shrug sitting on the crescendo, an ending that arrives '
            . 'in the rising half. Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            $sheet . "\nAt most 4 findings, only real misplacements; an empty list is a fine answer.\n"
            . '{"findings":[{"item": 2, "say": "one sentence: what the beat does vs where it sits"}]}'],
    ];
}

// ---------------------------------------------------------------------------
// What comes back
// ---------------------------------------------------------------------------

/** Model output → normalized findings. Unknown items become world-level notes. */
function xeric_repass_take(array $out, array $items, string $kind, string $storyKey = ''): array
{
    $found = [];
    foreach ((array)($out['findings'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $say = trim((string)($f['say'] ?? ''));
        if ($say === '') continue;
        $n   = (int)($f['item'] ?? 0);
        $it  = $items[$n] ?? null;
        $fix = trim((string)($f['fix'] ?? ''));
        // A fix is only carried where the door it would go through exists.
        if ($it === null || $fix === '' || xeric_review_field($it['path']) === null) $fix = '';
        $found[] = [
            'kind'  => $kind,
            // the settled marker is a note TO the finder, not a name for the row
            'about' => $it !== null ? (string)preg_replace('/^settled, do not report: /', '', $it['label'])
                     : ($kind === 'snake'
                         ? ($storyKey !== '' ? "the story '$storyKey'" . ', beat ' . $n : 'the story')
                         : 'the xeric as a whole'),
            'say'   => mb_substr($say, 0, 400),
            'path'  => $it !== null ? $it['path'] : '',
            'fix'   => mb_substr($fix, 0, 1200),
        ];
    }
    return $found;
}

// ---------------------------------------------------------------------------
// Applying what the editor carried
// ---------------------------------------------------------------------------

/**
 * Write every carried rewrite into the world, as ONE save.
 *
 * ONE SAVE, NOT N. Every save rolls a .prev, so applying eight fixes one door
 * at a time would leave the undo covering only the eighth — the person who
 * dislikes what the editor did could take back one sentence of it. Batched,
 * ↺ takes the whole repass back in a single step.
 *
 * NO LEARNING SIGNAL, deliberately. xeric_review_learn_edit() records the user
 * correcting the world in writing, the strongest signal there is; a model
 * rewriting its own output is a reroll wearing an editor's hat, and rerolls
 * are deliberately not recorded either.
 *
 * THE AGE FLOOR STILL STANDS. A fix that trips it is dropped and its finding
 * says so; everything else in the batch still lands.
 *
 * @return array{findings:array,fixed:int} findings, each now carrying 'fixed'
 */
function xeric_repass_apply(array $w, array $findings, array $opts = []): array
{
    $t    = (array)$w['template'];
    $seed = (array)($w['seed'] ?? ['events' => [], 'memories' => []]);
    $seedTouched = false;
    $fixed = 0;
    $sweep  = ($opts['mode'] ?? '') === 'sweep';
    $frozen = array_fill_keys(array_map('strval', (array)($opts['frozen'] ?? [])), true);
    // The permanent half of the freeze: lines the developmental lens already
    // improved, on any earlier visit. Read here and WRITTEN here — every
    // develop fix that lands adds its path, and the record rides the same
    // save as the fix, so there is no state to lose between button presses.
    $developed = array_fill_keys(array_map('strval', (array)($t['forge']['developed'] ?? [])), true);

    foreach ($findings as $i => $f) {
        $findings[$i]['fixed'] = false;
        $path = (string)($f['path'] ?? '');
        $fix  = trim((string)($f['fix'] ?? ''));
        if ($path === '' || $fix === '') continue;
        // A line this loop already corrected is settled: rewriting a rewrite
        // is how a tea set becomes spilled juice by pass ten. And the sweep
        // never touches user.* at all — the player's own words about
        // themselves are not a contradiction for a machine to clear.
        if (isset($frozen[$path])) continue;
        if ($sweep && str_starts_with($path, 'user.')) continue;
        // The developmental lens holds two more lines it may never cross,
        // whatever mode this is: a line it has enriched before (once is
        // enrichment, twice is churn), and the player's own words in ANY
        // mode — flatness in how somebody described themselves is theirs.
        if ((string)($f['kind'] ?? '') === 'develop'
            && (isset($developed[$path]) || str_starts_with($path, 'user.'))) continue;

        $spec = xeric_review_field($path);
        if ($spec === null) continue;
        [, $kind] = $spec;
        if ($kind !== 'line' && $kind !== 'text') continue;   // the editor only rewrites prose
        $fix = mb_substr($fix, 0, $kind === 'text' ? 1200 : 300);

        // ADVICE IS NOT A REWRITE. Asked for replacement text, a model
        // sometimes hands back the note it would leave in the margin —
        // "Connect the motivation to a specific local debt (e.g. …)" — and
        // applied verbatim that puts an editor's instruction into a field the
        // prompts read as the world. Shaped like advice, it is dropped here
        // whatever the prompt said, and the finding stays a finding.
        if (xeric_repass_is_advice($fix)) continue;

        // A REWRITE THAT CHANGES NOTHING IS AN ANSWER, NOT A FAILURE. Two lines
        // that contradict each other come back as TWO findings, one from each
        // end, and only one of them is the line that is wrong. Asked to correct
        // the right one, the editor hands back exactly what is already there —
        // which is it saying "this side is fine, fix the other one".
        //
        // Read as a failed rewrite, that answer burns both refix attempts and
        // lands a correct line in "needs a hand", which is what happened to a
        // wrench in Macomb: the event knew it was left at the feed store, the
        // memory said the co-op office, and the event's own finding could never
        // be satisfied because there was nothing about the event to satisfy.
        // Marked instead, so the loop leaves it alone and settles it when the
        // other end is fixed.
        $cur = xeric_repass_current($w, $path);
        if ($cur !== null && xeric_repass_same($cur, $fix)) {
            $findings[$i]['noop'] = true;
            $findings[$i]['say'] .= ' (Nothing to change on this line — it is the other one that is wrong.)';
            continue;
        }

        $about = xeric_age_floor($t, xeric_review_edit_handles($w, $path), [$fix]);
        if ($about !== null) {
            $findings[$i]['say'] .= ' (Its rewrite was dropped: nothing sexual may be written about '
                . $about . ', who is a child here.)';
            continue;
        }

        if (str_starts_with($path, 'seed.')) {
            $bag = xeric_review_set(['seed' => $seed], $path, $fix);
            $seed = (array)$bag['seed'];
            $seedTouched = true;
        } else {
            $t = xeric_review_set($t, $path, $fix);
        }
        if ((string)($f['kind'] ?? '') === 'develop') {
            $developed[$path] = true;
            $t['forge'] = (array)($t['forge'] ?? []);
            $t['forge']['developed'] = array_keys($developed);
        }
        $findings[$i]['fixed'] = true;
        $fixed++;
    }

    if ($fixed > 0) {
        // The corrections changed the book, so the cover comes off with the
        // same save; whoever is looking at a cover element rewrites it now
        // (teaser_stale in the reply), and any later visit heals it too.
        unset($t['meta']['teaser']);
        try {
            xeric_review_save((string)$w['slug'], $t, $seedTouched ? $seed : null);
        } catch (Throwable $e) {
            // The save refused the whole batch, so nothing landed: say so on
            // every finding that thought it had.
            foreach ($findings as $i => $f) {
                if (!empty($f['fixed'])) {
                    $findings[$i]['fixed'] = false;
                    $findings[$i]['say'] .= ' (Not applied: ' . $e->getMessage() . ')';
                }
            }
            $fixed = 0;
        }
    }

    return ['findings' => $findings, 'fixed' => $fixed];
}

// ---------------------------------------------------------------------------
// The judge, and the second opinion
// ---------------------------------------------------------------------------
//
// "Clear All Contradictions" used to loop whole passes and count to zero,
// which measures the detector's mood: every pass RE-SAMPLES a stochastic
// finder, so fresh complaints appear forever and zero certifies nothing.
// The loop is over FINDINGS now. Each one is judged individually, cold, and
// ends in a terminal state — verified closed, judged not real, or handed to
// a human — and a thing that cannot leave its state cannot churn.

/** The line a finding points at, as it stands on disk right now. */
function xeric_repass_current(array $w, string $path): ?string
{
    $bag = str_starts_with($path, 'seed.')
        ? ['seed' => (array)($w['seed'] ?? [])]
        : (array)$w['template'];
    $v = xeric_review_get($bag, $path);
    return is_scalar($v) ? (string)$v : null;
}

/**
 * Is this "rewrite" the line it claims to be replacing?
 *
 * Loose on purpose. A model asked to reproduce a line it does not want to change
 * hands it back with a different quote mark, a trailing full stop, an ellipsis
 * normalised, one run of whitespace collapsed — all of which are the same
 * sentence and none of which are a correction. Case and punctuation go; what is
 * left is the words.
 */
function xeric_repass_same(string $a, string $b): bool
{
    $flat = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', "'", $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    };
    return $flat($a) !== '' && $flat($a) === $flat($b);
}

/**
 * The judge: does the complaint still hold against the line as it now stands?
 *
 * COLD ON PURPOSE. temp 0.1 and a yes/no shape, because this is the one call
 * whose job is to be boring: the finder is allowed imagination, the judge is
 * not. It reads the CURRENT text off disk, never the text the finder saw, so
 * a rewrite that landed since is what gets judged.
 */
function xeric_repass_judge(array $w, array $endpoint, string $path, string $say, string $context,
                            ?callable $onNote = null): array
{
    $current = $path !== '' ? xeric_repass_current($w, $path) : null;
    if ($path !== '' && $current === null) {
        // The line is gone (a reroll swept it away): nothing to hold a
        // complaint against, and that is a resolution, not an error.
        return ['still' => false, 'why' => 'the line this was about no longer exists'];
    }
    $out = xeric_forge_ask($endpoint, 'repass-judge', [
        // Two kinds of complaint reach this judge and each is held to its own
        // standard, spelled out so the strictness that keeps contradiction
        // findings honest does not silently dismiss every flatness finding as
        // "not a contradiction" — which is what the old wording did the day
        // the developmental lens arrived.
        ['role' => 'system', 'content' =>
            'You are a fact checker. You are shown a complaint about a story world and the text as it '
            . 'stands NOW. You answer one question: does the complaint still hold? A complaint about a '
            . 'CONTRADICTION holds only if two facts still cannot both be true — be strict: wobbly prose '
            . 'and things you would write differently are not contradictions. A complaint about FLATNESS '
            . '(generic, thin, interchangeable) holds only while the text is still generic — a line that '
            . 'now carries a specific, concrete detail has answered it. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            'THE COMPLAINT: ' . $say
            . ($current !== null ? "\nTHE LINE AS IT NOW STANDS: " . mb_substr($current, 0, 500) : '')
            . "\nREAD AGAINST (schedules, walls):\n" . mb_substr($context, 0, 1500)
            . "\n\n" . '{"still": true or false, "why": "one short sentence"}'],
    ], ['temperature' => 0.1, 'max_tokens' => 200], $onNote);

    return ['still' => (bool)($out['still'] ?? true),
            'why'   => mb_substr(trim((string)($out['why'] ?? '')), 0, 240)];
}

/**
 * One more rewrite of one line, told exactly why the last one failed.
 *
 * The whole prompt is the line, the complaint, and the judge's objection —
 * no digest, no items, nothing else to get distracted by. What comes back
 * goes through the same doors as every other machine rewrite: the advice
 * check, the age floor, and a save with no learning signal.
 */
function xeric_repass_refix(array $w, array $endpoint, string $path, string $say, string $why,
                            ?callable $onNote = null): array
{
    $current = xeric_repass_current($w, $path);
    if ($current === null) return ['fixed' => false, 'fix' => '', 'note' => 'the line no longer exists'];

    $out = xeric_forge_ask($endpoint, 'repass-refix', [
        ['role' => 'system', 'content' =>
            'You rewrite one line of a story world so a stated complaint goes away — a contradiction '
            . 'resolved, or a flat line made particular without changing any fact it states. You reply '
            . 'with the replacement line itself, in the line\'s own voice: never advice, never an '
            . 'instruction, never "e.g.". Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            'THE LINE: ' . mb_substr($current, 0, 500)
            . "\nTHE COMPLAINT: " . $say
            . ($why !== '' ? "\nWHY THE LAST REWRITE FAILED: " . $why : '')
            . "\n\n" . '{"fix": "the replacement line"}'],
    ], ['temperature' => 0.5, 'max_tokens' => 400], $onNote);

    $fix = trim((string)($out['fix'] ?? ''));
    if ($fix === '' || xeric_repass_is_advice($fix)) return ['fixed' => false, 'fix' => '', 'note' => 'no usable rewrite came back'];

    $applied = xeric_repass_apply($w, [[
        'kind' => 'consistency', 'about' => $path, 'say' => $say, 'path' => $path, 'fix' => $fix,
    ]], ['mode' => 'sweep']);

    // `noop` rides out with it: a second rewrite that comes back as the line
    // already on disk is the editor saying this end is fine, and the caller
    // must not spend another attempt on it or call it a failure.
    return ['fixed' => $applied['fixed'] > 0, 'fix' => $fix,
            'noop' => !empty($applied['findings'][0]['noop']),
            'note' => $applied['fixed'] > 0 ? '' : (string)($applied['findings'][0]['say'] ?? '')];
}

/** Does this "rewrite" read as a note to the author rather than world text? */
function xeric_repass_is_advice(string $fix): bool
{
    $f = mb_strtolower($fix);
    if (str_contains($f, 'e.g.') || str_contains($f, 'for example') || str_contains($f, 'such as ')) return true;
    if (str_contains($f, 'the player') || str_contains($f, 'this item') || str_contains($f, 'the roster')) return true;
    return (bool)preg_match(
        '/^(connect|define|add|consider|establish|clarify|anchor|ensure|specify|rewrite|mention|include|make|introduce|give|remove|change)\b/',
        $f);
}

// ---------------------------------------------------------------------------
// The snake's shape: arithmetic, no model
// ---------------------------------------------------------------------------

function xeric_repass_snake_shape(array $s): array
{
    // Only what xeric_story_validate() does NOT already refuse. Monotonic
    // progress, 0..1 intensity, the 0..1 span, the ordered false calm and its
    // 0.5 landing are all validation, and a story that fails them never
    // reaches this function — xeric_story_for() throws and the throw is
    // reported as its own finding. What is left is shape that is LEGAL and
    // still bad drama.
    $key   = xeric_story_key($s);
    $about = "the story '$key'";
    $snake = (array)($s['snake'] ?? []);
    $out   = [];
    $note  = function (string $say) use (&$out, $about): void {
        $out[] = ['kind' => 'snake', 'about' => $about, 'say' => $say, 'path' => '', 'fix' => ''];
    };

    $maxI = -1.0;
    $maxAt = 0.0;
    $minI  = 2.0;
    foreach ((array)($snake['curve'] ?? []) as $pt) {
        if (!is_array($pt) || count($pt) !== 2) continue;
        if ((float)$pt[1] > $maxI) { $maxI = (float)$pt[1]; $maxAt = (float)$pt[0]; }
        if ((float)$pt[1] < $minI) { $minI = (float)$pt[1]; }
    }

    // A FLAT CURVE IS A DECISION, NOT BAD DRAMA. A world forged with no shape
    // hands its flat 0.5 down to any overlay laid on it, and every check below
    // would then fire on it — the peak "sits at the very front", the crescendo
    // "never clears the middle of the dial" — which is a report that somebody's
    // deliberately unpaced xeric is broken. It is not badly paced. It is not
    // paced, on purpose, and there is nothing here to say about it.
    if ($maxI <= $minI) return $out;
    if ($maxAt <= 0.1) $note('Its peak sits at the very front (p=' . $maxAt . ') — the story is loudest '
        . 'before anybody has met it, and everything after reads as closing.');
    if ($maxI < 0.6)   $note('Its loudest point is intensity ' . $maxI . ' — the crescendo never clears '
        . 'the middle of the dial, so the big night and an ordinary Tuesday play at nearly the same weight.');

    $fc = array_map('floatval', (array)($snake['false_calm'] ?? []));
    if (count($fc) === 2 && $fc[1] > $maxAt) {
        $note('Its false calm runs past the peak (' . $fc[1] . ' > ' . $maxAt . ') — the quiet before '
            . 'the storm is sitting on top of the storm.');
    }

    $beats = (array)($s['beats'] ?? []);
    if (count($beats) === 1 || count($beats) === 2) {
        $note('It has ' . count($beats) . ' beat(s) — under three, the snake has nothing to pace and '
            . 'the overlay is a single scene wearing a curve.');
    }
    // The back half of the story should still be opening things: a spine whose
    // last beat lands before the crescendo leaves the loudest stretch empty.
    $lastAt = -1.0;
    foreach ($beats as $b) if (is_array($b)) $lastAt = max($lastAt, (float)($b['at'] ?? -1));
    if ($lastAt >= 0 && count($fc) === 2 && $lastAt <= $fc[0]) {
        $note('Its last beat opens at ' . $lastAt . ', before the false calm even starts — nothing is '
            . 'left to happen in the crescendo, which is the stretch the whole curve exists to feed.');
    }

    return $out;
}
