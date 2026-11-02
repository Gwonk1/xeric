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
panorama you can drag to look around (and, in a headset, step inside), a still,
or a link to a hosted room you can walk inside — with the people in it tagged
where they stand, and what the room sounds like underneath. `engine/scene.php`
composes what the room looks like and sounds like. It does not draw a single
pixel or make a single sound, it names no vendor, and with no machine chosen it
does nothing at all — **dormant is the shipping state and never an error.**

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
  what they are wearing, what they are at — and where they stand (see
  [Where things sit](#where-things-sit)).
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
does not invalidate a scene already rendered. Nor are the bearings below, which
are a function of the interior and the company — both already in.

---

## Where things sit

Every person and every named thing in a brief carries a **bearing**: whole
degrees, 0 to 359, clockwise from straight ahead as you stand at the vantage
(`xeric_scene_bearings`). It is **derived, never stored** — crc32 of the world,
the place and the thing's own name, lowercased the way photo.php's per-thing
seeds are — so two visits, two renders and two different clients all put the
pie case in the same place, and nobody had to author a floor plan or keep one in
step with the template. In Milldale's fixture Dot, alone behind the Bluebird's
counter at 07:10, is at 239°.

The bearings are **spread so a label can be read.** Anything landing within
20° of something already placed moves on by 40°, as many times as it takes, in
a fixed order: furniture first, by name, then people, by handle. Furniture
first so a room's things never move because somebody walked in; people after,
so they find their places around what was already there. Nine things always
fit; a room more crowded than that keeps the raw bearing for whatever does not,
an honest overlap rather than a loop.

The brief hands the bearings to the machine (a machine that lays a room out has
somewhere to put people), and to the play view, which hangs each person's name
tag over the panorama at theirs. Whether a given machine actually *draws* Dot at
239° is up to the machine; the tags and any headset agree with each other
whether it does or not.

---

## Drift, answered with conditioning

World models drift: ask one for the same diner twice and the booths move. A seed
holds a room still only for a machine that honours seeds, and not every one
does. But **every machine can be shown what it drew last time**, so the reaper
sends the newest picture of the room along with the next request for it
(`xeric_scene_reference`) — `reference_image` and `reference_fingerprint` in the
envelope — and the pie case stays the pie case across light buckets because the
last picture of it rides along.

The reference is the newest finished *look* with an image type whose bytes are
still on this disk. One that is missing, or heavier than 8 MB
(`XERIC_SCENE_REFERENCE_CAP`), is passed over for the one before it, five deep
at most, because conditioning on an older view of the same room still beats
conditioning on none. A splat, a viewer link or a sound is not a picture and
never rides along. Which picture was sent is recorded in the row's meta as
`reference_fingerprint`. The image-machine fallback never takes one: an
`images/generations` call has nowhere to put it.

---

## What you hear

The second channel. `xeric_sound_brief` is the look brief's sibling, built from
the same canon under the same refusals and **smaller on purpose**: the kind of
place (read by keyword into eatery, worship, shop, outdoors, home or room), open
or shut or gone dark, the weather line, the part of the day, the era — and the
company **as a doorway hears it**: nobody, one, or several, and whether the one
is talking. A count, never a name. No name, no doing text and no description
reaches the sound brief at all; whether somebody is talking is one bit read off
their week's `doing` by keyword and never echoed.

It makes two things:

- **a line** for the sidebar, at most fourteen words, built only from a fixed
  vocabulary of phrases — *Somebody busy, not talking, cups and plates
  knocking.* Nothing in it is template prose, so it cannot quote a wall even by
  accident, and it is era-true by construction: rain on a roof, a floorboard,
  cups and plates, a murmur. An 1873 fog town never hears an engine.
- **a paragraph** for a machine that makes sound, in the look prompt's order,
  with the era clause *in earshot* rather than in view, and the rating last.

**Voices, never words.** When anybody is talking the paragraph says *voices as
texture only, no words that can be made out* — an audio machine that rendered
speech you could make out would be writing dialogue nobody wrote.

Sound rows share `scene_jobs` with looks under `channel = 'sound'`, and a sound's
fingerprint carries a **`sound:` prefix**, so one (key, fingerprint) index holds
both channels and a sound can never be filed, served or mistaken as a look;
enqueue refuses a brief whose channel and prefix disagree. The sound
fingerprint holds the place, its kind, era and locale, open/dark, the weather,
the part of the day, the headcount bucket, whether the one is talking, vantage,
style, ask — and **the rating**, so a child walking into a room can never be
served the row that was rendered for adults. Three people and five are the same
murmur, rendered once.

Which channels a machine is asked for is `xeric_scene_channels`: none when
scenes are off or there is no machine, look only on an image machine or when
`media.scene.sound.enabled` is false, look and sound otherwise. Sound rows for a
machine that cannot make sound **wait untried** — no try is burned on a machine
that was never going to answer — and the day one that can is chosen, they are
already in the queue. A `generic` machine is assumed able to make both; one that
cannot should say so through the template (`sound.enabled: false`), or its sound
rows will spend their three tries and fail.

---

## The room through its day

Every look row records the **light bucket** it was asked in, and
`xeric_scene_day` hands back the room through its day: the newest finished look
for every light it has been seen in, in the order a day goes — pre-dawn to
lamplit night. Only lights that have a view are keyed, and views from before the
light was written down sit it out. The play view draws it as a strip once there
are two or more.

---

## Ahead of being looked at

`media.scene.prerender` lets the heart draw rooms before anybody asks:

| Mode | What the heart queues |
|---|---|
| `off` | nothing — the player's own room included |
| `here` | the room the player is standing in |
| `occupied` | that, and every staged room with somebody in it now |
| `all` | every staged room in the story |

**Unset, the answer is the machine's cost** (`xeric_scene_prerender`): `here`
on a loopback machine, `off` on anything that bills. The heart runs with nobody
watching, and it must never spend an API key nobody asked it to — that is now
the fourth thing on heart.php's list of things it will not do. A template that
wants prerender on a metered machine says `media.scene.prerender` in so many
words. **A glance at a room is consent to draw it; a heartbeat is consent to
nothing.** No machine, or scenes turned off, is `off` whatever the template says.

Per world per tick the heart backfills (`xeric_scene_backfill` — look, and sound
when the machine and world have it; idempotent by fingerprint, so offering the
same rooms every minute adds a row only when one of them changed) and then
draws at most two (`XERIC_HEART_SCENES`). It skips paused worlds and a busy
model queue — a person waiting on a reply outranks a picture of a room, and on a
local machine they are the same GPU — probes each machine once per pass however
many worlds share it, and resolves the machine under the session the pass is
acting for, so one owner's key never draws another owner's room.

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
   viewport, not a degraded one. It is always synchronous, takes no reference
   picture and makes no sound.
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
  "channel":     "look" | "sound",
  "kind_wanted": ["pano","image"] for a look, preference
                 order (media.scene.kinds, else this);
                 ["audio"] for a sound,
  "prompt":      the composed paragraph, rating clause last,
  "negative":    media.scene.negative, a look's, only when set,
  "brief":       the structured brief — observables only,
                 bearings included for a machine that lays
                 a room out,
  "seed":        the place's interior seed (a sound's own),
  "fingerprint": stable across visits that look alike,
  "size":        [4096, 2048], only when a pano is wanted,
  "quality":     "draft" | "full",
  "timeout_s":   how long Xeric will wait on this call,
  "reference_image":       {mime, b64} — the newest picture
                           of this room, only when one exists,
  "reference_fingerprint": which look that picture was of
}
```

The body is built by one pure function, `xeric_scene_body`, so what an adapter
receives is testable without a machine to receive it.

Concretely — Milldale's fixture, the Bluebird at 07:10 on a Monday, the first
look of the room (so no reference yet), the brief abbreviated:

```json
{
  "channel": "look",
  "kind_wanted": ["pano", "image"],
  "prompt": "a river town of nine hundred in southern Ohio. period-true to present day, nothing anachronistic in view. inside the Bluebird Diner, Eight booths, nine stools, a pie case that has held the same three pies since the Clinton administration. The window fogs from the inside every morning and somebody always writes in it. morning light, Bright and sharp, the first real chill in it. present: Dot Vance, Apron over everything, reading glasses on a chain she never uses for reading — counter, grill, everything. seen from where you stand, just inside the door. a wholesome everyday scene, nothing suggestive.",
  "brief": {
    "key": "bluebird", "name": "the Bluebird Diner", "kind": "diner",
    "now": { "epoch": 1793621400, "hhmm": "07:10", "dow": 1, "phase": "morning" },
    "light": "morning light",
    "weather": "Bright and sharp, the first real chill in it.",
    "open": true, "dark": false,
    "who": [{ "handle": "dot", "name": "Dot Vance", "doing": "counter, grill, everything",
              "at_home": false, "appearance": "Apron over everything, …", "build": "", "wears": [],
              "bearing": 239 }],
    "rating": "sfw", "minor": false, "vantage": "first_person",
    "seeds": { "place": { "exterior": 958866395, "interior": 428416240, "items": [] },
               "faces": { "dot": 660118203 } },
    "…": "channel, exterior, interior, you, setting, enabled, prompt, fingerprint"
  },
  "seed": 428416240,
  "fingerprint": "41048312ac3f57afb7223c08319e0f07e12433f0",
  "quality": "full",
  "timeout_s": 300,
  "size": [4096, 2048]
}
```

`timeout_s` defaults to 300: rooms are slower than faces. The second look at the
Bluebird — 16:00, afternoon light, Theo in the back booth — carries
`"reference_image": {"mime": "image/png", "b64": "…"}` and
`"reference_fingerprint": "41048312ac3f57afb7223c08319e0f07e12433f0"`, the
morning's picture. Theo is a minor, so that prompt ends on the floor's words
(see [Rating](#rating)).

A sound request at the same 07:10 (brief omitted):

```json
{
  "channel": "sound",
  "kind_wanted": ["audio"],
  "prompt": "a river town of nine hundred in southern Ohio. period-true to present day, nothing anachronistic in earshot. the sound of the Bluebird Diner, a diner, in the morning. Bright and sharp, the first real chill in it. one person here, busy and not talking. heard from where you stand, just inside the door. wholesome everyday sound, nothing suggestive.",
  "seed": 1375495960,
  "fingerprint": "sound:2794a047d77b9924bfdc352bee5b8c8ab131bc87",
  "quality": "full",
  "timeout_s": 300
}
```

### Answer

```
{ "kind": "image"|"pano"|"splat"|"glb"|"viewer"|"audio",
  "mime": "...", and EXACTLY ONE of "b64" | "url",
  "extras": [...] optional, "meta": {...} optional,
  "usage": {...} optional, reaches the meter }
