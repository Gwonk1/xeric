<?php
/**
 * forge.php — world creation. A handful of answers in, a validated world out.
 *
 * The forge is the product's front door: the user answers four questions (or
 * presses ✨ and answers none of them) and this file builds a place that has
 * been running for months. It is the one part of Xeric allowed to be expensive,
 * because it runs once per world and everything after it runs on a small local
 * model.
 *
 * DISCIPLINES, all of them from FORGE.md and none of them optional:
 *
 *  • A WORLD IS ALWAYS LAUNCHABLE. Every pass is attempted, retried once, and
 *    then replaced by a deterministic hand-written default for that section.
 *    Nothing in here throws on a bad model; xeric_forge_build() cannot fail to
 *    return a template. What happened goes into `notes` so the review UI can be
 *    honest with the user ("the model's places didn't validate; used defaults")
 *    instead of pretending.
 *
 *  • NOTHING GENERATED IS LOAD-BEARING UNTIL IT VALIDATES. The assembled
 *    template goes through xeric_world_validate() before the caller sees it, and
 *    a template that fails is repaired (dangling references dropped) or
 *    defaulted — never handed onward broken.
 *
 *  • SURPRISE-ME DRAWS ONE CONCEPT, NOT SIX COIN FLIPS. Per-field randomness
 *    produces "the world stage + faith + found family", which is nothing. The
 *    fill picks a single row of interview.json's surprise_concepts and takes
 *    every unanswered field from that row.
 *
 *  • NO SEXUAL CONTENT INVOLVING A MINOR — AND NOTHING ELSE GATED. Every
 *    character carries an integer `age`, and the cast is written across a whole
 *    lifetime on purpose: children, teenagers, adults and old people. A minor
 *    is an ORDINARY character — orbit, schedule, secrets, witness, portrait,
 *    conversation — and the one thing he is kept out of is the desire economy,
 *    done structurally at assembly by xeric_forge_age_floor() rather than by
 *    asking a model to behave. A rule here that would keep a kid out of a
 *    sweep, off the shelf or out of a conversation is the rule written
 *    backwards. Death, crime and ordinary fictional violence are not gated at
 *    all; a murder mystery needs a body.
 *
 *  • WRITTEN TO THE 12B FLOOR. Prompts are short, JSON-only, one job per call,
 *    and ask for FLAT keys with small values — the nesting and the schema shape
 *    are assembled here, in code, where they cannot be got wrong. A small model
 *    is asked what a place smells like, never what a valid week[] block is.
 *
 * Zero dependencies. PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/../engine/world.php';   // xeric_world_validate(), the gate
require_once __DIR__ . '/../engine/llm.php';     // the only thing that talks HTTP
require_once __DIR__ . '/../engine/walls.php';   // the quotation test the forge checks its own output against
require_once __DIR__ . '/../engine/shape.php';   // the shape library, and the checker that disposes of an invented one

// ---------------------------------------------------------------------------
// The interview + the answers
// ---------------------------------------------------------------------------

/** Load interview.json. Questions are data so they can be edited without code. */
function xeric_forge_interview(?string $path = null): array
{
    $path = $path ?? __DIR__ . '/interview.json';
    $raw = @file_get_contents($path);
    if ($raw === false) throw new RuntimeException("forge: cannot read interview $path");
    $iv = json_decode($raw, true);
    if (!is_array($iv)) throw new RuntimeException("forge: bad JSON in $path: " . json_last_error_msg());
    return $iv;
}

/** Step keys the interview asks for, in order. */
function xeric_forge_step_keys(array $interview): array
{
    $out = [];
    foreach ((array)($interview['steps'] ?? []) as $s) {
        $k = (string)($s['key'] ?? '');
        if ($k !== '') $out[] = $k;
    }
    return $out;
}

/**
 * ✨ SURPRISE ME — fill whatever is unanswered with ONE coherent concept.
 *
 * With an endpoint, the model is asked for a single coherent set given what the
 * user already said. Without one (or when the model fumbles), a hand-written
 * concept from interview.json is drawn WHOLE. The one thing this function may
 * never do is mix two concepts: that is what makes surprise-me a product
 * instead of a dice roll, and the test suite asserts it.
 */
function xeric_forge_answers_fill(array $answers, array $interview, ?array $endpoint = null,
                                  bool $modelFill = true): array
{
    $answers = xeric_forge_answers_clean($answers);
    $gaps = xeric_forge_answer_gaps($answers, $interview);
    if ($gaps === []) return $answers;

    // WHAT THEY WROTE COMES FIRST. The premise screen promises, in as many
    // words, that "names, dates and facts you put here are kept" — and the
    // premise route then filled every unanswered question from the surprise
    // table, so somebody who typed "I am Neil Kessler, eighteen, Macomb
    // Illinois, 1997, my teacher Mr. Sanders teaches me chess" was handed a
    // world called Macomb in which they were Sam, forty, running a feed store,
    // and Mr. Sanders did not exist. The concept pass read the paragraph; no
    // other pass did.
    //
    // So the paragraph gets first refusal on every gap, and the table only
    // fills what the paragraph is genuinely silent about.
    $premise = trim((string)($answers['premise'] ?? ''));
    if ($premise !== '' && $endpoint) {
        try {
            $said = xeric_forge_answers_from_premise($premise, $interview, $gaps, $endpoint);
            $read = [];
            foreach ($gaps as $k) {
                if (isset($said[$k]) && $said[$k] !== '' && $said[$k] !== []) {
                    $answers[$k] = $said[$k];
                    $read[] = $k;
                }
            }
            // Never a gap and never asked: the people the paragraph names, for
            // the cast pass to write instead of inventing four strangers.
            if (!empty($said['people'])) { $answers['people'] = $said['people']; $read[] = 'people'; }
            // Which of these came from the paragraph rather than the table, so
            // the build log can say so. Stripped again by the caller.
            if ($read !== []) $answers['__read'] = $read;
            $gaps = xeric_forge_answer_gaps($answers, $interview);
            if ($gaps === []) return $answers;
        } catch (Throwable $e) {
            // A premise nobody could read is still a premise the concept pass
            // will use. Fall through rather than fail the build.
        }
    }

    if ($endpoint && $modelFill) {
        try {
            $filled = xeric_forge_fill_with_model($answers, $interview, $gaps, $endpoint);
            foreach ($gaps as $k) {
                if (isset($filled[$k]) && $filled[$k] !== '' && $filled[$k] !== []) $answers[$k] = $filled[$k];
            }
            // A name the MODEL chose gets the same freshness gate the cast
            // does — ✨ handed three different owners "Elias" before this
            // line. A name the user typed is never here: it was not a gap.
            if (in_array('name', $gaps, true) && is_string($answers['name'] ?? null)) {
                $answers['name'] = xeric_forge_fresh_person_name(
                    (string)$answers['name'], xeric_forge_naming($answers), 0, []);
            }
            $gaps = xeric_forge_answer_gaps($answers, $interview);
            if ($gaps === []) return $answers;
        } catch (Throwable $e) {
            // fall through to the table — a slow or stupid model must not cost
            // the user their surprise-me.
        }
    }

    $concept = xeric_forge_concept_pick($interview, $answers);
    foreach ($gaps as $k) {
        if (array_key_exists($k, $concept['answers'])) $answers[$k] = $concept['answers'][$k];
    }
    return $answers;
}

/**
 * Read the person, and the people, out of what somebody wrote.
 *
 * ONE CALL, AND IT MAY DECLINE. Every key is optional and an omitted key is the
 * right answer for a premise that does not mention it: a paragraph about a river
 * town that never says who the reader is leaves `name` alone and the surprise
 * table fills it, exactly as before. What this must never do is invent — a
 * guessed name is worse than a table's, because the table is honestly arbitrary
 * and a guess reads as though the paragraph said it.
 *
 * `centrality` is the one worth spelling out to the model. "I am X and this is
 * my story about the man who saved me" is a MAIN answer, and getting it wrong is
 * what put a supporting character's arc at the centre of somebody's own life
 * story and then reported the mismatch back to them as a contradiction.
 *
 * `people` is not an interview key. It is the names the paragraph gives, with
 * what it says about each, and it goes to the cast pass so those people get
 * written instead of four strangers.
 *
 * @return array<string,mixed> only the keys the premise actually states
 */
function xeric_forge_answers_from_premise(string $premise, array $interview, array $gaps,
                                          array $endpoint, ?callable $onNote = null): array
{
    $premise = mb_substr($premise, 0, 4000);

    // What each gap is, in the model's terms, and what it may answer with.
    $ask = [];
    $byKey = [];
    foreach ((array)($interview['steps'] ?? []) as $s) $byKey[(string)($s['key'] ?? '')] = $s;
    foreach ($gaps as $k) {
        $s = $byKey[$k] ?? null;
        $opts = [];
        foreach ((array)($s['presets'] ?? []) as $p) {
            $v = (string)($p['value'] ?? '');
            if ($v !== '') $opts[] = $v;
        }
        $hint = trim((string)($s['fill_hint'] ?? ''));
        $q    = trim((string)($s['question'] ?? ''));
        $line = '  "' . $k . '": ';
        if ($k === 'themes')      $line .= '["2-3 one-word themes the paragraph is actually about"]';
        elseif ($k === 'circle')  $line .= '"who is around them, 2-4 words"';
        elseif ($opts !== [])     $line .= '"one of: ' . implode(' | ', $opts) . '"';
        else                      $line .= '"' . ($hint !== '' ? $hint : $q) . '"';
        $ask[] = $line;
    }
    if ($ask === []) $ask[] = '  "name": "the person\'s name, if it says"';

    $out = xeric_forge_attempt('reading what you wrote', function () use ($endpoint, $premise, $ask, $onNote) {
        $raw = xeric_forge_ask($endpoint, 'premise', [
            ['role' => 'system', 'content' =>
                'You read a person\'s description of the story world they want and pull out only the '
                . 'facts they actually stated. You never invent. A key you are not sure about is a key '
                . 'you leave out entirely. Reply with ONE JSON object and nothing else.'],
            ['role' => 'user', 'content' =>
                "Here is what they wrote:\n\n\"\"\"\n$premise\n\"\"\"\n\n"
                . "Answer ONLY with what this paragraph says or plainly implies. Leave out every key it "
                . "does not answer — an incomplete object is the correct reply, an invented value is not.\n\n"
                . "If they wrote \"I am <name>\", that is their name. If they describe their own life, "
                . "their own arc, or what happens to them, centrality is \"main\"; if they describe a "
                . "place and its people with themselves among them, \"ensemble\"; if they are watching "
                . "somebody else's story, \"side\".\n\n"
                . "{\n" . implode(",\n", $ask) . ",\n"
                . "  \"people\": [{\"name\":\"a person the paragraph names\",\"who\":\"what it says about "
                . "them, one short phrase\"}]\n}\n"
                . "`people` holds only people the paragraph actually names, and never the writer "
                . "themselves. No prose outside the JSON."],
        ], ['temperature' => 0.2, 'max_tokens' => 700], $onNote);

        if (!is_array($raw) || $raw === []) throw new RuntimeException('nothing readable came back');
        return $raw;
    }, fn() => [], $onNote);

    // Shape it against the interview: a choice step may only answer with one of
    // its own values, so a model that writes "main character" cannot smuggle an
    // unknown centrality into the template.
    $clean = [];
    foreach ($gaps as $k) {
        if (!array_key_exists($k, $out)) continue;
        $v = $out[$k];
        if ($k === 'themes') {
            $list = xeric_forge_list($v, 3, 40);
            if ($list !== []) $clean[$k] = $list;
            continue;
        }
        $s = $byKey[$k] ?? null;
        $opts = [];
        foreach ((array)($s['presets'] ?? []) as $p) {
            $val = (string)($p['value'] ?? '');
            if ($val !== '') $opts[] = $val;
        }
        $str = xeric_forge_str($v, '', 160);
        if ($str === '') continue;
        // A closed step (centrality, scale) takes only its own vocabulary; an
        // open one (name, job) takes the words, because that is the point.
        if ($opts !== [] && empty($s['allow_free_text'])) {
            $pick = xeric_forge_pick_key($str, $opts, '');
            if ($pick !== '') $clean[$k] = $pick;
            continue;
        }
        $clean[$k] = $str;
    }

    $people = [];
    foreach ((array)($out['people'] ?? []) as $p) {
        if (!is_array($p)) continue;
        $n = xeric_forge_str($p['name'] ?? '', '', 60);
        if ($n === '') continue;
        $people[] = ['name' => $n, 'who' => xeric_forge_str($p['who'] ?? '', '', 160)];
        if (count($people) >= 6) break;
    }
    if ($people !== []) $clean['people'] = $people;

    return $clean;
}

/** Which answers are missing and may be filled by ✨. */
function xeric_forge_answer_gaps(array $answers, array $interview): array
{
    $gaps = [];
    foreach ((array)($interview['steps'] ?? []) as $s) {
        $k = (string)($s['key'] ?? '');
        if ($k === '' || empty($s['allow_surprise'])) continue;
        if (!isset($answers[$k]) || $answers[$k] === '' || $answers[$k] === []) $gaps[] = $k;
    }
    // themes and circle are never asked in the slice but the concepts carry
    // them and the later passes read them, so they count as gaps too.
    foreach (['themes', 'circle'] as $k) {
        if (!isset($answers[$k]) || $answers[$k] === '' || $answers[$k] === []) $gaps[] = $k;
    }
    return $gaps;
}

/**
 * Which hand-written concept to draw from.
 *
 * Scored, not random: a user who already said "a city" gets a city concept, so
 * the fill agrees with the answers instead of overwriting their meaning. Ties
 * break randomly, which is where the ✨ variety actually comes from.
 */
function xeric_forge_concept_pick(array $interview, array $answers): array
{
    $rows = (array)($interview['surprise_concepts'] ?? []);
    if ($rows === []) throw new RuntimeException('forge: interview.json has no surprise_concepts');

    $best = -1;
    $pool = [];
    foreach ($rows as $row) {
        $a = (array)($row['answers'] ?? []);
        $score = 0;
        foreach ($a as $k => $v) {
            if (!isset($answers[$k]) || !is_string($v) || !is_string($answers[$k])) continue;
            if (strcasecmp(trim($answers[$k]), trim($v)) === 0) $score++;
        }
        if ($score > $best) { $best = $score; $pool = [$row]; }
        elseif ($score === $best) { $pool[] = $row; }
    }
    $row = $pool[array_rand($pool)];
    $row['answers'] = (array)($row['answers'] ?? []);
    return $row;
}

/** One small call: the missing answers, coherent with the given ones. */
function xeric_forge_fill_with_model(array $answers, array $interview, array $gaps, array $endpoint): array
{
    $known = [];
    foreach ($answers as $k => $v) {
        if (in_array($k, $gaps, true)) continue;
        $known[] = "- $k: " . (is_array($v) ? implode(', ', array_map('strval', $v)) : (string)$v);
    }
    $wants = [];
    foreach ((array)($interview['steps'] ?? []) as $s) {
        $k = (string)($s['key'] ?? '');
        if (!in_array($k, $gaps, true)) continue;
        $opts = [];
        foreach ((array)($s['presets'] ?? []) as $p) $opts[] = (string)($p['value'] ?? '');
        // A step may carry `fill_hint` for ✨: the QUESTION is written for a
        // human reading it on a screen, and a small model handed "name: a short
        // phrase // What do people call you?" answers with the name of the
        // world. The hint says what the field IS, in the model's terms.
        $hint = trim((string)($s['fill_hint'] ?? ''));
        $wants[] = '"' . $k . '": '
            . ($opts ? 'one of ' . implode(' | ', array_filter($opts)) : ($hint !== '' ? $hint : 'a short phrase'))
            . '   // ' . (string)($s['question'] ?? '');
    }
    if (in_array('themes', $gaps, true)) {
        $wants[] = '"themes": a list of exactly 3, chosen from: '
            . implode(', ', array_map('strval', (array)($interview['themes'] ?? [])));
    }
    if (in_array('circle', $gaps, true)) {
        $wants[] = '"circle": one of living family | found family | coworkers | congregation | crew | strangers';
    }

    $msgs = [
        ['role' => 'system', 'content' =>
            'You design a single coherent premise for a character-driven story world. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            ($known ? "Already decided:\n" . implode("\n", $known) . "\n\n" : '')
            . "Fill in the rest so the whole thing is ONE premise that hangs together — "
            . "not a mix of unrelated ideas.\n\nJSON keys, exactly these:\n{\n  "
            . implode(",\n  ", $wants) . "\n}\nNo prose outside the JSON."],
    ];
    return xeric_forge_ask($endpoint, 'fill', $msgs, ['temperature' => 1.0, 'max_tokens' => 500]);
}

/** Trim strings, drop empties, normalise the two list-shaped answers. */
function xeric_forge_answers_clean(array $answers): array
{
    $out = [];
    foreach ($answers as $k => $v) {
        $k = (string)$k;
        if ($k === 'themes') {
            $out[$k] = is_array($v) ? array_values(array_filter(array_map('trim', array_map('strval', $v))))
                                    : array_values(array_filter(array_map('trim', explode(',', (string)$v))));
            continue;
        }
        if (is_array($v)) { $out[$k] = $v; continue; }
        $s = trim((string)$v);
        if ($s !== '') $out[$k] = $s;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// The model seam + the failure discipline
// ---------------------------------------------------------------------------

/**
 * The ONLY place the forge talks to a model.
 *
 * `$endpoint['stub']` is a test seam: a callable answering in-process, so the
 * whole forge — every pass, every fallback — is testable with no network and no
 * model. `$tag` names the pass so a stub can answer differently per pass.
 * An empty endpoint means "no model available": passes go straight to defaults.
 *
 * A `timeout` on the ENDPOINT is the per-call ceiling for every pass that will
 * ever use it. It rides the descriptor rather than the opts because the passes
 * take their arguments one at a time and there are nine of them, and because
 * the ceiling is a property of who is being called, not of what is being asked.
 * llm.php's own default is XERIC_LLM_TIMEOUT — ten minutes, times the one
 * retry, against a queue that hands the GPU on after seven.
 */
function xeric_forge_ask(array $endpoint, string $tag, array $messages, array $opts = [], ?callable $onNote = null): array
{
    if (!isset($opts['timeout']) && isset($endpoint['timeout'])) $opts['timeout'] = (int)$endpoint['timeout'];
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
        $out = ($endpoint['stub'])($tag, $messages, $opts);
        if (!is_array($out)) throw new RuntimeException("forge: stub for '$tag' returned no object");
        return $out;
    }
    if (($endpoint['base'] ?? '') === '') throw new RuntimeException("forge: no model endpoint for '$tag'");
    return xeric_llm_json($endpoint, $messages, $opts, $onNote);
}

/**
 * Try, retry once, then take the hand-written default.
 *
 * Every pass and every sub-call goes through here. This is the whole of the
 * failure discipline: the user gets a world even when the model is down, and
 * the note says which parts of it the model actually wrote.
 */
function xeric_forge_attempt(string $what, callable $attempt, callable $fallback, ?callable $onNote = null): array
{
    $last = '';
    for ($try = 1; $try <= 2; $try++) {
        try {
            return $attempt($try);
        } catch (Throwable $e) {
            $last = $e->getMessage();
            if ($try === 1) xeric_forge_note($onNote, "$what failed ($last) — retrying once");
        }
    }
    xeric_forge_note($onNote, "$what failed twice ($last) — used the built-in default");
    return $fallback();
}

function xeric_forge_note(?callable $onNote, string $msg): void
{
    if ($onNote) $onNote($msg);
}

// ---------------------------------------------------------------------------
// The naming register — one palette per world, carried into every pass
// ---------------------------------------------------------------------------
//
// Eight worlds on the shelf, and Elias Thorne lived in every one of them. A
// fog town in the 1850s, a Mojave truck stop, a near-future port — and the
// same man, sometimes twice (Silas Vane appeared verbatim in two worlds; a
// space-opera forged later carried Kaelen Voss VERBATIM out of a 19th-century
// fog town, plus Vance, Vane and Thorne for good measure). Thorne/Vane/Vance/
// Voss covered 27 of the shelf's 32 surnames. Six towns had a Rusty-something,
// five a St. Jude's, three an Ozone-something. The genre changed completely
// and the names did not, which settles the diagnosis: every pass is a separate
// model call with no memory of any other world, so each world independently
// converges on the statistically safest tokens. Context does not fix that.
// Nothing about "a hyper-capitalist luxury hub" makes 'Kaelen Voss' less
// probable; only different raw material does.
//
// The cure is the one already proven inside a single world by the vocal-shape
// fix (xeric_forge_dedupe_cast): ASSIGN diversity up front, then GUARANTEE it
// deterministically, because a prompt ban alone is a hope, not a gate.
//
//   1. A REGISTER is chosen per world — a naming culture with hand-written
//      banks (registers.json): given names, surnames, toponyms, business
//      names, a church. Chosen deterministically from the world's own answers
//      (never the date, never rand()), so a reroll of one character lands in
//      the same register as the world around it. Every pass that invents a
//      proper noun quotes the register in its prompt.
//   2. THE GATES run after every model reply and cannot be talked out of it:
//      the observed repeat offenders (banned lists in registers.json, shapes
//      in code below) and anything already worn by a world on the shelf are
//      replaced from the register's banks, walked by position exactly the way
//      the dedupe banks are. The shelf read is what gives the forge cross-
//      world memory: the third world in the directory cannot reuse the first
//      world's cast, because the first world's names are sitting right there.
//
// TEMPERATURE WAS EVALUATED AND LEFT ALONE. llm.php takes a per-call
// temperature and nothing finer; names ride the same JSON calls as the
// structure, so "hotter names" would mean hotter braces too, and the 12B
// floor pays for that in repair rounds. A register moves the distribution
// further than heat ever did — heat samples the same valley more noisily,
// the register moves the valley.
//
// Data-file failure is a seasoning failure, not a build failure: a missing or
// unreadable registers.json quietly degrades to shelf-only gates (contrast
// interview.json, which throws — the interview is the product).

/** registers.json, memoized per path. Never throws; see the section comment. */
function xeric_forge_registers(?string $path = null): array
{
    static $memo = [];
    $path = $path ?? __DIR__ . '/registers.json';
    if (isset($memo[$path])) return $memo[$path];
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) $data = [];
    return $memo[$path] = [
        'registers' => array_values((array)($data['registers'] ?? [])),
        'banned'    => (array)($data['banned'] ?? []),
    ];
}

/**
 * Where the shelf is — the worlds directory the cross-world gates read.
 *
 * Pass a directory to set it (a build with $opts['worlds_dir'] does, and the
 * tests pin it so they cannot be haunted by whatever worlds the repo happens
 * to carry). Pass '' to drop back to the default: the deployment's own
 * XERIC_WORLDS_DIR when set — the same variable the web layer resolves its
 * worlds directory from — else the repo's worlds/ beside this file.
 */
function xeric_forge_shelf(?string $dir = null): string
{
    static $set = null;
    if ($dir !== null) $set = $dir === '' ? null : rtrim($dir, '/');
    if ($set !== null) return $set;
    $env = (string)(getenv('XERIC_WORLDS_DIR') ?: '');
    return $env !== '' ? rtrim($env, '/') : dirname(__DIR__) . '/worlds';
}

/** Lowercased, punctuation-free, article-free — the form every name gate compares in. */
function xeric_forge_name_norm(string $s): string
{
    $s = xeric_forge_slug($s, ' ');
    return (string)preg_replace('/^(the|a|an) /', '', $s);
}

/** "Pastor Dale Ostrander" → ['Dale', 'Ostrander']. A single word has no surname. */
function xeric_forge_name_split(string $name): array
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $titles = ['mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'doc', 'rev', 'reverend', 'pastor', 'father',
               'sister', 'brother', 'coach', 'deputy', 'sheriff', 'judge', 'captain', 'nurse'];
    while (count($parts) > 1 && in_array(strtolower(rtrim((string)$parts[0], '.')), $titles, true)) {
        array_shift($parts);
    }
    $first = (string)($parts[0] ?? '');
    $last  = count($parts) > 1 ? (string)end($parts) : '';
    return [$first, $last];
}

/**
 * Every proper noun the shelf has already spent, read once per process.
 *
 * This is the forge's only memory of other worlds, and it is exactly as
 * durable as the worlds themselves: nothing is written anywhere, the names
 * are simply read back off world-template.json. Capped so a hoarder's shelf
 * cannot make every build reread hundreds of files.
 */
