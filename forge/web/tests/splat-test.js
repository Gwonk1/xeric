// Xeric — the splat viewer's harness. NOT RUN ON ITS OWN: splat-test.php
// renders play.php with a splat on screen, checks it loads xeric-splat.js, and
// hands this file the served viewer (argv[2]), its worker (argv[3]),
// xeric-view.js (argv[4]) and a fixture pair the PHP converter wrote
// (argv[5] = .splat, argv[6] = the PLY tools/splat-to-ply.php made of it).
//
// The fakes are the smallest DOM, WebGL 2 / WebGL 1 and Worker the viewer
// touches. The Worker is not a stub: it runs the real worker file in a
// sandbox of its own, so the sort tested is the sort shipped.
//
// Every result is one line, "R\t<0|1>\t<name>\t<detail>", as viewer-test.js.

const fs = require('fs');
const vm = require('vm');

const results = [];
function ok(name, cond, detail) { results.push([name, !!cond, detail === undefined ? '' : String(detail)]); }
const near = (a, b, eps) => Math.abs(a - b) < (eps === undefined ? 1e-5 : eps);
const tick = (ms) => new Promise((r) => setTimeout(r, ms || 5));

const [, , VIEW_SRC, WORKER_SRC, XV_SRC, FIX_SPLAT, FIX_PLY] = process.argv;
const workerCode = fs.readFileSync(WORKER_SRC, 'utf8');

// -- a DOM, as small as the viewer needs ------------------------------------------
class ClassList {
  constructor(el) { this.el = el; }
  _get() { return String(this.el.className || '').split(/\s+/).filter(Boolean); }
  contains(c) { return this._get().includes(c); }
  add(...cs) { const s = this._get(); cs.forEach((c) => { if (!s.includes(c)) s.push(c); }); this.el.className = s.join(' '); }
  remove(...cs) { this.el.className = this._get().filter((c) => !cs.includes(c)).join(' '); }
}
class El {
  constructor(tag) {
    this.tagName = tag.toUpperCase(); this.className = ''; this.children = []; this.parentNode = null;
    this.dataset = {}; this.attrs = {}; this.hidden = false; this.textContent = ''; this.listeners = {};
    this.classList = new ClassList(this); this.clientWidth = 400; this.clientHeight = 300; this.width = 0; this.height = 0;
  }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  appendChild(c) { if (c.parentNode) c.remove(); c.parentNode = this; this.children.push(c); return c; }
  remove() { const p = this.parentNode; if (!p) return; p.children = p.children.filter((x) => x !== this); this.parentNode = null; }
  querySelector(sel) {
    const cls = sel.replace(/^[a-z]*\./, '');
    const walk = (e) => { for (const c of e.children) { if (c.classList.contains(cls)) return c; const f = walk(c); if (f) return f; } return null; };
    return walk(this);
  }
  querySelectorAll() { return []; }
  addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); }
  removeEventListener(t, fn) { this.listeners[t] = (this.listeners[t] || []).filter((f) => f !== fn); }
  fire(t, e) { (this.listeners[t] || []).slice().forEach((fn) => fn(Object.assign({ preventDefault() {} }, e))); }
  setPointerCapture() {}
}

// -- WebGL, recorded --------------------------------------------------------------
function fakeGL(opts) {
  opts = opts || {};
  const calls = [];
  const base = {
    calls,
    createShader: () => ({}), createProgram: () => ({}), createBuffer: () => ({}),
    getShaderParameter: () => true, getProgramParameter: () => true, getShaderInfoLog: () => '',
    getUniformLocation: (p, n) => ({ n }),
    getExtension: (n) => {
      calls.push(['getExtension', [n]]);
      if (n === 'ANGLE_instanced_arrays' && opts.angle) {
        return { vertexAttribDivisorANGLE: (...a) => calls.push(['vertexAttribDivisorANGLE', a]),
                 drawArraysInstancedANGLE: (...a) => calls.push(['drawArraysInstancedANGLE', a]) };
      }
      if (n === 'WEBGL_lose_context') return { loseContext: () => calls.push(['loseContext', []]) };
      return null;
    },
  };
  let k = 1;
  const consts = {};
  return new Proxy(base, {
    get(t, p) {
      if (p in t) return t[p];
      if (typeof p === 'string' && /^[A-Z0-9_]+$/.test(p)) return consts[p] || (consts[p] = 0x1000 + (k++));
      return (...a) => { calls.push([p, a]); return undefined; };
    },
  });
}
function canvas(mode) {
  const cv = new El('canvas');
  cv.className = 'looksplat';
  cv.dataset.worker = 'xeric-splat-worker.js?v=0123456789abcdef';
  cv.gl = null;
  cv.getContext = (kind) => {
    if (mode === 'none') return null;
    if (kind === 'webgl2' && mode === 'webgl2') return (cv.gl = fakeGL());
    if (kind === 'webgl' && mode === 'webgl1') return (cv.gl = fakeGL({ angle: true }));
    if (kind === 'webgl' && mode === 'webgl1-no-instancing') return (cv.gl = fakeGL({ angle: false }));
    return null;
  };
  const box = new El('div');
  box.className = 'lookmain looksplatbox';
  box.appendChild(cv);
  const st = new El('p'); st.className = 'looksplatstate'; box.appendChild(st);
  const dl = new El('p'); dl.className = 'looksplatdl'; box.appendChild(dl);
  return { cv, box, st, dl };
}

