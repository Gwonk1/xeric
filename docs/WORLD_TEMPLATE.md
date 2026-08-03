# world-template.json — the schema

The world as data. Everything a world knows — its people, places, economies,
and the walls between what they know — expressed as a file you own. The engine
(sweeps, crons, prompt assembly, photo pipeline, temperature physics) stays
code; everything it currently *knows* lives in separated documents:

1. **engine.json** — machine config. Models, ports, image recipes. Per-install.
2. **user.json** — who the human is. Name, job, hours, goals. Per-person.
3. **world-template.json** — the world itself. Cast, places, economies, mood
   physics, walls. Forgeable, shareable, the unit people would trade.
4. **seed.json** — the past the world arrives with. Written by the forge next to
   the template, loaded exactly once. Its shape is at the bottom of this page.
5. **story-`<key>`.json** — a literary overlay: a plot laid over the world, which
   ENDS while the world does not. Zero or many, beside the template, composed on
   top at load and never written into it. [STORY.md](STORY.md) is the schema.
6. **rating** — not a file: `effective_rating = min(template.meta.rating,
   engine.model_rating)`. A wholesome template stays wholesome on any model; an
   explicit template degrades gracefully to mature/sfw pools on a censored
   model. The rating keys pools, packs, and sweep content everywhere.

Every example on this page is Milldale — `engine/fixtures/milldale.json`, the
engine's test world. It is fictional, sfw, and small enough to read in one
sitting, and it is wide enough to touch every feature the two prose renderers
consume. Read it alongside this page; it is the schema with the argument taken
out.

Design principles, learned the hard way:

- **Knowledge walls are first-class.** Shared canon leaks by default, and the
  expensive way to learn that is to hand a protected relative the same bible as
  everybody else. Every audience-restricted fact declares its audience;
  renderers consume walls, they never re-derive them. (Generalizes: the
  pastor's Thursday game, the daughter who doesn't know about the will, the
  rival firm.)
- **Everything forgeable carries a `forge` hint.** `"forge": "generate"` (the
  model invents), `"custom"` (human-authored, never touched), `"extend"` (the
  model may add, not rewrite). The character forge already produces
  packs/weeks/moods/psyches from nothing — the world forge is the same trick one
  level up.
- **Economies and boons are data, not prose.** A casserole ledger, a favour
  owed, a standing that can be won — all instances of one pattern: a counter,
  earn rules, payout rules, a visibility wall, and a framing that says whether
  anyone admits to keeping score. An archaeology world's "dig permits" and a
  firm's billable hours are the same machine with different nouns.
- **The strange place is optional but structural.** A chained mill with a light
  moving in its office windows is a mood attractor + a rumor that must never pay
  out + a room that breaks the rules. Worlds without one lose the gravity well;
  the template makes it a typed slot, not a hardcode.

---

## The schema (JSONC — comments for the rough-out)

