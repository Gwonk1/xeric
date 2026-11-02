# The Viewport — the room, from where you stand

Since the day it was written, `engine/travel.php` has said the same thing about
pictures: *a map is a client of `xeric_travel_map()`, so is a first-person
view, so is a headset.* `forge/web/where.php` said it louder — **this file is the
architecture, not a feature** — and made a bet on it: the surface is the cheap,
replaceable, one-shottable part, the world model is not, and the bet only pays
for as long as no renderer ever has to work out for itself who is standing
where.

The viewport is the first client to collect on that bet. It is the room you are
standing in, at this minute, rendered by a machine **you** chose — a 360°
panorama you can drag to look around, a still, or a link to a hosted room you
can walk inside. `engine/scene.php` composes what the room looks like. It does
not draw a single pixel, it names no vendor, and with no machine chosen it does
nothing at all — **dormant is the shipping state and never an error.**

The engine half answers one question every room-making machine asks — *what is
here, now* — and hands the answer over. Which machine draws it is the owner's
business, and the machines are arriving faster than any one of them is worth
naming in code.

---

## What the engine composes

`xeric_scene_brief($t, $db, $key = null, $now = null, $opts = [])` is the thing a
viewport is built against, the way `xeric_travel_map()` is the thing a map is.
With no key it is the player's own room (`xeric_player_where`). A player who is
nowhere has no room, and the brief is `null` — **nowhere is a real state**, the
same one travel.php already prints. An unknown key, or a home nobody in the
story lives in yet, is `null` too: fail closed, exactly as the map simply does
not list an unstaged house.

**Nothing in it is invented, and nothing in it is asked of a language model.**
It is read off canon:

- **the place** — its name, kind, description and interior, with the same
  derived seeds `photo.php` keeps for rooms (`xeric_photo_place_seeds`): an
  exterior seed, an interior seed, a seed per named thing in the room. The pie
  case is the same pie case in every frame it ever appears in.
- **the light**, bucketed from the clock alone (`xeric_scene_light`): pre-dawn,
  morning light, noon, afternoon light, golden hour, dusk, lamplit night. No
  latitude and no season, for the same reason the phase boundaries are not
  per-template — a light that drifted by date would mint a new scene every few
  days for a room that has not changed.
- **the sky** — `xeric_weather_line` read *with the database*, so the sky in
  the picture is the sky the narrator told the cast about this morning.
- **the room's state** — open, closed at this hour, or gone dark and unkept.
  An open room needs no announcement; a shut one changes every surface.
- **who is here** — presence (`xeric_world_who_is_where`), the dead passed in
  explicitly so nobody is ever standing in the room they died in, and for each
  person only what a body in the doorway could see: name, build, appearance,
  what they are wearing, what they are at.
- **the setting** — locale and era, as an era-true clause first, because a phone
  in an 1873 room is a broken room.

From that the engine writes one paragraph in code (`xeric_scene_prompt`), in
photo.php's order: the world's aesthetic, the room, its things, its light and
sky, the people, where the eye is (*seen from where you stand, just inside the
door*, or a wide establishing view), the template's style, an optional ask —
and **the rating clause last**, so nothing upstream of it can talk it down or
up. An `ask` is folded in as a clause the way photo.php folds in the model's
proposal. It is never the prompt.

### What it refuses to include

**Only observables.** Never a wall, a secret, a psyche, a memory, a standing, a
ledger. And the important word is not *filtered*: none of those are **read** in
`scene.php` at all. A brief cannot leak a secret through a mis-written filter if
the code that writes it never opened the drawer.

This is the project's oldest rule pointed at a new audience. **A hallucinated
wall is a real secret told to the wrong person**, and a rendered secret is the
same thing with worse manners: it goes to a third party's machine, it is
written into pixels nobody can redact, and it is shown to the player, who is
exactly the person most walls exist to keep it from. Milldale has a card game
behind a wall; no scene of any room in Milldale will ever show a card. The test
suite says so negatively — every walled string in the fixture,
checked against the brief and the prompt of every place at several hours.

