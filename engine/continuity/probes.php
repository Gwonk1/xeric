<?php
/**
 * Xeric continuity benchmark — the probes.
 *
 * Seven classes, every probe a function of the run record returning
 * {id, class, severity, pass, score, evidence}. Evidence cites wall paths, row
 * ids, capture numbers and prompt hashes — NEVER the secret text, so a
 * scorecard can be pasted anywhere without becoming the leak it reports.
 *
 *   walls          quotation (hard), at rest (hard), table seats (hard),
 *                  telltale words (soft), oracle coverage (hard)
 *   recall         per fact: which part of the prompt still carries it
 *   presence       the dead, located participants, hours, the mill, quiet
 *                  hours, the narrator's audit, minimum activity
 *   relationships  a promise missed then repaired, a promise kept, trust
 *                  recomputed from the log
 *   media          seeds, fingerprints by minute, fingerprints by death
 *   cost           calls / characters / tokens per day and phase; cache breaks
 *   learn          tally against the script, the heart's learn pass, buckets
 */

declare(strict_types=1);

require_once __DIR__ . '/oracle.php';

function cb_probe(string $id, string $class, string $severity, bool $pass, float $score, array $evidence): array
{
    return ['id' => $id, 'class' => $class, 'severity' => $severity, 'pass' => $pass,
            'score' => round($score, 3), 'evidence' => $evidence];
}

function cb_hay(array $messages): string
{
    return implode("\n", array_map(fn($m) => (string)($m['content'] ?? ''), $messages));
}

function cb_h(string $s): string { return substr(hash('sha256', $s), 0, 12); }

/** Every captured prompt, as [viewer, hay, where]. A sweep prompt counts against everybody in it. */
function cb_prompts(array $run): array
{
    $out = [];
    foreach ($run['calls'] as $c) {
        $hay = cb_hay($c['messages']);
        foreach ($c['viewers'] as $v) $out[] = [$v, $hay, 'call#' . $c['n'] . ':' . $c['tag']];
    }
    foreach ($run['builds'] as $i => $b) $out[] = [$b['handle'], cb_hay($b['messages']), 'build#' . $i];
    return $out;
}

// ---------------------------------------------------------------------------
// 1. Walls
// ---------------------------------------------------------------------------