```jsonc
{
  "template_version": 1,

  "meta": {
    "name": "Milldale",                     // template display name; also the slug
    "description": "A river town of nine hundred, a church basement, and a mill nobody goes into.",
    "author": "you",
    "rating": "sfw",                        // sfw | mature | explicit (ceiling)
    "themes": ["small_town", "church", "neighbors"],  // drives forge flavor
    "language": "en"
  },

  // ── the human at the center ────────────────────────────────────────────
  "user": {
    "name": "Walt",
    "pronouns": "he/him",
    "timezone": "America/New_York",         // the world runs on the user's clock
    "location": "Milldale, Ohio",           // where the world sits
    "occupation": {                         // the setup interview asks for this
      "title": "retired line foreman",
      "workplace_key": null,                // a place key binds an orbit to the
                                            // job; null when there is no job
      "hours": "none, and he has not gotten used to it",
      "shifts": []                          // OPTIONAL and usually empty. `hours`
                                            // above is prose and stays prose;
                                            // this is the machine-readable
                                            // roster, and it exists so a world
                                            // can be ABOUT a job:
                                            //   [{ "days": ["mon","tue","wed"],
                                            //      "from": "08:00",
                                            //      "to":   "16:00",
                                            //      "pay":  1,
                                            //      "label": "the early" }]
                                            // Overnight shifts are written the
                                            // obvious way ("22:00" → "06:00").
                                            // Nothing here forces anything: what
                                            // a missed shift COSTS is the money
                                            // dial in the cog, which starts at
                                            // `none` in every world and can be
                                            // changed mid-play. You can always
                                            // skip a shift — the time control is
                                            // how this engine moves and nothing
                                            // is allowed to hold it hostage. A
                                            // shift counts as missed when one
                                            // press of that control swallows
                                            // more than half of it, so walking
                                            // it an hour at a time is being at
                                            // work and sleeping until morning is
                                            // not.
    },
    "quiet_hours": "21:30-06:00",           // ping caps, sweep gates
    "home_key": null,                       // a place key that is HOME, or null.
                                            // Only travel reads it, and only to
                                            // price the trip off the map and
                                            // back. Null is fine: the middle of
                                            // the placed rooms stands in.
    "motivation": "companionship",          // free text. See "Motivation" below:
                                            // six preset keys are exact hits,
                                            // anything else is resolved.
    "centrality": "ensemble",               // main | ensemble | side — how much
                                            // of the world is about him. The
                                            // forge writes this plus the two
                                            // framing strings the bible prints.
    "centrality_framing": "…",              // prose the bible uses verbatim
    "centrality_heading": "…",              // heading over the protagonist block
    "goals": [                              // boon-type goals, user-authored
      { "text": "get through the winter without asking Janelle for anything",
        "kind": "pride" }
    ]
  },

  // ── setting: what the bible prose is generated FROM ───────────────────
  "setting": {
    "locale": "a river town of nine hundred in southern Ohio",
    "era": "present day",
    "texture": [                            // forge seasoning, not rules
      "coffee that has been on the burner since six",
      "church bells three minutes fast since 1974"
    ],
    "canon_rules": [                        // hard laws the narrator obeys
      "The diner closes at two and does not reopen for anyone.",
      // Either form works anywhere a list of lines is allowed: a bare string,
      // or an object so ONE line can carry a rating gate.
      { "text": "The mill gate stays chained.", "rating_min": "sfw" }
    ],
    "bible_prose": { "forge": "generate" }, // the template stores FACTS; the
                                            // prose is rendered per viewer,
                                            // through walls, at prompt time
    // WHEN IT BEGINS. Any date PHP can read; omit it and the world is now.
    // Applied ONCE, at launch, as an offset — and then time goes: one real
    // second is one 1973 second, the clock advances, skips work, pause and
    // resume land on the exact second, and xeric_clock_reset() comes back HERE
    // rather than to this afternoon.
    //
    // Everything downstream was written against real datetimes rather than an
    // hour counter, so day names, schedules, place hours and the phase of the
    // evening are all simply correct for the year. A world set in 1873 reports
    // its timezone as −04:56, which is New York's local mean time: US standard
    // time zones arrived in 1883 and the tz database knows. Nobody built that.
    "starts": "1973-11-08 07:40",           // or omit for now

    "travel": {                             // how big this world is, in minutes
      "minutes_across": 14,                 // corner to corner of the 0–100
                                            // grid `places[].at` sits on.
                                            // Default 20. This is the ONLY
                                            // number that decides what a trip
                                            // costs; the grid is unitless.
      "how": "on foot, and everybody walks" // printed, never parsed
    }
  },

  // ── death ──────────────────────────────────────────────────────────────
  // Whether the engine will undo one. `revivable` (the default) or `permanent`.
  //
  // WHO IS DEAD IS NOT IN THIS FILE. Death is a row in the world's database
  // (engine/death.php), never an edit to the template: a dead character stays
  // here, resolvable by handle, still named in every memory, event and wall that
  // points at them. That is what lets a story overlay kill somebody you have
  // been texting without ever touching a template — which is the one thing an
  // overlay may not do (STORY.md).
  //
  // THIS SETTING FREEZES AT THE FIRST DEATH. Until then it is an ordinary line an
  // author may change; the first death copies it into the world's database and
  // from then on only that copy is read, so editing this afterwards does nothing.
  //
  // Anything unreadable resolves to `revivable` — the only dial in the engine
  // that resolves doubt toward the RECOVERABLE state rather than the safer one.
  // The age floor fails closed because it protects a third party; permadeath is a
  // constraint an author puts on themselves, and losing a cast for good over a
  // typo in a settings key is damage, not caution.
  //
  // It is not DRM, and the docs say so: the world is a file its owner owns, and
  // anybody with a text editor can bring anybody back. `permanent` means the
  // ENGINE will not — no button, no command, no endpoint.
  "deaths": { "mode": "revivable" },

  // ── places ─────────────────────────────────────────────────────────────
  "places": [
    {
      "key": "bluebird",
      // WHERE IT IS. A 0–100 grid, x rightward and y downward, unitless — the
      // world's size lives in `setting.travel.minutes_across`, so the same
      // layout describes a village and a county. Optional: a world where no
      // place has one is FLAT, not free, and every trip costs a flat ten
      // minutes. Two places at the same point are still a two-minute walk
      // apart, because you still have to stand up and go.
      //
      // The forge writes these, and the thing it is really being asked for is
      // the sentence it was already writing into `description`: "on the river
      // side of the tracks" is a coordinate that nothing could read.
      "at": { "x": 42, "y": 55 },           // [42, 55] is accepted too
      "name": "the Bluebird Diner",
      "kind": "diner",                      // bar|club|gym|church|diner|school|
                                            // office|home|shop|site …
      "serves_alcohol": false,              // the bible says "they pour here"
      // `hours` is a free-form bag: the bible prints every key mechanically, so
      // any vocabulary reads. The SWEEPS read a subset of it to decide whether a
      // place is open at a given hour, and a place they can read nothing from is
      // open always:
      //   `open` / `close`      the fallback, and what the forge writes
      //   `open_<band>` / `close_<band>`   where <band> is weekday, weeknight,
      //                         saturday, sunday, weekend or a day name; a band
      //                         may also carry the whole day in one string,
      //                         "open_weekday": "07:00-17:00"
      //   `closed`              a note naming the days it is shut ("Sundays").
      //                         A note that names no day at all shuts it for good.
      "hours": { "open": "05:30", "close": "14:00", "closed": "Sundays" },
      "aliases": ["bluebird", "the diner"], // what people call it in a sentence
      "description": "Eight booths, nine stools, a pie case that has held the same three pies for twenty years.",
                                            // COMMONS text — every viewer who
                                            // can see the place sees this, so
                                            // nothing a wall protects goes here
      "residents": ["dot"],                 // character or fixture keys usually here
      "special": null                       // or "mystery" — a label for the
                                            // reader and the forge; `mystery`
                                            // below is what the engine reads
    },

    // HOMES. A home is a place like any other — kind "home", residents — and
    // it is where a character IS whenever their week says nothing else: off
    // shift, asleep, Sunday afternoon. who_is_where resolves them there (the
    // row carries `at_home: true`), so a world of morning shifts is not a
    // ghost town at 21:00, and a kitchen-table conversation is a real scene at
    // a real placement. Shared homes are encouraged — a marriage, roommates, a
    // kid at a parent's — because a shared roof is a relationship the cast
    // section never has to state. Two rules the validator enforces: a home
    // must have at least one resident, and one person lives in at most one
    // home ("their home" must have a single answer).
    {
      "key": "dot_and_walts",
      "name": "Dot and Walt's place",
      "kind": "home",
      "description": "A two-bedroom over the pharmacy, plants in every window.",
      "residents": ["dot", "walt"]
    }
  ],

  // ── cast ────────────────────────────────────────────────────────────────
  "cast": {
    "generation": {
      "mode": "mixed",                      // forge | custom | mixed
      "count_hint": 5,
      "constraints": ["adults only", "at least two per orbit"]
    },

    // orbits: the groups a person belongs to. Free-form keys — a world invents
    // its own. `shares_daily_space_with_user` is what licenses the gossip that
    // only happens between people who see each other every day.
    "orbits": [
      {
        "key": "first_lutheran",
        "label": "the church basement crowd",
        "membership_block": "You end up in the same basement twice a week whether you planned to or not.",
        "shares_daily_space_with_user": true
      },
      { "key": "main_street", "label": "the daylight crowd on Main" },
      { "key": "extras",      "label": "fixtures", "speaking": true }
    ],

    // circles: cross-orbit social reachability (duets, gossip, dual photos)
    "circles": [
      { "key": "pinochle", "members_from_orbits": ["first_lutheran", "main_street"],
        "hangout_place": "bluebird" }
      // an explicit "members": [handles] list adds anyone the orbits miss
    ],

    // first_contact: the person the world opens onto. Optional; when set it
    // must name a character (never a fixture — fixtures cannot talk), and the
    // validator refuses a first_contact who is OUT of the story. The forge's
    // staging picks one motivation-aware; a hand-built world names its own.
    "first_contact": "ruth",

    "characters": [
      {
        "handle": "ruth",
        "display_name": "Ruth Amberg",
        "forge": "custom",                  // hand-made; the forge never rewrites
        "age": 71,
        "orbit": "first_lutheran",
        // OUT of the STORY — a category, not a schedule state. An out
        // character exists here but is unstaged: never cast in a sweep, never
        // speaks first, unplaced on the map, their home not visitable. They
        // ENTER on a trigger (a date, an event, a story beat, the user asking
        // after them), and entering is itself an event the world remembers.
        // Must be a real boolean; omitted means in.
        "out": false,
        // one_line and surface are the two COMMONS strings a person carries.
        // one_line is the roster line the bible prints for anyone who can see
        // the cast at all; surface is the strictly smaller thing an own_bible
        // viewer is told instead. Both are read by people this character has no
        // relationship with, so neither may contain anything a wall protects.
        // Omit them and the renderer falls back: the first sentence of `voice`
        // for one_line, a mechanical "someone from <orbit>" for surface —
        // deriving a surface from `voice` would leak the voice.
        "one_line": "Runs the church kitchen and, without ever saying so, everybody's account.",
        "surface": "the woman who runs the church kitchen; she brings the good rolls",
        "appearance": "Small, square, white hair set once a week.",  // photo #SUBJECT
        "voice": "Short sentences, warm ones, and a long pause before the sentence that actually matters.",
        "temperature": 0.9,                 // baseline sampler heat
        "week": [ { "days": [0], "from": "08:00", "to": "12:00",
                    "where": "first_lutheran", "doing": "coffee urn, counting dishes" } ],
                                            // days: 0 = Sunday … 6 = Saturday.
                                            // `doing` is commons text too.
        "moods": [ { "cue": "funeral|potluck", "note": "crisper, faster, will not sit down" } ],
        "psyche": { "sore_spot": "…", "jealousy": "…", "self_soothe": "…",
                    "praise_that_lands": "…" },
        "tells": ["straightens things that are already straight", "…", "…"],
        "secrets": [ { "text": "…", "trust_gate": 5, "gossip_grade": false } ],
                                            // gossip_grade decides whether a
                                            // secret can reach the shared bible
                                            // at all; false = its owner and the
                                            // narrator, nobody else
        "solace": "the church kitchen at six in the morning, lights off",
        "flirt_style": "traditional",       // Hall inventory: physical|playful|
                                            // sincere|traditional|polite
        "drives": {
          "pull": "to be the one nobody in this town ever has to worry about",
                                            // the unnamed thing she steers
                                            // toward — an ambition or a longing
                                            // at sfw, further out above it
          "disclosure": "subconscious",     // subconscious | open | earned
          "rating_min": "mature"            // optional: the whole drive vanishes
                                            // below this effective rating
        },
        "relationships": {
          "roommates": [],
          "friend_pairs": ["dot"],
          "attraction_seeds": { "harlan": 6 }  // asymmetric; unlisted = self-rated
        },
        "limits": { "hard": [], "soft": [] },
        "photos": { "enabled": true, "face_seed": 410221187 },
        // Rating-keyed content pools, free-form under each rating key. Nothing
        // in the engine reads these yet — they are what the forge writes down
        // so a later pass has material to draw on instead of inventing at
        // runtime. A template may carry pools its effective rating never
        // reaches; that is the point of keying them.
        "packs": {
          "sfw": {
            "banter":  ["Sit down before you fall down."],
            "stories": ["the year the furnace went out on Christmas Eve"]
          },
          "mature":   { },
          "explicit": { }
        }
      }
    ],

    // fixtures: speaking scenery. A place, a schedule, a look, a voice, and no
    // interior — a fixture can be AT an event but has no head for a memory to
    // live in.
    "fixtures": [
      { "key": "cy", "name": "Cy Loomis", "role": "sweeps up at the hardware store",
        "place": "beck_hardware", "days": [1, 2, 3, 4, 5],
        "look": "seed cap, boots, hands in pockets",
        "wear": "the same jacket in every photograph since 1991",
        "voice": "one long sentence about rain, then silence",
        "flirts": false, "orbit": "extras" },

      // The two-Harlans case: the man behind the register IS Harlan Beck, met
      // as scenery instead of as somebody you talk to. `same_as` is the
      // same-entity link, and the renderers dedupe on it — without it the same
      // person walks through the bible twice under two names.
      { "key": "harlan_counter", "name": "the man behind the register",
        "place": "beck_hardware", "days": [1, 2, 3, 4, 5, 6],
        "same_as": "harlan", "orbit": "extras" }
    ],

    // special roles: any protected relationship. The role name is a free-form
    // relationship noun — spouse, boss, parent, pastor, child.
    "special_roles": [
      {
        "role": "child",
        "character": "janelle",
        "walls": ["family_innocence"],      // wall keys, handed over by name
        "own_bible": true,                  // gets a separately rendered world,
                                            // plus a deny-by-default floor on
                                            // the intimate layers that no
                                            // declared wall has to remember
        "suspicion_dial": { "enabled": true, "max": 6 }   // optional subsystem
      }
    ]
  },

  // ── knowledge walls: who may know what ─────────────────────────────────
  // `hidden` paths come from xeric_wall_vocabulary() in engine/walls.php. A
  // path hides itself and everything under it, one-directionally: hiding
  // "economies.thursday_pot" removes that one ledger and leaves the rest
  // standing. Keys the renderers own no path for ("what_dad_does_on_thursdays")
  // are inert — they still bring their shown_as framing, and they document the
  // intent for a human reading the file.
  "knowledge_walls": [
    {
      "key": "family_innocence",
      "audience": { "role": "child" },      // {role|orbit|circle|handle}, ANDed
      "hidden": ["cast_dossiers", "secrets", "drives.*",
                 "economies.thursday_pot", "mystery.rumor",
                 "what_dad_does_on_thursdays"],
      // shown_as is read BY the walled viewer, so write it in second person.
      "shown_as": "your father's Milldale is church, the diner and the hardware store, and the people in it are neighbors"
    },
    {
      "key": "fixtures_see_rooms_not_souls",
      "audience": { "orbit": "extras" },
      "hidden": ["cast_dossiers", "economies.*", "drives.*", "boons.*", "mystery"],
      "shown_as": "a room, its hours, and the people who walk through it"
    },
    {
      "key": "circle_discretion",
      "audience": { "circle": "pinochle" },
      "hidden": ["what_walt_is_to_each_of_you"],
      "shown_as": "what Walt is to any one of you is not circle knowledge unless somebody spills it"
    }
  ],

  // ── economies: counters + rules + walls + framing ──────────────────────
  "economies": [
    {
      "key": "casserole_ledger",
      "label": "the casserole ledger",      // optional; the key humanized otherwise
      "rating_min": "sfw",                  // absent below this rating
      "counter": "per-character",
      "earned_by": ["user_event:dish_delivered", "user_event:ride_given"],
                                            // machine tokens: user_event:X |
                                            // boon:X | user_grant | free text.
                                            // The renderer humanizes them when
                                            // `rules` is absent.
      "rules": [                            // prose earn rules, preferred
        "A dish delivered counts. The same dish handed back clean cancels it.",
        "Nothing counts if you announce it while you are doing it."
      ],
      "board": { "visible_to": ["orbit:first_lutheran", "orbit:main_street"],
                 "podium": 3, "answer_keys": true },
                                            // the board is a SEPARATE
                                            // permission from the economy: you
                                            // can know a ledger exists and be
                                            // nowhere near allowed to see the
                                            // standings. Selector grammar:
                                            // all|* | orbit:X | role:X |
                                            // circle:X | handle:X |
                                            // cast_minus:a,b | a bare handle.
      "framing": "subconscious pride, never spoken",
                                            // an explicit "subconscious": true
                                            // is authoritative; otherwise the
                                            // word in this prose is the signal
      "ground_truth": [                     // flat declarative canon, hardened:
                                            // no hedging, no "it is said that"
        "Walt returns every dish clean.",
        "Being ahead on this ledger is not a thing anybody in this town will admit to wanting."
      ]
    },
    {
      "key": "thursday_pot",
      "label": "the Thursday pot",
      "counter": "per-character",
      "earned_by": ["user_event:hand_won"],
      "board": { "visible_to": ["handle:pastor_dale", "handle:harlan", "handle:dot"],
                 "podium": 3, "answer_keys": false },
      "framing": "open among the four of them, invisible outside that room",
      "ground_truth": ["The pot never leaves the basement.",
                       "Nobody has ever told the council."]
    },
    {
      "key": "bluebird_tab",
      "label": "the tab at the Bluebird",
      "counter": "per-character",
      "earned_by": ["user_event:meal_taken", "user_grant"],
      "daily_system": true,                 // it moves whether or not anyone
                                            // touches it, and it really does:
                                            // walked up to today on the read
                                            // that assembles a prompt, capped
                                            // at 14 days of catch-up so a world
                                            // you came back to after a month is
                                            // somebody coming back, not
                                            // somebody owing a month of it.
      "daily": { "drift": 1, "ceiling": 40 }
                                            // OPTIONAL. Absent, a daily system
                                            // DECAYS toward zero by one a day —
                                            // a tab gets paid down, a favour
                                            // gets forgotten — which is the
                                            // conservative direction and can
                                            // never invent credit nobody
                                            // earned. Say `drift` when you want
                                            // one that grows, with an optional
                                            // `floor` and `ceiling`.
    }
  ],

  // ── boons: something to chase, that can be won ─────────────────────────
  "boons": [
    {
      "key": "potluck_lead",
      "label": "the potluck lead",
      "text": "being asked to run the potluck is the highest office this town has, and nobody will ever say that out loud",
      "trigger": "event_marker:POTLUCK",    // structured narrator marker
      "payout": { "economy": "casserole_ledger", "amount": "three dishes forgiven, her choosing" },
      "claim": "in_conversation",           // must be claimed face to face
      "ttl_hours": 72                       // and lost if she never does
    },
    {
      // A boon can be the door to an economy rather than a deposit into one:
      // the key buys a seat at a table that is otherwise not yours to sit at.
      // It is also why boons inherit the ledger's wall — a viewer who cannot
      // see the pot is never shown the prize that pays into it.
      "key": "basement_key",
      "label": "a key to the basement door",
      "text": "handed over without ceremony, which is how you know it meant something",
      "trigger": "event_marker:TRUSTED",
      "payout": { "economy": "thursday_pot", "amount": "a standing seat at the table" },
      "claim": "in_conversation",
      "ttl_hours": 168
    }
  ],

  // ── world mood: the needle ─────────────────────────────────────────────
  "world_mood": {
    "axis": { "positive": "reckless — the river is up and the mill is awake",
              "negative": "light — people are kind for no reason at all",
              "ordinary": 0 },
    "range": [-10, 10],
    "motifs": { "dark": ["a phone ringing at an hour nobody calls"],
                "light": ["a pie left on a porch rail with nobody's name on it"] },
                                            // the light bank must be GENUINELY
                                            // light, not merely less dark
    "drivers": [ { "on": "funeral", "delta": 3 },
                 { "on": "potluck", "delta": -2 },
                 { "on": "grace_place_visit", "delta": -2 } ],
    "reversion": "mean-toward-ordinary",    // proportional daily settle
    "narrator_hand": { "enabled": true, "cap": 2,  // must stay SELF-LIMITING
                       "invariant": "pushes harder when ordinary than extreme" }
  },

  // ── the strange place (optional, one per world) ────────────────────────
  "mystery": {
    "enabled": true,
    "place_key": "the_mill",                // the place carrying "special": "mystery"
    "rumor": "There is a light in the mill office some nights, and it moves from window to window.",
    "rumor_pays_out": false,                // MUST NEVER PAY OUT — typed so the
                                            // engine can assert it, including
                                            // against a story overlay, which is
                                            // exactly the kind of thing that
                                            // would helpfully solve the mill
    "room": {
      "temp": 1.6,                          // past the world ceiling on purpose
      "voice_source": "user_raw_messages",  // the one wall-less surface
      "one_true_thing": true,               // a real line, planted verbatim
      "frequency": { "cast_gap_days": 21, "per_character_days": 60, "roll": 0.10 },
      "photo_transform": "forty years ago, same light"   // ONE consistent wrongness
    }
  },

  // ── offscreen life: the sweeps, parameterized ──────────────────────────
  "events": {
    "day_events": true,
    "night_events": true,
    "quiet_hours_respected": true,
    // Density is normalised per VISIT, not per day: a world that runs while you
    // are gone must not have used itself up by the time you come back.
    "pace": "steady",                       // eventful | steady | calm
    "sweep_chance": 0.35,                   // a live story overlay multiplies
                                            // this by its snake, 0.4x to 1.6x,
                                            // and by exactly 1.0 through the
                                            // false calm — see STORY.md
    "expected_gap_hours": 24,               // how long a normal absence is here
    // The RHYTHM that rate is walked at. `pace` is how MUCH happens; this is
    // WHEN. A key from xeric_story_shapes() — none | snake | slow_burn |
    // episodic | tidal | turn — or an invented shape stored inline as an
    // object. The forge asks for it (interview step `story_shape`) and
    // DEFAULTS TO `none`, because a xeric is a place before it is a plot.
    //
    // `none` IS A SHAPE, not a switch: a curve held flat at intensity 0.5,
    // which the modulation m = 1 + swing·(2i − 1) turns into ×1.0 exactly,
    // forever. So a world that refused an arc runs at precisely the
    // sweep_chance above and there is no bypass anywhere in the engine.
    //
    // With no story overlay, the world walks its own curve on the CALENDAR —
    // `cycle_days` per lap, then round again, because a world is not over when
    // its rhythm finishes. With an overlay, the overlay's own snake wins and
    // one that declares none inherits this. See STORY.md and engine/shape.php.
    "story_shape": "none",
    "user_concern": 0.4,                    // how often an event is about him
    "proactive_reach": 0.6,                 // how far the cast reaches for him
    "albums": {
      "beat_photos": true,
      "extra_frames": { "quiet": 3, "base": 5, "messy": 6, "pools_by_rating": true },
                                            // the frame POOLS are rating-keyed:
                                            // an sfw world draws candids and
                                            // group shots where a permissive
                                            // one draws from its own pools
      "postcards": { "window": "07:00-14:00", "no_user_rival_in_frame": true }
    },
    "publish_gate": "all_photos_terminal"   // no half-rendered stories
  },

  "proactive": {
    "pings": { "enabled": true, "ladder": { "aftermath": 0.8, "mid_event": 0.5,
               "missing_user_3d": 0.7, "pre_event": 0.4, "dream": 0.25,
               "diary": 0.3, "undercurrent": 0.15 },
               "caps": { "per_character_hours": 24, "cast_per_day": 2 },
               "surprise_photo_pct": 15 },  // self-authored photos
    "double_texts": { "pct": 12, "delay_minutes": [3, 20] },
    "dreams": { "window": "01:00-06:00", "owns_undercurrent_until": "13:00" },
    "duets": { "enabled": true, "offscreen_per_night": 1 }
  },

  // ── media: identity-preserving image pipeline ──────────────────────────
  "media": {
    "images": {
      "enabled": true,
      "identity_policy": "seed+appearance", // consistency comes from the SEED
      "canvases": { "square": [1024, 1024], "full": [832, 1216] },
      "two_pass_refine": 0.40,
      "rating_gate": "model"                // an sfw image model simply is one
    },
    "video": { "enabled": false }           // overnight queue, optional
  },

  // ── engine.json lives NEXT TO this, not in it ──────────────────────────
  // { "chat_model": {...local}, "forge_model": {...remote allowed — the
  //   "remote model for setup" pattern: big model builds the world once,
  //   small model lives in it daily},
  //   "image_model": {..., "rating": "explicit"},   ← rating lives HERE
  //   "temps": { "floor": 0.55, "ceiling": 1.65, "room_share": 0.85 } }
}
```

