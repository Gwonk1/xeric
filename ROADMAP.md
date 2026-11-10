# Roadmap

What is built, what is next, and what is honestly still missing. This is a
working document, not a promise — dates are absent on purpose.

The long view — where this goes over about two years, the bets it rests on,
the milestones, and what the code actually is today as against what this file
says — is [docs/FUTURE.md](docs/FUTURE.md). Its truth table is what the
headings below are checked against, row by row; with all five milestones
shipped, the one heading still out of step is story overlays, built and filed
under Later.

---

## The heart — the world running when nobody is looking

`forge/web/heart.php`, looped by `./xeric` every 60 seconds, or by cron, or by
systemd. **One pass and exit, not a long-lived process:** a thing that does one
job and dies is crash-safe by construction and testable by running it once.

Before it existed, only the skip button and the CLI could make a world live
through an hour. The clock ran — world time is real time plus an offset — but
nothing happened in the time it passed, so a week away left the date moved and
the week empty. That is not a delay, it is a deletion, and it meant the headline
claim of the project was being delivered by a button.

It is safe to run every minute because a sweep is idempotent per window: the
marker is written whether an hour produced an event or was skipped, so a tick
that finds no new window does no work and costs no tokens. Nothing has to
remember when it last ran.

Four things it will not do:

1. **Run a world that is stopped.** A paused world is paused for this too.
2. **Take the model from somebody using it.** A person waiting on a reply
   outranks a town having a Tuesday, so a tick that finds the queue busy skips
   and tries again next minute — the window is still unswept and still there.
3. **Live an unbounded gap in one tick.** A month away is ~240 model calls; a
   handful per world per tick, and a long gap fills over several minutes.
4. **Spend a metered scene machine nobody asked it to.** It may draw a room
   before anybody looks, at most two per world per tick. By default it does
   that only on the owner's own electricity; on a machine that bills it draws
   nothing until the template says `media.scene.prerender` out loud. A glance
   is consent to draw a room; a heartbeat is consent to nothing.

---

## The room — three-way conversation

`engine/room.php`. Three to five characters hold one conversation while you
watch, and the law it was designed around survived contact with the build:
**the one-call shortcut is fatal and will look right.** One call, five voices,
perfect overlap, a fifth of the price — and every wall in the world collapsing
silently into a single point of view. So every spoken line is its own model
call from that speaker's own assembly, and the only thing that crosses between
heads is what was actually SAID, carried as labeled turns. That is not an
optimisation to revisit.

The questions that were open are answered in code, and inspectably:

- **Who answers.** A weighted draw per beat. Being addressed by name outranks
  everything — a question with your name on it is not a suggestion — then
  whoever holds the most unsettled material about the last speaker, resolved
  as a weighted shuffle rather than a ranking, because the hottest person
  taking every beat is how a room becomes one character and some furniture.
  Every beat's candidate weights are written into the why-trail.
- **Who stays silent.** Silence is a seat at the table, and the engine counts
  it: a long enough streak of being present-and-quiet licenses the next
  speaker to notice it — once, and never anything stronger. The quiet are
  never forced to speak, never handed a turn they did not draw, and never
  spoken for.
- **Who leaves.** A scene takes time and schedules do not pause for it:
  presence is re-read as the scene clock advances, and anyone the schedule
  moves out of the room departs as a stage direction in observables only —
  everyone saw them go; where they went may be behind a wall. Fewer than two
  left ends the scene, because a room of one is somebody standing in a room.

`room-cli.php` is the acceptance surface. Nobody arrives mid-scene: who may
join a room is the Director's question, below.

## Death, revival, and permadeath

`engine/death.php`, with `forge/web/fate.php` as the web surface. **Death is a state,
not a deletion.** A dead person stays in the template, stays resolvable by
handle, stays in every memory that names them and every wall they were
behind; what changes is one row in the world's database saying they are gone.
All three modes ship: a death the engine can undo, a catastrophe big enough
to end a whole world and be restored from, and **permanent** — the engine
really will not bring them back.

