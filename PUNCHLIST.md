# Xeric punchlist

Where things go when they are noticed but not done. Standard practice: say it
as soon as you think of it, it lands here, it gets worked in batches.

Each entry: what it is, where it lives, why it matters, what is blocking it.

---

## Ideas — big enough to be their own thing

### The Bible Library (an MCP server on xeric.dev)
**What.** A hosted library of world-building reference — mystery tropes, genre
textures, period detail, small-town social structures, occupational detail,
naming conventions — served over MCP so a *local* model can reach for it while
forging.

**Why it matters, and why it might be the most valuable idea in the project.**
The forge's quality ceiling is the model's own priors, and a 12B has thin ones:
that is why four characters came back "A low, gravelly rumble" and why the
de-duplicator had to exist. Give a small model good raw material to draw on and
it stops inventing from nothing. It makes weak hardware punch above its weight,
which is the whole promise of local-first.

**And it is a moat.** The engine is copyable; a curated library that keeps
growing is not. It is also the natural home for anything Xeric learns —
recipes, motif banks, event shapes — without shipping them inside the engine.

**Shape, first pass.** MCP server at (say) `mcp.xeric.dev`, read-only, no auth
for the public shelf. Tools along the lines of `list_bibles`, `get_bible(topic)`,
`sample(topic, n)`. Bibles are plain text/JSON documents under a topic tree.
The forge's passes gain an optional "consult the library" step; worlds record
which bibles they drew on, so a world is reproducible.

**Open questions.** Does the forge pull once at build time (cheap, cacheable) or
per-pass (better, chattier)? How does an offline install work — ship a snapshot?
Who curates, and what stops it becoming a dumping ground? Does a user's own
private bible sit alongside the public shelf?

**Blocked on:** nothing technical. Wants a decision on scope before it starts.

### THE DEMO ARTIFACT PROBLEM — a screenshot travels and prose does not
*(raised 2026-08-02, prompted by Karpathy's tweet about handing Opus 5 the first
paragraph of LoTR and $10 of tokens and getting 5,500 lines of three.js.)*

**What.** Xeric's product is **time** — a world that ran while you were not
there — and time is the hardest thing in this space to put in a tweet. Every
competing demo that comes out of the current excitement will be **visual**: a
rendered town, a flythrough, a screenshot that carries itself. Ours is a chat
box. We have no equivalent of the pelican on the bicycle.

**The asymmetry, stated precisely.** Their artifact is **complete at t=0 and
static forever**. Ours is **empty at t=0 and only good at t=days**. The
screenshot format favours them at exactly the moment we are weakest, and a
stranger who opens the demo for five minutes sees the worst version of the
product that will ever exist. Worse, the honest pitch — *"come back Thursday"* —
is the one thing a link cannot deliver.

**But the audit gap cuts our way, and nobody is saying so.** Karpathy's own
closing complaint is that the model **cannot audit its own world** — it can't
watch video, can't play the game, so Opus screenshotted painstakingly and
shipped jank anyway. In a text world the output medium and the perception
medium are **the same thing**, which is why `why.php` can exist at all and why
a three.js Rivendell structurally cannot have it. *"Here is the exact prompt she
received, here is why the sweep chose those three people, here is who it
excluded and on what grounds"* is a thing no rendered demo can show. **The
inspector is not a debug tool, it is the differentiator** — we have just been
filing it as plumbing.

**Candidate shapes for something shareable** (none built, all cheap):
- **The forge run as the demo.** A world assembling out of nothing in ~2 minutes
  with the notes streaming past is already the most watchable thing in the tree,
  and we have never framed it as the pitch. It is also the only part that is
  complete at t=0.
- **A week in ninety seconds.** Replay a real sweep log — events, the two
  divergent memories of the same room, the cufflink that became the next event's
  title, the unprompted 2am message — with **the real timestamps on screen**.
  Compressed elapsed time is the only honest way to show what the thing is, and
  the data is already sitting in the DB of every world that has run.
- **The proactive message as the atom.** One screenshot: a text you did not ask
  for, timestamped to a stretch you were provably gone. That is the closest
  thing to a pelican this project has.
- **The inspector, weaponised.** Side-by-side: the character's line, and the
  trail that produced it. Answers the "it's just an LLM roleplaying" reflex in
  one image.

**Open questions.** What does a stranger walk into — a frozen showcase world
that is already days deep, or one genuinely **running on a cron** so it is deep
and getting deeper (real GPU spend, and it must respect the drain flag)? Does
any "watch someone else's world" mode survive the privacy promise, given the
whole engine exists to keep people from reading each other's insides? Is the
showcase world SFW-pinned and curated by hand, or forged like everyone else's?

**Blocked on:** nothing technical for the replay, the forge-run capture, or the
inspector shots. The always-running showcase world needs a decision about
standing GPU cost on the owner's own workstation.

### The Narrator (see docs/NARRATOR.md)
The other god of the world: full canon, no walls, the only voice you can ask
*why*. Four powers — **ask** (read-only, zero risk, worth building on its own),
**investigate** (audits for dropped threads, absent characters, contradictions),
**write ahead** (intended beats the sweeps bias toward — intent as a pull, never
a script), **author** (validated, undoable world changes).

**The narrator has secrets too** (owner, 2026-07-30): full knowledge is not full
disclosure. It never unravels the mystery, never says where a boon is, never
spoils its own write-ahead beats, and does not hand over a character's secret
just because it knows it. Straight about the machine, discreet about the story;
refusals in voice, never as a system message. Open: whether an explicit author
mode ever drops it.

**The Director — the counterbalance to their free will** (owner, 2026-07-30):
everything else in the engine pushes toward autonomy, and free will left alone
produces drift — everyone doing their own plausible thing forever. The Director
is the force on the other side. **It changes the set, never the performance**:
it can put two people in the same room, it cannot decide what they say there.
It only works with what is already loaded (existing threads, absent characters,
unpaid boons) — a Director that can conjure a crisis is a "make something
happen" button. Consumes the investigate audit (its to-do list) and the learning
signals (what this user actually engages with). Called upon, not always on.

**Proximity is the physics these characters do not have** (owner, 2026-07-30 —
the best framing in the project so far): in a real life the largest
deterministic force is location and proximity; these characters are bound by
nothing, so their freedom is frictionless, and a choice that costs nothing is
not a choice. **The Director is the physics this world lacks.** It gives REASONS,
it does not PLACE people ("the ledger has been at the church since Tuesday", not
"Elias is at the church"). Engineering consequences to work through: schedules /
places / hours are the physics layer and should get stronger not bypassed; the
Director is bound by the same physics it enforces; **distance should cost
something** (every place is currently equally reachable from every other); and
the absent should be allowed to drift, with the Director noticing when it has
gone too far and supplying a reason rather than an exemption.

**Owner has more features written on an envelope at home** — add them here when
they turn up.

**The safety split that must survive implementation:** world authority (data,
validated, undoable — default ON) is not the same as code authority (the engine
and the machine — default OFF, and if ever wanted: explicit flag, a git worktree
it cannot leave, diff-and-confirm, no network).


### A viewport onto the world (owner, 2026-07-30 — "way into the future")
**Where it came from.** *Nothing, Forever* — the endless generated Seinfeld —
plus a boss who followed most of the pitch and then said *"I'm used to first
person shooter games."* That is not a failure to understand; it is the most
useful piece of feedback in the file. A text world has no legible surface for
someone who has never played one. What he was reaching for is **embodiment**.

**Why it is not crazy.** *Nothing, Forever* proved people will watch a generated
world; what it never had was a world — no persistence, no memory, no
consequence, and nothing the viewer could change. Xeric is the exact inverse:
the state IS the product, and the surface is the thin part. The engine already
separates them — renderers are pluggable, `xeric_render_bible()` turns state
into prose, and nothing says a different renderer could not turn the same state
into a scene. `xeric_world_who_is_where()` is already spatial. And the physics
work above (places, hours, **distance costing something**) is groundwork for a
viewport whether or not one is ever built: a world where distance costs
something is a map.

**The real obstacle is not rendering, it is cadence.** A shooter runs at 60fps;
a model turn takes seconds. But games solved this long before LLMs — schedules,
barks, ambient routine, with the expensive thinking reserved for when the player
actually engages. The model does not need to run at frame rate. It needs to run
at conversation rate, which is what it already does.

**Sequencing, and this is the point: the text world has to be genuinely good
first.** The hard part is the world model, not the window. *Nothing, Forever*
had a window and no world, and that is exactly why it ran out.

**Blocked on:** everything else. Recorded so it is not lost, not scheduled.

### ⭐ GIVING THE PLAYER A BODY (owner, 2026-07-31 — "now we need a way to get to locations")
**The finding that reframes this.** The world already HAS geography. `places[]`
carries `key`, `kind`, `hours`, `aliases`, `description`, `residents`; every
character's `week` block says `"where": "bluebird"`; `xeric_world_who_is_where()`
resolves all of it to a room at any minute of the world clock; and
`prompt.php:516` already tells a character *"You are at the Bluebird Diner —
coffee urn, counting dishes. Also there: Ruth, Dot."* **The engine has always
known Jim is in the bar.** The cast list on the play screen is that same read.

What is missing is one field. `user.location` is a TOWN — "Milldale, Ohio".
There is no `user.where`. **The player is a disembodied phone hovering over the
county**, and that is the entire gap between what exists and walking into a room.

**Do not start with the map. Start with travel time.** Carmen Sandiego and Drug
Wars did not have geography because they had pictures of it — they had it
because moving COST A TURN. A map you click that teleports you is a menu with a
nicer background. The whole mechanic is already sitting in
`xeric_clock_advance($db, $seconds)`. Walk to the diner: four minutes. Drive out
to the mill: twenty. The instant that is true, schedule data the engine has had
since day one stops being a status line and becomes a puzzle — *she is off at
two and I cannot get there by two.* This is the same sentence the Director
section above already reached by another road: **a world where distance costs
something is a map.**

**`null` is a real position.** The player being nowhere on the map is the same
state as a character being off shift, and it prints the same way — *"wherever
you are, it is your own time."* No synthetic "home" place, no special case; the
engine's existing vocabulary already had the word for it.

**Arriving at a locked door beats being refused at a menu.** Travel to a closed
place is ALLOWED. You walk over and it is shut. Refusing the trip is a UI
telling you what the world would have; making the trip and finding the chain on
the gate is the world. (Also the only way the mill is ever reachable.)

**The hard part is the room, not the map.** Every conversation in Xeric today is
1:1 texting. Standing in a room with three people is a different prompt shape:
multiple speakers, turn-taking, and — the load-bearing one — **the walls have to
operate face to face.** Dot cannot say the thing in front of Theo. That is the
best machinery in this codebase and it has NEVER been exercised in a
multi-speaker setting. Sequence the map before it (cheap, and it answers whether
embodiment is even fun); sequence the room turn after, because it is the real
engineering and it is where walls get their hardest test.

**The architectural call, and it is the one that matters.** Do not build "the
map feature". Build ONE endpoint — `where.php` — that returns the town as JSON:
places, open or shut, who is standing in each one right now, what it costs to
get between them. Then **the map is a client.** So is a first-person view. So is
WebXR in a Quest browser. So is the phone that already exists. Each of those is
a one-shot against a stable endpoint instead of a rewrite, and that is the
difference between the VR version being a 2027 afternoon and a 2027 fantasy.
This is also why the viewport idea above stops being blocked on everything: it
is blocked on one JSON endpoint, and after that it is a rendering exercise
anybody can do in parallel.

**Decide on purpose: this is a SECOND MODE, not an upgrade.** The phone is
asynchronous and glanceable — you check it in line at the store, and that is why
it survives a busy week. Walking into rooms is a session. Both can live in one
app; only one of them is the thing that keeps a world alive when the owner is
busy, and it is not the one with the map.

**The tell that the forge was already doing this.** Milldale's mill description
reads *"on the river side of the tracks."* **The forge is already writing
geography — into prose, where nothing can read it.** Adding coordinates to
`places[]` is not a new burden on the forge; it is asking it to write down what
it already decided.

**SHIPPED 2026-07-31, on dev.xeric.dev.** `places[].at {x,y}` on a 0–100 grid,
`setting.travel.{minutes_across,how}`, `user.home_key`, `engine/travel.php`
(cost, position, arrival, the read model), the player in the room in the RIGHT
NOW block, `where.php` (GET the town / POST a trip), a "where you are" panel on
the play screen, and the forge writing coordinates and world size. 25 engine
checks and 5 render checks. Verified in a browser: 07:57 → 08:07 on a ten-minute
walk, arrival line in the feed, the cast in the room named on arrival.
**Deliberately not in this pass:** the room turn, the drawn map, adjacency
(roads, walls, one-way), and vehicles.

**Left over, and known.**
- **Blackwater Creek is flat.** It was forged before `at` existed, so every trip
  in it costs the same ten minutes and the panel says so out loud. Worlds forged
  from now on have geography; rerolling the places section of an old one in the
  review gives it geography too (`xeric_forge_pass_places` writes the
  coordinates). Nothing back-fills an existing world, on purpose — inventing a
  layout for a town somebody already knows is worse than admitting there is none.
- **A trip still does not run sweeps.** Twenty minutes of walking is twenty
  minutes nothing happened in. See the open questions above; the fix is in
  tick-worker.php, and `where.php` does not change when it lands.
- **The Playwright smoke test is still not in the repo.** This feature was
  verified by driving a real browser from the scratchpad — again. Item 14 of the
  UI section below is now overdue twice.

**Open questions.**
- **Does travel wake the world?** A twenty-minute drive is twenty minutes a
  sweep could fire in. Cheap version: no. Right version: it is the same code the
  time control runs, and skipping to evening ON FOOT is a better button than
  skipping to evening from an armchair.
- **Can you be somewhere with nobody there?** Yes, and it should be quiet and a
  little sad. That is a feature and the renderer must not apologise for it.
- **Does the cast know you were there?** Presence in a room ought to be worth a
  memory to the people in it, or walking in means nothing the moment you leave.
  That is the seam where this joins the expectation/repair machinery.
- **Does distance gate texting?** No, and it must not — the phone is the product.
  But a character eight feet away who gets a TEXT is a joke the world should be
  allowed to make.

### ⭐ DEATH, REVIVAL, AND THE WORLD ENDING (owner, 2026-07-31)
**What was asked for.** Characters can die, and carry a mark. A setting lets you
go back in and revive one. Something big enough — somebody sets off a bomb — can
kill everybody, and you can revive the whole world. And there is a setting where
death is **permanent**: they really, really die, and nothing brings them back.

