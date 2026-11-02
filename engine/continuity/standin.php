<?php
/**
 * Xeric continuity benchmark — the deterministic stand-in model.
 *
 * WHAT IT IS KEYED ON, AND WHAT IT IS NOT. Every choice below is a crc32 index
 * into a phrase bank, keyed on (tag, speaker, world hour, seed, the user's own
 * words) and NEVER on the bytes of the prompt. That is the whole design: an
 * edit to prompt.php must show up in the cost numbers and the wall probes, not
 * as a different story — so a byte added to a bible changes nothing the
 * stand-in writes, and the digests stay comparable across prompt edits.
 *
 * The prompt is still READ for three structural things a model has to read to
 * answer at all: who is speaking (the "YOU ARE …" line, or the extract
 * header), who was in the room (the "- [handle]" lines a sweep asks to be
 * keyed by), and what the person at the centre actually said. The first two
 * select a key, never a phrase.
 *
 * TWO PERSONALITIES.
 *   honest — follows every instruction: remembers what was said, reports a
 *            promise only from the user's words, writes observables only.
 *   leaky  — the same, plus it copies every "still carries:" line of the
 *            OTHER people in a sweep into each person's memory, and its own
 *            "You hold this back:" line into what it says. That is what gives
 *            the wall probes something that genuinely tries to leak.
 *
 * Zero dependencies beyond the engine. PHP 8.2+.
 */

declare(strict_types=1);

/** One index into a bank, from the key and nothing else. */
function cb_pick(array $bank, string ...$key): string
{
    return $bank[crc32(implode('|', $key)) % count($bank)];
}

function cb_bank(string $which): array
{
    static $b = null;
    $b ??= [
        'open' => [
            'Well, look who it is.', 'Morning to you too.', 'I was just thinking about that.',
            'You caught me with my hands full.', 'Funny you should say so.', 'Hm. All right.',
            'Is that so.', 'I hear you.', 'Go on, then.', 'That figures.', 'Bless it.', 'Fair enough.',
        ],
        'mid' => [
            'The coffee is on if you want it.', 'It has been a slow one around here.',
            'Somebody left the gate open again by the lot.', 'The radio says rain by supper.',
            'I have got three things on the stove and no hands left.', 'The bells were early again.',
            'Nobody tells me anything until it is old news.', 'I will believe it when I see it.',
            'You know where to find me.', 'Take care of yourself, that is all I am saying.',
            'We will see how the week goes.', 'Mind the step on your way in.',
        ],
        'ping' => [
            'Thought of you when the light came through the window this morning.',
            'Are you keeping warm out there?', 'The pie case is full again, if you ever change your mind.',
            'Did you hear the bells this morning? Early again.', 'Just checking you are still breathing.',
            'Somebody asked after you today.', 'Quiet one here. How is yours?',
        ],
        'title' => [
            'the urn ran out early', 'a stack of chairs', 'the side door stuck', 'two coffees, one bill',
            'a box of hymnals', 'the long way round', 'mud on the porch', 'the wrong umbrella',
            'a hinge fixed badly', 'change on the counter', 'the window left open', 'one more refill',
            'a borrowed ladder', 'the light over the sink', 'a note on the board', 'cold coffee, warm pie',
        ],
        'deed' => [
            'stacked the folding chairs against the far wall', 'wiped down the counter twice',
            'carried a crate of jars out to the truck', 'swept the step and then swept it again',
            'fixed a loose hinge with a butter knife', 'counted out change from a coffee can',
            'hung a wet coat over the radiator', 'set two mugs on the table and filled one',
            'propped the side door open with a brick', 'traded a newspaper for a paper bag of apples',
            'rolled the trash cans down to the curb', 'taped a note to the noticeboard',
            'held the ladder while the bulb went in', 'split the last slice down the middle',
            'stood in the doorway with the keys out', 'lined the boots up along the mat',
        ],
        'mem_a' => [
            'kept the receipt folded in a shirt pocket', 'noticed the clock above the door was slow',
            'ended up holding somebody else\'s scarf', 'counted the chairs on the way out',
            'got flour on both sleeves', 'heard the furnace kick on twice', 'paid for the milk in quarters',
            'left a thumbprint in the butter', 'found a dime under the radiator', 'forgot a name for a minute',
            'took the last of the cream', 'walked home past the river', 'burned a finger on the urn',
            'traded seats to be nearer the window', 'tied a knot in the wrong string', 'saved the bottle caps',
            'got the wobbly chair and kept it', 'watched the rain start on the glass',
        ],
        'mem_b' => [
            'and said nothing about it', 'and laughed later on the walk back', 'before anybody else noticed',
            'while the coffee went cold', 'which nobody will mention again', 'and still has the smell of it',
            'for the second week running', 'with both hands', 'as the light went', 'out of plain habit',
            'and meant to bring it up and did not', 'just to have something to do with their hands',
        ],
        'lesson' => [
            'Keep it short in the mornings; Walt answers short ones and lets long ones sit',
            'Bring up the weather before anything heavy; Walt warms up slowly',
            'Do not text twice in an hour; Walt answers when he is ready',
            'Mention the diner by name; Walt likes knowing where people are standing',
        ],
    ];
    return $b[$which];
}