function xeric_forge_shelf_names(?string $dir = null): array
{
    static $memo = [];
    $dir = rtrim($dir ?? xeric_forge_shelf(), '/');
    if (isset($memo[$dir])) return $memo[$dir];
    $out = ['given' => [], 'family' => [], 'worlds' => [], 'places' => []];
    foreach (array_slice(glob($dir . '/*/world-template.json') ?: [], 0, 80) as $f) {
        $t = json_decode((string)@file_get_contents($f), true);
        if (!is_array($t)) continue;
        $w = xeric_forge_name_norm((string)($t['meta']['name'] ?? ''));
        if ($w !== '') $out['worlds'][$w] = true;
        foreach ((array)($t['cast']['characters'] ?? []) as $c) {
            if (!is_array($c)) continue;
            [$first, $last] = xeric_forge_name_split((string)($c['display_name'] ?? ''));
            if ($first !== '') $out['given'][xeric_forge_name_norm($first)] = true;
            if ($last !== '')  $out['family'][xeric_forge_name_norm($last)] = true;
        }
        foreach ((array)($t['places'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $n = xeric_forge_name_norm((string)($p['name'] ?? ''));
            if ($n !== '') $out['places'][$n] = true;
        }
    }
    return $memo[$dir] = $out;
}

/**
 * The naming context every pass reads: the chosen register, the overflow
 * banks, the banned lists as sets, and what the shelf already spent.
 *
 * DETERMINISTIC ON THE ANSWERS, and only the answers. The same answers pick
 * the same register, which is what keeps a character reroll in the same
 * register as the world it happens in — forge.answers ride the template for
 * exactly this kind of caller. Two worlds forged from identical answers land
 * in the same register too, and that is fine: the shelf gates make the second
 * one walk further down the same banks rather than repeat the first.
 */
function xeric_forge_naming(array $answers): array
{
    $data = xeric_forge_registers();
    $rows = $data['registers'];
    $bans = $data['banned'];
    $used = xeric_forge_shelf_names();

    $n = count($rows);
    $i = 0;
    $row = [];
    if ($n > 0) {
        $seed = mb_strtolower(implode('|', [
            (string)($answers['name'] ?? ''), (string)($answers['job'] ?? ''),
            (string)($answers['motivation'] ?? ''), (string)($answers['scale'] ?? ''),
            mb_substr((string)($answers['premise'] ?? ''), 0, 200),
        ]));
        $i = (crc32($seed) & 0x7fffffff) % $n;
        $row = (array)$rows[$i];
    }

    // The other registers' banks, in rotation from the chosen one, so a bank
    // that runs dry borrows a neighbour's rather than giving up. Twelve
    // registers deep, nothing here runs dry before a cast of a hundred.
    $over = ['given' => [], 'family' => [], 'toponyms' => [], 'businesses' => []];
    for ($k = 1; $k < $n; $k++) {
        $r2 = (array)$rows[($i + $k) % $n];
        foreach (array_keys($over) as $bank) {
            foreach ((array)($r2[$bank] ?? []) as $v) $over[$bank][] = (string)$v;
        }
    }

    $set = static function ($list): array {
        $out = [];
        foreach ((array)$list as $v) {
            $k = xeric_forge_name_norm((string)$v);
            if ($k !== '') $out[$k] = true;
        }
        return $out;
    };

    return [
        'key'    => (string)($row['key'] ?? ''),
        'label'  => (string)($row['label'] ?? ''),
        'sound'  => (string)($row['sound'] ?? ''),
        'given'  => array_map('strval', (array)($row['given'] ?? [])),
        'family' => array_map('strval', (array)($row['family'] ?? [])),
        'toponyms'   => array_map('strval', (array)($row['toponyms'] ?? [])),
        'businesses' => array_map('strval', (array)($row['businesses'] ?? [])),
        'church' => (string)($row['church'] ?? ''),
        'over'   => $over,
        'banned_given'  => $set($bans['given'] ?? []),
        'banned_family' => $set($bans['family'] ?? []),
        // kept as plain lists too, because the prompts say them out loud
        'ban_given_say'  => array_map('strval', (array)($bans['given'] ?? [])),
        'ban_family_say' => array_map('strval', (array)($bans['family'] ?? [])),
        'banned_world'  => array_map(fn($v) => xeric_forge_name_norm((string)$v), (array)($bans['world_words'] ?? [])),
        'banned_suffix' => array_map(fn($v) => xeric_forge_name_norm((string)$v), (array)($bans['world_suffixes'] ?? [])),
        'used' => $used,
    ];
}

/** A few bank entries for a prompt, rotated by position so calls differ. */
function xeric_forge_naming_examples(array $naming, string $bank, int $n, int $rot = 0): string
{
    $rows = (array)($naming[$bank] ?? []);
    if ($rows === []) return '';
    $out = [];
    for ($k = 0; $k < min($n, count($rows)); $k++) $out[] = (string)$rows[($rot + $k) % count($rows)];
    return implode(', ', $out);
}

/** May a world be called this? Bans by word, by suffix, by the Low-<thing> shape, by shelf reuse. */
function xeric_forge_world_name_ok(string $name, array $naming): bool
{
    $n = xeric_forge_name_norm($name);
    if ($n === '') return false;
    if (isset($naming['used']['worlds'][$n])) return false;
    foreach ((array)$naming['banned_world'] as $w) {
        if ($w !== '' && str_contains($n, $w)) return false;
    }
    foreach ((array)$naming['banned_suffix'] as $sfx) {
        if ($sfx !== '' && ($n === $sfx || str_ends_with($n, ' ' . $sfx))) return false;
    }
    if (preg_match('/^low /', $n) === 1) return false;
    return true;
}

/**
 * The world's name, kept or replaced. A name the premise itself contains is
 * the user's decision and is never touched, banned or not — somebody who
 * typed "a town called Blackwood, like my grandmother's" has already chosen.
 */
function xeric_forge_fresh_world_name(string $name, array $naming, string $premise = ''): string
{
    if ($name === '') return $name;
    if ($premise !== '' && mb_stripos($premise, $name) !== false) return $name;
    if (xeric_forge_world_name_ok($name, $naming)) return $name;
    $bank = array_merge((array)$naming['toponyms'], (array)($naming['over']['toponyms'] ?? []));
    if ($bank === []) return $name;
    $start = (crc32(xeric_forge_name_norm($name)) & 0x7fffffff) % count($bank);
    for ($k = 0; $k < count($bank); $k++) {
        $cand = (string)$bank[($start + $k) % count($bank)];
        if (xeric_forge_world_name_ok($cand, $naming)) return $cand;
    }
    return $name;
}

/** May a place be called this? The Rusty-<thing> and St.-Jude shapes live here, in code, on purpose. */
function xeric_forge_place_name_ok(string $name, array $naming): bool
{
    $n = xeric_forge_name_norm($name);
    if ($n === '') return false;
    if (isset($naming['used']['places'][$n])) return false;
    if (preg_match('/\brusty\b/', $n) === 1) return false;
    // "St. Jude's" normalises to "st judes" — the slug DROPS apostrophes
    // rather than spacing them — so the possessive is matched with the s on.
    if (preg_match('/\b(st|saint) judes?\b/', $n) === 1) return false;
    if (preg_match('/^low /', $n) === 1) return false;
    foreach ((array)$naming['banned_world'] as $w) {
        if ($w !== '' && str_contains($n, $w)) return false;
    }
    return true;
}

/**
 * A place's name, kept or replaced. `$used` is this world's own ledger, by
 * reference, so six replacements in one pass cannot all be the same bakery.
 * A church takes the register's congregation first; everything else walks the
 * business bank; and the last resort is BUILT rather than picked — a surname
 * over the door and the kind under it — because a fallback that can run out
 * is two failures for the price of one.
 */
function xeric_forge_fresh_place_name(string $name, string $kind, array $naming, int $i, array &$used): string
{
    $norm = xeric_forge_name_norm($name);
    if ($name !== '' && xeric_forge_place_name_ok($name, $naming) && !isset($used[$norm])) {
        $used[$norm] = true;
        return $name;
    }
    // A church gets the register's congregation before anything else — the
    // walk below starts at $i, and a bakery is a poor stand-in for a parish.
    if (strtolower($kind) === 'church' && (string)$naming['church'] !== '') {
        $cn = xeric_forge_name_norm((string)$naming['church']);
        if (xeric_forge_place_name_ok((string)$naming['church'], $naming) && !isset($used[$cn])) {
            $used[$cn] = true;
            return (string)$naming['church'];
        }
    }
    $bank = [];
    foreach ((array)$naming['businesses'] as $b) $bank[] = (string)$b;
    foreach ((array)($naming['over']['businesses'] ?? []) as $b) $bank[] = (string)$b;
    for ($k = 0; $k < count($bank); $k++) {
        $cand = (string)$bank[($i + $k) % count($bank)];
        $cn = xeric_forge_name_norm($cand);
        if (xeric_forge_place_name_ok($cand, $naming) && !isset($used[$cn])) {
            $used[$cn] = true;
            return $cand;
        }
    }
    $word = ['diner' => 'Diner', 'cafe' => 'Cafe', 'bar' => 'Tavern', 'club' => 'Club', 'church' => 'Chapel',
             'school' => 'School', 'clinic' => 'Clinic', 'shop' => 'General', 'market' => 'Market',
             'office' => 'Office', 'gym' => 'Gym', 'park' => 'Green', 'home' => 'House', 'site' => 'Yard',
             'station' => 'Depot', 'hall' => 'Hall'][strtolower($kind)] ?? ucfirst($kind !== '' ? $kind : 'Place');
    foreach (array_merge((array)$naming['family'], (array)($naming['over']['family'] ?? [])) as $f) {
        $cand = $f . "'s " . $word;
        $cn = xeric_forge_name_norm($cand);
        if (xeric_forge_place_name_ok($cand, $naming) && !isset($used[$cn])) {
            $used[$cn] = true;
            return $cand;
        }
    }
    $used[$norm] = true;
    return $name;
}

/**
 * A person's name, kept or replaced — the gate behind the cast prompt's ban.
 *
 * Each half is judged on its own: a banned or shelf-worn first name costs the
 * first name, a banned or shelf-worn surname costs the surname, and whatever
 * was fine stays. Replacements walk the register's banks from this
 * character's own index, exactly the way the dedupe banks are walked, so two
 * renamed characters cannot land on the same replacement either.
 *
 * A name the brief itself contains is kept whole whatever the shelf thinks:
 * "my teacher Mr. Sanders" has named a person, and renaming somebody the user
 * asked for by name would be the premise promise broken a second way.
 * Surname reuse WITHIN a world is deliberately not gated here — two Blevinses
 * in one town are a family; the same Blevins in three worlds is a rut.
 */
function xeric_forge_fresh_person_name(string $name, array $naming, int $index, array $taken, string $brief = ''): string
{
    [$first, $last] = xeric_forge_name_split($name);
    if ($first === '') return $name;
    if ($brief !== '') {
        foreach ([$first, $last] as $part) {
            if (mb_strlen($part) >= 3 && mb_stripos($brief, $part) !== false) return $name;
        }
    }
    $nf = xeric_forge_name_norm($first);
    $nl = xeric_forge_name_norm($last);
    $badFirst = isset($naming['banned_given'][$nf]) || isset($naming['used']['given'][$nf]);
    $badLast  = $last !== '' && (isset($naming['banned_family'][$nl]) || isset($naming['used']['family'][$nl]));
    if (!$badFirst && !$badLast) return $name;

    if ($badFirst) {
        $bank = array_merge((array)$naming['given'], (array)($naming['over']['given'] ?? []));
        for ($k = 0; $k < count($bank); $k++) {
            $cand = (string)$bank[($index + $k) % count($bank)];
            $cn = xeric_forge_name_norm($cand);
            if (!isset($naming['banned_given'][$cn]) && !isset($naming['used']['given'][$cn])
                && !isset($taken[xeric_forge_key(trim($cand . ' ' . $last))])) {
                $first = $cand;
                break;
            }
        }
    }
    if ($badLast) {
        $bank = array_merge((array)$naming['family'], (array)($naming['over']['family'] ?? []));
        for ($k = 0; $k < count($bank); $k++) {
            $cand = (string)$bank[($index + $k) % count($bank)];
            $cn = xeric_forge_name_norm($cand);
            if (!isset($naming['banned_family'][$cn]) && !isset($naming['used']['family'][$cn])
                && !isset($taken[xeric_forge_key($first . ' ' . $cand)])) {
                $last = $cand;
                break;
            }
        }
    }
    return trim($first . ($last !== '' ? ' ' . $last : ''));
}

// ---------------------------------------------------------------------------
// Pass 1 — concept. The world's voice; everything downstream quotes it.
// ---------------------------------------------------------------------------

/**
 * @return array{meta:array,setting:array,world_mood:array}
 */
function xeric_forge_pass_concept(array $answers, array $endpoint, ?callable $onNote = null): array
{
    $scale = xeric_forge_human((string)($answers['scale'] ?? 'small_town'));
    $who   = xeric_forge_str($answers['name'] ?? '', 'you', 60);
    $job   = xeric_forge_str($answers['job'] ?? '', 'work', 120);
    $why   = xeric_forge_human((string)($answers['motivation'] ?? 'company'));
    $rating = xeric_forge_rating($answers);
    $themes = xeric_forge_list($answers['themes'] ?? [], 4, 40);
    // WHAT SOMEBODY BROUGHT WITH THEM. The interview asks about the person and a
    // few knobs; it never asks what the world IS, because normally the model is
    // the one inventing that. When a premise is given, it stops being an
    // invention and becomes a brief.
    $premise = xeric_forge_str($answers['premise'] ?? '', '', 2000);

    // The register rides into the prompt, and the gate waits on the far side
    // of the reply. Both halves matter: the prompt is what makes the model
    // WRITE in the register, the gate is what makes a shelf of worlds stay
    // distinct when it ignores the prompt anyway.
    $naming = xeric_forge_naming($answers);
    $nameBlock = '';
    if ($naming['key'] !== '') {
        $tops = xeric_forge_naming_examples($naming, 'toponyms', 3);
        $worn = array_slice(array_keys((array)$naming['used']['worlds']), 0, 8);
        $nameBlock =
            ($premise !== '' ? 'If the description above already names the place or its people, those names win. ' : '')
            . "Otherwise: the people of this place are named out of {$naming['sound']}. "
            . "Let the place's own name come off the same shelf — the sound of {$tops}, not a copy of them. "
            . 'Do not call it Oakhaven or Blackwood, nothing ending in Creek or Hollow'
            . ($worn !== [] ? ', and not ' . implode(', ', array_map('ucwords', $worn)) . ' — those are taken' : '')
            . ".\n\n";
    }

    xeric_forge_note($onNote, $premise !== '' ? 'concept: reading what you wrote'
                                              : 'concept: inventing the place');

    return xeric_forge_attempt('concept', function () use ($endpoint, $scale, $who, $job, $why, $rating, $themes, $premise, $answers, $naming, $nameBlock, $onNote) {
        $msgs = [
            ['role' => 'system', 'content' =>
                'You invent settings for a character-driven story world. Concrete, specific, '
                . 'no fantasy unless asked. Reply with ONE JSON object and nothing else.'],
            ['role' => 'user', 'content' =>
                // FIRST, AND SAID TO BE BINDING. It is above the knobs because a
                // model reading "a river town in Ohio" under four lines of
                // demographics treats it as one more preference to average in.
                // Somebody who typed a paragraph has already decided.
                ($premise !== ''
                    ? "The world has already been described by the person who will live in it. "
                    . "This is not a suggestion to blend with the details below — it is the "
                    . "world. Keep its place, its era, its names and its facts. Where it is "
                    . "silent, invent something that fits it:\n\n\"\"\"\n$premise\n\"\"\"\n\n"
                    : '')
                . "The person who will live in this world:\n"
                . "- scale: $scale\n- name: $who\n- what they do: $job\n- why they are here: $why\n"
                . ($themes ? '- themes: ' . implode(', ', $themes) . "\n" : '')
                . "- content rating: " . xeric_rating_label($rating) . '. ' . xeric_rating_style($rating) . "\n\n"
                . $nameBlock
                . ($premise !== ''
                    ? "Now write that place up. ONE JSON object, exactly these keys:\n"
                    : "Invent the place. ONE JSON object, exactly these keys:\n")
                . "{\n"
                . "  \"name\": \"the name of the place, 1-3 words\",\n"
                . "  \"description\": \"one sentence, under 20 words\",\n"
                . "  \"locale\": \"short phrase, e.g. 'a river town of nine hundred in southern Ohio'\",\n"
                . "  \"era\": \"short phrase, e.g. 'present day'\",\n"
                . "  \"texture\": [\"4 short sensory details, under 10 words each\"],\n"
                . "  \"canon_rules\": [\"3 hard facts about this place the story must always obey, one sentence each\"],\n"
                . "  \"mood_high\": \"short phrase: what this place is like on its wildest nights\",\n"
                . "  \"mood_low\": \"short phrase: what it is like when people are kind for no reason\",\n"
                . "  \"motifs_dark\": [\"3 short images for the wild end\"],\n"
                . "  \"motifs_light\": [\"3 short images for the kind end\"],\n"
                . "  \"themes\": [\"3 one-or-two-word themes\"]\n"
                . "}\nNo prose outside the JSON."],
        ];
        $flat = xeric_forge_ask($endpoint, 'concept', $msgs, ['temperature' => 1.0, 'max_tokens' => 900], $onNote);
        // The gate. A banned or shelf-worn name is swapped for a register
        // toponym HERE, before anything downstream quotes it into prose.
        $was = xeric_forge_str($flat['name'] ?? '', '', 60);
        $fresh = xeric_forge_fresh_world_name($was, $naming, $premise);
        if ($fresh !== $was) {
            xeric_forge_note($onNote, "naming: the model called it {$was} — every shelf has one of those; here it is {$fresh}");
            $flat['name'] = $fresh;
        }
        return xeric_forge_concept_from($flat, $answers);
    }, fn() => xeric_forge_default_concept($answers), $onNote);
}

/** Shape + sanitize a concept reply. Throws when the model gave us nothing usable. */
function xeric_forge_concept_from(array $flat, array $answers): array
{
    $name = xeric_forge_str($flat['name'] ?? '', '', 60);
    if ($name === '') throw new RuntimeException('concept has no name');
    $def = xeric_forge_default_concept($answers);

    $texture = xeric_forge_list($flat['texture'] ?? [], 6, 120);
    $rules   = xeric_forge_list($flat['canon_rules'] ?? [], 5, 200);
    $themes  = xeric_forge_list($flat['themes'] ?? ($answers['themes'] ?? []), 4, 40);

    return [
        'meta' => [
            'name'        => $name,
            'description' => xeric_forge_str($flat['description'] ?? '', $def['meta']['description'], 200),
            // The rating is the USER's answer, never the model's opinion: a
            // model must not be able to raise the ceiling on a world.
            'rating'      => xeric_forge_rating($answers),
            'themes'      => $themes ?: $def['meta']['themes'],
        ],
        'setting' => [
            'locale'      => xeric_forge_str($flat['locale'] ?? '', $def['setting']['locale'], 200),
            'era'         => xeric_forge_str($flat['era'] ?? '', $def['setting']['era'], 80),
            'texture'     => $texture ?: $def['setting']['texture'],
            'canon_rules' => $rules ?: $def['setting']['canon_rules'],
        ],
        'world_mood' => [
            'axis' => [
                'positive' => xeric_forge_str($flat['mood_high'] ?? '', $def['world_mood']['axis']['positive'], 160),
                'negative' => xeric_forge_str($flat['mood_low'] ?? '', $def['world_mood']['axis']['negative'], 160),
                'ordinary' => 0,
            ],
            'range'  => [-10, 10],
            'motifs' => [
                'dark'  => xeric_forge_list($flat['motifs_dark'] ?? [], 5, 120) ?: $def['world_mood']['motifs']['dark'],
                'light' => xeric_forge_list($flat['motifs_light'] ?? [], 5, 120) ?: $def['world_mood']['motifs']['light'],
            ],
        ],
    ];
}

/**
 * The hand-written concept, keyed by scale. Not a stub: these four are the
 * worlds a user gets when the model is down, so they are written to be worth
 * launching on their own.
 */
function xeric_forge_default_concept(array $answers): array
{
    $scale = xeric_forge_scale($answers);
    $table = [
        'small_town' => [
            'name' => "Cutter's Bend",
            'description' => 'A river town where everybody knows what everybody drives.',
            'locale' => 'a town of two thousand on a slow brown river',
            'texture' => [
                'coffee that has been on the burner since six',
                'one traffic light, and everybody times it',
                'the river you can smell before you can see it',
                'porch lights left on for people who might come by',
            ],
            'canon_rules' => [
                'Nothing opens before six and nothing stays open past ten.',
                'News travels through a kitchen before it travels anywhere else.',
                'Everybody here is somebody in this town before they are anything else.',
            ],
            'high' => 'loud — the river is up and nobody is going home',
            'low'  => 'kind — people are looking after each other for no reason',
            'dark' => ['a phone ringing at an hour nobody calls', 'a truck parked where no truck should be', 'water higher on the piling than yesterday'],
            'light' => ['a pie left on a porch rail with nobody\'s name on it', 'somebody\'s kid asleep in a booth under a coat', 'two men arguing happily about a fence neither of them owns'],
            'themes' => ['small_town', 'neighbors', 'ordinary life'],
        ],
        'city' => [
            'name' => 'Halloran',
            'description' => 'A mid-size city that works nights and complains about it.',
            'locale' => 'a mid-size American city that never quite finished rebuilding',
            'texture' => [
                'bus exhaust and cold pizza at midnight',
                'a bar on every third corner',
                'somebody practising scales through a wall',
                'a train you can set your watch by and never do',
            ],
            'canon_rules' => [
                'Nobody gets anywhere in this city in under forty minutes.',
                'Every favour here is remembered by somebody.',
                'The bars close at two and the diners never do.',
            ],
            'high' => 'reckless — the night is running and nobody is calling it',
            'low'  => 'kind — the city is being decent to somebody who needed it',
            'dark' => ['a stairwell light that has been out for weeks', 'a text answered four hours late', 'somebody counting cash in a parked car'],
            'light' => ['a stranger holding an elevator without looking up', 'the good bakery smell at five in the morning', 'a whole bar singing the same wrong lyric'],
            'themes' => ['nightlife', 'ambition', 'neighbors'],
        ],
        'world_stage' => [
            'name' => 'The Circuit',
            'description' => 'Rooms with good acoustics in four time zones, and the people who keep showing up in them.',
            'locale' => 'a circuit of capitals, hotel bars and departure lounges',
            'texture' => [
                'a suit that has been folded twice too often',
                'coffee in four currencies',
                'the hum of a room before it fills',
                'somebody important pretending not to wait',
            ],
            'canon_rules' => [
                'Nothing said in a hallway stays in the hallway.',
                'Everyone here is on somebody else\'s clock.',
                'The plane leaves whether or not you are finished talking.',
            ],
            'high' => 'reckless — somebody is about to say the true thing on the record',
            'low'  => 'kind — two tired people being honest in an empty lobby',
            'dark' => ['a phone face-down that keeps lighting the tablecloth', 'a name crossed off a seating chart', 'a car idling at a service entrance'],
            'light' => ['a drink bought by somebody who owes you nothing', 'a translator laughing before anyone else does', 'the last flight home, half empty'],
            'themes' => ['intrigue', 'ambition', 'the road'],
        ],
        'invented' => [
            'name' => 'Ashfall Station',
            'description' => 'A waystation at the edge of a map nobody finished drawing.',
            'locale' => 'a waystation on the last road out, where the map stops',
            'texture' => [
                'wind that carries grit and old paper',
                'lanterns lit an hour before they are needed',
                'a bell rung for arrivals and for nothing else',
                'maps with a torn edge everyone pretends is the border',
            ],
            'canon_rules' => [
                'The road closes at dark, and it closes for everyone.',
                'What you carry in you carry out.',
                'Nobody asks where you came from on your first night.',
            ],
            'high' => 'reckless — something came up the road that should not have',
            'low'  => 'kind — the hall is full and the doors are shut against the wind',
            'dark' => ['a lantern moving where no one should be walking', 'a ration jar lighter than it was', 'boots by the door that belong to nobody'],
            'light' => ['a bowl handed over without being asked for', 'somebody singing badly in the water house', 'the bell rung for a caravan everyone had stopped expecting'],
            'themes' => ['frontier', 'survival', 'found family'],
        ],
    ];
    $d = $table[$scale] ?? $table['small_town'];
    $themes = xeric_forge_list($answers['themes'] ?? [], 4, 40) ?: $d['themes'];

    return [
        'meta' => [
            // The banks feed the fallbacks too: a second offline world on the
            // same shelf takes a register toponym instead of being Cutter's
            // Bend again with a -2 on the directory.
            'name'        => xeric_forge_fresh_world_name($d['name'], xeric_forge_naming($answers)),
            'description' => $d['description'],
            'rating'      => xeric_forge_rating($answers),
            'themes'      => $themes,
        ],
        'setting' => [
            'locale'      => $d['locale'],
            'era'         => 'present day',
            'texture'     => $d['texture'],
            'canon_rules' => $d['canon_rules'],
        ],
        'world_mood' => [
            'axis'   => ['positive' => $d['high'], 'negative' => $d['low'], 'ordinary' => 0],
            'range'  => [-10, 10],
            'motifs' => ['dark' => $d['dark'], 'light' => $d['light']],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Pass 2 — places. Six for the slice, fifteen later.
// ---------------------------------------------------------------------------

/**
 * The rooms the world happens in.
 *
 * The user's workplace (interview step 2) is PINNED: it is asked for by itself,
 * it is always present whatever the model does, and it is marked with
 * `user_workplace` so the orbit, the cast and user.occupation.workplace_key can
 * all bind to the same key.
 */
function xeric_forge_pass_places(array $answers, array $concept, array $endpoint, int $count = 6, ?callable $onNote = null): array
{
    $count = max(2, $count);
    $job   = xeric_forge_str($answers['job'] ?? '', 'work', 120);
    $name  = xeric_forge_str($concept['meta']['name'] ?? '', 'the town', 60);
    $locale = xeric_forge_str($concept['setting']['locale'] ?? '', '', 200);
    $texture = xeric_forge_list($concept['setting']['texture'] ?? [], 4, 120);

    xeric_forge_note($onNote, "places: building $count rooms for $name");

    $naming = xeric_forge_naming($answers);
    $bizLine = '';
    if ($naming['key'] !== '') {
        $bizLine = 'Business names come off the same shelf as the people\'s — '
            . xeric_forge_naming_examples($naming, 'businesses', 2)
            . " is the sound, not the copy. Never 'the Rusty <anything>' and never a St. Jude's: "
            . "every other town already has both.\n";
    }

    $places = xeric_forge_attempt('places', function () use ($endpoint, $count, $job, $name, $locale, $texture, $answers, $concept, $naming, $bizLine, $onNote) {
        $msgs = [
            ['role' => 'system', 'content' =>
                'You invent places for a story world. Ordinary, specific, walkable. '
                . 'Reply with ONE JSON object and nothing else.'],
            ['role' => 'user', 'content' =>
                "The world: $name — $locale\n"
                . ($texture ? 'Texture: ' . implode('; ', $texture) . "\n" : '')
                . "The person living here works: $job\n\n"
                . "Invent the places. ONE JSON object:\n"
                . "{\n"
                . "  \"workplace\": { \"name\": \"where they work, from the job above\", \"kind\": \"…\", "
                . "\"open\": \"HH:MM\", \"close\": \"HH:MM\", \"late\": false, \"alcohol\": false, "
                . "\"x\": 50, \"y\": 50, "
                . "\"description\": \"one or two sentences anyone in town could tell you\", "
                . "\"interior\": [\"4 to 6 concrete things IN the room a scene can touch, 3-6 words each\"] },\n"
                . "  \"places\": [ " . ($count - 1) . " more objects with the same keys ]\n"
                . "}\n"
                . "kind is one of: diner cafe bar club church school clinic shop market office gym park home site station hall.\n"
                . "Give one place where people drink at night and one place people go in the daytime.\n"
                . $bizLine
                // Asking for the map is asking the model to write down a decision
                // it is already making: a place list always has a main street and
                // an edge, and until now that only ever survived as a phrase in a
                // description ("on the river side of the tracks") that no code
                // could read. Two numbers per room and the same sentence becomes
                // a distance somebody has to walk.
                . "x and y put the place on a map, 0 to 100 each, and they MATTER: "
                . "things people pass on the same walk go within a few points of each other, "
                . "and anywhere out past the edge of town goes out near a corner. "
                . "Do not put everything in the middle and do not spread them evenly — "
                . "a real place is a cluster with two or three outliers.\n"
                . "The description is read by EVERYONE — nothing secret in it.\n"
                // The still-life fix proved models invent props anyway, just
                // uncoordinated — a brass shaker one hour, gone the next. The
                // interior list is those props written down ONCE, so sweeps,
                // duets, arrivals and dreams all touch the same chairs.
                . "interior is the room's furniture: the register, the corner booth, the pie case. "
                . "Things, not people, and nothing secret — this is what any stranger sees from the door.\n"
                . "No prose outside the JSON."],
        ];
        $raw = xeric_forge_ask($endpoint, 'places', $msgs, ['temperature' => 0.9, 'max_tokens' => 1500], $onNote);

        // The gate, on the raw names and BEFORE keys are cut from them, so a
        // renamed room's key, aliases and references all belong to the name
        // it actually ships with.
        $fresh = [];
        $renamed = 0;
        $gate = function (array $p, int $i) use ($naming, &$fresh, &$renamed): array {
            $was = (string)($p['name'] ?? '');
            if ($was === '') return $p;
            $p['name'] = xeric_forge_fresh_place_name($was, (string)($p['kind'] ?? ''), $naming, $i, $fresh);
            if ($p['name'] !== $was) $renamed++;
            return $p;
        };
        if (is_array($raw['workplace'] ?? null)) $raw['workplace'] = $gate($raw['workplace'], 0);
        foreach ((array)($raw['places'] ?? []) as $pi => $p) {
            if (is_array($p)) $raw['places'][$pi] = $gate($p, $pi + 1);
        }
        if ($renamed > 0) {
            xeric_forge_note($onNote, "naming: {$renamed} place name(s) were worn out or already on another world's street — renamed from the register");
        }

        $out = [];
        $taken = [];
        $wp = xeric_forge_place_from((array)($raw['workplace'] ?? []), true, $taken);
        if ($wp) $out[] = $wp;
        foreach ((array)($raw['places'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $one = xeric_forge_place_from($p, false, $taken);
            if ($one) $out[] = $one;
            if (count($out) >= $count) break;
        }
        if (count($out) < 3) throw new RuntimeException('model returned ' . count($out) . ' usable places');
        return $out;
    }, fn() => xeric_forge_default_places($answers, $concept, $count), $onNote);

    // The workplace is not negotiable: if the model dropped it, or the defaults
    // were used with a job the table does not know, one gets synthesised here.
    if (xeric_forge_workplace_key($places) === null) {
        xeric_forge_note($onNote, 'places: no workplace came back — adding one from your job');
        $taken = [];
        foreach ($places as $p) $taken[(string)$p['key']] = true;
        array_unshift($places, xeric_forge_workplace_place($answers, $taken));
    }
    // A model that returned four rooms when six were asked for is not a failure
    // worth throwing the pass away over — top up from the table instead.
    if (count($places) < $count) {
        $have = [];
        foreach ($places as $p) $have[(string)$p['key']] = true;
        foreach (xeric_forge_default_places($answers, $concept, $count) as $p) {
            if (count($places) >= $count) break;
            if (isset($have[(string)$p['key']]) || !empty($p['user_workplace'])) continue;
            $have[(string)$p['key']] = true;
            $places[] = $p;
        }
        xeric_forge_note($onNote, 'places: topped up to ' . count($places) . ' from the built-in table');
    }
    if (count($places) > $count) $places = array_slice($places, 0, $count);

    return $places;
}

/** One place, shaped to schema. Returns null when there is nothing to shape. */
function xeric_forge_place_from(array $raw, bool $isWorkplace, array &$taken): ?array
{
    $name = xeric_forge_str($raw['name'] ?? '', '', 80);
    if ($name === '') return null;
    $kind = strtolower(xeric_forge_str($raw['kind'] ?? '', 'shop', 24));
    $key  = xeric_forge_key($name, $taken);
    $taken[$key] = true;

    $late    = !empty($raw['late']);
    $alcohol = !empty($raw['alcohol']) || in_array($kind, ['bar', 'club', 'tavern', 'pub'], true);
    $open    = xeric_forge_hhmm($raw['open'] ?? null, $late ? '16:00' : '08:00');
    $close   = xeric_forge_hhmm($raw['close'] ?? null, $late ? '02:00' : '18:00');

    $place = [
        'key'  => $key,
        // Absent rather than guessed. A place with no coordinates costs the flat
        // default to reach, which is honest; a place invented at (50,50) because
        // the model skipped it is a map that says the church is downtown.
        'at'   => xeric_forge_at($raw),
        'name' => $name,
        'kind' => $kind,
        'serves_alcohol' => $alcohol,
        'hours' => ['open' => $open, 'close' => $close, 'open_late_weekend' => $late],
        'aliases' => xeric_forge_aliases($name),
        'description' => xeric_forge_str($raw['description'] ?? '', 'A room people keep ending up in.', 400),
        'residents' => [],
        'special' => null,
    ];
    // The furniture, capped and stringy: at most six things, each a short
    // commons phrase, dropped rather than repaired when the model hands back
    // something that is not a thing. Absent when nothing usable came — a room
    // with no interior has none, and the arrival beat copes.
    $interior = [];
    foreach ((array)($raw['interior'] ?? []) as $item) {
        $s = trim(xeric_forge_str(is_array($item) ? ($item['text'] ?? '') : $item, '', 60));
        if ($s !== '' && mb_strlen($s) >= 3) $interior[] = $s;
        if (count($interior) >= 6) break;
    }
    if ($interior !== []) $place['interior'] = $interior;
    // Not in WORLD_TEMPLATE.md: the validator ignores unknown keys, and the
    // review UI, the orbit builder and the cast pass all need to find this one
    // place again after it has been renamed by a model.
    if ($isWorkplace) $place['user_workplace'] = true;
    return $place;
}

/**
 * Two numbers off a model, or null.
 *
 * Accepts them loose — `{"x":…,"y":…}`, a nested `at`, or `[x, y]` — because a
 * model asked for two numbers in a nine-key object will occasionally hand back a
 * different shape of the same right answer, and throwing away a whole world's
 * geography over that would be the wrong trade. What it will NOT do is invent:
 * a place that came back without a position keeps not having one, all the way
 * out to the template, where travel.php prices it as unknown.
 */
function xeric_forge_at(array $raw): ?array
{
    $at = is_array($raw['at'] ?? null) ? $raw['at'] : $raw;
    $x  = $at['x'] ?? $at[0] ?? null;
    $y  = $at['y'] ?? $at[1] ?? null;
    if (!is_numeric($x) || !is_numeric($y)) return null;

    return ['x' => max(0, min(100, (int)round((float)$x))),
            'y' => max(0, min(100, (int)round((float)$y)))];
}

/** The pinned workplace, built from the job answer alone. */
function xeric_forge_workplace_place(array $answers, array &$taken = []): array
{
    $job  = xeric_forge_str($answers['job'] ?? '', 'work', 120);
    $kind = xeric_forge_job_kind($job);
    $names = [
        'school' => 'the school', 'clinic' => 'the clinic', 'bar' => 'the bar',
        'diner' => 'the diner', 'shop' => 'the shop', 'office' => 'the office',
        'church' => 'the church', 'site' => 'the yard', 'station' => 'the station',
        'home' => 'the house', 'market' => 'the market', 'gym' => 'the gym',
    ];
    $late = in_array($kind, ['bar', 'station'], true);
    $p = xeric_forge_place_from([
        'name' => $names[$kind] ?? 'the office',
        'kind' => $kind,
        'open' => $late ? '16:00' : '08:00',
        'close' => $late ? '02:00' : '17:00',
        'late' => $late,
        'alcohol' => $kind === 'bar',
        'description' => 'Where the day goes. ' . ucfirst($job) . ' — the same walls, five days out of seven.',
    ], true, $taken);
    return $p ?? [
        'key' => 'work', 'name' => 'work', 'kind' => 'office', 'serves_alcohol' => false,
        'hours' => ['open' => '09:00', 'close' => '17:00', 'open_late_weekend' => false],
        'aliases' => ['work'], 'description' => 'Where the day goes.',
        'residents' => [], 'special' => null, 'user_workplace' => true,
    ];
}

/** Guess a place kind from a free-text job. Wrong guesses are survivable; a missing workplace is not. */
function xeric_forge_job_kind(string $job): string
{
    $j = strtolower($job);
    $map = [
        'school' => ['teach', 'teacher', 'school', 'professor', 'principal', 'tutor'],
        'clinic' => ['nurse', 'doctor', 'clinic', 'hospital', 'medic', 'paramedic', 'vet', 'dentist'],
        'bar'    => ['bartend', 'tend bar', 'barman', 'barmaid', 'pub', 'tavern', 'bouncer', 'dj'],
        'diner'  => ['cook', 'chef', 'diner', 'waiter', 'waitress', 'server', 'bake', 'barista', 'cafe'],
        'shop'   => ['shop', 'store', 'clerk', 'retail', 'grocer', 'feed store', 'hardware', 'garage', 'mechanic'],
        'church' => ['pastor', 'priest', 'minister', 'preach', 'rabbi', 'imam', 'chaplain'],
        'site'   => ['farm', 'field', 'construct', 'build', 'mill', 'rig', 'mine', 'dock', 'boat', 'crew', 'water house', 'pump'],
        'station'=> ['dispatch', 'police', 'fire', 'ambulance', 'radio', 'transit', 'conductor'],
        'gym'    => ['coach', 'trainer', 'gym'],
        'market' => ['market', 'stall', 'vendor'],
        'home'   => ['retired', 'unemployed', 'stay at home', 'write', 'freelance', 'no fixed'],
    ];
    foreach ($map as $kind => $words) {
        foreach ($words as $w) if (str_contains($j, $w)) return $kind;
    }
    return 'office';
}

/** Which place is the user's, or null. */
function xeric_forge_workplace_key(array $places): ?string
{
    foreach ($places as $p) {
        if (!empty($p['user_workplace'])) return (string)$p['key'];
    }
    return null;
}

/** A public place people can run into each other in — for weeks and hangouts. */
function xeric_forge_public_key(array $places, ?string $not = null): string
{
    $rank = ['diner' => 1, 'cafe' => 1, 'bar' => 2, 'market' => 3, 'church' => 3, 'park' => 3, 'hall' => 3, 'shop' => 4];
    $best = null;
    $bestScore = 99;
    foreach ($places as $p) {
        $k = (string)$p['key'];
        if ($not !== null && $k === $not) continue;
        $s = $rank[(string)($p['kind'] ?? '')] ?? 9;
        if ($s < $bestScore) { $bestScore = $s; $best = $k; }
    }
    if ($best !== null) return $best;
    return (string)($places[0]['key'] ?? 'work');
}

/** The hand-written places, keyed by scale, plus the pinned workplace. */
function xeric_forge_default_places(array $answers, array $concept, int $count): array
{
    $scale = xeric_forge_scale($answers);
    $table = [
        'small_town' => [
            ['name' => 'the Bluebird', 'kind' => 'diner', 'open' => '05:30', 'close' => '14:00', 'description' => 'Eight booths, nine stools, and a pie case that has held the same three pies for years. The window fogs from the inside and somebody always writes in it.'],
            ['name' => 'the Legion hall', 'kind' => 'bar', 'open' => '16:00', 'close' => '23:00', 'late' => true, 'alcohol' => true, 'description' => 'Cheap beer, one pool table with a warped rail, and a sign-up sheet for something on the wall by the door.'],
            ['name' => 'First Church', 'kind' => 'church', 'open' => '08:00', 'close' => '20:00', 'description' => 'White clapboard, a steeple a degree off true, and a basement with the best coffee urn in the county.'],
            ['name' => 'Hollis Hardware', 'kind' => 'shop', 'open' => '07:00', 'close' => '17:00', 'description' => 'Narrow aisles, a wood floor, a bin of loose bolts nobody has counted in forty years, and a chair by the register understood to be for sitting and talking.'],
            ['name' => 'the landing', 'kind' => 'park', 'open' => '06:00', 'close' => '22:00', 'description' => 'A concrete ramp into the river, two picnic tables, and the best place in town to be alone without being unfriendly about it.'],
        ],
        'city' => [
            ['name' => 'Delaney\'s', 'kind' => 'bar', 'open' => '16:00', 'close' => '02:00', 'late' => true, 'alcohol' => true, 'description' => 'Long dark bar, bad lighting on purpose, and a jukebox that only takes cash.'],
            ['name' => 'the all-night diner', 'kind' => 'diner', 'open' => '00:00', 'close' => '23:59', 'late' => true, 'description' => 'Open through everything. Coffee in thick white cups and a booth in the back nobody fights you for at four in the morning.'],
            ['name' => 'the corner market', 'kind' => 'market', 'open' => '07:00', 'close' => '23:00', 'description' => 'Two aisles, one register, a cat that is not supposed to be there, and the only flowers you can buy after nine.'],
            ['name' => 'the gym on Third', 'kind' => 'gym', 'open' => '05:00', 'close' => '23:00', 'description' => 'Rubber, chalk, and the same eleven people at the same eleven hours. Nobody talks before the second set.'],
            ['name' => 'the platform', 'kind' => 'station', 'open' => '05:00', 'close' => '01:00', 'description' => 'Concrete, wind, and everybody in the city standing four feet apart pretending to be somewhere else.'],
        ],
        'world_stage' => [
            ['name' => 'the hotel bar', 'kind' => 'bar', 'open' => '17:00', 'close' => '02:00', 'late' => true, 'alcohol' => true, 'description' => 'Low chairs, a piano nobody plays before nine, and four conversations happening in three languages at any hour.'],
            ['name' => 'the press room', 'kind' => 'office', 'open' => '07:00', 'close' => '21:00', 'description' => 'Folding chairs, a riser, and a coffee urn that has never once been hot.'],
            ['name' => 'the departure lounge', 'kind' => 'station', 'open' => '04:00', 'close' => '23:59', 'description' => 'Carpet, glass, and the specific tiredness of people who are always about to leave.'],
            ['name' => 'the club on Rue Basse', 'kind' => 'club', 'open' => '21:00', 'close' => '03:00', 'late' => true, 'alcohol' => true, 'description' => 'Members only, badly lit, and the only room on the circuit where nobody writes anything down.'],
            ['name' => 'the embassy garden', 'kind' => 'park', 'open' => '09:00', 'close' => '18:00', 'description' => 'Gravel paths, clipped hedges, and two people walking slowly enough to finish a sentence.'],
        ],
        'invented' => [
            ['name' => 'the common hall', 'kind' => 'hall', 'open' => '06:00', 'close' => '22:00', 'description' => 'Long tables, a fire kept low, and the door that everybody shoulders shut behind them without thinking.'],
            ['name' => 'the market row', 'kind' => 'market', 'open' => '07:00', 'close' => '15:00', 'description' => 'Six stalls under one roof, and prices that change with the wind out of the west.'],
            ['name' => 'the water house', 'kind' => 'site', 'open' => '05:00', 'close' => '20:00', 'description' => 'Pumps, pipe, and the sound of the whole station\'s day being kept running by three people.'],
            ['name' => 'the road gate', 'kind' => 'station', 'open' => '05:00', 'close' => '19:00', 'description' => 'Two posts, a chain, and a ledger of everybody who came in and everybody who has not gone back out.'],
            ['name' => 'the drinking house', 'kind' => 'bar', 'open' => '17:00', 'close' => '01:00', 'late' => true, 'alcohol' => true, 'description' => 'Lanterns, benches, one long argument that has been going for years and changes hands as people leave.'],
        ],
    ];
    $rows = $table[$scale] ?? $table['small_town'];

    $taken = [];
    $out = [xeric_forge_workplace_place($answers, $taken)];
    // The same freshness gate the model's rooms get: a hand-written room that
    // is already standing in another world on this shelf takes a register
    // name instead, so two offline worlds are not the same five doors twice.
    $naming = xeric_forge_naming($answers);
    $fresh = [];
    foreach ($rows as $ri => $r) {
        if (count($out) >= $count) break;
        $r['name'] = xeric_forge_fresh_place_name((string)$r['name'], (string)($r['kind'] ?? ''), $naming, $ri + 1, $fresh);
        $p = xeric_forge_place_from($r, false, $taken);
        if ($p) $out[] = $p;
    }

    // The built-in rooms get a built-in layout: work and the two places you pass
    // on the way to it clustered on one street, and the last two out at opposite
    // edges — the cluster-with-outliers shape the model is asked for above. A
    // fallback world is still a world, and a flat one would be the only kind
    // Xeric ships with no map at all. Only fills what is empty, so a top-up into
    // a model-built list leaves the model's own geography alone.
    $layout = [[46, 48], [58, 44], [34, 62], [66, 56], [16, 22], [88, 80]];
    foreach ($out as $i => $p) {
        if (($p['at'] ?? null) !== null || !isset($layout[$i])) continue;
        $out[$i]['at'] = ['x' => $layout[$i][0], 'y' => $layout[$i][1]];
    }
    return $out;
}

/**
 * How big the world is, in minutes across, from the one answer that already knows.
 *
 * `scale` is what the interview asks about the size of the place — the same
 * answer that picks which table of rooms gets used — so it is also the honest
 * source for how long crossing it takes. Nobody is asked a fifth question to get
 * this number, and a world that came back with no scale is a town, which is the
 * shape most of them are.
 */
function xeric_forge_travel(array $answers): array
{
    $by = [
        'small_town'  => [14, 'on foot, mostly, and everybody walks'],
        'city'        => [35, 'trains and a lot of waiting on platforms'],
        'world_stage' => [45, 'cars, planes, and somebody else driving'],
        'invented'    => [20, 'on foot'],
    ];
    [$minutes, $how] = $by[xeric_forge_scale($answers)] ?? $by['small_town'];

    return ['minutes_across' => $minutes, 'how' => $how];
}

// ---------------------------------------------------------------------------
// Structure (the slice's slice of pass 3) + pass 4 — cast
// ---------------------------------------------------------------------------

/**
 * Orbits, built in code rather than asked for.
 *
 * Two for the slice — the people you see at work and the people you see
 * everywhere else — plus `extras` so fixtures have somewhere to live later.
 * Deterministic on purpose: an orbit key is a reference target for every
 * character and every wall, and a model inventing one it then misspells is the
 * exact failure the validator exists to catch.
 */
function xeric_forge_orbits(array $answers, array $concept, array $places): array
{
    $wk = xeric_forge_workplace_key($places) ?? 'work';
    $wp = null;
    foreach ($places as $p) if ((string)$p['key'] === $wk) $wp = $p;
    $wpName = (string)($wp['name'] ?? 'work');
    $where = xeric_forge_str($concept['setting']['locale'] ?? '', 'here', 120);

    return [
        [
            'key' => $wk,
            'label' => 'the people at ' . $wpName,
            'membership_block' => 'You spend your hours in the same rooms as these people whether you planned to or not. '
                . 'You know their days by heart and you talk about the rest of your life sideways, if at all.',
            'shares_daily_space_with_user' => true,
        ],
        [
            'key' => 'outside',
            'label' => 'the rest of ' . xeric_forge_str($concept['meta']['name'] ?? '', 'town', 60),
            'membership_block' => 'You know each other from ' . $where . ' — from the same three rooms, the same hours, '
                . 'and years of running into each other on purpose and calling it an accident.',
            'shares_daily_space_with_user' => false,
        ],
        ['key' => 'extras', 'label' => 'fixtures', 'speaking' => true],
    ];
}

/**
 * The cast. One model call per character, and each call is told who already
 * exists — a cast written in one shot comes back as four versions of the same
 * person, and a model that cannot see #1-#3 writes #4 as their twin.
 *
 * @return array{orbits:array,characters:array}
 */
function xeric_forge_pass_cast(array $answers, array $concept, array $places, array $endpoint, int $count = 4, ?callable $onNote = null): array
{
    $count  = max(1, $count);
    $orbits = xeric_forge_orbits($answers, $concept, $places);

    // THE PEOPLE SOMEBODY ALREADY NAMED GO FIRST. A premise that says "my
    // teacher Mr. Sanders teaches me chess after school" has named a member of
    // this cast, and a cast pass that invents four strangers around him has
    // thrown away the one character the person actually asked for. Read out of
    // the premise upstream (xeric_forge_answers_from_premise) and briefed into
    // the same person-writer a hand-add uses, so they get a full dossier, a
    // week and an orbit like anybody else — not a cameo.
    $named = [];
    foreach ((array)($answers['people'] ?? []) as $p) {
        $n = is_array($p) ? trim((string)($p['name'] ?? '')) : trim((string)$p);
        if ($n === '') continue;
        $named[] = $n . (is_array($p) && trim((string)($p['who'] ?? '')) !== ''
            ? ' — ' . trim((string)$p['who']) : '');
        if (count($named) >= $count) break;
    }
    if ($named !== []) {
        xeric_forge_note($onNote, 'cast: ' . count($named) . ' of these '
            . (count($named) === 1 ? 'is somebody' : 'are people') . ' you named');
    }

    $chars = [];
    $taken = [];
    for ($i = 0; $i < $count; $i++) {
        // Alternating orbits: the workplace orbit must not end up empty, and a
        // world where everybody is a coworker has no outside to go to.
        $orbit = xeric_forge_orbit_for($orbits, $i);
        $sofar = [];
        foreach ($chars as $c) $sofar[] = '- ' . $c['display_name'] . ' — ' . $c['one_line'];

        $brief = $named[$i] ?? '';
        xeric_forge_note($onNote, $brief !== ''
            ? 'cast: writing ' . $brief
            : 'cast: writing person ' . ($i + 1) . " of $count");

        $c = xeric_forge_person($answers, $concept, $places, $endpoint, $i, $orbit, $sofar, $taken,
            $onNote, 'cast person ' . ($i + 1), [], $brief);

        $taken[$c['handle']] = true;
        $chars[] = $c;
    }

    // The prompt asks for variety; this guarantees it.
    $chars = xeric_forge_dedupe_cast($chars, $places, $onNote);

    return ['orbits' => $orbits, 'characters' => $chars];
}

/** Which orbit the Nth person belongs to. Alternating — see xeric_forge_pass_cast. */
function xeric_forge_orbit_for(array $orbits, int $index): string
{
    return ($index % 2 === 0) ? (string)$orbits[0]['key'] : 'outside';
}

/**
 * ONE person, written by the model, with the built-in archetype behind them.
 *
 * Lifted out of xeric_forge_pass_cast()'s loop unchanged, and for one reason:
 * the review step must be able to reroll a SINGLE character without rewriting
 * the other three. A second copy of this prompt would drift from the one the
 * build uses within a week, and then "reroll" would quietly mean "generate a
 * different kind of person". There is one prompt, and both callers use it.
 *
 * @param int    $index  position in the cast — picks the assigned vocal shape,
 *                       the fallback archetype and the fallback age
 * @param string $orbit  which orbit they belong to
 * @param array  $sofar  "- Name — one line" for everybody who already exists,
 *                       so the model does not write their twin
 * @param array  $taken  handles already spoken for, so the new one is unique
 */
function xeric_forge_person(array $answers, array $concept, array $places, array $endpoint,
                            int $index, string $orbit, array $sofar = [], array $taken = [],
                            ?callable $onNote = null, string $label = '', array $avoid = [],
                            string $brief = ''): array
{
    $orbits = xeric_forge_orbits($answers, $concept, $places);
    $wk     = xeric_forge_workplace_key($places) ?? (string)($places[0]['key'] ?? 'work');
    $public = xeric_forge_public_key($places, $wk);
    $keys   = array_map(fn($p) => (string)$p['key'], $places);

    $lines = [];
    foreach ($places as $p) $lines[] = '- ' . $p['key'] . ' — ' . $p['name'] . ' (' . $p['kind'] . ')';
    $placeBlock = implode("\n", $lines);

    $user   = xeric_forge_str($answers['name'] ?? '', 'the user', 60);
    $job    = xeric_forge_str($answers['job'] ?? '', 'works', 120);
    $why    = xeric_forge_human((string)($answers['motivation'] ?? 'company'));
    $circle = xeric_forge_str($answers['circle'] ?? '', '', 60);
    $world  = xeric_forge_str($concept['meta']['name'] ?? '', 'here', 60);
    $rating = xeric_forge_rating($answers);

    $ctx = [
        'orbit' => $orbit,
        'orbit_label' => $orbit === 'outside' ? (string)$orbits[1]['label'] : (string)$orbits[0]['label'],
        'places' => $places, 'place_keys' => $keys,
        'workplace' => $wk, 'public' => $public,
        'index' => $index, 'user' => $user,
        // The world's naming register, so the fallback archetypes get the
        // same shelf-freshness gate the model's people do.
        'naming' => xeric_forge_naming($answers),
        // Which stretch of a life this slot is written to, and the age the
        // slot falls back to. Assigned, not asked for — see xeric_forge_age_band().
        //
        // UNLESS SOMEBODY ASKED. The band exists so a cast the forge DEALS OUT
        // spans a town's ages instead of being six people in their forties; a
        // person somebody typed in by hand is not being dealt out. Left in
        // place, "a young welder" came back as the town's oldest retired
        // metalworker, because the slot said sixties and the slot wins the
        // clamp. So a brief opens the band to a whole life and the words
        // decide. Nothing about minors changes: the exclusion that matters is
        // structural (xeric_forge_age_floor), not this range.
        'age_band' => trim($brief) !== ''
            ? ['brief' => 'whatever age the request implies. If it does not say, pick one that fits them.',
               'min' => 9, 'max' => 92, 'example' => 34]
            : xeric_forge_age_band($index, $orbit),
        // Names the hand-written fallback must not hand back. A reroll passes
        // the person leaving; a build passes nobody.
        'avoid' => array_values(array_filter(array_map('strval', $avoid))),
    ];
    $i = $index;

    // What the person living in this world asked for, when somebody added them
    // by hand rather than the forge dealing them out. Trimmed hard and quoted as
    // a request, never as a template: a small model handed a long brief starts
    // copying its wording into every field.
    $brief = xeric_forge_str($brief, '', 240);

    return xeric_forge_attempt($label !== '' ? $label : 'cast person ' . ($index + 1), function () use (
            $endpoint, $ctx, $sofar, $placeBlock, $user, $job, $why, $circle, $world, $rating, $onNote, $orbit, $taken, $i, $brief
        ) {
            $where = $orbit === 'outside'
                ? "somebody $user knows outside of work"
                : "somebody $user sees at work ($job)";
            // Every forged character came back "A low, gravelly/raspy/rhythmic…":
            // the model's prior for "voice" is overwhelming, and one call cannot
            // see what the others chose. So each character is ASSIGNED a vocal
            // shape by index, and the known default opener is banned outright.
            // Sixteen shapes, not eight: the default cast is twelve now, and a
            // bank shorter than the cast hands slots 9-12 the same voices as
            // 1-4, which is the disease this bank exists to cure.
            $voiceShapes = [
                'fast and clipped, sentences that end early',
                'slow and warm, circles back to finish a thought later',
                'precise and quiet, picks one exact word instead of three',
                'loud and theatrical, talks in bits and performances',
                'flat and dry, deadpan, funniest when apparently serious',
                'soft and hesitant, trails off, apologises mid-sentence',
                'blunt and fast, interrupts, says the true thing too early',
                'formal and careful, over-polite even when angry',
                'measured and unhurried, tells everything in the order it happened',
                'quick and low, half of it asides out of the corner of the mouth',
                'rapid-fire questions, rarely waits for the answers',
                'storyteller cadence, everything is an anecdote with a setup',
                'clipped and practical, numbers and times where feelings would go',
                'warm and teasing, nicknames for everybody, serious only in private',
                'slow and doubtful, tries a sentence twice until it sits right',
                'cheerful and relentless, will not let a silence live',
            ];
            $voiceBrief = $voiceShapes[$i % count($voiceShapes)];
            // The roster line collapses the same way the voice did. A space
            // world came back with three of four one-liners reading "The only
            // [role] who can [feat]…" — different content, same sentence, and
            // the content dedupe sails right past a shared SHAPE. So the
            // one-liner gets the vocal-shape treatment too: an angle assigned
            // by index, and the observed opener banned outright.
            $lineShapes = [
                'a reputation: what the town says behind their back',
                'a warning: what a newcomer gets told about them first',
                'a habit anyone can watch: something they are seen doing, and when',
                'what they are wrong about, and everybody knows it',
                'who they used to be, and what is left of that',
                'the debt or favour the town still remembers',
                'what they run, and how tightly they run it',
                'the thing they are always about to do and never do',
                'whose kid, whose ex, whose rival — the tie that places them',
                'the rule they enforce, or the one they quietly break',
                'what they know that they should not',
                'where they reliably are at a given hour',
                'what they lost and will not discuss',
                'the small kingdom they defend',
                'two true facts about them that do not fit together',
                'the question people are still asking about them',
            ];
            $lineBrief = $lineShapes[$i % count($lineShapes)];
            // The name rule: register in, repeat offenders out. The list said
            // out loud here is the short one — the full gate, including every
            // name a world on this shelf already spent, is deterministic
            // (xeric_forge_fresh_person_name) and runs on the reply.
            $naming = (array)$ctx['naming'];
            $nameRule = '';
            if (($naming['key'] ?? '') !== '') {
                $worn = array_slice(array_keys((array)$naming['used']['given']), 0, 12);
                $nameRule = "NAME: first and last, in the register of {$naming['sound']} — "
                    . 'the shelf next to ' . xeric_forge_naming_examples($naming, 'given', 4, $i * 3)
                    . '; ' . xeric_forge_naming_examples($naming, 'family', 3, $i * 2)
                    . '. Invent in that register rather than copying. Never the first names '
                    . implode(', ', $naming['ban_given_say']) . '; never the surnames '
                    . implode(', ', $naming['ban_family_say']) . ' — every world ever forged is elbow-deep in them.'
                    . ($worn !== [] ? ' Also spoken for, by people in neighbouring worlds: '
                        . implode(', ', array_map('ucwords', $worn)) . '.' : '')
                    . ($brief !== '' ? ' If the request below names them, that name wins.' : '')
                    . "\n";
            }
            $band = $ctx['age_band'];
            // A world whose rating is above sfw still writes children, and the
            // model is told plainly which half of that is which: a minor is an
            // ordinary character with an ordinary week, and nothing sexual is
            // ever written about them. The structural half of this — the desire
            // economy they are never placed in — is xeric_forge_age_floor(),
            // because a line in a prompt is a request and the exclusion is not.
            $ageRule = "AGE: {$band['brief']} `age` must be a whole number between {$band['min']} and {$band['max']}.\n";
            // The child paragraph fires whenever the band can REACH a child —
            // not only when every age in it is one. An open band (a hand-typed
            // brief) spans a whole life, and "write a twelve-year-old" must
            // still carry the rule that a twelve-year-old is written by.
            if ($band['min'] < 18) {
                $ageRule .= ($band['max'] < 18 ? "They are a child. " : "If they are under 18 they are a child: ")
                    . "Write them as a full person — school, friends, chores, opinions, "
                    . "something they know that the adults do not. Nothing sexual, nothing romantic, no "
                    . "attraction: they are not in anybody's love life and never will be.\n";
            }
            $msgs = [
                ['role' => 'system', 'content' =>
                    'You write one person for a story world. Specific, ordinary, contradictory — a real person, '
                    . 'not a description of a type. Reply with ONE JSON object and nothing else.'],
                ['role' => 'user', 'content' =>
                    "World: $world. Rating: " . xeric_rating_label($rating) . ' — ' . xeric_rating_style($rating)
                    . " The person at the centre is $user, who $job, here for $why."
                    . ($circle !== '' ? " The people around them are $circle." : '')
                    . "\n\nPlaces (use these keys exactly):\n$placeBlock\n"
                    . ($sofar ? "\nAlready written — do NOT repeat their job, age or manner:\n" . implode("\n", $sofar) . "\n" : '')
                    . "\nWrite $where.\n" . $ageRule . $nameRule
                    . ($brief !== ''
                        ? "\nThe person who lives in this world asked for them specifically: \"$brief\"\n"
                        . "Honour that. If they named the person, use that name; if they said what the "
                        . "person is or does, that is what they are. Fill in everything they did not say.\n"
                        : '')
                    . "\nONE JSON object:\n"
                    . "{\n"
                    . "  \"display_name\": \"first and last name\",\n"
                    . "  \"short_name\": \"what people in the room actually call them — one word, "
                    . "usually the first name or the nickname\",\n"
                    . "  \"pronouns\": \"she/her | he/him | they/them | anything else that fits\",\n"
                    . "  \"age\": {$band['example']},\n"
                    . "  \"one_line\": \"one sentence anyone in town could say about them. "
                    . "REQUIRED ANGLE: {$lineBrief}. Say it like gossip, not a citation — never open "
                    . "with 'The only' and never make them the best at anything\",\n"
                    . "  \"appearance\": \"one sentence, what you see first\",\n"
                    . "  \"build\": \"how they are put together, 2-6 words — tall and stooped, "
                    . "broad through the shoulders, small and quick\",\n"
                    . "  \"wears\": [\"3-4 things they are wearing on an ordinary day, 2-5 words each, "
                    . "era-true — what a stranger at ten feet would see\"],\n"
                    . "  \"carries\": [\"2-4 things in their pockets or hands most days, 2-5 words each — "
                    . "objects a bystander could see them produce, nothing secret\"],\n"
                    . "  \"voice\": \"how they talk — rhythm and habit, one or two sentences. "
                    . "REQUIRED SHAPE: {$voiceBrief}. Do NOT open with 'A low' and do NOT call it "
                    . "gravelly, raspy, or a rumble — every other character already is.\",\n"
                    . "  \"sore_spot\": \"short phrase\",\n"
                    . "  \"jealousy\": \"short phrase\",\n"
                    . "  \"self_soothe\": \"short phrase\",\n"
                    . "  \"praise\": \"the compliment that actually lands, short phrase\",\n"
                    . "  \"tells\": [\"3 things they do without noticing\"],\n"
                    . "  \"pull\": \"the thing they steer toward without naming it\",\n"
                    . "  \"solace\": \"where they go when it is too much — in words, not a key\",\n"
                    . "  \"work_place\": \"a key from the list\",\n"
                    . "  \"work_days\": \"weekdays | weekends | most days\",\n"
                    . "  \"work_from\": \"HH:MM\",\n"
                    . "  \"work_to\": \"HH:MM\",\n"
                    . "  \"work_doing\": \"3-6 words\",\n"
                    . "  \"hangout_place\": \"a different key from the list\",\n"
                    . "  \"hangout_days\": \"weekdays | weekends | most days\",\n"
                    . "  \"hangout_from\": \"HH:MM\",\n"
                    . "  \"hangout_to\": \"HH:MM\",\n"
                    . "  \"hangout_doing\": \"3-6 words\"\n"
                    . "}\nNo prose outside the JSON."],
            ];
            $flat = xeric_forge_ask($endpoint, 'cast', $msgs, ['temperature' => 1.05, 'max_tokens' => 900], $onNote);
            // The gate, before the handle is cut from the name: a banned or
            // shelf-worn half is replaced from the register, and the handle,
            // the aliases and every later reference belong to the name that
            // actually ships. The short name goes with the half it named.
            $was = xeric_forge_str($flat['display_name'] ?? '', '', 80);
            $fresh = xeric_forge_fresh_person_name($was, $naming, $i, $taken, $brief);
            if ($was !== '' && $fresh !== $was) {
                $onNote && $onNote("naming: {$was} already lives in another world — here it is {$fresh}");
                $flat['display_name'] = $fresh;
                $short = xeric_forge_str($flat['short_name'] ?? '', '', 32);
                if ($short !== '' && mb_stripos($fresh, $short) === false) unset($flat['short_name']);
            }
            return xeric_forge_character_from($flat, $ctx, $taken);
        }, fn() => xeric_forge_default_character($ctx, $taken), $onNote);
}

/** Shape + sanitize one character. Every reference is forced onto a real key here. */
/**
 * Deterministic de-duplication of cast interiors.
 *
 * The prompt now assigns each character a vocal shape, but a prompt is a
 * request and a small model is a small model: two characters still land on the
 * same self_soothe habit or the same unnamed pull often enough to matter. Two
 * people who share an interior are one person wearing two names, and it is the
 * single fastest way for a forged world to feel thin.
 *
 * So after the cast is written, every interior field is compared across the
 * cast (normalised: case, punctuation, leading articles) and any repeat is
 * replaced from a varied bank, chosen by position so the result is stable for
 * the same world. Cheaper and more reliable than another model round-trip, and
 * it cannot fail.
 *
 * Also strips place KEYS out of prose fields — a model answering "solace" with
 * `pier_9_station` renders as "Goes to ground at: Pier_9_station." The key does
 * not have to be the whole answer to read as machine output, so a key sitting
 * inside a sentence is swapped too, but only when it carries an underscore: a
 * single-word key like `anchor` is also an ordinary English word, and rewriting
 * that mid-sentence would damage prose that was never broken.
 */
function xeric_forge_dedupe_cast(array $chars, array $places, ?callable $onNote = null): array
{
    $norm = static function (string $v): string {
        $v = mb_strtolower(trim($v));
        $v = preg_replace('/^(a|an|the)\s+/', '', $v) ?? $v;
        return trim(preg_replace('/[^a-z0-9 ]+/', '', $v) ?? $v);
    };
    $placeNames = [];
    $embedded = [];
    foreach ($places as $p) {
        $k = (string)($p['key'] ?? '');
        if ($k === '') continue;
        $placeNames[$k] = (string)($p['name'] ?? $k);
        // `_` is a word character to PCRE, so \b will not fire inside a longer
        // key: pier_9 cannot eat the front of pier_9_station.
        if (str_contains($k, '_')) $embedded[$k] = '/\b' . preg_quote($k, '/') . '\b/iu';
    }
    /** A prose field with keys in it, keys swapped for names. Unchanged if it had none. */
    $unkey = static function (string $s) use ($embedded, $placeNames): string {
        foreach ($embedded as $k => $rx) {
            $name = $placeNames[$k];
            $s = preg_replace_callback($rx, static fn() => $name, $s) ?? $s;
        }
        return $s;
    };
    // banks are deliberately concrete and unglamorous — an interior should
    // sound like a person, not like a tagline. Sixteen deep, every one of
    // them, because the default cast is twelve and a bank shorter than the
    // cast is a queue for the same six interiors.
    $banks = [
        'one_line'     => ['Shows up early, leaves late, and has never once said which it was on purpose.',
                           'Knows everybody\'s order and nobody\'s business, and works to keep it that way.',
                           'Said no to something big once, and the town has never agreed on what.',
                           'Can be lent anything except a reason to hurry.',
                           'Remembers who helped and who watched, and prices accordingly.',
                           'Laughs easy, forgives slow.',
                           'Has a system for everything and will not explain any of it.',
                           'Turns up wherever something needs holding, lifting or witnessing.',
                           'Keeps the peace by knowing exactly where all the trouble is.',
                           'Never in a hurry, never quite late.',
                           'Asks the question everybody else was dancing around.',
                           'Does the thing nobody noticed needed doing, then leaves before the thanks.',
                           'Argues both sides of everything and votes with neither.',
                           'Carries half the street\'s spare keys and none of its gossip.',
                           'Half the stories about them are true, and they will not say which half.',
                           'Been here long enough to remember what the place was called before.'],
        'voice'        => ['Talks fast, finishes other people\'s sentences, apologises for it later.',
                           'Long pauses, then one flat sentence that settles the matter.',
                           'Over-explains, hears themselves doing it, keeps going anyway.',
                           'Answers questions with questions, not to deflect but because they are genuinely curious.',
                           'Quiet until something matters, then unexpectedly direct.',
                           'Jokes first, meaning second, and you have to wait for the second part.',
                           'Starts in the middle of the thought and trusts you to catch up.',
                           'Says numbers and dates where other people say feelings.',
                           'Repeats your last three words back before answering them.',
                           'Talks to the room, never quite to you, until it matters.',
                           'One-word answers that somehow carry whole opinions.',
                           'Narrates what they are doing while they do it, quieter when watched.',
                           'Asks after your people first, business second, always in that order.',
                           'Swallows the end of any sentence that turns out to be about themselves.',
                           'Corrects themselves mid-word and lands on the plainer term.',
                           'Leaves pauses so long you answer your own question.'],
        'sore_spot'    => ['Being told they have gotten soft.', 'Anyone bringing up the year they left.',
                           'Being thanked in public.', 'Being asked why they never married.',
                           'Someone finishing a job they had started.', 'Being called reliable.',
                           'Being handled carefully.', 'The nickname that followed them here.',
                           'Being asked to say it in front of everyone.',
                           'Anyone doing their job for them, even well.',
                           'The one decision everybody still relitigates.',
                           'Being the example in someone else\'s story.',
                           'Praise for the wrong thing.', 'Being asked how they are doing twice in a row.',
                           'The year nobody mentions on purpose.', 'Being told to take it easy.'],
        'self_soothe'  => ['Reorganises a drawer that did not need it.', 'Walks the long way, twice.',
                           'Cleans something already clean.', 'Counts stock out loud.',
                           'Fixes a thing that is not broken yet.', 'Makes coffee they will not drink.',
                           'Sharpens everything in the drawer.',
                           'Walks the fence line, or the block, whichever is nearer.',
                           'Deals a hand of solitaire and abandons it halfway.',
                           'Sweeps a floor that was swept this morning.',
                           'Reads the same dozen pages of the same book.',
                           'Waters things — plants, gravel, the porch boards.',
                           'Writes the letter, then does not send it.',
                           'Polishes shoes nobody is going to see.',
                           'Recounts the till twice, slower the second time.',
                           'Sits in the truck in the driveway for one more song.'],
        'jealousy'     => ['Anyone who left and did well.', 'People who find things easy.',
                           'Whoever gets asked first.', 'Anyone at ease in a room.',
                           'People who can say no without a reason.', 'Anyone whose family still calls.',
                           'People whose apologies get accepted the first time.',
                           'Anyone with a standing invitation somewhere.',
                           'People who sleep through the night.',
                           'Whoever the new people get introduced to first.',
                           'Anyone whose work gets missed when they skip a day.',
                           'People who can cry in front of others.',
                           'The ones who left and get welcomed back anyway.',
                           'Anyone with a place that is theirs without argument.',
                           'People whose names get spelled right the first time.',
                           'Whoever gets asked to tell the story.'],
        'praise'       => ['That they noticed something first.', 'That they were right to wait.',
                           'That the work held up.', 'That they were missed.',
                           'That they made it look easy.', 'That somebody trusted them with it.',
                           'That the place runs different when they are gone.',
                           'That somebody kept the thing they made.',
                           'That they were quoted, accurately, months later.',
                           'That the young ones copy how they do it.',
                           'That it was noticed before they had to point it out.',
                           'That somebody drove out of the way for their opinion.',
                           'That they were the first call, not the second.',
                           'That the fix outlived the argument about it.',
                           'That someone remembered how they take their coffee.',
                           'That they were told the truth early, like an equal.'],
        'pull'         => ['To be needed without having to ask.', 'To be believed the first time.',
                           'To finish one thing completely.', 'To be somewhere nobody knows the story.',
                           'To be forgiven without discussing it.', 'To be the one who stayed.',
                           'To be asked to stay, not just allowed to.',
                           'To hand the responsibility over and have it held.',
                           'To be surprised by their own life once more.',
                           'To hear the place got better because they were in it.',
                           'To owe nothing to anybody by winter.',
                           'To be recognised somewhere they have never been.',
                           'To do the hard version of the job once, properly, witnessed.',
                           'To be the calm one in the room when it finally happens.',
                           'To be chosen over somebody impressive.',
                           'To leave something behind with their name off it.'],
        'solace'       => ['The back step, ten minutes, no phone.', 'The long road out of town and back.',
                           'The radio on low, lights off.', 'The first hour before anyone is up.',
                           'A job that takes both hands.', 'The bench nobody else uses.',
                           'The roof, technically for the aerial, actually for the view.',
                           'The cab of whatever is parked farthest from the door.',
                           'The river gauge, checked in person for no reason.',
                           'The last pew, weekday afternoon, lights off.',
                           'The walk-in cooler, two minutes, sleeves down.',
                           'The old road nobody plows first.',
                           'The stockroom radio, volume two.',
                           'The porch after the streetlight comes on.',
                           'The far end of the counter with the crossword.',
                           'The workshop after everyone stops needing things.'],
    ];
    $seen = [];
    $fixes = 0;
    foreach ($chars as $i => $c) {
        $paths = [
            ['one_line',    fn(&$x) => $x['one_line'] ?? null, fn(&$x, $v) => $x['one_line'] = $v],
            ['voice',       fn(&$x) => $x['voice']  ?? null,  fn(&$x, $v) => $x['voice'] = $v],
            ['sore_spot',   fn(&$x) => $x['psyche']['sore_spot'] ?? null,   fn(&$x, $v) => $x['psyche']['sore_spot'] = $v],
            ['self_soothe', fn(&$x) => $x['psyche']['self_soothe'] ?? null, fn(&$x, $v) => $x['psyche']['self_soothe'] = $v],
            ['jealousy',    fn(&$x) => $x['psyche']['jealousy'] ?? null,    fn(&$x, $v) => $x['psyche']['jealousy'] = $v],
            ['praise',      fn(&$x) => $x['psyche']['praise_that_lands'] ?? null, fn(&$x, $v) => $x['psyche']['praise_that_lands'] = $v],
            ['pull',        fn(&$x) => $x['drives']['pull'] ?? null,        fn(&$x, $v) => $x['drives']['pull'] = $v],
            ['solace',      fn(&$x) => $x['solace'] ?? null,                fn(&$x, $v) => $x['solace'] = $v],
        ];
        foreach ($paths as [$field, $get, $set]) {
            $cur = trim((string)($get($chars[$i]) ?? ''));
            if ($cur === '') continue;
            // a place key is never prose, whole answer or buried in one
            $bare = mb_strtolower($cur);
            if (isset($placeNames[$bare])) {
                $set($chars[$i], $placeNames[$bare]);
                $cur = $placeNames[$bare];
                $fixes++;
            } elseif (($swapped = $unkey($cur)) !== $cur) {
                $set($chars[$i], $swapped);
                $cur = $swapped;
                $fixes++;
            }
            $n = $norm($cur);
            $dupe = $n !== '' && isset($seen[$field][$n]);
            // The roster line is gated on SHAPE as well as content. A forged
            // space world came back with three of four one-liners reading
            // "The only [role] who can [feat]…" — different words, same
            // sentence — and a fourth in the sibling "The [noun] who [verb]s"
            // frame, and the content comparison above waves all four through.
            // A line's shape is the "<noun> who" frame when it wears one,
            // else its first three words; the second line to wear a shape is
            // a repeat, and the superlative opener is banned outright even on
            // its first appearance, because one of it is already a citation
            // where gossip should be.
            if ($field === 'one_line' && $n !== '' && !$dupe) {
                if (preg_match('/^only /', $n) === 1) {
                    $dupe = true;
                } else {
                    $shape = preg_match('/^(\w+ who) /', $n, $m) === 1
                        ? $m[1]
                        : implode(' ', array_slice(explode(' ', $n), 0, 3));
                    if (isset($seen['one_line@shape'][$shape])) $dupe = true;
                    else $seen['one_line@shape'][$shape] = true;
                }
            }
            if ($n === '' || !$dupe) { $seen[$field][$n] = true; continue; }
            $bank = $banks[$field] ?? [];
            if ($bank === []) continue;
            // walk the bank from a position derived from index so two characters
            // never collide on the replacement either
            for ($k = 0; $k < count($bank); $k++) {
                $cand = $bank[($i + $k) % count($bank)];
                if (!isset($seen[$field][$norm($cand)])) {
                    $set($chars[$i], $cand);
                    $seen[$field][$norm($cand)] = true;
                    if ($field === 'one_line') {
                        $cn = $norm($cand);
                        $seen['one_line@shape'][preg_match('/^(\w+ who) /', $cn, $m) === 1
                            ? $m[1] : implode(' ', array_slice(explode(' ', $cn), 0, 3))] = true;
                    }
                    $fixes++;
                    break;
                }
            }
        }
    }
    if ($fixes > 0) $onNote && $onNote("cast: replaced {$fixes} duplicated or key-shaped interior field(s)");
    return $chars;
}

/**
 * The stretch of a life a cast slot is written to.
 *
 * A town is not a staff meeting. Left alone a small model writes four people
 * between thirty and forty-five and the place reads like an office, so the band
 * is ASSIGNED by slot exactly as the vocal shape is, and the slot's fallback
 * age lands inside it by construction.
 *
 * Children and teenagers are ordinary characters and one of them belongs in a
 * cast of four: a kid has a schedule, a secret, an orbit and a portrait, and in
 * a mystery he is usually the one who saw it. The ONLY thing a minor is kept
 * out of is the desire economy, and that is done at assembly by
 * xeric_forge_age_floor() rather than by asking a model to behave.
 *
 * Work-orbit slots take working ages — they hold down the job the user works
 * alongside. The outside orbit is where the rest of a life lives, so that is
 * where the young and the old come from.
 */
function xeric_forge_age_band(int $index, string $orbit): array
{
    // Eight rows a side, not four: the default cast is twelve, slots
    // alternate orbits, and the walk below moves one row per two slots — so
    // four rows a side repeated bands from the ninth character on, and a
    // sixteen-cast town got two of everybody. Eight a side covers a cast of
    // sixteen without a repeat, and a second child arrives in the bigger
    // casts, which is what a town that size would actually have.
    $work = [
        ['brief' => 'late twenties or thirties.', 'min' => 24, 'max' => 39, 'example' => 31],
        ['brief' => 'forties or fifties.', 'min' => 40, 'max' => 59, 'example' => 46],
        ['brief' => 'sixties — near the end of a working life, and not done yet.', 'min' => 60, 'max' => 74, 'example' => 63],
        ['brief' => 'young — a first real job, still living like a student.', 'min' => 18, 'max' => 23, 'example' => 20],
        ['brief' => 'fifties — mid-career, seen every version of this job.', 'min' => 48, 'max' => 58, 'example' => 53],
        ['brief' => 'late twenties.', 'min' => 25, 'max' => 32, 'example' => 27],
        ['brief' => 'a TEENAGER with a part-time shift — after school, weekends, saving for something.',
         'min' => 14, 'max' => 17, 'example' => 16],
        ['brief' => 'seventies — should have stopped, will not.', 'min' => 68, 'max' => 80, 'example' => 72],
    ];
    $outside = [
        ['brief' => 'thirties or forties.', 'min' => 28, 'max' => 49, 'example' => 37],
        ['brief' => "a CHILD or TEENAGER — somebody's kid, at school, around because "
            . 'a parent works here or because there is nowhere else to be.', 'min' => 9, 'max' => 17, 'example' => 14],
        ['brief' => 'old — retired, been here longer than most of the street.', 'min' => 65, 'max' => 86, 'example' => 71],
        ['brief' => 'twenties.', 'min' => 20, 'max' => 29, 'example' => 25],
        ['brief' => 'a CHILD — primary-school age, around because everybody is around.', 'min' => 8, 'max' => 12, 'example' => 10],
        ['brief' => 'forties — busier outside work than anyone at work would guess.', 'min' => 38, 'max' => 52, 'example' => 44],
        ['brief' => 'eighteen or nineteen — one foot out of town already.', 'min' => 18, 'max' => 19, 'example' => 19],
        ['brief' => 'late fifties.', 'min' => 54, 'max' => 64, 'example' => 58],
    ];
    // Slots alternate orbits (xeric_forge_orbit_for), so the band walks on
    // every SECOND slot: a cast of four is a working adult, a neighbour, an
    // older colleague, and a kid.
    $rows = $orbit === 'outside' ? $outside : $work;
    return $rows[intdiv(max(0, $index), 2) % count($rows)];
}

/**
 * A whole-number age, or this slot's deterministic default.
 *
 * `age` is REQUIRED on every character and is the only input to the minor
 * derivation (xeric_is_minor(), engine/world.php), so nothing leaves the cast
 * pass without one. A plausible human age is honoured exactly as written —
 * including a child's, which is the point of asking. A missing age, a range
 * ("30s"), a fraction or a number no person has takes the band's example.
 *
 * It is never narrowed toward adulthood. A default that quietly ages a child
 * into an adult would be a lie that the exclusion downstream then acts on, and
 * fail-closed means the unknown case reads as a minor, not as an adult.
 */
function xeric_forge_age($v, array $ctx): int
{
    $band = is_array($ctx['age_band'] ?? null)
        ? $ctx['age_band']
        : xeric_forge_age_band((int)($ctx['index'] ?? 0), (string)($ctx['orbit'] ?? 'outside'));

    if (is_int($v) || (is_float($v) && floor($v) === $v)
        || (is_string($v) && preg_match('/^\s*\d{1,3}\s*$/', $v) === 1)) {
        $n = (int)$v;
        if ($n >= 1 && $n <= 104) return $n;
    }
    return (int)$band['example'];
}

function xeric_forge_character_from(array $flat, array $ctx, array $taken): array
{
    $name = xeric_forge_str($flat['display_name'] ?? '', '', 80);
    if ($name === '') throw new RuntimeException('character has no name');

    $handle = xeric_forge_key($name, $taken);
    $keys = $ctx['place_keys'];
    $workPlace = xeric_forge_pick_key($flat['work_place'] ?? '', $keys, $ctx['orbit'] === 'outside' ? $ctx['public'] : $ctx['workplace']);
    $hangPlace = xeric_forge_pick_key($flat['hangout_place'] ?? '', $keys, $ctx['public']);

    $tells = xeric_forge_list($flat['tells'] ?? [], 4, 120);
    if ($tells === []) $tells = ['goes quiet when the subject turns'];

    $week = [[
        'days'  => xeric_forge_days($flat['work_days'] ?? null, [1, 2, 3, 4, 5]),
        'from'  => xeric_forge_hhmm($flat['work_from'] ?? null, '09:00'),
        'to'    => xeric_forge_hhmm($flat['work_to'] ?? null, '17:00'),
        'where' => $workPlace,
        'doing' => xeric_forge_str($flat['work_doing'] ?? '', 'the day\'s work', 80),
    ]];
    if ($hangPlace !== $workPlace) {
        $week[] = [
            'days'  => xeric_forge_days($flat['hangout_days'] ?? null, [5, 6]),
            'from'  => xeric_forge_hhmm($flat['hangout_from'] ?? null, '19:00'),
            'to'    => xeric_forge_hhmm($flat['hangout_to'] ?? null, '22:00'),
            'where' => $hangPlace,
            'doing' => xeric_forge_str($flat['hangout_doing'] ?? '', 'nothing in particular', 80),
        ];
    }

    // Not clamped to adulthood. The old floor of 18 here was the whole reason a
    // forged town had no children in it; what a minor is kept out of is sex,
    // and that exclusion lives in xeric_forge_age_floor().
    $age = xeric_forge_age($flat['age'] ?? null, $ctx);

    return [
        'handle' => $handle,
        'display_name' => $name,
        // What a room of twelve people calls them. Blank is a fine answer and
        // the common one on every world forged before this field existed:
        // xeric_play_short_name() derives one from the nickname already inside
        // the name, or the first word, so nothing anywhere has to cope with a
        // person who has no short form.
        'short_name' => xeric_forge_str($flat['short_name'] ?? '', '', 32),
        // Their own word, from birth. The UI shades their face with it and
        // falls back to reading their prose when it is missing (old worlds);
        // a blank stays blank rather than being guessed at here.
        'pronouns' => xeric_forge_str($flat['pronouns'] ?? '', '', 40),
        'forge' => 'generate',
        'age' => $age,
        'orbit' => $ctx['orbit'],
        'one_line' => xeric_forge_str($flat['one_line'] ?? '', 'Around here more than they mean to be.', 200),
        // COMMONS-safe: `surface` is what a walled viewer is told instead of the
        // dossier, so it may never be derived from voice or pull.
        'surface' => 'someone from ' . $ctx['orbit_label'],
        'appearance' => xeric_forge_str($flat['appearance'] ?? '', '', 300),
        // The frame the face sits in — a photo needs a body as much as a face,
        // and "what you see first" is usually neither. Blank stays blank.
        'build' => xeric_forge_str($flat['build'] ?? '', '', 80),
        // THE INVENTORY, both halves of it: worn and carried. COMMONS by rule —
        // what somebody wears and carries IS what a bystander sees, so these
        // ride the public presence read without touching a wall, and nothing
        // secret may be smuggled in here (a hidden letter is a secret, not a
        // carry). Capped and stringy like a place's interior; absent lists are
        // an unfurnished person, which every world forged before today is.
        'wears'   => xeric_forge_things($flat['wears'] ?? [], 4),
        'carries' => xeric_forge_things($flat['carries'] ?? [], 4),
        'voice' => xeric_forge_str($flat['voice'] ?? '', 'Says less than they mean and means all of it.', 300),
        'temperature' => 1.0,
        'week' => $week,
        'psyche' => [
            'sore_spot' => xeric_forge_str($flat['sore_spot'] ?? '', 'being managed', 160),
            'jealousy' => xeric_forge_str($flat['jealousy'] ?? '', 'people who make it look easy', 160),
            'self_soothe' => xeric_forge_str($flat['self_soothe'] ?? '', 'work they can finish in an hour', 160),
            'praise_that_lands' => xeric_forge_str($flat['praise'] ?? '', 'being told they were right, later', 160),
        ],
        'tells' => $tells,
        // Asked for in words; small models answer with a place key about a
        // third of the time, and "you go to: pier_9_station" reads as a bug in
        // the character's mouth. Keys become the name of the place.
        'solace' => xeric_forge_solace($flat['solace'] ?? '', $ctx),
        'drives' => [
            'pull' => xeric_forge_str($flat['pull'] ?? '', 'to be counted on by somebody who has options', 200),
            'disclosure' => 'subconscious',
        ],
        'relationships' => ['roommates' => [], 'friend_pairs' => [], 'attraction_seeds' => (object)[]],
        'limits' => ['hard' => [], 'soft' => []],
        // Two seeds, minted together and never apart: the face and the frame
        // it sits in. engine/photo.php derives one for pre-photos worlds, but
        // a minted pair beats a derived one — it survives a rename.
        'photos' => ['enabled' => true,
                     'face_seed' => random_int(100000000, 999999999),
                     'body_seed' => random_int(100000000, 999999999)],
    ];
}

/**
 * A short list of THINGS: capped, stringy, dropped rather than repaired.
 * The one shaping rule shared by a room's interior, a person's wears and
 * their carries — the three lists that make the world's objects data.
 */
function xeric_forge_things($raw, int $cap): array
{
    $out = [];
    foreach ((array)$raw as $item) {
        $s = trim(xeric_forge_str(is_array($item) ? ($item['text'] ?? '') : $item, '', 60));
        if ($s !== '' && mb_strlen($s) >= 3) $out[] = $s;
        if (count($out) >= $cap) break;
    }
    return $out;
}

/** Solace as prose. A bare place key becomes the place's name. */
function xeric_forge_solace($v, array $ctx): string
{
    $s = xeric_forge_str($v, 'the long way home', 160);
    $slug = xeric_forge_slug($s, '_');
    foreach ((array)$ctx['places'] as $p) {
        if ((string)$p['key'] === $slug) return (string)$p['name'];
    }
    return $s;
}

/** The hand-written people. Five archetypes, cycled, bound to whatever places exist. */
function xeric_forge_default_character(array $ctx, array $taken): array
{
    $rows = [
        ['name' => 'Nell Farrow', 'age' => 41, 'one' => 'Has been doing this two years longer than you and will never once say so.',
         'voice' => 'Short answers, dry ones, and a second sentence a beat later that is the actual answer.',
         'app' => 'Sleeves pushed up in every season, pen behind the ear she has already lost twice.',
         'sore' => 'being thanked in front of people', 'jeal' => 'anybody who gets to leave at five',
         'sooth' => 'reorganising something that did not need it', 'praise' => 'being told a thing she fixed stayed fixed',
         'tells' => ['straightens what is already straight', 'says "sure" when she disagrees', 'answers the question you should have asked'],
         'pull' => 'to be the one nobody here has to worry about', 'solace' => 'the loading door, ten minutes, no phone',
         'doing' => 'the whole day, start to finish'],
        ['name' => 'Ray Ocampo', 'age' => 33, 'one' => 'Knows everybody, owes three of them money, and is good company anyway.',
         'voice' => 'Fast, warm, tells the story before he tells you why he is telling it.',
         'app' => 'Jacket for a colder day than this one; always half a step into leaving.',
         'sore' => 'being treated like a joke twice in a row', 'jeal' => 'people whose plans work out',
         'sooth' => 'driving with the radio too loud', 'praise' => 'somebody saying he showed up when it counted',
         'tells' => ['starts sentences with "no, listen"', 'buys the round he cannot afford', 'checks the door when it opens'],
         'pull' => 'to be somebody a room is glad to see', 'solace' => 'the last booth, after everyone leaves',
         'doing' => 'talking to whoever is here'],
        ['name' => 'June Whitlock', 'age' => 58, 'one' => 'Runs the thing everybody assumes runs itself.',
         'voice' => 'Plain sentences, long pauses, and one question that goes further than you expected.',
         'app' => 'Reading glasses on a chain she never uses for reading.',
         'sore' => 'being asked if she is sure', 'jeal' => 'people who were never the responsible one',
         'sooth' => 'washing dishes with the water too hot', 'praise' => 'a thing handed back done properly',
         'tells' => ['hands you food instead of answering', 'says "well" before she disagrees', 'stays standing when she means to go'],
         'pull' => 'to be needed in a way she does not have to ask for', 'solace' => 'the empty room before anyone else is up',
         'doing' => 'keeping the place upright'],
        // The kid. A hand-written cast of four has one, because a town has one:
        // he is at the counter after school, he is the reason a shift gets
        // dropped, and he notices what the adults are too busy to. His week is
        // a school week, not a work week, which is why this row carries hours.
        ['name' => 'Otis Pell', 'age' => 13, 'one' => 'Around after school because his mother is still on shift.',
         'voice' => 'Fast, sideways, tells you the end of the story first and then argues with himself about it.',
         'app' => 'School bag with one strap, sleeves over his hands.',
         'sore' => 'being talked over by adults who asked him a question', 'jeal' => 'kids whose parents finish at five',
         'sooth' => 'drawing the same aeroplane over and over', 'praise' => 'being told he was right about something before anyone else',
         'tells' => ['answers a question with a fact nobody wanted', 'kicks the same table leg', 'goes silent when an adult sits down'],
         'pull' => 'to be believed the first time he says something',
         'solace' => 'the back step, where you can see the whole street and nobody sees you',
         'doing' => 'homework, mostly not doing homework',
         'days' => 'weekdays', 'from' => '15:30', 'to' => '18:00',
         'h_days' => 'weekends', 'h_from' => '11:00', 'h_to' => '16:00',
         'h_doing' => 'hanging around waiting for a lift'],
        ['name' => 'Teddy Marsh', 'age' => 27, 'one' => 'Newest here, working twice as hard as anyone asked him to.',
         'voice' => 'Over-explains, catches himself, makes a joke about over-explaining.',
         'app' => 'Clothes a size too careful; hair he keeps touching.',
         'sore' => 'being the youngest in the room', 'jeal' => 'people who make it look effortless',
         'sooth' => 'lists, rewritten', 'praise' => 'being trusted with something without a speech about it',
         'tells' => ['laughs one beat late', 'apologises before he disagrees', 'arrives ten minutes early and waits outside'],
         'pull' => 'to be taken as seriously as the people who were here first', 'solace' => 'the walk home the long way',
         'doing' => 'whatever nobody else wanted'],
        ['name' => 'Marguerite Dial', 'age' => 47, 'one' => 'Came back after years away and has not explained it to anyone.',
         'voice' => 'Careful, funny in a low register, changes the subject like it was an accident.',
         'app' => 'Better coat than anyone else in the room and no comment about it.',
         'sore' => 'being asked why she came back', 'jeal' => 'people who never left and are happy',
         'sooth' => 'long walks in bad weather', 'praise' => 'being told she is easy to be around',
         'tells' => ['answers a question with a question', 'holds a cup with both hands', 'leaves before the goodbyes start'],
         'pull' => 'to be somewhere she does not have to perform', 'solace' => 'the water, whatever the weather is doing',
         'doing' => 'here again, same as yesterday'],
        // Rows seven through sixteen exist because the default cast is twelve:
        // six archetypes under a twelve-slot walk hands the seventh person the
        // first person's name, and a fallback world with two Nell Farrows in
        // it is not a fallback, it is a bug with a face. Sixteen rows cover a
        // sixteen-cast before anything wraps.
        ['name' => 'Gus Palmateer', 'age' => 63, 'one' => 'Opens up an hour before anyone needs him to and has opinions about people who do not.',
         'voice' => 'Slow, gravel-free, everything said once at the volume he decided on in 1988.',
         'app' => 'The same jacket in every season, zipped to exactly half.',
         'sore' => 'being told there is an easier way', 'jeal' => 'men his age who took the buyout',
         'sooth' => 'oiling hinges nobody complained about', 'praise' => 'somebody asking him to look at a thing before they buy it',
         'tells' => ['taps the barometer on the way past', 'stacks chairs before the room is empty', 'calls everyone under fifty "the kid"'],
         'pull' => 'to hand the keys to somebody who deserves them', 'solace' => 'the flag pole at closing, folding it right',
         'doing' => 'the opening routine, unabridged'],
        ['name' => 'Ivy Renshaw', 'age' => 19, 'one' => 'Has a bus timetable folded in her wallet and has not used it yet.',
         'voice' => 'Fast when it is safe, one-word when it counts, saves the real sentences for later.',
         'app' => 'Work shirt over a concert shirt for a band that has never played within four hundred miles.',
         'sore' => 'being told she will grow out of it', 'jeal' => 'anyone who left at her age and calls it easy',
         'sooth' => 'headphones in, one earbud only, in case', 'praise' => 'an adult asking her opinion and then using it',
         'tells' => ['checks the clock over the door', 'draws on her own wrist', 'stands near exits at parties'],
         'pull' => 'to be missed by this place more than she misses it', 'solace' => 'the roof of the car, hood still warm',
         'doing' => 'the closing shift, faster than it needs doing'],
        ['name' => 'Ambrose Dunmore', 'age' => 71, 'one' => 'Retired from the job but not from the corner table, and holds both offices daily.',
         'voice' => 'Court-room careful, softened by thirty years of nobody objecting.',
         'app' => 'Shirt buttoned to the collar, no tie, a pen he clicks but rarely uses.',
         'sore' => 'being summarised', 'jeal' => 'men whose sons call on weekdays',
         'sooth' => 'rereading minutes of meetings long adjourned', 'praise' => 'being asked for the story behind the story',
         'tells' => ['folds his napkin before disagreeing', 'quotes the exact date', 'stands when anyone leaves the table'],
         'pull' => 'to be consulted once more on something that matters', 'solace' => 'the cemetery road, walked slowly, hat on',
         'doing' => 'presiding, unofficially'],
        ['name' => 'Faye Herrick', 'age' => 36, 'one' => 'Runs three lives on one calendar and nobody has ever seen her check it.',
         'voice' => 'Efficient and kind in the same breath, lists that end in a joke.',
         'app' => 'Everything in pockets, nothing in bags, keys on a carabiner that has a story.',
         'sore' => 'being called organised like it is a personality', 'jeal' => 'people with one job and a hobby',
         'sooth' => 'ironing things that do not need it', 'praise' => 'somebody noticing the thing that did NOT go wrong',
         'tells' => ['answers texts in the order they arrived', 'feeds whoever sits down', 'hums when the plan is working'],
         'pull' => 'for one whole day to run without her and be fine', 'solace' => 'the laundromat spin cycle, watched like television',
         'doing' => 'four errands folded into one'],
        ['name' => 'Sal Antonelli', 'age' => 52, 'one' => 'Knows the price of everything in town and charges most people less than that.',
         'voice' => 'Loud greetings, quiet deals, and a whisper that means the real conversation started.',
         'app' => 'Apron strings doubled around front, pencil behind the ear that writes nothing down.',
         'sore' => 'being asked for a receipt by a friend', 'jeal' => 'businesses with somebody to leave the keys to',
         'sooth' => 'restacking the window display at night', 'praise' => 'being told the old man would have approved',
         'tells' => ['rounds down out loud', 'feeds the meter for strangers', 'argues prices he already decided to drop'],
         'pull' => 'to be owed nothing and known anyway', 'solace' => 'the storeroom ladder, top step, radio on',
         'doing' => 'the counter, the ledger, the neighborhood'],
        ['name' => 'Winnie Okafor', 'age' => 24, 'one' => 'Arrived for a six-month posting two years ago and keeps not booking the flight home.',
         'voice' => 'Precise, warm, translates her own jokes when nobody laughs fast enough.',
         'app' => 'Dressed for a slightly better town, adjusting one item at a time toward this one.',
         'sore' => 'being asked when she is really from', 'jeal' => 'people whose whole family fits in one kitchen',
         'sooth' => 'voice notes to her sister, never under ten minutes', 'praise' => 'being told the place would notice if she left',
         'tells' => ['photographs ordinary things', 'learns names on the first try', 'keeps her coat on until she decides to stay'],
         'pull' => 'to stop calling two different places home in the same sentence', 'solace' => 'the international aisle at the market, unhurried',
         'doing' => 'the job she is visibly overqualified for'],
        ['name' => 'Lena Crowder', 'age' => 16, 'one' => 'Works the counter Saturdays and knows more about the regulars than their families do.',
         'voice' => 'Deadpan for adults, rapid for friends, and an announcer voice for reading signs aloud.',
         'app' => 'Name tag decorated beyond regulation, sleeves pushed up like somebody twice her age.',
         'sore' => 'being tipped like a charity', 'jeal' => 'kids whose Saturdays are their own',
         'sooth' => 'reorganising the candy rack by a system only she knows', 'praise' => 'a regular asking for her by name',
         'tells' => ['counts change twice for the old ones', 'mouths the totals', 'saves the window seat for the same customer'],
         'pull' => 'to be trusted with the register and the truth at the same time',
         'solace' => 'the loading dock steps between rushes',
         'doing' => 'the register, and the intelligence service',
         'days' => 'weekends', 'from' => '09:00', 'to' => '15:00',
         'h_days' => 'weekdays', 'h_from' => '16:00', 'h_to' => '18:00',
         'h_doing' => 'homework at a corner table, allegedly'],
        ['name' => 'Hobart Yoakum', 'age' => 68, 'one' => 'Fixes what gets brought to him and files what people say while they wait.',
         'voice' => 'Barely above the workbench, and you lean in, which is how he likes it.',
         'app' => 'Glasses pushed up on the forehead, a second pair hanging at the collar.',
         'sore' => 'parts described as "the little whatsit"', 'jeal' => 'nobody, and he would thank you not to check',
         'sooth' => 'taking apart something already fixed', 'praise' => 'a machine brought back to HIM after somebody else failed',
         'tells' => ['tests screws with a fingernail', 'talks to the object, not the owner', 'keeps every third broken thing'],
         'pull' => 'to be the last one who knows how the old ones work', 'solace' => 'the bench light after supper, door cracked',
         'doing' => 'repairs, and the archive of what people let slip'],
        ['name' => 'Tessa Bright', 'age' => 30, 'one' => 'Trains harder than anyone in town for a race she has not named yet.',
         'voice' => 'Breathless and cheerful, sentences timed to a stride nobody else is keeping.',
         'app' => 'Running shoes at every occasion including the wrong ones.',
         'sore' => 'being asked what she is running from', 'jeal' => 'people who rest without negotiating with themselves',
         'sooth' => 'lap counts, out loud, until the number is the only thought', 'praise' => 'somebody keeping pace without being asked',
         'tells' => ['stretches against whatever is vertical', 'eats like it is logistics', 'waves at every single car'],
         'pull' => 'to cross one finish line with somebody she loves at the tape', 'solace' => 'the track before the dew burns off',
         'doing' => 'miles, and the errands along them'],
        ['name' => 'Curtis Lowe', 'age' => 44, 'one' => 'Coaches whatever season it is and keeps a folding chair in the truck for whatever it is not.',
         'voice' => 'Practice-field volume dialed down for indoors, mostly successfully.',
         'app' => 'Whistle worn like jewelry, cap bill curved to regulation.',
         'sore' => 'parents who coach from the fence', 'jeal' => 'men who played past nineteen',
         'sooth' => 'chalking lines straighter than the game requires', 'praise' => 'a kid he cut coming back to say it worked out',
         'tells' => ['claps twice before bad news', 'learns the shy kid\'s name first', 'rakes the infield nobody plays on Mondays'],
         'pull' => 'to send one of them somewhere he never got to', 'solace' => 'the bleachers after the lights time out',
         'doing' => 'drills, carpools, and morale'],
    ];
    // Walk from this slot's archetype to the first one nobody in the world is
    // already standing on. Indexing by slot alone is why an offline reroll used
    // to hand back the very person it was asked to replace: the model is dead,
    // the row is a function of the position, and the position did not move.
    $avoid = [];
    foreach ((array)($ctx['avoid'] ?? []) as $a) $avoid[xeric_forge_key((string)$a)] = true;
    foreach (array_keys($taken) as $h) $avoid[(string)$h] = true;

    $n = count($rows);
    $r = $rows[$ctx['index'] % $n];
    for ($step = 0; $step < $n; $step++) {
        $cand = $rows[($ctx['index'] + $step) % $n];
        if (!isset($avoid[xeric_forge_key((string)$cand['name'])])) { $r = $cand; break; }
    }
    // The same shelf gate the model's people get: the second offline world on
    // a shelf must not hand back the first one's Nell Farrow. The register
    // rides in on ctx when the cast pass built it; a caller without one
    // (repair's stand-in) computes the answerless register, which is still
    // deterministic and still reads the shelf.
    $r['name'] = xeric_forge_fresh_person_name((string)$r['name'],
        is_array($ctx['naming'] ?? null) ? $ctx['naming'] : xeric_forge_naming([]),
        (int)$ctx['index'], $taken);

    $isWork = $ctx['orbit'] !== 'outside';
    $where = $isWork ? $ctx['workplace'] : $ctx['public'];
    $other = $isWork ? $ctx['public'] : $ctx['workplace'];

    $flat = [
        'display_name' => $r['name'], 'age' => $r['age'], 'one_line' => $r['one'],
        'appearance' => $r['app'], 'voice' => $r['voice'],
        'sore_spot' => $r['sore'], 'jealousy' => $r['jeal'], 'self_soothe' => $r['sooth'], 'praise' => $r['praise'],
        'tells' => $r['tells'], 'pull' => $r['pull'], 'solace' => $r['solace'],
        // A row may carry its own hours. The generic block is a working adult's
        // day, and a thirteen-year-old is not at the diner from ten to four.
        'work_place' => $where, 'work_days' => $r['days'] ?? 'weekdays',
        'work_from' => $r['from'] ?? ($isWork ? '09:00' : '10:00'),
        'work_to' => $r['to'] ?? ($isWork ? '17:00' : '16:00'), 'work_doing' => $r['doing'],
        'hangout_place' => $other, 'hangout_days' => $r['h_days'] ?? 'weekends',
        'hangout_from' => $r['h_from'] ?? '19:00', 'hangout_to' => $r['h_to'] ?? '22:00',
        'hangout_doing' => $r['h_doing'] ?? 'the usual, later than planned',
    ];
    return xeric_forge_character_from($flat, $ctx, $taken);
}

// ---------------------------------------------------------------------------
// Assembly + repair
// ---------------------------------------------------------------------------

/**
 * The canonical subsystem vocabulary. A motivation may arm ANY of these; the
 * list is what the engine actually knows how to switch on, so a resolver
 * (model or table) may only return names from here.
 */
const XERIC_SYSTEMS = [
    'daily_rhythms'            => 'schedules, routines, the ordinary week',
    'visits'                   => 'people come to you, and expect you to come to them',
    'expectations'             => 'promises are heard, kept, missed, and remembered — a plan made is a plan somebody waits on',
    'shared_meals'             => 'food as the unit of togetherness',
    'remembering'              => 'they hold what you told them and bring it back',
    'gentle_proactive_contact' => 'someone checks on you, softly, unprompted',
    'attraction'               => 'wanting, between people, tracked',
    'arcs'                     => 'relationships that move over time',
    'jealousy'                 => 'who got attention, and who noticed',
    'private_history'          => 'the things only the two of you reference',
    'standings'                => 'an unspoken pecking order',
    'favors'                   => 'debts owed in kindness, tracked',
    'rivals'                   => 'someone measures themselves against you',
    'boons'                    => 'a thing to chase, that can be won',
    'the_ladder'               => 'position that can be climbed or lost',
    'strange_place'            => 'somewhere the rules are different',
    'rumors'                   => 'stories that travel and distort',
    'unreliable_witnesses'     => 'accounts that disagree',
    'slow_reveal'              => 'the world gives up its truth in pieces',
    'a_debt'                   => 'something owed that predates the story',
    'people_who_remember'      => 'your past is known here',
    'a_chance_to_be_different' => 'the world will let you change, if you work',
    'scarcity'                 => 'not enough to go around',
    'danger'                   => 'the world can cost you',
    'alliances_that_cost'      => 'help is real and it is never free',
    'faith'                    => 'belief, ritual, and the people who keep it',
    'craft'                    => 'skill, apprenticeship, work done well',
    'secrets'                  => 'things held back, and the pressure of holding them',
    'grief'                    => 'absence as a live presence in the world',
    'comfort_systems'          => 'softness, safety, small pleasures',
];

/**
 * Which subsystems this world arms.
 *
 * The user may type ANY motivation (owner, 2026-07-30: "I want a user to
 * choose any motivation truly") — "get my daughter to speak to me again",
 * "prove the mine is poisoning the river", "learn to cook like my mother".
 * So this is a RESOLVER, not a lookup: the known motivations stay as exact
 * hits (fast, free, and the tested path), and anything else is resolved by
 * the model against the vocabulary above, with a keyword fallback so a dead
 * or dumb model still produces a sane world.
 *
 * @param array|null $endpoint  null = table + keyword fallback only
 */
function xeric_forge_armed(string $motivation, ?array $endpoint = null, ?callable $onNote = null): array
{
    // Every name here is a member of XERIC_SYSTEMS, and the table is cleaned on
    // the way out anyway: company's disarms used to read rivalry /
    // desire_economies / mystery_pressure, which are English rather than
    // vocabulary, so the one case this table was written for — the elderly user
    // who wants company and nothing sharper — disarmed nothing at all.
    $table = [
        'company'    => [['daily_rhythms', 'visits', 'shared_meals', 'remembering', 'gentle_proactive_contact', 'expectations'],
                         ['rivals', 'jealousy', 'unreliable_witnesses']],
        'romance'    => [['attraction', 'arcs', 'jealousy', 'private_history', 'expectations'], []],
        'ambition'   => [['standings', 'favors', 'rivals', 'boons', 'the_ladder'], []],
        'mystery'    => [['strange_place', 'rumors', 'unreliable_witnesses', 'slow_reveal'], []],
        'redemption' => [['a_debt', 'people_who_remember', 'a_chance_to_be_different'], []],
        'survival'   => [['scarcity', 'danger', 'alliances_that_cost'], ['comfort_systems']],
    ];
    $key = strtolower(trim($motivation));
    if (isset($table[$key])) {
        return ['armed' => xeric_forge_systems_clean($table[$key][0]),
                'disarmed' => xeric_forge_systems_clean($table[$key][1]), 'source' => 'preset'];
    }
    // NO GOAL ARMS NOTHING. This used to fall through to company's five, which
    // is right for a forge that was never given a motivation and wrong for the
    // one caller that can hand over an empty one on purpose: a xeric started
    // blank, whose whole proposition is that nothing happens until the person
    // living in it decides what this place is for. Clearing the box is now a
    // way to switch the world off rather than a way to get somebody else's
    // idea of company. A forged world never reaches this — xeric_forge_assemble
    // defaults the motivation to "company" long before here.
    if ($key === '') {
        return ['armed' => [], 'disarmed' => [], 'source' => 'none'];
    }

    // Free-form. Ask the model which of the known systems this world needs.
    if ($endpoint !== null) {
        try {
            $vocab = '';
            foreach (XERIC_SYSTEMS as $k => $desc) $vocab .= "- $k: $desc\n";
            $out = xeric_forge_ask($endpoint, 'systems', [
                ['role' => 'system', 'content' =>
                    "You configure a life-simulation engine. Given what a person wants from their world, "
                    . "choose which subsystems to switch on.\n\nAVAILABLE SYSTEMS:\n{$vocab}\n"
                    . "Rules: choose 3-6 for `arm`. Choose for `disarm` only systems that would actively "
                    . "work AGAINST what they want (often none). Use ONLY names from the list above. "
                    . "Reply ONLY JSON: {\"arm\":[…],\"disarm\":[…],\"why\":\"one short sentence\"}"],
                ['role' => 'user', 'content' => "What they want from this world:\n" . mb_substr($motivation, 0, 400)],
            ], ['temperature' => 0.3, 'max_tokens' => 300]);
            $arm = xeric_forge_systems_clean($out['arm'] ?? []);
            $dis = xeric_forge_systems_clean($out['disarm'] ?? []);
            if ($arm !== []) {
                $onNote && $onNote('motivation "' . mb_substr($motivation, 0, 40) . '" arms: ' . implode(', ', $arm));
                return ['armed' => $arm, 'disarmed' => $dis, 'source' => 'model',
                        'why' => xeric_forge_str($out['why'] ?? '', '', 200)];
            }
        } catch (Throwable $e) {
            $onNote && $onNote('system resolver failed (' . $e->getMessage() . ') — using keywords');
        }
    }

    // Keyword fallback: a dead model must still produce a coherent world.
    $hits = [];
    $probe = [
        'company'    => 'lonely|alone|company|friend|family|belong|community|talk to|visit',
        'romance'    => 'love|romance|romantic|marry|date|dating|attract|affair|partner',
        'ambition'   => 'career|money|power|win|succeed|climb|rich|boss|prove myself|status',
        'mystery'    => 'mystery|secret|truth|find out|uncover|investigate|strange|haunt|missing',
        'redemption' => 'redeem|forgive|amends|second chance|start over|make it right|sober|guilt',
        'survival'   => 'survive|survival|escape|danger|hunt|scarcity|last|endure|apocalyp',
    ];
    foreach ($probe as $k => $rx) {
        if (preg_match('/\b(' . $rx . ')/i', $motivation)) $hits[] = $k;
    }
    if ($hits === []) $hits = ['company'];
    $armed = $disarmed = [];
    foreach ($hits as $k) {
        $armed = array_merge($armed, $table[$k][0]);
        $disarmed = array_merge($disarmed, $table[$k][1]);
    }
    // a system someone else armed must not stay disarmed
    $armed = xeric_forge_systems_clean($armed);
    $disarmed = xeric_forge_systems_clean(array_diff($disarmed, $armed));
    return ['armed' => $armed, 'disarmed' => $disarmed, 'source' => 'keywords'];
}

/** Keep only names the engine actually knows; dedupe; cap. */
function xeric_forge_systems_clean($list): array
{
    $out = [];
    foreach ((array)$list as $v) {
        $k = strtolower(trim((string)$v));
        $k = preg_replace('/[^a-z_]+/', '_', $k) ?? '';
        $k = trim($k, '_');
        if ($k !== '' && isset(XERIC_SYSTEMS[$k]) && !in_array($k, $out, true)) $out[] = $k;
        if (count($out) >= 8) break;
    }
    return $out;
}

/**
 * When the world sleeps, derived from when the USER is around.
 *
 * A motel night-desk world was forged with quiet hours of 23:00-08:00 — its
 * most alive hours were its deadest, and nothing could happen exactly when its
 * owner was playing (2026-07-30). Quiet hours are not a property of worlds;
 * they are a property of people. The world goes quiet when you do.
 *
 * Free text is read for an explicit range or a late-night tell before falling
 * back to the presets.
 */
function xeric_forge_quiet_hours(string $around): string
{
    $a = mb_strtolower(trim($around));
    // "up till 2am", "awake until 1", "2am most nights"
    if (preg_match('/\b(?:till|until|til)\s*(\d{1,2})\s*(am|pm)?/', $a, $m)) {
        $h = (int)$m[1];
        $mer = $m[2] ?? '';
        if ($mer === 'pm') {
            if ($h < 12) $h += 12;
        } elseif ($mer === 'am') {
            if ($h === 12) $h = 0;
        } else {
            // A bare hour after "until" is the one a person means out loud, not
            // a 24-hour reading: nobody says "up until 11" about eleven in the
            // morning, and midnight is said as twelve. Reading 11 as 11:00 put
            // a night owl's world to sleep all afternoon and woke it at 03:00 —
            // the exact inversion this step was added to prevent (2026-07-30).
            if ($h === 12) $h = 0;
            elseif ($h >= 5 && $h <= 11) $h += 12;
        }
        if ($h <= 23) {
            $start = $h;                        // they sleep when they stop
            $end = ($start + 6) % 24;           // a short night, since they are late
            return sprintf('%02d:00-%02d:00', $start, $end);
        }
        // an hour no clock has: say nothing rather than something wrong
    }
    if (preg_match('/\b(\d{1,2})\s*am\b/', $a, $m) && str_contains($a, 'before')) {
        $wake = (int)$m[1] % 24;                // "6am before the kids wake"
        return sprintf('%02d:00-%02d:00', ($wake + 17) % 24, $wake);
    }
    return match (true) {
        str_contains($a, 'night')   => '04:00-12:00',   // nocturnal: the world sleeps by day
        str_contains($a, 'morning') => '21:00-05:00',
        str_contains($a, 'workday') || str_contains($a, 'through the day') => '22:00-07:00',
        str_contains($a, 'scatter') => '02:00-07:00',   // narrow: they could be anywhere
        default                     => '23:00-08:00',   // evenings
    };
}

/**
 * Event density, normalised PER VISIT rather than per hour.
 *
 * The sweep fires per world-hour, but what a person experiences is per visit.
 * Someone who looks in every evening lives ~16 unattended hours between visits;
 * someone who looks in once a week lives ~150. The same hourly chance gives the
 * first person a trickle and the second a soap opera. So the hourly rate is
 * derived from a target number of events PER VISIT and the expected gap.
 *
 * @return array{pace:string, chance:float, per_visit:float, gap_hours:int}
 */
function xeric_forge_pace(string $pace, string $around): array
{
    $perVisit = match (mb_strtolower(trim($pace))) {
        'eventful' => 3.0,
        'calm'     => 0.7,
        default    => 1.5,      // steady
    };
    // how many unattended hours a typical gap holds, by playing habit
    $a = mb_strtolower(trim($around));
    $gap = match (true) {
        str_contains($a, 'workday') || str_contains($a, 'through the day') => 4,
        str_contains($a, 'scatter') => 8,
        default => 16,          // once a day: evenings, mornings, nights
    };
    // quiet hours eat part of the gap; assume roughly two thirds is sweepable
    $sweepable = max(1, (int)round($gap * 0.66));
    $chance = min(0.9, max(0.05, $perVisit / $sweepable));
    return ['pace' => $pace ?: 'steady', 'chance' => round($chance, 3),
            'per_visit' => $perVisit, 'gap_hours' => $gap];
}

/**
 * A legal shape built from six proportions. The disposal half of "model
 * proposes, code disposes", and the reason the invented shape cannot fail.
 *
 * WHY SCALARS AND NOT A CURVE. A snake is only legal if the curve is FLAT AT
 * EXACTLY 0.5 across its declared false calm — the arithmetic the ×1.0 claim
 * rests on — and asking a small model to hand back a piecewise-linear curve
 * that satisfies that is asking it to do the one part of this it is worst at.
 * It would fail validation most times and the option would be a fallback with
 * extra steps. So the model is asked for the DRAMA — where the calm sits, how
 * long it holds, where the peak lands and how loud — and this builds the curve.
 * Every knob is clamped and the control points are forced strictly increasing,
 * so there is no input, hostile or confused, that produces an illegal shape.
 *
 * @param array $spec whatever the model said; every field optional
 */
function xeric_forge_shape_build(array $spec): array
{
    $num = fn(string $k, float $d, float $lo, float $hi): float
        => max($lo, min($hi, isset($spec[$k]) && is_numeric($spec[$k]) ? (float)$spec[$k] : $d));

    // Where the flat sits, and how long it holds. Everything else is arranged
    // around it, because it is the part with an exact value to honour.
    $calmFrom = $num('calm_from', 0.45, 0.15, 0.70);
    $calmTo   = max($calmFrom + 0.08, $num('calm_to', 0.70, 0.23, 0.85));

    // Four positions, forced apart so progress strictly increases whatever came
    // back. 0.04 is comfortably above the float noise a 0..1 curve carries.
    $riseAt = max(0.04, min($calmFrom - 0.04, $num('rise_at', 0.28, 0.04, 0.66)));
    $peakAt = max($calmTo + 0.04, min(0.96, $num('peak_at', 0.90, 0.27, 0.96)));

    $shape = [
        'label' => xeric_forge_str($spec['label'] ?? '', 'a shape of its own', 60),
        'hint'  => xeric_forge_str($spec['hint'] ?? '', 'invented for this xeric', 120),
        'curve' => [
            [0.00,      $num('open_at', 0.20, 0.0, 1.0)],
            [$riseAt,   $num('rise_to', 0.75, 0.0, 1.0)],
            [$calmFrom, 0.50],
            [$calmTo,   0.50],
            [$peakAt,   $num('peak',    0.95, 0.0, 1.0)],
            [1.00,      $num('end_at',  0.30, 0.0, 1.0)],
        ],
        'false_calm' => [$calmFrom, $calmTo],
        'pace_swing' => $num('swing', 0.55, 0.0, 0.9),
        'cycle_days' => (int)round($num('cycle_days', 30.0, 3.0, 365.0)),
        'kind_thumb' => [],
    ];

    // A thumb may re-weight what a world armed and may never arm or delete, so
    // unknown stages, unknown kinds and non-positive multipliers are dropped
    // here rather than carried into a template for the engine to ignore.
    $stages = xeric_story_stages();
    $kinds  = array_keys(xeric_sweep_kinds());
    foreach ((array)($spec['kind_thumb'] ?? []) as $stage => $thumb) {
        if (!in_array((string)$stage, $stages, true) || !is_array($thumb)) continue;
        foreach ($thumb as $k => $m) {
            if (!in_array((string)$k, $kinds, true) || !is_numeric($m)) continue;
            $m = (float)$m;
            if ($m <= 0.0 || $m > 4.0) continue;
            $shape['kind_thumb'][(string)$stage][(string)$k] = round($m, 2);
        }
    }
    return $shape;
}

/**
 * The shape this world runs on: a name from the library, or one invented for it.
 *
 * THE ANSWER IS A KEY, AND AN UNREADABLE ONE IS `none`. Fail-quiet rather than
 * fail-closed, which is the right default here for the reason shape.php gives:
 * pacing is not a safety property, and a world whose shape nobody can read runs
 * at the rate it declared for itself — the behaviour every world had before
 * shapes existed.
 *
 * `invent` needs a model and this is called once from a path that sometimes has
 * none (the preview assembly at the top of the file passes no endpoint). With
 * no endpoint, or a model that will not answer, it falls back to the plot snake
 * and SAYS SO — silently handing back a different shape than the one somebody
 * chose is the kind of quiet substitution that makes a setting untrustworthy.
 */
function xeric_forge_shape(string $answer, array $concept, ?array $endpoint = null, ?callable $onNote = null): string|array
{
    $answer = mb_strtolower(trim($answer));
    $lib    = xeric_story_shapes();

    if ($answer === '' || $answer === 'none') return 'none';
    if (isset($lib[$answer])) return $answer;
    if ($answer !== 'invent' && $answer !== 'surprise') {
        xeric_forge_note($onNote, "shape: '$answer' is not a shape this engine knows — running with none");
        return 'none';
    }

    if ($endpoint === null) return 'snake';

    $premise = xeric_forge_str($concept['premise'] ?? ($concept['one_line'] ?? ''), '', 400);
    $reg     = xeric_forge_str($concept['register'] ?? '', '', 80);

    $msgs = [
        ['role' => 'system', 'content' =>
            'You design the RHYTHM of a story world — how much happens, and when. Not the plot: '
            . 'the shape the plot would be paced against. Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "The world:\n$premise\n" . ($reg !== '' ? "Register: $reg\n" : '')
            . "\nInvent a rhythm that suits it. Every number is a position from 0 to 1 along one "
            . "full cycle, except where noted.\n\n"
            . "Reply exactly:\n{\n"
            . '  "label": "three or four words naming the rhythm",' . "\n"
            . '  "hint": "one short sentence a person choosing it would read",' . "\n"
            . '  "open_at": 0.0-1.0,   how loud it starts' . "\n"
            . '  "rise_at": 0.0-0.66,  where the first build tops out' . "\n"
            . '  "rise_to": 0.0-1.0,   how loud that build gets' . "\n"
            . '  "calm_from": 0.15-0.70, where the quiet stretch begins' . "\n"
            . '  "calm_to": 0.23-0.85,   where it ends' . "\n"
            . '  "peak_at": 0.27-0.96,   where the loudest point lands' . "\n"
            . '  "peak": 0.0-1.0,        how loud it gets there' . "\n"
            . '  "end_at": 0.0-1.0,      where it settles' . "\n"
            . '  "swing": 0.0-0.9,       how far the whole thing swings the rate' . "\n"
            . '  "cycle_days": 3-365,    how many days one full cycle takes' . "\n"
            . "}\n\n"
            . "The quiet stretch is the world at EXACTLY its own ordinary pace — not slack, not "
            . "building. Make it a real stretch, not a pause. A rhythm whose loudest point barely "
            . "clears the middle is a world where the big night and an ordinary Tuesday feel the same."],
    ];

    try {
        $out = xeric_forge_ask($endpoint, 'shape', $msgs, ['temperature' => 0.9, 'max_tokens' => 400], $onNote);
    } catch (Throwable $e) {
        xeric_forge_note($onNote, 'shape: the model would not draw one (' . $e->getMessage()
            . ') — running with the plot snake');
        return 'snake';
    }

    $shape = xeric_forge_shape_build(is_array($out) ? $out : []);

    // Belt and braces. The builder cannot produce an illegal shape, so this can
    // only fire if the builder itself is wrong — which is exactly when a world
    // should not be paced by it.
    $bad = xeric_story_shape_check($shape);
    if ($bad !== []) {
        xeric_forge_note($onNote, 'shape: the invented rhythm did not validate (' . implode('; ', $bad)
            . ') — running with the plot snake');
        return 'snake';
    }
    $shape['key'] = 'invented';
    xeric_forge_note($onNote, 'shape: invented — ' . $shape['label'] . ', a '
        . $shape['cycle_days'] . '-day cycle');
    return $shape;
}

/**
 * Narrative gravity: how much of this world is about the user.
 *
 * Distinct from motivation, which says what they WANT. This says how much the
 * world bends toward them. A side character in a mystery world hears things in
 * a bar; a main character in the same world has people turning up at their door.
 * Same engine, same motivation, completely different experience.
 *
 * Three knobs come out of it:
 *  - concern:  how often a sweep event is about the user or touches their thread
 *  - reach:    multiplier on how often anybody makes unprompted contact
 *  - framing:  what the bible calls the user's own section, and how it reads.
 *              A side character is not "at the centre" of anything, and telling
 *              the model otherwise is the fastest way to make the whole cast
 *              orbit somebody they barely know.
 */
function xeric_forge_centrality(string $c): array
{
    return match (mb_strtolower(trim($c))) {
        'main' => [
            'centrality' => 'main',
            'concern' => 0.65, 'reach' => 1.4,
            'heading' => 'THE PERSON AT THE CENTER',
            'framing' => 'This world turns around them. What happens here tends to involve them, '
                . 'or be about them, or be a consequence of something they did.',
        ],
        'side' => [
            'centrality' => 'side',
            'concern' => 0.12, 'reach' => 0.5,
            'heading' => 'THE PERSON YOU KNOW',
            'framing' => 'They are not the centre of anything here. This world has its own business '
                . 'and would have it without them. They are around, they are known, and they hear '
                . 'things — that is all. Do not orbit them, and do not treat their arrival as an event.',
        ],
        default => [
            'centrality' => 'ensemble',
            'concern' => 0.35, 'reach' => 1.0,
            'heading' => 'ONE OF THE PEOPLE HERE',
            'framing' => 'They matter here as much as anyone does, and no more. Some of what happens '
                . 'involves them; plenty of it does not, and carries on regardless.',
        ],
    };
}

// ---------------------------------------------------------------------------
// The age floor
// ---------------------------------------------------------------------------

/**
 * The desire economy, by name — the ONE thing a minor is kept out of.
 *
 * Scoped to sex and to nothing else. `jealousy`, `arcs` and `private_history`
 * are ordinary human weather: a twelve-year-old can be jealous of his brother,
 * can have a friendship that moves over a year, and can share a private joke
 * with his aunt. They are deliberately NOT on this list and must not be added
 * to it. `attraction` is wanting between people, tracked, and that is the one a
 * minor is never armed with.
 */
const XERIC_DESIRE_SYSTEMS = ['attraction'];

/**
 * The floor: no sexual content involving a minor, made STRUCTURAL.
 *
 * Read the shape of this function before changing it, because the shape is the
 * policy. A minor here is a whole character — cast member, orbit, schedule,
 * secrets, portrait, witness, the lot — and the only thing that comes off him
 * is the desire economy:
 *
 *   • his attraction seeds are emptied, and everybody else's seeds pointed at
 *     him are dropped, so the economy has no edge that touches him;
 *   • his per-character armed set is the world's minus XERIC_DESIRE_SYSTEMS,
 *     so the systems that generate wanting are never switched on over him;
 *   • every rating gate inside his record comes down to his effective rating
 *     (xeric_effective_rating(), which returns the weakest rating for a minor
 *     in every world), so an explicit-rated node under him cannot exist;
 *   • a world whose entire cast is minors disarms the desire systems outright,
 *     because there is nobody left they could legally fire over.
 *
 * Nothing else moves. If a change here would keep a kid out of a sweep, off the
 * shelf, out of an orbit or out of a conversation, it is the wrong change.
 *
 * `xeric_is_minor()` and `xeric_effective_rating()` are the engine's and are
 * never reimplemented here: age is the only input, a missing or non-integer age
 * reads as a minor, and the flag is computed rather than read off the template
 * so the model can never clear it.
 */
function xeric_forge_age_floor(array $t, ?callable $onNote = null): array
{
    $worldRating = (string)($t['meta']['rating'] ?? xeric_ratings()[0]);
    $armed       = xeric_forge_systems_clean((array)($t['forge']['armed'] ?? []));
    $disarmed    = xeric_forge_systems_clean((array)($t['forge']['disarmed'] ?? []));
    $chars       = (array)($t['cast']['characters'] ?? []);

    // Who is a minor, decided once, before anybody's relationships are read:
    // dropping an edge needs to know about the far end of it too.
    $minors = [];
    $pool   = [];
    $named  = [];
    foreach ($chars as $c) {
        if (!is_array($c)) continue;
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        if (xeric_is_minor($c)) {
            $minors[$h] = true;
            $named[] = (string)($c['display_name'] ?? $h) . ' (' . (string)($c['age'] ?? '?') . ')';
        } else {
            $pool[] = $h;
        }
    }

    foreach ($chars as $i => $c) {
        if (!is_array($c)) continue;
        $minor = xeric_is_minor($c);

        // No edge of the desire economy may touch a minor, from either end.
        $seeds = (array)($c['relationships']['attraction_seeds'] ?? []);
        if ($minor) {
            $seeds = [];
        } else {
            foreach (array_keys($seeds) as $who) {
                if (isset($minors[(string)$who])) unset($seeds[$who]);
            }
        }
        $c['relationships'] = (array)($c['relationships'] ?? []);
        $c['relationships']['attraction_seeds'] = $seeds === [] ? (object)[] : $seeds;

        // Per-character arming. Written for everybody so it can be read and
        // argued with, but it is DERIVED, not authoritative: anything acting on
        // it must still derive the flag with xeric_is_minor(), because a field
        // that has been edited out has to fail closed.
        $c['armed'] = $minor ? array_values(array_diff($armed, XERIC_DESIRE_SYSTEMS)) : $armed;

        if ($minor) {
            unset($c['flirt_style']);       // the Hall inventory is a flirting style; a child has none
            $c = xeric_forge_rating_lock($c, xeric_effective_rating($worldRating, $c));
            $hard = array_values(array_filter(array_map('strval', (array)($c['limits']['hard'] ?? []))));
            $line = 'nothing sexual or romantic: this character is a minor';
            if (!in_array($line, $hard, true)) $hard[] = $line;
            $c['limits'] = (array)($c['limits'] ?? []);
            $c['limits']['hard'] = $hard;
        }

        $chars[$i] = $c;
    }
    $t['cast']['characters'] = array_values($chars);

    // A world with no adults in it has nobody the desire systems could fire
    // over, so they come off the world as well as off the people.
    if ($pool === [] && array_intersect($armed, XERIC_DESIRE_SYSTEMS) !== []) {
        $armed    = array_values(array_diff($armed, XERIC_DESIRE_SYSTEMS));
        $disarmed = xeric_forge_systems_clean(array_merge($disarmed, XERIC_DESIRE_SYSTEMS));
        xeric_forge_note($onNote, 'age floor: nobody in this cast is an adult — the desire systems are disarmed');
    }

    $t['forge'] = (array)($t['forge'] ?? []);
    $t['forge']['armed']    = $armed;
    $t['forge']['disarmed'] = $disarmed;
    // Who may be placed in a desire economy. The pool is the record; the
    // derivation is still the truth.
    $t['forge']['desire_pool'] = $pool;
    $t['forge']['desire_excluded'] = array_keys($minors);

    if ($named !== []) {
        xeric_forge_note($onNote, 'age floor: ' . implode(', ', $named)
            . ' — out of the desire economy, and in everything else');
    }
    return $t;
}

/**
 * Bring every rating gate inside ONE character down to $eff.
 *
 * `rating_min` above the ceiling is lowered rather than deleted, because a node
 * with no gate at all is a node that shows everywhere; content pools keyed
 * above it are dropped, since a pool is the content. Both directions are the
 * same rule: nothing under a minor may be reachable above his effective rating.
 */
function xeric_forge_rating_lock(array $node, string $eff): array
{
    $out = [];
    foreach ($node as $k => $v) {
        if ($k === 'rating_min') {
            $out[$k] = xeric_rating_rank((string)$v) > xeric_rating_rank($eff) ? $eff : (string)$v;
            continue;
        }
        if ($k === 'packs' && is_array($v)) {
            $keep = [];
            foreach ($v as $rk => $rv) {
                if (xeric_rating_rank((string)$rk) <= xeric_rating_rank($eff)) $keep[$rk] = $rv;
            }
            $out[$k] = $keep === [] ? (object)[] : $keep;
            continue;
        }
        $out[$k] = is_array($v) ? xeric_forge_rating_lock($v, $eff) : $v;
    }
    return $out;
}

/** Pass output → a whole world-template.json. */
/**
 * A xeric with nothing invented in it, made without a model.
 *
 * WHY THIS IS NOT A HAND-WRITTEN TEMPLATE. Every rule about what a xeric must
 * contain lives in this file and in the validator, and a literal array typed out
 * somewhere else would be a second copy of those rules that nobody updates. So
 * this calls the same builders every pass falls back to when the model is
 * unreachable: the default concept, the default places, the default person, the
 * same assembler. What comes back is a real xeric, and it is valid for the same
 * reason a forged one is.
 *
 * ONE ROOM AND ONE PERSON, because that is the floor the engine enforces:
 * cast.characters and cast.orbits are both required to be non-empty, and a
 * renderer with no room to put anybody in has nothing to say. Blank means
 * nothing was INVENTED for you, not that the file is empty.
 *
 * @param array $answers whatever the visitor has already said, all of it optional
 */
function xeric_forge_blank(array $answers = [], string $name = '', string $who = ''): array
{
    $answers = array_filter($answers, fn($v) => $v !== '' && $v !== []);

    // The fallbacks, called directly. No endpoint is passed anywhere below, so
    // nothing here can reach for a model even if one is running.
    $concept = xeric_forge_default_concept($answers);
    $orbits  = xeric_forge_orbits($answers, $concept, []);

    // NOWHERE AND NOBODY. The orbits stay because they are not content — they
    // are the two or three groupings a person is added INTO, and an empty list
    // there means the add button has nothing to file anybody under.
    $t = xeric_forge_assemble($answers, $concept, [], ['orbits' => $orbits, 'characters' => []]);

    $t['places'] = [];
    $t['cast']['characters'] = [];
    $t['cast']['generation']['count_hint'] = 0;

    // The user works nowhere in particular yet: a workplace_key naming a place
    // that does not exist is the one thing an empty places list can still get
    // wrong, and the validator checks references whether or not it is a draft.
    $t['user']['occupation'] = ['title' => '', 'workplace_key' => '', 'hours' => ''];

    if (trim($name) !== '') $t['meta']['name'] = xeric_forge_str($name, 'Untitled', 60);
    $t['meta']['description'] = '';

    // THE ONE THING A BLANK WORLD CANNOT INVENT. Every renderer prints it and
    // every character is handed it; left at the fallback, four people you added
    // by hand address you as "you" forever.
    if (trim($who) !== '') $t['user']['name'] = xeric_forge_str($who, '', 60);

    // ------------------------------------------------------------------ blank
    // BLANK MEANS BLANK. This used to call the default-concept table and keep
    // what came back, so "nothing is invented" handed you an empty cast standing
    // in Cutter's Bend: a town of two thousand on a slow brown river, with
    // coffee that has been on the burner since six, three canon rules about when
    // things close, a mood axis about the river being up, and five armed
    // systems, none of which anybody asked for. The people were missing and
    // everything else was somebody else's town.
    //
    // The concept call above still happens because it is what shapes a valid
    // template — the orbits, the scaffolding, the keys every renderer reads. Its
    // CONTENT comes straight back out here. What is left is a name, a person,
    // a clock, and two empty rooms to file people into.
    $t['setting']['locale']      = '';
    $t['setting']['texture']     = [];
    $t['setting']['canon_rules'] = [];
    $t['meta']['themes']         = [];
    $t['user']['location']       = '';
    $t['world_mood']['axis']   = ['positive' => '', 'negative' => '', 'ordinary' => 0];
    $t['world_mood']['motifs'] = ['dark' => [], 'light' => []];

    // NO GOAL, SO NOTHING IS ARMED. `motivation` is not just a line in the
    // bible — xeric_forge_armed() reads it to decide which systems this world
    // runs, and "company" armed five of them. A world with nothing armed is a
    // world where nothing happens unless somebody makes it happen, which is
    // what a xeric that goes on forever with no goal actually is. A goal
    // arrives later, from the player, or from a story overlay laid over it.
    $t['user']['motivation'] = '';
    $t['forge']['armed'] = [];

    // WHENEVER TODAY IS. `setting.era` stays "present day" on purpose: it is
    // what xeric_play_era_year() reads, and "present day" resolves to the real
    // year, so a blank xeric opens on today's date and today's clock rather
    // than on a decade somebody has to notice and correct.
    $t['setting']['era'] = 'present day';

    // It says what it is, so the review page can offer the right things and the
    // shelf can tell a xeric somebody built by hand from one the forge wrote.
    $t['forge']['blank'] = true;
    $t['forge']['notes'] = ['started blank, nothing was invented'];
    return $t;
}

function xeric_forge_assemble(array $answers, array $concept, array $places, array $cast,
                              ?array $endpoint = null, ?callable $onNote = null): array
{
    $wk = xeric_forge_workplace_key($places);
    // NOT lowercased or truncated to 40: a motivation may be a whole sentence
    // in the user's own words (owner 2026-07-30 — "open is better").
    $motivation = xeric_forge_str($answers['motivation'] ?? '', 'company', 400);
    $systems = xeric_forge_armed($motivation, $endpoint, $onNote);
    $pace = xeric_forge_pace((string)($answers['pace'] ?? 'steady'), (string)($answers['around'] ?? ''));
    $grav = xeric_forge_centrality((string)($answers['centrality'] ?? 'ensemble'));
    // Default `none` and that is deliberate: a xeric is a place before it is a
    // plot, and a world nobody asked to have an arc should not quietly acquire
    // one. Every world forged before this question existed answers '' and keeps
    // running at exactly the rate it always did.
    $shape = xeric_forge_shape((string)($answers['story_shape'] ?? ''), $concept, $endpoint, $onNote);

    // Residents: whoever's first week block puts them here. The bible prints
    // them as the people you expect to find in the room.
    $byPlace = [];
    foreach ($cast['characters'] as $c) {
        $w = (array)($c['week'][0] ?? []);
        $k = (string)($w['where'] ?? '');
        if ($k !== '') $byPlace[$k][] = (string)$c['handle'];
    }
    foreach ($places as &$p) {
        $k = (string)$p['key'];
        $p['residents'] = array_slice($byPlace[$k] ?? [], 0, 3);
    }
    unset($p);

    $t = [
        'template_version' => 1,

        'meta' => [
            'name'        => (string)$concept['meta']['name'],
            'description' => (string)$concept['meta']['description'],
            'author'      => 'forge',
            'rating'      => xeric_forge_rating($answers),
            'themes'      => array_values((array)$concept['meta']['themes']),
            'language'    => 'en',
        ],

        'user' => [
            'name' => xeric_forge_str($answers['name'] ?? '', 'you', 60),
            // Not asked in the slice. FORGE.md's step 2 asks name/job/hours and
            // nothing else, so pronouns and timezone are defaulted here and are
            // the first two things the review page should offer to fix.
            'pronouns' => xeric_forge_str($answers['pronouns'] ?? '', 'they/them', 30),
            'timezone' => xeric_forge_timezone($answers),
            'location' => (string)$concept['setting']['locale'],
            'occupation' => [
                'title' => xeric_forge_str($answers['job'] ?? '', 'work', 120),
                'workplace_key' => $wk,
                'hours' => xeric_forge_str($answers['hours'] ?? '', 'no fixed hours', 80),
            ],
            'quiet_hours' => xeric_forge_quiet_hours((string)($answers['around'] ?? '')),
            'centrality' => $grav['centrality'],
            'centrality_framing' => $grav['framing'],
            'centrality_heading' => $grav['heading'],
            'motivation' => $motivation,
            'goals' => [],
        ],

        'setting' => [
            'locale'      => (string)$concept['setting']['locale'],
            'era'         => (string)$concept['setting']['era'],
            'texture'     => array_values((array)$concept['setting']['texture']),
            'canon_rules' => array_values((array)$concept['setting']['canon_rules']),
            'bible_prose' => ['forge' => 'generate'],
            'travel'      => xeric_forge_travel($answers),
        ],

        'places' => array_values($places),

        'cast' => [
            'generation' => [
                'mode' => 'forge',
                'count_hint' => count($cast['characters']),
                // NOT "adults only". That constraint was free text handed to a
                // model as a request, it enforced nothing, and what it asked for
                // was wrong anyway: a town with no children in it is not a town.
                // The floor that IS enforced is xeric_forge_age_floor(), and it
                // gates sex and nothing else.
                'constraints' => ['people of every age — children and teenagers are ordinary characters',
                                  'at least one person where you work'],
            ],
            'orbits' => array_values($cast['orbits']),
            'circles' => [],
            'characters' => array_values($cast['characters']),
            'fixtures' => [],
            'special_roles' => [],
        ],

        'world_mood' => [
            'axis'    => $concept['world_mood']['axis'],
            'range'   => $concept['world_mood']['range'],
            'motifs'  => $concept['world_mood']['motifs'],
            'drivers' => [
                ['on' => 'intimacy', 'delta' => 1],
                ['on' => 'conflict', 'delta' => 2],
                ['on' => 'shared_meal', 'delta' => -2],
                ['on' => 'grace_place_visit', 'delta' => -2],
            ],
            'reversion' => 'mean-toward-ordinary',
            'narrator_hand' => ['enabled' => true, 'cap' => 2, 'invariant' => 'pushes harder when ordinary than extreme'],
        ],

        'events' => [
            'day_events' => true,
            'night_events' => true,
            'quiet_hours_respected' => true,
            // density normalised per VISIT — see xeric_forge_pace()
            'pace' => $pace['pace'],
            'sweep_chance' => $pace['chance'],
            'expected_gap_hours' => $pace['gap_hours'],
            // The RHYTHM that rate is walked at — a key from xeric_story_shapes()
            // or an invented shape inline. 'none' is a shape: flat at 0.5, which
            // is ×1.0 exactly, which is sweep_chance untouched forever.
            'story_shape' => $shape,
            // how often an event is about the user — see xeric_forge_centrality()
            'user_concern' => $grav['concern'],
            'proactive_reach' => $grav['reach'],
            'albums' => [
                'beat_photos' => true,
                'extra_frames' => ['quiet' => 3, 'base' => 5, 'messy' => 6, 'pools_by_rating' => true],
                'postcards' => ['window' => '07:00-14:00', 'no_user_rival_in_frame' => true],
            ],
            'publish_gate' => 'all_photos_terminal',
        ],

        'proactive' => [
            'pings' => [
                'enabled' => true,
                'ladder' => ['aftermath' => 0.8, 'mid_event' => 0.5, 'missing_user_3d' => 0.65,
                             'pre_event' => 0.4, 'dream' => 0.25, 'diary' => 0.3, 'undercurrent' => 0.15],
                'caps' => ['per_character_hours' => 24, 'cast_per_day' => 2],
                'surprise_photo_pct' => 15,
            ],
            'double_texts' => ['pct' => 12, 'delay_minutes' => [3, 20]],
            'dreams' => ['window' => '01:00-06:00', 'owns_undercurrent_until' => '13:00'],
            'duets' => ['enabled' => true, 'offscreen_per_night' => 1],
        ],

        // Provenance. Not in WORLD_TEMPLATE.md, and load-bearing for the review
        // step: a reroll needs the answers that produced this, and the UI needs
        // to say which systems the motivation armed.
        'forge' => [
            'built_at' => gmdate('c'),
            'answers'  => $answers,
            // Which naming register this world drew from — the answers
            // re-derive it (xeric_forge_naming is deterministic on them), so
            // this is a record for the reader, not a second source of truth.
            'naming'   => ($nm = xeric_forge_naming($answers))['key'] !== ''
                            ? ['register' => $nm['key'], 'label' => $nm['label']] : null,
            'armed'    => $systems['armed'],
            'disarmed' => $systems['disarmed'],
            // How the armed set was arrived at, and — when a free-text
            // motivation was resolved by the model — its own one-line reason.
            // The review UI shows this: "you asked for X, so the world runs Y"
            // is only honest if it can say who decided and why.
            'systems_source' => $systems['source'],
            'systems_why'    => $systems['why'] ?? null,
            // A rating a PERSON chose (owner, 2026-08-02). Every door into the
            // forge now writes the rating field before building, so a world
            // assembled here carries a human answer and says so. Worlds forged
            // before this marker existed get a one-time confirmation in the
            // play view instead — the gap-era worlds whose rating a model
            // filled are exactly the ones this flag exists to tell apart.
            'rating_confirmed' => true,
        ],
    ];

    // The floor runs at ASSEMBLY, on the whole cast, because exclusion from the
    // desire economy is a property of the pool and not something a per-person
    // prompt can be trusted with. It is the last thing done to the template so
    // that nothing assembled after it can put an edge back.
    return xeric_forge_age_floor($t, $onNote);
}

/**
 * Make a template legal without throwing anything away that is still valid.
 *
 * Called when the assembled world fails validation. Every fix here is a
 * dangling reference being dropped or a required field being filled — never a
 * silent rewrite of something the model said.
 */
function xeric_forge_repair(array $t, ?callable $onNote = null): array
{
    $note = fn(string $m) => xeric_forge_note($onNote, 'repair: ' . $m);

    if (trim((string)($t['meta']['name'] ?? '')) === '') { $t['meta']['name'] = 'the world'; $note('meta.name was empty'); }
    if (!in_array((string)($t['meta']['rating'] ?? ''), xeric_ratings(), true)) { $t['meta']['rating'] = 'sfw'; $note('meta.rating was not a legal rating'); }
    try { new DateTimeZone((string)($t['user']['timezone'] ?? '')); }
    catch (Throwable $e) { $t['user']['timezone'] = 'UTC'; $note('user.timezone was not a timezone'); }

    // places
    $places = [];
    $seen = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        if (!is_array($p)) continue;
        $k = (string)($p['key'] ?? '');
        if ($k === '' || isset($seen[$k])) { $note('dropped a place with a missing or duplicate key'); continue; }
        $seen[$k] = true;
        $places[] = $p;
    }
    if ($places === []) { $places = [xeric_forge_workplace_place((array)($t['forge']['answers'] ?? []))]; $note('there were no usable places'); }
    $t['places'] = array_values($places);
    $placeKeys = [];
    foreach ($t['places'] as $p) $placeKeys[(string)$p['key']] = true;

    // orbits
    $orbits = [];
    $seen = [];
    foreach ((array)($t['cast']['orbits'] ?? []) as $o) {
        $k = (string)($o['key'] ?? '');
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $orbits[] = $o;
    }
    if ($orbits === []) { $orbits = [['key' => 'outside', 'label' => 'everybody']]; $note('there were no orbits'); }
    $t['cast']['orbits'] = $orbits;
    $first = (string)$orbits[0]['key'];
    $orbitKeys = [];
    foreach ($orbits as $o) $orbitKeys[(string)$o['key']] = true;

    // characters
    $chars = [];
    $seen = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || isset($seen[$h])) { $note('dropped a character with a missing or duplicate handle'); continue; }
        $seen[$h] = true;
        if (!isset($orbitKeys[(string)($c['orbit'] ?? '')])) { $c['orbit'] = $first; $note("$h was in an orbit nobody declared"); }
        if (!is_int($c['age'] ?? null)) {
            // Required, and the only input the minor derivation has. A
            // character whose age cannot be read IS a minor, so the repair
            // writes the oldest age that still is one and says so out loud —
            // inventing an adult here would unlock everything on their behalf,
            // which is the one direction this field is not allowed to move.
            $c['age'] = XERIC_ADULT_AGE - 1;
            $note("$h had no readable age — a minor until somebody sets one");
        }
        $week = [];
        foreach ((array)($c['week'] ?? []) as $w) {
            if (!is_array($w)) continue;
            $where = (string)($w['where'] ?? '');
            if ($where !== '' && !isset($placeKeys[$where])) { $note("$h had a shift at a place that does not exist"); continue; }
            $w['days'] = array_values(array_filter(array_map('intval', (array)($w['days'] ?? [])), fn($d) => $d >= 0 && $d <= 6));
            if ($w['days'] === []) $w['days'] = [1, 2, 3, 4, 5];
            foreach (['from', 'to'] as $f) {
                if (isset($w[$f]) && !xeric_world_is_hhmm((string)$w[$f])) unset($w[$f]);
            }
            $week[] = $w;
        }
        $c['week'] = $week;
        $chars[] = $c;
    }
    if ($chars === []) {
        $answers = (array)($t['forge']['answers'] ?? []);
        $ctx = ['orbit' => $first, 'orbit_label' => 'here', 'places' => $t['places'],
                'place_keys' => array_keys($placeKeys), 'workplace' => array_key_first($placeKeys),
                'public' => array_key_first($placeKeys), 'index' => 0, 'user' => (string)($t['user']['name'] ?? 'you')];
        $chars = [xeric_forge_default_character($ctx, [])];
        $note('there were no usable characters');
    }
    $t['cast']['characters'] = array_values($chars);
    $people = [];
    foreach ($t['cast']['characters'] as $c) $people[(string)$c['handle']] = true;

    // residents + workplace binding, now that we know who exists
    foreach ($t['places'] as &$p) {
        $p['residents'] = array_values(array_filter(array_map('strval', (array)($p['residents'] ?? [])), fn($r) => isset($people[$r])));
    }
    unset($p);
    $wk = (string)($t['user']['occupation']['workplace_key'] ?? '');
    if ($wk !== '' && !isset($placeKeys[$wk])) {
        $t['user']['occupation']['workplace_key'] = xeric_forge_workplace_key($t['places']) ?? array_key_first($placeKeys);
        $note('user.occupation.workplace_key pointed at nothing');
    }

    // Repair can drop a character and can invent one, so the floor is re-run on
    // what came out: a hand-written stand-in must arrive armed like everybody
    // else, and a dropped adult must not leave a seed pointing at nobody.
    return xeric_forge_age_floor($t, $onNote);
}

// ---------------------------------------------------------------------------
// Pass 8 — seed history. What makes minute one land.
// ---------------------------------------------------------------------------

/**
 * Whose story is this, if it is not the user's?
 *
 * A story needs gravity somewhere. When the user declares themselves a side
 * character — or one of an ensemble — the centre does not disappear, it moves.
 * Without somebody standing in it, a side-character world is ambient noise with
 * no plot: things happen, nothing is *going* anywhere, and the user is watching
 * a screensaver.
 *
 * So the forge names a protagonist from the cast and gives them something they
 * are driving toward. Sweeps weight them into events; the world's motion
 * becomes their motion, and the user's part is to be near it.
 *
 * Runs only when the user is NOT the main character. Model-chosen (it has read
 * the whole cast by now), validated against real handles, with a deterministic
 * fallback so this can never be the thing that fails a build.
 */
/**
 * Which of this character's private fields the arc is quoting, or '' if none.
 *
 * WHOSE STORY THIS IS is a section of the SHARED bible. The framing reaches
 * everybody the wall leaves it standing for, and the arc reaches everybody who
 * can read `drives`. So the arc may only say what the town could already say
 * out loud — and the one thing it must never be is the pull, which is the field
 * the privacy baseline exists to hide and the field the model was shown while
 * being asked this question.
 */
function xeric_forge_arc_quotes_interior(array $character, string $said): string
{
    $hay = xeric_wall_words($said);
    if ($hay === []) return '';

    $private = [
        'drives.pull' => [(string)($character['drives']['pull'] ?? '')],
        'drives.fear' => [(string)($character['drives']['fear'] ?? '')],
        'secrets'     => array_map('strval', (array)($character['secrets'] ?? [])),
        'psyche'      => array_map('strval', (array)($character['psyche'] ?? [])),
    ];
    foreach ($private as $path => $strings) {
        foreach ($strings as $s) {
            if (trim($s) === '') continue;
            if (xeric_wall_quotes($hay, xeric_wall_words($s))) return $path;
        }
    }
    return '';
}

function xeric_forge_pass_protagonist(array $template, array $endpoint, ?callable $onNote = null): ?array
{
    $centrality = (string)($template['user']['centrality'] ?? 'ensemble');
    if ($centrality === 'main') return null;   // the user is standing in it

    $chars = (array)($template['cast']['characters'] ?? []);
    if (count($chars) < 2) return null;
    $byHandle = [];
    foreach ($chars as $c) $byHandle[(string)$c['handle']] = $c;

    $pick = null;
    if ($endpoint !== null) {
        try {
            $lines = '';
            foreach ($chars as $c) {
                $lines .= '- ' . $c['handle'] . ' — ' . ($c['display_name'] ?? '') . ' — '
                    . mb_substr((string)($c['one_line'] ?? ''), 0, 80)
                    . ' — wants: ' . mb_substr((string)($c['drives']['pull'] ?? ''), 0, 60) . "\n";
            }
            $out = xeric_forge_ask($endpoint, 'protagonist', [
                ['role' => 'system', 'content' =>
                    "You pick the protagonist of a world.\n\n"
                    . "The person playing this world is NOT the main character — they are "
                    . ($centrality === 'side' ? "on the edge of it, someone who hears things"
                                              : "one of an ensemble") . ". "
                    . "So the story belongs to somebody in the cast. Choose the one whose situation "
                    . "is most likely to MOVE — who has something to lose, or something coming.\n\n"
                    . "Reply ONLY JSON: {\"handle\":\"…\",\"arc\":\"what they are driving toward, one sentence\","
                    . "\"pressure\":\"what is forcing it now, one short phrase\"}"],
                ['role' => 'user', 'content' =>
                    "World: " . (string)($template['meta']['name'] ?? '') . " — "
                    . (string)($template['meta']['description'] ?? '') . "\nCast:\n{$lines}"],
            ], ['temperature' => 0.5, 'max_tokens' => 250]);
            $h = xeric_forge_pick_key($out['handle'] ?? '', array_keys($byHandle), '');
            if ($h !== '') {
                $arc   = xeric_forge_str($out['arc'] ?? '', '', 240);
                $press = xeric_forge_str($out['pressure'] ?? '', '', 120);
                // The model has just read every pull in the cast, and a model
                // asked "what is this person driving toward" answers with the
                // pull it was shown — which is the private field, said again in
                // a section the whole town reads. Refusing costs a paragraph;
                // keeping it costs the wall.
                $quoted = xeric_forge_arc_quotes_interior($byHandle[$h], $arc . ' ' . $press);
                if ($quoted !== '') {
                    $onNote && $onNote('protagonist: the model\'s arc repeated ' . $h . '\'s ' . $quoted
                        . ' — refused, writing one from what the town already says');
                } else {
                    $pick = ['handle' => $h, 'arc' => $arc, 'pressure' => $press, 'source' => 'model'];
                }
            }
        } catch (Throwable $e) {
            $onNote && $onNote('protagonist: model declined (' . $e->getMessage() . ') — choosing by hand');
        }
    }
    if ($pick === null) {
        // Deterministic: whoever the world already leans on — the first
        // character with a stated pull, else simply the first.
        $h = (string)$chars[0]['handle'];
        foreach ($chars as $c) {
            if (trim((string)($c['drives']['pull'] ?? '')) !== '') { $h = (string)$c['handle']; break; }
        }
        // The arc is public. It is printed in WHOSE STORY THIS IS, which is a
        // section of the shared bible, so it may only say what the cast could
        // all plausibly sense about this person. It used to be `drives.pull`
        // copied byte for byte — the unspoken thing, the one field the privacy
        // baseline exists to hide, handed to everybody who can read the page.
        // one_line is what the town already says out loud, so that is the seed.
        $one = xeric_forge_str($byHandle[$h]['one_line'] ?? '', '', 180);
        $pick = ['handle' => $h,
                 'arc' => $one !== ''
                     ? rtrim($one, ' .') . ', and that is about to stop holding.'
                     : 'to come out the far side of this with something still theirs.',
                 'pressure' => 'time, and the way this place watches',
                 'source' => 'fallback'];
    }
    $pick['display_name'] = (string)($byHandle[$pick['handle']]['display_name'] ?? $pick['handle']);
    $onNote && $onNote('protagonist: this is ' . $pick['display_name'] . "'s story — " . mb_substr($pick['arc'], 0, 70));
    return $pick;
}

/**
 * PASS 7 — knowledge walls. The safety-critical pass.
 *
 * Two layers, and the order matters:
 *
 *  1. THE PRIVACY BASELINE, deterministic and unconditional. Without it every
 *     character's system prompt contains every other character's sore spot,
 *     self-soothe habit and unspoken pull — while the same prompt's rules say
 *     "do not narrate anybody else's insides". The bible wins that argument on
 *     token count, so the cast reads each other's minds and the world feels
 *     like one narrator wearing hats. One wall per orbit hides
 *     cast_dossiers/drives/secrets from everyone in it.
 *
 *     VERIFIED (2026-07-30) before this pass was written: a walled speaker
 *     still knows THEMSELVES, because the engine renders the speaker's own
 *     interior from a separate YOU ARE block rather than from the bible's
 *     dossier section. The narrator (viewer=null) matches no orbit selector,
 *     so it keeps full canon.
 *
 *  2. PROTECTED RELATIONSHIPS, model-proposed. A daughter who must not learn
 *     what her father does on Thursdays; an employer who must not know about
 *     the second job. These get a wall AND a special_role with own_bible, so
 *     they read a smaller, different world.
 *
 * Everything a model proposes is validated against the real cast before it is
 * kept, and every wall carries `explain` — one plain sentence the UI shows the
 * user, because a hallucinated wall is a real person's secret told to the
 * wrong character, and the user is the only one who can catch that.
 *
 * A dead or dumb model costs you layer 2 only. Layer 1 always ships.
 */
function xeric_forge_pass_walls(array $template, array $endpoint, ?callable $onNote = null): array
{
    $chars = (array)($template['cast']['characters'] ?? []);
    $orbits = (array)($template['cast']['orbits'] ?? []);
    if ($chars === []) return ['knowledge_walls' => [], 'special_roles' => []];

    // ---- layer 1: privacy, unconditional -----------------------------------
    $walls = [];
    foreach ($orbits as $o) {
        $key = (string)($o['key'] ?? '');
        if ($key === '') continue;
        $walls[] = [
            'key' => 'privacy_' . $key,
            'audience' => ['orbit' => $key],
            // Deliberately NOT `protagonist`. The arc and the pressure ride
            // `drives`, so hiding drives already takes them; hiding the path
            // itself would take the framing too, and the framing is commons —
            // the cast is meant to feel the world leaning somebody's way
            // without being told what they are after. `protagonist` belongs on
            // the walls that are supposed to leave nothing standing: the
            // protected relationship below, and the own_bible floor.
            'hidden' => ['cast_dossiers', 'drives', 'secrets'],
            'shown_as' => 'You know these people the way anyone knows the people around them: '
                . 'what they do, how they seem, what they choose to show you. What is underneath '
                . 'is theirs, and you can only guess at it.',
            'explain' => 'Nobody in ' . ((string)($o['label'] ?? $key)) . ' can read anyone else\'s private interior.',
            'source' => 'baseline',
        ];
    }
    $onNote && $onNote('walls: privacy baseline for ' . count($walls) . ' orbit(s)');

    // ---- layer 2: protected relationships, proposed -------------------------
    $roles = [];
    if ($endpoint !== null && count($chars) > 1) {
        try {
            $castLines = '';
            foreach ($chars as $c) {
                $castLines .= '- ' . $c['handle'] . ' — ' . ($c['display_name'] ?? '')
                    . ' — ' . mb_substr((string)($c['one_line'] ?? ''), 0, 90) . "\n";
            }
            $motive = mb_substr((string)($template['user']['motivation'] ?? ''), 0, 200);
            $uname = (string)($template['user']['name'] ?? 'the user');
            $out = xeric_forge_ask($endpoint, 'walls', [
                ['role' => 'system', 'content' =>
                    "You design the information walls of a life-simulation world.\n\n"
                    . "A PROTECTED PERSON is someone who must NOT learn something the rest of the world knows — "
                    . "a parent kept in the dark, a spouse who does not know, an employer, a child. They exist to "
                    . "create tension, not cruelty.\n\n"
                    . "Return 0-2 of them. If nobody in this cast plausibly needs protecting, return an empty list — "
                    . "that is a good answer, not a failure.\n\n"
                    . "Reply ONLY JSON: {\"protected\":[{\"handle\":\"…\",\"role\":\"one word: child|parent|spouse|"
                    . "employer|friend|sibling\",\"must_not_know\":\"one short phrase\",\"why\":\"one sentence\"}]}"],
                ['role' => 'user', 'content' =>
                    "World: " . (string)($template['meta']['name'] ?? '') . " — "
                    . (string)($template['meta']['description'] ?? '') . "\n"
                    . "The person at the center: {$uname}" . ($motive !== '' ? ", who wants: {$motive}" : '') . "\n"
                    . "Cast:\n{$castLines}\nWho must be kept in the dark, if anyone?"],
            ], ['temperature' => 0.4, 'max_tokens' => 400]);

            $byHandle = [];
            foreach ($chars as $c) $byHandle[(string)$c['handle']] = $c;
            foreach ((array)($out['protected'] ?? []) as $p) {
                if (!is_array($p)) continue;
                $h = xeric_forge_pick_key($p['handle'] ?? '', array_keys($byHandle), '');
                if ($h === '' || isset($roles[$h])) continue;   // must be a real, unclaimed character
                $role = preg_replace('/[^a-z]/', '', strtolower((string)($p['role'] ?? 'family'))) ?: 'family';
                $what = xeric_forge_str($p['must_not_know'] ?? '', 'what really goes on here', 120);
                $why  = xeric_forge_str($p['why'] ?? '', '', 200);
                $wallKey = 'protects_' . $h;
                $walls[] = [
                    'key' => $wallKey,
                    'audience' => ['role' => $role],
                    'hidden' => ['cast_dossiers', 'drives', 'secrets', 'economies', 'mystery', 'protagonist'],
                    'shown_as' => 'These are ordinary people to you, doing ordinary things. '
                        . 'As far as you know, nothing is going on that anyone would need to hide.',
                    'explain' => ($byHandle[$h]['display_name'] ?? $h) . ' must never learn: ' . $what
                        . ($why !== '' ? ' (' . $why . ')' : ''),
                    'source' => 'model',
                ];
                $roles[$h] = [
                    'role' => $role,
                    'character' => $h,
                    'walls' => [$wallKey],
                    'own_bible' => true,
                    'must_not_know' => $what,
                ];
                $onNote && $onNote('walls: ' . ($byHandle[$h]['display_name'] ?? $h) . ' is protected — must not learn ' . $what);
            }
        } catch (Throwable $e) {
            $onNote && $onNote('walls: no protected relationships proposed (' . $e->getMessage() . ') — privacy baseline still applies');
        }
    }

    return ['knowledge_walls' => $walls, 'special_roles' => array_values($roles)];
}

/**
 * Does this sentence carry the thing a protected person must not learn?
 *
 * Deterministic and deliberately blunt. A seed memory is one sentence written
 * by a small model that was just told what not to say, and a small model that
 * ignores that instruction does not paraphrase — it reuses the words. So the
 * test is lexical: two content words shared with the secret (or the only one
 * there is) and the sentence does not ship.
 *
 * It over-refuses, and that is the correct direction. The alternative to
 * dropping a memory that might be about the secret is writing the secret into
 * the system prompt of the one person the wall exists to keep it from.
 */
function xeric_forge_trips_wall(string $text, string $mustNotKnow): bool
{
    $words = static function (string $s): array {
        $stop = ['about', 'after', 'again', 'been', 'being', 'could', 'does', 'doing', 'from', 'have',
                 'into', 'just', 'more', 'much', 'other', 'over', 'said', 'same', 'some', 'still',
                 'than', 'that', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'very',
                 'were', 'what', 'when', 'which', 'while', 'with', 'would', 'your'];
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($s)) ?? '';
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
            // a plural is the same secret as its singular
            if (mb_strlen($w) >= 4 && !in_array($w, $stop, true)) $out[rtrim($w, 's')] = true;
        }
        return $out;
    };
    $secret = $words($mustNotKnow);
    if ($secret === []) return false;               // nothing said, nothing to protect
    $shared = count(array_intersect_key($words($text), $secret));
    return $shared >= (count($secret) > 1 ? 2 : 1);
}

// ---------------------------------------------------------------------------
// Homes — everybody lives somewhere (owner, 2026-08-02)
// ---------------------------------------------------------------------------

/**
 * The homes pass: a dwelling for every character, some of them shared.
 *
 * WHY THIS EXISTS. who_is_where() resolves off-shift hours to a character's
 * home — but only if the world gave them one, and until this pass no forged
 * world ever did. A cast of morning shifts made a ghost town of every evening.
 *
 * ONE MODEL CALL for the whole cast, asking for HOUSEHOLDS rather than houses:
 * who lives with whom is the interesting answer (a marriage, roommates, a kid
 * at a parent's — a shared roof is a relationship the cast pass never has to
 * state), and the dwelling's name falls out of it. Everything is validated
 * against the real cast; anybody the model missed gets a solo place, so the
 * pass cannot leave a person homeless however badly the call goes. The
 * deterministic fallback (xeric_forge_default_homes) is the same shape with
 * nobody sharing.
 *
 * Home names are derived from their residents, so they ride whatever naming
 * register the cast already draws from — no second register plumbing here.
 */
function xeric_forge_pass_homes(array $t, array $endpoint, ?callable $onNote = null): array
{
    $chars = (array)($t['cast']['characters'] ?? []);
    if ($chars === []) return [];
    if ($endpoint === []) return xeric_forge_default_homes($t);

    // IDEMPOTENT BY EXCLUSION: anyone who already has a home — a hand-declared
    // one, or a rerun of this pass — is simply not on the roster. The validator
    // holds "one person, one home" with teeth, so this pass must be incapable
    // of arguing with it.
    $housed0 = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        if ((string)($p['kind'] ?? '') !== 'home') continue;
        foreach ((array)($p['residents'] ?? []) as $r) $housed0[(string)$r] = true;
    }

    $byHandle = [];
    $byName   = [];
    $roster   = [];
    foreach ($chars as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || isset($housed0[$h])) continue;
        $byHandle[$h] = (array)$c;
        $byName[mb_strtolower(trim((string)($c['display_name'] ?? '')))] = $h;
        $roster[] = $h . ' · ' . (string)($c['display_name'] ?? $h) . ' · ' . (int)($c['age'] ?? 0)
            . (xeric_is_minor((array)$c) ? ' (a child — never lives alone)' : '');
    }
    if ($byHandle === []) return [];                 // everyone already lives somewhere

    $placeNames = array_map(fn($p) => (string)($p['name'] ?? ''), (array)($t['places'] ?? []));

    $raw = xeric_forge_ask($endpoint, 'homes', [
        ['role' => 'system', 'content' =>
            'You decide who lives with whom in a small fictional community, and what their homes are '
            . 'called. Households of one to three people. A child always lives with an adult. Home names '
            . 'are plain and local — a surname, a street, a room over a shop — never grand. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "The world: " . (string)($t['meta']['name'] ?? '') . ' — ' . (string)($t['meta']['description'] ?? '')
            . "\nWhere and when: " . (string)($t['setting']['locale'] ?? '') . ', ' . (string)($t['setting']['era'] ?? '')
            . "\nThe people (handle · name · age):\n" . implode("\n", $roster)
            . "\nExisting place names, do not reuse: " . implode('; ', array_filter($placeNames))
            . "\n\nReply as {\"households\":[{\"who\":[\"handle\",…],\"name\":\"what locals call the home\","
            . "\"desc\":\"one concrete sentence about the inside\"}]}. Every person in exactly one household."],
    ], ['temperature' => 0.8, 'max_tokens' => 1200], $onNote);

    // Validation is the pass. The model proposes; the cast list disposes.
    $taken = [];
    foreach ((array)($t['places'] ?? []) as $p) $taken[(string)($p['key'] ?? '')] = true;

    $housed = [];
    $homes  = [];
    foreach ((array)($raw['households'] ?? []) as $row) {
        $who = [];
        foreach ((array)($row['who'] ?? []) as $w) {
            $w = trim((string)$w);
            $h = isset($byHandle[$w]) ? $w : ($byName[mb_strtolower($w)] ?? '');
            if ($h === '' || isset($housed[$h])) continue;   // unknown, or already housed: dropped
            $who[] = $h;
            $housed[$h] = true;
        }
        $who = array_slice($who, 0, 3);
        if ($who === []) continue;
        $first = explode(' ', (string)($byHandle[$who[0]]['display_name'] ?? $who[0]))[0];
        $name  = xeric_forge_str($row['name'] ?? '', $first . "'s place", 60);
        $key   = xeric_forge_key($name, $taken);
        $taken[$key] = true;
        $homes[] = [
            'key' => $key, 'name' => $name, 'kind' => 'home',
            'description' => xeric_forge_str($row['desc'] ?? '', '', 200),
            'aliases' => xeric_forge_aliases($name),
            'residents' => $who,
        ];
    }

    // Nobody sleeps outside. Anyone the model forgot gets a solo place — and a
    // forgotten CHILD gets noted out loud, because a child living alone is a
    // thing only the person reviewing this world can decide is right.
    foreach ($byHandle as $h => $c) {
        if (isset($housed[$h])) continue;
        $first = explode(' ', (string)($c['display_name'] ?? $h))[0];
        $name  = $first . "'s place";
        $key   = xeric_forge_key($name, $taken);
        $taken[$key] = true;
        $homes[] = ['key' => $key, 'name' => $name, 'kind' => 'home',
                    'description' => '', 'aliases' => xeric_forge_aliases($name), 'residents' => [$h]];
        if ($onNote && xeric_is_minor((array)$c)) {
            $onNote('homes: ' . (string)($c['display_name'] ?? $h) . ' is a child living alone — worth a look in review');
        }
    }
    return $homes;
}

