<?php
/**
 * Xeric — photos. The world as pictures, composed here and rendered elsewhere.
 *
 * THE FORGE CARRIES THE DATA NOW; THE IMAGES ARRIVE WHEN A MACHINE DOES.
 * Nothing in this file talks to a renderer. It answers two questions any
 * image provider will eventually ask — WHO does this person look like, and
 * WHAT is in this frame — deterministically, from template data, so that the
 * day an endpoint exists (xeric_image_endpoint), every world ever forged is
 * already photographable and every photo of the same person is the same
 * person. Until then the whole feature is dormant and costs nothing.
 *
 * MODEL PROPOSES, CODE DISPOSES — for pictures too. A model may pick the
 * MOMENT and the framing (a selfie at the counter, the light doing something
 * on the water); it never composes the final prompt. This file does, from:
 *
 *   - the FACE seed (photos.face_seed, minted at forge time) and the BODY
 *     seed and build phrase, so a face and a frame agree across months;
 *   - the CLOTHING overlay (`wears`, seeded per garment) and the ITEM overlay
 *     (`carries`, seeded per object) — the same coat is the same coat, the
 *     pocket watch that shows up in June is the watch from March;
 *   - the BACKGROUND: the place's description and `interior` list, the day's
 *     derived weather, so an hour's photo and its arrival beat and its sweep
 *     all touch the same chairs under the same sky;
 *   - the AESTHETIC: the world's own locale and era, so an 1873 fog town
 *     never renders a phone into its own hands.
 *
 * PER-THING SEEDS ARE DERIVED, NEVER STORED. crc32(world|handle|the words) —
 * so there is no migration, no sidecar, and no way for two surfaces to
 * disagree; edit the coat in the review and it is honestly a new coat.
 *
 * THE AGE RULE IS STRUCTURAL, decided on the punchlist before any of this
 * was allowed to land: a minor's image prompt is FORCED to the weakest
 * rating with wholesome framing appended in code, whatever the world's
 * rating and whatever the model proposed — and an image provider's own
 * classifier is never the only control. The adult ceiling is the world's
 * effective rating, capped in code the same way.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/weather.php';
require_once __DIR__ . '/state.php';    // the queue is rows; clock.php rides in with it
require_once __DIR__ . '/clock.php';    // render-time prompts read the world's own now

/**
 * The image machine, if this install has one. Null is dormant, and dormant
 * is the shipping state: XERIC_IMAGE_BASE names an endpoint when one exists
 * (any provider — the adapter that speaks to it lives beside llm.php the day
 * it is needed), and nothing in the engine may treat its absence as an error.
 */
function xeric_image_endpoint(): ?array
{
    $base = trim((string)(getenv('XERIC_IMAGE_BASE') ?: ''));
    if ($base === '') return null;
    return ['base' => $base, 'key' => (string)(getenv('XERIC_IMAGE_KEY') ?: '')];
}

/**
 * IS the machine actually answering? Detection, not configuration: the app
 * decides photo-versus-caption by asking, the way xeric_llm_up() asks, so an
 * endpoint that is configured but down degrades to captions instead of to
 * broken image bubbles. A stub is always up (the tests' seam); a closed port
 * and a 404 are both "no imaging here", and no caller needs to tell them
 * apart. Short timeout, never a thrown error — absence is a mode, not a fault.
 */
function xeric_image_up(?array $endpoint = null, int $timeout = 3): bool
{
    $endpoint ??= xeric_image_endpoint();
    if ($endpoint === null) return false;
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) return true;

    $base = rtrim((string)($endpoint['base'] ?? ''), '/');
    if ($base === '') return false;
    $headers = ['Accept: application/json'];
    if ((string)($endpoint['key'] ?? '') !== '') {
        $headers[] = 'Authorization: Bearer ' . (string)$endpoint['key'];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $headers),
        'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    return @file_get_contents($base, false, $ctx) !== false;
}