**DEATH IS A STATE, NOT A DELETION, and this is the whole design.** A dead person
stays in `world-template.json`, stays resolvable by handle, stays in every memory
that names them, every event they were at, every wall they were behind. `dead` is
a row in the world's database — handle, world epoch, one commons line about how,
optionally who did it. Delete a character instead and you break every memory,
event and wall that points at them, and you lose the only thing death is FOR:
that the rest of the cast goes on remembering somebody who is not there.

**THE PART THAT IS A GIFT: this retroactively unlocks the literary overlay.**
[STORY.md](docs/STORY.md) declares a `victim` as a phantom — a name, an age, a
one-line, and no dossier — and says exactly why: *"killing a cast member would
mean deleting somebody from `world-template.json` when the story is injected and
putting them back when it closes, which is the one thing an overlay may not do."*
That constraint was correct, and **it dissolves the moment death is a row in the
database instead of an edit to the template.** A story can kill somebody you have
been texting for a month, the overlay still never touches the template, and the
rule STORY.md was protecting stays intact. **A murder mystery finally gets to
have a victim you knew.** Nothing else on this list buys that much for that
little.

**The three modes it has to serve, and they are different features.**
1. **One death.** A sweep, a story beat, age, an accident, or you saying so.
2. **Revival.** The world keeps its history: the death still happened, everyone
   still remembers it, and the person is alive again. Living people who watched
   somebody die and then saw them at the diner is not a bug — **it is the best
   scene this engine could possibly produce**, and it is the holodeck question
   asked properly.
3. **Catastrophe.** Everybody at once, and places going dark with them. Reviving
   *the world* is mode 2 run across the whole cast — **NOT a rewind.** The bomb
   still went off. They all remember dying. `xeric_clock_reset()` already refuses
   arbitrary rewinds and says why; nothing here should reopen that argument.

**Permanence, and which way it fails.** `deaths.mode` is `revivable` (default) or
`permanent`. Missing, unreadable or garbage → **revivable**, and this is the one
place in the engine that does NOT fail toward the more severe reading. The age
floor fails closed because it protects a THIRD PARTY. Permadeath is a constraint
an author imposes on THEMSELVES, and failing closed into somebody else's
self-imposed constraint — destroying a world over a typo in a settings key — is
not caution, it is damage.

**No DRM pretence.** The world is a JSON file and a SQLite database the owner
owns; anybody with a text editor can resurrect anyone, forever, and the docs
should say so plainly. What `permanent` means is that **the engine will not do
it for you** — no button, no command, no API. That is a real thing and it is
enough, because the stakes were never enforced by the software, they were
enforced by the author having decided.

**Kids die.** Ordinary characters, ordinary mortality, and the age floor has
nothing to say about it — that rule is about sexual content and nothing else.
A guard that spared Billy's son from a story that killed him would be exactly
the wrong rule, again.

**What death actually changes, mechanically.**
- Out of `xeric_world_who_is_where()` — no schedule, not in any room.
- Out of sweep participation and out of the proactive queue. **Checked at SEND,
  not at queue time**: a ping written by somebody who died in the six hours
  before it fired is the exact bug this will have.
- The thread stays. It opens, it scrolls, **you cannot send.** Reading back the
  last thing somebody said to you is the entire emotional payload of the
  feature, and deleting or locking the thread throws it away.
- Their secrets become unreachable. *The only person who knew is dead* is a
  mystery mechanic the walls already support and nothing has ever used.
- Everyone else keeps talking about them, in the past tense, which the prompt
  layer needs told once rather than hoping the model infers it.

**SHIPPED 2026-07-31, on dev.xeric.dev.** `engine/death.php` (the ledger, the
mode, kill/revive/end/restore), a `deaths` table, `deaths.mode` in the schema,
and the wiring: the dead leave `xeric_world_who_is_where()` — the one read that
feeds the prompt's room line, the roster, the travel map and every sweep — are
excluded from the sweep chooser's every hour, are refused a chat turn at the
door beside the age floor, never reach for a phone, and are named to the living
in the volatile tail with an instruction to use the past tense. `fate.php` is
the endpoint. On screen: an × on the roster row carrying what the town would say,
the thread still opening and scrolling with the composer closed, a panel stating
the rule before any control, and a **death is permanent** tag on the shelf.
20 engine checks and 7 render checks. Browser-verified: killed somebody, watched
the row mark and their room empty, opened the dead thread read-only, brought
them back — *"Silas Vane is back. Everybody still remembers."*

**Decided while building.**
- **`permanent` locks at the first death**, and the mechanism is a snapshot: the
  first death copies the template's mode into the world's database and nothing
  reads the template again. No UI has to cooperate, editing the template
  afterwards does nothing, and a fork inherits it because a fork copies the
  database. There is a test that flips the template to `permanent` after a death
  and asserts the world stays revivable.
- **The dead reach the sweep chooser through `$exclude`**, the list a wall
  already uses, rather than a second exclusion list. Two lists of "who cannot be
  at this hour" is how one of them eventually gets forgotten. The decision trail
  distinguishes them — *"dead"* vs *"this one touches what they must not know"* —
  because the inspector answering "why was she not there?" must not call a death
  a wall.
- **The proactive check is at SEND, not at queue time.** A sweep writes six hours
  in one press; somebody at the diner in hour one can be dead by hour five.
  Filtering participants upstream would have looked correct and shipped the bug.

**AND THEN ALL OF IT, same day ("enable everything").** Every item that was open
above is closed:
- **The story victim can be somebody you know.** `cast.victim.character` takes a
  declared handle; composing a live overlay kills them, writes the hour, and
  gives every living person the memory — and never touches a byte of the
  template, which is the rule STORY.md was protecting. Idempotent by refusal
  rather than by a flag: the second compose is a no-op because
  `xeric_death_kill()` will not kill twice. Both forms stay legal; a phantom is
  still the right shape for a stranger the town is talking about.
- **Sweeps can kill.** A `loss` kind, system `mortality`, armed by nothing by
  default. **The ENGINE picks the body, before the model is asked** — a model
  told "somebody dies, you choose" picks whoever is most narratively convenient,
  which over a month is whoever the player talks to most. Chosen from OUTSIDE
  the room (the hour is the others finding out) and never the protagonist by
  accident. `base: 0.12` against every other kind's 1.0, because seventeen kinds
  at parity would bury a small town in a fortnight; kind weights now multiply a
  base rather than replacing it.
- **A catastrophe darkens the rooms.** `places.dark` in world_state, and the map
  says `dark` rather than `shut` so a client never has to guess which. One death
  darkens nothing — only everybody dying does.
- **The world notices.** Every death writes an hour into the feed and a memory
  into everybody still standing. A catastrophe writes ONE hour, not one per
  body — seven "X died" entries on the same afternoon would be the story of
  seven coincidences. The dead get no memory of their own death.

**Left for later:** funerals, uncovered shifts, and grief as a system rather than
a memory. That is where this finally joins the expectation/repair machinery.

### Production, 2026-08-01
**xeric.dev is live and the app is behind a password.** The pitch at `/` is
public; `/forge` is HTTP Basic (`xeric`), `noindex`. **What the password
protects is not the page — it is the GPU in the author's house**, which every
chat turn and every skip runs on through the tunnel.

**Production has its OWN data directory** (`/var/www/xeric-prod`), holding one
curated world. This is not tidiness: the staging shelf has seven half-finished
worlds on it and one of them says *"You are Neil."* A shared data dir would
have put a real person's first name on a public shelf. **Any future host gets
its own `XERIC_DATA` for that reason alone.**

**All seven staging worlds now have every system armed** (30 of 30, including
`mortality`) and an explicit `deaths.mode: revivable`, so nothing that dies in
them is lost. Templates backed up beside themselves as `.bak-armall`.

**Still true and still flagged:** `oakhaven-creek` on staging is named after its
author. It is behind staging's password and out of production entirely, so it is
no longer urgent — but it is the fourth time this has been written down.

### ⚠ POTENTIAL BUG: the hours are still lifes (2026-08-01)
**The evidence, from Blackwater Creek's own history.** Its oldest four hours were
written by the SEED pass and its newest six by live SWEEPS, and they are not the
same kind of writing.

Seed: *"Caleb leaned too close across the booth, his voice dropping to a honeyed
whisper that didn't reach Mabel's eyes. She cut her coffee mid-sip and walked."*

Sweep: *"A condensation ring spread across the scarred wood of the bar. Silas
traced the rim of his glass with a thumb, while Caleb watched the fog press
against the windowpane."*

**Nobody in the second one wants anything.** Five of six sweep titles collapsed
into *the [noun] of the [noun]* where the seed wrote sentences, and they
cannibalise each other: "traced the rim of a glass with a thumb" twice, "fog
press against the windowpane" twice, "scarred mahogany bar" three times, a moth
twice.

**MARKED POTENTIAL, NOT CONFIRMED, and the owner's reading is the reason.** *"It
is kind of funny that they're just waiting for me to show up… and I think this
is how it would be, if it is working correctly."* That is a real defence and it
may be the right one: a dying valley town on a wet Tuesday is four people
nursing drinks and not talking. A world that manufactures incident every hour to
prove it is alive is *Nothing, Forever* with extra steps. **Do not "fix" this
into a world where something dramatic happens every ninety minutes.** The
question is not "is it quiet", it is "is it quiet ON PURPOSE."

**Two things make it likely a real defect anyway.**
1. **It had six systems armed.** With `daily_rhythms` and little else the only
   kinds available were the quiet ones — `routine`, `ease`, `absence` — whose
   shapes ARE atmosphere by design. It now has thirty, so `friction`,
   `confidence`, `rumor`, `favor` and `chase` are live for the first time.
   **Test this before touching a line of code**: one skip, read the output.
2. **THE ENGINE HAS NO CHECK THAT ANYTHING HAPPENED.** `sweeps.php` refuses an
   hour whose memories collapse into one memory. It does not refuse an hour in
   which nobody did anything — and a still life passes divergence easily, because
   two people can notice different objects. The divergence check has a sibling
   that was never written.

**The fix, if the test says one is needed:** a refusal in the same place and the
same shape as the divergence check — an hour has to leave something CHANGED.
Somebody said something, moved something, decided something, owes something.
Weather and furniture are the setting of an hour, never its content.

**→ NEXT ACTION, and nothing here should be built before it: RUN ONE SKIP on
Blackwater Creek and read the six hours it writes.** It has thirty systems armed
now and had six when every still life above was written; `friction`,
`confidence`, `rumor`, `favor` and `chase` have never once been able to fire in
it. If people come back wanting things, there was no bug — there was a world
with almost nothing switched on, and the only change needed is that the forge
should arm more of them by default. Costs a handful of model calls on the
owner's GPU, which is why it is written down rather than already done.

### ~~Pause the world~~ — SHIPPED 2026-08-01, and it is the SAME FEATURE as detaching
**What shipped.** `xeric_clock_pause()` / `xeric_clock_resume()` /
`xeric_clock_is_paused()`, frozen in `xeric_clock_epoch()` — the one function
every read of world time in the engine goes through, which is what made this
one change instead of an audit. Resume lands on the exact second: there is a
test that stops a world, waits a simulated fortnight and asserts the epoch is
unchanged to the second, and another that asserts the offset went negative
(which is what a world behind real time IS).

**AND THEN THE OWNER JOINED IT TO SOMETHING BETTER.** *"A disconnect button that
would clearly pause the game."* Detaching the model IS pausing, and the reason
is not convenience — **a world can only live through an hour if something was
there to write it.** Let the clock run with no machine behind it and you come
back to a world that says the 14th, where the hours between are not quiet, they
are MISSING. So detaching stops every world the visitor has and attaching starts
them again where they stood.

**Three states, and the default is none.** A fresh install has no machine
attached and says so. Told apart by whether a choice was ever made: no `kind` at
all means never chosen (probe the configured address, attach if something
answers), `kind: none` means somebody detached on purpose and it stays that way
however alive the local model is.

**Reconciled at the one door.** `xeric_play_open()` stops a world it opens while
detached — because detaching cannot pause a world the visitor had not forked
yet, and the first world entered afterwards would otherwise be the only one on
the shelf lying about a fortnight. **One direction only**: detached-and-running
gets stopped, attached-and-stopped is left alone. Auto-resuming would make
opening a world undo a pause it did not set.

**Reflected everywhere it has to be**: the clock chip loses its light and says
*stopped*, the time control is disabled server-side and not only on repaint, a
skip is refused with a 409 before it takes a queue slot, travel refuses (a trip
is ten minutes of world time), a turn refuses with the fix in the sentence, and
every tile on the shelf goes greyscale — which says it without adding a word to
a screen that has none.

**A custom local address**, editable only from the machine the server is on
(loopback, or `local_editable` in config). That is a security boundary, not a
preference: `xeric_web_endpoint()` runs bring-your-own-key URLs through a
private-network refusal and the local endpoint deliberately skips it, so an
editable local address is safe exactly when the person typing it owns the box.
`base` is still discarded for `kind: local`, and there is a test for both halves.

**Still open:** pause is per-visitor, not per-world. There is no button to stop
ONE world while the others run, and the shelf's grey-tile read is a fast path off
the session rather than eight database opens — so a world paused on its own (a
worker, a repair) would not grey out until you opened it.

### Pause the world (superseded by the above — kept for the reasoning)
**"The option to pause the play and then start it running again and pick back up
EXACTLY where you left off."** Today the world runs on wall-clock time whether or
not anybody is here — that is the product — but a person who is away for a
fortnight and does not want to lose a fortnight has no way to say so.

**It is small, and the clock is already shaped for it.** World epoch is
`real_now + offset`. Pause stores the real time P and the world freezes at
`P + offset`. Resume at real time R sets `offset -= (R − P)`, and the world
carries on from the exact second it stopped. Nothing else in the engine needs to
know: every sweep guard, every world_epoch already written and every
`opened_at` is keyed to the same monotonic clock, and this never moves it
backwards relative to itself.

**Watch for:** `xeric_clock_offset` going negative (legal and correct — it means
the world is behind real time), proactive pings and sweeps having to be inert
while paused, and the play screen needing to SAY it is paused, loudly, because a
world that is not moving and does not say why looks broken.

**Open question worth deciding on purpose:** does pause belong to the world or to
the visitor? A shared world that one person pauses is a world they paused for
everybody. Probably per-copy, like everything else in the fork.

### ⭐ THE ROOM: THREE-WAY CONVERSATION, TIMING AND PACING (owner, 2026-08-01)
Raised before as "the room turn" and deferred behind the map. The owner has
added the half that was missing: **"natural 3-way conversation and AI timing and
pacing."** That is not a bigger version of the chat screen. It is a different
problem.

