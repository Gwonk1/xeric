<?php
/**
 * Xeric — ears tests.  `php engine/tests/ears-test.php`, exit 0 on pass.
 *
 * The player speaks, and every promise engine/ears.php makes is checked: the
 * machine is found in the same order a voice machine is (resolver, then the
 * environment, off is off), dormant is null and never an error, the
 * recording is what it says it is before anything is spent, the room's names
 * ride as Whisper's prompt, Whisper's silence sign-offs never reach the
 * player's composer, and a machine that refuses says why. The wire is
 * exercised twice: through the stub, and against a thirty-line transcriber
 * this file starts on a loopback port, so the multipart body, the key's
 * header and the refusal's words are not tested only by assumption.
 */

declare(strict_types=1);

require_once __DIR__ . '/../ears.php';

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

/** Does $fn throw a RuntimeException whose message contains $needle (and, if given, with this code)? */
function throws(callable $fn, string $needle = '', ?int $code = null): bool
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return ($needle === '' || str_contains($e->getMessage(), $needle))
            && ($code === null || $e->getCode() === $code);
    }
    return false;
}

$dir = sys_get_temp_dir() . '/xeric-ears-test-' . getmypid();
@mkdir($dir, 0775, true);
$pid = 0;
register_shutdown_function(function () use (&$pid, $dir): void {
    if ($pid > 0) @exec('kill ' . (int)$pid . ' 2>/dev/null');
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) @unlink($f); }
    @rmdir($dir);
});

$envKeep = [];
foreach (['XERIC_EARS_BASE', 'XERIC_EARS_KEY', 'XERIC_EARS_MODEL', 'XERIC_EARS_LANGUAGE'] as $k) {
    $envKeep[$k] = getenv($k);
    putenv($k);
}

$METER = [];
xeric_ears_meter(function (array $u, string $where) use (&$METER): void {
    $METER[] = [$u, $where];
    throw new RuntimeException('the ledger is locked');   // a sink that throws must not take the line down
});

/** A WAV of $secs seconds of 8 kHz 8-bit mono silence: a header that says its own length. */
function wav(float $secs): string
{
    $rate = 8000;
    $data = str_repeat("\x80", (int)($rate * $secs));
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
         . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1) . pack('V', $rate) . pack('V', $rate)
         . pack('v', 1) . pack('v', 8)
         . 'data' . pack('V', strlen($data)) . $data;
}
$WEBM = "\x1A\x45\xDF\xA3" . str_repeat("\x00", 60);
$OGG  = 'OggS' . str_repeat("\x00", 60);
$MP4  = "\x00\x00\x00\x18ftypmp42" . str_repeat("\x00", 60);

// ---------------------------------------------------------------------------
echo "# the machine\n";
// ---------------------------------------------------------------------------

ok('dormant is the shipping state: no resolver, no environment, no machine',
    xeric_ears_endpoint() === null && !xeric_ears_up() && !xeric_ears_costly());
ok('and dormant hears nothing, without an error', xeric_ears_hear($WEBM, 'audio/webm') === null);

putenv('XERIC_EARS_BASE=http://127.0.0.1:1');
$ep = xeric_ears_endpoint();
ok('the environment names a machine, and the defaults fill the rest',
    $ep !== null && $ep['base'] === 'http://127.0.0.1:1' && $ep['key'] === ''
    && $ep['model'] === 'whisper-1' && $ep['language'] === '', json_encode($ep));
putenv('XERIC_EARS_MODEL=large-v3');
putenv('XERIC_EARS_LANGUAGE=EN');
putenv('XERIC_EARS_KEY=sk-ears-test');
$ep = xeric_ears_endpoint();
ok('model, language and key come from the environment when it says them',
    $ep['model'] === 'large-v3' && $ep['language'] === 'en' && $ep['key'] === 'sk-ears-test', json_encode($ep));
putenv('XERIC_EARS_LANGUAGE=not a language');
ok('a language that is not one is "let the machine tell", not an error', xeric_ears_endpoint()['language'] === '');
ok('a configured machine that is not answering is down, and hears nothing',
    !xeric_ears_up() && xeric_ears_hear($WEBM, 'audio/webm') === null);
ok('loopback is the owner\'s own electricity', !xeric_ears_costly());
putenv('XERIC_EARS_BASE=https://api.example.com');
ok('anywhere else is somebody\'s meter', xeric_ears_costly());

