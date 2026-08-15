<?php
/**
 * tools/mcp.php — a seat at the centre of a xeric, for an agent.
 *
 * A stdio MCP server that wraps the occupant endpoints — join, where, the
 * room, the thread — as tools, so any MCP-capable model can hold a player
 * seat in a running xeric the way a person with a phone does. Wire it up:
 *
 *     claude mcp add xeric -- php tools/mcp.php
 *     XERIC_BASE=http://127.0.0.1:8787   which instance (this is the default)
 *     XERIC_AGENT_SEATS=~/.xeric-agent.json   where the seat credential lives
 *
 * Then, in conversation: join with a pair code the owner minted (the same
 * five-minute code a phone scans), and everything after that is walking,
 * reading rooms, and talking.
 *
 * THE WRAPPER ADDS NOTHING, and that is its entire security story. It is a
 * CLIENT — the same JSON the phone speaks, with one header on it
 * (X-Xeric-Session, boot.php's agent door). Walls are resolved server-side
 * per viewer, limits ride the seat like any visitor's, and the why-trails
 * record what this seat was told and why. Nothing here could show the agent
 * a byte the seat may not see, because nothing here has any bytes of its own.
 *
 * WHAT IT DELIBERATELY DOES NOT EXPOSE: the forge, the review, the model
 * page beyond its own attachment, the inspector, and the time control. A
 * seat is a seat. Tuning a world is its owner's; skipping its hours is a
 * decision for somebody the world belongs to. The one clock cost an agent
 * can incur is the same one a person incurs — walking takes minutes, and a
 * walk whose hours spawn a sweep rides the skip meter like anybody's
 * (xeric_play_trip_job; the walk itself is never refused).
 *
 * THE SEAT LIFECYCLE, which join() does in one motion:
 *   1. mint 32 hex once — the client-minted bearer id the door takes;
 *   2. redeem the pair code (join.php?json=1) — the seat;
 *   3. attach a machine (model.php act=adduse) — without one, this session's
 *      own turns have no model to run on and say.php answers "detached";
 *   4. store {sid, player, name} per (instance, world), so the seat survives
 *      the process — an agent that rejoins every morning is a stranger every
 *      morning, and the whole point of a seat is being known.
 *
 * Protocol: MCP over stdio, newline-delimited JSON-RPC 2.0 — initialize,
 * tools/list, tools/call, ping. Logs to stderr only; stdout is the wire.
 * Zero dependencies. PHP 8.2+.
 */

declare(strict_types=1);

const XMCP_VERSION  = '1.0';
const XMCP_PROTO    = '2024-11-05';
const XMCP_SLOW     = 240;   // a turn on a local model is slow and legitimate
const XMCP_QUICK    = 30;

function xmcp_base(): string
{
    $b = rtrim(trim((string)(getenv('XERIC_BASE') ?: 'http://127.0.0.1:8787')), '/');
    return preg_match('#^https?://#i', $b) ? $b : 'http://' . $b;
}

function xmcp_seats_path(): string
{
    $p = trim((string)(getenv('XERIC_AGENT_SEATS') ?: ''));
    if ($p !== '') return $p;
    $home = (string)(getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir());
    return rtrim($home, '/') . '/.xeric-agent.json';
}

