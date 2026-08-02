<?php
/**
 * Xeric — narrator tests.  `php engine/tests/narrator-test.php`, exit 0 on pass.
 *
 * The meat here is the same as render-test's: negative assertions. The
 * narrator's failure mode is not a bad sentence, it is a spoiler with database
 * access — so the ORACLE list below names the exact overlay-only strings that
 * must never reach the assembled prompt, and the list is SELF-CHECKED against
 * the world template first: if a fixture edit ever copies one of those strings
 * into milldale.json, the needle goes blunt and this file says so instead of
 * passing quietly.
 *
 * The other half is the positive claim that makes the narrator the narrator:
 * both sides of every wall in one prompt. Janelle's private secret and Ruth's
 * sit side by side here, which no character's prompt is ever allowed to show.
 *
 * And the contract that costs nothing to test and everything to lose: asking
 * is a read. Row counts across every table are identical before and after a
 * build-and-ask.
 */

declare(strict_types=1);

require_once __DIR__ . '/../narrator.php';

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

/** Which of $needles appear in $hay. Case-insensitive: a leak is a leak. */
function leaks(string $hay, array $needles): array
{
    $found = [];
    foreach ($needles as $n) {
        if (stripos($hay, $n) !== false) $found[] = $n;
    }
    return $found;
}

// ---------------------------------------------------------------------------
// The world, the overlay, and a lived stretch of history
// ---------------------------------------------------------------------------

$tplPath = __DIR__ . '/../fixtures/milldale.json';
$T = xeric_world_load($tplPath);
$S = xeric_story_read(__DIR__ . '/../fixtures/milldale-story.json');
xeric_story_validate($S, $T, 'milldale-story.json');

$dbPath = sys_get_temp_dir() . '/xeric-narrator-test-' . getmypid() . '.db';
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) @unlink($f);
$db = xeric_state_open($dbPath);
xeric_state_seed($db, $T);

$tz    = new DateTimeZone((string)$T['user']['timezone']);
$epoch = (new DateTimeImmutable('2026-06-02 20:00', $tz))->getTimestamp();
$now   = xeric_world_now($T, $epoch);
ok('fixture: the chosen epoch is a Tuesday evening on the world clock',
    $now['dow'] === 2 && $now['phase'] === 'evening', $now['dow'] . ' ' . $now['phase']);

// A stretch of history: one spine hour with a kept trail, one plain hour, a
// memory on each side of the big wall, a chat thread, a death, a spilled beat.
$evSpine = xeric_event_add($db, 'A quiet hour at the mill fence', $epoch - 3 * 3600, 'the_mill',
    ['harlan', 'ruth'], 'Two people stood at the chain and did not say much.', null, true);
$evPlain = xeric_event_add($db, 'Coffee ran long at the Bluebird', $epoch - 26 * 3600, 'bluebird',
    ['dot', 'theo'], 'The specials board did not change and nobody minded.', null, false);

xeric_world_state_set($db, 'why:event:' . $evSpine, json_encode([
    'kind'     => 'ordinary',
    'on_spine' => true,
    'why'      => 'NARRATOR-TRAIL-ALPHA both were on shift at the mill fence',
    'trail'    => ['excluded' => [[
        'handle' => 'janelle', 'name' => 'Janelle',
        'why'    => 'this one touches what they must not know',
    ]]],
], JSON_UNESCAPED_SLASHES));

xeric_memory_add($db, 'janelle', 'MEM-JANELLE the drive down felt shorter this time', 'auto', [], $epoch - 5 * 3600);
xeric_memory_add($db, 'ruth',    'MEM-RUTH the hymn numbers changed twice this week', 'auto', [], $epoch - 4 * 3600);

$conv = xeric_conversation_create($db, 'ruth', 'chat');
xeric_message_append($db, $conv, 'user', null, 'you around?', $epoch - 2 * 86400);
xeric_message_append($db, $conv, 'character', 'ruth', 'always, until nine', $epoch - 2 * 86400 + 60);

$killed = xeric_death_kill($T, $db, 'dot', $epoch - 86400, 'the ice on the church steps', 'harlan', false);
ok('setup: the death row landed', $killed['ok'] === true, (string)$killed['error']);