xeric_ears_source(fn() => ['base' => 'http://127.0.0.1:2', 'key' => '']);
ok('the resolver outranks the environment', xeric_ears_endpoint()['base'] === 'http://127.0.0.1:2');
xeric_ears_source(fn() => false);
ok('a resolver that says false is explicitly none, environment and all', xeric_ears_endpoint() === null);
xeric_ears_source(fn() => null);
ok('a resolver with nothing to say falls to the environment', xeric_ears_endpoint()['base'] === 'https://api.example.com');
putenv('XERIC_EARS_BASE');
putenv('XERIC_EARS_MODEL');
putenv('XERIC_EARS_LANGUAGE');
putenv('XERIC_EARS_KEY');

$stubUp = ['stub' => fn() => null];
ok('a stub is always up, and never costs anything', xeric_ears_up($stubUp) && !xeric_ears_costly($stubUp));

// ---------------------------------------------------------------------------
echo "\n# the bytes\n";
// ---------------------------------------------------------------------------

ok('the types a MediaRecorder names fold to one spelling',
    xeric_ears_type('audio/webm;codecs=opus') === 'audio/webm' && xeric_ears_type('video/webm') === 'audio/webm'
    && xeric_ears_type('audio/mp4') === 'audio/mp4' && xeric_ears_type('audio/ogg; codecs=opus') === 'audio/ogg'
    && xeric_ears_type('audio/x-wav') === 'audio/wav' && xeric_ears_type('audio/mp3') === 'audio/mpeg');
ok('and anything else is not a recording', xeric_ears_type('image/png') === '' && xeric_ears_type('') === ''
    && xeric_ears_type('text/html') === '');
ok('the sniff knows the five containers',
    xeric_ears_sniff($WEBM) === 'audio/webm' && xeric_ears_sniff($OGG) === 'audio/ogg'
    && xeric_ears_sniff($MP4) === 'audio/mp4' && xeric_ears_sniff(wav(0.1)) === 'audio/wav'
    && xeric_ears_sniff('ID3' . "\x04") === 'audio/mpeg' && xeric_ears_sniff("\x89PNG") === '');
ok('a WAV says how long it is', abs((float)xeric_ears_seconds(wav(2.0), 'audio/wav') - 2.0) < 0.01);
ok('and nothing else is parsed for it', xeric_ears_seconds($WEBM, 'audio/webm') === null);
ok('the file name follows the type, because servers read the extension',
    xeric_ears_filename('audio/webm') === 'audio.webm' && xeric_ears_filename('audio/mp4') === 'audio.mp4'
    && xeric_ears_filename('audio/mpeg') === 'audio.mp3' && xeric_ears_filename('audio/ogg') === 'audio.ogg'
    && xeric_ears_filename('audio/wav') === 'audio.wav');

$CALLS = [];
$stub = ['stub' => function (string $what, array $req) use (&$CALLS): ?array {
    $CALLS[] = [$what, $req];
    return ['text' => ' Morning, Dot.  Is the pie fresh? ', 'usage' => ['seconds' => 2.5]];
}];

ok('an empty recording is a 400, and the machine is never asked',
    throws(fn() => xeric_ears_hear('', 'audio/webm', [], $stub), 'nothing', 400) && $CALLS === []);
ok('a recording whose bytes disagree with its type is refused before anything is spent',
    throws(fn() => xeric_ears_hear($OGG, 'audio/webm', [], $stub), 'not what it says', 415) && $CALLS === []);
