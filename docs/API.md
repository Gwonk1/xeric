# The client API — v1

`forge/web/api.php` is the versioned, documented way for something that is not
`play.php` to read a running world: a headset page, a native client, a script.
It is thin on purpose. Every route calls a function a page already calls and
hands the answer to a shaper in `forge/web/api-lib.php`; if a client needs a
fact that is not here, the fact goes into the engine first (where.php's rule),
never into the client and never only into this file.

**Both halves.** The reads call existing functions directly. The verbs (go,
look, say, read, skip, rewind, hear, the room, a line read aloud) each
`require` the existing script unchanged — where.php, scene.php, say.php,
tick.php, hear.php, room.php, speak.php — through boot.php's two seams, so
their doors, limits and refusals are theirs and arrive enveloped. The link and
join ceremonies hand a device its token ([FUTURE.md](FUTURE.md), Appendix C).

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
reworded at any time. Kinds raised here: `token` (401), `cross_site`,
`guest`, `host`, `insecure` (403), `no_world`, `no_route`, `version`,
`no_character`, `no_look`, `no_message`, `not_rendered`, `no_voice`,
`no_job` (404), `method` (405), `code` (410), `limit`, `locked` (429),
`bug` (500). The verb scripts add their own — `travel` (400), `pace` (429,
with `retry_after`), `paused`, `detached`, `room`, `quiet` (409),
`model_down`, `busy` (503), `refused` (422) — unchanged. A refusal raised deep inside the
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

**Seen, never cookied.** Every Bearer-identified call marks the device row
and the session record seen (to the minute), so a session used only through a
headset is not swept as idle — through `xeric_session_seen()`, which sends no
`Set-Cookie`. (`xeric_session_touch()` would hand the device the owner's raw
sid, a credential no revoke could take back.)

**Tokens work on api.php and nowhere else.** Machine admin stays on the box:
`link.php`, `model.php`, `power.php`, `notify.php` and the forge never read
an `Authorization` header (a device is a stranger there), and `link.php`
refuses a request that carries one outright.

**The Host pin (DNS rebinding).** A page whose name has been re-pointed at
127.0.0.1 is same-origin to itself and its requests arrive from this machine
— on a solo install, the owner, with no cookie at all. So a **solo**-identified
request whose `Host` is not `127.0.0.1`, `localhost`, `[::1]`, an IP literal
(rebinding needs a name) or the configured bind host (`XERIC_BIND`, or
`XERIC_HOST`), on this server's port (`SERVER_PORT` / `XERIC_PORT`), is a
**403 `host`**. Bearer requests are not pinned (a token is not ambient);
`link.php` pins too, because a rebound page could otherwise mint a code there
and read it. The proper home is `xeric_web_solo()` in boot.php, where it would
cover every page; until it moves there it is enforced by the API and link.php.

---

## Getting a token

Two ceremonies, both on pair.php's discipline — eight characters from an
alphabet with no 0/O/1/I/L, five minutes, single use, stored hashed, the burn
and the mint in one transaction, and a door that shuts after wrong guesses.
Every wrong answer (never minted, run out, already used) is one sentence and
one status, **410 `code`**; the lockout is **429 `locked`** with `Retry-After`.
Neither route asks who is calling — the code is the credential — but both
refuse cross-site.

### `POST link {code, label?}` — the same person, a second device

The owner opens **`link.php`** on the box (solo, or their cookie; never a
token), presses *Make a code*, and types it into the device. The answer is a
token for **the owner's own session**, role `owner`:

```json
{ "api": "1.0", "ok": true, "token": "xd1_…", "role": "owner", "label": "the headset",
  "adult": true, "device": 7 }
```

The **adult bit** is set at link time from the session's affirmation (and
again as it stands at redemption); ticking *For a child* on link.php mints it
lowered. On link.php the owner sees this session's devices (label, role,
adult, last seen), can **lower** one (a kids' headset is one flag) and can
**unlink** one — its next request is a 401. Nothing raises a bit. The link
door shuts for 120 s after **5** wrong codes, machine-wide (a guesser does not
say whose code they guess at). On `--lan` link.php also draws a QR of
`http://LAN:8787/api.php/v1/#link=CODE` — the code in the fragment, which no
server ever logs.

```sh
curl -s -H 'Content-Type: application/json' -d '{"code":"7KQ4MZ2P","label":"the headset"}' $X/link
```

### `POST join {world, code, name}` — a new player

A code from the owner's *invite someone* (play.php, `a=invite`), redeemed
exactly as join.php redeems it — the owner's real `world.db`,
`xeric_pair_claim()` (8 wrong guesses per world shut it), a new session that
remembers which player it is — with a token handed back instead of a
redirect. Role `guest`, and `adult: false`: the guest's session was never
asked. A guest chats and skips; walking, looking, hearing, the room's doors
and rendering a voice are the owner's and answer 403 `guest` from the scripts
themselves. (A guest session with no machine attached that opens a running
world stops its clock, as a browser guest's does; that is xeric_play_open's
reconcile and the API does not paper over it.)

```json
{ "api": "1.0", "ok": true, "token": "xd1_…", "role": "guest", "world": "milldale",
  "player": 2, "label": "Sam", "adult": false }
```

```sh
curl -s -H 'Content-Type: application/json' -d '{"world":"milldale","code":"H8RT3WQX","name":"Sam"}' $X/join
```

### The secure-context rule

A Bearer token is a long-lived credential; over plain http on a LAN, anybody
who reads one packet has it. So **`POST link` and `POST join` refuse to mint
(403 `insecure`, and the code is not used up) over plain http unless**:

- the request is over TLS (`HTTPS` set by the server); or
- the client is **loopback** (127.0.0.1 / ::1). Two no-TLS setups land here:
  **Quest Link** with desktop Chrome at `http://127.0.0.1:8787`, and a Quest
  in developer mode with **`adb reverse tcp:8787 tcp:8787`**. So does
  **`tailscale serve --bg http://127.0.0.1:8787`**, which terminates TLS on
  the tailnet and hands the request on from loopback — the recipe for any
  other device; or
- the operator has set **`XERIC_LAN_TOKENS=1`**, accepting the risk on a
  network they trust.

---

## Routes

### `GET /` — what this machine is

```json
{ "api": "1.0", "ok": true, "version": "1.0", "versions": ["v1"],
  "auth": { "modes": ["bearer", "solo"], "via": "solo", "role": "owner", "link": true, "join": true },
  "features": { "model": true, "scene": false, "voice": true, "image": false, "ears": false,
                "pulse": true, "sse": true, "write": true } }
```

`auth.modes` is what this install accepts (`cookie` or `solo`, plus
`bearer`); `auth.via` is how this request was identified. `features` says
which machines this session has configured — **booleans only, never an
address or a key**. `write` is true: the verbs below are served.

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
  "beat": 1793612345,
  "room": {"conv": 51, "place": "bluebird", "last": 430, "turn": true, "typing": "dot",
           "present": ["dot", "ruth"], "said": 2, "beats": 3},
  "cursor": "9f2c…", "epoch": 1793608745, "server": 1793612345 }
