<?php
/**
 * limits.php — what one visitor may spend, said out loud.
 *
 * Rate limits are the product's dignity, not an afterthought — that is the whole
 * design brief for this file. A shared instance runs on one GPU that is also
 * somebody's working machine; the question is never "how do we punish abuse"
 * but "what can we honestly promise a stranger, and how do we say no when we
 * cannot". So:
 *
 *  • EVERY REFUSAL IS A SENTENCE A PERSON WROTE, with a retry_after wherever
 *    there is a real time when the answer changes. Never a 500, never a silent
 *    truncation, never a spinner that means "no".
 *
 *  • A LIMIT IS DECIDED AND RECORDED IN ONE BREATH. The count and the append
 *    happen inside one flock, because a check that is an unlocked read is not a
 *    limit at all: eight requests arriving together all read four-of-five, all
 *    pass, and all spend model time. xeric_limit_check() therefore TAKES the
 *    seat rather than looking at it.
 *
 *  • AND A SEAT NOBODY SITS IN IS GIVEN BACK. The other half of the promise
 *    above: a visitor who typo'd a world name, or found the model down, has not
 *    spent a message. xeric_limit_note() says the work started and keeps the
 *    seat; anything still held when the request ends is released automatically,
 *    so a path that refuses in the middle costs nobody anything without having
 *    to remember to say so.
 *
 *  • EVICTION BEFORE REFUSAL. When the global caps bite, the oldest idle
 *    sessions are let go — with their worlds and copies — before anybody is
 *    turned away. Refusing a new visitor while holding a week of dead ones is
 *    the wrong trade for a demo whose entire job is first impressions.
 *
 *  • THE ADDRESS IS NEVER KEPT. Per-IP caps exist only so one person cannot
 *    mint unlimited cookie jars to escape the per-session caps. What is stored
 *    is an HMAC of the address under a per-install salt — not the address, not
 *    reversible without the salt, and never logged, printed or returned.
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';

// ===========================================================================
// THE TUNABLES. Everything this app will spend on strangers, in one block.
// Each is a default; config.local.php may override any of them by name under a
// 'limits' key, so the owner can tighten a knob on a busy day without a deploy.
// ===========================================================================

/** Per session, per hour. One chat turn ≈ 2–20 GPU seconds; 30 is ~5 minutes of model. */
const XERIC_LIMIT_MESSAGES_PER_HOUR = 30;

/** Per session, per hour. A skip is 2–4 model calls plus a proactive check — far dearer than a message. */
const XERIC_LIMIT_SKIPS_PER_HOUR = 10;

/**
 * Per session, per hour. Rerolling one section of a world in the review step.
 *
 * Higher than skips on purpose: a reroll is usually ONE model call, and the
 * review step is where somebody is deciding whether they like their world at
 * all. Refusing that is refusing the product's own "the forge proposes, the user
 * disposes" — a whole cast is 4 of these, so 24 is six honest attempts.
 */
const XERIC_LIMIT_REROLLS_PER_HOUR = 24;

/** Per session, per day. A world is 2–3 minutes of model AND a directory that lives for a week. */
const XERIC_LIMIT_FORGES_PER_DAY = 5;

/** Per address, per day. Protects the above from somebody clearing their cookies. */
const XERIC_LIMIT_IP_FORGES_PER_DAY = 10;

/**
 * Per address, per day. The anti-escape-hatch: without it, every per-session cap
 * is worth exactly one `curl -c newjar`. Over budget does NOT stop somebody
 * looking around — it stops them spending the model.
 */
const XERIC_LIMIT_IP_SESSIONS_PER_DAY = 20;

/** Global. Live sessions held at once; over this, the oldest idle are evicted. */
const XERIC_LIMIT_LIVE_SESSIONS = 150;

/** Global. Worlds on disk at once. A world is ~90KB of template + seed + db. */
const XERIC_LIMIT_WORLDS = 100;

/** Global. The whole demo's disk budget, megabytes: worlds, copies, sessions, jobs. */
const XERIC_LIMIT_DISK_MB = 1024;

/**
 * A session seen this recently is never evicted, however full the disk is.
 * Evicting somebody who is mid-conversation to make room for a new arrival would
 * be the demo failing at the one thing it is for.
 */
const XERIC_LIMIT_EVICT_GRACE = 1800;     // 30 minutes