// -- the Worker, for real, in a sandbox -------------------------------------------
const workers = [];
class FakeWorker {
  constructor(url) {
    this.url = url; this.sent = []; this.terminated = false; workers.push(this);
    const self = { postMessage: (m, tr) => { this.back = (this.back || 0) + 1; this.backTransfer = tr; setTimeout(() => { if (!this.terminated && this.onmessage) this.onmessage({ data: m }); }, 0); } };
    this.ctx = vm.createContext({ self, setTimeout, Float32Array, Uint16Array, Uint32Array, Math, Infinity });
    vm.runInContext(workerCode, this.ctx, { filename: 'xeric-splat-worker.js' });
    this.self = self;
  }
  postMessage(m, tr) { this.sent.push([m, tr]); setTimeout(() => { if (!this.terminated) this.self.onmessage({ data: m }); }, 0); }
  terminate() { this.terminated = true; }
}

// -- the page's globals ---------------------------------------------------------------
let fetched = [];
let respond = null;
globalThis.window = globalThis;
globalThis.devicePixelRatio = 2;
globalThis.document = { createElement: (t) => new El(t), querySelector: () => null, querySelectorAll: () => [] };
globalThis.requestAnimationFrame = (fn) => setTimeout(fn, 0);
globalThis.cancelAnimationFrame = (id) => clearTimeout(id);
globalThis.fetch = (url, o) => { fetched.push(url); return Promise.resolve(respond(url, o)); };
globalThis.Worker = FakeWorker;
vm.runInThisContext(fs.readFileSync(VIEW_SRC, 'utf8'), { filename: 'xeric-splat.js' });
const XS = globalThis.XericSplat;
ok('xeric-splat.js defines one global, XericSplat, and runs nothing on load',
  !!XS && typeof XS.mount === 'function' && fetched.length === 0 && workers.length === 0);

