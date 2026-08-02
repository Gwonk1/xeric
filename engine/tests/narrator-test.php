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
// (h) INVESTIGATE — a world built to fail every audit, one way each
// ---------------------------------------------------------------------------
// A second db, purpose-built: every deterministic check has exactly one row
// arranged to trip it and one arranged not to, so a firing proves the check
// and a silence proves the fence. The protagonist's pressure carries its own
// needle — the audit must point at the door, never quote what is behind it.

$T2 = $T;
$T2['cast']['protagonist'] = ['handle' => 'harlan',
    'arc'      => 'the store cannot make another winter',
    'pressure' => 'PRESSURE-NEEDLE the note comes due before the thaw'];

$dbPath2 = sys_get_temp_dir() . '/xeric-narrator-audit-' . getmypid() . '.db';
foreach ([$dbPath2, $dbPath2 . '-wal', $dbPath2 . '-shm'] as $f) @unlink($f);
$db2 = xeric_state_open($dbPath2);
xeric_state_seed($db2, $T2);

// The seeded past: the marker and its rows share one created_at, which is the
// whole boundary the never-lived check reads.
$seedAt = 12345;
xeric_world_state_set($db2, XERIC_SEED_MARKER,
    json_encode(['events' => 2, 'memories' => 1, 'skipped' => 0, 'at' => $seedAt, 'now' => $epoch - 10 * 86400]), $seedAt);
$eSeed1 = xeric_event_add($db2, 'The Kerr porch light burned all night', $epoch - 9 * 86400, null, ['janelle'], '', $seedAt);
$eSeed2 = xeric_event_add($db2, 'A casserole went round twice', $epoch - 8 * 86400, 'bluebird', ['dot', 'janelle'], '', $seedAt);
$mSeed  = xeric_memory_add($db2, 'janelle', 'JANELLE-SEED the porch light going all night', 'seed', ['seeded' => true], $epoch - 9 * 86400, $seedAt);

// The lived record: the protagonist's one stale hour, and one body in two rooms.
$eHarlan = xeric_event_add($db2, 'Stock came in late at the hardware', $epoch - 5 * 86400, 'beck_hardware', ['harlan'],
    'The truck was late and the counting was done alone.');
$eA = xeric_event_add($db2, 'Coffee ran long at the Bluebird', $epoch - 10 * 3600, 'bluebird', ['ruth'], '');
$eB = xeric_event_add($db2, 'A ladder against the mill fence', $epoch - 10 * 3600 + 1800, 'the_mill', ['ruth', 'theo'], '');

// A death (ledger row only), a memory that jumps the gun on it, and one that
// does not. The by_handle is a needle: tier 1, read by xeric_deaths() into
// every audit pass, and it must never surface anywhere the audit speaks.
$k2 = xeric_death_kill($T2, $db2, 'dot', $epoch - 86400, 'the ice on the church steps', 'KILLER-NEEDLE', false);
ok('audit setup: the death row landed', $k2['ok'] === true, (string)$k2['error']);
$mEarly = xeric_memory_add($db2, 'harlan', 'MEM-EARLY the way he told it, Dot was dead and buried on the ridge', 'auto', [], $epoch - 3 * 86400);
xeric_memory_add($db2, 'ruth', 'MEM-FINE the hymn numbers changed twice', 'auto', [], $epoch - 4 * 3600);

// Threads: one gone quiet on a statement, one ending on a question, one warm.
$cvRuth    = xeric_conversation_create($db2, 'ruth', 'chat');
$qRuth     = xeric_message_append($db2, $cvRuth, 'user', null, 'did anyone ever find the spare ledger key?', $epoch - 6 * 86400);
$rRuthLast = xeric_message_append($db2, $cvRuth, 'character', 'ruth', 'the weather turned cold early', $epoch - 5 * 86400);
$cvDale    = xeric_conversation_create($db2, 'pastor_dale', 'chat');
xeric_message_append($db2, $cvDale, 'character', 'pastor_dale', 'the council can wait', $epoch - 6 * 86400);
$qDale     = xeric_message_append($db2, $cvDale, 'user', null, 'will the council meet this month?', $epoch - 4 * 86400);
$cvHarlan  = xeric_conversation_create($db2, 'harlan', 'chat');
$mWarm     = xeric_message_append($db2, $cvHarlan, 'character', 'harlan', 'stock is in', $epoch - 3600);

