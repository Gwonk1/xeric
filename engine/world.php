<?php
/**
 * Xeric — world templates: load, validate, resolve, and the world clock.
 *
 * Everything here is world-agnostic. It knows the SHAPE of a world-template,
 * never the contents of one; `milldale` appears nowhere below.
 *
 * Two disciplines this file exists to enforce:
 *
 *  1. VALIDATION IS A PROSE BUG-CATCHER. Every check here corresponds to a way
 *     a template can be wrong that would otherwise surface as confident garbage
 *     in a model's mouth — a character in an orbit nobody declared falls outside
 *     every wall audience (a leak), a wall aimed at a role nobody holds silently
 *     protects nobody (a leak), a boon paying into a missing economy renders a
 *     prize for a ledger that does not exist. So the failures are loud, they name
 *     the offending path, and they happen at load time rather than at 2am in a
 *     sweep.
 *
 *  2. THE CLOCK IS INJECTABLE, ALWAYS. `time()` is called in exactly one place
 *     in this file — the default argument of xeric_world_now() — and nowhere
 *     else in the engine may a function reach for the wall clock on its own.
 *     The demo advances the world artificially ("skip to evening"); a stray
 *     time() deep in a renderer would make half the world live in the present
 *     and the other half in the visitor's fast-forward.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/walls.php';

// ---------------------------------------------------------------------------
// Load + validate
// ---------------------------------------------------------------------------

/**
 * The ladder, weakest first — STANDARD BROADCAST TIERS (owner, 2026-08-02).
 *
 * Three tiers made "adult themes" carry everything from grief to gore, and the
 * step past it was a cliff. Five reads the way televisions already taught
 * everyone to read: TV-G / TV-PG / TV-14 / TV-MA, and one tier above broadcast
 * for what broadcast never airs.
 *
 * THE OLD KEYS DID NOT MOVE. sfw is still rank 0 and mature/explicit kept
 * their relative order, so every rating_min ever written stays legal and means
 * what it meant — pg and teen slotted BETWEEN, and no template migrates.
 * Minors pin to rank 0 exactly as before; the 18+ gate still stands in front
 * of everything above rank 0, because widening the ladder is not loosening it.
 */
function xeric_ratings(): array
{
    return ['sfw', 'pg', 'teen', 'mature', 'explicit'];
}

/** The name a person sees. Broadcast tiers wear their broadcast names. */
function xeric_rating_label(?string $rating): string
{
    switch (strtolower(trim((string)$rating))) {
        case 'explicit': return 'Unrated';
        case 'mature':   return 'TV-MA';
        case 'teen':     return 'TV-14';
        case 'pg':       return 'TV-PG';
        default:         return 'TV-G';
    }
}

/**
 * How a world at this tier is WRITTEN — the sentence every prose-producing
 * prompt carries, because a rating that only gates content pools changes what
 * may appear without changing how anything reads. "Vary story and style
 * accordingly" (owner): a TV-PG world does not merely omit what TV-MA shows,
 * it narrates like a different program. One source, so the forge, the chat
 * turn and the sweeps cannot drift apart about what a tier sounds like.
 */
function xeric_rating_style(?string $rating): string
{
    switch (strtolower(trim((string)$rating))) {
        case 'explicit':
            return 'This world is unrated: nothing is off the table, on the page or in the story. '
                 . 'Write it like prestige fiction, not like a transcript — explicitness serves the '
                 . 'scene, never the other way round.';
        case 'mature':
            return 'Write it TV-MA: adult lives on screen — violence with consequences, sex '
                 . 'acknowledged and sometimes shown, language as people actually use it. Grim is '
                 . 'allowed; gratuitous is lazy.';
        case 'teen':
            return 'Write it TV-14: real stakes, real menace, real attraction — a knife can be '
                 . 'shown, the wound is implied; desire is on screen, bodies are not. Strong '
                 . 'language rarely, and it lands harder for it.';
        case 'pg':
            return 'Write it TV-PG: conflict, loss and consequence all happen, but the camera cuts '
                 . 'away before blood or bedrooms. Menace over violence, longing over desire, and '
                 . 'nothing a parent would have to explain in the car.';
        default:
            return 'Write it TV-G: warm, plain and safe for anyone in the room. Trouble is the '
                 . 'engine of every story here too — but it is the trouble of casseroles, grudges, '
                 . 'weather and pride, never of blood or bodies.';
    }
}

// ---------------------------------------------------------------------------
// Age
// ---------------------------------------------------------------------------

/**
 * The age below which the engine treats a character as a child.
 *
 * One number in one place. Every gate asks xeric_is_minor() rather than
 * comparing an age itself, so there is nowhere for a second threshold to drift
 * into existence.
 */
const XERIC_ADULT_AGE = 18;

/**
 * Is this character a minor?
 *
 * DERIVED, never declared. A template carries an age; it does not carry a flag,
 * because a flag is a field a model can write and therefore a field a model can
 * clear. The integer `age` is the only input, and no argument, override or
 * author opt-in exists to answer this differently.
 *
 * Missing, null, "17", 17.5 — anything that is not an integer — is a minor.
 * That is the fail-closed direction: guessing "child" about an adult costs one
 * character who cannot be flirted with, and guessing the other way costs the
 * thing this entire layer exists to prevent. xeric_world_validate() requires an
 * integer age so that a loaded world never leans on this default.
 *
 * What being a minor gates is ONE axis: sex. It has no bearing on whether a
 * character exists, appears in a place, speaks, keeps a secret, witnesses
 * something, drives an event or has a portrait. A town with no children is not
 * a town, and in a mystery the child is usually the one who saw it.
 */
function xeric_is_minor(array $character): bool
{
    $age = $character['age'] ?? null;
    if (!is_int($age)) return true;
    return $age < XERIC_ADULT_AGE;
}

/**
 * The rating a given character may be rendered at.
 *
 * An adult renders at the world's rating. A minor renders at the weakest rating
 * there is — whatever the world asked for, whatever a node asked for, with no
 * path to raise it. Callers hold this string and gate against it, which is what
 * makes the content unreachable rather than merely discouraged: see
 * xeric_rating_allows() in walls.php, where every gate in the engine lands.
 */
