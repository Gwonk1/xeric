<?php
/**
 * worker.php — the build, off the end of the request that asked for it.
 *
 * WHY THIS EXISTS. A world is two to three minutes of model calls. Every proxy
 * in front of this app cuts a streaming response long before that: measured on
 * dev.xeric.dev, Cloudflare kills the connection at ~120 seconds even when a
 * frame is flushed every five. Tying the build's life to one HTTP connection
 * therefore means no build ever finishes. So the build runs here, detached, and
 * the browser watches a file.
 *
 * That reverses the usual abandoned-tab rule on purpose: a user who closes the
 * tab still gets their world, written to disk, waiting at forge.php?w=<slug>.
 * The model slot is held only for the life of THIS process, so a crash frees it
 * (flock is released by the kernel) and a build can never wedge the queue.
 *
 * THE LINE IS JOINED BY build.php, NOT HERE. The ticket arrives in the payload
 * so that the order of the queue is the order people actually pressed the
 * button, not the order in which detached processes happened to get scheduled.
 * This process says hello, then waits its turn, saying where it is as it moves
 * up — queue.php's whole point is that a wait with a position in it is a state
 * and a wait without one is a hang.
 *
 * THE KEY. A bring-your-own key arrives on STDIN — not argv (which is world
 * readable in ps), not a file, not an environment variable. It exists in this
 * process's memory and dies with it. Nothing written to the job file, the world
 * file or the session ever contains it.
 *
 * Usage (never a URL): php worker.php <job-id>   with the payload JSON on stdin.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("worker.php is not a page\n"); }

require_once __DIR__ . '/review-lib.php';   // → ui.php → boot.php → forge.php

$job = (string)($argv[1] ?? '');
if (!xeric_web_job_ok($job)) { fwrite(STDERR, "worker: bad job id\n"); exit(2); }

$payload = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($payload)) { xeric_web_job_append($job, ['k' => 'error', 'message' => 'the forge got no answers to build from']); exit(2); }

set_time_limit(0);
ignore_user_abort(true);

$t0 = microtime(true);
$el = fn() => round(microtime(true) - $t0, 1);

// The visitor this build belongs to. Set before anything else so the world can
// be claimed for them at the end — a detached process has no cookie of its own.
$sid = (string)($payload['sid'] ?? '');
if (preg_match('/^[a-f0-9]{32}$/', $sid)) xeric_session_use($sid);

/**
 * THE CEILING ON ONE MODEL CALL IN A BUILD.
 *
 * engine/llm.php's own default is 600 seconds and the forge retries every pass
 * once, so an unqualified call could hold the GPU for 1200 against a
 * XERIC_QUEUE_HOLD_MAX of 420 — and a build is around eight passes. A pass that
 * has not answered in four minutes is not going to; the forge's fallbacks are
 * written for exactly that and the world still gets built. tick-worker.php holds
 * its sweeps to 240 for the same reason and this matches it deliberately.
 */
const XERIC_WEB_BUILD_CALL_TIMEOUT = 240;

$lock = null;
$stopped = false;

