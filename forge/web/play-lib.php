<?php
/**
 * play-lib.php — what the play view needs to know, and nothing the engine owns.
 *
 * The web app is a THIN consumer of engine/. Everything below is either (a) a
 * read of the world shaped for a screen, or (b) a rule that exists only because
 * strangers are sharing one GPU. Nothing here decides what a world
 * IS, what may happen in it, or how time works — clock.php, sweeps.php and
 * proactive.php own all of that and are called exactly the way the CLIs call
 * them (engine/sweep-cli.php is the reference sequence).
 *
 * Three things worth reading before editing:
 *
 *  • THE WORLD DB LIVES NEXT TO ITS TEMPLATE — IF IT IS YOURS. worlds/<slug>/
 *    world.db, exactly where chat-cli.php and sweep-cli.php put it, so a world
 *    forged in the browser, played in the browser and then poked at from the
 *    command line is ONE world. On the cloud that directory is the data dir,
 *    never the repo (boot.php's worlds_dir), which keeps www-data out of a
 *    checkout. A world somebody ELSE forged is opened against a copy of that
 *    database in the visitor's own session (session.php): the template and the
 *    seed are shared and immutable, and only state forks. Nobody moves a
 *    stranger's evening on.
 *
 *  • FIRST ENTRY IS A SEEDING. A freshly forged world has no db at all. Opening
 *    it creates one, runs xeric_state_seed() for the arcs and xeric_seed_apply()
 *    for the baked past, so the visitor walks into the middle of something rather
 *    than into day one. Idempotent by the engine's own guard — the note this
 *    returns is the only thing that is once-only.
 *
 *  • THE MODEL IS ONE SLOT. The play view joins the SAME line the forge joins
 *    (queue.php). A chat turn during a build waits its turn and is told where it
 *    is standing — never a spinner, and no longer a 409.
 */

declare(strict_types=1);

require_once __DIR__ . '/ui.php';                       // → boot.php → forge.php → engine/world.php + llm.php
require_once XERIC_WEB_LIB . '/engine/state.php';
require_once XERIC_WEB_LIB . '/engine/clock.php';
require_once XERIC_WEB_LIB . '/engine/seed.php';
require_once XERIC_WEB_LIB . '/engine/chat.php';
require_once XERIC_WEB_LIB . '/engine/sweeps.php';
require_once XERIC_WEB_LIB . '/engine/proactive.php';
require_once XERIC_WEB_LIB . '/engine/learn.php';
require_once XERIC_WEB_LIB . '/engine/story.php';       // the overlays beside a world, if it has any
require_once XERIC_WEB_LIB . '/engine/photo.php';       // the pictures a world owes itself
require_once XERIC_WEB_LIB . '/engine/qr.php';          // this xeric, on the phone in your pocket
require_once XERIC_WEB_LIB . '/engine/mood.php';        // the town's own needle, which its hours move
require_once XERIC_WEB_LIB . '/engine/work.php';        // the shift, and how much money is allowed to matter
require_once XERIC_WEB_LIB . '/engine/table.php';       // and the games a place holds

/**
 * How many events one press of the time control may produce.
 *
 * Not a cadence: the cadence knob is `chance`, and the time control passes 1.0
 * on purpose (sweeps.php blesses an explicit opt from "the demo's time control").
 * A visitor who pressed *skip to evening* asked to SEE the evening, not to roll
 * for it. This is the ceiling that keeps a six-hour skip from being eleven model
 * calls and four minutes of staring.
 */
const XERIC_PLAY_MAX_EVENTS = 3;

/** Hours one press may walk. A day's skip is 24 windows; the clock caps at 7d. */
const XERIC_PLAY_MAX_WINDOWS = 26;

/** Seconds a single chat turn may take before it is called a failure. */
const XERIC_PLAY_CHAT_TIMEOUT = 70;

/** A turn slower than this does not also get a memory harvest — see say.php. */
const XERIC_PLAY_EXTRACT_UNDER = 25.0;

// ---------------------------------------------------------------------------
// Worlds on disk
// ---------------------------------------------------------------------------

/**
 * Every world that has been forged here, newest first.
 *
 * Reads the template rather than trusting the directory name: a world whose JSON
 * will not load is listed as broken instead of being silently missing, because
 * "my world vanished" is a worse bug report than "my world will not open".
 */
function xeric_play_worlds(?string $sid = null): array
{
    $sid = $sid ?? xeric_session_id();
    $copies = array_flip(xeric_session_copies($sid));
    $out = [];
    foreach (glob(xeric_web_worlds_dir() . '/*/world-template.json') ?: [] as $path) {
        $slug = basename(dirname($path));
        $raw  = json_decode((string)@file_get_contents($path), true);
        $when = is_array($raw) ? (string)($raw['forge']['built_at'] ?? '') : '';
        $out[] = [
            'slug'    => $slug,
            'name'    => is_array($raw) ? (string)($raw['meta']['name'] ?? $slug) : $slug,
            'blurb'   => is_array($raw) ? (string)($raw['meta']['description'] ?? '') : '',
            'ok'      => is_array($raw),
            'cast'    => is_array($raw) ? count((array)($raw['cast']['characters'] ?? [])) : 0,
            'places'  => is_array($raw) ? count((array)($raw['places'] ?? [])) : 0,
            'rating'  => is_array($raw) ? (string)($raw['meta']['rating'] ?? '') : '',
            'built'   => $when !== '' ? (int)strtotime($when) : (int)@filemtime($path),
            'lived'   => is_file(dirname($path) . '/world.db'),
            // Everything on this shelf is playable by everybody; what differs is
            // whose state you are about to write to (session.php).
            'mine'    => xeric_session_owns($slug, $sid),
            'copy'    => isset($copies[$slug]),
            // A world nobody has launched yet is on somebody's anvil. It is on
            // THEIR shelf, marked, and on nobody else's — a stranger walking into
            // a half-reviewed world would be seeing work in progress, and the
            // person doing the work would find their evening moved on.
            'launched' => !is_array($raw) || empty($raw['forge']['review_pending']),
            // TOLD BEFORE THE DOOR, not after somebody is gone. Read from the
            // template rather than from the world's database, because the shelf
            // reads templates and because a visitor has not forked one of these
            // yet — the copy they are about to be given inherits this and freezes
            // it at their first death, and by then it is too late to be warned.
            'permanent' => is_array($raw) && xeric_death_mode_of($raw) === XERIC_DEATH_PERMANENT,
            // What its cover art is a picture OF, worked out here because this is
            // the one place the whole template is already open.
            'scene'   => is_array($raw) ? xeric_play_scene($raw) : xeric_play_scene([]),
            // Whether it is moving. Read from the file rather than by opening
            // every world's database: the shelf renders eight of these and
            // eight WAL connections to answer one boolean each is a shelf that
            // takes a second to draw.
            'paused'  => xeric_play_paused_quick($slug, $sid),
        ];
    }
    $out = array_values(array_filter($out, fn($x) => $x['launched'] || $x['mine']));
    usort($out, fn($a, $b) => $b['built'] <=> $a['built']);
    return $out;
}

/**
 * Open a world for play: template, database, and its past written in.
 *
 * The sequence is sweep-cli.php's, in its order, for the reason that file gives:
 * seed the arcs from the template, then apply seed.json measured back from the
 * WORLD's launch moment (which is the clock's, not the server's).
 *
 * WHICH DATABASE. Left to itself this asks session.php, which hands back the
 * canonical db for a world this visitor forged and a private copy for anybody
 * else's — forking it on the first open. $dbPath overrides that for the one
 * caller that cannot ask: a detached worker, which has no cookie and is given
 * the answer by the request that started it.
 *
 * AND THE OVERLAYS, IN ONE PLACE. Every page in this app that moves a world —
 * the turn, the skip, the repaint — goes through here, so this is where a story
 * is picked up and where it is composed in. Both halves travel: `template` is
 * the COMPOSED one (walls, held pieces, convictions) and `stories` is the raw
 * list (pace, thumb, beats, and the thing that watches a conversation). A caller
 * that passes one and not the other gets a half-live story, so neither is
 * optional and neither is assembled anywhere else.
 *
 * `template` is therefore NOT the bytes on the disk while a story is live. The
 * one page that promises those — world.php, "your world is a file you own" —
 * reads the file itself and does not come through here.
 *
 * @return array{slug:string,dir:string,template:array,db:PDO,db_path:string,
 *               mine:bool,forked:bool,fresh:bool,seeded:array,
 *               stories:array,story_notes:array}
 * @throws RuntimeException when there is no such world, or its template is bad
 */
function xeric_play_open(string $slug, ?string $dbPath = null, ?bool $adult = null): array
{
    $slug = xeric_web_slug($slug);
    if ($slug === '') throw new RuntimeException('which xeric?');

    $dir  = xeric_web_worlds_dir() . '/' . $slug;
    $path = $dir . '/world-template.json';
    if (!is_file($path)) throw new RuntimeException("no xeric called '$slug' has been forged here");

    $t = xeric_world_load($path);

    // THE PLAY BOUNDARY. The affirmation guards the forge at its door; without
    // this it guards nothing past it, because a world can be opened that this
    // visitor did not forge. Everything downstream already inherits meta.rating
    // — xeric_world_rating(), every rating_min in the tree, the prompt, the
    // sweeps — so pinning it once, here, is the whole control rather than a
    // check each of them would have to remember.
    //
    // A detached worker has no cookie, and asking the session on its behalf
    // would answer "unaffirmed" and quietly downgrade its owner's world. It is
    // told, the same way and by the same request that tells it which database
    // to write. Null still means ask, so every ordinary page is unchanged.
    $t = xeric_world_clamp_rating($t, $adult ?? xeric_session_adult());

    if ($dbPath === null) {
        $pick = xeric_session_db($slug);
    } else {
        // A path handed over by our own request. Still checked against the two
        // directories a world db may ever live in: a worker is told where to
        // write by an HTTP request, and that is not a sentence to end there.
        $pick = ['path' => $dbPath, 'mine' => $dbPath === $dir . '/world.db', 'forked' => false];
        // NORMALISED BEFORE COMPARED. realpath() hands back C:\\Users\\x\\.xeric\\worlds
        // on Windows, and appending a forward slash to that compares a path with
        // backslashes against a root with one slash at the end: never a prefix,
        // so this refused every xeric on the platform. The heartbeat and the time
        // skip both come through here, so it was not a message, it was silence.
        $norm = static function (string $p): string {
            $p = rtrim(str_replace('\\', '/', $p), '/');
            // NTFS is case-insensitive and realpath can hand back a different
            // casing than the configured root, which would fail the same way.
            return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($p) : $p;
        };

        $real = realpath(dirname($dbPath));
        $ok = false;
        foreach ([(string)xeric_web_config()['data_dir'], xeric_web_worlds_dir()] as $rootRaw) {
            $root = realpath($rootRaw);
            if ($root !== false && $real !== false
                && str_starts_with($norm($real) . '/', $norm($root) . '/')) $ok = true;
        }
        if (!$ok) throw new RuntimeException('that is not a xeric database this demo owns');
    }

    $db = xeric_state_open((string)$pick['path']);

    // RECONCILE THE CLOCK WITH WHETHER THERE IS A MACHINE, and do it here because
    // this is the one door every world goes through.
    //
    // Detaching pauses every world the visitor HAD. It cannot pause one they had
    // not opened yet — and opening a world is what forks it, so a world first
    // entered while detached would otherwise be created running, tick along on
    // wall-clock time with nothing behind it, and be the only world on the shelf
    // telling the truth about a fortnight it did not live.
    //
    // ONE DIRECTION ONLY. Detached and running gets stopped; attached and stopped
    // is left alone. Auto-resuming would make this function undo any pause it did
    // not set — a per-world stop, a worker's, a repair — and starting a world
    // again is a decision somebody makes on the model page, not a side effect of
    // looking at it.
    try {
        if (!xeric_web_connected(xeric_web_model()) && !xeric_clock_is_paused($db)) {
            xeric_clock_pause($db);
        }
    } catch (Throwable $e) {
        // A worker with no session, or a session store that will not read. The
        // world opens either way; the clock is not worth refusing a world over.
    }

    // WHEN IT BEGINS, before anything reads the clock — the seed below is
    // measured backwards from the world's launch moment, and a world that got
    // its 1973 offset after being seeded would have six weeks of history dated
    // 2026 behind a present that is fifty years earlier.
    xeric_clock_begin($db, $t);

    $fresh = !xeric_seed_applied($db);
    $now   = xeric_clock_now($db, $t);
    xeric_state_seed($db, $t, xeric_state_time());
    $seeded = xeric_seed_apply($db, $t, xeric_seed_load($dir), $now['epoch']);

    // The pictures this world owes itself, enqueued on every open — idempotent
    // (one row per subject, however many opens), which is also the whole
    // backfill story for worlds forged before photos existed: opening them IS
    // enqueuing them. Rows only; nothing renders until an image machine
    // answers AND the owner said yes (xeric_photo_reap's gates).
    try { xeric_photo_backfill($t, $db); } catch (Throwable $e) { /* photos owe the world nothing */ }

    // xeric_story_for() rather than xeric_story_load(): an overlay rated above
    // what this session may be shown is DROPPED with a note, not refused. A world
    // that would not open because a story nobody was going to see is rated too
    // high is a session gate that breaks worlds.
    $notes   = [];
    $stories = xeric_story_for($dir, $t, function (string $n) use (&$notes): void { $notes[] = $n; });
    $t       = xeric_story_compose($t, $stories, $db);

    return ['slug' => $slug, 'dir' => $dir, 'template' => $t, 'db' => $db,
            'db_path' => (string)$pick['path'], 'mine' => (bool)$pick['mine'],
            'forked' => (bool)$pick['forked'], 'fresh' => $fresh, 'seeded' => $seeded,
            'stories' => $stories, 'story_notes' => $notes];
}

// ---------------------------------------------------------------------------
// Whose world this is
// ---------------------------------------------------------------------------
//
// Everything on this shelf is PLAYABLE by everybody and almost none of it is
// READABLE by everybody. A world is worth walking into because you do not yet
// know what the people in it want; the pages that print what they want are for
// whoever is tuning the thing, and a slug typed off the shelf is not tuning.
//
// Four pages show a world's insides — the review step, the inspector, the result
// screen and the file itself — and each of them used to decide for itself who
// was allowed to look. They decided differently, and the one that never asked at
// all (the inspector) went unnoticed because there was no one place to notice it
// in. This is that place, and the inspector, the result screen and the file all
// come through it. review.php refuses inside its own chrome instead — it is a
// full page with the stylesheet already loaded, so it can afford to — but the
// question it asks is this one.

/**
 * The interiors a stranger never reads, whichever page is doing the showing.
 *
 * Exactly what xeric_wall_interiors() registers as walled and what the bible
 * renders behind cast_dossiers: `drives` is the unspoken pull, `psyche` the sore
 * spot and the self-soothe habit, `secrets` says what it is, `tells` are the
 * things somebody does without noticing, `solace` is where they go when it is
 * too much. The privacy baseline keeps every one of them from the CAST — so a
 * passer-by holding a slug may not have them either. A world that showed a
 * stranger an interior nobody living there may see is not redacted, it is leaky.
 *
 * DERIVED, NOT DECLARED. This used to be a hand list, and it drifted from the
 * engine's own inventory of interiors twice: `tells` and `solace` were missing
 * once and fixed by editing the constant, then `voice` — which walls.php has
 * always filed under the dossier — leaked to every stranger who pressed "show
 * me the file". The list of what a wall can take away is the wall system's to
 * keep (xeric_wall_interior_fields), and this reads it, so the next field the
 * indexer learns is redacted the same day.
 */
function xeric_play_interior_fields(): array
{
    return (array)(xeric_wall_interior_fields()['characters'] ?? []);
}

/**
 * A refusal shaped like whoever asked, and then nothing else.
 *
 * These URLs are linked from the play view, the review step and every result
 * screen, so the thing that most often hits one it cannot have is a PERSON with
 * a stale bookmark or a shared link — and answering a person with `{"error":…}`
 * on a white page is the raw-JSON-on-screen failure this app is not allowed to
 * have. A client that asked for an object still gets one.
 *
 * @param array<string,string> $links  href => label, in the order they read
 */
function xeric_play_no(string $why, int $status = 403, string $head = 'There is nothing to show you',
                       string $more = '', array $links = []): void
{
    if (!xeric_web_wants_html()) xeric_web_json(['error' => $why], $status);

    if ($more === '') {
        $more = 'Xerics forged here are kept for seven days after their maker\'s last visit and then let go, '
            . 'with everything in them. A bookmark older than that will land exactly here.';
    }
    if ($links === []) $links = ['play.php' => 'The xerics that are here →'];

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>Xeric: ' . h($head) . '</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, '
        . 'interactive-widget=resizes-content">'
        // HARDCODED, AND FOLLOWING THE SYSTEM RATHER THAN THE SWITCH. This page
        // is a refusal: it must render when nothing else can, so it carries its
        // own colours and depends on no stylesheet and no script. That means it
        // cannot read the wordmark's choice — prefers-color-scheme is the
        // closest honest thing, and a page somebody sees once is not the place
        // to spend a dependency.
        . '<style>:root{color-scheme:light dark}'
        . 'body{margin:0;background:#f7f6f2;color:#1a1813;font-family:ui-sans-serif,system-ui,sans-serif;'
        . 'line-height:1.55}main{max-width:34rem;margin:0 auto;padding:3rem 1.15rem}'
        . 'h1{font-size:1.6rem;font-weight:600;margin:0 0 .6rem}a{color:#9a5f19}'
        . 'p.n{border-left:2px solid #a53f22;padding:.15rem 0 .15rem .8rem}'
        . '@media(prefers-color-scheme:dark){body{background:#12141a;color:#eceef3}'
        . 'a{color:#e0a45c}p.n{border-left-color:#e0715a}}</style>'
        . '<main><h1>' . h($head) . '</h1><p class="n">' . h($why) . '</p>'
        . '<p>' . h($more) . '</p><p>'
        . implode('<br>', array_map(fn($href, $label) => '<a href="' . h($href) . '">' . h($label) . '</a>',
                                    array_keys($links), array_values($links)))
        . '</p></main>';
    exit;
}

/**
 * The gate in front of every page that prints a world's insides. Never returns
 * to a visitor who is not the owner.
 *
 * Fails closed on all three counts, in the order a person would ask them: is
 * there a world named, is it still here, is it theirs. A world with no owner
 * file is nobody's and therefore not this visitor's either (xeric_session_owns)
 * — the shelf is not a wall, and a reader nobody in the world knows is nobody
 * in it.
 *
 * $because is the calling page's own sentence about what it would have printed.
 * The rest of the refusal is shared, because "somebody else forged this" reads
 * the same whichever door it is said at.
 *
 * Called BEFORE the world is opened, on purpose: xeric_play_open() forks a copy
 * of a stranger's database into this session, and a page that is about to refuse
 * has no business leaving anything behind.
 */
function xeric_play_guard(string $slug, string $because, ?string $sid = null): void
{
    $slug = xeric_web_slug($slug);
    if ($slug === '') {
        xeric_play_no('No xeric was named, this URL needs ?w=<the xeric\'s folder name>.', 400,
                      'There is no xeric to show you');
    }
    if (!is_file(xeric_web_worlds_dir() . '/' . $slug . '/world-template.json')) {
        xeric_play_no("No xeric called '$slug' has been forged here.", 404, 'There is no xeric to show you');
    }
    // OWNING AND PLAYING ARE TWO DIFFERENT THINGS. Somebody who came through a
    // pairing code is in this world for real — the owner's own evening, not a
    // forked copy — so they pass the door. What they may DO once inside is a
    // separate question, answered by $w['mine'] at every action that changes
    // the machine or the world rather than the story.
    $sid = $sid ?? xeric_session_id();
    if (xeric_session_player($slug, $sid) !== null) return;

    xeric_play_no($because, 403, 'That xeric is not yours to read',
        'What everybody in it privately wants, and what one of them is not allowed to know, are the whole of '
        . 'what makes it worth walking into, and they only work while they are still a surprise. You can '
        . 'still play it: your copy is yours, and nothing you do in it moves their evening on.',
        ['play.php?w=' . rawurlencode($slug) => 'Play your own copy of it →',
         'forge.php' => 'Forge one that is yours →']);
}

/**
 * The second half of the sentence above: through the door is not the same as
 * holding the keys.
 *
 * A guest who came in on a pairing code plays the OWNER'S world — the canonical
 * database, not a fork — because that is the entire point of inviting somebody.
 * `xeric_play_guard()` therefore lets them in, and says in its own comment that
 * what they may DO once inside is answered by `$w['mine']` at every action that
 * changes the machine or the world rather than the story.
 *
 * SIX PLACES NEVER ASKED. A guest could stop the owner's clock, throw their
 * learning switch, grant standing photo consent and then spend it, walk their
 * character across town, take back the hours of their last skip, close a scene
 * into their database — and, worst, `fate.php act=end`, which kills the entire
 * cast in one request and, in a world whose death mode is permanent, cannot be
 * undone. engine/pair.php's own docblock promises none of this is possible.
 *
 * So it is a function rather than six remembered `if`s: the previous shape was
 * "remember to check", and six places forgot. A guest is not a hole in the room,
 * but they are a guest.
 *
 * ── AND IT IS NOT SIMPLY `!mine` ──────────────────────────────────────────
 *
 * `mine` is false for two completely different people. A STRANGER reading
 * somebody else's shelf gets a FORK (xeric_session_db), and in their own copy
 * they may stop the clock, walk the map, end the world — nothing they do
 * reaches the owner's evening, and refusing them would break the demo's whole
 * argument. A GUEST is the opposite: false, and on the canonical database,
 * because being in the same evening is the point of the invitation.
 *
 * The thing that tells them apart is which file is open, so that is what this
 * reads. Off the world's own `world.db` and not the owner: a guest.
 */
function xeric_play_is_guest(array $w): bool
{
    if (!empty($w['mine'])) return false;                      // the owner
    $canon = rtrim((string)($w['dir'] ?? ''), '/') . '/world.db';
    return (string)($w['db_path'] ?? '') === $canon;           // else: their own copy
}

/** The same question, as a door. Answers and stops. */
function xeric_play_owner_only(array $w, string $what): void
{
    if (!xeric_play_is_guest($w)) return;
    xeric_web_json([
        'error' => 'You are a guest in this xeric — ' . $what . ' is the owner\'s to do.',
        'kind'  => 'guest',
    ], 403);
}

/**
 * Somebody else's world with the interiors taken out — the shape of it, which is
 * the demo's best argument, and none of what the walls exist to hold.
 *
 * Beyond XERIC_PLAY_INTERIORS: what a protected character must never learn, and
 * the list of keys every wall takes away. `explain` goes with the walls because
 * the forge writes it as a sentence that RESTATES the must_not_know word for
 * word — taking the field away and leaving its own explanation behind is not
 * redaction, it is a longer route to the same paragraph. `shown_as` stays: it is
 * the cover story, which is exactly the thing a walled viewer is supposed to be
 * told. So does the protagonist's arc go, because the arc is a public sentence
 * only for somebody the wall leaves it standing for.
 *
 * @return array{0:array,1:string[]}  the template, and the field paths removed
 */
function xeric_play_redact(array $T): array
{
    $gone = [];
    foreach (array_keys((array)($T['knowledge_walls'] ?? [])) as $i) {
        foreach (['hidden', 'explain'] as $f) {
            if (!isset($T['knowledge_walls'][$i][$f])) continue;
            unset($T['knowledge_walls'][$i][$f]);
            $gone["knowledge_walls[].$f"] = true;
        }
    }
    foreach (array_keys((array)($T['cast']['special_roles'] ?? [])) as $i) {
        if (!isset($T['cast']['special_roles'][$i]['must_not_know'])) continue;
        unset($T['cast']['special_roles'][$i]['must_not_know']);
        $gone['special_roles[].must_not_know'] = true;
    }
    $vocab = xeric_wall_interior_fields();
    foreach (array_keys((array)($T['cast']['characters'] ?? [])) as $i) {
        foreach ((array)($vocab['characters'] ?? []) as $f) {
            if (!isset($T['cast']['characters'][$i][$f])) continue;
            unset($T['cast']['characters'][$i][$f]);
            $gone["characters[].$f"] = true;
        }
    }
    if (isset($T['cast']['protagonist'])) {
        foreach ((array)($vocab['protagonist'] ?? []) as $f) {
            if (!isset($T['cast']['protagonist'][$f])) continue;
            unset($T['cast']['protagonist'][$f]);
            $gone['protagonist.' . $f] = true;
        }
    }
    // A walled ledger's private half, and the mystery's rumor: the same
    // material a family_innocence or no_rumor wall exists to hide is not the
    // demo's to hand a stranger either. Keys, labels and counters stay — the
    // SHAPE of a world is the shelf's best argument; its secrets are not.
    foreach (array_keys((array)($T['economies'] ?? [])) as $i) {
        foreach ((array)($vocab['economies'] ?? []) as $f) {
            if (!isset($T['economies'][$i][$f])) continue;
            unset($T['economies'][$i][$f]);
            $gone["economies[].$f"] = true;
        }
    }
    foreach ((array)($vocab['mystery'] ?? []) as $f) {
        if (!isset($T['mystery'][$f])) continue;
        unset($T['mystery'][$f]);
        $gone["mystery.$f"] = true;
    }
    return [$T, array_keys($gone)];
}

/**
 * The model the play view uses.
 *
 * Local only, deliberately. The forge lets a visitor bring a key because a world
 * is one expensive build they may not want to wait for; play is dozens of small
 * calls, and a key that had to ride every one of them would be a key sitting in
 * a browser for an hour. One slot, one endpoint, one lock.
 */
function xeric_play_endpoint(?string $sid = null): array
{
    // THE VISITOR'S CHOICE, not the host's default. Playing used to hardcode
    // `local`, which meant the model page could be set to anything and every
    // turn still went to the machine this install was deployed against — the
    // setting existed and did nothing outside the forge.
    $m = xeric_web_model($sid);
    if (!xeric_web_connected($m)) {
        throw new RuntimeException('Nothing is attached to this yet, so nobody can answer. '
            . 'Pick a machine and the xerics start again where they stopped.');
    }
    return xeric_web_endpoint($m);
}

// ---------------------------------------------------------------------------
// The clock, as three buttons
// ---------------------------------------------------------------------------

/**
 * The time control's spans, computed from where the world actually stands.
 *
 * "Skip to evening" is not six hours; it is however long it is from HERE to
 * evening, which at 20:00 means tomorrow. Every span is forward, none can reach
 * XERIC_CLOCK_MAX_JUMP, and each carries the label the button will wear so the
 * page and the worker cannot disagree about what was pressed.
 *
 * THE TARGET IS A DATE, NOT A NUMBER OF MINUTES. A wall-clock delta times sixty
 * is only the right number of seconds on a day that has twenty-four hours in it:
 * on the two nights a year the offset moves, "skip to morning" landed an hour
 * short in spring and an hour long in autumn — in a product whose whole claim is
 * that the world keeps its own time. So the button names 08:00 in the WORLD's
 * timezone and the span is whatever the epoch difference turns out to be.
 *
 * @return array<string,array{key:string,label:string,seconds:int,to:string}>
 */
