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

- **Theo can be dealt into the Thursday game** — `engine/table.php` `xeric_table_barred()`
  bars by walls only, and Theo is not walled from the pot (e5a73b4).
- **The casserole-ledger board re-ranks inside two static prompts** — a cache break the
  benchmark's soft probe `cost.cache` reports (`engine/continuity/probes.php`; 4dd1c46).
- **The room's idle-beat offer is covered by source checks only** — `forge/web/play.php`
  `roomIdleArm()`; `room-web-test.php` greps the page, nothing runs the JS (0b75ac0).
- **room-web-test needs `XERIC_SOLO=0` and a machine per session** —
  `forge/web/tests/room-web-test.php` sets it itself; a solo run makes owner and guest one
  person (0b75ac0).
- **No suite has been run round the clock** — the web suites open their worlds on the wall
  clock, so what they find depends on the hour they are started. `splat-test.php` and
  `stream-test.php` failed in the afternoon and are pinned; the others have only ever passed
  at the hours somebody happened to run them, and nothing replays them under a faked clock.
- **Apache + PHP-FPM drops `Authorization`** unless the vhost says `CGIPassAuth On`, so a
  Bearer token never arrives — `docs/API.md`, "Behind Apache with PHP-FPM" (c74f580).
- **The vendor fields in `tools/proxies/` are unverified** — each mapping says *verify*;
  `tools/proxies/README.md` (0d3208c). Still so after M5: the scaffold's and the bet test's
  machines are our own, and no vendor has answered one of these requests.
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
- **A fork keeps the trust the owner earned** — `forge/web/session.php`
  `xeric_session_fork_clear()` takes the owner's promises, confidences, grudges, sleep facts
  and spend, and leaves `trust` and `trust.warmth` on the cast: a template's starting dial and
  what the owner earned on top of it are one number, and nothing records the dial to put back.
