<?php
/**
 * review.php — the forge proposes; you dispose, and only then does it launch.
 *
 * FORGE.md principle 3. Everything a pass wrote is on one page, every text box
 * on it is editable, every section has a ↻ that re-runs THAT pass and nothing
 * else, and at the top is the one button that says this world is ready.
 *
 * FOUR THINGS THIS PAGE IS, AND THE ORDER THEY MATTER IN:
 *
 *  1. IT IS THE TUNING LOOP. The owner is about to spend a week living in this
 *     screen: change one line of somebody's voice, go and talk to them, come
 *     back. So an edit saves the moment the box loses focus, a reroll costs one
 *     tap, and neither ever makes you rebuild a world from scratch.
 *
 *  2. IT IS HONEST ABOUT REFUSALS. An edit that would not validate is refused
 *     under the box that caused it, in a sentence, with the old value still in
 *     place. A reroll that comes back unusable changes nothing at all.
 *
 *  3. IT COSTS MODEL TIME AND SAYS SO. Every reroll goes through the same
 *     session, the same caps and the same one-GPU queue as a chat turn or a
 *     skip (limits.php, queue.php). A reroll while somebody else is mid-build
 *     shows a position in the line, not a spinner.
 *
 *  4. IT IS SKIPPABLE. "Launch it and go in" is one tap from here and one tap
 *     from the result screen. A user who does not want to read any of this must
 *     not have to.
 *
 *     GET  review.php?w=<slug>          the page
 *     POST review.php?a=edit            {world, path, value} → saved, or why not
 *     POST review.php?a=reroll          {world, what, index} → a job id
 *     POST review.php?a=launch          {world}              → it is playable
 */

declare(strict_types=1);

require_once __DIR__ . '/review-lib.php';

/**
 * How many people one xeric holds.
 *
 * A CEILING, NOT A TARGET. Every character is a system prompt of their own and a
 * row in every sweep, so a cast of forty is a xeric that thinks slowly and reads
 * like a phone book. Twenty is past any hand-built cast anybody has wanted and
 * still short of where the sweeps get slow.
 */
const XERIC_REVIEW_CAST_MAX = 20;

/** And how many rooms. Same reasoning: every place is a row in every sweep. */
const XERIC_REVIEW_PLACE_MAX = 12;

/**
 * Which machine does this piece of work, resolved from an index.
 *
 * SHARED BY THE REROLL AND THE DICE, because they are the same decision and a
 * second copy is a second place for the boundary to be got wrong. What arrives
 * is a row number in the visitor's own machine list and, if that row is an API,
 * a key that is used here and never stored.
 *
 * @throws RuntimeException when nothing is attached at all
 */
function xeric_review_pick_endpoint(array $model): array
{
    $key = trim((string)($model['key'] ?? ''));
    if (isset($model['i'])) {
        $rows = xeric_model_list();
        $i = (int)$model['i'];
        if (isset($rows[$i])) {
            return xeric_web_endpoint(xeric_model_descriptor((string)$rows[$i]['base']) + ['key' => $key]);
        }
    }
    // Nothing named, or a row that has gone: the engine, which is what these
    // have always defaulted to.
    return xeric_play_endpoint();
}


$slug   = xeric_web_slug((string)($_GET['w'] ?? ''));
$action = (string)($_GET['a'] ?? '');
$sid    = xeric_session_id();

// ---------------------------------------------------------------------------
// The actions
// ---------------------------------------------------------------------------

