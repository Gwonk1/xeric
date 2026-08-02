<?php
/**
 * progress.php — the build, watched.
 *
 * Server-Sent Events over a job file: every note worker.php writes is a frame
 * here, in order, with its line number as the SSE `id`. Ordinary SSE — an
 * EventSource in the browser, `curl -N` on the command line.
 *
 * IT CLOSES ON PURPOSE. Cloudflare kills a streaming response on this host at
 * about 120 seconds no matter how often it is flushed, so rather than be cut
 * mid-sentence this hangs up cleanly at 40 and lets the client come back with
 * Last-Event-ID (EventSource does that by itself). The build is not in this
 * request, so a reconnect costs nothing and misses nothing.
 *
 * A silent job is a dead job: if nothing has been appended for four minutes and
 * no result has landed, that is reported as a failure rather than spun on
 * forever.
 *
 * AND IT COSTS A PROCESS, WHICH IS WHY IT IS RATIONED. Every open stream is one
 * PHP worker held for its whole window, and this app shares a box with other
 * people's sites. A job id that names nothing used to be slept on for ten
 * seconds, so two hundred well-formed guesses took the whole pool down — with
 * nothing to show for it, because the client reconnects by itself and a build
 * that is half a second late is picked up on the next connection anyway. So: an
 * absent job is answered at once, and one visitor may hold only so many streams.
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';

/**
 * Seconds per connection, well inside any proxy's patience.
 *
 * OVERRIDABLE BECAUSE OF WINDOWS. PHP only forks server workers where fork
 * exists, so `php -S` there is one request at a time however many
 * PHP_CLI_SERVER_WORKERS says — and a stream held for forty seconds is forty
 * seconds in which the page watching it cannot load anything else. The Windows
 * launcher sets this to a few seconds instead, so the stream hands the server
 * back regularly and the client reconnects with Last-Event-ID and carries on
 * from the same line, which it already knows how to do.
 *
 * A MITIGATION AND NOT A FIX. The fix is a server that can do two things at
 * once, or polling instead of streaming; both are on the punchlist.
 */
define('XERIC_PROGRESS_WINDOW', max(2, (int)(getenv('XERIC_PROGRESS_WINDOW') ?: 40)));
const XERIC_PROGRESS_SILENT = 240;   // seconds without a note before we call it dead

/**
 * Streams one visitor may hold at once.
 *
 * A build in one tab and a skip in another is two, and this app has nothing that
 * legitimately opens more than that at a time, so four leaves room for a reload
 * whose old connection has not been reaped yet and still refuses a tab farm.
 */
const XERIC_PROGRESS_MAX = 4;

$job = (string)($_GET['job'] ?? '');

// EventSource resumes with a header; curl and the fallback client use ?from=.
$from = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['from'] ?? 0);
if ($from < 0) $from = 0;

set_time_limit(0);
ignore_user_abort(false);
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); @apache_setenv('dont-vary', '1'); }

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, no-transform');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) @ob_end_flush();
@ob_implicit_flush(true);

echo ':' . str_repeat(' ', 2048) . "\n\n";   // shove past any proxy's read-ahead buffer
echo "retry: 750\n\n";                       // how soon EventSource should come back
@flush();

$send = function (string $event, array $data, ?int $id = null): void {
    if ($id !== null) echo 'id: ' . $id . "\n";
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
};

// A job id that is not a job id used to be answered with a 400 and a JSON body.
// An EventSource cannot read that: it sees a non-200 with the wrong content
// type, fires its own `error`, and the page shows "the connection keeps
// dropping" — which is a lie about a request that was wrong on arrival. So the
// refusal is said in the stream's own grammar, where the listener will show it.
if (!xeric_web_job_ok($job)) {
    $send('failed', ['message' => 'that is not a job this machine is running. If you got here from a '
        . 'bookmark or a reload, the work it was watching is long finished, the xeric it made is on the shelf.']);
    exit;
}

// One seat per open stream, given back when this request ends — including when
// the client simply goes away, which is what makes the count self-healing. The
// window is a little longer than a connection so a worker killed outright
// releases its seat by expiry rather than holding one forever.
//
// Keyed off the cookie this request already carries, or the hashed address when
// there is none — deliberately NOT xeric_session_id(), which would mint a
// session record per connection and turn a rate limit into a way of filling the
// disk. A cookieless flood is one address and is rationed as one.
$who = (string)($_COOKIE[XERIC_WEB_COOKIE] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $who)) $who = 'ip-' . xeric_limit_ip();
$seat = xeric_limit_reserve('sse-' . $who, XERIC_PROGRESS_WINDOW + 20, XERIC_PROGRESS_MAX, time());
if (!$seat['ok']) {
    $send('failed', ['message' => 'This browser already has ' . XERIC_PROGRESS_MAX . ' of these live feeds open '
        . 'on the demo, which is as many as one visitor may hold, each one is a whole process on a machine '
        . 'that is doing other work too. Close a tab that is watching a build or a skip, or give it a minute '
        . 'and reload this one; nothing that is running has stopped.']);
    exit;
}

// A job file that is not there is a build that is over, was swept, or was never
// real: answered at once rather than slept on. build.php and tick.php both wait
// for the worker's first word before handing this id out, so a stream that is
// genuinely early is a reconnect away from being right — and sleeping here is
// how two hundred guessed ids take down every site on the box.
if (!is_file(xeric_web_job_path($job))) {
    $send('failed', ['message' => 'that build is not running any more, it may have finished hours ago']);
    exit;
}

$started = microtime(true);
$last = microtime(true);
$beat = 0.0;

while (true) {
    $j = xeric_web_job_read($job, $from);

    foreach ($j['records'] as $r) {
        $rec = $r['rec'];
        $k = (string)($rec['k'] ?? 'note');
        unset($rec['k'], $rec['at']);
        // NOT called "error": EventSource fires its own `error` event on every
        // reconnect, and a server frame by that name is indistinguishable from
        // a dropped connection at the listener.
        $send($k === 'error' ? 'failed' : $k, $rec, $r['i']);
        $from = $r['i'] + 1;
        $last = microtime(true);
    }

    if ($j['done']) exit;

    $now = microtime(true);
    if ($now - $last > XERIC_PROGRESS_SILENT) {
        $send('failed', ['message' => 'the build stopped answering, nothing has happened for four minutes']);
        exit;
    }
    // Hand back before the proxy takes it away. The client reconnects with
    // Last-Event-ID and carries on from the same line.
    if ($now - $started > XERIC_PROGRESS_WINDOW) {
        $send('pause', ['from' => $from]);
        exit;
    }
    // THE HEARTBEAT CARRIES THE METER. A build is a dozen model calls over three
    // minutes and the tokens were being counted correctly the whole time; the
    // only thing missing was anybody telling the page, which does not reload
    // while it watches. This costs one small session read every two seconds and
    // needs no request of its own.
    if ($now - $beat > 2.0) {
        $beat = $now;
        $send('meter', ['by' => xeric_web_tokens_by()]);
    }

    usleep(300000);
}
