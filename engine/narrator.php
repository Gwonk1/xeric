<?php
/**
 * Xeric — the Narrator's ASK mode. The other god of the world gets a mouth.
 *
 * The engine has carried the narrator since the first day of the renderer:
 * `xeric_render_bible($t, null, …)` is full canon with no walls applied, and
 * every wall in the system is defined as "what this viewer does not get." This
 * file gives that viewer a voice — read-only, on request, and the first two of
 * its four powers (docs/NARRATOR.md): ASK, which answers questions about the
 * world and its history, and INVESTIGATE, which audits the lived record for
 * the failures that accumulate quietly. Neither changes anything.
 *
 * ── READ-ONLY IS A CONTRACT, NOT A MOOD ──────────────────────────────────
 *
 * Nothing in this file writes. No arc, no memory, no event, no world_state row,
 * no conversation — asking the narrator a question must be exactly as safe as
 * reading the files, because "a debugger for a world" that edited the world
 * while debugging it would be the bug. This is also why the narrator NEVER
 * calls xeric_story_compose(): composition has one write in it — the inciting
 * death of an overlay's victim lands on first compose — and a read-only
 * question that killed somebody would be the least read-only thing in the
 * codebase. The narrator reads a story through xeric_story_state() (arcs,
 * SELECT only) and reads the world through the RAW template.
 *
 * ── THE DISCRETION DESIGN: TWO TIERS ─────────────────────────────────────
 *
 * The doc's rule is "straight about the machine, discreet about the story",
 * and it is explicit that the narrator's discretion governs OUTPUT where the
 * walls govern INPUT. This file implements that with two different fences,
 * because the two kinds of withheld thing have different failure modes:
 *
 * TIER 1 — THE ORACLE SHELF IS ABSENT, NOT FENCED. The authorial answers —
 * a story's `truth`, a herring's `actually` and `is_false`, an unspilled
 * beat's `piece`/`spilled_as`/keys, the resolution's answer, the snake and its
 * stages, the mystery's `room`, a death row's `by_handle` — are never
 * assembled into the prompt at all. Three reasons, in order of weight:
 *   1. Instruction-following is probabilistic and absence is arithmetic. A
 *      small local model told a secret and told "never say it" will say it;
 *      a model that never read it cannot.
 *   2. The engine already owns this precedent: story.php composes `truth`,
 *      `is_false` and `actually` NOWHERE — "read nowhere near a prompt" — and
 *      the narrator does not get to be the exception that leaks.
 *   3. None of these has any legitimate use in an ASK answer. The narrator
 *      never says who did it, so it does not need to know; and a prompt that
 *      says "you know only what the town knows" is telling the truth, which
 *      beats asking the model to act out an ignorance it does not have.
 *
 * TIER 2 — IN-WORLD HIDDEN FACTS ARE PRESENT, UNDER STATED DISCRETION. A
 * character's secrets, drives, psyche, the walls' payloads — the full-canon
 * bible carries all of it, because that is what MAKES it the narrator, and
 * because answering "what has Mabel been carrying around?" at the right
 * altitude — "something about the ledger, and she has not said it to you" —
 * requires knowing the secret exists, whom it belongs to and what it touches.
 * The discretion rules in the system prompt state the doc's four refusals and
 * the refusal style, verbatim in spirit: point at the door, never open it.
 *
 * The line between tiers: tier 1 is knowledge about the STORY AS AN ARTIFACT
 * (what the author wrote in the outline); tier 2 is knowledge IN THE WORLD
 * (what somebody here knows and has not said). The narrator is the world's
 * voice, so it holds the second and is never handed the first. What the player
 * has already been told is no longer withheld at all — a spilled beat reaches
 * the narrator the honest way, through the spill memory the teller carries.
 *
 * ── OPEN QUESTIONS THE DOC LEFT, ANSWERED HERE ───────────────────────────
 *
 *   • Author mode (discretion off): NOT built, per the doc's own lean — "the
 *     default is the storyteller." No flag exists, so no flag can be left on.
 *   • Citations: DETERMINISTIC. `sources` is the manifest of what was actually
 *     assembled (event ids, trail ids, memory counts per head, story keys),
 *     built during assembly rather than claimed by the model — a small model
 *     asked to cite invents citations, and an invented citation in a debugger
 *     is worse than none.
 *   • Temperature: 0.6 by default, below chat's 0.85. A narrator reciting a
 *     record should be steadier than a character texting at midnight.
 *
 * ── PROMPT SHAPE ─────────────────────────────────────────────────────────
 *
 * Same split prompt.php lives by, for the same cache reason:
 *   SYSTEM (byte-stable per template + overlay status): identity → discretion
 *     → the full-canon bible → the shelf digest. The digest changes when a
 *     story opens or closes, which over a story's life is a handful of times.
 *   USER (grows every hour the world lives): the clock, presence, the dead,
 *     the ledgers, events with their decision trails, memories across the
 *     cast, last-heard dates — and the question, last, where a model pays the
 *     most attention.
 *
 * PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/story.php';   // overlay status via arcs — reads, never composes
require_once __DIR__ . '/death.php';
require_once __DIR__ . '/travel.php';  // where the player is standing, if anywhere
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/renderers/bible.php';
require_once __DIR__ . '/constructs.php'; // expectations: the debts the audit reads
require_once __DIR__ . '/seed.php';       // XERIC_SEED_MARKER: where "seeded, never lived" begins

// ---------------------------------------------------------------------------
// The canon the narrator reads — full, minus the oracle shelf
// ---------------------------------------------------------------------------

/**
 * The template as the narrator's bible renders it: everything, except the two
 * places the AUTHOR'S knowledge lives inside the world file itself.
 *
 * `mystery.room` goes because the room's machinery (whose words it borrows,
 * the one true thing, the consistent wrongness) is the experience of the
 * strange place, and "the strange place keeps its secret from the narrator's
 * mouth as firmly as from the cast's" — the doc's sentence, not a gloss. The
 * rumor stays: the rumor is the town's, and the town says it out loud.
 *
 * `story` (the composed lines block) goes defensively: this function is handed
 * the RAW template by every caller in this file, but a future caller holding a
 * composed one must not find that the composition rode into the narrator's
 * bible. CONTRACT: hand this file the uncomposed template. Composition writes
 * an overlay's held pieces into holders' dossiers, and once they are in the
 * cast there is no way to tell them from authored secrets — the fence for
 * those is upstream, in what you pass, not here.
 */
