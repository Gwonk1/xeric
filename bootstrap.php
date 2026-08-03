<?php
/**
 * bootstrap.php — everything a launcher has to work out, decided once.
 *
 *     php bootstrap.php --sh    [--port N] [--data DIR]
 *     php bootstrap.php --cmd   [--port N] [--data DIR]
 *
 * WHY THIS EXISTS. There were two launchers coming: a bash one and a Windows
 * one, and every line they would have had in common is a decision — which PHP,
 * which extensions are missing, which port is free, where the data lives. Two
 * copies of a decision is one copy that will be wrong, and the one that is wrong
 * is always the platform the author does not use.
 *
 * So the shell part of each launcher is the part that genuinely cannot be
 * shared: starting a background process and trapping an exit. Everything else is
 * here, in the language the app is already written in, checked by the same
 * runtime that will run it.
 *
 * IT PRINTS ASSIGNMENTS AND NOTHING ELSE, in the dialect asked for:
 *
 *     --sh    export XERIC_PORT='8787'
 *     --cmd   set "XERIC_PORT=8787"
 *
 * so a launcher runs it and evaluates the output. Anything it wants to SAY goes
 * to stderr, which neither shell will try to execute.
 *
 * EXIT 1 MEANS DO NOT START. The message is already on stderr and is written for
 * somebody who has just double-clicked something, not for a log.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$dialect = '';
$port    = (int)(getenv('XERIC_PORT') ?: 8787);
$data    = (string)(getenv('XERIC_DATA_DIR') ?: '');
// LOOPBACK UNLESS ASKED, and asked in words. --lan binds every interface so a
// phone on the same wifi can reach this xeric — which also means everything
// else on that network can. There is no password on this app; the guard is
// the bind address, and that is exactly why it is opt-in per run.
$host    = (string)(getenv('XERIC_HOST') ?: '127.0.0.1');

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--sh':  $dialect = 'sh';  break;
        case '--cmd': $dialect = 'cmd'; break;
        case '--port':
            $v = $argv[++$i] ?? '';
            if (!ctype_digit((string)$v) || (int)$v < 1 || (int)$v > 65535) {
                fwrite(STDERR, "xeric: --port needs a number between 1 and 65535\n");
                exit(1);
            }
            $port = (int)$v;
            break;
        case '--data':
            $data = (string)($argv[++$i] ?? '');
            if ($data === '') { fwrite(STDERR, "xeric: --data needs a directory\n"); exit(1); }
            break;
        case '--lan': $host = '0.0.0.0'; break;
        default:
            fwrite(STDERR, "xeric: unknown option {$argv[$i]}\n");
            exit(1);
    }
}
if ($dialect === '') { fwrite(STDERR, "xeric: bootstrap needs --sh or --cmd\n"); exit(1); }

$here = str_replace('\\', '/', __DIR__);
$web  = $here . '/forge/web';
if (!is_dir($web)) { fwrite(STDERR, "xeric: no forge/web beside this script\n"); exit(1); }

// -- what this PHP can do ----------------------------------------------------
// CHECKED HERE RATHER THAN DISCOVERED AS A STACK TRACE THREE SCREENS IN. sqlite
// is not optional — a xeric IS a sqlite database — and zlib is what reads a PDF
// on a machine with no poppler.
if (version_compare(PHP_VERSION, '8.1', '<')) {
    fwrite(STDERR, 'xeric: this is PHP ' . PHP_VERSION . " and it needs 8.1 or newer.\n");
    exit(1);
}
$missing = [];
foreach (['pdo_sqlite', 'sqlite3', 'mbstring', 'json', 'zlib'] as $ext) {
    if (!extension_loaded($ext)) $missing[] = $ext;
}
if ($missing !== []) {
    fwrite(STDERR, 'xeric: this php is missing: ' . implode(' ', $missing) . "\n");
    exit(1);
}

// -- where things live -------------------------------------------------------
require_once $web . '/boot.php';                 // xeric_web_home_dir(), and nothing else yet
if ($data === '') $data = xeric_web_home_dir();
$data = rtrim(str_replace('\\', '/', $data), '/');

foreach (['', '/worlds', '/sessions', '/session-worlds', '/limits', '/queue', '/jobs'] as $sub) {
    $d = $data . $sub;
    if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
        fwrite(STDERR, "xeric: cannot create $d\n");
        exit(1);
    }
}
if (!is_writable($data)) { fwrite(STDERR, "xeric: $data is not writable\n"); exit(1); }

// -- a port nobody else is on ------------------------------------------------
// A BUSY PORT IS THE FIRST THING THAT GOES WRONG, and the error php -S gives for
// it scrolls past in a window nobody is reading yet. Walk up instead.
$tries = 0;
while (true) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
    if ($fp === false) break;
    fclose($fp);
    if (++$tries > 20) { fwrite(STDERR, "xeric: no free port near $port\n"); exit(1); }
    $port++;
}

// -- the answers -------------------------------------------------------------
$out = [
    'XERIC_HERE'       => $here,
    'XERIC_WEB'        => $web,
    // PHP_BINARY is whatever is running this, which is already the bundled one
    // when a launcher found it — so the detached workers inherit it without
    // anybody passing it along.
    'XERIC_PHP'        => PHP_BINARY,
    'XERIC_PORT'       => (string)$port,
    'XERIC_HOST'       => $host,
    'XERIC_DATA_DIR'   => $data,
    'XERIC_WORLDS_DIR' => $data . '/worlds',
    // The browser is opened on loopback whatever the bind: this machine can
    // always reach itself, and 0.0.0.0 is a bind address rather than a place.
    'XERIC_URL'        => 'http://127.0.0.1:' . $port . '/play.php',
    // The machine is yours, so its address is yours to change.
    'XERIC_LOCAL_EDIT' => (string)(getenv('XERIC_LOCAL_EDIT') ?: 'true'),
];

// PHP_CLI_SERVER_WORKERS IS NOT OPTIONAL WHERE IT EXISTS. The built-in server is
// otherwise one request at a time, and progress.php holds a stream open for the
// length of a build — with one worker that stream blocks every other request,
// so the page you are watching it from cannot load.
//
// IT DOES NOT EXIST ON WINDOWS. PHP only forks workers where fork exists, so
// there the server is single-request whatever this says, and the stream is
// shortened instead (XERIC_PROGRESS_WINDOW) so it hands the server back every
// few seconds and reconnects. That is a mitigation, not a fix; the fix is not to
// use php -S on Windows at all.
if (PHP_OS_FAMILY === 'Windows') {
    $out['XERIC_PROGRESS_WINDOW'] = (string)(getenv('XERIC_PROGRESS_WINDOW') ?: '3');
} else {
    $out['PHP_CLI_SERVER_WORKERS'] = (string)(getenv('PHP_CLI_SERVER_WORKERS') ?: '6');
}

foreach ($out as $k => $v) {
    echo $dialect === 'sh'
        ? $k . '=' . escapeshellarg($v) . '; export ' . $k . "\n"
        : 'set "' . $k . '=' . str_replace('"', '', $v) . '"' . "\n";
}
exit(0);