/** Every character solo, named after themselves. Launchable, never wrong, never surprising. */
function xeric_forge_default_homes(array $t): array
{
    $taken = [];
    foreach ((array)($t['places'] ?? []) as $p) $taken[(string)($p['key'] ?? '')] = true;
    $homes = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $first = explode(' ', (string)($c['display_name'] ?? $h))[0];
        $name  = $first . "'s place";
        $key   = xeric_forge_key($name, $taken);
        $taken[$key] = true;
        $homes[] = ['key' => $key, 'name' => $name, 'kind' => 'home',
                    'description' => '', 'aliases' => xeric_forge_aliases($name), 'residents' => [$h]];
    }
    return $homes;
}

/**
 * Who the world opens onto. Deterministic — no model call, because the answer
 * is derivable and a hallucinated opening scene would be validated away anyway.
 *
 * The ladder: the person who shares the most working hours with the user at
 * their own workplace (they were always going to meet first), else the
 * protagonist (it is their story), else the first of the cast. Whoever it is,
 * the validator holds the guarantee that matters: first_contact can never be
 * OUT of the story.
 */
function xeric_forge_first_contact(array $t, ?callable $onNote = null): ?string
{
    $chars = (array)($t['cast']['characters'] ?? []);
    if ($chars === []) return null;

    $wkey = (string)($t['user']['occupation']['workplace_key'] ?? '');
    if ($wkey !== '') {
        $best = null; $bestMins = 0;
        foreach ($chars as $c) {
            if (!empty($c['out'])) continue;
            $mins = 0;
            foreach ((array)($c['week'] ?? []) as $w) {
                if ((string)($w['where'] ?? '') !== $wkey) continue;
                $from = xeric_world_minutes((string)($w['from'] ?? '')) ?? 0;
                $to   = xeric_world_minutes((string)($w['to'] ?? ''))   ?? 0;
                $span = $to > $from ? $to - $from : (1440 - $from) + $to;   // wraps count too
                $mins += $span * max(1, count((array)($w['days'] ?? [])));
            }
            if ($mins > $bestMins) { $bestMins = $mins; $best = (string)$c['handle']; }
        }
        if ($best !== null) {
            if ($onNote) $onNote('first contact: ' . $best . ' — most hours beside you at work');
            return $best;
        }
    }

    $star = (string)($t['cast']['protagonist']['handle'] ?? '');
    foreach ($chars as $c) {
        if ($star !== '' && (string)($c['handle'] ?? '') === $star && empty($c['out'])) {
            if ($onNote) $onNote('first contact: ' . $star . ' — the protagonist');
            return $star;
        }
    }
    foreach ($chars as $c) {
        if (empty($c['out']) && (string)($c['handle'] ?? '') !== '') {
            if ($onNote) $onNote('first contact: ' . (string)$c['handle'] . ' — first of the cast');
            return (string)$c['handle'];
        }
    }
    return null;
}

