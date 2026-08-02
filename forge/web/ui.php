<?php
/**
 * ui.php — the page chrome and the one renderer for "here is what got made".
 *
 * The result screen is rendered HERE, on the server, by a single function, and
 * then used two ways: build.php ships it down the live stream when the last
 * pass lands, and forge.php?w=<slug> renders the same markup from the file on
 * disk. One renderer means the world you watched being built and the world you
 * come back to tomorrow cannot disagree.
 *
 * The visual language is the landing page's: paper white, warm near-black, one
 * tungsten accent, no fonts, no CDN. Mobile first — the whole thing is
 * one column that never needs a horizontal scroll, tap targets are finger-sized,
 * and every input is 16px+ so iOS does not zoom the page when it focuses one.
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';

function xeric_web_head(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<title><?= h($title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#f7f6f2">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:,"><!-- no icon yet; without this every desktop browser asks for /favicon.ico and 404s -->
<script><?= xeric_web_theme_js() ?></script>
<style><?= xeric_web_css() ?></style>
<script><?= xeric_web_meter_js() ?></script>
<?php
}

/**
 * "Tokens wasted", top right, on every screen that has a corner.
 *
 * The owner's label and it stays: a meter that said "usage" would be a billing
 * widget, and this is a running count of what a made-up town has cost to keep
 * alive. Both kinds count — the local machine reports usage in the same field
 * a paid API does, and a number that only moved when money was involved would
 * be a bill rather than a meter.
 */
function xeric_web_meter_html(?string $sid = null, string $at = ''): string
{
    $t = xeric_web_tokens($sid, $at);
    $n = $t['in'] + $t['out'];
    // The count lives in the visitor's session, so clearing cookies zeroes it.
    // That is not a limitation to hide — it is the only reset there is, and a
    // meter nobody can zero is a meter people stop reading.
    // THE NUMBER ON SCREEN IS THE CLIENT'S, NOT THIS ONE. What the server can
    // count is one session, and a session ends when somebody clears a cookie —
    // which would quietly forgive them every token they had ever spent, and the
    // whole joke is that nothing is forgiven. So the server reports the session
    // and the browser keeps the running total forever (xeric_web_meter_js),
    // adding what it has not seen yet rather than trusting either side's
    // absolute figure.
    // ONLY THE TOTAL CARRIES THE LEDGER. A machines screen prints one of these
    // per card as well, and the browser's running figure summed every one it
    // could see — so a page with three machines on it counted the same session
    // three times. `data-all` marks the one that means everything.
    // THE TOTAL CARRIES THE SPLIT. A meter that means "everything" ships the
    // per-machine breakdown with it, so one of these on any screen is enough to
    // keep the browser's ledger current for every machine — including ones that
    // screen never draws a card for.
    $extra = $at === ''
        ? ' data-all="1" data-by="' . h((string)json_encode(xeric_web_tokens_by($sid))) . '"'
        : ' data-key="' . h(xeric_web_meter_key($at)) . '"';

    return '<span class="meter"' . $extra . ' data-n="' . $n . '" title="'
        . h(number_format($t['in']) . ' in · ' . number_format($t['out']) . ' out · '
            . number_format($t['calls']) . ' calls this session') . '">'
        . 'Tokens wasted: <b>' . h(xeric_web_tokens_short($n)) . '</b></span>';
}

/**
 * The meter's memory, which lives in the browser and never resets.
 *
 * A ledger of what has been WASTED cannot be cleared by clearing a cookie —
 * that is the entire joke, and a number that forgives you for deleting your
 * history is not a number anybody would bother reading.
 *
 * It accumulates a DELTA rather than trusting a total. The server counts one
 * session; when that session goes away its figure drops back to zero, and a
 * client that stored `max(mine, theirs)` would then freeze until the new
 * session out-spent the old one. So the browser remembers what it last saw the
 * server say, adds only the difference, and treats a figure that went DOWN as a
 * fresh session whose whole count is new.
 */
function xeric_web_meter_js(): string
{
    return <<<'JS'
(function () {
  // THE LEDGER IS PER MACHINE NOW, and the universal figure is the sum of it.
  // It used to be one number, which could say what had been spent but never
  // where — so the machines screen could only show what the CURRENT session had
  // spent at each address, and that drops to zero the moment a cookie goes.
  var K = 'xeric.by', S = 'xeric.seen.by';
  var OLD_K = 'xeric.wasted', OLD_S = 'xeric.seen';

  function readMap(k) {
    try { var v = JSON.parse(localStorage.getItem(k) || '{}'); return (v && typeof v === 'object') ? v : {}; }
    catch (e) { return {}; }
  }
  function writeMap(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function num(k) { try { return parseInt(localStorage.getItem(k) || '0', 10) || 0; } catch (e) { return 0; } }

  // Whatever the single-number ledger had counted is kept, under a machine
  // nobody will ever match. Losing somebody's running total to a refactor is
  // the one thing this feature cannot do.
  (function carry() {
    var by = readMap(K);
    if (by.__carried === undefined) {
      var old = num(OLD_K);
      if (old > 0) by.__carried = old;
      else by.__carried = 0;
      writeMap(K, by);
    }
  })();

  function shorten(n) {
    if (n < 1000) return String(n);
    if (n < 1000000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
    return (n / 1000000).toFixed(2).replace(/\.?0+$/, '') + 'M';
  }

  function total(by) {
    var n = 0;
    for (var k in by) if (Object.prototype.hasOwnProperty.call(by, k)) n += by[k] || 0;
    return n;
  }

  // A session that went backwards is a NEW session and everything in it is new
  // spending; anything else adds only what has appeared since last time.
  function feed(fresh) {
    var by = readMap(K), seen = readMap(S), k;
    for (k in fresh) {
      if (!Object.prototype.hasOwnProperty.call(fresh, k)) continue;
      var now = fresh[k] || 0, was = seen[k] || 0;
      by[k] = (by[k] || 0) + ((now < was) ? now : (now - was));
      seen[k] = now;
    }
    writeMap(K, by); writeMap(S, seen);
    return by;
  }

  function paint(by) {
    var all = total(by);
    document.querySelectorAll('.meter[data-all]').forEach(function (el) {
      var b = el.querySelector('b');
      if (b) b.textContent = shorten(all);
      if (!el.dataset.told) {
        el.title = el.title + "\nall time, kept in this browser, and nothing clears it but you";
        el.dataset.told = '1';
      }
    });
    document.querySelectorAll('.meter[data-key]').forEach(function (el) {
      var b = el.querySelector('b');
      if (b) b.textContent = shorten(by[el.dataset.key] || 0);
    });
  }

  // Read every "everything" meter on the page: each carries the whole split.
  window.xericMeter = function () {
    var fresh = {};
    document.querySelectorAll('.meter[data-all]').forEach(function (el) {
      var by = {};
      try { by = JSON.parse(el.dataset.by || '{}') || {}; } catch (e) { by = {}; }
      for (var k in by) if (Object.prototype.hasOwnProperty.call(by, k)) fresh[k] = by[k];
    });
    paint(feed(fresh));
  };

  // For anything watching work happen: a build streaming its passes, a world
  // page that just took a turn. Same accounting, no page load.
  window.xericMeterFeed = function (by) { if (by) paint(feed(by)); };

  // AFTER THE BODY EXISTS. This block is emitted in <head>, so calling it
  // straight away finds no .meter at all.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.xericMeter);
  } else {
    window.xericMeter();
  }
})();
JS;
}

/**
 * Light or dark, decided before the first pixel.
 *
 * IN THE HEAD AND BEFORE THE STYLESHEET, deliberately. A theme applied after
 * paint is a white flash on every navigation for anybody who chose dark, which
 * is the one thing that makes a site feel like a web page rather than an app.
 * This is why it is a separate block from the meter's script, which can happily
 * wait for a body to exist.
 *
 * THE SYSTEM CHOOSES FIRST, THE PERSON CHOOSES LAST. With nothing stored, it
 * follows prefers-color-scheme; once somebody has pressed the wordmark, their
 * answer outranks the operating system's until they press it again.
 */
function xeric_web_theme_js(): string
{
    return <<<'JS'
(function () {
  var K = 'xeric.theme';
  function want() {
    try { var v = localStorage.getItem(K); if (v === 'dark' || v === 'light') return v; } catch (e) {}
    return (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  }
  function apply(t) {
    document.documentElement.setAttribute('data-theme', t);
    // The browser's own chrome — the address bar on a phone — follows this, and
    // a light bar over a dark page is the seam that gives away a web app.
    var m = document.querySelector('meta[name=theme-color]');
    if (m) m.setAttribute('content', t === 'dark' ? '#12141a' : '#f7f6f2');
  }
  apply(want());

  // THE WORDMARK IS THE SWITCH, on every screen that has one, bound without any
  // markup knowing about it — there are eight of them across five files and a
  // control that has to be added to each is a control that will be missing from
  // one. No title attribute: it is discovered by pressing it.
  function bind() {
    document.querySelectorAll('.mark, .wordmark').forEach(function (el) {
      if (el.dataset.themed) return;
      el.dataset.themed = '1';
      el.setAttribute('role', 'button');
      el.setAttribute('tabindex', '0');
      el.setAttribute('aria-label', 'switch between light and dark');
      var flip = function () {
        var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(K, t); } catch (e) {}
        apply(t);
      };
      el.addEventListener('click', flip);
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); flip(); }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();

  // Somebody who has never pressed it keeps following the system, including
  // when the system changes at sunset.
  if (window.matchMedia) {
    try {
      matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        var stored = null;
        try { stored = localStorage.getItem(K); } catch (e) {}
        if (stored !== 'dark' && stored !== 'light') apply(want());
      });
    } catch (e) {}
  }
})();
JS;
}