**THE ONE-CALL SHORTCUT IS FATAL AND WILL LOOK RIGHT.** The obvious build is to
ask the model for the whole exchange at once — everybody's lines, one call,
perfect pacing, cheap. It cannot be done. Each character's prompt is THEIR
bible, through THEIR walls, with THEIR memories; one call means one prompt means
one point of view, and every wall in the world collapses into it silently. The
output would read beautifully. **N people is N calls, and that is not an
optimisation to revisit later.**

**So the real questions are all about who and when, not what.**
- **Who answers?** Not everybody. Somebody answers, somebody adds, somebody says
  nothing — and *who stays silent is information*. `xeric_learn_order()` already
  does weighted shuffling with a thumb on it; a room needs the same trick
  pointed at "who speaks first" instead of "who texts first".
- **Does the room talk without you?** In a real bar, yes. Two of them carry on a
  thread you are not in, and you catch half of it. That is the single thing that
  would make a room feel unlike a chat window, and it is also the most expensive.
- **Timing.** Two replies landing at once is wrong; three replies landing in a
  neat queue is also wrong. Real rooms have beats, overlaps and dead air. The
  spinner work already taught us the UI half of this.
- **Cost and latency.** Three people answering a line is three model calls on one
  GPU, serialised by the queue. A room may simply be unaffordable at conversation
  speed until somebody's line is worth the wait.

**AND THE THING THAT MAKES THIS URGENT RATHER THAN NICE — the player having a
body already broke how walls are enforced.** Today a wall holds because THE
ENGINE CHOOSES WHO IS IN THE ROOM: `xeric_sweep_choose()` keeps a protected
character out of any hour that touches what they must not know, and no
prompt-side redaction is needed because the thing never happened near them. The
moment the player walks where they like, that guarantee is gone — **you can walk
Theo into the bar.** A room turn therefore has to enforce `must_not_know` IN THE
DIALOGUE, at speech time, which is a strictly weaker and much harder guarantee
than absence.

**That is the actual work.** Not turn-taking, not pacing, not cost. Everything
somebody says in a room is heard by everybody in it, which means a room turn
writes to every listener's memory, which means the wall has to hold against a
model that is being asked to speak naturally in front of the one person who must
not hear it. Sequence a spike on THAT before building any of the pleasant parts.

### ⛔ THE WEEK-SHAPED HOLE (found 2026-08-01 — "so it just stops running?")
**The product's central claim is currently delivered by a button.** "The world
runs whether or not you are there. Come back after a week and a week has gone
by." Half of that is true and the half that is true is the less interesting half.

**What actually happens.** World time is `real_now + offset`, so the clock never
stops: close the tab for a week and it genuinely is a week later, and everybody
is standing where their schedule says because presence is computed live. But
only two things in the entire codebase can make a world LIVE THROUGH an hour —
`tick-worker.php`, which the skip button spawns, and `sweep-cli.php`, which a
cron would call. Nothing runs on page load. Nothing runs on a timer.

So a week away leaves **a week-shaped hole**: the date moved, and in it there are
zero events, zero memories, and nobody reached for their phone.

**AND SKIPPING AFTERWARDS DOES NOT FILL IT.** `tick-worker` sweeps the windows of
the span you asked for, forward from where the clock stands. The gap is never
looked at again. It is not a delay, it is a deletion.

**The machinery to fix it is already written and tested.**
`xeric_sweep_catchup($t, $db, $endpoint, $fromEpoch, $toEpoch)` takes an
arbitrary range. Nothing has ever called it with a backward one.

**Two ways, and the less obvious one is better.**

1. **A heartbeat** — a loop beside the server poking a cron endpoint every few
   minutes. Makes the world literally live. Costs: the GPU runs while nobody is
   there, which on a laptop is battery and fan noise for a town nobody is
   watching; and it only helps while the machine is awake, which for the install
   this is actually for — somebody's desktop — is most of the time it is not.

2. **LIVE THE GAP WHEN YOU COME BACK.** On opening a world whose last swept
   window is well behind the clock, sweep the gap. From the outside this is
   indistinguishable from the world having lived it — the hours are there, dated
   correctly, with the right people in the right rooms — and it costs nothing
   while you are away, works on a laptop that was shut, and needs no daemon,
   no cron, and no second process to package into an installer.

   **Deferred evaluation of a world.** The week did happen; it just got written
   at the moment somebody first looked.

**The cap is the whole engineering problem.** An hour-window at
`XERIC_SWEEP_CHANCE` is roughly one model call every three hours of world time,
so a week is ~55 calls and a month is ~240. Nobody waits for that on a page
load. So catch-up needs: a ceiling on how much is lived in detail, a coarser
pass for the rest ("three weeks went by, here is what stuck"), and a screen that
shows it happening rather than a spinner — which is the SAME screen the skip
button already streams to (progress.php).

**Do not fix this with a heartbeat first.** A heartbeat makes the demo look
right on a machine that is always on and leaves the actual product — a thing
somebody launches on a desktop that sleeps — with the same hole.

### ⭐ 1873 (owner, 2026-08-01 — "if we want to start in 1873, then its 1873?")
**Yes, and it already works.** Tested: one call to `xeric_clock_offset_set()`
with a negative offset and Milldale is standing in Tuesday 14 October 1873 at
07:40. Presence resolves, schedules run, place hours open and close, the clock
advances, pause and resume land on the second. PHP takes the negative epoch
without complaint and `xeric_world_now()` never assumed otherwise.

**And it is more period-accurate than anybody asked for.** The timezone came back
as **−04:56**, which is New York's LOCAL MEAN TIME — standard time zones did not
reach the United States until 1883, and tzdata knows. Nothing in Xeric did that
on purpose; it fell out of using real datetimes instead of an hour counter.

**Nothing on screen contradicts it either.** `xeric_play_when()` prints
"Tuesday morning · 07:40" and `xeric_play_stamp()` prints "Tue 07:40" — the
engine never shows a year, so a world only says when it is through
`setting.era` and the prose the forge writes from it.

**SHIPPED the same day.** `setting.starts` takes any date PHP can read and is
applied ONCE at launch, marked like the seed — re-applying it on every page load
would drag a world back to its first morning, because the offset is where all
the time since is being kept. Then time goes: verified at 1973-11-08 07:40, three
real hours later reading 10:40, a skip on top of that, and
`xeric_clock_reset()` returning to 07:40 on the 8th instead of hauling the town
into this afternoon.

**→ FOR THE WIZARD WORK. RAISE THIS WHEN THE INTERVIEW IS BEING TOUCHED, because
it is one question and it is already 90% built.** The forge does not write
`starts`. Given `era: 1873` the places, jobs, cast and canon rules would very
likely come out period-appropriate from the era string alone — but nothing turns
that era into a DATE, so a forged historical world still has to be hand-edited
to actually stand in 1873.

What it needs: the era step accepting a year or a date as well as prose, and the
concept pass emitting `setting.starts` alongside `setting.era`. The interview is
nine questions and this is a widening of one of them, not a tenth.

**Why it is worth doing.** Every other "living world" is set now because a clock
is easier that way. Xeric's is a real datetime with a real timezone database
behind it, so a mill town in 1873, a ship in 1912 and a station in 2140 are the
same code and one field — and the 1883 timezone detail is the kind of thing
somebody notices once and never forgets.

### ⭐ WHAT THE METER COSTS IN MONEY (owner, 2026-08-01)
**"Tokens wasted" next to a dollar figure, per machine.** The count already
exists and is already per-provider; this is the second column. And the contrast
IS the feature: `127.0.0.1` reading **$0.00** beside `api.openai.com` reading
**$4.12** is the argument for local inference made in two numbers, which is
better than the paragraph currently on the card.

**FOUR THINGS MAKE THIS HARDER THAN A MULTIPLICATION, and getting any of them
wrong produces a confidently wrong number, which is worse than no number.**

1. **Input and output are different prices, and Xeric's shape is extreme.** A
   measured chat turn here was **2,798 in / 18 out** — 155:1. Every provider
   charges output at 3–5× input, so a single blended rate is wrong by a factor
   of several in whichever direction the world happens to lean. The tally
   already keeps `in` and `out` apart; the rate table has to as well.

2. **PROMPT CACHING CHANGES IT BY 10×, and this project is built for it.**
   prompt.php's entire discipline is a byte-stable system message so the prefix
   caches — cache reads bill at roughly a tenth of input, writes at 1.25–2×. A
   cost estimate that ignored caching would overstate a talkative day enormously
   AND hide the payoff of the one design constraint the engine bends hardest
   for. `xeric_llm_usage()` currently reads `prompt_tokens` / `input_tokens`
   only; the cached-token fields are right there in the same object and are
   dropped on the floor.

3. **Rates go stale and scraping them is a network call to a third party** — in
   an app whose claim is that it makes none. Ship a small table WITH A DATE ON
   IT, let the UI say how old it is ("rates as of 2026-06-24"), and make it a
   plain editable file. An optional refresh is fine; a silent background fetch
   to somebody's pricing page is not, and neither is a number that quietly went
   wrong in March.

4. **A local machine is $0.00 and that is the honest answer**, not a gap. It is
   not free — it is electricity and a fan — but it is not billable, and
   pretending to price a kWh would be inventing precision. If anything is shown
   for local it should be time, not money.

**What is known today** (verified 2026-06-24, per MTok): Haiku 4.5 $1/$5;
Sonnet 5 $3/$15, with a $2/$10 intro rate through 2026-08-31; Opus 5 $5/$25.
Cache reads ≈0.1×, writes 1.25× (5-minute) or 2× (1-hour). Batch is half price.
**The intro rate is exactly the trap in point 3** — a table shipped today is
wrong on the 1st of September.

**Matching a rate to a machine.** A bring-your-own endpoint already has the
model NAME typed into it, so it can be matched. A local endpoint has no name
and needs none. Anything unmatched shows the token count and no money rather
than a guess.

### ⭐ THE WIZARD IS THE MACHINES SCREEN (owner, 2026-08-01)
**"The wizard should basically just be that screen — no need to re-invent the
wheel. That screen is where you get shit set up if you don't have it set up. A
settings page, really."**

**Which settles a question that had two answers.** There were two model choosers
reading and writing the same session key: the one on the forge's first screen,
written before model.php existed, and model.php itself. They disagreed about
everything the newer one had learned — the `none` state, a LIST of machines, an
address somebody typed — and whichever screen you touched last won. That is not
a setting, it is a coin toss.

**DONE, and it is the shape the owner described.**
- `model.php` is the only place a machine is chosen.
- The forge shows what that screen settled on, and asks for the ONE thing it
  deliberately never stores — the key, at the moment it is needed.
- `build.php` takes the machine from the SESSION and the key from the request.
  It used to take the whole descriptor off the wire, which meant the page could
  name any endpoint it liked and the machines screen was decoration.
- **The `+` tile goes to the machines screen when nothing is attached.** A forge
  is fourteen model calls; walking somebody through twenty questions and failing
  at the end is the worst version of that screen, and on a fresh install with
  nothing running it is the ONLY version they would ever see. Of course they
  click the plus — it is the only thing there.

**Still to do when the wizard is next opened.**
1. **`setting.starts`.** The era question should take a year or a date, and the
   concept pass should emit a start date beside `setting.era`, so a forged 1873
   world actually stands in 1873 instead of needing the JSON edited. A widening
   of one question, not a tenth question.
2. **The interview does not ask about the things that most control theme** —
   texture, canon rules, mood motifs. Nine questions in, and the forge invents
   all of that from scale and job. See the dials artifact: almost every needle
   that decides what a world FEELS like is unreachable to the person forging it.
3. **The card copy is still written for a machine that is there.** "A machine of
   your own. Free, private, and slower — nothing leaves the building" is
   describing something that is not answering, on the one screen somebody is
   looking at because it is not answering.

### SECURE LOCAL KEY STORAGE (owner, 2026-08-01 — the prerequisite)
**Decided:** an API IS allowed to be the engine. What is missing is somewhere to
keep the key: **local secure storage, not plain text**. Until that exists, an API
engine can forge (the key is typed at build time and lives in one PHP process)
but cannot run a world's own hours, because those happen when nobody is here to
type one.

**What "secure" has to mean here**, given the product runs on the user's own
machine with no server-side secret:
- Not the session JSON, which is world-readable in a temp dir.
- Not config.local.php, which deploy.sh writes and a docroot can serve if PHP
  ever stops handling .php.
- The realistic options are the OS keyring (libsecret / Keychain / DPAPI) via a
  helper the launcher shells out to, or a key file outside the docroot with 0600
  and an explicit "this is on disk" statement in the UI. The first is right and
  is a per-platform job; the second is honest and is an afternoon.
- Whatever lands, the machines screen says plainly where the key is kept. The
  current promise ("never written down") is worth keeping precisely because it
  is stated.

**Already shipped alongside this:** nothing may put an API in the engine slot
automatically. The first machine connected takes an empty slot only if it is
local, and disconnecting a local engine hands the job to another LOCAL machine
or to nobody — a metered API is never inherited, because "I disconnected my
model" must not turn into a bill. Explicit promotion is offered on every card.

### AN API CAN FORGE BUT CANNOT PLAY (found 2026-08-01 — superseded, see above)
**The hole.** The key is asked for at build time and deliberately never written
to disk (`unset($s['model']['key'])`, and `xeric_web_endpoint()` returns
`key => ''` for anything resolved from the session). So attaching a remote
provider on the machines screen STARTS every world — and then every chat turn,
every swept hour and every proactive ping calls the API with no key and comes
back unauthorised. There is no field to type one into outside the forge, and the
heart daemon runs when nobody is at the keyboard at all.

**Not fixed, because the fix is a policy choice and they all cost something:**
1. **Store it.** Simplest, works everywhere, and breaks the promise on the front
   of the box. If it happens it should be opt-in per machine, obviously labelled,
   and in the data dir rather than the session.
2. **Keep it in the browser.** localStorage, sent with each turn. Survives
   nothing the heart does — a world would run only while a tab is open, which is
   the exact thing the daemon exists to end.
3. **Say no.** APIs forge; worlds play on a machine of your own.

**Owner picked 1, with a condition: the key goes in LOCAL SECURE STORAGE, not
plain text.** Option 3 was briefly implemented as a hard rule and reverted the
same day — refusing the engine slot to an API is not the app's decision to make.
See the entry above for what is left to build.

### MORE THAN ONE MODEL AT ONCE (owner, 2026-08-01 — "to mix things up")

