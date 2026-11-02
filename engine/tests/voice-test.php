<?php
/**
 * Xeric — voice tests.  `php engine/tests/voice-test.php`, exit 0 on pass.
 *
 * The cast has voices, and every promise engine/voice.php makes is checked
 * from both sides: the deal is derived and stable (and blind to everything
 * about a person but their name), the owner's pick wins only when the machine
 * can honour it, the spoken text is the shown text minus what a voice cannot
 * say, and the clip is rendered once, filed per message, metered, and never
 * attempted without words, a person and an answering machine. The wire is
 * exercised twice: through the stub every other test uses, and against a
 * forty-line voice machine this file starts on a loopback port, so the parts
 * that will actually meet a real server — statuses, content types, the
 * inventory's shapes, the key's header — are not tested only by assumption.
 */

declare(strict_types=1);

require_once __DIR__ . '/../voice.php';

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

/** Does $fn throw a RuntimeException whose message contains $needle? */
function throws(callable $fn, string $needle = ''): bool
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $needle === '' || str_contains($e->getMessage(), $needle);
    }
    return false;
}

$T = xeric_template_load(__DIR__ . '/../fixtures/milldale.json');

/** Milldale with one character's (or fixture's) template node edited. */
function with_node(array $t, string $handle, callable $edit): array
{
    foreach ($t['cast']['characters'] as $i => $c) {
        if ($c['handle'] === $handle) { $t['cast']['characters'][$i] = $edit($c); return $t; }
    }
    foreach ($t['cast']['fixtures'] as $i => $f) {
        if ($f['key'] === $handle) { $t['cast']['fixtures'][$i] = $edit($f); return $t; }
    }
    throw new LogicException("no $handle in the fixture");
}

$dir = sys_get_temp_dir() . '/xeric-voice-test-' . getmypid();
$dbP = $dir . '-db.sqlite';
foreach ([$dbP, $dbP . '-wal', $dbP . '-shm'] as $f) @unlink($f);
@mkdir($dir, 0775, true);
$db = xeric_state_open($dbP);

// Whatever happens below — a failed assertion or a fatal — the loopback
// machine is stopped and the scratch files go. $pid is filled in when the
// machine is started, near the end.
$pid = 0;
register_shutdown_function(function () use (&$pid, $dir, $dbP): void {
    if ($pid > 0) @exec('kill ' . (int)$pid . ' 2>/dev/null');
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) @unlink($f); }
    @rmdir($dir);
    foreach ([$dbP, $dbP . '-wal', $dbP . '-shm'] as $f) @unlink($f);
});

// Nothing from the environment may leak into the dormant checks below.
$envKeep = [];
foreach (['XERIC_VOICE_BASE', 'XERIC_VOICE_KEY', 'XERIC_VOICE_MODEL', 'XERIC_VOICE_FORMAT'] as $k) {
    $envKeep[$k] = getenv($k);
    putenv($k);
}

// One meter for the whole file: every render lands here.
$METER = [];
$METER_THROWS = false;
xeric_voice_meter(function (array $u, string $where) use (&$METER, &$METER_THROWS): void {
    $METER[] = [$u, $where];
    if ($METER_THROWS) throw new RuntimeException('the ledger is locked');
});

// A Kokoro-shaped inventory: English and otherwise, spelled the way it spells.
$KOKORO = ['af_alloy', 'af_bella', 'af_heart', 'af_jessica', 'af_nicole', 'af_nova', 'af_river', 'af_sarah',
           'af_sky', 'am_adam', 'am_echo', 'am_eric', 'am_liam', 'am_michael', 'am_onyx', 'am_puck',
           'bf_alice', 'bf_emma', 'bf_isabella', 'bf_lily', 'bm_daniel', 'bm_fable', 'bm_george', 'bm_lewis',
           'ef_dora', 'ff_siwis', 'hf_alpha', 'if_sara', 'jf_alpha', 'jf_nezumi', 'jm_kumo', 'pf_dora',
           'zf_xiaobei', 'zm_yunxi'];

// ---------------------------------------------------------------------------
echo "# the machine\n";
// ---------------------------------------------------------------------------

ok('dormant is the shipping state: no resolver, no environment, no machine',
    xeric_voice_endpoint() === null && !xeric_voice_up() && !xeric_voice_costly());

putenv('XERIC_VOICE_BASE=http://127.0.0.1:1');
$ep = xeric_voice_endpoint();
ok('the environment names a machine, and the defaults fill the rest',
    $ep !== null && $ep['base'] === 'http://127.0.0.1:1' && $ep['key'] === ''
    && $ep['model'] === 'tts-1' && $ep['format'] === 'mp3', json_encode($ep));
putenv('XERIC_VOICE_MODEL=kokoro');
putenv('XERIC_VOICE_FORMAT=opus');
putenv('XERIC_VOICE_KEY=sk-voice-test');
$ep = xeric_voice_endpoint();
ok('model, format and key come from the environment when it says them',
    $ep['model'] === 'kokoro' && $ep['format'] === 'opus' && $ep['key'] === 'sk-voice-test', json_encode($ep));
putenv('XERIC_VOICE_FORMAT=WAV');
ok('a format is read without regard to case', xeric_voice_endpoint()['format'] === 'wav');
putenv('XERIC_VOICE_FORMAT=flac');
ok('a format this engine does not keep is the default, not an error',
    xeric_voice_endpoint()['format'] === 'mp3');
