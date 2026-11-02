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
8080, 1234, 5000, 8000, 4891). If yours is somewhere else — a GPU box on the
LAN, an ssh tunnel, llama.cpp bound on another host — point it there:

```bash
./xeric --llm http://192.168.1.50:8080
./xeric --llm http://gpu.example.com:8080 --llm-key SECRET   # llama-server --api-key
export XERIC_LOCAL_BASE=http://192.168.1.50:8080            # same thing, sticky
```

A trailing `/v1` is stripped, so the URL you copied from an OpenAI-compatible
tutorial works. The machines screen in the UI also takes an address.

On the llama.cpp side, bind all interfaces if Xeric is not on the same box:

```bash
llama-server --host 0.0.0.0 --port 8080 --api-key SECRET -m model.gguf
```

Router mode (no `-m`, several GGUFs behind one address) needs a model id, which
the machines screen offers as a list once it sees GET `/models` with a lifecycle
status. Same thing from the shell:

```bash
./xeric --llm http://192.168.1.50:8080 --llm-model Qwen3-8B-Q4_K_M
export XERIC_LOCAL_MODEL=Qwen3-8B-Q4_K_M
```

## Your first world

Open the wizard, answer as much or as little as you like — every question has a
✨ that fills it in for you, and *start blank* is a legitimate answer to all of
them. Two to four minutes on a local model and you have a cast, the places they
haunt, their weeks, and their secrets.

Then **review it before you launch it.** The review page shows every section
with a reroll button, 159 editable fields, and every knowledge wall written out
in plain English — because a wall the forge got wrong is a secret told to the
wrong person, and you are the only one who can catch that.

### Seeing the room

The play view can show you the room you are standing in — a 360° panorama you
drag to look around, or a still — once there is a machine to draw it. Choose
one on **the machines screen** (the *Scene machine* card), or set it and skip
the screen:

```bash
XERIC_SCENE_BASE=http://127.0.0.1:9100   # anything that speaks POST /v1/scenes
XERIC_SCENE_KIND=generic                 # or image
XERIC_SCENE_AUTH=x-api-key               # where the key goes; default bearer
XERIC_SCENE_KEY=...                      # environment only, never stored
```

No scene machine and an image machine already set up? The viewport falls back
to it and gives you **a still of the room from where you stand**. Neither, and
there is simply no view — nothing breaks. Press **look** in the sidebar and the
room is asked for; a second look in the same light with the same people is the
same picture, not a second render. The request format, and how to put a proxy
in front of a panorama service, is in [`docs/VIEWPORT.md`](docs/VIEWPORT.md).

**Hearing it.** Under the view is a line saying what the room sounds like. When
the scene machine can make sound as well (an image machine cannot), a
**listen** toggle plays it. It never plays on its own. Turn a world's sound off
with `media.scene.sound.enabled: false`. On a local machine the heart also draws
the room you are in ahead of time; on a metered one it draws nothing unless the
template sets `media.scene.prerender`.

**The voices.** Any text-to-speech server that answers `POST /v1/audio/speech`
(Kokoro-FastAPI on your own machine, or a hosted one) gives every character a
voice of their own:

```bash
XERIC_VOICE_BASE=http://127.0.0.1:8880   # the speech server
XERIC_VOICE_MODEL=tts-1                  # the default
XERIC_VOICE_FORMAT=mp3                   # or opus, wav
XERIC_VOICE_KEY=...                      # environment only, never stored
```

Each person is dealt a voice from their name and keeps it. Pick one yourself
with `speech.voice` on the character ([`docs/VOICE.md`](docs/VOICE.md)).

**The ears.** Any speech-to-text server that answers
`POST /v1/audio/transcriptions` (a local Whisper server, or a hosted one)
puts a **hold to talk** button beside the composer. What you said lands in
the box for you to read and send; the recording is never stored.

```bash
XERIC_EARS_BASE=http://127.0.0.1:8090    # the transcription server; unset is no button
XERIC_EARS_MODEL=whisper-1               # the default
XERIC_EARS_LANGUAGE=en                   # optional; empty lets the machine tell
XERIC_EARS_KEY=...                       # environment only, never stored
```

**Talk to the room.** Where two or more of the cast are standing with you, the
view offers *talk to the room*: say something, and the people there answer one
at a time — or don't, and the room may carry on a line or two without you.

