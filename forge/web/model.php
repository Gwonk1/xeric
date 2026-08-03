<?php
/**
 * model.php — the machines.
 *
 *     GET  model.php              the list
 *     GET  model.php?a=probe&i=N  is machine N answering
 *     POST {act:use, i}           make it the one, and start the clocks
 *     POST {act:off}              nothing is the one, and stop the clocks
 *     POST {act:add, base}        remember an address
 *     POST {act:drop, i}          forget one
 *
 * A machine is an address and nothing else. Its kind is read off the host —
 * api.anthropic.com speaks Anthropic, anything else public speaks the OpenAI
 * chat API, anything private is the machine you are on — so there is no
 * dropdown, and adding a machine is typing where it is.
 *
 * THE LIGHTS ARE FILLED IN BY THE BROWSER, one request per machine, after the
 * page is on screen. Five machines probed server-side is five timeouts in a row
 * before anybody sees anything, and the answer is stale the moment it renders.
 *
 * ACTIVE IS A SELECTION, NOT A BUTTON. Picking a machine attaches it and starts
 * every world where it stopped; picking the active one again detaches and stops
 * them. Detached is a real state and the default (xeric_web_model).
 *
 * Row 0 is this install's configured address and cannot be forgotten: a list
 * with nothing in it and no route back to the machine the server is on is a
 * dead end.
 *
 * Private addresses may only be ADDED from the machine the server is on
 * (xeric_web_local_editable). xeric_web_endpoint() refuses a private host for
 * any remote kind; this is the same boundary applied where an address is
 * stored rather than where it is used.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$sid  = xeric_session_id();
$edit = xeric_web_local_editable();

/** Why an address was not taken, in a sentence with the fix in it. */
function xeric_model_no(string $base, string $host, bool $edit): string
{
    if ($base === '' || $host === '') return 'That does not look like an address.';
    if (!$edit && !xeric_web_host_open($host)) {
        return $host . ' is on a private network, and this install only takes an address like that '
            . 'from the machine it is running on. Set local_editable in config.local.php to allow it '
            . 'from anywhere, which also means from anyone who can reach this page.';
    }
    return 'That address was not taken: ' . $host . ' does not resolve to anywhere reachable.';
}

$list = xeric_model_list($sid);

// -- is it answering ---------------------------------------------------------
if ((string)($_GET['a'] ?? '') === 'probe') {
    $i = (int)($_GET['i'] ?? -1);
    if (!isset($list[$i])) xeric_web_json(['up' => false]);

    $base = (string)$list[$i]['base'];
    $kind = xeric_model_kind($base);

    // A REMOTE ENDPOINT CANNOT BE PROBED HONESTLY. The key is never stored, so
    // the only request that can be made is keyless, and a keyless request to a
    // working OpenAI endpoint is a 401 — which reads as "down" and is a lie
    // about somebody's perfectly good API. Null, and the lamp stays grey.
    if ($kind !== 'local') {
        xeric_web_json(['up' => null] + ['who' => xeric_model_who($base, $kind)]);
    }

    $up = false;
    try {
        $up = xeric_llm_up(['kind' => 'local', 'base' => $base, 'model' => '', 'key' => ''], 4);
    } catch (Throwable $e) {
        $up = false;
    }
    // AND WHERE ONE ACTUALLY IS. The offer used to be worked out when the page
    // rendered, which meant a machine broken IN PLACE could never show it — the
    // element had not been printed, because at render time nothing was wrong.
    // The probe is the thing that knows something is wrong, so the probe carries
    // the way out.
    $found = '';
    if (!$up) {
        $live = xeric_web_probe_local();
        if (rtrim($live, '/') !== rtrim($base, '/')) $found = $live;
    }
    // Asked only of a machine that answered. Fingerprinting something that is
    // down is four more timeouts on the one screen somebody is looking at
    // BECAUSE things are timing out.
    $who = $up ? xeric_model_who($base, $kind) : ['name' => '', 'model' => '', 'local' => true];
    xeric_web_json(['up' => $up, 'found' => $found, 'who' => $who]);
}

// -- is the image machine answering ------------------------------------------
// The configured one only, never an arbitrary base: a probe endpoint that took
// an address would be a port scanner with this server's return address on it.
if ((string)($_GET['a'] ?? '') === 'iprobe') {
    $ep = xeric_image_endpoint();
    xeric_web_json(['up' => $ep !== null && xeric_image_up($ep, 4)]);
}

// -- what else is on this machine --------------------------------------------
// ASKED BY THE BROWSER, AFTER THE PAGE IS UP, for the same reason the lamps are:
// a dozen ports checked before the first byte is sent is a screen that appears
// to hang on the one visit where nothing is working.
if ((string)($_GET['a'] ?? '') === 'scan') {
    xeric_web_json(xeric_model_scan(array_column($list, 'base')));
}

