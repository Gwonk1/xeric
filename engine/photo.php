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
require_once __DIR__ . '/llm.php';      // xeric_llm_header_safe: a key is a header value, never a header
require_once __DIR__ . '/budget.php';   // the world's daily ceiling, read before every render

/**
 * The floor's framing, in words. One constant because two composers say it —
 * a photograph here and a whole room in scene.php — and the structural rule
 * is only structural if both of them say exactly the same thing.
 */
const XERIC_PHOTO_FLOOR_FRAMING = 'a wholesome everyday photograph, fully clothed, ordinary daylight, '
                                . 'suitable for a family album';

/**
 * The image machine, if this install has one. Null is dormant, and dormant
 * is the shipping state: XERIC_IMAGE_BASE names an endpoint when one exists
 * (any provider — the adapter that speaks to it lives beside llm.php the day
 * it is needed), and nothing in the engine may treat its absence as an error.
 */
function xeric_image_endpoint(): ?array
{
    // The web layer may register a resolver (the machines screen's stored
    // address, read at CALL time so a worker under a forced session sees that
    // session's choice). The engine's own answer stays the environment, so a
    // headless install needs nothing but two variables — and the engine never
    // reads a session, because that would be the layering running backwards.
    $fn = xeric_image_source();
    if ($fn !== null) {
        $ep = $fn();
        if (is_array($ep) && trim((string)($ep['base'] ?? '')) !== '') return $ep;
        if ($ep === false) return null;   // the resolver says: explicitly none
    }
    $base = trim((string)(getenv('XERIC_IMAGE_BASE') ?: ''));
    if ($base === '') return null;
    // XERIC_IMAGE_EDITS=1 says this machine answers /v1/images/edits, so a
    // headless install can chain portraits without the machines screen's probe.
    return ['base' => $base, 'key' => (string)(getenv('XERIC_IMAGE_KEY') ?: ''),
            'edits' => (string)(getenv('XERIC_IMAGE_EDITS') ?: '') === '1'];
}

