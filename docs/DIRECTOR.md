# The Director

The Director changes the set, never the performance. It can put two people in
the same room. It cannot decide what they say there. Code: `engine/director.php`.
Tests: `engine/tests/director-test.php`.

Left alone, free will produces drift: everyone keeps doing their own plausible
thing forever. The Director pushes back with one power, presence. It can
stage two or three people at one place for up to two hours. Nothing else is
touched: not the kind of hour, not the prompt, not what anybody knows. The
sweep's groups form out of presence the way they always do, so two people in
one room become a group, and the hour that follows belongs to them.

## A staging

One row in `director_stagings`. director.php creates this table with
`CREATE TABLE IF NOT EXISTS`, the first time anything is staged.

| field  | meaning |
|--------|---------|
| who    | 2–3 handles |
| where  | a place key |
| from/to | world epochs, half-open, at most 7200 s apart |
| why    | a short reason for the inspector ("they have not been in a room together for nine days") |
| source | `narrator:<n>`, `apart`, `player` or `owner` |
| state  | planned → live → done, or cancelled |

The clock decides whether a staging is in force, not the stored state. A
staging that was cancelled partway through still holds for the part of its
window before the cancel, so when history is read back it agrees with what
happened.

## Presence

`xeric_world_who_is_where($t, $now, $dead, $db)` lays the stagings over the
schedule's answer. It reads them from `$db` when one is passed. When none is
passed, it reads them from a template that `xeric_director_dress($T, $db)`
carried them in on. This second route exists because the heart hands its sweep
a dressed template, and sweeps.php takes no database. A staged row looks like
`{where, doing, staged: true, staging: id}`. The `doing` text comes from what
can be seen about the place: "waiting on a coffee" where it serves, "waiting
on a drink" where it pours, "stopping by" at a home, "walking past" anywhere
else. It never carries a name, a pronoun or a reason. `xeric_travel_map` passes
its database, so a staged person appears in the map's `who` with
`staged: true`. The player who walks in finds the encounter with no change to
the viewport.

The refusals are checked when a staging is made and again on every read:

- **The dead** are absent from presence, so they can never be moved.
- **An unmissable shift.** A week block can say `unmissable: true` or `false`.
  An unmarked block counts as unmissable when its place has kind `work`, or
  when it is a non-home place that lists the person, or a `same_as` fixture who
  is them, as a resident. The place you are usually found is your job.
- **A minor in an adult room.** A minor is never staged into a place whose
  `rating_min` is above the floor, that serves alcohol, or whose kind is `bar`
  or `club`.
- **Closed places.** A place that is shut seats nobody.

A staging that can no longer seat two people seats nobody. Moving one person
alone would be a schedule edit with no purpose.

## Rules (`director.rules`, default none)

The heart calls `xeric_director_tick($T, $db, $now)` once per world-hour,
after the pause check and before the hours. It needs no model. A rule proposes
people and places. The slot finder then takes the next whole hour at which the
place is open, it is not quiet hours, everybody is free or already there, and
at least one person would actually have to move.

- **narrator**: a live intent (read with `xeric_narrator_leans(...,
  settle: false)`, or from the `intent:<n>` rows in world_state when
  narrator.php is not loaded) that names two or more people, for any kind
  except `glimpse`. Each intent gets one staging. The intent's place is used
  if it has one; otherwise a shared haunt.
- **apart**: a pair in one orbit with no event together for `apart_days`. The
  record starts at the first event, so an empty events table proposes nothing.
  The staging goes to a shared haunt: a circle's hangout, then the places in
  their weeks.
- **player**: the gap between what two people think of the player
  (`xeric_trust_of`) has grown since a reference reading, which is refreshed
  daily, and is now `player_gap` or more. They are staged at the player's
  workplace, or failing that wherever the player is standing.

Every staging, whatever its source, is limited to `per_day` per world-day, and
no person may be in two stagings that overlap.

## Off by default

With no rules, the tick returns early. No table is created, no world_state key
is written, and `xeric_director_dress` returns the template unchanged. The
continuity probe `presence.director_off` checks this on every benchmark run.

## Seams

- `xeric_director_why($db, $now)` returns `{live, planned, recent}` stagings
  with their reasons. It is the source for the inspector's section, which
  belongs to the narrator agent. `why.php` has a comment marking the spot.
- `xeric_director_cancel($db, $id, $epoch)` is the owner's cancel. The web
  surface belongs to the inspector.
- `xeric_director_stage($T, $db, [...], $epoch)` is the owner's own staging.
  It throws on a refusal and names the reason.

## Not yet

Readers that call `who_is_where` without a database still see the plain
schedule: the prompt's room line, the play view's cast panel, room.php and
scene.php. tick-worker's skip also does not dress its template. One line in
`xeric_play_open` (`$T = xeric_director_dress($T, $db)`) would make all of
them agree.