$spill = xeric_story_spill($S, $db, 'the_chair', $epoch - 3600);
ok('setup: the beat spilled and left the teller a memory', $spill['spilled'] && $spill['memory'] !== null);

// ---------------------------------------------------------------------------
// The oracle shelf — overlay-only strings, self-checked before use
// ---------------------------------------------------------------------------

// Each of these lives ONLY in the overlay (or, for the last one, only in the
// renderer's mystery.room prose). None may reach the narrator's prompt.
$ORACLE = [
    'a flight of iron stairs',            // truth
    'often enough to bring a chair',      // red_herrings.it_was_only_kids.actually
    'who he was sitting with',            // red_herrings.the_reds_cap_at_the_mill.actually
    'has not told their own family yet',  // an unspilled beat's piece (the_hospital_run)
    'counted the drawer twice',           // an unspilled beat's spilled_as (the_till_key)
    'the_till_key',                       // a beat key — the outline's own vocabulary
    'crescendo',                          // a snake stage — the shape of what is coming
    'one thing that is actually true',    // mystery.room, which the bible would print for viewer=null
];
$worldRaw = (string)file_get_contents($tplPath);
ok('needles: every oracle needle is absent from the world template itself',
    leaks($worldRaw, $ORACLE) === [], implode(' | ', leaks($worldRaw, $ORACLE)));

// ---------------------------------------------------------------------------
// Read-only: snapshot everything countable before the narrator reads
// ---------------------------------------------------------------------------

function narrator_snapshot(PDO $db): array
{
    $out = [];
    foreach (['conversations', 'messages', 'memories', 'arcs', 'events',
              'world_state', 'signals', 'deaths', 'reminders'] as $table) {
        $out[$table] = (int)$db->query("SELECT COUNT(*) c FROM $table")->fetchAll()[0]['c'];
    }
    return $out;
}
$before = narrator_snapshot($db);

// ---------------------------------------------------------------------------
// (a) the assembled prompt — shape, discretion, both sides of the walls
// ---------------------------------------------------------------------------

$Q     = 'Why has Ruth gone quiet?';
$built = xeric_narrator_prompt($T, $db, $now, $Q, ['stories' => [$S]]);
$msgs  = $built['messages'];
$sys   = (string)$msgs[0]['content'];
$usr   = (string)$msgs[1]['content'];
$all   = $sys . "\n" . $usr;

ok('prompt: two messages, system then user',
    count($msgs) === 2 && $msgs[0]['role'] === 'system' && $msgs[1]['role'] === 'user');

ok('discretion: the rules are stated, in the doc\'s own terms',
    str_contains($sys, 'Full knowledge is not full disclosure')
    && str_contains($sys, 'That is not mine to say')
    && str_contains($sys, '"Something is moving" is the most you say')
    && str_contains($sys, 'you never say what it keeps'));
ok('discretion: straight about the machine is stated beside it',
    str_contains($sys, 'WHAT YOU ANSWER FREELY — THE MACHINE')
    && str_contains($sys, 'Quiet hours, shifts, who was free'));

ok('canon: both sides of the walls sit in one bible — every private secret, side by side',
    str_contains($sys, 'five-card draw')       // the pastor's walled Thursday habit
    && str_contains($sys, 'green spiral notebook')  // Ruth's private (gossip_grade: false) secret
    && str_contains($sys, 'glovebox'));             // Janelle's — behind the wall aimed AT her
ok('canon: both sides of the walls in the lived record too — two heads\' private memories together',
    str_contains($usr, 'MEM-JANELLE') && str_contains($usr, 'MEM-RUTH'));

ok('oracle: no overlay-only string reaches the prompt',
    leaks($all, $ORACLE) === [], implode(' | ', leaks($all, $ORACLE)));
ok('mystery: the rumor is canon and the room is not — the town\'s half stays, the author\'s goes',
    str_contains($sys, 'Nobody has ever found anything in there')
    && !str_contains($sys, 'actually true'));

