# The client API — v1

`forge/web/api.php` is the versioned, documented way for something that is not
`play.php` to read a running world: a headset page, a native client, a script.
It is thin on purpose. Every route calls a function a page already calls and
hands the answer to a shaper in `forge/web/api-lib.php`; if a client needs a
fact that is not here, the fact goes into the engine first (where.php's rule),
never into the client and never only into this file.

**This is the read half.** Every route is a `GET`. The verbs (say, go, look,
skip, rewind, hear, read, the room) and the link and join ceremonies that hand
a device its token are the write half, still to come
([FUTURE.md](FUTURE.md), M2 and Appendix C).

---

## Where it is

```
api.php/v1/<route>            PATH_INFO, the canonical form
api.php?r=/v1/<route>         the same route, for a host that strips PATH_INFO
```

No rewrite rules: `.htaccess` deliberately has none, and `php -S` (which is
what `./xeric` runs) supports PATH_INFO as it is. Links the API hands out (a
look's `src`) are built in whichever of the two shapes the request arrived in.

The examples below assume `./xeric` on its default address,
`http://127.0.0.1:8787`, where a request from this machine is the solo owner:

```sh
X=http://127.0.0.1:8787/api.php/v1
```

---

## Versioning

- **Major in the path** (`v1`). **Minor in every body** (`"api": "1.0"`) **and
  in a header** (`Xeric-Api: 1.0`), on every answer: the 304, the bytes, the
  stream and every refusal included.
- **Within v1, additive only.** A new route, a new field, a new value of
  `kind`: a minor bump. Clients ignore fields they do not know.
- **Removal, rename, retype or a change of unit is v2**, which will run beside
  v1 for a release with `Deprecation` and `Sunset` headers.
- **play.php's wire is v0**: private to that page, free to change, and not a
  contract. Nothing outside `play.php` should read `play.php?a=…`.
- **The contract test** (`forge/web/tests/api-test.php`) snapshots the sorted
  key paths of every route's body into `forge/web/tests/api-contract.json`. A
  promised key that disappears fails the suite; a new key is printed as a note.
  After an additive change, regenerate the snapshot and commit it with the
  change:

  ```sh
  XERIC_API_CONTRACT=write php forge/web/tests/api-test.php
  ```

---

## The envelope

Every JSON body:

```json
{ "api": "1.0", "ok": true, "...": "the route's fields" }
```

Every refusal, with the HTTP status that matches it:

```json
{ "api": "1.0", "ok": false, "error": "a sentence a person can read", "kind": "no_world" }
```

`kind` is a short machine-readable word; `error` is for people and may be
reworded at any time. Kinds the read half raises: `token` (401),
`cross_site` (403), `guest` (403), `no_world`, `no_route`, `version`,
`no_character`, `no_look`, `no_message`, `not_rendered`, `no_job` (404),
`method` (405), `limit` (429), `bug` (500). A refusal raised deep inside the
app (a limit, an owner-only door, the floor under every page) arrives in the
same envelope: api.php registers its shaper with boot.php's
`xeric_web_json_shaper()`, which every JSON body in the app passes through.

**Normalised on the way out**, so a client never sees the old wire's
accidents: a world is always `world` (never `w`); `ok` is always a boolean
(never `1`); a refusal's sentence is always `error` (never `why`); a message's
id is always `id` (never `mid`); a job note's sentence is always `text` (never
`message`); every refusal has a `kind`; no body carries a `*_html` key; every
message carries its world `epoch` beside the display string `when`.

No CORS headers are sent, by design: a page on another origin cannot read
anything here.

---

## Who is asking

Resolution order, first match wins:

1. **`Authorization: Bearer xd1_…`** — a device token. `xd1_` and 32 random
   bytes in hex, kept only as a sha256 hash in `<data_dir>/api-tokens.db`,
   mapping to {session, role (`owner` a linked device, `guest` a joined
   player), label, adult, seen}. A token that does not resolve — malformed,
   unknown, revoked, or whose session has been swept — is a **401**, never a
   fall-through to the cookie or solo. A credential that is not a Bearer token
   is a 401 too.
2. **The session cookie** (`xeric_forge`), exactly as every page reads it.
3. **Solo**: on a single-user install a request from this machine is the
   owner, cookie or not.

The answer is put through `xeric_session_use()`, the same mechanism the
detached workers use, so ownership, forks, machines, the meter and the limits
resolve against it without knowing a token exists.

**Cross-site.** A cookie and the solo identity ride along with any request a
browser sends, whoever's page sent it, so a cookie- or solo-identified request
that says it came from another site (`Sec-Fetch-Site: cross-site`, or an
`Origin` that is not this host) is refused with a **403 `cross_site`** on
every method, reads included. A Bearer header is never sent automatically, so
a header-less native client is not refused.

**Tokens never in URLs** — `php -S` prints request lines to its log.
`?token=` is ignored.

**Behind Apache with PHP-FPM** the `Authorization` header is dropped unless
the vhost says `CGIPassAuth On` (or the `SetEnvIf Authorization` idiom); with
mod_php or `php -S` it arrives as it is.

**Rating, per viewer.** What a viewer may be shown is the session's
affirmation **and** the token's own `adult` bit, handed to
`xeric_play_open()`. A kids' headset is one flag. The gap, stated: clamping
happens at generation, so a thread or a render made earlier for an adult
session reads as it was made.

**Today**: cookie and solo, and tokens resolved read-only. Nothing in the app
mints a token yet; `xeric_api_token_mint()` is used only by the tests. The
link (same person, second device) and join (a new player) ceremonies, built on
pair.php's five-minute, single-use, hashed, rate-limited codes, are the write
half's.

---

## Routes

### `GET /` — what this machine is

```json
{ "api": "1.0", "ok": true, "version": "1.0", "versions": ["v1"],
  "auth": { "modes": ["bearer", "solo"], "via": "solo", "role": "owner", "link": false, "join": false },
  "features": { "model": true, "scene": false, "voice": true, "image": false, "ears": false,
                "pulse": true, "sse": true, "write": false } }
```

`auth.modes` is what this install accepts (`cookie` or `solo`, plus
`bearer`); `auth.via` is how this request was identified. `features` says
which machines this session has configured — **booleans only, never an
address or a key**. `write` turns true when the write half lands.

```sh
curl -s $X/
```

### `GET worlds` — the shelf

`{worlds: [{slug, name, blurb, ok, cast, places, rating, built, lived, mine,
copy, launched, permanent, scene, paused}]}` — xeric_play_worlds(). `ok` on a
row says whether its template loads; `mine` whether this viewer forged it;
`copy` whether they have a fork of it.

```sh
curl -s $X/worlds
```

### `GET worlds/{w}` — the world, as data

xeric_play_state() with every `*_html` key dropped, plus:

| field | |
|---|---|
| `world` | the slug |
| `mine`, `guest` | forged by this viewer; or a guest in the owner's real world (not a fork) |
| `rating` | the rating this viewer is reading it at, after the clamp |
| `queue` | the model queue, as `a=me` reports it |
| `clock` | `when`, `hhmm`, `phase`, `off` (a label), `paused`, **`epoch`** (the world's now), **`offset`** (signed seconds the world runs ahead of wall time), **`server`** (the server's wall clock when it answered) |
| `spans` | the time control's buttons |
| `rewind` | the rewind window, or null |
| `cast` | every person: handle, name, where, doing, unread, … |
| `where` | the map (as `worlds/{w}/map`) |
| `fate`, `story` | death mode and the overlays |

**Run the clock yourself**: a running world's now is
`clock.epoch + (your_now − clock.server)`; a paused one's is `clock.epoch`.

```sh
curl -s $X/worlds/milldale
```

### `GET worlds/{w}/pulse` — what changed

A conditional short poll, not a stream: `php -S` has six workers (one on
Windows) and a say turn can hold one for 150 s, so nothing here holds a
connection open.

```json
{ "api": "1.0", "ok": true, "world": "milldale", "offset": -3600, "paused": false,
  "where": "bluebird", "here": ["dot"], "last_message": 412, "last_event": 37,
  "unread": [{"handle": "ruth", "n": 2}],
  "scenes": {"pending": 0, "working": 1, "done": 9, "failed": 0, "latest": 14},
  "beat": 1793612345, "room": null,
  "cursor": "9f2c…", "epoch": 1793608745, "server": 1793612345 }
```

**The cursor** is a hash of: the clock's offset, whether it is paused, where
the player is, who is where right now (the whole cast, so a schedule turning
over counts), the newest message id, the newest event id, the unread counts,
the room-render queue (`scenes`), the heart's last beat, and (when the room on
the web lands) the open room. It is **not** a hash of the epoch: a running
clock changes every second and a client runs it from `offset`.