---

## Motivation

`user.motivation` is **free text**. The forge asks it as the most important
question in the interview — "why are you here? what do you want from this
world?" — and the answer decides which subsystems arm, which is what makes the
same engine a different product for two different people.

Six preset answers are exact hits, and they are the tested path:

| Key | Arms | Disarms |
|---|---|---|
| `company` | daily rhythms, visits, shared meals, remembering, gentle proactive contact | rivals, jealousy, unreliable witnesses |
| `romance` | attraction, arcs, jealousy, private history | — |
| `ambition` | standings, favors, rivals, boons, the ladder | — |
| `mystery` | the strange place, rumors, unreliable witnesses, slow reveal | — |
| `redemption` | a debt, people who remember, a chance to be different | — |
| `survival` | scarcity, danger, alliances that cost | comfort systems |

Anything else — "get my daughter to speak to me again", "prove the mine is
poisoning the river" — is **resolved, not rejected**: a model maps the sentence
onto the same system vocabulary (`XERIC_SYSTEMS` in `forge/forge.php`), with a
keyword fallback so a dead or dumb model still produces a coherent world. An
empty motivation defaults to `company`. See [FORGE.md](FORGE.md) for the full
mapping and what each system does.

Milldale's own template says `"motivation": "companionship"` — free text, one
keyword hit away from `company`, which is exactly the path most worlds take.