ok('shelf: the story is named at the player\'s own altitude — title, logline, still running',
    str_contains($sys, 'What Happened on the Fourth-Floor Landing')
    && str_contains($sys, 'went down the stairwell instead')
    && str_contains($sys, 'It is still running.'));
ok('shelf: the narrator is told the truth about its own ignorance of the outline',
    str_contains($sys, 'the outline') && str_contains($sys, 'was never yours to read'));

// ---------------------------------------------------------------------------
// (b) the lived record — the machine half, answered straight
// ---------------------------------------------------------------------------

ok('record: the clock heads the lived block', str_contains($usr, 'Tuesday evening, 20:00'));
ok('record: quiet hours are a plain fact here', str_contains($usr, '21:30 to 06:00'));

$rightNow = '';
foreach (explode("\n", $usr) as $line) {
    if (str_starts_with($line, 'Right now:')) { $rightNow = $line; break; }
}
$living = [];
foreach ($T['cast']['characters'] as $c) {
    if ((string)$c['handle'] !== 'dot') $living[] = (string)$c['display_name'];
}
$missing = array_values(array_filter($living, fn($n) => !str_contains($rightNow, $n)));
ok('record: presence names every living cast member', $rightNow !== '' && $missing === [], implode(' | ', $missing));
ok('record: the dead are in no room', !str_contains($rightNow, xeric_world_name($T, 'dot')));

$gone = '';
foreach (explode("\n", $usr) as $line) {
    if (str_starts_with($line, 'Gone from this world:')) { $gone = $line; break; }
}
ok('record: the death is public — name, date, what the town would say',
    str_contains($gone, xeric_world_name($T, 'dot')) && str_contains($gone, 'ice on the church steps'));
// by_handle is tier 1: who killed whom is a story's to hand out one beat at a
// time, so the row's `by` never reaches the prompt — not even fenced.
ok('record: who did it stays in the store', !str_contains($gone, 'Harlan'), $gone);

ok('record: the spine hour is in the record, and marked in payload-free words',
    str_contains($usr, 'A quiet hour at the mill fence')
    && str_contains($usr, 'this hour touched what the world is keeping quiet'));
ok('record: the decision trail rides its event',
    str_contains($usr, 'NARRATOR-TRAIL-ALPHA')
    && str_contains($usr, 'kept out: Janelle — this one touches what they must not know'));
ok('record: the plain hour is there too', str_contains($usr, 'Coffee ran long at the Bluebird'));

ok('record: a spilled beat reaches the narrator the honest way — through the teller\'s memory',
    str_contains($usr, 'He told Walt about the folding chair in the stairwell'));

ok('record: the ledgers are counted out loud',
    str_contains($usr, 'Trust, as counted:') && str_contains($usr, 'needle sits at'));
ok('record: last-heard dates answer the "why has she not appeared" class of question',
    str_contains($usr, 'LAST HEARD FROM')
    && str_contains($usr, xeric_world_name($T, 'ruth') . ': Sun 31 May'));

ok('question: it is the last thing the model reads',
    str_contains($usr, 'asks you: ' . $Q)
    && str_ends_with(trim($usr), 'your discretion where it does not.'));

// ---------------------------------------------------------------------------
// (c) sources — the citations are the assembly manifest, not the model's word
// ---------------------------------------------------------------------------

$src = $built['sources'];
ok('sources: both events are cited and only the kept trail is',
    $src['events'] === [$evSpine, $evPlain] && $src['trails'] === [$evSpine]);
ok('sources: every head whose memories were read is named',
    isset($src['memories']['janelle'], $src['memories']['ruth'], $src['memories']['theo']));
ok('sources: the thread, the story and the death are on the manifest',
    isset($src['threads']['ruth'])
    && ($src['stories']['mill_stairwell'] ?? '') === 'open'
    && $src['deaths'] === ['dot']);

// ---------------------------------------------------------------------------
// (d) the rating is the world's, and an override is an override
// ---------------------------------------------------------------------------

$GATED = "hasn't cleared its own rent since March";
ok('rating: the narrator reads at the world\'s rating — the mature node is absent in this sfw world',
    !str_contains($sys, $GATED));
