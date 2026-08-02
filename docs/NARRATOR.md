# The Narrator — the other god of the world

The user is one god: they act, and the world answers. The Narrator is the other:
it sees everything, acts rarely, and is the only voice you can ask *why*.

This is not a chatbot bolted onto the side. The engine already has the concept —
`xeric_render_bible($t, null, …)` renders **full canon with no walls applied**,
and every wall in the system is defined as "what this viewer does not get." The
narrator is simply the viewer with nothing withheld. It has existed since the
first day of the renderer; it has never had a mouth.

## The narrator has secrets too

**Full knowledge is not full disclosure.** The narrator sees everything — that is
what makes it useful — but what it *sees* and what it *says* are two different
things, and conflating them produces a spoiler with database access instead of a
storyteller.

It will not:

- **unravel the mystery.** Not the solution, not a decisive hint, not
  "well, notice who was at the mill on Tuesday." The strange place keeps its
  secret from the narrator's mouth as firmly as from the cast's.
- **tell you where a boon is** or how to claim it. A thing worth chasing stops
  being worth chasing the moment somebody hands you the map.
- **spoil what is coming.** Its own write-ahead beats are the one part of the
  world it will never discuss. "Something is moving" is the most it says.
- **hand over a character's secret** just because it knows it. If the user could
  plausibly learn it in the world, the narrator points at the door — it does not
  open it.

This is a second kind of wall, and it is worth being precise that it is not the
existing kind. The engine's `knowledge_walls` govern **what a viewer is told**.
The narrator's discretion governs **what a knower will volunteer**. Same
principle — information has an audience — applied to output rather than input.

### The line it can answer freely

There is one whole class of question where discretion would just be obstruction:
**mechanics**. As the world's author you must be able to ask why the machinery
did what it did, and get a straight answer:

> *Why did nothing happen last night?* — quiet hours, and the two people free
> were both off shift in different places.
> *Why does she keep bringing up the ledger?* — it is in four of her last six
> memories; here they are.
> *Why has Mabel not appeared in three days?* — her week has her at the church
> 06:00–11:00 and you have only skipped evenings.

So: **straight about the machine, discreet about the story.** The narrator will
tell you how the world works and refuse to tell you how it ends.

### Refusals are in voice, not in error

When it declines, it declines like a narrator — *"That is not mine to say."*
*"You will find out, or you will not."* — never a system message, never a policy
sentence, and never a coy hint that leaks the shape of the answer. A refusal that
tells you there IS something to find has already told you too much.

**Open question:** whether an explicit author mode ever drops the discretion —
for debugging a world you are building rather than living in. Deliberately not
assumed: the default is the storyteller, and the author's convenience is not a
good enough reason to make the world transparent by accident.

## What it is for

Four powers, in ascending order of how much they can break:

### 1. Ask (read-only, zero risk)
Talk to the world itself. *"Why did Silas leave early?" "Has anyone mentioned
the mill since Tuesday?" "What has Mabel been carrying around?"* It reads from
full canon — every memory, every decision trail the sweeps recorded, everything
no character could tell you — and answers **within its discretion above**: freely
about what happened and how the machinery works, sparingly about what is hidden,
never about what is coming.

This alone is worth building. It is a debugger for a world, in the world's own
voice, and it needs nothing that does not already exist.

### 2. Investigate (read-only, model-assisted)
Audits a world for the failures that accumulate quietly:
- threads that were opened and dropped (a secret nobody ever used, an arc that
  stopped moving, a boon owed and never claimed)
- characters who have not appeared in N days of world time
- contradictions between what two people remember about the same event
- a protagonist whose pressure never actually pressed
- economies with no motion

Output is a list of *observations*, not fixes. The world's author decides.

