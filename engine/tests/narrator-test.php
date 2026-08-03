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
 *
 * The write-ahead sections (o)–(s) defend the third power the same way: an
 * intent's words reach the owner's listing and nothing else — not the ask
 * prompt, not the investigate prompt, not the sweep's event prompt, not the
 * trail — and the lean they license shifts a seeded draw without ever forcing
 * it. An incompatible world draws byte-for-byte the draw it always drew.
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
// (o) WRITE AHEAD — recording, parsing, and the oracle-shaped absence
// ---------------------------------------------------------------------------

/** Run $fn, return the exception message it threw, or '' if it didn't. */
function intent_err(callable $fn): string
{
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

$IN_NEEDLE = 'INTENT-NEEDLE-OMEGA';
$madeA = xeric_narrator_intend($T, $db,
    '  ' . $IN_NEEDLE . " Ruth finds the missing\nhymn ledger at the Bluebird Diner  ",
    ['epoch' => $epoch]);
$inA = $madeA['intent'];

ok('intend: the hints are parsed by code — who, where, and nothing guessed',
    $inA['participants'] === ['ruth'] && $inA['place'] === 'bluebird' && $inA['kind'] === null,
    json_encode($inA));
ok('intend: the row is live, numbered, flattened, and fades fourteen world-days out',
    $inA['n'] === 1 && $inA['state'] === 'live' && !str_contains($inA['text'], "\n")
    && $inA['fades'] === $epoch + 14 * 86400);
ok('intend: the intent lives where the oracle lives — a world_state row no prompt reads',
    xeric_world_state_get($db, 'intent:1') !== null
    && xeric_world_state_get($db, 'intent_seq') === '1');

$hints = xeric_narrator_intent_hints($T, 'a rumor goes round about the mill');
ok('intend: a kind of hour and a place are read out of plain words',
    $hints['kind'] === 'rumor' && $hints['place'] === 'the_mill' && $hints['participants'] === []);

ok('intend: no words is refused',
    str_contains(intent_err(fn() => xeric_narrator_intend($T, $db, '   ')), 'needs words'));
ok('intend: an essay is refused — an intent is one breath',
    str_contains(intent_err(fn() => xeric_narrator_intend($T, $db, str_repeat('word ', 60))), 'one breath'));
ok('intend: an explicit kind the engine does not know is refused',
    str_contains(intent_err(fn() => xeric_narrator_intend($T, $db, 'something', ['kind' => 'wedding'])),
        'no kind of hour'));
ok('intend: an explicit participant outside the cast is refused',
    str_contains(intent_err(fn() => xeric_narrator_intend($T, $db, 'something', ['participants' => ['zed']])),
        'not in this cast'));

// The absence that makes it the oracle's: the ask prompt is rebuilt over the
// same world, now with an intent recorded, and carries not one word of it.
$b3   = xeric_narrator_prompt($T, $db, $now, $Q, ['stories' => [$S]]);
$all3 = (string)$b3['messages'][0]['content'] . "\n" . (string)$b3['messages'][1]['content'];
ok('write-ahead: the intent is absent from the ask prompt by construction',
    !str_contains($all3, $IN_NEEDLE) && !str_contains($all3, 'missing hymn ledger'));
ok('write-ahead: the ask manifest does not even know intents exist',
    !array_key_exists('intents', $b3['sources']));

$snapI = narrator_snapshot($db);
$audI  = xeric_narrator_investigate($T, $db, ['stub' => fn() => '[]'], ['now' => $now, 'days' => 3]);
$iProm = '';
foreach ($audI['messages'] as $x) $iProm .= $x['content'] . "\n";
$iObs = '';
foreach ($audI['observations'] as $x) $iObs .= $x['text'] . "\n";
ok('write-ahead: the intent is absent from the investigate prompt and, unfaded, from the report',
    !str_contains($iProm, $IN_NEEDLE) && !str_contains($iObs, $IN_NEEDLE)
    && audit_find($audI, 'faded_intent') === []);
ok('write-ahead: the audit reads the intent ledger onto its manifest, and settles nothing',
    $audI['sources']['intents'] === [1] && narrator_snapshot($db) === $snapI);

// ---------------------------------------------------------------------------
// (p) matching — two doors, argued; the history fence; retiring
// ---------------------------------------------------------------------------

$dbPathM = sys_get_temp_dir() . '/xeric-narrator-intent-' . getmypid() . '.db';
foreach ([$dbPathM, $dbPathM . '-wal', $dbPathM . '-shm'] as $f) @unlink($f);
$dbM = xeric_state_open($dbPathM);

$iA = xeric_narrator_intend($T, $dbM, 'Dot and Theo end up at a shared meal', ['epoch' => $epoch]);
ok('match setup: people and kind both parsed',
    $iA['intent']['participants'] === ['dot', 'theo'] && $iA['intent']['kind'] === 'shared_meal');

$evBefore = xeric_event_add($dbM, 'coffee happened', $epoch - 3600, 'bluebird', ['dot', 'theo'], 'Nothing much.');
$evWrong  = xeric_event_add($dbM, 'a quiet visit', $epoch + 3600, 'bluebird', ['dot', 'theo'], 'He dropped by.');
xeric_world_state_set($dbM, 'why:event:' . $evWrong, json_encode(['kind' => 'visit']));
$st0 = xeric_narrator_intents_settle($T, $dbM);
ok('match: people alone do not retire an intent — the kind half is required too',
    $st0['settled'] === [] && (xeric_narrator_intents($dbM)[1]['state'] ?? '') === 'live');

$evYes = xeric_event_add($dbM, 'supper ran long', $epoch + 7200, 'bluebird', ['dot', 'theo', 'ruth'], 'Plates went round.');
xeric_world_state_set($dbM, 'why:event:' . $evYes, json_encode(['kind' => 'shared_meal']));
$st1 = xeric_narrator_intents_settle($T, $dbM);
ok('match: the strong door — its people and its kind of hour — retires it, citing the hour',
    isset($st1['settled'][1]) && $st1['settled'][1]['done']['event'] === $evYes
    && $st1['settled'][1]['done']['by'] === 'its people and its kind of hour');

// The kind read the other way: no kept trail, but a participant's memory of
// the hour carries it in its meta, the way every sweep-written memory does.
$iB = xeric_narrator_intend($T, $dbM, 'Ruth and Harlan share a routine', ['epoch' => $epoch]);
$evMem = xeric_event_add($dbM, 'the counting went twice', $epoch + 9000, null, ['ruth', 'harlan'], 'The drawer would not balance.');
xeric_memory_add($dbM, 'ruth', 'Ruth watched the drawer refuse to balance', 'event',
    ['event_id' => $evMem, 'kind' => 'routine'], $epoch + 9000);
$st2 = xeric_narrator_intents_settle($T, $dbM);
ok('match: the kind is read from a memory\'s meta when no trail was kept',
    isset($st2['settled'][2]) && $st2['settled'][2]['done']['event'] === $evMem);

$iC = xeric_narrator_intend($T, $dbM, 'INTENT-LOOSE-NEEDLE the drawer would not balance twice', ['epoch' => $epoch]);
ok('intend: an intent that reads nothing is stored loose, and says so',
    $iC['intent']['participants'] === [] && $iC['intent']['kind'] === null
    && str_contains(implode(' ', $iC['notes']), 'stored loose'));
$st3 = xeric_narrator_intents_settle($T, $dbM);
ok('match: the loose door — its own words, the same needle the wall uses',
    isset($st3['settled'][3]) && $st3['settled'][3]['done']['event'] === $evMem
    && $st3['settled'][3]['done']['by'] === 'its own words');

$iD = xeric_narrator_intend($T, $dbM, 'INTENT-LATE-NEEDLE plates went round at supper again', ['epoch' => $epoch + 86400]);
$st4 = xeric_narrator_intents_settle($T, $dbM);
ok('match: history cannot realize an intent — an hour before the recording does not count',
    $st4['settled'] === [] && (xeric_narrator_intents($dbM)[4]['state'] ?? '') === 'live');

$ret = xeric_narrator_intent_retire($dbM, 4, $epoch + 86400);
ok('retire: the owner takes a live intent back',
    $ret !== null && $ret['state'] === 'retired'
    && (xeric_narrator_intents($dbM)[4]['state'] ?? '') === 'retired');
ok('retire: only once, and never a realized beat',
    xeric_narrator_intent_retire($dbM, 4, $epoch) === null
    && xeric_narrator_intent_retire($dbM, 1, $epoch) === null
    && xeric_narrator_intent_retire($dbM, 99, $epoch) === null);

// Names are content words to the needle, so an intent that names its people
// must also find them at the hour — otherwise "Dot and Ruth see the drawer"
// retires on Ruth alone, which a live run actually produced.
$iE = xeric_narrator_intend($T, $dbM, 'INTENT-BAR Dot and Ruth see the drawer would not balance', ['epoch' => $epoch]);
$st5 = xeric_narrator_intents_settle($T, $dbM);
ok('match: the word door is barred to an hour missing somebody the intent named',
    $iE['intent']['participants'] === ['ruth', 'dot'] || $iE['intent']['participants'] === ['dot', 'ruth']
        ? $st5['settled'] === [] && (xeric_narrator_intents($dbM)[5]['state'] ?? '') === 'live'
        : false,
    json_encode($iE['intent']));

// ---------------------------------------------------------------------------
// (q) the lean — a pull the draw can feel and the prompt cannot see
// ---------------------------------------------------------------------------

// Four people, all free, nowhere to be: six equal pairs. The intent names one.
$TL = [
    'meta'   => ['name' => 'Leanfield'],
    'user'   => ['name' => 'Neil', 'timezone' => 'UTC'],
    'cast'   => ['characters' => [
        ['handle' => 'ada',  'display_name' => 'Ada Verne'],
        ['handle' => 'bram', 'display_name' => 'Bram Ott'],
        ['handle' => 'cass', 'display_name' => 'Cass Rhee'],
        ['handle' => 'dree', 'display_name' => 'Dree Fenn'],
    ]],
    'places' => [],
    'forge'  => ['armed' => []],
];
$nowL   = xeric_world_now($TL, $epoch);
$kindsL = xeric_sweep_kinds_for($TL);
$pairOf = function (?array $c): array {
    if ($c === null) return [];
    $h = array_map('strval', (array)$c['handles']);
    sort($h);
    return $h;
};

$base = $lean = 0;
$trailOk = true;
for ($s = 1; $s <= 400; $s++) {
    mt_srand($s);
    $c0 = xeric_sweep_choose($TL, $nowL, $kindsL, []);
    mt_srand($s);
    $c1 = xeric_sweep_choose($TL, $nowL, $kindsL,
        ['intents' => [['n' => 1, 'participants' => ['ada', 'bram']]]]);
    if ($pairOf($c0) === ['ada', 'bram']) $base++;
    $won = $pairOf($c1) === ['ada', 'bram'];
    if ($won) $lean++;

    $tl = $c1['trail']['lean'] ?? null;
    if ($tl === null || $tl['why'] !== 'leaning toward an intended beat'
        || $tl['intents'] !== [1] || $tl['groups'] !== 1 || $tl['kind'] !== false
        || $tl['chose'] !== $won) $trailOk = false;
    if (!array_key_exists('lean', $c0['trail']) || $c0['trail']['lean'] !== null) $trailOk = false;
}
ok('lean: the pull measurably shifts a seeded draw toward the intended pair',
    $base > 0 && $lean > $base, "base $base, lean $lean of 400");
ok('lean: and never forces it — the rest of the town keeps winning hours',
    $lean < 400, "lean $lean of 400");
ok('lean: the trail records it every time, by number and in the appointed words',
    $trailOk);

// An incompatible intent — a stranger, an unarmed kind — is not a smaller
// pull, it is no pull: the same seed draws the same bytes.
mt_srand(77);
$p0 = xeric_sweep_choose($TL, $nowL, $kindsL, []);
mt_srand(77);
$p1 = xeric_sweep_choose($TL, $nowL, $kindsL, ['intents' => [
    ['n' => 9, 'participants' => ['ada', 'zed'], 'kind' => 'rumor'],
    ['n' => 10, 'participants' => [], 'kind' => null],
]]);
ok('lean: an incompatible world draws byte-for-byte the draw it always drew',
    json_encode($p0) === json_encode($p1));

// The kind half: two armed kinds, an intent asking for one by name.
$TLK = $TL;
$TLK['forge'] = ['armed' => ['rumors', 'faith']];
$kindsK = xeric_sweep_kinds_for($TLK);
$bk = $lk = 0;
$kTrailOk = true;
for ($s = 1; $s <= 400; $s++) {
    mt_srand($s);
    if (xeric_sweep_choose($TLK, $nowL, $kindsK, [])['kind']['key'] === 'ritual') $bk++;
    mt_srand($s);
    $c1 = xeric_sweep_choose($TLK, $nowL, $kindsK, ['intents' => [['n' => 2, 'kind' => 'ritual']]]);
    $tl = $c1['trail']['lean'] ?? null;
    if ($c1['kind']['key'] === 'ritual') {
        $lk++;
        if ($tl === null || $tl['kind'] !== true || $tl['intents'] !== [2]
            || $tl['groups'] !== 0 || $tl['chose'] !== false) $kTrailOk = false;
    } elseif ($tl !== null) {
        $kTrailOk = false;      // an hour the lean did not touch says nothing about it
    }
}
ok('lean: a named kind is tried sooner, and still loses hours',
    $bk > 0 && $lk > $bk && $lk < 400, "base $bk, lean $lk of 400");
ok('lean: the kind lean is on the trail only for the hours it actually leaned', $kTrailOk);

// End to end: a lived sweep under a lean, and the event prompt clean of it.
$TSw = $T;
$TSw['forge'] = ['armed' => ['shared_meals']];
$dbPathS = sys_get_temp_dir() . '/xeric-narrator-lean-' . getmypid() . '.db';
foreach ([$dbPathS, $dbPathS . '-wal', $dbPathS . '-shm'] as $f) @unlink($f);
$dbS = xeric_state_open($dbPathS);
xeric_state_seed($dbS, $TSw);

$i1 = xeric_narrator_intend($TSw, $dbS, 'INTENT-STUB-NEEDLE somebody put the folding chairs back wrong', ['epoch' => $epoch - 3600]);
$i2 = xeric_narrator_intend($TSw, $dbS, 'INTENT-LEAN-NEEDLE Dot Vance and Theo Vance should share an evening soon', ['epoch' => $epoch - 3600]);
$leans = xeric_narrator_leans($TSw, $dbS, $epoch);
ok('leans: only the leanable cross the bridge, as hints with exactly no text',
    count($leans) === 1 && $leans[0]['n'] === 2
    && array_keys($leans[0]) === ['n', 'participants', 'kind', 'place']
    && $leans[0]['participants'] === ['dot', 'theo']
    && !str_contains(json_encode($leans), 'NEEDLE'));

$seenSweep = null;
$sweepStub = ['base' => 'stub://', 'stub' => function (string $tag, array $msgs, array $opts) use (&$seenSweep) {
    $seenSweep = $msgs;
    preg_match_all('/^- \[([a-z0-9_]+)\]/mu', (string)($msgs[1]['content'] ?? ''), $m);
    $halves = ['counted the folding chairs twice', 'left the urn switched on late', 'carried the hymnals up alone'];
    $mem = [];
    $i = 0;
    foreach ($m[1] as $h) $mem[$h] = ucfirst($h) . ' ' . $halves[$i++ % 3] . ' tonight.';
    return ['title' => 'the urn ran out early',
            'prose' => 'Somebody put the folding chairs back wrong and nobody said so.',
            'memories' => $mem];
}];

$r = xeric_sweep_run($TSw, $dbS, $sweepStub, $now, ['chance' => 1.0, 'seed' => 31, 'intents' => $leans]);
ok('lean e2e: the hour landed', count($r['events']) === 1, implode('; ', $r['notes']));
$evL = $r['events'][0] ?? ['trail' => []];
$sweepProm = '';
foreach ((array)$seenSweep as $x) $sweepProm .= $x['content'] . "\n";
ok('lean e2e: the sweep EVENT prompt carries not one word of any intent',
    $seenSweep !== null && !str_contains($sweepProm, 'INTENT-STUB-NEEDLE')
    && !str_contains($sweepProm, 'INTENT-LEAN-NEEDLE')
    && !str_contains($sweepProm, 'share an evening'));
ok('lean e2e: the trail says a lean happened — by number, never by words',
    ($evL['trail']['lean']['why'] ?? '') === 'leaning toward an intended beat'
    && in_array(2, (array)($evL['trail']['lean']['intents'] ?? []), true)
    && !str_contains(json_encode($evL['trail']), 'NEEDLE')
    && !str_contains(json_encode($evL['trail']), 'share an evening'));

$stS = xeric_narrator_intents_settle($TSw, $dbS);
ok('lean e2e: the loose intent auto-retires on the hour its own words match',
    isset($stS['settled'][1]) && $stS['settled'][1]['done']['event'] === (int)$evL['id']
    && $stS['settled'][1]['done']['by'] === 'its own words');
ok('lean e2e: a people-only intent stays live — an evening happened, but nobody said it was THE one',
    (xeric_narrator_intents($dbS)[2]['state'] ?? '') === 'live'
    && array_column(xeric_narrator_leans($TSw, $dbS, $epoch), 'n') === [2]);

// ---------------------------------------------------------------------------
// (r) the fade — the beat returns to the owner, and only to the owner
// ---------------------------------------------------------------------------

$i3 = xeric_narrator_intend($TSw, $dbS, 'INTENT-FADE-NEEDLE Ruth Amberg and the organ bench finally give way', ['epoch' => $epoch - 20 * 86400]);
$i4 = xeric_narrator_intend($TSw, $dbS, 'INTENT-REALIZED somebody put the folding chairs back wrong again', ['epoch' => $epoch - 20 * 86400]);
ok('fade: a faded intent stops pulling — the leans exclude it by arithmetic',
    array_column(xeric_narrator_leans($TSw, $dbS, $epoch, ['settle' => false]), 'n') === [2]);

$snapF = narrator_snapshot($dbS);
$audF  = xeric_narrator_investigate($TSw, $dbS, ['stub' => fn() => '[]'], ['now' => $now, 'days' => 3]);
$o = audit_find($audF, 'faded_intent');
ok('fade: the audit returns the beat to the owner, in its own words, citing the intent',
    count($o) === 1 && str_contains($o[0]['text'], 'INTENT-FADE-NEEDLE')
    && str_contains($o[0]['text'], 'never found room')
    && $o[0]['cites'] === ['intents' => [3]]);
ok('fade: a faded intent the record already realized is not a finding',
    !str_contains($o[0]['text'] ?? '', 'INTENT-REALIZED'));
$fProm = '';
foreach ($audF['messages'] as $x) $fProm .= $x['content'] . "\n";
ok('fade: the investigate prompt still carries no intent, faded or not',
    !str_contains($fProm, 'INTENT-FADE-NEEDLE') && !str_contains($fProm, 'INTENT-REALIZED'));
ok('fade: the whole intent ledger is on the audit manifest, and the audit wrote nothing',
    $audF['sources']['intents'] === [1, 2, 3, 4] && narrator_snapshot($dbS) === $snapF);

$stF = xeric_narrator_intents_settle($TSw, $dbS);
ok('fade: the realized one settles as done the next time anybody settles',
    isset($stF['settled'][4]) && $stF['settled'][4]['done']['by'] === 'its own words'
    && (xeric_narrator_intents($dbS)[3]['state'] ?? '') === 'live');

// ---------------------------------------------------------------------------
// (s) the CLI — record, list, retire, and the fences hold through it
// ---------------------------------------------------------------------------

$wd3 = sys_get_temp_dir() . '/xeric-narrator-intent-world-' . getmypid();
@mkdir($wd3);
@unlink($wd3 . '/world.db');
file_put_contents($wd3 . '/world-template.json', json_encode($TSw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$cliN = function (string $flags) use ($wd3, $epoch): array {
    $out = [];
    $rc  = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../narrator-cli.php')
        . ' --world=' . escapeshellarg($wd3) . ' --epoch=' . $epoch . ' ' . $flags . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};

[$rc, $cli] = $cliN('--intend=' . escapeshellarg('INTENT-CLI-NEEDLE Dot and Theo end up at a shared meal'));
ok('cli: --intend records and reads the hints back to the owner',
    $rc === 0 && str_contains($cli, 'intent #1') && str_contains($cli, 'Dot Vance')
    && str_contains($cli, 'a shared meal hour') && str_contains($cli, 'It fades'), substr($cli, 0, 300));

[$rc, $cli] = $cliN('--intents');
ok('cli: --intents is the one surface that prints the words',
    $rc === 0 && str_contains($cli, 'INTENDED BEATS') && str_contains($cli, 'INTENT-CLI-NEEDLE')
    && str_contains($cli, 'live, fades'), substr($cli, 0, 300));

[$rc, $cli] = $cliN('--context');
ok('cli: the ask context stays clean of it', $rc === 0 && !str_contains($cli, 'INTENT-CLI-NEEDLE'));

[$rc, $cli] = $cliN('--retire=1');
ok('cli: --retire takes it back', $rc === 0 && str_contains($cli, 'retired'), substr($cli, 0, 200));
[$rc, $cli] = $cliN('--retire=7');
ok('cli: retiring nothing is an error, not a shrug', $rc === 2 && str_contains($cli, 'no live intent'));
[$rc, $cli] = $cliN('--intents');
ok('cli: the listing shows the retirement', $rc === 0 && str_contains($cli, 'retired —'));

[$rc, $cli] = $cliN('--intend=' . escapeshellarg('INTENT-CLI-FADED the bell rope frays through at last') . ' --fade-days=1');
ok('cli: a short fade is a flag away', $rc === 0, substr($cli, 0, 200));
$out4 = [];
$rc4  = 1;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../narrator-cli.php')
    . ' --world=' . escapeshellarg($wd3) . ' --epoch=' . ($epoch + 2 * 86400)
    . ' --investigate --no-model --days=3 2>&1', $out4, $rc4);
$cli4 = implode("\n", $out4);
ok('cli: the audit hands a faded beat back under its own header, cited by number',
    $rc4 === 0 && str_contains($cli4, 'INTENDED BEATS THE WORLD NEVER FOUND ROOM FOR')
    && str_contains($cli4, 'INTENT-CLI-FADED') && str_contains($cli4, 'intent #2')
    && str_contains($cli4, 'intended beat'), substr($cli4, 0, 400));

// ---------------------------------------------------------------------------

ok('ask: an ordinary question is nobody\'s but the model\'s',
    xeric_narrator_stock('where is everyone tonight') === null
    && xeric_narrator_stock('') === null);

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
