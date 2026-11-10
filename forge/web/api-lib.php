<?php
/**
 * api-lib.php — the client API's library: identity, the envelope, the shapers.
 *
 * api.php is the router; everything it does that another file will want is
 * here, so the write half (and its tests) can call the same shapers the read
 * half answers with. docs/API.md is the contract; this file is how it is kept.
 *
 * THREE THINGS LIVE HERE AND NOWHERE ELSE:
 *
 *  1. THE TOKEN STORE. A device token is `xd1_` and 32 random bytes in hex,
 *     handed out once and kept only as its sha256. It names a session (the sid
 *     is the identity and is never handed out), a role, a label and one bit of
 *     its own: whether this device may be shown adult material at all. The
 *     store is a web-layer concern — a world database knows nothing about
 *     devices — so it is its own small SQLite file in the data dir, created
 *     here, not in engine/state.php. A token is handed to a person by one of
 *     two ceremonies built on pair.php's discipline (8 characters, 5 minutes,
 *     single use, hashed, a lockout): LINK (the owner mints a code on the box,
 *     link.php; the device becomes the same person) and JOIN (a pair code from
 *     the invite; the device becomes a new player). Tokens work on api.php and
 *     nowhere else: link.php, model.php, power.php, notify.php and the forge
 *     never read an Authorization header, so a device is a stranger there.
 *
 *  2. THE ENVELOPE. Every body leaves as {api, ok, …}; a refusal is
 *     {api, ok:false, error, kind}. The inconsistencies the old wire grew
 *     (FUTURE.md, Appendix C) are normalised on the way out — `w`→`world`,
 *     `ok:1`→`true`, `why`→`error`, `mid`→`id`, job `message`→`text`, a
 *     refusal with no `kind` given one by its status, every `*_html` dropped.
 *     boot.php's xeric_web_json() runs bodies through this when api.php has
 *     registered it, which is how refusals raised deep inside come out shaped.
 *
 *  3. THE PER-ROUTE SHAPERS. A read calls the existing function directly and
 *     hands its answer to a shaper here; a verb `require`s the existing script
 *     (where.php, say.php, tick.php, …) through boot.php's two seams, and its
 *     answer passes through xeric_api_shape_verb() on the way into the
 *     envelope. Nothing a verb does is reimplemented here: the doors, the
 *     limits and the refusals are the scripts' own.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/pair.php';

/** The contract's version: major in the path, the whole of it here and in a header. */
const XERIC_API_VERSION = '1.0';

/** The major version this file answers, as it appears in the path. */
const XERIC_API_MAJOR = 'v1';

/** What a token looks like. Anything else on a Bearer line is refused unread. */
const XERIC_API_TOKEN_RE = '/^xd1_[a-f0-9]{64}$/';

/** The two things a device can be: the same person (a link) or a new player (a join). */
const XERIC_API_ROLES = ['owner', 'guest'];

/**
 * Wrong link codes before the door stops answering, and for how long. Tighter
 * than pair.php's eight: a pair code lets somebody PLAY one world, a link code
 * makes a device the owner.
 */
const XERIC_API_LINK_TRIES = 5;
const XERIC_API_LINK_LOCK  = 120;

// ---------------------------------------------------------------------------
// The token store
// ---------------------------------------------------------------------------

/** The store, opened (and made) once per process. */
function xeric_api_tokens_db(): PDO
{
    static $db = null;
    static $at = '';
    $path = (string)xeric_web_config()['data_dir'] . '/api-tokens.db';
    if ($db !== null && $at === $path) return $db;

    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $at = $path;
    $db->exec('PRAGMA busy_timeout = 3000');
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS api_tokens (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            hash        TEXT    NOT NULL UNIQUE,           -- sha256 of the token; the token is never kept
            sid         TEXT    NOT NULL,                  -- the session it acts as
            role        TEXT    NOT NULL,                  -- owner (a link) | guest (a join)
            label       TEXT    NOT NULL DEFAULT '',       -- what the person called the device
            adult       INTEGER NOT NULL DEFAULT 0,        -- may this device be shown adult material
            created_at  INTEGER NOT NULL,
            seen_at     INTEGER,
            revoked_at  INTEGER
        )
    SQL);
    $db->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_sid ON api_tokens(sid)');
    // The link codes waiting to be typed into a device, hashed as pair.php
    // hashes its own, and the door's two numbers (wrong tries, locked until).
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS api_links (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            hash        TEXT    NOT NULL UNIQUE,           -- xeric_pair_hash of 'link:' + the code
            sid         TEXT    NOT NULL,                  -- the session the device will be
            label       TEXT    NOT NULL DEFAULT '',
            adult       INTEGER NOT NULL DEFAULT 0,        -- the bit the token will carry
            dies        INTEGER NOT NULL,
            created_at  INTEGER NOT NULL
        )
    SQL);
    $db->exec('CREATE TABLE IF NOT EXISTS api_door (k TEXT PRIMARY KEY, v INTEGER NOT NULL)');
    @chmod($path, 0600);
    return $db;
}

/**
 * A new device token for a session. Returned once; only its hash is kept.
 *
 * Called by the two ceremonies (xeric_api_link_claim, xeric_api_join) and by
 * the tests; nothing else hands one out.
 */
function xeric_api_token_mint(string $sid, string $role, string $label, bool $adult): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) throw new InvalidArgumentException('not a session id');
    if (!in_array($role, XERIC_API_ROLES, true)) throw new InvalidArgumentException('role is owner or guest');
    $label = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $label)), 0, 60);

    $token = 'xd1_' . bin2hex(random_bytes(32));
    xeric_api_tokens_db()->prepare('INSERT INTO api_tokens (hash, sid, role, label, adult, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $sid, $role, $label, $adult ? 1 : 0, time()]);
    return $token;
}

/**
 * Who a Bearer token is, or null — and null is a 401, never a fall-through.
 *
 * A token whose session has been swept is dead with it: the record is the
 * identity, and a token that outlived it would be a key to an empty house that
 * a new visitor might one day be handed. A revoked one is dead too.
 *
 * @return ?array{sid:string,role:string,adult:bool,id:int,label:string}
 */
function xeric_api_token_resolve(string $bearer): ?array
{
    $bearer = trim($bearer);
    if (!preg_match(XERIC_API_TOKEN_RE, $bearer)) return null;
    try {
        $db = xeric_api_tokens_db();
        $st = $db->prepare('SELECT * FROM api_tokens WHERE hash = ? AND revoked_at IS NULL LIMIT 1');
        $st->execute([hash('sha256', $bearer)]);
        $row = $st->fetch();
        $st->closeCursor();
    } catch (Throwable $e) {
        return null;                                   // no store is no token, never solo
    }
    if (!$row) return null;
    $sid = (string)$row['sid'];
    if (!preg_match('/^[a-f0-9]{32}$/', $sid) || !in_array((string)$row['role'], XERIC_API_ROLES, true)) return null;
    if (!is_file(xeric_web_session_path($sid))) return null;

    // SEEN ON EVERY AUTHENTICATED CALL, to the minute: the device row (what
    // link.php's "last seen" reads) and the session record behind it (what
    // the sweep reads — a session used only through a headset is not idle).
    // At most one write a minute each, so a pulse every few seconds is not a
    // write every few seconds. And never a Set-Cookie: xeric_session_seen()
    // exists so this does not have to call xeric_session_touch(), which would
    // hand the device the owner's raw sid.
    if ((int)($row['seen_at'] ?? 0) < time() - 60) {
        try {
            $db->prepare('UPDATE api_tokens SET seen_at = ? WHERE id = ?')->execute([time(), (int)$row['id']]);
        } catch (Throwable $e) {
            // A locked store is not a reason to refuse a read.
        }
    }
    xeric_session_seen($sid);
    return ['sid' => $sid, 'role' => (string)$row['role'], 'adult' => (int)$row['adult'] === 1,
            'id' => (int)$row['id'], 'label' => (string)$row['label']];
}

