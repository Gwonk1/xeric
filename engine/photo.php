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