/** A stable seed for one named thing in one world. Derived, never stored. */
function xeric_photo_seed(array $t, string $scope, string $thing): int
{
    return crc32((string)($t['meta']['name'] ?? 'xeric') . '|' . $scope . '|' . mb_strtolower(trim($thing)));
}

/**
 * A place's own seeds: the outside, the inside, and every named thing in it.
 *
 * Two shells and their contents, seeded apart on purpose: the Bluebird's
 * street face in June is its street face in March, the room behind the door
 * is consistently THAT room, and the pie case is the same pie case in every
 * frame it appears in — the same discipline the wears/carries seeds keep for
 * people, applied to rooms. Derived, never stored, like everything here.
 *
 * @return ?array{exterior:int,interior:int,items:array<int,array{text:string,seed:int}>}
 */
function xeric_photo_place_seeds(array $t, string $key): ?array
{
    $p = xeric_world_place($t, $key);
    if ($p === null) return null;

    $items = [];
    foreach ((array)($p['interior'] ?? []) as $item) {
        $s = trim(xeric_text($item));
        if ($s !== '') $items[] = ['text' => $s, 'seed' => xeric_photo_seed($t, $key . '.item', $s)];
    }
    return [
        'exterior' => xeric_photo_seed($t, 'place.exterior', $key),
        'interior' => xeric_photo_seed($t, 'place.interior', $key),
        'items'    => $items,
    ];
}

/**
 * WHO this person looks like — the identity block every photo of them shares.
 *
 * Face and body seeds anchor the renders; the build phrase, the garments and
 * the carried objects ride as text with their own per-thing seeds, so a
 * provider that honors regional seeds keeps the coat the same coat and one
 * that does not still gets the same words every time. Null for a handle
 * nobody answers to — fail closed, like every resolver here.
 *
 * @return ?array{handle:string,name:string,face_seed:int,body_seed:int,
 *                build:string,appearance:string,wears:array,carries:array,minor:bool}
 */
function xeric_photo_identity(array $t, string $handle): ?array
{
    $c = xeric_world_character($t, $handle);
    if ($c === null) return null;

    $things = function (string $key) use ($c, $t, $handle): array {
        $out = [];
        foreach ((array)($c[$key] ?? []) as $item) {
            $s = trim(xeric_text($item));
            if ($s !== '') $out[] = ['text' => $s, 'seed' => xeric_photo_seed($t, $handle . '.' . $key, $s)];
        }
        return $out;
    };

    $face = (int)($c['photos']['face_seed'] ?? 0);
    if ($face <= 0) $face = xeric_photo_seed($t, 'face', $handle);   // pre-photos worlds still photograph

    return [
        'handle'     => $handle,
        'name'       => xeric_world_name($t, $handle) ?: $handle,
        'face_seed'  => $face,
        // The body is its own seed, derived beside the face rather than from a
        // second stored number: no migration, and the pair can never split.
        'body_seed'  => (int)($c['photos']['body_seed'] ?? xeric_photo_seed($t, 'body', $handle . '#' . $face)),
        'build'      => trim(xeric_text($c['build'] ?? '')),
        'appearance' => trim(xeric_text($c['appearance'] ?? '')),
        'wears'      => $things('wears'),
        'carries'    => $things('carries'),
        'minor'      => xeric_is_minor($c),
    ];
}

/**
 * WHAT is in the frame — the final prompt, composed, capped, and framed.
 *
 * $ask is the model's (or the narrator's) proposal: the moment, in words. It
 * is folded in as a CLAUSE, never trusted as the prompt — everything
 * structural around it comes from the template, and the rating language is
 * appended by code after the proposal so it cannot be negotiated away.
 *
 * Kinds: 'portrait' (identity, once per character), 'message' (a photo sent
 * in a thread — rare and motivated, the punchlist's words), 'place' (the
 * narrator's establishing shot, nobody in frame).
 *
 * @return array{prompt:string,seeds:array,kind:string,rating:string}
 */
