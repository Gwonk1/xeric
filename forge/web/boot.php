<?php
/**
 * boot.php — where the web forge finds itself, and everything it needs to know
 * before it can render a question or run a pass.
 *
 * Three jobs, and nothing else:
 *
 *  1. FIND THE LIBRARY. The deployed copy carries engine/ + forge/ under
 *     web/lib/, so a docroot holds one self-contained tree; a checkout has them
 *     two levels up. One line resolves it, and forge.php's own
 *     `require '../engine/…'` then works unchanged in both — which is the whole
 *     point of mirroring the layout instead of rewriting include paths.
 *
 *  2. KNOW WHERE THE MODEL IS. It may be llama.cpp on 127.0.0.1:8080, Ollama on
 *     11434, LM Studio on 1234, or the far end of an ssh tunnel on a host that
 *     is not the one serving this. Hardcoding any of them is a bug waiting for
 *     a deploy, so: config file, then env, then a 400ms connect probe.
 *
 *  3. HOLD THE ANSWERS BETWEEN SCREENS. A session id in a cookie and a JSON
 *     file in a temp dir. No database, and — the one rule with teeth — NEVER an
 *     API key. A key the user brings is posted with the build that uses it and
 *     lives in one PHP process for the life of that request. It is not written
 *     here, not logged, and not echoed back.
 */

declare(strict_types=1);

$XERIC_LIB = is_dir(__DIR__ . '/lib/forge') ? __DIR__ . '/lib' : dirname(__DIR__, 2);
require_once $XERIC_LIB . '/forge/forge.php';
require_once $XERIC_LIB . '/engine/notify.php';   // the phone, and the meter's marks
define('XERIC_WEB_LIB', $XERIC_LIB);

/**
 * Cookie name for the visitor.
 *
 * It says "forge" because the wizard minted it first, and it is deliberately not
 * renamed: session.php makes this ONE identity for the whole demo — the wizard's
 * answers, the worlds a visitor owns, and what they have spent. A second cookie
 * for ownership would be two visitors in one browser.
 */
const XERIC_WEB_COOKIE = 'xeric_forge';

/**
 * How long an abandoned session sticks around before it is swept.
 *
 * session.php owns what expiry MEANS (the record, the forked databases and the
 * worlds it forged all go together); this is only the cookie's own life, and it
 * is re-set on every visit so seven days means seven idle days.
 */
const XERIC_WEB_SESSION_TTL = 604800;   // 7 days

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/**
 * Settings, in order of authority: config.local.php (written by deploy),
 * environment, then a sensible guess. Every value has a working default so an
 * unconfigured checkout still runs.
 */
function xeric_web_config(): array
{
    static $c = null;
    if ($c !== null) return $c;

    $c = [];
    $f = __DIR__ . '/config.local.php';
    if (is_file($f)) {
        $x = @include $f;
        if (is_array($x)) $c = $x;
    }

    // ENVIRONMENT BEATS THE FILE, and it used to be the other way round.
    //
    // config.local.php describes a HOST — deploy.sh writes it and nothing else
    // should. An environment variable is a statement by the process that is
    // running right now, and it is the more specific of the two: a launcher
    // saying "this install keeps its worlds here", a test saying "use this
    // throwaday directory".
    //
    // The old order made a file the process never asked for silently outrank
    // the sandbox the process had just set for itself with putenv() — so the
    // whole demo suite ran against whatever data directory happened to be
    // configured on the machine, and found real worlds in it.
    $c['local_base'] = (string)(getenv('XERIC_LOCAL_BASE') ?: '') ?: (string)($c['local_base'] ?? '');
    if ($c['local_base'] === '') $c['local_base'] = xeric_web_probe_local();

    $c['data_dir'] = (string)(getenv('XERIC_DATA_DIR') ?: '') ?: (string)($c['data_dir'] ?? '');
    // NOT THE TEMP DIRECTORY. Sessions, the queue, the worker log AND worlds all
    // live under data_dir, and only the launcher was setting it — so anybody
    // running `php -S` by hand, and every Windows user until there is a launcher
    // for them, kept their xerics in /tmp. Most distributions clear that on
    // reboot and systemd-tmpfiles sweeps it mid-week. Silent loss, reported to
    // the visitor as "your session expired".
    if ($c['data_dir'] === '') $c['data_dir'] = xeric_web_home_dir();

    $c['worlds_dir'] = (string)(getenv('XERIC_WORLDS_DIR') ?: '') ?: (string)($c['worlds_dir'] ?? '');
    if ($c['worlds_dir'] === '') $c['worlds_dir'] = xeric_web_worlds_default($c['data_dir']);

    // The same two, for a launcher that has no file to write.
    $c['php'] = (string)(getenv('XERIC_PHP') ?: '') ?: (string)($c['php'] ?? '');
    $env = getenv('XERIC_LOCAL_EDIT');
    if ($env !== false && trim((string)$env) !== '') {
        $c['local_editable'] = in_array(strtolower(trim((string)$env)), ['1', 'true', 'yes', 'on'], true);
    }

    // THE DEFAULTS LIVE HERE, and only here. This line is why the cast default
    // has been four since 2026-08-02 despite a commit that afternoon changing it
    // to twelve: that commit moved the number in worker.php and forge-cli.php
    // and did not touch this file, so `$cfg['cast']` was ALWAYS set by the time
    // a caller looked and every `?? 12` behind it was dead code. Four people is
    // a writers' room, not a town — every sweep drew from the same three
    // pairings and a world ran out of strangers inside a week.
    //
    // Same shape as the worlds-dir bug: a value defaulted in two places, with
    // the two agreeing right up until somebody changed one of them. A caller
    // that wants a different number should pass it, not re-default it.
    $c['places'] = (int)($c['places'] ?? 6);
    $c['cast']   = (int)($c['cast'] ?? 12);
    return $c;
}

/**
 * Where this install keeps things, when nobody has said.
 *
 * A per-user application directory on each platform, because that is the one
 * place a program may write without asking and expect to find it again. The
 * temp directory is the last resort, not the default.
 */
/**
 * Where xerics live, given where the data lives. ONE ANSWER, TWO CALLERS.
 *
 * This used to be decided twice and the two did not agree. bootstrap.php set
 * `XERIC_WORLDS_DIR` to `<data>/worlds` unconditionally and the launchers `eval`
 * that, while the config above preferred the repository's OWN `worlds/` whenever
 * one existed and was writable — so the answer depended on how you started the
 * app, and the environment variable always won. A machine with a `worlds/` in
 * its checkout kept its xerics there when somebody ran `php -S` by hand, and the
 * launcher then showed an empty shelf. Nothing was lost and everything looked
 * lost, which is the worst version of this.
 *
 * The repository branch is gone rather than shared, for three reasons. It never
 * fired on a fresh clone at all — `worlds/` is not tracked (see .gitignore), so
 * whether it applied to you came down to whether a directory happened to exist.
 * `forge/web/deploy.sh` writes `worlds_dir => $DATA/worlds` when it configures a
 * host, so that was already the deliberate answer wherever anybody had stated
 * one. And a world inside the install directory is a world that a reinstall, an
 * update or a stray `git clean` takes with it — the same argument the data_dir
 * comment above makes about the temp directory.
 *
 * An explicit `XERIC_WORLDS_DIR` or a `worlds_dir` in config.local.php still
 * beats this; it is the default, not a rule.
 */
function xeric_web_worlds_default(string $dataDir): string
{
    return rtrim(str_replace('\\', '/', $dataDir), '/') . '/worlds';
}

function xeric_web_home_dir(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $base = (string)(getenv('LOCALAPPDATA') ?: getenv('APPDATA') ?: '');
        if ($base !== '') return rtrim(str_replace('\\', '/', $base), '/') . '/Xeric';
    }
    $home = (string)(getenv('HOME') ?: '');
    if ($home !== '' && is_dir($home)) return rtrim($home, '/') . '/.xeric';

    return sys_get_temp_dir() . '/xeric-forge';
}

/**
 * Which loopback port has a model behind it.
 *
 * A connect probe, not an HTTP call: a closed port fails in microseconds, and
 * being wrong here costs a user two minutes of watching nothing happen.
 */