function xeric_web_css(): string
{
    return <<<'CSS'
/* THE PALETTE, AND IT IS THE ONLY PLACE COLOUR IS DECIDED.
   Xeric ran in a tungsten-lit dark room until 2026-08-01 and now runs on paper.
   Everything below is a ROLE, never a colour: `--sel` is "the fill of something
   you have chosen", not "a dark brown". Every rule in this file and in
   play-lib.php goes through one of these, so the room can be relit by editing
   nine lines — which is exactly what happened, and would not have been possible
   the week before, when a dozen hexes were typed inline.

   The ground is warm off-white and the CARDS ARE WHITER THAN IT. That inversion
   is the whole trick of a light UI: depth comes from a card being lighter than
   its ground plus a soft shadow, where in the dark theme it came from a card
   being lighter than its ground plus a border. Get it backwards and every
   surface reads as a hole. */
:root{
  --bg:#f7f6f2; --bg-2:#ffffff; --bg-3:#efece4;
  /* --fg-dim was #6a6558, which is a dark grey doing the job of a light one:
     dark enough to read as body text and low enough in contrast to look
     smudged next to it. Secondary text should be plainly secondary. */
  --fg:#1a1813; --fg-dim:#7b7565; --fg-far:#9d978a;
  --line:#ded9cc; --line-2:#e9e5da;
  --accent:#9a5f19; --accent-dim:#c2a173;
  --warn:#8a6516; --bad:#a53f22; --good:#4d6a2e;
  --sel:#fbf5ea;          /* chosen: a warm tint of the card, never a fill */
  --on-accent:#fffdf8;    /* text on an accent field */
  --dot:#d8d2c3;          /* an indicator that is off */
  --glow:rgba(154,95,25,.16);
  --fade:rgba(247,246,242,0);
  --shadow:0 1px 2px rgba(26,24,19,.05), 0 6px 18px rgba(26,24,19,.07);
  --shadow-lift:0 2px 4px rgba(26,24,19,.07), 0 14px 34px rgba(26,24,19,.11);
}

/* ---------------------------------------------------------------- dark */
/* THE SAME ROOM WITH THE LIGHTS OFF, and every value here is the same ROLE as
   above: --sel is still "the fill of something you have chosen". Nothing else in
   this file or in play-lib.php changes, because nothing else names a colour.
   That was the point of doing it this way, and this is the first time it has
   been collected on.
   The ground is near-black and the CARDS ARE LIGHTER than it, which is the
   inverse of the light theme and the reason both read as surfaces rather than
   as holes. */
:root[data-theme="dark"]{
  --bg:#12141a; --bg-2:#1a1d25; --bg-3:#232733;
  --fg:#eceef3; --fg-dim:#a7adbb; --fg-far:#7b8290;
  --line:#2c313d; --line-2:#232733;
  --accent:#e0a45c; --accent-dim:#8a6a3f;
  --warn:#d9b45e; --bad:#e0715a; --good:#8fc06a;
  --sel:#232733;
  --on-accent:#17140f;
  --dot:#3a4050;
  --glow:rgba(224,164,92,.18);
  --fade:rgba(18,20,26,0);
  --shadow:0 1px 2px rgba(0,0,0,.4), 0 6px 18px rgba(0,0,0,.34);
  --shadow-lift:0 2px 4px rgba(0,0,0,.45), 0 14px 34px rgba(0,0,0,.5);
}

/* THE SWITCH IS THE WORDMARK. There is no settings screen to put a toggle on,
   and a xeric is a thing you look at rather than configure — so the one word
   already at the top of every screen does it. No tooltip: a control that has to
   explain itself in a hover is not discoverable on a phone anyway, and this one
   is discovered by pressing it. */
.mark,.wordmark{cursor:pointer;-webkit-tap-highlight-color:transparent;
  user-select:none;-webkit-user-select:none;touch-action:manipulation}
.mark:focus-visible,.wordmark:focus-visible{outline:2px solid var(--accent);outline-offset:4px;border-radius:.2rem}

*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--fg)}
body{
  font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  line-height:1.55;-webkit-font-smoothing:antialiased;overflow-x:hidden;
  padding-bottom:env(safe-area-inset-bottom);
}
main{max-width:40rem;margin:0 auto;padding:1.75rem 1.15rem 7rem}
a{color:var(--accent)}
hr.horizon{border:0;height:1px;margin:1.75rem 0;
  background:linear-gradient(to right,transparent,var(--accent-dim) 15%,var(--accent) 50%,var(--accent-dim) 85%,transparent)}