- **A fork keeps the town's memory of the owner** — same function: `events` (a room's or a
  duet's prose can name them), `gossip.*`, and the owner's position (`player.where`,
  `player.where_since`) are copied as they stand. By design so far — a visitor gets the town
  as it is — and not the same thing as a blank slate.
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
- **A secret said in a room is read at the close, a line at a time** — `engine/room.php`
  `xeric_room_land_live()` hands each line to `xeric_confide_repeat()` when the hour lands,
  so the breach is not felt while the room is still talking, and one told across two lines
  is missed (on purpose: an hour's words pooled would break confidences nobody told). A duet
  or a scene the cast holds among themselves is still not read.
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

## Open — left by M4

Filed by the room with two humans in it, not yet fixed.

- **Two phones in a real browser have never been tried** — `forge/web/tests/room-web-test.php`
  seats two sessions on one world in-process and through child-process page requests; the
  strip's peer lane, "the floor is Ann's" and the ceiling note in `forge/web/play.php`
  `roomPaint()` are covered by nothing that runs the JS.
- **One table talks at a time in a world** — `forge/web/play-view.php` `xeric_play_room_say()`
  refuses a line from somebody standing elsewhere while a room is live; two rooms at once would
  need `room:live` keyed by place.
- **Memories harvested from adult lines are not stamped** — the harvest now reads every line
  whole (`engine/chat.php` `xeric_chat_extract()`, viewer null), so a memory can carry an adult
  line's substance into a prompt a kids' headset's turn builds; `memories` has no `rating`
  column. The room's close is the same (`xeric_room_land_live()`).
- **The ceiling never comes back up** after the lower-rated player leaves —
  `engine/room.php` `xeric_room_player_join()` folds down only, by design (the hour is one
  record), so the rest of that session stays at the floor.
- **Kept and explained without a name are a heuristic** — `engine/chat.php`
  `xeric_chat_extract()` falls back to the newest user line the harvest is the first to read
  when the model answers `true` rather than a name, which is right for a reply and wrong for an
  aside.
- **Sitting down has no narrator row** — a second person's first line is their arrival; the
  far table is still ranked by heat about the opener only (`xeric_room_open()`).
- **A late joiner's poll shows the whole thread** — `forge/web/room.php` answers rows from the
  cursor, not from `joined_at_row`; harmless while guests may read the room anyway.

## Open — left by M5

Filed by the layout, the stream and the scaffold (cc381c0, a304675, 377bb8a) and
the follow-up that closed the plan, not yet fixed.

- **No real world model has drawn a room** — `tools/proxies/worldmodel.py` rasterises a
  blockout; a local video model plugs in at `send_frame()` and none has; Genie 4's API and the
  hosted streamers were never called. The bet test (`engine/tests/bet-test.php`) proves the
  bytes, not the picture.
- **`/v1/frames` is the scaffold's invented shape** — `tools/proxies/worldmodel.py`
  `--upstream-frames` POSTs a proxy frame and expects a frame back in a contract nobody's
  model speaks yet; a real one needs a wrapper, or the shape changes.
- **"Which machine drew it" is untested by a person** — the tags now sit at their bearings
  over every view (`forge/web/xeric-view.js` `flatAt()`, `splatTags()`), but nobody has looked
  at the three side by side.
- **An unconfirmed stream end is never in the ledger, by decision** — `engine/stream.php`
  `xeric_stream_told()` writes the seconds a machine may still bill as
  `meta.unconfirmed_end` and shows them (`xeric_stream_unconfirmed_line()`); nobody knows they
  were spent, so they are not counted.
- **The sweep's DELETEs are bounded per heart pass** — `engine/stream.php`
  `XERIC_STREAM_TELL_PER_TICK` (4, every world together) at `XERIC_STREAM_TELL_TIMEOUT` (2 s);
  a pass that finds more owed sends the rest next minute, so a machine may run up to a minute
  longer per four abandoned sessions. The owner's own beat or end sends two more.
- **A second kind of look is engine-only** — `xeric_scene_enqueue($db, $brief, 'splat')`
  files a splat beside the template's panorama (`engine/scene.php` `xeric_scene_want()`), but
  no owner action asks for one ("draw it as a splat"), and the look view, the day strip and
  `scene.php`'s file route show only the template's first kind.
- **A still's field of view is assumed** — `forge/web/play-view.php`
  `xeric_play_look_fov_attrs()` takes `hfov`/`fov` from the answer's meta and otherwise 90°;
  no machine has been asked what it draws, and the brief does not say.
- **The splat's frame is assumed to be the layout's** — `xeric_play_look_places()` puts the
  vantage at the splat's origin looking down −z; a machine whose capture is centred or turned
  elsewhere puts the tags off their people. The y-up line above is the same assumption.
- **The stand page's tags are still a row under a still or a stream** —
  `forge/web/stand.php`; only the play view lays them over every view.
- **smoke-test fails four page checks under `XERIC_SOLO=0`** — `forge/web/tests/smoke-test.php`
  (the workbench, the book, the watch, the join page); it passes solo, and the CONTRIBUTING
  loop's measure excludes it.

## Closed

- **A promise to somebody who said nothing was pinned on whoever else spoke** — the floor
  and a live room's close handed `xeric_expect_from_scene()` only the lines spoken, and it
  read who was present off who spoke: "Dale, I'll bring the hymnals by tomorrow", with Dale
  silent and Janelle answering, became Janelle's expectation. Both now pass everyone in the
  room, and the scene reports what it filed (`$held`, `"<listener>:<key>"`), so the floor's
  trail carries one shape.
- **A watched scene told the cast the owner had walked in when a guest had** —
  `xeric_duet_messages()` takes the walker's name and `xeric_watch_line()` passes it, so
  "is here, in the room with you" names whoever is standing there.
- **The inspector showed a guest the owner's prompt** — `forge/web/why.php`'s character
  lane built with no seat; it now builds and rebuilds for the session's player.
- **The inspector's real-time stamps were in the host's zone** — `date()` on the room
  trail, the learn crumbs and the struck lessons; `xeric_play_real_stamp()` prints them in
  the zone the world's clock is read in.
- **The MCP's `read_thread` put a dash where every speaker's name should be** — it read
  `name`, and a thread's rows carry `who` (`tools/mcp.php`). A line kept from the seat now
  says so instead of printing empty.
- **The MCP's `seat` tool advertised `"properties": []`** — an empty PHP array, which is
  not a JSON Schema object; it is `{}` now.
- **A thread only a guest had written in carried the owner's name** —
  `xeric_play_thread()` named a lone voice from the template; a lone guest is named from
  the roster.
- **A pass in curly quotes was spoken aloud** — `xeric_floor_passed()` took straight quotes
  only, so `“(stays quiet)”` or a trailing `…` landed in the room as a line.
- **splat-test and stream-test failed at hours the Bluebird stands empty** — both opened a
  world on the wall clock and then expected somebody in the diner to tag. Each pins its
  world to a Tuesday morning now (the rest of that weakness is filed above).
- **The narrator-audit contradiction in the leaky run** no longer reproduces — the baseline
  accepted with b8f13d9 shows `presence.audit` clean in both runs.
- **A bare-day promise missed at 04:00** — `xeric_promise_when("Thursday")` resolved to that
  day's midnight, so the four-hour grace ran out before the day began. A bare day is due at
  20:00 (`XERIC_EXPECT_BARE_HOUR`): missed when the day is over, kept by being there at any
  hour of it.
- **Drop-in visits ignored a workplace's hours** — the sweep's drop-in group reads
  `xeric_sweep_open_places()` now, so nobody is seated in the Bluebird after it has shut.
- **A GET of a thread marked it read** — `xeric_play_thread()` takes `$markRead`; the API's
  read route and the suggester pass false, and `POST …/read` is the one way the dot goes out.
- **A fork carried what the cast held for or against the owner** — beyond the day's `spend`
  (the line as filed): the owner's promises and their quotes, confidences, grudges, rituals,
  milestones, worry and news, the construct memories about them, sleep facts, reminders, the
  narrator's intents and the director's requested stagings. `xeric_session_fork_clear()`
  takes them and `xeric_session_fork_is_clear()` refuses a copy that still has any.
- **Repeating a secret in a room was not read** — the close of a live room reads each line
  against the confidences its hearers must not hear (what is left of it is filed above).
- **The narrator's audit outlived its world** — the cache is named for a database path, and
  a world forged again under an old name, or a visitor's second copy, found the last one's
  audit. Deleting a world, forgetting a session and making a new database each drop the one
  file (`xeric_web_audit_forget()`).
- **The payer files were never swept** — the turn's worker removes its own
  `room-pay-<lease>.json` when it ends, and one a dead worker left ages out with the jobs.
- **Extensions** — `bootstrap.php` checks `ctype` and no longer `sqlite3`, and says so when
  `openssl` is missing (needed only for a remote model); the PHP finder knows an 8.1 binary.
- **`worlds.pre-blank-20260803-082606/` was tracked** — it is not in the tree any more.
- **A world kept one look per room-moment** — `scene_jobs` was UNIQUE(key, fingerprint), so a
  panorama and a splat of one moment could not coexist; it is keyed (key, fingerprint, `want`),
  rebuilt once under the same rows by `xeric_state_scene_jobs_want()`, and `want` '' is the
  template's first kind, so every row from before still resolves (found by a304675).
- **The tags sat in a row under a splat, a still and a stream** — the one way a careful eye
  could tell the machines apart; they are laid over every view at their bearings, through a
  flat frame's field of view or the splat's own camera each frame, the row kept as the
  fallback (found by a304675).
- **The stream sweep sent DELETEs on the sidebar's read path** — a page render could stall
  five seconds per abandoned session on a dead machine; the sweep only marks
  (`meta.tell_owed`), and `xeric_stream_tell_owed()` sends from the heart's pass and the
  owner's own beat and end (377bb8a's review).