// -- change ------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $i   = (int)($_POST['i'] ?? -1);

    // -- the image machine ---------------------------------------------------
    // One address, session-stored like the model machines, read at call time
    // by the engine's resolver (boot.php). The KEY is never stored: a remote
    // image machine takes XERIC_IMAGE_KEY from the environment only — the
    // same never-in-a-session discipline every machine on this page keeps.
    if ($act === 'image') {
        $b = trim((string)($_POST['base'] ?? ''));
        if ($b !== '' && !preg_match('#^https?://#i', $b)) $b = 'http://' . $b;
        $b    = rtrim($b, '/');
        $host = strtolower((string)(parse_url($b, PHP_URL_HOST) ?? ''));
        $ok   = $b !== '' && filter_var($b, FILTER_VALIDATE_URL) !== false && $host !== '';
        if ($ok && !xeric_web_host_open($host) && !$edit) $ok = false;
        if ($ok) {
            xeric_web_session_edit(function (array &$s) use ($b): void {
                $s['image'] = ['base' => $b];
            }, $sid);
            header('Location: model.php');
            exit;
        }
        header('Location: model.php?no=' . rawurlencode(xeric_model_no($b, $host, $edit)));
        exit;
    }
    if ($act === 'imageoff') {
        // Explicitly none — the resolver returns false and even an environment
        // XERIC_IMAGE_BASE is ignored for this session. Worlds fall back to
        // captions, which is the photo's own graceful floor.
        xeric_web_session_edit(function (array &$s): void { $s['image'] = ['off' => true]; }, $sid);
        header('Location: model.php');
        exit;
    }

    if ($act === 'add') {
        $b = trim((string)($_POST['base'] ?? ''));
        if ($b !== '' && !preg_match('#^https?://#i', $b)) $b = 'http://' . $b;
        $b    = rtrim($b, '/');
        $host = strtolower((string)(parse_url($b, PHP_URL_HOST) ?? ''));
        $ok   = $b !== '' && filter_var($b, FILTER_VALIDATE_URL) !== false && $host !== '';
        // A private address is only somebody's own machine when they are on it.
        if ($ok && !xeric_web_host_open($host) && !$edit) $ok = false;

        if ($ok) {
            xeric_web_session_edit(function (array &$s) use ($b): void {
                $have = array_column((array)($s['machines'] ?? []), 'base');
                if (!in_array($b, $have, true)) $s['machines'][] = ['base' => $b];
            }, $sid);
            header('Location: model.php');
            exit;
        }
        // REFUSED, AND IT SAYS WHY. It redirected either way, so typing a
        // perfectly good address on a host that will not store it looked exactly
        // like the form doing nothing — the worst outcome for the one screen
        // somebody is on precisely because nothing else is working.
        header('Location: model.php?no=' . rawurlencode(xeric_model_no($b, $host, $edit)));
        exit;
    }

    $wantsJson = !empty($_POST['json']);

    if ($act === 'edit') {
        $b = trim((string)($_POST['base'] ?? ''));
        if ($b !== '' && !preg_match('#^https?://#i', $b)) $b = 'http://' . $b;
        $b    = rtrim($b, '/');
        $host = strtolower((string)(parse_url($b, PHP_URL_HOST) ?? ''));
        $ok   = $b !== '' && filter_var($b, FILTER_VALIDATE_URL) !== false && $host !== '';
        // Retyping an address is the same act as adding one, and is held to the
        // same boundary: a private address only from the machine that owns it.
        if ($ok && !xeric_web_host_open($host) && !$edit) $ok = false;

        if (!$ok) {
            $why = xeric_model_no($b, $host, $edit);
            if ($wantsJson) xeric_web_json(['ok' => false, 'why' => $why]);
            header('Location: model.php?no=' . rawurlencode($why));
            exit;
        }
        if (isset($list[$i])) {
            $fixed = !empty($list[$i]['fixed']);
            // THE SELECTION FOLLOWS THE ADDRESS. Attachment is matched on the
            // address, so retyping one silently un-attached the machine — and
            // left the session pointing at an address no longer in the list,
            // which meant the card read "idle" while every model call still went
            // to the old one. Moving a machine is not letting go of it.
            $wasOn = $i === xeric_model_active($list, $sid);
            $kind  = xeric_model_kind($b);

            xeric_web_session_edit(function (array &$s) use ($b, $i, $fixed, $wasOn, $kind): void {
                if ($fixed) { $s['local_base'] = $b; }
                else {
                    $rows = array_values((array)($s['machines'] ?? []));
                    if (isset($rows[$i - 1])) $rows[$i - 1]['base'] = $b;
                    $s['machines'] = $rows;
                }
                if ($wasOn) {
                    $s['model'] = $kind === 'local'
                        ? ['kind' => 'local', 'base' => '', 'local' => $b, 'model' => '']
                        : ['kind' => $kind, 'base' => $b, 'local' => '', 'model' => ''];
                }
            }, $sid);
        }
        if ($wantsJson) {
            xeric_web_json(['ok' => true, 'i' => $i,
                            'base' => (string)preg_replace('#^https?://#', '', $b)]);
        }
        header('Location: model.php');
        exit;
    }

    // ONE CLICK FROM DISCOVERED TO IN USE. Add-then-Connect is two round trips
    // to say one thing, and the intermediate state — on the list, not attached —
    // is not a state anybody wanted to be in. The address is checked exactly as
    // `add` checks it, because it arrives the same way: over the wire.
    if ($act === 'adduse') {
        $b = trim((string)($_POST['base'] ?? ''));
        if ($b !== '' && !preg_match('#^https?://#i', $b)) $b = 'http://' . $b;
        $b    = rtrim($b, '/');
        $host = strtolower((string)(parse_url($b, PHP_URL_HOST) ?? ''));
        $ok   = $b !== '' && filter_var($b, FILTER_VALIDATE_URL) !== false && $host !== '';
        if ($ok && !xeric_web_host_open($host) && !$edit) $ok = false;
        if (!$ok) {
            header('Location: model.php?no=' . rawurlencode(xeric_model_no($b, $host, $edit)));
            exit;
        }
        // Connecting an API does not hand it the worlds. The first LOCAL machine
        // connected takes the empty engine slot because it is free and already
        // running; an API waits to be chosen on purpose.
        $wasEngine = xeric_model_active($list, $sid) >= 0 || !xeric_model_auto_engine($b);
        // READ THE SET AS THE SCREEN SEES IT, which includes an engine that was
        // attached before `wired` existed or by the auto-attach on first sight.
        // Writing back only what is literally in the record drops that machine
        // out of the set the moment something else becomes the engine.
        $wiredNow = xeric_model_wired($sid);
        xeric_web_session_edit(function (array &$s) use ($b, $wasEngine, $wiredNow): void {
            $have = array_column((array)($s['machines'] ?? []), 'base');
            // Row 0 is the install's own address and is not in `machines`, so a
            // discovered machine that IS row 0 must not be added a second time.
            $own = rtrim(trim((string)($s['local_base'] ?? '')), '/');
            if ($own === '') $own = rtrim((string)xeric_web_config()['local_base'], '/');
            if ($b !== $own && !in_array($b, $have, true)) $s['machines'][] = ['base' => $b];

            $s['wired'] = array_values(array_unique(array_merge($wiredNow, [$b])));
            // The FIRST machine connected runs the worlds, because something has
            // to and there is nothing else. The second does not take that job
            // away from it — connecting a machine you intend to forge with on
            // Sunday must not silently move every world onto it.
            if (!$wasEngine) $s['model'] = xeric_model_descriptor($b);
        }, $sid);
        if (!$wasEngine) xeric_play_clock_all(false, $sid);
        header('Location: model.php');
        exit;
    }

    if ($act === 'drop') {
        // FORGETTING THE ONE IN USE IS ALSO LETTING GO OF IT. Selection is matched
        // on the address, so dropping the attached machine made the list say
        // "Nothing is attached" while the session still held a model and every
        // world kept running — against an address that is no longer on the
        // screen and, being forgotten, usually no longer answering. The screen
        // and the engine disagreeing about whether anything is attached is the
        // one disagreement this page exists to prevent.
        $wasOn = $i === xeric_model_active($list, $sid);

        xeric_web_session_edit(function (array &$s) use ($i, $wasOn): void {
            $rows = array_values((array)($s['machines'] ?? []));
            unset($rows[$i - 1]);                       // row 0 is the install's own
            $s['machines'] = array_values($rows);
            if ($wasOn) $s['model']['kind'] = 'none';
        }, $sid);

        if ($wasOn) xeric_play_clock_all(true, $sid);   // same as Disconnect, because it is one
        header('Location: model.php');
        exit;
    }

    // LETTING GO OF ONE, NOT OF EVERYTHING. This used to be the only way out and
    // it detached whatever was attached, because there was only ever one thing
    // attached. Disconnecting the machine you forge with must not stop the
    // worlds running on a different one.
    if ($act === 'off') {
        $base = isset($list[$i]) ? (string)$list[$i]['base'] : '';
        $wasEngine = $i === xeric_model_active($list, $sid);

        // THE JOB PASSES TO WHOEVER IS LEFT. A session with two machines
        // connected, one of them the engine, should not lose every clock because
        // the engine was the one let go — there is another machine right there,
        // connected, doing nothing.
        $next = '';
        if ($wasEngine) {
            foreach (xeric_model_wired($sid) as $w) {
                // LOCAL ONLY, and this is the one that matters most. Somebody
                // disconnecting their local model has not asked to start paying
                // per token — and every world would quietly carry on, against a
                // meter, until they noticed. If nothing free is left the engine
                // goes empty and the worlds stop, which is loud and free.
                if ($w !== $base && xeric_model_auto_engine($w)) { $next = $w; break; }
            }
        }

        $wiredNow = xeric_model_wired($sid);
        xeric_web_session_edit(function (array &$s) use ($base, $wasEngine, $next, $wiredNow): void {
            $s['wired'] = array_values(array_filter($wiredNow, fn($x) => $x !== $base));
            if ($wasEngine) {
                $s['model'] = $next !== '' ? xeric_model_descriptor($next)
                                           : ['kind' => 'none', 'base' => '', 'local' => '', 'model' => ''];
            }
        }, $sid);

        if ($wasEngine) xeric_play_clock_all($next === '', $sid);
        header('Location: model.php');
        exit;
    }

    if (!isset($list[$i])) { header('Location: model.php'); exit; }

    // `use` CONNECTS. `engine` PROMOTES. They were one action when there was one
    // slot, and collapsing them again would mean you cannot set up a machine
    // without handing it every world you have.
    $base = (string)$list[$i]['base'];
    // `engine` is somebody pressing a button and means it whatever the machine
    // is. Filling an EMPTY slot is the app deciding, and the app only ever
    // decides in favour of a machine that costs nothing to run.
    $engine = $act === 'engine'
              || (xeric_model_active($list, $sid) < 0 && xeric_model_auto_engine($base));
    // The set as the screen sees it — the outgoing engine included, so promoting
    // one machine does not disconnect the other.
    $wiredNow = xeric_model_wired($sid);

    xeric_web_session_edit(function (array &$s) use ($base, $engine, $wiredNow): void {
        $s['wired'] = array_values(array_unique(array_merge($wiredNow, [$base])));
        if ($engine) $s['model'] = xeric_model_descriptor($base);
    }, $sid);
    if ($engine) xeric_play_clock_all(false, $sid);
    header('Location: model.php');
    exit;
}

