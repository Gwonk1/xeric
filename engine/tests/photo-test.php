<?php
/**
 * Xeric — portrait chaining tests.  `php engine/tests/photo-test.php`, exit 0 on pass.
 *
 * The first portrait is the likeness every later photo of that person is
 * conditioned on, on a machine that answers /v1/images/edits — and only
 * there. Checked through the stub seam (what the reaper hands the adapter,
 * and what the row remembers) and against a small images machine this file
 * starts on a loopback port, so the multipart body a real sd-server would
 * parse — and the generations call a machine without the route still gets —
 * are not tested only by assumption. The photo assertions that predate
 * chaining live in engine-test.php and are untouched.
 */

declare(strict_types=1);

require_once __DIR__ . '/../photo.php';

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

foreach (['XERIC_IMAGE_BASE', 'XERIC_IMAGE_KEY', 'XERIC_IMAGE_EDITS'] as $k) putenv($k);

$T = xeric_template_load(__DIR__ . '/../fixtures/milldale.json');

$dir = sys_get_temp_dir() . '/xeric-photo-test-' . getmypid();
@mkdir($dir, 0775, true);
$dbP = $dir . '/db.sqlite';
$db  = xeric_state_open($dbP);
xeric_state_migrate($db);
xeric_clock_begin($db, $T);
xeric_world_state_set($db, 'photos.approved', '1');
$photos = $dir . '/photos';

// A real (tiny) PNG, so the reference's mime sniff has something to sniff.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

echo "# the schema\n";
ok('meta: photo_jobs carries a meta column, defaulting to an empty object',
    in_array('meta', xeric_state_columns($db, 'photo_jobs'), true));

echo "\n# the likeness\n";

ok('reference: nobody photographed yet is no reference', xeric_photo_reference($db, 'ruth', $photos) === null);
ok('reference: an empty handle is no reference', xeric_photo_reference($db, '', $photos) === null);

// The stub records what it was handed; it draws a PNG that names its prompt.
$seen = [];
$stub = function (bool $edits) use (&$seen, $png): array {
    return ['edits' => $edits, 'stub' => function (string $tag, array $c, array $o) use (&$seen, $png) {
        $seen[] = ['composed' => $c, 'opts' => $o];
        return ['bytes' => $png . md5((string)$c['prompt']), 'usage' => []];
    }];
};
$job = fn(string $kind, string $subj) => xeric_photo_of($db, $kind, $subj);
$meta = fn(?array $row) => json_decode((string)($row['meta'] ?? '{}'), true) ?: [];

// Enqueue just ruth's and theo's portraits (not the whole backfill: keeps the order legible).
$ins = $db->prepare('INSERT INTO photo_jobs (kind, subject, caption, created_at) VALUES (?, ?, ?, ?)');
$ins->execute(['portrait', 'ruth', 'Ruth', 1]);

$ep = $stub(true);
xeric_photo_reap($T, $db, $photos, $ep, 5);
ok('first portrait: the first picture takes no reference, even on a machine that takes one',
    count($seen) === 1 && !isset($seen[0]['opts']['reference']), json_encode(array_keys($seen[0]['opts'] ?? [])));
ok('first portrait: and its row records nothing it was not conditioned on',
    !isset($meta($job('portrait', 'ruth'))['reference_of']), (string)($job('portrait', 'ruth')['meta'] ?? ''));

$ref = xeric_photo_reference($db, 'ruth', $photos);
$ruthId = (int)$job('portrait', 'ruth')['id'];
ok('reference: the done portrait, read off the disk, is the likeness',
    $ref !== null && $ref['mime'] === 'image/png' && $ref['id'] === $ruthId
    && $ref['bytes'] === (string)file_get_contents($photos . '/' . $job('portrait', 'ruth')['file'])
    && $ref['fingerprint'] !== '');
ok('reference: a cap smaller than the file passes it over', xeric_photo_reference($db, 'ruth', $photos, 8) === null);
// A kids' headset's photo is never conditioned on an adult portrait.
$db->prepare('UPDATE photo_jobs SET rating = ? WHERE id = ?')->execute(['mature', $ruthId]);
ok('reference: a portrait stamped above the render\'s rating is no likeness for it',
    xeric_photo_reference($db, 'ruth', $photos, XERIC_PHOTO_REFERENCE_CAP, 'sfw') === null
    && xeric_photo_reference($db, 'ruth', $photos, XERIC_PHOTO_REFERENCE_CAP, 'mature') !== null
    && xeric_photo_reference($db, 'ruth', $photos) !== null);
