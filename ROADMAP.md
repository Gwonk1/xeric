# Roadmap

What is built, what is next, and what is honestly still missing. This is a
working document, not a promise — dates are absent on purpose.

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

Three things it will not do:

1. **Run a world that is stopped.** A paused world is paused for this too.
2. **Take the model from somebody using it.** A person waiting on a reply
   outranks a town having a Tuesday, so a tick that finds the queue busy skips
   and tries again next minute — the window is still unswept and still there.
3. **Live an unbounded gap in one tick.** A month away is ~240 model calls; a
   handful per world per tick, and a long gap fills over several minutes.

---

## Known limitations, stated plainly

These are real, they are deliberate as of today, and they are the first things
you will notice.

- **The undo is one step deep.** Deliberate — a deep undo stack in a
  single-user tool is a way to lose track of which world you are in — but a
  reroll spree past two steps is unrecoverable.

---

## Next

### The Narrator

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

**The one-call shortcut is fatal and will look right.** The obvious build asks
the model for the whole exchange at once: everybody's lines, one call, perfect
pacing, cheap. It cannot be done. Each character's prompt is *their* bible,
through *their* walls, with *their* memories; one call means one point of view,
and every wall in the world collapses into it silently. The output would read
beautifully. **N people is N calls, and that is not an optimisation to revisit.**

So the open questions are all about who and when: who answers (not everybody —
and who stays silent is information), whether the room talks without you (in a
real bar, yes, and that is the single thing that would make a room feel unlike a
chat window), and timing, since two replies landing at once is wrong.

### Death, revival, and permadeath

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

### A viewport onto the world

**Shipped: the room you are standing in, rendered by a machine you choose.**
`engine/scene.php` composes, in code, what the room looks like at this minute —
the place and its interior on photo.php's seeds, the light bucketed by hour, the
sky the cast was told, who is here and what they are wearing — and hands it to
a scene machine: a 360° panorama you drag to look around, a still, or a link to
a hosted room. With no scene machine it falls back to the image machine for a
still; with neither it does nothing, and nothing is an error. The whole of it
is in [docs/VIEWPORT.md](docs/VIEWPORT.md).

**This is the bet `where.php` made, cashed in.** The surface was always meant
to be the cheap, replaceable part and the world model the part that is not, and
a first-person view was named as a client of `xeric_travel_map()` from the day
travel was written. It turned out to need nothing from a renderer that the
engine did not already know: position, presence, the clock, the weather, the
seeds. The new code is a composer and a queue, not a second world model — and
because it reads only observables, a wall cannot leak through it by being
mis-filtered. Scenes are keyed by what the room looks like rather than the
minute, so two visits in the same light to the same company are one render.

Next, in rough order of how much they would change:

- **Streaming**, when a world model that streams can hold a room still — today
  they reinvent the booths when you turn your head.
- **Walking inside a splat.** The envelope already accepts splats and meshes;
  the play view offers them as downloads. Standing in one is a viewer, and
  walking out of its door must still be a trip that costs minutes.
- **A scene per hour, rendered by the heart overnight** — opt-in, because it
  spends money on a remote machine — so the room is already there when you
  arrive in the morning light.

---

## Design rules that are not up for grabs

- **The engine is world-agnostic.** Anything that exists only because strangers
  share a GPU lives in `forge/web/`, never in `engine/`.
- **Never write gendered prose engine-side.** Pronouns are template data. This
  has been fixed twice.
- **A hallucinated wall is a real secret told to the wrong person.** Every wall
  carries a plain-English explanation because only the person who built the
  world can catch it.
- **Fail closed.** An unresolvable viewer, an unknown wall selector, an
  unparseable rating: all of them deny rather than allow.
- **A locally-forged world is a legitimate world, not a degraded one.** Some
  people are less put-together than others, even in the real world.