function xeric_play_spans(array $now, ?array $t = null, ?array $dead = null): array
{
    $epoch = (int)($now['epoch'] ?? 0);
    try { $tz = new DateTimeZone((string)($now['tz'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    $here = (new DateTimeImmutable('@' . $epoch))->setTimezone($tz);

    $until = function (int $hour) use ($here, $epoch): int {
        $target = $here->setTime($hour, 0);
        // Standing on it means the next one, and so does having just passed it.
        if ($target->getTimestamp() <= $epoch) $target = $here->modify('+1 day')->setTime($hour, 0);
        return $target->getTimestamp() - $epoch;
    };

    $spans = [
        'hour'    => ['label' => '+1 hour',          'seconds' => 3600],
        'evening' => ['label' => 'skip to evening',  'seconds' => $until(19)],
        'morning' => ['label' => 'skip to morning',  'seconds' => $until(8)],
    ];

    // SLEEP, which is the same stretch of clock as "skip to morning" and a
    // different thing to have done. Offered only when it is late enough to be
    // plausible, because a sleep button at two in the afternoon is a nap
    // button and this world does not have one. It carries no separate rule —
    // the hours pass exactly as they would have — but it is the honest label
    // for the press somebody actually meant, and it is the one that walks you
    // through a morning shift without pretending you were at it.
    $h = (int)$here->format('G');
    if ($h >= 21 || $h < 5) {
        $spans = ['sleep' => ['label' => 'sleep until morning', 'seconds' => $until(8)]] + $spans;
        unset($spans['morning']);           // the same jump twice is a cluttered row
    }

    // THE WORLD'S OWN NEXT THING (owner's ask, engine half built 2026-08-02).
    // +1 hour and morning/evening are the clock's offers; these are the
    // TIMETABLE's — "the Bluebird opens", "Ruth gets off shift" — read from
    // xeric_world_next_change(), which already respects OUT and the dead. Two
    // at most, so the timetable seasons the row rather than owning it, and
    // only transitions at least half an hour out, because a button that lands
    // you ninety seconds from now is the +1 hour button wearing a costume.
    if ($t !== null) {
        $added = 0;
        foreach (xeric_world_next_change($t, $now, 24, $dead) as $chg) {
            $secs = (int)($chg['epoch'] ?? 0) - $epoch;
            if ($secs < 1800) continue;
            $spans['next_' . ($chg['key'] ?? $added)] = [
                'label' => 'skip to when ' . (string)($chg['label'] ?? 'things change'),
                'seconds' => $secs,
            ];
            if (++$added >= 2) break;
        }
    }

    $out = [];
    foreach ($spans as $key => $s) {
        $secs = min(XERIC_CLOCK_MAX_JUMP, max(60, (int)$s['seconds']));
        $out[$key] = [
            'key'     => $key,
            'label'   => $s['label'],
            'seconds' => $secs,
            'span'    => xeric_clock_span_label($secs),
            'to'      => xeric_play_hhmm((int)($now['epoch'] ?? 0) + $secs, $now),
        ];
    }
    return $out;
}

/** "Thu 19:00", in the world's timezone. */
function xeric_play_hhmm(int $epoch, array $now): string
{
    try { $tz = new DateTimeZone((string)($now['tz'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    return (new DateTimeImmutable('@' . $epoch))->setTimezone($tz)->format('D H:i');
}

/** "Thursday evening · 19:47" — the header's clock. */
function xeric_play_when(array $now): string
{
    return xeric_world_day_name((int)($now['dow'] ?? 0)) . ' ' . (string)($now['phase'] ?? '')
        . ' · ' . (string)($now['hhmm'] ?? '');
}

/**
 * The year this world SAYS it is.
 *
 * The epoch is wall-clock time plus an offset, so its year is this one — and a
 * town written for the late 1990s printing "2026" on its own calendar would be
 * the world contradicting itself in the corner of every screen. The year is
 * read off setting.era: a plain year wins, a decade takes early/mid/late as
 * 2/5/7, and a world whose era names no year keeps the real one. Day, month
 * and weekday still flow from the real clock, so schedules never disagree —
 * this is the fiction's own calendar, not a copy of the era's.
 */
function xeric_play_era_year(array $t, array $now): int
{
    $era  = mb_strtolower((string)($t['setting']['era'] ?? ''));
    $real = (int)substr((string)($now['iso'] ?? date('c', (int)($now['epoch'] ?? 0))), 0, 4);

    if (preg_match('/\b(1[0-9]{3}|20[0-9]{2})s\b/', $era, $m)) {
        $decade = (int)$m[1];
        $lean = str_contains($era, 'early') ? 2 : (str_contains($era, 'late') ? 7 : 5);
        return $decade + $lean;
    }
    if (preg_match('/\b(1[0-9]{3}|20[0-9]{2})\b/', $era, $m)) return (int)$m[1];
    return $real;
}

/** "January 3, 1997, Saturday" — the sidebar's calendar line. */
function xeric_play_date_line(array $t, array $now): string
{
    try { $tz = new DateTimeZone((string)($now['tz'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    $d = (new DateTimeImmutable('@' . (int)($now['epoch'] ?? 0)))->setTimezone($tz);
    return $d->format('F j') . ', ' . xeric_play_era_year($t, $now) . ', '
         . xeric_world_day_name((int)($now['dow'] ?? 0));
}

/** "Fri 01:12" for a stamped event, in the world's timezone. */
function xeric_play_stamp(array $t, int $epoch): string
{
    try { $tz = new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    return (new DateTimeImmutable('@' . $epoch))->setTimezone($tz)->format('D H:i');
}

/** "3 hours ago" — for the world list, where exact minutes help nobody. */
function xeric_play_ago(int $then, ?int $now = null): string
{
    $d = max(0, ($now ?? time()) - $then);
    if ($d < 90)     return 'just now';
    if ($d < 5400)   return intdiv($d, 60) . ' minutes ago';
    if ($d < 172800) return intdiv($d, 3600) . ' hours ago';
    return intdiv($d, 86400) . ' days ago';
}

// ---------------------------------------------------------------------------
// The world, shaped for a screen
// ---------------------------------------------------------------------------

/**
 * Which pronoun family a character reads as, for the avatar's shade and for any
 * sentence the UI writes ABOUT them ("why does she say what she says").
 *
 * THE CHARACTER'S OWN WORD WINS. A `pronouns` field, when one is set (the cog
 * writes it), is the answer and nothing else is consulted. Without one, the
 * character's own prose is read: forge output narrates people in the third
 * person constantly, so counting she/her against he/him across the descriptive
 * fields settles most casts without a model call. Anything mixed, sparse or
 * deliberately neither lands in the third family rather than being forced into
 * one of two, which is the point: 'x' is a real answer, not a failure to guess.
 *
 * @return string 'f' | 'm' | 'x'
 */
function xeric_play_kind(array $c): string
{
    $p = mb_strtolower(trim((string)($c['pronouns'] ?? '')));
    if ($p !== '') {
        $f = (bool)preg_match('/\bshe\b|\bher\b/u', $p);
        $m = (bool)preg_match('/\bhe\b|\bhim\b/u', $p);
        if ($f && !$m) return 'f';
        if ($m && !$f) return 'm';
        return 'x';
    }

    // Only the fields that describe THIS person: a tell is their own thigh
    // being tapped, a psyche line their own sore spot. Seed prose and
    // relationship lines are deliberately left out, because a pronoun in "what
    // she remembers about him" belongs to somebody else half the time.
    $prose = (string)($c['one_line'] ?? '') . ' ' . (string)($c['surface'] ?? '')
           . ' ' . (string)($c['appearance'] ?? '') . ' ' . (string)($c['voice'] ?? '')
           . ' ' . (string)($c['solace'] ?? '');
    foreach ((array)($c['psyche'] ?? []) as $v) if (is_string($v)) $prose .= ' ' . $v;
    foreach ((array)($c['tells'] ?? [])  as $v) if (is_string($v)) $prose .= ' ' . $v;
    foreach ((array)($c['drives'] ?? []) as $v) if (is_string($v)) $prose .= ' ' . $v;
    foreach ((array)($c['week'] ?? [])   as $v) {
        if (is_array($v) && is_string($v['doing'] ?? null)) $prose .= ' ' . $v['doing'];
    }
    $prose = mb_strtolower($prose);

    $f = preg_match_all('/\bshe\b|\bher\b|\bhers\b|\bherself\b|\bwoman\b|\bgirl\b/u', $prose);
    $m = preg_match_all('/\bhe\b|\bhim\b|\bhis\b|\bhimself\b|\bman\b|\bboy\b/u',      $prose);
    // Uncontested wins outright; contested needs to win clearly. A record with
    // no gendered word at all stays in the third family, honestly.
    if ($f > 0 && $m === 0) return 'f';
    if ($m > 0 && $f === 0) return 'm';
    if ($f >= 2 && $f > 2 * $m) return 'f';
    if ($m >= 2 && $m > 2 * $f) return 'm';
    return 'x';
}

/** The pronoun the UI uses when it writes a sentence about somebody. */
function xeric_play_pronoun(array $c): string
{
    return ['f' => 'she', 'm' => 'he', 'x' => 'they'][xeric_play_kind($c)];
}

/**
 * What you call them in a row of twelve.
 *
 * "Bramwell 'Bram' Halloway" is who they are; it is not what fits on a chip
 * beside eleven other people, and a bar of full names either scrolls forever or
 * truncates every one of them to "Bramwell 'Bram' Hallo…", which is the worst of
 * both. So a character carries a SHORT NAME as well as a whole one, and the
 * places that are tight use it while the places that are about the person keep
 * the full one.
 *
 * DERIVED WHEN NOBODY SET IT, because no forged world before today has the field
 * and a blank chip is not an option. The order is the order a person would use:
 *   1. what the character says to call them, if the field is set
 *   2. the nickname already inside their name — 'Bram' in Bramwell 'Bram'
 *      Halloway is the whole town's answer to this question, in the template,
 *      written by the pass that named them
 *   3. their first name
 */
function xeric_play_short_name(array $c): string
{
    $set = trim((string)($c['short_name'] ?? ''));
    if ($set !== '') return $set;

    $full = trim((string)($c['display_name'] ?? ($c['handle'] ?? '')));
    if ($full === '') return '?';

    if (preg_match('/[\'"\x{2018}\x{201C}]([^\'"\x{2019}\x{201D}]{1,24})[\'"\x{2019}\x{201D}]/u', $full, $m)) {
        $nick = trim($m[1]);
        if ($nick !== '') return $nick;
    }

    $words = preg_split('/\s+/u', $full) ?: [];
    return $words[0] !== '' ? (string)$words[0] : $full;
}

/**
 * The avatar: a colour and two letters, and the colour carries the person.
 *
 * SHADED BY FAMILY, DISTINCT BY HAND(LE). Each pronoun family owns a band of
 * hues, rose, blue, and green, so a glance at a full chip bar reads who is who
 * the way a contact list with photos does; within the band the handle picks the
 * exact hue, so two women are two different roses rather than one rose twice.
 * Nothing is stored: the same handle is the same face forever, on every screen.
 */
function xeric_play_face(array $c): array
{
    $kind = xeric_play_kind($c);
    $name = trim((string)($c['display_name'] ?? ($c['handle'] ?? '?')));

    $words = preg_split('/\s+/u', $name) ?: [];
    $txt = '';
    foreach (array_slice($words, 0, 2) as $wd) $txt .= mb_strtoupper(mb_substr($wd, 0, 1));
    if ($txt === '') $txt = '?';

    [$lo, $span] = ['f' => [320, 32], 'm' => [198, 40], 'x' => [88, 54]][$kind];
    $hue = $lo + (crc32((string)($c['handle'] ?? $name)) % $span);

    return ['txt' => $txt, 'hue' => $hue, 'kind' => $kind];
}

/**
 * Where you stand with everybody, in one sentence.
 *
 * "You are Elias, tend bar at The Silt & Spigot" said WHAT you do and left out
 * WHO you do it among — which is the only question a roster of four strangers
 * actually raises. The material is already in the template and nowhere on the
 * screen: an orbit that declares `shares_daily_space_with_user` is the engine's
 * own word for "these are the people in the room with you", and everyone else is
 * someone you know the way anyone knows a small town.
 *
 * NOT INVENTED, EVER. This reads orbits and nothing else; a world whose forge
 * left every orbit outside the user's day says so in those words rather than
 * guessing an intimacy the bibles will not back up. The dead are named as dead
 * — "shares your hours" about somebody in the ground is the kind of small lie
 * that makes a player stop trusting the panel.
 *
 * The last clause is the walls, by name only. Who is being kept in the dark is
 * commons to the person doing the keeping; WHAT is kept is the wall's whole
 * point and is never printed here, the same rule the runs panel follows.
 */
function xeric_play_relations(array $t, PDO $db): string
{
    $chars = (array)($t['cast']['characters'] ?? []);
    if ($chars === []) return '';

    $dead  = xeric_deaths($db);
    $daily = [];   // orbit keys that share the user's day
    foreach ((array)($t['cast']['orbits'] ?? []) as $o) {
        if (!empty($o['shares_daily_space_with_user']) && ($k = (string)($o['key'] ?? '')) !== '') {
            $daily[$k] = true;
        }
    }
    // The workplace is a daily space whether or not the forge said so: an orbit
    // named for the room you work in IS the room you work in.
    $work = (string)($t['user']['occupation']['workplace_key'] ?? '');
    if ($work !== '') $daily[$work] = true;

    // SHORT NAMES IN A SENTENCE. This is the one line on the screen that is
    // written the way somebody would say it out loud, and nobody says "Bramwell
    // 'Bram' Halloway and Marguerite Delacroix-Vance are in that room with me".
    // At the twelve people this is built for, full names turn it into a
    // paragraph of surnames; the whole name is on the hover and on every row
    // underneath.
    $chip = function (array $c) : string {
        $full  = (string)($c['display_name'] ?? ($c['handle'] ?? ''));
        $short = xeric_play_short_name($c);
        return $short !== $full && $full !== ''
            ? '<b title="' . h($full) . '">' . h($short) . '</b>'
            : '<b>' . h($short) . '</b>';
    };

    $near = $far = $gone = [];
    foreach ($chars as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $n = $chip($c);
        if (isset($dead[$h]))                        $gone[] = $n;
        elseif (isset($daily[(string)($c['orbit'] ?? '')])) $near[] = $n;
        else                                         $far[] = $n;
    }

    $where = xeric_world_place_name($t, $work);
    $town  = trim((string)($t['meta']['name'] ?? '')) ?: 'here';

    $bits = [];
    if ($near !== []) {
        // "that room" only works when the sentence before it named one.
        $bits[] = xeric_join_list($near) . ' ' . (count($near) === 1 ? 'is' : 'are')
                . ($where !== '' ? ' in that room with you most days' : ' in your days whether you planned it or not');
    }
    if ($far !== []) {
        $bits[] = xeric_join_list($far) . ' you know the way you know anyone in ' . h($town);
    }
    if ($gone !== []) {
        $bits[] = xeric_join_list($gone) . ' ' . (count($gone) === 1 ? 'is' : 'are') . ' gone';
    }
    if ($bits === []) return '';

    $out = ucfirst(implode('; ', $bits)) . '.';

    $kept = [];
    foreach ((array)($t['cast']['special_roles'] ?? []) as $r) {
        $rh = (string)($r['character'] ?? '');
        foreach ($chars as $c) {
            if ((string)($c['handle'] ?? '') === $rh) { $kept[] = $chip($c); break; }
        }
    }
    if ($kept !== []) {
        $out .= ' There is something you do not say in front of ' . xeric_join_list($kept, 'or') . '.';
    }
    return $out;
}

/**
 * The compass: three readings that move, under the world's name.
 *
 * WHAT A COMPASS IS FOR is knowing where you are while you are moving, so every
 * point on this one has to be a number that changes when the story does. The
 * themes were the obvious thing to print here and they are the wrong thing: they
 * are the same three words the day you forge it and the day you finish it, which
 * is a label, not a reading.
 *
 * The three that move:
 *   HOW LONG   — days on the world's own clock since it started. The offset is
 *                zero at seed (state.php), so the world epoch it began at IS the
 *                real second it was seeded, and every skipped hour is in here.
 *   HOW MUCH   — things that have happened. The one number that says whether
 *                this town has been lived in or just opened.
 *   HOW MANY   — of the cast you have actually spoken to. A running story's real
 *                position: five people in the roster and two threads open means
 *                three of them are still strangers.
 */
function xeric_play_compass_html(array $t, PDO $db, ?array $now = null): string
{
    $now ??= xeric_clock_now($db, $t);

    $seeded = (int)(xeric_world_state_get($db, 'seeded_at') ?? 0);
    $days   = $seeded > 0 ? max(0, intdiv((int)$now['epoch'] - $seeded, 86400)) : 0;
    $lived  = $days < 1 ? 'day one'
            : ($days < 14 ? $days . ($days === 1 ? ' day' : ' days')
            : intdiv($days, 7) . ' weeks');

    $events = xeric_events_count($db);
    $cast   = count((array)($t['cast']['characters'] ?? []));
    $known  = 0;
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '' && xeric_conversation_find($db, $h, 'chat') !== null) $known++;
    }

    $pt = fn(string $v, string $k, string $why) =>
        '<span class="cpt" title="' . h($why) . '"><b>' . h($v) . '</b>' . h($k) . '</span>';

    // THE FOURTH READING, AND THE ONLY ONE THAT IS NOT A COUNT. The needle
    // finally moves (engine/mood.php), so the compass can say what this town
    // has been LIKE lately, in the town's own words rather than a number with
    // a label bolted on. Absent for a world that declared no axis: a world
    // with no vocabulary for its mood should be shown no mood.
    $mood = xeric_mood_read($db, $t);

    return '<p class="scompass">'
        // "day one" is already a whole phrase; "3 days" and "6 weeks" need the
        // preposition that says they are elapsed rather than remaining.
        . $pt($lived, $days < 1 ? '' : ' in',
              'how long this xeric has been running on its own clock, skipped hours included')
        . $pt((string)$events, $events === 1 ? ' thing happened' : ' things happened',
              'everything this xeric has lived through, baked past and all')
        . $pt($known . '/' . $cast, ' spoken to',
              'how many of the cast you have actually opened a thread with')
        . ($mood === [] ? '' : $pt((string)$mood['word'], '',
              'how this xeric has been lately, in its own words'
              . ($mood['motif'] !== '' ? ' — ' . (string)$mood['motif'] : '')))
        . '</p>';
}

// ---------------------------------------------------------------------------
// Presence marks — the vocabulary of the small glyph beside a name
// ---------------------------------------------------------------------------
//
// COMMONS ONLY. Every state below derives from the week, the places and the
// clock — the three things a neighbour standing in the street could know.
// Nothing here reads a wall, a psyche, an arc or a thread: a presence mark that
// knew what somebody was privately carrying would be the inspector's job
// leaking onto the front page, one glyph at a time.

/**
 * Where somebody WORKS, as opposed to where they happen to be standing.
 *
 * The template never says "this is her job" — it says where she is, when, and
 * what she is doing there, so a shift has to be recognised rather than looked
 * up. The claim: a block is a shift when it carries a `doing` and at least
 * three hours, at a place that collects three or more distinct days of such
 * blocks a week. Days because a job is a rhythm, not an errand; hours because
 * the counter-example is in the engine's own fixture — Theo is at the diner
 * five days running, two hours at a time, doing homework, and calling that boy
 * staff would be wrong in exactly the way a town would notice.
 *
 * UNDER-CLAIMING IS THE DESIGN. A shift this misses renders as merely placed,
 * which is never a lie; a shift this over-claims puts a briefcase on a
 * regular's habit. So the borderline cases — the two-day volunteer, the short
 * turn behind a counter — stay pins on purpose.
 *
 * @return string[] place keys
 */
function xeric_play_workplaces(array $c): array
{
    $tally = [];
    foreach ((array)($c['week'] ?? []) as $w) {
        $where = (string)($w['where'] ?? '');
        if ($where === '' || xeric_text($w['doing'] ?? '') === '') continue;
        if (xeric_play_block_minutes($w) < 180) continue;
        foreach ((array)($w['days'] ?? []) as $d) $tally[$where][(int)$d] = true;
    }
    return array_keys(array_filter($tally, fn(array $days): bool => count($days) >= 3));
}

/** Minutes inside one week[] block. A wrap past midnight counts its whole arc;
 *  a block with no times owns its day, the same reading week_covers gives it. */
function xeric_play_block_minutes(array $w): int
{
    $from = xeric_world_minutes(isset($w['from']) ? (string)$w['from'] : null);
    $to   = xeric_world_minutes(isset($w['to'])   ? (string)$w['to']   : null);
    if ($from === null || $to === null) return 1440;
    return $to > $from ? $to - $from : $to + 1440 - $from;
}

/**
 * Is this an hour the world would call sleep?
 *
 * The quiet-hours band when the template has a readable one, engine-canon
 * night when it does not. Deliberately NOT xeric_sweep_quiet(): that gate
 * protects the person holding the phone, so an unreadable field makes it call
 * EVERY hour quiet, and a template may switch it off for events entirely —
 * both right for pings, both wrong here, where they would put a cast to sleep
 * at noon or never. A sleep mark guards nobody; it only has to be plausible.
 */
function xeric_play_sleeping_hour(array $t, array $now): bool
{
    $why  = null;
    $win  = xeric_sweep_quiet_window((string)($t['user']['quiet_hours'] ?? ''), $why);
    if ($win === null) return (string)($now['phase'] ?? '') === 'night';
    $mins = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    [$f, $to] = $win;
    return $to > $f ? ($mins >= $f && $mins < $to) : ($mins >= $f || $mins < $to);
}

/**
 * The mark a cast chip wears, and the sentence under it.
 *
 * Six words and a modifier, decided in confidence order:
 *
 *   absent — no presence row at all. OUT of the story: the engine leaves them
 *            off the map entirely (xeric_world_who_is_where), and absence IS
 *            the state — not a gap to paper over with an invented placement or
 *            a guess that they are sleeping.
 *   work   — placed by a block xeric_play_workplaces() recognises. 💼
 *   placed — placed by any other block. 📍
 *   soon   — not placed, but a block of theirs starts today within two hours:
 *            "→ the Bluebird Diner at 19:00". A written start time outranks
 *            every guess below it — the week is data and "asleep" is only an
 *            inference from the hour, so somebody with a five-o'clock open is
 *            shown heading for it rather than sleeping through it.
 *   asleep — at home inside sleeping hours. 💤
 *   home   — at home, awake, nothing on the week. 🏠
 *   off    — no block and no home to fall back to: the bare words "off shift",
 *            unchanged, for worlds forged before homes existed.
 *
 *   slow   — a modifier riding beside any waking state before ten, when last
 *            night's block wrapped past midnight and is over. ☕ A late close
 *            earns a slow morning; it is a small cast over the chip, gentle on
 *            purpose, and never shown on somebody still marked asleep —
 *            their slow morning has not started yet.
 *
 * @param array|null $row this handle's row out of xeric_world_who_is_where(),
 *                        or null when the map has no row for them
 * @return array{state:string,glyph:string,say:string,pw:string,slow:bool}
 *         `say` is the title-attribute sentence, `pw` the row's short line.
 *         Glyphs stay small and the sentences stay sentences — never paragraphs.
 */
function xeric_play_presence_mark(array $t, array $c, ?array $row, array $now): array
{
    if ($row === null) {
        return ['state' => 'absent', 'glyph' => '', 'say' => '', 'pw' => 'not in the story yet', 'slow' => false];
    }

    $dow  = (int)($now['dow'] ?? 0);
    $prev = ($dow + 6) % 7;
    $mins = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    $week = (array)($c['week'] ?? []);

    // THE MORNING AFTER A WRAP. A block whose `to` is at or before its `from`
    // ran past midnight (week_covers' own reading), so yesterday's copy of it
    // ending in today's small hours is a late night actually worked — and a
    // close at 00:00 exactly still counts, because `to <= mins` holds from the
    // first minute of the day. Over by now (`to <= $mins`, or they would still
    // be placed on it) and before ten is the whole test.
    $slow = false;
    if ($mins < 600) {
        foreach ($week as $wk) {
            $from = xeric_world_minutes(isset($wk['from']) ? (string)$wk['from'] : null);
            $to   = xeric_world_minutes(isset($wk['to'])   ? (string)$wk['to']   : null);
            if ($from === null || $to === null || $to > $from) continue;
            if (!in_array($prev, array_map('intval', (array)($wk['days'] ?? [])), true)) continue;
            if ($to <= $mins) { $slow = true; break; }
        }
    }

    if (($row['where'] ?? null) !== null && empty($row['at_home'])) {
        $name  = xeric_world_place_name($t, (string)$row['where']);
        $doing = trim((string)($row['doing'] ?? ''));
        $line  = 'at ' . $name . ($doing !== '' ? ' · ' . $doing : '');

        // The block that placed them, found the engine's own way — same reader,
        // same first-match tiebreak as who_is_where, so the block this judges
        // is always the block that did the placing.
        $block = null;
        foreach ($week as $wk) {
            if (xeric_world_week_covers($wk, $dow, $prev, $mins)) { $block = $wk; break; }
        }
        if ($block !== null && $doing !== '' && xeric_play_block_minutes($block) >= 180
            && in_array((string)$row['where'], xeric_play_workplaces($c), true)) {
            return ['state' => 'work', 'glyph' => '💼', 'say' => 'at work — ' . $name
                    . ($doing !== '' ? ' · ' . $doing : ''), 'pw' => $line, 'slow' => $slow];
        }
        return ['state' => 'placed', 'glyph' => '📍', 'say' => $line, 'pw' => $line, 'slow' => $slow];
    }

    // Not placed by any block. A start time within two hours is read straight
    // off the week — today's blocks only, because "tonight, past midnight" is
    // tomorrow's business and this mark is about the stretch just ahead.
    // xeric_world_next_change() was the other way to know this and it answers
    // a different question (every transition, everybody, in label prose); one
    // handle's next start is cheaper read where it is written.
    $soon = null;
    foreach ($week as $wk) {
        if (!in_array($dow, array_map('intval', (array)($wk['days'] ?? [])), true)) continue;
        $from  = xeric_world_minutes(isset($wk['from']) ? (string)$wk['from'] : null);
        $where = (string)($wk['where'] ?? '');
        if ($from === null || $where === '' || $from <= $mins || $from - $mins > 120) continue;
        if ($soon === null || $from < $soon[0]) $soon = [$from, $where, xeric_text($wk['doing'] ?? '')];
    }
    if ($soon !== null) {
        $hh   = sprintf('%02d:%02d', intdiv($soon[0], 60), $soon[0] % 60);
        $name = xeric_world_place_name($t, $soon[1]);
        return ['state' => 'soon', 'glyph' => '→',
                'say'   => 'due at ' . $name . ' by ' . $hh . ($soon[2] !== '' ? ' — ' . $soon[2] : ''),
                'pw'    => $name . ' at ' . $hh, 'slow' => $slow];
    }

    if (!empty($row['at_home'])) {
        if (xeric_play_sleeping_hour($t, $now)) {
            return ['state' => 'asleep', 'glyph' => '💤', 'say' => 'home and asleep, most likely',
                    'pw' => 'home', 'slow' => false];
        }
        return ['state' => 'home', 'glyph' => '🏠',
                'say'   => 'home — ' . xeric_world_place_name($t, (string)$row['where'])
                         . ', nothing on the week right now',
                'pw'    => 'home', 'slow' => $slow];
    }

    return ['state' => 'off', 'glyph' => '', 'say' => '', 'pw' => 'off shift', 'slow' => $slow];
}

/**
 * The cast: who they are, where they are standing, and whether the phone is lit.
 *
 * Presence comes from xeric_world_who_is_where() — the same read sweeps.php uses
 * to decide who could plausibly be at a thing — so the room the UI shows and the
 * room an event happens in can never disagree.
 */
function xeric_play_cast(array $t, PDO $db, array $now): array
{
    $deaths   = xeric_deaths($db);
    $presence = xeric_world_who_is_where($t, $now, array_keys($deaths));
    $star     = (string)($t['cast']['protagonist']['handle'] ?? '');

    $out = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;

        $conv  = xeric_conversation_find($db, $h, 'chat');
        $row   = $presence[$h] ?? null;
        $where = $row['where'] ?? null;
        $gone  = $deaths[$h] ?? null;

        $out[] = [
            'handle'      => $h,
            'name'        => (string)($c['display_name'] ?? $h),
            'short'       => xeric_play_short_name($c),
            'one_line'    => (string)($c['one_line'] ?? ''),
            'face'        => xeric_play_face($c),
            'pronoun'     => xeric_play_pronoun($c),
            'where'       => $where !== null ? xeric_world_place_name($t, (string)$where) : '',
            'doing'       => (string)($row['doing'] ?? ''),
            // The home fallback is a real placement, but not the same claim as
            // a block: `at_home` rides along so the renderer can say "home"
            // instead of pretending somebody's kitchen is an appointment.
            'at_home'     => !empty($row['at_home']),
            // A LIVING character with no presence row is OUT of the story —
            // the map leaves the unentered off entirely, and that absence is a
            // state the renderer says out loud rather than a lookup to shrug
            // past. Never claimed for the dead, whose absence means the ground.
            'out'         => $gone === null && $row === null,
            // The presence mark, computed HERE and not in the renderer: the
            // row is the one thing that is always repainted, and a mark that
            // rode anywhere else could be showing yesterday's morning.
            'mark'        => $gone === null
                ? xeric_play_presence_mark($t, $c, $row, $now)
                : ['state' => 'dead', 'glyph' => '', 'say' => '', 'pw' => '', 'slow' => false],
            'unread'      => $conv ? (int)$conv['unread'] : 0,
            'known'       => $conv !== null,
            'protagonist' => $h !== '' && $h === $star,
            // The dead stay on the roster, in cast order, where they always
            // were. Sorting them to the bottom or dropping them would be the
            // deletion this engine refuses to do, done in CSS.
            'dead'        => $gone !== null,
            'how'         => $gone !== null ? (string)$gone['how'] : '',
        ];
    }
    return $out;
}

/**
 * Put out the unread dot on a thread the visitor is demonstrably looking at.
 *
 * The engine counts unread because a character spoke; deciding when somebody has
 * READ that is a property of THIS screen, not of the world, which is why it
 * lives here. updated_at is written back unchanged — looking at a thread is not
 * the thread changing, and reordering the world because somebody glanced at it
 * would be a lie about when things happened.
 */
function xeric_play_mark_read(PDO $db, int $convId): void
{
    $conv = xeric_conversation_get($db, $convId);
    if ($conv === null || (int)$conv['unread'] === 0) return;
    xeric_conversation_touch($db, $convId, 0, (int)$conv['updated_at']);
}

/**
 * One thread, oldest first, and the unread dot put out.
 *
 * Reading a thread is what marks it read, so the dot means "since you last
 * looked" rather than "since the server last restarted".
 */
/**
 * One collapsible section of the sidebar, opened.
 *
 * THE SIDEBAR OUTGREW ITS COLUMN. Rooms, the people the clock says are nowhere,
 * and everything that happened while nobody was watching all stack into one
 * 17rem strip — and at the cast of twelve this app is built for, that is a
 * scroll rather than a glance, which is the one thing a sidebar may not be.
 *
 * THE COUNT IS WHY SHUTTING ONE IS SAFE. A folded section still prints how much
 * is behind it, so closing it costs you the detail and never the fact that
 * there is something to look at: "while you were away · 6" reads shut.
 *
 * AND IT PRINTS ZERO. At four in the morning nobody is anywhere, and "· 0" is
 * the true answer to a folded "where everybody is" — where no badge at all is
 * indistinguishable from a section that never counted itself. The one number
 * this must never do is go quiet.
 *
 * <details> AND NOT A CLASS WE TOGGLE, the same call the events inside already
 * made: the browser gives us the keyboard, the arrow, the screen-reader state
 * and the print behaviour for free. Which sections are open is a preference
 * about the app rather than a fact about a xeric, so play.php keeps it in the
 * browser — and it therefore survives both the twelve-second repaint and the
 * next time this xeric is opened.
 */
function xeric_play_sideblock(string $key, string $title, int $n, bool $open): string
{
    return '<details class="sideblock" data-sb="' . h($key) . '"' . ($open ? ' open' : '') . '>'
         . '<summary class="sh">' . h($title)
         . '<span class="sbn">' . $n . '</span>'
         . '</summary><div class="sbb">';
}

/**
 * The sidebar: where everybody is, and what has been happening without you.
 *
 * THIS IS THE PANEL THE APP WAS MISSING. A messenger without one is a series of
 * pages: opening a thread hid the cast, so there was no moment where you could
 * see who else was about while you were talking to somebody. A xeric is a place
 * with people moving around it — that fact had nowhere to live.
 *
 * BY PLACE, NOT BY PERSON. The conversation list is the chip bar's job and the
 * cast screen's; this answers the other question, which is the one only a xeric
 * can be asked: who is in the same room right now, and what are they doing. A
 * room with nobody in it is still listed, because "the diner is empty at this
 * hour" is information.
 *
 * AND WHAT HAPPENED OFF SCREEN. The heart lives hours while nobody is watching,
 * and until now the only way to see what came of that was to open the book. It
 * is the sidebar's other half: the last few things this xeric did without you.
 */
function xeric_play_side_html(array $t, PDO $db, ?array $now = null, string $slug = '', bool $mine = true): string
{
    $now ??= xeric_clock_now($db, $t);
    $map  = xeric_travel_map($t, $db, $now);
    $dead = xeric_deaths($db);

    // One face per handle, so every name in this panel wears the same disc the
    // chips and the thread do.
    $faces = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $faces[(string)($c['handle'] ?? '')] = xeric_play_face($c);
    }
    $disc = function (string $h) use ($faces): string {
        $f = $faces[$h] ?? ['txt' => '?', 'hue' => 0];
        return '<span class="av s" style="--hue:' . (int)$f['hue'] . '" aria-hidden="true">'
             . h((string)$f['txt']) . '</span>';
    };

    // Every room's hours, read once off the template: the sidebar answers
    // "who is where" and the next question is always "until when".
    $hours = [];
    foreach ((array)($t['places'] ?? []) as $p) {
        $k = (string)($p['key'] ?? '');
        $o = trim((string)($p['hours']['open'] ?? ''));
        $c = trim((string)($p['hours']['close'] ?? ''));
        if ($k !== '' && $o !== '' && $c !== '') $hours[$k] = $o . '–' . $c;
    }

    // Counted before it is drawn, because the heading has to carry the number
    // even when the section is shut. People and not rooms: an empty diner is
    // worth a line inside, but "9" on a folded section means nine people about.
    $standing = 0;
    foreach ((array)($map['places'] ?? []) as $pl) $standing += count((array)($pl['who'] ?? []));

    // THE DAY'S SKY, above the rooms it hangs over. The same derived line every
    // prompt reads (engine/weather.php): day-coarse world state that repaints
    // with the sidebar, so a skip that crosses midnight changes the weather the
    // same moment it changes everything else.
    //
    // AND THE TOWN'S MOOD BESIDE IT, because they are the same kind of fact:
    // what it is like here today, one line, no numbers. The sky is derived
    // and the mood is lived — hours move the needle (engine/mood.php) — but a
    // person reading the panel is asking one question, so they answer as one
    // sentence. The motif carries it when the world wrote one: "a little
    // reckless · a truck parked where no truck should be".
    $wx   = xeric_weather_line($t, $now, $db);
    $mood = xeric_mood_read($db, $t);
    $moodLine = $mood === [] || ($mood['side'] ?? '') === 'ordinary'
        ? ''
        : (string)$mood['word'] . ((string)($mood['motif'] ?? '') !== ''
            ? ' <i>· ' . h((string)$mood['motif']) . '</i>' : '');

    $out = '';
    if ($wx !== '' || $moodLine !== '') {
        $out = '<p class="swx">' . h($wx)
             . ($moodLine !== '' ? ($wx !== '' ? '<br>' : '') . '<b>' . $moodLine . '</b>' : '')
             . '</p>';
    }

    // WHO IS AT THE CENTRE, AND THE WAY IN. Above the rooms, because it is a
    // fact about the table rather than about the town — and because the door
    // was buried in the cog, which is where you put a setting, not where you
    // put an invitation. Somebody in the same house should be able to see that
    // letting a person in is a thing this program does.
    //
    // Shown for the owner always (there is always a way in to offer) and for a
    // guest only when there is somebody besides themselves, so a guest's panel
    // is not a permanent reminder of whose house they are in.
    require_once XERIC_WEB_LIB . '/engine/pair.php';
    $people = xeric_players($db, $t);
    if ($mine || count($people) > 1) {
        $out .= xeric_play_sideblock('who', 'who is here', count($people), count($people) > 1);
        $out .= '<ul class="atcentre">';
        foreach ($people as $pid => $p) {
            $g = xeric_guest($db, $pid);
            $out .= '<li><span class="pn">' . h((string)$p['name']) . '</span>'
                  . ($pid === XERIC_PLAYER_FIRST
                      ? '<span class="pw">whose world it is</span>'
                      : '<span class="pw">' . h($g === null ? 'here too'
                          : ($g['way'] === 'stranger'
                              ? 'a stranger, so far'
                              : 'came with ' . xeric_player_name($db, (int)$g['via'], $t))) . '</span>'
                    . ($mine ? '<button type="button" class="pout" data-out="' . (int)$pid . '"'
                             . ' title="show them out">×</button>' : ''))
                  . '</li>';
        }
        $out .= '</ul>';
        if ($mine) {
            $waiting = count(xeric_pair_open($db));
            $out .= '<button type="button" class="sinv" data-invite="1">'
                  . ($waiting > 0 ? 'show the code again' : 'let somebody in')
                  . '</button>'
                  . '<p class="sinvhint">a code for somebody else in the house to scan. They join '
                  . 'THIS world, not a copy.</p>'
                  . '<div class="sinvbox" id="sinvbox" hidden></div>';
        }
        $out .= '</div></details>';
    }

    // Open by default — this is the panel the sidebar was built for, and the one
    // section that answers the question only a xeric can be asked.
    $out .= xeric_play_sideblock('where', 'where everybody is', $standing, true)
         . '<ul class="places">';

    foreach ((array)($map['places'] ?? []) as $pl) {
        $who = (array)($pl['who'] ?? []);
        $cls = 'place'
             . (!empty($pl['here']) ? ' here' : '')
             . (empty($pl['open']) ? ' shut' : '')
             . ($who === [] ? ' empty' : '');

        // THE PLACE IS A BUTTON. Knowing where everybody is only matters if you
        // can then go and stand there, so the name walks you over, through the
        // same trip the map screen charges for. Where you already are, and
        // anywhere shut, the name goes back to being a fact.
        $key  = (string)($pl['key'] ?? '');
        $walk = $key !== '' && empty($pl['here']) && !empty($pl['open']);
        $mins = (int)($pl['minutes'] ?? 0);
        $hrs  = isset($hours[$key]) ? '<span class="phrs">' . h($hours[$key]) . '</span>' : '';
        $out .= '<li class="' . $cls . '">'
              . ($walk
                  ? '<button type="button" class="pl wplace" data-to="' . h($key) . '"'
                    . ' title="walk there · ' . $mins . ' min">' . h((string)$pl['name']) . $hrs
                    . '<span class="wgo2">› go</span></button>'
                  : '<span class="pl">' . h((string)$pl['name']) . $hrs
                    . (!empty($pl['here']) ? '<span class="youare">you are here</span>' : '')
                    . '</span>');

        if ($who === []) {
            $out .= '<span class="nobody">' . (empty($pl['open']) ? 'closed' : 'nobody') . '</span>';
        } else {
            $out .= '<ul class="who">';
            foreach ($who as $p) {
                $h = (string)($p['handle'] ?? '');
                $doing = trim((string)($p['doing'] ?? ''));
                // `at_home` rides the map for exactly this: somebody off shift
                // resolved to their own roof reads "home", not like a customer
                // standing in a closed room. One word, where the name is.
                //
                // And the inventory rides as a TOOLTIP, not a line: what they
                // wear and carry is commons (a bystander sees it), but printed
                // it would double every row. Hover answers; the panel stays a
                // glance.
                $cInv   = xeric_world_character($t, $h);
                $invBits = array_merge(
                    array_map('xeric_text', (array)($cInv['wears'] ?? [])),
                    array_map('xeric_text', (array)($cInv['carries'] ?? [])));
                $invTip  = trim(implode(', ', array_filter($invBits)));
                $out .= '<li><button type="button" class="wperson" data-h="' . h($h) . '"'
                      . ($invTip !== '' ? ' title="' . h($invTip) . '"' : '') . '>' . $disc($h)
                      . '<span><span class="wn">' . h((string)($p['name'] ?? $h))
                      . (!empty($p['at_home']) ? '<span class="whome">home</span>' : '') . '</span>'
                      . ($doing !== '' ? '<span class="wd">' . h($doing) . '</span>' : '')
                      . '</span></button></li>';
            }
            $out .= '</ul>';
        }
        $out .= '</li>';
    }
    $out .= '</ul></div></details>';

    // Anybody the clock says is nowhere — asleep, off shift, or dead — still
    // exists, and a list that quietly omitted them would read as a cast that
    // shrinks at night.
    $placed = [];
    foreach ((array)($map['places'] ?? []) as $pl) {
        foreach ((array)($pl['who'] ?? []) as $p) $placed[(string)($p['handle'] ?? '')] = true;
    }
    $away = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || isset($placed[$h])) continue;
        $away[] = ['h' => $h, 'name' => (string)($c['display_name'] ?? $h), 'dead' => isset($dead[$h])];
    }
    if ($away !== []) {
        // Shut by default. Who is NOT about is the standing background fact —
        // true all night, changing slowly — so it is the first thing to fold.
        $out .= xeric_play_sideblock('away', 'not out', count($away), false)
              . '<ul class="who away">';
        foreach ($away as $a) {
            $out .= '<li><button type="button" class="wperson' . ($a['dead'] ? ' gone' : '') . '"'
                  . ' data-h="' . h($a['h']) . '">' . $disc($a['h'])
                  . '<span><span class="wn">' . h($a['name']) . '</span>'
                  . '<span class="wd">' . ($a['dead'] ? 'dead' : 'not out right now') . '</span></span></button></li>';
        }
        $out .= '</ul></div></details>';
    }

    // WHAT HAPPENED IS NOT A HEADLINE. These were four titles with nothing
    // behind them — "A broken porcelain tea set" is a thing you want to READ,
    // and the only way to was to open the book and scroll to the bottom. Every
    // one now opens where it stands, with the hour, the room, who was in it,
    // what happened, and the way through to why the engine decided it.
    //
    // <details> and not a class we toggle ourselves: the browser gives us the
    // keyboard, the arrow, the screen-reader state and the print behaviour for
    // free, and the sidebar's repaint re-opens what was open by id.
    // Filtered before it is counted, not while it is drawn: a titleless event
    // renders nothing, so counting it would print a number the section cannot
    // show — and a "6" that opens onto four lines is worse than no number.
    $events = array_values(array_filter(
        xeric_events_recent($db, 6),
        fn($e) => trim((string)($e['title'] ?? '')) !== ''
    ));
    if ($events !== []) {
        // Shut by default too, and the count carries it: this is news, so the
        // number is the part you need at a glance — the prose is the part you
        // open when you have a minute for it.
        $out .= xeric_play_sideblock('recent', 'while you were away', count($events), false)
              . '<ul class="offscreen">';
        foreach ($events as $e) {
            $ti = trim((string)($e['title'] ?? ''));

            $who = [];
            foreach ((array)($e['participants'] ?? []) as $p) {
                $n = xeric_world_name($t, (string)$p);
                if ($n !== '') $who[] = $n;
            }
            $place = xeric_world_place_name($t, (string)($e['place'] ?? ''));
            $when  = xeric_play_stamp($t, (int)($e['world_epoch'] ?? 0));
            $prose = trim((string)($e['prose'] ?? ''));

            $out .= '<li><details class="ev" data-e="' . (int)$e['id'] . '">'
                  . '<summary>' . h($ti) . '</summary>'
                  . '<div class="evb"><p class="evw">' . h($when)
                  . ($place !== '' ? ' · ' . h($place) : '')
                  . ($who !== [] ? ' · ' . h(implode(', ', $who)) : '') . '</p>'
                  . ($prose !== '' ? '<p class="evp">' . h($prose) . '</p>' : '')
                  . ($slug !== '' && $mine
                      ? '<a class="whylink" href="why.php?w=' . h(rawurlencode($slug)) . '&amp;e=' . (int)$e['id']
                        . '">why did this happen?</a>'
                      : '')
                  . '</div></details></li>';
        }
        $out .= '</ul></div></details>';
    }

    return $out;
}

/** The narrator's handle. Not a person, and deliberately unable to be one. */
const XERIC_NARRATOR = '__narrator';

/**
 * Everything at once, which is the one thing no character may have.
 *
 * A CHARACTER KNOWS WHAT THEY KNOW — that is the whole engine, and the walls
 * exist to keep it true. The narrator is not a character: it is the thing that
 * can see the board, and it is the only place in this app where seeing all of it
 * is correct rather than a leak.
 *
 * WHICH MAKES THE PROMPT THAT USES IT THE DANGEROUS PART. Seeing the board and
 * TELLING somebody what is on it are different acts, and the second one ends the
 * story in a sentence. So this assembles positions, hours and open threads, and
 * xeric_play_hint() is under orders to point rather than answer.
 */
function xeric_play_overview(array $t, PDO $db): string
{
    $now  = xeric_clock_now($db, $t);
    $dead = xeric_deaths($db);
    $me   = trim((string)($t['user']['name'] ?? '')) ?: 'you';

    $lines = ['It is ' . xeric_play_when($now) . '.'];

    // ['you']['place'] is WHERE they are; ['you']['name'] is who they are, and
    // reading the wrong one produced "Elias is at Elias."
    $where = xeric_travel_map($t, $db, $now);
    $at = trim((string)($where['you']['place'] ?? ''));
    $lines[] = $at !== '' ? "$me is at $at." : "$me is not anywhere in particular.";

    // Who is about, where, and whether anything is waiting from them. Nothing
    // about what they want or are hiding: that is theirs.
    $people = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $name = (string)($c['display_name'] ?? $h);
        if (isset($dead[$h])) { $people[] = "$name is dead."; continue; }

        $row = $name;
        $one = trim((string)($c['one_line'] ?? ''));
        if ($one !== '') $row .= ' — ' . $one;

        $conv = xeric_conversation_find($db, $h, 'chat');
        $unread = $conv !== null ? (int)$conv['unread'] : 0;
        $said   = $conv !== null;
        if ($unread > 0)   $row .= ' (has said something you have not read)';
        elseif (!$said)    $row .= ' (never spoken to)';
        $people[] = $row;
    }
    if ($people) $lines[] = "The people:\n- " . implode("\n- ", $people);

    // What the xeric has done lately, in the sentences it already wrote.
    $recent = [];
    foreach (xeric_events_recent($db, 6) as $e) {
        $ti = trim((string)($e['title'] ?? ''));
        if ($ti !== '') $recent[] = $ti;
    }
    if ($recent) $lines[] = "Lately:\n- " . implode("\n- ", $recent);

    return implode("\n\n", $lines);
}

/**
 * A hint, or a nudge toward somebody. Never the answer.
 *
 * TWO SHAPES AND THE PROMPT PICKS. If the player is somewhere near the thread,
 * a hint about what to press on; if they are nowhere near it, a name and a
 * reason to go — "talk to Tom" — because a hint about a thing you have not found
 * is indistinguishable from noise.
 *
 * IT MAY NOT SPEND THE STORY. It can see what the xeric is keeping quiet and
 * saying it would end the thing the player is here for, so it is told to point
 * and not to tell. The overview is what makes a useful pointer possible; the
 * instruction is what stops it being a spoiler.
 */
function xeric_play_hint(array $t, PDO $db, string $ask, array $endpoint): string
{
    $me   = trim((string)($t['user']['name'] ?? '')) ?: 'you';
    $ask  = trim($ask);
    $view = xeric_play_overview($t, $db);

    $msgs = [
        ['role' => 'system', 'content' =>
            'You are the narrator of a story world, talking to the person living in it. '
            . 'You can see everything. You never say what anybody is hiding, never explain a '
            . 'mystery, and never describe what would happen next. You point. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "Here is where things stand.\n\n$view\n\n"
            . ($ask !== '' ? "$me asks: \"$ask\"\n\n" : "$me is stuck and has not said what about.\n\n")
            . "Answer in ONE of two ways, whichever is more use:\n"
            . "  - a HINT: one thing worth pressing on, if they are already near it.\n"
            . "  - a NUDGE: NAME one of the people above and say why to go to them.\n"
            . "If they asked who to talk to, or they have spoken to nobody, NUDGE.\n\n"
            . "Under 30 words. Second person. No spoilers: do not say what anybody is "
            . "hiding, do not resolve anything, do not describe what will happen.\n\n"
            . 'Reply exactly: {"say": "..."}'],
    ];

    $out = xeric_llm_json($endpoint, $msgs, ['tag' => 'hint', 'temperature' => 0.9, 'max_tokens' => 160]);
    $v = $out['say'] ?? ($out['text'] ?? ($out['hint'] ?? ''));
    if (is_array($v)) $v = implode(' ', array_map('strval', $v));
    return trim(mb_substr(trim((string)$v), 0, 400));
}

/**
 * One line the player might say next, written to open something.
 *
 * NOT A CONTINUATION OF THEIR SENTENCE. An autocomplete that finishes what
 * somebody started guesses at what they meant and is wrong most of the time;
 * this proposes a whole line instead, which is either useful or plainly
 * ignorable. The blank box is the problem being solved, not the half-typed one.
 *
 * IT IS TOLD TO OPEN RATHER THAN CLOSE. A model asked for "something to say"
 * writes "sounds good, talk later" — polite, in character, and the end of the
 * conversation. A suggestion that closes it is worse than none, because it is
 * easier to accept than to reject. So the ask is for a question, a request, or
 * something admitted: the three shapes that oblige an answer.
 *
 * IT SEES THE THREAD AND THE HOUR AND NOTHING ELSE. No dossiers, no interiors,
 * no walls to breach — the player's own view of this conversation is exactly
 * what a player has, which is the only thing they could have written from
 * anyway.
 */
function xeric_play_suggest(array $t, PDO $db, string $handle, array $endpoint, ?array $sceneLines = null): string
{
    $c    = xeric_world_character($t, $handle) ?? [];
    $them = trim((string)($c['display_name'] ?? $handle));
    $you  = trim((string)($t['user']['name'] ?? '')) ?: 'you';
    $now  = xeric_clock_now($db, $t);

    // Two sources, one ghost. A thread suggestion reads the conversation the
    // db holds; a SCENE suggestion (the watch surface's walk-in composer)
    // hands its own transcript in, because a scene has no conversation row
    // until it closes — and asking the db would suggest a line for a
    // conversation that is not the one on screen. Null keeps every existing
    // caller byte-identical.
    if ($sceneLines !== null) {
        $lines = [];
        foreach ($sceneLines as $l) {
            $who = trim((string)($l['name'] ?? $l['handle'] ?? ''));
            $txt = trim((string)($l['text'] ?? ''));
            if ($txt !== '') $lines[] = $who . ': ' . mb_substr($txt, 0, 300);
        }
    } else {
        $th   = xeric_play_thread($t, $db, $handle, 12);
        $lines = [];
        foreach ((array)($th['messages'] ?? []) as $m) {
            $who = ((string)($m['role'] ?? '')) === 'user' ? $you : $them;
            $txt = trim((string)($m['text'] ?? ''));
            if ($txt !== '') $lines[] = $who . ': ' . mb_substr($txt, 0, 300);
        }
    }
    $recent = $lines === [] ? '(nothing yet, this is the first thing said)'
                            : implode("\n", array_slice($lines, -8));

    $msgs = [
        ['role' => 'system', 'content' =>
            'You suggest ONE line for somebody to send in a text conversation. '
            . 'Reply with ONE JSON object and nothing else.'],
        ['role' => 'user', 'content' =>
            "You are helping $you write their next message to $them.\n"
            . 'It is ' . xeric_play_when($now) . ".\n\n"
            . "The conversation so far:\n$recent\n\n"
            . "Suggest ONE thing $you could send that OPENS something: a question, a "
            . "request, or something admitted. It must oblige a reply. Do not write a "
            . "sign-off, do not agree and leave, do not say goodbye.\n"
            . "Their own words, plain, under 20 words, no quotation marks.\n\n"
            . 'Reply exactly: {"say": "..."}'],
    ];

    $out = xeric_llm_json($endpoint, $msgs, ['tag' => 'suggest', 'temperature' => 1.0, 'max_tokens' => 120]);
    $v = $out['say'] ?? ($out['text'] ?? ($out['value'] ?? ''));
    if (is_array($v)) $v = implode(' ', array_map('strval', $v));
    $v = trim((string)$v, " \t\n\r\0\x0B\"'");
    return mb_substr($v, 0, 240);
}

function xeric_play_thread(array $t, PDO $db, string $handle, int $limit = 60): array
{
    $conv = xeric_conversation_find($db, $handle, 'chat');
    if ($conv === null) return ['conversation_id' => null, 'messages' => []];

    $id   = (int)$conv['id'];
    $rows = xeric_messages_recent($db, $id, $limit);
    xeric_play_mark_read($db, $id);

    // The photos running behind this conversation, mapped by the message they
    // belong to. A done job turns its caption message into an image bubble; a
    // pending one leaves the caption standing — the caption IS the photo until
    // the reaper develops it, and the thread's ordinary repaint is what swaps
    // the picture in when it lands.
    $shots = xeric_photo_thread($db, $id);

    $out = [];
    foreach ($rows as $m) {
        $row = [
            'role' => (string)$m['role'],
            'who'  => (string)$m['role'] === 'user'
                        ? (trim((string)($t['user']['name'] ?? '')) ?: 'you')
                        : xeric_world_name($t, (string)($m['handle'] ?? $handle)),
            'text' => (string)$m['content'],
            'when' => xeric_play_stamp($t, (int)($m['world_epoch'] ?? $m['created_at'])),
        ];
        $shot = $shots[(int)$m['id']] ?? null;
        if ($shot !== null && (string)$shot['status'] === 'done') {
            $row['photo'] = ['k' => 'message', 's' => (string)$shot['subject'],
                             'caption' => (string)$shot['caption']];
        }
        $out[] = $row;
    }
    return ['conversation_id' => $id, 'messages' => $out];
}

/**
 * The world-state panel: what this world runs on, and who it belongs to.
 *
 * The protected character is named and their secret is NOT. `must_not_know` is a
 * wall (sweeps.php keeps them out of the room for it); printing it on the front
 * page would hand the visitor the one thing the world is built around keeping.
 */
function xeric_play_panel(array $t, PDO $db, bool $mine = true): array
{
    $protected = [];
    foreach ((array)($t['cast']['special_roles'] ?? []) as $sr) {
        $h = (string)($sr['character'] ?? '');
        if ($h === '' || trim((string)($sr['must_not_know'] ?? '')) === '') continue;
        $protected[] = ['handle' => $h, 'name' => xeric_world_name($t, $h)];    // name only. never the secret.
    }

    $star = (array)($t['cast']['protagonist'] ?? []);
    $chance = (float)($t['events']['sweep_chance'] ?? XERIC_SWEEP_CHANCE);

    return [
        'armed'    => xeric_sweep_armed($t),
        'disarmed' => xeric_sweep_disarmed($t),
        'kinds'    => array_keys(xeric_sweep_kinds_for($t)),
        'pace'     => (string)($t['events']['pace'] ?? ''),
        // The money dial, and whether there is a roster for it to act on. Both,
        // because a dial with nothing under it is worth showing differently
        // from one that is simply switched off — the first is a world that
        // never had a shift in it, the second is a choice somebody made.
        'money'    => xeric_money_dial($db, $t),
        'shifts'   => count(xeric_shifts($t)),
        // The games this world has, and whether one is on TONIGHT — two
        // different facts, because a table you cannot sit at until Thursday is
        // worth knowing about and a button that does nothing is not.
        'tables'   => array_values(array_map(
            fn($g) => ['key' => $g['key'], 'name' => $g['name'],
                       'tonight' => xeric_table_tonight($g, xeric_clock_now($db, $t))],
            xeric_tables($t))),
        'chance'   => $chance,
        'gap'      => (int)($t['events']['expected_gap_hours'] ?? 0),
        // How long a world actually goes between things, which is NOT `gap`:
        // that is the unattended stretch the forge normalised the density FOR
        // (xeric_forge_pace), and a panel that printed it as the interval told
        // somebody tuning their pace knob that a steady world was twice as quiet
        // as it is. One roll per sweep window at `chance`, so: window ÷ chance.
        'every'    => $chance > 0 ? max(1, (int)round((XERIC_SWEEP_WINDOW / 3600) / $chance)) : 0,
        // The name, and the arc only for whoever owns this world. `arc` is what
        // the protagonist is really after, which xeric_play_redact() strips from
        // a stranger's copy of the template — printing it here would have handed
        // back three inches lower what world.php had just refused.
        'star'     => $star === [] ? null : [
            'name' => (string)($star['display_name'] ?? xeric_world_name($t, (string)($star['handle'] ?? ''))),
            'arc'  => $mine ? (string)($star['arc'] ?? '') : '',
        ],
        'protected' => $protected,
        'events'    => xeric_events_count($db),
        'memories'  => xeric_memories_count($db),
        'unread'    => xeric_conversation_unread_total($db),
    ];
}

/**
 * Everything a repaint needs after a turn or a tick, in one object.
 *
 * The cast and the panel travel as RENDERED HTML, not as data for the browser to
 * render a second way. ui.php makes the same choice for the forge's result
 * screen and gives the reason: one renderer means the thing you watched happen
 * and the thing you come back to cannot disagree. The structured `cast` rides
 * along because the page occasionally wants to know a handle without parsing.
 */
function xeric_play_state(array $w, ?string $sid = null): array
{
    $t   = $w['template'];
    $db  = $w['db'];
    $now = xeric_clock_now($db, $t);
    $cast = xeric_play_cast($t, $db, $now);
    // Both halves travel: the rows so the page can tell a story that has just
    // closed from one that was already closed when it loaded, and the HTML so
    // the strip is rendered once, here, the way the cast and the panel are.
    $stories = xeric_play_stories($w, (int)$now['epoch']);
    // Position repaints with everything else, because everything else can move
    // it: a skip changes who is in the room you are standing in, and a room that
    // silently kept saying Dot was there would be the same class of lie as a
    // stale clock.
    $map = xeric_travel_map($t, $db, $now);

    return [
        'clock' => [
            'when'  => xeric_play_when($now),
            'hhmm'  => (string)$now['hhmm'],
            'phase' => (string)$now['phase'],
            'epoch' => (int)$now['epoch'],
            'off'   => xeric_clock_span_label(xeric_clock_offset($db)),
            // A world that is not moving and does not say so looks broken, and
            // the clock chip is the one thing on this screen everybody reads.
            'paused' => xeric_clock_is_paused($db),
        ],
        'spans'      => array_values(xeric_play_spans($now, $t, xeric_dead_handles($db))),
        // The rewind window, read through the same guards the rewind runs —
        // null means the button stays dark, and it goes dark the moment words
        // are said or the world lives an hour of its own.
        'rewind'     => (static function () use ($t, $db): ?array {
            require_once dirname(__DIR__, 2) . '/engine/rewind.php';
            return xeric_rewind_peek($t, $db);
        })(),
        'cast'       => $cast,
        'cast_html'  => xeric_play_cast_html($cast, (string)$w['slug'], (bool)$w['mine']),
        'where'      => $map,
        'where_html' => xeric_play_where_html($map, (string)$w['slug']),
        'fate_html'  => xeric_play_fate_html($t, $db, $cast),
        // The meter is server-rendered chrome, so without this it holds whatever
        // it said when the page loaded — which for a counter somebody is reading
        // as currency is the one number on the screen that must never be stale.
        'meter_html' => xeric_web_meter_html($sid),
        'fate'       => ['mode' => xeric_death_mode($t, $db), 'locked' => xeric_death_locked($db),
                         'living' => count(xeric_death_living($t, $db))],
        'panel_html' => xeric_play_panel_html(xeric_play_panel($t, $db, (bool)$w['mine'])),
        'you_html'   => xeric_play_you_html($w, $sid),
        'past_html'  => xeric_play_events_html($t, $db, (string)$w['slug'], (bool)$w['mine']),
        'story'      => $stories,
        'story_html' => xeric_play_story_html($stories, (string)($t['meta']['name'] ?? '')),
    ];
}

// ---------------------------------------------------------------------------
// The story the world is carrying
// ---------------------------------------------------------------------------
//
// An overlay is a story that ENDS laid over a world that does not, and both
// halves of that have to be on the screen or the premise is a paragraph in a
// README. What is shown is exactly what xeric_story_shelf() calls player-visible
// — the title, the logline before it resolves, `world_keeps` after — and nothing
// else. `truth`, `actually` and every piece stay in the file: this is a strip on
// the front page of a mystery, and the cheapest possible way to lose a mystery
// is to print its answer above the time control.

/**
 * Roughly where a story stands, in a word a player can read.
 *
 * The engine derives six stages off the curve and declares none of them
 * (xeric_story_snake), so this maps rather than duplicates: a seventh stage
 * appearing in story.php must not need a second edit here to keep working, which
 * is why an unknown stage falls through to the engine's own word.
 */
function xeric_play_stage_word(string $stage, bool $live): string
{
    if (!$live) return 'closed';
    return [
        'opening'    => 'opening',
        'rising'     => 'rising',
        'taper'      => 'settling',
        'false_calm' => 'quiet',
        'crescendo'  => 'crescendo',
        'closing'    => 'closing',
    ][$stage] ?? $stage;
}

/**
 * Every overlay this world is carrying, shaped for the strip.
 *
 * Reads $w['stories'] rather than the directory: those are the overlays that
 * actually compose for THIS session, so a story rated above what this visitor
 * may be shown is absent here for the same reason it is absent from the world
 * (xeric_story_for). A closed story stays in the list — it closed, it did not
 * stop having happened.
 */
function xeric_play_stories(array $w, ?int $epoch = null): array
{
    $out = [];
    foreach ((array)($w['stories'] ?? []) as $s) {
        if (!is_array($s) || xeric_story_key($s) === '') continue;
        $pr  = xeric_story_progress($s, $w['db'], $epoch);
        $row = [
            'key'     => xeric_story_key($s),
            'title'   => xeric_story_title($s),
            'logline' => (string)($s['logline'] ?? ''),
            'live'    => (bool)$pr['live'],
            'stage'   => xeric_play_stage_word((string)$pr['stage'], (bool)$pr['live']),
            'spilled' => (int)$pr['spilled'],
            'total'   => (int)$pr['total'],
        ];
        // The one string that replaces the logline once it is over, and only
        // then — before that it is the ending, printed early.
        if (!$pr['live']) $row['keeps'] = (string)($s['on_close']['world_keeps'] ?? '');
        $out[] = $row;
    }
    return $out;
}

/**
 * The strip: what is being carried, and roughly where it has got to.
 *
 * Quiet by construction — no accent border, no card — because the time control
 * above it is the thing this page is selling and a mystery banner would win a
 * fight it is not supposed to be in. The one place it stops being quiet is the
 * moment it closes, and that is the point of the whole feature: the story ends,
 * the town does not, and the strip has to say the second half out loud or a
 * closed story reads as a world that broke.
 *
 * The dots are the beats that have been SAID out loud, not the ones that have
 * opened: what a player has heard is a fact they can check against the thread.
 */
function xeric_play_story_html(array $rows, string $world = ''): string
{
    if ($rows === []) return '';
    $world = $world !== '' ? $world : 'This xeric';

    $out = '';
    foreach ($rows as $r) {
        $live = (bool)$r['live'];
        $dots = '';
        for ($i = 0; $i < min(12, (int)$r['total']); $i++) {
            $dots .= '<i' . ($i < (int)$r['spilled'] ? ' class="on"' : '') . '></i>';
        }
        $out .= '<div class="story' . ($live ? '' : ' done') . '">'
            . '<div class="sh"><span class="sk">'
            . ($live ? 'the story this xeric is carrying' : 'the story this xeric was carrying')
            . '</span><span class="sp">' . h((string)$r['stage']) . '</span></div>'
            . '<div class="sn">' . h((string)$r['title']) . '</div>';

        if ($live) {
            if ((string)$r['logline'] !== '') $out .= '<p class="sl">' . h((string)$r['logline']) . '</p>';
            $out .= '<div class="sm" aria-hidden="true">' . $dots . '</div>';
        } else {
            if ((string)($r['keeps'] ?? '') !== '') $out .= '<p class="sl">' . h((string)$r['keeps']) . '</p>';
            $out .= '<p class="sw">It is over, and nothing else is. ' . h($world) . ' is still keeping its own '
                . 'time, everybody is still where they are, and the buttons above still move it.</p>';
        }
        $out .= '</div>';
    }
    return $out;
}

/**
 * What the world itself says at the end of a turn, and therefore what gets
 * WRITTEN DOWN.
 *
 * WHO SAYS THIS. story.php hands `said` back rather than storing it, on the
 * grounds that who says a dead lead's epitaph is a question about the scene —
 * and this is the scene, so it is answered here: nobody in the cast says it. It
 * is the world's own line, appended to the thread in the `narrator` role that
 * state.php has always had and prompt.php has always rendered as "(what
 * happened)". Three reasons that is the right voice rather than putting it in
 * the speaker's mouth:
 *
 *  • The believer is still certain. "It was not kids that night" is exactly what
 *    Ruth does not think, and a herring that dies by its own believer conceding
 *    it is not a herring, it is a quiz answer.
 *  • It is addressed to the player, not to the character. Nobody in the room
 *    said it out loud; the town simply stopped being wrong about it.
 *  • It survives a reload. The single most load-bearing sentence in a mystery
 *    vanishing on a refresh would be a bug, and the transcript is the only place
 *    on this screen that is durable.
 *
 * It costs the tail of the message history and never a byte of the system
 * message, which is what keeps a story from dragging the whole prompt out of
 * cache (story.php's third discipline).
 *
 * @return string[] in the order they land
 */
function xeric_play_story_lines(array $w, array $story): array
{
    $lines = [];
    foreach ((array)($story['said'] ?? []) as $line) {
        $line = trim((string)$line);
        if ($line !== '') $lines[] = $line;
    }

    $world = trim((string)($w['template']['meta']['name'] ?? '')) ?: 'this xeric';
    foreach ((array)($story['resolved'] ?? []) as $r) {
        // Only an ending. `right` without `closed` is somebody who has guessed
        // it and not shown it, and story.php is explicit that this gets no
        // acknowledgment and no wink — a narrator line there would be the wink.
        if (empty($r['closed']) || empty($r['right'])) continue;
        foreach ((array)($w['stories'] ?? []) as $s) {
            if (!is_array($s) || xeric_story_key($s) !== (string)($r['key'] ?? '')) continue;
            $lines[] = 'That is the end of ' . xeric_story_title($s) . '. ' . $world
                . ' is still keeping its own time.';
        }
    }
    return $lines;
}

/**
 * The quiet line: whose world this is, and what this visitor has left.
 *
 * Deliberately one small grey sentence under the header. A visitor should never
 * be surprised by a limit — but they came here to meet somebody, not to read a
 * quota, so this is never louder than the world's own name.
 */
function xeric_play_you_html(array $w, ?string $sid = null): string
{
    $sid  = $sid ?? xeric_session_id();
    $left = xeric_limit_left($sid);

    $whose = !empty($w['mine'])
        ? 'This xeric is <b>yours</b>, you forged it in this browser.'
        : 'This xeric was forged by somebody else, so you are playing <b>your own copy</b> of it. '
          . 'Nothing you do here moves their evening on.';

    // A BUDGET IS ONLY WORTH SAYING WHERE THERE IS ONE. With the caps off — which
    // is every local install — xeric_limit_left() hands back a large number so
    // that callers gating a button do not need to know about a third state, and
    // this printed it: "999999 of 999999 things to say left this hour · One GPU,
    // shared", on somebody's own machine, about their own GPU. A sentence that
    // is nonsense is worse than a sentence that is missing.
    $budget = xeric_limit_on()
        ? ' <span class="budget">' . (int)$left['messages'] . ' of ' . (int)$left['of']['messages']
          . ' things to say left this hour · ' . (int)$left['skips'] . ' of ' . (int)$left['of']['skips']
          . ' skips. One GPU, shared.</span>'
        : '';

    return '<span class="mine">' . $whose . '</span>' . $budget;
}

// ---------------------------------------------------------------------------
// The two blocks that get repainted
// ---------------------------------------------------------------------------

/**
 * The cast list.
 *
 * The dot is the whole point of this block: an unread thread is the only place
 * the world reaches out of the page at somebody. It gets the accent colour and a
 * halo, and nothing else on the row is allowed to compete with it.
 *
 * The inspector and the review step belong to whoever forged this world
 * (xeric_play_guard), so somebody playing a copy is not offered a door that is
 * going to be shut in their face.
 */
function xeric_play_cast_html(array $cast, string $slug = '', bool $mine = true): string
{
    $out = '';
    $tune = $slug !== '' && $mine;
    if ($cast === []) {
        // A world with nobody in it is a real failure with a real fix, and it is
        // not the visitor's fault. Never an empty list and no explanation.
        return '<li><p class="note bad">There is nobody in this world. Every pass that writes people fell '
            . 'through to nothing, which should be impossible, the forge has a hand-written cast behind the '
            . 'model for exactly this. Nothing here can be talked to until somebody is in it.'
            . ($tune ? ' <a href="review.php?w=' . h(rawurlencode($slug)) . '#sec-cast">Reroll the cast →</a>' : '')
            . '</p></li>';
    }
    foreach ($cast as $c) {
        // A dead person is not off shift and must never be printed as though they
        // were: "off shift" is a sentence about a schedule, and they no longer
        // have one. What they get instead is what the town would say, which is
        // what `how` is for — and when nothing was said, the bare fact.
        // Everybody living wears their presence mark instead: the glyph, the
        // title-attribute sentence, and the short line, all decided by
        // xeric_play_presence_mark() so this stays a renderer and not a judge.
        $dead = !empty($c['dead']);
        $mk   = (array)($c['mark'] ?? []) + ['state' => '', 'glyph' => '', 'say' => '', 'pw' => '', 'slow' => false];
        $where = $dead
            ? ((string)$c['how'] !== '' ? h($c['how']) : 'died')
            : (((string)$mk['glyph'] !== ''
                    ? '<span class="pmk" title="' . h((string)$mk['say']) . '">' . $mk['glyph'] . '</span>' : '')
               . h((string)$mk['pw'] !== '' ? (string)$mk['pw'] : 'off shift')
               // The slow morning is a modifier, not a state: it rides beside
               // whatever the chip already says, and its sentence is the whole
               // joke it is allowed to make.
               . (!empty($mk['slow'])
                    ? '<span class="pmk slow" title="a late one last night; slow going this morning">☕</span>' : ''));
        // Everything the chips and the thread need about this person rides on
        // the row as data: the row is the one thing that is always repainted,
        // so a view built FROM it can never be showing yesterday's face.
        $f = (array)($c['face'] ?? ['txt' => '?', 'hue' => 0, 'kind' => 'x']);
        $out .= '<li class="crow"><button type="button" class="person' . ($c['unread'] ? ' lit' : '')
            . ($dead ? ' gone' : '') . (!empty($c['out']) ? ' out' : '') . '" data-h="' . h($c['handle']) . '"'
            . ' data-hue="' . (int)$f['hue'] . '" data-av="' . h((string)$f['txt']) . '"'
            // data-place is the chip bar's word for "pinned out in the world",
            // so the home fallback does not travel through it: a bedroom is not
            // a placement to pin, and blanking it here is what lets the bar's
            // night fallback keep meaning what it says.
            . ' data-place="' . h(empty($c['at_home']) ? (string)$c['where'] : '') . '"'
            . ' data-doing="' . h((string)$c['doing']) . '"'
            // What the chip bar calls them when twelve of them have to fit.
            . ' data-short="' . h((string)($c['short'] ?? '')) . '"'
            // And the mark itself, as data, so the bar can wear the same glyph
            // and say the same sentence without deriving either a second time.
            . ((string)$mk['glyph'] !== ''
                ? ' data-mark="' . h((string)$mk['glyph']) . '" data-say="' . h((string)$mk['say']) . '"' : '')
            . (!empty($mk['slow']) ? ' data-slow="1"' : '')
            . ($dead ? ' data-dead="1"' : '') . '>'
            . '<span class="pdot' . ($c['unread'] ? ' on' : '') . ($dead ? ' x' : '') . '">'
            . ($dead ? '×' : '') . '</span>'
            . '<span class="av" style="--hue:' . (int)$f['hue'] . '" aria-hidden="true">' . h((string)$f['txt']) . '</span>'
            . '<span><span class="pn">' . h($c['name'])
            . ($c['protagonist'] ? '<span class="tag">their story</span>' : '') . '</span>'
            . ((string)$c['one_line'] !== '' ? '<span class="po">' . h($c['one_line']) . '</span>' : '')
            . '<span class="pw' . ($dead ? ' rip'
                : (in_array((string)$mk['state'], ['off', 'absent', ''], true) ? ' off' : '')) . '">' . $where . '</span>'
            . '</span><span class="pgo">›</span></button>'
            // The cog and the inspector, one tap from the person they are
            // about. Neither may live inside the <button>, so they ride beside
            // and underneath it.
            . ($tune
                ? '<button type="button" class="pgear" data-h="' . h($c['handle'])
                  . '" title="Change ' . h($c['name']) . '" aria-label="Change ' . h($c['name']) . '">⚙</button>'
                  . '<a class="whylink" href="why.php?w=' . h(rawurlencode($slug)) . '&amp;h=' . h(rawurlencode($c['handle']))
                  . '">why ' . (($c['pronoun'] ?? 'they') === 'they'
                      ? 'do they say what they say'
                      : 'does ' . h((string)$c['pronoun']) . ' say what ' . h((string)$c['pronoun']) . ' says') . '?</a>'
                : '')
            . '</li>';
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Connected, or not — and what that does to time
// ---------------------------------------------------------------------------
//
// A WORLD WITH NO MACHINE BEHIND IT MUST NOT KEEP RUNNING. That is the whole
// reason these two concerns are in one place. Xeric's pitch is that the world
// lives on wall-clock time whether or not you are watching — but it can only
// live through an hour if something was there to write it. Disconnect the model
// and let the clock run and you come back to a world that "lived" a fortnight
// it had no way to think through: the hours are simply missing, and the world
// says Tuesday the 14th as though they had happened.
//
// So disconnecting STOPS THE CLOCK, in every world this visitor has a copy of,
// and reconnecting starts it again on the second it stopped. The two are one
// action with two halves, which is why nothing here lets you do one without the
// other.

/** Every world database this visitor can move: their forks, and any they forged. */
function xeric_play_my_dbs(?string $sid = null): array
{
    $sid  = $sid ?? xeric_session_id();
    $out  = [];
    foreach (xeric_session_copies($sid) as $slug) {
        $p = xeric_session_db_dir($sid) . '/' . $slug . '.db';
        if (is_file($p)) $out[$slug] = $p;
    }
    foreach (xeric_session_worlds($sid) as $slug) {
        $p = xeric_web_worlds_dir() . '/' . $slug . '/world.db';
        if (is_file($p)) $out[$slug] = $p;
    }
    return $out;
}

/**
 * Stop or start every world this visitor has.
 *
 * Failures are counted, never thrown. A world whose database will not open is a
 * problem, but it is not a reason to leave the other six in a state nobody
 * asked for — and the caller's next screen is going to read the real state back
 * out anyway rather than trust a return value.
 *
 * @return array{done:int,failed:int,away:int} `away` is the longest sleep resumed
 */
function xeric_play_clock_all(bool $pause, ?string $sid = null): array
{
    $done = $failed = $away = 0;
    foreach (xeric_play_my_dbs($sid) as $path) {
        try {
            $db = xeric_state_open($path);
            if ($pause) {
                if (xeric_clock_pause($db)) $done++;
            } else {
                $slept = xeric_clock_resume($db);
                if ($slept > 0 || !xeric_clock_is_paused($db)) $done++;
                $away = max($away, $slept);
            }
            $db = null;
        } catch (Throwable $e) {
            $failed++;
        }
    }
    return ['done' => $done, 'failed' => $failed, 'away' => $away];
}

/**
 * Are all of this visitor's worlds stopped?
 *
 * true  — they have worlds and every one is paused
 * false — at least one is still running
 * null  — they have no worlds, so the question has no answer
 *
 * The null matters. "Every world you have is stopped" is a claim, and a claim
 * about nothing is the kind of sentence that makes somebody go looking for the
 * worlds it is talking about.
 */
function xeric_play_all_stopped(?string $sid = null): ?bool
{
    $dbs = xeric_play_my_dbs($sid);
    if ($dbs === []) return null;

    foreach ($dbs as $path) {
        try {
            $db = xeric_state_open($path);
            $running = !xeric_clock_is_paused($db);
            $db = null;
            if ($running) return false;
        } catch (Throwable $e) {
            // A world that will not open is not a world that is running.
        }
    }
    return true;
}

/**
 * May this visitor point "the local model" somewhere of their own choosing?
 *
 * ONLY FROM THE MACHINE THE SERVER IS ON, and this is a security boundary rather
 * than a preference. `xeric_web_endpoint()` runs every bring-your-own-key URL
 * through xeric_web_host_open(), which refuses private networks — because a
 * hosted install that will fetch any URL a stranger types is a request forgery
 * service with a chat window attached. The LOCAL endpoint deliberately skips
 * that check, since pointing at 127.0.0.1 is the entire point of it.
 *
 * Those two facts together mean an editable local address is safe exactly when
 * the person typing it is the person the server belongs to. Loopback is the
 * cheapest true test of that: on somebody's laptop every request is loopback, on
 * xeric.dev none of them are, and no configuration has to be right for either
 * case to behave correctly. `local_editable` in config.local.php overrides it
 * for a LAN install, where the answer is a judgement nobody else can make.
 */
function xeric_web_local_editable(): bool
{
    $cfg = xeric_web_config();
    if (array_key_exists('local_editable', $cfg)) return (bool)$cfg['local_editable'];

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '127.0.0.1' || $ip === '::1' || $ip === '';   // '' = CLI
}

/** Is a machine attached at all? `none` is a real state, not a missing value. */
function xeric_web_connected(array $model): bool
{
    return (string)($model['kind'] ?? 'none') !== 'none';
}

/**
 * This visitor's machine, resolved. NOTHING IS EVER ATTACHED HERE.
 *
 * This function used to probe on first sight and attach whatever answered,
 * on the argument that clicking a button to state the obvious is friction.
 * The owner overruled it, twice, and the second time named the principle:
 * FOUND IS NOT CONNECTED. Detection is a fact about the machine; connection
 * is a decision by a person, and the moment they learn the difference must
 * not be after the first world is already running on a model a port scan
 * picked. The machines screen shows what is alive (its lamps are filled in by
 * the browser); one press on a lit machine is the whole ceremony.
 *
 *   no `kind` at all  →  never chosen. Nothing attached; forge.php sends the
 *                        visitor to the machines screen, where the choice is.
 *   `kind` = 'none'   →  chosen. Somebody detached on purpose and it stays
 *                        detached, however alive the local model is.
 *
 * Nothing is written back for the never-chosen case — there is no probe left
 * to amortise, and an unwritten record is what keeps "never chosen" visibly
 * different from "detached on purpose" in the session file itself.
 */
function xeric_web_model(?string $sid = null): array
{
    $sid = $sid ?? xeric_session_id();
    $m   = (array)(xeric_web_session_read($sid)['model'] ?? []);
    if (array_key_exists('kind', $m) && trim((string)$m['kind']) !== '') return $m;

    return ['kind' => 'none', 'base' => '', 'local' => '', 'model' => ''];
}

// ---------------------------------------------------------------------------
// The machines — one list, read by every screen that shows one
// ---------------------------------------------------------------------------
//
// WHO ANSWERS, NOT JUST WHETHER. An address and a green lamp say something is
// there; they do not say it is the thing you meant. Every server in this space
// speaks the same OpenAI wire format, so "127.0.0.1:8080" is llama.cpp or Ollama
// or LM Studio or vLLM with equal plausibility — and the failure that costs an
// afternoon is talking confidently to the wrong one, or to the right one with a
// model loaded that nobody meant to load.
//
// NAMED, NEVER LOGOED. What is printed is a name we choose and a model id the
// server volunteered. No third-party marks are shipped: an app that draws
// somebody else's logo is making a claim about a relationship it does not have,
// and it is one more thing to keep current when a company restyles.
//
// EVERY ONE OF THESE IS BEST-EFFORT AND SHORT. Identification is decoration on
// top of the liveness probe; a server that will not say what it is still works
// perfectly, so nothing here may add a second of latency or throw.

/**
 * The ports people actually run a model on, in likelihood order.
 *
 * Shared with xeric_web_probe_local(), which wants the first one open; this list
 * is also walked in full by the scan. A SHORT LIST ON PURPOSE: every entry costs
 * a quarter-second when nothing is listening, and walking everything above 1024
 * to find a model is a port scan of somebody's own machine on every cold start.
 */
function xeric_model_ports(): array
{
    return [
        11434 => 'Ollama',
        8080  => 'llama.cpp',
        1234  => 'LM Studio',
        18080 => 'a tunnel',
        5000  => 'text-generation-webui',
        8000  => 'vLLM',
        4891  => 'GPT4All',
        11435 => 'Ollama',            // a second Ollama, which is how two models get served at once
        8081  => 'llama.cpp',         // and the same for llama-server
        8082  => 'llama.cpp',
    ];
}

/** Is anything listening there at all? The cheap half of the scan. */
function xeric_model_open(int $port, float $wait = 0.25): bool
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, $wait);
    if ($fp === false) return false;
    fclose($fp);
    return true;
}

/**
 * Everything running on this machine that Xeric could talk to, sorted into what
 * writes and what draws.
 *
 * WHY ALL OF THEM AND NOT THE FIRST. Somebody serving a 26B for prose and a 4B
 * for the fast jobs has two servers up, and a scan that stops at the first tells
 * them they have one machine — so the second gets typed in by hand, from memory,
 * including the port. The list is the thing that makes more than one model an
 * ordinary setup rather than an expert one.
 *
 * ONE PASS OVER THE UNION OF PORTS, and the picture test runs FIRST. This was
 * found the hard way on the author's own machine: stable-diffusion.cpp's
 * `sd-server` answers /v1/models like any chat server, so it was listed as a
 * text model called "sd-cpp-local" — an address that would have been attached,
 * chosen to forge with, and failed three minutes later. Whatever is more
 * specific decides, and /sdapi/v1/sd-models is more specific than being
 * OpenAI-shaped.
 *
 * @param array $have addresses already on the visitor's list, which are skipped
 * @return array{models:array<int,array{base:string,who:array}>,art:array<int,array{base:string,who:array}>}
 */
function xeric_model_scan(array $have = []): array
{
    $seen = [];
    foreach ($have as $b) $seen[rtrim((string)$b, '/')] = true;

    $ports = array_values(array_unique(array_merge(
        array_keys(xeric_model_ports()), xeric_model_art_ports()
    )));
    sort($ports);

    $out = ['models' => [], 'art' => []];
    foreach ($ports as $port) {
        if (!xeric_model_open((int)$port)) continue;
        $base = 'http://127.0.0.1:' . $port;

        // Something is LISTENING, which is not the same as something answering.
        // A dev server, a database, a printer — anything can hold a port, and a
        // list that offered them all as models would be worse than no list.
        $who = xeric_model_who($base, 'local');
        if ($who['name'] === '') continue;

        if (!empty($who['art'])) { $out['art'][] = ['base' => $base, 'who' => $who]; continue; }
        if (isset($seen[$base])) continue;              // already on the list, nothing to offer
        $out['models'][] = ['base' => $base, 'who' => $who];
        $seen[$base] = true;
    }

    // Biggest first, so the list reads in the order somebody would choose from
    // it and xeric_model_best() is simply the top of what is on screen.
    usort($out['models'], fn(array $a, array $b): int =>
        xeric_model_size((string)($b['who']['model'] ?? ''))
        <=> xeric_model_size((string)($a['who']['model'] ?? '')));
    return $out;
}

// ---------------------------------------------------------------------------
// Pictures — found and named, not yet wired to anything
// ---------------------------------------------------------------------------
//
// NOTHING IN THE ENGINE DRAWS ANYTHING YET. This is here because the machines
// screen is where somebody sets up what Xeric can reach, and an image server is
// the same kind of thing as a model server: an address on your own machine that
// something answers at. Finding them now means the day the engine can use one,
// the setup already exists — and it means the screen can be honest that it found
// something rather than silently ignoring it.
//
// The tiles on the shelf are the obvious first customer (they are drawn from a
// seeded palette today), then a face for a character, then a place.

/** Where the picture servers live. */
function xeric_model_art_ports(): array
{
    return [7860, 8188, 9090, 7801, 3000, 5001];
}

/** What kind of picture server is at this address, if any. */
function xeric_model_art_who(string $base): array
{
    $base = rtrim($base, '/');

    // ComfyUI names its own hardware, which is the most useful line on the card.
    $s = xeric_model_peek($base . '/system_stats');
    if ($s !== null && isset($s['devices'])) {
        $d = (string)($s['devices'][0]['name'] ?? '');
        return ['name' => 'ComfyUI', 'model' => xeric_model_trim($d)];
    }

    // Automatic1111 and everything that copied its API: SD.Next, Forge, reForge.
    $m = xeric_model_peek($base . '/sdapi/v1/sd-models');
    if ($m !== null && isset($m[0]['title'])) {
        return ['name' => 'Stable Diffusion (A1111 API)',
                'model' => xeric_model_trim((string)($m[0]['model_name'] ?? $m[0]['title']))];
    }

    $v = xeric_model_peek($base . '/api/v1/app/version');
    if ($v !== null && isset($v['version'])) {
        return ['name' => 'InvokeAI ' . xeric_model_trim((string)$v['version']), 'model' => ''];
    }

    return ['name' => '', 'model' => ''];
}

/** The API behind a public hostname, or '' when it is not one we know. */
function xeric_model_vendor(string $host): string
{
    $host = strtolower($host);
    // Longest-suffix wins, so api.moonshot.cn and moonshot.cn agree.
    $known = [
        'anthropic.com' => 'Claude', 'openai.com' => 'OpenAI', 'azure.com' => 'Azure OpenAI',
        'moonshot.cn' => 'Kimi', 'moonshot.ai' => 'Kimi', 'deepseek.com' => 'DeepSeek',
        'mistral.ai' => 'Mistral', 'groq.com' => 'Groq', 'together.xyz' => 'Together',
        'together.ai' => 'Together', 'openrouter.ai' => 'OpenRouter', 'x.ai' => 'xAI',
        'googleapis.com' => 'Gemini', 'cohere.ai' => 'Cohere', 'cohere.com' => 'Cohere',
        'perplexity.ai' => 'Perplexity', 'fireworks.ai' => 'Fireworks', 'deepinfra.com' => 'DeepInfra',
        'anyscale.com' => 'Anyscale', 'hyperbolic.xyz' => 'Hyperbolic', 'lambdalabs.com' => 'Lambda',
        'novita.ai' => 'Novita', 'cerebras.ai' => 'Cerebras', 'sambanova.ai' => 'SambaNova',
        'nvidia.com' => 'NVIDIA NIM', 'z.ai' => 'Z.ai', 'bigmodel.cn' => 'Zhipu',
        'qwen.ai' => 'Qwen', 'aliyuncs.com' => 'Qwen', 'minimax.chat' => 'MiniMax',
        'baseten.co' => 'Baseten', 'replicate.com' => 'Replicate', 'nebius.com' => 'Nebius',
        'inference.net' => 'Inference.net', 'featherless.ai' => 'Featherless',
    ];
    $best = '';
    foreach ($known as $suffix => $name) {
        if (($host === $suffix || str_ends_with($host, '.' . $suffix))
            && strlen($suffix) > strlen($best === '' ? '' : $best)) {
            $best = $suffix;
        }
    }
    return $best === '' ? '' : $known[$best];
}

/** One short GET, decoded, or null. Never throws, never waits long. */
function xeric_model_peek(string $url, int $timeout = 2): ?array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => "Accept: application/json\r\n",
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 1,
    ]]);
    $out = @file_get_contents($url, false, $ctx);
    if (!is_string($out) || $out === '') return null;
    $j = json_decode($out, true);
    return is_array($j) ? $j : null;
}

/**
 * Does this model id belong to something that makes PICTURES rather than words?
 *
 * A NAME TEST, BEHIND THE ENDPOINT TESTS AND NOT INSTEAD OF THEM. Endpoint
 * fingerprints are the real answer; this catches the case where a picture or
 * video server exposes nothing but the OpenAI shape and its own model id —
 * which is exactly how stable-diffusion.cpp presents itself, and how a video
 * server dressed as an inference endpoint would present itself too.
 *
 * Wrong in the safe direction: a chat model that trips this is skipped when
 * choosing a default, which costs one click. A video server that does NOT trip
 * it gets attached, and every world stops answering.
 */
function xeric_model_art_id(string $id): bool
{
    $s = strtolower($id);
    foreach (['stable-diffusion', 'stablediffusion', 'sd-cpp', 'sd3', 'sdxl', 'sd15', 'sd-turbo',
              'flux', 'wan2', 'wan-', 'wanx', 'svd', 'animatediff', 'cogvideo', 'hunyuan-video',
              'hunyuanvideo', 'ltx-video', 'ltxvideo', 'mochi', 'zeroscope', 'modelscope',
              'dreamshaper', 'juggernaut', 'realvis', 'pony', 'illustrious', 'noobai',
              'comfy', 'vae', 'controlnet', 'inpaint', 'img2img', 'txt2img', 'text2video',
              'image2video', 'i2v', 't2v', 'upscal', 'esrgan', 'lora'] as $mark) {
        if (str_contains($s, $mark)) return true;
    }
    // "…-diffusion" and bare "diffusion" checkpoints, without catching a chat
    // model that merely mentions it in a fine-tune name.
    return (bool)preg_match('/(^|[^a-z])diffusion([^a-z]|$)/', $s);
}

/**
 * How big is this model, in billions of parameters, read off its name.
 *
 * THE ONLY SIGNAL ANY OF THESE SERVERS ACTUALLY GIVES. None of them report a
 * parameter count; every one of them puts it in the file name, because that is
 * how the files are published. "gemma-4-26B-A4B" is a 26B mixture with 4B
 * active — the TOTAL is what makes it the better writer, so the largest number
 * in the name wins.
 *
 * Returns 0.0 when the name says nothing, which sorts below anything that does
 * without ever beating it.
 */
function xeric_model_size(string $id): float
{
    $s = strtolower($id);

    // "8x7b" is a mixture of eight 7B experts and weighs 56B on the card and in
    // the GPU. Read as one number it is a 7B, which sorts a Mixtral below a
    // 26B it should beat.
    if (preg_match('/(\d+)\s*x\s*(\d+(?:\.\d+)?)\s*b(?![a-z])/', $s, $x)) {
        $total = (float)$x[1] * (float)$x[2];
        if ($total > 0 && $total < 2000) return $total;
    }

    if (!preg_match_all('/(\d+(?:\.\d+)?)\s*b(?![a-z])/i', $s, $m)) return 0.0;

    $best = 0.0;
    foreach ($m[1] as $n) {
        $v = (float)$n;
        if ($v > 0 && $v < 2000 && $v > $best) $best = $v;   // 2000B is past any real model
    }
    return $best;
}

/**
 * The best local model to start with: the biggest one that actually writes.
 *
 * WHY BIGGEST AND WHY NOT THE FIRST OPEN PORT. A person running several servers
 * is usually running one to write with and the others for jobs it would be
 * wasteful to spend it on — and the first port to answer is decided by the port
 * NUMBER, which has nothing to do with any of that. Worse, it put a picture
 * server ahead of a 26B on this machine, purely because 8081 sorts after 8080.
 *
 * @param array $scan the result of xeric_model_scan(), or null to run one
 */
function xeric_model_best(?array $scan = null): string
{
    $scan = $scan ?? xeric_model_scan();
    // The scan already sorts biggest-first, so this is the top of the list a
    // person is looking at — one ordering, not two that can disagree.
    $rows = (array)($scan['models'] ?? []);
    return $rows === [] ? '' : (string)$rows[0]['base'];
}

/** A model id as a person would read it: the file name, not the path to it. */
function xeric_model_trim(string $id): string
{
    $id = trim($id);
    if ($id === '') return '';
    if (str_contains($id, '/') && !preg_match('#^[\w.-]+/[\w.-]+$#', $id)) {
        $id = (string)substr($id, (int)strrpos($id, '/') + 1);   // a path, not an org/model handle
    }
    $id = (string)preg_replace('/\.(gguf|safetensors|bin)$/i', '', $id);
    return mb_substr($id, 0, 48);
}

/**
 * What is at this address: a name for it, and the model it says it has loaded.
 *
 * @return array{name:string,model:string,local:bool}
 */
function xeric_model_who(string $base, string $kind = ''): array
{
    $base = rtrim(trim($base), '/');
    $kind = $kind !== '' ? $kind : xeric_model_kind($base);
    $host = strtolower((string)(parse_url($base, PHP_URL_HOST) ?? ''));

    // SOMEBODY ELSE'S API IS IDENTIFIED WITHOUT ASKING IT ANYTHING. The key is
    // never stored here, so every request this app could make unauthenticated
    // would come back 401 — and a 401 tells you nothing except that the URL was
    // spelled right. The hostname already says whose it is.
    if ($kind !== 'local') {
        return ['name' => xeric_model_vendor($host) ?: 'OpenAI-compatible API',
                'model' => '', 'local' => false];
    }

    // WHAT IT IS AND WHAT IT HAS LOADED ARE TWO QUESTIONS, and the second one
    // decides. A video server run behind llama.cpp answers /props like any
    // llama.cpp — because it IS one — so a branch that returned as soon as it
    // recognised the SERVER filed wan2.2-i2v-a14b as a 14B chat model, which is
    // the whole failure this function exists to prevent. Every branch now falls
    // through to the same last line, and the last line looks at the checkpoint.
    $name = '';
    $model = '';
    $art = false;

    $v = xeric_model_peek($base . '/api/version');
    if ($v !== null && isset($v['version'])) {
        $name = trim('Ollama ' . xeric_model_trim((string)$v['version']));
        $tags = xeric_model_peek($base . '/api/tags');
        $model = xeric_model_trim((string)((array)($tags['models'][0] ?? []))['name'] ?? '');
    }

    if ($name === '') {
        $p = xeric_model_peek($base . '/props');
        if ($p !== null && (isset($p['default_generation_settings']) || isset($p['model_path']))) {
            $name  = 'llama.cpp';
            $model = xeric_model_trim((string)($p['model_path'] ?? ''));
            if ($model === '') $model = xeric_model_trim((string)($p['default_generation_settings']['model'] ?? ''));
        }
    }

    if ($name === '') {
        $l = xeric_model_peek($base . '/api/v0/models');
        if ($l !== null && isset($l['data'][0]['type'])) {
            $name  = 'LM Studio';
            $model = xeric_model_trim((string)($l['data'][0]['id'] ?? ''));
        }
    }

    if ($name === '') {
        $k = xeric_model_peek($base . '/api/extra/version');
        if ($k !== null && isset($k['result'])) $name = 'KoboldCpp';
    }

    if ($name === '') {
        $vl = xeric_model_peek($base . '/version');
        $models = xeric_model_peek($base . '/v1/models');
        $first  = xeric_model_trim((string)($models['data'][0]['id'] ?? ''));
        if ($vl !== null && isset($vl['version'])) { $name = 'vLLM'; $model = $first; }
        elseif ($models !== null)                  { $name = 'OpenAI-compatible'; $model = $first; }
    }

    // THE ENDPOINT TEST, which is the strong one: a server that answers
    // /sdapi/v1/sd-models or /system_stats is a picture server whatever else it
    // said about itself.
    $pic = xeric_model_art_who($base);
    if ($pic['name'] !== '') {
        $art  = true;
        $name = $name === '' || $name === 'OpenAI-compatible' ? $pic['name'] : $name;
        // Its own API names the CHECKPOINT; /v1/models on the same server tends
        // to name the service ("sd-cpp-local"). The checkpoint is the useful one
        // — it is what somebody chose and what they would recognise.
        if ($pic['model'] !== '') $model = $pic['model'];
    }

    // AND THE NAME TEST, which catches everything that presents as an ordinary
    // inference server and is holding a checkpoint that draws.
    if (!$art && $model !== '' && xeric_model_art_id($model)) {
        $art = true;
        if ($name === '') $name = 'Pictures or video';
    }

    return ['name' => $name, 'model' => $model, 'local' => true, 'art' => $art];
}

// These lived in model.php until the forge needed the same list, to let somebody
// pick which machine forges. Two screens rendering "your machines" from two
// definitions is the exact disagreement model.php was built to end, so the
// definition moved here and both screens ask it.

/** Every machine this visitor knows about. Row 0 is the install's own. */
function xeric_model_list(?string $sid = null): array
{
    $sess = xeric_web_session_read($sid ?? xeric_session_id());
    // Row 0 is this install's address — a STARTING POINT, not a constant. Once
    // somebody has retyped it, theirs is the one that counts; the config value
    // is what a fresh visitor is handed.
    $own  = rtrim(trim((string)($sess['local_base'] ?? '')), '/');
    if ($own === '') $own = rtrim((string)xeric_web_config()['local_base'], '/');

    // ROW 0 REFUSES TO BE AN IMAGING SERVER. It is the one row that cannot be
    // forgotten, so an address that lands here wrong is stuck at the top of the
    // list — and one did: a probe from before the ranking existed picked 8081,
    // stable-diffusion.cpp, and wrote it here. From then on the first machine
    // the screen offered, on every visit, was a thing that draws pictures.
    //
    // Checked rather than trusted, and it is nearly free: identification of a
    // remote address makes no request at all, and a local one is four short
    // calls to loopback that a wrong answer here would cost somebody an
    // afternoon of.
    if ($own !== '' && !empty(xeric_model_who($own)['art'])) {
        $better = xeric_model_best();
        // Only if there is somewhere better to point. With nothing else running,
        // the wrong address stays visible and correctable rather than being
        // swapped for a guess.
        if ($better !== '') $own = $better;
    }

    $out  = [['base' => $own, 'fixed' => true]];
    foreach ((array)($sess['machines'] ?? []) as $m) {
        $b = rtrim(trim((string)($m['base'] ?? '')), '/');
        // NEVER THE SAME ADDRESS TWICE. Row 0 can move — it self-corrects off an
        // imaging server, and it is editable — so it can land on an address the
        // visitor had also added by hand. Two cards for one machine means two
        // meters counting the same tokens and two lamps that can disagree.
        if ($b !== '' && $b !== $own) $out[] = ['base' => $b, 'fixed' => false];
    }
    return $out;
}

/** What a machine is, in the words the card prints. */
function xeric_model_says(string $kind): string
{
    return $kind === 'local'
        ? 'Local AI (all data stored and processed locally)'
        : 'External Provider';
}

/**
 * Read off the host, so nobody has to be asked.
 *
 * SYNTACTIC, NOT RESOLVED. This used to ask xeric_web_host_open(), which does a
 * live DNS lookup — so api.openai.com was labelled "a machine of your own" and
 * probed keylessly on any afternoon the resolver was slow, and reported red for
 * being down when it was neither down nor local. What kind of address something
 * IS does not depend on the weather.
 *
 * The real guard still resolves: xeric_web_endpoint() refuses a private host for
 * any remote kind, and model.php refuses to STORE one unless the request came
 * from the machine the server is on. This decides a label and a probe strategy.
 */
function xeric_model_kind(string $base): string
{
    $host = strtolower((string)(parse_url($base, PHP_URL_HOST) ?? ''));
    if ($host === '') return 'local';
    if (str_ends_with($host, 'anthropic.com')) return 'anthropic';

    if ($host === 'localhost' || str_ends_with($host, '.local')
        || str_ends_with($host, '.localhost') || !str_contains($host, '.')) {
        return 'local';
    }
    // A literal address is private or it is not; a NAME is somebody else's.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return filter_var($host, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false ? 'local' : 'openai';
    }
    return 'openai';
}

/**
 * Which row is attached, or -1.
 *
 * Matched on the ADDRESS rather than on a stored index, so forgetting a machine
 * cannot silently slide the selection onto a different one.
 */
function xeric_model_active(array $list, ?string $sid = null): int
{
    $m = xeric_web_model($sid);
    if (!xeric_web_connected($m)) return -1;

    $at = rtrim(trim((string)($m['local'] ?? '')), '/');
    if ($at === '') $at = rtrim(trim((string)($m['base'] ?? '')), '/');
    if ($at === '') $at = rtrim((string)xeric_web_config()['local_base'], '/');

    foreach ($list as $i => $row) if ($row['base'] === $at) return $i;
    return -1;
}

// ---------------------------------------------------------------------------
// Connected is a SET; one of them is the engine
// ---------------------------------------------------------------------------
//
// It used to be one attachment, so choosing a machine meant un-choosing the last
// one — and the second machine somebody sets up is usually not the one they want
// running their worlds. It is the big one they will forge with on Sunday, or the
// small fast one they are trying out. Making that choice cost them the first
// machine turned "I have two models" into a toggle.
//
// So: any number CONNECTED, exactly one of them the ENGINE.
//
//   engine     runs your worlds — every chat turn, every hour the heart lives,
//              every proactive ping. There is one because a world has one voice,
//              and because the prompt cache is per-model: spreading
//              a world's turns across machines multiplies its cold prefixes.
//   connected  set up, reachable, and available to forge with. Costs nothing
//              until it is used, which is the whole point of setting it up now.
//
// The engine is still stored as `model` — the single descriptor every other
// screen and the daemon already read — so nothing downstream learns a new shape.
// `wired` is the set around it.

/** Every machine this visitor has connected, engine included. */
function xeric_model_wired(?string $sid = null): array
{
    $sid = $sid ?? xeric_session_id();
    $s   = xeric_web_session_read($sid);

    $out = [];
    foreach ((array)($s['wired'] ?? []) as $b) {
        $b = rtrim(trim((string)$b), '/');
        if ($b !== '' && !in_array($b, $out, true)) $out[] = $b;
    }

    // THE ENGINE IS CONNECTED WHETHER OR NOT THE SET SAYS SO. A session written
    // before `wired` existed has an engine and no set, and a screen that read
    // the set alone would show the machine running every world as "not
    // connected" — with a Connect button under it.
    $m = xeric_web_model($sid);
    if (xeric_web_connected($m)) {   // engine, whatever it is — it is still connected
        $at = rtrim(trim((string)($m['local'] ?? '')), '/');
        if ($at === '') $at = rtrim(trim((string)($m['base'] ?? '')), '/');
        if ($at === '') $at = rtrim((string)xeric_web_config()['local_base'], '/');
        if ($at !== '' && !in_array($at, $out, true)) array_unshift($out, $at);
    }
    return $out;
}

/** Which rows of a machine list are connected. */
function xeric_model_wired_at(array $list, ?string $sid = null): array
{
    $on = xeric_model_wired($sid);
    $out = [];
    foreach ($list as $i => $row) if (in_array((string)$row['base'], $on, true)) $out[] = $i;
    return $out;
}

/**
 * May the app put this machine in the engine slot BY ITSELF?
 *
 * ANYTHING CAN BE THE ENGINE IF SOMEBODY SAYS SO. Nothing here is a ban: an API
 * is a perfectly reasonable thing to run your worlds on, and picking one is a
 * choice this screen offers.
 *
 * WHAT IT MAY NEVER BE IS AUTOMATIC. The engine is not a slot that is merely
 * filled — it is the thing every world calls, every hour the heart lives, and
 * every ping at three in the morning while nobody is watching. Sliding that onto
 * a metered API because it happened to be the next connected machine in a list
 * turns "I disconnected my local model" into a bill. The local ones are free and
 * already on the desk; those are the only ones this app promotes on its own.
 *
 * Anything else waits to be chosen, deliberately, by somebody who knows what it
 * costs per token.
 */
function xeric_model_auto_engine(string $base): bool
{
    return xeric_model_kind($base) === 'local';
}

/** The descriptor a machine gets when it becomes the engine. */
function xeric_model_descriptor(string $base): array
{
    return xeric_model_kind($base) === 'local'
        ? ['kind' => 'local', 'base' => '', 'local' => $base, 'model' => '']
        : ['kind' => xeric_model_kind($base), 'base' => $base, 'local' => '', 'model' => ''];
}

// ---------------------------------------------------------------------------
// The shelf — the first screen, and the only one with no words on it
// ---------------------------------------------------------------------------
//
// The Wii menu is the reference, and not for the look. It never explained
// itself: no copy, no onboarding, no welcome. A grid of tiles, each one a PLACE
// YOU CAN GO, and the only affordance is that they are clickable. It works
// because the tile carries meaning that a sentence would dilute — and it is
// right here for a reason beyond taste, which is that a Xeric world IS a
// channel. Self-contained, running whether or not anybody is watching, resumed
// rather than started.
//
// So: nothing on this screen describes what this screen is. The wordmark, the
// tiles, and one link in the corner for the thing a person cannot discover by
// clicking (which model does the thinking). Everything the old shelf said out
// loud — "Somewhere to be", the lead, the note about copies, the per-world
// metadata line — is gone. If a visitor cannot tell what to do with a grid of
// pictures, more words were never going to be the fix.

/**
 * How long until this xeric lives another hour, and how many it has lived.
 *
 * THE SHELF HAS TO EXPLAIN ITS OWN SILENCE. A sweep happens once per window of
 * world time, so a perfectly healthy xeric does nothing at all for fifty-odd
 * minutes — and a token counter that has not moved since lunch looks exactly
 * like one that is broken. The lamp says it is alive; this says when it will
 * next prove it.
 *
 * @return array{due:?int,lived:int} seconds until the next window, and windows swept
 */
function xeric_play_next_hour(string $slug): array
{
    $dir = xeric_web_worlds_dir() . '/' . $slug;
    $t   = xeric_world_load($dir . '/world-template.json');
    $db  = xeric_state_open($dir . '/world.db');

    $size = (int)($t['events']['window_seconds'] ?? XERIC_SWEEP_WINDOW);
    if ($size < 1) $size = XERIC_SWEEP_WINDOW;

    $now  = xeric_clock_now($db, $t);
    $here = intdiv((int)$now['epoch'], $size);

    $pre  = 'sweep:' . $size . ':';
    $last = 0;
    $lived = 0;
    foreach (xeric_world_state_all($db) as $k => $_) {
        if (!str_starts_with((string)$k, $pre)) continue;
        $lived++;
        $n = (int)substr((string)$k, strlen($pre));
        if ($n > $last) $last = $n;
    }

    // Due now if a window has passed unswept; otherwise the seconds until the
    // one it is standing in runs out.
    $due = ($here > $last) ? 0 : (($here + 1) * $size - (int)$now['epoch']);
    return ['due' => max(0, $due), 'lived' => $lived];
}

/**
 * Is this world stopped? Answered without opening it, where that is possible.
 *
 * A stopped world is stopped because its visitor detached the model, and that is
 * a fact about the VISITOR — so the session answers for all of them at once and
 * the shelf draws eight tiles without eight database connections. A world can
 * still be paused on its own (a worker, a repair, a future per-world button), so
 * this is a fast path and not the truth: xeric_clock_is_paused() is the truth,
 * and the world screen asks it properly.
 */
function xeric_play_paused_quick(string $slug, ?string $sid = null): bool
{
    static $off = null;
    if ($off === null) {
        $off = !xeric_web_connected(xeric_web_model($sid));
    }
    return $off;
}

/** Where a world's cover art would live, if it has any. */
function xeric_play_tile_file(string $slug): ?string
{
    foreach (['tile.webp', 'tile.jpg', 'tile.png'] as $name) {
        $p = xeric_web_worlds_dir() . '/' . $slug . '/' . $name;
        if (is_file($p)) return $p;
    }
    return null;
}

/**
 * What this world's cover art is a picture OF.
 *
 * READ FROM THE WORLD, NOT INVENTED. The first version of this fallback was a
 * seeded colour field, and the note it got back was the right one: Blackwater
 * Creek came out as a blue circle on a green ground, and Blackwater Creek is a
 * river town under a ridge with a steeple in it. A template already says all of
 * that — `setting.locale` in a sentence, and `places[].kind` as a list of the
 * buildings that exist — so the art can be a picture of the place instead of a
 * texture that happens to be near it.
 *
 * Deliberately crude in what it reads: keywords out of the locale and a lookup
 * over place kinds. It is choosing between six silhouettes, not parsing English,
 * and a world it reads wrongly still gets a landscape rather than a failure.
 *
 * @return array{terrain:string,water:string,landmark:string,hue:int,dusk:bool}
 */
function xeric_play_scene(array $t): array
{
    $locale = strtolower(xeric_text($t['setting']['locale'] ?? '') . ' '
                       . xeric_text($t['meta']['description'] ?? ''));
    $has = fn(string ...$words): bool => (bool)array_filter($words, fn($w) => str_contains($locale, $w));

    // City wins over coast on purpose: a coastal megalopolis is a skyline with
    // water in front of it, and terrain decides the skyline while `water` below
    // decides the water. They are two questions and this is the one that is not
    // about the sea.
    $terrain = match (true) {
        $has('city', 'megalopolis', 'metro', 'downtown', 'district')  => 'city',
        $has('mountain', 'cascade', 'ridge', 'peak', 'alpine')        => 'mountain',
        $has('valley', 'hollow', 'creek', 'appalach')                 => 'valley',
        $has('coast', 'harbour', 'harbor', 'fishing', 'tide', 'bay')  => 'coast',
        $has('desert', 'dry', 'mesa', 'plain', 'prairie')             => 'flat',
        default                                                       => 'valley',
    };
    $water = match (true) {
        $has('coast', 'harbour', 'harbor', 'tide', 'sea', 'ocean', 'fishing', 'bay') => 'sea',
        $has('river', 'creek', 'landing', 'basin')                                   => 'river',
        default                                                                      => '',
    };

    // The landmark is whichever building this world has that most says where you
    // are, in a fixed order of legibility at 200 pixels: you can read a steeple
    // and a headframe at that size, and you cannot read a post office.
    $kinds = [];
    foreach ((array)($t['places'] ?? []) as $p) $kinds[strtolower((string)($p['kind'] ?? ''))] = true;
    $landmark = 'roofs';
    foreach (['site' => 'headframe', 'church' => 'steeple', 'station' => 'tower',
              'market' => 'silo', 'school' => 'roofs', 'hall' => 'roofs'] as $kind => $mark) {
        if (isset($kinds[$kind])) { $landmark = $mark; break; }
    }
    if ($terrain === 'city') $landmark = 'towers';

    $h = crc32((string)($t['meta']['name'] ?? '') . '|' . $locale);
    return [
        'terrain'  => $terrain,
        'water'    => $water,
        'landmark' => $landmark,
        'hue'      => 8 + ($h % 200),
        // Dusk on anything that called itself dying, decaying or fog-drenched.
        'dusk'     => $has('decay', 'dying', 'rust', 'fog', 'abandon', 'secluded'),
    ];
}

/**
 * A tile's face, as a small landscape.
 *
 * SVG rather than an image or a gradient, for three reasons that all matter:
 * it is sharp at any tile size, it costs no request, and it can be BUILT FROM
 * THE WORLD rather than picked from a set. Silhouettes only — no detail
 * survives at 200 pixels, and atmospheric perspective (each layer darker and
 * more saturated than the one behind it) is what makes four flat shapes read as
 * distance.
 *
 * Deterministic: the same world draws the same ridge forever. Seeded from the
 * slug so two worlds with the same locale still get different hills.
 */
function xeric_play_tile_svg(string $slug, array $scene): string
{
    // A tiny LCG, because mt_rand() would make the shelf different on every
    // reload and a world's own picture is not allowed to move.
    $seed = crc32($slug);
    $rnd = function (int $lo, int $hi) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
        return $lo + (int)($seed % max(1, $hi - $lo + 1));
    };

    // SKY AND LAND ARE NOT THE SAME HUE ROTATED, and the first attempt at this
    // made them exactly that — which produced pink skies over green hills and
    // read, correctly, as a bug rather than as an evening. Land gets the world's
    // hue folded into the band that ground actually comes in (olive through
    // green through the blue-green of distance); sky gets warm light or cool
    // light and nothing else. A landscape is two palettes meeting at a line, not
    // one palette applied twice.
    $dusk = (bool)$scene['dusk'];
    $hue  = 74 + ((int)$scene['hue'] % 126);          // 74-200: olive -> green -> teal

    $sky = $dusk
        ? [26 + ((int)$scene['hue'] % 18), 46, 78]     // low amber, a shade per world
        : [200 + ((int)$scene['hue'] % 16), 26, 88];   // pale, cool, high

    // Each layer forward: a little more saturated, a lot darker, and pulled a
    // few degrees toward blue - which is atmospheric perspective, and the only
    // reason four flat polygons read as distance.
    $tone = fn(int $step): string => 'hsl(' . max(70, $hue - $step * 5) . ' '
        . min(52, 20 + $step * 8) . '% ' . max(11, ($dusk ? 50 : 58) - $step * 10) . '%)';

    $W = 400; $H = 300;
    // Where the land meets the sky. A coast sits low so there is water to see;
    // mountains sit high so there is something to look up at.
    $skyline = match ($scene['terrain']) {
        'mountain' => 118, 'city' => 176, 'coast' => 186, 'flat' => 205, default => 152,
    };

    // -- the ridges ---------------------------------------------------------
    $ridge = function (int $baseY, int $amp, int $steps) use ($rnd, $W, $H): string {
        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {
            $x = (int)round($i * $W / $steps);
            $pts[] = $x . ',' . max(0, $baseY + $rnd(-$amp, $amp));
        }
        return implode(' ', $pts) . " $W,$H 0,$H";
    };

    $layers = '';
    $far = match ($scene['terrain']) {
        'mountain' => [$skyline, 46, 5],
        'valley'   => [$skyline, 26, 6],
        'coast'    => [$skyline, 10, 7],
        'city'     => [$skyline, 8, 9],
        default    => [$skyline, 7, 8],
    };
    $layers .= '<polygon points="' . $ridge($far[0], $far[1], $far[2]) . '" fill="' . $tone(1) . '"/>';
    $layers .= '<polygon points="' . $ridge($far[0] + 34, (int)max(6, $far[1] * 0.55), $far[2] + 2)
             . '" fill="' . $tone(2) . '"/>';

    // -- the landmark, on the near ridge ------------------------------------
    $lx = $rnd(90, 300);
    $ly = $far[0] + 30;
    $ink = $tone(4);
    $mark = match ($scene['landmark']) {
        'steeple'   => '<rect x="' . ($lx - 9) . '" y="' . ($ly - 26) . '" width="18" height="26" fill="' . $ink . '"/>'
                     . '<polygon points="' . $lx . ',' . ($ly - 52) . ' ' . ($lx - 9) . ',' . ($ly - 26)
                     . ' ' . ($lx + 9) . ',' . ($ly - 26) . '" fill="' . $ink . '"/>',
        'headframe' => '<polygon points="' . $lx . ',' . ($ly - 54) . ' ' . ($lx - 17) . ',' . $ly
                     . ' ' . ($lx - 9) . ',' . $ly . ' ' . $lx . ',' . ($ly - 40) . ' ' . ($lx + 9) . ',' . $ly
                     . ' ' . ($lx + 17) . ',' . $ly . '" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx - 22) . '" y="' . ($ly - 58) . '" width="44" height="5" fill="' . $ink . '"/>',
        'tower'     => '<rect x="' . ($lx - 5) . '" y="' . ($ly - 46) . '" width="10" height="46" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx - 16) . '" y="' . ($ly - 58) . '" width="32" height="16" rx="4" fill="' . $ink . '"/>',
        'silo'      => '<rect x="' . ($lx - 11) . '" y="' . ($ly - 44) . '" width="22" height="44" rx="11" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx + 14) . '" y="' . ($ly - 22) . '" width="26" height="22" fill="' . $ink . '"/>',
        'towers'    => '<rect x="' . ($lx - 30) . '" y="' . ($ly - 74) . '" width="20" height="74" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx - 6) . '" y="' . ($ly - 52) . '" width="16" height="52" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx + 14) . '" y="' . ($ly - 92) . '" width="14" height="92" fill="' . $ink . '"/>',
        default     => '',
    };

    // A handful of roofs beside it, so the landmark is in a town rather than
    // alone in a field. Never more than five — this is a hamlet, not a skyline.
    $town = '';
    for ($i = 0, $n = $rnd(3, 5); $i < $n; $i++) {
        $bx = $rnd(20, $W - 40);
        $bw = $rnd(18, 34);
        $bh = $rnd(10, 20);
        $town .= '<polygon points="' . $bx . ',' . $ly . ' ' . $bx . ',' . ($ly - $bh)
               . ' ' . ($bx + $bw / 2) . ',' . ($ly - $bh - 7) . ' ' . ($bx + $bw) . ',' . ($ly - $bh)
               . ' ' . ($bx + $bw) . ',' . $ly . '" fill="' . $tone(3) . '"/>';
    }

    // -- water, in front of everything --------------------------------------
    $wy = $scene['water'] === 'sea' ? 214 : 236;
    // WATER IS THE SKY LYING DOWN. Filling it from the land palette is what made
    // the first pass read as a dark strip of nothing: a river is bright because
    // it is reflecting the one light source in the picture.
    // Bright, not dark. Dropping 34 points of lightness off the sky gave a brown
    // strip that read as dirt; water is one of the LIGHTEST things in a dusk
    // landscape, because it is the only surface pointed at the sky.
    $wet   = 'hsl(' . $sky[0] . ' ' . max(14, $sky[1] - 14) . '% ' . max(54, $sky[2] - 14) . '%)';
    $glint = 'hsl(' . $sky[0] . ' ' . $sky[1] . '% ' . min(92, $sky[2] + 6) . '%)';
    $water = $scene['water'] === ''
        ? '<polygon points="' . $ridge(248, 9, 5) . '" fill="' . $tone(5) . '"/>'
        // A dark bank line where the water starts, so it reads as a far edge
        // rather than as the picture running out.
        : '<rect x="0" y="' . ($wy - 4) . '" width="' . $W . '" height="5" fill="' . $tone(4) . '"/>'
          . '<rect x="0" y="' . $wy . '" width="' . $W . '" height="' . ($H - $wy) . '" fill="' . $wet . '"/>'
          . '<rect x="0" y="' . ($wy + 10) . '" width="' . $W . '" height="3" fill="' . $glint . '" opacity=".75"/>'
          . '<rect x="' . $rnd(20, 120) . '" y="' . ($wy + 26) . '" width="' . $rnd(60, 150)
          . '" height="2" fill="' . $glint . '" opacity=".38"/>'
          . '<rect x="' . $rnd(180, 320) . '" y="' . ($wy + 40) . '" width="' . $rnd(40, 90)
          . '" height="2" fill="' . $glint . '" opacity=".28"/>';

    return '<svg class="tface" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="xMidYMid slice"'
        . ' role="img" aria-hidden="true" focusable="false">'
        // The sky warms and saturates toward the horizon, never away from it -
        // the one gradient direction that is true of every sky anybody has seen.
        . '<defs><linearGradient id="sk' . substr(md5($slug), 0, 6) . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="hsl(' . (($sky[0] + 14) % 360) . ' ' . max(12, $sky[1] - 18)
        . '% ' . min(96, $sky[2] + 8) . '%)"/>'
        . '<stop offset="1" stop-color="hsl(' . $sky[0] . ' ' . ($sky[1] + 8) . '% ' . ($sky[2] - 6) . '%)"/>'
        . '</linearGradient></defs>'
        . '<rect width="' . $W . '" height="' . $H . '" fill="url(#sk' . substr(md5($slug), 0, 6) . ')"/>'
        . $layers . $town . $mark . $water
        . '</svg>';
}

