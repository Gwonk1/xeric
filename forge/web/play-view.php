<?php
/**
 * play-view.php — the readers: fate, where, the voice, the look, and the room.
 *
 * PART OF play-lib.php, moved out of it whole: every function here kept its
 * name, signature and body. play-lib.php require_once's this file, so every
 * page that requires play-lib.php has it; requiring this file alone pulls
 * play-lib.php in as well (the two include each other once). The map of which
 * part lives where is at the top of play-lib.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

/**
 * What death means here, who it has taken, and what can be done about it.
 *
 * The mode is stated in ONE plain sentence at the top, before any control,
 * because it is the only thing on this panel a person can get wrong in a way
 * that cannot be undone. A world that quietly offered a "bring them back" button
 * that turned out not to exist would be worse than one that never offered it.
 *
 * The picker lists the living and nothing else, so the one destructive control
 * here cannot be aimed at somebody who is already gone. Everything about
 * permanence is decided in death.php; this renders what it says.
 */
function xeric_play_fate_html(array $t, PDO $db, array $cast): string
{
    $mode   = xeric_death_mode($t, $db);
    $perm   = $mode === XERIC_DEATH_PERMANENT;
    $locked = xeric_death_locked($db);
    $dead   = array_values(array_filter($cast, fn(array $c): bool => !empty($c['dead'])));
    $living = array_values(array_filter($cast, fn(array $c): bool => empty($c['dead'])));

    $out = '<p class="frule">'
        . ($perm
            ? '<b>Death is permanent here.</b> Nothing in this app will bring anybody back.'
            : '<b>Death can be undone here.</b> Anybody lost can be brought back, and the xeric keeps '
              . 'every memory of having lost them.')
        . '</p>';

    // Said only while it is still true. After the first death the sentence would
    // be an invitation to go and change something that no longer changes.
    if (!$locked) {
        $out .= '<p class="note">No one has died here yet, so this is still a setting, '
              . '<code>deaths.mode</code> in the template. The first death freezes it.</p>';
    }

    if ($dead !== []) {
        $out .= '<ul class="flist">';
        foreach ($dead as $c) {
            $out .= '<li><span><span class="fn">' . h($c['name']) . '</span>'
                 . ((string)$c['how'] !== '' ? '<span class="fh">' . h($c['how']) . '</span>' : '')
                 . '</span>'
                 . ($perm ? '<span class="fperm">gone</span>'
                          : '<button type="button" class="btn ghost small fback" data-h="' . h($c['handle'])
                            . '">bring back</button>')
                 . '</li>';
        }
        $out .= '</ul>';
    }

    if ($living !== []) {
        $out .= '<div class="fkill"><select id="fwho">';
        foreach ($living as $c) {
            $out .= '<option value="' . h($c['handle']) . '">' . h($c['name']) . '</option>';
        }
        $out .= '</select><input id="fhow" type="text" maxlength="200" autocomplete="off" '
             . 'placeholder="what the town would say"><button type="button" class="btn ghost small" id="fkill">'
             . 'they die</button></div>';
    }

    $out .= '<div class="fworld">';
    if ($living !== []) {
        $out .= '<button type="button" class="btn ghost small warnbtn" id="fend">end this xeric</button>';
    }
    if ($dead !== [] && !$perm) {
        $out .= '<button type="button" class="btn ghost small" id="frestore">bring the xeric back</button>';
    }
    $out .= '</div>';

    if ($living === [] && $dead !== []) {
        $out .= '<p class="note bad">There is nobody left in ' . h((string)($t['meta']['name'] ?? 'this xeric'))
              . '.' . ($perm ? ' And there will not be.' : '') . '</p>';
    }
    return $out;
}

/**
 * Where you are standing, and what it costs to be somewhere else.
 *
 * The first surface built on xeric_travel_map(), and deliberately the dumbest
 * one that works: a list of rooms with a price on each. A drawn map, a room
 * view, a headset — all of those are this same object rendered differently, and
 * the reason this one is a list of buttons is that a list of buttons proves the
 * endpoint is enough before anybody spends a weekend on pixels.
 *
 * NEAREST FIRST, not template order. The question a person is holding when they
 * look at this is "can I get there before she leaves", and sorting by the number
 * that answers it is the whole difference between a map and a directory.
 *
 * Every destination says who is standing in it RIGHT NOW, which is the honest
 * version of the feature and also the one that makes it fun: you can see that
 * Dot is at the diner, and you can see that it is a six-minute walk, and those
 * two facts together are a decision. Hiding the second one would be a menu.
 */
function xeric_play_where_html(array $map, string $slug = ''): string
{
    $here  = $map['you']['where'] ?? null;
    $spots = $map['places'];
    usort($spots, fn(array $a, array $b): int => ($a['minutes'] <=> $b['minutes']) ?: strcmp($a['name'], $b['name']));

    $who = function (array $p): string {
        if ($p['who'] === []) return '<span class="wq">nobody there</span>';
        return '<span class="ww">' . h(xeric_join_list(array_column($p['who'], 'name'))) . '</span>';
    };

    // Standing somewhere is a statement; being on your own time is the same
    // sentence the engine gives a character who is off shift, on purpose.
    $out = '<p class="wnow">';
    if ($here !== null) {
        $at = null;
        foreach ($map['places'] as $p) if ($p['key'] === $here) $at = $p;
        $out .= '<b>' . h((string)($at['name'] ?? $here)) . '</b>'
             . '<span class="' . ($at && $at['open'] ? 'wo' : 'wc') . '">'
             . ($at && $at['open'] ? 'open' : (!empty($at['dark']) ? 'dark' : 'shut')) . '</span>'
             . ($at ? $who($at) : '');
    } else {
        $out .= '<b>Nowhere in particular</b><span class="wq">your own time</span>';
    }
    $out .= '</p>';

    if (!(bool)$map['mapped']) {
        // Said plainly rather than hidden: a world forged before places had
        // coordinates still works, every trip just costs the same. Telling
        // somebody that is better than letting them wonder why the mill and the
        // shop next door are the same walk.
        $out .= '<p class="note">This xeric was made before it had a map, so everywhere in it is the '
              . 'same distance from everywhere else.'
              . ($slug !== '' ? ' Rerolling its places in the review gives it one.' : '') . '</p>';
    }

    $out .= '<ul class="wgo">';
    foreach ($spots as $p) {
        if ($p['here']) continue;
        $out .= '<li><button type="button" class="goto" data-to="' . h($p['key']) . '">'
             . '<span class="wm">' . (int)$p['minutes'] . ' min</span>'
             . '<span><span class="wn">' . h($p['name']) . '</span>'
             . '<span class="wd">'
             . '<span class="' . ($p['open'] ? 'wo' : 'wc') . '">'
             . ($p['open'] ? 'open' : (!empty($p['dark']) ? 'dark' : 'shut')) . '</span>'
             . $who($p) . '</span></span>'
             . '<span class="pgo">›</span></button></li>';
    }
    if ($here !== null) {
        $out .= '<li><button type="button" class="goto ghost" data-to="">'
             . '<span class="wm">' . (int)($map['you']['leave']['minutes'] ?? 0) . ' min</span>'
             . '<span><span class="wn">Leave</span>'
             . '<span class="wd"><span class="wq">go be on your own time</span></span></span>'
             . '<span class="pgo">›</span></button></li>';
    }
    return $out . '</ul>';
}

// ---------------------------------------------------------------------------
// What you see: the viewport's web half (engine/scene.php is the other)
// ---------------------------------------------------------------------------
//
// THE ROOM IS DESCRIBED BEFORE IT IS DRAWN, and described by the engine. Every
// state of #look below is a reading of xeric_scene_brief() and the scene_jobs
// table and nothing else: no client works out who is in the room or what the
// light is doing, for exactly the reason where.php gives about position — the
// day somebody builds a headset on this, the headset asks the same question
// and gets the same answer.
//
// ONE READER, THREE CALLERS. scene.php's GET answers in JSON, play.php's
// sidebar renders HTML, and the bytes GET decides what may be served; all of
// them go through xeric_play_look(), so the picture on the page, the brief a
// client was handed, and the file the server is willing to send cannot
// disagree about which row is the room.

// ---------------------------------------------------------------------------
// The cast, out loud: the voice machine's web half (engine/voice.php)
// ---------------------------------------------------------------------------

