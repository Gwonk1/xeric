# The stand page

`forge/web/stand.php?w=<world>` is a page that is nothing but the room you
are standing in. On a laptop or a phone it is the panorama, full-screen, with
the people in it as name tags at their bearings. In a headset you **step
inside**: the room is all around you, the names hang where the people are,
you point at one and press to turn to them, hold to talk, and the answer
comes back from where they stand.

It is a client of the [client API](API.md) and nothing else. The page itself
carries no world — not the name, not the place, not a person — so that its
service worker can keep it for when the box is out of reach. Everything it
shows it asks `api.php/v1` for, as you: with a device token it was given, the
session cookie, or (on the machine Xeric runs on) solo.

FUTURE.md's M2 and Appendix C are the plan this follows.

---

## What it does

**In 2D** (any browser):

- The look-around, full-screen: drag, the arrow keys or a phone held up turn
  you. People are buttons at their bearings. **Press** a name to turn to them
  (their unread dot goes out, and their last lines show in the corner);
  **hold** a name to talk to them.
- **Come in** starts the sound: the room's ambient loop (behind the *sound*
  toggle) and replies read aloud. It is also where the microphone is asked
  for — once, on the page, never from inside a headset.
- **Hold to talk** talks to the person you turned to, or to the room when
  nobody is focused and two or more people are there.
- The **room strip** appears while a table is live: the last few lines, each
  read aloud from where its speaker sits.
- It repaints on the **pulse**, a conditional poll: the world's cursor is the
  ETag, and an unchanged world answers 304 in milliseconds. Nothing is polled
  while the tab is hidden.

**In a headset** (*step inside*, after *come in*):

- The room is drawn around you. On a browser with WebXR Layers (Meta Quest)
  it is handed to the compositor as an `XREquirectLayer` — the headset
  reprojects it itself and no per-pixel shader runs. Elsewhere the same
  shader the play view uses draws it.
- Names are quads one metre out at each person's bearing, a little under the
  horizon, drawn with your head's rotation only (the room is at infinity, so a
  name does not slide when you lean).
- Point a controller or a hand. On **select** the nearest name within **7°**
  of the ray wins. A **tap** turns to that person. A **hold** (over a third of
  a second) talks to them; a hold at empty space talks to the room, if two or
  more people are there; otherwise it does nothing.
- Let go, and what was heard is shown as a caption for **1.5 seconds** with a
  cancel (a tap, or the cancel button), then said. The reply is captioned and
  played through an HRTF panner at the speaker's bearing, with the room's
  sound ducked under it.
