# The Literary Overlay — a story that ends, laid over a world that does not

Forever mode was never the thing to replace. A world is a place with people in
it and a Tuesday evening whether anybody logs in or not; that is the product,
and a story that consumed it would be a worse product with a better trailer.

So a story is an **overlay**. It sits on top of a living world, points the
machinery that world already has at a plot, and then **ends** — and the world
underneath is exactly the world it was. The same six people, the same shifts,
the same diner that closes at two. What has changed is that there is no longer
an open question between any of them. You can inject another one. You can have
two running at once. Think campaign modules on a persistent town rather than a
campaign that *is* the town.

Everything below is designed against one constraint, and if a decision here
looks strange it is probably this constraint doing the work:

> **Closing a story must be a subtraction, not an edit.**

An overlay never writes into `world-template.json`. It is composed on top at
load time. Closing it means no longer composing it, and the template the engine
assembles prompts from is then byte-identical to what it was before the story
arrived. That is a testable claim, and it should be the first test written:
compose, resolve, compose again, assert `===` against the untouched template.
Every other rule in this document exists to keep that assertion true.

The residue a story leaves behind is in **memory** — the culprit remembers the
Tuesday it came out, the child remembers being believed — because memory is
where a lived past belongs and because the engine already treats a seeded past
and a lived past as the same thing. Nothing goes back into the world file.

---

## Where an overlay lives

Beside the template, the way `seed.json` does. The forge already writes two
files into a world directory; a world with stories has more:

```
worlds/milldale/
  world-template.json          the world
  seed.json                    the past it arrived with, applied once
  story-mill_stairwell.json    an overlay
  story-the_amulet.json        another
  world.db                     everything that has happened since
```

One file per overlay, named `story-<key>.json`, discovered by glob and loaded in
filename order. The key inside the file is authoritative and namespaces
everything the overlay creates: its wall keys are `story.<key>.<name>`, its
state lives in arcs under `story:<key>:…`. Two overlays cannot collide because
neither can write an unprefixed name.

**Several live at once, and they compose in one direction only.** An overlay may
*add* walls, protections, secrets and beliefs. It may never remove one that the
template or another overlay put up. Walls only accumulate. That makes multiple
overlays order-independent in the only respect where order would be dangerous —
nobody can be handed something by story B that story A was keeping from them —
and it means the composition function needs no conflict resolution at all.

State is in the database, never in the file. The overlay is content; how far
through it you are is world data:

```
arcs, handle = ''  (xeric_arc_world())
  story:<key>:opened            how many beats have opened
  story:<key>:beat:<beat>       locked | open | spilled
  story:<key>:opened_at:<beat>  world epoch the beat opened
  story:<key>:herring:<key>     live | collapsed
  story:<key>:wrong             wrong accusations so far
  story:<key>:closed            world epoch it resolved, absent while live
```

One `xeric_arcs_prefixed($db, '', 'story:<key>:')` reads the whole machine.

---

## The overlay object

```jsonc
{
  "story_version": 1,
  "key": "mill_stairwell",              // lowercase slug; namespaces everything
  "for_world": "Milldale",              // the meta.name it was written for. A
                                        // mismatch is a WARNING, not a refusal —
                                        // portability is a feature, and the
                                        // handle checks below are the real gate
  "title": "What Happened on the Fourth-Floor Landing",
  "logline": "Ellis Chandler came home to sell the mill and went down the stairwell instead.",
                                        // the ONE string a player may be shown
                                        // before the story resolves. Commons
                                        // text, same discipline as one_line
  "kind": "mystery",                    // a label for the shelf and the forge;
                                        // the engine reads `resolution`
  "rating_min": "sfw",                  // a FLOOR on visibility, never a raise:
                                        // an overlay above the world's effective
                                        // rating simply does not compose
  "source": "authored",                 // authored | model | reshaped

  "truth": "…",                         // narrator-only, one sentence: what
                                        // actually happened. Never composed into
                                        // anybody's prompt

  "cast": {
    "victim":  { "name": "Ellis Chandler", "age": 74, "one_line": "…", "found": "…" },
    "culprit": "harlan",                // a declared character handle
    "protect": [ { "character": "pastor_dale", "role": "unaware",
                   "must_not_know": "who was on the landing at the mill",
                   "wall": "story.mill_stairwell.dale_unaware" } ]
  },

  "walls":        [ … ],                // knowledge_walls, namespaced
  "beats":        [ … ],                // the spine
  "red_herrings": [ … ],                // the genuinely new mechanic
  "snake":        { … },                // pacing
  "resolution":   { … },                // what "solved" means
  "on_close":     { … }                 // the residue
}
```

