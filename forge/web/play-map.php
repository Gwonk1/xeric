<?php
/**
 * play-map.php — the drawn map: the town from above, beside the room from
 * inside. PART OF play-lib.php, required from its parts list like the others.
 *
 * A CLIENT OF xeric_travel_map() AND OF NOTHING ELSE. Every mark, every disc
 * and every price on it is read off the object the where list is drawn from,
 * so the picture and the list cannot disagree about who is in the diner or what
 * the walk costs: they are the same object, drawn twice. The list stays — it is
 * the text form, for a screen reader, a narrow phone, and anybody who would
 * rather read than look. This draws nothing the list does not already say.
 *
 * THREE DECISIONS:
 *
 *  1. A MARK IS A BUTTON ON THE SAME WIRE. Every place you could walk to is a
 *     `.goto[data-to]`, which is what play.php's bindGo() listens on, so a
 *     press on the map is the same go() — the same POST, the same refusal, the
 *     same repaint — as a press on the list. xeric-map.js adds keys and a
 *     tooltip; it adds no second way to travel.
 *
 *  2. DETERMINISTIC, TO THE BYTE. No randomness, no clock, no ids: positions
 *     come from coordinates, people sit around a mark in handle order, labels
 *     take the first side that is free in key order. Two renders of one moment
 *     are one string, which is what lets a test diff it and a repaint leave the
 *     DOM alone when nothing moved.
 *
 *  3. A FLAT WORLD GETS NO PICTURE. When `mapped` is false this returns '' and
 *     the list stands alone with its sentence about having no map: a drawing
 *     of a town with no layout would be invented geography.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/travel.php';   // the bodies, and players.php's seam with them

// ---------------------------------------------------------------------------
// Whose body: the web half of xeric_player_current()
// ---------------------------------------------------------------------------

/**
 * WHICH PLAYER IS LOOKING, for one open world database — the resolver the
 * engine's seam is handed (engine/players.php), installed below on include so
 * every page and worker that loads play-lib asks the same question the same
 * way. Here, rather than in the engine, because the answer is a session's
 * (xeric_session_player) and the engine never reads one.
 *
 * Read off WHICH FILE IS OPEN, the same test xeric_play_is_guest() makes: only
 * a world's own `world.db` under this server's worlds directory has more than
 * one person in it — the owner, and anybody who came in through a pairing
 * code. A fork in a session directory is its visitor's own evening, and they
 * are its first person; so is a database this cannot place (a test's temp
 * file, `:memory:`). Remembered per database and session, because the engine
 * asks on every read of position and the answer cannot change mid-request.
 */
function xeric_play_player_of(?PDO $db): int
{
    static $memo = null;
    if ($db === null) return XERIC_PLAYER_FIRST;
    $memo ??= new WeakMap();
    $sid  = xeric_session_id();
    $seen = $memo[$db] ?? [];
    if (isset($seen[$sid])) return $seen[$sid];

    $id = XERIC_PLAYER_FIRST;
    try {
        $file = '';
        foreach ($db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string)($r['name'] ?? '') === 'main') $file = (string)($r['file'] ?? '');
        }
        $root = realpath(xeric_web_worlds_dir());
        if ($file !== '' && basename($file) === 'world.db' && $root !== false
            && realpath(dirname($file, 2)) === $root) {
            $id = xeric_session_player(basename(dirname($file)), $sid) ?? XERIC_PLAYER_FIRST;
        }
    } catch (Throwable $e) {
        $id = XERIC_PLAYER_FIRST;                    // fail toward the owner's body
    }
    $seen[$sid] = $id;
    $memo[$db]  = $seen;
    return $id;
}

xeric_player_current('xeric_play_player_of');

/** The drawing's width in user units. Height follows the town's shape. */
const XERIC_MAP_W = 320;

/** "14" → "fourteen", up to ninety-nine; past that the digits read fine. */
function xeric_play_map_words(int $n): string
{
    $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
             'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = [2 => 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    if ($n < 0 || $n > 99) return (string)$n;
    if ($n < 20) return $ones[$n];
    return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
}

