# Roadmap

What is built, what is next, and what is honestly still missing. This is a
working document, not a promise — dates are absent on purpose.

The long view — where this goes over about two years, the bets it rests on,
the milestones, and what the code actually is today as against what this file
says — is [docs/FUTURE.md](docs/FUTURE.md). Its truth table is what the
headings below are checked against, row by row; after milestone three the one
heading still out of step is story overlays, built and filed under Later.

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

### Together — what is next

M4 in [docs/FUTURE.md](docs/FUTURE.md#m4--together): two humans in the same
room with the cast. Milestone three, "A world with intent", shipped
([docs/FUTURE.md](docs/FUTURE.md#m3--a-world-with-intent)) apart from more
than one voice of weights, which is in progress. In order:

- **A body each.** Today there is one `player.where`; a position per player,
  and the other players in `who` with a flag, so the map, the view and the
  tags show them without learning anything new.
- **The room with two humans in it.** The who-answers draw treats a human
  like anyone else who just spoke; the floor can return to either; silence
  is noticed for both.
- **Threads that know who said what.** A player id on messages, so a
  character remembers which of you promised the dish — and so news, worry,
  ritual and milestone stop reading only the first player.
- **Rating per viewer.** Messages, events and scene rows stamped with a
  rating and filtered per viewer, not only clamped when generated.

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
the age floor. **Guests may read and may not speak into it.** **The book gets
a line** for each room you stood in. **No `id_slot`** per speaker is sent; the
cost of several speakers on one llama.cpp slot is to be measured first
([punchlist](docs/PUNCHLIST.md)). Next for the room is a second human in it —
two people at the same table, each through their own walls — which is M4,
"Together", in [docs/FUTURE.md](docs/FUTURE.md).

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

### Story overlays

**The story ends; the world does not.** A story is an overlay on a living world.
When the question is answered the story closes and the world keeps running, with
the same people who simply no longer have that between them. It is
multi-injection: a world can take another later, and more than one can be live
at once — campaign modules on a persistent town rather than a campaign that *is*
the town.

**A mystery is a wall structure**, which is what makes this cheap: one character
knows the truth, several hold partial truths, and the pacing decides when those
walls come down. The genuinely new mechanic is the **red herring** — walls
currently hide things that are true, and a character who sincerely believes
something false is a new field. That is what makes a mystery work rather than
unspool.

Overlays are paced against a story curve rather than escalating forever or going
slack: a steep opening, a build, a taper to a deliberate false calm, then a
crescendo down into resolution. Every knob it turns already exists.

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
- **Streaming**, when a world model that streams can hold a room still — today
  they reinvent the booths when you turn your head.
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
