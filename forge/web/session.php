<?php
/**
 * session.php — who this visitor is, and which worlds are theirs.
 *
 * A shared instance is one machine, one GPU and one directory of worlds, being
 * used by strangers who have never met. Everything below exists to make that
 * survivable without asking anybody for a name — sessions are cheap, but they
 * are not free.
 *
 * THE FOUR RULES THIS FILE IS:
 *
 *  1. IDENTITY IS A COOKIE AND NOTHING ELSE. An opaque 128-bit id, httponly,
 *     secure, samesite=lax. No account, no email, no name, no address kept —
 *     limits.php hashes the IP and even that never lands here. A session is a
 *     row of counters, a list of slugs and ONE BIT (the affirmation below), and
 *     it is the only thing about a visitor this app can ever know. The bit is a
 *     bit and not an age on purpose: an age column is a list of people who said
 *     they were children, which is a liability invented out of nothing.
 *
 *  2. ONE VISITOR, ONE ID. This deliberately reuses the wizard's existing
 *     cookie (boot.php's XERIC_WEB_COOKIE) rather than minting a second one:
 *     ownership that could disagree with the wizard's answers would be two
 *     visitors in one browser, and the first bug report would be "the forge
 *     built my world and then said it wasn't mine".
 *
 *  3. THE TEMPLATE IS SHARED; ONLY STATE FORKS. Every world on disk is visible
 *     to everybody, because a shelf of worlds other people made is the demo's
 *     best argument. But a visitor playing a world they did not forge must not
 *     move somebody else's evening on, so the first time they open it their
 *     session gets its own copy of the DATABASE — the template and the seed
 *     stay shared and are never written to. A world db is ~64KB; a copy per
 *     visitor per world is cheaper than the request that asked for it.
 *
 *  4. NOTHING IS KEPT FOREVER. A session idle for XERIC_SESSION_TTL is swept,
 *     and its forked databases and the worlds it forged go with it. That is the
 *     promise the front page makes ("nothing here is uploaded anywhere") read
 *     backwards: what the demo holds, it also lets go of.
 *
 * CLI note: workers have no cookie. They are handed the visitor's id in their
 * payload and call xeric_session_use() before claiming anything on their behalf.
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';

/**
 * How long a session survives with nobody coming back to it.
 *
 * Idle, not absolute: a visitor who returns on day six keeps their worlds. Seven
 * days is long enough that "I'll show my friend tomorrow" works and short enough
 * that a week of strangers is not still on the disk a month later.
 */
const XERIC_SESSION_TTL = 604800;         // 7 days

/** Where a session's forked world databases live. Never the docroot. */
function xeric_session_root(): string
{
    return xeric_web_dir((string)xeric_web_config()['data_dir'] . '/session-worlds');
}

function xeric_session_db_dir(string $sid): string
{
    return xeric_session_root() . '/' . $sid;
}

// ---------------------------------------------------------------------------
// Identity
// ---------------------------------------------------------------------------

/**
 * Force the current identity, for a process that has no cookie.
 *
 * The two workers run detached under the CLI SAPI and act on behalf of the
 * visitor who started them; the tests switch visitors the same way. Passing null
 * hands the process back to the cookie.
 */
function xeric_session_use(?string $sid): string
{
    static $cur = '';
    if ($sid !== null) $cur = preg_match('/^[a-f0-9]{32}$/', $sid) ? $sid : '';
    return $cur;
}

/**
 * This visitor's id, minted and cookied on first sight.
 *
 * The cookie is re-set on every visit so the seven days are idle days, not days
 * since they first arrived. Everything else here is boot.php's: one cookie, one
 * session file, one sweep.
 */
function xeric_session_id(): string
{
    $forced = xeric_session_use(null);
    if ($forced !== '') return $forced;

    $sid = xeric_web_sid();
    // A CLI process that was NOT told whose work it is doing gets an id for the
    // life of the process and nothing on disk: a worker started by hand must not
    // leave a phantom visitor behind, or spend one's budget.
    if (PHP_SAPI !== 'cli') xeric_session_touch($sid);
    return $sid;
}

/**
 * Mark a session seen, creating its record on the first request that carries it.
 *
 * Once per request per session: a page that reads the session six times must not
 * write it six times, and the cookie must not be re-sent on every include.
 *
 * THE COOKIE GOES OUT FIRST AND THE RECORD FOLLOWS. An id minted a line ago is a
 * guess about a visitor — nobody has come back with it yet — and a client that
 * keeps no cookies is one visitor however many times it calls. Writing a record
 * for each of those calls is what let a flood of cookieless GETs push real
 * people's worlds off the disk, because eviction walks oldest-seen first and the
 * phantoms sort last. So: everybody gets the cookie, and whoever returns holding
 * it gets a record.
 */