A **victim** is declared inside the overlay and nowhere else. The alternative —
killing a cast member — would mean deleting somebody from `world-template.json`
when the story is injected and putting them back when it closes, which is the
one thing an overlay may not do. He gets a name, an integer age and a one-line:
enough for six people to talk about him, and no dossier, because he does not
speak. The age is required for the same reason it is required on a character —
`xeric_is_minor()` reads age and nothing else, and answers "minor" to anything
it cannot read.

---

## THE PLOT SNAKE

The shape, as an English professor drew it on an x/y graph: start at the axis, a
quick steep rise on **both** axes, build, taper off, **back down to about
halfway**, hold there, then a big crescendo and down to resolution.

The halfway part is the whole reason this is data and not vibes. Every
generative system gets that stretch wrong in one of two ways: it escalates
forever, or it goes slack. Halfway is neither. It is the world **at exactly its
own ordinary pace** — which is why the modulation below is written so that an
intensity of 0.5 multiplies by 1.0. The false calm is not the story turning
down; it is the story letting go for a while, and the town carrying on the way
it would have if nobody had died.

```jsonc
"snake": {
  "curve": [                            // [progress, intensity], both 0..1,
    [0.00, 0.00],                       // progress strictly increasing
    [0.08, 0.55],                       // the steep rise, and it is steep
    [0.35, 0.80],                       // the build
    [0.50, 0.50],                       // the taper, down to halfway
    [0.72, 0.50],                       // and HELD there. The flat is the point
    [0.92, 1.00],                       // the crescendo
    [1.00, 0.15]                        // resolution — not zero. The world was
  ],                                    // never at zero and does not end at it
  "false_calm": [0.50, 0.72],           // no beat may open in this window
  "pace_swing": 0.60,
  "kind_thumb": { "opening": {…}, "rising": {…}, … }
}
```

Intensity between control points is linear. Curves are for renderers; this is
read by arithmetic and by humans editing a JSON file, and a piecewise line is
legible to both.

**The `false_calm` window and the flat of the curve are the same two numbers on
purpose.** They could differ, and then there would be a stretch that was named
calm while the pace was still coming down, and the two would drift the first
time somebody edited one of them. Validate that the curve is flat at 0.50 across
the declared window and the ×1.0 claim below stays arithmetic rather than
aspiration.

### What it modulates — and nothing else

Two knobs, both of which already exist, both already read on every sweep.

**How much happens** is `events.sweep_chance`, multiplied:

```
m      = 1 + pace_swing * (2 * intensity - 1)
chance = clamp(0.05, 0.9, world_chance * m)
```

At intensity 1.0 the world runs 1.6× its own rate; at 0.0, 0.4×; at 0.5,
**exactly its own rate**. Against Milldale's default 0.35 that is a band from
0.14 to 0.56, with the false calm sitting on 0.35 to three decimal places.

**What kind of thing happens** is the `weight` field that
`xeric_sweep_kinds_for()` already attaches to every surviving kind and
`xeric_sweep_kind_order()` already consumes. The snake multiplies it. It is the
same float that learn.php writes, and the two thumbs compose by multiplication
the way two thumbs on one scale should:

| stage | what the world does more of |
|---|---|
| `opening` | `routine`, `visit`, `recognition` — introductions and texture |
| `rising` | `rumor`, `confidence`, `glimpse`, `friction` — complications |
| `taper` | nothing; the thumb comes off |
| `false_calm` | `ease`, `shared_meal`, `routine` up; `rumor`, `confidence`, `glimpse` **down** |
| `crescendo` | `confidence`, `mishap`, `friction`, `chase` |
| `closing` | `recognition`, `ease` |

