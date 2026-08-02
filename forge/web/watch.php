<?php
/**
 * watch.php — sit in on two of them talking.  ?w=<slug>
 *
 * The duet (engine/duet.php) run one line at a time, so a browser can do the
 * three things a terminal never needed: PAUSE it, WALK INTO it, and leave it
 * without ceremony. The engine's internals were split for exactly this — the
 * admission, the seating, and each line's assembly are the engine's own
 * functions, stepped by play-lib's watch wrappers, and nothing on this page
 * decides what a scene may say. This is deliberately THE DUET PLUS A VOICE,
 * not the Room: two cast members carry the scene, strict alternation holds,
 * and the player is a walk-in the next line answers — not a third seat.
 *
 * What this page has to get right, in the order it would hurt:
 *
 *  • NOTHING LANDS UNTIL THE CLOSE. The engine's law, kept whole: the
 *    transcript lives in a session-scoped state file (the wizard's job-file
 *    discipline) until a=close writes the CLI's own close — one event, two
 *    diaries, one trail, one transaction. Closing a tab mid-scene evaporates
 *    the scene honestly: the world never heard of it. The page says so on the
 *    way in, once, and "end the scene" is the affirmative close.
 *
 *  • A WATCHED LINE SPENDS LIKE A CHAT TURN. Every a=line is one model call
 *    through the same queue, the same hold, and the same hourly note as
 *    say.php — watching is not a way around the meter. The walk-in (a=say)
 *    calls no model and spends nothing.
 *
 *  • PLAY/PAUSE IS CLIENT-SIDE TRUTH. Playing means this page asks for the
 *    next line when the previous one lands (with a beat between, so it reads
 *    as conversation rather than printout); paused means it stops asking. The
 *    server holds no play state at all — a scene nobody asks about simply
 *    stands where it stood.
 *
 *  • FOR ITS OWNER ONLY, at book.php's own door. A scene is the cast talking
 *    with nobody performing for a visitor; that is the world's insides moving,
 *    and the shelf's rule (xeric_play_guard) is that insides are read by
 *    whoever forged them. The refusal comes before the open, so it forks
 *    nothing.
 *
 * The walk-in composer has no suggestion ghost, on purpose: the chat one
 * (play.php a=suggest) reads a stored thread by conversation id, and a scene
 * has no conversation — see xeric_play_suggest for the seam it would need.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$slug   = xeric_web_slug((string)($_GET['w'] ?? ''));
$action = (string)($_GET['a'] ?? '');
$sid    = xeric_session_id();

// Before the open, exactly as the book gates: a page about to refuse must not
// fork a stranger's world into their session on the way to saying no.
xeric_play_guard($slug, 'Watching two of its people talk to each other is the xeric\'s insides moving — '
    . 'what they carry about each other, said out loud with nobody performing for a visitor. That is for '
    . 'whoever forged it, and it plays as one.');

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    if (!xeric_web_wants_html()) xeric_web_json(['error' => $e->getMessage()], 404);
    xeric_web_head('Xeric: watch');
    echo '<style>' . xeric_play_css() . xeric_watch_css() . '</style>';
    echo '<main class="watch"><div class="top"><p class="wordmark">XERIC</p><span class="kicker">watch</span></div>';
    echo '<h1>There is nothing to watch</h1><p class="note bad">' . h($e->getMessage()) . '</p>';
    echo '<p><a href="play.php">The xerics that are here →</a></p></main>';
    exit;
}

$T  = $w['template'];
$db = $w['db'];

// ---------------------------------------------------------------------------
// The actions. POST, JSON, say.php's idiom: the action names itself in the
// query, the body carries the pair (and the walk-in's words).
// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // A line may legitimately outlive mod_php's 30 seconds; same ceiling as a turn.
    @set_time_limit(150);
    ignore_user_abort(false);

    $in = xeric_web_input();
    $pa = trim((string)($in['a'] ?? ''));
    $pb = trim((string)($in['b'] ?? ''));

    // The engine's sentences are written for a log; its REFUSALS are written
    // for people and this surface promises them verbatim — minus the log's
    // own "duet:" prefix, which is an address, not a word anybody said.
    $bare = fn(string $m): string => preg_replace('/^duet:\s*/', '', $m) ?? $m;

    // -- open a scene ---------------------------------------------------------
    if ($action === 'start') {
        xeric_watch_sweep();
        try {
            $s = xeric_watch_start($w, $pa, $pb);
        } catch (Throwable $e) {
            // A rule and a bad request are different answers: "refused," is the
            // engine working — most often the entry page gone stale under a
            // moved clock — and its sentence names both rooms, so it renders
            // as itself. Anything else is a caller that named the wrong person.
            $rule = str_contains($e->getMessage(), 'refused,');
            xeric_web_json(['error' => $bare($e->getMessage()), 'kind' => $rule ? 'refused' : 'bad'],
                $rule ? 409 : 400);
        }
        xeric_watch_write(xeric_watch_path($sid, $slug, (string)$s['a'], (string)$s['b']), $s);
        xeric_web_json(['ok' => true, 'scene' => xeric_watch_public($s)]);
    }

    // Everything below acts on a running scene.
    $path = xeric_watch_path($sid, $slug, $pa, $pb);
    $s    = $pa !== '' && $pb !== '' ? xeric_watch_read($path) : null;
    if ($s === null) {
        xeric_web_json(['error' => 'That scene is not running any more. A scene lives only while this page '
            . 'holds it, nothing it said was written anywhere, start another.', 'kind' => 'gone'], 409);
    }

    // -- one spoken line ------------------------------------------------------
    if ($action === 'line') {
        if ((int)$s['spoken'] >= (int)$s['turns']) {
            xeric_web_json(['error' => 'The scene already said its last line.', 'kind' => 'gone'], 409);
        }

        xeric_limit_guard(xeric_limit_check('message', ['sid' => $sid]));

        // The same line as everything else: one GPU, one slot, position said
        // out loud past the wait — say.php's shape exactly.
        $got = xeric_queue_take('say', XERIC_QUEUE_SAY_WAIT, $sid);
        if (!$got['ok']) {
            $kind  = (string)($got['kind'] ?? 'queued');
            $retry = (int)($got['retry_after'] ?? 15);
            if ($retry > 0 && !headers_sent()) header('Retry-After: ' . $retry);
            xeric_web_json([
                'error'       => (string)$got['message'],
                'kind'        => $kind,
                'ahead'       => (int)($got['ahead'] ?? 0),
                'eta'         => (int)($got['eta'] ?? 0),
                'phrase'      => (string)($got['phrase'] ?? ''),
                'retry_after' => $retry,
            ], in_array($kind, ['drained', 'full', 'broken'], true) ? 503 : 429);
        }
        $lock = $got['hold'];
        $done = function (array $body, int $status = 200) use ($lock): void {
            xeric_queue_release($lock);
            xeric_web_json($body, $status);
        };

        try {
            $endpoint = xeric_play_endpoint();
        } catch (Throwable $e) {
            $done(['error' => $e->getMessage(), 'kind' => 'detached'], 409);
            return;
        }
        if (!xeric_llm_up($endpoint, 6)) {
            $done(['error' => 'The model this xeric runs on is not answering, so the scene stands where it '
                . 'is. Nothing in it is lost. Try again in a minute.', 'kind' => 'model_down'], 503);
            return;
        }

        $who = xeric_watch_next($s);
        try {
            $out = xeric_watch_line($w, $s, $endpoint, $sid);
        } catch (Throwable $e) {
            $m = $e->getMessage();
            if (str_contains($m, 'next to the thing they must not know')) {
                // The wall's own sentence, verbatim: a rule, not an outage.
                $done(['error' => $bare($m), 'kind' => 'refused'], 422);
                return;
            }
            $refused = xeric_play_say_refused($m);
            $done(['error' => xeric_play_say_error($m, $who['name']),
                   'kind'  => $refused ? 'refused' : 'model'], $refused ? 422 : 502);
            return;
        }
        xeric_watch_write($path, $s);

        // The natural end closes itself: the last line and the landing are one
        // answer, and a scene that finished must not depend on one more tap.
        $closed = null;
        if (!empty($out['done'])) {
            try {
                $closed = xeric_watch_close($w, $s, $endpoint);
                xeric_watch_clear($path);
            } catch (Throwable $e) {
                // The line is in the state file; "end the scene" retries the
                // close against a world nothing has half-written.
                $done(['error' => xeric_play_say_error($e->getMessage(), ''), 'kind' => 'model',
                       'who' => $out['handle'], 'name' => $out['name'], 'text' => $out['text']], 502);
                return;
            }
        }

        $done([
            'ok'     => true,
            'who'    => (string)$out['handle'],
            'name'   => (string)$out['name'],
            'text'   => (string)$out['text'],
            'next'   => $out['next'],
            'done'   => (bool)$out['done'],
            'closed' => $closed,
            'waited' => (float)($got['waited'] ?? 0),
        ]);
    }

    // -- the walk-in ----------------------------------------------------------
    if ($action === 'say') {
        try {
            $out = xeric_watch_say($w, $s, (string)($in['text'] ?? ''));
        } catch (Throwable $e) {
            $m = $e->getMessage();
            if (str_contains($m, 'next to the thing they must not know')) {
                xeric_web_json(['error' => $bare($m), 'kind' => 'refused'], 422);
            }
            $refused = xeric_play_say_refused($m);
            xeric_web_json(['error' => $refused ? xeric_play_say_error($m, '') : $bare($m),
                            'kind'  => $refused ? 'refused' : 'bad'], $refused ? 422 : 400);
        }
        xeric_watch_write($path, $s);
        xeric_web_json(['ok' => true, 'next' => (string)$out['next']]);
    }

    // -- the affirmative close ------------------------------------------------
    if ($action === 'close') {
        // The two diary calls are model calls, so the close joins the line like
        // one. A missing or silent model forfeits the diaries into notes and
        // the event still lands — the scene happened, learning is garnish.
        $got = xeric_queue_take('say', XERIC_QUEUE_SAY_WAIT, $sid);
        if (!$got['ok']) {
            $kind  = (string)($got['kind'] ?? 'queued');
            $retry = (int)($got['retry_after'] ?? 15);
            if ($retry > 0 && !headers_sent()) header('Retry-After: ' . $retry);
            xeric_web_json(['error' => (string)$got['message'], 'kind' => $kind,
                            'phrase' => (string)($got['phrase'] ?? ''), 'retry_after' => $retry],
                in_array($kind, ['drained', 'full', 'broken'], true) ? 503 : 429);
        }
        $lock = $got['hold'];
        $done = function (array $body, int $status = 200) use ($lock): void {
            xeric_queue_release($lock);
            xeric_web_json($body, $status);
        };

        $endpoint = null;
        try {
            $ep = xeric_play_endpoint();
            if (xeric_llm_up($ep, 6)) $endpoint = $ep;
        } catch (Throwable $e) {
            // Detached is not a reason to hold a lived scene hostage.
        }

        try {
            $closed = xeric_watch_close($w, $s, $endpoint);
        } catch (Throwable $e) {
            $done(['error' => xeric_play_say_error($e->getMessage(), ''), 'kind' => 'model'], 502);
            return;
        }
        xeric_watch_clear($path);

        if (!empty($closed['empty'])) {
            // Nobody spoke, so there is nothing to make true. Said as the
            // non-event it is, not dressed as a failure.
            $done(['ok' => true, 'empty' => true,
                   'note' => 'Nothing was said, so nothing lands. The world never heard of it.']);
            return;
        }
        $done(['ok' => true, 'closed' => [
            'event_id' => (int)$closed['event_id'],
            'title'    => (string)$closed['title'],
            'prose'    => (string)$closed['prose'],
            'memories' => (array)$closed['memories'],
            'names'    => (array)$s['names'],
            'notes'    => (array)$closed['notes'],
        ]]);
    }

    xeric_web_json(['error' => 'no such action'], 400);
}

