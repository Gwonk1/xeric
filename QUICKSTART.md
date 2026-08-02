# Xeric — the card you actually need

Everything to run, test, and stop the thing, in one page.

## The URLs

| What | Where |
|---|---|
| Public site | https://xeric.dev · https://www.xeric.dev |
| Staging (everything below lives here) | https://dev.xeric.dev — basic auth |
| **Forge a world** | https://dev.xeric.dev/forge |
| **Play a world** | https://dev.xeric.dev/forge/play.php |
| Review / edit / reroll | `…/forge/review.php?w=<slug>` |
| **The inspector** | `…/forge/why.php?w=<slug>` |
| Raw template JSON | `…/forge/world.php?w=<slug>` |

Staging is private (basic auth) and `noindex`. The public site is just the
landing page — nothing playable is exposed. The staging credential is not in
this repo and never will be: it lives in the `AuthUserFile` the staging vhost
points at, on the server, and in your password manager.

## Two variables, then everything below works

The host and the data dir are yours, not the project's, so nothing here bakes
them in. Set them once per shell:

```bash
export XERIC_HOST=<the ssh target that serves dev.xeric.dev>
export XERIC_DATA=/var/www/xeric-data   # worlds, sessions, queue — outside the docroot
```

`deploy.sh` reads the same two names, and refuses to run without `XERIC_HOST`
rather than guessing.

## Take your GPU back, instantly

The demo runs on your workstation and must never get in your way:

```bash
ssh "$XERIC_HOST" "sudo -u www-data touch $XERIC_DATA/queue.drained"
```

Every model endpoint immediately answers *"The machine's owner has taken the GPU
back for a while… nothing is lost."* In-flight work stops where it is and keeps
what it already produced. Undo it:

```bash
ssh "$XERIC_HOST" "sudo -u www-data rm -f $XERIC_DATA/queue.drained"
```

## Reading the inspector (the tuning tool)

`why.php?w=<slug>` is the index.

- `&h=<handle>` — **the exact prompt that character receives**, section by
  section with sizes. When somebody behaves oddly, look here first: it is
  usually not the model, it is that one section is crowding out another.
  Watch the bible size in particular — it is the biggest block and it grows
  with the cast.
- `&e=<event id>` — **why that event happened**: which kind was chosen and why,
  who was free, who was excluded and on what grounds, whether it sat on the
  secret's spine, how many groupings competed and at what weight.
- It also records why an hour produced *nothing*, which is the answer to "why
  is my world so quiet?"

## What a world has learned about you

`engine/learn.php`. A world writes down what you actually did — who you answered
and how long the answer was, whose message you left sitting, which hours you
walked straight past, and above all anything you **retyped by hand in the review
step**, which is the only correction in the system that is not a guess. A
periodic pass turns those into two things:

- **counting**, which needs no model and therefore always happens: it weights
  what kind of hour the sweeps produce and how often each person reaches out.
  Every weight has a floor — nothing is ever switched off, because a world that
  only does what you liked last week has stopped being able to surprise you;
- **a handful of short lessons in plain language**, which ride each character's
  system prompt. Capped and deduped, so the notebook can never become the prompt.

Where to look:

```bash
php engine/sweep-cli.php --world=worlds/<slug> --advance=6h     # "can happen here: rumor ×2, glimpse ×0.25"
php engine/sweep-cli.php --world=worlds/<slug> --advance=6h --no-learn
sqlite3 worlds/<slug>/world.db "SELECT kind,handle,subject,note FROM signals ORDER BY id DESC LIMIT 20"
sqlite3 worlds/<slug>/world.db "SELECT handle,value FROM arcs WHERE key='learn.lessons'"
```

In the demo it runs off the back of a skip, while the tick already holds the GPU.

## Where things live

| | |
|---|---|
| Code (local) | this checkout — 7 test suites, all green, all local commits |
| Deploy the web app | `bash forge/web/deploy.sh` |
| Worlds + sessions (server) | `$XERIC_DATA/` (www-data) |
| Worlds (local, from the CLI) | `worlds/<slug>/` — gitignored, and they are real worlds with real names in them |
| Local model | `127.0.0.1:8080` on the workstation · `127.0.0.1:18080` on the server (the far end of the tunnel) |

## Doing it from the command line

```bash
cd <this checkout>

# forge a world (2-4 min on the local model)
php forge/forge-cli.php --local --surprise \
    --answers='name=Vera,job=run the night desk,motivation=find out who keeps leaving keys'

# talk to somebody
php engine/chat-cli.php --world=worlds/<slug> --speaker=<handle> --say="..."

# skip time and see what happened without you
php engine/sweep-cli.php --world=worlds/<slug> --advance=6h

# everything still works? all seven suites, no exceptions — learn-test.php is
# the one guarding the pass that can write text into a live world's prompts.
for t in render engine chat sweep learn; do php engine/tests/$t-test.php; done
php forge/tests/forge-test.php && php forge/web/tests/demo-test.php
```

## The rules this project runs on

- **Nothing is pushed to GitHub without your say-so.** 28+ commits sit on local
  `main`; the remote is private and empty of them.
- **The demo never starves your machine** — hence the drain flag above.
- **The engine is world-agnostic**; anything that exists only because strangers
  share a GPU lives in `forge/web/`, never in `engine/`.
- Worlds you forge on staging belong to your browser session (7 days idle).
  The pre-existing example worlds are shared and never expire; playing one forks
  your own copy rather than changing it.

## When you find something

Tell me and it goes in `PUNCHLIST.md`. That is the standing practice — nothing
gets lost between "I noticed" and "I have time."
