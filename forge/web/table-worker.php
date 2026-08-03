<?php
/**
 * table-worker.php — a night at the table, off the end of the request.
 *
 * The cards cost nothing: a night is arithmetic and finishes in under a
 * millisecond. What costs is the one model call that writes what was SAID, and
 * it is the reason this is detached rather than inline — that call is the same
 * size as any other and the edge cuts a request long before a model finishes a
 * slow one.
 *
 * THE ORDER IS THE POINT, and it is the same order the sweep uses: the night is
 * PLAYED and SETTLED first, then the model is told how it went. A model asked
 * to run a card game picks the winner it likes.
 *
 * Usage (never a URL): php table-worker.php <job-id>  with the payload on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("table-worker.php is not a page\n"); }

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/table.php';

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "table: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => 'nobody said which game']);
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

$ticket = (string)($payload['ticket'] ?? '');
$lock   = null;

/**
 * STOP, AND HAND THE MODEL BACK FIRST. PHP does not run a `finally` on
 * `exit()`, so the exit in the catch below stranded the queue on a process that
 * was already gone — and the next person in line waited out the whole hold for
 * a GPU that was free. The `finally` stays as the backstop; this cannot
 * double-release.
 */
$done = function (int $code) use (&$lock, &$ticket): void {
    if ($lock !== null)     { xeric_queue_release($lock); $lock = null; }
    elseif ($ticket !== '') { xeric_queue_leave($ticket); $ticket = ''; }
    exit($code);
};

try {
    $w   = xeric_play_open(xeric_web_slug((string)($payload['slug'] ?? '')));
    $T   = $w['template'];
    $db  = $w['db'];
    $now = xeric_clock_now($db, $T);

    $tables = xeric_tables($T);
    $key    = (string)($payload['table'] ?? '');
    if (!isset($tables[$key])) throw new RuntimeException('there is no game there');
    $table = $tables[$key];

    if (!xeric_table_tonight($table, $now)) {
        throw new RuntimeException(ucfirst((string)$table['name']) . ' is not tonight.');
    }

    // WHO IS ACTUALLY THERE. Not a guest list — the same presence reader the
    // whole engine uses, at the world's current hour, so a game you walk in on
    // has the people the schedule says are in that room and nobody else.
    $seats = [];
    foreach (xeric_world_who_is_where($T, $now) as $h => $row) {
        if ((string)($row['where'] ?? '') === $key) $seats[] = (string)$h;
    }
    $seats = array_values(array_diff($seats, xeric_dead_handles($db)));
    if (count($seats) < 1) throw new RuntimeException('There is nobody down there right now.');
    if (count($seats) > XERIC_TABLE_MAX - 1) $seats = array_slice($seats, 0, XERIC_TABLE_MAX - 1);

    $style = (string)($payload['style'] ?? 'steady');
    $hands = max(2, min(20, (int)($payload['hands'] ?? 8)));

    xeric_web_job_append($job, ['k' => 'hello',
        'message' => 'you sit down at ' . (string)$table['name'],
        'seats' => array_map(fn($h) => xeric_world_name($T, $h) ?: $h, $seats)]);

    // -- the night, which is arithmetic and already over ---------------------
    $night = xeric_table_sit($T, $db, $table, $seats, $style, $hands,
        (int)$now['epoch'] + (int)crc32($key . $style), null, (int)$now['epoch']);

    foreach (array_slice((array)$night['result']['log'], -12) as $line) {
        xeric_web_job_append($job, ['k' => 'note', 'message' => (string)$line]);
    }
    xeric_web_job_append($job, ['k' => 'note',
        'message' => $night['net'] > 0 ? 'You are up ' . $night['net'] . '.'
            : ($night['net'] < 0 ? 'You are down ' . abs($night['net']) . '.' : 'You broke even.')]);

    // -- and only now, what was said ----------------------------------------
    $talk = [];
    try {
        $endpoint = xeric_play_endpoint();
        if ($ticket === '') $ticket = xeric_queue_join('table', $sid);
        $got = xeric_queue_wait($ticket, XERIC_QUEUE_WAIT_MAX,
            function (int $ahead, int $eta, string $phrase) use ($job): void {
                xeric_web_job_append($job, ['k' => 'queue', 'ahead' => $ahead, 'eta' => $eta,
                                            'text' => ucfirst($phrase)]);
            });
        if ($got['ok']) {
            $lock   = $got['hold'];
            $ticket = '';
            $talk = xeric_table_talk($T, $table, $night['result'], $endpoint, ['timeout' => 90]);
            foreach ($talk as $line) {
                xeric_web_job_append($job, ['k' => 'note', 'message' => $line]);
            }
        }
    } catch (Throwable $e) {
        // A QUIET GAME IS STILL A GAME. The money already moved and is already
        // committed; a model that could not be reached costs the table talk and
        // nothing else, and saying so beats pretending the night did not happen.
        xeric_web_job_append($job, ['k' => 'note',
            'message' => 'Nobody said much — the model was not answering.']);
    }

    xeric_web_job_append($job, ['k' => 'done',
        'message' => xeric_table_say($T, $night['result']),
        'night' => ['net' => (int)$night['net'], 'purse' => (int)$night['purse'],
                    'hands' => (int)$night['result']['hands'], 'talk' => count($talk)]]);
} catch (Throwable $e) {
    xeric_web_job_append($job, ['k' => 'error', 'message' => $e->getMessage()]);
    $done(1);
} finally {
    if ($lock !== null) xeric_queue_release($lock);
    elseif ($ticket !== '') xeric_queue_leave($ticket);
}