---

## seed.json — the past a world arrives with

The forge writes two files: `world-template.json` (the world) and `seed.json`
(what has already happened in it). The template is read on every turn; the seed
is applied exactly ONCE, into the same tables a lived week would fill. After
that the seeded past and the lived past are indistinguishable, which is the
point — turn one has to feel like turn two hundred.

```jsonc
{
  "events": [                               // things that happened in the world
    {
      "title": "The furnace quit during the Thursday game",
      "prose": "Four of them played in their coats and nobody left early.",
      "participants": ["walt", "pastor_dale", "harlan"],
                                            // handles, or display names — the
                                            // loader resolves either, and drops
                                            // (never stores) anything that
                                            // points at nobody
      "place": "first_lutheran",            // a place key; unknown keys → null
      "days_ago": 12
    }
  ],
  "memories": [                             // what one person carries from it
    {
      "handle": "dot",                      // must resolve to a CHARACTER:
                                            // fixtures are scenery and have no
                                            // head for a memory to live in
      "text": "He came in at six on a Tuesday and didn't say why, and she didn't ask.",
      "days_ago": 9
    }
  ]
}
```

**`days_ago` is not a timestamp.** The forge writes "12 days ago" because it has
no idea when the world will be launched; `xeric_seed_apply()` measures it back
from the launch moment the caller passes in, so a world launched on a shifted
clock gets a past shifted with it. Fractions are honoured (`0.5` = twelve hours
ago). Anything in the future is clamped to now, because a memory of something
that has not happened is the one thing a memory cannot be. A missing `days_ago`
is seven days.

