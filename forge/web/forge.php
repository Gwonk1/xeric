<?php
/**
 * forge.php — the Forge, as a wizard you can hold in one hand.
 *
 * The whole interview is rendered ONCE, from interview.json, as a stack of
 * sections; the browser shows one at a time. That is deliberate:
 *
 *  • THE QUESTIONS ARE DATA. Nothing here knows what "motivation" means. Add a
 *    step to interview.json and a screen appears, presets and all.
 *
 *  • THREE DOORS ON EVERY QUESTION (FORGE.md). Answer it, type something we
 *    never thought of, or press ✨. Every screen carries all three, plus the
 *    escape hatch — ✨ for everything else — because a user who does not know
 *    what they want must still be able to get a coherent world.
 *
 *  • A STEP MARKED presets_are_suggestions INVERTS. The free-text box goes
 *    first and big, the presets go underneath and quiet. Motivation is open by
 *    design and the layout has to say so before the copy does.
 *
 *  • THE KEY NEVER LANDS. A bring-your-own key lives in one form field and
 *    rides the build POST. It is not saved to the session, not put in a URL
 *    (which would be in an access log), and not sent back to the page.
 *
 *  • THE AGE QUESTION IS NOT ASKED, AND THERE IS NOWHERE TO ANSWER IT. This
 *    page has no age field and no way to send one. What it has is a box on the
 *    ONE step where content is chosen, revealed only by reaching for a rating
 *    above the weakest — press "TV-G", or skip the question the way
 *    every unattended path skips it, and nothing about age is ever put on
 *    screen. The box is the choice: until it is ticked the gated presets do not
 *    take, and the screen keeps the value it had.
 */

declare(strict_types=1);

require_once __DIR__ . '/review-lib.php';   // → ui.php: the result screen points at the review step
require_once __DIR__ . '/pdf.php';         // text out of a PDF, with or without poppler

/** As big a PDF as is worth reading. Past this, somebody means a chapter of it. */
const XERIC_PDF_MAX = 25165824;             // 24MB

/**
 * And how much of its text becomes a premise.
 *
 * The concept pass reads one prompt, and a premise is a brief rather than a
 * source text: the first few thousand characters of anything are where somebody
 * says what it is.
 */
const XERIC_PDF_CHARS = 12000;                // must match xeric_web_answer_cap('premise')

$interview = xeric_forge_interview(XERIC_WEB_LIB . '/forge/interview.json');
$steps = array_values(array_filter((array)($interview['steps'] ?? []), fn($s) => (string)($s['key'] ?? '') !== ''));

// ---------------------------------------------------------------------------
// Small JSON actions the wizard calls while the user is still typing
// ---------------------------------------------------------------------------

$action = (string)($_GET['a'] ?? '');

if ($action === 'save') {
    $in = xeric_web_input();
    $m = (array)($in['model'] ?? []);
    // Cleaned OUTSIDE the lock. It reads the affirmation, and a read that ever
    // reached for the record's own lock while this one holds it would hang the
    // save rather than slow it — nothing in here needs the lock to do its work.
    $clean = xeric_web_clean_answers((array)($in['answers'] ?? []), $interview);
    xeric_web_session_edit(function (array &$s) use ($clean, $m): void {
        $s['answers'] = $clean;
        $s['model'] = ['kind' => (string)($m['kind'] ?? 'local'), 'base' => (string)($m['base'] ?? ''),
                       'model' => (string)($m['model'] ?? '')];   // never the key
    });
    xeric_web_json(['ok' => true]);
}

// The back cover, for a world forged before back covers existed. New forges
// write meta.teaser as the build's last pass; this backfills an old one on the
// owner's first visit and then never runs again — the answer is on disk.
if ($action === 'teaser') {
    require_once dirname(__DIR__) . '/forge.php';   // xeric_forge_teaser()
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') xeric_web_json(['error' => 'that is a POST'], 405);
    $in   = xeric_web_input();
    $slug = xeric_web_slug((string)($in['xeric'] ?? ''));
    xeric_play_guard($slug, 'A back cover is written from the maker\'s own copy.');

    try { $w = xeric_review_open($slug); }
    catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage()], 404); }
    if (!$w['mine']) xeric_web_json(['error' => 'not yours to write a cover for'], 403);

    // `fresh` forces a rewrite: the caller knows the book changed under the
    // cover (a repass corrected it, a reroll moved it) and the stored blurb is
    // now about a previous draft.
    $have = trim((string)($w['template']['meta']['teaser'] ?? ''));
    if ($have !== '' && empty($in['fresh'])) xeric_web_json(['ok' => true, 'teaser' => $have]);

    try { $endpoint = xeric_play_endpoint(); }
    catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409); }

    $hold = xeric_queue_take('say', 6.0, 'teaser:' . $slug);
    if (($hold['ok'] ?? false) !== true) {
        xeric_web_json(['error' => 'The model is busy. The cover will write itself next visit.', 'kind' => 'busy'], 503);
    }
    try {
        $blurb = xeric_forge_teaser($w['template'], $endpoint);
    } catch (Throwable $e) {
        xeric_queue_release($hold);
        xeric_web_json(['error' => 'no cover came back', 'kind' => 'model'], 502);
    }
    xeric_queue_release($hold);
    if ($blurb === '') xeric_web_json(['error' => 'no cover came back', 'kind' => 'empty'], 502);

    $t = $w['template'];
    $t['meta']['teaser'] = $blurb;
    try { xeric_review_save($slug, $t); }
    catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage()], 422); }
    xeric_web_json(['ok' => true, 'teaser' => $blurb, 'tokens' => xeric_web_tokens_by(xeric_session_id())]);
}

// The affirmation, and the only thing this endpoint can touch. It takes a
// BOOLEAN and there is no age in the request to take — the whole point of the
// pattern is that the server never learns one. The answer is what the session
// affirms AFTERWARDS, which is not always what was asked for: a session too new
// to have come back with its cookie cannot affirm at all (session.php), and the
// page has to be told so rather than left showing a ticked box.
if ($action === 'affirm') {
    $in = xeric_web_input();
    xeric_web_json(['ok' => true, 'adult' => xeric_session_affirm(($in['adult'] ?? null) === true)]);
}

if ($action === 'surprise') {
    // One question's worth of ✨. Drawn from a WHOLE concept row scored against
    // what they have already said, so surprising one field never contradicts
    // the fields around it — the same rule the full fill obeys.
    $in = xeric_web_input();
    $key = (string)($in['key'] ?? '');
    $answers = xeric_web_clean_answers((array)($in['answers'] ?? []), $interview);
    unset($answers[$key]);
    try {
        $concept = xeric_forge_concept_pick($interview, $answers);
    } catch (Throwable $e) {
        xeric_web_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $value = $concept['answers'][$key] ?? null;
    if ($value === null) {
        foreach ($steps as $s) {
            if ((string)$s['key'] !== $key) continue;
            $p = (array)($s['presets'] ?? []);
            if ($p) $value = (string)$p[array_rand($p)]['value'];
        }
    }
    if (is_array($value)) $value = implode(', ', array_map('strval', $value));
    $value = (string)($value ?? '');
    // ✨ is not a way around the gate. The concepts carry ratings and the preset
    // fallback above draws at random, and a random draw is the exact definition
    // of nobody having decided anything.
    if ($key === 'rating' && $value !== '') $value = xeric_session_rating($value);
    xeric_web_json(['ok' => true, 'value' => $value, 'concept' => (string)($concept['label'] ?? '')]);
}

// -- read a PDF ---------------------------------------------------------------
// TEXT OUT, NOTHING ELSE IN. What comes back goes straight into the box the
// visitor was already typing in, so a document is treated as words somebody
// wrote rather than as a second, invisible input the forge trusts more than
// them. It is editable the moment it lands.
//
// pdftotext (poppler), because it is on virtually every machine that has a PDF
// viewer, it is one process, and it needs nothing added to PHP. NO OCR: a scan
// with no text layer comes back empty and says so, which is a better answer
// than a page of garbage that reads like a premise.
//
// THE FILE NEVER LANDS ANYWHERE IT COULD BE SERVED. It goes to a temp path
// outside the docroot, is read once, and is deleted in a finally.
if ($action === 'pdf') {
    $f = $_FILES['pdf'] ?? null;
    if (!is_array($f) || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        xeric_web_json(['error' => 'no file arrived'], 400);
    }
    if ((int)$f['size'] > XERIC_PDF_MAX) {
        xeric_web_json(['error' => 'That file is larger than '
            . (int)round(XERIC_PDF_MAX / 1048576) . 'MB. Copy the part you mean and paste it.'], 413);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'xeric-pdf-');
    $how = '';
    try {
        if (!@move_uploaded_file((string)$f['tmp_name'], $tmp)) {
            xeric_web_json(['error' => 'that file could not be read'], 500);
        }
        $r    = xeric_pdf_text($tmp);
        $text = (string)$r['text'];
        $how  = (string)$r['how'];
    } finally {
        @unlink($tmp);
    }

    if ($text === '') {
        xeric_web_json(['error' => 'Nothing readable came out of that. If it is a scan there is no '
            . 'text in it to read, only pictures of text.'], 422);
    }

    // Trimmed to what a premise can be. A whole book is not a brief, and the
    // concept pass reads one prompt.
    $text = mb_substr($text, 0, XERIC_PDF_CHARS);
    xeric_web_json(['ok' => true, 'text' => $text, 'how' => $how,
                    'words' => count(preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])]);
}