/* header */
.top{display:flex;align-items:baseline;gap:.6rem;margin:0 0 .25rem}
.wordmark{margin:0;font-size:1.15rem;font-weight:700;letter-spacing:.22em}
.kicker{font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;color:var(--accent)}
.count{margin-left:auto;font-size:.72rem;letter-spacing:.12em;color:var(--fg-dim)}
/* the meter. Right edge, every screen, never competing with the thing it is
   beside — it is a fact somebody may want, not a thing to act on. */
.meter{margin-left:auto;font-size:.72rem;color:var(--fg-dim);white-space:nowrap;
  font-variant-numeric:tabular-nums}
.meter b{color:var(--accent);font-weight:600}
.count + .meter,.meter + .count{margin-left:.75rem}
.rail{display:flex;gap:.25rem;margin:.6rem 0 1.5rem}
.rail i{flex:1;height:2px;background:var(--line);border-radius:2px}
.rail i.on{background:var(--accent)}
.rail i.now{background:var(--accent);box-shadow:0 0 0 1px var(--accent-dim)}

/* questions */
.screen{display:none}
.screen.live{display:block;animation:in .22s ease-out}
@keyframes in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
h1{margin:0 0 .35rem;font-size:clamp(1.5rem,6.2vw,2.1rem);line-height:1.18;font-weight:600;letter-spacing:-.01em}
h2{margin:2rem 0 .6rem;font-size:.78rem;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--accent)}
h2:first-child{margin-top:0}
p{margin:0 0 .9rem}
.sub{color:var(--fg-dim);margin:0 0 1.25rem;font-size:.95rem}
.lead{font-size:1.05rem}

label.field{display:block;margin:0 0 .4rem;font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;color:var(--fg-dim)}
input[type=text],input[type=password],textarea,select{
  width:100%;background:var(--bg-2);color:var(--fg);border:1px solid var(--line);
  border-radius:.55rem;padding:.85rem .9rem;font:inherit;font-size:1.02rem;line-height:1.45;
}
textarea{min-height:5.5rem;resize:vertical}
input:focus,textarea:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 1px var(--accent-dim)}
::placeholder{color:var(--fg-far)}
.hintline{margin:.5rem 0 0;font-size:.85rem;color:var(--fg-dim)}

.chips{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 .25rem;padding:0;list-style:none}
.chip{
  appearance:none;display:block;text-align:left;cursor:pointer;
  background:var(--bg-2);color:var(--fg);border:1px solid var(--line);
  border-radius:.9rem;padding:.6rem .85rem;font:inherit;font-size:.95rem;min-height:2.75rem;
}
.chip .ch{display:block;font-size:.78rem;color:var(--fg-dim);line-height:1.3}
.chip[aria-pressed=true]{border-color:var(--accent);color:var(--accent);background:var(--sel)}
.chip[aria-pressed=true] .ch{color:var(--accent-dim)}
.chip:active{transform:translateY(1px)}
.chips-label{margin:1.4rem 0 .55rem;font-size:.8rem;color:var(--fg-dim)}

/* buttons */
.bar{
  position:fixed;left:0;right:0;bottom:0;z-index:5;
  display:flex;gap:.6rem;align-items:center;
  padding:.75rem 1.15rem calc(.75rem + env(safe-area-inset-bottom));
  background:linear-gradient(to top,var(--bg) 62%,var(--fade));
}
/* an author display: rule outranks the UA's [hidden]{display:none}, so say it here */
.bar[hidden]{display:none}
.bar .inner{display:flex;gap:.6rem;align-items:center;width:100%;max-width:40rem;margin:0 auto}
.btn{
  appearance:none;cursor:pointer;font:inherit;font-size:1rem;font-weight:600;
  border-radius:.6rem;padding:.8rem 1.1rem;min-height:3rem;
  background:var(--accent);color:var(--on-accent);border:1px solid var(--accent);
}
.btn:active{transform:translateY(1px)}
.btn[disabled]{opacity:.45;cursor:default}
.btn.ghost{background:transparent;color:var(--fg);border-color:var(--line);font-weight:500}
.btn.wide{flex:1}
.btn.spark{background:transparent;color:var(--accent);border-color:var(--accent-dim)}
.linkbtn{
  appearance:none;background:none;border:0;padding:.35rem 0;margin:0;font:inherit;font-size:.9rem;
  color:var(--accent);cursor:pointer;text-align:left;text-decoration:underline;text-underline-offset:3px;
}
.escapes{display:flex;flex-direction:column;align-items:flex-start;gap:.15rem;margin:1.5rem 0 0}

/* The rating beside the all-surprise button. Quiet on purpose: it sits under an
   escape hatch, not a question, and the weakest option is already chosen — so it
   should read as a thing you may adjust, never as one more thing to answer. */
.srate{display:flex;flex-wrap:wrap;align-items:center;gap:.1rem .6rem;font-size:.85rem;margin:.1rem 0 .1rem}
.srate-l{color:var(--dim)}
.srate-o{display:inline-flex;align-items:center;gap:.3rem;cursor:pointer;color:var(--fg)}
.srate-o input{accent-color:var(--accent);margin:0;cursor:pointer}
.srate-o.off{opacity:.45;cursor:not-allowed}
.srate-o.off input{cursor:not-allowed}
.srate-n{font-size:.8rem;color:var(--dim);margin:0 0 .2rem}

/* ---------------------------------------------------------------- the ways */
/* THREE BOXES, IN THE SHELF'S LANGUAGE. A channel is a thing you point at and
   pick, and choosing how to build is the same kind of act as choosing what to
   play — so these are the same object: 4:3, hairline, soft shadow, and a lift
   when you reach for it. The lift is the affordance; there is no other one.

   Written out here rather than shared with play-lib.php because this page does
   not load the shelf's stylesheet, and pulling in a whole shelf to borrow one
   rule would drag along tile art, stopped-world greying and the plus. */
.ways{display:grid;gap:clamp(.7rem,2vw,1.1rem);margin:1.6rem 0 2.2rem;
  grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr))}
@media (max-width:30rem){.ways{grid-template-columns:1fr}}

.way{position:relative;display:block;width:100%;aspect-ratio:4/3;padding:0;
  font:inherit;text-align:left;color:var(--fg);cursor:pointer;
  background:var(--bg-2);border:1px solid var(--line);border-radius:.85rem;
  box-shadow:var(--shadow);
  transition:transform .16s ease-out,box-shadow .16s ease-out,border-color .16s ease-out}
/* THE LIFT IS ALSO A STACKING CONTEXT, and that is what put the tooltips behind
   the machines below them. A `transform` makes the element a stacking context
   whatever its z-index says, so the tip's own z-index:5 was being resolved
   INSIDE the card — against nothing — while the card itself sat at auto and lost
   to every positioned thing later in the document: the "what forges it" heading,
   the cards' stretched pickers at z-index 1, their headers at 2.

   Raising the card raises the context it created, and with it the tip. */
.way:hover,.way:focus-visible,.way:focus-within{transform:translateY(-3px);
  box-shadow:var(--shadow-lift);border-color:var(--accent-dim);outline:none;z-index:20}
.way:focus-visible{border-color:var(--accent)}
.way:active{transform:translateY(0);box-shadow:var(--shadow)}
@media (max-width:30rem){.way{aspect-ratio:auto;min-height:5.5rem}}