### 3. Write ahead (the interesting one)
Today the world is **weather**: sweeps pick a plausible kind, a plausible group,
and something happens. It is alive but it is not *going* anywhere. A narrator
that writes ahead gives the world **intention**: a small set of intended beats —
"the ledger goes missing before Friday", "Elias and Mabel end up in the same room
and it does not go well" — stored as world intent, which sweeps then bias toward
rather than obey.

The distinction matters and must survive implementation: intent is a **pull, not
a script**. If the user does something that makes an intended beat impossible or
stupid, the beat dies quietly and the narrator writes a new one. A world that
insists on its plan is a novel with a chat interface, and the user will feel the
rails immediately.

### 3b. Direct — the counterbalance to their free will

Everything else in this engine pushes toward autonomy. Characters choose their
own photos and write their own prompts. They steer toward a pull they will not
name. They rate their own attraction, keep their own secrets, decide what they
took away from a night. That is the point, and it is also the problem: **free
will, left alone, produces drift.** Everyone does their own plausible thing, in
their own plausible room, forever. Alive, and going nowhere.

The Director is the force on the other side of that. Not a hand on anyone's
will — a hand on the circumstances their will runs into.

**The rule that keeps it honest: the Director changes the set, never the
performance.** It can put two people in the same room at the same hour. It
cannot decide what they say there. It can make sure the man who is owed
something finally stands in front of the person who owes it. It cannot make him
ask. Everything downstream of the arrangement stays exactly as free as it was.

**And it works with what is already loaded.** It advances threads that exist,
brings back somebody who has been absent, lets a boon that has been owed for
days finally come due, closes a distance that has been widening. It does not
invent new drama from nothing — a Director who can conjure a crisis on demand is
a "make something happen" button, and the world stops feeling like it has its
own life the first time you press it.

That constraint also makes it the natural consumer of two things already built:
**investigate** (dropped threads, absent characters, unpaid boons — that audit
is literally the Director's to-do list) and the **learning signals** (which
characters and which kinds of night the user actually engages with — so the
Director leans toward what this person came here for, without ever being told).

**Called upon, not always on.** The user asks — *"something needs to happen"* —
or a stretch of skipped time goes by with nothing landing, and it takes one
quiet turn. It should be rare enough that a world still mostly runs itself,
because a Director who acts every hour has replaced the free will it was
supposed to balance.

### 3c. Why a Director is needed at all: these people have no physics

In the physical world, the largest deterministic force in an ordinary life is
not character or intention. It is **location and proximity**. You marry someone
you were near. You take the job you heard about because of where you stood. The
friend you kept for thirty years was assigned to the desk beside you. Nobody
arranged any of it, and none of it was free of arrangement.

**These characters have none of that.** Nothing physical constrains them.
Anyone could plausibly be anywhere, meet anyone, at any hour. Their freedom is
not the human kind — bounded, situated, expensive to move against — it is the
frictionless kind, which is not really freedom at all, because a choice that
costs nothing and excludes nothing is not a choice.

So the honest description of the Director is not *author*, and not even
*director*: **it is the physics this world does not have.** It supplies the
constraint that geography supplies for us. That reframing is worth more than the
metaphor, because it settles what the Director may and may not do.

#### It gives reasons, it does not place people

Sharper than "changes the set": a physics does not *put* you somewhere. It makes
being somewhere else expensive, or gives you a cause to move.

> **Teleportation:** Elias is at the church tonight.
> **Physics:** the ledger Elias needs has been at the church since Tuesday.

The first overrides a will. The second creates a *reason* — and he may still not
go, because he is tired, or because he does not want to see who is there. The
world stays causally honest, and the encounter, if it happens, was his.

This is compatibilism, arrived at by engineering rather than by argument. The
engine already gives characters real freedom: they choose their own words, their
own photos, what they took from a night, who they want. What it has never given
them is **a world that constrains those choices in a non-arbitrary way** — and
free will inside no constraints is indistinguishable from randomness. The
Director is not the enemy of their autonomy. It is the thing that makes their
autonomy *mean* something, in exactly the way that being stuck in one town with
these particular people is what makes a human life a story instead of a list.

