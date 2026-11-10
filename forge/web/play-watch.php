<?php
/**
 * play-watch.php — the watch: a duet taken one line at a time (watch.php).
 *
 * PART OF play-lib.php, moved out of it whole: every function here kept its
 * name, signature and body. play-lib.php require_once's this file, so every
 * page that requires play-lib.php has it; requiring this file alone pulls
 * play-lib.php in as well (the two include each other once). The map of which
 * part lives where is at the top of play-lib.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

// ---------------------------------------------------------------------------
// The watch — a duet taken one line at a time (watch.php reads all of this)
// ---------------------------------------------------------------------------

require_once XERIC_WEB_LIB . '/engine/duet.php';   // the internals this steps through

//
// engine/duet.php runs a whole scene in one call because a CLI can afford to
// stand there while it does. A browser cannot: play/pause only means anything
// if the page is the thing asking for the next line, and a walk-in only means
// anything if there is a moment between lines for somebody to speak into. So
// this is the stepping wrapper the duet's internals were split up for —
// xeric_duet_together admits the pair, xeric_duet_order deals the seats,
// xeric_duet_system/scene/messages assemble each call, and NOTHING here
// re-derives what any of those already decide. One law is inherited whole and
// stated here because everything below leans on it: the engine writes nothing
// until the close, so the transcript has to live somewhere that is not the
// database — a scene abandoned half-way must cost nothing and land nothing.
//
// WHERE THE TRANSCRIPT LIVES. A state file under the data dir, the job files'
// own discipline (boot.php): session-scoped, keyed by slug and pair, swept
// when stale, and deleted the moment the close lands. It is the ONLY record of
// the scene until then. The db sees one transaction at the end — the same
// event + diaries + trail the CLI's close writes — or nothing at all.
//
// THE WALK-IN IS THE DUET PLUS A VOICE, NOT THE ROOM. The player's line rides
// the transcript as a user turn (the duet's own mapping hands anything that is
// not the speaker's to the user seat), no model is called for it, and strict
// alternation carries on — the next scheduled speaker answers having seen the
// words. What it does NOT yet do is stand the player in the room: the duet's
// assembly seats the player nowhere on purpose (xeric_duet_messages passes
// null into the now-block's playerWhere), and that is the engine's sentence to
// change, not this file's. The position is recorded here (state + trail) so
// the day the seam opens, this surface already carries the answer.

/** The handle a walk-in line wears in the transcript. Never a cast handle. */
const XERIC_WATCH_PLAYER = '__you';

/** A scene file untouched this long is an abandoned tab, and is swept. */
const XERIC_WATCH_TTL = 21600;

function xeric_watch_dir(): string
{
    return xeric_web_dir((string)xeric_web_config()['data_dir'] . '/watch');
}

/**
 * One scene per (session, xeric, pair). Hashed rather than concatenated so a
 * handle never becomes filesystem input, and pair-order-blind so starting
 * (ruth, dot) and reloading into (dot, ruth) find the same scene.
 */
function xeric_watch_path(string $sid, string $slug, string $a, string $b): string
{
    $pair = [$a, $b];
    sort($pair);
    return xeric_watch_dir() . '/' . sha1($sid . '|' . $slug . '|' . implode('|', $pair)) . '.json';
}