/**
 * The face of a tile: the world's own image if somebody made one, else the
 * landscape above.
 */
function xeric_play_tile_face(string $slug, array $scene): string
{
    if (xeric_play_tile_file($slug) !== null) {
        return '<span class="tface" style="background-image:url(\'tile.php?w=' . h(rawurlencode($slug))
             . '\');background-size:cover;background-position:center"></span>';
    }
    return xeric_play_tile_svg($slug, $scene);
}

/** Retired: the colour-field fallback, kept only so nothing breaks mid-deploy. */
function xeric_play_tile_css(string $slug): string
{
    if (xeric_play_tile_file($slug) !== null) {
        return "background-image:url('tile.php?w=" . h(rawurlencode($slug)) . "');background-size:cover;"
             . 'background-position:center';
    }

    $h = crc32($slug);
    // 200° of the wheel starting at 8: clay → ochre → sage → teal → river blue.
    // Stops before magenta on purpose. A band this wide is the difference
    // between a shelf and a shelf where four things are the same green; a band
    // any wider is a shelf with a bug on it.
    $hue  = 8 + ($h % 200);
    $hue2 = ($hue + 28 + (($h >> 8) % 40)) % 360;
    $x1   = 18 + (($h >> 3)  % 55);
    $y1   = 14 + (($h >> 7)  % 40);
    $x2   = 30 + (($h >> 11) % 60);
    $y2   = 50 + (($h >> 15) % 45);
    $tilt = (($h >> 19) % 60) - 30;

    // Weighted like a PHOTOGRAPH, not like a tint. The first version of this sat
    // at 82–95% lightness and every tile read as a white card with a stain on
    // it — which is the failure mode a fallback has to avoid above all others,
    // because a shelf of near-empty rectangles looks like art that failed to
    // load rather than art that was never made. Mid-lightness, real saturation,
    // and a vignette, so a tile has the same visual weight as the image that
    // will one day replace it and the grid does not reflow when it does.
    return 'background-image:'
        . 'radial-gradient(120% 100% at 50% 120%, rgba(20,18,14,.20) 0%, rgba(20,18,14,0) 60%),'
        . "radial-gradient(66% 60% at {$x1}% {$y1}%, hsl($hue 52% 78%) 0%, hsl($hue 46% 62% / 0) 72%),"
        . "radial-gradient(58% 54% at {$x2}% {$y2}%, hsl($hue2 46% 55%) 0%, hsl($hue2 42% 48% / 0) 74%),"
        . "linear-gradient({$tilt}deg, hsl($hue 40% 72%) 0%, hsl($hue2 36% 56%) 100%)";
}

