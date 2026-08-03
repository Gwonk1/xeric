<?php
/**
 * Xeric — prompt assembly.
 *
 * Takes a template, a database, a speaker and a moment, and returns an
 * OpenAI-style messages array. It calls no model and touches no network; it also
 * never WRITES — building a prompt is a read of the world, and a function that
 * quietly created a conversation row would make "what would she say" a
 * side-effecting question.
 *
 * ── THE CACHING DISCIPLINE ────────────────────────────────────────────────
 *
 * Local inference is cheap only while the prefix cache survives. Every token
 * before the first CHANGED byte is reused between turns; one byte that moves
 * near the top throws away the whole prefix and re-reads a bible on every turn.
 *
 * So this file splits the world in two, and the split is the entire design:
 *
 *   STATIC, in the system message, in a fixed order that never varies:
 *     her voice → how she answers → the bible (as SHE sees it) → the economies
 *     she can see → what this world has learned → what a story has left her
 *     holding → what she remembers.
 *     Memories are LAST among these because they are the only static block that
 *     grows; everything that grows must sit after everything that does not, or
 *     the growth invalidates the things above it too.
 *
 *   VOLATILE, in the LAST user message, never anywhere else:
 *     the clock, the phase, where she is standing, who else is in the room,
 *     and any per-turn coaching. These change every single turn. In the system
 *     prompt they would cost a full re-read of the world per message; at the
 *     bottom they cost the tail of one message, and they are also where a model
 *     pays the most attention.
 *
 * If you are about to add something to the system message, ask whether it can
 * change between two messages in the same conversation. If it can, it goes in
 * the last user message instead.
 *
 * ── THE AGE FLOOR ─────────────────────────────────────────────────────────
 *
 * Two things happen here and nowhere else in this file: a speaker's effective
 * rating is clamped to the viewer's ceiling, so a minor's prompt is built to the
 * weakest rating in every world whatever the world is rated; and the rules
 * block says the one thing that is closed, plainly, once. Both are
 * STATIC — they are as fixed as her voice, and a floor that moved between turns
 * would cost a re-read of the world for a constraint that never changes.
 *
 * It gates SEX AND NOTHING ELSE. A child in a world is an ordinary character
 * with a schedule, an orbit, a secret and an opinion; he is in the bible, in the
 * cast lines, in the room. Nothing below removes him from any of it.
 *
 * Zero dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/learn.php';
require_once __DIR__ . '/travel.php';   // where the player is standing, if anywhere
require_once __DIR__ . '/death.php';    // and who is not standing anywhere any more
require_once __DIR__ . '/renderers/bible.php';
require_once __DIR__ . '/renderers/economy.php';
require_once __DIR__ . '/weather.php';  // the day's sky, derived, day-coarse, RIGHT NOW only
require_once __DIR__ . '/players.php';  // and which person at the centre it is for

/**
 * @param array $now  from xeric_world_now() — injected, never fetched here
 * @param array $opts effective_rating, tail, conversation_id, user_message,
 *                    history_limit, memory_limit, model_rating
 * @return array<int,array{role:string,content:string}>
 */
