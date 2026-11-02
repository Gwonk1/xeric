# Reference proxies

Xeric speaks one dialect to a machine that makes rooms: the generic envelope
in [docs/VIEWPORT.md](../../docs/VIEWPORT.md#the-generic-envelope) —
`POST /v1/scenes`, `GET /v1/scenes/{job}`, an answer of `{kind, mime, b64|url}`.
No vendor speaks it, and none should have to. A **proxy** is the small server
that sits beside a vendor and translates, so the vendor's field names live in
one file somebody can fix in an afternoon, and the engine never grows a branch
per vendor.

These three are **examples, not dependencies**. Nothing in the engine imports
or knows about them. Each is one file, Python 3.9+ standard library only — no
pip, no virtualenv.

| file | upstream shape | makes | async | key from |
|---|---|---|---|---|
| `skybox.py` | Blockade Labs Skybox | `pano` (+ depth map extra) | yes | `SKYBOX_API_KEY` |
| `marble.py` | World Labs Marble | `pano`, or `splat` when asked first (+ collider, splat extras) | yes | `WLT_API_KEY` |
| `images.py` | any local `/v1/images/generations` (sd-server, an A1111 or ComfyUI shim) | `image`; `pano` with `--pano` | no | `XERIC_PROXY_IMAGES_KEY`, optional |

## Running one

```sh
SKYBOX_API_KEY=... python3 tools/proxies/skybox.py --port 9100 --style 67
WLT_API_KEY=...    python3 tools/proxies/marble.py --port 9100 --full-model marble-1.1
python3 tools/proxies/images.py --port 9100 --upstream http://127.0.0.1:1234 --pano --edits
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

None of the three makes sound. Each answers a sound request with a plain
`400 {error}`, which the engine reads as "this machine cannot", and from then
on stops asking it — or set `media.scene.sound.enabled: false` in the template.

## Before you trust one

The vendor APIs were **not verified** from the session that wrote these.
Every vendor field sits in one function pair, `to_upstream` / `from_upstream`,
with a comment on each saying *per <vendor> docs as of Nov 2026 — verify*.
Run with `--dry-run` first and compare what it prints with the vendor's docs.

```sh
python3 -I tools/proxies/test_proxies.py
```

runs all three against `fake_upstream.py`, which plays each vendor's shape —
the 202 path, the "cannot", the loopback guard, the key that never prints.
It proves the plumbing, not the vendor.