/** Window lengths, named once so a message and its retry_after cannot disagree. */
const XERIC_LIMIT_HOUR = 3600;
const XERIC_LIMIT_DAY  = 86400;

/** A tunable by name: the constant above, unless this host overrides it. */
function xeric_limit_n(string $key): int
{
    static $over = null;
    if ($over === null) $over = (array)(xeric_web_config()['limits'] ?? []);
    if (isset($over[$key])) return (int)$over[$key];

    $c = 'XERIC_LIMIT_' . strtoupper($key);
    return defined($c) ? (int)constant($c) : 0;
}

// ---------------------------------------------------------------------------
// Counters
// ---------------------------------------------------------------------------

function xeric_limit_dir(): string
{
    return xeric_web_dir((string)xeric_web_config()['data_dir'] . '/limits');
}

/** One bucket = one (action, who) pair. The name is already a hash; keep it filename-safe. */
function xeric_limit_file(string $bucket): string
{
    return preg_replace('/[^a-z0-9_.-]/i', '', $bucket) . '.json';
}

function xeric_limit_path(string $bucket): string
{
    return xeric_limit_dir() . '/' . xeric_limit_file($bucket);
}

/**
 * The hits still inside the window, and the oldest of them.
 *
 * A sliding window rather than a fixed bucket, because "you may say 30 things an
 * hour" resetting on the hour means 60 in two minutes at :59, and then a
 * confusing wait — and the honest retry_after is exactly what a sliding window
 * already knows: when the oldest hit falls out.
 *
 * @return array{count:int,oldest:int}
 */
function xeric_limit_hits(string $bucket, int $window, int $now): array
{
    $raw = @file_get_contents(xeric_limit_path($bucket));
    $d = $raw === false ? null : json_decode($raw, true);
    $hits = is_array($d) ? array_map('intval', (array)($d['hits'] ?? [])) : [];

    $cut = $now - $window;
    $hits = array_values(array_filter($hits, fn($t) => $t > $cut));
    sort($hits);
    return ['count' => count($hits), 'oldest' => $hits === [] ? 0 : $hits[0]];
}

/** Record one hit, trimming the window on the way past. Never throws. */
function xeric_limit_add(string $bucket, int $window, int $now): void
{
    $path = xeric_limit_path($bucket);
    $fh = @fopen($path, 'c+');
    if (!$fh) return;
    @flock($fh, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fh), true);
    $hits = is_array($d) ? array_map('intval', (array)($d['hits'] ?? [])) : [];
    $cut = $now - $window;
    $hits = array_values(array_filter($hits, fn($t) => $t > $cut));
    $hits[] = $now;
    // A bucket cannot grow without bound even if a window is misconfigured.
    if (count($hits) > 500) $hits = array_slice($hits, -500);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(['hits' => $hits]));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Decide against the cap and record the hit in ONE operation.
 *
 * This is the whole of the concurrency story. Everything above the lock — the
 * window, the sort, the count — is exactly what xeric_limit_hits() does; the
 * difference is that nobody else can slip a hit in between the counting and the
 * appending, so of eight requests that arrive together against a cap of five,
 * five pass and three are refused rather than all eight passing.
 *
 * A hit taken here is HELD (see xeric_limit_held): it counts immediately, and it
 * is given back at the end of the request unless somebody said the work started.
 *
 * @return array{ok:bool,count:int,oldest:int}
 */
function xeric_limit_reserve(string $bucket, int $window, int $cap, int $now): array
{
    $path = xeric_limit_path($bucket);
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        // No counter file means no counting, and refusing every visitor on this
        // machine because a directory is unwritable would be the worst failure
        // this file has available to it. Fail open, and say so where it is seen.
        error_log('xeric: cannot open the rate-limit bucket ' . $path . ', nothing is being counted');
        return ['ok' => true, 'count' => 0, 'oldest' => 0];
    }
    @flock($fh, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fh), true);
    $hits = is_array($d) ? array_map('intval', (array)($d['hits'] ?? [])) : [];
    $cut = $now - $window;
    $hits = array_values(array_filter($hits, fn($t) => $t > $cut));
    sort($hits);

    $count = count($hits);
    $ok = $count < $cap;
    if ($ok) {
        $hits[] = $now;
        if (count($hits) > 500) $hits = array_slice($hits, -500);
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(['hits' => $hits]));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);

    if ($ok) {
        $held = &xeric_limit_held();
        $held[$bucket][] = $now;
    }
    return ['ok' => $ok, 'count' => $ok ? $count + 1 : $count, 'oldest' => $hits === [] ? 0 : $hits[0]];
}

