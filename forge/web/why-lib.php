<?php
/**
 * why-lib.php — what the inspector knows, separated from the page that shows it.
 *
 * One function in here earns the file on its own: xeric_why_system_sections()
 * rebuilds the system prompt out of the same calls prompt.php makes, in the same
 * order, so why.php can put a size on each of them. That reconstruction is only worth
 * anything if it is EXACT, and "exact" is a thing a test can assert — which it
 * cannot do against a page that renders itself the moment it is included.
 *
 * So: the page is why.php, the knowledge is here, and demo-test.php checks every
 * release that the split still reproduces the real message byte for byte. The
 * day prompt.php changes shape, that test fails instead of an inspector quietly
 * showing somebody something adjacent to the truth.
 */

declare(strict_types=1);

require_once __DIR__ . '/play-lib.php';

/** A token-ish size. Honest about being an estimate — no tokenizer lives here. */
function xeric_why_tokens(string $s): int
{
    return (int)ceil(mb_strlen($s) / 4);
}

function xeric_why_bar(int $part, int $whole): string
{
    $pct = $whole > 0 ? max(1, (int)round($part * 100 / $whole)) : 0;
    return '<span class="bar"><i style="width:' . $pct . '%"></i></span><span class="pct">' . $pct . '%</span>';
}

/**
 * The system message, as the pieces prompt.php built it from.
 *
 * Rebuilt by calling the same functions in the same order, then joined the
 * same way ("\n\n", trailing newline). `exact` says whether that reproduced the
 * real string; when it is false the caller must show the real one instead.
 *
 * EVERY BLOCK prompt.php CAN EMIT HAS TO BE HERE, including the ones a fixture
 * world never has. The lessons block was missing, so the inspector declared
 * itself out of date on every world that had ever learned anything — and the
 * per-section sizes quietly excluded the one block that competes with the bible
 * for the model's attention, which is the number this page exists to show.
 *
 * @return array{sections:array,exact:bool}
 */
function xeric_why_system_sections(array $t, PDO $db, string $handle, string $eff, int $memoryLimit, ?int $epoch): array
{
    $viewer = ['handle' => $handle];
    $out = [];

    // THE AGE FLOOR, THE SAME LINE prompt.php:149 APPLIES, BEFORE THE FIRST USE
    // OF $eff. Without it this page rendered a minor's prompt at the WORLD's
    // rating: in a mature world the inspector showed mature-gated tells, moods,
    // secrets and drives under a child viewer that the real system message does
    // not contain. Both halves of that are fatal here — it puts gated content on
    // a screen, and it stops being a reproduction of the prompt, which is the
    // only reason this function exists rather than a second renderer.
    $eff = xeric_viewer_rating($eff, xeric_viewer($t, ['handle' => $handle]));

    $voice = implode("\n", xeric_prompt_voice($t, $handle, $eff));
    $out[] = ['name' => 'who she is, in her own head',
              'note' => 'xeric_prompt_voice(), her own record, read directly. Walls never apply to a person\'s '
                      . 'account of themselves.', 'text' => $voice];

    $rules = implode("\n", xeric_prompt_rules($t, $handle, $eff));
    $out[] = ['name' => 'how she answers',
              'note' => 'xeric_prompt_rules(): the answering rules, plus the rating written as a STYLE at '
                      . 'this viewer\'s own ceiling — a child\'s rules carry TV-G\'s register whatever the '
                      . 'world is rated.', 'text' => $rules];

    $bible = xeric_render_bible($t, $viewer, $eff);
    if (trim($bible) !== '') {
        $out[] = ['name' => 'the xeric, as SHE sees it',
                  'note' => 'xeric_render_bible() with her as the viewer, this is where knowledge walls bite. '
                          . 'It is almost always the biggest block, and everything under it competes with it.',
                  'text' => rtrim($bible)];
    }

    $economy = xeric_render_economy($t, $viewer, xeric_state_counters($db, $t, $handle, $epoch), $eff);
    if (trim($economy) !== '') {
        $out[] = ['name' => 'what is being counted',
                  'note' => 'xeric_render_economy(), only the economies she is allowed to see.',
                  'text' => "WHAT IS BEING COUNTED\n" . rtrim($economy)];
    }

    $lessons = xeric_prompt_lessons($t, $db, $handle);
    if ($lessons !== '') {
        $out[] = ['name' => 'what this xeric has worked out about you',
                  'note' => 'xeric_prompt_lessons(), distilled by learn.php from how this xeric has been '
                          . 'played, and rewritten on the order of days rather than turns. It sits above the '
                          . 'memories because the rare write should be the one that costs the big block.',
                  'text' => $lessons];
    }

    require_once dirname(__DIR__, 2) . '/engine/constructs.php';
    $owed = xeric_expect_block($t, $db, $handle, ['epoch' => $epoch ?? 0]);
    if ($owed !== '') {
        $out[] = ['name' => 'what she is owed, and what she owes',
                  'note' => 'xeric_expect_block(), the constructs door. The promises this character heard '
                          . 'and what became of them, the favours she is carrying (a debt knows what it '
                          . 'was FOR, which is why it is a row and not a number), and what the town has '
                          . 'been saying. Her OWN ledger, so no wall applies. Day-coarse on purpose: it '
                          . 'changes only when a state changes, never per turn.',
                  'text' => $owed];
    }

    $story = xeric_prompt_story($t, $handle);
    if ($story !== '') {
        $out[] = ['name' => 'where she stands on what is going on',
                  'note' => 'xeric_prompt_story(), the lines a story overlay left this speaker holding. Only '
                          . 'xerics with an overlay have one, which is exactly why it was missing here: a block '
                          . 'no fixture emits is a block nobody notices is absent until a real xeric declares '
                          . 'itself out of date.',
                  'text' => $story];
    }

    $memories = xeric_prompt_memories($t, $db, $handle, $memoryLimit);
    if ($memories !== []) {
        $out[] = ['name' => 'what she remembers',
                  'note' => 'xeric_prompt_memories(), newest ' . $memoryLimit . '. LAST on purpose: it is the only '
                          . 'static block that grows, and anything above a growing block gets re-read every time '
                          . 'it grows.',
                  'text' => implode("\n", $memories)];
    }

    $rebuilt = implode("\n\n", array_map(fn($s) => $s['text'], $out)) . "\n";
    return ['sections' => $out, 'rebuilt' => $rebuilt];
}