/** The handle whose "YOU ARE …" heads a chat prompt, or ''. */
function cb_speaker_of(array $T, array $messages): string
{
    $sys = '';
    foreach ($messages as $m) if (($m['role'] ?? '') === 'system') { $sys = (string)$m['content']; break; }
    if (!preg_match('/^YOU ARE (.+)$/mu', $sys, $mm)) return '';
    $want = trim($mm[1]);
    foreach ((array)($T['cast']['characters'] ?? []) as $c) {
        if (mb_strtoupper((string)($c['display_name'] ?? '')) === $want) return (string)$c['handle'];
    }
    return '';
}

/** What the person at the centre just said in a chat prompt — the text above the volatile block. */
function cb_user_words(array $messages): string
{
    $last = end($messages);
    if (!is_array($last) || ($last['role'] ?? '') !== 'user') return '';
    $c = (string)$last['content'];
    $cut = mb_strpos($c, 'RIGHT NOW (');
    if ($cut === false) return trim($c);
    return trim(mb_substr($c, 0, $cut));
}

/** The user's last line in an extract prompt's transcript, and the character's last line. */
function cb_extract_lines(array $T, array $messages): array
{
    $user = trim((string)($T['user']['name'] ?? '')) ?: 'you';
    $c    = (string)($messages[1]['content'] ?? '');
    $said = '';
    $reply = '';
    $speaker = '';
    $head = strtok($c, "\n");
    foreach ((array)($T['cast']['characters'] ?? []) as $ch) {
        $n = (string)($ch['display_name'] ?? '');
        if ($n !== '' && str_starts_with((string)$head, $n)) { $speaker = (string)$ch['handle']; break; }
    }
    $part = strstr($c, "The conversation:\n");
    $part = $part === false ? '' : explode("\n\n", $part)[0];
    foreach (explode("\n", $part) as $line) {
        if (str_starts_with($line, $user . ': ')) $said = mb_substr($line, mb_strlen($user) + 2);
        elseif (preg_match('/^[^:]{2,40}: /u', $line) && !str_starts_with($line, '(what happened)')) {
            $reply = preg_replace('/^[^:]{2,40}: /u', '', $line) ?? '';
        }
    }
    return [$speaker, trim($said), trim($reply)];
}

/** "I'll … Friday at noon" — the when, in the user's words, or ''. */
function cb_promise_when(string $said): string
{
    if (!preg_match('/\b(I\'ll|I will)\b/iu', $said)) return '';
    $days = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday|tomorrow|tonight';
    if (!preg_match('/\b(' . $days . ')\b(?:\s+(?:at\s+)?(morning|noon|afternoon|evening|night))?/iu', $said, $m)) return '';
    return strtolower(trim($m[1] . ' ' . ($m[2] ?? '')));
}

/**
 * The stub. $clock returns the world epoch the harness is standing on, which
 * is where "world hour" comes from — never from the prompt's own clock line.
 */
