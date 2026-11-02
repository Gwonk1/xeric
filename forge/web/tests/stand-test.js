// Xeric — the stand page's harness. NOT RUN ON ITS OWN: stand-test.php serves
// the page, checks it, and hands this file two paths — xeric-view.js and
// stand-sw.php's output, both exactly as served (argv[2], argv[3]).
//
// Two halves. The viewer's stand parts (tag quads at bearings, the ray, the
// hold's target, the panner, offline, step inside with and without layers)
// run beside a fake DOM, WebGL, 2D canvas, WebXR and AudioContext that record
// what was asked of them. Then the service worker runs beside a fake `self`,
// Cache Storage and network: the shell kept, a panorama kept by fingerprint,
// the API never answered from a cache.
//
// Every result is one line, "R\t<0|1>\t<name>\t<detail>", as viewer-test.js.

const fs = require('fs');

const results = [];
function ok(name, cond, detail) { results.push([name, !!cond, detail === undefined ? '' : String(detail)]); }
const near = (a, b, eps) => Math.abs(a - b) < (eps || 1e-6);
const tick = (ms) => new Promise((r) => setTimeout(r, ms || 5));
const RAD = Math.PI / 180;
const TAGP = Math.atan(-0.12) / RAD;                 // a tag's elevation: −0.12 at one metre, about −6.8°

