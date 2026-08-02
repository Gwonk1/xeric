<?php
/**
 * why.php — the inspector. What a character is actually told, and why the world
 * did what it did.
 *
 * This page exists for exactly one person: whoever is tuning a world. Everything
 * else in this app is written so a stranger can enjoy it without knowing how it
 * works; this one is written so the author can find out why it did not.
 *
 * THREE QUESTIONS, AND NOTHING ELSE:
 *
 *  1. WHAT IS SHE ACTUALLY BEING TOLD?  ?w=<slug>&h=<handle>
 *     The EXACT messages xeric_prompt_build() produces — not a description of
 *     them, not a re-derivation, the real array — split into the sections
 *     prompt.php assembles them from, each with its size. The size is the point:
 *     "her voice is 300 characters and the bible is 4,000" is the whole
 *     explanation for why she sounds like a narrator, and it is invisible
 *     anywhere else in this product.
 *
 *     The sections are rebuilt by calling the SAME functions prompt.php calls,
 *     and then checked against the real system message byte for byte. When they
 *     do not reconstruct it, this page says so loudly and shows the real one
 *     whole — an inspector that quietly shows you something adjacent to the
 *     truth is worse than no inspector.
 *
 *  2. WHY DID THAT HAPPEN?  ?w=<slug>&e=<event id>
 *     The sweep's decision trail: which kinds this world could produce, which
 *     were tried, who was standing where, who was kept out and on what grounds,
 *     which grouping won and by how much weight. sweeps.php produces all of that
 *     while it decides; tick-worker.php writes it to world_state keyed by the
 *     event id, so it is still answerable a week later.
 *
 *  3. WHAT HAS IT LEARNED?  ?w=<slug>&learn=1
 *     The learning layer, from the evidence up: the newest signals in plain
 *     language, the per-kind weights with their floors, each person's reach,
 *     the lessons with the evidence that earned them where that is on record,
 *     and anything the distil pass has struck, with its reasons. The prompt
 *     view above shows the lessons by accident — they ride the system message —
 *     but nothing anywhere else shows the numbers underneath them, and a weight
 *     that cannot explain itself reads as the world being moody. learn.php
 *     produces every figure on the page; nothing here re-derives one.
 *
 * Read-only, start to finish. No model call, no write, no clock moved.
 *
 * AND ONLY FOR ITS OWNER. Read-only is not the same as harmless: what this page
 * reads is the assembled system message, and prompt.php puts "You hold this back:
 * <secret>" and "What you are really after: <drives.pull>" into it, along with
 * the psyche, the tells and the solace. Printed verbatim, as they must be — an
 * inspector that shows you something adjacent to the truth is worse than no
 * inspector — so this page cannot be made safe for a stranger by redacting it.
 * It can only be refused to them, which is what review.php and world.php do with
 * the same material and what xeric_play_guard() now says in one place.
 */

declare(strict_types=1);

require_once __DIR__ . '/why-lib.php';

// ---------------------------------------------------------------------------

$slug = xeric_web_slug((string)($_GET['w'] ?? ''));
$handle = trim((string)($_GET['h'] ?? ''));
$eventId = (int)($_GET['e'] ?? 0);

// Before the open, not after: opening a stranger's world forks a copy of its
// database into this session, and a page about to refuse leaves nothing behind.
xeric_play_guard($slug, 'The inspector prints the exact messages the model is handed, which is every '
    . 'character\'s secret, what each of them is really steering toward, and the reasoning behind every '
    . 'hour this xeric has lived. That is the tuning tool for whoever forged it, and it only works by '
    . 'showing the real thing.');

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_head('Xeric: why');
    echo '<style>' . xeric_play_css() . xeric_why_css() . '</style>';
    echo '<main><div class="top"><p class="wordmark">XERIC</p><span class="kicker">why</span></div>';
    echo '<h1>There is nothing to inspect</h1><p class="note bad">' . h($e->getMessage()) . '</p>';
    echo '<p><a href="play.php">The xerics that are here →</a></p></main>';
    exit;
}