The stage is derived from the curve, not declared next to it: `opening` below
the first knee, `false_calm` inside the declared window, `rising` and `taper`
by the sign of the slope before it, `crescendo` after it, `closing` past the
peak. A second field naming the stage would be a second timeline to keep in
sync, and the first thing that would happen is that they would disagree.

**The invariant that makes this safe: a thumb is not a gate.** Every multiplier
must be positive, and it can only re-weight kinds the world has already armed.
A story may never arm a system. If it could, `"closeness"` in a `kind_thumb`
would be a back door into the attraction economy, and the age floor's whole
argument is that the desire economy is closed structurally rather than by
everybody remembering. So: arming stays `forge.armed`, the snake stays a thumb,
and a `kind_thumb` entry naming a kind this world never armed is inert — which
is exactly what happens in Milldale, and is noted in the fixture.

### What advances progress

Beats, not the calendar. `p = (beats_opened + fraction_of_next_dwell) /
beats_total`. A player who ignores the story leaves it where it is, and the
world runs at 1.0× forever, which is the correct behaviour and costs nothing to
implement because 1.0× is the world.

The dwell is what stops a player who blitzes three reveals in one evening from
reaching the crescendo on day one: a beat cannot open until
`opens_when.min_dwell_hours` of **world** time have passed since the previous
beat opened. Progress is therefore a function of the story's own structure, and
the snake is a function of progress, and neither of them is a function of how
long the app has been installed.

**Beats are not rolled.** The snake modulates the world's ambient motion; a beat
event fires on the first eligible window after its beat opens, through the same
compose path with the roll skipped. A story that could stall on dice forever is
not a story, it is weather with a title.

---

## The mystery is a wall structure

This is the insight that makes the whole feature cheap, and it is worth stating
as a piece of engineering rather than as a metaphor.

A whodunnit is: one character knows the truth, several hold pieces of it, most
of the cast holds none, and the pacing decides when those pieces come out.
Xeric has had all three of those since the first week.

- **A piece is a secret.** It composes onto its holder as an entry in their
  `secrets` list with `gossip_grade: false`. Secrets render into their owner's
  own voice block and — when not gossip-grade — into nobody else's bible. The
  per-orbit privacy walls the forge writes (`hidden: [cast_dossiers, drives,
  secrets]`) already keep the rest of the cast out of them. **A mystery needs no
  new hiding machinery for its pieces at all.**
- **Not knowing is `must_not_know`.** `xeric_sweep_protected()` reads it and
  `xeric_sweep_choose()` keeps those handles out of any hour that rolls
  `on_spine`. That is the property a mystery wants and would otherwise have to
  build: the truth does not leak into an offscreen Tuesday.
- **Quoting is already policed.** `xeric_quotes_walled()` compares loose prose —
  a seeded memory, a lesson, an event title — against every wall's `explain` and
  every role's `must_not_know`, by six-word run. A story gets that for free.

### Two rules on protections, both learned from the code

**At most `floor(n/2)` of the cast may be protected by one overlay.**
`xeric_sweep_choose()` excludes *every* protected handle from a spine hour; if
five of six are protected there is never anybody left to have one, spine hours
quietly stop happening, and a mystery with no offscreen motion is a lookup
table. Protect the people whose learning it early would break the plot, and let
everybody else be ordinary.

**An overlay may only protect a character who does not already carry a
`special_role`.** `xeric_viewer()` merges wall keys from every matching role but
takes `role` last-wins, so two roles on one handle is an ambiguous audience
selector — and ambiguity in the wall layer is the one thing this codebase
refuses outright. A character who already has a role is protected by the wall
they already have; if that is not enough, the story is asking the wrong person
to be in the dark.

---

## Red herrings — the genuinely new mechanic

Everything above hides things that are **true**. A red herring is the opposite
shape: a character who **sincerely believes something false**. Wrong, and
known-wrong to the engine, and not known-wrong to them.