// Debts: one boon faded, one still open, one that never fades; one expectation
// open past its grace, one already repaired.
xeric_arc_set($db2, 'ruth',   'boon.potluck_lead', $epoch - 2 * 3600);
xeric_arc_set($db2, 'harlan', 'boon.basement_key', $epoch + 72 * 3600);
xeric_arc_set($db2, 'theo',   'boon.paper_route', 0);
xeric_arc_set($db2, 'theo', 'expect.1', json_encode(['what' => 'the fishing trip', 'quote' => 'I will take you Saturday',
    'when_said' => 'Saturday', 'due' => $epoch - 2 * 86400, 'formed' => $epoch - 4 * 86400, 'state' => 'open']));
xeric_arc_set($db2, 'pastor_dale', 'expect.1', json_encode(['what' => 'the Omaha story', 'quote' => 'I will tell you Sunday',
    'when_said' => 'Sunday', 'due' => $epoch - 3 * 86400, 'formed' => $epoch - 5 * 86400, 'state' => 'repaired']));

$before2 = narrator_snapshot($db2);

/** The observations of one kind whose text contains $needle ('' = all of the kind). */
function audit_find(array $out, string $kind, string $needle = ''): array
{
    $hits = [];
    foreach ($out['observations'] as $o) {
        if ($o['kind'] === $kind && ($needle === '' || stripos((string)$o['text'], $needle) !== false)) $hits[] = $o;
    }
    return $hits;
}

// ---------------------------------------------------------------------------
// (i) every deterministic check fires, and cites the rows it stands on
// ---------------------------------------------------------------------------

$aud = xeric_narrator_investigate($T2, $db2, null, ['now' => $now, 'days' => 3]);

$o = audit_find($aud, 'unspoken', 'Ruth Amberg');
ok('audit: the unheard-from are named with their last line and its id',
    count($o) === 1 && $o[0]['cites'] === ['messages' => [$rRuthLast]]
    && str_contains($o[0]['text'], '5 world-days')
    && str_contains($o[0]['text'], 'the weather turned cold early'));
ok('audit: a character with no thread at all is reported as exactly that',
    count(audit_find($aud, 'unspoken', 'never had a thread')) === 2   // janelle and theo
    && audit_find($aud, 'unspoken', 'Janelle Kerr') !== [] && audit_find($aud, 'unspoken', 'Theo Vance') !== []);
ok('audit: a warm thread is not an absence', audit_find($aud, 'unspoken', 'Harlan Beck') === []);
ok('audit: the dead are dead, not absent', audit_find($aud, 'unspoken', 'Dot Vance') === []);

$o = audit_find($aud, 'dropped_question');
ok('audit: a thread that ends on a question is a dropped question, by arithmetic alone',
    count($o) === 1 && $o[0]['cites'] === ['messages' => [$qDale]] && $o[0]['found_by'] === 'code'
    && str_contains($o[0]['text'], 'will the council meet this month?')
    && str_contains($o[0]['text'], 'the thread ends there'));

$o = audit_find($aud, 'unpaid_debt', 'potluck lead');
ok('audit: a boon that faded unclaimed is a debt, cited at its arc',
    count($o) === 1 && $o[0]['cites'] === ['arcs' => ['ruth:boon.potluck_lead']]);
$o = audit_find($aud, 'unpaid_debt', 'fishing trip');
ok('audit: an expectation open past its grace is the same debt one system over',
    count($o) === 1 && $o[0]['cites'] === ['arcs' => ['theo:expect.1']]
    && str_contains($o[0]['text'], 'no miss has ever been recorded'));
ok('audit: an open boon, a never-fading boon and a repaired expectation are nobody\'s debts',
    audit_find($aud, 'unpaid_debt', 'basement') === []
    && audit_find($aud, 'unpaid_debt', 'paper') === []
    && audit_find($aud, 'unpaid_debt', 'Omaha') === []);

$o = audit_find($aud, 'idle_pressure');
ok('audit: idle pressure names the protagonist and cites their last hour',
    count($o) === 1 && $o[0]['cites'] === ['events' => [$eHarlan]]
    && str_contains($o[0]['text'], 'Harlan Beck') && str_contains($o[0]['text'], '5 world-days'));

$o = audit_find($aud, 'never_lived');
$nlEv = (array)($o[0]['cites']['events'] ?? []);
sort($nlEv);
ok('audit: seeded-never-lived fires for the one who only ever arrived, citing the seed rows',
    count($o) === 1 && str_contains($o[0]['text'], 'Janelle Kerr')
    && $nlEv === [$eSeed1, $eSeed2] && ($o[0]['cites']['memories'] ?? []) === [$mSeed]);
ok('audit: living in one lived event is living', audit_find($aud, 'never_lived', 'Theo') === []);