$T   = $w['template'];
$db  = $w['db'];
$now = xeric_clock_now($db, $T);

xeric_web_head('Xeric: why · ' . (string)$T['meta']['name']);
echo '<style>' . xeric_play_css() . xeric_why_css() . '</style>';
?>
<main>
  <div class="top">
    <p class="wordmark">XERIC</p>
    <span class="kicker">why</span>
    <?= xeric_web_meter_html() ?>
    <span class="count"><a href="play.php?w=<?= h(rawurlencode($w['slug'])) ?>">back to the xeric</a></span>
  </div>
<?php

// ===========================================================================
// One character's prompt
// ===========================================================================
if ($handle !== '' && xeric_world_character($T, $handle) !== null) {
    $c = xeric_world_character($T, $handle);
    $eff = xeric_world_rating($T, null);
    $limit = 12;

    $messages = xeric_prompt_build($T, $db, $handle, $now, ['memory_limit' => $limit, 'history_limit' => 20]);
    $system = xeric_prompt_system_of($messages);
    $built = xeric_why_system_sections($T, $db, $handle, $eff, $limit, (int)($now['epoch'] ?? 0) ?: null);
    $exact = $built['rebuilt'] === $system;

    $total = 0;
    foreach ($messages as $m) $total += mb_strlen((string)$m['content']);
    $walls = xeric_viewer_walls($T, xeric_viewer($T, ['handle' => $handle]));
    ?>
  <h1><?= h((string)($c['display_name'] ?? $handle)) ?>, from the inside</h1>
  <p class="sub">This is what <?= h((string)($c['display_name'] ?? $handle)) ?> is handed, exactly, the next
    time you say something to her, <code>xeric_prompt_build()</code>, run just now, against
    <?= h((string)$T['meta']['name']) ?> at <?= h(xeric_play_when($now)) ?>.</p>

  <div class="tot">
    <div><b><?= number_format($total) ?></b> characters · <b>≈<?= number_format(xeric_why_tokens($system) + xeric_why_tokens(implode('', array_map(fn($m) => (string)$m['content'], array_slice($messages, 1))))) ?></b> tokens
      <span class="quiet">(chars ÷ 4, an estimate, not a tokenizer)</span></div>
    <div class="quiet"><?= count($messages) ?> messages: one system message,
      <?= max(0, count($messages) - 1) ?> turns. Rating in force: <b><?= h($eff) ?></b>.</div>
  </div>

  <?php if (!$exact): ?>
    <p class="note bad">These sections do not reconstruct the real system message byte for byte, so
      prompt.php has changed and this page has not. The real message is printed whole at the bottom;
      trust that one and not the split.</p>
  <?php endif; ?>

  <h2>the system message, in the order she reads it</h2>
  <p class="quiet">Everything here is static by design: byte-identical between two turns a minute apart,
    so the local model's prefix cache survives. One changing byte near the top throws away everything
    below it.</p>

  <?php $sysLen = mb_strlen($system); foreach ($built['sections'] as $s): $len = mb_strlen($s['text']); ?>
  <div class="blk">
    <div class="blkhead">
      <span class="bn"><?= h($s['name']) ?></span>
      <span class="bs"><?= number_format($len) ?> chars · ≈<?= number_format(xeric_why_tokens($s['text'])) ?> tok</span>
    </div>
    <div class="blkbar"><?= xeric_why_bar($len, $sysLen) ?></div>
    <p class="quiet blknote"><?= h($s['note']) ?></p>
    <pre class="p"><?= h($s['text']) ?></pre>
  </div>
  <?php endforeach; ?>

  <h2>what is being kept from her</h2>
  <?php if ($walls === []): ?>
    <p class="note bad">No wall matches this character, so the bible above contains every other
      character's private interior, their sore spots, what they steer toward, what they hold back.
      That is the failure that makes a cast read as one narrator in four hats.
      <a href="review.php?w=<?= h(rawurlencode($w['slug'])) ?>#sec-walls">Reroll the walls →</a></p>
  <?php else: ?>
    <ul class="sys">
      <?php foreach ($walls as $wall): ?>
      <li><span class="k"><code><?= h((string)($wall['key'] ?? '')) ?></code></span>
        <span class="v"><?= h((string)($wall['explain'] ?? 'no explanation was written for this wall')) ?><br>
          hides: <?= h(implode(', ', (array)($wall['hidden'] ?? []))) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>the conversation, as turns</h2>
  <?php
    $turns = array_slice($messages, 1);
    if ($turns === []): ?>
    <p class="quiet">Nothing yet, the last message below is all she has.</p>
  <?php else:
    foreach ($turns as $i => $m):
      $isLast = $i === count($turns) - 1;
      $txt = (string)$m['content'];
      $vol = '';
      if ($isLast && str_contains($txt, 'RIGHT NOW (')) {
          $cut = mb_strpos($txt, 'RIGHT NOW (');
          $vol = mb_substr($txt, $cut);
          $txt = rtrim(mb_substr($txt, 0, $cut));
      }
  ?>
    <div class="blk<?= $isLast ? ' last' : '' ?>">
      <div class="blkhead">
        <span class="bn"><?= h((string)$m['role']) ?><?= $isLast ? ' · the last thing she reads' : '' ?></span>
        <span class="bs"><?= number_format(mb_strlen((string)$m['content'])) ?> chars</span>
      </div>
      <?php if ($txt !== ''): ?><pre class="p"><?= h($txt) ?></pre><?php endif; ?>
      <?php if ($vol !== ''): ?>
        <p class="quiet blknote">And riding the same message, the ONLY volatile block in the whole prompt, the clock, the room, and who else is in it. It lives here rather than in the system message
          because it changes every single turn, and at the top it would cost a full re-read of the bible
          per message.</p>
        <pre class="p vol"><?= h($vol) ?></pre>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>

  <?php if (!$exact): ?>
  <h2>the real system message, whole</h2>
  <pre class="p"><?= h($system) ?></pre>
  <?php endif; ?>

  <p class="jump"><a href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>">every character and every event →</a>
    · <a href="review.php?w=<?= h(rawurlencode($w['slug'])) ?>#who-<?= h($handle) ?>">edit <?= h((string)($c['display_name'] ?? $handle)) ?></a></p>
<?php

// ===========================================================================
// One event's decision trail
// ===========================================================================
} elseif ($eventId > 0) {
    $ev = null;
    foreach (xeric_events_recent($db, 200) as $row) {
        if ((int)$row['id'] === $eventId) { $ev = $row; break; }
    }
    if ($ev === null) {
        echo '<h1>No such event</h1><p class="note bad">Nothing with that id is among the last 200 things '
            . 'that happened here. Ids are counted per xeric, so a link carried over from another one '
            . 'lands exactly here.</p>';
    } else {
        $raw = xeric_world_state_get($db, 'why:event:' . $eventId);
        $why = $raw !== null ? json_decode($raw, true) : null;
        ?>
  <h1><?= h((string)$ev['title']) ?></h1>
  <p class="sub"><?= h(xeric_play_stamp($T, (int)$ev['world_epoch'])) ?>
    <?php $pn = xeric_world_place_name($T, (string)($ev['place'] ?? '')); if ($pn !== '') echo ' · ' . h($pn); ?>
    · event #<?= (int)$ev['id'] ?></p>
  <p><?= h((string)$ev['prose']) ?></p>

  <h2>what each of them took away from it</h2>
  <?php
    $any = false;
    foreach ((array)$ev['participants'] as $p) {
        $rows = xeric_memories_for($db, (string)$p, 40);
        foreach ($rows as $m) {
            // state.php decodes `meta` on the way out — it is an array here, not
            // the JSON string it is in the column.
            $meta = is_array($m['meta'] ?? null) ? $m['meta'] : (json_decode((string)($m['meta'] ?? ''), true) ?: []);
            if ((int)($meta['event_id'] ?? 0) !== $eventId) continue;
            $any = true;
            echo '<div class="take"><div class="tn">' . h(xeric_world_name($T, (string)$p)) . '</div>'
                . '<div class="tt">' . h((string)$m['text']) . '</div></div>';
        }
    }
    if (!$any) echo '<p class="quiet">Nobody\'s memory of this is stored, it was baked in as seed history '
        . 'rather than lived through a sweep.</p>';
  ?>

  <h2>why this, and why them</h2>
  <?php if (!is_array($why) || $why === []): ?>
    <p class="note warn">No decision trail was kept for this event. Either it is <b>seed history</b>, the
      forge wrote it into the xeric's past, so nothing chose it, or it happened before the trail was
      being recorded. Everything that happens from now on carries one.</p>
  <?php else:
      $tr = (array)($why['trail'] ?? []);
  ?>
    <div class="panel">
      <div class="row"><div class="k">kind</div><div class="v"><code><?= h((string)($why['kind'] ?? '?')) ?></code>
        <?php if (($tr['shape'] ?? '') !== ''): ?><br><span class="quiet"><?= h((string)$tr['shape']) ?></span><?php endif; ?></div></div>
      <div class="row"><div class="k">why them</div><div class="v"><?= h((string)($why['why'] ?? $tr['chose'] ?? '')) ?>
        <?php if (isset($tr['groups'])): ?><br><span class="quiet">chosen out of <?= (int)$tr['groups'] ?>
          plausible groupings, at weight <?= (int)($tr['weight'] ?? 1) ?>, the shapes the xeric thinks are
          likelier get a heavier thumb</span><?php endif; ?></div></div>
      <div class="row"><div class="k">on spine</div><div class="v"><?= !empty($why['on_spine'])
        ? 'yes, this one touches what this xeric is keeping quiet'
        : (!empty($tr['could_spine']) ? 'no, this kind could have been, and the roll said not this time'
                                      : 'no, this kind of hour is never about the secret') ?></div></div>
      <?php if (!empty($tr['protagonist'])): ?>
      <div class="row"><div class="k">protagonist</div><div class="v">the person carrying this xeric was in it, their groups are weighted ×3 on purpose</div></div>
      <?php endif; ?>
      <div class="row"><div class="k">took</div><div class="v"><?= h(number_format(((int)($why['ms'] ?? 0)) / 1000, 1)) ?>s
        <span class="quiet">· <?= (int)($why['attempts'] ?? 1) ?> attempt(s) at making their memories differ</span></div></div>
    </div>

    <?php
    // THE FALSE CALM IS WHY THIS BLOCK EXISTS. A story deliberately drops the
    // world back to about its own pace between the build and the crescendo, and
    // an hour in that stretch looks exactly like a world that has gone slack.
    // The one place somebody tuning a world would go to find out is this page,
    // so the stage is printed in words and the pace multiplier next to it.
    // Nothing here is any part of the answer — engine/sweeps.php builds this
    // block to be printed as it stands.
    $sy = (array)($tr['story'] ?? []);
    if (($sy['live'] ?? []) !== []): ?>
    <h2>the story this hour was paced by</h2>
    <ul class="sys">
      <?php foreach ((array)$sy['live'] as $s): ?>
      <li><span class="k"><?= h((string)($s['title'] ?? $s['key'] ?? '')) ?>, <?= h((string)($s['stage'] ?? '')) ?></span>
        <span class="v"><?= h((string)($s['why'] ?? '')) ?>
          · <?= (int)round(100 * (float)($s['p'] ?? 0)) ?>% through, <?= h((string)($s['beats'] ?? '')) ?>
          · the xeric's own chance ×<?= h(number_format((float)($s['pace'] ?? 1), 2)) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="quiet">A story never arms a system and never adds a kind. All it does is lean on the two
      knobs this xeric already has, how much happens, and what kind of thing it is, which is why a
      <b>false calm</b> reads as an ordinary quiet evening rather than as the xeric having stopped.
      This hour rolled at <?= h(number_format((float)($sy['chance']['rolled'] ?? 0), 3)) ?>
      against this xeric's own <?= h(number_format((float)($sy['chance']['world'] ?? 0), 3)) ?>.</p>
    <?php $th = (array)($sy['thumb'] ?? []); if ($th !== []): ?>
    <p class="quiet">What that leaned on:
      <?php foreach ($th as $kind => $factor): ?><code><?= h(str_replace('_', ' ', (string)$kind)) ?>
        ×<?= h(number_format((float)$factor, 2)) ?></code> <?php endforeach; ?></p>
    <?php endif; ?>
    <?php if (($sy['beat'] ?? null) !== null): ?>
    <p class="quiet">This hour was a <b>beat</b> (<code><?= h((string)$sy['beat']) ?></code>), a beat is
      owed to the xeric rather than rolled for, so it happened on the first hour it could.</p>
    <?php endif; ?>
    <?php endif; ?>

    <h2>who was kept out</h2>
    <?php $ex = (array)($tr['excluded'] ?? []); if ($ex === []): ?>
      <p class="quiet">Nobody. Anyone who was around could have been at this.</p>
    <?php else: ?>
      <ul class="sys">
        <?php foreach ($ex as $x): ?>
        <li><span class="k"><?= h((string)($x['name'] ?? $x['handle'] ?? '')) ?></span>
          <span class="v"><?= h((string)($x['why'] ?? '')) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>where everybody was, that hour</h2>
    <?php $st = (array)($tr['standing'] ?? []); if ($st === []): ?>
      <p class="quiet">Not recorded.</p>
    <?php else: ?>
      <ul class="sys">
        <?php foreach ($st as $p): $inIt = in_array((string)($p['handle'] ?? ''), (array)$ev['participants'], true); ?>
        <li class="<?= $inIt ? '' : 'off' ?>"><span class="k"><?= h((string)($p['name'] ?? '')) ?><?= $inIt ? ', was there' : '' ?></span>
          <span class="v"><?= empty($p['free'])
            ? h('on shift at ' . (string)$p['where'] . ((string)($p['doing'] ?? '') !== '' ? ', ' . (string)$p['doing'] : ''))
            : 'nothing on, free' ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>the kinds this xeric can produce</h2>
    <p class="quiet">A kind exists only if the forge armed one of its systems. Everything not on this list
      can never happen here, however much the model would like it to.</p>
    <ul class="sys">
      <?php foreach ((array)($tr['kinds_armed'] ?? []) as $k):
        $picked = (string)$k === (string)($why['kind'] ?? ''); ?>
      <li class="<?= $picked ? '' : 'off' ?>"><span class="k"><code><?= h((string)$k) ?></code><?= $picked ? ', chosen' : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php $tried = (array)($tr['kinds_tried'] ?? []); if ($tried !== []): ?>
    <p class="quiet">Tried first and came to nothing:</p>
    <ul class="sys">
      <?php foreach ($tried as $x): ?>
      <li class="off"><span class="k"><code><?= h((string)($x['kind'] ?? '')) ?></code></span>
        <span class="v"><?= h((string)($x['why_not'] ?? '')) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php $notes = (array)($why['notes'] ?? []); if ($notes !== []): ?>
    <h2>what the model needed telling</h2>
    <ul class="sys">
      <?php foreach ($notes as $n): ?><li><span class="v"><?= h((string)$n) ?></span></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  <?php endif; ?>
  <p class="jump"><a href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>">every character and every event →</a></p>
        <?php
    }

// ===========================================================================
// What it has learned
// ===========================================================================
} elseif (!empty($_GET['learn'])) {
    // Every number below is learn.php's own answer, asked the way the engine
    // asks: the weights through xeric_learn_kind_weights (so the kill switch
    // and the confidence floor both show as they actually bite), the reach
    // through xeric_learn_reach, the crumbs through the same evidence-line
    // renderer the distil pass hands the model.
    $userName   = trim((string)($T['user']['name'] ?? '')) ?: 'the visitor';
    $learningOn = xeric_learn_enabled($db);
    $rates      = xeric_learn_kind_rates($db);
    $weights    = xeric_learn_kind_weights($db);
    $recent     = xeric_signals_recent($db, 20);
    $struck     = xeric_lessons_struck($db, 12);
    ?>
  <h1>What this xeric has learned</h1>
  <p class="sub">learn.php, from the evidence up: what <?= h($userName) ?> actually did, what the counting
    made of it, and the few sentences the distil pass thought worth keeping. Like the rest of this page
    it is a read of what is already there — no model call, no write, no clock moved.</p>

  <?php if (!$learningOn): ?>
    <p class="note warn">Learning is <b>switched off</b> for this xeric. The crumbs below still land — the
      diary never goes blind — but no distil pass runs, every weight and reach on this page is held at its
      default, and the lessons are held out of every prompt until it is switched back on. Nothing has been
      deleted.</p>
  <?php endif; ?>

  <h2>the thumb on each kind of hour</h2>
  <?php if ($rates === []): ?>
    <p class="quiet">Nothing yet. These are written in hindsight, at the tail of a skip: an hour whose
      people were spoken to afterwards landed, one walked past did not.</p>
  <?php else: ?>
    <ul class="sys">
      <?php foreach ($rates as $name => $r): $wgt = $weights[$name] ?? null; ?>
      <li><span class="k"><code><?= h((string)$name) ?></code></span>
        <span class="v">offered <?= (int)$r['seen'] ?>, followed up <?= (int)$r['engaged'] ?><?=
            $r['rate'] !== null ? ' (' . (int)round(100 * (float)$r['rate']) . '%)' : '' ?>
          · weight <?php if ($wgt !== null): ?><b>×<?= h(number_format((float)$wgt, 3)) ?></b><?php
            elseif ($learningOn): ?>×1.000, too few to move anything yet<?php
            else: ?>×1.000, held at default while learning is off<?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="quiet">Relative to this xeric's own average engagement, clamped to
      ×<?= h(number_format(XERIC_LEARN_KIND_FLOOR, 2)) ?>–×<?= h(number_format(XERIC_LEARN_KIND_CEIL, 2)) ?>,
      and nothing moves under <?= (int)XERIC_LEARN_CONFIDENT ?> observations. The floor is the point: a
      kind nobody ever follows up on still happens, a quarter as often — a xeric pruned down to the four
      things you liked last week has stopped being able to surprise you. The weight multiplies the kind's
      own base rarity in sweeps.php; it leans on the order kinds are tried in, it never decides.</p>
  <?php endif; ?>

  <h2>who has earned another message</h2>
  <ul class="sys">
    <?php foreach ((array)($T['cast']['characters'] ?? []) as $c):
        $hh    = (string)$c['handle'];
        $tal   = xeric_learn_tally($db, $hh);
        $reach = xeric_learn_reach($db, $hh);
        $bits  = [];
        if ($tal['reply_rate'] === null) {
            $bits[] = 'has never texted first — never answered and never asked are different facts';
        } else {
            $bits[] = 'answered ' . (int)$tal['replies'] . ', left sitting ' . (int)$tal['ignored']
                    . ' (' . (int)round(100 * (float)$tal['reply_rate']) . '%)';
        }
        if ((int)$tal['reads'] > 0) $bits[] = (int)$tal['reads'] . ' thread read(s)';
        if ((int)$tal['edits'] > 0) $bits[] = (int)$tal['edits'] . ' hand edit(s)';
        if ($tal['avg_reply_chars'] !== null) $bits[] = 'replies average ' . (int)$tal['avg_reply_chars'] . ' chars';
    ?>
    <li><span class="k"><?= h((string)$c['display_name']) ?> <code>×<?= h(number_format($reach, 3)) ?></code></span>
      <span class="v"><?= h(implode(' · ', $bits)) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <p class="quiet">The multiplier on how often each of them reaches out (proactive.php), clamped to
    ×<?= h(number_format(XERIC_LEARN_REACH_FLOOR, 2)) ?>–×<?= h(number_format(XERIC_LEARN_REACH_CEIL, 2)) ?>
    and ×1.000 until <?= (int)XERIC_LEARN_CONFIDENT ?> messages have been offered: being ignored is not
    being deleted, and one quiet evening is not a preference.</p>

  <h2>the newest crumbs, in plain language</h2>
  <?php if ($recent === []): ?>
    <p class="quiet">Nothing yet. A crumb is written where the doing happens — a turn, a thread opened, a
      hand edit, the tail of a skip — and this xeric has not been played since learning existed.</p>
  <?php else: ?>
    <ul class="sys">
      <?php foreach ($recent as $r):
          $line = xeric_learn_evidence_line($T, $r, $userName);
          if ($line === '') $line = (string)$r['kind'] . ((string)$r['note'] !== '' ? ', ' . (string)$r['note'] : '');
      ?>
      <li class="<?= (int)$r['processed'] === 1 ? 'off' : '' ?>"><span class="v"><?= h($line) ?>
        <span class="quiet">· <?= (int)($r['world_epoch'] ?? 0) > 0
            ? h(xeric_play_stamp($T, (int)$r['world_epoch']))
            : h(date('M j, H:i', (int)$r['created_at'])) ?>
          · <?= (int)$r['processed'] === 1 ? 'read' : 'not yet read' ?></span></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="quiet">Newest 20, dimmed once a distil pass has read them. Read crumbs age out after
      <?= (int)XERIC_LEARN_KEEP_DAYS ?> days — evidence, not history.</p>
  <?php endif; ?>

  <h2>the lessons, and what earned them</h2>
  <?php
    $buckets = ['' => 'about everybody'];
    foreach ((array)($T['cast']['characters'] ?? []) as $c) {
        $buckets[(string)$c['handle']] = 'about ' . (string)$c['display_name'];
    }
    $anyLessons = false;
    foreach ($buckets as $bh => $label):
        $ls = xeric_lessons_read($db, $bh === '' ? xeric_arc_world() : $bh);
        if ($ls === []) continue;
        $anyLessons = true;
        $earned = xeric_lessons_earned($db, $bh === '' ? xeric_arc_world() : $bh);
  ?>
    <div class="take"><div class="tn"><?= h($label) ?></div>
      <?php foreach ($ls as $l): $e = $earned[$l] ?? null; ?>
      <div class="tt">“<?= h((string)$l) ?>”</div>
      <?php if (is_array($e)): ?>
        <p class="quiet blknote">distilled <?= h(date('M j', (int)($e['at'] ?? 0))) ?>, the pass that wrote
          it was reading:<?php foreach ((array)($e['evidence'] ?? []) as $ln): ?><br>· <?= h((string)$ln) ?><?php endforeach; ?></p>
      <?php else: ?>
        <p class="quiet blknote">no trace — written before provenance was kept, or added by hand</p>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$anyLessons): ?>
    <p class="quiet">No lessons yet. The distil pass writes one only when the words of a hand edit, a
      reply, or a thread visit say something the counting cannot — an empty notebook is a correct answer.</p>
  <?php elseif (!$learningOn): ?>
    <p class="quiet">Held out of every prompt while learning is off; shown here because the notebook
      itself is untouched.</p>
  <?php endif; ?>

  <h2>struck out</h2>
  <?php if ($struck === []): ?>
    <p class="quiet">Nothing. A distil pass may strike ONE lesson the recent record contradicts — the
      only exit that is not eviction by age — and it has not happened here, or not in the last
      <?= (int)XERIC_LEARN_KEEP_DAYS ?> days, which is as long as the trace is kept.</p>
  <?php else: ?>
    <ul class="sys">
      <?php foreach ($struck as $s): ?>
      <li><span class="k"><?= h((string)$s['handle'] === '' ? 'everybody' : xeric_world_name($T, (string)$s['handle'])) ?></span>
        <span class="v">“<?= h((string)$s['lesson']) ?>”<br>
          <span class="quiet">struck <?= h(date('M j, H:i', (int)$s['created_at'])) ?> —
            <?= h((string)$s['why']) ?></span></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <p class="jump"><a href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>">every character and every event →</a>
    · <a href="play.php?w=<?= h(rawurlencode($w['slug'])) ?>">back to the xeric</a></p>