putenv('XERIC_VOICE_MODEL=   ');
ok('a blank model is the default model', xeric_voice_endpoint()['model'] === 'tts-1');
ok('a configured machine that is not answering is down, not broken', !xeric_voice_up());
ok('loopback is the owner\'s own electricity', !xeric_voice_costly());
putenv('XERIC_VOICE_BASE=https://api.example.com');
ok('anywhere else is somebody\'s meter', xeric_voice_costly());

$stubUp = ['stub' => fn() => null];
ok('a stub is always up, and never costs anything', xeric_voice_up($stubUp) && !xeric_voice_costly($stubUp));
ok('settings default on an endpoint that never said them',
    xeric_voice_settings($stubUp) === ['model' => 'tts-1', 'format' => 'mp3']);

xeric_voice_source(fn() => $stubUp);
ok('the web layer\'s resolver is asked first', (xeric_voice_endpoint()['stub'] ?? null) !== null);
xeric_voice_source(fn() => false);
ok('a resolver that says "explicitly none" beats the environment', xeric_voice_endpoint() === null);
xeric_voice_source(fn() => null);
ok('a resolver with nothing to say falls through to the environment',
    (xeric_voice_endpoint()['base'] ?? '') === 'https://api.example.com');
xeric_voice_source(fn() => ['base' => 'http://127.0.0.1:8880']);
ok('a resolver\'s machine gets the defaults too', xeric_voice_settings(xeric_voice_endpoint())['model'] === 'tts-1');
xeric_voice_source(fn() => null);
foreach (['XERIC_VOICE_BASE', 'XERIC_VOICE_KEY', 'XERIC_VOICE_MODEL', 'XERIC_VOICE_FORMAT'] as $k) putenv($k);
ok('and with the environment cleared again it is dormant again', xeric_voice_endpoint() === null);

// ---------------------------------------------------------------------------
echo "\n# the inventory\n";
// ---------------------------------------------------------------------------

$pl = xeric_voice_parse_list(['af_bella', 'am_adam']);
ok('a list of names is a list of voices',
    array_column($pl, 'id') === ['af_bella', 'am_adam'] && $pl[0]['name'] === 'af_bella', json_encode($pl));
$pl = xeric_voice_parse_list('{"voices":["af_bella","bf_emma","jf_alpha"]}');
ok('Kokoro\'s envelope, as the JSON it arrives as',
    array_column($pl, 'id') === ['af_bella', 'bf_emma', 'jf_alpha'], json_encode($pl));
ok('and a Kokoro id spells its language in its first letter, which is all that is read of it',
    array_column($pl, 'language') === ['en-us', 'en-gb', 'ja']);
$pl = xeric_voice_parse_list(['voices' => [
    ['voice_id' => 'r4ch3l', 'name' => 'Rachel', 'labels' => ['gender' => 'female', 'accent' => 'american']],
    ['id' => 'en_US-amy-medium', 'name' => 'Amy', 'gender' => 'F', 'language' => 'en_US'],
]]);
ok('objects: the wire id is voice_id or id, and the name is what a person reads',
    array_column($pl, 'id') === ['r4ch3l', 'en_US-amy-medium'] && array_column($pl, 'name') === ['Rachel', 'Amy'],
    json_encode($pl));
ok('gender and labels ride along when the machine said them — for the owner\'s picker',
    $pl[0]['gender'] === 'female' && $pl[0]['labels']['accent'] === 'american' && $pl[1]['gender'] === 'f');
ok('a stated language is normalised, and a non-Kokoro id is never read for one',
    $pl[1]['language'] === 'en-us' && $pl[0]['language'] === '');
$pl = xeric_voice_parse_list(['object' => 'list', 'data' => [['id' => 'alloy', 'object' => 'voice'], ['id' => 'nova']]]);
ok('the {"data": …} envelope', array_column($pl, 'id') === ['alloy', 'nova']);
$pl = xeric_voice_parse_list(['voices' => ['af_bella' => ['language' => 'en-us'], 'x9' => 'Display Name']]);
ok('a map of id => details, or id => display name',
    array_column($pl, 'id') === ['af_bella', 'x9'] && $pl[1]['name'] === 'Display Name', json_encode($pl));
ok('duplicates and blanks are dropped',
    array_column(xeric_voice_parse_list(['a1', 'a1', '', '  ', 'b2']), 'id') === ['a1', 'b2']);
$garbage = [null, 42, 'not json at all', '{"detail":"Not Found"}', '[]', [null, [], 7.5, ['foo' => 'bar'], "bad\x01id"],
            ['voices' => 'nope'], true];
ok('garbage is an empty inventory, in every shape garbage comes in',
    array_filter(array_map('xeric_voice_parse_list', $garbage)) === []);
ok('a language that is not a language tag is unknown, not a guess',
    xeric_voice_parse_list([['id' => 'v1', 'language' => 'English']])[0]['language'] === '');

// ---------------------------------------------------------------------------
echo "\n# who sounds like whom\n";
// ---------------------------------------------------------------------------

$ruth = xeric_voice_identity($T, 'ruth', $KOKORO);
ok('an identity: the person, the voice, the speed, the seed',
    $ruth !== null && $ruth['handle'] === 'ruth' && $ruth['name'] === 'Ruth Amberg'
    && in_array($ruth['voice'], $KOKORO, true) && $ruth['speed'] === 1.0 && $ruth['chosen'] === false,
    json_encode($ruth));