ok('a picture calling itself audio is refused',
    throws(fn() => xeric_ears_hear("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 40), 'audio/mp4', [], $stub), '', 415)
    && $CALLS === []);
ok('a type this does not hear is refused', throws(fn() => xeric_ears_hear($WEBM, 'text/plain', [], $stub), '', 415));
ok('more than 25 MB is a 413',
    throws(fn() => xeric_ears_hear($WEBM . str_repeat("\x00", XERIC_EARS_MAX_BYTES), 'audio/webm', [], $stub), '', 413)
    && $CALLS === []);
ok('a WAV longer than thirty seconds is a 413',
    throws(fn() => xeric_ears_hear(wav(31), 'audio/wav', [], $stub), '30 seconds', 413) && $CALLS === []);

// ---------------------------------------------------------------------------
echo "\n# the stub: a round trip\n";
// ---------------------------------------------------------------------------

$METER = [];
$h = xeric_ears_hear($WEBM, 'audio/webm;codecs=opus', ['names' => ['Dot Pruitt', 'Harlan', 'dot pruitt', '', "Ruth\nX"]], $stub);
ok('the transcript comes back, trimmed', ($h['text'] ?? null) === 'Morning, Dot. Is the pie fresh?', json_encode($h));
ok('the machine was asked once, with the bytes and the file name the type gives',
    count($CALLS) === 1 && $CALLS[0][0] === 'transcribe' && $CALLS[0][1]['bytes'] === $WEBM
    && $CALLS[0][1]['filename'] === 'audio.webm' && $CALLS[0][1]['model'] === 'whisper-1'
    && $CALLS[0][1]['response_format'] === 'json');
ok('the names present ride as the prompt, distinct, and nothing that could break a field',
    ($CALLS[0][1]['prompt'] ?? '') === 'Dot Pruitt, Harlan, Ruth X', json_encode($CALLS[0][1]['prompt'] ?? null));
ok('no language unless one is said', !array_key_exists('language', $CALLS[0][1]));
ok('the meter is told once, as a hearing with its seconds, and a sink that throws takes nothing down',
    count($METER) === 1 && $METER[0][0]['hearings'] === 1 && $METER[0][0]['seconds'] === 2.5 && $METER[0][1] === 'stub');
xeric_ears_hear($WEBM, 'audio/webm', ['language' => 'fr'], ['model' => 'tiny'] + $stub);
ok('a language and a model are sent when said', ($CALLS[1][1]['language'] ?? '') === 'fr'
    && $CALLS[1][1]['model'] === 'tiny' && !array_key_exists('prompt', $CALLS[1][1]));

$refuse = ['stub' => function (): array { throw new RuntimeException('ears: the ears machine said HTTP 400: Invalid file format'); }];
ok('a machine that refuses throws in its own words', throws(fn() => xeric_ears_hear($WEBM, 'audio/webm', [], $refuse),
    'Invalid file format'));
ok('a stub with no text is an error, not an empty line',
    throws(fn() => xeric_ears_hear($WEBM, 'audio/webm', [], ['stub' => fn() => ['nope' => 1]]), 'no text'));

// ---------------------------------------------------------------------------
echo "\n# what Whisper hears in silence\n";
// ---------------------------------------------------------------------------

foreach (['Thank you.', ' thanks for watching! ', 'you', 'You', '[BLANK_AUDIO]', '[Music]', '(silence)',
          'Thank you for watching.', 'Subtitles by the Amara.org community', '', '   ', '♪'] as $s) {
    ok('dropped: ' . json_encode($s), xeric_ears_clean($s) === '');
}
foreach (['Thank you, Dot.' => 'Thank you, Dot.', 'you said that yesterday' => 'you said that yesterday',
          '[BLANK_AUDIO] Morning. [BLANK_AUDIO]' => 'Morning.', 'Thanks for the pie.' => 'Thanks for the pie.'] as $in => $want) {
    ok('kept: ' . json_encode($in), xeric_ears_clean($in) === $want, json_encode(xeric_ears_clean($in)));
}
$long = str_repeat('This is a sentence that goes on. ', 60);
$cut  = xeric_ears_clean($long);
ok('a long transcript is capped at 1,200 characters, on a sentence', mb_strlen($cut) <= 1200 && str_ends_with($cut, 'on.'));
$silent = ['stub' => fn() => ['text' => 'Thank you.']];
ok('a hearing of silence is an empty line, not a line nobody said',
    (xeric_ears_hear($WEBM, 'audio/webm', [], $silent)['text'] ?? null) === '');

// ---------------------------------------------------------------------------
echo "\n# the wire, against a transcriber on loopback\n";
// ---------------------------------------------------------------------------

$router = $dir . '/fake-ears.php';
$wire   = $dir . '/wire.log';
file_put_contents($router, <<<'FAKE'
<?php
// A transcriber in thirty lines. /ok hears what the prompt names; /refuse
// refuses the way a real server does; /pretend answers 200 with no text.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$json = function (int $code, $body): bool {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
    return true;
};
if (!preg_match('#^/(ok|refuse|pretend)(/.*)?$#', $path, $m)) return $json(404, ['detail' => 'Not Found']);
[$kind, $rest] = [$m[1], $m[2] ?? '/'];
if ($rest === '/') { echo 'an ears machine'; return true; }
if ($rest === '/v1/audio/transcriptions' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $f    = $_FILES['file'] ?? [];
    $hdrs = function_exists('getallheaders') ? getallheaders() : [];
    file_put_contents((string)getenv('XERIC_FAKE_EARS_LOG'), json_encode([
        'kind' => $kind, 'headers' => $hdrs, 'post' => $_POST,
        'file' => ['name' => $f['name'] ?? '', 'type' => $f['type'] ?? '', 'size' => $f['size'] ?? 0,
                   'head' => isset($f['tmp_name']) ? bin2hex((string)file_get_contents($f['tmp_name'], false, null, 0, 4)) : ''],
    ]) . "\n", FILE_APPEND);
    if ($kind === 'refuse')  return $json(400, ['error' => ['message' => 'Audio file is too short']]);
    if ($kind === 'pretend') return $json(200, ['ok' => true]);
    return $json(200, ['text' => 'Morning, ' . explode(',', (string)($_POST['prompt'] ?? 'nobody'))[0] . '.',
                       'usage' => ['type' => 'duration', 'seconds' => 3]]);
}
return $json(404, ['detail' => 'Not Found']);
FAKE);

$port = 0;
for ($try = 19031; $try < 19099; $try++) {
    $probe = @fsockopen('127.0.0.1', $try, $e1, $e2, 0.2);
    if ($probe === false) { $port = $try; break; }
    fclose($probe);
}
if ($port > 0) {
    $pid = (int)trim((string)shell_exec('XERIC_FAKE_EARS_LOG=' . escapeshellarg($wire) . ' '
        . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router)
        . ' > ' . escapeshellarg($dir . '/server.log') . ' 2>&1 & echo $!'));
}
$up = false;
for ($i = 0; $port > 0 && $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s !== false) { fclose($s); $up = true; break; }
    usleep(100000);
}
ok('the loopback ears machine answers', $up, (string)@file_get_contents($dir . '/server.log'));