function xeric_web_probe_local(): string
{
    // THE PORTS PEOPLE ACTUALLY RUN, in the order they are likely to be the one
    // somebody meant. This once knew only about llama.cpp's default and one ssh
    // tunnel, so an install with Ollama or LM Studio running reported "no model
    // found" while a model sat there answering — the worst possible first run:
    // correct-looking, and wrong.
    //
    // A short list on purpose. Every entry costs 0.4s of a page load when
    // nothing is listening, and a scan of everything above 1024 to find a model
    // is a port scan of somebody's own machine on every cold start.
    // THE LIST LIVES IN ONE PLACE (xeric_model_ports, play-lib.php) so the scan
    // and this cannot drift; the copy below is the fallback for anything loading
    // boot.php on its own.
    $ports = function_exists('xeric_model_ports')
        ? array_keys(xeric_model_ports())
        : [11434, 8080, 1234, 18080, 5000, 8000, 4891];

    // THE BIGGEST ONE THAT WRITES, not the lowest port that answers. Port order
    // is an accident of what each project chose as a default, and using it as a
    // ranking put stable-diffusion.cpp on 8081 ahead of a 26B on 8080 the moment
    // the 26B was restarting. Where the scan is loaded, it decides.
    if (function_exists('xeric_model_best')) {
        $best = xeric_model_best();
        if ($best !== '') return $best;
    }

    foreach ($ports as $port) {
        $fp = @fsockopen('127.0.0.1', (int)$port, $errno, $errstr, 0.25);
        if (!$fp) continue;
        fclose($fp);
        return 'http://127.0.0.1:' . $port;
    }
    // Nothing answered. llama.cpp's default is the least surprising thing to
    // show in a field somebody is about to correct.
    return 'http://127.0.0.1:8080';
}

/** mkdir -p, and say so honestly when it cannot. */
function xeric_web_dir(string $path): string
{
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("the forge cannot create $path");
    }
    return $path;
}

function xeric_web_worlds_dir(): string   { return xeric_web_dir((string)xeric_web_config()['worlds_dir']); }
function xeric_web_sessions_dir(): string { return xeric_web_dir((string)xeric_web_config()['data_dir'] . '/sessions'); }
function xeric_web_jobs_dir(): string     { return xeric_web_dir((string)xeric_web_config()['data_dir'] . '/jobs'); }

/**
 * A php CLI binary to run the worker with.
 *
 * PHP_BINARY under mod_php is /usr/sbin/apache2, which would be an entertaining
 * thing to exec, so it is only trusted when we are already CLI.
 */
function xeric_web_php_bin(): string
{
    $c = (string)(xeric_web_config()['php'] ?? '');
    if ($c !== '' && is_executable($c)) return $c;

    // cli-server IS cli. Under `php -S` the SAPI is 'cli-server', not 'cli', so
    // PHP_BINARY — which is correct and right there — was skipped and the search
    // fell through to a list of Unix paths. That is every local install: on
    // Windows nothing matched at all, and macOS dropped /usr/bin/php in 12. Only
    // the launcher's XERIC_PHP was holding it up.
    if (in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true) && is_executable(PHP_BINARY)) {
        return PHP_BINARY;
    }

    // Then the PATH, which is where a php somebody installed actually is.
    foreach (['php', 'php8.4', 'php8.3', 'php8.2'] as $name) {
        $p = xeric_web_which($name);
        if ($p !== '') return $p;
    }

    foreach (['/usr/bin/php', '/usr/bin/php8.3', '/usr/bin/php8.2', '/usr/local/bin/php',
              '/opt/homebrew/bin/php'] as $p) {
        if (is_executable($p)) return $p;
    }
    throw new RuntimeException('the forge cannot find a php binary to run the build with');
}

// ---------------------------------------------------------------------------
// Jobs — a build that outlives the request that asked for it
// ---------------------------------------------------------------------------
//
// A world takes two to three minutes. CDNs and most reverse proxies cut a
// streaming response long before that — measured at ~120s behind one common
// CDN, no matter how often it is flushed. So the build does NOT live in an HTTP
// request: build.php starts a detached worker, the worker appends one JSON line
// per note to a job file, and progress.php tails that file over SSE in short,
// resumable stretches. A dropped connection then costs a reconnect instead of
// the whole world.

/** A new job id. Unguessable, and the only handle on a running build. */
function xeric_web_job_new(): string { return bin2hex(random_bytes(12)); }

function xeric_web_job_ok(string $id): bool { return (bool)preg_match('/^[a-f0-9]{24}$/', $id); }

function xeric_web_job_path(string $id): string { return xeric_web_jobs_dir() . '/' . $id . '.jsonl'; }

/**
 * What a note is allowed to say about a call that failed.
 *
 * llm.php puts up to 300 bytes of whatever answered into its exception, which is
 * exactly right in a terminal and wrong here: a note goes to the BROWSER, and on
 * a bring-your-own-endpoint build the browser is the one who chose what answers.
 * Left alone that turns the progress list into a way to read anything this
 * server can reach. Which pass failed, which host, and that it failed are all
 * true and safe to say; the body of the answer is neither.
 */
function xeric_web_note_safe(string $n): string
{
    return preg_replace(
        ['#llm: HTTP (\d{3}).*$#s', '#llm: non-JSON response:.*$#s', '#llm: cannot reach ([^\s(]+).*$#s'],
        ['the endpoint answered HTTP $1, and nothing usable came back with it',
         'the endpoint answered with something that was not JSON',
         'the endpoint at $1 could not be reached'],
        $n) ?? $n;
}

/** Append one record. One line, one flush — this file is read while it is written. */
function xeric_web_job_append(string $id, array $rec): void
{
    // `html` too: the done record's rendered result is built from notes that
    // may carry an endpoint's raw reply, and this is the last place every
    // record passes through before it is written where a browser can read it.
    foreach (['text', 'message', 'html'] as $k) {
        if (isset($rec[$k]) && is_string($rec[$k])) $rec[$k] = xeric_web_note_safe($rec[$k]);
    }
    if (isset($rec['notes']) && is_array($rec['notes'])) {
        $rec['notes'] = array_map(fn($n) => is_string($n) ? xeric_web_note_safe($n) : $n, $rec['notes']);
    }
    $rec['at'] = microtime(true);
    @file_put_contents(xeric_web_job_path($id),
        json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX);
}

