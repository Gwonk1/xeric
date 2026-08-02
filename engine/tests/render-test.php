<?php
/**
 * Xeric — renderer tests.  `php engine/tests/render-test.php`, exit 0 on pass.
 *
 * The interesting half of this file is negative assertions. A wall that leaks
 * is not a cosmetic bug: it is the failure mode the whole design exists to
 * prevent, and it is invisible unless something goes looking for the exact
 * secret substring in the exact viewer's prompt. So: SECRETS below is the list
 * of strings that must never reach the wrong reader, and most checks are
 * "none of these appear".
 */

declare(strict_types=1);

require_once __DIR__ . '/../renderers/bible.php';
require_once __DIR__ . '/../renderers/economy.php';
require_once __DIR__ . '/../world.php';        // xeric_world_validate(), for the age checks below

$FAILED = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $FAILED;
    if ($cond) {
        echo "ok   - $name\n";
    } else {
        $FAILED++;
        echo "FAIL - $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

/** Which of $needles leaked into $hay. Case-insensitive: a leak is a leak. */
function leaks(string $hay, array $needles): array
{
    $found = [];
    foreach ($needles as $n) {
        if (stripos($hay, $n) !== false) $found[] = $n;
    }
    return $found;
}

// Every string below lives behind a wall somewhere in milldale.json.
const SECRETS = [
    'five-card draw',          // the pastor's Thursday habit
    'poker',                   // the word itself, thursday_pot rules only
    'green spiral notebook',   // Ruth's private ledger (gossip_grade: false)
    'glovebox',                // Janelle's own secret
    "comping Harlan's lunch",  // Dot's gossip-grade secret
    'moves from window to window', // the mill rumor
    'The pot never leaves the basement',
    'standing seat at the table',
];

const PSYCHE_MARKERS = ['Sore spot:', 'Praise that lands:', 'Pull:', 'Holds back'];

$T = xeric_template_load(__DIR__ . '/../fixtures/milldale.json');

$state = [
    'counters' => [
        'casserole_ledger' => [
            'viewer_count' => 14,
            'board' => [
                ['handle' => 'dot',     'name' => 'Dot Vance',    'n' => 11],
                ['handle' => 'marisol', 'name' => 'Marisol Ruiz', 'n' => 2],
                ['handle' => 'ruth',    'name' => 'Ruth Amberg',  'n' => 14],
                ['handle' => 'harlan',  'name' => 'Harlan Beck',  'n' => 6],
            ],
        ],
        'thursday_pot' => [
            'viewer_count' => 3,
            'board' => [
                ['handle' => 'pastor_dale', 'name' => 'Pastor Dale Ostrander', 'n' => 9],
                ['handle' => 'harlan',      'name' => 'Harlan Beck',           'n' => 5],
            ],
        ],
    ],
    'boons_due' => [
        ['key' => 'potluck_lead', 'note' => 'asked at the June meeting', 'expires_in_hours' => 40],
        ['key' => 'basement_key'],
    ],
];

// ---------------------------------------------------------------------------
// (a) the bible renders for a default viewer
// ---------------------------------------------------------------------------

$narrator = xeric_render_bible($T, null, 'sfw');
ok('bible: renders non-empty for the default (narrator) viewer', strlen($narrator) > 800, strlen($narrator) . ' bytes');
ok('bible: narrator gets the commons — places, orbits, schedules',
    str_contains($narrator, 'the Bluebird Diner')
    && str_contains($narrator, 'the church basement crowd')
    && str_contains($narrator, 'Ruth Amberg:'));
ok('bible: narrator gets full canon — walled facts included',
    str_contains($narrator, 'five-card draw') && str_contains($narrator, 'green spiral notebook'));
ok('bible: the never-pays-out rumor is rendered as in-world canon, not a stage direction',
    str_contains($narrator, 'Nobody has ever found anything in there'));
ok('bible: the same-entity fixture is not listed twice',
    substr_count($narrator, 'the man behind the register') === 0
    && str_contains($narrator, 'Cy Loomis'));

$ruth = xeric_render_bible($T, ['handle' => 'ruth'], 'sfw');
ok('bible: renders for an ordinary cast viewer', strlen($ruth) > 800);
ok('bible: a private (non-gossip) secret does not reach another cast member',
    !str_contains($ruth, 'glovebox') && str_contains($ruth, 'green spiral notebook'),
    'Ruth keeps her own, Janelle keeps hers');
ok('bible: a gossip-grade secret does travel to cast',
    str_contains($ruth, 'five-card draw'));

// ---------------------------------------------------------------------------
// (b, c) the walled daughter
// ---------------------------------------------------------------------------

$janelle = xeric_render_bible($T, ['handle' => 'janelle'], 'sfw');
$leaked  = leaks($janelle, SECRETS);
ok('wall: the daughter\'s bible contains none of the walled strings', $leaked === [], implode(' | ', $leaked));

$leaked = leaks($janelle, PSYCHE_MARKERS);
ok('wall: the daughter gets no cast interiors at all', $leaked === [], implode(' | ', $leaked));

ok('wall: shown_as framing is present for the daughter',
    str_contains($janelle, 'exactly and only what they look like'));
ok('wall: the daughter still gets the commons',
    str_contains($janelle, 'the Bluebird Diner') && str_contains($janelle, 'Beck Hardware'));
ok('wall: own_bible swaps cast one-liners for surface descriptions',
    str_contains($janelle, 'The woman who owns the diner')
    && !str_contains($janelle, 'Owns the Bluebird, works the counter herself'));
ok('wall: no negative space — no empty section headers where content was cut',
    !str_contains($janelle, 'WHAT THEY CARRY') && !str_contains($janelle, 'THE STRANGE PLACE'));
ok('wall: the walled bible is still a usable prompt, not a stub', strlen($janelle) > 600, strlen($janelle) . ' bytes');

// ---------------------------------------------------------------------------
// (d) fixtures see rooms, not souls
// ---------------------------------------------------------------------------

$cy     = xeric_render_bible($T, ['handle' => 'cy'], 'sfw');
$leaked = leaks($cy, array_merge(SECRETS, PSYCHE_MARKERS));
ok('wall: the fixture viewer sees no cast psyche or secrets', $leaked === [], implode(' | ', $leaked));
ok('wall: the fixture viewer does see places and schedules',
    str_contains($cy, 'the Bluebird Diner')
    && str_contains($cy, 'WHERE THEY ARE')
    && str_contains($cy, 'Pastor Dale Ostrander: Sundays'));
ok('wall: the fixture viewer gets their own framing',
    str_contains($cy, 'A room, its hours, and the people who walk through it.'));

// ---------------------------------------------------------------------------
// (e) the economy renders, with board and podium
// ---------------------------------------------------------------------------

$ruthEco = xeric_render_economy($T, ['handle' => 'ruth'], $state, 'sfw');
ok('economy: renders for an allowed viewer', str_contains($ruthEco, 'THE CASSEROLE LEDGER'));
ok('economy: subconscious framing opens the block',
    str_contains($ruthEco, 'None of this is ever said out loud'));
ok('economy: ground truth renders as flat declarative canon',
    str_contains($ruthEco, 'Walt returns every dish clean.'));
ok('economy: earn rules render', str_contains($ruthEco, 'It counts when: dish delivered, ride given, and driveway shoveled.'));
ok('economy: the viewer\'s own standing renders', str_contains($ruthEco, 'Where you stand right now: 14.'));
ok('economy: the board renders as a podium, sorted, viewer marked',
    str_contains($ruthEco, "Standing:\n  1. Ruth Amberg (you)")
    && str_contains($ruthEco, '2. Dot Vance')
    && str_contains($ruthEco, '3. Harlan Beck'));
ok('economy: podium truncates below its cutoff', !str_contains($ruthEco, 'Marisol'));
ok('economy: a board the viewer cannot see is withheld while the economy still renders',
    str_contains($ruthEco, 'THE THURSDAY POT') && !str_contains($ruthEco, 'Pastor Dale Ostrander'));
ok('economy: non-subconscious framing uses the authored line',
    str_contains($ruthEco, 'Open among the four of them, invisible outside that room.'));
ok('economy: boons render with payout, claim and expiry',
    str_contains($ruthEco, 'BOONS')
    && str_contains($ruthEco, 'three dishes forgiven')
    && str_contains($ruthEco, 'goes stale after 72 hours'));
ok('economy: due boons render from state',
    str_contains($ruthEco, 'Owed right now, unclaimed:')
    && str_contains($ruthEco, 'The potluck lead (asked at the June meeting), 40 hours left.'));

// ---------------------------------------------------------------------------
// (f) the economy is absent for walled viewers
// ---------------------------------------------------------------------------

$janelleEco = xeric_render_economy($T, ['handle' => 'janelle'], $state, 'sfw');
$leaked     = leaks($janelleEco, ['thursday', 'poker', 'the pot never leaves', 'standing seat at the table', 'key to the basement']);
ok('wall: the walled economy is wholly absent for the daughter — no hint of it', $leaked === [], implode(' | ', $leaked));
ok('wall: a boon paying into a walled economy is suppressed with it',
    !str_contains($janelleEco, 'basement') && str_contains($janelleEco, 'potluck lead'));
ok('economy: an unwalled economy still renders for her', str_contains($janelleEco, 'THE CASSEROLE LEDGER'));
ok('economy: due boons never name a boon she cannot see',
    leaks($janelleEco, ['basement key', 'key to the basement']) === []);

$cyEco = xeric_render_economy($T, ['handle' => 'cy'], $state, 'sfw');
ok('wall: economies.* hides the entire render — empty string, not a header', $cyEco === '', var_export($cyEco, true));

// ---------------------------------------------------------------------------
// walls fail closed, not open
// ---------------------------------------------------------------------------

$stranger    = xeric_render_bible($T, ['handle' => 'nobody_by_that_name'], 'sfw');
$strangerEco = xeric_render_economy($T, ['handle' => 'nobody_by_that_name'], $state, 'sfw');
$leaked      = leaks($stranger, array_merge(SECRETS, PSYCHE_MARKERS));
ok('wall: an unresolvable viewer fails closed, not open', $leaked === [], implode(' | ', $leaked));
ok('wall: an unresolvable viewer gets no economies at all', $strangerEco === '');
ok('wall: an unresolvable viewer still gets the commons', str_contains($stranger, 'the Bluebird Diner'));

// ---------------------------------------------------------------------------
// whose story this is — the feeling is commons, the arc is an interior
// ---------------------------------------------------------------------------

$star = $T;
$star['cast']['protagonist'] = [
    'handle'   => 'harlan',
    'arc'      => 'He is deciding whether to sell the hardware store before it decides for him.',
    'pressure' => 'A second letter from the bank he has not opened.',
];
$ARC = ['before it decides for him', 'second letter from the bank'];

$starNarrator = xeric_render_bible($star, null, 'sfw');
ok('protagonist: the narrator reads the whole of it',
    str_contains($starNarrator, 'WHOSE STORY THIS IS')
    && leaks($starNarrator, $ARC) === $ARC);

$starRuth = xeric_render_bible($star, ['handle' => 'ruth'], 'sfw');
ok('protagonist: a cast member no wall touches reads it too', leaks($starRuth, $ARC) === $ARC);

$starCy = xeric_render_bible($star, ['handle' => 'cy'], 'sfw');
ok('protagonist: a wall over drives keeps the framing and takes the arc — the cast still feels it',
    str_contains($starCy, 'Something is moving around Harlan Beck')
    && leaks($starCy, $ARC) === [],
    implode(' | ', leaks($starCy, $ARC)));

$starJanelle = xeric_render_bible($star, ['handle' => 'janelle'], 'sfw');
ok('protagonist: the protected daughter gets no arc',
    leaks($starJanelle, $ARC) === [], implode(' | ', leaks($starJanelle, $ARC)));
ok('protagonist: and no section either — in her world nothing is moving',
    !str_contains($starJanelle, 'WHOSE STORY THIS IS'));

$starStranger = xeric_render_bible($star, ['handle' => 'nobody_by_that_name'], 'sfw');
ok('protagonist: an unresolvable viewer fails closed here too',
    !str_contains($starStranger, 'WHOSE STORY THIS IS') && leaks($starStranger, $ARC) === []);

// The same claim against a REAL forged world. Forged worlds are private and
// gitignored, so this walks whatever is on this machine; on a clean checkout it
// says so rather than passing quietly, and the fixture above is the half that
// is always there.
$forged = [];
foreach (glob(__DIR__ . '/../../worlds/*/world-template.json') ?: [] as $file) {
    $w  = xeric_template_load($file);
    $p  = (array)($w['cast']['protagonist'] ?? []);
    $ph = (string)($p['handle'] ?? '');
    $said = array_values(array_filter([xeric_text($p['arc'] ?? ''), xeric_text($p['pressure'] ?? '')]));
    if ($ph === '' || $said === []) continue;
    foreach ((array)($w['cast']['special_roles'] ?? []) as $sr) {
        if (empty($sr['own_bible'])) continue;
        $forged[] = [basename(dirname($file)), $w, (string)($sr['character'] ?? ''), $ph, $said];
    }
}
if ($forged === []) {
    echo "skip - no forged world with a protected character on this machine\n";
}
foreach ($forged as [$name, $w, $protected, $ph, $said]) {
    $pb = xeric_render_bible($w, ['handle' => $protected], 'sfw');
    ok("protagonist: $name — the protected character's own bible carries no arc",
        leaks($pb, $said) === [] && !str_contains($pb, 'WHOSE STORY THIS IS'),
        implode(' | ', leaks($pb, $said)));

    // And everybody else in that world whose walls take the interiors: the forge
    // has been caught writing drives.pull into cast.protagonist.arc byte for
    // byte, so the two have to travel together or the wall means nothing.
    $bad = [];
    foreach ($w['cast']['characters'] ?? [] as $c) {
        $h = (string)($c['handle'] ?? '');
        if ($h === '') continue;
        $walls = xeric_viewer_walls($w, xeric_viewer($w, ['handle' => $h]));
        if (!xeric_hidden($walls, 'drives.' . $ph)) continue;
        if (leaks(xeric_render_bible($w, ['handle' => $h], 'sfw'), $said) !== []) $bad[] = $h;
    }
    ok("protagonist: $name — nobody walled off the interiors reads the arc", $bad === [], implode(' | ', $bad));
}

// ---------------------------------------------------------------------------
// (g) rating gate
// ---------------------------------------------------------------------------

$GATED = "hasn't cleared its own rent since March";
ok('rating: a mature node is absent at sfw', !str_contains($narrator, $GATED));

$narratorMature = xeric_render_bible($T, null, 'mature');
ok('rating: the same node appears at mature', str_contains($narratorMature, $GATED));
$stripped = implode("\n", array_filter(explode("\n", $narratorMature), fn($l) => !str_contains($l, $GATED)));
ok('rating: the gate removes exactly that node and nothing else', $stripped === $narrator);
ok('rating: an unknown rating string does not unlock anything',
    !str_contains(xeric_render_bible($T, null, 'wide-open'), $GATED));

// ---------------------------------------------------------------------------
// (g2) the child renders, and the sex gate holds above him
//
// The first six checks are the ones to read twice. Theo is twelve and he is on
// the shelf: in the roster with his age on it, in his room on his schedule, in
// the adults' bible, holding the secret a mystery here would turn on, and
// reading a bible of his own with the town's gossip in it exactly as any other
// cast member does. Raising the world's rating does not thin him out. A change
// that quietly removed any of that is the wrong change, whatever it was
// protecting.
//
// The last three are the other half: his ceiling is the weakest rating in a
// world of any rating, and the validator will not load a template that hangs
// content above sfw on him — so there is no gated node under a child for a
// renderer to be careless with in the first place.
// ---------------------------------------------------------------------------

$mature   = $T;
$mature['meta']['rating'] = 'mature';
$kidView  = xeric_viewer($T, ['handle' => 'theo']);
$theo     = xeric_render_bible($T, ['handle' => 'theo'], 'sfw');

ok('child: he is in the roster with his age, his line and his room',
    str_contains($narrator, 'Theo Vance (12)')
    && str_contains($narrator, 'does his homework in the last booth')
    && str_contains($narrator, 'the back booth, homework'));
ok('child: the adults have him in their bible like anybody else',
    str_contains($ruth, 'Theo Vance') && str_contains($ruth, 'Dot\'s grandson'));
ok('child: he holds a secret and it reaches the narrator whole',
    str_contains($narrator, 'fourth-floor stairwell'));
ok('child: he reads a bible of his own — a full one, not a stub',
    strlen($theo) > 800 && str_contains($theo, 'the Bluebird Diner') && str_contains($theo, 'WHERE THEY ARE'),
    strlen($theo) . ' bytes');
ok('child: the town\'s gossip reaches him exactly as it reaches any cast member — no blanket wall',
    str_contains($theo, 'five-card draw') && !str_contains($theo, 'glovebox'));
ok('child: a mature world still has him in it, in full',
    (function () use ($mature) {
        $b = xeric_render_bible($mature, null, 'mature');
        return str_contains($b, 'Theo Vance (12)')
            && str_contains($b, 'fourth-floor stairwell')
            && str_contains($b, 'the back booth, homework');
    })());

ok('child: his ceiling is the weakest rating in a world of any rating',
    xeric_viewer_rating('mature', $kidView) === 'sfw'
    && xeric_viewer_rating('explicit', $kidView) === 'sfw'
    && xeric_viewer_rating('explicit', xeric_viewer($T, ['handle' => 'ruth'])) === 'explicit');
ok('child: rendered at the rating he is entitled to, the mature node is gone — and it is still there for an adult',
    !str_contains(xeric_render_bible($mature, ['handle' => 'theo'], xeric_viewer_rating('mature', $kidView)), $GATED)
    && str_contains(xeric_render_bible($mature, ['handle' => 'ruth'], 'mature'), $GATED));
ok('child: and no loadable world can hang a gate above sfw on him anyway', (function () use ($mature) {
    $bad = $mature;
    $bad['cast']['characters'][5]['secrets'][0]['rating_min'] = 'mature';
    try { xeric_world_validate($bad, 'milldale.json'); } catch (Throwable $e) {
        return str_contains($e->getMessage(), "cast.characters[5].secrets[0].rating_min")
            && str_contains($e->getMessage(), "'theo', who is a minor");
    }
    return false;
})());

// ---------------------------------------------------------------------------
// (h) determinism
// ---------------------------------------------------------------------------

ok('determinism: two bible renders are byte-identical',
    xeric_render_bible($T, ['handle' => 'ruth'], 'sfw') === $ruth);
ok('determinism: two economy renders are byte-identical',
    xeric_render_economy($T, ['handle' => 'ruth'], $state, 'sfw') === $ruthEco);
ok('determinism: a reshuffled board sorts to the same output', (function () use ($T, $state, $ruthEco) {
    $shuffled = $state;
    $shuffled['counters']['casserole_ledger']['board'] = array_reverse($shuffled['counters']['casserole_ledger']['board']);
    return xeric_render_economy($T, ['handle' => 'ruth'], $shuffled, 'sfw') === $ruthEco;
})());
ok('determinism: a re-decoded template renders identically',
    xeric_render_bible(xeric_template_load(__DIR__ . '/../fixtures/milldale.json'), null, 'sfw') === $narrator);

// ---------------------------------------------------------------------------

echo "\n" . ($FAILED === 0 ? "PASS" : "FAIL ($FAILED)") . "\n";
exit($FAILED === 0 ? 0 : 1);
