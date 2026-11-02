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
 *     here, not in engine/state.php. Only the read half is built: tokens are
 *     resolved, and minted only by the tests. The link and join ceremonies
 *     that hand one to a person are the write half's.
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
 *     hands its answer to a shaper here; the shaper is a function so the write
 *     half can reuse it on the `state` a verb answers with.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

/** The contract's version: major in the path, the whole of it here and in a header. */
const XERIC_API_VERSION = '1.0';

/** The major version this file answers, as it appears in the path. */
const XERIC_API_MAJOR = 'v1';

/** What a token looks like. Anything else on a Bearer line is refused unread. */
const XERIC_API_TOKEN_RE = '/^xd1_[a-f0-9]{64}$/';

/** The two things a device can be: the same person (a link) or a new player (a join). */
const XERIC_API_ROLES = ['owner', 'guest'];

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
    @chmod($path, 0600);
    return $db;
}

/**
 * A new device token for a session. Returned once; only its hash is kept.
 *
 * USED ONLY BY THE TESTS FOR NOW. The ceremonies that hand a token to a
 * person — link (the owner shows a code, the device becomes the same person)
 * and join (a new player) — are the write half's, built on pair.php's
 * discipline. Until then nothing in the app calls this.
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

    // Seen, at most once a minute: a pulse every few seconds must not be a
    // write every few seconds.
    if ((int)($row['seen_at'] ?? 0) < time() - 60) {
        try {
            $db->prepare('UPDATE api_tokens SET seen_at = ? WHERE id = ?')->execute([time(), (int)$row['id']]);
        } catch (Throwable $e) {
            // A locked store is not a reason to refuse a read.
        }
    }
    return ['sid' => $sid, 'role' => (string)$row['role'], 'adult' => (int)$row['adult'] === 1,
            'id' => (int)$row['id'], 'label' => (string)$row['label']];
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
        return ['ok' => true, 'sid' => $tok['sid'], 'via' => 'bearer', 'role' => $tok['role'],
                'adult' => xeric_session_adult($tok['sid']) && $tok['adult']];
    }

    // A cookie or the machine's own identity rides along with a request
    // whether or not the page that sent it meant to, so neither is honoured
    // from somebody else's page — on any method, reads included: a read of a
    // world is a read of where somebody is and what was said to them.
    if (xeric_web_cross_site()) {
        return ['ok' => false, 'status' => 403, 'kind' => 'cross_site',
                'error' => 'That request came from another site, so it was not answered.'];
    }
    $sid = xeric_session_id();
    xeric_session_use($sid);
    return ['ok' => true, 'sid' => $sid, 'via' => xeric_web_solo() ? 'solo' : 'cookie',
            'role' => 'owner', 'adult' => xeric_session_adult($sid)];
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
    $state = xeric_api_drop_html(xeric_play_state($w, $sid));
    $state['clock']['epoch']  = (int)($state['clock']['epoch'] ?? xeric_clock_epoch($w['db']));
    $state['clock']['offset'] = xeric_clock_offset($w['db']);
    $state['clock']['server'] = time();
    return ['world' => (string)$w['slug'], 'mine' => (bool)$w['mine'], 'guest' => xeric_play_is_guest($w),
            'rating' => xeric_world_rating($w['template']), 'queue' => xeric_queue_status()] + $state;
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
    $ep   = [];
    if ($ids !== []) {
        $st = $db->prepare('SELECT id, COALESCE(world_epoch, created_at) AS e FROM messages WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ep[(int)$r['id']] = (int)$r['e'];
        $st->closeCursor();
    }
    foreach ($msgs as $i => $m) $msgs[$i]['epoch'] = $ep[(int)$m['id']] ?? 0;
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
 * is, who is where right now (a schedule turning over moves people with no row
 * written), the newest message and event, the unread dots, the room renders'
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
        // SEAM(room): the room on the web is being built beside this
        // (engine/room.php). When it lands, a summary of the open room — its
        // id, its newest line id, whether it is closed — goes here, and into
        // the hash below by riding in $body. Null until then.
        'room'     => null,
    ];
    $cursor = substr(hash('sha256', json_encode([$body, $present], JSON_UNESCAPED_SLASHES)), 0, 32);
    // Not hashed: they change every second, or are only for a client's clock.
    return $body + ['cursor' => $cursor, 'epoch' => (int)$now['epoch'], 'server' => time()];
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
            // The ceremonies that hand out a token are the write half's.
            'link'  => false,
            'join'  => false,
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
            'write'  => false,
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
 * Answer one GET. The router (api.php) has set the base and identified the
 * caller; this picks the route and calls the existing function behind it.
 *
 * @return array{status:int,headers:array,body?:?array,bytes?:string,delegate?:string,query?:array}
 */