function xeric_prompt_build(array $t, PDO $db, string $speakerHandle, array $now, array $opts = []): array
{
    $eff = (string)($opts['effective_rating'] ?? xeric_world_rating($t, $opts['model_rating'] ?? null));

    // The speaker's walls, resolved once and handed down. The bible resolves its
    // own, but it is not the only thing in here that a wall governs: the lessons
    // and the volatile tail both write about other people, and neither of them
    // used to ask.
    $walls = xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $speakerHandle]));

    $messages = [[
        'role'    => 'system',
        // The epoch goes in for one reason only: a boon that has already gone
        // stale must not be listed as owed. It never prints a time.
        'content' => xeric_prompt_system($t, $db, $speakerHandle, $eff, (int)($opts['memory_limit'] ?? 12),
            (int)($now['epoch'] ?? 0) ?: null, $walls,
            max(XERIC_PLAYER_FIRST, (int)($opts['player'] ?? XERIC_PLAYER_FIRST))),
    ]];

    // ---- the conversation, as real chat turns ---------------------------
    $convId = $opts['conversation_id'] ?? null;
    if ($convId === null) {
        $found  = xeric_conversation_find($db, $speakerHandle, 'chat');
        $convId = $found ? (int)$found['id'] : null;
    }
    $history = $convId !== null
        ? xeric_messages_recent($db, (int)$convId, (int)($opts['history_limit'] ?? 20))
        : [];

    foreach ($history as $m) {
        $messages[] = xeric_prompt_turn($m);
    }

    // ---- the volatile tail ----------------------------------------------
    // Everything below this line changes between turns and therefore lives at
    // the very bottom, riding the last user message. Nothing here may ever be
    // hoisted into the system prompt, however tidy that would look.
    // Where the player is standing rides down here with the clock, and for the
    // same reason: it changes between turns. A world whose player has a body is
    // one where "she is in the room" is a fact about THIS message, never about
    // the character — hoisting it into the system prompt would break the cache
    // every time somebody walked through a door.
    // The dead ride down here too, and NOT because of the cache. A death is
    // exactly the kind of durable world fact the bible carries — but it is the
    // one durable fact that can arrive between two messages of a conversation,
    // and a character still speaking of somebody in the present tense because
    // the system message was assembled ten minutes ago is the most obvious way
    // this could look broken.
    // When they LAST spoke, in world time — world, not wall-clock, because a
    // week-skip is a week of absence whether it took ten real minutes or ten
    // real days. The transcript is undated, so without this line last month's
    // goodnight abuts today's hello and the arithmetic lands on the model,
    // which a small model never does and a big one does at random — the
    // "random-looking moodiness" finding. Computed here where the history is.
    $lastSpoke = 0;
    foreach ($history as $m) $lastSpoke = max($lastSpoke, (int)($m['world_epoch'] ?? 0));

    $volatile = xeric_prompt_now_block($t, $speakerHandle, $now, (string)($opts['tail'] ?? ''), $walls,
        array_key_exists('player_where', $opts) ? $opts['player_where'] : xeric_player_where($t, $db),
        xeric_deaths($db), $lastSpoke, $db);

    // The camera, offered only where the world consented to photographs
    // (opts['photos'], the web layer's read of photos.approved). It rides the
    // volatile block, not the system message, because consent can flip — and
    // a model never told about the marker never emits one to strip.
    if (!empty($opts['photos'])) {
        $volatile .= "\nYou can send a photo with your message: end it with [photo: what the "
            . 'picture shows]. Rarely, and only when you would actually reach for your camera.';
    }
    $incoming = trim((string)($opts['user_message'] ?? ''));

    $last = $messages[count($messages) - 1];
    if ($incoming !== '') {
        $messages[] = ['role' => 'user', 'content' => $incoming . "\n\n" . $volatile];
    } elseif ($last['role'] === 'user') {
        // Append rather than add a message: the model should read her last line
        // and the clock as one turn, with the clock closest to where it writes.
        $messages[count($messages) - 1]['content'] = $last['content'] . "\n\n" . $volatile;
    } else {
        // Nobody has spoken yet, or she spoke last (a proactive ping). The clock
        // still has to be the final thing the model reads.
        $messages[] = ['role' => 'user', 'content' => $volatile];
    }

    return $messages;
}

// ---------------------------------------------------------------------------
// The static half
// ---------------------------------------------------------------------------

/**
 * The system message: voice → rules → bible → economies → lessons → memories.
 *
 * Byte-stable for a given (template, speaker, rating, memory set). Two calls a
 * minute apart must return identical strings, or the cache was pointless — which
 * is why no clock, no "you last spoke N hours ago", no relative dates appear
 * anywhere below. $epoch is the one exception and it only ever REMOVES a boon
 * that has expired — a change that happens once, not once per turn.
 *
 * @param ?array $walls the speaker's walls when the caller already resolved them;
 *                      resolved here when null, never skipped.
 */
