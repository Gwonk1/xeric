<?php
/**
 * Xeric — scenes. The room you are standing in, described for a machine that
 * can show it to you.
 *
 * THE SIBLING OF photo.php, ONE SIZE UP. A photograph is a person in a frame;
 * a scene is the whole room at this minute — its shell and its furniture, the
 * light through its windows, the sky the cast was told about this morning,
 * and whoever is standing in it doing whatever their week says they are
 * doing. The machines that can turn that into something to look at (a
 * first-person still, an explorable room, a world model that streams) are
 * arriving faster than any of them is worth naming, so this file names none.
 * It answers the one question every one of them asks — WHAT IS HERE, NOW —
 * and hands the answer to whichever machine the owner chose.
 *
 * MODEL PROPOSES, CODE DISPOSES — for rooms too. Nothing in the brief is
 * invented and nothing is asked of a language model. It is read off canon:
 * the player's position (xeric_player_where), presence (who_is_where, the
 * dead excluded), the place's description and interior, the derived weather
 * WITH the database so the sky agrees with what the narrator told the cast,
 * the clock, the open/dark state. An `ask` may be folded in as a clause, the
 * way photo.php folds the model's proposal in; it is never the prompt, and
 * the rating language lands after it.
 *
 * ONLY OBSERVABLES. A scene is what a body in the doorway could SEE: who is
 * there, what they look like, what they are wearing, what they are at. Never
 * a wall, a secret, a psyche, a memory, a standing, a ledger — none of those
 * are read here at all, so none of them can leak by being mis-filtered. The
 * brief is safe to hand to a third-party machine whole because there is
 * nothing in it that was not already on the map (xeric_travel_map) or in a
 * portrait (xeric_photo_identity).
 *
 * THE AGE RULE IS THE SAME RULE, IN THE SAME WORDS. A minor anywhere in the
 * room forces the brief to the floor and appends photo.php's own wholesome
 * framing (XERIC_PHOTO_FLOOR_FRAMING — one constant, so the two composers
 * cannot drift), whatever the world's rating and whatever the ask said. A
 * machine's own classifier is never the only control. Everyone else is
 * rendered at the world's effective rating, passed through: the owner chose
 * the machine, and what it will draw is between them and it.
 *
 * NO OFFER, NO 'approved' GATE. Photos ask once ("generate photos now?")
 * because a forge enqueues a whole cast's worth of renders the owner never
 * individually requested. A scene is requested by somebody looking: the
 * player is standing in a room and a client asked to see it. The owner has
 * already chosen a scene machine (or an image machine) on the machines
 * screen, and that choice IS the consent. Dormant is still the shipping
 * state — no machine, no renders, no errors.
 *
 * KEYED BY WHAT THE ROOM LOOKS LIKE. One job per (place, fingerprint), and
 * the fingerprint is everything that changes the picture and nothing that
 * does not (xeric_scene_fingerprint says exactly what). Two visits in the
 * same light to the same company are the same scene and cost one render.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

require_once __DIR__ . '/photo.php';    // seeds, identity, the floor's words, the image machine
require_once __DIR__ . '/travel.php';   // position, open hours; death.php rides in with it
require_once __DIR__ . '/weather.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/clock.php';

// ---------------------------------------------------------------------------
// The template's own knobs
// ---------------------------------------------------------------------------

/**
 * `media.scene`, with its defaults. A missing block is the defaults; a
 * malformed one is read as far as it reads and the rest defaults — a world
 * forged before scenes existed is already viewable, and nothing here throws.
 *
 * @return array{enabled:bool,vantage:string,style:string,kinds:string[],quality:string,negative:string}
 */
function xeric_scene_config(array $t): array
{
    $c = $t['media']['scene'] ?? [];
    if (!is_array($c)) $c = [];
    $v = strtolower(trim((string)($c['vantage'] ?? 'first_person')));
    return [
        'enabled' => !array_key_exists('enabled', $c) || !empty($c['enabled']),
        // Two vantages and no third: an unknown word is the default, not an
        // error, because a typo in a template should not cost anyone the view.
        'vantage' => in_array($v, ['first_person', 'establishing'], true) ? $v : 'first_person',
        'style'   => trim(xeric_text($c['style'] ?? '')),
        // What the owner would like back, in preference order, said to the
        // machine as `kind_wanted`; the machine answers with what it made.
        'kinds'   => array_values(array_filter(array_map(fn($k) => strtolower(trim((string)$k)),
                         is_array($c['kinds'] ?? null) ? $c['kinds'] : []),
                         fn($k) => in_array($k, ['pano', 'image', 'splat', 'glb', 'viewer'], true)))
                     ?: ['pano', 'image'],
        'quality' => strtolower((string)($c['quality'] ?? '')) === 'draft' ? 'draft' : 'full',
        'negative'=> trim(xeric_text($c['negative'] ?? '')),
    ];
}

/**
 * The light, as a short phrase. DETERMINISTIC from the clock alone: the
 * phase is the engine's canon bucket (xeric_world_phase) and the hour splits
 * it where a window would. No latitude and no season — the same reason the
 * phase boundaries are not per-template: a light that drifted by date would
 * mint a new fingerprint every few days for a room that has not changed.
 */