ok('the seed is crc32(world|voice|handle) — derived, never stored',
    $ruth['seed'] === crc32('Milldale|voice|ruth'));
ok('determinism: the same person draws the same voice every time',
    xeric_voice_identity($T, 'ruth', $KOKORO) === $ruth
    && xeric_voice_identity(xeric_template_load(__DIR__ . '/../fixtures/milldale.json'), 'ruth', $KOKORO) === $ruth);
$shuffled = $KOKORO;
mt_srand(7);
shuffle($shuffled);
ok('the deal is blind to the order the machine listed its voices in',
    xeric_voice_identity($T, 'ruth', $shuffled)['voice'] === $ruth['voice']);

$differ = 0;
$pairs  = 200;
for ($i = 0; $i < $pairs; $i++) {
    $a = xeric_voice_identity(with_node($T, 'ruth', fn($c) => ['handle' => "person$i"] + $c), "person$i", $KOKORO);
    $b = xeric_voice_identity(with_node($T, 'ruth', fn($c) => ['handle' => "person$i-b"] + $c), "person$i-b", $KOKORO);
    if ($a['voice'] !== $b['voice']) $differ++;
}
ok('two different people usually sound different (expected 1 in 24 to share)',
    $differ >= (int)($pairs * 0.9), "$differ of $pairs pairs differ");
$castVoices = [];
foreach ($T['cast']['characters'] as $c) $castVoices[] = xeric_voice_identity($T, $c['handle'], $KOKORO)['voice'];
ok('and the town is not one voice', count(array_unique($castVoices)) >= 3, json_encode($castVoices));

$english = true;
foreach ($T['cast']['characters'] as $c) {
    $v = xeric_voice_identity($T, $c['handle'], $KOKORO)['voice'];
    $english = $english && in_array($v[0], ['a', 'b'], true);
}
ok('an English world is dealt English voices, never a Japanese voice reading English', $english,
    json_encode($castVoices));
$Tit = $T;
$Tit['meta']['language'] = 'it';
ok('the world\'s language decides the pool, not anything about the person',
    xeric_voice_identity($Tit, 'ruth', $KOKORO)['voice'] === 'if_sara');
$Tde = $T;
$Tde['meta']['language'] = 'de';
ok('a world whose language the machine lacks still gets a voice, from all of them',
    in_array(xeric_voice_identity($Tde, 'ruth', $KOKORO)['voice'], $KOKORO, true));

// The deal is rendezvous, not modulo: a voice added moves only the people who
// now draw it, and a voice nobody drew changes nobody.
$moved = 0;
$moverTargets = [];
$plus = array_merge($KOKORO, ['af_newcomer']);
for ($i = 0; $i < 300; $i++) {
    $Tp = with_node($T, 'ruth', fn($c) => ['handle' => "p$i"] + $c);
    $was = xeric_voice_identity($Tp, "p$i", $KOKORO)['voice'];
    $now = xeric_voice_identity($Tp, "p$i", $plus)['voice'];
    if ($was !== $now) { $moved++; $moverTargets[$now] = true; }
}
ok('a voice added to the machine moves only the people who now draw it',
    $moved > 0 && $moved < 30 && array_keys($moverTargets) === ['af_newcomer'], "$moved moved");
$unused = array_values(array_diff($KOKORO, $castVoices, ['am_puck']));
$fewer  = array_values(array_diff($KOKORO, [$unused[0]]));
$same   = true;
foreach ($T['cast']['characters'] as $i => $c) {
    $same = $same && xeric_voice_identity($T, $c['handle'], $fewer)['voice'] === $castVoices[$i];
}
ok('and a voice nobody had, taken away, changes nobody', $same);

$fb = xeric_voice_identity($T, 'ruth', []);
ok('a machine that cannot list its voices deals from the six every server knows',
    in_array($fb['voice'], XERIC_VOICE_FALLBACK, true) && $fb['seed'] === $ruth['seed']);
$fbVoices = [];
for ($i = 0; $i < 120; $i++) {
    $fbVoices[xeric_voice_deal(xeric_photo_seed($T, 'voice', "fb$i"), XERIC_VOICE_FALLBACK)] = true;
}
ok('and every one of the six gets dealt to somebody', count($fbVoices) === count(XERIC_VOICE_FALLBACK));

// The owner's pick.
$Tpick = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => 'am_adam']]);
$pick  = xeric_voice_identity($Tpick, 'ruth', $KOKORO);
ok('the owner names a voice the machine has, and it wins',
    $pick['voice'] === 'am_adam' && $pick['chosen'] === true && $pick['seed'] === $ruth['seed']);
ok('a pick the machine does not have does not take — the deal stands, and says so',
    xeric_voice_identity($Tpick, 'ruth', ['af_bella', 'bf_emma']) !== null
    && xeric_voice_identity($Tpick, 'ruth', ['af_bella', 'bf_emma'])['chosen'] === false
    && in_array(xeric_voice_identity($Tpick, 'ruth', ['af_bella', 'bf_emma'])['voice'], ['af_bella', 'bf_emma'], true));
ok('with no inventory the owner knows the machine better than the engine: the pick wins as written',
    xeric_voice_identity($Tpick, 'ruth', [])['voice'] === 'am_adam');
