<?php
/**
 * review-lib.php — the forge proposes; the user disposes.
 *
 * FORGE.md, principle 3: "Review before launch, always. Every generated
 * artifact — the town, each place, each person — is editable before the world
 * starts, and skippable for anyone who wants to just go."
 *
 * This file is that principle, and the three rules it turns into:
 *
 *  1. A REROLL RE-RUNS THE REAL PASS. Not a copy of it. `xeric_forge_person()`
 *     was lifted out of the cast loop precisely so one character can be rewritten
 *     by the SAME prompt the build used; places, walls, the protagonist and the
 *     seed all call their own pass function with the template's own stored
 *     answers. A second prompt would drift from the first within a week and
 *     "reroll" would quietly start meaning "generate a different kind of thing".
 *
 *  2. NOTHING IS SAVED THAT DOES NOT VALIDATE. Every edit and every reroll is
 *     applied to a COPY, run through xeric_world_validate(), and only then
 *     written. A rejected edit keeps the old value and says why in a sentence —
 *     never a path and a stack trace.
 *
 *  3. A WORLD IS NOT PLAYABLE UNTIL SOMEBODY LAUNCHES IT. `forge.review_pending`
 *     is the flag, and it is absent from every world forged before today, which
 *     is exactly right: those were launched by existing. Launching is one tap and
 *     the review is skippable, because principle 3 says so.
 *
 * WHAT THIS FILE MAY NOT DO: decide anything about the world. Every value here
 * comes from forge.php's passes or from something the user typed.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

/** How many people a reroll of the whole cast writes, and how many rooms. */
function xeric_review_counts(array $t): array
{
    return [
        'places' => max(2, count((array)($t['places'] ?? []))),
        'cast'   => max(1, count((array)($t['cast']['characters'] ?? []))),
    ];
}

// ---------------------------------------------------------------------------
// Launched, or still on the anvil
// ---------------------------------------------------------------------------

/**
 * Has this world been launched?
 *
 * Absence means yes. Every world forged before the review step existed is
 * launched by virtue of having been played, and a flag that defaulted the other
 * way would have quietly locked six of them behind a button nobody knew about.
 */
function xeric_review_launched(array $t): bool
{
    return empty($t['forge']['review_pending']);
}

/** Mark a freshly forged template as needing a look before it is playable. */
function xeric_review_mark_pending(array $t): array
{
    $t['forge'] = (array)($t['forge'] ?? []);
    $t['forge']['review_pending'] = true;
    return $t;
}

// ---------------------------------------------------------------------------
// Reading and writing the files
// ---------------------------------------------------------------------------

function xeric_review_dir(string $slug): string
{
    return xeric_web_worlds_dir() . '/' . xeric_web_slug($slug);
}

/**
 * Take a xeric off the shelf, for good.
 *
 * THE ONE OPERATION WITH NO UNDO. Everything else in this app keeps a `.prev`,
 * and the whole engine is built on the idea that a world is somebody's months of
 * continuity — so this is written to be read: the caller checks ownership, and
 * this checks that what it is about to delete is genuinely a world directory
 * inside the worlds directory, by realpath, before it removes a single file. A
 * slug that escapes (`..`, a symlink somebody planted) resolves outside and is
 * refused rather than followed.
 *
 * Its forks go with it. A visitor who opened somebody else's xeric plays a copy
 * of the database at <sessions>/<sid>/<slug>.db, and those copies are worthless
 * the moment the template they were forked from is gone — left behind they are
 * dead bytes that no page can ever open again.
 *
 * @return array{files:int,forks:int}
 * @throws RuntimeException in a sentence, always
 */
function xeric_review_delete(string $slug): array
{
    $slug = xeric_web_slug($slug);
    if ($slug === '') throw new RuntimeException('which xeric?');

    $root = realpath(xeric_web_worlds_dir());
    $dir  = realpath(xeric_review_dir($slug));
    if ($root === false || $dir === false) {
        throw new RuntimeException("There is no xeric called '$slug' here.");
    }
    // Inside the worlds directory, and not the worlds directory itself.
    if ($dir === $root || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('that is not a xeric this app may remove');
    }
    if (!is_file($dir . '/world-template.json')) {
        throw new RuntimeException("There is nothing at '$slug' that looks like a xeric.");
    }

    $files = 0;
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f) && @unlink($f)) $files++;
    }
    if (!@rmdir($dir)) throw new RuntimeException('the folder would not go');

    // Every session's copy of it, including this one's.
    $forks = 0;
    foreach (glob(xeric_session_root() . '/*/' . $slug . '.db*') ?: [] as $f) {
        if (@unlink($f)) $forks++;
    }

    // And the shelf stops claiming it. A world in `own` that is not on disk is
    // already filtered out by xeric_session_worlds(), but leaving the name
    // there means a stale `result` can still point a page at a dead slug.
    xeric_web_session_edit(function (array &$s) use ($slug): void {
        $s['own'] = array_values(array_filter((array)($s['own'] ?? []),
            fn($x) => (string)$x !== $slug));
        if ((string)($s['result']['slug'] ?? '') === $slug) unset($s['result']);
        if (isset($s['tick'][$slug])) unset($s['tick'][$slug]);
    });

    return ['files' => $files, 'forks' => $forks];
}

/**
 * A world, for review: template, seed, whose it is, whether it has been lived in.
 *
 * @throws RuntimeException in a sentence, always
 */
function xeric_review_open(string $slug, ?string $sid = null): array
{
    $slug = xeric_web_slug($slug);
    if ($slug === '') throw new RuntimeException('which xeric?');
    $dir  = xeric_review_dir($slug);
    $path = $dir . '/world-template.json';
    if (!is_file($path)) {
        throw new RuntimeException("There is no xeric called '$slug' here any more. Xerics forged in the demo "
            . 'are kept for seven days after your last visit and then let go.');
    }
    $t = xeric_world_load($path);
    $seed = json_decode((string)@file_get_contents($dir . '/seed.json'), true);
    if (!is_array($seed)) $seed = ['events' => [], 'memories' => []];
    $seed['events']   = array_values((array)($seed['events'] ?? []));
    $seed['memories'] = array_values((array)($seed['memories'] ?? []));

    return [
        'slug' => $slug, 'dir' => $dir, 'path' => $path,
        'template' => $t, 'seed' => $seed,
        'mine' => xeric_session_owns($slug, $sid ?? xeric_session_id()),
        'launched' => xeric_review_launched($t),
        'lived' => is_file($dir . '/world.db'),
    ];
}

/**
 * Write a template (and optionally a seed) back over its own files.
 *
 * Validated first, written to a neighbour and renamed second: a half-written
 * world-template.json is a world that will not load, and the person it would
 * happen to is the one who was in the middle of fixing it.
 *
 * PASS $seed ONLY WHEN IT CHANGED. Every save rolls what it is about to replace
 * into a .prev.json, so handing an UNCHANGED seed back rewrites seed.json and
 * rolls seed.prev.json over the top of it — which is how fixing one typo used to
 * quietly spend the undo for a seed reroll while the page went on offering it.
 * null means "the past is not part of this save", and it is the common case.
 *
 * @throws RuntimeException with the validator's complaint already in English
 */
function xeric_review_save(string $slug, array $t, ?array $seed = null): void
{
    try {
        xeric_world_validate($t, 'this xeric');
    } catch (Throwable $e) {
        throw new RuntimeException(xeric_review_plain($e->getMessage()));
    }

    $dir = xeric_review_dir($slug);
    if (!is_dir($dir)) throw new RuntimeException('that xeric is not on disk any more');

    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    // KEEP THE LAST GOOD COPY BEFORE OVERWRITING. Rerolling the whole cast is a
    // bulldozer — it clears special_roles, the protagonist and every proposed
    // wall, because they name people who no longer exist — and until now that
    // was unrecoverable. Somebody tuning their own world for a week WILL do it
    // once by accident to a world they liked. One rename is the whole fix.
    $live = $dir . '/world-template.json';
    if (is_file($live)) @copy($live, $dir . '/world-template.prev.json');
    if ($seed !== null && is_file($dir . '/seed.json')) @copy($dir . '/seed.json', $dir . '/seed.prev.json');

    xeric_review_put($live, json_encode($t, $flags) . "\n");
    if ($seed !== null) xeric_review_put($dir . '/seed.json', json_encode($seed, $flags) . "\n");
}

/**
 * Put back the copy taken before the last save. One step, deliberately — a
 * deep undo stack for a single-user tuning tool is a way to lose track of which
 * world you are in. Returns what it restored, or null when there is nothing.
 */
function xeric_review_undo(string $slug): ?array
{
    $dir = xeric_review_dir($slug);
    $prev = $dir . '/world-template.prev.json';
    if (!is_file($prev)) return null;

    $t = json_decode((string)file_get_contents($prev), true);
    if (!is_array($t)) return null;
    try {
        xeric_world_validate($t, 'the previous version');
    } catch (Throwable $e) {
        return null;   // never restore something that cannot load
    }
    // swap, so undo is itself undoable
    $live = $dir . '/world-template.json';
    $tmp = $dir . '/world-template.swap.json';
    if (is_file($live)) @copy($live, $tmp);
    @copy($prev, $live);
    if (is_file($tmp)) @rename($tmp, $prev);

    $seedPrev = $dir . '/seed.prev.json';
    if (is_file($seedPrev)) {
        $sTmp = $dir . '/seed.swap.json';
        if (is_file($dir . '/seed.json')) @copy($dir . '/seed.json', $sTmp);
        @copy($seedPrev, $dir . '/seed.json');
        if (is_file($sTmp)) @rename($sTmp, $seedPrev);
    }
    return $t;
}

/** Is there something to go back to? */
function xeric_review_has_undo(string $slug): bool
{
    return is_file(xeric_review_dir($slug) . '/world-template.prev.json');
}

/** Write-then-rename. The only way a reader never sees half a file. */
function xeric_review_put(string $path, string $body): void
{
    $tmp = $path . '.tmp-' . getmypid();
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        throw new RuntimeException('this xeric could not be written back to disk, the demo may be out of room');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('this xeric could not be written back to disk, the demo may be out of room');
    }
}

/**
 * The validator's complaint, as a sentence.
 *
 * The engine's messages are precise and shaped for a log — "xeric: this world:
 * cast.characters[2].week[0].from '25:99' is not an HH:MM time". A person
 * editing a text box needs the same fact without the path, so the path becomes
 * the name of the thing they were touching.
 *
 * A PATTERN HERE MATCHES THE PATH AND STOPS. Four of them used to end in `.*`,
 * which ate the validator's entire complaint and left refusals reading "One of
 * the knowledge walls " with nothing after it — the reason a cast reroll that
 * could not validate was undiagnosable for as long as it was. The remainder IS
 * the explanation; only the path in front of it is ours to replace.
 */
function xeric_review_plain(string $msg): string
{
    $m = preg_replace('/^xeric:\s*[^:]*:\s*/', '', trim($msg)) ?? $msg;

    $names = [
        '/^meta\.name\b/'                          => 'The name of the xeric',
        '/^meta\.rating\b/'                        => 'The content rating',
        '/^user\.timezone\b/'                      => 'Your timezone',
        '/^user\.occupation\.workplace_key\b/'     => 'Where you work',
        '/^places\[(\d+)\]\.key\b/'                => 'One of the places',
        '/^places\[(\d+)\]\.residents/'            => 'Somebody listed as being at one of the places',
        '/^cast\.characters\[(\d+)\]\.handle\b/'   => 'One of the cast',
        '/^cast\.characters\[(\d+)\]\.orbit\b/'    => 'Which group one of the cast belongs to',
        '/^cast\.characters\[(\d+)\]\.week\[(\d+)\]\.where\b/' => 'Where one of the cast spends that part of their week',
        '/^cast\.characters\[(\d+)\]\.week\[(\d+)\]\.(from|to)\b/' => 'One of the times in a character\'s week',
        '/^cast\.characters\[(\d+)\]\.week\[(\d+)\]\.days/'        => 'The days of one of the cast\'s shifts',
        '/^cast\.special_roles\[(\d+)\]/'          => 'One of the protected people',
        '/^knowledge_walls\[(\d+)\]/'              => 'One of the knowledge walls',
        '/^cast\.orbits\b/'                        => 'The groups the cast is divided into',
        '/^cast\.characters\b/'                    => 'The cast',
    ];
    foreach ($names as $re => $label) {
        if (preg_match($re, $m)) {
            $rest = preg_replace($re, '', $m) ?? $m;
            return $label . ' ' . ltrim($rest, ' .');
        }
    }
    return $m;
}

// ---------------------------------------------------------------------------
// Editing by hand
// ---------------------------------------------------------------------------

/**
 * Which dotted paths a person may type over.
 *
 * A whitelist, not a blacklist, and deliberately narrow: prose, times and names.
 * Nothing here can change a KEY or a HANDLE, because every wall, every week
 * block and every seeded memory points at those by name — renaming a place from
 * a text box would silently empty four people's schedules. The label is what the
 * refusal calls the field, so a person is told which box they broke.
 *
 * THE THIRD ELEMENT IS WHAT THE EDIT COSTS A WORLD THAT IS ALREADY LIVE, and it
 * lives in this table so there is exactly one list. 'deep' marks the rows that
 * land in the identity-bearing top of somebody's system prompt — a voice, the
 * psyche, what somebody is really after, the canon the bible carries as law,
 * and the walls — where one changed byte costs the model a re-read of who she
 * is before the next answer. Everything unmarked is 'live': surface prose that
 * is simply true from the next thing anybody says. The rating would be 'deep'
 * too, and is not here at all, because it is not editable — it is the user's,
 * from the interview. xeric_review_edit_weight() is the only reader.
 *
 * @return array<string,array{0:string,1:string,2?:string}>  regex => [label, kind, weight?]
 */
