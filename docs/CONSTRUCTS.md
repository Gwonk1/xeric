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

## Open questions

1. **Rewind semantics.** The clean version: rewind undoes the *last skip
   only, whole* — its events, memories and fuse-firings un-happen, the clock
   returns, and you live those hours differently. It is destructive (the
   world un-remembers), it cannot branch, and it cannot reach past the most
   recent skip. Alternative designs (branching saves, arbitrary rewind)
   multiply state beyond what a single-file world should carry. Owner call.
2. **The book view.** Named by the owner as where obligations live; the
   sidebar calendar page is the nearest existing surface. Confirm what the
   book view wants to be before wiring.
3. **Which sibling second.** Gossip ripple is now cheap (observables-only
   makes it walls-safe); confidences (a user-created wall; betrayal as the
   highest-stakes event) is the deep one. Sequence to owner's taste.