#### What this implies for the code

- **Schedules, places and hours are not flavour — they are the physics layer.**
  `week[]`, `xeric_world_who_is_where()`, `xeric_sweep_open_places()` and each
  place's own `hours` and `serves_alcohol`
  are the existing implementation of proximity-determinism, and they should get
  *stronger*, not be routed around. A sweep that assembles a "plausible group"
  without asking whether those people could actually be in a room together is
  cheating in the one place cheating is expensive.
- **The Director must be bound by the same physics it enforces.** It may create
  causes; it may not create coincidences. If it needs two people in a room, it
  needs a reason one of them would go.
- **Distance should cost something.** Right now every place is equally reachable
  from every other. A world where the mill is forty minutes out and the diner is
  across the street is a world where who you see is decided partly by effort —
  which is the whole point.
- **The absent should drift.** Somebody the user never goes near should slowly
  stop featuring, exactly as people do. That is not a bug to be papered over
  with a "bring them back" rule; it is the physics working, and the Director's
  job is to notice when it has gone too far and supply a reason, not an
  exemption.

### 4. Author (write, gated, undoable)
Create and change world content on request: invent a character, add a place,
retire someone, plant a secret, redirect an arc, rewrite what a sweep produced.
Everything it writes goes through `xeric_world_validate()` and the same
copy-before-save that the review page uses, so every authored change is one
undo away.

## Two authorities, and only one of them is safe

The owner's phrasing was "using the local model as a TUI so that code changes
could be made" — and flagged it as dangerous. It is, and the danger is not
evenly distributed. Split it:

| | **World authority** | **Code authority** |
|---|---|---|
| Changes | templates, state, events, memories | the engine itself |
| Blast radius | one world, undoable | every world, and the machine |
| Validated by | `xeric_world_validate()` | nothing a model can run |
| Default | **on** | **off, and probably permanently** |

World authority is safe *because the world is data*: everything the narrator can
touch is validated, versioned, and revertible. That covers nearly every use the
owner described — recreating a storyline, investigating a character, redirecting
what is coming.

Code authority is a different product. A local model with a shell is a local
model with a shell; the fact that it is wearing a narrator costume does not
change what happens when it decides the fix is `rm -rf`. If it is ever wanted, it
belongs behind: an explicit flag, a git working tree it cannot leave, a
diff-and-confirm step, and no network. Not in this subsystem, and not by
accident.

## Interface

A TUI is the right instinct — this is a tool for the world's author, not for a
visitor. But the same core should serve three surfaces, because they are the
same conversation:

- **CLI/TUI** (`narrator-cli.php`) — the author's console. Local, fast, no auth.
- **Web** (`narrator.php`) — a panel beside the play view, for asking on a phone.
- **MCP** — the narrator as a tool a *different* agent can call, which is the
  same shelf idea as the Bible Library pointed at your own world.

## What it is not

- Not a character. It has no voice of its own in the world, never speaks to the
  cast, and never appears in anybody's prompt. The moment the narrator can be
  talked *to* by a character, the walls stop meaning anything.
- Not a rules engine. It proposes and observes; the physics stay in the engine.
  If the narrator has to be consulted for the world to run, the world is broken.
- Not always on. It runs when asked (plus, at most, one cheap write-ahead pass
  when a stretch of time is skipped).

## First slice

1. `engine/narrator.php` — `xeric_narrator_ask()`: full-canon context (bible with
   no walls + recent events with their decision trails + memories across the cast
   + world state) → a question → an answer, with citations of what it drew on.
2. `engine/narrator-cli.php` — ask from the terminal.
3. `narrator.php` in the web app — the same, beside the play view.
4. Investigate as a second verb once ask is proven.

Write-ahead and author come after, in that order, and only once ask has shown
the context assembly is right — because everything above depends on it.
