<?php
/**
 * Xeric — the forge's story door. A paragraph in, a legal overlay out.
 *
 * THE ENGINE HAS HAD A MYSTERY IN IT FOR DAYS AND NO WAY IN. Overlays are
 * built, validated and tested — walls-as-mystery, beats, red herrings that
 * sincerely believe something false, resolution conditions, the residue on
 * close — and until now the only way to get one was to hand-write JSON that
 * satisfies a validator with sixty refusals in it. This is the door: somebody
 * describes the story they want, in their own words, and it lands.
 *
 * MODEL PROPOSES, CODE DISPOSES — the shape-builder's lesson applied to the
 * biggest structure in the app. The model is asked for the STORY: who did it,
 * who is dead, who must not find out, what the wrong leads are and why the
 * people holding them believe them. It is asked for none of the SCHEMA: not
 * the wall namespacing, not the beat positions, not the opens_when chain, not
 * the resolution's shape, not the story_version. Code assembles all of that,
 * so a model that writes a wonderful story and a malformed file still gets a
 * world that runs.
 *
 * AND THE SNAKE IS SIMPLY OMITTED. An overlay with no snake inherits the
 * world's own shape (engine/story.php), which means the pacing question was
 * already answered by whoever forged the world — and the beat-in-false-calm
 * rule relaxes for an inherited curve, so beats land where the story wants
 * them rather than where somebody else's rhythm allows.
 *
 * WHAT IT WILL NOT DO. It will not put a child in a node gated above sfw, or
 * name a minor as the culprit; it will not protect more people than the cap;
 * it will not point a red herring at the culprit (that is the answer, not a
 * wrong lead). Each of those is a validator refusal, and each is prevented
 * HERE rather than discovered there, because a refusal after three minutes of
 * model time is a worse experience than a story that quietly obeys the rules.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/forge.php';
require_once __DIR__ . '/../engine/story.php';

/**
 * A legal overlay, assembled from whatever the model said.
 *
 * Every structural decision is made here. The spec is read defensively: a
 * missing field becomes a default, an unusable one is dropped, and a story
 * that would break a rule is bent until it does not. What cannot be defaulted
 * — a culprit who is somebody, at least one beat — throws, because an overlay
 * with no answer in it is not a story.
 *
 * @param array $spec the model's own words, any shape
 * @throws RuntimeException when there is no story in the spec at all
 */