$Tcase = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => 'AM_Adam']]);
ok('an owner who wrote the name in the wrong case meant the voice, spelled the machine\'s way',
    xeric_voice_identity($Tcase, 'ruth', $KOKORO)['voice'] === 'am_adam');
$Tmix = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => 'af_bella + AF_SKY(2)']]);
ok('a Kokoro blend takes when every voice in it is on the machine, weights kept',
    xeric_voice_identity($Tmix, 'ruth', $KOKORO)['voice'] === 'af_bella+af_sky(2)');
$Tmix2 = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => 'af_bella+af_nobody']]);
ok('and not when any part of it is missing', xeric_voice_identity($Tmix2, 'ruth', $KOKORO)['chosen'] === false);
$Tjunk = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => ['am_adam']]]);
ok('a malformed pick is no pick', xeric_voice_identity($Tjunk, 'ruth', $KOKORO) === $ruth);
$Tlist = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => 'Rachel']]);
ok('a pick matches the wire id, not a display name',
    xeric_voice_identity($Tlist, 'ruth', [['voice_id' => 'r4ch3l', 'name' => 'Rachel']])['chosen'] === false);

// Speed.
$speedOf = fn($v) => xeric_voice_identity(with_node($T, 'ruth', fn($c) => $c + ['speech' => ['speed' => $v]]),
                                          'ruth', $KOKORO)['speed'];
ok('speed clamps at the top', $speedOf(2.0) === 1.3);
ok('speed clamps at the bottom', $speedOf(0.1) === 0.7);
ok('a speed inside the range is kept', $speedOf(1.15) === 1.15 && $speedOf(1) === 1.0);
ok('a numeric string is a number', $speedOf(' 0.9 ') === 0.9);
ok('anything else is ordinary speed', $speedOf('fast') === 1.0 && $speedOf(true) === 1.0
    && $speedOf(NAN) === 1.0 && $speedOf(INF) === 1.0 && $speedOf(null) === 1.0);

// Nothing about a person but their name.
$Trecast = with_node($T, 'ruth', function (array $c): array {
    $c['pronouns'] = 'he/him';
    $c['age']      = 9;
    $c['voice']    = 'A deep, gravelly baritone; slow; never warm.';
    $c['appearance'] = 'Tall.';
    return $c;
});
ok('pronouns, age, appearance and the prose voice change nothing about which voice',
    xeric_voice_identity($Trecast, 'ruth', $KOKORO) === $ruth
    && xeric_voice_identity($Trecast, 'ruth', []) === $fb);
$labelled = array_map(fn($id) => ['id' => $id, 'gender' => ($id[1] ?? '') === 'f' ? 'female' : 'male',
                                  'labels' => ['gender' => 'whatever']], $KOKORO);
ok('and an inventory\'s gender labels change nothing about the deal',
    xeric_voice_identity($T, 'ruth', $labelled)['voice'] === $ruth['voice']
    && xeric_voice_identity($T, 'theo', $labelled)['voice'] === xeric_voice_identity($T, 'theo', $KOKORO)['voice']);

// Fixtures, and nobody.
$cy = xeric_voice_identity($T, 'cy', $KOKORO);
ok('speaking scenery has a voice of its own', $cy !== null && $cy['name'] === 'Cy Loomis'
    && $cy['seed'] === crc32('Milldale|voice|cy'));
$counter = xeric_voice_identity($T, 'harlan_counter', $KOKORO);
$harlan  = xeric_voice_identity($T, 'harlan', $KOKORO);
ok('the man behind the register IS Harlan: same seed, same voice, same name',
    $counter['voice'] === $harlan['voice'] && $counter['seed'] === $harlan['seed']
    && $counter['name'] === 'Harlan Beck' && $counter['handle'] === 'harlan_counter');
$Tcy = with_node($T, 'cy', fn($f) => $f + ['speech' => ['voice' => 'bm_george', 'speed' => 0.8]]);
ok('a fixture takes an owner\'s pick the same way', xeric_voice_identity($Tcy, 'cy', $KOKORO)['voice'] === 'bm_george'
    && xeric_voice_identity($Tcy, 'cy', $KOKORO)['speed'] === 0.8);
ok('nobody is nobody', xeric_voice_identity($T, 'no_such_person', $KOKORO) === null
    && xeric_voice_identity($T, '', $KOKORO) === null && xeric_voice_identity($T, ' ', []) === null);

// ---------------------------------------------------------------------------
echo "\n# what is said\n";
// ---------------------------------------------------------------------------

