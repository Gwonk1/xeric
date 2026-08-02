# The Forge — world creation, the product's front door

The user arrives with nothing. Twenty minutes later they have a world that
feels like it has been running for months. Everything between those two points
is the Forge.

This is the thing nobody else ships. Chat frontends hand you a blank character
card; Xeric asks you a handful of questions and *builds a place*.

## Principles

1. **Every question has three doors.** Answer it, customize it, or press
   ✨ *surprise me*. A user who does not know what they want must be able to
   hold down "surprise me" and still get a coherent world — the defaults are
   the product, not a fallback.
2. **The forge is the one place a big model earns its money.** World creation
   is a handful of expensive calls, once. Daily life then runs on a small
   local model. So: bring your own API key (any OpenAI-compatible endpoint,
   Anthropic, OpenRouter) *or* use the local model and wait a bit longer. No
   account, no service dependency, no subscription.
3. **Review before launch, always.** The forge proposes; the user disposes.
   Every generated artifact — the town, each place, each person — is editable
   before the world starts, and skippable for anyone who wants to just go.
4. **A world may start mid-story — or genuinely blank.** Both work, and the
   choice belongs to the user.

   *Seeded:* the last forge pass writes history — what these people already
   did, owe, remember, and resent — so day one does not feel like day one.
   Best for a demo, or for someone who wants to walk into something already
   in motion.

   *Blank:* no memories, no backstory, nothing but people, places, and the
   rules of the world. **This is the empirically proven path** — the world
   this engine was extracted from was created exactly this way and built
   itself over months (owner, 2026-07-30: "I created my world with no
   memories, no backstory, and it worked well and built itself"). Everything
   that world eventually knew, it learned by living. A blank start is also
   cheaper and faster to forge (one less pass) and carries zero risk of
   invented history contradicting what the user later does.

   The engine is built for accretion either way: memories come from
   extraction, events from sweeps, standings from what actually happens. Seed
   history is a *head start*, never a requirement — and a seeded world and a
   blank one converge after a few weeks of real use.

   Design consequence: the interview asks. "Start blank and let it grow" is a
   first-class answer, not a degraded one, and it should probably be the
   default for anyone who says they want to discover their world.
5. **Nothing generated is load-bearing until it validates.** Every pass output
   goes through `xeric_world_load()`'s validator before the user sees it. A
   model that invents an orbit that doesn't exist gets corrected, not shipped.

## The interview

Data-driven (`forge/interview.json`) so questions can be edited without code.
Each step: a question, an optional set of preset answers, free text, and
"surprise me". Steps can branch on earlier answers.

| # | Step | Asks | Feeds |
|---|------|------|-------|
| 0 | **Model** | BYO API key + endpoint, or local model | which engine forges |
| 1 | **Scale** | small town · a city · the world stage · somewhere invented | setting.locale, place count, orbit shapes |
| 2 | **You** | your name, what you do, your hours | user.*, the orbit bound to your work |
| 3 | **Theme** | ordinary life · intrigue · faith · money · frontier · academia · crime · showbiz · invented | texture, canon rules, mood axis wording |
| 4 | **Why you're here** (motivation) | company · romance · ambition · mystery · redemption · survival | **which systems arm** (see below) |
| 5 | **Who's around you** | living family · found family · coworkers · congregation · crew · strangers | orbits + special_roles |
| 6 | **The shape of it** | open-ended · a definite ending you're moving toward | arc + ending conditions |
| 7 | **Boons** | is there something to chase? (a job, a seat, a secret, an inheritance) | economies + boons |
| 8 | **Edges** | anything you never want in your world | hard exclusions, honored by every pass |
| 9 | **Rating** | keep it clean · adult themes · no limits | template ceiling (model still governs) |

**"AI generate" button:** answers every unanswered step at once with a
coherent set — not random per-field, but one drawn concept, so a "small town +
faith + found family" world doesn't collide with "world stage + crime".

## Motivation → which systems arm

This is the single most important mapping in the product. The same engine is a
different thing depending on it:

| Motivation | Armed | Disarmed |
|---|---|---|
| **Company** (the elderly-user case) | daily rhythms, visits, shared meals, remembering, gentle proactive contact | rivals, jealousy, unreliable witnesses |
| **Romance** | attraction, arcs, jealousy, private history | — |
| **Ambition** | standings, favors, rivals, boons, the ladder | — |
| **Mystery** | the strange place, rumors, unreliable witnesses, a slow reveal | — |
| **Redemption** | a debt, people who remember, a chance to be different | — |
| **Survival** | scarcity, danger, alliances that cost | comfort systems |

A world may arm several. The forge picks defaults from motivation + theme; the
user can toggle any of them in the review step.

## The passes

Each pass is one model call (or a few), validated, cheap to retry. Ordered so
later passes see earlier output.

| Pass | Produces | Notes |
|---|---|---|
| 1. **Concept** | name, locale, era, texture, canon_rules, mood axis + motifs | the world's voice; everything downstream quotes it |
| 2. **Places** | ~15 places: kind, hours, aliases, commons description, which are late-night, which are yours | the user's workplace is pinned from step 2 |
| 3. **Structure** | orbits, circles, how they overlap, the user's position in each | small worlds get 2-3 orbits, cities get 5+ |
| 4. **Cast** | N characters: name, one_line, surface, voice, week, psyche, tells, drives, limits | one call per character, in parallel where the endpoint allows |
| 5. **Ties** | friendships, roommates, rivalries, attraction seeds, who-knows-whom | needs the whole cast in hand — a barrier pass |
| 6. **Economies** | counters, earn rules, boons, ground truth | armed set from motivation |
| 7. **Walls** | who must never know what; special_roles with own_bible | **the safety-critical pass** — generated, then validated, then shown to the user in plain language |
| 8. **Seed history** | 2-6 weeks of backfill: events, memories per character, arcs, favors owed | what makes minute one land |

Between passes the UI shows what was made and offers *keep · tweak · reroll*.

## Failure discipline

- A pass that returns unparseable JSON is retried once with the parse error fed
  back, then falls back to a hand-written template default for that section.
  A world must always be launchable.
- Validation errors are shown as plain English ("Ruth works at the mill, but
  there is no mill — add one or move her?") with fix buttons, never raw JSON.
- The local model is slower and dumber than a frontier model; the forge must
  produce an acceptable world on a 12B. Prompts are written to that floor,
  and every pass has a deterministic fallback.

## What ships first (the vertical slice)

Not all nine steps and eight passes at once. First working version:

1. Interview steps 1, 2, 4, 9 + the ✨ surprise-me path
2. Passes 1 (concept), 2 (places, 6 not 15), 4 (cast, 4 people), 8 (light seed)
3. Validate → write `worlds/<slug>/world-template.json` → launch into the
   existing engine
4. Review UI: one page showing what was made, with reroll-per-section

Everything else is depth on a working spine.
