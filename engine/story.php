<?php
/**
 * Xeric — story overlays. A story that ends, laid over a world that does not.
 *
 * An overlay is content; how far through it you are is world data. The file is
 * read-only forever and every piece of progress lives in arcs, which is what
 * makes the one constraint this whole file is designed against enforceable:
 *
 *   CLOSING A STORY IS A SUBTRACTION, NOT AN EDIT.
 *
 * Nothing here writes into a world template. xeric_story_compose() builds a new
 * array on top of the one it was handed, and closing a story means no longer
 * composing it — after which the composed template is === the untouched one.
 * That is the first test in engine-test.php and every rule below exists to keep
 * it true. If a change to this file would make an overlay edit the world it sits
 * on, the change is wrong however convenient it is.
 *
 * Three more disciplines, all of them inherited rather than invented:
 *
 *  1. FAIL CLOSED GOVERNS KNOWLEDGE, NOT PROGRESS. Every gate on what a
 *     character may KNOW and every gate on a minor is closed by default, the way
 *     walls.php and world.php do it. The spill detector is deliberately the other
 *     way round: over-detecting moves the story on one beat early, under-detecting
 *     strands a player in front of somebody who has already told them, and of
 *     those two only the second is a bug. Story progress is not a safety property.
 *  2. TIME COMES FROM THE CALLER. Nothing here reads the wall clock. World epochs
 *     are passed in; the real-time `updated_at` of a row rides the optional $at
 *     the store already takes.
 *  3. THE COMPOSED TEMPLATE MUST BE CACHEABLE. What an overlay composes changes
 *     only when a beat actually opens or spills — a real state change, once. No
 *     number that ticks (progress, intensity, a countdown) is ever composed into
 *     the template, because the system message is assembled from it and a ticking
 *     field would drag a whole prompt out of cache every turn.
 *
 * PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/walls.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/sweeps.php';   // the kind names a thumb may push on
require_once __DIR__ . '/shape.php';    // the curve, which a world has with or without a story
require_once __DIR__ . '/death.php';    // a victim who is somebody you know

// ---------------------------------------------------------------------------
// Discovery + load
// ---------------------------------------------------------------------------

/**
 * The overlay files beside a world template, in filename order.
 *
 * Filename order is the only order, and it is stable across machines because it
 * is a plain byte sort rather than whatever the filesystem hands back. Order
 * matters for nothing that is dangerous — composition is add-only — but two
 * runs of the same world must still assemble the same bytes.
 */
function xeric_story_files(string $dir): array
{
    $found = glob(rtrim($dir, '/') . '/story-*.json') ?: [];
    sort($found, SORT_STRING);
    return $found;
}

/** Read and decode one overlay. Throws, naming the file, like the template loader. */
function xeric_story_read(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) throw new RuntimeException("xeric: cannot read story $path");
    $s = json_decode($raw, true);
    if (!is_array($s)) throw new RuntimeException('xeric: bad JSON in ' . basename($path) . ': ' . json_last_error_msg());
    return $s;
}

/** Every overlay in a world directory, decoded, unvalidated, in filename order. */
function xeric_story_load(string $dir): array
{
    $out = [];
    foreach (xeric_story_files($dir) as $p) $out[] = xeric_story_read($p);
    return $out;
}

/**
 * Discovery for a world that is about to run: load, drop what can never compose,
 * validate the rest.
 *
 * The rating skip is not the same event as a validation failure and must not
 * share its exit. An overlay whose rating_min is above the world's ceiling is an
 * AUTHORING error and xeric_story_validate() says so — but the same template
 * arrives here clamped for an unaffirmed session (xeric_world_clamp_rating), and
 * a world that refused to load because a story it was never going to show is
 * rated too high would be a session gate that breaks worlds. So: skipped, with a
 * note, and the world runs without it.
 *
 * @param ?callable $onNote fn(string $note): void — skips and warnings, in order
 */
function xeric_story_for(string $dir, array $t, ?callable $onNote = null): array
{
    $eff  = xeric_world_rating($t);
    $out  = [];
    foreach (xeric_story_files($dir) as $path) {
        $s     = xeric_story_read($path);
        $label = basename($path);
        if (xeric_rating_rank((string)($s['rating_min'] ?? 'sfw')) > xeric_rating_rank($eff)) {
            if ($onNote) $onNote("$label is rated above this world and does not compose");
            continue;
        }
        // THE WORLD'S SHAPE FALLS THROUGH TO ITS STORIES. An overlay that
        // declares its own snake keeps it — a mystery written to a particular
        // rhythm is not something a world setting gets to overrule — but one
        // that declares none inherits the world's, so a xeric forged with no
        // arc gives the stories laid on it no arc either, and a xeric forged
        // as a slow burn paces the mystery you inject the same way it paces
        // its own Tuesdays. Before validate(), because the filled snake is the
        // one that has to be legal, and every shape in the library is.
        if ((array)($s['snake']['curve'] ?? []) === []) {
            $s['snake'] = xeric_story_shape($t);
            // Marked, because the validator has one rule that only makes sense
            // against a curve the story's own author chose. See beats[].at.
            $s['snake']['inherited'] = true;
            if ($onNote) $onNote("$label declares no snake and takes the world's shape, "
                . xeric_story_shape_key($t));
        }
        xeric_story_validate($s, $t, $label);
        if ($onNote) foreach (xeric_story_warnings($s, $t) as $w) $onNote("$label: $w");
        $out[] = $s;
    }
    return $out;
}

function xeric_story_key(array $s): string
{
    return (string)($s['key'] ?? '');
}

/**
 * What to call this overlay in an ERROR when no filename is in hand — the file
 * it would have been written to, because an author reading a validation failure
 * is looking for a file. What to call it in front of a person is
 * xeric_story_title().
 */
function xeric_story_label(array $s): string
{
    $k = xeric_story_key($s);
    return $k === '' ? 'story' : 'story-' . $k . '.json';
}

/** What to call it out loud: the title it was given, or failing that its key. */
function xeric_story_title(array $s): string
{
    $t = trim((string)($s['title'] ?? ''));
    return $t !== '' ? $t : xeric_story_key($s);
}

/**
 * Things worth saying out loud that are not refusals.
 *
 * `for_world` is the important one: portability is a feature, so an overlay
 * written for another town is a note rather than a refusal — the handle, place
 * and beat checks in the validator are the real gate on whether it fits.
 */
function xeric_story_warnings(array $s, array $t): array
{
    $out  = [];
    $want = trim((string)($s['for_world'] ?? ''));
    $have = trim((string)($t['meta']['name'] ?? ''));
    if ($want !== '' && $have !== '' && $want !== $have) {
        $out[] = "was written for '$want' and this world is '$have'";
    }

    // A thumb on a kind this world never armed is inert, by design: arming stays
    // forge.armed and a story may never buy itself a system. Worth one line so
    // an author is not left wondering why the crescendo felt like a Tuesday.
    $armed = array_keys(xeric_sweep_kinds_for($t));
    $idle  = [];
    foreach ((array)($s['snake']['kind_thumb'] ?? []) as $stage => $thumb) {
        foreach ((array)$thumb as $k => $m) {
            if (!in_array((string)$k, $armed, true) && !in_array((string)$k, $idle, true)) $idle[] = (string)$k;
        }
    }
    if ($idle !== []) $out[] = 'this world arms none of ' . xeric_join_list($idle) . ', so those thumbs are inert';
    return $out;
}

// ---------------------------------------------------------------------------
// Validate
// ---------------------------------------------------------------------------