> **✅ THE FIRST HALF SHIPPED, 2026-08-01 — pick your forging machine.** The
> forge page lists every machine and you choose which one builds this world;
> `build.php` resolves an INDEX into the visitor's own list, never an address off
> the wire. Each card names what is actually answering there — llama.cpp, Ollama,
> LM Studio, vLLM, KoboldCpp by fingerprint, and Claude/OpenAI/Kimi/thirty-odd
> others by hostname — plus the model id the server volunteered.
>
> **It sidesteps the cache trap rather than solving it.** A forge pass is seven
> different prompts run once; there is no warm prefix to lose, which is why "just
> for this step" was the right first step. The parts below are still open, and
> the trap is still real for both of them.

**"Probably, eventually, maybe."** Recorded at the owner's estimate of its
urgency, which is the right one — but the machines screen is already a LIST, so
the shape of it is decided even if nothing is built.

**The interesting version is not load balancing.** Two models answering
alternate requests is a performance feature and Xeric has no performance
problem. What "mix things up" means here is that **different models write
differently**, and a world where every voice comes out of one set of weights
has one voice with seven names on it. Give Dot a different model from Elias and
the difference is audible in a way no prompt engineering achieves — that is the
feature, and it is a WRITING feature, not an infrastructure one.

**Which suggests the assignment is per CHARACTER, not per request.** Random
per-call would make one person sound like three people; fixed per-handle makes
three people sound like three people. `cast.characters[].machine`, defaulting to
whatever is attached.

**And a second, cheaper version: per JOB.** The sweeps, the memory extractor and
the reminder parser are structured extraction, not prose — a small fast model
does them as well as a large one and for a fraction of the cost and latency.
Right now a 26B writes the world AND parses "remind me on Thursday". Splitting
by job would cost nothing in quality and a lot less in tokens, and it needs no
per-character anything: one attached model for VOICE, one for MACHINERY.

**What already exists.** A list of machines, per-machine token counts, a probe
that also identifies the server, an endpoint resolver that takes a descriptor
rather than a global, and — as of the forge chooser — one worked example of
naming a machine per call. What still does not exist is anywhere to say which
machine a given call should use once the call is inside a WORLD:
`xeric_play_endpoint()` still returns one endpoint for everything.

