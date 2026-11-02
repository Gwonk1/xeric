<?php
/**
 * stand.php — stand in the room. A page that is nothing but the room, built
 * on the client API (api.php/v1, docs/API.md), for a headset first and any
 * browser second. docs/STAND.md is the person's guide; FUTURE.md Appendix C
 * is the plan this follows.
 *
 *     GET stand.php?w=<slug>            the page: a shell, no world in it
 *     GET stand.php?manifest&w=<slug>   its web app manifest (install it, and
 *                                       the icon opens straight into the room)
 *
 * A SHELL WITH NO WORLD DATA IN IT. Every response carries a fresh nonce, so
 * a page with the world printed into it could never be cached — and the
 * service worker (stand-sw.php) must be able to keep this page for when the
 * box is unreachable. So nothing here opens a world, reads a session or
 * names a slug: the page reads `w` from its own address and asks api.php for
 * everything, with whichever identity it has — a Bearer token it was given
 * by `POST link`, the session cookie, or (on this machine) solo. A source
 * check in tests/stand-test.php holds that line.
 *
 * WHAT IT DOES. In 2D, the panorama full-screen with the people as buttons at
 * their bearings (xeric-view.js's pano, the play view's own); the pulse drives
 * repaint (a conditional poll: 304 when nothing moved); the room's sound
 * behind a listen toggle; the room strip when a table is live. Step inside
 * (WebXR) draws the people as name quads in the headset, a ray picks them,
 * a tap focuses and a hold talks; the reply comes back from where the speaker
 * stands (an HRTF panner at their bearing) with the room's sound ducked under.
 *
 * ONE DIFFERENCE FROM play.php'S HOLD TO TALK. There, what was heard is put
 * into the box and the player presses send — a misheard name never becomes a
 * line in their mouth. A headset has no box and no keyboard, so here the
 * transcript is shown as a caption for a second and a half with a cancel
 * (a tap, or the button) and then said by the page itself.
 *
 * THE SECURE-CONTEXT RULE. WebXR, the microphone, service workers and install
 * all need a secure context; `--lan` serves plain http. On plain http to
 * anything but this machine the page says so in one line, names the setups
 * that work, and offers neither step inside nor hold to talk.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

// ---------------------------------------------------------------------------
// The manifest. The slug is the request's own (start_url opens that world);
// nothing about the world is read.
// ---------------------------------------------------------------------------
if (isset($_GET['manifest'])) {
    $slug  = xeric_web_slug((string)($_GET['w'] ?? ''));
    $start = 'stand.php' . ($slug !== '' ? '?w=' . rawurlencode($slug) : '');
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'name'             => 'Xeric — stand in it',
        'short_name'       => 'Xeric',
        'description'      => 'Stand in a room of a world that keeps its own time.',
        'id'               => $start,
        'start_url'        => $start,
        'scope'            => 'stand.php',
        'display'          => 'standalone',
        'orientation'      => 'any',
        'background_color' => '#1d1b18',
        'theme_color'      => '#1d1b18',
        'icons'            => [['src' => 'stand-icon.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

xeric_web_head('Xeric: stand', ['stand' => true]);
?>
<style>
html,body{height:100%}
body{margin:0;background:#1d1b18;color:#f7f6f2;overflow:hidden}
#stand{position:fixed;inset:0;display:flex;flex-direction:column;max-width:none;margin:0;padding:0}
/* an author display: rule outranks the UA's [hidden]{display:none}, so say it here */
#stand [hidden]{display:none !important}
#stand .sbar{display:flex;align-items:center;gap:.5rem;padding:.45rem .7rem;font-size:.85rem;
  background:rgba(29,27,24,.85);z-index:3;flex-wrap:wrap}
#stand .sbar .place{font-weight:600;margin-right:auto}
#stand .sbar button,#stand .ctl button{font:inherit;font-size:.85rem;padding:.35rem .7rem;border-radius:999px;
  border:1px solid rgba(247,246,242,.45);background:transparent;color:inherit;cursor:pointer}
#stand button[aria-pressed="true"]{background:#f7f6f2;color:#1d1b18}
#stand button:disabled{opacity:.35;cursor:default}
#banner{margin:0;padding:.45rem .7rem;font-size:.8rem;line-height:1.4;background:#5a2e1e;color:#f7f6f2;z-index:3}
#banner code{font-size:.78rem}
#view{position:relative;flex:1;min-height:0}
#view .lookpanobox,#view .lookstill{position:absolute;inset:0}
#view canvas.lookpano,#view canvas.standstill{display:block;width:100%;height:100%;cursor:grab;touch-action:none;background:#26231f}
#view canvas.lookpano.grabbing{cursor:grabbing}
#view .looktags .looktag{position:absolute;transform:translate(-50%,-50%)}
#view .looktagrow{position:absolute;left:0;right:0;bottom:4.2rem;display:flex;flex-wrap:wrap;gap:.35rem;justify-content:center}
#view .looktag{font:inherit;font-size:.85rem;padding:.3rem .75rem;border-radius:999px;border:1px solid rgba(247,246,242,.55);
  background:rgba(29,27,24,.72);color:#f7f6f2;cursor:pointer;white-space:nowrap;touch-action:none;user-select:none}
#view .looktag.focus{background:#f7f6f2;color:#1d1b18}
#view .looktag.unread::after{content:"";display:inline-block;width:.45rem;height:.45rem;border-radius:50%;
  background:#d98a4e;margin-left:.35rem;vertical-align:middle}
