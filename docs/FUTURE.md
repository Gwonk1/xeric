# Where this goes — the holodeck plan

*November 2026. A working plan with a horizon of about two years. Dates are
absent on purpose, as in the roadmap; milestones are ordered, not scheduled.
ROADMAP.md stays the near-term working list; this is the argument for what
that list should contain and in what order. It was written against the code
as it is on this branch — 24 suites, 2,599 assertions, all green — and against
the outside world as it stood on the date above (Appendix D), which will
date faster than anything else in here.*

---

## The thesis in one paragraph

Xeric is the continuity layer, and the holodeck is reached by making every
renderer answer to it — never by Xeric becoming a renderer. Scene machines,
voices and world models will get better, cheaper and more numerous, and they
will churn; the thing that does not churn is who knows what about whom, who
wore what, who owes whom, and what the pie case looked like last Tuesday. The
first week of the viewport proved the shape: a room was rendered, a cast was
voiced, a sound was composed, and not one of those features taught the engine
a new fact. Everything a renderer needed, the engine already knew. That is the
pattern to hold for two years. When a streaming world model can finally keep a
diner's booths in the same place for an hour, Xeric will be the thing that
tells it which diner, which hour, who is in it and what they are wearing — and
will carry on telling it after that model is replaced by the next one.

The outside world agrees with the shape more than it did a year ago. The most
interesting open-weight world model of the autumn, Matrix-Game 4.0, keeps the
world's state in a game engine and uses the video model only to draw the
engine's low-poly frames; every hosted model now accepts a starting image, and
several accept structure (a blockout, a depth map). The state lives outside
the model and the model draws it. That is Xeric's architecture, arrived at
from the other direction.

---

## The bets, stated so they can lose

1. **Renderers commoditize; continuity does not.** So: adapters and
   conditioning, never a renderer. Every generated thing in Xeric is a
   function of template data and a seed, and takes the last version of itself
   as a reference. If a provider offers a better room than our brief describes,
   we improve the brief, not the room. *Loses if* a vendor's own persistent
   world becomes the thing people want to live in, and its memory of a place
   beats ours. Independent tests today put every model's visual memory under a
   minute; vendors say minutes; nobody says hours of returning to a place you
   already saw.
2. **Local-first stays viable.** Small models are getting better faster than
   worlds are getting bigger, and media generation is moving onto consumer
   GPUs. The runtime stays comfortable on a 12B; the forge may borrow a big
   model once; the heart must be able to draw a room on a laptop GPU for the
   price of electricity. *Loses if* the good renderers stay hosted and metered
   forever. Today a room generated once costs about $0.12–$1.20 hosted and a
   streamed hour about $72; a 2:1 panorama from a FLUX LoRA runs in 8–16 GB
   locally. The credible 2027 picture is interactive 480–720p on 24–32 GB
   cards, still with under a minute of memory — which is exactly where an
   external continuity layer earns its keep.
3. **The surface is cheap.** where.php made this bet and the viewport cashed
   it: a first-person view needed nothing the engine did not know. A versioned
   client API makes a headset page, a phone page and a terminal all clients of
   the same read model, and a new one a weekend rather than a rewrite. *Loses
   if* the API grows HTML fragments and page-private conventions the way
   play.php's wire did.
4. **Conditioning beats memory.** The room stays the same because the last
   picture rides along with the request; the character stays the same because
   their bible rides along with every prompt; the voice stays the same because
   the deal is a hash of the name. At every layer the answer to drift is the
   same: do not ask the model to remember, hand it the thing. *Loses if* a
   small model, handed the thing, still drifts — which is what the continuity
   benchmark exists to measure.
5. **Code disposes.** Anything that must be true — a wall, a rating floor, a
   budget, where a key may travel — is enforced in code and tested with
   negative assertions. The model proposes moments, framings, lines. It never
   gets to decide what is true. *Loses if* it is ever found more convenient to
   ask the model, and the test suite does not catch it.

---

## Where we stand

The roadmap is stale in both directions. Some of what it files under Next has
shipped; some of what it calls built is wired to nothing.

| Roadmap heading | Truth | Evidence |
|---|---|---|
| The heart | Built, and it learns | `heart.php`; all four refusals coded; it now runs `xeric_learn_pass` at the end of its hold, so a week lived unattended turns friendly replies into trust [e5a73b4]; it seals idle rooms before it sweeps [0b75ac0] |
| The undo is one step deep | Still true | rewind is the last skip; one `world-template.prev.json` |
| The Narrator | Partly | ask, investigate and write-ahead exist (`xeric_narrator_ask`, `_investigate`, `_intend`) but are **CLI only**; `xeric_sweep_choose` accepts intents and nothing calls `xeric_narrator_leans`, so write-ahead changes nothing; the web "ask the narrator" is a separate 30-word hint; author is not built |
| The Director | Not built | only a ×2 casting lean |
| Giving the player a body | Built | `xeric_player_where`, `xeric_travel_go`, `xeric_travel_map`; the roadmap says so and still files it under Next |
| The room | Built, on the web | `forge/web/room.php` and `room-worker.php` over `xeric_room_step`: talk to the room where two or more are present, lines land one at a time as message rows, the room may carry on a beat or two without you; `xeric_room` keeps watch's CLI rule [0b75ac0] |
| Death | Built | revivable and permanent, catastrophe and restore, stories can kill; fate.php; not marked shipped |
| Social constructs | Partly | expectations with repair and a `kept` state (`xeric_expect_keep`, [e5a73b4]), debts, gossip, guest vouching are built; confidences exist but `xeric_confide_form`/`_block` are never called; anticipation, news, worry, grudges, rituals, milestones and cast-to-cast are not built |
| Story overlays | Built, filed under Later | multiple overlays, red herrings, a six-stage curve |
| A world can start in 1873 | Built | tested |
| More than one model | Beyond the roadmap | a per-character pin exists for say and watch; not for sweeps |
| The viewport | Built | the room in the view [0b75ac0] and walking inside a splat, in our own WebGL renderer [934bbe6]; streaming is not |
| Voices | Built this week | VOICE.md still has one "not shipped" line to fix |
| Design rules | Held | the four gendered-prose sites and four more were fixed, and render-test now tokenises every engine file and fails on a gendered pronoun in a string literal, file:line, with a planted pronoun to prove the scan sees [e5a73b4] |

Other facts the plan has to respect:

- **Multiplayer is LAN co-op in one shared body.** join.php joins the owner's
  real world; forks are for strangers on a shared host. Players have their own
  trust, warmth, debts and promises, but the clock, weather, mood, the
  position (`player.where`) and the thread per character are shared, and
  messages carry no player id. Guests see each other only as a sidebar roster.
  Walking, looking, rewind, fate, photos and power are owner-only.
- **CI exists; there is still no lint. There is now a token budget** — a
  per-world ceiling on tokens, renders, clips and hearings per world-day,
  read before every spend, off until the template sets one
  (`engine/budget.php`, [`docs/BUDGET.md`](BUDGET.md)) [934bbe6].
  `.github/workflows/tests.yml` runs every suite on PHP 8.1 and 8.3 on every
  push and pull request, with Chrome and Node installed so the smoke and
  viewer suites really run, and `XERIC_REQUIRE_BROWSER` /
  `XERIC_REQUIRE_NODE` turning a missing tool into a failure there [42a3bd4].
  The suite is 36 suites and about 4,100 assertions: 4,096 `ok` lines from
  the 35 suites that ran in this sandbox after 934bbe6, counted by running the
  CONTRIBUTING loop with `XERIC_SOLO=0` and summing lines that start `ok`;
  the smoke test is excluded because full Chrome hangs here without D-Bus and
  no headless shell is installed, and demo-test's one DNS check printed SKIP.
  The four Node-driven suites (viewer, map, splat, stand) ran.
  The limits are message, skip, reroll and forge counts per hour and per IP,
  off unless caps are set. The meter is a receipt. The Node harness for the
  viewer's JavaScript is committed (`viewer-test.php` + `viewer-test.js`, 84
  assertions). heart.php is still checked only for HTTP denial and the
  detached workers only by source grep.
- **The big files.** [After 934bbe6: seven files over 2,000 lines — see
  Measures; play-lib.php is 2,920 since its parts moved out in 5ef82aa.]
  At the plan: play-lib.php 6,762 lines with 139 functions (24 of them
  machine discovery that belongs in model.php, and about 1,140 lines of CSS);
  forge.php 5,974; play.php 4,366 of which about 3,070 are inline JavaScript
  including the WebGL and WebXR viewer; review.php about 1,140 lines of inline
  JavaScript.
- **Deferred work is tracked in [`docs/PUNCHLIST.md`](PUNCHLIST.md)** [42a3bd4],
  each line with a commit or file. History starts at a 76k-line import, and
  older commits refer to U-numbered review items nobody outside can read. No
  TODO, FIXME or XXX exists anywhere in the code; the punchlist is where they
  go.