function xeric_effective_rating(string $worldRating, array $character): string
{
    if (xeric_is_minor($character)) return xeric_ratings()[0];
    return $worldRating;
}

/**
 * Read, decode and validate a world template.
 *
 * @throws RuntimeException on unreadable file, bad JSON, or any validation failure.
 */
function xeric_world_load(string $path): array
{
    $t = xeric_template_load($path);           // walls.php: read + json_decode
    xeric_world_validate($t, basename($path));
    return $t;
}

/**
 * Validate an already-decoded template.
 *
 * @param string $label what to call this template in error messages — a filename
 *                      when there is one, so a stack of worlds is tellable apart.
 * @throws RuntimeException naming the offending path, e.g.
 *         "xeric: milldale.json: cast.characters[2].orbit 'firm' is not a declared orbit"
 */
function xeric_world_validate(array $t, string $label = 'template'): void
{
    $bad = function (string $path, string $problem) use ($label): void {
        throw new RuntimeException("xeric: $label: $path $problem");
    };

    // -- required top-level keys ------------------------------------------
    // setting / economies / boons / mystery / world_mood / events / proactive
    // / media are all optional: a world can be a cast in a room. These four are
    // not, because every renderer and every wall reads them.
    foreach (['meta', 'user', 'places', 'cast'] as $req) {
        if (!array_key_exists($req, $t)) $bad($req, 'is a required top-level key and is missing');
        if (!is_array($t[$req]))         $bad($req, 'must be an object');
    }
    if (trim((string)($t['meta']['name'] ?? '')) === '') $bad('meta.name', 'is required and must be a non-empty string');
    if (!xeric_world_is_list($t['places']))              $bad('places', 'must be a list');

    // -- the user's timezone: the clock is built from it ------------------
    $tz = trim((string)($t['user']['timezone'] ?? ''));
    if ($tz === '') $bad('user.timezone', 'is required, the world clock has nowhere to stand without it');
    try { new DateTimeZone($tz); }
    catch (Throwable $e) { $bad('user.timezone', "'$tz' is not a timezone PHP knows"); }

    // A DRAFT MAY BE EMPTY. A xeric that has not been launched is on the anvil:
    // somebody is building it by hand, and the state they start in is nobody and
    // nowhere. Requiring a cast there means "start blank" has to invent a person
    // to satisfy a rule about xerics that are being PLAYED.
    //
    // The rule itself does not move. review.php validates as strict before it
    // will launch anything, so a xeric with nobody in it can be edited all day
    // and can never be entered — which is the honest place for the check,
    // because that is the moment the renderers and the walls start reading.
    $draft = !empty($t['forge']['review_pending']);

    $cast = (array)$t['cast'];
    if (!isset($cast['characters']) || !xeric_world_is_list($cast['characters'])
        || ($cast['characters'] === [] && !$draft)) {
        $bad('cast.characters', $draft ? 'must be a list' : 'must be a non-empty list');
    }
    if (!isset($cast['orbits']) || !xeric_world_is_list($cast['orbits'])
        || ($cast['orbits'] === [] && !$draft)) {
        $bad('cast.orbits', 'must be a non-empty list, every character declares one');
    }

    // -- collect declarations first, then check references ----------------
    $places = [];
    foreach ($t['places'] as $i => $p) {
        $k = (string)($p['key'] ?? '');
        if ($k === '')                 $bad("places[$i].key", 'is required and must be a non-empty string');
        if (isset($places[$k]))        $bad("places[$i].key", "'$k' is declared twice");
        $places[$k] = true;
        // The furniture, when a room has any: a LIST of short strings. Absent
        // is a room nobody furnished yet (legal); a map or a string here is a
        // hand-edit gone wrong, and half-reading it would seat a scene on
        // furniture that is not a list of things.
        if (array_key_exists('interior', $p)) {
            if (!xeric_world_is_list($p['interior'])) {
                $bad("places[$i].interior", 'must be a list of short strings, the things in the room');
            } else {
                foreach ((array)$p['interior'] as $j => $item) {
                    if (trim(xeric_text($item)) === '') $bad("places[$i].interior[$j]", 'is empty');
                }
            }
        }
    }

    $orbits = [];
    foreach ($cast['orbits'] as $i => $o) {
        $k = (string)($o['key'] ?? '');
        if ($k === '')                 $bad("cast.orbits[$i].key", 'is required and must be a non-empty string');
        if (isset($orbits[$k]))        $bad("cast.orbits[$i].key", "'$k' is declared twice");
        $orbits[$k] = true;
    }

    $chars  = [];
    $minors = [];
    foreach ($cast['characters'] as $i => $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '')                 $bad("cast.characters[$i].handle", 'is required and must be a non-empty string');
        if (isset($chars[$h]))         $bad("cast.characters[$i].handle", "'$h' is declared twice");
        $chars[$h] = true;

        // The inventory lists, when a person has them: same shape rule as a
        // place's interior — lists of short strings, absent legal, garbage not.
        foreach (['wears', 'carries'] as $inv) {
            if (!array_key_exists($inv, $c)) continue;
            if (!xeric_world_is_list($c[$inv])) {
                $bad("cast.characters[$i].$inv", 'must be a list of short strings, the things themselves');
                continue;
            }
            foreach ((array)$c[$inv] as $j => $item) {
                if (trim(xeric_text($item)) === '') $bad("cast.characters[$i].$inv" . "[$j]", 'is empty');
            }
        }

        // Age is a shape rule, checked here with the handle because everything
        // downstream reads it: xeric_is_minor() takes age and nothing else, and
        // answers "minor" for anything it cannot read. A template that simply
        // forgot the field would therefore load as an all-child cast and lose
        // its adult layer without saying a word — so it does not load.
        if (!is_int($c['age'] ?? null)) {
            $bad("cast.characters[$i].age", "is required and must be an integer, '$h' has " . json_encode($c['age'] ?? null));
        }
        if (xeric_is_minor($c)) $minors[$h] = true;
    }

    $fixtures = [];
    foreach ((array)($cast['fixtures'] ?? []) as $i => $f) {
        $k = (string)($f['key'] ?? '');
        if ($k === '')                 $bad("cast.fixtures[$i].key", 'is required and must be a non-empty string');
        if (isset($fixtures[$k]))      $bad("cast.fixtures[$i].key", "'$k' is declared twice");
        $fixtures[$k] = true;
    }

    $circles = [];
    foreach ((array)($cast['circles'] ?? []) as $i => $c) {
        $k = (string)($c['key'] ?? '');
        if ($k === '')                 $bad("cast.circles[$i].key", 'is required and must be a non-empty string');
        $circles[$k] = true;
    }

    $roles = [];
    foreach ((array)($cast['special_roles'] ?? []) as $i => $sr) {
        $r = (string)($sr['role'] ?? '');
        if ($r === '')                 $bad("cast.special_roles[$i].role", 'is required and must be a non-empty string');
        $roles[$r] = true;
    }

    $wallKeys = [];
    foreach ((array)($t['knowledge_walls'] ?? []) as $i => $w) {
        $k = (string)($w['key'] ?? '');
        if ($k === '')                 $bad("knowledge_walls[$i].key", 'is required and must be a non-empty string');
        if (isset($wallKeys[$k]))      $bad("knowledge_walls[$i].key", "'$k' is declared twice");
        $wallKeys[$k] = true;
    }

    // Who asks for a wall BY NAME, so the audience check below can tell a wall
    // handed out by a special role from a wall nobody will ever be handed.
    $wallRefs = [];
    foreach ((array)($cast['special_roles'] ?? []) as $sr) {
        foreach ((array)($sr['walls'] ?? []) as $named) $wallRefs[(string)$named] = true;
    }

    $economies = [];
    foreach ((array)($t['economies'] ?? []) as $i => $e) {
        $k = (string)($e['key'] ?? '');
        if ($k === '')                 $bad("economies[$i].key", 'is required and must be a non-empty string');
        if (isset($economies[$k]))     $bad("economies[$i].key", "'$k' is declared twice");
        $economies[$k] = true;
    }

    $people = $chars + $fixtures;   // anyone a name can point at

    // -- ratings ----------------------------------------------------------
    $legal = xeric_ratings();
    $mr = (string)($t['meta']['rating'] ?? 'sfw');
    if (!in_array($mr, $legal, true)) {
        $bad('meta.rating', "'$mr' is not one of " . implode('|', $legal));
    }
    // Every rating_min in the tree, wherever an author hung one. An unknown
    // string sorts as sfw in xeric_rating_rank(), so a typo would silently
    // UNGATE a node meant to be gated — the exact opposite of what was asked for.
    foreach (xeric_world_find_ratings($t, '') as [$path, $value]) {
        if (!in_array($value, $legal, true)) {
            $bad($path, "'$value' is not one of " . implode('|', $legal));
        }
    }

    // -- places -----------------------------------------------------------
    // HOMES (2026-08-02). A home is a place like any other — `kind: "home"`,
    // residents — because places[] already carried residents and a second
    // concept would mean a second resolver. Two rules with teeth:
    //
    //   • a home with nobody in it fails: an empty home resolves nobody's
    //     off-shift hours and exists only to pad the map;
    //   • one person, one home: a character resident in two homes makes
    //     "their home" ambiguous, and xeric_world_home_of() would answer by
    //     template order — a silent tiebreak nobody chose. Shared homes are
    //     the point (a marriage, roommates, a kid at their parent's); a
    //     character split ACROSS homes is a contradiction.
    $homeOf = [];
    foreach ($t['places'] as $i => $p) {
        $isHome = (string)($p['kind'] ?? '') === 'home';
        if ($isHome && (array)($p['residents'] ?? []) === []) {
            $bad("places[$i].residents", 'a home with no residents is a room nobody can be in');
        }
        foreach ((array)($p['residents'] ?? []) as $j => $r) {
            $r = (string)$r;
            if (!isset($people[$r])) $bad("places[$i].residents[$j]", "'$r' names neither a character nor a fixture");
            if ($isHome) {
                if (isset($homeOf[$r])) {
                    $bad("places[$i].residents[$j]", "'$r' already lives at '" . $homeOf[$r] . "' — one person, one home");
                }
                $homeOf[$r] = (string)($p['key'] ?? '');
            }
        }
    }

    // -- who has entered the story ------------------------------------------
    // OUT IS A CATEGORY (owner, 2026-08-02): out of the STORY, not out at a
    // place. An out character exists in the template but is unstaged — never
    // swept, never proactive, unplaced — until something brings them in. The
    // flag must be a real boolean because everything that reads it fails
    // closed: a string "false" is truthy, and a typo would quietly bench a
    // character the forge meant to stage.
    foreach ($cast['characters'] as $i => $c) {
        if (array_key_exists('out', $c) && !is_bool($c['out'])) {
            $bad("cast.characters[$i].out", 'must be true or false — it means out of the STORY');
        }
    }

    // -- the opening scene ---------------------------------------------------
    // first_contact is the person the world opens onto. It must name a real
    // character (not a fixture: fixtures cannot talk), and it can never be OUT
    // — a world whose designated first encounter has not entered the story
    // opens onto nobody, which is the exact failure staging exists to prevent.
    $fc = trim((string)($cast['first_contact'] ?? ''));
    if ($fc !== '') {
        if (!isset($chars[$fc])) $bad('cast.first_contact', "'$fc' is not a declared character");
        foreach ($cast['characters'] as $c) {
            if ((string)($c['handle'] ?? '') === $fc && !empty($c['out'])) {
                $bad('cast.first_contact', "'$fc' is OUT of the story — the opening scene cannot star somebody who has not entered it");
            }
        }
    }

    // -- user -------------------------------------------------------------
    $wkey = $t['user']['occupation']['workplace_key'] ?? null;
    if ($wkey !== null && (string)$wkey !== '' && !isset($places[(string)$wkey])) {
        $bad('user.occupation.workplace_key', "'$wkey' is not a declared place");
    }

    // Quiet hours fail OPEN when they are malformed: the sweeps parse
    // "HH:MM-HH:MM" and read anything else as "there are none", so "11pm-7am",
    // or the en-dash a paste leaves behind, switches the world's nights back on
    // without saying so. Empty is how a world says it never sleeps.
    $quiet = trim((string)($t['user']['quiet_hours'] ?? ''));
    if ($quiet !== '') {
        $ends = explode('-', $quiet);
        if (count($ends) !== 2 || !xeric_world_is_hhmm(trim($ends[0])) || !xeric_world_is_hhmm(trim($ends[1]))) {
            $bad('user.quiet_hours', "'$quiet' is not an HH:MM-HH:MM range (leave it empty for none)");
        }
    }

    // -- circles ----------------------------------------------------------
    foreach ((array)($cast['circles'] ?? []) as $i => $c) {
        foreach ((array)($c['members_from_orbits'] ?? []) as $j => $o) {
            $o = (string)$o;
            if (!isset($orbits[$o])) $bad("cast.circles[$i].members_from_orbits[$j]", "'$o' is not a declared orbit");
        }
        foreach ((array)($c['members'] ?? []) as $j => $m) {
            $m = (string)$m;
            if (!isset($people[$m])) $bad("cast.circles[$i].members[$j]", "'$m' names neither a character nor a fixture");
        }
        $hp = (string)($c['hangout_place'] ?? '');
        if ($hp !== '' && !isset($places[$hp])) $bad("cast.circles[$i].hangout_place", "'$hp' is not a declared place");
    }

    // -- characters -------------------------------------------------------
    foreach ($cast['characters'] as $i => $c) {
        $h     = (string)($c['handle'] ?? '');
        $orbit = (string)($c['orbit'] ?? '');
        // Required, not optional: a character with no orbit matches no audience
        // selector, so every orbit-scoped wall silently misses them. Deny by
        // default means refusing to load rather than quietly leaking.
        if ($orbit === '')          $bad("cast.characters[$i].orbit", 'is required, a character outside every orbit falls outside every wall');
        if (!isset($orbits[$orbit])) $bad("cast.characters[$i].orbit", "'$orbit' is not a declared orbit");

        // A minor is out of the desire economy STRUCTURALLY, at load, rather
        // than by asking every renderer to remember. These three fields are the
        // whole of it — the romance surfaces (flirt_style renders in the bible
        // and in the character's own prompt, attraction_seeds is the desire
        // ledger) and rating gates inside their dossier, which
        // xeric_effective_rating() makes unreachable anyway: content that can
        // never render is content nobody should be writing about a child.
        // Nothing else on the character is touched. Their schedule, secrets,
        // walls, events, portrait and place in the cast are ordinary.
        if (isset($minors[$h])) {
            if (trim((string)($c['flirt_style'] ?? '')) !== '') {
                $bad("cast.characters[$i].flirt_style", "is set on '$h', who is a minor, minors are not in the desire economy");
            }
            if ((array)($c['relationships']['attraction_seeds'] ?? []) !== []) {
                $bad("cast.characters[$i].relationships.attraction_seeds", "is set on '$h', who is a minor, minors are not in the desire economy");
            }
            foreach (xeric_world_find_ratings($c, "cast.characters[$i]") as [$rp, $rv]) {
                if (xeric_rating_rank($rv) > 0) {
                    $bad($rp, "gates content above sfw on '$h', who is a minor, a minor never renders above sfw, so the node is unreachable");
                }
            }
        }

        foreach ((array)($c['week'] ?? []) as $j => $w) {
            $p = "cast.characters[$i].week[$j]";
            $where = (string)($w['where'] ?? '');
            if ($where !== '' && !isset($places[$where])) $bad("$p.where", "'$where' is not a declared place");
            foreach ((array)($w['days'] ?? []) as $k => $d) {
                if (!is_int($d) || $d < 0 || $d > 6) $bad("$p.days[$k]", 'must be an integer 0-6 (0 = Sunday)');
            }
            foreach (['from', 'to'] as $f) {
                if (!isset($w[$f])) continue;
                if (!xeric_world_is_hhmm((string)$w[$f])) $bad("$p.$f", "'{$w[$f]}' is not an HH:MM time");
            }
        }

        $rel = (array)($c['relationships'] ?? []);
        foreach (['roommates', 'friend_pairs'] as $rk) {
            foreach ((array)($rel[$rk] ?? []) as $j => $r) {
                $r = (string)$r;
                if (!isset($people[$r])) $bad("cast.characters[$i].relationships.$rk\[$j]", "'$r' names neither a character nor a fixture");
            }
        }
        foreach ((array)($rel['attraction_seeds'] ?? []) as $who => $n) {
            $who = (string)$who;
            if (!isset($people[$who])) $bad("cast.characters[$i].relationships.attraction_seeds.$who", 'names neither a character nor a fixture');
            // The seed points OUT, so the character it aims at is the one being
            // put in the economy — checking only the seed's owner would leave
            // the aimed-at half of the pair unguarded.
            if (isset($minors[$who])) {
                $bad("cast.characters[$i].relationships.attraction_seeds.$who", "aims at '$who', who is a minor, minors are not in the desire economy");
            }
        }
    }

    // -- fixtures ---------------------------------------------------------
    foreach ((array)($cast['fixtures'] ?? []) as $i => $f) {
        $pl = (string)($f['place'] ?? '');
        if ($pl !== '' && !isset($places[$pl])) $bad("cast.fixtures[$i].place", "'$pl' is not a declared place");
        $same = (string)($f['same_as'] ?? '');
        // The two-Harlans case: a fixture may be the scenery form of a speaking
        // character. A same_as pointing at nothing means the renderer dedupes
        // nothing and the person appears twice under two names.
        if ($same !== '' && !isset($chars[$same])) $bad("cast.fixtures[$i].same_as", "'$same' is not a declared character");
        $fo = (string)($f['orbit'] ?? '');
        if ($fo !== '' && !isset($orbits[$fo])) $bad("cast.fixtures[$i].orbit", "'$fo' is not a declared orbit");
        foreach ((array)($f['days'] ?? []) as $j => $d) {
            if (!is_int($d) || $d < 0 || $d > 6) $bad("cast.fixtures[$i].days[$j]", 'must be an integer 0-6 (0 = Sunday)');
        }
    }

    // -- special roles ----------------------------------------------------
    foreach ((array)($cast['special_roles'] ?? []) as $i => $sr) {
        $ch = (string)($sr['character'] ?? '');
        if ($ch === '')            $bad("cast.special_roles[$i].character", 'is required');
        if (!isset($chars[$ch]))   $bad("cast.special_roles[$i].character", "'$ch' is not a declared character");
        foreach ((array)($sr['walls'] ?? []) as $j => $wk2) {
            $wk2 = (string)$wk2;
            if (!isset($wallKeys[$wk2])) $bad("cast.special_roles[$i].walls[$j]", "'$wk2' is not a declared knowledge wall");
        }
    }

    // -- knowledge walls --------------------------------------------------
    foreach ((array)($t['knowledge_walls'] ?? []) as $i => $w) {
        $wk = (string)($w['key'] ?? '');
        if (!isset($w['audience'])) {
            // A wall handed out by name only is the explicit case and fine. A
            // wall that nobody is handed and that matches nobody is a wall in an
            // open field: it loads, it renders nothing, and the thing it was
            // written to keep back is in every reader's prompt.
            if (!isset($wallRefs[$wk])) {
                $bad("knowledge_walls[$i]", "'$wk' has no audience and no special role names it, so it protects nobody");
            }
            continue;
        }
        if (!is_array($w['audience'])) $bad("knowledge_walls[$i].audience", 'must be an object');
        if ($w['audience'] === [])     $bad("knowledge_walls[$i].audience", 'is empty and therefore matches nobody');
        foreach ($w['audience'] as $field => $want) {
            $p    = "knowledge_walls[$i].audience.$field";
            $want = (string)$want;
            switch ($field) {
                case 'role':   if (!isset($roles[$want]))  $bad($p, "'$want' is not a role declared in cast.special_roles"); break;
                case 'orbit':  if (!isset($orbits[$want])) $bad($p, "'$want' is not a declared orbit"); break;
                case 'circle': if (!isset($circles[$want])) $bad($p, "'$want' is not a declared circle"); break;
                case 'handle': if (!isset($people[$want])) $bad($p, "'$want' names neither a character nor a fixture"); break;
                default:
                    // xeric_audience_match() returns false for unknown selectors,
                    // so this wall would protect nobody, quietly, forever.
                    $bad($p, 'is not a selector the engine understands (role|orbit|circle|handle)');
            }
        }
    }

    // -- economies + their board audiences --------------------------------
    foreach ((array)($t['economies'] ?? []) as $i => $e) {
        foreach ((array)($e['board']['visible_to'] ?? []) as $j => $sel) {
            $p = "economies[$i].board.visible_to[$j]";
            xeric_world_check_selector((string)$sel, $p, $roles, $orbits, $circles, $people, $bad);
        }
    }

    // -- boons ------------------------------------------------------------
    foreach ((array)($t['boons'] ?? []) as $i => $b) {
        if ((string)($b['key'] ?? '') === '') $bad("boons[$i].key", 'is required and must be a non-empty string');
        $eco = (string)($b['payout']['economy'] ?? '');
        if ($eco !== '' && !isset($economies[$eco])) $bad("boons[$i].payout.economy", "'$eco' is not a declared economy");
    }

    // -- mystery ----------------------------------------------------------
    $m = (array)($t['mystery'] ?? []);
    if (!empty($m['enabled'])) {
        $pk = (string)($m['place_key'] ?? '');
        if ($pk !== '' && !isset($places[$pk])) $bad('mystery.place_key', "'$pk' is not a declared place");
    }
}