function xeric_review_editable(): array
{
    return [
        '#^meta\.(name|description)$#'                                   => ['the xeric', 'line'],
        '#^setting\.(locale|era)$#'                                      => ['the setting', 'line'],
        '#^setting\.texture\.\d+$#'                                      => ['the setting', 'line'],
        // "These are laws here, not flavor" is how the bible hands these to
        // every speaker — canon, not colour, hence the weight.
        '#^setting\.canon_rules\.\d+$#'                                  => ['the setting', 'line', 'deep'],
        // THE FIELDS A BLANK XERIC HAS NONE OF. Themes and the mood axis were
        // read-only because a forged world always arrives with them written; a
        // world somebody starts empty arrives with neither, and "customise it
        // by hand" has to mean all of it or the hand-built xeric is the one
        // that cannot be finished. Each one takes the dice like any other
        // field, so filling them in is a choice between typing and rolling.
        '#^meta\.themes\.\d+$#'                                          => ['a theme', 'line'],
        '#^world_mood\.axis\.(positive|negative)$#'                      => ['the mood at one end', 'line'],
        '#^world_mood\.motifs\.(dark|light)\.\d+$#'                      => ['a recurring image', 'line'],
        // Quiet hours have their own kind because they FAIL OPEN: the sweeps read
        // "11pm-7am" or an en-dashed paste as no range at all and let the world
        // run all night, which is the opposite of what the person typing it asked
        // for. A format nobody downstream can read is refused here, in a sentence.
        '#^user\.quiet_hours$#'                                          => ['when this xeric goes quiet', 'quiet'],
        '#^user\.(name|pronouns|timezone|motivation|location)$#'         => ['you', 'line'],
        // The bio grows on write like a character's pronouns do: no forged
        // world carries one until the player writes it from the pill.
        '#^user\.bio$#'                                                  => ['your bio', 'text'],
        '#^user\.occupation\.(title|hours)$#'                            => ['your work', 'line'],
        '#^places\.\d+\.(name|kind)$#'                                   => ['a place', 'line'],
        '#^places\.\d+\.description$#'                                   => ['a place', 'text'],
        '#^places\.\d+\.hours\.(open|close)$#'                           => ['a place\'s hours', 'time'],
        '#^cast\.characters\.\d+\.(display_name|one_line|surface)$#'     => ['a character', 'line'],
        '#^cast\.characters\.\d+\.(appearance|solace)$#'                 => ['a character', 'text'],
        '#^cast\.characters\.\d+\.voice$#'                               => ['a character', 'text', 'deep'],
        '#^cast\.characters\.\d+\.age$#'                                 => ['a character\'s age', 'int'],
        // The cog on the play screen writes these three. Pronouns are the
        // character's own word and shade their face; the orbit select only
        // offers keys the template declares, and the validator behind every
        // save is what actually holds that line; heat is the sampler knob.
        '#^cast\.characters\.\d+\.pronouns$#'                            => ['a character\'s pronouns', 'line'],
        // What the chip bar calls them. Grows on write like pronouns do — no
        // world forged before it existed carries the key.
        '#^cast\.characters\.\d+\.short_name$#'                          => ['what people call them', 'line'],
        '#^cast\.characters\.\d+\.orbit$#'                               => ['a character\'s circle', 'line'],
        '#^cast\.characters\.\d+\.temperature$#'                         => ['a character\'s heat', 'num'],
        '#^cast\.characters\.\d+\.tells\.\d+$#'                          => ['a character\'s tells', 'line'],
        '#^cast\.characters\.\d+\.psyche\.(sore_spot|jealousy|self_soothe|praise_that_lands)$#' => ['a character', 'line', 'deep'],
        '#^cast\.characters\.\d+\.drives\.pull$#'                        => ['a character', 'line', 'deep'],
        '#^cast\.characters\.\d+\.week\.\d+\.(from|to)$#'                => ['a shift time', 'time'],
        '#^cast\.characters\.\d+\.week\.\d+\.doing$#'                    => ['what somebody is doing', 'line'],
        '#^cast\.special_roles\.\d+\.must_not_know$#'                    => ['what somebody must not know', 'line', 'deep'],
        '#^cast\.protagonist\.(arc|pressure)$#'                          => ['whose story this is', 'line'],
        '#^knowledge_walls\.\d+\.(explain|shown_as)$#'                   => ['a knowledge wall', 'text', 'deep'],
        '#^seed\.events\.\d+\.(title|prose)$#'                           => ['something that already happened', 'text'],
        '#^seed\.memories\.\d+\.text$#'                                  => ['something somebody remembers', 'text'],
    ];
}

/** [label, kind] for an editable path, or null when it is not one. */
function xeric_review_field(string $path): ?array
{
    foreach (xeric_review_editable() as $re => $spec) {
        if (preg_match($re, $path)) return $spec;
    }
    return null;
}

/**
 * What saving this path costs a world that is already live.
 *
 * Read off the editable table's own third column, never off a second list:
 * 'deep' for the rows marked identity-bearing up there, 'live' for the rest,
 * null for a path the review does not let anybody type over at all.
 */
function xeric_review_edit_weight(string $path): ?string
{
    $spec = xeric_review_field($path);
    if ($spec === null) return null;
    return (string)($spec[2] ?? 'live');
}

/**
 * The one honest sentence a save on a LAUNCHED world carries back.
 *
 * Editing a live world was silent, which was the documented annoyance: the
 * person in it is different from the next message on, and nobody said so. So
 * every accepted edit now answers with what it just did to the running world —
 * a prose edit is simply live, an identity edit costs the model a re-read of
 * who she is (xeric_prompt_build's caching discipline: her voice and psyche are
 * the FIRST bytes of her system prompt, so one changed byte there re-reads
 * everything). Classification is the table's, in code; no model is asked.
 *
 * Null in exactly three honest places: a world still on the anvil (nothing is
 * running, there is nothing to warn about), a path the review cannot save
 * anyway, and the seed of a lived world — its past is in the database, the
 * section's own banner already says the file is all an edit changes, and "live
 * from the next thing anybody says" would be the one lie on the page.
 */
function xeric_review_live_note(array $w, string $path): ?string
{
    if (empty($w['launched'])) return null;
    if (str_starts_with($path, 'seed.')) return null;
    $weight = xeric_review_edit_weight($path);
    if ($weight === null) return null;

    if ($weight !== 'deep') return 'Live from the next thing anybody says.';

    $t = (array)($w['template'] ?? []);
    if (preg_match('#^cast\.characters\.(\d+)\.#', $path, $m)) {
        $c = (array)($t['cast']['characters'][(int)$m[1]] ?? []);
        // The character's own word for themselves, the same way the faces read
        // it — this page must not misgender somebody while renaming their soul.
        $k    = xeric_play_kind($c);
        $subj = ['f' => 'she', 'm' => 'he', 'x' => 'they'][$k];
        $obj  = ['f' => 'her', 'm' => 'him', 'x' => 'them'][$k];
        $who  = trim((string)($c['display_name'] ?? ''));
        $is   = ($who === '' && $k === 'x') ? 'are' : 'is';
        return 'This changes who ' . ($who !== '' ? $who : $subj) . ' ' . $is
            . ' — the next answer will take longer while the model re-reads ' . $obj . '.';
    }
    // Canon and the walls: not one person's interior but what every speaker
    // holds true, and every one of them pays the re-read.
    return 'This changes what this xeric holds true — the next answer will take longer '
        . 'while every speaker re-reads it.';
}

/** Read a dotted path out of a nested array. null when any step is missing. */
function xeric_review_get(array $data, string $path)
{
    $node = $data;
    foreach (explode('.', $path) as $step) {
        if (!is_array($node) || !array_key_exists($step, $node)) return null;
        $node = $node[$step];
    }
    return $node;
}

/** Set a dotted path in a nested array. Only ever writes where something already is. */
function xeric_review_set(array $data, string $path, $value): array
{
    $steps = explode('.', $path);
    $ref = &$data;
    foreach ($steps as $i => $step) {
        if ($i === count($steps) - 1) { $ref[$step] = $value; break; }
        if (!isset($ref[$step]) || !is_array($ref[$step])) return $data;
        $ref = &$ref[$step];
    }
    unset($ref);
    return $data;
}

/**
 * Who an edited path is ABOUT — the room the age floor reads it in.
 *
 * A character's own fields are about that character; a seed event is about
 * everybody who was in it, and a seeded memory about whoever is carrying it.
 * Everything else — a place, the setting, your own details — is about nobody in
 * particular and comes back empty, which is not a hole: xeric_age_floor() reads
 * an empty room for the world's children BY NAME, so sexual prose that names a
 * child in a place's description is refused there too.
 *
 * @return string[] handles, in no particular order
 */
function xeric_review_edit_handles(array $w, string $path): array
{
    $t = (array)$w['template'];

    if (preg_match('#^cast\.characters\.(\d+)\.#', $path, $m)) {
        return [(string)($t['cast']['characters'][(int)$m[1]]['handle'] ?? '')];
    }
    if (preg_match('#^cast\.special_roles\.(\d+)\.#', $path, $m)) {
        return [(string)($t['cast']['special_roles'][(int)$m[1]]['character'] ?? '')];
    }
    if (str_starts_with($path, 'cast.protagonist.')) {
        return [(string)($t['cast']['protagonist']['handle'] ?? '')];
    }
    if (preg_match('#^seed\.events\.(\d+)\.#', $path, $m)) {
        $e = (array)($w['seed']['events'][(int)$m[1]] ?? []);
        return array_map('strval', (array)($e['participants'] ?? []));
    }
    if (preg_match('#^seed\.memories\.(\d+)\.#', $path, $m)) {
        return [(string)($w['seed']['memories'][(int)$m[1]]['handle'] ?? '')];
    }
    return [];
}

/**
 * One hand edit: applied to a copy, validated, saved. Old value kept on refusal.
 *
 * @return array{ok:bool,value?:string,error?:string}
 */
/**
 * May a path that is not there yet be written anyway?
 *
 * "Missing" is the ordinary starting state for a great deal of this schema, and
 * the alternative — writing every optional key into every template so a box has
 * something to point at — is how a format grows a hundred empty strings nobody
 * asked for. So a whitelist of things that come into existence the first time
 * somebody writes them:
 *
 *   A KEY THAT NEVER FORGES. Pronouns, the short name, the player's bio. The
 *   record they hang off must exist, or the write below would have nowhere to
 *   stand.
 *
 *   THE NEXT ITEM OF A LIST, and only the NEXT one. Index N is an append when
 *   the list already holds N; index 12 of a list of three would leave a hole,
 *   and a PHP array with a hole in it stops being a JSON list and starts being
 *   an object with numeric keys, which every reader downstream then gets wrong.
 *   This is what lets a xeric started blank grow its themes, its texture, its
 *   canon rules and its motifs from nothing — a forged world has them all and a
 *   hand-built one starts with none.
 */
function xeric_review_growable(array $bag, string $path): bool
{
    if (preg_match('#^cast\.characters\.\d+\.(pronouns|short_name)$#', $path)) {
        return xeric_review_get($bag, (string)preg_replace('#\.(pronouns|short_name)$#', '.handle', $path)) !== null;
    }
    if ($path === 'user.bio') return is_array($bag['user'] ?? null);

    // A missing half of the mood axis, on a world that has an axis at all.
    if (preg_match('#^world_mood\.axis\.(positive|negative)$#', $path)) {
        return is_array(xeric_review_get($bag, 'world_mood.axis'));
    }

    // The next item of one of the lists a person fills in by hand.
    if (preg_match('#^(meta\.themes|setting\.texture|setting\.canon_rules'
                 . '|world_mood\.motifs\.(?:dark|light)|cast\.characters\.\d+\.tells)\.(\d+)$#',
                   $path, $m)) {
        $list = xeric_review_get($bag, $m[1]);
        return is_array($list) && (int)$m[2] === count($list);
    }
    return false;
}