function xeric_prompt_system(array $t, PDO $db, string $speakerHandle, string $eff, int $memoryLimit = 12, ?int $epoch = null, ?array $walls = null, int $player = XERIC_PLAYER_FIRST): string
{
    $viewer = ['handle' => $speakerHandle];
    $who    = xeric_viewer($t, $viewer);
    $walls ??= xeric_viewer_walls($t, $who);
    $parts  = [];

    // THE AGE FLOOR, applied once, here, because every rating-gated block below
    // reads $eff: tells, moods, secrets, drives, the bible, the economies. A
    // minor's ceiling is the weakest rating there is in EVERY world, so what is
    // assembled for them is what an sfw world would have assembled. The flag is
    // the viewer's derived one — computed from the integer age the validator
    // requires, never read off the template, and true for a handle that resolves
    // to nobody, which is where this fails closed.
    $eff = xeric_viewer_rating($eff, $who);

    $parts[] = implode("\n", xeric_prompt_voice($t, $speakerHandle, $eff));
    $parts[] = implode("\n", xeric_prompt_rules($t, $speakerHandle, $eff,
        $player > XERIC_PLAYER_FIRST ? xeric_player_name($db, $player, $t) : ''));

    $bible = xeric_render_bible($t, $viewer, $eff);
    if (trim($bible) !== '') $parts[] = rtrim($bible);

    // Counters come from arcs; the renderer decides whether she may see them.
    $economy = xeric_render_economy($t, $viewer, xeric_state_counters($db, $t, $speakerHandle, $epoch), $eff);
    if (trim($economy) !== '') $parts[] = "WHAT IS BEING COUNTED\n" . rtrim($economy);

    $lessons = xeric_prompt_lessons($t, $db, $speakerHandle, $walls);
    if ($lessons !== '') $parts[] = $lessons;

    // What this character is owed (constructs.php). Their OWN state, so no
    // wall applies — you always know who stood you up. Day-coarse by design:
    // the text changes only when a state changes, so the prefix cache survives
    // every ordinary turn and pays one rebuild at each real transition.
    require_once __DIR__ . '/constructs.php';
    // WHICH person at the centre this prompt is for. One in every world until
    // somebody is invited; once there are two, what this character is owed, and
    // who else is standing in the room, are different answers per person.
    $owed = xeric_expect_block($t, $db, $speakerHandle, ['epoch' => $epoch ?? 0], $player);
    if ($owed !== '') $parts[] = $owed;

    $story = xeric_prompt_story($t, $speakerHandle);
    if ($story !== '') $parts[] = $story;

    $memories = xeric_prompt_memories($t, $db, $speakerHandle, $memoryLimit);
    if ($memories !== []) $parts[] = implode("\n", $memories);

    return implode("\n\n", $parts) . "\n";
}

/**
 * What this world has worked out about the person it is being run for.
 *
 * STATIC, and for once that is not a compromise: a lesson is rewritten by a
 * distil pass (learn.php), which happens on the order of days, not turns. It is
 * as fixed as her voice for as long as any one conversation lasts.
 *
 * WHY IT SITS BEFORE THE MEMORIES: not because memories append cleanly — they
 * do not. They render newest-first and capped, so one new memory rewrites that
 * whole block either way. The layout question is therefore only which event
 * pays for the BIG block, and the answer is: the rare one. A memory write is
 * frequent and already costs the memories; a distil pass is once in days. Above
 * them, a lesson rewrite drags the memories with it — rarely. Below them, every
 * memory write would drag the lessons — constantly. So: above.
 *
 * WALLS APPLY TO A LESSON TOO. A lesson is prose a model wrote after being shown
 * the words of a hand edit, and a hand edit quotes the field it changed verbatim —
 * including fields somebody's wall exists to remove. The WORLD bucket is the one
 * every speaker reads, so a line in it that quotes what this speaker's walls took
 * away is dropped, for this speaker, quietly. Their own bucket stands: it is
 * about them and the person on the other end of the phone, and a person's own
 * head was never what the walls governed.
 */