function xeric_forge_story_build(array $spec, array $t, string $key): array
{
    $chars = [];
    $minor = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $chars[$h] = $c;
        if (xeric_is_minor((array)$c)) $minor[$h] = true;
    }
    if (count($chars) < 3) throw new RuntimeException('story: this xeric is too small to hide anything in');

    $adults = array_keys(array_diff_key($chars, $minor));
    if ($adults === []) throw new RuntimeException('story: a story needs somebody old enough to have done it');

    // Roles the world already handed out: xeric_viewer() is last-wins, so an
    // overlay that protects somebody who already carries a role would silently
    // replace it. Those people are simply not eligible here.
    $taken = [];
    foreach ((array)($t['cast']['special_roles'] ?? []) as $sr) $taken[(string)($sr['character'] ?? '')] = true;

    // -- the culprit: an adult, and never the player's own protagonist -------
    $culprit = (string)($spec['culprit'] ?? '');
    if (!isset($chars[$culprit]) || isset($minor[$culprit])) $culprit = $adults[0];

    // -- the victim: a declared character, or a stranger the town discusses --
    $victim = [];
    $vh = (string)($spec['victim_character'] ?? '');
    if (isset($chars[$vh]) && $vh !== $culprit) {
        $victim = ['character' => $vh];
    } else {
        $vn = xeric_forge_str($spec['victim_name'] ?? '', 'a stranger nobody claimed', 80);
        $va = (int)($spec['victim_age'] ?? 0);
        $victim = [
            'name'     => $vn,
            'age'      => $va >= 18 && $va <= 110 ? $va : 58,
            'one_line' => xeric_forge_str($spec['victim_line'] ?? '', 'Known to enough people to be missed.', 200),
            'found'    => xeric_forge_str($spec['victim_found'] ?? '', 'Found in the morning, by somebody who was not looking.', 300),
        ];
    }

    // -- who must not find out, and the walls that hold it -------------------
    // The cap the validator enforces is half the cast; code stays under it
    // rather than discovering it, and a protected person is never the culprit
    // (they would be protected from their own knowledge) nor somebody the
    // world already gave a role to.
    $cap     = max(1, intdiv(count($chars), 2));
    $protect = [];
    $walls   = [];
    foreach ((array)($spec['protect'] ?? []) as $p) {
        if (count($protect) >= $cap) break;
        $h = (string)(is_array($p) ? ($p['character'] ?? '') : $p);
        $what = trim(xeric_forge_str(is_array($p) ? ($p['must_not_know'] ?? '') : '', '', 200));
        if (!isset($chars[$h]) || $h === $culprit || isset($taken[$h])) continue;
        if (isset($protect[$h])) continue;
        if ($what === '') $what = 'what really happened, and who did it';
        $wk = 'story.' . $key . '.' . $h . '_unaware';
        $walls[] = ['key' => $wk, 'audience' => ['handle' => $h],
                    'hidden' => ['story.' . $key . '.truth'],
                    'explain' => $what];
        $protect[$h] = ['character' => $h, 'role' => 'unaware', 'must_not_know' => $what, 'wall' => $wk];
    }

    // -- the beats: the story's order, positioned by code --------------------
    // The model says WHAT is learned and WHO holds it; the position on the
    // curve, the key, and the chain that makes each beat wait for the last
    // are all structure. Spread across 0.12..0.95 so the first is not the
    // opening frame and the last leaves room to close.
    $raw = array_values(array_filter((array)($spec['beats'] ?? []), 'is_array'));
    if ($raw === []) throw new RuntimeException('story: nothing to find out is not a mystery');
    $raw = array_slice($raw, 0, 6);
    $n   = count($raw);

    $beats = [];
    $prevKey = null;
    foreach ($raw as $i => $b) {
        $bk = 'b' . ($i + 1);
        $at = round(0.12 + ($n === 1 ? 0.5 : ($i / max(1, $n - 1)) * 0.83), 3);

        $holder = (string)($b['holder'] ?? '');
        if (!isset($chars[$holder]) || $holder === $culprit) $holder = '';

        $row = ['key' => $bk, 'at' => $at,
                'title' => xeric_forge_str($b['title'] ?? '', 'something comes out', 90)];
        if ($prevKey !== null) $row['opens_when'] = ['after' => [$prevKey], 'min_dwell_hours' => 6];

        if ($holder !== '') {
            // A held beat: somebody knows something and can be got to say it.
            // Four fields are required on one and every one is defaulted, so a
            // model that answered half the question still produces a story.
            $row['holder']       = $holder;
            $row['piece']        = xeric_forge_str($b['piece'] ?? '', 'what they saw and have not repeated', 300);
            $row['while_locked'] = xeric_forge_str($b['while_locked'] ?? '',
                'You have not told anybody this and you would rather not start.', 300);
            $row['when_open']    = xeric_forge_str($b['when_open'] ?? '',
                'You have said it out loud now, and it cannot be unsaid.', 300);
            $row['spilled_as']   = xeric_forge_str($b['spilled_as'] ?? '',
                'They told you what they saw.', 200);
            $row['spill_detect'] = 'auto';
        } else {
            // An unheld beat happens in the world instead: the sweep writes it
            // as an hour when the beat comes due.
            $pl = (string)($b['place'] ?? '');
            $row['as_event'] = [
                'title' => $row['title'],
                'prose' => xeric_forge_str($b['prose'] ?? '', 'It happened where anybody could see it.', 400),
            ];
            if (xeric_world_place($t, $pl) !== null) $row['as_event']['place'] = $pl;
        }
        $beats[] = $row;
        $prevKey = $bk;
    }

    // -- the wrong leads -----------------------------------------------------
    // A herring is a character who SINCERELY believes something false. The
    // validator refuses one that points at the culprit (that is the answer)
    // or at its own believer, so neither is ever built.
    $herrings = [];
    foreach (array_slice((array)($spec['red_herrings'] ?? []), 0, 3) as $i => $h) {
        if (!is_array($h)) continue;
        $bl = (string)($h['believer'] ?? '');
        if (!isset($chars[$bl])) continue;
        $pa = (string)($h['points_at'] ?? '');
        if (!isset($chars[$pa]) || $pa === $culprit || $pa === $bl) $pa = '';

        $row = [
            'key'       => 'h' . ($i + 1),
            'believer'  => $bl,
            'is_false'  => true,
            'belief'    => xeric_forge_str($h['belief'] ?? '', 'They have the wrong end of it entirely.', 300),
            'because'   => xeric_forge_str($h['because'] ?? '', 'Because of what they saw, which was not what they thought.', 300),
            'actually'  => xeric_forge_str($h['actually'] ?? '', 'The truth is duller and worse.', 300),
            'sincerity' => in_array((string)($h['sincerity'] ?? ''), ['certain', 'fairly_sure', 'wondering'], true)
                            ? (string)$h['sincerity'] : 'fairly_sure',
        ];
        if ($pa !== '') $row['points_at'] = $pa;
        $herrings[] = $row;
    }
    // Each wrong lead is disposed of exactly once, by a beat, and both halves
    // of that link have to agree — so the link is written from one side here.
    foreach ($herrings as $i => $h) {
        $onBeat = $beats[min($i + 1, count($beats) - 1)]['key'];
        $herrings[$i]['collapses_on'] = $onBeat;
        foreach ($beats as $j => $b) {
            if ($b['key'] !== $onBeat) continue;
            $beats[$j]['kills_herring'] = array_values(array_unique(
                array_merge((array)($b['kills_herring'] ?? []), [$h['key']])));
        }
    }

    // -- how it ends ---------------------------------------------------------
    // An accusation, said to somebody, provable only once the beats are in
    // hand — and a wrong one never closes the story, because a story that
    // ends when you are wrong is a quiz.
    $to = array_values(array_diff(array_keys($chars), [$culprit]));
    $story = [
        'story_version' => XERIC_STORY_VERSION,
        'key'           => $key,
        'title'         => xeric_forge_str($spec['title'] ?? '', 'the thing nobody says', 90),
        'for_world'     => (string)($t['meta']['name'] ?? ''),
        'source'        => 'model',
        'rating_min'    => 'sfw',
        'logline'       => xeric_forge_str($spec['logline'] ?? '', 'Something happened here and nobody has said what.', 300),
        'truth'         => xeric_forge_str($spec['truth'] ?? '', 'The obvious answer is the wrong one.', 600),
        'cast'          => ['culprit' => $culprit, 'victim' => $victim,
                            'protect' => array_values($protect)],
        'walls'         => $walls,
        'beats'         => $beats,
        'red_herrings'  => $herrings,
        'resolution'    => [
            'kind'           => 'accusation',
            'answer'         => $culprit,
            'requires_beats' => [$beats[count($beats) - 1]['key']],
            'accept'         => ['to' => array_slice($to, 0, 3)],
            'on_wrong'       => ['closes' => false],
        ],
    ];
    // The strange place is a gravity well, not a puzzle: a world that says its
    // rumor never pays out gets an overlay that promises not to solve it.
    if (!empty($t['mystery']['enabled']) && ($t['mystery']['rumor_pays_out'] ?? true) === false) {
        $story['resolution']['never'] = ['mystery.rumor'];
    }
    return $story;
}

