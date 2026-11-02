# The punchlist

What is known and not yet fixed, in one place a contributor can read. Each
line names where it lives (a commit, or file:line as of this writing; line
numbers drift, the function names do not) and why it is still open. The
source is the codebase audit, [`FUTURE.md` Appendix E](FUTURE.md#appendix-e--the-codebase-audit),
plus anything found since.

When you fix one, delete its line in the same commit. When you find one and
do not fix it, add a line here in the same commit — "filed, not fixed" means
filed *here*.

## Open — found since the plan

Filed by the commits since the plan landed, not yet fixed.

- **A bare-day promise misses at 04:00** — `engine/constructs.php` `xeric_promise_when()`
  resolves "Thursday" to its midnight; with the four-hour grace it misses before the day
  begins (`docs/CONSTRUCTS.md`, "Still open"; e5a73b4).
- **Drop-in visits ignore a workplace's hours** — `engine/sweeps.php` the drop-in group
  checks walls (`$barred`) and never `xeric_sweep_open_places()` (fixture note
  `bluebird_hours`; e5a73b4).
- **Theo can be dealt into the Thursday game** — `engine/table.php` `xeric_table_barred()`
  bars by walls only, and Theo is not walled from the pot (e5a73b4).
- **The casserole-ledger board re-ranks inside two static prompts** — a cache break the
  benchmark's soft probe `cost.cache` reports (`engine/continuity/probes.php`; 4dd1c46).
- **One narrator-audit contradiction in the leaky run** — soft probe `presence.audit`
  (`engine/continuity/probes.php`; 4dd1c46).
- **The room's idle-beat offer is covered by source checks only** — `forge/web/play.php`
  `roomIdleArm()`; `room-web-test.php` greps the page, nothing runs the JS (0b75ac0).
- **room-web-test needs `XERIC_SOLO=0` and a machine per session** —
  `forge/web/tests/room-web-test.php` sets it itself; a solo run makes owner and guest one
  person (0b75ac0).
- **Apache + PHP-FPM drops `Authorization`** unless the vhost says `CGIPassAuth On`, so a
  Bearer token never arrives — `docs/API.md`, "Behind Apache with PHP-FPM" (c74f580).
- **`xeric_play_thread()` marks the thread read** — `forge/web/play-lib.php`, called by
  `api-lib.php`'s thread route, so a GET has a side effect; a `$markRead` flag would make
  the API's read half side-effect free (c74f580).
- **The vendor fields in `tools/proxies/` are unverified** — each mapping says *verify*;
  `tools/proxies/README.md` (0d3208c).
- **The smoke test cannot run under full Chrome in a sandbox without D-Bus** —
  `forge/web/tests/smoke-test.php` fails in 20 s and says so; `chrome-headless-shell` via
  `XERIC_CHROME` works (42a3bd4).
- **SPZ and SOG splats are not decoded** — `engine/splat.php` `xeric_splat_to_ply()` keeps
  them as they came and `forge/web/xeric-splat.js` `sniff()` refuses them; a server-side
  decoder with no WebAssembly would convert them to PLY on arrival (934bbe6).
- **The splat viewer assumes y up** — `forge/web/xeric-splat.js`, the view matrix; a
  y-down capture appears upside down, and the row needs a per-row up-axis flag (934bbe6).
- **XR tags sit about 6.8° below the horizon** — `forge/web/xeric-view.js` `tagPos()` puts
  them at y = −0.12, so a horizon-level ray at the right bearing is already near
  `raySelect()`'s 7° limit (934bbe6).
- **The walk-inside view has no XR mode** — `forge/web/xeric-splat.js` is a flat canvas;
  stepping inside a splat in a headset is not built (934bbe6).
- **A fork inherits the owner's spend for the day** — `forge/web/session.php`
  `xeric_session_fork_clear()` leaves the `spend` table, so a fork starts its world-day
  with the owner's ledger; it could clear `spend` (934bbe6).
- **The headset and talk path is untested on a device** — `forge/web/xeric-view.js`
  `standXR`, `raySelect`, `panAt`; run only against fakes in Node and headless Chrome
  (`forge/web/tests/stand-test.js`, the smoke test), never on a Quest, Android XR or
  Vision Pro (934bbe6).
- **The stand page's room poll is untested in a browser** — `forge/web/stand.php`
  `roomPoll()`; stand-test checks that the page's inline script parses and runs the
  viewer's parts, not the page's own polling (934bbe6).

## Open — left by milestone three

Filed by fc576e6, not yet fixed.

- **The teller finds out at once** — `engine/confide.php` `xeric_confide_repeat()` takes the
  teller's trust the moment the player repeats a confidence; nothing routes the finding-out
  through the gossip ripple (`docs/CONSTRUCTS.md`, "Still open"; fc576e6).
- **Repeating a secret in a room is not read** — `engine/chat.php` calls
  `xeric_confide_repeat()` from the chat harvest only; `engine/room.php` lines never reach it
  (fc576e6).
- **News, worry, ritual and milestone read the first player only** — `engine/constructs.php`
  `xeric_sibling_user()`, `xeric_sibling_thread()`; the thread and the body carry no player
  id until M4 (fc576e6).
- **The siblings are not in the forge's vocabulary** — `forge/forge.php` `XERIC_SYSTEMS` has
  no `anticipation`, `news`, `worry`, `grudge`, `ritual` or `milestone`, so the forge never
  proposes them; they arm by hand or through the preset systems (fc576e6).
- **A skip's rewind does not cover the sleep tables** — `engine/sleep.php` writes
  `sleep_facts` and `sleep_links` outside the rewind's diff; safe while the heart, never a
  skip, runs `xeric_sleep_pass()` and only memories older than any skip's mark are taken
  (`docs/SLEEP.md`, "What it does not do"; fc576e6).
- **Other readers still see consolidated memories raw** — the extract's dedupe
  (`engine/chat.php`), `engine/room.php`, `engine/duet.php` and `engine/narrator.php` read
  every memory, consolidated or not; only `engine/prompt.php` `xeric_prompt_recall()` renders
  facts in their place (`docs/SLEEP.md`, `engine/sleep.php` header; fc576e6).
- **The Director has not run for a month** — `engine/director.php` is exercised by
  `engine/tests/director-test.php` on a fixed clock; the continuity benchmark runs three
  days with it off (`presence.director_off`), so M3's "an encounter the Director staged"
  in a month left alone is untested (fc576e6).
- **Some presence readers do not see stagings** — `docs/DIRECTOR.md`, "Not yet": readers
  that call `xeric_world_who_is_where()` with no database (the prompt's room line,
  `engine/room.php`, `engine/scene.php`) and tick-worker's skip see the plain schedule
  unless their template was dressed by `xeric_director_dress()` (fc576e6).
- **The narrator's investigate cache is a file under the data dir** — `forge/web/play-narrator.php`
  `xeric_play_narrator_audit_path()` writes `<data_dir>/audits/<sha1>.json`, outside the
  world's database, and nothing that deletes or forks a world clears it (fc576e6).

## Open — left by M4

Filed by the room with two humans in it, not yet fixed.

- **Two phones in a real browser have never been tried** — `forge/web/tests/room-web-test.php`
  seats two sessions on one world in-process and through child-process page requests; the
  strip's peer lane, "the floor is Ann's" and the ceiling note in `forge/web/play.php`
  `roomPaint()` are covered by nothing that runs the JS.
- **One table talks at a time in a world** — `forge/web/play-view.php` `xeric_play_room_say()`
  refuses a line from somebody standing elsewhere while a room is live; two rooms at once would
  need `room:live` keyed by place.
- **A kids' headset reads an adult player's own room lines as gaps** — a player line keeps its
  request's stamp (`xeric_message_append`); only the cast lines after the fold are stamped at
  the room's ceiling (`xeric_play_room_run`). Stamping player text lower would want a content
  check at that ceiling, which nothing has.
- **The ceiling never comes back up** after the lower-rated player leaves —
  `engine/room.php` `xeric_room_player_join()` folds down only, by design (the hour is one
  record), so the rest of that session stays at the floor.
- **The payer files are never swept** — `forge/web/play-view.php`
  `xeric_play_room_payer_set()` writes `<jobs>/room-pay-<lease>.json`, one small file per turn
  another person spoke into; nothing deletes them.
- **Kept and explained without a name are a heuristic** — `engine/chat.php`
  `xeric_chat_extract()` falls back to the newest user line the harvest is the first to read
  when the model answers `true` rather than a name, which is right for a reply and wrong for an
  aside.
- **Sitting down has no narrator row** — a second person's first line is their arrival; the
  far table is still ranked by heat about the opener only (`xeric_room_open()`).
- **A late joiner's poll shows the whole thread** — `forge/web/room.php` answers rows from the
  cursor, not from `joined_at_row`; harmless while guests may read the room anyway.

## Closed

- **The live room seated one player** — `engine/room.php` seats `players`; the floor, silence,
  the ceiling and the close are per person; a guest with a roster seat may speak (M4).
- **constructs.php read the current player's body for every promise** —
  `xeric_expect_keep_present()` reads each promiser's own (M4).
- **The pulse, the sound brief, kept/explained and reference images read one person** — each
  reads every player, or the right one (M4; `docs/MULTIPLAYER.md`).

- **A cast member's confidence is formed by nothing** — `xeric_confide_form()` is called
  from the cast-to-cast pass via `xeric_confide_from_line()` (a287e01).
- **`id_slot` is not sent per speaker** — `xeric_llm_slot()` sends a stable slot per
  handle to llama.cpp machines with more than one slot, in sweeps and both rooms (9bbecf5).

From [`FUTURE.md`](FUTURE.md), "The next ninety days", items 1–3; ★ are the four the
continuity benchmark (Appendix B) found on day one, all failing on the commit before
the fix and passing since.

- ★ **Milldale's special role had no `must_not_know`** — fixed in the engine instead:
  `engine/table.php` `xeric_table_barred()` keeps anyone walled from a table's economy
  out of every group at that table on its night (e5a73b4).
- ★ **Promises had no `kept` state** — `engine/constructs.php` `xeric_expect_keep()` (e5a73b4).
- ★ **The Bluebird's Saturday hours** — `engine/fixtures/milldale.json` says `close` (e5a73b4).
- ★ **The heart never ran the learn pass** — `forge/web/heart.php` calls
  `xeric_learn_pass()` at the end of its hold (e5a73b4).
- **The gendered-prose sites** — the audit's four and four more; render-test scans every
  engine string literal (e5a73b4).
- **`xeric_room_messages` dropped narrator-set weather** — `engine/room.php` (e5a73b4).
- **The fork scrub left duet and room diaries** — `forge/web/session.php` (e5a73b4).
- **dayView's "earlier:" never cleared** — play-lib writes `data-now` now (0b75ac0).
- **No token budget** (was deferred by decision) — the per-world budget, `engine/budget.php`,
  `docs/BUDGET.md` (934bbe6).
- **why.php did not say why a world stopped on budget** — `forge/web/why.php` calls
  `xeric_budget_why()` when `budget.stopped` is set (414da36).
- **The narrator's write-ahead was unwired** — the sweeps read `xeric_narrator_leans()` into
  `xeric_sweep_choose()`, and `xeric_narrator_intents_deliver()` marks a realized beat; ask is
  the play view's (`forge/web/play-narrator.php`), investigate the inspector's (fc576e6).
- **Confidences were never formed** — the chat harvest forms them with
  `xeric_confide_to_player()`, `xeric_confide_repeat()` breaks them, and
  `engine/constructs.php` renders `xeric_confide_block()` (fc576e6; the cast's own
  `xeric_confide_form()` is still open, above).
- **The cast-to-cast seam was dormant** — expectations carry `of`, a cast handle, formed from
  rooms, duets and now the sweep's quoted speech (`xeric_constructs_cast_tick()`, from the
  heart), and `engine/room.php`'s heat notch reads it (fc576e6).