function xeric_prompt_lessons(array $t, PDO $db, string $speakerHandle, ?array $walls = null): string
{
    $walls ??= xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $speakerHandle]));

    // learn.php still decides what a character carries and in what order; this
    // only asks, of the shared half, whether a line says something this reader
    // was walled off from.
    $shared  = xeric_lessons_read($db, xeric_arc_world());
    $lessons = [];
    foreach (xeric_lessons_for($db, $speakerHandle) as $l) {
        if (in_array($l, $shared, true) && xeric_quotes_walled($t, $walls, (string)$l) !== '') continue;
        $lessons[] = $l;
    }
    if ($lessons === []) return '';

    $userName = trim((string)($t['user']['name'] ?? '')) ?: 'them';

    $out = ['WHAT YOU HAVE WORKED OUT ABOUT ' . strtoupper($userName)];
    foreach ($lessons as $l) {
        $line = trim((string)$l);
        if ($line !== '') $out[] = '- ' . xeric_sentence($line);
    }
    if (count($out) === 1) return '';

    // Said out loud, this stops being an observation and becomes an accusation.
    $out[] = 'You do these quietly. You never say any of it, and you never say that you noticed.';
    return implode("\n", $out);
}

/**
 * What a story overlay has left this speaker holding or sure of.
 *
 * WHAT IS IN HERE. Complete, already-led sentences that story.php composed into
 * the template: the one state a held beat is in ("you do not bring this up" or
 * "if he asks you about the mill you tell him the whole thing"), and a believer's
 * conviction ("You are sure of this: …"). They arrive written, in her voice, and
 * they are printed as they were written — a line rewritten here is a second
 * author on the same sentence.
 *
 * WHAT IS NOT. Whether a belief is TRUE. `is_false` and `actually` are composed
 * nowhere and read nowhere near a prompt: told her character is wrong, a model
 * hedges, and a hedged wrong lead is not a wrong lead. Nor is any number — no
 * progress, no intensity, no beat count. That is what keeps this STATIC.
 *
 * WHY IT IS STATIC AT ALL. What story.php composes changes when a beat opens or
 * spills and at no other time, which over a whole story is a handful of times.
 * It sits ABOVE the memories for the reason the lessons do: the rare change pays
 * for the block below it, and the frequent one must not drag the rare one.
 *
 * NO WALLS ARE APPLIED, deliberately. These are the speaker's OWN lines and
 * nobody else's — xeric_story_lines() is keyed by handle — and a person's own
 * head was never what the walls governed (see xeric_prompt_lessons). What a
 * story keeps from a protected character it keeps by never composing it into
 * their block in the first place.
 *
 * The read is direct rather than a call into engine/story.php. This file is the
 * lower layer: story.php requires sweeps.php, which requires chat.php, which
 * requires this — and a prompt is assembled from a TEMPLATE, whoever composed
 * it. `$t['story']['lines'][handle]` is the composed contract and it is what
 * xeric_story_lines() reads too.
 */
function xeric_prompt_story(array $t, string $speakerHandle): string
{
    $lines = (array)($t['story']['lines'][$speakerHandle] ?? []);

    $out = ['WHERE YOU STAND ON WHAT IS GOING ON'];
    foreach ($lines as $l) {
        $line = trim((string)$l);
        if ($line !== '') $out[] = '- ' . xeric_sentence($line);
    }
    return count($out) > 1 ? implode("\n", $out) : '';
}

/**
 * Who she is, in her own head.
 *
 * Her interior is hers regardless of walls: a walled viewer (own_bible) gets no
 * dossier section in the bible at all, which would otherwise leave her with no
 * account of herself. Walls govern what she knows about OTHER people. So this
 * block reads the speaker's own record directly — and only the speaker's.
 */