// -- fixtures: three splats, both formats, built by hand ----------------------------
const SH_C0 = 0.28209479177387814;
const three = [
  { p: [0, 0, -1], s: [0.1, 0.2, 0.3], c: [255, 0, 0, 255], q: [1, 0, 0, 0] },
  { p: [0, 0, -5], s: [0.5, 0.5, 0.5], c: [0, 255, 0, 128], q: [0, 1, 0, 0] },
  { p: [1, 0, -3], s: [0.05, 0.05, 0.05], c: [0, 0, 255, 64], q: [Math.SQRT1_2, 0, 0, Math.SQRT1_2] },
];
function makeSplat(list) {
  const b = new ArrayBuffer(list.length * 32), dv = new DataView(b);
  list.forEach((x, i) => {
    const o = i * 32;
    x.p.forEach((v, k) => dv.setFloat32(o + k * 4, v, true));
    x.s.forEach((v, k) => dv.setFloat32(o + 12 + k * 4, v, true));
    x.c.forEach((v, k) => dv.setUint8(o + 24 + k, v));
    x.q.forEach((v, k) => dv.setUint8(o + 28 + k, Math.max(0, Math.min(255, Math.round(v * 128 + 128)))));
  });
  return b;
}
function makePly(list) {
  // nx ny nz first and three f_rest_* in the middle: properties the viewer
  // must step over by offset without naming them.
  const props = ['x', 'y', 'z', 'nx', 'ny', 'nz', 'f_dc_0', 'f_dc_1', 'f_dc_2', 'f_rest_0', 'f_rest_1', 'f_rest_2',
                 'opacity', 'scale_0', 'scale_1', 'scale_2', 'rot_0', 'rot_1', 'rot_2', 'rot_3'];
  const head = 'ply\nformat binary_little_endian 1.0\ncomment three splats, by hand\nelement vertex ' + list.length + '\n'
    + props.map((p) => 'property float ' + p + '\n').join('') + 'end_header\n';
  const hb = Buffer.from(head, 'latin1');
  const b = new ArrayBuffer(hb.length + list.length * props.length * 4);
  new Uint8Array(b).set(hb);
  const dv = new DataView(b, hb.length);
  list.forEach((x, i) => {
    const a = x.c[3] / 255;
    const v = { x: x.p[0], y: x.p[1], z: x.p[2], nx: 9, ny: 9, nz: 9,
      f_dc_0: (x.c[0] / 255 - 0.5) / SH_C0, f_dc_1: (x.c[1] / 255 - 0.5) / SH_C0, f_dc_2: (x.c[2] / 255 - 0.5) / SH_C0,
      f_rest_0: 7, f_rest_1: 7, f_rest_2: 7,
      opacity: Math.log(Math.max(a, 1e-3) / Math.max(1 - a, 1e-3)),
      scale_0: Math.log(x.s[0]), scale_1: Math.log(x.s[1]), scale_2: Math.log(x.s[2]),
      rot_0: x.q[0] * 2, rot_1: x.q[1] * 2, rot_2: x.q[2] * 2, rot_3: x.q[3] * 2 };   // unnormalised on purpose
    props.forEach((p, k) => dv.setFloat32((i * props.length + k) * 4, v[p], true));
  });
  return b;
}
function rec(s, i) {
  const o = i * 12;
  return { p: [s.f[o], s.f[o + 1], s.f[o + 2]], s: [s.f[o + 3], s.f[o + 4], s.f[o + 5]],
           q: [s.f[o + 6], s.f[o + 7], s.f[o + 8], s.f[o + 9]], c: Array.from(s.u8.slice(o * 4 + 40, o * 4 + 44)) };
}
const splatBuf = makeSplat(three), plyBuf = makePly(three);

// -- sniffing and parsing -------------------------------------------------------------
ok('sniff: PLY by its magic, .splat by its 32-byte records',
  XS.sniff(plyBuf).kind === 'ply' && XS.sniff(splatBuf).kind === 'splat');
const gz = new Uint8Array([0x1f, 0x8b, 8, 0]).buffer, zip = new Uint8Array([0x50, 0x4b, 3, 4]).buffer;
ok('sniff: SPZ (gzip) and SOG (zip) are refused by name, odd lengths as not a splat',
  XS.sniff(gz).kind === null && /SPZ/.test(XS.sniff(gz).why) && /SOG/.test(XS.sniff(zip).why)
  && XS.sniff(new ArrayBuffer(33)).kind === null);

const ps = XS.parseSplat(splatBuf);
ok('.splat: three splats, nothing cut', ps.n === 3 && ps.total === 3 && ps.truncated === false);
let r1 = rec(ps, 1), r2 = rec(ps, 2);
ok('.splat: position and scale are the floats as written', r1.p.join() === '0,0,-5' && near(r1.s[0], 0.5) && near(rec(ps, 0).s[2], 0.3));
ok('.splat: colour and alpha are the bytes', r1.c.join() === '0,255,0,128' && rec(ps, 0).c.join() === '255,0,0,255');
ok('.splat: rotation bytes → a unit quaternion (w x y z)',
  near(r1.q[1], 1, 1e-6) && near(r2.q[0], Math.SQRT1_2, 0.01) && near(r2.q[3], Math.SQRT1_2, 0.01)
  && near(Math.hypot(...r2.q), 1), r2.q.join());

const pp = XS.parsePly(plyBuf);
ok('PLY: three splats, nx/f_rest_* stepped over', pp.n === 3 && rec(pp, 0).p.join() === '0,0,-1' && rec(pp, 2).p.join() === '1,0,-3');
ok('PLY: scale is exp(scale_k)', near(rec(pp, 0).s[1], 0.2) && near(rec(pp, 1).s[0], 0.5));
ok('PLY: colour is 0.5 + C0·f_dc, alpha the sigmoid of opacity',
  rec(pp, 0).c.join() === '255,0,0,255' && rec(pp, 1).c.join() === '0,255,0,128' && rec(pp, 2).c[3] === 64, rec(pp, 1).c.join());
