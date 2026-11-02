# Reference proxies

Xeric speaks one dialect to a machine that makes rooms: the generic envelope
in [docs/VIEWPORT.md](../../docs/VIEWPORT.md#the-generic-envelope) —
`POST /v1/scenes`, `GET /v1/scenes/{job}`, an answer of `{kind, mime, b64|url}`.
No vendor speaks it, and none should have to. A **proxy** is the small server
that sits beside a vendor and translates, so the vendor's field names live in
one file somebody can fix in an afternoon, and the engine never grows a branch
per vendor.

These four are **examples, not dependencies**. Nothing in the engine imports
or knows about them. Each is one file, Python 3.9+ standard library only — no
pip, no virtualenv.

| file | upstream shape | makes | async | key from |
|---|---|---|---|---|
| `skybox.py` | Blockade Labs Skybox | `pano` (+ depth map extra) | yes | `SKYBOX_API_KEY` |
| `marble.py` | World Labs Marble | `pano`, or `splat` when asked first (+ collider, splat extras) | yes | `WLT_API_KEY` |
| `images.py` | any local `/v1/images/generations` (sd-server, an A1111 or ComfyUI shim) | `image`; `pano` with `--pano` | no | `XERIC_PROXY_IMAGES_KEY`, optional |
| `worldmodel.py` | none needed; optionally a local video model's `/v1/frames` | `stream` (MJPEG) | live session | none — local |

**The layout.** When the envelope carries `layout` (the room's blockout —
labelled boxes at bearings and distances from where you stand, from
`engine/layout.php`), each proxy uses what its upstream can take: `skybox.py`
leads the prompt with the labels, nearest first; `marble.py` leads the text
prompt with labels, bearings and distances (Chisel's blockout API could not be
verified — *verify*), and with `--pano-input` sends a 2:1 reference as a
panorama prompt rather than an image one; `images.py --control` sends
`layout_depth` (`--control=seg`: `layout_seg`, both present only when the world
sets `media.scene.layout_images: true`) to `/v1/images/edits` as a second
multipart file, ControlNet-style. That field is nobody's standard — sd-server
and the ComfyUI / A1111 wrappers each name it differently — so it is
`--control-field` (default `control`), and the model needs a depth or
segmentation ControlNet loaded. Every proxy leaves the rating clause the
prompt's last words.

## Running one

```sh
SKYBOX_API_KEY=... python3 tools/proxies/skybox.py --port 9100 --style 67
WLT_API_KEY=...    python3 tools/proxies/marble.py --port 9100 --full-model marble-1.1
python3 tools/proxies/images.py --port 9100 --upstream http://127.0.0.1:1234 --pano --edits --control
python3 tools/proxies/worldmodel.py --port 9100        # add --upstream-frames http://127.0.0.1:7860/v1/frames
```

Common flags: `--wait` (seconds to wait inline before answering 202, default
20), `--dry-run` (print the upstream request with the key redacted, answer a
1×1 PNG, call nobody), `--allow-remote` (answer clients that are not
loopback — refused by default; widen `--host` too), `--pass-url` (async ones:
hand back the vendor's link instead of fetching it). `--help` on each says the
rest.

## Pointing Xeric at it

```sh
XERIC_SCENE_BASE=http://127.0.0.1:9100 XERIC_SCENE_KIND=generic
```

or the same address, kind *generic*, on **the machines screen**. Leave
`XERIC_SCENE_KEY` unset. **No key is ever sent to Xeric — the proxy holds it.**
It reads its vendor key from its own environment, never from a request, never
into a log; the app has nothing to leak. A proxy on loopback counts as a local
machine for the meter, so if your vendor bills, keep an eye on its dashboard
rather than Xeric's.

None of the four makes sound. Each answers a sound request with a plain
`400 {error}`, which the engine reads as "this machine cannot", and from then
on stops asking it — or set `media.scene.sound.enabled: false` in the template.

## worldmodel.py — the engine-driven loop, on one desk

The shape [FUTURE.md](../../docs/FUTURE.md) M5 bets on, after Matrix-Game 4.0:
**the engine holds the world, the model draws it.** It answers only
`kind_wanted` containing `stream`:

```
{"kind": "stream",
 "session": {"id", "url": "http://127.0.0.1:9100/s/<id>.mjpeg", "protocol": "mjpeg",
             "expires_at": <epoch s>, "max_seconds": 600},
 "meta": {"vendor": "xeric-blockout" | "local-frames", "vendor_id", "seed_used", "elapsed_s", ...}}
```

Per frame, a renderer in pure Python (no numpy) rasterises the layout's boxes
from the vantage, all the way round on a cylinder, flat-shaded at the brief's
light: a 320×180 low-poly **proxy frame**. With `--upstream-frames` each one
goes to a local video model that re-renders it into a finished frame; without
one the blockout itself is the stream, so the whole path — go live, the page's
`<img>`, events, the end — runs with no GPU. Frames go out as MJPEG
(`multipart/x-mixed-replace`, a baseline JPEG encoder in the file).

While it is live, Xeric POSTs `{type, text, at}` to
`/v1/scenes/{session}/events`: `weather` tints the frame, `light` sets its
brightness, `arrival` adds a figure at the bearing of the person named
(matched against the layout's people and the brief's `who`; nobody known
comes in at the door — a layout door, else `--door-bearing`, 180°),
`departure` takes one away. `DELETE /v1/scenes/{session}` ends it; it ends by
itself at `max_seconds` (`--max-seconds`, or less if the request asks), the
stream closing and later calls answering `410`. `GET
/v1/scenes/{session}/frame` is the latest frame, PNG when it has one.

The stream is on the **API's own port** by default, its url naming the host
the request came to, because Xeric plays a session only from the machine's
own origin (`XERIC_SCENE_BASE`'s scheme, host and port). `--stream-port`
moves it to a listener of its own, for any other client.

**Pointing Xeric at it:** `XERIC_SCENE_BASE=http://127.0.0.1:9100`,
`XERIC_SCENE_KIND=generic`, and `media.scene.stream.enabled: true` in the
template (`media.scene.layout_images: true` too if your model wants the depth
and segmentation maps). Loopback counts as local, so the live view is not
metered as a bill.

**It is a scaffold for a real local world model**, not one. The real model
plugs in at `send_frame()`: point `--upstream-frames` at a wrapper that takes

```
POST {frame: {mime: "image/png", b64}, caption, first_frame, memory: [...],
      layout_depth?, layout_seg?, seed, session, index}
  -> {frame: {mime, b64}}   (or the image bytes, image/jpeg or image/png)
```

— the proxy frame, the composed prompt, the first finished frame (the room's
reference render before there is one), up to `--memory` recent frames: the
Matrix-Game conditioning, as Xeric can give it. That shape is this file's
invention; nobody serves it yet. Or replace `send_frame` with an in-process
call. The session, the events, the stream and the guard stay as they are.
`--dry-run` prints the first frame's stats and calls no upstream.

## Before you trust one

The vendor APIs were **not verified** from the session that wrote these.
Every vendor field sits in one function pair, `to_upstream` / `from_upstream`,
with a comment on each saying *per <vendor> docs as of Nov 2026 — verify*.
Run with `--dry-run` first and compare what it prints with the vendor's docs.

```sh
python3 -I tools/proxies/test_proxies.py
```

runs all four against `fake_upstream.py`, which plays each vendor's shape —
the 202 path, the "cannot", the loopback guard, the key that never prints, the
control file's field, the layout in the prompts, and a frames model that
hands each frame back with a magenta border so the test can see it went
through. For `worldmodel.py` it reads the MJPEG stream, checks every part is a
JPEG, and checks that an arrival, a departure, the weather and the light each
change the frame where they should, and that a session ends on time.
It proves the plumbing, not the vendor.
