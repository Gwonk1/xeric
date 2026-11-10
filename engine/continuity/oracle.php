<?php
/**
 * Xeric continuity benchmark — the wall oracle.
 *
 * GENERATED, NOT HAND-WRITTEN. cb_forbidden(T, viewer) is everything this
 * viewer must never be handed, derived from the template and the engine's own
 * wall machinery, so a new secret in a fixture is covered the day it is
 * written:
 *
 *   - every interior string xeric_wall_interiors() files under a path that
 *     xeric_viewer_walls() hides from this viewer (their OWN interiors excepted:
 *     a person's own head was never what the walls governed);
 *   - a wall's `explain` and a special role's `must_not_know`, as
 *     xeric_quotes_walled() treats them;
 *   - every OTHER character's `gossip_grade: false` secret — owner-only canon;
 *   - every node rated above this viewer's ceiling;
 *   - every boon that pays into an economy this viewer's walls hide;
 *   - the scenario's canaries, against the wall path they name.
 *
 * A HARD FAIL is a quotation by the engine's own test — xeric_wall_words /
 * xeric_wall_quotes, six words in a row (XERIC_WALL_QUOTE_RUN), or the whole of
 * a short field of 16+ characters. Words that exist only inside forbidden
 * strings are a SOFT flag. Nothing returned here is ever printed: evidence
 * carries the wall path and a hash, never the string.
 */

declare(strict_types=1);

/** The strings a character's own voice block is built from — theirs to read. */
function cb_own_interiors(array $T, string $handle): array
{
    $c = xeric_world_character($T, $handle);
    if ($c === null) return [];
    $own = [xeric_text($c['voice'] ?? ''), xeric_text($c['solace'] ?? ''), xeric_text($c['drives']['pull'] ?? '')];
    foreach (['psyche', 'tells', 'secrets'] as $k) foreach ((array)($c[$k] ?? []) as $n) $own[] = xeric_text($n);
    return array_values(array_filter($own, fn($s) => $s !== ''));
}

/** Walk the template for rated nodes above this rank. */
function cb_overrated(array $node, int $rank, string $path, array &$out): void
{
    if (isset($node['rating_min']) && xeric_rating_rank((string)$node['rating_min']) > $rank) {
        $s = xeric_text($node);
        if ($s !== '') $out[] = ['rating.' . $path, $s];
        return;
    }
    foreach ($node as $k => $v) if (is_array($v)) cb_overrated($v, $rank, $path === '' ? (string)$k : $path . '.' . $k, $out);
}

/**
 * @return array<int,array{path:string,text:string,words:array}> what this viewer must never see
 */