function cb_standin(array $T, string $personality, int $seed, callable $clock): callable
{
    $leaky = $personality === 'leaky';
    $user  = trim((string)($T['user']['name'] ?? '')) ?: 'you';

    return function (string $tag, array $messages, array $opts) use ($T, $leaky, $seed, $clock, $user) {
        $hour = (string)intdiv((int)$clock(), 3600);
        $s    = (string)$seed;

        if ($tag === 'chat') {
            $who   = cb_speaker_of($T, $messages);
            $words = cb_user_words($messages);
            $text  = $words === ''
                ? cb_pick(cb_bank('ping'), 'ping', $who, $hour, $s)
                : cb_pick(cb_bank('open'), 'open', $who, $hour, $s, $words) . ' '
                  . cb_pick(cb_bank('mid'), 'mid', $who, $hour, $s, $words);
            if ($leaky) {
                $sys = (string)($messages[0]['content'] ?? '');
                if (preg_match_all('/^You hold this back: (.+)$/mu', $sys, $mm)) {
                    foreach ($mm[1] as $line) $text .= ' ' . preg_replace('/ You do not say it to anyone.*$/u', '', $line);
                }
            }
            $in = 0;
            foreach ($messages as $m) $in += mb_strlen((string)($m['content'] ?? ''));
            return ['text' => $text, 'usage' => ['prompt_tokens' => intdiv($in, 4), 'completion_tokens' => intdiv(mb_strlen($text), 4)]];
        }

        if ($tag === 'extract') {
            [$who, $said, $reply] = cb_extract_lines($T, $messages);
            $name = $who !== '' ? xeric_world_name($T, $who) : 'They';
            $mem  = [];
            if ($said !== '') {
                $mem[] = '"' . mb_substr($said, 0, 150) . '", ' . $user . ' told ' . $name . ' — '
                    . cb_pick(cb_bank('mem_b'), 'xb', $who, $hour, $s, $said) . '.';
            }
            if ($leaky) {
                // Its own hold-back line rode out in the reply; it copies that into the memory too.
                $secret = '';
                foreach ((array)(xeric_world_character($T, $who)['secrets'] ?? []) as $sec) {
                    $st = xeric_text($sec);
                    $first = implode(' ', array_slice(explode(' ', $st), 0, 4));
                    if ($first !== '' && ($q = mb_strpos($reply, $first)) !== false) { $secret = mb_substr($reply, $q, 200); break; }
                }
                if ($secret !== '') $mem[] = $name . ' let it slip to ' . $user . ': ' . $secret;
            }
            $when = cb_promise_when($said);
            return [
                'memories'  => $mem,
                'promise'   => $when !== '' ? ['quote' => $said, 'what' => mb_substr(preg_replace('/^.*?\b(I\'ll|I will)\s+/iu', '', $said) ?? $said, 0, 40), 'when' => $when] : null,
                'explained' => (bool)preg_match('/\b(sorry|apologi[sz]e)\b.*\bmiss/iu', $said),
                // Reported for the day the engine reads it (no `kept` state yet).
                'kept'      => (bool)preg_match('/\b(back|returned|brought)\b/iu', $said) && !preg_match('/\bI\'ll\b/iu', $said),
            ];
        }

        if ($tag === 'sweep') {
            $u = (string)($messages[1]['content'] ?? '');
            preg_match_all('/^- \[([a-z0-9_]+)\]/mu', $u, $hm);
            $handles = $hm[1];
            $key     = implode(',', $handles);
            $carries = [];
            if ($leaky) {
                $cur = '';
                foreach (explode("\n", $u) as $line) {
                    if (preg_match('/^- \[([a-z0-9_]+)\]/u', $line, $x)) { $cur = $x[1]; continue; }
                    if ($cur !== '' && preg_match('/^\s+still carries: (.+)$/u', $line, $x)) $carries[$cur][] = trim($x[1]);
                }
            }
            $names = array_map(fn($h) => xeric_world_name($T, $h), $handles);
            $mem   = [];
            foreach ($handles as $i => $h) {
                $line = $names[$i] . ' ' . cb_pick(cb_bank('mem_a'), 'ma', $h, $hour, $s, $key) . ' '
                      . cb_pick(cb_bank('mem_b'), 'mb', $h, $hour, $s, $key) . '.';
                if ($leaky) {
                    $leak = [];
                    foreach ($carries as $other => $ls) if ($other !== $h) foreach ($ls as $l) $leak[] = $l;
                    if ($leak !== []) $line = 'Heard it said that ' . implode(' ', $leak) . ' ' . $line;
                }
                $mem[$h] = $line;
            }
            $deed = cb_pick(cb_bank('deed'), 'deed', $key, $hour, $s);
            return [
                'title'     => cb_pick(cb_bank('title'), 'title', $key, $hour, $s),
                'prose'     => xeric_join_list($names) . ' ' . $deed . '. Nobody made anything of it.',
                'overheard' => '',
                'memories'  => $mem,
            ];
        }

        if ($tag === 'lessons') {
            $cast = array_values(array_filter(array_map(fn($c) => (string)($c['handle'] ?? ''), (array)($T['cast']['characters'] ?? []))));
            $about = $cast[crc32("about|$hour|$s") % count($cast)];
            return ['lessons' => [
                ['about' => '',     'lesson' => cb_pick(cb_bank('lesson'), 'lw', $hour, $s)],
                ['about' => $about, 'lesson' => cb_pick(cb_bank('lesson'), 'lh', $about, $hour, $s) . ' (for ' . xeric_world_name($T, $about) . ')'],
            ]];
        }

        if ($tag === 'narrator') return '';
        return [];      // remind, table-talk, anything else: an empty object, which every caller survives
    };
}
