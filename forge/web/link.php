<?php
/**
 * link.php — a second device, as the same person.
 *
 *     GET  link.php                     this session's devices, and the form
 *     POST link.php {a: mint, label, kid}   a link code, shown once (and a QR on --lan)
 *     POST link.php {a: revoke, id}     that device's token stops working
 *     POST link.php {a: lower, id}      that device may no longer be shown adult material
 *     POST link.php {a: clear}          the codes still waiting are put out
 *
 * A headset, a phone app, a script: anything that is not a browser on this
 * machine holds a device token (api-lib.php), and this is where one comes
 * from. The owner presses the button HERE, on the box, and types the code it
 * shows into the device, which sends it to `POST api.php/v1/link` and gets a
 * token for this session back. pair.php's ceremony: eight characters, five
 * minutes, once, hashed, and a door that stops answering after five wrong
 * guesses. Being able to see this screen is the authentication.
 *
 * THE OWNER'S, ON THE BOX. Identified by the session cookie or, on a solo
 * install, by being this machine — and NEVER by a device token: a request
 * carrying an Authorization header is refused before anything is read. A
 * token works on api.php and nowhere else; machine admin (this page,
 * model.php, power.php, notify.php, the forge) stays on the box, so a lost
 * headset is one revoke away from being nothing at all.
 *
 * CROSS-SITE IS REFUSED ON EVERY METHOD, the GET included — the page shows a
 * code, and a code is a token five minutes from now. And on a solo install
 * the Host header is pinned (api-lib.php's xeric_api_host_pinned): a page
 * whose name was rebound to 127.0.0.1 is same-origin to itself, and could
 * otherwise mint a code here, read it, and redeem it.
 *
 * THE KIDS' HEADSET IS ONE FLAG. A device carries the session's affirmation
 * as it stood when it was linked, or less: tick "for a child" and it is
 * linked lowered, and any device can be lowered later. Nothing raises one.
 */

declare(strict_types=1);

require_once __DIR__ . '/api-lib.php';

$refuse = function (int $status, string $title, string $why): void {
    http_response_code($status);
    xeric_web_head('Xeric: ' . $title);
    echo '<main class="lk"><h1>' . h($title) . '</h1><p>' . h($why) . '</p></main>';
    exit;
};

if (xeric_api_authorization($_SERVER) !== '') {
    $refuse(403, 'Not with a device token',
        'This page is the owner\'s, on the machine itself. A device token opens the client API and nothing else.');
}
xeric_web_spend_guard();                                    // cross-site, on every method
if (xeric_web_solo() && !xeric_api_host_pinned($_SERVER)) {
    $refuse(403, 'Not at that address',
        'This machine shows its owner this page only at its own address (127.0.0.1, localhost, or the address '
        . 'it was started on).');
}

$sid  = xeric_session_id();
$made = null;
$note = '';
$bad  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    xeric_web_csrf_guard();
    $a  = (string)($_POST['a'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($a === 'mint') {
            $made = xeric_api_link_new($sid, (string)($_POST['label'] ?? ''), empty($_POST['kid']));
        } elseif ($a === 'revoke') {
            $note = xeric_api_device_revoke($sid, $id) ? 'That device is unlinked. Its token no longer works.'
                                                      : 'There is no such device linked to you.';
        } elseif ($a === 'lower') {
            $note = xeric_api_device_lower($sid, $id) ? 'That device will no longer be shown adult material.'
                                                     : 'That device was already kept to the gentlest rating.';
        } elseif ($a === 'clear') {
            xeric_api_link_clear($sid);
            $note = 'Every code that was waiting has been put out.';
        }
    } catch (Throwable $e) {
        $bad = $e->getMessage();
    }
}

$adult   = xeric_session_adult($sid);
$devices = xeric_api_devices($sid);
$https   = !in_array(strtolower(trim((string)($_SERVER['HTTPS'] ?? ''))), ['', 'off'], true);
$lanOpen = !in_array((string)(getenv('XERIC_BIND') ?: '127.0.0.1'), ['127.0.0.1', 'localhost', '::1'], true);
$qr      = null;
if ($made !== null) {
    $lan = xeric_play_lan_url('api.php/v1/');
    // The code rides in the fragment, which is never sent to any server and
    // so never lands in a request log; the device reads it and POSTs it.
    if ($lan !== null) $qr = xeric_qr_svg($lan . '#link=' . $made['code'], 4);
}