function xeric_narrator_canon(array $t): array
{
    unset($t['story']);
    if (isset($t['mystery']) && is_array($t['mystery'])) unset($t['mystery']['room']);
    return $t;
}

// ---------------------------------------------------------------------------
// The static half — identity, discretion, bible, shelf
// ---------------------------------------------------------------------------

/**
 * The system message. Byte-stable for a given (template, overlays, story
 * status, rating): no clock, no counts that tick, no relative dates.
 *
 * The discretion block is ENGINE CANON, not per-template prose. Every rule in
 * it is a sentence from docs/NARRATOR.md carried into the one place the model
 * reads posture from, and a template that wanted to move them would be moving
 * what the narrator IS.
 */
function xeric_narrator_system(array $t, PDO $db, array $stories = [], ?string $eff = null): string
{
    $eff  ??= xeric_world_rating($t);
    $asker  = trim((string)($t['user']['name'] ?? '')) ?: 'the one who lives here';

    $parts = [];

    $parts[] = implode("\n", [
        'YOU ARE THE NARRATOR',
        'You are the voice of this world itself. You are not a person in it: you have no',
        'body, no schedule and no stake, you never appear in anybody\'s story, and nobody',
        'in the cast has ever heard of you. You speak only to ' . $asker . ', from outside,',
        'about this world and its history. You change nothing by speaking.',
        '',
        'Full knowledge is not full disclosure. You see everything — that is what makes',
        'you worth asking — but what you see and what you say are two different things.',
    ]);

    $parts[] = implode("\n", [
        'WHAT YOU ANSWER FREELY — THE MACHINE',
        '- What happened, when, where, and who was there: the record below is yours to recite.',
        '- Why an hour happened, or did not: the reasons are written beside the events.',
        '  Quiet hours, shifts, who was free and who was off in another room are plain facts here.',
        '- Where anybody is or was, what their week looks like, what is being counted and by whom.',
        '- That something is kept from somebody. You may say a wall exists and who stands',
        '  behind it; you never say what it keeps.',
    ]);

    $parts[] = implode("\n", [
        'WHAT YOU DO NOT SAY — THE STORY',
        '- You do not unravel a mystery: not the solution, not a decisive hint, not',
        '  "notice who was at the mill on Tuesday". The strange place keeps its secret',
        '  from your mouth as firmly as from the cast\'s.',
        '- You do not say where a hidden thing is or how to claim it. A thing worth',
        '  chasing stops being worth chasing the moment somebody hands over the map.',
        '- You do not say what is coming. "Something is moving" is the most you say.',
        '- You do not hand over a character\'s secret because you know it. If it could be',
        '  learned in the world, you point at the door; you do not open it.',
    ]);

    $parts[] = implode("\n", [
        'WHEN YOU DECLINE',
        '- In your own voice, briefly: "That is not mine to say." "You will find out, or',
        '  you will not." Then move on.',
        '- Flat, never coy: no hints, no inventory of what you are keeping back. A refusal',
        '  that says there is something to find has already said too much.',
        '- Never a policy line, never a system\'s apology. The machinery you may speak of',
        '  is the world\'s — hours, shifts, chance, ledgers, walls — never models, prompts',
        '  or files, which are no part of any answer.',
    ]);

    $parts[] = implode("\n", [
        'HOW YOU SOUND',
        '- Plain, brief, a little dry. A few sentences, not an essay.',
        '- Dates and reasons exactly as the record gives them. Where you would be',
        '  guessing, say that you would be guessing.',
    ]);

    // The bible, unwalled. The header says the quiet part on purpose: telling
    // the model its knowledge outruns its mouth is the discretion design said
    // once more, at the door of the biggest block in the prompt.
    $bible = rtrim(xeric_render_bible(xeric_narrator_canon($t), null, $eff));
    if ($bible !== '') {
        $parts[] = "WHAT YOU KNOW\nEverything below is canon, and none of it is withheld from you. Most of it is\nnot yours to repeat; the rules above decide what leaves your mouth.\n\n" . $bible;
    }

    $shelf = xeric_narrator_shelf($t, $db, $stories);
    if ($shelf !== '') $parts[] = $shelf;

    return implode("\n\n", $parts) . "\n";
}

/**
 * The shelf digest: what has been laid over this world, at the altitude the
 * PLAYER is already entitled to. Title and logline are the two strings the
 * story validator requires precisely because they are the pre-resolution
 * public surface; `world_keeps` is the residue a closed story leaves out loud.
 *
 * Nothing else. No beat keys, no counts of what remains, no stage, no
 * progress: "how much is left" is the shape of what is coming, and the shape
 * of what is coming is the one part of the world the narrator never discusses.
 * The digest also tells the model the truth about its own ignorance — the
 * outline was never assembled, so saying so costs nothing and stops a model
 * from confabulating knowledge of an ending it does not hold.
 */