/** Records from $from onward, and whether the job has finished. */
function xeric_web_job_read(string $id, int $from = 0): array
{
    $raw = @file(xeric_web_job_path($id), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($raw === false) return ['records' => [], 'next' => $from, 'done' => false, 'count' => 0];
    $out = [];
    $done = false;
    foreach ($raw as $i => $line) {
        $d = json_decode($line, true);
        if (!is_array($d)) continue;
        if (in_array((string)($d['k'] ?? ''), ['done', 'error'], true)) $done = true;
        if ($i >= $from) $out[] = ['i' => $i, 'rec' => $d];
    }
    return ['records' => $out, 'next' => count($raw), 'done' => $done, 'count' => count($raw)];
}

/** Old jobs are noise. Swept on the way past, never on a schedule. */
function xeric_web_job_sweep(): void
{
    if (random_int(1, 10) !== 1) return;
    $cut = time() - 7200;
    foreach (glob(xeric_web_jobs_dir() . '/*.jsonl') ?: [] as $f) {
        if (@filemtime($f) < $cut) @unlink($f);
    }
}

/**
 * Launch a worker and walk away.
 *
 * `sh -c '… &'` so the process we wait on is the shell, which exits at once:
 * proc_close() on a still-running worker would block this request for the whole
 * job. setsid detaches it from anything Apache does to its children later — a
 * graceful restart must not take somebody's world with it.
 *
 * `exec 3<&0; … <&3` is not decoration. POSIX says a shell with job control off
 * gives an asynchronous command /dev/null for stdin unless stdin is redirected
 * EXPLICITLY, so a plain `cmd &` gets the payload's pipe swapped out from under
 * it and the worker reads nothing. Duplicating the descriptor first and naming
 * it in the redirect is what keeps the pipe — and the pipe is the whole point,
 * because it is how the user's API key reaches the build without ever being a
 * command-line argument, a file, or an environment variable.
 *
 * @param string $script which worker (worker.php builds a world, tick-worker.php
 *                       moves one forward). Always a path this app owns.
 */
function xeric_web_spawn(string $job, array $payload, string $script = 'worker.php'): void
{
    $php    = xeric_web_php_bin();
    $worker = __DIR__ . '/' . basename($script);
    if (!is_file($worker)) throw new RuntimeException("no such worker: " . basename($script));

    $log  = (string)xeric_web_config()['data_dir'] . '/worker.log';
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $spec  = xeric_web_spawn_cmd($php, $worker, $job, $log);
    $proc  = @proc_open($spec['cmd'], [0 => ['pipe', 'r']], $pipes, null, null, $spec['opts']);
    if (!is_resource($proc)) throw new RuntimeException('cannot start a build process');

    fwrite($pipes[0], $body);
    fclose($pipes[0]);

    // On POSIX the thing we close is the shell, which has already exited. On
    // Windows it is the worker itself, and proc_close() WAITS — which would hold
    // this request open for the whole build, the exact failure the shell trick
    // exists to avoid. There, let it go without waiting.
    if ($spec['wait']) proc_close($proc);
    else               @proc_get_status($proc);
}

/**
 * How to start a detached worker on this operating system.
 *
 * Split out from the spawn so the one genuinely unportable thing in the app can
 * be READ, and tested, without starting a process.
 *
 * WINDOWS. No /bin/sh and no setsid, so neither trick is available — but neither
 * is needed. A proc_open child on Windows is not killed when its parent exits,
 * and `bypass_shell` runs the binary directly rather than through cmd.exe, which
 * is what stops a console window appearing and what makes the argv array safe
 * without quoting. `create_new_console` gives it its own console so it does not
 * die with the one Apache or `php -S` is holding. Output goes to the log by
 * descriptor rather than by a `>>` the shell would have expanded.
 *
 * WHY STDIN, ON EVERY PLATFORM. The payload carries the user's API key. It is
 * not a command-line argument (visible in `ps` and Task Manager to every account
 * on the box), not a file (it would be on disk, and the whole promise is that it
 * is not), and not an environment variable (inherited, and readable from
 * /proc on Linux). A pipe that dies with the process is the only one of the four
 * that keeps the promise, so every branch below keeps stdin a pipe.
 *
 * $family is injectable for one reason: the Windows branch is unreachable on
 * every machine this suite runs on, and a branch that cannot be executed is a
 * branch that stays wrong. The default is the truth; a test passes the other.
 *
 * @return array{cmd:array|string,opts:array,wait:bool}
 */
function xeric_web_spawn_cmd(string $php, string $worker, string $job, string $log,
                             ?string $family = null): array
{
    if (($family ?? PHP_OS_FAMILY) === 'Windows') {
        return [
            'cmd'  => [$php, $worker, $job],
            'opts' => ['bypass_shell' => true, 'create_new_console' => true,
                       'create_process_group' => true],
            'wait' => false,
        ];
    }

    // POSIX. `sh -c '… &'` so the process we wait on is the shell, which exits
    // at once: proc_close() on a still-running worker would block this request
    // for the whole job.
    //
    // setsid detaches it from anything the web server does to its children later
    // — a graceful restart must not take somebody's world with it. Looked up on
    // PATH rather than at /usr/bin/setsid, because macOS does not ship it at all
    // and several Linuxes put it in /bin; a missing setsid is survivable (the
    // trailing `&` plus the shell exiting still orphans the worker to init) and
    // is not worth refusing a build over.
    $inner = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($job);
    $setsid = xeric_web_which('setsid', $family);
    if ($setsid !== '') $inner = escapeshellarg($setsid) . ' ' . $inner;

    // `exec 3<&0; … <&3` is not decoration. POSIX says a shell with job control
    // off gives an asynchronous command /dev/null for stdin unless stdin is
    // redirected EXPLICITLY, so a plain `cmd &` gets the payload's pipe swapped
    // out from under it and the worker reads nothing. Duplicating the descriptor
    // first and naming it in the redirect is what keeps the pipe.
    $cmd = 'exec 3<&0; ' . $inner . ' <&3 >> ' . escapeshellarg($log) . ' 2>&1 &';

    return ['cmd' => ['/bin/sh', '-c', $cmd], 'opts' => [], 'wait' => true];
}

/** A program on PATH, or ''. No shell, so nothing here can be injected into. */
function xeric_web_which(string $name, ?string $family = null): string
{
    $fam = $family ?? PHP_OS_FAMILY;

    // WINDOWS HAS A PATH TOO. This returned '' for every lookup on Windows,
    // which was right for `setsid` and wrong for everything else — it is also
    // how the php binary above could not be found. Its separator is ';', its
    // entries contain 'C:', and its executables end in .exe or .cmd.
    if ($fam === 'Windows') {
        $exts = array_filter(array_map('trim', explode(';', (string)(getenv('PATHEXT') ?: '.EXE;.BAT;.CMD'))));
        foreach (explode(';', (string)(getenv('PATH') ?: '')) as $dir) {
            $dir = rtrim(trim($dir), '\\/');
            if ($dir === '') continue;
            foreach (array_merge([''], $exts) as $ext) {
                $p = $dir . DIRECTORY_SEPARATOR . $name . strtolower($ext);
                if (@is_file($p)) return $p;
            }
        }
        return '';
    }

    foreach (explode(':', (string)(getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin')) as $dir) {
        $dir = rtrim(trim($dir), '/');
        if ($dir === '') continue;
        $p = $dir . '/' . $name;
        if (@is_executable($p)) return $p;
    }
    return '';
}

/**
 * Wait for a detached worker to say it is alive, and hand back its first word.
 *
 * This is what turns a detached job back into an honest synchronous answer: if
 * it lost the lock race or died on the way up, the caller is told NOW instead of
 * being sent to a progress screen that was doomed before it opened.
 *
 * @return array{k:string,rec:array}|null  the 'hello' or 'error' record, or null
 *         when the worker is simply slow to speak (the job file exists, so it is
 *         demonstrably alive and the caller should hand over the job id).
 */
function xeric_web_job_await(string $job, float $seconds = 8.0): ?array
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $j = xeric_web_job_read($job);
        foreach ($j['records'] as $r) {
            $k = (string)($r['rec']['k'] ?? '');
            if ($k === 'error' || $k === 'hello') return ['k' => $k, 'rec' => (array)$r['rec']];
        }
        usleep(150000);
    }
    return null;
}

// ---------------------------------------------------------------------------
// The endpoint
// ---------------------------------------------------------------------------

/**
 * The addresses a visitor may not aim this server at.
 *
 * Loopback and the RFC1918 network are the obvious ones; 169.254.169.254 is the
 * one that matters most, because on a cloud host it hands out credentials to
 * anything that asks from the right place. The rest are the ranges that carry a
 * v4 address inside a v6 one (v4-mapped, 6to4, Teredo, NAT64), which is how the
 * same private space is reached under a different spelling.
 */
const XERIC_WEB_CLOSED_NETS = [
    '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
    '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
    '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64',
    '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
];

/** Is this address inside that CIDR? Both families, no extensions required. */
function xeric_web_ip_in(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, '128');
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;

    $bits  = max(0, min(strlen($a) * 8, (int)$bits));
    $whole = intdiv($bits, 8);
    $rest  = $bits % 8;
    if ($whole > 0 && substr($a, 0, $whole) !== substr($b, 0, $whole)) return false;
    if ($rest === 0) return true;
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return ($a[$whole] & $mask) === ($b[$whole] & $mask);
}

/**
 * Every address this name answers with, or nothing at all.
 *
 * Both families are asked for, because a host with a public A record and a
 * loopback AAAA is one line of zone file and would otherwise walk straight past
 * a v4-only check.
 */
function xeric_web_resolve(string $host): array
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) return [$host];

    $out = [];
    foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
        foreach (@dns_get_record($host, $type) ?: [] as $r) {
            $ip = (string)($r[$field] ?? '');
            if ($ip !== '') $out[] = $ip;
        }
    }
    if ($out === []) {
        foreach (@gethostbynamel($host) ?: [] as $ip) $out[] = $ip;
    }
    return array_values(array_unique($out));
}

/**
 * May this server be pointed at that host?
 *
 * A bring-your-own-key base URL is fetched BY THIS SERVER, and this server is a
 * shared box that also answers for other people's sites. Unchecked, the field is
 * an arbitrary GET and POST from inside the perimeter — every neighbour vhost,
 * every service on loopback, and the metadata address — with the answer read
 * back out through the progress stream.
 *
 * FAILS CLOSED. A name that will not resolve is refused, because "we could not
 * check" and "it is fine" are not the same sentence, and because a name that
 * resolves to nothing now can resolve to 127.0.0.1 by the time llm.php calls it.
 */