**Put on a headset.** On the machine Xeric runs on, open `link.php`, name the
device and press *Make a code*; on the headset, open `stand.php?w=<slug>`,
press **link** and type the eight characters within five minutes (on `--lan`
the link page also shows the code as a QR). The stand page is the room and
nothing else: press a name to turn to them, hold to talk, *step inside* for
the headset. WebXR and the microphone need a secure context — https, or a
page on this machine — so plain `--lan` http gets the 2D view with neither;
Quest Link, `adb reverse` and `tailscale serve` are the three setups that
work. See [`docs/STAND.md`](docs/STAND.md).

**Set a budget.** A world's template may carry a ceiling per world-day:

```json
"budget": { "tokens_per_day": 200000, "renders_per_day": 20, "clips_per_day": 0,
            "hearings_per_day": 0, "metered_only": true }
```

Every default is 0, and 0 means no ceiling, so a world without the block
behaves as before. `metered_only` (on by default) counts only machines that
bill — a remote model, scene, voice or ears machine — and never rations your
own electricity unless you set it false. A day is the world's date, not the
wall's. Out of tokens, the heart stops living hours where it ran out; out of
renders, rooms wait. See [`docs/BUDGET.md`](docs/BUDGET.md).

**Ask the narrator.** The narrator is the chip and the row at the end of the
cast in the sidebar. It sees the whole world with no walls, so you can ask it
*why*: why nothing happened last night, why she keeps bringing up the
ledger. It answers straight about how the world works and will not tell you
how a story ends, where something hidden is, or anybody's secret. One
question is one model call, counted as a message, and the last few
exchanges are kept on the narrator's pane. Only the owner of the world can
ask; a guest on a pairing code is told no. On the same pane you can write a
beat you want to happen (*Ruth and Walt at the Bluebird this week*). The
sweeps lean towards it but are not forced, and a beat that happens is struck
through. `why.php?w=<slug>&audit=1` is the narrator's audit: dropped
threads, people who have gone missing, contradictions. Reading it costs
nothing. The button that reads the threads with the model is metered like a
message. See [`docs/NARRATOR.md`](docs/NARRATOR.md).

**Turn on the Director.** The Director is off until a world's template says
otherwise. When it is on, it puts two or three people in one room for an
hour, so the encounter is already there when you walk in. It never decides
what they say there. Pick the rules you want:

```json
"director": { "rules": ["narrator", "apart", "player"], "per_day": 2,
              "apart_days": 7, "player_gap": 2, "minutes": 60 }
```

- `narrator` stages a narrator beat that names two or more people.
- `apart` brings together two people of one circle who have gone
  `apart_days` without an hour together.
- `player` brings together two people whose trust in you has drifted
  `player_gap` or more apart.

`per_day` caps the stagings per world-day for all rules together; the
default is 2. A staging lasts `minutes`, at most 120. It never moves the
dead, never takes anybody off a shift marked or read as unmissable, never
seats a minor in a room that serves drink, and moves nobody when fewer than
two can come. The inspector's *what the director has staged* says why each
one happened, and you can cancel one there. See
[`docs/DIRECTOR.md`](docs/DIRECTOR.md).

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

# the test suites — all of them, no exceptions; stops at the first that fails
for t in engine/tests/*-test.php forge/tests/*.php forge/web/tests/*.php; do php "$t" || { echo "FAILED: $t"; break; }; done

# two of those need something besides PHP, and say SKIP (exit 0) without it:
#   smoke-test.php  starts a throwaway server, loads every page in headless
#                   Chrome, and fails on any uncaught javascript. Needs chrome,
#                   chromium or chrome-headless-shell; XERIC_CHROME names one.
#   viewer-test.php runs the play view's panorama, headset, day-strip and
#                   speaker JavaScript in Node against a fake DOM and WebGL.
# A SKIP line is not a pass: CI sets XERIC_REQUIRE_BROWSER=1 and
# XERIC_REQUIRE_NODE=1 so a missing tool fails there instead.
XERIC_CHROME=/path/to/chrome-headless-shell php forge/web/tests/smoke-test.php
```

In a container with no D-Bus session bus (some sandboxes), full Chrome starts
and never dumps a page whatever flags it is given; the smoke test notices in
twenty seconds and says so. `chrome-headless-shell` does not need the bus.
The one demo-test check that needs real DNS says SKIP when offline.
CONTRIBUTING.md has the rest.

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