function xeric_scene_light(array $now): string
{
    $m     = xeric_world_minutes((string)($now['hhmm'] ?? '')) ?? 0;
    $phase = (string)($now['phase'] ?? xeric_world_phase($m));
    return match ($phase) {
        'morning'   => $m < 390 ? 'pre-dawn' : ($m < 660 ? 'morning light' : 'noon'),
        'afternoon' => $m < 840 ? 'noon' : 'afternoon light',
        'evening'   => $m < 1140 ? 'golden hour' : ($m < 1260 ? 'dusk' : 'lamplit night'),
        default     => ($m >= 240 && $m < 300) ? 'pre-dawn' : 'lamplit night',
    };
}

// ---------------------------------------------------------------------------
// The brief
// ---------------------------------------------------------------------------

/**
 * The composed description of a place at a moment. THE thing a viewport
 * client is built against, the way xeric_travel_map is the thing a map is.
 *
 * $key null is the player's own room; a player who is nowhere has no room,
 * and null is the honest answer (nowhere is a real state). An unknown key,
 * or a home not yet in the story, is null too — fail closed, the same way the
 * map simply does not list an unstaged house.
 *
 * $opts: 'ask' (a clause folded in before the rating, never the prompt).
 *
 * @return ?array see the shape in the return statement; every field is an
 *         observable or a number derived from one.
 */
function xeric_scene_brief(array $t, PDO $db, ?string $key = null, ?array $now = null, array $opts = []): ?array
{
    $key ??= xeric_player_where($t, $db);
    if ($key === null || $key === '') return null;
    $p = xeric_world_place($t, $key);
    if ($p === null || xeric_travel_unstaged($t, $key)) return null;

    $now  ??= xeric_clock_now($db, $t);
    $cfg    = xeric_scene_config($t);
    $dark   = xeric_dark_places($db);
    $seeds  = xeric_photo_place_seeds($t, $key);
    $light  = xeric_scene_light($now);
    $wx     = trim(xeric_weather_line($t, $now, $db));
    $open   = xeric_travel_open($t, $key, $now, $dark);
    $isDark = in_array($key, $dark, true);

    // Who is here, and only what a body in the doorway could see of them.
    // Presence already drops the dead and the not-yet-entered; the dead are
    // passed explicitly so nobody is ever standing in the room they died in.
    $presence = xeric_world_who_is_where($t, $now, xeric_dead_handles($db));
    $who   = [];
    $faces = [];
    $minor = false;
    foreach (xeric_world_who_is_at($presence, $key) as $h) {
        $id = xeric_photo_identity($t, $h);
        if ($id === null) continue;
        $minor = $minor || $id['minor'];
        $faces[$h] = $id['face_seed'];
        $who[] = [
            'handle'     => $h,
            'name'       => $id['name'],
            'doing'      => trim(xeric_text($presence[$h]['doing'] ?? '')),
            'at_home'    => !empty($presence[$h]['at_home']),
            'appearance' => $id['appearance'],
            'build'      => $id['build'],
            'wears'      => array_column($id['wears'], 'text'),
        ];
    }

    // The ceiling, and the floor that beats it. Same reasoning as photo.php's
    // AGE RULE: a minor in the room is a minor in the frame.
    $rating = $minor ? xeric_ratings()[0] : xeric_world_rating($t);

    $name  = (string)($p['name'] ?? $key);
    $desc  = trim(xeric_text($p['description'] ?? ''));
    $items = array_column((array)($seeds['items'] ?? []), 'text');

    $brief = [
        'key'      => $key,
        'name'     => $name,
        'kind'     => (string)($p['kind'] ?? ''),
        'now'      => [
            'epoch' => (int)($now['epoch'] ?? 0),
            'hhmm'  => (string)($now['hhmm'] ?? ''),
            'dow'   => (int)($now['dow'] ?? 0),
            'phase' => (string)($now['phase'] ?? ''),
        ],
        'light'    => $light,
        'weather'  => $wx,
        'open'     => $open,
        'dark'     => $isDark,
        'exterior' => ['description' => $desc, 'seed' => (int)($seeds['exterior'] ?? 0)],
        'interior' => (array)($seeds['items'] ?? []),
        'who'      => $who,
        'you'      => ['name' => xeric_text($t['user']['name'] ?? '') ?: 'you',
                       'since' => xeric_player_since($db)],
        'setting'  => ['era'    => trim(xeric_text($t['setting']['era'] ?? '')),
                       'locale' => trim(xeric_text($t['setting']['locale'] ?? ''))],
        'rating'   => $rating,
        'minor'    => $minor,
        'enabled'  => $cfg['enabled'],
        'vantage'  => $cfg['vantage'],
        'seeds'    => ['place' => $seeds, 'faces' => $faces],
    ];

    $brief['prompt']      = xeric_scene_prompt($brief, $name, $desc, $items, $cfg, trim((string)($opts['ask'] ?? '')));
    $brief['fingerprint'] = xeric_scene_fingerprint($brief, $desc, $items, $cfg, trim((string)($opts['ask'] ?? '')));
    return $brief;
}

