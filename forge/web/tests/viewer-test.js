// Xeric — the viewer's harness. NOT RUN ON ITS OWN: viewer-test.php renders
// play.php, checks it loads xeric-view.js, and hands that file to this one as
// VIEWER_SRC (a path in argv[2]). What is here is the smallest DOM, WebGL, 2D
// canvas, WebXR and DeviceOrientationEvent that the viewer touches —
// fakes that record what was asked of them, so the assertions can read it
// back. No dependency, no npm: plain Node.
//
// Every result is one line, "R\t<0|1>\t<name>\t<detail>", which the PHP side
// turns into its own ok/FAIL lines so the suite reads like every other suite.
// Sloppy mode on purpose: the file is evaluated in this scope, so the browser
// globals it reads (document, window, Image, fetch…) are the fakes below.

const fs = require('fs');

const results = [];
function ok(name, cond, detail) {
  results.push([name, !!cond, detail === undefined ? '' : String(detail)]);
}
const near = (a, b, eps) => Math.abs(a - b) < (eps || 0.5);
const tick = () => new Promise((r) => setTimeout(r, 5));

// -- a DOM, as small as the viewer needs ---------------------------------------
function camel(s) { return s.replace(/-([a-z])/g, (m, c) => c.toUpperCase()); }

class ClassList {
  constructor(el) { this.el = el; }
  _get() { return String(this.el.className || '').split(/\s+/).filter(Boolean); }
  contains(c) { return this._get().includes(c); }
  add(...cs) { const s = this._get(); cs.forEach((c) => { if (!s.includes(c)) s.push(c); }); this.el.className = s.join(' '); }
  remove(...cs) { this.el.className = this._get().filter((c) => !cs.includes(c)).join(' '); }
  toggle(c, on) { if (on === undefined) on = !this.contains(c); if (on) this.add(c); else this.remove(c); return on; }
}

