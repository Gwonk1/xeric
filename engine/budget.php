<?php
/**
 * budget.php — what a world may spend in a day, as a number its owner chose.
 *
 * THE METER IS A RECEIPT; THIS IS A CEILING. The two count the same calls and
 * answer different questions, so they are kept in different places:
 *
 *   THE METER (forge/web/boot.php's sinks) is PER SESSION. It says what this
 *   visitor has spent, everywhere, at each machine — "tokens wasted" — and it
 *   lives in the session file because that is whose bill it is. It never
 *   refuses anything.
 *
 *   THE LEDGER (here, the `spend` table) is PER WORLD. It lives in the world's
 *   own database, beside the hours it paid for, and says what THIS world has
 *   spent TODAY. It is what the heart, the reapers, the look button, the voice
 *   and the ears read before they spend. A fork is its own database and so its
 *   own ledger; the owner's ceiling (the template's `budget` block) travels
 *   with the template.
 *
 * Every metered call lands in both: the web layer wraps each meter sink in
 * xeric_budget_sink(), which writes into whichever world this process last
 * opened (xeric_budget_bind, called by the play layer's one door). The engine
 * itself writes nothing to the ledger on its own — it only reads it before a
 * spend — so a CLI with no sinks has no ledger and no ceiling bites there.
 *
 * A DAY IS THE WORLD'S DATE, not the wall clock's. The heart lives world-hours
 * overnight and a skip lives six of them in a minute: a ceiling on the wall
 * clock would let a world that lives a week in one night spend a week's worth
 * against one day's allowance, or starve its Tuesday because Monday's wall day
 * has not ended. What the owner bought is "this much living per day of the
 * world's life", so the ledger is keyed by xeric_clock_now()'s local date.
 *
 * METERED ONLY, BY DEFAULT. `metered_only: true` (the default) means the
 * ceiling applies only to spend on a costly machine — a remote model, a
 * remote scene, voice or ears machine (xeric_*_costly). The owner's own
 * electricity is never rationed unless the owner says `metered_only: false`.
 *
 * A ZERO IS NO CEILING. Every default is 0, so a world without the block — or
 * with the block as WORLD_TEMPLATE.md prints it — behaves exactly as it did
 * before this file existed. Nothing here widens anything: the rating, consent
 * and owner doors are where they were, and a budget only ever says no.
 *
 * FAIL CLOSED. When a ceiling applies and the ledger cannot be read, the
 * answer is no: a budget that lets everything through when it is broken is
 * not a budget.
 */

declare(strict_types=1);

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/clock.php';
require_once __DIR__ . '/llm.php';      // xeric_llm_host_local: one answer to "is this spend free?"

/** The block's knobs, kind => template key. */
const XERIC_BUDGET_KINDS = [
    'tokens'   => 'tokens_per_day',
    'renders'  => 'renders_per_day',
    'clips'    => 'clips_per_day',
    'hearings' => 'hearings_per_day',
];

/** The words a refusal uses for each kind. */
const XERIC_BUDGET_WORDS = [
    'tokens'   => ['token', 'tokens'],
    'renders'  => ['render', 'renders'],
    'clips'    => ['clip', 'clips'],
    'hearings' => ['hearing', 'hearings'],
];

/** A budget said no. The verdict (xeric_budget_allows) rides along. */
final class XericBudgetOut extends RuntimeException
{
    public array $verdict;

    public function __construct(array $verdict, int $code = 429)
    {
        $this->verdict = $verdict;
        parent::__construct((string)($verdict['reason'] ?? 'this world\'s budget for today is spent'), $code);
    }
}

/**
 * The template's `budget` block, with defaults that mean "no ceiling".
 *
 * @return array{tokens_per_day:int,renders_per_day:int,clips_per_day:int,hearings_per_day:int,metered_only:bool}
 */