**The ETag is the cursor.** Send it back as `If-None-Match` and an unchanged
world answers **304** with no body (a few milliseconds); a changed one answers
200 with a new ETag. `Cache-Control: private, no-cache`. When the cursor
changes, fetch what you show (`worlds/{w}`, a thread, the look).

```sh
curl -si $X/worlds/milldale/pulse | grep -i etag
curl -si -H 'If-None-Match: "9f2c…"' $X/worlds/milldale/pulse | head -1    # HTTP/1.1 304
```

### `GET worlds/{w}/map` — the town

xeric_travel_map(), plus `world`: `now`, `you` (`where`, `place`, `since`,
`leave.minutes`), `mapped`, `skipping`, `how`, `across`, and `places[]` with
`key`, `name`, `kind`, `description`, `at` {x, y}, `open`, `dark`, `here`,
`minutes` and `who[]` {handle, name, doing, at_home}.

```sh
curl -s $X/worlds/milldale/map
```

### `GET worlds/{w}/look` — the room you stand in

xeric_play_look_view() with the machine **not** probed (`machine.up` is
false; the read half never calls a machine), plus `world`. `brief` is the
engine's observables-only brief or null; `scene` the newest showable view
{id, kind, fingerprint, mime, url, src, meta, stale} or null; `day[]` the room
through its day; `sound` {line, fingerprint, src, mime} or null; `pending`;
`machine` {up, costly, kind}. Every `src` is a v1 link to the bytes route.

