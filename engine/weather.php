<?php
/**
 * Xeric — weather. One sentence about the sky that everybody agrees on.
 *
 * THE PROBLEM IT SOLVES: weather used to exist only as prose the models
 * invented per hour — fog pressed thick at ten, a bruised purple sky at
 * eleven, clear noon at twelve — uncoordinated, so one hour's storm was the
 * next hour's picnic. This makes the sky WORLD STATE: deterministic,
 * day-coarse, seeded from the world's own name and calendar, so every prompt,
 * every sweep and the sidebar all read the same line without ever writing one
 * down. There is no table to migrate and no row to sync — the sky is a pure
 * function of who you are and what day it is.
 *
 * DAY-COARSE IS THE CACHE DISCIPLINE. A line that changed hourly in the RIGHT
 * NOW block would still be legal (that block is volatile by design), but a
 * day's weather holding still is also just how small towns talk about
 * weather: it is "raining today", not "raining at 14:00". Nothing here may
 * ever reach a SYSTEM message — the consumers put it in the volatile block
 * and the sweep's user prompt, and the byte-stability suite holds them to it.
 *
 * SET THE SCENE, NEVER CARRY IT. The still-life rule already says weather may
 * dress an hour and may not be the subject of one. These lines are written to
 * be scenery: no events in them, no verbs a plot could hang from.
 *
 * LOCALE-AWARE BY KEYWORD, NOT BY CONFIG. A Gulf town storms, high desert
 * does not, a station has no sky at all — and the template already says which
 * one it is, in the words of setting.locale and the premise. Deriving beats
 * declaring: no forge change, no migration, every world ever forged gets
 * weather today. The keyword read is deliberately coarse; a world that wants
 * a different climate edits its locale line, which is the field that SHOULD
 * mean that.
 *
 * PHP 8.2+. Zero dependencies beyond world.php's day names.
 */

declare(strict_types=1);

/**
 * Which sky this world lives under.
 *
 * Checked in specificity order — "a dome over a dead sea" should read as
 * interior before 'sea' can make it coastal. Unknown text lands temperate,
 * which is the climate of not saying.
 */
function xeric_weather_climate(array $t): string
{
    $text = mb_strtolower(
        (string)($t['setting']['locale'] ?? '') . ' '
        . (string)($t['meta']['premise'] ?? '') . ' '
        . (string)($t['meta']['one_line'] ?? ''));

    $any = function (array $words) use ($text): bool {
        foreach ($words as $w) if (str_contains($text, $w)) return true;
        return false;
    };

    if ($any(['station', 'starship', ' ship', 'orbit', 'underground', 'bunker', ' dome',
              'colony ring', 'habitat', ' vault', ' hull'])) return 'interior';
    if ($any(['desert', 'dune', 'mesa', 'arid', 'high plain', 'scrub', 'dust town',
              'badland', 'wasteland'])) return 'arid';
    if ($any(['tundra', 'arctic', 'frozen', 'glacier', 'permafrost', 'snowbound',
              'far north'])) return 'cold';
    if ($any(['gulf', 'coast', 'harbor', 'harbour', 'port ', ' bay', 'island', ' sea',
              'seaside', 'wharf', 'fishing'])) return 'coastal';
    if ($any(['jungle', 'tropic', 'monsoon', 'delta', 'bayou', 'swamp'])) return 'humid';
    return 'temperate';
}

/**
 * The lines, per climate, per season. Written era-neutral on purpose — no
 * cars, no forecasts, no wires — so an 1873 fog town and a present-day one
 * read the same sky without embarrassment. Interior worlds get the machinery
 * of air instead of a sky, all four seasons the same, because a station does
 * not have a June.
 *
 * @return array<string,array<int,array<int,string>>> climate => [season 0-3 => lines]
 */