/** The resolver seam, meter-discipline shaped: set once by the web layer. */
function xeric_image_source($fn = null): ?callable
{
    static $src = null;
    if ($fn !== null) $src = $fn;
    return $src;
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
        $headers[] = 'Authorization: Bearer ' . xeric_llm_header_safe((string)$endpoint['key']);
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

    // THE LORA LAYER — aesthetic, never identity. Harvested trigger words fold
    // in here, BEFORE the rating language, so the cap still gets the last
    // word; and a frame with a minor in it takes no LoRA at all, outright —
    // a style vocabulary trained on who-knows-what does not get within arm's
    // reach of the floor, belt and braces.
    $lorasUsed = [];
    if (!($who !== null && $who['minor'])) {
        foreach ((array)($opts['loras'] ?? []) as $l) {
            $lw = array_filter(array_map('strval', (array)($l['words'] ?? [])));
            if ($lw === [] || (string)($l['name'] ?? '') === '') continue;
            $bits[] = implode(', ', $lw);
            $lorasUsed[] = (string)$l['name'];
        }
    }

    // THE STRUCTURAL CAP. A minor in frame forces the floor — wholesome,
    // clothed, daylight terms appended in code, whatever the world's rating,
    // whatever the ask said, and never left to a provider's classifier.
    // Everyone else gets the world's effective tier, said in the rating
    // ladder's own language.
    if ($who !== null && $who['minor']) {
        $eff = 'sfw';
        $bits[] = XERIC_PHOTO_FLOOR_FRAMING;
    } else {
        $bits[] = match ($eff) {
            'sfw', 'pg' => 'a wholesome everyday photograph, nothing suggestive',
            'teen'      => 'an everyday photograph, nothing explicit',
            default     => 'a photograph in keeping with the world\'s adult rating',
        };
    }

    return ['prompt' => implode('. ', $bits) . '.', 'seeds' => $seeds, 'kind' => $kind, 'rating' => $eff,
            // The render path needs both: which LoRAs to load (a1111 tags are
            // its dialect, not the prompt's), and whether a minor is in frame
            // — the rating alone cannot say, since sfw is also a world tier.
            'loras' => $lorasUsed, 'minor' => $who !== null && $who['minor'],
            // WHO is in frame, by handle: the reaper's key for the likeness
            // this frame is conditioned on (xeric_photo_reference).
            'handle' => $who !== null ? $who['handle'] : '',
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
    $ins = $db->prepare('INSERT OR IGNORE INTO photo_jobs (kind, subject, caption, created_at, rating)
                         VALUES (?, ?, ?, ?, ?)');
    $n   = ['portraits' => 0, 'places' => 0];

    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $cap = xeric_photo_caption(xeric_photo_prompt($t, 'portrait', ['handle' => $h, 'ask' => 'a portrait']));
        $ins->execute(['portrait', $h, $cap, $at, xeric_state_rating()]);
        $n['portraits'] += $ins->rowCount();
    }
    foreach ((array)($t['places'] ?? []) as $p) {
        $k = (string)($p['key'] ?? '');
        if ($k === '') continue;
        $cap = xeric_photo_caption(xeric_photo_prompt($t, 'place', ['place' => $k]));
        $ins->execute(['place', $k, $cap, $at, xeric_state_rating()]);
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

// ---------------------------------------------------------------------------
// Portrait chaining: the first portrait is the likeness
// ---------------------------------------------------------------------------

/** The most a likeness may weigh and still ride along — the scene reference's cap. */
const XERIC_PHOTO_REFERENCE_CAP = 8 * 1024 * 1024;

/**
 * May this machine be shown a likeness? Only one that answers the images-edits
 * route (`edits`, probed by the machines screen on connect, or
 * XERIC_IMAGE_EDITS=1). sd-server and OpenAI-shaped servers do; a1111's own
 * API has img2img instead, which this adapter does not speak — an a1111
 * without an OpenAI shim in front of it renders every photo unchained, from
 * seeds and words alone, exactly as before. A stub is asked the same question.
 */
function xeric_image_takes_reference(?array $endpoint): bool
{
    return $endpoint !== null && !empty($endpoint['edits']);
}

/**
 * One picture file read as a likeness, or null: missing, empty, too heavy, or
 * not a whole png, jpeg or webp.
 *
 * NOT AN IMAGE IS NOT A PNG. This used to label anything getimagesize could
 * not read as image/png and hand it over anyway — a half-written file from a
 * reroll racing the worker, an error page saved as a picture — and the edits
 * route then conditioned a likeness on garbage, or refused the whole render.
 * Null instead, so the walk-back tries the one before and, failing that, the
 * photo renders unchained from seeds and words, which is what it did before a
 * likeness existed. Whole means the format's own end is in the bytes (png's
 * IEND chunk, jpeg's EOI marker, webp's RIFF length): getimagesize reads only
 * the header, and a truncated file has a perfect header.
 */
function xeric_photo_reference_read(string $path, int $cap = XERIC_PHOTO_REFERENCE_CAP): ?array
{
    // A worker lives for minutes and a reroll rewrites the file under it.
    clearstatcache(true, $path);
    $size = is_file($path) ? @filesize($path) : false;
    if ($size === false || $size === 0 || $size > $cap) return null;
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || $bytes === '') return null;
    $mime = xeric_photo_sniff($bytes);
    if ($mime === null) return null;
    return ['bytes' => $bytes, 'mime' => $mime, 'fingerprint' => substr(sha1($bytes), 0, 16)];
}

/**
 * The rating a photo render runs at, for the likeness walk: the lower of the
 * row's stamp (whose request it was) and this process's (what the prompt is
 * composed under). '' when neither is set.
 */
function xeric_photo_render_rating(string $stamp): string
{
    $low = '';
    foreach ([$stamp, xeric_state_rating()] as $r) {
        $r = strtolower(trim($r));
        if ($r === '') continue;
        if (!function_exists('xeric_rating_rank')) require_once __DIR__ . '/walls.php';
        if ($low === '' || xeric_rating_rank($r) < xeric_rating_rank($low)) $low = $r;
    }
    return $low;
}

/** image/png, image/jpeg or image/webp when these bytes are a whole one of those, else null. */
function xeric_photo_sniff(string $bytes): ?string
{
    $mime = null;
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        if (substr($bytes, 12, 4) === 'IHDR' && str_contains(substr($bytes, 33), "IEND\xae\x42\x60\x82")) $mime = 'image/png';
    } elseif (str_starts_with($bytes, "\xff\xd8\xff")) {
        if (str_contains(substr($bytes, 3), "\xff\xd9")) $mime = 'image/jpeg';
    } elseif (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        $len = unpack('V', substr($bytes, 4, 4))[1] ?? 0;
        if ($len >= 4 && strlen($bytes) >= $len + 8) $mime = 'image/webp';
    }
    if ($mime === null) return null;
    // And the header must read as the same thing to the library that will describe it.
    $seen = (string)(@getimagesizefromstring($bytes)['mime'] ?? '');
    return $seen === $mime ? $mime : null;
}

/**
 * The likeness every later photo of this person is conditioned on: their most
 * recent DONE portrait with its file on this disk. Walked back the way
 * xeric_scene_reference walks a room's looks — newest first, a file too heavy
 * or gone passed over for the one before, five deep — though today the
 * unique (kind, subject) index keeps one portrait row per person, and a
 * reroll re-renders that row in place. Null when nobody has been
 * photographed yet: the first portrait is the first picture and takes none.
 *
 * @return ?array{bytes:string,mime:string,fingerprint:string,id:int}
 */
function xeric_photo_reference(PDO $db, string $handle, string $photosDir, int $cap = XERIC_PHOTO_REFERENCE_CAP,
                               ?string $rating = null): ?array
{
    if ($handle === '') return null;
    $st = $db->prepare("SELECT id, file, rating FROM photo_jobs
                        WHERE kind = 'portrait' AND subject = ? AND status = 'done' AND file != ''
                        ORDER BY done_at DESC, id DESC LIMIT 50");
    $st->execute([$handle]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    // A likeness stamped above the rating this render runs at is no likeness
    // for it (xeric_scene_reference's rule): a kids' headset's photo is never
    // conditioned on an adult portrait. No rating filters nothing.
    if ($rating !== null && $rating !== '') {
        $rows = array_values(array_filter($rows, fn($r) => xeric_state_shown((string)($r['rating'] ?? ''), $rating)));
    }
    foreach (array_slice($rows, 0, 5) as $row) {
        $ref = xeric_photo_reference_read(rtrim($photosDir, '/') . '/' . basename((string)$row['file']), $cap);
        if ($ref !== null) return $ref + ['id' => (int)$row['id']];
    }
    return null;
}

/**
 * A multipart/form-data body, built by hand (streams have no CURLFile).
 * Text fields first, then the one file part.
 *
 * @return array{0:string,1:string} [content-type header value, body]
 */
function xeric_image_multipart(array $fields, string $fileField, string $fileName, string $mime, string $bytes): array
{
    $b    = '----xeric' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"" . $k . "\"\r\n\r\n" . $v . "\r\n";
    }
    $body .= "--$b\r\nContent-Disposition: form-data; name=\"" . $fileField . '"; filename="' . $fileName . "\"\r\n"
           . 'Content-Type: ' . $mime . "\r\n\r\n" . $bytes . "\r\n--$b--\r\n";
    return ['multipart/form-data; boundary=' . $b, $body];
}

/**
 * Render one image. The ADAPTER — the one function that will ever speak to an
 * image provider, llm.php's discipline applied to pictures. A stub endpoint
 * returns its own bytes (the tests' seam, and the only path exercised until a
 * real machine exists); the live path speaks the common images-API shape and
 * takes the first base64 answer it is given. Throws on nothing usable — the
 * reaper counts that as a try, not a catastrophe.
 *
 * WITH A LIKENESS ($opts['reference'], {bytes, mime}) and a machine that
 * takes one (xeric_image_takes_reference), the same prompt and seed go as
 * multipart to {base}/v1/images/edits with image=<the likeness>; otherwise —
 * no reference, or a machine without the route — the generations call,
 * byte for byte what it always was. The likeness widens nothing: the prompt,
 * the rating cap and the minor's floor are composed before this is reached.
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

    // The LoRA loader tags, in the one dialect that reads them inline. Their
    // WORDS are already in the prompt (composed before the rating cap); the
    // tags are how an a1111-family server knows to load the weights at all.
    // Never on a minor's frame — compose already refused the words, and the
    // tags follow the same law.
    $prompt = (string)$composed['prompt'];
    if (strtolower((string)($endpoint['kind'] ?? '')) === 'a1111'
            && empty($composed['minor']) && (array)($composed['loras'] ?? []) !== []) {
        $tags = array_map(fn($n) => '<lora:' . $n . ':0.8>', (array)$composed['loras']);
        $prompt = implode(' ', $tags) . ' ' . $prompt;
    }
    $seed = (int)($composed['seeds']['face'] ?? $composed['seeds']['place']['exterior'] ?? 0);
    $ref  = $opts['reference'] ?? null;
    if (is_array($ref) && (string)($ref['bytes'] ?? '') !== '' && xeric_image_takes_reference($endpoint)) {
        $mime = (string)($ref['mime'] ?? 'image/png');
        $ext  = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/webp' ? 'webp' : 'png');
        [$ctype, $body] = xeric_image_multipart(
            ['prompt' => $prompt, 'seed' => (string)$seed, 'n' => '1', 'response_format' => 'b64_json'],
            'image', 'reference.' . $ext, $mime, (string)$ref['bytes']);
        $route = '/v1/images/edits';
    } else {
        $ctype = 'application/json';
        $body  = json_encode([
            'prompt' => $prompt,
            'seed'   => $seed,
            'n'      => 1,
            'response_format' => 'b64_json',
        ], JSON_UNESCAPED_UNICODE);
        $route = '/v1/images/generations';
    }
    $headers = ['Content-Type: ' . $ctype];
    if ((string)($endpoint['key'] ?? '') !== '') $headers[] = 'Authorization: Bearer ' . xeric_llm_header_safe((string)$endpoint['key']);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => (int)($opts['timeout'] ?? 120), 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($base . $route, false, $ctx);
    $j   = is_string($raw) ? json_decode($raw, true) : null;
    $b64 = (string)($j['data'][0]['b64_json'] ?? '');
    if ($b64 === '') throw new RuntimeException('photo: the image machine answered with no image');
    $bytes = base64_decode($b64, true);
    if ($bytes === false) throw new RuntimeException('photo: the image machine answered with bad base64');
    return ['bytes' => $bytes, 'usage' => (array)($j['usage'] ?? [])];
}

/**
 * Trigger words out of a LoRA's own training metadata.
 *
 * A safetensors LoRA carries `ss_tag_frequency` — the tag counts of the set
 * it was trained on — and the top few tags ARE its effective trigger
 * vocabulary, no guessing required. This takes that structure in any of the
 * shapes providers hand it back (a JSON string, a dict of dataset-dirs each
 * holding tag counts, or a flat tag => count map), merges, drops the booru
 * boilerplate every dataset carries (1girl, masterpiece, simple background —
 * words that describe every image and therefore none), and keeps the top
 * five. Pure, so the harvest is testable without a server.
 */
function xeric_image_lora_words($freq, int $keep = 5): array
{
    if (is_string($freq)) $freq = json_decode($freq, true);
    if (!is_array($freq)) return [];

    // Dataset-dir nesting flattens; a flat map passes through.
    $counts = [];
    foreach ($freq as $k => $v) {
        if (is_array($v)) { foreach ($v as $tag => $n) $counts[(string)$tag] = ($counts[(string)$tag] ?? 0) + (int)$n; }
        else $counts[(string)$k] = ($counts[(string)$k] ?? 0) + (int)$v;
    }

    static $noise = ['1girl', '1boy', '2girls', 'solo', 'highres', 'absurdres', 'lowres',
        'masterpiece', 'best quality', 'high quality', 'looking at viewer', 'simple background',
        'white background', 'grey background', 'realistic', 'photorealistic', 'blurry',
        'upper body', 'full body', 'portrait', 'smile', 'open mouth', 'closed mouth'];
    $out = [];
    foreach ($counts as $tag => $n) {
        $tag = trim(str_replace('_', ' ', mb_strtolower((string)$tag)));
        if ($tag === '' || in_array($tag, $noise, true)) continue;
        $out[$tag] = ($out[$tag] ?? 0) + $n;
    }
    arsort($out);
    return array_slice(array_keys($out), 0, max(1, $keep));
}

/**
 * The LoRAs an image machine is holding, each with its harvested words.
 *
 * A1111 answers /sdapi/v1/loras with everything embedded; ComfyUI names its
 * files through the object graph and hands each file's header back through
 * /view_metadata (capped — a hoard of two hundred LoRAs is not worth two
 * hundred requests on the one screen somebody is looking at). A file with no
 * usable metadata still lists, with its own name as the only word, editable
 * on the machines screen — a guess owned up to beats a file hidden.
 *
 * @return array<int,array{name:string,words:array<int,string>}>
 */
function xeric_image_loras(array $endpoint, int $cap = 25): array
{
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
        return (array)($endpoint['stub'])('loras', [], []);
    }
    $base = rtrim((string)($endpoint['base'] ?? ''), '/');
    if ($base === '') return [];
    $get = function (string $url) {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        return is_string($raw) ? json_decode($raw, true) : null;
    };

    $out = [];

    // A1111: one call, everything embedded.
    $rows = $get($base . '/sdapi/v1/loras');
    if (is_array($rows)) {
        foreach (array_slice($rows, 0, $cap) as $r) {
            if (!is_array($r)) continue;
            $name  = trim((string)($r['alias'] ?? $r['name'] ?? ''));
            if ($name === '') continue;
            $words = xeric_image_lora_words($r['metadata']['ss_tag_frequency'] ?? null);
            $out[] = ['name' => $name, 'words' => $words !== [] ? $words : [mb_strtolower($name)]];
        }
        return $out;
    }

    // ComfyUI: the graph names the files, the header endpoint yields each one.
    $info  = $get($base . '/object_info/LoraLoader');
    $files = (array)($info['LoraLoader']['input']['required']['lora_name'][0] ?? []);
    foreach (array_slice($files, 0, $cap) as $f) {
        $f = (string)$f;
        if ($f === '') continue;
        $meta  = $get($base . '/view_metadata/loras?filename=' . rawurlencode($f));
        $words = xeric_image_lora_words($meta['ss_tag_frequency'] ?? null);
        $name  = preg_replace('/\.(safetensors|pt|ckpt)$/i', '', basename($f));
        $out[] = ['name' => $name, 'words' => $words !== [] ? $words : [mb_strtolower((string)$name)]];
    }
    return $out;
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
    // THE WORLD'S CEILING, before the machine is even asked: out of renders
    // for today is a row that waits for tomorrow, never one that fails.
    $now     = xeric_clock_now($db, $t);
    $costly  = $endpoint !== null && xeric_image_costly($endpoint);
    $verdict = xeric_budget_allows($t, $db, $now, 'renders', 1, $costly);
    if (!$verdict['ok']) { $out['notes'][] = $verdict['reason']; return $out; }
    if (!xeric_image_up($endpoint)) { $out['notes'][] = 'no image machine is answering'; return $out; }

    if (!is_dir($photosDir) && !@mkdir($photosDir, 0775, true) && !is_dir($photosDir)) {
        $out['notes'][] = 'the photos directory cannot be created';
        return $out;
    }

    $claim = $db->prepare("UPDATE photo_jobs SET status = 'working', done_at = ?
                           WHERE id = ? AND status = 'pending'");
    foreach (array_slice($pending, 0, max(1, $limit)) as $job) {
        // Asked again per job: the render before this one may have been the last.
        $verdict = xeric_budget_allows($t, $db, $now, 'renders', 1, $costly);
        if (!$verdict['ok']) { $out['notes'][] = $verdict['reason']; break; }
        $kind = (string)$job['kind'];
        $subj = (string)$job['subject'];
        // THE CLAIM IS THE UPDATE: two reapers behind one conversation both
        // reach for the same row and exactly one rowCount comes back 1 —
        // SQLite serializes writers, so this needs no lock of its own.
        $claim->execute([xeric_state_time(), (int)$job['id']]);
        if ($claim->rowCount() !== 1) continue;
        try {
            // COMPOSED AT THE LOWER of the row's stamp (whose request queued
            // it — a kids' headset's photo is that headset's) and this
            // process's rating, capped by the world's. The reaper is whoever's
            // request happened to run it; an adult worker reaping a kids'
            // headset's photo must not compose it at the adult's rating.
            $renderR = xeric_photo_render_rating((string)($job['rating'] ?? ''));
            $worldR  = xeric_world_rating($t);
            $compose = $renderR !== '' && xeric_rating_rank($renderR) < xeric_rating_rank($worldR) ? $renderR : $worldR;
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
                    'with_carries' => true, 'loras' => (array)($endpoint['loras'] ?? []),
                    'effective_rating' => $compose,
                ]);
            } else {
                $composed = $kind === 'place'
                    ? xeric_photo_prompt($t, 'place', ['place' => $subj, 'now' => $now,
                                                       'loras' => (array)($endpoint['loras'] ?? []),
                                                       'effective_rating' => $compose])
                    : xeric_photo_prompt($t, 'portrait', ['handle' => $subj, 'ask' => 'a portrait',
                                                          'loras' => (array)($endpoint['loras'] ?? []),
                                                          'effective_rating' => $compose]);
            }
            // PORTRAIT CHAINING. Every later photo of a person is conditioned
            // on their portrait, on a machine that takes a likeness. A first
            // portrait has nothing to take; a portrait row that already has
            // its file on disk is a REROLL, and takes the picture it replaces.
            // A message, or any frame with a person in it, takes that
            // person's portrait. The minor's floor is already in the prompt,
            // and a likeness widens nothing.
            $rOpts = [];
            $ref   = null;
            if (xeric_image_takes_reference($endpoint)) {
                if ($kind === 'portrait') {
                    $own = (string)($job['file'] ?? '');
                    $ref = $own !== '' ? xeric_photo_reference_read($photosDir . '/' . basename($own)) : null;
                    if ($ref !== null) $ref['id'] = (int)$job['id'];
                } else {
                    $ref = xeric_photo_reference($db, (string)($composed['handle'] ?? ''), $photosDir,
                        XERIC_PHOTO_REFERENCE_CAP, $renderR);
                }
                if ($ref !== null) $rOpts['reference'] = $ref;
            }
            $img  = xeric_image_render($endpoint, $composed, $rOpts);
            $file = $kind . '-' . preg_replace('/[^a-z0-9_-]+/i', '_', $subj) . '.png';
            if (@file_put_contents($photosDir . '/' . $file, $img['bytes']) === false) {
                throw new RuntimeException('the photo could not be written to disk');
            }
            // The row remembers what it was conditioned on, and forgets a
            // reference an earlier render of it took and this one did not.
            $meta = json_decode((string)($job['meta'] ?? '{}'), true);
            if (!is_array($meta)) $meta = [];
            unset($meta['reference_of'], $meta['reference_fingerprint']);
            if ($ref !== null) {
                $meta['reference_of']          = (int)$ref['id'];
                $meta['reference_fingerprint'] = (string)$ref['fingerprint'];
            }
            // THE QUEUED STAMP STANDS. It was composed at or below it (above),
            // so re-stamping at whoever reaped it could only ever raise it — a
            // kids' headset's photo, reaped by an adult worker, locked out of
            // the very headset that asked for it. A row from before stamps
            // ('') takes the rating it was composed under; '' stays ''.
            $st = $db->prepare("UPDATE photo_jobs SET status = ?, file = ?, tries = tries + 1, done_at = ?, meta = ?,
                                rating = CASE WHEN rating = '' THEN ? ELSE rating END WHERE id = ?");
            $st->execute(['done', $file, xeric_state_time(),
                          json_encode((object)$meta, JSON_UNESCAPED_SLASHES),
                          $renderR, (int)$job['id']]);
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
    $ins = $db->prepare('INSERT OR IGNORE INTO photo_jobs (kind, subject, caption, ask, conv, created_at, rating)
                         VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute(['message', $handle . '#' . $messageId, $caption, $ask, $convId, $at ?? xeric_state_time(),
                   xeric_state_rating()]);
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
    // The one host test, the same one the budget's ledger asks.
    return !xeric_llm_host_local((string)($endpoint['base'] ?? ''));
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