/**
 * The machine's voice ids, harvested once on connect (model.php). Ids only —
 * what the deal needs, and nothing a session file should carry beyond it. A
 * machine that cannot say is [] and still speaks; the engine deals the six
 * every compatible server knows.
 *
 * @return string[]
 */
function xeric_play_voice_ids(array $ep): array
{
    try {
        $ids = array_column(xeric_voice_list($ep), 'id');
    } catch (Throwable $e) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('strval', $ids), fn($i) => $i !== '')));
}

/**
 * Is a voice machine set for this visitor? CONFIGURED, NOT PROBED — the rule
 * the look button keeps: this renders on every page load, and asking a
 * machine whether it is there is the speaker's job when it is pressed
 * (speak.php), not the page's on the way in.
 */
function xeric_play_voice_on(): bool
{
    try {
        return xeric_voice_endpoint() !== null;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * One message as speak.php needs it: a CHARACTER's line in THIS world's
 * database. The handle is the row's, never the request's — a query string
 * that could name the speaker could make anybody say anything. Null for an
 * unknown id (which is also every id from another world: a world's messages
 * live in its own db), for the player's own lines, and for the narrator.
 *
 * @return ?array{id:int,handle:string,content:string}
 */
function xeric_play_spoken(PDO $db, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $db->prepare("SELECT id, handle, content FROM messages WHERE id = ? AND role = 'character'
                        AND handle IS NOT NULL AND handle != '' LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return $row ? ['id' => (int)$row['id'], 'handle' => (string)$row['handle'], 'content' => (string)$row['content']] : null;
}

/**
 * The clip for a message, as speak.php serves it — found, or rendered now.
 *
 * ONLY THE ENGINE COMPOSES. Which voice, which words, which fingerprint are
 * xeric_voice_clip's to decide, and nothing here repeats them. What this adds
 * is the split speak.php needs between the half that is free and the half
 * that spends:
 *
 *   A HIT is the clip this message was last voiced with (xeric_voice_of),
 *   still on disk. It is served to anybody who can read the thread — it cost
 *   nothing, and it costs nothing again.
 *
 *   A MISS goes through $beforeRender first, and only then to the engine,
 *   which may render. speak.php's hook is the door and the budget: the owner
 *   (or a visitor in their own copy) and the rate limit. A hook that refuses
 *   answers the request itself and never comes back, so a guest in the
 *   owner's real world hears what has been said aloud already and cannot make
 *   the owner's key say anything new.
 *
 * The answer is the bytes, sniffed — scene.php's rule for sound: the stored
 * type must be on the audio allowlist and the first bytes must say the same.
 * Null is a 404 (nothing to say, nobody to say it, no machine, bytes that are
 * not what they claim). A machine that refused throws, in its own words.
 *
 * @return ?array{bytes:string,mime:string,cached:bool}
 */
function xeric_play_voice_clip(array $t, PDO $db, string $clipsDir, array $msg, ?array $ep, ?array $voices,
                               ?callable $beforeRender = null): ?array
{
    if ($ep === null) return null;
    $row = xeric_voice_of($db, (int)$msg['id']);
    if ($row !== null && is_file($clipsDir . '/' . basename((string)$row['file']))) {
        return xeric_play_voice_bytes($clipsDir . '/' . basename((string)$row['file']), (string)$row['mime'], true);
    }
    if ($beforeRender !== null) $beforeRender();
    $clip = xeric_voice_clip($t, $db, $clipsDir, (string)$msg['handle'], (int)$msg['id'],
                             (string)$msg['content'], $ep, $voices);
    if ($clip === null) return null;
    return xeric_play_voice_bytes($clipsDir . '/' . basename((string)$clip['file']), (string)$clip['mime'],
                                  (bool)$clip['cached']);
}

/**
 * A clip's bytes, if they are what the row says. Opus is an Ogg stream on
 * disk whatever a machine calls it, so it is sniffed and served as one —
 * a serving rule, not a composing one; the rest of the allowlist is the five
 * containers scene.php will play.
 *
 * @return ?array{bytes:string,mime:string,cached:bool}
 */
function xeric_play_voice_bytes(string $path, string $said, bool $cached): ?array
{
    if (!is_file($path)) return null;
    $bytes = (string)file_get_contents($path);
    $said  = strtolower($said);
    $mime  = xeric_scene_audio_mime($said === 'audio/opus' ? 'audio/ogg' : $said);
    if ($mime === '' || xeric_play_audio_sniff(substr($bytes, 0, 16)) !== $mime) return null;
    return ['bytes' => $bytes, 'mime' => $mime, 'cached' => $cached];
}

/**
 * Where this database keeps its rooms: beside the db, under scenes/. Read off
 * the open connection rather than passed in, because the db a page opened IS
 * the copy whose rooms these are — a fork's db lives in the visitor's session
 * folder, and its scenes dir is there too, never beside the owner's file.
 */
function xeric_play_scenes_dir(PDO $db): string
{
    $file = '';
    try {
        foreach ($db->query('PRAGMA database_list')->fetchAll() as $r) {
            if ((string)($r['name'] ?? '') === 'main') $file = (string)($r['file'] ?? '');
        }
    } catch (Throwable $e) {
        $file = '';
    }
    // An in-memory db (a test) has no folder; '' makes every file check fail
    // closed rather than resolving against the working directory.
    return $file === '' ? '' : dirname($file) . '/scenes';
}

/**
 * Does a done row have something to show? A viewer is a link and needs no
 * file; everything else needs its bytes on THIS disk, beside THIS db. A row
 * whose file is missing (a fork's inherited row, a scenes dir somebody
 * deleted) is not a view, it is a broken image waiting to happen.
 */
function xeric_play_look_has(array $row, string $scenesDir): bool
{
    if ((string)($row['status'] ?? '') !== 'done') return false;
    if ((string)($row['kind'] ?? '') === 'viewer') {
        $u = (string)($row['url'] ?? '');
        return in_array(strtolower((string)parse_url($u, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
    $f = basename((string)($row['file'] ?? ''));
    if ($f === '' || $scenesDir === '' || !is_file($scenesDir . '/' . $f)) return false;
    // A still or a panorama must BE one before an <img> is pointed at it —
    // the bytes GET would refuse it anyway, and a refused picture is a broken
    // icon on the sidebar. getimagesize reads the header, not the file, so
    // this costs a few bytes per repaint rather than a 4096-wide panorama.
    if (in_array((string)($row['kind'] ?? ''), ['image', 'pano'], true)) {
        $mime = (string)(@getimagesize($scenesDir . '/' . $f)['mime'] ?? '');
        return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true);
    }
    // A sound is the same rule in another medium: twelve bytes of header must
    // say what the row says, or there is no <audio> to point at it.
    if ((string)($row['kind'] ?? '') === 'audio') {
        $head = (string)@file_get_contents($scenesDir . '/' . $f, false, null, 0, 16);
        $want = xeric_scene_audio_mime((string)($row['mime'] ?? ''));
        return $want !== '' && xeric_play_audio_sniff($head) === $want;
    }
    return true;
}

/**
 * What a sound's first bytes say it is, or ''. MAGIC, NOT THE MACHINE'S WORD:
 * the row's mime is what a third party claimed, and a stored-XSS delivery
 * service is exactly a server that repeats a claim as a Content-Type. Five
 * containers and no sixth — the five the engine keeps (XERIC_SCENE_AUDIO_MIMES).
 * An MP3 is an ID3 tag or a bare frame sync (eleven set bits: 0xFFFB and its
 * MPEG-2 and unprotected cousins).
 */
function xeric_play_audio_sniff(string $head): string
{
    if (str_starts_with($head, 'ID3')) return 'audio/mpeg';
    if (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) return 'audio/mpeg';
    if (str_starts_with($head, 'OggS')) return 'audio/ogg';
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') return 'audio/wav';
    if (str_starts_with($head, "\x1A\x45\xDF\xA3")) return 'audio/webm';
    if (substr($head, 4, 4) === 'ftyp') return 'audio/mp4';
    return '';
}

/**
 * The viewport, read once: the brief for where you stand, the newest view of
 * that room that can actually be shown, whether it is out of date, and whether
 * the current look is already being made.
 *
 * @return array{brief:?array,row:?array,stale:bool,pending:bool,failed:bool,scenes_dir:string,
 *               day:array<string,array>,sound:?array,sound_row:?array}
 */
function xeric_play_look(array $t, PDO $db, ?array $now = null): array
{
    $out = ['brief' => null, 'row' => null, 'stale' => false, 'pending' => false,
            'failed' => false, 'scenes_dir' => xeric_play_scenes_dir($db),
            'day' => [], 'sound' => null, 'sound_row' => null];
    $brief = xeric_scene_brief($t, $db, null, $now);
    if ($brief === null) return $out;
    $out['brief'] = $brief;

    $cur = xeric_scene_of($db, (string)$brief['key'], (string)$brief['fingerprint']);
    if ($cur !== null) {
        $out['pending'] = in_array((string)$cur['status'], ['pending', 'working'], true);
        $out['failed']  = (string)$cur['status'] === 'failed';
    }
    // The CURRENT look first, when it is done and showable; otherwise the
    // newest finished view of the room that can still be SHOWN, whatever it
    // looked like then — a stale room is better than a blank one, provided it
    // says so. Walked back, not just the newest: a newest row whose file has
    // gone, or that is a splat the sidebar has no frame for, must not hide a
    // perfectly good still from the hour before. Five, like
    // xeric_scene_reference's walk, because a room whose last five views are
    // all unshowable is a room to describe in words.
    $row = ($cur !== null && xeric_play_look_has($cur, $out['scenes_dir'])) ? $cur
         : xeric_play_look_back($db, (string)$brief['key'], $out['scenes_dir']);
    if ($row !== null && xeric_play_look_has($row, $out['scenes_dir'])) {
        $out['row']   = $row;
        $out['stale'] = (string)$row['fingerprint'] !== (string)$brief['fingerprint'];
    }

    // THE ROOM THROUGH ITS DAY: the newest view per light that can still be
    // shown as a picture — a thumbnail is bytes, so a viewer link or a glb
    // sits the strip out. Two or more, or it is not a strip, it is the view.
    foreach (xeric_scene_day($db, (string)$brief['key']) as $light => $d) {
        if (in_array((string)$d['kind'], ['image', 'pano'], true) && xeric_play_look_has($d, $out['scenes_dir'])) {
            $out['day'][$light] = $d;
        }
    }
    if (count($out['day']) < 2) $out['day'] = [];

    // WHAT YOU HEAR, beside it: the line always (it is the sound brief's own
    // fixed vocabulary, safe on any surface), the recording only when one for
    // THIS sound is done and on this disk — never an older one: a stale
    // picture says so with a pill, a stale sound would just lie.
    $sb = xeric_sound_brief($t, $db, (string)$brief['key'], $now);
    if ($sb !== null && !empty($sb['enabled'])) {
        $out['sound'] = $sb;
        $srow = xeric_scene_of($db, (string)$sb['key'], (string)$sb['fingerprint'], 'sound');
        if ($srow !== null && (string)$srow['kind'] === 'audio' && xeric_play_look_has($srow, $out['scenes_dir'])) {
            $out['sound_row'] = $srow;
        }
    }
    return $out;
}

/**
 * The newest done look of a place that the sidebar can SHOW, from the last
 * five done looks: a still, a panorama or a viewer link first — something the
 * room can be seen in, inline or one tap away — and only when none of the
 * five is one of those, a splat or glb to download. A missing file, or a
 * picture that is not one, is never the answer.
 */
function xeric_play_look_back(PDO $db, string $key, string $scenesDir, int $tries = 5): ?array
{
    $st = $db->prepare("SELECT * FROM scene_jobs WHERE key = ? AND channel = 'look' AND status = 'done'
                        ORDER BY done_at DESC, id DESC LIMIT ?");
    $st->execute([$key, max(1, $tries)]);
    $rows = $st->fetchAll();
    $st->closeCursor();
    $download = null;
    foreach ($rows as $r) {
        if (!xeric_play_look_has($r, $scenesDir)) continue;
        if (in_array((string)$r['kind'], ['image', 'pano', 'viewer'], true)) return $r;
        $download ??= $r;
    }
    return $download;
}

/**
 * The JSON scene.php answers a GET with. The brief whole (it is observables
 * only, the engine's own promise, and nothing in it is not already on the map
 * or in a portrait), the row trimmed to what a viewer needs, and the machine
 * asked — the one network call in here, which is why the sidebar does not
 * make it and this does.
 */
function xeric_play_look_view(array $t, PDO $db, string $slug, ?array $now = null, bool $probe = true): array
{
    $l  = xeric_play_look($t, $db, $now);
    $ep = xeric_scene_endpoint();
    $scene = null;
    if ($l['row'] !== null) {
        $r    = $l['row'];
        $meta = json_decode((string)($r['meta'] ?? '{}'), true);
        $scene = [
            'id'          => (int)$r['id'],
            'kind'        => (string)$r['kind'],
            'fingerprint' => (string)$r['fingerprint'],
            'mime'        => (string)$r['mime'],
            'url'         => (string)$r['kind'] === 'viewer' ? (string)$r['url'] : null,
            'src'         => (string)$r['kind'] === 'viewer' ? null
                             : xeric_play_look_src($slug, (string)$r['key'], (string)$r['fingerprint']),
            'meta'        => (object)(is_array($meta) ? $meta : []),
            'stale'       => $l['stale'],
        ];
    }
    $day = [];
    foreach ($l['day'] as $light => $d) {
        $day[] = ['light' => (string)$light, 'kind' => (string)$d['kind'],
                  'src' => xeric_play_look_src($slug, (string)$d['key'], (string)$d['fingerprint']),
                  'current' => $l['row'] !== null && (int)$d['id'] === (int)$l['row']['id']];
    }
    $sound = $l['sound'] === null ? null : [
        'line'        => (string)$l['sound']['line'],
        'fingerprint' => (string)$l['sound']['fingerprint'],
        'src'         => $l['sound_row'] === null ? null
                         : xeric_play_look_src($slug, (string)$l['sound_row']['key'], (string)$l['sound_row']['fingerprint']),
        'mime'        => $l['sound_row'] === null ? null : (string)$l['sound_row']['mime'],
    ];
    return [
        'ok'      => true,
        'brief'   => $l['brief'],
        'scene'   => $scene,
        'day'     => $day,
        'sound'   => $sound,
        'pending' => $l['pending'],
        'machine' => [
            'up'     => $probe && $ep !== null && xeric_scene_up($ep, 3),
            'costly' => $ep !== null && xeric_scene_costly($ep),
            'kind'   => $ep === null ? null : (string)($ep['kind'] ?? ''),
        ],
    ];
}

/** Where a row's bytes are asked for. Built in one place so the page and the JSON agree. */
function xeric_play_look_src(string $slug, string $key, string $fp): string
{
    return 'scene.php?w=' . rawurlencode($slug) . '&k=' . rawurlencode($key) . '&f=' . rawurlencode($fp);
}

/**
 * What the bytes GET may serve for (key, fingerprint), or null for a 404.
 *
 * THREE RULES AND NO FOURTH. A still or a panorama is served inline ONLY if its
 * bytes sniff as an image — photo.php's own rule, because a docroot that
 * echoes unverified bytes under an image type is a stored-XSS delivery
 * service. A splat or a glb is not something a browser renders, so it is
 * served only under a type on a two-entry allowlist, as an ATTACHMENT, with
 * nosniff: whatever is in it is saved, never executed. A sound is served
 * inline to an <audio> only when its row's type is on the audio allowlist AND
 * its magic bytes agree (xeric_play_audio_sniff). A viewer row has no bytes
 * at all. The path is the row's file column, basename()d, under this
 * db's own scenes dir — never a user string.
 *
 * @return ?array{path:string,mime:string,inline:bool,name:string,bytes:string}
 */
function xeric_play_look_file(PDO $db, string $key, string $fp): ?array
{
    // A look is forty hex; a sound is the same behind 'sound:' — and the
    // prefix IS the channel, so a sound fingerprint can only find a sound row.
    if ($key === '' || strlen($key) > 64 || !preg_match('/^(sound:)?[a-f0-9]{40}$/', $fp)) return null;
    $row = xeric_scene_of($db, $key, $fp, str_starts_with($fp, 'sound:') ? 'sound' : 'look');
    if ($row === null || (string)$row['status'] !== 'done' || (string)$row['file'] === '') return null;
    $dir = xeric_play_scenes_dir($db);
    if ($dir === '') return null;
    $path = $dir . '/' . basename((string)$row['file']);
    if (!is_file($path)) return null;

    $bytes = (string)file_get_contents($path);
    $kind  = (string)$row['kind'];
    if (in_array($kind, ['image', 'pano'], true)) {
        $mime = (string)(@getimagesizefromstring($bytes)['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return null;
        return ['path' => $path, 'mime' => $mime, 'inline' => true, 'name' => basename($path), 'bytes' => $bytes];
    }
    if (in_array($kind, ['splat', 'glb'], true)) {
        $mime = (string)$row['mime'];
        if (!in_array($mime, ['model/gltf-binary', 'application/octet-stream'], true)) return null;
        return ['path' => $path, 'mime' => $mime, 'inline' => false, 'name' => basename($path), 'bytes' => $bytes];
    }
    // A sound: the row's type off the allowlist, AND the bytes' own magic
    // agreeing with it. Never served as an image, whatever it sniffs as — the
    // channel and the kind both have to say audio before anything does.
    if ($kind === 'audio' && (string)$row['channel'] === 'sound') {
        $mime = xeric_scene_audio_mime((string)$row['mime']);
        if ($mime === '' || xeric_play_audio_sniff(substr($bytes, 0, 16)) !== $mime) return null;
        return ['path' => $path, 'mime' => $mime, 'inline' => true, 'name' => basename($path), 'bytes' => $bytes];
    }
    return null;
}

/**
 * The room in a sentence or three, from the brief's own fields — the name, the
 * light, the sky, the state of the door, and who is standing in it doing what.
 * NOT the brief's `prompt`: that is a paragraph addressed to a provider (it
 * names the vantage and ends on the rating clause) and reading it aloud to the
 * player would be reading them the stage directions. No pronouns: names and
 * the week's own words carry everyone, the same rule the prompt keeps.
 */
function xeric_play_look_prose(array $b): string
{
    $sky = ucfirst((string)$b['light']);
    $wx  = trim((string)$b['weather']);
    $out = '<b>' . h((string)$b['name']) . '</b> — ' . h($sky) . '.'
         . ($wx !== '' ? ' ' . h(rtrim($wx, '.') . '.') : '');
    if (!empty($b['dark']))   $out .= ' The place has gone dark.';
    elseif (empty($b['open'])) $out .= ' It is shut at this hour.';
    if ($b['who'] === []) {
        $out .= ' Nobody else is here.';
    } else {
        $bits = [];
        foreach ($b['who'] as $p) {
            $d = rtrim(trim((string)$p['doing']), '.');
            $bits[] = h((string)$p['name']) . ($d !== '' ? ', ' . h($d) : (!empty($p['at_home']) ? ', at home' : ''));
        }
        $out .= ' Here: ' . implode('; ', $bits) . '.';
    }
    return '<p class="lookprose">' . $out . '</p>';
}

/**
 * What one press of "look" owes, read before anybody waits on a machine: the
 * look, unless it is already drawn as it looks now and on this disk; and the
 * sound, when this machine can make one (xeric_scene_channels — an image
 * machine draws and cannot hear) and the room does not already sound like
 * this on disk. Reading the endpoint is a session read, not a call.
 *
 * @return array{look:bool,sound:bool,sound_brief:?array}
 */
function xeric_play_look_owed(array $t, PDO $db, array $brief, ?array $ep): array
{
    $dir = xeric_play_scenes_dir($db);
    $row = xeric_scene_of($db, (string)$brief['key'], (string)$brief['fingerprint']);
    $out = ['look' => !($row !== null && xeric_play_look_has($row, $dir)), 'sound' => false, 'sound_brief' => null];
    if ($ep === null || !in_array('sound', xeric_scene_channels($t, $ep), true)) return $out;
    $sb = xeric_sound_brief($t, $db, (string)$brief['key']);
    if ($sb === null || empty($sb['enabled'])) return $out;
    $srow = xeric_scene_of($db, (string)$sb['key'], (string)$sb['fingerprint'], 'sound');
    $out['sound']       = !($srow !== null && xeric_play_look_has($srow, $dir));
    $out['sound_brief'] = $sb;
    return $out;
}

/**
 * What the heart draws ahead with this scene machine, in one sentence — the
 * engine's own default (xeric_scene_prerender) said out loud on the machines
 * screen, because "the heart spends nothing on a meter nobody asked it to" is
 * a promise, and a promise belongs where the meter is chosen. A world's
 * template can say otherwise (media.scene.prerender); this is what happens
 * when it does not. HTML, escaped here.
 */
function xeric_play_scene_ahead_line(array $ep, string $keyVar): string
{
    if (!xeric_scene_costly($ep)) {
        return 'Drawn ahead: while the world lives, the heart draws the room you are standing in '
            . 'before you look at it, on this computer, unless a world&rsquo;s template says otherwise.';
    }
    return 'Drawn ahead: nothing — the heart never spends ' . h($keyVar) . ' on a room nobody looked at, '
        . 'unless a world&rsquo;s template sets <code>media.scene.prerender</code>.';
}

/**
 * Rows still owed: pending ON A CHANNEL THIS MACHINE CAN MAKE, or working on
 * a machine that said "later". The channel matters: an image machine never
 * makes a sound (xeric_scene_channels), the reaper leaves those rows waiting
 * untried, and scene-worker.php, counting them, would sit out its fifteen minutes
 * on work nobody here can do.
 *
 * @param string[] $channels
 */
function xeric_play_scene_owed(PDO $db, array $channels): int
{
    $waiting = (int)$db->query("SELECT COUNT(*) FROM scene_jobs
                                WHERE status = 'working' AND remote_job IS NOT NULL")->fetchColumn();
    if ($channels === []) return $waiting;
    // A row from before channels existed is a look (xeric_scene_channel_of).
    $in = implode(',', array_fill(0, count($channels), '?'));
    $st = $db->prepare("SELECT COUNT(*) FROM scene_jobs WHERE status = 'pending'
                        AND (CASE WHEN channel = '' THEN 'look' ELSE channel END) IN ($in)");
    $st->execute(array_values($channels));
    return $waiting + (int)$st->fetchColumn();
}

/**
 * The people in the room as buttons — one per `who` row, its name on it, what
 * they are at as its title and description, its bearing in data-b. Pressing
 * one opens that person's thread, the same function the cast chips call
 * (play.php, bindLook). Over a panorama the page's script places each at its
 * bearing; under a still they sit in a row, LEFT TO RIGHT as you would see
 * them, which is bearing read as -180..180 rather than 0..359.
 *
 * Furniture gets no button — a tag on the pie case is noise — but its
 * bearings ride on the viewer's root (data-bearings) for whatever wants to
 * aim at them next.
 */
function xeric_play_look_tags(array $who, string $class): string
{
    usort($who, fn($a, $b) => xeric_play_signed_bearing((int)$a['bearing']) <=> xeric_play_signed_bearing((int)$b['bearing']));
    $out = '';
    foreach ($who as $p) {
        $d = trim((string)$p['doing']);
        $out .= '<button type="button" class="looktag" data-h="' . h((string)$p['handle'])
              . '" data-b="' . (int)$p['bearing'] . '"'
              . ($d !== '' ? ' title="' . h($d) . '" aria-description="' . h($d) . '"' : '')
              . ($class === 'looktags' ? ' hidden' : '') . '>' . h((string)$p['name']) . '</button>';
    }
    return $out === '' ? '' : '<div class="' . $class . '">' . $out . '</div>';
}

/** A bearing as a signed turn: 350° is ten degrees to your left, not most of the way round. */
function xeric_play_signed_bearing(int $b): int
{
    $b = (($b % 360) + 360) % 360;
    return $b > 180 ? $b - 360 : $b;
}

/**
 * #look, rendered — and the ONE place it is assembled. The states, in the
 * order they are decided:
 *
 *   nowhere           one line, and an invitation to walk.
 *   no view yet       the room in prose, and a look button.
 *   image             the still, the people as a row of buttons under it,
 *                     its light and sky under that.
 *   pano              a canvas the page's own script turns into a look-around
 *                     (play.php, pano), the people as buttons it places at
 *                     their bearings; the image is the fallback.
 *   viewer            a link out, NEVER a frame: this app sends
 *                     frame-ancestors 'none' and frames nothing either,
 *                     because a third party's page inside ours is a page
 *                     that can read where you are standing.
 *   splat / glb       a download, said plainly, because a browser tab is not
 *                     where those are looked at yet.
 *
 * Over any of them: "from an earlier hour" when the view is of a room that
 * has since changed, and "developing…" while the current look is being made.
 * Under any of them: the day strip, when the room has been seen in two lights
 * or more; and what you hear — the line, and the recording when there is one
 * for exactly this sound.
 *
 * THE BUTTON IS SHOWN, NOT PROBED. Whether a machine is CONFIGURED is a read
 * of the session; whether it is ANSWERING is a network call, and this renders
 * on every turn's repaint. So configured lights the button and the POST asks
 * the machine — and says so, with a 409 in plain words, if nobody answers.
 */
function xeric_play_look_html(array $t, PDO $db, ?array $now, string $slug, bool $mine): string
{
    try {
        $l = xeric_play_look($t, $db, $now);
    } catch (Throwable $e) {
        // A world from before scene_jobs, a locked table: the sidebar still
        // draws. The view is a nicety; the rest of the screen is not.
        return '<p class="quiet">The room cannot be shown right now.</p>';
    }
    $b = $l['brief'];
    if ($b === null) {
        return '<p class="quiet">You are nowhere in particular. Walk somewhere and the room will show itself.</p>';
    }

    $ep  = null;
    try { $ep = xeric_scene_endpoint(); } catch (Throwable $e) { $ep = null; }
    $row = $l['row'];

    // -- the view ---------------------------------------------------------------
    $view = '';
    $meta = '<p class="lookmeta">' . h(ucfirst((string)$b['light']))
          . (trim((string)$b['weather']) !== '' ? ' · ' . h(rtrim(trim((string)$b['weather']), '.')) : '');
    if ($row !== null) {
        $kind = (string)$row['kind'];
        $src  = xeric_play_look_src($slug, (string)$row['key'], (string)$row['fingerprint']);
        $alt  = (string)$b['name'] . ', ' . (string)$b['light'];
        if ($kind === 'image') {
            $view .= '<div class="lookmain lookstill" data-src="' . h($src) . '"><img class="lookimg" src="' . h($src) . '" alt="' . h($alt) . '">'
                   . xeric_play_look_tags($b['who'], 'looktagrow') . '</div>';
        } elseif ($kind === 'pano') {
            // The bearings of everything, furniture too, on the viewer's
            // root: the tags read the people's off their own buttons, and the
            // rest is there for the next client that wants to aim at the room.
            $bear = ['who'   => array_map(fn($p) => ['handle' => (string)$p['handle'], 'bearing' => (int)$p['bearing']], $b['who']),
                     'items' => array_map(fn($i) => ['text' => (string)($i['text'] ?? ''), 'bearing' => (int)($i['bearing'] ?? 0)],
                                          (array)$b['interior'])];
            $view .= '<div class="lookmain lookpanobox" data-src="' . h($src) . '" data-alt="' . h($alt) . '"'
                   . ' data-bearings="' . h((string)json_encode($bear, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">'
                   . '<canvas class="lookpano" tabindex="0" role="img" aria-label="'
                   . h('A look around ' . (string)$b['name'] . '. Drag, or use the arrow keys, to turn; scroll to zoom.')
                   . '"></canvas>' . xeric_play_look_tags($b['who'], 'looktags') . '</div>';
        } elseif ($kind === 'viewer') {
            $view .= '<p class="lookmain"><a class="lookview" href="' . h((string)$row['url'])
                   . '" target="_blank" rel="noopener noreferrer">open the room in its viewer</a></p>';
        } else {
            $ext = ltrim(strrchr(basename((string)$row['file']), '.') ?: '.' . $kind, '.');
            $view .= '<p class="lookmain"><a class="lookdl" href="' . h($src) . '" download>download the room (.' . h($ext) . ')</a></p>'
                   . '<p class="note">This app shows panoramas and stills inline; a ' . h($kind)
                   . ' opens in a viewer of your own.</p>';
        }
        $view .= '<p class="lookearlier" hidden></p>'
               . $meta . ($l['stale'] ? ' <span class="lookstale">from an earlier hour</span>' : '') . '</p>';
    } else {
        $view .= xeric_play_look_prose($b);
    }

    // -- the day strip ----------------------------------------------------------
    // In day order (xeric_scene_day already is), the view on screen marked.
    // Pressing a thumb swaps the view to it (play.php) and says "earlier: …";
    // the thumbs are the same bytes GET as the view, sized by CSS.
    $strip = '';
    if ($l['day'] !== []) {
        $strip = '<div class="lookday" role="group" aria-label="this room through the day">';
        foreach ($l['day'] as $light => $d) {
            $cur  = $row !== null && (int)$d['id'] === (int)$row['id'];
            $dsrc = xeric_play_look_src($slug, (string)$d['key'], (string)$d['fingerprint']);
            // data-now marks the hour the room looks like NOW, written here
            // rather than inferred by the page: dayView reads it to decide
            // whether "earlier: …" applies, and a strip whose "now" thumb never
            // said so could never clear it (PUNCHLIST, the day strip).
            $strip .= '<button type="button" class="lookthumb' . ($cur ? ' cur' : '') . '" data-src="' . h($dsrc)
                    . '" data-kind="' . h((string)$d['kind']) . '" data-light="' . h((string)$light) . '"'
                    . ' data-now="' . ($cur ? '1' : '0') . '"'
                    . ' aria-pressed="' . ($cur ? 'true' : 'false') . '">'
                    . '<img src="' . h($dsrc) . '" alt="" loading="lazy"><span>' . h((string)$light) . '</span></button>';
        }
        $strip .= '</div>';
    }

    // -- what you hear ----------------------------------------------------------
    // The line always; the recording only on request — preload none, never
    // autoplay, and a `listen` toggle rather than a sound that starts because
    // somebody walked into a diner with their speakers up.
    $sound = '';
    if ($l['sound'] !== null) {
        $sound = '<p class="looksound">' . h((string)$l['sound']['line']) . '</p>';
        if ($l['sound_row'] !== null) {
            $asrc = xeric_play_look_src($slug, (string)$l['sound_row']['key'], (string)$l['sound_row']['fingerprint']);
            $sound .= '<div class="lookaudio"><button type="button" class="nbtn ghost" data-listen aria-pressed="false">listen</button>'
                    . '<audio loop preload="none" controls src="' . h($asrc) . '"></audio></div>';
        }
    }

    // -- who came and went, and the room ------------------------------------------
    // The beat is the doorway's sibling (xeric_travel_comings): somebody
    // walking in or getting up to go in the last few world-minutes, said from
    // observables. Inside a live room the same sentence is the room's own
    // narrator row instead, so it is said in one place and never in both.
    //
    // The room itself — three to five of them and you, engine/room.php — has
    // its strip beside #look in play.php, because a repaint replaces #look
    // wholesale and a conversation must not be wiped by a clock tick. What
    // lives here is the door: "talk to the room" for whoever may speak, and
    // for a guest, who may only read, the way in to the thread.
    $live = null;
    try { $live = xeric_room_live_get($db); } catch (Throwable $e) { $live = null; }
    $liveHere = $live !== null && (string)$live['place'] === (string)$b['key'];
    $beat = '';
    if (!$liveHere) {
        try {
            $cm = xeric_travel_comings($t, $db, $now ?? xeric_clock_now($db, $t), (string)$b['key']);
            if ($cm['line'] !== '') $beat = '<p class="lookbeat">' . h($cm['line']) . '</p>';
        } catch (Throwable $e) { $beat = ''; }
    }
    $castHere = array_values(array_filter((array)$b['who'],
        fn($p) => xeric_world_character($t, (string)($p['handle'] ?? '')) !== null));
    $room = '';
    if ($liveHere) {
        $with = xeric_join_list(array_map(fn($h) => (string)($live['names'][$h] ?? $h), (array)$live['present']));
        $room = '<p class="lookroom"><button type="button" class="nbtn" data-room>'
              . ($mine ? 'back to the room' : 'read the room') . '</button>'
              . ($with !== '' ? ' <span class="note">talking with ' . h($with) . '</span>' : '') . '</p>';
    } elseif ($mine && count($castHere) >= 2) {
        $room = '<p class="lookroom"><button type="button" class="nbtn" data-room>talk to the room</button></p>';
    }

    // -- what can be pressed ----------------------------------------------------
    $act = '';
    if ($l['pending']) {
        // data-pending is what the page's script watches for: a reload in the
        // middle of a render still knows to come back and look.
        $act = '<p class="lookdev" data-pending="1">developing…</p>';
    } elseif ($row === null || $l['stale']) {
        if (empty($b['enabled'])) {
            $act = '<p class="note">This world does not draw its rooms.</p>';
        } else {
            if ($l['failed']) $act .= '<p class="note">The machine could not make this room last time.</p>';
            if ($mine && $ep === null) {
                $act .= '<p class="lookact"><button type="button" class="nbtn" data-look disabled>look</button>'
                      . ' <span class="note">no scene machine — set one on the <a href="model.php">machines screen</a></span></p>';
            } elseif ($mine) {
                $act .= '<p class="lookact"><button type="button" class="nbtn" data-look>look</button></p>';
            }
        }
    }

    return $view . $beat . $act . $strip . $sound . $room;
}

// ---------------------------------------------------------------------------
// The room — three to five of them and you (room.php and room-worker.php)
// ---------------------------------------------------------------------------
//
// engine/room.php owns every rule: who may sit, who answers, when the room
// keeps talking, how a line is spaced, what the close writes. This is the
// web's half of the session — where a line comes from (a POST), who carries
// the turn (a detached worker under a lease), where the transcript goes (the
// thread rows the page polls), and the doors around it: a guest reads, never
// speaks; a cast beat spends from the owner's hour like a `reroll`, a player
// line like a message; a person waiting on the model outranks a room talking
// to itself.

/** Whoever is at the table on this side of the screen, as the room will name them. */
function xeric_play_room_player(array $w): array
{
    $id = xeric_session_player((string)$w['slug']) ?? XERIC_PLAYER_FIRST;
    $t  = $w['template'];
    // No name is no name: '' here, and xeric_room_open seats them under a
    // sentinel for mentions and "the visitor" for prose. The literal 'you'
    // this used to hand over was a word the mention test found everywhere.
    $name = $id > XERIC_PLAYER_FIRST
        ? xeric_player_name($w['db'], $id, $t)
        : trim((string)($t['user']['name'] ?? ''));
    return ['id' => $id, 'name' => $name];
}

/**
 * The session as the page may hold it — names and state, never a prompt.
 * Null when there is no live room. Guests get the same shape (they may read
 * the thread); `mine` says whether the composer is theirs.
 */
function xeric_play_room_public(array $t, ?array $live, bool $mine): ?array
{
    if ($live === null) return null;
    $names = (array)$live['names'];
    $present = [];
    foreach ((array)$live['present'] as $h) $present[] = ['h' => (string)$h, 'name' => (string)($names[$h] ?? $h)];
    $last = '';
    foreach (array_reverse((array)$live['trail']) as $b) {
        if (($b['speaker'] ?? '') !== XERIC_ROOM_YOU && isset($b['speaker'])) { $last = (string)$b['speaker']; break; }
    }
    $typing = null;
    if (is_array($live['typing'] ?? null) && xeric_room_lease_fresh($live)) {
        $th = (string)($live['typing']['h'] ?? '');
        $typing = ['h' => $th, 'name' => (string)($names[$th] ?? $th),
                   'queue' => (string)($live['typing']['queue'] ?? '')];
    }
    return [
        'conv'       => (int)$live['conv'],
        'place'      => (string)$live['place'],
        'place_name' => (string)($live['room']['place_name'] ?? ''),
        'you'        => xeric_room_you_named($live) ? xeric_room_you_name($live) : 'you',
        'present'    => $present,
        'far'        => array_values(array_map(fn($h) => (string)($names[$h] ?? xeric_world_name($t, (string)$h)), (array)$live['far'])),
        'typing'     => $typing,
        'speaking'   => $last,
        'turn'       => (int)$live['budget'] > 0 || xeric_room_lease_fresh($live),
        'said'       => (int)$live['said'],
        'beats'      => (int)$live['beats'],
        'carry_left' => max(0, XERIC_ROOM_CARRY_SESSION - (int)$live['carried']),
        'error'      => (string)($live['error'] ?? ''),
        'mine'       => $mine,
    ];
}

/** Rows as the page draws them: me, them, narr — the thread's own three lanes. */
function xeric_play_room_rows(array $t, array $live, array $rows): array
{
    $names = (array)$live['names'];
    $me    = xeric_room_you_named($live) ? xeric_room_you_name($live) : 'you';   // the page's own lane says you
    $out = [];
    foreach ($rows as $r) {
        $role = (string)$r['role'];
        $h    = (string)($r['handle'] ?? '');
        $out[] = [
            'id'   => (int)$r['id'],
            'who'  => $role === 'user' ? 'me' : ($role === 'character' && $h !== '' ? 'them' : 'narr'),
            'h'    => $role === 'character' ? $h : ($role === 'user' ? XERIC_ROOM_YOU : ''),
            'name' => $role === 'user' ? $me
                    : ($role === 'character' ? (string)($names[$h] ?? xeric_world_name($t, $h)) : ''),
            'text' => (string)$r['content'],
            'when' => $r['world_epoch'] !== null ? xeric_play_stamp($t, (int)$r['world_epoch']) : '',
        ];
    }
    return $out;
}

/**
 * Who came and went, applied: one narrator row in the room's own words
 * (xeric_travel_comings_line — the look block's beat, so the two never say it
 * twice), the leavers unseated at that row, the adults seated from it. A
 * child walking in is reported back, because the answer to that is the seal.
 * MUST run inside xeric_room_live_edit().
 */
function xeric_play_room_presence(array $t, PDO $db, array &$live, array $now): ?string
{
    $p = xeric_room_presence($t, $db, $live, $now);
    if ($p['far_left'] !== []) $live['far'] = array_values(array_diff((array)$live['far'], $p['far_left']));
    $leaving = array_keys($p['left']);
    $coming  = array_merge($p['arrived'], $p['minors']);
    if ($leaving === [] && $coming === []) return null;
    $line = xeric_travel_comings_line($t, $now, (string)$live['place'], $coming, $leaving, $db);
    $mid  = xeric_message_append($db, (int)$live['conv'], 'narrator', null, $line, (int)$now['epoch']);
    foreach ($leaving as $h) xeric_room_unseat($live, (string)$h, $mid, $p['left'][$h], (int)$now['epoch']);
    foreach ($p['arrived'] as $h) xeric_room_seat($t, $live, (string)$h, $mid, $now);
    $live['trail'][] = array_filter(['mid' => $mid, 'stage' => $line,
        'left' => $leaving !== [] ? $p['left'] : null, 'arrived' => $p['arrived'] !== [] ? $p['arrived'] : null,
        'minor' => $p['minors'] !== [] ? $p['minors'] : null], fn($v) => $v !== null);
    return $p['minors'] !== [] ? 'minor' : null;
}

/**
 * The session's housekeeping, no model involved: who came and went, and
 * whether it is over (you walked out, too few left, twenty quiet minutes, a
 * stopped world, a child at the door). Left to the worker while one holds the
 * floor. Returns the conversations sealed — which the caller lands.
 */
function xeric_play_room_tick(array $w, ?array $now = null): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $now ??= xeric_clock_now($db, $t);
    return xeric_room_live_edit($db, function (?array &$live) use ($t, $db, $now): array {
        if ($live === null || xeric_room_lease_fresh($live)) return [];
        if (xeric_play_room_presence($t, $db, $live, $now) === 'minor') {
            return [xeric_room_seal_here($db, $live, 'minor', $now)];
        }
        $over = xeric_room_over($t, $db, $live, $now);
        return $over !== null ? [xeric_room_seal_here($db, $live, $over, $now)] : [];
    });
}

/**
 * The player says something to the room. No model call: the line is heard
 * (floor, wall), lands as its row at once, and the turn it buys is booked —
 * the caller spawns the worker when this hands back a fresh lease.
 *
 * @return array{mid:int,text:string,opened:bool,lease:?string,conv:int}
 * @throws RuntimeException 'room: refused, …' — nothing written
 */
function xeric_play_room_say(array $w, string $text, ?array $now = null): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $now ??= xeric_clock_now($db, $t);
    $where = xeric_player_where($t, $db);
    if ($where === null) {
        throw new RuntimeException('room: refused, you are not standing anywhere — walk somewhere with people in it first');
    }
    $player = xeric_play_room_player($w);

    // First, whatever the last session owes: a room in another place, or one
    // that has gone quiet, closes before this line opens the next. One edit:
    // the tick's xeric_room_over() already reads 'you left' off the same
    // position this line was given, so a second "seal it if elsewhere" pass
    // was a transaction that could never change anything.
    xeric_play_room_tick($w, $now);

    return xeric_room_live_edit($db, function (?array &$live) use ($t, $db, $where, $now, $player, $text): array {
        if ($live !== null && (string)$live['place'] !== $where) {
            throw new RuntimeException('room: refused, the last conversation is still finishing its line — try again in a moment');
        }
        $opened = false;
        if ($live === null) {
            $live = xeric_room_open($t, $db, $now, $where, $player);
            $live['conv'] = xeric_conversation_create($db, '', 'room', $where);
            $opened = true;
        } elseif (!xeric_room_lease_fresh($live)) {
            xeric_play_room_presence($t, $db, $live, $now);
        }
        $lines = xeric_room_lines($live, xeric_room_rows($db, (int)$live['conv']));
        $heard = xeric_room_hear($t, $live, $lines, $text);
        $mid   = xeric_message_append($db, (int)$live['conv'], 'user', null, $heard, (int)$now['epoch']);
        xeric_room_said($live, $mid, $heard, $now);
        unset($live['error']);
        $lease = null;
        if (!xeric_room_lease_fresh($live)) {
            $lease = bin2hex(random_bytes(8));
            $live['lease'] = ['id' => $lease, 'until' => microtime(true) + XERIC_ROOM_LEASE];
        }
        return ['mid' => $mid, 'text' => $heard, 'opened' => $opened, 'lease' => $lease, 'conv' => (int)$live['conv']];
    });
}

/**
 * May the engine be asked to speak for these people on this machine without
 * a person asking? Local means the world's engine and every pinned voice of
 * the people at the table are `local`-kind machines (this box, or one on the
 * owner's own network) — never a hosted key.
 */
function xeric_play_room_local(PDO $db, array $present, ?string $sid = null): bool
{
    try {
        if ((string)(xeric_play_endpoint($sid)['kind'] ?? '') !== 'local') return false;
    } catch (Throwable $e) {
        return false;
    }
    foreach ($present as $h) {
        $base = xeric_play_speaker_machine($db, (string)$h);
        if ($base !== '' && xeric_model_kind($base) !== 'local') return false;
    }
    return true;
}

/**
 * TALKING WITHOUT YOU: one beat, asked for by a page that has sat idle with
 * the tab visible. The engine's gate decides (xeric_room_carry_idle); this
 * reads the queue and the machine for it and, on yes, books the beat and
 * takes the lease. The heart never calls this — a room nobody is watching
 * says nothing.
 *
 * @return array{ok:bool,why:string,lease:?string}
 */
function xeric_play_room_carry(array $w, ?string $sid = null): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $q  = xeric_queue_state();
    $idle = (array)($q['line'] ?? []) === [] && !xeric_queue_busy();
    $live0 = xeric_room_live_get($db);
    $local = $live0 !== null ? xeric_play_room_local($db, (array)$live0['present'], $sid) : false;
    $here  = $live0 !== null && xeric_player_where($t, $db) === (string)$live0['place'];
    return xeric_room_live_edit($db, function (?array &$live) use ($t, $idle, $local, $here): array {
        if ($live === null) return ['ok' => false, 'why' => 'There is no conversation to carry.', 'lease' => null];
        $g = xeric_room_carry_idle($t, $live, ['queue_idle' => $idle, 'local' => $local, 'player_here' => $here]);
        if (!$g['ok']) return ['ok' => false, 'why' => $g['why'], 'lease' => null];
        $lease = bin2hex(random_bytes(8));
        $live['mode']     = 'idle';
        $live['budget']   = 1;
        $live['owed']     = [];
        $live['answered'] = 0;
        $live['run']      = (int)$live['run'] + 1;
        $live['carried']  = (int)$live['carried'] + 1;
        $live['lease']    = ['id' => $lease, 'until' => microtime(true) + XERIC_ROOM_LEASE];
        return ['ok' => true, 'why' => '', 'lease' => $lease];
    });
}

/**
 * KEEP THE TURN OURS. The lease is good for XERIC_ROOM_LEASE from now. A beat
 * is a wait in line, a probe and a call — on a slow model that is most of
 * the lease, so renewing it only at the top of the beat let it lapse mid-call:
 * the next line spawned a second worker and the first one's finished, already
 * charged line was thrown away as "not ours". The worker renews at the top,
 * on every queue update while it waits, and the moment the call returns.
 */
function xeric_play_room_renew(array &$live): void
{
    if (is_array($live['lease'] ?? null)) $live['lease']['until'] = microtime(true) + XERIC_ROOM_LEASE;
}

/** Is a turn being carried right now? A skip must not move the clock under it. */
function xeric_play_room_midturn(PDO $db): bool
{
    $live = xeric_room_live_get($db);
    return $live !== null && xeric_room_lease_fresh($live) && (int)$live['budget'] > 0;
}

/**
 * Carry the turn: the worker's loop, here so a test can drive it in-process.
 *
 * Per beat — re-check presence, position and pause under the edit; draw and
 * set typing; take the ONE model slot with the queue phrase showing where the
 * typing dots are; pick the speaker's machine and step (one call); RELEASE
 * the slot; then, if the player spoke meanwhile, drop the line and say so in
 * the trail ("you spoke first"); else sleep until the line is due — the row
 * does not exist before then, so no client can show it early — append it,
 * meter it, and let the engine say whether the room keeps talking.
 *
 * $o: endpoint (fn(handle): array), sleep (fn(float)), queue (bool, default
 * true), wait (seconds in line), seed, up (bool: probe the model, default true).
 *
 * @return array{beats:int,dropped:int,stopped:string,sealed:bool,notes:array}
 */
function xeric_play_room_run(array $w, string $sid, string $lease, array $o = []): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $endpointOf = $o['endpoint'] ?? fn(string $h): array => xeric_play_speaker_endpoint($t, $db, $h, $sid);
    $sleep      = $o['sleep'] ?? function (float $s): void { if ($s > 0) usleep((int)round($s * 1e6)); };
    $useQueue   = (bool)($o['queue'] ?? true);
    $probe      = (bool)($o['up'] ?? true);
    $out = ['beats' => 0, 'dropped' => 0, 'stopped' => '', 'sealed' => false, 'notes' => []];

    $ours = fn(?array $live): bool => $live !== null && is_array($live['lease'] ?? null)
                                      && (string)($live['lease']['id'] ?? '') === $lease;
    $fail = function (string $sentence, string $kind) use ($db, $ours): void {
        xeric_room_live_edit($db, function (?array &$live) use ($ours, $sentence, $kind): void {
            if (!$ours($live)) return;
            $live['budget'] = 0;
            $live['typing'] = null;
            $live['lease']  = null;
            $live['error']  = $sentence;
            $live['trail'][] = ['refused' => $kind, 'why' => $sentence];
        });
    };

    for ($guard = 0; $guard < XERIC_ROOM_BEATS_CAP + 8; $guard++) {
        $now = xeric_clock_now($db, $t);

        // -- 1. the world first, then the draw, under the edit -----------------
        $plan = xeric_room_live_edit($db, function (?array &$live) use ($t, $db, $now, $ours): array {
            if (!$ours($live)) return ['stop' => 'not ours'];
            if (xeric_play_room_presence($t, $db, $live, $now) === 'minor') {
                xeric_room_seal_here($db, $live, 'minor', $now);
                return ['stop' => 'minor', 'sealed' => true];
            }
            $over = xeric_room_over($t, $db, $live, $now);
            if ($over !== null) {
                xeric_room_seal_here($db, $live, $over, $now);
                return ['stop' => $over, 'sealed' => true];
            }
            if ((int)$live['budget'] <= 0) {
                $live['lease'] = null; $live['typing'] = null;
                return ['stop' => 'done'];
            }
            $lines = xeric_room_lines($live, xeric_room_rows($db, (int)$live['conv']));
            $draw  = xeric_room_next($t, $db, $live, $lines);
            $sp    = (string)$draw['speaker'];
            $live['typing'] = ['h' => $sp, 'at' => microtime(true), 'queue' => ''];
            xeric_play_room_renew($live);
            return ['draw' => $draw, 'seq' => (int)$live['seq'], 'live' => $live, 'lines' => $lines];
        });
        if (isset($plan['stop'])) {
            $out['stopped'] = (string)$plan['stop'];
            $out['sealed']  = !empty($plan['sealed']);
            break;
        }
        $draw = $plan['draw'];
        $sp   = (string)$draw['speaker'];

        // -- 2. one call, under the one slot -----------------------------------
        $hold = null;
        if ($useQueue) {
            $got = xeric_queue_take('say', (float)($o['wait'] ?? XERIC_QUEUE_SAY_WAIT), $sid,
                function (int $ahead, int $eta, string $phrase) use ($db, $ours): void {
                    xeric_room_live_edit($db, function (?array &$live) use ($ours, $phrase): void {
                        if (!$ours($live)) return;
                        xeric_play_room_renew($live);
                        if (is_array($live['typing'] ?? null)) $live['typing']['queue'] = ucfirst($phrase);
                    });
                });
            if (!($got['ok'] ?? false)) {
                $fail((string)($got['message'] ?? 'The model is busy.'), 'queue');
                $out['stopped'] = 'queue';
                break;
            }
            $hold = $got['hold'];
        }
        $beat = null; $err = null; $limitOk = true;
        try {
            $ep = $endpointOf($sp);
            if ($probe && !xeric_llm_up($ep, 6)) {
                throw new RuntimeException('model_down: the model this xeric runs on is not answering, so the room has gone quiet. Nothing is lost.');
            }
            // A CAST BEAT SPENDS LIKE A REROLL, on the person whose room it is:
            // closer to the machine redrawing something than to somebody
            // saying a sentence, and never free.
            $lim = xeric_limit_take('reroll', ['sid' => $sid]);
            if (!($lim['ok'] ?? false)) {
                $limitOk = false;
                throw new RuntimeException('limit: ' . (string)($lim['message'] ?? 'that is the hour spent'));
            }
            $beat = xeric_room_step($t, $db, $plan['live'], $plan['lines'], $now, $ep, $draw,
                                    ['timeout' => XERIC_PLAY_CHAT_TIMEOUT]);
        } catch (Throwable $e) {
            $err = $e;
        } finally {
            if ($hold !== null) xeric_queue_release($hold);
        }
        if ($err !== null) {
            $m = $err->getMessage();
            $kind = str_starts_with($m, 'limit: ') ? 'limit'
                  : (str_starts_with($m, 'model_down: ') ? 'model_down'
                  : (xeric_play_say_refused($m) || str_contains($m, 'refused,') ? 'refused' : 'model'));
            $sentence = $kind === 'limit' ? substr($m, 7)
                      : ($kind === 'model_down' ? ucfirst(substr($m, 12))
                      : ($kind === 'refused' ? (string)preg_replace('/^room:\s*/', '', $m)
                      : xeric_play_say_error($m, (string)($plan['live']['names'][$sp] ?? ''))));
            $fail($sentence, $kind);
            $out['stopped'] = $kind;
            $out['notes'][] = $sentence;
            break;
        }

        // -- 3. did the player speak first? when is it due? --------------------
        $ready   = microtime(true);
        $q       = xeric_queue_state();
        $waiting = (array)($q['line'] ?? []) !== [];
        $res = xeric_room_live_edit($db, function (?array &$live) use ($ours, $plan, $beat, $draw, $ready, $sp): array {
            if (!$ours($live)) return ['stop' => true];
            xeric_play_room_renew($live);                 // the call is back: the lease runs from here
            if ((int)$live['seq'] !== (int)$plan['seq']) {
                $live['dropped'][] = ['speaker' => $sp, 'why' => 'you spoke first'];
                $live['typing'] = null;
                return ['dropped' => true];
            }
            $prev = '';
            foreach (array_reverse($plan['lines']) as $l) { if ($l['kind'] === 'line') { $prev = (string)$l['text']; break; } }
            $named = in_array((string)($draw['how'] ?? ''), ['addressed', 'owed'], true);
            $due = xeric_room_pace((float)$live['due'], $prev, $ready, $named, (string)$live['mode'] === 'idle',
                                   $live['conv'] . '|' . $live['beats']);
            $live['typing']['due'] = $due;
            return ['due' => $due];
        });
        if (!empty($res['stop'])) { $out['stopped'] = 'not ours'; break; }
        if (!empty($res['dropped'])) { $out['dropped']++; continue; }

        // -- 4. not a moment before it is due ----------------------------------
        $sleep(max(0.0, (float)$res['due'] - microtime(true)));

        // -- 5. land it, and let the engine say whether the room goes on -------
        $after = xeric_clock_now($db, $t);
        $here  = xeric_player_where($t, $db);
        $paused = xeric_clock_is_paused($db);
        $landed = xeric_room_live_edit($db, function (?array &$live) use ($t, $db, $ours, $plan, $beat, $draw, $res, $after, $here, $paused, $waiting, $limitOk, $sp, $o): string {
            if (!$ours($live)) return 'not ours';
            if ((int)$live['seq'] !== (int)$plan['seq']) {
                $live['dropped'][] = ['speaker' => $sp, 'why' => 'you spoke first'];
                $live['typing'] = null;
                return 'dropped';
            }
            $mid = xeric_message_append($db, (int)$live['conv'], 'character', $sp, (string)$beat['text'], (int)$after['epoch']);
            $carry = xeric_room_carry($live, $beat, [
                'limit_ok'      => $limitOk,
                'player_here'   => $here === (string)$live['place'],
                'queue_waiting' => $waiting,
                'paused'        => $paused,
            ] + (isset($o['seed']) ? ['seed' => $o['seed']] : []));
            xeric_room_spoke($live, $beat, $mid, $draw, $carry, $after, (float)$res['due']);
            return 'landed';
        });
        if ($landed === 'dropped') { $out['dropped']++; continue; }
        if ($landed !== 'landed') { $out['stopped'] = $landed; break; }
        $out['beats']++;
    }
    return $out;
}

/**
 * THE CLOSE, ONLY WITH A MODEL TO WRITE IT. The heart and the room worker
 * used to land a seal with endpoint null whenever the probe failed at that
 * instant — one hiccup forfeited every head's diary and deleted the seal that
 * would have retried them. A seal is retried by design, so a model that is not
 * answering is a seal left for the next pass: null here, nothing touched.
 *
 * @return ?array what xeric_play_room_land() landed, or null when the model is down
 */
function xeric_play_room_land_up(array $w, ?array $endpoint, int $timeout = 6): ?array
{
    if ($endpoint === null || !xeric_llm_up($endpoint, $timeout)) return null;
    return xeric_play_room_land($w, $endpoint);
}

/**
 * Land every sealed session of this world: the close through the engine
 * (xeric_room_land_live). $endpoint null forfeits the diaries into notes and
 * still lands the event. Each close is its own transaction; one that fails
 * stays sealed for the next try.
 */
function xeric_play_room_land(array $w, ?array $endpoint): array
{
    $out = [];
    $db  = $w['db'];
    foreach (xeric_room_seals($db) as $conv => $s) {
        // CLAIMED BEFORE THE DIARIES. Two workers (the turn's and a step
        // back's) can both find the same seal; the first to mark it lands it,
        // and a mark older than five minutes is a worker that died.
        $key = XERIC_ROOM_SEAL . $conv;
        $db->exec('BEGIN IMMEDIATE');
        try {
            $cur = json_decode((string)(xeric_world_state_get($db, $key) ?? ''), true);
            $mine = is_array($cur) && (int)($cur['landing'] ?? 0) < time() - 300;
            if ($mine) {
                $cur['landing'] = time();
                xeric_world_state_set($db, $key, json_encode($cur, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { /* nothing begun */ }
            $mine = false;
        }
        if (!$mine) continue;
        try {
            $out[$conv] = xeric_room_land_live($w['template'], $w['db'], $s, $endpoint,
                                               ['timeout' => XERIC_PLAY_CHAT_TIMEOUT]);
        } catch (Throwable $e) {
            $out[$conv] = ['error' => $e->getMessage()];
        }
    }
    return $out;
}