/** Selector strings ("orbit:x", "cast_minus:a,b", bare handle) used by boards. */
function xeric_world_check_selector(string $sel, string $path, array $roles, array $orbits, array $circles, array $people, callable $bad): void
{
    $sel = trim($sel);
    if ($sel === 'all' || $sel === '*') return;

    $kind = $sel;
    $arg  = '';
    if (str_contains($sel, ':')) [$kind, $arg] = explode(':', $sel, 2);

    switch ($kind) {
        case 'orbit':  if (!isset($orbits[$arg]))  $bad($path, "'$arg' is not a declared orbit"); return;
        case 'role':   if (!isset($roles[$arg]))   $bad($path, "'$arg' is not a role declared in cast.special_roles"); return;
        case 'circle': if (!isset($circles[$arg])) $bad($path, "'$arg' is not a declared circle"); return;
        case 'handle': if (!isset($people[$arg]))  $bad($path, "'$arg' names neither a character nor a fixture"); return;
        case 'cast_minus':
            foreach (explode(',', $arg) as $ex) {
                $ex = trim($ex);
                if ($ex === '') continue;
                if (!isset($roles[$ex]) && !isset($orbits[$ex]) && !isset($people[$ex])) {
                    $bad($path, "exempts '$ex', which is not a role, an orbit, or anybody");
                }
            }
            return;
        default:
            if (!isset($people[$sel])) $bad($path, "'$sel' names neither a character nor a fixture");
    }
}