function cb_probe_walls(array $run): array
{
    $T = $run['T']; $sc = $run['sc'];
    $out = [];

    // W1 — six words in a row, in any captured prompt.
    $hits = []; $checked = 0; $seen = [];
    foreach (cb_prompts($run) as [$viewer, $hay, $where]) {
        $k = $viewer . '|' . md5($hay);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $checked++;
        foreach (cb_quotes($T, $sc, $viewer, $hay) as $path) {
            $hits[] = ['viewer' => $viewer, 'path' => $path, 'where' => $where, 'prompt' => cb_h($hay)];
        }
    }
    $out[] = cb_probe('walls.quote', 'walls', 'hard', $hits === [], $checked ? 1 - count($hits) / $checked : 1.0,
        ['prompts_checked' => $checked, 'quotations' => count($hits), 'hits' => array_slice($hits, 0, 25)]);

    // W2 — stored memories, at rest.
    $rest = []; $n = 0;
    foreach ((array)$T['cast']['characters'] as $c) {
        $h = (string)$c['handle'];
        $st = $run['db']->prepare('SELECT id, text FROM memories WHERE handle = ? ORDER BY id');
        $st->execute([$h]);
        foreach ($st->fetchAll() as $m) {
            $n++;
            foreach (cb_quotes($T, $sc, $h, (string)$m['text']) as $path) $rest[] = ['viewer' => $h, 'path' => $path, 'memory_id' => (int)$m['id']];
        }
    }
    $out[] = cb_probe('walls.rest', 'walls', 'hard', $rest === [], $n ? 1 - count($rest) / $n : 1.0,
        ['memories_checked' => $n, 'hits' => array_slice($rest, 0, 25)]);

    // W3 — no legal group seats anybody at a table whose economy their walls hide.
    $tables = xeric_tables($T);
    $bad = []; $hours = 0;
    for ($e = $run['start']; $e < $run['end']; $e += 3600) {
        $now = xeric_world_now($T, $e + 1800);
        foreach ($tables as $place => $tb) {
            if (!xeric_table_tonight($tb, $now)) continue;
            $hours++;
            $eco = (string)($tb['economy'] ?? '');
            mt_srand(crc32($run['seed'] . '|g|' . $e));
            foreach (xeric_sweep_groups($T, $now, []) as $g) {
                if ((string)($g['where'] ?? '') !== (string)$place) continue;
                foreach ($g['handles'] as $h) {
                    $walls = xeric_viewer_walls($T, xeric_viewer($T, ['handle' => $h]));
                    if ($eco !== '' && xeric_hidden($walls, 'economies.' . $eco)) {
                        $k = $h . '@' . $place . ':' . implode(',', $g['handles']) . ':' . (str_contains((string)($g['why'] ?? ''), 'was open') ? 'v' : 'f');
                        $at = xeric_world_day_name((int)$now['dow']) . ' ' . $now['hhmm'];
                        $bad[$k] ??= ['viewer' => $h, 'place' => $place, 'group' => $g['handles'], 'why' => (string)($g['why'] ?? ''), 'hours' => 0, 'first' => $at];
                        $bad[$k]['hours']++;
                        $bad[$k]['last'] = $at;
                    }
                }
            }
        }
    }
    $seated = array_sum(array_column($bad, 'hours'));
    // a group with somebody already at work there first: those are the ones every draw can pick
    uasort($bad, fn($a, $b) => [str_contains($a['why'], 'was open') ? 0 : 1, -$a['hours']] <=> [str_contains($b['why'], 'was open') ? 0 : 1, -$b['hours']]);
    $out[] = cb_probe('walls.table', 'walls', 'hard', $bad === [], $hours ? 1 - min(1, $seated / $hours) : 1.0,
        ['table_hours' => $hours, 'seatings' => array_slice(array_values(array_map(fn($x) => array_diff_key($x, ['why' => 1]) + ['kind' => str_contains($x['why'], 'was open') ? 'visit' : 'both free'], $bad)), 0, 8)]);

    // W4 — soft: words that exist only inside what this viewer may not see.
    $soft = []; $seen = [];
    foreach (cb_prompts($run) as [$viewer, $hay, $where]) {
        $k = $viewer . '|' . md5($hay);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $words = array_flip(xeric_wall_words(cb_allowed($sc, $viewer, $hay)));
        foreach (cb_telltales($T, $sc, $viewer) as $w) {
            if (isset($words[$w])) $soft[$viewer][cb_h($w)] = true;
        }
    }
    $flags = array_sum(array_map('count', $soft));
    $out[] = cb_probe('walls.telltale', 'walls', 'soft', $flags === 0, 1 / (1 + $flags),
        ['flags' => $flags, 'by_viewer' => array_map(fn($a) => array_keys($a), $soft)]);

    // W5 — the oracle covers render-test's hand-written list.
    $cov = cb_oracle_covers($T, $sc);
    $out[] = cb_probe('walls.coverage', 'walls', 'hard', $cov['total'] > 0 && $cov['missing'] === [],
        $cov['total'] ? $cov['covered'] / $cov['total'] : 0.0, $cov);
    return $out;
}

// ---------------------------------------------------------------------------
// 2. Recall
// ---------------------------------------------------------------------------

/** The parts of an assembled prompt, by the names the scenario uses. */
function cb_parts(array $messages): array
{
    $sys = (string)($messages[0]['content'] ?? '');
    $parts = ['system' => $sys];
    foreach (preg_split('/\n\n/', $sys) as $block) {
        $title = strtok($block, "\n");
        foreach (['WHAT YOU REMEMBER', 'WHAT YOU ARE OWED', 'WHAT YOU HAVE WORKED OUT'] as $t) {
            if (str_starts_with((string)$title, $t)) $parts[$t] = ($parts[$t] ?? '') . $block;
        }
    }
    $rest = array_slice($messages, 1);
    $last = array_pop($rest);
    $lc = (string)($last['content'] ?? '');
    $cut = strpos($lc, 'RIGHT NOW (');
    $parts['RIGHT NOW'] = $cut === false ? '' : substr($lc, $cut);
    $parts['transcript'] = cb_hay($rest) . "\n" . ($cut === false ? $lc : substr($lc, 0, $cut));
    return $parts;
}