if ($action !== '') {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if (!$isPost && $action !== 'section') {
        xeric_web_json(['error' => 'that is a POST'], 405);
    }
    $in = $isPost ? xeric_web_input() : [];
    $slug = xeric_web_slug((string)($in['world'] ?? $slug));

    try {
        $w = xeric_review_open($slug, $sid);
    } catch (Throwable $e) {
        xeric_web_json(['error' => $e->getMessage()], 404);
    }

    // Reviewing is EDITING, so it is the owner's only. Somebody else's world is
    // read-only here for the same reason their evening is: it is theirs. And it
    // is not read-only either — a section of this page is every wall and every
    // interior in it, so the check goes ABOVE the redraw, not below it.
    if (!$w['mine']) {
        xeric_web_json(['error' => 'This xeric was forged in a different browser, so it is not yours to change. '
            . 'You can still play your own copy of it.'], 403);
    }

    // One section, redrawn from disk. Rerolling the cast clears the walls and
    // whose-story-this-is (they named people who no longer exist); rerolling the
    // rooms moves everybody's week. The sections that went stale ask for
    // themselves rather than the page being reloaded under somebody's cursor.
    if ($action === 'section') {
        $sec = (string)($_GET['sec'] ?? '');
        if (!in_array($sec, ['concept', 'you', 'systems', 'places', 'cast', 'walls', 'protagonist', 'seed'], true)) {
            xeric_web_json(['error' => 'no such section'], 400);
        }
        xeric_web_json(['ok' => true, 'sec' => $sec, 'html' => xeric_review_section_html($sec, $w)]);
    }

    // -- add a person ------------------------------------------------------
    // NO MODEL. It appends the next hand-written character the forge would have
    // fallen back to, told which names are already taken, and the review page is
    // where you make it somebody. That is the whole of "start blank and add
    // characters": the engine's floor is one person, and this is how you get the
    // second, the third and the tenth without spending a token on any of them.
    if ($action === 'add') {
        $t = $w['template'];
        $chars = array_values((array)($t['cast']['characters'] ?? []));
        if (count($chars) >= XERIC_REVIEW_CAST_MAX) {
            xeric_web_json(['error' => 'That is as many people as one xeric holds. Rewrite one you '
                . 'have rather than adding another.', 'kind' => 'full'], 409);
        }

        $places = array_values((array)($t['places'] ?? []));
        // EVERYBODY HAS TO BE SOMEWHERE. A character carries place keys — where
        // they work, where they are found, where they go to be alone — and a
        // xeric with nowhere in it cannot answer any of those. Refused with the
        // fix in the sentence rather than inventing a room nobody asked for,
        // which is the one thing "start blank" promised not to do.
        if ($places === []) {
            xeric_web_json(['error' => 'There is nowhere in this xeric yet. Add a place first, then '
                . 'people have somewhere to be.', 'kind' => 'no_places'], 409);
        }
        $orbits = array_values((array)($t['cast']['orbits'] ?? []));
        $answers = (array)($t['forge']['answers'] ?? []);
        $taken = [];
        $avoid = [];
        foreach ($chars as $c) {
            $taken[(string)($c['handle'] ?? '')] = true;
            $avoid[] = (string)($c['display_name'] ?? '');
        }

        $i      = count($chars);
        $orbit  = xeric_forge_orbit_for($orbits, $i);
        $wk     = xeric_forge_workplace_key($places) ?? (string)($places[0]['key'] ?? 'work');
        $person = xeric_forge_default_character([
            'orbit'       => $orbit,
            'orbit_label' => $orbit === 'outside' ? (string)($orbits[1]['label'] ?? 'outside')
                                                  : (string)($orbits[0]['label'] ?? 'here'),
            'places'      => $places,
            'place_keys'  => array_map(fn($p) => (string)$p['key'], $places),
            'workplace'   => $wk,
            'public'      => xeric_forge_public_key($places, $wk),
            'index'       => $i,
            'user'        => xeric_forge_str($answers['name'] ?? '', 'the user', 60),
            'age_band'    => xeric_forge_age_band($i, $orbit),
            'avoid'       => $avoid,
            'rating'      => xeric_forge_rating($answers),
        ], $taken);

        $chars[] = $person;
        $t['cast']['characters'] = $chars;
        xeric_review_save($slug, $t);
        xeric_web_json(['ok' => true, 'handle' => (string)$person['handle'],
                        'name' => (string)$person['display_name'], 'n' => count($chars)]);
    }

    // -- add a place -------------------------------------------------------
    // The same shape as adding a person, from the same hand-written table the
    // forge falls back to, and just as free.
    if ($action === 'addplace') {
        $t = $w['template'];
        $places = array_values((array)($t['places'] ?? []));
        if (count($places) >= XERIC_REVIEW_PLACE_MAX) {
            xeric_web_json(['error' => 'That is as many places as one xeric holds.', 'kind' => 'full'], 409);
        }

        $answers = (array)($t['forge']['answers'] ?? []);
        $concept = ['meta' => (array)($t['meta'] ?? []), 'setting' => (array)($t['setting'] ?? []),
                    'world_mood' => (array)($t['world_mood'] ?? [])];
        $taken = [];
        foreach ($places as $p) $taken[(string)($p['key'] ?? '')] = true;

        // Ask for one more than there are and keep the first that is new, so a
        // second press cannot hand back the room you already have.
        $add = null;
        foreach (xeric_forge_default_places($answers, $concept, count($places) + 3) as $cand) {
            if (!isset($taken[(string)($cand['key'] ?? '')])) { $add = $cand; break; }
        }
        if ($add === null) {
            xeric_web_json(['error' => 'The built-in rooms are all in this xeric already. Rename one '
                . 'rather than adding another.', 'kind' => 'full'], 409);
        }

        $places[] = $add;
        $t['places'] = $places;
        // The first room is also where the user works, unless they already have one.
        if (trim((string)($t['user']['occupation']['workplace_key'] ?? '')) === '') {
            $t['user']['occupation']['workplace_key'] = (string)$add['key'];
        }
        xeric_review_save($slug, $t);
        xeric_web_json(['ok' => true, 'key' => (string)$add['key'], 'name' => (string)$add['name'],
                        'n' => count($places)]);
    }

    // -- roll one field ----------------------------------------------------
    // ONE FIELD, WRITTEN FROM EVERYTHING ELSE ALREADY IN THE XERIC. The reroll
    // buttons rewrite a whole section and cost a worker, a queue ticket and a
    // stream; this is one small call, answered in the request, for the moment
    // somebody is looking at an empty box with nothing to put in it.
    //
    // IT DOES NOT SAVE. What comes back is a suggestion in the box, and the
    // ordinary edit path saves it — so a rolled value passes the same age floor
    // and the same wall checks a typed one does. A field that could be written
    // by a model and stored without those checks would be a way around them.
    if ($action === 'roll') {
        $path = (string)($in['path'] ?? '');
        $spec = xeric_review_field($path);
        if ($spec === null) {
            xeric_web_json(['error' => 'that is not a field this page can write'], 400);
        }

        try { $endpoint = xeric_review_pick_endpoint((array)($in['model'] ?? [])); }
        catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409); }

        // One machine, one thing at a time — the same rule a chat turn obeys.
        // A dice roll is seconds, so it waits briefly rather than queueing.
        $hold = xeric_queue_take('say', 6.0, 'roll:' . $slug);
        if (($hold['ok'] ?? false) !== true) {
            xeric_web_json(['error' => 'The model is busy with something else. Try the dice again in a '
                . 'moment.', 'kind' => 'busy'], 503);
        }

        try {
            $value = xeric_review_roll($w['template'], $path, (string)$spec[0], $endpoint);
        } catch (Throwable $e) {
            xeric_queue_release($hold);
            xeric_web_json(['error' => 'The dice came back empty: ' . $e->getMessage(), 'kind' => 'model'], 502);
        }
        xeric_queue_release($hold);

        if (trim($value) === '') {
            xeric_web_json(['error' => 'The model had nothing for that one. Try it again, or type '
                . 'something and roll the next field.', 'kind' => 'empty'], 502);
        }
        xeric_web_json(['ok' => true, 'value' => $value]);
    }

    // -- the pronoun backfill ----------------------------------------------
    // ONE CALL FOR THE WHOLE CAST, and only for the holes. Casts forged before
    // pronouns were a field fall back to prose-reading and grey anyone the
    // prose does not settle; this asks the model that one question, validates
    // every answer against the vocabulary the engine reads, and writes ONLY
    // the empty fields through the same validated save every edit takes — one
    // prev copy, so ↺ is the whole batch back. An "unclear" is respected and
    // that person stays grey: a name is never evidence.
    if ($action === 'pronouns') {
        $missing = xeric_review_pronounless($w['template']);
        if ($missing === []) {
            xeric_web_json(['error' => 'Everybody in this cast already has pronouns on record.',
                            'kind' => 'complete'], 409);
        }

        try { $endpoint = xeric_review_pick_endpoint((array)($in['model'] ?? [])); }
        catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409); }

        // One machine, one thing at a time — a single small call, so it waits
        // briefly like the dice rather than queueing like a reroll.
        $hold = xeric_queue_take('say', 6.0, 'pronouns:' . $slug);
        if (($hold['ok'] ?? false) !== true) {
            xeric_web_json(['error' => 'The model is busy with something else. Try again in a moment.',
                            'kind' => 'busy'], 503);
        }
        try {
            $answers = xeric_review_pronoun_ask($w['template'], $missing, $endpoint);
        } catch (Throwable $e) {
            xeric_queue_release($hold);
            xeric_web_json(['error' => 'The model had no answer: ' . $e->getMessage(), 'kind' => 'model'], 502);
        }
        xeric_queue_release($hold);

        $r = xeric_review_pronoun_merge($w['template'], $answers);
        if ($r['filled'] !== []) {
            try {
                xeric_review_save($slug, $r['template']);
            } catch (Throwable $e) {
                xeric_web_json(['error' => $e->getMessage() . ' Nothing was written.'], 422);
            }
        }

        // The report the page prints: what landed, and who stays grey and why.
        $name = function (string $h) use ($w): string {
            foreach ((array)($w['template']['cast']['characters'] ?? []) as $c) {
                if ((string)($c['handle'] ?? '') === $h) {
                    return trim((string)($c['display_name'] ?? '')) ?: $h;
                }
            }
            return $h;
        };
        $said = [];
        if ($r['filled'] !== []) {
            $said[] = 'wrote ' . implode(', ',
                array_map(fn($h) => $name($h) . ' (' . $r['filled'][$h] . ')', array_keys($r['filled'])));
        }
        if ($r['left'] !== []) {
            $said[] = 'left ' . implode(', ', array_map($name, array_keys($r['left'])))
                . ' grey — the prose does not say, and a name is never enough';
        }
        $note = 'Pronouns: ' . implode('; ', $said) . '.'
            . ($r['filled'] !== [] ? ' One ↺ takes the whole thing back.' : '');
        xeric_web_json(['ok' => true, 'filled' => $r['filled'], 'left' => $r['left'], 'note' => $note]);
    }

    // -- rename the address ---------------------------------------------------
    // The slug is the directory, the URL, and what the owner's session points
    // at. All three move together here, or none of them do. Never automatic:
    // a rename breaks every link that exists, so it is a button somebody read.
    if ($action === 'rename') {
        $new = xeric_forge_slug((string)($w['template']['meta']['name'] ?? ''));
        if ($new === '') xeric_web_json(['error' => 'this xeric\'s name will not make an address'], 422);
        if ($new === $slug) xeric_web_json(['ok' => true, 'slug' => $slug, 'url' => 'review.php?w=' . rawurlencode($slug)]);
        $src = xeric_web_worlds_dir() . '/' . $slug;
        $dst = xeric_web_worlds_dir() . '/' . $new;
        if (is_dir($dst)) xeric_web_json(['error' => "/$new is already another xeric"], 409);
        if (!@rename($src, $dst)) xeric_web_json(['error' => 'the folder would not move'], 500);
        xeric_web_session_edit(function (array &$s) use ($slug, $new): void {
            $s['own'] = array_values(array_unique(array_map(
                fn($x) => (string)$x === $slug ? $new : (string)$x, (array)($s['own'] ?? []))));
            if ((string)($s['result']['slug'] ?? '') === $slug) $s['result']['slug'] = $new;
        }, $sid);
        xeric_web_json(['ok' => true, 'slug' => $new, 'url' => 'review.php?w=' . rawurlencode($new)]);
    }

    // -- take it off the shelf, for good --------------------------------------
    // The only door in this app with nothing behind it. Owner-gated like every
    // write, and it answers with where to go next rather than leaving the page
    // pointed at a slug that no longer resolves.
    if ($action === 'delete') {
        if (!$w['mine']) {
            xeric_web_json(['error' => 'This xeric was forged in a different browser, so it is not '
                . 'yours to delete. Your own copy of it is only the database you have been playing.'], 403);
        }
        try {
            $gone = xeric_review_delete($slug);
        } catch (Throwable $e) {
            xeric_web_json(['error' => $e->getMessage()], 500);
        }
        xeric_web_json(['ok' => true, 'url' => 'play.php',
            'name' => (string)($w['template']['meta']['name'] ?? $slug)] + $gone);
    }

    // -- the literary repass -------------------------------------------------
    // An editor's read of the whole xeric: contradictions, the plot's
    // through-line, and the story snake's pacing. Findings only — a fix is
    // applied by a=edit like any hand edit, so this endpoint never writes.
    if ($action === 'repass') {
        require_once dirname(__DIR__) . '/repass.php';
        xeric_limit_guard(xeric_limit_check('reroll', ['sid' => $sid]));

        try { $endpoint = xeric_review_pick_endpoint((array)($in['model'] ?? [])); }
        catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409); }

        // Two or three model calls back to back, so it queues like a reroll:
        // wait briefly for the slot, and say "busy" honestly rather than piling
        // an editor on top of somebody's forge.
        $hold = xeric_queue_take('say', 6.0, 'repass:' . $slug);
        if (($hold['ok'] ?? false) !== true) {
            xeric_web_json(['error' => 'The model is busy with something else. Try the repass again in a '
                . 'few minutes.', 'kind' => 'busy'], 503);
        }
        // The red button's loop sends mode=sweep (contradictions only, user.*
        // untouchable) and the paths it has already corrected, which are
        // settled and not up for re-litigation.
        $ropts = ['mode'   => (string)($in['mode'] ?? ''),
                  'frozen' => array_map('strval', (array)($in['frozen'] ?? []))];
        try {
            $r = xeric_repass($w, $endpoint, null, $ropts);
        } catch (Throwable $e) {
            xeric_queue_release($hold);
            xeric_web_json(['error' => 'The repass fell over: ' . $e->getMessage(), 'kind' => 'model'], 502);
        }
        xeric_queue_release($hold);
        // The editor's rewrites go straight in — one batched save, so ↺ takes
        // the whole pass back in one step. The findings come back marked with
        // what was fixed and what was only pointed at.
        $applied = xeric_repass_apply($w, $r['findings'], $ropts);
        $r['findings'] = $applied['findings'];
        $r['fixed']    = $applied['fixed'];
        // corrections took the back cover with them; the page rewrites it
        $r['teaser_stale'] = $applied['fixed'] > 0;
        // What it just cost rides the reply, so the meter ticks with the work
        // instead of waiting for the next page that happens to carry a pulse.
        xeric_web_json(['ok' => true, 'tokens' => xeric_web_tokens_by($sid)] + $r);
    }

    // -- the judge -------------------------------------------------------------
    // One finding, one cold question: does the complaint still hold against
    // the line as it stands on disk NOW? This is what lets Clear All
    // Contradictions terminate: a finding the judge closes stays closed, and a
    // finding the judge calls not-real twice is detector noise, not work.
    if ($action === 'verify') {
        require_once dirname(__DIR__) . '/repass.php';
        try { $endpoint = xeric_review_pick_endpoint((array)($in['model'] ?? [])); }
        catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409); }
        $hold = xeric_queue_take('say', 6.0, 'verify:' . $slug);
        if (($hold['ok'] ?? false) !== true) {
            xeric_web_json(['error' => 'The model is busy. Try again in a moment.', 'kind' => 'busy'], 503);
        }
        try {
            [, $context] = xeric_repass_digest($w['template'], $w['seed']);
            $v = xeric_repass_judge($w, $endpoint, (string)($in['path'] ?? ''),
                (string)($in['say'] ?? ''), $context);
        } catch (Throwable $e) {
            xeric_queue_release($hold);
            xeric_web_json(['error' => 'the judge did not answer', 'kind' => 'model'], 502);
        }
        xeric_queue_release($hold);
        xeric_web_json(['ok' => true, 'tokens' => xeric_web_tokens_by($sid)] + $v);
    }

    // -- one more rewrite of one line -------------------------------------
    // Told exactly why the last one failed, applied through the same doors
    // (advice check, age floor, no learning signal), one line per save.
    if ($action === 'refix') {
        require_once dirname(__DIR__) . '/repass.php';
        try { $endpoint = xeric_review_pick_endpoint((array)($in['model'] ?? [])); }
        catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409); }
        $hold = xeric_queue_take('say', 6.0, 'refix:' . $slug);
        if (($hold['ok'] ?? false) !== true) {
            xeric_web_json(['error' => 'The model is busy. Try again in a moment.', 'kind' => 'busy'], 503);
        }
        try {
            $v = xeric_repass_refix($w, $endpoint, (string)($in['path'] ?? ''),
                (string)($in['say'] ?? ''), (string)($in['why'] ?? ''));
        } catch (Throwable $e) {
            xeric_queue_release($hold);
            xeric_web_json(['error' => 'no rewrite came back', 'kind' => 'model'], 502);
        }
        xeric_queue_release($hold);
        $v['teaser_stale'] = !empty($v['fixed']);
        xeric_web_json(['ok' => true, 'tokens' => xeric_web_tokens_by($sid)] + $v);
    }

    if ($action === 'edit') {
        $path  = (string)($in['path'] ?? '');
        $value = (string)($in['value'] ?? '');

        // ONE BOX ON THIS PAGE IS WORTH A MODEL CALL. Retyping the motivation
        // re-arms the whole world (xeric_review_rearm), and the keyword table
        // it falls back to knows six archetypes — so a specific goal typed by
        // hand becomes "company" unless something reads it. Taken briefly, and
        // NEVER waited for: no slot means the resolver works it out from
        // keywords, which is what it always did. Every other field saves with
        // no model at all, exactly as before.
        $hold = null;
        $opts = [];
        if ($path === 'user.motivation') {
            try {
                $ep = xeric_review_pick_endpoint((array)($in['model'] ?? []));
                $hold = xeric_queue_take('say', 3.0, 'rearm:' . $slug);
                if (($hold['ok'] ?? false) === true) $opts['endpoint'] = $ep;
                else                                 $hold = null;
            } catch (Throwable $e) {
                $hold = null;                       // detached: keywords it is
            }
        }

        // A save that holds also writes itself down as a learning signal — a
        // hand edit is the one correction in this app that is not an inference.
        // review-lib.php does it, so there is one place rather than two.
        $r = xeric_review_apply_edit($w, $path, $value, $opts);
        if (is_array($hold)) xeric_queue_release($hold);
        xeric_web_json($r, $r['ok'] ? 200 : 422);
    }

    if ($action === 'undo') {
        // One step back. Every save keeps the copy it replaced, so a reroll
        // that bulldozed the walls and the protagonist is recoverable — the
        // failure a week of solo tuning is most likely to hit.
        $t = xeric_review_undo($slug);
        if ($t === null) {
            xeric_web_json(['ok' => false,
                'error' => 'There is nothing to go back to, this xeric has not been changed since it was forged.'], 422);
        }
        xeric_web_json(['ok' => true, 'name' => (string)($t['meta']['name'] ?? $slug),
            'note' => 'Put back the version from before the last change. Undo again to swap them the other way.']);
    }

    // -- bring somebody into the story ------------------------------------
    // The door for an OUT character (cast.characters[].out), which until now
    // only a hand edit to the JSON could open. One call into the engine, which
    // owns the whole of it — the flip, the validation, the prev-copy save and
    // the event the town remembers — so this action is a doorbell, not a
    // second implementation. Owner-only and POST-only by the same shared
    // guards as every other write on this page.
    //
    // THE DATABASE IS PASSED ONLY IF IT EXISTS. Opening it here would create
    // it, and world.db existing is what "this world has been lived in" means
    // to everything that reads the shelf (see xeric_review_learn_edit). An
    // unopened world gets the flip with no event — there is no past yet for
    // an entrance to land in, and the engine says so in its note.
    if ($action === 'enter') {
        require_once dirname(__DIR__, 2) . '/engine/enter.php';
        $dir    = xeric_review_dir($slug);
        $dbFile = $dir . '/world.db';
        try {
            $r = xeric_enter($dir, is_file($dbFile) ? xeric_state_open($dbFile) : null,
                (string)($in['handle'] ?? ''));
        } catch (Throwable $e) {
            xeric_web_json(['error' => $e->getMessage()], 422);
        }
        xeric_web_json(['ok' => true] + $r);
    }

    if ($action === 'launch') {
        $t = $w['template'];
        // A DRAFT MAY BE EMPTY; A LAUNCHED XERIC MAY NOT. The save below would
        // refuse it anyway — dropping review_pending makes the validator strict
        // — but it would refuse it in the validator's words, which name a JSON
        // path. This is the same rule said by somebody who knows what you were
        // trying to do.
        if ((array)($t['cast']['characters'] ?? []) === []) {
            xeric_web_json(['error' => 'There is nobody in this xeric yet, so there is nobody to go in '
                . 'and see. Add a person first.', 'kind' => 'empty'], 409);
        }
        if ((array)($t['places'] ?? []) === []) {
            xeric_web_json(['error' => 'There is nowhere in this xeric yet. Add a place first.',
                            'kind' => 'empty'], 409);
        }
        unset($t['forge']['review_pending']);
        $t['forge']['launched_at'] = gmdate('c');
        try {
            xeric_review_save($slug, $t);
        } catch (Throwable $e) {
            xeric_web_json(['error' => $e->getMessage()], 422);
        }
        $url = 'play.php?w=' . rawurlencode($slug);
        // A form POST (the no-JavaScript path from the launch gate) gets a
        // redirect. Answering it with JSON would put a raw object on the screen,
        // which is the one thing an error state is never allowed to be.
        if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'form-urlencoded')) {
            header('Location: ' . $url, true, 303);
            exit;
        }
        xeric_web_json(['ok' => true, 'url' => $url]);
    }

    // -- inject a story ------------------------------------------------------
    // The overlay door: somebody's own paragraph becomes a mystery laid over a
    // town that keeps running underneath it. An overlay edits nothing, so this
    // is safe on a world somebody has lived in for a week — and closing the
    // story later leaves the template byte-for-byte the one that was always
    // there. One model call, detached, watched through progress.php.
    if ($action === 'story') {
        if (!$w['mine']) xeric_web_json(['error' => 'Only the owner writes into a xeric.'], 403);
        $ask = trim((string)($in['ask'] ?? ''));
        if (mb_strlen($ask) > 2000) $ask = mb_substr($ask, 0, 2000);

        xeric_limit_guard(xeric_limit_check('reroll', ['sid' => $sid]));

        $endpoint = xeric_review_pick_endpoint((array)($in['model'] ?? []));
        if ($endpoint['kind'] !== 'local' && trim((string)$endpoint['key']) === '') {
            xeric_web_json(['error' => 'That machine is an API and needs a key.', 'kind' => 'needs_key'], 400);
        }
        if (!xeric_llm_up($endpoint, 8)) {
            xeric_web_json(['error' => 'The model at ' . $endpoint['base'] . ' is not answering, so there '
                . 'is nothing to write the story with. Your xeric is exactly as you left it.',
                'kind' => 'model_down'], 503);
        }
        if (xeric_queue_drained()) {
            $r = xeric_queue_drained_no();
            xeric_web_json(['error' => (string)$r['message'], 'kind' => 'drained', 'retry_after' => 120], 503);
        }
        $why    = null;
        $ticket = xeric_queue_join('reroll', $sid, $why);
        if ($ticket === '') {
            $r = is_array($why) ? $why : xeric_queue_no('full', 'reroll');
            xeric_web_json(['error' => (string)$r['message'], 'kind' => (string)($r['kind'] ?? 'full'),
                            'retry_after' => (int)($r['retry_after'] ?? 60)],
                           ($r['kind'] ?? '') === 'yours' ? 429 : 503);
        }

        $job = xeric_web_job_new();
        xeric_web_job_sweep();
        try {
            xeric_web_spawn($job, ['slug' => $slug, 'ask' => $ask, 'sid' => $sid,
                                   'ticket' => $ticket, 'endpoint' => $endpoint], 'story-worker.php');
        } catch (Throwable $e) {
            xeric_queue_leave($ticket);
            xeric_web_json(['error' => 'that story could not be started: ' . $e->getMessage()], 500);
        }
        xeric_limit_note('reroll', ['sid' => $sid]);
        xeric_web_json(['ok' => true, 'job' => $job]);
    }

    if ($action === 'reroll') {
        $what = (string)($in['what'] ?? '');
        // 'draft' is the whole book again — every section in one job. Not in
        // the sections list because it is not a section of anything.
        if ($what !== 'draft' && !isset(xeric_review_sections()[$what])) {
            xeric_web_json(['error' => 'that is not a section of this xeric'], 400);
        }
        if ($what === 'seed' && $w['lived']) {
            xeric_web_json(['error' => 'This xeric has already been opened, so its past is in its database now. '
                . 'Rewriting the file would not change what anybody remembers.'], 409);
        }
        // A draft is redraftable until it is LAUNCHED. `lived` is too strict a
        // gate here: merely glancing at the play page bakes a database, but
        // launch is the door — before it, nothing in that database is anything
        // a human did, and the redraft sweeps it out along with the old draft.
        if ($what === 'draft' && $w['launched']) {
            xeric_web_json(['error' => 'This xeric is live. A whole new draft would put its people through '
                . 'a world that no longer matches what they remember; reroll sections instead.'], 409);
        }

        xeric_limit_guard(xeric_limit_check('reroll', ['sid' => $sid]));

        // A reroll defaults to the local model — a key that had to ride every
        // small call would be a key sitting in a browser all week. But a world
        // forged by a frontier model gets a visibly worse character back from a
        // 12B, so a key MAY be supplied for this one call. It is used and
        // dropped: never stored, never logged, never written to the job file
        // (xeric_web_spawn hands it to the worker on stdin).
        // WHICH MACHINE, AS AN INDEX. The page used to hand over a whole
        // descriptor typed into three browser prompts; now it names a row of
        // the visitor's own machine list and the server looks it up. Same
        // boundary the forge keeps: a page that can post a URL makes the
        // machines screen advisory.
        $endpoint = xeric_review_pick_endpoint((array)($in['model'] ?? []));
        if ($endpoint['kind'] !== 'local' && trim((string)$endpoint['key']) === '') {
            xeric_web_json(['error' => 'That machine is an API and needs a key. Type one in the '
                . 'reroll panel and press the reroll again.', 'kind' => 'needs_key'], 400);
        }
        if (!xeric_llm_up($endpoint, 8)) {
            xeric_web_json([
                'error' => 'The model at ' . $endpoint['base'] . ' is not answering, so nothing can be '
                    . 'rewritten just now. Your xeric is exactly as you left it.',
                'kind'  => 'model_down',
            ], 503);
        }

        // Both refusals are BUILT, never fetched by re-entering the line. Taking
        // a second ticket purely to read its sentence can win the model on a line
        // that just emptied and then walk away still holding it.
        if (xeric_queue_drained()) {
            $r = xeric_queue_drained_no();
            xeric_web_json(['error' => (string)$r['message'], 'kind' => 'drained', 'retry_after' => 120], 503);
        }
        $why = null;
        $ticket = xeric_queue_join('reroll', $sid, $why);
        if ($ticket === '') {
            $r = is_array($why) ? $why : xeric_queue_no('full', 'reroll');
            // 'yours' is the per-visitor cap and is the visitor's own doing: 429.
            xeric_web_json(['error' => (string)$r['message'], 'kind' => (string)($r['kind'] ?? 'full'),
                            'retry_after' => (int)($r['retry_after'] ?? 60)],
                           ($r['kind'] ?? '') === 'yours' ? 429 : 503);
        }

        $job = xeric_web_job_new();
        xeric_web_job_sweep();
        try {
            // the endpoint (with any key) rides the payload, which goes to the
            // worker on stdin — never argv, never the job file, never a log
            xeric_web_spawn($job, ['slug' => $slug, 'what' => $what, 'index' => (int)($in['index'] ?? -1),
                                   'sid' => $sid, 'ticket' => $ticket,
                                   'endpoint' => $endpoint], 'reroll-worker.php');
        } catch (Throwable $e) {
            xeric_queue_leave($ticket);
            xeric_web_json(['error' => 'that reroll could not be started: ' . $e->getMessage()], 500);
        }

        xeric_limit_note('reroll', ['sid' => $sid]);

        $first = xeric_web_job_await($job, 8.0);
        if ($first !== null && $first['k'] === 'error') {
            $kind = (string)($first['rec']['kind'] ?? 'reroll');
            xeric_web_json(['error' => (string)$first['rec']['message'], 'kind' => $kind],
                           in_array($kind, ['queued', 'drained', 'full'], true) ? 503 : 500);
        }
        if ($first === null && !is_file(xeric_web_job_path($job))) {
            xeric_web_json(['error' => 'that reroll did not start, the process never answered'], 500);
        }
        xeric_web_json(['ok' => true, 'job' => $job, 'what' => $what]);
    }

    xeric_web_json(['error' => 'no such action'], 400);
}

