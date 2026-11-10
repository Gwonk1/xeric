<?php
/**
 * Xeric — the live view. A world model drawing the room you stand in as
 * video, for as long as you stand in it, conditioned on everything the
 * engine already knows and told only what the engine itself decides.
 *
 * FUTURE.md M5: "A `stream` kind in the envelope. A session URL the page
 * shows in a video element; the brief, the reference render, the layout and
 * the bearings are the conditioning. Promptable events are the engine's
 * weather and arrivals, not the player's wishes. Metered by the second and
 * defaulted off." This file is that sentence, and nothing it does teaches the
 * engine a new fact: a stream is one more client of the brief.
 *
 * OFF, TWICE OVER. `media.scene.stream.enabled` is false unless the template
 * says true, and a machine is believed to stream only once it has answered a
 * stream request with one (xeric_stream_machine_says — detection, never
 * configuration, the way sound is). A machine that bills refuses outright
 * unless the template also says `remote: true`: streaming costs about $72 an
 * hour against $0.12–$1.20 for a room drawn once (FUTURE.md Appendix D), and
 * a heartbeat is consent to nothing.
 *
 * NEVER PRERENDERED. Nothing here is called by the heart or by a reaper; the
 * one way in is xeric_stream_start, and the one caller of that is the owner
 * pressing "go live" (forge/web/stream.php). xeric_scene_answer refuses a
 * stream offered in answer to a look, so the queue cannot start one either.
 *
 * A SESSION, NOT BYTES — AND ITS OWN TABLE. A live view is a link with a
 * lifetime: it is started, beaten, told things and ended for a reason. A
 * scene_jobs row is a job with tries, keyed by what a room looks like, and
 * every reader of that table (the latest view, the day strip, the reference
 * walk, the reaper's queue) would have to learn to step round a row that is
 * not a picture. `scene_streams` keeps them apart by construction: nothing
 * that reads scene_jobs can mistake a session for a view, and nothing here
 * can be reaped. Created here, IF NOT EXISTS, beside the world's other tables.
 *
 * WHAT IT IS TOLD WHILE LIVE: PROMPTABLE EVENTS, THE ENGINE'S OWN. The
 * weather line changing, somebody coming in or going (the doorway's own
 * sentence, xeric_travel_comings_line), the light moving on one bucket —
 * each composed in code from observables, rating clause last, and POSTed to
 * {base}/v1/scenes/{session}/events as {type, text, at}. NEVER THE PLAYER'S
 * TEXT, and there is no parameter that could carry it: the world model draws
 * the world, it does not decide it. If a player could type "a dragon comes
 * in" at the stream, the picture would hold a dragon the engine never had —
 * nobody else would see it, the cast would not know it, and the next still
 * of the room would not have it. What happens in the world happens in the
 * engine first, and the picture follows.
 *
 * WHEN IT ENDS: the player leaves the place; the room changes past what one
 * conditioning can carry (the light two buckets on from where it began, or
 * the room shut or gone dark); max_seconds passes (the template's, the
 * machine's, and its expires_at, whichever is first); the page is hidden for
 * more than thirty seconds or stops beating; or the world's stream seconds
 * for today are spent. Every end has a reason, and the page says it.
 *
 * PHP 8.1+.
 */

declare(strict_types=1);

require_once __DIR__ . '/scene.php';
require_once __DIR__ . '/budget.php';

/** The page beats this often, seconds. */
const XERIC_STREAM_BEAT     = 10;
/** The most one beat may count, seconds: a late beat is not a free hour. */
const XERIC_STREAM_BEAT_CAP = 15;
/** Hidden, or silent, for longer than this and the live view ends. */
const XERIC_STREAM_AWAY     = 30;
/** One session's ceiling when the template does not say, seconds. */
const XERIC_STREAM_MAX      = 300;
/** How long a machine's "I do not stream" is believed before it is asked again, seconds. */
const XERIC_STREAM_NO_FOR   = 86400;
/** The protocols a page here can show, in no order. */
const XERIC_STREAM_PROTOCOLS = ['hls', 'webrtc-whep', 'mjpeg'];

// ---------------------------------------------------------------------------
// The template's knobs, and whether this world may go live at all
// ---------------------------------------------------------------------------

/**
 * `media.scene.stream`, with its defaults: off, not on a billing machine,
 * five minutes a session. Off with the rooms: a world that turned scenes off
 * has no live view either.
 *
 * @return array{enabled:bool,remote:bool,max_seconds:int}
 */
function xeric_stream_config(array $t): array
{
    $c = $t['media']['scene']['stream'] ?? [];
    if (!is_array($c)) $c = [];
    $max = is_numeric($c['max_seconds'] ?? null) ? (int)$c['max_seconds'] : XERIC_STREAM_MAX;
    return [
        'enabled'     => xeric_scene_config($t)['enabled'] && ($c['enabled'] ?? false) === true,
        'remote'      => ($c['remote'] ?? false) === true,
        'max_seconds' => max(30, min(3600, $max)),
    ];
}

/**
 * A url's origin, as a CSP source: scheme://host[:port], lowercased, and ''
 * for anything that is not plainly one. Strict on purpose — this string is
 * printed into a Content-Security-Policy header, and a host with a space or a
 * semicolon in it would be a second directive.
 */
