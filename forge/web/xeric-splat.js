// xeric-splat.js — walking inside a gaussian splat, in our own WebGL.
//
// A splat is a room as a cloud of soft coloured ellipsoids: each one a centre,
// three radii, a rotation, a colour and an opacity. To draw one you project its
// 3D covariance (the ellipsoid, R·S·Sᵀ·Rᵀ) through the camera into a 2D
// covariance on the screen, which is an ellipse; you draw a quad big enough to
// hold it and let the fragment shader fade a gaussian across it. Drawn back to
// front with premultiplied colour, a million of them are the room. The
// back-to-front is the expensive part, so it is not done here: the depth sort
// runs in xeric-splat-worker.js, a file from this origin (worker-src 'self'),
// never a blob and never WebAssembly — the line the CSP would otherwise have to
// cross for Spark or Babylon (docs/FUTURE.md, Appendix D).
//
// LOADED STATICALLY, STARTED LAZILY. play.php loads this file next to
// xeric-view.js with the same nonce and content hash — under a nonce-only CSP
// with no 'strict-dynamic' a script cannot add another script — and nothing
// here runs until XericView's mount({kind:'splat'}) calls XericSplat.mount.
// A page with no splat on it pays one cached file and nothing else.
//
// TWO FORMATS, read in the browser as they arrive: binary PLY (the standard
// 3DGS header; f_rest_* and anything else skipped by offset) and the compact
// .splat (32 bytes a splat). SPZ is gzip and SOG is a zip of WebP — both need
// a decoder this file would have to carry, and are refused with a reason; the
// page's download link is the answer for them (tools/splat-to-ply.php notes
// the same).
//
// FRAMES ONLY ON DEMAND, like the panorama: one draw per drag, key or resize,
// and one more when a sort comes back. A still room costs nothing.
//
// forge/web/tests/splat-test.php runs this file, as served, against a fake
// WebGL2 and a fake Worker that runs the real worker file.
(function (root) {
  'use strict';

  var CAP = 1500000;                 // splats, at most; the rest are cut, and the page says so
  var CAP_LOW_MEMORY = 750000;       // a device that says it has 4 GB or less (a Quest, a phone)
  var CAP_NO_WORKER = 250000;        // sorting on the main thread: fewer, or the page stalls
  var MAX_BYTES = 400 * 1024 * 1024; // what we will hold in memory to parse
  var LOAD_MS = 180000;              // the time guard: a room that has not arrived in 3 minutes will not
  var RADIUS = 3;                    // metres from where you came in
  var SH_C0 = 0.28209479177387814;   // the zeroth spherical harmonic: f_dc → colour

  // -- reading the bytes ---------------------------------------------------------

  /** What a buffer is, by its first bytes: 'ply', 'splat', or why not. */
  function sniff(buf) {
    var u = new Uint8Array(buf, 0, Math.min(4, buf.byteLength));
    if (u.length >= 4 && u[0] === 0x70 && u[1] === 0x6c && u[2] === 0x79 && (u[3] === 0x0a || u[3] === 0x0d)) return { kind: 'ply' };
    if (u.length >= 2 && u[0] === 0x1f && u[1] === 0x8b) return { kind: null, why: 'this room is an SPZ (compressed) splat, which this viewer does not read yet' };
    if (u.length >= 2 && u[0] === 0x50 && u[1] === 0x4b) return { kind: null, why: 'this room is a SOG (zipped) splat, which this viewer does not read yet' };
    if (buf.byteLength > 0 && buf.byteLength % 32 === 0) return { kind: 'splat' };
    return { kind: null, why: 'these bytes are not a splat this viewer can read' };
  }

  function alloc(n) {
    // One interleaved record per splat, 48 bytes, so a sort can reorder the
    // whole set as twelve 32-bit words a splat: centre f32×3, scale f32×3,
    // rotation f32×4 (w x y z, unit), colour u8×4 (premultiplied later, in
    // the shader). The views share the one buffer.
    var buf = new ArrayBuffer(n * 48);
    return { n: n, buf: buf, f: new Float32Array(buf), u8: new Uint8Array(buf), pos: new Float32Array(n * 3) };
  }

  function put(s, i, x, y, z, sx, sy, sz, qw, qx, qy, qz, r, g, b, a) {
    var o = i * 12, f = s.f;
    f[o] = x; f[o + 1] = y; f[o + 2] = z;
    f[o + 3] = sx; f[o + 4] = sy; f[o + 5] = sz;
    var l = Math.sqrt(qw * qw + qx * qx + qy * qy + qz * qz) || 1;
    f[o + 6] = qw / l; f[o + 7] = qx / l; f[o + 8] = qy / l; f[o + 9] = qz / l;
    var c = o * 4 + 40;
    s.u8[c] = r; s.u8[c + 1] = g; s.u8[c + 2] = b; s.u8[c + 3] = a;
    s.pos[i * 3] = x; s.pos[i * 3 + 1] = y; s.pos[i * 3 + 2] = z;
  }

  function clamp255(v) { v = Math.round(v); return v < 0 ? 0 : v > 255 ? 255 : v; }

  /**
   * Binary PLY, little-endian, one `vertex` element first. Every property is
   * read by its offset, so f_rest_*, normals and anything a trainer adds are
   * stepped over without being named. Colour is f_dc (or red/green/blue).
   */
  function parsePly(buf, cap) {
    var head = new Uint8Array(buf, 0, Math.min(buf.byteLength, 65536));
    var text = '', end = -1;
    for (var i = 0; i < head.length; i++) {
      text += String.fromCharCode(head[i]);
      if (head[i] === 0x0a && /end_header\r?\n$/.test(text)) { end = i + 1; break; }
    }
    if (end < 0) throw new Error('the PLY header never ends');
    var lines = text.split(/\r?\n/), n = -1, props = [], inVertex = false, fmt = '';
    var SIZE = { char: 1, uchar: 1, int8: 1, uint8: 1, short: 2, ushort: 2, int16: 2, uint16: 2,
                 int: 4, uint: 4, int32: 4, uint32: 4, float: 4, float32: 4, double: 8, float64: 8 };
    lines.forEach(function (ln) {
      var w = ln.trim().split(/\s+/);
      if (w[0] === 'format') fmt = w[1];
      else if (w[0] === 'element') { inVertex = w[1] === 'vertex' && n < 0; if (inVertex) n = parseInt(w[2], 10); }
      else if (w[0] === 'property' && inVertex) {
        if (w[1] === 'list') throw new Error('a list property in the vertex element');
        if (!SIZE[w[1]]) throw new Error('a PLY type this viewer does not know: ' + w[1]);
        props.push({ type: w[1], name: w[2] });
      }
    });
    if (fmt !== 'binary_little_endian') throw new Error('only binary little-endian PLY is read here');
    if (!(n >= 0)) throw new Error('the PLY has no vertex element');
    var at = {}, stride = 0;
    props.forEach(function (p) { at[p.name] = { off: stride, type: p.type }; stride += SIZE[p.type]; });
    ['x', 'y', 'z', 'opacity', 'scale_0', 'scale_1', 'scale_2', 'rot_0', 'rot_1', 'rot_2', 'rot_3'].forEach(function (k) {
      if (!at[k]) throw new Error('the PLY has no ' + k + ', so it is not a gaussian splat');
    });
    var dc = !!(at.f_dc_0 && at.f_dc_1 && at.f_dc_2), rgb = !!(at.red && at.green && at.blue);
    if (!dc && !rgb) throw new Error('the PLY has no colour (f_dc_0..2)');
    if (end + n * stride > buf.byteLength) throw new Error('the PLY is shorter than its header says');
    var total = n, keep = Math.min(n, cap || CAP);
    var dv = new DataView(buf, end), s = alloc(keep);
    function rd(base, p) {
      var o = base + p.off;
      switch (p.type) {
        case 'float': case 'float32': return dv.getFloat32(o, true);
        case 'double': case 'float64': return dv.getFloat64(o, true);
        case 'uchar': case 'uint8': return dv.getUint8(o);
        case 'char': case 'int8': return dv.getInt8(o);
        case 'ushort': case 'uint16': return dv.getUint16(o, true);
        case 'short': case 'int16': return dv.getInt16(o, true);
        case 'uint': case 'uint32': return dv.getUint32(o, true);
        default: return dv.getInt32(o, true);
      }
    }
    for (var k = 0; k < keep; k++) {
      var b = k * stride, r, g, bl;
      if (dc) {
        r = clamp255((0.5 + SH_C0 * rd(b, at.f_dc_0)) * 255);
        g = clamp255((0.5 + SH_C0 * rd(b, at.f_dc_1)) * 255);
        bl = clamp255((0.5 + SH_C0 * rd(b, at.f_dc_2)) * 255);
      } else { r = rd(b, at.red); g = rd(b, at.green); bl = rd(b, at.blue); }
      var a = clamp255(255 / (1 + Math.exp(-rd(b, at.opacity))));      // stored as a logit
      put(s, k, rd(b, at.x), rd(b, at.y), rd(b, at.z),
          Math.exp(rd(b, at.scale_0)), Math.exp(rd(b, at.scale_1)), Math.exp(rd(b, at.scale_2)),  // stored as logs
          rd(b, at.rot_0), rd(b, at.rot_1), rd(b, at.rot_2), rd(b, at.rot_3), r, g, bl, a);
    }
    s.total = total; s.truncated = total > keep;
    return s;
  }

  /** The compact .splat: position f32×3, scale f32×3 (linear), colour u8×4, rotation u8×4 ((b-128)/128, w x y z). */
  function parseSplat(buf, cap) {
    if (buf.byteLength % 32 !== 0) throw new Error('a .splat is a whole number of 32-byte records');
    var total = buf.byteLength / 32, keep = Math.min(total, cap || CAP);
    var f = new Float32Array(buf, 0, keep * 8), u = new Uint8Array(buf), s = alloc(keep);
    for (var i = 0; i < keep; i++) {
      var o = i * 8, c = i * 32 + 24;
      put(s, i, f[o], f[o + 1], f[o + 2], f[o + 3], f[o + 4], f[o + 5],
          (u[c + 4] - 128) / 128, (u[c + 5] - 128) / 128, (u[c + 6] - 128) / 128, (u[c + 7] - 128) / 128,
          u[c], u[c + 1], u[c + 2], u[c + 3]);
    }
    s.total = total; s.truncated = total > keep;
    return s;
  }

  /** Either format, by sniffing; throws with a sentence a person can read. */
  function parse(buf, cap) {
    var k = sniff(buf);
    if (!k.kind) throw new Error(k.why);
    return k.kind === 'ply' ? parsePly(buf, cap) : parseSplat(buf, cap);
  }

  // -- the maths the shader does, in JavaScript, so it can be tested -----------

  /** Σ = R·S·Sᵀ·Rᵀ from scale (x y z) and a unit quaternion (w x y z), as [xx, xy, xz, yy, yz, zz]. */
  function cov3d(s, q) {
    var w = q[0], x = q[1], y = q[2], z = q[3];
    var R = [1 - 2 * (y * y + z * z), 2 * (x * y - w * z), 2 * (x * z + w * y),
             2 * (x * y + w * z), 1 - 2 * (x * x + z * z), 2 * (y * z - w * x),
             2 * (x * z - w * y), 2 * (y * z + w * x), 1 - 2 * (x * x + y * y)];   // row-major
    var M = [];                                                                       // M = R·S
    for (var r = 0; r < 3; r++) for (var c = 0; c < 3; c++) M[r * 3 + c] = R[r * 3 + c] * s[c];
    function dot(a, b) { return M[a * 3] * M[b * 3] + M[a * 3 + 1] * M[b * 3 + 1] + M[a * 3 + 2] * M[b * 3 + 2]; }
    return [dot(0, 0), dot(0, 1), dot(0, 2), dot(1, 1), dot(1, 2), dot(2, 2)];
  }

  /**
   * The 2D covariance on screen, in pixels², of a splat whose centre is at
   * camera-space c (the camera looks down −z) — J·W·Σ·Wᵀ·Jᵀ with W the view's
   * rotation (column-major 4×4 view), plus the usual 0.3 px² so a splat is
   * never thinner than a pixel. Returns [a, b, c] for [[a b] [b c]].
   */
  function cov2d(cov, view, cam, fx, fy) {
    var z = -cam[2];
    var j0 = [fx / z, 0, fx * cam[0] / (z * z)], j1 = [0, fy / z, fy * cam[1] / (z * z)];
    // T = J·W: row i of T is jᵢᵀ·W, W[r][c] = view[c*4 + r]
    function row(j) { var t = []; for (var c = 0; c < 3; c++) t[c] = j[0] * view[c * 4] + j[1] * view[c * 4 + 1] + j[2] * view[c * 4 + 2]; return t; }
    var t0 = row(j0), t1 = row(j1);
    var S = [[cov[0], cov[1], cov[2]], [cov[1], cov[3], cov[4]], [cov[2], cov[4], cov[5]]];
    function q(a, b) { var s = 0; for (var r = 0; r < 3; r++) for (var c = 0; c < 3; c++) s += a[r] * S[r][c] * b[c]; return s; }
    return [q(t0, t0) + 0.3, q(t0, t1), q(t1, t1) + 0.3];
  }

  /** Where you are facing: yaw 0 is down −z, positive yaw turns left (the panorama's own sense). */
  function forward(yaw, pitch) {
    return [-Math.sin(yaw) * Math.cos(pitch), Math.sin(pitch), -Math.cos(yaw) * Math.cos(pitch)];
  }

  /** A column-major view matrix for an eye and a yaw/pitch, y up. */
  function viewMatrix(eye, yaw, pitch) {
    var f = forward(yaw, pitch);
    var r = [Math.cos(yaw), 0, -Math.sin(yaw)];
    var u = [r[1] * f[2] - r[2] * f[1], r[2] * f[0] - r[0] * f[2], r[0] * f[1] - r[1] * f[0]];
    var d = function (a) { return a[0] * eye[0] + a[1] * eye[1] + a[2] * eye[2]; };
    return new Float32Array([r[0], u[0], -f[0], 0, r[1], u[1], -f[1], 0, r[2], u[2], -f[2], 0, -d(r), -d(u), d(f), 1]);
  }

  /**
   * THE THREE-METRE RULE. You came into this room through its door, and you
   * may walk about in it — three metres any way from where you came in — but
   * not out of it: the eye is pulled back onto that sphere. Leaving the room
   * is a trip, which costs the world minutes and is the engine's to decide
   * (the map, the travel verb), never a camera's. The viewer is a room, not
   * a world.
   */
  function clampEye(p, radius) {
    var R = radius === undefined ? RADIUS : radius;
    var l = Math.sqrt(p[0] * p[0] + p[1] * p[1] + p[2] * p[2]);
    if (l <= R) return p;
    return [p[0] * R / l, p[1] * R / l, p[2] * R / l];
  }

  /**
   * The sort, for a browser with no Worker (the worker file has its own copy,
   * which the test holds to the same answer): back to front along `fwd`, a
   * counting sort on depth quantised to 16 bits. O(n + 65536).
   */
  function depthSort(pos, n, eye, fwd) {
    var depth = new Float32Array(n), lo = Infinity, hi = -Infinity, i;
    for (i = 0; i < n; i++) {
      var d = (pos[i * 3] - eye[0]) * fwd[0] + (pos[i * 3 + 1] - eye[1]) * fwd[1] + (pos[i * 3 + 2] - eye[2]) * fwd[2];
      depth[i] = d; if (d < lo) lo = d; if (d > hi) hi = d;
    }
    var k = 65535 / ((hi - lo) || 1), keys = new Uint16Array(n), count = new Uint32Array(65536);
    for (i = 0; i < n; i++) { var b = ((hi - depth[i]) * k) | 0; keys[i] = b; count[b]++; }   // farthest is bucket 0
    for (var s = 0, j = 0; j < 65536; j++) { var c = count[j]; count[j] = s; s += c; }
    var order = new Uint32Array(n);
    for (i = 0; i < n; i++) order[count[keys[i]]++] = i;
    return order;
  }

  // -- the shaders ---------------------------------------------------------------
  // GLSL ES 1.00, which WebGL 2 compiles too: one pair of shaders for both.
  // a_quad runs −2..2, so the quad reaches 2√2 standard deviations; the axes
  // are √(2λ) so that exp(−|v|²) in the fragment shader is the gaussian.
  var VS = [
    'precision highp float;',
    'attribute vec2 a_quad; attribute vec3 a_center; attribute vec3 a_scale; attribute vec4 a_rot; attribute vec4 a_color;',
    'uniform mat4 u_view; uniform vec2 u_focal; uniform vec2 u_viewport;',
    'varying vec4 v_color; varying vec2 v_pos;',
    'void main() {',
    '  vec4 cam = u_view * vec4(a_center, 1.0);',
    '  float z = -cam.z;',
    '  vec2 c = vec2(u_focal.x * cam.x / z, u_focal.y * cam.y / z) / (0.5 * u_viewport);',
    '  if (z < 0.05 || abs(c.x) > 1.3 || abs(c.y) > 1.3) { gl_Position = vec4(0.0, 0.0, 2.0, 1.0); return; }',
    '  float w = a_rot.x, x = a_rot.y, y = a_rot.z, q = a_rot.w;',
    '  mat3 R = mat3(1.0 - 2.0*(y*y + q*q), 2.0*(x*y + w*q), 2.0*(x*q - w*y),',   // column-major
    '                2.0*(x*y - w*q), 1.0 - 2.0*(x*x + q*q), 2.0*(y*q + w*x),',
    '                2.0*(x*q + w*y), 2.0*(y*q - w*x), 1.0 - 2.0*(x*x + y*y));',
    '  mat3 M = mat3(R[0] * a_scale.x, R[1] * a_scale.y, R[2] * a_scale.z);',          // Σ = M·Mᵀ
    '  mat3 W = mat3(u_view[0].xyz, u_view[1].xyz, u_view[2].xyz);',
    '  vec3 j0 = vec3(u_focal.x / z, 0.0, u_focal.x * cam.x / (z * z));',
    '  vec3 j1 = vec3(0.0, u_focal.y / z, u_focal.y * cam.y / (z * z));',
    '  vec3 p = (j0 * W) * M, r = (j1 * W) * M;',                                        // Mᵀ·Wᵀ·jᵢ
    '  float a = dot(p, p) + 0.3, b = dot(p, r), d = dot(r, r) + 0.3;',
    '  float mid = 0.5 * (a + d), rad = length(vec2(0.5 * (a - d), b));',
    '  float l1 = mid + rad, l2 = max(mid - rad, 0.1);',
    '  vec2 e = abs(b) < 1e-7 ? (a >= d ? vec2(1.0, 0.0) : vec2(0.0, 1.0)) : normalize(vec2(b, l1 - a));',
    '  vec2 ax1 = min(sqrt(2.0 * l1), 1024.0) * e, ax2 = min(sqrt(2.0 * l2), 1024.0) * vec2(e.y, -e.x);',
    '  v_color = a_color; v_pos = a_quad;',
    '  gl_Position = vec4(c + (a_quad.x * ax1 + a_quad.y * ax2) / (0.5 * u_viewport), 0.0, 1.0);',
    '}'].join('\n');
  var FS = [
    'precision mediump float;',
    'varying vec4 v_color; varying vec2 v_pos;',
    'void main() {',
    '  float A = -dot(v_pos, v_pos);',
    '  if (A < -4.0) discard;',
    '  float B = exp(A) * v_color.a;',
    '  gl_FragColor = vec4(B * v_color.rgb, B);',                                       // premultiplied
    '}'].join('\n');

  /** WebGL 2, else WebGL 1 with instancing; null when neither. */
  function context(cv) {
    var o = { antialias: false, premultipliedAlpha: true, alpha: false, preserveDrawingBuffer: false };
    var gl = null;
    try { gl = cv.getContext('webgl2', o); } catch (e) { gl = null; }
    if (gl) return {
      gl: gl, two: true,
      divisor: function (l, d) { gl.vertexAttribDivisor(l, d); },
      draw: function (n) { gl.drawArraysInstanced(gl.TRIANGLE_STRIP, 0, 4, n); }
    };
    try { gl = cv.getContext('webgl', o); } catch (e) { gl = null; }
    var ext = gl && gl.getExtension('ANGLE_instanced_arrays');
    if (!ext) return null;
    return {
      gl: gl, two: false,
      divisor: function (l, d) { ext.vertexAttribDivisorANGLE(l, d); },
      draw: function (n) { ext.drawArraysInstancedANGLE(gl.TRIANGLE_STRIP, 0, 4, n); }
    };
  }

  function program(gl) {
    var sh = function (type, text) {
      var s = gl.createShader(type);
      gl.shaderSource(s, text); gl.compileShader(s);
      if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) throw new Error('the splat shader did not compile: ' + gl.getShaderInfoLog(s));
      return s;
    };
    var p = gl.createProgram();
    gl.attachShader(p, sh(gl.VERTEX_SHADER, VS));
    gl.attachShader(p, sh(gl.FRAGMENT_SHADER, FS));
    ['a_quad', 'a_center', 'a_scale', 'a_rot', 'a_color'].forEach(function (n, i) { gl.bindAttribLocation(p, i, n); });
    gl.linkProgram(p);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) throw new Error('the splat shader did not link');
    return p;
  }

  // -- the viewer ------------------------------------------------------------------

  /**
   * Build the walk-inside on a <canvas class="looksplat">. url is the bytes
   * route (scene.php?w&k&f — served as an attachment; fetch does not care);
   * the worker's hashed address is on the canvas as data-worker. opts:
   * onReady({n, total, truncated}), onError(why), onProgress(0..1), and, for
   * the test, cap / Worker. Returns { destroy, state }.
   */
  function mount(cv, url, opts) {
    opts = opts || {};
    var box = cv.parentNode, dead = false, raf = 0, worker = null, ctl = null, timer = 0, on = [];
    var st = box && box.querySelector ? box.querySelector('.looksplatstate') : null;
    if (!st && box) { st = document.createElement('p'); st.className = 'looksplatstate'; st.setAttribute('role', 'status'); box.appendChild(st); }
    var api = { destroy: destroy, state: { phase: 'loading', n: 0, eye: [0, 0, 0], yaw: 0, pitch: 0 } };
    var S = api.state;
    function say(t) { if (st) { st.textContent = t; st.hidden = t === ''; } }

    function fail(why) {
      if (dead) return;
      S.phase = 'error'; S.why = why;
      say('This room cannot be walked in here (' + why + '). The download is below.');
      destroy();
      if (cv.parentNode) cv.remove();
      if (box && box.classList) box.classList.add('looksplatfailed');
      if (opts.onError) opts.onError(why);
    }

    var C = context(cv);
    if (!C) { fail('this browser has no WebGL'); return api; }
    var gl = C.gl, prog = null;
    try { prog = program(gl); } catch (e) { C = null; fail(e.message); return api; }

    var Wk = opts.Worker !== undefined ? opts.Worker : (typeof Worker === 'function' ? Worker : null);
    var cap = opts.cap || CAP;
    var mem = typeof navigator !== 'undefined' && navigator.deviceMemory;
    if (mem && mem <= 4) cap = Math.min(cap, CAP_LOW_MEMORY);
    if (!Wk) cap = Math.min(cap, CAP_NO_WORKER);

    say('loading 0%');
    var started = Date.now();
    ctl = typeof AbortController === 'function' ? new AbortController() : null;
    timer = setTimeout(function () { if (ctl) ctl.abort(); fail('the room took too long to arrive'); }, LOAD_MS);
    fetchBytes(url, ctl, function (p) { if (!dead) { say('loading ' + Math.round(p * 100) + '%'); if (opts.onProgress) opts.onProgress(p); } })
      .then(function (buf) {
        clearTimeout(timer);
        if (dead) return;
        var s;
        try { s = parse(buf, cap); } catch (e) { fail(e && e.name === 'RangeError' ? 'the room is too big for this device\'s memory' : e.message); return; }
        buf = null;
        start(s, started);
      }, function (e) { clearTimeout(timer); fail(e && e.message ? e.message : 'the room could not be fetched'); });

    var data = null, gpu = null, view = null, order = null, inflight = false, dirty = false, lastSort = null;
    var fx = 1, fy = 1, W = 1, H = 1;

    function start(s, t0) {
      data = s; S.n = s.n; S.total = s.total; S.truncated = s.truncated;
      // The record buffer on the GPU, and one quad every instance shares.
      var quad = gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, quad);
      gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-2, -2, 2, -2, -2, 2, 2, 2]), gl.STATIC_DRAW);
      gl.enableVertexAttribArray(0);
      gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
      C.divisor(0, 0);
      gpu = gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, gpu);
      gl.bufferData(gl.ARRAY_BUFFER, s.buf, gl.DYNAMIC_DRAW);
      [[1, 3, gl.FLOAT, false, 0], [2, 3, gl.FLOAT, false, 12], [3, 4, gl.FLOAT, false, 24], [4, 4, gl.UNSIGNED_BYTE, true, 40]]
        .forEach(function (a) {
          gl.enableVertexAttribArray(a[0]);
          gl.vertexAttribPointer(a[0], a[1], a[2], a[3], 48, a[4]);
          C.divisor(a[0], 1);
        });
      view = new Uint32Array(s.buf.slice(0));          // the records in file order, which the sort reorders from
      if (Wk) {
        try {
          worker = new Wk(cv.dataset && cv.dataset.worker ? cv.dataset.worker : 'xeric-splat-worker.js');
          worker.onmessage = function (e) { if (e.data && e.data.type === 'order') sorted(e.data.order); };
          // A worker that dies (a 404, a CSP that says no) hands the sort back
          // to this thread, which is why the positions are kept here too.
          worker.onerror = function () { worker = null; inflight = false; lastSort = null; sort(); };
          var p = new Float32Array(s.pos);
          worker.postMessage({ type: 'pos', pos: p, n: s.n }, [p.buffer]);
        } catch (e) { worker = null; }
      }
      data.posLocal = s.pos;
      S.phase = 'ready';
      say(s.truncated ? 'showing ' + s.n.toLocaleString('en') + ' of ' + s.total.toLocaleString('en')
                        + ' splats, the most this device is given' : '');
      if (opts.onReady) opts.onReady({ n: s.n, total: s.total, truncated: s.truncated, ms: Date.now() - t0 });
      sort();
      ask();
    }

    // THE SORT: one in flight at a time. A camera change while one is out
    // marks the order dirty, and the answer's arrival sends the next — so a
    // drag sends as many sorts as the worker can answer and no more.
    function sort() {
      if (dead || !data) return;
      var f = forward(S.yaw, S.pitch), e = S.eye;
      if (lastSort && f[0] * lastSort.f[0] + f[1] * lastSort.f[1] + f[2] * lastSort.f[2] > 0.99995
          && Math.abs(e[0] - lastSort.e[0]) + Math.abs(e[1] - lastSort.e[1]) + Math.abs(e[2] - lastSort.e[2]) < 0.005) return;
      if (inflight) { dirty = true; return; }
      lastSort = { f: f, e: e.slice() };
      S.sorting = true;
      if (worker) { inflight = true; worker.postMessage({ type: 'sort', eye: e.slice(), fwd: f }); return; }
      sorted(depthSort(data.posLocal, data.n, e, f));
    }
    function sorted(o) {
      inflight = false; S.sorting = false;
      if (dead || !data) return;
      order = o;
      // Reorder the records, twelve words a splat, and hand the GPU the lot.
      var out = new Uint32Array(data.buf), n = Math.min(o.length, data.n);
      for (var i = 0; i < n; i++) {
        var a = o[i] * 12, b = i * 12;
        out[b] = view[a]; out[b + 1] = view[a + 1]; out[b + 2] = view[a + 2]; out[b + 3] = view[a + 3];
        out[b + 4] = view[a + 4]; out[b + 5] = view[a + 5]; out[b + 6] = view[a + 6]; out[b + 7] = view[a + 7];
        out[b + 8] = view[a + 8]; out[b + 9] = view[a + 9]; out[b + 10] = view[a + 10]; out[b + 11] = view[a + 11];
      }
      gl.bindBuffer(gl.ARRAY_BUFFER, gpu);
      gl.bufferSubData(gl.ARRAY_BUFFER, 0, data.buf);
      S.order = o;
      if (dirty) { dirty = false; sort(); }
      ask();
    }

    function size() {
      var dpr = Math.min((typeof window !== 'undefined' && window.devicePixelRatio) || 1, 2);
      var w = Math.max(1, Math.round((cv.clientWidth || 300) * dpr)), h = Math.max(1, Math.round((cv.clientHeight || 150) * dpr));
      if (cv.width !== w || cv.height !== h) { cv.width = w; cv.height = h; }
      W = w; H = h;
      fy = H / (2 * Math.tan(FOV / 2)); fx = fy;
    }
    function draw() {
      if (dead || !data) return;
      size();
      gl.viewport(0, 0, W, H);
      gl.clearColor(0, 0, 0, 1);
      gl.clear(gl.COLOR_BUFFER_BIT);
      gl.disable(gl.DEPTH_TEST);
      gl.enable(gl.BLEND);
      gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);     // back to front, premultiplied
      gl.useProgram(prog);
      gl.uniformMatrix4fv(gl.getUniformLocation(prog, 'u_view'), false, viewMatrix(S.eye, S.yaw, S.pitch));
      gl.uniform2f(gl.getUniformLocation(prog, 'u_focal'), fx, fy);
      gl.uniform2f(gl.getUniformLocation(prog, 'u_viewport'), W, H);
      C.draw(data.n);
      S.frames = (S.frames || 0) + 1;
    }
    function ask() {
      if (dead || raf) return;
      raf = requestAnimationFrame(function () { raf = 0; draw(); });
    }

    // -- looking and walking ---------------------------------------------------------
    function turn(dx, dy) {
      S.yaw += dx; S.pitch = Math.max(-1.45, Math.min(1.45, S.pitch + dy));
      sort(); ask();
    }
    function walk(fwd, side) {
      // Along the floor, not along the gaze: looking down and pressing W
      // walks you forward, not into the ground.
      var cy = Math.cos(S.yaw), sy = Math.sin(S.yaw);
      S.eye = clampEye([S.eye[0] - sy * fwd + cy * side, S.eye[1], S.eye[2] - cy * fwd - sy * side]);
      sort(); ask();
    }
    api.turn = turn; api.walk = walk;

    var pts = {}, drag = null;
    function pcount() { return Object.keys(pts).length; }
    function mid() {
      var x = 0, y = 0, k = Object.keys(pts);
      k.forEach(function (id) { x += pts[id].x; y += pts[id].y; });
      return { x: x / k.length, y: y / k.length };
    }
    function listen(t, ev, fn, o) { t.addEventListener(ev, fn, o); on.push([t, ev, fn, o]); }
    listen(cv, 'pointerdown', function (e) {
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      try { cv.setPointerCapture(e.pointerId); } catch (x) {}
      drag = mid();
      if (cv.classList) cv.classList.add('grabbing');
    });
    listen(cv, 'pointermove', function (e) {
      if (!pts[e.pointerId] || !drag) return;
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      var m = mid(), dx = m.x - drag.x, dy = m.y - drag.y;
      drag = m;
      if (pcount() >= 2) {
        // Two fingers walk: up the screen is forward, sideways is a side step.
        var k = 4 / Math.max(1, cv.clientHeight || 1);
        walk(-dy * k, dx * k);
      } else {
        var t = FOV / Math.max(1, cv.clientHeight || 1);   // the room moves at the speed of the finger
        turn(dx * t, dy * t);
      }
    });
    function up(e) {
      delete pts[e.pointerId];
      drag = pcount() ? mid() : null;
      if (!drag && cv.classList) cv.classList.remove('grabbing');
    }
    listen(cv, 'pointerup', up);
    listen(cv, 'pointercancel', up);
    listen(cv, 'keydown', function (e) {
      var s = 0.1, k = e.key;
      if (k === 'w' || k === 'W' || k === 'ArrowUp') walk(s, 0);
      else if (k === 's' || k === 'S' || k === 'ArrowDown') walk(-s, 0);
      else if (k === 'a' || k === 'A') walk(0, -s);
      else if (k === 'd' || k === 'D') walk(0, s);
      else if (k === 'ArrowLeft') turn(0.08, 0);
      else if (k === 'ArrowRight') turn(-0.08, 0);
      else return;
      e.preventDefault();
    });
    if (typeof window !== 'undefined' && window.addEventListener) listen(window, 'resize', ask);

    function destroy() {
      if (dead) return;
      dead = true;
      clearTimeout(timer);
      if (raf && typeof cancelAnimationFrame === 'function') cancelAnimationFrame(raf);
      raf = 0;
      if (ctl) { try { ctl.abort(); } catch (e) {} }
      if (worker) { try { worker.terminate(); } catch (e) {} worker = null; }
      on.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
      on = [];
      data = null; view = null;
      if (gl) { var lc = gl.getExtension && gl.getExtension('WEBGL_lose_context'); if (lc) lc.loseContext(); }
    }
    return api;
  }
  var FOV = Math.PI / 3;              // vertical, sixty degrees: a person's, not a lens's

  /** The bytes, with progress when the response can be read as a stream, and the memory guard. */
  function fetchBytes(url, ctl, progress) {
    return fetch(url, ctl ? { signal: ctl.signal, credentials: 'same-origin' } : { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('the room\'s file is not there (' + r.status + ')');
      var len = parseInt((r.headers && r.headers.get && r.headers.get('Content-Length')) || '0', 10) || 0;
      if (len > MAX_BYTES) throw new Error('the room is too big for this viewer (' + Math.round(len / 1048576) + ' MB)');
      if (!r.body || !r.body.getReader || !len) return r.arrayBuffer().then(function (b) {
        if (b.byteLength > MAX_BYTES) throw new Error('the room is too big for this viewer');
        progress(1); return b;
      });
      var rd = r.body.getReader(), out = new Uint8Array(len), got = 0;
      function pump() {
        return rd.read().then(function (c) {
          if (c.done) { progress(1); return got === len ? out.buffer : out.slice(0, got).buffer; }
          if (got + c.value.length > len) {                     // the length lied: grow, within the guard
            if (got + c.value.length > MAX_BYTES) throw new Error('the room is too big for this viewer');
            var bigger = new Uint8Array(Math.max(got + c.value.length, len * 2));
            bigger.set(out.subarray(0, got)); out = bigger; len = bigger.length;
          }
          out.set(c.value, got); got += c.value.length;
          progress(Math.min(0.99, got / len));
          return pump();
        });
      }
      return pump();
    });
  }

  root.XericSplat = {
    mount: mount, parse: parse, parsePly: parsePly, parseSplat: parseSplat, sniff: sniff,
    cov3d: cov3d, cov2d: cov2d, forward: forward, viewMatrix: viewMatrix, clampEye: clampEye,
    depthSort: depthSort, context: context, VS: VS, FS: FS,
    CAP: CAP, CAP_LOW_MEMORY: CAP_LOW_MEMORY, CAP_NO_WORKER: CAP_NO_WORKER, RADIUS: RADIUS, MAX_BYTES: MAX_BYTES
  };
})(typeof window !== 'undefined' ? window : this);