function xeric_review_apply_edit(array $w, string $path, string $value, array $opts = []): array
{
    $spec = xeric_review_field($path);
    if ($spec === null) {
        return ['ok' => false, 'error' => 'That is not something the review step lets you change by hand. '
            . 'Keys and handles are what everything else in the xeric points at, so they are fixed once forged.'];
    }
    [$label, $kind] = $spec;

    $isSeed = str_starts_with($path, 'seed.');
    $bag = $isSeed ? ['seed' => $w['seed']] : $w['template'];
    $before = xeric_review_get($bag, $path);
    if ($before === null) {
        if (!xeric_review_growable($bag, $path)) {
            return ['ok' => false, 'error' => 'That field is not in this xeric any more, reload the page.'];
        }
        $before = '';
    }

    $value = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', '', $value) ?? $value);

    if ($kind === 'time') {
        if (!xeric_world_is_hhmm($value)) {
            return ['ok' => false, 'error' => 'Times are written as HH:MM on a 24-hour clock, 07:30, or 19:00. '
                . '“' . mb_substr($value, 0, 20) . '” is not one, so ' . $label . ' is unchanged.'];
        }
    } elseif ($kind === 'quiet') {
        $typed = $value;
        $value = xeric_review_quiet_hours($value);
        if ($value === null) {
            return ['ok' => false, 'error' => 'Quiet hours are two times on a 24-hour clock with a dash between '
                . 'them, 23:00-08:00. “' . mb_substr($typed, 0, 24) . '” is not, and the sweeps would read it '
                . 'as no quiet hours at all and keep the xeric awake all night, so it is unchanged. '
                . 'Leave the box empty if you want nothing kept quiet.'];
        }
    } elseif ($kind === 'int') {
        // A PLAUSIBLE HUMAN AGE, AND NOTHING MORE. This box used to refuse
        // anything under 18 and tell the person that everybody in a Xeric world
        // is an adult, which was never true and is not what the engine does: a
        // forged child's age could not be corrected from twelve to thirteen —
        // the only edit the page accepted was ageing him into an adult — and a
        // child could not be hand-authored at all, while forge.php's band table
        // was deliberately putting one in every cast. A minor is an ordinary
        // character. The one thing his age decides is that he is out of the
        // desire economy, and xeric_forge_age_floor() below is what decides it.
        if (!preg_match('/^\d{1,3}$/', $value) || (int)$value < 1 || (int)$value > 110) {
            return ['ok' => false, 'error' => 'An age is a whole number of years between 1 and 110. '
                . ucfirst($label) . ' is unchanged.'];
        }
        $value = (int)$value;
    } elseif ($kind === 'num') {
        // The sampler's heat. The band is the model's, not a taste: past 2.0
        // every backend this app has met is producing word salad.
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > 2) {
            return ['ok' => false, 'error' => 'Heat is a number between 0 and 2, most people live between '
                . '0.6 and 1.2. ' . ucfirst($label) . ' is unchanged.'];
        }
        $value = round((float)$value, 2);
    } else {
        // EMPTY IS AN ANSWER FOR EXACTLY ONE BOX. Everywhere else a blank field
        // is a hole the prose falls through, which is what this refusal is for.
        // The motivation is different: an empty one arms nothing, and switching
        // this world off is a thing somebody may deliberately want — it is the
        // whole proposition of a xeric started blank, which has no goal until
        // its owner decides there is one.
        if ($value === '' && $path !== 'user.motivation') {
            return ['ok' => false, 'error' => 'That cannot be empty, every character and every place needs '
                . 'something here for the prose to read from. ' . ucfirst($label) . ' is unchanged.'];
        }
        $value = mb_substr($value, 0, $kind === 'text' ? 1200 : 300);
    }

    // THE AGE FLOOR OVER EVERYTHING TYPED BY HAND. These boxes are the one way
    // text reaches a world without crossing a wall or a rating gate on its way
    // in: `drives` carries no rating_min at all, so a pull written here renders
    // into an adult's bible in an sfw world, and a seed event's prose is the
    // past that every memory in it is written from. The engine's own check
    // decides — one word list, one threshold, in chat.php — and it short-circuits
    // to nothing in a world with no children, so an all-adult cast never meets it.
    if (is_string($value)) {
        $about = xeric_age_floor($w['template'], xeric_review_edit_handles($w, $path), [$value]);
        if ($about !== null) {
            return ['ok' => false, 'error' => 'Nothing sexual may be written about ' . $about
                . ', who is a child in this xeric, so ' . $label . ' is unchanged. Everything else about them '
                . 'stands: they live here, they work, they talk, they can be hurt and they can know things '
                . 'nobody else does.'];
        }
    }

    $bag = xeric_review_set($bag, $path, $value);

    $t = $isSeed ? $w['template'] : $bag;
    // A TEMPLATE EDIT DOES NOT TOUCH THE SEED. It used to hand the seed back
    // unchanged, which still rewrote seed.json AND rolled seed.prev.json over
    // the top of it — so fixing one typo anywhere in the world silently spent
    // the undo for a seed reroll while the page went on offering it.
    $seed = $isSeed ? $bag['seed'] : null;

    $also = [];

    // AN AGE THAT CROSSED THE ADULT LINE TAKES THE REST OF THE RECORD WITH IT.
    // Re-run rather than patch: xeric_forge_age_floor() is the one pass that
    // knows what a child's record may not carry — his flirt_style, his own
    // attraction seeds, every seed anybody else points at him, the desire
    // systems in his armed set — and it is idempotent, so running it over an
    // already-floored world is free. Both directions, because the desire pool
    // and the world's armed set are a statement about the WHOLE cast and are
    // stale either way once one age moved across.
    if (preg_match('#^cast\.characters\.(\d+)\.age$#', $path, $m)) {
        $was = xeric_is_minor(['age' => $before]);
        $now = xeric_is_minor(['age' => $value]);
        if ($was !== $now) {
            $armedWas = (array)($t['forge']['armed'] ?? []);
            // No note sink: the pass writes for the forge's log stream and this
            // is one JSON reply to one box, so what moved is said here instead.
            $t = xeric_forge_age_floor($t);
            $who = trim((string)($t['cast']['characters'][(int)$m[1]]['display_name'] ?? '')) ?: 'They';
            $note = $now
                ? $who . ' is ' . $value . ', so this xeric treats them as a child: out of the desire economy, '
                    . 'and in everything else. They still live here, work, speak, and keep whatever they know.'
                : $who . ' is ' . $value . ' now, so they stand in the desire economy with everybody else.';
            $also = ['note' => $note];
            // Only when the world's own systems moved, which is when the cast has
            // run out of adults. Redrawing the cast instead would take the
            // sentence above off the screen with the box it is printed under.
            if ((array)($t['forge']['armed'] ?? []) !== $armedWas) {
                $also['note']  = $note . ($now
                    ? ' Nobody in this cast is an adult now, so the systems that generate wanting are '
                        . 'switched off for this whole world.'
                    : ' This cast has an adult in it again, so the systems that generate wanting are back on.');
                $also['stale'] = ['systems'];
            }
        }
    }

    if ($path === 'user.motivation') {
        $re = xeric_review_rearm($t, (string)$value, $opts['endpoint'] ?? null);
        $t = $re['template'];
        $also = ['note' => $re['note'], 'stale' => ['systems']];
    }

    try {
        xeric_review_save($w['slug'], $t, $seed);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage() . ' Nothing was saved, the old value is still there.'];
    }
    // THE STRONGEST SIGNAL IN THE SYSTEM, written down here rather than at the
    // call site so there is exactly one place that can forget to. Everything
    // else engine/learn.php collects is an inference from behaviour; this is the
    // user being shown what the world thought and correcting it in writing.
    // A reroll is deliberately not this: it goes through xeric_review_save(),
    // and "I did not like that, try again" is not a correction.
    xeric_review_learn_edit($w, $path, (string)$before, (string)$value);

    // EDITING A LAUNCHED WORLD IS LIVE, AND NOW SAYS SO. One sentence, riding
    // the save that made it true; where a more specific note is already there
    // (a re-arm, an age that crossed the line), this one follows it.
    $liveNote = xeric_review_live_note($w, $path);
    if ($liveNote !== null) {
        $also['note'] = isset($also['note'])
            ? rtrim((string)$also['note']) . ' ' . $liveNote
            : $liveNote;
    }

    // `was` goes back out for the same reason it goes in: a caller handed only
    // the new text has to guess what was wrong with the old.
    return ['ok' => true, 'value' => (string)$value, 'was' => (string)$before] + $also;
}

/**
 * A retyped motivation, and the systems it arms.
 *
 * `forge.armed` is not decoration: xeric_sweep_kinds_for() reads it, so it is
 * the entire list of things that can ever happen offscreen in this world. Left
 * alone after an edit, the panel underneath the box went on naming the systems
 * the OLD motivation armed — and the new one's kinds could never fire, so
 * somebody who retyped their way to "prove the mine is poisoning the river"
 * had a world in which the mine plot was literally impossible.
 *
 * WITH A MODEL WHEN ONE IS FREE, and never at the cost of the save. This used
 * to be resolved without one on the grounds that a text box is not a place to
 * spend the GPU slot — and the keyword table it fell to is a list of six
 * archetypes, so "prove the mine is poisoning the river" matched none of them,
 * fell through to `company`, and armed shared meals and gentle proactive
 * contact for a world about an industrial cover-up. The exact failure this
 * function's own comment was written about, still happening, because the fix
 * was aimed at the panel rather than at the resolver.
 *
 * A goal somebody typed by hand is the most specific sentence in the whole
 * template and deserves the one call it takes to read it. The caller passes an
 * endpoint only when the queue could be taken without waiting; a null one falls
 * to the table and then the keywords exactly as before, so a busy or absent
 * model costs accuracy and never the edit.
 *
 * @return array{template:array,note:string}
 */
function xeric_review_rearm(array $t, string $motivation, ?array $endpoint = null): array
{
    $sys = xeric_forge_armed($motivation, $endpoint);

    $t['forge'] = (array)($t['forge'] ?? []);
    $t['forge']['armed']    = $sys['armed'];
    $t['forge']['disarmed'] = $sys['disarmed'];
    $t['forge']['systems_source'] = $sys['source'];
    // Whatever the model said when it read the OLD motivation is now a sentence
    // about a world that no longer exists. Better absent than quoted at somebody.
    $t['forge']['systems_why'] = null;

    $armed = implode(', ', array_map(fn($k) => str_replace('_', ' ', (string)$k), (array)$sys['armed']));
    // An empty box is a real answer and gets a real sentence. Told "worked out
    // again from that: " and then nothing, somebody would reasonably read it as
    // the resolver having failed rather than as the world having been switched
    // off, which is what they just did.
    if ((array)$sys['armed'] === []) {
        return ['template' => $t,
                'note' => 'Nothing is armed now. With no goal, this xeric has no systems running: '
                        . 'it keeps its clock and its people, and nothing happens in it unless you '
                        . 'make it happen. Give it a goal and it starts moving again.'];
    }
    return ['template' => $t,
            'note' => 'What this xeric runs on was worked out again from that: ' . $armed
                    . '. Nothing else about it changed.'];
}

/**
 * A typed quiet-hours range, in the one shape the sweeps can read.
 *
 * '' means none, and is a real answer. Anything else must come out as
 * HH:MM-HH:MM: engine/sweeps.php reads the range with a plain hyphen and two
 * 24-hour times, and a pasted en dash or "11pm-7am" does not fail there — it
 * matches nothing and switches quiet hours OFF, which is a world that talks to
 * you at four in the morning because you typed the range in English.
 *
 * @return string|null null when it is not a range, which the caller must refuse
 */
function xeric_review_quiet_hours(string $v): ?string
{
    // The dashes a phone keyboard and a paste from a document actually produce.
    $v = trim(str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"], '-', $v));
    if ($v === '') return '';

    $parts = explode('-', preg_replace('/\s*-\s*/', '-', $v) ?? $v);
    if (count($parts) !== 2) return null;
    if (!xeric_world_is_hhmm($parts[0]) || !xeric_world_is_hhmm($parts[1])) return null;

    return $parts[0] . '-' . $parts[1];
}

/**
 * A hand edit, written into the world's own learning signals.
 *
 * ONLY WHEN THE WORLD ALREADY HAS A DATABASE. Opening one here would create it,
 * and the existence of `world.db` is what "this world has been lived in" means
 * everywhere else in this file — it is what refuses a seed reroll, on the good
 * grounds that the past is in the database by then. Fixing a typo before launch
 * must not quietly spend that. It also would not be worth much: an edit made
 * before anybody has spoken to anybody is a correction to a world nobody has met.
 *
 * Never throws. Learning is garnish; the edit itself has already been saved.
 */
function xeric_review_learn_edit(array $w, string $path, string $was, string $now): void
{
    $file = (string)$w['dir'] . '/world.db';
    if (!is_file($file) || trim($now) === '' || $was === $now) return;

    // Whose line was it? A character's own fields are about that character; a
    // place, the setting or the user's own details are about the world.
    $handle = '';
    if (preg_match('/^cast\.characters\.(\d+)\./', $path, $m)) {
        $handle = (string)($w['template']['cast']['characters'][(int)$m[1]]['handle'] ?? '');
    } elseif (preg_match('/^cast\.special_roles\.(\d+)\./', $path, $m)) {
        $handle = (string)($w['template']['cast']['special_roles'][(int)$m[1]]['character'] ?? '');
    }

    $spec  = xeric_review_field($path);
    $label = $spec !== null ? $spec[0] : $path;

    // WHAT THE CRUMB MAY QUOTE. A crumb with no handle is filed under the WORLD,
    // and world-bucket lessons are written into every speaker's prompt — that is
    // what the bucket is for. So the two VALUES, which learn.php hands to the
    // model with "take it literally", would carry a walled sentence past the
    // wall in a lesson nobody wrote by hand. The label and the path say what was
    // corrected, which is the whole of what the distiller needs; the words
    // themselves are what only some readers may have.
    $note = ($handle === '' && xeric_review_path_walled($w['template'], $path))
        ? 'this was rewritten by hand, the wording is behind a wall and is not repeated here'
        : 'was “' . mb_substr($was, 0, 160) . '”, now “' . mb_substr($now, 0, 160) . '”';

    try {
        $db = xeric_state_open($file);
        xeric_signal_add($db, 'edit', [
            'handle'  => $handle,
            'subject' => $label . ' (' . $path . ')',
            'n'       => mb_strlen($now),
            'note'    => $note,
            'world_epoch' => xeric_clock_epoch($db),
        ]);
    } catch (Throwable $e) {
        // the edit is saved either way
    }
}

/**
 * Is this edited path's TEXT something some reader of this world may not have?
 *
 * Fails closed twice over. Anything describing the hiding — a wall's own explain
 * or shown_as, a must_not_know — is walled by definition and is not a path any
 * wall could list. Anything in the seed is somebody's private past. Everything
 * else is compared against what the walls of THIS world actually hide, so an
 * ordinary world with no walls over its places goes on learning from its places.
 */
function xeric_review_path_walled(array $template, string $path): bool
{
    if (preg_match('#^(knowledge_walls|seed)\.#', $path)) return true;
    if (str_starts_with($path, 'cast.protagonist.')) return true;

    $hidden = [];
    foreach ((array)($template['knowledge_walls'] ?? []) as $wall) {
        foreach ((array)($wall['hidden'] ?? []) as $h) $hidden[(string)$h] = true;
    }
    if ($hidden === []) return false;

    // The editable world-level paths, in the vocabulary the walls speak.
    $paths = [];
    if (preg_match('#^setting\.(texture|canon_rules)\.#', $path, $m)) $paths = ['setting', 'setting.' . $m[1]];
    elseif (str_starts_with($path, 'setting.'))                       $paths = ['setting'];
    elseif (str_starts_with($path, 'user.'))                          $paths = ['user'];
    elseif (preg_match('#^places\.(\d+)\.#', $path, $m)) {
        $key = (string)($template['places'][(int)$m[1]]['key'] ?? '');
        $paths = $key !== '' ? ['places', 'places.' . $key] : ['places'];
    }

    foreach ($paths as $p) if (isset($hidden[$p])) return true;
    return false;
}

// ---------------------------------------------------------------------------
// The pronoun backfill
// ---------------------------------------------------------------------------
//
// Every cast forged before pronouns were a field has none, so the faces read
// each person's own prose and honestly grey anyone the prose does not settle.
// New forges ask at birth and the cog fixes one person at a time; this is the
// one-time repair for a whole old cast — ONE model call, only pronoun sets
// back, only the EMPTY fields ever written, and an uncertain answer is
// respected: it stays grey, because a name is never evidence.

/**
 * Who in this cast has no pronouns on record.
 *
 * Missing and empty are the same answer — the cog writes '' nowhere, so an
 * empty string is a field that has never been set, not a choice.
 *
 * @return array<string,int> handle => position in the cast
 */
function xeric_review_pronounless(array $t): array
{
    $out = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $i => $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        if (trim((string)($c['pronouns'] ?? '')) === '') $out[$h] = (int)$i;
    }
    return $out;
}