ok('PLY: rot_0..3 normalised', near(rec(pp, 1).q[1], 1) && near(Math.hypot(...rec(pp, 2).q), 1));
let threw = '';
try { XS.parsePly(new Uint8Array(Buffer.from('ply\nformat ascii 1.0\nelement vertex 1\nproperty float x\nend_header\n')).buffer); }
catch (e) { threw = e.message; }
ok('PLY: ascii is refused with a reason', /little-endian/.test(threw), threw);
try { threw = ''; XS.parsePly(plyBuf.slice(0, plyBuf.byteLength - 4)); } catch (e) { threw = e.message; }
ok('PLY: a file shorter than its header is refused', /shorter/.test(threw), threw);

// A HOSTILE HEADER: 64 KB of comments and no end. The header used to be
// rescanned with a regex at every newline — lines × length — so a file that
// never ends its header stalled the page for seconds before saying so. Read
// once, it is refused at once, twenty times over well inside a quarter second.
const hostile = new Uint8Array(Buffer.from('ply\ncomment end_headerz, not the end\n' + 'c\n'.repeat(40000))).buffer;
let hostileWhy = '', hostileAll = true;
const hostileT0 = Date.now();
for (let i = 0; i < 20; i++) {
  try { XS.parsePly(hostile); hostileAll = false; } catch (e) { hostileWhy = e.message; if (!/never ends/.test(e.message)) hostileAll = false; }
}
const hostileMs = Date.now() - hostileT0;
ok('PLY: a header that never ends is refused fast (20 parses of 64 KB under 250 ms)',
  hostileAll && /never ends/.test(hostileWhy) && hostileMs < 250, hostileMs + ' ms, ' + hostileWhy);
const crlfHead = Buffer.from(Buffer.from(plyBuf).toString('latin1').split('end_header\n')[0].replace(/\n/g, '\r\n') + 'end_header\r\n', 'latin1');
const crlfBuf = new Uint8Array(crlfHead.length + plyBuf.byteLength - (Buffer.from(plyBuf).indexOf('end_header\n') + 11));
crlfBuf.set(crlfHead); crlfBuf.set(new Uint8Array(plyBuf, Buffer.from(plyBuf).indexOf('end_header\n') + 11), crlfHead.length);
const pc = XS.parsePly(crlfBuf.buffer);
ok('PLY: a CRLF header reads as the LF one, its body found after \\r\\n',
  pc.n === 3 && rec(pc, 2).p.join() === '1,0,-3' && rec(pc, 1).c.join() === '0,255,0,128');

// -- the cap ---------------------------------------------------------------------------
const cut = XS.parseSplat(splatBuf, 2), cutp = XS.parsePly(plyBuf, 2);
ok('the cap truncates (n 2 of 3) and says it did, both formats',
  cut.n === 2 && cut.total === 3 && cut.truncated === true && cutp.n === 2 && cutp.truncated === true);
ok('the cap is 1.5M splats, less on a small device and without a worker',
  XS.CAP === 1500000 && XS.CAP_LOW_MEMORY < XS.CAP && XS.CAP_NO_WORKER < XS.CAP_LOW_MEMORY);