/**
 * This session's devices, newest first: what link.php lists. Never the hash.
 *
 * @return list<array{id:int,label:string,role:string,adult:bool,created:int,seen:?int}>
 */
function xeric_api_devices(string $sid): array
{
    $st = xeric_api_tokens_db()->prepare('SELECT id, label, role, adult, created_at, seen_at FROM api_tokens
                                          WHERE sid = ? AND revoked_at IS NULL ORDER BY id DESC');
    $st->execute([$sid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['id' => (int)$r['id'], 'label' => (string)$r['label'], 'role' => (string)$r['role'],
                  'adult' => (int)$r['adult'] === 1, 'created' => (int)$r['created_at'],
                  'seen' => $r['seen_at'] === null ? null : (int)$r['seen_at']];
    }
    return $out;
}

/** Revoke one of this session's devices. Somebody else's id is not found, not refused. */
function xeric_api_device_revoke(string $sid, int $id): bool
{
    $st = xeric_api_tokens_db()->prepare('UPDATE api_tokens SET revoked_at = ?
                                          WHERE id = ? AND sid = ? AND revoked_at IS NULL');
    $st->execute([time(), $id, $sid]);
    return $st->rowCount() > 0;
}

/**
 * Take a device's adult bit away. LOWER ONLY: there is no function that
 * raises it, because the only honest source of "this device may be shown
 * adult material" is the session's affirmation at the moment of linking,
 * and a kids' headset that could be flipped back from the API would be a
 * kids' headset in name.
 */
function xeric_api_device_lower(string $sid, int $id): bool
{
    $st = xeric_api_tokens_db()->prepare('UPDATE api_tokens SET adult = 0
                                          WHERE id = ? AND sid = ? AND revoked_at IS NULL AND adult = 1');
    $st->execute([$id, $sid]);
    return $st->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// The ceremonies — link (the same person) and join (a new player)
// ---------------------------------------------------------------------------

/**
 * May a token be minted over THIS connection?
 *
 * A Bearer token is a long-lived credential: a pair code lived five minutes, a
 * token lives until it is revoked, and over plain http on a LAN anybody on the
 * wifi who can read one packet has it. So a token is handed out only over
 * TLS, or to a client on this machine — which is where `adb reverse` (a Quest
 * in developer mode) and Quest Link with desktop Chrome both land, and where
 * `tailscale serve` hands its already-encrypted requests — or when the
 * operator has said, with XERIC_LAN_TOKENS=1, that they understand the risk.
 *
 * Null when it may; otherwise the refusal, which names the ways that work.
 */
function xeric_api_mint_refusal(array $server): ?array
{
    $https = strtolower(trim((string)($server['HTTPS'] ?? '')));
    if ($https !== '' && $https !== 'off') return null;
    $peer = (string)($server['REMOTE_ADDR'] ?? '');
    // No peer is the CLI: the launcher's own tooling, as xeric_web_solo() reads it.
    if ($peer === '' || in_array($peer, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) return null;
    if (in_array(strtolower(trim((string)getenv('XERIC_LAN_TOKENS'))), ['1', 'true', 'yes', 'on'], true)) return null;
    return xeric_api_no(403, 'insecure',
        'A device token is a long-lived key, and this connection is plain http over the network, so anybody '
        . 'on it could read the key as it was handed over. No token was made and the code was not used. Two '
        . 'setups need no TLS: Quest Link with desktop Chrome at http://127.0.0.1:8787, or a Quest in developer '
        . 'mode with `adb reverse tcp:8787 tcp:8787` (the headset then reaches this machine as itself). For '
        . 'anything else, put TLS in front: `tailscale serve --bg http://127.0.0.1:8787` and use the https '
        . 'address it prints. An operator who accepts the risk on a network they trust can set XERIC_LAN_TOKENS=1.');
}

/** A link code, normalised the way somebody types one: case, spaces and dashes forgiven. */
function xeric_api_link_norm(string $code): string
{
    return strtoupper((string)preg_replace('/[\s\-]+/', '', $code));
}

/**
 * Mint a link code for this session. Returns the plaintext ONCE.
 *
 * pair.php's code (its alphabet, its length) and its hash, with its lifetime
 * and its cap on how many may wait. The bit the token will carry is decided
 * HERE, from the session's affirmation at this moment: a session that has
 * not affirmed cannot mint an adult device, and an owner who says "this one
 * is the kids' headset" mints it lowered.
 *
 * @return array{code:string,dies:int,ttl:int,adult:bool}
 * @throws RuntimeException when too many codes are already waiting
 */
function xeric_api_link_new(string $sid, string $label = '', bool $adult = true): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) throw new InvalidArgumentException('not a session id');
    $db  = xeric_api_tokens_db();
    $now = time();
    $db->prepare('DELETE FROM api_links WHERE dies <= ?')->execute([$now]);
    $st = $db->prepare('SELECT COUNT(*) FROM api_links WHERE sid = ?');
    $st->execute([$sid]);
    if ((int)$st->fetchColumn() >= XERIC_PAIR_MAX) {
        throw new RuntimeException('There are already ' . XERIC_PAIR_MAX
            . ' link codes waiting. Use one, or let them run out.');
    }
    $adult = $adult && xeric_session_adult($sid);
    $code  = xeric_pair_code();
    $label = mb_substr(trim((string)preg_replace('/\s+/u', ' ', $label)), 0, 60);
    $db->prepare('INSERT INTO api_links (hash, sid, label, adult, dies, created_at) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([xeric_pair_hash('link:' . $code), $sid, $label, $adult ? 1 : 0, $now + XERIC_PAIR_TTL, $now]);
    return ['code' => $code, 'dies' => $now + XERIC_PAIR_TTL, 'ttl' => XERIC_PAIR_TTL, 'adult' => $adult];
}

/** Put this session's waiting link codes out. */
function xeric_api_link_clear(string $sid): void
{
    xeric_api_tokens_db()->prepare('DELETE FROM api_links WHERE sid = ?')->execute([$sid]);
}

/** Seconds the link door is still shut for; 0 when it is answering. */
function xeric_api_link_locked(?int $now = null): int
{
    $st = xeric_api_tokens_db()->query("SELECT v FROM api_door WHERE k = 'locked'");
    $until = (int)($st->fetchColumn() ?: 0);
    $st->closeCursor();
    $now = $now ?? time();
    return $until > $now ? $until - $now : 0;
}

/**
 * Somebody typed a link code into a device. A token for the SAME person, or a
 * refusal for the router.
 *
 * pair.php's rules, kept: every wrong answer — never minted, run out, already
 * used — gets one sentence and one status (410), because the difference is
 * information; wrong tries are counted and past XERIC_API_LINK_TRIES the door
 * shuts for XERIC_API_LINK_LOCK seconds (429), for everybody, since a guesser
 * does not say whose code they are guessing at; and the burn and the mint are
 * one transaction, so two devices typing one code at once cannot both get in.
 * The door is the whole machine's, not a world's: a link code names a person.
 */
function xeric_api_link_claim(string $code, string $label = ''): array
{
    $db  = xeric_api_tokens_db();
    $now = time();
    $want = xeric_pair_hash('link:' . xeric_api_link_norm($code));

    $db->exec('BEGIN IMMEDIATE');
    try {
        $locked = (int)($db->query("SELECT v FROM api_door WHERE k = 'locked'")->fetchColumn() ?: 0);
        if ($locked > $now) {
            $db->exec('COMMIT');
            $wait = $locked - $now;
            return xeric_api_no(429, 'locked', 'Too many wrong codes. Try again in '
                . max(1, (int)ceil($wait / 10) * 10) . ' seconds.', ['Retry-After' => (string)$wait]);
        }
        $db->prepare('DELETE FROM api_links WHERE dies <= ?')->execute([$now]);
        $st = $db->prepare('SELECT * FROM api_links WHERE hash = ? LIMIT 1');
        $st->execute([$want]);
        $row = $st->fetch();
        $st->closeCursor();

        $sid = $row ? (string)$row['sid'] : '';
        if (!$row || !is_file(xeric_web_session_path($sid))) {
            $tries = (int)($db->query("SELECT v FROM api_door WHERE k = 'tries'")->fetchColumn() ?: 0) + 1;
            $put = $db->prepare('INSERT INTO api_door (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
            if ($tries >= XERIC_API_LINK_TRIES) {
                $put->execute(['locked', $now + XERIC_API_LINK_LOCK]);
                $put->execute(['tries', 0]);
            } else {
                $put->execute(['tries', $tries]);
            }
            if ($row) $db->prepare('DELETE FROM api_links WHERE id = ?')->execute([(int)$row['id']]);
            $db->exec('COMMIT');
            if ($tries >= XERIC_API_LINK_TRIES) {
                return xeric_api_no(429, 'locked', 'Too many wrong codes. Try again in '
                    . XERIC_API_LINK_LOCK . ' seconds.', ['Retry-After' => (string)XERIC_API_LINK_LOCK]);
            }
            return xeric_api_no(410, 'code', 'That code is not good: it may have been used already, or run '
                . 'out. Make another one on link.php.');
        }

        // Burned FIRST, in the same transaction as the mint.
        $db->prepare('DELETE FROM api_links WHERE id = ?')->execute([(int)$row['id']]);
        $db->exec("INSERT INTO api_door (k, v) VALUES ('tries', 0) ON CONFLICT(k) DO UPDATE SET v = 0");
        $label = trim($label) !== '' ? $label : (string)$row['label'];
        // The bit as minted, and the session's affirmation as it stands NOW:
        // an owner who took the affirmation back in the five minutes between
        // has not linked an adult device.
        $adult = (int)$row['adult'] === 1 && xeric_session_adult($sid);
        $token = xeric_api_token_mint($sid, 'owner', $label !== '' ? $label : 'a device', $adult);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
    $id = (int)$db->query('SELECT last_insert_rowid()')->fetchColumn();
    return xeric_api_yes(['token' => $token, 'role' => 'owner', 'label' => $label !== '' ? $label : 'a device',
                          'adult' => $adult, 'device' => $id]);
}

/**
 * Somebody scanned an invite on a device that is not a browser: a NEW player,
 * exactly as join.php makes one, with a token handed back instead of a
 * redirect.
 *
 * join.php's own calls, in its own order: the owner's real database (never a
 * fork — joining the owner's evening is the point), xeric_pair_claim (the
 * burn, the lockout and the new player in one transaction), then a session
 * that remembers which player it is. The session is new and its own: the sid
 * is never handed out, only the token that names it. It is minted the way a
 * new cookie is (the per-address budget), and it is never affirmed, so a
 * guest's device carries adult=false — the guest was never asked.
 */
function xeric_api_join(string $slug, string $code, string $name, string $label = ''): array
{
    $slug = xeric_web_slug($slug);
    $dir  = xeric_web_worlds_dir() . '/' . $slug;
    if ($slug === '' || !is_file($dir . '/world-template.json')) {
        return xeric_api_no(404, 'no_world', 'That code points at a world this machine does not have.');
    }
    $T = json_decode((string)file_get_contents($dir . '/world-template.json'), true) ?: [];

    $rec = ['created' => time()];
    if (function_exists('xeric_limit_new_session')) $rec = xeric_limit_new_session($rec);
    if (function_exists('xeric_limit_session_denied') && xeric_limit_session_denied($rec)) {
        return xeric_api_no(429, 'limit', 'This machine has let in as many new people today as it will.');
    }

    $name = mb_substr(trim($name), 0, 40);
    try {
        $db = xeric_state_open($dir . '/world.db');
        xeric_state_migrate($db);
        $player = xeric_pair_claim($db, $T, xeric_api_link_norm($code), $name);
    } catch (Throwable $e) {
        $m = trim((string)preg_replace('/^(pair|players|guest):\s*/', '', $e->getMessage()));
        $locked = str_contains($m, 'too many wrong codes');
        return xeric_api_no($locked ? 429 : 410, $locked ? 'locked' : 'code', $m !== '' ? ucfirst($m) : 'That code is not good.');
    }

    $sid = bin2hex(random_bytes(16));
    xeric_web_session_edit(function (array &$s) use ($rec): void {
        $s = $rec + $s;
        $s['seen'] = time();
    }, $sid);
    xeric_session_join($slug, $player, $sid);
    $label = trim($label) !== '' ? $label : ($name !== '' ? $name : 'a guest');
    $token = xeric_api_token_mint($sid, 'guest', $label, false);
    return xeric_api_yes(['token' => $token, 'role' => 'guest', 'world' => $slug, 'player' => $player,
                          'label' => $label, 'adult' => false]);
}

/** The body the router read: JSON, or a form (multipart included). Never anything else. */
function xeric_api_body(): array
{
    if ($_POST !== []) return $_POST;
    $raw = (string)@file_get_contents('php://input');
    $d = $raw === '' ? null : json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/**
 * `POST link` and `POST join`: the two routes that need nobody to be anybody,
 * because the code is the credential. Cross-site is still refused (a page on
 * another origin has no business typing codes into this machine), and the
 * secure-context rule is checked BEFORE the code is looked at, so a refused
 * mint never burns the code somebody is still holding.
 */
function xeric_api_ceremony(string $method, string $which, array $body, array $server): array
{
    if ($method !== 'POST') {
        return xeric_api_no(405, 'method', 'A ' . $which . ' is a POST.', ['Allow' => 'POST']);
    }
    if (xeric_web_cross_site()) {
        return xeric_api_no(403, 'cross_site', 'That request came from another site, so it was not answered.');
    }
    if (($no = xeric_api_mint_refusal($server)) !== null) return $no;
    $code = trim((string)($body['code'] ?? ''));
    if ($code === '') return xeric_api_no(400, 'bad_request', 'There is no code in that.');
    $label = (string)($body['label'] ?? '');
    if ($which === 'link') return xeric_api_link_claim($code, $label);
    return xeric_api_join((string)($body['world'] ?? $body['w'] ?? ''), $code, (string)($body['name'] ?? ''), $label);
}

// ---------------------------------------------------------------------------
// Identity
// ---------------------------------------------------------------------------

/** The Authorization header, wherever this SAPI put it. '' when absent. */
function xeric_api_authorization(array $server): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (trim((string)($server[$k] ?? '')) !== '') return trim((string)$server[$k]);
    }
    if (function_exists('getallheaders') && PHP_SAPI !== 'cli') {
        foreach ((array)@getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0 && trim((string)$v) !== '') return trim((string)$v);
        }
    }
    return '';
}

/**
 * Who is asking, in the order Appendix C gives: a Bearer header, then the
 * session cookie, then solo. The answer is put through xeric_session_use(),
 * the mechanism every worker already uses, so ownership, forks, machines, the
 * meter and the limits all resolve against it without knowing a token exists.
 *
 * Either ['ok' => true, sid, via, role, adult] — `adult` being what this
 * viewer may be shown: the session's affirmation AND the device's own bit —
 * or ['ok' => false, status, error, kind] for the router to send.
 */
function xeric_api_identify(array $server): array
{
    $auth = xeric_api_authorization($server);
    if ($auth !== '') {
        // A credential that is not ours, or one of ours that does not resolve,
        // is a 401. Falling through to solo would make a stolen-and-revoked
        // token on a loopback headset the owner.
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) || ($tok = xeric_api_token_resolve($m[1])) === null) {
            return ['ok' => false, 'status' => 401, 'kind' => 'token',
                    'error' => 'That device token is not one this machine knows, or it has been revoked.'];
        }
        xeric_session_use($tok['sid']);
        $adult = xeric_session_adult($tok['sid']) && $tok['adult'];
        // The device's bit, where every verb script will find it: they open
        // worlds and start workers by asking xeric_session_adult() bare.
        xeric_session_device(['sid' => $tok['sid'], 'adult' => $tok['adult']]);
        return ['ok' => true, 'sid' => $tok['sid'], 'via' => 'bearer', 'role' => $tok['role'],
                'adult' => $adult, 'device' => $tok['id']];
    }

    // A cookie or the machine's own identity rides along with a request
    // whether or not the page that sent it meant to, so neither is honoured
    // from somebody else's page — on any method, reads included: a read of a
    // world is a read of where somebody is and what was said to them.
    if (xeric_web_cross_site()) {
        return ['ok' => false, 'status' => 403, 'kind' => 'cross_site',
                'error' => 'That request came from another site, so it was not answered.'];
    }
    // DNS REBINDING. A page on evil.example whose name is re-pointed at
    // 127.0.0.1 is same-origin to itself and its requests arrive from this
    // machine, which on a solo install is the owner with no cookie at all.
    // The one thing it cannot forge is the Host header: the browser sends the
    // name it dialled. SEAM: the proper home for this is xeric_web_solo() in
    // boot.php, where it would cover every page; it is enforced here, for
    // the API, until that lands.
    if (xeric_web_solo() && !xeric_api_host_pinned($server)) {
        return ['ok' => false, 'status' => 403, 'kind' => 'host',
                'error' => 'This machine answers its own owner only at its own address (127.0.0.1, localhost '
                         . 'or the address it was started on), and that request named another.'];
    }
    $sid = xeric_session_id();
    xeric_session_use($sid);
    return ['ok' => true, 'sid' => $sid, 'via' => xeric_web_solo() ? 'solo' : 'cookie',
            'role' => 'owner', 'adult' => xeric_session_adult($sid)];
}

/**
 * Is the Host this request named one this machine answers to as itself?
 *
 * 127.0.0.1, localhost, [::1], and the address it was told to bind
 * (XERIC_BIND, which ./xeric sets; XERIC_HOST when an operator names one) —
 * and any IP literal, because rebinding needs a NAME: a browser that dialled
 * an address sent that address, and the only address that reaches this
 * server is this server's. A port, when given, must be the one this server
 * is listening on (SERVER_PORT, or XERIC_PORT). No Host at all is not a
 * browser (every browser sends one) and is let through.
 */
function xeric_api_host_pinned(array $server): bool
{
    $raw = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
    if ($raw === '') return true;
    if (preg_match('/^\[([0-9a-f:.]+)\](?::(\d+))?$/', $raw, $m)) {
        $name = $m[1];
        $port = $m[2] ?? '';
    } elseif (preg_match('/^([^:]+)(?::(\d+))?$/', $raw, $m)) {
        $name = rtrim($m[1], '.');
        $port = $m[2] ?? '';
    } else {
        return false;
    }
    if ($port !== '') {
        $ports = array_filter([(string)($server['SERVER_PORT'] ?? ''), (string)(getenv('XERIC_PORT') ?: '')]);
        if ($ports !== [] && !in_array($port, $ports, true)) return false;
    }
    if (filter_var($name, FILTER_VALIDATE_IP) !== false) return true;
    $ok = ['localhost'];
    foreach (['XERIC_BIND', 'XERIC_HOST'] as $k) {
        $v = strtolower(trim((string)(getenv($k) ?: '')));
        if (str_contains($v, '@')) $v = substr($v, strrpos($v, '@') + 1);   // an ssh target names its host last
        $v = trim((string)preg_replace('/:\d+$/', '', $v), '[]');
        if ($v !== '') $ok[] = $v;
    }
    return in_array($name, $ok, true);
}

// ---------------------------------------------------------------------------
// The envelope
// ---------------------------------------------------------------------------

/** A refusal's kind when the code that raised it did not name one. */
function xeric_api_kind_for(int $status): string
{
    return [400 => 'bad_request', 401 => 'token', 403 => 'forbidden', 404 => 'not_found',
            405 => 'method', 409 => 'conflict', 429 => 'limit', 500 => 'bug', 502 => 'upstream',
            503 => 'unavailable'][$status] ?? ($status >= 500 ? 'bug' : 'bad_request');
}

/** Every key ending in _html, at any depth, gone. */
function xeric_api_drop_html(array $a): array
{
    foreach ($a as $k => $v) {
        if (is_string($k) && str_ends_with($k, '_html')) { unset($a[$k]); continue; }
        if (is_array($v)) $a[$k] = xeric_api_drop_html($v);
    }
    return $a;
}

/**
 * Any body, enveloped and normalised. What boot.php's shaper seam calls.
 *
 * $event is set only when progress.php is streaming for us: an SSE frame is a
 * body too, and carries the same `api` and `ok`.
 */
function xeric_api_envelope(array $body, int $status = 200, ?string $event = null): array
{
    // The old wire's names, once each.
    if (array_key_exists('why', $body)) {
        if (!array_key_exists('error', $body)) $body['error'] = $body['why'];
        unset($body['why']);
    }
    if (array_key_exists('mid', $body)) {
        if (!array_key_exists('id', $body)) $body['id'] = $body['mid'];
        unset($body['mid']);
    }
    if (array_key_exists('w', $body)) {
        if (!array_key_exists('world', $body)) $body['world'] = $body['w'];
        unset($body['w']);
    }
    unset($body['api']);
    $body = xeric_api_drop_html($body);

    if ($event !== null) {
        // A job's notes said `message` in some places and `text` in others.
        if (array_key_exists('message', $body) && !array_key_exists('text', $body)) {
            $body['text'] = $body['message'];
            unset($body['message']);
        }
        $ok = $event !== 'failed';
        if (!$ok) {
            $body['error'] ??= (string)($body['text'] ?? 'the job failed');
            $body['kind']  ??= 'job';
        }
    } else {
        $ok = $status < 400 && !array_key_exists('error', $body)
            && (!array_key_exists('ok', $body) || (bool)$body['ok']);
        if (!$ok) {
            $body['error'] = (string)($body['error'] ?? 'that was refused');
            $body['kind']  = (string)($body['kind'] ?? xeric_api_kind_for($status));
        }
    }
    unset($body['ok']);
    return ['api' => XERIC_API_VERSION, 'ok' => $ok] + $body;
}

/** A refusal, as a response for the router. */
function xeric_api_no(int $status, string $kind, string $error, array $headers = []): array
{
    return ['status' => $status, 'headers' => $headers,
            'body' => ['ok' => false, 'error' => $error, 'kind' => $kind]];
}

/** An answer, as a response for the router. */
function xeric_api_yes(array $body, array $headers = []): array
{
    return ['status' => 200, 'headers' => $headers, 'body' => $body];
}

// ---------------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------------

/**
 * Where this API is, and how it was reached. A link handed out (a look's
 * `src`) is built the way the request arrived: PATH_INFO when it came that
 * way, `?r=` when it did not — a host that strips PATH_INFO strips it from
 * the links too, so the link has to be the shape that worked.
 */
function xeric_api_base(?string $script = null, ?bool $viaQuery = null): array
{
    static $base = ['script' => 'api.php', 'query' => false];
    if ($script !== null) $base['script'] = $script;
    if ($viaQuery !== null) $base['query'] = $viaQuery;
    return $base;
}

/** A v1 URL for a route, e.g. `worlds/x/look/k/f`. Segments are encoded here. */
function xeric_api_url(array $segments, array $query = []): string
{
    $b = xeric_api_base();
    $path = '/' . XERIC_API_MAJOR . '/' . implode('/', array_map(
        fn($s) => str_replace('%3A', ':', rawurlencode((string)$s)), $segments));
    if ($b['query']) {
        return $b['script'] . '?r=' . rawurlencode($path) . ($query ? '&' . http_build_query($query) : '');
    }
    return $b['script'] . $path . ($query ? '?' . http_build_query($query) : '');
}

/** A look's bytes link, from the one scene.php hands out (xeric_play_look_src). */
function xeric_api_look_src(?string $legacy, string $slug): ?string
{
    if ($legacy === null || $legacy === '') return $legacy;
    $q = [];
    parse_str((string)parse_url($legacy, PHP_URL_QUERY), $q);
    if (!isset($q['k'], $q['f'])) return $legacy;
    return xeric_api_url(['worlds', $slug, 'look', (string)$q['k'], (string)$q['f']]);
}

// ---------------------------------------------------------------------------
// The per-route shapers — the write half reuses these
// ---------------------------------------------------------------------------

/**
 * `GET worlds/{w}` — xeric_play_state() with the HTML gone, and the clock a
 * client can run by itself: `epoch` is the world's now, `offset` the signed
 * seconds it runs ahead of the server's wall clock, `server` the server's
 * wall clock at the moment of answering. A running world's now is
 * `epoch + (client_now - server)`; a paused one's is `epoch`.
 */
function xeric_api_shape_state(array $w, string $sid): array
{
    $state = xeric_api_shape_state_of(xeric_play_state($w, $sid), $w['db']);
    return ['world' => (string)$w['slug'], 'mine' => (bool)$w['mine'], 'guest' => xeric_play_is_guest($w),
            'rating' => xeric_world_rating($w['template']), 'queue' => xeric_queue_status()] + $state;
}

/**
 * A xeric_play_state() already made — the one say.php answers with — as v1
 * reads it: no HTML, and the clock a client can run by itself.
 */
function xeric_api_shape_state_of(array $state, PDO $db): array
{
    $state = xeric_api_drop_html($state);
    $state['clock'] = (array)($state['clock'] ?? []);
    $state['clock']['epoch']  = (int)($state['clock']['epoch'] ?? xeric_clock_epoch($db));
    $state['clock']['offset'] = xeric_clock_offset($db);
    $state['clock']['server'] = time();
    return $state;
}

/** World epochs for message ids, read off the rows: [id => epoch]. */
function xeric_api_epochs(PDO $db, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if ($ids === []) return [];
    $st = $db->prepare('SELECT id, COALESCE(world_epoch, created_at) AS e FROM messages WHERE id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    $ep = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ep[(int)$r['id']] = (int)$r['e'];
    $st->closeCursor();
    return $ep;
}

/** `GET worlds/{w}/look` — the brief and the view, with every `src` a v1 link. */
function xeric_api_shape_look(array $view, string $slug): array
{
    if (is_array($view['scene'] ?? null)) {
        $view['scene']['src'] = xeric_api_look_src($view['scene']['src'] ?? null, $slug);
    }
    foreach ((array)($view['day'] ?? []) as $i => $d) {
        $view['day'][$i]['src'] = xeric_api_look_src($d['src'] ?? null, $slug);
    }
    if (is_array($view['sound'] ?? null)) {
        $view['sound']['src'] = xeric_api_look_src($view['sound']['src'] ?? null, $slug);
    }
    unset($view['ok']);
    return ['world' => $slug] + $view;
}

/**
 * One thread's messages with ids and world epochs. The display string `when`
 * stays; `epoch` is the world time it was said at, the number a client can
 * sort and format by itself. xeric_play_thread() does not carry it, so it is
 * read here off the same rows (one query, by id).
 *
 * @param array $th  what xeric_play_thread() answered
 */
function xeric_api_shape_thread(PDO $db, array $th, int $since = 0): array
{
    $msgs = array_values(array_filter((array)($th['messages'] ?? []), fn($m) => (int)($m['id'] ?? 0) > $since));
    $ids  = array_map(fn($m) => (int)$m['id'], $msgs);
    $ep   = xeric_api_epochs($db, $ids);
    // WHO SAID A PLAYER LINE, on every row and always the same shape — a
    // client keys a bubble on it without first asking whether the thread has
    // two people in it. The player's index on a user row (NULL on disk is the
    // first), null on everybody else's. `redacted` says a line stamped above
    // this device's rating arrived emptied (xeric_messages_recent): the kids'
    // headset reads "(a line you are not shown)" and nothing of what was said.
    $who = [];
    if ($ids !== []) {
        $st = $db->prepare('SELECT id, role, player FROM messages WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $who[(int)$r['id']] = (string)$r['role'] === 'user' ? max(1, (int)($r['player'] ?? 0)) : null;
        }
        $st->closeCursor();
    }
    foreach ($msgs as $i => $m) {
        $msgs[$i]['epoch']    = $ep[(int)$m['id']] ?? 0;
        $msgs[$i]['player']   = $who[(int)$m['id']] ?? null;
        $msgs[$i]['redacted'] = !empty($m['redacted']);
    }
    $last = $ids === [] ? $since : max($ids);
    return ['conversation_id' => $th['conversation_id'] ?? null, 'since' => $since, 'last' => $last,
            'messages' => $msgs];
}

/** One job record, as JSON and as an SSE frame both read it. */
function xeric_api_shape_job_record(int $i, array $rec): array
{
    $k = (string)($rec['k'] ?? 'note');
    unset($rec['k']);
    if (array_key_exists('message', $rec) && !array_key_exists('text', $rec)) {
        $rec['text'] = $rec['message'];
        unset($rec['message']);
    }
    // `error` is `failed`, as the stream has always called it (an EventSource
    // fires its own `error` on every reconnect).
    return ['id' => $i, 'kind' => $k === 'error' ? 'failed' : $k] + xeric_api_drop_html($rec);
}

/** `GET jobs/{id}?from=` — xeric_web_job_read(), shaped. */
function xeric_api_shape_job(string $id, array $read): array
{
    return ['job' => $id, 'done' => (bool)$read['done'], 'next' => (int)$read['next'],
            'count' => (int)$read['count'],
            'records' => array_map(fn($r) => xeric_api_shape_job_record((int)$r['i'], (array)$r['rec']),
                                   (array)$read['records'])];
}

/** The heart's last pass, read off its file. 0 when it has never beaten. */
function xeric_api_heart_beat(): int
{
    $tick = json_decode((string)@file_get_contents((string)xeric_web_config()['data_dir'] . '/heart.tick'), true);
    return is_array($tick) ? (int)($tick['at'] ?? 0) : 0;
}

/**
 * `GET worlds/{w}/pulse` — what changed, small enough to ask every few seconds.
 *
 * THE CURSOR IS A HASH OF EVERYTHING A CLIENT WOULD REPAINT FOR: the clock's
 * offset (a skip, a trip, a rewind) and whether it is paused, where the player
 * is (and, with company, where every player is), who is where right now (a
 * schedule turning over moves people with no row written), the newest message
 * and event, the unread dots, the room renders'
 * queue, and the heart's beat. NOT the epoch: a running clock changes every
 * second, and the client runs it from `offset`. Same cursor, same ETag, and a
 * conditional GET is a 304 with no body.
 */
function xeric_api_pulse(array $w): array
{
    $t   = $w['template'];
    $db  = $w['db'];
    $now = xeric_clock_now($db, $t);
    $here = xeric_player_where($t, $db);

    $present = [];
    foreach (xeric_world_who_is_where($t, $now, xeric_dead_handles($db)) as $h => $row) {
        $present[(string)$h] = $row['where'] ?? null;
    }
    ksort($present);
    $with = $here === null ? [] : array_keys(array_filter($present, fn($p) => $p === $here));

    $one = static fn(string $sql): int => (int)$db->query($sql)->fetchColumn();
    $unread = [];
    foreach ($db->query("SELECT handle, unread FROM conversations WHERE kind = 'chat' AND unread > 0
                         ORDER BY handle")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $unread[] = ['handle' => (string)$r['handle'], 'n' => (int)$r['unread']];
    }
    $scenes = ['pending' => 0, 'working' => 0, 'done' => 0, 'failed' => 0, 'latest' => 0];
    foreach ($db->query('SELECT status, COUNT(*) AS n, MAX(id) AS m FROM scene_jobs GROUP BY status')
                ->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (array_key_exists((string)$r['status'], $scenes)) $scenes[(string)$r['status']] = (int)$r['n'];
        $scenes['latest'] = max($scenes['latest'], (int)$r['m']);
    }

    $body = [
        'world'    => (string)$w['slug'],
        'offset'   => xeric_clock_offset($db),
        'paused'   => xeric_clock_is_paused($db),
        'where'    => $here,
        'here'     => $with,
        'last_message' => $one('SELECT COALESCE(MAX(id), 0) FROM messages'),
        'last_event'   => $one('SELECT COALESCE(MAX(id), 0) FROM events'),
        'unread'   => $unread,
        'scenes'   => $scenes,
        'beat'     => xeric_api_heart_beat(),
        // The open room, small: room.php's own public summary cut to what a
        // client repaints for, plus its newest line — so the cursor moves when
        // somebody at the table starts typing, speaks, arrives or leaves, or
        // the room closes (null again). Rides in $body, so it is hashed.
        'room'     => xeric_api_room_summary($t, $db),
    ];
    // EVERY BODY, once there is more than one: a friend's walk is something the
    // owner's screen repaints for (their tag in the look, their ring on the
    // map), and their position is no part of `where`, which is the asker's own.
    // A world of one carries no such key, so its cursor is the one it was.
    if (xeric_players_many($db)) {
        $bodies = [];
        foreach (xeric_travel_players($t, $db) as $id => $p) $bodies[(string)$id] = $p['where'];
        $body['players'] = $bodies;
    }
    $cursor = substr(hash('sha256', json_encode([$body, $present], JSON_UNESCAPED_SLASHES)), 0, 32);
    // Not hashed: they change every second, or are only for a client's clock.
    return $body + ['cursor' => $cursor, 'epoch' => (int)$now['epoch'], 'server' => time()];
}

/**
 * The open room as the pulse carries it, or null when nobody is at a table.
 * xeric_play_room_public() (what room.php's poll answers `live` with), cut to
 * the fields that change when the room does, and the id of its newest row.
 */
function xeric_api_room_summary(array $t, PDO $db): ?array
{
    try {
        $live = xeric_room_live_get($db);
        $pub  = xeric_play_room_public($t, $live, true);
    } catch (Throwable $e) {
        return null;
    }
    if ($pub === null) return null;
    $st = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?');
    $st->execute([(int)$pub['conv']]);
    $last = (int)$st->fetchColumn();
    $st->closeCursor();
    return ['conv' => (int)$pub['conv'], 'place' => (string)$pub['place'], 'last' => $last,
            'turn' => (bool)$pub['turn'], 'typing' => $pub['typing']['h'] ?? null,
            'present' => array_column((array)$pub['present'], 'h'),
            'said' => (int)$pub['said'], 'beats' => (int)$pub['beats']];
}

/** Does an If-None-Match header name this ETag? */
function xeric_api_etag_matches(string $header, string $etag): bool
{
    $header = trim($header);
    if ($header === '') return false;
    if ($header === '*') return true;
    $want = preg_replace('#^W/#', '', $etag);
    foreach (explode(',', $header) as $tag) {
        if (preg_replace('#^W/#', '', trim($tag)) === $want) return true;
    }
    return false;
}

/** `GET /` — the version, how one may identify, and which machines exist (booleans, never addresses). */
function xeric_api_index(array $who): array
{
    $is = static function (callable $f): bool {
        try { return $f() !== null; } catch (Throwable $e) { return false; }
    };
    return [
        'version'  => XERIC_API_VERSION,
        'versions' => [XERIC_API_MAJOR],
        'auth'     => [
            'modes' => ['bearer', xeric_web_solo() ? 'solo' : 'cookie'],
            'via'   => (string)$who['via'],
            'role'  => (string)$who['role'],
            // The ceremonies that hand out a token: POST link, POST join.
            'link'  => true,
            'join'  => true,
        ],
        'features' => [
            'model'  => (static function (string $sid): bool {
                try { return xeric_web_connected(xeric_web_model($sid)); } catch (Throwable $e) { return false; }
            })((string)$who['sid']),
            'scene'  => $is('xeric_scene_endpoint'),
            'voice'  => $is('xeric_voice_endpoint'),
            'image'  => $is('xeric_image_endpoint'),
            'ears'   => $is('xeric_ears_endpoint'),
            'pulse'  => true,
            'sse'    => true,
            'write'  => true,
        ],
    ];
}

// ---------------------------------------------------------------------------
// The router's half that is a function
// ---------------------------------------------------------------------------

/** The route, as segments, from PATH_INFO or `?r=`. The second value says which. */
function xeric_api_path(array $server, array $get): array
{
    $raw = (string)($server['PATH_INFO'] ?? '');
    $viaQuery = false;
    if (trim($raw, '/') === '' && isset($get['r']) && is_string($get['r'])) {
        $raw = (string)$get['r'];
        $viaQuery = true;
    }
    $segs = array_values(array_filter(explode('/', $raw), fn($s) => $s !== ''));
    return [array_map('rawurldecode', $segs), $viaQuery];
}

/** A world opened for this viewer, or a refusal. */
function xeric_api_open(string $slug, bool $adult): array
{
    $slug = xeric_web_slug($slug);
    if ($slug === '') return ['no' => xeric_api_no(404, 'no_world', 'which xeric?')];
    try {
        return ['w' => xeric_play_open($slug, null, $adult)];
    } catch (Throwable $e) {
        return ['no' => xeric_api_no(404, 'no_world', $e->getMessage())];
    }
}

/** Bytes, as a response for the router. */
function xeric_api_bytes(string $bytes, string $mime, array $headers = []): array
{
    return ['status' => 200, 'bytes' => $bytes,
            'headers' => ['Content-Type' => $mime, 'Content-Length' => (string)strlen($bytes),
                          'X-Content-Type-Options' => 'nosniff'] + $headers];
}

/**
 * Which route this is, and the methods it answers. Null for none.
 *
 * ONE TABLE, so a wrong method on a real route is a 405 that says what the
 * route does take (Allow), and a path that is no route is a 404 whatever the
 * method. The ceremonies (`link`, `join`) are not here: they need nobody to
 * be anybody, so api.php answers them before asking who is calling.
 *
 * @return ?array{0:string,1:list<string>}
 */
function xeric_api_match(array $r): ?array
{
    $GH  = ['GET', 'HEAD'];
    $P   = ['POST'];
    $n   = count($r);
    if ($n === 0) return ['index', $GH];
    if ($r[0] === 'worlds' && $n === 1) return ['worlds', $GH];
    if ($r[0] === 'jobs' && $n === 2) return ['job', $GH];
    if ($r[0] !== 'worlds' || $n < 2) return null;
    if ($n === 2) return ['world', $GH];

    $what = (string)$r[2];
    if ($n === 3) {
        return match ($what) {
            'pulse' => ['pulse', $GH],
            'map'   => ['map', $GH],
            'look'  => ['look', ['GET', 'HEAD', 'POST']],
            'go'    => ['go', $P],
            'skip'  => ['skip', $P],
            'rewind'=> ['rewind', $P],
            'hear'  => ['hear', $P],
            'room'  => ['room', ['GET']],
            default => null,
        };
    }
    if ($what === 'look' && $n === 5) return ['look_bytes', $GH];
    if ($what === 'threads' && $n === 4) return ['thread', $GH];
    if ($what === 'threads' && $n === 5 && in_array($r[4], ['say', 'read'], true)) return [(string)$r[4], $P];
    if ($what === 'messages' && $n === 5 && $r[4] === 'audio') return ['audio', ['GET', 'HEAD', 'POST']];
    if ($what === 'room' && $n === 4 && in_array($r[3], ['say', 'carry', 'leave'], true)) return ['room_' . $r[3], $P];
    return null;
}

/**
 * A verb, as the router hands it to api.php: which script, and what it is to
 * be told. The script is `require`d unchanged — its doors (owner, limits,
 * pace, queue) are its own and arrive enveloped through the shaper seam.
 *
 * ONLY THE FIELDS THE VERB TAKES are passed from the body, and the world (and
 * handle, and action) come from the path, never from the body: a body that
 * says `"rewind": 1` to a skip, or names another world, is not heard.
 */
function xeric_api_verb(string $name, string $slug, array $body, array $get = [], string $method = 'POST',
                        array $extra = []): array
{
    $pick = static function (array $keys) use ($body): array {
        $out = [];
        foreach ($keys as $k) if (array_key_exists($k, $body)) $out[$k] = $body[$k];
        return $out;
    };
    $w = ['w' => $slug, 'world' => $slug];
    [$script, $input] = match ($name) {
        'go'        => ['where.php', $pick(['to']) + $w],
        'look'      => ['scene.php', ['a' => 'look'] + $w],
        'say'       => ['say.php', $pick(['text']) + ['handle' => (string)($extra['handle'] ?? '')] + $w],
        'skip'      => ['tick.php', $pick(['span']) + $w],
        'rewind'    => ['tick.php', ['rewind' => 1] + $w],
        'hear'      => ['hear.php', ['h' => (string)($body['h'] ?? $body['handle'] ?? '')] + $w],
        'room'      => ['room.php', null],
        'room_say'  => ['room.php', $pick(['text']) + ['a' => 'say'] + $w],
        'room_carry'=> ['room.php', ['a' => 'carry'] + $w],
        'room_leave'=> ['room.php', ['a' => 'leave'] + $w],
        'audio'     => ['speak.php', null],
    };
    if (str_starts_with($name, 'room_')) $get = ['w' => $slug, 'a' => substr($name, 5)];
    return ['status' => 200, 'headers' => [], 'verb' => [
        'name' => $name, 'script' => $script, 'slug' => $slug, 'method' => $method,
        'input' => $input, 'get' => $get,
    ]];
}

/**
 * Get the request ready for a verb script, and hand back the path to require.
 *
 *  - the shaper: the verb's answer through xeric_api_shape_verb, then the
 *    envelope — refusals raised anywhere inside included;
 *  - the body through xeric_web_input_set, and $_GET and the method the
 *    script reads;
 *  - BEARER PASSES THE CROSS-SITE GUARD. The scripts' guard
 *    (xeric_web_input → xeric_web_csrf_guard, xeric_web_spend_guard) exists
 *    because a cookie and the solo identity ride along with any request a
 *    browser sends. An Authorization header never does: a page on another
 *    origin that tried to set one would need a CORS preflight this API never
 *    answers, so a Bearer request in hand is not a forged one. The two
 *    headers the guard reads are therefore dropped for a Bearer-identified
 *    request — cookie and solo were already refused cross-site, on every
 *    method, by xeric_api_identify();
 *  - hear.php runs its request half only when it IS the request, so it is
 *    told it is;
 *  - a refusal some script answers with a status and no body (speak.php's
 *    bare 404) still leaves enveloped (xeric_api_bare_refusal).
 */
function xeric_api_verb_prepare(array $v, array $who): string
{
    $name  = (string)$v['name'];
    $slug  = (string)$v['slug'];
    $adult = (bool)$who['adult'];
    xeric_web_json_shaper(function (array $b, int $st) use ($name, $slug, $adult): array {
        return xeric_api_envelope(xeric_api_shape_verb($name, $b, $st, $slug, $adult), $st);
    });
    xeric_web_input_set($v['input']);
    $_GET = (array)$v['get'];
    $_SERVER['REQUEST_METHOD'] = (string)$v['method'];
    if (($who['via'] ?? '') === 'bearer') unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);

    $script = __DIR__ . '/' . basename((string)$v['script']);
    if (basename($script) === 'hear.php') $_SERVER['SCRIPT_FILENAME'] = (string)realpath($script);

    ob_start();
    register_shutdown_function('xeric_api_bare_refusal', $name === 'audio'
        ? ['no_message', 'That line has no voice in this world.']
        : ['not_found', 'There is nothing here by that name.']);
    return $script;
}

/** A status with no body, at the end of a verb: the same refusal, enveloped. */
function xeric_api_bare_refusal(array $default): void
{
    $st = http_response_code();
    if (!is_int($st) || $st < 400 || headers_sent()) return;
    if (ob_get_level() > 0 && ob_get_length() > 0) return;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(xeric_api_envelope(['error' => (string)$default[1],
        'kind' => $st === 404 ? (string)$default[0] : xeric_api_kind_for($st)], $st),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * A verb's answer, as v1 reads it, before the envelope: the world named; a
 * `state` (say.php's) with the clock a client can run; a job pointed at the
 * v1 jobs route rather than progress.php; and the message epochs the read
 * half promises beside every display `when` (the reply's, a room's rows').
 * Refusals pass through untouched: the envelope shapes those.
 */
function xeric_api_shape_verb(string $name, array $body, int $status, string $slug, bool $adult): array
{
    if ($status >= 400 || isset($body['error']) || (array_key_exists('ok', $body) && !$body['ok'])) return $body;

    $db = null;
    $open = static function () use (&$db, $slug, $adult): ?PDO {
        if ($db === null) {
            try { $db = xeric_play_open($slug, null, $adult)['db']; } catch (Throwable $e) { $db = false; }
        }
        return $db instanceof PDO ? $db : null;
    };

    if (is_array($body['state'] ?? null) && ($d = $open()) !== null) {
        $body['state'] = xeric_api_shape_state_of($body['state'], $d);
    }
    $mid = (int)($body['mid'] ?? $body['id'] ?? 0);
    if (in_array($name, ['say', 'room_say'], true) && $mid > 0 && ($d = $open()) !== null) {
        $body['epoch'] = xeric_api_epochs($d, [$mid])[$mid] ?? 0;
    }
    if ($name === 'room' && is_array($body['rows'] ?? null) && $body['rows'] !== [] && ($d = $open()) !== null) {
        $ep = xeric_api_epochs($d, array_column($body['rows'], 'id'));
        foreach ($body['rows'] as $i => $row) $body['rows'][$i]['epoch'] = $ep[(int)($row['id'] ?? 0)] ?? 0;
    }
    if (is_string($body['job'] ?? null) && $body['job'] !== '' && xeric_web_job_ok($body['job'])) {
        $body['stream'] = xeric_api_url(['jobs', $body['job']]);
    } elseif (is_string($body['stream'] ?? null) && str_starts_with($body['stream'], 'progress.php')) {
        unset($body['stream']);
    }
    return ['world' => $slug] + $body;
}

/**
 * `POST threads/{h}/read` — what play.php's a=thread does beyond the thread:
 * the unread count read BEFORE the dot goes out, the dot put out
 * (xeric_play_mark_read), and the learn signal (xeric_learn_read) — "this
 * person, not that one", the cheapest thing anybody does that means anything,
 * attributed to whoever is at the centre. Never for an empty thread: a tap
 * that opened nothing says nothing (learn.php).
 */
function xeric_api_read(array $w, string $handle): array
{
    $db   = $w['db'];
    $conv = xeric_conversation_find($db, $handle, 'chat');
    $unread = $conv !== null ? (int)$conv['unread'] : 0;
    $signal = null;
    if ($conv !== null) {
        xeric_play_mark_read($db, (int)$conv['id']);
        $signal = xeric_learn_read($db, $handle, $unread, xeric_clock_epoch($db),
            xeric_session_player((string)$w['slug']) ?? XERIC_PLAYER_FIRST);
    }
    return ['world' => (string)$w['slug'], 'handle' => $handle, 'read' => $conv !== null,
            'unread' => $unread, 'conversation_id' => $conv !== null ? (int)$conv['id'] : null,
            'learned' => $signal !== null && (int)$signal > 0];
}

/**
 * Answer one request. The router (api.php) has set the base and identified
 * the caller; this picks the route and calls the existing function behind
 * it — or, for a verb, names the existing script api.php is to require.
 *
 * @return array{status:int,headers:array,body?:?array,bytes?:string,delegate?:string,query?:array,verb?:array}
 */
function xeric_api_route(string $method, array $seg, array $get, array $server, array $who, array $body = []): array
{
    if ($seg === []) {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return xeric_api_no(405, 'method', 'This answers GET only.', ['Allow' => 'GET, HEAD']);
        }
        return xeric_api_yes(['versions' => [XERIC_API_MAJOR],
                              'base' => xeric_api_url([])]);
    }
    if ($seg[0] !== XERIC_API_MAJOR) {
        return xeric_api_no(404, 'version', 'This machine speaks ' . XERIC_API_MAJOR . '.');
    }
    $r     = array_slice($seg, 1);
    $n     = count($r);
    $sid   = (string)$who['sid'];
    $match = xeric_api_match($r);
    if ($match === null) return xeric_api_no(404, 'no_route', 'There is no such route in v1.');
    [$name, $allow] = $match;
    if (!in_array($method, $allow, true)) {
        return xeric_api_no(405, 'method', 'That route answers ' . implode(', ', $allow) . '.',
                            ['Allow' => implode(', ', $allow)]);
    }

    if ($name === 'index') return xeric_api_yes(xeric_api_index($who));
    if ($name === 'worlds') return xeric_api_yes(['worlds' => xeric_play_worlds($sid)]);

    if ($name === 'job') {
        $id = (string)$r[1];
        if (!xeric_web_job_ok($id) || !is_file(xeric_web_job_path($id))) {
            return xeric_api_no(404, 'no_job', 'That is not a job this machine is running.');
        }
        $from = max(0, (int)($get['from'] ?? 0));
        if (str_contains(strtolower((string)($server['HTTP_ACCEPT'] ?? '')), 'text/event-stream')) {
            return ['status' => 200, 'headers' => [], 'delegate' => 'progress.php',
                    'query' => ['job' => $id, 'from' => (string)$from]];
        }
        return xeric_api_yes(xeric_api_shape_job($id, xeric_web_job_read($id, $from)));
    }

    $slug = xeric_web_slug((string)$r[1]);

    // THE VERBS THAT ARE A SCRIPT, required as they are. Each opens the world
    // itself, as the page it is, so it is not opened here.
    if (in_array($name, ['go', 'say', 'skip', 'rewind', 'hear', 'room', 'room_say', 'room_carry', 'room_leave'], true)
        || ($name === 'look' && $method === 'POST')) {
        if ($slug === '') return xeric_api_no(404, 'no_world', 'which xeric?');
        $q = $name === 'room' ? ['w' => $slug, 'after' => (string)max(0, (int)($get['after'] ?? 0))]
            + (isset($get['c']) ? ['c' => (string)(int)$get['c']] : []) : [];
        return xeric_api_verb($name, $slug, $body, $q, $name === 'room' ? 'GET' : 'POST',
                              ['handle' => (string)($r[3] ?? '')]);
    }

    $o = xeric_api_open($slug, (bool)$who['adult']);
    if (isset($o['no'])) return $o['no'];
    $w    = $o['w'];
    $slug = (string)$w['slug'];
    $t    = $w['template'];
    $db   = $w['db'];

    if ($name === 'world') return xeric_api_yes(xeric_api_shape_state($w, $sid));

    if ($name === 'pulse') {
        $p    = xeric_api_pulse($w);
        $etag = '"' . $p['cursor'] . '"';
        $h    = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization, Cookie'];
        if (xeric_api_etag_matches((string)($server['HTTP_IF_NONE_MATCH'] ?? ''), $etag)) {
            return ['status' => 304, 'headers' => $h, 'body' => null];
        }
        return xeric_api_yes($p, $h);
    }

    if ($name === 'map') {
        return xeric_api_yes(['world' => $slug] + xeric_travel_map($t, $db));
    }

    if ($name === 'look') {
        return xeric_api_yes(xeric_api_shape_look(xeric_play_look_view($t, $db, $slug, null, false,
                                                                       !xeric_play_is_guest($w)), $slug));
    }

    if ($name === 'look_bytes') {
        // scene.php's bytes, by scene.php's own function: the same sniffing,
        // the same allowlist, the same attachment rule.
        $file = xeric_play_look_file($db, (string)$r[3], (string)$r[4]);
        if ($file === null) return xeric_api_no(404, 'no_look', 'There is no such view of this room on this disk.');
        return xeric_api_bytes($file['bytes'], $file['mime'], [
            'Content-Disposition' => $file['inline'] ? 'inline'
                : 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) . '"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    if ($name === 'thread' || $name === 'read') {
        $handle = (string)$r[3];
        $c = xeric_world_character($t, $handle);
        if ($c === null) return xeric_api_no(404, 'no_character', 'Nobody in this xeric answers to that name.');
        if ($name === 'read') return xeric_api_yes(xeric_api_read($w, $handle));
        $since = max(0, (int)($get['since'] ?? 0));
        // A GET CHANGES NOTHING. It used to put the unread dot out, as
        // play.php's a=thread does, which made a poll, a retry or a cache
        // warm-up a write. Both things a person looking at a screen says —
        // the dot out, and the learn signal ("this person, not that one") —
        // are one explicit act: `POST threads/{h}/read`.
        $th   = xeric_play_thread($t, $db, $handle, $since > 0 ? 500 : 60, false);
        $gone = xeric_deaths($db)[$handle] ?? null;
        return xeric_api_yes(['world' => $slug, 'handle' => $handle,
            'name' => (string)($c['display_name'] ?? $handle),
            'dead' => $gone !== null, 'how' => $gone !== null ? (string)$gone['how'] : '',
            'one_line' => (string)($c['one_line'] ?? '')] + xeric_api_shape_thread($db, $th, $since));
    }

    if ($name === 'audio') {
        $msg = xeric_play_spoken($db, (int)$r[3]);
        if ($msg === null) return xeric_api_no(404, 'no_message', 'That line has no voice in this world.');

        if ($method === 'POST') {
            // A MISS RENDERS: speak.php, required as it is — its door (the
            // owner's machine), its spend guard and its `reroll` meter are
            // spent on a miss and only on a miss; a hit is a file read.
            if (xeric_voice_endpoint() === null) {
                return xeric_api_no(404, 'no_voice', 'There is no voice machine set up here.');
            }
            return xeric_api_verb('audio', $slug, [], ['w' => $slug, 'm' => (string)(int)$msg['id']], 'GET');
        }

        // GET: A CACHED CLIP ONLY. Rendering spends the owner's voice
        // machine, which is the POST. What has already been said aloud is
        // anybody's who can read the thread.
        $row  = xeric_voice_of($db, (int)$msg['id']);
        $dir  = dirname((string)$w['db_path']) . '/voice';
        $clip = $row === null ? null
              : xeric_play_voice_bytes($dir . '/' . basename((string)$row['file']), (string)$row['mime'], true);
        if ($clip === null) {
            return xeric_api_no(404, 'not_rendered', 'That line has not been read aloud yet. POST to this '
                . 'route to have it read.');
        }
        return xeric_api_bytes($clip['bytes'], $clip['mime'],
            ['Content-Disposition' => 'inline', 'Cache-Control' => 'private, max-age=86400']);
    }

    return xeric_api_no(404, 'no_route', 'There is no such route in v1.');
}