```

**The cursor** is a hash of: the clock's offset, whether it is paused, where
the player is, who is where right now (the whole cast, so a schedule turning
over counts), the newest message id, the newest event id, the unread counts,
the room-render queue (`scenes`), the heart's last beat, and the open room
(`room`: room.php's own summary, cut to what moves — so the cursor moves when
somebody at the table types, speaks, comes or goes, or the table closes). It is **not** a hash of the epoch: a running
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
false; a read never calls a machine), plus `world`. `brief` is the
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
`POST threads/{h}/read`.

```sh
curl -s "$X/worlds/milldale/threads/dot?since=410"
```

### `GET worlds/{w}/messages/{id}/audio` — a line, out loud

The clip a character's line was last voiced with, if it is still on disk,
sniffed as speak.php sniffs it. **A cached clip only**: a line never read
aloud is a 404 `not_rendered`, because rendering spends the owner's voice
machine and that is a verb. The player's and the narrator's lines have no
voice (404 `no_message`). `POST` the same route to render a miss (below).

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

## The verbs

Every verb is a `POST` (the room's poll is a `GET`); a wrong method on a real
route is **405** with `Allow`. The world, the handle and the action come from
the path, never the body, and only the fields a verb takes are read from the
body (a `rewind` sent to `skip` is not heard). Bodies are JSON (or a form;
`hear` is multipart).

**CSRF.** Bearer-identified requests pass the scripts' cross-site guard — a
browser never sends an `Authorization` header on its own, and a page on
another origin that tried would need a CORS preflight this API never answers.
Cookie- and solo-identified cross-site requests are refused (403
`cross_site`) on every method before any script runs. Owner doors and limits
are the scripts' own and arrive enveloped; so does a script's bodiless
refusal (speak.php's bare 404).

**Shaped on the way out** like everything else, plus: the world is named; a
`state` (say.php's) carries the runnable clock (`epoch`, `offset`, `server`);
a job's `stream` is the v1 `jobs/{id}` route, never progress.php; a said line
carries its `epoch` beside `when`, as do the room's rows.

| route | the script | answers |
|---|---|---|
| `POST worlds/{w}/go {to}` | where.php | `went` {from, to, minutes, open, who, scene} + the map. `to` absent/null is leaving the map |
| `POST worlds/{w}/look` | scene.php `a=look` | `{job, stream, fingerprint, pending, sound}`; 409 with no scene machine |
| `POST worlds/{w}/threads/{h}/say {text}` | say.php | `{id, text, when, epoch, name, seconds, waited, photo, story, state}`; 429 `pace` / `queued` with `Retry-After` |
| `POST worlds/{w}/threads/{h}/read` | what play.php's a=thread does beyond the thread: `xeric_play_mark_read` + `xeric_learn_read` | `{handle, read, unread (as it was), conversation_id, learned}` |
| `POST worlds/{w}/skip {span}` | tick.php | `{job, stream, span, label, from}`; follow the job |
| `POST worlds/{w}/rewind` | tick.php `rewind` | xeric_rewind's answer; 409 when there is nothing to take back |
| `POST worlds/{w}/hear` multipart `{audio, h?}` | hear.php | `{text}` — text only; the client puts it in the box and says it |
| `GET worlds/{w}/room?after=<id>&c=<conv>` | room.php's poll | `{live, conv, rows[{id, who, h, name, text, when, epoch}], last, poll, guest}` |
| `POST worlds/{w}/room/say {text}` | room.php `a=say` | `{id, text, epoch, opened, conv, live}` |
| `POST worlds/{w}/room/carry` | room.php `a=carry` | `{}`; 409 `quiet` is the room keeping quiet |
| `POST worlds/{w}/room/leave` | room.php `a=leave` | `{conv}` |
| `POST worlds/{w}/messages/{id}/audio` | speak.php | the clip's bytes: a hit is a file read, a **miss renders** (owner door, `reroll` meter); `GET` stays cache-only |

```sh
T='Authorization: Bearer xd1_…'; J='Content-Type: application/json'; W=$X/worlds/milldale
curl -s -H "$T" -H "$J" -d '{"to":"beck_hardware"}'          $W/go
curl -s -H "$T" -X POST                                      $W/look
curl -s -H "$T" -H "$J" -d '{"text":"Is there coffee?"}'     $W/threads/dot/say
curl -s -H "$T" -X POST                                      $W/threads/dot/read
curl -s -H "$T" -H "$J" -d '{"span":"evening"}'              $W/skip
curl -s -H "$T" -X POST                                      $W/rewind
curl -s -H "$T" -F audio=@line.webm -F h=dot                 $W/hear
curl -s -H "$T"                                              "$W/room?after=412"
curl -s -H "$T" -H "$J" -d '{"text":"Morning, all."}'        $W/room/say
curl -s -H "$T" -X POST                                      $W/room/carry
curl -s -H "$T" -X POST                                      $W/room/leave
curl -s -H "$T" -X POST -o line.mp3                          $W/messages/412/audio
```

---

## One exchange, worked

Appendix C's sequence — aim at Dot's tag in a headset, pinch, hold and speak,
release, hear her answer — is five calls:

```sh
# 1. look: the room you stand in, the tags' people in brief, the view's src
curl -s -H "$T" $W/look
#   → {"brief": {"key": "bluebird", …}, "scene": {"src": "api.php/v1/worlds/milldale/look/…"}, …}

# 2. read: the pinch on Dot's tag focuses her thread — the dot out, the learn signal
curl -s -H "$T" -X POST $W/threads/dot/read
#   → {"handle": "dot", "read": true, "unread": 1, "learned": true, …}

# 3. hear: what was said while holding, as text (the caption the player checks)
curl -s -H "$T" -F audio=@hold.webm -F h=dot $W/hear
#   → {"text": "Is the pie fresh today?"}

# 4. say: the caption, said — one chat turn (429 pace/queued: wait Retry-After)
curl -s -H "$T" -H "$J" -d '{"text":"Is the pie fresh today?"}' $W/threads/dot/say
#   → {"id": 431, "text": "Baked at five.", "epoch": 1793608800, "state": {…}, …}

# 5. audio: her line out loud — a miss renders on the owner's voice machine, once
curl -s -H "$T" -X POST -o dot.mp3 $W/messages/431/audio
```

Then the pulse's cursor moves (a new message), and a second device reading
the same world sees it at its next conditional `GET pulse`.