/** Every rating_min in the tree, as [dotted.path, value] pairs. */
function xeric_world_find_ratings($node, string $path): array
{
    $out = [];
    if (!is_array($node)) return $out;
    foreach ($node as $k => $v) {
        $child = $path === '' ? (string)$k : $path . (is_int($k) ? "[$k]" : ".$k");
        if ($k === 'rating_min') { $out[] = [$child, (string)$v]; continue; }
        if (is_array($v)) $out = array_merge($out, xeric_world_find_ratings($v, $child));
    }
    return $out;
}

function xeric_world_is_list($v): bool
{
    return is_array($v) && ($v === [] || array_is_list($v));
}

function xeric_world_is_hhmm(string $s): bool
{
    return (bool)preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $s);
}

/**
 * effective_rating = min(template ceiling, what the loaded model will do).
 * A wholesome template stays wholesome on any model; an explicit template
 * degrades to the model's pools rather than asking for what it cannot give.
 */
function xeric_world_rating(array $t, ?string $modelRating = null): string
{
    $template = (string)($t['meta']['rating'] ?? 'sfw');
    if ($modelRating === null) return $template;
    return xeric_rating_rank($modelRating) < xeric_rating_rank($template) ? $modelRating : $template;
}

/**
 * Pin a template's meta.rating to what the session actually affirmed.
 *
 * DOWN ONLY. An unaffirmed session reads every world at the weakest rating no
 * matter what its meta.rating says; an affirmed one reads what the template
 * already declared. Affirming is not a way to raise a world's ceiling — there
 * is no argument to this function that raises anything.
 *
 * Clamp once, at the boundary where a template enters a session, and everything
 * downstream inherits it: xeric_world_rating() floors against it, every
 * rating_min in the tree gates against it, and a world acquired at explicit
 * cannot so much as render its gated nodes for a session that never said yes.
 *
 * The affirmation itself is read by the web layer and passed in. Nothing here
 * touches session state: a rating that depended on ambient state would be a
 * rating nobody could test.
 */
