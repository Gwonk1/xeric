<?php
/**
 * Xeric — the entrance. An OUT character comes into the story.
 *
 * OUT is a casting state (world.php's validator, 2026-08-02): the character
 * exists in the template but is unstaged — never swept, never proactive,
 * unplaced — until something brings them in. Until this file, the only thing
 * that could was a hand edit to the JSON, and a hand edit is every safety
 * skipped at once: no validation, no prev-copy to undo to, and a world whose
 * newest inhabitant appeared between two page loads with nobody noticing.
 *
 * That last one is the real bug. THE WORLD MUST REMEMBER THAT SOMEBODY
 * ENTERED. Every prompt reads the events table as what has happened, so a
 * character flipped in silently is a character the world treats as having
 * always been here — and the first person to mention "when you got to town"
 * gets confidently contradicted by the town. So the flip and the memory of it
 * are one operation: the template changes AND an event lands, in the same
 * breath, or neither does.
 *
 * The failure modes are not symmetric, and the transaction is shaped by which
 * is worse. In-the-story-but-unremembered is the silent-appearance bug again;
 * remembered-but-still-out is a town recalling an arrival that never happened
 * — worse, because no later action heals it. So the event is written inside a
 * transaction and the template is saved before the commit: a file that will
 * not write takes the memory of the entrance down with it, and the world
 * stays exactly as it was.
 *
 * THE SAME SAFETY AS THE REVIEW DOOR, NOT THE SAME CODE. The review screen
 * keeps the last good copy before every overwrite (world-template.prev.json)
 * and that discipline holds here — but the engine does not reach into
 * forge/web for it. The prev-copy is two lines; importing a web library into
 * the engine to avoid retyping them would be the coupling the layering
 * exists to prevent.
 *
 * A world that has never been OPENED has no database, no lived past, and
 * therefore no room that noticed anybody arrive. Passing $db as null says so:
 * the flip still validates and saves through the same door, and no event is
 * written, because when the world finally opens the character is simply among
 * the people who were always going to be there. The caller decides, because
 * the caller knows whether world.db exists — and must not create it to ask,
 * since the FILE EXISTING is what "this world has been lived in" means to
 * everything that reads the shelf.
 *
 * Zero web dependencies. PHP 8.2+.
 */

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/clock.php';    // the entrance lands at the WORLD's hour, not the wall's

/**
 * Bring one OUT character into the story.
 *
 * @param string   $dir    the world directory holding world-template.json
 * @param PDO|null $db     the world's database, or null for a world that has
 *                         never been opened (no past, so no entry event)
 * @param string   $handle who is entering
 * @param int|null $epoch  the world moment the entrance lands at. Null reads
 *                         the world's own clock — offset and all, so a
 *                         fast-forwarded world does not get an arrival stamped
 *                         three days into its past. Tests inject one.
 * @return array{entered:bool,event_id:?int,handle:string,name:string,note:string}
 *         `entered` is false when they were never out — a double-press is
 *         ordinary, and a second "turned up today" row would be a town
 *         remembering the same arrival twice.
 * @throws RuntimeException on an unknown handle, a template that will not
 *         validate with the flip in it, or a save that fails — in every case
 *         nothing has changed, on disk or in the database.
 */
function xeric_enter(string $dir, ?PDO $db, string $handle, ?int $epoch = null): array
{
    $dir  = rtrim($dir, '/');
    $live = $dir . '/world-template.json';
    $t    = xeric_world_load($live);

    $at = null;
    foreach ((array)($t['cast']['characters'] ?? []) as $i => $c) {
        if ((string)($c['handle'] ?? '') === $handle) { $at = $i; break; }
    }
    if ($at === null) {
        throw new RuntimeException("xeric: enter: '$handle' is not a character in this world");
    }

    $name = xeric_world_name($t, $handle);
    if (empty($t['cast']['characters'][$at]['out'])) {
        return ['entered' => false, 'event_id' => null, 'handle' => $handle, 'name' => $name,
                'note' => $name . ' is already in the story'];
    }

    // Set false rather than unset: the field staying behind is the record that
    // this person was staged late, which a template diff can read and a
    // missing key cannot.
    $t['cast']['characters'][$at]['out'] = false;

    // Validated with the flip IN it, before anything is written anywhere. The
    // load above proved the world on disk; this proves the world being made.
    xeric_world_validate($t, basename($live));

    $save = function () use ($t, $dir, $live): void {
        // The review door's prev-copy, reimplemented: last good copy first,
        // then an atomic swap, so a crash mid-write can never leave half a
        // template where a world used to be.
        if (is_file($live)) @copy($live, $dir . '/world-template.prev.json');
        $json = json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $tmp  = $live . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('the template could not be written back to disk');
        }
        if (!@rename($tmp, $live)) {
            @unlink($tmp);
            throw new RuntimeException('the template could not be written back to disk');
        }
    };

    if ($db === null) {
        $save();
        return ['entered' => true, 'event_id' => null, 'handle' => $handle, 'name' => $name,
                'note' => $name . ' is in the story now; this world has not been opened yet, '
                        . 'so there is no past for the entrance to land in'];
    }

    $when  = $epoch ?? (int)xeric_clock_now($db, $t)['epoch'];
    $world = trim((string)($t['meta']['name'] ?? '')) ?: 'town';
    // Where the entrance is felt: their own doorstep when the world gave them
    // one, and nowhere in particular otherwise — never a guessed public room.
    $place = xeric_world_home_of($t, $handle);

    $db->beginTransaction();
    try {
        $eventId = xeric_event_add($db, $name . ' turned up today', $when, $place, [$handle],
            $name . ' turned up in ' . $world . ' today. Nobody made a ceremony of it, and none was '
            . 'needed: by evening the town counted ' . $name . ' among the people who are here, and '
            . 'talk made room accordingly.');

        // Inside the transaction on purpose — see the header. A file that will
        // not save rolls the event back, and the worse failure cannot happen.
        $save();

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('xeric: enter: nothing changed, ' . $e->getMessage(), 0, $e);
    }

    return ['entered' => true, 'event_id' => $eventId, 'handle' => $handle, 'name' => $name,
            'note' => $name . ' is in the story now'];
}