// -- start blank --------------------------------------------------------------
// NO MODEL, NO QUEUE, NO WORKER. A xeric with nothing invented in it is built by
// calling the same fallbacks a forge pass uses when the model is unreachable, so
// it is a real xeric for the same reason a forged one is — and it is ready
// before the click finishes, which is why this is a plain POST rather than a job
// with a progress stream to watch.
if ($action === 'blank') {
    $sid = xeric_session_id();
    xeric_limit_guard(xeric_limit_check('forge', ['sid' => $sid]));

    try {
        $in = xeric_web_input();
        // NOTHING BUT THE TWO ANSWERS. The session's leftover wizard answers
        // used to be folded in here, which meant a half-finished interview from
        // an hour ago decided this world's scale, motivation and locale — the
        // exact "somebody else's town" problem blank exists to avoid. Two
        // questions in, two answers out.
        $t = xeric_forge_blank(
            [],
            trim((string)($in['name'] ?? '')),
            trim((string)($in['who'] ?? ''))
        );
        // On the anvil, not in play: the same rule a build obeys, and the whole
        // point here — the review page is where the adding happens.
        $t = xeric_review_mark_pending($t);
        $path = xeric_forge_write($t, xeric_forge_default_seed($t), xeric_web_worlds_dir());
        $slug = basename(dirname($path));
    } catch (Throwable $e) {
        xeric_web_json(['error' => 'that could not be started: ' . $e->getMessage()], 500);
    }

    xeric_session_claim($slug, $sid);
    xeric_limit_note('forge', ['sid' => $sid]);
    xeric_web_json(['ok' => true, 'slug' => $slug, 'url' => 'review.php?w=' . rawurlencode($slug)]);
}

if ($action === 'status') {
    $e = xeric_web_endpoint(['kind' => 'local']);
    $q = xeric_queue_status();
    xeric_web_json(['up' => xeric_llm_up($e, 4), 'base' => $e['base'],
                    'busy' => (bool)$q['busy'], 'queue' => $q, 'left' => xeric_limit_left()]);
}

// A visitor's own view of themselves: which worlds are theirs, what they have
// left, and what the one model slot is doing. Same answer as play.php's, from
// the same function, because two versions of "what do you know about me" is one
// too many.
if ($action === 'me') xeric_web_json(xeric_session_me());

// ---------------------------------------------------------------------------
// ?w=<slug> — the result screen for a world already on disk
// ---------------------------------------------------------------------------

$view = xeric_web_slug((string)($_GET['w'] ?? ''));
if ($view !== '') {
    // THE RESULT SCREEN IS THE SEED, IN SENTENCES. ui.php prints every event in
    // seed.json — its title, how many days ago, the room it happened in — which
    // is the same baked past world.php refuses to a stranger outright, on the
    // grounds that prose written with the secrets in hand cannot have them taken
    // back out of it. Refused here for the same reason, in the same words, from
    // the same gate: the last time these two disagreed, one of them was open.
    xeric_play_guard($view, 'The screen a build ends on lists what has already happened in that xeric, '
        . 'the baked past, in the sentences the forge wrote with the xeric\'s secrets in hand. It is '
        . 'shown to whoever forged it and to nobody else.');

    $dir = xeric_web_worlds_dir() . '/' . $view;
    $path = $dir . '/world-template.json';
    xeric_web_head('Xeric: ' . $view);
    echo '<main>';
    // The meter rides this top too: the repass button below spends real
    // tokens, and a spend with no needle is how the meter stops being a joke
    // and starts being a suspicion.
    echo '<div class="top"><p class="wordmark">XERIC</p><span class="kicker">the forge</span>'
        . xeric_web_meter_html() . '<span class="count"><a href="play.php">all xerics</a></span></div>';
    try {
        $t = xeric_world_load($path);
        $seed = json_decode((string)@file_get_contents($dir . '/seed.json'), true) ?: ['events' => [], 'memories' => []];
        $sid  = xeric_session_id();
        $sess = xeric_web_session_read($sid);
        $meta = ['slug' => $view, 'json_url' => 'world.php?w=' . rawurlencode($view),
                 'mine' => true, 'left' => xeric_limit_left($sid),
                 'review' => !xeric_review_launched($t)];
        if ((string)($sess['result']['slug'] ?? '') === $view) {
            $meta['notes'] = (array)($sess['result']['notes'] ?? []);
            $meta['seconds'] = (float)($sess['result']['seconds'] ?? 0);
            $meta['endpoint'] = (string)($sess['result']['endpoint'] ?? '');
        }
        echo xeric_web_result_html($t, (array)$seed, $meta);
    } catch (Throwable $e) {
        echo '<h1>That xeric will not load</h1><p class="note bad">' . h($e->getMessage()) . '</p>';
    }
    echo '</main>';
    exit;
}

// ---------------------------------------------------------------------------
// The wizard
// ---------------------------------------------------------------------------

if (isset($_GET['fresh'])) {
    xeric_web_session_edit(function (array &$s): void {
        $s['answers'] = [];
        unset($s['result']);
    });
    header('Location: forge.php');
    exit;
}

$sid = xeric_session_id();
$sess = xeric_web_session_read($sid);
$answers = (array)$sess['answers'];
$model = (array)$sess['model'];

// One bit, read once. It decides whether the box below starts on screen and
// ticked — a visitor who affirmed yesterday is not asked again, and must still
// be able to take it back.
$adult = xeric_session_adult($sid);

// -- how far an unattended build may go --------------------------------------
// THE RATING THAT A BUILD-WITHOUT-ANSWERS WILL USE, offered wherever such a build
// can be started. There are two such places and they are the SAME build: Auto
// Generate on the first screen, and the ✨ escape hatch on every question screen.
//
// Without a choice here `rating` is left unanswered, and an unanswered rating is
// a GAP — which three separate routes will fill (the premise reader, the model
// fill, and the concept table, four of whose five rows ask for `mature`). Nobody
// decided that, and interview.json says in as many words that nobody deciding
// must land clean.
//
// An ANSWER is not a gap, which is the whole fix: the weakest rating is
// pre-selected, it is written into the SAME field the rating question writes,
// and the fill routes can no longer reach the field at all.
//
// IT IS STILL NOT A WAY AROUND THE GATE. A rating needing the affirmation renders
// disabled until the session carries it, so these boxes can only offer what the
// visitor could already have chosen the long way round.
$ratingStep = null;
foreach ($steps as $rs) {
    if ((string)($rs['key'] ?? '') === 'rating' || !empty($rs['gate'])) { $ratingStep = $rs; break; }
}
$ratingPresets = (array)($ratingStep['presets'] ?? []);
$ratingFloor   = (string)($ratingStep['gate']['required_above'] ?? xeric_ratings()[0]);
$ratingNow     = (string)($answers['rating'] ?? '');
$ratingAffirm  = trim((string)($ratingStep['gate']['affirm'] ?? '')) ?: 'I am 18 or older.';
if ($ratingNow !== '') $ratingNow = xeric_session_rating($ratingNow, $sid);
if ($ratingNow === '') $ratingNow = xeric_ratings()[0];

/**
 * The rating, as a thing you can read at a glance and open if you disagree.
 *
 * A ROW OF RADIOS WAS THE WRONG SHAPE, and the Auto Generate screen proved it
 * twice over. Visually it read as a fourth question on a screen whose whole
 * promise is that you answer nothing — and functionally it was a DEAD END: the
 * gated options were disabled with a note pointing at "the 18+ box on the last
 * question", which is a screen the Auto Generate path never visits. An adult who
 * wanted an adult world had no way to say so from the only screen they were on.
 *
 * So it is a statement with a way in behind it. Closed, it is one line saying
 * what will happen. Open, it is the three choices AND the affirmation together,
 * because the affirmation is the only thing standing between a visitor and two of
 * those choices, and it must be reachable from wherever they are being offered.
 *
 * THE GATE IS NOT WEAKENED BY MOVING IT. Ticking the box here posts to the same
 * endpoint, is believed only if the SERVER says so, and every copy of this
 * control on the page re-reads its answer. What changes is that a person is asked
 * where they are standing rather than sent somewhere else to be asked.
 */
function xeric_web_rating_box(array $presets, string $floor, string $now, bool $adult, string $group, string $label, string $affirmLabel): string
{
    if (!$presets) return '';
    $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $nowLabel = '';
    $opts = '';
    $gatedAny = false;
    foreach ($presets as $p) {
        $v = (string)($p['value'] ?? '');
        if ($v === '') continue;
        $gated = !empty($p['requires_affirmation'])
                 || xeric_rating_rank($v) > xeric_rating_rank($floor);
        if ($gated) $gatedAny = true;
        $lab = (string)($p['label'] ?? $v);
        if ($v === $now) $nowLabel = $lab;
        $opts .= '<button type="button" class="rateopt" data-value="' . $h($v) . '"'
               . ($gated ? ' data-gated="1"' : '')
               . ' aria-pressed="' . ($v === $now ? 'true' : 'false') . '">'
               . '<span class="ro-l">' . $h($lab) . '</span>'
               . (!empty($p['hint']) ? '<span class="ro-h">' . $h($p['hint']) . '</span>' : '')
               . '</button>';
    }
    if ($nowLabel === '') $nowLabel = (string)($presets[0]['label'] ?? $now);

    $pop = 'ratepop-' . $h($group);
    $out = '<div class="rateset" data-pop="' . $h($group) . '">'
         . '<button type="button" class="ratepill" aria-expanded="false" aria-controls="' . $pop . '">'
         // "<what will build> · <what it will be> · change". The label names the
         // control this belongs to rather than describing the rating, because the
         // preset labels are already sentences ("keep it clean") and gluing them
         // to a verb produced "Auto Generate keeps it keep it clean".
         . '<span class="rp-l">' . $h($label) . '</span>'
         . '<span class="rp-s" aria-hidden="true"> · </span>'
         . '<span class="rp-v">' . $h($nowLabel) . '</span>'
         . '<span class="rp-c" aria-hidden="true">change</span>'
         . '</button>'
         . '<div class="ratepop" id="' . $pop . '" hidden>'
         . '<div class="rateopts">' . $opts . '</div>';
    if ($gatedAny) {
        $out .= '<label class="rateaff' . ($adult ? '' : ' ask') . '">'
              . '<input type="checkbox" class="rate-affirm"' . ($adult ? ' checked' : '') . '>'
              . '<span>' . $h($affirmLabel) . '</span></label>'
              . '<p class="ratenote">Nobody is asked how old they are and nothing about it is stored. '
              . 'Untick it and anything past the first choice goes back out of reach.</p>';
    }
    $out .= '</div></div>';
    return $out;
}