## Deferred by decision

Known, understood, and left on purpose until something forces them.

- **The repass gives up after two rewrites per finding** (75cf4ad) — a finding
  that survives two rewrites is shown, not looped on.
- **The canned surprise concepts should go** (084477d) — all five rows are still in
  `forge/interview.json` (`surprise_concepts`).
- **Rerolls leave dangling handle references in `board.visible_to`** (4fddf39) —
  pinned by `forge/web/tests/demo-test.php:877`.
- **Only poker is built** (cb31214) — the sub-game seam under `engine/table.php` has one game.
- **The DNS-rebinding window in the URL check** (b0e034d) — `forge/web/boot.php:587`
  `xeric_web_host_open` resolves, then the fetch resolves again; close it by fetching
  by IP with a Host header when M2 makes remote URLs common. Re-checked at read time
  (`boot.php:1297`), which narrows it and does not close it.
- **The Host pin in solo mode** the client-API plan asks for (FUTURE.md, Appendix C).
- **The forge's result fragment ships a `<script>`** — `forge/web/forge.php:1694`
  re-creates the fragment's scripts with the page's nonce rather than shipping
  wiring in the page itself.

## Unwired

Built, or offered, and not connected to anything that runs.

- **`death` and `story` notify kinds are offered and never sent** — `engine/notify.php:40`, `:42`.
- **The narrator's author power refuses** — `engine/narrator.php`
  `xeric_narrator_author_refuses()` throws and writes nothing, on purpose, until the undo is
  deeper than one step (`docs/NARRATOR.md`, "What author needs before it is built").