/**
 * The grid. Tiles in, wordmark first, nothing explained.
 *
 * Ordered newest-forged first, which is the same order the old list used and the
 * only ordering a person can predict without being told one.
 */
function xeric_play_shelf_html(array $worlds): string
{
    $out = '';
    foreach ($worlds as $i => $x) {
        $href = ($x['launched'] ? 'play.php' : 'review.php') . '?w=' . h(rawurlencode($x['slug']));

        // Not a word on the tile beyond the world's own name — but a world where
        // death sticks still has to say so before somebody walks in, so it says
        // it the only way this screen allows: a mark, and a title for anyone
        // who hovers or reads with a screen reader.
        $mark = $x['permanent']
            ? '<span class="tmark" title="Death is permanent in this xeric" aria-label="death is permanent">&times;</span>'
            : '';

        // THE LAMP ANSWERS ONE QUESTION: is time passing in there right now. Green
        // yes, red no — and "no" covers both a xeric somebody stopped and one
        // that has never been launched, because from outside the door those are
        // the same fact. Which one it is goes in the tooltip, where a person who
        // wants the difference can find it.
        //
        // The greying of a stopped tile stays: it is what somebody notices from
        // across the room, and the lamp is what they can be sure of.
        $live = empty($x['paused']) && !empty($x['launched']) && !empty($x['lived']);
        $why  = !$x['launched'] ? 'not launched yet, so no time is passing'
              : (!$x['lived']   ? 'never opened, so its clock has not started'
              : ($x['paused']   ? 'stopped, its clock is where you left it'
                                : 'running, time is passing in here'));

        $out .= '<a class="tile' . (!empty($x['paused']) ? ' stopped' : '')
              . '" data-slug="' . h((string)$x['slug']) . '"'
              . ' href="' . $href . '" style="--d:' . (0.24 + $i * 0.045) . 's">'
              . xeric_play_tile_face((string)$x['slug'], (array)$x['scene'])
              . '<span class="tlamp' . ($live ? ' on' : '') . '" title="' . h($why)
              . '" aria-label="' . h($why) . '"></span>'
              . $mark
              . '<span class="tname">' . h($x['name']) . '</span>'
              . '</a>';
    }

    // The forge is a tile like any other, and it is last so the shelf reads as
    // "these, and one more" rather than as a toolbar with worlds attached.
    // WITH NOTHING ATTACHED THE PLUS GOES TO THE MACHINES, NOT THE INTERVIEW.
    // A forge is fourteen model calls; walking somebody through twenty
    // questions and failing at the end is the worst version of this screen, and
    // on a fresh install with nothing running it is the ONLY version they would
    // ever see. Of course they click the plus — it is the only thing here.
    $ready = xeric_web_connected(xeric_web_model());
    $out .= '<a class="tile new" href="' . ($ready ? 'forge.php?fresh=1' : 'model.php') . '"'
          . ' style="--d:' . (0.24 + count($worlds) * 0.045) . 's"'
          . ' title="' . ($ready ? 'Forge a new xeric' : 'Set up a machine first')
          . '" aria-label="' . ($ready ? 'Forge a new xeric' : 'Set up a machine first') . '">'
          . '<span class="plus" aria-hidden="true"></span></a>';

    return $out;
}

