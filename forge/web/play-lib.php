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
 *
 * THE MAP. This file was one 7,000-line library serving every surface; it is now
 * split by the surface each part serves, as pure moves (every function kept its
 * name, signature and body). Requiring this file still loads all of it.
 *
 *   play-lib.php    here: worlds on disk, open / guard / redact, time and its
 *                   spans, the cast, the sidebar, state, hints and suggestions,
 *                   the stories, connected-or-not, the event feed, jobs, the
 *                   trail, failures said out loud, which machine speaks for whom
 *   model-lib.php   the machines: ports, the scan, who answers, the list, which
 *                   are wired and which is the engine (xeric_model_*)
 *   play-shelf.php  the shelf: next hour, tiles, their scenes and SVG, the grid
 *   play-view.php   the readers: fate, where, the voice, the look (what you see),
 *                   and the room's web half (xeric_play_room_*)
 *   play-book.php   the book (xeric_book_*, book.php)
 *   play-watch.php  the duet watch (xeric_watch_*, watch.php)
 *   xeric.css       the play view's stylesheet, and xeric-shelf.css the shelf's:
 *                   static, linked with a content hash (xeric_play_css_link);
 *                   xeric_play_css() / xeric_play_shelf_css() hand back the same
 *                   bytes for a page that inlines them
 *   xeric-view.js   the viewer's JavaScript (the look-around, step inside, the
 *                   day strip, the phone, the speaker, hold to talk), static,
 *                   loaded by play.php with the page's nonce
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
require_once XERIC_WEB_LIB . '/engine/room.php';        // three to five of them, and you
require_once XERIC_WEB_LIB . '/engine/director.php';    // the set, never the performance

