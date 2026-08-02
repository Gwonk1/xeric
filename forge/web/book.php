<?php
/**
 * book.php — the world writing its own novel.  ?w=<slug>[&from=YYYY-MM-DD][&days=N]
 *
 * The lived record printed as a BOOK: world days, newest first, each chapter
 * holding what that day actually produced — the hours the sweep wrote (title
 * and prose, as they already print everywhere the player looks), the player's
 * own conversations reduced to scene lines (who, and at what hour; a book is
 * not a chat log and the transcripts stay in the threads), dreams when an hour
 * was one, and what is owed (docs/CONSTRUCTS.md: a promise is a fact about a
 * coming day, so open promises sit on the current page and a miss sits on the
 * day it happened).
 *
 * WHAT THIS PAGE MAY NEVER PRINT. The book shows the USER's view of the world
 * and nothing below it: no memory, no interior, no decision trail, not one
 * sentence out of a thread. Events are commons — play.php hands the same rows
 * to whoever is playing — and everything else here is a count, an hour or a
 * name. The one thing read off the inspector's trail is the KIND of an hour,
 * so a dream can be set in its own register; the reasoning stays on why.php.
 *
 * AND ONLY FOR ITS OWNER. Not because a page this careful leaks — because the
 * record it binds is the owner's world entire, months of it in one scroll, and
 * the shelf's rule (xeric_play_guard) is that a world's insides are read by
 * whoever forged it and lived by everybody else. A stranger is refused at the
 * same door with the same sentence as the inspector, before the open, so the
 * refusal leaves no forked database behind.
 *
 * PAGED BY DAYS, not by rows: ?from= names the newest day on the page, ?days=
 * how far back it reads (default the last 7 lived days). A world with months
 * behind it turns pages; it does not arrive as megabytes.
 *
 * (play.php has a SCREEN it calls "the book" — the old single-document view,
 * data-screen="world" (renamed from "book" the day this page arrived), which
 * is the time control and the map. This page is the
 * other book, the printed one, and it deliberately does not reuse that name in
 * markup: its sections are .bday, its idiom is type, and the only thing the
 * two share is a spine.)
 *
 * Read-only, start to finish. No model call, no write, no clock moved. Print
 * it: the chrome stays on the desk and the column goes to paper (@media print
 * in xeric_book_css).
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

// ---------------------------------------------------------------------------

$slug = xeric_web_slug((string)($_GET['w'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$days = (int)($_GET['days'] ?? 0);

// Before the open, not after: opening a stranger's world forks a copy of its
// database into this session, and a page about to refuse leaves nothing behind.
xeric_play_guard($slug, 'The book is this xeric\'s whole lived record printed as a story — every hour '
    . 'it produced, every day of it, bound in order. That is the diary of whoever forged it, and it '
    . 'reads as one.');

try {
    $w = xeric_play_open($slug);
} catch (Throwable $e) {
    xeric_web_head('Xeric: book');
    echo '<style>' . xeric_play_css() . xeric_book_css() . '</style>';
    echo '<main class="book"><div class="top"><p class="wordmark">XERIC</p><span class="kicker">book</span></div>';
    echo '<h1>There is nothing to read</h1><p class="note bad">' . h($e->getMessage()) . '</p>';
    echo '<p><a href="play.php">The xerics that are here →</a></p></main>';
    exit;
}

$T   = $w['template'];
$db  = $w['db'];
$now = xeric_clock_now($db, $T);

$book  = xeric_book_days($T, $db, $now, $from, $days);
$pager = $book['pager'];
$me    = trim((string)($T['user']['name'] ?? '')) ?: 'you';

$page = fn(string $day): string => 'book.php?w=' . rawurlencode($w['slug'])
    . '&amp;from=' . rawurlencode($day) . ($days > 0 ? '&amp;days=' . $days : '');

xeric_web_head('Xeric: book · ' . (string)$T['meta']['name']);
echo '<style>' . xeric_play_css() . xeric_book_css() . '</style>';
?>
<main class="book">
  <div class="top">
    <p class="wordmark">XERIC</p>
    <span class="kicker">book</span>
    <?= xeric_web_meter_html() ?>
    <span class="count"><a href="play.php?w=<?= h(rawurlencode($w['slug'])) ?>">back to the xeric</a></span>
  </div>

  <h1 class="btitle"><?= h((string)$T['meta']['name']) ?></h1>
  <p class="bsub">the days as they happened, most recent first ·
    <?= h(xeric_play_date_line($T, $now)) ?></p>

<?php if ($book['days'] === []): ?>
  <p class="bempty">Nothing has been set down in these days. A xeric writes its book as it lives —
    press the time control and there will be a page here, or turn back to where the story was.</p>
<?php endif; ?>

<?php foreach ($book['days'] as $d): ?>
  <section class="bday">
    <h2 class="dayh"><?= h($d['label']) ?></h2>

    <?php if ($d['promises'] !== []): ?>
    <!-- What is owed belongs on the current page: a promise is a fact about a
         coming day, and this is the day it is read from. Coarse state only,
         exactly as the arc carries it — never a countdown. -->
    <div class="vows">
      <?php foreach ($d['promises'] as $p): ?>
      <p class="vow"><?= h(xeric_book_expect_line($T, $p)) ?><?php if ($p['due'] !== null): ?>
        <span class="voww">— <?= h(xeric_book_heading($T, (int)$p['due'])) ?></span><?php endif; ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($d['items'] as $it): ?>
      <?php if ($it['kind'] === 'event'): $e = $it['event'];
        $who = [];
        foreach ((array)$e['participants'] as $p) {
            $n = xeric_world_name($T, (string)$p);
            if ($n !== '') $who[] = $n;
        }
        $place = xeric_world_place_name($T, (string)($e['place'] ?? ''));
      ?>
      <article class="bev">
        <h3 class="bevt"><?= h((string)$e['title']) ?></h3>
        <p class="bevw"><?= h(xeric_book_hour($T, (int)$e['world_epoch'])) ?><?php
          if ($place !== '') echo ' · ' . h($place);
          if ($who !== []) echo ' · ' . h(implode(', ', $who));
        ?></p>
        <?php if (trim((string)($e['prose'] ?? '')) !== ''): ?>
        <p class="bevp"><?= h((string)$e['prose']) ?></p>
        <?php endif; ?>
      </article>

      <?php elseif ($it['kind'] === 'dream'): $e = $it['event']; ?>
      <!-- The one kind of hour only the book could ever have seen. Its own
           register: italic, set apart, and never mistaken for the waking day. -->
      <aside class="dream">
        <p><?= h(trim((string)($e['prose'] ?? '')) !== '' ? (string)$e['prose'] : (string)$e['title']) ?></p>
        <p class="dreamw">a dream, <?= h(xeric_book_hour($T, (int)$e['world_epoch'])) ?></p>
      </aside>

      <?php elseif ($it['kind'] === 'scene'): $s = $it['scene'];
        $a = xeric_book_hour($T, (int)$s['first']);
        $b = xeric_book_hour($T, (int)$s['last']);
        $lines = (int)$s['yours'] + (int)$s['theirs'];
      ?>
      <p class="scene"><?= h(ucfirst($me)) ?> and <?= h((string)$s['name']) ?> spoke,
        <?= h($a === $b ? 'at ' . $a : $a . '–' . $b) ?> —
        <?= $lines === 1 ? 'a single line' : $lines . ' lines' ?> between them.</p>

      <?php elseif ($it['kind'] === 'miss'): $x = $it['expect'];
        // The three faces a miss can wear, in the ledger's own states: still
        // raw, mended with a reason, or hardened by the want of one.
        $missw = match ($x['status']) {
            'repaired' => 'missed — and later mended with a reason',
            'hardened' => 'missed — and never explained',
            default    => 'the hour came and went, ' . xeric_book_hour($T, (int)$x['due']),
        };
      ?>
      <p class="vow missed"><?= h(xeric_book_expect_line($T, $x)) ?>
        <span class="voww">— <?= h($missw) ?></span></p>
      <?php endif; ?>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

  <nav class="bpager">
    <span><?php if ($pager['later'] !== ''): ?><a href="<?= $page($pager['later']) ?>">← later days</a><?php endif; ?></span>
    <span><?php if ($pager['earlier'] !== ''): ?><a href="<?= $page($pager['earlier']) ?>">earlier days →</a><?php endif; ?></span>
  </nav>

  <footer>The book reads <code>worlds/<?= h($w['slug']) ?>/</code> and the database this browser plays
    against. It never calls a model and never writes anything — print it, and the chrome stays behind.</footer>
</main>
</html>
<?php