function xeric_weather_lines(): array
{
    $interior = [
        'The air handlers are running a shade warm today.',
        'A faint chemical sweetness in the recycled air, gone if you mention it.',
        'The lights are holding steady at full today.',
        'Condensation beads along the cold lines this shift.',
        'The vents have a low hum in them today, more felt than heard.',
        'The air is dry today, the kind that cracks knuckles.',
    ];
    return [
        'temperate' => [
            ['Grey and cold, with a wind that finds the gaps.', 'Snow that cannot commit, half rain by noon.',
             'A hard frost overnight, still on the shaded grass.', 'Low cloud all day, lights on by four.',
             'Bright, bitter cold, breath hanging in the air.'],
            ['Rain on and off since morning, gutters running.', 'A washed blue sky and a wind with winter still in it.',
             'Mild and grey, the ground soft everywhere.', 'Sun between showers, everything smelling of dirt.',
             'A warm afternoon that will not last, and everyone knows it.'],
            ['Hot and still, the shade doing no good.', 'A thunderhead building west of town all afternoon.',
             'Hazy heat, the road shimmering by ten.', 'A dry bright day with a breeze worth sitting in.',
             'Close and heavy, rain that keeps threatening and never comes.'],
            ['Clear and cool, the light gone long and yellow.', 'Wind stripping the trees a little barer by the hour.',
             'Fog until midmorning, burned off by noon.', 'A cold rain that settles in for the day.',
             'Bright and sharp, the first real chill in it.'],
        ],
        'coastal' => [
            ['A gale off the water rattling everything loose.', 'Cold spray in the wind three streets inland.',
             'Grey swell, grey sky, no line between them.', 'A hard clear day, the water almost black.'],
            ['Fog in off the water, foghorns going.', 'A stiff onshore breeze, everything tasting of salt.',
             'Broken cloud and gulls riding the updrafts.', 'Rain squalls marching in one after another.'],
            ['Still and hot until the sea breeze arrives midafternoon.', 'A storm standing offshore, deciding.',
             'Glare off the water fit to blind.', 'Warm, damp, the air thick enough to lean on.'],
            ['The first big blow of the season due any day, they say.', 'Whitecaps to the horizon.',
             'A high milky sky and a falling glass.', 'Clear, cold, the water calmer than it has any right to be.'],
        ],
        'arid' => [
            ['Cold overnight enough to crack stone, warm by ten.', 'A dry wind with grit in its teeth.',
             'Cloudless. It is always cloudless.', 'Frost gone an hour after sunup.'],
            ['Dust standing in the air from something far off.', 'The wind up all day, doors banging.',
             'Hard bright light and shadows like ink.', 'A dry storm on the horizon, all lightning, no rain.'],
            ['Heat that starts before the sun clears the ridge.', 'The air shaking over every flat surface.',
             'A furnace wind out of the south.', 'The one cloud of the week, gone by noon.'],
            ['Cooler at last, the light gone amber.', 'A cold wind under a hot sun.',
             'The first night frost, denied by everyone.', 'Still, dry, the dust finally settled.'],
        ],
        'cold' => [
            ['Snow squeaking underfoot, the cold a physical fact.', 'Ice fog, the sun a pale coin.',
             'A whiteout by afternoon, they are saying.', 'Clear and brutally cold, the air itself cracking.'],
            ['The thaw dripping off every roof.', 'Rotten ice going grey on the water.',
             'Mud where the snow gave up.', 'A wet snow that will not stick.'],
            ['A long pale evening that never quite ends.', 'Mosquitoes and meltwater.',
             'Warm enough for shirtsleeves, and nobody trusts it.', 'Rain on the last stubborn drifts.'],
            ['The first serious snow, settling in.', 'A hard freeze that means it this time.',
             'The light failing earlier by minutes a day.', 'Everything battened down that can be.'],
        ],
        'humid' => [
            ['Cool and dripping, the green gone dark.', 'Mist in the low ground until noon.',
             'A rare dry day, everything steaming.', 'Rain on the roof all night, still going.'],
            ['The wet season clearing its throat.', 'A downpour you can hear coming a full minute off.',
             'Heat and flowers and standing water.', 'Thunder somewhere every afternoon now.'],
            ['Air you wear rather than breathe.', 'Rain at three, like an appointment.',
             'The river up and the color of coffee.', 'A sodden heat that slows everything to a walk.'],
            ['The storms further apart now, the mud staying.', 'A clear morning worth remarking on.',
             'Everything mildewed that stood still too long.', 'The first cool night in months.'],
        ],
        'interior' => [$interior, $interior, $interior, $interior],
    ];
}

/**
 * The sentence itself: one line, same for every reader, all day.
 *
 * Seeded by (world name, world date): crc32 is stable across machines and
 * PHP versions, the date string is day-coarse by construction, and the month
 * picks the season so a long-lived world drifts through its year. Nothing is
 * stored and nothing can disagree — two prompts, the sidebar and a sweep all
 * derive the identical byte string for the identical day.
 */
function xeric_weather_line(array $t, array $now): string
{
    $iso = (string)($now['iso'] ?? '');
    if (strlen($iso) < 10) return '';
    $date  = substr($iso, 0, 10);                    // day-coarse, the whole point
    $month = (int)substr($iso, 5, 2);
    $season = (int)(($month % 12) / 3);              // 0 winter, 1 spring, 2 summer, 3 autumn

    $climate = xeric_weather_climate($t);
    $lines   = xeric_weather_lines()[$climate][$season];
    $seed    = crc32((string)($t['meta']['name'] ?? 'xeric') . '|' . $date);
    return $lines[$seed % count($lines)];
}
