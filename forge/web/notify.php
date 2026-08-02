<?php
/**
 * notify.php — what is worth a buzz.
 *
 *     GET  notify.php              the switches
 *     POST notify.php              save them
 *     POST notify.php {act:test}   send one now
 *
 * NOTHING IS ON BY DEFAULT and there is no URL until somebody types one. A world
 * that arrived buzzing would be uninstalled before it was understood, and the
 * fastest way to make somebody mute an app forever is to interrupt them about an
 * ordinary Tuesday.
 *
 * The URL is a whole configuration: `https://ntfy.sh/<something-nobody-guesses>`
 * needs no account and no key, and a self-hosted ntfy is the same line with a
 * different host — which matters for a project whose claim is that it runs on
 * your machine. What travels is deliberately thin (engine/notify.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

$sid = xeric_session_id();
$cfg = xeric_web_notify($sid);
$msg = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ((string)($_POST['act'] ?? '') === 'test') {
        $ok = xeric_notify_send($cfg, 'If you are reading this on a phone, it works.',
            ['title' => 'Xeric', 'tags' => 'wave', 'priority' => 3]);
        header('Location: notify.php?' . ($ok ? 'sent=1' : 'sent=0'));
        exit;
    }

    $url = trim((string)($_POST['url'] ?? ''));
    // A URL that is not a URL is dropped rather than stored, so a typo turns the
    // whole thing off loudly instead of failing silently every time it fires.
    if ($url !== '' && !preg_match('#^https?://\S+$#i', $url)) { $url = ''; $msg = 'That is not a URL.'; }

    $on = [];
    foreach (array_keys(xeric_notify_kinds()) as $k) {
        if (!empty($_POST['on'][$k])) $on[] = $k;
    }
    $every = max(0, (int)($_POST['tokens_every'] ?? 0));

    xeric_web_session_edit(function (array &$s) use ($url, $on, $every): void {
        $s['notify'] = ['url' => $url, 'on' => $on, 'tokens_every' => $every];
    }, $sid);

    if ($msg === '') { header('Location: notify.php?saved=1'); exit; }
    $cfg = xeric_web_notify($sid);
}

$sent  = isset($_GET['sent']) ? (string)$_GET['sent'] : '';
$saved = isset($_GET['saved']);
$on    = array_map('strval', (array)($cfg['on'] ?? []));

xeric_web_head('Xeric');
echo '<style>' . xeric_play_css() . xeric_play_shelf_css() . '</style>';
?>
<body class="shelf">
<div class="shelfwrap">
  <h1 class="mark">XERIC</h1>

  <?php if ($msg !== ''): ?><p class="mstop bad"><?= h($msg) ?></p><?php endif; ?>
  <?php if ($sent === '1'): ?><p class="mstop">Sent.</p><?php endif; ?>
  <?php if ($sent === '0'): ?><p class="mstop bad">That URL did not take it.</p><?php endif; ?>
  <?php if ($saved): ?><p class="mstop">Saved.</p><?php endif; ?>

  <form method="post" action="notify.php" class="nform">
    <input type="text" name="url" id="nurl" inputmode="url" autocapitalize="off" autocorrect="off"
           spellcheck="false" placeholder="https://ntfy.sh/pick-something-nobody-will-guess"
           value="<?= h((string)($cfg['url'] ?? '')) ?>" aria-label="notification URL">

    <ul class="nlist">
      <?php foreach (xeric_notify_kinds() as $k => $what): ?>
        <li>
          <!-- A div, not a label. A label wrapping its own checkbox is already a
               click target, so the script below toggled it a second time and the
               two cancelled — and the number field inside would have flipped the
               switch every time somebody typed in it. -->
          <div class="opt<?= in_array($k, $on, true) ? ' on' : '' ?>">
            <input type="checkbox" id="n-<?= h($k) ?>" name="on[<?= h($k) ?>]" value="1"
                   <?= in_array($k, $on, true) ? 'checked' : '' ?>>
            <label class="t" for="n-<?= h($k) ?>"><?= h($k) ?></label>
            <span class="d"><?= h($what) ?></span>
            <?php if ($k === 'tokens'): ?>
              <span class="d">every
                <input type="number" name="tokens_every" min="0" step="10000"
                       value="<?= (int)($cfg['tokens_every'] ?? 0) ?>"> tokens</span>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="nrow">
      <button class="btn" type="submit">Save</button>
      <button class="btn ghost" type="submit" name="act" value="test">Send one now</button>
    </div>
  </form>

  <!-- The universal meter belongs on every screen that has a corner: it is the
       one number that is about the whole app rather than about this page. -->
  <p class="corner"><?= xeric_web_meter_html() ?> · <a href="model.php">machines</a>
    · <a href="play.php">back</a></p>
</div>

<script>
  // The whole card is the target — except the checkbox, its label and the number
  // field, all of which the browser already handles. Toggling here on a click
  // the browser has handled is how the two cancel out.
  document.querySelectorAll('.nlist .opt').forEach(function (el) {
    var box = el.querySelector('input[type=checkbox]');
    var paint = function () { el.classList.toggle('on', box.checked); };
    box.addEventListener('change', paint);
    el.addEventListener('click', function (e) {
      var t = e.target;
      if (t === box || t.tagName === 'INPUT' || t.tagName === 'LABEL') return;
      box.checked = !box.checked; paint();
    });
  });
</script>