function xeric_budget(array $t): array
{
    $b   = is_array($t['budget'] ?? null) ? $t['budget'] : [];
    $out = [];
    foreach (XERIC_BUDGET_KINDS as $key) {
        $out[$key] = is_numeric($b[$key] ?? null) ? max(0, (int)$b[$key]) : 0;
    }
    // Only an explicit false rations the owner's own machine.
    $out['metered_only'] = ($b['metered_only'] ?? true) !== false;
    return $out;
}

/** Does this world set any ceiling at all? */
function xeric_budget_any(array $t): bool
{
    $b = xeric_budget($t);
    foreach (XERIC_BUDGET_KINDS as $key) if ($b[$key] > 0) return true;
    return false;
}

/** The ledger's day: the world's own local date, YYYY-MM-DD. */
function xeric_budget_day(array $now): string
{
    $iso = (string)($now['iso'] ?? '');
    if (preg_match('/^-?\d{4,}-\d{2}-\d{2}/', $iso, $m)) return $m[0];
    return gmdate('Y-m-d', (int)($now['epoch'] ?? 0));
}

/**
 * What this world has spent on its current date.
 *
 * @param bool $costlyOnly only what was spent on costly machines
 * @return array{tokens:int,renders:int,clips:int,hearings:int}
 * @throws Throwable when the ledger cannot be read — the caller decides
 */
function xeric_budget_spent(PDO $db, array $now, bool $costlyOnly = false): array
{
    $out = array_fill_keys(array_keys(XERIC_BUDGET_KINDS), 0);
    $q = $db->prepare('SELECT kind, SUM(count) AS n FROM spend WHERE day = ?'
                    . ($costlyOnly ? ' AND costly = 1' : '') . ' GROUP BY kind');
    $q->execute([xeric_budget_day($now)]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k = (string)$row['kind'];
        if (array_key_exists($k, $out)) $out[$k] = (int)$row['n'];
    }
    $q->closeCursor();
    return $out;
}

/** "this world's clip budget for today is spent (40 a day)" */
function xeric_budget_sentence(string $kind, int $ceiling): string
{
    $w = XERIC_BUDGET_WORDS[$kind] ?? [$kind, $kind];
    return "this world's " . $w[0] . ' budget for today is spent (' . number_format($ceiling) . ' '
         . $w[1] . ' a day); it comes back when the world\'s date turns over';
}

/**
 * May this world spend $amount of $kind now?
 *
 * $amount is what the caller knows it is about to spend — 1 for a render, a
 * clip or a hearing. Tokens are not known before the model answers, so a
 * token check asks for 1: "is there anything left", and the call that crosses
 * the line is the last one.
 *
 * @return array{ok:bool,left:int,ceiling:int,reason:string} left is PHP_INT_MAX
 *         when no ceiling applies (none set, or local spend under metered_only)
 */
function xeric_budget_allows(array $t, PDO $db, array $now, string $kind, int $amount, bool $costly): array
{
    $b   = xeric_budget($t);
    $key = XERIC_BUDGET_KINDS[$kind] ?? null;
    if ($key === null) {
        return ['ok' => false, 'left' => 0, 'ceiling' => 0, 'reason' => "there is no budget for '$kind'"];
    }
    $ceiling = $b[$key];
    // No ceiling, or the owner's own electricity under metered_only: the
    // ledger is not even read, so a world without a budget pays nothing for it.
    if ($ceiling <= 0 || ($b['metered_only'] && !$costly)) {
        return ['ok' => true, 'left' => PHP_INT_MAX, 'ceiling' => $ceiling, 'reason' => ''];
    }
    try {
        $spent = xeric_budget_spent($db, $now, $b['metered_only'])[$kind];
    } catch (Throwable $e) {
        return ['ok' => false, 'left' => 0, 'ceiling' => $ceiling,
                'reason' => "this world's ledger cannot be read, so nothing that spends is let through until it can"];
    }
    $left = max(0, $ceiling - $spent);
    $ok   = $left >= max(1, $amount);
    return ['ok' => $ok, 'left' => $left, 'ceiling' => $ceiling,
            'reason' => $ok ? '' : xeric_budget_sentence($kind, $ceiling)];
}