/**
 * The paragraph, composed in code, in the order photo.php composes: the
 * world's own aesthetic first (an era-true clause, because a phone in an 1873
 * room is a broken room), then the room, its things, its light and sky, then
 * the people, then where the eye is — and the rating LAST, after style and
 * ask, so nothing upstream of it can talk it down or up.
 */
function xeric_scene_prompt(array $b, string $name, string $desc, array $items, array $cfg, string $ask): string
{
    $bits = [];
    if ($b['setting']['locale'] !== '') $bits[] = $b['setting']['locale'];
    if ($b['setting']['era'] !== '') {
        $bits[] = 'period-true to ' . $b['setting']['era'] . ', nothing anachronistic in view';
    }

    $room = 'inside ' . $name;
    if ($desc !== '') $room .= ', ' . rtrim($desc, '.');
    $bits[] = $room;
    if ($items !== []) $bits[] = 'in the room: ' . implode(', ', $items);

    $sky = $b['light'];
    if ($b['weather'] !== '') $sky .= ', ' . rtrim($b['weather'], '.');
    $bits[] = $sky;
    // The state of the room, said only when it is not the ordinary one: an
    // open room needs no announcement, a shut one changes every surface.
    if ($b['dark'])       $bits[] = 'the place has gone dark and stands unkept';
    elseif (!$b['open'])  $bits[] = 'closed at this hour';

    // The people, as a bystander would list them. No pronouns: names and
    // the template's own words carry every person, so nothing here guesses.
    if ($b['who'] === []) {
        $bits[] = 'nobody else is here';
    } else {
        $people = [];
        foreach ($b['who'] as $w) {
            $line = $w['name'];
            if ($w['build'] !== '')      $line .= ', ' . rtrim($w['build'], '.');
            if ($w['appearance'] !== '') $line .= ', ' . rtrim($w['appearance'], '.');
            if ($w['wears'] !== [])      $line .= ', wearing ' . implode(', ', $w['wears']);
            if ($w['doing'] !== '')      $line .= ' — ' . rtrim($w['doing'], '.');
            elseif ($w['at_home'])       $line .= ' — at home';
            $people[] = $line;
        }
        $bits[] = 'present: ' . implode('; ', $people);
    }

    $bits[] = $cfg['vantage'] === 'establishing'
        ? 'a wide establishing view of the whole place, nobody at the centre of the frame'
        : 'seen from where you stand, just inside the door';

    if ($cfg['style'] !== '') $bits[] = rtrim($cfg['style'], '.');
    if ($ask !== '')          $bits[] = rtrim($ask, '.');

    // THE STRUCTURAL CAP, last. Photo.php's floor words for a minor in the
    // room, verbatim; the rating ladder's own language for everyone else.
    $bits[] = $b['minor'] ? XERIC_PHOTO_FLOOR_FRAMING : match ($b['rating']) {
        'sfw', 'pg' => 'a wholesome everyday scene, nothing suggestive',
        'teen'      => 'an everyday scene, nothing explicit',
        default     => 'a scene in keeping with the world\'s adult rating',
    };

    // Each bit is a sentence; strip a trailing stop from each so a template
    // that ends its description with one does not double the full stop.
    return implode('. ', array_map(fn($s) => rtrim($s, '. '), $bits)) . '.';
}

/**
 * What the room LOOKS like, hashed. In: the place key and description, its
 * interior, the light, the weather line, who is present (sorted handles, and
 * what each looks like and wears), open/dark, the vantage and style, the ask,
 * the rating. Out, deliberately: the minute, the epoch, the day of week, and
 * what each person is DOING — the minute because a room at 07:10 and at 07:40
 * in the same morning light is the same room, and a fingerprint that ticked
 * would make every glance a render; the doing because it is a pose, and the
 * week's blocks change who is present (which IS in) far more often than they
 * change one present person's errand. The prompt row keeps the doing words as
 * they were first asked; a viewer that wants the present tense reads the
 * brief, which is fresh every call.
 */