/**
 * Is this a pronoun set the engine can actually read?
 *
 * THE VOCABULARY IS xeric_pronouns()'S AND IS NOT COPIED HERE. That helper is
 * what every renderer resolves a set through, and it falls back to they/them
 * for anything it does not know — so known-ness is asked of it directly: a set
 * is valid when the helper answers something other than its fallback, or when
 * the set genuinely IS "they". A second copy of the word list would drift the
 * first time somebody taught the engine a new set.
 *
 * "unclear" is invalid by construction, which is the point: the model saying
 * it cannot tell must never become a stored answer.
 */
function xeric_review_pronoun_ok(string $set): bool
{
    $first = trim((string)(explode('/', mb_strtolower(trim($set)))[0] ?? ''));
    if ($first === '' || preg_match('/[^a-z]/', $first)) return false;
    return $first === 'they' || xeric_pronouns(['user' => ['pronouns' => $first]])['subj'] !== 'they';
}

/**
 * One question, once, for the whole cast: which pronoun set does each person's
 * own description use?
 *
 * Only the people in $missing are asked about, and only their display name and
 * self-descriptive prose ride along — the same fields the grey fallback reads,
 * because the model is being asked to do that reading better, not to know
 * things the page does not. The prompt says out loud that a name is not
 * evidence and that "unclear" is a real answer.
 *
 * @param array<string,int> $missing from xeric_review_pronounless()
 * @return array<string,string> handle => the model's raw answer, unvalidated —
 *                              xeric_review_pronoun_merge() is the gate
 */
function xeric_review_pronoun_ask(array $t, array $missing, array $endpoint): array
{
    if ($missing === []) return [];

    $rows = [];
    foreach ($missing as $h => $i) {
        $c = (array)($t['cast']['characters'][$i] ?? []);
        $desc = [];
        foreach (['one_line', 'appearance', 'voice', 'solace'] as $k) {
            $v = trim((string)($c[$k] ?? ''));
            if ($v !== '') $desc[] = $v;
        }
        $rows[] = '- ' . $h . ': ' . (trim((string)($c['display_name'] ?? '')) ?: $h)
            . ($desc !== [] ? ' — ' . mb_substr(implode(' ', $desc), 0, 320) : '');
    }

    $msgs = [
        ['role' => 'system', 'content' =>
            'You read a cast list and answer ONE question per person: which pronoun set '
            . 'their own description uses. Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "The cast:\n" . implode("\n", $rows) . "\n\n"
            . 'For each person, answer with a pronoun set: "he/him", "she/her", "they/them", '
            . '"ze/hir", "xe/xem" or "it/its". Read ONLY the description. A name is not '
            . 'evidence — if the description does not settle it, answer "unclear" rather '
            . 'than guessing. Reply with one JSON object mapping each handle to its answer, '
            . 'like {"' . (string)array_key_first($missing) . '": "she/her"}.'],
    ];

    $out = xeric_llm_json($endpoint, $msgs, ['tag' => 'pronouns', 'temperature' => 0.2, 'max_tokens' => 500]);
    // Some models wrap the map; take it wherever it is.
    if (isset($out['answers']) && is_array($out['answers'])) $out = $out['answers'];

    $answers = [];
    foreach ($missing as $h => $i) {
        $v = $out[$h] ?? null;
        if (is_array($v)) $v = reset($v);          // {"handle": {"pronouns": "she/her"}}
        if (is_string($v)) $answers[$h] = $v;
    }
    return $answers;
}

/**
 * The model's answers, folded onto the cast. The whole gate, in one place:
 *
 *   ONLY THE EMPTY FIELDS. The fold iterates what xeric_review_pronounless()
 *   found, so a pronoun somebody already chose — at birth, or by hand in the
 *   cog — cannot be overwritten by a machine, whatever the model answered.
 *
 *   ONLY SETS THE ENGINE READS. Everything else, "unclear" first among them,
 *   leaves that person exactly as grey as before, with the reason kept so the
 *   page can say it. Uncertain is an answer this feature was built to respect.
 *
 * Pure: the caller saves the returned template through xeric_review_save(),
 * the same validated write every edit takes, so one ↺ is the whole batch back.
 *
 * @return array{template:array,filled:array<string,string>,left:array<string,string>}
 */
function xeric_review_pronoun_merge(array $t, array $answers): array
{
    $filled = [];
    $left   = [];
    foreach (xeric_review_pronounless($t) as $h => $i) {
        $raw = trim((string)($answers[$h] ?? ''));
        $set = mb_strtolower((string)preg_replace('/\s*/u', '', $raw));
        if ($raw === '' || $set === 'unclear' || $set === 'unknown') { $left[$h] = 'unclear'; continue; }
        if (!xeric_review_pronoun_ok($set)) { $left[$h] = 'not a set this app reads'; continue; }
        $set = mb_substr($set, 0, 40);
        $t['cast']['characters'][$i]['pronouns'] = $set;
        $filled[$h] = $set;
    }
    return ['template' => $t, 'filled' => $filled, 'left' => $left];
}

// ---------------------------------------------------------------------------
// Rerolling one section
// ---------------------------------------------------------------------------

/** The sections a reroll may name, and what each one costs to say out loud. */
function xeric_review_sections(): array
{
    return [
        'concept'     => 'the xeric itself, its name, its voice, and the rules it obeys',
        'places'      => 'every room in it',
        'character'   => 'one person',
        'cast'        => 'everybody',
        'walls'       => 'who is kept in the dark, and about what',
        'protagonist' => 'whose story this is',
        'seed'        => 'the six weeks behind it',
    ];
}

/** The concept block, rebuilt from a template — what pass 2 and 4 expect to read. */
function xeric_review_concept_of(array $t): array
{
    return [
        'meta' => [
            'name'        => (string)($t['meta']['name'] ?? ''),
            'description' => (string)($t['meta']['description'] ?? ''),
            'rating'      => (string)($t['meta']['rating'] ?? 'sfw'),
            'themes'      => array_values((array)($t['meta']['themes'] ?? [])),
        ],
        'setting' => [
            'locale'      => (string)($t['setting']['locale'] ?? ''),
            'era'         => (string)($t['setting']['era'] ?? ''),
            'texture'     => array_values((array)($t['setting']['texture'] ?? [])),
            'canon_rules' => array_values((array)($t['setting']['canon_rules'] ?? [])),
        ],
        'world_mood' => (array)($t['world_mood'] ?? []),
    ];
}

/**
 * Places moved under a cast that was written for the old ones.
 *
 * Rerolling the rooms would otherwise empty every schedule in the world: the
 * validator drops a shift whose place does not exist, and four people with no
 * week are four people who are never anywhere. So the old keys are mapped onto
 * the new ones BY POSITION, with the workplace pinned to the workplace, which is
 * the only mapping that is both total and not a guess about meaning.
 *
 * THE WORKPLACE ORBIT MOVES WITH IT. xeric_forge_orbits() names the first orbit
 * after the workplace PLACE, so a rerolled workplace leaves `cast.orbits[0].key`
 * pointing at a room that no longer exists. Nothing complained: the orbit is
 * still declared, so the world went on validating — until the next cast reroll
 * rebuilt the orbits from the new places while the kept baseline walls still
 * named the old one, and from then on every cast reroll cost a model call per
 * character and a rate-limit hit and then refused, forever, for that world. The
 * orbit is a reference target, so it is remapped here with everything else that
 * points at a place key.
 */
function xeric_review_remap_places(array $t, array $old, array $new): array
{
    $oldKeys = array_map(fn($p) => (string)$p['key'], $old);
    $newKeys = array_map(fn($p) => (string)$p['key'], $new);
    if ($newKeys === []) return $t;

    $map = [];
    foreach ($oldKeys as $i => $k) $map[$k] = $newKeys[$i] ?? $newKeys[0];

    $oldWk = xeric_forge_workplace_key($old);
    $newWk = xeric_forge_workplace_key($new) ?? $newKeys[0];
    if ($oldWk !== null) $map[$oldWk] = $newWk;

    $pick = fn(string $k) => $map[$k] ?? $newKeys[0];

    foreach ((array)($t['cast']['characters'] ?? []) as $i => $c) {
        foreach ((array)($c['week'] ?? []) as $j => $wk) {
            $where = (string)($wk['where'] ?? '');
            if ($where === '') continue;
            $t['cast']['characters'][$i]['week'][$j]['where'] = $pick($where);
        }
    }
    $uw = (string)($t['user']['occupation']['workplace_key'] ?? '');
    $t['user']['occupation']['workplace_key'] = $uw !== '' ? $pick($uw) : $newWk;

    // Residents are recomputed rather than mapped: they are a derived list (the
    // people whose first week block puts them here) and a mapped one would list
    // somebody in a room they no longer work in.
    $byPlace = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $k = (string)($c['week'][0]['where'] ?? '');
        if ($k !== '') $byPlace[$k][] = (string)$c['handle'];
    }
    foreach ($new as $i => $p) {
        $new[$i]['residents'] = array_slice($byPlace[(string)$p['key']] ?? [], 0, 3);
    }
    $t['places'] = array_values($new);

    // Only the workplace orbit is named after a place (xeric_forge_orbits), so
    // that is the only orbit key a place reroll may move — renaming any orbit
    // that merely happens to share a place's key would silently re-aim whatever
    // wall points at it, and a wall pointed somewhere else protects the wrong
    // person.
    if ($oldWk !== null && $newWk !== $oldWk) $t = xeric_review_rename_orbit($t, $oldWk, $newWk);

    return $t;
}

/**
 * One orbit key, changed everywhere anything refers to it.
 *
 * Every reference the validator checks (engine/world.php): the declaration
 * itself, each character's and each fixture's membership, a circle's source
 * orbits, and a wall's audience — that last one being why this exists at all.
 * The wall keys are left alone: `privacy_<orbit>` is a name, not a reference,
 * and special_roles point at wall keys by that name.
 */
function xeric_review_rename_orbit(array $t, string $from, string $to): array
{
    if ($from === '' || $to === '' || $from === $to) return $t;

    foreach ((array)($t['cast']['orbits'] ?? []) as $i => $o) {
        if ((string)($o['key'] ?? '') === $from) $t['cast']['orbits'][$i]['key'] = $to;
    }
    foreach (['characters', 'fixtures'] as $group) {
        foreach ((array)($t['cast'][$group] ?? []) as $i => $c) {
            if ((string)($c['orbit'] ?? '') === $from) $t['cast'][$group][$i]['orbit'] = $to;
        }
    }
    foreach ((array)($t['cast']['circles'] ?? []) as $i => $c) {
        foreach ((array)($c['members_from_orbits'] ?? []) as $j => $o) {
            if ((string)$o === $from) $t['cast']['circles'][$i]['members_from_orbits'][$j] = $to;
        }
    }
    foreach ((array)($t['knowledge_walls'] ?? []) as $i => $wall) {
        if ((string)($wall['audience']['orbit'] ?? '') === $from) {
            $t['knowledge_walls'][$i]['audience']['orbit'] = $to;
        }
    }
    return $t;
}

/**
 * Re-run ONE pass against the model and fold the answer back in.
 *
 * Never throws for a model's sake — every pass here has forge.php's own
 * deterministic fallback behind it — and never returns a template that has not
 * been through the validator. A section that comes back invalid is discarded
 * whole and the old one is kept, because half a reroll is worse than none.
 *
 * `saved` is false when the answer was not worth a save: a reroll that came back
 * identical, or one the model was never reached for. Both would otherwise cost
 * the single step of undo and give nothing for it.
 *
 * @param array $o  what: section · index: which character · endpoint
 * @return array{template:array,seed:array,notes:array,changed:string,saved:bool}
 */