/** One coordinate, printed the same way every time. */
function xeric_play_map_n(float $v): string
{
    $s = sprintf('%.1f', $v);
    return $s === '-0.0' ? '0.0' : $s;
}

/** A character's initials: first letter of the first and the last word. */
function xeric_play_map_initials(string $name): string
{
    $w = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $i = mb_substr($w[0], 0, 1);
    if (count($w) > 1) $i .= mb_substr($w[count($w) - 1], 0, 1);
    return mb_strtoupper($i);
}

/** Do two boxes [x, y, w, h] overlap? */
function xeric_play_map_hit(array $a, array $b): bool
{
    return $a[0] < $b[0] + $b[2] && $b[0] < $a[0] + $a[2] && $a[1] < $b[1] + $b[3] && $b[1] < $a[1] + $a[3];
}

/**
 * The town as inline SVG, above the where list. '' when the world is flat.
 *
 * @param array $map xeric_travel_map()'s answer, untouched
 */
function xeric_play_map_svg(array $map, string $slug): string
{
    if (empty($map['mapped'])) return '';

    // Only places the map has put somewhere. A place with no coordinates in an
    // otherwise mapped world is still on the list, priced; it just has no spot.
    $spots = array_values(array_filter((array)$map['places'], fn(array $p): bool => is_array($p['at'] ?? null)));
    if (count($spots) < 2) return '';

    // -- scale: the town's extent, not the whole 0–100 grid ------------------
    $xs = array_map(fn($p) => (float)$p['at']['x'], $spots);
    $ys = array_map(fn($p) => (float)$p['at']['y'], $spots);
    $spanX = max(20.0, max($xs) - min($xs));
    $spanY = max(20.0, max($ys) - min($ys));
    $inner = XERIC_MAP_W - 72;
    $s  = min($inner / $spanX, $inner / $spanY);
    $x0 = (min($xs) + max($xs) - $spanX) / 2;                    // centred when narrower than 20
    $y0 = (min($ys) + max($ys) - $spanY) / 2;
    $ox = (XERIC_MAP_W - $spanX * $s) / 2;
    $px = fn(array $at): array => [$ox + ((float)$at['x'] - $x0) * $s, 36 + ((float)$at['y'] - $y0) * $s];
    $townBottom = 36 + $spanY * $s;

    // Reading order, so Tab walks the town the way an eye does.
    usort($spots, function (array $a, array $b) use ($px): int {
        [$ax, $ay] = $px($a['at']);
        [$bx, $by] = $px($b['at']);
        return (round($ay) <=> round($by)) ?: (round($ax) <=> round($bx)) ?: strcmp($a['key'], $b['key']);
    });

    // -- the obstacles a label must not land on: every mark and its company --
    $obst = [];
    foreach ($spots as $p) {
        [$cx, $cy] = $px($p['at']);
        $r = $p['who'] === [] ? 10.0 : 26.0;
        $obst[] = [$cx - $r, $cy - $r, 2 * $r, 2 * $r];
    }

    // Labels take the first free side, in key order, so adding a place never
    // moves a label that was already placed before it in that order.
    $byKey = $spots;
    usort($byKey, fn($a, $b) => strcmp($a['key'], $b['key']));
    $label = [];
    foreach ($byKey as $p) {
        [$cx, $cy] = $px($p['at']);
        $r  = $p['who'] === [] ? 12.0 : 28.0;
        $w  = max(mb_strlen($p['name']) * 5.6, 30.0);
        $sides = [
            ['middle', $cx, $cy + $r + 9, [$cx - $w / 2, $cy + $r, $w, 20]],
            ['middle', $cx, $cy - $r - 11, [$cx - $w / 2, $cy - $r - 20, $w, 20]],
            ['start', $cx + $r, $cy - 1, [$cx + $r, $cy - 10, $w, 20]],
            ['end', $cx - $r, $cy - 1, [$cx - $r - $w, $cy - 10, $w, 20]],
        ];
        $pick = $sides[0];
        foreach ($sides as $side) {
            $free = true;
            foreach ($obst as $o) if (xeric_play_map_hit($side[3], $o)) { $free = false; break; }
            if ($free) { $pick = $side; break; }
        }
        $obst[]  = $pick[3];
        $label[$p['key']] = $pick;
    }

    // -- the player: on their place, or off the edge of town -----------------
    $here = $map['you']['where'] ?? null;
    $you  = null;
    foreach ($spots as $p) if ($p['here']) $you = $px($p['at']);
    $nowhere = $you === null;
    if ($nowhere) $you = [24.0, $townBottom + 34];

    // -- the extent of everything drawn, so nothing is clipped ---------------
    $minX = 0.0; $maxX = (float)XERIC_MAP_W; $minY = 0.0; $maxY = $townBottom + 36;
    foreach ($obst as $o) {
        $minX = min($minX, $o[0] - 4); $maxX = max($maxX, $o[0] + $o[2] + 4);
        $minY = min($minY, $o[1] - 4); $maxY = max($maxY, $o[1] + $o[3] + 4);
    }
    $maxY = max($maxY, $you[1] + 20);
    $legendY = $maxY + 4;
    $maxY = $legendY + 14;

    $across = (int)($map['across'] ?? 0);
    $how    = trim((string)($map['how'] ?? ''));
    $ppm    = $across > 0 ? $s * XERIC_TRAVEL_SPAN / $across : 0.0;    // drawn units per minute
    $barMin = 1;
    foreach ([1, 2, 5, 10, 15, 20, 30, 45, 60, 90, 120] as $m) if ($m * $ppm <= 100) $barMin = $m;
    $barLen = $barMin * $ppm;

    $W = $maxX - $minX;
    $H = $maxY - $minY;
    $title = 'A map of the town' . ($nowhere ? ', and you are nowhere on it' : ', and you at '
           . (string)($map['you']['place'] ?? ''));

    $out = '<div class="wmap" data-world="' . h($slug) . '">'
         . '<svg class="wsvg" xmlns="http://www.w3.org/2000/svg" viewBox="'
         . xeric_play_map_n($minX) . ' ' . xeric_play_map_n($minY) . ' '
         . xeric_play_map_n($W) . ' ' . xeric_play_map_n($H) . '" role="group" aria-label="' . h($title) . '">'
         . '<title>' . h($title) . '</title>'
         . '<rect class="wground" x="' . xeric_play_map_n($minX) . '" y="' . xeric_play_map_n($minY)
         . '" width="' . xeric_play_map_n($W) . '" height="' . xeric_play_map_n($H) . '" rx="8"/>';

    foreach ($spots as $p) {
        [$cx, $cy] = $px($p['at']);
        $state = !empty($p['dark']) ? 'dark' : ($p['open'] ? 'open' : 'shut');
        $mins  = $p['here'] ? 'here' : (int)$p['minutes'] . ' min';
        $names = array_column($p['who'], 'name');
        $aria  = $p['name'] . ', ' . ($p['here'] ? 'you are here' : $mins) . ', ' . $state . ', '
               . ($names === [] ? 'nobody there' : xeric_join_list($names));

        $out .= '<g class="' . ($p['here'] ? 'wpl whr' : 'goto wpl') . ' w' . $state . '" role="' . ($p['here'] ? 'img' : 'button') . '" tabindex="0"'
             . ($p['here'] ? ' aria-current="location"' : ' data-to="' . h($p['key']) . '"')
             . ' data-key="' . h($p['key']) . '" data-name="' . h($p['name']) . '" data-state="' . $state . '"'
             . ' data-min="' . h($mins) . '" data-x="' . xeric_play_map_n($cx) . '" data-y="' . xeric_play_map_n($cy) . '"'
             . ' aria-label="' . h($aria) . '">'
             . '<circle class="wdot" cx="' . xeric_play_map_n($cx) . '" cy="' . xeric_play_map_n($cy) . '" r="8"/>';

        // The company, around the mark in handle order, eight to a ring.
        $who = $p['who'];
        usort($who, fn($a, $b) => strcmp((string)$a['handle'], (string)$b['handle']));
        $n = count($who);
        foreach ($who as $i => $c) {
            $ring  = intdiv($i, 8);
            $inRing = min(8, $n - $ring * 8);
            $ang   = -M_PI / 2 + 2 * M_PI * ($i % 8) / $inRing + ($ring % 2 ? M_PI / 8 : 0);
            $rad   = 19 + 12 * $ring;
            $dx    = $cx + $rad * cos($ang);
            $dy    = $cy + $rad * sin($ang);
            // ANOTHER PLAYER is a person in the company like anybody, drawn
            // with its own ring (.wpc) so nobody mistakes a friend for the cast,
            // and said as what it is: there is no errand to print for a human.
            $pc    = !empty($c['player']);
            $doing = $pc ? 'another player'
                   : (!empty($c['at_home']) ? 'at home' : (string)($c['doing'] ?? ''));
            $out .= '<g class="wch' . ($pc ? ' wpc' : '') . '" data-handle="' . h($c['handle']) . '" data-name="' . h($c['name'])
                 . '" data-doing="' . h($doing) . '"' . ($pc ? ' data-player="1"' : '') . '>'
                 . ($pc ? '<title>' . h($c['name'] . ', another player') . '</title>' : '')
                 . '<circle cx="' . xeric_play_map_n($dx) . '" cy="' . xeric_play_map_n($dy) . '" r="6.5"/>'
                 . '<text x="' . xeric_play_map_n($dx) . '" y="' . xeric_play_map_n($dy + 2.3) . '">'
                 . h(xeric_play_map_initials((string)$c['name'])) . '</text></g>';
        }

        [$anchor, $lx, $ly] = $label[$p['key']];
        $out .= '<text class="wlab" text-anchor="' . $anchor . '" x="' . xeric_play_map_n($lx) . '" y="'
             . xeric_play_map_n($ly) . '">' . h($p['name']) . '</text>'
             . '<text class="wmin" text-anchor="' . $anchor . '" x="' . xeric_play_map_n($lx) . '" y="'
             . xeric_play_map_n($ly + 10) . '">' . h($mins) . '</text>'
             . '</g>';
    }

    // The player last, so they are drawn over the town. The position rides as
    // data so xeric-map.js can walk the mark from the last one to this one.
    $out .= '<g class="wyou' . ($nowhere ? ' wnone' : '') . '" data-x="' . xeric_play_map_n($you[0])
         . '" data-y="' . xeric_play_map_n($you[1]) . '" data-where="' . h((string)($here ?? '')) . '"'
         . ' transform="translate(' . xeric_play_map_n($you[0]) . ' ' . xeric_play_map_n($you[1]) . ')">'
         . '<title>' . ($nowhere ? 'You, on your own time' : 'You are here') . '</title>'
         . '<circle r="12.5"/>'
         . ($nowhere ? '<text class="wlab" x="16" y="3.5">nowhere in particular</text>' : '')
         . '</g>';

    // The scale: a bar of round minutes, and the sentence the world gives.
    $bx = $maxX - 8 - $barLen;
    $out .= '<g class="wscale" aria-hidden="true">'
         . '<path d="M' . xeric_play_map_n($bx) . ' ' . xeric_play_map_n($legendY - 3) . 'v3h'
         . xeric_play_map_n($barLen) . 'v-3"/>'
         . '<text text-anchor="end" x="' . xeric_play_map_n($bx - 4) . '" y="' . xeric_play_map_n($legendY + 1)
         . '">' . $barMin . ' min</text></g>'
         . '</svg>';

    if ($across > 0) {
        $out .= '<p class="wscalecap">About ' . xeric_play_map_words($across) . ' minute' . ($across === 1 ? '' : 's')
              . ' across' . ($how !== '' ? ', ' . h($how) : '') . '.</p>';
    }
    return $out . '</div>';
}