// ---------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------

try {
    $w = xeric_review_open($slug, $sid);
} catch (Throwable $e) {
    xeric_web_head('Xeric: review');
    echo '<main><div class="top"><p class="wordmark">XERIC</p><span class="kicker">review</span></div>';
    echo '<h1>That xeric will not open</h1><p class="note bad">' . h($e->getMessage()) . '</p>';
    echo '<p><a href="play.php">The xerics that are here →</a></p></main>';
    exit;
}

// THE PAGE IS THE SAME SECRET THE ACTIONS ARE. It prints every knowledge wall
// with its hidden list, every character's drives, every special role's
// must_not_know and the whole baked past — which is the point when it is your
// world and a peephole when it is somebody else's. The actions above have always
// refused a stranger; the page simply never asked.
if (!$w['mine']) {
    xeric_web_head('Xeric: review');
    echo '<main><div class="top"><p class="wordmark">XERIC</p><span class="kicker">review</span>'
        . xeric_web_meter_html() . '<span class="count"><a href="play.php">all xerics</a></span></div>';
    echo '<h1>' . h((string)$w['template']['meta']['name']) . '</h1>';
    echo '<p class="note warn">This xeric was forged in a different browser, so its workings are not yours '
        . 'to read. What everybody in it privately wants, and what one of them is not allowed to know, are '
        . 'the whole of what makes it worth walking into, and they only work while they are still a '
        . 'surprise.</p>';
    echo '<p><a href="play.php?w=' . h(rawurlencode($w['slug'])) . '">Play your own copy of it →</a>'
        . '<br><a href="forge.php">Forge one that is yours →</a></p></main>';
    exit;
}