function xeric_review_reroll(array $w, array $o, ?callable $note = null): array
{
    $t        = $w['template'];
    $seed     = $w['seed'];
    $what     = (string)($o['what'] ?? '');
    $endpoint = (array)($o['endpoint'] ?? []);
    $answers  = (array)($t['forge']['answers'] ?? []);
    $counts   = xeric_review_counts($t);
    $notes    = [];
    $say = function (string $m) use (&$notes, $note): void { $notes[] = $m; if ($note) $note($m); };

    $before = $t;
    $beforeSeed = $seed;

    if ($what === 'draft') {
        // THE WHOLE BOOK AGAIN: same interview answers, same address, same
        // owner, every pass re-run — a forge minus the wizard. The page-edit
        // fold is skipped on purpose (a redraft is a new draft, not a merge),
        // and the back cover is kept, because the build just wrote one that
        // matches. One save, so ↺ is the whole old draft back.
        $out = xeric_forge_build($answers, $endpoint, [
            'interview' => xeric_forge_interview(XERIC_WEB_LIB . '/forge/interview.json'),
            'places'    => max(1, count((array)($t['places'] ?? []))),
            'cast'      => max(1, count((array)($t['cast']['characters'] ?? []))),
            'seed'      => true,
        ], $say);
        $t2 = xeric_review_mark_pending($out['template']);
        xeric_review_save($w['slug'], $t2, (array)$out['seed']);
        // The baked past goes with the draft it was baked FROM. Launch is the
        // door, so nothing in an unlaunched world's database is anything a
        // human did; left behind, the next open would replay an old world's
        // six weeks under a brand-new town.
        foreach (['world.db', 'world.db-wal', 'world.db-shm'] as $f) {
            @unlink(rtrim((string)$w['dir'], '/') . '/' . $f);
        }
        return ['template' => $t2, 'seed' => (array)$out['seed'],
                'notes' => array_merge($notes, array_map('strval', (array)$out['notes'])),
                'changed' => 'draft', 'saved' => true,
                'was' => $before['meta']['name'] ?? '', 'was_seed' => count((array)$beforeSeed['events'])];
    }

    if ($what === 'concept') {
        $c = xeric_forge_pass_concept($answers, $endpoint, $say);
        $t['meta']['name']        = (string)$c['meta']['name'];
        $t['meta']['description'] = (string)$c['meta']['description'];
        $t['meta']['themes']      = array_values((array)$c['meta']['themes']);
        $t['setting']['locale']      = (string)$c['setting']['locale'];
        $t['setting']['era']         = (string)$c['setting']['era'];
        $t['setting']['texture']     = array_values((array)$c['setting']['texture']);
        $t['setting']['canon_rules'] = array_values((array)$c['setting']['canon_rules']);
        $t['world_mood']['axis']   = $c['world_mood']['axis'];
        $t['world_mood']['motifs'] = $c['world_mood']['motifs'];
        $t['user']['location']     = (string)$c['setting']['locale'];
        $say('concept: this place is now ' . $t['meta']['name']);
    } elseif ($what === 'places') {
        $old = (array)$t['places'];
        $new = xeric_forge_pass_places($answers, xeric_review_concept_of($t), $endpoint, $counts['places'], $say);
        $t = xeric_review_remap_places($t, $old, $new);
        $say('places: the cast\'s weeks were moved onto the new rooms, in the same order');
    } elseif ($what === 'cast') {
        $leaving = xeric_review_cast_names($t);
        $oldOrbits = array_map(fn($x) => (string)($x['key'] ?? ''), (array)($t['cast']['orbits'] ?? []));

        $cast = xeric_forge_pass_cast($answers, xeric_review_concept_of($t), (array)$t['places'],
            $endpoint, $counts['cast'], $say);
        $t['cast']['orbits']     = array_values($cast['orbits']);
        $t['cast']['characters'] = array_values($cast['characters']);
        // Everything that pointed at the old people points at nobody now.
        $t['cast']['special_roles'] = [];
        $t['knowledge_walls'] = array_values(array_filter((array)($t['knowledge_walls'] ?? []),
            fn($x) => (string)($x['source'] ?? '') === 'baseline'));
        // The privacy baseline is kept rather than rebuilt, so it has to be
        // re-aimed: the orbits it names were just replaced. Dropping a wall that
        // no longer resolves would be the easy fix and the wrong one — a missing
        // privacy wall is every character's interior in everybody's prompt.
        $t = xeric_review_reaim_walls($t, $oldOrbits, $say);
        unset($t['cast']['protagonist']);
        $say('cast: the walls and whose-story-this-is were cleared, they named people who no longer exist');
        $t = xeric_review_reseat_residents($t);
        $seed = xeric_review_forget_seed($seed, $leaving, $say);
    } elseif ($what === 'character') {
        $chars = array_values((array)$t['cast']['characters']);
        $idx = (int)($o['index'] ?? -1);
        if (!isset($chars[$idx])) throw new RuntimeException('there is nobody at that position in the cast');
        $old = $chars[$idx];

        $sofar = [];
        $taken = [];
        foreach ($chars as $i => $c) {
            if ($i === $idx) continue;
            $sofar[] = '- ' . $c['display_name'] . ', ' . $c['one_line'];
            $taken[(string)$c['handle']] = true;
        }
        $say('cast: rewriting ' . (string)$old['display_name'] . ', and nobody else');

        // The person leaving is named as somebody to avoid, so a reroll against
        // a dead model walks the archetype table instead of handing back the
        // exact person it was asked to replace — which spent the one-step undo
        // and changed nothing.
        $new = xeric_forge_person($answers, xeric_review_concept_of($t), (array)$t['places'], $endpoint,
            $idx, (string)($old['orbit'] ?? 'outside'), $sofar, $taken, $say,
            'reroll of ' . (string)$old['display_name'], [(string)($old['display_name'] ?? '')]);

        // The de-duper compares down the list and rewrites the LATER of two
        // matching interiors, so the new person goes last: a reroll must never
        // silently rewrite somebody the user did not ask about.
        $others = $chars;
        unset($others[$idx]);
        $deduped = xeric_forge_dedupe_cast(array_merge(array_values($others), [$new]), (array)$t['places'], $say);
        $new = $deduped[count($deduped) - 1];

        $chars[$idx] = $new;
        $t['cast']['characters'] = array_values($chars);

        // Anything that named the person who just left names nobody.
        $gone = (string)$old['handle'];
        if ($gone !== (string)$new['handle']) {
            $t = xeric_review_forget_handle($t, $gone, $say);
            $seed = xeric_review_forget_seed($seed, [$gone => (string)($old['display_name'] ?? '')], $say);
        }
        $t = xeric_review_reseat_residents($t);
        $say('cast: ' . (string)$old['display_name'] . ' is now ' . (string)$new['display_name']);
    } elseif ($what === 'walls') {
        $out = xeric_forge_pass_walls($t, $endpoint, $say);
        $t['knowledge_walls'] = array_values((array)$out['knowledge_walls']);
        $t['cast']['special_roles'] = array_values((array)$out['special_roles']);
    } elseif ($what === 'protagonist') {
        $p = xeric_forge_pass_protagonist($t, $endpoint, $say);
        if ($p === null) {
            $say('you said you are the main character, so nobody else is, there is nothing here to reroll');
            unset($t['cast']['protagonist']);
        } else {
            $t['cast']['protagonist'] = $p;
        }
    } elseif ($what === 'seed') {
        if (!empty($w['lived'])) {
            throw new RuntimeException('This xeric has already been lived in: its past is in the database now, '
                . 'and rewriting the file would not change what anybody remembers. Forge a new xeric to try a '
                . 'different history.');
        }
        $seed = xeric_forge_attempt('seed pass', fn() => xeric_forge_pass_seed($t, $endpoint, $say),
            fn() => xeric_forge_default_seed($t), $say);
        $say('seed: ' . count((array)$seed['events']) . ' events, ' . count((array)$seed['memories']) . ' memories');
    } else {
        throw new RuntimeException("'" . mb_substr($what, 0, 30) . "' is not a section of this xeric");
    }

    // The gate. A reroll that does not validate is thrown away whole.
    try {
        xeric_world_validate($t, 'this xeric');
    } catch (Throwable $e) {
        $say('that reroll did not hold together (' . xeric_review_plain($e->getMessage()) . '), repairing');
        $t = xeric_forge_repair($t, $say);
        try {
            xeric_world_validate($t, 'this xeric');
        } catch (Throwable $e2) {
            throw new RuntimeException('The model\'s new ' . $what . ' did not fit this xeric ('
                . xeric_review_plain($e2->getMessage()) . '), so nothing was changed. Try it again, '
                . 'the same button, and a different answer.');
        }
    }

    // A REROLL THAT CHANGED NOTHING MUST NOT BE SAVED. Every save rolls the last
    // good copy into world-template.prev.json, so a no-op save spends the one
    // step of undo the user was about to press — and the reroll a dead model
    // gives you IS a no-op: xeric_forge_default_character() is indexed by
    // position, so the built-in fallback hands back the very person it handed
    // back when the world was built. Falling back at all is treated the same
    // way, because a stock person quietly replacing a model-written one is not
    // what "reroll" was pressed for either.
    $seedMoved = $seed !== $beforeSeed;
    $fellBack  = false;
    foreach ($notes as $n) if (str_contains($n, 'used the built-in default')) $fellBack = true;

    if ($t === $before && !$seedMoved) {
        $say($fellBack
            ? 'the model could not be reached, so this came back as the built-in default, which is the '
              . 'person who was already here. Nothing was saved, and the step back is still yours to take.'
            : 'that reroll came back identical to what is already here, so nothing was saved.');
        return ['template' => $t, 'seed' => $seed, 'notes' => $notes, 'changed' => $what, 'saved' => false,
                'was' => $before['meta']['name'] ?? '', 'was_seed' => count((array)$beforeSeed['events'])];
    }
    if ($fellBack) {
        $say('the model could not be reached, so this came from the built-in default rather than from a '
            . 'reroll. Nothing was saved, press it again when the model is back.');
        return ['template' => $t, 'seed' => $seed, 'notes' => $notes, 'changed' => $what, 'saved' => false,
                'was' => $before['meta']['name'] ?? '', 'was_seed' => count((array)$beforeSeed['events'])];
    }

    // WHAT THE PAGE DID WHILE THIS RAN. A reroll is detached and can take two
    // minutes; the boxes stay live the whole time, and until now the worker
    // wrote the template it had opened at the START back over the file, so every
    // hand edit made in the meantime was gone — off the page's next repaint too,
    // with prev.json now holding the version that HAD the edit. So the file is
    // re-read here and the reroll is folded onto it: the reroll wins where it
    // moved something, and every other byte is whatever is on disk now.
    [$t, $seed] = xeric_review_fold_onto_disk($w, $before, $t, $beforeSeed, $seed, $what, $say);

    // A COVER IS WRITTEN ABOUT A BOOK, and the book just changed. Dropped, not
    // rewritten here (a reroll is already someone waiting): the forge page
    // writes a fresh one on its next sight of a coverless world.
    unset($t['meta']['teaser']);

    xeric_review_save($w['slug'], $t, $seedMoved ? $seed : null);

    return ['template' => $t, 'seed' => $seed, 'notes' => $notes, 'changed' => $what, 'saved' => true,
            'was' => $before['meta']['name'] ?? '', 'was_seed' => count((array)$beforeSeed['events'])];
}

/**
 * The reroll's answer, folded onto the files as they stand NOW.
 *
 * Three-way, and the arithmetic is the whole of it: where the reroll left a
 * value alone, whatever is on disk wins (that is somebody's typing); where the
 * reroll moved it, the reroll wins (that is what was pressed). The two only meet
 * inside the section that was rerolled, and there the fresher hand is the one
 * that just ran.
 *
 * If the folded world will not validate, NOTHING is saved and the reroll is
 * lost. That is the deliberate way round: a reroll is one button and a wait,
 * and the sentences somebody typed while it ran are not replaceable.
 *
 * @return array{0:array,1:array}  template, seed
 * @throws RuntimeException in a sentence, when the two cannot both be kept
 */
function xeric_review_fold_onto_disk(array $w, array $before, array $t,
                                     array $beforeSeed, array $seed, string $what, callable $say): array
{
    try {
        $live = xeric_world_load((string)$w['path']);
    } catch (Throwable $e) {
        return [$t, $seed];      // nothing readable to fold onto; the save is the repair
    }

    if ($live !== $before) {
        $folded = xeric_review_fold($before, $t, $live);
        try {
            xeric_world_validate($folded, 'this xeric');
        } catch (Throwable $e) {
            throw new RuntimeException('This xeric was edited by hand while the ' . $what . ' was being '
                . 'rerolled, and the two cannot both be kept ('
                . xeric_review_plain($e->getMessage()) . '). Nothing was saved, so what you typed is still '
                . 'there, reroll it again now that the edit has landed.');
        }
        $t = $folded;
        $say('an edit you made while this was running was kept, the reroll was folded onto it, not over it');
    }

    $liveSeed = xeric_review_read_seed((string)$w['dir']);
    if ($liveSeed !== $beforeSeed && $seed !== $beforeSeed) {
        $seed = xeric_review_fold($beforeSeed, $seed, $liveSeed);
    } elseif ($seed === $beforeSeed) {
        $seed = $liveSeed;
    }

    return [$t, $seed];
}

/**
 * A three-way merge of two arrays that share an ancestor.
 *
 * Node by node: untouched by one side means take the other's; touched by both
 * means recurse while both are arrays, and give it to `$mine` when they are not.
 * A key one side deleted stays deleted, which is what makes an unset protagonist
 * survive the fold.
 */
function xeric_review_fold(array $base, array $mine, array $theirs): array
{
    if ($mine === $base)   return $theirs;
    if ($theirs === $base) return $mine;

    $out = [];
    foreach (array_keys($mine + $theirs) as $k) {
        $inBase   = array_key_exists($k, $base);
        $inMine   = array_key_exists($k, $mine);
        $inTheirs = array_key_exists($k, $theirs);

        if ($inBase && !$inMine)   continue;             // the reroll dropped it
        if ($inBase && !$inTheirs) continue;             // an edit dropped it
        if (!$inMine)   { $out[$k] = $theirs[$k]; continue; }
        if (!$inTheirs) { $out[$k] = $mine[$k];   continue; }

        if ($inBase && is_array($base[$k]) && is_array($mine[$k]) && is_array($theirs[$k])) {
            $out[$k] = xeric_review_fold($base[$k], $mine[$k], $theirs[$k]);
        } elseif ($inBase && $mine[$k] === $base[$k]) {
            $out[$k] = $theirs[$k];
        } else {
            $out[$k] = $mine[$k];
        }
    }
    return $out;
}

/** A world's seed.json as the reroll reads it — same shape xeric_review_open() hands out. */
function xeric_review_read_seed(string $dir): array
{
    $seed = json_decode((string)@file_get_contents($dir . '/seed.json'), true);
    if (!is_array($seed)) $seed = ['events' => [], 'memories' => []];
    $seed['events']   = array_values((array)($seed['events'] ?? []));
    $seed['memories'] = array_values((array)($seed['memories'] ?? []));
    return $seed;
}

/** Residents are derived from the week, so after any cast or place change they are recomputed. */
function xeric_review_reseat_residents(array $t): array
{
    $byPlace = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $k = (string)($c['week'][0]['where'] ?? '');
        if ($k !== '') $byPlace[$k][] = (string)$c['handle'];
    }
    foreach ((array)($t['places'] ?? []) as $i => $p) {
        $t['places'][$i]['residents'] = array_slice($byPlace[(string)$p['key']] ?? [], 0, 3);
    }
    return $t;
}

/** Drop every reference to somebody who is no longer in the cast. */
function xeric_review_forget_handle(array $t, string $handle, ?callable $say = null): array
{
    $hit = false;

    $roles = [];
    $droppedWalls = [];
    foreach ((array)($t['cast']['special_roles'] ?? []) as $sr) {
        if ((string)($sr['character'] ?? '') === $handle) {
            $hit = true;
            foreach ((array)($sr['walls'] ?? []) as $wk) $droppedWalls[(string)$wk] = true;
            continue;
        }
        $roles[] = $sr;
    }
    $t['cast']['special_roles'] = array_values($roles);

    $walls = [];
    foreach ((array)($t['knowledge_walls'] ?? []) as $wall) {
        if (isset($droppedWalls[(string)($wall['key'] ?? '')])) continue;
        if ((string)($wall['audience']['handle'] ?? '') === $handle) { $hit = true; continue; }
        $walls[] = $wall;
    }
    $t['knowledge_walls'] = array_values($walls);

    if ((string)($t['cast']['protagonist']['handle'] ?? '') === $handle) {
        unset($t['cast']['protagonist']);
        $hit = true;
        $say && $say('whose story this is was cleared, it was theirs');
    }

    foreach ((array)($t['places'] ?? []) as $i => $p) {
        $t['places'][$i]['residents'] = array_values(array_filter(
            array_map('strval', (array)($p['residents'] ?? [])), fn($r) => $r !== $handle));
    }
    foreach ((array)($t['cast']['characters'] ?? []) as $i => $c) {
        $rel = (array)($c['relationships'] ?? []);
        foreach (['roommates', 'friend_pairs'] as $k) {
            if (!isset($rel[$k])) continue;
            $t['cast']['characters'][$i]['relationships'][$k] = array_values(array_filter(
                array_map('strval', (array)$rel[$k]), fn($r) => $r !== $handle));
        }
        if (isset($rel['attraction_seeds']) && is_array($rel['attraction_seeds'])
            && array_key_exists($handle, $rel['attraction_seeds'])) {
            unset($t['cast']['characters'][$i]['relationships']['attraction_seeds'][$handle]);
        }
    }

    if ($hit) $say && $say('dropped what pointed at the person who was replaced');
    return $t;
}