function xeric_web_host_open(string $host): bool
{
    $addrs = xeric_web_resolve($host);
    if ($addrs === []) return false;
    foreach ($addrs as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
        foreach (XERIC_WEB_CLOSED_NETS as $cidr) {
            if (xeric_web_ip_in($ip, $cidr)) return false;
        }
    }
    return true;
}

/**
 * A model descriptor from what the welcome screen collected.
 *
 * The key is passed through and never stored. `kind` is clamped to the three
 * llm.php understands, and a BYO base has to be an http(s) URL naming a host
 * that is somewhere else — this endpoint is fetched by the server, so a
 * user-supplied `file://` or a URL resolving to the machine's own back yard is
 * a hole, not a preference.
 */
function xeric_web_endpoint(array $model): array
{
    $kind = strtolower(trim((string)($model['kind'] ?? 'local')));

    // `none` is a real state and the one a fresh install starts in: no machine
    // is attached, nothing can be asked, and every caller has to find that out
    // HERE rather than by watching a request to an empty URL time out.
    if ($kind === 'none') {
        throw new RuntimeException('No machine is attached to this yet, pick one first.');
    }
    if (!in_array($kind, ['local', 'openai', 'anthropic'], true)) $kind = 'local';

    if ($kind === 'local') {
        // A local address the visitor set themselves beats the one this host was
        // deployed with. It can only have been stored by somebody the server
        // belongs to (xeric_web_local_editable), which is what makes it safe to
        // skip the private-network check the branch below applies — pointing at
        // 127.0.0.1 or a box on your own LAN is the entire purpose of it.
        $own = trim((string)($model['local'] ?? ''));
        $base = ($own !== '' && preg_match('#^https?://#i', $own))
            ? rtrim($own, '/')
            : (string)xeric_web_config()['local_base'];

        return ['kind' => 'local', 'base' => $base,
                'model' => (string)($model['model'] ?? ''), 'key' => ''];
    }

    $base = trim((string)($model['base'] ?? ''));
    if ($base === '' && $kind === 'anthropic') $base = 'https://api.anthropic.com';
    if ($base === '' && $kind === 'openai')    $base = 'https://api.openai.com/v1';
    if (!preg_match('#^https?://#i', $base)) throw new RuntimeException('that endpoint URL does not look like a URL');

    $host = (string)(parse_url($base, PHP_URL_HOST) ?? '');
    if ($host === '') throw new RuntimeException('that endpoint URL does not look like a URL');
    if (!xeric_web_host_open($host)) {
        throw new RuntimeException('That endpoint is on this machine\'s own network, or its name does not '
            . 'resolve to anywhere the forge is allowed to call. Point it at a public API host.');
    }

    return ['kind' => $kind, 'base' => rtrim($base, '/'),
            'model' => trim((string)($model['model'] ?? '')), 'key' => (string)($model['key'] ?? '')];
}

/** How to name an endpoint out loud. Never includes the key. */
function xeric_web_endpoint_label(array $e): string
{
    if (($e['kind'] ?? '') === 'local') {
        $port = (string)(parse_url((string)$e['base'], PHP_URL_PORT) ?? '');
        return 'the local model' . ($port !== '' ? " (127.0.0.1:$port)" : '');
    }
    $host = (string)(parse_url((string)$e['base'], PHP_URL_HOST) ?? $e['base']);
    return ($e['model'] !== '' ? $e['model'] . ' at ' : 'your endpoint at ') . $host;
}

// ---------------------------------------------------------------------------
// Session — answers across screens, and never a key
// ---------------------------------------------------------------------------

/**
 * The session id from the cookie, minted if there isn't one yet.
 *
 * Minting only. SENDING the cookie is session.php's, in one place, because it
 * re-sends it on every visit to keep the seven days idle days — doing it here as
 * well would put two identical Set-Cookie headers on every new visitor's first
 * response, which is the kind of thing that is never wrong and always confusing.
 */
/**
 * Is this one person's machine, or a host with visitors on it?
 *
 * SOLO IS THE DEFAULT NOW, and it should have been from the start. A cookie is
 * how a SERVER tells strangers apart; on somebody's own computer it is a way to
 * lose everything they have by clearing their browser — which is a thing people
 * do for unrelated reasons, and which took this owner's xerics off their own
 * shelf twice in one afternoon. The machine is the identity. The browser is
 * furniture.
 *
 * A host turns it off with `solo => false` in config.local.php, or XERIC_SOLO=0,
 * and gets exactly the cookie behaviour it had before.
 *
 * ── AND A DEFAULT MUST NOT BE A DOOR ──────────────────────────────────────
 *
 * "Default on unless a host says otherwise" is only safe if every host says
 * otherwise, and one did not: deploy.sh wrote a config.local.php with no `solo`
 * key at all, so the public demo ran the laptop's rule. Solo means the machine
 * identity IS the visitor, so that collapsed every stranger on the demo into one
 * person — each of them owner of all the others' worlds, free to read an
 * unredacted template, edit it, or delete it. Nobody had to attack anything;
 * they just had to open the page.
 *
 * So the default is now conditional on the thing that makes it TRUE rather than
 * on nobody having said no. Solo is a claim about there being one person here,
 * and the only evidence for that available to a request is that it came from
 * this machine. A request from somewhere else is a visitor, whatever the config
 * forgot to say. An EXPLICIT `solo => true` still wins — a host that means it
 * can still say so — but silence no longer reads as yes to a stranger.
 */
function xeric_web_solo(): bool
{
    static $solo = null;
    if ($solo === null) {
        $env = getenv('XERIC_SOLO');
        if ($env !== false && trim((string)$env) !== '') {
            $solo = in_array(strtolower(trim((string)$env)), ['1', 'true', 'yes', 'on'], true);
            return $solo;
        }
        $c = xeric_web_config();
        if (array_key_exists('solo', $c)) return $solo = !empty($c['solo']);

        // Nobody said. CLI has no peer and is the launcher's own tooling, which
        // is single-user by definition; a request with a peer is solo only when
        // that peer is this machine.
        $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $solo = $peer === '' || in_array($peer, ['127.0.0.1', '::1'], true);
    }
    return $solo;
}

/**
 * Who this install is, kept in one file beside its xerics.
 *
 * IT ADOPTS RATHER THAN STARTS FRESH. Turning this on for somebody who already
 * has xerics must not orphan them, so the first run looks for the session that
 * owns the most and takes its id. That is the identity they have been using; it
 * simply stops depending on a cookie to be found again.
 *
 * AND THE FIRST RUN IS WRITTEN ONCE, BY WHOEVER GETS THERE FIRST. In solo mode
 * this id is the visitor's whole ownership identity, so two of them is not a
 * cosmetic problem: it is two owners of one machine, and whichever loses the
 * race finds the worlds it made belong to somebody else. A browser opening a
 * page that fires two requests at a cold install is all it takes, which is why
 * the claim is an exclusive create and not a write — and why losing it means
 * ADOPTING the winner's id, never overwriting it with your own.
 */
function xeric_web_machine_id(): string
{
    static $id = null;
    if ($id !== null) return $id;

    $path = (string)xeric_web_config()['data_dir'] . '/identity';
    $have = trim((string)@file_get_contents($path));
    if (preg_match('/^[a-f0-9]{32}$/', $have)) return $id = $have;

    // Adopt the busiest existing session, if there is one.
    $best = '';
    $most = 0;
    foreach (glob(xeric_web_sessions_dir() . '/*.json') ?: [] as $f) {
        $sid = basename($f, '.json');
        if (!preg_match('/^[a-f0-9]{32}$/', $sid)) continue;
        $rec = json_decode((string)@file_get_contents($f), true);
        $n = is_array($rec) ? count((array)($rec['own'] ?? [])) : 0;
        if ($n > $most) { $most = $n; $best = $sid; }
    }

    $mine = $best !== '' ? $best : bin2hex(random_bytes(16));

    // ONE PATH, AND THE DECISION IS MADE UNDER THE LOCK. 'c' creates the file
    // if it is not there and never truncates it, so opening is not yet a claim;
    // the read INSIDE the lock is what decides. A process that lost the race
    // sees the winner's bytes and adopts them, and one that finds the file
    // empty or truncated — a crash mid-write, a disk that filled — repairs it,
    // which the check-then-act version did by accident and an exclusive create
    // would have stopped doing.
    // 'c+' and not 'c': 'c' is write-ONLY, and the read below is the whole
    // point of holding the lock.
    $fh = @fopen($path, 'c+b');
    // Unwritable data_dir. Use ours for this process rather than failing the
    // request, and cache it: an id that changes between two calls inside one
    // request is worse than one that does not outlive the request. This is the
    // only path left where two machines can disagree, and the unconditional
    // write always had it too.
    if ($fh === false) return $id = $mine;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return $id = $mine; }

    $have = trim((string)stream_get_contents($fh));
    if (!preg_match('/^[a-f0-9]{32}$/', $have)) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $mine);
        fflush($fh);
        $have = $mine;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0600);
    return $id = $have;
}