/* The mark, centred in the space above the name. Grey until you reach for it,
   like the plus on an empty shelf. */
.wayface{position:absolute;left:0;right:0;top:0;bottom:1.9rem;
  display:flex;align-items:center;justify-content:center}
.wg{font-size:1.7rem;line-height:1;color:var(--fg-far);transition:color .16s ease-out}
.wq{font-size:3.2rem;line-height:0;transform:translateY(.35rem)}
/* An empty frame: the room you are about to fill in. Dashed, like the plus on an
   empty shelf, because it is the same idea — a shape with nothing in it yet. */
.wblank{width:1.5rem;height:1.5rem;border:1px dashed var(--fg-far);border-radius:.25rem;
  transition:border-color .16s ease-out}
.way:hover .wblank,.way:focus-visible .wblank{border-color:var(--accent)}
.way:hover .wg,.way:focus-visible .wg{color:var(--accent)}
/* The wizard's mark is the wizard's own progress rail — the thing you actually
   spend the next two minutes looking at. */
.wrail{display:flex;gap:.32rem;align-items:center}
.wrail i{width:.42rem;height:.42rem;border-radius:50%;background:var(--dot);
  transition:background .16s ease-out}
.wrail i.on{background:var(--fg-far)}
.way:hover .wrail i.on,.way:focus-visible .wrail i.on{background:var(--accent)}

.way .tname{position:absolute;left:0;right:0;bottom:0;padding:0 .7rem .7rem;
  font-size:.92rem;font-weight:600;line-height:1.2;text-align:center;color:var(--fg)}

/* The tooltip. On hover AND on focus, because a keyboard reaching this box
   deserves the same sentence a mouse gets — and it is the only place the
   difference between the three is written down.

   Outside the card's own box and above its neighbours: a description that
   pushed the grid around every time somebody moved the mouse would be worse
   than no description. Pointer-events off so it cannot eat the click it is
   explaining. */
.tip{position:absolute;left:50%;top:calc(100% + .5rem);transform:translate(-50%,-.25rem);
  z-index:5;width:min(20rem,80vw);padding:.6rem .75rem;
  font-size:.8rem;font-weight:400;line-height:1.45;text-align:left;
  color:var(--fg);background:var(--bg-2);border:1px solid var(--line);border-radius:.6rem;
  box-shadow:var(--shadow-lift);pointer-events:none;
  opacity:0;visibility:hidden;transition:opacity .16s ease-out,transform .16s ease-out,visibility .16s}
.way:hover .tip,.way:focus-visible .tip{opacity:1;visibility:visible;transform:translate(-50%,0)}
/* The last box's tooltip would hang off the right edge of a narrow window. */
.ways > .way:last-child .tip{left:auto;right:0;transform:translate(0,-.25rem)}
.ways > .way:last-child:hover .tip,
.ways > .way:last-child:focus-visible .tip{transform:translate(0,0)}
@media (prefers-reduced-motion: reduce){
  .way,.tip{transition:none}
  .way:hover,.way:focus-visible{transform:none}
}

/* ------------------------------------------------------ what forges it */
/* The machines, on the forge's own screen. The machines screen styles the same
   markup from play-lib.php's shelf sheet, which this page does not load — and
   pulling in a whole shelf to borrow four rules would bring tile art and the
   plus with it. The classes match so the two screens read as the same object. */
.forgeat{list-style:none;margin:0 0 1.1rem;padding:0;display:flex;flex-direction:column;gap:.45rem}
.forgeat li{display:flex;align-items:stretch;gap:.4rem}
.forgeat .opt{position:relative;flex:1;min-width:0;margin:0;cursor:pointer;
  transition:border-color .15s ease-out,box-shadow .15s ease-out}
.forgeat .opt:has(.mpick:hover),.forgeat .opt:has(.mpick:focus-visible){
  border-color:var(--accent-dim);box-shadow:var(--shadow)}
/* The stretched hit area, under the meter so the number stays selectable. */
.mpick{position:absolute;inset:0;z-index:1;appearance:none;border:0;background:none;
  cursor:pointer;padding:0}
.mpick:focus-visible{outline:2px solid var(--accent);outline-offset:-3px}
.forgeat .thead{display:flex;align-items:baseline;gap:.75rem;position:relative;z-index:2}
.forgeat .thead .t{flex:1;min-width:0;font-weight:600;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.forgeat .thead .meter{flex:0 0 auto;margin:0}
.forgeat .status{display:flex;align-items:center;gap:.35rem;margin:.5rem 0 0;font-size:.78rem;
  color:var(--fg-dim)}
/* Right-hand end: not "can this be reached" but "is this the one building".
   Never grey — grey is this screen's colour for "not asked yet". */
.wired{margin-left:auto;display:inline-flex;align-items:center;gap:.35rem;
  font-size:.78rem;color:var(--fg-dim);white-space:nowrap}
.wired .dot{width:.5rem;height:.5rem;margin:0;background:var(--dot);border:0}
.wired.on{color:var(--good)}
.wired.on .dot{background:var(--good)}
/* Not the one being built with. Red because on this screen it is an answer to a
   question somebody is actively deciding, not a complaint about the machine —
   there is nothing wrong with it, it is simply not the one. */
.wired.no{color:var(--bad)}
.wired.no .dot{background:var(--bad)}
/* Who is answering: two glyphs and no logos — a filled square is a machine you
   can walk over to, a hollow ring is somebody else's. */
/* PURE TEXT, AND IT SITS OVER THE CARD'S STRETCHED PICKER. Without this it
   swallows every click that lands on the badge, which is the widest thing
   on the card. */
.whois{pointer-events:none;display:flex;align-items:center;gap:.4rem;margin:.35rem 0 0;
  font-size:.78rem;color:var(--fg-dim);min-width:0;position:relative;z-index:2}
.wsig{flex:0 0 auto;width:.55rem;height:.55rem;border:1px solid var(--fg-far)}
.wsig.here{background:var(--fg-far);border-radius:.1rem}
.wsig.away{background:transparent;border-radius:50%}
.wname{flex:0 0 auto;font-weight:600;color:var(--fg)}
.wmodel{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.72rem}
.forgeat .corner,main > .screen .corner{text-align:left}

/* ------------------------------------------------- how you describe it */
/* Three doors on one screen. A segmented row rather than three more channels:
   these are not different destinations, they are the same one reached with more
   or less of it already in your head. */
.ways3{display:inline-flex;gap:0;margin:0 0 1rem;border:1px solid var(--line);
  border-radius:.6rem;overflow:hidden;background:var(--bg-2)}
.w3{appearance:none;border:0;border-right:1px solid var(--line);background:transparent;
  font:inherit;font-size:.86rem;color:var(--fg-dim);padding:.45rem .9rem;cursor:pointer;
  transition:background .14s ease-out,color .14s ease-out}
.w3:last-child{border-right:0}
.w3:hover{color:var(--fg);background:var(--bg-3)}
.w3.on{color:var(--on-accent);background:var(--accent)}
.w3:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}

.pdfdrop{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin:.9rem 0 0}
.pdfst{font-size:.82rem;color:var(--fg-dim)}