function xeric_narrator_shelf(array $t, PDO $db, array $stories): string
{
    $lines = [];
    foreach ($stories as $s) {
        if (!is_array($s) || xeric_story_key($s) === '') continue;
        $st    = xeric_story_state($s, $db);
        $title = xeric_story_title($s);
        $log   = trim((string)($s['logline'] ?? ''));
        $line  = '- "' . $title . '" — ' . xeric_sentence($log);
        if ($st['live']) {
            $line .= ' It is still running.';
        } else {
            $keeps = trim((string)($s['on_close']['world_keeps'] ?? ''));
            $line .= ' It has finished.' . ($keeps !== '' ? ' ' . xeric_sentence($keeps) : '');
        }
        $lines[] = $line;
    }
    if ($lines === []) return '';

    array_unshift($lines,
        'WHAT HAS BEEN LAID OVER THIS WORLD',
        'Of anything still running you know only what the town below knows — the outline',
        'was never yours to read — and you do not speculate about how it ends.');
    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// The lived half — the record, then the question
// ---------------------------------------------------------------------------

/** 'D j M' in the world's timezone — the same shape memories already wear. */
function xeric_narrator_date(array $t, int $epoch): string
{
    $tzName = (string)($t['user']['timezone'] ?? 'UTC');
    try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    return (new DateTimeImmutable('@' . $epoch))->setTimezone($tz)->format('D j M');
}

/**
 * Everything the world has lived, as one block, plus the manifest of what went
 * in. This is the half that grows, so it rides the user message — and it is
 * where "both sides of the walls" becomes literal: every head's memories sit
 * side by side here, which no character's prompt is ever allowed to show.
 *
 * @param array $opts events (default 10), memories per head (default 5),
 *                    player_where (override; resolved from the db when absent)
 * @return array{text:string,sources:array}
 */
function xeric_narrator_lived(array $t, PDO $db, array $now, array $opts = []): array
{
    $sources = ['bible' => true, 'events' => [], 'trails' => [],
                'memories' => [], 'threads' => [], 'deaths' => []];
    $lines   = [];

    $deaths = xeric_deaths($db);

    // -- the moment -------------------------------------------------------
    $lines[] = 'THE WORLD AS IT STANDS (the record; trust it over anything you assume)';
    $day   = xeric_world_day_name((int)($now['dow'] ?? 0));
    $lines[] = xeric_sentence(trim($day . ' ' . (string)($now['phase'] ?? '')) . ', '
        . (string)($now['hhmm'] ?? '') . ' (' . (string)($now['tz'] ?? 'UTC') . ')');
    $quiet = trim((string)($t['user']['quiet_hours'] ?? ''));
    if ($quiet !== '') $lines[] = 'Quiet hours here run ' . str_replace('-', ' to ', $quiet) . '.';

    // -- who is where, right now ------------------------------------------
    $presence = xeric_world_who_is_where($t, $now, array_keys($deaths));
    $standing = [];
    foreach ($presence as $h => $row) {
        $name = xeric_world_name($t, (string)$h);
        if (($row['where'] ?? null) === null) {
            $standing[] = $name . ' on their own time';
        } elseif (!empty($row['at_home'])) {
            $standing[] = $name . ' at home';
        } else {
            $doing = trim((string)($row['doing'] ?? ''));
            $standing[] = $name . ' at ' . xeric_world_place_name($t, (string)$row['where'])
                . ($doing !== '' ? ' (' . $doing . ')' : '');
        }
    }
    if ($standing !== []) $lines[] = 'Right now: ' . xeric_join_list($standing) . '.';

    $playerWhere = array_key_exists('player_where', $opts)
        ? $opts['player_where']
        : xeric_player_where($t, $db);
    if ($playerWhere !== null && $playerWhere !== '') {
        $asker = trim((string)($t['user']['name'] ?? '')) ?: 'The one who lives here';
        $lines[] = $asker . ' is standing at ' . xeric_world_place_name($t, (string)$playerWhere) . '.';
    }

    // -- the dead ---------------------------------------------------------
    // `how` is commons text by construction. `by_handle` is tier 1 and stays in
    // the store: who killed whom is a story's to hand out one beat at a time,
    // and a narrator that knows it is one bad sampling step from saying it.
    foreach ($deaths as $h => $d) {
        $lines[] = 'Gone from this world: ' . xeric_world_name($t, (string)$h)
            . ', since ' . xeric_narrator_date($t, (int)$d['world_epoch'])
            . (trim((string)$d['how']) !== '' ? ' — ' . xeric_sentence((string)$d['how']) : '.');
        $sources['deaths'][] = (string)$h;
    }

    // -- the ledgers ------------------------------------------------------
    // Trust and the needle are machine numbers, and "why does she keep her
    // distance" is a machine question. Cast order, so the block is stable.
    $trust = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || isset($deaths[$h])) continue;
        $trust[] = xeric_world_name($t, $h) . ' ' . xeric_arc_int($db, $h, 'trust', 0);
    }
    if ($trust !== []) {
        $lines[] = 'Trust, as counted: ' . implode(', ', $trust)
            . '. The town\'s needle sits at ' . xeric_arc_int($db, xeric_arc_world(), 'needle', 0) . '.';
    }

    // -- what happened, with its reasons ----------------------------------
    $events = xeric_events_recent($db, max(1, (int)($opts['events'] ?? 10)));
    if ($events !== []) {
        $lines[] = '';
        $lines[] = 'WHAT HAS HAPPENED, NEWEST FIRST';
        foreach ($events as $e) {
            $id    = (int)$e['id'];
            $names = [];
            foreach ((array)$e['participants'] as $p) $names[] = xeric_world_name($t, (string)$p);
            $head = '- (' . xeric_narrator_date($t, (int)$e['world_epoch']) . ', event #' . $id . ') '
                . xeric_sentence((string)$e['title']);
            $place = xeric_world_place_name($t, (string)($e['place'] ?? ''));
            if ($place !== '')  $head .= ' At ' . $place . ($names !== [] ? ', with ' . xeric_join_list($names) : '') . '.';
            elseif ($names !== []) $head .= ' With ' . xeric_join_list($names) . '.';
            $lines[] = $head;
            $prose = trim((string)($e['prose'] ?? ''));
            if ($prose !== '') $lines[] = '    ' . $prose;

            // The decision trail the demo keeps under why:event:<id>. This is
            // the inspector's record and it is already payload-safe: the sweep
            // wrote "this one touches what they must not know" into it
            // precisely so a reader could be told who was kept out without
            // being told what from.
            $raw = xeric_world_state_get($db, 'why:event:' . $id);
            $why = $raw !== null ? json_decode($raw, true) : null;
            if (is_array($why)) {
                $sources['trails'][] = $id;
                $reason = trim((string)($why['why'] ?? ''));
                if ($reason !== '') $lines[] = '    why it happened: ' . xeric_sentence($reason);
                foreach ((array)($why['trail']['excluded'] ?? []) as $kept) {
                    $lines[] = '    kept out: ' . (string)($kept['name'] ?? $kept['handle'] ?? '?')
                        . ' — ' . (string)($kept['why'] ?? '');
                }
            }
            if (!empty($e['on_spine'])) $lines[] = '    this hour touched what the world is keeping quiet.';
            $sources['events'][] = $id;
        }
    }

    // -- what each head carries -------------------------------------------
    // Cast order, each head's most recent few. Fixtures hold no memories and
    // the dead keep theirs: history does not rot because its owner did.
    $per  = max(1, (int)($opts['memories'] ?? 5));
    $mem  = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $rows = xeric_memories_for($db, $h, $per);
        if ($rows === []) continue;
        $mem[] = xeric_world_name($t, $h) . ':';
        foreach ($rows as $r) {
            $when = $r['world_epoch'] !== null ? (int)$r['world_epoch'] : (int)$r['created_at'];
            $mem[] = '- (' . xeric_narrator_date($t, $when) . ') ' . xeric_sentence(trim((string)$r['text']));
        }
        $sources['memories'][$h] = count($rows);
    }
    if ($mem !== []) {
        $lines[] = '';
        $lines[] = 'WHAT EACH OF THEM CARRIES (each in their own head; no one else has read these)';
        foreach ($mem as $l) $lines[] = $l;
    }

    // -- when they were last heard from -----------------------------------
    // The doc's own worked example — "why has Mabel not appeared in three
    // days?" — is answered from exactly this: the last world-stamped message
    // in each chat thread, against the schedules the bible already carries.
    $heard = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $conv = xeric_conversation_find($db, $h, 'chat');
        if ($conv === null) continue;
        $last = xeric_messages_recent($db, (int)$conv['id'], 1);
        if ($last === []) continue;
        $when = ($last[0]['world_epoch'] ?? null) !== null
            ? (int)$last[0]['world_epoch'] : (int)$last[0]['created_at'];
        $heard[] = '- ' . xeric_world_name($t, $h) . ': ' . xeric_narrator_date($t, $when);
        $sources['threads'][$h] = $when;
    }
    if ($heard !== []) {
        $lines[] = '';
        $lines[] = 'LAST HEARD FROM (their thread with ' . (trim((string)($t['user']['name'] ?? '')) ?: 'the one who lives here') . ')';
        foreach ($heard as $l) $lines[] = $l;
    }

    return ['text' => implode("\n", $lines), 'sources' => $sources];
}