xeric_state_rating('sfw');
ok('reference: the render runs at the lower of the row\'s stamp and the process\'s',
    xeric_photo_render_rating('mature') === 'sfw' && xeric_photo_render_rating('') === 'sfw');
xeric_state_rating('');
ok('reference: and with neither set, nothing is filtered', xeric_photo_render_rating('') === '');
$db->prepare('UPDATE photo_jobs SET rating = ? WHERE id = ?')->execute(['', $ruthId]);

// Walk-back: a missing file is no likeness; the row stays, the file does not.
$f = $photos . '/' . $job('portrait', 'ruth')['file'];
$keep = (string)file_get_contents($f);
unlink($f);
ok('reference: a portrait whose file is gone is passed over', xeric_photo_reference($db, 'ruth', $photos) === null);
file_put_contents($f, $keep);
// Not an image is not a png: a file cut off mid-write has a perfect header and
// no end, and must not be handed over labelled image/png.
file_put_contents($f, substr($keep, 0, 40));
ok('reference: a truncated portrait is no likeness — read as nothing, not labelled png',
    xeric_photo_reference_read($f) === null && xeric_photo_reference($db, 'ruth', $photos) === null);
file_put_contents($f, 'Bad Gateway: the machine was rebooting');
ok('reference: nor are bytes that are not a picture at all', xeric_photo_reference_read($f) === null);
file_put_contents($f, "\xff\xd8\xff\xe0" . str_repeat('x', 40));
ok('reference: nor a jpeg with no end', xeric_photo_reference_read($f) === null);
file_put_contents($f, $keep);
ok('reference: and the whole file reads again', (xeric_photo_reference_read($f)['mime'] ?? '') === 'image/png');

echo "\n# frames with a person in them\n";

$conv = xeric_conversation_create($db, 'ruth', 'chat');
$mid  = xeric_message_append($db, $conv, 'character', 'ruth', '📷 Ruth at the urn', 1000);
xeric_photo_enqueue_message($db, 'ruth', $mid, $conv, 'a selfie by the coffee urn', 'Ruth by the urn');
$seen = [];
xeric_photo_reap($T, $db, $photos, $stub(true), 5);
$mp = $job('message', 'ruth#' . $mid);
ok('message photo: after a portrait it carries the likeness through the stub seam',
    count($seen) === 1 && ($seen[0]['opts']['reference']['bytes'] ?? '') === $keep
    && ($seen[0]['opts']['reference']['id'] ?? 0) === $ruthId);
ok('message photo: and its row records reference_of the portrait it was drawn from',
    (string)$mp['status'] === 'done' && ($meta($mp)['reference_of'] ?? 0) === $ruthId
    && ($meta($mp)['reference_fingerprint'] ?? '') === $ref['fingerprint'], (string)$mp['meta']);

// A machine without edits: no reference handed over, the prompt identical.
$mid2 = xeric_message_append($db, $conv, 'character', 'ruth', '📷 again', 1001);
xeric_photo_enqueue_message($db, 'ruth', $mid2, $conv, 'a selfie by the coffee urn', 'Ruth by the urn');
$withPrompt = (string)$seen[0]['composed']['prompt'];
$seen = [];
xeric_photo_reap($T, $db, $photos, $stub(false), 5);
$mp2 = $job('message', 'ruth#' . $mid2);
ok('no edits: a machine without the route is never handed a likeness',
    count($seen) === 1 && !isset($seen[0]['opts']['reference']) && (string)$mp2['status'] === 'done'
    && !isset($meta($mp2)['reference_of']));
ok('no edits: and the prompt is the same prompt — a reference changes no words',
    (string)$seen[0]['composed']['prompt'] === $withPrompt);