/**
 * Validate an overlay against the world it is to be laid over.
 *
 * Same contract as xeric_world_validate(): loud, at load time, naming the
 * offending path, because an author reading "invalid story" learns nothing. Each
 * rule below corresponds to a way an overlay can be wrong that would otherwise
 * surface as a mystery that cannot be solved, a wrong lead nothing disposes of,
 * or a wall that protects nobody.
 *
 * @throws RuntimeException e.g.
 *   "xeric: story-mill_stairwell.json: red_herrings[0].points_at names the culprit"
 */
function xeric_story_validate(array $s, array $t, string $label = ''): void
{
    $label = $label !== '' ? $label : xeric_story_label($s);
    $bad = function (string $path, string $problem) use ($label): void {
        throw new RuntimeException("xeric: $label: $path $problem");
    };

    // -- what the world declares, collected once ---------------------------
    $chars = [];
    $minors = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $chars[$h] = $c;
        if (xeric_is_minor((array)$c)) $minors[$h] = true;
    }
    $fixtures = [];
    foreach ((array)($t['cast']['fixtures'] ?? []) as $f) $fixtures[(string)($f['key'] ?? '')] = true;
    $places = [];
    foreach ((array)($t['places'] ?? []) as $p) $places[(string)($p['key'] ?? '')] = true;
    $rolesTaken = [];
    foreach ((array)($t['cast']['special_roles'] ?? []) as $sr) {
        $rolesTaken[(string)($sr['character'] ?? '')] = (string)($sr['role'] ?? '');
    }
    $knownKinds = array_keys(xeric_sweep_kinds());

    // -- identity ----------------------------------------------------------
    $key = xeric_story_key($s);
    if ($key === '' || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
        // The key namespaces every arc row and every wall this overlay creates.
        // A key with a dot or a colon in it would write into another overlay's
        // prefix, and two stories cannot be allowed to collide.
        $bad('key', "'$key' must be a lowercase slug (a-z, 0-9, underscore)");
    }
    if ((int)($s['story_version'] ?? 0) !== XERIC_STORY_VERSION) {
        $bad('story_version', 'must be ' . XERIC_STORY_VERSION);
    }
    if (trim((string)($s['logline'] ?? '')) === '') {
        $bad('logline', 'is required, it is the one string a player may be shown before this resolves');
    }
    if (trim((string)($s['truth'] ?? '')) === '') {
        $bad('truth', 'is required, the narrator is the viewer with nothing withheld and needs something to withhold');
    }
    $src = (string)($s['source'] ?? 'authored');
    if (!in_array($src, ['authored', 'model', 'reshaped'], true)) {
        $bad('source', "'$src' is not one of authored|model|reshaped");
    }

    // -- rating ------------------------------------------------------------
    $legal = xeric_ratings();
    $eff   = xeric_world_rating($t);
    $sr    = (string)($s['rating_min'] ?? 'sfw');
    if (!in_array($sr, $legal, true)) $bad('rating_min', "'$sr' is not one of " . implode('|', $legal));
    if (xeric_rating_rank($sr) > xeric_rating_rank($eff)) {
        // rating_min is a FLOOR on visibility and never a raise. An overlay above
        // the world's own ceiling would compose in no session that ever loaded
        // this template, which makes it an authoring mistake rather than a
        // preference. (A session clamped DOWN is a different event and is skipped
        // rather than refused — see xeric_story_for.)
        $bad('rating_min', "'$sr' is above this world's '$eff', it would never compose");
    }
    foreach (xeric_world_find_ratings($s, '') as [$rp, $rv]) {
        if (!in_array($rv, $legal, true)) $bad($rp, "'$rv' is not one of " . implode('|', $legal));
    }

    // -- cast --------------------------------------------------------------
    $culprit = (string)($s['cast']['culprit'] ?? '');
    if (!isset($chars[$culprit])) $bad('cast.culprit', "'$culprit' is not a declared character");

    // THE VICTIM MAY NOW BE SOMEBODY YOU KNOW.
    //
    // This used to be a phantom and nothing else — a name, an age, a one-line,
    // no dossier — because killing a cast member would have meant deleting
    // somebody from the template on injection and putting them back on close,
    // which is the one thing an overlay may not do. death.php removed that
    // constraint by making death a ROW rather than an edit: an overlay can now
    // kill a declared character without touching a single byte of the template.
    //
    // So both forms are legal, and they are genuinely different stories:
    //   { "character": "harlan" }              somebody the player has been texting
    //   { "name": "…", "age": 74, … }          a stranger the town is talking about
    //
    // `character` wins when both are given, and it supplies the name and the age
    // from the cast, which is also why the age requirement lifts for that form —
    // a declared character already carries a required integer age that
    // xeric_is_minor() reads.
    $victim = (array)($s['cast']['victim'] ?? []);
    $vwho   = trim((string)($victim['character'] ?? ''));

    if ($vwho !== '') {
        if (!isset($chars[$vwho]))  $bad('cast.victim.character', "'$vwho' is not a declared character");
        if ($vwho === $culprit)     $bad('cast.victim.character', 'is also the culprit, a story needs two people');
    } else {
        if (trim((string)($victim['name'] ?? '')) === '') {
            $bad('cast.victim.name', 'is required (or name a declared character with cast.victim.character)');
        }
        if (!is_int($victim['age'] ?? null)) {
            $bad('cast.victim.age', 'is required and must be an integer, has ' . json_encode($victim['age'] ?? null));
        }
    }

    $wallKeys = [];
    foreach ((array)($s['walls'] ?? []) as $i => $w) {
        $wk = (string)($w['key'] ?? '');
        if ($wk === '')            $bad("walls[$i].key", 'is required and must be a non-empty string');
        if (isset($wallKeys[$wk])) $bad("walls[$i].key", "'$wk' is declared twice");
        if (!str_starts_with($wk, 'story.' . $key . '.')) {
            $bad("walls[$i].key", "'$wk' must be namespaced 'story.$key.', an overlay may not write an unprefixed name");
        }
        $wallKeys[$wk] = true;
    }

    $protect = (array)($s['cast']['protect'] ?? []);
    $cap     = intdiv(count($chars), 2);
    if (count($protect) > $cap) {
        // xeric_sweep_choose() excludes EVERY protected handle from a spine hour.
        // Protect five of six and there is never anybody left to have one, spine
        // hours quietly stop happening, and a mystery with no offscreen motion is
        // a lookup table.
        $bad('cast.protect', 'protects ' . count($protect) . ' of ' . count($chars) . ", the cap is $cap");
    }
    foreach ($protect as $i => $p) {
        $h = (string)($p['character'] ?? '');
        if (!isset($chars[$h])) $bad("cast.protect[$i].character", "'$h' is not a declared character");
        if (isset($rolesTaken[$h])) {
            // xeric_viewer() merges wall keys from every matching role but takes
            // `role` last-wins, so two roles on one handle is an ambiguous
            // audience selector — and ambiguity in the wall layer is the one
            // thing this codebase refuses outright.
            $bad("cast.protect[$i].character", "'$h' already carries special_role '{$rolesTaken[$h]}', role is last-wins in xeric_viewer()");
        }
        if (trim((string)($p['must_not_know'] ?? '')) === '') $bad("cast.protect[$i].must_not_know", 'is required');
        if (!isset($wallKeys[(string)($p['wall'] ?? '')])) {
            $bad("cast.protect[$i].wall", "'" . (string)($p['wall'] ?? '') . "' is not a wall this overlay declares");
        }
    }

    // -- snake -------------------------------------------------------------
    $curve = (array)($s['snake']['curve'] ?? []);
    if (count($curve) < 4) $bad('snake.curve', 'needs at least four control points');
    $prev = -1.0;
    foreach ($curve as $i => $pt) {
        if (!is_array($pt) || count($pt) !== 2) $bad("snake.curve[$i]", 'must be [progress, intensity]');
        if ((float)$pt[0] <= $prev)             $bad("snake.curve[$i]", 'progress must strictly increase');
        if ((float)$pt[1] < 0.0 || (float)$pt[1] > 1.0) $bad("snake.curve[$i]", 'intensity must be 0..1');
        $prev = (float)$pt[0];
    }
    if ((float)$curve[0][0] !== 0.0 || (float)$curve[count($curve) - 1][0] !== 1.0) {
        $bad('snake.curve', 'must span progress 0..1');
    }

    $fc = array_map('floatval', (array)($s['snake']['false_calm'] ?? []));
    if (count($fc) !== 2 || $fc[0] >= $fc[1] || $fc[0] < 0.0 || $fc[1] > 1.0) {
        $bad('snake.false_calm', 'must be an ordered window inside 0..1');
    }
    // The window and the flat of the curve are the same two numbers ON PURPOSE.
    // Let them differ and there is a stretch named calm while the pace is still
    // coming down, and the ×1.0 claim below stops being arithmetic.
    $flat = xeric_story_intensity($curve, $fc[0]);
    if ($flat !== 0.5) {
        $bad('snake.false_calm', 'starts at intensity ' . $flat . ', the false calm is the world at exactly its own pace, which is 0.5');
    }
    if (xeric_story_intensity($curve, $fc[1]) !== 0.5) {
        $bad('snake.false_calm', 'the curve is not flat at 0.5 across the declared window');
    }
    foreach ($curve as $i => $pt) {
        if ((float)$pt[0] > $fc[0] && (float)$pt[0] < $fc[1] && (float)$pt[1] !== 0.5) {
            $bad("snake.curve[$i]", 'sits inside the false calm at an intensity other than 0.5');
        }
    }

    $swing = (float)($s['snake']['pace_swing'] ?? 0.6);
    if ($swing < 0.0 || $swing > 0.9) $bad('snake.pace_swing', 'must be 0..0.9');

    foreach ((array)($s['snake']['kind_thumb'] ?? []) as $stage => $thumb) {
        if (!in_array((string)$stage, xeric_story_stages(), true)) {
            $bad("snake.kind_thumb.$stage", 'is not a stage the curve produces');
        }
        foreach ((array)$thumb as $k => $m) {
            if (!in_array((string)$k, $knownKinds, true)) $bad("snake.kind_thumb.$stage.$k", 'is not a kind the engine knows');
            // A thumb is not a gate. It re-weights what the world already armed
            // and can never delete a kind — a zero would be a story switching a
            // system off, which is arming in the other direction.
            if ((float)$m <= 0.0) $bad("snake.kind_thumb.$stage.$k", 'must be positive, a thumb never deletes a kind');
        }
    }

    // -- beats -------------------------------------------------------------
    $beats = (array)($s['beats'] ?? []);
    if ($beats === []) $bad('beats', 'must be a non-empty list');

    $herringKeys = [];
    foreach ((array)($s['red_herrings'] ?? []) as $h) $herringKeys[(string)($h['key'] ?? '')] = true;

    $beatKeys = [];
    $prevAt   = -1.0;
    foreach ($beats as $i => $b) {
        $bk = (string)($b['key'] ?? '');
        if ($bk === '')            $bad("beats[$i].key", 'is required and must be a non-empty string');
        if (isset($beatKeys[$bk])) $bad("beats[$i].key", "'$bk' is declared twice");

        $at = (float)($b['at'] ?? -1);
        if ($at < 0.0 || $at > 1.0) $bad("beats[$i].at", 'must be 0..1');
        if ($at <= $prevAt)         $bad("beats[$i].at", 'must strictly increase, the beats are the story\'s order');
        // NOT WHEN THE SNAKE WAS INHERITED. This is an AUTHORING check — you
        // wrote a beat into your own quiet stretch and probably did not mean to
        // — and there is no author to tell when the curve came from the world
        // instead of the file. The person who chose their xeric's rhythm never
        // saw this story's beats; the person who wrote the beats never saw that
        // rhythm. Refusing to load is the worst of the three available outcomes,
        // so an inherited collision is a warning (xeric_story_warnings) and the
        // story runs — the same call this file already makes for `for_world`,
        // and for the same reason: portability is a feature.
        if ($at > $fc[0] && $at < $fc[1] && empty($s['snake']['inherited'])) {
            $bad("beats[$i].at", 'falls inside the false calm, where nothing may open');
        }
        $prevAt = $at;

        $holder = $b['holder'] ?? null;
        if ($holder === null) {
            if (!isset($b['as_event'])) $bad("beats[$i].as_event", 'a beat with no holder must declare one');
            $pl = (string)($b['as_event']['place'] ?? '');
            if ($pl !== '' && !isset($places[$pl])) $bad("beats[$i].as_event.place", "'$pl' is not a declared place");
            $wk = (string)($b['as_event']['wants_kind'] ?? '');
            if ($wk !== '' && !in_array($wk, $knownKinds, true)) $bad("beats[$i].as_event.wants_kind", "'$wk' is not a kind the engine knows");
        } else {
            $holder = (string)$holder;
            if (!isset($chars[$holder])) {
                $bad("beats[$i].holder", "'$holder' is not a declared character" . (isset($fixtures[$holder]) ? ', scenery has no interior to hold a piece in' : ''));
            }
            foreach (['piece', 'while_locked', 'when_open', 'spilled_as'] as $req) {
                if (trim((string)($b[$req] ?? '')) === '') $bad("beats[$i].$req", 'is required on a held beat');
            }
            // The age floor, in the only direction it points: a child may hold a
            // piece, be a witness and be why a story is solvable. What he may not
            // be is the subject of a node gated above sfw, because a minor never
            // renders above sfw and content that can never render is content
            // nobody should be writing about a child. Same rule, same reason and
            // the same shape of message as flirt_style on a minor.
            if (isset($minors[$holder])) {
                foreach (xeric_world_find_ratings($b, "beats[$i]") as [$rp, $rv]) {
                    if (xeric_rating_rank($rv) > 0) {
                        $bad($rp, "gates content above sfw on '$holder', who is a minor, a minor never renders above sfw, so the node is unreachable");
                    }
                }
            }
        }

        foreach ((array)($b['opens_when']['after'] ?? []) as $j => $a) {
            if (!isset($beatKeys[(string)$a])) $bad("beats[$i].opens_when.after[$j]", "'$a' is not an EARLIER beat");
        }
        foreach ((array)($b['kills_herring'] ?? []) as $j => $hk) {
            if (!isset($herringKeys[(string)$hk])) $bad("beats[$i].kills_herring[$j]", "'$hk' is not a declared red herring");
        }
        $detect = (string)($b['spill_detect'] ?? 'quote');
        if (!in_array($detect, ['quote', 'auto', 'manual'], true) && !str_starts_with($detect, 'marker:')) {
            $bad("beats[$i].spill_detect", "'$detect' must be quote|auto|manual|marker:X");
        }
        $beatKeys[$bk] = true;
    }

    // -- red herrings ------------------------------------------------------
    $killedBy = [];
    foreach ($beats as $b) {
        foreach ((array)($b['kills_herring'] ?? []) as $hk) $killedBy[(string)$hk][] = (string)$b['key'];
    }

    foreach ((array)($s['red_herrings'] ?? []) as $i => $h) {
        $hk = (string)($h['key'] ?? '');
        if ($hk === '') $bad("red_herrings[$i].key", 'is required');
        $bl = (string)($h['believer'] ?? '');
        if (!isset($chars[$bl])) {
            $bad("red_herrings[$i].believer", "'$bl' is not a declared character" . (isset($fixtures[$bl]) ? ', scenery has no interior to be wrong in' : ''));
        }
        // is_false exists to make the ENGINE's knowledge explicit. A field that
        // may be omitted or set false is a field that drifts into meaning
        // nothing, and a wrong lead that is true is a wall — write a wall.
        if (($h['is_false'] ?? null) !== true) {
            $bad("red_herrings[$i].is_false", 'must be exactly true, a lead that is true is a wall, not a herring');
        }
        if (trim((string)($h['belief'] ?? '')) === '')   $bad("red_herrings[$i].belief", 'is required');
        if (trim((string)($h['because'] ?? '')) === '')  $bad("red_herrings[$i].because", 'is required, a belief with no grounds does not survive one question');
        if (trim((string)($h['actually'] ?? '')) === '') $bad("red_herrings[$i].actually", 'is required, an unexplained wrong lead is a dead end');
        $sinc = (string)($h['sincerity'] ?? '');
        if (!in_array($sinc, ['certain', 'fairly_sure', 'wondering'], true)) {
            $bad("red_herrings[$i].sincerity", "'$sinc' must be certain|fairly_sure|wondering");
        }

        $pa = $h['points_at'] ?? null;
        if ($pa !== null) {
            $pa = (string)$pa;
            if (!isset($chars[$pa]))  $bad("red_herrings[$i].points_at", "'$pa' is not a declared character");
            if ($pa === $culprit)     $bad("red_herrings[$i].points_at", 'names the culprit, that is the answer, not a wrong lead');
            if ($pa === $bl)          $bad("red_herrings[$i].points_at", 'names the believer themselves');
        }
        foreach ((array)($h['known_false_to'] ?? []) as $j => $kf) {
            if (!isset($chars[(string)$kf])) $bad("red_herrings[$i].known_false_to[$j]", "'$kf' is not a declared character");
        }

        $co = (string)($h['collapses_on'] ?? '');
        if ($co !== 'resolution' && !isset($beatKeys[$co])) {
            $bad("red_herrings[$i].collapses_on", "'$co' is neither a declared beat nor 'resolution'");
        }
        if (isset($beatKeys[$co]) && !in_array($co, (array)($killedBy[$hk] ?? []), true)) {
            $bad("red_herrings[$i].collapses_on", "names '$co', which does not list this herring in kills_herring, both halves must agree");
        }
        if (count((array)($killedBy[$hk] ?? [])) > 1) {
            $bad("red_herrings[$i]", 'is killed by more than one beat, a wrong lead is disposed of once');
        }
        if (isset($minors[$bl])) {
            foreach (xeric_world_find_ratings($h, "red_herrings[$i]") as [$rp, $rv]) {
                if (xeric_rating_rank($rv) > 0) {
                    $bad($rp, "gates content above sfw on '$bl', who is a minor, a minor never renders above sfw, so the node is unreachable");
                }
            }
        }
    }

    // -- resolution --------------------------------------------------------
    $r    = (array)($s['resolution'] ?? []);
    $kind = (string)($r['kind'] ?? '');
    if (!in_array($kind, ['accusation', 'possession', 'arrival', 'marker'], true)) {
        $bad('resolution.kind', "'$kind' must be accusation|possession|arrival|marker");
    }
    // Not optional on any kind: naming the right person on day one is a guess,
    // and the story closes when the player can SHOW it.
    if ((array)($r['requires_beats'] ?? []) === []) {
        $bad('resolution.requires_beats', 'is required and must be non-empty, a guess is not a solution');
    }
    foreach ((array)($r['requires_beats'] ?? []) as $j => $rb) {
        if (!isset($beatKeys[(string)$rb])) $bad("resolution.requires_beats[$j]", "'$rb' is not a declared beat");
    }
    if ($kind === 'accusation') {
        $ans = (string)($r['answer'] ?? '');
        if (!isset($chars[$ans])) $bad('resolution.answer', "'$ans' is not a declared character");
        if ($ans !== $culprit)    $bad('resolution.answer', "'$ans' is not the culprit this overlay declared");
        if ((array)($r['accept']['to'] ?? []) === []) $bad('resolution.accept.to', 'is required, an accusation is said to somebody');
        foreach ((array)($r['accept']['to'] ?? []) as $j => $to) {
            if (!isset($chars[(string)$to])) $bad("resolution.accept.to[$j]", "'$to' is not a declared character");
        }
        // A story that ends when you are wrong is a quiz. Accusing the wrong man
        // in act two is the genre working correctly: it costs something, the
        // counter goes up, and the story keeps running.
        if (($r['on_wrong']['closes'] ?? true) !== false) {
            $bad('resolution.on_wrong.closes', 'must be false, a wrong accusation is a beat, never an ending');
        }
    }
    if ($kind === 'possession' && trim((string)($r['boon'] ?? '')) === '') {
        $bad('resolution.boon', 'is required on a possession, it closes when the boon is claimed');
    }
    // The strange place is a gravity well, not a puzzle, and an overlay is
    // exactly the kind of thing that would helpfully solve it.
    if (!empty($t['mystery']['enabled']) && ($t['mystery']['rumor_pays_out'] ?? true) === false) {
        if (!in_array('mystery.rumor', (array)($r['never'] ?? []), true)) {
            $bad('resolution.never', "must contain 'mystery.rumor', this world's rumor_pays_out is false");
        }
    }

    // -- on_close ----------------------------------------------------------
    foreach ((array)($s['on_close']['memories'] ?? []) as $h => $m) {
        if (!isset($chars[(string)$h])) $bad("on_close.memories.$h", 'is not a declared character');
        if (trim((string)$m) === '')    $bad("on_close.memories.$h", 'is empty');
    }
    $cp = (string)($s['on_close']['event']['place'] ?? '');
    if ($cp !== '' && !isset($places[$cp])) $bad('on_close.event.place', "'$cp' is not a declared place");

    // -- no overlay string restates a rating-gated interior ----------------
    // An overlay states what a holder can OBSERVE. Restating a character's gated
    // interior in an overlay string would render, at the lower rating, the exact
    // sentences the gate exists to withhold — so it is compared the only way
    // loose prose can be, by six-word run, against the nodes this world's rating
    // is currently withholding.
    $gated = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        foreach (['drives', 'secrets', 'psyche'] as $sect) {
            $node = $c[$sect] ?? null;
            if (!is_array($node)) continue;
            if (!isset($node['rating_min'])) continue;
            if (xeric_rating_rank((string)$node['rating_min']) <= xeric_rating_rank($eff)) continue;
            foreach ($node as $k => $v) {
                if (is_string($v)) $gated["cast.characters.{$c['handle']}.$sect.$k"] = $v;
            }
        }
    }
    if ($gated !== []) {
        $strings = [];
        array_walk_recursive($s, function ($v) use (&$strings) {
            if (is_string($v) && mb_strlen($v) > 24) $strings[] = $v;
        });
        foreach ($strings as $line) {
            $hay = xeric_wall_words($line);
            foreach ($gated as $path => $g) {
                if (xeric_wall_quotes($hay, xeric_wall_words($g))) {
                    $bad('(overlay prose)', "restates rating-gated $path at a lower rating");
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// State — arcs, and nothing else
// ---------------------------------------------------------------------------

/** Every arc row this overlay owns lives under this prefix. */
function xeric_story_prefix(array $s): string
{
    return 'story:' . xeric_story_key($s) . ':';
}

/**
 * The whole state machine, in one read.
 *
 * Beat and herring status are DERIVED from the arc rows over the beats the file
 * declares, so a row for a beat that no longer exists is ignored rather than
 * counted, and a beat with no row yet is locked rather than missing. The
 * `opened` counter is written too, because the design names it and a reader with
 * a SQL prompt should find it — but nothing here trusts it, because a counter
 * and the rows it counts are two things that can disagree.
 *
 * @return array{key:string,beats:array<string,string>,opened_at:array<string,int>,
 *               herrings:array<string,string>,opened:int,spilled:int,total:int,
 *               wrong:int,closed:?int,live:bool}
 */
function xeric_story_state(array $s, PDO $db): array
{
    $pre  = xeric_story_prefix($s);
    $rows = xeric_arcs_prefixed($db, xeric_arc_world(), $pre);

    $beats = [];
    foreach ((array)($s['beats'] ?? []) as $b) $beats[(string)($b['key'] ?? '')] = 'locked';
    $herrings = [];
    foreach ((array)($s['red_herrings'] ?? []) as $h) $herrings[(string)($h['key'] ?? '')] = 'live';

    $openedAt = [];
    foreach ($rows as $k => $v) {
        $tail = substr($k, strlen($pre));
        if (str_starts_with($tail, 'beat:')) {
            $bk = substr($tail, 5);
            if (isset($beats[$bk]) && in_array($v, ['locked', 'open', 'spilled'], true)) $beats[$bk] = $v;
            continue;
        }
        if (str_starts_with($tail, 'opened_at:')) { $openedAt[substr($tail, 10)] = (int)$v; continue; }
        if (str_starts_with($tail, 'herring:')) {
            $hk = substr($tail, 8);
            if (isset($herrings[$hk]) && in_array($v, ['live', 'collapsed'], true)) $herrings[$hk] = $v;
        }
    }

    $opened = 0;
    $spilled = 0;
    foreach ($beats as $st) {
        if ($st !== 'locked') $opened++;
        if ($st === 'spilled') $spilled++;
    }

    $closed = array_key_exists($pre . 'closed', $rows) ? (int)$rows[$pre . 'closed'] : null;
    return [
        'key'       => xeric_story_key($s),
        'beats'     => $beats,
        'opened_at' => $openedAt,
        'herrings'  => $herrings,
        'opened'    => $opened,
        'spilled'   => $spilled,
        'total'     => count($beats),
        'wrong'     => (int)($rows[$pre . 'wrong'] ?? 0),
        'closed'    => $closed,
        'live'      => $closed === null,
    ];
}

/** The overlays that compose right now: still open, and inside the world's rating. */
function xeric_story_active(array $stories, PDO $db, ?array $t = null): array
{
    $eff = $t !== null ? xeric_world_rating($t) : null;
    $out = [];
    foreach ($stories as $s) {
        if (!is_array($s) || xeric_story_key($s) === '') continue;
        if ($eff !== null && xeric_rating_rank((string)($s['rating_min'] ?? 'sfw')) > xeric_rating_rank($eff)) continue;
        if (!xeric_story_state($s, $db)['live']) continue;
        $out[] = $s;
    }
    return $out;
}

function xeric_story_beat(array $s, string $key): ?array
{
    foreach ((array)($s['beats'] ?? []) as $b) {
        if ((string)($b['key'] ?? '') === $key) return (array)$b;
    }
    return null;
}

function xeric_story_herring(array $s, string $key): ?array
{
    foreach ((array)($s['red_herrings'] ?? []) as $h) {
        if ((string)($h['key'] ?? '') === $key) return (array)$h;
    }
    return null;
}

/**
 * May this beat open yet?
 *
 * Two conditions, and neither of them is the calendar. Everything it names must
 * already have been SPILLED — a beat that merely opened is a sentence nobody has
 * said out loud — and the dwell must have passed in WORLD hours since the last
 * beat opened, which is what stops a player who blitzes three reveals in one
 * evening from standing in the crescendo on day one.
 *
 * A missing epoch skips the dwell rather than failing it: a caller with no clock
 * is asking a structural question, and refusing on a clock nobody supplied would
 * stall a story rather than pace it.
 */
function xeric_story_ready(array $s, PDO $db, array $beat, ?int $epoch = null, ?array $state = null): bool
{
    $st = $state ?? xeric_story_state($s, $db);
    if (!$st['live']) return false;

    foreach ((array)($beat['opens_when']['after'] ?? []) as $a) {
        if (($st['beats'][(string)$a] ?? 'locked') !== 'spilled') return false;
    }

    $dwell = (int)($beat['opens_when']['min_dwell_hours'] ?? 0);
    if ($dwell <= 0 || $epoch === null || $st['opened_at'] === []) return true;
    return ($epoch - max($st['opened_at'])) >= $dwell * 3600;
}

/**
 * Open a beat. Idempotent: a beat that is already open or spilled is left where
 * it is, because re-opening one would rewrite its opened_at and move the dwell
 * every beat after it is measured against.
 */
function xeric_story_open(array $s, PDO $db, string $beat, int $epoch, ?int $at = null): bool
{
    $st = xeric_story_state($s, $db);
    if (!$st['live'] || ($st['beats'][$beat] ?? null) !== 'locked') return false;

    $pre = xeric_story_prefix($s);
    xeric_arc_set($db, xeric_arc_world(), $pre . 'beat:' . $beat, 'open', $at);
    xeric_arc_set($db, xeric_arc_world(), $pre . 'opened_at:' . $beat, $epoch, $at);
    xeric_arc_set($db, xeric_arc_world(), $pre . 'opened', $st['opened'] + 1, $at);
    return true;
}

/**
 * They told you.
 *
 * The memory is the TELLER'S and nobody else's: a spill is a fact about a
 * conversation between two people, and if it spreads that is a `rumor` sweep
 * doing its ordinary job — which is a better mechanism than a broadcast, because
 * the story it spreads comes out changed. It is written as a memory rather than
 * anywhere else because memories are the last static block in prompt.php by
 * design, so a spill costs the tail of the system message and nothing above it.
 *
 * A beat that was still locked spills anyway, and opens on the way through. The
 * alternative is a character who has said the thing and a state machine that
 * says they have not.
 *
 * @return array{spilled:bool,collapsed:array<int,string>,memory:?int}
 */
function xeric_story_spill(array $s, PDO $db, string $beat, int $epoch, ?int $at = null): array
{
    $out = ['spilled' => false, 'collapsed' => [], 'memory' => null];
    $st  = xeric_story_state($s, $db);
    if (!$st['live'] || !array_key_exists($beat, $st['beats'])) return $out;
    if ($st['beats'][$beat] === 'spilled') return $out;          // said once, remembered once

    $b   = xeric_story_beat($s, $beat) ?? [];
    $pre = xeric_story_prefix($s);

    if ($st['beats'][$beat] === 'locked') {
        xeric_arc_set($db, xeric_arc_world(), $pre . 'opened_at:' . $beat, $epoch, $at);
        xeric_arc_set($db, xeric_arc_world(), $pre . 'opened', $st['opened'] + 1, $at);
    }
    xeric_arc_set($db, xeric_arc_world(), $pre . 'beat:' . $beat, 'spilled', $at);

    $holder = $b['holder'] ?? null;
    $text   = trim((string)($b['spilled_as'] ?? ''));
    if ($holder !== null && $text !== '') {
        $out['memory'] = xeric_memory_add($db, (string)$holder, $text, 'spill', [
            'story' => $st['key'],
            'beat'  => $beat,
            'to'    => 'user',
        ], $epoch, $at);
    }

    foreach ((array)($b['kills_herring'] ?? []) as $hk) {
        if (xeric_story_collapse($s, $db, (string)$hk, $epoch, $at)) $out['collapsed'][] = (string)$hk;
    }

    $out['spilled'] = true;
    return $out;
}

/**
 * A wrong lead, disposed of. The belief stops composing from here on; `actually`
 * is what the world says out loud at this moment and is returned to the caller
 * rather than written into anybody's head, because who says it is a question
 * about the scene, not about the store.
 */
function xeric_story_collapse(array $s, PDO $db, string $herring, int $epoch, ?int $at = null): bool
{
    $st = xeric_story_state($s, $db);
    if (!$st['live'] || ($st['herrings'][$herring] ?? null) !== 'live') return false;
    xeric_arc_set($db, xeric_arc_world(), xeric_story_prefix($s) . 'herring:' . $herring, 'collapsed', $at);
    return true;
}

/** The wrong-accusation counter. Never closes anything; that is the point of it. */
function xeric_story_wrong(array $s, PDO $db, ?int $at = null): int
{
    return xeric_arc_bump($db, xeric_arc_world(), xeric_story_prefix($s) . 'wrong', 1, $at);
}

/**
 * Close it. One arc row, then the residue, and nothing else anywhere.
 *
 * The residue is memories and one event: the culprit remembers the Tuesday it
 * came out, the child remembers being believed. Nothing goes back into the world
 * file, and after this call xeric_story_compose() adds nothing — which is what
 * makes the composed template identical to the untouched one again.
 *
 * Idempotent, because a second call would give the town the same Tuesday twice.
 */
function xeric_story_close(array $s, PDO $db, int $epoch, ?int $at = null): void
{
    if (!xeric_story_state($s, $db)['live']) return;
    $at = $at ?? xeric_state_time();

    // All of it or none of it — a story that closed without its residue would
    // leave a town with no memory of the Tuesday. A caller that already has a
    // transaction open keeps it: nesting one would throw, and the atomicity it
    // wanted is the atomicity this needs.
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        xeric_arc_set($db, xeric_arc_world(), xeric_story_prefix($s) . 'closed', $epoch, $at);

        $memories = (array)($s['on_close']['memories'] ?? []);
        foreach ($memories as $h => $text) {
            $text = trim((string)$text);
            if ((string)$h === '' || $text === '') continue;
            xeric_memory_add($db, (string)$h, $text, 'story', [
                'story' => xeric_story_key($s),
                'close' => true,
            ], $epoch, $at);
        }

        $ev    = (array)($s['on_close']['event'] ?? []);
        $title = trim((string)($ev['title'] ?? ''));
        if ($title !== '') {
            // Not a spine hour: the thing this world was keeping quiet is out,
            // and the event that says so is the one hour everybody may read.
            xeric_event_add($db, $title, $epoch, ($ev['place'] ?? null) !== null ? (string)$ev['place'] : null,
                array_keys($memories), (string)($ev['prose'] ?? ''), $at, false);
        }
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw new RuntimeException('story: could not close ' . xeric_story_key($s) . ', ' . $e->getMessage(), 0, $e);
    }
}

function xeric_story_progress(array $s, PDO $db, ?int $epoch = null): array
{
    $st    = xeric_story_state($s, $db);
    $total = max(1, $st['total']);

    $fraction = 0.0;
    if ($epoch !== null && $st['opened'] < $st['total'] && $st['opened_at'] !== []) {
        foreach ((array)($s['beats'] ?? []) as $b) {
            if (($st['beats'][(string)($b['key'] ?? '')] ?? 'locked') !== 'locked') continue;
            $dwell = (int)($b['opens_when']['min_dwell_hours'] ?? 0);
            if ($dwell > 0) {
                $elapsed  = max(0, $epoch - max($st['opened_at']));
                // Strictly below the next whole beat: arriving at the dwell is
                // being ready to open one, not having opened it.
                $fraction = min(0.999, $elapsed / ($dwell * 3600));
            }
            break;                                     // the next beat is the one that paces
        }
    }

    $p    = min(1.0, ($st['opened'] + $fraction) / $total);
    $snake = xeric_story_snake((array)($s['snake'] ?? []), $p);

    return [
        'p'         => $p,
        'stage'     => $snake['stage'],
        'intensity' => $snake['intensity'],
        'm'         => $snake['m'],
        'opened'    => $st['opened'],
        'spilled'   => $st['spilled'],
        'total'     => $st['total'],
        'closed'    => $st['closed'],
        'live'      => $st['live'],
        'beats'     => $st['beats'],
    ];
}

/**
 * The world's own sweep chance, modulated by every live story.
 *
 * clamp(0.05, 0.9, chance × m) — 1.6× at full intensity, 0.4× at none, and
 * EXACTLY the world's own number through the false calm. Several live stories
 * multiply, because two stories both pulling hard is a busier town and there is
 * no reading of "average" that means anything.
 */
function xeric_story_chance(array $t, array $stories, PDO $db, ?int $epoch = null): float
{
    $base = (float)($t['events']['sweep_chance'] ?? XERIC_SWEEP_CHANCE);
    $m    = 1.0;
    foreach (xeric_story_active($stories, $db, $t) as $s) {
        $m *= xeric_story_progress($s, $db, $epoch)['m'];
    }
    // A rate the world set for itself is the world's business; only a story's
    // multiplier is clamped, so a world with nothing live keeps its own number
    // whatever that number is.
    if ($m === 1.0) return $base;
    return max(0.05, min(0.9, $base * $m));
}

/**
 * The kind weights, with each live story's thumb on them.
 *
 * The `weight` float xeric_sweep_kinds_for() already attaches is the same one
 * learn.php writes, and two thumbs on one scale compose by multiplication. A
 * thumb can only push on a kind that is already there: a story may never arm a
 * system, so an entry naming a kind this world never armed is inert, which is
 * exactly what happens in a world that armed nothing.
 */
function xeric_story_thumb(array $kinds, array $stories, PDO $db, ?int $epoch = null): array
{
    foreach ($stories as $s) {
        if (!is_array($s)) continue;
        if (!xeric_story_state($s, $db)['live']) continue;
        $stage = xeric_story_progress($s, $db, $epoch)['stage'];
        foreach ((array)($s['snake']['kind_thumb'][$stage] ?? []) as $k => $mul) {
            $k   = (string)$k;
            $mul = (float)$mul;
            if (!isset($kinds[$k]) || $mul <= 0.0) continue;   // never adds a kind, never deletes one
            $kinds[$k]['weight'] = (float)($kinds[$k]['weight'] ?? 1.0) * $mul;
        }
    }
    return $kinds;
}

// ---------------------------------------------------------------------------
// Composition
// ---------------------------------------------------------------------------

/**
 * Lay every live overlay on top of the template.
 *
 * ADD-ONLY, and that is the property that makes several overlays at once safe:
 * walls, protections, pieces and beliefs accumulate, and nobody can be handed
 * something by story B that story A was keeping from them. There is therefore no
 * conflict resolution in here at all, and there is nothing to undo on close —
 * the caller simply stops composing and the template is what it always was.
 *
 * What it composes, and why each lands where it does:
 *   - walls        → knowledge_walls, so xeric_viewer_walls() applies them free
 *   - protections  → cast.special_roles, so xeric_sweep_protected() keeps the
 *                    protected character out of a spine hour without knowing why
 *   - a piece      → a secrets entry on its holder, gossip_grade:false. The
 *                    per-orbit privacy walls the forge writes already keep the
 *                    rest of the cast out of secrets, so a mystery needs no new
 *                    hiding machinery for its pieces at all
 *   - the state of a beat and a believer's conviction → $t['story']['lines'],
 *     already-written sentences keyed by handle, for the speaker's own block
 *
 * `truth`, `is_false` and `actually` are composed NOWHERE. Tell a model its
 * character is wrong and it hedges, and hedging is the death of a wrong lead.
 */
function xeric_story_compose(array $t, array $stories, PDO $db): array
{
    $live = xeric_story_active($stories, $db, $t);
    if ($live === []) return $t;                       // the subtraction, in one line

    $eff   = xeric_world_rating($t);
    $index = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $i => $c) $index[(string)($c['handle'] ?? '')] = $i;

    $walls = [];
    $roles = [];
    $lines = [];
    $keys  = [];

    foreach ($live as $s) {
        $key = xeric_story_key($s);
        $st  = xeric_story_state($s, $db);
        $keys[] = $key;

        // THE INCITING DEATH, and it is the only thing composition does that
        // writes to the world rather than to the template it returns.
        //
        // A story going live IS the death: there is no earlier moment, because a
        // story becomes live the instant its file is beside the world. So the
        // first compose that sees it kills the victim, writes the hour and gives
        // every living person the memory — and every compose after that does
        // nothing at all, because xeric_death_kill() refuses a second death.
        // Idempotent by construction rather than by a flag somebody has to
        // remember to check.
        //
        // Nothing is deleted, here or on close. The victim stays in the cast,
        // resolvable, remembered, behind whatever walls they were behind, and
        // their thread keeps every word they ever said. THAT is what makes this
        // legal for an overlay: it is a row in a database, not an edit to a file
        // somebody else owns.
        //
        // In a `permanent` world this cannot be undone even by resolving the
        // story — which is correct, and is the strongest reason to know which
        // kind of world you are laying a murder over.
        $vic = trim((string)($s['cast']['victim']['character'] ?? ''));
        if ($vic !== '' && isset($index[$vic]) && !xeric_is_dead($db, $vic)) {
            xeric_death_kill($t, $db, $vic, xeric_clock_epoch($db),
                xeric_text($s['cast']['victim']['found'] ?? ''));
        }

        foreach ((array)($s['walls'] ?? []) as $w) $walls[] = $w;

        foreach ((array)($s['cast']['protect'] ?? []) as $p) {
            $h = (string)($p['character'] ?? '');
            if ($h === '' || !isset($index[$h])) continue;
            $roles[] = [
                'character'     => $h,
                'role'          => (string)($p['role'] ?? 'unaware'),
                'must_not_know' => (string)($p['must_not_know'] ?? ''),
                'walls'         => [(string)($p['wall'] ?? '')],
            ];
        }

        foreach ((array)($s['beats'] ?? []) as $b) {
            $holder = $b['holder'] ?? null;
            if ($holder === null) continue;            // an event beat has no interior to sit in
            $holder = (string)$holder;
            if (!isset($index[$holder])) continue;
            $c = (array)$t['cast']['characters'][$index[$holder]];
            // Gated against the SUBJECT, which for a minor is the weakest rating
            // there is in every world and cannot be anything else.
            if (!xeric_rating_allows($eff, $b, $c)) continue;

            $status = $st['beats'][(string)($b['key'] ?? '')] ?? 'locked';
            $piece  = trim((string)($b['piece'] ?? ''));
            if ($piece !== '' && !xeric_story_already_holds($c, $piece)) {
                $entry = ['text' => $piece, 'gossip_grade' => false];
                // The gate is what a secret means to its owner, and it comes off
                // when they have already told you: a character who is still being
                // asked to earn it after saying it out loud reads as a machine.
                $gate = (int)($b['opens_when']['trust_gate'] ?? 0);
                if ($gate > 0 && $status !== 'spilled') $entry['trust_gate'] = $gate;
                $t['cast']['characters'][$index[$holder]]['secrets'][] = $entry;
            }

            // The two states of one sentence; exactly one of them is present.
            $line = trim((string)($status === 'locked' ? ($b['while_locked'] ?? '') : ($b['when_open'] ?? '')));
            if ($line !== '') $lines[$holder][] = $line;
        }

        foreach ((array)($s['red_herrings'] ?? []) as $h) {
            $bl = (string)($h['believer'] ?? '');
            if ($bl === '' || !isset($index[$bl])) continue;
            if (($st['herrings'][(string)($h['key'] ?? '')] ?? 'live') !== 'live') continue;
            $c = (array)$t['cast']['characters'][$index[$bl]];
            if (!xeric_rating_allows($eff, $h, $c)) continue;

            // Byte-identical in shape to a conviction that happens to be true.
            // She has to be certain, because the player has to believe her.
            $lines[$bl][] = 'You are sure of this: ' . xeric_sentence((string)$h['belief'])
                . ' ' . xeric_sentence((string)($h['because'] ?? ''))
                . ' You would say so if it came up.';
        }
    }

    if ($walls !== []) $t['knowledge_walls'] = array_merge((array)($t['knowledge_walls'] ?? []), $walls);
    if ($roles !== []) $t['cast']['special_roles'] = array_merge((array)($t['cast']['special_roles'] ?? []), $roles);
    if ($lines !== []) {
        // A namespaced section rather than a field somebody else owns, and it
        // carries prose only: no progress, no intensity, no countdown. What is in
        // here changes when a beat opens or spills and at no other time, which is
        // what keeps a system message assembled from it in cache between turns.
        $t['story'] = ['keys' => $keys, 'lines' => $lines];
    }
    return $t;
}

/** Does this character's own dossier already say the piece? Then it is not said twice. */
function xeric_story_already_holds(array $c, string $piece): bool
{
    $needle = xeric_wall_words($piece);
    foreach ((array)($c['secrets'] ?? []) as $s) {
        $text = xeric_text($s);
        if ($text !== '' && xeric_wall_quotes(xeric_wall_words($text), $needle)) return true;
    }
    return false;
}

/** The story lines this speaker carries, in composition order. [] for everybody else. */
function xeric_story_lines(array $t, string $handle): array
{
    return array_values((array)($t['story']['lines'][$handle] ?? []));
}

// ---------------------------------------------------------------------------
// Watching a conversation
// ---------------------------------------------------------------------------

/**
 * One turn, looked at from the story's side: did a beat open, and did they tell?
 *
 * $turn is what the character just said; $opts['asked'] is what the user said to
 * get it, and both are searched for the beat's `asks_about` words, because a
 * topic is live in a conversation whichever of the two people raised it.
 *
 * Detection is allowed to be generous — see the note at the top of this file.
 *
 * @param array $opts asked:string, trust:int (defaults to the arc),
 *                    spill:string|array (for spill_detect "manual")
 * @return array{opened:array<int,string>,spilled:array<int,string>,
 *               collapsed:array<int,string>,said:array<int,string>}
 */
function xeric_story_observe(array $s, PDO $db, string $handle, string $turn, int $epoch, array $opts = []): array
{
    $out = ['opened' => [], 'spilled' => [], 'collapsed' => [], 'said' => []];
    $st  = xeric_story_state($s, $db);
    if (!$st['live']) return $out;

    $asked = (string)($opts['asked'] ?? '');
    $trust = array_key_exists('trust', $opts) ? (int)$opts['trust'] : xeric_arc_int($db, $handle, 'trust', 0);
    $at    = $opts['at'] ?? null;

    foreach ((array)($s['beats'] ?? []) as $b) {
        if ((string)($b['holder'] ?? '') !== $handle) continue;
        $key    = (string)($b['key'] ?? '');
        $status = $st['beats'][$key] ?? 'locked';
        if ($status === 'spilled') continue;

        if ($status === 'locked'
            && xeric_story_ready($s, $db, (array)$b, $epoch, $st)
            && $trust >= (int)($b['opens_when']['trust_gate'] ?? 0)
            && xeric_story_asked((array)$b, $asked . ' ' . $turn)) {
            if (xeric_story_open($s, $db, $key, $epoch, $at)) {
                $out['opened'][] = $key;
                $status = 'open';
            }
        }

        if (xeric_story_told((array)$b, $turn, $opts)) {
            $r = xeric_story_spill($s, $db, $key, $epoch, $at);
            if (!$r['spilled']) continue;
            $out['spilled'][] = $key;
            foreach ($r['collapsed'] as $hk) {
                $out['collapsed'][] = $hk;
                $h = xeric_story_herring($s, $hk);
                // What the world may now say out loud. Returned, never written:
                // who says it is a question about the scene, not about the store.
                if ($h !== null && trim((string)($h['actually'] ?? '')) !== '') $out['said'][] = (string)$h['actually'];
            }
        }
        $st = xeric_story_state($s, $db);              // a later beat reads what this one just did
    }
    return $out;
}

/** Is the beat's subject live in this exchange? A beat with no words is always on topic. */
function xeric_story_asked(array $beat, string $text): bool
{
    $words = (array)($beat['opens_when']['asks_about'] ?? []);
    if ($words === []) return true;
    $hay = ' ' . implode(' ', xeric_wall_words($text)) . ' ';
    foreach ($words as $w) {
        $w = trim(mb_strtolower((string)$w));
        if ($w !== '' && str_contains($hay, ' ' . $w . ' ')) return true;
    }
    return false;
}

/**
 * Did that turn contain the piece?
 *
 * The default reuses the six-word-run matcher walls.php already ships and tests:
 * a character who has said the piece has, by construction, said most of it.
 * "marker:X" reuses the marker grammar boons already use; "auto" belongs to
 * event beats, which spill when their event fires rather than in a conversation;
 * "manual" exists so a demo can drive one from a button.
 */
function xeric_story_told(array $beat, string $turn, array $opts = []): bool
{
    $detect = (string)($beat['spill_detect'] ?? 'quote');
    $key    = (string)($beat['key'] ?? '');

    if (str_starts_with($detect, 'marker:')) {
        $marker = trim(substr($detect, 7));
        return $marker !== '' && stripos($turn, $marker) !== false;
    }
    if ($detect === 'manual') {
        $manual = $opts['spill'] ?? [];
        return in_array($key, is_array($manual) ? array_map('strval', $manual) : [(string)$manual], true);
    }
    if ($detect === 'auto') return false;

    $piece = trim((string)($beat['piece'] ?? ''));
    return $piece !== '' && xeric_wall_quotes(xeric_wall_words($turn), xeric_wall_words($piece));
}

/**
 * The next event beat owed to the world: no holder, ready, and not yet fired.
 *
 * BEATS ARE NOT ROLLED. The snake modulates the world's ambient motion; a beat
 * event fires on the first eligible window after its beat opens, through the
 * ordinary compose path with the roll skipped. A story that could stall on dice
 * forever is not a story, it is weather with a title.
 */
function xeric_story_due(array $s, PDO $db, int $epoch): ?array
{
    $st = xeric_story_state($s, $db);
    if (!$st['live']) return null;
    foreach ((array)($s['beats'] ?? []) as $b) {
        if (($b['holder'] ?? null) !== null) continue;
        if (($st['beats'][(string)($b['key'] ?? '')] ?? 'locked') !== 'locked') continue;
        if (!xeric_story_ready($s, $db, (array)$b, $epoch, $st)) continue;
        return (array)$b;
    }
    return null;
}

/** Fire an event beat: it opens and spills in one motion, because nobody held it back. */
function xeric_story_fire(array $s, PDO $db, string $beat, int $epoch, ?int $at = null): array
{
    return xeric_story_spill($s, $db, $beat, $epoch, $at);
}

// ---------------------------------------------------------------------------
// Resolution
// ---------------------------------------------------------------------------

/** Have the beats that make the answer supportable actually been spilled? */
function xeric_story_supported(array $s, PDO $db, ?array $state = null): bool
{
    $st = $state ?? xeric_story_state($s, $db);
    foreach ((array)($s['resolution']['requires_beats'] ?? []) as $rb) {
        if (($st['beats'][(string)$rb] ?? 'locked') !== 'spilled') return false;
    }
    return true;
}

/**
 * Somebody named somebody. Does the story close?
 *
 * Three outcomes and only one of them is an ending:
 *
 *   right + supported  → it closes, here, in this call
 *   right + unsupported→ nothing. No acknowledgment, no wink; the character
 *                        answers as themselves and the story keeps running,
 *                        because a guess is not a solution
 *   wrong              → the counter goes up and it costs what on_wrong says it
 *                        costs. on_wrong.closes is validated false: a story that
 *                        ends when you are wrong is a quiz
 *
 * `possession`, `arrival` and `marker` come through the same door with $named
 * carrying the boon key, the arrival or the marker — the requires_beats gate is
 * the same on all four, which is why it is required on all four.
 *
 * @param array $opts to:string — who it was said to, checked against accept.to
 * @return array{closed:bool,right:bool,why:string,wrong:int,costs:string}
 */
function xeric_story_resolve(array $s, PDO $db, string $named, int $epoch, array $opts = []): array
{
    $st  = xeric_story_state($s, $db);
    $r   = (array)($s['resolution'] ?? []);
    $out = ['closed' => false, 'right' => false, 'why' => '', 'wrong' => $st['wrong'], 'costs' => ''];

    if (!$st['live']) {
        $out['closed'] = true;
        $out['why']    = 'this story is already closed';
        return $out;
    }

    $kind   = (string)($r['kind'] ?? 'accusation');
    $answer = (string)($r['answer'] ?? $r['boon'] ?? $r['marker'] ?? '');

    // Said to the wrong person is not an accusation at all: it costs nothing and
    // counts nothing, and the story never learns it happened.
    $to     = (string)($opts['to'] ?? '');
    $accept = array_map('strval', (array)($r['accept']['to'] ?? []));
    if ($kind === 'accusation' && $to !== '' && $accept !== [] && !in_array($to, $accept, true)) {
        $out['why'] = "an accusation said to '$to' is not said to anybody who would carry it";
        return $out;
    }

    if ($named !== $answer) {
        $out['wrong'] = xeric_story_wrong($s, $db, $opts['at'] ?? null);
        $out['costs'] = (string)($r['on_wrong']['costs'] ?? '');
        $out['why']   = 'wrong, and a wrong accusation is a beat rather than an ending';
        return $out;
    }

    $out['right'] = true;
    if (!xeric_story_supported($s, $db, $st)) {
        $out['why'] = 'right, and not yet shown, a guess is not a solution';
        return $out;
    }

    xeric_story_close($s, $db, $epoch, $opts['at'] ?? null);
    $out['closed'] = true;
    $out['why']    = 'shown, and closed';
    return $out;
}

// ---------------------------------------------------------------------------
// The shelf
// ---------------------------------------------------------------------------

/**
 * What this world has been given, and how far through it is.
 *
 * The player-visible half only: `logline` before it resolves and `world_keeps`
 * after. `truth`, `actually` and every piece stay in the file — the shelf is the
 * one surface a player reads directly, and a shelf that spoiled the mystery
 * would be the cheapest possible way to lose it.
 *
 * A closed story is LISTED, not removed. Its file stays where it is, and nothing
 * auto-injects the next one.
 */
function xeric_story_shelf(string $dir, PDO $db, ?int $epoch = null): array
{
    $out = [];
    foreach (xeric_story_files($dir) as $path) {
        $s  = xeric_story_read($path);
        if (xeric_story_key($s) === '') continue;
        $pr = xeric_story_progress($s, $db, $epoch);
        $row = [
            'key'     => xeric_story_key($s),
            'file'    => basename($path),
            'title'   => (string)($s['title'] ?? ''),
            'logline' => (string)($s['logline'] ?? ''),
            'kind'    => (string)($s['kind'] ?? ''),
            'source'  => (string)($s['source'] ?? 'authored'),
            'live'    => $pr['live'],
            'closed'  => $pr['closed'],
            'opened'  => $pr['opened'],
            'spilled' => $pr['spilled'],
            'total'   => $pr['total'],
            'p'       => $pr['p'],
            'stage'   => $pr['stage'],
        ];
        if (!$pr['live']) $row['world_keeps'] = (string)($s['on_close']['world_keeps'] ?? '');
        $out[] = $row;
    }
    return $out;
}
