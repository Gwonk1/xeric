<?php
/**
 * play-shelf.php — the shelf, the first screen: its tiles, their scenes and art.
 *
 * PART OF play-lib.php, moved out of it whole: every function here kept its
 * name, signature and body. play-lib.php require_once's this file, so every
 * page that requires play-lib.php has it; requiring this file alone pulls
 * play-lib.php in as well (the two include each other once). The map of which
 * part lives where is at the top of play-lib.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

// ---------------------------------------------------------------------------
// The shelf — the first screen, and the only one with no words on it
// ---------------------------------------------------------------------------
//
// The Wii menu is the reference, and not for the look. It never explained
// itself: no copy, no onboarding, no welcome. A grid of tiles, each one a PLACE
// YOU CAN GO, and the only affordance is that they are clickable. It works
// because the tile carries meaning that a sentence would dilute — and it is
// right here for a reason beyond taste, which is that a Xeric world IS a
// channel. Self-contained, running whether or not anybody is watching, resumed
// rather than started.
//
// So: nothing on this screen describes what this screen is. The wordmark, the
// tiles, and one link in the corner for the thing a person cannot discover by
// clicking (which model does the thinking). Everything the old shelf said out
// loud — "Somewhere to be", the lead, the note about copies, the per-world
// metadata line — is gone. If a visitor cannot tell what to do with a grid of
// pictures, more words were never going to be the fix.

/**
 * How long until this xeric lives another hour, and how many it has lived.
 *
 * THE SHELF HAS TO EXPLAIN ITS OWN SILENCE. A sweep happens once per window of
 * world time, so a perfectly healthy xeric does nothing at all for fifty-odd
 * minutes — and a token counter that has not moved since lunch looks exactly
 * like one that is broken. The lamp says it is alive; this says when it will
 * next prove it.
 *
 * @return array{due:?int,lived:int} seconds until the next window, and windows swept
 */
function xeric_play_next_hour(string $slug): array
{
    $dir = xeric_web_worlds_dir() . '/' . $slug;
    $t   = xeric_world_load($dir . '/world-template.json');
    $db  = xeric_state_open($dir . '/world.db');

    $size = (int)($t['events']['window_seconds'] ?? XERIC_SWEEP_WINDOW);
    if ($size < 1) $size = XERIC_SWEEP_WINDOW;

    $now  = xeric_clock_now($db, $t);
    $here = intdiv((int)$now['epoch'], $size);

    $pre  = 'sweep:' . $size . ':';
    $last = 0;
    $lived = 0;
    foreach (xeric_world_state_all($db) as $k => $_) {
        if (!str_starts_with((string)$k, $pre)) continue;
        $lived++;
        $n = (int)substr((string)$k, strlen($pre));
        if ($n > $last) $last = $n;
    }

    // Due now if a window has passed unswept; otherwise the seconds until the
    // one it is standing in runs out.
    $due = ($here > $last) ? 0 : (($here + 1) * $size - (int)$now['epoch']);
    return ['due' => max(0, $due), 'lived' => $lived];
}

/**
 * Is this world stopped? Answered without opening it, where that is possible.
 *
 * A stopped world is stopped because its visitor detached the model, and that is
 * a fact about the VISITOR — so the session answers for all of them at once and
 * the shelf draws eight tiles without eight database connections. A world can
 * still be paused on its own (a worker, a repair, a future per-world button), so
 * this is a fast path and not the truth: xeric_clock_is_paused() is the truth,
 * and the world screen asks it properly.
 */
function xeric_play_paused_quick(string $slug, ?string $sid = null): bool
{
    static $off = null;
    if ($off === null) {
        $off = !xeric_web_connected(xeric_web_model($sid));
    }
    return $off;
}

/** Where a world's cover art would live, if it has any. */
function xeric_play_tile_file(string $slug): ?string
{
    foreach (['tile.webp', 'tile.jpg', 'tile.png'] as $name) {
        $p = xeric_web_worlds_dir() . '/' . $slug . '/' . $name;
        if (is_file($p)) return $p;
    }
    return null;
}

/**
 * What this world's cover art is a picture OF.
 *
 * READ FROM THE WORLD, NOT INVENTED. The first version of this fallback was a
 * seeded colour field, and the note it got back was the right one: Blackwater
 * Creek came out as a blue circle on a green ground, and Blackwater Creek is a
 * river town under a ridge with a steeple in it. A template already says all of
 * that — `setting.locale` in a sentence, and `places[].kind` as a list of the
 * buildings that exist — so the art can be a picture of the place instead of a
 * texture that happens to be near it.
 *
 * Deliberately crude in what it reads: keywords out of the locale and a lookup
 * over place kinds. It is choosing between six silhouettes, not parsing English,
 * and a world it reads wrongly still gets a landscape rather than a failure.
 *
 * @return array{terrain:string,water:string,landmark:string,hue:int,dusk:bool}
 */