function xeric_watch_read(string $path): ?array
{
    $d = json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

function xeric_watch_write(string $path, array $s): void
{
    $s['at'] = time();
    @file_put_contents($path, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function xeric_watch_clear(string $path): void
{
    @unlink($path);
}

/** Old scenes are noise. Swept on the way past, never on a schedule. */
function xeric_watch_sweep(): void
{
    if (random_int(1, 10) !== 1) return;
    $cut = time() - XERIC_WATCH_TTL;
    foreach (glob(xeric_watch_dir() . '/*.json') ?: [] as $f) {
        if ((int)@filemtime($f) < $cut) @unlink($f);
    }
}

/**
 * A reload must not lose a running scene, and the client cannot name the pair
 * it forgot. The paths are hashed, so this walks the cast's pairs instead of
 * the directory — a few dozen sha1s, and only on the page render.
 */
function xeric_watch_find(string $sid, string $slug, array $t): ?array
{
    $handles = [];
    foreach ((array)($t['cast']['characters'] ?? []) as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h !== '') $handles[] = $h;
    }
    for ($i = 0; $i < count($handles); $i++) {
        for ($j = $i + 1; $j < count($handles); $j++) {
            $s = xeric_watch_read(xeric_watch_path($sid, $slug, $handles[$i], $handles[$j]));
            if ($s !== null) return $s;
        }
    }
    return null;
}

/**
 * The rooms, from the same read the where panel draws (xeric_travel_map, which
 * is xeric_world_who_is_where underneath — the exact presence that will admit
 * or refuse the pair). Only people who could carry half a conversation are
 * offered: the dead are already gone from the map, and scenery and the
 * not-yet-arrived are dropped here for the duet's own reasons.
 *
 * @return array<int,array{key:string,name:string,who:array,pairs:array}>
 */
function xeric_watch_rooms(array $t, PDO $db, ?array $now = null): array
{
    $rooms = [];
    foreach ((array)xeric_travel_map($t, $db, $now)['places'] as $p) {
        $who = [];
        foreach ((array)$p['who'] as $row) {
            $h = (string)($row['handle'] ?? '');
            $c = xeric_world_character($t, $h);
            if ($c === null || !empty($c['out'])) continue;
            $who[] = ['handle' => $h, 'name' => (string)$row['name'],
                      'doing'  => trim((string)($row['doing'] ?? ''))];
        }
        if ($who === []) continue;
        $pairs = [];
        for ($i = 0; $i < count($who); $i++) {
            for ($j = $i + 1; $j < count($who); $j++) {
                $pairs[] = [$who[$i]['handle'], $who[$j]['handle']];
            }
        }
        $rooms[] = ['key' => (string)$p['key'], 'name' => (string)$p['name'],
                    'who' => $who, 'pairs' => $pairs];
    }
    return $rooms;
}

/**
 * Open a scene: the duet's own admission, seating and ceiling, and then a
 * state array instead of a running loop. WRITES NOTHING — not to the db (the
 * engine's law) and not to disk (the caller owns the file, so a refusal here
 * leaves no scene behind).
 *
 * The pre-checks mirror xeric_duet()'s door sentence for sentence rather than
 * calling it, because calling it would run the whole scene; the one refusal
 * that must be the engine's verbatim — two people not in a room together —
 * IS the engine's, thrown by xeric_duet_together itself.
 *
 * @throws RuntimeException in the duet's own words, nothing written
 */
function xeric_watch_start(array $w, string $a, string $b, ?array $now = null): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $now ??= xeric_clock_now($db, $t);
    $a = trim($a);
    $b = trim($b);

    if ($a === '' || $b === '') throw new RuntimeException('duet: a scene needs two people named');
    if ($a === $b) throw new RuntimeException("duet: a conversation needs two people, '$a' twice is one");

    $world = (string)($t['meta']['name'] ?? 'this xeric');
    $names = [];
    foreach ([$a, $b] as $h) {
        if (xeric_world_character($t, $h) === null) {
            if (xeric_world_fixture($t, $h) !== null) {
                throw new RuntimeException("duet: '$h' is scenery, a fixture cannot carry half a conversation");
            }
            throw new RuntimeException("duet: nobody in $world answers to '$h'");
        }
        $names[$h] = xeric_world_name($t, $h);
        if (xeric_is_dead($db, $h)) throw new RuntimeException(xeric_death_refusal('duet', $names[$h]));
        $c = xeric_world_character($t, $h);
        if (!empty($c['out'])) throw new RuntimeException('duet: refused, ' . $names[$h] . ' has not entered the story');
    }

    // The engine's geographic refusal, verbatim — a stale pair off the entry
    // page lands exactly here, and the sentence names both rooms.
    $room = xeric_duet_together($t, $db, $a, $b, $now);

    // The room's ceiling, the duet's own fold: one minor clamps both voices.
    $eff   = xeric_world_rating($t);
    $minor = false;
    foreach ([$a, $b] as $h) {
        $who = xeric_viewer($t, ['handle' => $h]);
        if ($who['is_minor']) { $minor = true; $eff = xeric_viewer_rating($eff, $who); }
    }

    $order = xeric_duet_order($db, $a, $b, []);

    // THE SYSTEM, ASSEMBLED ONCE AND PINNED — the same pattern as the epoch,
    // eff and room pinned above. Recomputing it per line read live DB state
    // (arcs, lessons, the expect block, memories all move under the heart's
    // 60s tick), so a scene watched across several HTTP requests shredded its
    // own prefix cache mid-scene. One string per speaker, because the system
    // is per-voice; the pair itself cannot change inside a scene (a and b are
    // fixed at this door), so there is no regeneration path to build.
    $sys = [];
    foreach ([[$a, $b], [$b, $a]] as [$me, $other]) {
        $sys[$me] = xeric_duet_system($t, $db, $me, $other, $eff, (int)$now['epoch'],
            xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $me])), 12);
    }

    return [
        'v'        => 1,
        'slug'     => (string)$w['slug'],
        'a'        => $a,
        'b'        => $b,
        'names'    => $names,
        'room'     => $room,
        'epoch'    => (int)$now['epoch'],
        'eff'      => $eff,
        'minor'    => $minor,
        'sys'      => $sys,
        'first'    => (string)$order['first'],
        'turns'    => (int)$order['turns'],
        'extended' => (bool)$order['extended'],
        'lines'    => [],
        'spoken'   => 0,
        'player'   => ['present' => false, 'where' => null, 'place' => '', 'name' => ''],
        'started'  => time(),
        'at'       => time(),
    ];
}

