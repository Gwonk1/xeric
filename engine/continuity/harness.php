<?php
/**
 * Xeric continuity benchmark — the harness. docs/FUTURE.md Appendix B; how to
 * run and read it is docs/CONTINUITY.md.
 *
 * ONE WORLD, LIVED ON A FAKE REAL CLOCK. The wall clock is pinned through
 * xeric_state_time() and advanced an hour per tick, so world time (real time
 * plus the offset) moves exactly the way it does when nobody is looking, and
 * every created_at, every reply lag and every learn crumb is the same number on
 * every run. Each tick runs heart.php's order; a scripted skip runs
 * tick-worker.php's; say / travel / kill run the engine calls play does. None
 * of this re-implements a rule: it calls the engine, in the engine's orders,
 * and writes down what came back.
 *
 *   tick (heart.php)   xeric_constructs_tick → one-window xeric_sweep_catchup
 *                      → xeric_proactive_check   (+ a learn pass, only if
 *                      heart.php itself has grown one — see cb_heart_learns)
 *   skip (tick-worker) xeric_learn_settle → xeric_rewind_mark → xeric_clock_advance
 *                      → catchup window by window → proactive → xeric_learn_pend
 *                      → xeric_lessons_distil → xeric_rewind_commit
 *   say                xeric_chat_turn + xeric_chat_extract, without say.php's
 *                      wall-clock gate on the harvest
 *   travel / kill      xeric_travel_go / xeric_death_kill
 *
 * SEEDS. Every window is seeded crc32("$seed|w|$window") and every proactive
 * check crc32("$seed|p|$hour"), reseeding before every call, because
 * xeric_table_play reseeds mt partway through a sweep.
 *
 * WHAT IS RECORDED. Every model call through the capture wrapper (phase,
 * viewers, day, the messages, bytes, approximate tokens — and reported to
 * xeric_llm_tally, which stub calls otherwise never reach), and every third
 * hour every living character's prompt, built with xeric_prompt_build, so the
 * people nobody talks to are probed too. Digests ignore timestamps and ids.
 */

declare(strict_types=1);

require_once __DIR__ . '/../clock.php';
require_once __DIR__ . '/../sweeps.php';
require_once __DIR__ . '/../proactive.php';
require_once __DIR__ . '/../story.php';
require_once __DIR__ . '/../rewind.php';
require_once __DIR__ . '/../death.php';
require_once __DIR__ . '/../travel.php';
require_once __DIR__ . '/../narrator.php';
require_once __DIR__ . '/../scene.php';
require_once __DIR__ . '/../photo.php';
require_once __DIR__ . '/standin.php';

const CB_WINDOW         = 3600;
const CB_SKIP_MAX_EVENTS  = 3;    // forge/web/play-lib.php XERIC_PLAY_MAX_EVENTS
const CB_SKIP_MAX_WINDOWS = 26;   // forge/web/play-lib.php XERIC_PLAY_MAX_WINDOWS
const CB_HEART_MAX_WINDOWS = 6;   // forge/web/heart.php XERIC_HEART_MAX_WINDOWS

// ---------------------------------------------------------------------------
// The scenario
// ---------------------------------------------------------------------------

/** Scenario + the world it runs, with `patch` applied. */
function cb_scenario_load(string $path): array
{
    $sc = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $T  = xeric_world_load(dirname($path) . '/' . (string)$sc['world']);
    foreach ((array)($sc['patch'] ?? []) as $dotted => $value) {
        $ref = &$T;
        foreach (explode('.', (string)$dotted) as $k) { $ref = &$ref[$k]; }
        $ref = $value;
        unset($ref);
    }
    return ['T' => $T, 'sc' => $sc, 'path' => $path];
}

/** "d2 07:15" → a world epoch in the world's timezone. */
function cb_at(array $T, array $sc, string $spec): int
{
    if (!preg_match('/^d(\d+)\s+(\d{1,2}):(\d{2})$/', trim($spec), $m)) throw new RuntimeException("continuity: bad time '$spec'");
    $tz = new DateTimeZone((string)($T['user']['timezone'] ?? 'UTC'));
    $d  = (new DateTimeImmutable((string)$sc['start'] . ' 00:00', $tz))->modify('+' . ((int)$m[1] - 1) . ' days')
            ->setTime((int)$m[2], (int)$m[3]);
    return $d->getTimestamp();
}