- **Storage readers redacted at whoever's request ran them** — `xeric_messages_recent()`,
  `xeric_room_rows()` and `xeric_events_recent()` take an explicit viewer (null: whole); a kids'
  headset's turn no longer makes the harvest skip adult lines or a room's close write diaries
  from placeholders; a cast prompt after the fold is held to the room's `eff`
  (`xeric_room_lines_shown()`); every room line is stamped at the table's ceiling
  (`xeric_room_append()`); the photo reaper composes at the lower of the queued stamp and its own
  rating and never re-stamps (review of M4).
- **The harvest's name match found "Ann" inside "Hannah"** — `xeric_chat_name_player()`, whole
  names, longest first, ambiguous is nobody; confidences and repeats go to the line's own player
  (`xeric_chat_confidant()`).
- **The room poll drew the viewer's face on a friend's lines** — `h` is `__you` only for the
  viewer's own rows, `__you.p<n>` otherwise (`xeric_play_room_rows()`, `roomFace()`).
- **The ritual pass read one body** — `xeric_ritual_step()` reads every player's, with a visit
  log and rows each.
- **Two players in one room fingerprinted it twice** — `xeric_scene_brief()`'s `company` keys
  the room on everybody in it.
- **A friend who moved twice in a stretch never left** — `player.moves[.p<n>]`, the last eight
  moves, answers `xeric_player_where_at()`.
- **api-test and splat-test flaked** — an unlocked session read could land between
  `xeric_web_session_edit()`'s truncate and write and read "no machine", pausing the owner's
  clock (`xeric_web_session_read()` takes `LOCK_SH`); api-test's servers take kernel-assigned
  ports and wait for their own "started"; splat-test's 250,001-splat check waits for its state
  instead of 80 ms.

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
- **Thin coverage**: heart.php is checked only for HTTP denial (and, for its live-view pass,
  by source and through `xeric_stream_pass()`, never as a process); workers only by source
  grep; no test names any of `forge/web/pdf.php`'s functions.

## Deferred from the room on the web

- *(none open)*

## Stale text

Nothing known. (The roadmap's overlays heading, the room's "dormant" notch, the PHP 8.2
docblocks and QUICKSTART's `curl` were the last; all four are corrected.)

## Platform

- **The Windows server is single-request** — `forge/web/progress.php:43`
  (`XERIC_PROGRESS_WINDOW`), `xeric.cmd:110`; a mitigation, not a fix (a real server,
  or polling instead of streaming).
- **`power.php`'s `off` is probably a no-op on Windows** — `forge/web/power.php:100`.
- **`deploy.sh` assumes bash, ssh and Apache.**
- **The suites need a POSIX shell** (`shell_exec`, `command -v`, `timeout`).