/** The shelf's own stylesheet. Loaded only there; nothing else has tiles. */
function xeric_play_shelf_css(): string
{
    return <<<'CSS'
/* WHITE NOTHINGNESS, and it has to be nothingness — no header, no rule, no
   footer, no chrome of any kind. The ground carries a gradient so faint it
   reads as light rather than as a colour, which is the one thing the Wii menu
   did that a flat fill cannot: it makes the tiles sit ON something. */
/* THE TOKEN, NOT A HEX. This was #fbfaf8 — a shade of the ground picked by eye
   and typed straight in, which meant the shelf stayed white when the rest of the
   app went dark. It is the one line that made "the room can be relit by editing
   nine lines" untrue. */
body.shelf{background:var(--bg);min-height:var(--app-h,100dvh);display:flex;flex-direction:column}
.shelfwrap{width:100%;max-width:62rem;margin:auto;padding:clamp(2.5rem,9vh,5rem) clamp(1rem,4vw,2rem);
  display:flex;flex-direction:column;gap:clamp(1.8rem,6vh,3rem)}

/* The wordmark arrives first and alone. Everything else is timed off it. */
.mark{margin:0;text-align:center;font-size:clamp(1.6rem,7vw,2.4rem);font-weight:300;
  letter-spacing:.42em;text-indent:.42em;color:var(--fg);
  animation:fadein .55s ease-out both}

/* Four across on a laptop, two on a phone — the Wii's own count, and the
   number at which a name is readable at a glance without the tile becoming a
   poster. */
.grid{display:grid;gap:clamp(.7rem,2vw,1.1rem);
  grid-template-columns:repeat(auto-fill,minmax(12.5rem,1fr))}
@media (max-width:26rem){.grid{grid-template-columns:repeat(2,1fr)}}
/* A FRESH INSTALL IS ONE TILE, and auto-fill puts it hard left under a centred
   wordmark — the first thing anybody ever sees. One or two centre instead of
   stretching; three or more fill the row as before. */
.grid:has(.tile:nth-child(-n+2):last-child){justify-content:center;
  grid-template-columns:repeat(auto-fit,minmax(12.5rem,15rem))}

/* A CHANNEL. White, hairline, soft shadow, 4:3 — and it lifts when you reach
   for it, which is the entire interaction language of the thing this borrows
   from. The lift is the affordance; there is no other one on this screen. */
.tile{position:relative;display:block;aspect-ratio:4/3;border-radius:.85rem;overflow:hidden;
  background:var(--bg-2);border:1px solid var(--line);box-shadow:var(--shadow);
  text-decoration:none;color:var(--fg);
  transition:transform .16s ease-out,box-shadow .16s ease-out,border-color .16s ease-out;
  animation:tilein .5s cubic-bezier(.16,.84,.3,1) both;animation-delay:var(--d,.3s)}
.tile:hover,.tile:focus-visible{transform:translateY(-3px);box-shadow:var(--shadow-lift);
  border-color:var(--accent-dim);outline:none}
.tile:active{transform:translateY(0);box-shadow:var(--shadow)}

.tface{position:absolute;inset:0}

/* The name sits IN FRONT of the picture, on a scrim rather than a bar, so it
   stays legible over art nobody has seen yet without boxing it in. */
.tname{position:absolute;left:0;right:0;bottom:0;padding:2rem 5.2rem .6rem .75rem;
  font-size:.95rem;font-weight:600;line-height:1.25;color:#fffdf9;
  background:linear-gradient(to top,rgba(18,16,12,.62) 34%,rgba(18,16,12,0));
  text-shadow:0 1px 3px rgba(18,16,12,.45)}

/* IS TIME PASSING IN THERE. Top left, opposite the permanent-death mark, over
   the art rather than beside it — the tile is the object and the lamp is a fact
   about the object, not a caption under it. The ring is what keeps it legible on
   a bright picture and a dark one alike. */
.tlamp{position:absolute;top:.45rem;left:.5rem;width:.6rem;height:.6rem;border-radius:50%;
  background:var(--bad);box-shadow:0 0 0 2px rgba(255,255,255,.85),0 1px 2px rgba(18,16,12,.5)}
.tlamp.on{background:var(--good)}
/* THE FLICKER. White for a moment and back to green, with the ring blooming out
   with it — a pulse rather than a blink, which is the difference between a thing
   that is alive and a thing that is faulty.
   Fast on the way up and slow on the way down, because that is what a real one
   looks like: the light arrives all at once and fades. */
/* THE BEAT: a circle that becomes a play triangle and comes back the same way
   it went. Out and back through the same shapes is what makes it read as one
   pulse rather than two events — and a triangle is the one shape that says
   "running" without a word on it.

   BOTH SHAPES ARE EIGHT-POINT POLYGONS, which is the whole trick: clip-path only
   interpolates between polygons with the same number of points, so the circle is
   an octagon (indistinguishable at ten pixels) and the triangle repeats vertices
   to match. Anything else snaps instead of morphing.

   The ring goes to a glow on the way through, because clip-path crops a
   box-shadow and a half-cropped ring around a triangle looks like a rendering
   fault. */
.tlamp.beat{animation:tbeat 1s cubic-bezier(.4,0,.2,1) 1}
@keyframes tbeat{
  0%, 100% {
    background:var(--good);
    clip-path:polygon(50% 0,85% 15%,100% 50%,85% 85%,50% 100%,15% 85%,0 50%,15% 15%);
    box-shadow:0 0 0 2px rgba(255,255,255,.85),0 1px 2px rgba(18,16,12,.5);
  }
  50% {
    background:#ffffff;
    clip-path:polygon(4% 2%,52% 26%,100% 50%,52% 74%,4% 98%,4% 98%,4% 50%,4% 2%);
    box-shadow:0 0 12px 4px rgba(255,255,255,.75);
  }
}
/* Somebody who asked not to be moved still gets the news, just not the motion. */
@media (prefers-reduced-motion: reduce){ .tlamp.beat{animation:none} }
/* A stopped tile is greyed, and its lamp must not be: the lamp is the one thing
   on it that still has to be readable. */
.tile.stopped .tlamp{filter:none}

/* WHEN THE NEXT HOUR LANDS. Bottom left, under the lamp it explains, small and
   in the scrim that already carries the name — the shelf is deliberately
   wordless and this is the one word it earns: without it, a healthy xeric that
   is between hours is indistinguishable from a broken one. */
/* BOTTOM RIGHT, because bottom left is where the name is. Put here first, it
   was drawn straight over the title — text that is present, positioned, and
   unreadable, which is worse than absent. The name gets room reserved for it
   rather than the two of them fighting over the same corner. */
.tdue{position:absolute;right:.6rem;bottom:.55rem;z-index:2;
  font-size:.66rem;letter-spacing:.02em;color:#fffdf9;opacity:.8;
  text-shadow:0 1px 3px rgba(18,16,12,.8)}
.tile.stopped .tdue{display:none}

.tmark{position:absolute;top:.35rem;right:.55rem;font-size:1.05rem;line-height:1;color:#fffdf9;
  text-shadow:0 1px 3px rgba(18,16,12,.6)}

/* The plus is the same tile with nothing in it. Dashed, because an empty solid
   square reads as a world that failed to load. */
.tile.new{border-style:dashed;border-color:var(--line);box-shadow:none;background:transparent}
.tile.new:hover,.tile.new:focus-visible{border-color:var(--accent);background:var(--bg-2);
  box-shadow:var(--shadow)}
.plus{position:absolute;inset:0;display:block}
.plus::before,.plus::after{content:"";position:absolute;top:50%;left:50%;background:var(--fg-far);
  transition:background .16s ease-out}
.plus::before{width:1.5rem;height:1px;transform:translate(-50%,-50%)}
.plus::after{width:1px;height:1.5rem;transform:translate(-50%,-50%)}
.tile.new:hover .plus::before,.tile.new:hover .plus::after,
.tile.new:focus-visible .plus::before,.tile.new:focus-visible .plus::after{background:var(--accent)}

/* The one link on the screen, for the one thing clicking a tile cannot reach. */
.corner{text-align:center;animation:fadein .5s ease-out both;animation-delay:.55s}
.corner a{font-size:.82rem;color:var(--accent);text-decoration:none;
  border-bottom:1px solid var(--accent-dim);padding-bottom:1px}
.corner a:hover,.corner a:focus-visible{border-bottom-color:var(--accent)}
.corner a.off{color:var(--bad);border-bottom-color:var(--bad)}
/* The machine, named, with a light on it — the same two things the machines
   screen says, in the one line the shelf allows itself. */
.cmodel{display:inline-flex;align-items:center;gap:.4rem}
.corner .lamp{width:.5rem;height:.5rem;border-radius:50%;background:var(--dot);
  border:1px solid var(--line);flex:0 0 auto}
.corner .lamp.up{background:#3f9142;border-color:#2f6e32}
.corner .lamp.down{background:var(--bad);border-color:var(--bad)}

/* A STOPPED WORLD LOOKS STOPPED, without a word being added to a screen that
   has none. Colour draining out of a picture is the oldest signal there is for
   "this is not live", and it survives being two hundred pixels wide. */
.tile.stopped .tface{filter:grayscale(.86) contrast(.92)}
.tile.stopped{opacity:.82}
.tile.stopped:hover,.tile.stopped:focus-visible{opacity:1}

/* the machines — the same card the forge has always used for a choice, one per
   machine, so this screen and the forge's model step are visibly one thing. */
.mlist{list-style:none;margin:0 auto;padding:0;width:100%;max-width:30rem;
  display:flex;flex-direction:column;gap:.55rem;
  animation:fadein .5s ease-out both;animation-delay:.24s}
.mlist li{display:flex;gap:.4rem;align-items:stretch}
.mrow{flex:1;min-width:0;display:flex}
.mlist .opt{position:relative;flex:1;min-width:0;margin:0;font:inherit;text-align:left;
  color:var(--fg);box-shadow:var(--shadow);
  transition:border-color .15s ease-out,box-shadow .15s ease-out}
/* Only a card you can attach reacts to being reached for. The active one is not
   a target — its one action is the button inside it. */
.mlist .opt:has(.mpick){cursor:pointer}
.mlist .opt:has(.mpick:hover),.mlist .opt:has(.mpick:focus-visible){
  border-color:var(--accent-dim);box-shadow:var(--shadow-lift)}
/* The stretched hit area. Invisible, covers the card, sits UNDER the meter and
   the disconnect button so both stay clickable. */
.mpick{position:absolute;inset:0;z-index:1;appearance:none;border:0;background:none;
  cursor:pointer;padding:0}
.mpick:focus-visible{outline:2px solid var(--accent);outline-offset:-3px}
.mact{position:relative;z-index:2;margin:0}

/* Per machine, on the address's own line. The question a meter answers is "is
   the free one carrying this or am I paying for it", and one number for
   everything cannot answer it.
   In the flow rather than absolutely placed: parked in the corner it needed a
   7.5rem right padding on the card, which wrapped every description two words
   early to make room for a number that is usually four characters long. */
.thead{display:flex;align-items:baseline;gap:.75rem;position:relative;z-index:2}
.thead .t{flex:1;min-width:0}
.thead .meter{flex:0 0 auto;margin:0}
/* AN ADDRESS IS TEXT UNTIL SOMEBODY REACHES FOR IT, and then it is plainly a
   field with a Save beside it. A list of machines that showed seven input boxes
   would read as a form to be filled in rather than as things that exist. */
.tedit{flex:1;min-width:0;display:flex;align-items:center;gap:.4rem}
.tshow{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  text-align:left;font:inherit;font-weight:600;font-size:1rem;color:var(--fg);cursor:text;
  background:none;border:0;border-bottom:1px dashed transparent;padding:0}
.tshow:hover,.tshow:focus-visible{border-bottom-color:var(--accent-dim);outline:none}
/* Open: a real field, lifted off the card so it reads as something being edited
   rather than something being displayed. */
input.t{flex:1;min-width:0;font:inherit;font-weight:600;font-size:1rem;color:var(--fg);
  background:var(--bg-2);border:1px solid var(--accent);border-radius:.4rem;
  padding:.25rem .45rem;box-shadow:var(--shadow-lift)}
input.t:focus{outline:none}
.tsave{flex:0 0 auto;cursor:pointer;font:inherit;font-size:.82rem;font-weight:600;
  color:var(--on-accent);background:var(--accent);border:1px solid var(--accent);
  border-radius:.4rem;padding:.25rem .6rem}
.tsave[disabled]{opacity:.5;cursor:default}
/* Taken. A tick, then nothing. */
.tok{flex:0 0 auto;color:var(--good);font-size:1.05rem;line-height:1;opacity:0;
  transition:opacity .25s ease-out}
.tok.go{opacity:1}
.tnote{display:block;margin:.35rem 0 0;font-size:.8rem;color:var(--bad)}
/* The way out, offered rather than guessed at. */
.tfound{position:relative;z-index:2;margin:.5rem 0 0}
.tfound .btn{border-color:var(--accent-dim);color:var(--accent)}
/* THREE STATES, THREE WEIGHTS. The engine gets the accent ring — it is the one
   running every world and there is only one. A connected machine gets a quiet
   ring in the same family: set up and available, not in charge. Everything else
   is a plain card. */
.mlist .opt.on{box-shadow:0 0 0 1px var(--accent),var(--shadow)}
.mlist .opt.lit{box-shadow:0 0 0 1px var(--accent-dim),var(--shadow)}
.mlist .opt .t{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mlist .opt .d{display:block;margin:.1rem 0 0}
/* WHO IS ANSWERING. Two glyphs and no logos: a filled square is a machine you
   can walk over to, a hollow ring is somebody else's. The name is ours; the
   model id is whatever the server volunteered, which is why it is quieter than
   the name — it is a fact being reported, not a claim being made. */
/* PURE TEXT, AND IT SITS OVER THE CARD'S STRETCHED PICKER. Without this it
   swallows every click that lands on the badge, which is the widest thing
   on the card. */
.whois{pointer-events:none;display:flex;align-items:center;gap:.4rem;margin:.35rem 0 0;
  font-size:.78rem;color:var(--fg-dim);min-width:0}
.wsig{flex:0 0 auto;width:.55rem;height:.55rem;border:1px solid var(--fg-far)}
.wsig.here{background:var(--fg-far);border-radius:.1rem}
.wsig.away{background:transparent;border-radius:50%}
.wname{flex:0 0 auto;font-weight:600;color:var(--fg)}
.wmodel{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.72rem}
/* The status row reserves the button's height on EVERY card, so choosing one
   does not make it taller than the rest. `.btn` carries min-height:3rem for
   finger-sized targets on a form; `.small` set padding and font size and never
   overrode it, which is where the extra fourteen pixels came from. */
.mlist .opt .status{display:flex;align-items:center;gap:.35rem;margin:.5rem 0 0;min-height:2.15rem}
/* The action sits under the meter, hard right, away from the row of things that
   only report. */
.mlist .mact{display:flex;justify-content:flex-end;gap:.45rem;margin:.5rem 0 0;position:relative;z-index:2;
  /* THE ROW IS FULL WIDTH; THE BUTTON IS NOT. Sitting above the stretched picker
     at z-index 2, the row swallowed every click in the empty space to the LEFT
     of the button — a dead band straight across a card whose whole point is that
     you can click it anywhere. The button takes its own clicks back. */
  pointer-events:none}
.mlist .mact .btn{pointer-events:auto}
.mlist .btn.small{min-height:2.15rem;padding:.3rem .75rem}
/* The right-hand lamp: not "can this be reached" but "is it the one in use".
   Right-aligned in the card, so the two facts sit at opposite ends and neither
   has to be read as a qualifier of the other. */
.wired{margin-left:auto;display:inline-flex;align-items:center;gap:.35rem;
  font-size:.78rem;color:var(--fg-dim);white-space:nowrap}
/* Both ways round, and never grey: grey is this screen's colour for "not asked
   yet", and whether something is connected is a thing the app is certain of. */
.wired .dot{width:.5rem;height:.5rem;margin:0;background:var(--bad);border-color:var(--bad)}
.wired{color:var(--bad)}
.wired.on{color:var(--good)}
.wired.on .dot{background:var(--good);border-color:var(--good)}
/* The engine says so in the app's own accent rather than in the green every
   connected machine wears — the difference between "reachable" and "in charge"
   should not be a shade of the same colour. */
.wired.engine{color:var(--accent);font-weight:600}
.wired.engine .dot{background:var(--accent);border-color:var(--accent)}
/* RED, because it is the one control on this screen that stops every world the
   visitor has. Outlined rather than filled: it is a thing to be found when
   wanted, not a thing the eye lands on first. */
.btn.cut{background:transparent;color:var(--bad);border-color:var(--bad);
  min-height:2.15rem;padding:.3rem .75rem;font-size:.85rem}
.btn.cut:hover,.btn.cut:focus-visible{background:var(--bad);color:var(--on-accent)}
/* Its opposite, in the same place and the same shape: green, outlined, one word.
   Same size as the cut so a list of machines has one button edge down the right
   rather than two — which is also what stops a card changing height when it
   becomes the chosen one. */
.btn.join{background:transparent;color:var(--good);border-color:var(--good);
  min-height:2.15rem;padding:.3rem .75rem;font-size:.85rem}
.btn.join:hover,.btn.join:focus-visible{background:var(--good);color:var(--on-accent)}

/* ------------------------------------------------- what else is running */
/* Quieter than the list above it, because everything here is a suggestion and
   nothing here is in use. Dashed, like the plus on an empty shelf: the shape of
   a thing that could exist rather than one that does. */
.found{width:100%;max-width:34rem;margin:0 auto}
.fh{margin:0 0 .5rem;font-size:.78rem;font-weight:600;letter-spacing:.1em;
  text-transform:uppercase;color:var(--fg-far)}
.found .mlist{margin:0}
.fopt{border-style:dashed;background:transparent;box-shadow:none!important;
  display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.fopt .thead{flex:1;min-width:9rem}
.fopt .whois{flex:2;min-width:11rem;margin:0}
.fopt .mact{flex:0 0 auto;margin:0}
/* The answer to a question somebody just asked, in the place they asked it. */
.soon{align-self:center;font-size:.82rem;color:var(--fg-dim);white-space:nowrap}
/* Said once, where the choice is made, and only when it applies. */
.mnote{width:100%;max-width:34rem;margin:-.4rem auto 0;font-size:.82rem;line-height:1.5;
  color:var(--fg-dim);border-left:2px solid var(--warn);padding-left:.8rem}

/* The way on, beside the line that says there is one. Sized to sit against a
   sentence rather than to anchor a page — it is a door, not a call to action. */
.mstops.row{display:flex;align-items:center;justify-content:center;gap:.9rem;flex-wrap:wrap}
.mstops.row .mstop{margin:0}
.btn.go{display:inline-flex;align-items:center;justify-content:center;
  text-decoration:none;background:var(--accent);color:var(--on-accent);
  border-color:var(--accent);min-height:2.15rem;padding:.25rem 1rem;font-size:.85rem}
.btn.go:hover,.btn.go:focus-visible{filter:brightness(1.08)}

/* The forget button, and — on rows that have none — the space one would take.
   Without the spacer the fixed row is wider than the rest and the right edge of
   the list zigzags. */
.mx,.mgap{flex:0 0 auto;width:2.2rem}
.mx{cursor:pointer;font:inherit;font-size:1.1rem;line-height:1;color:var(--fg-far);
  background:none;border:1px solid transparent;border-radius:.6rem;padding:0}
.mx:hover,.mx:focus-visible{color:var(--bad);border-color:var(--line);outline:none}

.madd{border-style:dashed;background:transparent;box-shadow:none!important}
.madd:hover,.madd:focus-within{border-color:var(--accent);background:var(--bg-2)}
.madd input{width:100%;margin:.55rem 0 0;font:inherit;font-size:1rem;color:var(--fg);
  background:var(--bg-2);border:1px solid var(--accent);border-radius:.5rem;padding:.6rem .7rem}
.madd input:focus{outline:none}

/* Directly under the wordmark, because it is the one thing on this screen that
   is about the whole app rather than about a row in a list. */
/* what is worth a buzz */
.nform{width:100%;max-width:30rem;margin:0 auto;display:flex;flex-direction:column;gap:.8rem;
  animation:fadein .5s ease-out both;animation-delay:.24s}
#nurl{width:100%;font:inherit;font-size:1rem;color:var(--fg);background:var(--bg-2);
  border:1px solid var(--line);border-radius:.6rem;padding:.75rem .85rem;box-shadow:var(--shadow)}
#nurl:focus{outline:none;border-color:var(--accent)}
.nlist{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.45rem}
.nlist .opt{margin:0;cursor:pointer;box-shadow:var(--shadow);position:relative;padding-left:2.3rem}
.nlist .opt input[type=checkbox]{position:absolute;left:.85rem;top:.95rem}
.nlist .opt .t{display:block;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  font-size:.86rem;letter-spacing:.04em}
.nlist .opt .d{display:block}
.nlist .opt input[type=number]{width:6.5rem;font:inherit;font-size:.86rem;color:var(--fg);
  background:var(--bg-2);border:1px solid var(--line);border-radius:.35rem;padding:.1rem .35rem}
.nrow{display:flex;gap:.5rem;flex-wrap:wrap}

.mstops{margin:-1rem 0 0}
.mstops .mstop{margin:0}
.mstops .mstop + .mstop{margin-top:.4rem}
.mstop{margin:-1rem 0 0;text-align:center;font-size:.88rem;color:var(--fg-dim);
  animation:fadein .5s ease-out both;animation-delay:.18s}
.mstop.bad{max-width:34rem;margin-left:auto;margin-right:auto;color:var(--bad)}
/* The engine's own accent, the same one its card wears, so the line under the
   wordmark and the ring six inches below are visibly the same fact. */
.mstop.on{color:var(--accent);font-weight:600;letter-spacing:.02em}

@keyframes fadein{from{opacity:0}to{opacity:1}}
@keyframes tilein{from{opacity:0;transform:translateY(10px) scale(.985)}
                  to{opacity:1;transform:none}}

/* Somebody who has asked not to be moved gets the same screen, instantly. */
@media (prefers-reduced-motion: reduce){
  .mark,.tile,.corner{animation:none}
  .tile{transition:none}
}
CSS;
}

/**
 * What death means here, who it has taken, and what can be done about it.
 *
 * The mode is stated in ONE plain sentence at the top, before any control,
 * because it is the only thing on this panel a person can get wrong in a way
 * that cannot be undone. A world that quietly offered a "bring them back" button
 * that turned out not to exist would be worse than one that never offered it.
 *
 * The picker lists the living and nothing else, so the one destructive control
 * here cannot be aimed at somebody who is already gone. Everything about
 * permanence is decided in death.php; this renders what it says.
 */
function xeric_play_fate_html(array $t, PDO $db, array $cast): string
{
    $mode   = xeric_death_mode($t, $db);
    $perm   = $mode === XERIC_DEATH_PERMANENT;
    $locked = xeric_death_locked($db);
    $dead   = array_values(array_filter($cast, fn(array $c): bool => !empty($c['dead'])));
    $living = array_values(array_filter($cast, fn(array $c): bool => empty($c['dead'])));

    $out = '<p class="frule">'
        . ($perm
            ? '<b>Death is permanent here.</b> Nothing in this app will bring anybody back.'
            : '<b>Death can be undone here.</b> Anybody lost can be brought back, and the xeric keeps '
              . 'every memory of having lost them.')
        . '</p>';

    // Said only while it is still true. After the first death the sentence would
    // be an invitation to go and change something that no longer changes.
    if (!$locked) {
        $out .= '<p class="note">No one has died here yet, so this is still a setting, '
              . '<code>deaths.mode</code> in the template. The first death freezes it.</p>';
    }

    if ($dead !== []) {
        $out .= '<ul class="flist">';
        foreach ($dead as $c) {
            $out .= '<li><span><span class="fn">' . h($c['name']) . '</span>'
                 . ((string)$c['how'] !== '' ? '<span class="fh">' . h($c['how']) . '</span>' : '')
                 . '</span>'
                 . ($perm ? '<span class="fperm">gone</span>'
                          : '<button type="button" class="btn ghost small fback" data-h="' . h($c['handle'])
                            . '">bring back</button>')
                 . '</li>';
        }
        $out .= '</ul>';
    }

    if ($living !== []) {
        $out .= '<div class="fkill"><select id="fwho">';
        foreach ($living as $c) {
            $out .= '<option value="' . h($c['handle']) . '">' . h($c['name']) . '</option>';
        }
        $out .= '</select><input id="fhow" type="text" maxlength="200" autocomplete="off" '
             . 'placeholder="what the town would say"><button type="button" class="btn ghost small" id="fkill">'
             . 'they die</button></div>';
    }

    $out .= '<div class="fworld">';
    if ($living !== []) {
        $out .= '<button type="button" class="btn ghost small warnbtn" id="fend">end this xeric</button>';
    }
    if ($dead !== [] && !$perm) {
        $out .= '<button type="button" class="btn ghost small" id="frestore">bring the xeric back</button>';
    }
    $out .= '</div>';

    if ($living === [] && $dead !== []) {
        $out .= '<p class="note bad">There is nobody left in ' . h((string)($t['meta']['name'] ?? 'this xeric'))
              . '.' . ($perm ? ' And there will not be.' : '') . '</p>';
    }
    return $out;
}

/**
 * Where you are standing, and what it costs to be somewhere else.
 *
 * The first surface built on xeric_travel_map(), and deliberately the dumbest
 * one that works: a list of rooms with a price on each. A drawn map, a room
 * view, a headset — all of those are this same object rendered differently, and
 * the reason this one is a list of buttons is that a list of buttons proves the
 * endpoint is enough before anybody spends a weekend on pixels.
 *
 * NEAREST FIRST, not template order. The question a person is holding when they
 * look at this is "can I get there before she leaves", and sorting by the number
 * that answers it is the whole difference between a map and a directory.
 *
 * Every destination says who is standing in it RIGHT NOW, which is the honest
 * version of the feature and also the one that makes it fun: you can see that
 * Dot is at the diner, and you can see that it is a six-minute walk, and those
 * two facts together are a decision. Hiding the second one would be a menu.
 */
function xeric_play_where_html(array $map, string $slug = ''): string
{
    $here  = $map['you']['where'] ?? null;
    $spots = $map['places'];
    usort($spots, fn(array $a, array $b): int => ($a['minutes'] <=> $b['minutes']) ?: strcmp($a['name'], $b['name']));

    $who = function (array $p): string {
        if ($p['who'] === []) return '<span class="wq">nobody there</span>';
        return '<span class="ww">' . h(xeric_join_list(array_column($p['who'], 'name'))) . '</span>';
    };

    // Standing somewhere is a statement; being on your own time is the same
    // sentence the engine gives a character who is off shift, on purpose.
    $out = '<p class="wnow">';
    if ($here !== null) {
        $at = null;
        foreach ($map['places'] as $p) if ($p['key'] === $here) $at = $p;
        $out .= '<b>' . h((string)($at['name'] ?? $here)) . '</b>'
             . '<span class="' . ($at && $at['open'] ? 'wo' : 'wc') . '">'
             . ($at && $at['open'] ? 'open' : (!empty($at['dark']) ? 'dark' : 'shut')) . '</span>'
             . ($at ? $who($at) : '');
    } else {
        $out .= '<b>Nowhere in particular</b><span class="wq">your own time</span>';
    }
    $out .= '</p>';

    if (!(bool)$map['mapped']) {
        // Said plainly rather than hidden: a world forged before places had
        // coordinates still works, every trip just costs the same. Telling
        // somebody that is better than letting them wonder why the mill and the
        // shop next door are the same walk.
        $out .= '<p class="note">This xeric was made before it had a map, so everywhere in it is the '
              . 'same distance from everywhere else.'
              . ($slug !== '' ? ' Rerolling its places in the review gives it one.' : '') . '</p>';
    }

    $out .= '<ul class="wgo">';
    foreach ($spots as $p) {
        if ($p['here']) continue;
        $out .= '<li><button type="button" class="goto" data-to="' . h($p['key']) . '">'
             . '<span class="wm">' . (int)$p['minutes'] . ' min</span>'
             . '<span><span class="wn">' . h($p['name']) . '</span>'
             . '<span class="wd">'
             . '<span class="' . ($p['open'] ? 'wo' : 'wc') . '">'
             . ($p['open'] ? 'open' : (!empty($p['dark']) ? 'dark' : 'shut')) . '</span>'
             . $who($p) . '</span></span>'
             . '<span class="pgo">›</span></button></li>';
    }
    if ($here !== null) {
        $out .= '<li><button type="button" class="goto ghost" data-to="">'
             . '<span class="wm">' . (int)($map['you']['leave']['minutes'] ?? 0) . ' min</span>'
             . '<span><span class="wn">Leave</span>'
             . '<span class="wd"><span class="wq">go be on your own time</span></span></span>'
             . '<span class="pgo">›</span></button></li>';
    }
    return $out . '</ul>';
}

/**
 * What has already happened here, newest first, each linked to its reasoning.
 *
 * The live feed only exists during a skip; ten seconds after one, the evidence
 * that this world has a past is gone from the screen. For somebody tuning a
 * world that is the wrong way round — the history IS the product — so it is on
 * the page, permanently, with the decision trail one tap away — for the person
 * who forged it. Somebody playing a copy gets the history and not the trail,
 * because the trail is the inspector and the inspector is theirs (why.php).
 */
function xeric_play_events_html(array $t, PDO $db, string $slug, bool $mine = true, int $limit = 8): string
{
    $rows = xeric_events_recent($db, $limit);
    if ($rows === []) {
        return '<p class="quiet">Nothing has happened here yet. Press one of the buttons above and it will.</p>';
    }
    $out = '';
    foreach ($rows as $e) {
        $who = [];
        foreach ((array)$e['participants'] as $p) $who[] = xeric_world_name($t, (string)$p);
        $place = xeric_world_place_name($t, (string)($e['place'] ?? ''));
        $out .= '<div class="evt"><div class="ft">' . h((string)$e['title']) . '</div>'
            . '<div class="w">' . h(xeric_play_stamp($t, (int)$e['world_epoch']))
            . ($place !== '' ? ' · ' . h($place) : '')
            . ($who !== [] ? ' · ' . h(implode(', ', $who)) : '') . '</div>'
            . '<p class="fp">' . h((string)$e['prose']) . '</p>'
            . ($slug !== '' && $mine
                ? '<a class="whylink" href="why.php?w=' . h(rawurlencode($slug)) . '&amp;e=' . (int)$e['id']
                  . '">why did this happen?</a>'
                : '')
            . '</div>';
    }
    return $out;
}

/** What this world runs on — armed systems, pace, whose story, who is in the dark. */
function xeric_play_panel_html(array $p): string
{
    $row = fn(string $k, string $v) => '<div class="row"><div class="k">' . h($k) . '</div><div class="v">' . $v . '</div></div>';

    // CHIPS, NOT A SENTENCE. These were <code> spans joined by a literal space,
    // which reads as one run-on phrase the moment there is more than a handful —
    // "a chance to be different a debt alliances that cost arcs attraction" is
    // five systems and looks like a broken string. A world with everything armed
    // has thirty. Each one is its own bordered thing, and `mortality` is called
    // out because it is the only system here that removes people.
    $armed = (array)$p['armed'];
    sort($armed);
    $sys = $armed === []
        ? '<span class="quiet">nothing in particular, ordinary life</span>'
        : '<span class="chips">' . implode('', array_map(
            fn($s) => '<code' . ((string)$s === 'mortality' ? ' class="lethal"' : '') . '>'
                    . h(str_replace('_', ' ', (string)$s)) . '</code>', $armed)) . '</span>'
          . (in_array('mortality', $armed, true)
              ? '<span class="quiet">, <b>mortality</b> is armed, so the cast can die while you are away. '
                . 'Rare on purpose, and see below for whether it can be undone.</span>'
              : '');

    $pace = (string)$p['pace'] !== ''
        ? h($p['pace']) . ' <span class="quiet">· about one thing every ' . (int)($p['every'] ?? 0)
          . 'h of xeric time if nobody skips</span>'
        : '<span class="quiet">the engine default</span>';

    $star = $p['star'] === null
        ? '<span class="quiet">yours, you are the one this happens to</span>'
        : '<b>' . h((string)$p['star']['name']) . '</b>'
          . ((string)$p['star']['arc'] !== '' ? ' <span class="quiet">, ' . h((string)$p['star']['arc']) . '</span>' : '');

    // The name, and never the secret: `must_not_know` is a wall the sweeps
    // enforce by keeping them out of the room, not a spoiler for the front page.
    $prot = $p['protected'] === []
        ? '<span class="quiet">nobody, this xeric keeps nothing from its own cast</span>'
        : implode(', ', array_map(fn($x) => '<b>' . h((string)$x['name']) . '</b>', (array)$p['protected']))
          . ' <span class="quiet">, there is something this xeric keeps from them. It is not printed here.</span>';

    return $row('armed', $sys)
        . $row('pace', $pace)
        . $row('whose story', $star)
        . $row('in the dark', $prot)
        . $row('so far', (int)$p['events'] . ' events · ' . (int)$p['memories'] . ' memories · '
            . (int)$p['unread'] . ' unread');
}

// ---------------------------------------------------------------------------
// Jobs
// ---------------------------------------------------------------------------

/**
 * A world's live tick job, if this browser has one running.
 *
 * Same contract as the forge's resume: the work is not in the page, so a reload,
 * a locked phone or a proxy that cut the stream costs a reconnect and nothing
 * else. Scoped per world so leaving one world does not rejoin another's skip.
 */
function xeric_play_job_of(array $sess, string $slug): string
{
    $job = (string)($sess['tick'][$slug] ?? '');
    if (!xeric_web_job_ok($job) || !is_file(xeric_web_job_path($job))) return '';
    $j = xeric_web_job_read($job);
    return $j['done'] ? '' : $job;
}

// ---------------------------------------------------------------------------
// The look
// ---------------------------------------------------------------------------

/**
 * The play view's CSS, on top of the wizard's.
 *
 * Same palette, same spacing, same tap targets — a visitor who forged a world
 * and pressed "enter" must not feel handed off to a different product. Only
 * shapes the wizard has no use for live here.
 */
function xeric_play_css(): string
{
    return <<<'CSS'
/* This page shows and hides things with the `hidden` ATTRIBUTE, and the browser
   implements that with a plain `[hidden]{display:none}` in its own stylesheet —
   one class selector's worth of specificity. So any rule here that sets
   `display` on the same element beats it, the attribute silently stops meaning
   anything, and the element stays on screen while every script in the file
   correctly believes it is gone. That is exactly how the typing dots came to sit
   under an idle world announcing that somebody was always about to speak.
   One line, stated once, so the idiom the whole page relies on cannot be
   overridden by a later rule that happens to need a display mode. */
[hidden]{display:none!important}

/* header ------------------------------------------------------------ */
/* The clock is sticky because it is the one fact this product is selling:
   a world that keeps its own time whether or not you are looking. Scrolling
   past it on a phone and losing it was the fastest way to forget that. */
/* --------------------------------------------------------------- the shell */
/* Sidebar and main, side by side on anything wide enough. On a phone the
   sidebar becomes a drawer over the conversation rather than a column beside
   it, because 250px of a 390px screen is not a sidebar, it is the screen. */
/* The chip bar is above this now and takes --bar-h out of the window, so the
   shell asks for what is left. Without this the page is always one bar taller
   than the screen and every phone has a dead scroll at the bottom. */
#app{display:grid;grid-template-columns:17rem 1fr;align-items:start;
  min-height:calc(var(--app-h,100dvh) - var(--bar-h))}
/* width:100% because the base stylesheet's `main` is a centred max-content
   column: left alone, a single unwrappable row (the chip bar) sizes the whole
   pane to ITS width and the page scrolls sideways on a phone. 100% resolves
   against the grid track instead, and the reading clamp still holds it to
   40rem on anything wide. */
#app > main{min-width:0;width:100%;padding-top:.6rem}

/* Sticks BELOW the chip bar, not under it. */
#side{position:sticky;top:var(--bar-h);align-self:start;height:calc(var(--app-h,100dvh) - var(--bar-h));
  display:flex;flex-direction:column;gap:.9rem;overflow-y:auto;overscroll-behavior:contain;
  padding:.9rem .9rem calc(.9rem + env(safe-area-inset-bottom))
          calc(.9rem + env(safe-area-inset-left));
  background:var(--bg-2);border-right:1px solid var(--line)}
.sbrand{display:flex;align-items:baseline;gap:.5rem}
.sstatus{font-size:.8rem}
/* the world's name, under the brand and over its calendar */
.sname{margin:.15rem 0 .3rem;font-size:1.2rem;line-height:1.25}
/* the compass: three readings that move, under the world's name */
.scompass{display:flex;flex-wrap:wrap;gap:.15rem .7rem;margin:0 0 .5rem;
  font-size:.72rem;color:var(--fg-far)}
.cpt b{color:var(--fg-dim);font-weight:700;font-variant-numeric:tabular-nums}
/* the world's own calendar, at the top of the sidebar */
.sdate{margin:0 0 .35rem;font-size:.92rem;font-weight:600;color:var(--fg)}
.smeter{margin:.4rem 0 0;font-size:.76rem;color:var(--fg-dim)}
/* a room's hours, on its name */
.phrs{margin-left:.45rem;font-size:.7rem;font-weight:400;color:var(--fg-dim)}
.sfoot{margin-top:auto}
.sideblock{border-top:1px solid var(--line-2);padding-top:.7rem}
.sh{margin:0 0 .45rem;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;
  color:var(--fg-far);font-weight:700}
/* Each block folds. Three sections in one 17rem column is a scroll at the cast
   size this is built for, and a sidebar you have to scroll is not a glance.
   Same ▸/▾ vocabulary as the events inside, so one arrow means one thing. */
summary.sh{list-style:none;cursor:pointer;position:relative;display:flex;align-items:center;
  gap:.4rem;padding-left:.85rem;touch-action:manipulation}
summary.sh::-webkit-details-marker{display:none}
summary.sh::before{content:'▸';position:absolute;left:0;top:0;font-size:.7rem;line-height:1.6;
  color:var(--fg-far)}
.sideblock[open] > summary.sh::before{content:'▾'}
summary.sh:hover,.sideblock[open] > summary.sh{color:var(--fg-dim)}
summary.sh:focus-visible{outline:1px solid var(--accent);outline-offset:2px;border-radius:2px}
/* The count is what makes folding safe: shut, a section still says how much is
   behind it, so nothing goes quiet just because somebody put it away. */
.sbn{font-weight:700;font-variant-numeric:tabular-nums;letter-spacing:normal;
  font-size:.7rem;color:var(--fg-dim)}
.sbn::before{content:'·';margin-right:.35rem;color:var(--fg-far)}
.sbb{padding-bottom:.15rem}

.places{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.55rem}
.place .pl{display:block;font-size:.84rem;font-weight:600;color:var(--fg)}
.place.shut .pl,.place.empty .pl{color:var(--fg-dim);font-weight:500}
.place.here .pl{color:var(--accent)}
.youare{margin-left:.4rem;font-size:.62rem;letter-spacing:.06em;text-transform:uppercase;
  color:var(--accent);font-weight:700}
.nobody{font-size:.75rem;color:var(--fg-far)}
.who{list-style:none;margin:.2rem 0 0;padding:0 0 0 .6rem;border-left:2px solid var(--line-2)}
.wperson{display:block;width:100%;text-align:left;background:none;border:0;padding:.2rem 0;
  font:inherit;cursor:pointer;color:var(--fg);touch-action:manipulation}
.wperson:hover .wn,.wperson:focus-visible .wn{color:var(--accent);outline:none}
.wn{display:block;font-size:.82rem}
.wd{display:block;font-size:.72rem;color:var(--fg-dim)}
/* the day's sky, one quiet line above the rooms it hangs over */
.swx{margin:0 0 .55rem;font-size:.74rem;font-style:italic;color:var(--fg-dim);line-height:1.4}
/* the mood on the second line: the town's own word carries the weight, its
   motif trails after in the same voice the sky is written in */
.swx b{font-weight:600;font-style:normal;color:var(--fg)}
.swx i{font-weight:400;color:var(--fg-far)}
/* WHO IS AT THE CENTRE, and the way in. A door, not a setting — which is why
   it is here and not only under the cog. */
.atcentre{list-style:none;padding:0;margin:0 0 .5rem}
.atcentre li{display:flex;gap:.4rem;align-items:baseline;padding:.18rem 0;font-size:.82rem}
.atcentre .pn{font-weight:600}
.atcentre .pw{color:var(--fg-far);font-size:.74rem}
.atcentre .pout{margin-left:auto;font:inherit;font-size:.9rem;line-height:1;padding:0 .3rem;
  border:0;background:transparent;color:var(--fg-far);cursor:pointer;border-radius:.25rem}
.atcentre .pout:hover{color:var(--fg);background:var(--line)}
.sinv{width:100%;font:inherit;font-size:.8rem;padding:.4rem;border-radius:.35rem;
  border:1px solid var(--line);background:transparent;color:var(--fg-dim);cursor:pointer}
.sinv:hover{color:var(--fg)}
.sinv:disabled{opacity:.5;cursor:default}
.sinvhint{margin:.35rem 0 0;font-size:.7rem;color:var(--fg-far);line-height:1.45}
.sinvbox{margin:.5rem 0 0;text-align:center}
.sinvbox svg{width:100%;max-width:11rem;height:auto}
.sinvcode{margin:.4rem 0 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  font-size:1.05rem;letter-spacing:.18em;font-weight:600}
/* the one-word "home" mark beside a name standing under their own roof */
.whome{margin-left:.4rem;font-size:.6rem;letter-spacing:.06em;text-transform:uppercase;
  color:var(--fg-far);border:1px solid var(--line-2);border-radius:.5rem;padding:0 .3rem}
/* A WALK IS A LIE MID-SKIP: the worker owns the clock and travel.php refuses
   the write anyway, so the buttons say so instead of bouncing. Body class over
   per-node disabling because the sidebar repaints every twelve seconds and a
   class on <body> survives every repaint for free. */
body.skipping .wplace{opacity:.45;pointer-events:none}
body.skipping .wplace .wgo2{visibility:hidden}
.wperson.gone{cursor:default;opacity:.55}
.offscreen{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.35rem;
  font-size:.78rem;color:var(--fg-dim)}
.offscreen li{padding-left:.7rem;border-left:2px solid var(--line-2)}
/* a headline you can open. The marker is ours because the native triangle sits
   on the first line of a wrapped title and drifts as it wraps. */
.ev > summary{list-style:none;cursor:pointer;display:block;position:relative;padding-left:.85rem;
  color:var(--fg-dim);touch-action:manipulation}
.ev > summary::-webkit-details-marker{display:none}
.ev > summary::before{content:'▸';position:absolute;left:0;top:0;font-size:.7rem;line-height:1.5;
  color:var(--fg-far)}
.ev[open] > summary::before{content:'▾'}
.ev > summary:hover,.ev[open] > summary{color:var(--fg)}
.evb{margin:.3rem 0 .5rem;padding-left:.85rem}
.evw{margin:0 0 .25rem;font-size:.68rem;color:var(--fg-far)}
.evp{margin:0;font-size:.76rem;line-height:1.45;color:var(--fg-dim)}
.evb .whylink{margin-top:.35rem;font-size:.7rem}

/* THE CHIP BAR. Everybody, across the top, always — so a thread is a thing you
   switch rather than a page you leave. It sits ABOVE the shell, not inside main:
   twelve people is what this is built for, and confined to the pane right of the
   sidebar twelve chips have two thirds of a screen to live in. Full window
   width, sticky at the very top, and the sidebar hangs below it (--bar-h). */
/* --bar-h carries the notch with it, so the three rules that have to agree
   about how tall this thing is (the bar, the shell, the sidebar's sticky top)
   read one number and cannot drift apart on a phone. */
:root{--bar-h:calc(3.15rem + env(safe-area-inset-top))}
.chipbar{position:sticky;top:0;z-index:11;display:flex;align-items:center;gap:.4rem;
  box-sizing:border-box;height:var(--bar-h);
  padding:calc(.5rem + env(safe-area-inset-top)) calc(.7rem + env(safe-area-inset-right))
          .5rem calc(.7rem + env(safe-area-inset-left));
  background:var(--bg);border-bottom:1px solid var(--line-2)}
/* .chipbar-scoped: the runs panel also owns a `.chips`, and its flex-wrap
   would stack this bar two rows deep on a phone. */
.chipbar .chips{flex:1 1 auto;min-width:0;display:flex;flex-wrap:nowrap;gap:.35rem;overflow-x:auto;
  overscroll-behavior-x:contain;scrollbar-width:none;scroll-behavior:smooth}
.chips::-webkit-scrollbar{display:none}
/* DRAGGABLE. A touch screen already flicks this; a mouse had a scroll strip with
   no scrollbar, which is a bar you cannot reach the end of. Grab and pull. The
   cursor is the only affordance there is room for. */
.chipbar .chips{cursor:grab}
.chipbar .chips.dragging{cursor:grabbing;scroll-behavior:auto;scroll-snap-type:none;
  user-select:none}
/* A drag that started on a chip must not also open that chip's thread. */
.chipbar .chips.dragging .chip{pointer-events:none}
/* THERE IS MORE THAT WAY. With the scrollbar hidden, a chip sliced off at the
   edge reads as a broken layout rather than as a strip that continues; a face
   fading out reads as one that continues. Set by JS only when the strip
   actually overflows, and per side, or the last chip of a cast of three would
   sit in a fade that means nothing. */
.chipbar .chips.more{mask-image:linear-gradient(to right,#000 calc(100% - 1.6rem),transparent);
  -webkit-mask-image:linear-gradient(to right,#000 calc(100% - 1.6rem),transparent)}
.chipbar .chips.less{mask-image:linear-gradient(to right,transparent,#000 1.6rem);
  -webkit-mask-image:linear-gradient(to right,transparent,#000 1.6rem)}
.chipbar .chips.less.more{mask-image:linear-gradient(to right,transparent,#000 1.6rem,
  #000 calc(100% - 1.6rem),transparent);
  -webkit-mask-image:linear-gradient(to right,transparent,#000 1.6rem,
  #000 calc(100% - 1.6rem),transparent)}
.chip{flex:0 0 auto;padding:.3rem .7rem;font:inherit;font-size:.8rem;white-space:nowrap;
  color:var(--fg-dim);background:var(--bg-2);border:1px solid var(--line);border-radius:1rem;
  cursor:pointer;touch-action:manipulation}
.chip:hover{color:var(--fg);border-color:var(--accent-dim)}
.chip.on{color:var(--on-accent);background:var(--accent);border-color:var(--accent)}
.chip.unread{border-color:var(--bad);color:var(--fg)}
.chip.gone{opacity:.5;text-decoration:line-through}
.narrchip{flex:0 0 auto;width:2rem;height:2rem;border-radius:50%;font-weight:700;
  color:var(--accent);background:var(--bg-2);border:1px dashed var(--accent-dim);cursor:pointer}
/* the door somebody walks in through, beside the one you ask questions of */
.addchip{flex:0 0 auto;width:2rem;height:2rem;border-radius:50%;font-size:1.05rem;font-weight:700;
  line-height:1;color:var(--fg-dim);background:var(--bg-2);border:1px dashed var(--line);cursor:pointer}
.addchip:hover{color:var(--accent);border-color:var(--accent-dim)}

/* The burger is the drawer, and it carries the unread count the chips would
   have shown if they were not scrolled off. */
.burger{position:relative;flex:0 0 auto;width:2.2rem;height:2.2rem;display:none;
  flex-direction:column;justify-content:center;gap:.22rem;padding:0 .45rem;
  background:none;border:0;cursor:pointer;touch-action:manipulation}
.burger span{display:block;height:2px;background:var(--fg);border-radius:2px}
.bbadge{position:absolute;top:.1rem;right:.05rem;min-width:1rem;height:1rem;padding:0 .2rem;
  font-size:.6rem;line-height:1rem;text-align:center;color:var(--on-accent);
  background:var(--bad);border-radius:.5rem;font-weight:700}
#sidescrim{position:fixed;inset:0;z-index:8;background:rgba(0,0,0,.45);border:0}

/* -------------------------------------------------- faces, cogs and places */
/* The small and tiny sizes of the same face. One class, one hue variable, so a
   person is the same colour in the sidebar, the chips, the cast and the thread
   without four rules agreeing by luck. */
.av.s{width:1.35rem;height:1.35rem;font-size:.58rem}
.av.c{width:1.15rem;height:1.15rem;font-size:.5rem;margin:-.1rem .1rem -.1rem -.35rem}

/* Sidebar people wear their face beside the name. */
.wperson{display:flex;gap:.45rem;align-items:center}
.wperson>span:last-child{min-width:0}

/* The place name is a walk. The affordance only surfaces on hover and focus:
   a list where every line shouts "go" reads as a menu, not a town. */
/* color explicit: buttons take ButtonText, not the theme's ink */
.wplace{font:inherit;color:var(--fg);background:none;border:0;padding:.2rem 0;cursor:pointer;width:100%;text-align:left}
.wplace:hover,.wplace:focus-visible{color:var(--accent);outline:none}
.wplace .wgo2{margin-left:.45rem;font-size:.68rem;color:var(--accent-dim);opacity:0}
.wplace:hover .wgo2,.wplace:focus-visible .wgo2{opacity:1}
.wplace:disabled{cursor:default;color:var(--fg-dim)}

/* The cog rides the row's top corner, beside the button it is about. */
.crow{position:relative}
.pgear{position:absolute;top:.45rem;right:.45rem;z-index:2;width:2rem;height:2rem;
  font-size:.95rem;line-height:1;color:var(--fg-dim);background:var(--bg-2);
  border:1px solid var(--line);border-radius:50%;cursor:pointer;touch-action:manipulation}
.pgear:hover,.pgear:focus-visible{color:var(--fg);border-color:var(--accent-dim);outline:none}
.crow .person{padding-right:2.8rem}

/* The player's own chip, first in the bar: dashed like the narrator's, because
   both are of the world without being cast in it. */
.chip.me{border-style:dashed;border-color:var(--accent-dim);color:var(--fg)}
.chip.me:hover{border-color:var(--accent)}
/* the mirror: the exact sentences the cast is handed about the player */
.pillme{margin-left:auto;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:var(--fg-dim)}
.pseen{margin:0 0 .9rem;padding:.5rem .8rem;border-left:3px solid var(--accent);
  background:var(--bg-2);border-radius:0 .5rem .5rem 0}
.pseenline{margin:.25rem 0;font-size:.88rem;line-height:1.5;color:var(--fg)}

/* Chips carry the face, the unread dot, where somebody is, and the cog on the
   open one. The presence mark is a glyph with a tooltip, never a sentence. */
.chip{display:inline-flex;align-items:center;gap:.3rem}
.chip .cdot{width:.4rem;height:.4rem;border-radius:50%;background:var(--bad);flex:0 0 auto}
.chip .cps{font-size:.72rem;line-height:1;opacity:.85}
.chip .cgear{display:none;font-size:.8rem;line-height:1;opacity:.8;padding:0 0 0 .1rem}
.chip.on .cgear{display:inline}
.chip.on .av.c{outline:2px solid var(--on-accent);outline-offset:-1px}

/* ----------------------------------------------------------- the cog modal */
#coverlay{position:fixed;inset:0;z-index:40;display:none;align-items:flex-start;justify-content:center;
  overflow-y:auto;-webkit-overflow-scrolling:touch;background:rgba(0,0,0,.55);
  padding:max(1rem,env(safe-area-inset-top)) max(.8rem,env(safe-area-inset-right))
          max(1rem,env(safe-area-inset-bottom)) max(.8rem,env(safe-area-inset-left))}
#coverlay.open{display:flex}
#cmodal{width:100%;max-width:38rem;margin:auto 0;background:var(--bg);border:1px solid var(--line);
  border-radius:.9rem;padding:1.1rem 1.15rem 1rem;box-shadow:var(--shadow-lift)}
#cmodal h2{display:flex;align-items:center;gap:.55rem;margin:0 0 .8rem;font-size:1.05rem}
#cmodal h2 .av{width:1.6rem;height:1.6rem;font-size:.66rem}
.cfield{margin:0 0 .65rem}
.cvsel{font:inherit;background:var(--card,var(--bg));color:var(--fg);border:1px solid var(--line);border-radius:.35rem;padding:.3rem .5rem;max-width:100%}
.cfield label{display:block;font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;
  color:var(--fg-dim);margin:0 0 .25rem}
.cline{display:flex;gap:.4rem;align-items:flex-start}
.cline input[type=text],.cline textarea,.cline select,.cline input[type=number]{
  flex:1 1 auto;min-width:0;font:inherit;font-size:16px;color:var(--fg);background:var(--bg-2);
  border:1px solid var(--line);border-radius:.5rem;padding:.5rem .6rem;outline:none}
.cline textarea{min-height:4.2rem;resize:vertical;line-height:1.4}
.cline :focus-visible{border-color:var(--accent-dim)}
/* The die: rolls this one field from everything else in the xeric, the same
   dice the review page throws. */
.cdice{flex:0 0 auto;width:2.4rem;height:2.4rem;font-size:1rem;line-height:1;cursor:pointer;
  color:var(--fg-dim);background:var(--bg-2);border:1px solid var(--line);border-radius:.5rem;
  touch-action:manipulation}
.cdice:hover,.cdice:focus-visible{color:var(--fg);border-color:var(--accent-dim);outline:none}
.cdice:disabled{opacity:.5;cursor:default}
.cdice.rolling{animation:cdroll .8s linear infinite}
@keyframes cdroll{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion: reduce){ .cdice.rolling{animation:none;opacity:.6} }
.cerr{margin:.3rem 0 0;font-size:.78rem;color:var(--bad)}
.crow2{display:grid;grid-template-columns:1fr 1fr;gap:.65rem}
@media (max-width:520px){ .crow2{grid-template-columns:1fr} }
.cbtns{display:flex;gap:.5rem;margin:1rem 0 0;flex-wrap:wrap;align-items:center}
.cbtns .grow{flex:1 1 auto}
.cbtns .workbench{font-size:.78rem}
#csave{min-height:2.6rem;padding:.5rem 1.2rem;font:inherit;font-weight:600;cursor:pointer;
  color:var(--on-accent);background:var(--accent);border:0;border-radius:.6rem}
#csave:disabled{opacity:.6;cursor:default}
#ccancel{min-height:2.6rem;padding:.5rem 1rem;font:inherit;cursor:pointer;
  color:var(--fg);background:var(--bg-2);border:1px solid var(--line);border-radius:.6rem}
#adgo{min-height:2.6rem;padding:.5rem 1.2rem;font:inherit;font-weight:600;cursor:pointer;
  color:var(--on-accent);background:var(--accent);border:0;border-radius:.6rem}
#adgo:disabled,#adjust:disabled{opacity:.6;cursor:default}
/* the second door: same weight of decision, quieter chrome, because most of
   the time you do want them to have been here all along */
#adjust{min-height:2.6rem;padding:.5rem 1.1rem;font:inherit;font-weight:600;cursor:pointer;
  color:var(--fg);background:var(--bg-2);border:1px solid var(--accent-dim);border-radius:.6rem}
.adhint{margin:.7rem 0 0;font-size:.74rem;line-height:1.55;color:var(--fg-far)}
.adhint b{color:var(--fg-dim);font-weight:700}
/* what the + is actually going to do, said before it does it */
.addwhy{margin:0 0 1rem;font-size:.8rem;line-height:1.5;color:var(--fg-dim)}
/* the passes as they land: four model calls is long enough that silence reads
   as a hung page */
.adlog{max-height:9rem;overflow-y:auto;margin:.9rem 0 0;padding:.5rem .6rem;font-size:.76rem;
  line-height:1.5;color:var(--fg-dim);background:var(--bg-2);border:1px solid var(--line-2);
  border-radius:.5rem}
.adline.bad{color:var(--bad)}

/* A quiet report at the bottom of the screen, for saves and refusals. */
#ptoast{position:fixed;left:50%;bottom:max(1.2rem,env(safe-area-inset-bottom));transform:translateX(-50%);
  z-index:60;display:none;max-width:min(85vw,26rem);padding:.55rem 1rem;text-align:center;
  font-size:.85rem;color:var(--fg);background:var(--bg-2);border:1px solid var(--line);
  border-radius:.6rem;box-shadow:var(--shadow-lift)}

/* The sidebar already says XERIC and carries the quick buttons, so wide
   screens drop main's copies of THOSE. The clock stays: it is the one thing on
   the page everybody reads, and a clock you have to open a drawer to see is a
   clock the app does not have. */
@media (min-width:761px){
  /* main's top is brand-only now, and the sidebar's brand is already on
     screen: an empty bar would just be a rule under nothing */
  #app > main > .top{display:none}
  #app > main .screen > .pbar .pnav{display:none}
}

@media (max-width:760px){
  #app{grid-template-columns:1fr}
  .burger{display:flex}
  /* The drawer is an OVERLAY, so it starts at the top of the window and covers
     the bar it was opened from — the burger is behind it and the scrim is how
     you get back. Hence z-index above the bar's, and the safe-area padding the
     docked sidebar hands back to the bar. */
  #side{position:fixed;left:0;top:0;bottom:0;z-index:12;width:min(19rem,86vw);height:100dvh;
    padding-top:calc(.9rem + env(safe-area-inset-top));
    transform:translateX(-102%);transition:transform .2s ease-out;box-shadow:var(--shadow-lift)}
  body.side-open #side{transform:none}
  #sidescrim{z-index:11}
}
@media (prefers-reduced-motion: reduce){ #side{transition:none} }

/* ------------------------------------------------------------- the app bar */
/* Two rows: what time it is, then what you can do about it. The clock is above
   the buttons because it is not a control — it is the thing every control is
   relative to. You skip FROM somewhere, and you are texting somebody AT an hour
   of their day.
   Sticky and safe-area aware, so it survives a notch and stays put while the
   page under it moves. */
/* Sticks under the chip bar rather than behind it: the bar owns the top of the
   window now, and a thread header pinned at 0 would slide out of sight beneath
   the very faces it belongs to. The safe-area inset is the bar's job now too. */
.pbar{position:sticky;top:var(--bar-h);z-index:6;display:block;
  margin:0 0 .2rem;padding:.5rem 0 .45rem;background:var(--bg);border-bottom:1px solid var(--line-2)}
.pclock{display:flex;align-items:baseline;gap:.5rem;flex-wrap:wrap}
.pbar .pname{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* The button row. Fingertip-sized, and it scrolls sideways rather than wrapping
   — a bar that gets taller because a xeric has a long name is a bar that moves
   the page under somebody's thumb while they are reading it. */
.pnav{display:flex;gap:.4rem;margin:.5rem 0 0;overflow-x:auto;overscroll-behavior-x:contain;
  -webkit-overflow-scrolling:touch;scrollbar-width:none}
.pnav::-webkit-scrollbar{display:none}
.nbtn{flex:0 0 auto;min-height:2.4rem;padding:.4rem .85rem;font:inherit;font-size:.85rem;
  color:var(--fg);background:var(--bg-2);border:1px solid var(--line);border-radius:1.2rem;
  cursor:pointer;white-space:nowrap;touch-action:manipulation;
  -webkit-tap-highlight-color:transparent;
  transition:border-color .14s ease-out,background .14s ease-out}
.nbtn:hover,.nbtn:focus-visible{border-color:var(--accent-dim);background:var(--bg-3);outline:none}
.nbtn:active{background:var(--bg-3)}

/* What the bar scrolls to must not arrive underneath the bar. */
#times,#where,#cast,#story,#fate,#past{scroll-margin-top:7.5rem}

/* THE THREAD IS THREE ROWS: a bar that stays, a list that scrolls, and a
   composer at the bottom. Laid out as a document it collapsed upward — with no
   messages yet the composer floated in the middle of an empty screen, which is
   the one arrangement no messaging app has ever had.

   min-height:0 on the list is what actually makes it scroll: a flex child
   defaults to min-height:auto and refuses to shrink below its content, so the
   list grows the page instead of scrolling inside it and the composer is pushed
   off the bottom. */
.screen[data-screen=thread].live{display:flex;flex-direction:column;
  min-height:calc(var(--app-h,100dvh) - 8.5rem)}
.screen[data-screen=thread] .msgs{flex:1 1 auto;min-height:0;overflow-y:auto;
  padding-right:.15rem}
.screen[data-screen=thread] .composer{margin-top:auto}

/* -------------------------------------------------------- phone-app chrome */
/* The message list never triggers the browser's own pull-to-refresh, a tap
   never waits to see whether it was a double-tap, and the composer's field is
   16px because anything smaller makes iOS zoom the page when it is focused. */
.msgs{overscroll-behavior:contain;-webkit-overflow-scrolling:touch}
.composer textarea{font-size:16px}
.composer .btn,.nbtn{touch-action:manipulation}

/* WHILE THE KEYBOARD IS UP. Browsers that resize the layout are handled by
   interactive-widget=resizes-content in the head; the ones that OVERLAY it are
   handled by the script in play.php pinning --app-h. Either way the
   home-indicator padding goes, because the keyboard is covering that zone. */
body.kb-open .composer{padding-bottom:.6rem}

/* ------------------------------------------------- the phone, specifically */
/* THE CODE YOU SCAN, and the address under it for anybody whose camera is
   being difficult. The SVG is engine-built and sized here, never inline. */
.xcqrbox{margin:0 0 12px;text-align:center}
.xcqrbox svg{width:min(240px,60vw);height:auto;border-radius:.5rem}
.xcqrurl{display:block;margin:.4rem 0 0;font-size:.76rem;word-break:break-all;color:var(--accent)}

/* Every SELECT on a touch screen is 16px for the same reason the composer is:
   below that, iOS zooms the whole page on focus and never zooms back. The cog
   is where the model picker, the shape and the pace all live, so this is the
   difference between a settings press and a page somebody has to pinch out of. */
@media (max-width:760px){
  select,.cvsel,#xcshape{font-size:16px}
  /* The cog is a full-height sheet on a phone: a 38rem card centred in a
     420px window with a dozen rows in it scrolls the PAGE behind the overlay,
     which is how a modal ends up with its Save button unreachable. */
  #coverlay{align-items:stretch}
  #cmodal{max-height:100dvh;overflow-y:auto;border-radius:0;margin:0;
    padding-bottom:calc(1rem + env(safe-area-inset-bottom));-webkit-overflow-scrolling:touch}
  /* Cog rows stack rather than squeeze: a button plus a sentence of hint on
     one line at 390px leaves four characters for the sentence. */
  .xcrow{flex-direction:column;align-items:flex-start;gap:.35rem}
  .xchint{flex:1 1 auto}
  /* Photos never push the bubble past the window. */
  .mphoto{max-height:220px}
  .cportrait img{max-height:180px}
}
/* The pin the script sets, with the ordinary full height as the fallback for
   every browser and every moment where no keyboard is covering anything. */
body{min-height:var(--app-h,100dvh)}
.pname{margin:0;font-size:clamp(1.35rem,5.5vw,1.8rem);font-weight:700;letter-spacing:-.01em;line-height:1.15}
.clock{display:inline-flex;align-items:center;gap:.45rem;border:1px solid var(--accent-dim);border-radius:.5rem;
  padding:.2rem .55rem;font-size:.8rem;color:var(--accent);font-variant-numeric:tabular-nums;white-space:nowrap}
.clock .ph{width:.5rem;height:.5rem;border-radius:50%;background:var(--accent)}
/* Stopped: the light goes out and the chip stops being the accent colour. The
   dot is the thing people read without reading, so it has to be the thing that
   changes. */
.clock.stopped{border-color:var(--line);color:var(--fg-dim)}
.clock.stopped .ph{background:var(--dot)}
.whoami{font-size:.88rem;color:var(--fg-dim);margin:.15rem 0 .3rem}
/* who you are among: its own line, because it is a second fact and reads as
   one long run-on when it trails the job title. */
.whorel{display:block;margin-top:.15rem}
.whoami b{color:var(--fg);font-weight:600}
/* whose world this is, and what is left of the demo's day. One grey line: a
   visitor came here to meet somebody, not to read a quota. */
.yours{font-size:.8rem;color:var(--fg-dim);margin:0 0 1.1rem;line-height:1.5}
.yours b{color:var(--accent);font-weight:600}
.yours .budget{display:block;color:var(--fg-far)}

/* the time control -------------------------------------------------- */
.timecard{border:1px solid var(--accent-dim);border-radius:.7rem;background:var(--sel);padding:.85rem .9rem;margin:0 0 1.4rem}
.timecard h2{margin:0 0 .1rem}
.timecard .why{font-size:.85rem;color:var(--fg-dim);margin:0 0 .7rem}
.times{display:grid;grid-template-columns:1fr;gap:.5rem}
@media (min-width:26rem){.times{grid-template-columns:repeat(3,1fr)}}
/* A stopped world's time control is disabled, and has to LOOK it — a live-looking
   button that refuses is worse than one that plainly cannot be pressed. */
.tbtn[disabled]{opacity:.42;cursor:default;transform:none}
.tbtn{appearance:none;cursor:pointer;font:inherit;text-align:left;min-height:3.1rem;
  background:var(--bg-2);color:var(--fg);border:1px solid var(--line);border-radius:.55rem;padding:.55rem .7rem}
.tbtn .tl{display:block;font-weight:600;font-size:.95rem}
.tbtn .ts{display:block;font-size:.76rem;color:var(--fg-dim);font-variant-numeric:tabular-nums}
.tbtn:active{transform:translateY(1px)}
.tbtn[disabled]{opacity:.45;cursor:default}
.tbtn.on{border-color:var(--accent);color:var(--accent)}

/* the story the world is carrying ------------------------------------- */
/* Deliberately not a card: the time control above it is the only card on this
   page, and a mystery banner would win a fight it is not meant to be in. The
   left rule is the whole decoration until it closes. */
.story{border-left:2px solid var(--accent-dim);padding:.1rem 0 .1rem .75rem;margin:0 0 1.2rem}
.story .sh{display:flex;gap:.5rem;align-items:baseline;flex-wrap:wrap}
.story .sk{font-size:.7rem;letter-spacing:.11em;text-transform:uppercase;color:var(--fg-dim)}
.story .sp{font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--accent);
  border:1px solid var(--accent-dim);border-radius:.4rem;padding:0 .35rem}
.story .sn{font-weight:600;margin:.2rem 0 0}
.story .sl{font-size:.87rem;color:var(--fg-dim);margin:.15rem 0 0}
.story .sm{display:flex;gap:.3rem;margin:.45rem 0 0}
.story .sm i{width:.42rem;height:.42rem;border-radius:50%;background:var(--dot)}
.story .sm i.on{background:var(--accent)}
/* Closed is the one moment this strip is allowed to be loud — and the loud part
   is the sentence saying the world did not close with it. */
.story.done{border-left-color:var(--accent);background:var(--sel);border-radius:0 .5rem .5rem 0;
  padding:.6rem .8rem .65rem .75rem}
.story.done .sp{color:var(--bg);background:var(--accent);border-color:var(--accent)}
.story .sw{font-size:.85rem;color:var(--fg-dim);margin:.5rem 0 0}

/* A refusal is not a fault. The age floor saying no is this engine working
   exactly as designed, and drawing it in the outage colour taught somebody to
   read a rule as a breakage — and to press send again, which will refuse
   again. Same shape as every other note, none of the alarm. */
.note.rule{border-color:var(--accent-dim);color:var(--fg)}

/* what a turn moved, and where a story ended --------------------------- */
.msgs .moved{justify-content:center}
.msgs .moved .b{max-width:100%;background:none;border:0;font-size:.79rem;color:var(--accent-dim);
  text-align:center;padding:.15rem 0}
.msgs .ended{display:block}
.ended .ec{border:1px solid var(--accent);border-radius:.6rem;background:var(--sel);padding:.85rem .9rem;
  animation:in .25s ease-out}
.ended .eh{font-size:.7rem;letter-spacing:.11em;text-transform:uppercase;color:var(--accent);margin:0 0 .25rem}
.ended .en{font-weight:600;font-size:1.02rem}
.ended .ep{font-size:.9rem;margin:.35rem 0 0}
.ended .ew{font-size:.85rem;color:var(--fg-dim);margin:.5rem 0 0}

/* cast --------------------------------------------------------------- */
.cast{list-style:none;margin:0;padding:0}
.cast li{margin:0 0 .55rem}
.person{display:flex;width:100%;gap:.7rem;align-items:flex-start;text-align:left;cursor:pointer;font:inherit;
  background:var(--bg-2);color:var(--fg);border:1px solid var(--line);border-radius:.6rem;padding:.7rem .8rem}
.person:active{transform:translateY(1px)}
.person.lit{border-color:var(--accent)}
.person .pdot{flex:0 0 auto;width:.55rem;height:.55rem;border-radius:50%;background:var(--dot);margin-top:.5rem}
.person .pdot.on{background:var(--accent);box-shadow:0 0 0 3px var(--glow)}
/* display:block, and it is load-bearing. These three are <span>s carrying
   vertical margins, and a vertical margin on an inline box does nothing at all —
   so the name, the one-liner and the location ran together into "…a rusted
   wrench and a prayer.off shift", on every row, for as long as this screen has
   existed. Nothing in the markup was wrong and nothing in it looked wrong. */
.person .pn{display:block;font-weight:600}
.person .po{display:block;font-size:.87rem;color:var(--fg-dim);margin:.1rem 0 0}
.person .pw{display:block;font-size:.78rem;color:var(--accent-dim);margin:.2rem 0 0}
.person .pw.off{color:var(--fg-dim)}

/* The dead. Dimmed and marked, never struck through and never a skull: this is a
   roster of people somebody knows, and the row still opens — reading back the
   last thing they said to you is the entire point of keeping the thread. The
   name stays at full weight for the same reason. */
.person.gone{opacity:.62}
.person.gone .pn{opacity:1}
.person .pdot.x{background:none;box-shadow:none;width:auto;height:auto;margin-top:.15rem;
  color:var(--fg-dim);font-size:1rem;line-height:1}
.person .pw.rip{color:var(--fg-dim);font-style:italic}
.gonebar{margin:.35rem 0 0;font-size:.82rem;color:var(--fg-dim)}
.gonebar b{color:var(--fg);font-weight:600}

/* what death means here */
.frule{margin:0 0 .6rem}
.flist{list-style:none;margin:0 0 .7rem;padding:0}
.flist li{display:flex;gap:.7rem;align-items:center;margin:0 0 .4rem;padding:.5rem .7rem;
  background:var(--bg-2);border:1px solid var(--line);border-radius:.5rem}
.flist .fn{display:block;font-weight:600}
.flist .fh{display:block;font-size:.82rem;color:var(--fg-dim);font-style:italic}
.flist li>*:last-child{margin-left:auto}
.fperm{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--fg-dim)}
.fkill{display:flex;flex-wrap:wrap;gap:.45rem;margin:0 0 .6rem}
.fkill select,.fkill input{font:inherit;font-size:.9rem;color:var(--fg);background:var(--bg-2);
  border:1px solid var(--line);border-radius:.45rem;padding:.45rem .55rem}
.fkill input{flex:1 1 9rem;min-width:0}
.fworld{display:flex;flex-wrap:wrap;gap:.45rem}
.btn.small{padding:.45rem .7rem;font-size:.85rem}
.btn.warnbtn{border-color:var(--accent-dim)}

/* where you are, and what a walk costs */
.wnow{margin:0 0 .7rem;font-size:.95rem}
.wnow b{font-weight:600}
.wnow>*+*,.wd>*+*{margin-left:.55rem}
.wo{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--accent)}
.wc,.wq{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--fg-dim)}
.ww{font-size:.82rem;color:var(--fg-dim)}
.wgo{list-style:none;margin:0;padding:0}
.wgo li{margin:0 0 .45rem}
.goto{display:flex;width:100%;gap:.7rem;align-items:center;text-align:left;cursor:pointer;font:inherit;
  color:inherit;background:var(--bg-2);border:1px solid var(--line);border-radius:.5rem;padding:.55rem .7rem}