The bar is dime store. Not literary ambiguity, not an unreliable narrator — a
satisfying wrong lead that a reader enjoys being taken in by and enjoys seeing
disposed of.

```jsonc
{
  "key": "the_reds_cap_at_the_mill",
  "believer": "dot",                    // a CHARACTER. Fixtures are scenery and
                                        // have no interior to be wrong in
  "belief": "Pastor Dale's car was down by the mill the night Ellis Chandler
             died. She saw the Reds cap through the windshield at eleven…",
  "because": "She was closing up and she looked out and she saw it. She is not
              guessing and she does not guess.",
  "sincerity": "certain",               // certain | fairly_sure | wondering
  "is_false": true,                     // REQUIRED, and must be exactly true
  "actually": "It was Dale's car and it was Dale driving, forty minutes the
               other way, to the county hospital…",
  "points_at": "pastor_dale",           // or null: a wrong THEORY, not a wrong
                                        // suspect. Never the culprit
  "known_false_to": ["pastor_dale"],    // who knows better, and is not saying
  "collapses_on": "the_hospital_run",   // a beat key, or "resolution"
  "rating_min": "sfw"
}
```

**The believer's prompt never learns it is wrong.** `belief` and `because`
compose into the believer's voice block in the same grammatical position a
secret occupies, with their own lead — *"You are sure of this: … You would say
so if it came up."* — and `is_false` and `actually` go nowhere near it. Tell a
model its character is mistaken and you will get hedging, and hedging is the
death of a wrong lead: Dot has to be *certain*, because the player has to
believe her. The falsity is engine-side knowledge. **The believer's prompt is
byte-identical in shape to a conviction that happens to be true.**

Who does see `actually`? The narrator, whose whole definition is the viewer with
nothing withheld; the resolution checker; and the world, at the moment the
herring collapses, which is when `actually` becomes what somebody says out loud.

Four validator rules, each of which catches a real authoring mistake:

- **`is_false` must be exactly `true`.** A "red herring" that is true is a wall,
  and the author should write a wall. This field exists to make the engine's
  knowledge explicit, so a field that can be omitted or set false is a field
  that will drift into meaning nothing.
- **`points_at` may not be the culprit.** A wrong lead that points at the guilty
  party is the answer, not a lead. This is mechanically checkable and it is the
  single most likely thing a model drafting an overlay will get wrong.
- **`actually` is required.** A wrong lead with no explanation is a dead end. The
  bar is dime store: the reader gets to find out *why* they were fooled.
- **`collapses_on` must name a beat that exists, or `"resolution"` — and that
  beat must list the herring in its `kills_herring`.** Both halves, agreeing.
  Every wrong lead has a moment it is disposed of; an unfalsifiable one is a
  player staring at a wall forever.

---

## Characters know what they spilled

When a wall comes down, the person who let it down is aware they told you. This
is what makes interrogation feel like something instead of a lookup, and it is
one memory row.

A held beat carries three strings that are read at three different moments:

```jsonc
{
  "key": "the_chair",
  "at": 0.15,                           // where on the snake it opens
  "holder": "theo",
  "piece": "He got through the gate at the mill in June and there was a folding
            chair set up in the fourth-floor stairwell, facing the door.",
  "opens_when": {
    "after": ["the_word_gets_around"],  // earlier beats that must be spilled
    "min_dwell_hours": 12,              // world hours since the last beat opened
    "asks_about": ["mill", "chair", "stairwell"],
    "trust_gate": 3                     // the secret's own grammar, reused
  },
  "while_locked": "You do not bring this up. You have told it to two adults and
                   neither of them believed you…",
  "when_open":   "If he asks you about the mill you tell him the whole thing,
                  fast, and you watch his face to see whether this one lands.",
  "spilled_as":  "He told Walt about the folding chair in the stairwell and Walt
                  did not laugh and did not say we'll see.",
  "spill_detect": "quote",              // quote | auto | manual | marker:X
  "kills_herring": ["it_was_only_kids"]
}
```