/** Everyone currently in the cast: handle => the name the world calls them. */
function xeric_review_cast_names(array $t): array
{
    $out = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '') $out[$h] = (string)($c['display_name'] ?? '');
    }
    return $out;
}

/**
 * Walls kept across a cast reroll, re-aimed at the orbits that replaced theirs.
 *
 * The orbits are rebuilt by the cast pass, so a kept baseline wall names one
 * that is gone — which is what made a cast reroll after a places reroll refuse
 * to validate forever.
 *
 * BY KEY, NEVER BY POSITION. This used to map old index to new index, on the
 * theory that both lists come from xeric_forge_orbits() in a fixed order — but
 * when both lists DO come from there they carry the same keys and the survive-
 * by-key check already placed every wall, so the positional path only ever ran
 * in the one case its justification was false: a model-rewritten orbit list,
 * where docs/WORLD_TEMPLATE.md says keys are free-form and order means
 * nothing. Old index 2 landing on whatever now sits at index 2 aimed privacy
 * walls at semantically random circles.
 *
 * FAILS TOWARD MORE PROTECTION, actually. A wall whose orbit is gone now
 * covers EVERY new orbit: the original row keeps its key (special_roles
 * reference walls by key, and that link must survive) and takes the first,
 * clones with suffixed keys take the rest. Over-covering costs some characters
 * a view of interiors until the owner re-aims by hand; under-covering is
 * somebody's interior in a stranger's prompt — and only one of those is
 * recoverable. Each move is reported BY NAME, so the owner can see which
 * walls want their aim checked rather than being told a count.
 */
function xeric_review_reaim_walls(array $t, array $oldOrbits, ?callable $say = null): array
{
    $new = [];
    foreach ((array)($t['cast']['orbits'] ?? []) as $o) {
        $k = (string)($o['key'] ?? '');
        if ($k !== '') $new[] = $k;
    }
    if ($new === []) return $t;
    $declared = array_flip($new);

    $added = [];
    foreach ((array)($t['knowledge_walls'] ?? []) as $i => $wall) {
        $o = (string)($wall['audience']['orbit'] ?? '');
        if ($o === '' || isset($declared[$o])) continue;   // the key survived: the one mapping that means anything

        $wk = (string)($wall['key'] ?? 'wall');
        $t['knowledge_walls'][$i]['audience']['orbit'] = $new[0];
        foreach (array_slice($new, 1) as $nk) {
            $clone = $wall;
            $clone['key'] = $wk . '.' . $nk;               // unique, and never the key a role references
            $clone['audience']['orbit'] = $nk;
            $added[] = $clone;
        }
        if ($say) $say("walls: '" . $wk . "' was aimed at '" . $o . "', which the reroll removed — "
            . 'it now covers every orbit (' . implode(', ', $new) . '); re-aim it in the review '
            . 'if that is broader than it was written for');
    }
    foreach ($added as $c) $t['knowledge_walls'][] = $c;
    return $t;
}

/**
 * The baked past, with the people who are no longer in it taken out.
 *
 * A cast or character reroll used to leave seed.json addressed to the dead: the
 * memories went nowhere (engine/seed.php drops a memory whose owner is not in
 * the cast, so the world silently launched with fewer than it claims), the event
 * prose went on naming somebody who was never here, and the review page drew a
 * card headed by a handle that no longer answers to anything.
 *
 * Dropped rather than reassigned, on purpose. A seeded memory is written from
 * the inside of one particular person; handing it to whoever replaced them would
 * be putting words in a stranger's head — and where the departed was protected,
 * it would be handing their private history to somebody the walls do not cover.
 *
 * @param array<string,string> $gone handle => the display name they had
 */
function xeric_review_forget_seed(array $seed, array $gone, ?callable $say = null): array
{
    if ($gone === []) return $seed;

    $keys = [];
    foreach ($gone as $handle => $name) {
        $keys[xeric_seed_norm((string)$handle)] = true;
        if ((string)$name !== '') $keys[xeric_seed_norm((string)$name)] = true;
    }
    unset($keys['']);
    $isGone = fn(string $who) => isset($keys[xeric_seed_norm($who)]);

    $events = [];
    $lostEvents = 0;
    foreach ((array)($seed['events'] ?? []) as $e) {
        $hit = false;
        foreach ((array)($e['participants'] ?? $e['who'] ?? []) as $p) {
            if ($isGone((string)$p)) $hit = true;
        }
        // The prose names people the participant list does not, and a paragraph
        // about somebody who was never in this world is worse than a shorter past.
        $text = (string)($e['title'] ?? '') . ' ' . (string)($e['prose'] ?? '');
        foreach ($gone as $name) {
            if ((string)$name !== '' && mb_stripos($text, (string)$name) !== false) $hit = true;
        }
        if ($hit) { $lostEvents++; continue; }
        $events[] = $e;
    }

    $mems = [];
    $lostMems = 0;
    foreach ((array)($seed['memories'] ?? []) as $m) {
        if ($isGone((string)($m['handle'] ?? ''))) { $lostMems++; continue; }
        $mems[] = $m;
    }

    if ($lostEvents > 0 || $lostMems > 0) {
        $say && $say('the past lost ' . $lostEvents . ' thing(s) that happened and ' . $lostMems
            . ' memor(ies), they were about people who are not in this xeric any more. '
            . 'Reroll the past to give it one again.');
    }

    $seed['events']   = array_values($events);
    $seed['memories'] = array_values($mems);
    return $seed;
}

// ---------------------------------------------------------------------------
// The page, in sections
// ---------------------------------------------------------------------------
//
// ONE RENDERER PER SECTION, used twice: once when the page is built and once
// when a reroll lands and the browser swaps that section's body out. ui.php
// makes the same choice for the forge's result screen and gives the reason —
// what you watched happen and what you come back to must not be able to
// disagree. A reroll therefore repaints from the SAVED file, never from the
// worker's idea of what it just did.

/**
 * Write one field, from everything else this xeric already says.
 *
 * THE CONTEXT IS THE POINT. A model asked "write a voice" writes a voice for
 * nobody; asked "write the voice of the 58-year-old who runs the diner in a
 * river town in 1973, whose sore spot is being asked if she is sure", it writes
 * one that belongs. So this hands it three things: what the xeric IS, what the
 * thing being edited is (the whole place, or the whole person, minus the field
 * in question), and the field's own name.
 *
 * THE FIELD IN QUESTION IS REMOVED FROM ITS OWN CONTEXT. Left in, a model
 * rewrites it — hands back a polish of what is there, which is the one thing a
 * dice is not for. It is for the box that is empty.
 *
 * @param string $label the section's own word for this kind of field
 */