// THE MINOR RULE with a likeness. theo is twelve; the world is rated explicit.
$X = $T;
$X['meta']['rating'] = 'explicit';
$ins->execute(['portrait', 'theo', 'Theo', 2]);
xeric_photo_reap($X, $db, $photos, $stub(true), 5);
$tc  = xeric_conversation_create($db, 'theo', 'chat');
$tm  = xeric_message_append($db, $tc, 'character', 'theo', '📷', 1002);
xeric_photo_enqueue_message($db, 'theo', $tm, $tc, 'moody artistic shot', 'Theo');
$seen = [];
xeric_photo_reap($X, $db, $photos, $stub(true), 5);
ok('minor: the frame takes his own likeness, and the floor framing still lands last',
    count($seen) === 1 && ($seen[0]['opts']['reference']['id'] ?? 0) === (int)$job('portrait', 'theo')['id']
    && $seen[0]['composed']['rating'] === 'sfw' && !empty($seen[0]['composed']['minor'])
    && str_ends_with((string)$seen[0]['composed']['prompt'], XERIC_PHOTO_FLOOR_FRAMING . '.'),
    (string)($seen[0]['composed']['prompt'] ?? ''));
ok('minor: the likeness is never another person\'s',
    ($seen[0]['opts']['reference']['id'] ?? 0) !== $ruthId);

// A place with nobody in frame takes no likeness; a REROLL of a portrait takes the one it replaces.
$ins->execute(['place', 'bluebird', 'the Bluebird', 3]);
$db->prepare("UPDATE photo_jobs SET status = 'pending' WHERE kind = 'portrait' AND subject = 'ruth'")->execute();
$seen = [];
xeric_photo_reap($T, $db, $photos, $stub(true), 5);
$byKind = [];
foreach ($seen as $s) $byKind[(string)$s['composed']['kind']] = $s;
ok('place: an establishing shot with nobody in it takes no likeness',
    isset($byKind['place']) && !isset($byKind['place']['opts']['reference']));
ok('reroll: a second render of a portrait takes the first as its likeness',
    isset($byKind['portrait']) && ($byKind['portrait']['opts']['reference']['bytes'] ?? '') === $keep
    && ($meta($job('portrait', 'ruth'))['reference_of'] ?? 0) === $ruthId);

// THE KIDS' HEADSET'S PHOTO, REAPED BY AN ADULT WORKER: composed at the lower
// of the queued stamp and the reaper's own rating, and still the kid's after.
$XM = $T;
$XM['meta']['rating'] = 'mature';
$db->prepare('INSERT INTO photo_jobs (kind, subject, caption, created_at, rating) VALUES (?, ?, ?, ?, ?)')
   ->execute(['place', 'beck_hardware', 'the hardware store', 4, 'sfw']);
xeric_state_rating('mature');
$seen = [];
xeric_photo_reap($XM, $db, $photos, $stub(false), 5);
xeric_state_rating('');
$kidShot = array_values(array_filter($seen, fn($s) => ($s['composed']['kind'] ?? '') === 'place'))[0] ?? null;
ok('rating: an sfw-queued photo reaped by a mature worker composes at sfw, not at the reaper\'s rating',
    $kidShot !== null && $kidShot['composed']['rating'] === 'sfw', json_encode($kidShot['composed']['rating'] ?? null));
ok('rating: and keeps the stamp it was queued with — never re-stamped upward, so the kid can see it',
    (string)$job('place', 'beck_hardware')['status'] === 'done' && (string)$job('place', 'beck_hardware')['rating'] === 'sfw');

echo "\n# the wire\n";

// A forty-line images machine: /edits/ answers the edits route, /bare/ only generations.
$router = $dir . '/fake-images.php';
$wire   = $dir . '/wire.jsonl';
file_put_contents($router, <<<'FAKE'
<?php
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
[$_, $kind, $rest] = array_pad(explode('/', $path, 3), 3, '');
$rest = '/' . $rest;
$json = function (int $code, array $body) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($body); return true; };
$log  = function (array $row) { file_put_contents((string)getenv('XERIC_FAKE_IMAGE_LOG'), json_encode($row) . "\n", FILE_APPEND); };
$png  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return $json(404, ['error' => 'not found']);
if ($rest === '/v1/images/generations') {
    $log(['kind' => $kind, 'route' => 'generations', 'ctype' => $_SERVER['CONTENT_TYPE'] ?? '',
          'body' => json_decode((string)file_get_contents('php://input'), true)]);
    return $json(200, ['data' => [['b64_json' => $png]]]);
}
if ($rest === '/v1/images/edits' && $kind === 'edits') {
    $img = $_FILES['image'] ?? null;
    $row = ['kind' => $kind, 'route' => 'edits', 'ctype' => $_SERVER['CONTENT_TYPE'] ?? '', 'post' => $_POST,
            'image' => $img ? base64_encode((string)file_get_contents($img['tmp_name'])) : null,
            'image_type' => $img['type'] ?? null];
    if (($_POST['prompt'] ?? '') === '' || $img === null) return $json(400, ['error' => 'prompt and image are required']);
    $log($row);
    return $json(200, ['data' => [['b64_json' => $png]]]);
}
return $json(404, ['error' => 'not found']);
FAKE);