// ---------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------

$now   = xeric_clock_now($db, $T);
$rooms = xeric_watch_rooms($T, $db, $now);
$live  = xeric_watch_find($sid, $slug, $T);          // a scene a reload must not lose
$me    = trim((string)($T['user']['name'] ?? '')) ?: 'you';

xeric_web_head('Xeric: watch · ' . (string)$T['meta']['name']);
echo '<style>' . xeric_play_css() . xeric_watch_css() . '</style>';
?>
<main class="watch">
  <div class="top">
    <p class="wordmark">XERIC</p>
    <span class="kicker">watch</span>
    <?= xeric_web_meter_html() ?>
    <span class="count"><a href="play.php?w=<?= h(rawurlencode($w['slug'])) ?>">back to the xeric</a></span>
  </div>

  <h1>two of them, talking</h1>
  <p class="sub lead">This is the duet plus a voice, not the Room: two people carry the scene in strict turns,
    and anything you say is a walk-in the next line answers. Every spoken line is its own model call from its
    own speaker's head, and it spends from your hour like any message.</p>
  <!-- The one warning, on the way in, once: leaving is free and writes nothing. -->
  <p class="wnote">Walking away evaporates a scene — nothing it said is written anywhere until you
    <b>end the scene</b>, which is what lands it: one event the town keeps, and what each of them privately
    took away.</p>

  <!-- ----------------------------------------------------------- the entry -->
  <section id="wentry"<?= $live !== null ? ' hidden' : '' ?>>
    <h2>who is in a room together</h2>
    <?php if ($rooms === []): ?>
    <p class="quiet">Nobody is anywhere at all right now — the whole cast is off the map. Skip some time on
      the play view and look again.</p>
    <?php endif; ?>
    <?php $anyPair = false; foreach ($rooms as $r): ?>
    <div class="wroom">
      <h3><?= h($r['name']) ?></h3>
      <p class="wpeople"><?php
        $bits = [];
        foreach ($r['who'] as $p) $bits[] = $p['name'] . ($p['doing'] !== '' ? ' — ' . $p['doing'] : '');
        echo h(implode(' · ', $bits));
      ?></p>
      <?php if ($r['pairs'] === []): ?>
      <p class="wone">only <?= h($r['who'][0]['name']) ?> here — nobody to talk to.</p>
      <?php else: $anyPair = true; ?>
      <div class="wpairs">
        <?php foreach ($r['pairs'] as $pr): ?>
        <button type="button" class="wpair" data-a="<?= h($pr[0]) ?>" data-b="<?= h($pr[1]) ?>">
          <?= h(xeric_world_name($T, $pr[0])) ?> ✕ <?= h(xeric_world_name($T, $pr[1])) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($rooms !== [] && !$anyPair): ?>
    <p class="quiet">Nobody shares a room with anybody else right now. Two people have to be in one place at
      the world's own now — skip time, or wait for a shift to put them together.</p>
    <?php endif; ?>
    <p class="note warn" id="wenterr" hidden></p>
  </section>

  <!-- ----------------------------------------------------------- the scene -->
  <section id="wscene" hidden>
    <div class="wshead" id="wshead"></div>
    <ul class="msgs" id="wmsgs"></ul>
    <div class="thinking" id="wthinking" hidden><i></i><i></i><i></i><span id="wthinkwho"></span></div>
    <p class="note warn" id="werr" hidden></p>
    <div class="wctl">
      <button type="button" class="nbtn" id="wplay">⏸ pause</button>
      <span class="grow"></span>
      <button type="button" class="nbtn" id="wclose">end the scene</button>
    </div>
    <div class="composer">
      <textarea id="wcomposer" rows="1" placeholder="walk in — say something…" autocomplete="off"></textarea>
      <button class="btn" type="button" id="wsay">Walk in</button>
    </div>
  </section>

  <div id="wendbox"></div>

  <footer>The scene lives in a file of this session's until it is ended, and in the xeric's database only
    after: one event saying they talked, and each speaker's own diary of it. Walking away writes nothing.</footer>