function cb_probe_recall(array $run): array
{
    $T = $run['T']; $sc = $run['sc'];
    $out = [];
    $dead = xeric_deaths($run['db']);
    foreach ((array)($sc['facts'] ?? []) as $f) {
        $said = cb_at($T, $sc, (string)$f['said']);
        $want = (array)$f['parts'];
        $kw   = array_map('mb_strtolower', (array)$f['keywords']);
        $holders = (string)$f['holder'] === '*'
            ? array_values(array_filter(array_map(fn($c) => (string)$c['handle'], $T['cast']['characters']), fn($h) => !isset($dead[$h])))
            : [(string)$f['holder']];
        $n = 0; $carried = 0; $byDay = []; $partsSeen = []; $lastCarried = null; $firstMiss = null;
        foreach ($run['builds'] as $i => $b) {
            if ($b['epoch'] < $said || !in_array($b['handle'], $holders, true)) continue;
            $n++;
            $in = [];
            foreach (cb_parts($b['messages']) as $name => $text) {
                $lt = mb_strtolower($text);
                $all = true;
                foreach ($kw as $k) if (!str_contains($lt, $k)) { $all = false; break; }
                if ($all && $name !== 'system') $in[] = $name;
            }
            $ok = array_intersect($in, $want) !== [];
            $byDay[$b['day']] = ($byDay[$b['day']] ?? [0, 0]);
            $byDay[$b['day']][1]++;
            if ($ok) { $carried++; $byDay[$b['day']][0]++; $lastCarried = $b['epoch']; foreach ($in as $p) $partsSeen[$p] = ($partsSeen[$p] ?? 0) + 1; }
            elseif ($firstMiss === null) $firstMiss = 'build#' . $i . ':' . $b['handle'];
        }
        $out[] = cb_probe('recall.' . $f['id'], 'recall', 'soft', $n > 0 && $carried === $n, $n ? $carried / $n : 0.0, [
            'builds' => $n, 'carried' => $carried, 'by_day' => array_map(fn($d) => $d[0] . '/' . $d[1], $byDay),
            'parts' => $partsSeen, 'survived_hours' => $lastCarried === null ? 0 : intdiv($lastCarried - $said, 3600),
            'first_miss' => $firstMiss,
        ]);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// 3. Presence
// ---------------------------------------------------------------------------

function cb_probe_presence(array $run): array
{
    $T = $run['T']; $sc = $run['sc']; $db = $run['db'];
    $out = [];
    $deaths = xeric_deaths($db);
    $diedAt = [];
    foreach ($deaths as $h => $d) $diedAt[$h] = (int)($d['world_epoch'] ?? 0);

    // The dead: absent from every hour and phone after, named in every living prompt, refused, released.
    $ghost = [];
    foreach ($run['events'] as $e) foreach ($e['participants'] as $p) if (isset($diedAt[$p]) && $e['epoch'] >= $diedAt[$p]) $ghost[] = ['event' => $e['id'], 'who' => $p];
    foreach ($run['pings'] as $p) if (isset($diedAt[$p['handle']]) && $p['epoch'] >= $diedAt[$p['handle']]) $ghost[] = ['ping_at' => $p['epoch'], 'who' => $p['handle']];
    $out[] = cb_probe('presence.dead_absent', 'presence', 'hard', $ghost === [], $ghost === [] ? 1.0 : 0.0, ['deaths' => array_keys($diedAt), 'after' => $ghost]);

    $need = 0; $have = 0; $miss = [];
    foreach ($run['builds'] as $i => $b) {
        foreach ($diedAt as $h => $at) {
            if ($b['epoch'] < $at || $b['handle'] === $h) continue;
            $need++;
            $rn = cb_parts($b['messages'])['RIGHT NOW'];
            if (str_contains($rn, 'Dead, and everybody here knows it') && str_contains($rn, xeric_world_name($T, $h))) $have++;
            elseif (count($miss) < 10) $miss[] = 'build#' . $i . ':' . $b['handle'];
        }
    }
    $out[] = cb_probe('presence.death_line', 'presence', 'hard', $need === $have, $need ? $have / $need : 1.0, ['prompts' => $need, 'carry' => $have, 'missing' => $miss]);

    $tries = array_values(array_filter($run['actions'], fn($a) => $a['kind'] === 'say' && ($a['args']['expect'] ?? '') === 'refused'));
    $refused = array_filter($tries, fn($a) => !empty($a['refused']) && str_contains((string)$a['note'], 'dead'));
    $out[] = cb_probe('presence.dead_refused', 'presence', 'hard', count($tries) > 0 && count($refused) === count($tries),
        $tries ? count($refused) / count($tries) : 0.0, ['tries' => count($tries), 'refused' => count($refused)]);

    $open = [];
    foreach ($diedAt as $h => $at) foreach (xeric_expects_for($db, $h) as $e) if ($e['state'] === 'open') $open[] = $h . ':' . $e['key'];
    $out[] = cb_probe('presence.released', 'presence', 'hard', $open === [], $open === [] ? 1.0 : 0.0, ['still_open' => $open]);

    // Located: everybody in an hour was at its place on their week, or free.
    $bad = [];
    foreach ($run['events'] as $e) {
        $now = xeric_world_now($T, $e['epoch']);
        $pres = xeric_world_who_is_where($T, $now, array_keys(array_filter($diedAt, fn($at) => $at <= $e['epoch'])));
        foreach ($e['participants'] as $p) {
            $w = $pres[$p]['where'] ?? null;
            if (!isset($pres[$p]) || ($w !== null && empty($pres[$p]['at_home']) && $w !== $e['place'])) $bad[] = ['event' => $e['id'], 'who' => $p, 'was' => $w];
        }
    }
    $out[] = cb_probe('presence.located', 'presence', 'hard', $bad === [], $run['events'] ? 1 - count($bad) / max(1, count($run['events'])) : 1.0,
        ['events' => count($run['events']), 'misplaced' => array_slice($bad, 0, 10)]);

    // Hours: the authored truth, against the hours lived AND against how the engine reads the place.
    $viol = []; $checked = 0;
    foreach ((array)($sc['truth']['closed'] ?? []) as $cl) {
        $p = xeric_world_place($T, (string)$cl['place']);
        if ($p === null) continue;
        $from = xeric_world_minutes((string)$cl['from']) ?? 0;
        $to   = $cl['to'] === '24:00' ? 1440 : (xeric_world_minutes((string)$cl['to']) ?? 1440);
        for ($e = $run['start']; $e < $run['end']; $e += 1800) {
            $now = xeric_world_now($T, $e);
            $m = xeric_world_minutes((string)$now['hhmm']) ?? 0;
            if (!in_array((int)$now['dow'], array_map('intval', (array)$cl['days']), true) || $m < $from || $m >= $to) continue;
            $checked++;
            if (xeric_sweep_place_open((array)($p['hours'] ?? []), $m, (int)$now['dow'])) {
                $viol[$cl['place'] . ' reads open'][] = xeric_world_day_name((int)$now['dow']) . ' ' . $now['hhmm'];
            }
        }
        foreach ($run['events'] as $ev) {
            $now = xeric_world_now($T, $ev['epoch']);
            $m = xeric_world_minutes((string)$now['hhmm']) ?? 0;
            if ($ev['place'] === $cl['place'] && in_array((int)$now['dow'], array_map('intval', (array)$cl['days']), true) && $m >= $from && $m < $to) {
                $viol[$cl['place'] . ' hosted an hour'][] = 'event#' . $ev['id'];
            }
        }
    }
    $nv = array_sum(array_map('count', $viol));
    $out[] = cb_probe('presence.hours', 'presence', 'hard', $nv === 0, $checked ? 1 - min(1, $nv / $checked) : 1.0,
        ['half_hours_checked' => $checked, 'violations' => array_map(fn($l) => ['n' => count($l), 'first' => $l[0], 'last' => end($l)], $viol)]);

    $mill = array_values(array_filter($run['events'], fn($e) => $e['place'] === 'the_mill'));
    $out[] = cb_probe('presence.mill', 'presence', 'hard', $mill === [], $mill === [] ? 1.0 : 0.0, ['events' => array_column($mill, 'id')]);

    // Quiet hours: no waking text, no hour of a kind that may not wake anybody.
    $why = null;
    $q = xeric_sweep_quiet_window((string)($T['user']['quiet_hours'] ?? ''), $why);
    $kinds = xeric_sweep_kinds();
    $loud = [];
    $inQuiet = function (int $epoch) use ($T): bool { return xeric_sweep_quiet($T, xeric_world_now($T, $epoch)); };
    foreach ($run['pings'] as $p) if (!$p['dream'] && $inQuiet($p['epoch'])) $loud[] = ['ping' => $p['handle'], 'at' => xeric_world_now($T, $p['epoch'])['hhmm']];
    foreach ($run['events'] as $e) if ($inQuiet($e['epoch']) && empty($kinds[$e['kind']]['quiet'])) $loud[] = ['event' => $e['id'], 'kind' => $e['kind']];
    $out[] = cb_probe('presence.quiet', 'presence', 'hard', $loud === [], $loud === [] ? 1.0 : 0.0, ['window' => $q, 'woke' => $loud]);

    // The narrator's own arithmetic, counted against.
    $audit = xeric_narrator_audit($T, $db, xeric_world_now($T, $run['end'] - 60), ['days' => $run['days']]);
    $contra = array_values(array_filter((array)($audit['observations'] ?? []), fn($o) => ($o['kind'] ?? '') === 'contradiction'));
    $out[] = cb_probe('presence.audit', 'presence', 'soft', $contra === [], 1 / (1 + count($contra)),
        ['contradictions' => count($contra), 'cites' => array_slice(array_map(fn($o) => $o['cites'], $contra), 0, 10)]);

    // A run that passes by doing nothing has not passed.
    $min = (array)($sc['min_activity'] ?? []);
    $have = [
        'events'   => count($run['events']),
        'memories' => (int)$db->query('SELECT COUNT(*) FROM memories')->fetchColumn(),
        'replies'  => count(array_filter($run['actions'], fn($a) => $a['kind'] === 'say' && $a['ok'])),
    ];
    $short = [];
    foreach ($min as $k => $n) if (($have[$k] ?? 0) < (int)$n) $short[$k] = $have[$k] ?? 0;
    $out[] = cb_probe('presence.activity', 'presence', 'hard', $short === [], $short === [] ? 1.0 : 0.0, ['have' => $have, 'want' => $min, 'short' => $short]);
    return $out;
}

// ---------------------------------------------------------------------------
// 4. Relationships
// ---------------------------------------------------------------------------

/** The expectation a scripted promise formed, found by when it was formed. */
function cb_expect_of(array $run, array $p): ?array
{
    $at = cb_at($run['T'], $run['sc'], (string)$p['said']);
    foreach (xeric_expects_for($run['db'], (string)$p['holder']) as $e) {
        if (abs((int)($e['formed'] ?? 0) - $at) < 1800) return $e;
    }
    return null;
}

function cb_probe_relationships(array $run): array
{
    $T = $run['T']; $sc = $run['sc'];
    $out = [];
    $days = $run['daily'];
    foreach ((array)($sc['promises'] ?? []) as $p) {
        $e = cb_expect_of($run, $p);
        $h = (string)$p['holder'];
        $trail = array_map(fn($d) => $d['trust'][$h] ?? null, $days);
        if ($p['expect'] === 'missed_then_repaired') {
            // From the trust log: the fuse took a notch, and the apology gave one back.
            $miss = null; $mend = null;
            foreach ($run['trust_log'] as $i => $t) {
                $d = (int)($t['delta'][$h] ?? 0);
                if ($t['via'] === 'constructs' && $d === -1 && $miss === null) $miss = $i;
                if ($t['via'] === 'say' && $d === 1 && $miss !== null && $mend === null) $mend = $i;
            }
            $ok = $e !== null && $e['state'] === 'repaired' && $miss !== null && $mend !== null;
            $out[] = cb_probe('relationships.repair', 'relationships', 'hard', $ok, $ok ? 1.0 : 0.0,
                ['holder' => $h, 'state' => $e['state'] ?? null,
                 'missed' => $miss === null ? null : xeric_world_now($T, $run['trust_log'][$miss]['at'])['iso'],
                 'repaired' => $mend === null ? null : xeric_world_now($T, $run['trust_log'][$mend]['at'])['iso'],
                 'trust_by_day' => $trail]);
        } elseif ($p['expect'] === 'kept') {
            $waited = (int)$run['db']->query("SELECT COUNT(*) FROM memories WHERE handle = " . $run['db']->quote($h)
                . " AND source = 'construct' AND text LIKE '%did not come%'")->fetchColumn();
            $dropped = false;
            $prev = null;
            foreach ($trail as $v) { if ($prev !== null && $v < $prev) $dropped = true; $prev = $v; }
            $ok = $e !== null && !in_array($e['state'], ['missed', 'hardened', 'repaired'], true) && $waited === 0 && !$dropped;
            $out[] = cb_probe('relationships.kept', 'relationships', 'hard', $ok, $ok ? 1.0 : 0.0,
                ['holder' => $h, 'state' => $e['state'] ?? null, 'waited_memories' => $waited, 'trust_dropped' => $dropped, 'trust_by_day' => $trail]);
        }
    }

    // trust.of, recomputed from the log: start + what the constructs did + what the learn passes did.
    $drift = [];
    foreach ((array)$T['cast']['characters'] as $c) {
        $h = (string)$c['handle'];
        $calc = (int)($c['trust_start'] ?? 0);
        foreach (xeric_expects_for($run['db'], $h) as $e) {
            if (!empty($e['of'])) continue;
            $calc += match ($e['state']) { 'missed', 'hardened' => -1, 'kept' => 1, default => 0 };
        }
        foreach (cb_standing_passes($run) as $lp) $calc += ($lp['after'][$h] ?? 0) - ($lp['before'][$h] ?? 0);
        $real = xeric_trust_of($run['db'], $h);
        if ($real !== $calc) $drift[] = ['handle' => $h, 'trust' => $real, 'recomputed' => $calc];
    }
    $out[] = cb_probe('relationships.trust_log', 'relationships', 'hard', $drift === [], 1 - count($drift) / max(1, count($T['cast']['characters'])), ['drift' => $drift]);
    return $out;
}

/** Learn passes still standing: a rerolled skip's pass was rewound with it. */
function cb_standing_passes(array $run): array
{
    $out = [];
    $seen = [];
    foreach ($run['learn_passes'] as $lp) {
        if ($lp['via'] === 'skip') {
            $n = ($seen[$lp['at']] = ($seen[$lp['at']] ?? 0) + 1);
            // of N passes at the same skip moment only the last survived the rewinds
            $total = count(array_filter($run['learn_passes'], fn($x) => $x['via'] === 'skip' && $x['at'] === $lp['at']));
            if ($n < $total) continue;
        }
        $out[] = $lp;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// 5. Media
// ---------------------------------------------------------------------------

function cb_probe_media(array $run): array
{
    $T = $run['T']; $sc = $run['sc'];
    $out = [];
    $hashes = array_map(fn($d) => $d['seeds'], $run['daily']);
    $out[] = cb_probe('media.seeds', 'media', 'hard', count(array_unique($hashes)) === 1, count(array_unique($hashes)) === 1 ? 1.0 : 0.0,
        ['days' => count($hashes), 'distinct' => count(array_unique($hashes)), 'hash' => cb_h((string)reset($hashes))]);

    // Same hour, two minutes, same people: one fingerprint.
    $pairs = 0; $diff = [];
    foreach ((array)$T['places'] as $p) {
        $key = (string)$p['key'];
        for ($d = 1; $d <= $run['days']; $d++) foreach (['07', '10', '15', '20'] as $hh) {
            $a = xeric_world_now($T, cb_at($T, $sc, "d$d $hh:10"));
            $b = xeric_world_now($T, cb_at($T, $sc, "d$d $hh:40"));
            $dead = array_keys(array_filter(xeric_deaths($run['db']), fn($x) => (int)$x['world_epoch'] <= $a['epoch']));
            $wa = xeric_world_who_is_at(xeric_world_who_is_where($T, $a, $dead), $key);
            $wb = xeric_world_who_is_at(xeric_world_who_is_where($T, $b, $dead), $key);
            if ($wa !== $wb || $a['phase'] !== $b['phase']) continue;
            $fa = cb_fingerprint($run, $key, $a, $dead);
            $fb = cb_fingerprint($run, $key, $b, $dead);
            if ($fa === null || $fb === null) continue;
            $pairs++;
            if ($fa !== $fb) $diff[] = $key . ' d' . $d . ' ' . $hh;
        }
    }
    $out[] = cb_probe('media.fingerprint_minute', 'media', 'hard', $pairs > 0 && $diff === [], $pairs ? 1 - count($diff) / $pairs : 0.0,
        ['pairs' => $pairs, 'changed' => $diff]);

    $k = $run['kill_fingerprints'] ?? [];
    $ok = $k !== [] && array_filter($k, fn($x) => $x['before'] !== null && $x['before'] !== $x['after']) === $k;
    $out[] = cb_probe('media.fingerprint_death', 'media', 'hard', $ok, $ok ? 1.0 : 0.0,
        ['kills' => array_map(fn($x) => ['who' => $x['who'], 'place' => $x['place'], 'before' => $x['before'] ? cb_h($x['before']) : null,
                                         'after' => $x['after'] ? cb_h($x['after']) : null], $k)]);
    return $out;
}

/** A place's scene fingerprint at a moment, with these handles dead. */
function cb_fingerprint(array $run, string $key, array $now, array $dead): ?string
{
    $b = xeric_scene_brief($run['T'], $run['db'], $key, $now);
    return $b === null ? null : (string)$b['fingerprint'];
}

/** The fingerprints the baseline pins: every place at noon of day 1. */
function cb_fingerprints(array $run): array
{
    $out = [];
    $now = xeric_world_now($run['T'], cb_at($run['T'], $run['sc'], 'd1 12:00'));
    foreach ((array)$run['T']['places'] as $p) $out[(string)$p['key']] = cb_fingerprint($run, (string)$p['key'], $now, []);
    foreach ($run['kill_fingerprints'] ?? [] as $x) { $out['kill.' . $x['who'] . '.before'] = $x['before']; $out['kill.' . $x['who'] . '.after'] = $x['after']; }
    return $out;
}

// ---------------------------------------------------------------------------
// 6. Cost
// ---------------------------------------------------------------------------

function cb_cost(array $run): array
{
    $byDay = []; $byPhase = []; $sys = [];
    $total = ['calls' => 0, 'chars' => 0, 'tokens' => 0, 'out_chars' => 0];
    foreach ($run['calls'] as $c) {
        $tok = intdiv($c['in'] + 3, 4);
        foreach (['byDay' => (string)$c['day'], 'byPhase' => $c['phase']] as $var => $k) {
            $$var[$k] ??= ['calls' => 0, 'chars' => 0, 'tokens' => 0];
            $$var[$k]['calls']++; $$var[$k]['chars'] += $c['in']; $$var[$k]['tokens'] += $tok;
        }
        $total['calls']++; $total['chars'] += $c['in']; $total['tokens'] += $tok; $total['out_chars'] += $c['out'];
        if ($c['tag'] === 'chat' && isset($c['viewers'][0])) {
            $s = strlen((string)($c['messages'][0]['content'] ?? ''));
            $sys[$c['viewers'][0]] = max($sys[$c['viewers'][0]] ?? 0, $s);
        }
    }
    ksort($byDay); ksort($byPhase); ksort($sys);
    return ['total' => $total, 'by_day' => $byDay, 'by_phase' => $byPhase, 'system_chars' => $sys, 'tally' => $run['tally']];
}

function cb_probe_cost(array $run): array
{
    $cost = cb_cost($run);
    $out = [cb_probe('cost.summary', 'cost', 'info', $cost['total']['calls'] > 0, 1.0, $cost)];

    // A cache break: the system message changed and nothing it is built from (memories, lessons, owed) did.
    $prev = []; $breaks = []; $changes = 0;
    foreach ($run['builds'] as $i => $b) {
        $p = cb_parts($b['messages']);
        $sig = [md5($p['system']), md5(($p['WHAT YOU REMEMBER'] ?? '') . '|' . ($p['WHAT YOU HAVE WORKED OUT'] ?? '') . '|' . ($p['WHAT YOU ARE OWED'] ?? ''))];
        $h = $b['handle'];
        if (isset($prev[$h]) && $prev[$h][0] !== $sig[0]) {
            $changes++;
            if ($prev[$h][1] === $sig[1]) $breaks[] = ['build' => $i, 'handle' => $h, 'day' => $b['day'], 'system' => substr($sig[0], 0, 12)];
        }
        $prev[$h] = $sig;
    }
    $out[] = cb_probe('cost.cache', 'cost', 'soft', $breaks === [], $changes ? 1 - count($breaks) / $changes : 1.0,
        ['system_changes' => $changes, 'unexplained' => count($breaks), 'breaks' => array_slice($breaks, 0, 10)]);
    return $out;
}

// ---------------------------------------------------------------------------
// 7. Learn
// ---------------------------------------------------------------------------

function cb_probe_learn(array $run): array
{
    $T = $run['T']; $db = $run['db'];
    $out = [];
    $passes = cb_standing_passes($run);
    $lastAt  = $passes !== [] ? max(array_column($passes, 'at')) : null;
    $counted = array_filter($passes, fn($p) => !empty($p['distilled']));
    $lastSeq = $counted !== [] ? max(array_column($counted, 'seq')) : null;

    // Every reply crumb written before the last standing pass was counted, and the reach points the right way.
    $want = [];
    foreach ($run['actions'] as $a) {
        if ($a['kind'] === 'say' && $a['ok'] && $lastSeq !== null && $a['seq'] < $lastSeq) $want[$a['args']['to']] = ($want[$a['args']['to']] ?? 0) + 1;
    }
    $bad = [];
    foreach ($want as $h => $n) {
        $t = xeric_learn_tally($db, $h);
        $reach = xeric_learn_reach($db, $h);
        if ($t['replies'] !== $n || ($t['ignored'] === 0 && $reach < 1.0)) $bad[] = ['handle' => $h, 'scripted' => $n, 'tallied' => $t['replies'], 'ignored' => $t['ignored'], 'reach' => $reach];
    }
    $out[] = cb_probe('learn.tally', 'learn', 'hard', $lastSeq !== null && $bad === [], $want ? 1 - count($bad) / count($want) : 0.0,
        ['passes' => count($passes), 'scripted' => $want, 'off' => $bad]);

    // A day lived with nobody pressing skip still learns from the replies in it.
    $d1 = $run['daily'][1] ?? null;
    $folded = $d1 === null ? 0 : array_sum(array_map(fn($t) => (int)$t['replies'], $d1['tally']));
    $said1 = count(array_filter($run['actions'], fn($a) => $a['kind'] === 'say' && $a['ok'] && cb_day($T, $run['sc'], $a['at']) === 1));
    $out[] = cb_probe('learn.heart', 'learn', 'hard', $said1 > 0 && $folded > 0, $said1 ? min(1.0, $folded / $said1) : 0.0,
        ['day1_replies' => $said1, 'folded_by_midnight' => $folded, 'open_crumbs' => $d1['open_signals'] ?? null, 'heart_learns' => $run['heart_learns']]);

    // Each lesson only in its own bucket's prompts; the world's in everybody's — as the buckets stood when the prompt was built.
    $wrong = []; $checked = 0; $count = [];
    foreach ($run['builds'] as $i => $b) {
        $p = (string)(cb_parts($b['messages'])['WHAT YOU HAVE WORKED OUT'] ?? '');
        foreach ((array)($b['lessons'] ?? []) as $h => $ls) foreach ($ls as $l) {
            $checked++;
            $count[$h === '' ? '(world)' : $h][$l] = true;
            $has = str_contains($p, rtrim($l, '.'));
            $should = $h === '' || $h === $b['handle'];
            if ($has !== $should) $wrong[] = ['build' => $i, 'reader' => $b['handle'], 'bucket' => $h === '' ? 'world' : $h, 'present' => $has];
        }
    }
    $buckets = $count; $world = [];
    $out[] = cb_probe('learn.buckets', 'learn', 'hard', $checked > 0 && $wrong === [], $checked ? 1 - count($wrong) / $checked : 0.0,
        ['checked' => $checked, 'lessons' => array_map('count', $buckets), 'misfiled' => array_slice($wrong, 0, 10)]);
    return $out;
}

/** Every probe, in class order. */
function cb_probes(array $run): array
{
    return array_merge(cb_probe_walls($run), cb_probe_recall($run), cb_probe_presence($run),
        cb_probe_relationships($run), cb_probe_media($run), cb_probe_cost($run), cb_probe_learn($run));
}
