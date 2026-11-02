# The per-world budget

The meter is a receipt; the budget is a ceiling. A world's owner writes a
`budget` block into its template (see `WORLD_TEMPLATE.md`), and everything
that spends reads it first.

```json
"budget": { "tokens_per_day": 0, "renders_per_day": 0, "clips_per_day": 0,
            "hearings_per_day": 0, "metered_only": true }
```

**Zero is no ceiling.** Every default is 0, so a world without the block
behaves exactly as it did before the budget existed, and the ledger is never
even read.

**Metered only, by default.** With `metered_only: true` the ceiling counts
and limits only spend on a machine that bills — a remote model, or a remote
scene, voice or ears machine (anything not on loopback; the `xeric_*_costly`
bit). The owner's own electricity is never rationed unless they set it false.

**A day is the world's date.** The ledger is keyed by `xeric_clock_now()`'s
local date, not the wall clock. The heart lives world-hours overnight and a
skip lives six in a minute; what the owner is buying is "this much living
per day of the world's life", so a world that lives a week in one night gets
a week of days, and a world spent out at 23:59 has its allowance back at 00:01
world time.

## Receipt and ledger

| | the meter | the ledger |
|---|---|---|
| where | the visitor's session file | the world's own database (`spend`) |
| per | session, per machine | world, per world date, costly or not |
| for | "tokens wasted" | the ceiling |
| refuses | never | when a ceiling is reached |

Every metered call lands in both: `forge/web/boot.php` wraps each meter sink in
`xeric_budget_sink()`, which writes into the world this process last opened
(`xeric_budget_bind`, called by `xeric_play_open`). A fork is its own database
and so its own ledger, read against the template's ceiling. The engine never
writes the ledger by itself; a CLI with no sinks has no ledger.

## What reads it

| who | kind | out of budget |
|---|---|---|
| the heart's sweep | tokens | stops living hours at the window it ran out; `world_state` `budget.stopped` says when and why (`xeric_budget_why`) |
| the heart's draw-ahead (`xeric_scene_ahead`) | renders | skips the whole pass and says so in the tick line |
| the scene and photo reapers | renders | a pending row waits — never claimed, no try burned |
| scene.php's look | renders | 429, naming the ceiling |
| speak.php (a miss) | clips | 503, "this world's clip budget for today is spent" |
| hear.php | hearings | 429, naming the ceiling |
| say.php, room.php (a line, a cast beat) | tokens | 429, naming the ceiling |

A token check asks "is anything left": a reply's size is not known before the
model answers, so the call that crosses the line is the last one.

**Fail closed.** When a ceiling applies and the ledger cannot be read, the
answer is no.

**It never widens anything.** The rating, consent and owner doors are where
they were. The hourly caps in `forge/web/limits.php` are per visitor and are
left as they are; the two compose, and neither reads the other.

The owner sees it on the meter that means "everything": `today: 1,204 of
200,000 tokens, 3 of 20 renders` beside the session's total, only when a
ceiling exists.