function xeric_world_clamp_rating(array $t, bool $adultAffirmed): array
{
    $meta = (array)($t['meta'] ?? []);
    $meta['rating'] = xeric_rating_clamp((string)($meta['rating'] ?? xeric_ratings()[0]), $adultAffirmed);
    $t['meta'] = $meta;
    return $t;
}

// ---------------------------------------------------------------------------
// Resolvers
// ---------------------------------------------------------------------------

function xeric_world_character(array $t, string $handle): ?array
{
    foreach ($t['cast']['characters'] ?? [] as $c) {
        if ((string)($c['handle'] ?? '') === $handle) return $c;
    }
    return null;
}

function xeric_world_place(array $t, string $key): ?array
{
    foreach ($t['places'] ?? [] as $p) {
        if ((string)($p['key'] ?? '') === $key) return $p;
    }
    return null;
}

function xeric_world_fixture(array $t, string $key): ?array
{
    foreach ($t['cast']['fixtures'] ?? [] as $f) {
        if ((string)($f['key'] ?? '') === $key) return $f;
    }
    return null;
}

/** The cast in template order, optionally narrowed to one orbit. */
function xeric_world_cast(array $t, ?string $orbit = null): array
{
    $out = [];
    foreach ($t['cast']['characters'] ?? [] as $c) {
        if ($orbit !== null && (string)($c['orbit'] ?? '') !== $orbit) continue;
        $out[] = $c;
    }
    return $out;
}