`while_locked` and `when_open` are the two states of the same sentence in the
holder's system message; exactly one is present at a time. `spilled_as` is
written the moment they tell you.

**Where it is recorded, and why there:**

1. **A memory row on the teller** — `xeric_memory_add($db, $holder, $spilled_as,
   'spill', ['story' => …, 'beat' => …, 'to' => 'user'])`. This is what memories
   are for, and it is the cheapest possible place in cache terms: prompt.php
   documents the memory block as the last static block precisely because it is
   the one that grows, so a spill costs the tail of the system message and
   nothing above it.
2. **An arc** — `story:<key>:beat:<beat> = spilled`. The state machine reads one
   indexed row instead of grepping prose.

**The memory is the teller's, not the world's.** Nobody else gets it. A spill is
a fact about a conversation between two people; if it spreads, that is a `rumor`
sweep doing its ordinary job, which is a far better mechanism than a broadcast
because the story it spreads comes out changed.

### Detecting that they told you

The default, `"quote"`, reuses `xeric_wall_quotes(xeric_wall_words($turn),
xeric_wall_words($piece))` — the six-word-run matcher walls.php already ships
and already tests. A character who has said the piece has, by construction, said
most of the piece.

This detector is allowed to be generous, and that is a deliberate departure from
the fail-closed discipline everywhere else in the engine. **Fail-closed governs
what a character may KNOW and what the age floor forbids. Story progress is not
a safety property.** Over-detecting a spill moves the story forward one beat
early; under-detecting strands a player in front of somebody who has already
told them. Of those two, the second is the bug.

`"marker:X"` reuses the `event_marker:` grammar boons already use, for beats
that resolve on something structured. `"auto"` is for beats with no holder —
an inciting event, a bus, a body — which spill themselves when their event
fires. `"manual"` exists so a demo can drive one from a button.

---

## Resolution — and what happens the moment it fires

```jsonc
"resolution": {
  "kind": "accusation",                 // accusation | possession | arrival | marker
  "answer": "harlan",
  "requires_beats": ["the_chair", "the_ledger_of_lunches", "the_till_key"],
  "accept": { "to": ["harlan", "pastor_dale", "dot", "ruth"], "in": "conversation" },
  "on_wrong": {
    "closes": false,                    // must be false. Always
    "costs": "the one he named goes short with him for a day and says why, once",
    "arc": "story:mill_stairwell:wrong"
  },
  "never": ["mystery.rumor"]
}
```

**`requires_beats` is the anti-cheese rule and it is not optional. A guess is not
a solution.** Naming the right person on day one does nothing: no acknowledgment,
no wink, the character simply answers as themselves and the story keeps running.
The story closes when the player can *show* it — when the beats that make the
accusation supportable have actually been spilled.

**A wrong accusation is a beat, not a failure.** `on_wrong.closes` must be
`false`, validated, because a story that ends when you are wrong is a quiz. It
costs something — the accused goes short with you and says why — the arc counter
goes up, and the herring that led you there gets louder rather than quieter.
Accusing the wrong man in act two is the genre working correctly.

`possession` costs nothing new: `{"kind": "possession", "boon": "the_amulet"}`
closes when the boon is claimed, and boons already have triggers, claims and
TTLs. That is the punchlist's "boon obtained" case, implemented by pointing at
something that exists.

**`never` is a typed assertion, in the same spirit as `rumor_pays_out`.** A story
that happens at the mill may not explain the light in the mill. When the world
says `rumor_pays_out: false`, an overlay must name `mystery.rumor` in `never`
and no resolution may be it. The strange place is a gravity well, not a puzzle,
and an overlay is exactly the kind of thing that would helpfully solve it.

### The close

Four things, in this order:

1. **One arc row.** `story:<key>:closed = <world epoch>`. That is the entire
   state change.
2. **The overlay stops composing.** Its walls come down, its beliefs vanish, its
   pieces go back to being whatever the character's own dossier said, the snake
   stops modulating, `sweep_chance` returns to the world's own number. Assert the
   composed template against the untouched one; they must be identical.