function xeric_play_scene(array $t): array
{
    $locale = strtolower(xeric_text($t['setting']['locale'] ?? '') . ' '
                       . xeric_text($t['meta']['description'] ?? ''));
    $has = fn(string ...$words): bool => (bool)array_filter($words, fn($w) => str_contains($locale, $w));

    // City wins over coast on purpose: a coastal megalopolis is a skyline with
    // water in front of it, and terrain decides the skyline while `water` below
    // decides the water. They are two questions and this is the one that is not
    // about the sea.
    $terrain = match (true) {
        $has('city', 'megalopolis', 'metro', 'downtown', 'district')  => 'city',
        $has('mountain', 'cascade', 'ridge', 'peak', 'alpine')        => 'mountain',
        $has('valley', 'hollow', 'creek', 'appalach')                 => 'valley',
        $has('coast', 'harbour', 'harbor', 'fishing', 'tide', 'bay')  => 'coast',
        $has('desert', 'dry', 'mesa', 'plain', 'prairie')             => 'flat',
        default                                                       => 'valley',
    };
    $water = match (true) {
        $has('coast', 'harbour', 'harbor', 'tide', 'sea', 'ocean', 'fishing', 'bay') => 'sea',
        $has('river', 'creek', 'landing', 'basin')                                   => 'river',
        default                                                                      => '',
    };

    // The landmark is whichever building this world has that most says where you
    // are, in a fixed order of legibility at 200 pixels: you can read a steeple
    // and a headframe at that size, and you cannot read a post office.
    $kinds = [];
    foreach ((array)($t['places'] ?? []) as $p) $kinds[strtolower((string)($p['kind'] ?? ''))] = true;
    $landmark = 'roofs';
    foreach (['site' => 'headframe', 'church' => 'steeple', 'station' => 'tower',
              'market' => 'silo', 'school' => 'roofs', 'hall' => 'roofs'] as $kind => $mark) {
        if (isset($kinds[$kind])) { $landmark = $mark; break; }
    }
    if ($terrain === 'city') $landmark = 'towers';

    $h = crc32((string)($t['meta']['name'] ?? '') . '|' . $locale);
    return [
        'terrain'  => $terrain,
        'water'    => $water,
        'landmark' => $landmark,
        'hue'      => 8 + ($h % 200),
        // Dusk on anything that called itself dying, decaying or fog-drenched.
        'dusk'     => $has('decay', 'dying', 'rust', 'fog', 'abandon', 'secluded'),
    ];
}

/**
 * A tile's face, as a small landscape.
 *
 * SVG rather than an image or a gradient, for three reasons that all matter:
 * it is sharp at any tile size, it costs no request, and it can be BUILT FROM
 * THE WORLD rather than picked from a set. Silhouettes only — no detail
 * survives at 200 pixels, and atmospheric perspective (each layer darker and
 * more saturated than the one behind it) is what makes four flat shapes read as
 * distance.
 *
 * Deterministic: the same world draws the same ridge forever. Seeded from the
 * slug so two worlds with the same locale still get different hills.
 */