// NOTHING ATTACHED MEANS NOTHING TO BUILD WITH, so this page does not open. A
// forge is fourteen model calls; twenty questions ending in "there is no machine"
// is the worst screen in the app, and it is the one a fresh install would show
// every single time. The shelf's plus aims here already — this is the same
// refusal one level deeper, for a bookmark, a back button, or a typed URL.
//
// AND A FRESH INSTALL IS EXACTLY THIS CASE, on purpose. Nothing auto-attaches
// any more (xeric_web_model: FOUND IS NOT CONNECTED), so the first visit lands
// on the machines screen, sees what is alive, and connects it in one press.
if (!xeric_web_connected(xeric_web_model($sid))) {
    header('Location: model.php');
    exit;
}

// EVERY MACHINE THIS VISITOR HAS, and which one is standing ready. model.php
// owns adding and attaching; this page owns "which of them builds this world",
// which is a per-build decision and not a setting.
//
// NOTHING IS PROBED HERE. Five machines checked server-side is five timeouts
// before the page draws, and the answer is stale by the time it renders — the
// browser asks model.php's probe per card, exactly as the machines screen does.
// ONLY THE CONNECTED ONES. A machine on the list that has not been connected is
// an address somebody typed, not a thing that is going to answer — and this
// screen is the last place a build can be stopped cheaply. Keys are preserved
// because build.php resolves the choice as an index into the FULL list.
$all      = xeric_model_list($sid);
$machines = array_intersect_key($all, array_flip(xeric_model_wired_at($all, $sid)));

// The engine forges unless somebody says otherwise: it is the machine already
// running their worlds, so it is the one they have most reason to trust today.
$chosen = xeric_model_active($all, $sid);
if ($chosen < 0 || !isset($machines[$chosen])) {
    $chosen = $machines === [] ? 0 : (int)array_key_first($machines);
}
$localUp  = true;
$queue = xeric_queue_status();
$left  = xeric_limit_left($sid);
$mine  = xeric_session_worlds($sid);

// A build this browser started may still be running — a reload, a phone that
// locked, a stream the proxy cut. The build was never in the page, so it can
// simply be rejoined.
$resume = '';
$jobId = (string)($sess['job'] ?? '');
if (xeric_web_job_ok($jobId) && is_file(xeric_web_job_path($jobId))) {
    $j = xeric_web_job_read($jobId);
    if (!$j['done']) $resume = $jobId;
}