#view .looktag.talking{outline:2px solid #d98a4e}
#view .lookxr,#view .lookgaze{font:inherit;font-size:.85rem;padding:.4rem .8rem;border-radius:999px;cursor:pointer;
  border:1px solid rgba(247,246,242,.55);background:rgba(29,27,24,.72);color:#f7f6f2}
#view .lookgaze.on{background:#f7f6f2;color:#1d1b18}
#view .lookxr{position:absolute;right:.7rem;bottom:.7rem;z-index:2}
#view .lookgaze{position:absolute;left:.7rem;bottom:.7rem;z-index:2}
body:not(.entered) #view .lookxr,body.insecure #view .lookxr{display:none}
#empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:2rem;
  font-size:.95rem;opacity:.8;line-height:1.5}
.lookearlier{position:absolute;left:50%;top:.7rem;transform:translateX(-50%);z-index:2;font-size:.8rem;
  padding:.15rem .6rem;border-radius:999px;background:#f7f6f2;color:#1d1b18}
#caption{position:absolute;left:50%;bottom:6.5rem;transform:translateX(-50%);max-width:min(40rem,90vw);z-index:2;
  padding:.5rem .9rem;border-radius:.6rem;background:rgba(29,27,24,.88);font-size:.95rem;line-height:1.4;text-align:center}
#caption button{margin-left:.6rem}
#room{position:absolute;left:.7rem;top:.7rem;max-width:min(24rem,70vw);z-index:2;font-size:.8rem;line-height:1.4;
  padding:.4rem .6rem;border-radius:.5rem;background:rgba(29,27,24,.8)}
#room p{margin:.1rem 0}
#room .who{font-weight:600}
#thread{position:absolute;right:.7rem;top:.7rem;max-width:min(22rem,60vw);z-index:2;font-size:.8rem;line-height:1.4;
  padding:.4rem .6rem;border-radius:.5rem;background:rgba(29,27,24,.8)}
#thread p{margin:.1rem 0}
.ctl{position:absolute;left:0;right:0;bottom:0;display:flex;justify-content:center;gap:.5rem;padding:.7rem;z-index:2;flex-wrap:wrap}
#talk{min-width:12rem;padding:.6rem 1rem;touch-action:none;user-select:none}
#talk.on{background:#d98a4e;color:#1d1b18;border-color:#d98a4e}
body.insecure #talk{display:none}
#linkbox{position:absolute;inset:0;z-index:4;display:flex;align-items:center;justify-content:center;background:rgba(29,27,24,.94)}
#linkbox form{max-width:24rem;width:100%;padding:1.2rem;display:flex;flex-direction:column;gap:.5rem}
#linkbox input{font:inherit;font-size:1.3rem;letter-spacing:.15em;padding:.55rem .7rem;border-radius:.4rem;
  border:1px solid rgba(247,246,242,.55);background:transparent;color:inherit;text-transform:uppercase}
#linkbox p{margin:0;font-size:.85rem;line-height:1.5;opacity:.85}
#linkbox button{font:inherit;padding:.55rem .9rem;border-radius:999px;border:1px solid #f7f6f2;background:#f7f6f2;color:#1d1b18}
#linkbox .why{font-size:.78rem;opacity:.75}
#note{margin:0;padding:.3rem .7rem;font-size:.8rem;opacity:.85;z-index:3;min-height:1.1rem}
#worlds a{color:inherit}
</style>
<main id="stand" aria-label="The room">
  <p id="banner" hidden></p>
  <div class="sbar">
    <span class="place" id="place">…</span>
    <span id="clock"></span>
    <button type="button" id="enter">come in</button>
    <button type="button" id="listen" aria-pressed="false" hidden>sound</button>
    <button type="button" id="relink">link</button>
  </div>
  <div id="view">
    <div id="empty"><p>Opening the room…</p></div>
    <p class="lookearlier" hidden></p>
    <div id="room" hidden aria-live="polite"></div>
    <div id="thread" hidden></div>
    <div id="caption" hidden aria-live="assertive"><span id="captext"></span><button type="button" id="capcancel" hidden>cancel</button></div>
    <div class="ctl">
      <button type="button" id="talk" data-verb="talk" disabled>hold to talk</button>
    </div>
    <div id="linkbox" hidden>
      <form id="linkform" autocomplete="off">
        <h2 style="margin:0 0 .3rem">Link this device</h2>
        <p>On the machine Xeric runs on, open <b>link.php</b>, press <i>Make a code</i>, and type the
          eight characters here within five minutes.</p>
        <label for="code" class="why">The code</label>
        <input id="code" name="code" maxlength="12" inputmode="text" autocapitalize="characters" spellcheck="false" required>
        <label for="label" class="why">What to call this device</label>
        <input id="label" name="label" maxlength="60" placeholder="the headset" style="font-size:1rem;letter-spacing:0;text-transform:none">
        <button type="submit">link</button>
        <p class="why" id="keepwhy"></p>
        <p class="why" id="linkerr" role="alert"></p>
        <p id="worlds" class="why"></p>
        <button type="button" id="linkclose" style="background:transparent;color:inherit">not now</button>
      </form>
    </div>
  </div>
  <p id="note" role="status"></p>