/**
 * The past this world already had.
 *
 * Deliberately NOT part of world-template.json: the template is the world, this
 * is what has happened in it. The caller writes events into the events table
 * and memories into the memories table (engine/state.php) when the world is
 * launched, which is also why the shapes here match those two functions.
 *
 * Runs AFTER the walls pass, and reads them. A protected character's memories
 * go straight into the prompt that character speaks from, so a seeded memory
 * that happens to mention the thing they must not know hands them the secret on
 * day one and nothing downstream can take it back. They are written with the
 * exclusion stated, and then checked against it and DROPPED — never rewritten,
 * because a rewrite of a sentence about the secret is still about the secret.
 *
 * @return array{events:array,memories:array}
 */
function xeric_forge_pass_seed(array $template, array $endpoint, ?callable $onNote = null): array
{
    $chars = (array)($template['cast']['characters'] ?? []);
    $places = (array)($template['places'] ?? []);
    if ($chars === []) return ['events' => [], 'memories' => []];

    $protected = [];
    foreach ((array)($template['cast']['special_roles'] ?? []) as $r) {
        $h = (string)($r['character'] ?? '');
        $what = trim((string)($r['must_not_know'] ?? ''));
        if ($h !== '' && $what !== '') $protected[$h] = $what;
    }

    $user = xeric_forge_str($template['user']['name'] ?? '', 'you', 60);
    $world = xeric_forge_str($template['meta']['name'] ?? '', 'here', 60);
    $handles = [];
    $castLines = [];
    foreach ($chars as $c) {
        $handles[(string)$c['handle']] = true;
        $castLines[] = '- ' . $c['handle'] . ' — ' . $c['display_name'] . ' — ' . (string)($c['one_line'] ?? '');
    }
    $placeLines = [];
    $placeKeys = [];
    foreach ($places as $p) {
        $placeKeys[(string)$p['key']] = true;
        $placeLines[] = '- ' . $p['key'] . ' — ' . $p['name'];
    }

    xeric_forge_note($onNote, 'seed: backfilling what already happened');

    $default = xeric_forge_default_seed($template);

    $events = xeric_forge_attempt('seed history', function () use ($endpoint, $user, $world, $castLines, $placeLines, $handles, $placeKeys, $onNote) {
        $msgs = [
            ['role' => 'system', 'content' =>
                'You write the recent past of a story world — small, concrete things that already happened. '
                . 'Reply with ONE JSON object and nothing else.'],
            ['role' => 'user', 'content' =>
                "World: $world. The person at the centre is $user.\n\nPeople:\n" . implode("\n", $castLines)
                . "\n\nPlaces:\n" . implode("\n", $placeLines)
                . "\n\nWrite 4 things that happened in the last six weeks. Ordinary, specific, unfinished — "
                . "at least two of them should still be owed, unsaid or unresolved.\n"
                . "{\n  \"events\": [\n    { \"title\": \"5 words\", \"days_ago\": 12, \"place\": \"a key from the list\", "
                . "\"who\": [\"handles from the list\"], \"prose\": \"2-3 past-tense sentences\" }\n  ]\n}\n"
                . "No prose outside the JSON."],
        ];
        $raw = xeric_forge_ask($endpoint, 'seed_events', $msgs, ['temperature' => 1.0, 'max_tokens' => 1100], $onNote);
        $out = [];
        foreach ((array)($raw['events'] ?? []) as $e) {
            if (!is_array($e)) continue;
            $title = xeric_forge_str($e['title'] ?? '', '', 120);
            $prose = xeric_forge_str($e['prose'] ?? '', '', 800);
            if ($title === '' || $prose === '') continue;
            // Models answer `who` with display names as often as handles, and a
            // silently-empty participant list is a seed event nobody owns.
            $who = [];
            foreach (xeric_forge_list($e['who'] ?? [], 6, 60) as $h) {
                $match = xeric_forge_pick_key($h, array_keys($handles), '');
                if ($match !== '' && !in_array($match, $who, true)) $who[] = $match;
            }
            // The place resolves the way participants always have: through the
            // tolerant matcher, because the model answers "Salt & Silt" or a
            // near-key as readily as the key itself — and did so reliably the
            // moment the list grew past six entries (homes made it thirteen).
            // Exact-match-or-null was the participants bug of 2026-07-30 worn
            // by a different field; unmatchable still fails closed to null.
            $place = xeric_forge_pick_key((string)($e['place'] ?? ''), array_keys($placeKeys), '');
            $out[] = [
                'title' => $title,
                'days_ago' => max(1, min(60, (int)($e['days_ago'] ?? 7))),
                'place' => $place !== '' ? $place : null,
                'participants' => $who,
                'prose' => $prose,
            ];
        }
        if (count($out) < 2) throw new RuntimeException('only ' . count($out) . ' usable events');
        return $out;
    }, fn() => $default['events'], $onNote);

    $memories = [];
    foreach ($chars as $i => $c) {
        $handle = (string)$c['handle'];
        $blind = $protected[$handle] ?? '';      // what THIS character must never learn
        xeric_forge_note($onNote, "seed: what {$c['display_name']} already knows");
        $mine = xeric_forge_attempt("seed memories for $handle", function () use (
            $endpoint, $c, $user, $world, $castLines, $placeLines, $events, $blind, $onNote
        ) {
            $recent = [];
            foreach (array_slice($events, 0, 3) as $e) {
                // a walled person did not attend the part of the past they are walled from
                if ($blind !== '' && xeric_forge_trips_wall($e['title'] . ' ' . $e['prose'], $blind)) continue;
                $recent[] = '- ' . $e['title'] . ': ' . $e['prose'];
            }
            $msgs = [
                ['role' => 'system', 'content' =>
                    'You write what one person already remembers. Concrete, small, past tense. '
                    . 'Reply with ONE JSON object and nothing else.'],
                ['role' => 'user', 'content' =>
                    "World: $world. {$c['display_name']} — " . (string)($c['one_line'] ?? '') . "\n"
                    . "The person at the centre is $user.\n\nOthers:\n" . implode("\n", $castLines)
                    . "\n\nPlaces:\n" . implode("\n", $placeLines)
                    . ($recent ? "\n\nRecently:\n" . implode("\n", $recent) : '')
                    . "\n\nWrite 3 things {$c['display_name']} already knows, did, or owes. One sentence each, "
                    . "third person, past tense. At least one must involve $user by name.\n"
                    . ($blind !== ''
                        ? "{$c['display_name']} does not know about $blind, and must not find out here. "
                        . "Nothing they remember may state it, hint at it, or be an account of somebody "
                        . "who does know. Write around it as though it were not there.\n"
                        : '')
                    . "{ \"memories\": [\"…\", \"…\", \"…\"] }\nNo prose outside the JSON."],
            ];
            $raw = xeric_forge_ask($endpoint, 'seed_memories', $msgs, ['temperature' => 1.0, 'max_tokens' => 450], $onNote);
            $out = [];
            foreach (xeric_forge_list($raw['memories'] ?? [], 4, 400) as $m) {
                // dropped, not rewritten — a tidied-up sentence about the secret
                // is still a sentence about the secret
                if ($blind !== '' && xeric_forge_trips_wall($m, $blind)) continue;
                $out[] = ['handle' => (string)$c['handle'], 'text' => $m, 'days_ago' => 3 + count($out) * 9];
            }
            if (count($out) < 2) throw new RuntimeException('only ' . count($out) . ' usable memories');
            return $out;
        }, function () use ($default, $handle) {
            $out = [];
            foreach ($default['memories'] as $m) if ($m['handle'] === $handle) $out[] = $m;
            return $out;
        }, $onNote);
        if ($blind !== '') {
            // The fallback memories are ours and generic, but they are built from
            // this world's events, so they get the same gate. A protected person
            // with two memories instead of three is a smaller loss than one who
            // starts the world already knowing.
            $kept = array_values(array_filter($mine, fn($m) => !xeric_forge_trips_wall((string)$m['text'], $blind)));
            if (count($kept) < count($mine)) {
                xeric_forge_note($onNote, 'seed: dropped ' . (count($mine) - count($kept))
                    . " memory/memories that walked into what {$c['display_name']} must not know");
            }
            $mine = $kept;
        }
        foreach ($mine as $m) $memories[] = $m;
    }

    return ['events' => $events, 'memories' => $memories];
}