```

For example:

```json
{ "kind": "pano", "mime": "image/png", "b64": "iVBORw0KGgo…",
  "meta": { "seed": 428416240 }, "usage": { "credits": 1 } }
```

- **image, pano, splat, glb, audio**: a `url` is fetched **at once**, with the
  same auth header — vendor links expire — and the bytes are stored on this
  disk. Xeric keeps no link to anybody's bucket.
- **audio** must be one of `audio/mpeg`, `audio/ogg`, `audio/wav`, `audio/webm`,
  `audio/mp4`, and is the only kind a sound may come back as. An answer that
  names no kind is taken as an image for a look and audio for a sound.
- **viewer**: the url *is* the scene, a hosted explorable room, and is stored as
  a string with no bytes.
- `meta` (the seed used, cost, the vendor's own id) is recorded on the row as it
  came. `extras` (a depth map, a collider) is kept in it too.

The answer is checked, and refused rather than guessed at: a kind outside the
six, a sound outside the five audio types, both or neither of `b64` and `url`, bad base64, an empty scene, or **a url
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

The sketch handles looks only. A request with `"channel": "sound"` wants
`kind: "audio"` back from a text-to-audio upstream; a proxy with no such
upstream should be paired with `media.scene.sound.enabled: false` in the
template, since anything it answers to a sound will be counted as a failed try.

The proxy owns its own key, so the one Xeric holds (if any) is only the proxy's.
It may answer synchronously when its upstream is fast, and it may return `url`
instead of `b64` — Xeric fetches it immediately either way. A `GET` on the base
should answer something, because that is how "is it up" is asked.

### Reference proxies

Three of them ship in [`tools/proxies/`](../tools/proxies/README.md), one file
each, Python standard library only, so the first proxy anybody writes can be a
copy of one that runs:

- **`skybox.py`** — the Blockade Labs Skybox shape. Makes `pano` only, passes
  the seed, returns the depth map as an extra. It takes no picture to condition
  on, so it remembers which upstream id drew which fingerprint and *remixes*
  that render when a reference rides along — `meta.reference_used: "remix"`.
- **`marble.py`** — the World Labs Marble shape. Answers the world's `pano` by
  default, or its `.spz` as kind `splat` when `media.scene.kinds` puts splat
  first; the collider `.glb` and the other asset ride as `extras`. A reference
  picture becomes an image `world_prompt`. `quality: draft` uses `--model`;
  full uses `--full-model` only when one was typed.
- **`images.py`** — any local `/v1/images/generations`. What kind `image`
  already does, plus the two things that kind cannot: a 2:1 `pano` with
  `--pano` (the owner's word that a 360 LoRA is loaded), and the last picture
  of the room sent to `/v1/images/edits` with `--edits`. Synchronous.

What they share is the contract's other side. The vendor key comes from the
proxy's own environment and nowhere else — Xeric holds none
(`XERIC_SCENE_BASE=http://127.0.0.1:9100`, `XERIC_SCENE_KIND=generic`, no
`XERIC_SCENE_KEY`). Only loopback clients are answered unless `--allow-remote`.
`kind_wanted` is read in order and the first makeable kind answered; nothing
makeable is a `400 {error}` with no asset, so a sound request reads as the
engine's plain *cannot*. An async upstream is waited on inline for `--wait`
seconds, then answered `202 pending`. `meta` always carries `vendor`,
`vendor_id`, `seed_used` and `elapsed_s`.

