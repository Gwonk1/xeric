<?php
/**
 * power.php — the switches behind the ⚙ cog: stop the xeric, stop its
 * learning, and stop the machine it runs on.
 *
 *     POST { a:"clock", world, set:"stop"|"start" }  → { ok, paused }
 *     POST { a:"learn", world, set:"off"|"on" }      → { ok, learning }
 *     POST { a:"off" }                               → { ok } …and the server exits
 *
 * WHY THIS FILE EXISTS. The old play interface had a stop button; the rebuilt
 * one shipped without it, and a local install ended up with no control that
 * could stop a running xeric short of hunting PIDs in a terminal. The clock
 * half is nothing new — xeric_clock_pause()/resume() are the same calls the
 * engine connect/disconnect path already makes — this just gives the person
 * playing the same lever the plumbing has.
 *
 * "LEARN" IS THE KILL SWITCH THE CLI ALWAYS HAD (sweep-cli --no-learn) and the
 * web did not. This file only flips one world_state key; the SEMANTICS are
 * engine-side in learn.php (xeric_learn_enabled) so the heart, the CLI and the
 * web are all stopped by the same hand: off refuses the distil, drops every
 * weight and reach to its default and holds the lessons out of the prompts,
 * while the raw signals keep landing — a paused learner that also went blind
 * could never explain the week it missed. Nothing is deleted in either
 * direction.
 *
 * "OFF" IS FOR LOCAL RUNS ONLY, twice over: it requires the built-in server
 * (cli-server is what ./xeric starts; on Apache the parent is Apache and
 * killing it would be somebody else's outage) AND a loopback peer. The reply
 * is flushed before the signal so the page hears "ok" instead of a dropped
 * socket, and the TERM goes to the master process — the workers are its
 * children and follow it down.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    xeric_web_json(['error' => 'power is a POST'], 405);
}

$in = xeric_web_input();
$a  = (string)($in['a'] ?? '');

if ($a === 'clock') {
    $slug = xeric_web_slug((string)($in['world'] ?? $in['w'] ?? ''));
    try {
        $w = xeric_play_open($slug);
    } catch (Throwable $e) {
        xeric_web_json(['error' => $e->getMessage()], 404);
    }
    $set = (string)($in['set'] ?? '');
    if ($set === 'stop') {
        xeric_clock_pause($w['db']);
    } elseif ($set === 'start') {
        xeric_clock_resume($w['db']);
    } else {
        xeric_web_json(['error' => 'set is "stop" or "start"'], 400);
    }
    xeric_web_json(['ok' => 1, 'paused' => xeric_clock_is_paused($w['db'])]);
}

if ($a === 'learn') {
    $slug = xeric_web_slug((string)($in['world'] ?? $in['w'] ?? ''));
    try {
        $w = xeric_play_open($slug);
    } catch (Throwable $e) {
        xeric_web_json(['error' => $e->getMessage()], 404);
    }
    $set = (string)($in['set'] ?? '');
    if ($set !== 'off' && $set !== 'on') {
        xeric_web_json(['error' => 'set is "off" or "on"'], 400);
    }
    // One write, engine-owned meaning. The reply reads the state back rather
    // than echoing the request — the truth is what the database now says.
    xeric_web_json(['ok' => 1, 'learning' => xeric_learn_switch($w['db'], $set === 'on')]);
}

if ($a === 'off') {
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $local = in_array($peer, ['127.0.0.1', '::1'], true);
    if (PHP_SAPI !== 'cli-server' || !$local) {
        xeric_web_json(['error' => 'off is a local-run switch — this is not one'], 403);
    }
    // Say goodbye first: the signal races the socket otherwise, and a page
    // that asked for a shutdown deserves to hear it happened.
    header('Content-Type: application/json');
    echo json_encode(['ok' => 1, 'stopping' => 1]);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    else { @ob_end_flush(); @flush(); }
    if (function_exists('posix_getppid') && function_exists('posix_kill')) {
        $master = posix_getppid();
        // The worker's parent IS the cli-server master. TERM it, then follow.
        if ($master > 1) @posix_kill($master, 15);
        @posix_kill(posix_getpid(), 15);
    } else {
        // No posix on this build (static runtimes often ship without it).
        // getmyppid() does not exist in PHP, so the parent comes from /proc
        // where there is one; where there is not, the worker takes itself
        // down and the master is left for the launcher's own trap.
        $ppid = 0;
        $stat = @file_get_contents('/proc/self/status');
        if (is_string($stat) && preg_match('/^PPid:\s*(\d+)/mi', $stat, $m)) $ppid = (int)$m[1];
        @exec('kill -TERM ' . ($ppid > 1 ? (int)$ppid . ' ' : '') . (int)getmypid());
    }
    exit;
}

xeric_web_json(['error' => 'a is "clock", "learn" or "off"'], 400);
