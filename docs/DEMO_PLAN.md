# The ten-day target: a playable Xeric world at dev.xeric.dev

**Goal:** a visitor opens a URL and, inside five minutes, *gets it* — that this
is not a chatbot, it's a world that was running before they arrived and keeps
running when they leave.

## The demo problem, stated honestly

Xeric's whole thesis is **ambient and asynchronous**: things happen offscreen,
characters text first, continuity accrues over weeks. None of that is visible
in a five-minute visit. A naive demo — "here's a chat box, talk to Ruth" —
demonstrates exactly the thing everyone already has.

So the demo must do two things a private install never has to:

1. **Arrive already lived-in.** The world ships with weeks of history baked in:
   events that happened, memories characters hold, favors owed, a friendship
   that soured in June. The visitor walks into the middle of something.
2. **Make time legible.** A control that advances the world — *skip to
   evening* — runs a real sweep: the cast does something without the visitor,
   and then someone messages them about it. That compresses Xeric's core loop
   from days into thirty seconds, using the real machinery, not a canned reel.

If those two land, the demo sells itself. Everything else is plumbing.

## Shape

```
xeric/
  engine/               # reusable, world-agnostic (walls + renderers exist)
    world.php           # load/validate a template, resolve viewers
    state.php           # SQLite: conversations, messages, memories, arcs, events
    prompt.php          # assemble a system prompt from renderers + live state
    chat.php            # one turn: build → call model → parse → persist
    sweeps.php          # offscreen life: an event happens, memories are written
    seed.php            # the baked past, written once into the same tables
    llm.php             # model adapter (llama.cpp / ollama / OpenAI-compatible)
    renderers/          # facts → prose, through the walls
    fixtures/
      milldale.json     # the world the engine is tested against
  forge/                # the setup pass: answers → a validated world
    forge.php
    web/                # the public web app — a THIN layer over both
      forge.php         # the forge wizard
      play.php          # a world, live: chat, cast sidebar, the time control
      review.php why.php# edit-and-reroll before launch; the inspector
      session.php       # per-visitor world copy, TTL, rate limits, queue
      deploy.sh
```

Worlds do not live in the repo. Every world — forged in the browser or from the
CLI — is written into a data dir outside the docroot, because a world directory
holds somebody's real name, occupation and session token. The only world that
ships is the fixture, and it is fiction.

**Engine/web split is load-bearing.** Anything a private local install would
need goes in `engine/`; anything that exists only because strangers are
sharing one GPU goes in `forge/web/`. The web app is a *consumer* of the
engine, never a fork of it.

## Constraints that shape the build

- **One model slot.** The A2000 runs one llama at a time, shared with the
  author's private app and image generation. The demo must serialize: a global
  in-flight lock, a short queue, and an honest "someone else is talking —
  you're next" state. Never let the demo starve the owner's own machine.
- **Sessions are cheap but not free.** Each visitor gets their own copy of the
  seed world (SQLite file copy — the baked world is small). TTL ~24h, a hard
  cap on live sessions, oldest evicted.
- **Rate limits are the product's dignity, not an afterthought.** Per-session
  message cap, per-IP session cap, and a graceful "the demo has had enough for
  today" rather than a 500.
- **Staging is behind basic auth** during the build, so all of this can be
  wrong in public for nine days.

## Day plan (a target, not a promise)

**Revised day 2 (owner's call): the FORGE comes first.** A demo of one
hand-built world proves the engine; a demo where the visitor *forges their own*
world proves the product. See [FORGE.md](FORGE.md). Milldale stays as the
fixture the engine is tested against and the fallback demo world.

| Day | Delivered |
|-----|-----------|
| 1 ✅ | Site live; staging live + authed; walls and both prose renderers, hand-verified |
| 2 ✅ | Engine core (load/validate, state, prompt assembly, clock); the LLM adapter; the Forge spec |
| 3 ✅ | Forge vertical slice → a validated world from answers; the chat turn; memory extraction |
| 4 ✅ | The forge as a **live web wizard** on staging; knowledge-walls pass; distinct interiors |
| 5 ✅ | **Sweeps and the time control** — the world lives without you, with divergent memories |
| 6 ✅ | Time from the person (quiet hours, per-visit pace); centrality; the protagonist pass |
| 7 ✅ | **The play view**: talk to someone, skip ahead, watch the world move |
| 8 ✅ | Sessions: per-visitor worlds, TTL, eviction, rate limits, the queue |
| 9 ✅ | **Review-and-reroll before launch**; the inspector; honest error states; phone polish |
| 10 | Buffer. Decide: keep it behind auth, or open it |

**Substance first, then the plumbing.** The forge, walls, chat, memory, sweeps
and the time control all exist and are verified against real worlds; day 8 added
what makes it survivable by strangers — a session per visitor, their own copy of
anybody else's world, honest rate limits, and a real queue in front of the one
model slot (`forge/web/session.php`, `limits.php`, `queue.php`).

Day 9 made it forgiving, and then made it **tunable**, which turned out to be the
more valuable half:

- `review.php` — every artifact the forge wrote, on one page, every text box
  editable (validated before it is saved, refused in English when it would not
  hold), and a ↻ on each section that re-runs *that* pass and nothing else.
  Rerolling one character rewrites one character. Then: launch.
- `why.php` — the inspector. For any character, the exact messages
  `xeric_prompt_build()` produces, split into the sections prompt.php assembles
  them from with a size on each (which is usually the answer to "why does she
  sound like that"). For any event, the sweep's decision trail: what could have
  happened here, what was tried, who was standing where, who was kept out and
  why. **That trail used to be thrown away the moment the decision was made**;
  `xeric_sweep_choose()` now returns it and the demo layer writes it into the
  world's own `world_state`, keyed by the event.
- Error states with no silence left in them: a global floor under every page,
  SSE refusals said in SSE, model failures translated out of log-speak, and a
  world that was let go explained rather than 404'd.

The web layer is a consumer of the engine, never a fork of it: nothing in
`engine/` knows that sessions, limits or queues exist, because a private install
on one person's machine has one visitor, one GPU and no line.

## What "working" means on day 10

A stranger with the staging password can:
- read who these people are and how they're connected,
- talk to one of them and be answered in a voice that references real history,
- press *skip to evening*, watch the world do something without them,
- and get a message about it, unprompted.

That's the whole pitch, running, on the author's own GPU.