function xeric_photo_prompt(array $t, string $kind, array $opts = []): array
{
    $era    = trim(xeric_text($t['setting']['era'] ?? ''));
    $locale = trim(xeric_text($t['setting']['locale'] ?? ''));
    $eff    = (string)($opts['effective_rating'] ?? xeric_world_rating($t));

    $bits  = [];
    $seeds = [];

    // The aesthetic first: the world's own light. Era-true is the one rule an
    // image cannot cheat quietly — a phone in an 1873 hand is a broken photo.
    if ($locale !== '') $bits[] = $locale;
    if ($era !== '')    $bits[] = 'period-true to ' . $era . ', nothing anachronistic in frame';

    // The subject, when the frame has one.
    $who = null;
    if (($opts['handle'] ?? '') !== '') {
        $who = xeric_photo_identity($t, (string)$opts['handle']);
        if ($who === null) {
            throw new RuntimeException("photo: nobody answers to '" . (string)$opts['handle'] . "'");
        }
        $line = $who['name'];
        if ($who['build'] !== '')      $line .= ', ' . rtrim($who['build'], '.');
        if ($who['appearance'] !== '') $line .= ', ' . rtrim($who['appearance'], '.');
        $bits[] = $line;
        if ($who['wears'] !== []) {
            $bits[] = 'wearing ' . implode(', ', array_column($who['wears'], 'text'));
        }
        if (!empty($opts['with_carries']) && $who['carries'] !== []) {
            $bits[] = 'with ' . implode(', ', array_column($who['carries'], 'text'));
        }
        $seeds = ['face' => $who['face_seed'], 'body' => $who['body_seed'],
                  'things' => array_merge($who['wears'], $who['carries'])];
    }

    // The background: the room and the day, the same data every other surface
    // reads, so the photo agrees with the arrival beat and the sweep about
    // which chairs exist and what the sky is doing.
    $placeName = '';
    if (($opts['place'] ?? '') !== '') {
        $p = xeric_world_place($t, (string)$opts['place']);
        if ($p !== null) {
            $placeName = (string)($p['name'] ?? $opts['place']);
            $bg = 'at ' . $placeName;
            $desc = trim(xeric_text($p['description'] ?? ''));
            if ($desc !== '') $bg .= ', ' . rtrim($desc, '.');
            $furn = array_filter(array_map(fn($i) => trim(xeric_text($i)), (array)($p['interior'] ?? [])));
            if ($furn !== []) $bg .= '; in the room: ' . implode(', ', $furn);
            $bits[] = $bg;
            // The full block — shell, room, and every named thing in it — so a
            // provider that honors regional seeds keeps the pie case the pie case.
            $seeds['place'] = xeric_photo_place_seeds($t, (string)$opts['place']);
        }
    }
    if (!empty($opts['now']) && is_array($opts['now'])) {
        $wx = xeric_weather_line($t, $opts['now']);
        if ($wx !== '') $bits[] = rtrim($wx, '.');
    }

    // The proposal, folded in as a clause. Whatever it says, the rating
    // language lands AFTER it and is not up for negotiation.
    $ask = trim((string)($opts['ask'] ?? ''));
    if ($ask !== '') $bits[] = rtrim($ask, '.');

    // THE STRUCTURAL CAP. A minor in frame forces the floor — wholesome,
    // clothed, daylight terms appended in code, whatever the world's rating,
    // whatever the ask said, and never left to a provider's classifier.
    // Everyone else gets the world's effective tier, said in the rating
    // ladder's own language.
    if ($who !== null && $who['minor']) {
        $eff = 'sfw';
        $bits[] = 'a wholesome everyday photograph, fully clothed, ordinary daylight, '
                . 'suitable for a family album';
    } else {
        $bits[] = match ($eff) {
            'sfw', 'pg' => 'a wholesome everyday photograph, nothing suggestive',
            'teen'      => 'an everyday photograph, nothing explicit',
            default     => 'a photograph in keeping with the world\'s adult rating',
        };
    }

    return ['prompt' => implode('. ', $bits) . '.', 'seeds' => $seeds, 'kind' => $kind, 'rating' => $eff,
            // The structured parts ride along for the caption: deriving six
            // words from prose would mean parsing our own sentence back.
            'parts' => ['who' => $who !== null ? $who['name'] : '', 'ask' => $ask, 'place' => $placeName]];
}

