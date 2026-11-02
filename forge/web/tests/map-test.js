// Xeric — the drawn map's harness. NOT RUN ON ITS OWN: map-test.php renders two
// where panels from the fixture world (the player at the Bluebird, then at the
// mill) and hands this xeric-map.js and both files (argv[2..4]), and play.php
// (argv[5]), whose trip lock and click handler are lifted out and run here. The markup is
// parsed into the smallest DOM the map touches — the real server output, not a
// hand-written stand-in — and every result is one line, "R\t<0|1>\t<name>\t<detail>".

'use strict';
const fs = require('fs');

const results = [];
function ok(name, cond, detail) { results.push([name, !!cond, detail === undefined ? '' : String(detail)]); }

// -- a DOM, as small as the map needs ------------------------------------------
const ENT = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", '#039': "'" };
const unent = (s) => s.replace(/&(#\d+|#x[0-9a-f]+|\w+);/gi, (m, e) => ENT[e]
  ?? (e[0] === '#' ? String.fromCodePoint(e[1] === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10)) : m));

function matches(el, simple) {             // ".a.b", "tag", "#id"
  if (!el || !el.attrs) return false;
  const parts = simple.match(/[#.]?[\w-]+/g) || [];
  const cls = String(el.attrs.class || '').split(/\s+/);
  return parts.every((p) => p[0] === '.' ? cls.includes(p.slice(1))
    : p[0] === '#' ? el.attrs.id === p.slice(1) : el.tagName === p.toLowerCase());
}
function matchSel(el, sel) {                // descendant combinator only
  const chain = sel.trim().split(/\s+/);
  if (!matches(el, chain[chain.length - 1])) return false;
  let i = chain.length - 2, p = el.parentNode;
  while (i >= 0 && p) { if (matches(p, chain[i])) i--; p = p.parentNode; }
  return i < 0;
}

class El {
  constructor(tag, doc) {
    this.tagName = tag; this.attrs = {}; this.childNodes = []; this.parentNode = null;
    this.ownerDocument = doc; this.style = {}; this.hidden = false; this.listeners = {}; this._text = '';
  }
  get id() { return this.attrs.id || ''; } set id(v) { this.attrs.id = v; }
  get className() { return this.attrs.class || ''; } set className(v) { this.attrs.class = v; }
  get firstChild() { return this.childNodes[0] || null; }
  get dataset() {
    const o = {};
    for (const k of Object.keys(this.attrs || {})) if (k.startsWith('data-')) o[k.slice(5).replace(/-([a-z])/g, (m, c) => c.toUpperCase())] = this.attrs[k];
    return o;
  }
  get classList() {
    const el = this, get = () => String(el.attrs.class || '').split(/\s+/).filter(Boolean);
    return { contains: (c) => get().includes(c),
             toggle: (c, on) => { const l = get().filter((x) => x !== c); if (on) l.push(c); el.attrs.class = l.join(' '); return !!on; } };
  }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  appendChild(c) { c.parentNode = this; this.childNodes.push(c); return c; }
  removeChild(c) { this.childNodes = this.childNodes.filter((x) => x !== c); c.parentNode = null; return c; }
  get textContent() { return this._text + this.childNodes.map((c) => c.textContent).join(''); }
  set textContent(v) { this.childNodes = []; this._text = String(v); }
  all() { const out = []; const walk = (e) => e.childNodes.forEach((c) => { out.push(c); walk(c); }); walk(this); return out; }
  querySelectorAll(sel) { return this.all().filter((e) => matchSel(e, sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  closest(sel) { for (let e = this; e && e.attrs; e = e.parentNode) if (matchSel(e, sel)) return e; return null; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  dispatchEvent(ev) {
    ev.target = ev.target || this;
    for (let e = this; e; e = e.parentNode) (e.listeners[ev.type] || []).forEach((f) => f(ev));
    return true;
  }
  focus() { this.ownerDocument.activeElement = this; }
  getBoundingClientRect() {
    const x = parseFloat(this.attrs['data-x'] || 0), y = parseFloat(this.attrs['data-y'] || 0);
    return { left: x - 8, top: y - 8, right: x + 8, bottom: y + 8, width: 16, height: 16 };
  }
}

function parseInto(parent, html, doc) {
  const voids = new Set(['br', 'img', 'input', 'meta', 'link', 'hr']);
  let cur = parent;
  const re = /<\/([\w-]+)\s*>|<([\w-]+)((?:\s+[\w:-]+="[^"]*")*)\s*(\/?)>|([^<]+)/g;
  let m;
  while ((m = re.exec(html))) {
    if (m[1]) { if (cur !== parent) cur = cur.parentNode; continue; }
    if (m[5] !== undefined) { const t = new El('#text', doc); t._text = unent(m[5]); t.attrs = null; cur.appendChild(t); continue; }
    const el = new El(m[2].toLowerCase(), doc);
    m[3].replace(/([\w:-]+)="([^"]*)"/g, (_, k, v) => { el.attrs[k] = unent(v); });
    cur.appendChild(el);
    if (!m[4] && !voids.has(el.tagName)) cur = el;
  }
}

class Doc extends El {
  constructor() {
    super('#document', null); this.ownerDocument = this; this.attrs = {};
    this.body = this.appendChild(new El('body', this)); this.activeElement = null;
  }
  createElement(t) { return new El(t, this); }
  getElementById(id) { return this.all().find((e) => e.attrs && e.attrs.id === id) || null; }
  createEvent() { return { initEvent(type) { this.type = type; } }; }
}

global.MouseEvent = class { constructor(type) { this.type = type; } };
function key(k, target) {
  return { type: 'keydown', key: k, target, prevented: false, preventDefault() { this.prevented = true; } };
}

// -- load --------------------------------------------------------------------
const XM = require(process.argv[2]);
const A = fs.readFileSync(process.argv[3], 'utf8');
const B = fs.readFileSync(process.argv[4], 'utf8');

const doc = new Doc();
const where = doc.body.appendChild(new El('div', doc)); where.id = 'where';
parseInto(where, A, doc);
const frames = [];
const win = { scrollX: 0, scrollY: 100, requestAnimationFrame: (f) => frames.push(f) };

// What play.php's bindGo() does: a click on any .goto travels to its data-to.
let went = [];
function bindGo() { doc.querySelectorAll('.goto').forEach((b) => b.addEventListener('click', () => went.push(b.getAttribute('data-to')))); }
bindGo();

const state = XM.install(doc, win);
const mark = (k) => doc.querySelectorAll('.wmap .wpl').find((e) => e.getAttribute('data-key') === k);
const tip = () => doc.getElementById('wtip');
ok('the harness parsed the server\'s map: four marks and the player', doc.querySelectorAll('.wmap .wpl').length === 4
  && doc.querySelector('.wmap .wyou') !== null, doc.querySelectorAll('.wpl').length);
ok('installing remembers where the player was drawn, and animates nothing on first paint',
  state.last && state.last.x === parseFloat(mark('bluebird').getAttribute('data-x')) && !doc.querySelector('.wyou').style.transform);

// -- the tooltip --------------------------------------------------------------
mark('beck_hardware').dispatchEvent({ type: 'focusin', target: mark('beck_hardware') });
let t = tip();
const lines = t ? t.childNodes.map((c) => c.textContent) : [];
ok('focusing a mark shows who is there and what they are doing',
  t && !t.hidden && lines[0] === 'Beck Hardware' && lines[1] === 'open, 2 min'
  && /^Harlan Beck — .+/.test(lines[2] || ''), JSON.stringify(lines));
ok('as text in a <b> and spans, with the tooltip role, under the mark',
  t.childNodes[0].tagName === 'b' && t.childNodes.slice(1).every((c) => c.tagName === 'span')
  && t.getAttribute('role') === 'tooltip' && t.style.top === Math.round(mark('beck_hardware').getBoundingClientRect().bottom + 106) + 'px',
  t.style.top);
mark('beck_hardware').dispatchEvent({ type: 'focusout', target: mark('beck_hardware') });
ok('and focus leaving hides it', tip().hidden === true);

const disc = mark('bluebird').querySelector('.wch circle');
disc.dispatchEvent({ type: 'mouseover', target: disc });
const l2 = tip().childNodes.map((c) => c.textContent);
ok('hovering a person\'s disc shows their place, said as the place you are in',
  !tip().hidden && l2[0] === 'the Bluebird Diner' && l2[1] === 'open, and you are here' && /^Dot Vance/.test(l2[2]), JSON.stringify(l2));
const empty = mark('first_lutheran');
empty.dispatchEvent({ type: 'mouseover', target: empty });
ok('an empty place says nobody is there', tip().childNodes.map((c) => c.textContent)[2] === 'nobody there');
ok('one tooltip, reused', doc.querySelectorAll('.wtip').length === 1);
empty.dispatchEvent(key('Escape', empty));
ok('Escape hides it', tip().hidden === true);

// -- the keys -----------------------------------------------------------------
let ev = key('Enter', mark('the_mill'));
mark('the_mill').dispatchEvent(ev);
ok('Enter on a mark presses it, through the same listener bindGo() hung on the list',
  went.length === 1 && went[0] === 'the_mill' && ev.prevented, JSON.stringify(went));
ev = key(' ', mark('first_lutheran'));
mark('first_lutheran').dispatchEvent(ev);
ok('and so does Space', went[1] === 'first_lutheran' && ev.prevented);
went = [];
ev = key('Enter', mark('bluebird'));
mark('bluebird').dispatchEvent(ev);
ok('the place you stand in is not a trip: Enter there goes nowhere', went.length === 0 && !ev.prevented);

const move = (from, k) => { doc.activeElement = null; const e = key(k, mark(from)); mark(from).dispatchEvent(e);
  return doc.activeElement ? doc.activeElement.getAttribute('data-key') : null; };
ok('ArrowRight from the diner is the hardware store next door', move('bluebird', 'ArrowRight') === 'beck_hardware');
ok('ArrowLeft from the diner is the church', move('bluebird', 'ArrowLeft') === 'first_lutheran');
ok('ArrowUp from the hardware store is the mill', move('beck_hardware', 'ArrowUp') === 'the_mill');
ok('ArrowDown from the mill comes back to town', move('the_mill', 'ArrowDown') === 'beck_hardware');
ok('and nothing lies right of the mill, so focus stays put', move('the_mill', 'ArrowRight') === null);
ok('the nearest-mark rule ignores the mark it starts from',
  XM.nearest([mark('the_mill')], mark('the_mill'), 'down') === null);

// -- follow: a walk reads as a walk ----------------------------------------------
const from = { x: state.last.x, y: state.last.y };
where.childNodes = [];
parseInto(where, B, doc);                       // the repaint play.php does after go()
bindGo();
const you = doc.querySelector('.wmap .wyou');
const moved = XM.follow(doc, state, win);
ok('after a repaint the player\'s mark starts where it was',
  moved && you.style.transform === 'translate(' + from.x + 'px,' + from.y + 'px)' && you.style.transition === 'none',
  you.style.transform);
frames.splice(0).forEach((f) => f());
ok('and walks to the mill on the next frame, by CSS transition',
  you.style.transition === '' && you.style.transform === 'translate(' + parseFloat(you.getAttribute('data-x')) + 'px,'
  + parseFloat(you.getAttribute('data-y')) + 'px)' && you.getAttribute('data-where') === 'the_mill', you.style.transform);
ok('a repaint that did not move anybody animates nothing', XM.follow(doc, state, win) === false && frames.length === 0);

// -- the trip lock: a mark is not a door while you are walking ---------------------
// go() used to set .disabled on every .goto, and a mark is an SVG <g>, which has
// no such thing: mid-trip the map stayed live and said so. play.php's own
// tripLock and click handler, lifted out of the page and run on a fresh parse.
{
  const PLAY = fs.readFileSync(process.argv[5], 'utf8');
  const lift = (name) => {
    const at = PLAY.indexOf('  function ' + name + '(');
    const end = at < 0 ? -1 : PLAY.indexOf('\n  }\n', at);
    return at < 0 || end < 0 ? '' : PLAY.slice(at, end + 4);
  };
  const lockSrc = lift('tripLock'), bindSrc = lift('bindGo');
  ok('play.php has a trip lock and a click handler to lift', lockSrc !== '' && bindSrc !== '');
  const d2 = new Doc();
  const w2 = d2.body.appendChild(new El('div', d2)); w2.id = 'where';
  parseInto(w2, A, d2);
  XM.install(d2, win);
  const trips = [];
  const T = { going: false };
  const api = (new Function('$$', 'go', 'T', (lockSrc + '\n' + bindSrc).replace(/\bgoing\b/g, 'T.going') + '\nreturn { tripLock: tripLock, bindGo: bindGo };'))(
    (sel) => d2.querySelectorAll(sel), (to) => trips.push(to), T);
  api.bindGo();
  const m2 = (k) => d2.querySelectorAll('.wmap .wpl').find((e) => e.getAttribute('data-key') === k);
  const click = (el) => el.dispatchEvent({ type: 'click', target: el });
  api.tripLock(true);                         // what go() does as the trip starts
  click(m2('the_mill'));
  const kev = key('Enter', m2('first_lutheran')); m2('first_lutheran').dispatchEvent(kev);
  ok('during a trip a mark press is ignored, by click or by Enter', trips.length === 0 && !kev.prevented, JSON.stringify(trips));
  ok('and the mark reads aria-disabled, with the class the list\'s buttons wear too',
    m2('the_mill').getAttribute('aria-disabled') === 'true' && m2('the_mill').classList.contains('gooff')
    && d2.querySelectorAll('.goto').every((b) => b.getAttribute('aria-disabled') === 'true'));
  api.tripLock(false);                        // and as it ends
  click(m2('the_mill'));
  ok('after the trip the same mark travels again', trips.length === 1 && trips[0] === 'the_mill'
    && m2('the_mill').getAttribute('aria-disabled') === 'false', JSON.stringify(trips));
}

for (const [n, c, d] of results) process.stdout.write('R\t' + (c ? 1 : 0) + '\t' + n + '\t' + d.replace(/\s+/g, ' ') + '\n');
