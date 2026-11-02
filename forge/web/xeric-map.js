/*
 * xeric-map.js — the drawn map's behaviour (the markup is play-map.php's).
 *
 * Three things, none of which travels: travelling is play.php's go(), which
 * the marks reach by being `.goto[data-to]` like the list's buttons.
 *
 *   1. A TOOLTIP on hover and on focus: the place, its state and price, and
 *      who is there doing what. Read off data attributes the server wrote —
 *      observables only, the same ones the list prints — and set as text,
 *      never as markup.
 *   2. KEYS: Enter or Space presses a mark (an SVG <g> has no click of its own
 *      for the keyboard); the arrows move focus to the nearest mark that way.
 *   3. FOLLOW: when the where panel is repainted and the player's mark is
 *      somewhere new, it is walked there from where it was (a CSS transition
 *      on transform), so a trip reads as a walk rather than a jump.
 *
 * Everything is delegated from the document, so a repaint that replaces the
 * whole panel needs no rebinding. No dependencies.
 */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) { module.exports = api; return; }
  root.XericMap = api;
  if (root.document) api.install(root.document, root);
})(this, function () {
  'use strict';

  function attr(el, k) { return (el && el.getAttribute(k)) || ''; }
  function num(el, k) { var v = parseFloat(attr(el, k)); return isFinite(v) ? v : 0; }

  /** What the tooltip says about one mark: [title, state line, who lines...]. */
  function lines(mark) {
    var min = attr(mark, 'data-min');
    var out = [attr(mark, 'data-name'), attr(mark, 'data-state') + (min === 'here' ? ', and you are here' : ', ' + min)];
    var who = mark.querySelectorAll('.wch');
    if (!who.length) out.push('nobody there');
    for (var i = 0; i < who.length; i++) {
      var d = attr(who[i], 'data-doing');
      out.push(attr(who[i], 'data-name') + (d ? ' — ' + d : ''));
    }
    return out;
  }

  function tip(doc) {
    var t = doc.getElementById('wtip');
    if (!t) {
      t = doc.createElement('div');
      t.id = 'wtip';
      t.className = 'wtip';
      t.setAttribute('role', 'tooltip');
      t.hidden = true;
      doc.body.appendChild(t);
    }
    return t;
  }

  function show(doc, win, mark) {
    var t = tip(doc), l = lines(mark);
    while (t.firstChild) t.removeChild(t.firstChild);
    l.forEach(function (s, i) {
      var e = doc.createElement(i === 0 ? 'b' : 'span');
      e.textContent = s;
      t.appendChild(e);
    });
    var r = mark.getBoundingClientRect();
    t.style.left = Math.round(r.left + (win.scrollX || 0)) + 'px';
    t.style.top = Math.round(r.bottom + (win.scrollY || 0) + 6) + 'px';
    t.hidden = false;
    return t;
  }

  function hide(doc) { var t = doc.getElementById('wtip'); if (t) t.hidden = true; }

  /** The nearest mark from `from` in a direction, or null. Off-axis distance
   *  counts double, so "right" means right, not the nearest thing at all. */
  function nearest(marks, from, dir) {
    var fx = num(from, 'data-x'), fy = num(from, 'data-y'), best = null, score = Infinity;
    for (var i = 0; i < marks.length; i++) {
      var m = marks[i];
      if (m === from) continue;
      var dx = num(m, 'data-x') - fx, dy = num(m, 'data-y') - fy;
      var main = dir === 'right' ? dx : dir === 'left' ? -dx : dir === 'down' ? dy : -dy;
      var off = (dir === 'right' || dir === 'left') ? Math.abs(dy) : Math.abs(dx);
      if (main <= 0) continue;
      var s = main + 2 * off;
      if (s < score) { score = s; best = m; }
    }
    return best;
  }

  var KEYS = { ArrowRight: 'right', ArrowLeft: 'left', ArrowDown: 'down', ArrowUp: 'up' };

  function markOf(e) {
    var t = e.target;
    return t && t.closest ? t.closest('.wmap .wpl') : null;
  }

  function press(doc, mark) {
    if (!/\bgoto\b/.test(attr(mark, 'class'))) return false;
    var ev;
    try { ev = new MouseEvent('click', { bubbles: true, cancelable: true }); }
    catch (x) { ev = doc.createEvent('MouseEvents'); ev.initEvent('click', true, true); }
    mark.dispatchEvent(ev);
    return true;
  }

  function key(doc, e) {
    var mark = markOf(e);
    if (!mark) return;
    if (e.key === 'Enter' || e.key === ' ') {
      if (press(doc, mark)) e.preventDefault();
      return;
    }
    if (e.key === 'Escape') { hide(doc); return; }
    var dir = KEYS[e.key];
    if (!dir) return;
    var svg = mark.closest('.wmap');
    var next = svg && nearest(svg.querySelectorAll('.wpl'), mark, dir);
    if (next) { e.preventDefault(); next.focus(); }
  }

  /** Walk the player's mark from its last position to this one. */
  function follow(doc, state, win) {
    var you = doc.querySelector('.wmap .wyou');
    if (!you || you === state.el) return false;
    var x = num(you, 'data-x'), y = num(you, 'data-y'), last = state.last;
    state.el = you;
    state.last = { x: x, y: y };
    hide(doc);
    if (!last || (last.x === x && last.y === y)) return false;
    you.style.transition = 'none';
    you.style.transform = 'translate(' + last.x + 'px,' + last.y + 'px)';
    you.getBoundingClientRect();                               // commit the start
    var go = function () {
      you.style.transition = '';
      you.style.transform = 'translate(' + x + 'px,' + y + 'px)';
    };
    if (win && win.requestAnimationFrame) win.requestAnimationFrame(go); else go();
    return true;
  }

  function install(doc, win) {
    var state = { el: null, last: null };
    doc.addEventListener('mouseover', function (e) { var m = markOf(e); if (m) show(doc, win, m); });
    doc.addEventListener('focusin', function (e) { var m = markOf(e); if (m) show(doc, win, m); });
    doc.addEventListener('mouseout', function (e) { if (markOf(e)) hide(doc); });
    doc.addEventListener('focusout', function (e) { if (markOf(e)) hide(doc); });
    doc.addEventListener('keydown', function (e) { key(doc, e); });
    follow(doc, state, win);
    if (win && win.MutationObserver && doc.body) {
      new win.MutationObserver(function () { follow(doc, state, win); })
        .observe(doc.body, { childList: true, subtree: true });
    }
    return state;
  }

  return { lines: lines, show: show, hide: hide, nearest: nearest, key: key, follow: follow, install: install };
});