$says = [
    'plain words pass through untouched'                  => ["Morning. Coffee's on.", "Morning. Coffee's on."],
    'a message that is all stage direction says nothing'  => ['*nods*', ''],
    'several of them say nothing either'                  => ["*nods* *sips the coffee*\n(no reply)", ''],
    'a stage direction around the words goes'            => ['*sets the cup down* Fine. *wipes the counter*', 'Fine.'],
    'and one between sentences'                           => ['Fine. *sighs* Go on.', 'Fine. Go on.'],
    'with its own full stop'                              => ['*sighs*. Fine.', 'Fine.'],
    'emphasis inside a sentence keeps its word'           => ['I *told* you.', 'I told you.'],
    'and so does emphasis before a lowercase word'        => ['*Not* now.', 'Not now.'],
    'bold, underscores, strike and code keep their words' => ['**Never** __again__, _quietly_. ~~no~~ `ok`',
                                                             'Never again, quietly. no ok'],
    'an underscore inside a word is a word'               => ['snake_case_name', 'snake_case_name'],
    'a parenthesis on a line of its own goes'             => ["(long pause)\nFine.", 'Fine.'],
    'a row of them too, and a bracket'                    => ["(she laughs) (then stops)\n[beat]\nOkay.", 'Okay.'],
    'a parenthesis inside a sentence is an aside, spoken' => ['I saw Walt (the deacon) at the store.',
                                                             'I saw Walt (the deacon) at the store.'],
    'and so is a texted one after a sentence'             => ['See you at six. (Bring the pie.)',
                                                             'See you at six. (Bring the pie.)'],
    'links are not speech'                                => ['The list is at https://example.com/x?y=1, see you.',
                                                             'The list is at, see you.'],
    'nor is a bare address, and its seam closes'          => ['Look: www.example.org.', 'Look.'],
    'a markdown link keeps its words'                     => ['Read [the notice](https://example.com/n) first.',
                                                             'Read the notice first.'],
    'the photo caption the thread stores is not spoken'   => ["Give me a second.\n📷 Ruth the counter at golden hour",
                                                             'Give me a second.'],
    'a message that is only a caption says nothing'       => ['📷 Ruth by the urn', ''],
    'nor is a "photo:" line, or a [photo: …] marker'      => ["photo: Ruth by the urn, mid-laugh\nHere. [photo: the urn]",
                                                             'Here.'],
    'whitespace collapses'                                => ["Fine.\n\n   Go   on.", 'Fine. Go on.'],
    'a heart is not a word'                               => ['Love you ❤️ 👍🏽', 'Love you'],
    'punctuation alone is nothing to say'                 => ['...', ''],
    'nothing is nothing'                                  => ["  \n ", ''],
];
foreach ($says as $name => [$in, $want]) {
    $got = xeric_voice_text($in);
    ok('text: ' . $name, $got === $want, json_encode($got, JSON_UNESCAPED_UNICODE));
}
ok('text: invalid bytes never reach a regex unscrubbed', xeric_voice_text("ok \xff\xfe then") !== '');
$long = str_repeat('The river was high this year and nobody went down to look at it. ', 40);
$cut  = xeric_voice_text($long);
ok('text: a long message is capped on a sentence boundary',
    mb_strlen($cut) <= XERIC_VOICE_MAX_CHARS && str_ends_with($cut, 'look at it.') && mb_strlen($cut) > 1000,
    (string)mb_strlen($cut));
$run = xeric_voice_text(str_repeat('word ', 600));
ok('text: and a run-on with no sentence in it is capped at a word',
    mb_strlen($run) <= XERIC_VOICE_MAX_CHARS && str_ends_with($run, 'word') && !str_ends_with($run, ' '));
$uni = xeric_voice_text(str_repeat('Café — “naïve”, déjà vu. ', 80));
ok('text: the cap counts characters, not bytes, and never splits one',
    mb_check_encoding($uni, 'UTF-8') && mb_strlen($uni) <= XERIC_VOICE_MAX_CHARS && mb_strlen($uni) > 1100
    && str_ends_with($uni, 'déjà vu.'), (string)mb_strlen($uni));
ok('text: deterministic, byte for byte', xeric_voice_text($long) === $cut
    && xeric_voice_text('*sighs* Fine. **Go**.') === xeric_voice_text('*sighs* Fine. **Go**.'));

// ---------------------------------------------------------------------------
echo "\n# the clip\n";
// ---------------------------------------------------------------------------

xeric_voice_schema($db);
xeric_voice_schema($db);
$cols = array_column($db->query('PRAGMA table_info(voice_clips)')->fetchAll(), 'name');
ok('the table is made where it is used, and making it twice is making it once',
    $cols === ['id', 'handle', 'message_id', 'fingerprint', 'file', 'mime', 'created_at'], json_encode($cols));