/** Whose line is due. Strict alternation over SPOKEN lines; walk-ins spend no turn. */
function xeric_watch_next(array $s): array
{
    $h = ((int)$s['spoken'] % 2 === 0)
        ? (string)$s['first']
        : ((string)$s['first'] === (string)$s['a'] ? (string)$s['b'] : (string)$s['a']);
    return ['handle' => $h, 'name' => (string)($s['names'][$h] ?? $h)];
}

/** The scene as the page may hold it. The transcript rides so a reload redraws whole. */
function xeric_watch_public(array $s): array
{
    return [
        'a'      => (string)$s['a'],
        'b'      => (string)$s['b'],
        'names'  => (array)$s['names'],
        'place'  => (string)$s['room']['place_name'],
        'why'    => (string)$s['room']['why'],
        'doing'  => (array)($s['room']['doing'] ?? []),
        'first'  => (string)$s['names'][$s['first']],
        'next'   => (int)$s['spoken'] >= (int)$s['turns'] ? null : xeric_watch_next($s)['name'],
        'turns'  => (int)$s['turns'],
        'spoken' => (int)$s['spoken'],
        'lines'  => array_values(array_map(fn($l) => [
            'handle' => (string)$l['handle'], 'name' => (string)$l['name'], 'text' => (string)$l['text'],
        ], (array)$s['lines'])),
    ];
}

/**
 * ONE spoken line: one model call, through the speaker's own assembly, cleaned,
 * floored and walled exactly as the engine's loop does it — same functions,
 * same order, same sentences. Mutates $s (the caller owns writing it back) and
 * touches the database not at all.
 *
 * THE SYSTEM COMES OFF THE SCENE FILE, assembled once at xeric_watch_start()
 * and pinned there beside the epoch, eff and room. The old claim — "nothing
 * writes during a scene, so a rebuild is byte-identical" — was false: the
 * heart's 60s tick moves arcs, lessons, the expect block and memories under a
 * running scene, so a per-request rebuild changed bytes mid-scene and threw
 * the speaker's whole prefix out of cache. Only the VOLATILE end (material,
 * scene tail, the now-block) is rebuilt per line, which is where changing
 * state belongs. A scene file from before the pin is healed in place: the
 * system is assembled once on first use and pinned then.
 *
 * The charge is here, not at the door: a watched line is a model call and
 * spends like one (say.php's own ordering — the caller has held the slot and
 * seen the model up before this runs, so what is charged is what happens).
 *
 * @throws RuntimeException the duet's own sentences; the line did not happen
 */