$T    = $w['template'];
$left = xeric_limit_left($sid);
$queue = xeric_queue_status();
// A page, not an endpoint: it renders whether or not a machine is attached, and
// the reroll buttons are what stop working — which the page can say.
// EVERY CONNECTED MACHINE, so a reroll can be aimed the same way a build is.
// Keys preserved: what rides the request is an INDEX into this visitor's own
// list, resolved server-side, exactly as build.php does it.
$allMachines = xeric_model_list($sid);
$machines    = array_intersect_key($allMachines, array_flip(xeric_model_wired_at($allMachines, $sid)));
$engineAt    = xeric_model_active($allMachines, $sid);

try { $local = xeric_play_endpoint(); }
catch (Throwable $e) { $local = ['kind' => 'none', 'base' => '', 'model' => '', 'key' => '']; }
$localUp = xeric_llm_up($local, 3);

xeric_web_head('Xeric: review ' . (string)$T['meta']['name']);
echo '<style>' . xeric_play_css() . xeric_review_css() . '</style>';
?>
<main>
  <div class="top">
    <p class="wordmark">XERIC</p>
    <span class="kicker">review</span>
    <?= xeric_web_meter_html() ?>
    <span class="count"><a href="play.php">all xerics</a></span>
  </div>

  <!-- The name, and the one control with nothing behind it. Beside the title
       on this page for the same reason it is beside the title on the play
       screen: it deletes THIS xeric, and a delete button that is not next to
       the thing it deletes is a delete button somebody presses by accident. -->
  <div class="titlerow">
    <h1><?= h((string)$T['meta']['name']) ?></h1>
    <button type="button" class="xdel" id="xericdel"
            title="delete <?= h((string)$T['meta']['name']) ?>" aria-label="delete this xeric">✕</button>
  </div>
  <?php $rslug = xeric_forge_slug((string)$T['meta']['name']); ?>
  <?php if ($rslug !== '' && $rslug !== $w['slug']): ?>
    <!-- A RENAMED BOOK ON AN OLD SPINE. The slug is the directory and the URL,
         fixed at forge time; a concept reroll can rename the world over it.
         Offered, never automatic: renaming breaks every link that exists. -->
    <p class="renamebar"><code>/<?= h($w['slug']) ?></code>
      <button type="button" class="linkbtn" id="renamebtn" data-to="<?= h($rslug) ?>"
        title="Move this xeric to /<?= h($rslug) ?>. Old links and bookmarks stop working.">rename the address to /<?= h($rslug) ?></button>
    </p>
  <?php endif; ?>
  <!-- the back cover, on the workbench too: rewritten whenever a repass
       corrects the book underneath it -->
  <blockquote class="cover" id="cover" data-w="<?= h($w['slug']) ?>"<?=
      trim((string)($T['meta']['teaser'] ?? '')) === '' ? ' data-teaser="1"' : '' ?>><?=
      h(trim((string)($T['meta']['teaser'] ?? ''))) ?></blockquote>
  <!-- who you are in it, then what it IS: the same information the result
       screen carries, at the exact spot the eye lands before deciding what
       to retype and what to launch. -->
  <?php
    $uWork = xeric_world_place_name($T, (string)($T['user']['occupation']['workplace_key'] ?? ''));
    $uJob  = trim((string)($T['user']['occupation']['title'] ?? ''));
    $uHrs  = trim((string)($T['user']['occupation']['hours'] ?? ''));
  ?>
  <p class="whoami">You are <b><?= h(trim((string)($T['user']['name'] ?? '')) ?: 'you') ?></b><?php
    if ($uJob !== '') echo ', ' . h($uJob);
    if ($uWork !== '') echo ' at ' . h($uWork);
    if ($uHrs !== '') echo ', ' . h($uHrs);
  ?>.</p>
  <ul class="facts">
    <?php if (trim((string)($T['setting']['locale'] ?? '')) !== ''): ?><li><?= h((string)$T['setting']['locale']) ?></li><?php endif; ?>
    <?php if (trim((string)($T['setting']['era'] ?? '')) !== ''): ?><li><?= h((string)$T['setting']['era']) ?></li><?php endif; ?>
    <?php
      // The rating wears its broadcast name here, and when the cast has a
      // child the pin is said OUT LOUD: a minor renders at TV-G in every
      // world whatever this line says, and a reviewer reading "TV-MA" beside
      // a twelve-year-old deserves to be told the engine already knows.
      $revMinor = false;
      foreach ((array)($T['cast']['characters'] ?? []) as $rc) { if (xeric_is_minor((array)$rc)) { $revMinor = true; break; } }
    ?>
    <li>rating: <?= h(xeric_rating_label((string)($T['meta']['rating'] ?? 'sfw'))) ?><?=
      $revMinor && xeric_rating_rank((string)($T['meta']['rating'] ?? 'sfw')) > 0
        ? ' — children in this cast are always written TV-G, whatever this says' : '' ?></li>
    <?php if (!empty($T['meta']['themes'])): ?><li><?= h(implode(' · ', (array)$T['meta']['themes'])) ?></li><?php endif; ?>
    <?php if (trim((string)($T['user']['motivation'] ?? '')) !== ''): ?><li>here for: <?= h((string)$T['user']['motivation']) ?></li><?php endif; ?>
  </ul>
  <p class="sub"><?= $w['launched']
      ? 'This xeric is live. Everything below is still yours to change, an edit lands the moment you '
        . 'click away from the box, and the people in it will be different the next time you speak to them.'
      : 'Nothing here is final yet. Read it, retype anything that is wrong, reroll anything that is dull, '
        . 'then launch it.' ?></p>

  <?php if (!$localUp): ?>
    <p class="note bad">Your engine is not answering at <?= h((string)$local['base']) ?>, so nothing can be
      rerolled right now. Editing by hand still works, that never touches a model.</p>
  <?php elseif ($queue['drained']): ?>
    <p class="note warn">The machine's owner has the GPU back for a while. Editing by hand still works;
      rerolls cannot start until they are done.</p>
  <?php elseif ($queue['busy']): ?>
    <p class="note warn">The model is busy with something else right now
      (<?= h(xeric_queue_phrase((int)$queue['depth'] + 1, (int)$queue['eta'])) ?>). Press a reroll anyway, you keep your place, and it starts by itself when it is your turn.</p>
  <?php endif; ?>

  <noscript><div class="noscript"><strong>The review step needs JavaScript.</strong> Edits save as you make
    them and rerolls stream what the model is doing. Without it, this page is a read-only listing.</div></noscript>

  <div class="launchbar" id="launchbar">
    <?php if (!$w['launched'] && $w['mine']): ?>
      <button class="btn" type="button" id="launch">Launch this xeric →</button>
      <button class="linkbtn" type="button" id="undo" title="Put back the version from before the last change">↺ undo the last change</button>
      <!-- THE LABEL IS THE STATE, THE TOOLTIP IS THE ACTION, and it used to be
           the other way round on one control: the words said "reroll with the
           local model" while the tooltip said "use a paid API", so reading them
           together told you nothing about which one you were doing.

           It also said "the local model" when a reroll goes to the ENGINE,
           whatever that is — this page hands the server nothing and the server
           uses xeric_play_endpoint(). Somebody whose engine is an API was being
           told their rerolls were local. -->
      <button class="linkbtn" type="button" id="usekey" aria-expanded="false" aria-controls="rpick"
              title="Choose which machine rewrites these sections">⚙ change engine for rerolls</button>
      <span class="st" id="launchst">It is not playable until you do.</span>
    <?php else: ?>
      <a class="btn" href="play.php?w=<?= h(rawurlencode($w['slug'])) ?>">▶ go in</a>
      <span class="st">Live.<?php if (xeric_limit_on()): ?> <?= (int)$left['rerolls'] ?> of <?= (int)$left['of']['rerolls'] ?> rerolls left this hour.<?php endif; ?></span>
    <?php endif; ?>
  </div>

  <!-- THE LITERARY REPASS. One button, findings underneath. Each finding names
       what it is about; one with a rewrite in hand carries an apply button that
       goes through a=edit — the same door as typing, so the age floor and the
       undo hold, and ↺ on the launchbar takes any of it back. -->
  <div class="repassbar">
    <button class="greenbtn" type="button" id="repass"
            title="An editor reads the whole xeric once: contradictions, the plot's through-line, and the story snake's pacing. Rewrites it can carry go straight in; ↺ takes the pass back.">📖 1 literary repass</button>
    <?php if (!$w['launched']): ?>
    <button class="linkbtn dicebig" type="button" id="draftagain"
            title="The whole book again: same interview answers, same address, every sentence up for replacement. One ↺ step brings this draft back.">🎲 Draft again</button>
    <?php endif; ?>
    <button class="redbtn" type="button" id="repassall"
            title="Warning: this uses a lot of tokens, and it makes several passes. It reads, rewrites, and reads again until no contradictions remain.">Clear All Contradictions</button>
    <span class="st" id="repassst"></span>
    <ul class="rfind" id="rfind" hidden></ul>
  </div>

  <?php if ($w['mine']): ?>
  <!-- THE STORY DOOR. An overlay is laid OVER a world and edits nothing in it,
       which is why this is safe on a town somebody has been living in for a
       week: close the story later and the template is byte-for-byte the one
       that was always there. The box takes a paragraph, not a genre — "the
       pastor's brother turns up owing money to the wrong person" is a story;
       "a murder" is a category. -->
  <div class="repassbar">
    <label class="st" for="storyask">Lay a story over this xeric</label>
    <textarea id="storyask" rows="3" placeholder="What happens? Whose fault is it, and who must never find out? A paragraph in your own words — or leave it empty and let the model find the story this town is already holding."></textarea>
    <div>
      <button class="greenbtn" type="button" id="storygo"
              title="One model call. Writes story-KEY.json beside this world; the next open finds it and lays it on. It edits nothing — closing the story later leaves the world exactly as it is now.">🔎 Write the story</button>
      <span class="st" id="storyst"></span>
    </div>
    <ul class="rfind" id="storyout" hidden></ul>
  </div>
  <?php endif; ?>

  <?php if (!$w['launched'] && $w['mine'] && $machines !== []): ?>
    <!-- THE SAME CHOOSER AS THE FORGE, in the same markup, because it is the
         same decision: which machine does this piece of work. It replaced three
         browser prompts asking for an API kind, a key and a model name, typed
         from memory, with no way to see whether any of it was reachable.

         Closed until asked for: a reroll normally goes to the engine and this
         panel is for the times it should not. -->
    <div class="rpick" id="rpick" hidden>
      <ul class="mlist forgeat" id="rlist">
        <?php foreach ($machines as $i => $row):
            $mk = xeric_model_kind((string)$row['base']);
            $on = $i === $engineAt; ?>
          <li>
            <div class="opt<?= $on ? ' on' : '' ?>" data-i="<?= $i ?>" data-kind="<?= h($mk) ?>">
              <button type="button" class="mpick" aria-label="reroll with this machine"
                      aria-pressed="<?= $on ? 'true' : 'false' ?>"></button>
              <span class="thead">
                <span class="t"><?= h((string)preg_replace('#^https?://#', '', $row['base'])) ?></span>
                <?= xeric_web_meter_html($sid, (string)$row['base']) ?>
              </span>
              <p class="whois" data-i="<?= $i ?>" hidden>
                <span class="wsig" aria-hidden="true"></span><span class="wname"></span> <span class="wmodel"></span>
              </p>
              <span class="status">
                <span class="dot" data-i="<?= $i ?>"></span><span class="said" data-i="<?= $i ?>"
                  data-up="<?= $mk === 'local' ? 'Local AI Available' : 'Available' ?>"><?=
                  $mk === 'local' ? 'asking…' : 'needs a key' ?></span>
                <span class="wired<?= $on ? ' on' : ' no' ?>">
                  <span class="dot"></span><?= $on ? 'rerolling with this' : 'Not selected' ?></span>
              </span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <!-- NO PROSE IN HERE. What each thing is belongs in its tooltip; a panel
           that explains itself in paragraphs is a panel nobody reads twice. -->
      <div class="byo" id="rbyo">
        <div class="row">
          <label class="field" for="r-key">api key</label>
          <input type="password" id="r-key" autocomplete="off" autocapitalize="off" autocorrect="off"
                 spellcheck="false" placeholder="sk-…"
                 title="Used for the rerolls on this page and nothing else. Never written to disk, never put in a URL, gone when you leave.">
        </div>
      </div>
      <p class="rmore"><a href="model.php" title="Add a machine, or connect one you already have">machines</a></p>
    </div>
  <?php endif; ?>

  <p class="jump">
    <a href="#sec-concept">the xeric</a> <a href="#sec-places">places</a> <a href="#sec-cast">cast</a>
    <a href="#sec-walls">walls</a> <a href="#sec-protagonist">whose story</a> <a href="#sec-seed">the past</a>
    <a href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>">what they are told →</a>
  </p>

  <?= xeric_review_body_html($w) ?>

  <footer>
    <code>worlds/<?= h($w['slug']) ?>/</code>, a template and its baked past, two files on this server.
    <br><a href="world.php?w=<?= h(rawurlencode($w['slug'])) ?>">the raw template</a> ·
    <a href="forge.php?w=<?= h(rawurlencode($w['slug'])) ?>">how it was forged</a> ·
    <a href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>">the inspector</a>
  </footer>