try {
    $interview = xeric_forge_interview(XERIC_WEB_LIB . '/forge/interview.json');
    $answers = xeric_web_clean_answers((array)($payload['answers'] ?? []), $interview);
    $fill = ((string)($payload['fill'] ?? 'presets') === 'model') ? 'model' : 'presets';
    $endpoint = xeric_web_endpoint((array)($payload['model'] ?? []));
    $cfg = xeric_web_config();

    xeric_web_job_append($job, ['k' => 'hello', 't' => 0.0,
        'endpoint' => xeric_web_endpoint_label($endpoint), 'fill' => $fill]);

    // -- wait for the one model slot -----------------------------------------
    // Every frame here is a position, never a spinner: "you are 2nd in line —
    // about 3 minutes" is something a person can decide about.
    $ticket = (string)($payload['ticket'] ?? '');
    if ($ticket === '') $ticket = xeric_queue_join('forge', $sid);

    $got = xeric_queue_wait($ticket, XERIC_QUEUE_WAIT_MAX,
        function (int $ahead, int $eta, string $phrase) use ($job, $el): void {
            xeric_web_job_append($job, ['k' => 'queue', 't' => $el(), 'ahead' => $ahead,
                'eta' => $eta, 'text' => ucfirst($phrase)]);
        });

    if (!$got['ok']) {
        xeric_queue_leave($ticket);
        xeric_web_job_append($job, ['k' => 'error', 't' => $el(),
            'kind' => (string)($got['kind'] ?? 'queued'), 'message' => (string)$got['message']]);
        exit(0);
    }
    $lock = $got['hold'];
    if ((float)($got['waited'] ?? 0) > 1.0) {
        xeric_web_job_append($job, ['k' => 'note', 't' => $el(), 'pass' => 'prep', 'level' => 'info',
            'text' => 'the model is free, starting (waited ' . (int)$got['waited'] . 's in line)']);
    }

    if ($fill === 'model' && count($answers) < count(xeric_forge_step_keys($interview))) {
        xeric_web_job_append($job, ['k' => 'note', 't' => $el(), 'pass' => 'prep', 'level' => 'info',
            'text' => 'asking the model for one coherent premise to fill the gaps']);
    }

    $lastPass = 'prep';
    $onNote = function (string $note) use ($job, $el, &$lastPass): void {
        $lastPass = xeric_web_pass_of($note, $lastPass);
        xeric_web_job_append($job, [
            'k' => 'note', 't' => $el(), 'pass' => $lastPass,
            'level' => xeric_web_note_warn($note) ? 'warn' : 'info', 'text' => $note,
        ]);
    };

    // THE OWNER'S MACHINE WINS, MID-BUILD. tick-worker.php can ask between
    // windows because a skip is a loop; a build is eight straight passes, so the
    // question is handed to the forge as a hook it calls between them. Throwing
    // is how it stops: no half-written world reaches the disk, the visitor is
    // told in a sentence, and the finally below hands the slot back at once —
    // which is the entire guarantee `touch queue.drained` exists to make.
    $stop = function () use (&$lock, &$stopped): void {
        if (!is_array($lock) || !xeric_queue_expired($lock)) return;
        $stopped = true;
        throw new RuntimeException('The build stopped: ' . xeric_queue_stop_reason($lock)
            . '. Your answers are still here, press build again in a few minutes.');
    };

    $out = xeric_forge_build($answers, $endpoint, [
        'interview' => $interview,
        'places' => (int)($cfg['places'] ?? 6),
        // Twelve by default (owner, 2026-08-02): four people is a writers' room,
        // not a town. Overridable per-host through config.local.php's `cast`, the
        // same way every other knob here is.
        'cast'   => (int)($cfg['cast'] ?? 12),
        'seed'   => true,
        'fill'   => $fill,
        'timeout' => XERIC_WEB_BUILD_CALL_TIMEOUT,
        'guard'   => $stop,
    ], $onNote);

    $template = $out['template'];
    $seed = $out['seed'];
    // FORGE.md principle 3: the forge proposes, the user disposes. A world
    // arrives on the anvil, not in play — review.php's launch button is what
    // makes it playable, and it is one tap from here and one tap from the world.
    $template = xeric_review_mark_pending($template);
    $path = xeric_forge_write($template, $seed, xeric_web_worlds_dir());
    $slug = basename(dirname($path));
    $onNote('written: worlds/' . $slug . '/world-template.json');

    $meta = [
        'slug' => $slug,
        'seconds' => microtime(true) - $t0,
        'notes' => (array)$out['notes'],
        'endpoint' => xeric_web_endpoint_label($endpoint),
        'json_url' => 'world.php?w=' . rawurlencode($slug),
        'review'   => !xeric_review_launched($template),
    ];

    // WHOSE WORLD THIS IS. Claimed before the result frame goes out, so the
    // screen that announces it can already say "yours" — and so the world is
    // theirs even if the browser never comes back for the answer.
    if (preg_match('/^[a-f0-9]{32}$/', $sid)) {
        xeric_session_claim($slug, $sid);
        $meta['mine'] = true;
        $meta['left'] = xeric_limit_left($sid);

        // The session the wizard is holding gets the result, so forge.php?w=<slug>
        // can show the same page with the same notes tomorrow.
        // Under the lock: xeric_session_claim() writes 'own' into this same
        // record, and a read-then-write pair here would drop the claim on the
        // world this build just made — the visitor would own nothing.
        xeric_web_session_edit(function (array &$sess) use ($template, $answers, $interview, $meta): void {
            $sess['answers'] = xeric_web_clean_answers((array)($template['forge']['answers'] ?? $answers), $interview);
            $sess['result'] = $meta;
            unset($sess['job']);
        }, $sid);
    }

    xeric_web_job_append($job, [
        'k' => 'done', 't' => $el(),
        'slug' => $slug,
        'name' => (string)$template['meta']['name'],
        'seconds' => round($meta['seconds'], 1),
        'url' => 'forge.php?w=' . rawurlencode($slug),
        'html' => xeric_web_result_html($template, $seed, $meta),
    ]);
} catch (Throwable $e) {
    // xeric_forge_build() is written never to throw for a model's sake, so
    // anything here is ours: the hold being taken back mid-pass, a disk we
    // cannot write, a template even the defaults could not validate. A stop is
    // not a fault and is named separately, so the screen does not offer to
    // report a bug about the owner reclaiming their own GPU.
    xeric_web_job_append($job, ['k' => 'error', 't' => $el(), 'kind' => $stopped ? 'stopped' : 'forge',
        'message' => $e->getMessage()]);
} finally {
    // Hand the slot back and tell the queue what it cost, so the next visitor's
    // estimate is measured rather than guessed.
    if (is_array($lock)) xeric_queue_release($lock);
}
exit(0);