.goto:active{transform:translateY(1px)}
.goto[disabled]{opacity:.5;cursor:default;transform:none}
.goto .wm{flex:0 0 3.6rem;font-size:.8rem;color:var(--accent);text-align:right;font-variant-numeric:tabular-nums}
.goto .wn{display:block;font-weight:600}
.goto .wd{display:block;margin:.15rem 0 0}
.goto.ghost .wm{color:var(--fg-dim)}
.person .pgo{margin-left:auto;color:var(--fg-dim);align-self:center}

/* thread ------------------------------------------------------------- */
/* ------------------------------------------------------------- the thread */
/* A message row is an avatar and a bubble. Outgoing rows are the same row
   mirrored, which is why there is one rule for the shape and one for the side —
   two sets of bubble geometry drift apart the first time either is touched. */
.msgs{margin:.2rem 0 0;padding:0;list-style:none}
.msgs li{margin:0 0 .7rem;display:flex;align-items:flex-end;gap:.45rem}
.msgs .b{max-width:min(88%,42rem);border-radius:1.1rem;padding:.55rem .8rem;
  font-size:.97rem;line-height:1.5;white-space:pre-wrap}

/* WHO SAID IT, as a colour and two letters worked out from the name — the same
   face every time, with nothing stored and no picture fetched. It is what makes
   a thread read as a conversation rather than as a transcript. */