/** The inspector's own CSS: monospace blocks that never push the page sideways. */
function xeric_why_css(): string
{
    return <<<'CSS'
.tot{border:1px solid var(--accent-dim);border-radius:.6rem;background:#15150f;padding:.7rem .85rem;margin:0 0 1.2rem;font-size:.9rem}
.blk{border:1px solid var(--line);border-radius:.6rem;background:var(--bg-2);padding:.7rem .8rem;margin:0 0 .9rem}
.blk.last{border-color:var(--accent-dim)}
.blkhead{display:flex;gap:.6rem;align-items:baseline;flex-wrap:wrap;margin:0 0 .35rem}
.blkhead .bn{font-weight:600;font-size:.95rem}
.blkhead .bs{margin-left:auto;font-size:.76rem;color:var(--fg-dim);font-variant-numeric:tabular-nums;white-space:nowrap}
.blkbar{display:flex;align-items:center;gap:.5rem;margin:0 0 .45rem}
.bar{flex:1;height:.35rem;background:#221f18;border-radius:.2rem;overflow:hidden}
.bar i{display:block;height:100%;background:var(--accent)}
.pct{font-size:.72rem;color:var(--fg-dim);font-variant-numeric:tabular-nums}
.blknote{font-size:.82rem;margin:0 0 .5rem}
pre.p{margin:0;padding:.6rem .7rem;background:var(--bg);border:1px solid #1d1a14;border-radius:.4rem;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.76rem;line-height:1.55;
  color:var(--fg);white-space:pre-wrap;overflow-wrap:anywhere;max-height:26rem;overflow:auto}
pre.p.vol{border-color:var(--accent-dim)}
.jump{display:flex;flex-wrap:wrap;gap:.4rem .9rem;margin:1.6rem 0 0;font-size:.88rem}
.take{border-left:2px solid var(--accent-dim);padding:.1rem 0 .1rem .7rem;margin:0 0 .6rem}
.take .tn{font-size:.85rem;font-weight:600}
.take .tt{font-size:.9rem;color:var(--fg-dim)}
.cast a.person{text-decoration:none}
CSS;
}