xeric_web_head('Xeric: the forge');
?>
<main>
  <div class="top">
    <p class="wordmark">XERIC</p>
    <span class="kicker">the forge</span>
    <?= xeric_web_meter_html() ?>
    <span class="count" id="count"></span>
  </div>
  <div class="rail" id="rail" aria-hidden="true"></div>

  <noscript>
    <div class="noscript"><strong>The forge needs JavaScript.</strong> The build streams its
      progress as it happens, three minutes of silence would be indistinguishable from a hang.</div>
  </noscript>

  <!-- ---------------------------------------------------------------- 0 -->
  <!-- THE SHELF'S RULE, APPLIED HERE. Nothing on this screen describes what this
       screen is. It had a headline that made a promise ("twenty minutes from now
       you will have somewhere to be") and a paragraph explaining what a forge
       does — both written for a stranger arriving at a demo, and both read by
       somebody who has already installed the thing and clicked the plus. The
       wordmark, three boxes, and the machines underneath. -->
  <section class="screen live" data-screen="intro">
    <?php if ($queue['drained']): ?>
      <p class="note warn" id="busybanner">The machine's owner has the GPU back for a while, this demo
        runs on their own workstation. You can still look around; nothing can be built until they are done.</p>
    <?php elseif ($queue['busy']): ?>
      <p class="note warn" id="busybanner">The forge is busy with someone else's xeric right now
        (<?= h(xeric_queue_phrase((int)$queue['depth'] + 1, (int)$queue['eta'])) ?>). Start anyway, you will be told where you are in the line, and your build starts by itself when it is your turn.</p>
    <?php endif; ?>

    <?php if ($mine !== [] || (xeric_limit_on() && (int)$left['forges'] < (int)$left['of']['forges'])): ?>
      <p class="note">
        <?php if ($mine !== []): ?>You have forged <?= count($mine) ?>
          <?= count($mine) === 1 ? 'xeric' : 'xerics' ?> in this browser, <a href="play.php">they are on the shelf</a>.<?php endif; ?>
        <?php if (xeric_limit_on()): ?><?= (int)$left['forges'] ?> of <?= (int)$left['of']['forges'] ?> builds left today.<?php endif; ?></p>
    <?php endif; ?>

    <!-- FOUR WAYS IN, AS FOUR BOXES. The shelf's language, reused on purpose:
         a channel is a thing you point at and pick, and picking how to build is
         the same kind of act as picking what to play. It also replaces a screen
         whose only visible choice was "Begin", with the other two ways in as a
         link underneath — which made one of three equals look like the way and
         the others look like escapes.

         BUTTONS, NOT LINKS. Two of these do not navigate: Auto Generate starts a
         build where it stands, and Your Own Idea opens a panel in this same
         page. An <a href="#"> that runs script is a link that lies about what
         will happen when it is opened in a new tab. -->
    <div class="grid ways">
      <button class="tile way" type="button" data-way="auto" aria-describedby="tip-auto">
        <span class="wayface" aria-hidden="true"><span class="wg">&#10024;</span></span>
        <span class="tname">Auto Generate</span>
        <span class="tip" id="tip-auto" role="tooltip">You answer nothing. The forge invents the
          place, the people who live there, what they want from each other, and six weeks of
          things that already happened, then hands you the keys.</span>
      </button>

      <button class="tile way" type="button" data-way="blank" aria-describedby="tip-blank">
        <!-- An empty frame: the room you will fill in. -->
        <span class="wayface" aria-hidden="true"><span class="wblank"></span></span>
        <span class="tname">Start Blank</span>
        <span class="tip" id="tip-blank" role="tooltip">Two questions, then an empty xeric on today's
          date: no rooms, nobody in it, no goal, nothing running. Every field is yours to type — and
          every field has a die beside it, so you can write the ones you care about and roll the rest.
          It has no ending and goes on as long as you keep adding to it.</span>
      </button>

      <button class="tile way" type="button" data-way="own" aria-describedby="tip-own">
        <span class="wayface" aria-hidden="true"><span class="wg wq">&ldquo;</span></span>
        <span class="tname">Your Own Idea</span>
        <span class="tip" id="tip-own" role="tooltip">Describe it yourself: type it, answer
          <?= count($steps) ?> questions, or hand over a PDF. Whatever you give it, the forge keeps
          your place, your era, your names and your facts, and invents only what you left out.</span>
      </button>

      <!-- SOLVE A PROBLEM (owner, 2026-08-03). It was already reachable as a tab
           INSIDE Your Own Idea, which is the wrong shape: the tabs answer "how
           do you want to describe your xeric", and this does not describe a
           xeric at all. It is a different thing to make — a room, a question,
           and three to five people who will not agree about it — so it belongs
           beside the other ways to make something, not underneath one of them.
           The tab stays: somebody already typing a premise can still switch, and
           this tile lights that same tab, so there is one path and not two.

           EXPERIMENTAL IS ON THE TILE, not only in the tooltip. A tooltip is a
           thing you have to go and find, and "this is not a place you live in"
           is the fact somebody needs before they press. -->
      <button class="tile way" type="button" data-way="panel" aria-describedby="tip-panel">
        <span class="wayface" aria-hidden="true"><span class="wg">&#9878;</span></span>
        <span class="tname">Solve a Problem<span class="wexp">experimental</span></span>
        <span class="tip" id="tip-panel" role="tooltip">Not a place to live in — a room to argue in.
          Describe a problem instead of a town and the forge casts three to five people who want
          incompatible things from it, puts them somewhere with a door, and lets them fight.
          Consensus is not the goal: if they cannot get there, it tells you which two positions
          could never both be satisfied, which is usually the more useful answer. They are
          characters arguing, never experts consulting — read it as a way of seeing the
          disagreement, not as advice.</span>
      </button>
    </div>

    <!-- AUTO GENERATE ANSWERS NOTHING, so this is the only place its content
         rating can be said. It sits under the three ways rather than inside the
         Auto Generate tile because that tile is a <button>, and a radio inside a
         button is neither valid nor clickable. Start Blank calls no model and
         invents nothing; Your Own Idea reaches the rating question the long way
         round. This row is for the one way in that builds a whole world without
         asking anything. -->
    <?php if ($ratingPresets): ?>
    <div class="waysrate">
      <?= xeric_web_rating_box($ratingPresets, $ratingFloor, $ratingNow, $adult, 'auto', 'Auto Generate', $ratingAffirm) ?>
    </div>
    <?php endif; ?>

    <!-- WHAT FORGES IT — the whole list now, not a report on one machine.
         This used to print whatever model.php had settled on, because there was
         one attachment and nothing to choose between. There are several
         machines, they are not interchangeable, and WHICH ONE WRITES THE WORLD
         is the single most consequential decision on this page: a 26B and a 4B
         produce different towns from identical answers.

         model.php is still where a machine is added, named and attached; this
         picks which of them does this one build. Attached is the default and
         needs no click.

         THE PAGE STILL CANNOT NAME AN ENDPOINT. What rides the build POST is an
         INDEX into this visitor's own stored list, resolved server-side against
         the session (build.php). That is the boundary model.php enforces when an
         address is stored, and it would be worth nothing if this page could post
         a URL instead. -->
    <h2>what forges it</h2>

    <ul class="mlist forgeat" id="forgeat">
      <?php foreach ($machines as $i => $row):
          $mk  = xeric_model_kind((string)$row['base']);
          $on  = $i === $chosen; ?>
        <li>
          <div class="opt<?= $on ? ' on' : '' ?>" data-i="<?= $i ?>" data-kind="<?= h($mk) ?>">
            <button type="button" class="mpick" aria-label="forge with this machine"
                    aria-pressed="<?= $on ? 'true' : 'false' ?>"></button>
            <span class="thead">
              <span class="t"><?= h((string)preg_replace('#^https?://#', '', $row['base'])) ?></span>
              <?= xeric_web_meter_html($sid, (string)$row['base']) ?>
            </span>
            <p class="whois" data-i="<?= $i ?>" hidden>
              <span class="wsig" aria-hidden="true"></span><span class="wname"></span> <span class="wmodel"></span>
            </p>
            <span class="d"><?= h(xeric_model_says($mk)) ?></span>
            <span class="status">
              <span class="dot" data-i="<?= $i ?>"></span><span class="said" data-i="<?= $i ?>"
                data-remote="<?= $mk === 'local' ? '0' : '1' ?>"
                data-up="<?= $mk === 'local' ? 'Local AI Available' : 'Available' ?>"><?=
                $mk === 'local' ? 'asking…' : 'needs a key' ?></span>
              <!-- THE ANSWER TO ONE QUESTION: is this the machine that is about to
                   build. Green for the one that is, red for the ones that are
                   not — this is a choice being made right now, not a status
                   being reported, and "connected" in grey read as a fact about
                   the machine rather than an answer about this build. -->
              <span class="wired<?= $on ? ' on' : ' no' ?>">
                <span class="dot"></span><?= $on ? 'Forging engine' : 'Not selected' ?></span>
            </span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- ONE KEY FIELD, REVEALED BY THE CHOICE. It was printed only when the
         attached machine happened to be remote, which with a list means the
         field is absent exactly when somebody picks the API they added for this.
         Always in the markup, shown when the chosen machine needs it. -->
    <div class="byo" id="byo">
      <div class="row">
        <label class="field" for="m-key">api key</label>
        <input type="password" id="m-key" autocomplete="off" autocapitalize="off" autocorrect="off"
               spellcheck="false" placeholder="sk-…">
        <p class="hintline">Used for this build and nothing else. It is never written to disk,
          never put in a URL, and never sent back to this page, which is why it is asked for
          here, at the moment it is needed, rather than saved on the machines screen.</p>
      </div>
    </div>

    <p class="corner"><a href="model.php">add or change a machine</a></p>
  </section>

  <!-- ------------------------------------------------------------- blank -->
  <!-- TWO FIELDS, BECAUSE TWO THINGS HAVE TO BE DECIDED AND NOTHING ELSE DOES.
       A xeric is written to a folder named after it, so it cannot be created
       nameless — and the person living in it cannot be invented, because every
       renderer prints their name and every character is handed it. Left blank,
       everybody you add later calls you "you" forever.

       Everything else — where it is, when it is, who is in it, what it is
       about — is filled in afterwards, by hand or by a roll you asked for, on
       the review page and then from inside the world itself. -->
  <section class="screen" data-screen="blank">
    <h1>Two things, then it is yours.</h1>
    <label class="field" for="blankwho">What do people call you?</label>
    <input type="text" class="val" id="blankwho" maxlength="60" placeholder="Sam" autocomplete="off">
    <label class="field" for="blankname">And what is this xeric called?</label>
    <input type="text" class="val" id="blankname" maxlength="60" placeholder="Cold Harbour" autocomplete="off">
    <p class="sub">Nothing else is decided. It starts today, on today's clock, with no rooms, nobody
      in it and nothing it is about — you add all of that yourself, and it goes on as long as you
      keep adding.</p>
  </section>

  <!-- ---------------------------------------------------------- your own -->
  <!-- NOT A QUESTION SCREEN. It carries no data-idx, no ✨ and no rail step,
       because it is not one of the interview's steps — it is the whole
       interview replaced by a paragraph. The engine treats it that way too: a
       premise is read by the concept pass as a brief to follow, not as one more
       preference to average in with the knobs. -->
  <section class="screen" data-screen="premise">
    <h1>Tell it what you have in mind.</h1>

    <!-- THREE WAYS TO SAY THE SAME THING, and they are one way in because they
         are one act: handing the forge what you already have. Typing it, being
         asked for it, and having it written down somewhere are differences in
         how much of it is in your head, not in what it is.

         The wizard was its own tile until it became clear that "answer some
         questions about the xeric you want" and "describe the xeric you want"
         are the same sentence with the work split differently. -->
    <!-- ASK ME SITS FIRST (owner, 2026-08-02): being asked is the gentler door
         and the one a first-timer actually takes, so it gets the leading spot.
         "type it" keeps the SELECTED state because this screen's body is the
         typing surface — ask me is less a tab than a door, and it leaves. -->
    <div class="ways3" role="tablist" aria-label="how to describe it">
      <button type="button" class="w3" role="tab" aria-selected="false" data-mode="ask"
              title="Be asked <?= count($steps) ?> questions instead, and skip any of them">ask me</button>
      <button type="button" class="w3 on" role="tab" aria-selected="true" data-mode="type"
              title="Write it in your own words, as much or as little as you like">type it</button>
      <button type="button" class="w3" role="tab" aria-selected="false" data-mode="pdf"
              title="Hand over a PDF and the forge reads the text out of it">a PDF</button>
      <!-- DISCUSSION MODE (owner, 2026-08-03). EXPERIMENTAL, and labelled so on
           the tab itself rather than only in a note underneath: this builds a
           different kind of xeric — not a place you live in, a room you put a
           question into and watch three to five people fail to settle it. The
           engine underneath is the ordinary one, which is why it is a tab here
           and not a second product. -->
      <button type="button" class="w3" role="tab" aria-selected="false" data-mode="panel"
              title="EXPERIMENTAL. Describe a problem instead of a place, and the forge casts a room of people who will not agree about it">a problem 🧪</button>
    </div>

    <p class="sub">A paragraph is plenty. Where you are, when it is, who is around, what the place
      is like. Anything you leave out gets invented to fit what you wrote.</p>
    <textarea class="val" data-key="premise" rows="9" data-ph="A river town of nine hundred in southern Ohio, November 1973. The mill has been closing for two years and everybody knows it. My uncle runs the hardware store on Front Street and hears everything first…" placeholder="A river town of nine hundred in southern Ohio, November 1973. The mill has been closing for two years and everybody knows it. My uncle runs the hardware store on Front Street and hears everything first…"></textarea>
    <p class="hintline">Names, dates and facts you put here are kept. The forge does not overrule them.</p>
    <?= xeric_web_rating_box($ratingPresets, $ratingFloor, $ratingNow, $adult, 'premise', 'And keep it', $ratingAffirm) ?>

    <!-- THE PDF DROPS INTO THE SAME BOX. What comes out of a document is text
         somebody wrote, which is exactly what the field above holds, so it goes
         there rather than into a second hidden place the forge reads instead.
         It is editable the moment it lands: nobody hands over a whole file and
         means every page of it.

         NO CHOOSE BUTTON (owner, 2026-08-02). There used to be a second button
         here — press "a PDF", then press "choose a PDF" — two clicks for one
         act. The tab IS the chooser now: pressing it opens the file dialog
         directly, and this div survives only to hold the status line. -->
    <div class="pdfdrop" id="pdfdrop" hidden>
      <input type="file" id="pdffile" accept="application/pdf,.pdf" hidden>
      <span class="pdfst" id="pdfst"></span>
    </div>
  </section>

  <!-- ------------------------------------------------------------ steps -->
  <?php $boxDrawn = false;
  foreach ($steps as $i => $s):
      $key   = (string)$s['key'];
      $open  = !empty($s['presets_are_suggestions']);
      $free  = !empty($s['allow_free_text']);
      $spark = !empty($s['allow_surprise']);
      $presets = (array)($s['presets'] ?? []);
      $val = (string)($answers[$key] ?? '');
      $long = $open || $key === 'motivation';
      // WHICH STEP IS GATED IS DATA, like everything else here: a step declares
      // a `gate`, and a preset above `gate.required_above` (or one that says
      // requires_affirmation outright) cannot be reached without the box. The
      // `rating` key is ORed in and not relied upon — a gate that could be
      // switched off by editing a JSON file is not a gate.
      $gate = $key === 'rating' || !empty($s['gate']);
      $floor = (string)($s['gate']['required_above'] ?? xeric_ratings()[0]);
      $affirmLabel = trim((string)($s['gate']['affirm'] ?? '')) ?: 'I am 18 or older.';
      // ONE box, however many steps declare a gate. A second copy would be a
      // duplicate id, and a duplicate id is a box the page cannot find — its
      // chips stay gated and unreachable, which is the safe way to be wrong.
      $box = $gate && !$boxDrawn;
      if ($box) $boxDrawn = true;
      // An empty answer stays empty: the gate is not a way of putting a decision
      // on screen that nobody made.
      if ($gate && $val !== '') $val = xeric_session_rating($val, $sid);
  ?>
  <section class="screen" data-screen="q" data-key="<?= h($key) ?>" data-idx="<?= $i ?>" data-spark="<?= $spark ? 1 : 0 ?>">
    <h1><?= h($s['question']) ?></h1>
    <?php if (!empty($s['free_text_hint'])): ?>
      <p class="sub"><?= h($s['free_text_hint']) ?></p>
    <?php endif; ?>

    <?php if ($free && $long): ?>
      <textarea class="val" data-key="<?= h($key) ?>" rows="3"
        placeholder="in your own words…"><?= h($val) ?></textarea>
      <?php if ($presets): ?>
        <p class="chips-label">or borrow one of these, they are only shortcuts</p>
      <?php endif; ?>
    <?php elseif ($free && !$presets): ?>
      <input type="text" class="val" data-key="<?= h($key) ?>" value="<?= h($val) ?>"
             placeholder="type it" autocomplete="off">
    <?php endif; ?>

    <?php if ($presets): ?>
      <div class="chips">
        <?php foreach ($presets as $p): $pv = (string)($p['value'] ?? '');
              $gated = $gate && (!empty($p['requires_affirmation'])
                                 || xeric_rating_rank($pv) > xeric_rating_rank($floor)); ?>
        <button type="button" class="chip" data-value="<?= h($pv) ?>"
                <?= $gated ? 'data-gated="1" ' : '' ?>
                aria-pressed="<?= $pv !== '' && $pv === $val ? 'true' : 'false' ?>">
          <?= h($p['label'] ?? $pv) ?>
          <?php if (!empty($p['hint'])): ?><span class="ch"><?= h($p['hint']) ?></span><?php endif; ?>
        </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($box): ?>
      <!-- Hidden until somebody reaches past the weakest rating, and shown from
           the start only to a session that already affirmed — so it can be taken
           back, which is the half of a consent control people forget to build. -->
      <label class="note" id="affirm" style="display:flex;gap:.6rem;align-items:flex-start"<?= $adult ? '' : ' hidden' ?>>
        <input type="checkbox" id="affirm-box" style="margin-top:.25rem;flex:0 0 auto"<?= $adult ? ' checked' : '' ?>>
        <span><?= h($affirmLabel) ?></span>
      </label>
      <p class="hintline" id="affirm-note"<?= $adult ? '' : ' hidden' ?>>Nobody is asked how old they are and
        there is nowhere here to say, a stored age is a list of people who said they were children, and
        this demo will not keep one. What is kept is this box, on this browser, until the session is
        swept. Untick it and the xeric goes back to being clean.</p>
    <?php endif; ?>

    <?php if ($free && !$long && $presets): ?>
      <p class="chips-label">or say it your own way</p>
      <input type="text" class="val" data-key="<?= h($key) ?>" value="<?= h($val) ?>"
             placeholder="anything you like" autocomplete="off">
    <?php elseif (!$free): ?>
      <input type="hidden" class="val" data-key="<?= h($key) ?>" value="<?= h($val) ?>">
    <?php endif; ?>

    <p class="hintline drew" hidden></p>

    <div class="escapes">
      <?php if (!$gate): ?>
        <?= xeric_web_rating_box($ratingPresets, $ratingFloor, $ratingNow, $adult, (string)$i, 'Surprise me', $ratingAffirm) ?>
      <?php endif; ?>
      <button class="linkbtn" type="button" data-all="1">✨ Surprise me for everything else and build it</button>
    </div>
  </section>
  <?php endforeach; ?>

  <!-- ------------------------------------------------------------ build -->
  <section class="screen" data-screen="build">
    <!-- THE HEADING IS TWO NODES, and it has to be: five places read or set the
         title as a build moves through its states, and textContent replaces
         every child — so a spinner living directly in the h1 would be wiped by
         the first "Waiting for the model", and the comparison on line ~991 would
         never match again. The text gets its own span. -->
    <h1 id="build-h"><span class="spinner" id="build-spin" aria-hidden="true"></span><span
      id="build-t">Building your xeric</span></h1>
    <p class="sub" id="build-sub"><span id="build-why">This is the expensive part and it only needs to happen during xeric creation.</span> <span class="elapsed" id="elapsed">0:00</span></p>
    <ul class="passes" id="passes"></ul>
    <div class="log" id="log" aria-live="polite"></div>
    <div id="build-err"></div>
  </section>

  <!-- ----------------------------------------------------------- result -->
  <section class="screen" data-screen="done"><div id="result"></div></section>

  <footer>
    A xeric is a living place that runs on your own machine. Nothing here is uploaded anywhere;
    the one you make is a file on this server, and it is yours.
  </footer>
</main>

<div class="bar" id="bar"><div class="inner">
  <button class="btn ghost" id="back" type="button" aria-label="back" hidden>←</button>
  <button class="btn spark" id="spark" type="button" aria-label="surprise me" hidden>✨</button>
  <button class="btn wide" id="next" type="button">Begin</button>
</div></div>

<script>
(function () {
  'use strict';
  var LOCAL_UP = <?= $localUp ? 'true' : 'false' ?>;
  var PANEL = false;   // EXPERIMENTAL discussion mode: a problem, not a place
  var PASSES = [
    ['prep',     'getting your answers straight'],
    ['concept',  'the premise: what this place is'],
    ['places',   'the rooms it happens in'],
    ['cast',     'the people who live there'],
    ['systems',  'what the xeric runs on'],
    ['validate', 'checking it all holds together'],
    ['seed',     'what already happened']
  ];

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var screens = $$('.screen');
  var qScreens = $$('.screen[data-screen=q]');
  var at = 0;

  // SCREENS ARE FOUND BY NAME, NOT COUNTED FROM THE END. This used to say
  // `screens.length - 2` for the build and `- 1` for the result, which is a
  // correct answer that stops being correct the moment a screen is added
  // anywhere — and the way it fails is silent: the build opens the last
  // question instead of the progress list.
  function idxOf(name) {
    for (var i = 0; i < screens.length; i++) if (screens[i].dataset.screen === name) return i;
    return -1;
  }
  var bar = $('#bar'), next = $('#next'), back = $('#back'), spark = $('#spark');
  var rail = $('#rail'), count = $('#count');


  // -- rail ----------------------------------------------------------------
  qScreens.forEach(function () { var i = document.createElement('i'); rail.appendChild(i); });

  function paintRail() {
    // BEFORE, DURING, OR AFTER. A screen that is not a question is one of two
    // things — a way in (intro, premise) or a way out (build, done) — and the
    // rail has to say which. Reading `at === 0` said "before" for exactly one
    // screen and "after" for every other non-question, which lit the whole rail
    // the moment somebody chose to write their own premise.
    var st = screens[at].dataset.screen;
    var qi = st === 'q' ? qScreens.indexOf(screens[at])
           : (st === 'intro' || st === 'premise' || st === 'blank' ? -1 : qScreens.length);
    $$('i', rail).forEach(function (el, i) {
      el.className = i < qi ? 'on' : (i === qi ? 'now' : '');
    });
    count.textContent = qi >= 0 && qi < qScreens.length ? (qi + 1) + ' / ' + qScreens.length : '';
  }

  function show(i) {
    at = i;
    screens.forEach(function (s, n) { s.classList.toggle('live', n === i); });
    var s = screens[i], t = s.dataset.screen;
    // THE CHOOSER HAS NO NEXT. Three boxes are the choice; a "Begin" button
    // underneath them would be a fourth thing to click and would quietly make
    // one of the three the default.
    bar.hidden = (t === 'build' || t === 'done' || t === 'intro');
    back.hidden = !(t === 'q' || t === 'premise' || t === 'blank');
    spark.hidden = !(t === 'q' && s.dataset.spark === '1');
    if (t === 'blank') next.textContent = 'Start';
    else if (t === 'premise') next.textContent = 'Build my xeric';
    else if (t === 'q') paintNext();
    paintRail();
    window.scrollTo(0, 0);
    var f = s.querySelector('textarea.val, input[type=text].val');
    if (f && (t === 'q' || t === 'premise' || t === 'blank') && window.innerWidth > 720) f.focus();
  }

  function paintNext() {
    var s = screens[at];
    if (s.dataset.screen !== 'q') return;
    var v = valueOf(s);
    var last = qScreens.indexOf(s) === qScreens.length - 1;
    next.textContent = v ? (last ? 'Build my xeric' : 'Next') : (last ? 'Skip it and build' : 'Skip this one');
  }

  function valueOf(s) { var el = $('.val', s); return el ? el.value.trim() : ''; }

  function collect() {
    var a = {};
    qScreens.forEach(function (s) { var v = valueOf(s); if (v) a[s.dataset.key] = v; });
    // Sent whenever it has been written, whichever way in was taken. Somebody
    // who describes a place and THEN answers questions about themselves has
    // given the forge both, and dropping half of that because of which box they
    // clicked first would be the page second-guessing them.
    var p = $('.screen[data-screen=premise] .val');
    if (p && p.value.trim()) a.premise = p.value.trim();
    return a;
  }

  // -- which machine forges --------------------------------------------------
  // AN INDEX, NOT AN ENDPOINT. build.php resolves it against this visitor's own
  // stored list, so the worst a tampered page can do is pick a different machine
  // of its owner's — which is exactly what the buttons do anyway.
  var chosenAt = <?= (int)$chosen ?>;

  function chosenCard() { return document.querySelector('.forgeat .opt[data-i="' + chosenAt + '"]'); }

  function pickMachine(i) {
    chosenAt = parseInt(i, 10);
    $$('.forgeat .opt').forEach(function (o) {
      var on = parseInt(o.dataset.i, 10) === chosenAt;
      o.classList.toggle('on', on);
      o.querySelector('.mpick').setAttribute('aria-pressed', on ? 'true' : 'false');
      var w = o.querySelector('.wired');
      w.classList.toggle('on', on);
      w.classList.toggle('no', !on);
      w.lastChild.textContent = on ? 'Forging engine' : 'Not selected';
    });
    paintKey();
  }

  // The key field belongs to the CHOICE, not to the page. It used to be printed
  // only when the ATTACHED machine happened to be remote, which meant it was
  // missing in exactly the case somebody picks the API they added for this.
  function paintKey() {
    var c = chosenCard();
    $('#byo').classList.toggle('on', !!c && c.dataset.kind !== 'local');
  }

  $$('.forgeat .mpick').forEach(function (b) {
    b.addEventListener('click', function () { pickMachine(b.closest('.opt').dataset.i); });
  });
  paintKey();

  // -- are they answering ----------------------------------------------------
  // model.php's probe, called from here. One implementation of "is this machine
  // up, and what is it" asked by both screens — a second copy on this page is
  // how the two would come to disagree about the same address.
  function probeCard(i) {
    var dot  = document.querySelector('.forgeat .status > .dot[data-i="' + i + '"]'),
        said = document.querySelector('.forgeat .said[data-i="' + i + '"]'),
        who  = document.querySelector('.forgeat .whois[data-i="' + i + '"]');
    if (!dot) return;
    fetch('model.php?a=probe&i=' + encodeURIComponent(i))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.who && d.who.name && who) {
          who.querySelector('.wname').textContent = d.who.name;
          who.querySelector('.wmodel').textContent = d.who.model || '';
          who.querySelector('.wsig').className = 'wsig ' + (d.who.local ? 'here' : 'away');
          who.hidden = false;
        }
        if (d.up === null) return;                       // remote: nothing honest to say
        dot.classList.add(d.up ? 'up' : 'down');
        if (said) said.textContent = d.up ? (said.dataset.up || 'Available') : 'no answer';
      },
      // Two arguments rather than a chained .catch(), so a bug in the painting
      // above cannot disguise itself as a machine that did not answer.
      function () {
        dot.classList.add('down');
        if (said) said.textContent = 'no answer';
      });
  }
  $$('.forgeat .dot[data-i]').forEach(function (el) { probeCard(el.dataset.i); });

  function modelChoice() {
    var k = $('#m-key');
    return { i: chosenAt, key: k ? k.value : '' };
  }

  function save() {
    var m = modelChoice();
    delete m.key;                                     // the key never goes to the server except to build with
    fetch('forge.php?a=save', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ answers: collect(), model: m }) }).catch(function () {});
  }

  // -- the affirmation -----------------------------------------------------
  // ATTACHED TO THE CONTENT CHOICE AND TO NOTHING ELSE. A gated preset is not
  // chosen by pressing it: the press only ASKS, and the box is the choice. That
  // is what keeps every unattended path — skipping this step, ✨, closing the
  // tab on it — landing on the weakest rating with nobody having decided.
  var ADULT = <?= $adult ? 'true' : 'false' ?>;
  var affirm = $('#affirm'), affirmBox = $('#affirm-box'), affirmNote = $('#affirm-note');
  // The gated screen is the one the box is ON, not a step key spelled out here
  // a second time — which step is gated is the interview's to say.
  var gateScreen = affirm ? affirm.closest('.screen') : null;
  var pending = '';

  /** The chip for this value, if reaching it needs the affirmation. */
  function gatedChip(v) {
    if (!gateScreen || !v) return null;
    var hit = null;
    $$('.chip', gateScreen).forEach(function (c) {
      if (c.dataset.gated === '1' && c.dataset.value === v) hit = c;
    });
    return hit;
  }

  function askAffirm(value) {
    pending = value;
    if (!affirm) return;
    affirm.hidden = false;
    affirmNote.hidden = false;
    affirmBox.focus();
  }

  // Put the screen where the affirmation says it should be. Granted, the choice
  // they reached for takes; withdrawn, a choice that needed it goes back to
  // nothing — and an unanswered rating is the weakest one, which is exactly
  // where the default path lands.
  function applyAffirm() {
    var el = gateScreen ? $('.val', gateScreen) : null;
    if (!el) { pending = ''; return; }
    if (ADULT) { if (pending) el.value = pending; }
    else if (gatedChip(el.value.trim())) el.value = '';
    pending = '';
    $$('.chip', gateScreen).forEach(function (c) {
      c.setAttribute('aria-pressed',
        el.value.trim() !== '' && c.dataset.value === el.value.trim() ? 'true' : 'false');
    });
    // The pills carry the same gate, so they hold and release with it — and the
    // value has already been cleared above if it needed the affirmation, which
    // lands on the weakest rating, where every unattended path lands.
    paintSurpriseRating();
    paintNext(); save();
  }

  if (affirmBox) affirmBox.addEventListener('change', function () {
    var want = affirmBox.checked;
    affirmBox.disabled = true;
    fetch('forge.php?a=affirm', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ adult: want }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        affirmBox.disabled = false;
        // THE SERVER'S ANSWER IS THE TRUTH, NOT THE BOX'S. The session is what
        // the forge reads, and a box left ticked over a refused or unreachable
        // write is a page telling somebody they have something they do not.
        ADULT = !!(d && d.ok && d.adult === true);
        affirmBox.checked = ADULT;
        applyAffirm();
      })
      .catch(function () {
        affirmBox.disabled = false;
        ADULT = false;
        affirmBox.checked = false;
        affirmNote.textContent = 'That could not be saved, you are probably offline. This xeric stays clean.';
        applyAffirm();
      });
  });

  // -- chips + inputs ------------------------------------------------------
  $$('.chip').forEach(function (c) {
    c.addEventListener('click', function () {
      var s = c.closest('.screen'), el = $('.val', s);
      if (c.dataset.gated === '1' && !ADULT) { askAffirm(c.dataset.value); return; }
      var on = c.getAttribute('aria-pressed') === 'true';
      $$('.chip', s).forEach(function (o) { o.setAttribute('aria-pressed', 'false'); });
      if (!on) c.setAttribute('aria-pressed', 'true');
      if (el) el.value = on ? '' : c.dataset.value;
      $('.drew', s).hidden = true;
      paintNext(); save();
    });
  });

  $$('.val').forEach(function (el) {
    el.addEventListener('input', function () {
      var s = el.closest('.screen');
      $$('.chip', s).forEach(function (c) {
        c.setAttribute('aria-pressed', c.dataset.value === el.value.trim() ? 'true' : 'false');
      });
      paintNext();
    });
    el.addEventListener('change', save);
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && el.tagName !== 'TEXTAREA') { e.preventDefault(); next.click(); }
    });
  });

  // -- navigation ----------------------------------------------------------
  next.addEventListener('click', function () {
    var t = screens[at].dataset.screen;
    if (t === 'blank') { startBlank(); return; }
    // The third door gets the same lock as the other two. A typed premise used
    // to build with `rating` unanswered — a GAP, which the premise reader or
    // the concept table filled, mature four times in five for an affirmed
    // session. Same bug as ✨ and Auto Generate, found last.
    if (t === 'premise') { lockRating(); save(); build('presets'); return; }
    if (t === 'q') {
      save();
      if (qScreens.indexOf(screens[at]) === qScreens.length - 1) { build('presets'); return; }
      show(at + 1);
    }
  });
  back.addEventListener('click', function () {
    // Back from the first question is back to the CHOOSER, not to whatever
    // section happens to sit above it in the source — which is the premise
    // panel, a screen the wizard path never went through.
    var st0 = screens[at].dataset.screen;
    if (st0 === 'premise' || st0 === 'blank' || qScreens.indexOf(screens[at]) === 0) {
      show(0);
      return;
    }
    if (at > 0) show(at - 1);
  });

  // -- ✨ one question -----------------------------------------------------
  spark.addEventListener('click', function () {
    var s = screens[at], el = $('.val', s);
    spark.disabled = true;
    fetch('forge.php?a=surprise', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: s.dataset.key, answers: collect() }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        spark.disabled = false;
        var drew = $('.drew', s);
        // ✨ failing silently was the wizard's one dishonest state: the button
        // greyed, came back, and nothing happened, which reads as a dead button.
        if (!d.ok || !d.value) {
          drew.textContent = '✨ could not draw one just now, type anything, or press Next and let the '
            + 'build fill this in.';
          drew.hidden = false;
          return;
        }
        if (el) el.value = d.value;
        $$('.chip', s).forEach(function (c) {
          c.setAttribute('aria-pressed', c.dataset.value === d.value ? 'true' : 'false');
        });
        if (d.concept) { drew.textContent = 'drawn from: ' + d.concept; drew.hidden = false; }
        paintNext(); save();
      })
      .catch(function () {
        spark.disabled = false;
        var drew = $('.drew', s);
        drew.textContent = '✨ could not be reached, you are probably offline. Everything you have typed '
          + 'is still here.';
        drew.hidden = false;
      });
  });

  // -- the three ways in ---------------------------------------------------
  // Auto Generate and the ✨ escape hatch on the question screens are the SAME
  // build — fill:'model' — reached from two places, so they call the same line
  // rather than two lines that will drift.
  $$('[data-way]').forEach(function (b) {
    b.addEventListener('click', function () {
      var w = b.dataset.way;
      // A build that needs a key and has not been given one fails a minute in,
      // after the queue and the first model call, with an error about
      // authorisation. The field is right there on this screen; ask for it now.
      if (w !== 'wizard' && w !== 'blank' && !keyReady()) return;   // blank calls no model
      // Auto Generate answers NOTHING, so the rating row under the tiles is the
      // only thing standing between it and a gap the fill routes would fill.
      // Untouched it reads the weakest rating, and this writes it.
      if (w === 'auto') { lockRating(); save(); build('model'); return; }
      if (w === 'blank') { show(idxOf('blank')); return; }
      // SOLVE A PROBLEM lands on the same premise screen and then LIGHTS ITS OWN
      // TAB, rather than repeating what that tab does. Everything the panel
      // needs — PANEL = true, the heading, the sub, the experimental line, the
      // placeholder — is already written once, in the tab's handler; a second
      // copy here is a second copy to forget to change. Show first, because the
      // handler ends by focusing the box and a hidden box cannot take focus.
      if (w === 'panel') {
        show(idxOf('premise'));
        var pt = document.querySelector('.w3[data-mode="panel"]');
        if (pt) pt.click();
        return;
      }
      // AND THE WAY BACK OUT. Both doors land on the same premise screen, so
      // "Your Own Idea" pressed after "Solve a Problem" would otherwise open a
      // screen still asking "What is the problem?" with the panel tab lit — the
      // right box, the wrong question, and a build that quietly makes a
      // discussion room out of somebody describing a town. Lighting `type it`
      // undoes it through the same handler that did it, so the heading, the
      // sub, the hint, the placeholder and PANEL all come back together.
      if (w === 'own') {
        show(idxOf('premise'));
        if (PANEL) {
            var tt = document.querySelector('.w3[data-mode="type"]');
            if (tt) tt.click();
        }
        return;
      }
      show(idxOf('q'));
    });
  });

  // NO BUILD SCREEN FOR THIS ONE. There is nothing to watch: no queue, no passes,
  // no model. It is done before a progress list could finish drawing, so the
  // button says it is working and the next screen is the answer.
  function startBlank() {
    if (next.disabled) return;
    next.disabled = true;
    var was = next.textContent;
    next.textContent = 'starting…';
    var f = $('#blankname'), g = $('#blankwho');
    fetch('forge.php?a=blank', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: f ? f.value : '', who: g ? g.value : '' }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.url) { window.location.href = d.url; return; }
        next.textContent = was; next.disabled = false;
        openBuildScreen(); fail((d && d.error) || 'that did not start', false);
      },
      function (e) {
        next.textContent = was; next.disabled = false;
        openBuildScreen(); fail('the forge could not be reached, ' + e.message, false);
      });
  }

  // The one thing this screen can check before spending three minutes on a
  // build. Returns true when there is nothing to check, which is every local
  // install — the case the whole project is for.
  // -- how you want to describe it -----------------------------------------
  // "ask me" is the wizard, which is still the same nine screens: this only
  // decides which door somebody walks through to reach them.
  $$('.w3').forEach(function (t) {
    t.addEventListener('click', function () {
      var mode = t.dataset.mode;
      if (mode === 'ask') { save(); show(idxOf('q')); return; }

      $$('.w3').forEach(function (o) {
        var on = o === t;
        o.classList.toggle('on', on);
        o.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      var drop = $('#pdfdrop');
      if (drop) drop.hidden = mode !== 'pdf';
      // THE TAB IS THE CHOOSER. There is no second button — pressing "a PDF"
      // opens the dialog itself. Cancelling leaves the tab lit with an empty
      // status, and pressing it again simply asks again.
      if (mode === 'pdf' && file) file.click();
      var box = $('.screen[data-screen=premise] .val');
      // Discussion mode is the same box holding a different kind of writing, so
      // the copy around it changes and the box does not. What you type here is
      // a PROBLEM, and saying so in the placeholder is worth more than any
      // amount of explanation underneath it.
      var head = $('.screen[data-screen=premise] h1');
      var sub  = $('.screen[data-screen=premise] .sub');
      var hint = $('.screen[data-screen=premise] .hintline');
      PANEL = (mode === 'panel');
      if (head) head.textContent = PANEL
        ? 'What is the problem?'
        : 'Tell it what you have in mind.';
      if (sub) sub.textContent = PANEL
        ? 'The forge builds a room to hold it and three to five people who will not agree about it. '
          + 'You can watch, or walk in and say something. Consensus is not the goal — if the room '
          + 'cannot get there, it tells you which two positions could never both be satisfied, and '
          + 'that is usually the more useful answer.'
        : 'A paragraph is plenty. Where you are, when it is, who is around, what the place is like. '
          + 'Anything you leave out gets invented to fit what you wrote.';
      if (hint) hint.textContent = PANEL
        ? 'EXPERIMENTAL. These are characters arguing, not experts consulting — treat what comes out '
          + 'as a way of seeing the disagreement, never as advice.'
        : 'Names, dates and facts you put here are kept. The forge does not overrule them.';
      if (box) {
        box.placeholder = PANEL
          ? 'We have to cut twenty percent of the budget and every department says theirs is the one that cannot take it…'
          : box.dataset.ph || box.placeholder;
        if (mode === 'type' || PANEL) box.focus();
      }
    });
  });

  // -- a PDF -----------------------------------------------------------------
  // The text lands in the box above, editable, because a document is a thing
  // somebody wrote and that is what the box is for. Nobody hands over forty
  // pages and means all forty.
  var file = $('#pdffile'), pst = $('#pdfst');
  if (file) {
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) return;
      pst.textContent = 'reading ' + f.name + '…';
      var fd = new FormData();
      fd.append('pdf', f);
      fetch('forge.php?a=pdf', { method: 'POST', body: fd })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok || !res.d.text) {
            pst.textContent = (res.d && res.d.error) || 'nothing could be read out of that';
            return;
          }
          var box = $('.screen[data-screen=premise] .val');
          box.value = res.d.text;
          pst.textContent = res.d.words + ' words from ' + f.name + ', edit it however you like';
          box.focus();
          save();
        },
        function (e) { pst.textContent = 'that could not be sent, ' + e.message; });
    });
  }

  function keyReady() {
    var c = chosenCard();
    if (!c || c.dataset.kind === 'local') return true;      // your own machine wants no key
    var k = $('#m-key');
    if (!k || k.value.trim() !== '') return true;
    k.closest('.byo').classList.add('want');
    k.scrollIntoView({ block: 'center', behavior: 'smooth' });
    k.focus();
    return false;
  }

  // -- ✨ everything else --------------------------------------------------
  // ONE VALUE, HOWEVER MANY COPIES OF THE CONTROL. Each pill writes to the rating
  // question's own field — the same one the chips write — so there is never a
  // second answer to reconcile, and the affirmation logic above keeps working.
  var RATING_DEFAULT = <?= json_encode(xeric_ratings()[0]) ?>;

  function ratingField() { return gateScreen ? $('.val', gateScreen) : null; }
  function ratingValue() {
    var el = ratingField();
    return el && el.value.trim() ? el.value.trim() : RATING_DEFAULT;
  }

  /** Every control on the page shows what the one field actually says. */
  function paintSurpriseRating() {
    var v = ratingValue();
    $$('.rateset').forEach(function (set) {
      var chosen = null;
      $$('.rateopt', set).forEach(function (o) {
        var on = o.dataset.value === v;
        o.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (on) chosen = o;
        // A GATED OPTION IS NEVER HIDDEN, only held. Hiding it would leave a
        // visitor unable to see that there is anything to unlock, which is how
        // the row-of-radios version dead-ended on a screen with no gate on it.
        var held = o.dataset.gated === '1' && !ADULT;
        o.classList.toggle('held', held);
        o.setAttribute('aria-disabled', held ? 'true' : 'false');
      });
      var out = $('.rp-v', set);
      if (out && chosen) out.textContent = $('.ro-l', chosen).textContent;
      var box = $('.rate-affirm', set);
      if (box) box.checked = ADULT;
      var lab = box ? box.closest('.rateaff') : null;
      if (lab) lab.classList.toggle('ask', !ADULT);
    });
  }

  function closeRatePops(except) {
    $$('.rateset').forEach(function (set) {
      if (set === except) return;
      var pop = $('.ratepop', set), pill = $('.ratepill', set);
      if (pop) pop.hidden = true;
      if (pill) pill.setAttribute('aria-expanded', 'false');
    });
  }

  $$('.ratepill').forEach(function (pill) {
    pill.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var set = pill.closest('.rateset'), pop = $('.ratepop', set);
      var open = pop.hidden;
      closeRatePops(set);
      pop.hidden = !open;
      pill.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  $$('.rateopt').forEach(function (o) {
    o.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var set = o.closest('.rateset');
      // HELD, NOT REFUSED. The box that would release it is in this same panel,
      // so point at it rather than saying no — the whole reason this control
      // exists is that the affirmation used to live on a screen you could not
      // get to from here.
      if (o.dataset.gated === '1' && !ADULT) {
        var lab = $('.rateaff', set);
        if (lab) {
          lab.classList.add('want');
          var b = $('.rate-affirm', lab);
          if (b) b.focus();
          setTimeout(function () { lab.classList.remove('want'); }, 1200);
        }
        return;
      }
      var el = ratingField();
      if (el) el.value = o.dataset.value;
      // The chips on the gated screen are the same choice seen from the other
      // side; leaving them stale would show two answers to one question.
      if (gateScreen) {
        $$('.chip', gateScreen).forEach(function (c) {
          c.setAttribute('aria-pressed', c.dataset.value === o.dataset.value ? 'true' : 'false');
        });
      }
      paintSurpriseRating();
      paintNext(); save();
      var pop = $('.ratepop', set), pill = $('.ratepill', set);
      pop.hidden = true;
      pill.setAttribute('aria-expanded', 'false');
      pill.focus();
    });
  });

  // THE SAME ENDPOINT AND THE SAME RULE AS THE BOX ON THE RATING SCREEN: the
  // server's answer is the truth, not the checkbox's. A tick left standing over a
  // refused write is a page telling somebody they have something they do not.
  $$('.rate-affirm').forEach(function (box) {
    box.addEventListener('change', function (ev) {
      ev.stopPropagation();
      var want = box.checked;
      $$('.rate-affirm').forEach(function (b) { b.disabled = true; });
      fetch('forge.php?a=affirm', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ adult: want }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          ADULT = !!(d && d.ok && d.adult === true);
          if (affirmBox) affirmBox.checked = ADULT;     // the one on the rating screen agrees
          if (affirm) { affirm.hidden = !ADULT; }
          if (affirmNote) { affirmNote.hidden = !ADULT; }
          // Withdrawn, a rating that needed it goes back to nothing — which is
          // the weakest rating, where every unattended path already lands.
          var el = ratingField();
          if (!ADULT && el && gatedValue(el.value.trim())) el.value = '';
          $$('.rate-affirm').forEach(function (b) { b.disabled = false; });
          paintSurpriseRating(); paintNext(); save();
        })
        .catch(function () {
          $$('.rate-affirm').forEach(function (b) { b.disabled = false; b.checked = ADULT; });
        });
    });
  });

  /** Does reaching this value need the affirmation? Asked of the markup, not a list here. */
  function gatedValue(v) {
    if (!v) return false;
    var hit = false;
    $$('.rateopt').forEach(function (o) {
      if (o.dataset.value === v && o.dataset.gated === '1') hit = true;
    });
    return hit;
  }

  document.addEventListener('click', function () { closeRatePops(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeRatePops(null); });
  $$('.ratepop').forEach(function (p) { p.addEventListener('click', function (e) { e.stopPropagation(); }); });

  paintSurpriseRating();

  // NEVER LEAVE IT A GAP. Untouched, the boxes read the weakest rating and this
  // writes it, so an unattended build carries a real answer rather than an
  // absence for the fill routes to argue over. Called by both ways in.
  function lockRating() {
    var el = ratingField();
    if (el && el.value.trim() === '') el.value = RATING_DEFAULT;
  }

  $$('[data-all]').forEach(function (b) {
    b.addEventListener('click', function () { lockRating(); save(); build('model'); });
  });

  // -- the build -----------------------------------------------------------
  var passEls = {}, started = 0, tick = null;

  function paintPasses() {
    var ul = $('#passes');
    ul.innerHTML = '';
    passEls = {};
    PASSES.forEach(function (p) {
      var li = document.createElement('li');
      li.className = 'idle';
      li.innerHTML = '<span class="pd"></span><span><span class="pt"></span><br><span class="ps"></span></span>';
      $('.pt', li).textContent = p[1];
      ul.appendChild(li);
      passEls[p[0]] = li;
    });
  }

  function markPass(key, text, warn) {
    if (!passEls[key]) return;              // an unknown pass must never march the list forward
    var seen = false;
    PASSES.forEach(function (p) {
      var li = passEls[p[0]];
      if (p[0] === key) {
        seen = true;
        li.className = warn ? 'run warn' : 'run';
        $('.ps', li).textContent = text || '';
      } else if (!seen && li.className.indexOf('warn') < 0) {
        li.className = 'done';              // everything above the live pass has landed
      }
    });
  }

  function logline(text, warn) {
    var el = $('#log'), d = document.createElement('div');
    if (warn) d.className = 'w';
    d.textContent = text;
    el.appendChild(d);
    el.scrollTop = el.scrollHeight;
  }

  function clock() {
    var s = Math.floor((Date.now() - started) / 1000);
    $('#elapsed').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
  }

  function fail(msg, offerKey) {
    if (tick) clearInterval(tick);
    var sp = $('#build-spin'); if (sp) sp.hidden = true;   // nothing is turning any more
    $('#build-t').textContent = 'That did not work';
    $('#build-sub').textContent = '';
    var box = $('#build-err');
    box.innerHTML = '';
    var p = document.createElement('p');
    p.className = 'note bad';
    p.textContent = msg;
    box.appendChild(p);
    var b = document.createElement('button');
    b.className = 'btn ghost';
    b.type = 'button';
    b.textContent = offerKey ? 'Use my own key instead' : 'Back to the start';
    b.addEventListener('click', function () { if (offerKey) pickKind('byo'); show(0); });
    box.appendChild(b);
    bar.hidden = true;
  }

  // A build outlives the request that asked for it (worker.php), so starting one
  // and watching one are two different things: POST to start, then follow the
  // job with an EventSource that is allowed to be interrupted and come back.
  function build(fill) {
    openBuildScreen();
    next.disabled = true;
    fetch('build.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ answers: collect(), model: modelChoice(), fill: fill, panel: PANEL }) })
      .then(function (res) {
        return res.json().then(function (d) {
          next.disabled = false;
          if (!res.ok || !d.job) { fail(d.error || ('the forge answered ' + res.status), d.kind === 'model_down'); return; }
          logline('forging with ' + (d.endpoint || 'the model'));
          follow(d.job);
        });
      })
      .catch(function (e) {
        next.disabled = false;
        fail('the forge could not be reached, ' + e.message, false);
      });
  }

  function openBuildScreen() {
    show(idxOf('build'));
    var sp = $('#build-spin'); if (sp) sp.hidden = false;
    paintPasses();
    $('#log').innerHTML = '';
    $('#build-err').innerHTML = '';
    $('#build-t').textContent = 'Building your xeric';
    started = Date.now();
    if (tick) clearInterval(tick);
    tick = setInterval(clock, 1000);
    clock();
  }

  var es = null, drops = 0;

  function follow(job) {
    if (es) es.close();
    es = new EventSource('progress.php?job=' + encodeURIComponent(job));

    // The meter, live. The build is spending tokens the whole time it runs and
    // the page does not reload while it watches, so without this the number a
    // person is staring at is whatever it was when they clicked.
    es.addEventListener('meter', function (m) {
      try {
        var d = JSON.parse(m.data);
        if (window.xericMeterFeed) window.xericMeterFeed(d.by || {});
      } catch (e) {}
    });

    es.addEventListener('hello', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      markPass('prep', 'reading your answers');
      logline('forging with ' + d.endpoint);
    });

    // One GPU, one thing at a time. A wait with a position in it is a state; a
    // wait without one is a hang, and the visitor is right to close the tab.
    es.addEventListener('queue', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      $('#build-t').textContent = 'Waiting for the model';
      $('#build-why').textContent = d.text + ', your build starts by itself when it is your turn.';
      markPass('prep', d.text);
      logline(d.text);
    });

    es.addEventListener('note', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      // The build's clock is the worker's, not the browser's: a reload halfway
      // through must not restart the timer at zero.
      if (d.t > 0) started = Date.now() - d.t * 1000;
      if ($('#build-t').textContent !== 'Building your xeric') {
        $('#build-t').textContent = 'Building your xeric';
        $('#build-why').textContent = 'This is the expensive part and it only needs to happen during xeric creation.';
      }
      markPass(d.pass, d.text, d.level === 'warn');
      logline('[' + Number(d.t).toFixed(1) + 's] ' + d.text, d.level === 'warn');
    });

    es.addEventListener('done', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      es.close();
      if (tick) clearInterval(tick);
      PASSES.forEach(function (p) {
        var li = passEls[p[0]];
        if (li.className.indexOf('warn') < 0) li.className = 'done';
      });
      $('#result').innerHTML = d.html;
      // innerHTML never runs scripts, and the fragment wires its own back
      // cover and repass button — so its scripts are run by hand, exactly
      // once, here.
      $$('#result script').forEach(function (s) {
        try { (new Function(s.textContent))(); } catch (e) { /* a dead wire, not a dead page */ }
      });
      show(idxOf('done'));
      history.replaceState(null, '', 'forge.php?w=' + encodeURIComponent(d.slug));
    });

    es.addEventListener('failed', function (m) {
      var d = JSON.parse(m.data);
      es.close();
      fail(d.message, !!d.offer_key);
    });

    // The stream hangs up every 40s on purpose — anything in front of this app
    // would cut it at two minutes anyway. EventSource comes straight back with
    // Last-Event-ID; only a run of failures is really a failure.
    es.addEventListener('pause', function () { drops = 0; });
    es.onerror = function () {
      if (es.readyState === 2 || ++drops > 40) {
        es.close();
        fail('the connection to the forge keeps dropping. The build may still be running, '
             + 'reload this page to pick it back up.', false);
      }
    };
  }

  show(0);
  paintRail();

  // A build already running for this browser (a reload, a locked phone) is
  // rejoined rather than restarted — it was never in the page to begin with.
  var RESUME = <?= json_encode($resume) ?>;
  if (RESUME) { openBuildScreen(); logline('rejoining the build already running…'); follow(RESUME); }
})();
</script>
</html>