// -- the covariance ----------------------------------------------------------------------
let c = XS.cov3d([1, 2, 3], [1, 0, 0, 0]);
ok('Σ for scale (1,2,3), no rotation: diag(1, 4, 9)', [1, 0, 0, 4, 0, 9].every((v, i) => near(c[i], v)), c.join());
c = XS.cov3d([1, 2, 3], [Math.SQRT1_2, 0, 0, Math.SQRT1_2]);       // 90° about z: x→y, y→−x
ok('Σ turned 90° about z: diag(4, 1, 9)', [4, 0, 0, 1, 0, 9].every((v, i) => near(c[i], v)), c.join());
c = XS.cov3d([2, 1, 1], [Math.cos(Math.PI / 8), 0, 0, Math.sin(Math.PI / 8)]);  // 45° about z
// R = [[c,-s],[s,c]], c=s=√½: Σxx = 4c²+s² = 2.5, Σxy = 4cs − sc = 1.5, Σyy = 4s²+c² = 2.5
ok('Σ turned 45° about z, by hand: xx 2.5, xy 1.5, yy 2.5', near(c[0], 2.5) && near(c[1], 1.5) && near(c[3], 2.5) && near(c[5], 1), c.join());
const V0 = XS.viewMatrix([0, 0, 0], 0, 0);
const c2 = XS.cov2d(XS.cov3d([0.1, 0.1, 0.1], [1, 0, 0, 0]), V0, [0, 0, -2], 100, 100);
// J = diag(f/z) = 50: 2500 × 0.01 + 0.3
ok('2D: a 10 cm ball two metres ahead at f = 100 px is 25.3 px² round', near(c2[0], 25.3) && near(c2[1], 0) && near(c2[2], 25.3), c2.join());
const c3 = XS.cov2d(XS.cov3d([0.1, 0.1, 0.1], [1, 0, 0, 0]), V0, [0, 0, -4], 100, 100);
ok('and twice as far is a quarter the area (6.25 + 0.3)', near(c3[0], 6.55), c3.join());
ok('the vertex shader does the same sums (R from the quaternion, M = R·S, J·W, eigen axes)',
  /mat3 M = mat3\(R\[0\] \* a_scale\.x/.test(XS.VS) && /\(j0 \* W\) \* M/.test(XS.VS) && /\+ 0\.3/.test(XS.VS)
  && /B \* v_color\.rgb, B/.test(XS.FS));

// -- the camera -------------------------------------------------------------------------
const f0 = XS.forward(0, 0);
ok('the camera starts looking down −z', near(f0[0], 0) && near(f0[2], -1));
const vm0 = XS.viewMatrix([0, 0, 0], 0, 0);
ok('and the view at the origin is the identity', [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1].every((v, i) => near(vm0[i], v)), Array.from(vm0).join());
const vm1 = XS.viewMatrix([1, 2, 3], 0.7, 0.3), pt = [0.3, -1, 4];
const cam = [0, 1, 2].map((r) => vm1[r] * pt[0] + vm1[4 + r] * pt[1] + vm1[8 + r] * pt[2] + vm1[12 + r]);
const fw = XS.forward(0.7, 0.3), rel = [pt[0] - 1, pt[1] - 2, pt[2] - 3];
ok('the view matrix puts depth along forward on −z', near(-cam[2], rel[0] * fw[0] + rel[1] * fw[1] + rel[2] * fw[2]));
ok('the 3 m rule: a step to 4 m comes back to 3, inside is untouched',
  XS.clampEye([4, 0, 0]).join() === '3,0,0' && near(Math.hypot(...XS.clampEye([3, 3, 3])), 3)
  && XS.clampEye([1, 1, 1]).join() === '1,1,1' && XS.RADIUS === 3);

// -- the sort, in the worker as shipped ------------------------------------------------
(async function () {
  const w = new FakeWorker('xeric-splat-worker.js');
  const got = [];
  w.onmessage = (e) => got.push(e.data);
  w.postMessage({ type: 'pos', pos: new Float32Array([0, 0, -1, 0, 0, -5, 1, 0, -3]), n: 3 });
  w.postMessage({ type: 'sort', eye: [0, 0, 0], fwd: [0, 0, -1] });
  await tick(20);
  ok('worker: depths 1, 5, 3 sort back to front as [1, 2, 0]',
    got.length === 1 && got[0].type === 'order' && Array.from(got[0].order).join() === '1,2,0', got.map((g) => Array.from(g.order || [])).join('|'));
  ok('worker: the order comes back as a transferable', Array.isArray(w.backTransfer) && w.backTransfer[0] === got[0].order.buffer);
  w.postMessage({ type: 'sort', eye: [0, 0, 0], fwd: [0, 0, 1] });
  w.postMessage({ type: 'sort', eye: [0, 0, 0], fwd: [0, 0, -1] });
  w.postMessage({ type: 'sort', eye: [0, 0, -6], fwd: [0, 0, 1] });   // turned round, behind the far one
  await tick(20);
  ok('worker: three sorts sent at once are answered once, for the last', got.length === 2
    && Array.from(got[1].order).join() === '0,2,1', got.length + ' ' + (got[1] ? Array.from(got[1].order).join() : ''));
  const sb = XS.depthSort(new Float32Array([0, 0, -1, 0, 0, -5, 1, 0, -3]), 3, [0, 0, 0], [0, 0, -1]);
  ok('the main thread\'s sort (no Worker) gives the worker\'s answer', Array.from(sb).join() === '1,2,0');
  const N = 5000, P = new Float32Array(N * 3);
  for (let i = 0; i < N * 3; i++) P[i] = Math.sin(i * 12.9898) * 3;
  const big = vm.runInContext('self.depthSort', w.ctx)(P, N, [0, 0, 0], [0, 0, -1]);
  let mono = true;
  for (let i = 1; i < N; i++) if (-P[big[i] * 3 + 2] > -P[big[i - 1] * 3 + 2] + 6 / 65535 * 1.01) { mono = false; break; }
  ok('worker: 5,000 random splats come back far to near, within one 16-bit bucket', mono && new Set(big).size === N);

  // -- mounting: WebGL 2, the real worker, the fixture over fetch -------------------
  respond = () => ({ ok: true, status: 200, headers: { get: () => String(splatBuf.byteLength) },
                     body: null, arrayBuffer: () => Promise.resolve(splatBuf.slice(0)) });
  let k = canvas('webgl2'), ready = null, err = null;
  let A = XS.mount(k.cv, 'scene.php?w=t&k=bluebird&f=' + 'a'.repeat(40), { onReady: (x) => { ready = x; }, onError: (x) => { err = x; } });
  ok('mount says loading first', /^loading 0%/.test(k.st.textContent), k.st.textContent);
  await tick(40);
  ok('WebGL 2: ready with three splats', ready && ready.n === 3 && !err, JSON.stringify(ready) + ' ' + err);
  ok('the bytes are asked of the route on the canvas, once', fetched.length === 1 && /^scene\.php\?w=t&k=bluebird/.test(fetched[0]));
  const wk = workers[workers.length - 1];
  ok('the worker is the same-origin hashed file the page named, never a blob', wk.url === 'xeric-splat-worker.js?v=0123456789abcdef');
  ok('it is handed the positions once, transferred', wk.sent[0][0].type === 'pos' && wk.sent[0][1][0] === wk.sent[0][0].pos.buffer
    && wk.sent.filter((s) => s[0].type === 'pos').length === 1);
  const gl = k.cv.gl;
  const draws = gl.calls.filter((x) => x[0] === 'drawArraysInstanced');
  ok('it draws three instanced quads', draws.length >= 1 && draws[draws.length - 1][1][3] === 3, JSON.stringify(draws));
  ok('back to front, premultiplied: blendFunc(ONE, ONE_MINUS_SRC_ALPHA)', gl.calls.some((x) => x[0] === 'blendFunc' && x[1][0] === gl.ONE && x[1][1] === gl.ONE_MINUS_SRC_ALPHA));
  ok('the sorted order went to the GPU: [1, 2, 0] from the start', A.state.order && Array.from(A.state.order).join() === '1,2,0'
    && gl.calls.some((x) => x[0] === 'bufferSubData'));
  ok('device-pixel-ratio aware: 400×300 css at dpr 2 is 800×600', k.cv.width === 800 && k.cv.height === 600, k.cv.width + 'x' + k.cv.height);
  const framesAt = A.state.frames;
  await tick(30);
  ok('no input, no frames', A.state.frames === framesAt);
  k.cv.fire('pointerdown', { pointerId: 1, clientX: 100, clientY: 100 });
  k.cv.fire('pointermove', { pointerId: 1, clientX: 160, clientY: 100 });
  await tick(30);
  ok('a drag turns you (yaw, like the panorama) and draws', A.state.yaw > 0 && A.state.frames > framesAt, A.state.yaw);
  k.cv.fire('pointerup', { pointerId: 1 });
  const sortsBefore = wk.sent.filter((s) => s[0].type === 'sort').length;
  for (let i = 0; i < 80; i++) k.cv.fire('keydown', { key: 'w' });
  await tick(40);
  ok('W walks forward, and eighty steps stop at the 3 m sphere', near(Math.hypot(...A.state.eye), 3, 1e-6), A.state.eye.join());
  const sortsAfter = wk.sent.filter((s) => s[0].type === 'sort').length;
  ok('eighty steps are not eighty sorts: one in flight, the rest coalesced', sortsAfter - sortsBefore < 20, sortsAfter - sortsBefore);
  const eye0 = A.state.eye.slice();
  k.cv.fire('pointerdown', { pointerId: 1, clientX: 100, clientY: 200 });
  k.cv.fire('pointerdown', { pointerId: 2, clientX: 200, clientY: 200 });
  k.cv.fire('pointermove', { pointerId: 1, clientX: 100, clientY: 260 });
  ok('two fingers walk instead of look', Math.hypot(eye0[0] - A.state.eye[0], eye0[2] - A.state.eye[2]) > 0.01);
  A.destroy();
  ok('destroy ends the worker and hands the context back', wk.terminated && gl.calls.some((x) => x[0] === 'loseContext'));

  // -- through XericView, as play.php mounts it ---------------------------------------
  vm.runInThisContext(fs.readFileSync(XV_SRC, 'utf8'), { filename: 'xeric-view.js' });
  const XV = globalThis.XericView({ world: 't' });
  k = canvas('webgl2'); k.cv.dataset.src = 'scene.php?w=t&k=x&f=' + 'b'.repeat(40); ready = null;
  const lv = XV.mount({ kind: 'splat', canvas: k.cv, url: k.cv.dataset.src, onReady: (x) => { ready = x; } });
  await tick(40);
  ok('XericView mount({kind:"splat"}) delegates to XericSplat.mount', lv && lv.state && ready && ready.n === 3 && XV.lookView === lv);
  XV.unmount();

  // -- WebGL 1 with instancing --------------------------------------------------------
  k = canvas('webgl1'); ready = null;
  XS.mount(k.cv, 'x', { onReady: (x) => { ready = x; } });
  await tick(40);
  ok('WebGL 1: drawn with ANGLE_instanced_arrays', ready && k.cv.gl.calls.some((x) => x[0] === 'drawArraysInstancedANGLE' && x[1][3] === 3));

  // -- no WebGL: the message, and the download left standing ---------------------------
  for (const mode of ['none', 'webgl1-no-instancing']) {
    k = canvas(mode); err = null; const nf = fetched.length;
    XS.mount(k.cv, 'x', { onError: (x) => { err = x; } });
    ok('no usable WebGL (' + mode + '): a sentence, the canvas gone, the download kept, nothing fetched',
      /WebGL/.test(err || '') && /WebGL/.test(k.st.textContent) && k.cv.parentNode === null
      && k.dl.parentNode === k.box && fetched.length === nf, k.st.textContent);
  }

  // -- the cap, as the page says it ---------------------------------------------------
  k = canvas('webgl2'); ready = null;
  XS.mount(k.cv, 'x', { cap: 2, onReady: (x) => { ready = x; } });
  await tick(40);
  ok('cut to the cap, the page says how many of how many', ready && ready.n === 2 && ready.truncated
    && /showing 2 of 3 splats/.test(k.st.textContent), k.st.textContent);

  // -- no Worker: sorted here --------------------------------------------------------
  k = canvas('webgl2'); ready = null; const nw = workers.length;
  let B = XS.mount(k.cv, 'x', { Worker: null, onReady: (x) => { ready = x; } });
  await tick(40);
  ok('no Worker: sorted on the main thread, no worker made', ready && workers.length === nw && Array.from(B.state.order).join() === '1,2,0');
  B.destroy();

  // -- a worker that dies after the room loaded: the main thread's cap --------------
  // The sort comes back to this thread, which sorts at most CAP_NO_WORKER; a
  // room kept at the worker's cap would stall every turn. Cut, and said.
  const bigN = XS.CAP_NO_WORKER + 1, bigBuf = new ArrayBuffer(bigN * 32), bigF = new Float32Array(bigBuf), bigU = new Uint8Array(bigBuf);
  for (let i = 0; i < bigN; i++) {
    bigF[i * 8] = (i % 100) / 50 - 1; bigF[i * 8 + 2] = -1 - (i % 7); bigF[i * 8 + 3] = bigF[i * 8 + 4] = bigF[i * 8 + 5] = 0.01;
    bigU[i * 32 + 27] = 255; bigU[i * 32 + 28] = 255; bigU[i * 32 + 29] = bigU[i * 32 + 30] = bigU[i * 32 + 31] = 128;
  }
  class DyingWorker {
    constructor() { this.terminated = false; workers.push(this); }
    postMessage(m) { if (m.type === 'pos') setTimeout(() => { if (this.onerror) this.onerror({ message: 'gone' }); }, 0); }
    terminate() { this.terminated = true; }
  }
  const respondWas = respond;
  respond = () => ({ ok: true, status: 200, headers: { get: () => String(bigBuf.byteLength) }, body: null,
                     arrayBuffer: () => Promise.resolve(bigBuf.slice(0)) });
  k = canvas('webgl2'); ready = null;
  const D = XS.mount(k.cv, 'x', { Worker: DyingWorker, onReady: (x) => { ready = x; } });
  await tick(80);
  const drawn = k.cv.gl.calls.filter((x) => x[0] === 'drawArraysInstanced').map((x) => x[1][3]);
  ok('a worker that dies after load: cut to CAP_NO_WORKER, sorted here, and the page says so',
    ready && ready.n === bigN && D.state.n === XS.CAP_NO_WORKER && D.state.truncated
    && D.state.order && D.state.order.length === XS.CAP_NO_WORKER
    && drawn.length > 0 && drawn[drawn.length - 1] === XS.CAP_NO_WORKER
    && /showing 250,000 of 250,001 splats/.test(k.st.textContent), D.state.n + ' / ' + drawn.slice(-1) + ' / ' + k.st.textContent);
  D.destroy();
  respond = respondWas;

  // -- loading n%, the memory guard, a bad file, a 404 --------------------------------
  const half = splatBuf.byteLength / 2;
  respond = () => {
    const chunks = [new Uint8Array(splatBuf.slice(0, half)), new Uint8Array(splatBuf.slice(half))];
    return { ok: true, status: 200, headers: { get: () => String(splatBuf.byteLength) },
             body: { getReader: () => ({ read: () => Promise.resolve(chunks.length ? { done: false, value: chunks.shift() } : { done: true }) }) } };
  };
  const seen = []; k = canvas('webgl2'); ready = null;
  XS.mount(k.cv, 'x', { onProgress: (p) => seen.push(Math.round(p * 100)), onReady: (x) => { ready = x; } });
  await tick(40);
  ok('streamed: loading 50%, 99% while the last bytes are read, then 100%, then ready', seen.join() === '50,99,100' && ready && ready.n === 3, seen.join());
  respond = () => ({ ok: true, status: 200, headers: { get: () => String(XS.MAX_BYTES + 1) }, body: null,
                     arrayBuffer: () => Promise.reject(new Error('should not be read')) });
  k = canvas('webgl2'); err = null;
  XS.mount(k.cv, 'x', { onError: (x) => { err = x; } });
  await tick(20);
  ok('the memory guard refuses a file too big before reading it', /too big/.test(err || ''), err);
  respond = () => ({ ok: true, status: 200, headers: { get: () => '4' }, body: null,
                     arrayBuffer: () => Promise.resolve(gz.slice(0)) });
  k = canvas('webgl2'); err = null;
  XS.mount(k.cv, 'x', { onError: (x) => { err = x; } });
  await tick(20);
  ok('an SPZ errors to the download with its name', /SPZ/.test(err || '') && k.cv.parentNode === null && /download/.test(k.st.textContent), err);
  respond = () => ({ ok: false, status: 404, headers: { get: () => '0' } });
  k = canvas('webgl2'); err = null;
  XS.mount(k.cv, 'x', { onError: (x) => { err = x; } });
  await tick(20);
  ok('a 404 errors to the download', /404/.test(err || ''), err);

  // -- the PHP converter's PLY reads as the .splat it came from -------------------------
  if (FIX_SPLAT && FIX_PLY) {
    const toAB = (b) => b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength);
    const a = XS.parse(toAB(fs.readFileSync(FIX_SPLAT))), b = XS.parse(toAB(fs.readFileSync(FIX_PLY)));
    let same = a.n === b.n && a.n === 3, why = '';
    for (let i = 0; same && i < a.n; i++) {
      const x = rec(a, i), y = rec(b, i);
      same = x.p.every((v, j) => near(v, y.p[j])) && x.s.every((v, j) => near(v, y.s[j], 1e-4))
        && x.q.every((v, j) => near(v, y.q[j], 1e-4)) && x.c.every((v, j) => Math.abs(v - y.c[j]) <= 1);
      if (!same) why = JSON.stringify([x, y]);
    }
    ok('tools/splat-to-ply.php: its PLY of the fixture reads back as the fixture', same, why);
  }

  results.forEach((r) => console.log('R\t' + (r[1] ? 1 : 0) + '\t' + r[0] + '\t' + r[2].replace(/[\t\n]/g, ' ')));
  process.exit(0);
})().catch((e) => {
  results.forEach((r) => console.log('R\t' + (r[1] ? 1 : 0) + '\t' + r[0] + '\t' + r[2].replace(/[\t\n]/g, ' ')));
  console.log('R\t0\tthe harness ran to the end\t' + String(e && e.stack || e).replace(/[\t\n]/g, ' '));
  process.exit(0);
});
