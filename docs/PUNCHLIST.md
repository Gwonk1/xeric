# The punchlist

What is known and not yet fixed, in one place a contributor can read. Each
line names where it lives (a commit, or file:line as of this writing; line
numbers drift, the function names do not) and why it is still open. The
source is the codebase audit, [`FUTURE.md` Appendix E](FUTURE.md#appendix-e--the-codebase-audit),
plus anything found since.

When you fix one, delete its line in the same commit. When you find one and
do not fix it, add a line here in the same commit — "filed, not fixed" means
filed *here*.

## In progress — the ninety-day bugs

From [`FUTURE.md`](FUTURE.md), "The next ninety days", items 1–3; the four the
continuity benchmark (Appendix B) finds on day one are marked ★. Being worked
on now; check `git log` before starting on one.

- ★ **Milldale's special role has no `must_not_know`** — `engine/sweeps.php:359`
  `xeric_sweep_protected()` returns empty, so [pastor_dale, janelle] is a legal
  visit group at First Lutheran on table night.
- ★ **Promises have no `kept` state** — `engine/constructs.php`; Ruth is logged as
  waiting after her dish comes back.
- ★ **The Bluebird's Saturday hours** — `engine/fixtures/milldale.json`: the hours set
  only `close_weekday`, so Saturday read as open all day. (The fixture note says
  the sweep's drop-in group still does not check a workplace's hours.)
- ★ **The heart never runs the learn pass** — `forge/web/heart.php`; a week lived
  unattended never turns friendly replies into trust.
- **Four gendered-prose sites** — panel.php:358 (player-visible), learn.php:1005–1054,
  prompt.php:469, table.php:496 and 713; plus the negative assertion that keeps them out.
- **`xeric_room_messages` drops narrator-set weather** — `engine/room.php:803` passes a
  database to a function with no such parameter (the duet fixed its copy).
- **The fork scrub leaves duet and room diaries** — `forge/web/session.php:594`
  `xeric_session_fork_clear` scrubs only `auto` memories.

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
- **The cast-to-cast seam is dormant** — `engine/room.php:44`, `engine/constructs.php:58`:
  expectations point only at the user, so the notch for one naming the last speaker
  reads a field no row carries. Tested so the day it lights up is not the day it breaks.
- **No token budget** — limits are counts (messages, skips, rerolls, forges); the
  per-world budget is M2.

## Unwired

Built, or offered, and not connected to anything that runs.

- **`death` and `story` notify kinds are offered and never sent** — `engine/notify.php:40`, `:42`.
- **The narrator's write-ahead is unwired** — `engine/narrator.php`; CLI only.
- **Confidences are never formed** — `engine/confide.php:97` `xeric_confide_form` has
  no caller outside its tests; the sweep (`engine/sweeps.php:867`) only breaks and
  reads them, and confide has no surface.
- **Guests' `stranger` and `written_in` are unreachable** — `engine/guest.php:54`; the
  join UI always posts `{}`.
- **Thin coverage**: heart.php is checked only for HTTP denial; workers only by source
  grep; no test names any of `forge/web/pdf.php`'s functions.

## Deferred from the room on the web

- **No `id_slot` per speaker** — `engine/room.php` `xeric_room_step()`; several speakers
  on one llama.cpp slot re-read each system prompt per beat (each speaker's system is
  byte-stable across the session, so the cost is the slot swap, not the prompt).
  Measure first; then either `--parallel` ≥ roster, or pin a slot per speaker. Not sent.

## Stale text

- **`docs/CONSTRUCTS.md` "Still open"** — being rewritten alongside the `kept` work.
- **`ROADMAP.md`** — to be corrected against FUTURE.md's truth table ("Where we stand").
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
- **Headless Chrome without a session bus hangs** — in some sandboxes full Chrome
  never dumps a page whatever flags it is given; `smoke-test.php` fails in 20 s and
  says so. `chrome-headless-shell` (via `XERIC_CHROME`) works there.
- **`worlds.pre-blank-20260803-082606/` is tracked against the stated policy** — 20
  files of forged worlds in the repository, which `.gitignore` says never belong
  there. Not deleted: it is the owner's call whether to remove it from history.