.av{flex:0 0 auto;width:1.75rem;height:1.75rem;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:.66rem;font-weight:700;letter-spacing:.02em;
  color:hsl(var(--hue) 45% 22%);background:hsl(var(--hue) 55% 82%);
  border:1px solid hsl(var(--hue) 40% 70%);user-select:none}
:root[data-theme="dark"] .av{color:hsl(var(--hue) 60% 88%);background:hsl(var(--hue) 32% 30%);
  border-color:hsl(var(--hue) 30% 42%)}

.msgs .them .b{background:var(--bg-3);border:1px solid var(--line);
  border-bottom-left-radius:.3rem}
.msgs .me{flex-direction:row-reverse}
.msgs .me .b{background:var(--sel);border:1px solid var(--accent-dim);
  border-bottom-right-radius:.3rem}
/* The world's own voice: never a bubble, never on a side, and italic, because
   the one failure that matters in this lane is a player reading "it was not
   kids that night" as a sentence somebody in the cast texted them. */
.msgs .narr{justify-content:center}
.msgs .narr .b{max-width:100%;background:none;border:0;color:var(--fg-dim);font-size:.85rem;
  font-style:italic;text-align:center;padding:.35rem 1.2rem}
.msgs .st{display:block;font-size:.68rem;color:var(--fg-dim);margin:.25rem 0 0;font-variant-numeric:tabular-nums}
/* THE NARRATOR, after the people and not among them. A dashed edge because it
   is not somebody in this xeric — it is the thing you ask about them. */
.narrbox{margin-top:.6rem}
.person.narrator{border-style:dashed;background:transparent}
.person.narrator .pn{color:var(--accent)}
.person.addperson{border-style:dashed;background:transparent}
.person.addperson .pn{color:var(--fg-dim)}
.person.addperson:hover .pn{color:var(--accent)}

/* The suggestion, above the field it would go into. Dashed, because it is not
   yours yet. */
.ghost{display:flex;align-items:baseline;gap:.6rem;flex-wrap:wrap;
  margin:0 0 .4rem;padding:.5rem .7rem;border:1px dashed var(--accent-dim);
  border-radius:.7rem;background:var(--bg-2)}
.gtext{flex:1 1 auto;min-width:0;font-size:.92rem;color:var(--fg-dim);font-style:italic}
.gkey{flex:0 0 auto;font-size:.7rem;letter-spacing:.04em;color:var(--fg-far);white-space:nowrap}

/* SOLID, NOT A FADE. A gradient lets the last line of the thread show through
   the field somebody is typing into, which reads as a rendering fault on a
   phone. It is a bar; bars are opaque. */
.composer{position:sticky;bottom:0;z-index:5;display:flex;gap:.5rem;align-items:flex-end;
  padding:.6rem 0 calc(.6rem + env(safe-area-inset-bottom));
  background:var(--bg);border-top:1px solid var(--line-2)}
.composer textarea{min-height:2.75rem;max-height:8rem;border-radius:1.25rem;
  padding:.65rem .9rem;resize:none;overflow-y:auto}
.composer .btn{border-radius:1.25rem;min-height:2.75rem}
.composer .btn{flex:0 0 auto}
.thinking{display:flex;gap:.45rem;align-items:center;color:var(--fg-dim);font-size:.88rem;margin:.2rem 0 .8rem}
.thinking i{width:.4rem;height:.4rem;border-radius:50%;background:var(--accent);animation:pulse 1.1s ease-in-out infinite}
.thinking i:nth-child(2){animation-delay:.18s}
.thinking i:nth-child(3){animation-delay:.36s}
/* It lives in the app bar now, so it is one of the bar's buttons — its own
   borderless-link styling was left over from when it sat above one. */
.back{margin:0}

/* the feed ------------------------------------------------------------ */
.feed{margin:0 0 1rem}
.fev{border:1px solid var(--line);border-radius:.6rem;background:var(--bg-2);padding:.8rem .85rem;margin:0 0 .8rem;
  animation:in .25s ease-out}
.fev .ft{font-weight:600;font-size:1.02rem;letter-spacing:-.01em}
.fev .fm{font-size:.76rem;color:var(--fg-dim);margin:.1rem 0 .5rem;font-variant-numeric:tabular-nums}
.fev .fm .spine{color:var(--warn)}
.fev .fp{font-size:.93rem;margin:0 0 .7rem}
.tk{font-size:.7rem;letter-spacing:.11em;text-transform:uppercase;color:var(--accent);margin:0 0 .4rem}
.takes{display:grid;gap:.5rem;grid-template-columns:1fr}
@media (min-width:30rem){.takes{grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))}}
.take{border-left:2px solid var(--accent-dim);padding:.1rem 0 .1rem .65rem}
.take .tn{font-size:.8rem;font-weight:600}
.take .tt{font-size:.87rem;color:var(--fg-dim)}
.ping{border:1px solid var(--accent);border-radius:.6rem;background:var(--sel);padding:.8rem .85rem;margin:0 0 .8rem;
  animation:in .25s ease-out}
.ping .pw{font-size:.7rem;letter-spacing:.11em;text-transform:uppercase;color:var(--accent);margin:0 0 .3rem}
.ping .pt{font-size:.97rem;white-space:pre-wrap}
.ping .pn{font-weight:600;margin:0 0 .2rem}

/* world state --------------------------------------------------------- */
.panel{border:1px solid var(--line);border-radius:.6rem;background:var(--bg-2);padding:.8rem .85rem;margin:0 0 1rem}
.panel .row{display:flex;gap:.5rem;padding:.3rem 0;border-bottom:1px solid var(--line-2);font-size:.87rem}
.panel .row:last-child{border-bottom:0}
.panel .k{flex:0 0 6.5rem;color:var(--fg-dim);font-size:.8rem;letter-spacing:.04em;text-transform:uppercase}
.panel .v{flex:1}
.panel code{font:inherit;color:var(--accent)}
.chips{display:flex;flex-wrap:wrap;gap:.3rem}
.chips code{border:1px solid var(--line);border-radius:.4rem;padding:.05rem .38rem;font-size:.82rem;
  white-space:nowrap}
.chips code.lethal{border-color:var(--accent-dim);color:var(--fg)}
.wlist{list-style:none;margin:0;padding:0}
.wlist a{display:block;border:1px solid var(--line);border-radius:.6rem;background:var(--bg-2);
  padding:.8rem .9rem;margin:0 0 .7rem;text-decoration:none;color:var(--fg)}
.wlist a:active{transform:translateY(1px)}
.wlist .n{font-weight:600}
.wlist .d{font-size:.9rem;color:var(--fg-dim);margin:.15rem 0 0}
.wlist .m{font-size:.76rem;color:var(--fg-dim);margin:.35rem 0 0}

/* the inspector, one tap from whatever it is about ------------------- */
.whylink{display:inline-block;margin:.3rem 0 0;font-size:.78rem;color:var(--accent-dim);
  text-decoration:underline;text-underline-offset:3px}
.fev .whylink{margin-top:.5rem}

/* long prose never pushes the page sideways, on any screen ------------ */
.fev,.evt,.msgs .b,.panel,.note,.take,.card{overflow-wrap:anywhere}
.evt .ft{font-weight:600}
.evt .fp{font-size:.92rem;margin:.3rem 0 0}
#past{margin:0 0 1rem}
CSS;
}

// ---------------------------------------------------------------------------
// The decision trail, kept
// ---------------------------------------------------------------------------

/**
 * Write one sweep's reasoning into the world it happened in.
 *
 * WHY THIS IS HERE AND NOT IN THE ENGINE. sweeps.php produces the trail as part
 * of deciding (that much is the engine's), but WHERE a trail is kept is a
 * property of an installation, not of a world: a private install with a terminal
 * open wants it on stdout, and the demo wants it answerable from a URL a week
 * later. So the engine hands it back and this decides to keep it — in the
 * world's own world_state table, keyed by the event id, in the database the
 * visitor actually plays against.
 *
 * Never fatal. A trail that could not be written costs an explanation, never an
 * event: the event and its memories are already committed by the time this runs.
 */
