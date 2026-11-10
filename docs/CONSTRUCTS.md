# Social constructs — obligations, and what missing them does

A relationship that cannot be let down cannot be kept. Today the cast has
history — memories, arcs, trust counters — but nothing anyone *owes* anyone.
This document is the design for the first construct, **expectations**, decided
with the owner on 2026-08-02, and the record of the siblings that inherited its
shape: gossip, debts, confidences, and — by fuse length — anticipation, news,
worry, grudges, rituals and milestones.

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

## Confidences, formed

A confidence was a row nothing wrote and only the gossip sweep broke. Now the
chat harvest watches for it (`confided` beside `promise`, `explained`, `kept`):
the model reports the character's exact words, and code decides — the words
must carry an explicit ask ("don't tell", "between us", "keep this to
yourself": `xeric_confide_marked`), must really be in what she said in the
window, and the teller must be an adult. The row (`confided.N` on the teller,
`xeric_confide_to_player`) is a **user-created wall**: the fact, who told it,
and who must not hear it — named in her words ("don't tell Harlan") or, when
nobody was named, anybody.

It renders in exactly one prompt, the teller's ("You told Walt … and asked
them to keep it"); `xeric_confide_player_lines` gives the player's side ("Ruth
told you X and asked you to keep it") for a voice that speaks to the player.
**Breaking** reads the formed row: a later harvest of the player's own lines to
somebody who must not hear it, with every distinctive word of the fact present
(`xeric_confide_repeat`), breaks it — three notches off what the teller thinks
of the player, a memory on the teller, a why-trail. Owning up inside seven days
mends it (`xeric_confide_mend`, the `explained` watch, after a repairable miss);
never owning up hardens it into a grudge.

## The siblings, by fuse length

Each is the same shape, an arc on the character who holds it (so rewind's arc
diff un-happens it), stepped by `xeric_constructs_tick`, rendered as one coarse
line in an ON YOUR MIND block (no clocks, no digits), and explained by one
inspector line (`xeric_constructs_why`, appended to why.php's constructs note).
Licences are spent by a construct rung in proactive.php that runs only after
the aftermath rung found nothing, and obeys every rule a ping obeys, plus the
dream rung's: only somebody you have actually talked with.

| Sibling | Trigger | Epochs (coarse) | Licence | Residue |
|---|---|---|---|---|
| anticipation | an expectation of the player forms | ahead · the eve · the day | one text the day before | "had been looking forward to it" (and it happened, or not) |
| news | an hour they were in, since the thread last moved | fresh (renders after a day) · told · stale | a text-first after 24 h; stale at 72 h | told |
| worry | silence past twice the usual gap (median gap between visits to the thread, from its messages) | worried · sore (4×) | a text-first | relief, or a grudge seed |
| grudge | a miss or a broken confidence unrepaired past its window | cold · cool (14 d) · thawing · settled (7 d) | none; it renders | "let it go" |
| ritual | third arrival at the same place and hour on different days, inside a fortnight | kept · missed | a line when missed | a memory of the miss |
| milestone | a visible ledger crosses 10/25/50/100; a month of knowing | due · marked | one line, once | noticed |

The repair path for a grudge is an explanation that came too late for the
miss, or a promise kept since: it thaws. Rituals log the body's arrivals in a
capped world arc; learn.php's tally does not carry a gap or a place, so worry
and ritual measure from the thread and the arrivals themselves.

## Cast to cast

Rooms and duets already handed their lines to `xeric_expect_from_scene`; the
sweep's prose now goes the same way through `xeric_constructs_cast_tick` (the
heart calls it): a quotation whose sentence names exactly one of the hour's
people is theirs, and everybody else in the hour heard it. Kept when their week
seats them together, missed otherwise; the promiser now keeps a memory of their
half too. **Through the walls:** a promise whose words a listener's walls
remove forms nothing for that listener (the ripple's own needle and quotation
test), so a promise about the Thursday pot made in front of Janelle never
reaches her prompt; the miss event stays observables-only. A day said with a
time of day ("Wednesday morning") now carries the hour in scenes too.

## Still open

A bare day used to be due at its midnight, so "I'll be there Thursday" missed
at four in the morning, before Thursday had begun. It is due at 20:00 now
(`XERIC_EXPECT_BARE_HOUR`): the grace runs out at midnight, and being there at
any hour of the day keeps it. And the sweep's drop-in group reads
`xeric_sweep_open_places()`, so nobody drops in on a workplace after it has
shut. Both were filed here; both are closed.

- **Theo can be dealt into the Thursday game.** `xeric_table_barred()` bars
  by walls, and the wall that hides the pot is the child role's; Theo is
  twelve and not that role, so nothing keeps him out of the sweep's groups
  at First Lutheran on table night.

- **The teller learns at once.** A broken confidence costs the teller's trust
  the moment the player repeats it; nothing routes the finding-out through the
  ripple (the hearer and teller sharing a room). That would be truer.
- **Repeating it in a room** is read when the room closes, one line at a time
  (`xeric_room_land_live`), not as it is said; a duet or a scene among the
  cast is not read at all.
- **One person at the centre for news, worry, ritual, milestone.** They read
  the thread and the body, which carry no player id yet (M4).
- **The sibling names are not in `XERIC_SYSTEMS`**, so the forge never
  proposes them; they arm by name only when hand-written, and otherwise ride
  the preset systems (docs/WORLD_TEMPLATE.md).
- **News licenses more pings.** The continuity stand-in run makes three more
  model calls in three days (construct-rung texts), which is most of the token
  move in the accepted baseline.