$active = xeric_model_active($list, $sid);         // the engine, or -1
$wired  = xeric_model_wired_at($list, $sid);      // every connected row

xeric_web_head('Xeric');
echo '<style>' . xeric_play_css() . xeric_play_shelf_css() . '</style>';
?>
<body class="shelf">
<div class="shelfwrap">
  <h1 class="mark">XERIC</h1>

  <?php
    // UNDER THE WORDMARK, AND ONLY WHEN IT IS TRUE. Two conditions, not one:
    // nothing attached, and the worlds actually stopped. Detached with a world
    // still running is a state that should not happen — a world forked before
    // the detach, a worker that reopened one — and the sentence would be a
    // confident lie about the only thing on the screen worth being sure of.
    $stopped = $active < 0 ? xeric_play_all_stopped($sid) : false;
  ?>
  <?php $no = trim((string)($_GET['no'] ?? '')); ?>
  <?php if ($no !== ''): ?>
    <p class="mstop bad"><?= h(mb_substr($no, 0, 400)) ?></p>
  <?php endif; ?>
  <?php if ($active < 0): ?>
    <!-- ONE FLEX CHILD, TWO PARAGRAPHS. .shelfwrap is a flex column with a
         3rem gap, so two loose <p>s are two children and get a room's worth of
         space between two sentences that are one thought. -->
    <div class="mstops">
      <p class="mstop"><?= $wired === [] ? 'Nothing is connected.' : 'No engine is set.' ?></p>
      <?php if ($wired === []): ?>
        <!-- THE FIRST-RUN SENTENCE. This is the screen a fresh install opens on,
             and it must say the one thing a lamp cannot: found is not connected.
             The probe lights what is alive; nothing is attached until a person
             presses it. -->
        <p class="mstop">A lit lamp below means a model is already answering there.
          Nothing runs on it until you press the card — connecting is yours to do.</p>
      <?php endif; ?>
      <?php if ($stopped === true): ?>
        <!-- And only when it is true. "All xerics frozen" is a claim about
             xerics; with none forged it is a claim about nothing, and a
             reader goes looking for the ones it means.

             A XERIC, singular, is one world — the thing on a tile. The app is
             Xeric and the things it makes are xerics, which is the vocabulary
             every platform with a shape eventually grows (a Wii had channels).
             This is the first place in the app that says it out loud. -->
        <p class="mstop">All xerics frozen.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <!-- THE SAME LINE, THE OTHER WAY ROUND. This spot has only ever spoken up
         when something was wrong, which means the state it never described is
         the one somebody arrives in when everything is fine — and a screen that
         is silent about working is a screen you cannot confirm anything from.
         The word is ENGINE and not the address, because the address is on the
         card six inches below and this line is about the slot. -->
    <!-- THE STATE AND THE WAY ON, ON ONE LINE. The button used to sit at the
         bottom, under the discovered machines and the imaging list, which put
         the whole reason for the screen below two things somebody may never
         scroll to. Beside the sentence that says the screen is done, it is where
         the eye already is. -->
    <div class="mstops row">
      <p class="mstop on">Engine Connected</p>
      <a class="btn go" href="forge.php?fresh=1">Continue &rarr;</a>
    </div>
  <?php endif; ?>

  <ul class="mlist" id="mlist">
    <?php foreach ($list as $i => $row):
        $kind = xeric_model_kind((string)$row['base']);
        $on   = $i === $active;                       // the engine
        $lit  = in_array($i, $wired, true);           // connected, engine or not
        ?>
      <li>
        <div class="opt<?= $on ? ' on' : ($lit ? ' lit' : '') ?>">
          <?php if (!$lit): ?>
            <!-- The whole card attaches this machine. A stretched submit rather
                 than a wrapping <button>, so the buttons below can be real
                 buttons instead of illegal nested ones. -->
            <form method="post" action="model.php">
              <input type="hidden" name="act" value="use">
              <input type="hidden" name="i" value="<?= $i ?>">
              <button type="submit" class="mpick" aria-label="use this machine"></button>
            </form>
          <?php endif; ?>

          <span class="thead">
            <?php if (!$edit): ?>
              <span class="t"><?= h((string)preg_replace('#^https?://#', '', $row['base'])) ?></span>
            <?php else: ?>
              <!-- ALWAYS EDITABLE, and the rule it replaces was a trap. It used
                   to be read-only while a machine was in use, on the theory that
                   changing the address under something that is answering would
                   move every world without saying so. But the machine somebody
                   most needs to correct is the one that is selected and NOT
                   answering — a typo in the port, a model that moved — and that
                   was exactly the state the old rule froze. Being able to retype
                   an address IS the operation "use a different model", and the
                   lamp says what happened within a second of doing it.
                   Its own form, above the stretched picker, so typing does not
                   attach it. -->
              <form method="post" action="model.php" class="tedit" data-i="<?= $i ?>">
                <input type="hidden" name="act" value="edit">
                <input type="hidden" name="i" value="<?= $i ?>">
                <button type="button" class="tshow"><?=
                  h((string)preg_replace('#^https?://#', '', $row['base'])) ?></button>
                <input type="text" name="base" class="t" inputmode="url" autocapitalize="off"
                       autocorrect="off" spellcheck="false" aria-label="address and port"
                       value="<?= h((string)preg_replace('#^https?://#', '', $row['base'])) ?>" hidden>
                <button type="submit" class="tsave" hidden>Save</button>
                <span class="tok" hidden aria-hidden="true">&#10003;</span>
              </form>
            <?php endif; ?>
            <?= xeric_web_meter_html($sid, (string)$row['base']) ?>
          </span>

          <!-- WHO IS ANSWERING, filled in by the probe. Printed empty and left
               empty when nothing came back: every server here speaks the same
               wire format, so a badge guessed from the address would be a
               confident label on a machine that is something else. -->
          <p class="whois" data-i="<?= $i ?>" hidden>
            <span class="wsig" aria-hidden="true"></span><span class="wname"></span> <span class="wmodel"></span>
          </p>

          <!-- Under the meter, at the right-hand edge: the one thing on this card
               that DOES something rather than reporting something. Every card has
               one, and it is the same control both ways round — the state names
               itself at the far end of the status row, and the verb sits up here
               where the eye is already reading numbers.

               THE WHOLE CARD ALSO STILL ATTACHES (.mpick, stretched underneath).
               A button is what somebody looks for; a card-sized target is what
               they hit. Both post the same thing, so it does not matter which
               one a finger lands on. -->
          <div class="mact">
            <?php if (!$lit): ?>
              <form method="post" action="model.php">
                <input type="hidden" name="act" value="use">
                <input type="hidden" name="i" value="<?= $i ?>">
                <button type="submit" class="btn join">Connect</button>
              </form>
            <?php else: ?>
              <?php if (!$on): ?>
                <!-- Offered to anything already connected, API included: choosing
                     what runs your worlds is a decision somebody is allowed to
                     make. It is only the app that will not make it for them. -->
                <form method="post" action="model.php">
                  <input type="hidden" name="act" value="engine">
                  <input type="hidden" name="i" value="<?= $i ?>">
                  <button type="submit" class="btn small ghost">Make engine</button>
                </form>
              <?php endif; ?>
              <form method="post" action="model.php">
                <input type="hidden" name="act" value="off">
                <input type="hidden" name="i" value="<?= $i ?>">
                <button type="submit" class="btn cut">Disconnect</button>
              </form>
            <?php endif; ?>
          </div>
          <span class="d"><?= h(xeric_model_says($kind)) ?></span>

          <?php if ($kind === 'local'): ?>
            <!-- Printed empty and filled in by the probe, which is the only
                 thing that knows both that this address is dead and where a
                 live one is. -->
            <form method="post" action="model.php" class="tfound" hidden>
              <input type="hidden" name="act" value="edit">
              <input type="hidden" name="i" value="<?= $i ?>">
              <input type="hidden" name="base" value="">
              <button type="submit" class="btn ghost small"></button>
            </form>
          <?php endif; ?>

          <!-- ANSWERING means it is answering THIS. Available means it is up and
               nothing is asking. IN USE is only ever printed beside a machine
               that can actually be used — the card read "no answer · in use",
               which is two halves of the truth arranged into a lie. It starts
               hidden and the probe reveals it. -->
          <!-- TWO LAMPS, TWO DIFFERENT FACTS, and they were one before. On the left,
               can this address be reached at all. On the right, is it the one
               being used. "answering · in use" tried to carry both in a single
               phrase and could not: a machine can be up and idle, chosen and
               dead, or any other pairing, and one sentence has to pick a side. -->
          <span class="status">
            <span class="dot" data-i="<?= $i ?>"></span><span class="said" data-i="<?= $i ?>"
              data-on="<?= $on ? '1' : '0' ?>" data-remote="<?= $kind === 'local' ? '0' : '1' ?>"
              data-up="<?= $kind === 'local' ? 'Local AI Available' : 'Available' ?>"><?=
              $kind === 'local' ? 'asking…' : 'needs a key, so it cannot be checked from here' ?></span>

            <!-- THE STATE, AT THE RIGHT-HAND END, AND THERE ARE THREE OF THEM.
                 Connected is not one thing any more: a machine can be set up and
                 waiting for a build, or it can be the one every world is
                 actually running on. Those are different facts and the second
                 one is singular. -->
            <span class="wired<?= $on ? ' on engine' : ($lit ? ' on' : '') ?>">
              <span class="dot"></span><?= $on ? 'engine' : ($lit ? 'connected' : 'not connected') ?></span>

          </span>
        </div>
        <?php if ($row['fixed']): ?>
          <span class="mgap" aria-hidden="true"></span>
        <?php else: ?>
          <form method="post" action="model.php">
            <input type="hidden" name="act" value="drop">
            <input type="hidden" name="i" value="<?= $i ?>">
            <button type="submit" class="mx" aria-label="forget this machine"
                    title="forget this machine">&times;</button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>

    <li>
      <form method="post" action="model.php" class="mrow">
        <input type="hidden" name="act" value="add">
        <div class="opt madd" id="maddcard">
          <span class="t">Add a machine</span>
          <span class="d">Anything that speaks the OpenAI chat API: llama.cpp, Ollama, vLLM, LM Studio,
            or an API you have a key for.</span>
          <input type="text" name="base" id="maddin" inputmode="url" autocapitalize="off"
                 autocorrect="off" spellcheck="false" placeholder="host:port" hidden>
        </div>
      </form>
      <span class="mgap" aria-hidden="true"></span>
    </li>
  </ul>

  <?php
    // Said only when there is an API connected, because that is when the rule is
    // something somebody is living with rather than a fact about the app.
    $anyRemote = false;
    foreach ($wired as $wi) {
        if (isset($list[$wi]) && !xeric_model_auto_engine((string)$list[$wi]['base'])) $anyRemote = true;
    }
  ?>
  <?php if ($anyRemote): ?>
    <p class="mnote">An API can forge, and it can be the engine if you make it one, but Xeric
      will never move the engine onto it by itself. Disconnect a machine of your own and the xerics
      stop rather than quietly carrying on against a meter. Note that the key is asked for at each
      build and never written down, so an API engine cannot yet run a xeric&rsquo;s own hours.</p>
  <?php endif; ?>

  <!-- FOUND, NOT ADDED. Everything here is one click from being on the list
       above and nothing here is on it yet: a screen that quietly attached
       whatever it discovered would be deciding, on a machine that might be
       running four models for four reasons, which one this app gets to use. -->
  <section class="found" id="found" hidden>
    <h2 class="fh">Also running on this machine</h2>
    <ul class="mlist" id="foundlist"></ul>
  </section>

  <!-- PICTURES. The engine draws now: portraits, places, and the photos
       characters send in conversation, all through one address the reaper
       reads. Chosen here because the machines screen is where somebody sets
       up what Xeric can reach — and the whole photo feature stays dormant,
       costing nothing, until an address is set AND each world's owner says
       yes to its own cost-warned offer. -->
  <?php $imgEp = xeric_image_endpoint(); ?>
  <section class="found" id="art" <?= $imgEp === null ? 'hidden' : '' ?>>
    <h2 class="fh">Imaging model</h2>
    <?php if ($imgEp !== null): ?>
    <ul class="mlist">
      <li><div class="opt fopt">
        <span class="thead"><span class="t"><?= h(preg_replace('#^https?://#', '', (string)$imgEp['base'])) ?></span></span>
        <p class="whois"><span class="wname">the image machine</span>
          <span class="wmodel" id="artlamp">checking…</span></p>
        <?php if (xeric_image_costly($imgEp)): ?>
        <p class="whois"><strong>This address is not on this computer: every render bills an API
          key</strong> (XERIC_IMAGE_KEY, environment only — keys are never stored here), including
          photos characters decide to send while you are away. The meter counts every one, but the
          meter is a receipt, not a limit — set spending caps with your provider.</p>
        <?php else: ?>
        <p class="whois">Local: renders cost your own electricity and GPU time, nothing metered.
          Every render is still counted on the meter.</p>
        <?php endif; ?>
        <div class="mact"><form method="post" action="model.php">
          <input type="hidden" name="act" value="imageoff">
          <button type="submit" class="btn">Disconnect</button>
        </form></div>
      </div></li>
    </ul>
    <?php endif; ?>
    <ul class="mlist" id="artlist"></ul>
  </section>


  <p class="corner"><?= xeric_web_meter_html() ?> · <a href="play.php">back</a></p>