</main>
<script nonce="<?= h(xeric_web_nonce()) ?>" src="<?= h(xeric_play_asset('xeric-view.js')) ?>"></script>
<script nonce="<?= h(xeric_web_nonce()) ?>">
(function () {
  'use strict';
  // EVERYTHING BELOW READS THE WORLD FROM api.php, never from this file: the
  // page is a shell the service worker may keep (see the header comment).
  var $ = function (s) { return document.querySelector(s); };
  var noop = function () {};
  var qs = new URLSearchParams(location.search);
  var W = String(qs.get('w') || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
  var API = 'api.php/v1/';
  var WP = 'worlds/' + encodeURIComponent(W) + '/';
  var HOST = location.hostname.replace(/^\[|\]$/g, '');
  var LOOP = /^127\.\d+\.\d+\.\d+$/.test(HOST) || HOST === 'localhost' || HOST === '::1';
  var SECURE = !!window.isSecureContext;
  // A KEY KEPT IN THE BROWSER is only as safe as the page that can read it:
  // over plain http to another machine anything on the network could rewrite
  // this page and read localStorage. So a token is stored only on a secure
  // context or on this machine; otherwise it lives in memory until the page
  // closes, and the page says so.
  var KEEP = SECURE || LOOP;
  var TK = 'xeric.stand.token', LAST = 'xeric.stand.last.' + W;
  var token = null;
  try { if (KEEP) token = localStorage.getItem(TK) || null; } catch (e) { token = null; }

  var XV = window.XericView({ world: W });
  var S = {
    features: {}, name: '', place: '', where: null, here: [], who: [], unread: {}, cursor: '',
    look: null, viewSrc: '', pending: null, focus: null, inside: false, offline: false,
    room: null, roomLast: 0, roomConv: 0, roomRows: [], roomSaid: {},
    entered: false, listen: false, ctx: null, amb: null, speech: null, ambSrc: '', ambNode: null,
    stream: null, rec: null, recTimer: 0, busy: false, cap: null, faceB: 0, voiceQ: Promise.resolve(),
    timer: 0, fails: 0, pics: {}
  };

  function note(t) { $('#note').textContent = t || ''; }

  // -- the network ----------------------------------------------------------
  function auth(h) {
    h = h || {};
    if (token) h.Authorization = 'Bearer ' + token;
    return h;
  }
  // {status, ok, d} — d is the envelope, or null. A network failure rejects.
  function api(path, o) {
    o = o || {};
    var init = { method: o.method || 'GET', headers: auth(o.headers), credentials: 'same-origin', cache: 'no-store' };
    if (o.json !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(o.json); }
    else if (o.form) init.body = o.form;
    return fetch(API + path, init).then(function (r) {
      if (r.status === 304) return { status: 304, ok: true, d: null, r: r };
      return r.json().then(function (d) { return { status: r.status, ok: r.ok && d && d.ok, d: d, r: r }; },
                           function () { return { status: r.status, ok: false, d: null, r: r }; });
    });
  }
  function why(res, fallback) { return (res && res.d && res.d.error) || fallback; }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  // A panorama (or a sound) behind the token: fetched, then decoded without
  // ever becoming a blob: URL — createImageBitmap for pictures, so the CSP
  // needs nothing it does not already say. Kept per address: a repaint with
  // the same room does not fetch it again.
  function loadPic(src) {
    if (S.pics[src]) return S.pics[src];
    var p = fetch(src, { headers: auth(), credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('no picture');
      return r.blob();
    }).then(function (b) {
      if (window.createImageBitmap) return createImageBitmap(b);
      return new Promise(function (res, rej) {        // older Safari: a data: URL, which img-src allows
        var fr = new FileReader();
        fr.onload = function () { var im = new Image(); im.onload = function () { res(im); }; im.onerror = rej; im.src = fr.result; };
        fr.onerror = rej;
        fr.readAsDataURL(b);
      });
    });
    p.catch(function () { delete S.pics[src]; });
    var keys = Object.keys(S.pics);
    if (keys.length > 3) delete S.pics[keys[0]];
    S.pics[src] = p;
    return p;
  }
  function bytes(src, method) {
    return fetch(src, { method: method || 'GET', headers: auth(), credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) {
        return r.json().then(function (d) { var e = new Error((d && d.error) || 'no sound'); e.kind = d && d.kind; e.status = r.status; throw e; },
                             function () { var e = new Error('no sound'); e.status = r.status; throw e; });
      }
      return r.arrayBuffer();
    });
  }

  // -- the secure-context line --------------------------------------------------
  var port = location.port || '8787';
  if (!SECURE && !LOOP) {
    document.body.classList.add('insecure');
    var b = $('#banner');
    b.hidden = false;
    b.innerHTML = '';
    b.appendChild(document.createTextNode('Plain http over the network: no headset and no microphone here (both need a secure context). '
      + 'No TLS needed with Quest Link and desktop Chrome at http://127.0.0.1:' + port
      + ', or a Quest in developer mode with '));
    var c1 = document.createElement('code'); c1.textContent = 'adb reverse tcp:' + port + ' tcp:' + port; b.appendChild(c1);
    b.appendChild(document.createTextNode('; anything else: '));
    var c2 = document.createElement('code'); c2.textContent = 'tailscale serve --bg http://127.0.0.1:' + port; b.appendChild(c2);
    b.appendChild(document.createTextNode(' on the box, and open the https address it prints.'));
  }
  $('#keepwhy').textContent = KEEP
    ? 'The key this hands back is kept in this browser, which is safe here: the page is on a secure connection or on this machine.'
    : 'This page is plain http over the network, so anything on the way could rewrite it and read a key stored in the browser. '
      + 'The key is kept in memory only, and you will link again when the page closes.';

  // -- installable, and kept for when the box is away --------------------------
  (function () {
    var l = document.createElement('link');
    l.rel = 'manifest';
    l.href = 'stand.php?manifest' + (W ? '&w=' + encodeURIComponent(W) : '');
    document.head.appendChild(l);
    if (SECURE && navigator.serviceWorker && navigator.serviceWorker.register) {
      try { navigator.serviceWorker.register('stand-sw.php', { scope: 'stand.php' }).catch(noop); } catch (e) {}
    }
  })();

  // -- linking ---------------------------------------------------------------
  function showLink(msg) {
    $('#linkbox').hidden = false;
    $('#linkerr').textContent = msg || '';
    var m = /(?:^|[#&])link=([A-Za-z0-9-]+)/.exec(location.hash || '');
    if (m && !$('#code').value) $('#code').value = m[1];
    try { $('#code').focus(); } catch (e) {}
  }
  $('#relink').addEventListener('click', function () {
    if (token) {
      token = null;
      try { localStorage.removeItem(TK); } catch (e) {}
      note('This device has forgotten its key. Link it again, or carry on as this browser.');
    }
    showLink('');
  });
  $('#linkclose').addEventListener('click', function () { $('#linkbox').hidden = true; });
  $('#linkform').addEventListener('submit', function (e) {
    e.preventDefault();
    var code = $('#code').value.trim();
    if (!code) return;
    $('#linkerr').textContent = 'linking…';
    // No Authorization on the ceremony: the code is the credential.
    fetch(API + 'link', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ code: code, label: $('#label').value.trim() || 'the headset' }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok || !d.token) { $('#linkerr').textContent = (d && d.error) || 'That did not link.'; return; }
        token = d.token;
        if (KEEP) { try { localStorage.setItem(TK, token); } catch (x) {} }
        if (location.hash) history.replaceState(null, '', location.pathname + location.search);
        $('#code').value = '';
        $('#linkbox').hidden = true;
        note(KEEP ? 'Linked. This device is you now.' : 'Linked, until this page closes (plain http: the key is not stored).');
        start();
      })
      .catch(function () { $('#linkerr').textContent = 'The machine could not be reached.'; });
  });

  // -- the people ------------------------------------------------------------
  function bearingOf(h) {
    for (var i = 0; i < S.who.length; i++) if (S.who[i].h === h) return S.who[i].b;
    return S.faceB;
  }
  function nameOf(h) {
    for (var i = 0; i < S.who.length; i++) if (S.who[i].h === h) return S.who[i].name;
    return h;
  }
  function tagButton(p) {
    var t = document.createElement('button');
    t.type = 'button'; t.className = 'looktag'; t.textContent = p.name;
    t.dataset.h = p.h; t.dataset.b = String(p.b); t.dataset.verb = 'focus';
    t.setAttribute('aria-label', p.name + (p.doing ? ', ' + p.doing : '') + '. Press to turn to them; hold to talk.');
    // A press is a focus, a hold is a line: the same as the headset's ray.
    var down = 0, held = false, tm = 0;
    t.addEventListener('pointerdown', function (e) {
      if (t.disabled || (e.button !== undefined && e.button !== 0)) return;
      e.preventDefault();
      try { t.setPointerCapture(e.pointerId); } catch (x) {}
      down = Date.now(); held = false;
      tm = setTimeout(function () {
        tm = 0;
        if (!S.stream || document.body.classList.contains('insecure')) return;
        held = true; t.classList.add('talking'); talkStart({ kind: 'person', h: p.h, b: p.b, name: p.name });
      }, 350);
    });
    var up = function () {
      if (!down) return;
      if (tm) { clearTimeout(tm); tm = 0; }
      down = 0;
      if (held) { held = false; t.classList.remove('talking'); talkEnd(); return; }
      focus(p.h);
    };
    t.addEventListener('pointerup', up);
    t.addEventListener('pointercancel', function () { if (tm) clearTimeout(tm); tm = 0; down = 0; if (held) { held = false; t.classList.remove('talking'); talkEnd(); } });
    t.addEventListener('click', function (e) { if (e.detail === 0) focus(p.h); });  // the keyboard
    t.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    return t;
  }
  function paintTags() {
    $$all('#view .looktag').forEach(function (t) {
      t.classList.toggle('focus', t.dataset.h === S.focus);
      t.classList.toggle('unread', !!S.unread[t.dataset.h]);
      t.setAttribute('aria-pressed', t.dataset.h === S.focus ? 'true' : 'false');
    });
    paintTalk();
  }
  function $$all(s) { return Array.prototype.slice.call(document.querySelectorAll(s)); }

  function focus(h) {
    if (S.offline) return;
    S.focus = h;
    paintTags();
    api(WP + 'threads/' + encodeURIComponent(h) + '/read', { method: 'POST' }).then(function (res) {
      if (res.ok) { delete S.unread[h]; paintTags(); }
    }).catch(noop);
    api(WP + 'threads/' + encodeURIComponent(h)).then(function (res) {
      if (!res.ok || S.focus !== h) return;
      var box = $('#thread'), ms = (res.d.messages || []).slice(-3);
      box.innerHTML = '';
      var head = document.createElement('p'); head.className = 'who'; head.textContent = res.d.name || nameOf(h);
      box.appendChild(head);
      ms.forEach(function (m) {
        var p = document.createElement('p');
        p.textContent = (m.role === 'user' ? 'you: ' : '') + m.text;
        box.appendChild(p);
      });
      box.hidden = false;
    }).catch(noop);
  }

  // -- the view --------------------------------------------------------------
  // The headset's hooks: what standXR draws, picks and hands back.
  var hooks = {
    tags: function () { return S.who.map(function (p) { return { h: p.h, name: p.name, b: p.b, focus: p.h === S.focus }; }); },
    present: function () { return S.here; },
    caption: function () { return S.cap ? { text: S.cap.text, b: S.cap.b } : null; },
    can: function () { return S.entered; },
    offline: function () { return S.offline; },
    tap: function (hit) {
      if (S.cap && S.cap.cancel) { S.cap.cancel(); return; }
      if (hit) focus(hit.h);
    },
    holdStart: function (t, o) { talkStart(t, o && o.b); },
    holdEnd: function () { talkEnd(); },
    face: function (m) {
      var f = XV.rayDir(m);
      S.faceB = XV.bearingOf(f);
      if (S.ctx) XV.listenerFace(S.ctx, f, [m[4], m[5], m[6]]);
    },
    start: function (mode) { S.inside = true; note(mode === 'layers' ? 'Inside (the room on a layer).' : 'Inside.'); },
    end: function () {
      S.inside = false; note('');
      if (S.pending) { var p = S.pending; S.pending = null; paintView(p); }
    }
  };

  function paintView(look) {
    var scene = look && look.scene, view = $('#view');
    var empty = $('#empty');
    if (XV.lookView && XV.lookView.inside && (!scene || scene.src !== S.viewSrc)) { S.pending = look; return; }
    if (scene && scene.src === S.viewSrc && view.querySelector('.lookmain')) { retag(); return; }
    XV.unmount();
    $$all('#view .lookmain').forEach(function (x) { x.remove(); });
    S.viewSrc = scene ? scene.src : '';
    if (!scene) {
      empty.hidden = false;
      empty.innerHTML = '';
      var p = document.createElement('p');
      p.textContent = S.where === null && !(look && look.brief)
        ? 'You are out on the town, not in a room.'
        : (look && look.pending ? 'The room is being drawn.' : 'Nothing has drawn this room yet.');
      empty.appendChild(p);
      var row = document.createElement('div'); row.className = 'lookmain looktagrow';
      S.who.forEach(function (w) { row.appendChild(tagButton(w)); });
      view.insertBefore(row, $('#caption'));
      paintTags();
      return;
    }
    empty.hidden = true;
    var box = document.createElement('div');
    if (scene.kind === 'pano') {
      box.className = 'lookmain lookpanobox';
      box.dataset.src = scene.src;
      box.dataset.alt = (look.brief && look.brief.name) || 'the room';
      var cv = document.createElement('canvas');
      cv.className = 'lookpano'; cv.tabIndex = 0; cv.setAttribute('role', 'img');
      cv.setAttribute('aria-label', 'A look around ' + box.dataset.alt + '. Drag, or use the arrow keys, to turn.');
      box.appendChild(cv);
      var tags = document.createElement('div'); tags.className = 'looktags';
      S.who.forEach(function (w) { var t = tagButton(w); t.hidden = true; tags.appendChild(t); });
      box.appendChild(tags);
      view.insertBefore(box, $('#caption'));
      XV.mount(box, { load: loadPic, stand: hooks });
    } else {
      // A still: drawn to fit, the people as a row under it.
      box.className = 'lookmain lookstill';
      var sc = document.createElement('canvas'); sc.className = 'standstill';
      box.appendChild(sc);
      var row2 = document.createElement('div'); row2.className = 'looktagrow';
      S.who.forEach(function (w) { row2.appendChild(tagButton(w)); });
      box.appendChild(row2);
      view.insertBefore(box, $('#caption'));
      loadPic(scene.src).then(function (bm) {
        var g = sc.getContext('2d'); if (!g) return;
        sc.width = sc.clientWidth || 800; sc.height = sc.clientHeight || 450;
        var k = Math.min(sc.width / bm.width, sc.height / bm.height), w = bm.width * k, h = bm.height * k;
        g.drawImage(bm, (sc.width - w) / 2, (sc.height - h) / 2, w, h);
      }).catch(noop);
    }
    paintTags();
  }
  // The same room, different people: rebuild the buttons, keep the view.
  function retag() {
    var wrap = document.querySelector('#view .looktags, #view .looktagrow');
    if (!wrap) return;
    var pano = wrap.classList.contains('looktags');
    wrap.innerHTML = '';
    S.who.forEach(function (w) { var t = tagButton(w); if (pano) t.hidden = true; wrap.appendChild(t); });
    if (pano && XV.lookView && XV.lookView.retag) XV.lookView.retag();
    paintTags();
  }

  function takeLook(d) {
    S.look = d;
    var br = d.brief;
    S.place = br ? (br.name || '') : '';
    S.who = br && br.who ? br.who.map(function (p) {
      return { h: p.handle, name: p.name, b: +p.bearing || 0, doing: p.doing || '' };
    }) : [];
    if (S.focus && !S.who.some(function (p) { return p.h === S.focus; })) { S.focus = null; $('#thread').hidden = true; }
    $('#place').textContent = (S.name ? S.name + ' · ' : '') + (S.place || (S.where ? '' : 'out on the town'));
    paintView(d);
    try {
      if (d.scene && d.scene.kind === 'pano') localStorage.setItem(LAST, JSON.stringify({ src: d.scene.src, alt: S.place }));
    } catch (e) {}
    playAmbient();
  }

  // -- offline: the last room, earlier, and nothing said ------------------------
  function goOffline() {
    if (S.offline) return;
    S.offline = true;
    note('The machine cannot be reached. This is the room as it was; nothing can be said until it is back.');
    var last = null;
    try { last = JSON.parse(localStorage.getItem(LAST) || 'null'); } catch (e) { last = null; }
    if (last && last.src && last.src !== S.viewSrc) {
      paintView({ scene: { src: last.src, kind: 'pano' }, brief: { name: last.alt } });
    }
    XV.offline($('#stand'), true);
    paintTalk();
  }
  function goOnline() {
    if (!S.offline) return;
    S.offline = false;
    XV.offline($('#stand'), false);
    note('');
    paintTalk();
  }

  // -- the pulse ---------------------------------------------------------------
  // A conditional short poll: the cursor is the ETag, an unchanged world is a
  // 304 in milliseconds, and only a change fetches what is shown. Never while
  // the tab is hidden (a headset taken off is a hidden tab).
  function pulse() {
    clearTimeout(S.timer);
    if (!W) return;
    if (document.hidden && !S.inside) { S.timer = setTimeout(pulse, 4000); return; }
    var h = auth();
    if (S.cursor) h['If-None-Match'] = '"' + S.cursor + '"';
    fetch(API + WP + 'pulse', { headers: h, credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
      S.fails = 0;
      goOnline();
      if (r.status === 304) return null;
      return r.json().then(function (d) {
        if (!r.ok || !d || !d.ok) { stopped(r.status, d); return null; }
        return changed(d);
      });
    }).catch(function () {
      if (++S.fails >= 2) goOffline();
    }).then(function () {
      S.timer = setTimeout(pulse, S.offline ? 8000 : (S.room ? 1200 : 2500));
    });
  }
  function changed(p) {
    var moved = p.where !== S.where, first = !S.cursor;
    var hereNow = (p.here || []).slice().sort().join(','), hereWas = S.here.slice().sort().join(',');
    var drawn = S.look === null || !S.lastScenes || p.scenes.latest !== S.lastScenes.latest || p.scenes.pending !== S.lastScenes.pending;
    S.cursor = p.cursor; S.where = p.where; S.here = p.here || []; S.lastScenes = p.scenes;
    S.unread = {};
    (p.unread || []).forEach(function (u) { if (u.n > 0) S.unread[u.handle] = u.n; });
    paintTags();
    var jobs = [];
    if (first || moved) jobs.push(world());
    if (first || moved || drawn || hereNow !== hereWas) jobs.push(api(WP + 'look').then(function (res) { if (res.ok) takeLook(res.d); }));
    S.room = p.room || null;
    if (S.room || S.roomRows.length) jobs.push(roomPoll());
    return Promise.all(jobs).catch(noop);
  }
  function stopped(status, d) {
    var kind = d && d.kind;
    if (status === 401) {
      token = null;
      try { localStorage.removeItem(TK); } catch (e) {}
      showLink('This device\'s key is not known any more (unlinked, or the session it belonged to has gone). Link it again.');
    } else if (status === 403 && !token) {
      showLink(why({ d: d }, 'This machine does not know this browser.') + ' Link this device to play as yourself.');
    } else {
      note(why({ d: d }, kind === 'no_world' ? 'There is no such world here.' : 'That world did not open.'));
    }
  }
  function world() {
    return api(WP.replace(/\/$/, '')).then(function (res) {
      if (!res.ok) { stopped(res.status, res.d); return; }
      var c = res.d.clock || {};
      $('#clock').textContent = c.hhmm ? c.hhmm + (c.paused ? ' (stopped)' : '') : '';
    });
  }

  // -- the room ----------------------------------------------------------------
  function roomPoll() {
    var q = 'room?after=' + S.roomLast + (S.roomConv ? '&c=' + S.roomConv : '');
    return api(WP + q).then(function (res) {
      if (!res.ok) return;
      var d = res.d, box = $('#room');
      if (!d.live && !(d.rows || []).length) { S.roomRows = []; box.hidden = true; return; }
      if (d.conv && d.conv !== S.roomConv) { S.roomConv = d.conv; S.roomRows = []; }
      (d.rows || []).forEach(function (row) {
        S.roomRows.push(row);
        if (row.h && !S.roomSaid[row.id]) { S.roomSaid[row.id] = 1; speakLine(row.id, bearingOf(row.h)); }
      });
      S.roomLast = Math.max(S.roomLast, d.last || 0);
      S.roomRows = S.roomRows.slice(-4);
      box.innerHTML = '';
      S.roomRows.forEach(function (row) {
        var p = document.createElement('p'), w = document.createElement('span');
        w.className = 'who'; w.textContent = (row.name || row.who || 'you') + ': ';
        p.appendChild(w); p.appendChild(document.createTextNode(row.text || ''));
        box.appendChild(p);
      });
      box.hidden = !S.roomRows.length;
      if (!d.live) S.room = null;
    });
  }

  // -- sound -------------------------------------------------------------------
  // ONE AudioContext, made and resumed by the click on "come in" — a browser
  // will not make sound before a person has pressed something, and a headset
  // session cannot ask. The room's sound loops on its own gain, which ducks
  // while you talk and while somebody answers.
  function decode(buf) {
    return new Promise(function (res, rej) {
      var p = S.ctx.decodeAudioData(buf, res, rej);
      if (p && p.then) p.then(res, rej);
    });
  }
  function duck(on) {
    if (!S.amb) return;
    try { S.amb.gain.setTargetAtTime(on ? 0.2 : 1, S.ctx.currentTime, 0.12); } catch (e) { S.amb.gain.value = on ? 0.2 : 1; }
  }
  function stopAmbient() {
    if (S.ambNode) { try { S.ambNode.stop(); } catch (e) {} S.ambNode = null; }
    S.ambSrc = '';
  }
  function playAmbient() {
    var snd = S.look && S.look.sound;
    if (!S.ctx || !S.listen || !snd || !snd.src) { stopAmbient(); return; }
    if (S.ambSrc === snd.src) return;
    stopAmbient();
    var src = S.ambSrc = snd.src;
    bytes(src).then(decode).then(function (ab) {
      if (S.ambSrc !== src || !S.listen) return;
      var n = S.ctx.createBufferSource();
      n.buffer = ab; n.loop = true; n.connect(S.amb); n.start(0);
      S.ambNode = n;
    }).catch(noop);
  }
  // A line out loud, at the speaker's bearing: the cached clip, or (a miss)
  // rendered once by the voice machine. One at a time, in order.
  function speakLine(id, b) {
    if (!S.ctx || !S.listen || !id) return S.voiceQ;
    var url = API + WP + 'messages/' + encodeURIComponent(id) + '/audio';
    S.voiceQ = S.voiceQ.then(function () {
      return bytes(url).catch(function (e) {
        if (e && e.kind === 'not_rendered' && S.features.voice) return bytes(url, 'POST');
        throw e;
      }).then(decode).then(function (ab) {
        if (!S.inside && XV.lookView && XV.lookView.view) {
          var y = XV.lookView.view.yaw;
          XV.listenerFace(S.ctx, [-Math.sin(y), 0, -Math.cos(y)]);
        }
        return new Promise(function (res) {
          var n = S.ctx.createBufferSource(), p = XV.panAt(S.ctx, b, S.speech);
          n.buffer = ab; n.connect(p);
          duck(true);
          n.onended = function () { duck(false); try { p.disconnect(); } catch (x) {} res(); };
          n.start(0);
        });
      }).catch(noop);
    });
    return S.voiceQ;
  }

  function enter() {
    if (!S.entered) {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (AC) {
        try {
          S.ctx = new AC();
          S.amb = S.ctx.createGain(); S.amb.connect(S.ctx.destination);
          S.speech = S.ctx.createGain(); S.speech.connect(S.ctx.destination);
        } catch (e) { S.ctx = null; }
      }
      S.entered = true;
      document.body.classList.add('entered');
      $('#enter').hidden = true;
      $('#listen').hidden = false;
      // THE MICROPHONE IS ASKED FOR HERE, on the 2D page, before any headset
      // session: a permission prompt cannot be answered from inside one. The
      // stream is kept and its track switched on only while you hold.
      if (SECURE && S.features.ears && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder) {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (st) {
          S.stream = st;
          st.getAudioTracks().forEach(function (t) { t.enabled = false; });
          paintTalk();
        }, function () { note('The microphone was not allowed, so holding to talk is off.'); paintTalk(); });
      } else if (!S.features.ears) {
        note('No ears machine is set on the box, so holding to talk is off. The machines screen sets one.');
      }
    }
    if (S.ctx && S.ctx.resume) S.ctx.resume().catch(noop);
    setListen(true);
  }
  function setListen(on) {
    S.listen = !!on && !!S.ctx;
    $('#listen').setAttribute('aria-pressed', S.listen ? 'true' : 'false');
    $('#listen').textContent = S.listen ? 'sound on' : 'sound off';
    if (S.listen) playAmbient(); else stopAmbient();
  }
  $('#enter').addEventListener('click', enter);
  $('#listen').addEventListener('click', function () { setListen(!S.listen); });

  // -- hold to talk --------------------------------------------------------------
  function talkTarget() {
    if (S.focus && S.who.some(function (p) { return p.h === S.focus; })) {
      return XV.holdTarget({ h: S.focus, b: bearingOf(S.focus), name: nameOf(S.focus) }, S.here);
    }
    return XV.holdTarget(null, S.here);
  }
  function paintTalk() {
    var b = $('#talk'), t = talkTarget();
    b.textContent = S.rec ? 'listening… let go to send'
      : !t ? 'hold to talk (turn to somebody first)'
      : t.kind === 'room' ? 'hold to talk to the room' : 'hold to talk to ' + t.name;
    b.disabled = S.offline || !S.stream || !t || S.busy;
    b.classList.toggle('on', !!S.rec);
  }
  (function () {
    var b = $('#talk');
    var go = function (e) { if (e) e.preventDefault(); var t = talkTarget(); if (t && !b.disabled) talkStart(t); };
    b.addEventListener('pointerdown', function (e) { try { b.setPointerCapture(e.pointerId); } catch (x) {} go(e); });
    b.addEventListener('pointerup', talkEnd);
    b.addEventListener('pointercancel', talkEnd);
    b.addEventListener('keydown', function (e) { if ((e.key === ' ' || e.key === 'Enter') && !e.repeat) go(e); });
    b.addEventListener('keyup', function (e) { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); talkEnd(); } });
    b.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  })();

  function pickType() {
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
    var c = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
    for (var i = 0; i < c.length; i++) if (MediaRecorder.isTypeSupported(c[i])) return c[i];
    return '';
  }
  function talkStart(target, b) {
    if (S.offline || S.busy || S.rec || !target) return;
    if (!S.stream) { caption('The microphone is not on. Come in from the page first.', 1500, false); return; }
    var type = pickType(), rec;
    try { rec = type ? new MediaRecorder(S.stream, { mimeType: type }) : new MediaRecorder(S.stream); }
    catch (e) { note('This browser cannot record here.'); return; }
    var chunks = [], began = Date.now(), tb = target.kind === 'person' ? target.b : (b === undefined ? S.faceB : b);
    S.stream.getAudioTracks().forEach(function (t) { t.enabled = true; });
    rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
    rec.onstop = function () {
      S.stream.getAudioTracks().forEach(function (t) { t.enabled = false; });
      S.rec = null; duck(false); paintTalk();
      var blob = new Blob(chunks, { type: rec.mimeType || type || 'audio/webm' });
      if (Date.now() - began < 350 || !blob.size) { hideCaption(); return; }   // a tap, not a line
      heard(blob, target, tb);
    };
    S.rec = rec;
    rec.start();
    duck(true);
    S.recTimer = setTimeout(talkEnd, 30000);           // thirty seconds, the server's own ceiling
    showCaption(target.kind === 'room' ? 'listening… (to the room)' : 'listening… (to ' + target.name + ')', tb, false);
    paintTalk();
  }
  function talkEnd() {
    if (S.recTimer) { clearTimeout(S.recTimer); S.recTimer = 0; }
    if (S.rec && S.rec.state === 'recording') S.rec.stop();
  }

  // The caption: what was heard, for a moment, with a way to take it back.
  function showCaption(text, b, cancellable, onCancel) {
    S.cap = { text: text, b: b === undefined ? S.faceB : b, cancel: cancellable ? onCancel : null };
    $('#captext').textContent = text;
    $('#capcancel').hidden = !cancellable;
    $('#caption').hidden = false;
  }
  function hideCaption() { S.cap = null; $('#caption').hidden = true; }
  $('#capcancel').addEventListener('click', function () { if (S.cap && S.cap.cancel) S.cap.cancel(); });
  // Resolves true to go ahead, false when cancelled.
  function caption(text, ms, cancellable, b) {
    return new Promise(function (res) {
      var done = false, tm = 0;
      var finish = function (go) { if (done) return; done = true; clearTimeout(tm); hideCaption(); res(go); };
      showCaption(text, b, cancellable, function () { finish(false); });
      tm = setTimeout(function () { finish(true); }, ms);
    });
  }

  // HEARD → SAID. hear gives text only; the page shows it for 1.5 s with a
  // cancel and then says it (play.php puts it in the box instead: there is
  // a box there).
  function heard(blob, target, b) {
    S.busy = true; paintTalk();
    var fd = new FormData();
    var ext = /mp4/.test(blob.type) ? '.mp4' : (/ogg/.test(blob.type) ? '.ogg' : '.webm');
    fd.append('audio', blob, 'line' + ext);
    if (target.kind === 'person') fd.append('h', target.h);
    showCaption('…', b, false);
    api(WP + 'hear', { method: 'POST', form: fd }).then(function (res) {
      if (!res.ok) throw new Error(why(res, 'The ears machine did not answer.'));
      var text = String(res.d.text || '').trim();
      if (!text) throw new Error('Nothing was heard. Hold while you talk.');
      return caption('“' + text + '”', 1500, true, b).then(function (go) {
        if (!go) { note('Not said.'); return null; }
        return said(target, text, b);
      });
    }).catch(function (e) {
      return caption(e && e.message ? e.message : 'That did not go through.', 2500, false, b);
    }).then(function () { S.busy = false; paintTalk(); });
  }
  function said(target, text, b, tries) {
    tries = tries || 0;
    var path = target.kind === 'room' ? 'room/say' : 'threads/' + encodeURIComponent(target.h) + '/say';
    showCaption('…', b, false);
    return api(WP + path, { method: 'POST', json: { text: text } }).then(function (res) {
      if (res.status === 429 && tries < 3 && res.d && (res.d.kind === 'pace' || res.d.kind === 'queued')) {
        var after = Math.min(20, +(res.d.retry_after || 3));
        showCaption('a moment… (' + after + ' s)', b, false);
        return wait(after * 1000).then(function () { return said(target, text, b, tries + 1); });
      }
      if (!res.ok) throw new Error(why(res, 'That was not said.'));
      hideCaption();
      if (target.kind === 'room') { S.roomSaid[res.d.id] = 1; return roomPoll(); }
      var reply = res.d;
      showCaption((reply.name || target.name) + ': ' + reply.text, b, false);
      setTimeout(function () { if (S.cap && S.cap.text.indexOf(reply.text) >= 0) hideCaption(); }, 6000);
      if (S.focus === target.h) focus(target.h);
      return speakLine(reply.id, target.b);
    });
  }

  // -- start -------------------------------------------------------------------
  function shelf() {
    // No world named: the ones this identity can stand in, as links.
    api('worlds').then(function (res) {
      var box = $('#empty');
      box.innerHTML = '';
      if (!res.ok) { stopped(res.status, res.d); return; }
      var p = document.createElement('p'); p.textContent = 'Which world?';
      box.appendChild(p);
      (res.d.worlds || []).filter(function (w) { return w.ok; }).forEach(function (w) {
        var a = document.createElement('a'); a.href = 'stand.php?w=' + encodeURIComponent(w.slug); a.textContent = w.name || w.slug;
        a.style.cssText = 'display:block;margin:.4rem;color:inherit';
        box.appendChild(a);
      });
    }).catch(goOffline);
  }
  function start() {
    api('').then(function (res) {
      if (!res.ok) { stopped(res.status, res.d); return; }
      S.features = res.d.features || {};
      if (!W) { shelf(); return; }
      return api('worlds').then(function (r2) {
        if (r2.ok) (r2.d.worlds || []).forEach(function (w) { if (w.slug === W) S.name = w.name || ''; });
        S.cursor = '';
        pulse();
      });
    }).catch(function () { goOffline(); S.timer = setTimeout(pulse, 8000); });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && W) pulse(); });
  document.body.dataset.stand = 'ready';
  start();
})();
</script>
