<?php
/**
 * join.php — somebody scanned the code. ?w=<slug>&c=<code>
 *
 * The whole flow, on one page, because it happens on a phone in somebody's
 * kitchen and every screen between the scan and the world is a screen where
 * they hand the phone back and say it did not work:
 *
 *     scan  →  type your name  →  you are in
 *
 * BEING IN THE ROOM IS THE AUTHENTICATION. There is no password to invent, no
 * account to make, no email. The code was on a screen somebody was holding, it
 * dies in five minutes, and the first person through burns it. That is the
 * right amount of security for two people in a house on their own wifi, and
 * the reasoning for it is written out in engine/pair.php.
 *
 * A GUEST PLAYS. A GUEST DOES NOT OWN. What comes out of here is a session
 * bound to a player number in one world — not a claim on the machine, not the
 * ability to shut the server down, edit the world, or delete anything. The
 * person whose computer it is stays the person whose computer it is.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';
require_once XERIC_WEB_LIB . '/engine/pair.php';

$slug = xeric_web_slug((string)($_GET['w'] ?? ''));
$code = strtoupper(trim((string)($_GET['c'] ?? ($_POST['c'] ?? ''))));
$name = trim((string)($_POST['name'] ?? ''));
$sid  = xeric_session_id();

$dir = xeric_web_worlds_dir() . '/' . $slug;
if ($slug === '' || !is_file($dir . '/world-template.json')) {
    xeric_web_head('Xeric: join');
    echo '<style>' . xeric_play_css() . xeric_join_css() . '</style>';
    echo '<main><div class="top"><p class="wordmark">XERIC</p><span class="kicker">join</span></div>';
    echo '<h1>There is no xeric here</h1>';
    echo '<p class="note">That link points at a world this machine does not have. Ask for a new code.</p>';
    echo '</main>';
    exit;
}

$T     = json_decode((string)file_get_contents($dir . '/world-template.json'), true) ?: [];
$world = trim((string)($T['meta']['name'] ?? $slug));

// ALREADY IN. Somebody who scans the same code twice, or comes back to the tab
// tomorrow, should land in the world rather than at a form telling them their
// code is spent — being already through the door is the opposite of an error.
$already = xeric_session_player($slug, $sid);
if ($already !== null) {
    header('Location: play.php?w=' . rawurlencode($slug));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $code !== '') {
    // This page reads $_POST directly, so it does not pass through
    // xeric_web_input() where the cross-site guard lives. Both of the
    // demonstrated drive-by attacks landed here.
    xeric_web_csrf_guard();

    try {
        // The guest's own copy of the world is NOT forked here: they are joining
        // the owner's evening, which is the entire point. xeric_play_open()'s
        // forking is for strangers reading somebody else's shelf.
        $db = xeric_state_open($dir . '/world.db');
        xeric_state_migrate($db);
        $player = xeric_pair_claim($db, $T, $code, $name);
        xeric_session_join($slug, $player, $sid);
        header('Location: play.php?w=' . rawurlencode($slug));
        exit;
    } catch (Throwable $e) {
        $error = xeric_pair_plain($e->getMessage());
    }
}

xeric_web_head('Xeric: join ' . $world);
echo '<style>' . xeric_play_css() . xeric_join_css() . '</style>';
?>
<main>
  <div class="top"><p class="wordmark">XERIC</p><span class="kicker">join</span></div>

  <h1>Join <?= h($world) ?></h1>
  <p class="sub">Somebody in this house is running a world and asked you in. Tell the people in it
    what to call you.</p>

<?php if ($error !== ''): ?>
  <p class="note bad"><?= h($error) ?></p>
<?php endif; ?>

  <form method="post" action="join.php?w=<?= h(rawurlencode($slug)) ?>" class="joinform">
    <label for="name">Your name</label>
    <input type="text" id="name" name="name" maxlength="40" autofocus autocomplete="given-name"
           placeholder="what they should call you" value="<?= h($name) ?>">

    <label for="c">The code</label>
    <!-- Prefilled from the QR and shown anyway, because a code you can SEE is a
         code you can read out to somebody whose camera will not focus. -->
    <input type="text" id="c" name="c" maxlength="12" autocapitalize="characters"
           autocomplete="off" spellcheck="false" class="code" value="<?= h($code) ?>">

    <button type="submit" class="nbtn go">Go in</button>
  </form>

  <p class="note">You are joining somebody else's world, not making a copy. What you do in it
    happens to them too. The people there will not know you — but they know whose friend you are,
    and they will take you accordingly.</p>
  <p class="note quiet">Codes last five minutes and work once. If this one has run out, ask for
    another.</p>
</main>
<?php

/** The engine's own sentence, without the file prefix a person should not see. */
function xeric_pair_plain(string $m): string
{
    return trim((string)preg_replace('/^(pair|players|guest):\s*/', '', $m));
}

/** The join screen's own skin. */
function xeric_join_css(): string
{
    return <<<'CSS'
main{max-width:32rem}
main>h1{margin:.4rem 0 .3rem}
.sub{opacity:.8;margin:0 0 1.4rem;line-height:1.5}
.joinform{display:flex;flex-direction:column;gap:.35rem;margin:0 0 1.4rem}
.joinform label{font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;opacity:.65;margin-top:.7rem}
.joinform input{font:inherit;font-size:1.15rem;padding:.7rem;border-radius:.5rem;
  border:1px solid;background:transparent;color:inherit;width:100%;box-sizing:border-box}
.joinform input.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.18em;
  text-transform:uppercase}
.joinform .go{margin-top:1.1rem;font-size:1.05rem;padding:.8rem}
.note{opacity:.75;font-size:.9rem;line-height:1.55;margin:.7rem 0}
.note.quiet{opacity:.55;font-size:.85rem}
.note.bad{border:1px solid currentColor;border-radius:.4rem;padding:.6rem .7rem;opacity:1}
CSS;
}
