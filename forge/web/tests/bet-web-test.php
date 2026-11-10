<?php
/**
 * Xeric — bet 1, from the diner.  `php forge/web/tests/bet-web-test.php`, exit 0 on pass.
 *
 * engine/tests/bet-test.php drives one brief through three real machines on
 * loopback — a stored panorama, a splat, a live stream — and keeps the three
 * stores (`--keep`). This renders each of them where a person sees it, #look
 * (xeric_play_look_html), and holds the half of FUTURE.md M5's Done-when the
 * engine cannot: "a person cannot tell from the diner which machine drew it".
 * Same people, same names, same bearings, same light and sky under it; the
 * only thing that differs is the surface the room is drawn on, and nothing on
 * the page names the machine.
 *
 * SKIPS when the engine test skips (no python3, no php -S).
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-bet-web-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');
foreach (['XERIC_SCENE_BASE', 'XERIC_SCENE_KEY', 'XERIC_SCENE_KIND', 'XERIC_VOICE_BASE', 'XERIC_IMAGE_BASE'] as $k) putenv($k);

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
function bw_rmtree(string $d): void
{
    foreach (glob($d . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $p) { is_dir($p) ? bw_rmtree($p) : @unlink($p); }
    @rmdir($d);
}

$t0   = microtime(true);
$keep = $tmp . '/bet';
$out  = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/engine/tests/bet-test.php')
                         . ' --keep ' . escapeshellarg($keep) . ' 2>&1');
if (str_contains($out, "\nskipped\n") || str_starts_with($out, 'SKIP') || str_contains($out, "\nSKIP - ")) {
    echo "SKIP - bet-web-test: engine/tests/bet-test.php skipped, so there are no three stores to show\n\nskipped\n";
    bw_rmtree($tmp);
    exit(0);
}
ok('the engine half ran, against its three machines, and passed', str_contains($out, "\nPASS\n"),
   implode(' | ', preg_grep('/^FAIL/', explode("\n", $out)) ?: ['no PASS line']));
$man = json_decode((string)@file_get_contents($keep . '/manifest.json'), true);
if (!is_array($man)) {
    ok('the engine half left its stores', false);
    bw_rmtree($tmp);
    exit(1);
}

/** The name tags: [handle, bearing, name] in page order. */
function bw_tags(string $html): array
{
    preg_match_all('/<button type="button" class="looktag" data-h="([^"]*)" data-b="(-?\d+)"[^>]*>([^<]*)<\/button>/', $html, $m, PREG_SET_ORDER);
    return array_map(fn($x) => [html_entity_decode($x[1]), (int)$x[2], html_entity_decode($x[3])], $m);
}

$html = [];
foreach ($man['stores'] as $kind => $path) {
    $db = xeric_state_open($path);
    $html[$kind] = xeric_play_look_html($man['templates'][$kind], $db, null, 'bet', true);
    $db = null;
}
$expect = array_map(fn($w) => [$w['handle'], (int)$w['bearing'], $w['name']], $man['who']);

ok('pano: #look is the look-around canvas', str_contains($html['pano'] ?? '', 'class="lookmain lookpanobox"'));
ok('splat: #look is the canvas you stand inside, not a download', str_contains($html['splat'] ?? '', 'class="lookmain looksplatbox"'));
ok('stream: #look is the live view, an MJPEG picture that keeps arriving',
    str_contains($html['stream'] ?? '', 'class="lookmain lookstreambox"') && str_contains($html['stream'] ?? '', 'data-protocol="mjpeg"'));
$tags = array_map('bw_tags', $html);
ok('all three carry the same name tags at the same bearings — the brief\'s',
    $tags === ['pano' => $expect, 'splat' => $expect, 'stream' => $expect], json_encode($tags));
$meta = array_map(fn($h) => preg_match('/<p class="lookmeta">(.*?)(?:<\/p>| <span)/s', $h, $m) ? $m[1] : '', $html);
ok('and the same light and sky under each', count(array_unique($meta)) === 1 && $meta['pano'] !== '', json_encode($meta));
ok('none of them is stale or still developing: each is the room as it is now',
    !str_contains(implode('', $html), 'from an earlier hour') && !str_contains(implode('', $html), 'developing'));
$tells = ['images.py', 'worldmodel', 'bet-splat', 'xeric-blockout', 'openai-images', 'dry-run', 'vendor'];
$told  = [];
foreach ($html as $k => $h) foreach ($tells as $w) if (stripos($h, $w) !== false) $told[] = "$k:$w";
ok('and nothing on the page names the machine that drew it', $told === [], implode(', ', $told));

$took = microtime(true) - $t0;
echo '# whole test: ' . sprintf('%.1f', $took) . " s\n";
ok('under thirty seconds end to end', $took < 30, sprintf('%.1f s', $took));

bw_rmtree($tmp);
echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
