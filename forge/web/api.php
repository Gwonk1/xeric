<?php
/**
 * api.php — the client API, v1. The contract is docs/API.md.
 *
 *     GET api.php/v1/                               version, auth modes, features
 *     GET api.php/v1/worlds                         the shelf
 *     GET api.php/v1/worlds/{w}                     the world, as data
 *     GET api.php/v1/worlds/{w}/pulse               what changed (ETag; 304 when nothing did)
 *     GET api.php/v1/worlds/{w}/map                 the town (where.php's read)
 *     GET api.php/v1/worlds/{w}/look                the room you stand in (scene.php's read)
 *     GET api.php/v1/worlds/{w}/look/{k}/{f}        that view's bytes
 *     GET api.php/v1/worlds/{w}/threads/{h}?since=  one thread, with ids and epochs
 *     GET api.php/v1/worlds/{w}/messages/{id}/audio a line already read aloud
 *     GET api.php/v1/jobs/{id}?from=                a job's notes (SSE with Accept: text/event-stream)
 *
 * Routed on PATH_INFO, with `?r=/v1/…` for a host that strips it — no rewrite
 * rules, which .htaccess deliberately avoids. play.php's own wire is v0: it
 * is private and free to change; this one is additive within v1.
 *
 * THIN BY DESIGN. Every route calls a function a page already calls and hands
 * the answer to a shaper in api-lib.php; if a client needs a fact that is not
 * here, it goes into the engine first (where.php's rule), never into this file.
 *
 * THE READ HALF ONLY. Every verb — say, go, look, skip, rewind, hear, read,
 * the room — is the write half's, and will `require` the existing scripts
 * through boot.php's two seams (xeric_web_input_set, xeric_web_json_shaper).
 */

declare(strict_types=1);

require_once __DIR__ . '/api-lib.php';

// Every answer says which contract it is, the 304 and the stream included,
// and every JSON body raised anywhere below — a limit, an owner refusal, the
// floor under the whole app — leaves enveloped.
header('Xeric-Api: ' . XERIC_API_VERSION);
header('X-Content-Type-Options: nosniff');
xeric_web_json_shaper('xeric_api_envelope');

[$seg, $viaQuery] = xeric_api_path($_SERVER, $_GET);
$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
xeric_api_base(str_ends_with($script, '/api.php') ? $script : 'api.php', $viaQuery);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$who = xeric_api_identify($_SERVER);
$res = $who['ok']
    ? xeric_api_route($method, $seg, $_GET, $_SERVER, $who)
    : xeric_api_no((int)$who['status'], (string)$who['kind'], (string)$who['error'],
                   (int)$who['status'] === 401 ? ['WWW-Authenticate' => 'Bearer realm="xeric"'] : []);

// A job, streamed: progress.php does the streaming, and its frames come out
// through the same envelope (its $send asks boot.php for the shaper).
if (isset($res['delegate'])) {
    $_GET = (array)$res['query'];                  // Last-Event-ID still wins, as it does there
    require __DIR__ . '/' . basename((string)$res['delegate']);
    exit;
}

http_response_code((int)$res['status']);
foreach ((array)($res['headers'] ?? []) as $k => $v) header($k . ': ' . $v);

if (isset($res['bytes'])) {
    if ($method !== 'HEAD') echo $res['bytes'];
    exit;
}
if (($res['body'] ?? null) === null) exit;          // a 304 has no body

if (!isset($res['headers']['Cache-Control'])) header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
if ($method !== 'HEAD') {
    echo json_encode(xeric_api_envelope((array)$res['body'], (int)$res['status']),
                     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