$o = audit_find($aud, 'contradiction', 'inside the same hour');
ok('audit: one body in two rooms in one hour, both hours cited',
    count($o) === 1 && $o[0]['cites'] === ['events' => [$eA, $eB]]
    && str_contains($o[0]['text'], 'Ruth Amberg')
    && str_contains($o[0]['text'], xeric_world_place_name($T2, 'bluebird'))
    && str_contains($o[0]['text'], xeric_world_place_name($T2, 'the_mill')));
$o = audit_find($aud, 'contradiction', 'already speaks of');
ok('audit: a memory that buried Dot before the ice did is caught, memory and ledger cited',
    count($o) === 1 && $o[0]['cites'] === ['memories' => [$mEarly], 'deaths' => ['dot']]
    && str_contains($o[0]['text'], 'Dot Vance'));

$kindOrder = array_flip(XERIC_NARRATOR_KINDS);
$seq = array_map(fn($x) => $kindOrder[$x['kind']], $aud['observations']);
$sorted = $seq; sort($sorted);
ok('audit: the report is grouped in the kinds\' canonical order', $seq === $sorted);

// ---------------------------------------------------------------------------
// (j) the manifest is the assembly's, and the model was never consulted
// ---------------------------------------------------------------------------

$src2 = $aud['sources'];
ok('audit sources: the transcripts ASK never assembled are on the manifest, id by id',
    ($src2['messages']['ruth'] ?? []) === [$qRuth, $rRuthLast]
    && ($src2['messages']['pastor_dale'] ?? []) === [$qDale - 1, $qDale]
    && ($src2['threads']['pastor_dale'] ?? 0) === $epoch - 4 * 86400);
ok('audit sources: every arc read is listed, debts or not',
    in_array('harlan:boon.basement_key', $src2['arcs'], true)
    && in_array('pastor_dale:expect.1', $src2['arcs'], true)
    && in_array('ruth:boon.potluck_lead', $src2['arcs'], true));
ok('audit sources: the walked events and the deaths ledger are the manifest\'s',
    $src2['deaths'] === ['dot']
    && array_diff([$eSeed1, $eSeed2, $eHarlan, $eA, $eB], $src2['events']) === []);
ok('audit sources: no bible was assembled, and the manifest does not claim one',
    !array_key_exists('bible', $src2));
ok('audit: with no endpoint the model was not asked and no prompt was built',
    $aud['model']['asked'] === false && $aud['messages'] === []);

// ---------------------------------------------------------------------------
// (k) the model reads the transcripts — and only points, never cites
// ---------------------------------------------------------------------------

$tagSeen2 = '';
$stub2 = ['stub' => function (string $tag, array $messages, array $opts) use (&$tagSeen2, $qRuth, $qDale, $mWarm) {
    $tagSeen2 = $tag;
    // One honest claim, one invented id, one duplicate of what arithmetic
    // already found, one row that holds no question — the three gates, plus
    // the fences a chatty small model actually produces.
    return ['text' => "Here are my findings:\n```json\n[" .
            '{"handle":"ruth","id":' . $qRuth . '},' .
            '{"handle":"ruth","id":999999},' .
            '{"handle":"pastor_dale","id":' . $qDale . '},' .
            '{"handle":"harlan","id":' . $mWarm . '}]' . "\n```",
        'usage' => ['prompt_tokens' => 21, 'completion_tokens' => 9]];
}];
$aud2 = xeric_narrator_investigate($T2, $db2, $stub2, ['now' => $now, 'days' => 3]);

ok('investigate: the seam is tagged investigate, so a stub can tell the audit from the ask',
    $tagSeen2 === 'investigate');
ok('investigate: one claim kept, three dropped at the gates',
    $aud2['model'] === ['asked' => true, 'claims' => 4, 'kept' => 1, 'dropped' => 3,
                        'usage' => $aud2['model']['usage']]
    && ($aud2['model']['usage']['prompt_tokens'] ?? 0) === 21
    && array_key_exists('ms', $aud2['model']['usage']));
$o = audit_find($aud2, 'dropped_question', 'spare ledger key');
ok('investigate: the kept claim is re-cited by code from the row it verified',
    count($o) === 1 && $o[0]['found_by'] === 'model'
    && $o[0]['cites'] === ['messages' => [$qRuth]]
    && str_contains($o[0]['text'], 'the conversation moved on without an answer'));
ok('investigate: the invented id, the duplicate and the questionless row produced nothing',
    count(audit_find($aud2, 'dropped_question')) === 2);   // qDale (code) + qRuth (model)

$mUsr = (string)$aud2['messages'][1]['content'];
$mSys = (string)$aud2['messages'][0]['content'];
ok('investigate prompt: the transcripts ride with their ids, speaker by speaker',
    str_contains($mUsr, 'THREAD WITH Ruth Amberg (handle: ruth)')
    && str_contains($mUsr, '[#' . $qRuth . '] Walt: did anyone ever find the spare ledger key?')
    && str_contains($mUsr, '[#' . $rRuthLast . '] Ruth Amberg: the weather turned cold early'));