function xeric_api_route(string $method, array $seg, array $get, array $server, array $who): array
{
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return xeric_api_no(405, 'method', 'The read half of v1 answers GET only.', ['Allow' => 'GET, HEAD']);
    }
    if ($seg === []) {
        return xeric_api_yes(['versions' => [XERIC_API_MAJOR],
                              'base' => xeric_api_url([])]);
    }
    if ($seg[0] !== XERIC_API_MAJOR) {
        return xeric_api_no(404, 'version', 'This machine speaks ' . XERIC_API_MAJOR . '.');
    }
    $r   = array_slice($seg, 1);
    $n   = count($r);
    $sid = (string)$who['sid'];

    if ($n === 0) return xeric_api_yes(xeric_api_index($who));

    if ($r[0] === 'worlds' && $n === 1) {
        return xeric_api_yes(['worlds' => xeric_play_worlds($sid)]);
    }

    if ($r[0] === 'jobs' && $n === 2) {
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

    if ($r[0] !== 'worlds' || $n < 2) return xeric_api_no(404, 'no_route', 'There is no such route in v1.');

    $o = xeric_api_open((string)$r[1], (bool)$who['adult']);
    if (isset($o['no'])) return $o['no'];
    $w    = $o['w'];
    $slug = (string)$w['slug'];
    $t    = $w['template'];
    $db   = $w['db'];

    if ($n === 2) return xeric_api_yes(xeric_api_shape_state($w, $sid));

    $what = (string)$r[2];

    if ($what === 'pulse' && $n === 3) {
        $p    = xeric_api_pulse($w);
        $etag = '"' . $p['cursor'] . '"';
        $h    = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization, Cookie'];
        if (xeric_api_etag_matches((string)($server['HTTP_IF_NONE_MATCH'] ?? ''), $etag)) {
            return ['status' => 304, 'headers' => $h, 'body' => null];
        }
        return xeric_api_yes($p, $h);
    }

    if ($what === 'map' && $n === 3) {
        return xeric_api_yes(['world' => $slug] + xeric_travel_map($t, $db));
    }

    if ($what === 'look' && $n === 3) {
        return xeric_api_yes(xeric_api_shape_look(xeric_play_look_view($t, $db, $slug, null, false), $slug));
    }

    if ($what === 'look' && $n === 5) {
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

    if ($what === 'threads' && $n === 4) {
        $handle = (string)$r[3];
        $c = xeric_world_character($t, $handle);
        if ($c === null) return xeric_api_no(404, 'no_character', 'Nobody in this xeric answers to that name.');
        $since = max(0, (int)($get['since'] ?? 0));
        // Reading a thread puts its unread dot out, exactly as play.php's
        // a=thread does (xeric_play_thread). The learn signal — "this person,
        // not that one" — is NOT recorded here: that is a statement about a
        // screen somebody is looking at, and it is the write half's
        // `POST threads/{h}/read`.
        $th   = xeric_play_thread($t, $db, $handle, $since > 0 ? 500 : 60);
        $gone = xeric_deaths($db)[$handle] ?? null;
        return xeric_api_yes(['world' => $slug, 'handle' => $handle,
            'name' => (string)($c['display_name'] ?? $handle),
            'dead' => $gone !== null, 'how' => $gone !== null ? (string)$gone['how'] : '',
            'one_line' => (string)($c['one_line'] ?? '')] + xeric_api_shape_thread($db, $th, $since));
    }

    if ($what === 'messages' && $n === 5 && $r[4] === 'audio') {
        // A CACHED CLIP ONLY. Rendering a new one spends the owner's voice
        // machine, which is a verb, and verbs are the write half's. What has
        // already been said aloud is anybody's who can read the thread.
        $msg = xeric_play_spoken($db, (int)$r[3]);
        if ($msg === null) return xeric_api_no(404, 'no_message', 'That line has no voice in this world.');
        $row  = xeric_voice_of($db, (int)$msg['id']);
        $dir  = dirname((string)$w['db_path']) . '/voice';
        $clip = $row === null ? null
              : xeric_play_voice_bytes($dir . '/' . basename((string)$row['file']), (string)$row['mime'], true);
        if ($clip === null) {
            return xeric_api_no(404, 'not_rendered', 'That line has not been read aloud yet.');
        }
        return xeric_api_bytes($clip['bytes'], $clip['mime'],
            ['Content-Disposition' => 'inline', 'Cache-Control' => 'private, max-age=86400']);
    }

    return xeric_api_no(404, 'no_route', 'There is no such route in v1.');
}