/** Deterministic seed history, derived from the week the cast already keeps. */
function xeric_forge_default_seed(array $template): array
{
    $chars = array_values((array)($template['cast']['characters'] ?? []));
    $user = xeric_forge_str($template['user']['name'] ?? '', 'you', 60);
    $names = [];
    foreach ($chars as $c) $names[(string)$c['handle']] = (string)$c['display_name'];

    $placeName = function (string $key) use ($template): string {
        foreach ((array)$template['places'] as $p) if ((string)$p['key'] === $key) return (string)$p['name'];
        return $key;
    };
    $whereOf = function (array $c) use ($template): string {
        $k = (string)($c['week'][0]['where'] ?? '');
        return $k !== '' ? $k : (string)(((array)$template['places'])[0]['key'] ?? '');
    };

    $events = [];
    $a = $chars[0] ?? null;
    $b = $chars[1] ?? $a;
    $c3 = $chars[2] ?? $a;
    if ($a) {
        $p = $whereOf($a);
        $events[] = [
            'title' => 'the night nobody went home',
            'days_ago' => 11,
            'place' => $p,
            'participants' => array_values(array_unique(array_filter([(string)$a['handle'], (string)($b['handle'] ?? '')]))),
            'prose' => 'It went long at ' . $placeName($p) . ' and nobody made a move to leave. '
                . $names[(string)$a['handle']] . ' told a story nobody had heard before and has not mentioned it since. '
                . $user . ' was there for the end of it.',
        ];
    }
    if ($b) {
        $p = $whereOf($b);
        $events[] = [
            'title' => 'the favour that got done quietly',
            'days_ago' => 24,
            'place' => $p,
            'participants' => [(string)$b['handle']],
            'prose' => $names[(string)$b['handle']] . ' covered for ' . $user . ' at ' . $placeName($p)
                . ' and made a point of not making a point of it. It has not come up again, which is its own kind of ledger.',
        ];
    }
    if ($c3) {
        $p = $whereOf($c3);
        $events[] = [
            'title' => 'the argument left where it fell',
            'days_ago' => 6,
            'place' => $p,
            'participants' => [(string)$c3['handle']],
            'prose' => 'Something got said at ' . $placeName($p) . ' that was true and badly timed. '
                . $names[(string)$c3['handle']] . ' has been polite ever since, which everyone has noticed.',
        ];
    }

    $memories = [];
    foreach ($chars as $i => $c) {
        $h = (string)$c['handle'];
        $p = $placeName($whereOf($c));
        $memories[] = ['handle' => $h, 'days_ago' => 30,
            'text' => $names[$h] . ' has been at ' . $p . ' long enough to know which door sticks and who is going to be late.'];
        $memories[] = ['handle' => $h, 'days_ago' => 12,
            'text' => $names[$h] . ' owes ' . $user . ' one, from a night neither of them has brought up since.'];
        $other = $chars[($i + 1) % max(1, count($chars))] ?? null;
        if ($other && (string)$other['handle'] !== $h) {
            $memories[] = ['handle' => $h, 'days_ago' => 20,
                'text' => $names[$h] . ' and ' . (string)$other['display_name'] . ' have an old disagreement they keep alive on purpose.'];
        }
    }

    return ['events' => $events, 'memories' => $memories];
}

