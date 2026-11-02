// xeric-view.js — the viewer, one static file for every page that shows a room.
//
// The look-around (WebGL panorama, the 2D strip under it, the plain image
// under that), the people's tags at their bearings, step inside (WebXR), look
// with the phone (device orientation), the day strip, the speaker, and hold to
// talk. It lived inline in play.php; it is here so the room page's strip and
// the stand page can load the same bytes, and so a browser can cache it:
// pages ask for it as xeric-view.js?v=<hash of this file> (xeric_play_asset in
// play-lib.php), with the page's nonce on the <script src> — the CSP is
// nonce-only for scripts, and a nonce on a src script is as good as one on an
// inline block. No 'strict-dynamic', nothing loaded from anywhere else.
//
// ONE GLOBAL, A FACTORY: XericView({ world }) returns the viewer for one page.
// What it holds — the view on screen, where you were facing in each one, the
// one <audio> — is the page's state and lives in that closure, never on window.
// The page keeps only its glue: when to build a view (XV.mount / XV.unmount,
// XV.dayView), which button speaks (XV.speak), and who the hold-to-talk line
// is for (XV.hold). forge/web/tests/viewer-test.php runs this file, as served,
// against a fake DOM, WebGL, WebXR and DeviceOrientationEvent.
//
// THE STAND PAGE (stand.php) loads the same file and passes pano() two more
// things — a loader for a panorama behind a token, and its hooks — which turn
// step inside into standXR: the people as quads, a ray that picks them, hold
// to talk, and an XREquirectLayer where the browser has layers. Its pure parts
// (tagPos, tagQuad, raySelect, holdTarget, panAt, offline) are handed out so
// forge/web/tests/stand-test.php can run them.
(function (root) {
  'use strict';

root.XericView = function (o) {
  o = o || {};
  var W = o.world || '';
  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var lookView = null;                                // the look-around on screen, if any
  var lookYaw = {};                                   // where you were facing, per view, across repaints

  // THE DAY STRIP: pressing a light puts that hour's view where the view is.
  // The main view is rebuilt rather than re-pointed — a WebGL canvas whose
  // context has been handed back cannot be given another — and the people's
  // buttons move across with it, because they are where people are NOW
  // whichever hour the walls are lit for. Where you were facing is kept.
  function dayView(th) {
    var main = document.querySelector('#look .lookmain');
    if (!main) return;
    var src = th.dataset.src, kind = th.dataset.kind, old = main.dataset.src || '';
    if (lookYaw[old] && !lookYaw[src]) lookYaw[src] = lookYaw[old];
    var tags = main.querySelector('.looktags, .looktagrow');
    var el = document.createElement('div');
    if (kind === 'pano') {
      el.className = 'lookmain lookpanobox';
      el.dataset.src = src;
      el.dataset.alt = th.dataset.light || '';
      if (main.dataset.bearings) el.dataset.bearings = main.dataset.bearings;
      var cv = document.createElement('canvas');
      cv.className = 'lookpano'; cv.tabIndex = 0; cv.setAttribute('role', 'img');
      cv.setAttribute('aria-label', 'A look around the room, ' + (th.dataset.light || '') + '. Drag, or use the arrow keys, to turn.');
      el.appendChild(cv);
      if (tags) { tags.className = 'looktags'; $$('.looktag', tags).forEach(function (b) { b.hidden = true; }); }
    } else {
      el.className = 'lookmain lookstill';
      el.dataset.src = src;
      var im = document.createElement('img');
      im.className = 'lookimg'; im.src = src; im.alt = th.dataset.light || '';
      el.appendChild(im);
      if (tags) { tags.className = 'looktagrow'; $$('.looktag', tags).forEach(function (b) { b.hidden = false; }); }
    }
    if (tags) el.appendChild(tags);
    if (lookView) { lookView.destroy(); lookView = null; }
    main.replaceWith(el);
    if (kind === 'pano') lookView = pano(el);
    $$('#look .lookthumb').forEach(function (x) {
      x.classList.toggle('cur', x === th);
      x.setAttribute('aria-pressed', x === th ? 'true' : 'false');
    });
    var lab = document.querySelector('#look .lookearlier');
    if (lab) {
      lab.hidden = th.dataset.now === '1';
      lab.textContent = th.dataset.now === '1' ? '' : 'earlier: ' + (th.dataset.light || '');
    }
  }

  // THE LOOK-AROUND. An equirectangular panorama is a sphere unrolled: every
  // pixel's column is a compass bearing and its row an elevation. To look
  // through it, each pixel of the canvas is turned into a ray from your eye
  // (its position on a virtual screen tan(fov/2) wide), the ray is turned by
  // where you are facing (pitch, then yaw), and the ray's bearing and
  // elevation say which texel of the panorama it hits. That is the whole
  // viewer, and it is one fragment shader. No library, because the CSP loads
  // none and because there is nothing here a library would add.
  //
  // FRAMES ONLY ON DEMAND: one draw per drag, wheel or key, coalesced through
  // requestAnimationFrame. A still sidebar costs nothing. WebGL first; a 2D
  // canvas that pans the strip sideways if there is no WebGL; the plain image
  // if there is no canvas at all.
  //
  // THE PEOPLE are real buttons laid over the canvas, not pixels drawn on it,
  // so they can be tabbed to, read out and pressed. Each frame puts each one
  // at its bearing (degrees clockwise from where the room faces you, the
  // engine's own number) just under the horizon, fades it toward the edge of
  // the view and hides it past the edge, where a hidden button is also not a
  // tab stop.
  //
  // TWO THINGS A PAGE MAY HAND OVER (opt), both the stand page's:
  //   opt.load(src) → a promise of something WebGL can upload (an ImageBitmap
  //     from createImageBitmap, or a canvas), for a page whose panorama is
  //     behind an Authorization header an <img> cannot send. Never a blob:
  //     URL, which the CSP does not allow and does not need to.
  //   opt.stand — the stand page's hooks; step inside is then standXR (tags
  //     drawn in the headset, a ray that picks them, hold to talk) instead of
  //     xr(). Without either, the view is exactly what play.php has always had.
  function pano(box, opt) {
    opt = opt || {};
    var cv = box.querySelector('canvas'), src = box.dataset.src;
    var v = lookYaw[src] || { yaw: 0, pitch: 0, fov: 1.4 };
    lookYaw[src] = v;
    var tags = [].slice.call(box.querySelectorAll('.looktag'));
    var img = new Image(), gl = null, g2 = null, prog = null, tex = null, raf = 0, dead = false, xrh = null, gz = null;
    var bm = null, buf = null;                        // opt.load's bitmap, when the page loads it; the quad
    // opt.video: a playing <video> (the live view, stand.php) drawn onto the
    // same sphere, one texImage2D per frame. Cross-origin video taints a
    // WebGL texture unless the machine answers with CORS, so a refused upload
    // drops back to the panorama behind it (opt.videoFail says so).
    var vid = opt.video || null, vraf = 0;
    function pw() { return vid ? vid.videoWidth : bm ? bm.width : img.naturalWidth; }
    function ph() { return vid ? vid.videoHeight : bm ? bm.height : img.naturalHeight; }
    function plain() {
      if (dead) return;
      // The still, and the people as the row a still has under it.
      var st = document.createElement('div');
      st.className = 'lookmain lookstill';
      var im = document.createElement('img');
      im.className = 'lookimg'; im.src = src; im.alt = box.dataset.alt || '';
      st.appendChild(im);
      var tg = box.querySelector('.looktags');
      if (tg) { tg.className = 'looktagrow'; tags.forEach(function (b) { b.hidden = false; b.style.cssText = ''; }); st.appendChild(tg); }
      box.replaceWith(st);
      dead = true;
    }
    try { gl = cv.getContext('webgl', { antialias: false, preserveDrawingBuffer: false }); } catch (e) { gl = null; }
    if (gl) {
      prog = panoProgram(gl, 'uniform vec2 r;uniform float y,p,f;'
        + 'void main(){vec2 q=(gl_FragCoord.xy/r)*2.0-1.0;q.x*=r.x/r.y;'
        + 'vec3 d=normalize(vec3(q.x*f,q.y*f,-1.0));'
        + 'float cp=cos(p),sp=sin(p);d=vec3(d.x,d.y*cp-d.z*sp,d.y*sp+d.z*cp);'
        + 'float cy=cos(y),sy=sin(y);d=vec3(d.x*cy+d.z*sy,d.y,-d.x*sy+d.z*cy);'
        + 'gl_FragColor=look(d);}');
      if (!prog) gl = null;
    }
    if (!gl) { try { g2 = cv.getContext('2d'); } catch (e) { g2 = null; } }
    if (!gl && !g2) { plain(); return { destroy: function () {} }; }

    function size() {
      var dpr = Math.min(window.devicePixelRatio || 1, 2);
      var w = Math.max(1, Math.round(cv.clientWidth * dpr)), h = Math.max(1, Math.round(cv.clientHeight * dpr));
      if (cv.width !== w || cv.height !== h) { cv.width = w; cv.height = h; }
    }
    // THE SEAM: one frame from three plain numbers — yaw (radians, positive
    // turns you left), pitch (radians, positive looks up) and fov (vertical,
    // radians). Nothing in here reads the drag state, so anything that wants
    // to aim the view (a name tag at a bearing, a day strip, a headset) calls
    // this with its own numbers.
    function drawAt(yaw, pitch, fov) {
      if (dead || !((vid && vid.readyState >= 2 && vid.videoWidth) || bm || (!vid && img.complete && img.naturalWidth))) return;
      size();
      if (gl && vid && tex) {
        gl.bindTexture(gl.TEXTURE_2D, tex);
        try { gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, vid); }
        catch (e) { unvideo(); return; }
      }
      if (gl) {
        gl.bindFramebuffer(gl.FRAMEBUFFER, null);
        gl.viewport(0, 0, cv.width, cv.height);
        gl.useProgram(prog);
        gl.uniform2f(gl.getUniformLocation(prog, 'r'), cv.width, cv.height);
        gl.uniform1f(gl.getUniformLocation(prog, 'y'), yaw);
        gl.uniform1f(gl.getUniformLocation(prog, 'p'), pitch);
        gl.uniform1f(gl.getUniformLocation(prog, 'f'), Math.tan(fov / 2));
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
        return;
      }
      // The strip, twice its height so a band about the horizon fills the
      // frame, drawn three times side by side so turning never runs off the
      // end. Positive yaw slides it right, the same way the shader turns.
      var h = cv.height * 2, w = h * pw() / ph();
      var x = (((yaw / (2 * Math.PI)) % 1 + 1) % 1) * w - w / 2 + cv.width / 2;
      var y0 = -cv.height / 2 + pitch / Math.PI * h;
      g2.clearRect(0, 0, cv.width, cv.height);
      for (var i = -1; i <= 1; i++) g2.drawImage(vid || bm || img, x + i * w, y0, w, h);
    }
    // The tags for the same three numbers, in CSS pixels over the canvas. The
    // WebGL view is a perspective, so a bearing lands at tan(angle); the 2D
    // strip is linear, so it lands at angle × pixels-per-radian.
    function placeTags(yaw, pitch, fov) {
      if (!tags.length || dead) return;
      var W = cv.clientWidth, H = cv.clientHeight, f = Math.tan(fov / 2), asp = W / Math.max(1, H);
      var below = -0.12;                              // a little under the horizon: about chest height
      var k = 2 * H / Math.PI;                        // the strip's pixels per radian, both ways
      tags.forEach(function (t) {
        var a = (+t.dataset.b || 0) * Math.PI / 180 + yaw;
        a = Math.atan2(Math.sin(a), Math.cos(a));     // -π..π, the short way round
        var x, y;
        if (gl) {
          if (Math.abs(a) >= Math.PI / 2) { t.hidden = true; return; }
          x = Math.tan(a) / (f * asp);
          y = Math.tan(below - pitch) / f;
        } else {
          x = a * k / (W / 2);
          y = (below - pitch) * k / (H / 2);
        }
        var ax = Math.abs(x);
        if (ax > 1 || Math.abs(y) > 1) { t.hidden = true; return; }
        t.hidden = false;
        t.style.left = ((x + 1) / 2 * W + cv.offsetLeft) + 'px';
        t.style.top = ((1 - y) / 2 * H + cv.offsetTop) + 'px';
        t.style.opacity = ax > 0.75 ? String(Math.max(0.15, (1 - ax) / 0.25)) : '1';
      });
    }
    function ask() {
      if (!raf) raf = requestAnimationFrame(function () {
        raf = 0; drawAt(v.yaw, v.pitch, v.fov); placeTags(v.yaw, v.pitch, v.fov);
      });
    }
    function turn(dx, dy) {
      v.yaw += dx; v.pitch = Math.max(-1.45, Math.min(1.45, v.pitch + dy)); ask();
    }

    function loaded() {
      if (dead) return;
      if (gl && tex) {                                // a second source (the poster after the video): same texture
        gl.bindTexture(gl.TEXTURE_2D, tex);
        try { gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, bm || img); } catch (e) { plain(); return; }
        ask(); return;
      }
      if (gl) {
        var source = vid || bm || img, max = gl.getParameter(gl.MAX_TEXTURE_SIZE) || 2048;
        if (!vid && pw() > max) {                             // a phone's ceiling: shrink once, on a 2D canvas
          var c2 = document.createElement('canvas');
          c2.width = max; c2.height = Math.round(max * ph() / pw());
          c2.getContext('2d').drawImage(bm || img, 0, 0, c2.width, c2.height);
          source = c2;
        }
        buf = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buf);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
        // Location 0 by construction (panoProgram binds it), so the headset's
        // program reads the same quad without being told where it is.
        gl.enableVertexAttribArray(0);
        gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
        tex = gl.createTexture();
        gl.bindTexture(gl.TEXTURE_2D, tex);
        // No mipmaps and clamp both ways: a panorama is never a power of two,
        // and WebGL 1 will only sample one of those like this. The wrap at the
        // seam is done in the shader with fract().
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
        try { gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, source); }
        catch (e) { if (vid) { unvideo(); return; } plain(); return; }
      }
      if (vid && !vraf) {
        // A video is a picture that changes: draw every frame while it plays.
        var loop = function () { vraf = 0; if (dead || !vid) return; ask(); vraf = requestAnimationFrame(loop); };
        vraf = requestAnimationFrame(loop);
      }
      ask();
    }
    // The live view will not draw here (tainted, or gone): the panorama.
    function unvideo() {
      if (!vid) return;
      vid = null;
      if (vraf) { cancelAnimationFrame(vraf); vraf = 0; }
      if (typeof opt.videoFail === 'function') { try { opt.videoFail(); } catch (e) {} }
      picture();
    }
    function broken() { dead = true; box.remove(); }
    // The quad and the panorama put back where the look-around expects them,
    // after a headset frame has drawn its tags with buffers of its own.
    function quad() {
      if (!gl || !buf) return;
      gl.bindBuffer(gl.ARRAY_BUFFER, buf);
      gl.enableVertexAttribArray(0);
      gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
      gl.bindTexture(gl.TEXTURE_2D, tex);
    }
    img.addEventListener('load', loaded);
    img.addEventListener('error', broken);
    function picture() {
    if (typeof opt.load === 'function') {
      // The page fetches the bytes itself (with its token) and hands back a
      // bitmap: createImageBitmap(blob) needs no blob: URL and no img-src.
      Promise.resolve().then(function () { return opt.load(src); }).then(function (b) {
        if (dead) return;
        if (!b || !b.width || !b.height) { broken(); return; }
        bm = b; loaded();
      }, function () { if (!dead) broken(); });
    } else {
      img.src = src;                                  // same origin: img-src 'self', and no tainted texture
    }
    }
    if (vid) {
      if (vid.readyState >= 2) loaded();
      else vid.addEventListener('loadeddata', function () { if (vid) loaded(); });
      vid.addEventListener('error', unvideo);
    } else {
      picture();
    }

    var drag = null;
    cv.addEventListener('pointerdown', function (e) {
      drag = { x: e.clientX, y: e.clientY };
      cv.setPointerCapture(e.pointerId);
      cv.classList.add('grabbing');
    });
    cv.addEventListener('pointermove', function (e) {
      if (!drag) return;
      // A drag across the whole canvas turns you by the field of view: the
      // room moves under the finger at the speed the finger moves.
      var k = v.fov / Math.max(1, cv.clientHeight);
      turn((e.clientX - drag.x) * k, (e.clientY - drag.y) * k);
      drag = { x: e.clientX, y: e.clientY };
    });
    function up() { drag = null; cv.classList.remove('grabbing'); }
    cv.addEventListener('pointerup', up);
    cv.addEventListener('pointercancel', up);
    cv.addEventListener('wheel', function (e) {
      e.preventDefault();
      v.fov = Math.max(0.5, Math.min(2.0, v.fov * (e.deltaY > 0 ? 1.08 : 1 / 1.08)));
      ask();
    }, { passive: false });
    cv.addEventListener('keydown', function (e) {
      var s = 0.12;
      if (e.key === 'ArrowLeft')       turn(s, 0);     // positive yaw is turning left
      else if (e.key === 'ArrowRight') turn(-s, 0);
      else if (e.key === 'ArrowUp')    turn(0, s);
      else if (e.key === 'ArrowDown')  turn(0, -s);
      else return;
      e.preventDefault();
    });
    window.addEventListener('resize', ask);
    if (gl && opt.stand) {
      xrh = standXR({ gl: gl, box: box, redraw: ask, stand: opt.stand, quad: quad,
                      dims: function () { return { w: pw(), h: ph() }; },
                      ready: function () { return !dead && !!tex; }, dead: function () { return dead; } });
    } else if (gl) {
      xrh = xr({ gl: gl, box: box, redraw: ask,
                 ready: function () { return !dead && !!tex; }, dead: function () { return dead; } });
    }
    gz = gaze({ box: box, view: v, redraw: ask, dead: function () { return dead; } });
    return {
      view: v, draw: drawAt, tags: placeTags, redraw: ask,
      // The people changed and the room did not: read the buttons again
      // rather than build the view again (which would end a headset session).
      retag: function () { tags = [].slice.call(box.querySelectorAll('.looktag')); ask(); },
      get inside() { return !!(xrh && xrh.inside && xrh.inside()); },
      destroy: function () {
        dead = true;
        if (xrh) xrh.end();
        if (gz) gz.end();
        window.removeEventListener('resize', ask);
        if (raf) cancelAnimationFrame(raf);
        if (vraf) cancelAnimationFrame(vraf);
        // Hand the context back: a page that repaints its sidebar after every
        // turn would otherwise collect WebGL contexts until the browser drops one.
        if (gl) { var lose = gl.getExtension('WEBGL_lose_context'); if (lose) lose.loseContext(); }
      }
    };
  }

  // THE LIVE VIEW (engine/stream.php, docs/VIEWPORT.md "Streams"). A world
  // model drawing the room as video, played straight from the scene machine's
  // own origin — the one remote origin the page's CSP names (ui.php).
  //
  // WHEP, MINIMALLY, AND NO LIBRARY: a receive-only RTCPeerConnection, its
  // offer POSTed as application/sdp to the session url once ICE has gathered
  // (non-trickle, two seconds at most), the answer set, and the track put on
  // the <video>. The resource the server names in Location is DELETEd on
  // close when it is on the same origin. No credentials ride along: the
  // session url is the machine's own short-lived capability, and the key
  // never leaves the server. `dep` is the test's seam (PC, fetch, iceWait).
  function whep(url, video, dep) {
    dep = dep || {};
    var PC = dep.PC || root.RTCPeerConnection, F = dep.fetch || root.fetch;
    var closed = false, res = null;
    if (!PC || !F) {
      return { ready: Promise.reject(new Error('this browser has no WebRTC')), close: function () {}, pc: null };
    }
    var pc = new PC({ bundlePolicy: 'max-bundle' });
    pc.addTransceiver('video', { direction: 'recvonly' });
    pc.addTransceiver('audio', { direction: 'recvonly' });
    pc.ontrack = function (e) {
      var ms = (e.streams && e.streams[0]) || null;
      if (!ms && root.MediaStream) { ms = new root.MediaStream(); ms.addTrack(e.track); }
      if (ms && video && video.srcObject !== ms) video.srcObject = ms;
      var p = video && video.play && video.play();
      if (p && p.catch) p.catch(function () {});
    };
    function gathered() {
      if (pc.iceGatheringState === 'complete') return Promise.resolve();
      return new Promise(function (r) {
        var t = setTimeout(r, dep.iceWait == null ? 2000 : dep.iceWait);
        pc.onicegatheringstatechange = function () { if (pc.iceGatheringState === 'complete') { clearTimeout(t); r(); } };
      });
    }
    var ready = pc.createOffer().then(function (o) { return pc.setLocalDescription(o); }).then(gathered).then(function () {
      return F(url, { method: 'POST', headers: { 'Content-Type': 'application/sdp' }, body: pc.localDescription.sdp,
                      credentials: 'omit', cache: 'no-store' });
    }).then(function (r) {
      if (!r || !(r.status === 201 || r.ok)) throw new Error('the machine did not take the offer');
      var loc = r.headers && r.headers.get ? r.headers.get('Location') : null;
      if (loc) { try { var u = new URL(loc, url); if (u.origin === new URL(url).origin) res = u.href; } catch (e) {} }
      return r.text();
    }).then(function (sdp) {
      if (closed) return;
      return pc.setRemoteDescription({ type: 'answer', sdp: sdp });
    });
    return {
      ready: ready, pc: pc,
      close: function () {
        if (closed) return;
        closed = true;
        try { pc.close(); } catch (e) {}
        if (res) { try { F(res, { method: 'DELETE', credentials: 'omit' }).catch(function () {}); } catch (e) {} }
      }
    };
  }

  // Can this browser play HLS by itself? Safari, visionOS and Quest can;
  // desktop Chrome does not without a library, and this app vendors none.
  function playsHls(v) {
    return !!(v && v.canPlayType && v.canPlayType('application/vnd.apple.mpegurl'));
  }

  // The heartbeat, alone — the stand page runs it beside its own sphere.
  // Every ten seconds a POST to stream.php says the view is still on screen;
  // the server counts the seconds and answers whether it goes on. Hidden for
  // more than thirty seconds, the page ends it itself. o: id, beat, beatS,
  // auth(headers), fetch, onEnd(reason).
  function streamBeat(o) {
    var dead = false, hidAt = 0, hideT = 0;
    function post(body) {
      var F = o.fetch || root.fetch;
      var h = { 'Content-Type': 'application/json' };
      if (o.auth) h = o.auth(h);
      var b = { w: W, id: +o.id };
      Object.keys(body).forEach(function (k) { b[k] = body[k]; });
      return F(o.beat || 'stream.php', { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify(b) })
        .then(function (r) { return r.json(); });
    }
    function stop() {
      dead = true; clearInterval(timer); clearTimeout(hideT);
      if (typeof document !== 'undefined' && document.removeEventListener) document.removeEventListener('visibilitychange', vis);
    }
    function ended(reason) { if (dead) return; stop(); if (o.onEnd) o.onEnd(reason); }
    function beat() {
      if (dead) return;
      var hf = hidAt ? Math.round((Date.now() - hidAt) / 1000) : 0;
      post({ a: 'beat', hidden_for: hf }).then(function (j) {
        if (j && j.live === false) ended(j.reason || 'it ended');
      }, function () {});
    }
    function vis() {
      if (document.hidden) {
        if (!hidAt) hidAt = Date.now();
        clearTimeout(hideT);
        hideT = setTimeout(function () {
          if (!document.hidden || dead) return;
          post({ a: 'end', why: 'hidden' }).then(function () {}, function () {});
          ended('the tab was hidden for more than 30 seconds');
        }, 31000);
      } else { hidAt = 0; clearTimeout(hideT); }
    }
    if (typeof document !== 'undefined' && document.addEventListener) document.addEventListener('visibilitychange', vis);
    var timer = setInterval(beat, (+o.beatS || 10) * 1000);
    return {
      beat: beat, stop: stop,
      end: function (why) { if (dead) return; stop(); return post({ a: 'end', why: why || 'closed' }).catch(function () {}); }
    };
  }

  // The play view's live box (play-view.php renders it): the media element,
  // the heartbeat, and the stored room back with "the live view ended:
  // <reason>" when it ends — opt.onEnd(reason) then asks the server for the
  // view, which says the same. HLS where the browser plays it; the MJPEG
  // fallback where it does not and the session offers one; otherwise the
  // view is ended at once and says why, rather than billing for a picture
  // nobody can see.
  function stream(box, opt) {
    opt = opt || {};
    var d = box.dataset, pr = d.protocol, url = d.url, fb = d.fallback || '';
    var media = box.querySelector('video, img.lookstreamimg'), w = null, hb = null, dead = false;
    function line(t) { var st = box.querySelector('.lookstreamstate'); if (st) st.textContent = t; }
    function asImg(src) {
      var im = document.createElement('img');
      im.className = 'lookstreamimg'; im.alt = 'live'; im.src = src;
      if (media) media.replaceWith(im);
      media = im;
    }
    function quiet() {
      if (w) { w.close(); w = null; }
      if (media && media.tagName === 'VIDEO') {
        try { media.pause(); } catch (e) {}
        try { media.removeAttribute('src'); media.srcObject = null; } catch (e) {}
      }
    }
    function end(reason, told) {
      if (dead) return;
      dead = true;
      if (hb) { if (told) hb.stop(); else hb.end('closed'); }
      quiet();
      var back = document.createElement('div');
      back.className = 'lookmain lookstill';
      if (d.poster) {
        var im = document.createElement('img');
        im.className = 'lookimg'; im.src = d.poster; im.alt = '';
        back.appendChild(im);
      }
      var p = document.createElement('p');
      p.className = 'lookliveend'; p.textContent = 'the live view ended: ' + reason;
      back.appendChild(p);
      if (box.parentNode) box.replaceWith(back);
      if (opt.onEnd) opt.onEnd(reason);
    }
    var how = '';
    if (pr === 'hls') {
      if (playsHls(media)) {
        how = 'video';
        if (!media.getAttribute('src')) media.setAttribute('src', url);
        var p = media.play && media.play();
        if (p && p.catch) p.catch(function () {});
      } else if (fb) {
        how = 'mjpeg'; asImg(fb);
        line('live — this browser does not play HLS itself, so this is the machine\'s picture-by-picture view');
      }
    } else if (pr === 'webrtc-whep') {
      how = 'video';
      w = whep(url, media, opt);
      w.ready.catch(function () { end('the live view could not be connected', false); });
    } else if (pr === 'mjpeg') {
      how = 'mjpeg';
      if (!media || media.tagName !== 'IMG') asImg(url);
    }
    var api = {
      streamId: d.stream, how: how,
      get alive() { return !dead; },
      get media() { return media; },
      end: function (reason) { end(reason || 'you ended it', false); },
      // A repaint with the same live view: the playing element moves into the
      // new box, so the picture does not blink and WHEP does not reconnect.
      move: function (nb) {
        var fresh = nb.querySelector('video, img.lookstreamimg');
        if (fresh && media && fresh !== media) fresh.replaceWith(media);
        box = nb; d = nb.dataset;
      },
      // Thrown away without a repaint to move into (the player walked on, the
      // view changed): the server is told, so the machine stops drawing now
      // rather than when the sweep notices.
      destroy: function () { if (dead) return; dead = true; if (hb) hb.end('page'); quiet(); }
    };
    hb = streamBeat({ id: d.stream, beat: d.beat, beatS: opt.beatS, auth: opt.auth, fetch: opt.fetch,
                      onEnd: function (reason) { end(reason, true); } });
    if (!how) end('this live view is HLS, and this browser does not play HLS by itself (Safari, Vision Pro and Quest do)', false);
    return api;
  }

  // LOOK WITH THE PHONE. The same view, aimed by the phone in your hand: hold
  // it up like a window into the room and turn. Offered only where the
  // browser has DeviceOrientationEvent and a finger is the pointer (a laptop
  // has the event and nothing that moves it); OFF until pressed, because a
  // view that swings when you shift in your chair is a view you did not ask
  // for. iOS asks permission, and only from a press — so requestPermission()
  // is called in the click itself, and a no (or a throw) takes the button away
  // and says nothing, the headset's rule. Hidden tab, off: a phone in a
  // pocket is not looking at anything.
  //
  // RELATIVE, NOT ABSOLUTE. Each reading is turned into where the back of the
  // phone points (phoneGaze) and only the CHANGE since the last reading is
  // added to the view, so switching it on never jumps, a compass that is a
  // few degrees out costs nothing, and a drag still works on top of it — the
  // drag moves the view, the phone keeps adding its turns to wherever that is.
  function gaze(api) {
    var D = root.DeviceOrientationEvent, btn = null, on = false, last = null;
    var none = { end: function () {} };
    if (!D) return none;
    var asks = typeof D.requestPermission === 'function';
    try {
      if (!asks && root.matchMedia && !root.matchMedia('(pointer: coarse)').matches) return none;
    } catch (e) { return none; }
    function screenAngle() {
      var so = root.screen && root.screen.orientation;
      var a = so && typeof so.angle === 'number' ? so.angle : (typeof root.orientation === 'number' ? root.orientation : 0);
      return a * Math.PI / 180;
    }
    function lit() {
      if (!btn) return;
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.textContent = on ? 'stop following the phone' : 'look with the phone';
      btn.classList.toggle('on', on);
    }
    function read(e) {
      if (!on) return;
      if (api.dead()) { stop(); return; }
      if (e.alpha == null || e.beta == null || e.gamma == null) return;
      var g = phoneGaze(+e.alpha, +e.beta, +e.gamma, screenAngle());
      if (last) {
        var dy = g.yaw - last.yaw;
        api.view.yaw += Math.atan2(Math.sin(dy), Math.cos(dy));
        api.view.pitch = Math.max(-1.45, Math.min(1.45, api.view.pitch + (g.pitch - last.pitch)));
        api.redraw();
      }
      last = g;
    }
    function start() {
      if (on || api.dead()) return;
      on = true; last = null;
      window.addEventListener('deviceorientation', read);
      lit();
    }
    function stop() {
      if (on) window.removeEventListener('deviceorientation', read);
      on = false; last = null;
      lit();
    }
    function gone() { stop(); if (btn) { btn.remove(); btn = null; } }
    function hidden() { if (document.hidden) stop(); }
    btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'nbtn lookgaze';
    btn.addEventListener('click', function () {
      if (on) { stop(); return; }
      if (!asks) { start(); return; }
      try {
        D.requestPermission().then(function (r) { if (r === 'granted') start(); else gone(); }, gone);
      } catch (e) { gone(); }
    });
    lit();
    api.box.appendChild(btn);
    document.addEventListener('visibilitychange', hidden);
    return { end: function () { gone(); document.removeEventListener('visibilitychange', hidden); } };
  }

  // Where the back of the phone points, as the viewer's yaw and pitch.
  // alpha, beta, gamma are the W3C's Z-X'-Y'' angles in degrees: the phone's
  // frame is R = Rz(alpha)·Rx(beta)·Ry(gamma) in the earth's (x east, y north,
  // z up), so the column k of R is the phone's axis k on the ground. Looking is
  // along -z (out of the back); pitch is how far that rises. The heading is
  // taken from the look AND the screen's up together, f·u.z − u·f.z across the
  // ground, which is the true heading for any pitch and stays steady looking
  // straight down, where f alone has none. The screen's up is the phone's y
  // turned by the screen's own rotation (0, 90, 180, 270), so a phone held
  // sideways looks where a phone held upright does. Yaw is that heading turned
  // round to the viewer's sense: positive is turning left, as the arrow keys.
  function phoneGaze(alpha, beta, gamma, screenAngle) {
    var r = Math.PI / 180, a = alpha * r, b = beta * r, g = gamma * r;
    var ca = Math.cos(a), sa = Math.sin(a), cb = Math.cos(b), sb = Math.sin(b), cg = Math.cos(g), sg = Math.sin(g);
    var x = [ca * cg - sa * sb * sg, sa * cg + ca * sb * sg, -cb * sg];   // the phone's right edge
    var y = [-sa * cb, ca * cb, sb];                                     // its top edge
    var z = [ca * sg + sa * sb * cg, sa * sg - ca * sb * cg, cb * cg];   // out of the screen
    var ss = Math.sin(screenAngle || 0), cs = Math.cos(screenAngle || 0);
    var f = [-z[0], -z[1], -z[2]];
    var u = [ss * x[0] + cs * y[0], ss * x[1] + cs * y[1], ss * x[2] + cs * y[2]];
    var hx = f[0] * u[2] - u[0] * f[2], hy = f[1] * u[2] - u[1] * f[2];
    return { yaw: -Math.atan2(hx, hy), pitch: Math.asin(Math.max(-1, Math.min(1, f[2]))) };
  }

  // STEP INSIDE. The same panorama on a headset, through the same context and
  // the same sampling GLSL (panoProgram's look()), with one change: the ray for
  // each pixel comes from the headset rather than from yaw and pitch. Per eye,
  // the inverse of projection × view — the view's rotation only, because a
  // panorama is at infinity and leaning forward must not walk you into the pie
  // case — turns a pixel's position on that eye's viewport into a direction in
  // the 'local' space, which is -z ahead and y up exactly as the 2D view is.
  //
  // OFFERED, NEVER DANGLED: the button exists only once the browser has said
  // an immersive session is supported; absent rather than disabled otherwise.
  // The people's tags do not come along — buttons do not exist in there, and
  // drawing them into the shader would be a second renderer of who is where.
  // Anything that throws, anywhere, takes the button away and says nothing;
  // the 2D view is the floor and it is still there.
  function xr(api) {
    var gl = api.gl, box = api.box, btn = null, session = null, xprog = null;
    function off() { session = null; if (btn) { btn.remove(); btn = null; } }
    function fail() { var s = session; off(); try { if (s) s.end(); } catch (e) {} }
    try {
      var nx = navigator.xr;
      if (!nx || typeof nx.isSessionSupported !== 'function') return { end: function () {} };
      nx.isSessionSupported('immersive-vr').then(function (yes) {
        if (!yes || api.dead()) return;
        btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'nbtn lookxr'; btn.textContent = 'step inside';
        btn.addEventListener('click', function () {
          if (session) { try { session.end(); } catch (e) { fail(); } return; }
          enter();
        });
        box.appendChild(btn);
      }, off).catch(off);
    } catch (e) { off(); }

    function enter() {
      try {
        var ready = gl.makeXRCompatible ? gl.makeXRCompatible() : Promise.resolve();
        ready.then(function () { return navigator.xr.requestSession('immersive-vr'); }).then(function (s) {
          session = s;
          xprog = xprog || panoProgram(gl, 'uniform mat4 m;uniform vec4 vp;'
            + 'void main(){vec2 q=((gl_FragCoord.xy-vp.xy)/vp.zw)*2.0-1.0;'
            + 'vec4 w=m*vec4(q,1.0,1.0);gl_FragColor=look(normalize(w.xyz/w.w));}');
          if (!xprog) throw new Error('no headset shader');
          s.updateRenderState({ baseLayer: new XRWebGLLayer(s, gl) });
          s.addEventListener('end', function () {
            session = null;
            if (btn) btn.textContent = 'step inside';
            try { gl.bindFramebuffer(gl.FRAMEBUFFER, null); } catch (e) {}
            api.redraw();                               // the 2D view, back where it was
          });
          if (btn) btn.textContent = 'step out';
          return s.requestReferenceSpace('local').then(function (space) {
            var frame = function (t, f) {
              if (session !== s) return;
              try {
                s.requestAnimationFrame(frame);
                var pose = f.getViewerPose(space);
                if (!pose || !api.ready()) return;
                var layer = s.renderState.baseLayer;
                gl.bindFramebuffer(gl.FRAMEBUFFER, layer.framebuffer);
                gl.useProgram(xprog);
                for (var i = 0; i < pose.views.length; i++) {
                  var view = pose.views[i], vp = layer.getViewport(view);
                  gl.viewport(vp.x, vp.y, vp.width, vp.height);
                  gl.uniform4f(gl.getUniformLocation(xprog, 'vp'), vp.x, vp.y, vp.width, vp.height);
                  gl.uniformMatrix4fv(gl.getUniformLocation(xprog, 'm'), false, xrRay(view));
                  gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
                }
              } catch (e) { fail(); }
            };
            s.requestAnimationFrame(frame);
          });
        }).catch(fail);
      } catch (e) { fail(); }
    }
    return { end: function () { if (session) fail(); } };
  }

  // inverse(P × R), column-major as WebXR hands matrices over: R is the
  // view's world-to-eye matrix with its translation taken out.
  function xrRay(view) {
    var r = Array.prototype.slice.call(view.transform.inverse.matrix);
    r[12] = r[13] = r[14] = 0;
    var p = view.projectionMatrix, m = new Array(16);
    for (var c = 0; c < 4; c++) for (var row = 0; row < 4; row++) {
      var sum = 0;
      for (var k = 0; k < 4; k++) sum += p[k * 4 + row] * r[c * 4 + k];
      m[c * 4 + row] = sum;
    }
    return mat4inv(m);
  }

  // A 4×4 inverse by cofactors; the singular case cannot come from a real
  // projection, and answers identity rather than NaN if it ever does.
  function mat4inv(a) {
    var b00 = a[0] * a[5] - a[1] * a[4], b01 = a[0] * a[6] - a[2] * a[4], b02 = a[0] * a[7] - a[3] * a[4],
        b03 = a[1] * a[6] - a[2] * a[5], b04 = a[1] * a[7] - a[3] * a[5], b05 = a[2] * a[7] - a[3] * a[6],
        b06 = a[8] * a[13] - a[9] * a[12], b07 = a[8] * a[14] - a[10] * a[12], b08 = a[8] * a[15] - a[11] * a[12],
        b09 = a[9] * a[14] - a[10] * a[13], b10 = a[9] * a[15] - a[11] * a[13], b11 = a[10] * a[15] - a[11] * a[14];
    var det = b00 * b11 - b01 * b10 + b02 * b09 + b03 * b08 - b04 * b07 + b05 * b06;
    if (!det) return new Float32Array([1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1]);
    det = 1 / det;
    return new Float32Array([
      (a[5] * b11 - a[6] * b10 + a[7] * b09) * det, (a[2] * b10 - a[1] * b11 - a[3] * b09) * det,
      (a[13] * b05 - a[14] * b04 + a[15] * b03) * det, (a[10] * b04 - a[9] * b05 - a[11] * b03) * det,
      (a[6] * b08 - a[4] * b11 - a[7] * b07) * det, (a[0] * b11 - a[2] * b08 + a[3] * b07) * det,
      (a[14] * b02 - a[12] * b05 - a[15] * b01) * det, (a[8] * b05 - a[10] * b02 + a[11] * b01) * det,
      (a[4] * b10 - a[5] * b08 + a[7] * b06) * det, (a[1] * b08 - a[0] * b10 - a[3] * b06) * det,
      (a[12] * b04 - a[13] * b02 + a[15] * b00) * det, (a[9] * b02 - a[8] * b04 - a[11] * b00) * det,
      (a[5] * b07 - a[4] * b09 - a[6] * b06) * det, (a[0] * b09 - a[1] * b07 + a[2] * b06) * det,
      (a[13] * b01 - a[12] * b03 - a[14] * b00) * det, (a[8] * b03 - a[9] * b01 + a[10] * b00) * det
    ]);
  }

  // One program, two ways to aim it. Both share the quad (attribute 0) and
  // look(d): a direction in, the panorama's texel out — bearing to column,
  // elevation to row, the seam wrapped with fract(). Null when the GPU will
  // not compile it, which every caller reads as "no WebGL here".
  function panoProgram(gl, body) {
    var vs = 'attribute vec2 a;void main(){gl_Position=vec4(a,0.0,1.0);}';
    var fs = '#ifdef GL_FRAGMENT_PRECISION_HIGH\nprecision highp float;\n#else\nprecision mediump float;\n#endif\n'
      + 'uniform sampler2D t;'
      + 'vec4 look(vec3 d){float lon=atan(d.x,-d.z);float lat=asin(clamp(d.y,-1.0,1.0));'
      + 'return texture2D(t,vec2(fract(lon/6.2831853+0.5),0.5-lat/3.1415927));}' + body;
    var sh = function (type, text) {
      var s = gl.createShader(type); gl.shaderSource(s, text); gl.compileShader(s);
      return gl.getShaderParameter(s, gl.COMPILE_STATUS) ? s : null;
    };
    var a = sh(gl.VERTEX_SHADER, vs), b = sh(gl.FRAGMENT_SHADER, fs);
    if (!a || !b) return null;
    var p = gl.createProgram();
    gl.attachShader(p, a); gl.attachShader(p, b);
    gl.bindAttribLocation(p, 0, 'a');
    gl.linkProgram(p);
    return gl.getProgramParameter(p, gl.LINK_STATUS) ? p : null;
  }

  // ===========================================================================
  // THE STAND PAGE'S HEADSET (stand.php). Everything below is additive: play.php
  // never passes opt.stand, so its step inside is still xr() above, unchanged.
  //
  // In a headset there are no buttons, so the people are drawn: each tag is a
  // canvas with a name on it, uploaded as a texture and drawn as a quad at
  // (sin b, −0.12, −cos b) for bearing b — one metre away, a little under the
  // horizon, the same place the 2D view puts its buttons (placeTags). Drawn
  // with the view's ROTATION ONLY, like the panorama: the room is at infinity,
  // and a name that slid about when you leaned would not be on the person.
  //
  // A controller or a hand points a ray; on `select` the nearest tag within 7°
  // of it wins (raySelect). A tap focuses that person; a hold talks to them;
  // a hold on empty space talks to the room, if a room can open (two people
  // or more where you stand: holdTarget). What a tap or a hold then DOES is
  // the page's (opt.stand: tap, holdStart, holdEnd); this file only aims.
  //
  // THE PANORAMA, two ways. Where the browser has WebXR Layers (Quest), it is
  // handed to the compositor once as an XREquirectLayer and the tags go on a
  // transparent projection layer above it — the headset reprojects the room
  // itself, at its own resolution, and no per-pixel shader runs at all.
  // Elsewhere (Android XR without layers, Vision Pro, the emulator) it is the
  // same per-pixel shader xr() uses, and the tags are drawn over it.
  // ===========================================================================

  // Where a tag stands, as a direction from the eye: bearing in degrees,
  // clockwise from where the room faces you; y a little under the horizon.
  function tagPos(b, y) {
    var r = (+b || 0) * Math.PI / 180;
    return [Math.sin(r), y === undefined ? -0.12 : y, -Math.cos(r)];
  }

  // One tag's quad, as a triangle strip of four (x, y, z, u, v): centred on
  // tagPos, square to the line from the eye, upright. The canvas is uploaded
  // unflipped, so v = 0 is the top of the name.
  function tagQuad(b, hw, hh, y) {
    var c = tagPos(b, y), r = (+b || 0) * Math.PI / 180;
    var rx = Math.cos(r) * hw, rz = Math.sin(r) * hw;  // to the right, as you face it
    return new Float32Array([
      c[0] - rx, c[1] - hh, c[2] - rz, 0, 1,
      c[0] + rx, c[1] - hh, c[2] + rz, 1, 1,
      c[0] - rx, c[1] + hh, c[2] - rz, 0, 0,
      c[0] + rx, c[1] + hh, c[2] + rz, 1, 0
    ]);
  }

  function norm3(v) {
    var l = Math.sqrt(v[0] * v[0] + v[1] * v[1] + v[2] * v[2]) || 1;
    return [v[0] / l, v[1] / l, v[2] / l];
  }

  // Where a pose points: its −z axis, from a column-major rigid transform.
  function rayDir(m) { return norm3([-m[8], -m[9], -m[10]]); }

  // That direction as a bearing, in degrees, the tags' own sense.
  function bearingOf(d) { return Math.atan2(d[0], -d[2]) * 180 / Math.PI; }

  // THE NEAREST TAG WITHIN maxDeg (7°) OF THE RAY, or null. By direction only:
  // the tags are drawn at infinity, so where the hand is does not matter,
  // only where it points.
  function raySelect(tags, dir, maxDeg) {
    var lim = (maxDeg === undefined ? 7 : maxDeg) * Math.PI / 180, d = norm3(dir), best = null, bestA = Infinity;
    (tags || []).forEach(function (t) {
      var p = norm3(tagPos(t.b));
      var a = Math.acos(Math.max(-1, Math.min(1, p[0] * d[0] + p[1] * d[1] + p[2] * d[2])));
      if (a <= lim && a < bestA) { best = t; bestA = a; }
    });
    return best;
  }

  // WHAT A HOLD TALKS TO: the person the ray hit; else the room, when one can
  // open — two people or more where you stand; else nothing (a hold at the
  // wall of an empty diner is not a line said to anybody).
  function holdTarget(hit, present) {
    if (hit) return { kind: 'person', h: hit.h, b: +hit.b || 0, name: hit.name || '' };
    if ((present || []).length >= 2) return { kind: 'room' };
    return null;
  }

  // inverse(xrRay(view)): projection × the view's rotation, for drawing quads
  // where the panorama's shader looks them up.
  function viewProj(view) {
    var r = Array.prototype.slice.call(view.transform.inverse.matrix);
    r[12] = r[13] = r[14] = 0;
    var p = view.projectionMatrix, m = new Float32Array(16);
    for (var c = 0; c < 4; c++) for (var row = 0; row < 4; row++) {
      var sum = 0;
      for (var k = 0; k < 4; k++) sum += p[k * 4 + row] * r[c * 4 + k];
      m[c * 4 + row] = sum;
    }
    return m;
  }

  // A name on a canvas, for a texture. Null where there is no 2D canvas to
  // write on (the tag is then simply not drawn; the ray still finds it).
  function tagCanvas(text, lit, wide) {
    var c = document.createElement('canvas');
    c.width = wide ? 1024 : 256; c.height = 64;
    var g = null;
    try { g = c.getContext('2d'); } catch (e) { g = null; }
    if (!g || typeof g.fillText !== 'function') return null;
    var size = 30, t = String(text || '');
    g.font = size + 'px system-ui, sans-serif';
    while (size > 14 && g.measureText && g.measureText(t).width > c.width - 32) { size -= 2; g.font = size + 'px system-ui, sans-serif'; }
    g.clearRect(0, 0, c.width, c.height);
    g.fillStyle = lit ? 'rgba(247,246,242,0.95)' : 'rgba(29,27,24,0.78)';
    g.fillRect(0, 8, c.width, 48);
    g.fillStyle = lit ? '#1d1b18' : '#f7f6f2';
    g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillText(t, c.width / 2, 33);
    return c;
  }

  // THE VOICE AT ITS BEARING. An HRTF panner one metre out at the speaker's
  // bearing (the listener stands at the origin, facing −z, as the room does);
  // no fall-off with distance, because the distance is not real. Connected
  // to `dest` (the page's speech bus) or straight out.
  function panAt(ctx, b, dest) {
    var p = ctx.createPanner(), r = (+b || 0) * Math.PI / 180, x = Math.sin(r), z = -Math.cos(r);
    p.panningModel = 'HRTF';
    p.distanceModel = 'inverse';
    p.refDistance = 1;
    p.rolloffFactor = 0;
    if (p.positionX) { p.positionX.value = x; p.positionY.value = 0; p.positionZ.value = z; }
    else if (p.setPosition) p.setPosition(x, 0, z);
    p.connect(dest || ctx.destination);
    return p;
  }

  // Which way the listener faces: f forward and u up, unit vectors in the
  // room's frame. The 2D view passes (−sin yaw, 0, −cos yaw); a headset its
  // pose's −z and +y.
  function listenerFace(ctx, f, u) {
    var L = ctx && ctx.listener;
    if (!L) return;
    u = u || [0, 1, 0];
    if (L.forwardX) {
      L.forwardX.value = f[0]; L.forwardY.value = f[1]; L.forwardZ.value = f[2];
      L.upX.value = u[0]; L.upY.value = u[1]; L.upZ.value = u[2];
    } else if (L.setOrientation) L.setOrientation(f[0], f[1], f[2], u[0], u[1], u[2]);
  }

  // OFFLINE: the last room, labelled "earlier", and every verb off. Nothing
  // is queued for later — a line said now and sent in an hour would be
  // stamped into a different world hour than the one it answered. Every
  // control that changes the world carries data-verb; this is the one switch.
  function offline(box, on) {
    var n = 0;
    $$('[data-verb]', box).forEach(function (b) {
      b.disabled = !!on;
      b.setAttribute('aria-disabled', on ? 'true' : 'false');
      n++;
    });
    var lab = $('.lookearlier', box);
    if (lab) { lab.hidden = !on; lab.textContent = on ? 'earlier' : ''; }
    if (box.classList) box.classList.toggle('offline', !!on);
    return n;
  }

  // STEP INSIDE, the stand page's way. Offered and failed exactly as xr() is
  // (absent unless supported; anything that throws takes the button away and
  // ends the session; the 2D view is the floor), plus: the tags, the ray, the
  // hold, the equirect layer. S is the page's hooks:
  //   tags() [{h, name, b, focus}], present() [handles], caption() {text, b} or null,
  //   can() may a session start now, offline() are verbs off,
  //   tap(hit), holdStart(target, {b}), holdEnd(target), face(matrix),
  //   start(mode), end(), layers (false to never use them), holdMs.
  function standXR(api) {
    var gl = api.gl, box = api.box, S = api.stand || {}, btn = null, session = null;
    var xprog = null, bprog = null, tprog = null, tbuf = null, texs = {}, ntex = 0, held = null;
    var HOLD = S.holdMs || 350;
    function call(name) {
      if (typeof S[name] !== 'function') return undefined;
      return S[name].apply(null, Array.prototype.slice.call(arguments, 1));
    }
    function dropTextures() {
      Object.keys(texs).forEach(function (k) { try { gl.deleteTexture(texs[k]); } catch (e) {} });
      texs = {}; ntex = 0;
    }
    function off() { session = null; held = null; if (btn) { btn.remove(); btn = null; } }
    function fail() { var s = session; off(); try { if (s) s.end(); } catch (e) {} }
    try {
      var nx = navigator.xr;
      if (!nx || typeof nx.isSessionSupported !== 'function') return { end: function () {}, inside: function () { return false; } };
      nx.isSessionSupported('immersive-vr').then(function (yes) {
        if (!yes || api.dead()) return;
        btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'nbtn lookxr standxr'; btn.textContent = 'step inside';
        btn.addEventListener('click', function () {
          if (session) { try { session.end(); } catch (e) { fail(); } return; }
          if (call('can') === false) return;
          enter();
        });
        box.appendChild(btn);
      }, off).catch(off);
    } catch (e) { off(); }

    function tagProgram() {
      var vs = 'attribute vec3 a;attribute vec2 u;uniform mat4 m;varying vec2 v;'
        + 'void main(){v=u;gl_Position=m*vec4(a,1.0);}';
      var fs = 'precision mediump float;uniform sampler2D t;uniform float k;varying vec2 v;'
        + 'void main(){vec4 c=texture2D(t,v);gl_FragColor=vec4(c.rgb,c.a*k);}';
      var sh = function (type, text) {
        var s = gl.createShader(type); gl.shaderSource(s, text); gl.compileShader(s);
        return gl.getShaderParameter(s, gl.COMPILE_STATUS) ? s : null;
      };
      var a = sh(gl.VERTEX_SHADER, vs), b = sh(gl.FRAGMENT_SHADER, fs);
      if (!a || !b) return null;
      var p = gl.createProgram();
      gl.attachShader(p, a); gl.attachShader(p, b);
      gl.bindAttribLocation(p, 0, 'a'); gl.bindAttribLocation(p, 1, 'u');
      gl.linkProgram(p);
      return gl.getProgramParameter(p, gl.LINK_STATUS) ? p : null;
    }
    function texFor(key, text, lit, wide) {
      if (texs[key]) return texs[key];
      var c = tagCanvas(text, lit, wide);
      if (!c) return null;
      if (ntex > 64) dropTextures();
      var t = gl.createTexture();
      gl.bindTexture(gl.TEXTURE_2D, t);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, c);
      texs[key] = t; ntex++;
      return t;
    }
    // The tags (and the caption, while there is one) into whatever is bound.
    function drawTags(view) {
      var tags = call('tags') || [], cap = call('caption') || null;
      if (!tprog || (!tags.length && !cap)) return;
      gl.useProgram(tprog);
      gl.enable(gl.BLEND);
      gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
      gl.uniformMatrix4fv(gl.getUniformLocation(tprog, 'm'), false, viewProj(view));
      gl.activeTexture(gl.TEXTURE0);
      tbuf = tbuf || gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, tbuf);
      gl.enableVertexAttribArray(1);
      var one = function (key, text, b, y, lit, hw, hh, wide) {
        var t = texFor(key, text, lit, wide);
        if (!t) return;
        gl.bufferData(gl.ARRAY_BUFFER, tagQuad(b, hw, hh, y), gl.DYNAMIC_DRAW);
        gl.vertexAttribPointer(0, 3, gl.FLOAT, false, 20, 0);
        gl.vertexAttribPointer(1, 2, gl.FLOAT, false, 20, 12);
        gl.bindTexture(gl.TEXTURE_2D, t);
        gl.uniform1f(gl.getUniformLocation(tprog, 'k'), 1);
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
      };
      tags.forEach(function (t) {
        one('t|' + t.h + '|' + t.name + '|' + (t.focus ? 1 : 0), t.name || t.h, t.b, -0.12, !!t.focus, 0.1, 0.025, false);
      });
      if (cap && cap.text) one('c|' + cap.text, cap.text, cap.b || 0, -0.3, true, 0.4, 0.025, true);
      gl.disableVertexAttribArray(1);
      gl.disable(gl.BLEND);
    }

    // XREquirectLayer, where there is XRWebGLBinding and the session granted
    // layers. Null otherwise, and the shader does the panorama.
    function layers(s, space) {
      if (S.layers === false || typeof root.XRWebGLBinding !== 'function') return null;
      if (s.enabledFeatures && Array.prototype.indexOf.call(s.enabledFeatures, 'layers') < 0) return null;
      var bind = new root.XRWebGLBinding(s, gl);
      if (typeof bind.createEquirectLayer !== 'function' || typeof bind.createProjectionLayer !== 'function') return null;
      var d = api.dims(), max = gl.getParameter(gl.MAX_TEXTURE_SIZE) || 4096;
      var w = Math.max(1, Math.min(d.w || 1, max)), h = Math.max(1, Math.round(w * (d.h || 1) / (d.w || 1)));
      var eq = bind.createEquirectLayer({ space: space, viewPixelWidth: w, viewPixelHeight: h, layout: 'mono',
        radius: 0, centralHorizontalAngle: 2 * Math.PI, upperVerticalAngle: Math.PI / 2, lowerVerticalAngle: -Math.PI / 2 });
      var proj = bind.createProjectionLayer({ textureType: 'texture' });
      s.updateRenderState({ layers: [eq, proj] });
      return { bind: bind, eq: eq, proj: proj, fb: gl.createFramebuffer(), dirty: true };
    }

    function enter() {
      try {
        var ready = gl.makeXRCompatible ? gl.makeXRCompatible() : Promise.resolve();
        ready.then(function () {
          return navigator.xr.requestSession('immersive-vr', { optionalFeatures: ['layers'] });
        }).then(function (s) {
          session = s;
          s.addEventListener('end', function () {
            if (held && held.timer) clearTimeout(held.timer);
            session = null; held = null;
            dropTextures();
            if (btn) btn.textContent = 'step inside';
            try { gl.bindFramebuffer(gl.FRAMEBUFFER, null); api.quad(); } catch (e) {}
            try { call('end'); } catch (e) {}
            api.redraw();                             // the 2D view, back where it was
          });
          if (btn) btn.textContent = 'step out';
          return s.requestReferenceSpace('local').then(function (space) { run(s, space); });
        }).catch(fail);
      } catch (e) { fail(); }
    }

    function run(s, space) {
      if (session !== s) return;
      tprog = tprog || tagProgram();
      var L = layers(s, space);
      if (L) {
        bprog = bprog || panoProgram(gl, 'uniform vec4 vp;'
          + 'void main(){vec2 q=(gl_FragCoord.xy-vp.xy)/vp.zw;gl_FragColor=texture2D(t,vec2(q.x,1.0-q.y));}');
        if (!bprog) throw new Error('no copy shader');
      } else {
        xprog = xprog || panoProgram(gl, 'uniform mat4 m;uniform vec4 vp;'
          + 'void main(){vec2 q=((gl_FragCoord.xy-vp.xy)/vp.zw)*2.0-1.0;'
          + 'vec4 w=m*vec4(q,1.0,1.0);gl_FragColor=look(normalize(w.xyz/w.w));}');
        if (!xprog) throw new Error('no headset shader');
        s.updateRenderState({ baseLayer: new XRWebGLLayer(s, gl) });
      }

      // THE RAY. selectstart remembers what the ray was on; a release before
      // HOLD ms is a tap (select), a press past it is a hold, and the hold's
      // target is fixed at the press — turning your head while you talk does
      // not hand the line to somebody else.
      var rayOf = function (e) {
        var p = e && e.frame && e.inputSource ? e.frame.getPose(e.inputSource.targetRaySpace, space) : null;
        return p ? rayDir(p.transform.matrix) : null;
      };
      s.addEventListener('selectstart', function (e) {
        try {
          if (session !== s) return;
          if (held && held.timer) clearTimeout(held.timer);
          held = null;
          if (call('offline')) return;
          var d = rayOf(e), hit = d ? raySelect(call('tags') || [], d, 7) : null;
          var h = held = { t0: Date.now(), hit: hit, target: null, b: d ? bearingOf(d) : 0, timer: 0 };
          h.timer = setTimeout(function () {
            if (held !== h || session !== s) return;
            h.timer = 0;
            h.target = holdTarget(hit, call('present') || []);
            if (h.target) call('holdStart', h.target, { b: h.b });
          }, HOLD);
        } catch (x) { fail(); }
      });
      s.addEventListener('select', function () {
        try {
          var h = held;
          if (!h || h.target) return;
          if (h.timer) clearTimeout(h.timer);
          held = null;
          if (Date.now() - h.t0 < HOLD) call('tap', h.hit);
        } catch (x) { fail(); }
      });
      s.addEventListener('selectend', function () {
        try {
          var h = held;
          held = null;
          if (!h) return;
          if (h.timer) clearTimeout(h.timer);
          if (h.target) call('holdEnd', h.target);
        } catch (x) { fail(); }
      });

      call('start', L ? 'layers' : 'shader');
      var frame = function (t, f) {
        if (session !== s) return;
        try {
          s.requestAnimationFrame(frame);
          var pose = f.getViewerPose(space);
          if (!pose || !api.ready()) return;
          call('face', pose.transform.matrix);
          var i, view, vp;
          if (L) {
            if (L.dirty || L.eq.needsRedraw) {      // the room, once, into the compositor's own sphere
              var sub = L.bind.getSubImage(L.eq, f);
              gl.bindFramebuffer(gl.FRAMEBUFFER, L.fb);
              gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, sub.colorTexture, 0);
              vp = sub.viewport;
              gl.viewport(vp.x, vp.y, vp.width, vp.height);
              gl.useProgram(bprog);
              api.quad();
              gl.uniform4f(gl.getUniformLocation(bprog, 'vp'), vp.x, vp.y, vp.width, vp.height);
              gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
              L.dirty = false;
            }
            gl.bindFramebuffer(gl.FRAMEBUFFER, L.fb);
            for (i = 0; i < pose.views.length; i++) {
              view = pose.views[i];
              var vs = L.bind.getViewSubImage(L.proj, view);
              gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, vs.colorTexture, 0);
              vp = vs.viewport;
              gl.viewport(vp.x, vp.y, vp.width, vp.height);
              gl.clearColor(0, 0, 0, 0);
              gl.clear(gl.COLOR_BUFFER_BIT);
              drawTags(view);
            }
            return;
          }
          var layer = s.renderState.baseLayer;
          gl.bindFramebuffer(gl.FRAMEBUFFER, layer.framebuffer);
          for (i = 0; i < pose.views.length; i++) {
            view = pose.views[i]; vp = layer.getViewport(view);
            gl.viewport(vp.x, vp.y, vp.width, vp.height);
            gl.useProgram(xprog);
            api.quad();
            gl.uniform4f(gl.getUniformLocation(xprog, 'vp'), vp.x, vp.y, vp.width, vp.height);
            gl.uniformMatrix4fv(gl.getUniformLocation(xprog, 'm'), false, xrRay(view));
            gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
            drawTags(view);
          }
        } catch (e) { fail(); }
      };
      s.requestAnimationFrame(frame);
    }
    return {
      end: function () { if (session) fail(); },
      inside: function () { return !!session; }
    };
  }

  // THE SPEAKER, one per character line. ONE <audio> for the whole thread, so
  // two lines can never talk over each other, and a press on a second line
  // is a change of mind, not a duet. The line is asked for with a fetch first
  // and only then handed to the <audio>: a fetch can read speak.php's 404 and
  // the machine's own 503 sentence, an <audio> element can only say "error".
  // The second request is the engine's cache, or this browser's — a file
  // read either way, never a second render. Shown once and then the button
  // stands down: a line that cannot be spoken will not be spoken on a retry.
  var voiceAu = null, voiceBtn = null;
  function speak(btn) {
    if (!voiceAu) {
      voiceAu = document.createElement('audio');
      voiceAu.preload = 'none';
      // The element's own events arrive late (a pause fires after the next
      // press has begun), so they speak only for the button the element is
      // PLAYING FOR — voiceAu.forBtn, set when the src is handed over — and
      // never for whichever button happens to be newest.
      voiceAu.addEventListener('playing', function () {
        var b = voiceAu.forBtn; if (!b) return;
        b.classList.remove('loading'); b.classList.add('on'); b.setAttribute('aria-pressed', 'true');
      });
      var stop = function () { var b = voiceAu.forBtn; if (b) unspeak(b); };
      voiceAu.addEventListener('ended', stop);
      voiceAu.addEventListener('pause', stop);
      voiceAu.addEventListener('error', stop);
    }
    var again = voiceBtn === btn && (btn.classList.contains('on') || btn.classList.contains('loading'));
    if (voiceBtn) unspeak(voiceBtn);
    voiceAu.forBtn = null;
    voiceAu.pause();
    voiceBtn = null;
    if (again) return;                                // the second press is "stop"
    voiceBtn = btn;
    btn.classList.add('loading');
    var url = 'speak.php?w=' + encodeURIComponent(W) + '&m=' + encodeURIComponent(btn.dataset.m);
    fetch(url).then(function (r) {
      if (r.ok) return null;
      if (r.status === 404) return 'There is nothing in this line to hear.';
      return r.json().then(function (j) { return (j && j.error) || 'the voice machine did not answer'; },
                           function () { return 'the voice machine did not answer'; });
    }).then(function (why) {
      if (voiceBtn !== btn) return;                   // another line was pressed meanwhile
      if (why) { refuseSpeak(btn, why); return; }
      voiceAu.forBtn = btn;
      voiceAu.src = url;
      var p = voiceAu.play();
      if (p && p.catch) p.catch(function () { btn.classList.remove('loading'); });
    }).catch(function () { refuseSpeak(btn, 'the voice machine could not be reached'); });
  }
  function unspeak(b) { b.classList.remove('loading', 'on'); b.setAttribute('aria-pressed', 'false'); }
  function refuseSpeak(btn, why) {
    btn.classList.remove('loading', 'on');
    btn.disabled = true;
    var n = document.createElement('span');
    n.className = 'mspkno';
    n.textContent = why;
    btn.after(n);
    if (voiceBtn === btn) voiceBtn = null;
  }

  // -- hold to talk ------------------------------------------------------------
  // PRESS, SPEAK, LET GO, READ, SEND. The recording goes to hear.php and the
  // words come back into the box — never straight into the thread, because a
  // machine that misheard a name would otherwise put a line in the player's
  // mouth that they did not say. The microphone is asked for on the first
  // press and on no earlier one (so the prompt is never a surprise); the
  // tracks are stopped after every line, so the browser's recording light
  // means what it says. Thirty seconds at most, the server's own ceiling.
  // A refusal is shown once and the button stands down, the speaker's rule.
  // The page hands over the button and two things only it knows: whose
  // thread is open (the line is heard with that person's name in the prompt)
  // and how it says a refusal in the composer.
  function hold(hb, env) {
    if (!hb) return;
    var MAX_MS = 30000;
    var holding = false, rec = null, chunks = [], timer = null, began = 0, sending = false, asking = false;

    function pickType() {
      if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
      var c = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
      for (var i = 0; i < c.length; i++) if (MediaRecorder.isTypeSupported(c[i])) return c[i];
      return '';
    }
    function extOf(t) {
      t = String(t || '');
      if (t.indexOf('mp4') >= 0) return '.mp4';
      if (t.indexOf('ogg') >= 0) return '.ogg';
      return '.webm';
    }
    function lit(on) {
      hb.classList.toggle('on', on);
      hb.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    function refuseHear(why) {
      holding = false;
      lit(false);
      hb.classList.remove('loading');
      hb.disabled = true;
      env.fail(why || 'the ears machine did not answer');
    }
    function stopTracks(stream) {
      if (!stream) return;
      stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
    }

    function start() {
      if (hb.disabled || holding || rec || sending || asking || !env.handle()) return;
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
        refuseHear('This browser cannot record here, so the box takes typing only.');
        return;
      }
      holding = true;
      asking = true;
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        asking = false;
        // The first press is often spent answering the browser's question.
        // If the button was let go meanwhile, nothing is recorded: the next
        // press is the one that listens.
        if (!holding) { stopTracks(stream); return; }
        var type = pickType();
        try {
          rec = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);
        } catch (e) {
          stopTracks(stream);
          refuseHear('This browser cannot record here, so the box takes typing only.');
          return;
        }
        chunks = [];
        rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
        rec.onstop = function () {
          var r = rec; rec = null;
          stopTracks(stream);
          var blob = new Blob(chunks, { type: (r && r.mimeType) || type || 'audio/webm' });
          chunks = [];
          if (Date.now() - began < 350 || !blob.size) return;   // a tap, not a line
          send_(blob);
        };
        began = Date.now();
        rec.start();
        lit(true);
        timer = setTimeout(stop, MAX_MS);
      }, function () {
        asking = false;
        refuseHear('The microphone was not allowed, so the box takes typing only.');
      });
    }

    function stop() {
      holding = false;
      if (timer) { clearTimeout(timer); timer = null; }
      lit(false);
      if (rec && rec.state === 'recording') rec.stop();
    }

    function send_(blob) {
      sending = true;
      hb.classList.add('loading');
      var fd = new FormData();
      fd.append('w', W);
      fd.append('h', env.handle() || '');
      fd.append('audio', blob, 'line' + extOf(blob.type));
      fetch('hear.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; },
                                                   function () { return { ok: false, d: null }; }); })
        .then(function (res) {
          sending = false;
          hb.classList.remove('loading');
          if (!res.ok || !res.d || !res.d.ok) {
            refuseHear((res.d && res.d.error) || 'the ears machine did not answer');
            return;
          }
          var text = String(res.d.text || '').trim();
          if (!text) { env.fail('Nothing was heard. Hold the button while you talk.'); return; }
          var box = $('#composer');
          $('#sayerr').hidden = true;
          box.value = box.value.trim() ? (box.value.replace(/\s+$/, '') + ' ' + text) : text;
          box.dispatchEvent(new Event('input'));            // let it grow to fit
          box.focus();
          box.selectionStart = box.selectionEnd = box.value.length;
        })
        .catch(function () {
          sending = false;
          hb.classList.remove('loading');
          refuseHear('the ears machine could not be reached');
        });
    }

    hb.addEventListener('pointerdown', function (e) {
      if (e.button !== undefined && e.button !== 0) return;
      e.preventDefault();
      try { hb.setPointerCapture(e.pointerId); } catch (x) {}
      start();
    });
    hb.addEventListener('pointerup', stop);
    hb.addEventListener('pointercancel', stop);
    hb.addEventListener('lostpointercapture', function () { if (holding) stop(); });
    hb.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    // The keyboard holds it too: Space (or Enter) held while the button has
    // focus is the same as a finger on it.
    hb.addEventListener('keydown', function (e) {
      if (e.key !== ' ' && e.key !== 'Enter') return;
      e.preventDefault();
      if (!e.repeat) start();
    });
    hb.addEventListener('keyup', function (e) {
      if (e.key !== ' ' && e.key !== 'Enter') return;
      e.preventDefault();
      stop();
    });
    hb.addEventListener('blur', function () { if (holding) stop(); });
    hb.addEventListener('click', function (e) { e.preventDefault(); });
  }

  return {
    pano: pano, xr: xr, xrRay: xrRay, mat4inv: mat4inv, panoProgram: panoProgram,
    // The live view's: the WHEP client, the HLS question, the heartbeat.
    whep: whep, playsHls: playsHls, stream: stream, streamBeat: streamBeat,
    gaze: gaze, phoneGaze: phoneGaze, dayView: dayView,
    speak: speak, unspeak: unspeak, refuseSpeak: refuseSpeak, hold: hold,
    // The stand page's: the headset's tags, ray and hold, the voice's panner.
    standXR: standXR, tagPos: tagPos, tagQuad: tagQuad, rayDir: rayDir, bearingOf: bearingOf,
    raySelect: raySelect, holdTarget: holdTarget, viewProj: viewProj, tagCanvas: tagCanvas,
    panAt: panAt, listenerFace: listenerFace, offline: offline,
    // The view on screen: built over a .lookpanobox, thrown away before the
    // next one (its WebGL context goes back to the browser with it). opt is
    // pano()'s: the stand page's loader and hooks; play.php passes none.
    mount: function (box, opt) {
      // THE LIVE VIEW: mount({ kind: 'stream', box, onEnd }). The same live
      // view repainted keeps its element; anything else is a fresh one.
      if (box && box.kind === 'stream') {
        if (lookView && lookView.alive && lookView.streamId && lookView.streamId === box.box.dataset.stream) {
          lookView.move(box.box); return lookView;
        }
        if (lookView) lookView.destroy();
        lookView = stream(box.box, box); return lookView;
      }
      if (lookView) lookView.destroy();
      // A SPLAT is xeric-splat.js's, loaded beside this file and started only
      // here: mount({ kind: 'splat', canvas, url, onReady, onError }).
      if (box && box.kind === 'splat') {
        var XS = root.XericSplat;
        lookView = XS && box.canvas ? XS.mount(box.canvas, box.url || box.canvas.dataset.src,
                                               { onReady: box.onReady, onError: box.onError }) : null;
        return lookView;
      }
      lookView = box ? pano(box, opt) : null; return lookView;
    },
    unmount: function () { if (lookView) { lookView.destroy(); lookView = null; } },
    get lookView() { return lookView; },
    lookYaw: lookYaw,
    get voiceAu() { return voiceAu; }
  };
};
})(typeof window !== 'undefined' ? window : this);