Permanent is frozen into the database at the first death — editable until it
has cost somebody, locked after — and an unreadable setting resolves to
revivable, the one dial in the engine that fails toward the recoverable
state, because deleting a world's cast for good over a typo is not caution.
And it is not DRM: the world is a file its owner owns, and anybody with a
text editor can resurrect anybody, forever. What `permanent` means is that
THE ENGINE will not do it — no button, no command, no endpoint — which is
enough, because the stakes were always enforced by the author having decided,
never by the software.

**Revival is not a rewind.** The death still happened, the events still
happened, and everyone who watched still remembers. People who saw somebody
die and then see them at the diner is not a bug to paper over; it is the best
scene this engine can produce. This also retroactively unlocked story
overlays that kill: an overlay can now end a cast member without editing the
template, which is the one thing an overlay may not do, kept.

## Social constructs — expectations, and gossip through the walls

`engine/constructs.php`. The reusable shape held exactly as written: *trigger
→ a small arc with epochs → renders as coarse state, never a timer → licenses
a sweep or a proactive line → residue becomes an ordinary memory.* No
construct ever renders a countdown — a timer would change every message and
bust the one prefix cache the engine is disciplined around.

The first construct is **expectations**. One forms when a character hears the
user commit with no weasel words and a real when — the model proposes the
quote, but the gate is code, run on the verbatim words, because a linguistic
rule enforced by instruction is a hope and enforced by regex is a fact. A
miss lands in three places: trust, one notch, silently; the body, as a
licensed stage-direction line, never narrated feelings; and the record, as an
event written in observables only. An explanation inside the repair window is
a real move and returns the notch — how well it lands is the person's, not
the system's. Fuses burn in world time, skips included. v1 scope: they point
at the user, who is the only one who can let anybody down.

The second is the **gossip ripple**, and it runs through the walls, or it
would be a leak engine with a folksy name. What travels is what was SEEN —
the event's own commons words, coarsened in code, never enriched — one hop at
a time between people who actually share a place and an hour. No model, no
teleporting news. Attribution thins as it travels, a hop past third-hand has
nothing left to pass on, old news dies in six world-days, and a wall blocks
the hop, so the ripple flows around the protected, never through.

## The player has a body

`engine/travel.php`. The advice this file used to give held: start with
travel time, not the map. A trip advances the world clock by the minutes it
takes, on the same offset the time control writes — which is what turns
schedule data the engine has had since day one into a puzzle: *she is off at
two and I cannot get there by two.* `null` is a real position — being nowhere
on the map is the same state as a character being off shift, and prints the
same way. You may walk to a closed place and find the chain still on the
gate, because refusing the trip would be a menu telling you what the world
would have contained. Unknown geometry costs the default, never zero, so a
world forged before coordinates existed is flat, not free. And distance never
gates the phone: the phone is the product and it works from anywhere.

## Story overlays

**The story ends; the world does not — and this ships.**
[docs/STORY.md](docs/STORY.md) is the schema, `engine/story.php` validates,
composes and paces it, and the forge has a story door: describe the story you
want in your own words and it lands as a legal overlay, or hand-write the
JSON, or reshape an existing one — which writes a new file, because moving
walls under a running world has no sensible answer. A story is a sidecar
beside the template, composed on top at load and never written into it. It is
multi-injection: a world can take another later, and more than one can be
live at once — campaign modules on a persistent town rather than a campaign
that *is* the town.

**A mystery is a wall structure**, which is what made this cheap: one
character knows the truth, several hold partial truths, and the pacing
decides when those walls come down. The genuinely new mechanic — the **red
herring**, a character who sincerely believes something false — shipped as
its own field, with what was actually going on stored where no prompt ever
reads it. Overlays are paced against a story curve rather than escalating
forever or going slack — a steep opening, a build, a taper to a deliberate
false calm, then a crescendo down into resolution — and an overlay that
carries no curve of its own inherits the world's.