// ---------------------------------------------------------------------------
// Pass 9 — the story overlay. A plot laid over the cast, never written into it.
// ---------------------------------------------------------------------------

/**
 * THE PLOT SNAKE, as the professor drew it: up fast on both axes, build, taper
 * to halfway, HOLD there, a crescendo, and down to a resolution that is not
 * zero, because the world was never at zero.
 *
 * The curve belongs to the forge and not to the model, for the same reason the
 * week block does: a small model asked for seven strictly-increasing control
 * points with a flat stretch matching a separately declared window will get one
 * of those three things right. A model is good for what happens. This is when.
 *
 * The flat of the curve and `false_calm` are the same two numbers on purpose.
 * Intensity 0.5 is the value that multiplies sweep_chance by exactly 1.0, so
 * the false calm is the world at its own ordinary pace rather than the story
 * going slack — and if the two numbers were allowed to differ they would, the
 * first time somebody edited one of them.
 *
 * `kind_thumb` re-weights kinds the world has ALREADY armed, and every
 * multiplier is positive: a thumb is not a gate. A story may never arm a
 * system, or `closeness` in this table would be a back door into the desire
 * economy that the age floor closes structurally.
 */
function xeric_forge_story_snake(): array
{
    $thumb = [
        'opening'    => ['routine' => 2.0, 'visit' => 2.0, 'recognition' => 1.5],
        'rising'     => ['rumor' => 2.0, 'confidence' => 1.8, 'glimpse' => 1.8, 'friction' => 1.5],
        'taper'      => [],
        'false_calm' => ['ease' => 2.0, 'shared_meal' => 2.0, 'routine' => 1.8,
                         'rumor' => 0.5, 'confidence' => 0.5, 'glimpse' => 0.5],
        'crescendo'  => ['confidence' => 2.5, 'mishap' => 2.0, 'friction' => 2.0, 'chase' => 2.0],
        'closing'    => ['recognition' => 2.0, 'ease' => 1.5],
    ];
    // sweeps.php is not a forge dependency — the wizard loads this file on its
    // own — so the table is checked against the engine's vocabulary only when
    // something else has already loaded it. A kind the engine does not know is
    // inert rather than harmful, but it is also a rename nobody noticed.
    if (function_exists('xeric_sweep_kinds')) {
        $known = xeric_sweep_kinds();
        foreach ($thumb as $stage => $row) {
            foreach (array_keys($row) as $k) if (!isset($known[$k])) unset($thumb[$stage][$k]);
        }
    }
    foreach ($thumb as $stage => $row) if ($row === []) $thumb[$stage] = (object)[];

    return [
        'curve' => [[0.0, 0.0], [0.08, 0.55], [0.35, 0.8], [0.5, 0.5], [0.72, 0.5], [0.92, 1.0], [1.0, 0.15]],
        'false_calm' => [0.5, 0.72],
        'pace_swing' => 0.6,
        'kind_thumb' => $thumb,
    ];
}

/**
 * Where n beats sit on the curve.
 *
 * Deterministic, and it steps around the false calm rather than trusting
 * anybody to remember it is there: two thirds of the beats climb to the taper,
 * the rest land on the crescendo, and nothing opens between 0.50 and 0.72.
 */
function xeric_forge_story_ats(int $n, array $calm = [0.5, 0.72]): array
{
    if ($n <= 0) return [];
    $pre  = max(1, min(max(1, $n - 1), (int)ceil($n * 0.66)));
    $post = $n - $pre;
    $lo = max(0.0, (float)($calm[0] ?? 0.5) - 0.05);    // 0.45 — the last rung before the flat
    $hi = min(0.92, (float)($calm[1] ?? 0.72) + 0.06);  // 0.78 — the first rung after it
    $out = [];
    for ($i = 0; $i < $pre; $i++)  $out[] = round($pre === 1 ? 0.0 : $i * $lo / ($pre - 1), 3);
    for ($j = 0; $j < $post; $j++) $out[] = round($post === 1 ? $hi : $hi + $j * (0.92 - $hi) / ($post - 1), 3);
    return $out;
}

/**
 * Everything about this world a story has to be true to, read once.
 *
 * `alias` is the reason this pass can be pointed at an existing cast at all: a
 * model answers "Dale" and "Pastor Dale Ostrander" as readily as `pastor_dale`,
 * and a piece attributed to nobody is a beat that gets dropped. Ambiguous
 * aliases are deleted rather than guessed at — two Vances in a town means
 * "Vance" resolves to nobody, because attributing a piece to the wrong person
 * is worse than losing it.
 */
function xeric_forge_story_ctx(array $t): array
{
    $chars = $minors = $roles = [];
    $seen = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $chars[$h] = $c;
        if (xeric_is_minor($c)) $minors[$h] = true;
        $name = (string)($c['display_name'] ?? '');
        $cand = [$h];
        foreach (xeric_forge_aliases($name) as $a) $cand[] = $a;
        foreach (explode(' ', $name) as $w) $cand[] = $w;
        foreach ($cand as $a) {
            $k = xeric_forge_slug($a, '_');
            if ($k === '' || mb_strlen($k) < 3) continue;
            $seen[$k][$h] = true;
        }
    }
    $alias = [];
    foreach ($seen as $k => $owners) if (count($owners) === 1) $alias[$k] = (string)array_key_first($owners);
    foreach (array_keys($chars) as $h) $alias[$h] = $h;   // a handle is never ambiguous

    foreach ((array)($t['cast']['special_roles'] ?? []) as $r) {
        $h = (string)($r['character'] ?? '');
        if ($h !== '') $roles[$h] = (string)($r['role'] ?? '');
    }

    $places = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $k = (string)($p['key'] ?? '');
        if ($k !== '') $places[$k] = (string)($p['name'] ?? $k);
    }

    // The rating-gated interior this overlay may not restate at a lower rating.
    // Harlan's money trouble is `mature` in an sfw world: an overlay states what
    // a holder can OBSERVE and never re-tells somebody's gated inside.
    $eff = xeric_world_rating($t);
    $gated = [];
    foreach ($chars as $h => $c) {
        foreach (['drives', 'secrets', 'psyche'] as $sect) {
            $node = $c[$sect] ?? null;
            if (!is_array($node) || !isset($node['rating_min'])) continue;
            if (xeric_rating_rank((string)$node['rating_min']) <= xeric_rating_rank($eff)) continue;
            foreach ($node as $k => $v) if (is_string($v) && mb_strlen($v) > 24) $gated["$h.$sect.$k"] = $v;
        }
    }

    return [
        'chars' => $chars, 'handles' => array_keys($chars), 'alias' => $alias,
        'minors' => $minors, 'roles' => $roles, 'places' => $places, 'gated' => $gated,
        'rating' => (string)($t['meta']['rating'] ?? xeric_ratings()[0]),
        'effective' => $eff,
        'world' => xeric_forge_str($t['meta']['name'] ?? '', 'here', 60),
        'user' => xeric_forge_str($t['user']['name'] ?? '', 'you', 60),
        'public' => xeric_forge_public_key((array)($t['places'] ?? [])),
        'cap' => intdiv(count($chars), 2),
    ];
}

/** A handle the model offered, resolved against this cast's real names. */
function xeric_forge_story_handle($v, array $ctx, string $fallback = ''): string
{
    $s = xeric_forge_slug((string)$v, '_');
    if ($s === '') return $fallback;
    if (isset($ctx['alias'][$s])) return $ctx['alias'][$s];
    return xeric_forge_pick_key($v, $ctx['handles'], $fallback);
}