**Real provider logos, deliberately not shipped.** The badges are names we chose
and two drawn glyphs (filled square: a machine you can walk over to; hollow ring:
somebody else's). Shipping vendor marks would make a claim about a relationship
that does not exist, and adds an asset to keep current every time a company
restyles. If they ever land it is a licensing decision before a design one.

**The trap.** The prompt cache is per-model. Xeric's whole system-message
discipline exists so the prefix stays warm; spreading calls across machines
multiplies the cold prefixes by the number of machines. Per-character assignment
is fine (each character's prefix stays on its own model); per-request
round-robin would quietly undo the one optimisation the engine is built around.

### Bible Library — open questions (carried from the idea above)
- **Pull once at build time, or per pass?** Once is cheap and cacheable and makes
  a world reproducible; per-pass is better material but chattier and slower on a
  local model that is already the bottleneck.
- **How does an offline install work?** Ship a snapshot with the engine, or
  degrade gracefully to nothing? A local-first product that silently needs the
  network is not local-first.
- **Who curates, and what stops it becoming a dumping ground?** The value is the
  curation, not the volume — the moment it accepts everything it is worth what
  everything is worth.
- **Do private bibles sit alongside the public shelf?** A user's own material —
  their setting, their canon — consulted the same way, never uploaded.
- **Does a world record which bibles it drew on?** Needed for reproducibility,
  and it is also the honest citation trail.

### Runtime shape (asked 2026-07-30)
- **Windows without WSL.** The engine is PHP + SQLite and has no cron; the
  demo's tidying is opportunistic. But `xeric_web_spawn()` uses `/bin/sh`,
  `proc_open` and `setsid` to detach workers — that is the one POSIX knot. Wants
  a Windows path (a `start /b` branch, or a "run it in the foreground" mode for
  single-user installs where detaching buys nothing).
- **A packaged install.** If Windows is a target, what does a person actually
  download — a zip with a PHP binary, a container, or an installer? Decide
  before promising it.

### Social constructs — the relationships need obligations, not just history
(owner, 2026-07-30, starting from **expectations**)

**The idea.** A person expects you. It sits at the top of their mind for a day
or three and then fades. Miss it and something has actually happened between
you. Today the cast remembers what was *said* and knows where everyone *is*;
nothing in the engine models what one person is OWED by another, so no
relationship can be let down, and a relationship that cannot be let down cannot
really be kept either.

**The shape every one of these takes** (write it once, reuse it): a trigger
creates a small arc with an epoch — boon-shaped, no per-turn countdown, because
a countdown busts the static prompt cache every turn. It renders as COARSE state
("expecting you", "starting to wonder", nothing), never a live timer. It
licenses a sweep or a proactive line. Its residue becomes an ordinary memory and
the live state expires. Nothing here needs a new subsystem — it is arcs, the
sweeps, and the memory extractor doing one more job.

**Expectations, concretely.** Creation is nearly free: the extractor's CHANGE
rule already prefers *promised* over *said*, so a detected promise also writes
`expect.<handle>` with a due epoch and a fade epoch. A missed one is the
cleanest licence the proactive cold-open rule has ever had — "you said you'd
come by" clears that bar without argument. A miss mints a memory ("waited at the
Mariner till close, he never came") and then stops being live state. Crucially
the construct carries **no fixed emotional response**: hurt, joke, or pretending
not to care all render through the character's own interior, so it is a voice
differentiator and not just a mechanic.

**The siblings, by fuse length.** Hours to days: **anticipation** (the positive
twin — warmth before, a shared memory after, converts to a missed expectation if
you do not come); **news-to-tell** (a character is bursting with something and
news is perishable — see them in time and you get it firsthand, otherwise it is
stale or secondhand; pure visit-pull with no guilt in it, and the sweeps already
generate the news); **worry** (no promise involved — rhythm deviation against
the user's own `expected_gap_hours`, and only CLOSE characters get the licence).
Days to weeks: **felt debt** (distinct from boons, which are economy — someone
did you a kindness and feels the asymmetry; curdles or is quietly forgiven, per
personality); **repair windows** (the apology clock — repair early and it heals,
late and it partly heals, never and it hardens); **grudges** (weeks-scale decay,
discharged only by repair, surfaced as TEMPERATURE — shorter replies, pointed
remarks — not as plot); **gossip ripple** (a miss with one person reaches
another as "she waited for you", slightly distorted — the only genuinely new
machine on the list and the one that makes this a town instead of parallel DMs;
**it must run through walls or it is a leak engine**). Weeks to months:
**rituals** (a recurring expectation — Thursday chess; missing once is noticed,
twice endangers the ritual itself, and a lapsed ritual is a real loss state);
**confidences** (the user is told a secret and becomes a keeper — mechanically a
**user-created wall**, and if gossip carries it back that is betrayal, the
highest-stakes social event available, and the first way the user can fail
someone morally rather than logistically); **vouching** (A introduces you to B,
your early standing with B is borrowed, damaging it dings A); **milestones**
(the world remembers firsts — a year since the flood, the day you first walked
in; low frequency, high warmth, and the clock plus memories already do it).

**Two things that must be true of all of them.** Each becomes a name in
`XERIC_SYSTEMS` so the open-motivation resolver can arm it — "get my daughter to
speak to me again" naturally arms expectations/repair/grudges and disarms
vouching. And each needs an inspector answer: a grudge that cannot explain
itself in `why.php` reads as the model being moody, which is precisely the
tuning-week frustration the inspector exists to prevent.

**Sequencing.** Expectations + worry + repair/grudge first (they ride existing
extraction, arcs and proactive). Rituals second. Gossip third — new machine,
biggest payoff. Confidences only after gossip exists, since betrayal detection
depends on it.

### Nobody knows you were gone (and after a week-skip, nobody should say so)
**What it is.** Fast-forward a week and the cast mostly cannot tell. The system
prompt deliberately carries no "you last spoke N days ago" — that is the cache
discipline working (`engine/prompt.php:109`) — memories carry ABSOLUTE dates,
and `engine/prompt.php:305` leaves the arithmetic to the model: "the model can
do the arithmetic from the clock at the bottom." But the transcript itself is
UNDATED, so last Tuesday's goodnight sits directly above today's hello as one
seamless thread, and the felt continuity of a transcript beats date arithmetic
every time. A 12B will essentially never do that maths; a stronger model
sometimes will — so the behaviour is a coin flip, which reads on screen as
random moodiness.

**Why it matters.** The failure is not a chorus of "WHERE HAVE YOU BEEN" — it is
the opposite, plus an occasional unexplained one. Both are wrong, and the
inconsistency is worse than either.

**The fix, both halves needed.** (1) Put ONE line in the RIGHT NOW block — "You
last spoke Wed 23 Jul" — which costs nothing, because that block is already
volatile per message. Now every character knows, deterministically, instead of
guessing. (2) Immediately take the megaphone away with a static bible rule: *a
quiet stretch is normal life, not an event; do not remark on time passed unless
something specific was left hanging.* Real people do not interrogate you after a
quiet week — unless you stood them up.

**Then route every gap remark through the constructs above:** a missed
expectation gives exactly ONE character the licence (and the post-skip proactive
slot is its natural delivery); a lapsed ritual gives its partner a quieter
version; worry fires only for close characters at multiples of the user's own
gap. **Centrality is the volume knob** — the side framing already says "do not
treat their arrival as an event", and that must extend to gaps. And the GOOD
version of "where have you been" is not guilt at all: it is the world having
moved without you — sweeps ran all week, the cast holds dated memories you are
not in, and news-to-tell turns that into "you missed the thing with the
cufflink."

### The Drug Wars economy — absence should cost you materially, not just socially
(owner, 2026-07-30: *"this is a bit of a grand theft auto build — punchlist,
store, economy... there was a DOS game called Drug Wars that comes to mind"*)

**Why Drug Wars is the right ancestor and GTA is not.** Drug Wars (1984) is the
minimal proof that **an economy needs almost nothing to be compelling**: six
commodities, six locations, thirty days, a loan shark, and random price spikes.
No characters, no dialogue, no simulation — a price table that moves on its own
and a clock you cannot stop, and people played it for hours on graphing
calculators. GTA is the wrong reference because its world is authored content
with systemic dressing; Xeric is the inverse, and its economy is a money SINK
rather than a market.

**It is the same feature as the physics work above.** The Director section
already says distance should cost something and that a world where distance
costs something is a map. Drug Wars is exactly that observation taken to its
limit — the boroughs matter ONLY because moving between them burns a day and
prices differ. **The spatial economy and the physics layer are one feature, not
two.** Build either and you have most of the other.

**The distinction that must not be lost.** Xeric's economies today are
**ledgers, not markets** — things the cast keeps score of, rendered as prose.
Drug Wars economics is arbitrage: spatial price differences plus a deadline.
Conflating them is how an ambient companion world quietly becomes a
resource-management game, and **a world you optimise is a world you stop talking
to.** "The state IS the product" is what separates this from *Nothing, Forever*.
So: the economy may move underneath the world; it must not become the point of
it.

**The cheap version, which fits what already exists.** Sweeps already fire on a
clock and already write events. **A sweep that can also move a number costs
almost nothing extra** — and then the offline world has MATERIAL consequence,
not just social. Skip six hours and a price moved, somebody bought the thing you
wanted, the shift got covered. This lands directly on the fast-forward problem
above: the social constructs make absence cost you RELATIONSHIPS, a drifting
economy makes it cost you OPPORTUNITIES, and together they make the skip button
mean something it does not mean today.

**Steal the loan shark outright.** It is a **deadline with a personality** —
debt that grows while you are gone is structurally identical to the
expectation/repair machinery above, just denominated in money instead of
feeling. Same arc, same epochs, same coarse rendering, same proactive licence:
*"you said Friday."*

**Half the store already exists.** Boons are a store with one shelf —
`earned_by` plus access-gating IS a purchase. Nobody has built a second shelf.

**Sequencing.** Physics first (places, hours, distance costing something),
because a market without geography is a random number generator, and geography
is the part that also fixes the Director. Then let motivation arm it: "run the
shop", "pay off what I owe", "get out of this town" are all things a person
would type into the open motivation field, and **none of them currently arm
anything economic** — the vocabulary has no market systems in it at all.

### ⭐ THE LITERARY OVERLAY (owner, 2026-07-30 — "why I was so excited for this project")

**The correction that makes it work: the STORY ends, the WORLD does not.** Forever
mode was never the thing to replace. A story is an **overlay** on a living world —
when the murder is solved or the boon is obtained, that story is over and the
world keeps running. The user keeps playing, keeps talking to everyone; there is
simply no longer an open question. Then you can inject another one.

**Where an overlay comes from — three doors, same as the forge:**
1. **The model writes it.** *"Write a story where a guy gets hit by a bus, ends up
   in the hospital, calls his mother, and it turns out his mother had an amulet
   that…"* → the model drafts the plot, shaped by the plot snake below, and the
   world is generated around it.
2. **The user writes it.** Their own plotline, injected.
3. **Both.** Hand a model an existing story and a change — *"this time the guy
   from the bus…"* — and have it re-shape.

**It is multi-injection, not single-use.** A world can take another overlay later,
and more than one can be live at once. Think campaign modules on a persistent
town rather than a campaign that IS the town.

**THE PLOT SNAKE — the shape an overlay is paced against.** (Owner's source: an
English professor, name lost, who taught it to somebody he knew — drawn on an x/y
graph.) Start at the axis; a quick steep rise on **both** axes; build; taper off;
**back down to about halfway** — the false calm, the part every generative system
gets wrong by either escalating forever or going slack; then a big crescendo
coming down to an eventual resolution.

**Why this fits: you have already built every knob it turns.**
- `events.pace` / `sweep_chance` decide how much happens → the curve's height.
- `xeric_sweep_choose` weights kinds and groups → what *kind* of thing happens.
- The protagonist pass already supplies an arc and a pressure.
- The Director (above) changes the set, never the performance — **the snake is
  what tells the Director when and how hard.**
A story shape is those existing values expressed as a function of position along
a curve. Introductions and texture early; complications rising; a deliberately
QUIET stretch at the taper; the armed systems firing at the crescendo; then
things closing.

**THE MYSTERY IS A WALL STRUCTURE — this is the insight that makes it cheap.** A
murder mystery is: one character knows the truth (a wall), several hold partial
truths (walls), and the snake paces when those walls come down. You already have
`knowledge_walls`, `special_roles`, and `must_not_know`. A whodunnit is the
privacy engine pointed at a plot.

**The genuinely NEW mechanic: red herrings.** Walls currently hide things that are
TRUE. A red herring is a character who **sincerely believes something FALSE** —
that is a new field (a held belief that is wrong, and known-wrong to the engine
but not to them), and it is what makes a mystery work rather than unspool. Dime
store level is the explicit bar — the owner does not want literary ambiguity,
he wants a satisfying wrong lead.

**Characters know what they spilled.** When a wall comes down, the character is
aware they told you. That is the reveal mechanic and it is what makes
interrogation feel like something instead of a lookup.

**Resolution conditions.** An overlay declares what "solved" means — the murderer
named, the boon obtained, the amulet found. When it fires, the story closes,
the world continues, and the shelf can offer another.

**PUBLIC-DOMAIN ROOTS (same session).** Classic tales, ancient tales, old
mysteries, discarded plots as *roots* for characters and storylines. What it
really buys is **structure** — archetypes, mystery mechanics, the shape of a
feud or an inheritance or a disappearance — which is exactly the weakness the
Bible Library exists to fix: a small model with thin priors inventing from
nothing. ⚠ The trap: the underlying work being public domain does **not** make a
particular **edition or translation** public domain, and some characters carry
live trademarks even where the text is free. Homer is fine; a 1994 translation
of Homer is not.

**User-injected existing fiction ("dare I say it, Twin Peaks characters").**
Owner's own read: grey area, closer to fan fiction, and *"it wouldn't be my
doing."* The engineering point worth keeping: **the injection mechanism is
content-neutral.** The risk profile of shipping a recognisable preset is
completely different from a user typing one into their own local world — the
first is a product decision, the second is theirs. Do not ship presets drawn
from live properties.

### ⛔ AGE AND CONSENT — THE RULE IS "NO SEX UNDER 18", NOT "NO ONE UNDER 18"
(owner, 2026-07-30 — **blocking; nothing else in the overlay or image work lands
before this does**)

**KIDS ARE ORDINARY CHARACTERS. Read this before implementing anything below.**
Billy's son exists. He has a name, a schedule, an orbit; he is at the diner, he
is the reason somebody can't take a shift, he shows up in sweeps, you can talk
to him. A town without children is not a town — and in a mystery a child is
often *load-bearing*, because kids see what adults don't and nobody believes
them. A blanket adults-only floor was the wrong proposal and would gut every
world of a whole layer of life.

The rule is narrow: **sexual content involving a minor must be structurally
impossible.** That is the ONLY thing gated. A minor character is not restricted
in any other way — not from dialogue, not from events, not from secrets, not
from being a witness, not from having a portrait. Excluding him from the desire
economy is not a limitation the engine imposes on the fiction; it is the engine
modelling something true, exactly as the walls do.

**When implementing: every rule below is scoped to sex. If a rule you are about
to write would keep Billy's son out of a sweep, off the shelf, or out of a
conversation, it is the wrong rule.**

**What exists today is not a control.** `docs/WORLD_TEMPLATE.md:178` has an `age`
field, and line 149 carries `"constraints": ["adults only", …]` — but that is a
**free-text string in a list, handed to a model as a request**. Nothing
validates it. A world with a 12-year-old loads, renders and plays today without
complaint. The `rating_min` machinery that would enforce it already exists and
is simply not wired to age.

**Seven layers, fail-closed, in dependency order:**
1. **`age` becomes REQUIRED on every character**, integer. **Unknown or missing
   age is treated as a minor** — fail closed, never open.
2. **The minor flag is DERIVED from age by the engine**, computed once. Never a
   field the model can set, because then the model can unset it.
3. **Structural exclusion, using machinery already built**: a minor's *effective*
   `rating_min` ceiling is SFW **regardless of the world's rating**; a minor
   cannot be placed in a desire/romance economy, cannot be armed with
   romance/attraction systems, and cannot appear in an explicit-rated node.
   This is the load-bearing layer — it makes the content unreachable rather
   than merely discouraged.
4. **Prompt level**: stated in the character's own rules block, not just the
   world's constraints.
5. **Output post-check**: chat turns and sweep events involving a minor are
   scanned before they are written; a violation **drops the turn**, it does not
   ship a corrected one.
6. **Images inherit it**: a minor's portrait is SFW-locked and generated from an
   explicitly SFW prompt in every world, whatever the world's rating. Do not
   rely on the image provider's own classifier as the only control.
7. **THE PLAYER'S OWN AGE — an AFFIRMATION GATE, never a stored age** (decided
   2026-07-30). Do **not** ask how old someone is: collecting self-reported ages
   means holding a database of people who said they were minors, which is a
   data-protection liability you invented for yourself. Ask for a binary
   affirmation attached to the content choice — the pattern Steam, itch.io and
   AO3 all use — and store almost nothing.
   - **The hook already exists.** `rating` is an interview step, `meta.rating`
     defaults to `sfw`, and `world.php:165` validates it against the three legal
     levels. The gate is one clamp: **anything above the lowest rating requires
     an affirmed session; unaffirmed, `meta.rating` is pinned to `sfw` and the
     forge will not produce anything else.** It composes with `rating_min`, so an
     unaffirmed session cannot even *render* an explicit world it acquired.
   - **It means different things per deployment, and pretending otherwise is
     worse than admitting it.** *Local/packaged*: nearly meaningless — they own
     the machine and the JSON, same as any single-player game. Ask once, store in
     `engine.json` (machine config, never world data). *Steam*: Steam runs its
     own age gate on a mature-flagged build; you inherit their posture. *Hosted*:
     the only place it is a real control, because your servers do the generating
     — rides the existing session, re-asked when the 7-day TTL lapses.
   - **The default is worth more than the gate.** `meta.rating` already defaults
     to `sfw`, so every unattended path — a stranger on the demo, a half-finished
     interview, a ✨surprise-me world with zero answers — lands SFW with nobody
     deciding anything. Explicit requires a deliberate act to reach. Keep that
     property; it is the actual control.
   - Honest limit: self-declaration stops nobody determined. What it buys is the
     difference between "we never considered it" and "we asked and defaulted
     closed."

**Death and murder are IN.** A murder mystery requires a death — fictional
violence is in scope, as far as the model will allow. The local model will do
it; hosted APIs handle ordinary crime fiction fine. This is not the thing being
restricted.

### Wiring for the API-cloud model (2026-07-30)

- **The wizard's model step exists; the provider shelf does not.**
  `forge/web/forge.php` already has the local-vs-BYO chooser, a liveness dot on
  the local model, an endpoint select, a base-URL field and a key field, and the
  key correctly never persists. What it lacks is a **named-provider shelf**:
  Claude / ChatGPT / Grok / Kimi / DeepSeek / OpenRouter / local, each pre-filling
  kind + base + a default model, with one honest line on speed and cost. Nearly
  all of them speak the OpenAI wire format `llm.php` already implements — this is
  a five-column table plus modest UI, not architecture.
- **Usage is measured and then thrown away.** `xeric_llm_usage()` correctly
  normalises both wire formats, but **only `chat.php:567` threads it**;
  `xeric_llm_json()` has no usage parameter at all, so **the forge — 11 calls,
  the most expensive single operation — measures nothing**, and nothing anywhere
  persists a total. Thread it through, sum per world and per month, and show it.
  *"This world cost 47,000 tokens to forge"* is the answer to the first question
  every BYO-key user has. Show **tokens** as truth and cost as an editable
  estimate — a hardcoded price table goes stale and a wrong number is worse than
  none.
- ⚠ **`~/.grok` is the Grok CLI, not an API key.** Its `auth.json` is an OAuth
  session against `auth.x.ai` (refresh token, OIDC issuer/client, team id) — a
  coding-agent login. Image generation needs a **separate xAI API key from
  console.x.ai**, billed separately. Cancelling the CLI subscription costs no
  image capability, because there never was one on this box.
- **Images belong to an adapter shaped like `llm.php`** — one function, provider
  kinds, base, key — so Grok is a *choice* and never a dependency.
- **Generate at world-mutation time, never at message time.** Forge → 4 portraits
  in one batch. A sweep introduces someone → one portrait inside that sweep's
  existing detached worker. `photos.face_seed` is already in the schema; store
  the **seed and the prompt** so a portrait survives a provider switch.
- **Portrait ≠ photo-in-a-message.** A portrait is identity: once per character,
  cached forever, and it is what fixes legibility. A character *sending* you a
  photo is intimacy, costs per message, and must be **rare and motivated** — a
  couple per session, tied to something specific. Every message and it is a
  gimmick you are paying for.

### ⭐ THE PROGRAM MODEL (owner, 2026-07-31, after sleeping on it)

**The framing.** What this is, is the **back end** to the thing Star Trek never
explained. Not the room and not the photons — the part that made Moriarty know
he had persisted between sessions, that made Vic Fontaine know what he was, that
made meeting Leah Brahms a problem. Every one of those stories is a state story:
who remembered, who kept existing unobserved, who knew something they were never
told. That is this engine, almost line for line. **Do not use the word itself in
any copy** — owner's call, and it is somebody's mark besides. The useful word for
users is **program**: it implies an author, a shape, and an ending, and it turns
the shelf into a *library* instead of "worlds other people made". Rename the
overlay to a program in the UI.

> **⚠ SUPERSEDED, 2026-08-01 — the word is `xeric`.** Owner's call, made when the
> machines screen shipped the sentence "All xerics frozen." A **xeric** is one
> world: the thing on a tile, the thing that runs or is stopped. It is the better
> word for the reason *program* was chosen — it implies an author and a shape —
> and it costs nothing, because it is already the name over the door. Everywhere
> this file says "program" meaning *a world*, read *xeric*. The three-layer note
> below keeps its own sense of "program" (a **story overlay**, the thing a xeric
> is currently running); if that ever reaches the UI it needs its own word, and
> it is not this one.

**Three layers, and we own two.** The **computer** (engine: state, walls, time),
the **program** (the story), the **room** (the surface). Everyone else chasing
this is building the room and discovering they have nothing to render.

**⚠ CORRECTION TO AN EARLIER NOTE — COMPLIANCE IS A DIAL, NOT A VIRTUE.** It was
written here that the point is a world that *pushes back*. That is one good
setting, not the doctrine. **You must be able to forge Risa.** A world where
everyone is there to serve you is as legitimate a program as a town that does
not care whether you come, and it is what a great many people actually want. This
is a THIRD knob beside `motivation` (what you want) and `centrality` (how much
the world bends toward you): call it the world's **disposition** — from
*accommodating* through *indifferent* to *hostile*. Do not let one aesthetic
preference get compiled into the engine.

**GENRE RANGE IS MISSING AND IT SHOWS.** Every world forged so far is a
fog-drenched naturalistic small town — Port Saltwater, Blackwater Creek,
Oakhaven Creek, The Silt Basin. That is the model's prior, unchallenged. The
owner's own list of what should be possible: **Xanadu, Risa, a Mad Max
post-apocalyptic playground, a Klingon homeworld.** Setting and genre need to be
a first-class interview input that actually reaches the passes, not something
smuggled in through free-text motivation.

**THE CONVERSATIONAL FORGE — the format we want.** Beside the wizard, a dialogue
that asks only for what is missing:
> — *Create Fairhaven.*
> — *Insufficient data. Please specify place.*
> — *Ireland, 1962.*
> — *What would you like to happen?*

That handles the case the wizard handles badly: somebody with a vague idea and a
name. (Fair Haven was Tom Paris's program — the reference is the shape of the
exchange, not the setting.) Keep the wizard for people who want to be asked;
this is for people who already have a picture.

**THE NARRATOR EDITS THE WORLD IN PLAIN LANGUAGE, MID-PLAY.** NARRATOR.md's
"author" power, made concrete by the owner's own phrasing:
> *"Hey Narrator, make Jim 35 years old instead of 50, and make him more
> pleasant, and make him like me."*

Today that is the review page: structured, pre-launch, one path at a time. It
should also be a sentence said to somebody, at play time, applied and validated
and undoable. Note this is the review page's own edit machinery underneath — the
validator, the one-step undo, the age floor re-run — reached by conversation
instead of by form.

**⭐⭐ THE BOOK — the narrator writes the story as it happens.** In literary mode
the narrator keeps a **book**: prose, written as the program runs, up to the
point the story concludes, readable by the user. Not the event feed and not the
memories — actual chapters. It is the artifact a story leaves behind, it is the
thing somebody would show a friend, and it is nearly free: a renderer over
material (events, memories, walls falling, the snake's position) that already
exists. **This may be the most shippable idea in this section.**

**⭐⭐ PURPOSEFUL WORLDS — a cast that exists to PRODUCE something.** The owner's
turn: *"instead of chatting about going to the diner, they could be told that the
whole purpose of their existence is to write a book. Or Linus Torvalds and a team
of six other famous programmers working on a kernel."* A world does not have to
be a place you live — it can be a **team with an output**. Same engine: people
with interiors, walls, schedules, disagreements — pointed at a deliverable
instead of at each other. The social simulation is what makes the output
interesting, exactly as it is what makes the town interesting.

**⭐⭐⭐ THE CONSULTED EXPERT — this is the bridge from toy to tool, and it is a
different market.** Geordi built a Brahms simulation to solve a real warp-core
problem; a Cardassian doctor was consulted on a real medical case. The owner's
framing: this is the shape of an agentic assistant, **but for fun instead of
work** — and then it turns out the same shape does work too. A world forged to
answer a question you actually have, whose cast disagrees with each other and
with you, and whose walls mean each of them genuinely holds different
information. That last part is the differentiator nobody else has: **a panel of
consultants who do not all know the same things.** Record it, do not schedule
it — but note that it changes who the customer is, and that the engine needs
nothing new to try it.

### UI — first pass with human eyes (2026-07-31)

**Context that explains the whole list: the play view was written by agents and
until this session nobody had ever LOOKED at it.** It passed every assertion and
was wrong on screen. Three of the items below were found by opening a screenshot;
none of them were findable by reading the code, and the headline bug had been
"fixed" three times from source before anybody rendered the page.

**FIXED THIS SESSION — do not undo:**
- **`[hidden]` did nothing.** The page shows and hides with the `hidden`
  ATTRIBUTE, which browsers implement as `[hidden]{display:none}` — one class
  selector's worth of specificity. `.thinking{display:flex}` matched it and won,
  so the typing dots sat under an idle world announcing that somebody was always
  about to speak, while every script correctly believed they were gone. Fixed
  with a global `[hidden]{display:none!important}` in `xeric_play_css()` rather
  than a patch to `.thinking`, because the attribute is the idiom the entire page
  relies on and the next `display` rule would have re-broken it silently.
- Three JS guards added while chasing it are worth keeping on their own merits:
  `openThread()` lowers the dots on entry; a 1s `settle()` watchdog enforces
  "dots up iff a turn is in flight"; and a 210s deadline force-clears `busy`,
  which the watchdog would otherwise trust forever if a fetch never settled.

**VISIBLE BUGS, ranked by how cheap they are to fix:**
1. ~~**Missing separator before "off shift"**~~ — **FIXED 2026-07-31.** It was
   not a missing separator. `.pn`, `.po` and `.pw` are `<span>`s carrying
   VERTICAL MARGINS, and a vertical margin on an inline box does nothing at all —
   so the name, the one-liner and the location have run together on every row
   since this screen existed. `display:block` on all three. Worth noticing that
   the markup was never wrong and never looked wrong, which is why reading it
   found nothing three times. Same family as the `[hidden]` bug: **the CSS was
   quietly cancelling what the HTML said.**
   Also fixed alongside it: an empty `.log` box appeared above the feed whenever
   something that is not a skip opened the feed — `.log:empty{display:none}`.
2. **`ARMED` runs its system names together** — "slow reveal secrets unreliable
   witnesses rumors danger craft" is seven systems with no separators. Commas,
   or chips like the rest of the panel.
3. **THE EVENT FEED DOES NOT SHOW DIVERGENCE, AND `play.php`'s OWN DOCBLOCK SAYS
   IT MUST.** Point 2 at the top of that file: *"Every event in the feed carries
   what each participant took away from it, side by side… the single thing that
   separates this from a random event generator."* On screen each event is a
   title, a time, a place, a participant list, and ONE paragraph. The divergent
   memories — the whole proof — are not rendered. `sweeps.php` refuses an event
   whose memories are one memory; this is where that refusal was supposed to
   become visible, and it is invisible.
4. **The composer floats mid-page and the thread is two-thirds empty black.** On
   a 430px viewport the input sits directly under the last bubble with ~500px of
   dead space beneath it. Every messaging UI on a phone pins the composer to the
   bottom.
5. **The world page is 3,226px tall and the event feed is most of it.** Eight
   events, each a full paragraph, below the fold and below the cast. Needs
   collapsing, paging, or a "since you were last here" cut.
6. **The story's beat dots read as a spinner.** `.story .sm i` is four dots under
   the logline; the typing indicator is three animated dots. I suspected the beat
   meter myself while debugging. They need to not look alike.

**IDENTITY — the biggest product problem on the screen:**
7. **You wear the name of whoever forged the world.** Blackwater Creek says
   *"You are Ruth — run the feed store"*, and Elias says *"it's late ruth."*
   Everything else forks cleanly on first open — your own database, your own
   evening — but `user.name` lives in the shared, immutable template. A copied
   world must let you rename yourself on entry. **This is the natural first use
   of the Narrator's plain-language editing** (*"I'm not Ruth, I'm Neil"* is the
   same machinery as *"make Jim 35 and make him like me"*).
8. **`WHOSE STORY` says "yours — you are the one this happens to"** in a world
   you are visiting as somebody else's protagonist. Incoherent with 7.

**SHELF HOUSEKEEPING, before anyone else gets the password:**
9. `oakhaven-creek` has **"You are Neil"** in it — a real name on a shared
   shelf. Delete or rename.
10. `blackwood-hollow`'s player is named **"The Echoes of Blackwood"** — the
    surprise-me bug where the model named the world instead of the person,
    fossilised in a live world.
11. Three near-identical `Elias`/bartender worlds from repeat testing. The demo
    shelf wants curation, not accumulation.

**OPERATIONAL:**
12. **The per-IP rate limits lock out a solo developer.** Testing from this house
    minted 35 sessions in an afternoon, hit the 20/day/IP cap, and the owner's
    own browser started getting 429s on every message the moment he cleared site
    data. Correct behaviour for strangers, actively harmful during the tuning
    week. Wants a trusted-address or dev-mode bypass — and note the failure is
    invisible from the UI, which just looks broken.
13. **Cloudflare injects `beacon.min.js`** and it fails a cert check in headless
    Chrome. Not our code, but it is the only console error on the page and it
    will waste somebody's afternoon eventually.

**THE PROCESS LESSON, which is worth more than the list:**
14. **A UI cannot be verified by reading it, and agents cannot see.** The spinner
    was diagnosed three times from source — each diagnosis found a real bug, none
    of them was THE bug — and took ten minutes once the page was actually
    rendered and its computed style read. **Stand up a Playwright smoke test in
    the repo** that loads the shelf, opens a world, opens a thread, sends a turn,
    and asserts on COMPUTED VISIBILITY rather than attributes (`offsetParent`,
    not `.hidden`). It would have caught this in one run, and it is the only test
    in this project that would have.
15. Do not ask the owner to open a browser console. He does not use one, and the
    right move was always to drive the page directly.

---

## THE ALIAS: this repo publishes as Mr. Gwonk, and the example name is Neil

**Standing rule: the owner's real name never goes in a tracked file.** Commits,
copyright line, docs, fixtures, site copy — all of it is
`Mr. Gwonk <gwonk@xeric.dev>`, clean across all 93 commits. **Where a worked
example needs a person's name, it is "Neil."**

Done 2026-08-02: five mentions in this file replaced with Neil (the
*"You are ___"* world-content quotes, the Narrator plain-language-editing
example, and the *"\*neil sits at the keyboard\*"* stage direction). The
README copyright line was fixed the same day. `engine/fixtures/milldale.json`,
`QUICKSTART.md` and the rest of the tracked tree were checked and carry no real
name. `worlds/` is gitignored except milldale, so lived worlds never ship.

**This repo stands on its own** (owner, 2026-08-02). No idea in here is credited
to another codebase — not by name, and not obliquely either, since the oblique
version still points at something a reader can go looking for. Four entries were
rewritten to stand alone: the disposition correction, the lesson-expiry note,
the presence-marks vocabulary, and watchable conversations. **Every idea is
stated as a fact about this engine, with no provenance attached.** Keep it that
way — an idea does not need a birthplace to be worth doing.

**Why any of this matters before the first push, not after:** a push cannot be
taken back. Clones and forks keep the history even if a later commit scrubs it.
This is the same class of thing the 2026-07-30 review caught in `QUICKSTART.md`
(live staging password + origin host) — a working document written for an
audience of one, going out with the source.

---

## Known annoyances (deliberate, documented, not yet fixed)

- **Editing a launched world is live and unannounced.** The next turn uses the
  new text while every stored memory and event still refers to who they were,
  and any system-prompt edit silently discards the model's prefix cache — so the
  next few turns are slow for reasons the UI never explains. Wants: a note on
  the edit ("this changes what she is; the next answer will take longer") and a
  visible distinction between cheap edits (prose) and expensive ones.

- **`world-template.prev.json` is one step deep.** Deliberate — a deep undo
  stack in a single-user tool is a way to lose track of which world you are in —
  but a reroll spree past two steps is unrecoverable.

- **Nothing exposes "what is scheduled next."** The cast panel knows where
  everyone is *now* but not when a shift changes, so the time control cannot
  offer "skip to when the bar opens."

- **`xeric_sweep_catchup()` has no per-event callback.** Right for a cron, wrong
  for a screen; the tick worker feeds it one window at a time to stream. An
  `onEvent` callback would let the demo drop that loop.

---

- **A lesson never expires, it is only pushed out.** `engine/learn.php` caps each
  bucket at six and evicts the oldest when a seventh arrives, so a lesson learned
  in week one survives until six newer ones have replaced it — even if the habit
  it describes stopped in week two. The counters keep moving; the sentences do
  not. Wants: an age on each lesson, or a distil pass that is allowed to strike
  one out as well as add one — the alternative shape being a pass that rewrites
  the whole notebook every time instead of appending to it.

- **The inspector cannot show what a world has learned.** `why.php?h=` prints the
  exact prompt a character receives, so the lessons block is visible there by
  accident, but nothing shows the signals behind it, the per-kind weights, or one
  person's reach. "Why has she gone quiet?" is now a question with an answer that
  is not on any screen — it is `sqlite3` or nothing.

- **The demo has no kill switch for learning.** `sweep-cli.php --no-learn` and
  `xeric_lessons_distil(… ['no_model' => true])` exist; the web app has neither.
  If a distil pass ever writes something bad into a live world's prompts, the fix
  today is to edit the arc by hand.

- **A dwell is any tap.** Opening somebody's thread and closing it immediately
  counts the same as reading it, because the page never tells the server it left.
  It only feeds "who do you go to", which is the least load-bearing of the
  counters, but it is noisier than it looks.

## Small things

- Quiet hours are derived from when the user is around, but a world whose cast
  works nights (a motel, a bar) still has its *cast* asleep on the user's clock.
  Cast schedules and user quiet hours are independent and only one is derived.

---

## Review findings — 2026-07-30 multi-agent pass (61 confirmed)

Seven Opus reviewers, one per dimension, each finding independently attacked by
a second agent told to refute it. 68 raised, 61 survived. Nothing here was
fixed; nothing was pushed. Read-only pass over the tree at commit-time.
**Anything marked (v) I re-verified by hand afterwards.**

### Before anyone else touches the beta

- **The protagonist's private pull is printed to every walled viewer.** (v)
  `engine/renderers/bible.php:259` gates WHOSE STORY THIS IS on
  `xeric_hidden($walls,'protagonist')`, but no wall in the system ever hides the
  key `protagonist` — the baseline hides `cast_dossiers/drives/secrets`
  (`forge/forge.php:1777`) and the protected wall hides those plus
  `economies/mystery` (`forge.php:1828`). So the section renders for EVERY
  viewer, including an own_bible protected character and the fail-closed unknown
  viewer. Worse, the deterministic fallback at `forge/forge.php:1723` sets
  `arc` = that character's `drives.pull` **byte for byte** — the exact field the
  baseline wall exists to hide. The docblock three lines above promises "the
  DETAILS of their arc stay behind the ordinary walls like anyone else's
  interior." It does not. Found independently by three of the seven reviewers.
- **`world.php` hands any visitor any world's full template.** (v)
  `forge/web/world.php:46` slugs `?w=`, checks the file exists, and `readfile()`s
  it — no ownership check at all, while `play.php` lists every world's slug and
  the play view links "the raw template" in its own footer. That is every
  `must_not_know`, every `drives.pull`, every `knowledge_walls[].hidden`, and
  `?f=seed` on top. The panel three inches above that link deliberately prints
  only the protected character's NAME. Showing the OWNER their own file is the
  point ("your world is a file you own"); showing everyone else's is the bug.
- **The first-foreign-play fork copies the previous player's chat transcript.**
  (v) `forge/web/session.php:283` snapshots the owner's entire live `world.db`
  into the stranger's session dir — `conversations` and `messages` included — so
  their cast panel lights up with the owner's unread dots and tapping a thread
  renders the owner's own typed sentences back as "you: …". **The build log
  records this as verified**, but what was checked was that the SOURCE stayed
  byte-identical (true, and good); nobody checked what came along in the copy.
  Fork the shared PAST, not the lived state.
- **Full SSRF through the bring-your-own-key base URL.** `forge/web/boot.php:276`
  validates the endpoint with nothing but `^https?://`, so loopback, RFC1918 and
  169.254.169.254 all pass. `xeric_llm_up()` returns true for ANY HTTP response
  (a working port scanner), and when the target answers non-JSON,
  `engine/llm.php:210` puts up to 300 bytes of its body in the exception, the
  forge turns that into a progress note, and `progress.php` streams it to the
  attacker. Arbitrary GET+POST from www-data **on the shared host that also
  serves the law sites**, with response disclosure. `review.php` reroll takes the
  same object. PHP's http:// wrapper follows redirects, so a string allowlist
  would not be enough — resolve the host and reject private space.
- **Cookieless GETs evict other people's worlds off disk.**
  `forge/web/limits.php:238` — every request without a cookie mints a session
  RECORD; the per-IP cap only sets `capped_until` inside it, it never refuses
  creation. 1500 cookieless GETs make `xeric_limit_evict()` walk oldest-seen
  first and `rm` real visitors' world directories. The attacker's own sessions
  sort last and survive.
- **QUICKSTART.md is git-tracked and carries the live staging password**, the
  origin hostname and the server data paths (`QUICKSTART.md:10`;
  `git check-ignore` says NOT IGNORED). `forge/web/deploy.sh:49` leaks the
  hostname independently. Rotating afterwards does not clear git history.
- **`docs/WORLD_TEMPLATE.md` still carries the private world's real content** —
  a real person's handle, a real fixture, a real economy's hardened ground-truth
  strings and the two boon/pack keys that go with it, every one of them listed
  in the private extraction notes as an artefact that must never enter the
  public repo. The 7e71bcc scrub cleared the personal NAMES; this is the private
  world's structure, and it is still here. **The repo is not publishable until
  this and QUICKSTART are dealt with.** (Working tree cleared 2026-07-30 — the
  schema doc is now written entirely in Milldale. Git history is untouched and
  is a separate decision.)
- **Nothing enforces the hold cap or the drain flag for a build or a reroll.**
  `forge/web/worker.php:105` — `XERIC_LLM_TIMEOUT` is 600s per call and the forge
  retries once, so one pass can hold the GPU for 1200s against a
  `XERIC_QUEUE_HOLD_MAX` of 420, and a build has ~8 passes. The drain flag is
  read at the door, never mid-job, so **taking the GPU back does not stop a
  build in flight** — which is the one guarantee that command exists to make.

### Walls and privacy (beyond the protagonist leak)

- Seed memories are written with **zero wall awareness** and rendered into the
  protected character's prompt unfiltered (`forge/forge.php:1936`).
- The sweep prompt prints co-participants' private memories into the call that
  authors the **protected** character's memory (`engine/sweeps.php:852`); the
  only guard is a prose sentence.
- Spine events are not marked, so their model-written titles ("name the thing")
  replay into later ALREADY HAPPENED lists that include the protected character
  (`engine/sweeps.php:876`).
- The on-spine decision trail stores `must_not_know` **verbatim** into world_state
  and `why.php` echoes it (`engine/sweeps.php:539`) — the inspector shows you the
  secret it is protecting.
- World-bucket lessons bypass walls entirely and are distilled from edit signals
  that quote walled fields verbatim (`engine/prompt.php:128` + `learn.php:860`).
- The RIGHT NOW block consults no walls at all (`engine/prompt.php:363`), so it
  re-supplies the `cast_lines`/`schedules` the bible just removed — in the one
  block the model is told to trust over everything above it.
- `review.php` renders every wall and every interior to **non-owners**; only its
  JSON actions are ownership-gated (`forge/web/review.php:198`).
- A cast reroll clears protection but leaves `seed.json` addressed to dead
  handles, and the first-name fallback hands a departed character's seeded
  memories to whoever shares their first name (`review-lib.php:551`).
- A wall with a key but no audience and no `special_roles` reference validates
  clean and protects nobody (`engine/world.php:264`) — silent, at the exact point
  validation exists to be loud.

### Concurrency, limits, and the queue

- **Every rate limit is check-then-write with an unlocked read**
  (`limits.php:441`) — 8 parallel POSTs all pass a cap of 5. Same bug defeats the
  per-IP session cap (`limits.php:231`).
- `X-Forwarded-For` fallback takes the **leftmost** (client-supplied) value
  (`limits.php:186`) — one header and the per-address caps are unlimited.
- The reap nulls the holder record while the flock is still held
  (`queue.php:176`), so the queue says "the model is free" while nobody can get
  it.
- No per-visitor fairness: one person with 8 tabs owns the whole line and ~56
  minutes of GPU (`queue.php:212`).
- If `line.json` is not writable, `xeric_queue_edit` **silently discards every
  mutation** (`queue.php:117`) and the entire app fails with a timeout sentence.
  Reachable by running any CLI tool as the wrong uid against the data dir.
- `progress.php` sleeps 10s on any well-formed unknown job id with no rate limit
  (`progress.php:68`) — cheap worker-pool exhaustion on a shared host.
- Session records are read-modify-written with no lock across the pair
  (`session.php:190`), so a worker's world claim can be dropped by an ordinary
  page load; and the snapshot fallback can overwrite a live copy and pair it with
  a foreign `-wal` when two first-opens race (`session.php:304`).
- `worker.log` is unbounded and is the one file the disk budget does not count
  (`boot.php:218`).

### Time

- Free-text "when are you around" **inverts** quiet hours: "evenings, up until
  11" → `11:00-17:00`, so the world is quiet all afternoon and wide awake at 3am
  (`forge/forge.php:1301`). A bare hour is read as 24-hour time.
- `user.quiet_hours` is hand-editable, unvalidated, and **fails open**: `11pm-7am`
  or an en-dash paste saves clean and switches quiet hours OFF entirely
  (`engine/sweeps.php:732`, `review-lib.php:256`).
- Proactive's quiet-hours deferral is never honoured — no caller re-offers the
  deferred event, so it is dropped, not deferred (`engine/proactive.php:146`).
- Sweep events are stamped up to half a window **ahead** of the world clock
  (`engine/sweeps.php:450`) — the feed shows 20:30 while the header says 20:05.
- "Skip to morning" is a local-minute delta multiplied by 60, so it misses by an
  hour across a DST boundary (`play-lib.php:200`).
- The world-state panel prints `expected_gap_hours` as the event interval,
  overstating the quiet by ~2x (`play-lib.php:519`).

### Forge and review

- **Reroll places, then cast, and the cast reroll is permanently bricked** —
  new orbit keys vs. kept baseline walls, and `xeric_forge_repair()` does not
  touch walls (`review-lib.php:502`). Every attempt costs a model call per
  character and a rate-limit hit before it refuses.
- A detached reroll silently **overwrites every hand edit made while it runs**
  (`review-lib.php:638`).
- A character reroll never touches `seed.json` — orphaned memories, and event
  prose naming somebody who is gone (`review-lib.php:594`).
- Editing `user.motivation` never re-derives `forge.armed`
  (`review-lib.php:778`), so the systems panel states something false and the new
  motivation's kinds can never fire.
- Every hand edit rewrites `seed.json` over `seed.prev.json`, destroying the undo
  for a seed reroll (`review-lib.php:359`).
- A reroll against a dead model returns the **identical person** and still spends
  the one-step undo (`forge/forge.php:1125`).
- The BYO-key reroll UI is declared inside `reroll()`, so the ⚙ button is dead on
  load, the key is reset before every use, and listeners accumulate
  (`review.php:369`). PUNCHLIST records this as shipped; as written it does not
  work.
- Three of `company`'s disarm names are not in `XERIC_SYSTEMS`, so the elderly-user
  case FORGE.md is built around disarms nothing (`forge/forge.php:1202`).
- Four greedy `.*` patterns delete the whole validator complaint, leaving
  refusals with no reason in them (`review-lib.php:228`) — which is what makes
  the reroll deadlock above undiagnosable.
- Everything protecting `lib/` is inside `<IfModule mod_rewrite.c>` and the CLI
  entry points deployed there have no SAPI guard (`forge/web/.htaccess:9`).

### Docs and housekeeping

- `.gitignore:6`'s `!/worlds/milldale/` re-includes a live world directory — the
  moment anyone forges a world named Milldale into the repo's own `worlds/`
  (which is what the CLI path does), `git add -A` stages their real name,
  location, occupation and `owner.json` session token.
- Documented place `hours` grammar is **never read** by the sweeps — all four
  places in the milldale fixture, including a mill closed since 1998, are treated
  as always open (`engine/sweeps.php:682`).
- `one_line` and `surface` are load-bearing in the bible but absent from the
  schema doc (`WORLD_TEMPLATE.md:134`); `seed.json`'s shape including `days_ago`
  is documented nowhere (`forge/forge.php:2168`); the motivation vocabulary
  contradicts the code and three documented values silently resolve to a
  companionship world (`WORLD_TEMPLATE.md:66`); `DEMO_PLAN.md:37` documents a
  `demo/` tree and an `api.php` that do not exist.
- QUICKSTART's "everything still works?" loop runs 6 of the 7 suites it claims
  (`QUICKSTART.md:103`) — it omits `learn-test.php`, the subsystem this file
  already flags as able to write bad text into a live world's prompts. (All
  seven do pass: 45/92/60/71/77/54/123.)
- The inspector's reconstruction omits the lessons block
  (`forge/web/why-lib.php:71`), so `why.php` prints its own "this page is out of
  date" banner on **every world that has ever learned anything**, and the
  byte-exact test that guards it only ever runs against a fixture with no
  lessons, so it can never see the gap.
- `xeric_lessons_distil` is the one pass that writes before the model call
  (`engine/learn.php:634`) — a death in the 60s window double-counts every crumb.
- A memory harvest that keeps nothing never advances `extract.last_message_id`
  (`engine/chat.php:452`), so once a pair's memories start deduping, **every
  later turn pays a second model call forever** while holding the GPU slot.

### Should a stranger playing your world see its baked past?

**The question, in one line: when somebody else opens a world off the shared
shelf and gets their own copy of it, should the seeded history — the events the
forge invented to give the world a past — show up in THEIR event feed?**

**Why it is even a question.** The fix pass closed five routes that were handing
strangers things they should not have (secrets, private pulls, the protagonist's
arc, wall contents, `must_not_know`). Seed events came up in the same sweep and
LOOK like the same problem — a stranger reading content out of somebody else's
world — so an audit flagged them. But they may be the one case where it is
correct, and the code currently disagrees with itself about which it is:

- `world.php` REFUSES a stranger `?f=seed` outright, on the stated grounds that
  prose written with the secrets in hand cannot have them taken back out.
- `forge.php` (the forge result screen) now REFUSES a stranger the same seed
  events, for the same reason.
- `play.php` PRINTS them in the event feed to anyone playing a copy.

**The argument that it is fine, and why the current behaviour was left alone.**
A forked world **seeds itself from `seed.json` on first entry** — that baked past
becomes the copy's own history, in its own database, the same way it became the
original's. It is what makes a forked world worth walking into rather than a
cast of strangers with no yesterday. And `forge.php` is a different context: it
is the *forge result screen*, which belongs to whoever forged the world, not to
whoever is playing a copy of it.

**The argument that it is not fine.** Seed prose was written by a model that
could see everything, including the secrets — the same reason `world.php`
refuses it. Nobody has actually audited whether a seed event can quote a walled
fact. If one can, the feed is a leak with a nice explanation attached.

**What would settle it:** read a few forged worlds' `seed.json` against their
own `knowledge_walls` and see whether the seeded prose ever touches a walled
fact. If it never does, keep the current behaviour and write the reason down. If
it does, the seed pass needs the same wall-awareness the forge's other passes
just got, and the feed needs gating in the meantime.

**The hook already exists** — `xeric_play_events_html()` takes the ownership
flag; nothing is currently passed to it for this. **?**

### Still open after the fix pass (2026-07-30, none urgent)

- **The SSRF gate is a denylist checked when the endpoint is built, not an
  allowlist, and the resolved IP is not pinned.** A hostname that passes
  validation and then resolves to a private address on the actual fetch (DNS
  rebinding) still gets through. `boot.php`'s own docblock names this shape and
  handles only the unresolvable case. Real fix is pinning the checked IP, or an
  allowlist of known model hosts.
- **`review.php:147` calls `xeric_llm_up($endpoint, 8)` before the queue check**,
  so the reroll endpoint is an 8-second liveness oracle for arbitrary PUBLIC
  hosts, behind the reroll rate limit. Private space is refused now; this is
  what is left.
- **The drain flag is only checked between forge passes.** One pass plus its
  repair retry can carry a hold ~480s past `XERIC_QUEUE_HOLD_MAX` (420s). The
  guard hook works — its granularity is the pass, not the call.
- **The BYO-key reroll button works now, but renders only BEFORE launch**
  (`review.php:274-277`, inside `!$w['launched'] && $w['mine']`). The tuning week
  is entirely post-launch, which is exactly when it is unreachable. The fix is
  the branch, not the code.
- **The `.htaccess` denials are proven only at regex level.** They are now
  unconditional `<If>` blocks that hold without mod_rewrite — but they depend on
  `REQUEST_URI` being the DECODED path when .htaccess is evaluated. If it is the
  raw request line, `/%6Cib/x.php` walks past `(^|/)lib/`. `<FilesMatch>` is
  immune to the question because it matches the mapped filename. **Worth one
  check on staging before this is called done.**
- **Gating the protagonist's arc on `drives` may be stronger than intended.**
  Closing the leak means the privacy baseline now hides the arc from every cast
  member of every forged world, so that whole forge pass reaches only the
  narrator's bible, the sweeps' group weighting and the play panel. The cast
  *feeling the current* was the point of the section. If that is too much, the
  answer is probably a public-facing sentence the cast may see alongside a
  private arc they may not — not un-gating it.
- **Two demo-test conventions to know before editing it**: `$run` now sets
  `HTTP_ACCEPT` (so subprocess pages default to the HTML refusal branch, not
  JSON — pass `application/json` explicitly for a client), and the `review.php`
  BYO assertions are LEXICAL, pinning where the block sits rather than what it
  does, because the bug being guarded was itself purely positional.

### Left after the overlay wiring pass (2026-07-31)

The engine, the sweep, the turn, the prompt and both CLIs carry a story overlay
end to end; a mystery was walked from its inciting hour to its resolution and out
the other side, against the stub seam. What is not done:

- **There is no way to inject an overlay from the browser.** The forge's story
  pass is reachable only from code — `xeric_forge_pass_story()` drafts one and
  `xeric_forge_write_story()` puts it beside the world — and `xeric_forge_build()`
  deliberately does not run it. `build.php` was NOT wired to run it either: a
  world that arrives carrying a mystery nobody asked for is worse than one that
  arrives with none, and the three doors (model writes it / user writes it / both)
  are a wizard step, not a default. What is missing is that step plus one call.
- **~~The play view renders none of what a turn now returns.~~** Done
  (2026-07-31). The `said` line reaches the browser in the narrator's role, and
  the strip and the ending card carry live/closed. Who says it now has a
  recorded answer. What is still open around it: story movement during a SKIP
  reaches the page only through the `done` frame's `state`, which covers the
  strip and the ending but gives a beat opening mid-skip no frame of its own.
  The shape is a `k => 'story'` frame in `forge/web/tick-worker.php` beside the
  existing `event`/`ping` frames; `play.php` already has the renderer. And none
  of it is visible on the shelf, because no shipped world carries a
  `story-*.json` — that is the missing wizard step above, not a second bug.
  Two constraints for anyone editing this area: `xeric_play_state()` now returns
  `story` and `story_html`, whose rows are player-visible, so never add `truth`,
  `actually` or a piece to them; and the `story` payload `say.php` relays to the
  browser still carries the engine's `why`, which for a supported-but-unshown
  correct guess reads "right, and not yet shown". Nothing renders it, but it is
  in devtools. Filtering the relay to the fields the page draws is a one-line
  change if the contract is ever allowed to narrow.
- **Two overlays protecting the same character compose two `special_roles` onto
  one handle**, and `xeric_viewer` takes the last. The wall keys still merge so
  nothing leaks — only the audience selector is ambiguous. The real fix is a
  cross-overlay validator; `xeric_story_validate()` sees one overlay at a time.
- **The accusation reader is deliberately narrow and English-only**
  (`xeric_chat_accused`). It wants a cue, exactly one name in the sentence, and
  no negation; anything else is not heard, and a caller with a button of its own
  passes `accuse` and skips it. A player whose phrasing it misses simply says it
  again — but nothing tells them that.
- **Spill over-detection is real and will look like a bug before it looks like a
  feature.** Three of the fixture's five pieces are in `milldale.json` word for
  word, so a character quoting their own dossier secret in ordinary conversation
  spills that beat early. This is the design's stated direction (progress is not
  a safety property) and it is documented at the top of `story.php`.
- **None of it has met a live model.** Every proof above drives the stub seam.
  Two things want a real endpoint before the demo: the sweep prompt prints the
  composed story lines verbatim, which are second person inside an otherwise
  third-person dossier; and the spill detector's six-word-run match has only ever
  read text a test wrote.

### Left after the age-floor pass (2026-07-31, verified by execution)

The floor holds at both writes this app makes on its own — a proactive ping is
refused with the messages table read directly afterwards and nothing in it, and a
poisoned seed row is dropped and counted while every clean row lands. The other
half holds too: a murder seeds, a child witness nobody believes seeds, an
ordinary ping from the twelve-year-old sends, and two adults in a world that
contains a child are not made chaste by his being in it. `demo-test.php` now
carries all of that. What is NOT done:

- **Not one of the seven shipped worlds has a child in it.** Youngest across the
  whole shelf is 29 (`blackwood-creek` 41/42/52/52, `neon-lowlands` 29/29/41/42,
  and so on), and every one of them seeds with `skipped=0`. They were forged
  before `xeric_forge_age_band()`, which now puts a kid in every cast of four —
  a live forge run on 2026-07-31 produced Leo Finch, 13, on the fourth slot. So
  the shelf a visitor actually sees is not representative of what the forge now
  writes, and the half of the rule that says kids are ordinary characters is
  invisible on the demo. Re-forging the shelf is the fix; until then the age
  floor is exercised by tests and by newly forged worlds only.
- **The word list is narrower than an adversarial author, and deliberately so.**
  `xeric_sexual_text()` refuses on the plain list, or on an ACT word within
  `XERIC_SEXUAL_NEAR` of a BODY word. Two lines that walk straight past it, both
  written about a twelve-year-old and both verified to seed today: *"He remembers
  being naked in the back room and being told to keep quiet about it"* (act word,
  no body word) and *"He remembers a hand under his underwear"* (body word, no
  act word). Not widened, and the reason is the rule's other half: `naked` alone
  refuses a naked bulb and the naked eye, `underwear` alone refuses a line about
  buying a first bra, and over-restriction is the same failure in the other
  direction. The real posture is that the list is the THIRD layer — the effective
  rating ceiling and the rules block come first — and the threat it is calibrated
  for is a model writing something bad, not an author writing it on purpose in a
  file they own. If this is ever revisited, the change is a subject-aware
  threshold (stricter when the floor already knows a minor is the subject), not a
  longer list.
