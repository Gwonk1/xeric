<?php
/**
 * play.php — a forged world, playable.
 *
 * This is the screen the whole project is selling: you arrive somewhere that was
 * already running, talk to somebody in it, press *skip to evening*, watch the
 * cast do something without you, and get a message about it. Five minutes, on
 * one ordinary GPU.
 *
 * Three things this page is built around, in order of how load-bearing they are:
 *
 *  1. THE TIME CONTROL IS THE PRODUCT. It is above the cast, in the accent
 *     colour, and it is the only thing on the page that is a card. Everything
 *     else here is context for it. A visitor who never presses it has seen a
 *     chat box; a visitor who presses it once has seen the pitch.
 *
 *  2. DIVERGENCE IS SHOWN, NOT CLAIMED. Every event in the feed carries what
 *     each participant took away from it, side by side, because "she remembers
 *     what he did with his hands; he remembers that she left early" is the
 *     single thing that separates this from a random event generator. sweeps.php
 *     refuses an event whose memories are one memory; this is where that refusal
 *     becomes visible.
 *
 *  3. NOTHING HERE DECIDES ANYTHING. The cast's positions are
 *     xeric_world_who_is_where(), the hours are clock.php, what may happen is
 *     sweeps.php, whether a phone buzzes is proactive.php. This file renders and
 *     posts. If a rule about the world appears below, it is in the wrong file.
 *
 * ?w=<slug> is a world. No argument is the shelf of worlds forged here.
 */

declare(strict_types=1);

// review-lib.php for one thing only — whether a world has been launched. It
// pulls play-lib.php in behind it, so this is still the same one include.
require_once __DIR__ . '/review-lib.php';

$slug = xeric_web_slug((string)($_GET['w'] ?? ''));

// ---------------------------------------------------------------------------
// JSON actions — what the page asks for while it is being used
// ---------------------------------------------------------------------------

$action = (string)($_GET['a'] ?? '');
$sid    = xeric_session_id();

// Who this visitor is, what is theirs, and what they have left. No name, no
// address, no account — there is nothing else to return, by construction.
if ($action === 'me') xeric_web_json(xeric_session_me($sid));

// -- the pulse ---------------------------------------------------------------
// WHAT THE SHELF ASKS WHILE SOMEBODY IS LOOKING AT IT. Two facts, both cheap:
// what has been spent, and which xerics are moving. No model, no database
// opened — the running state is read off the files (xeric_play_paused_quick),
// which is why a shelf of eight does not cost eight connections.
//
// IT EXISTS BECAUSE THE HEART IS INVISIBLE. Hours are being lived by a process
// nobody can see, in xerics nobody has open, and a screen that never changes is
// indistinguishable from a screen that is broken. The number climbing IS the
// activity light for the whole app.
if ($action === 'pulse') {
    // The heart's last pass, so the lamps can flicker on the real thing rather
    // than on a timer in the browser pretending to be one.
    $beat = 0;
    $tick = json_decode((string)@file_get_contents(
        (string)xeric_web_config()['data_dir'] . '/heart.tick'), true);
    if (is_array($tick)) $beat = (int)($tick['at'] ?? 0);

    $out = ['tokens' => xeric_web_tokens_by($sid), 'beat' => $beat, 'xerics' => []];
    foreach (xeric_play_worlds($sid) as $x) {
        $live = empty($x['paused']) && !empty($x['launched']) && !empty($x['lived']);
        $row = ['live' => $live, 'stopped' => !empty($x['paused']), 'due' => null, 'lived' => 0];

        // WHEN THE NEXT HOUR LANDS. A xeric sweeps once per window of world time
        // — an hour, usually — so a running one spends nothing for fifty-odd
        // minutes at a stretch, and a meter that does not move is
        // indistinguishable from a meter that is broken. This is the difference
        // said out loud: idle until 22 minutes from now, rather than silence.
        if ($live) {
            try {
                $when = xeric_play_next_hour((string)$x['slug']);
                $row['due']   = $when['due'];
                $row['lived'] = $when['lived'];
            } catch (Throwable $e) {
                // A xeric that will not open is not this poll's problem.
            }
        }
        $out['xerics'][(string)$x['slug']] = $row;
    }
    xeric_web_json($out);
}

if ($action !== '') {
    try {
        $w = xeric_play_open($slug);
    } catch (Throwable $e) {
        xeric_web_json(['error' => $e->getMessage()], 404);
    }

    if ($action === 'state') {
        xeric_web_json(['ok' => true, 'mine' => (bool)$w['mine'], 'queue' => xeric_queue_status()]
            + xeric_play_state($w, $sid));
    }

    // -- the one-time rating confirmation (owner, 2026-08-02) -----------------
    // Worlds forged before every door wrote the rating field carry a rating a
    // MODEL chose. This is the human decision, once: keep it or change it, and
    // either way the world records that a person answered. Through the review
    // save door, so the prev-copy undo and full validation hold like any edit.
    if ($action === 'rating') {
        if (!$w['mine']) xeric_web_json(['error' => 'Only the owner rates a xeric.'], 403);
        $in   = xeric_web_input();
        $want = strtolower(trim((string)($in['rating'] ?? '')));
        if (!in_array($want, xeric_ratings(), true)) {
            xeric_web_json(['error' => '"' . mb_substr($want, 0, 20) . '" is not a rating this shelf knows'], 400);
        }
        // The session's ceiling holds here exactly as it does in the forge: an
        // unaffirmed session can CONFIRM the weakest rating, not raise one.
        $got = xeric_session_rating($want, $sid);
        $t2  = $w['template'];
        $t2['meta']['rating'] = $got;
        $t2['forge']['rating_confirmed'] = true;
        xeric_review_save((string)$w['slug'], $t2);
        xeric_web_json(['ok' => true, 'rating' => $got, 'label' => xeric_rating_label($got),
                        'clamped' => $got !== $want,
                        'note' => 'Takes effect from the next thing anybody says.']);
    }

    // -- the world's own pulse ------------------------------------------------
    // The shelf's pulse says which xerics move; this one says what moved INSIDE
    // the open one, so the sidebar's rooms, the chips and the clock repaint
    // while somebody is looking at them. Without it the panel is a photograph:
    // a skip in another tab, a lived hour, somebody walking to the diner, none
    // of it lands until a reload, and a screen that never changes cannot be
    // told from a screen that is broken.
    if ($action === 'wpulse') {
        $nowW = xeric_clock_now($w['db'], $w['template']);
        $beat = 0;
        $tick = json_decode((string)@file_get_contents(
            (string)xeric_web_config()['data_dir'] . '/heart.tick'), true);
        if (is_array($tick)) $beat = (int)($tick['at'] ?? 0);
        xeric_web_json([
            'ok'        => true,
            'epoch'     => (int)$nowW['epoch'],
            'paused'    => xeric_clock_is_paused($w['db']),
            'beat'      => $beat,
            'tokens'    => xeric_web_tokens_by($sid),
            'side'      => xeric_play_side_html($w['template'], $w['db'], $nowW,
                (string)$w['slug'], (bool)$w['mine']),
            // The compass reads live numbers and lives above the sidebar's body,
            // so it repaints on its own or it is a photograph of the first hour.
            'compass'   => xeric_play_compass_html($w['template'], $w['db'], $nowW),
            'cast_html' => xeric_play_cast_html(
                xeric_play_cast($w['template'], $w['db'], $nowW),
                (string)$w['slug'], (bool)$w['mine']),
        ]);
    }

    // -- you, as the town reads you ----------------------------------------
    // The pill's two halves: the user section of the bible EXACTLY as an
    // unwalled character receives it (same renderer, no summary of it), and
    // the fields behind those sentences, editable through review.php's door.
    // This is how somebody stays in character while changing what the cast
    // can see about them: the sentence and the dial are on one screen.
    if ($action === 'you') {
        if (!$w['mine']) {
            xeric_web_json(['error' => 'This xeric was forged in a different browser. On your own copy, '
                . 'the pill is yours.'], 403);
        }
        $t = $w['template'];
        $u = (array)($t['user'] ?? []);
        $seen = xeric_bible_user($t, [], xeric_world_rating($t));
        xeric_web_json(['ok' => true,
            'seen'   => array_values(array_filter(array_map('strval', $seen), fn($s) => trim($s) !== '')),
            'fields' => [
                ['path' => 'user.name',             'label' => 'your name',      'kind' => 'line',
                 'value' => (string)($u['name'] ?? '')],
                ['path' => 'user.pronouns',         'label' => 'your pronouns',  'kind' => 'pronouns',
                 'value' => (string)($u['pronouns'] ?? '')],
                ['path' => 'user.occupation.title', 'label' => 'what you do',    'kind' => 'line',
                 'value' => (string)($u['occupation']['title'] ?? '')],
                ['path' => 'user.occupation.hours', 'label' => 'your hours',     'kind' => 'line',
                 'value' => (string)($u['occupation']['hours'] ?? '')],
                ['path' => 'user.location',         'label' => 'where you are from', 'kind' => 'line',
                 'value' => (string)($u['location'] ?? '')],
                ['path' => 'user.quiet_hours',      'label' => 'when nobody reaches you', 'kind' => 'line',
                 'value' => (string)($u['quiet_hours'] ?? '')],
                ['path' => 'user.bio',              'label' => 'your bio, in your own words', 'kind' => 'text',
                 'value' => (string)($u['bio'] ?? '')],
            ]]);
    }

    // -- one character, shaped for the cog --------------------------------
    // Values and paths only: the saves and the dice go through review.php,
    // which is where the ownership check, the age floor, the undo copy and the
    // learning signal already live. This endpoint exists so the modal can open
    // instantly with what is true NOW rather than what the page loaded with.
    if ($action === 'char') {
        if (!$w['mine']) {
            xeric_web_json(['error' => 'This xeric was forged in a different browser, so its people are not '
                . 'yours to change.'], 403);
        }
        $h = (string)($_GET['h'] ?? '');
        $t = $w['template'];
        $idx = null; $c = null;
        foreach ((array)($t['cast']['characters'] ?? []) as $i => $row) {
            if ((string)($row['handle'] ?? '') === $h) { $idx = (int)$i; $c = (array)$row; break; }
        }
        if ($c === null) xeric_web_json(['error' => 'Nobody in this xeric goes by that handle.'], 404);

        $base = 'cast.characters.' . $idx . '.';
        // Only what is actually in the record: a field the character never had
        // would be refused by the save, so it is not offered by the form.
        $grow = ['pronouns' => true, 'short_name' => true];
        $offer = function (string $tail, string $label, string $kind, bool $dice) use ($t, $base, $grow): ?array {
            $v = xeric_review_get($t, $base . $tail);
            if ($v === null && !isset($grow[$tail])) return null;
            return ['path' => $base . $tail, 'label' => $label, 'kind' => $kind,
                    'dice' => $dice, 'value' => is_scalar($v) ? (string)$v : ''];
        };
        $fields = array_values(array_filter([
            $offer('display_name',             'name',                       'line', true),
            // Blank here is not missing: xeric_play_short_name() falls back to
            // the nickname inside their name, then their first name.
            $offer('short_name',               'what people call them',      'line', false),
            $offer('pronouns',                 'pronouns',                   'pronouns', false),
            $offer('age',                      'age',                        'int',  true),
            $offer('orbit',                    'circle',                     'orbit', false),
            $offer('one_line',                 'the roster line',            'line', true),
            $offer('surface',                  'what strangers see',         'line', true),
            $offer('appearance',               'appearance',                 'text', true),
            $offer('voice',                    'voice',                      'text', true),
            $offer('solace',                   'what steadies them',         'text', true),
            $offer('psyche.sore_spot',         'sore spot',                  'line', true),
            $offer('psyche.jealousy',          'what stings',                'line', true),
            $offer('psyche.self_soothe',       'how they cope',              'line', true),
            $offer('psyche.praise_that_lands', 'praise that lands',          'line', true),
            $offer('drives.pull',              'what pulls them',            'line', true),
            $offer('temperature',              'heat',                       'num',  false),
        ]));

        $orbits = [];
        foreach ((array)($t['cast']['orbits'] ?? []) as $o) {
            $k = (string)($o['key'] ?? '');
            if ($k !== '') $orbits[] = ['key' => $k, 'label' => (string)($o['label'] ?? $k)];
        }

        xeric_web_json(['ok' => true, 'handle' => $h,
            'name'   => (string)($c['display_name'] ?? $h),
            'face'   => xeric_play_face($c),
            'orbits' => $orbits,
            // The voice machine: the model picker, smaller and integrated —
            // only ACTIVE (wired) machines are offered, plus the engine
            // default. Sam can be one model and Lucy another; the per-model
            // prefix cache makes this cheaper than it sounds, because a
            // character pinned to a machine keeps their prefix warm THERE.
            'voice'  => xeric_voice_choices($w['db'], $h, $sid),
            // The portrait's stand-in words, straight off the job row: the alt
            // text for a developed photo, the whole photo for a pending one.
            'photo_caption' => (string)((xeric_photo_of($w['db'], 'portrait', $h)['caption'] ?? '')),
            'fields' => $fields]);
    }

    // -- which model speaks for whom ------------------------------------------
    if ($action === 'voice') {
        if (!$w['mine']) xeric_web_json(['error' => 'Not yours to retune.'], 403);
        $in = xeric_web_input();
        $h  = trim((string)($in['h'] ?? ''));
        if (xeric_world_character($w['template'], $h) === null) {
            xeric_web_json(['error' => 'nobody by that name'], 400);
        }
        try {
            $label = xeric_voice_set($w['db'], $h, (string)($in['base'] ?? ''), $sid);
        } catch (Throwable $e) {
            xeric_web_json(['error' => $e->getMessage()], 422);
        }
        xeric_web_json(['ok' => true, 'label' => $label,
                        'note' => 'From the next thing they say. Their first answer on a new machine '
                                . 'takes longer — a fresh model reads them from the top.']);
    }

    // -- the narrator --------------------------------------------------------
    // THE ONE THING THAT CAN SEE THE BOARD. Every character knows only what they
    // know — that is the engine, and the walls exist to keep it true. This is not
    // a character: it is the thing a player can ask when they are lost, and
    // seeing everything is what makes it able to answer. What it may not do is
    // SPEND the story, which is xeric_play_hint()'s problem and is written into
    // its prompt: it points, it does not tell.
    if ($action === 'hint') {
        try { $endpoint = xeric_play_endpoint($sid); }
        catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409); }

        $hold = xeric_queue_take('say', 6.0, 'hint:' . $slug);
        if (($hold['ok'] ?? false) !== true) {
            xeric_web_json(['error' => 'The model is busy. Ask again in a moment.', 'kind' => 'busy'], 503);
        }
        try {
            $line = xeric_play_hint($w['template'], $w['db'],
                                    (string)($_GET['ask'] ?? ''), $endpoint);
        } catch (Throwable $e) {
            xeric_queue_release($hold);
            xeric_web_json(['error' => 'nothing came back', 'kind' => 'model'], 502);
        }
        xeric_queue_release($hold);
        if (trim($line) === '') xeric_web_json(['error' => 'nothing came back'], 502);
        xeric_web_json(['ok' => true, 'text' => $line]);
    }

    // -- a line you might say ----------------------------------------------
    // ASKED FOR, NEVER OFFERED. It costs a model call and it puts words in
    // somebody's mouth, so nothing about it happens until they press the key —
    // an autocomplete that appears on its own is a co-author nobody hired.
    //
    // WHAT IT IS FOR is getting past the blank box, so the prompt asks for a
    // line that OPENS something: a question, a request, a thing admitted. A
    // suggestion that closes the conversation politely is the one thing worse
    // than no suggestion, because it is easier to accept than to reject.
    if ($action === 'suggest') {
        $handle = trim((string)($_GET['h'] ?? ''));
        $c = xeric_world_character($w['template'], $handle);
        if ($c === null) xeric_web_json(['error' => 'nobody by that name'], 400);
        if (isset(xeric_deaths($w['db'])[$handle])) {
            xeric_web_json(['error' => 'they are dead'], 409);
        }

        try { $endpoint = xeric_play_endpoint($sid); }
        catch (Throwable $e) { xeric_web_json(['error' => $e->getMessage(), 'kind' => 'detached'], 409); }

        $hold = xeric_queue_take('say', 6.0, 'suggest:' . $slug);
        if (($hold['ok'] ?? false) !== true) {
            xeric_web_json(['error' => 'the model is busy', 'kind' => 'busy'], 503);
        }
        try {
            $line = xeric_play_suggest($w['template'], $w['db'], $handle, $endpoint);
        } catch (Throwable $e) {
            xeric_queue_release($hold);
            xeric_web_json(['error' => $e->getMessage(), 'kind' => 'model'], 502);
        }
        xeric_queue_release($hold);

        if (trim($line) === '') xeric_web_json(['error' => 'nothing came back'], 502);
        xeric_web_json(['ok' => true, 'text' => $line]);
    }

    if ($action === 'thread') {
        $handle = trim((string)($_GET['h'] ?? ''));
        if (xeric_world_character($w['template'], $handle) === null) {
            xeric_web_json(['error' => 'Nobody in this xeric answers to that name any more. If somebody was '
                . 'rerolled while this page was open, reload it, the cast has changed under you.'], 400);
        }
        // What was waiting, read before the thread puts the dot out.
        $conv   = xeric_conversation_find($w['db'], $handle, 'chat');
        $unread = $conv !== null ? (int)$conv['unread'] : 0;

        $th = xeric_play_thread($w['template'], $w['db'], $handle);

        // Opening somebody's thread is the cheapest thing a visitor does and the
        // only one that says "this person, not that one". Recorded here rather
        // than in the engine for the same reason unread is put out here: whether
        // somebody is LOOKING at a thread is a fact about this screen, not about
        // the world. Never for an empty thread — a tap that opened nothing says
        // nothing (learn.php).
        if ($th['conversation_id'] !== null) {
            xeric_learn_read($w['db'], $handle, $unread, xeric_clock_epoch($w['db']));
        }

        $c    = xeric_world_character($w['template'], $handle);
        $gone = xeric_deaths($w['db'])[$handle] ?? null;
        xeric_web_json(['ok' => true, 'handle' => $handle,
            'name' => (string)($c['display_name'] ?? $handle),
            // The thread of somebody who is dead OPENS. It scrolls, it keeps every
            // word, and only the composer is closed — reading back the last thing
            // they said to you is the whole reason the row is still on the roster.
            'dead' => $gone !== null,
            'how'  => $gone !== null ? (string)$gone['how'] : '',
            'one_line' => (string)($c['one_line'] ?? '')] + $th);
    }

    xeric_web_json(['error' => 'no such action'], 400);
}