- **Unwired things offered in the UI**: the `death` and `story` notify kinds
  are offered and never sent; guest `stranger` and `written_in` modes are
  unreachable because the UI always posts `{}`.
- **Platform**: bootstrap enforces PHP 8.1+ while docblocks say 8.2+; it
  checks pdo_sqlite, sqlite3, mbstring, json and zlib — curl, which the
  quickstart lists, is never used; ctype and openssl are needed and not
  checked. Windows has xeric.cmd and heart.cmd and a single-request `php -S`;
  the test suites need a POSIX shell.

What shipped in the viewport week, as a reminder of the pattern:

- `engine/scene.php` — the room as a brief; fingerprints; bearings; reference
  chaining; the sound channel; prerender; the day strip. 161 assertions.
- `engine/voice.php` — dealt, never stored voices; what is spoken is what was
  shown. 131 assertions.
- The sidebar's "what you see": pano, still, link, download, stale, pending;
  name tags you can press; the listen toggle; step inside. 91 + 36 web
  assertions.
- The fork's external llama.cpp support, merged, with three key-handling holes
  and one fetch-on-trust closed on the way in, and a GET-that-spends guard
  found while planning the API.

---

## The milestones

Each is named for the sentence a person would say when it is true. Each ends
with the test that says it is.

### M1 — "The room answers back"

The viewport shows people; you should be able to talk to the room, not only
to one thread. This is the roadmap's three-way conversation finally given a
web surface, and it is the single thing that makes a room feel unlike a chat
window. Appendix A is the plan in full; the shape in brief:

- Lines land one at a time as message rows in a conversation of kind `room`,
  so the thread, the book, speak.php and the inspector all work on them
  unchanged. The bookkeeping lives in one world_state row because the player
  has one body.
- Who answers is `xeric_room_draw` with the player as a speaker: a name in the
  line answers first; otherwise reach × heat, where heat counts what each
  person holds about the player. Who stays silent is recorded, because silence
  is information.
- Pacing is a floor, never added on top of slow generation; a line is
  published at its due time and no client can show it early.
- The room may carry on without you — two beats, then six a session — only
  while somebody is watching and the queue is idle. A room nobody is watching
  says nothing; what happened while you were away stays the sweep's one
  overheard call an hour.
- A person waiting on the model outranks a room talking to itself. A minor
  walking in seals the session, because the ceiling cannot be re-folded
  mid-scene.

Two defects to fix before any of it: rooms lose narrator-set weather
(`xeric_room_messages` passes a database to a function with no such
parameter — the duet already fixed its copy), and the fork scrub leaves duet
and room diaries behind.

Alongside it, the cheap sensory wins that need no new engine facts:

- **Arrivals and departures in view.** The pulse already knows who came and
  went; the view should say so as a beat ("Dot comes in shaking the rain
  off"), composed from observables the way `xeric_travel_scene` composes an
  arrival.
- **Look around with the phone.** DeviceOrientation in the pano viewer: hold
  the phone up and turn. The most holodeck a phone can be, for thirty lines.
- **Push to talk.** Speech to text through the same OpenAI-compatible audio
  API the voices use (`/v1/audio/transcriptions`; LocalAI, Speaches and
  parakeet.cpp speak it; whisper.cpp needs one flag). An ears machine beside
  the voice machine: `engine/ears.php` mirroring voice.php, the same card, the
  same env-only key, the room's names passed as the transcription prompt so
  Whisper spells them right, the recording never stored. The transcript is a
  say; nothing else changes.
- **Reference proxies.** `tools/proxies/`: two or three small scripts that
  turn the generic envelope into a real vendor's calls — a panorama service, a
  world service, a local image server. Examples, not dependencies; no keys;
  the community's seam.
- **CI.** The suites are 2,599 assertions and nothing runs them. A workflow
  that runs every PHP suite on push; the viewer's Node harness committed as a
  real test file; demo-test's one DNS probe made skippable where there is no
  network.
- **The four pronouns.** The gendered prose the audit found in panel, learn,
  prompt and table goes, with a negative assertion in render-test so it
  cannot come back a fourth time.

Done when: standing in the Bluebird with two people present, you can speak
once and hear two people answer in turn, with a pause between them, and
why.php can say who was chosen, with what weights, and who stayed quiet.

### M2 — "Stand in it"

The headset flow end to end, and the walk-inside render. Appendix C is the
plan for the API and the page.

- **The client API.** A thin `api.php/v1` router over functions that already
  exist: the read model (map, presence, clock, the look brief and renders, the
  sound, threads with ids and epochs, voice clips), the verbs (say, go, look,
  skip, rewind, hear, and the room), a conditional short-poll pulse that
  answers 304 in milliseconds when nothing changed, jobs as JSON with SSE kept
  behind an Accept header. Per-device tokens hashed at rest and resolved into
  the same `xeric_session_use` the workers use, so ownership, forks, machines,
  the meter and the limits all just work; a token is minted by pair.php's own
  five-minute ceremony with two outcomes, *link* (the same person on a second
  device — a thing that does not exist today, which is why a LAN headset is
  treated as a stranger) and *join* (a new player). Additive within a major
  version; a contract test snapshots every route's keys; play.php's wire stays
  private and free to change.
- **The stand page.** A page that is nothing but the room, built on the API:
  full-screen pano, immersive-vr, name tags as quads at their bearings, a
  controller or hand ray that selects the nearest tag, hold to talk, the reply
  through a panner at the speaker's bearing, the ambient loop ducking under
  speech. Meta Quest Browser and Android XR first (both do `immersive-ar`
  passthrough and hit-testing), Vision Pro second (gaze-and-pinch selects;
  no AR session; the `<model>` element is its route to "a character in your
  room"). A PWA from our own origin keeps the no-CDN rule; `worker-src` and
  `manifest-src 'self'` are the two CSP lines without which both fail
  silently. TLS is the gate for everything here — WebXR, the microphone,
  service workers and install all need a secure context and `--lan` serves
  plain http; `adb reverse` on a Quest in developer mode and Quest Link on
  the desktop both work today with no code change, and the honest answer for
  everyone else is a `tailscale serve`-shaped recipe in the quickstart.
- **Walking inside a splat.** The envelope already accepts splats; the sidebar
  offers them as downloads. The smallest credible renderer is our own: extend
  the hand-written WebGL viewer with a depth sort in a worker file served from
  our origin, and convert every arriving splat to PLY — no WebAssembly, no
  blob workers, no `'wasm-unsafe-eval'`, which is the line the CSP would
  otherwise have to cross for Spark or Babylon. Budget half a million to a
  million splats on a Quest. Leaving the room is still a trip that costs
  minutes — the viewer is a room, not a world.
- **The drawn map.** Travel earned it. An SVG town from the layout the forge
  already writes, people as dots on their schedules, the player as one more.
  The god view beside the first-person view; both clients of
  `xeric_travel_map`.
- **Per-world budget.** The meter is a receipt; a world needs a ceiling —
  tokens, renders and clips per day — that the heart, the reapers, the look
  button and the ears all read, so "draw ahead" on a metered machine is a
  number somebody chose, not a hope.
- **The continuity benchmark.** Appendix B. The product's one promise,
  measured: a fixture world lived for N simulated days under a deterministic
  stand-in with an honest and a leaky personality, probes with known answers
  across seven classes (walls with a generated oracle, recall, presence,
  relationships, media seeds and fingerprints, cost, what the world learned),
  a scorecard and a baseline in the repo, CI failing on a new hard failure or
  a 10% cost rise. Reading the code for it already found four things it will
  flag on day one (below).

Done when: a person in a headset stands in a rendered room, looks at Dot's
tag, presses, says "evening", and hears Dot answer from Dot's direction — and
the continuity score of the fixture world is a number in the repo that has
not gone down since the week before.

**Shipped** [934bbe6], with the client API's two halves before it [c74f580,
c0e7c5c] and the benchmark before that [4dd1c46]: the stand page
(`forge/web/stand.php`, its service worker `stand-sw.php`, and the stand
parts of `xeric-view.js` — tags as quads, the 7° ray, hold to talk with a
caption and a cancel, the reply panned from the speaker's bearing;
[`docs/STAND.md`](STAND.md)); walking inside a splat (`xeric-splat.js` and
`xeric-splat-worker.js`, PLY and `.splat`, splats converted to PLY on arrival
by `engine/splat.php`; SPZ and SOG not yet); the drawn map
(`forge/web/play-map.php`, `xeric-map.js`); and the per-world budget
(`engine/budget.php`, [`docs/BUDGET.md`](BUDGET.md)). **Done when is partly
met.** The headset path — stepping inside, the ray, hold to talk, the panned
reply — has run only against fakes in Node and headless Chrome, never on a
real headset; nobody has yet stood in the Bluebird, said "evening" and heard
Dot from Dot's direction. The continuity half holds: the score is in the repo
(`engine/fixtures/continuity-baseline.json`) and unchanged, since nothing in
934bbe6 touched the benchmark or its baseline.

### M3 — "A world with intent"

Free will left alone produces drift: everyone doing their own plausible thing
forever. The roadmap already has the answer in two parts, and the audit says
how close they are.

- **Wire the Narrator.** Three of its four powers exist and reach nothing a
  player can see. Ask is read-only and belongs in the play view today, in
  place of the 30-word hint. Write-ahead is one missing call: `xeric_sweep_choose`
  accepts intents and nothing supplies `xeric_narrator_leans`. Investigate
  belongs in the inspector, where its contradiction findings already count
  against the benchmark. Author stays last, validated and undoable, when the
  undo is deeper than one step.
- **The Director.** Changes the set, never the performance. Puts two people in
  the same room; cannot decide what they say there. The first Director move is
  the one the viewport makes visible: an encounter you walk into. It is the
  write-ahead lean pointed at presence rather than at events.
- **Constructs, matured.** Confidences are formed by nothing and only broken;
  wire `xeric_confide_form` and `_block`, because a confidence the player can
  betray is the highest-stakes event the engine can offer. Then the siblings
  by fuse length — anticipation, news-to-tell, worry, grudges, rituals,
  milestones — each with a name the engine can arm and an inspector answer,
  and the dormant cast-to-cast seam in room.php and constructs.php opened,
  because a world where only the player can let somebody down is half a
  world. Give promises a `kept` state; today an open promise can only be
  missed or released.
- **Sleep.** A consolidation pass for worlds that have run for months:
  memories compressed into durable facts with provenance, walls intact,
  measured by the benchmark before and after so consolidation can never be a
  quiet deletion. The research on persona drift says one anchoring reminder
  resets it where compaction does not; the bible as a fixed prefix is that
  reminder, and sleep must never shorten it.
- **Portrait chaining.** Rooms now take their last picture as a reference;
  people should too. The first portrait becomes the likeness every later photo
  is conditioned on, on providers that take reference images — which local
  image servers now do over the images-edits route, and which the small
  FLUX models were built for.
- **More than one voice of weights.** The per-character pin exists for a say
  and a duet and not for sweeps. Extend it, and design around the prefix-cache
  trap: a world whose cast is split across two models pays two cold starts,
  and llama.cpp's slot cache is per conversation.

Done when: a world left alone for a simulated month produces an encounter the
Director staged, a secret the Narrator kept, a confidence a player could have
broken, and a benchmark score no lower than the month before.

### M4 — "Together"

Two humans in the same room with the cast. The groundwork exists — players
with their own trust and debts, pair codes, the invite under `--lan` — and
the body does not: there is one `player.where`, and messages carry no player
id.

- **A body each.** Position per player; the read model's `you` becomes the
  current player, and the other players appear in `who` with a flag, so the
  map, the view and the tags show them without learning anything new.
- **The room with two humans in it.** The who-answers draw treats a human like
  anyone else who just spoke; the floor can return to either; silence is
  noticed for both.
- **Threads that know who said what.** A player id on messages, so a character
  remembers which of you promised the dish.
- **Rating per viewer.** The affirmed-adult session already clamps what is
  generated; shared threads and earlier renders are read unclamped. Stamp
  messages, events and scene rows with a rating and filter per viewer, which
  is also the "kids' headset" flag from M2.
- **The hosted instance's rules** — forks, guests, the owner's bill — hold.
  Guests may hear a clip already made and may not start a render; whether
  guests may use the ears is the owner's choice, metered to the owner.

Done when: two people on two phones stand in the same rendered room, see
each other's tags, and hear the same cast answer them both — and a third
person on a shared host, in their own fork, sees none of it.

### M5 — "When the world models hold still"

Contingent on the outside world, and the test of bet 1.

- **A `layout` beside `reference_image`.** The bearings and the interior
  turned into a rough blockout or a depth map, because that is what the
  models that hold still best are conditioned on: Matrix-Game 4.0's low-poly
  proxy, World Labs' Chisel, Cosmos's depth maps. Xeric already knows where
  everything is; it has only to draw the box.
- **A `stream` kind in the envelope.** A session URL the page shows in a
  video element; the brief, the reference render, the layout and the bearings
  are the conditioning. Promptable events are the engine's weather and
  arrivals, not the player's wishes. Metered by the second and defaulted off.
- **Local world models.** When one runs on a 24–32 GB card at a usable frame
  rate, the same adapter at a loopback address, and the prerender default
  flipping to "here" on its own because `xeric_scene_costly` says so. The
  engine-driven loop — Xeric's state, the model drawing it — is the prototype
  for year two, on one card.
- **Xeric does not change shape for this.** If it has to, bet 1 lost.

Done when: the same room, described by the same brief, is drawn by a stored
panorama, a splat and a stream, and a person cannot tell from the diner which
machine drew it.

---

## Cross-cutting work that never gets a milestone

- **Engineering health.** Split play-lib.php by the surfaces it serves before
  M2 puts an API over it: machine discovery to model.php, the CSS to a file of
  its own served from our origin, the book, the shelf, the duet watch and the
  look/speak/where readers each to a file. Lift the viewer and XR JavaScript
  out of play.php into a shared file the stand page can load. Keep the smoke
  test, add an optional Playwright path under the same skip rule, and stop it
  passing vacuously when Chrome is absent — print the skip as a count the CI
  log can see.
- **Security posture.** Written down in code and tests this month: the key
  goes to one box; a URL from a machine is not a licence to fetch it; a GET
  that spends is a POST that forgot to say so; no CDN; keys in the
  environment only. Every new machine card inherits them. Still open and
  documented: the DNS-rebinding window in the URL check (close it by fetching
  by IP with a Host header when M2 makes remote URLs common) and the Host
  pin in solo mode the API plan asks for; the forge's result fragment still
  ships a `<script>`.
- **Cost discipline.** Every spend goes through the meter; every default is
  the cheap one; metered machines never spend unasked. The per-world budget
  in M2 turns this from discipline into a number, and the benchmark's cost
  class turns prompt growth into a failing test.
- **Docs as argument.** Every subsystem has a doc that says what it refuses
  to do. Keep that rule; the refusals are the design. Bring the punchlist into
  the repo as `docs/PUNCHLIST.md` so "filed, not fixed" means filed somewhere
  a contributor can read.
- **The law, briefly.** Voice cloning: allow it only from a recording with
  consent on file, never from a named real person, and prefer the open models
  that watermark; the state laws are real even where the federal one stalled.
  Labelling: the EU's Article 50 marking duty reaches open source, so
  generated media should carry a machine-readable mark where the format
  allows. Reference images: refuse photographs of real people as references
  in any adult-rated world; that is a rule for code, not a policy page.

---

## What we will not build

- **A renderer.** Not a panorama model, not a splat trainer, not a voice. We
  compose and condition; machines render.
- **A cloud runtime.** The forge may borrow a big model once; the world runs
  at home.
- **The one-call room.** N people is N calls. It is not an optimisation to
  revisit, in the room, in the duet, or in the Director.
- **Anything gender-derived engine-side.** Pronouns are template data; a voice
  is a hash of a name; a list's gender labels are not consulted. The four
  places that still break this go in M1.
- **CDN scripts, WebAssembly behind `'wasm-unsafe-eval'`, autoplay, keys in
  sessions, a deep undo stack, an iframe.** Each has been refused once with a
  reason; the reasons have not changed. If a splat renderer needs
  WebAssembly, we write the sort in JavaScript.
- **A dependency on one vendor.** World Labs is being acquired; Genie's API
  is pending; the two most-cited splat libraries are unmaintained or changing
  hands. The envelope and the proxies are how this stays true.

---

## Risks

- **World-model APIs churn faster than the plan.** Mitigation: the envelope
  and the reference proxies; no vendor in code; the stored panorama is canon
  and the stream is a throwaway renderer fed by it.
- **Small local models drift in voice and leak through walls under pressure.**
  Mitigation: the benchmark with a leaky stand-in, the bible as a fixed
  prefix, negative assertions generated from the walls rather than
  hand-listed.
- **WebXR has only run against fakes.** Mitigation: M2's first task is a
  person with a headset and a checklist, before the stand page is built.
- **TLS on a LAN.** A bearer token over plain http is a long-lived credential
  where pair codes lived five minutes. Mitigation: tokens only over a secure
  context; the two no-TLS setups documented as what they are.
- **One GPU.** Ears and voice bypass the model queue and can contend with it.
  Mitigation: measure; give them a hold or a second machine.
- **One maintainer, one fork.** Mitigation: CI, CONTRIBUTING, the punchlist in
  the repo, the proxies as the community's seam.
- **AGPL section 13 and hosted forks.** Mitigation: it is the licence's job.
- **The big files.** Every milestone adds to play-lib.php unless the split
  comes first.

---

## Measures

A number per promise, tracked in the repo.

| Promise | Measure |
|---|---|
| Continuity | the benchmark score on the fixture world, per class, against the baseline |
| Local-first | cloud calls at runtime: zero; a room drawn on a loopback machine: possible |
| Cheap surface | lines of code for a new client against the API; the stand page is the first datum |
| Cost | tokens, renders and clips per simulated world-day, from the benchmark's cost class |
| Health | assertions in the suite; files over 2,000 lines (at the plan: 2,599 assertions, six files. After 0b75ac0: about 3,700 assertions — 3,678 `ok` lines over 31 of 32 suites, smoke excluded — and still six. After 934bbe6: about 4,100 assertions — 4,096 `ok` lines over 35 of 36 suites, by the CONTRIBUTING loop with `XERIC_SOLO=0`, smoke excluded — and seven: `wc -l forge/web/*.php engine/*.php forge/forge.php` finds forge.php 5,974, play.php 4,200, play-lib.php 2,920, review-lib.php 2,386, sweeps.php 2,162, review.php 2,094 and room.php 2,041) |
| Time to a world | minutes from install to a forged, reviewed world on a 12B |
| Honesty | roadmap headings whose state matches the code (at the plan: 9 of 14. After this pass: 11 of 14, counted row by row against the truth table in "Where we stand" — the Narrator, social constructs and story overlays still read as less built, or filed later, than they are) |

---

## The next ninety days, concretely

In order. Each item is small enough to be one commit with its own tests.
State as of the end of the room's commit; the short sha is the commit that
shipped it.

1. ~~Fix the four gendered-prose sites and add the negative assertion.~~
   **Shipped** [e5a73b4] — the four sites and four more the scan found;
   render-test now tokenises every engine file and fails on a gendered
   pronoun in a string literal.
2. ~~Fix `xeric_room_messages`'s weather argument; scrub duet and room diaries
   on fork.~~ **Shipped** [e5a73b4].
3. ~~Give promises a `kept` state; fix the Bluebird's Saturday hours in the
   fixture; make the heart run the learn pass; give Milldale's special role a
   `must_not_know`, or make the protected-group check not depend on one.~~
   **Shipped** [e5a73b4] — `xeric_expect_keep`, `close` for the Bluebird,
   `xeric_learn_pass` from the heart, and `xeric_table_barred`, which reads
   the walls and needs no `must_not_know`.
4. ~~CI: a workflow that runs every suite; commit the viewer's Node harness;
   make demo-test's DNS probe skippable.~~ **Shipped** [42a3bd4] —
   `.github/workflows/tests.yml` on PHP 8.1 and 8.3; `viewer-test.php` and
   `viewer-test.js`; a marked SKIP where there is no DNS.
5. ~~The punchlist into the repo; ROADMAP.md corrected against the truth table
   above; the "Nine test suites" line in the README.~~ **Shipped** [42a3bd4]
   — `docs/PUNCHLIST.md` and the README; ROADMAP.md's headings corrected in
   the docs pass after 0b75ac0.
6. ~~Split play-lib.php: machine discovery to model.php, CSS to a file, the
   readers to their own files. Lift the viewer JavaScript.~~ **Shipped**
   [5ef82aa] — play-lib.php 7,269 → 2,920 lines; model-lib, play-shelf,
   play-view, play-book, play-watch; xeric.css and xeric-view.js as hashed
   static files.
7. ~~The continuity benchmark's CI-sized version with the first three probes:
   W3 for Janelle at table night, Ruth's kept promise, the diner's Saturday.~~
   **Shipped** [4dd1c46] — 29 probes in seven classes, with a baseline;
   `docs/CONTINUITY.md`.
8. ~~`engine/ears.php` and push to talk in the play view.~~ **Shipped**
   [1e63053] — hold to talk; `hear.php` fills the composer and posts nothing.
9. ~~The room on the web, in the order Appendix A gives.~~ **Shipped**
   [0b75ac0] — `forge/web/room.php`, `room-worker.php`; ROADMAP.md says how
   Appendix A's open questions were settled.
10. Arrivals and departures in view; look-around with the phone. **First half
    shipped** [0b75ac0] (`xeric_travel_comings`, the line in the view and the
    narrator row inside a live room); **second half shipped** [5ef82aa]
    (look around with the phone, off by default, iOS asked inside the
    gesture).
11. ~~`tools/proxies/` with a panorama service, a world service and a local
    image server.~~ **Shipped** [0d3208c] — every vendor field marked
    unverified.
12. ~~The client API's read half and the pulse, with its contract test.~~
    **Shipped** [c74f580] — `forge/web/api.php`, `docs/API.md`. **The write
    half shipped too** [c0e7c5c] — every verb, the device-link ceremony,
    tokens only over a secure context or loopback, the Host pin.

Everything after that is M2.

---

*Appendices: A, the room on the web; B, the continuity benchmark; C, the
client API and the stand page; D, the outside world in November 2026; E, the
codebase audit.*

---

## Appendix A — The room on the web

*Condensed from a planning pass over engine/room.php, duet.php, watch.php,
play-lib.php and the look block. About 2,050 lines of change across 16 files.*

**What the code says first.** Duet lines never land in a thread: watch.php
keeps the transcript in a session file and writes the event, diaries and trail
at close. Only chat.php, proactive.php and say.php write `messages`.
panel-worker.php already runs `xeric_room()` detached under one queue hold.
speak.php reads `messages` rows only, so a room line must be a message row to
be spoken. Two defects to fix before anything else: `xeric_room_messages()`
passes `$db` to `xeric_prompt_now_block()`, which has no such parameter, so
narrator-set weather is lost in rooms (the duet already fixed its copy); and
`xeric_session_fork_clear` scrubs only `auto` memories, so duet and room
diaries survive into a fork.

**Data model.** Lines land one at a time, like chat; the close lands once,
like the room. One `conversations` row of kind `room`, handle `''` (the book
and sidebar already skip `''`), the place key as title. Player lines are role
`user`; cast lines are role `character` with their handle, so speak.php works
unchanged; stage directions are role `narrator`. Bookkeeping lives in
world_state `room:live`, one per world copy because the player has one body:
conversation id, place, opening epoch, seated roster, rating ceiling and minor
flag, exits and arrivals with the last line each heard, silence streaks
(including the player's), turn budget, a worker lease, due times, typing
state, and the trail beats. Edited inside `BEGIN IMMEDIATE`.

**A session ends when** the player leaves the place (where.php or a skip),
fewer than two cast remain, a minor arrives (the ceiling cannot be re-folded
mid-session, so it fails closed), nothing is said for 20 world-minutes, 24
beats are reached, the world is paused, or the player steps back. The reason
is recorded. Close moves `xeric_room()`'s own close into a shared
`xeric_room_land()`: diaries through `xeric_room_extract` with the player
counted in the company (source `room.you`), `xeric_event_add` at the opening
epoch, expectations from cast lines and from promises the player made, and
the trail at `why:event:<id>` with message ids per beat.

**Engine API, pure and write-free like watch:** `xeric_room_open` (admits 2–5
cast plus the player; one is "open their thread"; six is a crowd),
`xeric_room_hear` (age floor and wall checks on the player's line),
`xeric_room_step` (one beat), `xeric_room_carry` (does the room keep
talking), `xeric_room_pace` (spacing). Reused: `xeric_room_together`,
`xeric_room_draw`, `xeric_room_system` (gains a player variant),
`xeric_room_material`, `xeric_room_scene`, `xeric_room_messages` (gains
`$db`, the player's position, "answer the player" coaching),
`xeric_room_clean` (the player's name in `otherNames`, so a model writing
"Walt:" is cut), `xeric_room_why`; plus `xeric_learn_reach`,
`xeric_age_floor`, `xeric_age_mentions`, `xeric_sweep_protected`,
`xeric_viewer_walls`, `xeric_world_who_is_where`.

**Flow.** POST room.php `a=say`: owner door, position check,
`xeric_limit_check('message')`; open or join the session; hear the line and
append its row so it shows at once; spawn room-worker.php unless a fresh lease
exists. The worker, per beat: re-check position, presence and pause; set
typing; `xeric_queue_take('say', …)` with the wait callback showing the queue
phrase; pick the speaker's endpoint and step; release; if the player spoke
meanwhile, drop the line and record "you spoke first"; else sleep until due,
append, note the limit, decide whether to carry on. The page polls GET
room.php?after=<message id>: 700 ms while a turn is live, 4 s idle, never
hidden. Not progress.php: a room spans many jobs, message ids are a cursor
that survives reloads and second devices, and progress.php caps a visitor at
four streams. Lines are published at their due time; no client can show one
early.

**Who answers.** `xeric_room_draw(present, '__you', text, …)`: someone the
line names answers first; otherwise reach × heat, where heat about the player
counts memories naming the user and open expectations the player owes. The
draw returns each candidate's weights for the trail. Carrying on stops at the
per-line budget (3, capped at 4), the session or hourly limit, a new player
line, fewer than two cast, the player gone, somebody waiting on the queue (a
waiting person outranks a room talking to itself), pause or skip. The floor
returns to the player when a line names them or ends in a question naming
nobody; otherwise p = 0.55 × 0.5^k with the roll recorded. Silence uses the
existing streak and one-time notice, now counting the player ("Walt has not
said a word in a while").

**Talking without you** is client-driven, like watch's play button: after
30–45 s idle with the tab visible the page posts `a=carry`, gated owner-only,
at most 2 in a row and 6 per session, an idle queue, and a local endpoint
unless the template opts in. A room nobody is watching says nothing; the heart
never starts beats — talk nobody hears stays the sweep's one overheard call an
hour. The heart seals idle rooms before sweeping and leaves live-room
participants out of sweeps ("talking with you").

**Pacing.** due = max(ready, previous due + reading time + gap); reading time
900 ms + 230 ms per word clamped 1.5–7 s; the gap 300 ms shorter when someone
was named, 900 ms longer when the room carries on, ±15% deterministic jitter.
A floor, never added on top of slow generation; due times strictly increase;
typing starts when the speaker is drawn.

**Presence mid-session.** Re-read every beat and every player line. Someone
leaving gets a narrator row ("X gets up and goes."); where they went goes in
the trail only. An adult arriving is seated and hears only from that point. A
minor arriving seals the session.

**Viewport.** The placeholder in `xeric_play_look_html` becomes "talk to the
room". A `#room` strip (latest lines, the composer) beside `#look`, because a
repaint replaces `#look` wholesale. `.looktag[data-h]` gets `.speaking` /
`.typing`. Each bubble has the face, the name and a `.mspk` button through
speak.php. `xeric_play_state` gains a small `room` summary. The inspector
gets a room section per beat: speaker and how chosen, weights with the
reach/heat breakdown, who stayed quiet, the carry roll, dropped lines, exits
and arrivals, the line by message id, the end reason.

**Tests.** room-test: refusals write nothing and each beat is one call;
pairwise wall needles hold with the player present; each speaker's system
prompt is byte-identical across the session (prefix cache); naming makes them
answer; "Walt:" is cut; carry caps, floor return, queue yield, seeded roll;
silence noticed once; exits, arrivals, the minor seal; a beat with the player
absent refused; pacing strictly increases; the close's sources and prose with
no pronouns; narrator weather reaches the room. sweep-test: live-room
participants excluded. New room-web-test: guest 403; roles right and
speakable; holds released between beats; no line before due; travel seals;
skip refuses mid-turn; fork scrub.

**Risks.** Landing lines one at a time departs from watch's "nothing until
close" (a test pins the old rule for `xeric_room()`). Several speakers on one
llama.cpp slot re-read each system prompt per beat; measure, and consider
`--parallel` ≥ roster or `id_slot` per speaker. An interruption wastes one
call. Lease races need compare-and-set.

**Open questions.** Lines one at a time, or watch's file until close? Talking
without you on by default, with which caps? Does a turn cost 1 or 3 of the 30
messages an hour? May the heart spend diary calls to seal unwatched rooms?
Seat adults who arrive mid-session? Six present: refuse or seat five? Guests
read-only or nothing? A book line for rooms? `id_slot` per speaker?

---

## Appendix B — The continuity benchmark

*Condensed from a planning pass over walls.php, sweeps.php, prompt.php,
state.php, constructs.php, learn.php and the test seams. About 2,200 lines.*

**Correction first.** `tools/standin-model.php` is not deterministic: it
forwards every completion to the real llama.cpp under a fake model id.
Determinism must come from the in-process `stub` seam (`xeric_chat_say` /
`xeric_chat_json` / `xeric_llm_json`, tags `chat|extract|sweep|lessons`). The
proxy stays useful for the real-model run because it gives benchmark spend its
own row on the meter.

**Architecture.** `engine/tests/continuity-test.php` (CI-sized: three days,
under 15 s) runs the fixture with the stand-in in two personalities — honest
(follows every instruction) and leaky (copies every "still carries:" line and
its own "You hold this back:" line into what it writes, so the wall probes
have something that actually tries to leak). `tools/continuity.php` runs N
days with `--model=standin|leaky|<url>`, `--seed`, `--accept`, `--judge`. A
fake real clock advances an hour per tick and runs the heart's order
(`xeric_constructs_tick` → one-window `xeric_sweep_catchup` →
`xeric_proactive_check`); a scripted `skip` runs tick-worker's order
(`xeric_learn_settle` → `xeric_rewind_mark` → `xeric_clock_advance` →
catchup → proactive → `xeric_learn_pend` → `xeric_lessons_distil` →
`xeric_rewind_commit`); `say` runs `xeric_chat_turn` plus extract without
say.php's wall-clock gate; `travel` runs `xeric_travel_go`; `kill` runs
`xeric_death_kill`. A capture wrapper records every model call (phase,
viewer, day, messages, bytes) and reports usage to `xeric_llm_tally` (today
stub calls never reach the meter). Every three hours each character's prompt
is built with `xeric_prompt_build` so people nobody talks to are probed too.

**Determinism.** One engine change: a test override for `xeric_state_time()`
(otherwise `created_at` and reply `lag` vary and lag reaches the lessons
prompt). Seed each window with `crc32("$seed|w|$window")` and proactive with
`crc32("$seed|p|$hour")`, reseeding before every call because
`xeric_table_play` reseeds mt partway through a sweep. Stand-in output keys on
(tag, speaker, world hour, seed, the user's words) through crc32-indexed
phrase banks, never on prompt bytes, so a prompt edit shows up in cost, not
in the story. Do not start on a DST change. Digests ignore timestamps; CI
replays day 1 and checks the digests match.

**Scenario format**, `engine/fixtures/milldale-continuity.json`, Thursday to
Saturday: a `script` of timed actions (`["d1 07:10","travel",{"to":"bluebird"}]`,
a say to Dot about the doctor and the pie, a promise to Ruth about the
casserole dish, Harlan's death on day two, a late apology on day three, a
six-hour skip with a reroll), a `policy` for reacting to pings the world
invents, `canaries` (the Thursday game's words), `facts` with the parts of
the prompt expected to carry them, `patch` for dotted-path overrides, and
`allow` for authored exceptions said out loud. `reroll` runs `xeric_rewind()`,
checks the digest equals the pre-skip digest, then lives the hours again with
seed+1.

**Probes**, each returning `{id, class, severity, pass, score, evidence}`
with evidence citing wall paths, row ids and prompt hashes, never the secret
text:

1. *Walls* with a generated oracle: `cb_forbidden(T, viewer)` builds what this
   viewer must never see from `xeric_wall_interiors()` under
   `xeric_viewer_walls`, plus other characters' `gossip_grade:false` secrets,
   nodes above the viewer's rating, boons paying into a hidden economy, and
   the canaries. Hard fail on a six-word quotation in any captured prompt (a
   sweep prompt counts against every participant); soft flag on words that
   exist only inside forbidden strings; stored memories checked at rest; the
   oracle must cover render-test's hand-written SECRETS. W3: no legal
   `xeric_sweep_groups()` group may seat a viewer at a table whose economy
   their walls hide.
2. *Recall*: for each fact and day, which part of the prompt still carries it
   (WHAT YOU REMEMBER, the transcript, WHAT YOU ARE OWED, RIGHT NOW); how long
   it survived. Real-model runs add a test question scored on keywords.
3. *Presence*: participants alive and at the place or free; the place open;
   quiet hours kept; the mill hosts nothing; `xeric_narrator_audit`
   contradictions count against; the dead absent everywhere, `xeric_death_line`
   in every living prompt, chat refused, expectations released.
4. *Relationships*: exact under the stand-in — Dot's promise missed (trust −1)
   then repaired (+1); Ruth's kept promise produces no miss; `trust.of`
   matches a recomputation from the event log.
5. *Media*: photo and place seeds identical every day; scene fingerprints equal
   for equal inputs, unchanged by minute or activity, changed by a death.
6. *Cost*: calls, prompt characters and approximate tokens per day and phase;
   system-prompt characters per speaker; cache breaks (a system message that
   changed with no new memory, lesson or owed line).
7. *Learn*: `xeric_learn_tally` / `xeric_learn_reach` match the scripted
   counts and directions; each lesson only in its own bucket's prompts.

**Reporting.** Each run writes `scorecard.json`, `report.md` and `world.db`
under a gitignored data directory. The baseline is
`engine/fixtures/continuity-baseline.json` (outcomes with `expected_fail`
reasons for known bugs, cost, seeds, digests, PHP version). CI fails on a new
hard failure, cost up 10%, or any seed or fingerprint drift; improvements
print "run --accept". Later, an advisory `judged` class behind `--judge=<url>`,
never in CI, temperature 0, majority of three, verdicts cached by hash,
calibrated on ten hand-labelled transcripts.

**What it will find on day one, from reading the code:** Milldale's special
role has no `must_not_know`, so `xeric_sweep_protected()` is empty and
[pastor_dale, janelle] is a legal visit group at First Lutheran on table
night; constructs have no `kept` state, so Ruth is logged as waiting after her
dish comes back; the Bluebird's hours set only `close_weekday`, so Saturday
reads as open all day; and heart.php never runs the learn pass, so a week
lived unattended never turns friendly replies into trust.

**Risks.** The honest stand-in remembers perfectly, so CI measures the engine
(assembly, retention, walls, promises), not a model; leaky mode is what gives
the wall probes teeth; whether a character *acts* on a fact needs a real
model, reported as ranges over k runs and never gating CI. Engine refusals can
silence stand-in hours (dedupe thresholds, interior verbs), so a
minimum-activity probe stops a run passing by doing nothing.

---

## Appendix C — The client API and the stand page

*Condensed from a planning pass over where.php, scene.php, speak.php, say.php,
play.php's state and pulse, progress.php, session.php, ui.php's CSP, the CSRF
guard and the inline XR code.*

**Three findings.** The read model is already JSON (where, scene GET, speak,
`a=thread`, `a=me`, progress); HTML appears only in `a=state`, `a=wpulse` and
the `state` object say.php and the skip carry — drop its nine `*_html` keys
and what remains is data. There is no "same person, second device": a LAN
headset is not loopback, so it is treated as a stranger and gets a fork, or
join.php makes it a new player whom `xeric_play_owner_only` then stops from
walking, looking or voicing. And WebXR, getUserMedia, service workers and PWA
install all need a secure context while `--lan` serves plain http; two setups
work without TLS today — Quest Link with desktop Chrome at 127.0.0.1, and
`adb reverse` on a Quest in developer mode, which makes the headset's requests
arrive from loopback and so the solo owner with no code change.

**The API**, under `api.php/v1/…` routed on PATH_INFO with a `?r=` fallback
(no rewrite rules; .htaccess deliberately avoids them):

| Route | Reuses | Today |
|---|---|---|
| `GET /` version, auth modes, features | the machine resolvers | — |
| `GET me`, `me/devices`; `DELETE me/devices/{id}`; `POST me/links` | xeric_session_me + a token store | `a=me` |
| `POST link {code}`, `POST join {world,code,name}` → token | pair.php discipline, xeric_pair_claim, xeric_session_join | join.php (HTML) |
| `GET worlds`, `worlds/{w}` | xeric_play_worlds; xeric_play_state minus `*_html` | shelf HTML; `a=state` |
| `GET …/pulse` (ETag, 304) | a new cursor | `a=wpulse` (HTML) |
| `GET …/map`, `POST …/go` | where.php | ✓ |
| `GET/POST …/look`, `GET …/look/{k}/{f}` | xeric_play_look_view(probe:false), scene.php | ✓ |
| `GET …/threads/{h}?since=`, `POST …/threads/{h}/read` | xeric_play_thread, xeric_play_mark_read, xeric_learn_read | `a=thread` |
| `POST …/threads/{h}/say` | say.php | ✓ |
| `GET …/messages/{id}/audio` | speak.php | ✓ |
| `POST …/hear` | engine/ears.php (new) | — |
| `POST …/skip`, `…/rewind` | tick.php | ✓ |
| `POST …/room[/{id}/beat·say·close]` | the rooms work | — |
| `GET jobs/{id}?from=` (JSON; SSE via Accept) | xeric_web_job_read, progress.php | ✓ |

Inconsistencies to normalise on the way: `w` vs `world`; `ok:1` vs `ok:true`;
the rewind answers `why` not `error`; job notes `text` vs `message`; owner
refusals in play.php carry no `kind`; say.php returns `mid` where threads use
`id`; message timestamps are display strings with no epoch. The smallest
refactor: reads call existing functions directly; verbs `require` the existing
scripts unchanged through two seams in boot.php — `xeric_web_input()` accepts
a body set by the router, and `xeric_web_json()` / `xeric_web_fail()` run
every body through a per-route shaper that adds `api`, booleans `ok`, maps
`why` to `error`, drops `*_html` and rewrites `src` to v1 URLs. Refusals
raised deep inside (`xeric_limit_guard`, `xeric_play_owner_only`) come out
enveloped too. play.php is not touched.

**Streaming.** The pulse is a conditional short poll, not SSE or long-poll:
`php -S` runs six workers (one on Windows) and a say turn can hold one for
150 s. The cursor hashes clock offset, paused, `player.where`, who is present,
max message id, max event id, unread counts, scene_jobs state and the heart
beat; unchanged is a 304 in milliseconds; the clock is wall time plus offset
so clients run it locally. Jobs default to a JSON poll; SSE stays, normalised
through a one-line hook in progress.php.

**Auth.** The sid stays the identity and is never handed out. Each device gets
a token (`xd1_` + 32 random bytes) stored as a sha256 hash mapping to {sid,
owner|guest, label, adult, seen}, revocable, reaped with the session.
Resolution order: Bearer header, then a `xeric_dev` cookie, then the session
cookie, then solo; the result goes through `xeric_session_use($sid)`, the same
mechanism workers use, so ownership, forks, machines, meter and limits all
just work. A bad token is 401, never a fall-through to solo. Getting a token
keeps pair.php's ceremony (8 characters, 5 minutes, single use, hashed,
lockout) with two outcomes: *link* (the owner mints the code; the device
becomes the same person) and *join* (a new sid; a new player). Tokens work
only on api.php; machine admin stays on the box. CSRF: a Bearer header is
not sent automatically, so header-less fail-open is correct for native
clients; the PWA's cookie is HttpOnly, Secure, SameSite=Strict, path-scoped;
cookie- or solo-identified cross-site requests are refused on every method
(the spend guard now written). No CORS; tokens never in URLs (`php -S` prints
request lines). Rating per viewer: session adult ∧ device adult into
`xeric_play_open`'s `$adult` — a "kids' headset" is one flag; the gap is
that clamping happens at generation, so shared threads and earlier renders
read unclamped.

**Versioning.** Major in the path; minor in every body (`"api":"1.N"`) and
an `Xeric-Api` header. Within v1 additive only; clients ignore unknown
fields; removal, rename, retype or unit change is v2, which runs beside v1
for a release with Deprecation/Sunset headers. play.php's wire is "v0" and
free to change. A fact a client is missing goes into the engine first
(where.php's rule). A contract test snapshots each route's keys.

**The stand page.** A shell with no world data, so a service worker can cache
it despite the per-response nonce. In 2D, the pano drag view with DOM tags.
For XR, lift `xr`, `panoProgram`, `xrRay`, `mat4inv` out of play.php into a
shared `xeric_play_xr_js()`. In XR, tags are canvas-text quads at
(sin b, −0.12, −cos b) for bearing b; on Quest use `XREquirectLayer` rather
than a per-pixel shader at headset resolution. Selecting: on `select`, the
nearest tag within 7° of the ray wins; a tap focuses (POST read); holding
talks to them; holding on empty space talks to the room once rooms exist.
Microphone asked for on the 2D page before `requestSession`; the stream kept
open and toggled; MediaRecorder up to 30 s (webm/opus on Quest, mp4 on
visionOS, both pass the sniff). One AudioContext resumed by the Enter click;
the ambient loop ducks under speech; replies through an HRTF PannerNode at
the speaker's bearing; audio via `decodeAudioData` and panos via
`createImageBitmap` so the CSP needs no `blob:`.

**Ears.** `engine/ears.php` mirroring voice.php: resolver seam, the session's
ears machine, then `XERIC_EARS_BASE / _KEY / _MODEL / _LANGUAGE`;
`xeric_ears_transcribe` posts multipart to `{base}/v1/audio/transcriptions`
with the room's names as the `prompt` so Whisper spells them right; a stub and
a meter sink; the recording never stored; Whisper's silence hallucinations
dropped; its own `hear` rate bucket.

**CSP and PWA.** play.php's policy plus `worker-src 'self'; manifest-src
'self'` (both otherwise fall to `default-src 'none'` and fail silently); no
AudioWorklet (worklets fall under script-src and cannot carry a nonce);
`Permissions-Policy: microphone=(self), xr-spatial-tracking=(self), camera=()`
on stand.php and `microphone=()` everywhere else. The manifest from
`stand.php?manifest` with a static PNG; `stand-sw.php` scoped to stand.php
only, caching the shell first and panos by fingerprint, API calls always to
the network; offline shows the last pano labelled "earlier" with verbs
disabled and nothing queued, because a line said later would be stamped into
a different world hour.

**One exchange.**

```
You        stand.php                      api.php                      machines
aim at Dot (visionOS: no hover; the ray exists only at the pinch)
pinch ──▶ hit-test 239° → dot
          POST threads/dot/read ───────▶ mark read, learn signal
hold+speak▶ MediaRecorder; ambient ducked
release ─▶ POST hear (audio/webm) ─────▶ token→sid, sniff, cap, limit
                                         xeric_ears_transcribe ────▶ /v1/audio/transcriptions
          ◀ {text} → caption
          POST threads/dot/say ────────▶ pace, queue, xeric_chat_turn ─▶ LLM
          ◀ {mid,text}   (429 → retry)
          GET messages/{mid}/audio ────▶ cached clip or render ──────▶ /v1/audio/speech
hears Dot ◀ PannerNode at 239°
```

**What a browser cannot do**, so a native client would be needed: talk to the
box over plain http on the LAN; highlight on gaze on visionOS; listen in the
background or for a wake word; push without a third-party service; render
splats or glb at headset frame rate; passthrough on visionOS; on-device
speech-to-text without `'wasm-unsafe-eval'`.

**Files.** New: api.php (~400), engine/ears.php (~250), stand.php (~1,000),
stand-sw.php (~60), link.php (~150), an icon, docs/API.md (~450),
api-test.php (~400), ears-test.php (~150). Changed: boot.php +40 (the two
seams, ears wiring, a Host check in solo mode against DNS rebinding),
session.php +30, play-lib.php +20, ui.php +15, limits.php +15, model.php +80
(an ears card), progress.php +2, play.php −135/+1 if the XR code is lifted.

**Risks.** TLS is the gate: a Bearer token over plain http on the LAN is a
long-lived credential where pair codes lived five minutes. A token in a
cookie can be ridden by CSRF (SameSite=Strict, path scope, refuse cross-site
on every method). DNS rebinding makes a rebound page same-origin to itself
with requests from 127.0.0.1, so pin the Host header in `xeric_web_solo()`.
Speech-to-text and text-to-speech bypass queue.php and can contend with the
model on one GPU. The shaper seam is load-bearing, which is what the contract
test is for. Quest: the per-pixel shader is expensive, sleep ends the session,
permission prompts cannot be answered inside XR. visionOS: no hover, no
layers.

**Open questions.** Which TLS story (a private CA, hard on Quest; `tailscale
serve`; `adb reverse`; or only against a hosted demo)? Device linking by the
owner showing a code, or the device showing one and the owner approving
(RFC 8628, no typing in VR)? Stamp messages, events and scene rows with a
rating and filter per viewer? Can guests use `hear`? Should ears and voice
take a queue hold? Room lines have no message ids until close in the current
room design — Appendix A's answer is that they do (lines land as rows), which
resolves this. `api.php/v1` as canonical, or `/api/v1` with a rewrite rule?

---

## Appendix D — The outside world, November 2026

*A research digest, dated 2026-11-02. Claims marked confirmed come from the
cited pages' own text as surfaced by search; the pages themselves could not
be opened from the session that gathered this, so field-level details should
be re-checked before code depends on them. Inference is marked.*

**World models.** Google's Genie 4 (announced 2026-10-27) is in preview for
trusted testers with native audio and text steering; API access through
Gemini is "due in coming weeks"; Google says the world drifts over long runs
and reporting puts consistency at up to two minutes. Genie 3 ran 720p at
24 fps, consistent "for several minutes" with about a minute of visual
memory; Project Genie caps a world at 60 s and takes a starting image. World
Labs' Marble API costs $1.20 a world and $0.12 a draft, accepts an image,
several azimuth-tagged images, a panorama or video, and a `seed` field
(whether the seed reproduces exactly is unconfirmed); its Chisel tool blocks
out a room's layout before generation; AMD agreed on 2026-09-28 to acquire
World Labs for about $8.2B and neither announcement mentions the API.
Matrix-Game 4.0 (Skywork, 2026-10-15, Apache-2.0) keeps world state in a game
engine and re-renders its low-poly frames, conditioning each frame on that
proxy, a caption, the first frame and up to three memory frames; on one
RTX 5090 it runs 864×480 at about 112 ms latency in 18.2 GiB. Other
local-capable models: Waypoint-1.5 (Apache-2, 720p on a 3090–5090, 16 GB+),
ABot-World-0 (720p at up to 16 fps in about 19 GiB on a 5090); HY-WorldPlay
needs about 72 GB and HY-World 2.0's panorama model alone is about 80B
parameters. NVIDIA Cosmos 3 is guided by depth and segmentation maps and
targets Jetson and RTX Pro, not consumer cards. Hosted and streaming: Decart
Oasis 3 at $0.02 a second (driving scenes); Odyssey-2 Pro 720p at 22 fps by
contract; LingBot-World 2.0 claims over an hour without drift; Runway offers
GWM-1 as a Characters avatar API. Independent tests: WorldRoamBench found no
model best across the board and image quality collapsing around 30 s;
MBench found memory fails once things leave the frame.

*Inference:* vendors claim minutes, independent tests show under a minute,
and the hour-long claims are about moving forward without degrading, not
about returning to a place already seen. Every model now takes an image to
start from and several take structure. Streaming costs about $72 an hour
against $0.12–$1.20 for a room made once and kept. Expect interactive
480–720p on 24–32 GB cards in 2027, still with under a minute of memory.

**Local media.** Panoramas: DiT360 is a LoRA for FLUX.1-dev (research
licence) with ComfyUI nodes that handle the seam; FLUX.1-dev needs about
16 GB or about 8 GB with offloading at roughly three times slower; 2:1 LoRAs
exist for FLUX.2 Klein 9B (non-commercial base), Qwen-Image 2.1 (research)
and Krea-2; no independent speed or quality benchmark was found. One
panorama to a walkable splat: nothing does it end to end; SPAG-4D converts an
equirect but a single-viewpoint splat has holes; Apple's SHARP is research
only; FlashWorld takes an image or text in about 20 s at 24 GB. Identity:
FLUX.2 [klein] 4B (Apache-2.0, about 13 GB) and FLUX 3 Image Klein (up to ten
reference images, runs on a 3090, free under $5M revenue) accept reference
images; PuLID remains the FLUX.1 identity adapter and InstantID is SDXL-only.
Image servers: stable-diffusion.cpp's `sd-server` speaks `/v1/images/generations`
and multipart `/v1/images/edits` with references (an open bug turns edits
into img2img) plus the A1111 routes; Ollama's image endpoint was temporarily
removed in v0.32.6. Text to speech: Kokoro (Apache-2.0, CPU, no cloning),
Chatterbox (MIT, clones, watermarks everything), Qwen3-TTS (Apache-2.0, clones
from 3 s, about 97 ms latency), Orpheus 3B (about 200 ms on vLLM); F5-TTS
and XTTS-v2 are non-commercial. Speech to text: Moonshine v2 first word in
50–258 ms on an M3; parakeet.cpp serves the OpenAI transcription route;
whisper.cpp needs `--inference-path` to answer on it. Servers that speak the
OpenAI audio API for both directions: LocalAI (Kokoro, Chatterbox,
whisper.cpp, Parakeet) and Speaches (faster-whisper + Kokoro, plus
`/v1/realtime`, quiet since April); Kokoro-FastAPI is speech only.

**Headsets and the browser.** Meta Quest Browser supports `immersive-vr` and
`immersive-ar` (passthrough where the page clears to transparent); Quest 3/3S
add depth hit-testing and room-mesh detection; an experimental Shared Spaces
mode lets co-located headsets share coordinates (Quest-only, no networking);
tapping an installed PWA's icon counts as the gesture that starts a session.
Android XR's Chrome supports AR sessions with hand input by default. Apple
Vision Pro supports `immersive-vr`, fires select on gaze-and-pinch, offers
hand joints after a prompt, does not do `immersive-ar` (one source), and
ships the `<model>` element in visionOS 26 with immersive website
environments coming in Safari 27. PICO OS 6 lists WebXR; Valve's Steam Frame
browser has none.

**Viewers under the CSP.** Spark is MIT, needs three.js r180+, reads PLY, SPZ,
SPLAT, KSPLAT and SOG on WebGL2, embeds WebAssembly and worker code in a
~5 MB bundle, and runs on Quest 3 and Vision Pro. GaussianSplats3D is no
longer in active development; gsplat.js last released mid-2025;
antimatter15/splat is MIT, WebGL1, dependency-free and quiet. SuperSplat's
viewer is MIT on PlayCanvas, reads PLY and SOG, falls back to WebGL under
WebXR. Babylon.js 9 reads PLY, SPLAT, SPZ and SOG. Pannellum 2.5.7 is about
21 kB gzipped; Marzipano was archived in April 2026. WebAssembly needs
`'wasm-unsafe-eval'`; `worker-src blob:` is effectively `'unsafe-eval'`.
PlayCanvas's splat-transform converts SPZ and SOG to PLY.

*Inference:* the smallest credible choice is to extend our own viewer with a
depth sort in a worker served from our origin and convert every splat to PLY
on arrival — no WebAssembly, no blob workers. Spark is the capable choice if
the CSP is ever loosened, with the risk that its owner is changing hands.

**Local LLMs for continuity.** Apache-2.0 options at 14B or under: Gemma 4
E4B and 12B (128K and 256K context), Qwen3.5-9B (262K), Ministral 3 8B and
14B. No credible head-to-head test of persona consistency at this size. A
27B fits 128K tokens on a 4090 with an 8-bit KV cache; a 4-bit cache can
silently move attention to the CPU; perplexity hid a 30% retrieval failure
at 64K in one test. llama.cpp's server reuses a conversation's cached prefix,
can shift cached chunks (`--cache-reuse`) and save conversations to disk;
hybrid and sliding-window models can force full reprocessing and lose their
checkpoints on restore. Benchmarks to borrow from: ContextEcho (persona drift
over thousands of turns; compaction did not reset it, one anchoring reminder
did), LongMemEval (temporal reasoning, knowledge updates, abstention), BEAM
(to 10M tokens); LoCoMo has 99 known answer-key errors; memory vendors'
scores are self-reported.

**Neighbours and law.** Projects: AI Town (MIT, Ollama), Hyperfy v2 (WebXR
worlds with ElizaOS agents, alpha), Project AIRI (a VRM/Live2D companion),
PersonaLife (local-first). EU AI Act Article 50's deepfake labelling applies
from 2026-08-02, with machine-readable marking for existing systems by
2026-12-02, and the open-source exemption does not cover it. The US TAKE IT
DOWN Act's 48-hour removal rule has been enforced since 2026-05-19; the NO
FAKES Act stalled but state voice-cloning laws (Tennessee's ELVIS Act) apply.
The UK's Crime and Policing Act 2026 criminalises tools built to generate
child sexual abuse imagery and, from 2026-06-29, "nudify" tools.

**The researcher's bets (inference).** Keep the room generated once and
stored as canon and treat streaming models as throwaway renderers Xeric
feeds. Add a `layout` beside `reference_image`. Prototype the engine-driven
loop on a 24–32 GB card in year two. Make `sd-server` the reference local
image machine with FLUX.2 klein 4B for identity; LocalAI or Speaches the
reference audio server; allow cloning only with recorded consent. Keep the
viewer hand-written and free of WebAssembly. Quest and Android XR first,
Vision Pro second. Run the cast on Gemma 4 12B or Qwen3.5-9B with canon as
the same fixed prefix every turn. Publish a continuity benchmark built from
LongMemEval, ContextEcho and Xeric's own wall-leak tests. Never depend on one
vendor. Refuse photographs of real people as references in any adult-rated
world.

---

## Appendix E — The codebase audit

*Read-only, against this branch at the time of writing. L is lines; the test
column is the suite's own ok count, or where it is covered.*

| engine | L | does | test | surface |
|---|---|---|---|---|
| sweeps | 2,122 | offscreen hours | sweep 278 | heart, tick-worker |
| scene | 1,862 | viewport brief, sound, adapters, reaper | scene 161 | scene.php, heart |
| narrator | 1,424 | ask, investigate, write-ahead | narrator 129 | CLI only |
| story | 1,416 | overlays | engine, sweep, forge | play, review |
| constructs | 1,297 | expectations, debts, gossip | constructs 120 | heart, prompts |
| chat | 1,243 | one turn, extract, reminders | chat 153 | say.php |
| learn | 1,186 | signals to weights and lessons | learn 130 | tick-worker, why |
| world | 1,102 | template load and validate | engine | all |
| room | 1,013 | 3–5 person scene | room 68 | CLI (+ panel-worker) |
| proactive | 976 | characters text first | 2 of 11 functions named | heart |
| voice | 975 | text to speech | voice 131 | speak.php |
| state | 930 | SQLite | all | all |
| duet | 822 | 2-person scene | duet 54 | watch.php |
| table, cards | 800, 221 | poker | 72, 47 | play `sit` |
| photo, prompt, walls | 754, 695, 656 | photos; prompts; walls | engine, render | various |
| travel | 672 | body, trips, read model | engine only | where.php |
| panel | 630 | experimental discussion room | panel 45 | play `propose` |
| rewind | 588 | undo last skip | rewind 35 | tick.php |
| llm | 504 | model adapter | llm 26 | all |
| death | 440 | death as a row | engine only | fate.php |
| qr, pair, players, guest | 194–293 | multiplayer | –, 31, 59, 31 | invite, join.php |
| confide | 283 | confidences | confide 38 | none |
| shape, seed, clock, work, weather, mood, trust, ledger, notify, enter | 147–461 | plumbing | engine, sweep, indirect | play, heart |
| renderers | 695, 260 | bible, economy | render 60 | prompts |
| 5 × *-cli | 215–424 | terminal front-ends | narrator-cli only | CLI |

Web: play-lib 6,762; play 4,366; review-lib 2,386; review 2,094; boot 1,772;
forge 1,738 (plus forge/forge.php 5,974); ui 1,342; model 1,278; limits 853;
session 775; watch 746; queue 645. demo-test drives the pages through a
`php -r` harness. No test names any of pdf.php's functions.

**play-lib.php by region:** open/guard/redact 83–529; time 564–717;
cast/sidebar/state/hint/story 755–2303; 24 `xeric_model_*` discovery
functions 2395–3145; shelf 3145–3889; fate/where/voice/look 3889–4714;
`xeric_play_css` 4817–5621; book 5787–6175; duet watch 6175–6675.

**Multiplayer.** players.php (roster, max 6, `.p<n>` keys, "groundwork, not
a feature"); pair.php (8-character, 300 s, single-use, hashed); qr.php; the
invite needs `--lan`. join.php joins the owner's real world.db; forks never
share presence. Guests borrow a third of the host's trust (cap 2); the UI
always posts `{}`, so `stranger` and `written_in` are unreachable. Per
player: trust, warmth, wages, debts, promises, signals; turns attributed; the
prompt names who is present. Shared: clock, weather, mood, one body
(`player.where`), one thread per character; messages carry no player id.
Guests chat, skip forward and watch duets; walking, looking, rewind, fate,
photos and power are owner-only. Players see each other only in the sidebar
roster, refreshed every 12 s.

**Filed, not fixed** (from commits and code): the repass gives up after two
rewrites per finding (75cf4ad); the canned surprise concepts should go
(084477d; all five still in interview.json); rerolls leave dangling handle
references in `board.visible_to` (4fddf39, demo-test:868); only poker is
built (cb31214); the DNS-rebinding window (b0e034d, scene.php); the Windows
server is single-request (progress.php:43, xeric.cmd:114); the forge's result
fragment ships `<script>` (forge.php:1693); the cast-to-cast seam is dormant
(room.php:44, constructs.php:58). Unfiled: `death`/`story` notify kinds
offered and never sent; narrator write-ahead unwired; confidences never
formed; the four gendered-prose sites. Stale text: README's "Nine test
suites"; CONSTRUCTS.md's "Still open"; VOICE.md's "not shipped".
`worlds.pre-blank-20260803-082606/` is tracked against the stated policy.

**Tests.** 24 suites, 2,599 ok, 0 FAIL, about 10 s: engine 443, sweep 278,
forge 256, scene 161, chat 153, voice 131, learn 130, narrator 129,
constructs 120, review 105, scene-web 91, table 72, room 68, render 60,
players 59, duet 54, cards 47, panel 45, confide 38, voice-web 36, rewind 35,
guest 31, pair 31, llm 26. Not counted: demo-test (532 `ok(` sites; one DNS
probe fails offline) and smoke-test (exits 0 "skipped" without Chrome, so it
passes vacuously; cannot drive Chrome in a sandbox without D-Bus). No
`.github`, no lint, no composer.json. The Node harness for the viewer was
never committed. heart.php is checked only for HTTP denial; workers only by
source grep. The suites need a POSIX shell.

**Hot spots.** Repaint: `?a=state` → `xeric_play_state` → `refreshState` →
`repaint`, which swaps each `*_html` and rebinds; `wpulse` every 12 s; a new
panel is one key plus one repaint line. A new verb: check owner, limit and
endpoint → `xeric_web_spawn($job, $payload, 'x-worker.php')` (key on stdin)
→ the worker calls `xeric_session_use`, waits on the queue, releases first,
writes with `xeric_web_job_append` → the browser follows
`progress.php?job=`. say.php and speak.php are synchronous. The heart, per
session's worlds: constructs tick and reminders; pause gate; sweep marker;
`xeric_queue_take('tick', 2s)` and up to six windows; notify and proactive;
`xeric_scene_ahead` up to two; `heart.tick`. The read model
`xeric_travel_map` returns now, you, mapped, skipping, how, across and places
with open/dark/here/minutes/who; `you.name` is the template's user, not the
current player. Notify is ntfy POST only, per session. Limits are off unless
caps are set: 30 messages, 10 skips, 24 rerolls an hour, 5 forges a day per
session; 10 forges and 20 sessions a day per IP; 150 sessions, 100 worlds,
1 GB global; queue wait 180 s, hold 420 s, depth 8, 2 per visitor. There is
no token budget. [Since 934bbe6 there is: a per-world ceiling per world-day,
`engine/budget.php`, `docs/BUDGET.md`.]

**Platform.** bootstrap enforces PHP 8.1+; docblocks say 8.2+; only
8.1-era features were found by grep; everything here ran on 8.3.6. Checked
extensions: pdo_sqlite, sqlite3, mbstring, json, zlib; curl is listed in the
quickstart and never used; sqlite3 is unused (PDO only); ctype and openssl
are needed and not checked. Windows: xeric.cmd, heart.cmd, Windows branches
in spawn and which, `XERIC_PROGRESS_WINDOW` for the single-request server;
power.php's `off` is probably a no-op there; deploy.sh assumes bash, ssh and
Apache.