function xeric_web_sid(): string
{
    // ONE IDENTITY PER INSTALL, unless a host has asked for visitors.
    if (xeric_web_solo()) return xeric_web_machine_id();

    $sid = (string)($_COOKIE[XERIC_WEB_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) {
        $sid = bin2hex(random_bytes(16));
        $_COOKIE[XERIC_WEB_COOKIE] = $sid;
        xeric_web_sid_fresh($sid, true);
    }
    return $sid;
}

/**
 * Was this id made up here, or did the visitor arrive holding it?
 *
 * The difference is the whole defence against a flood of cookieless GETs: an id
 * nobody has ever come back with is a guess about a visitor, not a visitor, and
 * session.php will not spend a record on one — limits.php evicts oldest-seen
 * first, so a directory full of phantoms is a directory that deletes real
 * people's worlds. Kept here rather than read back off $_COOKIE because the line
 * above rewrites $_COOKIE, and after that nothing can tell the two apart.
 */
function xeric_web_sid_fresh(string $sid, bool $mark = false): bool
{
    static $fresh = [];
    if ($mark) $fresh[$sid] = true;
    return isset($fresh[$sid]);
}

function xeric_web_session_path(?string $sid = null): string
{
    // WHOSE SESSION, ASKED THE WAY EVERYTHING ELSE ASKS IT. This defaulted to
    // xeric_web_sid(), which reads the COOKIE — so a detached worker, which has
    // no cookie, minted a fresh id and wrote there. The world it built was still
    // claimed for the right visitor, because that path passes the sid
    // explicitly; everything that did not pass one went to a phantom.
    //
    // The visible symptom was the meter: a build spends a dozen model calls and
    // the counter never moved, because every one of them was banked into a
    // session nobody would ever load. Same for a skip, a reroll, and the heart
    // living an hour while nobody is watching.
    //
    // xeric_session_id() honours xeric_session_use(), which is exactly how a
    // worker says whose work it is doing. Guarded because session.php is
    // required at the FOOT of this file, and anything reaching for a session
    // before then can only have meant the cookie.
    if ($sid === null) {
        $sid = function_exists('xeric_session_id') ? xeric_session_id() : xeric_web_sid();
    }
    return xeric_web_sessions_dir() . '/' . $sid . '.json';
}

/** Read the session. Missing, unreadable or corrupt all mean "a fresh one". */
function xeric_web_session_read(?string $sid = null): array
{
    $raw = @file_get_contents(xeric_web_session_path($sid));
    $s = $raw === false ? null : json_decode($raw, true);
    if (!is_array($s)) $s = [];
    $s['answers'] = (array)($s['answers'] ?? []);
    $s['model']   = (array)($s['model'] ?? []);
    return $s;
}

/**
 * Read-modify-write one session record under its own lock.
 *
 * The same shape as xeric_queue_edit(), for the same reason: a session is read
 * by one request and written by another, and the pair is not atomic unless
 * something holds a lock across both halves. Unlocked, a worker claiming a world
 * loses that claim to any ordinary page load that happened to read the record
 * first and write it back a millisecond later — the visitor is then told the
 * world it just built is not theirs.
 *
 * The callback takes the record BY REFERENCE and mutates it; whatever it returns
 * is handed back to the caller. The `key` scrub is not defence in depth, it is
 * the defence: every path that persists anything goes through here, so a key can
 * only reach disk by someone deleting that line.
 */
function xeric_web_session_edit(callable $fn, ?string $sid = null)
{
    $path = xeric_web_session_path($sid);
    $fh = @fopen($path, 'c+');
    if (!$fh) {                                    // unwritable: never fail closed on the visitor
        $s = xeric_web_session_read($sid);
        return $fn($s);
    }

    @flock($fh, LOCK_EX);
    $s = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($s)) $s = [];
    $s['answers'] = (array)($s['answers'] ?? []);
    $s['model']   = (array)($s['model'] ?? []);

    $ret = $fn($s);

    unset($s['model']['key']);
    $s['at'] = time();
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);

    xeric_web_session_sweep();
    return $ret;
}

/**
 * Write the session whole.
 *
 * Replacing the record outright is still last-writer-wins, and deliberately so —
 * a caller handing over a complete record means that record. Anything that reads
 * a field, changes it and puts it back wants xeric_web_session_edit() instead.
 */
function xeric_web_session_write(array $s, ?string $sid = null): void
{
    xeric_web_session_edit(function (array &$cur) use ($s): void { $cur = $s; }, $sid);
}

/**
 * Drop sessions nobody came back for. Cheap, occasional, never fatal.
 *
 * The work is session.php's, because expiring a session means letting go of its
 * forked databases and the worlds it forged as well as its record — deleting the
 * JSON alone would leave the expensive half on the disk forever.
 */
function xeric_web_session_sweep(): void
{
    if (random_int(1, 20) !== 1) return;
    xeric_web_log_trim();
    xeric_session_sweep();
}

/**
 * Keep the worker log inside the disk budget it is counted in.
 *
 * Every unexpected failure and every worker's stderr lands in one file that
 * nothing ever truncated — so the one thing on this host that grows without a
 * ceiling was also the one thing xeric_limit_disk() did not weigh, and the
 * budget went on reporting room right up until a write failed. The tail is kept
 * because the tail is the part anybody debugging actually reads.
 */
function xeric_web_log_trim(int $keep = 262144): void
{
    $p = (string)xeric_web_config()['data_dir'] . '/worker.log';
    if ((int)@filesize($p) <= $keep * 2) return;

    $fh = @fopen($p, 'c+');
    if (!$fh) return;
    @flock($fh, LOCK_EX);
    $size = (int)@filesize($p);
    if ($size > $keep * 2) {
        fseek($fh, $size - $keep);
        $tail = (string)stream_get_contents($fh);
        $cut = strpos($tail, "\n");                 // never start the file mid-line
        if ($cut !== false) $tail = substr($tail, $cut + 1);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $tail);
        fflush($fh);
    }
    @flock($fh, LOCK_UN);
    fclose($fh);
}

// ---------------------------------------------------------------------------
// The demo layer
// ---------------------------------------------------------------------------
//
// Identity, the caps, and the one model slot. Every page that can spend the
// machine's time needs all three, so they are required here rather than six
// times over. They require boot.php in turn, which is a no-op by the time this
// line runs — PHP registers a file as included before it finishes executing it,
// and all three contain nothing but definitions.
//
// NOTHING BELOW MAY GO IN engine/. A private install on somebody's own machine
// has no sessions, no rate limits and no queue: one person, one GPU, no line.
// This is the layer that exists only because strangers are sharing a demo.

/**
 * THE METER. Every completion this process makes, added to this visitor's
 * running total as it lands.
 *
 * Registered once, here, because every page and every detached worker comes
 * through boot.php and nothing else has to know it exists — the engine reports
 * tokens to whoever registered a sink (xeric_llm_meter) and holds no state of
 * its own. Workers count too: they run under a forced identity, so a build or a
 * six-hour skip lands on the same total as a chat turn.
 *
 * Written per call rather than batched at the end of a request. A build is
 * fourteen model calls over three minutes and a skip is several over one; a
 * total that only appeared when the process exited would sit at zero for the
 * whole time anybody was watching it work.
 */