$port = 0;
for ($try = 18761; $try < 18830; $try++) {
    $probe = @fsockopen('127.0.0.1', $try, $e1, $e2, 0.2);
    if ($probe === false) { $port = $try; break; }
    fclose($probe);
}
$pid = 0;
if ($port > 0) {
    $pid = (int)trim((string)shell_exec('XERIC_FAKE_IMAGE_LOG=' . escapeshellarg($wire) . ' '
        . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router)
        . ' > ' . escapeshellarg($dir . '/server.log') . ' 2>&1 & echo $!'));
}
$up = false;
for ($i = 0; $port > 0 && $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s !== false) { fclose($s); $up = true; break; }
    usleep(100000);
}
ok('the loopback images machine answers', $up, (string)@file_get_contents($dir . '/server.log'));

$base = 'http://127.0.0.1:' . $port;
$last = function () use ($wire): array {
    $rows = file($wire, FILE_IGNORE_NEW_LINES) ?: [];
    return $rows === [] ? [] : (json_decode((string)end($rows), true) ?: []);
};
$composed = xeric_photo_prompt($T, 'message', ['handle' => 'ruth', 'ask' => 'a selfie by the coffee urn']);
$likeness = ['bytes' => $png, 'mime' => 'image/png', 'fingerprint' => 'x', 'id' => 1];

$r = xeric_image_render(['base' => $base . '/edits', 'edits' => true], $composed, ['reference' => $likeness]);
$w = $last();
ok('wire: with a likeness and edits, the render goes multipart to /v1/images/edits',
    $r['bytes'] === $png && ($w['route'] ?? '') === 'edits'
    && str_starts_with((string)($w['ctype'] ?? ''), 'multipart/form-data'), json_encode($w));
ok('wire: and the body carries the image and the prompt, seed and b64 asked for',
    ($w['image'] ?? '') === base64_encode($png) && ($w['image_type'] ?? '') === 'image/png'
    && ($w['post']['prompt'] ?? '') === $composed['prompt']
    && ($w['post']['seed'] ?? '') === (string)$composed['seeds']['face']
    && ($w['post']['n'] ?? '') === '1' && ($w['post']['response_format'] ?? '') === 'b64_json', json_encode($w['post'] ?? null));

xeric_image_render(['base' => $base . '/bare'], $composed, ['reference' => $likeness]);
$w = $last();
ok('wire: a likeness offered to a machine without edits falls back to generations, unchanged',
    ($w['kind'] ?? '') === 'bare' && ($w['route'] ?? '') === 'generations'
    && ($w['body'] ?? null) === ['prompt' => $composed['prompt'], 'seed' => $composed['seeds']['face'],
                                 'n' => 1, 'response_format' => 'b64_json'], json_encode($w));
xeric_image_render(['base' => $base . '/edits', 'edits' => true], $composed, []);
ok('wire: and no likeness on an edits machine is the generations call too',
    ($last()['route'] ?? '') === 'generations' && ($last()['kind'] ?? '') === 'edits');

// The machines screen's probe, run where it lives (the web layer), in its own process.
$probe = function (string $b): string {
    $lib = realpath(__DIR__ . '/../../forge/web/model-lib.php');
    return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'require ' . var_export($lib, true) . '; echo xeric_model_image_edits(' . var_export($b, true) . ') ? "yes" : "no";')
        . ' 2>/dev/null'));
};
ok('probe: a machine that refuses the empty form at /v1/images/edits takes a reference',
    $probe($base . '/edits') === 'yes');
ok('probe: a 404 there does not', $probe($base . '/bare') === 'no');
ok('probe: nor does a closed port', $probe('http://127.0.0.1:1') === 'no');

if ($pid > 0) @posix_kill($pid, 15) || @shell_exec('kill ' . $pid);
@shell_exec('rm -rf ' . escapeshellarg($dir));

echo $FAILED === 0 ? "\nall ok\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