Because there is nothing in the brief that was not already on the map or in a
portrait, **the brief is safe to hand to a third-party machine whole.** That is
the design, not a hope: the structured brief rides along with the prompt, so a
machine that wants to do its own composing has the parts. It does carry the
player's display name and how long they have stood there — both things the
cast can already see.

---

## The fingerprint — what changes a scene, and what does not

A scene is keyed by **what the room looks like**, not by when somebody asked.
One job per (place, fingerprint), and `xeric_scene_fingerprint` is a sha1 of:

| In | Out, deliberately |
|---|---|
| the place key and description | the minute, the epoch |
| the interior, item by item | the day of the week |
| the light bucket | what each person is **doing** |
| the weather line | the player |
| who is present (sorted handles) and what each looks like, their build, what they wear | |
| open / dark | |
| vantage and style | |
| the ask | |
| the rating | |

**The minute is out** because a room at 07:10 and at 07:40 in the same morning
light is the same room, and a fingerprint that ticked would make every glance a
render. **The doing is out** because it is a pose, and the week's blocks change
*who* is present — which is in — far more often than they change one present
person's errand. The stored prompt keeps the doing words as they were first
asked; a viewer that wants the present tense reads the brief, which is fresh
every call.

The consequence is the one that matters for a bill: **two visits in the same
light to the same company are the same scene and cost one render.** Somebody
walking in is a new scene. The light turning is a new scene. Standing there for
twenty minutes is not.

Not in it either, and worth knowing: `kinds`, `quality` and `negative`. They
shape how the machine is asked, not what the room looks like, so changing them
does not invalidate a scene already rendered.

---

## Choosing a machine

`xeric_scene_endpoint()` reads, in order:

1. **The machines screen.** The web layer registers a resolver
   (`xeric_scene_source`), read at call time, so a worker under a forced session
   sees that session's choice. "Explicitly none" on that screen stops here — it
   outranks the environment and the fallback both.
2. **The environment.**
   - `XERIC_SCENE_BASE` — the machine's address. Unset means "look further".
   - `XERIC_SCENE_KEY` — the key, if it needs one. **Environment only.** Keys
     are never stored, here or anywhere else in the app.
   - `XERIC_SCENE_KIND` — `generic` (the default) or `image`. Anything else is
     `generic`.
   - `XERIC_SCENE_AUTH` — where the key goes. `bearer` (the default) is
     `Authorization: Bearer <key>`; anything else is taken as the header's own
     name (`x-api-key`, `WLT-Api-Key` and friends). A value that is not a valid
     header name falls back to bearer, because a config typo should not become
     header injection.
3. **The image machine**, as kind `image`. An owner who wired up pictures for
   photographs has wired up this too, without being asked twice: the viewport
   becomes **a still of the room from where you stand**, rendered through the
   same `POST {base}/v1/images/generations` the photos use, seeded with the
   room's *interior* seed — the room, not its street face. That is a legitimate
   viewport, not a degraded one.
4. **Nothing.** Dormant. No renders, no errors, no empty frame.

A configured machine that is not answering reads as *no viewport here*, not as
a broken frame — `xeric_scene_up` does the same short, never-throwing probe the
image machine gets.

**Costly versus local** is one bit, inherited from photos (`xeric_scene_costly`
→ `xeric_image_costly`): a machine on `127.0.0.1`, `localhost` or `::1` costs
nothing; anything else is assumed to spend money, and every finished render is
reported to the meter the web layer registers (`{scenes: 1}` plus whatever `usage` the machine sent).
A local panorama model is a viewport with no per-image cost, which is the only
version that keeps the local-first promise intact for somebody who looks
around a lot.

The template has the last word on whether a world is viewable at all:
`media.scene.enabled: false` and nothing is queued, whatever machine is chosen.
The knobs are in [WORLD_TEMPLATE.md](WORLD_TEMPLATE.md).

---

## The generic envelope