ok('rating: an explicit effective_rating opens it',
    str_contains(xeric_narrator_system($T, $db, [$S], 'mature'), $GATED));

// ---------------------------------------------------------------------------
// (e) asking is a read, and the same ask twice is the same bytes
// ---------------------------------------------------------------------------

$tagSeen = '';
$stub = ['stub' => function (string $tag, array $messages, array $opts) use (&$tagSeen) {
    $tagSeen = $tag;
    return ['text' => 'She has been at the church since morning.',
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7]];
}];
$ans = xeric_narrator_ask($T, $db, $Q, $now, $stub, ['stories' => [$S]]);
ok('ask: the seam is tagged narrator and hands back the stub\'s text',
    $tagSeen === 'narrator' && $ans['text'] === 'She has been at the church since morning.');
ok('ask: usage carries the stub\'s counts and a wall-clock ms',
    ($ans['usage']['prompt_tokens'] ?? 0) === 11 && array_key_exists('ms', $ans['usage']));
ok('ask: the answer carries the same sources manifest the prompt was built from',
    $ans['sources']['events'] === [$evSpine, $evPlain]);

ok('read-only: a build and an ask changed not one row in any table',
    narrator_snapshot($db) === $before,
    json_encode(array_diff_assoc(narrator_snapshot($db), $before)));

$again = xeric_narrator_prompt($T, $db, $now, $Q, ['stories' => [$S]]);
ok('determinism: the same ask assembles byte-identical messages',
    json_encode($again['messages']) === json_encode($msgs));

// ---------------------------------------------------------------------------
// (f) after the story closes: the residue arrives, the oracle still does not
// ---------------------------------------------------------------------------

xeric_story_close($S, $db, $epoch);

$b2   = xeric_narrator_prompt($T, $db, $now, $Q, ['stories' => [$S]]);
$sys2 = (string)$b2['messages'][0]['content'];
$all2 = $sys2 . "\n" . (string)$b2['messages'][1]['content'];

ok('close: the shelf says finished, in the residue\'s own words',
    str_contains($sys2, 'It has finished.')
    && str_contains($sys2, 'nobody in this town has an open question'));

// The boundary, exactly: pastor_dale's on-close memory happens to contain the
// same words as the herring's `actually` — and NOW it reaches the narrator,
// because the world lived it. The truth and the unspilled beat still never do.
ok('close: what the world lived reaches the record',
    str_contains($all2, 'never did say who he was sitting with'));
$stillOracle = array_values(array_diff($ORACLE, ['who he was sitting with']));
ok('close: the rest of the oracle shelf stays out, closed or not',
    leaks($all2, $stillOracle) === [], implode(' | ', leaks($all2, $stillOracle)));
ok('close: the story reads closed on the manifest',
    ($b2['sources']['stories']['mill_stairwell'] ?? '') === 'closed');

// ---------------------------------------------------------------------------
// (g) the CLI wires the same assembly, and the fences hold through it
// ---------------------------------------------------------------------------

$wd = sys_get_temp_dir() . '/xeric-narrator-world-' . getmypid();
@mkdir($wd);
@unlink($wd . '/world.db');
copy($tplPath, $wd . '/world-template.json');
copy(__DIR__ . '/../fixtures/milldale-story.json', $wd . '/story-mill_stairwell.json');

$out = [];
$rc  = 1;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../narrator-cli.php')
    . ' --world=' . escapeshellarg($wd) . ' --context --epoch=' . $epoch . ' 2>&1', $out, $rc);
$cli = implode("\n", $out);

ok('cli: --context exits 0', $rc === 0, substr($cli, 0, 300));
ok('cli: prints the identity, the record and the sources line',
    str_contains($cli, 'YOU ARE THE NARRATOR')
    && str_contains($cli, 'THE WORLD AS IT STANDS')
    && str_contains($cli, 'drawn from: the bible'));
ok('cli: the oracle shelf stays out of the CLI path too',
    leaks($cli, $ORACLE) === [], implode(' | ', leaks($cli, $ORACLE)));

// ---------------------------------------------------------------------------

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