/**
 * The seats this request has taken and not yet spent, by bucket.
 *
 * Registering the release here rather than asking every endpoint to remember it
 * is deliberate: the paths that refuse in the middle — a 404, a dead model, a
 * full line — are exactly the paths somebody would forget, and forgetting means
 * charging a visitor for work that never happened.
 *
 * @return array<string,int[]>
 */
function &xeric_limit_held(): array
{
    static $held = [];
    static $armed = false;
    if (!$armed) {
        $armed = true;
        register_shutdown_function('xeric_limit_give_back');
    }
    return $held;
}

/** Give back every seat this request took and never sat in. */
function xeric_limit_give_back(): void
{
    $held = &xeric_limit_held();
    foreach ($held as $bucket => $stamps) {
        foreach ($stamps as $t) xeric_limit_drop((string)$bucket, (int)$t);
    }
    $held = [];
}

/** The work started: this seat is spent and stays spent. */
function xeric_limit_keep(string $bucket): bool
{
    $held = &xeric_limit_held();
    if (empty($held[$bucket])) return false;
    array_pop($held[$bucket]);
    if ($held[$bucket] === []) unset($held[$bucket]);
    return true;
}

/** The work is not going to happen: give this seat back now rather than at the end. */
function xeric_limit_give(string $bucket): void
{
    $held = &xeric_limit_held();
    if (empty($held[$bucket])) return;
    $t = (int)array_pop($held[$bucket]);
    if ($held[$bucket] === []) unset($held[$bucket]);
    xeric_limit_drop($bucket, $t);
}

/**
 * Remove one hit we put there ourselves, by its timestamp. Never anybody else's,
 * and never a bucket that does not already exist — a release is not a reason to
 * start writing files.
 */
function xeric_limit_drop(string $bucket, int $stamp): void
{
    $dir = (string)xeric_web_config()['data_dir'] . '/limits';
    if (!is_dir($dir)) return;
    $fh = @fopen($dir . '/' . xeric_limit_file($bucket), 'r+');
    if (!$fh) return;
    @flock($fh, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fh), true);
    $hits = is_array($d) ? array_map('intval', (array)($d['hits'] ?? [])) : [];
    $at = array_search($stamp, $hits, true);
    if ($at !== false) { unset($hits[$at]); $hits = array_values($hits); }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(['hits' => $hits]));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Which buckets an action spends from, in the order they are asked, with the
 * tunable and the window each is counted against.
 *
 * One list, so the check, the charge and the release cannot disagree about what
 * a forge costs.
 *
 * @return array<string,array{0:string,1:int}>
 */
function xeric_limit_buckets(string $action, string $sid, string $ip): array
{
    if ($action === 'message') return ['msg-' . $sid    => ['messages_per_hour', XERIC_LIMIT_HOUR]];
    if ($action === 'skip')    return ['skip-' . $sid   => ['skips_per_hour',    XERIC_LIMIT_HOUR]];
    if ($action === 'reroll')  return ['reroll-' . $sid => ['rerolls_per_hour',  XERIC_LIMIT_HOUR]];
    if ($action === 'forge')   return ['forge-' . $sid  => ['forges_per_day',    XERIC_LIMIT_DAY],
                                       'ipforge-' . $ip => ['ip_forges_per_day', XERIC_LIMIT_DAY]];
    return [];
}

/** Old buckets are noise. Swept on the way past, never on a schedule. */
function xeric_limit_sweep(?int $now = null): void
{
    $cut = ($now ?? time()) - (XERIC_LIMIT_DAY * 2);
    foreach (glob(xeric_limit_dir() . '/*.json') ?: [] as $f) {
        if ((int)@filemtime($f) < $cut) @unlink($f);
    }
}

// ---------------------------------------------------------------------------
// The address, hashed
// ---------------------------------------------------------------------------

/**
 * Cloudflare's published origin ranges — the only hops whose word about who the
 * visitor is may be taken (https://www.cloudflare.com/ips/).
 *
 * A host that is NOT behind Cloudflare adds its own front end's addresses under
 * a 'trust_proxy' key in config.local.php rather than editing this list, so that
 * moving the demo never means editing a security constant in a hurry.
 */