<?php

// ===========================================================================
// The index
// ===========================================================================
} else {
    $events = xeric_events_recent($db, 25);
    $lastRaw = xeric_world_state_get($db, 'why:last_tick');
    $last = $lastRaw !== null ? json_decode($lastRaw, true) : null;
    ?>
  <h1>Why this xeric does what it does</h1>
  <p class="sub"><?= h((string)$T['meta']['name']) ?> at <?= h(xeric_play_when($now)) ?>. Nothing on this page
    calls a model or changes anything, it is a read of what is already there.</p>

  <h2>what each of them is told</h2>
  <p class="quiet">The exact messages the model receives, with a size on every section, which is usually
    the answer to "why does she sound like that".</p>
  <ul class="cast">
    <?php foreach ((array)($T['cast']['characters'] ?? []) as $c): $hh = (string)$c['handle']; ?>
    <li><a class="person" href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>&amp;h=<?= h(rawurlencode($hh)) ?>">
      <span><span class="pn"><?= h((string)$c['display_name']) ?></span>
      <span class="po"><?= h((string)($c['one_line'] ?? '')) ?></span>
      <span class="pw"><?= (int)xeric_memories_count($db, $hh) ?> memories</span></span>
      <span class="pgo">›</span></a></li>
    <?php endforeach; ?>
  </ul>

  <h2>what has happened here</h2>
  <?php if ($events === []): ?>
    <p class="quiet">Nothing yet. Press the time control in the xeric and something will be.</p>
  <?php else: ?>
    <ul class="cast">
      <?php foreach ($events as $e):
        $kept = xeric_world_state_get($db, 'why:event:' . (int)$e['id']) !== null; ?>
      <li><a class="person" href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>&amp;e=<?= (int)$e['id'] ?>">
        <span><span class="pn"><?= h((string)$e['title']) ?></span>
        <span class="po"><?= h(mb_substr((string)$e['prose'], 0, 110)) ?><?= mb_strlen((string)$e['prose']) > 110 ? '…' : '' ?></span>
        <span class="pw<?= $kept ? '' : ' off' ?>"><?= h(xeric_play_stamp($T, (int)$e['world_epoch'])) ?>
          · <?= $kept ? 'decision trail kept' : 'seed history, nothing chose it' ?></span></span>
        <span class="pgo">›</span></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>the last hours that produced nothing</h2>
  <?php if (!is_array($last) || $last === []): ?>
    <p class="quiet">No skip has been recorded for this copy of the xeric yet.</p>
  <?php else: ?>
    <p class="quiet">The most recent skip: <?= h((string)($last['span'] ?? '')) ?>,
      <?= (int)($last['events'] ?? 0) ?> event(s). Quiet hours have reasons, and this is where they are.</p>
    <ul class="sys">
      <?php foreach ((array)($last['notes'] ?? []) as $n): ?>
      <li><span class="v"><?= h((string)$n) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>what it has learned</h2>
  <p class="quiet"><a href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>&amp;learn=1">The learning layer,
    from the evidence up →</a> the newest signals in plain language, the per-kind weights and their
    floors, each person's reach, the lessons with what earned them, and anything struck.</p>

  <h2>what this xeric runs on</h2>
  <div class="panel"><?= xeric_play_panel_html(xeric_play_panel($T, $db)) ?></div>
  <p class="jump"><a href="review.php?w=<?= h(rawurlencode($w['slug'])) ?>">change any of it →</a>
    · <a href="play.php?w=<?= h(rawurlencode($w['slug'])) ?>">back to the xeric</a></p>
<?php } ?>

  <footer>The inspector reads <code>worlds/<?= h($w['slug']) ?>/</code> and the database this browser plays
    against. It never calls a model and never writes anything.</footer>
</main>
</html>
<?php
