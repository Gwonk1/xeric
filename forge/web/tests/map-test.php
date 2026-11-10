<?php
/**
 * Xeric — the drawn map. `php forge/web/tests/map-test.php`, exit 0 on pass.
 *
 * play-map.php draws xeric_travel_map() from above; the where list draws the
 * same object as text. This proves the picture says what the object says —
 * one mark per place, the player on theirs, the company at theirs, the prices
 * and the states — that every mark is on the same wire as the list (bindGo's
 * `.goto[data-to]`), that a flat world gets no picture, and that one moment
 * renders to one string. Then it hands two renders to map-test.js, which runs
 * xeric-map.js against them in a fake DOM: the tooltip, the keys, the walk.
 *
 * NO NETWORK, NO MACHINE. The moment is pinned (a Monday, 08:54 in Milldale),
 * so who is where is the fixture's schedule and not the wall clock's.
 * The Node half SKIPS without node, the viewer test's rule.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-map-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called

require_once __DIR__ . '/../play-lib.php';

$FAILED = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) {
        echo "ok   - $name\n";
    } else {
        $FAILED++;
        echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

function rmtree(string $d): void
{
    foreach (glob($d . '/*') ?: [] as $p) { is_dir($p) ? rmtree($p) : @unlink($p); }
    @rmdir($d);
}

/** The opening tag of one place's mark, by key. */
function mark_tag(string $svg, string $key): string
{
    return preg_match('/<g class="[^"]*\bwpl\b[^"]*"[^>]*data-key="' . preg_quote($key, '/') . '"[^>]*>/', $svg, $m)
        ? $m[0] : '';
}

/** One place's whole mark, up to the next one. */
function mark_body(string $svg, string $key): string
{
    $tag = mark_tag($svg, $key);
    if ($tag === '') return '';
    $rest = substr($svg, strpos($svg, $tag));
    $next = preg_match('/<g class="(?:goto wpl|wpl whr|wyou)/', substr($rest, strlen($tag)), $m, PREG_OFFSET_CAPTURE)
        ? $m[0][1] + strlen($tag) : strlen($rest);
    return substr($rest, 0, $next);
}