// ---------------------------------------------------------------------------
// No world named — the shelf
// ---------------------------------------------------------------------------

if ($slug === '') {
    $worlds = xeric_play_worlds($sid);
    xeric_web_head('Xeric');
    echo '<style>' . xeric_play_css() . xeric_play_shelf_css() . '</style>';
    ?>
<body class="shelf">
<div class="shelfwrap">
  <h1 class="mark">XERIC</h1>
  <div class="grid"><?= xeric_play_shelf_html($worlds) ?></div>
  <?php
    // THE CORNER NAMES THE STATE, NOT THE ACTION. It used to say "change model"
    // when attached and "no machine attached" when not — one of those is a verb
    // and the other is a fact, so the only way to read the state was to notice
    // which sentence you were looking at. Both are facts now, and the fact worth
    // showing is WHICH machine: an address with a light on it, exactly as the
    // machines screen says it.
    $mm  = xeric_web_model($sid);
    $att = xeric_web_connected($mm);
    $at  = $att ? (string)preg_replace('#^https?://#', '',
              rtrim((string)(($mm['local'] ?? '') ?: ($mm['base'] ?? '')
                    ?: xeric_web_config()['local_base']), '/')) : '';
  ?>
  <p class="corner">
    <a href="model.php" class="cmodel<?= $att ? '' : ' off' ?>"<?= $att ? ' data-probe="1"' : '' ?>>
      <span class="lamp"></span><?= $att ? h($at) : 'no machine attached' ?></a>
    · <a href="notify.php">notify</a> · <?= xeric_web_meter_html($sid) ?></p>
</div>
<script>
  // THE ONLY SCRIPT ON THIS SCREEN, and it asks one question: is the machine
  // answering? So a model that has died is visible from the front door, rather
  // than only from the screen somebody opens BECAUSE they suspect it died.
  //
  // It lives here rather than in the world page's script because this branch
  // exits before that one is ever printed — which is how the first version came
  // to be bound to an element on a page it never ran on.
  (function () {
    var link = document.querySelector('.cmodel[data-probe]');
    if (!link) return;
    fetch('model.php?a=probe&i=0')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.up === null) return;                    // remote: nothing honest to say
        link.querySelector('.lamp').classList.add(d.up ? 'up' : 'down');
        if (!d.up) { link.classList.add('off'); nowhereToForge(); }
      })
      .catch(function () {});
  })();

  // ATTACHED IS NOT THE SAME AS WORKING. The server can only know whether a
  // machine was CHOSEN; whether it answers takes a request, and the front door
  // is not the place to spend one before it draws. So the plus is aimed at the
  // interview on the way out, and the probe above — already in flight for the
  // lamp — re-aims it at the machines screen if nothing came back. No extra
  // request, and the answer arrives in the second before anybody clicks.
  // -- the pulse -------------------------------------------------------------
  // THE HEART IS INVISIBLE, and a screen that never changes cannot be told from
  // a screen that is broken. Hours are being lived by a process nobody can see,
  // in xerics nobody has open — so the shelf asks every few seconds what has
  // been spent and what is moving, and the number climbing is the activity light
  // for the whole app.
  //
  // NOT WHILE NOBODY IS LOOKING. A tab in the background is a tab that does not
  // need to know, and a laptop lid is not a reason to keep making requests.
  var PULSE_MS = 8000;
  var pulseAt = 0;
  var lastBeat = 0;

  function pulse() {
    if (document.hidden) return;
    fetch('play.php?a=pulse')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.tokens && window.xericMeterFeed) window.xericMeterFeed(d.tokens);

        // A HEARTBEAT YOU CAN SEE. The heart stamps a file every pass, so this
        // flickers on the real thing — and only on a xeric that is actually
        // running, because a red lamp flashing would be claiming a pulse for
        // something with none.
        //
        // Not on the first poll: arriving at a page is not a heartbeat, and a
        // flash on load would be the browser performing liveness rather than
        // reporting it.
        if (d.beat && lastBeat && d.beat !== lastBeat) {
          document.querySelectorAll('.tlamp.on').forEach(function (l) {
            l.classList.remove('beat');
            void l.offsetWidth;                  // restart the animation
            l.classList.add('beat');
          });
        }
        if (d.beat) lastBeat = d.beat;

        var x = d.xerics || {};
        document.querySelectorAll('.tile[data-slug]').forEach(function (t) {
          var s = x[t.dataset.slug];
          if (!s) return;
          var lamp = t.querySelector('.tlamp');
          if (lamp) {
            lamp.classList.toggle('on', !!s.live);
            // WHY IT IS QUIET, in the one place somebody already looks to ask.
            // "Running" and "running, and the next hour lands in 20 minutes" are
            // the same fact; only the second one survives being stared at.
            lamp.title = !s.live ? lamp.title
              : (s.due === 0 ? 'running, an hour is due to be lived now'
                             : 'running, next hour in ' + Math.ceil((s.due || 0) / 60)
                               + ' min' + (s.lived ? '  (' + s.lived + ' lived so far)' : ''));
          }
          t.classList.toggle('stopped', !!s.stopped);

          // And on the tile itself, because a tooltip is not an answer on a
          // phone and this is the question the shelf gets asked most: is it
          // doing anything.
          var due = t.querySelector('.tdue');
          if (!due && s.live) {
            due = document.createElement('span');
            due.className = 'tdue';
            t.appendChild(due);
          }
          if (due) {
            due.hidden = !s.live;
            if (s.live) {
              due.textContent = s.due === 0 ? 'due now'
                              : 'next hour ' + Math.ceil((s.due || 0) / 60) + 'm';
            }
          }
        });
      })
      .catch(function () {});
  }

  // ONCE ON ARRIVAL, then on the interval. Waiting the first eight seconds meant
  // a shelf that had just been opened was showing lamps from whenever the page
  // was rendered — and the whole point of them is that they are current. It is
  // also what seeds lastBeat, so the first real tick after this is the first
  // thing that flickers.
  pulse();
  pulseAt = setInterval(pulse, PULSE_MS);
  // Straight away on coming back, so a tab someone returns to is not showing
  // them a number from before lunch.
  document.addEventListener('visibilitychange', function () { if (!document.hidden) pulse(); });

  function nowhereToForge() {
    var plus = document.querySelector('.tile.new');
    if (!plus) return;
    plus.setAttribute('href', 'model.php');
    plus.setAttribute('title', 'Set up a machine first');
    plus.setAttribute('aria-label', 'Set up a machine first');
  }
</script>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// A world
// ---------------------------------------------------------------------------

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    // Two different failures wearing one message, so they are told apart here:
    // a world that was never here, and a world that was and has been let go.
    $gone = !is_dir(xeric_web_worlds_dir() . '/' . $slug);
    xeric_web_head('Xeric: ' . $slug);
    echo '<style>' . xeric_play_css() . '</style>';
    echo '<main><div class="top"><p class="wordmark">XERIC</p><span class="kicker">play</span></div>';
    echo '<h1>That xeric will not open</h1>';
    echo '<p class="note bad">' . h($e->getMessage()) . '</p>';
    echo $gone
        ? '<p>Xerics forged in this demo are kept for seven days after their maker\'s last visit, and then '
          . 'let go with everything in them. If this one was yours and you have been away a week, that is '
          . 'what happened to it, nothing is broken, and forging another takes three minutes.</p>'
        : '<p>The xeric\'s folder is still on disk, so this is its template rather than the xeric itself. '
          . 'Nothing you did caused it and nothing else here is affected.</p>';
    echo '<p><a href="play.php">The other xerics →</a> · <a href="forge.php?fresh=1">Forge a new one →</a></p></main>';
    exit;
}

// -- has anybody launched this world? ----------------------------------------
// FORGE.md principle 3: review before launch, always — and skippable. So an
// unlaunched world is not refused, it is asked about, and "go in anyway" is one
// tap. Somebody ELSE's unlaunched world is refused: they are still working on it.
if (!xeric_review_launched($w['template'])) {
    $mine = $w['mine'];
    xeric_web_head('Xeric: ' . (string)$w['template']['meta']['name']);
    echo '<style>' . xeric_play_css() . '</style>';
    echo '<main><div class="top"><p class="wordmark">XERIC</p><span class="kicker">play</span>'
        . xeric_web_meter_html() . '<span class="count"><a href="play.php">all xerics</a></span></div>';
    echo '<h1>' . h((string)$w['template']['meta']['name']) . '</h1>';
    if (!$mine) {
        echo '<p class="note">Somebody is still building this one. It has not been launched, so it is not '
            . 'open yet, the xerics that are will be on the shelf.</p>'
            . '<p><a href="play.php">The xerics that are open →</a></p></main>';
        exit;
    }
    echo '<p class="sub lead">This xeric has been forged but not launched. Nothing in it has started keeping '
        . 'time yet, and nobody has been spoken to.</p>'
        . '<p class="note">Read it first and you can retype anything that is wrong and reroll anything that is '
        . 'dull, one person at a time, without rebuilding the world. Or go straight in; you can come back '
        . 'and change it at any point.</p>'
        . '<p><a class="enter" href="review.php?w=' . h(rawurlencode($w['slug'])) . '">Review it before it starts</a></p>'
        . '<form method="post" action="review.php?a=launch&amp;w=' . h(rawurlencode($w['slug'])) . '">'
        . '<button class="btn ghost wide" id="skiplaunch" type="submit">Skip the review, launch it and go in</button>'
        . '</form>'
        . '<script>document.getElementById("skiplaunch").addEventListener("click",function(e){e.preventDefault();'
        . 'fetch("review.php?a=launch",{method:"POST",headers:{"Content-Type":"application/json"},'
        . 'body:JSON.stringify({xeric:' . json_encode($w['slug']) . '})}).then(function(r){return r.json()})'
        . '.then(function(d){if(d.ok){window.location=d.url}else{alert(d.error||"that xeric would not launch")}})'
        . '.catch(function(){window.location.reload()})});</script>'
        . '</main>';
    exit;
}

$T     = $w['template'];
$db    = $w['db'];
$now   = xeric_clock_now($db, $T);
$state = xeric_play_state($w, $sid);
$spans = $state['spans'];
$queue = xeric_queue_status();

$sess   = xeric_web_session_read($sid);
$resume = xeric_play_job_of($sess, $w['slug']);

$userName = trim((string)($T['user']['name'] ?? '')) ?: 'you';
$userJob  = trim((string)($T['user']['occupation']['title'] ?? ''));
$userWork = xeric_world_place_name($T, (string)($T['user']['occupation']['workplace_key'] ?? ''));

xeric_web_head('Xeric: ' . (string)$T['meta']['name']);
echo '<style>' . xeric_play_css() . '
/* ── the ⚙ cog and its modal rows (xeric controls) ── */
/* color set EXPLICITLY: a button never inherits text colour, it takes the
   browser ButtonText default, black, which on the dark theme is invisible. */
/* The two controls are deliberately narrow. A 17rem sidebar minus a 44px cog
   minus a delete button leaves about 165px for the name, and "Oakhaven Bend"
   wrapped onto two lines the moment the second button arrived. The fingertip
   target is kept by the height and the negative margins, not by the width. */
.snamerow { display:flex; align-items:center; gap:.15rem; }
.snamerow .sname { margin-bottom:0; min-width:0; }
.snamerow .xcog { background:none; border:0; cursor:pointer; font-size:18px; line-height:1;
             color:var(--fg); min-width:34px; min-height:40px; margin-left:auto; opacity:.75;
             padding:0; margin-top:-8px; margin-bottom:-8px; }
.snamerow .xcog:hover { opacity:1; }
/* Red, and only red on approach: a permanent scarlet cross beside the name of
   the world reads as an error state on a page where nothing is wrong. */
.snamerow .xdel { background:none; border:0; cursor:pointer; font-size:14px; line-height:1;
             color:var(--fg-far); min-width:28px; min-height:40px; padding:0;
             margin-top:-8px; margin-bottom:-8px; }
.snamerow .xdel:hover, .snamerow .xdel:focus-visible { color:var(--bad); outline:none; }
/* the way back to the shelf rides the brand row, hard right of it and lifted
   clear of the "play" baseline so it reads as a control and not a subtitle */
.sbrand .sxall { margin-left:auto; position:relative; top:-6px; white-space:nowrap;
             font-size:.78rem; color:var(--fg-dim); }
.sbrand .sxall:hover { color:var(--fg); }
.xcnav { display:flex; gap:8px; flex-wrap:wrap; margin:10px 0 14px; }
/* the portrait at the top of a cog, when the reaper has developed one */
.cportrait { margin:0 0 12px; }
.cportrait img { max-width:100%; max-height:240px; border-radius:8px; display:block; }
.xcrow { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0 0 10px; }
.xchint { font-size:12px; opacity:.65; flex:1 1 200px; }
</style>';
?>
<!-- THE CHIP BAR, ACROSS THE WHOLE WINDOW. Everybody, always, so a thread is a
     thing you switch rather than a page you leave. It used to live inside main,
     which meant the people were confined to the pane right of the sidebar and
     twelve of them — the number this is built for — had two thirds of the screen
     to fit in. It is the app's primary navigation, so it gets the app's width:
     above the shell, sticky at the top, with the sidebar hanging below it.
     The burger is the drawer on a phone and carries the unread count; the + and
     the narrator are pinned at the far right, always in the same place. -->
<div class="chipbar" id="chipbar">
  <button type="button" class="burger" id="burger" aria-label="who is where"><span></span><span></span><span></span><b class="bbadge" id="bbadge" hidden>0</b></button>
  <div class="chips" id="chips"></div>
  <?php if ($w['mine']): ?>
  <button type="button" class="chip addchip" id="addchar1" title="write somebody new into this xeric">+</button>
  <?php endif; ?>
  <button type="button" class="chip narrchip" id="asknarr3" title="the narrator">?</button>
</div>

<!-- THE SHELL. Sidebar and main, side by side on anything wide enough and a
     slide-in drawer on a phone. It exists because a messenger without one is a
     series of pages: opening a thread hid the cast, so there was no moment where
     you could see who else was about while you were talking to somebody — and a
     xeric is a place with people moving around it, which had nowhere to live. -->