xeric_llm_meter(function (array $u, string $where = ''): void {
    $in  = (int)($u['prompt_tokens'] ?? 0);
    $out = (int)($u['completion_tokens'] ?? 0);
    if ($in === 0 && $out === 0) return;

    $key = xeric_web_meter_key($where);
    $mark = 0;
    try {
        xeric_web_session_edit(function (array &$s) use ($in, $out, $key, &$mark): void {
            // PER MACHINE, and the total is the sum rather than a fourth number
            // kept beside them. Two counters for one fact drift the moment
            // anything is added, removed or renamed, and the one that is wrong
            // is always the one somebody is looking at.
            $by = (array)($s['tokens']['by'] ?? []);
            $row = (array)($by[$key] ?? []);
            $by[$key] = [
                'in'    => (int)($row['in'] ?? 0) + $in,
                'out'   => (int)($row['out'] ?? 0) + $out,
                'calls' => (int)($row['calls'] ?? 0) + 1,
            ];
            $s['tokens'] = ['by' => $by];

            // THE DRAIN, decided inside the lock. The total and the last mark
            // announced are read and written in one place, so two completions
            // landing together cannot both cross the same mark and buzz twice.
            $n = 0;
            foreach ($by as $row) $n += (int)($row['in'] ?? 0) + (int)($row['out'] ?? 0);

            $cfg   = (array)($s['notify'] ?? []);
            $every = (int)($cfg['tokens_every'] ?? 0);
            $said  = (int)($s['tokens']['said'] ?? 0);

            $mark = xeric_notify_mark($n, $every, $said);
            if ($mark > 0) $s['tokens']['said'] = $mark;
        });
    } catch (Throwable $e) {
        // No session to write to, or a locked store. The model still answered.
    }

    // Sent OUTSIDE the lock. A phone that is unreachable takes four seconds to
    // say so, and holding a session lock for four seconds blocks every request
    // this visitor makes — including the one that is waiting for this reply.
    if ($mark > 0) {
        $cfg = xeric_web_notify();
        if (xeric_notify_on($cfg, 'tokens')) {
            xeric_notify_send($cfg, 'Xeric has spent ' . xeric_notify_round($mark) . ' tokens.',
                ['title' => 'Tokens wasted', 'tags' => 'fuelpump', 'priority' => 2]);
        }
    }
});

// THE IMAGE SPEND LANDS ON THE SAME LEDGER. The reaper hands every render to
// this sink (engine/photo.php's meter discipline, llm.php's shape), and the
// count rides the same per-machine rows the tokens do — so the wasted-tokens
// surface shows a render-happy afternoon beside a chatty one, and no
// unattended photo job ever spends quietly. Images are counted as images,
// never disguised as tokens: a picture is not a thousand words on this meter.
require_once $XERIC_LIB . '/engine/photo.php';

// WHERE THE IMAGE MACHINE IS, resolved at call time: the machines screen's
// stored address first (the visitor's own session, which is also the session
// a detached reaper is forced into), the environment as the engine's own
// fallback. The KEY is never stored: a remote image machine takes its key
// from XERIC_IMAGE_KEY only, the same never-in-a-session discipline the model
// machines keep.
xeric_image_source(function () {
    try {
        $img = (array)(xeric_web_session_read()['image'] ?? []);
    } catch (Throwable $e) {
        return null;                                   // no session: fall to env
    }
    $base = trim((string)($img['base'] ?? ''));
    if (($img['off'] ?? false) === true) return false;  // explicitly none, env ignored
    if ($base === '') return null;
    // The provider's dialect and the ENABLED LoRAs ride the endpoint, so the
    // reaper composes with the same style vocabulary the machines screen shows
    // — one source, read at call time, per session.
    $loras = [];
    foreach ((array)($img['loras'] ?? []) as $name => $words) {
        if ($words === false) continue;                 // listed but switched off
        $loras[] = ['name' => (string)$name, 'words' => array_values((array)$words)];
    }
    return ['base' => $base, 'key' => (string)(getenv('XERIC_IMAGE_KEY') ?: ''),
            'kind' => (string)($img['kind'] ?? ''), 'loras' => $loras];
});

xeric_photo_meter(function (array $u, string $where = ''): void {
    $key = xeric_web_meter_key($where);
    try {
        xeric_web_session_edit(function (array &$s) use ($u, $key): void {
            $by  = (array)($s['tokens']['by'] ?? []);
            $row = (array)($by[$key] ?? []);
            $row['images'] = (int)($row['images'] ?? 0) + max(1, (int)($u['images'] ?? 1));
            $row['calls']  = (int)($row['calls'] ?? 0) + 1;
            $by[$key] = $row;
            $s['tokens'] = ['by' => $by] + (array)($s['tokens'] ?? []);
        });
    } catch (Throwable $e) {
        // No session to write to. The picture still exists.
    }
});

/**
 * Where this visitor's notifications go, and what they are for.
 *
 * The session first, then the install. A hosted box may set XERIC_NTFY_URL so
 * its owner gets told about it without anybody having to configure a browser;
 * a visitor who sets their own gets their own, and neither can see the other's.
 */
function xeric_web_notify(?string $sid = null): array
{
    $s = (array)(xeric_web_session_read($sid)['notify'] ?? []);
    $url = trim((string)($s['url'] ?? ''));
    if ($url === '') $url = trim((string)(getenv('XERIC_NTFY_URL') ?: ''));

    // CHECKED AGAIN HERE, not only where it was saved. A name that resolved to
    // a public address on Tuesday can resolve to 127.0.0.1 on Friday — that is
    // what DNS rebinding IS, and the saved-URL check alone would be a fence
    // somebody walks around by editing a zone file. This is the last read
    // before every send, so it is the honest place for the second look.
    //
    // Silently emptied rather than refused: this is called from the heartbeat
    // and from a chat turn, and a notification that cannot be delivered must
    // never be a reason a world stops living. The screen that SETS it says why.
    if ($url !== '' && !xeric_web_host_open((string)parse_url($url, PHP_URL_HOST))) $url = '';

    return [
        'url'          => $url,
        'on'           => array_values(array_map('strval', (array)($s['on'] ?? []))),
        'tokens_every' => max(0, (int)($s['tokens_every'] ?? 0)),
    ];
}

/** One machine, one key: the address without its scheme or trailing slash. */
function xeric_web_meter_key(string $base): string
{
    $b = rtrim(trim($base), '/');
    $b = (string)preg_replace('#^https?://#i', '', $b);
    return $b === '' ? 'unknown' : mb_strtolower($b);
}

/**
 * What this visitor has spent — everywhere, or at one machine.
 *
 * @param string $at an address to narrow to, or '' for the sum of all of them
 */
function xeric_web_tokens(?string $sid = null, string $at = ''): array
{
    $by  = (array)(xeric_web_session_read($sid)['tokens']['by'] ?? []);
    $out = ['in' => 0, 'out' => 0, 'calls' => 0, 'images' => 0];

    $want = $at === '' ? null : xeric_web_meter_key($at);
    foreach ($by as $key => $row) {
        if ($want !== null && (string)$key !== $want) continue;
        $out['in']     += (int)($row['in'] ?? 0);
        $out['out']    += (int)($row['out'] ?? 0);
        $out['calls']  += (int)($row['calls'] ?? 0);
        $out['images'] += (int)($row['images'] ?? 0);
    }
    return $out;
}

/**
 * What has been spent at each machine, as {key: tokens}.
 *
 * THE BREAKDOWN TRAVELS WITH THE TOTAL. The browser keeps the all-time ledger —
 * a session ends when somebody clears a cookie, and the joke is that nothing is
 * forgiven — and it cannot split a single number back into machines. So every
 * meter that means "everything" carries the split with it, and any screen
 * showing that meter teaches the ledger about every machine at once, whether or
 * not it draws a card for them.
 */
function xeric_web_tokens_by(?string $sid = null): array
{
    $by  = (array)(xeric_web_session_read($sid)['tokens']['by'] ?? []);
    $out = [];
    foreach ($by as $key => $row) {
        $n = (int)($row['in'] ?? 0) + (int)($row['out'] ?? 0);
        if ($n > 0) $out[(string)$key] = $n;
    }
    return $out;
}

/** 0 · 940 · 12.4k · 1.31M — a number somebody can read at a glance. */
function xeric_web_tokens_short(int $n): string
{
    if ($n < 1000)      return (string)$n;
    if ($n < 1000000)   return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.') . 'k';
    return rtrim(rtrim(number_format($n / 1000000, 2, '.', ''), '0'), '.') . 'M';
}

/**
 * ONE NAME FOR THIS MACHINE.
 *
 * localhost and 127.0.0.1 are the same computer and DIFFERENT ORIGINS: separate
 * cookie jars, separate localStorage. Reaching the app by the other name makes
 * somebody a brand new visitor — their xerics are gone from the shelf (an
 * unlaunched one is shown to its owner and nobody else) and the token ledger,
 * which lives in the browser precisely so it survives everything else, reads
 * zero. Nothing is lost and it looks exactly like everything is.
 *
 * So the loopback names are collapsed into the one the launcher prints. Only
 * those: an install deliberately reached on a LAN address is somebody using
 * their xeric from the sofa, and moving them to 127.0.0.1 would send them to
 * their phone's own machine.
 *
 * GET only. A redirect is for a person who typed or bookmarked the other name;
 * everything the page fetches afterwards is relative and already correct.
 */