/* The key field, when a build was asked for without one. */
.byo.want{animation:wantkey .5s ease-out}
.byo.want input{border-color:var(--accent);box-shadow:0 0 0 3px var(--glow)}
@keyframes wantkey{0%,100%{transform:none}25%{transform:translateX(-3px)}75%{transform:translateX(3px)}}

/* welcome / model choice */
.opt{display:block;border:1px solid var(--line);border-radius:.7rem;background:var(--bg-2);
  padding:.9rem 1rem;margin:0 0 .7rem;cursor:pointer}
.opt.on{border-color:var(--accent);background:var(--sel)}
.opt .t{font-weight:600}
.opt .d{font-size:.86rem;color:var(--fg-dim)}
.byo{display:none;margin:.2rem 0 0;padding:.9rem 0 0;border-top:1px solid var(--line)}
.byo.on{display:block}
.byo .row{margin:0 0 .75rem}
.status{font-size:.85rem;color:var(--fg-dim)}
.dot{display:inline-block;width:.55rem;height:.55rem;border-radius:50%;margin-right:.4rem;vertical-align:baseline;background:var(--fg-dim)}
.dot.up{background:var(--good)}
.dot.down{background:var(--bad)}
.note{border-left:2px solid var(--line);padding:.15rem 0 .15rem .8rem;margin:0 0 1rem;color:var(--fg-dim);font-size:.9rem}
.note.warn{border-color:var(--warn);color:var(--fg)}
.note.bad{border-color:var(--bad);color:var(--fg)}

/* build */
.passes{list-style:none;margin:0;padding:0}
.passes li{display:flex;gap:.7rem;align-items:flex-start;padding:.55rem 0;border-bottom:1px solid var(--line-2)}
.passes li:last-child{border-bottom:0}
.passes .pd{flex:0 0 auto;width:.6rem;height:.6rem;margin-top:.55rem;border-radius:50%;background:var(--dot)}
/* THE RUNNING PASS SPINS. It was a dot fading in and out, which is motion but
   not the RIGHT motion: a fade reads as "idle, breathing" and a rotation reads
   as "working", and the difference matters on a screen somebody stares at for
   three minutes wondering whether anything is happening. A ring with one
   quarter missing, turning — the cheapest honest spinner there is. */
.passes li.run .pd{background:transparent;border:2px solid var(--accent-dim);
  border-top-color:var(--accent);width:.85rem;height:.85rem;margin-top:.4rem;
  animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* And one beside the heading, so there is motion from the moment the screen
   opens — before the first pass has reported anything, which is the window this
   screen felt dead in. */
.spinner{display:inline-block;width:.85rem;height:.85rem;vertical-align:-1px;margin-right:.5rem;
  border:2px solid var(--accent-dim);border-top-color:var(--accent);border-radius:50%;
  animation:spin .8s linear infinite}
.screen[data-screen=build] h1{display:flex;align-items:center;gap:.1rem}

/* Somebody who asked not to be moved gets the pulse instead of the rotation —
   still the difference between running and idle, without anything turning. */
@media (prefers-reduced-motion: reduce){
  .passes li.run .pd,.spinner{animation:pulse 1.1s ease-in-out infinite}
}
.passes li.done .pd{background:var(--good)}
.passes li.warn .pd{background:var(--warn)}
@keyframes pulse{0%,100%{opacity:.35}50%{opacity:1}}
.passes .pt{font-weight:600}
.passes li.idle .pt{color:var(--fg-dim);font-weight:500}
.passes .ps{font-size:.85rem;color:var(--fg-dim)}
.log{margin:1.25rem 0 0;padding:.8rem .9rem;background:var(--bg-2);border:1px solid var(--line);
  border-radius:.55rem;max-height:11rem;overflow:auto;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.76rem;line-height:1.6;color:var(--fg-dim)}
/* An empty log is a bordered box with nothing in it. The feed it lives in is
   opened by anything worth showing — a skip, a story closing, a trip across
   town — and only a skip ever writes here, so without this the other two open a
   panel and show an empty rectangle above their own line. */
.log:empty{display:none}
.log b{color:var(--accent);font-weight:400}
.log .w{color:var(--warn)}
.elapsed{font-variant-numeric:tabular-nums}

/* result */
.hero{margin:0 0 .3rem;font-size:clamp(1.8rem,8vw,2.6rem);line-height:1.1;font-weight:700;letter-spacing:-.02em}
.hero-sub{font-size:1.05rem;margin:0 0 .8rem}
.facts{display:flex;flex-wrap:wrap;gap:.3rem .9rem;margin:0 0 .3rem;padding:0;list-style:none;font-size:.82rem;color:var(--fg-dim)}
.card{border:1px solid var(--line);border-radius:.6rem;background:var(--bg-2);padding:.85rem .95rem;margin:0 0 .7rem}
.card .n{font-weight:600}
.card .m{font-size:.8rem;color:var(--fg-dim)}
.card .d{font-size:.9rem;margin:.35rem 0 0}
.card.mine{border-color:var(--accent-dim)}
.tag{display:inline-block;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);
  border:1px solid var(--accent-dim);border-radius:.5rem;padding:.05rem .4rem;margin-left:.4rem;vertical-align:.08em}
/* The one tag on the shelf that is a warning rather than a label. Dimmed rather
   than red: it is a fact about a world, not an error, and a world where death
   sticks is a world somebody chose to build that way. */
.tag.warn{color:var(--fg-dim);border-color:var(--line)}
.sys{list-style:none;margin:0;padding:0}
.sys li{padding:.35rem 0 .35rem .95rem;border-left:2px solid var(--accent-dim);margin:0 0 .35rem}
.sys li.off{border-color:var(--line);color:var(--fg-dim)}
.sys .k{font-weight:600}
.sys .k code{font:inherit;color:var(--accent)}
.sys li.off .k code{color:var(--fg-dim)}
.sys .v{font-size:.88rem;color:var(--fg-dim)}
.evt{padding:.5rem 0;border-bottom:1px solid var(--line-2)}
.evt:last-child{border-bottom:0}
.evt .w{font-size:.78rem;color:var(--fg-dim)}
.quiet{color:var(--fg-dim);font-size:.88rem}
details{margin:.6rem 0 0}
summary{cursor:pointer;color:var(--accent);font-size:.9rem}
details ul{margin:.6rem 0 0;padding-left:1.1rem}
details li{font-size:.85rem;color:var(--fg-dim);margin:0 0 .25rem}
details li.warn{color:var(--fg)}
footer{margin:2.5rem 0 0;padding-top:1rem;border-top:1px solid var(--line);font-size:.8rem;color:var(--fg-dim)}
.noscript{border:1px solid var(--bad);border-radius:.6rem;padding:.9rem;margin:0 0 1rem}
/* the way into a built world — the loudest thing on the result screen, and the
   same button on the shelf at play.php. Lives here, not in play-lib.php, because
   the forge's result screen shows it before the play view's stylesheet exists. */
.enter{display:block;text-align:center;font-weight:600;font-size:1.05rem;text-decoration:none;
  background:var(--accent);color:var(--on-accent);border:1px solid var(--accent);border-radius:.6rem;
  padding:.85rem 1.1rem;margin:0 0 .7rem}
.enter:active{transform:translateY(1px)}
/* THE BACK COVER. Set like a jacket blurb, not like documentation: bigger
   leading, hanging quote bar in the accent, room to breathe. Empty, it takes
   no room at all. */