// A real world, from the tracked fixture, the way scene-web-test.php builds one.
$fixT = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$slug = 'map-town';
$wdir = xeric_web_worlds_dir() . '/' . $slug;
@mkdir($wdir, 0775, true);
file_put_contents($wdir . '/world-template.json',
    json_encode($fixT, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($wdir . '/seed.json',
    json_encode(xeric_forge_default_seed($fixT), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$A = bin2hex(random_bytes(16));
xeric_session_use($A);
xeric_session_claim($slug, $A);
$w  = xeric_play_open($slug);
$T  = $w['template'];
$db = $w['db'];

$now = xeric_world_now($T, 1793627659);              // Monday 08:54: Dot at the counter, Harlan at the register
ok('the pinned moment is the one this test was written against',
    $now['hhmm'] === '08:54' && (int)$now['dow'] === 1, json_encode($now));

xeric_player_move($db, 'bluebird');
$map  = xeric_travel_map($T, $db, $now);
$html = xeric_play_where_html($map, $slug);
$svg  = xeric_play_map_svg($map, $slug);
ok('the fixture world is mapped, so the where panel draws it', $map['mapped'] === true && $svg !== '');

// -- the picture is above the list, and the list is still there -------------
ok('the map sits above the list, and the list is untouched beneath it',
    str_contains($html, $svg) && strpos($html, '<svg') < strpos($html, '<ul class="wgo">')
    && substr_count($html, 'class="goto"') === count($map['places']) - 1         // the list's own buttons
    && str_contains($html, 'class="goto ghost" data-to=""'),                      // and Leave
    (string)substr_count($html, 'class="goto"'));
ok('inline SVG, nothing fetched: no image, no href, no script, no style element',
    !preg_match('/<(img|image|use|script|style|foreignObject)\b|href=/i', $svg));

// -- one mark per place ------------------------------------------------------
$keys = array_column($map['places'], 'key');
preg_match_all('/<g class="[^"]*\bwpl\b[^"]*"[^>]*data-key="([^"]+)"/', $svg, $mk);
$drawn = $mk[1];
sort($drawn);
$want = $keys;
sort($want);
ok('one mark per staged place, and nothing else', $drawn === $want, implode(',', $mk[1]));

// -- the same wire as the list ----------------------------------------------
$elsewhere = array_values(array_filter($map['places'], fn($p) => !$p['here']));
$wired = 0;
foreach ($elsewhere as $p) {
    $tag = mark_tag($svg, $p['key']);
    if (preg_match('/class="goto wpl\b/', $tag) && str_contains($tag, 'data-to="' . $p['key'] . '"')
        && str_contains($tag, 'role="button"') && str_contains($tag, 'tabindex="0"')) $wired++;
}
ok('every place you could walk to is a .goto[data-to] mark, the same wire bindGo() listens on',
    $wired === count($elsewhere) && $wired === 3, (string)$wired);
$hereTag = mark_tag($svg, 'bluebird');
ok('and the place you are standing in is not a button to it',
    !str_contains($hereTag, 'goto') && !str_contains($hereTag, 'data-to=')
    && str_contains($hereTag, 'aria-current="location"'), $hereTag);

// -- the player --------------------------------------------------------------
preg_match('/<g class="wyou"[^>]*data-x="([\d.]+)" data-y="([\d.]+)" data-where="bluebird"/', $svg, $you);
ok('the player\'s mark is on the place they stand in',
    $you !== [] && str_contains($hereTag, 'data-x="' . $you[1] . '" data-y="' . $you[2] . '"'), $hereTag);

xeric_player_move($db, null);
$noSvg = xeric_play_map_svg(xeric_travel_map($T, $db, $now), $slug);
ok('a player on their own time is off the edge of town, said in words',
    str_contains($noSvg, '<g class="wyou wnone"') && str_contains($noSvg, 'nowhere in particular')
    && !str_contains($noSvg, 'aria-current'));
xeric_player_move($db, 'bluebird');

// -- the company --------------------------------------------------------------
$beck = mark_body($svg, 'beck_hardware');
$dot  = mark_body($svg, 'bluebird');
ok('a present character is a disc with initials at their place',
    str_contains($beck, 'data-handle="harlan"') && str_contains($beck, '>HB</text>')
    && str_contains($dot, 'data-handle="dot"') && str_contains($dot, '>DV</text>')
    && !str_contains($beck, 'data-handle="dot"'), $beck);
ok('and the disc carries only observables: a name and what they are doing',
    (bool)preg_match('/<g class="wch" data-handle="harlan" data-name="Harlan Beck" data-doing="[^"]+">/', $beck));

// A crowd sits around its mark in handle order, no two discs on one spot.
$crowdMap = xeric_travel_map($T, $db, $now);
foreach ($crowdMap['places'] as $i => $p) {
    if ($p['key'] !== 'bluebird') continue;
    foreach (['zed', 'amy', 'kit', 'bo', 'cy2', 'di', 'ed', 'flo', 'gus', 'hal'] as $hh) {
        $crowdMap['places'][$i]['who'][] = ['handle' => $hh, 'name' => ucfirst($hh) . ' Test', 'doing' => 'waiting', 'at_home' => false];
    }
}
$crowd = mark_body(xeric_play_map_svg($crowdMap, $slug), 'bluebird');
preg_match_all('/<g class="wch" data-handle="([^"]+)"[^>]*><circle cx="([\d.-]+)" cy="([\d.-]+)"/', $crowd, $cm, PREG_SET_ORDER);
$spotsSeen = array_map(fn($m) => $m[2] . ',' . $m[3], $cm);
$order = array_column($cm, 1);
$sorted = $order;
sort($sorted);
ok('eleven people at one counter: eleven discs, in handle order, no two in one spot',
    count($cm) === 11 && $order === $sorted && count(array_unique($spotsSeen)) === 11, implode(' ', $order));

// -- states and prices ---------------------------------------------------------
ok('a shut place is drawn shut, an open one open',
    str_contains(mark_tag($svg, 'the_mill'), 'wshut') && str_contains(mark_tag($svg, 'beck_hardware'), 'wopen'));
$okMin = true;
foreach ($elsewhere as $p) {
    if (!str_contains(mark_body($svg, $p['key']), '<text class="wmin" text-anchor="') ||
        !preg_match('/>' . (int)$p['minutes'] . ' min<\/text>/', mark_body($svg, $p['key']))) $okMin = false;
}
ok('every place carries its minutes from here, the list\'s price', $okMin
    && str_contains($dot, '>here</text>'));
ok('and the scale is the world\'s own: its minutes across and how people get about',
    str_contains($svg, 'About fourteen minutes across, on foot, and everybody walks.')
    && (bool)preg_match('/<g class="wscale"[^>]*>.*?>\d+ min<\/text>/', $svg));

xeric_dark_set($db, ['the_mill']);
$darkSvg = xeric_play_map_svg(xeric_travel_map($T, $db, $now), $slug);
ok('a room the world lost is drawn dark, which beats its hours',
    str_contains(mark_tag($darkSvg, 'the_mill'), 'wdark') && str_contains(mark_tag($darkSvg, 'the_mill'), 'data-state="dark"'));
xeric_dark_set($db, []);

// -- what is not drawn -----------------------------------------------------------
$homeT = $T;
$homeT['places'][] = ['key' => 'ruths_house', 'name' => "Ruth's house", 'kind' => 'home',
                      'residents' => ['ruth'], 'at' => ['x' => 30, 'y' => 30]];
foreach ($homeT['cast']['characters'] as $i => $c) if ($c['handle'] === 'ruth') $homeT['cast']['characters'][$i]['out'] = true;
ok('a home nobody in the story lives in yet is not on the map',
    !str_contains(xeric_play_map_svg(xeric_travel_map($homeT, $db, $now), $slug), 'ruths_house'));

$flatT = $T;
foreach ($flatT['places'] as $i => $_) unset($flatT['places'][$i]['at']);
$flatMap  = xeric_travel_map($flatT, $db, $now);
$flatHtml = xeric_play_where_html($flatMap, $slug);
ok('a flat world draws no picture, and the list stands alone with its sentence',
    xeric_play_map_svg($flatMap, $slug) === '' && !str_contains($flatHtml, '<svg')
    && str_contains($flatHtml, 'made before it had a map') && str_contains($flatHtml, 'class="wgo"'));

// -- one moment, one string ---------------------------------------------------------
$again = xeric_play_where_html(xeric_travel_map($T, $db, $now), $slug);
ok('two renders of one moment are byte-identical', $again === $html, (string)strlen($again));

$evil = $map;
$evil['places'][0]['name'] = '<script>x</script>"&';
ok('a name is text, never markup', !str_contains(xeric_play_map_svg($evil, $slug), '<script>'));

// -- another player ----------------------------------------------------------------
// A guest joins on a code and walks to the hardware store. The resolver
// play-map.php installs reads WHICH session is asking off the open file, so
// the owner sees a friend's ring at Beck's and the guest sees the owner's at
// the diner — the same object, read from two chairs.
$B   = bin2hex(random_bytes(16));
$pid = xeric_player_add($db, $T, 'Ann Price', 'she/her');
xeric_session_join($slug, $pid, $B);
xeric_session_use($B);
ok('the seam: a joined guest is their own player on the owner\'s file',
    xeric_player_current(null, $db) === $pid && xeric_player_where($T, $db) === null);
xeric_player_move($db, 'beck_hardware', (int)$now['epoch']);
$annMap = xeric_travel_map($T, $db, $now);
$annSvg = xeric_play_map_svg($annMap, $slug);
ok('as the guest, you are the guest — at Beck\'s, under her own name',
    $annMap['you']['where'] === 'beck_hardware' && $annMap['you']['name'] === 'Ann Price'
    && str_contains($annSvg, 'data-where="beck_hardware"'));
ok('and the owner is the other player at the diner',
    (bool)preg_match('/<g class="wch wpc" data-handle="p1" data-name="Walt" data-doing="another player" data-player="1">/',
                     mark_body($annSvg, 'bluebird')));
xeric_session_use($A);
ok('the owner is still player one, still at the diner',
    xeric_player_current(null, $db) === 1 && xeric_player_where($T, $db) === 'bluebird');
$twoMap  = xeric_travel_map($T, $db, $now);
$twoSvg  = xeric_play_map_svg($twoMap, $slug);
$twoBeck = mark_body($twoSvg, 'beck_hardware');
ok('the other player\'s mark: its own ring, her initials, said to be another player',
    (bool)preg_match('/<g class="wch wpc" data-handle="p' . $pid . '" data-name="Ann Price" data-doing="another player" data-player="1">'
                     . '<title>Ann Price, another player<\/title><circle [^>]+\/><text [^>]+>AP<\/text><\/g>/', $twoBeck)
    && str_contains($twoBeck, 'data-handle="harlan"'), $twoBeck);
ok('the list says it in words too, for anybody not looking at the picture',
    str_contains(xeric_play_where_html($twoMap, $slug), 'Ann Price (another player)'));
ok('and the cast\'s discs are drawn as they always were',
    (bool)preg_match('/<g class="wch" data-handle="harlan" data-name="Harlan Beck" data-doing="[^"]+">/', $twoBeck));
// -- and she walks herself ------------------------------------------------------
// where.php, as a real request from her cookie: walking was the owner's alone
// while there was one body; with a body each a joined player walks their own.
$page = function (string $sid, array $post) use ($tmp): array {
    $boot = '$_GET = []; $_POST = ' . var_export($post, true) . ';'
        . ' $_COOKIE = [' . var_export(XERIC_WEB_COOKIE, true) . ' => ' . var_export($sid, true) . '];'
        . ' $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["HTTP_ACCEPT"] = "application/json";'
        . ' register_shutdown_function(function () { echo "\n@@STATUS " . http_response_code(); });'
        . ' require ' . var_export(dirname(__DIR__) . '/where.php', true) . ';';
    $raw = (string)shell_exec('XERIC_DATA_DIR=' . escapeshellarg($tmp) . ' XERIC_WORLDS_DIR=' . escapeshellarg($tmp . '/worlds')
        . ' XERIC_LOCAL_BASE=http://127.0.0.1:1 XERIC_SOLO=0 ' . escapeshellarg(PHP_BINARY)
        . ' -d error_reporting=0 -r ' . escapeshellarg($boot) . ' 2>&1');
    $st = preg_match('/@@STATUS (\d+)\s*$/', $raw, $m) ? (int)$m[1] : 0;
    $j  = json_decode(trim((string)preg_replace('/@@STATUS \d+\s*$/', '', $raw)), true);
    return ['status' => $st, 'json' => is_array($j) ? $j : null, 'raw' => $raw];
};
$paused = xeric_clock_is_paused($db);
if ($paused) xeric_clock_resume($db);
$off  = xeric_clock_offset($db);
$walk = $page($B, ['world' => $slug, 'to' => 'the_mill']);
ok('where.php: a joined player may walk — her own body, not the owner\'s',
    $walk['status'] === 200 && ($walk['json']['you']['where'] ?? '') === 'the_mill'
    && ($walk['json']['you']['name'] ?? '') === 'Ann Price'
    && xeric_player_where($T, $db, $pid) === 'the_mill' && xeric_player_where($T, $db, 1) === 'bluebird',
    $walk['status'] . ' ' . mb_substr($walk['raw'], 0, 300));
ok('where.php: and the minutes she spent are everybody\'s',
    xeric_clock_offset($db) - $off === (int)($walk['json']['went']['minutes'] ?? -1) * 60 && $off !== xeric_clock_offset($db));
$C = bin2hex(random_bytes(16));
$fork = $page($C, ['world' => $slug, 'to' => 'beck_hardware']);
// (A fork made just now is opened stopped when no machine is attached, so its
// walk may be refused as a stopped world — never as a guest.)
ok('where.php: a stranger is never refused as a guest, and the owner\'s world never hears of their walk',
    $fork['status'] !== 403 && ($fork['json']['kind'] ?? '') !== 'guest' && xeric_player_where($T, $db, 1) === 'bluebird'
    && xeric_player_where($T, $db, $pid) === 'the_mill' && xeric_clock_offset($db) - $off === (int)$walk['json']['went']['minutes'] * 60,
    $fork['status'] . ' ' . mb_substr($fork['raw'], 0, 300));
if ($paused) xeric_clock_pause($db);

xeric_player_drop($db, $T, $pid);
ok('once she has gone, the map is the map it was', xeric_play_where_html(xeric_travel_map($T, $db, $now), $slug) === $html);

// -- the static file, as the page loads it --------------------------------------------
$page = (string)file_get_contents(dirname(__DIR__) . '/play.php');
ok('play.php loads xeric-map.js by its hash, with the nonce',
    str_contains($page, '<script nonce="<?= h(xeric_web_nonce()) ?>" src="<?= h(xeric_play_asset(\'xeric-map.js\')) ?>"></script>')
    && (bool)preg_match('/^xeric-map\.js\?v=[0-9a-f]{16}$/', xeric_play_asset('xeric-map.js')));

// -- the behaviour, in Node --------------------------------------------------------------
echo "# the map, run\n";
$node = (string)getenv('XERIC_NODE');
if ($node === '' || !is_executable($node)) $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '' && getenv('XERIC_REQUIRE_NODE') === '1') {
    ok('node is on this machine, as XERIC_REQUIRE_NODE=1 says it must be', false);
} elseif ($node === '') {
    echo "SKIP - map-test: the Node half did not run (no node on this machine; set XERIC_NODE)\n";
} else {
    xeric_player_move($db, 'the_mill');
    $walked = xeric_play_where_html(xeric_travel_map($T, $db, $now), $slug);
    file_put_contents($tmp . '/a.html', $html);
    file_put_contents($tmp . '/b.html', $walked);
    $out = (string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/map-test.js') . ' '
        . escapeshellarg(dirname(__DIR__) . '/xeric-map.js') . ' ' . escapeshellarg($tmp . '/a.html') . ' '
        . escapeshellarg($tmp . '/b.html') . ' ' . escapeshellarg(dirname(__DIR__) . '/play.php') . ' 2>&1');
    $ran = 0;
    foreach (explode("\n", $out) as $line) {
        if (!str_starts_with($line, "R\t")) continue;
        $p = explode("\t", $line, 4);
        ok($p[2] ?? '?', ($p[1] ?? '0') === '1', $p[3] ?? '');
        $ran++;
    }
    ok('the harness reported back (' . $ran . ' checks)', $ran >= 12, mb_substr($out, 0, 600));
}

rmtree($tmp);

echo "\n" . ($FAILED === 0 ? "all map tests passed\n" : "$FAILED map test(s) FAILED\n");
exit($FAILED === 0 ? 0 : 1);