function xeric_watch_line(array $w, array &$s, array $endpoint, string $sid = ''): array
{
    $t  = $w['template'];
    $db = $w['db'];
    if ((int)$s['spoken'] >= (int)$s['turns']) {
        throw new RuntimeException('watch: the scene has already said its last line');
    }

    $a = (string)$s['a'];
    $b = (string)$s['b'];
    $names   = (array)$s['names'];
    $epoch   = (int)$s['epoch'];
    $now     = xeric_world_now($t, $epoch);
    $speaker = xeric_watch_next($s)['handle'];
    $partner = $speaker === $a ? $b : $a;

    $protected = xeric_sweep_protected($t);
    $walls     = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $speaker]));
    // Pinned at the scene's door (xeric_watch_start) so every line of the
    // scene reads the same bytes — see the docblock above. A pre-pin scene
    // file gets its one assembly here, and it is pinned from then on.
    if (!isset($s['sys'][$speaker]) || (string)$s['sys'][$speaker] === '') {
        $s['sys'][$speaker] = xeric_duet_system($t, $db, $speaker, $partner,
            (string)$s['eff'], $epoch, $walls, 12);
    }
    $system    = (string)$s['sys'][$speaker];
    $material  = xeric_duet_material($t, $db, $speaker, $partner, $protected);
    $tail      = xeric_duet_scene($t, $speaker, $partner, (array)$s['room'], $walls, $material);

    $lines = array_map(fn($l) => ['handle' => (string)$l['handle'], 'text' => (string)$l['text']],
                       (array)$s['lines']);
    // The walk-in seam, landed: once the player has spoken into the scene,
    // their recorded position threads into every later assembly, so the
    // speakers stop insisting the room holds only each other. Before the
    // walk-in, null — the duet stays the world talking to itself.
    $pw = !empty($s['player']['present']) ? (string)($s['player']['where'] ?? '') : null;
    $messages = xeric_duet_messages($t, $speaker, $partner, $system, $lines, $tail, $now,
        $walls, xeric_deaths($db), (int)$s['spoken'] === 0, (int)$s['spoken'] === (int)$s['turns'] - 1,
        $pw !== '' ? $pw : null, $db,
        $pw !== null && trim((string)($s['player']['name'] ?? '')) !== '' ? (string)$s['player']['name'] : null);

    if ($sid !== '') xeric_limit_note('message', ['sid' => $sid]);

    try {
        $raw = xeric_chat_say($endpoint, $messages, [
            'temperature' => 0.85,
            'max_tokens'  => XERIC_DUET_MAX_TOKENS,
            'timeout'     => XERIC_PLAY_CHAT_TIMEOUT,
        ]);
    } catch (Throwable $e) {
        throw new RuntimeException('duet: ' . $names[$speaker] . ' did not answer, ' . $e->getMessage(), 0, $e);
    }

    $text = xeric_chat_clean($raw, $names[$speaker], $names[$partner], ['max_chars' => XERIC_DUET_MAX_CHARS]);
    if ($text === '') {
        throw new RuntimeException('duet: ' . $names[$speaker] . ' said nothing usable ('
            . mb_substr(trim($raw), 0, 120) . ')');
    }

    // The floor reads the new line with the line it answers — which after a
    // walk-in is the player's, and that is correct: the pair is one piece.
    $prev    = $s['lines'] !== [] ? (string)$s['lines'][count($s['lines']) - 1]['text'] : '';
    $refused = xeric_age_floor($t, [$a, $b], [$prev, $text]);
    if ($refused !== null) throw new RuntimeException(xeric_age_refusal('duet', $refused));

    foreach ($protected as $ph => $secret) {
        if (($ph === $a || $ph === $b) && xeric_sweep_touches($text, (string)$secret)) {
            throw new RuntimeException('duet: refused, the conversation put ' . $names[$ph]
                . ' next to the thing they must not know');
        }
    }

    $s['lines'][] = ['handle' => $speaker, 'name' => $names[$speaker], 'text' => $text];
    $s['spoken']  = (int)$s['spoken'] + 1;

    $done = (int)$s['spoken'] >= (int)$s['turns'];
    return [
        'handle' => $speaker,
        'name'   => $names[$speaker],
        'text'   => $text,
        'done'   => $done,
        'next'   => $done ? null : xeric_watch_next($s)['name'],
    ];
}