// ---------------------------------------------------------------------------
// Assembly
// ---------------------------------------------------------------------------

/**
 * The whole narrator turn, unsent: [system, user] plus the sources manifest.
 *
 * @param array $opts stories (raw overlays, from xeric_story_for — NEVER a
 *                    composed template), effective_rating, events, memories,
 *                    player_where
 * @return array{messages:array<int,array{role:string,content:string}>,sources:array}
 */
function xeric_narrator_prompt(array $t, PDO $db, array $now, string $question, array $opts = []): array
{
    $stories = (array)($opts['stories'] ?? []);
    $eff     = isset($opts['effective_rating']) ? (string)$opts['effective_rating'] : null;

    $lived = xeric_narrator_lived($t, $db, $now, $opts);
    $asker = trim((string)($t['user']['name'] ?? '')) ?: 'The one who lives here';

    // The question is the last thing in the last message, where a model pays
    // the most attention — the same seat the clock holds in a chat turn.
    $user = $lived['text'] . "\n\n"
        . "THE QUESTION\n"
        . $asker . ' asks you: ' . trim($question) . "\n\n"
        . 'Answer as the narrator: a few plain sentences, the reason where the record '
        . 'above shows one, and your discretion where it does not.';

    $lived['sources']['stories'] = [];
    foreach ($stories as $s) {
        if (is_array($s) && xeric_story_key($s) !== '') {
            $lived['sources']['stories'][xeric_story_key($s)] =
                xeric_story_state($s, $db)['live'] ? 'open' : 'closed';
        }
    }

    return [
        'messages' => [
            ['role' => 'system', 'content' => xeric_narrator_system($t, $db, $stories, $eff)],
            ['role' => 'user',   'content' => $user],
        ],
        'sources' => $lived['sources'],
    ];
}

// ---------------------------------------------------------------------------
// The model seam
// ---------------------------------------------------------------------------

/**
 * One narrator reply, as text. Same stub seam as xeric_chat_say(), tagged
 * 'narrator' so a test stub can tell the two voices apart — the interesting
 * assertions here are about what the prompt CARRIES, and those need no GPU.
 * The investigate pass rides the same seam under its own tag, for the same
 * reason: a stub that cannot tell the audit from the ask cannot test either.
 */
function xeric_narrator_say(array $endpoint, array $messages, array $opts = [], ?array &$usage = null, string $tag = 'narrator'): string
{
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
        $out = ($endpoint['stub'])($tag, $messages, $opts);
        if (is_array($out)) {
            $usage = (array)($out['usage'] ?? []);
            return (string)($out['text'] ?? '');
        }
        return (string)$out;
    }
    return xeric_llm_chat($endpoint, $messages, $opts, $usage);
}

/**
 * Ask the world why.
 *
 * @param array $now  from xeric_world_now() — injected, never fetched here
 * @param array $opts everything xeric_narrator_prompt() takes, plus
 *                    temperature (0.6), max_tokens (700), timeout (180)
 * @return array{text:string,usage:array,sources:array,messages:array}
 */
function xeric_narrator_ask(array $t, PDO $db, string $question, array $now, array $endpoint, array $opts = []): array
{
    $built = xeric_narrator_prompt($t, $db, $now, $question, $opts);

    $t0    = microtime(true);
    $usage = [];
    $text  = xeric_narrator_say($endpoint, $built['messages'], [
        'temperature' => (float)($opts['temperature'] ?? 0.6),
        'max_tokens'  => (int)($opts['max_tokens'] ?? 700),
        'timeout'     => (int)($opts['timeout'] ?? 180),
    ], $usage);
    $usage['ms'] = (int)round((microtime(true) - $t0) * 1000);

    return [
        'text'     => trim($text),
        'usage'    => $usage,
        'sources'  => $built['sources'],
        'messages' => $built['messages'],
    ];
}