.cover{margin:.9rem 0 1.1rem;padding:.2rem 0 .2rem 1rem;border-left:3px solid var(--accent);
  font-size:1.02rem;line-height:1.65;color:var(--fg)}
.cover:empty{display:none}
.cover.writing{color:var(--fg-dim);font-style:italic}
/* the repass, on the result shelf: green single pass left, red loop far right */
.fgrepass{margin:.4rem 0 1rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.fgrepass .redbtn{margin-left:auto}
.fgrepass .st{flex:1 1 100%;font-size:.85rem;color:var(--fg-dim)}
.fgrepass .rfind{flex:1 1 100%}
.greenbtn{min-height:2.6rem;padding:.5rem 1.2rem;font:inherit;font-weight:700;cursor:pointer;
  color:#fff;background:var(--good);border:1px solid var(--good);border-radius:.6rem}
.greenbtn:hover{filter:brightness(1.08)}
.greenbtn:disabled{opacity:.55;cursor:default}
:root[data-theme="dark"] .greenbtn{color:#14210d}
:root[data-theme="dark"] .redbtn{color:#2b0d05}
.rfind{list-style:none;margin:.7rem 0 0;padding:0;display:flex;flex-direction:column;gap:.5rem}
.rf{display:flex;gap:.55rem;align-items:baseline;flex-wrap:wrap;padding:.55rem .7rem;
  background:var(--bg-2);border:1px solid var(--line);border-radius:.6rem;font-size:.88rem}
.rfk{flex:0 0 auto;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;
  padding:.1rem .5rem;border-radius:.6rem;border:1px solid var(--line);color:var(--fg-dim)}
.rf.consistency .rfk{border-color:var(--bad);color:var(--bad)}
.rf.plot .rfk{border-color:var(--accent-dim);color:var(--accent)}
.rfs{flex:1 1 24rem;min-width:0}
.rfok{flex:0 0 auto;font-size:.72rem;font-weight:600;color:var(--accent)}
.rf.done{opacity:.85}
.redbtn{min-height:2.6rem;padding:.5rem 1.2rem;font:inherit;font-weight:700;cursor:pointer;
  color:#fff;background:var(--bad);border:1px solid var(--bad);border-radius:.6rem}
.redbtn:hover{filter:brightness(1.08)}
.redbtn:disabled{opacity:.55;cursor:default}
.dicebig{min-height:2.6rem;padding:.5rem 1rem;font:inherit;font-weight:600;cursor:pointer;
  color:var(--fg);background:var(--bg-2);border:1px solid var(--line);border-radius:.6rem}
.dicebig:hover{border-color:var(--accent-dim)}
.dicebig:disabled{opacity:.55;cursor:default}
CSS;
}

/**
 * Days, short enough for a phone.
 *
 * walls.php's xeric_days_phrase() writes for prose ("Mondays, Tuesdays,
 * Wednesdays, Thursdays, Fridays, and Saturdays") because it is feeding a
 * character bible. On a card that is a wall of text, so this says "Mon–Sat".
 */
function xeric_web_days_short(array $days): string
{
    $d = array_values(array_unique(array_filter(array_map('intval', $days), fn($n) => $n >= 0 && $n <= 6)));
    sort($d);
    if ($d === []) return '';
    if ($d === [0, 1, 2, 3, 4, 5, 6]) return 'every day';
    if ($d === [1, 2, 3, 4, 5]) return 'weekdays';
    if ($d === [0, 6]) return 'weekends';
    $n = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $run = count($d) >= 3 && ($d[count($d) - 1] - $d[0]) === count($d) - 1;
    return $run ? $n[$d[0]] . '–' . $n[$d[count($d) - 1]]
                : implode(' ', array_map(fn($i) => $n[$i], $d));
}

/**
 * The result screen, from a validated template + its seed.
 *
 * $meta: seconds, notes[], slug, endpoint, json_url, mine, left.
 *
 * `mine` and `left` are the demo layer showing through, and they are deliberately
 * QUIET: one line under the enter button. A visitor should learn that the world
 * is theirs and that the demo has a budget without either fact competing with
 * the name of the place they just made.
 */
function xeric_web_result_html(array $t, array $seed, array $meta = []): string
{
    $notes  = (array)($meta['notes'] ?? []);
    $slug   = (string)($meta['slug'] ?? '');
    $secs   = (float)($meta['seconds'] ?? 0);
    $jsonUrl = (string)($meta['json_url'] ?? ('world.php?w=' . rawurlencode($slug)));

    $forge = (array)($t['forge'] ?? []);
    $armed = (array)($forge['armed'] ?? []);
    $disarmed = (array)($forge['disarmed'] ?? []);
    $why = trim((string)($forge['systems_why'] ?? ''));
    $source = (string)($forge['systems_source'] ?? '');

    // A fallback is any note where a pass gave up and the built-in stood in.
    $fallbacks = array_values(array_filter($notes, fn($n) =>
        str_contains((string)$n, 'built-in default') || str_starts_with((string)$n, 'repair:')
        || str_contains((string)$n, 'validation failed') || str_contains((string)$n, 'falling back')));

    ob_start();
    ?>
<h2>the xeric is drafted</h2>
<p class="hero"><?= h($t['meta']['name']) ?></p>
<p class="hero-sub"><?= h($t['meta']['description']) ?></p>
<?php
    // THE BACK COVER, NOT THE MANUAL. This screen used to open with paragraphs
    // about proposals, browsers and storage — the reader had just made a book
    // and was handed a shipping label. The blurb is written by the forge's last
    // pass (spoiler-safe: it is built from the commons and never shown a wall);
    // a world from before back covers gets one written on the owner's first
    // visit, painted in by the fragment's own script below.
    $teaser = trim((string)($t['meta']['teaser'] ?? ''));
?>
<blockquote class="cover" id="cover"<?= $slug !== '' && !empty($meta['mine'])
    ? ' data-w="' . h($slug) . '"' . ($teaser === '' ? ' data-teaser="1"' : '') : '' ?>>
  <?= $teaser !== '' ? h($teaser) : '' ?>
</blockquote>
<?php if ($slug !== ''): ?>
<?php if (!empty($meta['review'])): ?>
<a class="enter" href="review.php?w=<?= h(rawurlencode($slug)) ?>"
   title="Nothing is final: reroll one person without rebuilding, retype anything, then launch">Look it over before it starts →</a>
<p class="quiet"><a href="play.php?w=<?= h(rawurlencode($slug)) ?>"
   title="You can come back and change anything at any point">skip the review and go straight in</a></p>
<?php else: ?>
<a class="enter" href="play.php?w=<?= h(rawurlencode($slug)) ?>"
   title="It is already the middle of a week in there">▶ enter this xeric</a>
<?php endif; ?>
<?php if (!empty($meta['mine'])): ?>
<!-- THE LITERARY REPASS, ON THE SHELF WHERE THE BOOK IS. Same endpoint the
     review page uses; findings land here, and a carried rewrite applies
     through the same edit door, undo and all. -->
<div class="fgrepass">
  <button type="button" class="greenbtn" id="fgrepass" data-w="<?= h($slug) ?>"
    title="An editor reads the whole xeric once: contradictions, the plot's through-line, and the story snake's pacing. Rewrites it can carry go straight in.">📖 1 literary repass</button>
  <?php if (!empty($meta['review'])): ?>
  <button type="button" class="linkbtn dicebig" id="fgdraftagain" data-w="<?= h($slug) ?>"
    title="The whole book again: same interview answers, same address, every sentence up for replacement. One ↺ step on the review page brings this draft back.">🎲 Draft again</button>
  <?php endif; ?>
  <button type="button" class="redbtn" id="fgrepassall" data-w="<?= h($slug) ?>"
    title="Warning: this uses a lot of tokens, and it makes several passes. It reads, rewrites, and reads again until no contradictions remain.">Clear All Contradictions</button>
  <span class="st" id="fgrepassst"></span>
  <ul class="rfind" id="fgrfind" hidden></ul>
</div>
<?php endif; ?>
<?php
    $left = (array)($meta['left'] ?? []);
    if ($left !== [] && xeric_limit_on()):
?>
<p class="quiet">You can forge <?= (int)($left['forges'] ?? 0) ?> more
  <?= ((int)($left['forges'] ?? 0) === 1 ? 'xeric' : 'xerics') ?> today, and say
  <?= (int)($left['messages'] ?? 0) ?> more things this hour. The demo runs on one GPU that somebody
  else also works on.</p>
<?php endif; ?>
<?php endif; ?>
<ul class="facts">
  <li><?= h($t['setting']['locale'] ?? '') ?></li>
  <li><?= h($t['setting']['era'] ?? '') ?></li>
  <li>rating: <?= h($t['meta']['rating']) ?></li>
  <?php if (!empty($t['meta']['themes'])): ?><li><?= h(implode(' · ', (array)$t['meta']['themes'])) ?></li><?php endif; ?>
</ul>

<hr class="horizon">

<h2>you, in it</h2>
<p><strong><?= h($t['user']['name']) ?></strong>, <?= h($t['user']['occupation']['title'] ?? '') ?>
  at <?= h(xeric_world_place_name($t, (string)($t['user']['occupation']['workplace_key'] ?? ''))) ?>,
  <?= h($t['user']['occupation']['hours'] ?? '') ?>.</p>
<p class="quiet">Here for: <?= h($t['user']['motivation'] ?? '') ?></p>

<h2>what that armed</h2>
<?php if ($why !== ''): ?>
  <p class="note">“<?= h($why) ?>” <span class="quiet">, the model, reading your answer</span></p>
<?php elseif ($source === 'preset'): ?>
  <p class="quiet">A known motivation, so these are the tested defaults.</p>
<?php elseif ($source === 'model'): ?>
  <p class="quiet">You said it in your own words, so the model chose what this xeric runs on.</p>
<?php elseif ($source === 'keywords'): ?>
  <p class="note warn">The model could not be reached to read your motivation, so these were matched on keywords.</p>
<?php endif; ?>
<ul class="sys">
  <?php foreach ($armed as $k): $k = (string)$k; ?>
  <li><span class="k">on, <code><?= h(str_replace('_', ' ', $k)) ?></code></span>
    <?php if (isset(XERIC_SYSTEMS[$k])): ?><span class="v"><?= h(XERIC_SYSTEMS[$k]) ?></span><?php endif; ?></li>
  <?php endforeach; ?>
  <?php foreach ($disarmed as $k): $k = (string)$k; ?>
  <li class="off"><span class="k">off, <code><?= h(str_replace('_', ' ', $k)) ?></code></span>
    <?php if (isset(XERIC_SYSTEMS[$k])): ?><span class="v"><?= h(XERIC_SYSTEMS[$k]) ?></span><?php endif; ?></li>
  <?php endforeach; ?>
</ul>

<h2>the places (<?= count((array)$t['places']) ?>)</h2>
<?php foreach ((array)$t['places'] as $p): ?>
<div class="card<?= !empty($p['user_workplace']) ? ' mine' : '' ?>">
  <div class="n"><?= h($p['name']) ?><?= !empty($p['user_workplace']) ? '<span class="tag">yours</span>' : '' ?></div>
  <div class="m"><?= h($p['kind']) ?> · <?= h(($p['hours']['open'] ?? '') . '–' . ($p['hours']['close'] ?? '')) ?><?php
    if (!empty($p['hours']['open_late_weekend'])) echo ' · open late'; ?></div>
  <?php if (!empty($p['description'])): ?><div class="d"><?= h($p['description']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>

<h2>the cast (<?= count((array)$t['cast']['characters']) ?>)</h2>
<?php foreach ((array)$t['cast']['characters'] as $c): ?>
<div class="card">
  <div class="n"><?= h($c['display_name']) ?> <span class="m">· <?= h((string)($c['age'] ?? '')) ?></span></div>
  <div class="d"><?= h($c['one_line'] ?? '') ?></div>
  <div class="m"><?php
    $bits = [];
    foreach ((array)($c['week'] ?? []) as $w) {
        $bits[] = xeric_web_days_short((array)($w['days'] ?? [])) . ' ' . ($w['from'] ?? '') . '–' . ($w['to'] ?? '')
            . ' at ' . xeric_world_place_name($t, (string)($w['where'] ?? ''));
    }
    echo h(implode(' · ', $bits));
  ?></div>
</div>
<?php endforeach; ?>

<h2>what already happened</h2>
<p class="quiet"><?= count((array)$seed['events']) ?> events and
  <?= count((array)$seed['memories']) ?> memories are already in the past of this xeric, day one is not day one.</p>
<?php foreach ((array)$seed['events'] as $e): ?>
<div class="evt">
  <div><?= h($e['title'] ?? '') ?></div>
  <div class="w"><?= (int)($e['days_ago'] ?? 0) ?> days ago<?php
    $pn = xeric_world_place_name($t, (string)($e['place'] ?? ''));
    if ($pn !== '') echo ' · ' . h($pn);
  ?></div>
</div>
<?php endforeach; ?>

<h2>how it went</h2>
<?php if ($fallbacks === []): ?>
  <p class="quiet">Every pass came back usable, nothing fell back to a built-in default.</p>
<?php else: ?>
  <p class="note warn">Some of this xeric is the built-in default, not the model's work:</p>
  <ul class="sys">
    <?php foreach ($fallbacks as $f): ?><li><span class="v"><?= h($f) ?></span></li><?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($notes !== []): ?>
<details>
  <summary><?= count($notes) ?> notes from the build</summary>
  <ul>
    <?php foreach ($notes as $n): $w = str_contains((string)$n, 'failed') || str_contains((string)$n, 'default') || str_starts_with((string)$n, 'repair:'); ?>
    <li<?= $w ? ' class="warn"' : '' ?>><?= h($n) ?></li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>

<?php if ($secs > 0): ?>
<p class="quiet">forged in <?= h(number_format($secs, 0)) ?>s<?= isset($meta['endpoint']) ? ' by ' . h((string)$meta['endpoint']) : '' ?>.</p>
<?php endif; ?>

<hr class="horizon">
<p><a href="<?= h($jsonUrl) ?>">Read the raw world-template.json →</a></p>
<p class="quiet">Written to <code>worlds/<?= h($slug) ?>/</code>, it is a file, and it is yours.</p>
<p><a href="forge.php?fresh=1">Forge another xeric</a></p>
<?php if ($slug !== '' && !empty($meta['mine'])): ?>
<script>
// The fragment wires itself: this runs as-is when the page is served whole
// (forge.php?w=), and the wizard executes it by hand after injecting the
// result — innerHTML does not run scripts, so the injection path calls each
// one through Function() (see forge.php). Idempotent via data-bound.
(function () {
  var q = function (s) { return document.querySelector(s); };

  // The back cover: written on first sight when a world has none, and
  // REWRITTEN whenever the book underneath it changes (a repass that
  // corrected something, a reroll — both take the cover with them).
  var cov = q('#cover');
  var COVW = (cov && cov.dataset.w) || '';
  function writeCover(force) {
    if (!cov || !COVW) return;
    if (cov.dataset.writing === '1') return;
    cov.dataset.writing = '1';
    cov.textContent = force ? 'the back cover is being rewritten…' : 'the back cover is being written…';
    cov.classList.add('writing');
    fetch('forge.php?a=teaser', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ xeric: COVW, fresh: force ? 1 : 0 }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        cov.dataset.writing = '';
        cov.classList.remove('writing');
        if (d && d.tokens && window.xericMeterFeed) window.xericMeterFeed(d.tokens);
        if (d && d.ok && d.teaser) { cov.textContent = d.teaser; return; }
        cov.textContent = '';                       // the description above stands
      })
      .catch(function () { cov.dataset.writing = ''; cov.classList.remove('writing'); cov.textContent = ''; });
  }
  if (cov && cov.dataset.teaser && !cov.dataset.bound) {
    cov.dataset.bound = '1';
    writeCover(false);
  }

  // the literary repass, same endpoint the review page uses — one read, or
  // the big red button's read-rewrite-read loop until the contradictions are gone
  var b = q('#fgrepass'), b2 = q('#fgrepassall');
  var st = q('#fgrepassst'), box = q('#fgrfind');

  function lockFg(on) { if (b) b.disabled = on; if (b2) b2.disabled = on; }

  function paintFg(d) {
    box.innerHTML = '';
    (d.findings || []).forEach(function (f) {
      var li = document.createElement('li');
      li.className = 'rf ' + f.kind + (f.fixed ? ' done' : '');
      var k = document.createElement('span');
      k.className = 'rfk';
      k.textContent = { consistency: 'contradiction', plot: 'plot', snake: 'the snake' }[f.kind] || f.kind;
      var s = document.createElement('span');
      s.className = 'rfs';
      s.textContent = f.about + ': ' + f.say;
      li.appendChild(k);
      li.appendChild(s);
      if (f.fixed) {
        var ok = document.createElement('span');
        ok.className = 'rfok';
        ok.textContent = 'corrected ✓';
        ok.title = f.fix;
        li.appendChild(ok);
      }
      box.appendChild(li);
    });
    box.hidden = (d.findings || []).length === 0;
  }

  function fgOnce(w, cb, fail, extra) {
    var body = { world: w };
    if (extra) for (var k in extra) body[k] = extra[k];
    fetch('review.php?a=repass', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (res.d && res.d.tokens && window.xericMeterFeed) window.xericMeterFeed(res.d.tokens);
        if (!res.ok) { fail((res.d && res.d.error) || 'the repass fell over'); return; }
        cb(res.d);
      }, function (e) { fail('the repass could not be reached, ' + e.message); });
  }

  if (b && !b.dataset.bound) {
    b.dataset.bound = '1';
    b.addEventListener('click', function () {
      lockFg(true);
      st.textContent = 'reading the whole xeric, this is a couple of model passes…';
      box.hidden = true;
      box.innerHTML = '';
      fgOnce(b.dataset.w, function (d) {
        lockFg(false);
        var fs = d.findings || [];
        if (!fs.length) {
          st.textContent = 'Nothing to report: no contradictions the editor could find, and the '
            + 'snake holds its shape.';
          return;
        }
        var n = d.fixed || 0;
        st.textContent = fs.length + ' finding' + (fs.length === 1 ? '' : 's') + ', '
          + n + ' correction' + (n === 1 ? '' : 's') + '.'
          + (n ? ' Already in, ↺ on the review page takes the whole pass back.' : '');
        paintFg(d);
        if (d.teaser_stale) writeCover(true);
      }, function (msg) { lockFg(false); st.textContent = msg; });
    });
  }

  // 🎲 Draft again: one reroll job re-running every pass with the same
  // answers; the stream narrates into the status line and the page reloads
  // whole when it lands, because every section just changed.
  var fgd = q('#fgdraftagain');
  if (fgd && !fgd.dataset.bound) {
    fgd.dataset.bound = '1';
    fgd.addEventListener('click', function () {
      if (!confirm('Draft the whole xeric again? Same answers, every sentence up for replacement. '
        + 'One ↺ step on the review page brings this draft back.')) return;
      lockFg(true);
      fgd.disabled = true;
      st.textContent = 'drafting again…';
      fetch('review.php?a=reroll', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ world: fgd.dataset.w, what: 'draft', index: -1 }) })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (!res.ok || !res.d.job) {
            lockFg(false); fgd.disabled = false;
            st.textContent = (res.d && res.d.error) || 'the forge would not start';
            return;
          }
          var es = new EventSource('progress.php?job=' + encodeURIComponent(res.d.job));
          es.addEventListener('meter', function (m) {
            try { var d = JSON.parse(m.data); if (window.xericMeterFeed) window.xericMeterFeed(d.by || {}); } catch (e) {}
          });
          es.addEventListener('note', function (m) {
            try { st.textContent = 'drafting again… ' + (JSON.parse(m.data).text || ''); } catch (e) {}
          });
          es.addEventListener('queue', function (m) {
            try { st.textContent = 'drafting again… ' + (JSON.parse(m.data).text || ''); } catch (e) {}
          });
          es.addEventListener('done', function () { es.close(); location.reload(); });
          es.addEventListener('error', function (m) {
            if (m && m.data) {
              es.close(); lockFg(false); fgd.disabled = false;
              try { st.textContent = JSON.parse(m.data).message || 'the draft fell over'; }
              catch (e) { st.textContent = 'the draft fell over'; }
            }
          });
        }, function (e) { lockFg(false); fgd.disabled = false; st.textContent = 'the forge could not be reached, ' + e.message; });
    });
  }

  // The full ledger-and-judge run lives on the review page, where the fields
  // it corrects are on screen and ↺ is within reach. This button carries the
  // confirm, then hands over; the flag it passes is stripped on arrival so a
  // refresh over there can never restart the run by itself.
  if (b2 && !b2.dataset.bound) {
    b2.dataset.bound = '1';
    b2.addEventListener('click', function () {
      if (!confirm('Are you sure? This reads the whole xeric, rewrites what it can, and puts every '
        + 'contradiction in front of a judge until each one is verified closed, dismissed as noise, '
        + 'or flagged for you. It can take several minutes and uses a lot of tokens.')) return;
      window.location = 'review.php?w=' + encodeURIComponent(b2.dataset.w) + '&clear=1';
    });
  }
})();
</script>
<?php endif; ?>
<?php
    return (string)ob_get_clean();
}