Kind `generic` is **the contract** for anybody writing an adapter to a machine
that makes rooms. No vendor is named in it and none needs to be.

### Request

`POST {base}/v1/scenes`, `Content-Type: application/json`, the key in the
configured auth header. The fields, as `scene.php` documents them:

```
{
  "kind_wanted": ["pano","image"],  preference order;
                 media.scene.kinds, else this default
  "prompt":      the composed paragraph, rating clause last,
  "negative":    media.scene.negative, only when set,
  "brief":       the structured brief — observables only,
  "seed":        the place's interior seed (int),
  "fingerprint": sha1, stable across visits that look alike,
  "size":        [4096, 2048], only when a pano is wanted,
  "quality":     "draft" | "full",
  "timeout_s":   how long Xeric will wait on this call
}
```

Concretely — Milldale's fixture, the Bluebird at 07:10 on a Monday, the brief
abbreviated:

```json
{
  "kind_wanted": ["pano", "image"],
  "prompt": "a river town of nine hundred in southern Ohio. period-true to present day, nothing anachronistic in view. inside the Bluebird Diner, Eight booths, nine stools, a pie case that has held the same three pies since the Clinton administration. The window fogs from the inside every morning and somebody always writes in it. morning light, Bright and sharp, the first real chill in it. present: Dot Vance, Apron over everything, reading glasses on a chain she never uses for reading — counter, grill, everything. seen from where you stand, just inside the door. a wholesome everyday scene, nothing suggestive.",
  "brief": {
    "key": "bluebird", "name": "the Bluebird Diner", "kind": "diner",
    "now": { "epoch": 1793621400, "hhmm": "07:10", "dow": 1, "phase": "morning" },
    "light": "morning light",
    "weather": "Bright and sharp, the first real chill in it.",
    "open": true, "dark": false,
    "who": [{ "handle": "dot", "name": "Dot Vance", "doing": "counter, grill, everything",
              "at_home": false, "appearance": "Apron over everything, …", "build": "", "wears": [] }],
    "rating": "sfw", "minor": false, "vantage": "first_person",
    "seeds": { "place": { "exterior": 958866395, "interior": 428416240, "items": [] },
               "faces": { "dot": 660118203 } },
    "…": "exterior, interior, you, setting, enabled, prompt, fingerprint"
  },
  "seed": 428416240,
  "fingerprint": "41048312ac3f57afb7223c08319e0f07e12433f0",
  "quality": "full",
  "timeout_s": 300,
  "size": [4096, 2048]
}
```

`timeout_s` defaults to 300: rooms are slower than faces.

### Answer

```
{ "kind": "image"|"pano"|"splat"|"glb"|"viewer",
  "mime": "...", and EXACTLY ONE of "b64" | "url",
  "extras": [...] optional, "meta": {...} optional,
  "usage": {...} optional, reaches the meter }
```

For example:

```json
{ "kind": "pano", "mime": "image/png", "b64": "iVBORw0KGgo…",
  "meta": { "seed": 428416240 }, "usage": { "credits": 1 } }
```

- **image, pano, splat, glb**: a `url` is fetched **at once**, with the same auth
  header — vendor links expire — and the bytes are stored on this disk. Xeric
  keeps no link to anybody's bucket.
- **viewer**: the url *is* the scene, a hosted explorable room, and is stored as
  a string with no bytes.