function xeric_stream_origin(string $url): string
{
    $p = @parse_url(trim($url));
    if (!is_array($p)) return '';
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host   = strtolower((string)($p['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) return '';
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $host)
        && !preg_match('/^\[[0-9a-f:.]+\]$/', $host)) return '';
    $port = isset($p['port']) ? (int)$p['port'] : 0;
    $dflt = $scheme === 'https' ? 443 : 80;
    return $scheme . '://' . $host . ($port > 0 && $port !== $dflt ? ':' . $port : '');
}

/** The scene machine's own origin — the only remote origin a page may ever name. */
function xeric_stream_machine_origin(?array $endpoint): string
{
    if ($endpoint === null) return '';
    return xeric_stream_origin((string)($endpoint['base'] ?? ''));
}

/**
 * Has this machine said whether it streams? true, false, or null for not yet
 * asked. Cached per machine (its address), in the world's own state: the
 * first "go live" asks, and the answer — a session, or a plain cannot
 * (xeric_scene_answer) — is written down against that address with the time
 * it was said. A refusal that is only "not now" is never written down.
 *
 * A NO DOES NOT KEEP. It is believed for a day (XERIC_STREAM_NO_FOR) and then
 * asked again — machines get upgraded — and a no for any OTHER address is
 * wiped as it is read, so choosing another machine is clearing it (the sound
 * mark's rule). A no from before the time was kept is not believed at all.
 */
function xeric_stream_machine_says(PDO $db, array $endpoint, ?int $wall = null): ?bool
{
    $wall ??= time();
    $map = json_decode((string)(xeric_world_state_get($db, 'scene.stream.machines') ?? ''), true);
    if (!is_array($map)) return null;
    $id = xeric_scene_machine_id($endpoint);
    $kept = $map;
    foreach ($map as $k => $v) {
        $says = is_array($v) ? (bool)($v['streams'] ?? false) : (bool)$v;
        $at   = is_array($v) ? (int)($v['at'] ?? 0) : 0;
        if ($says) continue;
        if ((string)$k !== $id || $at <= 0 || $wall - $at > XERIC_STREAM_NO_FOR) unset($kept[$k]);
    }
    if ($kept !== $map) {
        xeric_world_state_set($db, 'scene.stream.machines', json_encode((object)$kept, JSON_UNESCAPED_SLASHES));
    }
    if (!array_key_exists($id, $kept)) return null;
    $v = $kept[$id];
    return is_array($v) ? (bool)($v['streams'] ?? false) : (bool)$v;
}

function xeric_stream_machine_note(PDO $db, array $endpoint, bool $streams, ?int $wall = null): void
{
    $map = json_decode((string)(xeric_world_state_get($db, 'scene.stream.machines') ?? ''), true);
    if (!is_array($map)) $map = [];
    $map[xeric_scene_machine_id($endpoint)] = ['streams' => $streams, 'at' => $wall ?? time()];
    xeric_world_state_set($db, 'scene.stream.machines', json_encode($map, JSON_UNESCAPED_SLASHES));
}

/**
 * May this world offer "go live", with this machine, now? Read without a
 * network call (the sidebar asks on every repaint): the template said so,
 * there is a machine and it is not an images call (which has nowhere to put
 * a session), it has not said it does not stream, and — on a machine that
 * bills — the template said `remote: true` too.
 *
 * @return array{ok:bool,why:string,origin:string,costly:bool}
 */
function xeric_stream_offer(array $t, PDO $db, ?array $endpoint): array
{
    $no  = fn(string $why): array => ['ok' => false, 'why' => $why, 'origin' => '', 'costly' => false];
    $cfg = xeric_stream_config($t);
    if (!$cfg['enabled']) return $no('this world does not go live');
    if ($endpoint === null) return $no('no scene machine');
    if (strtolower((string)($endpoint['kind'] ?? '')) === 'image') return $no('an image machine cannot stream');
    $origin = xeric_stream_machine_origin($endpoint);
    if ($origin === '') return $no('the scene machine has no address a page could play from');
    if (xeric_stream_machine_says($db, $endpoint) === false) return $no('this scene machine does not stream');
    $costly = xeric_scene_costly($endpoint);
    if ($costly && !$cfg['remote']) {
        return $no('a live view on a machine that bills costs about $72 an hour; this world\'s template '
                 . 'must say media.scene.stream.remote: true before it will start one');
    }
    return ['ok' => true, 'why' => '', 'origin' => $origin, 'costly' => $costly];
}

// ---------------------------------------------------------------------------
// The table
// ---------------------------------------------------------------------------

function xeric_stream_schema(PDO $db): void
{
    static $done = [];
    $k = spl_object_id($db);
    if (isset($done[$k])) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS scene_streams (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            key         TEXT NOT NULL,                  -- the place
            machine     TEXT NOT NULL,                  -- xeric_scene_machine_id
            session     TEXT NOT NULL,                  -- the machine's own id for it
            url         TEXT NOT NULL,
            protocol    TEXT NOT NULL,                  -- hls | webrtc-whep | mjpeg
            fallback    TEXT NOT NULL DEFAULT '',       -- an mjpeg url, when offered
            status      TEXT NOT NULL DEFAULT 'live',   -- live | ended
            reason      TEXT NOT NULL DEFAULT '',
            started_at  INTEGER NOT NULL,               -- real time: the machine bills real seconds
            last_beat   INTEGER NOT NULL,
            ends_at     INTEGER NOT NULL,
            ended_at    INTEGER,
            seconds     INTEGER NOT NULL DEFAULT 0,     -- metered so far
            costly      INTEGER NOT NULL DEFAULT 0,
            cond        TEXT NOT NULL DEFAULT '{}',     -- what it was conditioned on, and told since
            events      INTEGER NOT NULL DEFAULT 0,
            rating      TEXT NOT NULL DEFAULT '',
            meta        TEXT NOT NULL DEFAULT '{}'
        )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scene_streams_status ON scene_streams(status, key)');
    $done[$k] = true;
}

function xeric_stream_get(PDO $db, int $id): ?array
{
    xeric_stream_schema($db);
    $q = $db->prepare('SELECT * FROM scene_streams WHERE id = ?');
    $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    $q->closeCursor();
    return $r ?: null;
}

/** The live view, if one is live. One per world: a second "go live" ends the first. */
function xeric_stream_live(PDO $db, ?string $key = null): ?array
{
    xeric_stream_schema($db);
    $q = $db->prepare("SELECT * FROM scene_streams WHERE status = 'live'" . ($key !== null ? ' AND key = ?' : '')
                    . ' ORDER BY id DESC LIMIT 1');
    $q->execute($key !== null ? [$key] : []);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    $q->closeCursor();
    return $r ?: null;
}

/** The last live view of this place that ended in the last $within seconds — for "the live view ended: …". */
function xeric_stream_ended(PDO $db, string $key, int $within = 600, ?int $wall = null): ?array
{
    xeric_stream_schema($db);
    $q = $db->prepare("SELECT * FROM scene_streams WHERE key = ? AND status = 'ended' AND ended_at >= ?
                       ORDER BY ended_at DESC, id DESC LIMIT 1");
    $q->execute([$key, ($wall ?? time()) - $within]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    $q->closeCursor();
    return $r ?: null;
}

// ---------------------------------------------------------------------------
// Going live
// ---------------------------------------------------------------------------

/** The bearings, flat: what a machine that lays a room out needs, without the rest of the brief. */
function xeric_stream_bearings(array $brief): array
{
    $out = ['items' => [], 'who' => []];
    foreach ((array)($brief['interior'] ?? []) as $i) {
        if (isset($i['text'], $i['bearing'])) $out['items'][(string)$i['text']] = (int)$i['bearing'];
    }
    foreach ((array)($brief['who'] ?? []) as $w) {
        if (isset($w['handle'], $w['bearing'])) $out['who'][(string)$w['handle']] = (int)$w['bearing'];
    }
    return $out;
}

/**
 * What a session was conditioned on, kept on its row so a beat can tell what
 * has changed since: the light (and the light it began in), the weather
 * line, open and dark, and who is in the room — handle => name, a bystander's
 * knowledge and nothing more.
 */
function xeric_stream_conditions(array $brief, ?array $was = null): array
{
    $who = [];
    foreach ((array)($brief['who'] ?? []) as $w) $who[(string)$w['handle']] = (string)$w['name'];
    ksort($who, SORT_STRING);
    return [
        'light'   => (string)($brief['light'] ?? ''),
        'light0'  => (string)($was['light0'] ?? $brief['light'] ?? ''),
        'weather' => trim((string)($brief['weather'] ?? '')),
        'open'    => !empty($brief['open']),
        'dark'    => !empty($brief['dark']),
        'who'     => $who,
        'fingerprint' => (string)($was['fingerprint'] ?? $brief['fingerprint'] ?? ''),
    ];
}

/**
 * The generic request a stream is started with: the look's own body
 * (xeric_scene_body — the prompt, the brief whole, the seed, the reference
 * render) with the channel and the kind said as a stream's, the bearings
 * flat beside it, the layout when the brief has one, and what a session here
 * may be: these protocols, this long.
 */
function xeric_stream_body(array $brief, array $cfg, ?array $ref, bool $layoutImages = false): array
{
    $body = xeric_scene_body($brief, ['channel' => 'look', 'kinds' => ['stream'], 'quality' => 'full',
                                      'timeout' => 30, 'layout_images' => $layoutImages]
                                     + ($ref !== null ? ['reference' => $ref] : []));
    unset($body['size']);
    $body['channel']     = 'stream';
    $body['kind_wanted'] = ['stream'];
    $body['bearings']    = xeric_stream_bearings($brief);
    $layout = $brief['layout'] ?? null;
    if ($layout !== null && !isset($body['layout'])) $body['layout'] = $layout;
    $body['protocols']   = XERIC_STREAM_PROTOCOLS;
    $body['max_seconds'] = $cfg['max_seconds'];
    $body['events']      = ['weather', 'arrival', 'departure', 'light'];
    return $body;
}

/**
 * The machine's session, checked. Its url — and its fallback, if it offers
 * one — must be on THE MACHINE'S OWN ORIGIN, because that origin is the one
 * remote source the page's CSP is widened to (forge/web/ui.php); a session
 * that pointed somewhere else would be a session the page cannot play, and a
 * machine that tried it is told no here rather than silently there.
 *
 * @return array{id:string,url:string,protocol:string,fallback:string,expires_at:int,max_seconds:int}
 */
function xeric_stream_session(array $s, array $endpoint): array
{
    $origin = xeric_stream_machine_origin($endpoint);
    $id  = trim((string)($s['id'] ?? ''));
    $url = trim((string)($s['url'] ?? ''));
    $pr  = strtolower(trim((string)($s['protocol'] ?? '')));
    if (!preg_match('/^[A-Za-z0-9._~-]{1,128}$/', $id)) throw new RuntimeException('stream: the session has no id this engine can name');
    if (!in_array($pr, XERIC_STREAM_PROTOCOLS, true)) throw new RuntimeException('stream: the session is in a protocol no page here plays');
    if ($origin === '' || xeric_stream_origin($url) !== $origin) {
        throw new RuntimeException('stream: the session is not on the machine\'s own address, so no page here may play it');
    }
    $fb = trim((string)($s['fallback']['url'] ?? ''));
    $fbp = strtolower(trim((string)($s['fallback']['protocol'] ?? 'mjpeg')));
    if ($fb !== '' && ($fbp !== 'mjpeg' || xeric_stream_origin($fb) !== $origin)) $fb = '';
    return ['id' => $id, 'url' => $url, 'protocol' => $pr, 'fallback' => $fb,
            'expires_at' => is_numeric($s['expires_at'] ?? null) ? (int)$s['expires_at'] : 0,
            'max_seconds' => is_numeric($s['max_seconds'] ?? null) ? (int)$s['max_seconds'] : 0];
}

/**
 * GO LIVE. The owner pressed the button; this asks the machine for a session
 * conditioned on the room as it is — the brief, the newest picture of the
 * room (xeric_scene_reference: the same "show it what it drew" that holds a
 * still room still), the layout when the brief carries one, the bearings —
 * and keeps the session it answers with.
 *
 * Refuses, with the sentence, rather than throwing: the world does not go
 * live; no machine; a machine that bills without `remote: true`; the world's
 * stream seconds spent; nowhere to stand; the machine does not stream (and
 * that is written down against it); a session this engine cannot play.
 *
 * $opts: scenes_dir (for the reference), wall (real time, tests), now,
 * meter (for the tail of a view this one replaces; the ledger by default).
 *
 * @return array{ok:bool,why:string,row:?array,status:int}
 */
function xeric_stream_start(array $t, PDO $db, ?array $endpoint, array $opts = []): array
{
    $no = fn(string $why, int $status = 409): array => ['ok' => false, 'why' => $why, 'row' => null, 'status' => $status];
    xeric_stream_schema($db);
    $offer = xeric_stream_offer($t, $db, $endpoint);
    if (!$offer['ok']) return $no($offer['why']);
    $cfg  = xeric_stream_config($t);
    $now  = $opts['now'] ?? xeric_clock_now($db, $t);
    $wall = (int)($opts['wall'] ?? time());

    $verdict = xeric_budget_allows($t, $db, $now, 'stream', 1, $offer['costly']);
    if (!$verdict['ok']) return $no($verdict['reason'], 429);

    $brief = xeric_scene_brief($t, $db, null, $now);
    if ($brief === null) return $no('you are nowhere in particular, so there is no room to go live in');

    // ONE LIVE VIEW A WORLD. The same room already live is that session; a
    // live view of somewhere else is over.
    $live = xeric_stream_live($db);
    if ($live !== null) {
        if ((string)$live['key'] === (string)$brief['key']) return ['ok' => true, 'why' => '', 'row' => $live, 'status' => 200];
        xeric_stream_end($db, $endpoint, (int)$live['id'], 'a live view of somewhere else began', $wall,
                         ['meter' => $opts['meter'] ?? xeric_stream_ledger($db, $now)]);
    }

    $ref = null;
    if (($opts['scenes_dir'] ?? '') !== '') {
        $ref = xeric_scene_reference($db, (string)$brief['key'], (string)$opts['scenes_dir'],
                                     XERIC_SCENE_REFERENCE_CAP, (string)($brief['rating'] ?? ''));
    }
    $body = xeric_stream_body($brief, $cfg, $ref, xeric_layout_images($t));

    try {
        if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
            $j = ($endpoint['stub'])('stream', $brief, ['body' => $body]);
            if (!is_array($j)) throw new RuntimeException('stream: the stub returned nothing');
            $a = xeric_scene_answer($j, [], 0, true, 'stream', $endpoint);
        } else {
            $auth = xeric_scene_auth_headers($endpoint);
            $j = xeric_scene_http('POST', rtrim((string)$endpoint['base'], '/') . '/v1/scenes', $auth, $body, 30);
            $a = xeric_scene_answer($j, $auth, 30, false, 'stream', $endpoint);
        }
    } catch (Throwable $e) {
        return $no(preg_replace('/^(scene|stream): /', '', $e->getMessage()), 502);
    }
    if ($a['cannot']) {
        xeric_stream_machine_note($db, $endpoint, false, $wall);
        return $no('this scene machine does not stream; it has been written down, and "go live" is gone until another machine is chosen');
    }
    if ($a['pending'] !== null) return $no('the machine said later, and a live view cannot wait', 502);
    try {
        $s = xeric_stream_session((array)$a['session'], $endpoint);
    } catch (Throwable $e) {
        return $no(preg_replace('/^stream: /', '', $e->getMessage()), 502);
    }
    xeric_stream_machine_note($db, $endpoint, true, $wall);

    // The first of three ceilings: this world's, the machine's, its expiry.
    $ends = $wall + $cfg['max_seconds'];
    if ($s['max_seconds'] > 0) $ends = min($ends, $wall + $s['max_seconds']);
    if ($s['expires_at'] > $wall) $ends = min($ends, $s['expires_at']);
    $cond = xeric_stream_conditions($brief);
    // What the MACHINE said it would run to, kept beside ours: if it is never
    // told to stop, that is how long it may go on billing (xeric_stream_end).
    $meta = $a['meta'] + ($ref !== null ? ['reference_fingerprint' => $ref['fingerprint']] : [])
          + (($brief['layout'] ?? null) !== null ? ['layout' => true] : [])
          + ($s['max_seconds'] > 0 ? ['machine_max_seconds' => $s['max_seconds']] : [])
          + ($s['expires_at'] > $wall ? ['machine_expires_at' => $s['expires_at']] : []);
    $db->prepare("INSERT INTO scene_streams (key, machine, session, url, protocol, fallback, status, started_at,
                  last_beat, ends_at, costly, cond, rating, meta) VALUES (?, ?, ?, ?, ?, ?, 'live', ?, ?, ?, ?, ?, ?, ?)")
       ->execute([(string)$brief['key'], xeric_scene_machine_id($endpoint), $s['id'], $s['url'], $s['protocol'],
                  $s['fallback'], $wall, $wall, $ends, $offer['costly'] ? 1 : 0,
                  json_encode($cond, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)($brief['rating'] ?? ''),
                  json_encode((object)$meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return ['ok' => true, 'why' => '', 'row' => xeric_stream_get($db, (int)$db->lastInsertId()), 'status' => 200];
}

// ---------------------------------------------------------------------------
// While live: the engine's own events
// ---------------------------------------------------------------------------

/**
 * The rating clause, last on every event — the scene prompt's own words
 * (xeric_scene_prompt), so a live view is held to exactly what a still is.
 * engine/tests/scene-test.php holds the two together.
 */
function xeric_stream_rating_clause(array $brief): string
{
    if (!empty($brief['minor'])) return XERIC_PHOTO_FLOOR_FRAMING;
    return match ((string)($brief['rating'] ?? '')) {
        'sfw', 'pg' => 'a wholesome everyday scene, nothing suggestive',
        'teen'      => 'an everyday scene, nothing explicit',
        default     => 'a scene in keeping with the world\'s adult rating',
    };
}

/**
 * What has happened in the room since the session was last told, as events,
 * and whether the room has moved past what one conditioning carries.
 *
 * PURE but for reading canon: the brief now against the conditions on the
 * row. The texts are composed here and nowhere else, from the brief's own
 * fields and the doorway's sentence — THIS FUNCTION TAKES NO TEXT. There is
 * nothing a player typed anywhere in its arguments, and so nothing a player
 * typed can reach a world model through it.
 *
 *   weather    the weather line is not the one the session last heard
 *   arrival    somebody is in the room who was not  (xeric_travel_comings_line)
 *   departure  somebody who was is not               (the same sentence)
 *   light      the light moved on one bucket from the one it was last told
 *
 * END, instead of an event: the light is two or more buckets from the one
 * the session began in (the reference render was another hour's), or the
 * room shut or went dark (every surface changes).
 *
 * @return array{events:array<int,array{type:string,text:string,at:int}>,cond:array,end:string}
 */
function xeric_stream_compose(array $t, PDO $db, array $cond, array $brief, array $now): array
{
    $out = ['events' => [], 'cond' => xeric_stream_conditions($brief, $cond), 'end' => ''];
    $at    = (int)($now['epoch'] ?? 0);
    $last  = fn(string $s): string => rtrim(trim($s), '. ') . '. ' . xeric_stream_rating_clause($brief) . '.';
    $lights = xeric_scene_lights();

    if (!empty($brief['dark']) !== !empty($cond['dark']) || !empty($brief['open']) !== !empty($cond['open'])) {
        $out['end'] = !empty($brief['dark']) ? 'the room has gone dark' : (!empty($brief['open']) ? 'the room has opened'
                    : 'the room has shut for the hour');
        return $out;
    }
    $i0 = array_search((string)($cond['light0'] ?? ''), $lights, true);
    $i  = array_search((string)$brief['light'], $lights, true);
    if ($i0 !== false && $i !== false) {
        $d = abs($i - $i0);
        if (min($d, count($lights) - $d) >= 2) {
            $out['end'] = 'the light has moved on too far from where the live view began';
            return $out;
        }
    }
    if ((string)$brief['light'] !== (string)($cond['light'] ?? '')) {
        $out['events'][] = ['type' => 'light', 'text' => $last('The light turns: ' . (string)$brief['light']), 'at' => $at];
    }
    $wx = trim((string)($brief['weather'] ?? ''));
    if ($wx !== '' && $wx !== trim((string)($cond['weather'] ?? ''))) {
        $out['events'][] = ['type' => 'weather', 'text' => $last('Outside, the weather turns: ' . $wx), 'at' => $at];
    }
    $was = (array)($cond['who'] ?? []);
    $is  = $out['cond']['who'];
    $came = array_values(array_diff(array_keys($is), array_keys($was)));
    $went = array_values(array_diff(array_keys($was), array_keys($is)));
    $names = $is + $was;
    $key = (string)$brief['key'];
    if ($came !== []) {
        $line = xeric_travel_comings_line($t, $now, $key, $came, [], $db, $names);
        if ($line !== '') $out['events'][] = ['type' => 'arrival', 'text' => $last($line), 'at' => $at];
    }
    if ($went !== []) {
        $line = xeric_travel_comings_line($t, $now, $key, [], $went, $db, $names);
        if ($line !== '') $out['events'][] = ['type' => 'departure', 'text' => $last($line), 'at' => $at];
    }
    return $out;
}

/**
 * One event, to the machine: POST {base}/v1/scenes/{session}/events
 * {type, text, at}. Best effort and short: a missed event is a picture a
 * moment behind the engine, never a reason to stop the view. A stub hears
 * it as stub('event', $event, ['session' => id]).
 */
function xeric_stream_send(?array $endpoint, array $row, array $event): bool
{
    if ($endpoint === null) return false;
    $ev = ['type' => (string)$event['type'], 'text' => (string)$event['text'], 'at' => (int)$event['at']];
    try {
        if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
            ($endpoint['stub'])('event', $ev, ['session' => (string)$row['session']]);
            return true;
        }
        $j = xeric_scene_http('POST', rtrim((string)($endpoint['base'] ?? ''), '/') . '/v1/scenes/'
                            . rawurlencode((string)$row['session']) . '/events',
                              xeric_scene_auth_headers($endpoint), $ev, 5);
        return (int)($j['_http'] ?? 0) < 400;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * THE HEARTBEAT. The page says it is still showing the live view; this
 * counts the seconds since the last beat (capped: a beat that arrives late
 * is not a free stretch), hands them to $opts['meter'] — the web layer's
 * ledger write — and then decides whether the view goes on: the player still
 * here, under max_seconds, the page not hidden too long, the seconds not
 * spent; and if it goes on, tells the machine what has changed.
 *
 * $opts: hidden_for (seconds the page says it has been hidden), wall (real
 * time), now (world clock), meter (fn(int $seconds, bool $costly)).
 *
 * @return array{live:bool,reason:string,counted:int,seconds:int,events:array,ends_at:int}
 */
function xeric_stream_beat(array $t, PDO $db, ?array $endpoint, int $id, array $opts = []): array
{
    $row = xeric_stream_get($db, $id);
    if ($row === null) return ['live' => false, 'reason' => 'there is no such live view', 'counted' => 0,
                               'seconds' => 0, 'events' => [], 'ends_at' => 0];
    $done = fn(array $r, string $why, int $counted = 0, array $ev = []): array => ['live' => false, 'reason' => $why,
        'counted' => $counted, 'seconds' => (int)$r['seconds'], 'events' => $ev, 'ends_at' => (int)$r['ends_at']];
    if ((string)$row['status'] !== 'live') return $done($row, (string)$row['reason']);

    $wall    = (int)($opts['wall'] ?? time());
    $elapsed = max(0, $wall - (int)$row['last_beat']);
    // Never past the session's own end: what the machine could not have sent
    // is not billed.
    $counted = min($elapsed, XERIC_STREAM_BEAT_CAP, max(0, (int)$row['ends_at'] - (int)$row['last_beat']));
    $q = $db->prepare("UPDATE scene_streams SET seconds = seconds + ?, last_beat = ? WHERE id = ? AND status = 'live'");
    $q->execute([$counted, $wall, $id]);
    if ($q->rowCount() !== 1) { $row = xeric_stream_get($db, $id) ?? $row; return $done($row, (string)$row['reason']); }
    $costly = (int)$row['costly'] === 1;
    if ($counted > 0 && isset($opts['meter']) && is_callable($opts['meter'])) {
        try { ($opts['meter'])($counted, $costly); } catch (Throwable $e) { /* the seconds happened */ }
    }
    $row = xeric_stream_get($db, $id) ?? $row;
    $end = function (string $why) use ($db, $endpoint, $id, $wall, $counted, $done): array {
        xeric_stream_end($db, $endpoint, $id, $why, $wall);
        return $done(xeric_stream_get($db, $id) ?? [], $why, $counted);
    };

    $now = $opts['now'] ?? xeric_clock_now($db, $t);
    if ($elapsed > XERIC_STREAM_AWAY) return $end('the page was away for more than ' . XERIC_STREAM_AWAY . ' seconds');
    if ((int)($opts['hidden_for'] ?? 0) > XERIC_STREAM_AWAY) {
        return $end('the tab was hidden for more than ' . XERIC_STREAM_AWAY . ' seconds');
    }
    $here = xeric_player_where($t, $db);
    if ($here !== (string)$row['key']) return $end('you left ' . (xeric_world_place_name($t, (string)$row['key']) ?: 'the room'));
    if ($wall >= (int)$row['ends_at']) {
        return $end('it reached its limit of ' . xeric_stream_minutes((int)$row['ends_at'] - (int)$row['started_at']));
    }
    if (!xeric_stream_config($t)['enabled']) return $end('this world no longer goes live');
    $verdict = xeric_budget_allows($t, $db, $now, 'stream', 1, $costly);
    if (!$verdict['ok']) return $end($verdict['reason']);

    $brief = xeric_scene_brief($t, $db, (string)$row['key'], $now);
    if ($brief === null) return $end('the room is no longer in this world');
    $cond = json_decode((string)$row['cond'], true);
    $c = xeric_stream_compose($t, $db, is_array($cond) ? $cond : [], $brief, $now);
    if ($c['end'] !== '') return $end($c['end'] . '; go live again for a fresh start');
    foreach ($c['events'] as $ev) xeric_stream_send($endpoint, $row, $ev);
    $db->prepare("UPDATE scene_streams SET cond = ?, events = events + ? WHERE id = ? AND status = 'live'")
       ->execute([json_encode($c['cond'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), count($c['events']), $id]);
    return ['live' => true, 'reason' => '', 'counted' => $counted, 'seconds' => (int)$row['seconds'],
            'events' => $c['events'], 'ends_at' => (int)$row['ends_at']];
}

/** "five minutes", "90 seconds" */
function xeric_stream_minutes(int $s): string
{
    if ($s % 60 === 0 && $s >= 60) return ($s / 60) . ($s === 60 ? ' minute' : ' minutes');
    return $s . ' seconds';
}

/**
 * The world's ledger as a meter: fn(int $seconds, bool $costly) that writes
 * the seconds as kind `stream` on the world's date (engine/budget.php). The
 * one shape every caller that ends or beats a live view hands in.
 */
function xeric_stream_ledger(PDO $db, array $now): Closure
{
    return function (int $s, bool $costly) use ($db, $now): void {
        xeric_budget_note($db, $now, 'stream', $s, $costly);
    };
}

/**
 * What the page's own words for an end mean (forge/web/stream.php's `why`):
 * a short code, never a sentence, so a page cannot write into the row
 * another page reads out. Anything else is the page having stopped it.
 */
function xeric_stream_why(string $code): string
{
    return match ($code) {
        'hidden'         => 'the tab was hidden for more than ' . XERIC_STREAM_AWAY . ' seconds',
        'closed'         => 'you ended it',
        'unplayable'     => 'this browser cannot play this machine\'s live view, so it was stopped before it billed for a picture nobody could see',
        'connect_failed' => 'the live view could not be connected, so it was stopped',
        default          => 'the page stopped it',
    };
}

/**
 * Where a session is told to stop: THE ADDRESS ON ITS ROW, never whatever
 * machine happens to be chosen now. The owner may have picked another since;
 * the session is still running on the one it was started on. The key is
 * borrowed from $current only when $current IS that machine — a key is never
 * sent to an address it was not configured for. null when the row names no
 * address this engine can reach (a stub's session, with no stub to hand).
 */
function xeric_stream_row_endpoint(array $row, ?array $current): ?array
{
    $machine = (string)($row['machine'] ?? '');
    if ($current !== null && xeric_scene_machine_id($current) === $machine) return $current;
    if ($machine === '' || $machine === 'stub' || xeric_stream_origin($machine) === '') return null;
    return ['base' => $machine, 'kind' => 'generic'];
}

/**
 * DELETE {base}/v1/scenes/{session}, short. true when the machine said it is
 * stopped (2xx, or a 404: there is no such session to stop), false when it
 * could not be reached or refused. A stub hears it as stub('end', {reason},
 * {session}); a stub that throws, or answers {_http: >= 400}, did not confirm.
 */
function xeric_stream_tell_end(?array $endpoint, array $row, string $reason, int $timeout = 5): bool
{
    if ($endpoint === null) return false;
    try {
        if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
            $r = ($endpoint['stub'])('end', ['reason' => $reason], ['session' => (string)$row['session']]);
            $st = is_array($r) ? (int)($r['_http'] ?? 200) : 200;
            return $st < 400 || $st === 404;
        }
        $base = rtrim(trim((string)($endpoint['base'] ?? '')), '/');
        if ($base === '') return false;
        $headers = array_merge(['Accept: application/json'], xeric_scene_auth_headers($endpoint));
        [, $st] = xeric_scene_get($base . '/v1/scenes/' . rawurlencode((string)$row['session']),
            ['method' => 'DELETE', 'header' => implode("\r\n", $headers), 'timeout' => max(1, $timeout),
             'ignore_errors' => true, 'follow_location' => 0]);
        return ($st >= 200 && $st < 300) || $st === 404;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * End a live view, for a reason. Idempotent: only a live row is ended, and
 * true says this call ended it. EVERY END DOES THREE THINGS, whichever path
 * it came by — the page's button, the tab hidden, the budget, the beat that
 * found the player gone, the sweep that found nobody beating:
 *
 *   THE TAIL IS METERED. The seconds since the last beat happened on the
 *   machine whether or not anybody beat for them: up to the beat's own cap
 *   (XERIC_STREAM_BEAT_CAP), never past the session's end, onto the row and
 *   into $opts['meter'] (the ledger, xeric_stream_ledger).
 *
 *   THE MACHINE IS TOLD. DELETE {base}/v1/scenes/{session}, best effort and
 *   short, to the address ON THE ROW (xeric_stream_row_endpoint) — $endpoint
 *   is only the machine chosen now, whose key is lent when it is the same one.
 *
 *   ...UNLESS THIS IS A READ PATH. $opts['tell'] false (the sweep) ends the
 *   row and meters it with no network at all, and writes meta.tell_owed: the
 *   DELETE is sent later by xeric_stream_tell_owed — from the heart's pass,
 *   or the next beat or end the owner's own page sends.
 *
 *   AN UNCONFIRMED STOP IS WRITTEN DOWN. If the machine could not be told,
 *   and it said when it would stop by itself (max_seconds, expires_at), the
 *   seconds it may still bill are kept as meta.unconfirmed_end — never
 *   counted as spent (nobody knows they were), always shown
 *   (xeric_stream_unconfirmed_line), because a closed tab must not leave a
 *   costly session running where nobody can see it.
 *
 * $opts: meter (fn(int, bool)), tell (default true), timeout (the DELETE's, 5).
 */
function xeric_stream_end(PDO $db, ?array $endpoint, int $id, string $reason, ?int $wall = null, array $opts = []): bool
{
    xeric_stream_schema($db);
    $wall ??= time();
    $before = xeric_stream_get($db, $id);
    if ($before === null || (string)$before['status'] !== 'live') return false;
    $tail = min(max(0, $wall - (int)$before['last_beat']), XERIC_STREAM_BEAT_CAP,
                max(0, (int)$before['ends_at'] - (int)$before['last_beat']));
    $q = $db->prepare("UPDATE scene_streams SET status = 'ended', reason = ?, ended_at = ?, seconds = seconds + ?,
                       last_beat = MAX(last_beat, ?) WHERE id = ? AND status = 'live'");
    $q->execute([$reason, $wall, $tail, $wall, $id]);
    if ($q->rowCount() !== 1) return false;
    $costly = (int)$before['costly'] === 1;
    if ($tail > 0 && isset($opts['meter']) && is_callable($opts['meter'])) {
        try { ($opts['meter'])($tail, $costly); } catch (Throwable $e) { /* the seconds happened */ }
    }
    if (array_key_exists('tell', $opts) && !$opts['tell']) {
        $meta = json_decode((string)$before['meta'], true);
        if (!is_array($meta)) $meta = [];
        $meta['tell_owed'] = true;
        $db->prepare('UPDATE scene_streams SET meta = ? WHERE id = ?')
           ->execute([json_encode((object)$meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        return true;
    }
    xeric_stream_told($db, $before, xeric_stream_tell_end(xeric_stream_row_endpoint($before, $endpoint), $before, $reason,
                                                         (int)($opts['timeout'] ?? 5)), $wall);
    return true;
}

/**
 * What a DELETE's answer leaves on an ended row: whether the machine
 * confirmed, and if not, the seconds it said it might still run on.
 */
function xeric_stream_told(PDO $db, array $before, bool $told, int $wall): void
{
    $id   = (int)$before['id'];
    $meta = json_decode((string)$before['meta'], true);
    if (!is_array($meta)) $meta = [];
    unset($meta['tell_owed']);
    $meta['end_confirmed'] = $told;
    if (!$told) {
        $until = 0;
        $mx = (int)($meta['machine_max_seconds'] ?? 0);
        $ex = (int)($meta['machine_expires_at'] ?? 0);
        if ($mx > 0) $until = (int)$before['started_at'] + $mx;
        if ($ex > 0) $until = $until > 0 ? min($until, $ex) : $ex;
        if ($until > $wall) $meta['unconfirmed_end'] = $until - $wall;
    }
    $db->prepare('UPDATE scene_streams SET meta = ? WHERE id = ?')
       ->execute([json_encode((object)$meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
}

/** How many owed DELETEs one heart pass sends, across every world, and how long each may take. */
const XERIC_STREAM_TELL_PER_TICK = 4;
const XERIC_STREAM_TELL_TIMEOUT  = 2;

/**
 * Send the DELETEs the sweep owed (meta.tell_owed), oldest end first, at most
 * $limit, each with a short $timeout. Run where a stall is affordable — the
 * heart's pass, and the beat and end requests the owner's own page makes —
 * never on a read path. Each row is claimed before it is told (the flag is
 * cleared under `meta LIKE`), so two runners never DELETE the same session
 * twice. Returns how many were sent.
 */
function xeric_stream_tell_owed(PDO $db, ?array $endpoint, int $limit = XERIC_STREAM_TELL_PER_TICK,
                                ?int $wall = null, int $timeout = XERIC_STREAM_TELL_TIMEOUT): int
{
    if ($limit <= 0) return 0;
    xeric_stream_schema($db);
    $wall ??= time();
    $q = $db->prepare("SELECT * FROM scene_streams WHERE status = 'ended' AND meta LIKE '%\"tell_owed\":true%'
                       ORDER BY ended_at, id LIMIT ?");
    $q->execute([$limit]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $q->closeCursor();
    $n = 0;
    foreach ($rows as $row) {
        $meta = json_decode((string)$row['meta'], true);
        if (!is_array($meta)) continue;
        unset($meta['tell_owed']);
        $m = json_encode((object)$meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $claim = $db->prepare('UPDATE scene_streams SET meta = ? WHERE id = ? AND meta = ?');
        $claim->execute([$m, (int)$row['id'], (string)$row['meta']]);
        if ($claim->rowCount() !== 1) continue;
        $row['meta'] = $m;
        $told = xeric_stream_tell_end(xeric_stream_row_endpoint($row, $endpoint), $row, (string)$row['reason'], $timeout);
        xeric_stream_told($db, $row, $told, $wall);
        $n++;
    }
    return $n;
}

/**
 * The heart's turn at a world's live views (forge/web/heart.php, once per
 * world per pass): the abandoned ones ended and metered as the sidebar would
 * (xeric_stream_sweep, no network), then up to $left of the DELETEs owed
 * sent, XERIC_STREAM_TELL_TIMEOUT each (xeric_stream_tell_owed).
 *
 * @return array{ended:int,told:int}
 */
function xeric_stream_pass(array $t, PDO $db, ?array $endpoint, int $left = XERIC_STREAM_TELL_PER_TICK,
                           ?int $wall = null): array
{
    $ended = xeric_stream_sweep($db, $wall, ['meter' => xeric_stream_ledger($db, xeric_clock_now($db, $t))]);
    return ['ended' => $ended, 'told' => $left > 0 ? xeric_stream_tell_owed($db, $endpoint, $left, $wall) : 0];
}

/**
 * Live views nobody is watching any more: no beat for longer than the away
 * window, or past their end. Ended as every view is (xeric_stream_end), the
 * tail metered — BUT WITH NO NETWORK: this runs on the sidebar's read path,
 * and a page render must not stall on a dead machine. The DELETE each one is
 * owed is written down (meta.tell_owed) and sent by xeric_stream_tell_owed,
 * from the heart's pass or the owner's next beat or end.
 *
 * $opts: meter. (endpoint is accepted and no longer used here.)
 */
function xeric_stream_sweep(PDO $db, ?int $wall = null, array $opts = []): int
{
    xeric_stream_schema($db);
    $wall ??= time();
    $n = 0;
    $ep = null;
    $end = ['meter' => $opts['meter'] ?? null, 'tell' => false];
    $rows = $db->query("SELECT id, last_beat, ends_at FROM scene_streams WHERE status = 'live'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($wall - (int)$r['last_beat'] > XERIC_STREAM_AWAY) {
            $n += (int)xeric_stream_end($db, $ep, (int)$r['id'], 'the page went away', $wall, $end);
        } elseif ($wall >= (int)$r['ends_at']) {
            $n += (int)xeric_stream_end($db, $ep, (int)$r['id'], 'it reached its limit', $wall, $end);
        }
    }
    return $n;
}

/**
 * The line a person is owed when a machine may still be billing for a live
 * view nobody could tell it to stop: the newest such end in the last day, or
 * null. The inspector shows it, and so does "the live view ended".
 */
function xeric_stream_unconfirmed_line(PDO $db, ?int $wall = null, ?array $row = null): ?string
{
    xeric_stream_schema($db);
    $wall ??= time();
    if ($row === null) {
        $q = $db->prepare("SELECT * FROM scene_streams WHERE status = 'ended' AND ended_at >= ? AND meta LIKE '%unconfirmed_end%'
                           ORDER BY ended_at DESC, id DESC LIMIT 1");
        $q->execute([$wall - 86400]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        $q->closeCursor();
    }
    if ($row === null) return null;
    $meta = json_decode((string)$row['meta'], true);
    $s = is_array($meta) ? (int)($meta['unconfirmed_end'] ?? 0) : 0;
    if ($s <= 0) return null;
    return 'The scene machine could not be told to stop the live view that ended at '
         . gmdate('H:i', (int)$row['ended_at']) . ' UTC; it may go on billing for up to '
         . xeric_stream_minutes($s) . ' more' . ((int)$row['costly'] === 1 ? ', on a machine that bills' : '') . '.';
}
