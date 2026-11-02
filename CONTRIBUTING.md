# Contributing

Small commits, each with its own tests. Read the doc for the subsystem you are
touching first: every one says what it refuses to do, and the refusals are the
design.

## Run the suites

Every suite, stopping at the first that fails — the same loop CI runs
([`.github/workflows/tests.yml`](.github/workflows/tests.yml), PHP 8.1 and 8.3):

```bash
for t in engine/tests/*-test.php forge/tests/*.php forge/web/tests/*.php; do
  php "$t" || { echo "FAILED: $t"; break; }
done
```

Each suite prints `ok` / `FAIL` lines and exits non-zero on any failure. Some
checks need something besides PHP and print `SKIP` (and exit 0) without it:

| suite | needs | without it |
|---|---|---|
| `forge/web/tests/smoke-test.php` | chrome, chromium or chrome-headless-shell (`XERIC_CHROME`) | SKIP; `XERIC_REQUIRE_BROWSER=1` makes it fail |
| `forge/web/tests/viewer-test.php` | `node` (`XERIC_NODE`) | SKIP; `XERIC_REQUIRE_NODE=1` makes it fail |
| `forge/web/tests/demo-test.php` | DNS, for one egress check | that one check says SKIP |

A SKIP is not a pass. CI sets both REQUIRE flags.

## The rules that bite

- Never commit a world: `worlds/`, `data/`, `*.db` are ignored for a reason.
- Keys live in the environment only; no CDN; a GET never spends.
- Nothing gender-derived engine-side.
- Found something and not fixing it? Add a line to
  [`docs/PUNCHLIST.md`](docs/PUNCHLIST.md) in the same commit.