// ---------------------------------------------------------------------------
// The second power — INVESTIGATE
// ---------------------------------------------------------------------------
//
// The audit (docs/NARRATOR.md §2): the failures that accumulate quietly —
// dropped threads, absent characters, unpaid debts, contradictions. Output is
// a list of OBSERVATIONS, not fixes; the world's author decides.
//
// ── CODE COUNTS, THE MODEL READS ─────────────────────────────────────────
// Every check that is arithmetic over rows is arithmetic over rows: who was
// last heard when, which boon epoch has passed, who stands in two rooms in
// one hour. The model is asked exactly one thing — to read the chat
// transcripts (the one lived surface ASK never assembled) for questions that
// were asked and never answered, because "was this answered" is a judgment
// about prose and pretending otherwise would be a regex cosplaying as a
// reader. Even there the model only POINTS: every claim it makes is a message
// id, validated against the assembly — the id must have been handed to it,
// the row must actually hold a question, and the observation's text is built
// by code from the row itself. A claim that fails any of that is dropped and
// counted, never printed. Citations are the assembly's, start to finish.
//
// ── THE DISCRETION HOLDS, TURNED TOWARD THE OWNER ────────────────────────
// Investigate reads both sides of every wall, exactly as ASK does. But its
// output is for the owner's tuning eye, and the posture stays "point at the
// door": it names that a pressure has pressed nothing without quoting the
// pressure, that a debt sits unclaimed by the debt's own label, that a thread
// went quiet by its dates — never the secret behind any of them. The oracle
// shelf stays absent exactly as ASK built it: no story outline, no beat, no
// truth is ever assembled here (the audit reads lived tables and the
// template's own machinery blocks, nowhere the author's answers live), and a
// death's `by_handle` stays in the store for the same tier-1 reason it does
// in the lived record.
//
// ── READ-ONLY, STILL ─────────────────────────────────────────────────────
// Same contract as ASK, same proof in the tests: not one row changes.

/** The audit's kinds, in the order the report groups them. */
const XERIC_NARRATOR_KINDS = ['unspoken', 'dropped_question', 'unpaid_debt',
                              'idle_pressure', 'never_lived', 'contradiction'];

/** One lived line, flattened to a single quotable breath. */
function xeric_narrator_flat(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** The flat line, cut to citation length. The id beside it is the whole text. */
function xeric_narrator_quote(string $s, int $max = 80): string
{
    $s = xeric_narrator_flat($s);
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) . '…' : $s;
}

/**
 * Every living cast member's chat thread, tail first-to-last, keyed by handle.
 * This is the one assembly ASK skipped — threads opened and dropped live here.
 * The dead are left out: the dead do not owe answers, and flagging a question
 * the grave already closed would be noise wearing an observation's clothes.
 * Characters with no thread at all are absent (nothing to transcribe); the
 * audit reports them from the cast list, not from here.
 */
function xeric_narrator_transcripts(array $t, PDO $db, int $per = 40, array $dead = []): array
{
    $out = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || in_array($h, $dead, true)) continue;
        $conv = xeric_conversation_find($db, $h, 'chat');
        if ($conv === null) continue;
        $rows = xeric_messages_recent($db, (int)$conv['id'], max(1, $per));
        if ($rows === []) continue;
        $out[$h] = ['handle' => $h, 'name' => xeric_world_name($t, $h),
                    'conv' => (int)$conv['id'], 'messages' => $rows];
    }
    return $out;
}

/**
 * The deterministic audit: every check that is arithmetic, plus the manifest
 * of everything read. No model anywhere in here — same rows in, same
 * observations out, byte for byte, which is what makes it a debugger.
 *
 * @param array $now  from xeric_world_now()
 * @param array $opts days (window, default 3 world-days), events (walk depth,
 *                    default 1000), memories (per head, default 200),
 *                    transcript (messages per thread, default 40)
 * @return array{observations:array,sources:array,transcripts:array}
 */