/**
 * The photo when there is no photo: six-to-eight words derived from the
 * prompt, standing where the image would.
 *
 * A world without an image machine still SENDS photos — a character reaches
 * for their camera whether or not this install can develop the film — and the
 * thread renders the moment as a short line instead of a bubble of nothing:
 * "photo: Ruth by the urn, mid-laugh". Derived from the composed parts, in
 * code, deterministically: same prompt, same caption, byte for byte, which
 * matters because the caption is stored in the thread and re-rendered
 * forever. When imaging arrives (xeric_image_up), the same composed prompt
 * renders for real and this line becomes the alt text it always secretly was.
 *
 * Word budget is a hard eight: the name and the ask's first clause carry the
 * moment, the place tops it up when the ask ran short, and whatever is left
 * is cut at the count rather than summarised — a trimmed clause reads as a
 * caption, a summary reads as a review.
 */
function xeric_photo_caption(array $composed): string
{
    $parts = (array)($composed['parts'] ?? []);
    $who   = trim((string)($parts['who'] ?? ''));
    $ask   = trim((string)($parts['ask'] ?? ''));
    $place = trim((string)($parts['place'] ?? ''));

    // The ask's FIRST clause: the moment, not the art direction.
    $clause = trim((string)preg_split('/[,;.]/u', $ask)[0]);

    $words = [];
    if ($who !== '') $words[] = $who;
    foreach (preg_split('/\s+/u', $clause) ?: [] as $w) {
        if ($w !== '') $words[] = $w;
    }
    // Short of six? The place tops it up — "at the Bluebird" earns its words.
    if (count($words) < 6 && $place !== '') {
        foreach (explode(' ', 'at ' . $place) as $w) $words[] = $w;
    }
    if ($words === []) $words = ['a', 'photograph'];

    return implode(' ', array_slice($words, 0, 8));
}

// ---------------------------------------------------------------------------
// The queue, and the reaper that drains it
// ---------------------------------------------------------------------------

/**
 * Everything this world owes itself a picture of, enqueued. IDEMPOTENT: the
 * unique (kind, subject) index means every open may offer the whole cast and
 * every place, and the table keeps one row each — which is also the whole
 * backfill story for worlds forged before photos existed: opening them is
 * enqueuing them. Captions are written at enqueue time so every job has its
 * stand-in line from the first moment anything renders a gallery.
 *
 * @return array{portraits:int,places:int} rows actually added (not offered)
 */
function xeric_photo_backfill(array $t, PDO $db, ?int $at = null): array
{
    $at  = $at ?? xeric_state_time();
    $ins = $db->prepare('INSERT OR IGNORE INTO photo_jobs (kind, subject, caption, created_at)
                         VALUES (?, ?, ?, ?)');
    $n   = ['portraits' => 0, 'places' => 0];

    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $cap = xeric_photo_caption(xeric_photo_prompt($t, 'portrait', ['handle' => $h, 'ask' => 'a portrait']));
        $ins->execute(['portrait', $h, $cap, $at]);
        $n['portraits'] += $ins->rowCount();
    }
    foreach ((array)($t['places'] ?? []) as $p) {
        $k = (string)($p['key'] ?? '');
        if ($k === '') continue;
        $cap = xeric_photo_caption(xeric_photo_prompt($t, 'place', ['place' => $k]));
        $ins->execute(['place', $k, $cap, $at]);
        $n['places'] += $ins->rowCount();
    }
    return $n;
}