// -- a DOM, as small as the stand parts need -----------------------------------
function camel(s) { return s.replace(/-([a-z])/g, (m, c) => c.toUpperCase()); }
class ClassList {
  constructor(el) { this.el = el; }
  _get() { return String(this.el.className || '').split(/\s+/).filter(Boolean); }
  contains(c) { return this._get().includes(c); }
  add(...cs) { const s = this._get(); cs.forEach((c) => { if (!s.includes(c)) s.push(c); }); this.el.className = s.join(' '); }
  remove(...cs) { this.el.className = this._get().filter((c) => !cs.includes(c)).join(' '); }
  toggle(c, on) { if (on === undefined) on = !this.contains(c); if (on) this.add(c); else this.remove(c); return on; }
}
function matchOne(e, c) {
  if (c[0] === '.') return e.classList.contains(c.slice(1));
  if (c[0] === '#') return e.id === c.slice(1);
  if (c[0] === '[') { const k = c.slice(1, -1); return k in e.attrs || (k.startsWith('data-') && camel(k.slice(5)) in e.dataset); }
  return e.tagName === c.toUpperCase();
}
function matches(e, sel) { return sel.split(',').some((s) => (s.trim().match(/[.#]?[\w-]+|\[[\w-]+\]/g) || []).every((c) => matchOne(e, c))); }
class El {
  constructor(tag) {
    this.tagName = tag.toUpperCase(); this.className = ''; this.id = ''; this.children = []; this.parentNode = null;
    this.dataset = {}; this.attrs = {}; this.style = {}; this.hidden = false; this.disabled = false; this.listeners = {};
    this.textContent = ''; this.classList = new ClassList(this);
    this.clientWidth = 0; this.clientHeight = 0; this.offsetLeft = 0; this.offsetTop = 0; this.width = 0; this.height = 0;
  }
  setAttribute(k, v) { this.attrs[k] = String(v); if (k.startsWith('data-')) this.dataset[camel(k.slice(5))] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  appendChild(c) { if (c.parentNode) c.remove(); c.parentNode = this; this.children.push(c); return c; }
  remove() { const p = this.parentNode; if (!p) return; p.children = p.children.filter((x) => x !== this); this.parentNode = null; }
  replaceWith(n) { const p = this.parentNode; if (!p) return; p.children[p.children.indexOf(this)] = n; n.parentNode = p; this.parentNode = null; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  removeEventListener(t, f) { this.listeners[t] = (this.listeners[t] || []).filter((x) => x !== f); }
  dispatch(t, extra) { const ev = Object.assign({ type: t, target: this, preventDefault() {} }, extra || {}); (this.listeners[t] || []).slice().forEach((f) => f(ev)); }
  click() { if (!this.disabled) this.dispatch('click'); }
  descendants() { const out = []; const walk = (e) => e.children.forEach((c) => { out.push(c); walk(c); }); walk(this); return out; }
  querySelectorAll(sel) { return this.descendants().filter((e) => matches(e, sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  setPointerCapture() {}
  getContext(kind) { return CANVAS(this, kind); }
}
const body = new El('body');
const document = {
  hidden: false, body,
  addEventListener() {}, removeEventListener() {},
  createElement: (t) => new El(t),
  querySelector: (s) => body.querySelector(s),
  querySelectorAll: (s) => body.querySelectorAll(s),
};
const window = { devicePixelRatio: 1, addEventListener() {}, removeEventListener() {} };
function requestAnimationFrame(f) { return setTimeout(() => f(Date.now()), 0); }
function cancelAnimationFrame(id) { clearTimeout(id); }
let IMG_SETS = 0;
class Image {
  constructor() { this.listeners = {}; this.complete = false; this.naturalWidth = 0; this.naturalHeight = 0; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  set src(v) { IMG_SETS++; this._src = v; setTimeout(() => { this.complete = true; this.naturalWidth = 1024; this.naturalHeight = 512; (this.listeners.load || []).forEach((f) => f()); }, 0); }
  get src() { return this._src; }
}

// -- WebGL, recording; and a 2D canvas that can write a name -----------------------
function makeGL() {
  const calls = [], uni = {};
  const rec = (n, ret) => function (...a) { calls.push([n, ...a]); return typeof ret === 'function' ? ret(...a) : ret; };
  const t = {
    calls, uni, count(n) { return calls.filter((c) => c[0] === n).length; },
    createShader: rec('createShader', (type) => ({ type })),
    shaderSource: rec('shaderSource', (s, text) => { s.src = text; }),
    getShaderParameter: rec('getShaderParameter', () => true),
    createProgram: rec('createProgram', () => ({ program: true })),
    getProgramParameter: rec('getProgramParameter', () => true),
    getUniformLocation: rec('getUniformLocation', (p, n) => n),
    uniform1f: rec('uniform1f', (l, v) => { uni[l] = v; }),
    uniform2f: rec('uniform2f', (l, a, b) => { uni[l] = [a, b]; }),
    uniform4f: rec('uniform4f', (l, a, b, c, d) => { uni[l] = [a, b, c, d]; }),
    uniformMatrix4fv: rec('uniformMatrix4fv', (l, tr, m) => { uni[l] = Array.from(m); }),
    getParameter: rec('getParameter', (k) => (k === 'MAX_TEXTURE_SIZE' ? 8192 : 0)),
    getExtension: rec('getExtension', () => null),
    makeXRCompatible: rec('makeXRCompatible', () => Promise.resolve()),
    createTexture: rec('createTexture', () => ({ texture: true })),
    createBuffer: rec('createBuffer', () => ({ buffer: true })),
    createFramebuffer: rec('createFramebuffer', () => ({ fb: true })),
  };
  return new Proxy(t, { get(o, k) {
    if (k in o) return o[k];
    if (typeof k !== 'string') return undefined;
    if (/^[A-Z_0-9]+$/.test(k)) return k;
    return (o[k] = rec(k));
  } });
}
function make2D() {
  const calls = [];
  return { calls, font: '', fillStyle: '', textAlign: '', textBaseline: '',
    clearRect(...a) { calls.push(['clearRect', ...a]); }, drawImage(...a) { calls.push(['drawImage', ...a]); },
    fillRect(...a) { calls.push(['fillRect', ...a]); }, fillText(...a) { calls.push(['fillText', ...a]); },
    measureText(s) { return { width: String(s).length * 12 }; } };
}
function CANVAS(el, kind) {
  if (el.tagName !== 'CANVAS') return null;
  if (kind === 'webgl') { if (!el._gl) el._gl = makeGL(); return el._gl; }
  if (kind === '2d') { if (!el._g2) el._g2 = make2D(); return el._g2; }
  return null;
}

// -- WebXR, with and without layers ------------------------------------------------
const navigator = {};
class XRWebGLLayer {
  constructor(s, gl) { this.session = s; this.gl = gl; this.framebuffer = { xr: true }; }
  getViewport(view) { return view.vp; }
}
const ID = [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1];
// A pose pointing at bearing b (degrees): rotation about y by −b, so −z goes to (sin b, 0, −cos b).
function poseAt(b, pitchDeg) {
  const r = b * RAD, p = (pitchDeg || 0) * RAD;
  const f = [Math.sin(r) * Math.cos(p), Math.sin(p), -Math.cos(r) * Math.cos(p)];   // forward
  const m = ID.slice(); m[8] = -f[0]; m[9] = -f[1]; m[10] = -f[2];
  return { transform: { matrix: m } };
}
let XRB = null;                                      // the XRWebGLBinding fake, when "Quest"
function fakeXR(opts) {
  opts = opts || {};
  const xr = { asked: [], sessions: [] };
  xr.isSessionSupported = (mode) => { xr.asked.push(mode); return Promise.resolve(true); };
  xr.requestSession = (mode, init) => {
    const s = { mode, init, listeners: {}, frames: [], ended: 0, renderState: {}, enabledFeatures: opts.features };
    s.addEventListener = (t, f) => { (s.listeners[t] = s.listeners[t] || []).push(f); };
    s.updateRenderState = (st) => { Object.assign(s.renderState, st); };
    s.requestReferenceSpace = () => Promise.resolve({ space: 'local' });
    s.requestAnimationFrame = (f) => { s.frames.push(f); return s.frames.length; };
    s.end = () => { s.ended++; (s.listeners.end || []).forEach((f) => f()); };
    s.fire = (t, b) => (s.listeners[t] || []).forEach((f) => f({ type: t, inputSource: { targetRaySpace: { ray: b } },
      frame: { getPose: (space) => (b === null ? null : poseAt(b, TAGP)) } }));
    s.step = () => {
      const f = s.frames.shift(); if (!f) return false;
      f(0, { getViewerPose() {
        if (opts.poseThrows) throw new Error('lost tracking');
        const eye = (x) => ({ transform: { matrix: ID.slice(), inverse: { matrix: ID.slice() } }, projectionMatrix: ID.slice(),
          vp: { x, y: 0, width: 100, height: 100 } });
        return { transform: { matrix: ID.slice() }, views: [eye(0), eye(100)] };
      } });
      return true;
    };
    xr.sessions.push(s);
    return Promise.resolve(s);
  };
  return xr;
}
class FakeBinding {
  constructor(s, gl) { this.s = s; this.gl = gl; this.made = []; this.subs = 0; this.viewSubs = 0; XRB = this; }
  createEquirectLayer(init) { const l = { kind: 'equirect', init, needsRedraw: false }; this.made.push(l); return l; }
  createProjectionLayer(init) { const l = { kind: 'projection', init }; this.made.push(l); return l; }
  getSubImage(layer) { this.subs++; return { colorTexture: { eq: true }, viewport: { x: 0, y: 0, width: layer.init.viewPixelWidth, height: layer.init.viewPixelHeight } }; }
  getViewSubImage(layer, view) { this.viewSubs++; return { colorTexture: { proj: true }, viewport: view.vp }; }
}

// -- an AudioContext that records ------------------------------------------------
function param(v) { return { value: v }; }
function fakeAudio(old) {
  const ctx = { connected: [], destination: { dest: true } };
  ctx.listener = old ? { setOrientation(...a) { this.o = a; } }
                     : { forwardX: param(0), forwardY: param(0), forwardZ: param(-1), upX: param(0), upY: param(1), upZ: param(0) };
  ctx.createPanner = () => {
    const p = { connectedTo: null, connect(d) { p.connectedTo = d; } };
    if (old) p.setPosition = (x, y, z) => { p.pos = [x, y, z]; };
    else { p.positionX = param(0); p.positionY = param(0); p.positionZ = param(0); }
    return p;
  };
  return ctx;
}

// -- the viewer, as served ---------------------------------------------------------
const VIEWER_SRC = fs.readFileSync(process.argv[2], 'utf8');
let V = null;
try {
  eval(VIEWER_SRC);
  V = window.XericView({ world: 'stand-town' });
  ok('the viewer, as served, loads in Node with the stand parts', ['tagPos', 'tagQuad', 'raySelect', 'holdTarget', 'panAt',
    'listenerFace', 'offline', 'standXR', 'rayDir', 'viewProj'].every((k) => typeof V[k] === 'function'));
} catch (e) { ok('the viewer, as served, loads in Node with the stand parts', false, e && e.stack); }

function makeBox(people) {
  const box = document.createElement('div');
  box.className = 'lookmain lookpanobox';
  box.dataset.src = 'api.php/v1/worlds/stand-town/look/bluebird/' + 'a'.repeat(40);
  const cv = document.createElement('canvas'); cv.className = 'lookpano'; cv.clientWidth = 400; cv.clientHeight = 200;
  box.appendChild(cv);
  const tg = document.createElement('div'); tg.className = 'looktags'; box.appendChild(tg);
  (people || []).forEach(([h, b]) => { const t = document.createElement('button'); t.className = 'looktag'; t.dataset.h = h; t.dataset.b = String(b); t.hidden = true; tg.appendChild(t); });
  body.appendChild(box);
  return box;
}

async function main() {
  if (!V) return;
  // == tags at their bearings ======================================================
  const at = (b) => V.tagPos(b);
  ok('tag quads: straight ahead is (0, −0.12, −1)', near(at(0)[0], 0) && near(at(0)[1], -0.12) && near(at(0)[2], -1));
  ok('tag quads: 90° is to the right, (1, −0.12, 0)', near(at(90)[0], 1) && near(at(90)[2], 0));
  ok('tag quads: 239° is (sin b, −0.12, −cos b)', near(at(239)[0], Math.sin(239 * RAD)) && near(at(239)[2], -Math.cos(239 * RAD)));
  const q = V.tagQuad(239, 0.1, 0.025);
  const corners = [0, 1, 2, 3].map((i) => [q[i * 5], q[i * 5 + 1], q[i * 5 + 2]]);
  const mid = [0, 1, 2].map((k) => corners.reduce((s, c) => s + c[k], 0) / 4);
  ok('tag quads: the quad is centred on its bearing', near(mid[0], at(239)[0]) && near(mid[1], -0.12) && near(mid[2], at(239)[2]));
  const across = [corners[1][0] - corners[0][0], corners[1][1] - corners[0][1], corners[1][2] - corners[0][2]];
  const up = [corners[2][0] - corners[0][0], corners[2][1] - corners[0][1], corners[2][2] - corners[0][2]];
  const c = at(239);
  ok('tag quads: square to the line from the eye (across and up are both perpendicular to it)',
    near(across[0] * c[0] + across[2] * c[2], 0) && near(up[0], 0) && near(up[2], 0) && near(up[1], 0.05));
  ok('tag quads: as wide as asked, and upright', near(Math.hypot(across[0], across[1], across[2]), 0.2) && near(across[1], 0));
  ok('tag quads: the name reads left to right (u 0→1 across, v 0 at the top)', q[3] === 0 && q[8] === 1 && q[14] === 0 && q[4] === 1);

  // == the ray ======================================================================
  const tags = [{ h: 'dot', b: 239 }, { h: 'earl', b: 10 }, { h: 'vic', b: 13 }];
  const dir = (b, p) => V.rayDir(poseAt(b, p === undefined ? TAGP : p).transform.matrix);
  ok('ray: rayDir reads −z off a pose', near(dir(90, 0)[0], 1) && near(dir(90, 0)[2], 0, 1e-9));
  ok('ray: pointing at a tag picks it', (V.raySelect(tags, dir(239)) || {}).h === 'dot');
  ok('ray: 5° off still picks it (within 7°)', (V.raySelect(tags, dir(244)) || {}).h === 'dot');
  ok('ray: 8° off picks nothing', V.raySelect(tags, dir(247)) === null);
  ok('ray: and so does a ray at the ceiling', V.raySelect(tags, dir(239, 60)) === null);
  ok('ray: two tags in reach, the nearer wins', (V.raySelect(tags, dir(12)) || {}).h === 'vic'
    && (V.raySelect(tags, dir(11)) || {}).h === 'earl');
  ok('ray: the angle is measured to the tag, under the horizon (a ray along the horizon is ~6.8° above it)',
    (V.raySelect(tags, dir(239, 0)) || {}).h === 'dot' && V.raySelect(tags, dir(239, 0), 6) === null
    && (V.raySelect(tags, dir(239), 1) || {}).h === 'dot');

  // == what a hold talks to ==========================================================
  ok('hold: a hit is that person', JSON.stringify(V.holdTarget({ h: 'dot', b: 239, name: 'Dot' }, ['dot'])) === JSON.stringify({ kind: 'person', h: 'dot', b: 239, name: 'Dot' }));
  ok('hold: empty space with nobody is nothing', V.holdTarget(null, []) === null);
  ok('hold: empty space with one person is nothing (no room opens for two)', V.holdTarget(null, ['dot']) === null);
  ok('hold: empty space with two or more is the room', (V.holdTarget(null, ['dot', 'earl']) || {}).kind === 'room'
    && (V.holdTarget(null, ['dot', 'earl', 'vic']) || {}).kind === 'room');

  // == the panner ======================================================================
  let ctx = fakeAudio(false);
  const bus = { bus: true };
  let p = V.panAt(ctx, 239, bus);
  ok('panner: HRTF', p.panningModel === 'HRTF');
  ok('panner: at the speaker\'s bearing, one metre out', near(p.positionX.value, Math.sin(239 * RAD)) && near(p.positionY.value, 0)
    && near(p.positionZ.value, -Math.cos(239 * RAD)));
  ok('panner: no fall-off with a distance that is not real', p.rolloffFactor === 0 && p.refDistance === 1);
  ok('panner: into the bus it is given', p.connectedTo === bus);
  p = V.panAt(ctx, 90);
  ok('panner: or straight out, and 90° is hard right', p.connectedTo === ctx.destination && near(p.positionX.value, 1) && near(p.positionZ.value, 0));
  ctx = fakeAudio(true);
  p = V.panAt(ctx, 180);
  ok('panner: an older browser\'s setPosition is used when there are no AudioParams', near(p.pos[0], 0) && near(p.pos[2], 1));
  V.listenerFace(ctx, [1, 0, 0]);
  ok('listener: an older browser is turned with setOrientation', ctx.listener.o.join() === '1,0,0,0,1,0');
  ctx = fakeAudio(false);
  V.listenerFace(ctx, [0, 0, 1], [0, 1, 0]);
  ok('listener: a newer one by its AudioParams', ctx.listener.forwardZ.value === 1 && ctx.listener.upY.value === 1);

  // == offline ===========================================================================
  const st = document.createElement('main');
  const talk = st.appendChild(document.createElement('button')); talk.setAttribute('data-verb', 'talk');
  const t1 = st.appendChild(document.createElement('button')); t1.dataset.verb = 'focus';
  const other = st.appendChild(document.createElement('button'));
  const lab = st.appendChild(document.createElement('p')); lab.className = 'lookearlier'; lab.hidden = true;
  const n = V.offline(st, true);
  ok('offline: every verb is disabled', n === 2 && talk.disabled && t1.disabled && talk.getAttribute('aria-disabled') === 'true');
  ok('offline: a control that is not a verb is left alone', !other.disabled);
  ok('offline: the room is labelled earlier', !lab.hidden && lab.textContent === 'earlier' && st.classList.contains('offline'));
  V.offline(st, false);
  ok('offline: back online, the verbs come back and the label goes', !talk.disabled && !t1.disabled && lab.hidden && !st.classList.contains('offline'));

  // == step inside, the stand's way: the shader path ===================================
  const log = [];
  let present = ['dot'], off = false, can = true, cap = null;
  const hooks = {
    holdMs: 30,
    tags: () => [{ h: 'dot', name: 'Dot', b: 239, focus: false }, { h: 'earl', name: 'Earl', b: 10, focus: true }],
    present: () => present, caption: () => cap, can: () => can, offline: () => off,
    tap: (hit) => log.push(['tap', hit && hit.h]),
    holdStart: (t) => log.push(['holdStart', t.kind, t.h || '']),
    holdEnd: (t) => log.push(['holdEnd', t.kind]),
    face: (m) => log.push(['face']),
    start: (mode) => log.push(['start', mode]), end: () => log.push(['end']),
  };
  let loads = 0;
  const bitmap = { width: 2048, height: 1024, bitmap: true };
  const load = (src) => { loads++; return Promise.resolve(bitmap); };
  navigator.xr = fakeXR();
  IMG_SETS = 0;
  let box = makeBox([['dot', 239]]);
  let h = V.mount(box, { load, stand: hooks });
  await tick(); await tick();
  let gl = box.querySelector('canvas')._gl;
  ok('load: the page\'s loader is asked for the panorama, and no <img> is', loads === 1 && IMG_SETS === 0);
  ok('load: the bitmap it hands back is the texture', gl.calls.some((c) => c[0] === 'texImage2D' && c[6] === bitmap)
    && gl.count('drawArrays') === 1);
  let btn = box.querySelector('.lookxr');
  ok('xr: the stand\'s step-inside button is offered', !!btn && btn.classList.contains('standxr') && btn.textContent === 'step inside');
  can = false; btn.click(); await tick();
  ok('xr: not before the page says so (come in first: sound and the microphone)', navigator.xr.sessions.length === 0);
  can = true; btn.click(); await tick(); await tick();
  let s = navigator.xr.sessions[0];
  ok('xr: then an immersive session, asking for layers as optional', !!s && s.mode === 'immersive-vr'
    && JSON.stringify(s.init) === '{"optionalFeatures":["layers"]}');
  ok('xr: no XRWebGLBinding, so the per-pixel shader through an XRWebGLLayer', s.renderState.baseLayer instanceof XRWebGLLayer
    && log.some((l) => l[0] === 'start' && l[1] === 'shader'));
  let draws = gl.count('drawArrays');
  s.step();
  ok('xr: a frame draws, per eye, the room and then each tag', gl.count('drawArrays') === draws + 2 * 3);
  ok('xr: the tags are drawn blended, with projection × rotation', gl.count('enable') >= 2 && JSON.stringify(gl.uni.m) === JSON.stringify(ID));
  ok('xr: a tag is a canvas with its name on it, uploaded once', gl.calls.filter((c) => c[0] === 'texImage2D' && c[6] && c[6].tagName === 'CANVAS').length === 2
    && (s.step(), gl.calls.filter((c) => c[0] === 'texImage2D' && c[6] && c[6].tagName === 'CANVAS').length === 2));
  ok('xr: the focused tag is lit (its own texture)', gl.calls.some((c) => c[0] === 'texImage2D' && c[6] && c[6]._g2
    && c[6]._g2.calls.some((k) => k[0] === 'fillText' && k[1] === 'Earl')));
  ok('xr: the listener is told where the head faces', log.some((l) => l[0] === 'face'));

  // select: tap, hold, hold on empty space
  log.length = 0;
  s.fire('selectstart', 241); s.fire('select', 241); s.fire('selectend', 241);
  ok('select: a tap on Dot\'s tag focuses Dot', JSON.stringify(log) === '[["tap","dot"]]');
  log.length = 0;
  s.fire('selectstart', 100); s.fire('select', 100); s.fire('selectend', 100);
  ok('select: a tap on nothing is a tap on nothing', JSON.stringify(log) === '[["tap",null]]');
  log.length = 0;
  s.fire('selectstart', 236); await tick(60);
  ok('select: holding on Dot talks to Dot', JSON.stringify(log) === '[["holdStart","person","dot"]]');
  s.fire('select', 300); s.fire('selectend', 300);
  ok('select: and letting go ends it, to whoever the press was on', JSON.stringify(log.slice(1)) === '[["holdEnd","person"]]');
  log.length = 0;
  s.fire('selectstart', 100); await tick(60); s.fire('select', 100); s.fire('selectend', 100);
  ok('select: holding on empty space with one person present is nothing', log.length === 0);
  present = ['dot', 'earl'];
  s.fire('selectstart', 100); await tick(60); s.fire('select', 100); s.fire('selectend', 100);
  ok('select: with two present it is the room', JSON.stringify(log) === '[["holdStart","room",""],["holdEnd","room"]]');
  log.length = 0; off = true;
  s.fire('selectstart', 239); s.fire('select', 239); s.fire('selectend', 239);
  s.fire('selectstart', 239); await tick(60); s.fire('selectend', 239);
  ok('select: offline, neither a tap nor a hold does anything', log.length === 0);
  off = false;
  cap = { text: 'Is the pie fresh today?', b: 239 };
  draws = gl.count('drawArrays'); s.step();
  ok('caption: while there is one it is drawn too, wide', gl.count('drawArrays') === draws + 2 * 4);
  cap = null;
  btn.click(); await tick();
  ok('xr: ending the session restores the 2D view and tells the page', s.ended === 1 && btn.textContent === 'step inside'
    && log.some((l) => l[0] === 'end') && gl.calls.some((c) => c[0] === 'bindFramebuffer' && c[2] === null));
  h.destroy();

  // == Quest: an equirect layer =========================================================
  root_XRWebGLBinding(FakeBinding);
  navigator.xr = fakeXR({ features: ['layers', 'local'] });
  log.length = 0;
  box = makeBox([['dot', 239]]); h = V.mount(box, { load, stand: hooks }); await tick(); await tick();
  gl = box.querySelector('canvas')._gl;
  box.querySelector('.lookxr').click(); await tick(); await tick();
  s = navigator.xr.sessions[0];
  const L = s.renderState.layers || [];
  ok('layers: with XRWebGLBinding the room is an XREquirectLayer under a projection layer for the tags',
    L.length === 2 && L[0].kind === 'equirect' && L[1].kind === 'projection' && !s.renderState.baseLayer
    && log.some((l) => l[0] === 'start' && l[1] === 'layers'));
  const ei = L[0] && L[0].init;
  ok('layers: the whole sphere, at infinity, mono, at the panorama\'s size', ei && ei.radius === 0 && near(ei.centralHorizontalAngle, 2 * Math.PI)
    && near(ei.upperVerticalAngle, Math.PI / 2) && near(ei.lowerVerticalAngle, -Math.PI / 2) && ei.layout === 'mono'
    && ei.viewPixelWidth === 2048 && ei.viewPixelHeight === 1024);
  draws = gl.count('drawArrays');
  s.step();
  ok('layers: the first frame copies the room into the layer once, and draws only tags per eye',
    XRB.subs === 1 && XRB.viewSubs === 2 && gl.count('drawArrays') === draws + 1 + 2 * 2);
  ok('layers: the tag layer is cleared to transparent', gl.calls.some((c) => c[0] === 'clearColor' && c[4] === 0));
  draws = gl.count('drawArrays');
  s.step();
  ok('layers: after that the room is the compositor\'s: no copy, no per-pixel shader', XRB.subs === 1 && gl.count('drawArrays') === draws + 2 * 2);
  L[0].needsRedraw = true; s.step();
  ok('layers: unless the compositor says it lost it', XRB.subs === 2);
  s.end(); h.destroy();

  // a session that refused layers falls back
  navigator.xr = fakeXR({ features: ['local'] });
  box = makeBox([]); h = V.mount(box, { load, stand: hooks }); await tick(); await tick();
  box.querySelector('.lookxr').click(); await tick(); await tick();
  s = navigator.xr.sessions[0];
  ok('layers: a session that did not grant layers gets the shader', s.renderState.baseLayer instanceof XRWebGLLayer && !s.renderState.layers);
  s.end(); h.destroy();
  root_XRWebGLBinding(undefined);

  // anything that throws
  navigator.xr = fakeXR({ poseThrows: true });
  box = makeBox([]); h = V.mount(box, { load, stand: hooks }); await tick(); await tick();
  box.querySelector('.lookxr').click(); await tick(); await tick();
  navigator.xr.sessions[0].step();
  ok('xr: anything that throws in a frame takes the button away and ends the session', !box.querySelector('.lookxr')
    && navigator.xr.sessions[0].ended === 1);
  h.destroy();

  // play.php's path is untouched: no stand hooks, the old xr()
  navigator.xr = fakeXR();
  box = makeBox([]); h = V.mount(box); await tick(); await tick();
  ok('play.php\'s view (no opt) still gets the plain step inside, and loads by <img>', !!box.querySelector('.lookxr')
    && !box.querySelector('.standxr') && IMG_SETS === 1);
  h.destroy();
}
function root_XRWebGLBinding(v) { window.XRWebGLBinding = v; }

// == the service worker =================================================================
async function sw() {
  const SRC = fs.readFileSync(process.argv[3], 'utf8');
  const stores = {}, net = [];
  let online = true;
  class Resp { constructor(body, init) { this.body = body; this.status = (init && init.status) || 200; this.ok = this.status < 400; }
    clone() { return new Resp(this.body, { status: this.status }); } }
  const ORIGIN = 'http://127.0.0.1:8787';
  const abs = (u) => (typeof u === 'string' ? new URL(u, ORIGIN + '/forge/stand-sw.php').href : u.url);
  const caches = {
    open(name) {
      const m = stores[name] = stores[name] || new Map();
      return Promise.resolve({
        put(k, r) { m.set(abs(k), r); return Promise.resolve(); },
        match(k) { return Promise.resolve(m.get(abs(k))); },
        keys() { return Promise.resolve([...m.keys()]); },
        delete(k) { m.delete(abs(k)); return Promise.resolve(true); },
      });
    },
    keys() { return Promise.resolve(Object.keys(stores)); },
    delete(n) { delete stores[n]; return Promise.resolve(true); },
  };
  const listeners = {};
  const self = { location: new URL(ORIGIN + '/forge/stand-sw.php'), addEventListener(t, f) { listeners[t] = f; },
    skipWaiting() { return Promise.resolve(); }, clients: { claim() { return Promise.resolve(); } } };
  const fetch = (r) => { const u = abs(r); net.push(u); return online ? Promise.resolve(new Resp('net:' + u)) : Promise.reject(new Error('offline')); };
  const Response = Resp;
  try { (new Function('self', 'caches', 'fetch', 'Response', 'URL', SRC))(self, caches, fetch, Response, URL); }
  catch (e) { ok('sw: the service worker, as served, runs', false, e && e.stack); return; }
  ok('sw: the service worker, as served, runs and listens', !!listeners.install && !!listeners.fetch && !!listeners.activate);
  let wait = null;
  listeners.install({ waitUntil(p) { wait = p; } }); await wait;
  const shellName = Object.keys(stores).find((k) => /^xeric-stand-[0-9a-f]{16}$/.test(k));
  const kept = shellName ? [...stores[shellName].keys()] : [];
  ok('sw: install keeps the shell first: the page, the viewer by its hash, the icon',
    kept.includes(ORIGIN + '/forge/stand.php') && kept.some((k) => /xeric-view\.js\?v=[0-9a-f]{16}$/.test(k))
    && kept.includes(ORIGIN + '/forge/stand-icon.png'));
  const go = async (url, mode) => {
    let resp = null;
    listeners.fetch({ request: { method: 'GET', url, mode: mode || 'cors' }, respondWith(p) { resp = p; } });
    return resp ? await resp : 'network';
  };
  net.length = 0;
  const pulse = await go(ORIGIN + '/forge/api.php/v1/worlds/stand-town/pulse');
  const look = await go(ORIGIN + '/forge/api.php/v1/worlds/stand-town/look');
  ok('sw: API calls are never answered by the worker (always the network)', pulse === 'network' && look === 'network');
  const pano = ORIGIN + '/forge/api.php/v1/worlds/stand-town/look/bluebird/' + 'b'.repeat(40);
  const r1 = await go(pano); await tick();
  online = false;
  const r2 = await go(pano);
  ok('sw: a panorama is kept by its fingerprint and served from the cache after', r1 && r2 && r2.body === 'net:' + pano
    && (stores['xeric-stand-panos'] || new Map()).has(pano));
  const snd = await go(ORIGIN + '/forge/api.php/v1/worlds/stand-town/look/bluebird/sound:' + 'c'.repeat(40));
  ok('sw: a sound is not a panorama: the network', snd === 'network');
  const nav = await go(ORIGIN + '/forge/stand.php?w=stand-town', 'navigate');
  ok('sw: offline, the page is the kept shell (any ?w=: the shell carries no world)', nav && /stand\.php$/.test(nav.body.replace('net:', '')));
  const post = (() => { let r = null; listeners.fetch({ request: { method: 'POST', url: ORIGIN + '/forge/api.php/v1/worlds/x/threads/dot/say' }, respondWith(p) { r = p; } }); return r; })();
  ok('sw: a verb is never touched, so nothing is ever queued', post === null);
  stores['xeric-stand-0000000000000000'] = new Map();
  listeners.activate({ waitUntil(p) { wait = p; } }); await wait;
  ok('sw: a new version drops the old shell and keeps the panoramas', !stores['xeric-stand-0000000000000000']
    && !!stores['xeric-stand-panos'] && !!stores[shellName]);
}

function finish() {
  for (const [n, c, d] of results) process.stdout.write('R\t' + (c ? 1 : 0) + '\t' + n + '\t' + d.replace(/\s+/g, ' ').slice(0, 300) + '\n');
  process.exit(0);
}
main().then(sw).catch((e) => { ok('the harness ran to the end', false, e && e.stack); }).then(finish);