/** Display name for a character, a fixture, or a handle that is neither. */
function xeric_world_name(array $t, string $handle): string
{
    $c = xeric_world_character($t, $handle);
    if ($c) return (string)($c['display_name'] ?? $handle);
    $f = xeric_world_fixture($t, $handle);
    if ($f) {
        $same = (string)($f['same_as'] ?? '');
        if ($same !== '') return xeric_world_name($t, $same);
        return (string)($f['name'] ?? $handle);
    }
    return $handle;
}

function xeric_world_place_name(array $t, ?string $key): string
{
    if ($key === null || $key === '') return '';
    $p = xeric_world_place($t, $key);
    return $p ? (string)($p['name'] ?? $key) : $key;
}

// ---------------------------------------------------------------------------
// The world clock
// ---------------------------------------------------------------------------

/**
 * The world clock, in the template's timezone.
 *
 * @param int|null $epoch inject one. The default is the ONLY read of the wall
 *                        clock in the engine — the demo's time control passes a
 *                        shifted epoch and everything downstream agrees with it,
 *                        which only works because nothing downstream calls time().
 * @return array{epoch:int,iso:string,dow:int,hhmm:string,phase:string,tz:string}
 *         dow is 0 = Sunday (PHP 'w'), matching week[].days everywhere else.
 */
function xeric_world_now(array $t, ?int $epoch = null): array
{
    $epoch = $epoch ?? time();
    $tzName = (string)($t['user']['timezone'] ?? 'UTC');
    try { $tz = new DateTimeZone($tzName); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); $tzName = 'UTC'; }

    $dt = (new DateTimeImmutable('@' . $epoch))->setTimezone($tz);
    $hh = (int)$dt->format('G');
    $mm = (int)$dt->format('i');

    return [
        'epoch' => $epoch,
        'iso'   => $dt->format('c'),
        'dow'   => (int)$dt->format('w'),
        'hhmm'  => $dt->format('H:i'),
        'phase' => xeric_world_phase($hh * 60 + $mm),
        'tz'    => $tzName,
    ];
}

/**
 * Minutes past local midnight → phase.
 *
 * Boundaries are engine canon, not per-template: sweeps, ping windows and prose
 * all key off these four words, so a template that moved them would move the
 * meaning of every phase-gated rule at once. Quiet hours are the per-world knob.
 */
function xeric_world_phase(int $minutes): string
{
    if ($minutes < 5 * 60)  return 'night';
    if ($minutes < 12 * 60) return 'morning';
    if ($minutes < 17 * 60) return 'afternoon';
    if ($minutes < 22 * 60) return 'evening';
    return 'night';
}

function xeric_world_day_name(int $dow): string
{
    $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $names[$dow] ?? '';
}

/** "HH:MM" → minutes past midnight, or null when it isn't a time. */
function xeric_world_minutes(?string $hhmm): ?int
{
    if ($hhmm === null || !xeric_world_is_hhmm($hhmm)) return null;
    [$h, $m] = explode(':', $hhmm);
    return ((int)$h) * 60 + (int)$m;
}