<div id="app">
  <aside id="side">
    <!-- The cog rides the brand row the eye actually finds: on anything wide,
         main's own wordmark is deduplicated away and THIS is the only
         "XERIC play" on screen — a cog next to a hidden wordmark is a cog
         nobody can see. The main top keeps its twin. -->
    <div class="sbrand"><p class="wordmark">XERIC</p><span class="kicker">play</span>
      <a class="sxall" href="play.php">all xerics</a></div>
    <!-- The name lives with the calendar: one column that says where you are,
         when it is, and what it has cost — main's own bar is gone entirely.
         The cog rides the NAME and not the brand: stopping the clock, skipping
         time and the literary repass are things you do to THIS xeric, not to
         the app, and a control belongs beside the thing it acts on. -->
    <div class="snamerow">
      <h1 class="sname"><?= h($T['meta']['name']) ?></h1>
      <button type="button" class="xcog" id="xericcog2" title="<?= h((string)$T['meta']['name']) ?> controls" aria-label="xeric controls">⚙</button>
      <?php if ($w['mine']): ?>
      <!-- The one control on this page with nothing behind it, so it is the one
           control that looks like what it is. Beside the name because that is
           what it deletes — not the app, not the shelf, this xeric. -->
      <button type="button" class="xdel" id="xericdel"
              title="delete <?= h((string)$T['meta']['name']) ?>" aria-label="delete this xeric">✕</button>
      <?php endif; ?>
    </div>
    <!-- The compass: three readings that move, so the sidebar says where this
         story stands and not only what it was called. -->
    <div id="scompass"><?= xeric_play_compass_html($T, $w['db']) ?></div>
    <!-- The quick buttons moved into the ⚙ above; what lives here now is the
         world's own calendar. The year is the era's (a 1990s town never
         prints 2026 on itself); day, month and weekday flow from the clock. -->
    <div class="sstatus">
      <p class="sdate" id="sdate"><?= h(xeric_play_date_line($T, $now)) ?></p>
      <span class="clock<?= $state['clock']['paused'] ? ' stopped' : '' ?>" id="sclock"><span class="ph"></span><span
        id="sclocktext" class="ckhm"><?= h((string)$now['hhmm']) ?></span></span>
      <!-- The meter keeps the clock company: time passing and tokens burning
           are the same fact about this machine, read in one glance. -->
      <div class="smeter"><?= xeric_web_meter_html() ?></div>
    </div>
    <div id="sidebody"><?= xeric_play_side_html($T, $w['db'], $now, (string)$w['slug'], (bool)$w['mine']) ?></div>
    <div class="sfoot">
      <button type="button" class="person narrator" id="asknarr2">
        <span class="pn">the narrator</span>
        <span class="po">Ask when you are lost.</span>
      </button>
    </div>
  </aside>
  <div id="sidescrim" hidden></div>

<main>
  <!-- Brand only, and only where the sidebar is a drawer: the meter, the cog
       and the way back to the shelf all live beside the sidebar's clock now,
       which is the corner of the screen that means "the machine". -->
  <div class="top">
    <p class="wordmark">XERIC</p>
    <span class="kicker">play</span>
  </div>

  <!-- ------------------------------------------------------------- world -->
  <section class="screen live" data-screen="xeric">
    <!-- No bar at all: the name and the clock live in the sidebar's calendar
         corner, and this screen opens straight onto who you are and who is
         here. -->
    <p class="whoami">You are <b><?= h($userName) ?></b><?php
      if ($userJob !== '') echo ', ' . h($userJob);
      if ($userWork !== '') echo ' at ' . h($userWork);
    ?>.<?php
      // …and among whom. A name and a job describe a person alone in a room;
      // the roster underneath is four strangers until this line says which of
      // them you see every day and which you merely know.
      $rel = xeric_play_relations($T, $db);
      if ($rel !== '') echo ' <span class="whorel">' . $rel . '</span>';
    ?></p>

    <!-- whose world this is, and what is left of the demo's day. Quiet on
         purpose: a limit nobody can see is a limit that feels like a bug. -->
    <p class="yours" id="yours"><?= $state['you_html'] ?></p>

    <?php if ($w['forked']): ?>
      <p class="note">You have just been given your own copy of this xeric, the same people, the same
        six weeks behind them, and from here on your own evening. The xeric it was copied from carries on
        without you.</p>
    <?php elseif ($w['fresh'] && (int)$w['seeded']['events'] > 0): ?>
      <p class="note">This xeric had never been opened. Its past has just been written in, <?= (int)$w['seeded']['events'] ?> things that already happened and
        <?= (int)$w['seeded']['memories'] ?> memories the cast were already carrying.</p>
    <?php endif; ?>

    <!-- THE FRONT DOOR IS THE PEOPLE. Everything else about a xeric — how it
         moves, where you are, what death means, what has already happened — is
         a thing you go and look at. Opening one used to land on all of it at
         once, as a document with the cast a scroll and a half down: correct as
         a reference, wrong as a way in. -->
    <ul class="cast" id="cast"><?= $state['cast_html'] ?></ul>

    <!-- ALWAYS LAST, ALWAYS THERE. Not one of the cast — it is the thing you ask
         when you do not know who to ask. It sits after them because it is not a
         person you might text; it is the way out of being stuck. -->
    <!-- The door somebody walks in through. Beside the narrator because that is
         where the roster stops being people and starts being the machine, and
         because the two of them are the same kind of act: one asks the world a
         question, the other adds to it. -->
    <ul class="cast narrbox">
      <?php if ($w['mine']): ?>
      <li><button type="button" class="person addperson" id="addchar2">
        <span class="pn">+ somebody new</span>
        <span class="po">Write another person into this xeric. They arrive tonight, with a past.</span>
      </button></li>
      <?php endif; ?>
      <li><button type="button" class="person narrator" id="asknarr">
        <span class="pn">the narrator</span>
        <span class="po">Knows where everything stands. Ask when you are lost.</span>
      </button></li>
    </ul>

    <p class="note bad" id="staleerr" hidden></p>
  </section>

  <!-- --------------------------------------------------------- the narrator -->
  <section class="screen" data-screen="narr">
    <div class="pbar">
      <div class="pclock">
        <h1 class="pname">the narrator</h1>
        <span class="clock"><span class="ph"></span><span id="nclock" class="cktxt"><?= h(xeric_play_when($now)) ?></span></span>
      </div>
      <nav class="pnav">
        <button class="nbtn" type="button" data-back="1">← the cast</button>
        <button class="nbtn" type="button" id="nagain">ask again</button>
      </nav>
    </div>

    <ul class="msgs" id="nmsgs"></ul>
    <div class="thinking" id="nthinking" hidden><i></i><i></i><i></i><span>looking at where things stand</span></div>
    <p class="note warn" id="nerr" hidden></p>
    <div class="composer">
      <textarea id="ncomposer" rows="1" placeholder="what are you stuck on?" autocomplete="off"></textarea>
      <button class="btn" type="button" id="nsend">Ask</button>
    </div>
  </section>

  <!-- ------------------------------------------------------------- the book -->
  <!-- The same document it always was, now a PAGE inside the app rather than
       the app itself. The bar's buttons bring you here and to the right part of
       it; the back button takes you to the people. -->
  <section class="screen" data-screen="world">
    <div class="pbar">
      <div class="pclock">
        <h1 class="pname"><?= h($T['meta']['name']) ?></h1>
        <span class="clock<?= $state['clock']['paused'] ? ' stopped' : '' ?>" id="bclock"><span class="ph"></span><span
          id="bclocktext" class="cktxt"><?= h(xeric_play_when($now)) ?></span></span>
      </div>
      <nav class="pnav">
        <button class="nbtn" type="button" data-back="1">← the cast</button>
        <button type="button" class="nbtn" data-to="times">skip time</button>
        <button type="button" class="nbtn" data-to="where">where</button>
        <button type="button" class="nbtn" data-to="past">what happened</button>
        <button type="button" class="nbtn leave" id="leave">leave</button>
      </nav>
    </div>

    <!-- THE EXIT ASKS THE ONE QUESTION LEAVING RAISES (owner, 2026-08-02):
         does the world keep living while you are gone, or does it hold its
         breath? Both answers are honest — the heart lives unattended hours,
         and the pause is the same clock-stop the power corner uses — and the
         question is asked HERE, at the door, because that is the moment it is
         actually about. Leaving by tab-close keeps the world running, which
         has always been the default and still is. -->
    <div class="leavecard" id="leavecard" hidden>
      <p><strong>You're leaving.</strong> Should the world keep living while
        you're gone — shifts, evenings, people talking about you — or should it
        hold still until you're back?</p>
      <div class="leavebtns">
        <a class="nbtn" href="play.php">keep it running →</a>
        <button type="button" class="nbtn" id="leavepause">pause it first</button>
        <button type="button" class="nbtn ghost" id="leavestay">stay</button>
      </div>
      <p class="note warn" id="leaveerr" hidden></p>
    </div>

    <noscript><div class="noscript"><strong>The time control needs JavaScript.</strong>
      Moving a xeric takes a minute of model calls and streams what happens as it happens.</div></noscript>

    <?php if ($w['mine'] && empty($t['forge']['rating_confirmed'])): ?>
    <!-- THE ONE-TIME RATING CONFIRMATION. This world predates the doors that
         write the rating field, so its rating may be a model's choice, not a
         person's. Asked ONCE, here, because the play view is where the owner
         actually returns — and any press, including "keep", is the answer. -->
    <div class="note" id="ratebanner">
      <p>This xeric is rated <strong><?= h(xeric_rating_label((string)($t['meta']['rating'] ?? 'sfw'))) ?></strong>
        — a choice the forge may have made for you. Confirm it, or set it:</p>
      <div class="ratebtns">
        <?php $cur = (string)($t['meta']['rating'] ?? 'sfw'); foreach (xeric_ratings() as $rv): ?>
        <button type="button" class="nbtn<?= $rv === $cur ? ' on' : '' ?>" data-rate="<?= h($rv) ?>">
          <?= h(xeric_rating_label($rv)) ?><?= $rv === $cur ? ' · keep' : '' ?>
        </button>
        <?php endforeach; ?>
      </div>
      <p class="why" id="ratenote">Style follows the rating: a TV-PG xeric is written like one.
        Takes effect from the next thing anybody says.</p>
    </div>
    <?php endif; ?>

    <?php if ($w['mine'] && xeric_photo_offer($w['db'])): ?>
    <!-- THE FIRST-HOOKUP OFFER. An image machine just answered for the first
         time and this world's photo jobs are waiting. Asked ONCE — either
         button stamps photos.asked, and only yes stamps photos.approved,
         because asking is not consent. Renders cost real compute and the count
         is printed before the question, so the yes is an informed one. -->
    <div class="note" id="photobanner">
      <p>An image machine is answering. This xeric has
        <strong><?= count(xeric_photo_jobs($w['db'], 'pending')) ?></strong> photographs waiting —
        the cast's portraits and the places. Develop them now?</p>
      <div class="ratebtns">
        <button type="button" class="nbtn" id="photoyes">Develop the film</button>
        <button type="button" class="nbtn ghost" id="photono">Not for this xeric</button>
      </div>
      <p class="why">Renders spend real compute, counted on the meter like everything else.
        Faces and places keep their seeds, so the same person is the same person in every frame.</p>
    </div>
    <?php endif; ?>

    <!-- the centrepiece -->
    <div class="timecard">
      <h2>move the xeric</h2>
      <p class="why">Nobody is waiting for you. Skip ahead and see what they did without you.</p>
      <div class="times" id="times">
        <?php foreach ($spans as $s): ?>
        <button type="button" class="tbtn" data-span="<?= h($s['key']) ?>"<?= $state['clock']['paused'] ? ' disabled' : '' ?>>
          <span class="tl"><?= h($s['label']) ?></span>
          <span class="ts"><?= h($s['span'] . ' · to ' . $s['to']) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php $rw = $state['rewind'] ?? null; if ($rw !== null): ?>
      <!-- THE REWIND (owner, 2026-08-02). Offered only while the window is
           open — same guards as the engine — and never without the warning,
           in the owner's words: experimental and destructive. The card states
           the physics before the button will fire: the world un-remembers,
           the player does not. -->
      <div class="rewindrow" id="rewindrow">
        <button type="button" class="tbtn rwbtn" id="rewindbtn">
          <span class="tl">⏪ take back the <?= h((string)$rw['span']) ?></span>
          <span class="ts">the last skip, un-happened</span>
        </button>
        <div class="leavecard" id="rewindcard" hidden>
          <p><strong>Experimental and destructive.</strong> The world un-remembers
            those hours — <?= (int)$rw['events'] ?> events, <?= (int)$rw['memories'] ?> memories,
            <?= (int)$rw['messages'] ?> messages — and will live them again, differently.
            You keep what you know; they keep nothing. There is no undo of the undo.</p>
          <div class="leavebtns">
            <button type="button" class="nbtn" id="rewindgo">take it back</button>
            <button type="button" class="nbtn ghost" id="rewindstay">never mind</button>
          </div>
          <p class="note warn" id="rewinderr" hidden></p>
        </div>
      </div>
      <?php endif; ?>
      <p class="note warn" id="tickerr" hidden></p>
      <?php if ($queue['drained']): ?>
        <p class="note warn" id="busybanner">The machine's owner has taken the GPU back for a while, this demo runs on their own workstation. The xeric is still here; it just cannot move yet.</p>
      <?php elseif ($queue['busy']): ?>
        <p class="note warn" id="busybanner">The model is busy with somebody else's xeric right now
          (<?= h(xeric_queue_phrase((int)$queue['depth'] + 1, (int)$queue['eta'])) ?>). Press anyway, you keep your place, and the skip starts by itself when it is your turn.</p>
      <?php endif; ?>
    </div>

    <!-- what this world is carrying, if it was given anything. Under the time
         control on purpose: it is context for the button, not a rival to it. -->
    <div id="story"><?= $state['story_html'] ?></div>

    <div id="feedwrap" hidden>
      <h2 id="feedh">while you were away</h2>
      <div class="log" id="ticklog" aria-live="polite"></div>
      <div class="feed" id="feed"></div>
    </div>

    <h2>where you are</h2>
    <p class="note warn" id="goerr" hidden></p>
    <div id="where"><?= $state['where_html'] ?></div>

    <h2>what death means here</h2>
    <p class="note warn" id="fateerr" hidden></p>
    <div id="fate"><?= $state['fate_html'] ?></div>

    <h2>what has already happened</h2>
    <div id="past"><?= $state['past_html'] ?></div>

    <h2>what this xeric runs on</h2>
    <div class="panel" id="panel"><?= $state['panel_html'] ?></div>

    <footer>
      This xeric is <code>worlds/<?= h($w['slug']) ?>/</code>, a template, its baked past, and a
      database of everything that has happened since. It keeps its own clock whether or not you are here.
      <br><?php if ($w['mine']): ?><a href="why.php?w=<?= h(rawurlencode($w['slug'])) ?>">why it does what it does</a> ·
      <a href="review.php?w=<?= h(rawurlencode($w['slug'])) ?>">change it</a> · <?php endif; ?>
      <a href="forge.php?w=<?= h(rawurlencode($w['slug'])) ?>">how it was forged</a> ·
      <a href="world.php?w=<?= h(rawurlencode($w['slug'])) ?>">the raw template</a>
    </footer>
  </section>

  <!-- ------------------------------------------------------------ thread -->
  <section class="screen" data-screen="thread">
    <!-- The same bar, and the clock is on it for the same reason: you are
         texting somebody at a particular hour of THEIR day, and that is most of
         why the reply reads the way it does. Losing it behind a scroll loses
         the point. -->
    <div class="pbar">
      <div class="pclock">
        <h1 class="pname" id="tname"></h1>
        <span class="clock"><span class="ph"></span><span id="tclock" class="cktxt"><?= h(xeric_play_when($now)) ?></span></span>
      </div>
      <nav class="pnav">
        <button class="nbtn back" type="button" id="back">← the cast</button>
      </nav>
    </div>
    <p class="whoami" id="tone"></p>
    <ul class="msgs" id="msgs"></ul>
    <div class="thinking" id="thinking" hidden><i></i><i></i><i></i><span id="thinkwho">thinking</span>
      <span id="thinkwhat"></span></div>
    <p class="note warn" id="sayerr" hidden></p>
    <!-- A LINE YOU MIGHT SAY. Empty and hidden until the right arrow is pressed:
         an autocomplete that appears on its own is a co-author nobody hired. -->
    <div class="ghost" id="ghost" hidden>
      <span class="gtext" id="gtext"></span>
      <span class="gkey" aria-hidden="true">→ again to use it</span>
    </div>
    <div class="composer">
      <textarea id="composer" rows="1" placeholder="say something…" autocomplete="off"></textarea>
      <button class="btn" type="button" id="send">Send</button>
    </div>
  </section>