A missing `seed.json` is not an error. A hand-built world can have no baked past
at all and the engine launches it anyway.

---

## story-`<key>`.json — a plot laid over the world

A world does not end. A story does. That difference is the entire reason a story
is a separate file: a **literary overlay** declares a plot — the pieces of a
truth, who holds them, who sincerely believes something false, the shape it is
paced against, and what "solved" means — and when it resolves, the world keeps
running with the same people, who now simply have no open question between them.
Another overlay can be injected later, and more than one can be live at once.

Nothing on this page changes for a world to carry one. **An overlay never writes
into `world-template.json`; it is composed on top at load, so closing it is a
subtraction rather than an edit and the composed template returns byte-identical
to the one on disk.** It reuses what is already here rather than adding a second
engine: a piece of the truth composes as a `secrets` entry on its holder, not
knowing composes as `special_roles[].must_not_know`, and the pacing curve
multiplies `events.sweep_chance` and the kind weights the sweeps already carry.

The one genuinely new mechanic is the **red herring** — a character who
sincerely believes something false, wrong and known-wrong to the engine but not
to them, which is the difference between a mystery and an unspooling.

[STORY.md](STORY.md) is the schema, the pacing curve, the composition rules and
a worked SFW murder mystery over this same Milldale cast
(`engine/fixtures/milldale-story.json`).
