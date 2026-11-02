# Social constructs — obligations, and what missing them does

A relationship that cannot be let down cannot be kept. Today the cast has
history — memories, arcs, trust counters — but nothing anyone *owes* anyone.
This document is the design for the first construct, **expectations**, decided
with the owner on 2026-08-02. The sibling constructs (grudges, confidences,
vouching, gossip ripple, rituals) inherit its shape.

## The promise test

An expectation is born when somebody hears a commitment **with no weasel words
in it**. "I'll be there Thursday" forms one. "I'll try to be there", "maybe",
"I might", "I think I'll get there", "probably" — **any hedge makes it a
non-promise**, and the world forms nothing. The extractor already catches
commitments (the CHANGE rule: prefer what was promised over the fact that
something was said); the promise test is the gate on top: unhedged, and
carrying a *when*.

**The child exception.** A young child — roughly three to seven — sometimes
hears every promise as a real one. "I might come by the market" forms nothing
in an adult listener and *may* form an expectation in a six-year-old, because
that is what six-year-olds do. This is not a bug in the gate; it is the gate
being a property of the LISTENER. (It also means letting a small child down
is easy to do by accident, which is true in the real world and is exactly the
kind of weight a construct exists to carry.)

## The body is the tell

There is no narrator in the room. Nobody is told how anyone feels — the only
access anyone has to another person's inside is **the face, the
micro-expressions, the body**. So a construct's emotional consequences surface
as **stage direction**: she says "it's fine" and wipes the same glass for a
minute; he reads the note twice and puts it in his pocket. Stage direction is
a rendering of the **subconscious** — the character does not narrate it and
may not even know they are doing it.

Three consequences flow from one event of being let down:

1. **Trust takes the hit** — the internal, invisible ledger (the existing
   trust arcs), adjusted without a word said.
2. **The body shows it** — observable residue in scenes: stage direction in
   chat turns and sweep prose. This is what the other people in the room see.
3. **The gossipy get something to talk about** — and this is the load-bearing
   rule: **gossip feeds on observables only.** What travels is "she waited
   outside the Bluebird till nine, I saw her" — never "she felt abandoned",
   because nobody but her body knew that. Gossip that spreads only what was
   seen is walls-safe *by construction*; a gossip system that could spread
   interiors would be a leak engine, and this rule is why it is not.

## Missing, explaining, and who forgives

Expectations are **not boon machinery**. A boon is earned and paid; an
obligation is *lived*, and life interferes — you have a sick aunt, you get
stranded in Omaha. A missed expectation opens a **repair window**: turning up
later with an explanation is a real move, and it lands.

**How well it lands depends on who is listening.** Forgiveness is a property
of the PERSON, not a rule of the system. One character hears the Omaha story
and pours you a coffee; another adds it to a private count of your excuses.
The disposition comes from the interior the forge already writes (the psyche,
the sore spots, the pull) — a forgiving person and a scorekeeper are different
people, and the same explanation should not work on both. No explanation at
all is its own answer: the trust delta stands, the residue hardens into an
ordinary memory, and the observable version of it is available to travel.

## Keeping it

A promise used to have only bad endings. An expectation could go from open to
missed, and from missed to repaired or hardened; a death released it. Nothing
closed one because the promised thing *happened* — so the user could walk into
the Bluebird on Friday morning with Ruth's casserole dish, and four hours later
the fuse burned anyway: Ruth waited, lost a notch of trust, and showed it in
small ways. A ledger that can only record failure is a grievance generator.

**Kept** is the fourth ending, and it is reached two ways, both gated in code
(`xeric_expect_keep`, `xeric_expect_keep_present` in `engine/constructs.php`):

1. **The body was there.** On the day the promise was for — from that local
   midnight until the miss would fire — whoever promised is standing where the
   listener is. For the person at the centre that is the player's body
   (`player.where`), and only if it *arrived after* the promise was made, so a
   promise said across the counter is not kept by the act of saying it. For a
   promise between two townspeople it is their own week seating them in the
   same room. Position and schedule, no model. The constructs tick checks this
   *before* it burns any fuse.