- **Guests' `stranger` and `written_in` are unreachable** — `engine/guest.php:54`; the
  join UI always posts `{}`.
- **Thin coverage**: heart.php is checked only for HTTP denial; workers only by source
  grep; no test names any of `forge/web/pdf.php`'s functions.

## Deferred from the room on the web

- *(none open)*

## Stale text

- **`ROADMAP.md`** — story overlays are built and still filed under Later against
  FUTURE.md's truth table ("Where we stand"); the Narrator and social constructs were
  corrected after fc576e6.
- **`engine/room.php`'s docblock calls the cast-to-cast notch dormant** — the heat rule
  (MATERIAL HEAT) says expectations point only at the user; they carry `of` now (fc576e6).
- **Docblocks say PHP 8.2+** (`engine/narrator.php:93`, `confide.php:39`, `table.php:41`);
  `bootstrap.php` enforces 8.1, CI runs 8.1 and 8.3.
- **QUICKSTART lists `curl`**, which nothing uses.
- ~~README's "Nine test suites"~~ and ~~VOICE.md's "not shipped"~~ — fixed; the README
  now points at CI.

## Platform

- **The Windows server is single-request** — `forge/web/progress.php:43`
  (`XERIC_PROGRESS_WINDOW`), `xeric.cmd:110`; a mitigation, not a fix (a real server,
  or polling instead of streaming).
- **`power.php`'s `off` is probably a no-op on Windows** — `forge/web/power.php:100`.
- **`deploy.sh` assumes bash, ssh and Apache.**
- **The suites need a POSIX shell** (`shell_exec`, `command -v`, `timeout`).
- **Extensions**: `sqlite3` is checked and unused (PDO only); `ctype` and `openssl`
  are needed and not checked by `bootstrap.php`.
- **`worlds.pre-blank-20260803-082606/` is tracked against the stated policy** — 20
  files of forged worlds in the repository, which `.gitignore` says never belong
  there. Not deleted: it is the owner's call whether to remove it from history.