</main>
</div>

<!-- THE COG. One modal for whoever's gear was tapped, filled from a=char and
     saved through review.php, so the play screen gets the workbench's rules,
     its undo and its age floor without growing a second copy of any of them. -->
<div id="coverlay"><div id="cmodal" role="dialog" aria-modal="true"></div></div>
<div id="ptoast" role="status"></div>

<script>
(function () {
  'use strict';
  var W = <?= json_encode($w['slug']) ?>;

  // -- the rewind ------------------------------------------------------------
  // The button never fires without the card, the card never lies about what
  // goes, and a refusal prints the engine's own sentence. Success reloads the
  // page whole: every pane is stale after a week un-happens, and a repaint
  // that missed one would show a ghost.
  (function () {
    var btn = document.getElementById('rewindbtn');
    if (!btn) return;
    var card = document.getElementById('rewindcard');
    btn.addEventListener('click', function () { card.hidden = !card.hidden; });
    var stay = document.getElementById('rewindstay');
    if (stay) stay.addEventListener('click', function () { card.hidden = true; });
    var go = document.getElementById('rewindgo');
    if (go) go.addEventListener('click', function () {
      go.disabled = true;
      fetch('tick.php?w=' + encodeURIComponent(W), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rewind: 1 })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.ok) { location.reload(); return; }
        var err = document.getElementById('rewinderr');
        err.textContent = (d && d.why) || 'that did not take';
        err.hidden = false;
        go.disabled = false;
      }).catch(function () { go.disabled = false; });
    });
  })();

  // -- the exit door ---------------------------------------------------------
  // "Keep it running" is a plain link to the shelf; "pause it first" stops
  // THIS world's clock through the same power.php call the machine corner
  // makes, then goes. A failed pause keeps you here with the reason on
  // screen, because leaving somebody who asked for stillness in a world that
  // is still moving is the one wrong outcome this card has.
  (function () {
    var card = document.getElementById('leavecard');
    var btn  = document.getElementById('leave');
    if (!card || !btn) return;
    btn.addEventListener('click', function () { card.hidden = !card.hidden; });
    var stay = document.getElementById('leavestay');
    if (stay) stay.addEventListener('click', function () { card.hidden = true; });
    var pause = document.getElementById('leavepause');
    if (pause) pause.addEventListener('click', function () {
      pause.disabled = true;
      fetch('power.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ a: 'clock', world: W, set: 'stop' }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) { location.href = 'play.php'; return; }
          throw new Error((d && d.error) || 'the clock did not stop');
        })
        .catch(function (e) {
          pause.disabled = false;
          var err = document.getElementById('leaveerr');
          err.textContent = 'Could not pause it: ' + e.message + ' — the world is still running. Stay, or leave it living.';
          err.hidden = false;
        });
    });
  })();

  // -- the first-hookup photo offer ------------------------------------------
  // Either press is the answer and the banner never returns: photo.php stamps
  // photos.asked on both paths and photos.approved only on yes. The yes spawns
  // the reaper detached and the banner says so — the photos land over the next
  // minutes, cog by cog, and the cover art follows.
  (function () {
    var pb = document.getElementById('photobanner');
    if (!pb) return;
    function answer(a) {
      pb.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      fetch('photo.php?w=' + encodeURIComponent(W), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ w: W, a: a })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (a === 'approve' && d && d.ok) {
          pb.innerHTML = '<p>Developing ' + (d.pending || 'the') + ' photographs in the background. '
            + 'They arrive over the next minutes — portraits in each cog, the cover art after.</p>';
          setTimeout(function () { pb.hidden = true; }, 12000);
        } else {
          pb.hidden = true;
        }
      }).catch(function () { pb.hidden = true; });
    }
    document.getElementById('photoyes').addEventListener('click', function () { answer('approve'); });
    document.getElementById('photono').addEventListener('click', function () { answer('decline'); });
  })();

  // -- the one-time rating confirmation --------------------------------------
  // Any press is the answer; the banner never comes back because the save
  // writes forge.rating_confirmed through the review door.
  (function () {
    var rb = document.getElementById('ratebanner');
    if (!rb) return;
    rb.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-rate]');
      if (!b) return;
      rb.querySelectorAll('[data-rate]').forEach(function (x) { x.disabled = true; });
      fetch('play.php?a=rating&w=' + encodeURIComponent(W), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rating: b.dataset.rate })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) {
          document.getElementById('ratenote').textContent = d.error || 'that did not take';
          rb.querySelectorAll('[data-rate]').forEach(function (x) { x.disabled = false; });
          return;
        }
        document.getElementById('ratenote').textContent =
          'Rated ' + d.label + (d.clamped ? ' (your session cannot rate higher)' : '') + '. ' + d.note;
        rb.querySelectorAll('.ratebtns').forEach(function (x) { x.remove(); });
        setTimeout(function () { rb.remove(); }, 4000);
      }).catch(function () {
        rb.querySelectorAll('[data-rate]').forEach(function (x) { x.disabled = false; });
      });
    });
  })();
  var ME = <?= json_encode($userName) ?>;
  var WNAME = <?= json_encode((string)$T['meta']['name']) ?>;
  var MINE = <?= $w['mine'] ? 'true' : 'false' ?>;
  var MEFACE = <?= json_encode(xeric_play_face([
      'handle' => '__you', 'display_name' => $userName,
      'pronouns' => (string)($T['user']['pronouns'] ?? ''),
  ])) ?>;
  // The world's real orbits, for the one question the add form has to ask: is
  // this somebody you see every day, or somebody the town has. `extras` is the
  // fixtures bucket and is not a place to put a person.
  var ORBITS = <?= json_encode(array_values(array_filter(array_map(
      fn($o) => ['key' => (string)($o['key'] ?? ''), 'label' => (string)($o['label'] ?? ''),
                 'daily' => !empty($o['shares_daily_space_with_user'])],
      (array)($T['cast']['orbits'] ?? [])),
      fn($o) => $o['key'] !== '' && $o['key'] !== 'extras'))) ?>;

  // -- the clock, actually moving -------------------------------------------
  // World time is real time plus an offset (engine/clock.php), so it flows
  // whether or not anybody asks. The page used to print it once and let it
  // rot; now every clock on the page is the same anchor plus however long you
  // have been staring at it, re-anchored by every repaint and every pulse.
  var CLOCK = {
    epoch: <?= (int)$now['epoch'] ?>,
    paused: <?= $state['clock']['paused'] ? 'true' : 'false' ?>,
    tz: <?= json_encode((string)($T['user']['timezone'] ?? 'UTC')) ?>,
    at: Date.now() / 1000
  };
  var CKFMT = null;
  try {
    CKFMT = new Intl.DateTimeFormat('en-US', { timeZone: CLOCK.tz, weekday: 'long',
      month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
  } catch (e) { CKFMT = null; }               // an unknown zone keeps the server's string
  // the era's year, not the epoch's: a 1990s town never prints 2026 on itself
  var CKYR = <?= (int)xeric_play_era_year($T, $now) ?>;

  var PHASE = <?= json_encode((string)$now['phase']) ?>;
  function phaseOf(mins) {
    if (mins < 5 * 60)  return 'night';
    if (mins < 12 * 60) return 'morning';
    if (mins < 17 * 60) return 'afternoon';
    if (mins < 22 * 60) return 'evening';
    return 'night';
  }
  function worldEpoch() {
    return CLOCK.paused ? CLOCK.epoch
         : Math.round(CLOCK.epoch + Date.now() / 1000 - CLOCK.at);
  }
  function tickClock() {
    if (!CKFMT) return;
    var parts = {};
    CKFMT.formatToParts(new Date(worldEpoch() * 1000)).forEach(function (p) { parts[p.type] = p.value; });
    if (!parts.weekday || !parts.hour) return;
    var hh = parseInt(parts.hour, 10), mm = parseInt(parts.minute || '0', 10);
    PHASE = phaseOf(hh * 60 + mm);
    var hm = parts.hour + ':' + (parts.minute || '00');
    var s = parts.weekday + ' ' + PHASE + ' · ' + hm;
    $$('.cktxt').forEach(function (el) { el.textContent = s; });
    // the sidebar's calendar: date line above, bare time below
    $$('.ckhm').forEach(function (el) { el.textContent = hm; });
    var sd = $('#sdate');
    if (sd && parts.month) sd.textContent = parts.month + ' ' + parts.day + ', ' + CKYR + ', ' + parts.weekday;
  }
  function anchorClock(epoch, paused) {
    if (typeof epoch === 'number') CLOCK.epoch = epoch;
    if (paused !== undefined) CLOCK.paused = !!paused;
    CLOCK.at = Date.now() / 1000;
    tickClock();
    var sc = $('#sclock');
    if (sc) sc.classList.toggle('stopped', CLOCK.paused);
  }
  setInterval(tickClock, 15000);

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var screens = $$('.screen');
  var openHandle = null, busy = false;
  // When the current turn started. A fetch that never settles — a dropped
  // connection, a suspended tab, a proxy that swallows the response — leaves
  // `busy` true forever, and a watchdog that trusts `busy` would sit there
  // agreeing that somebody is typing. Nothing in this app may take longer than
  // say.php's own ceiling plus the queue's retries, so past that the turn is
  // gone whatever the promise thinks.
  var busySince = 0, BUSY_MAX = 210000;

  function show(name) {
    screens.forEach(function (s) { s.classList.toggle('live', s.dataset.screen === name); });
    window.scrollTo(0, 0);
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  // For text that lands INSIDE an attribute: esc() leaves double quotes alone,
  // which is correct in a text node and an injection in a value="…".
  function escA(s) { return esc(s).replace(/"/g, '&quot;'); }

  // -- the keyboard ----------------------------------------------------------
  // TWO KINDS OF KEYBOARD. Some browsers RESIZE the layout when one opens, and
  // interactive-widget=resizes-content in the head is the whole fix there.
  // The rest OVERLAY it: the layout viewport does not change, so a composer
  // pinned to the bottom ends up underneath the keys.
  //
  // For those, the app's height is pinned to the visual viewport while a
  // keyboard is covering it — and the pin is CLEARED AGGRESSIVELY, because a
  // stale one renders as a dead bar across the bottom of the screen with
  // nothing in it. That is worse than the problem: it persists, and nothing the
  // person does looks like it should fix it.
  var vv = window.visualViewport;
  if (vv) {
    var pinned = false, watch = null;

    function syncKeyboard() {
      var covered = window.innerHeight - vv.height;
      if (covered > 80) {
        document.documentElement.style.setProperty('--app-h', vv.height + 'px');
        document.body.classList.add('kb-open');
        pinned = true;
        // The OS back button can dismiss a keyboard without any focusout, so
        // nothing would tell us to let go. Ask, while we are holding it.
        if (!watch) watch = setInterval(syncKeyboard, 1000);
      } else if (pinned) {
        document.documentElement.style.removeProperty('--app-h');
        document.body.classList.remove('kb-open');
        pinned = false;
        if (watch) { clearInterval(watch); watch = null; }
      }
    }

    vv.addEventListener('resize', syncKeyboard);
    vv.addEventListener('scroll', syncKeyboard);
    window.addEventListener('resize', syncKeyboard);
    // Twice, because the keyboard animates in and the first measurement is of
    // a screen halfway there.
    document.addEventListener('focusin', function () { setTimeout(syncKeyboard, 50); setTimeout(syncKeyboard, 300); });
    document.addEventListener('focusout', function () { setTimeout(syncKeyboard, 50); setTimeout(syncKeyboard, 300); });
    // A tab restored from the back-forward cache resumes with whatever pin was
    // active when it was frozen.
    window.addEventListener('pageshow', syncKeyboard);
    document.addEventListener('visibilitychange', syncKeyboard);
  }

  // -- the app bar's buttons -------------------------------------------------
  // They scroll rather than navigate. This screen is one long page by design —
  // the time control, where you are and the cast are all facts about the same
  // xeric and reading them in order is the point — so the bar is a way back to
  // each of them, not a router. scroll-margin-top on the targets keeps them
  // clear of the bar that is sitting on top of them.
  $$('.pnav .nbtn[data-to]').forEach(function (b) {
    b.addEventListener('click', function () {
      // The book is one page and these are its chapters, so this shows the page
      // and then goes to the part that was asked for. Pressed from inside the
      // world page it is just the scroll, which is why the show() is unconditional and
      // harmless: showing the screen you are on does nothing.
      show('world');
      var to = document.getElementById(b.dataset.to);
      if (!to) return;
      // After the switch, not during it: a scroll into a section that is still
      // display:none lands nowhere.
      requestAnimationFrame(function () {
        to.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  });

  $$('.pnav .nbtn[data-back]').forEach(function (b) {
    b.addEventListener('click', function () { show('xeric'); });
  });

  // -- the ⚙ cog: xeric controls, as a modal -------------------------------
  // The stop button came back here, and the app bar's three buttons moved in
  // with it: one gear up by the wordmark instead of a row crowding the top.
  // Reuses #coverlay/#cmodal — the character cog's shell — because a second
  // overlay would be a second copy of the close-guard that already works.
  var XNAV = [
    { to: 'times', label: 'skip time',     hint: 'Move this xeric forward without you' },
    { to: 'where', label: 'where',         hint: 'Where you are, and what it costs to get anywhere else' },
    { to: 'past',  label: 'what happened', hint: 'Everything this xeric has already lived through' }
  ];
  function openXericCog() {
    // CLOCK.paused is the ticker's own anchor, kept fresh by every repaint
    // and every pulse — the old source was a #clockstop element that no
    // longer exists now that main's bar is gone.
    var stopped = !!CLOCK.paused;
    $('#cmodal').innerHTML =
      '<h2>⚙ xeric controls</h2>' +
      '<div class="xcnav">' + XNAV.map(function (n) {
        return '<button type="button" class="nbtn" data-to="' + n.to + '" title="' + n.hint + '">' + n.label + '</button>';
      }).join('') + '</div>' +
      '<div class="xcrow"><button type="button" class="nbtn" id="xcclock">' +
        (stopped ? '▶ start the xeric' : '⏹ stop the xeric') + '</button>' +
        '<span class="xchint">' + (stopped
          ? 'the clock is stopped — nothing moves and nobody texts until you start it'
          : 'stops the clock: nothing moves and nobody texts until you start it again') + '</span></div>' +
      '<div class="xcrow"><button type="button" class="nbtn" id="xcoff">⏻ shut down</button>' +
        '<span class="xchint">stops the local xeric server itself — close the tab after</span></div>' +
      (MINE
        ? '<div class="xcrow"><a class="nbtn" href="review.php?w=' + encodeURIComponent(W) + '#repass">📖 literary repass</a>' +
          '<span class="xchint">an editor reads the whole xeric: contradictions, plotline, and the snake’s pacing</span></div>' +
          '<div class="xcrow"><a class="nbtn" href="book.php?w=' + encodeURIComponent(W) + '">📕 the book</a>' +
          '<span class="xchint">the xeric’s own story, day by day — events, scenes and dreams, fit to print</span></div>' +
          '<div class="xcrow"><a class="nbtn" href="watch.php?w=' + encodeURIComponent(W) + '">🎭 watch</a>' +
          '<span class="xchint">sit in on two of them talking — play, pause, or walk into the middle of it</span></div>'
        : '') +
      '<div class="xcrow"><button type="button" class="nbtn" id="ccancel">close</button></div>';
    $('#coverlay').classList.add('open');
    $('#ccancel').addEventListener('click', closeCog);
    $$('#cmodal [data-to]').forEach(function (b) {
      b.addEventListener('click', function () {
        // same contract as the old app bar: show the page, then the chapter
        closeCog(); show('world');
        var to = document.getElementById(b.dataset.to);
        if (to) requestAnimationFrame(function () { to.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
      });
    });
    $('#xcclock').addEventListener('click', function () {
      this.disabled = true;
      fetch('power.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ a: 'clock', world: W, set: stopped ? 'start' : 'stop' }) })
        .then(function (r) { return r.json(); })
        .then(function () { location.reload(); })   // the next paint reads the real state back out
        .catch(function () { location.reload(); });
    });
    $('#xcoff').addEventListener('click', function () {
      if (!confirm('Shut the local xeric server down?')) return;
      this.disabled = true;
      fetch('power.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ a: 'off' }) }).catch(function () {});
      document.body.innerHTML = '<main style="padding:3em;font:16px/1.6 system-ui">' +
        '<h1>xeric stopped</h1><p>The local server has been told to exit. Close this tab; ' +
        '<code>./xeric</code> starts it again.</p></main>';
    });
  }
  var xcogBtn = document.getElementById('xericcog');
  if (xcogBtn) xcogBtn.addEventListener('click', openXericCog);
  var xcogBtn2 = document.getElementById('xericcog2');
  if (xcogBtn2) xcogBtn2.addEventListener('click', function () { drawer(false); openXericCog(); });
  // THE CLOCK IS A DOOR TO THE TIME CONTROLS. Skip time lives in the cog now,
  // so the thing you would tap wanting to move time is the thing that opens
  // it — every clock pill on the page, not just the one beside the gear.
  $$('.clock').forEach(function (ck) {
    ck.style.cursor = 'pointer';
    ck.title = 'time controls';
    ck.addEventListener('click', function () { drawer(false); openXericCog(); });
  });

  // -- the cast ------------------------------------------------------------
  // The cast and the panel arrive as HTML the server rendered with the same
  // function that rendered them into this page. There is exactly one renderer
  // for each, so a repaint after a skip can never drift from the first paint.
  function bindCast() {
    $$('.person').forEach(function (b) {
      b.addEventListener('click', function () { openThread(b.dataset.h); });
    });
  }
  bindCast();

  // Walking somewhere. No job, no stream, no place in the queue: a trip calls no
  // model at all, so it answers inline in milliseconds and the only thing this
  // has to do is stop a second click landing while the first is in flight.
  //
  // The whole response is fed to repaint() rather than patched in, because the
  // clock moved: the cast is standing somewhere else than it was, the panel's
  // counts are stale, and a screen that repainted only the part you touched
  // would show you a world twenty minutes older than the room you just walked
  // into. Same reason a turn repaints everything.
  var going = false;
  var PERMANENT = <?= json_encode(xeric_death_mode($w['template'], $w['db']) === XERIC_DEATH_PERMANENT) ?>;
  var LASTCAST = <?= json_encode(array_map(fn($c) => ['handle' => $c['handle'], 'name' => $c['name']], $state['cast'])) ?>;
  function bindGo() {
    $$('.goto').forEach(function (b) {
      b.addEventListener('click', function () { go(b.dataset.to, b); });
    });
  }
  bindGo();

  // Death, and undoing it. Same shape as go(): no model, no job, no queue — one
  // row written and a ledger read back, so it answers inline.
  //
  // The two that cannot be undone ask first, and ONLY those two: a confirm on
  // every button teaches people to dismiss confirms. Under `permanent` every
  // death is one of them, which is why the question names the rule rather than
  // the act — somebody who set this weeks ago is owed the reminder, not a scolding.
  var fating = false;
  function bindFate() {
    var kill = $('#fkill'), end = $('#fend'), rest = $('#frestore');
    if (kill) kill.addEventListener('click', function () {
      var who = $('#fwho'), how = $('#fhow');
      if (!who || !who.value) return;
      var name = who.options[who.selectedIndex].text;
      if (PERMANENT && !confirm('Death is permanent in this world. ' + name
          + ' will not come back. Go ahead?')) return;
      fate({ act: 'kill', who: who.value, how: how ? how.value : '' });
    });
    if (end) end.addEventListener('click', function () {
      if (!confirm(PERMANENT
        ? 'This ends the xeric, and death is permanent here. Nobody comes back. Go ahead?'
        : 'This kills everybody in the xeric at once. You will be able to bring them back, and they '
          + 'will all remember. Go ahead?')) return;
      fate({ act: 'end', how: $('#fhow') && $('#fhow').value ? $('#fhow').value : '' });
    });
    if (rest) rest.addEventListener('click', function () { fate({ act: 'restore' }); });
    $$('.fback').forEach(function (b) {
      b.addEventListener('click', function () { fate({ act: 'revive', who: b.dataset.h }); });
    });
  }
  bindFate();

  function fate(body) {
    if (fating) return;
    fating = true;
    $('#fateerr').hidden = true;

    fetch('fate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ world: W }, body))
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j.ok) throw new Error(res.j.error || 'that cannot happen here');
        PERMANENT = res.j.mode === 'permanent';
        told(res.j);
        refreshState();
      })
      .catch(function (e) {
        $('#fateerr').textContent = e.message;
        $('#fateerr').hidden = false;
      })
      .then(function () { fating = false; });
  }

  // Deaths go in the same feed skips and trips write to, because they are the
  // same kind of thing: something that happened to the world while you watched.
  function told(j) {
    var r = j.result || {}, line = '';
    if (j.act === 'kill')          line = nameOf(r.handle) + ' is dead.';
    else if (j.act === 'revive')   line = nameOf(r.handle) + ' is back. Everybody still remembers.';
    else if (j.act === 'end')      line = 'Everybody in the xeric is dead, ' + (r.killed || []).length + ' of them.';
    else if (j.act === 'restore')  line = 'The xeric is back. ' + (r.revived || []).length
                                        + ' people, and every one of them remembers dying.';
    if (!line) return;
    $('#feedwrap').hidden = false;
    var el = document.createElement('div');
    el.className = 'ended';
    el.textContent = line;
    $('#feed').appendChild(el);
  }

  function nameOf(handle) {
    var n = handle;
    (LASTCAST || []).forEach(function (c) { if (c.handle === handle) n = c.name; });
    return n;
  }

  function go(to, btn) {
    if (going) return;
    going = true;
    $('#goerr').hidden = true;
    $$('.goto').forEach(function (b) { b.disabled = true; });

    fetch('where.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ world: W, to: to || null })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j.ok) throw new Error(res.j.error || 'you could not get there');
        arrived(res.j.went, res.j);
        // where.php answers with the map, not with rendered page chrome — so the
        // repaint comes from the state endpoint, the one renderer, exactly as it
        // does after a turn. Nothing here knows how a cast row is drawn.
        refreshState();
        // The sidebar's "you are here" moves with you, now rather than on the
        // next pulse.
        wpulseNow();
      })
      .catch(function (e) {
        $('#goerr').textContent = e.message;
        $('#goerr').hidden = false;
      })
      .then(function () {
        going = false;
        $$('.goto').forEach(function (b) { b.disabled = false; });
      });
  }

  // A trip is a thing that happened, so it goes in the same feed the skips write
  // to. It is also the only place the cost is stated as a fact rather than as a
  // price on a button — "that took you six minutes" is what makes the number on
  // the button mean anything the next time somebody reads one.
  function arrived(went, state) {
    if (!went) return;
    var name = 'your own time';
    if (went.to) {
      (state.places || []).forEach(function (p) { if (p.key === went.to) name = p.name; });
    }
    var who = (went.who || []).map(function (p) { return p.name; });
    var line = went.minutes > 0
      ? 'You walked to ' + name + '. That took ' + went.minutes + ' minute' + (went.minutes === 1 ? '' : 's') + '.'
      : 'You went to ' + name + '.';
    if (went.to) {
      line += went.open ? '' : ' It is shut.';
      // The narrator's arrival beat, when the engine composed one: who is
      // here, what they are at, the day's prop, and the surface of a room
      // noticing a door. The bare who-list stays as the fallback so a world
      // forged before interiors still says something.
      if (went.scene) {
        line += ' ' + went.scene;
      } else {
        line += who.length ? ' ' + who.join(', ') + (who.length > 1 ? ' are' : ' is') + ' here.'
                           : ' There is nobody here.';
      }
    }
    $('#feedwrap').hidden = false;
    var el = document.createElement('div');
    el.className = 'ended';
    el.textContent = line;
    $('#feed').appendChild(el);
  }

  function repaint(state) {
    if (!state) return;
    if (state.clock) {
      var tck = $('#tclock');
      if (tck) tck.textContent = state.clock.when;      // the same clock, on the thread screen
      // [data-span] scopes this to the SKIP buttons. The rewind button wears
      // .tbtn for the row, and disabling it on pause contradicted tick.php,
      // which answers a rewind BEFORE the paused check precisely so a world
      // held still keeps its last skip takeable-back.
      $$('.tbtn[data-span]').forEach(function (b) { b.disabled = !!state.clock.paused; });
      // Every skip and every turn re-anchors the ticker, so the minutes that
      // follow flow from the moment the world just landed on; a stopped world
      // says so on the sidebar pill, which anchorClock owns.
      anchorClock(state.clock.epoch, state.clock.paused);
    }
    if (state.meter_html) {
      var m = document.querySelector('.pbar .meter, .top .meter, #side .meter');
      // Replacing the element drops the all-time figure back to this session's,
      // so the browser's ledger is re-applied to whatever just landed.
      if (m) { m.outerHTML = state.meter_html; if (window.xericMeter) window.xericMeter(); }
    }
    if (state.cast) LASTCAST = state.cast;
    if (state.cast_html) { $('#cast').innerHTML = state.cast_html; bindCast(); }
    if (state.where_html) { $('#where').innerHTML = state.where_html; bindGo(); }
    if (state.fate_html) { $('#fate').innerHTML = state.fate_html; bindFate(); }
    if (state.panel_html) $('#panel').innerHTML = state.panel_html;
    if (state.past_html) $('#past').innerHTML = state.past_html;  // the history, with its reasoning one tap away
    if (state.you_html) $('#yours').innerHTML = state.you_html;   // what is left, kept honest after every turn
    if (typeof state.story_html === 'string') $('#story').innerHTML = state.story_html;
    if (state.story) storyEnded(state.story);
    if (state.spans) paintTimes(state.spans);
  }

  // A skip can close a story without the visitor having said a word, and a strip
  // quietly changing one caption is not a story ending. So the flip from live to
  // closed is watched for and put in the feed, where the skip's own account of
  // itself already is. Anything the thread has already shown has marked itself
  // here, so nothing is announced twice.
  function storyEnded(rows) {
    rows.forEach(function (r) {
      var was = storyLive[r.key];
      storyLive[r.key] = r.live;
      if (was !== true || r.live) return;
      $('#feedwrap').hidden = false;
      var el = document.createElement('div');
      el.className = 'ended';
      el.innerHTML = endHtml(r);
      $('#feed').appendChild(el);
    });
  }

  function paintTimes(spans) {
    // [data-span] again, and here it is load-bearing twice over: this maps the
    // span list onto buttons BY POSITION, and the document-wide collection had
    // the rewind button in it — so a span list one longer than the skip row
    // overwrote the rewind label and handed the button a real data-span, at
    // which point pressing "take it back" ran a genuine skip. Only buttons
    // that are already skip buttons may be repainted as skip buttons.
    var els = $$('.tbtn[data-span]');
    spans.forEach(function (s, i) {
      if (!els[i]) return;
      els[i].dataset.span = s.key;
      $('.tl', els[i]).textContent = s.label;
      $('.ts', els[i]).textContent = s.span + ' · to ' + s.to;
    });
  }

  // -- a thread ------------------------------------------------------------
  function openThread(handle) {
    openHandle = handle;
    $('#msgs').innerHTML = '';
    pending = null;                                   // whatever was on screen went with the innerHTML
    $('#sayerr').hidden = true;
    // Opening a thread is a fresh context: nobody is mid-sentence in it yet.
    // The queued path leaves the dots up on purpose while it waits its turn, so
    // without this a turn that was still queued when the visitor walked away
    // hands its spinner to the next person they open — who then appears to be
    // typing forever, because only a completed send ever takes it back down.
    busy = false;
    $('#send').disabled = false;
    $('#thinking').hidden = true;
    $('#composer').disabled = false;
    $('#composer').placeholder = 'say something…';
    $('#tname').textContent = '';
    $('#tone').textContent = '';
    show('thread');
    fetch('play.php?a=thread&w=' + encodeURIComponent(W) + '&h=' + encodeURIComponent(handle))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { $('#sayerr').textContent = d.error || 'that thread will not open'; $('#sayerr').hidden = false; return; }
        $('#tname').textContent = d.name;
        $('#tone').textContent = d.one_line || '';
        $('#thinkwho').textContent = d.name + ' is typing';
        if (d.dead) {
          $('#send').disabled = true;
          $('#composer').disabled = true;
          $('#composer').value = '';
          $('#composer').placeholder = d.name + ' is dead' + (d.how ? ', ' + d.how : '') + '.';
          $('#tone').textContent = d.how || (d.name + ' is dead.');
        }
        d.messages.forEach(function (m) { addMsg(m.role === 'user' ? 'me' : (m.role === 'character' ? 'them' : 'narr'), m.text, m.when); });
        // the dot is out now — repaint the cast behind this screen
        refreshState();
        var t = $('#composer');
        if (window.innerWidth > 720) t.focus();
      })
      .catch(function () { $('#sayerr').textContent = 'that thread will not open'; $('#sayerr').hidden = false; });
  }

  // AN AVATAR IS A COLOUR AND TWO LETTERS, and the colour is the person's:
  // the cast rows carry each face the server derived (hue banded by pronoun
  // family, letters from the name), so the thread wears the same disc the
  // chips and the sidebar do. The narrator lane deliberately has none, because
  // the world's own voice is not somebody who texted you. The name hash stays
  // as the fallback for a row that is not on the roster.
  function faceOf(name) {
    if (name === ME && MEFACE) return { text: MEFACE.txt, hue: MEFACE.hue };
    var row = openHandle && $('#cast .person[data-h="' + openHandle + '"]');
    if (row && row.dataset.av && name !== ME) {
      return { text: row.dataset.av, hue: parseInt(row.dataset.hue || '0', 10) };
    }
    var n = String(name || '?').trim();
    var initials = n.split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('');
    var h = 0;
    for (var i = 0; i < n.length; i++) h = (h * 31 + n.charCodeAt(i)) % 360;
    return { text: (initials || '?').toUpperCase(), hue: h };
  }

  function addMsg(who, text, when) {
    var li = document.createElement('li');
    li.className = who;

    var face = '';
    if (who !== 'narr') {
      var f = faceOf(who === 'me' ? (ME || 'you') : ($('#tname').textContent || '?'));
      face = '<span class="av" style="--hue:' + f.hue + '" aria-hidden="true">' + esc(f.text) + '</span>';
    }

    li.innerHTML = face + '<span class="b">' + esc(text)
                 + (when ? '<span class="st">' + esc(when) + '</span>' : '') + '</span>';
    $('#msgs').appendChild(li);
    stickDown(li);
    return li;
  }

  // STICK TO THE BOTTOM, unless somebody has scrolled up to read. A thread that
  // yanks itself down while you are reading what somebody said an hour ago is a
  // thread you cannot read backwards.
  function stickDown(li) {
    var m = $('#msgs');
    var near = (m.scrollHeight - m.scrollTop - m.clientHeight) < 120
            || (window.innerHeight + window.scrollY) > (document.body.offsetHeight - 160);
    if (near) li.scrollIntoView({ block: 'end' });
  }

  // -- the story underneath ------------------------------------------------
  //
  // WHO SAYS THE LINE. A wrong lead dying leaves a sentence behind — "it was not
  // kids that night" — and the engine hands it back rather than storing it,
  // because who says it is a question about the scene (engine/story.php). This
  // is the scene, and the answer is: nobody in the cast does. It arrives in the
  // 'narr' lane, which is the same lane a narrator row out of the database
  // lands in when this thread is reopened, and that lane is centred, unbubbled
  // and italic for one reason — a player must never read the world's own line as
  // something a person texted them. The believer, meanwhile, is still certain;
  // a herring that died because its believer conceded it is a quiz answer, not a
  // mystery. say.php writes the same line down so a refresh does not lose it.
  var storyLive = {};
  ((<?= json_encode($state['story']) ?>) || []).forEach(function (r) { storyLive[r.key] = r.live; });

  function storyRow(state, key) {
    var rows = (state && state.story) || [];
    for (var i = 0; i < rows.length; i++) if (rows[i].key === key) return rows[i];
    return null;
  }

  // The end of a story, and the same breath saying the world is still going.
  // That second half is the whole premise, so it is never optional and never
  // smaller than the first.
  function endHtml(row) {
    return '<div class="ec"><div class="eh">the story ends here</div>'
      + '<div class="en">' + esc(row.title) + '</div>'
      + (row.keeps ? '<p class="ep">' + esc(row.keeps) + '</p>' : '')
      + '<p class="ew">' + esc(WNAME) + ' does not end with it. The clock is still running, everybody is '
      + 'still where they are, and the time control still moves them. Nothing here is finished.</p></div>';
  }

  // A line about the story rather than in it — small, centred, and never in
  // anybody's voice.
  function moved(text) { addMsg('moved', text, ''); }

  function storyTurn(d) {
    var st = d.story || {};
    (st.said || []).forEach(function (line) { addMsg('narr', line, ''); });
    // Progress, never the piece: what she told you was in what she just said,
    // three lines up the screen.
    if ((st.spilled || []).length) moved(d.name + ' just told you something this story was holding.');

    (st.resolved || []).forEach(function (r) {
      if (r.closed && r.right) {
        var row = storyRow(d.state, r.key);
        if (!row) return;
        storyLive[row.key] = false;                  // shown here, so the repaint does not show it twice
        var li = document.createElement('li');
        li.className = 'ended';
        li.innerHTML = endHtml(row);
        $('#msgs').appendChild(li);
        li.scrollIntoView({ block: 'end' });
        return;
      }
      // Wrong is a beat and not an ending — story.php validates on_wrong.closes
      // false for exactly this — so it is said plainly, with what it cost, and
      // with nothing at all about who it actually was.
      if (!r.closed && !r.right) {
        moved('You named somebody, and it was not them.' + (r.costs ? ' ' + r.costs + '.' : ''));
      }
      // Right and not yet shown gets NOTHING. No acknowledgment, no wink: a
      // guess is not a solution, and a UI that flinched would be the wink.
    });
  }

  $('#back').addEventListener('click', function () { show('xeric'); refreshState(); });

  // -- saying something ----------------------------------------------------
  // A turn is two to twenty seconds. The page must never look frozen for that
  // long, so the line goes up the moment it is sent and the wait is a state, not
  // a stall.
  // One GPU, one line. A turn that could not get the slot in twenty-five seconds
  // comes back with a POSITION, which goes where the typing dots were and then
  // sends itself again — the visitor said their line once and should not have to
  // say it twice because somebody else was mid-skip. Bounded, so a demo under
  // real load says so out loud instead of retrying forever.
  var QUEUE_TRIES = 3;

  // The line put up the moment it was typed, held until the world actually has
  // it. A turn that failed — or was refused — wrote nothing, and leaving the
  // bubble on the screen would be the page telling somebody they said a thing
  // they did not say. It is taken back down and handed to them again, still
  // typed, which is the only useful thing to do with a refusal: the same
  // sentence will be refused the same way, and a different one will not.
  var pending = null;

  function unsay(text) {
    if (pending && pending.parentNode) pending.parentNode.removeChild(pending);
    pending = null;
    var box = $('#composer');
    if (text && !box.value.trim()) {
      box.value = text;
      box.style.height = 'auto';
      box.style.height = Math.min(box.scrollHeight, 128) + 'px';
    }
  }

  function sayfail(msg, rule) {
    var el = $('#sayerr');
    el.className = 'note ' + (rule ? 'rule' : 'warn');
    el.textContent = msg;
    el.hidden = false;
  }

  function send(retryText, tries) {
    var box = $('#composer'), text = retryText || box.value.trim();
    if (!text || (busy && !retryText) || !openHandle) {
      // A queued turn re-sends itself on a timer. If the visitor closed the
      // thread in the meantime this is where that timer lands, and returning
      // silently would leave the dots up with nothing coming — the one way this
      // page can lie about what the world is doing. Only the retry clears them:
      // a first send bailing on `busy` must not take down the spinner belonging
      // to the turn that is genuinely still running.
      if (retryText) { busy = false; $('#send').disabled = false; $('#thinking').hidden = true; }
      return;
    }
    tries = tries || 0;
    busy = true;
    busySince = Date.now();
    $('#send').disabled = true;
    $('#sayerr').hidden = true;
    if (!retryText) { pending = addMsg('me', text, ''); box.value = ''; box.style.height = ''; }
    $('#thinkwhat').textContent = '';
    $('#thinking').hidden = false;

    fetch('say.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ world: W, handle: openHandle, text: text }) })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok && res.d && res.d.kind === 'queued' && tries < QUEUE_TRIES) {
          // Not an error: a queue. Say where they are and come back by itself.
          $('#thinkwhat').textContent = ', ' + (res.d.phrase || 'waiting for the model');
          setTimeout(function () { send(text, tries + 1); },
                     Math.max(2, Math.min(60, res.d.retry_after || 10)) * 1000);
          return;
        }
        busy = false;
        $('#send').disabled = false;
        $('#thinking').hidden = true;
        if (!res.ok || !res.d.ok) {
          // A rule and a fault are two different screens. `refused` is the age
          // floor, which is deterministic: it is drawn calmly, and the sentence
          // it carries says why saying it again is not the answer.
          unsay(text);
          sayfail(res.d.error || 'nobody answered', res.d.kind === 'refused');
          return;
        }
        pending = null;                                // the xeric has it now
        addMsg('them', res.d.text, res.d.when);
        storyTurn(res.d);                              // ...and what the story made of it
        repaint(res.d.state);
      })
      .catch(function (e) {
        busy = false;
        $('#send').disabled = false;
        $('#thinking').hidden = true;
        unsay(text);
        sayfail('the xeric could not be reached, ' + e.message, false);
      });
  }

  $('#send').addEventListener('click', function () { send(); });
  // -- the shell: chips, the drawer, and who is where -------------------------
  // THE CHIPS ARE THE CAST, so switching threads is a tap rather than a journey
  // back through a list. They are built from the same cast the screen renders,
  // and the active one is whichever thread is open.
  var BASE_TITLE = document.title.replace(/^\(\d+\+?\)\s+/, '');
  function paintChips() {
    var box = $('#chips');
    if (!box) return;
    var rows = $$('#cast .person');
    box.innerHTML = '';
    var unread = 0;

    // YOU GO FIRST. The pill is the player's own chip: tap it to see the exact
    // sentences the cast is handed about you, and to change them from inside.
    if (MINE) {
      var me = document.createElement('button');
      me.type = 'button';
      me.className = 'chip me';
      me.title = 'You. What the others can see about you, and the pen to change it.';
      me.innerHTML = '<span class="av c" style="--hue:' + (MEFACE ? MEFACE.hue : 0) + '"'
                   + ' aria-hidden="true">' + esc(MEFACE ? MEFACE.txt : '?') + '</span>' + esc(ME || 'you');
      me.addEventListener('click', openPill);
      box.appendChild(me);
    }

    rows.forEach(function (r) {
      var h = r.dataset.h;
      if (!h) return;
      var name = (r.querySelector('.pn') || {}).textContent || h;
      // WHAT FITS, NOT WHO THEY ARE. Twelve people is the target and twelve
      // full names is a bar you scroll for a minute or a row of ellipses. The
      // chip says what the room calls them; the whole name is on the hover and
      // on every screen that is actually about the person.
      var shortName = r.dataset.short || name;
      var dot = r.classList.contains('lit');          // the cast row's own unread mark
      var dead = r.classList.contains('gone');
      var c = document.createElement('button');
      c.type = 'button';
      c.className = 'chip' + (h === openHandle ? ' on' : '') + (dot ? ' unread' : '')
                  + (dead ? ' gone' : '');
      c.dataset.h = h;
      if (shortName !== name) c.title = name;

      // The face, the name, the phone lit, where they are, and the cog on the
      // open one. Presence is a mark with a tooltip, never a sentence: out
      // somewhere gets the pin, home in the small hours gets sleep, and dead
      // gets nothing because the strike-through already said it.
      var html = '<span class="av c" style="--hue:' + (parseInt(r.dataset.hue || '0', 10)) + '"'
               + ' aria-hidden="true">' + esc(r.dataset.av || '?') + '</span>' + esc(shortName);
      if (dot) html += '<span class="cdot" aria-hidden="true"></span>';
      if (!dead) {
        // The rows carry the whole presence vocabulary now (data-mark/say from
        // xeric_play_presence_mark) — the bar reads it instead of re-deriving
        // pin-or-sleep from PHASE, which put OUT characters to bed and called
        // every shift a visit. The old two guesses remain only as fallback for
        // a row rendered before the marks existed.
        if (r.dataset.mark) {
          html += '<span class="cps" title="' + escA(r.dataset.say || '') + '">' + esc(r.dataset.mark) + '</span>';
          if (r.dataset.slow) html += '<span class="cps" title="a slow morning">☕</span>';
        } else if (r.dataset.place) {
          html += '<span class="cps" title="at ' + escA(r.dataset.place)
                + (r.dataset.doing ? ' · ' + escA(r.dataset.doing) : '') + '">📍</span>';
        } else if (PHASE === 'night') {
          html += '<span class="cps" title="asleep, most likely">💤</span>';
        }
      }
      if (MINE && h === openHandle && !dead) {
        html += '<span class="cgear" title="Change ' + escA(name) + '">⚙</span>';
      }
      c.innerHTML = html;
      if (dot) unread++;
      c.addEventListener('click', function (ev) {
        if (ev.target.classList && ev.target.classList.contains('cgear')) { openCog(h); return; }
        openThread(h);
      });
      box.appendChild(c);
    });
    if (window.xericChipEdges) window.xericChipEdges();
    var b = $('#bbadge');
    if (b) { b.textContent = String(unread); b.hidden = unread === 0; }
    // The tab carries the count too, so a backgrounded xeric can still wave.
    document.title = unread ? '(' + unread + ') ' + BASE_TITLE : BASE_TITLE;
  }

  // -- the chip bar, pulled by hand -------------------------------------------
  // Twelve people do not fit across a window, and a strip with no scrollbar (the
  // scrollbar is hidden on purpose — a permanent grey bar under the faces looks
  // broken) is a strip a mouse cannot reach the end of. A trackpad can swipe it
  // and a touchscreen can flick it; a plain mouse had nothing. So: grab it and
  // pull.
  //
  // THE HARD PART IS NOT THE DRAG, it is that a drag begins with a press on a
  // chip, and a press on a chip opens a thread. Movement past a few pixels is
  // what tells them apart: under the threshold nothing happens and the click
  // lands normally, over it the strip takes the pointer, the chips stop taking
  // clicks for the duration, and the click that ends the drag is swallowed.
  (function () {
    var strip = $('#chips');
    if (!strip) return;
    var down = false, moved = false, x0 = 0, left0 = 0, id = null;
    var SLOP = 5;                       // px before a press becomes a drag

    strip.addEventListener('pointerdown', function (e) {
      if (e.button !== 0 && e.pointerType === 'mouse') return;
      down = true; moved = false;
      x0 = e.clientX; left0 = strip.scrollLeft; id = e.pointerId;
    });

    strip.addEventListener('pointermove', function (e) {
      if (!down || e.pointerId !== id) return;
      var dx = e.clientX - x0;
      if (!moved) {
        if (Math.abs(dx) < SLOP) return;
        moved = true;
        strip.classList.add('dragging');
        // Only NOW, so a plain click is never captured away from the chip.
        try { strip.setPointerCapture(id); } catch (err) { /* older engines */ }
      }
      strip.scrollLeft = left0 - dx;
      e.preventDefault();
    });

    function release(e) {
      if (!down || (e && e.pointerId !== undefined && e.pointerId !== id)) return;
      down = false;
      if (moved) {
        strip.classList.remove('dragging');
        try { strip.releasePointerCapture(id); } catch (err) { /* already gone */ }
        // The click generated by letting go would otherwise land on whatever
        // chip is now under the cursor — a thread you never asked for. Armed
        // for one click and disarmed on a timer as well, because a release
        // outside the strip produces no click at all and a swallow left armed
        // would eat the next real one.
        var swallow = function (ev) { ev.stopPropagation(); ev.preventDefault(); disarm(); };
        var disarm = function () {
          strip.removeEventListener('click', swallow, true);
          clearTimeout(strip._swallow);
        };
        strip.addEventListener('click', swallow, true);
        strip._swallow = setTimeout(disarm, 400);
      }
      moved = false; id = null;
    }
    strip.addEventListener('pointerup', release);
    strip.addEventListener('pointercancel', release);
    strip.addEventListener('dragstart', function (e) { if (moved) e.preventDefault(); });

    // A wheel over the chips scrolls the chips. A mouse with no horizontal
    // wheel would otherwise scroll the PAGE while the pointer is on the one
    // strip that has somewhere sideways to go.
    strip.addEventListener('wheel', function (e) {
      if (e.deltaX !== 0 || strip.scrollWidth <= strip.clientWidth) return;
      strip.scrollLeft += e.deltaY;
      e.preventDefault();
    }, { passive: false });

    // Which way there is more. Exposed so paintChips can call it the moment it
    // has finished putting chips in.
    window.xericChipEdges = function () {
      var over = strip.scrollWidth - strip.clientWidth;
      strip.classList.toggle('more', over > 1 && strip.scrollLeft < over - 1);
      strip.classList.toggle('less', over > 1 && strip.scrollLeft > 1);
    };
    strip.addEventListener('scroll', window.xericChipEdges, { passive: true });
    window.addEventListener('resize', window.xericChipEdges);
    window.xericChipEdges();
  })();

  // THE DRAWER. Pinned open on anything wide; on a phone it slides over the
  // conversation and closes on the scrim, on Escape, and on going anywhere —
  // a drawer that survives navigation is a drawer covering the thing you just
  // asked to see.
  function drawer(open) {
    document.body.classList.toggle('side-open', open);
    var sc = $('#sidescrim');
    if (sc) sc.hidden = !open;
  }
  if ($('#burger')) $('#burger').addEventListener('click', function () {
    drawer(!document.body.classList.contains('side-open'));
  });
  if ($('#sidescrim')) $('#sidescrim').addEventListener('click', function () { drawer(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.body.classList.contains('side-open')) drawer(false);
  });

  // Somebody in a room is somebody you can text, so the sidebar's names open
  // their thread — that is the whole reason to know where they are.
  // An event somebody opened stays open. The sidebar's markup is replaced
  // wholesale every twelve seconds, which would otherwise shut a paragraph in
  // the middle of being read — so which ones are open is remembered out here,
  // by event id, and put back on every repaint.
  var openEv = {};

  // AND THE SECTIONS THEMSELVES FOLD. Rooms, the cast that is nowhere and the
  // off-screen news stack into one 17rem column; at twelve characters that is a
  // scroll rather than a glance. Which sections are open is a preference about
  // the app and not a fact about any one xeric, so it is kept in the browser
  // rather than the world — and it therefore outlives the repaint AND the tab.
  // localStorage in a try: a browser that refuses it (private mode, a file://
  // open) must lose the memory and nothing else.
  var sbOpen = {};
  try { sbOpen = JSON.parse(localStorage.getItem('xeric.side') || '{}') || {}; } catch (e) { sbOpen = {}; }
  function sbSave() { try { localStorage.setItem('xeric.side', JSON.stringify(sbOpen)); } catch (e) {} }

  function bindSide() {
    $$('#sidebody .sideblock').forEach(function (d) {
      var k = d.dataset.sb;
      if (!k) return;
      // A stored choice wins; NO stored choice leaves the server's default
      // alone, so a section added later arrives the way it was designed to
      // rather than inheriting a decision nobody made about it.
      if (sbOpen[k] === 1) d.open = true;
      else if (sbOpen[k] === 0) d.open = false;
      if (d.dataset.bound) return;
      d.dataset.bound = '1';
      // Bound after the state is set, so restoring a fold is not mistaken for
      // somebody choosing it.
      d.addEventListener('toggle', function () { sbOpen[k] = d.open ? 1 : 0; sbSave(); });
    });
    $$('#sidebody .ev').forEach(function (d) {
      if (openEv[d.dataset.e]) d.open = true;
      if (d.dataset.bound) return;
      d.dataset.bound = '1';
      d.addEventListener('toggle', function () {
        if (d.open) openEv[d.dataset.e] = 1; else delete openEv[d.dataset.e];
      });
    });
    $$('#sidebody .wperson').forEach(function (b) {
      if (b.dataset.bound) return;
      b.dataset.bound = '1';
      b.addEventListener('click', function () {
        if (b.classList.contains('gone')) return;
        drawer(false);
        openThread(b.dataset.h);
      });
    });
  }
  bindSide();
  paintChips();

  // The cast is repainted after a turn, a skip and a thread being opened, and
  // the chips are a view of it — so they are rebuilt from the same place rather
  // than kept in step by hand.
  var castBox = $('#cast');
  if (castBox && window.MutationObserver) {
    new MutationObserver(function () { paintChips(); }).observe(castBox, { childList: true, subtree: true });
  }
  var sideBox = $('#sidebody');
  if (sideBox && window.MutationObserver) {
    new MutationObserver(function () { bindSide(); }).observe(sideBox, { childList: true, subtree: true });
  }

  // A place in the sidebar is a walk. Delegated on the container, which
  // survives every repaint, rather than bound to rows that do not.
  if (sideBox) sideBox.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.wplace') : null;
    if (!b || !b.dataset.to) return;
    if (ticking) return;   // the belt to the CSS's braces: no walks mid-skip
    drawer(false);
    go(b.dataset.to);
    show('xeric');
  });

  // The cog beside a cast row. Delegated for the same reason.
  if (castBox) castBox.addEventListener('click', function (e) {
    var g = e.target.closest ? e.target.closest('.pgear') : null;
    if (g && g.dataset.h) openCog(g.dataset.h);
  });

  // -- a quiet report --------------------------------------------------------
  function toast(msg) {
    var t = $('#ptoast');
    if (!t) return;
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(t._h);
    t._h = setTimeout(function () { t.style.display = 'none'; }, 4500);
  }

  // -- the cog: one person, every dial ----------------------------------------
  // The form is values and paths; review.php is the law. Every save goes
  // through the same edit endpoint the workbench uses, so the age floor, the
  // undo copy and the learning signal all fire without this page knowing they
  // exist. The dice are the review's dice: roll THIS field from everything
  // else in the xeric, so nobody sits in front of an empty box.
  var PRONOUN_OPTS = ['she/her', 'he/him', 'they/them', 'she/they', 'he/they', 'it/its'];
  var cogOrig = {};

  function cogInput(f, i) {
    var id = 'cf' + i;
    if (f.kind === 'pronouns') {
      var v = f.value || '';
      var custom = v !== '' && PRONOUN_OPTS.indexOf(v) < 0;
      var opts = '<option value="">not set</option>' + PRONOUN_OPTS.map(function (o) {
        return '<option value="' + o + '"' + (v === o ? ' selected' : '') + '>' + o + '</option>';
      }).join('') + '<option value="__custom"' + (custom ? ' selected' : '') + '>their own words</option>';
      return '<select id="' + id + '" data-path="' + escA(f.path) + '" data-kind="pronouns">' + opts + '</select>'
           + '<input type="text" id="' + id + 'x" maxlength="40" placeholder="e.g. ze/zir"'
           + ' value="' + escA(custom ? v : '') + '"' + (custom ? '' : ' hidden') + '>';
    }
    if (f.kind === 'orbit') {
      var known = (window.COG_ORBITS || []).some(function (o) { return o.key === f.value; });
      return '<select id="' + id + '" data-path="' + escA(f.path) + '" data-kind="orbit">'
        // A circle the record names but the template no longer declares still
        // shows as itself: silently landing on the first option would turn
        // "look at her" into "move her" on save.
        + (known ? '' : '<option value="' + escA(f.value) + '" selected>' + esc(f.value || 'none') + '</option>')
        + (window.COG_ORBITS || []).map(function (o) {
            return '<option value="' + escA(o.key) + '"' + (f.value === o.key ? ' selected' : '') + '>'
                 + esc(o.label) + '</option>';
          }).join('') + '</select>';
    }
    if (f.kind === 'text') {
      return '<textarea id="' + id + '" data-path="' + escA(f.path) + '" maxlength="1200">' + esc(f.value) + '</textarea>';
    }
    if (f.kind === 'int') {
      return '<input type="number" id="' + id + '" data-path="' + escA(f.path) + '" min="1" max="110" step="1"'
           + ' value="' + escA(f.value) + '">';
    }
    if (f.kind === 'num') {
      return '<input type="number" id="' + id + '" data-path="' + escA(f.path) + '" min="0" max="2" step="0.05"'
           + ' value="' + escA(f.value) + '" title="How loose their sentences run. Most people live between 0.6 and 1.2.">';
    }
    return '<input type="text" id="' + id + '" data-path="' + escA(f.path) + '" maxlength="300" value="' + escA(f.value) + '">';
  }

  function cogValue(el) {
    if (el.dataset.kind === 'pronouns') {
      if (el.value === '__custom') {
        var x = $('#' + el.id + 'x');
        return x ? x.value.trim() : '';
      }
      return el.value;
    }
    return String(el.value).trim();
  }

  function openCog(h) {
    if (!MINE) return;
    fetch('play.php?w=' + encodeURIComponent(W) + '&a=char&h=' + encodeURIComponent(h))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { toast((d && d.error) || 'that one will not open'); return; }
        window.COG_ORBITS = d.orbits || [];
        cogOrig = {};
        var rows = (d.fields || []).map(function (f, i) {
          cogOrig[f.path] = f.value;
          return '<div class="cfield"><label for="cf' + i + '">' + esc(f.label) + '</label>'
               + '<div class="cline">' + cogInput(f, i)
               + (f.dice ? '<button type="button" class="cdice" data-path="' + escA(f.path)
                         + '" title="Roll this from everything else about them">⚄</button>' : '')
               + '</div><p class="cerr" hidden></p></div>';
        }).join('');
        // The model picker, smaller and integrated: one row, active machines
        // only, saved on change — a tuning knob, not a form field, so it does
        // not ride the Save button's review-path edit.
        var vopts = (d.voice || []).map(function (v) {
          return '<option value="' + escA(v.base) + '"' + (v.on ? ' selected' : '') + '>'
               + esc(v.label) + '</option>';
        }).join('');
        var vrow = vopts ? '<div class="cfield"><label for="cvoice">speaks through</label>'
          + '<div class="cline"><select id="cvoice" class="cvsel">' + vopts + '</select></div>'
          + '<p class="cerr" id="cvnote" hidden></p></div>' : '';

        // THE PORTRAIT, when the reaper has developed one. The <img> simply
        // tries: photo.php answers bytes for a done job and 404 for everything
        // else, and onerror folds the frame away — so a world with no image
        // machine shows exactly what it showed yesterday, and a world with one
        // opens the cog onto a face. The caption rides as alt text, which is
        // what it always secretly was.
        var pline = '<div class="cportrait"><img src="photo.php?w=' + encodeURIComponent(W)
          + '&k=portrait&s=' + encodeURIComponent(h) + '" alt="' + escA(d.photo_caption || '')
          + '" onerror="this.closest(\'.cportrait\').hidden=true"></div>';
        $('#cmodal').innerHTML =
            '<h2><span class="av" style="--hue:' + (d.face ? d.face.hue : 0) + '">'
          + esc(d.face ? d.face.txt : '?') + '</span>' + esc(d.name) + '</h2>'
          + pline + rows + vrow
          + '<div class="cbtns"><button type="button" id="csave">Save</button>'
          + '<button type="button" id="ccancel">Cancel</button><span class="grow"></span>'
          + '<a class="workbench" href="review.php?w=' + encodeURIComponent(W) + '#sec-cast">the full workbench →</a></div>';
        $('#coverlay').classList.add('open');
        bindPronounSelects();

        $$('#cmodal .cdice').forEach(function (b) {
          b.addEventListener('click', function () {
            var box = $('#cmodal [data-path="' + b.dataset.path + '"]');
            var err = b.closest('.cfield').querySelector('.cerr');
            if (!box || b.disabled) return;
            b.disabled = true; b.classList.add('rolling'); err.hidden = true;
            fetch('review.php?a=roll', { method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ world: W, path: b.dataset.path }) })
              .then(function (r) { return r.json(); })
              .then(function (j) {
                if (j && j.ok && typeof j.value === 'string') { box.value = j.value; return; }
                err.textContent = (j && j.error) || 'the dice came back empty';
                err.hidden = false;
              })
              .catch(function () { err.textContent = 'the dice could not be reached'; err.hidden = false; })
              .then(function () { b.disabled = false; b.classList.remove('rolling'); });
          });
        });

        // The voice picker saves on change — a tuning knob, not a form field,
        // so it never rides the Save button's review-path edit.
        var cv = $('#cvoice');
        if (cv) cv.addEventListener('change', function () {
          cv.disabled = true;
          fetch('play.php?w=' + encodeURIComponent(W) + '&a=voice', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ h: h, base: cv.value })
          }).then(function (r) { return r.json(); }).then(function (j) {
            cv.disabled = false;
            var n = $('#cvnote');
            n.textContent = j && j.ok ? ('Now speaking through ' + j.label + '. ' + (j.note || ''))
                                      : ((j && j.error) || 'that did not take');
            n.hidden = false;
          }).catch(function () { cv.disabled = false; });
        });

        $('#ccancel').addEventListener('click', closeCog);
        $('#csave').addEventListener('click', function () { saveCog(this); });
      })
      .catch(function () { toast('that one will not open'); });
  }

  // pronoun selects show their free box only when asked; shared by the cog
  // and the pill because it is the same control in both.
  function bindPronounSelects() {
    $$('#cmodal select[data-kind=pronouns]').forEach(function (sel) {
      sel.addEventListener('change', function () {
        var x = $('#' + sel.id + 'x');
        if (!x) return;
        x.hidden = sel.value !== '__custom';
        if (!x.hidden) x.focus();
      });
    });
  }

  // -- the pill: you, as the town reads you ----------------------------------
  // The exact sentences an unwalled character receives about the player, and
  // under them the dials those sentences are made of. Editing here is how you
  // change yourself without leaving the world: the same review door, the same
  // undo, and every character reads the new you on their next line.
  function openPill() {
    if (!MINE) return;
    fetch('play.php?w=' + encodeURIComponent(W) + '&a=you')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { toast((d && d.error) || 'the mirror will not open'); return; }
        cogOrig = {};
        var seen = (d.seen || []).map(function (s) {
          return '<p class="pseenline">' + esc(s) + '</p>';
        }).join('');
        var rows = (d.fields || []).map(function (f, i) {
          cogOrig[f.path] = f.value;
          return '<div class="cfield"><label for="cf' + i + '">' + esc(f.label) + '</label>'
               + '<div class="cline">' + cogInput(f, i) + '</div><p class="cerr" hidden></p></div>';
        }).join('');
        $('#cmodal').innerHTML =
            '<h2><span class="av" style="--hue:' + (MEFACE ? MEFACE.hue : 0) + '">'
          + esc(MEFACE ? MEFACE.txt : '?') + '</span>' + esc(ME || 'you')
          + '<span class="pillme">this is you</span></h2>'
          + '<div class="pseen" title="The exact sentences every character is handed about you. Chat history is theirs to remember; this is theirs to know.">' + (seen || '<p class="pseenline">They know your name and nothing else yet.</p>') + '</div>'
          + rows
          + '<div class="cbtns"><button type="button" id="csave">Save</button>'
          + '<button type="button" id="ccancel">Cancel</button></div>';
        $('#coverlay').classList.add('open');
        bindPronounSelects();
        $('#ccancel').addEventListener('click', closeCog);
        $('#csave').addEventListener('click', function () { saveCog(this); });
      })
      .catch(function () { toast('the mirror will not open'); });
  }

  // -- the + : somebody new ---------------------------------------------------
  // Three boxes, because three is what the forge cannot guess: who they are in
  // your own words, what to call them, and whether they are in your daily rooms
  // or out in the town. Everything else — their week, their voice, the thing
  // they will not say — is written for them, and then they are woven in: the
  // hour they walked in becomes an event, they get memories, and the people who
  // were standing there get one of meeting them.
  var addEs = null;
  function openAdd() {
    if (!MINE) { toast('This xeric was forged in a different browser, so its cast is not yours to add to.'); return; }
    var opts = ORBITS.map(function (o) {
      return '<option value="' + escA(o.key) + '"' + (o.daily ? ' selected' : '') + '>'
           + esc(o.label || o.key) + '</option>';
    }).join('');
    $('#cmodal').innerHTML =
        '<h2>somebody new</h2>'
      + '<p class="addwhy">They are written the way the rest of the cast was. Then you choose how much '
      + 'of this place they have already been in.</p>'
      + '<div class="cfield"><label for="adname">what to call them</label>'
      + '<div class="cline"><input id="adname" type="text" maxlength="80" placeholder="leave it blank and the xeric picks"></div></div>'
      + '<div class="cfield"><label for="adabout">who they are, in your words</label>'
      + '<div class="cline"><textarea id="adabout" rows="3" maxlength="240" placeholder="a retired lighthouse keeper who owes Bram money"></textarea></div></div>'
      + (opts ? '<div class="cfield"><label for="adorbit">where they fit</label>'
      + '<div class="cline"><select id="adorbit">' + opts + '</select></div></div>' : '')
      + '<div class="adlog" id="adlog" hidden></div>'
      + '<p class="cerr" id="aderr" hidden></p>'
      // TWO DOORS, AND THEY ARE DIFFERENT STORIES. Woven: they have been at the
      // edge of this town all along, so tonight becomes the hour they walked in,
      // they carry memories, and whoever was in the room remembers meeting them.
      // Stranger: nobody here has ever seen them. The cast still gets to know
      // them — every sweep from here puts them in front of somebody — they just
      // have no past to be known from.
      + '<div class="cbtns"><button type="button" id="adgo">Write them in</button>'
      + '<button type="button" id="adjust">Just add them</button>'
      + '<button type="button" id="ccancel">Cancel</button></div>'
      + '<p class="adhint" id="adhint">'
      + '<b>Write them in</b> — they have been at the edge of this place all along: tonight becomes '
      + 'the hour they arrived, they carry memories, and whoever was in the room remembers meeting them. '
      + 'Four passes.<br>'
      + '<b>Just add them</b> — a stranger moves to town. No shared past at all; the cast finds out who '
      + 'they are the same way you will. One pass.</p>';
    $('#coverlay').classList.add('open');
    $('#ccancel').addEventListener('click', function () { if (!addEs) closeCog(); });
    $('#adgo').addEventListener('click', function () { startAdd('woven'); });
    $('#adjust').addEventListener('click', function () { startAdd('stranger'); });
    $('#adname').focus();
  }

  function adlog(text, bad) {
    var box = $('#adlog');
    if (!box) return;
    box.hidden = false;
    var line = document.createElement('div');
    line.className = 'adline' + (bad ? ' bad' : '');
    line.textContent = text;
    box.appendChild(line);
    box.scrollTop = box.scrollHeight;
  }

  function addFail(msg) {
    if (addEs) { addEs.close(); addEs = null; }
    var err = $('#aderr');
    if (err) { err.textContent = msg; err.hidden = false; }
    ['#adgo', '#adjust'].forEach(function (s) {
      var b = $(s);
      if (b) { b.disabled = false; b.textContent = b.dataset.was || b.textContent; }
    });
  }

  function startAdd(mode) {
    var err = $('#aderr');
    ['#adgo', '#adjust'].forEach(function (s) {
      var b = $(s);
      if (!b) return;
      b.dataset.was = b.dataset.was || b.textContent;
      b.disabled = true;
    });
    var pressed = $(mode === 'stranger' ? '#adjust' : '#adgo');
    if (pressed) pressed.textContent = 'Writing…';
    if (err) err.hidden = true;
    var body = {
      world: W,
      mode: mode,
      name: ($('#adname') || {}).value || '',
      about: ($('#adabout') || {}).value || '',
      orbit: ($('#adorbit') || {}).value || ''
    };
    fetch('addchar.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j || !res.j.ok) { addFail((res.j && res.j.error) || 'nobody could be written just now'); return; }
        followAdd(res.j.job);
      })
      .catch(function () { addFail('that did not reach the xeric. Try again.'); });
  }

  // Same stream every other long job on this app uses; only the frames it cares
  // about differ. The boxes stay on screen behind the log so a failure does not
  // eat what somebody typed.
  function followAdd(job) {
    if (addEs) addEs.close();
    addEs = new EventSource('progress.php?job=' + encodeURIComponent(job));
    addEs.addEventListener('hello', function (m) { adlog(JSON.parse(m.data).text); });
    addEs.addEventListener('queue', function (m) { adlog(JSON.parse(m.data).text); });
    addEs.addEventListener('note', function (m) {
      var d = JSON.parse(m.data);
      adlog('· ' + d.text, d.level === 'warn');
    });
    addEs.addEventListener('done', function (m) {
      var d = JSON.parse(m.data);
      addEs.close(); addEs = null;
      (d.notes || []).forEach(function (n) { adlog('· ' + n); });
      closeCog();
      toast(d.name + ' is in ' + WNAME + (d.where ? ', at ' + d.where : '') + '.');
      refreshState();
      wpulseNow();
    });
    // progress.php renames an `error` frame to `failed` — the DOM's own `error`
    // on an EventSource is the transport dropping, which is onerror below.
    addEs.addEventListener('failed', function (m) { addFail(JSON.parse(m.data).message); });
    addEs.onerror = function () {
      if (addEs && addEs.readyState === 2) addFail('the connection dropped. They may still be arriving, reload to see.');
    };
  }

  if ($('#addchar1')) $('#addchar1').addEventListener('click', openAdd);
  if ($('#addchar2')) $('#addchar2').addEventListener('click', openAdd);

  // -- take this xeric off the shelf ------------------------------------------
  // The browser's own confirm, deliberately: it is the one dialog on this page
  // that cannot be mistaken for part of the app, and this is the one action
  // that cannot be taken back. It names the world and says what goes with it,
  // because "are you sure?" alone tells nobody what they are agreeing to.
  if ($('#xericdel')) $('#xericdel').addEventListener('click', function () {
    var b = this;
    if (!confirm('Delete ' + WNAME + '?\n\nEverybody in it, everything that has happened to them, '
               + 'and every hour it has lived goes with it. This cannot be undone.')) return;
    b.disabled = true;
    fetch('review.php?a=delete', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ world: W }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) { window.location = d.url || 'play.php'; return; }
        b.disabled = false;
        toast((d && d.error) || 'that xeric would not go');
      })
      .catch(function () { b.disabled = false; toast('that did not reach the shelf'); });
  });

  function closeCog() { $('#coverlay').classList.remove('open'); }

  // Close only when the interaction BEGAN on the backdrop: a text-selection
  // drag that ends past the modal's edge retargets the click and would
  // silently discard every unsaved box.
  var cogDown = false;
  $('#coverlay').addEventListener('pointerdown', function (e) { cogDown = e.target === this; });
  $('#coverlay').addEventListener('click', function (e) { if (cogDown && e.target === this) closeCog(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && $('#coverlay').classList.contains('open')) closeCog();
  });

  function saveCog(btn) {
    var boxes = $$('#cmodal [data-path]').filter(function (el) { return !el.classList.contains('cdice'); });
    var changed = boxes.filter(function (el) {
      var was = cogOrig[el.dataset.path];
      return cogValue(el) !== String(was === undefined || was === null ? '' : was);
    });
    if (!changed.length) { closeCog(); return; }
    btn.disabled = true;
    var i = 0, saved = 0;
    (function next() {
      if (i >= changed.length) {
        btn.disabled = false;
        closeCog();
        toast(saved === 1 ? 'Saved.' : 'Saved, ' + saved + ' things.');
        refreshState();
        wpulseNow();
        return;
      }
      var el = changed[i++];
      fetch('review.php?a=edit', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: W, path: el.dataset.path, value: cogValue(el) }) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) { saved++; cogOrig[el.dataset.path] = cogValue(el); next(); return; }
          var err = el.closest('.cfield').querySelector('.cerr');
          err.textContent = (j && j.error) || 'that would not save';
          err.hidden = false;
          el.closest('.cfield').scrollIntoView({ block: 'nearest' });
          btn.disabled = false;
          if (saved) { refreshState(); wpulseNow(); }
        })
        .catch(function () {
          var err = el.closest('.cfield').querySelector('.cerr');
          err.textContent = 'the save could not be reached';
          err.hidden = false;
          btn.disabled = false;
        });
    })();
  }

  // -- the world's own pulse ---------------------------------------------------
  // The heart lives hours while nobody is watching; this is how the open page
  // finds out. The sidebar and the cast only repaint when their HTML actually
  // changed, and never on the first answer: arriving is not news, and swapping
  // identical markup would throw away scroll positions for nothing.
  var lastSide = null, lastCastHtml = null;
  function wpulseNow() {
    fetch('play.php?w=' + encodeURIComponent(W) + '&a=wpulse')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        if (d.tokens && window.xericMeterFeed) window.xericMeterFeed(d.tokens);
        anchorClock(d.epoch, d.paused);
        if (typeof d.side === 'string') {
          if (lastSide !== null && d.side !== lastSide
              && !document.body.classList.contains('side-open')) {
            $('#sidebody').innerHTML = d.side;
          }
          lastSide = d.side;
        }
        if (typeof d.cast_html === 'string') {
          if (lastCastHtml !== null && d.cast_html !== lastCastHtml) {
            $('#cast').innerHTML = d.cast_html;
            bindCast();
          }
          lastCastHtml = d.cast_html;
        }
        if (typeof d.compass === 'string' && $('#scompass')
            && $('#scompass').innerHTML !== d.compass) {
          $('#scompass').innerHTML = d.compass;
        }
      })
      .catch(function () {});
  }
  setInterval(function () { if (!document.hidden) wpulseNow(); }, 12000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) wpulseNow(); });
  tickClock();
  wpulseNow();

  // -- the narrator ----------------------------------------------------------
  // Its answers land in the narr lane — centred, italic, unbubbled — which is
  // the same lane the world's own voice uses inside a thread, and for the same
  // reason: this is not somebody who texted you.
  //
  // NOTHING IS STORED. A hint is about where you are stuck right now; keeping a
  // transcript of them would turn a way out of being stuck into one more thing
  // to read.
  function narrSay(who, text) {
    var li = document.createElement('li');
    li.className = who;
    li.innerHTML = '<span class="b">' + esc(text) + '</span>';
    $('#nmsgs').appendChild(li);
    li.scrollIntoView({ block: 'end' });
  }

  var narrBusy = false;
  function askNarrator(ask) {
    if (narrBusy) return;
    narrBusy = true;
    $('#nerr').hidden = true;
    $('#nthinking').hidden = false;
    fetch('play.php?a=hint&w=' + encodeURIComponent(W) + '&ask=' + encodeURIComponent(ask || ''))
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        narrBusy = false;
        $('#nthinking').hidden = true;
        if (!res.ok || !res.d.text) {
          $('#nerr').textContent = (res.d && res.d.error) || 'nothing came back';
          $('#nerr').hidden = false;
          return;
        }
        narrSay('narr', res.d.text);
      },
      function () {
        narrBusy = false;
        $('#nthinking').hidden = true;
        $('#nerr').textContent = 'the xeric could not be reached';
        $('#nerr').hidden = false;
      });
  }

  if ($('#asknarr2')) $('#asknarr2').addEventListener('click', function () { drawer(false); $('#asknarr').click(); });
  if ($('#asknarr3')) $('#asknarr3').addEventListener('click', function () { $('#asknarr').click(); });

  if ($('#asknarr')) {
    $('#asknarr').addEventListener('click', function () {
      show('narr');
      // Opened cold, it answers the unasked question: what now.
      if (!$('#nmsgs').children.length) askNarrator('');
    });
  }
  if ($('#nagain')) $('#nagain').addEventListener('click', function () { askNarrator(''); });
  if ($('#nsend')) {
    $('#nsend').addEventListener('click', function () {
      var box = $('#ncomposer'), t = box.value.trim();
      if (!t) { askNarrator(''); return; }
      narrSay('me', t);
      box.value = '';
      askNarrator(t);
    });
    $('#ncomposer').addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('#nsend').click(); }
    });
  }

  // -- a line you might say --------------------------------------------------
  // RIGHT ARROW AT THE END OF WHAT YOU HAVE TYPED. Once to ask for a suggestion,
  // again to take it. Nowhere else does it do anything, because anywhere else
  // the right arrow is how somebody moves a cursor and stealing that would be
  // unforgivable in a text box.
  //
  // It costs a model call, so it is never speculative: nothing is fetched, shown
  // or paid for until the key is pressed.
  var ghosted = '';
  var ghosting = false;

  function ghostShow(text) {
    ghosted = text;
    $('#gtext').textContent = text;
    $('#ghost').hidden = false;
  }
  function ghostHide() {
    ghosted = '';
    $('#ghost').hidden = true;
  }
  function ghostTake() {
    var box = $('#composer');
    if (!ghosted) return;
    box.value = box.value ? (box.value.replace(/\s+$/, '') + ' ' + ghosted) : ghosted;
    ghostHide();
    box.focus();
    box.selectionStart = box.selectionEnd = box.value.length;
    box.dispatchEvent(new Event('input'));            // let it grow to fit
  }

  function ghostAsk() {
    if (ghosting || busy || !openHandle) return;
    ghosting = true;
    $('#gtext').textContent = 'thinking of something…';
    $('#ghost').hidden = false;
    fetch('play.php?a=suggest&w=' + encodeURIComponent(W) + '&h=' + encodeURIComponent(openHandle))
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        ghosting = false;
        if (!res.ok || !res.d.text) { ghostHide(); return; }
        ghostShow(res.d.text);
      },
      function () { ghosting = false; ghostHide(); });
  }

  $('#composer').addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight') {
      var box = e.target;
      var atEnd = box.selectionStart === box.selectionEnd
               && box.selectionStart === box.value.length;
      if (!atEnd) return;                              // it is a cursor key first
      e.preventDefault();
      if (ghosted) ghostTake(); else ghostAsk();
      return;
    }
    // Anything else means they had their own idea, which outranks ours.
    if (e.key === 'Escape') { ghostHide(); return; }
    if (ghosted && e.key.length === 1) ghostHide();
  });

  $('#composer').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  $('#composer').addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 128) + 'px';
  });

  // -- the time control ----------------------------------------------------
  var es = null, ticking = false, drops = 0;

  function tlog(text, warn) {
    var el = $('#ticklog'), d = document.createElement('div');
    if (warn) d.className = 'w';
    d.textContent = text;
    el.appendChild(d);
    el.scrollTop = el.scrollHeight;
  }

  function setTicking(on) {
    ticking = on;
    $$('.tbtn').forEach(function (b) { b.disabled = on; });
    // The sidebar's walk buttons grey with the skip: the worker owns the clock
    // and travel refuses mid-skip anyway, so the button tells the truth first.
    // A class on <body> because #sidebody repaints every twelve seconds and
    // would resurrect per-node disabling; CSS survives the repaint for free.
    document.body.classList.toggle('skipping', on);
  }

  // `.tbtn[data-span]`, NOT `.tbtn`. The rewind button wears .tbtn too — it
  // belongs to the same row and takes the same styling and the same disabling —
  // and this loop used to bind the SKIP handler to it as well. Its dataset.span
  // is undefined, tick.php reads a missing span as 'hour' (tick.php:97), so
  // pressing REWIND opened the confirm card and advanced the world an hour
  // behind it. The one control labelled experimental and destructive was moving
  // time the wrong way before anybody confirmed anything. A time button with no
  // span is not a skip button, and now the selector says so.
  $$('.tbtn[data-span]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (ticking) return;
      setTicking(true);
      b.classList.add('on');
      $('#tickerr').hidden = true;
      $('#feedwrap').hidden = false;
      $('#feed').innerHTML = '';
      $('#ticklog').innerHTML = '';
      $('#feedh').textContent = 'while you were away';

      fetch('tick.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: W, span: b.dataset.span }) })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok || !res.d.job) { tickFail(res.d.error || ('the xeric answered ' + res.status)); return; }
          follow(res.d.job);
        })
        .catch(function (e) { tickFail('the xeric could not be reached, ' + e.message); });
    });
  });

  function tickFail(msg) {
    setTicking(false);
    $$('.tbtn').forEach(function (b) { b.classList.remove('on'); });
    $('#tickerr').textContent = msg;
    $('#tickerr').hidden = false;
  }

  // The stream hangs up every 40s on purpose (progress.php) — anything in front
  // of this app would cut it at two minutes anyway. EventSource comes straight
  // back with Last-Event-ID and carries on from the same line, and the skip was
  // never in this request to begin with.
  function follow(job) {
    if (es) es.close();
    es = new EventSource('progress.php?job=' + encodeURIComponent(job));

    es.addEventListener('hello', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      tlog(d.span + ' from ' + d.from + ', ' + d.endpoint);
    });

    // Waiting for the one GPU is a state with a position in it, not a spinner:
    // the feed's heading says where you are standing until it is your turn.
    es.addEventListener('queue', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      $('#feedh').textContent = d.text;
      tlog(d.text);
    });

    es.addEventListener('note', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      if ($('#feedh').textContent !== 'while you were away') $('#feedh').textContent = 'while you were away';
      tlog('[' + Number(d.t).toFixed(1) + 's] ' + d.text, d.level === 'warn');
    });

    es.addEventListener('event', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      var takes = (d.takeaways || []).map(function (t) {
        return '<div class="take"><div class="tn">' + esc(t.name) + '</div><div class="tt">' + esc(t.text) + '</div></div>';
      }).join('');
      var el = document.createElement('div');
      el.className = 'fev';
      el.innerHTML = '<div class="ft">' + esc(d.title) + '</div>'
        + '<div class="fm">' + esc(d.when) + (d.place ? ' · ' + esc(d.place) : '') + ' · ' + esc(d.kind)
        + (d.on_spine ? ' · <span class="spine">touches what this xeric is keeping quiet</span>' : '') + '</div>'
        + '<p class="fp">' + esc(d.prose) + '</p>'
        + (takes ? '<div class="tk">what each of them took away from it</div><div class="takes">' + takes + '</div>' : '')
        // Straight from what just happened to why it happened, while the
        // question is still fresh. The trail was written before this frame was.
        + (d.why_url ? '<a class="whylink" href="' + esc(d.why_url) + '">why did this happen?</a>' : '');
      $('#feed').appendChild(el);
    });

    es.addEventListener('ping', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      var el = document.createElement('div');
      el.className = 'ping';
      el.innerHTML = '<div class="pw">and then your phone</div>'
        + '<div class="pn">' + esc(d.name) + (d.cold_open ? ', a new thread' : '') + '</div>'
        + '<div class="pt">' + esc(d.text) + '</div>';
      $('#feed').appendChild(el);
      el.addEventListener('click', function () { openThread(d.handle); });
      el.style.cursor = 'pointer';
    });

    es.addEventListener('quiet', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      (d.why || []).forEach(function (n) { tlog('· ' + n); });
      tlog('nobody texted about it.');
    });

    es.addEventListener('done', function (m) {
      drops = 0;
      var d = JSON.parse(m.data);
      es.close();
      setTicking(false);
      $$('.tbtn').forEach(function (b) { b.classList.remove('on'); });
      (d.notes || []).forEach(function (n) { tlog('· ' + n); });
      tlog(', ' + d.events + (d.events === 1 ? ' event' : ' events') + ' in ' + d.seconds + 's');
      $('#feedh').textContent = d.events ? 'while you were away' : 'nothing happened in those hours';
      repaint(d.state);
    });

    es.addEventListener('failed', function (m) {
      var d = JSON.parse(m.data);
      es.close();
      tickFail(d.message);
    });

    es.addEventListener('pause', function () { drops = 0; });
    es.onerror = function () {
      if (es.readyState === 2 || ++drops > 40) {
        es.close();
        tickFail('the connection keeps dropping. The xeric may still be moving, reload to pick it back up.');
      }
    };
  }

  // A repaint that fails used to be swallowed, which left somebody looking at a
  // world that had quietly stopped being true — the worst of the silent states,
  // because nothing on the screen was wrong, it was just old.
  // THE INVARIANT: the dots are up if and only if a turn is actually in flight.
  // Every path that raises them is responsible for lowering them, and one that
  // forgets strands them — the page then says somebody is typing while the model
  // sits idle, which is the most misleading thing this screen can do. Rather
  // than trusting each path to remember, `busy` is the single truth and the dots
  // merely display it. Costs nothing: no network, no work when already correct.
  function settle() {
    if (busy && busySince && Date.now() - busySince > BUSY_MAX) {
      busy = false;                                  // the turn is not coming back
      sayfail('that turn never came back, the xeric may have been busy. Say it again.', false);
    }
    if (!busy && !$('#thinking').hidden) {
      $('#thinking').hidden = true;
      $('#send').disabled = false;
    }
  }
  setInterval(settle, 1000);

  function refreshState() {
    settle();
    fetch('play.php?a=state&w=' + encodeURIComponent(W))
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.d.ok) {
          stale(res.d.error || 'this xeric stopped answering, so what is on this page may be out of date.');
          return;
        }
        $('#staleerr').hidden = true;
        repaint(res.d);
      })
      .catch(function (e) {
        stale('this page could not reach the xeric (' + e.message + '), so what you are looking at may be '
              + 'out of date. Nothing has been lost, reload when you are back online.');
      });
  }

  function stale(msg) {
    var el = $('#staleerr');
    el.textContent = msg;
    el.hidden = false;
  }

  // A skip already running for this browser (a reload, a locked phone) is
  // rejoined rather than restarted — it was never in the page to begin with.
  var RESUME = <?= json_encode($resume) ?>;
  if (RESUME) {
    setTicking(true);
    $('#feedwrap').hidden = false;
    tlog('rejoining the skip already running…');
    follow(RESUME);
  }
})();
</script>
</html>