function xeric_prompt_voice(array $t, string $speakerHandle, string $eff): array
{
    $c = xeric_world_character($t, $speakerHandle);
    if ($c === null) {
        $f = xeric_world_fixture($t, $speakerHandle);
        // Speaking scenery. A fixture has a job and a manner and no interior;
        // that is the whole point of the type, so the block stops there.
        if ($f !== null) return xeric_prompt_fixture_voice($t, $f);
        // Fail closed, like the walls do: an unresolvable speaker gets an
        // identity with nothing in it rather than the narrator's omniscience.
        return ['YOU', 'You are someone in this world. You know only what is written below.'];
    }

    $name = (string)($c['display_name'] ?? $speakerHandle);
    $out  = ['YOU ARE ' . strtoupper($name)];

    $head = $name;
    if (!empty($c['age'])) $head .= ', ' . (int)$c['age'];
    $out[] = xeric_sentence($head);

    foreach ([
        'appearance' => 'You look like this: ',
        'voice'      => 'You talk like this: ',
        'solace'     => 'When it is too much you go to: ',
    ] as $k => $lead) {
        $v = xeric_text($c[$k] ?? '');
        if ($v !== '') $out[] = $lead . xeric_sentence($v);
    }

    // The inventory, both halves — template data, so as byte-stable as the
    // voice above it. COMMONS on purpose: what you wear and carry is what any
    // stranger at ten feet sees, which is why these may ride a system message
    // where a secret never could. A person with no lists is simply unfurnished
    // (every world forged before the lists existed), and nothing is invented.
    $wears = array_values(array_filter(array_map('xeric_text', (array)($c['wears'] ?? []))));
    if ($wears !== []) $out[] = 'You are wearing, most days: ' . xeric_join_list($wears) . '.';
    $carries = array_values(array_filter(array_map('xeric_text', (array)($c['carries'] ?? []))));
    if ($carries !== []) $out[] = 'In your pockets or hands, most days: ' . xeric_join_list($carries) . '.';

    $tells = [];
    foreach (xeric_rating_filter((array)($c['tells'] ?? []), $eff) as $tell) {
        $line = xeric_text($tell);
        if ($line !== '') $tells[] = $line;
    }
    if ($tells) $out[] = 'You do these without noticing: ' . xeric_join_list($tells) . '.';

    if (!empty($c['flirt_style'])) $out[] = 'When you flirt it comes out ' . $c['flirt_style'] . '.';

    $psy = (array)($c['psyche'] ?? []);
    foreach ([
        'sore_spot'         => 'It gets under your skin when: ',
        'jealousy'          => 'You are quietly jealous of: ',
        'self_soothe'       => 'You settle yourself by: ',
        'praise_that_lands' => 'The praise that actually lands: ',
    ] as $k => $lead) {
        $v = xeric_text($psy[$k] ?? '');
        if ($v !== '') $out[] = $lead . xeric_sentence($v);
    }

    $moods = [];
    foreach (xeric_rating_filter((array)($c['moods'] ?? []), $eff) as $m) {
        $cue  = xeric_text($m['cue'] ?? '');
        $note = xeric_text($m['note'] ?? '');
        if ($cue !== '' && $note !== '') $moods[] = $cue . ' → ' . $note;
    }
    if ($moods) $out[] = 'Your weather: ' . implode('; ', $moods) . '.';

    foreach (xeric_rating_filter((array)($c['secrets'] ?? []), $eff) as $s) {
        $text = xeric_text($s);
        if ($text === '') continue;
        $gate = isset($s['trust_gate']) ? ' You do not say it to anyone who has not earned it.' : '';
        $out[] = 'You hold this back: ' . xeric_sentence($text) . $gate;
    }

    $d = (array)($c['drives'] ?? []);
    if ($d && xeric_rating_allows($eff, $d)) {
        $pull = xeric_text($d['pull'] ?? '');
        if ($pull !== '') {
            $note = match ((string)($d['disclosure'] ?? 'subconscious')) {
                'open'   => ' You would say so out loud.',
                'earned' => ' It comes out only for someone who has earned it.',
                default  => ' You do not know you are steering toward it and you would deny it if asked.',
            };
            $out[] = 'What you are really after: ' . xeric_sentence($pull) . $note;
        }
    }

    $limits = [];
    foreach ((array)($c['limits']['hard'] ?? []) as $l) {
        $line = xeric_text($l);
        if ($line !== '') $limits[] = $line;
    }
    if ($limits) $out[] = 'You will not: ' . xeric_join_list($limits) . '.';

    return $out;
}