function xeric_play_tile_svg(string $slug, array $scene): string
{
    // A tiny LCG, because mt_rand() would make the shelf different on every
    // reload and a world's own picture is not allowed to move.
    $seed = crc32($slug);
    $rnd = function (int $lo, int $hi) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
        return $lo + (int)($seed % max(1, $hi - $lo + 1));
    };

    // SKY AND LAND ARE NOT THE SAME HUE ROTATED, and the first attempt at this
    // made them exactly that — which produced pink skies over green hills and
    // read, correctly, as a bug rather than as an evening. Land gets the world's
    // hue folded into the band that ground actually comes in (olive through
    // green through the blue-green of distance); sky gets warm light or cool
    // light and nothing else. A landscape is two palettes meeting at a line, not
    // one palette applied twice.
    $dusk = (bool)$scene['dusk'];
    $hue  = 74 + ((int)$scene['hue'] % 126);          // 74-200: olive -> green -> teal

    $sky = $dusk
        ? [26 + ((int)$scene['hue'] % 18), 46, 78]     // low amber, a shade per world
        : [200 + ((int)$scene['hue'] % 16), 26, 88];   // pale, cool, high

    // Each layer forward: a little more saturated, a lot darker, and pulled a
    // few degrees toward blue - which is atmospheric perspective, and the only
    // reason four flat polygons read as distance.
    $tone = fn(int $step): string => 'hsl(' . max(70, $hue - $step * 5) . ' '
        . min(52, 20 + $step * 8) . '% ' . max(11, ($dusk ? 50 : 58) - $step * 10) . '%)';

    $W = 400; $H = 300;
    // Where the land meets the sky. A coast sits low so there is water to see;
    // mountains sit high so there is something to look up at.
    $skyline = match ($scene['terrain']) {
        'mountain' => 118, 'city' => 176, 'coast' => 186, 'flat' => 205, default => 152,
    };

    // -- the ridges ---------------------------------------------------------
    $ridge = function (int $baseY, int $amp, int $steps) use ($rnd, $W, $H): string {
        $pts = [];
        for ($i = 0; $i <= $steps; $i++) {
            $x = (int)round($i * $W / $steps);
            $pts[] = $x . ',' . max(0, $baseY + $rnd(-$amp, $amp));
        }
        return implode(' ', $pts) . " $W,$H 0,$H";
    };

    $layers = '';
    $far = match ($scene['terrain']) {
        'mountain' => [$skyline, 46, 5],
        'valley'   => [$skyline, 26, 6],
        'coast'    => [$skyline, 10, 7],
        'city'     => [$skyline, 8, 9],
        default    => [$skyline, 7, 8],
    };
    $layers .= '<polygon points="' . $ridge($far[0], $far[1], $far[2]) . '" fill="' . $tone(1) . '"/>';
    $layers .= '<polygon points="' . $ridge($far[0] + 34, (int)max(6, $far[1] * 0.55), $far[2] + 2)
             . '" fill="' . $tone(2) . '"/>';

    // -- the landmark, on the near ridge ------------------------------------
    $lx = $rnd(90, 300);
    $ly = $far[0] + 30;
    $ink = $tone(4);
    $mark = match ($scene['landmark']) {
        'steeple'   => '<rect x="' . ($lx - 9) . '" y="' . ($ly - 26) . '" width="18" height="26" fill="' . $ink . '"/>'
                     . '<polygon points="' . $lx . ',' . ($ly - 52) . ' ' . ($lx - 9) . ',' . ($ly - 26)
                     . ' ' . ($lx + 9) . ',' . ($ly - 26) . '" fill="' . $ink . '"/>',
        'headframe' => '<polygon points="' . $lx . ',' . ($ly - 54) . ' ' . ($lx - 17) . ',' . $ly
                     . ' ' . ($lx - 9) . ',' . $ly . ' ' . $lx . ',' . ($ly - 40) . ' ' . ($lx + 9) . ',' . $ly
                     . ' ' . ($lx + 17) . ',' . $ly . '" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx - 22) . '" y="' . ($ly - 58) . '" width="44" height="5" fill="' . $ink . '"/>',
        'tower'     => '<rect x="' . ($lx - 5) . '" y="' . ($ly - 46) . '" width="10" height="46" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx - 16) . '" y="' . ($ly - 58) . '" width="32" height="16" rx="4" fill="' . $ink . '"/>',
        'silo'      => '<rect x="' . ($lx - 11) . '" y="' . ($ly - 44) . '" width="22" height="44" rx="11" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx + 14) . '" y="' . ($ly - 22) . '" width="26" height="22" fill="' . $ink . '"/>',
        'towers'    => '<rect x="' . ($lx - 30) . '" y="' . ($ly - 74) . '" width="20" height="74" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx - 6) . '" y="' . ($ly - 52) . '" width="16" height="52" fill="' . $ink . '"/>'
                     . '<rect x="' . ($lx + 14) . '" y="' . ($ly - 92) . '" width="14" height="92" fill="' . $ink . '"/>',
        default     => '',
    };

    // A handful of roofs beside it, so the landmark is in a town rather than
    // alone in a field. Never more than five — this is a hamlet, not a skyline.
    $town = '';
    for ($i = 0, $n = $rnd(3, 5); $i < $n; $i++) {
        $bx = $rnd(20, $W - 40);
        $bw = $rnd(18, 34);
        $bh = $rnd(10, 20);
        $town .= '<polygon points="' . $bx . ',' . $ly . ' ' . $bx . ',' . ($ly - $bh)
               . ' ' . ($bx + $bw / 2) . ',' . ($ly - $bh - 7) . ' ' . ($bx + $bw) . ',' . ($ly - $bh)
               . ' ' . ($bx + $bw) . ',' . $ly . '" fill="' . $tone(3) . '"/>';
    }

    // -- water, in front of everything --------------------------------------
    $wy = $scene['water'] === 'sea' ? 214 : 236;
    // WATER IS THE SKY LYING DOWN. Filling it from the land palette is what made
    // the first pass read as a dark strip of nothing: a river is bright because
    // it is reflecting the one light source in the picture.
    // Bright, not dark. Dropping 34 points of lightness off the sky gave a brown
    // strip that read as dirt; water is one of the LIGHTEST things in a dusk
    // landscape, because it is the only surface pointed at the sky.
    $wet   = 'hsl(' . $sky[0] . ' ' . max(14, $sky[1] - 14) . '% ' . max(54, $sky[2] - 14) . '%)';
    $glint = 'hsl(' . $sky[0] . ' ' . $sky[1] . '% ' . min(92, $sky[2] + 6) . '%)';
    $water = $scene['water'] === ''
        ? '<polygon points="' . $ridge(248, 9, 5) . '" fill="' . $tone(5) . '"/>'
        // A dark bank line where the water starts, so it reads as a far edge
        // rather than as the picture running out.
        : '<rect x="0" y="' . ($wy - 4) . '" width="' . $W . '" height="5" fill="' . $tone(4) . '"/>'
          . '<rect x="0" y="' . $wy . '" width="' . $W . '" height="' . ($H - $wy) . '" fill="' . $wet . '"/>'
          . '<rect x="0" y="' . ($wy + 10) . '" width="' . $W . '" height="3" fill="' . $glint . '" opacity=".75"/>'
          . '<rect x="' . $rnd(20, 120) . '" y="' . ($wy + 26) . '" width="' . $rnd(60, 150)
          . '" height="2" fill="' . $glint . '" opacity=".38"/>'
          . '<rect x="' . $rnd(180, 320) . '" y="' . ($wy + 40) . '" width="' . $rnd(40, 90)
          . '" height="2" fill="' . $glint . '" opacity=".28"/>';

    return '<svg class="tface" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="xMidYMid slice"'
        . ' role="img" aria-hidden="true" focusable="false">'
        // The sky warms and saturates toward the horizon, never away from it -
        // the one gradient direction that is true of every sky anybody has seen.
        . '<defs><linearGradient id="sk' . substr(md5($slug), 0, 6) . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="hsl(' . (($sky[0] + 14) % 360) . ' ' . max(12, $sky[1] - 18)
        . '% ' . min(96, $sky[2] + 8) . '%)"/>'
        . '<stop offset="1" stop-color="hsl(' . $sky[0] . ' ' . ($sky[1] + 8) . '% ' . ($sky[2] - 6) . '%)"/>'
        . '</linearGradient></defs>'
        . '<rect width="' . $W . '" height="' . $H . '" fill="url(#sk' . substr(md5($slug), 0, 6) . ')"/>'
        . $layers . $town . $mark . $water
        . '</svg>';
}