2. **The extractor heard it done.** The same harvest that reports `explained`
   reports `kept`: the user actually did, in this conversation, what they had
   promised. The model spots it; code decides whether there is an open
   expectation of *that* player, formed before this turn, whose miss has not
   fired — and closes the soonest one. The same split as repair.

Keeping it is the mirror of missing it: **one notch of trust** through the
same bounded path (trust.php: past the contact band, "only the things that
cost something move it: a promise kept"), against whoever actually promised —
a townsperson keeping their word to another never credits the player — and
**one ordinary memory** ("Walt said … and did."). **No event**: a person
turning up as they said is not something a town retells, and gossip feeds on
what was charged. The block says it in one coarse line — "said … and did,
Friday (the casserole dish). Nothing is owed on it." — so the listener's
prompt and the inspector read *kept*, never *expecting*. A promise already
missed cannot be kept by turning up late; that is what explaining is for.

## Time travel is real

A skip is real time passing. Expectations fire **during** skips: the fuse
burns whether or not you are watching, the missed appointment happens in the
world's own hour, the stage-direction residue and the gossip material land
exactly as if you had lived past it slowly. No pace or centrality mercy — a
heavy skipper accumulates letdowns, which is the truth of being gone a lot.

**And the mercy is the rewind.** If you can fast-forward past your own
appointment, you should be able to rewind and *make* it. Rewind semantics are
the one open design question below.

## Where you see your obligations

- **The book view** carries them — the world's own calendar page is where a
  commitment naturally lives.
- **The Narrator knows and reminds you.** It has full canon; an upcoming
  promise is a fact about the world, and a reminder is the ask-mode voice
  doing something useful and unspoiling: "You told Thi you would be at the
  market Saturday morning." Notification-worthy — this is a thing the owner
  explicitly wants pushed, not merely visible.
- In the room, it stays diegetic: she reminds you herself, in character, or
  her body does when you show up late.

## The mechanical shape (unchanged from the punchlist spec)

trigger (unhedged promise heard) → a small arc with epochs — the fuse is the
promised WHEN plus a fade, never a per-turn countdown (cache discipline) →
renders as COARSE state ("expecting you Saturday", "you missed Thursday",
never a timer) → licenses a sweep beat or a proactive line → residue becomes
an ordinary memory. Every construct carries an XERIC_SYSTEMS name so an open
motivation can arm it (`expectations` arms with company/visits; a survival
world may not want it) and an inspector answer — why.php must be able to say
"she is short with you because you missed Thursday and never said why", or a
grudge reads as the model being moody.

## Decided (owner, 2026-08-02, same session)

1. **Rewind is real time travel — approved, with real effects.** It undoes
   the *last skip only, whole*: its events, memories and fuse-firings
   un-happen, the clock returns, and you live those hours differently. It is
   destructive on purpose — the world un-remembers that week. The physics of
   it is the point: **the world forgets the undone future; the player does
   not.** You rewind to make the appointment precisely because you know what
   happened when you missed it. No branches, no reach past the most recent
   skip.
2. **The book view is its own page**, off the main user-menu pill: it prints
   the day as it lands — events, things, and **dreams** — the world writing
   its own story as it happens. Obligations live there naturally (a promise
   is a fact about a coming day), and dreams belong to the same page because
   a dream is the one kind of event that only the book could ever have seen.
   Implementation note: the owner observes there is already "another settings
   page or view buried somewhere" — audit the buried views before adding this
   one, so the menu grows a page and not a pile.

## Still open

- **A bare day is due at its midnight.** `xeric_promise_when("Thursday")`
  resolves to 00:00 Thursday, so with the four-hour grace "I'll be there
  Thursday" misses at four in the morning, before the day it named has begun,
  and its keep window is those four hours. A promise with a time word
  ("Friday morning") is unaffected. Found while adding `kept`; not changed
  here because the miss tests and the benchmark's scenario both pin the
  current resolution.

- **Which sibling second.** Gossip ripple is now cheap (observables-only
  makes it walls-safe); confidences (a user-created wall; betrayal as the
  highest-stakes event) is the deep one. Sequence to owner's taste.
