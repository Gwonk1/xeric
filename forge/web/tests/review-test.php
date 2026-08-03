<?php
/**
 * Xeric — review workbench data-side tests. `php forge/web/tests/review-test.php`,
 * exit 0 on pass.
 *
 * NOT YET IN THE QUICKSTART LOOP. The canonical suite run is engine/tests/*,
 * forge/tests/forge-test.php and forge/web/tests/demo-test.php; this file runs
 * beside them, by name, until somebody decides the loop should know it.
 *
 * NO NETWORK, NO MODEL. The one "model" below is the stub seam every forge pass
 * already carries (xeric_llm_json honours endpoint['stub']), and everything else
 * is a pure function being asked pure questions.
 *
 * What is being defended here, in the order it would hurt:
 *
 *   1. AN EDIT TO A LIVE WORLD SAYS WHAT IT JUST DID. The classification that
 *      decides the sentence comes off the editable table's own third column —
 *      one list — and it must call a voice deep and a description live, or the
 *      page is warning about typos and shrugging at soul surgery.
 *   2. THE PRONOUN BACKFILL NEVER GUESSES AND NEVER OVERWRITES. "unclear" stays
 *      grey, junk stays grey, a set somebody already chose is untouchable, and
 *      only the vocabulary the engine actually reads is ever stored.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/xeric-review-test-' . getmypid();
@mkdir($tmp . '/worlds', 0775, true);
putenv('XERIC_DATA_DIR=' . $tmp);
putenv('XERIC_WORLDS_DIR=' . $tmp . '/worlds');
putenv('XERIC_LOCAL_BASE=http://127.0.0.1:1');      // never probed, never called

require_once __DIR__ . '/../review-lib.php';

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
    foreach (glob($d . '/*') ?: [] as $f) is_dir($f) ? rmtree($f) : @unlink($f);
    @rmdir($d);
}

echo "# what an edit costs a live world\n";

// ---------------------------------------------------------------------------
// The weight comes off the editable table itself — asking for it must never
// disagree with what the table lets you type.
// ---------------------------------------------------------------------------

foreach (xeric_review_editable() as $re => $spec) {
    if (!in_array((string)($spec[2] ?? 'live'), ['live', 'deep'], true)) {
        ok("the table row $re carries a weight this page knows", false, (string)($spec[2] ?? ''));
    }
}
ok('every editable row weighs either live or deep', true);

foreach (['cast.characters.0.voice', 'cast.characters.2.psyche.sore_spot',
          'cast.characters.1.psyche.praise_that_lands', 'cast.characters.1.drives.pull',
          'setting.canon_rules.0', 'knowledge_walls.1.explain', 'knowledge_walls.0.shown_as',
          'cast.special_roles.0.must_not_know'] as $p) {
    ok("$p is a deep edit", xeric_review_edit_weight($p) === 'deep', (string)xeric_review_edit_weight($p));
}
foreach (['meta.name', 'meta.description', 'cast.characters.0.one_line',
          'cast.characters.0.appearance', 'cast.characters.0.solace', 'places.3.description',
          'setting.texture.1', 'user.motivation', 'cast.characters.0.pronouns',
          'seed.events.0.prose'] as $p) {
    ok("$p is a live edit", xeric_review_edit_weight($p) === 'live', (string)xeric_review_edit_weight($p));
}
foreach (['places.0.key', 'cast.characters.0.handle', 'meta.rating', 'forge.armed'] as $p) {
    ok("$p weighs nothing because it cannot be edited at all", xeric_review_edit_weight($p) === null);
}

// ---------------------------------------------------------------------------
// The sentence itself. A launched world speaks; an anvil world stays quiet.
// ---------------------------------------------------------------------------

$T = ['cast' => ['characters' => [
    ['handle' => 'maren',   'display_name' => 'Maren Holt',   'pronouns' => 'she/her'],
    ['handle' => 'wendell', 'display_name' => 'Wendell Pike',
     'one_line' => 'He fixes what nobody asked him to and mentions it later.'],
    ['handle' => 'ash',     'display_name' => 'Ash Berg',
     'one_line' => 'Keeps the till and the peace, in that order.'],
]]];
$live  = ['launched' => true,  'template' => $T];
$anvil = ['launched' => false, 'template' => $T];

ok('a world still on the anvil gets no sentence at all',
    xeric_review_live_note($anvil, 'cast.characters.0.voice') === null);
ok('a prose edit on a live world is simply live',
    xeric_review_live_note($live, 'meta.description') === 'Live from the next thing anybody says.');
$n = (string)xeric_review_live_note($live, 'cast.characters.0.voice');
ok('a deep edit names the person it changes', str_contains($n, 'Maren Holt'), $n);
ok('and reads her by her own pronouns', str_contains($n, 're-reads her'), $n);
ok('and says the honest cost out loud', str_contains($n, 'will take longer'), $n);
$n = (string)xeric_review_live_note($live, 'cast.characters.1.psyche.sore_spot');
ok('a person with no pronouns field is read from his prose, like the faces do',
    str_contains($n, 'Wendell Pike') && str_contains($n, 're-reads him'), $n);
$n = (string)xeric_review_live_note($live, 'cast.characters.2.drives.pull');
ok('and someone the prose does not settle is they, never a guess',
    str_contains($n, 'Ash Berg') && str_contains($n, 're-reads them'), $n);
$n = (string)xeric_review_live_note($live, 'knowledge_walls.0.explain');
ok('a wall edit is about the world, and every speaker pays',
    str_contains($n, 'every speaker'), $n);
$n = (string)xeric_review_live_note($live, 'setting.canon_rules.0');
ok('so is a canon rule', str_contains($n, 'every speaker'), $n);
ok('the seed of a live world gets no sentence, its past is in the database',
    xeric_review_live_note($live, 'seed.events.0.prose') === null);
ok('an uneditable path gets none either',
    xeric_review_live_note($live, 'places.0.key') === null);

// ---------------------------------------------------------------------------
// And it rides the real save. A world on disk, edited before and after launch:
// quiet on the anvil, one honest sentence once it is live.
// ---------------------------------------------------------------------------

// FROM THE TRACKED FIXTURE, not off the developer's own shelf. This used to
// glob `<repo>/worlds/*` for whatever happened to be there: different on every
// machine, absent on a fresh clone, and it assumed xerics live inside the
// checkout, which they do not (see xeric_web_worlds_default).
$srcWorld = $tmp . '/fixture-world';
@mkdir($srcWorld, 0775, true);
$fixT = xeric_world_load(dirname(__DIR__, 3) . '/engine/fixtures/milldale.json');
$jopt = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents($srcWorld . '/world-template.json', json_encode($fixT, $jopt));
file_put_contents($srcWorld . '/seed.json', json_encode(xeric_forge_default_seed($fixT), $jopt));
ok('a world template to test against was built from the tracked fixture',
    is_file($srcWorld . '/world-template.json') && is_file($srcWorld . '/seed.json'));

$R = bin2hex(random_bytes(16));
xeric_session_use($R);
$dir = xeric_web_worlds_dir() . '/note-town';
@mkdir($dir, 0775, true);
@copy($srcWorld . '/world-template.json', $dir . '/world-template.json');
@copy($srcWorld . '/seed.json', $dir . '/seed.json');
xeric_session_claim('note-town', $R);
xeric_review_save('note-town', xeric_review_mark_pending(xeric_world_load($dir . '/world-template.json')));

$w = xeric_review_open('note-town', $R);
$r = xeric_review_apply_edit($w, 'cast.characters.0.voice', 'Answers slowly, and only once.');
ok('an edit before launch is accepted with no live sentence',
    ($r['ok'] ?? false) === true && !isset($r['note']), json_encode($r));

$lt = xeric_review_open('note-town', $R)['template'];
unset($lt['forge']['review_pending']);
xeric_review_save('note-town', $lt);

$w = xeric_review_open('note-town', $R);
$r = xeric_review_apply_edit($w, 'cast.characters.0.one_line', 'Keeps the books and her own counsel.');
ok('a prose edit after launch says it is live',
    ($r['ok'] ?? false) === true && ($r['note'] ?? '') === 'Live from the next thing anybody says.',
    json_encode($r));
$r = xeric_review_apply_edit(xeric_review_open('note-town', $R),
    'cast.characters.0.voice', 'Answers slowly, twice if it matters.');
ok('a deep edit after launch says who changed and what it costs',
    ($r['ok'] ?? false) === true && str_contains((string)($r['note'] ?? ''), 'This changes who ')
    && str_contains((string)($r['note'] ?? ''), 'will take longer'), json_encode($r));
$r = xeric_review_apply_edit(xeric_review_open('note-town', $R), 'cast.characters.0.week.0.from', 'noonish');
ok('a refused edit carries the refusal and never the live sentence',
    ($r['ok'] ?? true) === false && !isset($r['note']), json_encode($r));

echo "\n# the pronoun backfill\n";

// ---------------------------------------------------------------------------
// Who is missing, what counts as an answer, and what the fold may touch.
// ---------------------------------------------------------------------------

$T2 = ['cast' => ['characters' => [
    ['handle' => 'maren',   'display_name' => 'Maren Holt', 'pronouns' => 'she/her'],
    ['handle' => 'wendell', 'display_name' => 'Wendell Pike',
     'one_line' => 'He fixes what nobody asked him to.', 'voice' => 'Long pauses.'],
    ['handle' => 'ash',     'display_name' => 'Ash Berg', 'pronouns' => ''],
    ['handle' => 'quill',   'display_name' => 'Quill'],
]]];

$miss = xeric_review_pronounless($T2);
ok('a set pronoun is not missing', !isset($miss['maren']), json_encode($miss));
ok('an empty string is missing, it was never a choice', isset($miss['ash']));
ok('and so is no field at all', isset($miss['wendell']) && isset($miss['quill']));
ok('the missing are keyed by handle at their cast position',
    $miss === ['wendell' => 1, 'ash' => 2, 'quill' => 3], json_encode($miss));
ok('a complete cast has nobody missing',
    xeric_review_pronounless(['cast' => ['characters' => [
        ['handle' => 'a', 'pronouns' => 'they/them']]]]) === []);

foreach (['she/her', 'He/Him', 'they', 'they/them', 'she/they', 'ze/hir', 'ze/zir',
          'xe/xem', 'it/its', ' She / Her '] as $good) {
    ok("'$good' is a set the engine reads", xeric_review_pronoun_ok($good));
}
foreach (['', 'unclear', 'unknown', 'q/qq', 'them/they', 'attack helicopter', '12/12', 'shethem'] as $bad) {
    ok("'$bad' is not", !xeric_review_pronoun_ok($bad));
}

$fold = xeric_review_pronoun_merge($T2, [
    'wendell' => 'He/Him',
    'ash'     => 'unclear',
    'quill'   => 'sparkle/sparkles',
    'maren'   => 'they/them',            // the model was not asked; it must not matter
]);
ok('a clear answer is written, normalized to the cog\'s own spelling',
    (string)($fold['template']['cast']['characters'][1]['pronouns'] ?? '') === 'he/him'
    && ($fold['filled']['wendell'] ?? '') === 'he/him', json_encode($fold['filled']));
ok('an "unclear" is respected: nothing written, the grey stays grey',
    !isset($fold['template']['cast']['characters'][2]['pronouns'])
    || (string)$fold['template']['cast']['characters'][2]['pronouns'] === '');
ok('and it is reported as unclear, by name', ($fold['left']['ash'] ?? '') === 'unclear');
ok('a set the engine cannot read stays grey too',
    ($fold['left']['quill'] ?? '') === 'not a set this app reads'
    && !isset($fold['template']['cast']['characters'][3]['pronouns']));
ok('a pronoun somebody already chose is untouchable, whatever the model said',
    (string)$fold['template']['cast']['characters'][0]['pronouns'] === 'she/her');
ok('nothing else about the cast moved',
    array_diff_key($fold['template']['cast']['characters'][1], ['pronouns' => 1])
        === array_diff_key($T2['cast']['characters'][1], []), 'the merge should only add pronouns');

// ---------------------------------------------------------------------------
// The ask: one call for the whole cast, through the same stub seam every forge
// pass carries. The prompt must carry the people and the permission to say
// "unclear"; the answer comes back raw, because the merge is the gate.
// ---------------------------------------------------------------------------

$calls = 0;
$seen  = '';
$stub = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use (&$calls, &$seen): array {
    $calls++;
    $seen = implode("\n", array_map(fn($m) => (string)$m['content'], $msgs));
    if ($tag !== 'pronouns') throw new RuntimeException("the stub was asked for '$tag'");
    return ['wendell' => 'he/him', 'ash' => 'unclear', 'quill' => ['pronouns' => 'they/them']];
}];

$answers = xeric_review_pronoun_ask($T2, $miss, $stub);
ok('the whole cast costs exactly one model call', $calls === 1);
ok('the prompt carries each missing person by handle and name',
    str_contains($seen, 'wendell') && str_contains($seen, 'Wendell Pike')
    && str_contains($seen, 'quill') && str_contains($seen, 'Quill'), mb_substr($seen, 0, 200));
ok('and never the person who already answered', !str_contains($seen, 'Maren'));
ok('and says out loud that a name is not evidence and unclear is an answer',
    str_contains($seen, 'A name is not evidence') && str_contains($seen, '"unclear"'), $seen);
ok('answers come back raw, one per missing handle, wrappers unwrapped',
    $answers === ['wendell' => 'he/him', 'ash' => 'unclear', 'quill' => 'they/them'],
    json_encode($answers));

$wrapped = ['base' => 'stub://', 'stub' => fn(string $tag, array $msgs, array $opts): array
    => ['answers' => ['wendell' => 'she/her']]];
ok('a model that wraps the map in "answers" is still understood',
    xeric_review_pronoun_ask($T2, $miss, $wrapped) === ['wendell' => 'she/her']);

ok('nobody missing means nobody asked', xeric_review_pronoun_ask($T2, [], $stub) === []
    && $calls === 1);

// ---------------------------------------------------------------------------
// And the offer only exists where the hole does.
// ---------------------------------------------------------------------------

$w2 = ['slug' => 'note-town', 'template' => $T2, 'seed' => ['events' => [], 'memories' => []],
       'mine' => true, 'launched' => true, 'lived' => false];
$html = xeric_review_section_html('cast', $w2);
ok('an incomplete cast is offered the one button',
    str_contains($html, 'pronounfill') && str_contains($html, 'fill in pronouns — one model call'));
$w2['template']['cast']['characters'] = [
    ['handle' => 'maren', 'display_name' => 'Maren Holt', 'pronouns' => 'she/her'],
];
ok('a complete cast never sees it',
    !str_contains(xeric_review_section_html('cast', $w2), 'pronounfill'));
$w2['template'] = $T2;
$w2['mine'] = false;
ok('and neither does somebody else\'s browser',
    !str_contains(xeric_review_section_html('cast', $w2), 'pronounfill'));

// ---------------------------------------------------------------------------
// Re-aiming walls after a cast reroll — by key, never by position.
//
// The positional mapping only ever ran when its own justification was false
// (a model-rewritten orbit list has no order contract), and it landed privacy
// walls on semantically random circles. The law now: a wall whose orbit
// survives is untouched; a wall whose orbit is gone covers EVERY new orbit —
// original key kept (special_roles reference walls by key), clones for the
// rest — because over-covering is recoverable and a leaked interior is not.
// ---------------------------------------------------------------------------

echo "\n# re-aiming walls\n";

$reT = [
    'cast' => ['orbits' => [['key' => 'family'], ['key' => 'congregation']]],
    'knowledge_walls' => [
        ['key' => 'privacy_town',  'audience' => ['orbit' => 'town'],
         'hidden' => ['cast_dossiers', 'drives.*']],
        ['key' => 'kept_wall',     'audience' => ['orbit' => 'family'],
         'hidden' => ['secrets.someone']],
        ['key' => 'by_handle',     'audience' => ['handle' => 'ruth'],
         'hidden' => ['psyche.someone']],
    ],
];
$reSaid = [];
$reOut  = xeric_review_reaim_walls($reT, ['inner', 'work', 'town'], function ($s) use (&$reSaid) { $reSaid[] = $s; });

$reWalls = $reOut['knowledge_walls'];
$covered = [];
foreach ($reWalls as $wl) {
    if (str_starts_with((string)($wl['key'] ?? ''), 'privacy_town')) {
        $covered[(string)($wl['audience']['orbit'] ?? '')] = $wl['hidden'] ?? null;
    }
}
ok('reaim: a wall whose orbit is gone covers EVERY new orbit, hidden list intact',
    array_keys($covered) === ['family', 'congregation'] || array_keys($covered) === ['congregation', 'family'],
    json_encode(array_keys($covered)));
ok('reaim: every copy hides exactly what the original hid',
    array_values(array_unique(array_map('json_encode', $covered))) === [json_encode(['cast_dossiers', 'drives.*'])]);
ok('reaim: the original row keeps its key — the name a special role would reference',
    (bool)array_filter($reWalls, fn($wl) => ($wl['key'] ?? '') === 'privacy_town'));
ok('reaim: a wall whose orbit SURVIVED is untouched',
    (bool)array_filter($reWalls, fn($wl) => ($wl['key'] ?? '') === 'kept_wall'
        && ($wl['audience']['orbit'] ?? '') === 'family'));
ok('reaim: a handle-aimed wall is no business of the orbit pass',
    (bool)array_filter($reWalls, fn($wl) => ($wl['key'] ?? '') === 'by_handle'
        && ($wl['audience']['handle'] ?? '') === 'ruth' && !isset($wl['audience']['orbit'])));
ok('reaim: no wall is left naming an orbit that does not exist',
    array_filter($reWalls, fn($wl) => isset($wl['audience']['orbit'])
        && !in_array($wl['audience']['orbit'], ['family', 'congregation'], true)) === []);
ok('reaim: the move is reported BY NAME, with the old aim and the advice',
    count($reSaid) === 1 && str_contains($reSaid[0], "'privacy_town'")
    && str_contains($reSaid[0], "'town'") && str_contains($reSaid[0], 're-aim'), json_encode($reSaid));

// Idempotent: running it again finds every orbit in place and changes nothing.
$reAgain = xeric_review_reaim_walls($reOut, ['family', 'congregation']);
ok('reaim: a second pass is a no-op — nothing re-clones',
    count($reAgain['knowledge_walls']) === count($reOut['knowledge_walls']));

// ---------------------------------------------------------------------------
// THE DICE, AND WHOSE FIELD IT IS ROLLING.
//
// xeric_review_roll() told the model the WORLD's rating. Every other surface
// that writes a character derives the subject's own ceiling first, so rolling a
// line on the twelve-year-old announced the world's adult rating and then asked
// for something in that register. The clamp is xeric_viewer_rating() through
// xeric_viewer(), the same pair the prompt builder uses, so there is one answer
// to "what may this person be written at" and not a second one here.
// ---------------------------------------------------------------------------

$TR = ['meta' => ['name' => 'Milldale', 'rating' => 'mature'],
       'setting' => ['locale' => 'a river town', 'era' => '1973'],
       'cast' => ['characters' => [
           ['handle' => 'ruth', 'display_name' => 'Ruth Amberg', 'age' => 52,
            'one_line' => 'She runs the diner.'],
           ['handle' => 'theo', 'display_name' => 'Theo Vance', 'age' => 12,
            'one_line' => 'He is twelve.'],
           ['handle' => 'nameless', 'display_name' => 'Someone'],   // no age at all
       ]],
       'places' => ['diner' => ['name' => 'The Diner']]];

$rollSeen = '';
$rollStub = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use (&$rollSeen): array {
    $rollSeen = implode("\n", array_map(fn($m) => (string)$m['content'], $msgs));
    return ['value' => 'something'];
}];
$rated = static function (string $path) use ($TR, $rollStub, &$rollSeen): string {
    xeric_review_roll($TR, $path, 'a line', $rollStub);
    return preg_match('/Content rating: ([^.\s]+)/', $rollSeen, $m) === 1 ? $m[1] : '';
};

$floor = xeric_ratings()[0];
ok('dice: an adult is written at the world\'s rating', $rated('cast.characters.0.voice') === 'mature');
ok('dice: a child is written at the floor, whatever the world is rated',
    $rated('cast.characters.1.voice') === $floor, $rollSeen);
ok('dice: and so is somebody whose age the template does not yet say',
    $rated('cast.characters.2.voice') === $floor);
ok('dice: a nested field on a child is still the child\'s field',
    $rated('cast.characters.1.psyche.sore_spot') === $floor);
ok('dice: and a list index under one too',
    $rated('cast.characters.1.tells.2') === $floor);
ok('dice: a place is not a person, and keeps the world\'s rating',
    $rated('places.diner.name') === 'mature');
ok('dice: a cast path that names nobody falls closed rather than open',
    $rated('cast.characters.9.voice') === $floor);

rmtree($tmp);

echo "\n" . ($FAILED === 0 ? "all review tests passed\n" : "$FAILED review test(s) FAILED\n");
exit($FAILED === 0 ? 0 : 1);