/**
 * The face of a tile: the world's own image if somebody made one, else the
 * landscape above.
 */
function xeric_play_tile_face(string $slug, array $scene): string
{
    if (xeric_play_tile_file($slug) !== null) {
        return '<span class="tface" style="background-image:url(\'tile.php?w=' . h(rawurlencode($slug))
             . '\');background-size:cover;background-position:center"></span>';
    }
    return xeric_play_tile_svg($slug, $scene);
}

/** Retired: the colour-field fallback, kept only so nothing breaks mid-deploy. */
function xeric_play_tile_css(string $slug): string
{
    if (xeric_play_tile_file($slug) !== null) {
        return "background-image:url('tile.php?w=" . h(rawurlencode($slug)) . "');background-size:cover;"
             . 'background-position:center';
    }

    $h = crc32($slug);
    // 200° of the wheel starting at 8: clay → ochre → sage → teal → river blue.
    // Stops before magenta on purpose. A band this wide is the difference
    // between a shelf and a shelf where four things are the same green; a band
    // any wider is a shelf with a bug on it.
    $hue  = 8 + ($h % 200);
    $hue2 = ($hue + 28 + (($h >> 8) % 40)) % 360;
    $x1   = 18 + (($h >> 3)  % 55);
    $y1   = 14 + (($h >> 7)  % 40);
    $x2   = 30 + (($h >> 11) % 60);
    $y2   = 50 + (($h >> 15) % 45);
    $tilt = (($h >> 19) % 60) - 30;

    // Weighted like a PHOTOGRAPH, not like a tint. The first version of this sat
    // at 82–95% lightness and every tile read as a white card with a stain on
    // it — which is the failure mode a fallback has to avoid above all others,
    // because a shelf of near-empty rectangles looks like art that failed to
    // load rather than art that was never made. Mid-lightness, real saturation,
    // and a vignette, so a tile has the same visual weight as the image that
    // will one day replace it and the grid does not reflow when it does.
    return 'background-image:'
        . 'radial-gradient(120% 100% at 50% 120%, rgba(20,18,14,.20) 0%, rgba(20,18,14,0) 60%),'
        . "radial-gradient(66% 60% at {$x1}% {$y1}%, hsl($hue 52% 78%) 0%, hsl($hue 46% 62% / 0) 72%),"
        . "radial-gradient(58% 54% at {$x2}% {$y2}%, hsl($hue2 46% 55%) 0%, hsl($hue2 42% 48% / 0) 74%),"
        . "linear-gradient({$tilt}deg, hsl($hue 40% 72%) 0%, hsl($hue2 36% 56%) 100%)";
}