function xeric_play_keep_trail(PDO $db, array $event): void
{
    $id = (int)($event['id'] ?? 0);
    if ($id <= 0) return;
    try {
        xeric_world_state_set($db, 'why:event:' . $id, json_encode([
            'kind'      => (string)($event['kind'] ?? ''),
            'on_spine'  => (bool)($event['on_spine'] ?? false),
            'why'       => (string)($event['why'] ?? ''),
            'place'     => (string)($event['place'] ?? ''),
            'people'    => array_values(array_map('strval', (array)($event['participants'] ?? []))),
            'ms'        => (int)($event['usage']['ms'] ?? 0),
            'attempts'  => (int)($event['usage']['attempts'] ?? 1),
            'notes'     => array_values(array_map('strval', (array)($event['notes'] ?? []))),
            'trail'     => (array)($event['trail'] ?? []),
            'at'        => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        // The hour happened. Whether we wrote down why is our problem, not the world's.
    }
}

// ---------------------------------------------------------------------------
// Failures, said out loud
// ---------------------------------------------------------------------------

/**
 * A turn that went wrong, in a sentence somebody can act on.
 *
 * The engine's messages are precise and shaped for a log — "chat: Ruth did not
 * answer — llm: HTTP 500 — {"error":{"code":…}}". Every one of those is true and
 * not one of them is a thing to say to a person waiting for a reply, and the
 * tail of the worst of them is raw JSON, which is the one thing an error state
 * in this app may never be.
 *
 * Anything unrecognised falls through to a sentence that is still honest: it
 * says the turn did not happen, that nothing was written, and what to do.
 */
/**
 * Is this the age floor refusing, rather than anything going wrong?
 *
 * Matched on the two halves of the sentence xeric_age_refusal() has always
 * written — "refused —" and "may be sexual" — because that pairing is the stable
 * part of it and the name in the middle is not. Both halves are required: the
 * sweep refuses hours for several other reasons that also start "refused —", and
 * a fault miscounted as a rule would be a real outage told to shrug.
 */
function xeric_play_say_refused(string $raw): bool
{
    return str_contains($raw, 'refused, ') && str_contains($raw, 'may be sexual');
}

/**
 * Is this the world refusing because the person is dead?
 *
 * Kept apart from the age floor above even though both are rules rather than
 * faults, because the two say completely different things to the person holding
 * the phone. One is "this may never be written". The other is "you are too late".
 */
function xeric_play_say_dead(string $raw): bool
{
    return str_contains($raw, 'refused, ') && str_contains($raw, 'is dead and cannot answer');
}

function xeric_play_say_error(string $raw, string $name): string
{
    $r = trim($raw);
    $who = $name !== '' ? $name : 'they';

    // FIRST, because it is the one branch here that is not a failure. Everything
    // below tells somebody to try again, and this is the single case where
    // trying again is the wrong advice: the floor is deterministic and the same
    // message refuses the same way forever.
    //
    // Two things it has to say and one it must not. It has to say what the rule
    // actually is, and it has to say how NARROW the rule is — a child in this
    // engine is an ordinary character who is spoken to, argued with, believed or
    // not believed, and telling somebody "you cannot go near him" when the rule
    // is "nothing about him may be sexual" is its own kind of wrong. What it
    // must not do is read as an accusation: nothing was written, nothing was
    // recorded about the visitor, and the sentence says so.
    if (xeric_play_say_refused($r)) {
        $child = preg_match('/nothing involving (.+?), who is a child/u', $r, $m) === 1
            ? trim($m[1]) : 'somebody';
        return 'That one will not be sent. Nothing sexual may be written about ' . $child . ', who is a child '
            . 'in this xeric, not by you, not by the model, and not by the xeric itself. That is the whole of '
            . 'the rule: children here are ordinary people in an ordinary town, spoken to, argued with, '
            . 'believed or not believed, and holding whatever they are holding. Nothing was written down and '
            . 'nothing has been recorded about you. This message would be refused the same way every time, so '
            . 'it is worth saying something else instead.';
    }

    // The other branch here that is not a failure, and the other one where trying
    // again is wrong advice. Says what is still true — the thread is theirs and it
    // keeps — and does not offer to fix anything, because there is a rule about
    // whether this world gives people back and this is not the place that states it.
    // Nothing attached is not a fault and not a rule of the world — it is a
    // missing machine, and the only error on this screen whose answer is a link.
    if (str_contains($r, 'Nothing is attached') || str_contains($r, 'No machine is attached')) {
        return 'There is no machine attached to think with, so nobody can answer and every xeric you '
            . 'have is stopped where it stands. Nothing is lost, pick one and they start again on the '
            . 'exact second they stopped.';
    }

    if (xeric_play_say_dead($r)) {
        return $who . ' is dead, so that will not send. Everything either of you said is still here and '
            . 'still yours to read back.';
    }

    if (str_contains($r, 'answered with nothing usable') || str_contains($r, 'no completion in response')) {
        return $who . ' started to say something and it came out empty. That is the model having a bad '
            . 'moment, not her, say it again and she will answer. Nothing was written down.';
    }
    if (str_contains($r, 'cannot reach') || str_contains($r, 'timed out') || str_contains($r, 'timeout')) {
        return 'The model stopped answering part way through, so ' . $who . ' never got the message. '
            . 'It is usually loading something else, try again in a few seconds. Nothing was lost.';
    }
    if (preg_match('/HTTP (\d{3})/', $r, $m)) {
        $code = (int)$m[1];
        if ($code === 401 || $code === 403) {
            return 'The model refused the request outright (' . $code . '). On this demo that means the local '
                . 'endpoint is configured for a key it did not get, the owner needs to look, not you.';
        }
        if ($code === 429) {
            return 'The model is over its own rate limit. Nothing to do with your budget here, give it a '
                . 'minute and say it again.';
        }
        return 'The model answered with an error (' . $code . '), so nobody replied and nothing was written. '
            . 'Trying again is genuinely worth it; this one is usually transient.';
    }
    if (str_contains($r, 'could not store the turn')) {
        return 'She answered, and this machine could not write it down, which means the reply is gone rather '
            . 'than half-saved. The disk is the likely reason. Your xeric is intact.';
    }
    if (str_contains($r, 'nobody in') && str_contains($r, 'answers to')) {
        return 'Nobody in this xeric answers to that name any more. If somebody was rerolled while this page '
            . 'was open, reload it, the cast has changed under you.';
    }
    return 'That turn did not happen: ' . rtrim(preg_replace('/\s*\(.*$/s', '', preg_replace('/^chat:\s*/', '', $r) ?? $r) ?? $r, '. ')
        . '. Nothing was written, so saying it again costs you nothing but the wait.';
}

// ---------------------------------------------------------------------------
// The book — what book.php reads
// ---------------------------------------------------------------------------
//
// The world writing its own novel: the lived record grouped into world days,
// newest day first, each day holding what the USER could have seen of it —
// events as the commons prose they already are on every other screen, the
// user's own conversations reduced to scene lines, dreams when an hour was one,
// and what is owed (docs/CONSTRUCTS.md). Nothing below reads a memory or an
// interior: the book is the player's view bound in boards, and a page that
// printed what somebody privately took away from an hour would be the leak the
// walls exist to stop. When in doubt, less.
//
// Everything here is a read shaped for one page. It writes nothing, calls no
// model, and tolerates any database an older build left behind — a book that
// fell over on a column it did not recognise would be a diary that burns itself.

/** A page of the book, in days. */
const XERIC_BOOK_DAYS = 7;

/** The most days one URL may ask for — months of history is a shelf, not a page. */
const XERIC_BOOK_DAYS_MAX = 31;

/** The world's own timezone, with the same quiet fallback every formatter takes. */
function xeric_book_tz(array $t): DateTimeZone
{
    try { return new DateTimeZone((string)($t['user']['timezone'] ?? 'UTC')); }
    catch (Throwable $e) { return new DateTimeZone('UTC'); }
}

/**
 * "Thursday, the 14th of March" — a chapter heading, never a timestamp.
 *
 * No year on purpose: the era's year is a fact about the world (the sidebar's
 * calendar prints it once, via xeric_play_era_year), and a novel does not date
 * its chapters. The weekday and the month are the real clock's, so this heading
 * and every schedule in the world agree.
 */
function xeric_book_heading(array $t, int $epoch): string
{
    $d = (new DateTimeImmutable('@' . $epoch))->setTimezone(xeric_book_tz($t));
    return $d->format('l') . ', the ' . $d->format('jS') . ' of ' . $d->format('F');
}

/** "15:04" in the world's timezone — the hour a line of the day carries. */
function xeric_book_hour(array $t, int $epoch): string
{
    return (new DateTimeImmutable('@' . $epoch))->setTimezone(xeric_book_tz($t))->format('H:i');
}

/**
 * Which days one URL asks for, newest first, and where the page turns are.
 *
 * ?from=YYYY-MM-DD names the NEWEST day on the page and ?days= how far it reads
 * back; absent, the page is the last XERIC_BOOK_DAYS lived days ending today.
 * A `from` in the future is today — the book prints what has been lived, and
 * asking it for next week is asking it to make something up.
 *
 * `earlier` and `later` are `from` values for the neighbouring pages, or '' at
 * either cover. Earlier is only offered while there is still material back
 * there to read (the earliest stamped event or message), so the pager cannot
 * walk somebody into a century of blank days one week at a time.
 *
 * @return array{days:array<int,array{date:string,start:int,end:int}>,
 *               today:string,from:string,n:int,earlier:string,later:string}
 */
function xeric_book_range(array $t, PDO $db, array $now, string $from = '', int $days = 0): array
{
    $tz    = xeric_book_tz($t);
    $today = (new DateTimeImmutable('@' . (int)($now['epoch'] ?? 0)))->setTimezone($tz)->setTime(0, 0);
    $n     = $days > 0 ? min($days, XERIC_BOOK_DAYS_MAX) : XERIC_BOOK_DAYS;

    $top = $today;
    if ($from !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        if ($d instanceof DateTimeImmutable && $d < $today) $top = $d;
    }

    $list = [];
    $cur  = $top;
    for ($i = 0; $i < $n; $i++) {
        // The bounds are DATES, not midnight plus 86400: on the two nights a
        // year the offset moves, a day is 23 or 25 hours long, and an hour of
        // somebody's evening falling between two chapters is the kind of bug a
        // reader notices before a test does.
        $list[] = ['date' => $cur->format('Y-m-d'), 'start' => $cur->getTimestamp(),
                   'end' => $cur->modify('+1 day')->getTimestamp()];
        $cur = $cur->modify('-1 day');
    }

    // The earliest thing anybody wrote down, so the pager knows where the story
    // actually begins. Both stamped tables, tolerating either being empty.
    $first = null;
    foreach (['SELECT MIN(world_epoch) m FROM events',
              'SELECT MIN(world_epoch) m FROM messages WHERE world_epoch IS NOT NULL'] as $q) {
        try { $v = $db->query($q)->fetchAll()[0]['m'] ?? null; } catch (Throwable $e) { $v = null; }
        if ($v !== null && $v !== '' && ($first === null || (int)$v < $first)) $first = (int)$v;
    }

    $oldest  = $list[count($list) - 1];
    $earlier = ($first !== null && $first < $oldest['start'])
        ? (new DateTimeImmutable('@' . $oldest['start']))->setTimezone($tz)->modify('-1 day')->format('Y-m-d')
        : '';
    $later = '';
    if ($top < $today) {
        $next  = $top->modify('+' . $n . ' days');
        $later = ($next > $today ? $today : $next)->format('Y-m-d');
    }

    return ['days' => $list, 'today' => $today->format('Y-m-d'), 'from' => $top->format('Y-m-d'),
            'n' => $n, 'earlier' => $earlier, 'later' => $later];
}

/**
 * Everything that happened between two moments, oldest first — a chapter reads
 * forward even though the book reads back. Same decode as xeric_events_recent,
 * bounded by the day instead of by a count, which is what keeps a world with
 * months behind it from arriving as megabytes.
 */
function xeric_book_events_between(PDO $db, int $a, int $b): array
{
    $st = $db->prepare('SELECT * FROM events WHERE world_epoch >= ? AND world_epoch < ? ORDER BY world_epoch, id');
    $st->execute([$a, $b]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['participants'] = $r['participants'] !== null ? (json_decode((string)$r['participants'], true) ?: []) : [];
        $r['on_spine'] = (int)($r['on_spine'] ?? 0) === 1;
    }
    return $rows;
}

/**
 * What kind of hour the sweep called an event, read off the decision trail
 * why.php keeps. '' for seed history (nothing chose it) and for a trail that
 * does not say. The book only asks so a dream can be set in its own register —
 * the trail's REASONING stays on the inspector, where the owner reads it as an
 * owner rather than as a reader.
 */
function xeric_book_event_kind(PDO $db, int $eventId): string
{
    $raw = xeric_world_state_get($db, 'why:event:' . $eventId);
    if ($raw === null) return '';
    $why = json_decode($raw, true);
    return is_array($why) ? (string)($why['kind'] ?? '') : '';
}

/**
 * The user's conversations in a window, reduced to scene lines: who, and when.
 *
 * A BOOK IS NOT A CHAT LOG. The transcripts are the threads' to keep and the
 * player has read them once already; what the day's page owes is the fact that
 * a scene happened — "you and Marta talked, ten to eleven" — which is exactly
 * what a novel keeps of most conversations. So this counts lines and keeps
 * hours and returns not one sentence of what was said.
 *
 * The narrator's thread is not a scene: asking the machine where things stand
 * is reading the book, not living in the world, and a book that recorded its
 * own being read would never end.
 *
 * @return array<int,array{handle:string,name:string,first:int,last:int,yours:int,theirs:int}>
 */
function xeric_book_scenes(array $t, PDO $db, int $a, int $b): array
{
    $st = $db->prepare('SELECT c.handle AS handle, m.role AS role, m.world_epoch AS we
                          FROM messages m JOIN conversations c ON c.id = m.conversation_id
                         WHERE m.world_epoch >= ? AND m.world_epoch < ?
                         ORDER BY m.world_epoch, m.id');
    $st->execute([$a, $b]);
    $rows = $st->fetchAll();

    $by = [];
    foreach ($rows as $r) {
        $h = (string)$r['handle'];
        if ($h === '' || $h === XERIC_NARRATOR) continue;
        $we = (int)$r['we'];
        if (!isset($by[$h])) {
            $n = xeric_world_name($t, $h);
            $by[$h] = ['handle' => $h, 'name' => $n !== '' ? $n : $h,
                       'first' => $we, 'last' => $we, 'yours' => 0, 'theirs' => 0];
        }
        $by[$h]['first'] = min($by[$h]['first'], $we);
        $by[$h]['last']  = max($by[$h]['last'], $we);
        $role = (string)$r['role'];
        // 'character' is the schema's word and 'assistant' an older build's; a
        // narrator line inside a thread is the world talking, which is neither
        // side of the scene and counts for nobody.
        if ($role === 'user') $by[$h]['yours']++;
        elseif ($role !== 'narrator') $by[$h]['theirs']++;
    }

    // A window that only ever heard the world's own voice held no scene at all.
    return array_values(array_filter($by, fn(array $s): bool => $s['yours'] + $s['theirs'] > 0));
}

/**
 * One expectation arc's value, believed only as far as it parses.
 *
 * The first-class shape is engine/constructs.php's, which landed the same day
 * this page did: {what, quote, when_said, due, formed, state} with state one of
 * open | missed | repaired | hardened (xeric_expect_form / _tick / _repair).
 * The fallbacks under it are deliberate slack — a sibling construct, an older
 * db, or a schema that moved gets read as far as it is honest to and SKIPPED
 * past that, because a book that guessed at what somebody owes somebody would
 * be worse than one page short.
 *
 * @return array{what:string,quote:string,due:?int,status:string}|null
 *         null = not renderable; status is open|missed|repaired|hardened
 */
function xeric_book_expect_parse(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') return null;

    $v = json_decode($raw, true);
    if (is_string($v)) $v = ['what' => $v];
    if (!is_array($v)) {
        // Not JSON. A sentence is a usable coarse state on its own; a bare
        // number or a lone word is a counter wearing the wrong key prefix.
        if (preg_match('/[a-z]/i', $raw) !== 1 || !str_contains($raw, ' ')) return null;
        $v = ['what' => $raw];
    }

    $what = '';
    foreach (['what', 'text', 'promise', 'line', 'said', 'coarse'] as $k) {
        if (is_string($v[$k] ?? null) && trim((string)$v[$k]) !== '') { $what = trim((string)$v[$k]); break; }
    }
    $quote = is_string($v['quote'] ?? null) ? trim((string)$v['quote']) : '';
    if ($what === '' && $quote === '') return null;

    $due = null;
    foreach (['due', 'due_epoch', 'when', 'by', 'epoch', 'at'] as $k) {
        if (is_numeric($v[$k] ?? null) && (int)$v[$k] > 0) { $due = (int)$v[$k]; break; }
    }

    $s = strtolower(trim((string)($v['state'] ?? $v['status'] ?? '')));
    if ($s === '') {
        if (!empty($v['missed'])) $s = 'missed';
        elseif (!empty($v['kept']) || !empty($v['met'])) $s = 'kept';
    }
    if (in_array($s, ['open', 'missed', 'repaired', 'hardened'], true)) {
        $status = $s;
    } elseif (str_contains($s, 'miss') || str_contains($s, 'broke')) {
        $status = 'missed';
    } elseif (str_contains($s, 'repair') || str_contains($s, 'explain') || str_contains($s, 'mend')) {
        $status = 'repaired';
    } elseif (str_contains($s, 'kept') || str_contains($s, 'met') || str_contains($s, 'done')
        || str_contains($s, 'closed') || str_contains($s, 'honour') || str_contains($s, 'honor')) {
        // A kept promise is an ordinary memory now — not the ledger's to print.
        return null;
    } elseif ($s === '') {
        $status = 'open';
    } else {
        // A state this page has never heard of: render it only if it is still
        // plainly ahead of us, and let a past one go unclaimed rather than
        // called a miss on a guess.
        if ($due === null) return null;
        $status = 'open';
    }

    return ['what' => $what, 'quote' => $quote, 'due' => $due, 'status' => $status];
}

/**
 * Every expectation this world is carrying that the book can honestly print.
 *
 * Read across all handles because the arc lives in the LISTENER's row (the
 * person expecting you — engine/constructs.php, v1 scope: they all point at
 * the user). A database from before the construct existed has no rows and no
 * opinion, which is the whole defensive posture: zero arcs, a schema that
 * differs, a value that will not parse — each is a quiet absence, never an
 * error on a page.
 *
 * @return array<int,array{handle:string,key:string,what:string,quote:string,due:?int,status:string}>
 */
function xeric_book_expectations(PDO $db): array
{
    try {
        $st = $db->prepare("SELECT handle, key, value FROM arcs WHERE key LIKE 'expect.%' ORDER BY handle, key");
        $st->execute();
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $p = xeric_book_expect_parse((string)$r['value']);
        if ($p === null) continue;
        $out[] = ['handle' => (string)$r['handle'], 'key' => (string)$r['key']] + $p;
    }
    return $out;
}

/**
 * The sentence the book prints for one expectation — the reader's own promise,
 * in the second person, because the ledger's v1 scope is promises made TO the
 * cast BY the person reading this page. The verbatim quote wins when the arc
 * kept one ("You told Thi: 'I'll be at the market Saturday morning'"); the
 * label stands in when it did not; a foreign shape falls back to whatever
 * sentence it carried.
 */
function xeric_book_expect_line(array $t, array $x): string
{
    $name  = xeric_world_name($t, (string)($x['handle'] ?? ''));
    $quote = (string)($x['quote'] ?? '');
    $what  = (string)($x['what'] ?? '');
    if ($name !== '' && $quote !== '') return 'You told ' . $name . ': "' . $quote . '"';
    if ($name !== '' && $what !== '')  return 'You told ' . $name . ' — ' . $what . '.';
    return $what !== '' ? $what : ('"' . $quote . '"');
}

/**
 * The page, assembled: days newest first, each day's material oldest first.
 *
 * A day earns its heading by holding something — events, scenes, a dream, a
 * promise that broke that day — and days that hold nothing are simply not
 * chapters, because seven headings over seven silences is a calendar, not a
 * book. The one exception is today, which is kept whenever promises stand,
 * since "what is owed" belongs on the current page (docs/CONSTRUCTS.md) even
 * when nothing has happened yet this morning.
 *
 * Dreams are events the sweep filed as `dream` hours, pulled into their own
 * register. The proactive dream rung produces that kind since 2026-08-02 (the ladder weight in
 * proactive.pings is authored but unconsumed), so the register renders empty —
 * which is an absence, not a fault, and the day the engine dreams its first
 * dream this page prints it without being edited.
 *
 * @return array{days:array,pager:array{today:string,from:string,n:int,earlier:string,later:string}}
 */
function xeric_book_days(array $t, PDO $db, array $now, string $from = '', int $days = 0): array
{
    $range = xeric_book_range($t, $db, $now, $from, $days);

    $open = $missed = [];
    foreach (xeric_book_expectations($db) as $x) {
        if ($x['status'] === 'open') $open[] = $x;
        elseif ($x['status'] === 'missed' && $x['due'] !== null) $missed[] = $x;
        // A miss with no due epoch has no day to land on, and a kept promise is
        // an ordinary memory now — neither is the book's to place.
    }

    $out = [];
    foreach ($range['days'] as $day) {
        $items = [];
        foreach (xeric_book_events_between($db, $day['start'], $day['end']) as $e) {
            $dream = xeric_book_event_kind($db, (int)$e['id']) === 'dream';
            $items[] = ['kind' => $dream ? 'dream' : 'event',
                        'epoch' => (int)$e['world_epoch'], 'event' => $e];
        }
        foreach (xeric_book_scenes($t, $db, $day['start'], $day['end']) as $s) {
            $items[] = ['kind' => 'scene', 'epoch' => (int)$s['first'], 'scene' => $s];
        }
        foreach ($missed as $x) {
            if ($x['due'] >= $day['start'] && $x['due'] < $day['end']) {
                $items[] = ['kind' => 'miss', 'epoch' => (int)$x['due'], 'expect' => $x];
            }
        }
        usort($items, fn(array $a, array $b): int => [$a['epoch'], $a['kind']] <=> [$b['epoch'], $b['kind']]);

        $promises = $day['date'] === $range['today'] ? $open : [];
        if ($items === [] && $promises === []) continue;

        $out[] = ['date' => $day['date'], 'label' => xeric_book_heading($t, $day['start']),
                  'start' => $day['start'], 'end' => $day['end'],
                  'items' => $items, 'promises' => $promises];
    }

    return ['days' => $out, 'pager' => ['today' => $range['today'], 'from' => $range['from'],
                                        'n' => $range['n'], 'earlier' => $range['earlier'],
                                        'later' => $range['later']]];
}

// ---------------------------------------------------------------------------
// The watch — a duet taken one line at a time (watch.php reads all of this)
// ---------------------------------------------------------------------------

require_once XERIC_WEB_LIB . '/engine/duet.php';   // the internals this steps through

//
// engine/duet.php runs a whole scene in one call because a CLI can afford to
// stand there while it does. A browser cannot: play/pause only means anything
// if the page is the thing asking for the next line, and a walk-in only means
// anything if there is a moment between lines for somebody to speak into. So
// this is the stepping wrapper the duet's internals were split up for —
// xeric_duet_together admits the pair, xeric_duet_order deals the seats,
// xeric_duet_system/scene/messages assemble each call, and NOTHING here
// re-derives what any of those already decide. One law is inherited whole and
// stated here because everything below leans on it: the engine writes nothing
// until the close, so the transcript has to live somewhere that is not the
// database — a scene abandoned half-way must cost nothing and land nothing.
//
// WHERE THE TRANSCRIPT LIVES. A state file under the data dir, the job files'
// own discipline (boot.php): session-scoped, keyed by slug and pair, swept
// when stale, and deleted the moment the close lands. It is the ONLY record of
// the scene until then. The db sees one transaction at the end — the same
// event + diaries + trail the CLI's close writes — or nothing at all.
//
// THE WALK-IN IS THE DUET PLUS A VOICE, NOT THE ROOM. The player's line rides
// the transcript as a user turn (the duet's own mapping hands anything that is
// not the speaker's to the user seat), no model is called for it, and strict
// alternation carries on — the next scheduled speaker answers having seen the
// words. What it does NOT yet do is stand the player in the room: the duet's
// assembly seats the player nowhere on purpose (xeric_duet_messages passes
// null into the now-block's playerWhere), and that is the engine's sentence to
// change, not this file's. The position is recorded here (state + trail) so
// the day the seam opens, this surface already carries the answer.

/** The handle a walk-in line wears in the transcript. Never a cast handle. */
const XERIC_WATCH_PLAYER = '__you';

/** A scene file untouched this long is an abandoned tab, and is swept. */
const XERIC_WATCH_TTL = 21600;

function xeric_watch_dir(): string
{
    return xeric_web_dir((string)xeric_web_config()['data_dir'] . '/watch');
}

/**
 * One scene per (session, xeric, pair). Hashed rather than concatenated so a
 * handle never becomes filesystem input, and pair-order-blind so starting
 * (ruth, dot) and reloading into (dot, ruth) find the same scene.
 */
function xeric_watch_path(string $sid, string $slug, string $a, string $b): string
{
    $pair = [$a, $b];
    sort($pair);
    return xeric_watch_dir() . '/' . sha1($sid . '|' . $slug . '|' . implode('|', $pair)) . '.json';
}

function xeric_watch_read(string $path): ?array
{
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

function xeric_watch_write(string $path, array $s): void
{
    $s['at'] = time();
    @file_put_contents($path, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function xeric_watch_clear(string $path): void
{
    @unlink($path);
}

/** Old scenes are noise. Swept on the way past, never on a schedule. */
function xeric_watch_sweep(): void
{
    if (random_int(1, 10) !== 1) return;
    $cut = time() - XERIC_WATCH_TTL;
    foreach (glob(xeric_watch_dir() . '/*.json') ?: [] as $f) {
        if ((int)@filemtime($f) < $cut) @unlink($f);
    }
}

/**
 * A reload must not lose a running scene, and the client cannot name the pair
 * it forgot. The paths are hashed, so this walks the cast's pairs instead of
 * the directory — a few dozen sha1s, and only on the page render.
 */
function xeric_watch_find(string $sid, string $slug, array $t): ?array
{
    $handles = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '') $handles[] = $h;
    }
    for ($i = 0; $i < count($handles); $i++) {
        for ($j = $i + 1; $j < count($handles); $j++) {
            $s = xeric_watch_read(xeric_watch_path($sid, $slug, $handles[$i], $handles[$j]));
            if ($s !== null) return $s;
        }
    }
    return null;
}

/**
 * The rooms, from the same read the where panel draws (xeric_travel_map, which
 * is xeric_world_who_is_where underneath — the exact presence that will admit
 * or refuse the pair). Only people who could carry half a conversation are
 * offered: the dead are already gone from the map, and scenery and the
 * not-yet-arrived are dropped here for the duet's own reasons.
 *
 * @return array<int,array{key:string,name:string,who:array,pairs:array}>
 */
function xeric_watch_rooms(array $t, PDO $db, ?array $now = null): array
{
    $rooms = [];
    foreach ((array)xeric_travel_map($t, $db, $now)['places'] as $p) {
        $who = [];
        foreach ((array)$p['who'] as $row) {
            $h = (string)($row['handle'] ?? '');
            $c = xeric_world_character($t, $h);
            if ($c === null || !empty($c['out'])) continue;
            $who[] = ['handle' => $h, 'name' => (string)$row['name'],
                      'doing'  => trim((string)($row['doing'] ?? ''))];
        }
        if ($who === []) continue;
        $pairs = [];
        for ($i = 0; $i < count($who); $i++) {
            for ($j = $i + 1; $j < count($who); $j++) {
                $pairs[] = [$who[$i]['handle'], $who[$j]['handle']];
            }
        }
        $rooms[] = ['key' => (string)$p['key'], 'name' => (string)$p['name'],
                    'who' => $who, 'pairs' => $pairs];
    }
    return $rooms;
}

/**
 * Open a scene: the duet's own admission, seating and ceiling, and then a
 * state array instead of a running loop. WRITES NOTHING — not to the db (the
 * engine's law) and not to disk (the caller owns the file, so a refusal here
 * leaves no scene behind).
 *
 * The pre-checks mirror xeric_duet()'s door sentence for sentence rather than
 * calling it, because calling it would run the whole scene; the one refusal
 * that must be the engine's verbatim — two people not in a room together —
 * IS the engine's, thrown by xeric_duet_together itself.
 *
 * @throws RuntimeException in the duet's own words, nothing written
 */
function xeric_watch_start(array $w, string $a, string $b, ?array $now = null): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $now ??= xeric_clock_now($db, $t);
    $a = trim($a);
    $b = trim($b);

    if ($a === '' || $b === '') throw new RuntimeException('duet: a scene needs two people named');
    if ($a === $b) throw new RuntimeException("duet: a conversation needs two people, '$a' twice is one");

    $world = (string)($t['meta']['name'] ?? 'this xeric');
    $names = [];
    foreach ([$a, $b] as $h) {
        if (xeric_world_character($t, $h) === null) {
            if (xeric_world_fixture($t, $h) !== null) {
                throw new RuntimeException("duet: '$h' is scenery, a fixture cannot carry half a conversation");
            }
            throw new RuntimeException("duet: nobody in $world answers to '$h'");
        }
        $names[$h] = xeric_world_name($t, $h);
        if (xeric_is_dead($db, $h)) throw new RuntimeException(xeric_death_refusal('duet', $names[$h]));
        $c = xeric_world_character($t, $h);
        if (!empty($c['out'])) throw new RuntimeException('duet: refused, ' . $names[$h] . ' has not entered the story');
    }

    // The engine's geographic refusal, verbatim — a stale pair off the entry
    // page lands exactly here, and the sentence names both rooms.
    $room = xeric_duet_together($t, $db, $a, $b, $now);

    // The room's ceiling, the duet's own fold: one minor clamps both voices.
    $eff   = xeric_world_rating($t);
    $minor = false;
    foreach ([$a, $b] as $h) {
        $who = xeric_viewer($t, ['handle' => $h]);
        if ($who['is_minor']) { $minor = true; $eff = xeric_viewer_rating($eff, $who); }
    }

    $order = xeric_duet_order($db, $a, $b, []);

    return [
        'v'        => 1,
        'slug'     => (string)$w['slug'],
        'a'        => $a,
        'b'        => $b,
        'names'    => $names,
        'room'     => $room,
        'epoch'    => (int)$now['epoch'],
        'eff'      => $eff,
        'minor'    => $minor,
        'first'    => (string)$order['first'],
        'turns'    => (int)$order['turns'],
        'extended' => (bool)$order['extended'],
        'lines'    => [],
        'spoken'   => 0,
        'player'   => ['present' => false, 'where' => null, 'place' => '', 'name' => ''],
        'started'  => time(),
        'at'       => time(),
    ];
}

/** Whose line is due. Strict alternation over SPOKEN lines; walk-ins spend no turn. */
function xeric_watch_next(array $s): array
{
    $h = ((int)$s['spoken'] % 2 === 0)
        ? (string)$s['first']
        : ((string)$s['first'] === (string)$s['a'] ? (string)$s['b'] : (string)$s['a']);
    return ['handle' => $h, 'name' => (string)($s['names'][$h] ?? $h)];
}

/** The scene as the page may hold it. The transcript rides so a reload redraws whole. */
function xeric_watch_public(array $s): array
{
    return [
        'a'      => (string)$s['a'],
        'b'      => (string)$s['b'],
        'names'  => (array)$s['names'],
        'place'  => (string)$s['room']['place_name'],
        'why'    => (string)$s['room']['why'],
        'doing'  => (array)($s['room']['doing'] ?? []),
        'first'  => (string)$s['names'][$s['first']],
        'next'   => (int)$s['spoken'] >= (int)$s['turns'] ? null : xeric_watch_next($s)['name'],
        'turns'  => (int)$s['turns'],
        'spoken' => (int)$s['spoken'],
        'lines'  => array_values(array_map(fn($l) => [
            'handle' => (string)$l['handle'], 'name' => (string)$l['name'], 'text' => (string)$l['text'],
        ], (array)$s['lines'])),
    ];
}

/**
 * ONE spoken line: one model call, through the speaker's own assembly, cleaned,
 * floored and walled exactly as the engine's loop does it — same functions,
 * same order, same sentences. Mutates $s (the caller owns writing it back) and
 * touches the database not at all.
 *
 * The assemblies are rebuilt per line rather than carried in the state file:
 * nothing writes during a scene, so they come out byte-identical to what one
 * process would have cached, and a prompt does not get persisted to disk for
 * the privilege of saving a rebuild.
 *
 * The charge is here, not at the door: a watched line is a model call and
 * spends like one (say.php's own ordering — the caller has held the slot and
 * seen the model up before this runs, so what is charged is what happens).
 *
 * @throws RuntimeException the duet's own sentences; the line did not happen
 */
function xeric_watch_line(array $w, array &$s, array $endpoint, string $sid = ''): array
{
    $t  = $w['template'];
    $db = $w['db'];
    if ((int)$s['spoken'] >= (int)$s['turns']) {
        throw new RuntimeException('watch: the scene has already said its last line');
    }

    $a = (string)$s['a'];
    $b = (string)$s['b'];
    $names   = (array)$s['names'];
    $epoch   = (int)$s['epoch'];
    $now     = xeric_world_now($t, $epoch);
    $speaker = xeric_watch_next($s)['handle'];
    $partner = $speaker === $a ? $b : $a;

    $protected = xeric_sweep_protected($t);
    $walls     = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $speaker]));
    $system    = xeric_duet_system($t, $db, $speaker, $partner, (string)$s['eff'], $epoch, $walls, 12);
    $material  = xeric_duet_material($t, $db, $speaker, $partner, $protected);
    $tail      = xeric_duet_scene($t, $speaker, $partner, (array)$s['room'], $walls, $material);

    $lines = array_map(fn($l) => ['handle' => (string)$l['handle'], 'text' => (string)$l['text']],
                       (array)$s['lines']);
    // The walk-in seam, landed: once the player has spoken into the scene,
    // their recorded position threads into every later assembly, so the
    // speakers stop insisting the room holds only each other. Before the
    // walk-in, null — the duet stays the world talking to itself.
    $pw = !empty($s['player']['present']) ? (string)($s['player']['where'] ?? '') : null;
    $messages = xeric_duet_messages($t, $speaker, $partner, $system, $lines, $tail, $now,
        $walls, xeric_deaths($db), (int)$s['spoken'] === 0, (int)$s['spoken'] === (int)$s['turns'] - 1,
        $pw !== '' ? $pw : null);

    if ($sid !== '') xeric_limit_note('message', ['sid' => $sid]);

    try {
        $raw = xeric_chat_say($endpoint, $messages, [
            'temperature' => 0.85,
            'max_tokens'  => XERIC_DUET_MAX_TOKENS,
            'timeout'     => XERIC_PLAY_CHAT_TIMEOUT,
        ]);
    } catch (Throwable $e) {
        throw new RuntimeException('duet: ' . $names[$speaker] . ' did not answer, ' . $e->getMessage(), 0, $e);
    }

    $text = xeric_chat_clean($raw, $names[$speaker], $names[$partner], ['max_chars' => XERIC_DUET_MAX_CHARS]);
    if ($text === '') {
        throw new RuntimeException('duet: ' . $names[$speaker] . ' said nothing usable ('
            . mb_substr(trim($raw), 0, 120) . ')');
    }

    // The floor reads the new line with the line it answers — which after a
    // walk-in is the player's, and that is correct: the pair is one piece.
    $prev    = $s['lines'] !== [] ? (string)$s['lines'][count($s['lines']) - 1]['text'] : '';
    $refused = xeric_age_floor($t, [$a, $b], [$prev, $text]);
    if ($refused !== null) throw new RuntimeException(xeric_age_refusal('duet', $refused));

    foreach ($protected as $ph => $secret) {
        if (($ph === $a || $ph === $b) && xeric_sweep_touches($text, (string)$secret)) {
            throw new RuntimeException('duet: refused, the conversation put ' . $names[$ph]
                . ' next to the thing they must not know');
        }
    }

    $s['lines'][] = ['handle' => $speaker, 'name' => $names[$speaker], 'text' => $text];
    $s['spoken']  = (int)$s['spoken'] + 1;

    $done = (int)$s['spoken'] >= (int)$s['turns'];
    return [
        'handle' => $speaker,
        'name'   => $names[$speaker],
        'text'   => $text,
        'done'   => $done,
        'next'   => $done ? null : xeric_watch_next($s)['name'],
    ];
}

/**
 * The walk-in. No model call, no charge: the player's line lands in the
 * transcript as itself, under its own handle, and the duet's transcript
 * mapping does the rest — anything that is not the speaker's rides the user
 * seat, so the next scheduled line is answered with the words in view.
 *
 * The floor and the wall still read it: a rule about what may be said in this
 * room does not care which chair it was said from. And the player's real
 * position is recorded (xeric_player_where, a read) — carried in the state and
 * the trail today, into the assemblies the day the duet takes a playerWhere.
 *
 * @throws RuntimeException a refusal in the duet's words; nothing appended
 */
function xeric_watch_say(array $w, array &$s, string $text,
                         int $player = XERIC_PLAYER_FIRST): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $a  = (string)$s['a'];
    $b  = (string)$s['b'];

    $text = trim($text);
    if ($text === '') throw new RuntimeException('watch: there is nothing to say');
    if (mb_strlen($text) > XERIC_DUET_MAX_CHARS) $text = mb_substr($text, 0, XERIC_DUET_MAX_CHARS);

    $prev    = $s['lines'] !== [] ? (string)$s['lines'][count($s['lines']) - 1]['text'] : '';
    $refused = xeric_age_floor($t, [$a, $b], [$prev, $text]);
    if ($refused !== null) throw new RuntimeException(xeric_age_refusal('duet', $refused));

    foreach (xeric_sweep_protected($t) as $ph => $secret) {
        if (($ph === $a || $ph === $b) && xeric_sweep_touches($text, (string)$secret)) {
            throw new RuntimeException('duet: refused, the conversation put '
                . xeric_world_name($t, (string)$ph) . ' next to the thing they must not know');
        }
    }

    // WHOEVER ACTUALLY WALKED IN. The template's user is the person the world
    // was forged around; with two people in a house it is not necessarily the
    // one who typed this line, and a scene that labels a guest's words with the
    // owner's name puts words in somebody's mouth in the transcript itself.
    $me = $player > XERIC_PLAYER_FIRST
        ? xeric_player_name($db, $player, $t)
        : (trim((string)($t['user']['name'] ?? '')) ?: 'you');
    $where = xeric_player_where($t, $db);

    $s['lines'][] = ['handle' => XERIC_WATCH_PLAYER, 'name' => $me, 'text' => $text];
    $s['player']  = [
        'present' => true,
        'where'   => $where,
        'place'   => $where !== null ? xeric_world_place_name($t, $where) : '',
        'name'    => $me,
    ];

    return ['next' => xeric_watch_next($s)['name'], 'place' => (string)$s['player']['place']];
}

/**
 * The close: the CLI's close, re-stated line for line. Diaries first (model
 * calls, each allowed to fail into a note — the scene happened, learning is
 * garnish), then ONE transaction: the event, each speaker's memories, the
 * trail under the inspector's own key. A scene with no spoken line closes to
 * NOTHING — "they talked" may not be written about a room where nobody did.
 *
 * $endpoint may be null (the model has gone away since the scene ran): both
 * diaries are forfeit with a note each, and the event still lands, because
 * refusing a lived scene over its bookkeeping is the tail wagging the dog.
 *
 * @throws RuntimeException only when the store itself fails, rolled back whole
 */
function xeric_watch_close(array $w, array $s, ?array $endpoint): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $a  = (string)$s['a'];
    $b  = (string)$s['b'];

    if ((int)$s['spoken'] < 1) return ['empty' => true];

    $names     = (array)$s['names'];
    $room      = (array)$s['room'];
    $epoch     = (int)$s['epoch'];
    $now       = xeric_world_now($t, $epoch);
    $first     = (string)$s['first'];
    $protected = xeric_sweep_protected($t);

    $kept  = [];
    $notes = [];
    foreach ([$first, $first === $a ? $b : $a] as $me) {
        $other = $me === $a ? $b : $a;
        if ($endpoint === null) {
            $kept[$me] = [];
            $notes[]   = 'no model was attached at the close, so ' . $names[$me] . ' kept no diary of it';
            continue;
        }
        try {
            $kept[$me] = xeric_duet_extract($t, $db, $me, $other, (array)$s['lines'], $endpoint,
                $room, [$a, $b], $protected, $kept, ['timeout' => XERIC_PLAY_CHAT_TIMEOUT]);
        } catch (Throwable $e) {
            $kept[$me] = [];
            $notes[]   = 'could not harvest a diary for ' . $names[$me] . ', ' . $e->getMessage();
        }
    }

    // The last WORD is the last spoken line's: a walk-in at the end is the
    // player's, and the player is a voice in the room, not a seat at the table.
    $last = $first;
    foreach ((array)$s['lines'] as $l) {
        if ((string)$l['handle'] === $a || (string)$l['handle'] === $b) $last = (string)$l['handle'];
    }

    $material = [
        $a => xeric_duet_material($t, $db, $a, $b, $protected),
        $b => xeric_duet_material($t, $db, $b, $a, $protected),
    ];
    $title  = xeric_duet_title($names[$a], $names[$b]);
    $prose  = xeric_duet_prose($t, $names[$a], $names[$b], $room, $now);
    $player = (array)($s['player'] ?? []);

    $at = xeric_state_time();
    $db->beginTransaction();
    try {
        $eventId = xeric_event_add($db, $title, $epoch, (string)$room['where'], [$a, $b], $prose, $at, false);

        foreach ([$a, $b] as $me) {
            foreach ((array)($kept[$me] ?? []) as $text) {
                xeric_memory_add($db, $me, (string)$text, 'duet', [
                    'event_id' => $eventId,
                    'with'     => [$me === $a ? $b : $a],
                    'place'    => (string)$room['where'],
                ], $epoch, $at);
            }
        }

        xeric_world_state_set($db, 'why:event:' . $eventId, json_encode([
            'kind'        => 'duet',
            'why'         => $names[$a] . ' and ' . $names[$b] . ' were both at '
                           . ((string)$room['place_name'] !== '' ? (string)$room['place_name'] : 'the same place')
                           . ' (' . (string)$room['why'] . '); '
                           . xeric_duet_material_why($names, $material, $a, $b) . ' '
                           . $names[$first] . ' spoke first and ' . $names[$last] . ' had the last word.'
                           . (!empty($player['present'])
                               ? ' ' . (string)$player['name'] . ' walked in part way, and the next line answered them.'
                               : ''),
            'place'       => (string)($room['where'] ?? ''),
            'people'      => [$a, $b],
            'spoke_first' => $first,
            'last_word'   => $last,
            'turns'       => (int)$s['spoken'],
            'extended'    => (bool)$s['extended'],
            'minor_clamp' => (bool)$s['minor'],
            'rating'      => (string)$s['eff'],
            'watched'     => true,
            'player_present' => !empty($player['present']),
            'player_where'   => $player['where'] ?? null,
            'notes'       => $notes,
            'at'          => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('duet: could not store the close, ' . $e->getMessage(), 0, $e);
    }

    return [
        'lines'       => (array)$s['lines'],
        'event_id'    => $eventId,
        'title'       => $title,
        'prose'       => $prose,
        'place'       => (string)$room['where'],
        'place_name'  => (string)$room['place_name'],
        'memories'    => $kept,
        'spoke_first' => $first,
        'last_word'   => $last,
        'turns'       => (int)$s['spoken'],
        'notes'       => $notes,
    ];
}

// ---------------------------------------------------------------------------
// The voice machine — which model speaks for whom (owner, 2026-08-02)
// ---------------------------------------------------------------------------
//
// "Sam is Gemma, Lucy is Qwen": a character may be pinned to one of the
// session's CONNECTED machines, and their speaking calls — chat turns, watch
// lines — go there instead of the world's engine. Two facts make this cheaper
// than it sounds. The prefix cache is PER MODEL, so pinning a character to a
// machine keeps THEIR prefix warm on THAT machine — per-character models and
// the cache discipline align instead of fighting. And every speaking path is
// already one call per speaker (the duet's first law), so there is no call
// anywhere that would have to split.
//
// WHAT IS DELIBERATELY NOT PINNED: sweeps. One call writes a whole hour and
// every witness's memory of it; an hour has no single voice to route. The
// assignment is stored in the world db (an arc, not the template) because a
// machine address is a fact about THIS house, and templates travel.

/** The base a character is pinned to, or '' for the world's engine. */
function xeric_voice_machine(PDO $db, string $handle): string
{
    return trim((string)(xeric_arc_get($db, $handle, 'voice.machine') ?? ''));
}

/** Pin (a wired base) or clear (''). Returns the label the UI should say. */
function xeric_voice_set(PDO $db, string $handle, string $base, ?string $sid = null): string
{
    $base = rtrim(trim($base), '/');
    if ($base === '') { xeric_arc_clear($db, $handle, 'voice.machine'); return 'the world\'s engine'; }
    if (!in_array($base, xeric_model_wired($sid), true)) {
        throw new RuntimeException('That machine is not connected. Connect it on the machines screen first — only active machines can speak for somebody.');
    }
    xeric_arc_set($db, $handle, 'voice.machine', $base);
    return (string)preg_replace('#^https?://#', '', $base);
}

/**
 * The endpoint that speaks for this character: their pinned machine when it is
 * still connected, else the world's engine. FALLS BACK, NEVER FAILS — a
 * tuning choice must not be able to silence somebody, so a pin whose machine
 * has since been forgotten or detached degrades to the default and the turn
 * still answers. The trail of which machine actually spoke is the endpoint's
 * own label, already recorded where calls are logged.
 */
function xeric_voice_endpoint(array $t, PDO $db, string $handle, ?string $sid = null): array
{
    $base = xeric_voice_machine($db, $handle);
    if ($base !== '' && in_array($base, xeric_model_wired($sid), true)) {
        try {
            $ep = xeric_web_endpoint(xeric_model_descriptor($base));
            if (xeric_llm_up($ep, 3)) return $ep;
        } catch (Throwable $e) { /* fall through to the engine */ }
    }
    return xeric_play_endpoint($sid);
}

/** The compact picker's rows: the engine default plus every connected machine. */
function xeric_voice_choices(PDO $db, string $handle, ?string $sid = null): array
{
    $now = xeric_voice_machine($db, $handle);
    $out = [['base' => '', 'label' => "the world's engine", 'on' => $now === '']];
    foreach (xeric_model_wired($sid) as $b) {
        $out[] = ['base' => $b,
                  'label' => (string)preg_replace('#^https?://#', '', $b),
                  'on' => $b === $now];
    }
    return $out;
}


/**
 * This machine's address ON THE NETWORK, with a path hung off it — or null.
 *
 * Asked of the routing table rather than guessed: a UDP socket aimed at a
 * public address picks the interface a phone would come back through, and
 * connects to nothing (UDP has no handshake, and no packet is ever sent).
 * Falls back to the Host header, which on a LAN-bound server IS the address
 * the browser dialled.
 *
 * Null means there is nothing a phone could reach — either this xeric is
 * listening only to its own machine, or the machine cannot work out its own
 * address. Both are the caller's to explain; they are different sentences.
 */
function xeric_play_lan_url(string $path): ?string
{
    $host = (string)(getenv('XERIC_BIND') ?: '127.0.0.1');
    $port = (string)(getenv('XERIC_PORT') ?: '8787');
    if ($host === '127.0.0.1' || $host === 'localhost') return null;

    $ip = '';
    if (function_exists('socket_create')) {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock !== false) {
            if (@socket_connect($sock, '203.0.113.1', 9)) {   // TEST-NET-3, routed nowhere
                @socket_getsockname($sock, $ip);
            }
            @socket_close($sock);
        }
    }
    if ($ip === '') {
        $h = (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if (filter_var($h, FILTER_VALIDATE_IP) && !in_array($h, ['127.0.0.1', '::1'], true)) $ip = $h;
    }
    if ($ip === '' || $ip === '0.0.0.0') return null;

    return 'http://' . $ip . ':' . $port . '/' . ltrim($path, '/');
}