const XERIC_LIMIT_CF_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/** Is this address one of the front ends whose forwarding headers are facts? */
function xeric_limit_trusted_hop(string $ip): bool
{
    foreach (XERIC_LIMIT_CF_RANGES as $cidr) if (xeric_limit_in_cidr($ip, $cidr)) return true;
    foreach ((array)(xeric_web_config()['trust_proxy'] ?? []) as $cidr) {
        if (xeric_limit_in_cidr($ip, (string)$cidr)) return true;
    }
    return false;
}

/** Address inside range, for both families. A bare address is its own /32 or /128. */
function xeric_limit_in_cidr(string $ip, string $cidr): bool
{
    $bin = @inet_pton($ip);
    $slash = strpos($cidr, '/');
    $nbin = @inet_pton($slash === false ? $cidr : substr($cidr, 0, $slash));
    if ($bin === false || $nbin === false || strlen($bin) !== strlen($nbin)) return false;

    $bits = $slash === false ? strlen($bin) * 8 : (int)substr($cidr, $slash + 1);
    if ($bits <= 0 || $bits > strlen($bin) * 8) return $bits === 0;

    $whole = intdiv($bits, 8);
    if ($whole > 0 && strncmp($bin, $nbin, $whole) !== 0) return false;
    $rest = $bits % 8;
    if ($rest === 0) return true;
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return ($bin[$whole] & $mask) === ($nbin[$whole] & $mask);
}

/**
 * The visitor's address as this app sees it — for hashing, and for nothing else.
 *
 * Behind a CDN, REMOTE_ADDR is the proxy on every request and would put every
 * visitor in one bucket. CF-Connecting-IP is
 * therefore read — but ONLY when the request actually arrived from Cloudflare,
 * because a header is a fact from a trusted hop and a free choice of bucket from
 * anybody else. Any origin is reachable directly sooner or later (a shared host
 * makes it trivial), and a forgeable address header does not merely weaken the
 * per-address caps, it removes them: one fresh HMAC bucket per header value.
 *
 * X-Forwarded-For is not consulted at all. Its leftmost value is written by the
 * client, which is the version of this bug that was here before.
 */
function xeric_limit_ip_raw(): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '') return 'cli';

    if (xeric_limit_trusted_hop($remote)) {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false) return $cf;
    }
    return $remote;
}

/**
 * A stable, non-reversible handle for one address.
 *
 * THE RAW ADDRESS IS NEVER WRITTEN ANYWHERE. It is read from the request, hashed
 * under a salt that exists only on this machine, and forgotten. The salt is
 * random per install, so the hashes are not comparable across hosts and cannot
 * be reversed with a list of every IPv4 address (which is otherwise a two-minute
 * job for anyone who gets the files).
 */
function xeric_limit_ip(?string $raw = null): string
{
    static $salt = null;
    if ($salt === null) {
        $p = (string)xeric_web_config()['data_dir'] . '/ip-salt';
        $salt = (string)@file_get_contents($p);
        if (strlen($salt) < 32) {
            $salt = bin2hex(random_bytes(32));
            xeric_web_dir((string)xeric_web_config()['data_dir']);
            @file_put_contents($p, $salt, LOCK_EX);
            @chmod($p, 0600);
        }
    }
    return substr(hash_hmac('sha256', $raw ?? xeric_limit_ip_raw(), $salt), 0, 20);
}

// ---------------------------------------------------------------------------
// New sessions — the per-address budget, spent once
// ---------------------------------------------------------------------------

/**
 * A brand new session, seen from the address budget's point of view.
 *
 * Called by session.php the moment a record is created, and the decision comes
 * from the LOCKED increment rather than from a read beside it — a race here is
 * the one that matters most, because it is the race that buys an escape from
 * every other cap in the file.
 *
 * Being over budget does not refuse the VISITOR — they must always be able to
 * look at the demo, and a cookie is not the thing that costs anything. It
 * refuses the RECORD. That distinction used to be missing: an over-budget
 * address still minted a session file per cookieless GET, and fifteen hundred of
 * them walk xeric_limit_evict() through real visitors' worlds oldest-first while
 * the attacker's own sessions, being the newest, survive.
 *
 * The caller mints the record, so the caller is the one that must not write a
 * record this says no to — see xeric_limit_session_denied().
 */