// The rest of this library, by surface (the map above). After the engine, so
// each part finds what it leans on; each also requires this file, which is a
// no-op from here and makes requiring a part on its own load the whole.
require_once __DIR__ . '/model-lib.php';
require_once __DIR__ . '/play-shelf.php';
require_once __DIR__ . '/play-view.php';
require_once __DIR__ . '/play-map.php';
require_once __DIR__ . '/play-book.php';
require_once __DIR__ . '/play-watch.php';
require_once __DIR__ . '/play-narrator.php';

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
    // AND THAT RATING IS THIS PROCESS'S, both ways (xeric_state_rating): every
    // row written from here on is stamped with what it was generated under,
    // and every row read from here on is shown only if its stamp is within it.
    // A kids' headset is a device whose bit makes xeric_session_adult() false,
    // so it lands here as the floor and never reads an adult-stamped line.
    xeric_state_rating((string)($t['meta']['rating'] ?? xeric_ratings()[0]));

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
    // THE DIRECTOR'S STAGINGS, worn by the template so every reader that takes
    // no database (the prompt's room line, the cast panel, rooms, scenes, the
    // skip) sees them. No stagings: the template comes back unchanged.
    if (function_exists('xeric_director_dress')) $t = xeric_director_dress($t, $db);

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
    //
    // AND ONLY FOR THE OWNER'S CLOCK. A guest who came in on a pairing code is
    // standing in the owner's canonical world, and whether THEIR session has a
    // machine attached says nothing about the owner's — a guest on a phone with
    // nothing wired was pausing the owner's running evening just by opening
    // it. The canonical clock is the owner's to stop; a guest's open never
    // moves it, either way — nor does a worker a guest's request started, which
    // is handed the canonical path and so reads as `mine`. Asked by WHO this
    // process acts for, not by which file it holds. A stranger's fork is their
    // own and still reconciles.
    try {
        $guest = (string)$pick['path'] === $dir . '/world.db'
              && (int)(xeric_session_player($slug) ?? XERIC_PLAYER_FIRST) > XERIC_PLAYER_FIRST;
    } catch (Throwable $e) {
        $guest = !$pick['mine'] && (string)$pick['path'] === $dir . '/world.db';
    }
    try {
        if (!$guest && !xeric_web_connected(xeric_web_model()) && !xeric_clock_is_paused($db)) {
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

    // THE WORLD THIS PROCESS NOW SPENDS FOR. Every metered call from here on
    // lands in this world's ledger and is read against its `budget` block
    // (engine/budget.php); the heart rebinds as it walks the worlds.
    xeric_budget_bind($db, $t);

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
        // A CLOCK, NOT AN ARROW. Every other mark in this vocabulary is a
        // pictograph — 💼 at work, 🏠 home, 💤 asleep, 📍 out somewhere — and
        // this one was a bare arrow, which in a row of buttons reads as chrome:
        // a "next", a link, something to press. The owner's report was "some
        // weird arrow on his name for no apparent reason", and that is exactly
        // what an arrow means when everything beside it is a picture of a place.
        // It says DUE SOMEWHERE SOON, so it gets a clock.
        return ['state' => 'soon', 'glyph' => '🕘',
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

    // ── THREADS: THE CONVERSATIONS YOU ARE ACTUALLY IN ────────────────────
    //
    // The clean room opens its sidebar with the conversation list, and this app
    // had none at all: the only way to reach a thread was the chip bar, which
    // shows EVERYBODY — so "who exists" and "who I am mid-conversation with"
    // were the same row of buttons, and the second question had no answer.
    //
    // xeric_conversations_recent() has been in the engine since the beginning
    // and was called by NOTHING. The kinds it stores — chat and event — are the
    // same split the clean room draws (💬 chats, 🎬 events), so this is Xeric's
    // own data finally getting the shape it was written for.
    //
    // ONE QUERY, not one per row. The snippet is what makes this a conversation
    // list rather than a second copy of the chip bar, and a correlated subquery
    // over idx_messages_conv(conversation_id, id) is a seek per thread rather
    // than the N+1 the obvious version would have been.
    $threads = [];
    try {
        $st = $db->prepare(
            'SELECT c.id, c.handle, c.kind, c.title, c.unread, c.updated_at,'
            . ' (SELECT m.content FROM messages m WHERE m.conversation_id = c.id'
            . '  ORDER BY m.id DESC LIMIT 1) AS last,'
            . ' (SELECT m.rating FROM messages m WHERE m.conversation_id = c.id'
            . '  ORDER BY m.id DESC LIMIT 1) AS last_rating'
            . ' FROM conversations c ORDER BY c.updated_at DESC, c.id DESC LIMIT 14');
        $st->execute();
        $threads = $st->fetchAll();
        $st->closeCursor();
        // The snippet is the newest line, and it is read as this viewer may read it.
        foreach ($threads as &$th) {
            if (!xeric_state_shown((string)($th['last_rating'] ?? ''))) $th['last'] = XERIC_STATE_UNSHOWN;
        }
        unset($th);
    } catch (Throwable $e) { $threads = []; }

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
    // BOTH HALVES ESCAPED. The motif always was; the word was not, and it is the
    // one string on this page that reached a browser raw. It comes off
    // `world_mood.axis.positive|negative`, which the forge writes straight from
    // model output through xeric_forge_str() — trim, collapse, cap length, and
    // no HTML filtering anywhere in that path — so this was model-written script
    // running in the browser of somebody who merely opened the world from the
    // shelf. Nobody typed it, and the person it fired at had never met whoever
    // forged it. The compass block renders the same value correctly at
    // xeric_play_compass_html(); this was one missed call, not a pattern.
    $moodLine = $mood === [] || ($mood['side'] ?? '') === 'ordinary'
        ? ''
        : h((string)$mood['word']) . ((string)($mood['motif'] ?? '') !== ''
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
    // THE THREADS, FIRST, because the clean room's scheme puts what you are in
    // the middle of above what is merely true about the world. Unread rises to
    // the top and carries an edge bar; everything else keeps recency order.
    if ($threads !== []) {
        $unread = 0;
        foreach ($threads as $tr) if ((int)($tr['unread'] ?? 0) > 0) $unread++;
        usort($threads, function ($x, $y) {
            $ux = (int)($x['unread'] ?? 0) > 0 ? 1 : 0;
            $uy = (int)($y['unread'] ?? 0) > 0 ? 1 : 0;
            if ($ux !== $uy) return $uy <=> $ux;
            return (int)($y['updated_at'] ?? 0) <=> (int)($x['updated_at'] ?? 0);
        });

        $rowsFor = function (string $kind) use ($threads, $t, $disc, $dead): string {
            $li = '';
            foreach ($threads as $tr) {
                if ((string)($tr['kind'] ?? 'chat') !== $kind) continue;
                $h = (string)($tr['handle'] ?? '');
                if ($h === '') continue;
                $name = xeric_world_name($t, $h) ?: $h;
                $snip = trim(preg_replace('/\s+/u', ' ', (string)($tr['last'] ?? '')) ?? '');
                if (mb_strlen($snip) > 64) $snip = rtrim(mb_substr($snip, 0, 64)) . '…';
                $li .= '<li class="thr' . ((int)($tr['unread'] ?? 0) > 0 ? ' unread' : '')
                     . (isset($dead[$h]) ? ' gone' : '') . '">'
                     . '<button type="button" class="wperson thrb" data-h="' . h($h) . '">'
                     . $disc($h)
                     . '<span><span class="wn">' . h($name) . '</span>'
                     . ($snip !== '' ? '<span class="thrl">' . h($snip) . '</span>' : '')
                     . '</span></button></li>';
            }
            return $li;
        };

        $chats  = $rowsFor('chat');
        $events = $rowsFor('event');
        if ($chats !== '' || $events !== '') {
            // "threads", not "what you are in the middle of": the long version
            // wrapped onto two lines and stranded its own count on the second,
            // which is what every other heading in this panel avoids by being
            // one short word.
            $out .= xeric_play_sideblock('threads', 'threads', count($threads), true);
            // The 💬/🎬 sub-heads earn their line only when there is something to
            // tell apart. With one kind in the list the block title has already
            // said it, and a heading over every row is a heading nobody reads.
            $both = $chats !== '' && $events !== '';
            if ($chats !== '') {
                $out .= ($both ? '<p class="thrhead">💬 talking</p>' : '')
                      . '<ul class="thrs">' . $chats . '</ul>';
            }
            if ($events !== '') {
                $out .= ($both ? '<p class="thrhead">🎬 events</p>' : '')
                      . '<ul class="thrs">' . $events . '</ul>';
            }
            $out .= '</div></details>';
        }
    }

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
        // A SHUT ROOM IS STILL A PLACE YOU CAN WALK TO. This required the place
        // to be OPEN, which made the sidebar stricter than both the engine and
        // the map screen beside it: engine-test asserts "you may walk to a
        // chained gate, and it tells you it is chained", and where.php's own
        // list offers every place with an open/shut word on it. Only this panel
        // turned a closed mill into a dead end — and standing outside somewhere
        // shut, at the hour it is shut, is a thing a person in a town does.
        $walk = $key !== '' && empty($pl['here']);
        $mins = (int)($pl['minutes'] ?? 0);
        $hrs  = isset($hours[$key]) ? '<span class="phrs">' . h($hours[$key]) . '</span>' : '';
        // THE WHOLE ROW IS THE DOOR. It used to be a text link with a ten-pixel
        // "› go" beside it, so a place you could walk into and one you could not
        // were the same shape — the clean room's rows are full-width targets
        // with a chevron, and that is the difference between a list of facts and
        // a list of ways in. The minutes ride the row rather than the tooltip:
        // how far it is decides whether you go, and a tooltip cannot be read by
        // a thumb.
        $out .= '<li class="' . $cls . '">'
              . ($walk
                  ? '<button type="button" class="pl wplace" data-to="' . h($key) . '"'
                    . ' title="walk there · ' . $mins . ' min">'
                    . '<span class="pln">' . h((string)$pl['name']) . $hrs . '</span>'
                    . ($mins > 0 ? '<span class="plm">' . $mins . ' min</span>' : '')
                    . '<span class="wgo2">›</span></button>'
                  : '<span class="pl">'
                    . '<span class="pln">' . h((string)$pl['name']) . $hrs . '</span>'
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
        xeric_events_recent($db, 6, xeric_state_rating()),
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
    foreach (xeric_events_recent($db, 6, xeric_state_rating()) as $e) {
        $ti = trim((string)($e['title'] ?? ''));
        if ($ti !== '') $recent[] = $ti;
    }
    if ($recent) $lines[] = "Lately:\n- " . implode("\n- ", $recent);

    return implode("\n\n", $lines);
}

/**
 * The narrator, asked once. Never the answer to a mystery — the discretion
 * rules in engine/narrator.php decide what leaves its mouth, in its own voice.
 */
function xeric_play_hint(array $t, PDO $db, string $ask, array $endpoint): string
{
    // THE HINT IS THE NARRATOR NOW (docs/FUTURE.md M3): the real ask — full
    // canon, no walls, the discretion rules in force — through the one door
    // engine/narrator.php keeps. Signature kept so nothing that called the old
    // 30-word hint breaks; the play view stores its exchanges through
    // xeric_play_narrator_ask() instead, which also reads the UNCOMPOSED
    // template. A caller here should hand over the same.
    $now = xeric_clock_now($db, $t);
    $q   = trim($ask) !== '' ? trim($ask) : XERIC_PLAY_NARRATOR_COLD;
    return trim((string)xeric_narrator_ask($t, $db, $q, $now, $endpoint)['text']);
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
    $rows = xeric_messages_recent($db, $id, $limit, 0, xeric_state_rating());   // as this viewer reads it
    xeric_play_mark_read($db, $id);

    // The photos running behind this conversation, mapped by the message they
    // belong to. A done job turns its caption message into an image bubble; a
    // pending one leaves the caption standing — the caption IS the photo until
    // the reaper develops it, and the thread's ordinary repaint is what swaps
    // the picture in when it lands.
    $shots = xeric_photo_thread($db, $id);

    // WHO SAID IT, once it matters: with more than one person at the centre
    // having spoken in this thread, a player's line carries that player's
    // roster name and number, and the page labels the bubble with them. One
    // voice, and the row is exactly what it was — "you", unlabelled.
    $voices = [];
    foreach ($rows as $m) {
        if ((string)$m['role'] === 'user') $voices[max(XERIC_PLAYER_FIRST, (int)($m['player'] ?? 0))] = true;
    }
    $many = count($voices) > 1;

    $out = [];
    foreach ($rows as $m) {
        $p = max(XERIC_PLAYER_FIRST, (int)($m['player'] ?? 0));
        $row = [
            // The id rides along for the speaker beside a bubble (speak.php),
            // which asks after a line by id and by nothing else.
            'id'   => (int)$m['id'],
            'role' => (string)$m['role'],
            'who'  => (string)$m['role'] === 'user'
                        ? ($many ? xeric_player_name($db, $p, $t)
                                 : (trim((string)($t['user']['name'] ?? '')) ?: 'you'))
                        : xeric_world_name($t, (string)($m['handle'] ?? $handle)),
            'text' => (string)$m['content'],
            'when' => xeric_play_stamp($t, (int)($m['world_epoch'] ?? $m['created_at'])),
        ];
        if ($many && (string)$m['role'] === 'user') $row['player'] = $p;
        // A line stamped above this viewer arrives already emptied
        // (xeric_messages_recent); the flag lets the page draw it as a gap.
        if (!empty($m['redacted'])) $row['redacted'] = true;
        $shot = $shots[(int)$m['id']] ?? null;
        if ($shot !== null && (string)$shot['status'] === 'done' && empty($m['redacted'])
            && xeric_state_shown((string)($shot['rating'] ?? ''))) {
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
        // What you see, beside where you are and for the same reason: a trip,
        // a skip, or somebody walking in changes the room, and the state
        // payload is what refreshState repaints from — so travelling repaints
        // the view for free. Owner-or-own-copy may press look (the same door
        // scene.php's POST keeps); a guest in the owner's real world may not.
        'look_html'  => xeric_play_look_html($t, $db, $now, (string)$w['slug'], !xeric_play_is_guest($w),
                                             xeric_play_room_may_sit($w)),
        // The room you are in, when you are in one (engine/room.php): a small
        // summary so the page knows to open the strip and start polling. The
        // lines themselves come from room.php's own cursor, never from here.
        'room'       => xeric_play_room_public($t, xeric_room_live_get($db), xeric_play_room_may_sit($w),
                                               xeric_session_player((string)$w['slug'], $sid) ?? XERIC_PLAYER_FIRST),
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


// The machines, the shelf and the readers are in model-lib.php,
// play-shelf.php and play-view.php (the map is at the top of this file).


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
    $rows = xeric_events_recent($db, $limit, xeric_state_rating());
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
 * A static file beside this one, addressed by what is in it: `xeric.css?v=<hash>`.
 *
 * THE ADDRESS CHANGES WHEN THE BYTES DO, so the file can be cached as hard as a
 * browser likes (.htaccess says immutable for exactly this shape) and a deploy
 * is still seen on the next page load — no build step, no manifest, nothing to
 * forget. Sixteen hex digits of sha256, read once per request.
 */
function xeric_play_asset(string $name): string
{
    static $v = [];
    $v[$name] ??= substr((string)@hash_file('sha256', __DIR__ . '/' . $name), 0, 16);
    return $name . '?v=' . $v[$name];
}

/** The bytes of one of those files, for a page that inlines them instead. */
function xeric_play_static(string $name): string
{
    return rtrim((string)@file_get_contents(__DIR__ . '/' . $name), "\n");
}

/**
 * The play view's stylesheet as a <link>: xeric.css, and xeric-shelf.css on top
 * of it where there are tiles. style-src 'self' in ui.php's policy is what
 * allows it; it is printed where the inline <style> used to be, so the cascade
 * order (ui.php's base sheet first, then this, then a page's own) is unchanged.
 */
function xeric_play_css_link(bool $shelf = false): string
{
    $out = '<link rel="stylesheet" href="' . h(xeric_play_asset('xeric.css')) . '">';
    if ($shelf) $out .= '<link rel="stylesheet" href="' . h(xeric_play_asset('xeric-shelf.css')) . '">';
    return $out;
}

/**
 * The play view's CSS, on top of the wizard's.
 *
 * Same palette, same spacing, same tap targets — a visitor who forged a world
 * and pressed "enter" must not feel handed off to a different product. Only
 * shapes the wizard has no use for live here.
 *
 * The rules live in xeric.css (served by xeric_play_css_link). This returns the
 * same bytes for the pages that still print them inline, inside a <style> of
 * their own beside their page CSS.
 */
function xeric_play_css(): string
{
    return xeric_play_static('xeric.css');
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

// The book, the watch and the room are in play-book.php, play-watch.php and
// play-view.php.


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
// SWEEPS ARE PINNED BY THEIR CENTRE. One call writes a whole hour and every
// witness's memory of it, so an hour is written by the machine pinned to the
// person it centres on (engine/sweeps.php xeric_sweep_voice), through
// xeric_play_sweep_endpoints() below. The assignment is stored in the world db
// (an arc, not the template) because a machine address is a fact about THIS
// house, and templates travel.
//
// NAMED xeric_play_speaker_*, NOT xeric_voice_*: engine/voice.php is the cast
// out loud (text-to-speech), and it owns that prefix — including the engine's
// own xeric_voice_endpoint(). These four route a character's WORDS to a model;
// the engine's route their SOUND to a speech machine. Same word, two features,
// and PHP has one namespace for both.

/** The base a character is pinned to, or '' for the world's engine. */
function xeric_play_speaker_machine(PDO $db, string $handle): string
{
    return trim((string)(xeric_arc_get($db, $handle, 'voice.machine') ?? ''));
}

/** Pin (a wired base) or clear (''). Returns the label the UI should say. */
function xeric_play_speaker_set(PDO $db, string $handle, string $base, ?string $sid = null): string
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
function xeric_play_speaker_endpoint(array $t, PDO $db, string $handle, ?string $sid = null): array
{
    return xeric_play_speaker_pinned($db, $handle, $sid)['endpoint'] ?? xeric_play_endpoint($sid);
}

/**
 * The pin and whether it can speak: `base` is '' for no pin; `endpoint` is
 * null when there is a pin and its machine is detached or not answering.
 * $up is the reachability probe (xeric_llm_up by default), a seam for tests.
 *
 * @return array{base:string,endpoint:?array}
 */
function xeric_play_speaker_pinned(PDO $db, string $handle, ?string $sid = null, ?callable $up = null): array
{
    $base = xeric_play_speaker_machine($db, $handle);
    if ($base === '') return ['base' => '', 'endpoint' => null];
    if (in_array($base, xeric_model_wired($sid), true)) {
        try {
            $ep = xeric_web_endpoint(xeric_model_descriptor($base));
            if ($up !== null ? $up($ep) : xeric_llm_up($ep, 3)) return ['base' => $base, 'endpoint' => $ep];
        } catch (Throwable $e) { /* not answering */ }
    }
    return ['base' => $base, 'endpoint' => null];
}

/**
 * What server a machine is and how many llama.cpp slots it has, asked once
 * per process per address: the discovery name (xeric_model_who — 'llama.cpp',
 * 'llama.cpp router', 'Ollama …') and /props `total_slots`. Only a local
 * address is asked anything (xeric_model_who makes no request of a hosted
 * one), and anything that is not llama.cpp gets slots 1 — which
 * xeric_llm_slot() reads as "send no id_slot".
 *
 * @return array{server:string,slots:int}
 */
function xeric_play_speaker_server(string $base): array
{
    static $memo = [];
    $base = rtrim(trim($base), '/');
    if ($base === '' || !preg_match('#^https?://#i', $base)) return ['server' => '', 'slots' => 1];
    if (isset($memo[$base])) return $memo[$base];
    $server = '';
    $slots  = 1;
    try {
        $server = (string)(xeric_model_who($base)['name'] ?? '');
        if ($server === 'llama.cpp' || $server === 'llama.cpp router') {
            $p = xeric_model_peek($base . '/props', 2, xeric_model_probe_key($base));
            $slots = max(1, (int)($p['total_slots'] ?? 1));
        }
    } catch (Throwable $e) { $server = ''; $slots = 1; }
    return $memo[$base] = ['server' => $server, 'slots' => $slots];
}

/**
 * The resolver a sweep is handed: fn(string $handle): array, the endpoint
 * that writes an hour centred on that person (engine/sweeps.php
 * xeric_sweep_voice). Their pinned machine when it answers; else the world's
 * default — `$w['endpoint']` when the caller already holds it (the heart and
 * the tick worker do), xeric_play_endpoint() otherwise — and a pin that did
 * not answer says so in `pin.down`, which the sweep writes into the trail.
 *
 * Every endpoint carries `server`/`slots` so a llama.cpp keeps a slot per
 * speaker (xeric_llm_slot). Answers are kept for the resolver's life (one
 * catch-up): a machine is probed once, not once per hour; one that dies
 * mid-stretch is caught by the sweep's own fallback.
 *
 * $w: db (required), sid, endpoint; `up` and `server` are test seams
 * (fn(array $ep): bool, fn(string $base): array{server,slots}).
 */
function xeric_play_sweep_endpoints(array $w): callable
{
    $db      = $w['db'];
    $sid     = $w['sid'] ?? null;
    $default = $w['endpoint'] ?? null;
    $up      = $w['up'] ?? null;
    $server  = $w['server'] ?? 'xeric_play_speaker_server';
    $memo    = [];
    $tag = static function (array $ep) use ($server): array {
        $base = (string)(($ep['local'] ?? '') !== '' ? $ep['local'] : ($ep['base'] ?? ''));
        return $ep + (array)$server($base);
    };
    return function (string $handle) use ($db, $sid, &$default, $up, $tag, &$memo): array {
        if (isset($memo[$handle])) return $memo[$handle];
        $default ??= xeric_play_endpoint($sid);
        $p = xeric_play_speaker_pinned($db, $handle, $sid, $up);
        if ($p['base'] === '') $ep = $tag($default);
        else {
            $pin = ['machine' => (string)preg_replace('#^https?://#', '', $p['base']), 'down' => $p['endpoint'] === null];
            $ep  = ($p['endpoint'] !== null ? $tag($p['endpoint']) : $tag($default)) + ['pin' => $pin];
        }
        return $memo[$handle] = $ep;
    };
}

/** The compact picker's rows: the engine default plus every connected machine. */
function xeric_play_speaker_choices(PDO $db, string $handle, ?string $sid = null): array
{
    $now = xeric_play_speaker_machine($db, $handle);
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