## The Narrator — three of its four powers

`engine/narrator.php`, with `narrator-cli.php` as the author's console. The
other god of the world: full canon, no walls, the only voice you can ask
*why*. **Ask** answers questions about the world and its history and writes
nothing — asking must be exactly as safe as reading the files.
**Investigate** audits the lived record for the failures that accumulate
quietly: dropped threads, absent characters, contradictions. **Write ahead**
records an intended beat the sweeps then lean toward — intent as a pull,
never a script, written as one shape of row that no prompt ever reads
directly. The play view's hint lane speaks in the narrator's voice; the
console is where investigate and write-ahead live today.

**The narrator has secrets too**, and the discretion shipped as two tiers.
The authorial answers — a story's truth, a herring's *actually*, an unspilled
beat — are never assembled into its prompt at all, because
instruction-following is probabilistic and absence is arithmetic: a model
that never read the secret cannot say it. In-world hidden facts are present
under stated discretion — point at the door, never open it — because
answering at the right altitude requires knowing the secret exists. Straight
about the machine, discreet about the story; refusals in voice, never as a
system message.

---

## Known limitations, stated plainly

These are real, they are deliberate as of today, and they are the first things
you will notice.

- **The undo is one step deep.** Deliberate — a deep undo stack in a
  single-user tool is a way to lose track of which world you are in — but a
  reroll spree past two steps is unrecoverable.