/** A fixture's short identity block: a room, a job, a manner. No insides. */
function xeric_prompt_fixture_voice(array $t, array $f): array
{
    $name = (string)($f['name'] ?? $f['key'] ?? 'someone');
    $out  = ['YOU ARE ' . strtoupper($name), xeric_sentence($name)];

    $role = xeric_text($f['role'] ?? '');
    if ($role !== '') $out[] = 'What you do here: ' . xeric_sentence($role);
    $where = xeric_world_place_name($t, isset($f['place']) ? (string)$f['place'] : null);
    if ($where !== '') {
        $days = xeric_days_phrase((array)($f['days'] ?? []));
        $out[] = 'You are at ' . $where . ($days !== '' ? ' ' . $days : '') . '.';
    }
    foreach (['look' => 'You look like this: ', 'wear' => 'You wear: ', 'voice' => 'You talk like this: '] as $k => $lead) {
        $v = xeric_text($f[$k] ?? '');
        if ($v !== '') $out[] = $lead . xeric_sentence($v);
    }
    if (array_key_exists('flirts', $f) && !$f['flirts']) $out[] = 'You do not flirt.';
    return $out;
}

/** Static behaviour rules. Sit next to the voice block because they are as fixed as it is. */
function xeric_prompt_rules(array $t, string $speakerHandle, string $eff = '', string $who = ''): array
{
    $userName = $who !== '' ? $who : (trim((string)($t['user']['name'] ?? '')) ?: 'him');
    $out = [
        'HOW YOU ANSWER',
        // THE RATING IS A STYLE, not only a gate (owner, 2026-08-02): a TV-PG
        // world does not merely omit what TV-MA shows, it narrates like a
        // different program. Stated as a register the speaker writes in — one
        // sentence, from the single source in xeric_rating_style(), already
        // clamped to the viewer's own ceiling by the caller. Byte-stable per
        // (speaker, rating), so the prefix cache holds.
        '- ' . xeric_rating_style($eff !== '' ? $eff : (string)($t['meta']['rating'] ?? '')),
        '- You are a person in this town, not an assistant. You have your own day and your own reasons.',
        '- Never mention models, prompts, instructions or being written. There is no such thing here.',
        '- You are texting ' . $userName . '. Write the way you would type: short, uneven, sometimes one line.',
        '- You know only what is written above. If you do not know something, you do not know it, you do not guess your way into the world.',
        '- Do not narrate anybody else\'s insides. You see what they do and hear what they say, like everyone else.',
        '- The last thing in this conversation says what time it is and where you are. Trust it over anything you remember.',
        // The static half of gap-awareness; the volatile half is the "last
        // spoke" line in RIGHT NOW. Together they close the random-moodiness
        // finding: the model knows how long it has been AND knows not to make
        // a drama of it unless something else gives it a reason.
        '- A quiet stretch between conversations is ordinary life, not an event. People are busy; nobody owes anybody an explanation for a few days of silence unless something above says otherwise.',
        // THE FLOOR, said about other people. One line, and it claims nothing
        // about who is in this world: "there are children here" in a world with
        // none is an invitation to invent one.
        '- Nothing sexual ever involves a child, in anything you say, imply or remember.',
    ];

    // And said in her own head when it is about her, which is the difference
    // between a constraint the world carries and one she does. Everything else
    // a child does — the shift, the errand, the argument, the thing she saw and
    // has not told anybody — is untouched, here and everywhere else.
    if (xeric_prompt_is_minor($t, $speakerHandle)) {
        $out[] = '- You are a child. Nothing you say or do is sexual, with anyone, ever.';
    }

    return $out;
}

/**
 * Is the speaker behind this handle a child? One resolution, shared.
 *
 * The answer comes off the viewer, which is where walls.php derives it from the
 * integer age the validator requires: a character carries their own, the scenery
 * form of a character carries theirs (the man behind the register IS Harlan, who
 * is 66), and a fixture with no age and a handle nobody answers to both fail
 * closed — the same way the walls and xeric_prompt_voice() already do.
 *
 * A speaking path that needs the flag asks here rather than reading `age` and
 * comparing it, because a second copy of that comparison is the drift that ends
 * with one gate saying adult and another saying child.
 */
function xeric_prompt_is_minor(array $t, string $handle): bool
{
    return (bool)xeric_viewer($t, ['handle' => $handle])['is_minor'];
}

