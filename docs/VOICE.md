# Voices — the cast, out loud

Everything else this engine makes is read: a thread, a caption standing where a
photograph would, a room described for a machine that can draw it.
`engine/voice.php` gives every person in the world a voice of their own on
whatever text-to-speech machine the owner chose, and turns one of their lines
into audio. It is the same voice on Tuesday as on Monday, and the same line
costs one render however many times it is played. With no machine it is
dormant and costs nothing: no machine, no clips, no errors.

---

## What is spoken

**What was shown, and nothing else.** The words are the line already in the
thread, less what a voice cannot say (`xeric_voice_text`):

- **the photo caption**: a line that begins with the camera or with `photo:`,
  and a `[photo: …]` marker anywhere. A caption is a picture's stand-in, and
  reading it aloud would be a narrator nobody hired.
- **a parenthesis or a bracket that is a whole line on its own.** One inside a
  line is a person talking (*See you at six. (Bring the pie.)*) and is spoken.
- **links.** A markdown link keeps its words; a bare address is not speech.
- **markdown emphasis**, the markers only. The words stay.
- **a single-asterisk span that stands where a sentence would**, at the start of
  a line or after a full stop and followed by an end or a new sentence. That is a
  stage direction in every chat dialect the cast was trained on. A span *inside* a
  sentence is emphasis and keeps its words, and so does one followed by a
  lowercase word (*\*Not\* now.*). The rule leans toward speaking: reading out a
  stray "nods" is a hiccup, while dropping a "not" says the opposite of what she
  said.
- **pictographs.** A voice reading "red heart" is worse than one that says
  nothing.