</main>

<script>
(function () {
  'use strict';
  var W = <?= json_encode($w['slug']) ?>;
  var MINE = <?= $w['mine'] ? 'true' : 'false' ?>;
  // The world's own name, for the one dialog that has to say what it is about
  // to destroy. Read at page load: a rename reloads, so it cannot go stale.
  var WNAME = <?= json_encode((string)$T['meta']['name']) ?>;

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // -- edit by hand --------------------------------------------------------
  // Saved on blur, not on every keystroke: a validator run per character would
  // be a model of a world rebuilt forty times a sentence. The old value is kept
  // in the DOM so a refusal can put it straight back.
  // -- the dice --------------------------------------------------------------
  // Rolls one field from everything else already written, drops the answer in
  // the box, and then SAVES IT THE ORDINARY WAY — so a rolled value meets the
  // same age floor and the same wall checks a typed one does, and lands with the
  // same green tick. Escape puts back whatever was there, exactly as it does
  // while typing, so a roll you did not like costs nothing.
  function bindDice(root) {
    $$('.dice', root).forEach(function (d) {
      if (d.dataset.bound) return;
      d.dataset.bound = '1';
      d.addEventListener('click', function () {
        var box = d.parentElement.querySelector('.ed');
        if (!box || d.disabled) return;
        var sec = d.closest('.sec'), err = sec ? $('.secerr', sec) : null;
        var before = box.value;

        d.disabled = true;
        d.classList.add('rolling');
        fetch('review.php?a=roll', { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ world: W, path: d.dataset.path, model: rerollModel() }) })
          .then(function (r) { return r.json().then(function (x) { return { ok: r.ok, d: x }; }); })
          .then(function (res) {
            d.disabled = false;
            d.classList.remove('rolling');
            if (!res.ok || !res.d.value) {
              if (err) { err.textContent = res.d.error || 'the dice came back empty'; err.hidden = false; }
              return;
            }
            if (err) err.hidden = true;
            box.dataset.was = before;      // Escape still puts back what was there
            box.value = res.d.value;
            save(box);
          },
          function (e) {
            d.disabled = false;
            d.classList.remove('rolling');
            if (err) { err.textContent = 'the forge could not be reached, ' + e.message; err.hidden = false; }
          });
      });
    });
  }
  bindDice(document);

  // -- the story door --------------------------------------------------------
  // One press, one detached call, the stream's own notes underneath. The
  // overlay lands on disk; the next open finds it. Nothing here edits the
  // world, so there is nothing to undo and nothing to reload.
  (function () {
    var go = document.getElementById('storygo');
    if (!go) return;
    var st = document.getElementById('storyst');
    var out = document.getElementById('storyout');
    var say = function (msg, bad) {
      out.hidden = false;
      var li = document.createElement('li');
      li.textContent = msg;
      if (bad) li.className = 'bad';
      out.appendChild(li);
    };
    go.addEventListener('click', function () {
      go.disabled = true;
      out.hidden = true; out.innerHTML = '';
      st.textContent = 'writing…';
      fetch('review.php?a=story&w=' + encodeURIComponent(W), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ask: (document.getElementById('storyask').value || '').trim(),
                               model: rerollModel() })
      }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok || !res.d.ok) {
            go.disabled = false; st.textContent = '';
            say((res.d && res.d.error) || 'that did not start', true);
            return;
          }
          var es = new EventSource('progress.php?job=' + encodeURIComponent(res.d.job));
          es.addEventListener('hello', function (m) { st.textContent = JSON.parse(m.data).text || 'working'; });
          es.addEventListener('queue', function (m) { st.textContent = JSON.parse(m.data).text || 'waiting'; });
          es.addEventListener('note',  function (m) { say(JSON.parse(m.data).text || ''); });
          es.addEventListener('done',  function (m) {
            es.close(); go.disabled = false; st.textContent = '';
            var d = JSON.parse(m.data);
            say(d.text || 'written');
            if (d.story) {
              say('“' + d.story.title + '” — ' + d.story.logline);
              say('It is laid over this xeric now. Open it and start asking.');
            }
          });
          es.addEventListener('error', function (m) {
            es.close(); go.disabled = false; st.textContent = '';
            var t = 'the story fell over';
            try { t = JSON.parse(m.data).text || t; } catch (e) {}
            say(t, true);
          });
        })
        .catch(function (e) { go.disabled = false; st.textContent = ''; say('could not reach the forge', true); });
    });
  })();

  // -- the literary repass ---------------------------------------------------
  // Findings land under the button. "show" scrolls to the field a finding is
  // about and flashes it; "apply the rewrite" saves the carried fix through the
  // ordinary edit door, so Escape-discipline, the age floor and ↺ all hold.
  (function () {
    var b = $('#repass');
    if (!b || !MINE) { if (b) b.disabled = true; return; }
    var st = $('#repassst'), box = $('#rfind');

    function findField(path) {
      var el = document.querySelector('.ed[data-path="' + path + '"]');
      return el || null;
    }

    // A finding is a sentence; a fixed one says so and the field on this very
    // page already carries the correction. No buttons: the editor edits.
    function row(f) {
      var li = document.createElement('li');
      li.className = 'rf ' + f.kind + (f.fixed ? ' done' : '');
      var head = document.createElement('span');
      head.className = 'rfk';
      head.textContent = { consistency: 'contradiction', plot: 'plot', snake: 'the snake' }[f.kind] || f.kind;
      var say = document.createElement('span');
      say.className = 'rfs';
      say.textContent = f.about + ': ' + f.say;
      li.appendChild(head);
      li.appendChild(say);

      if (f.fixed) {
        var ok = document.createElement('span');
        ok.className = 'rfok';
        ok.textContent = 'corrected ✓';
        ok.title = f.fix;
        li.appendChild(ok);
        // the box on this page now shows the corrected line
        var el = findField(f.path);
        if (el) { el.value = f.fix; el.dataset.was = f.fix; el.classList.add('saved'); }
      }
      if (f.path && findField(f.path)) {
        var show = document.createElement('button');
        show.type = 'button';
        show.className = 'linkbtn';
        show.textContent = 'show';
        show.title = 'Scroll to the line this is about';
        show.addEventListener('click', function () {
          var el = findField(f.path);
          if (!el) return;
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
          el.classList.remove('flash');
          void el.offsetWidth;
          el.classList.add('flash');
        });
        li.appendChild(show);
      }
      return li;
    }

    var b2 = $('#repassall');

    // the back cover on this page, rewritten when a repass changes the book
    var cov = $('#cover');
    function writeCover(force) {
      if (!cov || !cov.dataset.w || cov.dataset.writing === '1') return;
      cov.dataset.writing = '1';
      cov.textContent = force ? 'the back cover is being rewritten…' : 'the back cover is being written…';
      cov.classList.add('writing');
      fetch('forge.php?a=teaser', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ xeric: cov.dataset.w, fresh: force ? 1 : 0 }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          cov.dataset.writing = '';
          cov.classList.remove('writing');
          if (d && d.tokens && window.xericMeterFeed) window.xericMeterFeed(d.tokens);
          cov.textContent = (d && d.ok && d.teaser) ? d.teaser : '';
        })
        .catch(function () { cov.dataset.writing = ''; cov.classList.remove('writing'); cov.textContent = ''; });
    }
    if (cov && cov.dataset.teaser && MINE) writeCover(false);

    var rn = $('#renamebtn');
    if (rn) rn.addEventListener('click', function () {
      if (!confirm('Move this xeric to /' + rn.dataset.to + '? Old links and bookmarks stop working.')) return;
      rn.disabled = true;
      fetch('review.php?a=rename', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: W }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok && d.url) { window.location = d.url; return; }
          rn.disabled = false;
          rn.textContent = (d && d.error) || 'the rename fell over';
        })
        .catch(function () { rn.disabled = false; rn.textContent = 'the rename could not be reached'; });
    });

    // THE ONE WITH NOTHING BEHIND IT. The browser's own confirm, because it is
    // the one dialog on this page that cannot be mistaken for part of the app —
    // and it names the world and says what goes with it, since "are you sure?"
    // on its own tells nobody what they are agreeing to. This page has no toast,
    // so a refusal is spoken by the title attribute of the button itself.
    var dl = $('#xericdel');
    if (dl && MINE) dl.addEventListener('click', function () {
      if (!confirm('Delete ' + WNAME + '?\n\nEverybody in it, everything that has happened to them, '
                 + 'and every hour it has lived goes with it. This cannot be undone.')) return;
      dl.disabled = true;
      fetch('review.php?a=delete', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: W }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) { window.location = d.url || 'play.php'; return; }
          dl.disabled = false;
          dl.title = (d && d.error) || 'that xeric would not go';
        })
        .catch(function () { dl.disabled = false; dl.title = 'that did not reach the shelf'; });
    });
    else if (dl) dl.hidden = true;   // somebody else's xeric: not yours to delete

    function repassOnce(cb, fail, extra) {
      var body = { world: W, model: rerollModel() };
      if (extra) for (var k in extra) body[k] = extra[k];
      fetch('review.php?a=repass', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body) })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (res.d && res.d.tokens && window.xericMeterFeed) window.xericMeterFeed(res.d.tokens);
          if (!res.ok) { fail(res.d.error || 'the repass fell over'); return; }
          cb(res.d);
        }, function (e) { fail('the repass could not be reached, ' + e.message); });
    }

    function paintPass(d) {
      box.innerHTML = '';
      (d.findings || []).forEach(function (f) { box.appendChild(row(f)); });
      box.hidden = (d.findings || []).length === 0;
    }

    function lock(on) {
      b.disabled = on;
      if (b2) b2.disabled = on;
      var _da = $('#draftagain');
      if (_da) _da.disabled = on;
    }

    b.addEventListener('click', function () {
      lock(true);
      st.textContent = 'reading the whole xeric, this is a couple of model passes…';
      box.hidden = true;
      box.innerHTML = '';
      repassOnce(function (d) {
        lock(false);
        var fs = d.findings || [];
        if (!fs.length) {
          st.textContent = 'Nothing to report: no contradictions the editor could find, and the '
            + 'snake holds its shape. That is a pass, not a blank.';
          return;
        }
        var n = d.fixed || 0;
        st.textContent = fs.length + ' finding' + (fs.length === 1 ? '' : 's') + ', '
          + n + ' correction' + (n === 1 ? '' : 's') + '.'
          + (n ? ' Already in, ↺ takes the whole pass back.' : '');
        paintPass(d);
        if (d.teaser_stale) writeCover(true);
      }, function (msg) { lock(false); st.textContent = msg; });
    });

    // THE BIG RED BUTTON, as a ledger and a judge. Counting passes measured
    // the detector's mood: every pass re-samples a stochastic finder, so
    // fresh complaints appear forever and zero certifies nothing. The loop is
    // over FINDINGS now. Each contradiction is judged individually and cold
    // against the line as it stands on disk, and every one terminates:
    // rewrite verified closed, judged not real twice, or handed to a human
    // after two failed rewrites. A finder round only exists to feed the
    // ledger, and three rounds with a growing settled list is the ceiling.
    var LG = {};                                  // fp -> finding state
    function fp(f) { return f.path + '|' + f.say.slice(0, 60); }

    function ledgerRow(k) {
      var e = LG[k];
      if (!e.li) {
        e.li = document.createElement('li');
        e.li.className = 'rf consistency';
        e.li.innerHTML = '<span class="rfk">contradiction</span><span class="rfs"></span><span class="rfv"></span>';
        box.appendChild(e.li);
        box.hidden = false;
      }
      e.li.querySelector('.rfs').textContent = e.about + ': ' + e.say;
      var v = e.li.querySelector('.rfv');
      v.textContent = e.label || '';
      v.title = e.why || '';
      e.li.className = 'rf consistency' + (e.state === 'resolved' ? ' done'
        : e.state === 'noise' ? ' noise' : e.state === 'hand' ? ' hand'
        : e.state === 'pair' ? ' pair' : '');
    }
    function setState(k, state, label, why) {
      LG[k].state = state;
      LG[k].label = label;
      LG[k].why = why || '';
      ledgerRow(k);
    }

    function jfetch(action, body, cb, fail) {
      body.world = W;
      body.model = rerollModel();
      fetch('review.php?a=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body) })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (res.d && res.d.tokens && window.xericMeterFeed) window.xericMeterFeed(res.d.tokens);
          if (!res.ok) { fail((res.d && res.d.error) || (action + ' fell over')); return; }
          cb(res.d);
        }, function (e) { fail(action + ' could not be reached, ' + e.message); });
    }

    function clearAll(maxRounds) {
      var ROUNDS = maxRounds || 3, round = 0, anyFixed = false;
      LG = {};
      lock(true);
      box.hidden = true;
      box.innerHTML = '';

      function counts() {
        var c = { resolved: 0, noise: 0, hand: 0, pair: 0 };
        for (var k in LG) if (LG[k].state in c) c[LG[k].state]++;
        return c;
      }
      function finish(extra) {
        lock(false);
        var c = counts();
        var bits = [];
        if (c.resolved) bits.push(c.resolved + ' corrected and verified');
        if (c.noise) bits.push(c.noise + ' judged not real');
        // A `pair` still standing at the end is a real contradiction whose
        // other end never got cleared — for the human, like a `hand`, but it
        // says WHICH line to look at rather than "the rewriter gave up".
        var left = c.hand + c.pair;
        var head = left === 0
          ? 'All contradictions clear' + (bits.length ? ': ' + bits.join(', ') + '.' : '.')
          : 'Clear except ' + left + ': ' + (bits.length ? bits.join(', ') + ', ' : '')
            + left + ' flagged for you.';
        st.textContent = head + (extra ? '  ' + extra : '');
        if (anyFixed) writeCover(true);
      }

      // one finding, driven to a terminal state, then cb()
      function settle(k, cb) {
        var e = LG[k];
        if (!e.path) { setState(k, 'hand', 'needs a hand', 'no single line to rewrite'); cb(); return; }
        // NOTHING TO REWRITE ON THIS END. The sweep asked for a correction and
        // got back the line already on disk, which is the editor saying the
        // other half of the pair is the wrong one. Judging and re-rewriting it
        // would spend four model calls to be told the same thing twice and
        // then mislabel a correct line as needing a hand. Left unfrozen, so
        // the next round asks again once the other end has had its turn.
        if (e.noop) {
          setState(k, 'pair', 'the other line is the one to fix',
                   e.why || 'this end reads correctly; its twin is what disagrees');
          cb(); return;
        }
        var tries = e.fixed ? 1 : 0;   // the sweep already spent one rewrite on it
        var noise = 0;
        (function judge() {
          setState(k, 'open', 'judging…');
          jfetch('verify', { path: e.path, say: e.say }, function (v) {
            if (!v.still) {
              if (e.fixed || tries > 0) { setState(k, 'resolved', '✓ verified closed', v.why); cb(); return; }
              noise++;
              if (noise >= 2) { setState(k, 'noise', 'judged not real', v.why); cb(); return; }
              setState(k, 'open', 'judging again…');
              judge();                                   // a second, independent no
              return;
            }
            if (tries >= 2) { setState(k, 'hand', 'needs a hand', v.why); cb(); return; }
            tries++;
            setState(k, 'open', 'rewriting…', v.why);
            jfetch('refix', { path: e.path, say: e.say, why: v.why }, function (r) {
              if (r.teaser_stale) anyFixed = true;
              if (r.noop) {
                e.noop = true;
                setState(k, 'pair', 'the other line is the one to fix',
                         'the rewrite came back as the line already there');
                cb(); return;
              }
              if (!r.fixed) { setState(k, 'hand', 'needs a hand', r.note || 'no usable rewrite'); cb(); return; }
              e.fixed = true;
              judge();                                   // the rewrite faces the same judge
            }, function (msg) { setState(k, 'hand', 'needs a hand', msg); cb(); });
          }, function (msg) { setState(k, 'hand', 'needs a hand', msg); cb(); });
        })();
      }

      (function nextRound() {
        round++;
        if (round > ROUNDS) { finish('(' + ROUNDS + ' rounds)'); return; }
        st.textContent = 'round ' + round + ': the editor is reading…';
        // `pair` is deliberately NOT settled: the finding is real and its cure
        // is on the other line, so freezing this one would stop the next round
        // from noticing when that cure lands.
        var settled = [];
        for (var k in LG) if (LG[k].state !== 'open' && LG[k].state !== 'pair') settled.push(LG[k].path);
        repassOnce(function (d) {
          if (d.fixed) anyFixed = true;
          var work = [];
          (d.findings || []).forEach(function (f) {
            if (f.kind !== 'consistency') return;
            var k = fp(f);
            // `pair` is the one non-open state that comes back for another
            // look — its cure is on a line this finding does not own.
            if (LG[k] && LG[k].state !== 'open' && LG[k].state !== 'pair') return;
            if (!LG[k]) LG[k] = { path: f.path, say: f.say, about: f.about, fixed: !!f.fixed,
                                  noop: !!f.noop, state: 'open' };
            else { LG[k].fixed = LG[k].fixed || !!f.fixed; LG[k].noop = !!f.noop; LG[k].state = 'open'; }
            ledgerRow(k);
            work.push(k);
          });
          if (!work.length) { finish(round === 1 ? '' : '(round ' + round + ' found nothing new)'); return; }
          var i = 0;
          (function step() {
            if (i >= work.length) { nextRound(); return; }
            var k = work[i++];
            st.textContent = 'round ' + round + ' · ' + i + ' of ' + work.length + ' · '
              + (LG[k].about || '').slice(0, 40) + '…';
            settle(k, step);
          })();
        }, function (msg) { finish(msg); },
          { mode: 'sweep', frozen: settled.filter(Boolean) });
      })();
    }

    if (b2) b2.addEventListener('click', function () {
      if (!confirm('Are you sure? This reads the whole xeric, rewrites what it can, and puts every '
        + 'contradiction in front of a judge until each one is verified closed, dismissed as noise, '
        + 'or flagged for you. It can take several minutes and uses a lot of tokens.')) return;
      clearAll();
    });

    // 🎲 DRAFT AGAIN. One reroll job that re-runs every pass with the same
    // interview answers; the stream narrates into the status line and the
    // page reloads whole when it lands, because every section just changed.
    var da = $('#draftagain');
    if (da) da.addEventListener('click', function () {
      if (!confirm('Draft the whole xeric again? Same answers, every sentence up for replacement. '
        + 'One ↺ step brings this draft back.')) return;
      lock(true);
      da.disabled = true;
      box.hidden = true;
      st.textContent = 'drafting again…';
      fetch('review.php?a=reroll', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: W, what: 'draft', index: -1, model: rerollModel() }) })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok || !res.d.job) {
            lock(false); da.disabled = false;
            st.textContent = (res.d && res.d.error) || 'the forge would not start';
            return;
          }
          var es = new EventSource('progress.php?job=' + encodeURIComponent(res.d.job));
          es.addEventListener('meter', function (m) {
            try { var d = JSON.parse(m.data); if (window.xericMeterFeed) window.xericMeterFeed(d.by || {}); } catch (e) {}
          });
          es.addEventListener('note', function (m) {
            try { st.textContent = 'drafting again… ' + (JSON.parse(m.data).text || ''); } catch (e) {}
          });
          es.addEventListener('queue', function (m) {
            try { st.textContent = 'drafting again… ' + (JSON.parse(m.data).text || ''); } catch (e) {}
          });
          es.addEventListener('done', function () { es.close(); location.reload(); });
          es.addEventListener('error', function (m) {
            if (m && m.data) {
              es.close(); lock(false); da.disabled = false;
              try { st.textContent = JSON.parse(m.data).message || 'the draft fell over'; }
              catch (e) { st.textContent = 'the draft fell over'; }
            }
          });
        }, function (e) { lock(false); da.disabled = false; st.textContent = 'the forge could not be reached, ' + e.message; });
    });

    // Arriving from the forge page's red button: the confirm already happened
    // there, and the flag is stripped from the address at once so a refresh
    // or a shared link can never restart a token-heavy run by itself.
    if (b2 && new URLSearchParams(location.search).get('clear') === '1') {
      history.replaceState(null, '', location.pathname + '?w=' + encodeURIComponent(W));
      clearAll();
    }

    // STRUCTURAL REROLLS MANUFACTURE CONTRADICTIONS BY CONSTRUCTION: new rooms
    // under old prose, a new cast under old references (the Bend-versus-Reach
    // name drift was a concept reroll's work). So the reroll follower calls
    // this when one of those lands: one bounded sweep-and-judge round, not the
    // full certification, which stays on the red button.
    window.xericSweepAfterReroll = function () {
      if (!b || b.disabled) return;                 // a run is already going
      st.textContent = 'the reroll moved the furniture; reading it back for contradictions…';
      clearAll(1);
    };
  })();

  // -- one more of these ------------------------------------------------------
  // ADDING A LIST ITEM IS NOT A SERVER ROUND TRIP. The next index is a box that
  // does not exist yet, and the ordinary save path already knows how to write
  // one (xeric_review_growable). So this draws the box, binds it like any
  // other, and the first blur — or the first roll of its dice — is what creates
  // it. An empty box abandoned is an empty box: nothing was written.
  //
  // It exists for the xeric somebody started blank, which arrives with no
  // themes, no texture, no canon rules and no motifs, and which was therefore
  // the one kind of world this page could not finish.
  function bindAddField(root) {
    $$('.addfield', root).forEach(function (b) {
      if (b.dataset.bound) return;
      b.dataset.bound = '1';
      b.addEventListener('click', function () {
        var path = b.dataset.list + '.' + b.dataset.n;
        var id = 'f-' + path.replace(/[^a-z0-9]+/gi, '-');
        var box = b.dataset.kind === 'text'
          ? '<textarea class="ed" id="' + id + '" data-path="' + path + '" rows="2"></textarea>'
          : '<input class="ed" id="' + id + '" type="text" data-path="' + path
            + '" value="" autocomplete="off" spellcheck="false">';
        var fld = document.createElement('div');
        fld.className = 'fld';
        fld.innerHTML = '<span class="fldbox">' + box
          + '<button type="button" class="dice" data-path="' + path + '" tabindex="-1"'
          + ' title="Write this one for me, from everything else in this xeric">&#9860;</button>'
          + '</span><p class="ferr" hidden></p>';
        b.parentNode.insertBefore(fld, b);
        // The button now points one further along, so a second press adds a
        // second box rather than fighting the first for the same index.
        b.dataset.n = String(parseInt(b.dataset.n, 10) + 1);
        bindEdits(fld);
        var f = fld.querySelector('.ed');
        if (f) f.focus();
      });
    });
  }

  // -- the pronoun backfill ---------------------------------------------------
  // One press, one model call for the whole cast. The report lands in the
  // section log (what was written, who stays grey), and the section repaints
  // from disk — which is also what takes the button away once the cast is
  // whole, because the renderer never draws it for a complete cast.
  function bindPronounFill(root) {
    $$('.pronounfill', root).forEach(function (b) {
      if (b.dataset.bound) return;
      b.dataset.bound = '1';
      b.addEventListener('click', function () {
        if (running || !MINE || b.disabled) return;
        var sec = b.closest('.sec');
        var err = sec ? $('.secerr', sec) : null, log = sec ? $('.seclog', sec) : null;
        var was = b.textContent;
        b.disabled = true;
        b.textContent = 'asking…';
        fetch('review.php?a=pronouns', { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ world: W, model: rerollModel() }) })
          .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) {
            if (!res.ok) {
              b.disabled = false;
              b.textContent = was;
              if (err) { err.textContent = res.d.error || 'no answer came back'; err.hidden = false; }
              return;
            }
            if (err) err.hidden = true;
            if (log && res.d.note) { log.hidden = false; tlog(log, res.d.note); }
            repaint('cast');
          },
          function (e) {
            b.disabled = false;
            b.textContent = was;
            if (err) { err.textContent = 'the forge could not be reached, ' + e.message; err.hidden = false; }
          });
      });
    });
  }

  function bindEdits(root) {
    bindDice(root);
    bindAddField(root);
    bindPronounFill(root);
    $$('.ed', root).forEach(function (el) {
      if (el.dataset.bound) return;
      el.dataset.bound = '1';
      el.dataset.was = el.value;
      el.addEventListener('focus', function () { el.classList.remove('saved', 'bad'); });
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && el.tagName !== 'TEXTAREA') { e.preventDefault(); el.blur(); }
        if (e.key === 'Escape') { el.value = el.dataset.was; el.blur(); }
      });
      el.addEventListener('blur', function () { save(el); });
    });
  }

  function save(el) {
    if (!MINE) return;
    // THE FIELD, NOT THE BOX AROUND IT. This read el.parentNode, which was the
    // .fld — until the dice needed something to be positioned against and the
    // input gained a .fldbox wrapper. The error line is a sibling of that
    // wrapper, so every save started throwing on a null it used to find.
    var fld = el.closest('.fld') || el.parentNode;
    var err = fld.querySelector('.ferr');
    if (!err) {   // never fail a save over a missing line
      err = { hidden: true, textContent: '', classList: { add: function () {}, remove: function () {} } };
    }
    if (el.value === el.dataset.was) { err.hidden = true; return; }
    var want = el.value;

    fetch('review.php?a=edit', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ world: W, path: el.dataset.path, value: want }) })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.d.ok) {
          el.value = el.dataset.was;                 // the old value, still there
          el.classList.add('bad');
          err.classList.remove('kept');
          err.textContent = res.d.error || 'that could not be saved';
          err.hidden = false;
          return;
        }
        el.value = res.d.value;
        el.dataset.was = res.d.value;
        el.classList.add('saved');

        // Some edits change more than the box they were typed in — retyping
        // your motivation re-arms the systems, and the panel three inches below
        // would otherwise go on naming the old ones until a reload. The note
        // says what else moved; the stale list says what to redraw. On a LIVE
        // world every accepted edit carries one: what this save just did to the
        // running world. Information, not a refusal, hence the quiet class.
        if (res.d.note) { err.classList.add('kept'); err.textContent = res.d.note; err.hidden = false; }
        else { err.classList.remove('kept'); err.hidden = true; }
        (res.d.stale || []).forEach(repaint);
      })
      .catch(function (e) {
        el.value = el.dataset.was;
        el.classList.add('bad');
        err.classList.remove('kept');
        err.textContent = 'the forge could not be reached, ' + e.message + '. Nothing was saved.';
        err.hidden = false;
      });
  }
  bindEdits(document);

  // -- reroll one section --------------------------------------------------
  // Detached worker + the same SSE tail as the build and the time control
  // (progress.php). A reroll is one to five model calls and a proxy would cut a
  // held request long before the slow ones finish.
  var running = false;
  var ENGINE_AT = <?= (int)$engineAt ?>;

  // A reroll normally uses the local model. A world forged by a frontier model
  // gets a visibly worse character back from a 12B, so a key may be supplied for
  // this page's rerolls. It lives in this variable and nowhere else: never a
  // cookie, never storage, never a URL, gone the moment the page is left.
  //
  // ALL THREE OF THESE ARE PAGE-LEVEL, NOT PER-REROLL. Declared inside reroll()
  // the button was dead until the first reroll ran, every reroll wiped the key
  // before building its own request body, and each one added another click
  // handler — so a fourth press opened three stacked prompts.
  // -- which machine rerolls -----------------------------------------------
  // AN INDEX, NOT A DESCRIPTOR. This used to ask three browser prompts for an
  // API kind, a key and a model name — typed from memory, with no way to see
  // whether any of it was reachable, and a fourth press stacked three more
  // dialogs. It picks a row of the visitor's own machines now, the way the forge
  // does, and the server resolves it.
  //
  // THE KEY STILL LIVES IN ONE VARIABLE AND NOWHERE ELSE: never a cookie, never
  // storage, never a URL, gone the moment the page is left. That is why it is
  // read out of the field at request time rather than kept anywhere.
  var pickAt = ENGINE_AT;
  var panel = $('#rpick'), kb = $('#usekey');

  function pickCard() { return document.querySelector('#rlist .opt[data-i="' + pickAt + '"]'); }

  function rerollModel() {
    var k = $('#r-key');
    return { i: pickAt, key: k ? k.value : '' };
  }

  var LOCAL_LABEL = '⚙ change engine for rerolls';

  function paintPick() {
    $$('#rlist .opt').forEach(function (o) {
      var on = parseInt(o.dataset.i, 10) === pickAt;
      o.classList.toggle('on', on);
      o.querySelector('.mpick').setAttribute('aria-pressed', on ? 'true' : 'false');
      var w = o.querySelector('.wired');
      w.classList.toggle('on', on);
      w.classList.toggle('no', !on);
      w.lastChild.textContent = on ? 'rerolling with this' : 'Not selected';
    });
    var c = pickCard();
    var remote = !!c && c.dataset.kind !== 'local';
    $('#rbyo').classList.toggle('on', remote);
    if (kb) {
      kb.textContent = (pickAt === ENGINE_AT)
        ? LOCAL_LABEL
        : '⚙ rerolls use ' + (c ? c.querySelector('.t').textContent.trim() : 'another machine');
    }
  }

  if (panel && kb) {
    kb.addEventListener('click', function () {
      var open = panel.hidden;
      panel.hidden = !open;
      kb.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        probeAll();
        // Under a sticky bar, "it opened" and "you can see it" are different
        // claims. Ask for it to be on screen before deciding it is.
        panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    });
    // Escape closes it, because a panel that only shuts by finding the same
    // small button again is a panel that stays open.
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { panel.hidden = true; kb.setAttribute('aria-expanded', 'false'); kb.focus(); }
    });
    $$('#rlist .mpick').forEach(function (b) {
      b.addEventListener('click', function () {
        pickAt = parseInt(b.closest('.opt').dataset.i, 10);
        paintPick();
      });
    });
    paintPick();
  }

  // Is it answering, and what is it? model.php's probe, asked from here — one
  // implementation of that question for every screen that shows a machine.
  var probed = false;
  function probeAll() {
    if (probed) return;
    probed = true;
    $$('#rlist .dot[data-i]').forEach(function (el) {
      var i = el.dataset.i;
      var dot = document.querySelector('#rlist .status > .dot[data-i="' + i + '"]'),
          said = document.querySelector('#rlist .said[data-i="' + i + '"]'),
          who  = document.querySelector('#rlist .whois[data-i="' + i + '"]');
      fetch('model.php?a=probe&i=' + encodeURIComponent(i))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.who && d.who.name && who) {
            who.querySelector('.wname').textContent = d.who.name;
            who.querySelector('.wmodel').textContent = d.who.model || '';
            who.querySelector('.wsig').className = 'wsig ' + (d.who.local ? 'here' : 'away');
            who.hidden = false;
          }
          if (d.up === null) return;
          dot.classList.add(d.up ? 'up' : 'down');
          if (said) said.textContent = d.up ? (said.dataset.up || 'Available') : 'no answer';
        },
        function () {
          dot.classList.add('down');
          if (said) said.textContent = 'no answer';
        });
    });
  }

  // -- add a person --------------------------------------------------------
  // The cast section is repainted from disk afterwards by the same renderer that
  // drew it, so the new person arrives with every field editable and nothing on
  // screen can disagree with the file.
  function bindAdd(root) {
    bindAdder(root, '.addplace', 'addplace', 'places');
    bindAdder(root, '.addperson', 'add', 'cast');
  }

  // One binder for both, because they are the same act on different sections and
  // a second copy is a second place for the busy-state handling to drift.
  function bindAdder(root, sel, action, section) {
    $$(sel, root).forEach(function (b) {
      if (b.dataset.bound) return;
      b.dataset.bound = '1';
      b.addEventListener('click', function () {
        if (running || !MINE) return;
        var was = b.textContent;
        b.disabled = true;
        b.textContent = 'adding…';
        fetch('review.php?a=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ world: W }) })
          .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) {
            b.disabled = false;
            b.textContent = was;
            var sec = document.getElementById('sec-' + section);
            if (!res.ok) { fail(sec, res.d.error || 'that did not work'); return; }
            repaint(section);
          },
          function (e) {
            b.disabled = false;
            b.textContent = was;
            fail(document.getElementById('sec-' + section), 'the forge could not be reached, ' + e.message);
          });
      });
    });
  }
  bindAdd(document);

  function bindRerolls(root) {
    $$('.reroll', root).forEach(function (b) {
      if (b.dataset.bound) return;
      b.dataset.bound = '1';
      b.addEventListener('click', function () { reroll(b); });
    });
  }
  bindRerolls(document);

  function reroll(btn) {
    if (running || !MINE) return;
    var sec = btn.closest('.sec');
    var what = btn.dataset.what;
    var idx = btn.dataset.index === undefined ? -1 : Number(btn.dataset.index);
    var err = $('.secerr', sec), log = $('.seclog', sec), body = $('.secbody', sec);

    running = true;
    sec.classList.add('busy');
    err.hidden = true;
    log.hidden = false;
    log.innerHTML = '';
    tlog(log, 'asking the model…');

    fetch('review.php?a=reroll', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ world: W, what: what, index: idx, model: rerollModel() }) })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.d.job) { fail(sec, res.d.error || ('the forge answered ' + res.status)); return; }
        follow(sec, res.d.job, body, log);
      })
      .catch(function (e) { fail(sec, 'the forge could not be reached, ' + e.message); });
  }

  function tlog(log, text, warn) {
    var d = document.createElement('div');
    if (warn) d.className = 'w';
    d.textContent = text;
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
  }

  function done(sec) { running = false; sec.classList.remove('busy'); }

  // A section that another section's reroll invalidated, redrawn from disk.
  function repaint(key) {
    var sec = $('#sec-' + key);
    if (!sec) return;
    fetch('review.php?a=section&w=' + encodeURIComponent(W) + '&sec=' + encodeURIComponent(key))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        var body = $('.secbody', sec);
        body.innerHTML = d.html;
        bindEdits(body);
        bindRerolls(body);
      })
      .catch(function () {});
  }

  function fail(sec, msg) {
    done(sec);
    var err = $('.secerr', sec);
    err.textContent = msg;
    err.hidden = false;
  }

  function follow(sec, job, body, log) {
    var es = new EventSource('progress.php?job=' + encodeURIComponent(job));
    var drops = 0;

    // The meter, live, on the stream's own heartbeat. A reroll is one to five
    // model calls and they are spent whether or not anybody is told.
    es.addEventListener('meter', function (m) {
      try {
        var d = JSON.parse(m.data);
        if (window.xericMeterFeed) window.xericMeterFeed(d.by || {});
      } catch (e) {}
    });

    es.addEventListener('hello', function (m) { drops = 0; tlog(log, JSON.parse(m.data).text || 'started'); });
    es.addEventListener('queue', function (m) { drops = 0; tlog(log, JSON.parse(m.data).text); });
    es.addEventListener('note', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      tlog(log, '[' + Number(d.t).toFixed(1) + 's] ' + d.text, d.level === 'warn');
    });
    es.addEventListener('done', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      es.close();
      done(sec);
      // The section is repainted from what was SAVED, by the same renderer that
      // drew it the first time — never from the worker's idea of what it did.
      body.innerHTML = d.html;
      bindEdits(body);
      bindRerolls(body);
      tlog(log, ', done in ' + d.seconds + 's');
      if (d.name) { var hh = $('h1'); if (hh) hh.textContent = d.name; }
      // A reroll that came back identical, or that never reached a model, is
      // not saved — so nothing above changed and the notes are the whole story.
      // Saying so is the difference between "it did nothing" and "it is broken".
      if (d.saved === false) {
        var e2 = $('.secerr', sec);
        e2.textContent = 'Nothing was changed, this xeric is exactly as it was. '
          + 'The log above says why.';
        e2.hidden = false;
      }
      (d.stale || []).forEach(repaint);
      // New rooms under old prose, a new cast under old references: a
      // structural reroll that SAVED gets one sweep-and-judge round on the
      // house, because it just manufactured exactly what the sweep catches.
      if (d.saved !== false
          && ['places', 'cast', 'concept', 'character'].indexOf(d.what) >= 0
          && window.xericSweepAfterReroll) {
        window.xericSweepAfterReroll();
      }
    });
    es.addEventListener('failed', function (m) { es.close(); fail(sec, JSON.parse(m.data).message); });
    es.addEventListener('pause', function () { drops = 0; });
    es.onerror = function () {
      if (es.readyState === 2 || ++drops > 40) {
        es.close();
        fail(sec, 'the connection keeps dropping. The reroll may have finished, reload to see.');
      }
    };
  }

  // -- undo -----------------------------------------------------------------
  // The bulldozer guard: rerolling the whole cast clears the walls, the
  // protagonist and every special role, and before this there was no way back.
  var ub = $('#undo');
  if (ub) {
    ub.addEventListener('click', function () {
      ub.disabled = true;
      ub.textContent = 'putting it back…';
      fetch('review.php?a=undo', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: W }) })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok || !res.d.ok) {
            ub.disabled = false;
            ub.textContent = '↺ undo the last change';
            $('#launchst').textContent = (res.d && res.d.error) || 'nothing to undo';
            return;
          }
          location.reload();
        })
        .catch(function () {
          ub.disabled = false;
          ub.textContent = '↺ undo the last change';
          $('#launchst').textContent = 'could not reach the server';
        });
    });
  }

  // -- launch ---------------------------------------------------------------
  var lb = $('#launch');
  if (lb) {
    lb.addEventListener('click', function () {
      lb.disabled = true;
      $('#launchst').textContent = 'launching…';
      fetch('review.php?a=launch', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: W }) })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok || !res.d.ok) {
            lb.disabled = false;
            $('#launchst').textContent = res.d.error || 'that xeric would not launch';
            return;
          }
          window.location = res.d.url;
        })
        .catch(function (e) {
          lb.disabled = false;
          $('#launchst').textContent = 'the forge could not be reached, ' + e.message;
        });
    });
  }
})();
</script>
</html>
