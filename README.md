# Xeric

**A living world that runs on your own machine — and doesn't wait for you.**

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
is a world you can talk to and skip time inside. Nine test suites, all green.

Start with [`QUICKSTART.md`](QUICKSTART.md) — what you need, how to start it,
and how to read the inspector when something surprises you.
[`ROADMAP.md`](ROADMAP.md) is what is next and, more usefully, what is still
missing. [`docs/WORLD_TEMPLATE.md`](docs/WORLD_TEMPLATE.md) is the template
schema, worked end to end against `engine/fixtures/milldale.json` — the
fictional world the engine is tested with.

## Architecture (the short version)

| Piece | What it does |
|---|---|
| **world-template.json** | The world as data: cast, places, orbits, economies, walls, mood physics. Forgeable, shareable. |
| **user.json** | You: name, timezone, work hours, motivation, goals. The world builds around your actual life. |
| **engine** | PHP + SQLite + cron sweeps. Prompt assembly, event generation, proactive contact, photo pipeline, temperature physics. |
| **setup forge** | A one-time pass by a capable model (remote or local) that writes your world. Runtime then runs on small local models. |
| **story overlay** | An optional mystery laid over a finished world as a sidecar file: beats, who is wrong about what, and an ending the world survives. |
| **media** *(planned)* | Optional image generation (identity-stable characters via seed + appearance anchoring), optional overnight video. The template already carries the fields — `photos.enabled`, `face_seed` — but no pipeline ships in this tree. |

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