**The vendors' fields were not verified when these were written.** Each sits
in one `to_upstream` / `from_upstream` pair, every field marked *verify*, and
`--dry-run` prints the upstream request (key redacted) and answers a 1×1 PNG,
so the whole path — reaper, adapter, proxy — runs without an account.
`python3 -I tools/proxies/test_proxies.py` drives all three against a fake of
each upstream.

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
`data:`, and media only from itself (`media-src 'self'`, added for the room's
sound — without it `default-src 'none'` refuses every `<audio>`, silently). So
the play view's **what you see** block is built from what it can serve itself:

- **a panorama** in a small inline WebGL viewer — drag, or the arrow keys, to
  turn; scroll to zoom — with **a name tag for each person present** hung at the
  bearing the engine dealt them, just under the horizon. Each tag is a real
  button: press it and you are in that character's thread. Furniture is not
  tagged; its bearings ride in a data attribute for the next client.
- **a still** as a plain `<img>`, the same name-tag buttons in a row beneath it,
  left to right by bearing.
- splats and `.glb` files **offered as downloads**; a `viewer` scene opens in a
  new tab, because a hosted room cannot be framed under that policy and should
  not be.
- **the day strip**, when the room has been drawn in two or more lights: press
  a thumbnail and the view rebuilds from that render, keeping the way you were
  facing, labelled *earlier: golden hour*.