</main>

<script>
(function () {
  'use strict';
  var W  = <?= json_encode($w['slug']) ?>;
  var ME = <?= json_encode($me) ?>;
  // A scene this session already has running: the transcript lives in the
  // state file, so a reload redraws it whole and picks up paused.
  var RESUME = <?= json_encode($live !== null ? xeric_watch_public($live) : null) ?>;
  var BEAT = 1300;                       // the breath between lines while playing
  var QUEUE_TRIES = 3;

  var $ = function (s) { return document.querySelector(s); };
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  var SCENE = null;                      // {a,b,names,next,...} while one runs
  var playing = false, inflight = false, over = false;

  // The face is the thread idiom's fallback: two letters and a hue off the name.
  function face(name) {
    var n = String(name || '?').trim();
    var initials = n.split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('');
    var h = 0;
    for (var i = 0; i < n.length; i++) h = (h * 31 + n.charCodeAt(i)) % 360;
    return { text: (initials || '?').toUpperCase(), hue: h };
  }

  function addMsg(who, name, text) {
    var li = document.createElement('li');
    li.className = who;
    var f = face(name);
    var label = who === 'them' ? '<span class="wwho">' + esc(name) + '</span>' : '';
    li.innerHTML = '<span class="av" style="--hue:' + f.hue + '" aria-hidden="true">' + esc(f.text) + '</span>'
                 + '<span class="b">' + label + esc(text) + '</span>';
    $('#wmsgs').appendChild(li);
    li.scrollIntoView({ block: 'end' });
    return li;
  }

  function fail(msg, rule) {
    var el = $('#werr');
    el.className = 'note ' + (rule ? 'rule' : 'warn');
    el.textContent = msg;
    el.hidden = false;
  }

  function thinking(name) {
    if (name) { $('#wthinkwho').textContent = name + ' is answering'; $('#wthinking').hidden = false; }
    else $('#wthinking').hidden = true;
  }

  function post(action, body) {
    return fetch('watch.php?w=' + encodeURIComponent(W) + '&a=' + action, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); });
  }

  function setPlaying(on) {
    playing = on;
    $('#wplay').textContent = on ? '⏸ pause' : '▶ play';
    if (on) askLine();
  }

  function openScene(sc) {
    SCENE = sc;
    over = false;
    $('#wentry').hidden = true;
    $('#wscene').hidden = false;
    $('#wendbox').innerHTML = '';
    var doing = [];
    [sc.a, sc.b].forEach(function (h) {
      var d = (sc.doing || {})[h];
      if (d) doing.push(sc.names[h] + ': ' + d);
    });
    $('#wshead').innerHTML =
        '<b>' + esc(sc.names[sc.a]) + '</b> and <b>' + esc(sc.names[sc.b]) + '</b>'
      + (sc.place ? ' at <b>' + esc(sc.place) + '</b>' : '') + ' — ' + esc(sc.why) + '.'
      + (doing.length ? '<span class="wsw">' + esc(doing.join(' · ')) + '</span>' : '')
      + '<span class="wsw">' + esc(sc.first) + ' speaks first · about ' + sc.turns + ' lines · this is the '
      + 'duet plus your voice, not the Room</span>';
    $('#wmsgs').innerHTML = '';
    (sc.lines || []).forEach(function (l) {
      addMsg(l.handle === '__you' ? 'me' : 'them', l.name, l.text);
    });
  }

  // -- the loop: playing means asking ----------------------------------------
  function askLine(tries) {
    if (!SCENE || !playing || inflight || over) return;
    if (SCENE.next === null) return;
    tries = tries || 0;
    inflight = true;
    $('#werr').hidden = true;
    thinking(SCENE.next);

    post('line', { a: SCENE.a, b: SCENE.b })
      .then(function (res) {
        if (!res.ok && res.d && res.d.kind === 'queued' && tries < QUEUE_TRIES) {
          // Not an error: a line. Say where we stand and come back by itself.
          $('#wthinkwho').textContent = (res.d.phrase || 'waiting for the model');
          setTimeout(function () { inflight = false; askLine(tries + 1); },
                     Math.max(2, Math.min(60, res.d.retry_after || 10)) * 1000);
          return;
        }
        inflight = false;
        thinking(null);
        if (!res.ok || !res.d.ok) {
          // A rule is drawn calmly and pauses the scene; a fault says try again.
          setPlaying(false);
          fail((res.d && res.d.error) || 'nothing came back', res.d && res.d.kind === 'refused');
          if (res.d && (res.d.who || res.d.text) && res.d.text) addMsg('them', res.d.name || '', res.d.text);
          if (res.d && res.d.kind === 'gone') { over = true; }
          return;
        }
        addMsg('them', res.d.name, res.d.text);
        SCENE.next = res.d.next;
        if (res.d.done) { ended(res.d.closed, null); return; }
        if (playing) setTimeout(askLine, BEAT);
      })
      .catch(function (e) {
        inflight = false;
        thinking(null);
        setPlaying(false);
        fail('the xeric could not be reached, ' + e.message, false);
      });
  }

  // -- the ending card -------------------------------------------------------
  function ended(closed, note) {
    over = true;
    playing = false;
    thinking(null);
    $('#wplay').disabled = true;
    $('#wclose').disabled = true;
    $('#wcomposer').disabled = true;
    $('#wsay').disabled = true;
    var html;
    if (closed) {
      var mems = '';
      Object.keys(closed.memories || {}).forEach(function (h) {
        (closed.memories[h] || []).forEach(function (m) {
          mems += '<p class="wem"><b>' + esc((closed.names || {})[h] || h) + '</b> keeps: ' + esc(m) + '</p>';
        });
      });
      html = '<div class="wend"><p class="weh">the scene landed</p>'
        + '<p class="wet">' + esc(closed.title) + '</p>'
        + '<p class="wep">' + esc(closed.prose) + '</p>'
        + (mems || '<p class="wem">Neither of them kept anything new of it.</p>')
        + ((closed.notes || []).map(function (n) { return '<p class="wem">· ' + esc(n) + '</p>'; }).join(''))
        + '</div>';
    } else {
      html = '<div class="wend"><p class="weh">the scene evaporated</p>'
        + '<p class="wep">' + esc(note || 'Nothing was said, so nothing lands.') + '</p></div>';
    }
    $('#wendbox').innerHTML = html + '<p><a href="watch.php?w=' + encodeURIComponent(W) + '">watch another →</a> · '
      + '<a href="play.php?w=' + encodeURIComponent(W) + '">back to the xeric</a></p>';
    $('#wendbox').scrollIntoView({ block: 'nearest' });
  }

  // -- the walk-in -----------------------------------------------------------
  var pending = null;
  function walkIn() {
    var box = $('#wcomposer'), text = box.value.trim();
    if (!text || !SCENE || over) return;
    $('#werr').hidden = true;
    pending = addMsg('me', ME, text);
    box.value = '';
    post('say', { a: SCENE.a, b: SCENE.b, text: text })
      .then(function (res) {
        if (!res.ok || !res.d.ok) {
          // The line was refused, so it was never said: down it comes, back it
          // goes, with the reason on screen — say.php's own contract.
          if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
          pending = null;
          if (!box.value.trim()) box.value = text;
          fail((res.d && res.d.error) || 'that did not land', res.d && res.d.kind === 'refused');
          return;
        }
        pending = null;
        SCENE.next = res.d.next;
        if (playing) askLine();          // the next speaker answers having seen it
      })
      .catch(function (e) {
        if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
        pending = null;
        if (!box.value.trim()) box.value = text;
        fail('the xeric could not be reached, ' + e.message, false);
      });
  }

  // -- the affirmative close -------------------------------------------------
  function closeScene() {
    if (!SCENE || over) return;
    playing = false;
    $('#wclose').disabled = true;
    thinking(null);
    post('close', { a: SCENE.a, b: SCENE.b })
      .then(function (res) {
        if (!res.ok || !res.d.ok) {
          $('#wclose').disabled = false;
          fail((res.d && res.d.error) || 'the close did not land', false);
          return;
        }
        if (res.d.empty) { ended(null, res.d.note); return; }
        ended(res.d.closed, null);
      })
      .catch(function (e) {
        $('#wclose').disabled = false;
        fail('the xeric could not be reached, ' + e.message, false);
      });
  }

  // -- wiring ----------------------------------------------------------------
  document.querySelectorAll('.wpair').forEach(function (b) {
    b.addEventListener('click', function () {
      $('#wenterr').hidden = true;
      b.disabled = true;
      post('start', { a: b.dataset.a, b: b.dataset.b })
        .then(function (res) {
          b.disabled = false;
          if (!res.ok || !res.d.ok) {
            // The engine's refusal, verbatim: it names where each of them
            // actually is, which is the whole answer to a stale pair.
            $('#wenterr').textContent = (res.d && res.d.error) || 'that scene would not open';
            $('#wenterr').hidden = false;
            return;
          }
          openScene(res.d.scene);
          setPlaying(true);
        })
        .catch(function () {
          b.disabled = false;
          $('#wenterr').textContent = 'the xeric could not be reached';
          $('#wenterr').hidden = false;
        });
    });
  });

  $('#wplay').addEventListener('click', function () { setPlaying(!playing); });
  $('#wclose').addEventListener('click', closeScene);
  $('#wsay').addEventListener('click', walkIn);
  $('#wcomposer').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); walkIn(); }
  });
  $('#wcomposer').addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 128) + 'px';
  });

  if (RESUME) {
    openScene(RESUME);
    setPlaying(false);                   // a reload resumes PAUSED: asking again is a choice
    if (RESUME.next === null) fail('This scene reached its end but was never closed. End it to land it.', false);
  }
})();
</script>
</html>