3. **`on_close` writes the residue** — one closing event into `events`, one
   memory per named character, and a `world_keeps` line for the shelf. Memories
   and events only. **A story leaves residue in memory and nothing in the world
   file.**
4. **The shelf may offer another.** Nothing auto-injects. A closed overlay is
   listed as closed, its file stays where it is, and the world is available for
   the next one.

What that adds up to, in the owner's words: the murderer is still in the cast.
He still opens at seven. You can still talk to him, and now he carries a memory
of the Tuesday it came out, and there is no longer an open question between the
two of you.

---

## Injection — three doors, one pass

`xeric_forge_pass_story(array $template, array $brief, array $endpoint, ?callable $onNote): array`

Same shape as `xeric_forge_pass_walls()`, `_protagonist()` and `_seed()`: one
model call, JSON only, a deterministic fallback, notes on the way through. It
returns an overlay array; the caller writes it beside the template.

**Door 1 — the model drafts from prose.** `$brief = ['prose' => "a guy gets hit
by a bus, ends up in the hospital, calls his mother, and it turns out his mother
had an amulet that…"]`. The cast goes in as a roster of handles and one-lines,
temperature around 0.4, and the model is asked for roles and pieces — never for
final beat prose about people it does not have. Everything it returns is coerced
through `xeric_forge_pick_key()` against the real handles, so a hallucinated
character becomes a real one or the beat is dropped. That is the trick every
existing pass already uses and there is no reason for this one to invent another.

**Door 2 — the user writes it.** No model. Load, validate, compose. The
validator is the contract, not the pass; a hand-written overlay and a
model-drafted one are the same object by the time anything reads them.

**Door 3 — reshape.** `$brief = ['from' => <overlay>, 'change' => "this time the
guy from the bus…"]`. The existing overlay goes to the model as JSON and comes
back as a sparse answer; unnamed fields keep their values. **It writes a NEW
file with a new key.** Reshaping a live story in place would move walls under a
running world, and there is no sensible answer to "what happens to a beat that
was already spilled".

**When the model is dead or useless**, `xeric_story_default()` builds a minimal
valid overlay from the cast — one holder, one piece, one herring, an accusation
— the way `xeric_forge_default_seed()` does. A world forged offline still gets a
playable story.

Two things the pass owes regardless of door:

- Every string it produces is checked against `xeric_forge_trips_wall()` for each
  protected character before it is written anywhere they read. The seed pass
  already does this; the story pass has the same problem and takes the same
  answer, which is to **drop** the line rather than rewrite it.
- No overlay string may restate a rating-gated node at a lower rating. **An
  overlay states what a holder can OBSERVE; it never restates another
  character's gated interior.** Checkable with the same `xeric_wall_quotes()`
  used everywhere else, and it produced a better fixture: Harlan's money trouble
  is `rating_min: mature` in Milldale and therefore invisible in that sfw world,
  so Dot's piece is the observable fact she owns — she has been comping his lunch
  and writing it down as waste — and the inference is left to the player, which
  is how it should have been written anyway.

---

## Where this touches the age floor

The rule is narrow and it does not move: **sexual content involving a minor must
be structurally impossible, and nothing else about a minor is restricted.**

What that means for overlays, stated positively first, because the positive half
is the load-bearing one:

- **A child may hold a piece, be a witness, believe a red herring, be the
  subject of a beat, be in the room when it comes out, and be the reason a story
  is solvable at all.** In the worked fixture below, the twelve-year-old holds
  the piece that kills a red herring — a chair set facing a door is not an
  accident — and no adult believed him. That is the oldest working part in the
  genre and the overlay is where it belongs.
- **If a rule you are writing would keep him out of a beat, an hour, or a
  conversation, it is the wrong rule.**

And the closed half:

- **Every overlay node is rating-evaluated against its subject character**, via
  `xeric_rating_allows($eff, $node, $subject)`. A minor's ceiling is the weakest
  rating in every world, so a beat, belief or epilogue on a minor renders at sfw
  whatever the world is rated.
- **A node carrying `rating_min` above sfw whose subject is a minor does not
  load.** Same rule, same reason and the same error message as
  `cast.characters[i].flirt_style` on a minor: content that can never render is
  content nobody should be writing about a child.