- **what you hear**: the sound brief's line, always, whenever you are somewhere;
  and when the machine has made the sound for exactly this fingerprint, an
  audio element behind a **listen** toggle — never autoplay, never an older
  sound. Audio is served only when the row's type is on the allowlist and its
  bytes agree.
- **step inside**: a WebXR button that exists only after the browser says
  `immersive-vr` is supported — absent, not disabled. It draws the same
  panorama into each eye in the headset and the flat view again when the
  session ends. It has been run against fakes, not on real hardware.

The bytes are served from this disk, which is the other reason a `url` answer
is fetched at once.

Somebody building a client outside the CSP — a separate page, a headset app —
has better tools: **Pannellum** is the standard drop-in equirect viewer,
**`<model-viewer>`** displays a `.glb` with orbit controls in one element, and
**Spark** renders gaussian splats in three.js. None of them ship here, and
nothing in the engine assumes any of them.

---

## What is deliberately not built

**No consent step beyond choosing a machine** — for a glance. Photos ask once —
*generate photos now?* — because a forge enqueues a whole cast's worth of
renders the owner never individually requested. A scene is requested by
somebody looking: the player is standing in a room and pressed a button to see
it. The owner chose a scene machine, or an image machine, on the machines
screen, and **that choice is the yes.** There is no `approved` column and no
world_state gate, because a gate that only ever re-asks a question already
answered is a second click, not a safeguard. Drawing *ahead* is the one place
the choice of machine is not enough on its own: on a metered machine the
template has to ask for it ([above](#ahead-of-being-looked-at)).

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

**No speech in the room's sound.** The sound channel is texture: voices, never
words. A character's actual lines are spoken by a different machine, line by
line, in the thread — [VOICE.md](VOICE.md).

**No way into the room's conversation from here yet.** Three to five people
talking is `engine/room.php`, and only the CLI can start one today. The view
knows who is standing together; it offers nothing it cannot deliver.

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

Sound has the same rule in its own medium's words, because a photograph's floor
is about clothes and daylight and a sound's is about what may be heard:
`XERIC_SOUND_FLOOR_FRAMING`, *only wholesome everyday sound, nothing
suggestive, fit for a family to overhear*, forced in code, last. Below the
floor, the sound ladder mirrors the picture's: *wholesome everyday sound,
nothing suggestive*; *everyday sound, nothing explicit*; *sound in keeping with
the world's adult rating*.

It is not a knob because it is the one thing a knob would only ever be turned
to break. Every other rating decision is the owner's, made once, about their
own world; this one is about a person who is not in a position to make it, and
**a machine's own classifier is never the only control.** A minor in the room
is a minor in the frame. That was true of photographs first, and a room is a
photograph one size up.