/**
 * The grid. Tiles in, wordmark first, nothing explained.
 *
 * Ordered newest-forged first, which is the same order the old list used and the
 * only ordering a person can predict without being told one.
 */
function xeric_play_shelf_html(array $worlds): string
{
    $out = '';
    foreach ($worlds as $i => $x) {
        $href = ($x['launched'] ? 'play.php' : 'review.php') . '?w=' . h(rawurlencode($x['slug']));

        // Not a word on the tile beyond the world's own name — but a world where
        // death sticks still has to say so before somebody walks in, so it says
        // it the only way this screen allows: a mark, and a title for anyone
        // who hovers or reads with a screen reader.
        $mark = $x['permanent']
            ? '<span class="tmark" title="Death is permanent in this xeric" aria-label="death is permanent">&times;</span>'
            : '';

        // THE LAMP ANSWERS ONE QUESTION: is time passing in there right now. Green
        // yes, red no — and "no" covers both a xeric somebody stopped and one
        // that has never been launched, because from outside the door those are
        // the same fact. Which one it is goes in the tooltip, where a person who
        // wants the difference can find it.
        //
        // The greying of a stopped tile stays: it is what somebody notices from
        // across the room, and the lamp is what they can be sure of.
        $live = empty($x['paused']) && !empty($x['launched']) && !empty($x['lived']);
        $why  = !$x['launched'] ? 'not launched yet, so no time is passing'
              : (!$x['lived']   ? 'never opened, so its clock has not started'
              : ($x['paused']   ? 'stopped, its clock is where you left it'
                                : 'running, time is passing in here'));

        $out .= '<a class="tile' . (!empty($x['paused']) ? ' stopped' : '')
              . '" data-slug="' . h((string)$x['slug']) . '"'
              . ' href="' . $href . '" style="--d:' . (0.24 + $i * 0.045) . 's">'
              . xeric_play_tile_face((string)$x['slug'], (array)$x['scene'])
              . '<span class="tlamp' . ($live ? ' on' : '') . '" title="' . h($why)
              . '" aria-label="' . h($why) . '"></span>'
              . $mark
              . '<span class="tname">' . h($x['name']) . '</span>'
              . '</a>';
    }

    // The forge is a tile like any other, and it is last so the shelf reads as
    // "these, and one more" rather than as a toolbar with worlds attached.
    // WITH NOTHING ATTACHED THE PLUS GOES TO THE MACHINES, NOT THE INTERVIEW.
    // A forge is fourteen model calls; walking somebody through twenty
    // questions and failing at the end is the worst version of this screen, and
    // on a fresh install with nothing running it is the ONLY version they would
    // ever see. Of course they click the plus — it is the only thing here.
    $ready = xeric_web_connected(xeric_web_model());
    $out .= '<a class="tile new" href="' . ($ready ? 'forge.php?fresh=1' : 'model.php') . '"'
          . ' style="--d:' . (0.24 + count($worlds) * 0.045) . 's"'
          . ' title="' . ($ready ? 'Forge a new xeric' : 'Set up a machine first')
          . '" aria-label="' . ($ready ? 'Forge a new xeric' : 'Set up a machine first') . '">'
          . '<span class="plus" aria-hidden="true"></span></a>';

    return $out;
}

/**
 * The shelf's own stylesheet. Loaded only there; nothing else has tiles.
 *
 * The rules live in xeric-shelf.css, served from this origin with its hash in
 * the address (xeric_play_css_link). This is the same bytes inline, for a
 * page that still prints them into a <style> of its own.
 */
function xeric_play_shelf_css(): string
{
    return xeric_play_static('xeric-shelf.css');
}