function xeric_review_roll(array $t, string $path, string $label, array $endpoint): string
{
    $steps  = explode('.', $path);
    $leaf   = (string)array_pop($steps);
    $parent = $steps === [] ? [] : (array)xeric_review_get($t, implode('.', $steps));

    // A list index for a leaf ("tells.2") makes the parent the list itself,
    // which says nothing about who it belongs to. Step up once more.
    if (ctype_digit($leaf) && $steps !== []) {
        $up = $steps;
        $leaf = (string)array_pop($up) . ' (one of several)';
        $parent = $up === [] ? [] : (array)xeric_review_get($t, implode('.', $up));
    }

    unset($parent[explode(' ', $leaf)[0]]);
    $parent = xeric_review_thin($parent);

    $name  = (string)($t['meta']['name'] ?? 'this place');
    $where = trim((string)($t['setting']['locale'] ?? '') . ' ' . (string)($t['setting']['era'] ?? ''));
    $rating = (string)($t['meta']['rating'] ?? 'clean');

    $msgs = [
        ['role' => 'system', 'content' =>
            'You fill in one field of a story world, in the register of what is already there. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "The world is called \"$name\"" . ($where !== '' ? ", $where" : '') . ".\n"
            . "Content rating: $rating.\n\n"
            . ($parent !== []
                ? "This is what is already written about the thing you are filling in:\n"
                  . json_encode($parent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
                : "Nothing else about it has been written yet.\n\n")
            . "Write the field \"$leaf\" ($label). One value, in the same voice as the rest, "
            . "concrete and specific, no more than 25 words unless the field is plainly a longer "
            . "description. Do not restate the other fields.\n\n"
            . 'Reply with one JSON object holding just that field, either '
            . '{"value": "..."} or {"' . $leaf . '": "..."}.'],
    ];

    $out = xeric_llm_json($endpoint, $msgs, ['tag' => 'roll', 'temperature' => 1.0, 'max_tokens' => 260]);

    // THREE PLACES TO LOOK, because a model asked for {"value": …} about a field
    // called one_line very reasonably answers {"one_line": …}. Insisting on the
    // wrapper name meant two rolls in three came back "empty" while the model had
    // answered perfectly every time. Take the value it meant.
    $key = explode(' ', $leaf)[0];
    $v = $out['value'] ?? ($out[$key] ?? null);
    if ($v === null) {
        foreach ($out as $cand) {
            if (is_string($cand) && trim($cand) !== '') { $v = $cand; break; }
            if (is_array($cand) && $cand !== [] && is_scalar(reset($cand))) { $v = $cand; break; }
        }
    }
    if (is_array($v)) $v = implode(', ', array_map('strval', $v));
    return trim(mb_substr((string)$v, 0, 400));
}

/**
 * The parts of a node worth showing a model: prose, not plumbing.
 *
 * Handles, keys, indexes and nested machinery say nothing about who somebody is
 * and cost tokens on every roll.
 */
function xeric_review_thin(array $node): array
{
    $out = [];
    foreach ($node as $k => $v) {
        if (in_array((string)$k, ['handle', 'key', 'id', 'week', 'schedule', 'economies',
                                  'systems', 'wall', 'walls', 'knows', 'secrets'], true)) continue;
        if (is_array($v)) {
            $flat = array_filter($v, 'is_scalar');
            if ($flat !== []) $out[$k] = array_values(array_map('strval', $flat));
            continue;
        }
        if (is_scalar($v) && trim((string)$v) !== '') $out[$k] = $v;
    }
    return $out;
}

/** A text box bound to a dotted path. This is the whole of "edit by hand". */
function xeric_review_input(string $path, $value, string $label = '', string $kind = 'line'): string
{
    $v = is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
    $id = 'f-' . preg_replace('/[^a-z0-9]+/i', '-', $path);
    $box = $kind === 'text'
        ? '<textarea class="ed" id="' . h($id) . '" data-path="' . h($path) . '" rows="2">' . h($v) . '</textarea>'
        : '<input class="ed' . ($kind === 'time' ? ' short' : '') . '" id="' . h($id) . '" type="text" '
          . 'data-path="' . h($path) . '" value="' . h($v) . '" autocomplete="off" spellcheck="false">';

    // THE DICE. One renderer for every editable field in the review step, so it
    // arrives on all of them at once and cannot be forgotten on the one somebody
    // is stuck in front of. It rolls THIS field against everything else already
    // written, which is the difference between a suggestion and a non sequitur.
    return '<div class="fld">'
        . ($label !== '' ? '<label class="flab" for="' . h($id) . '">' . h($label) . '</label>' : '')
        . '<span class="fldbox">' . $box
        . '<button type="button" class="dice" data-path="' . h($path) . '" tabindex="-1"'
        . ' title="Write this one for me, from everything else in this xeric">&#9860;</button>'
        . '</span><p class="ferr" hidden></p></div>';
}

/** A run of read-only lines — things the review step shows but does not let you retype. */
function xeric_review_lines(array $items, string $cls = 'quiet'): string
{
    if ($items === []) return '';
    $out = '<ul class="plain">';
    foreach ($items as $i) $out .= '<li class="' . h($cls) . '">' . h((string)$i) . '</li>';
    return $out . '</ul>';
}

/** Every section, in the order the page shows them. */
function xeric_review_section_html(string $sec, array $w): string
{
    $t = $w['template'];
    $seed = $w['seed'];
    $slug = (string)$w['slug'];
    ob_start();

    if ($sec === 'concept') {
        echo xeric_review_input('meta.name', $t['meta']['name'] ?? '', 'the name of this place');
        echo xeric_review_input('meta.description', $t['meta']['description'] ?? '', 'one line about it', 'text');
        echo xeric_review_input('setting.locale', $t['setting']['locale'] ?? '', 'where it is');
        echo xeric_review_input('setting.era', $t['setting']['era'] ?? '', 'when it is');
        // A LIST YOU CAN ADD TO. Every one of these arrives full from a forge
        // and empty from a blank start, and a section that renders "nothing"
        // for an empty list is a section a hand-built xeric can never fill in.
        // The button writes the next index; xeric_review_growable() is what
        // lets an index that does not exist yet be saved.
        $grow = function (string $list, int $have, string $what): string {
            return '<button type="button" class="linkbtn addfield" data-list="' . h($list) . '"'
                 . ' data-n="' . $have . '" data-kind="' . ($list === 'setting.canon_rules' ? 'text' : 'line') . '">'
                 . '+ add ' . h($what) . '</button>';
        };

        echo '<p class="flab">what it is like to stand in</p>';
        $tex = array_values((array)($t['setting']['texture'] ?? []));
        foreach ($tex as $i => $x) echo xeric_review_input("setting.texture.$i", $x);
        echo $grow('setting.texture', count($tex), 'a detail');

        echo '<p class="flab">rules this xeric always obeys</p>';
        $can = array_values((array)($t['setting']['canon_rules'] ?? []));
        foreach ($can as $i => $x) echo xeric_review_input("setting.canon_rules.$i", $x, '', 'text');
        echo $grow('setting.canon_rules', count($can), 'a rule');

        // THEMES AND THE MOOD, EDITABLE. They used to be one read-only sentence
        // at the bottom of this section, which is fine for a world that arrived
        // with them and useless for one that did not.
        echo '<p class="flab">what this story is about</p>';
        $th = array_values((array)($t['meta']['themes'] ?? []));
        foreach ($th as $i => $x) echo xeric_review_input("meta.themes.$i", $x);
        echo $grow('meta.themes', count($th), 'a theme');

        echo '<p class="flab">the two ends this place swings between</p>';
        echo xeric_review_input('world_mood.axis.positive',
            $t['world_mood']['axis']['positive'] ?? '', 'at its wildest');
        echo xeric_review_input('world_mood.axis.negative',
            $t['world_mood']['axis']['negative'] ?? '', 'at its kindest');
        foreach (['dark' => 'at the wild end', 'light' => 'at the kind end'] as $half => $lab) {
            $mo = array_values((array)($t['world_mood']['motifs'][$half] ?? []));
            echo '<p class="flab">images ' . h($lab) . '</p>';
            foreach ($mo as $i => $x) echo xeric_review_input("world_mood.motifs.$half.$i", $x);
            echo $grow('world_mood.motifs.' . $half, count($mo), 'an image');
        }

        echo '<p class="quiet">Rating: <b>' . h((string)($t['meta']['rating'] ?? '')) . '</b>. '
            . 'The rating is yours, from the interview, the model is never allowed to raise it.</p>';
    }

    if ($sec === 'you') {
        echo xeric_review_input('user.name', $t['user']['name'] ?? '', 'your name in here');
        echo xeric_review_input('user.pronouns', $t['user']['pronouns'] ?? '', 'pronouns');
        echo xeric_review_input('user.occupation.title', $t['user']['occupation']['title'] ?? '', 'what you do');
        echo xeric_review_input('user.occupation.hours', $t['user']['occupation']['hours'] ?? '', 'your hours');
        echo xeric_review_input('user.quiet_hours', $t['user']['quiet_hours'] ?? '', 'quiet hours (HH:MM-HH:MM)');
        echo xeric_review_input('user.timezone', $t['user']['timezone'] ?? '', 'timezone');
        echo xeric_review_input('user.motivation', $t['user']['motivation'] ?? '', 'why you are here', 'text');
        echo '<p class="quiet">You work at <b>'
            . h(xeric_world_place_name($t, (string)($t['user']['occupation']['workplace_key'] ?? '')))
            . '</b>. Which room that is comes from the places below, not from this box.</p>';
    }

    if ($sec === 'systems') {
        $forge = (array)($t['forge'] ?? []);
        $why = trim((string)($forge['systems_why'] ?? ''));
        $src = (string)($forge['systems_source'] ?? '');
        if ($why !== '') {
            echo '<p class="note">“' . h($why) . '” <span class="quiet">, the model, reading your answer</span></p>';
        } elseif ($src === 'preset') {
            echo '<p class="quiet">A known motivation, so these are the tested defaults.</p>';
        } elseif ($src === 'keywords') {
            echo '<p class="note warn">The model could not be reached when this was forged, so these were '
                . 'matched on keywords. Rerolling the xeric itself will not change them, they follow your motivation.</p>';
        }
        echo '<ul class="sys">';
        foreach ((array)($forge['armed'] ?? []) as $k) {
            $k = (string)$k;
            echo '<li><span class="k">on, <code>' . h(str_replace('_', ' ', $k)) . '</code></span>'
                . (isset(XERIC_SYSTEMS[$k]) ? '<span class="v">' . h(XERIC_SYSTEMS[$k]) . '</span>' : '') . '</li>';
        }
        foreach ((array)($forge['disarmed'] ?? []) as $k) {
            $k = (string)$k;
            echo '<li class="off"><span class="k">off, <code>' . h(str_replace('_', ' ', $k)) . '</code></span>'
                . (isset(XERIC_SYSTEMS[$k]) ? '<span class="v">' . h(XERIC_SYSTEMS[$k]) . '</span>' : '') . '</li>';
        }
        echo '</ul>';
        $kinds = array_keys(xeric_sweep_kinds_for($t));
        echo '<p class="quiet">Which means the only things that can happen offscreen here are: <b>'
            . h(implode(', ', $kinds)) . '</b>. Nothing else, ever, a xeric only gets the life it armed.</p>';
    }

    if ($sec === 'places') {
        foreach ((array)($t['places'] ?? []) as $i => $p) {
            $mine = !empty($p['user_workplace']);
            echo '<div class="card' . ($mine ? ' mine' : '') . '">';
            echo '<div class="cardhead"><code>' . h((string)$p['key']) . '</code>'
                . ($mine ? '<span class="tag">yours</span>' : '') . '</div>';
            echo xeric_review_input("places.$i.name", $p['name'] ?? '', 'name');
            echo '<div class="row2">'
                . xeric_review_input("places.$i.kind", $p['kind'] ?? '', 'kind')
                . xeric_review_input("places.$i.hours.open", $p['hours']['open'] ?? '', 'opens', 'time')
                . xeric_review_input("places.$i.hours.close", $p['hours']['close'] ?? '', 'closes', 'time')
                . '</div>';
            echo xeric_review_input("places.$i.description", $p['description'] ?? '', 'what anyone could tell you about it', 'text');
            $res = (array)($p['residents'] ?? []);
            if ($res !== []) {
                echo '<p class="quiet">usually here: '
                    . h(implode(', ', array_map(fn($r) => xeric_world_name($t, (string)$r), $res))) . '</p>';
            }
            echo '</div>';
        }
    }

    if ($sec === 'cast') {
        // THE ONE-TIME PRONOUN BACKFILL, offered only where there is a hole to
        // fill: a complete cast never sees this. It lives in the section body,
        // not the header, so the repaint that follows a successful fill is also
        // what takes the button away.
        if (!empty($w['mine'])) {
            $gap = xeric_review_pronounless($t);
            if ($gap !== []) {
                $gapNames = [];
                foreach ($gap as $gh => $gi) {
                    $gapNames[] = trim((string)($t['cast']['characters'][$gi]['display_name'] ?? '')) ?: $gh;
                }
                $who = count($gapNames) <= 4 ? implode(', ', $gapNames) : count($gapNames) . ' of this cast';
                echo '<p class="note pngap">' . h('Nobody asked ' . $who . ' their pronouns when '
                        . (count($gapNames) === 1 ? 'they were' : 'this xeric was') . ' forged, so their faces are '
                        . 'read from their prose — and grey wherever the prose does not say. ')
                    . '<button type="button" class="linkbtn pronounfill"'
                    . ' title="Ask the model once, for everybody at once. Only the empty fields are written; '
                    . 'anyone it cannot place honestly stays grey, and one ↺ takes the whole thing back.">'
                    . 'fill in pronouns — one model call</button></p>';
            }
        }
        $star = (string)($t['cast']['protagonist']['handle'] ?? '');
        foreach ((array)($t['cast']['characters'] ?? []) as $i => $c) {
            $h = (string)$c['handle'];
            echo '<div class="card" id="who-' . h($h) . '">';
            echo '<div class="cardhead"><code>' . h($h) . '</code>'
                . ($h === $star ? '<span class="tag">their story</span>' : '')
                . '<span class="cardacts">'
                . '<a class="linkbtn" href="why.php?w=' . h(rawurlencode($slug)) . '&amp;h=' . h(rawurlencode($h)) . '">what they are told →</a>'
                . '<button class="linkbtn reroll" type="button" data-what="character" data-index="' . (int)$i . '">reroll just this person</button>'
                . '</span></div>';
            echo '<div class="row2">'
                . xeric_review_input("cast.characters.$i.display_name", $c['display_name'] ?? '', 'name')
                . xeric_review_input("cast.characters.$i.age", $c['age'] ?? '', 'age', 'time')
                . '</div>';
            echo xeric_review_input("cast.characters.$i.one_line", $c['one_line'] ?? '', 'what anyone would say about them', 'text');
            echo xeric_review_input("cast.characters.$i.appearance", $c['appearance'] ?? '', 'what you see first', 'text');
            echo xeric_review_input("cast.characters.$i.voice", $c['voice'] ?? '', 'how they talk', 'text');
            echo '<p class="flab">what is underneath, this is the part only they can see</p>';
            echo xeric_review_input("cast.characters.$i.psyche.sore_spot", $c['psyche']['sore_spot'] ?? '', 'gets under their skin');
            echo xeric_review_input("cast.characters.$i.psyche.jealousy", $c['psyche']['jealousy'] ?? '', 'quietly jealous of');
            echo xeric_review_input("cast.characters.$i.psyche.self_soothe", $c['psyche']['self_soothe'] ?? '', 'settles themselves by');
            echo xeric_review_input("cast.characters.$i.psyche.praise_that_lands", $c['psyche']['praise_that_lands'] ?? '', 'praise that actually lands');
            echo xeric_review_input("cast.characters.$i.drives.pull", $c['drives']['pull'] ?? '', 'what they are really after');
            echo xeric_review_input("cast.characters.$i.solace", $c['solace'] ?? '', 'where they go when it is too much');
            echo '<p class="flab">things they do without noticing</p>';
            foreach ((array)($c['tells'] ?? []) as $j => $tell) {
                echo xeric_review_input("cast.characters.$i.tells.$j", $tell);
            }
            echo '<p class="flab">their week</p>';
            foreach ((array)($c['week'] ?? []) as $j => $wk) {
                echo '<div class="wk"><span class="wkw">'
                    . h(xeric_web_days_short((array)($wk['days'] ?? [])) . ' at '
                        . xeric_world_place_name($t, (string)($wk['where'] ?? ''))) . '</span>'
                    . '<div class="row2">'
                    . xeric_review_input("cast.characters.$i.week.$j.from", $wk['from'] ?? '', 'from', 'time')
                    . xeric_review_input("cast.characters.$i.week.$j.to", $wk['to'] ?? '', 'to', 'time')
                    . xeric_review_input("cast.characters.$i.week.$j.doing", $wk['doing'] ?? '', 'doing')
                    . '</div></div>';
            }
            echo '</div>';
        }
    }

    if ($sec === 'walls') {
        $walls = (array)($t['knowledge_walls'] ?? []);
        if ($walls === []) {
            echo '<p class="note bad">This xeric has no walls at all, which means every character\'s system '
                . 'prompt contains every other character\'s private interior. Reroll this section, the privacy '
                . 'baseline is deterministic and cannot fail.</p>';
        }
        foreach ($walls as $i => $wall) {
            $src = (string)($wall['source'] ?? '');
            echo '<div class="card">';
            echo '<div class="cardhead"><code>' . h((string)($wall['key'] ?? '')) . '</code>'
                . '<span class="tag">' . h($src === 'baseline' ? 'always on' : 'proposed') . '</span></div>';
            echo xeric_review_input("knowledge_walls.$i.explain", $wall['explain'] ?? '', 'in plain English', 'text');
            echo '<p class="quiet">applies to: '
                . h(implode(', ', array_map(fn($k, $v) => "$k = $v",
                    array_keys((array)($wall['audience'] ?? [])), array_values((array)($wall['audience'] ?? [])))))
                . ' · hides: ' . h(implode(', ', (array)($wall['hidden'] ?? []))) . '</p>';
            echo xeric_review_input("knowledge_walls.$i.shown_as", $wall['shown_as'] ?? '', 'what they are told instead', 'text');
            echo '</div>';
        }
        $roles = (array)($t['cast']['special_roles'] ?? []);
        if ($roles === []) {
            echo '<p class="quiet">Nobody in this xeric is being kept in the dark about anything in particular. '
                . 'That is a real answer, not a missing one.</p>';
        }
        foreach ($roles as $i => $sr) {
            echo '<div class="card mine"><div class="cardhead">'
                . h(xeric_world_name($t, (string)($sr['character'] ?? ''))) . '<span class="tag">'
                . h((string)($sr['role'] ?? '')) . '</span></div>';
            echo xeric_review_input("cast.special_roles.$i.must_not_know", $sr['must_not_know'] ?? '', 'must never learn');
            echo '<p class="quiet">They read a smaller xeric than everybody else, and the sweeps keep them out '
                . 'of the room when this comes up. If that is not true of this person, reroll or clear it, '
                . 'a wall around the wrong person is worse than none.</p></div>';
        }
    }

    if ($sec === 'protagonist') {
        $star = (array)($t['cast']['protagonist'] ?? []);
        $centrality = (string)($t['user']['centrality'] ?? 'ensemble');
        if ($star === []) {
            echo '<p class="quiet">' . ($centrality === 'main'
                ? 'You said you are the main character, so nobody else is. The xeric happens to you.'
                : 'Nobody is carrying this xeric yet. Without somebody in the middle, a side-character '
                  . 'xeric is weather: things happen and nothing is going anywhere.') . '</p>';
        } else {
            echo '<div class="card mine"><div class="cardhead">'
                . h((string)($star['display_name'] ?? $star['handle'] ?? '')) . '<span class="tag">'
                . h((string)($star['source'] ?? '')) . '</span></div>';
            echo xeric_review_input('cast.protagonist.arc', $star['arc'] ?? '', 'what they are driving toward', 'text');
            echo xeric_review_input('cast.protagonist.pressure', $star['pressure'] ?? '', 'what is forcing it now');
            echo '<p class="quiet">Sweeps put a heavy thumb on the scale for whatever room this person is in. '
                . 'You are near it, not in it.</p></div>';
        }
    }

    if ($sec === 'seed') {
        $events = (array)($seed['events'] ?? []);
        $mems   = (array)($seed['memories'] ?? []);
        if (!empty($w['lived'])) {
            echo '<p class="note warn">This xeric has already been opened, so its past is in the database now. '
                . 'Editing these lines changes the file, not what anybody remembers.</p>';
        }
        echo '<p class="quiet">' . count($events) . ' things that already happened and ' . count($mems)
            . ' memories the cast are already carrying. This is why minute one does not feel like day one.</p>';
        foreach ($events as $i => $e) {
            echo '<div class="card"><div class="cardhead">'
                . (int)($e['days_ago'] ?? 0) . ' days ago'
                . (($e['place'] ?? null) ? ' · ' . h(xeric_world_place_name($t, (string)$e['place'])) : '')
                . '</div>';
            echo xeric_review_input("seed.events.$i.title", $e['title'] ?? '', 'what it is called');
            echo xeric_review_input("seed.events.$i.prose", $e['prose'] ?? '', 'what happened', 'text');
            $who = array_map(fn($x) => xeric_world_name($t, (string)$x), (array)($e['participants'] ?? []));
            echo '<p class="quiet">' . ($who === [] ? 'nobody in particular' : 'who: ' . h(implode(', ', $who))) . '</p>';
            echo '</div>';
        }
        $byHandle = [];
        foreach ($mems as $i => $m) $byHandle[(string)($m['handle'] ?? '')][] = [$i, $m];
        foreach ($byHandle as $handle => $rows) {
            echo '<div class="card"><div class="cardhead">' . h(xeric_world_name($t, (string)$handle))
                . ' already knows</div>';
            foreach ($rows as [$i, $m]) echo xeric_review_input("seed.memories.$i.text", $m['text'] ?? '', '', 'text');
            echo '</div>';
        }
    }

    return (string)ob_get_clean();
}

/** The whole review page body: every section, with its reroll button. */
function xeric_review_body_html(array $w, array $meta = []): string
{
    $t = $w['template'];
    $slug = (string)$w['slug'];
    $secs = [
        ['concept', 'the xeric itself', 'reroll the place', true,
         'Its name, where it is, and the three rules it always obeys. Rerolling keeps the people and the rooms.'],
        ['you', 'you, in it', '', false,
         'From your answers, not the model\'s. Everything here is yours to retype.'],
        ['systems', 'what it runs on', '', false,
         'Your motivation decided this, and it decides what can ever happen offscreen.'],
        ['places', 'the places', 'reroll every room', true,
         'Rerolling these moves the cast\'s weeks onto the new rooms in the same order.'],
        ['cast', 'the cast', 'reroll everybody', true,
         'One person at a time is almost always what you want, the button on each card does that.'],
        ['walls', 'who is kept in the dark', 'reroll the walls', true,
         'The safety-critical pass. Read every line: a wall around the wrong person is worse than no wall.'],
        ['protagonist', 'whose story this is', 'reroll the protagonist', true, ''],
        ['seed', 'what already happened', 'reroll the past', true, ''],
    ];

    ob_start();
    foreach ($secs as [$key, $title, $btn, $can, $blurb]) {
        echo '<section class="sec" id="sec-' . h($key) . '" data-sec="' . h($key) . '">';
        echo '<div class="sechead"><h2>' . h($title) . '</h2>';
        if ($can && $btn !== '') {
            echo '<button class="linkbtn reroll" type="button" data-what="' . h($key) . '">↻ ' . h($btn) . '</button>';
        }
        // ADDING A PERSON IS NOT REROLLING ONE, and it belongs beside the cast
        // rather than on a screen of its own: it costs no model call, it is the
        // whole point of starting blank, and it is the thing somebody reaches
        // for on any xeric the moment they think "there should be a barman".
        if ($key === 'cast' && !empty($w['mine']) && empty($w['launched'])) {
            echo '<button class="linkbtn addperson" type="button"'
               . ' title="Add somebody to this xeric. No model is used: you get a person to rewrite.">'
               . '+ add a person</button>';
        }
        if ($key === 'places' && !empty($w['mine']) && empty($w['launched'])) {
            echo '<button class="linkbtn addplace" type="button"'
               . ' title="Add a room to this xeric. No model is used: you get a place to rewrite.">'
               . '+ add a place</button>';
        }
        echo '</div>';
        if ($blurb !== '') echo '<p class="quiet secblurb">' . h($blurb) . '</p>';
        echo '<p class="note bad secerr" hidden></p>';
        echo '<div class="log seclog" hidden></div>';
        echo '<div class="secbody">' . xeric_review_section_html($key, $w) . '</div>';
        echo '</section>';
    }
    return (string)ob_get_clean();
}

/**
 * The review step's own CSS, on top of the wizard's.
 *
 * A dense page that has to stay one thumb wide: every box is full width, every
 * label is above its box (never beside it), and the only two-column rows are the
 * ones holding times, which are four characters long and would waste a line each.
 */
function xeric_review_css(): string
{
    return <<<'CSS'
/* the back cover, on the workbench */
.cover{margin:.9rem 0 1.1rem;padding:.2rem 0 .2rem 1rem;border-left:3px solid var(--accent);
  font-size:1.02rem;line-height:1.65;color:var(--fg)}
.cover:empty{display:none}
.cover.writing{color:var(--fg-dim);font-style:italic}
.renamebar{margin:.2rem 0 .6rem;font-size:.85rem;color:var(--fg-dim);display:flex;gap:.6rem;align-items:center}
.facts{display:flex;flex-wrap:wrap;gap:.3rem .9rem;margin:0 0 .3rem;padding:0;list-style:none;font-size:.82rem;color:var(--fg-dim)}
/* the dice between the temperatures: quieter than either, still a button */
.dicebig{min-height:2.6rem;padding:.5rem 1rem;font:inherit;font-weight:600;cursor:pointer;
  color:var(--fg);background:var(--bg-2);border:1px solid var(--line);border-radius:.6rem;
  text-decoration:none}
.dicebig:hover{border-color:var(--accent-dim)}
.dicebig:disabled{opacity:.55;cursor:default}

/* ------------------------------------------------------ the literary repass */
/* One row, two temperatures: the green single pass on the left, the red loop
   pushed to the far edge so nobody's thumb drifts from one into the other. */
.repassbar{margin:.2rem 0 1rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.repassbar .redbtn{margin-left:auto}
.repassbar .st{flex:1 1 100%;font-size:.85rem;color:var(--fg-dim)}
.repassbar .rfind{flex:1 1 100%}
.greenbtn{min-height:2.6rem;padding:.5rem 1.2rem;font:inherit;font-weight:700;cursor:pointer;
  color:#fff;background:var(--good);border:1px solid var(--good);border-radius:.6rem}
.greenbtn:hover{filter:brightness(1.08)}
.greenbtn:disabled{opacity:.55;cursor:default}
/* dark theme's good and bad are pale; white text stops reading on them */
:root[data-theme="dark"] .greenbtn{color:#14210d}
:root[data-theme="dark"] .redbtn{color:#2b0d05}
.rfind{list-style:none;margin:.7rem 0 0;padding:0;display:flex;flex-direction:column;gap:.5rem}
.rf{display:flex;gap:.55rem;align-items:baseline;flex-wrap:wrap;padding:.55rem .7rem;
  background:var(--bg-2);border:1px solid var(--line);border-radius:.6rem;font-size:.88rem}
.rfk{flex:0 0 auto;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;
  padding:.1rem .5rem;border-radius:.6rem;border:1px solid var(--line);color:var(--fg-dim)}
.rf.consistency .rfk{border-color:var(--bad);color:var(--bad)}
.rf.plot .rfk{border-color:var(--accent-dim);color:var(--accent)}
.rfs{flex:1 1 24rem;min-width:0}
.rfok{flex:0 0 auto;font-size:.72rem;font-weight:600;color:var(--accent)}
.rf.done{opacity:.85}
/* the ledger's verdicts: closed, noise, and the ones handed to a human */
.rfv{flex:0 0 auto;font-size:.72rem;font-weight:600;color:var(--fg-dim)}
.rf.done .rfv{color:var(--good)}
.rf.noise{opacity:.6}
.rf.noise .rfv{color:var(--fg-dim);font-style:italic}
.rf.hand{border-color:var(--bad)}
.rf.hand .rfv{color:var(--bad)}
/* The world's name and the one control with nothing behind it. Red only on
   approach: a permanent scarlet ✕ beside a title reads as an error state on a
   page where nothing is wrong. */
.titlerow{display:flex;align-items:center;gap:.5rem}
.titlerow h1{margin:0;flex:1 1 auto;min-width:0}
.titlerow .xdel{flex:0 0 auto;background:none;border:0;cursor:pointer;font-size:1rem;line-height:1;
  color:var(--fg-far);min-width:2.4rem;min-height:2.4rem;border-radius:.5rem}
.titlerow .xdel:hover,.titlerow .xdel:focus-visible{color:var(--bad);outline:none}

/* A contradiction whose cure is on the OTHER line of the pair. Not a failure
   and not a hand job — it is the editor pointing across. Warm, not red. */
.rf.pair{border-color:var(--accent-dim)}
.rf.pair .rfv{color:var(--accent-dim)}
/* THE BIG RED BUTTON. Red because it means it: a loop of full passes, each
   one spending real tokens, until the editor finds nothing left to fix. */
.redbtn{min-height:2.6rem;padding:.5rem 1.2rem;font:inherit;font-weight:700;cursor:pointer;
  color:#fff;background:var(--bad);border:1px solid var(--bad);border-radius:.6rem}
.redbtn:hover{filter:brightness(1.08)}
.redbtn:disabled{opacity:.55;cursor:default}
/* a field a finding points at, found and lit for a moment */
.ed.flash{animation:rfflash 1.6s ease-out 1}
@keyframes rfflash{0%,40%{border-color:var(--accent);box-shadow:0 0 0 3px var(--glow)}100%{}}
@media (prefers-reduced-motion: reduce){ .ed.flash{animation:none;border-color:var(--accent)} }

/* ------------------------------------------------------- which machine rerolls */
/* The forge's own chooser, in a panel: same cards, same lamps, same words. It
   opens under the bar it belongs to and closes on Escape. */
/* The bar above is sticky, so a panel opening directly under it can be sat
   on. scroll-margin keeps it clear when it is scrolled to. */
/* ------------------------------------------------------------------- the dice */
/* Inside the box, at the right-hand end, quiet until the field is reached for.
   It must not take the tab key: somebody typing their way down this page is
   filling it in, not rolling it. */
.fldbox{position:relative;display:block}
.fldbox .ed{padding-right:2.2rem}
.dice{position:absolute;right:.45rem;top:.45rem;width:1.6rem;height:1.6rem;padding:0;
  display:flex;align-items:center;justify-content:center;
  font-size:1.05rem;line-height:1;color:var(--fg-far);cursor:pointer;
  background:transparent;border:1px solid transparent;border-radius:.35rem;
  opacity:.45;transition:opacity .14s ease-out,color .14s ease-out,border-color .14s ease-out}
.fld:hover .dice,.fldbox:focus-within .dice{opacity:1}
.dice:hover,.dice:focus-visible{color:var(--accent);border-color:var(--line);outline:none}
.dice[disabled]{cursor:default;color:var(--fg-far);border-color:transparent}
.dice.rolling{animation:roll .7s linear infinite}
@keyframes roll{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion: reduce){ .dice.rolling{animation:none;opacity:.5} }

.rpick{scroll-margin-top:5rem;margin:.6rem 0 1.1rem;padding:.85rem;border:1px solid var(--line);border-radius:.7rem;
  background:var(--bg-2);box-shadow:var(--shadow);animation:rpickin .16s ease-out}
@keyframes rpickin{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.rpick .forgeat{margin:0 0 .5rem}
.rmore{margin:0;text-align:right;font-size:.8rem}
@media (prefers-reduced-motion: reduce){ .rpick{animation:none} }

.sec{margin:0 0 2.2rem;scroll-margin-top:4rem}
.sechead{display:flex;align-items:baseline;gap:.6rem;flex-wrap:wrap;border-bottom:1px solid var(--line);padding:0 0 .35rem;margin:0 0 .6rem}
.sechead h2{margin:0}
.sechead .linkbtn{margin-left:auto}
.secblurb{margin:0 0 .8rem}
.secerr{margin:0 0 .8rem}
.seclog{margin:0 0 .8rem;max-height:8rem}
.fld{margin:0 0 .7rem;min-width:0}
.flab{display:block;margin:.2rem 0 .25rem;font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--fg-dim)}
.ed{width:100%;background:var(--bg-2);color:var(--fg);border:1px solid var(--line);border-radius:.5rem;
  padding:.6rem .7rem;font:inherit;font-size:1.02rem;line-height:1.45}
textarea.ed{min-height:3.1rem;resize:vertical;overflow-wrap:anywhere}
.ed:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 1px var(--accent-dim)}
.ed.saved{border-color:var(--good)}
.ed.bad{border-color:var(--bad)}
.ferr{margin:.3rem 0 0;font-size:.85rem;color:var(--bad)}
/* the same line under the box, when it is information rather than a refusal:
   the live-edit sentence and the re-arm note speak in the page's quiet voice */
.ferr.kept{color:var(--fg-dim)}
.row2{display:grid;grid-template-columns:repeat(auto-fit,minmax(7.5rem,1fr));gap:0 .6rem}
.cardhead{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;font-weight:600;margin:0 0 .5rem}
.cardhead code{font:inherit;font-size:.8rem;color:var(--accent);font-weight:400}
.cardacts{margin-left:auto;display:flex;gap:.9rem;flex-wrap:wrap}
.wk{border-left:2px solid var(--line);padding:.1rem 0 .1rem .7rem;margin:0 0 .5rem}
.wkw{display:block;font-size:.8rem;color:var(--accent-dim);margin:0 0 .2rem}
.plain{list-style:none;margin:0 0 .8rem;padding:0}
.plain li{font-size:.9rem;margin:0 0 .25rem}
.launchbar{position:sticky;top:0;z-index:6;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;
  padding:.7rem 0;margin:0 0 1.2rem;background:var(--bg);border-bottom:1px solid var(--line)}
.launchbar .btn{padding:.7rem .9rem;min-height:2.7rem;font-size:.95rem}
.launchbar .st{font-size:.8rem;color:var(--fg-dim)}
.card{overflow-wrap:anywhere}
.jump{display:flex;flex-wrap:wrap;gap:.4rem .9rem;margin:0 0 1.4rem;font-size:.85rem}
.busy{opacity:.55;pointer-events:none}
CSS;
}

/**
 * Which OTHER sections a reroll of this one made out of date.
 *
 * Not cosmetic. Rerolling the cast clears the walls and the protagonist (they
 * named people who no longer exist) and re-seats every place's residents; a page
 * that went on showing the old ones would be lying about a safety-critical pass.
 * Rerolling one person takes the past that was about them with it, which is why
 * `seed` is on that line too.
 */
function xeric_review_stale(string $what): array
{
    return match ($what) {
        'draft'       => ['concept', 'you', 'systems', 'places', 'cast', 'walls', 'protagonist', 'seed'],
        'places'      => ['cast', 'seed'],
        'cast'        => ['places', 'walls', 'protagonist', 'seed'],
        'character'   => ['places', 'walls', 'protagonist', 'seed'],
        'protagonist' => ['cast'],
        'concept'     => ['systems'],
        default       => [],
    };
}