/**
 * INJECTION — three doors, one pass. Returns an overlay object, or [] when this
 * world has nobody in it.
 *
 *   door 1  $brief = ['prose' => "a guy gets hit by a bus and it turns out…"]
 *           The cast goes in as a roster and the model is asked for roles and
 *           pieces — never for the schema. Everything it answers with is
 *           coerced against the real handles, so a hallucinated character
 *           becomes a real one or the beat is dropped.
 *   door 2  A user's own overlay needs no pass at all: engine-side load and
 *           validate. Handed here as ['from' => <overlay>] with no `change` it
 *           is re-shaped and re-keyed without a model call, which is what the
 *           paste box wants.
 *   door 3  $brief = ['from' => <overlay>, 'change' => "this time the guy…"]
 *           A sparse answer merged over the original, WRITTEN AS A NEW KEY.
 *           Reshaping in place would move walls under a running world and
 *           there is no sensible answer to "what happens to a spilled beat".
 *
 * `$brief['taken']` is the keys this world already carries — see
 * xeric_forge_story_keys() — so a second overlay cannot claim the first one's
 * namespace.
 *
 * The discipline is every other pass's: attempt, retry once, then a
 * deterministic hand-written overlay built from the cast. A world forged
 * offline still gets a story it can play.
 *
 * THE ONE NEW PERSON IS THE VICTIM. Everybody else is somebody this world
 * already has: a mystery is a wall structure, and the walls are already up.
 * The overlay is composed on top of the template and is never written into it,
 * so this function reads $template and hands back a separate object — closing
 * the story has to be a subtraction, and it cannot be one if injecting it was
 * an edit.
 */
function xeric_forge_pass_story(array $template, array $brief, array $endpoint, ?callable $onNote = null): array
{
    $ctx = xeric_forge_story_ctx($template);
    if ($ctx['handles'] === []) {
        xeric_forge_note($onNote, 'story: this world has nobody in it — a mystery needs somebody to have done it');
        return [];
    }

    $from    = is_array($brief['from'] ?? null) ? (array)$brief['from'] : [];
    $change  = xeric_forge_str($brief['change'] ?? '', '', 800);
    $prose   = xeric_forge_str($brief['prose'] ?? '', '', 1200);
    // No brief at all: the world's own reason for existing is the brief.
    if ($prose === '' && $from === []) $prose = xeric_forge_str($template['user']['motivation'] ?? '', '', 400);

    $floor = xeric_forge_default_parts($template, $ctx);
    $base  = $from !== [] ? xeric_forge_story_parts_of($from, $ctx, $floor) : $floor;

    xeric_forge_note($onNote, $from !== []
        ? 'story: re-shaping "' . ($base['title'] ?: 'a story') . '" as a new overlay'
        : 'story: laying a plot over the people who are already here');

    if ($from !== [] && $change === '') {
        $parts = $base;                     // door 2: no model, no change, new key
        $parts['source'] = 'authored';
    } else {
        $parts = xeric_forge_attempt('story draft', function () use ($endpoint, $template, $ctx, $base, $prose, $change, $from, $onNote) {
            $flat = xeric_forge_ask(
                $endpoint,
                'story',
                $from !== []
                    ? xeric_forge_story_reshape_msgs($template, $ctx, $from, $change)
                    : xeric_forge_story_draft_msgs($template, $ctx, $prose),
                ['temperature' => 0.4, 'max_tokens' => 1600],
                $onNote
            );
            return xeric_forge_story_from($flat, $ctx, $base, $from !== [] ? 'reshaped' : 'model');
        }, fn() => $base, $onNote);
    }

    $parts = xeric_forge_story_scrub($parts, $ctx, $floor, $onNote);
    if ($parts['pieces'] === []) {
        // Everything the model wrote walked into a wall or a gated interior.
        // The default is ours and is built from what this world shows in
        // public, so it survives the same scrub.
        xeric_forge_note($onNote, 'story: nothing the draft held up — falling back to the built-in plot');
        $parts = xeric_forge_story_scrub($floor, $ctx, $floor, $onNote);
    }

    $taken = [];
    foreach ((array)($brief['taken'] ?? []) as $k) $taken[xeric_forge_slug((string)$k, '_')] = true;
    if ($from !== [] && ($k = xeric_forge_slug((string)($from['key'] ?? ''), '_')) !== '') $taken[$k] = true;

    $overlay = xeric_forge_story_assemble(xeric_forge_story_key($parts, $taken), $parts, $template, $ctx);
    return xeric_forge_story_checked($overlay, $floor, $template, $ctx, $taken, $onNote);
}

/**
 * Nothing generated is load-bearing until it validates.
 *
 * engine/story.php owns the schema and is deliberately NOT a forge dependency:
 * the wizard loads this file on its own and has no business pulling in the
 * sweep engine to draft a plot. When the engine is already loaded — which is
 * true of anything that could actually inject an overlay into a running world
 * — the pass holds its own output to the real validator and falls back to the
 * built-in plot rather than handing onward something that will not load.
 *
 * If even the built-in plot fails, this returns NO story, the same as a world
 * with nobody in it. That is the one place the story pass is not "always
 * launchable", and it is deliberate: an overlay is written to a file and read
 * back forever, so an unloadable one is a world that throws every morning. No
 * story is a world that carries on exactly as it did.
 */
function xeric_forge_story_checked(array $overlay, array $floor, array $t, array $ctx, array $taken, ?callable $onNote): array
{
    if (!function_exists('xeric_story_validate')) return $overlay;
    try {
        xeric_story_validate($overlay, $t, 'forge');
        return $overlay;
    } catch (Throwable $e) {
        xeric_forge_note($onNote, 'story: the drafted overlay did not validate (' . $e->getMessage()
            . ') — used the built-in plot');
    }
    $built = xeric_forge_story_assemble(
        xeric_forge_story_key($floor, $taken),
        xeric_forge_story_scrub($floor, $ctx, $floor),
        $t,
        $ctx
    );
    try {
        xeric_story_validate($built, $t, 'forge');
        return $built;
    } catch (Throwable $e) {
        xeric_forge_note($onNote, 'story: the built-in plot did not validate either (' . $e->getMessage()
            . ') — this world gets no story rather than a broken one');
        return [];
    }
}

/**
 * The roster the model is shown. Handles, names, ages and the one public
 * sentence — commons only. Nobody's interior goes into a drafting prompt,
 * because a model handed a secret writes it back out as a piece and the wall
 * it belonged to was the thing that made it worth finding.
 */
function xeric_forge_story_roster(array $ctx): string
{
    $out = '';
    foreach ($ctx['chars'] as $h => $c) {
        $out .= '- ' . $h . ' — ' . (string)($c['display_name'] ?? $h)
            . ', ' . (int)($c['age'] ?? 0) . ' — '
            . mb_substr((string)($c['one_line'] ?? ''), 0, 110) . "\n";
    }
    return $out;
}

/** The shape asked for, and the rules that make an answer usable. Byte-stable. */
function xeric_forge_story_shape(): string
{
    return "{\n"
        . "  \"title\": \"the story's title, under 8 words\",\n"
        . "  \"logline\": \"ONE sentence a player may be shown before it is solved — it must not name who did it\",\n"
        . "  \"victim\": { \"name\": \"a person who is NOT in the list\", \"age\": 61, \"one_line\": \"one sentence\", \"found\": \"where and by whom\" },\n"
        . "  \"truth\": \"2-3 sentences: what actually happened. Nobody in the world is ever shown this\",\n"
        . "  \"culprit\": \"a handle from the list\",\n"
        . "  \"protect\": { \"handle\": \"a handle from the list\", \"must_not_know\": \"one short phrase\" },\n"
        . "  \"opening\": { \"title\": \"5 words\", \"place\": \"a place key\", \"prose\": \"2-3 sentences, past tense\" },\n"
        . "  \"pieces\": [ { \"handle\": \"a handle from the list\", \"piece\": \"ONE sentence: a thing this person saw or did\",\n"
        . "      \"while_locked\": \"one sentence TO them, second person: how they hold it back\",\n"
        . "      \"when_open\": \"one sentence TO them, second person: how they say it\",\n"
        . "      \"spilled_as\": \"one sentence, third person, past tense: what they remember telling\",\n"
        . "      \"asks_about\": [\"3 words that would get them onto it\"], \"trust_gate\": 4 } ],\n"
        . "  \"herrings\": [ { \"believer\": \"a handle from the list\", \"belief\": \"2 sentences, third person: what they are sure of\",\n"
        . "      \"because\": \"one sentence: how they came to be sure\", \"points_at\": \"a handle, or null\",\n"
        . "      \"actually\": \"one sentence: the truth of it\", \"known_false_to\": [\"handles\"] } ]\n"
        . "}\n"
        . "RULES\n"
        . "- Every handle must come from the list. The victim is the only new person you may invent.\n"
        . "- 4 or 5 pieces, 1 or 2 herrings. Spread the pieces over different people.\n"
        . "- A piece is one thing that person SAW or DID. Not a theory, not an accusation.\n"
        . "- A herring is believed sincerely and is WRONG. `points_at` must NOT be the culprit, and is null\n"
        . "  when the wrong lead is a theory rather than a suspect.\n"
        . "- Some of these people are children; their ages are in the list. A child may see the thing that\n"
        . "  solves it, and often does. Nothing sexual or romantic involving anybody under 18, in any field.\n"
        . "- Write only what a person could have observed. You have not been told anybody's private interior\n"
        . "  and you may not invent one.\n"
        . "No prose outside the JSON.";
}

/** Door 1 — one paragraph of prose in, a plot over this cast out. */
function xeric_forge_story_draft_msgs(array $t, array $ctx, string $prose): array
{
    $places = '';
    foreach ($ctx['places'] as $k => $name) $places .= "- $k — $name\n";
    return [
        ['role' => 'system', 'content' =>
            'You outline a small mystery over people who already exist. You are given a cast and you use it: '
            . 'the only new person you may invent is the one who died. Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            'World: ' . $ctx['world'] . ' — ' . mb_substr((string)($t['meta']['description'] ?? ''), 0, 200) . "\n"
            . 'The person at the centre is ' . $ctx['user'] . ".\n\n"
            . "People — use these handles exactly:\n" . xeric_forge_story_roster($ctx)
            . "\nPlaces:\n" . $places
            . "\nWhat this story is about:\n" . ($prose !== '' ? $prose : 'something that happened here that nobody has got to the bottom of')
            . "\n\n" . xeric_forge_story_shape()],
    ];
}