/** @return array<string,array{sid:string,player:int,name:string}> keyed base|world */
function xmcp_seats(): array
{
    $raw = @file_get_contents(xmcp_seats_path());
    $d = $raw === false ? null : json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function xmcp_seat_save(string $world, array $seat): void
{
    $all = xmcp_seats();
    $all[xmcp_base() . '|' . $world] = $seat;
    @file_put_contents(xmcp_seats_path(), json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(xmcp_seats_path(), 0600);   // a bearer id is a bearer id
}

/**
 * The seat for a world — or, with no world named, the only seat this instance
 * has, which is the common case: one agent, one world, no ceremony.
 *
 * @return array{world:string,sid:string,player:int,name:string}|null
 */
function xmcp_seat(?string $world): ?array
{
    $base = xmcp_base();
    $mine = [];
    foreach (xmcp_seats() as $k => $s) {
        [$b, $w] = array_pad(explode('|', $k, 2), 2, '');
        if ($b !== $base) continue;
        if ($world !== null && $world !== '' && $w !== $world) continue;
        $mine[$w] = $s;
    }
    if (count($mine) === 1) {
        $w = array_key_first($mine);
        return ['world' => $w] + $mine[$w];
    }
    if ($world !== null && $world !== '' && isset($mine[$world])) {
        return ['world' => $world] + $mine[$world];
    }
    return null;
}

// ---------------------------------------------------------------------------
// One HTTP call — streams, no curl, the header that is the whole door
// ---------------------------------------------------------------------------

/**
 * @param ?array $body JSON body when $form is false, form fields when true
 * @return array{status:int,json:?array,raw:string,location:string}
 *         `location` is the redirect target when the endpoint answered with
 *         one ('' otherwise) — deliberately captured rather than followed,
 *         because model.php speaks its refusals through it (see xmcp_no).
 */
function xmcp_http(string $method, string $url, ?array $body = null, bool $form = false,
                   ?string $sid = null, int $timeout = XMCP_QUICK): array
{
    $headers = [];
    if ($sid !== null) $headers[] = 'X-Xeric-Session: ' . $sid;
    $content = '';
    if ($body !== null) {
        if ($form) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $content   = http_build_query($body);
        } else {
            $headers[] = 'Content-Type: application/json';
            $content   = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
    $ctx = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $headers),
        'content'         => $content,
        'timeout'         => $timeout,
        'ignore_errors'   => true,    // a 4xx carries the sentence we want to read
        'follow_location' => 0,       // adduse answers 302; the redirect is not for us
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    $location = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
        if (preg_match('#^Location:\s*(.+)$#i', $h, $m)) $location = trim($m[1]);
    }
    if ($raw === false) {
        return ['status' => 0, 'json' => null, 'location' => '',
                'raw' => 'nothing is answering at ' . $url . ' — is the xeric running? (./xeric, then try again)'];
    }
    $d = json_decode((string)$raw, true);
    return ['status' => $status, 'json' => is_array($d) ? $d : null,
            'location' => $location, 'raw' => (string)$raw];
}

/**
 * The refusal a redirect carries, decoded — or '' when the redirect is a plain
 * success. model.php answers act=adduse with a 302 on BOTH outcomes; the only
 * thing telling them apart is the Location: a refusal goes back to
 * `model.php?no=<the sentence>`, a success to `model.php` bare. The helper
 * above deliberately never follows redirects, so the header is the one place
 * the sentence survives to be read.
 */
function xmcp_no(array $r): string
{
    $q = (string)(parse_url((string)($r['location'] ?? ''), PHP_URL_QUERY) ?? '');
    if ($q === '') return '';
    parse_str($q, $params);
    return trim((string)($params['no'] ?? ''));
}

/** The engine's own sentence when it refused, or a plain one when it broke. */
function xmcp_err(array $r): string
{
    if (($r['json']['error'] ?? '') !== '') {
        $e = (string)$r['json']['error'];
        if ((int)($r['json']['retry_after'] ?? 0) > 0) {
            $e .= ' (try again in ~' . (int)$r['json']['retry_after'] . 's)';
        }
        return $e;
    }
    if ($r['status'] === 0) return $r['raw'];
    return 'the instance answered HTTP ' . $r['status'] . ' with nothing readable';
}

// ---------------------------------------------------------------------------
// The tools
// ---------------------------------------------------------------------------

function xmcp_tools(): array
{
    $world = ['type' => 'string', 'description' =>
        'Which xeric. Optional when this agent holds exactly one seat.'];
    return [
        ['name' => 'join',
         'description' => 'Claim a seat in a xeric with a pair code the owner minted (codes last five '
             . 'minutes and work once). Does the whole lifecycle: mints this agent\'s session id, '
             . 'redeems the code, attaches a model machine so turns can run, and stores the seat so '
             . 'it survives restarts. After this, every other tool speaks as you.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'world'   => ['type' => 'string', 'description' => 'The xeric\'s slug (its folder name).'],
             'code'    => ['type' => 'string', 'description' => 'The pair code, e.g. M3MQ3SJ8.'],
             'name'    => ['type' => 'string', 'description' => 'What the people in the world should call you.'],
             'machine' => ['type' => 'string', 'description' =>
                 'Model endpoint for this seat\'s own turns. Default http://127.0.0.1:8080.'],
         ], 'required' => ['world', 'code', 'name']]],
        ['name' => 'seat',
         'description' => 'Which seats this agent holds on this instance — world, name, player number.',
         'inputSchema' => ['type' => 'object', 'properties' => []]],
        ['name' => 'look',
         'description' => 'The town as it stands: the clock, where you are, every place with who is '
             . 'standing in it, open or shut, and what it costs to walk there.',
         'inputSchema' => ['type' => 'object', 'properties' => ['world' => $world]]],
        ['name' => 'walk',
         'description' => 'Go somewhere. Burns real world-minutes (that is the point) and reports the '
             . 'arrival: who is there and what you walked in on. Walk to a shut place and you find it '
             . 'shut — the trip is never refused for hours.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'to' => ['type' => 'string', 'description' => 'The place key from look, or empty to go off on your own time.'],
             'world' => $world,
         ], 'required' => ['to']]],
        ['name' => 'read_room',
         'description' => 'The channel of a place: who is in it right now, whether you may speak, and '
             . 'the scrollback — including what was said while nobody was looking.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'place' => ['type' => 'string', 'description' => 'The place key.'],
             'world' => $world,
         ], 'required' => ['place']]],
        ['name' => 'say_in_room',
         'description' => 'Say something out loud in the place you are standing. The floor decides who '
             . 'answers — maybe one person, maybe two, maybe the room lets it sit, which is also an '
             . 'answer. You must have walked there first.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'place' => ['type' => 'string', 'description' => 'The place key you are standing in.'],
             'text'  => ['type' => 'string', 'description' => 'What you say, out loud.'],
             'world' => $world,
         ], 'required' => ['place', 'text']]],
        ['name' => 'text_character',
         'description' => 'Text one person directly — the 1:1 thread, from anywhere; distance never '
             . 'gates the phone. Slow replies are the model thinking, not a failure.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'handle' => ['type' => 'string', 'description' => 'Their handle.'],
             'text'   => ['type' => 'string', 'description' => 'Your message.'],
             'world'  => $world,
         ], 'required' => ['handle', 'text']]],
        ['name' => 'read_thread',
         'description' => 'Your thread with one person — what you two have said, oldest first.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'handle' => ['type' => 'string', 'description' => 'Their handle.'],
             'world'  => $world,
         ], 'required' => ['handle']]],
    ];
}