$base = 'http://127.0.0.1:' . $port;
$good = ['base' => $base . '/ok', 'key' => "sk-wire\r\nX-Injected: yes", 'language' => 'en'];
$wireLog = function () use ($wire): array {
    $rows = [];
    foreach (file($wire, FILE_IGNORE_NEW_LINES) ?: [] as $l) $rows[] = json_decode($l, true);
    return $rows;
};

$METER = [];
$w1 = xeric_ears_hear($WEBM, 'audio/webm;codecs=opus', ['names' => ['Dot', 'Harlan']], $good);
$log = $wireLog();
ok('wire: the transcript comes back from a real multipart POST', ($w1['text'] ?? '') === 'Morning, Dot.', json_encode($w1));
ok('wire: the recording is the file field, under the type\'s name, bytes intact',
    ($log[0]['file']['name'] ?? '') === 'audio.webm' && ($log[0]['file']['type'] ?? '') === 'audio/webm'
    && ($log[0]['file']['size'] ?? 0) === strlen($WEBM) && ($log[0]['file']['head'] ?? '') === '1a45dfa3',
    json_encode($log[0]['file'] ?? null));
ok('wire: model, language, format and the names as prompt are form fields',
    ($log[0]['post'] ?? []) === ['model' => 'whisper-1', 'response_format' => 'json', 'language' => 'en',
                                 'prompt' => 'Dot, Harlan'], json_encode($log[0]['post'] ?? null));
$h = array_change_key_case((array)($log[0]['headers'] ?? []), CASE_LOWER);
ok('wire: the key rides as a Bearer token, and a line break in it cannot become a header',
    ($h['authorization'] ?? '') === 'Bearer sk-wireX-Injected: yes' && !isset($h['x-injected']), json_encode($h));
ok('wire: the meter is told the seconds the machine reported, and where, never with what key',
    count($METER) === 1 && (float)$METER[0][0]['seconds'] === 3.0 && $METER[0][1] === $base . '/ok');
ok('wire: a machine that refuses says why, and the reason reaches the error',
    throws(fn() => xeric_ears_hear($WEBM, 'audio/webm', [], ['base' => $base . '/refuse']), 'HTTP 400: Audio file is too short'));
ok('wire: a 200 with no transcript is an error page wearing a success code',
    throws(fn() => xeric_ears_hear($WEBM, 'audio/webm', [], ['base' => $base . '/pretend']), 'without a transcript'));
ok('wire: a closed port hears nothing, quickly, and that is not an error',
    xeric_ears_hear($WEBM, 'audio/webm', [], ['base' => 'http://127.0.0.1:1']) === null);

// ---------------------------------------------------------------------------

foreach ($envKeep as $k => $v) { if ($v !== false) putenv($k . '=' . $v); }

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