ok('investigate prompt: no bible, no shelf — the threads and the one instruction',
    !str_contains($mSys, 'WHAT YOU KNOW') && !str_contains($mSys . $mUsr, 'five-card draw')
    && !str_contains($mSys . $mUsr, 'glovebox'));

// ---------------------------------------------------------------------------
// (l) the discretion holds: point at the door, and the oracle stays absent
// ---------------------------------------------------------------------------

$allText = '';
foreach ($aud2['observations'] as $x) $allText .= $x['text'] . "\n";
ok('discretion: the pressure is never quoted — the audit points at the door',
    !str_contains($allText . $mSys . $mUsr, 'PRESSURE-NEEDLE'));
ok('discretion: who did it stays in the store — the audit names no killer',
    !str_contains($allText . $mSys . $mUsr, 'KILLER-NEEDLE'));

// Against the story-lived world from the ASK sections: the overlay is spilled
// AND closed there, and the investigate prompt still carries none of it.
$snapO = narrator_snapshot($db);
$audO  = xeric_narrator_investigate($T, $db, ['stub' => fn() => '[]'],
    ['now' => $now, 'days' => 3]);
$oText = '';
foreach ($audO['observations'] as $x) $oText .= $x['text'] . "\n";
$oProm = '';
foreach ($audO['messages'] as $x) $oProm .= $x['content'] . "\n";
ok('oracle: no overlay-only string reaches the investigate prompt or the audit, closed story or not',
    leaks($oProm . $oText, $ORACLE) === [], implode(' | ', leaks($oProm . $oText, $ORACLE)));
ok('read-only: investigating the story world changed not one row',
    narrator_snapshot($db) === $snapO);

// ---------------------------------------------------------------------------
// (m) read-only, and the same audit twice is the same bytes
// ---------------------------------------------------------------------------

ok('read-only: the full audit — arithmetic and model pass — changed not one row in any table',
    narrator_snapshot($db2) === $before2,
    json_encode(array_diff_assoc(narrator_snapshot($db2), $before2)));
$again2 = xeric_narrator_investigate($T2, $db2, null, ['now' => $now, 'days' => 3]);
ok('determinism: the same audit assembles byte-identical observations and manifest',
    json_encode($again2) === json_encode($aud));

// ---------------------------------------------------------------------------
// (n) the CLI wires the same audit, grouped, cited, arithmetic-only on request
// ---------------------------------------------------------------------------

$wd2 = sys_get_temp_dir() . '/xeric-narrator-audit-world-' . getmypid();
@mkdir($wd2);
file_put_contents($wd2 . '/world-template.json', json_encode($T2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$out2 = [];
$rc2  = 1;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../narrator-cli.php')
    . ' --world=' . escapeshellarg($wd2) . ' --db=' . escapeshellarg($dbPath2)
    . ' --investigate --no-model --days=3 --epoch=' . $epoch . ' 2>&1', $out2, $rc2);
$cli2 = implode("\n", $out2);

ok('cli: --investigate exits 0', $rc2 === 0, substr($cli2, 0, 300));
ok('cli: the audit is grouped under the kinds\' own headers',
    str_contains($cli2, 'NOT SPOKEN TO IN 3 WORLD-DAYS')
    && str_contains($cli2, 'QUESTIONS ASKED, NEVER ANSWERED')
    && str_contains($cli2, 'DEBTS OPEN PAST THEIR FADE')
    && str_contains($cli2, 'PRESSURE PRODUCING NOTHING')
    && str_contains($cli2, 'SEEDED, NEVER LIVED')
    && str_contains($cli2, 'WHAT THE RECORD CONTRADICTS'));
ok('cli: every line stands on printed citations',
    str_contains($cli2, 'event #' . $eHarlan)
    && str_contains($cli2, 'message #' . $qDale)
    && str_contains($cli2, 'arc ruth/boon.potluck_lead')
    && str_contains($cli2, 'the deaths ledger (dot)'));
ok('cli: --no-model says so, and the sources line claims no bible',
    str_contains($cli2, 'no model was asked; every line above is arithmetic.')
    && str_contains($cli2, 'drawn from:') && !str_contains($cli2, 'the bible'));
ok('cli: the pressure, the killer and the oracle stay out of the CLI audit too',
    !str_contains($cli2, 'PRESSURE-NEEDLE') && !str_contains($cli2, 'KILLER-NEEDLE')
    && leaks($cli2, $ORACLE) === [],
    implode(' | ', leaks($cli2, $ORACLE)));

// ---------------------------------------------------------------------------

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