/** The jobs, newest last; $status narrows, null is everything. */
function xeric_photo_jobs(PDO $db, ?string $status = null): array
{
    $st = $status === null
        ? $db->query('SELECT * FROM photo_jobs ORDER BY id')
        : $db->prepare('SELECT * FROM photo_jobs WHERE status = ? ORDER BY id');
    if ($status !== null) $st->execute([$status]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows;
}

/** One subject's finished pictures — what a cog page's gallery reads. */
function xeric_photo_of(PDO $db, string $kind, string $subject): ?array
{
    $st = $db->prepare('SELECT * FROM photo_jobs WHERE kind = ? AND subject = ? LIMIT 1');
    $st->execute([$kind, $subject]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows ? $rows[0] : null;
}

/**
 * Render one image. The ADAPTER — the one function that will ever speak to an
 * image provider, llm.php's discipline applied to pictures. A stub endpoint
 * returns its own bytes (the tests' seam, and the only path exercised until a
 * real machine exists); the live path speaks the common images-API shape and
 * takes the first base64 answer it is given. Throws on nothing usable — the
 * reaper counts that as a try, not a catastrophe.
 *
 * @return array{bytes:string,usage:array}
 */
function xeric_image_render(array $endpoint, array $composed, array $opts = []): array
{
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
        $out = ($endpoint['stub'])('image', $composed, $opts);
        if (!is_array($out) || !isset($out['bytes'])) {
            throw new RuntimeException('photo: the stub returned no image');
        }
        return ['bytes' => (string)$out['bytes'], 'usage' => (array)($out['usage'] ?? [])];
    }

    $base = rtrim((string)($endpoint['base'] ?? ''), '/');
    if ($base === '') throw new RuntimeException('photo: no image machine is configured');
    $body = json_encode([
        'prompt' => (string)$composed['prompt'],
        'seed'   => (int)($composed['seeds']['face'] ?? $composed['seeds']['place']['exterior'] ?? 0),
        'n'      => 1,
        'response_format' => 'b64_json',
    ], JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json'];
    if ((string)($endpoint['key'] ?? '') !== '') $headers[] = 'Authorization: Bearer ' . $endpoint['key'];
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => (int)($opts['timeout'] ?? 120), 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($base . '/v1/images/generations', false, $ctx);
    $j   = is_string($raw) ? json_decode($raw, true) : null;
    $b64 = (string)($j['data'][0]['b64_json'] ?? '');
    if ($b64 === '') throw new RuntimeException('photo: the image machine answered with no image');
    $bytes = base64_decode($b64, true);
    if ($bytes === false) throw new RuntimeException('photo: the image machine answered with bad base64');
    return ['bytes' => $bytes, 'usage' => (array)($j['usage'] ?? [])];
}

/**
 * The image spend's sink, llm.php's meter discipline: photo.php counts
 * nothing itself, it hands each render to whoever registered — and the web
 * layer registers the "wasted tokens" ledger, so a render-happy afternoon is
 * as legible as a chatty one. A reaper that spent quietly would be the one
 * unattended cost the terms page promises does not exist.
 */
function xeric_photo_meter($sink = null): ?callable
{
    static $fn = null;
    if ($sink !== null) $fn = $sink;
    return $fn;
}

/**
 * Drain up to $limit jobs — the REAPER's one working function.
 *
 * The gate is threefold and checked in the cheap order: somebody registered
 * consent ('photos.approved' in world_state — the first-hookup offer's yes),
 * jobs are pending, and the machine actually answers (xeric_image_up — the
 * expensive check goes last). Prompts are composed AT RENDER TIME, not
 * enqueue time: the seeds make late rendering safe, and a coat edited in the
 * review between forge and render photographs as edited. Files land under
 * $photosDir as kind-subject.png — beside whichever db this is, so a fork's
 * photos are the fork's. A job that throws burns a try and goes failed at
 * three; a failed world is a world of captions, which still works.
 *
 * @return array{done:int,failed:int,notes:string[]}
 */
function xeric_photo_reap(array $t, PDO $db, string $photosDir, ?array $endpoint = null, int $limit = 1): array
{
    $out = ['done' => 0, 'failed' => 0, 'notes' => []];

    if ((string)(xeric_world_state_get($db, 'photos.approved') ?? '') !== '1') {
        $out['notes'][] = 'photos are not approved for this world yet';
        return $out;
    }

    // Stale claims BEFORE the pending read, so this very pass can finish what
    // a dead reaper dropped: a worker that died mid-frame left 'working' rows
    // behind, and ten minutes is longer than any render. done_at doubles as
    // the claim stamp while a job is working — it becomes the real done time
    // on completion, and a pending row never carries one.
    $db->prepare("UPDATE photo_jobs SET status = 'pending', done_at = NULL
                  WHERE status = 'working' AND done_at < ?")->execute([xeric_state_time() - 600]);

    $pending = xeric_photo_jobs($db, 'pending');
    if ($pending === []) return $out;

    $endpoint ??= xeric_image_endpoint();
    if (!xeric_image_up($endpoint)) { $out['notes'][] = 'no image machine is answering'; return $out; }

    if (!is_dir($photosDir) && !@mkdir($photosDir, 0775, true) && !is_dir($photosDir)) {
        $out['notes'][] = 'the photos directory cannot be created';
        return $out;
    }

    $now   = xeric_clock_now($db, $t);
    $claim = $db->prepare("UPDATE photo_jobs SET status = 'working', done_at = ?
                           WHERE id = ? AND status = 'pending'");
    foreach (array_slice($pending, 0, max(1, $limit)) as $job) {
        $kind = (string)$job['kind'];
        $subj = (string)$job['subject'];
        // THE CLAIM IS THE UPDATE: two reapers behind one conversation both
        // reach for the same row and exactly one rowCount comes back 1 —
        // SQLite serializes writers, so this needs no lock of its own.
        $claim->execute([xeric_state_time(), (int)$job['id']]);
        if ($claim->rowCount() !== 1) continue;
        try {
            if ($kind === 'message') {
                // A photo sent in a thread: subject is handle#messageId, the
                // ask is the model's own proposal, and the frame is wherever
                // that character is STANDING at render time — the reaper runs
                // behind the conversation, and a photo taken twenty minutes
                // after it was promised is taken where its taker now is.
                $mh = strstr($subj, '#', true) ?: $subj;
                $at = xeric_world_who_is_where($t, $now)[$mh]['where'] ?? null;
                $composed = xeric_photo_prompt($t, 'message', [
                    'handle' => $mh, 'ask' => (string)($job['ask'] ?? ''),
                    'place' => $at !== null ? (string)$at : '', 'now' => $now,
                    'with_carries' => true,
                ]);
            } else {
                $composed = $kind === 'place'
                    ? xeric_photo_prompt($t, 'place', ['place' => $subj, 'now' => $now])
                    : xeric_photo_prompt($t, 'portrait', ['handle' => $subj, 'ask' => 'a portrait']);
            }
            $img  = xeric_image_render($endpoint, $composed);
            $file = $kind . '-' . preg_replace('/[^a-z0-9_-]+/i', '_', $subj) . '.png';
            if (@file_put_contents($photosDir . '/' . $file, $img['bytes']) === false) {
                throw new RuntimeException('the photo could not be written to disk');
            }
            $st = $db->prepare('UPDATE photo_jobs SET status = ?, file = ?, tries = tries + 1, done_at = ?
                                WHERE id = ?');
            $st->execute(['done', $file, xeric_state_time(), (int)$job['id']]);
            $out['done']++;
            if (($fn = xeric_photo_meter()) !== null) {
                $fn(['images' => 1] + $img['usage'], (string)($endpoint['base'] ?? 'stub'));
            }
        } catch (Throwable $e) {
            // Back to pending (or failed) — and done_at comes off with the
            // claim, because a pending row never carries one.
            $tries = (int)$job['tries'] + 1;
            $st = $db->prepare('UPDATE photo_jobs SET status = ?, tries = ?, done_at = NULL WHERE id = ?');
            $st->execute([$tries >= 3 ? 'failed' : 'pending', $tries, (int)$job['id']]);
            if ($tries >= 3) $out['failed']++;
            $out['notes'][] = $kind . ' of ' . $subj . ': ' . $e->getMessage();
        }
    }
    return $out;
}

/**
 * A message-photo job: the character's promise to send a picture, queued
 * behind the conversation. Subject is handle#messageId — the message is the
 * caption row already sitting in the thread, and the renderer swaps the image
 * in over it the moment this job is done. The unique (kind, subject) index
 * holds because every message id is its own subject.
 */
function xeric_photo_enqueue_message(PDO $db, string $handle, int $messageId, int $convId,
                                     string $ask, string $caption, ?int $at = null): int
{
    $ins = $db->prepare('INSERT OR IGNORE INTO photo_jobs (kind, subject, caption, ask, conv, created_at)
                         VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute(['message', $handle . '#' . $messageId, $caption, $ask, $convId, $at ?? xeric_state_time()]);
    return (int)$db->lastInsertId();
}

/** The done message-photos of one conversation, mapped message-id => job row. */
function xeric_photo_thread(PDO $db, int $convId): array
{
    $st = $db->prepare("SELECT * FROM photo_jobs WHERE kind = 'message' AND conv = ?");
    $st->execute([$convId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $mid = (int)substr(strrchr((string)$row['subject'], '#') ?: '#0', 1);
        if ($mid > 0) $out[$mid] = $row;
    }
    $st->closeCursor();
    return $out;
}

/**
 * Does rendering here SPEND MONEY? Loopback is the owner's own electricity;
 * anything else is somebody's API key, and every surface that offers to
 * render is obliged to say so out loud before the yes. This is the one bit
 * every cost warning branches on.
 */
function xeric_image_costly(?array $endpoint = null): bool
{
    $endpoint ??= xeric_image_endpoint();
    if ($endpoint === null) return false;
    if (isset($endpoint['stub'])) return false;
    $host = (string)parse_url((string)($endpoint['base'] ?? ''), PHP_URL_HOST);
    return !in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
}

/**
 * Which place's photograph becomes the world's cover art. DETERMINISTIC — the
 * shelf must not reshuffle per visit — and the pick is the room the player's
 * life actually happens in: the workplace, else the first place declared.
 */
function xeric_photo_tile_place(array $t): ?string
{
    $wk = (string)($t['user']['occupation']['workplace_key'] ?? '');
    if ($wk !== '' && xeric_world_place($t, $wk) !== null) return $wk;
    foreach ((array)($t['places'] ?? []) as $p) {
        if (!empty($p['user_workplace']) && (string)($p['key'] ?? '') !== '') return (string)$p['key'];
    }
    foreach ((array)($t['places'] ?? []) as $p) {
        if ((string)($p['key'] ?? '') !== '') return (string)$p['key'];
    }
    return null;
}

/**
 * Should the app ask "generate photos now?" — the FIRST-HOOKUP offer.
 *
 * True exactly once per world: an image machine is answering, jobs are
 * waiting, and nobody has been asked before ('photos.asked'). The caller
 * that shows the question stamps photos.asked whatever the answer, and a yes
 * stamps photos.approved — asking is not consent, and silence is not either.
 */
function xeric_photo_offer(PDO $db, ?array $endpoint = null): bool
{
    if ((string)(xeric_world_state_get($db, 'photos.asked') ?? '') === '1') return false;
    if ((string)(xeric_world_state_get($db, 'photos.approved') ?? '') === '1') return false;
    if (xeric_photo_jobs($db, 'pending') === []) return false;
    return xeric_image_up($endpoint ?? xeric_image_endpoint());
}