</div>

<script>
  // ONE PROBE, ONE PLACE. There were two copies of this — the page-load pass and
  // the one after an inline save — and teaching only the first about the offer
  // meant a card fixed in place never showed it. Two functions doing one job is
  // two functions to remember.
  // The badge under the address. Left hidden when the server would not say —
  // "OpenAI-compatible" is a real answer, silence is not, and a label invented
  // to fill the space is exactly the confident-and-wrong this is meant to end.
  function paintWho(i, who) {
    var el = document.querySelector('.whois[data-i="' + i + '"]');
    if (!el) return;
    if (!who || !who.name) { el.hidden = true; return; }
    el.querySelector('.wname').textContent = who.name;
    el.querySelector('.wmodel').textContent = who.model || '';
    el.querySelector('.wsig').className = 'wsig ' + (who.local ? 'here' : 'away');
    el.hidden = false;
  }

  function probe(i) {
    var dot   = document.querySelector('.status > .dot[data-i="' + i + '"]'),
        said  = document.querySelector('.said[data-i="' + i + '"]'),
        card  = dot && dot.closest('.opt'),
        found = card && card.querySelector('.tfound');
    if (!dot) return;

    dot.className = 'dot';
    if (said && said.dataset.remote !== '1') said.textContent = 'asking\u2026';

    fetch('model.php?a=probe&i=' + encodeURIComponent(i))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        // A remote endpoint cannot be probed honestly, so it keeps its grey lamp
        // — but it can still SAY whose it is, which is read off the hostname and
        // costs no request at all.
        if (d.up === null) { paintWho(i, d.who); return; }

        dot.classList.add(d.up ? 'up' : 'down');
        // Answering is what a machine does when something is asking it.
        // The left lamp answers one question only: can this be reached. Whether
        // it is the one in use is the right-hand lamp's job now.
        if (said) said.textContent = d.up ? (said.dataset.up || 'Available') : 'no answer';
        paintWho(i, d.who);
        // And a dead card offers the port the probe can actually see.
        if (found) {
          if (!d.up && d.found) {
            found.querySelector('input[name=base]').value = d.found;
            found.querySelector('button').textContent =
              'Use ' + d.found.replace(/^https?:\/\//, '') + ': something is answering there';
            found.hidden = false;
          } else {
            found.hidden = true;
          }
        }
      },
      // TWO ARGUMENTS, NOT A CHAINED .catch(). A rejection handler chained after
      // the success handler also catches anything the SUCCESS handler throws —
      // so when a repaint function went missing in an edit, every card silently
      // read as though the probe had failed, with nothing in the console. This
      // form handles the fetch failing and lets a bug in the painting be a bug.
      function () {
        dot.classList.add('down');
        if (said) said.textContent = 'no answer';
      });
  }

  document.querySelectorAll('.dot[data-i]').forEach(function (el) { probe(el.dataset.i); });

  // -- what else is here -----------------------------------------------------
  // One request, after the page is drawn. What comes back is offered, never
  // taken: an install that attached whatever it found would be choosing, on a
  // machine running four models for four reasons, which one this app gets.
  fetch('model.php?a=scan')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      paintFound('found', 'foundlist', (d && d.models) || [], true);
      paintFound('art', 'artlist', (d && d.art) || [], false);
    })
    .catch(function () {});

  // The image machine's lamp: asked after the page is up, like every other
  // light on this screen, and honest about the one thing worth being sure of.
  var artlamp = document.getElementById('artlamp');
  if (artlamp) {
    fetch('model.php?a=iprobe').then(function (r) { return r.json(); })
      .then(function (d) {
        artlamp.textContent = d && d.up
          ? 'answering — each world offers to develop its photos from its own play screen'
          : 'not answering right now — worlds fall back to captions, which still works';
      })
      .catch(function () { artlamp.textContent = 'not answering right now'; });
  }

  function paintFound(sectionId, listId, rows, addable) {
    if (!rows.length) return;
    var ul = document.getElementById(listId);
    rows.forEach(function (row) {
      var addr = row.base.replace(/^https?:\/\//, '');
      var li = document.createElement('li');
      li.innerHTML =
        '<div class="opt fopt">' +
          '<span class="thead"><span class="t"></span></span>' +
          '<p class="whois"><span class="wsig here"></span><span class="wname"></span>' +
            '<span class="wmodel"></span></p>' +
        '</div>';
      // textContent throughout: a model id is a file name off a server, and a
      // file name is somewhere somebody can put a tag.
      li.querySelector('.t').textContent = addr;
      li.querySelector('.wname').textContent = row.who.name || '';
      li.querySelector('.wmodel').textContent = row.who.model || '';

      if (!addable) {
        // CONNECT MEANS CONNECT NOW. This button used to answer "coming soon";
        // the engine draws today, so it wires the address as the image machine
        // through the same one-press shape the model rows use. Each world still
        // asks its own cost-warned question before anything renders.
        var f2 = document.createElement('form');
        f2.method = 'post'; f2.action = 'model.php'; f2.className = 'mact';
        f2.innerHTML = '<input type="hidden" name="act" value="image">' +
                       '<input type="hidden" name="base">' +
                       '<button type="submit" class="btn join">Connect</button>';
        f2.querySelector('input[name=base]').value = row.base;
        li.querySelector('.opt').appendChild(f2);
      }

      if (addable) {
        // CONNECT, NOT ADD. What somebody wants from a model they can see
        // running is to use it; putting it on a list and making them press a
        // second button is a step that exists only because the storage has two.
        var f = document.createElement('form');
        f.method = 'post'; f.action = 'model.php'; f.className = 'mact';
        f.innerHTML = '<input type="hidden" name="act" value="adduse">' +
                      '<input type="hidden" name="base">' +
                      '<button type="submit" class="btn join">Connect</button>';
        f.querySelector('input[name=base]').value = row.base;
        li.querySelector('.opt').appendChild(f);
      }
      ul.appendChild(li);
    });
    document.getElementById(sectionId).hidden = false;
  }

  // AN ADDRESS IS TEXT UNTIL SOMEBODY REACHES FOR IT. Click, and a field opens
  // with a Save beside it; save, and the button gives way to a tick that fades
  // and leaves text again. The form still posts on Enter without any of this —
  // the whole block is an enhancement over something that already worked, which
  // is also why a refused save leaves the field open with the reason beside it
  // rather than navigating away from what somebody was in the middle of typing.
  document.querySelectorAll('.tedit').forEach(function (form) {
    var show = form.querySelector('.tshow'),
        inp  = form.querySelector('input.t'),
        save = form.querySelector('.tsave'),
        ok   = form.querySelector('.tok'),
        was  = inp.value;

    function open() {
      show.hidden = true; inp.hidden = false; save.hidden = false;
      inp.focus(); inp.select();
    }
    function close(text) {
      if (text !== undefined) { inp.value = text; show.textContent = text; was = text; }
      else                    { inp.value = was; }
      inp.hidden = true; save.hidden = true; show.hidden = false;
      note(form, '');
    }

    show.addEventListener('click', open);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (inp.value.trim() === '' || inp.value === was) { close(); return; }

      var body = new FormData(form);
      body.append('json', '1');
      save.disabled = true;
      fetch('model.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          save.disabled = false;
          if (!d.ok) { note(form, d.why || 'that address was not taken'); return; }

          // Save gives way to the tick, the tick fades, the text comes back.
          save.hidden = true;
          ok.hidden = false;
          requestAnimationFrame(function () { ok.classList.add('go'); });
          setTimeout(function () {
            ok.classList.remove('go');
            ok.hidden = true;
            close(d.base);
            probe(form.dataset.i);             // the address moved; so did the answer
          }, 1200);
        })
        .catch(function () { save.disabled = false; note(form, 'that did not save'); });
    });
  });

  function note(form, why) {
    var card = form.closest('.opt');
    var el = card.querySelector('.tnote');
    if (!el) { el = document.createElement('span'); el.className = 'tnote'; card.appendChild(el); }
    el.textContent = why || '';
    el.hidden = !why;
  }

  // The card is the field, once you click it.
  var card = document.getElementById('maddcard'), inp = document.getElementById('maddin');
  card.addEventListener('click', function (e) {
    if (e.target === inp) return;
    inp.hidden = false;
    inp.focus();
  });
  inp.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { inp.hidden = true; inp.value = ''; }
  });
  inp.addEventListener('blur', function () { if (inp.value.trim() === '') inp.hidden = true; });
</script>