function xeric_narrator_audit(array $t, PDO $db, array $now, array $opts = []): array
{
    $days  = max(1, (int)($opts['days'] ?? 3));
    $epoch = (int)($now['epoch'] ?? 0);
    $cut   = $epoch - $days * 86400;
    $user  = trim((string)($t['user']['name'] ?? '')) ?: 'the one who lives here';

    $deaths  = xeric_deaths($db);
    $sources = ['events' => [], 'memories' => [], 'threads' => [],
                'messages' => [], 'arcs' => [], 'deaths' => array_keys($deaths)];
    $obs = [];
    $add = static function (string $kind, string $text, array $cites, string $by = 'code') use (&$obs): void {
        $obs[] = ['kind' => $kind, 'text' => $text, 'cites' => $cites, 'found_by' => $by];
    };

    // -- the record, whole -------------------------------------------------
    // ASK reads the last handful; the audit walks the book. The cap exists so
    // a years-old world does not become a memory bill, not as a policy.
    $events = xeric_events_recent($db, max(1, (int)($opts['events'] ?? 1000)));
    foreach ($events as $e) $sources['events'][] = (int)$e['id'];

    // Where the seeded past ends and the lived one begins: every row the
    // seeder wrote shares the marker's own created_at, to the second.
    $seedAt = 0;
    $marker = xeric_world_state_get($db, XERIC_SEED_MARKER);
    if ($marker !== null) {
        $m      = json_decode($marker, true);
        $seedAt = (int)(is_array($m) ? ($m['at'] ?? 0) : 0);
    }

    $lastSeen = [];   // handle => ['epoch' =>, 'id' =>] — their newest hour on the record
    $seedEv   = [];   // handle => seed event ids
    $livedEv  = [];   // handle => count of lived events
    foreach ($events as $e) {
        $isSeed = $seedAt > 0 && (int)$e['created_at'] === $seedAt;
        $when   = (int)$e['world_epoch'];
        foreach ((array)$e['participants'] as $p) {
            $p = (string)$p;
            if (!isset($lastSeen[$p]) || $when > $lastSeen[$p]['epoch']) {
                $lastSeen[$p] = ['epoch' => $when, 'id' => (int)$e['id']];
            }
            if ($isSeed) $seedEv[$p][] = (int)$e['id'];
            else         $livedEv[$p] = ($livedEv[$p] ?? 0) + 1;
        }
    }

    // Per-head memories, read once, walked twice (never-lived, contradictions).
    $memByHead = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $rows = xeric_memories_for($db, $h, max(1, (int)($opts['memories'] ?? 200)));
        if ($rows === []) continue;
        $memByHead[$h] = $rows;
        $sources['memories'][$h] = count($rows);
    }

    $transcripts = xeric_narrator_transcripts($t, $db,
        max(1, (int)($opts['transcript'] ?? 40)), array_keys($deaths));
    foreach ($transcripts as $h => $tr) {
        $ids = [];
        foreach ($tr['messages'] as $m) $ids[] = (int)$m['id'];
        $sources['messages'][$h] = $ids;
        $msgs = $tr['messages'];
        $last = end($msgs);
        $sources['threads'][$h] = ($last['world_epoch'] ?? null) !== null
            ? (int)$last['world_epoch'] : (int)$last['created_at'];
    }

    // -- unspoken: who has gone unheard ------------------------------------
    // The doc's "characters who have not appeared in N days", read at the
    // thread: the last line in each, or the fact that no thread exists. The
    // dead are not absent, they are dead; OUT characters have not entered the
    // story, and a silence that was authored is not a finding.
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || isset($deaths[$h]) || !empty($c['out'])) continue;
        $name = xeric_world_name($t, $h);
        if (!isset($transcripts[$h])) {
            $add('unspoken', $name . ' has never had a thread at all — not one word, either direction.', []);
            continue;
        }
        $when = $sources['threads'][$h];
        if ($when > $cut) continue;
        $msgs = $transcripts[$h]['messages'];
        $last = end($msgs);
        $add('unspoken',
            'Nobody has spoken with ' . $name . ' in ' . intdiv($epoch - $when, 86400)
            . ' world-days. The thread\'s last line (' . xeric_narrator_date($t, $when) . '): "'
            . xeric_narrator_quote((string)$last['content']) . '"',
            ['messages' => [(int)$last['id']]]);
    }

    // -- dropped questions, the arithmetic half ----------------------------
    // A question that is the LAST message of a thread needs no reader: nothing
    // followed it, so nothing answered it. The window keeps a question asked
    // this morning from being an accusation. The subtler case — a question
    // answered by a change of subject — is the model's, in investigate.
    foreach ($transcripts as $h => $tr) {
        $msgs = $tr['messages'];
        $last = end($msgs);
        if (!str_contains((string)$last['content'], '?')) continue;
        $when = $sources['threads'][$h];
        if ($when > $cut) continue;
        $asker = (string)$last['role'] === 'user'
            ? $user . ' asked ' . $tr['name']
            : $tr['name'] . ' asked';
        $add('dropped_question',
            $asker . ' "' . xeric_narrator_quote((string)$last['content']) . '" ('
            . xeric_narrator_date($t, $when) . '), and the thread ends there. No answer ever came.',
            ['messages' => [(int)$last['id']]]);
    }

    // -- unpaid debts: boons and expectations past their fade --------------
    // `boon.<key>` holds the epoch a won boon goes stale at (0 = never);
    // xeric_state_counters() drops a stale one from every prompt, "simply
    // gone" — which is exactly why the audit says it out loud: a prize that
    // faded unclaimed is a thread the world opened and nobody pulled. An
    // expectation still `open` past due-plus-grace is the same shape one
    // system over: the fuse should have burned and nothing marked it.
    $boonLabel = [];
    foreach ((array)($t['boons'] ?? []) as $b) {
        $k = (string)($b['key'] ?? '');
        if ($k !== '') $boonLabel[$k] = trim((string)($b['label'] ?? '')) ?: $k;
    }
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || isset($deaths[$h])) continue;
        $name = xeric_world_name($t, $h);
        foreach (xeric_arcs_prefixed($db, $h, 'boon.') as $k => $v) {
            $sources['arcs'][] = $h . ':' . $k;
            $stale = (int)$v;
            if ($stale <= 0 || $stale > $epoch) continue;
            $key = substr((string)$k, strlen('boon.'));
            $add('unpaid_debt',
                $name . ' won ' . ($boonLabel[$key] ?? $key) . ' and never claimed it. It faded '
                . xeric_narrator_date($t, $stale) . ', and the record shows no claim.',
                ['arcs' => [$h . ':' . $k]]);
        }
        foreach (xeric_expects_for($db, $h) as $e) {
            $sources['arcs'][] = $h . ':' . $e['key'];
            if ((string)$e['state'] !== 'open' || $epoch <= $e['due'] + XERIC_EXPECT_GRACE) continue;
            $add('unpaid_debt',
                $name . ' is still waiting on ' . (string)$e['what'] . ' — due '
                . xeric_narrator_date($t, $e['due'])
                . ', grace long past, and no miss has ever been recorded.',
                ['arcs' => [$h . ':' . $e['key']]]);
        }
    }

    // -- idle pressure: a protagonist whose pressure never pressed ---------
    // The pressure itself is never quoted — the owner wrote it, and the
    // posture stays "point at the door" even toward the person who built the
    // door. What the audit says is only that it has produced nothing.
    $p  = (array)($t['cast']['protagonist'] ?? []);
    $ph = (string)($p['handle'] ?? '');
    if ($ph !== '' && trim((string)($p['pressure'] ?? '')) !== ''
        && !isset($deaths[$ph]) && empty((xeric_world_character($t, $ph) ?? [])['out'])) {
        $pname = xeric_world_name($t, $ph);
        $seen  = $lastSeen[$ph] ?? null;
        if ($seen === null) {
            $add('idle_pressure',
                $pname . ' carries this world\'s pressure and has never once appeared in an event.', []);
        } elseif ($seen['epoch'] <= $cut) {
            $add('idle_pressure',
                $pname . ' carries this world\'s pressure and it has pressed out nothing in '
                . intdiv($epoch - $seen['epoch'], 86400) . ' world-days. Their last hour on the record is '
                . xeric_narrator_date($t, $seen['epoch']) . '.',
                ['events' => [$seen['id']]]);
        }
    }

    // -- seeded, never lived -----------------------------------------------
    // A character whose every appearance shares the seeder's write-second and
    // whose head holds only 'seed' rows arrived with a past and has not lived
    // an hour since. "Lived" is measured where living happens: a lived event,
    // a lived memory, or a word of their own in a thread.
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || isset($deaths[$h]) || !empty($c['out'])) continue;
        $seedMemIds = [];
        $livedMem   = 0;
        foreach ($memByHead[$h] ?? [] as $m) {
            if ((string)$m['source'] === 'seed') $seedMemIds[] = (int)$m['id'];
            else $livedMem++;
        }
        $seedIds = $seedEv[$h] ?? [];
        if ($seedIds === [] && $seedMemIds === []) continue;   // never seeded: not this check's business
        $spoke = false;
        foreach (($transcripts[$h]['messages'] ?? []) as $m) {
            if ((string)$m['role'] !== 'user') { $spoke = true; break; }
        }
        if (($livedEv[$h] ?? 0) > 0 || $livedMem > 0 || $spoke) continue;
        $add('never_lived',
            xeric_world_name($t, $h) . ' arrived with a past and has not lived an hour since: '
            . count($seedIds) . ' seeded event' . (count($seedIds) === 1 ? '' : 's') . ', '
            . count($seedMemIds) . ' seeded memor' . (count($seedMemIds) === 1 ? 'y' : 'ies')
            . ', and not a word spoken in any thread.',
            array_filter(['events' => $seedIds, 'memories' => $seedMemIds], fn($v) => $v !== []));
    }

    // -- contradictions the repass would care about, at the lived level ----
    // (1) One body, two rooms, one hour: two placed events inside 60 minutes
    // sharing a participant and disagreeing about where. Placeless hours
    // cannot contradict — nowhere is compatible with anywhere.
    $chrono = $events;
    usort($chrono, fn($a, $b) => [(int)$a['world_epoch'], (int)$a['id']] <=> [(int)$b['world_epoch'], (int)$b['id']]);
    $n = count($chrono);
    for ($i = 0; $i < $n; $i++) {
        $a = $chrono[$i];
        if (($a['place'] ?? null) === null || (string)$a['place'] === '') continue;
        for ($j = $i + 1; $j < $n && (int)$chrono[$j]['world_epoch'] - (int)$a['world_epoch'] < 3600; $j++) {
            $b = $chrono[$j];
            if (($b['place'] ?? null) === null || (string)$b['place'] === '') continue;
            if ((string)$b['place'] === (string)$a['place']) continue;
            $shared = array_values(array_intersect(
                array_map('strval', (array)$a['participants']),
                array_map('strval', (array)$b['participants'])));
            if ($shared === []) continue;
            $names = [];
            foreach ($shared as $s) $names[] = xeric_world_name($t, $s);
            $add('contradiction',
                'The record places ' . xeric_join_list($names) . ' at '
                . xeric_world_place_name($t, (string)$a['place']) . ' and at '
                . xeric_world_place_name($t, (string)$b['place']) . ' inside the same hour ('
                . xeric_narrator_date($t, (int)$a['world_epoch']) . ').',
                ['events' => [(int)$a['id'], (int)$b['id']]]);
        }
    }

    // (2) A memory that speaks of somebody as dead before they died. The
    // match is deliberately narrow — the dead one's name AND a burial word in
    // the same head-row, dated before the ledger's date — because an audit
    // that cries wolf gets closed and never reopened. The death's `by` is
    // read nowhere here: who did it is tier 1, in the audit as everywhere.
    $deadWords = '/(?<!\w)(dead|died|dying|funeral|buried|burial|grave|passed away|the late)(?!\w)/iu';
    foreach ($deaths as $dh => $d) {
        $dname = xeric_world_name($t, (string)$dh);
        $first = preg_split('/\s+/u', trim($dname))[0] ?? $dname;
        $namePat = '/(?<!\w)(' . preg_quote($dname, '/') . '|' . preg_quote($first, '/') . ')(?!\w)/iu';
        foreach ($memByHead as $h => $rows) {
            foreach ($rows as $m) {
                $when = ($m['world_epoch'] ?? null) !== null ? (int)$m['world_epoch'] : (int)$m['created_at'];
                if ($when >= (int)$d['world_epoch']) continue;
                $txt = (string)$m['text'];
                if (!preg_match($namePat, $txt) || !preg_match($deadWords, $txt)) continue;
                $add('contradiction',
                    xeric_world_name($t, $h) . '\'s memory of ' . xeric_narrator_date($t, $when)
                    . ' already speaks of ' . $dname . ' as dead — ' . $dname . ' died '
                    . xeric_narrator_date($t, (int)$d['world_epoch']) . '.',
                    ['memories' => [(int)$m['id']], 'deaths' => [(string)$dh]]);
            }
        }
    }

    $order = array_flip(XERIC_NARRATOR_KINDS);
    usort($obs, fn($a, $b) => ($order[$a['kind']] ?? 99) <=> ($order[$b['kind']] ?? 99));

    return ['observations' => $obs, 'sources' => $sources, 'transcripts' => $transcripts];
}