if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $name = (string)(parse_url('http://' . $host, PHP_URL_HOST) ?? '');
    if (in_array($name, ['localhost', '::1', '[::1]', 'ip6-localhost'], true)) {
        $port = (string)(parse_url('http://' . $host, PHP_URL_PORT) ?? '');
        $to   = 'http://127.0.0.1' . ($port !== '' ? ':' . $port : '')
              . (string)($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $to, true, 302);
        exit;
    }
}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/limits.php';
require_once __DIR__ . '/queue.php';

// ---------------------------------------------------------------------------
// Notes → the progress list
// ---------------------------------------------------------------------------

/**
 * Which pass a note belongs to.
 *
 * The engine names the pass at the FRONT of every note it writes — "places: …",
 * "cast person 2 failed …", "seed: …" — so this matches there and nowhere else.
 * Searching the whole string looks more forgiving and is worse: a feed store
 * called Miller's Feed & Seed threw the progress list into the seed pass and
 * back, in front of the user, twenty seconds early.
 *
 * A note that names no pass (llm.php's "model returned unparseable JSON…")
 * belongs to whichever pass is running, which is what $current is for.
 */
function xeric_web_pass_of(string $n, string $current): string
{
    $s = strtolower(ltrim($n));
    $front = [
        'motivation '     => 'systems',
        'system resolver' => 'systems',
        'surprise-me'     => 'prep',
        'concept'         => 'concept',
        'world:'          => 'concept',
        'place'           => 'places',
        'cast'            => 'cast',
        'seed'            => 'seed',
        'written'         => 'seed',
        'repair'          => 'validate',
        'validation'      => 'validate',
        'still invalid'   => 'validate',
    ];
    foreach ($front as $prefix => $pass) {
        if (str_starts_with($s, $prefix)) return $pass;
    }
    return $current;
}

