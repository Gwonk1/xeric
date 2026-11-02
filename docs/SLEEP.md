# Sleep

A character who has lived for months has thousands of memories. The prompt
carries twelve. Without sleep those twelve are the newest twelve, and everything
older than a couple of weeks is gone from their head. Nothing deleted it; it
just stopped being shown.

Sleep is the alternative. Once per world-night, during the dreams window, each
character's old memories are read back and turned into a few **durable facts**,
and the prompt carries the facts. The code is `engine/sleep.php`. This is
milestone 3's "Sleep" in `docs/FUTURE.md`, and a test of bet 4: conditioning
beats memory.

## The rules it keeps

- **Nothing is deleted.** Source memories are never deleted or rewritten. A
  memory that went into a fact gets a row in `sleep_links` (`memory_id` →
  `fact_id`, its *consolidated_into*). It stops rendering in the prompt while
  its fact renders. Every other reader still sees every memory: the extract's
  dedupe, rooms, duets, the narrator.
- **The bible is never touched.** The bible is the fixed prefix of every prompt.
  It is the anchoring reminder that, according to Appendix D (ContextEcho),
  resets persona drift where compaction does not. Sleep never puts it in its own
  prompt and never writes to the template. Its block renders below the bible,
  so it cannot shorten it. `sleep-test.php` checks that everything before the
  memory blocks is byte-identical before and after a pass.
- **Code disposes.** The model proposes facts in a fixed shape:
  `{"facts": [{"fact", "about": [handles], "since": "YYYY-MM-DD", "from": [memory ids]}]}`.
  `xeric_sleep_dispose` then keeps a fact only if all of the following hold:
  - every `from` id was offered tonight, and no id is used by two facts;
  - it contains no six-word quotation of anything its holder is walled from
    (`cb_quotes`, the continuity oracle's own test), and it passes the age
    floor;
  - every `about` handle is named by some source;
  - every number, and every capitalised word that does not start a sentence,
    appears in the sources or in the names the sources mention;
  - at least half of its content words appear in the sources;
  - it is shorter than the memories it replaces.

  `since` is always the earliest source's world date, whatever the model wrote.
  A fact that is dropped leaves its memories unconsolidated, and they keep
  rendering as before.
- **Strictest wall wins.** A fact inherits from its sources:
  - `gossip_grade: false` if any source has it;
  - the union of the sources' `walls` and `wall` meta;
  - `walled_from`: every viewer that some source quotes a wall for;
  - the highest `rating_min`.

## What is never consolidated (pinned)

The following are never consolidated:

- memories with source `construct`, `spill`, `story`, `confide`, `lesson`,
  `death` or `pin`;
- memories whose meta has `expect`, `gossip`, `confide`, `told`, `beat`,
  `story`, `lesson` or `pinned`, or has `kind: death`;
- any memory that quotes one of its holder's expectations (a promise, in any
  state);
- any memory that quotes a confidence the holder was told or told someone else.

The engine points at these, so they stay as they are.

## When it runs

The heart calls `xeric_sleep_pass($t, $db, $now)` on every tick, guarded by
`function_exists`. It can also pass its `$endpoint` as a fourth argument; null
means the CLI default. The pass decides for itself whether to run:

- **Outside tonight's window it returns immediately.** The window is
  `sleep.window`, then `proactive.dreams.window`, then `01:00-06:00`. A window
  that wraps midnight counts as one night.
- **It runs once per person per night.** That is guarded by world_state
  `sleep:night:<date>:<handle>`, which is written in the same transaction as
  the facts.
- **It makes one model call per person, tag `sleep`.** Each call sends at most
  40 of that person's oldest eligible memories, grouped by the first other
  person they name. Failing that, by the first place; then the person at the
  centre (`user`); then the holder.

`xeric_sleep_due($t, $db, $now)` is the cheap check, if the heart wants to ask
first.

Sleep spends model tokens, so it is budgeted:

- **The budget is asked first.** It asks `xeric_budget_allows` for the whole
  prompt it is about to send. Sleep can wait a night, so it should never be the
  call that crosses the ceiling.
- **Local only, by default.** It refuses a costly model unless the template says
  `sleep.remote: true`.

A refusal or a model failure writes nothing and sets no guard.

```json
"sleep": {"enabled": true, "after_days": 14, "remote": false, "window": "01:00-06:00"}
```

Every key is optional. The tables `sleep_facts` and `sleep_links` are created by
the first write, inside `sleep.php`, not by `state.php`. In a world that never
slept, the prompt reads exactly what it read before.

## How it renders

`xeric_prompt_recall` (in `prompt.php`) renders a **WHAT YOU KNOW** block,
`- (since Thu 15 Oct) fact.`, directly ahead of WHAT YOU REMEMBER, which now
leaves out consolidated memories.

The two blocks share the memory cap of 12 lines. Facts get at most a third of
it, chosen by how many memories they stand for.

The two blocks together are never longer than the raw memories alone would have
been. The function renders the raw block first as a ceiling, then drops the
oldest raw lines, then the thinnest facts, until both blocks fit. So a prompt
gets shorter as a world ages.

`xeric_sleep_chain($db, $factId)` returns the fact and every memory it stands
for. This is the seam for `why.php`.

## Measuring it

The continuity benchmark has a `sleep` probe class. When the scenario says
`"sleep": true`, the harness runs the pass on the first tick of each night's
window. It builds every living prompt just before and just after the pass, and
the probes compare each pair:

| probe | hard rule |
|---|---|
| `sleep.recall` | a scripted fact the holder carried before, they carry after |
| `sleep.shorter` | no system prompt is longer after than before |
| `sleep.walls` | no fact at rest quotes its holder's walls; no prompt after quotes a wall path it did not quote before |
| `sleep.chain` | every fact has at least one source of its own holder, none missing, and no memory row was removed |
| `sleep.summary` | info: nights, calls, facts, and drop reasons |

At the default `after_days` (14), a three-day run leaves nothing old enough to
consolidate, so the baseline run makes no sleep calls.

`engine/tests/sleep-test.php` patches `after_days` to 1. It lives the three days
twice, once with sleep and once without, under both the honest and the leaky
stand-in. It asserts that:

- the four probes hold;
- recall scores do not drop;
- no build is longer than its twin without sleep;
- the memory tables are the same size;
- the leaky stand-in's padding with walled text and its invented fact are
  dropped.

## The tool

```
php tools/sleep.php --world=worlds/milldale            # tonight, if it is night there
php tools/sleep.php --world=worlds/milldale --force --dry-run
php tools/sleep.php --world=worlds/milldale --facts=dot
php tools/sleep.php --world=worlds/milldale --chain=12
```

## What it does not do (yet)

- Facts are never consolidated again. A world that runs for years builds up
  facts, and only the cap keeps them in check.
- A skip's rewind does not cover the sleep tables. The pass runs from the heart,
  never from inside a skip, and it only consolidates memories older than any
  skip's mark.
- The honest stand-in's facts are clipped source text, not real compression.
  A real model's compression quality is measured only by
  `tools/continuity.php --model=<url>`.