/**
 * The transcript-reading turn, unsent. Nothing here but the threads and the
 * one instruction — no bible, no shelf, no template prose at all, which is
 * what keeps the oracle absent from this prompt by construction rather than
 * by promise. The reply contract is ids, because ids are checkable.
 */
function xeric_narrator_investigate_prompt(array $t, array $transcripts): array
{
    $user = trim((string)($t['user']['name'] ?? '')) ?: 'the one who lives here';

    $sys = implode("\n", [
        'YOU ARE THE NARRATOR, READING THE RECORD',
        'Below are chat threads from this world, exactly as written. Your one job: find',
        'questions that were asked and never answered — not answered late, never answered',
        'at all. A question that got its answer, however brief, is not a finding. Small',
        'talk that merely trails off is not a finding. You are looking for a real question',
        'left hanging while the conversation moved on, or ended.',
        '',
        'Reply with a JSON array and nothing else. One object per finding:',
        '  [{"handle": "<thread handle>", "id": <the [#N] number of the question itself>}]',
        'If every question found its answer, reply [].',
    ]);

    $blocks = [];
    foreach ($transcripts as $tr) {
        $lines = ['THREAD WITH ' . $tr['name'] . ' (handle: ' . $tr['handle'] . ')'];
        foreach ($tr['messages'] as $m) {
            $who = (string)$m['role'] === 'user'
                ? $user : xeric_world_name($t, (string)($m['handle'] ?? $tr['handle']));
            $lines[] = '[#' . (int)$m['id'] . '] ' . $who . ': ' . xeric_narrator_flat((string)$m['content']);
        }
        $blocks[] = implode("\n", $lines);
    }

    return [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => implode("\n\n", $blocks)
            . "\n\nFind every question above that was asked and never answered. Reply with the JSON array only."],
    ];
}