function xeric_limit_new_session(array $s, ?int $now = null): array
{
    $now = $now ?? time();
    $bucket = 'sess-' . xeric_limit_ip();
    $h = xeric_limit_reserve($bucket, XERIC_LIMIT_DAY, xeric_limit_n('ip_sessions_per_day'), $now);
    xeric_limit_keep($bucket);          // a session outlives the request that minted it

    // Arrival is also when the global session cap is trimmed to. Occasional
    // rather than every time, because counting what everybody is holding walks
    // the whole data dir, and a new visitor should not pay for that to load a
    // page. There is no cron on this host: the demo tidies up as it is used.
    if (random_int(1, 10) === 1) xeric_limit_evict(0, $now);

    if (!$h['ok']) {
        $s['capped_until'] = ($h['oldest'] > 0 ? $h['oldest'] : $now) + XERIC_LIMIT_DAY;
        $s['deny'] = true;
    }
    return $s;
}

/**
 * Should this session record be written to disk at all?
 *
 * False for every ordinary visitor, and for every visitor who is merely over the
 * spending caps. True only for a new record from an address that has already had
 * its day's worth — the case where writing it is how the disk gets filled.
 */
function xeric_limit_session_denied(array $s): bool
{
    return !empty($s['deny']);
}

// ---------------------------------------------------------------------------
// The gate
// ---------------------------------------------------------------------------

/** A refusal, in the shape every endpoint knows how to return. */
function xeric_limit_no(string $message, int $retryAfter = 0, string $kind = 'limit', int $status = 429): array
{
    return ['ok' => false, 'kind' => $kind, 'status' => $status,
            'message' => $message, 'retry_after' => max(0, $retryAfter)];
}

/** "about 12 minutes" — a wait a person can act on, never 743 seconds. */
function xeric_limit_when(int $seconds): string
{
    if ($seconds <= 90)   return 'in a minute or so';
    if ($seconds < 5400)  return 'in about ' . max(1, (int)round($seconds / 60)) . ' minutes';
    if ($seconds < 86400) return 'in about ' . max(1, (int)round($seconds / 3600)) . ' hours';
    return 'tomorrow';
}

/**
 * May this session do this thing right now — and if so, that one is taken.
 *
 * The seat is taken here, not looked at (see the header). It is released again
 * when the request ends unless xeric_limit_note() says the work started, so the
 * old split still holds from the visitor's side: a request that goes on to fail
 * validation has not spent anything.
 *
 * @param string $action  message · skip · reroll · forge
 * @param array  $o       sid, ip, now — all injectable, which is what makes the
 *                        windows testable without waiting an hour.
 * @return array{ok:bool,kind?:string,status?:int,message?:string,retry_after?:int}
 */
/**
 * Are the caps switched on at all?
 *
 * OFF BY DEFAULT, BECAUSE THIS IS SOMEBODY'S OWN MACHINE. Every number in this
 * file was written for a public demo running on one workstation with one GPU,
 * where ten xerics a day from one address is generosity. On a local install it
 * is a stranger rationing your own hardware to you: the model is yours, the
 * electricity is yours, and being told to come back in twenty-one hours is
 * absurd.
 *
 * The machinery stays, all of it, because the hosted demo still needs it — a
 * host turns it back on with `caps => true` in config.local.php and everything
 * below behaves exactly as it always did.
 */
function xeric_limit_on(): bool
{
    static $on = null;
    if ($on === null) {
        $c = xeric_web_config();
        $env = getenv('XERIC_CAPS');
        $on = ($env !== false && trim((string)$env) !== '')
            ? in_array(strtolower(trim((string)$env)), ['1', 'true', 'yes', 'on'], true)
            : !empty($c['caps']);
    }
    return $on;
}