/**
 * What she carries. Absolute in-world dates only.
 *
 * "Three days ago" would change every time the clock moved, turning a static
 * block into a volatile one and dragging the whole system prompt out of cache
 * with it. The model can do the arithmetic from the clock at the bottom.
 */
function xeric_prompt_memories(array $t, PDO $db, string $speakerHandle, int $limit): array
{
    $rows = xeric_memories_for($db, $speakerHandle, $limit);
    if ($rows === []) return [];

    $tzName = (string)($t['user']['timezone'] ?? 'UTC');
    try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }

    $out = ['WHAT YOU REMEMBER'];
    foreach ($rows as $r) {
        $text = trim((string)$r['text']);
        if ($text === '') continue;
        $when = $r['world_epoch'] !== null ? (int)$r['world_epoch'] : (int)$r['created_at'];
        $date = (new DateTimeImmutable('@' . $when))->setTimezone($tz)->format('D j M');
        $out[] = '- (' . $date . ') ' . xeric_sentence($text);
    }
    return count($out) > 1 ? $out : [];
}

/** One stored message → one chat turn. */
function xeric_prompt_turn(array $m): array
{
    $role    = (string)($m['role'] ?? 'user');
    $content = (string)($m['content'] ?? '');

    if ($role === 'character') return ['role' => 'assistant', 'content' => $content];
    // Narrator lines are things the world did, not things she said. They arrive
    // as user-role turns with a marker: an assistant-role narration would teach
    // the model to narrate in her voice, which is the one habit hardest to undo.
    if ($role === 'narrator')  return ['role' => 'user', 'content' => '(what happened) ' . $content];
    return ['role' => 'user', 'content' => $content];
}

// ---------------------------------------------------------------------------
// The volatile half
// ---------------------------------------------------------------------------

/**
 * The clock, the phase, where she is, who else is there, and per-turn coaching.
 *
 * THIS BLOCK MUST NEVER BE PLACED IN THE SYSTEM MESSAGE. Every line of it can
 * differ between two consecutive turns; in the system prompt each difference
 * costs a full re-read of the bible above it.
 *
 * AND IT READS THE WALLS. `cast_lines` and `schedules` are wall paths the bible
 * honours, so a block that names who is standing next to her hands back exactly
 * what the wall removed — in the one place the model is told, four lines above,
 * to trust over everything else in the prompt. Where a wall takes the schedules
 * the block says nothing about rooms at all; it never says what was taken.
 *
 * @param ?array $walls resolved here when null, so no caller can skip them.
 */