function cb_forbidden(array $T, string $viewer, array $sc = []): array
{
    static $memo = [];
    $mk = md5(json_encode([$T['meta'] ?? [], $T['cast'] ?? [], $T['knowledge_walls'] ?? [], $sc['canaries'] ?? []])) . '|' . $viewer;
    if (isset($memo[$mk])) return $memo[$mk];

    $v     = xeric_viewer($T, ['handle' => $viewer]);
    $walls = xeric_viewer_walls($T, $v);
    $own   = array_flip(cb_own_interiors($T, $viewer));
    $rows  = [];

    foreach (xeric_wall_interiors($T) as $path => $strings) {
        if (!xeric_hidden($walls, (string)$path)) continue;
        foreach ($strings as $s) if (!isset($own[$s])) $rows[] = [(string)$path, $s];
    }
    foreach ($walls as $w) {
        $key = (string)($w['key'] ?? '');
        $ex  = xeric_text($w['explain'] ?? '');
        if ($ex !== '') $rows[] = ['knowledge_walls.' . $key, $ex];
        foreach ((array)($T['cast']['special_roles'] ?? []) as $sr) {
            if ($key === '' || !in_array($key, array_map('strval', (array)($sr['walls'] ?? [])), true)) continue;
            $mnk = xeric_text($sr['must_not_know'] ?? '');
            if ($mnk !== '') $rows[] = ['knowledge_walls.' . $key . '.must_not_know', $mnk];
        }
    }
    foreach ((array)($T['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '' || $h === $viewer) continue;
        foreach ((array)($c['secrets'] ?? []) as $s) {
            if (is_array($s) && array_key_exists('gossip_grade', $s) && !$s['gossip_grade']) $rows[] = ['secrets.' . $h . '.owner_only', xeric_text($s)];
        }
    }
    $rank = xeric_rating_rank(xeric_viewer_rating(xeric_world_rating($T), $v));
    $over = [];
    cb_overrated($T, $rank, '', $over);
    foreach ($over as $o) $rows[] = $o;

    foreach ((array)($T['boons'] ?? []) as $b) {
        $k   = (string)($b['key'] ?? '');
        $eco = (string)($b['payout']['economy'] ?? '');
        if (xeric_hidden($walls, 'boons.' . $k) || ($eco !== '' && xeric_hidden($walls, 'economies.' . $eco))) {
            foreach ([$b['text'] ?? '', $b['label'] ?? '', $b['payout']['amount'] ?? ''] as $s) {
                $s = xeric_text($s);
                if ($s !== '') $rows[] = ['boons.' . $k, $s];
            }
        }
    }
    foreach ((array)($sc['canaries'] ?? []) as $cn) {
        $p = (string)($cn['path'] ?? '');
        if ($p !== '' && xeric_hidden($walls, $p)) $rows[] = ['canary.' . $p, (string)$cn['text']];
    }

    $out = [];
    $seen = [];
    foreach ($rows as [$path, $text]) {
        $text = trim((string)$text);
        if ($text === '' || isset($seen[$path . '|' . $text])) continue;
        $seen[$path . '|' . $text] = true;
        $out[] = ['path' => $path, 'text' => $text, 'words' => xeric_wall_words($text)];
    }
    return $memo[$mk] = $out;
}

/** Text the scenario allows said out loud to this viewer, cut out before a check. */
function cb_allowed(array $sc, string $viewer, string $hay): string
{
    foreach ((array)($sc['allow'] ?? []) as $a) {
        $who = (string)($a['viewer'] ?? '*');
        if ($who === '*' || $who === $viewer) $hay = str_ireplace((string)$a['text'], ' ', $hay);
    }
    return $hay;
}

/**
 * The wall paths this text quotes, for this viewer. Never the text.
 * @return string[]
 */
function cb_quotes(array $T, array $sc, string $viewer, string $text): array
{
    $hay = xeric_wall_words(cb_allowed($sc, $viewer, $text));
    if ($hay === []) return [];
    $flat = ' ' . implode(' ', $hay) . ' ';
    $hit  = [];
    foreach (cb_forbidden($T, $viewer, $sc) as $f) {
        if (isset($hit[$f['path']])) continue;
        // cheap gate: the needle's first word must be in the hay at all
        if ($f['words'] === [] || !str_contains($flat, ' ' . $f['words'][0] . ' ') && count($f['words']) <= XERIC_WALL_QUOTE_RUN) continue;
        if (xeric_wall_quotes($hay, $f['words'])) $hit[$f['path']] = true;
    }
    return array_keys($hit);
}

/** Words found only inside this viewer's forbidden strings — the soft flag's vocabulary. */
function cb_telltales(array $T, array $sc, string $viewer): array
{
    static $memo = [];
    if (isset($memo[$viewer])) return $memo[$viewer];
    static $commons = null;
    if ($commons === null) {
        $inner = [];
        foreach (xeric_wall_interiors($T) as $strings) foreach ($strings as $s) $inner[$s] = true;
        foreach ((array)($T['cast']['characters'] ?? []) as $c) foreach ((array)($c['secrets'] ?? []) as $s) $inner[xeric_text($s)] = true;
        $all = [];
        $walk = function ($n) use (&$walk, &$all, $inner) {
            if (is_string($n)) { if (!isset($inner[$n])) foreach (xeric_wall_words($n) as $w) $all[$w] = true; return; }
            if (is_array($n)) foreach ($n as $k => $v) { if ($k === 'text' && is_string($v) && isset($inner[$v])) continue; $walk($v); }
        };
        $walk($T);
        $commons = $all;
    }
    $words = [];
    foreach (cb_forbidden($T, $viewer, $sc) as $f) {
        foreach ($f['words'] as $w) if (mb_strlen($w) >= 6 && !isset($commons[$w])) $words[$w] = true;
    }
    return $memo[$viewer] = array_keys($words);
}

/** render-test.php's hand-written SECRETS, read from the file so the two cannot drift. */
function cb_render_secrets(): array
{
    $src = (string)@file_get_contents(__DIR__ . '/../tests/render-test.php');
    if (!preg_match('/const SECRETS = \[(.*?)\];/s', $src, $m)) return [];
    preg_match_all('/^\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,/m', $m[1], $mm);
    return array_map(fn($q) => stripcslashes(substr($q, 1, -1)), $mm[1]);
}

/**
 * Does the oracle cover every SECRETS string, for the viewers render-test
 * walls (the daughter and a fixture)? Coverage means: some forbidden string for
 * that viewer contains it.
 *
 * @return array{covered:int,total:int,missing:int[]} missing are indexes into SECRETS
 */
function cb_oracle_covers(array $T, array $sc): array
{
    $secrets = cb_render_secrets();
    $pool = [];
    foreach (['janelle', 'cy'] as $v) foreach (cb_forbidden($T, $v, $sc) as $f) $pool[] = mb_strtolower($f['text']);
    $missing = [];
    foreach ($secrets as $i => $s) {
        $found = false;
        foreach ($pool as $p) if (str_contains($p, mb_strtolower($s))) { $found = true; break; }
        if (!$found) $missing[] = $i;
    }
    return ['covered' => count($secrets) - count($missing), 'total' => count($secrets), 'missing' => $missing];
}