/**
 * Where everybody is, right now.
 *
 * The spine of presence (who is in the room) and of event generation (who could
 * plausibly have been at the thing that happened). Characters only: fixtures are
 * scenery with a day list and no hours, and a fixture that is really a cast
 * member (same_as) would otherwise stand in two places at once.
 *
 * @param string[]|null $dead handles who are no longer in any room. Passed rather
 *                       than read, because this function is pure and a world is
 *                       a template — who has DIED is state, and it lives in the
 *                       database (death.php). This is the ONE place the dead
 *                       leave the world: presence feeds the prompt's room line,
 *                       the play screen's cast, the travel map and every sweep's
 *                       participant list, so a dead person dropped here is a
 *                       dead person dropped everywhere at once. Null is a world
 *                       that has lost nobody, which is almost all of them.
 * @return array<string,array{handle:string,where:?string,doing:?string}>
 *         keyed by handle AND carrying the handle, so callers can foreach or
 *         look up without converting. The dead are absent entirely rather than
 *         present with a null room: `where === null` already means off shift,
 *         and a caller cannot be asked to tell "at home" from "in the ground".
 */
/**
 * Where somebody lives, or null for a character the world gave no home.
 *
 * First place with `kind: "home"` naming them a resident. The validator
 * guarantees at most one, so "first" is not a tiebreak — it is the answer.
 */
function xeric_world_home_of(array $t, string $handle): ?string
{
    foreach ((array)($t['places'] ?? []) as $p) {
        if ((string)($p['kind'] ?? '') !== 'home') continue;
        foreach ((array)($p['residents'] ?? []) as $r) {
            if ((string)$r === $handle) return (string)($p['key'] ?? '') ?: null;
        }
    }
    return null;
}

function xeric_world_who_is_where(array $t, array $now, ?array $dead = null): array
{
    $dow  = (int)($now['dow'] ?? 0);
    $mins = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    $prev = ($dow + 6) % 7;
    $gone = $dead === null ? [] : array_flip(array_map('strval', $dead));

    $out = [];
    foreach ($t['cast']['characters'] ?? [] as $c) {
        $handle = (string)($c['handle'] ?? '');
        if ($handle === '' || isset($gone[$handle])) continue;
        // OUT of the story is not a placement. Excluded entirely rather than
        // returned unplaced, so nothing downstream can accidentally seat an
        // unentered character in a room — absence from this map IS the state.
        if (!empty($c['out'])) continue;

        $row = ['handle' => $handle, 'where' => null, 'doing' => null];
        foreach ((array)($c['week'] ?? []) as $w) {
            if (!xeric_world_week_covers($w, $dow, $prev, $mins)) continue;
            $where = (string)($w['where'] ?? '');
            $doing = xeric_text($w['doing'] ?? '');
            $row['where'] = $where !== '' ? $where : null;
            $row['doing'] = $doing !== '' ? $doing : null;
            break;                            // first matching block wins: template order is the tiebreak
        }
        // OFF-SHIFT IS NOT NOWHERE (owner, 2026-08-02). A week with no block
        // covering this minute used to resolve to null — a ghost town at 21:00
        // in a world of morning shifts. Anyone the world gave a home is AT it
        // when their week says nothing else, asleep or not, and a home is a
        // real, visitable placement. `at_home` rides along so a renderer can
        // say "home" instead of pretending a kitchen is a shift.
        if ($row['where'] === null) {
            $home = xeric_world_home_of($t, $handle);
            if ($home !== null) {
                $row['where']   = $home;
                $row['at_home'] = true;
            }
        }
        $out[$handle] = $row;
    }
    return $out;
}

/**
 * Does one week[] block cover this moment?
 *
 * A block with no `from`/`to` covers its whole day. A block whose `to` is at or
 * before its `from` wraps past midnight (22:00–02:00 is a real shift in a bar
 * world), so it also covers the small hours of the day AFTER each listed day.
 * Bounds are half-open [from, to): a block ending at 17:00 and one starting at
 * 17:00 hand off cleanly instead of both claiming 17:00.
 */
function xeric_world_week_covers(array $w, int $dow, int $prevDow, int $mins): bool
{
    $days = array_map('intval', (array)($w['days'] ?? []));
    $from = xeric_world_minutes(isset($w['from']) ? (string)$w['from'] : null);
    $to   = xeric_world_minutes(isset($w['to'])   ? (string)$w['to']   : null);

    $today     = in_array($dow, $days, true);
    $yesterday = in_array($prevDow, $days, true);

    if ($from === null || $to === null) return $today;      // all-day block
    if ($to > $from) return $today && $mins >= $from && $mins < $to;

    // wraps midnight
    return ($today && $mins >= $from) || ($yesterday && $mins < $to);
}

/** Handles of everyone at $placeKey right now, in cast order. */
function xeric_world_who_is_at(array $presence, string $placeKey): array
{
    $out = [];
    foreach ($presence as $row) {
        if (($row['where'] ?? null) === $placeKey) $out[] = (string)$row['handle'];
    }
    return $out;
}

/**
 * The next things that change, in order: who arrives or leaves where, and what
 * opens or closes.
 *
 * The cast panel knows where everybody is NOW; nothing knew what happens NEXT,
 * so a time control could offer "skip an hour" but never "skip to when the bar
 * opens" — and the bar's opening was sitting in the template the whole time,
 * twice over, as week[] blocks and hours bags. This reads both.
 *
 * TRANSITIONS ARE FOUND, NOT DERIVED. Presence and open-ness already have
 * exactly one reader each — xeric_world_who_is_where() above and
 * xeric_sweep_place_open() in sweeps.php — and each carries hard-won tolerance
 * (wrap-midnight shifts, half-open bounds, the home fallback, free-form hours
 * bags, "closed since 1998"). A second derivation of either would disagree
 * with the first inside a month. So the schedule is read only for WHEN it
 * could change: every from/to edge and every HH:MM an hours bag mentions is a
 * minute mark, and nothing moves between two marks — presence is a function of
 * (day, minute) that only steps at a block edge or at midnight, and a place
 * only flips at a time its own bag names or when the day's band turns over.
 * Each mark in the window is stood on, and on the minute before it, and the
 * two real readers are asked both times; a transition is any disagreement.
 * The list can therefore never contradict the cast map or the sweeps,
 * whatever tolerance either reader grows next.
 *
 * The OUT are not in it — they are absent from the story, and who_is_where
 * already leaves them off the map, so their edges are not even collected. The
 * dead are handed in the way who_is_where takes them, and for the same
 * reason: who has died is state, and this function reads no database.
 *
 * Coming off a block onto the home fallback is reported as LEAVING the block,
 * not arriving home — a home is where the week's silence puts you, and
 * "arrives home" would dress the absence of an appointment up as one.
 *
 * @param array $now  from xeric_world_now() — injected, like every clock here
 * @param int   $withinHours how far ahead to look; a day covers every
 *              schedule's whole period short of the week itself
 * @param string[]|null $dead as xeric_world_who_is_where()
 * @return array<int,array{in:int,epoch:int,kind:string,key:string,label:string}>
 *         soonest first. `in` is minutes after $now; `kind` is one of
 *         arrives|leaves|moves|opens|closes; `key` is the handle or place key;
 *         `label` is the row said out loud ("the Bluebird Diner opens").
 */