function xeric_scene_fingerprint(array $b, string $desc, array $items, array $cfg, string $ask = ''): string
{
    $people = [];
    foreach ($b['who'] as $w) {
        $people[$w['handle']] = [$w['appearance'], $w['build'], $w['wears']];
    }
    ksort($people, SORT_STRING);
    return sha1(json_encode([
        'key' => $b['key'], 'description' => $desc, 'interior' => $items,
        'light' => $b['light'], 'weather' => $b['weather'],
        'who' => $people, 'open' => $b['open'], 'dark' => $b['dark'],
        'vantage' => $cfg['vantage'], 'style' => $cfg['style'],
        'ask' => $ask, 'rating' => $b['rating'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ---------------------------------------------------------------------------
// The machine
// ---------------------------------------------------------------------------

/**
 * The scene machine, if this install has one. Null is dormant, and dormant is
 * the shipping state and never an error.
 *
 * Read in order: the web layer's resolver (the machines screen, read at CALL
 * time so a worker under a forced session sees that session's choice; false
 * means "explicitly none" and stops here), then XERIC_SCENE_BASE / _KEY /
 * _KIND / _AUTH, then — the fallback — the image machine with kind 'image'. A still
 * of the room from where you stand is a legitimate viewport, and an owner who
 * wired up pictures has wired up this too without being asked twice.
 */
function xeric_scene_endpoint(): ?array
{
    $fn = xeric_scene_source();
    if ($fn !== null) {
        $ep = $fn();
        if (is_array($ep) && trim((string)($ep['base'] ?? '')) !== '') {
            return $ep + ['kind' => 'generic'];
        }
        if (is_array($ep) && isset($ep['stub']) && is_callable($ep['stub'])) return $ep + ['kind' => 'stub'];
        if ($ep === false) return null;
    }
    $base = trim((string)(getenv('XERIC_SCENE_BASE') ?: ''));
    if ($base !== '') {
        $kind = strtolower(trim((string)(getenv('XERIC_SCENE_KIND') ?: 'generic')));
        return ['base' => $base, 'key' => (string)(getenv('XERIC_SCENE_KEY') ?: ''),
                'kind' => in_array($kind, ['image', 'generic'], true) ? $kind : 'generic',
                // Vendors disagree about where the key goes: 'bearer' is
                // Authorization: Bearer, anything else is the header's name.
                'auth' => trim((string)(getenv('XERIC_SCENE_AUTH') ?: 'bearer'))];
    }
    $img = xeric_image_endpoint();
    if ($img === null) return null;
    // The image machine's own dialect (a1111 and friends) rides as image_kind;
    // this file speaks only the common images shape to it.
    return ['image_kind' => (string)($img['kind'] ?? '')] + ['kind' => 'image'] + $img;
}

/** The resolver seam, set once by the web layer, like xeric_image_source. */
function xeric_scene_source($fn = null): ?callable
{
    static $src = null;
    if ($fn !== null) $src = $fn;
    return $src;
}

/**
 * IS the scene machine answering? The same detection xeric_image_up does —
 * a GET on the base, short timeout, never a throw — because a configured but
 * absent machine should read as "no viewport here", not as a broken frame.
 */
function xeric_scene_up(?array $endpoint = null, int $timeout = 3): bool
{
    $endpoint ??= xeric_scene_endpoint();
    if ($endpoint === null) return false;
    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) return true;
    if (trim((string)($endpoint['base'] ?? '')) === '') return false;
    return xeric_image_up($endpoint, $timeout);
}

/** Does rendering here spend money? xeric_image_costly's one bit, for scenes. */
function xeric_scene_costly(?array $endpoint = null): bool
{
    $endpoint ??= xeric_scene_endpoint();
    if ($endpoint === null) return false;
    return xeric_image_costly($endpoint);
}

/**
 * The key, in whichever header this machine wants it. 'bearer' (the default)
 * is Authorization: Bearer; anything else is taken as the header's own name
 * (x-api-key and friends) — and a name that is not a header name is bearer,
 * because a config typo should not become header injection.
 */
function xeric_scene_auth_headers(array $endpoint): array
{
    $key = (string)($endpoint['key'] ?? '');
    if ($key === '') return [];
    $how = trim((string)($endpoint['auth'] ?? 'bearer'));
    if ($how === '' || strtolower($how) === 'bearer' || !preg_match('/^[A-Za-z0-9-]+$/', $how)) {
        return ['Authorization: Bearer ' . $key];
    }
    return [$how . ': ' . $key];
}

/**
 * Render one scene, or ask after one already submitted. THE ADAPTER — the one
 * function that speaks to a scene machine. Throws on nothing usable; the
 * reaper counts that as a try, not a catastrophe.
 *
 * Kinds of ENDPOINT:
 *
 *   'image'   — the common images shape xeric_image_render speaks: POST
 *               {base}/v1/images/generations {prompt, seed, n:1,
 *               response_format:'b64_json'}; the first data[].b64_json is the
 *               still. Seed is the place's INTERIOR seed: the room, not its
 *               street face. Always synchronous; comes back kind 'image'.
 *
 *   'generic' — THE CONTRACT for anyone writing an adapter to a machine that
 *               makes rooms. No vendor is named here and none needs to be.
 *
 *     REQUEST   POST {base}/v1/scenes, Content-Type: application/json, the
 *               key in the configured auth header (xeric_scene_auth_headers):
 *                 {
 *                   "kind_wanted": ["pano","image"],  preference order;
 *                                  media.scene.kinds, else this default
 *                   "prompt":      the composed paragraph, rating clause last,
 *                   "negative":    media.scene.negative, only when set,
 *                   "brief":       the structured brief — observables only,
 *                   "seed":        the place's interior seed (int),
 *                   "fingerprint": sha1, stable across visits that look alike,
 *                   "size":        [4096, 2048], only when a pano is wanted,
 *                   "quality":     "draft" | "full",
 *                   "timeout_s":   how long Xeric will wait on this call
 *                 }
 *
 *     ANSWER    { "kind": "image"|"pano"|"splat"|"glb"|"viewer",
 *                 "mime": "...", and EXACTLY ONE of "b64" | "url",
 *                 "extras": [...] optional, "meta": {...} optional,
 *                 "usage": {...} optional, reaches the meter }
 *               image/pano/splat/glb: a url is fetched AT ONCE with the same
 *               auth header (vendor links expire) and the bytes are stored;
 *               Xeric keeps no link to anybody's bucket. viewer: the url IS
 *               the scene (a hosted explorable room) and is stored as a
 *               string, no bytes. `meta` (seed used, cost, the vendor's id)
 *               is recorded on the row as it came.
 *
 *     LATER     { "status": "pending", "job": "<id>", "retry_after_s": n },
 *               usually as a 202: Xeric does not block. The reaper records
 *               the job and asks again on its next pass with
 *               GET {base}/v1/scenes/{job}, which answers either shape above.
 *
 *   'stub'    — $endpoint['stub']('scene', $brief, $opts) => {bytes, mime,
 *               kind?, url?, meta?} or {pending:true, job}. $opts['job'] is
 *               set when it is being asked after a job. The tests' seam.
 *
 * $opts: kinds, quality, negative (from media.scene; the reaper passes
 * them), timeout (default 300 — rooms are slower than faces), job (poll).
 *
 * @return array{bytes:string,mime:string,usage:array,meta:array,kind:string,
 *               url:?string,extras:array,pending:?string}
 *         pending is the remote job id when the machine said "later", in
 *         which case bytes is '' and nothing else is meaningful.
 */
function xeric_scene_render(array $endpoint, array $brief, array $opts = []): array
{
    $job = trim((string)($opts['job'] ?? ''));

    if (isset($endpoint['stub']) && is_callable($endpoint['stub'])) {
        $out = ($endpoint['stub'])('scene', $brief, $opts);
        if (!is_array($out)) throw new RuntimeException('scene: the stub returned no scene');
        return xeric_scene_answer($out, [], 0, true);
    }

    $base = rtrim((string)($endpoint['base'] ?? ''), '/');
    if ($base === '') throw new RuntimeException('scene: no scene machine is configured');
    $auth    = xeric_scene_auth_headers($endpoint);
    $seed    = (int)($brief['seeds']['place']['interior'] ?? 0);
    $timeout = (int)($opts['timeout'] ?? 300);

    if (strtolower((string)($endpoint['kind'] ?? 'generic')) === 'image') {
        $j = xeric_scene_http('POST', $base . '/v1/images/generations', $auth, [
            'prompt' => (string)($brief['prompt'] ?? ''), 'seed' => $seed,
            'n' => 1, 'response_format' => 'b64_json',
        ], (int)($opts['timeout'] ?? 120));
        $bytes = base64_decode((string)($j['data'][0]['b64_json'] ?? ''), true);
        if ($bytes === false || $bytes === '') throw new RuntimeException('scene: the image machine answered with no image');
        return ['bytes' => $bytes, 'mime' => 'image/png', 'usage' => (array)($j['usage'] ?? []),
                'meta' => [], 'kind' => 'image', 'url' => null, 'extras' => [], 'pending' => null];
    }

    if ($job !== '') {
        $j = xeric_scene_http('GET', $base . '/v1/scenes/' . rawurlencode($job), $auth, null, $timeout);
        return xeric_scene_answer($j + ['job' => $job], $auth, $timeout, false);
    }

    $kinds = array_values(array_map('strval', (array)($opts['kinds'] ?? []))) ?: ['pano', 'image'];
    $body  = [
        'kind_wanted' => $kinds,
        'prompt'      => (string)($brief['prompt'] ?? ''),
        'brief'       => $brief,
        'seed'        => $seed,
        'fingerprint' => (string)($brief['fingerprint'] ?? ''),
        'quality'     => ($opts['quality'] ?? 'full') === 'draft' ? 'draft' : 'full',
        'timeout_s'   => $timeout,
    ];
    if (trim((string)($opts['negative'] ?? '')) !== '') $body['negative'] = trim((string)$opts['negative']);
    if (in_array('pano', $kinds, true)) $body['size'] = [4096, 2048];
    $j = xeric_scene_http('POST', $base . '/v1/scenes', $auth, $body, $timeout);
    return xeric_scene_answer($j, $auth, $timeout, false);
}

/**
 * One answer, read. Shared by the live path and the stub so the two cannot
 * disagree about what a scene IS; $stub allows raw `bytes` (the stub's
 * shortcut) where the wire only ever carries b64 or a url.
 */
function xeric_scene_answer(array $j, array $auth, int $timeout, bool $stub): array
{
    $res = ['bytes' => '', 'mime' => '', 'usage' => (array)($j['usage'] ?? []),
            'meta' => is_array($j['meta'] ?? null) ? $j['meta'] : [],
            'kind' => '', 'url' => null, 'extras' => array_values((array)($j['extras'] ?? [])),
            'pending' => null];

    if (!empty($j['pending']) || strtolower((string)($j['status'] ?? '')) === 'pending') {
        $id = trim((string)($j['job'] ?? ''));
        if ($id === '') throw new RuntimeException('scene: the machine said later but named no job');
        $res['pending'] = $id;
        return $res;
    }

    $kind = strtolower(trim((string)($j['kind'] ?? 'image')));
    if (!in_array($kind, ['image', 'pano', 'splat', 'glb', 'viewer'], true)) {
        throw new RuntimeException('scene: the machine made a kind of scene this engine does not know');
    }
    $res['kind'] = $kind;
    $b64 = is_string($j['b64'] ?? null) && $j['b64'] !== '' ? $j['b64'] : null;
    $url = is_string($j['url'] ?? null) && $j['url'] !== '' ? $j['url'] : null;
    $raw = $stub && isset($j['bytes']) ? (string)$j['bytes'] : null;
    if ((int)($b64 !== null) + (int)($url !== null) + (int)($raw !== null) !== 1) {
        throw new RuntimeException('scene: the machine must answer with exactly one of b64 or url');
    }
    // http(s) only, everywhere: a reply naming file:// or php:// is not a
    // scene, it is a request to read this disk — and a viewer link that is
    // javascript: is not a link.
    if ($url !== null && !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new RuntimeException('scene: the machine answered with a url that is not http');
    }
    $res['mime'] = xeric_scene_mime($j['mime'] ?? xeric_scene_default_mime($kind));

    if ($kind === 'viewer') {
        if ($url === null) throw new RuntimeException('scene: a viewer scene must be a url');
        $res['url'] = $url;
        return $res;
    }
    if ($raw !== null) {
        $res['bytes'] = $raw;
    } elseif ($b64 !== null) {
        $bytes = base64_decode($b64, true);
        if ($bytes === false) throw new RuntimeException('scene: the machine answered with bad base64');
        $res['bytes'] = $bytes;
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'header' => implode("\r\n", $auth),
            'timeout' => $timeout, 'ignore_errors' => false, 'follow_location' => 1,
        ]]);
        $bytes = @file_get_contents($url, false, $ctx);
        if (!is_string($bytes)) throw new RuntimeException('scene: the scene could not be fetched from its url');
        $res['bytes'] = $bytes;
    }
    if ($res['bytes'] === '') throw new RuntimeException('scene: the machine answered with an empty scene');
    return $res;
}

/** One JSON round trip; throws on anything that is not a JSON object. */
function xeric_scene_http(string $method, string $url, array $auth, ?array $body, int $timeout): array
{
    $headers = array_merge(['Accept: application/json'], $body !== null ? ['Content-Type: application/json'] : [], $auth);
    $http = ['method' => $method, 'header' => implode("\r\n", $headers),
             'timeout' => $timeout, 'ignore_errors' => true];
    if ($body !== null) $http['content'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $raw = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    $j   = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) throw new RuntimeException('scene: the machine did not answer in JSON');
    return $j;
}

/** A media type as a machine said it, or octet-stream when it said nonsense. */
function xeric_scene_mime($m): string
{
    $m = trim(explode(';', strtolower(trim((string)$m)))[0]);
    return preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $m) ? $m : 'application/octet-stream';
}