function xmcp_call(string $tool, array $a): string
{
    $base = xmcp_base();

    if ($tool === 'seat') {
        $out = [];
        foreach (xmcp_seats() as $k => $s) {
            [$b, $w] = array_pad(explode('|', $k, 2), 2, '');
            if ($b !== $base) continue;
            $out[] = $w . ' — as ' . (string)$s['name'] . ' (player ' . (int)$s['player'] . ')';
        }
        return $out === []
            ? 'No seat on ' . $base . ' yet. Ask the world\'s owner for a pair code and join.'
            : "Seats on $base:\n" . implode("\n", $out);
    }

    if ($tool === 'join') {
        $world = trim((string)($a['world'] ?? ''));
        $sid   = xmcp_seat($world)['sid'] ?? bin2hex(random_bytes(16));
        $r = xmcp_http('POST', $base . '/join.php?w=' . rawurlencode($world) . '&json=1', [
            'c' => strtoupper(trim((string)($a['code'] ?? ''))),
            'name' => trim((string)($a['name'] ?? '')), 'json' => '1',
        ], true, $sid);
        if (empty($r['json']['ok'])) return 'The door did not open: ' . xmcp_err($r);
        $player = (int)($r['json']['player'] ?? 0);

        // The machine, in the same motion — a seat that cannot run a turn is a
        // chair in a dark room. adduse answers with a redirect, not JSON — on
        // BOTH outcomes, so the status alone cannot tell a refusal from an
        // attachment. The refusal names itself in the Location (`?no=...`,
        // xmcp_no), and an agent whose seat is quietly detached deserves the
        // reason, not a success line.
        $machine = trim((string)($a['machine'] ?? '')) ?: 'http://127.0.0.1:8080';
        $m = xmcp_http('POST', $base . '/model.php', ['act' => 'adduse', 'base' => $machine], true, $sid);
        $no = xmcp_no($m);
        $attached = $m['status'] > 0 && $m['status'] < 400 && $no === '';

        xmcp_seat_save($world, ['sid' => $sid, 'player' => $player,
                                'name' => trim((string)($a['name'] ?? ''))]);
        return 'You are in: ' . (string)($r['json']['world'] ?? $world) . ', as '
            . trim((string)($a['name'] ?? '')) . ' (player ' . $player . ').'
            . ($attached ? '' : ' The model machine at ' . $machine . ' did not attach'
                . ($no !== '' ? ' — the instance said: ' . $no : '')
                . ' — your turns will be refused as "detached" until one does.')
            . ' The seat is stored; every other tool now speaks as you. Look around first.';
    }

    // Everything below needs a seat.
    $seat = xmcp_seat(isset($a['world']) ? trim((string)$a['world']) : null);
    if ($seat === null) {
        return 'No seat' . (isset($a['world']) ? ' in that xeric' : '') . ' on ' . $base
            . ' — join with a pair code first (or name the world; `seat` lists what you hold).';
    }
    $sid = $seat['sid'];
    $w   = $seat['world'];

    if ($tool === 'look') {
        $r = xmcp_http('GET', $base . '/where.php?w=' . rawurlencode($w), null, false, $sid);
        $d = $r['json'];
        if (empty($d['ok'])) return xmcp_err($r);
        $now = (array)($d['now'] ?? []);
        $you = (array)($d['you'] ?? []);
        $out = [];
        $out[] = 'The clock: ' . (string)($now['hhmm'] ?? '?') . ', ' . (string)($now['phase'] ?? '')
            . ($d['skipping'] ?? false ? ' (the world is fast-forwarding — walks will refuse until it lands)' : '');
        $out[] = 'You (' . (string)($you['name'] ?? '?') . '): '
            . (($you['place'] ?? '') !== '' ? 'at ' . (string)$you['place'] : 'nowhere in particular — on your own time');
        foreach ((array)($d['places'] ?? []) as $p) {
            $who = array_map(fn($q) => (string)$q['name']
                . ((string)($q['doing'] ?? '') !== '' ? ' (' . (string)$q['doing'] . ')' : ''),
                (array)($p['who'] ?? []));
            $out[] = '- ' . (string)$p['name'] . ' [' . (string)$p['key'] . '] '
                . ((string)($p['kind'] ?? '') !== '' ? '(' . (string)$p['kind'] . ') ' : '')
                . (!empty($p['here']) ? '— YOU ARE HERE ' : '')
                . (!empty($p['open']) ? '· open' : '· shut')
                . ' · ' . (int)($p['minutes'] ?? 0) . ' min'
                . ($who !== [] ? ' · ' . implode(', ', $who) : ' · empty');
        }
        return implode("\n", $out);
    }

    if ($tool === 'walk') {
        $to = trim((string)($a['to'] ?? ''));
        $r = xmcp_http('POST', $base . '/where.php', ['world' => $w, 'to' => $to], false, $sid, 60);
        $d = $r['json'];
        if (empty($d['ok'])) return xmcp_err($r);
        $went = (array)($d['went'] ?? []);
        if (($went['to'] ?? null) === null) {
            return 'You head off on your own time (' . (int)($went['minutes'] ?? 0) . ' min).';
        }
        $who = array_map(fn($q) => (string)$q['name'], (array)($went['who'] ?? []));
        $out = 'You walk over (' . (int)($went['minutes'] ?? 0) . ' min). '
            . ((string)($went['scene'] ?? '') !== '' ? (string)$went['scene']
               : ($who !== [] ? 'Here: ' . implode(', ', $who) . '.' : 'Nobody is here.'
                  . (empty($went['open']) ? ' It is shut.' : '')));
        return $out;
    }

    if ($tool === 'read_room') {
        $place = trim((string)($a['place'] ?? ''));
        $r = xmcp_http('GET', $base . '/play.php?a=room&w=' . rawurlencode($w)
            . '&p=' . rawurlencode($place), null, false, $sid);
        $d = $r['json'];
        if (empty($d['ok'])) return xmcp_err($r);
        $who = array_map(fn($q) => (string)$q['name'], (array)($d['who'] ?? []));
        $out = [(string)($d['name'] ?? $place) . (empty($d['open']) ? ' (shut)' : ' (open)')
            . ' — ' . ($who !== [] ? 'here now: ' . implode(', ', $who) : 'nobody in it') . '.'];
        if ((string)($d['why'] ?? '') !== '') $out[] = $d['why'];
        $msgs = (array)($d['messages'] ?? []);
        if ($msgs === []) {
            $out[] = '(nothing has been said in here yet)';
        } else {
            foreach (array_slice($msgs, -20) as $m) {
                $out[] = ((string)($m['name'] ?? '') !== '' ? (string)$m['name'] . ': ' : '— ')
                    . (string)($m['text'] ?? '');
            }
        }
        return implode("\n", $out);
    }

    if ($tool === 'say_in_room') {
        $r = xmcp_http('POST', $base . '/play.php?a=roomsay&w=' . rawurlencode($w), [
            'place' => trim((string)($a['place'] ?? '')),
            'text'  => (string)($a['text'] ?? ''),
        ], false, $sid, XMCP_SLOW);
        $d = $r['json'];
        if (empty($d['ok'])) return xmcp_err($r);
        $lines = (array)($d['lines'] ?? []);
        if ($lines === []) {
            return ((string)($d['note'] ?? '') !== '' ? (string)$d['note'] : 'Nobody picks it up.')
                . ' (Silence chosen is a real answer here, not an error.)';
        }
        return implode("\n", array_map(
            fn($l) => (string)$l['name'] . ': ' . (string)$l['text'], $lines));
    }

    if ($tool === 'text_character') {
        $r = xmcp_http('POST', $base . '/say.php', [
            'world' => $w,
            'handle' => trim((string)($a['handle'] ?? '')),
            'text' => (string)($a['text'] ?? ''),
        ], false, $sid, XMCP_SLOW);
        $d = $r['json'];
        if (empty($d['ok'])) return xmcp_err($r);
        return (string)($d['name'] ?? $a['handle']) . ': ' . (string)($d['text'] ?? '')
            . ((string)($d['when'] ?? '') !== '' ? "\n(" . (string)$d['when'] . ')' : '');
    }

    if ($tool === 'read_thread') {
        $r = xmcp_http('GET', $base . '/play.php?a=thread&w=' . rawurlencode($w)
            . '&h=' . rawurlencode(trim((string)($a['handle'] ?? ''))), null, false, $sid);
        $d = $r['json'];
        if ($d === null || isset($d['error'])) return xmcp_err($r);
        $msgs = (array)($d['messages'] ?? []);
        if ($msgs === []) return 'Nothing between you two yet.';
        $out = [];
        foreach (array_slice($msgs, -25) as $m) {
            $out[] = ((string)($m['name'] ?? '') !== '' ? (string)$m['name'] . ': ' : '— ')
                . (string)($m['text'] ?? '');
        }
        return implode("\n", $out);
    }

    return "There is no tool called '$tool'.";
}