Then the whitespace collapses and the line is capped at 1,200 characters
(`XERIC_VOICE_MAX_CHARS`), **cut on a sentence**, so it sounds like somebody who
stopped talking and not like a recording that broke. A message that is nothing
but stage direction (*\*nods\**, *(she's typing)*, *...*) says nothing. It is
never sent anywhere.

**The speech layer has no rating of its own.** The rating decision was made
when the line was written (chat.php's gates, the age floor, the world's
effective rating), and the voice adds nothing to it and takes nothing from it.
That is why voice.php has no minor check: a voice reading words that were
already allowed cannot make them less allowed, and the deal reads no age, so it
has no opinion to give.

---

## How a voice is dealt

A character's `voice` field is **prose**, a style guide the model writes to
(*short sentences, warm ones, and a long pause before the sentence that actually
matters*). The speech layer never reads it. A timbre guessed from adjectives
would be the engine deciding what "warm" sounds like, and it would be the same
guess for everybody ever described that way. The spoken voice is an ID on a
machine, and there are exactly two ways to get one:

- **The owner names it.** `speech.voice` on a character or a fixture names a
  voice by its name *on that machine*: `nova`, `af_bella`, a Kokoro blend like
  `af_bella+af_sky`. It is matched exactly, then without regard to case, and a
  blend only when every part is on the machine. It wins whenever the machine has
  it, and also whenever the machine cannot say what it has, because then the
  owner knows the machine better than the engine does. `speech.speed` rides
  beside it, default 1.0, clamped to 0.7–1.3. Past either end a voice stops
  sounding like a person talking and starts sounding like a tape doing
  something.
- **A seed deals it.** Otherwise the voice is derived, never stored, from the
  engine's one seed (`xeric_photo_seed(world, 'voice', handle)`), so the same
  person draws the same voice on every call and every surface. There is no row
  and no sidecar for two of them to disagree in. A fixture that is somebody
  (`same_as`) speaks in that somebody's voice.

**The deal is rendezvous hashing, not `seed % count`** (`xeric_voice_deal`).
Every voice in the pool is scored against the person's seed, and the highest
score wins. A modulo would re-deal *everybody* the day the machine gained or lost
one voice (an owner installs a pack, or Kokoro ships a point release), and the
whole town would wake up sounding like strangers. Highest-score moves only the
people whose best match is the new voice. It is also blind to the order a
machine lists its voices in, which no API promises to keep.

**Nothing about a person picks their voice but their name.** Not their
pronouns, not their age, not the prose `voice`, and not the gender labels a
machine's inventory may carry, including the ones spelled into the names
themselves (Kokoro's `af_` and `am_`). The project's rule is that the engine
writes no gendered prose of its own, because pronouns are template data. A
timbre chosen *from* pronouns would break that rule in audio: the engine
deciding what a "she" sounds like, and deciding it the same way for every she in
every world. A seed cannot be wrong about anybody in a way that says something
about them. An owner who cares picks by name; the labels ride along on the
inventory so a picker can show them to the *owner*, and the deal never reads
them.

**The one thing the deal does filter by is language.** The pool is the voices
in the world's `meta.language` (`en` when unsaid) or in no stated language. If
none match, the pool is the whole inventory, because something speaking beats
nothing. If the machine lists no voices at all, the pool is the six names every
OpenAI-compatible server answers to (`alloy`, `echo`, `fable`, `onyx`, `nova`,
`shimmer`). An English line handed to a Japanese voice is not casting, it is
noise, and a language belongs to the world, not to the person. For a
Kokoro-shaped id, the first letter is the one part of a voice's name that is
read.

---

## The wire

One call, the one that local servers and hosted providers both already answer:

```
POST {base}/v1/audio/speech
Authorization: Bearer <XERIC_VOICE_KEY>
{ "model": "tts-1", "input": "Morning.", "voice": "nova",
  "response_format": "mp3", "speed": 1.0 }
```

The answer is the audio itself, with a `Content-Type`. A non-2xx status is a
refusal, and the machine's own words ride on the error (*voice not found* is
the one an owner needs to read). A 200 whose type is JSON or HTML is an error
page wearing a success code, never an mp3. Redirects are not followed, and the
key is filtered for line breaks before it goes into a header.

The inventory is asked at `GET {base}/v1/audio/voices`, then
`GET {base}/v1/voices`, in whatever shape the machine answers: a list, a
`{voices}` or `{data}` wrapper, a map of id to details. An empty inventory is
not a failure; the machine still speaks, and the deal falls back to the six.

| Variable | |
|---|---|
| `XERIC_VOICE_BASE` | the machine's address. Unset, and nothing speaks. |
| `XERIC_VOICE_KEY` | **environment only.** Never in a row, the fingerprint, the meter or an error message. |
| `XERIC_VOICE_MODEL` | default `tts-1`, the name every compatible server answers to. |
| `XERIC_VOICE_FORMAT` | `mp3` (default), `opus` or `wav`. Anything else is the default, not an error. |

The machines screen's resolver is read first, as it is for scenes, and
"explicitly none" there stops before the environment. **Nothing stands in past
the environment.** A picture machine cannot speak, and a model machine that
happens to serve `/v1/audio/speech` is still not a machine anybody chose for it.
Costly versus local is the same one bit as photos and scenes: loopback costs
nothing, and anything else is assumed to bill.

---

## Caching

**Keyed by what it sounds like.** A clip's fingerprint is a sha1 of the voice,
the speed, the model, the format and the spoken words, and of nothing else
(`xeric_voice_fingerprint`). It does not include the person, because two people
dealt the same voice saying the same words make the same sound. It does not
include the machine's address, so moving a machine to another port re-renders
nothing. It never includes the key.

`voice_clips` holds one row per (message, fingerprint). Two messages that both
say "Morning." in the same voice share one file, but each message gets its own
row so a thread can ask after any line by id (`xeric_voice_of`), including
while the machine is away. A clip whose file has gone from disk is rendered
again rather than handed out as a link to nothing. The table is created by
voice.php itself, idempotently, so a database from any older build needs
nothing run against it first.

**No queue and no offer.** A clip is asked for by somebody pressing play on one
line. It is seconds of audio and they are waiting, so it renders now or not at
all. Choosing the machine was the consent, exactly as it is for a glance at a
room. A cached clip is free; each render is reported to the meter as one clip
plus its character count, because that is what a hosted voice bills by.

---

## What it refuses

- to read the prose `voice`, pronouns, age or gender labels when choosing a
  voice;
- to speak a caption, a stage direction, a link or an emoji;
- to send anything when nothing is left to say;
- to store the key;
- to keep anything that is not audio;
- to treat a machine that is down as an error. Down is `null`, exactly like no
  machine. A machine that answers *badly* (refuses, or sends something that is
  not audio) throws, because that is a fault and the person pressing play may
  need to hear about it.

---

## In the play view — the intended surface

*Being built as this is written; described here as intended, not as shipped.*
`forge/web/speak.php` renders and serves a clip for one message; a **Voice
machine** card on the machines screen takes the address (the key stays in
`XERIC_VOICE_KEY`, in the environment only, as it is everywhere else); and each
character's chat bubble gains a **speaker button** that plays that line in that
person's voice. The speaker asks by message alone: the server looks up who said
it and what was said, so a request cannot put words in anybody's mouth.