function xeric_scene_default_mime(string $kind): string
{
    return match ($kind) {
        'image', 'pano' => 'image/png',
        'glb'           => 'model/gltf-binary',
        'viewer'        => 'text/html',
        default         => 'application/octet-stream',
    };
}

/** The file extension for what a scene is. Unknown bytes are kept as .bin. */
function xeric_scene_ext(string $mime, string $kind = ''): string
{
    return match ($mime) {
        'image/png' => '.png', 'image/jpeg' => '.jpg', 'image/webp' => '.webp',
        'video/mp4' => '.mp4', 'video/webm' => '.webm',
        'model/gltf-binary' => '.glb', 'model/gltf+json' => '.gltf',
        default => match ($kind) { 'splat' => '.splat', 'glb' => '.glb', default => '.bin' },
    };
}

// ---------------------------------------------------------------------------
// The queue, and the reaper that drains it
// ---------------------------------------------------------------------------

/** The jobs, oldest first; $status narrows, null is everything. */
function xeric_scene_jobs(PDO $db, ?string $status = null): array
{
    $st = $status === null
        ? $db->query('SELECT * FROM scene_jobs ORDER BY id')
        : $db->prepare('SELECT * FROM scene_jobs WHERE status = ? ORDER BY id');
    if ($status !== null) $st->execute([$status]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows;
}

/** The one job for this room looking exactly like this, done or not. */
function xeric_scene_of(PDO $db, string $key, string $fingerprint): ?array
{
    $st = $db->prepare('SELECT * FROM scene_jobs WHERE key = ? AND fingerprint = ? LIMIT 1');
    $st->execute([$key, $fingerprint]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows ? $rows[0] : null;
}

/**
 * The most recent FINISHED view of a place, whatever it looked like then. A
 * stale view is better than a blank one while the fresh one renders — and the
 * caller says it is stale (its fingerprint is right there to compare). `kind`
 * rides on every row so the viewer can be chosen without sniffing bytes.
 */
function xeric_scene_latest(PDO $db, string $key): ?array
{
    $st = $db->prepare("SELECT * FROM scene_jobs WHERE key = ? AND status = 'done'
                        ORDER BY done_at DESC, id DESC LIMIT 1");
    $st->execute([$key]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    return $rows ? $rows[0] : null;
}

/**
 * Ask for this room to be rendered. IDEMPOTENT: one row per (key,
 * fingerprint), so a client may ask on every glance and the table keeps one
 * row; the id comes back either way. 0 when the brief cannot be a job — a
 * world that turned scenes off (media.scene.enabled false), or a brief with
 * no key or fingerprint to file it under.
 */
function xeric_scene_enqueue(PDO $db, array $brief): int
{
    if (array_key_exists('enabled', $brief) && !$brief['enabled']) return 0;
    $key = (string)($brief['key'] ?? '');
    $fp  = (string)($brief['fingerprint'] ?? '');
    if ($key === '' || $fp === '') return 0;

    $ins = $db->prepare('INSERT OR IGNORE INTO scene_jobs (key, fingerprint, prompt, created_at)
                         VALUES (?, ?, ?, ?)');
    $ins->execute([$key, $fp, (string)($brief['prompt'] ?? ''), xeric_state_time()]);
    if ($ins->rowCount() === 1) return (int)$db->lastInsertId();
    $row = xeric_scene_of($db, $key, $fp);
    return $row !== null ? (int)$row['id'] : 0;
}

/** The scene spend's sink — xeric_photo_meter's discipline, for rooms. */
function xeric_scene_meter($sink = null): ?callable
{
    static $fn = null;
    if ($sink !== null) $fn = $sink;
    return $fn;
}

/** How long a reaper's claim may sit before it is presumed dead, seconds. */
const XERIC_SCENE_CLAIM_TTL  = 1200;
/** How long a machine's "later" is believed before it counts as a failed try. */
const XERIC_SCENE_REMOTE_TTL = 1800;

/**
 * Drain scene jobs. Built exactly as xeric_photo_reap is — stale claims
 * reset first, the claim is the UPDATE, three tries and failed, every render
 * metered — minus the consent gate (see the header: the machine was the
 * consent), plus one thing photos never needed: a machine that says LATER.
 *
 * TWO KINDS OF WORK, in this order:
 *
 *   ASKING AFTER. A row that is 'working' with a remote_job is a scene the
 *   machine is still making. Every pass asks after every such row once (a GET
 *   is cheap and does not count against $limit) and never blocks on one.
 *   done_at holds the submit time while it waits and is not refreshed by
 *   asking, so the thirty-minute window is measured from the ask, not from
 *   the last poll; past it, the wait is a failed try and the row goes back
 *   to pending to be asked for afresh. Two reapers may ask after the same job
 *   at once; only the one whose finishing UPDATE lands writes the row or
 *   reaches the meter, and both would have written the same file name.
 *
 *   ASKING FOR. Up to $limit pending rows. The brief is RE-COMPOSED at render
 *   time from the row's key and the world's current clock — the seeds and the
 *   structured remainder go to the machine fresh. But the row is what was
 *   asked for: if the room has changed since (somebody left, the light
 *   turned), the STORED prompt and fingerprint are rendered anyway and a note
 *   says so, because a job keyed by one look that rendered another would file
 *   a picture under the wrong name. The changed room is another fingerprint,
 *   and the next glance asks for it.
 *
 * Files land under $scenesDir as scene-<key>-<fp12><ext>; a 'viewer' scene
 * has no file, only its url.
 *
 * @return array{done:int,failed:int,notes:string[]}
 */
function xeric_scene_reap(array $t, PDO $db, string $scenesDir, ?array $endpoint = null, int $limit = 1): array
{
    $out = ['done' => 0, 'failed' => 0, 'notes' => []];

    $cfg = xeric_scene_config($t);
    if (!$cfg['enabled']) {
        $out['notes'][] = 'scenes are turned off for this world';
        return $out;
    }

    // Stale claims first, so this pass can finish what a dead reaper dropped.
    // A room is slower than a face, so the window is twenty minutes, past the
    // adapter's five-minute default with room over. A row waiting on the
    // machine is NOT a dead claim and is left alone here.
    $tnow = xeric_state_time();
    $db->prepare("UPDATE scene_jobs SET status = 'pending', done_at = NULL
                  WHERE status = 'working' AND remote_job IS NULL AND done_at < ?")
       ->execute([$tnow - XERIC_SCENE_CLAIM_TTL]);
    // ...until it has waited too long, and then the wait was a try.
    $expired = $db->prepare("SELECT * FROM scene_jobs
                             WHERE status = 'working' AND remote_job IS NOT NULL AND done_at < ?");
    $expired->execute([$tnow - XERIC_SCENE_REMOTE_TTL]);
    foreach ($expired->fetchAll() as $row) {
        if (xeric_scene_fail($db, $row)) $out['failed']++;
        $out['notes'][] = 'scene of ' . $row['key'] . ': the machine never finished it';
    }
    $expired->closeCursor();

    $waiting = $db->query("SELECT * FROM scene_jobs WHERE status = 'working' AND remote_job IS NOT NULL ORDER BY id")
                  ->fetchAll();
    $pending = xeric_scene_jobs($db, 'pending');
    if ($pending === [] && $waiting === []) return $out;

    $endpoint ??= xeric_scene_endpoint();
    if (!xeric_scene_up($endpoint)) { $out['notes'][] = 'no scene machine is answering'; return $out; }

    if (!is_dir($scenesDir) && !@mkdir($scenesDir, 0775, true) && !is_dir($scenesDir)) {
        $out['notes'][] = 'the scenes directory cannot be created';
        return $out;
    }
    $opts = ['kinds' => $cfg['kinds'], 'quality' => $cfg['quality'], 'negative' => $cfg['negative']];

    foreach ($waiting as $job) {
        try {
            $r = xeric_scene_render($endpoint, ['key' => (string)$job['key'],
                                                'fingerprint' => (string)$job['fingerprint']],
                                    $opts + ['job' => (string)$job['remote_job']]);
            if ($r['pending'] !== null) {
                // Still making it. A new job id (some machines re-issue) is
                // kept; the clock on the wait is not reset.
                $db->prepare("UPDATE scene_jobs SET remote_job = ? WHERE id = ? AND status = 'working'")
                   ->execute([$r['pending'], (int)$job['id']]);
                continue;
            }
            if (xeric_scene_finish($db, $job, $r, $scenesDir, $endpoint)) $out['done']++;
        } catch (Throwable $e) {
            if (xeric_scene_fail($db, $job)) $out['failed']++;
            $out['notes'][] = 'scene of ' . $job['key'] . ': ' . $e->getMessage();
        }
    }

    $now   = xeric_clock_now($db, $t);
    $claim = $db->prepare("UPDATE scene_jobs SET status = 'working', done_at = ?
                           WHERE id = ? AND status = 'pending'");
    foreach (array_slice($pending, 0, max(1, $limit)) as $job) {
        $key = (string)$job['key'];
        $fp  = (string)$job['fingerprint'];
        // THE CLAIM IS THE UPDATE: exactly one reaper gets rowCount 1.
        $claim->execute([xeric_state_time(), (int)$job['id']]);
        if ($claim->rowCount() !== 1) continue;
        try {
            $brief = xeric_scene_brief($t, $db, $key, $now);
            if ($brief === null) throw new RuntimeException('the place is no longer in this world');
            if ($brief['fingerprint'] !== $fp) {
                $out['notes'][] = 'scene of ' . $key . ': the room has changed since it was asked for; '
                                . 'rendering it as asked';
                $brief['prompt']      = (string)$job['prompt'];
                $brief['fingerprint'] = $fp;
            }
            $r = xeric_scene_render($endpoint, $brief, $opts);
            if ($r['pending'] !== null) {
                // LATER: the claim stays, and done_at (the claim stamp) is now
                // the submit time the remote window is measured from.
                $db->prepare("UPDATE scene_jobs SET remote_job = ? WHERE id = ? AND status = 'working'")
                   ->execute([$r['pending'], (int)$job['id']]);
                $out['notes'][] = 'scene of ' . $key . ': the machine is making it';
                continue;
            }
            if (xeric_scene_finish($db, $job, $r, $scenesDir, $endpoint)) $out['done']++;
        } catch (Throwable $e) {
            if (xeric_scene_fail($db, $job)) $out['failed']++;
            $out['notes'][] = 'scene of ' . $key . ': ' . $e->getMessage();
        }
    }
    return $out;
}

/**
 * A rendered answer onto its row, and the meter told. False when another
 * reaper finished this row first — then nothing is written to the row and
 * nothing is metered (the file, if any, is the same name either way).
 */
function xeric_scene_finish(PDO $db, array $job, array $r, string $scenesDir, ?array $endpoint): bool
{
    $file = '';
    if ($r['kind'] !== 'viewer') {
        $file = 'scene-' . preg_replace('/[^a-z0-9_-]+/i', '_', (string)$job['key']) . '-'
              . substr((string)$job['fingerprint'], 0, 12) . xeric_scene_ext($r['mime'], $r['kind']);
        if (@file_put_contents($scenesDir . '/' . $file, $r['bytes']) === false) {
            throw new RuntimeException('the scene could not be written to disk');
        }
    }
    $meta = $r['meta'] + ($r['extras'] !== [] ? ['extras' => $r['extras']] : []);
    $st = $db->prepare("UPDATE scene_jobs SET status = 'done', kind = ?, file = ?, url = ?, mime = ?, meta = ?,
                        remote_job = NULL, tries = tries + 1, done_at = ? WHERE id = ? AND status = 'working'");
    $st->execute([$r['kind'], $file, $r['url'], $r['mime'],
                  json_encode((object)$meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                  xeric_state_time(), (int)$job['id']]);
    if ($st->rowCount() !== 1) return false;
    if (($fn = xeric_scene_meter()) !== null) {
        $fn(['scenes' => 1] + $r['usage'], (string)($endpoint['base'] ?? 'stub'));
    }
    return true;
}

/**
 * A try burned: back to pending, or failed at three. The claim stamp and any
 * remote job come off with it — a pending row carries neither. Reads tries
 * from the database rather than the row in hand, because a row asked after
 * across several passes is older than the copy this pass read. True when
 * this try was the third.
 */
function xeric_scene_fail(PDO $db, array $job): bool
{
    $q = $db->prepare('SELECT tries FROM scene_jobs WHERE id = ?');
    $q->execute([(int)$job['id']]);
    $tries = (int)($q->fetchColumn() ?: 0) + 1;
    $q->closeCursor();
    $st = $db->prepare('UPDATE scene_jobs SET status = ?, tries = ?, done_at = NULL, remote_job = NULL
                        WHERE id = ? AND status = ?');
    $st->execute([$tries >= 3 ? 'failed' : 'pending', $tries, (int)$job['id'], 'working']);
    return $st->rowCount() === 1 && $tries >= 3;
}
