# Xeric — the card you actually need

Everything to start it, forge a world, and look inside it, in one page.

## What you need

- **PHP 8.1 or newer** on your PATH, with `sqlite3`, `pdo_sqlite`, `mbstring`,
  `json` and `curl`. `bootstrap.php` checks all of it and names anything missing.
- **A model.** Either something running locally that speaks the OpenAI chat API
  — llama.cpp's server, Ollama, LM Studio — or an API key you bring at the time
  you use it. Nothing is stored either way.

A 12B is the floor for forging and it works. Bigger is better and slower; the
runtime, once a world exists, is comfortable on small models because the prompt
is engineered to cache.

## Start it

```bash
./xeric               # starts on 127.0.0.1:8787 and opens a browser
./xeric --port 9000   # somewhere else
./xeric --data ~/worlds   # keep your worlds somewhere else
./xeric --no-open     # do not touch the browser
```

On Windows, `xeric.cmd` takes the same arguments.

There is **no config file**. Every decision — which PHP, which port is free,
where the data lives — is made in `bootstrap.php` and passed as environment,
so a local install has nothing to write, hand-edit, or get out of date.

`./xeric` also starts **the heart** — one pass of `forge/web/heart.php` every 60
seconds, which is what makes your worlds live through the hours you are not
watching. Set `XERIC_HEART_EVERY` to change the interval. If you would rather
run it yourself, it is one pass and exit, so a crontab line does the same job:

```cron
* * * * * cd /path/to/xeric && php forge/web/heart.php >> heart.log 2>&1
```

It will not run a paused world, will not take the model from somebody who is
mid-conversation, and will not try to live a month-long gap in a single tick.

The app finds a local model by probing the ports people actually use (11434,
8080, 1234, 5000, 8000, 4891). If yours is somewhere else, the machines screen
in the UI takes an address, or set it and skip the probe.

## Your first world

Open the wizard, answer as much or as little as you like — every question has a
✨ that fills it in for you, and *start blank* is a legitimate answer to all of
them. Two to four minutes on a local model and you have a cast, the places they
haunt, their weeks, and their secrets.

Then **review it before you launch it.** The review page shows every section
with a reroll button, 159 editable fields, and every knowledge wall written out
in plain English — because a wall the forge got wrong is a secret told to the
wrong person, and you are the only one who can catch that.

## Reading the inspector — the tuning tool

`why.php?w=<slug>` is the index, and it is the thing to reach for whenever the
world does something you did not expect.

- `&h=<handle>` — **the exact prompt that character receives**, section by
  section with sizes. When somebody behaves oddly, look here first: it is
  usually not the model, it is that one section is crowding out another. Watch
  the bible size in particular — it is the biggest block and it grows with
  the cast.
- `&e=<event id>` — **why that event happened**: which kind was chosen and why,
  who was free, who was excluded and on what grounds, whether it sat on the
  spine of a secret, how many groupings competed and at what weight.
- It also records why an hour produced *nothing*, which is the answer to
  "why is my world so quiet?"

## What a world learns about you

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

```bash
sqlite3 <data>/worlds/<slug>/world.db \
  "SELECT kind,handle,subject,note FROM signals ORDER BY id DESC LIMIT 20"
sqlite3 <data>/worlds/<slug>/world.db \
  "SELECT handle,value FROM arcs WHERE key='learn.lessons'"
```

## From the command line

Everything the browser does has a CLI behind it.

```bash
# forge a world (2-4 min on a local model)
php forge/forge-cli.php --local --surprise \
    --answers='name=Vera,job=run the night desk,motivation=find out who keeps leaving keys'

# talk to somebody
php engine/chat-cli.php --world=worlds/<slug> --speaker=<handle> --say="..."

# skip time and see what happened without you
php engine/sweep-cli.php --world=worlds/<slug> --advance=6h
php engine/sweep-cli.php --world=worlds/<slug> --advance=6h --no-learn

# the test suites — all of them, no exceptions
for t in render engine chat sweep learn narrator constructs rewind; do php engine/tests/$t-test.php; done
php forge/tests/forge-test.php && php forge/web/tests/demo-test.php && php forge/web/tests/review-test.php
```

## Running it where other people can reach it

`forge/web/deploy.sh` puts the web app behind a real web server. It is
parameterized and bakes in no host: set `XERIC_HOST` to an ssh target and
`XERIC_DATA` to a directory outside the docroot, and it refuses to run rather
than guess.

Three things to know before you do:

- **The data directory must be outside the docroot.** Worlds hold real people's
  names, and `owner.json` holds a session token.
- **Give every host its own `XERIC_DATA`.** Sharing one between a private shelf
  and a public one is how a world with your own name in it ends up on a page
  strangers can open.
- **`touch $XERIC_DATA/queue.drained` takes the GPU back instantly.** Every
  model endpoint starts answering with a human sentence, in-flight work stops
  where it is and keeps what it already produced, and `rm` puts it back.

The web app's session, queue and rate-limit layer exists for exactly this case —
strangers sharing one GPU. Running locally, it costs you nothing and does
nothing.

## The rules this project runs on

- **The engine is world-agnostic.** Anything that exists only because strangers
  share a GPU lives in `forge/web/`, never in `engine/`.
- **A world is a file you own.** The template, the database, and everything your
  cast ever said are yours. Nothing phones home.
- **API keys are never stored.** A key you bring is posted with the request that
  uses it and lives in one PHP process for the life of that request.
- **Worlds you forge belong to you.** On a shared instance they belong to your
  browser session; playing somebody else's forks your own copy rather than
  changing theirs.