$mem = new PDO('sqlite::memory:');
ok('a database that has never heard of voices can be asked about one', xeric_voice_of($mem, 1) === null
    && (int)$mem->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'voice_clips'")->fetchColumn() === 1);

$CALLS = [];
$stub = ['stub' => function (string $what, array $req) use (&$CALLS): ?array {
    $CALLS[] = [$what, $req];
    if ($what === 'voices') return ['af_bella', 'am_adam', 'bf_emma', 'jf_alpha'];
    return ['bytes' => 'FAKE-AUDIO:' . $req['voice'] . ':' . $req['input'], 'mime' => 'audio/mpeg'];
}];
$INV  = ['af_bella', 'am_adam', 'bf_emma'];
$line = "*pours the coffee* Morning. **Sit** down before you fall down.\n📷 Ruth by the urn";
$said = 'Morning. Sit down before you fall down.';
$rId  = xeric_voice_identity($T, 'ruth', $INV);
$fp   = xeric_voice_fingerprint($rId['voice'], 1.0, 'tts-1', 'mp3', $said);

$c1 = xeric_voice_clip($T, $db, $dir, 'ruth', 1, $line, $stub, $INV);
ok('a clip renders through the stub, onto disk, under the handle and the fingerprint',
    $c1 !== null && $c1['cached'] === false && $c1['mime'] === 'audio/mpeg'
    && $c1['file'] === 'voice-ruth-' . substr($fp, 0, 12) . '.mp3' && is_file($dir . '/' . $c1['file']),
    json_encode($c1));
ok('the machine was asked for exactly the spoken words, in her voice, at her speed',
    count($CALLS) === 1 && $CALLS[0][0] === 'speech'
    && $CALLS[0][1] === ['model' => 'tts-1', 'input' => $said, 'voice' => $rId['voice'],
                         'response_format' => 'mp3', 'speed' => 1.0], json_encode($CALLS));
ok('the file holds what the machine said', file_get_contents($dir . '/' . $c1['file'])
    === 'FAKE-AUDIO:' . $rId['voice'] . ':' . $said);
$row1 = xeric_voice_of($db, 1);
ok('and the row files it under the message',
    $row1 !== null && $row1['handle'] === 'ruth' && (int)$row1['message_id'] === 1
    && $row1['fingerprint'] === $fp && $row1['file'] === $c1['file'] && $row1['mime'] === 'audio/mpeg',
    json_encode($row1));
ok('one render, metered: one clip and the characters spoken, at the stub',
    count($METER) === 1 && $METER[0][0] === ['clips' => 1, 'chars' => mb_strlen($said)] && $METER[0][1] === 'stub',
    json_encode($METER));

$c2 = xeric_voice_clip($T, $db, $dir, 'ruth', 1, $line, $stub, $INV);
ok('the second time it is cached: same file, and the machine is never asked',
    $c2 === ['file' => $c1['file'], 'mime' => 'audio/mpeg', 'cached' => true] && count($CALLS) === 1);
ok('a cached clip spent nothing, so the meter heard nothing', count($METER) === 1);

$c3 = xeric_voice_clip($T, $db, $dir, 'ruth', 2, 'Morning.  Sit down before you **fall** down.', $stub, $INV);
$row2 = xeric_voice_of($db, 2);
ok('another message saying the same words shares the render and gets its own row',
    $c3 !== null && $c3['cached'] && $c3['file'] === $c1['file'] && count($CALLS) === 1
    && $row2 !== null && (int)$row2['message_id'] === 2 && (int)$row2['id'] !== (int)$row1['id']);
ok('the row never holds a machine address or a key',
    !str_contains(json_encode($row1), 'http') && !str_contains(json_encode($row1), 'sk-'));

ok('nothing to say is no clip, and no call', xeric_voice_clip($T, $db, $dir, 'ruth', 3, '*nods*', $stub, $INV) === null
    && count($CALLS) === 1 && xeric_voice_of($db, 3) === null);
ok('a caption alone is nothing to say either',
    xeric_voice_clip($T, $db, $dir, 'ruth', 3, '📷 Ruth by the urn', $stub, $INV) === null && count($CALLS) === 1);
ok('nobody is no clip, and no call',
    xeric_voice_clip($T, $db, $dir, 'no_such_person', 3, 'Hello.', $stub, $INV) === null && count($CALLS) === 1);
ok('no machine is no clip: dormant, never an error',
    xeric_voice_clip($T, $db, $dir, 'ruth', 3, 'Hello there.', null, $INV) === null && xeric_voice_of($db, 3) === null);
ok('a machine that is configured but not answering is no clip either',
    xeric_voice_clip($T, $db, $dir, 'ruth', 3, 'Hello there.', ['base' => 'http://127.0.0.1:1'], $INV) === null);

$keyed = ['key' => 'sk-one'] + $stub;
$c4 = xeric_voice_clip($T, $db, $dir, 'ruth', 1, $line, $keyed, $INV);
ok('the key is not in the fingerprint: a new key re-renders nothing', $c4 !== null && $c4['cached']
    && count($CALLS) === 1);

$c5 = xeric_voice_clip($T, $db, $dir, 'dot', 4, 'Pie\'s gone. Come back Thursday.', $stub, null);
$dId = xeric_voice_identity($T, 'dot', ['af_bella', 'am_adam', 'bf_emma', 'jf_alpha']);
ok('without an inventory in hand the clip asks the machine for one, and deals from it',
    $c5 !== null && $CALLS[1][0] === 'voices' && $CALLS[2][0] === 'speech'
    && $CALLS[2][1]['voice'] === $dId['voice'] && $dId['voice'] !== 'jf_alpha', json_encode(array_slice($CALLS, 1)));

// The owner changes Ruth's voice, then changes it back.
$Tre = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => 'bf_emma', 'speed' => 1.6]]);
$n0  = count($CALLS);
$c6  = xeric_voice_clip($Tre, $db, $dir, 'ruth', 1, $line, $stub, $INV);
ok('a new voice is a new clip, at the clamped speed',
    $c6 !== null && !$c6['cached'] && $c6['file'] !== $c1['file'] && count($CALLS) === $n0 + 1
    && $CALLS[$n0][1]['voice'] === 'bf_emma' && $CALLS[$n0][1]['speed'] === 1.3, json_encode($CALLS[$n0] ?? null));
ok('and the message now answers with the voice it was last asked for',
    xeric_voice_of($db, 1)['file'] === $c6['file']);
$c7 = xeric_voice_clip($T, $db, $dir, 'ruth', 1, $line, $stub, $INV);
ok('changed back, the old clip is still there to be had, without a render',
    $c7 !== null && $c7['cached'] && $c7['file'] === $c1['file'] && count($CALLS) === $n0 + 1);
