# More than one person in a xeric

A xeric was built for one person at the centre. This is what it means now that
there can be more than one, what each of them has, what they share, and what
is still to come. The milestone is FUTURE.md M4, "Together".

## What a player is

A **player** is a row in the world's roster (`engine/players.php`): an id, a
name, pronouns. The person who forged the world is player 1. Until anybody
else arrives, the roster is not even written down — the template's `user` *is*
player 1 — so every world that existed before multiplayer is still exactly
the world it was.

Everything per-person is keyed by the same suffix rule: **the first player
keeps the bare key, everybody after them gets `.p<n>`**. `work.wages` is the
first person's purse, `work.wages.p2` the second's; `trust` is what Ruth
thinks of the first person, `trust.p2` of the second. No migration has ever
been run to make room for a second person, and none ever will be. Ids are a
high-water mark and are never reused: somebody who leaves takes their number
with them, and the next person through the door is somebody new.

A world seats at most six. Past that it is a lobby, not a world.

## How somebody gets in: link, join, and a copy

There are three ways a second device can come to a world, and they are three
different things.

- **Link** (`link.php`) is *the same person on another device* — the owner's
  headset, their phone app. It mints a device token for the owner's own
  session. A linked device is player 1.
- **Join** (`join.php`, from the invite's QR code under `--lan`) is *a new
  person*. Scan, type your name, and you are a new row in the roster, playing
  the owner's real `world.db` — not a copy, because being in the same evening
  is the point of being invited. `xeric_session_player()` remembers which
  player this session is in which world.
- **A copy** is what a stranger gets on a shared host: opening somebody else's
  world from the shelf forks it into the visitor's own session. They are
  player 1 of their copy, and nothing they do reaches the owner's evening.
  They never see the owner, the guests, or each other.

## A body each

Every player has their own position. Player 1's is the `player.where` /
`player.where_since` row every world already has; player n's is
`player.where.p<n>` / `player.where_since.p<n>`. In a world with company, a
move also records where the body came from (`player.where_from[.p<n>]`), the
one fact another player's doorway needs to say somebody got up and left. A
world of one never writes that row.

Which body a request is about is **the current player**. The engine never
reads a session: `xeric_player_current()` is a seam, the web layer installs a
resolver for it (`forge/web/play-map.php`, from `xeric_session_player`), and
everything else — the CLI, the tests, the heartbeat — gets player 1. Every
position reader takes an optional player id and defaults to the current one.

The read model shows the others without learning a new shape:

- **The map** (`xeric_travel_map`): `you` is the current player, named off
  the roster. The other players stand in their place's `who` as
  `{handle: "p<n>", name, player: true}` — a name and nothing else, because a
  player has no appearance or errand in the template and the engine does not
  invent one for a real person. `players` at the top level lists every body.
  Drawn, another player is a broken ring in the accent colour with their
  initials, whose tooltip says "another player"; listed, their name is
  followed by "(another player)".
- **The look** (`xeric_scene_brief`): other players present are in `who`
  with `player: true`, a name and a bearing. A second human in the room is a
  different room, so the fingerprint includes them; with nobody else there
  the key is absent and the fingerprint is exactly what it was. Their tag in
  the view is focusable and opens nothing — talking to a person who is
  standing beside you is the room's business, not a phone's.
- **Comings and goings**: another player walking in or out is a beat, like
  anybody — "Ann Price comes in, shaking the rain off."

Players are **never in presence**. `xeric_world_who_is_where()` is the cast
and stays the cast: the sweeps, the prompt's room line and everything a model
is handed about who is in the diner are about characters.

A world of one produces the map, the brief and the fingerprint it produced
before any of this existed, to the byte; `engine/tests/players-test.php`
holds the one-body engine's hashes and checks them.

## Time is shared

There is one clock. A trip moves the current player's body only, and burns
its minutes on the world's clock — so two people walking around one world
spend one clock between them. A twenty-minute drive by one of you is twenty
minutes the whole town lives through, the other player included, standing
where they stood. That is the honest version of co-op time: two clocks in one
town would be two towns. A trip lands whole, so nobody is ever half-way
across; a skip in flight refuses everybody's walk alike.

The same holds for everything else that is a fact about the world rather than
a person: weather, the town's mood, the pace dial, which rooms have gone dark.

## What a guest may do

A guest is a joined player on the owner's real world.

- **May**: chat with the cast, skip time forward, watch duets, and — now that
  there is a body each — **walk their own body** (`where.php`). Walking was
  the owner's alone while there was one body, because a guest walking it was
  a guest walking the owner's character across town.
- **May not**: anything that is the owner's machine or the owner's world
  rather than the story — fate, the power switch and the learning switch,
  photo consent and its spend, taking back a skip
  (`xeric_play_owner_only`).
- **A stranger on a shared host** walks, looks and ends worlds freely — in
  their own copy, which is all they ever had open.

## What is next

M4 has shipped (below, "The room with two humans"). What is left of it is in
[PUNCHLIST.md](PUNCHLIST.md) under "Open — left by M4": chiefly that two
phones in a real browser have never been tried, and the hosted instance's
rules for the owner's bill beyond what the per-session meters already do.

## Who said it, and who may read it

Every user line carries its speaker in `messages.player` (NULL is the first
player). Once two people have spoken in a thread, the character's transcript
labels each player line with that player's roster name, and the bubbles do
the same. A promise goes to whoever said its words, so only their trust pays
for a miss.

Every message, event, render and photo is stamped with the rating its request
ran at: the session's affirmation and the device's adult bit, capped by the
world's rating. A viewer below a row's stamp sees that something is there and
not what it is: "(a line you are not shown)", the prose instead of the render,
the caption instead of the photo. That is the kids' headset. Unstamped rows —
everything written before this — read as they always did.

A guest's open never pauses or resumes the owner's clock.

## The room with two humans

The live room (`engine/room.php`, the web half in `forge/web/play-view.php`
and `room.php`) used to seat one `player`. It seats `players`, in the order
they sat down, each `{id, handle, name, named, rating, joined_at_row,
silence, noticed}`, and keeps `left_players` for those who walked out while
somebody stayed. A live row or a seal written in the old shape is read as the
one-seat session it was (`xeric_room_live_norm`), so a session that was open
across the upgrade lands.

- **Sitting down is speaking.** A person standing at the room's place who
  says something to it is seated, and hears from their own row on — the rule
  an adult of the cast walking in already had. The first player's handle in
  the transcript is still `__you`; the n-th is `__you.p<n>`, a sigil the
  mention test can never find in a sentence. One table talks at a time in a
  world: a line from somebody standing elsewhere while a room is going is
  refused with the place it is going on at.
- **Leaving is walking out.** When a seated player's body leaves the place
  and somebody else is still at the table, a narrator row says so ("Ann
  heads out.") and their seat goes; pressing *step back* does the same
  ("Ann steps back from the table."). The session ends when nobody is left:
  `you left` for a room only one person ever sat at, as before, and
  `everyone left` when more than one did. Somebody who never sat down
  walking anywhere touches nothing.
- **The floor.** The who-answers draw treats each person like anybody who
  just spoke: the heat is what each of the cast holds about *that* person
  (their memories naming them, a promise *that* player owes, by the
  expectation's `p`). A cast line naming one of them hands the floor back to
  that one; a question put to the room goes back to whichever of them spoke
  last; a player's line that names the other player and none of the cast buys
  no answer at all — the floor is theirs. The receipt says who (`to` on the
  carry, `floor` on the trail), and the page says "the floor is Ann's".
- **Silence**, every person's, is counted and noticed once per stretch each
  ("Walt has not said a word in a while"), through a cast mouth, never more
  strongly than "you may notice".
- **The prompts** carry each person's lines under their own roster name, and
  the reseating block names everybody standing at the table ("Walt and Ann
  are standing right here and each of them hears every word"); the coaching
  owes the answer to whichever of them just spoke. With one person at the
  table every prompt, row and receipt is the one the one-player engine
  wrote — `engine/tests/room-test.php` hashes a whole session against it.
- **The ceiling is the lowest at the table.** Each seat carries the rating
  its session reads at. A newly seated player with a lower one — a kids'
  headset — lowers the room's ceiling for everybody, from the next cast line
  on, and the page says so ("Somebody at the table reads at a lower rating,
  so the room keeps to it for everyone"). It folds down only, never back up
  mid-session. Down is safe without a seal because every cast prompt is
  rebuilt at the session's ceiling each beat; the cast lines after the fold
  are stamped at it, so the kids' headset can read the answers. What a person
  typed keeps their own request's stamp, so an adult session's lines still
  read as gaps on the kids' headset — the stamp rule, doing its job.
- **Cost.** Each player's line is one message on *their* session's hour. A
  cast beat is charged, as a reroll, to the session whose line started the
  turn — whichever worker happens to be carrying it. The payer's session id
  is a credential, so it never goes into `world.db`; it sits beside the jobs,
  keyed by the lease. Talking without you runs while any seated player's tab
  is watching (the carry from either counts), and its caps — two in a row,
  six a session — are the session's, not each person's.
- **Doors.** A guest who joined with a name and has a seat in the roster may
  speak into the room, ask it to carry on, and step back from it. A guest
  with no seat in the roster reads the room and never speaks into it (M3's
  rule, kept for exactly them). A stranger on a shared host opens a fork — a
  separate database with the owner's table scrubbed out — and sees nothing of
  the owner's room; `forge/web/tests/room-web-test.php` asserts it.
- **The close** names everybody who sat — the event's title and prose, each
  diary's company — and its trail lists the seats with the row each sat down
  at. A promise made across the table is its speaker's (`p` on the row).

And the things part one left reading only one person:

- **The pulse** (`GET worlds/{w}/pulse`) hashes every player's position once
  there is more than one, so a friend's walk repaints the owner's screen; a
  world of one carries no such key and its cursor is the one it was.
- **The sound of a room** counts the other players standing in it in its
  headcount (never the listener); a solo world's sound fingerprint is pinned.
- **A promise is kept by the body of whoever made it** —
  `xeric_expect_keep_present` reads each promiser's own position, never the
  current request's player.
- **Kept and explained** go to the player whose line it was: the harvest is
  asked for a name when two have spoken, and otherwise the newest line it is
  the first to read decides — never simply whoever's turn ran the harvest.
- **Reference images** for a new render (`xeric_scene_reference`,
  `xeric_photo_reference`) skip rows stamped above the rating that render
  runs at, so a kids' headset's room is never drawn from an adult one.

What this has not been: two phones in a real browser. The tests seat two
sessions on one world in-process and through real page requests in a child
process; the rendered room and the hearing are exactly as tested as M2's
were.