/**
 * The walk-in. No model call, no charge: the player's line lands in the
 * transcript as itself, under its own handle, and the duet's transcript
 * mapping does the rest — anything that is not the speaker's rides the user
 * seat, so the next scheduled line is answered with the words in view.
 *
 * The floor and the wall still read it: a rule about what may be said in this
 * room does not care which chair it was said from. And the player's real
 * position is recorded (xeric_player_where, a read) — carried in the state and
 * the trail today, into the assemblies the day the duet takes a playerWhere.
 *
 * @throws RuntimeException a refusal in the duet's words; nothing appended
 */
function xeric_watch_say(array $w, array &$s, string $text,
                         int $player = XERIC_PLAYER_FIRST): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $a  = (string)$s['a'];
    $b  = (string)$s['b'];

    $text = trim($text);
    if ($text === '') throw new RuntimeException('watch: there is nothing to say');
    if (mb_strlen($text) > XERIC_DUET_MAX_CHARS) $text = mb_substr($text, 0, XERIC_DUET_MAX_CHARS);

    $prev    = $s['lines'] !== [] ? (string)$s['lines'][count($s['lines']) - 1]['text'] : '';
    $refused = xeric_age_floor($t, [$a, $b], [$prev, $text]);
    if ($refused !== null) throw new RuntimeException(xeric_age_refusal('duet', $refused));

    foreach (xeric_sweep_protected($t) as $ph => $secret) {
        if (($ph === $a || $ph === $b) && xeric_sweep_touches($text, (string)$secret)) {
            throw new RuntimeException('duet: refused, the conversation put '
                . xeric_world_name($t, (string)$ph) . ' next to the thing they must not know');
        }
    }

    // WHOEVER ACTUALLY WALKED IN. The template's user is the person the world
    // was forged around; with two people in a house it is not necessarily the
    // one who typed this line, and a scene that labels a guest's words with the
    // owner's name puts words in somebody's mouth in the transcript itself.
    $me = $player > XERIC_PLAYER_FIRST
        ? xeric_player_name($db, $player, $t)
        : (trim((string)($t['user']['name'] ?? '')) ?: 'you');
    $where = xeric_player_where($t, $db, $player);

    $s['lines'][] = ['handle' => XERIC_WATCH_PLAYER, 'name' => $me, 'text' => $text];
    $s['player']  = [
        'present' => true,
        'where'   => $where,
        'place'   => $where !== null ? xeric_world_place_name($t, $where) : '',
        'name'    => $me,
    ];

    return ['next' => xeric_watch_next($s)['name'], 'place' => (string)$s['player']['place']];
}

/**
 * The close: the CLI's close, re-stated line for line. Diaries first (model
 * calls, each allowed to fail into a note — the scene happened, learning is
 * garnish), then ONE transaction: the event, each speaker's memories, the
 * trail under the inspector's own key. A scene with no spoken line closes to
 * NOTHING — "they talked" may not be written about a room where nobody did.
 *
 * $endpoint may be null (the model has gone away since the scene ran): both
 * diaries are forfeit with a note each, and the event still lands, because
 * refusing a lived scene over its bookkeeping is the tail wagging the dog.
 *
 * @throws RuntimeException only when the store itself fails, rolled back whole
 */
