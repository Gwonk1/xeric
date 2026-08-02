#!/usr/bin/env bash
# deploy.sh — put the forge and the play view on staging, at https://dev.xeric.dev/forge
#
# The deployed tree mirrors the repo, one level down:
#
#   <docroot>/forge/           ← forge/web/*        the wizard AND the play view
#   <docroot>/forge/lib/forge/ ← forge/*.php + interview.json
#   <docroot>/forge/lib/engine/← engine/*
#
# boot.php resolves lib/ when it is there and ../../ when it is not, so the same
# files run from a checkout and from the docroot with no path rewriting.
#
#   forge.php   the forge         → build.php   → worker.php        ⎫ all three
#   review.php  before launch     → reroll      → reroll-worker.php ⎬ stream through
#   play.php    a world, live     → tick.php    → tick-worker.php   ⎭ progress.php (SSE)
#   say.php     one chat turn (synchronous — a turn is seconds, not minutes)
#   why.php     the inspector: the exact prompt a character gets, and the
#               decision trail behind any event. Reads only — no model, no write.
#
# A forged world arrives NOT LAUNCHED (`forge.review_pending` in its template).
# review.php is where it is read, edited, rerolled section by section and then
# launched; play.php refuses an unlaunched world with a two-button gate rather
# than a 404, because the review is meant to be skippable in one tap.
#
# Worlds, sessions and jobs do NOT live in the docroot: they are written by
# www-data into a data dir outside it. Each world's SQLite lives beside its
# template in that data dir — worlds/<slug>/world.db — so a world forged in the
# browser and a world played in the browser are one world, and neither of them
# is ever the repo's worlds/ directory on this host.
#
# The demo layer adds three more things under the data dir, all www-data's:
#
#   session-worlds/<sid>/<slug>.db   a visitor's own copy of somebody else's
#                                    world — the template is shared, only state
#                                    forks (session.php)
#   limits/                          sliding-window counters (limits.php)
#   queue/line.json + model.lock     who is waiting, and who has the GPU (queue.php)
#
# And one file this script deliberately does NOT create:
#
#   queue.drained                    touch it to take the GPU back, instantly,
#                                    with no deploy and no restart; rm to give it
#                                    back. The demo checks it mid-skip.
#
# Idempotent. Safe to re-run. Never touches anything else on the host.

set -euo pipefail

# The host is yours, not the project's. No default: a deploy that guesses where
# it is going is a deploy that eventually goes somewhere else.
HOST="${XERIC_HOST:-}"
[ -n "$HOST" ] || {
    echo "deploy.sh: set XERIC_HOST to the ssh target that serves the staging site." >&2
    echo "           e.g. XERIC_HOST=me@example.com bash forge/web/deploy.sh" >&2
    echo "           XERIC_DOCROOT, XERIC_DATA and XERIC_LOCAL_BASE override the rest." >&2
    exit 2
}
DOCROOT="${XERIC_DOCROOT:-/var/www/html/namedhosts/dev.xeric.dev}"
WEB="$DOCROOT/forge"
DATA="${XERIC_DATA:-/var/www/xeric-data}"
# The model is on this box's :8080; the server sees the same model at :18080,
# the far end of a persistent ssh tunnel. Pinned so no deploy can guess wrong.
LOCAL_BASE="${XERIC_LOCAL_BASE:-http://127.0.0.1:18080}"
# May a visitor point this install at an address of their own — including one on
# a private network? DEFAULT NO, and it stays no on anything public: the local
# endpoint deliberately skips the private-network refusal that every other
# outbound URL goes through, so an editable address on a public host is a request
# forgery hole. Set XERIC_LOCAL_EDIT=true only for a box whose visitors are the
# people who own it. Unset, the app falls back to loopback-only, which is the
# right answer for a laptop install.
LOCAL_EDIT="${XERIC_LOCAL_EDIT:-}"
EDIT_LINE=""
[ -n "$LOCAL_EDIT" ] && EDIT_LINE="    'local_editable' => $LOCAL_EDIT,"

cd "$(dirname "$0")/../.."          # repo root

PHPBIN="$(ssh "$HOST" 'command -v php8.3 || command -v php')"
[ -n "$PHPBIN" ] || { echo "no php on $HOST" >&2; exit 1; }

echo "→ $HOST:$WEB  (php: $PHPBIN)"
ssh "$HOST" "mkdir -p '$WEB/lib' && \
             sudo mkdir -p '$DATA/worlds' '$DATA/sessions' '$DATA/session-worlds' '$DATA/limits' '$DATA/queue' && \
             sudo chown -R www-data:www-data '$DATA' && sudo chmod -R 0775 '$DATA'"

# tests/ is not deployed: it is a CLI suite, and nothing under the docroot should
# be able to run it as a page.
rsync -a --delete --exclude 'lib/' --exclude 'tests/' --exclude 'config.local.php' --exclude 'deploy.sh' \
      forge/web/ "$HOST:$WEB/"
rsync -a --delete engine/ "$HOST:$WEB/lib/engine/"
rsync -a --delete --exclude 'web/' --exclude 'tests/' forge/ "$HOST:$WEB/lib/forge/"

ssh "$HOST" "cat > '$WEB/config.local.php' <<'PHP'
<?php
// Written by forge/web/deploy.sh. Not in git: it describes THIS host.
return [
    'local_base' => '$LOCAL_BASE',
    'data_dir'   => '$DATA',
    'worlds_dir' => '$DATA/worlds',
    // PHP_BINARY under mod_php is /usr/sbin/apache2, so the build worker needs
    // to be told where a real CLI php is.
    'php'        => '$PHPBIN',
$EDIT_LINE
    'places'     => 6,
    'cast'       => 4,
];
PHP
chmod -R a+rX '$WEB'"

echo "✓ https://dev.xeric.dev/forge"