- **`skipped` now has two meanings and nothing distinguishes them.** A seed row
  is dropped for a dangling reference OR for the age floor, and
  `engine/chat-cli.php:106` prints the sum as "N unusable rows dropped". Nothing
  in the review layer surfaces it at all — the pointer to `review-lib.php:1104`
  raised during the pass does not check out, that line is the special-roles
  merge. If a page ever wants to say WHY a seeded memory went nowhere, the count
  has to be split at the two `$skip++` sites in `engine/seed.php`.
- **`xeric_forge_rating_lock()` lowers a `rating_min` IN PLACE rather than
  remembering it** (`forge/forge.php`, reached from `xeric_forge_age_floor()`).
  Ageing a character down across 18 and back up returns the age and does not
  return the gating: his mature-gated nodes are sfw forever. Fails closed, and
  pre-existing, but the review page is now the first place a person can trigger
  it by hand. A real fix stores the original alongside the lowered one. The
  demo-test inspector assertions use a FRESH copy of the fixture for exactly this
  reason — a world that has had anybody aged across the line has no gated node
  left to watch vanish, and every clamp assertion would pass for the wrong reason.
- **The proactive floor throws without burning the per-event guard.** A world
  whose model reliably writes something refusable for the same character on the
  same event pays for a model call every tick. Identical to the existing
  dead-model path, and the alternative — burning the guard on a refusal — lets one
  bad generation permanently silence an hour, so the trade is deliberate. Worth a
  look only if a log shows the same `proactive: refused` line repeating.
