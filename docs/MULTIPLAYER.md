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

Being built alongside this:

- **The room with two humans in it.** The who-answers draw treats a human
  like anyone who just spoke; the floor can return to either of you; silence
  is noticed for both. Until then the live room belongs to the player who
  opened it, and another player walking out of the diner does not end it.
- **Threads that know who said what.** A player id on messages, so a
  character remembers which of you promised the dish.

After that: rating per viewer (a stamp on every message, event and scene row,
filtered per person — the "kids' headset" flag), and the hosted instance's
rules for guests and the owner's bill. M4 is done when two people on two
phones stand in the same rendered room, see each other's tags, and hear the
same cast answer them both — and a third person on a shared host, in their
own fork, sees none of it.

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