/**
 * What the model claimed, as [{handle, id}] — and nothing it did not. Fences,
 * preambles and trailing chat are cut away by taking the outermost [...]; a
 * reply that holds no readable array is a reply that made no claims.
 */
function xeric_narrator_claims(string $reply): array
{
    $a = strpos($reply, '[');
    $b = strrpos($reply, ']');
    if ($a === false || $b === false || $b < $a) return [];
    $d = json_decode(substr($reply, $a, $b - $a + 1), true);
    if (!is_array($d)) return [];
    $out = [];
    foreach ($d as $row) {
        if (!is_array($row)) continue;
        $h  = (string)($row['handle'] ?? '');
        $id = $row['id'] ?? null;
        if ($h === '' || !is_numeric($id)) continue;
        $out[] = ['handle' => $h, 'id' => (int)$id];
    }
    return $out;
}

/**
 * Audit the world. Deterministic checks always; the transcript read only when
 * an endpoint is offered and there are transcripts to read ($endpoint = null
 * is the honest way to ask for arithmetic alone). Model claims are validated
 * against the assembly and re-cited by code — see the section banner.
 *
 * @param array $opts everything xeric_narrator_audit() takes, plus now (from
 *                    xeric_world_now(); derived from the world's own clock
 *                    when not injected), temperature (0.2 — an auditor does
 *                    not improvise), max_tokens (500), timeout (180)
 * @return array{observations:array,sources:array,model:array,messages:array}
 *         `messages` is the transcript-reading prompt when one was sent and
 *         [] otherwise; `model` carries asked/claims/kept/dropped/usage.
 */
function xeric_narrator_investigate(array $t, PDO $db, ?array $endpoint, array $opts = []): array
{
    $now   = (array)($opts['now'] ?? xeric_world_now($t, xeric_clock_epoch($db)));
    $audit = xeric_narrator_audit($t, $db, $now, $opts);
    $obs   = $audit['observations'];

    $model    = ['asked' => false, 'claims' => 0, 'kept' => 0, 'dropped' => 0, 'usage' => []];
    $messages = [];

    if ($endpoint !== null && $audit['transcripts'] !== []) {
        $messages = xeric_narrator_investigate_prompt($t, $audit['transcripts']);

        $t0    = microtime(true);
        $usage = [];
        $reply = xeric_narrator_say($endpoint, $messages, [
            'temperature' => (float)($opts['temperature'] ?? 0.2),
            'max_tokens'  => (int)($opts['max_tokens'] ?? 500),
            'timeout'     => (int)($opts['timeout'] ?? 180),
        ], $usage, 'investigate');
        $usage['ms'] = (int)round((microtime(true) - $t0) * 1000);

        $model['asked'] = true;
        $model['usage'] = $usage;

        // What the arithmetic already cited is not the model's to re-find.
        $cited = [];
        foreach ($obs as $o) {
            foreach ((array)($o['cites']['messages'] ?? []) as $mid) $cited[(int)$mid] = true;
        }

        $claims = xeric_narrator_claims($reply);
        $model['claims'] = count($claims);
        $user = trim((string)($t['user']['name'] ?? '')) ?: 'the one who lives here';
        foreach ($claims as $cl) {
            $tr  = $audit['transcripts'][$cl['handle']] ?? null;
            $row = null;
            foreach ((array)($tr['messages'] ?? []) as $m) {
                if ((int)$m['id'] === $cl['id']) { $row = $m; break; }
            }
            // The three gates, in order of what they catch: an id that was
            // never assembled (invented), a row that holds no question
            // (misread), a row the arithmetic already reported (redundant).
            if ($row === null || !str_contains((string)$row['content'], '?') || isset($cited[$cl['id']])) {
                $model['dropped']++;
                continue;
            }
            $cited[$cl['id']] = true;
            $when = ($row['world_epoch'] ?? null) !== null ? (int)$row['world_epoch'] : (int)$row['created_at'];
            $msgs = $tr['messages'];
            $tail = end($msgs);
            $asker = (string)$row['role'] === 'user'
                ? $user . ' asked ' . $tr['name'] : $tr['name'] . ' asked';
            $obs[] = ['kind' => 'dropped_question',
                'text' => $asker . ' "' . xeric_narrator_quote((string)$row['content']) . '" ('
                    . xeric_narrator_date($t, $when) . '), and '
                    . ((int)$tail['id'] === $cl['id']
                        ? 'the thread ends there'
                        : 'the conversation moved on without an answer') . '.',
                'cites' => ['messages' => [$cl['id']]], 'found_by' => 'model'];
            $model['kept']++;
        }

        $order = array_flip(XERIC_NARRATOR_KINDS);
        usort($obs, fn($a, $b) => ($order[$a['kind']] ?? 99) <=> ($order[$b['kind']] ?? 99));
    }

    return [
        'observations' => $obs,
        'sources'      => $audit['sources'],
        'model'        => $model,
        'messages'     => $messages,
    ];
}