function xeric_limit_check(string $action, array $o = []): array
{
    if (!xeric_limit_on()) return ['ok' => true];
    $now = (int)($o['now'] ?? time());
    $sid = (string)($o['sid'] ?? xeric_session_id());
    $ip  = (string)($o['ip']  ?? xeric_limit_ip());

    // The per-address session budget. Read off the ADDRESS's own bucket, not
    // only off the session record: a record is not written for a visitor who is
    // already over that budget (session.php — writing one is how a flood of
    // made-up cookie values fills the disk and evicts real worlds), so trusting
    // the record alone would hand a fresh, unmarked session to exactly the
    // address the cap exists to hold. The record is still consulted, because it
    // outlives the window the bucket forgets.
    $sess = xeric_web_session_read($sid);
    $capped = (int)($sess['capped_until'] ?? 0);
    $addr = xeric_limit_hits('sess-' . $ip, XERIC_LIMIT_DAY, $now);
    if ($addr['count'] >= xeric_limit_n('ip_sessions_per_day') && $addr['oldest'] > 0) {
        $capped = max($capped, $addr['oldest'] + XERIC_LIMIT_DAY);
    }
    if ($capped > $now) {
        return xeric_limit_no(
            'The demo has already met as many new visitors from your address as it can hold in a day. '
            . 'You can still look around all of it, the model frees up ' . xeric_limit_when($capped - $now) . '.',
            $capped - $now, 'ip_sessions');
    }

    if ($action === 'message') {
        $n = xeric_limit_n('messages_per_hour');
        $h = xeric_limit_reserve('msg-' . $sid, XERIC_LIMIT_HOUR, $n, $now);
        if (!$h['ok']) {
            $wait = $h['oldest'] + XERIC_LIMIT_HOUR - $now;
            return xeric_limit_no(
                "That is $n things said in an hour, which is as much of one shared GPU as the demo can give "
                . 'one visitor. Everybody in your xeric will still be there ' . xeric_limit_when($wait) . '.',
                $wait);
        }
        return ['ok' => true];
    }

    if ($action === 'skip') {
        $n = xeric_limit_n('skips_per_hour');
        $h = xeric_limit_reserve('skip-' . $sid, XERIC_LIMIT_HOUR, $n, $now);
        if (!$h['ok']) {
            $wait = $h['oldest'] + XERIC_LIMIT_HOUR - $now;
            return xeric_limit_no(
                "You have moved a xeric forward $n times this hour. Each skip is several model calls, so that "
                . 'is the hour spent, the next one is yours ' . xeric_limit_when($wait) . '.',
                $wait);
        }
        return ['ok' => true];
    }

    if ($action === 'reroll') {
        $n = xeric_limit_n('rerolls_per_hour');
        $h = xeric_limit_reserve('reroll-' . $sid, XERIC_LIMIT_HOUR, $n, $now);
        if (!$h['ok']) {
            $wait = $h['oldest'] + XERIC_LIMIT_HOUR - $now;
            return xeric_limit_no(
                "You have rerolled $n things this hour, which is a whole xeric over again and then some. "
                . 'Everything you have kept is saved, the next reroll is yours ' . xeric_limit_when($wait) . '.',
                $wait);
        }
        return ['ok' => true];
    }

    if ($action === 'forge') {
        $n = xeric_limit_n('forges_per_day');
        $h = xeric_limit_reserve('forge-' . $sid, XERIC_LIMIT_DAY, $n, $now);
        if (!$h['ok']) {
            $wait = $h['oldest'] + XERIC_LIMIT_DAY - $now;
            return xeric_limit_no(
                "You have forged $n xerics today, which is the most this demo will build for one visitor. "
                . 'The ones you made are still here, and you can forge again ' . xeric_limit_when($wait) . '.',
                $wait);
        }

        // Two budgets, so a refusal by the second must give the first one back —
        // otherwise a visitor whose ADDRESS is spent quietly loses one of their
        // own five as well, every time they ask.
        $ipn = xeric_limit_n('ip_forges_per_day');
        $ih = xeric_limit_reserve('ipforge-' . $ip, XERIC_LIMIT_DAY, $ipn, $now);
        if (!$ih['ok']) {
            xeric_limit_give('forge-' . $sid);
            $wait = $ih['oldest'] + XERIC_LIMIT_DAY - $now;
            return xeric_limit_no(
                "$ipn xerics have been forged from your address today. That is the day's budget for one "
                . 'visitor, the demo builds them on one machine, one at a time. Try again ' . xeric_limit_when($wait) . '.',
                $wait);
        }

        // Global room. Eviction happens BEFORE the refusal, never instead of it:
        // if letting go of the dead makes room, nobody is turned away at all.
        $room = xeric_limit_room($now);
        if (!$room['ok']) {
            xeric_limit_cancel('forge', ['sid' => $sid, 'ip' => $ip]);
            return $room;
        }

        return ['ok' => true];
    }

    return ['ok' => true];
}

/**
 * Is there room on this machine for one more world?
 *
 * Two gauges, not counters: how many worlds are on disk, and how much disk the
 * whole demo is holding. Both try eviction first.
 */