/** Did this note mean something went wrong? The UI colours it and repeats it at the end. */
function xeric_web_note_warn(string $n): bool
{
    foreach (['failed', 'built-in default', 'unparseable', 'retrying', 'repair', 'still invalid',
              'could not', 'topped up', 'no workplace'] as $w) {
        if (stripos($n, $w) !== false) return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * A PHP value, safe to write INSIDE a `<script>` block. h()'s sibling.
 *
 * `h()` is for an HTML text node or an attribute and is wrong here — inside a
 * script element the browser is not parsing HTML entities, so `&lt;` would
 * arrive at the JS engine as five literal characters. The right tool is JSON
 * with the tag characters escaped.
 *
 * ── WHY THE FLAGS, WHEN json_encode ALREADY LOOKED SAFE ───────────────────
 *
 * Every one of these sites used a bare json_encode(), and `</script>` genuinely
 * could not break out of them — because PHP escapes `/` as `\/` by default. One
 * default flag was the only thing holding the line, and `JSON_UNESCAPED_SLASHES`
 * is already the house style two files over in xeric_web_json(). The copy-paste
 * that turns this into arbitrary script execution is one commit away, and it
 * would look like a tidy-up.
 *
 * AND ONE STRING GETS THROUGH TODAY REGARDLESS. `<!--<script>` drives the HTML
 * tokenizer into script-data-double-escaped state, where `</script>` stops
 * closing the element — so a world named `Mill <!--<script>` swallowed the rest
 * of the document into script text. Measured: the play view's entire client
 * side, every listener, the chip bar, the thread and the clock, all dead, WITH
 * NO CONSOLE ERROR — which is why the browser smoke test could not see it
 * either. That one is not XSS, it is a world name that silently bricks the app
 * for whoever opens it.
 *
 * JSON_HEX_TAG closes both, permanently, and does not depend on a default.
 */
function js($v): string
{
    return (string)json_encode($v,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * This request's script nonce. One per response, minted once.
 *
 * THE POINT OF IT is that an injected `<script>` does not carry it, and neither
 * does an `onerror=` attribute — a nonce policy blocks inline event handlers
 * outright, which is what actually stopped the stored-XSS finding this was
 * written after. `img-src` would not have helped: the payload never loaded an
 * image, it used a broken one to get an error handler.
 *
 * Every inline block in this app therefore has to be stamped, and the failure
 * mode when one is missed is a script that silently does not run. That is
 * unpleasant, and it is still the right trade: the alternative is
 * 'unsafe-inline', which is the same as having no script policy at all.
 */
function xeric_web_nonce(): string
{
    static $n = null;
    return $n ??= base64_encode(random_bytes(16));
}

/** JSON out, and stop. */
function xeric_web_json(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** The POST body as an array, whether it arrived as JSON or a form. */
/**
 * Did this POST come from somewhere that is not us?
 *
 * ── WHY THIS EXISTS, AND WHY IT IS NOT A TOKEN ────────────────────────────
 *
 * There was no cross-site protection here at all, and the identity model made
 * the usual fallback useless: `SameSite=Lax` guards a COOKIE, and in solo mode
 * the identity is a file on disk, so a request that carries no cookie at all is
 * still fully authenticated. Every POST in the app was therefore reachable by
 * any page the owner happened to open. Demonstrated, with no cookie: a world
 * deleted; the notification URL rewritten to a stranger's ntfy topic, which
 * redirects every future world name, hour title and reminder to them; an
 * attacker's address stored as the engine, after which every prompt — the whole
 * world, every conversation — leaves the machine; and `power.php a=off`, whose
 * loopback fence PASSES precisely because the request comes from the victim's
 * own browser. It was checking the wrong property.
 *
 * A token would also work and is a bigger change: every form and every fetch in
 * three thousand lines of client JS has to carry it, and the one that forgets is
 * a broken button nobody notices until a user hits it. This is the property that
 * actually distinguishes the attack — the request originated on somebody else's
 * page — read from the two headers browsers send precisely to say so.
 *
 * FAILS OPEN FOR CALLERS WITH NO ORIGIN, deliberately. curl, the launcher's own
 * tooling and the test suites send neither header, and they are not the threat:
 * a drive-by needs a browser, and every browser that can mount one has sent
 * `Sec-Fetch-Site` since 2020. Refusing header-less requests would break every
 * script anybody has written against this app to stop an attack that cannot be
 * mounted without the header.
 */
function xeric_web_cross_site(): bool
{
    $sfs = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($sfs !== '') return $sfs === 'cross-site';

    // No Sec-Fetch-Site. If the caller named an origin, compare it to the host
    // it reached; `null` is an opaque origin (a sandboxed frame, a data: URL)
    // and is never us.
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return false;
    if (strtolower($origin) === 'null') return true;
    return strcasecmp((string)parse_url($origin, PHP_URL_HOST) . ':' . (string)parse_url($origin, PHP_URL_PORT),
                      (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0
        && strcasecmp((string)parse_url($origin, PHP_URL_HOST),
                      (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) !== 0;
}

/** Refuse a state-changing request that started on somebody else's page. */
function xeric_web_csrf_guard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    if (!xeric_web_cross_site()) return;
    xeric_web_json(['error' => 'That request came from another site, so it was not carried out.',
                    'kind'  => 'cross_site'], 403);
}

function xeric_web_input(): array
{
    // EVERY POST THAT READS A BODY PASSES THROUGH HERE, which is why the guard
    // lives at this line rather than in seventeen page headers where the
    // eighteenth page would forget it. The four endpoints that read $_POST
    // directly call xeric_web_csrf_guard() themselves.
    xeric_web_csrf_guard();

    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d)) return $d;
    }
    return $_POST;
}

// ---------------------------------------------------------------------------
// The floor under every page
// ---------------------------------------------------------------------------
//
// Nothing in this app is allowed to answer a person with a blank page, a 500, or
// a stack trace. Every endpoint has its own honest failures — a limit, a queue,
// a model that is not answering — and those are written where they happen. This
// is the floor UNDER those: the bug nobody thought of, a disk that filled up,
// a template that decoded into something the engine will not touch.
//
// It says the same three things every time, because they are the three things a
// person actually needs: what happened, that nothing of theirs was lost, and
// where to go next. The detail goes to the worker log, where the owner can find
// it, and never to the screen.

/** Where an unexpected failure is written down for the owner. Never shown. */
function xeric_web_log(string $what): void
{
    $line = gmdate('c') . ' ' . ($_SERVER['REQUEST_URI'] ?? 'cli') . ', ' . $what . "\n";
    @file_put_contents((string)xeric_web_config()['data_dir'] . '/worker.log', $line, FILE_APPEND | LOCK_EX);
}

/** Does whoever asked for this want a page, or an object? */
function xeric_web_wants_html(): bool
{
    return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
}

/**
 * The last honest word, whatever went wrong.
 *
 * Three shapes, because there are three ways this page can already have begun:
 * nothing sent yet (a page or an object), a stream in flight (say so in the
 * stream's own grammar, so the listener shows it rather than reconnecting
 * forever), and a page half-written (append, do not pretend).
 */
function xeric_web_fail(string $detail, string $where = ''): void
{
    xeric_web_log(($where !== '' ? "$where: " : '') . $detail);

    $sentence = 'Something in the forge broke in a way nobody wrote an explanation for. '
        . 'Nothing you made has been lost, xerics are files, and they are still on the disk. '
        . 'The details have been written to this machine\'s log.';

    if (headers_sent()) {
        // Mid-stream: progress.php's contract is SSE frames, and a `failed`
        // frame is the one thing its listeners already know how to show.
        if (str_contains((string)(implode(' ', headers_list())), 'event-stream')) {
            echo "event: failed\ndata: " . json_encode(['message' => $sentence]) . "\n\n";
        } else {
            echo '<p class="note bad" style="color:#c2694b">' . h($sentence) . '</p>';
        }
        exit;
    }

    if (!xeric_web_wants_html()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $sentence, 'kind' => 'bug']);
        exit;
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>Xeric: that broke</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>body{margin:0;background:#0b0c0a;color:#e9e4d8;font-family:ui-sans-serif,system-ui,sans-serif;'
        . 'line-height:1.55}main{max-width:34rem;margin:0 auto;padding:3rem 1.15rem}'
        . 'h1{font-size:1.6rem;font-weight:600;margin:0 0 .6rem}a{color:#c98a4b}'
        . 'p.n{border-left:2px solid #c2694b;padding:.15rem 0 .15rem .8rem}</style>'
        . '<main><h1>That broke, and it is ours</h1><p class="n">' . h($sentence) . '</p>'
        . '<p><a href="play.php">The xerics that are here →</a><br><a href="forge.php">Forge a new one →</a></p></main>';
    exit;
}

/**
 * Install the floor. Called once, by this file, for web requests only.
 *
 * CLI is left alone on purpose: the two workers write their own error frames
 * into the job file, which is what the browser is reading, and a second handler
 * printing to stdout would land in the log instead of on anybody's screen.
 */
function xeric_web_guard(): void
{
    if (PHP_SAPI === 'cli') return;

    set_exception_handler(function (Throwable $e): void {
        xeric_web_fail(get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    });

    register_shutdown_function(function (): void {
        $e = error_get_last();
        if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        xeric_web_fail('fatal: ' . $e['message'] . ' @ ' . basename((string)$e['file']) . ':' . $e['line']);
    });
}

xeric_web_guard();

/** A world slug that cannot climb out of the worlds directory. */
function xeric_web_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\-]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/** The answer keys the interview owns, plus the three that ride along. */
function xeric_web_answer_keys(array $interview): array
{
    return array_merge(xeric_forge_step_keys($interview), ['themes', 'circle', 'premise']);
}

/**
 * How much of an answer is kept, per key.
 *
 * `premise` is somebody's own paragraph about the world they want, and 400
 * characters is two sentences — a cap that would silently eat the end of what
 * they wrote and build something adjacent to it. It is one field, sent once, and
 * read by one pass, so it gets room to be a paragraph. Everything else stays
 * short because everything else IS short: a name, a job, a preset key.
 */
function xeric_web_answer_cap(string $key): int
{
    // 12000 AND NOT 2000, and it has to match XERIC_PDF_CHARS in forge.php. A
    // premise can now arrive out of a document, and a cap below what the reader
    // hands over would silently eat the end of somebody's own brief on the way
    // to disk — the exact failure this function was written to prevent, one
    // input later.
    return $key === 'premise' ? 12000 : 400;
}

/**
 * Keep only answers the forge knows what to do with.
 *
 * THE ONE CHOKE POINT THE AFFIRMATION GATE STANDS ON. Every path that carries a
 * rating towards a world comes through here — the wizard saving as the user
 * types, the build POST, and the worker's own re-read of what it built — so the
 * clamp is applied once, in the funnel, rather than three times in three files
 * that can disagree. A caller that forgets to clamp cannot exist, because there
 * is no way in that does not pass this line.
 *
 * An unaffirmed session is not REFUSED its answer, it is LOWERED: a stranger who
 * pressed "Unrated" still gets a world built, and it is the world the default
 * would have given them anyway.
 *
 * UNAFFIRMED, THE RATING IS PINNED AND NOT MERELY LOWERED — it is written even
 * when nobody answered the question. That is the difference between a default
 * and a floor: an ANSWER is not a gap, and a gap is what ✨ fills. Left absent,
 * the hand-written surprise concepts (four of the five carry `mature`) and the
 * model's own fill both reach straight past this line and hand a rating to a
 * session that affirmed nothing. Pinned, there is nothing left for them to fill.
 */
function xeric_web_clean_answers(array $in, array $interview): array
{
    $ok = xeric_web_answer_keys($interview);
    $out = [];
    foreach ($in as $k => $v) {
        $k = (string)$k;
        if (!in_array($k, $ok, true)) continue;
        if ($k === 'themes') {
            $out[$k] = is_array($v) ? array_slice(array_map('strval', $v), 0, 4)
                                    : array_slice(array_map('trim', explode(',', (string)$v)), 0, 4);
            continue;
        }
        $s = trim((string)$v);
        if ($s !== '') $out[$k] = mb_substr($s, 0, xeric_web_answer_cap($k));
    }
    if (isset($out['rating']) || !xeric_session_adult()) {
        $out['rating'] = xeric_session_rating((string)($out['rating'] ?? ''));
    }

    // THE REGISTER PIN SURVIVES THE FUNNEL. It is not an interview question, so
    // the key filter above drops it — and a REDRAFT re-forges from these
    // answers, which would have re-rolled the register and moved a world out of
    // its own naming: the thing xeric_forge_naming() exists to prevent. You
    // cannot put a Klingon in 1873 Ireland, and a redraft is still that Ireland.
    //
    // Validated against the shipped list rather than trusted, because this now
    // arrives over HTTP: an unknown value is dropped, and xeric_forge_naming()
    // falls back to deriving one, which is what a world with no pin does anyway.
    $pin = $in['register'] ?? null;
    if (is_array($pin)) {
        // AN INVENTED REGISTER IS A VALUE, and a redraft has to carry the whole
        // thing or the world is renamed out from under itself. Rebuilt field by
        // field rather than passed through, because this arrives over HTTP: what
        // survives is a register's shape and nothing else somebody posted.
        $strs = static function ($v, int $cap): array {
            $o = [];
            foreach ((array)$v as $x) {
                if (!is_string($x)) continue;
                $x = trim(preg_replace('/\s+/u', ' ', $x) ?? '');
                if ($x !== '' && mb_strlen($x) <= 60) $o[] = $x;
                if (count($o) >= $cap) break;
            }
            return array_values(array_unique($o));
        };
        $clean = [
            'key'        => 'invented',
            'label'      => mb_substr(trim((string)($pin['label'] ?? '')), 0, 80),
            'sound'      => mb_substr(trim((string)($pin['sound'] ?? '')), 0, 300),
            'given'      => $strs($pin['given'] ?? [], 40),
            'family'     => $strs($pin['family'] ?? [], 40),
            'toponyms'   => $strs($pin['toponyms'] ?? [], 30),
            'businesses' => $strs($pin['businesses'] ?? [], 30),
            'church'     => mb_substr(trim((string)($pin['church'] ?? '')), 0, 80),
        ];
        if ($clean['given'] !== [] && $clean['family'] !== [] && $clean['sound'] !== '') {
            $out['register'] = $clean;
        }
    } else {
        $reg = mb_strtolower(trim((string)$pin));
        if ($reg !== '') {
            require_once XERIC_WEB_LIB . '/forge/forge.php';
            foreach (xeric_forge_registers()['registers'] as $r) {
                if (mb_strtolower((string)($r['key'] ?? '')) === $reg) { $out['register'] = $reg; break; }
            }
        }
    }
    return $out;
}