function xeric_session_touch(?string $sid = null): void
{
    static $done = [];
    $sid = $sid ?? xeric_session_id();
    if (isset($done[$sid])) return;
    $done[$sid] = true;

    if (!xeric_web_sid_fresh($sid)) {
        // A brand new session is where the per-address budget is spent, because
        // it is the only moment a new cookie jar costs anything (limits.php).
        // Spent HERE rather than inside the edit so the answer can decide
        // whether there is going to be an edit at all.
        $new = null;
        if (!is_file(xeric_web_session_path($sid))) {
            $new = ['created' => time()];
            if (function_exists('xeric_limit_new_session')) $new = xeric_limit_new_session($new);
        }

        // Over the address budget: no record. Made-up cookie values are as cheap
        // to send as none at all, and every record written is a file eviction
        // walks past — oldest-seen first, through real visitors' worlds. The
        // visitor still gets the cookie below and every page in the demo;
        // refusing the record must never refuse the page. limits.php reads the
        // address's own bucket, so nothing is let through by the record's
        // absence.
        $denied = $new !== null && function_exists('xeric_limit_session_denied')
            && xeric_limit_session_denied($new);

        if (!$denied) {
            xeric_web_session_edit(function (array &$s) use ($new): void {
                if (!isset($s['created'])) $s = ($new ?? ['created' => time()]) + $s;
                $s['seen'] = time();
            }, $sid);
        }
    }

    if (!headers_sent() && PHP_SAPI !== 'cli') {
        setcookie(XERIC_WEB_COOKIE, $sid, [
            'expires'  => time() + XERIC_SESSION_TTL,
            'path'     => '/',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

// ---------------------------------------------------------------------------
// The affirmation — one bit, never an age
// ---------------------------------------------------------------------------

/**
 * Has this visitor affirmed they are an adult?
 *
 * NOBODY IS EVER ASKED HOW OLD THEY ARE. The question the content choice needs
 * answered is one bit wide, and a stored age is a database of self-reported
 * minors that nothing in this app would ever read. So: a boolean on the session
 * record, and the record is all there is.
 *
 * IT RIDES THE SESSION'S OWN TTL AND IS NOT SEPARATELY DATED. The record is
 * deleted by xeric_session_sweep() after XERIC_SESSION_TTL idle days, which
 * means the affirmation expires by being forgotten along with everything else —
 * a visitor who comes back a fortnight later is asked again because there is
 * nothing left that says they were asked before.
 *
 * FAIL CLOSED ON EVERY UNKNOWN. No record, no key, a key of the wrong shape, a
 * record from a build that predates the gate, an unwritable sessions directory,
 * a CLI process nobody told whose work it is doing — all of them are "not
 * affirmed", and not affirmed means the weakest rating.
 *
 * READING THE BIT NEVER WRITES AND NEVER LOCKS, which is why the id is resolved
 * here instead of through xeric_session_id(). That one touches — it mints a
 * record and takes the record's own lock — and xeric_web_clean_answers() reads
 * this from INSIDE a xeric_web_session_edit() callback, with that lock already
 * held. flock() conflicts across two descriptors of the same process, so a read
 * that touched would not be slow, it would be the wizard's save hanging forever.
 */
function xeric_session_adult(?string $sid = null): bool
{
    if ($sid === null) {
        $sid = xeric_session_use(null);             // a worker's forced identity, if it has one
        if ($sid === '') $sid = xeric_web_sid();    // otherwise the cookie, unwritten and untouched
    }
    // A DEVICE THAT MAY NOT BE SHOWN IT IS NOT SHOWN IT, whatever its session
    // affirmed (xeric_session_device). Lowered only, never raised.
    $dev = xeric_session_device();
    if ($dev !== null && $dev['sid'] === $sid && !$dev['adult']) return false;
    $s = xeric_web_session_read($sid);
    return ($s['adult'] ?? null) === true;      // strictly true; "1", 1 and "true" are not
}

/**
 * THE DEVICE THIS REQUEST CAME FROM, when it came with a device token.
 *
 * The client API (api-lib.php) identifies a linked headset or phone by a
 * Bearer token that names a session AND carries one bit of its own: may this
 * device be shown adult material at all. A "kids' headset" is that bit. The
 * verb scripts api.php `require`s open worlds and start workers by asking
 * xeric_session_adult() with no argument, so this is where the bit has to be
 * for every one of them to honour it without knowing a token exists — the
 * same reason xeric_session_use() is how a worker says whose work it does.
 *
 * It can only LOWER the answer: a device bit of true over an unaffirmed
 * session is still unaffirmed. Called with an array ['sid' => …, 'adult' =>
 * bool]: sets it. Called with an explicit null: clears it. Called with
 * nothing: hands back what is set. Nothing sets one but api-lib.php.
 */
function xeric_session_device(?array $dev = null): ?array
{
    static $cur = null;
    if (func_num_args() > 0) {
        $cur = ($dev !== null && preg_match('/^[a-f0-9]{32}$/', (string)($dev['sid'] ?? '')))
            ? ['sid' => (string)$dev['sid'], 'adult' => ($dev['adult'] ?? false) === true] : null;
    }
    return $cur;
}

/**
 * A device token was used: the session it names is in use, so it is not idle.
 *
 * WITHOUT A COOKIE, which is the whole point of not calling
 * xeric_session_touch() here: that one re-sets the session cookie on every
 * visit, and a cookie carrying the owner's raw sid handed to a linked device
 * would turn a revocable token into a credential that cannot be revoked. The
 * record's `seen` (and so its mtime, which the sweep reads) moves at most once
 * a minute, so a pulse every few seconds is not a write every few seconds.
 */
function xeric_session_seen(string $sid, ?int $now = null): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) return;
    $now = $now ?? time();
    $path = xeric_web_session_path($sid);
    if (!is_file($path) || (int)@filemtime($path) >= $now - 60) return;
    try {
        xeric_web_session_edit(function (array &$s) use ($now): void { $s['seen'] = $now; }, $sid);
    } catch (Throwable $e) {
        // A locked record is not a reason to refuse the request.
    }
}

/**
 * Record the affirmation, or take it back.
 *
 * AN ID MINTED A MOMENT AGO CANNOT AFFIRM ANYTHING. xeric_session_touch() will
 * not spend a record on a session nobody has ever come back with, for reasons
 * written out at length there, and this must not be the hole in that wall — a
 * cookieless client POSTing straight at the gate would otherwise write a record
 * per request AND carry an affirmation on each one. Refused, they still get
 * every page; they get them at the weakest rating.
 *
 * @return bool  what the session affirms afterwards — never what was asked for
 */
function xeric_session_affirm(bool $adult, ?string $sid = null): bool
{
    $sid = $sid ?? xeric_session_id();
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) return false;
    if (xeric_session_adult($sid) === $adult) return $adult;    // nothing to write
    if ($adult && xeric_web_sid_fresh($sid)) return false;

    // Under the record's own lock, like every other write to it: a build worker
    // claiming a world into 'own' at the same moment must not lose its claim to
    // this, and this must not lose to it.
    return (bool)xeric_web_session_edit(function (array &$s) use ($adult): bool {
        if ($adult) $s['adult'] = true;
        else        unset($s['adult']);
        return $adult;
    }, $sid);
}

/**
 * The rating this session is allowed to reach, whatever it asked for.
 *
 * THE DEFAULT IS THE CONTROL, NOT THE GATE. Every unattended path — a stranger
 * on the shelf, a half-finished interview, a ✨ world with zero answers — asks
 * for nothing, and an empty string is not one of the legal ratings, so it lands
 * on the weakest one before the affirmation is even consulted. Anything above
 * that takes a deliberate act to reach, and this is where the act is looked for.
 *
 * An unaffirmed session is a person of unknown age, and unknown age is a
 * question the engine has already answered once: it is a minor. The clamp is
 * xeric_effective_rating() — the same function that keeps Billy's son out of the
 * desire economy — called with a character that has no age, so the player's
 * floor and the cast's floor are one piece of code and cannot drift apart.
 */
function xeric_session_rating(string $want, ?string $sid = null): string
{
    $ratings = xeric_ratings();
    $want = strtolower(trim($want));
    if (!in_array($want, $ratings, true)) $want = $ratings[0];      // as xeric_forge_rating() reads it
    // A character with no age at all, which is the one case xeric_is_minor()
    // exists to answer. Not a trick: it is the same question with the same
    // answer, and routing it through the same function is what stops the two
    // floors from being maintained separately and drifting.
    return xeric_session_adult($sid) ? $want : xeric_effective_rating($want, []);
}

/**
 * The highest rating this session may reach, asked for or not.
 *
 * What xeric_forge_build()'s `rating_ceiling` option wants handed to it, named
 * so that nobody has to work out that the ceiling is "the strongest rating, run
 * through the clamp" and reinvent it slightly differently at each call site. It
 * covers what the pin in xeric_web_clean_answers() cannot: a rating the forge
 * invents for itself AFTER the answers went through the funnel.
 */
function xeric_session_ceiling(?string $sid = null): string
{
    $r = xeric_ratings();
    return xeric_session_rating((string)end($r), $sid);
}

// ---------------------------------------------------------------------------
// Ownership
// ---------------------------------------------------------------------------

/**
 * Whose world this is, read from the world itself.
 *
 * Ownership is written twice on purpose — into the session (so "my worlds" is
 * one file read) and into the world directory (so "whose is this?" survives the
 * session being swept, and so eviction can tell a stranger's world from one of
 * the demo's own). A world with no owner.json is a SHARED world: the ones that
 * were forged before sessions existed, and anything the owner drops in by hand.
 * Shared worlds belong to everybody and are never deleted by the sweep.
 */
function xeric_session_world_owner(string $slug): string
{
    $slug = xeric_web_slug($slug);
    if ($slug === '') return '';
    $raw = @file_get_contents(xeric_web_worlds_dir() . '/' . $slug . '/owner.json');
    $d = $raw === false ? null : json_decode($raw, true);
    $sid = is_array($d) ? (string)($d['sid'] ?? '') : '';
    return preg_match('/^[a-f0-9]{32}$/', $sid) ? $sid : '';
}

/** Does this session own that world outright — did it forge it? */
function xeric_session_owns(string $slug, ?string $sid = null): bool
{
    $sid = $sid ?? xeric_session_id();
    $slug = xeric_web_slug($slug);
    if ($slug === '') return false;

    $owner = xeric_session_world_owner($slug);
    if ($owner !== '') return $owner === $sid;

    // No owner file: shared. It is nobody's, which is not the same as everybody's
    // — a shared world is played as a copy, exactly like a stranger's.
    return false;
}

/**
 * WHICH PERSON AT THE CENTRE THIS SESSION IS, in this world. Null for nobody.
 *
 * The owner is player one. Somebody who came through a pairing code is the
 * player their code minted, remembered here against the world's slug — and
 * OWNING and PLAYING are two different things from this line on. The person
 * whose machine it is stays the person whose machine it is: a guest may play a
 * world, and may not shut the server down, edit the world, or delete anything.
 */
function xeric_session_player(string $slug, ?string $sid = null): ?int
{
    $sid  = $sid ?? xeric_session_id();
    $slug = xeric_web_slug($slug);
    if ($slug === '') return null;
    if (xeric_session_owns($slug, $sid)) return 1;              // XERIC_PLAYER_FIRST

    $s = xeric_web_session_read($sid);
    // PARENTHESES: the cast binds tighter than `??`, so `(int)$a[$k] ?? 0` gave
    // the coalesce a `(int)null` that is already 0 and can never be null — the
    // default was dead code, and the array access was no longer the direct
    // operand of `??`, which is the one thing that exempts it from the
    // undefined-index warning. Every chat turn from a non-owner logged one.
    $id = (int)(((array)($s['joined'] ?? []))[$slug] ?? 0);
    return $id > 1 ? $id : null;
}

/** Remember that this session came in through a code, as this person. */
function xeric_session_join(string $slug, int $player, ?string $sid = null): void
{
    $sid  = $sid ?? xeric_session_id();
    $slug = xeric_web_slug($slug);
    if ($slug === '' || $player < 2 || !preg_match('/^[a-f0-9]{32}$/', $sid)) return;
    xeric_web_session_edit(function (array &$s) use ($slug, $player): void {
        $j = (array)($s['joined'] ?? []);
        $j[$slug] = $player;
        $s['joined'] = $j;
    }, $sid);
}

/** Record that this session forged this world. Idempotent. */
function xeric_session_claim(string $slug, ?string $sid = null): void
{
    $sid = $sid ?? xeric_session_id();
    $slug = xeric_web_slug($slug);
    if ($slug === '' || !preg_match('/^[a-f0-9]{32}$/', $sid)) return;

    $dir = xeric_web_worlds_dir() . '/' . $slug;
    if (is_dir($dir)) {
        @file_put_contents($dir . '/owner.json',
            json_encode(['sid' => $sid, 'at' => time()], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    // Under the record's own lock: a worker claims the world it just built while
    // the browser that asked for it is polling, and an unlocked read-then-write
    // loses whichever of the two finished second — which the visitor reads as
    // "the forge built my world and then said it wasn't mine".
    xeric_web_session_edit(function (array &$s) use ($slug): void {
        $own = array_values(array_unique(array_map('strval', (array)($s['own'] ?? []))));
        if (!in_array($slug, $own, true)) $own[] = $slug;
        $s['own'] = $own;
    }, $sid);
}

/** The worlds this session forged, newest claim last. */
function xeric_session_worlds(?string $sid = null): array
{
    $sid = $sid ?? xeric_session_id();
    $s = xeric_web_session_read($sid);
    $out = [];
    foreach ((array)($s['own'] ?? []) as $slug) {
        $slug = xeric_web_slug((string)$slug);
        // A world the sweep took, or one deleted by hand, is not still theirs.
        if ($slug !== '' && is_file(xeric_web_worlds_dir() . '/' . $slug . '/world-template.json')) {
            $out[] = $slug;
        }
    }
    return $out;
}

/** The foreign worlds this session has a copy of. */
function xeric_session_copies(?string $sid = null): array
{
    $sid = $sid ?? xeric_session_id();
    $out = [];
    foreach (glob(xeric_session_db_dir($sid) . '/*.db') ?: [] as $f) {
        $out[] = basename($f, '.db');
    }
    sort($out);
    return $out;
}

/**
 * This visitor, as an object: what is theirs, and what they have left.
 *
 * The whole of what the demo knows about somebody, in one place, so the two
 * screens that show it cannot drift apart — and so the answer to "what are you
 * keeping about me?" is a URL a visitor can open. There is no name, no address
 * and no account in it because there is none to put in it; the session id is
 * truncated to the first eight characters, which tells two visitors apart in a
 * support conversation and is not enough to be either of them.
 */
function xeric_session_me(?string $sid = null): array
{
    $sid = $sid ?? xeric_session_id();
    return [
        'ok'      => true,
        'session' => substr($sid, 0, 8),
        // The affirmation is in here because this list claims to be everything,
        // and a bit that is kept but not shown makes that claim false.
        'adult'   => xeric_session_adult($sid),
        'xerics'  => xeric_session_worlds($sid),
        'copies'  => xeric_session_copies($sid),
        'left'    => xeric_limit_left($sid),
        'queue'   => xeric_queue_status(),
    ];
}

// ---------------------------------------------------------------------------
// The fork — a copy of the state, never of the world
// ---------------------------------------------------------------------------

/**
 * Which database THIS visitor's play of this world writes to.
 *
 * Their own world: the canonical one beside the template, so a world forged in
 * the browser and poked at from the CLI stay one world (play-lib.php).
 *
 * Anybody else's: a copy in their session, made the first time they open it. The
 * copy carries the world's PAST and none of its life (xeric_session_fork_clear)
 * — an absent source is not an error at all: a world nobody has ever opened has
 * no db yet, and xeric_play_open() will seed a fresh one from the shared
 * seed.json.
 *
 * @return array{path:string,mine:bool,forked:bool}  forked = made just now
 */
function xeric_session_db(string $slug, ?string $sid = null): array
{
    $sid  = $sid ?? xeric_session_id();
    $slug = xeric_web_slug($slug);
    $dir  = xeric_web_worlds_dir() . '/' . $slug;

    // THE OWNER, AND ANYBODY THEY LET IN, PLAY THE SAME DATABASE.
    //
    // Forking is for a stranger reading somebody else's shelf: they get a copy
    // so their evening does not move the owner's on. A guest who came through a
    // pairing code is the exact opposite case — being in the SAME evening is
    // the entire point of having been invited, and handing them a private copy
    // would give two people in one house two silently diverging towns.
    //
    // `mine` stays false for them, and that is not a slip: it is the flag every
    // owner-only action reads, so a guest plays the real world and still cannot
    // shut the server down, edit the template, or delete anything.
    if (xeric_session_owns($slug, $sid)) {
        return ['path' => $dir . '/world.db', 'mine' => true, 'forked' => false];
    }
    if (xeric_session_player($slug, $sid) !== null) {
        return ['path' => $dir . '/world.db', 'mine' => false, 'forked' => false];
    }

    $mine = xeric_session_db_dir($sid) . '/' . $slug . '.db';
    if (is_file($mine)) return ['path' => $mine, 'mine' => false, 'forked' => false];

    xeric_web_dir(xeric_session_db_dir($sid));
    $src = $dir . '/world.db';
    if (is_file($src)) xeric_session_snapshot($src, $mine);

    return ['path' => $mine, 'mine' => false, 'forked' => true];
}

/**
 * A consistent copy of a live SQLite file, without writing to the original, and
 * without ever appearing at the destination half-made.
 *
 * VACUUM INTO takes a read transaction and writes a fresh database elsewhere, so
 * whatever is in the source's WAL comes with it and the source is not modified.
 * Older SQLite has no such statement, hence the fallback — and the fallback
 * carries the -wal along, which is the only way a plain copy is honest.
 *
 * IT IS BUILT UNDER A NAME NOBODY ELSE IS USING AND LINKED INTO PLACE. Two taps
 * on the same world open two requests, and the second one's VACUUM INTO used to
 * fail with "output file already exists" — landing in a catch that copied the
 * SOURCE over the first request's open database and then a foreign -wal on top
 * of that. link() cannot overwrite, so the loser of the race finds the winner's
 * copy already there and is simply finished: somebody else won IS success here.
 */
function xeric_session_snapshot(string $src, string $dst): bool
{
    if (is_file($dst)) return true;

    $tmp = $dst . '.' . bin2hex(random_bytes(6)) . '.part';
    try {
        $pdo = new PDO('sqlite:' . $src, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
        $pdo = null;
    } catch (Throwable $e) {
        // ONE failure earns the fallback: a SQLite with no such statement. Every
        // other way this throws — a locked source, a full disk, a name already
        // taken — means the copy did not happen, and quietly copying bytes over
        // whatever is there is how the original bug destroyed a live database.
        if (!xeric_session_vacuum_missing($e)) {
            xeric_web_log('fork: ' . basename($src) . ' could not be snapshotted, ' . $e->getMessage());
            @unlink($tmp);
            return is_file($dst);
        }
        @copy($src, $tmp);
        if (is_file($src . '-wal')) @copy($src . '-wal', $tmp . '-wal');
    }
    if (!is_file($tmp)) return is_file($dst);

    // Only a copy that is demonstrably empty of the last player is linked into
    // place. A world that arrives seeded from seed.json is a small loss; a world
    // that arrives with somebody else's conversations in it is the whole bug.
    if (!xeric_session_fork_clear($tmp)) {
        foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) @unlink($f);
        return is_file($dst);
    }

    $won = @link($tmp, $dst);
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) @unlink($f);
    return $won || is_file($dst);
}

/** The one VACUUM INTO failure that means "this SQLite is too old to have it". */
function xeric_session_vacuum_missing(Throwable $e): bool
{
    $m = strtolower($e->getMessage());
    return str_contains($m, 'syntax error') || str_contains($m, 'near "into"');
}

/**
 * Take the previous player out of a forked world, and leave the world in it.
 *
 * A fork inherits the SHARED PAST — the events, the seeded memories, the arcs
 * the template set — because that past is the whole reason somebody else's world
 * is worth walking into. What it must not inherit is the LIVED part:
 *
 *  • the threads. Copied whole, a stranger's cast panel lights up with the
 *    owner's unread dots, and opening one reads the owner's own typed sentences
 *    back to them as "you: …". This is the leak that matters most.
 *  • what a character remembers of those threads. `source='auto'` is exactly the
 *    memories chat.php harvested from somebody else's conversation; 'seed' and
 *    'event' are the world's own history and stay.
 *  • and the scenes the owner set going. A duet's and a room's diaries
 *    (`source='duet'`, `'room'`, and anything under `room.` — a room the
 *    player stood in writes there) are what two or three people took away from
 *    a conversation the OWNER asked for, watched, and in a room took part in.
 *    This list said only 'auto' because it was written before duets and rooms
 *    existed, so a stranger's Ruth went on remembering an afternoon with Dot
 *    that nothing in the stranger's copy says ever happened — the transcript
 *    that would explain it went with `messages` and `conversations` above. A
 *    memory with no conversation behind it is exactly the kind a character
 *    repeats with total confidence and nobody can trace. Read back too: a
 *    diary that survived the delete is a reason to refuse the handover.
 *  • the per-player bookkeeping — how far each person has been read, harvested
 *    and learned from — which is meaningless about a player who has not arrived.
 *  • the behaviour crumbs and the lessons distilled out of them (learn.php).
 *    Left behind, the new player's world would go on tuning itself to somebody
 *    who answers fast and likes short replies, having never met them.
 *  • the sweep's decision trail and the proactive ledger, which are notes about
 *    evenings the new player was not there for.
 *
 * Each statement stands alone: a world old enough to be missing one of these
 * tables must not stop the rest of the clearing from happening. And the result
 * is READ BACK rather than assumed — a delete that never reached the file
 * because a WAL went unfolded would leave a transcript in somebody else's hands,
 * so the caller is told no and nothing is handed over.
 *
 * @return bool  the copy is clear and safe to hand to a stranger
 */
/**
 * The memories that are a previous player's lived part, as one SQL predicate,
 * shared by the clear and its read-back so the two cannot drift apart: what
 * chat harvested ('auto'), duet and room diaries ('duet', 'room', 'room.*').
 * (A dot is not a LIKE wildcard, so 'room.%' is exactly "room." and anything.)
 */
const XERIC_FORK_LIVED_MEMORIES = "source IN ('auto', 'duet', 'room') OR source LIKE 'room.%'";

function xeric_session_fork_clear(string $path): bool
{
    $sql = [
        'DELETE FROM messages',
        'DELETE FROM conversations',
        "DELETE FROM memories WHERE " . XERIC_FORK_LIVED_MEMORIES,
        "DELETE FROM arcs WHERE key LIKE 'proactive.%' OR key LIKE 'extract.%' OR key LIKE 'learn.%'",
        // skip:% goes too — skip:last is the OWNER'S rewind manifest, and a
        // visitor who never pressed skip was being offered "take back the 6h"
        // on a fork, executing a rewind that deleted hours of the shared past
        // in their copy. (The owner's own db is never touched by a fork; the
        // damage was confined and still wrong.) skip:underway is a stamp for a
        // worker this fork does not have.
        "DELETE FROM world_state WHERE key = 'learn.pending' OR key LIKE 'why:%' OR key LIKE 'proactive:%'"
            . " OR key LIKE 'skip:%'"
            // AND THE OWNER'S OTHER PEOPLE. This list was written before
            // players.php, guest.php and pair.php existed, so a fork inherited
            // the whole co-op layer: the roster, the high-water mark of issued
            // ids, every guest row, and the outstanding pairing codes.
            //
            // The roster alone would be bookkeeping about somebody who has not
            // arrived. The guest row is worse, because it REACHES A PROMPT: a
            // written-in guest carries a model-written sentence placing a real
            // third party in the world ("Corey is Neil's cousin from Omaha, down
            // for the funeral"), and xeric_guest_block() puts it into EVERY
            // character's system message along with an instruction to talk to
            // them. A stranger who has never met the owner opened a copy and
            // found the whole cast addressing somebody who is not there, by
            // name. And the pairing codes are live credentials to the OWNER'S
            // world — short-lived, but no part of a stranger's copy.
            . " OR key = 'players' OR key = 'players.issued'"
            . " OR key LIKE 'guest.%' OR key LIKE 'pair.%'"
            // THE OWNER'S TABLE. room:live is a conversation the owner is
            // having right now and room:seal:* the ones still waiting for
            // their close — both about message rows this fork has just
            // deleted, and a live one would seat a stranger in the owner's
            // conversation mid-sentence. The diaries those rooms already
            // wrote (source room / room.*) go with the lived memories above.
            . " OR key LIKE 'room:%'",
        'DELETE FROM signals',
        // The owner's outstanding message-photos are jobs about their own private
        // thread — whose text this fork has just deleted. Left behind they are
        // counted as the stranger's pending work on the play screen, and the
        // reaper renders them, spending a GPU or somebody's API key on the
        // owner's conversation. Portraits and places are re-offered idempotently
        // by the backfill, so only the message ones need clearing.
        "DELETE FROM photo_jobs WHERE kind = 'message'",
        // Views of rooms are PLACE views, not the owner's private life, and
        // could be kept — but their bytes live beside the OWNER'S db, and a
        // fork's scenes dir is its own (scene.php serves only from there). A
        // done row left behind would be a broken picture on the stranger's
        // sidebar, and worse, the one row for that look: the queue is one row
        // per (place, fingerprint), so it would answer every later press in
        // the fork with a file that is not there. Only a viewer link, which
        // has no file, survives; anything still in flight was the owner's ask
        // on the owner's machine and goes too.
        "DELETE FROM scene_jobs WHERE NOT (status = 'done' AND kind = 'viewer')",
        // The owner's lines, read aloud: their messages went above, and their
        // clips live beside the OWNER'S db. A row left behind would only ever
        // miss (the fork's clips dir is its own), but it names a message the
        // stranger never saw, and that is reason enough. Absent on a world no
        // one has voiced yet, which the loop below shrugs off.
        'DELETE FROM voice_clips',
    ];

    try {
        $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout = 5000');
    } catch (Throwable $e) {
        xeric_web_log('fork: cannot open ' . basename($path) . ' to clear it, ' . $e->getMessage());
        return false;
    }

    foreach ($sql as $q) {
        try {
            $db->exec($q);
        } catch (Throwable $e) {
            xeric_web_log('fork: ' . basename($path) . ', ' . $e->getMessage());
        }
    }
    try { $db->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) { /* nothing to fold in */ }
    $db = null;

    return xeric_session_fork_is_clear($path);
}

/** Read it back: is there anything of the last player still in this file? */
function xeric_session_fork_is_clear(string $path): bool
{
    try {
        $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['messages', 'conversations'] as $t) {
            try {
                $n = (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            } catch (Throwable $e) {
                continue;                          // no such table: nothing there to leak
            }
            if ($n > 0) {
                xeric_web_log('fork: ' . basename($path) . " still holds $n rows in $t, not handing it over");
                return false;
            }
        }
        // The lived memories, read back for the same reason as the transcript:
        // a diary is a line the stranger's cast would SAY. Absent table on an
        // ancient world is nothing to leak, as above.
        try {
            $n = (int)$db->query('SELECT COUNT(*) FROM memories WHERE ' . XERIC_FORK_LIVED_MEMORIES)->fetchColumn();
            if ($n > 0) {
                xeric_web_log('fork: ' . basename($path) . " still holds $n lived memory row(s), not handing it over");
                return false;
            }
        } catch (Throwable $e) {
            // no memories table: nothing there to leak
        }
        // AND THE OWNER'S OTHER PEOPLE, read back for the same reason the
        // transcript is: a guest row is the one piece of this that reaches a
        // stranger's PROMPT rather than just their database, naming a real third
        // party to every character in the world.
        try {
            $n = (int)$db->query("SELECT COUNT(*) FROM world_state WHERE key = 'players'"
                . " OR key = 'players.issued' OR key LIKE 'guest.%' OR key LIKE 'pair.%'")->fetchColumn();
            if ($n > 0) {
                xeric_web_log('fork: ' . basename($path) . " still holds $n co-op row(s), not handing it over");
                return false;
            }
        } catch (Throwable $e) {
            // No world_state at all is a world too old to have had a roster.
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Expiry and eviction
// ---------------------------------------------------------------------------

/** Every live session, oldest use first: [sid, seen, bytes]. */
function xeric_session_live(): array
{
    $out = [];
    foreach (glob(xeric_web_sessions_dir() . '/*.json') ?: [] as $f) {
        $sid = basename($f, '.json');
        if (!preg_match('/^[a-f0-9]{32}$/', $sid)) continue;
        $out[] = ['sid' => $sid, 'seen' => (int)@filemtime($f), 'bytes' => xeric_session_bytes($sid)];
    }
    usort($out, fn($a, $b) => $a['seen'] <=> $b['seen']);
    return $out;
}

/** What one session is costing: its record, its copies, and the worlds it forged. */
function xeric_session_bytes(string $sid): int
{
    $n = (int)@filesize(xeric_web_session_path($sid));
    foreach (glob(xeric_session_db_dir($sid) . '/*') ?: [] as $f) $n += (int)@filesize($f);
    foreach (xeric_session_worlds($sid) as $slug) {
        foreach (glob(xeric_web_worlds_dir() . '/' . $slug . '/*') ?: [] as $f) $n += (int)@filesize($f);
    }
    return $n;
}

/**
 * Forget a session completely: the record, its copies, and its worlds.
 *
 * A world is only removed when its owner.json still names THIS session — a
 * shared world (no owner file) or one somebody else has since claimed is never
 * taken away by somebody else's expiry.
 *
 * @return array{bytes:int,worlds:int}
 */
function xeric_session_forget(string $sid): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) return ['bytes' => 0, 'xerics' => 0];

    $bytes = xeric_session_bytes($sid);
    $worlds = 0;

    foreach (xeric_session_worlds($sid) as $slug) {
        if (xeric_session_world_owner($slug) !== $sid) continue;
        $dir = xeric_web_worlds_dir() . '/' . $slug;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        if (@rmdir($dir)) $worlds++;
    }

    foreach (glob(xeric_session_db_dir($sid) . '/*') ?: [] as $f) @unlink($f);
    @rmdir(xeric_session_db_dir($sid));
    @unlink(xeric_web_session_path($sid));

    return ['bytes' => $bytes, 'xerics' => $worlds];
}

/**
 * Drop everything nobody has come back for. Cheap, occasional, never fatal.
 *
 * Called from boot.php's session write, which fires it one time in twenty: this
 * app has no cron and a sweep that only runs when somebody is here is a sweep
 * that cannot wedge anything.
 */
function xeric_session_sweep(?int $now = null): int
{
    $cut = ($now ?? time()) - XERIC_SESSION_TTL;
    $n = 0;
    foreach (glob(xeric_web_sessions_dir() . '/*.json') ?: [] as $f) {
        if ((int)@filemtime($f) >= $cut) continue;
        $sid = basename($f, '.json');
        if (!preg_match('/^[a-f0-9]{32}$/', $sid)) { @unlink($f); continue; }
        xeric_session_forget($sid);
        $n++;
    }
    // A copy directory whose session is gone (a sweep that was interrupted, a
    // record deleted by hand) is nobody's and costs disk.
    foreach (glob(xeric_session_root() . '/*') ?: [] as $d) {
        if (!is_dir($d)) continue;
        if (is_file(xeric_web_session_path(basename($d)))) continue;
        foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
    return $n;
}