/** Door 3 — the story that exists, plus what should be different about it. */
function xeric_forge_story_reshape_msgs(array $t, array $ctx, array $from, string $change): array
{
    // The original goes in as the same flat shape it will come back as, minus
    // the machinery: a model that is shown `at` values and wall keys starts
    // editing them, and those are assembled here where they cannot be got wrong.
    $was = ['title' => (string)($from['title'] ?? ''), 'logline' => (string)($from['logline'] ?? ''),
            'truth' => (string)($from['truth'] ?? ''), 'culprit' => (string)($from['cast']['culprit'] ?? ''),
            'victim' => (array)($from['cast']['victim'] ?? []), 'pieces' => [], 'herrings' => []];
    foreach ((array)($from['beats'] ?? []) as $b) {
        if (($b['holder'] ?? null) === null) continue;
        $was['pieces'][] = ['handle' => (string)$b['holder'], 'piece' => (string)($b['piece'] ?? '')];
    }
    foreach ((array)($from['red_herrings'] ?? []) as $h) {
        $was['herrings'][] = ['believer' => (string)($h['believer'] ?? ''), 'belief' => (string)($h['belief'] ?? '')];
    }

    return [
        ['role' => 'system', 'content' =>
            'You revise a story outline. You are given the story as it stands and one change to make to it. '
            . 'Return only the fields that are different; anything you leave out keeps the value it already has. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            'World: ' . $ctx['world'] . "\n\nPeople — use these handles exactly:\n" . xeric_forge_story_roster($ctx)
            . "\nThe story as it stands:\n" . (json_encode($was, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
            . "\n\nThe change:\n" . ($change !== '' ? $change : 'tell it about somebody else')
            . "\n\n" . xeric_forge_story_shape()],
    ];
}

/**
 * The overlay the forge writes when there is no model, and the floor every
 * other door falls back to. One victim, one holder per person, one wrong lead,
 * an accusation — the minimum that is actually playable.
 *
 * The pieces are written fresh, and are observations: what this person saw of
 * a thing that has just happened. An authored overlay is free to reuse a
 * character's own secret word for word — the fixture does, three times, and it
 * composes back onto the same person it was already about — but the forge
 * cannot know whether a secret this world already keeps has anything to do
 * with the body. What it CAN reuse is the gate: the trust it takes to get a
 * thing out of somebody is a property of that person, not of the plot.
 */
function xeric_forge_default_parts(array $t, array $ctx): array
{
    $chars = $ctx['chars'];
    $rows = [
        ['name' => 'Arlen Rook', 'age' => 74,
         'one_line' => 'Back after nine years with a folder under his arm and no explanation for either.',
         'found' => 'at the bottom of a stairwell on a Tuesday, by somebody walking a dog',
         'logline' => 'Arlen Rook came back to sell what was left and went down a flight of stairs instead.'],
        ['name' => 'Vera Dowd', 'age' => 68,
         'one_line' => 'Owned a good deal of what everybody here stands on, and had just told two people she was selling.',
         'found' => 'in the loading yard behind the building, early, by whoever opens up',
         'logline' => 'Vera Dowd told two people she was selling, and one of them was the last to see her.'],
        ['name' => 'Cassius Peel', 'age' => 57,
         'one_line' => 'Home for a week, staying nowhere in particular, owing money to three people who all liked him anyway.',
         'found' => 'in the water under the road bridge on a Sunday, by nobody in particular',
         'logline' => 'Cassius Peel came home owing three people money and did not leave again.'],
    ];
    // Stable per world, and it steps off a surname this cast already owns —
    // the victim is the one new person in the overlay and he should not read
    // as somebody's brother unless a person decided that.
    $owned = [];
    foreach ($chars as $c) foreach (explode(' ', (string)($c['display_name'] ?? '')) as $w) {
        $k = xeric_forge_slug($w, '_');
        if ($k !== '') $owned[$k] = true;
    }
    $n = count($rows);
    $start = (int)(crc32($ctx['world']) % $n);
    $row = $rows[$start];
    for ($s = 0; $s < $n; $s++) {
        $cand = $rows[($start + $s) % $n];
        $last = xeric_forge_slug(explode(' ', $cand['name'])[1] ?? '', '_');
        if (!isset($owned[$last])) { $row = $cand; break; }
    }
    $vName  = $row['name'];
    $vFirst = explode(' ', $vName)[0];

    // The dullest available choice: an adult. Not because a child may not have
    // done it — the model is free to say so and this pass will keep it — but
    // because a fallback should never be the interesting decision.
    $culprit = $ctx['handles'][0];
    foreach ($ctx['handles'] as $h) if (!isset($ctx['minors'][$h])) { $culprit = $h; break; }

    $protect = null;
    foreach ($ctx['handles'] as $h) {
        if ($h === $culprit || isset($ctx['roles'][$h]) || $ctx['cap'] < 1) continue;
        $protect = ['character' => $h, 'role' => 'unaware',
                    'must_not_know' => 'who was there when ' . $vFirst . ' died'];
        break;
    }

    $look = [
        '{N} saw {V} going the other way up the street at an hour that did not fit, and thought nothing of it until afterward.',
        '{N} was asked, the week before, who still had keys to the place, and answered without thinking about it.',
        '{N} has been carrying somebody else\'s shortfall since the spring and writing it down as something else.',
        '{N} found a door unlocked twice in a fortnight, and locked it both times without mentioning it to anybody.',
        '{N} heard two people arguing somewhere they should not have been, late, and did not recognise either voice.',
    ];
    $pieces = [];
    $j = 0;
    foreach ($ctx['handles'] as $h) {
        if ($h === $culprit || count($pieces) >= 5) continue;
        $own = xeric_forge_story_own_secret($chars[$h], $ctx);
        $pieces[] = [
            'handle' => $h,
            'piece' => str_replace(['{N}', '{V}'],
                [(string)($chars[$h]['display_name'] ?? $h), $vFirst], $look[$j % count($look)]),
            'while_locked' => '', 'when_open' => '', 'spilled_as' => '',
            'asks_about' => [], 'trust_gate' => $own['trust_gate'] ?: min(9, 3 + $j * 2),
            'rating_min' => 'sfw',
        ];
        $j++;
    }
    $pieces[] = xeric_forge_story_culprit_piece($ctx, $culprit, $vFirst);

    $herrings = [];
    $believer = '';
    foreach (array_reverse($ctx['handles']) as $h) {
        if ($h !== $culprit && $h !== (string)($protect['character'] ?? '')) { $believer = $h; break; }
    }
    if ($believer !== '') {
        $bn = (string)($chars[$believer]['display_name'] ?? $believer);
        $herrings[] = [
            'believer' => $believer,
            'belief' => 'It was an accident and nothing else. ' . $vFirst . ' was somewhere ordinary at an ordinary hour '
                . 'and something ordinary went wrong, and there is nothing here to solve. The talking is doing more damage than any answer would.',
            'because' => $bn . ' was around that evening, saw nothing out of the way, and is not somebody who misses things.',
            'sincerity' => 'certain',
            'actually' => 'It was not an accident. Somebody else was there, and had been there often enough to be comfortable there, and was gone before anybody came.',
            'points_at' => null,
            'known_false_to' => [$culprit],
            'rating_min' => 'sfw',
        ];
    }

    $where = (string)($t['mystery']['place_key'] ?? '');
    if ($where === '' || !isset($ctx['places'][$where])) $where = $ctx['public'];
    $closeAt = (string)($chars[$culprit]['week'][0]['where'] ?? '');
    if ($closeAt === '' || !isset($ctx['places'][$closeAt])) $closeAt = $ctx['public'];
    $victim = ['name' => $vName, 'age' => (int)$row['age'], 'one_line' => $row['one_line'], 'found' => $row['found']];
    $commons = xeric_forge_story_commons($victim, $culprit, $ctx);

    return [
        'source' => 'authored',
        'title' => $commons['title'],
        'logline' => $row['logline'],
        'truth' => $commons['truth'],
        'victim' => $victim,
        'culprit' => $culprit,
        'protect' => $protect,
        'event' => [
            'title' => 'They found ' . $vName,
            'place' => $where,
            'prose' => 'It went round before noon, the way it does, and by the afternoon it had picked up a detail '
                . 'nobody had actually seen. Nobody got much done anywhere after that.',
        ],
        'pieces' => $pieces,
        'herrings' => $herrings,
        'close' => [
            'title' => 'It came out on a Tuesday',
            'place' => $closeAt,
            'prose' => 'It got said standing up, in the middle of the afternoon, and then everybody stood there a while '
                . 'because nobody had worked out what you do next.',
            'world_keeps' => ($ctx['places'][$closeAt] ?? 'This place') . ' opens at the same hour tomorrow and everybody '
                . 'here still has the same job, and there is no longer an open question between any of them.',
        ],
    ];
}

/**
 * The three top-level strings, written from THIS victim and THIS culprit.
 *
 * They are also the replacements when a drafted one has to be dropped, which
 * is why they are derived rather than lifted from the built-in plot: falling
 * back to a stock logline about a man nobody in this story has heard of is a
 * worse failure than the one it was fixing.
 *
 * `title` and `logline` are the only strings a player may be shown before the
 * story resolves, so neither of them names anybody.
 */
function xeric_forge_story_commons(array $victim, string $culprit, array $ctx): array
{
    $vName = (string)$victim['name'];
    $cName = (string)($ctx['chars'][$culprit]['display_name'] ?? $culprit);
    $found = trim((string)($victim['found'] ?? ''));
    return [
        'title' => 'What Happened to ' . $vName,
        'logline' => $found !== ''
            ? $vName . ' was found ' . rtrim($found, '.') . ', and everybody here has a version of why.'
            : $vName . ' died here, and everybody here has a version of why.',
        'truth' => $cName . ' was there. They had been arguing about the same thing for months and it went wrong in '
            . 'the time it takes to put a hand out, and ' . explode(' ', $cName)[0] . ' has not slept since.',
    ];
}

/** The piece the person who did it is holding. Always the last beat. */
function xeric_forge_story_culprit_piece(array $ctx, string $culprit, string $victimFirst): array
{
    $name = (string)($ctx['chars'][$culprit]['display_name'] ?? $culprit);
    return [
        'handle' => $culprit,
        'piece' => $name . ' was there with ' . $victimFirst . ' that night, arguing about the thing they had both '
            . 'been arguing about for months, and it went wrong in about four seconds.',
        'while_locked' => '', 'when_open' => '', 'spilled_as' => '',
        'asks_about' => [], 'trust_gate' => 9, 'rating_min' => 'sfw',
    ];
}

/**
 * A secret this character already keeps that this world actually renders, and
 * what it costs to get it out of them.
 *
 * The gate is the part the fallback uses: what it takes to be told a thing is
 * a property of the person, and a story that re-invented it would have a
 * twelve-year-old and a pastor holding their pieces at the same price. The
 * text comes back with it because an authored overlay may reuse a character's
 * own line word for word — it composes onto the same person it was already
 * about, so it is not a leak. A secret gated above the world's effective
 * rating is skipped either way: an overlay states what a holder can OBSERVE
 * and never re-tells a gated interior.
 */
function xeric_forge_story_own_secret(array $c, array $ctx): array
{
    foreach ((array)($c['secrets'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $text = trim((string)($s['text'] ?? ''));
        if (mb_strlen($text) < 24) continue;
        if (isset($s['rating_min']) && xeric_rating_rank((string)$s['rating_min']) > xeric_rating_rank($ctx['effective'])) continue;
        return ['text' => $text, 'trust_gate' => max(0, min(10, (int)($s['trust_gate'] ?? 0)))];
    }
    return ['text' => '', 'trust_gate' => 0];
}

/**
 * A model reply, shaped into parts. Throws when it gave us nothing usable.
 *
 * Anything missing keeps the value it already had — which is what makes door 3
 * a sparse merge and door 1 safe against a model that answers half the object.
 */
function xeric_forge_story_from(array $flat, array $ctx, array $base, string $source): array
{
    $parts = $base;
    $parts['source'] = $source;

    $parts['title']   = xeric_forge_str($flat['title'] ?? '', $base['title'], 120);
    $parts['logline'] = xeric_forge_str($flat['logline'] ?? '', $base['logline'], 300);
    $parts['truth']   = xeric_forge_str($flat['truth'] ?? '', $base['truth'], 900);

    $v = (array)($flat['victim'] ?? []);
    if (trim((string)($v['name'] ?? '')) !== '') {
        $parts['victim'] = [
            'name' => xeric_forge_str($v['name'] ?? '', $base['victim']['name'], 80),
            'age' => (int)($v['age'] ?? $base['victim']['age']),
            'one_line' => xeric_forge_str($v['one_line'] ?? '', $base['victim']['one_line'], 300),
            'found' => xeric_forge_str($v['found'] ?? '', $base['victim']['found'], 300),
        ];
    }
    $parts['culprit'] = xeric_forge_story_handle($flat['culprit'] ?? '', $ctx, $base['culprit']);

    $p = (array)($flat['protect'] ?? []);
    $ph = xeric_forge_story_handle($p['handle'] ?? ($p['character'] ?? ''), $ctx, '');
    if ($ph !== '' && trim((string)($p['must_not_know'] ?? '')) !== '') {
        $parts['protect'] = ['character' => $ph, 'role' => 'unaware',
                             'must_not_know' => xeric_forge_str($p['must_not_know'] ?? '', '', 140)];
    }

    $o = (array)($flat['opening'] ?? $flat['event'] ?? []);
    if (trim((string)($o['prose'] ?? '')) !== '') {
        $place = xeric_forge_pick_key($o['place'] ?? '', array_keys($ctx['places']), $base['event']['place']);
        $parts['event'] = [
            'title' => xeric_forge_str($o['title'] ?? '', $base['event']['title'], 120),
            'place' => $place,
            'prose' => xeric_forge_str($o['prose'] ?? '', $base['event']['prose'], 700),
        ];
    }

    $pieces = [];
    $offered = $flat['pieces'] ?? $flat['beats'] ?? null;
    foreach ((array)($offered ?? []) as $row) {
        if (!is_array($row)) continue;
        $h = xeric_forge_story_handle($row['handle'] ?? ($row['holder'] ?? ''), $ctx, '');
        $text = xeric_forge_str($row['piece'] ?? '', '', 600);
        if ($h === '' || $text === '' || isset($pieces[$h])) continue;   // one beat per person keeps the chain legible
        $pieces[$h] = [
            'handle' => $h,
            'piece' => $text,
            'while_locked' => xeric_forge_str($row['while_locked'] ?? '', '', 400),
            'when_open' => xeric_forge_str($row['when_open'] ?? '', '', 400),
            'spilled_as' => xeric_forge_str($row['spilled_as'] ?? '', '', 400),
            'asks_about' => array_map('mb_strtolower', xeric_forge_list($row['asks_about'] ?? [], 6, 24)),
            'trust_gate' => max(0, min(10, (int)($row['trust_gate'] ?? 4))),
            'rating_min' => xeric_forge_str($row['rating_min'] ?? 'sfw', 'sfw', 20),
        ];
    }
    // A field the RESHAPE door did not answer keeps the value it already had —
    // that is what makes door 3 a sparse merge. A draft that names nobody who
    // exists is a bad reply whichever way it got there, and a bad reply is
    // retried and then defaulted.
    if ($pieces === [] && ($offered !== null || $source !== 'reshaped')) {
        throw new RuntimeException('no piece landed on anybody who exists');
    }
    if ($pieces !== []) $parts['pieces'] = array_values($pieces);

    $herrings = [];
    foreach ((array)($flat['herrings'] ?? $flat['red_herrings'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $b = xeric_forge_story_handle($row['believer'] ?? '', $ctx, '');
        $belief = xeric_forge_str($row['belief'] ?? '', '', 700);
        if ($b === '' || $belief === '') continue;
        $known = [];
        foreach (xeric_forge_list($row['known_false_to'] ?? [], 4, 60) as $k) {
            $kh = xeric_forge_story_handle($k, $ctx, '');
            if ($kh !== '' && $kh !== $b && !in_array($kh, $known, true)) $known[] = $kh;
        }
        $herrings[] = [
            'believer' => $b,
            'belief' => $belief,
            'because' => xeric_forge_str($row['because'] ?? '', '', 400),
            'sincerity' => in_array((string)($row['sincerity'] ?? ''), ['certain', 'fairly_sure', 'wondering'], true)
                ? (string)$row['sincerity'] : 'certain',
            'actually' => xeric_forge_str($row['actually'] ?? '', '', 500),
            // an unresolvable name is a wrong THEORY rather than a wrong suspect
            'points_at' => xeric_forge_story_handle($row['points_at'] ?? '', $ctx, '') ?: null,
            'known_false_to' => $known,
            'rating_min' => xeric_forge_str($row['rating_min'] ?? 'sfw', 'sfw', 20),
        ];
    }
    if ($herrings !== []) $parts['herrings'] = $herrings;

    return $parts;
}

/** An overlay that already exists, read back as parts. Doors 2 and 3 start here. */
function xeric_forge_story_parts_of(array $s, array $ctx, array $floor): array
{
    $flat = [
        'title' => $s['title'] ?? null, 'logline' => $s['logline'] ?? null, 'truth' => $s['truth'] ?? null,
        'victim' => $s['cast']['victim'] ?? null, 'culprit' => $s['cast']['culprit'] ?? null,
        'pieces' => [], 'herrings' => (array)($s['red_herrings'] ?? []),
    ];
    $p = (array)(($s['cast']['protect'] ?? [])[0] ?? []);
    if ($p !== []) $flat['protect'] = ['handle' => $p['character'] ?? '', 'must_not_know' => $p['must_not_know'] ?? ''];
    foreach ((array)($s['beats'] ?? []) as $b) {
        if (!is_array($b)) continue;
        if (($b['holder'] ?? null) === null) {
            $flat['opening'] = (array)($b['as_event'] ?? []);
            continue;
        }
        $flat['pieces'][] = [
            'handle' => $b['holder'], 'piece' => $b['piece'] ?? '',
            'while_locked' => $b['while_locked'] ?? '', 'when_open' => $b['when_open'] ?? '',
            'spilled_as' => $b['spilled_as'] ?? '',
            'asks_about' => $b['opens_when']['asks_about'] ?? [],
            'trust_gate' => $b['opens_when']['trust_gate'] ?? 4,
            'rating_min' => $b['rating_min'] ?? 'sfw',
        ];
    }
    try {
        $parts = xeric_forge_story_from($flat, $ctx, $floor, 'authored');
    } catch (Throwable $e) {
        return $floor;      // an overlay written for some other world's cast
    }
    $close = (array)($s['on_close'] ?? []);
    if (isset($close['event']['prose'])) {
        $parts['close'] = [
            'title' => xeric_forge_str($close['event']['title'] ?? '', $floor['close']['title'], 120),
            'place' => xeric_forge_pick_key($close['event']['place'] ?? '', array_keys($ctx['places']), $floor['close']['place']),
            'prose' => xeric_forge_str($close['event']['prose'] ?? '', $floor['close']['prose'], 700),
            'world_keeps' => xeric_forge_str($close['world_keeps'] ?? '', $floor['close']['world_keeps'], 500),
        ];
    }
    return $parts;
}

/**
 * Everything the pass owes regardless of which door it came through.
 *
 * Four gates, all of them DROPS rather than rewrites, for the same reason the
 * seed pass drops a memory: a tidied-up sentence about the secret is still a
 * sentence about the secret.
 *
 *  1. THE AGE FLOOR. Every node is evaluated against its SUBJECT. A node
 *     gating content above a minor's ceiling does not survive — same rule and
 *     same reason as flirt_style on a twelve-year-old, since content that can
 *     never render is content nobody should be writing about a child. Note
 *     what this rule does NOT do: a minor may hold a piece, believe a wrong
 *     lead, be named in the resolution and be in the room when it comes out.
 *     The floor is sex and nothing else, and a mystery usually needs the kid.
 *  2. THE PROTECTED PERSON'S OWN PROMPT. A piece or a belief composes into its
 *     holder's system message; one that walks into what they must not know
 *     hands them the thing on day one and nothing downstream takes it back.
 *  3. RATING-GATED INTERIORS. An overlay states what a holder can OBSERVE. It
 *     never restates somebody's gated inside at a lower rating, which is
 *     checkable with the same six-word-run test the walls use.
 *  4. THE COMMONS. `logline` and `title` are the only strings a player may see
 *     before it resolves, so neither may name who did it.
 */
function xeric_forge_story_scrub(array $parts, array $ctx, array $floor, ?callable $onNote = null): array
{
    $name = fn(string $h): string => (string)($ctx['chars'][$h]['display_name'] ?? $h);
    $gatedBy = function (string $s) use ($ctx): string {
        if (mb_strlen($s) <= 24 || $ctx['gated'] === []) return '';
        $hay = xeric_wall_words($s);
        foreach ($ctx['gated'] as $path => $g) if (xeric_wall_quotes($hay, xeric_wall_words($g))) return $path;
        return '';
    };
    // The highest rating a node about this person could ever render at: the
    // world's, or — for a minor, in every world, with no path to raise it —
    // the weakest rating there is. Derived per subject, never read off a field.
    $ceilingOf = function (string $h) use ($ctx): string {
        $eff = xeric_effective_rating($ctx['rating'], (array)($ctx['chars'][$h] ?? []));
        return xeric_rating_rank($eff) < xeric_rating_rank($ctx['effective']) ? $eff : $ctx['effective'];
    };

    $parts['culprit'] = isset($ctx['chars'][(string)($parts['culprit'] ?? '')])
        ? (string)$parts['culprit'] : (string)$floor['culprit'];
    $culprit = $parts['culprit'];

    // The victim is the one person an overlay invents, and the forge does not
    // invent a dead child. That is a rule about what this pass WRITES, not
    // about who a world may contain — an authored overlay is free to.
    $v = (array)($parts['victim'] ?? []);
    if (trim((string)($v['name'] ?? '')) === '' || !is_int($v['age'] ?? null) || (int)$v['age'] < XERIC_ADULT_AGE
        || $gatedBy((string)($v['one_line'] ?? '')) !== '') {
        $parts['victim'] = $floor['victim'];
    }

    // --- the protection, and what it costs -----------------------------------
    //
    // One, at most, and never more than floor(n/2) of the cast — which for one
    // protection means a cast of two. xeric_sweep_choose() excludes EVERY
    // protected handle from a spine hour, so protecting half a small town
    // stops spine hours happening at all, and a mystery with no offscreen
    // motion is a lookup table with a body in it.
    $blind = '';
    $p = is_array($parts['protect'] ?? null) ? $parts['protect'] : null;
    if ($p !== null) {
        $h = (string)($p['character'] ?? '');
        $what = trim((string)($p['must_not_know'] ?? ''));
        if (!isset($ctx['chars'][$h]) || $what === '' || $ctx['cap'] < 1) {
            $p = null;
        } elseif ($h === $culprit) {
            $p = null;
            xeric_forge_note($onNote, 'story: dropped a protection on the person who did it — they are not in the dark');
        } elseif (isset($ctx['roles'][$h])) {
            $p = null;
            xeric_forge_note($onNote, 'story: ' . $name($h) . ' already carries the special_role \'' . $ctx['roles'][$h]
                . '\' — a second one is an ambiguous audience, so this overlay protects nobody');
        } else {
            $blind = $what;
        }
    }
    $parts['protect'] = $p;

    // --- pieces ---------------------------------------------------------------
    $keep = [];
    foreach ((array)($parts['pieces'] ?? []) as $piece) {
        $h = (string)($piece['handle'] ?? '');
        if (!isset($ctx['chars'][$h]) || trim((string)($piece['piece'] ?? '')) === '' || isset($keep[$h])) continue;

        $ceiling = $ceilingOf($h);
        if (xeric_rating_rank((string)($piece['rating_min'] ?? 'sfw')) > xeric_rating_rank($ceiling)) {
            xeric_forge_note($onNote, 'story: dropped a beat gated at \'' . (string)$piece['rating_min'] . '\' on '
                . $name($h) . ', who renders at \'' . $ceiling . '\' — a node that can never be reached is not a beat');
            continue;
        }

        $prose = implode(' ', [$piece['piece'], $piece['while_locked'] ?? '', $piece['when_open'] ?? '', $piece['spilled_as'] ?? '']);
        if ($blind !== '' && $h === (string)$p['character'] && xeric_forge_trips_wall($prose, $blind)) {
            xeric_forge_note($onNote, 'story: dropped ' . $name($h) . '\'s beat — it walked into what they must not know');
            continue;
        }
        if (($path = $gatedBy($prose)) !== '') {
            xeric_forge_note($onNote, 'story: dropped ' . $name($h) . '\'s beat — it restates ' . $path
                . ', which this world does not render');
            continue;
        }
        $keep[$h] = $piece;
        if (count($keep) >= 6) break;
    }
    // The person who did it holds the last beat, always. A mystery whose answer
    // nobody is carrying is a lookup table with a body in it.
    if (!isset($keep[$culprit])) {
        $keep[$culprit] = xeric_forge_story_culprit_piece($ctx, $culprit, explode(' ', (string)$parts['victim']['name'])[0]);
    } else {
        $last = $keep[$culprit];
        unset($keep[$culprit]);
        $keep[$culprit] = $last;
    }
    $parts['pieces'] = array_values($keep);

    // --- red herrings ---------------------------------------------------------
    $herrings = [];
    foreach ((array)($parts['herrings'] ?? []) as $h) {
        $b = (string)($h['believer'] ?? '');
        if (!isset($ctx['chars'][$b])) continue;
        // `because` and `actually` are not decoration: a belief with no grounds
        // dies to one question, and a wrong lead with no explanation is a dead
        // end. Neither can be invented here, so the herring goes.
        if (trim((string)($h['belief'] ?? '')) === '' || trim((string)($h['because'] ?? '')) === ''
            || trim((string)($h['actually'] ?? '')) === '') {
            xeric_forge_note($onNote, 'story: dropped a wrong lead with no grounds or no explanation of itself');
            continue;
        }
        if (xeric_rating_rank((string)($h['rating_min'] ?? 'sfw')) > xeric_rating_rank($ceilingOf($b))) {
            xeric_forge_note($onNote, 'story: dropped a wrong lead gated above what ' . $name($b) . ' renders at');
            continue;
        }

        if ($blind !== '' && $b === (string)$p['character']
            && xeric_forge_trips_wall((string)$h['belief'] . ' ' . (string)$h['because'], $blind)) {
            xeric_forge_note($onNote, 'story: dropped ' . $name($b) . '\'s wrong lead — it walked into what they must not know');
            continue;
        }
        if (($path = $gatedBy((string)$h['belief'] . ' ' . (string)$h['because'] . ' ' . (string)$h['actually'])) !== '') {
            xeric_forge_note($onNote, 'story: dropped a wrong lead that restates ' . $path);
            continue;
        }
        // A lead that points at the guilty party is the answer, not a lead; one
        // that points at the person holding it is not a lead either. Both
        // become a wrong THEORY, which is the other honest shape.
        $pa = $h['points_at'] ?? null;
        if ($pa !== null && ((string)$pa === $culprit || (string)$pa === $b || !isset($ctx['chars'][(string)$pa]))) {
            xeric_forge_note($onNote, 'story: "' . mb_substr((string)$h['belief'], 0, 40)
                . '…" pointed somewhere it may not — kept as a wrong theory instead');
            $h['points_at'] = null;
        }
        $h['is_false'] = true;      // the field exists to make the engine's knowledge explicit
        $herrings[] = $h;
        if (count($herrings) >= 3) break;
    }
    $parts['herrings'] = $herrings;

    // --- the strings a player sees, and the one the narrator does -------------
    $tell = [];
    foreach ($ctx['alias'] as $a => $h) if ($h === $culprit && mb_strlen($a) >= 4) $tell[] = str_replace('_', ' ', $a);
    $names = fn(string $s): bool => (bool)array_filter($tell, fn($w) => preg_match('/\b' . preg_quote($w, '/') . '\b/iu', $s) === 1);
    $own = xeric_forge_story_commons((array)$parts['victim'], $culprit, $ctx);

    foreach (['title' => 200, 'logline' => 300, 'truth' => 900] as $field => $max) {
        $s = xeric_forge_str($parts[$field] ?? '', $own[$field], $max);
        // The commons is one sentence about a death, not the answer to it.
        if ($field !== 'truth' && $names($s)) {
            xeric_forge_note($onNote, 'story: the ' . $field . ' named who did it — wrote a plain one instead');
            $s = $own[$field];
        }
        if ($gatedBy($s) !== '' || trim($s) === '') $s = $own[$field];
        $parts[$field] = $s;
    }
    foreach (['event', 'close'] as $blk) {
        foreach ((array)($parts[$blk] ?? []) as $k => $s) {
            if (is_string($s) && $gatedBy($s) !== '') $parts[$blk][$k] = (string)$floor[$blk][$k];
        }
    }
    return $parts;
}

/**
 * Scrubbed parts in, a whole overlay out — and the overlay is the same object
 * whichever door the parts came through.
 *
 * Everything structural is decided HERE and never asked of a model: where the
 * beats sit on the curve, which beat opens after which, which beat kills which
 * wrong lead, what the resolution requires. A small model is asked what
 * somebody saw. It is never asked for a strictly increasing sequence that
 * steps around a declared window.
 */
function xeric_forge_story_assemble(string $key, array $parts, array $t, array $ctx): array
{
    $chars = $ctx['chars'];
    $culprit = (string)$parts['culprit'];
    $vName = (string)$parts['victim']['name'];
    $vFirst = explode(' ', $vName)[0];
    $vLast = explode(' ', $vName)[1] ?? '';
    $user = $ctx['user'];
    $name = fn(string $h): string => (string)($chars[$h]['display_name'] ?? $h);

    // --- beats ----------------------------------------------------------------
    $wants = 'mishap';
    if (function_exists('xeric_sweep_kinds') && !isset(xeric_sweep_kinds()[$wants])) $wants = '';
    $beats = [[
        'key' => 'the_word_gets_around',
        'at' => 0.0,
        'holder' => null,
        'as_event' => [
            'title' => (string)$parts['event']['title'],
            'wants_kind' => $wants,
            'place' => (string)$parts['event']['place'],
            'prose' => (string)$parts['event']['prose'],
        ],
        'opens_when' => ['min_dwell_hours' => 0],
        'spilled_as' => 'It got about, the way it does here, and by the afternoon everybody had a version of it.',
        'spill_detect' => 'auto',
        'rating_min' => 'sfw',
    ]];

    $dwell = [12, 18, 18, 24, 24];
    foreach ($parts['pieces'] as $i => $piece) {
        $h = (string)$piece['handle'];
        $n = $name($h);
        $isCulprit = $h === $culprit;
        $prev = (string)$beats[count($beats) - 1]['key'];
        $beats[] = [
            'key' => 'what_' . $h . ($isCulprit ? '_did' : '_saw'),
            'at' => 0.0,
            'holder' => $h,
            'piece' => (string)$piece['piece'],
            'opens_when' => [
                'after' => [$prev],
                'min_dwell_hours' => $dwell[min($i, count($dwell) - 1)],
                'asks_about' => $piece['asks_about'] ?: xeric_forge_story_asks((string)$piece['piece'], [$vFirst, $vLast]),
                'trust_gate' => (int)$piece['trust_gate'],
            ],
            'while_locked' => (string)$piece['while_locked'] !== '' ? (string)$piece['while_locked'] : ($isCulprit
                ? 'You do not talk about that night. You change the subject to work or to weather, and you are not smooth about it, and you know you are not.'
                : 'You do not bring this up on your own. It is not yours to hand around and you have decided it does not signify.'),
            'when_open' => (string)$piece['when_open'] !== '' ? (string)$piece['when_open'] : ($isCulprit
                ? 'You tell it. All of it, about eight words at a time, with long gaps, and you do not ask what is going to be done with it.'
                : 'If it is asked about directly you say it plainly, once, and you do not enjoy saying it.'),
            'spilled_as' => (string)$piece['spilled_as'] !== '' ? (string)$piece['spilled_as'] : ($isCulprit
                ? $n . ' told ' . $user . ' the whole of it, and afterward the room was very quiet.'
                : $n . ' told ' . $user . ' about it, and then found something to do with their hands.'),
            'spill_detect' => 'quote',
            'kills_herring' => [],
            'rating_min' => (string)$piece['rating_min'],
        ];
    }
    foreach (xeric_forge_story_ats(count($beats)) as $i => $at) $beats[$i]['at'] = $at;

    // --- wrong leads, and the beat that disposes of each ----------------------
    $used = [];
    $herrings = [];
    foreach ($parts['herrings'] as $h) {
        $on = '';
        // The person a lead points at ending it himself is the best shape there
        // is: his own answer is what clears him. Otherwise the earliest beat
        // that is not the answer, because a lead has to die before the ending.
        foreach ($beats as $b) {
            if ($b['holder'] !== null && $b['holder'] === ($h['points_at'] ?? null) && !isset($used[$b['key']])) {
                $on = (string)$b['key'];
                break;
            }
        }
        if ($on === '') foreach ($beats as $b) {
            if ($b['holder'] === null || isset($used[$b['key']]) || ($b['holder'] === $culprit && count($beats) > 2)) continue;
            $on = (string)$b['key'];
            break;
        }
        if ($on !== '') $used[$on] = true;
        $h['collapses_on'] = $on !== '' ? $on : 'resolution';
        $herrings[] = $h;
    }
    // keys are assigned after the wiring so the two halves cannot disagree
    foreach ($herrings as $i => $h) {
        $hk = 'lead_' . ($i + 1) . '_' . ($h['points_at'] ?? 'a_theory');
        $herrings[$i] = [
            'key' => $hk,
            'believer' => (string)$h['believer'],
            'belief' => (string)$h['belief'],
            'because' => (string)$h['because'],
            'sincerity' => (string)$h['sincerity'],
            'is_false' => true,
            'actually' => (string)$h['actually'],
            'points_at' => $h['points_at'] ?? null,
            'known_false_to' => array_values(array_diff((array)$h['known_false_to'], [(string)$h['believer']])),
            'collapses_on' => (string)$h['collapses_on'],
            'rating_min' => (string)$h['rating_min'],
        ];
        if ($h['collapses_on'] !== 'resolution') {
            foreach ($beats as $bi => $b) {
                if ((string)$b['key'] !== (string)$h['collapses_on']) continue;
                $beats[$bi]['kills_herring'] = [$hk];
            }
        }
    }

    // --- walls ----------------------------------------------------------------
    $walls = [];
    $protect = [];
    if (is_array($parts['protect'] ?? null)) {
        $ph = (string)$parts['protect']['character'];
        $wallKey = 'story.' . $key . '.' . $ph . '_unaware';
        $walls[] = [
            'key' => $wallKey,
            'hidden' => ['story.' . $key, 'secrets.' . $culprit],
            'shown_as' => 'Somebody went somewhere they should not have been and it went wrong, which is what '
                . 'everybody else thinks too.',
            'explain' => $name($ph) . ' must not learn: ' . (string)$parts['protect']['must_not_know']
                . '. While this story is open they read a world in which nobody knows yet.',
        ];
        $protect[] = [
            'character' => $ph,
            'role' => (string)($parts['protect']['role'] ?? 'unaware'),
            'must_not_know' => (string)$parts['protect']['must_not_know'],
            'wall' => $wallKey,
        ];
    }

    // --- resolution -----------------------------------------------------------
    $held = [];
    foreach ($beats as $b) if ($b['holder'] !== null) $held[] = (string)$b['key'];
    $answerBeat = $held[count($held) - 1];
    $requires = [];
    foreach ($held as $bk) {
        if ($bk === $answerBeat || count($requires) >= 2) continue;
        $requires[] = $bk;
    }
    $requires[] = $answerBeat;

    $to = [$culprit];
    foreach ($beats as $b) {
        if ($b['holder'] === null || in_array((string)$b['holder'], $to, true) || count($to) >= 4) continue;
        $to[] = (string)$b['holder'];
    }

    $resolution = [
        'kind' => 'accusation',
        'answer' => $culprit,
        'requires_beats' => $requires,
        'accept' => ['to' => $to, 'in' => 'conversation'],
        'on_wrong' => [
            // Never true. A story that ends when you are wrong is a quiz, and
            // accusing the wrong man in act two is the genre working.
            'closes' => false,
            'costs' => 'the one who was named goes short for a day and says why, once',
            'arc' => 'story:' . $key . ':wrong',
        ],
        'never' => [],
    ];
    // The strange place is a gravity well, not a puzzle, and an overlay is
    // exactly the kind of thing that would helpfully solve it.
    if (($t['mystery']['enabled'] ?? false) && ($t['mystery']['rumor_pays_out'] ?? true) === false) {
        $resolution['never'][] = 'mystery.rumor';
    }

    // --- the residue ----------------------------------------------------------
    $believers = [];
    foreach ($herrings as $h) $believers[(string)$h['believer']] = true;
    $holders = [];
    foreach ($beats as $b) if ($b['holder'] !== null) $holders[(string)$b['holder']] = true;
    $memories = [];
    foreach (array_keys($chars) as $h) {
        $n = $name($h);
        if ($h === $culprit) {
            $memories[$h] = $n . ' said it out loud in the end, and opened at the same hour the next morning anyway.';
        } elseif (isset($believers[$h])) {
            $memories[$h] = $n . ' was wrong about it in front of people for weeks, and has not finished making that right.';
        } elseif (isset($holders[$h])) {
            $memories[$h] = $n . ' told ' . $user . ' the one thing worth knowing, and was believed.';
        } else {
            $memories[$h] = 'Word reached ' . $n . ' the way word reaches everybody here: second-hand, and mostly right.';
        }
    }

    return [
        'story_version' => 1,
        'key' => $key,
        'for_world' => (string)($t['meta']['name'] ?? ''),
        'title' => (string)$parts['title'],
        'logline' => (string)$parts['logline'],
        'kind' => 'mystery',
        // A FLOOR on visibility, never a raise. The forge writes sfw overlays
        // and nothing else: an overlay is a plot, the world's own rating still
        // governs how anything in it gets played, and an overlay above the
        // world's rating would simply never compose.
        'rating_min' => xeric_ratings()[0],
        'source' => (string)$parts['source'],
        'truth' => (string)$parts['truth'],
        'cast' => [
            'victim' => $parts['victim'],
            'culprit' => $culprit,
            'protect' => $protect,
        ],
        'walls' => $walls,
        'beats' => $beats,
        'red_herrings' => $herrings,
        'snake' => xeric_forge_story_snake(),
        'resolution' => $resolution,
        'on_close' => [
            'event' => ['title' => (string)$parts['close']['title'], 'place' => (string)$parts['close']['place'],
                        'prose' => (string)$parts['close']['prose']],
            'memories' => $memories,
            'world_keeps' => (string)$parts['close']['world_keeps'],
        ],
    ];
}

/** The words that would get somebody onto this piece, if the model gave us none. */
function xeric_forge_story_asks(string $piece, array $extra = []): array
{
    $stop = ['about', 'after', 'again', 'been', 'being', 'could', 'does', 'doing', 'from', 'have', 'into',
             'just', 'more', 'much', 'other', 'over', 'said', 'same', 'some', 'still', 'than', 'that',
             'their', 'them', 'then', 'there', 'these', 'they', 'this', 'very', 'were', 'what', 'when',
             'which', 'while', 'with', 'would', 'your', 'himself', 'herself', 'anybody', 'nobody', 'because'];
    $out = [];
    foreach ($extra as $e) {
        $w = mb_strtolower(trim((string)$e));
        if ($w !== '' && !in_array($w, $out, true)) $out[] = $w;
    }
    foreach (xeric_wall_words($piece) as $w) {
        if (count($out) >= 6) break;
        if (mb_strlen($w) >= 5 && !in_array($w, $stop, true) && !in_array($w, $out, true)) $out[] = $w;
    }
    return $out;
}

/** A slug for this overlay, from its title, never one this world already has. */
function xeric_forge_story_key(array $parts, array $taken): string
{
    $words = array_slice(preg_split('/\s+/', trim((string)($parts['title'] ?? ''))) ?: [], 0, 6);
    $k = xeric_forge_key(implode(' ', $words), $taken);
    if (preg_match('/^[a-z0-9_]+$/', $k) !== 1) $k = xeric_forge_key('story', $taken);
    return $k;
}

/** The story keys a world already carries, so a new overlay cannot collide. */
function xeric_forge_story_keys(string $worldDir): array
{
    $out = [];
    foreach (glob(rtrim($worldDir, '/') . '/story-*.json') ?: [] as $path) {
        $out[] = substr(basename($path), 6, -5);
    }
    sort($out);
    return $out;
}

/**
 * Write worlds/<world>/story-<key>.json. Returns the path.
 *
 * Never overwrites. A reshape writes a NEW file with a new key, because
 * rewriting an overlay in place moves walls under a running world and there is
 * no sensible answer to what happens to a beat that was already spilled.
 */
function xeric_forge_write_story(array $overlay, string $worldDir): string
{
    $key = (string)($overlay['key'] ?? '');
    if (preg_match('/^[a-z0-9_]+$/', $key) !== 1) throw new RuntimeException('forge: a story overlay needs a slug key');
    if (!is_dir($worldDir)) throw new RuntimeException("forge: no world directory at $worldDir");
    $path = rtrim($worldDir, '/') . '/story-' . $key . '.json';
    if (is_file($path)) throw new RuntimeException("forge: $path already exists — an overlay is never rewritten in place");
    $json = json_encode($overlay, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException("forge: cannot write $path");
    }
    return $path;
}

// ---------------------------------------------------------------------------
// The orchestrator
// ---------------------------------------------------------------------------

/**
 * Answers in, world out. Never throws for a model's sake.
 *
 * $opts: places (int), cast (int), seed (bool), fill ('presets'|'model'),
 *        interview (array|path string), timeout (int seconds per model call),
 *        guard (callable(): void)
 *
 * GUARD is how a build is stopped from outside. It is called once before every
 * pass and never inside a try/catch, so whatever it throws leaves this function
 * unchanged and reaches the caller — a build that is caught and retried is a
 * build that did not stop, and stopping is the whole guarantee the owner's
 * `touch queue.drained` exists to make. Nothing half-written reaches the disk
 * because the disk is only written after this returns.
 *
 * @return array{template:array,seed:array,notes:array}
 */
function xeric_forge_build(array $answers, array $endpoint, array $opts = [], ?callable $onNote = null): array
{
    $notes = [];
    $note = function (string $m) use (&$notes, $onNote): void {
        $notes[] = $m;
        if ($onNote) $onNote($m);
    };

    $guard = is_callable($opts['guard'] ?? null) ? $opts['guard'] : static function (): void {};
    if (isset($opts['timeout'])) $endpoint['timeout'] = (int)$opts['timeout'];
    // Where the shelf is, for the cross-world naming gates. A caller writing
    // somewhere unusual says so here; everybody else reads the deployment's
    // own worlds directory (xeric_forge_shelf's default).
    if (!empty($opts['worlds_dir'])) xeric_forge_shelf((string)$opts['worlds_dir']);

    $iv = $opts['interview'] ?? null;
    $interview = is_array($iv) ? $iv : xeric_forge_interview(is_string($iv) ? $iv : null);

    $guard();
    $before = array_keys(xeric_forge_answers_clean($answers));
    // THE ENDPOINT GOES IN EITHER WAY. It used to be handed over only for
    // fill=model, which is ✨ surprise-me's mode — and the premise route always
    // asks for fill=presets, so the pass that READS the premise never had a
    // model to read it with and the paragraph was skipped on the one route that
    // exists to honour it. Reading what somebody wrote is not surprise-me; only
    // the second half of that function is, and that is what the flag gates.
    $answers = xeric_forge_answers_fill(
        $answers,
        $interview,
        $endpoint,
        ($opts['fill'] ?? 'presets') === 'model'
    );
    $filled = array_values(array_diff(array_keys($answers), $before));
    // Said apart, because they are different claims: one is what the paragraph
    // says and the other is what nobody said and the forge chose. Somebody
    // reading "surprise-me filled: name" under a premise that names them has
    // been told exactly what went wrong, in the build log, as it happens.
    $read = array_values(array_intersect($filled, (array)($answers['__read'] ?? [])));
    $guessed = array_values(array_diff($filled, $read, ['__read']));
    unset($answers['__read']);
    if ($read)    $note('read from what you wrote: ' . implode(', ', $read));
    if ($guessed) $note('surprise-me filled: ' . implode(', ', $guessed));

    // The ceiling, imposed from outside and applied AFTER the fill: ✨ still
    // draws a whole concept, and then the concept's rating is clamped like any
    // other answer. Clamping the answer rather than the finished template is
    // deliberate — every pass reads xeric_forge_rating($answers), so the world
    // is written to the rating it will ship with instead of being written high
    // and relabelled low.
    $asked = xeric_forge_rating($answers);
    $answers['rating'] = xeric_forge_rating($answers, $opts['rating_ceiling'] ?? null);
    if ($answers['rating'] !== $asked) {
        $note('rating: "' . $asked . '" was clamped to "' . $answers['rating'] . '" by the session');
    }

    $naming = xeric_forge_naming($answers);
    if ($naming['key'] !== '') $note('names: this world draws from the ' . $naming['label'] . ' register');

    $guard();
    $concept = xeric_forge_pass_concept($answers, $endpoint, $note);
    $note('world: ' . $concept['meta']['name'] . ' — ' . $concept['meta']['description']);

    $guard();
    $places = xeric_forge_pass_places($answers, $concept, $endpoint, (int)($opts['places'] ?? 6), $note);
    $note('places: ' . implode(', ', array_map(fn($p) => (string)$p['name'], $places)));

    $guard();
    // TWELVE, matching every caller and forge/web/boot.php. This line and its
    // twin in the fallback below were the last two places the old four lived:
    // the commit that raised the default moved it in worker.php and
    // forge-cli.php and left it in boot.php and in HERE, so the number lived
    // in four files and a caller that passed nothing quietly got a writers'
    // room instead of a town.
    $cast = xeric_forge_pass_cast($answers, $concept, $places, $endpoint, (int)($opts['cast'] ?? 12), $note);
    $note('cast: ' . implode(', ', array_map(fn($c) => (string)$c['display_name'], $cast['characters'])));

    $guard();
    $template = xeric_forge_assemble($answers, $concept, $places, $cast, $endpoint, $note);

    // The gate. A template that fails is repaired; a template that still fails
    // is replaced wholesale, because "no world" is not one of the outcomes.
    try {
        xeric_world_validate($template, 'forged');
    } catch (Throwable $e) {
        $note('validation failed (' . $e->getMessage() . ') — repairing');
        $template = xeric_forge_repair($template, $note);
        try {
            xeric_world_validate($template, 'forged');
            $note('repaired template validates');
        } catch (Throwable $e2) {
            $note('still invalid (' . $e2->getMessage() . ') — falling back to a hand-written world');
            // No endpoint: every pass takes its own default, which is the same
            // code path the fallbacks always take rather than a second one.
            $concept = xeric_forge_default_concept($answers);
            $places  = xeric_forge_default_places($answers, $concept, (int)($opts['places'] ?? 6));
            $cast    = xeric_forge_pass_cast($answers, $concept, $places, [], (int)($opts['cast'] ?? 12));
            $template = xeric_forge_assemble($answers, $concept, $places, $cast, $endpoint, $note);
            xeric_world_validate($template, 'forged');   // the defaults are ours; if THESE fail, that is a real bug
        }
    }

    // PASS 7 — walls. Runs on the VALIDATED template (it needs the real cast
    // and orbits) and before seed history, so a protected person's seeded
    // memories are written knowing they are protected. The privacy baseline is
    // deterministic, so this pass has no fallback branch: worst case the model
    // proposes nothing and the baseline ships alone.
    $guard();
    $wallsOut = xeric_forge_pass_walls($template, $endpoint, $note);
    if ($wallsOut['knowledge_walls'] !== []) {
        $template['knowledge_walls'] = array_merge(
            (array)($template['knowledge_walls'] ?? []), $wallsOut['knowledge_walls']);
    }
    if ($wallsOut['special_roles'] !== []) {
        $template['cast']['special_roles'] = array_merge(
            (array)($template['cast']['special_roles'] ?? []), $wallsOut['special_roles']);
    }
    try {
        xeric_world_validate($template, 'forged');
    } catch (Throwable $e) {
        // A wall that does not validate is worse than no wall: it would look
        // like protection while protecting nobody. Drop the proposed layer,
        // keep the deterministic baseline.
        $note('walls: proposed layer did not validate (' . $e->getMessage() . ') — keeping the privacy baseline only');
        $template['knowledge_walls'] = array_values(array_filter(
            (array)($template['knowledge_walls'] ?? []),
            fn($w) => ($w['source'] ?? '') === 'baseline'));
        $template['cast']['special_roles'] = [];
        xeric_world_validate($template, 'forged');
    }

    // Whose story is this? Only asked when the user stepped out of the centre.
    $guard();
    $prot = xeric_forge_pass_protagonist($template, $endpoint, $note);
    if ($prot !== null) {
        $template['cast']['protagonist'] = $prot;
        try {
            xeric_world_validate($template, 'forged');
        } catch (Throwable $e) {
            $note('protagonist: did not validate (' . $e->getMessage() . ') — dropped');
            unset($template['cast']['protagonist']);
        }
    }

    // HOMES + THE OPENING SCENE (owner, 2026-08-02). Before seed on purpose:
    // seed history may now put somebody "at the Voss place" and mean a real
    // room. Homes append to places and are validated in place — a set that
    // fails (one person in two homes, an empty house) is dropped whole and the
    // world ships home-less rather than wrong, exactly like the walls layer.
    $guard();
    $homes = xeric_forge_attempt('homes', fn() => xeric_forge_pass_homes($template, $endpoint, $note),
        fn() => xeric_forge_default_homes($template), $note);
    if ($homes !== []) {
        $withHomes = $template;
        $withHomes['places'] = array_merge((array)$template['places'], $homes);
        try {
            xeric_world_validate($withHomes, 'forged');
            $template = $withHomes;
            $shared = count(array_filter($homes, fn($h) => count((array)$h['residents']) > 1));
            $note('homes: ' . count($homes) . ' households, ' . $shared . ' shared');
        } catch (Throwable $e) {
            $note('homes: did not validate (' . $e->getMessage() . ') — this world ships without them');
        }
    }

    $fc = xeric_forge_first_contact($template, $note);
    if ($fc !== null) {
        $template['cast']['first_contact'] = $fc;
        try {
            xeric_world_validate($template, 'forged');
        } catch (Throwable $e) {
            $note('first contact: did not validate (' . $e->getMessage() . ') — dropped');
            unset($template['cast']['first_contact']);
        }
    }

    $guard();
    $seed = ['events' => [], 'memories' => []];
    if (($opts['seed'] ?? true)) {
        $seed = xeric_forge_attempt('seed pass', fn() => xeric_forge_pass_seed($template, $endpoint, $note),
            fn() => xeric_forge_default_seed($template), $note);
        $note('seed: ' . count($seed['events']) . ' events, ' . count($seed['memories']) . ' memories');
    }

    // The back cover, last, because a blurb is written about a finished book.
    // Optional twice over: a failure is a note, and the result screen falls
    // back to meta.description, which is what it showed before blurbs existed.
    $guard();
    $blurb = xeric_forge_teaser($template, $endpoint, $note);
    if ($blurb !== '') { $template['meta']['teaser'] = $blurb; $note('back cover: written'); }

    return ['template' => $template, 'seed' => $seed, 'notes' => $notes];
}

/**
 * The back cover: eighty-odd words that sell the world without opening it.
 *
 * SPOILER-SAFE BY STARVATION, not by instruction. The prompt is built from the
 * COMMONS only — the description, the setting, the places, each character's
 * roster line, and who the player is. The walls, the mystery, the drives and
 * the seeded past are simply never handed over, so the model cannot leak on
 * the cover what it was never shown. A back cover that gives away the third
 * act is a firing offence in every publishing house for a reason.
 */
function xeric_forge_teaser(array $t, array $endpoint, ?callable $onNote = null): string
{
    $cast = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $cast[] = (string)($c['display_name'] ?? '') . ' (' . (string)($c['one_line'] ?? '') . ')';
    }
    $places = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $places[] = (string)($p['name'] ?? '') . ': ' . (string)($p['description'] ?? '');
    }
    $you = trim((string)($t['user']['name'] ?? 'you'));

    try {
        $out = xeric_forge_ask($endpoint, 'teaser', [
            ['role' => 'system', 'content' =>
                'You write back-cover copy for novels. Concrete nouns, present tense, a hook at the end. '
                . 'You never explain mechanics, never address the reader as a player, and never use '
                . 'em dashes. Reply with ONE JSON object and nothing else.'],
            ['role' => 'user', 'content' =>
                'The book: ' . (string)($t['meta']['name'] ?? '') . ' — ' . (string)($t['meta']['description'] ?? '')
                . "\nWhere: " . (string)($t['setting']['locale'] ?? '') . ', ' . (string)($t['setting']['era'] ?? '')
                . "\nThemes: " . implode(', ', (array)($t['meta']['themes'] ?? []))
                . "\nThe lead: $you, " . (string)($t['user']['occupation']['title'] ?? '')
                . ', here for ' . (string)($t['user']['motivation'] ?? '') . '.'
                . "\nThe town says: " . implode(' · ', array_slice($cast, 0, 8))
                . "\nThe rooms: " . mb_substr(implode(' · ', $places), 0, 700)
                . "\n\nWrite the back cover: 70 to 110 words, at most two short paragraphs, third person "
                . "about $you, ending on a question or an itch. Only what is written above; invent no "
                . 'secrets and reveal none.'
                . "\n" . '{"teaser": "the copy"}'],
        ], ['temperature' => 0.9, 'max_tokens' => 400], $onNote);
    } catch (Throwable $e) {
        xeric_forge_note($onNote, 'back cover: the model had nothing (' . $e->getMessage() . ') — the description stands');
        return '';
    }

    $blurb = trim((string)($out['teaser'] ?? ''));
    // House style holds on the cover too: dashes become the pauses they were.
    $blurb = (string)preg_replace('/\s*[—–]\s*/u', ', ', $blurb);
    $blurb = trim((string)preg_replace('/\s+/u', ' ', $blurb));
    if (mb_strlen($blurb) < 40) return '';
    return mb_substr($blurb, 0, 900);
}

/**
 * Write worlds/<slug>/world-template.json (+ seed.json). Returns the template path.
 *
 * Collision-safe by directory: a second "Milldale" becomes milldale-2 rather
 * than overwriting somebody's lived-in world.
 */
function xeric_forge_write(array $template, array $seed, string $worldsDir): string
{
    $slug = xeric_forge_slug((string)($template['meta']['name'] ?? 'world')) ?: 'world';
    $dir = rtrim($worldsDir, '/') . '/' . $slug;
    for ($n = 2; is_dir($dir); $n++) {
        $dir = rtrim($worldsDir, '/') . '/' . $slug . '-' . $n;
        if ($n > 999) throw new RuntimeException('forge: too many worlds named ' . $slug);
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException("forge: cannot create $dir");

    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $path = $dir . '/world-template.json';
    if (@file_put_contents($path, json_encode($template, $flags) . "\n") === false) {
        throw new RuntimeException("forge: cannot write $path");
    }
    if (@file_put_contents($dir . '/seed.json', json_encode($seed, $flags) . "\n") === false) {
        throw new RuntimeException("forge: cannot write $dir/seed.json");
    }
    return $path;
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

/** One of the four scales, defaulted. Free text lands on the nearest one. */
function xeric_forge_scale(array $answers): string
{
    $s = strtolower(trim((string)($answers['scale'] ?? '')));
    if ($s === '') return 'small_town';
    foreach (['small_town' => ['small', 'town', 'village', 'rural'],
              'city' => ['city', 'urban', 'metro'],
              'world_stage' => ['world', 'stage', 'global', 'international'],
              'invented' => ['invent', 'fantasy', 'made up', 'somewhere else', 'other']] as $key => $words) {
        if ($s === $key) return $key;
        foreach ($words as $w) if (str_contains($s, $w)) return $key;
    }
    return 'small_town';
}

/**
 * The rating answer, clamped to the three legal strings and to $ceiling.
 *
 * Two directions, and only one of them is legal. The answer may always be
 * WEAKENED — an unanswered or unrecognised rating is the weakest one, and a
 * ceiling imposed from outside wins over what was answered. Nothing here can
 * RAISE it: not the model, not the ✨ concept it drew, not a later pass.
 *
 * The ceiling is how the affirmation gate reaches the forge. A session that has
 * not affirmed it is adult (xeric_session_adult(), forge/web/session.php) hands
 * down the weakest rating, and the forge then cannot produce anything else —
 * every pass below is prompted with the clamped value, so the world is not
 * written explicit and filed as sfw.
 */
function xeric_forge_rating(array $answers, ?string $ceiling = null): string
{
    $legal = xeric_ratings();
    $r = strtolower(trim((string)($answers['rating'] ?? '')));
    if (!in_array($r, $legal, true)) $r = $legal[0];
    if ($ceiling === null) return $r;

    $cap = strtolower(trim($ceiling));
    // An unrecognised ceiling is the weakest one: a typo must never be the
    // thing that lets a world through.
    if (!in_array($cap, $legal, true)) $cap = $legal[0];
    return xeric_rating_rank($cap) < xeric_rating_rank($r) ? $cap : $r;
}

/** A timezone the clock can stand on. The interview does not ask; the machine knows. */
function xeric_forge_timezone(array $answers): string
{
    $tz = trim((string)($answers['timezone'] ?? ''));
    if ($tz === '') $tz = date_default_timezone_get() ?: 'UTC';
    try { new DateTimeZone($tz); } catch (Throwable $e) { $tz = 'UTC'; }
    return $tz;
}

/** small_town → "small town", for prompts. */
function xeric_forge_human(string $v): string
{
    return trim(str_replace('_', ' ', $v));
}

/** A string, trimmed, collapsed, capped, with a fallback. */
function xeric_forge_str($v, string $default = '', int $max = 400): string
{
    if (is_array($v)) $v = implode(' ', array_map('strval', $v));
    $s = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
    if ($s === '' || strcasecmp($s, 'null') === 0) return $default;
    if (mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max)) . '…';
    return $s;
}

/** A list of clean strings, capped in count and length. */
function xeric_forge_list($v, int $max, int $maxLen = 200): array
{
    if (is_string($v)) $v = preg_split('/\s*[;\n]\s*/u', $v) ?: [];
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $item) {
        if (is_array($item)) $item = $item['text'] ?? implode(' ', array_map('strval', $item));
        $s = xeric_forge_str($item, '', $maxLen);
        if ($s !== '') $out[] = $s;
        if (count($out) >= $max) break;
    }
    return $out;
}

/** "9", "9am", "09:00", "17.30" → "09:00" / "17:30". Anything else → $default. */
function xeric_forge_hhmm($v, string $default): string
{
    $s = strtolower(trim((string)$v));
    if ($s === '') return $default;
    if (preg_match('/^(\d{1,2})[:.]?(\d{2})?\s*(am|pm)?$/', $s, $m)) {
        $h = (int)$m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        if (($m[3] ?? '') === 'pm' && $h < 12) $h += 12;
        if (($m[3] ?? '') === 'am' && $h === 12) $h = 0;
        if ($h === 24) $h = 0;
        if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) return sprintf('%02d:%02d', $h, $min);
    }
    return $default;
}

/** "weekdays" / "most days" / [1,3,5] → a day list the validator accepts. */
function xeric_forge_days($v, array $default): array
{
    if (is_array($v)) {
        $out = [];
        foreach ($v as $d) {
            if (is_int($d) || (is_string($d) && ctype_digit($d))) {
                $i = (int)$d;
                if ($i >= 0 && $i <= 6) $out[] = $i;
            } elseif (is_string($d)) {
                $one = xeric_forge_days($d, []);
                foreach ($one as $i) $out[] = $i;
            }
        }
        $out = array_values(array_unique($out));
        return $out ?: $default;
    }
    $s = strtolower(trim((string)$v));
    if ($s === '') return $default;
    if (str_contains($s, 'weekday')) return [1, 2, 3, 4, 5];
    if (str_contains($s, 'weekend')) return [0, 6];
    if (str_contains($s, 'every') || str_contains($s, 'daily') || str_contains($s, 'all week')) return [0, 1, 2, 3, 4, 5, 6];
    if (str_contains($s, 'most')) return [1, 2, 3, 4, 5, 6];
    $names = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    $out = [];
    foreach ($names as $n => $i) if (str_contains($s, $n)) $out[] = $i;
    return $out ?: $default;
}

/** A key from a display name: "the Bluebird Diner" → bluebird_diner, never taken twice. */
function xeric_forge_key(string $s, array $taken = []): string
{
    $k = xeric_forge_slug($s, '_');
    $k = preg_replace('/^(the|a|an)_/', '', $k) ?? $k;
    if ($k === '') $k = 'k';
    if (is_numeric($k[0])) $k = 'k' . $k;
    $base = $k;
    for ($n = 2; isset($taken[$k]); $n++) $k = $base . '_' . $n;
    return $k;
}

/** Lowercase, ascii-ish, separator-joined. */
function xeric_forge_slug(string $s, string $sep = '-'): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[\x{2018}\x{2019}\x{201C}\x{201D}\']/u', '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/u', $sep, $s) ?? $s;
    return trim((string)$s, $sep);
}

/** Aliases people would actually say: the name, and the name without its article. */
function xeric_forge_aliases(string $name): array
{
    $low = strtolower(trim($name));
    $out = [$low];
    $bare = preg_replace('/^(the|a|an)\s+/', '', $low) ?? $low;
    if ($bare !== $low && $bare !== '') $out[] = $bare;
    $words = explode(' ', $bare);
    if (count($words) > 1 && strlen($words[0]) > 3) $out[] = $words[0];
    return array_values(array_unique($out));
}

/** A place key the model offered, if it is real; otherwise the fallback. */
function xeric_forge_pick_key($v, array $keys, string $fallback): string
{
    $s = xeric_forge_slug((string)$v, '_');
    foreach ($keys as $k) if ($k === $s) return $k;
    // models like to answer with the display name — accept a prefix match too
    foreach ($keys as $k) if ($s !== '' && (str_starts_with($k, $s) || str_starts_with($s, $k))) return $k;
    return $fallback;
}