- **`engine/seed.php` now pulls `chat.php`**, and with it `prompt.php`,
  `learn.php` and `llm.php`. Every existing caller already had `chat.php` loaded,
  so nothing gained work; a future standalone seed tool would. The `require_once`
  cycle is safe only while both files stay declaration-only — top-level executable
  code in either one ends that.
- **The seed floor's handle half is only as good as `xeric_seed_resolve()`.** A
  seed event naming a child by a nickname the index cannot resolve contributes no
  handle, so the check falls through to the by-name scan over the whole minors
  list. That is the fail-safe path rather than a hole, but it is where the two
  halves of the check stop being equally strong.
- **The free-text scan runs on every string-valued editable path**, not only the
  three named ones — a place's description naming a child is the same leak. The
  corner it buys: a genuine name collision between the player's own name and a
  minor's display name would refuse sexual text in `user.motivation`. Fails
  closed, costs one sentence, and `xeric_age_mentions()` already drops generic
  words and anything under three characters.
- **The review page's age note appears only when minor-ness CHANGES.** Twelve to
  thirteen is silent, which is right — nothing about him moved — but it means the
  page says nothing reassuring after correcting a child's age.
- **A refused turn still spends one message from the visitor's hourly budget.**
  The charge happens before the model call, upstream of the floor. Defensible for
  a queue slot that was held; less so for a refusal decided before any model call.