/** Write a spend into the world's ledger, on its current date. */
function xeric_budget_note(PDO $db, array $now, string $kind, int $amount, bool $costly = true): void
{
    if ($amount <= 0 || !array_key_exists($kind, XERIC_BUDGET_KINDS)) return;
    $at = xeric_state_time();
    $db->prepare('INSERT INTO spend (day, kind, costly, count, created, updated) VALUES (?, ?, ?, ?, ?, ?)
                  ON CONFLICT(day, kind, costly) DO UPDATE SET count = count + excluded.count,
                                                               updated = excluded.updated')
       ->execute([xeric_budget_day($now), $kind, $costly ? 1 : 0, $amount, $at, $at]);
}

// ---------------------------------------------------------------------------
// THE BOUND WORLD. The meters report a call and where it went, and nothing
// about which world it was for — an llm call is twelve layers below anything
// that holds a database. So the process remembers the world it opened last
// (one request is one world; the heart rebinds as it walks them), and the
// sink writes there.
// ---------------------------------------------------------------------------

/** @return ?array{db:PDO,t:array} */
function &xeric_budget_slot(): ?array
{
    static $slot = null;
    return $slot;
}

/** This process now spends for this world (null lets go). */
function xeric_budget_bind(?PDO $db, ?array $t = null): void
{
    $slot = &xeric_budget_slot();
    $slot = ($db !== null && $t !== null) ? ['db' => $db, 't' => $t] : null;
}

/** @return ?array{db:PDO,t:array} */
function xeric_budget_bound(): ?array
{
    return xeric_budget_slot();
}

/** Does spend at this address cost money? Loopback and stubs do not. */
function xeric_budget_costly_at(string $where): bool
{
    // xeric_llm_host_local is the one host test (engine/llm.php); a second
    // copy here normalised differently from xeric_image_costly's, so the
    // ledger and the check could disagree about LOCALHOST or [::1].
    return !xeric_llm_host_local($where);
}

/** Is this model endpoint a costly one? A stub never is. */
function xeric_budget_llm_costly(?array $ep): bool
{
    if ($ep === null || isset($ep['stub'])) return false;
    return xeric_budget_costly_at((string)($ep['base'] ?? ''));
}

/** How much of $kind one meter report is. */
function xeric_budget_amount(string $kind, array $u): int
{
    switch ($kind) {
        case 'tokens':
            return max(0, (int)($u['prompt_tokens'] ?? 0)) + max(0, (int)($u['completion_tokens'] ?? 0));
        case 'renders':
            return max(1, (int)($u['scenes'] ?? $u['images'] ?? 1));
        case 'clips':
            return max(1, (int)($u['clips'] ?? 1));
        case 'hearings':
            return max(1, (int)($u['hearings'] ?? 1));
    }
    return 0;
}

/**
 * A meter sink that also lands in the bound world's ledger.
 *
 * Wraps the session meter the web layer already registers ($meter, called
 * first and exactly as before) and then writes the same call into the world's
 * ledger, on the world's date, marked costly by where it went. Bookkeeping
 * never fails the call it counts: anything that throws here is swallowed.
 */
function xeric_budget_sink(string $kind, ?callable $meter = null): callable
{
    return function (array $u, string $where = '') use ($kind, $meter): void {
        if ($meter !== null) $meter($u, $where);
        $w = xeric_budget_bound();
        if ($w === null) return;
        try {
            $n = xeric_budget_amount($kind, $u);
            if ($n > 0) xeric_budget_note($w['db'], xeric_clock_now($w['db'], $w['t']), $kind, $n,
                                          xeric_budget_costly_at($where));
        } catch (Throwable $e) {
            // The spend happened; the ledger missing it is not the caller's fault.
        }
    };
}

/** xeric_budget_allows, against the bound world; always ok when none is bound. */
function xeric_budget_bound_allows(string $kind, int $amount, bool $costly): array
{
    $w = xeric_budget_bound();
    if ($w === null) return ['ok' => true, 'left' => PHP_INT_MAX, 'ceiling' => 0, 'reason' => ''];
    try {
        $now = xeric_clock_now($w['db'], $w['t']);
    } catch (Throwable $e) {
        $now = ['epoch' => time(), 'iso' => gmdate('c')];
    }
    return xeric_budget_allows($w['t'], $w['db'], $now, $kind, $amount, $costly);
}

/** Throw the refusal when the verdict is no. */
function xeric_budget_enforce(array $verdict, int $code = 429): void
{
    if (!($verdict['ok'] ?? false)) throw new XericBudgetOut($verdict, $code);
}

// ---------------------------------------------------------------------------
// WHAT THE OWNER SEES.
// ---------------------------------------------------------------------------

/**
 * Today's spend against each ceiling that exists, kind => [spent, ceiling].
 * Counts what the ceiling counts (costly only, under metered_only). Empty when
 * no ceiling is set or the ledger will not read.
 */
function xeric_budget_today(array $t, PDO $db, array $now): array
{
    $b = xeric_budget($t);
    if (!xeric_budget_any($t)) return [];
    try {
        $spent = xeric_budget_spent($db, $now, $b['metered_only']);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach (XERIC_BUDGET_KINDS as $kind => $key) {
        if ($b[$key] > 0) $out[$kind] = ['spent' => $spent[$kind], 'ceiling' => $b[$key]];
    }
    return $out;
}

/** "today: 1,204 of 200,000 tokens, 3 of 20 renders" — '' with no ceiling. */
function xeric_budget_line(array $t, PDO $db, ?array $now = null): string
{
    try {
        $now ??= xeric_clock_now($db, $t);
    } catch (Throwable $e) {
        return '';
    }
    $parts = [];
    foreach (xeric_budget_today($t, $db, $now) as $kind => $r) {
        $parts[] = number_format($r['spent']) . ' of ' . number_format($r['ceiling']) . ' '
                 . XERIC_BUDGET_WORDS[$kind][1];
    }
    return $parts === [] ? '' : 'today: ' . implode(', ', $parts);
}

/**
 * The heart stopped living hours here because a ceiling was reached: written
 * down (world_state 'budget.stopped') so the inspector can say why the world
 * is standing still while its clock moves.
 */
function xeric_budget_stopped(PDO $db, array $now, array $verdict, string $kind = 'tokens'): void
{
    xeric_world_state_set($db, 'budget.stopped', json_encode([
        'day' => xeric_budget_day($now), 'kind' => $kind, 'at' => (int)($now['epoch'] ?? 0),
        'hhmm' => (string)($now['hhmm'] ?? ''), 'reason' => (string)($verdict['reason'] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Why the world stopped living today, in a sentence; null when it did not. */
function xeric_budget_why(PDO $db, array $now): ?string
{
    $raw = xeric_world_state_get($db, 'budget.stopped');
    $j   = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j) || (string)($j['day'] ?? '') !== xeric_budget_day($now)) return null;
    return 'The world stopped living hours at ' . (string)($j['hhmm'] ?? '?') . ': '
         . (string)($j['reason'] ?? 'its budget for today is spent') . '.';
}

/**
 * A refusal, as a JSON body an endpoint answers with: the sentence (with its
 * capital and full stop), kind 'budget', and the ceiling it ran into.
 */
function xeric_budget_body(array $verdict): array
{
    $why = trim((string)($verdict['reason'] ?? '')) ?: "this world's budget for today is spent";
    return ['error' => ucfirst($why) . '.', 'kind' => 'budget', 'ceiling' => (int)($verdict['ceiling'] ?? 0),
            'left' => 0];
}
