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

---

## Next

### The Narrator's fourth power, and the Director

**Author** — validated, undoable world changes — is the power still missing,
and the riskiest of the four. With it belongs a fuller web surface: the play
view can ask, but investigation reports and write-ahead beats live in a
terminal today, and they should not have to.

**The Director** is the narrator's counterweight. Everything else in the
engine pushes toward autonomy, and free will left alone produces drift —
everyone doing their own plausible thing forever. The Director **changes the
set, never the performance**: it can put two people in the same room, it
cannot decide what they say there. Who may join a running scene is its
question too — the room deliberately seats no newcomers on its own.

### Cast-to-cast expectations

Expectations today point only at the user: characters can be let down only by
the person at the center. The construct shape was built to be reused between
characters, fed by sweeps instead of chat, and the room already reads the
field for it through a tested, dormant seam — so the day it lights up is not
the day it is discovered broken. Anticipation, news-to-tell, worry, felt
debt, repair windows, grudges, rituals, confidences, vouching, milestones:
the siblings sorted by fuse length are all still ahead, and each needs an
inspector answer, because a grudge that cannot explain itself reads as the
model being moody.

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

### A viewport onto the world

Much further out: something to look at. The engine already carries the fields
for identity-stable character portraits. No pipeline ships in this tree.

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