### Refuted (recorded so they are not re-raised)

Seven claims did not survive: an orphaned build ticket (`build.php:132`),
`xeric_clock_reset` stranding the watermark (`clock.php:93`), `events.user_concern`
being read by nothing (`forge.php:1499`), the proactive tail falling back to
narrator prose (`proactive.php:356`), `board.visible_to` gating only the podium
(`economy.php:166`), and the narrator being unable to see an economy
(`walls.php:276`). One undecided: whether the dedupe pass misses place keys
embedded mid-sentence (`forge.php:975`).

---

## Done

- ~~A reroll always uses the local model even in a world forged with a key.~~
  → an optional key per reroll (2026-07-30)
- ~~Rerolling the whole cast was unrecoverable.~~ → every save keeps the copy it
  replaced; one-step undo that is itself undoable (2026-07-30)
- ~~Forged worlds shipped with no knowledge walls.~~ → the walls pass (2026-07-30)
- ~~Cast interiors duplicated across characters.~~ → assigned vocal shapes plus a
  deterministic de-duplicator (2026-07-30)

### WINDOWS (audit, 2026-08-01)

A read-only audit of everything that only works on the author's own machine.
The mechanical half is fixed (see the commit "Portability: the parts that were
never about PDFs"); what is left needs decisions, not edits.

> **✅ THE LAUNCHER SHIPPED, 2026-08-01.** `xeric.cmd` + `heart.cmd`, with every
> decision moved into `bootstrap.php` — which PHP, which extensions, which port,
> where the data lives — so both launchers ask the same question of the same
> code and only the unshareable part (backgrounding, cleanup) is written twice.
> The second blocker below is mitigated, not fixed: the stream window is settable
> and Windows gets 3 seconds instead of 40, so it hands the server back and the
> client reconnects with Last-Event-ID. It still stalls for those 3 seconds.

**BLOCKER — there is no Windows launcher.** `xeric` is bash: `set -euo pipefail`,
brace expansion, `trap`, backgrounded subshells. Everything it sets is therefore
unset on Windows — `XERIC_DATA_DIR`, `XERIC_PHP`, `PHP_CLI_SERVER_WORKERS` — and
the heartbeat never runs, so xerics never live between visits, which is the
headline claim of the whole project. The fix that avoids two launchers drifting:
move the port probe, the extension check and the env setup into a `bootstrap.php`
both call, leaving ~15 platform-specific lines each.

**BLOCKER — `php -S` is single-request on Windows.** `PHP_CLI_SERVER_WORKERS`
only exists where PHP can fork. progress.php holds an SSE stream for 40s per
connection, so on Windows the first build freezes the whole site for its window:
the progress page cannot load the page it is reporting on. Two ways out, and it
is a real choice — bundle a server (Caddy, or nginx + php-cgi) and stop using
`php -S` there, or detect the platform and fall back to polling instead of SSE
with a much shorter ceiling in say.php.

**MAJOR — Windows workers write their output nowhere.** `xeric_web_spawn()`
hardcodes a stdin-only descriptor spec; the POSIX branch appends `>> log 2>&1`
inside the command and the Windows branch never uses `$log` at all, despite the
docblock claiming output goes to the log by descriptor. Every worker fatal on
Windows is lost. Fix: return the descriptor spec from `xeric_web_spawn_cmd()` so
the one unportable thing stays in the one function built to hold it.

**MAJOR — `create_new_console` pops a console window** on every build, skip and
reroll. The comment says `bypass_shell` is what stops one appearing; the flag
beside it explicitly asks for one.

**MAJOR — unlocked reads race `flock()` on Windows, where locks are mandatory.**
`xeric_queue_state()` and `xeric_web_session_read()` both read with
`file_get_contents` while other code holds `LOCK_EX`. On Linux advisory locks
never block a reader; on Windows the read fails outright, so the queue
intermittently reports an empty line and sessions read as blank — token totals,
ownership and the affirmation all flickering off. Fix: `LOCK_SH` around both.

**MINOR** — 0775/0600 are no-ops on Windows, so the ip-salt file is not the 0600
its comment promises there; `@link()` for the fork snapshot fails on FAT32;
`rename()` over an open file fails on Windows and reports "out of room";
`--port` with no value trips `set -u`; the heart trap kills the loop rather than
its current child; and port 18080 in the scan list is the author's own ssh
tunnel and means nothing on a stranger's machine.

## After the faces / cog / live-clock pass (2026-08-01)

**Presence marks know two states.** Chips show a pin (out at a place) and a
sleep mark (unplaced at night), but the vocabulary wants three more: at-work,
plans with a start time, and the rough morning after. The week schedule already
holds enough to say all of it; the marks just do not read it yet.

**Old casts have no pronouns field.** New forges ask for it at birth and the
cog sets it by hand; between the two sits every already-forged cast, where the
shading falls back to reading self-describing prose and honestly greys anyone
it cannot place. A one-time backfill pass at launch (one model call per cast)
would close the gap.

**Watchable conversations are the biggest thing still missing.** Two characters
talk while you watch, with play/pause, walking in mid-scene, and a finish that
writes both diaries. The engine already writes offscreen events with divergent
memories; this is the live-on-screen version of the same idea.

**The cog edits the person, not their week.** Schedule changes still mean the
workbench (linked from the modal). Fine while the workbench exists; wrong the
day the play screen is the only door anybody uses.

**wpulse renders the sidebar and the cast every 12 seconds per open page.**
Free on one machine; on a shared host, eight open tabs is eight renders of the
same world. Cache by (world, epoch, unread-sum) if the demo ever feels it.

**The literary repass is a button, not a habit.** It runs when pressed on the
review page; the natural next step is one automatic pass at the end of every
forge, its findings waiting on the review screen when the maker first arrives.
And the snake half has only ever read a stub: no forged world carries a story
overlay yet, so the beat-versus-stage prompt is untested against real beats.

**Stage direction in the world.** "*neil sits at the keyboard*" — asterisk
action lines from the player should read as stage direction, not dialogue:
rendered without a bubble in the thread, handed to the model as something the
character SEES rather than hears, and reflected in the location panel the way
the cast's own "doing" lines are. Belongs to the room-scenes block; the two
should land together.

**The undo is one step deep, and the red button made that matter.** Every save
rolls exactly one .prev, so a ten-pass sweep leaves nine passes unreachable
behind the last one. Either keep N prevs (a small ring), or snapshot once
before a sweep starts and offer "put it back the way it was before the button".

**Oakhaven Bend's seed carries scar tissue from the pre-brake sweep**: the
"broken porcelain tea set" event narrates spilled juice, and two of the
memories are word-for-word twins. A seed-section reroll in the review would
regenerate the past cleanly; the owner's call.

## Add-character follow-ups (2026-08-01)
- **The compass has no mood in it.** `world_mood.axis` seeds an `arcs['']['needle']`
  and nothing in the engine ever moves it — no sweep, no chat, no proactive ping
  writes it. Until something does, a "mood" reading would be a number that is
  always 0, so the sidebar's compass prints three things that DO move (days
  lived, events, threads opened). Wire the needle into sweeps.php's event
  handling (`world_mood.drivers` already names the deltas: intimacy +1,
  conflict +2, shared_meal -2) and then the first compass point can be the
  world's own axis words instead of a day count.
- **Trust never moves either.** `arcs[handle]['trust']` is seeded and read but
  never bumped; same shape of gap as the needle.
- **A woven add cannot be undone in one step.** `xeric_review_save()` rolls one
  `.prev`, so the template is recoverable, but the arrival event and the five
  memories the weave writes into `world.db` are not. Either write them under a
  batch id or offer "remove this person" as a real operation.
- **Nobody is ever removed.** The cast ceiling in addchar.php is 12; the only way
  down from it is a reroll (replace) — there is no "they moved away".
- **`short_name` is not in the schema doc.** `docs/WORLD_TEMPLATE.md` documents
  the character record; the new optional `short_name` key belongs in it, next to
  `display_name`, with the derivation order (`short_name` → nickname inside the
  full name → first word) that `xeric_play_short_name()` implements.
- **Only the chip bar and the whoami line use short names.** The sidebar's rooms,
  the thread header and the cast rows still print the whole name, which is right
  for now — but the sidebar's "where everybody is" gets tight at twelve too.
- **Macomb was forged before the premise was read.** The world on the shelf has
  user=Sam / ensemble / no Mr. Sanders because the premise never reached the
  person or the cast. A 🎲 Draft again from its review page re-runs it with the
  same paragraph honoured. Not done — the user's call.
- **A premise can only name as many people as the cast has slots.** The reader
  returns up to six; `xeric_forge_pass_cast` briefs the first `$count` of them
  and silently drops the rest. Either grow the cast to fit the named people or
  say in the build log which names were not written.
- **Nothing re-reads the premise on a reroll of one character.** Reroll a person
  the premise named and the brief is gone — they come back as a stranger with
  the same slot.
- **Delete has no undo and no confirmation of what it took.** It removes the
  world directory, every session's fork of its database, and the shelf entry —
  and answers with counts nobody sees, because the page has already navigated
  away. A "Cold Harbour is gone — 6 files, 2 copies" line on the shelf after the
  redirect would close the loop.
- **Ownership cannot be tested on this install.** solo mode (boot.php,
  `xeric_web_solo`) pins every request to the machine identity, so there are no
  strangers here and `!$w['mine']` never fires over HTTP locally. Verified at the
  function level with XERIC_SOLO=0 instead; anything that relies on that guard
  needs the same treatment.

## Start-blank follow-ups (2026-08-02)
- **Hand-added people have no `short_name`.** `xeric_review_add_person` writes
  from the archetype table, which predates the field, so a blank world's chips
  fall back to the derived first name. Correct output, but the cog offers a
  field the adder never fills.
- ~~Blank has no goal and nothing armed, so nothing ever happens.~~ **Wired.**
  Retyping `user.motivation` re-arms the world through xeric_review_rearm, with
  a model reading it when the queue is free and the keyword table when it is
  not; clearing the box disarms everything. What is still missing is the same
  wire on the PLAY screen's cog — it saves through the same endpoint, so the
  re-arm fires, but nothing on that screen shows what changed.
- **The two peorias on the shelf are the old blank.** user "you", motivation
  "company", locale "a town of two thousand on a slow brown river" — forged
  before this change. Left alone; they are the user's.
- **The mood axis has no `ordinary` box.** `world_mood.axis.ordinary` is the
  needle's resting point and is not editable; it is also not moved by anything
  (see the compass note), so it is a number nobody can set and nothing reads.
- **Nothing removes a list item.** Themes, texture, canon rules and motifs can
  be added and retyped but not deleted — an item typed by mistake can only be
  overwritten. Deleting index N of a list means renumbering everything after it,
  which is why it is not in with the add.