- **An overlay cannot arm a system**, so it cannot put anybody into the desire
  economy. `kind_thumb` is a multiplier on kinds that survived the arming gate;
  if `attraction` was never armed, `closeness` does not exist and the thumb hits
  nothing.
- **An overlay cannot raise a rating.** `rating_min` is a floor on visibility. An
  overlay above the world's effective rating does not compose — it is not
  clamped, it is not degraded, it simply is not there.
- **Death and murder are in.** A murder mystery requires a death. Fictional
  violence is not what is being gated, here or anywhere.

The player-side affirmation gate composes with all of this without knowing
anything about stories: an unaffirmed session has `meta.rating` pinned to `sfw`
by `xeric_world_clamp_rating()`, and an overlay above sfw then fails to compose
for exactly the same reason any other node does.

---

## The worked fixture

`engine/fixtures/milldale-story.json` — a complete SFW murder mystery over the
Milldale cast, written to be implemented against.

Ellis Chandler, 74, the last of the mill family, comes home to sell the block
and goes down the fourth-floor stairwell instead. Harlan Beck let himself
through the chained gate with his father's till key to argue him out of it; they
argued on the landing; he put a hand out. He did not go there to hurt anybody
and he has not slept since.

**Three of the five pieces are already in `milldale.json`, word for word.** That
is the design justifying itself: Theo's folding chair in the stairwell, Dot's
comped lunches, Harlan's father's till key on a ring for a till that has been
gone eleven years. The world already had the walls. The overlay only decides
when they come down and in what order.

| beat | `at` | holder | what it buys |
|---|---|---|---|
| `the_word_gets_around` | 0.00 | — | the inciting hour, fired as an event |
| `the_chair` | 0.15 | theo (12) | kills "it was only kids" |
| `the_locked_gate` | 0.30 | ruth | Ellis asked who held the keys |
| `the_ledger_of_lunches` | 0.45 | dot | he is broke, observed not inferred |
| — | 0.50–0.72 | — | **the false calm. Nothing opens. The town is a town** |
| `the_hospital_run` | 0.78 | pastor_dale | kills "the Reds cap at the mill" |
| `the_till_key` | 0.92 | harlan | the landing, at the peak of the crescendo |

Two red herrings, one of each shape. **The Reds cap** points at a person: Dot saw
Dale's car by the mill at eleven and she is not guessing — he was forty minutes
the other way at the county hospital, sitting with somebody who has not told
their own family yet, and he would rather be thought a liar than say so. **It
was only kids** points at nobody: Ruth is certain there is nothing here to solve,
which is the wrong lead that stalls an investigation rather than misdirecting
it. A twelve-year-old ends it.

One protection: `pastor_dale` must not learn who was on the landing before he
has said where he was himself, because his own answer is what clears the man
Dot is currently wrong about. Janelle is not protected by this overlay — she
already carries a `special_role`, and `family_innocence` already hides
`secrets`, so she cannot read anybody's piece regardless.

The resolution is an accusation of `harlan`, said to any of four people
including him, gated on three beats. `never: ["mystery.rumor"]` — the light in
the mill office still moves from window to window and this story does not
explain it.

Milldale carries no `forge.armed` block, so `xeric_sweep_kinds_for()` gives it
the `ordinary` floor and the `kind_thumb` has nothing to push on. That is
correct, it is the invariant working, and it is written into the fixture's
`_notes` rather than papered over: in Milldale the snake modulates
`sweep_chance` and the beats fire as scheduled events. A world forged with
`motivation: mystery` — which arms `rumors`, `slow_reveal` and
`unreliable_witnesses` — is where the thumb bites.

---

## What the Build phase owes

An `engine/story.php` with roughly this surface:

```php
xeric_story_load(string $dir): array          // glob story-*.json, filename order
xeric_story_validate(array $s, array $t, string $label): void   // throws, like world_validate
xeric_story_compose(array $t, array $stories, PDO $db): array   // → template
xeric_story_progress(array $s, PDO $db): array // {p, stage, intensity, beats}
xeric_story_snake(array $snake, float $p): array// {intensity, stage}
xeric_story_chance(array $t, array $stories, PDO $db): float
xeric_story_thumb(array $kinds, array $stories, PDO $db): array // multiply weights
xeric_story_observe(array $s, PDO $db, string $handle, string $turn, int $epoch): array
xeric_story_close(array $s, PDO $db, int $epoch): void
xeric_story_shelf(string $dir, PDO $db): array
```

The validator rules, in the order they are cheapest to check, are the ten that
`docs/STORY.md` states above plus the referential ones — every handle a declared
character, every place a declared place, beat keys unique and `at` strictly
increasing, no beat inside the false calm, `opens_when.after` naming only
*earlier* beats, curve control points strictly increasing and spanning 0..1,
every `kind_thumb` multiplier positive, wall keys namespaced. There is a working
reference implementation of all of it: the scratch validator written alongside
this document ran 375 checks against the fixture and caught all ten deliberate
mutations (a true herring, a herring pointing at the culprit, a beat in the false
calm, a herring nothing kills, a second role on Janelle, a wrong answer, a
fixture as believer, a resolution with no required beats, a story that explains
the mill, and a mature node on a twelve-year-old).

The three tests worth writing first, in order of how much they are load-bearing:

1. **Compose, resolve, compose again — `===` the untouched template.** This is
   the central claim of the whole feature.
2. **The false calm multiplies `sweep_chance` by exactly 1.0.** Not
   approximately; the design says the false calm is the world at its own pace.
3. **A minor holds a beat, spills it, and is in the resolution** — with the
   suite asserting he was never excluded from an hour, an event or a
   conversation on account of his age.

## Where it is actually wired (2026-07-31)

The surface above exists. This is where it is joined to the rest of the engine,
because the overlay is inert until somebody passes it in — and it takes **both
halves or neither**:

- the **raw overlays** in `$opts['stories']` are what the pace, the kind thumb,
  the beats and the conversation watcher read;
- the **composed template** (`xeric_story_compose`) is what the walls, the
  protections and the voices read.

One without the other is a half-live story: pace with nobody's voice in it, or
voices at the wrong pace.

| Where | What it does |
|---|---|
| `forge/web/play-lib.php` → `xeric_play_open()` | The one seam for the whole web app: loads via `xeric_story_for()`, composes, and hands back `template` (composed) **and** `stories` (raw). `forge/web/world.php` deliberately does not come through it — that page promises the bytes on the disk. |
| `forge/web/say.php` | Passes `stories` to the turn; relays the result as `story` in its JSON. |
| `forge/web/tick-worker.php` | Passes `stories` to `xeric_sweep_catchup()` and to `xeric_proactive_check()`. |
| `forge/web/why.php` | Prints the trail's `story` block — the stage in words, so the **false calm** reads as a paced quiet rather than as a world gone slack. |
| `engine/sweep-cli.php`, `engine/chat-cli.php` | The reference sequences. Both load with `xeric_story_for()`, compose, and pass the raw list. |
| `engine/prompt.php` → `xeric_prompt_story()` | Renders `$t['story']['lines'][handle]` into the speaker's **static** block, between the lessons and the memories: it changes only when a beat opens or spills, so it sits above the block that grows every turn. It reads the composed template directly rather than calling into `story.php`, because prompt is the lower layer. |
| `engine/chat.php` → `xeric_chat_story()` | After the turn commits: `xeric_story_observe()` per live overlay, and `xeric_story_resolve()` when the player named somebody. It cannot fail the turn. `xeric_chat_accused()` reads the sentence — a cue, exactly one name, no negation — and `$opts['accuse']` skips it for a caller with a button. |

`engine/story.php` is required by the CLIs and by `play-lib.php`, never by
`sweeps.php`, `chat.php` or `prompt.php`: story.php requires sweeps.php, which
requires chat.php, which requires prompt.php. A world with no overlay must not
pay for the overlay engine, so those three reach for a `xeric_story_*` function
only once a caller has actually handed them one.