ok('and the message answers with it again', xeric_voice_of($db, 1)['file'] === $c1['file']
    && (int)$db->query('SELECT COUNT(*) FROM voice_clips WHERE message_id = 1')->fetchColumn() === 2);

// A file somebody deleted is not a cache hit.
@unlink($dir . '/' . $c1['file']);
$n0 = count($CALLS);
$c8 = xeric_voice_clip($T, $db, $dir, 'ruth', 2, $line, $stub, $INV);
ok('a clip whose file is gone is rendered again, not handed out as a link to nothing',
    $c8 !== null && !$c8['cached'] && is_file($dir . '/' . $c8['file']) && count($CALLS) === $n0 + 1);

// Formats, and what a machine may answer.
$opus = ['format' => 'opus', 'stub' => fn(string $w, array $r) => $w === 'speech' ? ['bytes' => 'OggS-fake'] : null];
$c9 = xeric_voice_clip($T, $db, $dir, 'janelle', 5, 'On my way.', $opus, $INV);
ok('opus asked for, opus kept: the type defaults to the format when the machine says none',
    $c9 !== null && $c9['mime'] === 'audio/ogg' && str_ends_with($c9['file'], '.opus'), json_encode($c9));
$silent = ['stub' => fn(string $w, array $r) => $w === 'speech' ? ['bytes' => ''] : null];
ok('a machine that answers with no audio is a fault, and files nothing',
    throws(fn() => xeric_voice_clip($T, $db, $dir, 'janelle', 6, 'Hello.', $silent, $INV), 'no audio')
    && xeric_voice_of($db, 6) === null);
$html = ['stub' => fn(string $w, array $r) => ['bytes' => '<html>oops</html>', 'mime' => 'text/html; charset=utf-8']];
ok('and so is one that answers with a web page', throws(fn() => xeric_voice_clip($T, $db, $dir, 'janelle', 6,
    'Hello.', $html, $INV), 'not audio') && xeric_voice_of($db, 6) === null);

$METER_THROWS = true;
$c10 = xeric_voice_clip($T, $db, $dir, 'theo', 7, 'Can I have a quarter?', $stub, $INV);
$METER_THROWS = false;
ok('a meter that throws does not take the clip down with it', $c10 !== null && !$c10['cached']
    && xeric_voice_of($db, 7) !== null && is_file($dir . '/' . $c10['file']));

$blocked = $dir . '/not-a-dir';
file_put_contents($blocked, 'a file where the directory should be');
$n0 = count($CALLS);
ok('a clips directory that cannot exist is a fault found BEFORE anything is paid for',
    throws(fn() => xeric_voice_clip($T, $db, $blocked, 'theo', 8, 'Something new to say.', $stub, $INV),
           'directory') && count($CALLS) === $n0);
$cx = xeric_voice_clip($T, $db, $dir, 'harlan_counter', 9, 'That one is the good hammer.', $stub, $INV);
ok('scenery that is somebody speaks in their voice, filed under its own handle',
    $cx !== null && str_starts_with($cx['file'], 'voice-harlan_counter-')
    && end($CALLS)[1]['voice'] === xeric_voice_identity($T, 'harlan', $INV)['voice']);

// ---------------------------------------------------------------------------
echo "\n# the wire, against a voice machine on loopback\n";
// ---------------------------------------------------------------------------

$router = $dir . '/fake-machine.php';
$wire   = $dir . '/wire.log';
file_put_contents($router, <<<'FAKE'
<?php
// A voice machine in forty lines. /kokoro lists its voices Kokoro's way,
// /eleven lists them the other way, /bare cannot list them at all; all three
// speak, and two voice names make them misbehave on purpose.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$json = function (int $code, $body): bool {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
    return true;
};
if (!preg_match('#^/(kokoro|eleven|bare)(/.*)?$#', $path, $m)) return $json(404, ['detail' => 'Not Found']);
[$kind, $rest] = [$m[1], $m[2] ?? '/'];
if ($rest === '/') { echo 'a voice machine'; return true; }
if ($kind === 'kokoro' && $rest === '/v1/audio/voices') return $json(200, ['voices' => ['af_bella', 'am_adam', 'jf_alpha']]);
if ($kind === 'eleven' && $rest === '/v1/voices') {
    return $json(200, ['voices' => [['voice_id' => 'r4ch3l', 'name' => 'Rachel',
                                     'labels' => ['gender' => 'female', 'accent' => 'american']]]]);
}
if ($rest === '/v1/audio/speech' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw  = (string)file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $hdrs = function_exists('getallheaders') ? getallheaders() : [];
    file_put_contents((string)getenv('XERIC_FAKE_VOICE_LOG'),
        json_encode(['kind' => $kind, 'headers' => $hdrs, 'raw' => $raw, 'body' => $body]) . "\n", FILE_APPEND);
    $voice = (string)($body['voice'] ?? '');
    if ($voice === 'nobody')    return $json(400, ['error' => ['message' => 'Voice nobody not found']]);
    if ($voice === 'pretender') return $json(200, ['ok' => true]);
    header('Content-Type: audio/mpeg');
    echo 'ID3-fake:' . $voice . ':' . ($body['input'] ?? '');
    return true;
}
return $json(404, ['detail' => 'Not Found']);
FAKE);