/**
 * Ask a model for a story about THIS world, and build it into a legal overlay.
 *
 * The prompt hands over the cast, the rooms and what the world is about —
 * because a mystery that could have happened anywhere has not happened here —
 * and asks for the story in plain fields. Everything structural is this file's
 * (xeric_forge_story_build), so the model is never asked to be a schema.
 *
 * @param string $ask the player's own paragraph, or '' for the model's choice
 */
function xeric_forge_story(array $t, string $ask, array $endpoint, ?callable $onNote = null): array
{
    $cast = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $cast[] = '  ' . $h . ' — ' . (string)($c['display_name'] ?? $h)
                . ', ' . (int)($c['age'] ?? 0) . ($c['one_line'] ?? '' ? ', ' . (string)$c['one_line'] : '')
                . (xeric_is_minor((array)$c) ? '   [A CHILD — may witness, may never be the culprit]' : '');
    }
    $places = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $places[] = '  ' . (string)($p['key'] ?? '') . ' — ' . (string)($p['name'] ?? '');
    }

    $msgs = [
        ['role' => 'system', 'content' =>
            'You write the hidden story under an ordinary town: who did what, who saw, who is wrong '
            . 'about it, and who must never find out. Dime-store, not literary — a satisfying wrong '
            . 'lead beats an ambiguous one. Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            'The world: ' . (string)($t['meta']['name'] ?? '') . ' — '
            . (string)($t['meta']['description'] ?? $t['setting']['locale'] ?? '') . "\n\n"
            . "The people (use these handles exactly):\n" . implode("\n", $cast) . "\n\n"
            . "The rooms:\n" . implode("\n", $places) . "\n\n"
            . ($ask !== '' ? "What they asked for:\n" . $ask . "\n\n"
                           : "They did not say what they wanted. Find the story this town is already holding.\n\n")
            . "Reply exactly:\n{\n"
            . '  "title": "three or four words",' . "\n"
            . '  "logline": "one sentence a player may be shown before it resolves",' . "\n"
            . '  "truth": "what actually happened, in two or three sentences — the narrator holds this",' . "\n"
            . '  "culprit": "a handle, an adult, never a child",' . "\n"
            . '  "victim_character": "a handle if it is one of them, else omit",' . "\n"
            . '  "victim_name": "if a stranger", "victim_age": 60,' . "\n"
            . '  "victim_line": "one line about them", "victim_found": "how they were found",' . "\n"
            . '  "protect": [{"character": "handle", "must_not_know": "the exact thing they must not learn"}],' . "\n"
            . '  "beats": [3 to 5 of {"title": "…", "holder": "handle who knows it, or omit for something '
            . 'that happens in the world", "piece": "what they know", "while_locked": "how they deflect, '
            . 'in their own voice", "when_open": "how they are once it is out", "spilled_as": "the memory '
            . 'it leaves", "place": "a room key if unheld", "prose": "the hour, if unheld"}],' . "\n"
            . '  "red_herrings": [2 of {"believer": "handle", "belief": "what they are sure of", '
            . '"because": "why they believe it", "actually": "what was really going on", '
            . '"points_at": "a handle who is NOT the culprit", "sincerity": "certain|fairly_sure|wondering"}]' . "\n"
            . "}\n\n"
            . "The beats are the ORDER it comes out in: the first is a loose thread, the last is the "
            . "thing that makes it undeniable. A red herring is somebody being sincerely WRONG, never "
            . "somebody lying. No prose outside the JSON."],
    ];

    $out = xeric_forge_ask($endpoint, 'story', $msgs, ['temperature' => 0.9, 'max_tokens' => 2000], $onNote);

    // The key namespaces every wall and arc row this overlay writes, so it is
    // built from the title rather than taken from the model: a key with a dot
    // in it would write into another overlay's prefix.
    $key = mb_strtolower(xeric_forge_str($out['title'] ?? '', 'the quiet thing', 40));
    $key = trim((string)preg_replace('/[^a-z0-9]+/', '_', $key), '_');
    if ($key === '' || preg_match('/^[a-z0-9_]+$/', $key) !== 1) $key = 'story';

    $built = xeric_forge_story_build(is_array($out) ? $out : [], $t, $key);

    // VERIFIED THE WAY IT WILL BE LOADED. xeric_story_for() fills an absent
    // snake from the world's shape and marks it inherited before validating,
    // so that is exactly what is checked here — a story that would refuse to
    // load must never reach the disk, and finding that out now costs nothing
    // where finding it out at open costs somebody their world.
    xeric_story_validate(xeric_forge_story_probe($built, $t), $t, 'the story just written');

    xeric_forge_note($onNote, 'story: "' . $built['title'] . '" — ' . count($built['beats'])
        . ' beats, ' . count($built['red_herrings']) . ' wrong leads, '
        . count($built['cast']['protect']) . ' kept in the dark');
    return $built;
}

/** An overlay as the loader will see it: the world's shape filled in. */
function xeric_forge_story_probe(array $s, array $t): array
{
    if ((array)($s['snake']['curve'] ?? []) === []) {
        $s['snake'] = xeric_story_shape($t);
        $s['snake']['inherited'] = true;
    }
    return $s;
}

/**
 * Write it beside the world, where xeric_story_files() looks. One story per
 * key; rewriting the same key is how a reshaped story replaces its earlier
 * self rather than accumulating.
 */
function xeric_forge_story_save(array $s, string $dir): string
{
    $path = rtrim($dir, '/') . '/story-' . (string)$s['key'] . '.json';
    $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($path, $json) === false) {
        throw new RuntimeException('story: could not write ' . basename($path));
    }
    return $path;
}