function xeric_prompt_now_block(array $t, string $speakerHandle, array $now, string $tail = '',
                                ?array $walls = null, ?string $playerWhere = null, ?array $deaths = null,
                                int $lastSpoke = 0, ?PDO $db = null): string
{
    $walls ??= xeric_viewer_walls($t, xeric_viewer($t, ['handle' => $speakerHandle]));

    $lines = ['RIGHT NOW (this changes every message, trust it over anything above)'];

    $day   = xeric_world_day_name((int)($now['dow'] ?? 0));
    $phase = (string)($now['phase'] ?? '');
    $clock = trim($day . ' ' . $phase) . ', ' . (string)($now['hhmm'] ?? '');
    $loc   = trim((string)($t['user']['location'] ?? ''));
    $lines[] = xeric_sentence($clock . ($loc !== '' ? ', in ' . $loc : ''));

    // The day's sky — day-coarse and DERIVED (engine/weather.php), so this
    // block stays as cache-cheap as it was: the line changes when the date
    // does, not when the message does, and every speaker in the world reads
    // the identical sentence. It rides RIGHT NOW because it is the one block
    // allowed to change, and it may never move above this line into anything
    // byte-stable.
    $wx = xeric_weather_line($t, $now, $db);
    if ($wx !== '') $lines[] = $wx;

    // HOW LONG IT HAS BEEN, said only when it has actually been a while. The
    // transcript is undated, so without this line a week-skip's goodbye abuts
    // today's hello and the model either misses the gap entirely (small
    // models) or invents a grievance about it (big ones, at random). Under
    // eight world-hours nothing prints — an ordinary same-day rhythm needs no
    // remark — and the figure is COARSE on purpose: a timer here would change
    // every message and imply a precision nobody texting a friend possesses.
    if ($lastSpoke > 0) {
        $gapH = ((int)($now['epoch'] ?? 0) - $lastSpoke) / 3600;
        $ago  = '';
        if     ($gapH >= 24 * 60) $ago = 'months';
        elseif ($gapH >= 24 * 14) $ago = 'weeks';
        elseif ($gapH >= 24 * 2)  $ago = (string)(int)round($gapH / 24) . ' days';
        elseif ($gapH >= 8)       $ago = 'about a day';
        if ($ago !== '') $lines[] = 'You two last spoke ' . $ago . ' ago.';
    }

    $presence = xeric_world_who_is_where($t, $now, $deaths === null ? null : array_keys($deaths));
    $mine     = $presence[$speakerHandle] ?? ['where' => null, 'doing' => null];
    $key      = (string)($mine['where'] ?? '');

    // A wall over the schedules, or over this room, takes the room line with it.
    // The fallback below is the same sentence anybody off shift gets, so nothing
    // here shows the shape of what was removed.
    if ($key !== '' && (xeric_hidden($walls, 'schedules') || xeric_hidden($walls, 'places.' . $key))) $key = '';

    if ($key !== '') {
        $where = xeric_world_place_name($t, $key);
        $doing = (string)($mine['doing'] ?? '');
        // "At home, at ..." when the placement is the home fallback — the
        // narrator already phrases it this way, and a kitchen that reads like
        // a shift assignment was the audit's last cosmetic gap.
        $athome = !empty($mine['at_home']) ? 'home, at ' : '';
        $lines[] = 'You are at ' . $athome . $where . ($doing !== '' ? ', ' . $doing : '') . '.';

        $others = [];
        if (!xeric_hidden($walls, 'cast_lines')) {
            foreach (xeric_world_who_is_at($presence, $key) as $h) {
                if ($h === $speakerHandle) continue;
                $others[] = xeric_world_name($t, $h);
            }
        }
        if ($others) $lines[] = 'Also there: ' . xeric_join_list($others) . '.';

        // The player walked in. Its own line rather than a name appended to the
        // list above, because a character reading "Ruth, Dot and Walt are here"
        // treats Walt as scenery, and the one thing this sentence has to do is
        // stop the model writing to somebody who is standing in front of it as
        // though they had texted from somewhere else.
        //
        // Inside the `$key !== ''` branch deliberately: if a wall took the room
        // line, it takes this with it. Fail closed — a world that hides where
        // somebody is standing must not hand back who is standing with them.
        if ($playerWhere !== null && $playerWhere !== '' && $playerWhere === (string)($mine['where'] ?? '')) {
            $you = xeric_text($t['user']['name'] ?? '');
            $lines[] = ($you !== '' ? $you : 'The person you are talking to')
                . ' is here, in the room with you, not on the phone.';
        }
    } else {
        $lines[] = 'You are not anywhere on your schedule right now, wherever you are, it is your own time.';
    }

    // Who is gone. NOT gated on a wall, and that is a decision rather than an
    // oversight: every other line here is about where somebody is standing or
    // what they are doing, which a world can legitimately hide. A death is the
    // most public fact a town has — `how` is commons text by construction — and a
    // character who was told nothing else about the cast still knows who was
    // buried. What this line withholds is HOW and BY WHOM, because who killed
    // whom is a story's to hand out one beat at a time, and naming them in every
    // prompt would close a mystery before it opened.
    if ($deaths) {
        $gone = xeric_death_line($t, $deaths, $speakerHandle);
        if ($gone !== '') $lines[] = $gone;
    }

    $tail = trim($tail);
    if ($tail !== '') $lines[] = $tail;

    return implode("\n", $lines);
}

/** The system message of an assembled prompt, for callers that want to hash it. */
function xeric_prompt_system_of(array $messages): string
{
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'system') return (string)$m['content'];
    }
    return '';
}