function xeric_limit_room(?int $now = null): array
{
    $now = $now ?? time();

    $worlds = count(glob(xeric_web_worlds_dir() . '/*/world-template.json') ?: []);
    $budget = xeric_limit_n('disk_mb') * 1024 * 1024;
    $used   = xeric_limit_disk();

    if ($worlds >= xeric_limit_n('worlds') || $used >= $budget) {
        xeric_limit_evict(4, $now);
        $worlds = count(glob(xeric_web_worlds_dir() . '/*/world-template.json') ?: []);
        $used   = xeric_limit_disk();
    }

    if ($worlds >= xeric_limit_n('worlds')) {
        return xeric_limit_no(
            'The demo is holding as many xerics as it has room for, and the idle ones have already been let go. '
            . 'Somebody else\'s will expire within the day, try again then, or play one of the xerics already here.',
            3600, 'full', 503);
    }
    if ($used >= $budget) {
        return xeric_limit_no(
            'The demo has used up the disk it is allowed. Nothing is broken and nothing of yours is lost, '
            . 'there is just no room to build another xeric until some of the old ones expire.',
            3600, 'full', 503);
    }
    return ['ok' => true];
}

/** What the whole demo is holding, in bytes: worlds, session copies, records, jobs. */
function xeric_limit_disk(): int
{
    $n = 0;
    $roots = [xeric_web_worlds_dir(), xeric_session_root(), xeric_web_sessions_dir(), xeric_web_jobs_dir()];
    foreach ($roots as $root) {
        foreach (glob($root . '/*') ?: [] as $p) {
            if (is_dir($p)) {
                foreach (glob($p . '/*') ?: [] as $f) $n += (int)@filesize($f);
            } else {
                $n += (int)@filesize($p);
            }
        }
    }

    // And everything that sits loose in the data dir itself, one level only —
    // worker.log above all, which the sweep truncates but nothing weighed, so
    // the budget kept reporting room for the one file that grows unattended.
    // ip-salt, model.lock and queue/line.json are small and counted for honesty.
    // The four roots above are subdirectories of this one and are skipped here
    // rather than counted twice.
    $counted = array_filter(array_map('realpath', $roots));
    foreach (glob(rtrim((string)xeric_web_config()['data_dir'], '/') . '/*') ?: [] as $p) {
        if (!is_dir($p)) { $n += (int)@filesize($p); continue; }
        if (in_array(realpath($p), $counted, true)) continue;
        foreach (glob($p . '/*') ?: [] as $f) if (!is_dir($f)) $n += (int)@filesize($f);
    }
    return $n;
}

/**
 * The work is genuinely starting: keep the seat the check took.
 *
 * Not a second charge — the hit is already in the bucket and has been counting
 * against everybody else since the check. This is the word that stops it being
 * given back when the request ends. A caller that never checked (a CLI path)
 * still gets a plain hit, so noting alone remains a complete charge.
 */
function xeric_limit_note(string $action, array $o = []): void
{
    if (!xeric_limit_on()) return;                 // nothing is being rationed
    $now = (int)($o['now'] ?? time());
    $sid = (string)($o['sid'] ?? xeric_session_id());
    $ip  = (string)($o['ip']  ?? xeric_limit_ip());

    foreach (xeric_limit_buckets($action, $sid, $ip) as $bucket => $spec) {
        if (!xeric_limit_keep((string)$bucket)) xeric_limit_add((string)$bucket, (int)$spec[1], $now);
    }
    // The sweep is about file ages, so it reads the real clock even when the
    // windows above are being driven from a test's own: an injected `now` decides
    // what counts as inside an hour, never what counts as an old file.
    if (random_int(1, 25) === 1) xeric_limit_sweep();
}

/**
 * The work is not going to happen after all: give the seat back now.
 *
 * The end of the request would do this anyway; calling it explicitly is for the
 * paths that go on to do something else afterwards, where holding a seat for the
 * rest of the request would refuse the visitor's own next action.
 */
function xeric_limit_cancel(string $action, array $o = []): void
{
    $sid = (string)($o['sid'] ?? xeric_session_id());
    $ip  = (string)($o['ip']  ?? xeric_limit_ip());
    foreach (array_keys(xeric_limit_buckets($action, $sid, $ip)) as $bucket) {
        xeric_limit_give((string)$bucket);
    }
}

/** Check and charge in one, for the endpoints where the work starts immediately. */
function xeric_limit_take(string $action, array $o = []): array
{
    $r = xeric_limit_check($action, $o);
    if ($r['ok']) xeric_limit_note($action, $o);
    return $r;
}

