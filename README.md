# Xeric

**A living world that runs on your own machine — and doesn't wait for you.**

**[xeric.dev](https://xeric.dev)** · [the whitepaper](https://xeric.dev/paper.html) · [terms](https://xeric.dev/terms.html)

Xeric is a local-first world engine for language models. You describe who you
are and what you want your world to be; a one-time setup forge builds a cast
of characters, the places they haunt, their friendships, grudges, schedules,
and secrets — then the world *runs*, on your hardware, on your clock:

- Characters live **between your visits**. Nights out happen while you sleep.
  Shifts get covered. Two of them talk about you when you're not there.
- They **text you first** — because something happened, because they dreamed,
  because it's been three days and one of them noticed.
- The world has **moods, economies, and rules**: favors owed, standings nobody
  says out loud, a place at the edge of town the stories are wrong about.
- Everyone remembers. Continuity is the whole product: outfits, promises,
  running jokes, who knows what about whom — enforced by typed knowledge
  walls, not by hoping the model remembers.
- A **story can be laid over a living world** — a mystery, a debt, a
  disappearance. It points what the cast already knows at one question,
  builds, goes deliberately quiet, breaks, and resolves. Then it is over and
  the world keeps going, the same people who simply no longer have that
  question between them.

No cloud dependency at runtime. No subscription. Your world is a JSON file
you own and a database on your disk. Content rating is carried by the world,
defaults to the mildest, and anything above it requires an affirmed adult
session.

> *Xeric* — of a dry, barren place — is what your computer is the day before
> you install this.

## Status

**Beta.** The engine runs end to end: the forge builds a world from an
interview, the review step lets you edit or reroll any of it, and the play view
is a world you can talk to and skip time inside. Forty test suites, about 4,400
assertions, run on PHP 8.1 and 8.3 on every push ([`.github/workflows/tests.yml`](.github/workflows/tests.yml));
how to run them yourself is in [`CONTRIBUTING.md`](CONTRIBUTING.md), and what is
known and not yet fixed is in [`docs/PUNCHLIST.md`](docs/PUNCHLIST.md).

Start with [`QUICKSTART.md`](QUICKSTART.md) — what you need, how to start it,
and how to read the inspector when something surprises you.
[`ROADMAP.md`](ROADMAP.md) is what is next and, more usefully, what is still
missing. [`docs/WORLD_TEMPLATE.md`](docs/WORLD_TEMPLATE.md) is the template
schema, worked end to end against `engine/fixtures/milldale.json` — the
fictional world the engine is tested with.
[`docs/VIEWPORT.md`](docs/VIEWPORT.md) is the viewport: what the engine tells a
machine about the room you are standing in, and what it never will.
[`docs/VOICE.md`](docs/VOICE.md) is how the cast is spoken aloud, and why a
voice is never chosen by gender.
[`docs/CONTINUITY.md`](docs/CONTINUITY.md) is the continuity benchmark: the
fixture world lived through three days and scored, and how to read it.
[`docs/API.md`](docs/API.md) is the client API's contract, with a `curl` per
route. [`docs/STAND.md`](docs/STAND.md) is the stand page: linking a headset,
the secure-context rule, and what each headset can do.
[`docs/BUDGET.md`](docs/BUDGET.md) is the per-world budget: a ceiling per
world-day on tokens, renders, clips and hearings.
[`docs/DIRECTOR.md`](docs/DIRECTOR.md) is the Director: who it may put in a
room, the rules a template opts into, and what it refuses.
[`docs/SLEEP.md`](docs/SLEEP.md) is sleep: how old memories become facts with
their sources kept, and why the prompt gets shorter, not longer, as a world
ages.

## A model that is not on this machine

Runtime talks the OpenAI chat API. llama.cpp on `127.0.0.1:8080` is still the
default; an external llama.cpp is a URL:

```bash
./xeric --llm http://192.168.1.50:8080
./xeric --llm http://gpu.example.com:8080 --llm-key SECRET
./xeric --llm http://192.168.1.50:8080 --llm-model Qwen3-8B-Q4_K_M   # llama.cpp router
export XERIC_LOCAL_BASE=http://192.168.1.50:8080   # same, for CLI and the heart
```

A trailing `/v1` is stripped. See [`QUICKSTART.md`](QUICKSTART.md).

## Architecture (the short version)

| Piece | What it does |
|---|---|
| **world-template.json** | The world as data: cast, places, orbits, economies, walls, mood physics. Forgeable, shareable. |
| **user.json** | You: name, timezone, work hours, motivation, goals. The world builds around your actual life. |
| **engine** | PHP + SQLite + cron sweeps. Prompt assembly, event generation, proactive contact, photo pipeline, temperature physics. |
| **setup forge** | A one-time pass by a capable model (remote or local) that writes your world. Runtime then runs on small local models. |
| **narrator** | The other god of the world: full canon, no walls, discreet about the story. In the play view you can ask it *why*; its write-ahead beats lean the sweeps without ever entering a prompt; its investigate is in the inspector. Author is not built. See [`docs/NARRATOR.md`](docs/NARRATOR.md). |
| **director** | Changes the set, never the performance: stages two or three people in one room for up to two hours, so an encounter is there when you walk in. Off until the template opts in, rule by rule, capped per day. See [`docs/DIRECTOR.md`](docs/DIRECTOR.md). |
| **sleep** | Once a world-night, a character's old memories become a few facts with their sources kept and their walls inherited; nothing is deleted and the bible is never touched. See [`docs/SLEEP.md`](docs/SLEEP.md). |
| **story overlay** | An optional mystery laid over a finished world as a sidecar file: beats, who is wrong about what, and an ending the world survives. |
| **client API** | `forge/web/api.php`: the world as data and the verbs, versioned (`v1`), with a pulse to poll and per-device tokens. Its first client is the stand page, `forge/web/stand.php` — nothing but the room, for a headset first and any browser second. See [`docs/API.md`](docs/API.md) and [`docs/STAND.md`](docs/STAND.md). |
| **media** | Photo composition (identity-stable characters via seed + appearance anchoring); the viewport — the room you stand in, as a panorama or a still, with its sound, or a splat you walk inside in our own WebGL viewer; the drawn map, the town from above beside the room from inside; a voice for every character, dealt from a seed; and ears — hold to talk, transcribed into the composer for you to send. All composed in code and dormant until you choose a machine; no machine, no renders, no errors. |

## License

**GNU Affero General Public License v3.0** — see [`LICENSE`](LICENSE).

Copyright (C) 2026 Mr. Gwonk.

Xeric is free software: you can redistribute it and/or modify it under the
terms of the AGPL as published by the Free Software Foundation, either
version 3 of the License, or (at your option) any later version. It is
distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.

> **Why AGPL, and what section 13 means here.** Xeric is built to run on your
> own machine, but it *is* a web application — if you run a modified copy
> where other people can reach it over a network, section 13 obliges you to
> offer them its source. That is deliberate: the local-first promise is worth
> nothing if a hosted fork can close it. Your **world** is not the software —
> the template, the database, and everything your cast ever said are yours,
> and no license here reaches them.