function xeric_world_next_change(array $t, array $now, int $withinHours = 24, ?array $dead = null): array
{
    // The hours-bag reader lives with the sweeps — the layer above — and is
    // pulled in at CALL time, not include time: a caller that never asks what
    // changes next never loads the sweep engine, and the include cycle
    // resolves the way seed.php already documents require_once cycles do.
    require_once __DIR__ . '/sweeps.php';

    $nowEpoch = (int)($now['epoch'] ?? 0);
    if ($nowEpoch <= 0 || $withinHours <= 0) return [];
    $until = $nowEpoch + $withinHours * 3600;

    // -- the minutes of a day the world could change at ---------------------
    $marks = [0 => true];                       // midnight: the day, and its band, turn over
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        if (!empty($c['out'])) continue;        // not in the story: none of their edges is next
        foreach ((array)($c['week'] ?? []) as $w) {
            foreach (['from', 'to'] as $f) {
                $m = xeric_world_minutes(isset($w[$f]) ? (string)$w[$f] : null);
                if ($m !== null) $marks[$m] = true;
            }
        }
    }
    $timed = [];                                // places that keep hours at all
    foreach ((array)($t['places'] ?? []) as $p) {
        $key   = (string)($p['key'] ?? '');
        $hours = (array)($p['hours'] ?? []);
        if ($key === '' || $hours === []) continue;      // no hours: always open, never news
        $timed[$key] = $hours;
        foreach ($hours as $v) {
            // Every HH:MM anywhere in the bag, spans included — the bag is
            // free-form and the reader's band logic decides what counts; this
            // only has to name every minute the reader COULD step at.
            if (!is_string($v)) continue;
            if (preg_match_all('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $v, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) $marks[((int)$m[1]) * 60 + (int)$m[2]] = true;
            }
        }
    }

    // -- those marks, as real moments inside the window ---------------------
    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    $day = (new DateTimeImmutable('@' . $nowEpoch))->setTimezone($tz);

    $edges = [];
    $days  = intdiv($withinHours, 24) + 2;      // calendar days that can hold the window, from any hour
    for ($d = 0; $d < $days; $d++) {
        $base = $d === 0 ? $day : $day->add(new DateInterval('P' . $d . 'D'));
        foreach (array_keys($marks) as $m) {
            $e = $base->setTime(intdiv($m, 60), $m % 60)->getTimestamp();
            if ($e > $nowEpoch && $e <= $until) $edges[$e] = true;
        }
    }
    $edges = array_keys($edges);
    sort($edges);

    // -- stand on each edge and the minute before it; ask the real readers --
    $out = [];
    foreach ($edges as $epoch) {
        $was = xeric_world_now($t, $epoch - 60);
        $is  = xeric_world_now($t, $epoch);
        $in  = intdiv($epoch - $nowEpoch, 60);

        $before = xeric_world_who_is_where($t, $was, $dead);
        $after  = xeric_world_who_is_where($t, $is, $dead);
        foreach ($after as $h => $row) {
            $a = $before[$h]['where'] ?? null;
            $b = $row['where'];
            if ($a === $b) continue;
            $h    = (string)$h;
            $name = xeric_world_name($t, $h);
            $fromBlock = $a !== null && empty($before[$h]['at_home']);
            $toBlock   = $b !== null && empty($row['at_home']);
            if ($toBlock && !$fromBlock) {
                $out[] = ['in' => $in, 'epoch' => $epoch, 'kind' => 'arrives', 'key' => $h,
                          'label' => $name . ' arrives at ' . xeric_world_place_name($t, (string)$b)];
            } elseif ($fromBlock && !$toBlock) {
                $out[] = ['in' => $in, 'epoch' => $epoch, 'kind' => 'leaves', 'key' => $h,
                          'label' => $name . ' leaves ' . xeric_world_place_name($t, (string)$a)];
            } elseif ($fromBlock && $toBlock) {
                $out[] = ['in' => $in, 'epoch' => $epoch, 'kind' => 'moves', 'key' => $h,
                          'label' => $name . ' leaves ' . xeric_world_place_name($t, (string)$a)
                                   . ' for ' . xeric_world_place_name($t, (string)$b)];
            }
            // Both sides unscheduled and different cannot happen: the home
            // fallback is constant, so there is no fourth arm to write.
        }

        $wasM = xeric_world_minutes((string)$was['hhmm']) ?? 0;
        $isM  = xeric_world_minutes((string)$is['hhmm']) ?? 0;
        foreach ($timed as $key => $hours) {
            $a = xeric_sweep_place_open($hours, $wasM, (int)$was['dow']);
            $b = xeric_sweep_place_open($hours, $isM, (int)$is['dow']);
            if ($a === $b) continue;
            $out[] = ['in' => $in, 'epoch' => $epoch, 'kind' => $b ? 'opens' : 'closes', 'key' => $key,
                      'label' => xeric_world_place_name($t, $key) . ($b ? ' opens' : ' closes')];
        }
    }
    return $out;
}