function matchCompound(e, c) {
  const m = /^([a-z0-9]+)?((?:[#.][\w-]+|\[[\w-]+\])*)$/i.exec(c);
  if (!m) throw new Error('selector the fake DOM cannot read: ' + c);
  if (m[1] && e.tagName !== m[1].toUpperCase()) return false;
  const bits = m[2].match(/[#.][\w-]+|\[[\w-]+\]/g) || [];
  return bits.every((b) => {
    if (b[0] === '#') return e.id === b.slice(1);
    if (b[0] === '.') return e.classList.contains(b.slice(1));
    return e.hasAttribute(b.slice(1, -1));
  });
}
function matches(e, sel, scope) {
  return sel.split(',').some((group) => {
    const parts = group.trim().split(/\s+/);
    if (!matchCompound(e, parts[parts.length - 1])) return false;
    let at = e.parentNode, i = parts.length - 2;
    while (i >= 0 && at) {
      if (matchCompound(at, parts[i])) i--;
      at = at.parentNode;
    }
    return i < 0;
  });
}

class El {
  constructor(tag) {
    this.tagName = tag.toUpperCase();
    this.className = ''; this.id = ''; this.children = []; this.parentNode = null;
    this.dataset = {}; this.attrs = {}; this.style = {}; this.hidden = false; this.disabled = false;
    this.listeners = {}; this.textContent = ''; this.classList = new ClassList(this);
    this.clientWidth = 0; this.clientHeight = 0; this.offsetLeft = 0; this.offsetTop = 0;
    this.width = 0; this.height = 0;
  }
  setAttribute(k, v) {
    this.attrs[k] = String(v);
    if (k === 'id') this.id = String(v);
    if (k === 'class') this.className = String(v);
    if (k.startsWith('data-')) this.dataset[camel(k.slice(5))] = String(v);
  }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  hasAttribute(k) { return k in this.attrs || (k.startsWith('data-') && camel(k.slice(5)) in this.dataset); }
  appendChild(c) { if (c.parentNode) c.remove(); c.parentNode = this; this.children.push(c); return c; }
  remove() {
    const p = this.parentNode; if (!p) return;
    p.children = p.children.filter((x) => x !== this); this.parentNode = null;
  }
  replaceWith(n) {
    const p = this.parentNode; if (!p) return;
    if (n.parentNode) n.remove();
    p.children[p.children.indexOf(this)] = n; n.parentNode = p; this.parentNode = null;
  }
  after(n) {
    const p = this.parentNode; if (!p) return;
    if (n.parentNode) n.remove();
    p.children.splice(p.children.indexOf(this) + 1, 0, n); n.parentNode = p;
  }
  get nextSibling() { const p = this.parentNode; return p ? p.children[p.children.indexOf(this) + 1] || null : null; }
  contains(n) { for (let x = n; x; x = x.parentNode) if (x === this) return true; return false; }
  closest(sel) { for (let x = this; x && x.tagName; x = x.parentNode) if (matches(x, sel)) return x; return null; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  removeEventListener(t, f) { this.listeners[t] = (this.listeners[t] || []).filter((x) => x !== f); }
  dispatch(t, extra) {
    const ev = Object.assign({ type: t, target: this, defaultPrevented: false,
      preventDefault() { this.defaultPrevented = true; } }, extra || {});
    (this.listeners[t] || []).slice().forEach((f) => f.call(this, ev));
    return ev;
  }
  click() { if (!this.disabled) this.dispatch('click'); }
  descendants() { const out = []; const walk = (e) => e.children.forEach((c) => { out.push(c); walk(c); }); walk(this); return out; }
  querySelectorAll(sel) { return this.descendants().filter((e) => matches(e, sel, this)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  setPointerCapture() {}
  getContext(kind) { return CANVAS.get(this, kind); }
}

const root = new El('html');
const body = root.appendChild(new El('body'));
const AUDIO = [];
const docListeners = {};
const document = {
  hidden: false,
  body,
  addEventListener(t, f) { (docListeners[t] = docListeners[t] || []).push(f); },
  removeEventListener(t, f) { docListeners[t] = (docListeners[t] || []).filter((x) => x !== f); },
  createElement(tag) {
    const e = new El(tag);
    if (tag === 'audio') {
      e.paused = true; e.plays = 0; e.src = '';
      e.play = function () { this.paused = false; this.plays++; return Promise.resolve(); };
      e.pause = function () { this.paused = true; };
      AUDIO.push(e);
    }
    return e;
  },
  querySelector: (s) => root.querySelector(s),
  querySelectorAll: (s) => root.querySelectorAll(s),
  getElementById: (id) => root.querySelector('#' + id),
};

// -- the window --------------------------------------------------------------
const winListeners = {};
const window = {
  devicePixelRatio: 1,
  addEventListener(t, f) { (winListeners[t] = winListeners[t] || []).push(f); },
  removeEventListener(t, f) { winListeners[t] = (winListeners[t] || []).filter((x) => x !== f); },
};
let rafs = 0;
function requestAnimationFrame(f) { rafs++; return setTimeout(() => f(Date.now()), 0); }
function cancelAnimationFrame(id) { clearTimeout(id); }

// An image that loads on the next turn, at the size it is told to, or fails
// when its address says so.
let IMG_W = 4096, IMG_H = 2048;
class Image {
  constructor() { this.listeners = {}; this.complete = false; this.naturalWidth = 0; this.naturalHeight = 0; this._src = ''; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  get src() { return this._src; }
  set src(v) {
    this._src = v;
    setTimeout(() => {
      if (/broken/.test(v)) { (this.listeners.error || []).forEach((f) => f()); return; }
      this.complete = true; this.naturalWidth = IMG_W; this.naturalHeight = IMG_H;
      (this.listeners.load || []).forEach((f) => f());
    }, 0);
  }
}

// -- WebGL and the 2D canvas, both recording ------------------------------------
// MODE says what this "browser" can do: webgl, a 2D context, shaders that
// compile, a texture upload that does not throw, and the GPU's ceiling.
const MODE = { webgl: true, twoD: true, compile: true, texThrows: false, max: 8192 };
const GLS = [], G2S = [];

function makeGL() {
  const calls = [], uni = {};
  const rec = (n, ret) => function (...a) { calls.push([n, ...a]); return typeof ret === 'function' ? ret(...a) : ret; };
  const t = {
    calls, uni, lost: false,
    count(n) { return calls.filter((c) => c[0] === n).length; },
    createShader: rec('createShader', (type) => ({ type })),
    shaderSource: rec('shaderSource', (s, text) => { s.src = text; }),
    getShaderParameter: rec('getShaderParameter', () => MODE.compile),
    createProgram: rec('createProgram', () => ({ program: true })),
    getProgramParameter: rec('getProgramParameter', () => true),
    getUniformLocation: rec('getUniformLocation', (p, n) => n),
    uniform1f: rec('uniform1f', (l, v) => { uni[l] = v; }),
    uniform2f: rec('uniform2f', (l, a, b) => { uni[l] = [a, b]; }),
    uniform4f: rec('uniform4f', (l, a, b, c, d) => { uni[l] = [a, b, c, d]; }),
    uniformMatrix4fv: rec('uniformMatrix4fv', (l, tr, m) => { uni[l] = Array.from(m); }),
    getParameter: rec('getParameter', (k) => (k === 'MAX_TEXTURE_SIZE' ? MODE.max : 0)),
    texImage2D: rec('texImage2D', () => { if (MODE.texThrows) throw new Error('tainted'); }),
    getExtension: rec('getExtension', (n) => (n === 'WEBGL_lose_context' ? { loseContext() { t.lost = true; } } : null)),
    makeXRCompatible: rec('makeXRCompatible', () => Promise.resolve()),
    createTexture: rec('createTexture', () => ({ texture: true })),
    createBuffer: rec('createBuffer', () => ({ buffer: true })),
  };
  // Anything else lower-case is a call to record; anything upper-case is a
  // constant, and its own name is as good a value as any.
  return new Proxy(t, {
    get(o, k) {
      if (k in o) return o[k];
      if (typeof k !== 'string') return undefined;
      if (/^[A-Z_0-9]+$/.test(k)) return k;
      return (o[k] = rec(k));
    },
  });
}
function make2D() {
  const calls = [];
  return { calls, count(n) { return calls.filter((c) => c[0] === n).length; },
    clearRect(...a) { calls.push(['clearRect', ...a]); },
    drawImage(...a) { calls.push(['drawImage', ...a]); } };
}
const CANVAS = {
  get(el, kind) {
    if (el.tagName !== 'CANVAS') return null;
    if (kind === 'webgl') {
      if (!MODE.webgl) return null;
      if (!el._gl) { el._gl = makeGL(); GLS.push(el._gl); }
      return el._gl;
    }
    if (kind === '2d') {
      if (!MODE.twoD) return null;
      if (!el._g2) { el._g2 = make2D(); G2S.push(el._g2); }
      return el._g2;
    }
    return null;
  },
};

// -- WebXR -------------------------------------------------------------------
const navigator = {};
class XRWebGLLayer {
  constructor(s, gl) { this.session = s; this.gl = gl; this.framebuffer = { xr: true }; }
  getViewport(view) { return view.vp; }
}
const ID = [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1];
function fakeXR(supported, opts) {
  opts = opts || {};
  const xr = { asked: [], sessions: [] };
  xr.isSessionSupported = (mode) => { xr.asked.push(mode); return supported; };
  xr.requestSession = (mode) => {
    const s = { mode, listeners: {}, frames: [], ended: 0, renderState: {} };
    s.addEventListener = (t, f) => { (s.listeners[t] = s.listeners[t] || []).push(f); };
    s.updateRenderState = (st) => { Object.assign(s.renderState, st); };
    s.requestReferenceSpace = () => Promise.resolve({ space: 'local' });
    s.requestAnimationFrame = (f) => { s.frames.push(f); return s.frames.length; };
    s.end = () => { s.ended++; (s.listeners.end || []).forEach((f) => f()); };
    s.step = () => {                                  // one headset frame, two eyes
      const f = s.frames.shift(); if (!f) return false;
      f(0, { getViewerPose() {
        if (opts.poseThrows) throw new Error('lost tracking');
        const eye = (x) => ({ transform: { inverse: { matrix: ID.slice() } }, projectionMatrix: ID.slice(),
          vp: { x, y: 0, width: 100, height: 100 } });
        return { views: [eye(0), eye(100)] };
      } });
      return true;
    };
    xr.sessions.push(s);
    return Promise.resolve(s);
  };
  return xr;
}

// -- the page's own glue: the world, and the network --------------------------
var W = 'viewer-town';
let FETCH = [];
let fetchAnswer = () => Promise.resolve({ ok: true, status: 200 });
function fetch(url) { FETCH.push(url); return fetchAnswer(url); }

// -- the viewer, exactly as the page is served it --------------------------------
const VIEWER_SRC = fs.readFileSync(process.argv[2], 'utf8');
let V = null;
try {
  eval(VIEWER_SRC);                                    // sets window.XericView, reading this scope's fakes
  V = window.XericView({ world: W });
  ok('the viewer, as served, loads and runs in Node', typeof V.pano === 'function'
    && typeof V.xr === 'function' && typeof V.speak === 'function' && typeof V.dayView === 'function');
} catch (e) {
  ok('the viewer, as served, loads and runs in Node', false, e && e.stack);
  finish();
}
const { pano, xrRay, mat4inv, dayView, speak, phoneGaze } = V;

const look = body.appendChild(new El('div'));
look.id = 'look';

let boxN = 0;
function makeBox(people, src) {
  const box = document.createElement('div');
  box.className = 'lookmain lookpanobox';
  box.dataset.src = src || ('scene.php?w=viewer-town&k=pano' + (++boxN));
  box.dataset.alt = 'the diner, morning';
  const cv = document.createElement('canvas');
  cv.className = 'lookpano'; cv.clientWidth = 400; cv.clientHeight = 200;
  box.appendChild(cv);
  const tg = document.createElement('div');
  tg.className = 'looktags';
  people.forEach(([h, b]) => {
    const t = document.createElement('button');
    t.className = 'looktag'; t.dataset.h = h; t.dataset.b = String(b); t.hidden = true;
    tg.appendChild(t);
  });
  box.appendChild(tg);
  look.children.slice().forEach((c) => { if (c.classList.contains('lookmain')) c.remove(); });
  look.appendChild(box);
  return box;
}
const tag = (box, h) => box.querySelectorAll('.looktag').find((t) => t.dataset.h === h)
  || look.querySelectorAll('.looktag').find((t) => t.dataset.h === h);
const px = (s) => parseFloat(String(s || 'NaN'));
function reset(mode) {
  Object.assign(MODE, { webgl: true, twoD: true, compile: true, texThrows: false, max: 8192 }, mode || {});
  navigator.xr = undefined; IMG_W = 4096; IMG_H = 2048;
  delete window.DeviceOrientationEvent; delete window.matchMedia; delete window.screen; document.hidden = false;
}

// -- the phone ---------------------------------------------------------------
// A DeviceOrientationEvent that exists (or, on "iOS", asks permission and
// answers as told), and a reading delivered the way the browser delivers one:
// to every window listener for `deviceorientation`.
function fakeOrientation(permission) {
  class DeviceOrientationEvent {}
  if (permission !== undefined) {
    DeviceOrientationEvent.asked = 0;
    DeviceOrientationEvent.requestPermission = () => {
      DeviceOrientationEvent.asked++;
      return permission === 'throws' ? Promise.reject(new Error('NotAllowedError')) : Promise.resolve(permission);
    };
  }
  window.DeviceOrientationEvent = DeviceOrientationEvent;
  return DeviceOrientationEvent;
}
function orient(alpha, beta, gamma) {
  (winListeners.deviceorientation || []).slice().forEach((f) => f({ type: 'deviceorientation', alpha, beta, gamma }));
}

async function main() {
  // == the panorama, through WebGL ==============================================
  reset();
  const PEOPLE = [['dot', 0], ['earl', 30], ['vic', 55], ['theo', 180], ['ruth', -60], ['ann', 100]];
  let box = makeBox(PEOPLE);
  let h = pano(box);
  await tick(); await tick();
  let gl = box.querySelector('canvas')._gl;
  ok('pano: a WebGL context is asked for first', !!gl);
  ok('pano: the look-around shader is compiled and linked', gl && gl.count('createShader') === 2 && gl.count('linkProgram') === 1);
  ok('pano: the fragment shader carries the shared look() sampler', gl && gl.calls.some((c) => c[0] === 'shaderSource' && /vec4 look\(vec3 d\)/.test(c[2])));
  ok('pano: the panorama is uploaded as a texture once it loads', gl && gl.count('texImage2D') === 1);
  ok('pano: and one frame is drawn, a quad as a triangle strip', gl && gl.count('drawArrays') === 1
    && gl.calls.find((c) => c[0] === 'drawArrays').slice(1).join() === 'TRIANGLE_STRIP,0,4');
  ok('pano: the canvas is sized to its box', box.querySelector('canvas').width === 400 && box.querySelector('canvas').height === 200
    && JSON.stringify(gl.uni.r) === '[400,200]');
  ok('pano: the first frame faces the room straight on', gl && gl.uni.y === 0 && gl.uni.p === 0 && near(gl.uni.f, Math.tan(0.7), 1e-9));
  ok('pano: a still sidebar asks for no further frames', gl && (await tick(), gl.count('drawArrays') === 1));

  // the people, at their bearings
  const f = Math.tan(0.7), asp = 2;
  ok('tags: somebody straight ahead stands in the middle', !tag(box, 'dot').hidden && near(px(tag(box, 'dot').style.left), 200));
  ok('tags: somebody 30 degrees right stands right of centre, where tan() puts them',
    !tag(box, 'earl').hidden && near(px(tag(box, 'earl').style.left), (Math.tan(Math.PI / 6) / (f * asp) + 1) / 2 * 400));
  ok('tags: just under the horizon, about chest height', px(tag(box, 'dot').style.top) > 100 && px(tag(box, 'dot').style.top) < 130);
  ok('tags: somebody behind you is hidden', tag(box, 'theo').hidden === true);
  ok('tags: somebody just past the edge of the view is hidden', tag(box, 'ruth').hidden === true);
  ok('tags: near the edge a tag fades, in the middle it does not',
    !tag(box, 'vic').hidden && +tag(box, 'vic').style.opacity < 1 && +tag(box, 'vic').style.opacity >= 0.15
    && tag(box, 'dot').style.opacity === '1');

  // turning
  let ev = box.querySelector('canvas').dispatch('keydown', { key: 'ArrowLeft' });
  await tick();
  ok('turn: the left arrow turns you left, one frame later', near(gl.uni.y, 0.12, 1e-9) && gl.count('drawArrays') === 2 && ev.defaultPrevented);
  ok('turn: and the people move right across the view as you do',
    near(px(tag(box, 'dot').style.left), (Math.tan(0.12) / (f * asp) + 1) / 2 * 400));
  ev = box.querySelector('canvas').dispatch('keydown', { key: 'q' });
  ok('turn: a key that is not an arrow is left to the page', !ev.defaultPrevented);
  for (let i = 0; i < 30; i++) box.querySelector('canvas').dispatch('keydown', { key: 'ArrowUp' });
  await tick();
  ok('turn: looking up stops short of straight overhead', near(gl.uni.p, 1.45, 1e-9));
  ok('turn: thirty presses coalesce into one frame', gl.count('drawArrays') === 3);
  box.querySelector('canvas').dispatch('wheel', { deltaY: 10 });
  await tick();
  ok('zoom: the wheel widens the field of view', near(h.view.fov, 1.4 * 1.08, 1e-9) && near(gl.uni.f, Math.tan(1.4 * 1.08 / 2), 1e-9));
  const yaw0 = h.view.yaw, cv0 = box.querySelector('canvas');
  cv0.dispatch('pointerdown', { clientX: 100, clientY: 50, pointerId: 1 });
  cv0.dispatch('pointermove', { clientX: 140, clientY: 50, pointerId: 1 });
  cv0.dispatch('pointerup', {});
  cv0.dispatch('pointermove', { clientX: 400, clientY: 50, pointerId: 1 });
  ok('drag: a drag turns you by the distance dragged, and only while held',
    near(h.view.yaw - yaw0, 40 * h.view.fov / 200, 1e-9));
  ok('seam: where you were facing is remembered for this view', V.lookYaw[box.dataset.src] === h.view);
  const resizeBefore = (winListeners.resize || []).length;
  h.destroy();
  ok('destroy: the WebGL context is handed back', gl.lost === true);
  ok('destroy: and the resize listener goes with it', (winListeners.resize || []).length === resizeBefore - 1);

  // a panorama wider than the GPU will take
  reset({ max: 2048 });
  box = makeBox([]); h = pano(box); await tick(); await tick();
  ok('pano: a panorama wider than the GPU\'s ceiling is shrunk once, on a 2D canvas',
    G2S.length > 0 && G2S[G2S.length - 1].count('drawImage') === 1
    && box.querySelector('canvas')._gl.count('texImage2D') === 1);
  h.destroy();

  // == no WebGL: the 2D strip ===================================================
  reset({ webgl: false });
  box = makeBox(PEOPLE); h = pano(box); await tick(); await tick();
  let g2 = box.querySelector('canvas')._g2;
  ok('2d: with no WebGL the 2D canvas draws instead', !!g2 && !box.querySelector('canvas')._gl);
  ok('2d: the strip is cleared and drawn three times side by side', g2 && g2.count('clearRect') === 1 && g2.count('drawImage') === 3);
  ok('2d: the strip is twice the canvas tall', g2 && g2.calls.filter((c) => c[0] === 'drawImage').every((c) => c[5] === 400));
  const k = 2 * 200 / Math.PI;
  ok('2d tags: placed linearly, angle times pixels per radian',
    !tag(box, 'earl').hidden && near(px(tag(box, 'earl').style.left), ((Math.PI / 6) * k / 200 + 1) / 2 * 400));
  ok('2d tags: and still hidden past the edge of the strip', tag(box, 'theo').hidden && tag(box, 'ann').hidden);
  const ruth2d = tag(box, 'ruth');                   // -60° → x = -1.047·k/200 ≈ -0.67, inside the strip
  ok('2d tags: the strip shows more of the room than the perspective did', ruth2d.hidden === false);
  const x0 = g2.calls.find((c) => c[0] === 'drawImage')[2];
  g2.calls.length = 0;
  box.querySelector('canvas').dispatch('keydown', { key: 'ArrowLeft' });
  await tick();
  ok('2d: turning left redraws the strip slid right, the way the shader turns', g2.count('drawImage') === 3
    && g2.calls.find((c) => c[0] === 'drawImage')[2] > x0);
  h.destroy();

  // shaders that will not compile are no WebGL at all
  reset({ compile: false });
  navigator.xr = fakeXR(Promise.resolve(true));
  box = makeBox([]); h = pano(box); await tick(); await tick();
  ok('2d: a GPU that will not compile the shader falls back to the strip', !!box.querySelector('canvas')._g2
    && box.querySelector('canvas')._g2.count('drawImage') === 3);
  ok('xr: and without WebGL there is no step-inside button, however supported', !box.querySelector('.lookxr'));
  h.destroy();

  // == no canvas at all: the plain image ==========================================
  reset({ webgl: false, twoD: false });
  box = makeBox(PEOPLE);
  h = pano(box);
  const still = look.querySelector('.lookmain');
  ok('plain: with no canvas the panorama becomes the still image', still && still.classList.contains('lookstill') && !box.parentNode);
  ok('plain: the image is the panorama, with its alt text', still && still.querySelector('img.lookimg')
    && still.querySelector('img.lookimg').src === box.dataset.src && still.querySelector('img.lookimg').alt === 'the diner, morning');
  ok('plain: the people become the row under a still, all shown',
    still && still.querySelector('.looktagrow') && still.querySelectorAll('.looktag').length === 6
    && still.querySelectorAll('.looktag').every((t) => !t.hidden));
  ok('plain: and destroy is harmless', (() => { try { h.destroy(); return true; } catch (e) { return false; } })());

  reset({ texThrows: true });
  box = makeBox(PEOPLE); h = pano(box); await tick(); await tick();
  ok('plain: a texture the GPU refuses also falls back to the still', look.querySelector('.lookmain.lookstill') && !box.parentNode);

  reset();
  box = makeBox([], 'scene.php?w=viewer-town&k=broken'); h = pano(box); await tick();
  ok('plain: an image that will not load takes the view away rather than showing a hole', !box.parentNode);

  // == step inside ================================================================
  reset();
  box = makeBox([]); h = pano(box); await tick(); await tick();
  ok('xr: no navigator.xr, no button, and no error', !box.querySelector('.lookxr'));
  h.destroy();

  reset(); navigator.xr = fakeXR(Promise.resolve(false));
  box = makeBox([]); h = pano(box); await tick(); await tick();
  ok('xr: isSessionSupported false shows no button, not a disabled one', !box.querySelector('.lookxr')
    && navigator.xr.asked.join() === 'immersive-vr');
  h.destroy();

  reset(); navigator.xr = fakeXR(Promise.reject(new Error('blocked by policy')));
  box = makeBox([]); h = pano(box); await tick(); await tick();
  ok('xr: a support check that rejects shows no button either', !box.querySelector('.lookxr'));
  h.destroy();

  reset(); navigator.xr = fakeXR(Promise.resolve(true));
  box = makeBox([]); h = pano(box); await tick(); await tick();
  let btn = box.querySelector('.lookxr');
  gl = box.querySelector('canvas')._gl;
  ok('xr: isSessionSupported true shows a step-inside button', !!btn && btn.textContent === 'step inside' && btn.tagName === 'BUTTON');
  btn.click(); await tick(); await tick();
  let s = navigator.xr.sessions[0];
  ok('xr: pressing it asks for an immersive session', !!s && s.mode === 'immersive-vr' && gl.count('makeXRCompatible') === 1);
  ok('xr: the session renders through an XRWebGLLayer on the same context',
    s && s.renderState.baseLayer instanceof XRWebGLLayer && s.renderState.baseLayer.gl === gl);
  ok('xr: and the button now says step out', btn.textContent === 'step out');
  const draws = gl.count('drawArrays');
  s.step();
  ok('xr: a headset frame draws once per eye, into the layer\'s framebuffer', gl.count('drawArrays') === draws + 2
    && gl.calls.some((c) => c[0] === 'bindFramebuffer' && c[2] && c[2].xr));
  ok('xr: each eye gets its ray matrix and its own viewport', JSON.stringify(gl.uni.m) === JSON.stringify(ID)
    && JSON.stringify(gl.uni.vp) === '[100,0,100,100]');
  ok('xr: and asks for the next frame', s.frames.length === 1);
  btn.click(); await tick();
  ok('xr: pressing again ends the session and the button says step inside', s.ended === 1 && btn.textContent === 'step inside');
  ok('xr: a frame after the end draws nothing', (() => { const d = gl.count('drawArrays'); s.step(); return gl.count('drawArrays') === d; })());
  h.destroy();

  reset(); navigator.xr = fakeXR(Promise.resolve(true), { poseThrows: true });
  box = makeBox([]); h = pano(box); await tick(); await tick();
  btn = box.querySelector('.lookxr'); btn.click(); await tick(); await tick();
  navigator.xr.sessions[0].step();
  ok('xr: anything that throws in a frame takes the button away, quietly', !box.querySelector('.lookxr')
    && navigator.xr.sessions[0].ended === 1);
  h.destroy();

  reset();
  let resolveLate; navigator.xr = fakeXR(new Promise((r) => { resolveLate = r; }));
  box = makeBox([]); h = pano(box); await tick(); await tick();
  h.destroy(); resolveLate(true); await tick();
  ok('xr: a view thrown away before the browser answered gets no button', !box.querySelector('.lookxr'));

  // the ray matrix
  const inv = mat4inv([2, 0, 0, 0, 0, 4, 0, 0, 0, 0, 5, 0, 0, 0, 0, 1]);
  ok('xr: mat4inv inverts', [0.5, 0, 0, 0, 0, 0.25, 0, 0, 0, 0, 0.2, 0, 0, 0, 0, 1].every((v, i) => near(inv[i], v, 1e-6)));
  ok('xr: and a singular matrix answers identity, not NaN', Array.from(mat4inv(new Array(16).fill(0))).join() === ID.join());
  const moved = ID.slice(); moved[12] = 5; moved[13] = -3; moved[14] = 9;
  ok('xr: leaning forward does not walk you into the pie case (translation is dropped)',
    Array.from(xrRay({ transform: { inverse: { matrix: moved } }, projectionMatrix: ID })).join() === ID.join());

  // == the day strip ================================================================
  reset();
  box = makeBox(PEOPLE, 'scene.php?w=viewer-town&k=morning');
  V.mount(box); await tick(); await tick();
  const oldGl = box.querySelector('canvas')._gl;
  V.lookView.view.yaw = 1;
  const day = look.appendChild(document.createElement('div')); day.className = 'lookday';
  const thumb = (src, kind, light, now) => {
    const t = document.createElement('button'); t.className = 'lookthumb';
    t.dataset.src = src; t.dataset.kind = kind; t.dataset.light = light; if (now) t.dataset.now = '1';
    day.appendChild(t); return t;
  };
  const tNoon = thumb('scene.php?w=viewer-town&k=noon', 'image', 'noon');
  const tDusk = thumb('scene.php?w=viewer-town&k=dusk', 'pano', 'dusk', true);
  const earlier = look.appendChild(document.createElement('p')); earlier.className = 'lookearlier'; earlier.hidden = true;
  dayView(tNoon);
  let main = look.querySelector('.lookmain');
  ok('day: pressing a still\'s light puts the still where the view was', main.classList.contains('lookstill')
    && main.querySelector('img.lookimg').src === tNoon.dataset.src);
  ok('day: the people come along as the row a still has, shown', main.querySelector('.looktagrow')
    && main.querySelectorAll('.looktag').length === 6 && main.querySelectorAll('.looktag').every((t) => !t.hidden));
  ok('day: the old WebGL context is handed back', oldGl.lost === true && V.lookView === null);
  ok('day: the pressed light is marked, and only it', tNoon.classList.contains('cur') && tNoon.getAttribute('aria-pressed') === 'true'
    && !tDusk.classList.contains('cur') && tDusk.getAttribute('aria-pressed') === 'false');
  ok('day: and the view says it is earlier', !earlier.hidden && earlier.textContent === 'earlier: noon');
  // back to a panorama: from the still, so seed where the still was facing
  V.lookYaw[tNoon.dataset.src] = { yaw: 1, pitch: 0, fov: 1.4 };
  dayView(tDusk); await tick(); await tick();
  main = look.querySelector('.lookmain');
  ok('day: pressing a panorama\'s light builds a fresh look-around', main.classList.contains('lookpanobox')
    && !!main.querySelector('canvas.lookpano') && !!V.lookView && !!main.querySelector('canvas')._gl);
  ok('day: where you were facing is kept across the swap', V.lookView && V.lookView.view.yaw === 1);
  ok('day: the people go back over the panorama, placed by bearing', main.querySelector('.looktags')
    && tag(main, 'theo').hidden === true);
  ok('day: the light marked now clears the earlier label', earlier.hidden && earlier.textContent === '');
  V.unmount();

  // == look with the phone =========================================================
  const RAD = Math.PI / 180;
  reset();
  box = makeBox(PEOPLE); h = pano(box); await tick(); await tick();
  ok('phone: no DeviceOrientationEvent, no toggle, and no error', !box.querySelector('.lookgaze'));
  h.destroy();

  reset(); fakeOrientation();
  window.matchMedia = (q) => ({ matches: q !== '(pointer: coarse)' });
  box = makeBox(PEOPLE); h = pano(box); await tick(); await tick();
  ok('phone: the event on a mouse-and-keyboard machine is not a phone, so no toggle', !box.querySelector('.lookgaze'));
  h.destroy();

  reset(); fakeOrientation();
  box = makeBox(PEOPLE); h = pano(box); await tick(); await tick();
  gl = box.querySelector('canvas')._gl;
  let gz = box.querySelector('.lookgaze');
  ok('phone: with DeviceOrientationEvent the toggle is offered, on the view, as a button',
    !!gz && gz.tagName === 'BUTTON' && gz.parentNode === box && gz.textContent === 'look with the phone');
  ok('phone: and it is off until pressed: pressed=false, nothing listening',
    gz.getAttribute('aria-pressed') === 'false' && !(winListeners.deviceorientation || []).length);
  orient(0, 90, 0); orient(40, 90, 0); await tick();
  ok('phone: while off, the phone turning moves nothing', h.view.yaw === 0 && gl.count('drawArrays') === 1);
  gz.click();
  ok('phone: a press turns it on', gz.getAttribute('aria-pressed') === 'true' && (winListeners.deviceorientation || []).length === 1
    && gz.classList.contains('on'));
  orient(10, 90, 0); await tick();
  ok('phone: the first reading only sets where the phone is: the view does not jump', h.view.yaw === 0 && h.view.pitch === 0);
  orient(40, 90, 0); await tick();
  ok('phone: turning the upright phone 30 degrees left turns the view 30 degrees left',
    near(h.view.yaw, 30 * RAD, 1e-9) && near(gl.uni.y, 30 * RAD, 1e-9));
  ok('phone: and the people move across the view as you turn',
    near(px(tag(box, 'dot').style.left), (Math.tan(30 * RAD) / (f * asp) + 1) / 2 * 400));
  orient(40, 110, 0); await tick();
  ok('phone: tipping the top back 20 degrees looks 20 degrees up', near(h.view.pitch, 20 * RAD, 1e-9) && near(gl.uni.p, 20 * RAD, 1e-9));
  orient(40, 90, 0); await tick();
  const cvG = box.querySelector('canvas');
  cvG.dispatch('pointerdown', { clientX: 100, clientY: 50, pointerId: 1 });
  cvG.dispatch('pointermove', { clientX: 150, clientY: 50, pointerId: 1 });
  cvG.dispatch('pointerup', {});
  const dragged = 50 * h.view.fov / 200;
  orient(20, 90, 0); await tick();
  ok('phone: a drag still turns the view, and the phone adds its turn on top',
    near(h.view.yaw, 30 * RAD + dragged - 20 * RAD, 1e-9));
  orient(350, 90, 0); orient(10, 90, 0); await tick();
  const before = h.view.yaw;
  orient(350, 90, 0); await tick();
  ok('phone: across north (10 to 350) is 20 degrees right, not 340 left', near(h.view.yaw, before - 20 * RAD, 1e-9));
  document.hidden = true;
  (docListeners.visibilitychange || []).slice().forEach((fn) => fn({ type: 'visibilitychange' }));
  ok('phone: a hidden tab turns it off', gz.getAttribute('aria-pressed') === 'false' && !(winListeners.deviceorientation || []).length);
  document.hidden = false;
  const held = h.view.yaw; orient(90, 90, 0); await tick();
  ok('phone: and off stays off', h.view.yaw === held);
  gz.click(); orient(0, 90, 0); gz.click();
  ok('phone: a second press turns it off again', gz.getAttribute('aria-pressed') === 'false' && !(winListeners.deviceorientation || []).length);
  gz.click();
  h.destroy();
  ok('phone: destroy takes the listeners and the toggle with it', !(winListeners.deviceorientation || []).length
    && !(docListeners.visibilitychange || []).length && !box.querySelector('.lookgaze'));

  // held sideways: the screen's own rotation is compensated
  reset(); fakeOrientation(); window.screen = { orientation: { angle: 90 } };
  box = makeBox([]); h = pano(box); await tick(); await tick();
  box.querySelector('.lookgaze').click();
  orient(90, 0, -90); orient(120, 0, -90); await tick();
  ok('phone: held sideways, turning 30 degrees left still turns the view 30 degrees left', near(h.view.yaw, 30 * RAD, 1e-9)
    && near(h.view.pitch, 0, 1e-9));
  h.destroy();
  ok('phone: phoneGaze, upright and level, faces the room straight on', (() => {
    const g = phoneGaze(0, 90, 0, 0); return near(g.yaw, 0, 1e-9) && near(g.pitch, 0, 1e-9); })());
  ok('phone: and flat on its back it looks straight down, still with a heading', (() => {
    const g = phoneGaze(30, 0, 0, 0); return near(g.pitch, -Math.PI / 2, 1e-9) && near(g.yaw, 30 * RAD, 1e-9); })());

  // iOS: permission, asked for in the press, and a no is quiet
  reset(); const ios = fakeOrientation('granted');
  box = makeBox([]); h = pano(box); await tick(); await tick();
  gz = box.querySelector('.lookgaze');
  ok('phone (iOS): the toggle is offered, and nothing is asked before a press', !!gz && ios.asked === 0);
  gz.click(); await tick();
  ok('phone (iOS): the press asks, and a yes turns it on', ios.asked === 1 && gz.getAttribute('aria-pressed') === 'true'
    && (winListeners.deviceorientation || []).length === 1);
  h.destroy();
  reset(); fakeOrientation('denied');
  box = makeBox([]); h = pano(box); await tick(); await tick();
  box.querySelector('.lookgaze').click(); await tick();
  ok('phone (iOS): a no takes the toggle away, quietly', !box.querySelector('.lookgaze') && !(winListeners.deviceorientation || []).length);
  h.destroy();
  reset(); fakeOrientation('throws');
  box = makeBox([]); h = pano(box); await tick(); await tick();
  box.querySelector('.lookgaze').click(); await tick();
  ok('phone (iOS): and so does a refusal that throws', !box.querySelector('.lookgaze'));
  h.destroy();

  // == the speaker =================================================================
  reset();
  const li = body.appendChild(document.createElement('li'));
  const mk = (m) => { const b = document.createElement('button'); b.className = 'mspk'; b.dataset.m = m;
    b.setAttribute('aria-pressed', 'false'); li.appendChild(b); return b; };
  const b1 = mk('41'), b2 = mk('42');
  FETCH = [];
  speak(b1);
  ok('speak: a press shows the line loading at once', b1.classList.contains('loading') && !b1.classList.contains('on'));
  await tick();
  ok('speak: it asks speak.php for that line in this world', FETCH[0] === 'speak.php?w=viewer-town&m=41');
  ok('speak: one <audio> is handed the same address and played', AUDIO.length === 1 && V.voiceAu.src === FETCH[0] && V.voiceAu.plays === 1);
  V.voiceAu.dispatch('playing');
  ok('speak: playing lights the button and says pressed', b1.classList.contains('on') && !b1.classList.contains('loading')
    && b1.getAttribute('aria-pressed') === 'true');
  speak(b1);
  ok('speak: a second press on the same line is stop', !b1.classList.contains('on') && b1.getAttribute('aria-pressed') === 'false'
    && V.voiceAu.paused && FETCH.length === 1);
  speak(b1); await tick(); V.voiceAu.dispatch('playing');
  ok('speak: and a third press plays it again', b1.classList.contains('on') && FETCH.length === 2);
  speak(b2); await tick();
  ok('speak: another line is a change of mind, not a duet', !b1.classList.contains('on') && b1.getAttribute('aria-pressed') === 'false'
    && b2.classList.contains('loading') && AUDIO.length === 1 && V.voiceAu.forBtn === b2);
  V.voiceAu.dispatch('playing'); V.voiceAu.dispatch('ended');
  ok('speak: when the line ends the button goes dark', !b2.classList.contains('on') && b2.getAttribute('aria-pressed') === 'false');

  fetchAnswer = () => Promise.resolve({ ok: false, status: 404 });
  const b3 = mk('43'); speak(b3); await tick();
  ok('speak: a line with nothing to hear stands the button down and says so', b3.disabled
    && b3.nextSibling && b3.nextSibling.className === 'mspkno' && b3.nextSibling.textContent === 'There is nothing in this line to hear.');
  fetchAnswer = () => Promise.resolve({ ok: false, status: 503, json: () => Promise.resolve({ error: 'the voice machine is asleep' }) });
  const b4 = mk('44'); speak(b4); await tick();
  ok('speak: a machine\'s refusal is shown in its own words', b4.disabled && b4.nextSibling.textContent === 'the voice machine is asleep');
  fetchAnswer = () => Promise.reject(new Error('offline'));
  const b5 = mk('45'); speak(b5); await tick();
  ok('speak: and an unreachable machine says that', b5.disabled && b5.nextSibling.textContent === 'the voice machine could not be reached');

  finish();
}

function finish() {
  for (const [n, c, d] of results) process.stdout.write('R\t' + (c ? 1 : 0) + '\t' + n + '\t' + d.replace(/\s+/g, ' ').slice(0, 300) + '\n');
  process.exit(0);
}

main().catch((e) => { ok('the harness ran to the end', false, e && e.stack); finish(); });