- Ending the session (the headset's own menu, or *step out*) puts you back in
  the 2D view, facing where you were. Anything that goes wrong inside takes
  the step-inside button away; the 2D view is the floor.

**One difference from the play view's hold to talk.** In play.php what was
heard goes into the message box and you press send — a misheard name never
becomes a line in your mouth unchecked. A headset has no box and no keyboard,
so the stand page says the line itself, after the caption and its cancel.

**Offline.** If the box cannot be reached, the page shows the last room it
had, labelled *earlier*, with every verb off. Nothing is queued: a line said
now and delivered an hour later would be stamped into a different world hour
than the one it answered.

**Install it.** On a secure context the page is an installable app (its
manifest is `stand.php?manifest&w=<world>`). Opened from its icon, it starts
in that world; on a Quest, tapping an installed app's icon counts as the
press that may start a headset session.

---

## Linking a headset

A headset is not the machine Xeric runs on, so it is a stranger until it is
linked to you.

1. On the machine Xeric runs on, open **`link.php`**, name the device ("the
   headset"), and press **Make a code**. Tick *For a child* for a kids'
   headset: it will never be shown adult material.
2. On the headset, open `stand.php?w=<world>` and press **link** (or wait for
   the form, which opens by itself when the machine does not know the
   browser). Type the eight characters within five minutes. On `--lan`,
   link.php also shows a QR code carrying the code.
3. The page sends `POST api.php/v1/link {code}` and gets a device token for
   your own session: same worlds, same machines, same evening.

Where the token is kept: in the browser (`localStorage`) **only on a secure
context or on this machine**. Over plain http to another machine, anything
on the network could rewrite the page and read a stored key, so the token is
kept in memory and you link again next time — though the API will not hand
one out over plain http in the first place unless the operator says so
(below). **link** with a token held forgets it. A lost headset is one
*Unlink* on link.php away from being nothing at all.

---

## The secure-context rule, and the three setups

WebXR, the microphone, service workers and installing all need a **secure
context**: https, or a page on this machine (`127.0.0.1`, `localhost`).
`./xeric --lan` serves plain http, so a headset pointed at
`http://192.168.x.y:8787` gets a page with a one-line banner, no step inside
and no hold to talk. And `POST link` refuses to mint a token over plain http
(403 `insecure`, code not used up) — a token is a long-lived key.

Three setups work:

1. **Quest Link** (or Air Link) with desktop Chrome on the box at
   `http://127.0.0.1:8787/stand.php?w=<world>`. The page is on this machine,
   so it is solo — no link needed — and the headset is the display.
2. **`adb reverse`**, a Quest in developer mode on a USB cable:
   `adb reverse tcp:8787 tcp:8787`, then open `http://127.0.0.1:8787/stand.php?w=<world>`
   in the Quest Browser. The headset's requests arrive from loopback: a secure
   context, and solo.
3. **TLS in front**, for anything else (an Android XR headset, a Vision Pro, a
   Quest without a cable): `tailscale serve --bg http://127.0.0.1:8787` on the
   box, and open the `https://…ts.net/stand.php?w=<world>` address it prints
   from a device on the same tailnet. Link the device (above): through
   tailscale the request names a host the solo pin does not answer to, so the
   page needs a token, and it may keep it.

An operator who accepts the risk on a network they trust can set
`XERIC_LAN_TOKENS=1` to mint tokens over plain http; the page still cannot
step inside or record there, because the browser will not.

---

## Headsets (as of November 2026)

From FUTURE.md Appendix D; check before depending on a detail.

| | Meta Quest Browser | Android XR (Chrome) | Apple Vision Pro (Safari) |
|---|---|---|---|
| step inside (`immersive-vr`) | yes | yes | yes |
| the room as an equirect layer | yes (WebXR Layers) | the shader | the shader (no layers) |
| pick a name | controller or hand ray | hand ray, by default | gaze and pinch: the ray exists only at the pinch, so nothing highlights as you look |
| hold to talk | yes (webm/opus) | yes | yes (mp4) |
| install from the icon | yes; the icon's tap may start a session | yes | add to Home Screen |
| passthrough (`immersive-ar`) | supported by the browser, not used yet | supported, not used yet | no |

Quest notes: the headset sleeping ends the session (you are back in 2D);
permission prompts cannot be answered inside a session, which is why *come
in* asks for the microphone first.

---

## What a browser cannot do

These need a native client, and the stand page does not pretend otherwise:

- talk to the box over plain http on the LAN with the microphone or a headset
  session;
- highlight a name as you look at it on visionOS (gaze is private until the
  pinch);
- listen in the background, or for a wake word;
- push a notification without a third-party push service;
- render splats or glb at headset frame rate;
- passthrough on visionOS;
- speech to text on the device without `'wasm-unsafe-eval'` in the policy.

---

## For developers

- **Files.** `forge/web/stand.php` (the shell and `?manifest`),
  `forge/web/stand-sw.php` (the service worker), `forge/web/stand-icon.png`,
  and the stand parts of `forge/web/xeric-view.js` (`standXR`, `tagPos`,
  `tagQuad`, `raySelect`, `holdTarget`, `panAt`, `listenerFace`, `offline`,
  and `pano(box, {load, stand})`). play.php passes no options and its view is
  unchanged.
- **Policy.** `xeric_web_head(…, ['stand' => true])` in ui.php adds
  `worker-src 'self'; manifest-src 'self'` to the usual policy (without them
  both fail silently) and sends
  `Permissions-Policy: microphone=(self), xr-spatial-tracking=(self), camera=()`.
  No `blob:`: pictures are decoded with `createImageBitmap` and sounds with
  `decodeAudioData`; no AudioWorklet (a worklet cannot carry a nonce).
- **The service worker** is scoped to `stand.php`. It keeps the shell (the
  page, the viewer by its hash, the icon) on install and serves it when a
  navigation fails; it keeps panoramas by their fingerprint address (a dozen);
  it never answers an API call or touches a POST.
- **Tests.** `php forge/web/tests/stand-test.php` serves the page and checks
  the shell, the headers, the manifest and the worker, then runs
  `stand-test.js` (Node) over the viewer's stand parts and the worker against
  fakes. The smoke test loads the page in headless Chrome.