$port = 0;
for ($try = 18931; $try < 18999; $try++) {
    $probe = @fsockopen('127.0.0.1', $try, $e1, $e2, 0.2);
    if ($probe === false) { $port = $try; break; }
    fclose($probe);
}
if ($port > 0) {
    $pid = (int)trim((string)shell_exec('XERIC_FAKE_VOICE_LOG=' . escapeshellarg($wire) . ' '
        . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router)
        . ' > ' . escapeshellarg($dir . '/server.log') . ' 2>&1 & echo $!'));
}
$up = false;
for ($i = 0; $port > 0 && $i < 50; $i++) {
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s !== false) { fclose($s); $up = true; break; }
    usleep(100000);
}
ok('the loopback voice machine answers', $up, (string)@file_get_contents($dir . '/server.log'));

$base  = 'http://127.0.0.1:' . $port;
$koko  = ['base' => $base . '/kokoro', 'key' => "sk-wire\r\nX-Injected: yes"];
$elev  = ['base' => $base . '/eleven/'];
$bare  = ['base' => $base . '/bare'];
$wireLog = function () use ($wire): array {
    $rows = [];
    foreach (file($wire, FILE_IGNORE_NEW_LINES) ?: [] as $l) $rows[] = json_decode($l, true);
    return $rows;
};

ok('wire: a machine that answers is up', xeric_voice_up($koko) && xeric_voice_up($bare));
$kl = xeric_voice_list($koko);
ok('wire: Kokoro\'s inventory, read off /v1/audio/voices',
    array_column($kl, 'id') === ['af_bella', 'am_adam', 'jf_alpha'], json_encode($kl));
$el = xeric_voice_list($elev);
ok('wire: a 404 on the first question, and the inventory from /v1/voices',
    count($el) === 1 && $el[0]['id'] === 'r4ch3l' && $el[0]['gender'] === 'female', json_encode($el));
ok('wire: a machine with no inventory lists nothing, and that is not an error', xeric_voice_list($bare) === []);
ok('wire: a closed port lists nothing, quickly', xeric_voice_list(['base' => 'http://127.0.0.1:1']) === []);

$METER = [];
$w1 = xeric_voice_clip($T, $db, $dir, 'ruth', 20, 'Morning, Walt. Sit.', $koko);
$log = $wireLog();
$wId = xeric_voice_identity($T, 'ruth', ['af_bella', 'am_adam', 'jf_alpha']);
ok('wire: a clip over HTTP — inventory asked, voice dealt in the world\'s language, audio kept',
    $w1 !== null && !$w1['cached'] && $w1['mime'] === 'audio/mpeg' && str_ends_with($w1['file'], '.mp3')
    && file_get_contents($dir . '/' . $w1['file']) === 'ID3-fake:' . $wId['voice'] . ':Morning, Walt. Sit.'
    && $wId['voice'] !== 'jf_alpha', json_encode([$w1, $log]));
ok('wire: the body is the speech API\'s own shape, speed a number with its fraction',
    json_decode((string)($log[0]['raw'] ?? ''), true) === ['model' => 'tts-1', 'input' => 'Morning, Walt. Sit.',
        'voice' => $wId['voice'], 'response_format' => 'mp3', 'speed' => 1.0]
    && str_contains((string)($log[0]['raw'] ?? ''), '"speed":1.0'), (string)($log[0]['raw'] ?? ''));
$h = array_change_key_case((array)($log[0]['headers'] ?? []), CASE_LOWER);
ok('wire: the key rides as a Bearer token, and a line break in it cannot become a header',
    ($h['authorization'] ?? '') === 'Bearer sk-wireX-Injected: yes' && !isset($h['x-injected']), json_encode($h));
ok('wire: the meter is told where the render happened, never with what key',
    count($METER) === 1 && $METER[0][1] === $base . '/kokoro');
ok('wire: and the second time no request is made at all',
    xeric_voice_clip($T, $db, $dir, 'ruth', 20, 'Morning, Walt. Sit.', $koko, ['af_bella', 'am_adam', 'jf_alpha'])['cached']
    && count($wireLog()) === 1);

$Tnobody = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => 'nobody']]);
ok('wire: a machine that refuses says why, and the reason reaches the error',
    throws(fn() => xeric_voice_clip($Tnobody, $db, $dir, 'ruth', 21, 'Hello.', $bare), 'HTTP 400: Voice nobody not found')
    && xeric_voice_of($db, 21) === null);
$Tpre = with_node($T, 'ruth', fn($c) => $c + ['speech' => ['voice' => 'pretender']]);
ok('wire: a 200 that is JSON is an error page wearing a success code, never an mp3',
    throws(fn() => xeric_voice_clip($Tpre, $db, $dir, 'ruth', 22, 'Hello.', $bare), 'not audio')
    && xeric_voice_of($db, 22) === null);
$w2   = xeric_voice_clip($T, $db, $dir, 'dot', 23, 'Pie?', $bare);
$last = $wireLog();
$last = end($last);
ok('wire: a machine that cannot list its voices speaks in the fallback six',
    $w2 !== null && in_array($last['body']['voice'] ?? '', XERIC_VOICE_FALLBACK, true), json_encode([$w2, $last]));

// ---------------------------------------------------------------------------

foreach ($envKeep as $k => $v) { if ($v !== false) putenv($k . '=' . $v); }
$db = null;   // the shutdown hook stops the machine and removes the files

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