```sh
curl -s $X/worlds/milldale/look
```

### `GET worlds/{w}/look/{k}/{f}` — a view's bytes

scene.php's bytes, by scene.php's own function (`xeric_play_look_file`): a
still or a pano only if its bytes sniff as PNG, JPEG or WebP; a splat or glb
as an attachment off a two-type allowlist; a sound (`f` = `sound:<fp>`) only
when the row's type and the bytes' magic agree. `nosniff`,
`Cache-Control: private, max-age=3600`. Anything else is a 404 `no_look`.

```sh
curl -s -o room.png $X/worlds/milldale/look/bluebird/0a1b…   # the scene.src above
```

### `GET worlds/{w}/threads/{h}?since=<id>` — one thread

```json
{ "api": "1.0", "ok": true, "world": "milldale", "handle": "dot", "name": "Dot",
  "dead": false, "how": "", "one_line": "…", "conversation_id": 3,
  "since": 410, "last": 412,
  "messages": [ { "id": 411, "role": "user", "who": "you", "text": "…", "when": "Tue 09:58", "epoch": 1793608680 },
                { "id": 412, "role": "character", "who": "Dot", "text": "…", "when": "Tue 09:59", "epoch": 1793608740 } ] }
```

xeric_play_thread(): the newest 60 messages, or with `since` only those with a
larger id (up to 500). `epoch` is the world time it was said at; `when` stays
for display. A message may carry `photo` {k, s, caption}. `last` is the
highest id in the answer (or `since`): pass it as the next `since`.

Reading a thread **puts its unread dot out**, as play.php does. The learn
signal ("this person, not that one") is not recorded by a read; that is the
write half's `POST threads/{h}/read`.

```sh
curl -s "$X/worlds/milldale/threads/dot?since=410"
```

### `GET worlds/{w}/messages/{id}/audio` — a line, out loud

The clip a character's line was last voiced with, if it is still on disk,
sniffed as speak.php sniffs it. **A cached clip only**: a line never read
aloud is a 404 `not_rendered`, because rendering spends the owner's voice
machine and that is a verb. The player's and the narrator's lines have no
voice (404 `no_message`).

```sh
curl -s -o line.mp3 $X/worlds/milldale/messages/412/audio
```

### `GET jobs/{id}?from=<n>` — a long thing, followed

```json
{ "api": "1.0", "ok": true, "job": "a3f…", "done": false, "next": 3, "count": 3,
  "records": [ { "id": 2, "kind": "note", "text": "drawing the room", "at": 1793612340.5 } ] }
```

xeric_web_job_read(): records from line `from` on. `kind` is the record's
own (`note`, `done`, `meter`, …), with `error` renamed `failed` as the stream
has always named it. Poll with `from = next` until `done`.

With `Accept: text/event-stream` the same job is streamed by progress.php
(SSE; ids are line numbers; resume with `Last-Event-ID`), each frame's data
in the envelope. The stream hangs up on its own every ~40 s with a `pause`
frame; an EventSource reconnects by itself.

```sh
curl -s "$X/jobs/a3f…?from=0"
curl -sN -H 'Accept: text/event-stream' "$X/jobs/a3f…"
```

---

## For whoever builds the write half

- boot.php has the two seams: `xeric_web_input_set(array)` hands a verb
  script the body the router read; `xeric_web_json_shaper()` is already
  registered by api.php, so `require`ing say.php or where.php inside api.php
  answers in the envelope. Reuse `xeric_api_shape_state()` for the `state` a
  verb answers with and `xeric_api_shape_look()` for a look.
- The pulse has a `room` slot (marked `SEAM(room)` in api-lib.php) waiting for
  the room on the web.
- Tokens: `xeric_api_token_mint()` and `xeric_api_token_resolve()` are in
  api-lib.php; the table has `revoked_at` for `DELETE me/devices/{id}`.
