<?php
/**
 * photo-test.php — the composed frame. `php engine/tests/photo-test.php`, exit 0.
 *
 * NO NETWORK, NO IMAGE MACHINE: everything here is about the PROMPT — the
 * owner's three tiers in the owner's order (identity, location, model free
 * will), physics as the one limitation, and the rating cap still landing
 * last where nobody can negotiate with it. Plus the code half of "show me":
 * the ask-detector that flips the camera offer from rare to expected.
 */

declare(strict_types=1);

require_once __DIR__ . '/../photo.php';
require_once __DIR__ . '/../prompt.php';

$FAILED = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) { echo "ok   - $name\n"; return; }
    $FAILED++;
    echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
}

$T = xeric_world_load(__DIR__ . '/../fixtures/milldale.json');

// ---------------------------------------------------------------------------
// The three tiers, in the owner's order
// ---------------------------------------------------------------------------

$p = xeric_photo_prompt($T, 'message', ['handle' => 'ruth', 'place' => 'bluebird',
                                        'ask' => 'mid-laugh by the coffee urn']);
$s = (string)$p['prompt'];

$posWho   = mb_strpos($s, xeric_world_name($T, 'ruth'));
$posWhere = mb_strpos($s, 'Bluebird');
$posFree  = mb_strpos($s, 'the photograph\'s own choice');
ok('tiers: identity first, location second, freedom third',
    $posWho !== false && $posWhere !== false && $posFree !== false
    && $posWho < $posWhere && $posWhere < $posFree,
    "who=$posWho where=$posWhere free=$posFree");
ok('tiers: physics is the one law, said as the anti-artifact language it is',
    str_contains($s, 'the one law is physics') && str_contains($s, 'nothing floats'));
ok('tiers: a frame with a person in it is told about bodies',
    str_contains($s, 'right number of parts'));
ok('tiers: the rating cap still lands after the freedom clause — free will ends at the floor',
    mb_strrpos($s, 'photograph') > $posFree
    && preg_match('/(wholesome|nothing explicit|adult rating)[^.]*\.$/u', $s) === 1, $s);

$pl = xeric_photo_prompt($T, 'place', ['place' => 'bluebird']);
ok('tiers: a place shot keeps the freedom clause and drops the bodies',
    str_contains((string)$pl['prompt'], 'the photograph\'s own choice')
    && !str_contains((string)$pl['prompt'], 'right number of parts'));

// The minor cap survives everything the tiers add: theo is 12, and the floor
// language is appended in code after every clause including the freedom one.
$pm = xeric_photo_prompt($T, 'portrait', ['handle' => 'theo', 'ask' => 'a portrait']);
ok('tiers: a minor in frame still forces the floor, whatever the tiers said',
    (string)$pm['rating'] === 'sfw'
    && str_contains((string)$pm['prompt'], 'suitable for a family album'));

// ---------------------------------------------------------------------------
// "Show me" — the ask-detector behind the camera offer
// ---------------------------------------------------------------------------

foreach ([
    'show me',                       'ok, show me the workshop then',
    'send a pic',                    'can you send me a picture of the urn?',
    'can I see?',                    'lemme see it',
    'what does the mill look like',  'take a photo of that',
    'got a pic of the new sign?',    'send it over',
] as $yes) {
    ok("asked: \"$yes\"", xeric_prompt_wants_photo($yes));
}
foreach ([
    'that picture of us from years ago was in the drawer',
    'I saw him yesterday',
    'the show merits a look',
    'it looks like rain',
    '',
] as $no) {
    ok("not asked: \"" . ($no === '' ? '(empty)' : $no) . "\"", !xeric_prompt_wants_photo($no));
}

echo $FAILED === 0 ? "\nall photo tests passed\n" : "\n$FAILED FAILED\n";
exit($FAILED === 0 ? 0 : 1);
