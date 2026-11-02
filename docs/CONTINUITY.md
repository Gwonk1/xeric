# The continuity benchmark

The product makes one promise: come back after a while and the world has gone on
living, the same world, and nobody in it knows what they shouldn't. This
benchmark checks that promise. It lives a scripted few days of a world on a fake
clock and then probes what every character was shown. The plan behind it is
`docs/FUTURE.md`, Appendix B.

## Running it

```
php engine/tests/continuity-test.php       # CI size: 3 days, honest + leaky, about 2 s
php tools/continuity.php                   # the same run, writes reports, compares to the baseline
php tools/continuity.php --days=7 --seed=4 # longer or different (compared only if the baseline matches)
php tools/continuity.php --model=leaky     # one personality
php tools/continuity.php --model=http://127.0.0.1:8080/v1   # a real model, temperature 0
php tools/continuity.php --accept          # make this run the new baseline
```

`--probe-every=N` sets how often every character's prompt is sampled, in hours
(the default is 3). This is the setting to change if the CI run gets slow.
`--judge` is accepted but not implemented yet. The judged class is advisory and
never gates CI.

A real model reads `XERIC_LLM_KEY` and `XERIC_LLM_MODEL`. To give its spend a
separate row on the meter, point `--model` at `tools/standin-model.php`'s proxy.

## What it does

- **The scenario.** `engine/fixtures/milldale-continuity.json` runs Milldale
  from Thursday to Saturday. Walt tells Dot the doctor took away his pie, promises
  her Friday noon and misses it, then apologises on Saturday. He promises Ruth her
  casserole dish back and returns it. Harlan dies on Friday. On Saturday afternoon
  six hours are skipped, rewound, and lived again with the next seed. The file
  also holds:
  - a ping `policy` saying who gets an answer;
  - `canaries` (the Thursday game's words);
  - `facts` to track;
  - authored `truth` (opening hours);
  - `allow` (things that may be said aloud);
  - `expected_fail` reasons.
- **The clock.** `xeric_state_time()` is pinned and moves forward one hour per
  tick. Each tick runs `forge/web/heart.php`'s steps in heart's order. The skip
  runs `tick-worker.php`'s steps in that order. Every model call goes through a
  capture wrapper, which reports to `xeric_llm_tally`.
- **The stand-in.** Honest mode follows every instruction. Leaky mode copies
  "still carries:" and "You hold this back:" lines into whatever it writes. Both
  pick their output from phrase banks, keyed on tag, speaker, world hour, seed
  and the user's words, never on prompt bytes. So editing a prompt changes the
  cost numbers but not the story.

## Reading a report

Each run writes `data/continuity/<scenario>-<model>-s<seed>-d<days>/` with three
files: `scorecard.json`, `report.md` and `world.db` (open it with
`sqlite3` to see exactly what happened).

Every probe returns `{id, class, severity, pass, score, evidence}`.

- **Severity.** `hard` gates CI. `soft` is reported. `info` is numbers only.
- **Evidence.** It cites wall paths, row ids, capture numbers (`call#12:sweep`,
  `build#40`) and prompt hashes. It never contains the walled text, so a report
  is safe to paste anywhere.

| class | asks |
|---|---|
| walls | Does any prompt contain a six-word quotation of something this viewer is walled from? Do stored memories? Can a viewer be seated at a table whose economy is hidden from them? Are there telltale words (soft)? Does the oracle cover `render-test.php`'s SECRETS? |
| recall | Which part of the prompt (WHAT YOU REMEMBER, transcript, WHAT YOU ARE OWED, RIGHT NOW) still carries each fact, and for how long? |
| presence | Is the dead person absent everywhere, named in every living prompt and refused in chat? Were participants where their week puts them? Were places open? Was the mill empty? Were quiet hours kept? Any narrator-audit contradictions? Was there a minimum of activity? |
| relationships | Was the missed promise followed by a trust dip and a repair? Was the kept promise left with no miss? Does trust recompute from the log? |
| media | Are seeds stable? Are fingerprints stable across minutes and changed by a death? |
| cost | Calls, characters and approximate tokens per day and per phase. System-prompt size per speaker. Cache breaks. |
| learn | Does the tally match the script? Does the heart learn? Does each lesson appear only in its own bucket's prompts? |

## The baseline, and the CI rule

`engine/fixtures/continuity-baseline.json` records, for each personality, every
probe's outcome, plus cost, seeds, fingerprints, digests and the PHP version.
Known bugs are marked `expected_fail` with a reason. In the leaky run the wall
failures are marked `teeth`: those probes *must* fail.

CI fails when any of these happen:

- a hard probe fails that the baseline did not expect to fail;
- a `teeth` probe passes, meaning the oracle stopped catching the leaky model;
- prompt tokens rise by more than 10%;
- any media seed or scene fingerprint drifts.

An expected failure that now passes prints "improved, run --accept". A digest
that moves is reported as a note.

**To accept a new baseline:** run `php tools/continuity.php --accept`, read the
diff of `continuity-baseline.json`, and check it in with the change that caused
it. If the baseline file is missing, the test writes one on its next run.

Do not edit outcomes by hand. If a probe is wrong, fix the probe. If the engine
is wrong, fix the engine.