- `meta` (the seed used, cost, the vendor's own id) is recorded on the row as it
  came. `extras` (a depth map, a collider) is kept in it too.

The answer is checked, and refused rather than guessed at: a kind outside the
five, both or neither of `b64` and `url`, bad base64, an empty scene, or **a url
that is not http(s)** — a reply naming `file://` is not a scene, it is a request
to read this disk, and a viewer link that is `javascript:` is not a link.

### Later — the 202 path

A machine that takes minutes answers instead, usually as a `202`:

```json
{ "status": "pending", "job": "<id>", "retry_after_s": 30 }
```

**Xeric does not block on it.** The reaper records the job, keeps its claim, and
asks again on its next pass with `GET {base}/v1/scenes/{job}`, which answers
either shape above — finished, or still pending (a re-issued job id is kept).
Asking after a job is a cheap GET and does not count against the reaper's limit;
`retry_after_s` is the machine's hint, and the reaper's next pass is the
schedule.
The patience is measured from the first ask, not the last poll: past thirty
minutes the wait counts as a failed try and the row goes back to be asked for
afresh. Three tries and it is failed, exactly as a photo is.

### Writing a proxy

Most machines that make rooms do not speak this envelope, and should not have
to. The intended shape is a small proxy beside them that does — so the vendor's
dialect lives in thirty lines somebody else maintains, and the engine's adapter
never grows a branch per vendor. A sketch, against a hypothetical asynchronous
panorama service:

```python
# POST /v1/scenes → submit upstream; GET /v1/scenes/<job> → poll upstream.
import base64, requests
from flask import Flask, request, jsonify
app, UP, KEY = Flask(__name__), "https://panorama.example/api", "…"

@app.post("/v1/scenes")
def submit():
    env = request.get_json()
    r = requests.post(f"{UP}/generate", headers={"x-api-key": KEY},
                      json={"prompt": env["prompt"], "seed": env["seed"],
                            "negative": env.get("negative", "")}).json()
    return jsonify(status="pending", job=r["id"], retry_after_s=20), 202

@app.get("/v1/scenes/<job>")
def poll(job):
    r = requests.get(f"{UP}/requests/{job}", headers={"x-api-key": KEY}).json()
    if r["state"] != "complete":
        return jsonify(status="pending", job=job, retry_after_s=20), 202
    png = requests.get(r["file_url"]).content
    return jsonify(kind="pano", mime="image/png", b64=base64.b64encode(png).decode(),
                   meta={"vendor_id": job, "seed": r.get("seed")})
```

The proxy owns its own key, so the one Xeric holds (if any) is only the proxy's.
It may answer synchronously when its upstream is fast, and it may return `url`
instead of `b64` — Xeric fetches it immediately either way. A `GET` on the base
should answer something, because that is how "is it up" is asked.

---

## The provider landscape, November 2026

**A snapshot, and it will date** — faster than anything else in this tree. It is
here so the first proxy somebody writes is aimed at the right kind of machine,
not as an endorsement, and nothing in the code depends on any of it.

**The recommended first target is a 360° equirectangular panorama.** A room is a
single vantage — you are standing in it, not flying through it — and an
equirect is exactly one vantage, all the way round. It is also the common
denominator: hosted world-makers ship one alongside their richer output, panorama
services make nothing else, and a local diffusion model can produce one. That is
why `kind_wanted` defaults to `["pano","image"]` and why a pano request carries
`size: [4096, 2048]`.

- **Blockade Labs Skybox.** `POST /api/v1/skybox` with `prompt`,
  `skybox_style_id` and `seed`; poll `GET /api/v1/imagine/requests/{id}`; the
  key in an `x-api-key` header (`XERIC_SCENE_AUTH=x-api-key`, behind a proxy
  that speaks the envelope). Returns an 8K equirect plus a depth map, and
  honours a seed — which matters here, because the seed is how the Bluebird
  stays the Bluebird between renders. Credit-priced, on subscription tiers.
- **World Labs Marble.** `POST https://api.worldlabs.ai/marble/v1/worlds:generate`
  with a `world_prompt` of type `text`, `image` or `panorama`; models
  `marble-1.1` and `marble-1.0-draft`; poll `/marble/v1/operations/{id}`; the key
  in `WLT-Api-Key`. Every world ships a `pano_url`, plus gaussian-splat `.spz`
  URLs and a collider `.glb` — a proxy can return the pano as the scene and the
  rest as `extras`. Roughly $1.20 a full world (about five minutes) and $0.12 a
  draft (about twenty seconds), which maps onto `quality: draft | full`. Seed
  support is unconfirmed, so a room may not be the same room twice.
- **Tencent HunyuanWorld / HY-World.** Open weights, self-hosted Python:
  panorama first, then a layered mesh. Around 33 GB of VRAM reported. There is
  no official HTTP server, so it needs a wrapper proxy — and once it has one it
  is a local machine, with no per-render cost.
- **Local ComfyUI with a FLUX 360/equirect LoRA.** The same format at no
  per-image cost on hardware many owners already have for photographs. This is
  the option that keeps the local-first promise, and the one to reach for first
  if the image machine is already a local ComfyUI.

Not a fit, today:

- **Streaming world models** (Decart Oasis, Odyssey): priced per second,
  oriented toward driving and robotics, and they have no stable room — a
  diner that reinvents its booths when you turn your head is not the Bluebird.
- **Google Genie 3**: no public developer API as of this date.
- **Meshy, Tripo, Stability 3D**: single props. A pie case, not the diner. The
  wrong tool.

### In the browser

The app's Content-Security-Policy admits only its own nonce-stamped inline
scripts: no CDN, no third-party script, no frames, images only from itself or
`data:`. So the play view shows a panorama with **a small inline WebGL viewer**,
drag to look; a still is a plain `<img>`; splats and `.glb` files are offered as
downloads; a `viewer` scene opens in a new tab, because a hosted room cannot be
framed under that policy and should not be. The bytes are served from this
disk, which is the other reason a `url` answer is fetched at once.

Somebody building a client outside the CSP — a separate page, a headset app —
has better tools: **Pannellum** is the standard drop-in equirect viewer,
**`<model-viewer>`** displays a `.glb` with orbit controls in one element, and
**Spark** renders gaussian splats in three.js. None of them ship here, and
nothing in the engine assumes any of them.

---

## What is deliberately not built

**No consent step beyond choosing a machine.** Photos ask once — *generate
photos now?* — because a forge enqueues a whole cast's worth of renders the
owner never individually requested. A scene is requested by somebody looking:
the player is standing in a room and pressed a button to see it. The owner
chose a scene machine, or an image machine, on the machines screen, and **that
choice is the yes.** There is no `approved` column and no world_state gate,
because a gate that only ever re-asks a question already answered is a second
click, not a safeguard.

**No streaming.** The world models that stream are not yet worth a machine that
streams, and a viewport that re-imagines the room every frame has thrown away
the one thing this one has — that it is *this* room, from canon.

**No multi-room walkthrough.** Travel is still the unit of movement. A splat you
can walk around in is a picture of one room; walking out of its door is a trip,
and a trip costs minutes on the world clock (travel.php's whole point). The
viewport shows where you **are**. It does not move you, and it does not show you
somewhere you have not gone.

**No language model in the loop.** The prompt is composed from template data.
MODEL PROPOSES, CODE DISPOSES, for rooms too.

---

## Rating

The world's effective rating passes through to the machine as the **final
clause** of the prompt: *a wholesome everyday scene, nothing suggestive* for sfw
and pg, *an everyday scene, nothing explicit* for teen, and *a scene in keeping
with the world's adult rating* above that. The owner chose the machine; what it
will draw above the floor is between them and it.

**The one structural rule is the minor floor.** A minor anywhere in the room
forces the whole scene to the bottom of the ladder and ends the prompt with
photo.php's own words, verbatim — `XERIC_PHOTO_FLOOR_FRAMING`, *a wholesome
everyday photograph, fully clothed, ordinary daylight, suitable for a family
album* — whatever the world's rating and whatever the ask said, and after both.
It is one constant shared by the two composers, so a portrait and a room
cannot drift apart.

It is not a knob because it is the one thing a knob would only ever be turned
to break. Every other rating decision is the owner's, made once, about their
own world; this one is about a person who is not in a position to make it, and
**a machine's own classifier is never the only control.** A minor in the room
is a minor in the frame. That was true of photographs first, and a room is a
photograph one size up.