xeric_web_head('Xeric: link a device');
?>
<style>
main.lk{max-width:36rem;margin:0 auto;padding:2rem 1.15rem 3rem}
main.lk h1{margin:.2rem 0 .4rem}
.lk .sub{opacity:.8;line-height:1.5;margin:0 0 1.2rem}
.lk .code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:2rem;letter-spacing:.2em;
  margin:.6rem 0;padding:.7rem 1rem;border:1px solid;border-radius:.5rem;display:inline-block}
.lk .qr svg{width:12rem;height:12rem;margin:.4rem 0}
.lk form.mint{display:flex;flex-direction:column;gap:.45rem;margin:1rem 0 1.6rem}
.lk input[type=text]{font:inherit;font-size:1.05rem;padding:.6rem;border:1px solid;border-radius:.4rem;
  background:transparent;color:inherit}
.lk table{width:100%;border-collapse:collapse;font-size:.92rem}
.lk td,.lk th{text-align:left;padding:.45rem .3rem;border-bottom:1px solid rgba(0,0,0,.12);vertical-align:top}
.lk td form{display:inline}
.lk .note{opacity:.8;font-size:.9rem;line-height:1.55}
.lk .warn{border-left:2px solid #c2694b;padding:.2rem 0 .2rem .7rem}
</style>
<main class="lk">
  <h1>Link a device</h1>
  <p class="sub">A headset, a phone, or a script, playing as you. Make a code here, type it into the
    device, and it is linked: same worlds, same machines, same evening.</p>

<?php if ($bad !== ''): ?>
  <p class="note warn"><?= h($bad) ?></p>
<?php endif; ?>
<?php if ($note !== ''): ?>
  <p class="note"><?= h($note) ?></p>
<?php endif; ?>

<?php if ($made !== null): ?>
  <p>Type this into the device within five minutes. It works once.</p>
  <p class="code" id="code"><?= h($made['code']) ?></p>
  <?php if ($qr !== null): ?><div class="qr"><?= $qr ?></div><?php endif; ?>
  <p class="note">The device sends it as <code>POST api.php/v1/link {"code": "…"}</code> and gets a token
    back<?= $made['adult'] ? '' : ', kept to the gentlest rating' ?>.</p>
<?php endif; ?>

<?php if (!$https && $lanOpen): ?>
  <p class="note warn">This machine is answering the network over plain http, so a token handed over it
    could be read by anybody on that network. The API will only hand one to a device on this machine (Quest
    Link, or a Quest with <code>adb reverse tcp:8787 tcp:8787</code>), over TLS
    (<code>tailscale serve --bg http://127.0.0.1:8787</code>), or when the operator has set
    <code>XERIC_LAN_TOKENS=1</code>.</p>
<?php endif; ?>

  <form method="post" action="link.php" class="mint">
    <input type="hidden" name="a" value="mint">
    <label for="label">What to call it</label>
    <input type="text" id="label" name="label" maxlength="60" placeholder="the headset" autocomplete="off">
<?php if ($adult): ?>
    <label><input type="checkbox" name="kid" value="1"> For a child: never show it adult material</label>
<?php else: ?>
    <p class="note">You have not said you are an adult, so a device linked to you is kept to the gentlest
      rating too.</p>
<?php endif; ?>
    <button type="submit" class="nbtn">Make a code</button>
  </form>

  <h2>Linked to you</h2>
<?php if ($devices === []): ?>
  <p class="note">Nothing yet.</p>
<?php else: ?>
  <table id="devices">
    <tr><th>Device</th><th>As</th><th>Adult</th><th>Last seen</th><th></th></tr>
<?php foreach ($devices as $d): ?>
    <tr data-device="<?= (int)$d['id'] ?>">
      <td><?= h($d['label'] !== '' ? $d['label'] : 'a device') ?></td>
      <td><?= h($d['role'] === 'owner' ? 'you' : 'a guest') ?></td>
      <td><?= $d['adult'] ? 'yes' : 'no' ?></td>
      <td><?= $d['seen'] === null ? 'never' : h(gmdate('Y-m-d H:i', (int)$d['seen']) . ' UTC') ?></td>
      <td>
<?php if ($d['adult']): ?>
        <form method="post" action="link.php"><input type="hidden" name="a" value="lower">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button type="submit">Make it a child's</button></form>
<?php endif; ?>
        <form method="post" action="link.php"><input type="hidden" name="a" value="revoke">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button type="submit">Unlink</button></form>
      </td>
    </tr>
<?php endforeach; ?>
  </table>
<?php endif; ?>

  <form method="post" action="link.php"><input type="hidden" name="a" value="clear">
    <button type="submit" class="nbtn">Put out waiting codes</button></form>
  <p class="note">Tokens open the client API (<code>api.php</code>) and nothing else: this page, the
    machines screen, the power switch, notifications and the forge stay on this machine.</p>
</main>
