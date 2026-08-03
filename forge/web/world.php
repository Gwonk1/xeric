<?php
/**
 * world.php — the world as the file it actually is.
 *
 * "Your world is a file you own" is on the front page, so the forge had better
 * be able to show you the file. Serves worlds/<slug>/world-template.json (or
 * seed.json) as JSON, straight off disk, no rewriting.
 *
 * ONLY TO ITS OWNER, THOUGH. Every world on this demo is on everybody's shelf
 * and the play view links this page in its own footer, so unguarded this is a
 * button that hands any passer-by every must_not_know, every drives.pull and
 * every knowledge_walls[].hidden in somebody else's world — the panel three
 * inches above that link prints only the protected character's NAME, on purpose.
 * A stranger gets the same file with the interiors taken out, because the shape
 * of a world is the demo's best argument and the secrets are not part of it.
 * WHICH interiors is not this page's opinion — xeric_play_redact() holds it, for
 * the same reason xeric_play_guard() holds the ownership check: four pages each
 * keeping their own copy of this rule is how one of them came to have none.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';         // → ui.php → boot.php; the ownership rule and the redaction

$slug = xeric_web_slug((string)($_GET['w'] ?? ''));
$which = ((string)($_GET['f'] ?? '') === 'seed') ? 'seed.json' : 'world-template.json';
$mine = $slug !== '' && xeric_session_owns($slug, xeric_session_id());

// This URL is linked from the footer of the play view and the review step, so
// the thing that most often hits a missing world here is a PERSON with a stale
// bookmark. xeric_play_no() answers them with a page and a client with an
// object; a file endpoint is exactly where the wrong one of those is tempting.
if ($slug === '') {
    xeric_play_no('No xeric was named, this URL needs ?w=<the xeric\'s folder name>.', 400,
                  'There is no file to show you');
}

$path = xeric_web_worlds_dir() . '/' . $slug . '/' . $which;
if (!is_file($path)) {
    xeric_play_no("No xeric called '$slug' has been forged here.", 404, 'There is no file to show you');
}

$json = function (string $body) use ($slug, $which): void {
    header('Content-Type: application/json; charset=utf-8');
    // The one model-written body served without it. tile.php, photo.php and the
    // debrief's artifact route all say nosniff; a browser will not sniff
    // application/json into HTML anyway, so this is the odd one out being
    // brought into line rather than a hole being closed.
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . $slug . '-' . $which . '"');
    header('Cache-Control: no-store');
    echo $body;
    exit;
};

// The owner gets the bytes on the disk, because that is the promise: this is
// your file, here it is, nothing has been done to it.
if ($mine) $json((string)file_get_contents($path));

// The baked past is prose about things that have already happened, written with
// the secrets in hand — there is no honest way to take them back out of a
// sentence, so a stranger does not get this one at all.
if ($which === 'seed.json') {
    xeric_play_no('The baked past of a xeric is only shown to whoever forged it. It is written in plain '
        . 'sentences that give away the things the xeric exists to keep, so there is no version of '
        . 'it that can be shown to everybody.', 403, 'That past is not yours to read',
        'The template, the shape of the xeric, its places and its people, is here: '
        . 'world.php?w=' . $slug);
}

$T = json_decode((string)file_get_contents($path), true);
if (!is_array($T)) {
    xeric_play_no("The file for '$slug' is on the disk but is not readable as JSON, so it cannot be shown safely.",
                  500, 'That file will not open');
}

// Somebody else's world, with the interiors left out — what the walls exist to
// hold, and nothing besides. Everything that survives it — the places, the week,
// the voices, the shape of the thing — is what makes a shelf of strangers'
// worlds worth looking at, and none of it is a secret.
//
// The list of what goes is xeric_play_redact()'s, not this file's. It was this
// file's, and it fell two fields short of its own stated rationale: `tells` and
// `solace` are walled interiors exactly like the other three, so a stranger was
// reading two things nobody living in that world is allowed to see.
[$T, $gone] = xeric_play_redact($T);

$T = ['_redacted' => 'This xeric was forged in a different browser, so this is the shape of it rather than '
        . 'the whole of it. Taken out: ' . ($gone === [] ? 'nothing, this xeric keeps no secrets'
            : implode(', ', $gone)) . '. Forge your own and its file is yours in full.']
     + $T;

$json((string)json_encode($T, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