- **Three promises the constructs still break**, written down in
  [docs/CONSTRUCTS.md](docs/CONSTRUCTS.md) and filed in
  [docs/PUNCHLIST.md](docs/PUNCHLIST.md): a promise for a bare day ("I'll be
  there Thursday") is due at that day's midnight and misses at 04:00; a
  drop-in visit ignores the hours of the workplace it drops in on; and Theo
  can still be dealt into the Thursday game.

---

## Next

### What is left is what only the outside world can supply

The five milestones of [docs/FUTURE.md](docs/FUTURE.md) are built. Every one
of them has been run against fakes, stubs, headless Chrome and real processes
on loopback — and none of them against the things a test cannot fake. That is
what is next, and none of it is code:

- **A device.** A real headset (Quest, Android XR, Vision Pro) worn in the
  diner; two real phones in the same room at once.
- **Time.** A world left alone for a month with the Director on, to see an
  encounter it staged land as an encounter and not as a coincidence.
- **A model that draws.** A real local world model behind the scaffold's one
  function, and a hosted one behind the same adapter — Genie 4's API and the
  hosted streamers have not been exercised, and every vendor field in
  `tools/proxies/` is still marked *verify*.
- **A person.** Somebody standing in the diner, shown a panorama, a splat and
  a stream of the same moment, asked which machine drew it.

And the code that is still owed, from [docs/PUNCHLIST.md](docs/PUNCHLIST.md):
the constructs' three broken promises (above); memories harvested from adult
lines carry no rating stamp; one table talks at a time in a world; the walk
inside a splat has no headset mode; SPZ and SOG splats are still downloads.

### When the world models hold still

**Shipped** — M5 in [docs/FUTURE.md](docs/FUTURE.md#m5--when-the-world-models-hold-still).
The room as a box (`engine/layout.php`: a blockout, a depth map and a
segmentation map, in pure PHP, never in the fingerprint), riding every look;
a live view (`engine/stream.php`: off by default, started only by the owner's
**go live**, refused on a machine that bills unless the template says
`remote: true`, metered by the second, told the engine's events and never the
player's words); and the engine-driven loop as a scaffold
(`tools/proxies/worldmodel.py`: Xeric's state, a blockout drawing it, a real
local model at one function). A world keeps one look per kind per moment, so a
panorama and a splat of the same room sit side by side; the people's tags sit
at their bearings over every view. The bet test sends one brief to a panorama
server, a splat machine and the scaffold, live, and the three receive the same
bytes; Xeric has no branch for any of them. **No real world model has drawn
it yet**, and nobody has looked at the three and tried to tell them apart.

### Together

**Shipped** — M4 in [docs/FUTURE.md](docs/FUTURE.md#m4--together), the whole
of it in [docs/MULTIPLAYER.md](docs/MULTIPLAYER.md). A body each (a position
per player; the others in the map, the look and the sound, never in the
cast's presence); threads that know who said what (a player on every user
row, a promise kept or missed by whoever made it); rating per viewer (every
row stamped, a kids' headset reads gaps); and the room with two humans in it.
**Untested on two real phones**: two sessions on one world are exercised
in-process and through real page requests, never two devices in a browser.
What it leaves open is in [docs/PUNCHLIST.md](docs/PUNCHLIST.md), "Open — left
by M4".

### The Narrator

**Shipped**, except author. Ask is the play view's *ask the narrator*
(`forge/web/play-narrator.php` over `xeric_narrator_ask`): full canon, no
walls, the discretion rules below in force, a refusal in voice, owner only,
one model call metered as a message, each exchange kept on the narrator's own
thread. Write ahead steers: every sweep window reads `xeric_narrator_leans`
into `xeric_sweep_choose` as a ×2 lean — handles and a kind key, never words —
and the hour that realizes a beat marks it delivered and says so on its
trail. Investigate is the inspector's `why.php?audit=1`: the arithmetic free
on a GET, the read of the threads a metered POST. **Author is not built**, on
purpose: `xeric_narrator_author_refuses` writes nothing, because "one undo
away" means nothing while the undo is one step deep. See
[docs/NARRATOR.md](docs/NARRATOR.md).

The other god of the world: full canon, no walls, the only voice you can ask
*why*. Four powers, in increasing order of risk — **ask** (read-only, worth
building on its own), **investigate** (audits for dropped threads, absent
characters, contradictions), **write ahead** (intended beats that the sweeps
bias toward — intent as a pull, never a script), and **author** (validated,
undoable world changes).

**The narrator has secrets too.** Full knowledge is not full disclosure. It
never unravels a mystery, never says where something hidden is, never spoils its
own write-ahead beats, and does not hand over a character's secret just because
it knows it. Straight about the machine, discreet about the story; refusals in
voice, never as a system message.

**The Director** is its counterweight. Everything else in the engine pushes
toward autonomy, and free will left alone produces drift — everyone doing their
own plausible thing forever. The Director **changes the set, never the
performance**: it can put two people in the same room, it cannot decide what
they say there.

**Shipped, and opt-in.** `engine/director.php` stages two or three people at
one place for at most two hours, on any of three rules a template lists in
`director.rules` — `narrator` (a live intent that names two or more people),
`apart` (a pair of one orbit with no hour together for `apart_days`) and
`player` (two people whose trust in you is pulling apart) — at most
`per_day` a world-day. It never moves the dead, never pulls anybody off an
unmissable shift, never puts a minor in a room that pours, and moves nobody
if fewer than two can come. The heart ticks it once a world-hour; the play
view and the map see a staging through the same presence read as everything
else; the inspector says why each one happened and the owner can cancel one.
With no rules it writes nothing at all, and the benchmark runs that way, so
a staged encounter has been tested in `director-test.php` and not yet in a
world left alone for a month. See [docs/DIRECTOR.md](docs/DIRECTOR.md).

### Giving the player a body

**Shipped.** `engine/travel.php` gave the player the missing field —
`xeric_player_where`, moved by `xeric_travel_go` — and `where.php` serves the
town as data for every client built on it. It started with travel time, not
the map, because a map you click that teleports you is a menu with a nicer
background: a trip burns its minutes on the world clock, so schedule data the
engine has had since day one became a puzzle — *she is off at two and I cannot
get there by two.* `null` stayed a real position, printed the way a character
off shift is.

### The room — three-way conversation

**Shipped.** Stand where two or more of the cast are and you can talk to the
room: `forge/web/room.php` takes the line, `room-worker.php` runs the beats,
and `engine/room.php`'s `xeric_room_step` decides who answers. **N people is
still N calls** — every line is its own model call from that speaker's own
assembly through that speaker's own walls, and what crosses between heads is
what was said, as labelled turns, and nothing else. A name in the line answers
first; otherwise reach times heat, where heat is what each person is holding
about you. Who stays silent is recorded, because silence is information, and
the inspector shows every beat's weights, roll and dropped lines. Pacing is a
floor, never added on top of slow generation: the worker takes the model for
one call and gives it back before each line's pause, a line written after you
spoke again is dropped and noted, and a person waiting on a reply outranks a
room talking to itself. The people in the view light up as they talk, and who
came and went is a sentence in the view — inside a live room, the narrator's
row, so the two never double up.

The open questions were settled like this. **Lines land one at a time, as
message rows** in a conversation of kind `room`, so the thread, the book, the
speaker button and the inspector work on them unchanged (the CLI's
`xeric_room()` keeps watch's nothing-until-close rule, and a test pins it).
**The room carries on without you by default, with caps**: two beats in a run,
six in a session, a halving roll after each, never on a hosted key unless
`cast.room.carry_remote` says so, and `cast.room.carry: false` turns it off.
**A player line costs one message** of the hour's cap, however many answer.
**The heart may seal** an idle room before it sweeps — spending the diary
calls a close needs — and leaves your table out of the sweep. **Adults who
arrive mid-scene are seated**, hearing from the row that saw them come in; a
child walking in seals the room, because the ceiling cannot be re-folded
mid-scene. **Six or more present: the five with most reach and heat are
seated** and the rest are at the far table, still counted for the walls and
the age floor. **Guests with a seat in the roster sit and speak; a guest
without one may read and may not speak into it.** **The book gets
a line** for each room you stood in. **No `id_slot`** per speaker is sent; the
cost of several speakers on one llama.cpp slot is to be measured first
([punchlist](docs/PUNCHLIST.md)). **Two humans at one table** (M4): either
sits by speaking, the floor goes to whichever was named or spoke last, each
one's silence is noticed once, one walking out ("Ann heads out.") leaves the
other at the table and the last one out ends it, a lower-rated seat folds the
ceiling down for everybody, and each line and each turn is charged to the
session that caused it ([docs/MULTIPLAYER.md](docs/MULTIPLAYER.md)).

### Stand in it

**Shipped**, and **untested on a real headset**: everything below has run
against fakes in Node and in headless Chrome, and nobody has yet worn one.
`forge/web/stand.php` is a page that is nothing but the room, a client of
the [client API](docs/API.md) and nothing else, so its service worker can
keep it for when the box is out of reach. A press on a name turns to them; a
hold talks to them, or to the room when two or more are there; in a headset
the names are quads at their bearings, the nearest within 7° of a
controller's or a hand's ray wins, what was heard is a caption with a cancel
before it is said, and the answer comes back from where the speaker stands.
A device is linked with a code from `link.php`; off a secure context the page
says so in one line and offers neither the headset nor the microphone. See
[docs/STAND.md](docs/STAND.md). Beside it: **walking inside a splat**, in
our own WebGL (`xeric-splat.js`, a depth sort in a worker from our own
origin, no WebAssembly; PLY and `.splat`, with SPZ and SOG still downloads),
three metres from the door because leaving the room is a trip; **the drawn
map**, the town as SVG above the where list, every mark the list's own
`go()`; and **a budget per world-day** — tokens, renders, clips, hearings —
that the heart, the reapers, the look button, the voices, the ears and the
room read before they spend, off until the template sets a ceiling
([docs/BUDGET.md](docs/BUDGET.md)).

### Death, revival, and permadeath

**Shipped.** Revivable and permanent deaths, a catastrophe that ends a world
and the restore that undoes it, and stories that may kill — `forge/web/fate.php`
over the engine's death state.

**Death is a state, not a deletion.** A dead person stays in the template, stays
resolvable by handle, stays in every memory that names them and every wall they
were behind. Delete them instead and you break everything pointing at them — and
you lose the only thing death is *for*, which is that the rest of the cast goes
on remembering somebody who is not there.

Three modes: a mark you can revive from, something big enough to end a whole
world and be revived from, and permanent — they really die.

**This retroactively unlocks story overlays.** A story could not previously kill
a cast member, because that would mean editing the template when a story is
injected and putting it back when it closes — the one thing an overlay may not
do. That constraint dissolves the moment death is a row in the database.

### Social constructs — relationships need obligations

**Shipped.** Expectations with repair and a `kept` state, debts, gossip that
runs through the walls, guest vouching — and, in milestone three, confidences
formed and not only broken: an explicit ask to keep something, checked in
code against what was actually said, becomes a wall the player made, and
repeating it to somebody who must not hear it costs three notches, mended by
owning up inside a week, hardened into a grudge otherwise. The six siblings
— anticipation, news, worry, grudge, ritual, milestone — each have a name
the engine can arm, a coarse line in an ON YOUR MIND block and one line in
the inspector; and promises between townspeople are read out of the sweep's
own quoted speech and kept or missed by their own schedules. Still open,
in [docs/CONSTRUCTS.md](docs/CONSTRUCTS.md): a bare-day promise is due at
its midnight and misses at 04:00; a drop-in ignores the workplace's hours;
and Theo can be dealt into the Thursday game.

A relationship that cannot be let down cannot be kept either. Today the cast has
history; it has no **expectations**. The seed idea: a person expects you, it
fades over 24–72 hours, and missing it is a real event.

The reusable shape is: *trigger → a small arc with epochs → renders as coarse
state, never a timer → licenses a sweep or a proactive line → residue becomes an
ordinary memory.* Siblings sorted by fuse length: anticipation, news-to-tell,
worry, felt debt, repair windows, grudges, gossip ripple (**which must run
through the walls or it is a leak engine**), rituals, confidences (a
user-created wall — betrayal is the highest-stakes event available), vouching,
milestones.

Each needs a name the engine can switch on, so an open-ended motivation can arm
it, and each needs an inspector answer — a grudge that cannot explain itself
reads as the model being moody.

### Sleep — a long memory that gets shorter

**Shipped.** A character who has lived for months has thousands of memories
and a prompt that carries twelve. `engine/sleep.php`, once a world-night per
person, offers memories older than a fortnight to the model as one call and
keeps what comes back as **facts** only when code can trace every name,
number and most words to their sources, only when a fact is shorter than
what it replaces, and only without a six-word quotation of any wall; a fact
inherits the strictest wall of its sources. Nothing is deleted — sources
are marked and stop rendering, the chain is kept — and the WHAT YOU KNOW
block shares the memory cap, so a prompt gets shorter as a world ages. The
bible is never touched. Budgeted, local-only unless the template says
`sleep.remote`, and measured by five `sleep` probes. See
[docs/SLEEP.md](docs/SLEEP.md).

---

## Later

### A world can start in 1873

**Already works.** `setting.starts` takes any date PHP can read and is applied
once at launch. Presence resolves, schedules run, place hours open and close.
It is more period-accurate than anyone asked for: an 1873 world in New York
comes back at −04:56, which is local mean time, because standard time zones did
not reach the United States until 1883 and tzdata knows. Nothing did that on
purpose — it fell out of using real datetimes instead of an hour counter.

### More than one model at once

The first half shipped: the forge page lists every machine it can find and you
choose which one builds a world, with each card naming what is actually
answering there.

**The interesting version is not load balancing.** Two models answering
alternate requests is a performance feature, and there is no performance
problem. What "mix things up" means is that **different models write
differently**, and a world where every voice comes from one set of weights
inherits that model's habits wholesale. The trap is the prefix cache: it is
per-model, so spreading one world's turns across machines multiplies its cold
starts.

**Shipped, the second half.** A character can be pinned to a machine, and the
pin now reaches everything they say: a thread, a duet, both rooms, and the hours
the heart and the skip live without you — each hour written by the machine
pinned to its centre. The cold-start trap is met with llama.cpp's slots: every
speaker gets a stable slot on a machine that has more than one, so their
system prompt stays warm however their hours interleave with other people's,
and nothing else is ever sent one. A pinned machine that is down falls back to
the world's default for that hour, and the trail says whose was not answering.

### A viewport onto the world

**Shipped: the room you are standing in, rendered by a machine you choose.**
`engine/scene.php` composes, in code, what the room looks like at this minute —
the place and its interior on photo.php's seeds, the light bucketed by hour, the
sky the cast was told, who is here and what they are wearing — and hands it to
a scene machine: a 360° panorama you drag to look around, a still, or a link to
a hosted room. With no scene machine it falls back to the image machine for a
still; with neither it does nothing, and nothing is an error. The people in it
are name tags at bearings the engine derives, so a panorama and a headset agree
about where Dot stands; each render takes the last picture of the room as a
reference, which is the answer to drift; the room has a sound (texture, never
words, a headcount and never a name); it can be seen through its day; a headset
can step inside it. The heart draws ahead — the player's own room by default on
a local machine, nothing on a metered one unless the template asks. The whole
of it is in [docs/VIEWPORT.md](docs/VIEWPORT.md).

**This is the bet `where.php` made, cashed in.** The surface was always meant
to be the cheap, replaceable part and the world model the part that is not, and
a first-person view was named as a client of `xeric_travel_map()` from the day
travel was written. It turned out to need nothing from a renderer that the
engine did not already know: position, presence, the clock, the weather, the
seeds. The new code is a composer and a queue, not a second world model — and
because it reads only observables, a wall cannot leak through it by being
mis-filtered. Scenes are keyed by what the room looks like rather than the
minute, so two visits in the same light to the same company are one render.

**Shipped beside it: voices.** `engine/voice.php` gives every person a stable
spoken voice on any machine that answers `POST /v1/audio/speech`, and speaks
exactly what the thread shows, without the stage directions. The voice is
dealt from the handle's seed by rendezvous hashing, so adding a voice to the
machine moves only the people who draw it. It is filtered by the world's
language and never by pronouns or gender labels, because a voice picked by
gender is the no-gendered-prose rule broken in audio. Clips are cached by what
they sound like; the key is never stored. See [docs/VOICE.md](docs/VOICE.md).

Next:

- ~~**The room conversation, in the view.**~~ Shipped — the view's door is
  *talk to the room* ([above](#the-room--three-way-conversation)).
- ~~**Streaming**~~ Shipped, off by default and metered by the second
  ([above](#when-the-world-models-hold-still)) — the stored panorama is still
  the room; a live view is a while of watching it move.
- ~~**Walking inside a splat.**~~ Shipped — our own WebGL viewer, three
  metres from the door ([above](#stand-in-it)); meshes are still downloads.

---

## Design rules that are not up for grabs

- **The engine is world-agnostic.** Anything that exists only because strangers
  share a GPU lives in `forge/web/`, never in `engine/`.
- **Never write gendered prose engine-side.** Pronouns are template data. This
  has been fixed three times; the third time added a scan in render-test that
  fails on a gendered pronoun in any engine string literal.
- **A hallucinated wall is a real secret told to the wrong person.** Every wall
  carries a plain-English explanation because only the person who built the
  world can catch it.
- **Fail closed.** An unresolvable viewer, an unknown wall selector, an
  unparseable rating: all of them deny rather than allow.
- **A locally-forged world is a legitimate world, not a degraded one.** Some
  people are less put-together than others, even in the real world.