/** Which scenario day (1-based) a world epoch falls in. */
function cb_day(array $T, array $sc, int $epoch): int
{
    $start = cb_at($T, $sc, 'd1 00:00');
    $tz = new DateTimeZone((string)($T['user']['timezone'] ?? 'UTC'));
    $a  = new DateTimeImmutable('@' . $start);
    $b  = (new DateTimeImmutable('@' . $epoch))->setTimezone($tz)->setTime(0, 0);
    return 1 + (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
}

/** Does heart.php run a learn pass? Read from heart.php itself, so a fix there shows up here. */
function cb_heart_learns(): bool
{
    $f = __DIR__ . '/../../forge/web/heart.php';
    $s = is_file($f) ? (string)file_get_contents($f) : '';
    return (bool)preg_match('/^\s*[^\/\*\n]*\bxeric_(learn_pass|lessons_distil|learn_tally_apply)\s*\(/m', $s);
}

// ---------------------------------------------------------------------------
// The model seam, wrapped
// ---------------------------------------------------------------------------

/**
 * $model: 'standin' | 'leaky' | an OpenAI-compatible base URL. The URL case
 * forwards through the same stub seam, so a real model's calls are captured
 * exactly like the stand-in's.
 */
function cb_endpoint(array &$run, string $model, int $seed): array
{
    $T     = $run['T'];
    $clock = function () use (&$run): int { return (int)$run['world_epoch']; };
    if ($model === 'standin' || $model === 'leaky') {
        $inner = cb_standin($T, $model === 'leaky' ? 'leaky' : 'honest', $seed, $clock);
        $base  = 'standin://' . $model;
    } else {
        $real  = ['base' => xeric_llm_normalize_base($model), 'key' => getenv('XERIC_LLM_KEY') ?: '',
                  'model' => getenv('XERIC_LLM_MODEL') ?: 'continuity'];
        $base  = $real['base'];
        $inner = function (string $tag, array $msgs, array $opts) use ($real) {
            if ($tag === 'chat' || $tag === 'narrator') {
                $u = [];
                $text = xeric_llm_chat($real, $msgs, ['temperature' => 0.0] + $opts, $u);
                return ['text' => $text, 'usage' => $u];
            }
            return xeric_llm_json($real, $msgs, ['temperature' => 0.0] + $opts);
        };
    }

    $stub = function (string $tag, array $msgs, array $opts) use (&$run, $inner, $base) {
        $out  = $inner($tag, $msgs, $opts);
        $in   = 0;
        foreach ($msgs as $m) $in += strlen((string)($m['content'] ?? ''));
        $outB = strlen(is_array($out) ? (isset($out['text']) ? (string)$out['text'] : json_encode($out)) : (string)$out);
        $viewers = [];
        if ($tag === 'chat') {
            $h = cb_speaker_of($run['T'], $msgs);
            if ($h !== '') $viewers[] = $h;
        } elseif ($tag === 'extract') {
            [$h] = cb_extract_lines($run['T'], $msgs);
            if ($h !== '') $viewers[] = $h;
        } elseif ($tag === 'sweep') {
            preg_match_all('/^- \[([a-z0-9_]+)\]/mu', (string)($msgs[1]['content'] ?? ''), $hm);
            $viewers = $hm[1];                 // a sweep prompt counts against everybody in the room
        }
        $run['calls'][] = [
            'n' => count($run['calls']), 'tag' => $tag, 'phase' => $run['phase'],
            'day' => cb_day($run['T'], $run['sc'], (int)$run['world_epoch']), 'epoch' => (int)$run['world_epoch'],
            'viewers' => $viewers, 'messages' => $msgs, 'in' => $in, 'out' => $outB,
        ];
        xeric_llm_tally(['prompt_tokens' => intdiv($in + 3, 4), 'completion_tokens' => intdiv($outB + 3, 4)], $base);
        return $out;
    };
    return ['base' => $base, 'stub' => $stub];
}

// ---------------------------------------------------------------------------
// The clock
// ---------------------------------------------------------------------------

function cb_set_real(array &$run, int $real): void
{
    $run['real'] = $real;
    xeric_state_time($real);
    $run['world_epoch'] = xeric_clock_epoch($run['db']);
}

function cb_now(array &$run): array
{
    $run['world_epoch'] = xeric_clock_epoch($run['db']);
    return xeric_clock_now($run['db'], $run['T']);
}

// ---------------------------------------------------------------------------
// Digests — what happened, without when the server happened to write it
// ---------------------------------------------------------------------------

function cb_digest(PDO $db): string
{
    $q = fn(string $sql) => $db->query($sql)->fetchAll(PDO::FETCH_NUM);
    $ws = [];
    foreach ($q('SELECT key, value FROM world_state ORDER BY key') as [$k, $v]) {
        if (str_starts_with((string)$k, 'skip:')) continue;          // the rewind's own bookkeeping
        $ws[] = [$k, preg_replace('/"at":\d+/', '"at":0', (string)$v)];
    }
    $parts = [
        'events'   => $q('SELECT title, world_epoch, place, participants, prose FROM events ORDER BY world_epoch, title'),
        'memories' => $q('SELECT handle, text, source, world_epoch FROM memories ORDER BY handle, world_epoch, text'),
        'messages' => $q('SELECT c.handle, m.role, m.handle, m.content, m.world_epoch FROM messages m
                          JOIN conversations c ON c.id = m.conversation_id ORDER BY c.handle, m.world_epoch, m.id'),
        'arcs'     => $q('SELECT handle, key, value FROM arcs ORDER BY handle, key'),
        'state'    => $ws,
        'signals'  => $q('SELECT kind, handle, subject, n, lag, note, processed, world_epoch FROM signals ORDER BY world_epoch, kind, handle, subject'),
        'deaths'   => $q('SELECT handle, world_epoch, how FROM deaths ORDER BY handle'),
    ];
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ---------------------------------------------------------------------------
// Running
// ---------------------------------------------------------------------------

/**
 * Live the scenario. $o: days, model, seed, db (path), probe_every (hours),
 * quiet (no output). Returns the run record the probes read.
 */
function cb_run(array $loaded, array $o = []): array
{
    $T     = $loaded['T'];
    $sc    = $loaded['sc'];
    $days  = (int)($o['days'] ?? $sc['days'] ?? 3);
    $seed  = (int)($o['seed'] ?? 1);
    $model = (string)($o['model'] ?? 'standin');
    $every = max(1, (int)($o['probe_every'] ?? 3));
    $path  = (string)($o['db'] ?? (sys_get_temp_dir() . '/xeric-continuity-' . getmypid() . '-' . $model . '.db'));
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) @unlink($f);

    $t0    = cb_at($T, $sc, 'd1 00:00');
    $end   = cb_at($T, $sc, 'd' . ($days + 1) . ' 00:00');
    xeric_state_time($t0);
    $db    = xeric_state_open($path);
    xeric_state_seed($db, $T, $t0);
    xeric_world_state_set($db, 'clock_began', '0', $t0);        // world time IS the fake real time

    $run = [
        'T' => $T, 'sc' => $sc, 'db' => $db, 'db_path' => $path, 'model' => $model, 'seed' => $seed,
        'days' => $days, 'start' => $t0, 'end' => $end, 'real' => $t0, 'world_epoch' => $t0,
        'phase' => 'setup', 'calls' => [], 'builds' => [], 'actions' => [], 'events' => [], 'pings' => [],
        'digests' => [], 'daily' => [], 'skips' => [], 'learn_passes' => [], 'refusals' => [],
        'heart_learns' => cb_heart_learns(), 'started' => microtime(true), 'pending' => [],
        'kill_fingerprints' => [], 'trust_log' => [], 'seq' => 0, 'tally' => [],
    ];
    $tally0 = xeric_llm_tally();
    $endpoint = cb_endpoint($run, $model, $seed);

    // The script, in world time; policy answers join it as they are owed.
    foreach ((array)$sc['script'] as $i => $row) {
        $at = cb_at($T, $sc, (string)$row[0]);
        if ($at >= $end) continue;
        $run['pending'][] = ['at' => $at, 'i' => $i, 'kind' => (string)$row[1], 'args' => (array)($row[2] ?? []), 'spec' => (string)$row[0]];
    }

    $nextTick = $t0 + 3600;
    $lastDay  = 1;
    while ($lastDay <= $days) {
        usort($run['pending'], fn($a, $b) => [$a['at'], $a['i']] <=> [$b['at'], $b['i']]);
        $offset   = xeric_clock_offset($db);
        $nextAct  = $run['pending'] !== [] ? $run['pending'][0]['at'] - $offset : PHP_INT_MAX;
        $realNext = min($nextTick, $nextAct);

        // A day's books close once everything stamped inside it has run —
        // including the midnight tick that lives its last hour.
        $boundary = cb_at($T, $sc, 'd' . ($lastDay + 1) . ' 00:00');
        if ($realNext + $offset > $boundary) {
            if ($boundary - $offset > $run['real']) cb_set_real($run, $boundary - $offset);
            cb_close_day($run, $lastDay);
            $lastDay++;
            continue;
        }

        cb_set_real($run, max($run['real'], $realNext));
        if ($realNext === $nextAct) {
            cb_act($run, $endpoint, array_shift($run['pending']));
            continue;
        }
        cb_tick($run, $endpoint, $every);
        $nextTick += 3600;
    }

    $tally1 = xeric_llm_tally();
    $run['tally'] = ['calls' => $tally1['calls'] - $tally0['calls'], 'in' => $tally1['in'] - $tally0['in'], 'out' => $tally1['out'] - $tally0['out']];
    $run['seconds'] = round(microtime(true) - $run['started'], 2);
    xeric_state_time(null);
    return $run;
}

/** heart.php, once. */
function cb_tick(array &$run, array $endpoint, int $every): void
{
    $T = $run['T']; $db = $run['db'];
    $run['phase'] = 'heart';
    $tb = cb_trusts($run);
    try { xeric_constructs_tick($T, $db, cb_now($run)); } catch (Throwable $e) { $run['refusals'][] = ['heart', 'constructs', $e->getMessage()]; }
    cb_trust_note($run, 'constructs', $tb);

    if (!xeric_clock_is_paused($db)) {
        $now  = cb_now($run);
        $here = intdiv((int)$now['epoch'], CB_WINDOW);
        $last = cb_last_window($db, CB_WINDOW);
        if ($last === 0) $last = $here - 1;
        if ($here > $last) {
            $run['phase'] = 'sweep';
            $evBefore = count($run['events']);
            $raw = [];
            for ($w = $last + 1; $w <= min($here, $last + CB_HEART_MAX_WINDOWS); $w++) {
                $r = xeric_sweep_catchup($T, $db, $endpoint, $w * CB_WINDOW, $w * CB_WINDOW,
                    ['window' => CB_WINDOW, 'clock' => (int)$now['epoch'], 'stories' => [],
                     'seed' => crc32($run['seed'] . '|w|' . $w)]);
                cb_take_events($run, (array)($r['events'] ?? []), 'heart');
                foreach ((array)($r['events'] ?? []) as $e) $raw[] = $e;
            }
            $run['phase'] = 'proactive';
            $after = cb_now($run);
            $hour  = intdiv((int)$after['epoch'], 3600);
            try {
                $ping = xeric_proactive_check($T, $db, $endpoint, $after,
                    ['stories' => [], 'seed' => crc32($run['seed'] . '|p|' . $hour)]);
            } catch (Throwable $e) { $ping = null; $run['refusals'][] = ['heart', 'proactive', $e->getMessage()]; }
            if ($ping !== null) cb_take_ping($run, $ping);

            if ($run['heart_learns']) {
                // heart.php's own learn pass, by the engine function it calls.
                $run['phase'] = 'learn';
                if (function_exists('xeric_learn_pass')) {
                    cb_learn_pass($run, $endpoint, 'heart', ['no_model' => false, 'timeout' => 60],
                        fn() => xeric_learn_pass($db, $T, $endpoint, (int)$now['epoch'], $raw, $ping, (int)$after['epoch'],
                                                 ['no_model' => false, 'timeout' => 60]));
                } else {
                    cb_learn_pass($run, $endpoint, 'heart', ['no_model' => false]);
                }
            }
        }
    }
    $run['phase'] = 'probe';
    $now = cb_now($run);
    if ((int)substr((string)$now['hhmm'], 0, 2) % $every === 0) cb_probe_build($run, $now);
}

/** heart.php's xeric_heart_last_window, verbatim in effect. */
function cb_last_window(PDO $db, int $size): int
{
    $pre = 'sweep:' . $size . ':';
    $max = 0;
    foreach (xeric_world_state_all($db) as $k => $_) {
        if (!str_starts_with((string)$k, $pre)) continue;
        $n = (int)substr((string)$k, strlen($pre));
        if ($n > $max) $max = $n;
    }
    return $max;
}

function cb_take_events(array &$run, array $events, string $via): void
{
    foreach ($events as $e) {
        $run['events'][] = [
            'id' => (int)($e['id'] ?? 0), 'title' => (string)($e['title'] ?? ''), 'kind' => (string)($e['kind'] ?? ''),
            'epoch' => (int)($e['world_epoch'] ?? 0), 'place' => isset($e['place']) ? (string)$e['place'] : (isset($e['where']) ? (string)$e['where'] : null),
            'participants' => array_values(array_map('strval', array_keys((array)($e['memories'] ?? [])) ?: (array)($e['participants'] ?? []))),
            'via' => $via, 'day' => cb_day($run['T'], $run['sc'], (int)($e['world_epoch'] ?? 0)),
        ];
    }
}

/** A ping the world invented, and the policy's answer to it. */
function cb_take_ping(array &$run, array $ping): void
{
    $h   = (string)($ping['handle'] ?? '');
    $pol = (array)($run['sc']['policy'] ?? []);
    $answer = (string)($pol['answer'][$h] ?? '');
    $run['pings'][] = ['epoch' => (int)$run['world_epoch'], 'handle' => $h, 'answered' => $answer !== '',
                       'cold_open' => !empty($ping['cold_open']), 'dream' => !empty($ping['dream']),
                       'phase' => $run['phase'], 'chars' => mb_strlen((string)($ping['text'] ?? ''))];
    if ($answer !== '') {
        $run['pending'][] = ['at' => (int)$run['world_epoch'] + 60 * (int)($pol['after_minutes'] ?? 20), 'i' => 1000 + count($run['pings']),
                             'kind' => 'say', 'args' => ['to' => $h, 'text' => $answer, 'policy' => true], 'spec' => 'policy'];
    }
}

/** One scripted action. */
function cb_act(array &$run, array $endpoint, array $act): void
{
    $T = $run['T']; $db = $run['db'];
    $a = $act['args'];
    $log = ['seq' => $run['seq']++, 'at' => (int)$run['world_epoch'], 'spec' => $act['spec'], 'kind' => $act['kind'], 'args' => $a, 'ok' => true, 'note' => ''];
    $run['phase'] = $act['kind'];
    $tb = cb_trusts($run);
    try {
        switch ($act['kind']) {
            case 'travel':
                $r = xeric_travel_go($T, $db, isset($a['to']) ? (string)$a['to'] : null);
                $log['ok'] = (bool)$r['ok'];
                $log['note'] = (string)($r['error'] ?? '');
                break;
            case 'say':
                $to  = (string)$a['to'];
                $now = cb_now($run);
                try {
                    $out = xeric_chat_turn($T, $db, $to, (string)$a['text'], $now, $endpoint, ['stories' => []]);
                    $run['phase'] = 'extract';
                    try {
                        xeric_chat_extract($T, $db, $to, (int)$out['conversation_id'], $endpoint, cb_now($run));
                    } catch (Throwable $e) { $log['note'] = 'extract: ' . $e->getMessage(); }
                    $log['reply_chars'] = mb_strlen((string)$out['text']);
                } catch (Throwable $e) {
                    $log['ok'] = false;
                    $log['refused'] = true;
                    $log['note'] = $e->getMessage();
                }
                break;
            case 'kill':
                $now   = cb_now($run);
                $place = xeric_world_who_is_where($T, $now, xeric_dead_handles($db))[(string)$a['who']]['where'] ?? null;
                $fp    = fn() => $place === null ? null : (string)(xeric_scene_brief($T, $db, (string)$place, $now)['fingerprint'] ?? '');
                $before = $fp();
                $r = xeric_death_kill($T, $db, (string)$a['who'], (int)$run['world_epoch'], (string)($a['how'] ?? ''));
                $run['kill_fingerprints'][] = ['who' => (string)$a['who'], 'place' => $place, 'before' => $before, 'after' => $fp()];
                $log['ok'] = (bool)$r['ok'];
                break;
            case 'skip':
                $pre = cb_skip($run, $endpoint, 3600 * (int)$a['hours'], $run['seed']);
                if (!empty($a['reroll'])) {
                    $run['phase'] = 'rewind';
                    $rw = xeric_rewind($T, $db);
                    $back = cb_digest($db);
                    $log['rewind'] = ['ok' => (bool)$rw['ok'], 'equal' => $back === $pre, 'why' => $rw['why']];
                    cb_set_real($run, $run['real']);
                    cb_skip($run, $endpoint, 3600 * (int)$a['hours'], $run['seed'] + 1);
                }
                break;
            default:
                throw new RuntimeException('unknown action ' . $act['kind']);
        }
    } catch (Throwable $e) {
        $log['ok'] = false;
        $log['note'] = $e->getMessage();
    }
    $run['world_epoch'] = xeric_clock_epoch($db);
    cb_trust_note($run, $act['kind'], $tb);
    $run['actions'][] = $log;
}

/** What one operation did to trust, per handle, when it did anything. */
function cb_trust_note(array &$run, string $via, array $before): void
{
    $after = cb_trusts($run);
    $d = [];
    foreach ($after as $h => $v) if ($v !== ($before[$h] ?? 0)) $d[$h] = $v - ($before[$h] ?? 0);
    if ($d !== []) $run['trust_log'][] = ['at' => (int)$run['world_epoch'], 'via' => $via, 'delta' => $d];
}

/** tick-worker.php, once. Returns the digest taken just before the mark. */
function cb_skip(array &$run, array $endpoint, int $span, int $seed): string
{
    $T = $run['T']; $db = $run['db'];
    $run['phase'] = 'skip';
    $before = cb_now($run);
    try { xeric_learn_settle($db, (int)$before['epoch']); } catch (Throwable $e) { }
    $pre  = cb_digest($db);
    $mark = xeric_rewind_mark($db);
    $after = xeric_clock_advance($db, $span, $T);
    $run['world_epoch'] = (int)$after['epoch'];

    $events = [];
    $firstW = intdiv((int)$before['epoch'], CB_WINDOW);
    $lastW  = intdiv((int)$after['epoch'], CB_WINDOW);
    $walked = 0;
    for ($win = $firstW; $win <= $lastW; $win++) {
        if (count($events) >= CB_SKIP_MAX_EVENTS) break;
        if ($walked++ >= CB_SKIP_MAX_WINDOWS) break;
        $r = xeric_sweep_catchup($T, $db, $endpoint, $win * CB_WINDOW, $win * CB_WINDOW, [
            'clock' => (int)$after['epoch'], 'chance' => 1.0, 'max_events' => 1, 'max_windows' => 1,
            'temperature' => 0.9, 'stories' => [], 'seed' => crc32($seed . '|w|' . $win)]);
        foreach ($r['events'] as $e) $events[] = $e;
    }
    cb_take_events($run, $events, 'skip');

    $run['phase'] = 'proactive';
    $ping = null;
    foreach ($events !== [] ? array_reverse($events) : [null] as $cand) {
        try {
            $ping = xeric_proactive_check($T, $db, $endpoint, $after, ['event' => $cand, 'chance' => 1.0,
                'temperature' => 0.95, 'stories' => [], 'seed' => crc32($seed . '|p|' . intdiv((int)$after['epoch'], 3600))]);
        } catch (Throwable $e) { $ping = null; }
        if ($ping !== null) break;
    }
    if ($ping !== null) cb_take_ping($run, $ping);

    try { xeric_learn_pend($db, $events, $ping, (int)$after['epoch']); } catch (Throwable $e) { }
    if (xeric_learn_due($db)) {
        $run['phase'] = 'learn';
        cb_learn_pass($run, $endpoint, 'skip', ['timeout' => 60]);
    }
    try { xeric_rewind_commit($db, $mark); } catch (Throwable $e) { }
    $run['skips'][] = ['at' => (int)$before['epoch'], 'span' => $span, 'seed' => $seed, 'events' => count($events), 'pre' => $pre];
    return $pre;
}

/** A distil pass, with trust before and after written down — the log trust is recomputed from. */
function cb_learn_pass(array &$run, array $endpoint, string $via, array $opts, ?callable $how = null): void
{
    $T = $run['T']; $db = $run['db'];
    $before = cb_trusts($run);
    try { $r = $how !== null ? (array)$how() : xeric_lessons_distil($db, $T, $endpoint, $opts); }
    catch (Throwable $e) { $r = ['signals' => 0, 'notes' => [$e->getMessage()]]; }
    $run['learn_passes'][] = ['seq' => $run['seq']++, 'at' => (int)$run['world_epoch'], 'via' => $via, 'signals' => (int)($r['signals'] ?? 0),
                              'distilled' => !empty($r['distilled']) || (int)($r['signals'] ?? 0) > 0,
                              'before' => $before, 'after' => cb_trusts($run)];
}

function cb_trusts(array $run): array
{
    $out = [];
    foreach ((array)$run['T']['cast']['characters'] as $c) $out[(string)$c['handle']] = xeric_trust_of($run['db'], (string)$c['handle']);
    return $out;
}

/** Every living character's prompt, as it would be built right now. */
function cb_probe_build(array &$run, array $now): void
{
    $T = $run['T']; $db = $run['db'];
    $dead = xeric_deaths($db);
    $lessons = ['' => xeric_lessons_read($db, xeric_arc_world())];
    foreach ((array)$T['cast']['characters'] as $c) $lessons[(string)$c['handle']] = xeric_lessons_read($db, (string)$c['handle']);
    foreach ((array)$T['cast']['characters'] as $c) {
        $h = (string)$c['handle'];
        if (isset($dead[$h])) continue;
        $msgs = xeric_prompt_build($T, $db, $h, $now, []);
        $run['builds'][] = ['epoch' => (int)$now['epoch'], 'day' => cb_day($T, $run['sc'], (int)$now['epoch']),
                            'handle' => $h, 'messages' => $msgs, 'lessons' => $lessons];
    }
}

/** The day's books: digest, media seeds, fingerprints, trust, the learn tally. */
function cb_close_day(array &$run, int $day): void
{
    if (isset($run['digests'][$day])) return;
    $T = $run['T']; $db = $run['db'];
    $run['digests'][$day] = cb_digest($db);

    $seeds = [];
    foreach ((array)$T['places'] as $p) $seeds['place.' . $p['key']] = xeric_photo_place_seeds($T, (string)$p['key']);
    foreach ((array)$T['cast']['characters'] as $c) {
        $id = xeric_photo_identity($T, (string)$c['handle']);
        $seeds['face.' . $c['handle']] = $id === null ? null : [$id['face_seed'], $id['body_seed']];
    }
    $tallies = [];
    foreach ((array)$T['cast']['characters'] as $c) {
        $h = (string)$c['handle'];
        $tallies[$h] = xeric_learn_tally($db, $h) + ['reach' => xeric_learn_reach($db, $h)];
    }
    $run['daily'][$day] = [
        'seeds' => hash('sha256', json_encode($seeds)), 'seeds_raw' => $seeds, 'trust' => cb_trusts($run),
        'tally' => $tallies, 'open_signals' => xeric_signals_count($db, null, true),
        'expects' => array_combine(array_map(fn($c) => (string)$c['handle'], (array)$T['cast']['characters']),
                    array_map(fn($c) => xeric_expects_for($db, (string)$c['handle']), (array)$T['cast']['characters'])),
    ];
}