/**
 * A refusal, answered.
 *
 * 429 for "not yet" and 503 for "not right now, and it isn't about you", both
 * with Retry-After so a script is told the same thing the sentence says.
 */
function xeric_limit_guard(array $r): void
{
    if ($r['ok'] ?? false) return;
    $retry = (int)($r['retry_after'] ?? 0);
    if ($retry > 0 && !headers_sent()) header('Retry-After: ' . $retry);
    xeric_web_json([
        'error'       => (string)($r['message'] ?? 'the demo has had enough for today'),
        'kind'        => (string)($r['kind'] ?? 'limit'),
        'retry_after' => $retry,
    ], (int)($r['status'] ?? 429));
}

/**
 * What this visitor has left, for the quiet line on the play view.
 *
 * Shown, not enforced — the enforcement is above. It is here because a limit a
 * visitor cannot see is a limit that feels like a bug when it lands.
 */
function xeric_limit_left(?string $sid = null, ?int $now = null): array
{
    // A LARGE NUMBER RATHER THAN A SPECIAL CASE. Callers print "N of M left" and
    // gate buttons on it; handing them nulls would mean teaching every one of
    // them about a state that only exists here.
    if (!xeric_limit_on()) {
        $n = ['messages' => 999999, 'skips' => 999999, 'forges' => 999999, 'rerolls' => 999999];
        return $n + ['of' => $n];
    }
    $now = $now ?? time();
    $sid = $sid ?? xeric_session_id();

    $msg    = xeric_limit_hits('msg-' . $sid, XERIC_LIMIT_HOUR, $now);
    $skip   = xeric_limit_hits('skip-' . $sid, XERIC_LIMIT_HOUR, $now);
    $forge  = xeric_limit_hits('forge-' . $sid, XERIC_LIMIT_DAY, $now);
    $reroll = xeric_limit_hits('reroll-' . $sid, XERIC_LIMIT_HOUR, $now);

    return [
        'messages' => max(0, xeric_limit_n('messages_per_hour') - $msg['count']),
        'skips'    => max(0, xeric_limit_n('skips_per_hour') - $skip['count']),
        'forges'   => max(0, xeric_limit_n('forges_per_day') - $forge['count']),
        'rerolls'  => max(0, xeric_limit_n('rerolls_per_hour') - $reroll['count']),
        'of'       => ['messages' => xeric_limit_n('messages_per_hour'),
                       'skips'    => xeric_limit_n('skips_per_hour'),
                       'forges'   => xeric_limit_n('forges_per_day'),
                       'rerolls'  => xeric_limit_n('rerolls_per_hour')],
    ];
}

// ---------------------------------------------------------------------------
// Eviction
// ---------------------------------------------------------------------------

/**
 * Let go of the oldest idle sessions, with everything they were holding.
 *
 * Oldest FIRST USE-idle, not oldest created: somebody who came back an hour ago
 * is a live visitor even if their session is six days old. Anybody seen inside
 * XERIC_LIMIT_EVICT_GRACE is untouchable at any pressure — the alternative is
 * deleting a world out from under somebody who is talking to its cast.
 *
 * @param int $want how many to let go of, at least; the caps may take more
 * @return array{sessions:int,worlds:int,bytes:int}
 */
function xeric_limit_evict(int $want = 1, ?int $now = null): array
{
    $now = $now ?? time();
    $live = xeric_session_live();                       // oldest use first
    $out = ['sessions' => 0, 'xerics' => 0, 'bytes' => 0];

    $overSessions = max(0, count($live) - xeric_limit_n('live_sessions'));
    $budget = xeric_limit_n('disk_mb') * 1024 * 1024;
    $used = xeric_limit_disk();

    $need = max($want, $overSessions);

    foreach ($live as $s) {
        $doneEnough = $out['sessions'] >= $need && $used < $budget;
        if ($doneEnough) break;
        if ((int)$s['seen'] > $now - XERIC_LIMIT_EVICT_GRACE) break;   // live visitors are not stock

        $gone = xeric_session_forget((string)$s['sid']);
        $out['sessions']++;
        $out['xerics'] += (int)$gone['xerics'];
        $out['bytes']  += (int)$gone['bytes'];
        $used -= (int)$gone['bytes'];
    }
    return $out;
}
