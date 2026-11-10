<?php
/**
 * layout.php — look at the box a room is drawn as (engine/layout.php).
 *
 *     php tools/layout.php milldale bluebird
 *     php tools/layout.php engine/fixtures/milldale.json bluebird --at '2026-11-02 16:00'
 *     php tools/layout.php data/worlds/myworld the_tavern --out /tmp/look --interior 'the bar,a piano'
 *
 * The first argument is a world slug (data/worlds/<slug>, or
 * engine/fixtures/<slug>.json), a world directory, or a template file. A world
 * directory's own world.db is read, never written; a bare template gets a
 * throwaway one. Writes depth.png, seg.png and layout.json to --out (default:
 * the system temp dir's xeric-layout/) for a human to look at — NOT A TEST
 * and nothing it writes is committed. --interior replaces the place's interior
 * for this look only, so a room the template left bare can still be seen
 * furnished; --vantage establishing looks from a corner.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/engine/scene.php';

$pos = []; $o = ['out' => sys_get_temp_dir() . '/xeric-layout', 'at' => '', 'interior' => null, 'vantage' => ''];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if (str_starts_with($a, '--') && isset($argv[$i + 1])) { $o[substr($a, 2)] = $argv[++$i]; continue; }
    $pos[] = $a;
}
if (count($pos) < 2) {
    fwrite(STDERR, "usage: php tools/layout.php <world-slug|world-dir|template.json> <place> "
        . "[--out DIR] [--at 'YYYY-MM-DD HH:MM'] [--interior 'a,b,c'] [--vantage establishing]\n");
    exit(2);
}
[$w, $key] = $pos;

$dataDir = (string)(getenv('XERIC_DATA_DIR') ?: $ROOT . '/data');
$tpl = null; $dbFile = null;
foreach ([$w, $dataDir . '/worlds/' . $w, $ROOT . '/engine/fixtures/' . $w . '.json'] as $c) {
    if (is_dir($c) && is_file($c . '/world-template.json')) {
        $tpl = $c . '/world-template.json';
        $dbFile = is_file($c . '/world.db') ? $c . '/world.db' : null;
        break;
    }
    if (is_file($c)) { $tpl = $c; break; }
}
if ($tpl === null) { fwrite(STDERR, "layout: no world or template called $w\n"); exit(2); }

try { $T = xeric_world_load($tpl); }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(2); }

if ($o['interior'] !== null) {
    foreach ($T['places'] as &$p) {
        if (($p['key'] ?? '') === $key) $p['interior'] = array_values(array_filter(array_map('trim', explode(',', (string)$o['interior']))));
    }
    unset($p);
}
if ($o['vantage'] !== '') $T['media']['scene']['vantage'] = (string)$o['vantage'];

// Read, never write: a world's own database is copied first.
$tmp = sys_get_temp_dir() . '/xeric-layout-' . getmypid() . '.sqlite';
if ($dbFile !== null) copy($dbFile, $tmp);
$db = xeric_state_open($tmp);
if ($dbFile === null) xeric_clock_begin($db, $T);
$now = $o['at'] !== ''
    ? xeric_world_now($T, (new DateTimeImmutable((string)$o['at'], new DateTimeZone((string)($T['user']['timezone'] ?? 'UTC'))))->getTimestamp())
    : null;

$b = xeric_scene_brief($T, $db, $key, $now);
$db = null;
foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) @unlink($f);
if ($b === null) { fwrite(STDERR, "layout: no room called $key (or not in the story yet)\n"); exit(1); }

$L = $b['layout'];
@mkdir((string)$o['out'], 0775, true);
$dir = rtrim((string)$o['out'], '/');
$png = xeric_layout_pngs($L);
file_put_contents($dir . '/depth.png', $png['depth']);
file_put_contents($dir . '/seg.png', $png['seg']);
file_put_contents($dir . '/layout.json', json_encode($L, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

printf("%s at %s, %s — %.0f x %.0f x %.0f m, %s\n", $b['name'], $b['now']['hhmm'], $b['light'],
    $L['room']['width'], $L['room']['depth'], $L['room']['height'], $L['vantage']['mode']);
foreach ($L['objects'] as $ob) {
    printf("  %-7s %3d°  %4.1f m  %-6s %s\n", $ob['kind'], $ob['bearing'], $ob['distance'], $ob['size'], $ob['label']);
}
echo "wrote $dir/depth.png, $dir/seg.png, $dir/layout.json\n";