// ---------------------------------------------------------------------------
// The wire: newline-delimited JSON-RPC 2.0 on stdio
// ---------------------------------------------------------------------------

function xmcp_send(array $msg): void
{
    fwrite(STDOUT, json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fflush(STDOUT);
}

function xmcp_result($id, array $result): void
{
    xmcp_send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

function xmcp_error($id, int $code, string $message): void
{
    xmcp_send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}

// Not when included by a test that wants the functions without the loop.
if (realpath($argv[0] ?? '') !== __FILE__) return;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $m = json_decode($line, true);
    if (!is_array($m)) { xmcp_error(null, -32700, 'that line was not JSON'); continue; }

    $id     = $m['id'] ?? null;
    $method = (string)($m['method'] ?? '');
    $params = (array)($m['params'] ?? []);
    $notice = !array_key_exists('id', $m);

    try {
        if ($method === 'initialize') {
            // Answer in the client's dialect when it names one that looks like a
            // dialect; ours otherwise. A tools-only server has nothing that
            // differs between the revisions this file could meet.
            $pv = (string)($params['protocolVersion'] ?? '');
            xmcp_result($id, [
                'protocolVersion' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $pv) ? $pv : XMCP_PROTO,
                'capabilities'    => ['tools' => new stdClass()],
                'serverInfo'      => ['name' => 'xeric', 'version' => XMCP_VERSION],
            ]);
        } elseif ($method === 'notifications/initialized' || str_starts_with($method, 'notifications/')) {
            // heard, and nothing to say back
        } elseif ($method === 'ping') {
            xmcp_result($id, []);
        } elseif ($method === 'tools/list') {
            xmcp_result($id, ['tools' => xmcp_tools()]);
        } elseif ($method === 'tools/call') {
            $text = xmcp_call((string)($params['name'] ?? ''), (array)($params['arguments'] ?? []));
            xmcp_result($id, ['content' => [['type' => 'text', 'text' => $text]]]);
        } elseif (!$notice) {
            xmcp_error($id, -32601, "no such method: $method");
        }
    } catch (Throwable $e) {
        // The agent gets a sentence, the stderr gets the truth, the loop lives.
        fwrite(STDERR, 'xeric-mcp: ' . $e->getMessage() . "\n");
        if (!$notice) {
            xmcp_result($id, ['content' => [['type' => 'text',
                'text' => 'That went wrong on the wrapper\'s side: ' . $e->getMessage()]], 'isError' => true]);
        }
    }
}