function xeric_watch_close(array $w, array $s, ?array $endpoint): array
{
    $t  = $w['template'];
    $db = $w['db'];
    $a  = (string)$s['a'];
    $b  = (string)$s['b'];

    if ((int)$s['spoken'] < 1) return ['empty' => true];

    $names     = (array)$s['names'];
    $room      = (array)$s['room'];
    $epoch     = (int)$s['epoch'];
    $now       = xeric_world_now($t, $epoch);
    $first     = (string)$s['first'];
    $protected = xeric_sweep_protected($t);

    $kept  = [];
    $notes = [];
    foreach ([$first, $first === $a ? $b : $a] as $me) {
        $other = $me === $a ? $b : $a;
        if ($endpoint === null) {
            $kept[$me] = [];
            $notes[]   = 'no model was attached at the close, so ' . $names[$me] . ' kept no diary of it';
            continue;
        }
        try {
            $kept[$me] = xeric_duet_extract($t, $db, $me, $other, (array)$s['lines'], $endpoint,
                $room, [$a, $b], $protected, $kept, ['timeout' => XERIC_PLAY_CHAT_TIMEOUT]);
        } catch (Throwable $e) {
            $kept[$me] = [];
            $notes[]   = 'could not harvest a diary for ' . $names[$me] . ', ' . $e->getMessage();
        }
    }

    // The last WORD is the last spoken line's: a walk-in at the end is the
    // player's, and the player is a voice in the room, not a seat at the table.
    $last = $first;
    foreach ((array)$s['lines'] as $l) {
        if ((string)$l['handle'] === $a || (string)$l['handle'] === $b) $last = (string)$l['handle'];
    }

    $material = [
        $a => xeric_duet_material($t, $db, $a, $b, $protected),
        $b => xeric_duet_material($t, $db, $b, $a, $protected),
    ];
    $title  = xeric_duet_title($names[$a], $names[$b]);
    $prose  = xeric_duet_prose($t, $names[$a], $names[$b], $room, $now);
    $player = (array)($s['player'] ?? []);

    $at = xeric_state_time();
    $db->beginTransaction();
    try {
        $eventId = xeric_event_add($db, $title, $epoch, (string)$room['where'], [$a, $b], $prose, $at, false);

        foreach ([$a, $b] as $me) {
            foreach ((array)($kept[$me] ?? []) as $text) {
                xeric_memory_add($db, $me, (string)$text, 'duet', [
                    'event_id' => $eventId,
                    'with'     => [$me === $a ? $b : $a],
                    'place'    => (string)$room['where'],
                ], $epoch, $at);
            }
        }

        xeric_world_state_set($db, 'why:event:' . $eventId, json_encode([
            'kind'        => 'duet',
            'why'         => $names[$a] . ' and ' . $names[$b] . ' were both at '
                           . ((string)$room['place_name'] !== '' ? (string)$room['place_name'] : 'the same place')
                           . ' (' . (string)$room['why'] . '); '
                           . xeric_duet_material_why($names, $material, $a, $b) . ' '
                           . $names[$first] . ' spoke first and ' . $names[$last] . ' had the last word.'
                           . (!empty($player['present'])
                               ? ' ' . (string)$player['name'] . ' walked in part way, and the next line answered them.'
                               : ''),
            'place'       => (string)($room['where'] ?? ''),
            'people'      => [$a, $b],
            'spoke_first' => $first,
            'last_word'   => $last,
            'turns'       => (int)$s['spoken'],
            'extended'    => (bool)$s['extended'],
            'minor_clamp' => (bool)$s['minor'],
            'rating'      => (string)$s['eff'],
            'watched'     => true,
            'player_present' => !empty($player['present']),
            'player_where'   => $player['where'] ?? null,
            'notes'       => $notes,
            'at'          => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $at);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('duet: could not store the close, ' . $e->getMessage(), 0, $e);
    }

    return [
        'lines'       => (array)$s['lines'],
        'event_id'    => $eventId,
        'title'       => $title,
        'prose'       => $prose,
        'place'       => (string)$room['where'],
        'place_name'  => (string)$room['place_name'],
        'memories'    => $kept,
        'spoke_first' => $first,
        'last_word'   => $last,
        'turns'       => (int)$s['spoken'],
        'notes'       => $notes,
    ];
}
