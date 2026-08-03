<?php
/**
 * debrief.php — what the room got to, and what it walked past. EXPERIMENTAL.
 *
 * The whole page is a report about ONE argument, and it is deliberately not a
 * chat log. A transcript tells you what a room concluded; this tells you what
 * it CONSIDERED — every position with the refusal behind it, every proposal
 * with who could and could not live with it, the reasoning under each turn,
 * anything the room built, and the threads nobody followed.
 *
 * ── THE THING THIS PAGE REFUSES TO DO ─────────────────────────────────────
 *
 * It does not produce a synthesis when there was not one. If the room hung,
 * the headline is the PAIR — which two refusals were never satisfied at the
 * same time — and it is printed as a finding, at the top, in the same weight
 * a consensus would get. That is the honest answer to most contested
 * questions and it is the one every tool of this shape throws away.
 *
 * ── AND WHY THE REASONING IS SHOWN ────────────────────────────────────────
 *
 * The room is an open collaboration: everybody can see what everybody else was
 * thinking, so anybody can pick up an abandoned half-idea. That is only true
 * if the reader can see it too, which is most of what this page is for. The
 * threads section exists because a room under pressure converges early and
 * drops the thing it did not have time for, and that is routinely the most
 * useful item on the page.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/panel.php';

$slug = xeric_web_slug((string)($_GET['w'] ?? ''));

// Before the open, for the same reason why.php checks first: opening a
// stranger's world forks a copy of its database, and a page about to refuse
// should leave nothing behind.
xeric_play_guard($slug, 'The debrief prints everything the room said and everything each of them '
    . 'was thinking when they said it, including the parts nobody followed up. That is the whole '
    . 'point of a discussion xeric and it only works by showing the real thing.');

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_head('Xeric: debrief');
    echo '<style>' . xeric_play_css() . xeric_debrief_css() . '</style>';
    echo '<main><div class="top"><p class="wordmark">XERIC</p><span class="kicker">debrief</span></div>';
    echo '<h1>There is nothing to report on</h1><p class="note bad">' . h($e->getMessage()) . '</p>';
    echo '<p><a href="play.php">The xerics that are here →</a></p></main>';
    exit;
}

$T  = $w['template'];
$db = $w['db'];
$P  = xeric_panel($T);

// ONE ARTIFACT, RAW. `?a=<n>` serves the thing itself with nothing around it —
// a link somebody can paste to a colleague, curl, or save, which is what
// "provide you the link" has to mean if the deliverable is a program. Served
// as text/plain ALWAYS, whatever the room called its language: this is
// attacker-adjacent content (a model wrote it, at somebody's prompting) and
// serving it as text/html would be handing a stored XSS a content type.
if (isset($_GET['a'])) {
    $all = xeric_panel_artifacts($db);
    $i   = (int)$_GET['a'];
    if (!isset($all[$i])) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8');
                            exit("no such thing\n"); }
    $one  = $all[$i];
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$one['title']) ?: 'artifact';
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (isset($_GET['d'])) header('Content-Disposition: attachment; filename="' . trim($name, '-') . '.txt"');
    echo (string)$one['body'];
    exit;
}

xeric_web_head('Xeric: debrief · ' . (string)$T['meta']['name']);
echo '<style>' . xeric_play_css() . xeric_debrief_css() . '</style>';
?>
<main>
  <div class="top">
    <p class="wordmark">XERIC</p>
    <span class="kicker">debrief</span>
    <?= xeric_web_meter_html() ?>
    <span class="count"><a href="play.php?w=<?= h(rawurlencode($w['slug'])) ?>">back to the room</a></span>
  </div>
<?php

if ($P === null) {
    echo '<h1>This xeric is not a discussion</h1>';
    echo '<p class="note">A debrief reports on a room built around a question — the experimental '
       . '<em>a problem</em> door on the forge. This one is a place to live in, and the thing that '
       . 'reads a place is <a href="book.php?w=' . h(rawurlencode($w['slug'])) . '">the book</a>.</p>';
    echo '</main>';
    exit;
}

$verdict   = xeric_panel_verdict($T, $db);
$proposals = array_values(array_filter(xeric_panel_proposals($db),
    fn($p) => ($p['clears'] ?? []) !== [] || ($p['crosses'] ?? []) !== []));
$thoughts  = xeric_panel_thoughts($db);
$threads   = xeric_panel_threads($db);
$made      = xeric_panel_artifacts($db);
$experts   = $P['experts'];
?>

  <p class="expmark">EXPERIMENTAL · these are characters arguing, not experts consulting.
    Read it as a way of seeing the disagreement, never as advice.</p>

  <h1><?= h($P['question'] !== '' ? $P['question'] : 'the question in the room') ?></h1>
  <p class="where"><?= h((string)($T['meta']['name'] ?? 'the room')) ?><?php
    if ($P['problem'] !== '') echo ' · ' . h(mb_substr($P['problem'], 0, 240))
        . (mb_strlen($P['problem']) > 240 ? '…' : ''); ?></p>

  <!-- THE HEADLINE, and a hung room gets the same weight a consensus does.
       That is the whole editorial position of this page: "nothing satisfies
       both of these" is a finding about the problem, not a failure of the
       room, and burying it under an apology would be the one thing that makes
       this tool dishonest. -->
  <section class="verdict v-<?= h($verdict['state']) ?>">
<?php if ($verdict['state'] === 'nothing'): ?>
    <h2>Nothing has been put to the room yet</h2>
    <p>Say something to them, or hand them a proposal, and this page fills in.</p>
<?php elseif ($verdict['state'] === 'consensus'): ?>
    <h2>The room got there</h2>
    <p class="prop"><?= h((string)$verdict['proposal']['text']) ?></p>
    <p class="tally">Nobody's line was crossed — <?= (int)$verdict['cleared'] ?> of
       <?= (int)$verdict['of'] ?>.</p>
<?php else: ?>
    <h2>The room did not get there, and that is the answer</h2>
    <p class="tally">The closest anybody came was <?= (int)$verdict['cleared'] ?> of
       <?= (int)$verdict['of'] ?>:</p>
    <p class="prop"><?= h((string)$verdict['proposal']['text']) ?></p>
<?php foreach ($verdict['tensions'] as $tn): ?>
    <div class="tension">
      <p class="between">Nothing satisfied <b><?= h($tn['between'][0]) ?></b> and
         <b><?= h($tn['between'][1]) ?></b> at the same time.</p>
      <p class="line">— <?= h($tn['lines'][0]) ?></p>
      <p class="line">— <?= h($tn['lines'][1]) ?></p>
    </div>
<?php endforeach; ?>
<?php endif; ?>
  </section>

<?php if ($made !== []): ?>
  <!-- WHAT THEY BUILT, above the argument about it. If somebody asked this
       room for a program, the program is what they came for. -->
  <h2 class="sec">What the room made</h2>
<?php foreach ($made as $mi => $m): ?>
  <section class="made">
    <h3><?= h((string)$m['title']) ?><?php
      if ((string)$m['kind'] !== 'text') echo ' <span class="kind">' . h((string)$m['kind']) . '</span>';
      if ((string)($m['by'] ?? '') !== '') echo ' <span class="by">' . h(xeric_world_name($T, (string)$m['by']) ?: (string)$m['by']) . '</span>';
    ?></h3>
    <pre class="body"><?= h((string)$m['body']) ?></pre>
    <p class="links"><a href="debrief.php?w=<?= h(rawurlencode($w['slug'])) ?>&amp;a=<?= (int)$mi ?>">raw</a>
      · <a href="debrief.php?w=<?= h(rawurlencode($w['slug'])) ?>&amp;a=<?= (int)$mi ?>&amp;d=1">download</a></p>
  </section>
<?php endforeach; ?>
<?php endif; ?>

  <h2 class="sec">Who was in the room</h2>
  <div class="who">
<?php foreach ($experts as $h => $e): ?>
    <section class="expert">
      <h3><?= h($e['name']) ?></h3>
<?php if ($e['stake'] !== ''): ?>
      <p class="stake">Protecting: <?= h($e['stake']) ?></p>
<?php endif; ?>
      <p class="red">Will not accept: <?= h($e['red_line']) ?></p>
<?php
    $for = $against = 0;
    foreach ($proposals as $p) {
        if (in_array($h, (array)$p['clears'], true)) $for++;
        elseif (isset($p['crosses'][$h])) $against++;
    }
    if ($for + $against > 0):
?>
      <p class="tally"><?= $for ?> they could live with, <?= $against ?> they refused.</p>
<?php endif; ?>
    </section>
<?php endforeach; ?>
  </div>

<?php if ($proposals !== []): ?>
  <!-- EVERY PROPOSAL WITH THE WHOLE ROOM'S ANSWER, including the ones that
       died. A report that only showed the winner would hide the shape of the
       disagreement, which is the thing worth reading. -->
  <h2 class="sec">What was put to them</h2>
<?php foreach ($proposals as $n => $p): ?>
  <section class="proposal<?= count((array)$p['clears']) >= count($experts) ? ' clean' : '' ?>">
    <p class="prop"><?= h((string)$p['text']) ?></p>
    <ul class="votes">
<?php foreach ($experts as $h => $e):
        $crossed = isset($p['crosses'][$h]); ?>
      <li class="<?= $crossed ? 'no' : 'yes' ?>"><b><?= h($e['name']) ?></b>
        <?= $crossed ? 'will not have it' : 'can live with it' ?><?php
          if ($crossed && (string)$p['crosses'][$h] !== '')
              echo ' <span class="why">— ' . h((string)$p['crosses'][$h]) . '</span>'; ?></li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($threads !== []): ?>
  <!-- THE MOST USEFUL SECTION ON THE PAGE, most of the time. A room under
       pressure converges early and drops the idea it did not have time for. -->
  <h2 class="sec">Threads nobody followed</h2>
  <p class="note">Things somebody raised that the room walked past. Not because they were wrong —
    because a room under pressure converges early. This is where the ideas you did not get are.</p>
<?php foreach ($threads as $th): ?>
  <section class="thread">
    <p class="said">“<?= h($th['said']) ?>”</p>
    <p class="who"><?= h(xeric_world_name($T, $th['who']) ?: $th['who']) ?><?php
      if ($th['why'] !== '') echo ' <span class="why">thinking: ' . h($th['why']) . '</span>'; ?></p>
  </section>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($thoughts !== []): ?>
  <h2 class="sec">Everything said, and what was behind it</h2>
  <p class="note">Nothing in this room is private. Each of them can see the others' reasoning
    while they argue, which is what lets somebody pick up a half-idea and finish it.</p>
  <ol class="turns">
<?php foreach ($thoughts as $r): ?>
    <li>
      <p class="said"><b><?= h(xeric_world_name($T, (string)$r['who']) ?: (string)$r['who']) ?></b>
         <?= h((string)$r['said']) ?></p>
<?php if ((string)($r['why'] ?? '') !== ''): ?>
      <p class="why">thinking: <?= h((string)$r['why']) ?></p>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>

<?php if (!empty($w['mine'])): ?>
  <!-- THE TWO THINGS YOU CAN DO TO A ROOM, on the page that shows you what it
       has done so far — which is where you actually decide to do them. A
       proposal costs one short model call per person; the build costs one long
       one. Both are said out loud, because this mode can spend real money on a
       hosted machine and a box that quietly bills you is not a box. -->
  <h2 class="sec">Put something to them</h2>
  <div class="doer">
    <textarea id="ptext" rows="3" placeholder="Nobody goes, and we borrow against next year."></textarea>
    <div class="drow">
      <button type="button" class="nbtn" id="pput">put it to the room</button>
      <button type="button" class="nbtn" id="pbuild">have them write it</button>
      <button type="button" class="nbtn" id="pargue">let them argue</button>
    </div>
    <p class="note">“Put it to the room” asks each of them, separately, whether it crosses their
      own line — one short call per person, and none of them sees the tally. “Have them write it”
      is one longer call that produces the actual thing, with every refusal in front of it.
      <b>Both spend tokens.</b> On a hosted machine that is real money.</p>
    <p class="pst" id="pst" hidden></p>
  </div>
  <script>
  (function () {
    var W = <?= json_encode($w['slug']) ?>;
    var st = document.getElementById('pst'), box = document.getElementById('ptext');
    function BTNS() {
      return ['pput', 'pbuild', 'pargue'].map(function (i) { return document.getElementById(i); })
        .filter(Boolean);
    }
    function go(mode) {
      var text = (box.value || '').trim();
      // A round needs nothing typed: the room already has its question.
      if (!text && mode !== 'round') { box.focus(); return; }
      BTNS().forEach(function (b) { b.disabled = true; });
      st.hidden = false;
      st.textContent = mode === 'build' ? 'writing it…'
        : (mode === 'round' ? 'letting them talk…' : 'putting it to them…');
      fetch('play.php?a=' + mode + '&w=' + encodeURIComponent(W), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: text }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.job) { fail((d && d.error) || 'that did not go anywhere'); return; }
          watch(d.job);
        })
        .catch(function () { fail('that did not go anywhere'); });
    }
    function fail(m) {
      st.textContent = m;
      BTNS().forEach(function (b) { b.disabled = false; });
    }
    // The room refusing in real time beats a spinner and a verdict: each note
    // is one person answering about their own line.
    function watch(job) {
      var es = new EventSource('progress.php?job=' + encodeURIComponent(job));
      ['note', 'queue'].forEach(function (k) {
        es.addEventListener(k, function (m) {
          try { var d = JSON.parse(m.data); st.textContent = d.message || d.text || st.textContent; } catch (e) {}
        });
      });
      es.addEventListener('done', function (m) {
        es.close();
        try { st.textContent = (JSON.parse(m.data).message || 'done') + ' — reloading the report'; } catch (e) {}
        setTimeout(function () { location.reload(); }, 900);
      });
      es.addEventListener('error', function (m) {
        es.close();
        var why = 'that did not finish';
        try { why = JSON.parse(m.data).message || why; } catch (e) {}
        fail(why);
      });
    }
    document.getElementById('pput').addEventListener('click', function () { go('propose'); });
    document.getElementById('pbuild').addEventListener('click', function () { go('build'); });
    document.getElementById('pargue').addEventListener('click', function () { go('round'); });
  })();
  </script>
<?php endif; ?>

  <p class="foot"><a href="play.php?w=<?= h(rawurlencode($w['slug'])) ?>">back to the room</a>
    · <a href="watch.php?w=<?= h(rawurlencode($w['slug'])) ?>">watch them talk</a></p>
</main>
<?php

/** The debrief's own skin, beside play.php's. */
function xeric_debrief_css(): string
{
    return <<<'CSS'
.expmark{font-size:.78rem;letter-spacing:.04em;text-transform:uppercase;opacity:.72;
  border:1px solid currentColor;border-radius:.4rem;padding:.5rem .7rem;margin:.4rem 0 1.2rem}
main>h1{margin:.2rem 0 .3rem;line-height:1.2}
.where{opacity:.7;margin:0 0 1.4rem;font-size:.92rem}
h2.sec{margin:2.2rem 0 .6rem;font-size:1.05rem;letter-spacing:.02em;text-transform:uppercase;opacity:.75}
.verdict{border-radius:.6rem;padding:1rem 1.1rem;border:1px solid;margin:0 0 1rem}
.verdict h2{margin:0 0 .5rem;font-size:1.25rem}
.verdict .prop{font-size:1.05rem;line-height:1.5;margin:.4rem 0}
.verdict .tally{opacity:.72;font-size:.9rem;margin:.3rem 0}
.v-consensus{border-color:#3d7a4e;background:rgba(61,122,78,.09)}
.v-hung{border-color:#8a6a2f;background:rgba(138,106,47,.09)}
.v-nothing{border-color:currentColor;opacity:.8}
.tension{margin:.9rem 0 0;padding:.7rem .8rem;border-left:3px solid currentColor;opacity:.95}
.tension .between{margin:0 0 .35rem}
.tension .line{margin:.15rem 0;opacity:.78;font-size:.92rem}
.who{display:grid;gap:.7rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr))}
.expert{border:1px solid;border-radius:.5rem;padding:.7rem .8rem;opacity:.94}
.expert h3{margin:0 0 .4rem;font-size:1rem}
.expert .stake{margin:.2rem 0;opacity:.75;font-size:.88rem}
.expert .red{margin:.3rem 0;font-size:.92rem}
.expert .tally{margin:.4rem 0 0;opacity:.6;font-size:.82rem}
.proposal{border:1px solid;border-radius:.5rem;padding:.7rem .8rem;margin:.6rem 0;opacity:.94}
.proposal.clean{border-color:#3d7a4e}
.proposal .prop{margin:0 0 .5rem;line-height:1.5}
.votes{list-style:none;padding:0;margin:0}
.votes li{font-size:.9rem;margin:.2rem 0;padding-left:1.1rem;position:relative}
.votes li:before{position:absolute;left:0}
.votes li.yes:before{content:'✓';color:#4e8a5f}
.votes li.no:before{content:'✕';color:#a8593f}
.votes .why{opacity:.7}
.thread{border-left:3px solid currentColor;padding:.5rem .8rem;margin:.6rem 0;opacity:.92}
.thread .said{margin:0 0 .25rem;font-size:1rem;line-height:1.45}
.thread .who{display:block;opacity:.65;font-size:.85rem;margin:0}
.made{border:1px solid;border-radius:.5rem;padding:.7rem .8rem;margin:.6rem 0}
.made h3{margin:0 0 .5rem;font-size:1rem}
.made .kind,.made .by{font-size:.75rem;opacity:.6;text-transform:uppercase;letter-spacing:.04em}
.made .body{white-space:pre-wrap;overflow-x:auto;font-size:.88rem;line-height:1.5;margin:0}
.turns{padding-left:1.2rem}
.turns li{margin:.6rem 0}
.turns .said{margin:0;line-height:1.5}
.turns .why,.thread .why{opacity:.62;font-size:.86rem;margin:.15rem 0 0;font-style:italic}
.doer{border:1px solid;border-radius:.5rem;padding:.8rem;margin:.5rem 0}
.doer textarea{width:100%;box-sizing:border-box;font:inherit;padding:.5rem;border-radius:.4rem;
  border:1px solid;background:transparent;color:inherit;resize:vertical}
.drow{display:flex;gap:.5rem;flex-wrap:wrap;margin:.6rem 0 .2rem}
.doer .note{font-size:.85rem;opacity:.72;margin:.5rem 0 0;line-height:1.5}
.pst{margin:.6rem 0 0;font-size:.9rem;opacity:.85}
.made .links{margin:.5rem 0 0;font-size:.85rem;opacity:.75}
.foot{margin:2.5rem 0 1rem;opacity:.75}
CSS;
}
