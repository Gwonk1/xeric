<?php
/**
 * pdf.php — the text out of a PDF, on a machine that has nothing installed.
 *
 * WHY THIS IS NOT JUST pdftotext. Poppler is on virtually every Linux desktop
 * and virtually no Windows one, and Xeric is shipped to both. A feature that
 * works here and prints "install poppler-utils" there is not a feature, it is a
 * platform note — so the fallback is the thing that has to be good, and the
 * binary is the bonus.
 *
 * WHAT IT NEEDS: zlib, which PHP has compiled in by default and which the
 * launcher checks for. Nothing else. No composer, no extension, no binary.
 *
 * WHAT IT READS. A PDF's page content is a stream of drawing operators, and the
 * ones that put text on a page are Tj, TJ, ' and ". Almost every PDF written by
 * a word processor, a browser's print-to-PDF, LaTeX or a report generator uses
 * WinAnsi or Standard encoding, and those come out as text directly.
 *
 * WHAT IT CANNOT READ, said plainly rather than returned as mush:
 *
 *  • A SCAN. There is no text in it to find, only pictures of text. No OCR here
 *    and none planned: it is a large dependency and a slow one, and a person
 *    holding a scan can paste what matters in ten seconds.
 *  • A SUBSET FONT WITH NO ToUnicode MAP. The bytes in the stream are glyph
 *    numbers in somebody's private ordering, and 3 might be "a" or might be a
 *    ligature. That comes back as noise, so the result is checked for being
 *    mostly readable and refused when it is not — a premise built from mojibake
 *    is worse than no premise.
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';        // xeric_web_which(), for the binary when there is one

/** Every content stream in the file, inflated where it was compressed. */
function xeric_pdf_streams(string $raw): array
{
    $out = [];
    $at = 0;
    while (($s = strpos($raw, 'stream', $at)) !== false) {
        // The dictionary immediately before it says how the bytes are encoded.
        $dictFrom = max(0, $s - 700);
        $dict = substr($raw, $dictFrom, $s - $dictFrom);

        $from = $s + 6;
        if (substr($raw, $from, 2) === "\r\n")      $from += 2;
        elseif (in_array(substr($raw, $from, 1), ["\n", "\r"], true)) $from += 1;

        $end = strpos($raw, 'endstream', $from);
        if ($end === false) break;
        $at = $end + 9;

        $body = substr($raw, $from, $end - $from);
        // Anything that is not a content stream is skipped by simply failing to
        // decode or failing to contain text operators; no attempt is made to
        // resolve the object graph, because the object graph is where a PDF
        // reader becomes a PDF library.
        if (str_contains($dict, '/FlateDecode')) {
            if (!function_exists('gzinflate')) continue;   // a PHP built without zlib
            $try = @gzuncompress($body);
            if ($try === false) $try = @gzinflate($body);
            if ($try === false) $try = @gzinflate(substr($body, 2));
            if ($try === false) continue;
            $body = $try;
        } elseif (preg_match('#/(DCTDecode|JPXDecode|CCITTFaxDecode|JBIG2Decode|RunLengthDecode|LZWDecode)#', $dict)) {
            continue;                     // a picture, or a compression we do not do
        }
        if ($body !== '') $out[] = $body;
    }
    return $out;
}

/** A PDF literal string, unescaped. */
function xeric_pdf_unescape(string $s): string
{
    $out = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c !== '\\') { $out .= $c; continue; }
        $next = $s[++$i] ?? '';
        switch ($next) {
            case 'n': $out .= "\n"; break;
            case 'r': $out .= "\r"; break;
            case 't': $out .= "\t"; break;
            case 'b': $out .= "\x08"; break;
            case 'f': $out .= "\x0c"; break;
            case "\n": break;                     // a line continuation
            case "\r": if (($s[$i + 1] ?? '') === "\n") $i++; break;
            default:
                if (ctype_digit($next)) {         // \ooo, one to three octal digits
                    $oct = $next;
                    while (strlen($oct) < 3 && ctype_digit($s[$i + 1] ?? '')) $oct .= $s[++$i];
                    $out .= chr(octdec($oct) & 0xff);
                } else {
                    $out .= $next;                // \( \) \\ and anything else literal
                }
        }
    }
    return $out;
}

/**
 * The text drawn by one content stream.
 *
 * TJ arrays carry kerning numbers between their strings, and a large positive
 * one is how a PDF writes a space it did not store as a character. 100 is the
 * usual threshold in thousandths of an em; below it the gap is letter-spacing.
 */
function xeric_pdf_ops(string $content): string
{
    $out  = '';
    $pend = '';          // strings read but not yet placed
    $n = strlen($content);
    $i = 0;

    // THE OPERATOR COMES AFTER ITS OPERANDS, so a string cannot be placed until
    // the thing that places it has been read. `(B)'` means "move to a new line,
    // THEN show B", and emitting the break when the quote is finally reached put
    // it after B instead of before it — which joined the first line of every
    // document to the second and got everything after that right, the most
    // annoying kind of wrong.
    $flush = function (string $before = '', string $after = '') use (&$out, &$pend): void {
        if ($pend === '') { $out .= $after; return; }
        $out .= $before . $pend . $after;
        $pend = '';
    };

    while ($i < $n) {
        $c = $content[$i];

        if ($c === '(') {                                   // a literal string
            $depth = 1; $j = $i + 1; $buf = '';
            while ($j < $n && $depth > 0) {
                $ch = $content[$j];
                if ($ch === '\\') { $buf .= $ch . ($content[$j + 1] ?? ''); $j += 2; continue; }
                if ($ch === '(') $depth++;
                if ($ch === ')') { $depth--; if ($depth === 0) break; }
                $buf .= $ch; $j++;
            }
            $pend .= xeric_pdf_unescape($buf);
            $i = $j + 1;
            continue;
        }

        if ($c === '<' && ($content[$i + 1] ?? '') !== '<') { // a hex string
            $j = strpos($content, '>', $i);
            if ($j === false) break;
            $hex = preg_replace('/[^0-9a-fA-F]/', '', substr($content, $i + 1, $j - $i - 1)) ?? '';
            if (strlen($hex) % 2 === 1) $hex .= '0';
            $pend .= (string)@hex2bin($hex);
            $i = $j + 1;
            continue;
        }

        // A kerning number inside a TJ array. A large negative one is how a PDF
        // writes a space it did not store as a character.
        if ($c === '-' || ctype_digit($c)) {
            $j = $i;
            while ($j < $n && (ctype_digit($content[$j]) || $content[$j] === '-' || $content[$j] === '.')) $j++;
            if ((float)substr($content, $i, $j - $i) <= -100) $pend .= ' ';
            $i = $j;
            continue;
        }

        // ' and " are "next line, THEN show", so the break goes in front.
        if ($c === "'" || $c === '"') { $flush("\n"); $i++; continue; }

        if ($c === 'T' && ($content[$i + 1] ?? '') === 'j') { $flush(); $i += 2; continue; }
        if ($c === 'T' && ($content[$i + 1] ?? '') === 'J') { $flush(); $i += 2; continue; }

        // Moving the cursor ends a line; so does closing the text object.
        if ($c === 'T' && in_array($content[$i + 1] ?? '', ['d', 'D', '*'], true)) { $flush('', "\n"); $i += 2; continue; }
        if ($c === 'E' && ($content[$i + 1] ?? '') === 'T') { $flush('', "\n"); $i += 2; continue; }

        $i++;
    }
    $flush();
    return $out;
}

/**
 * Every ToUnicode map in the file, as code => character.
 *
 * THIS IS THE DIFFERENCE BETWEEN READING PDFs AND READING PDFs FROM THIS BOX.
 * A modern writer — Chromium's print-to-PDF, Word, anything that subsets its
 * fonts — stores glyph numbers in its own private order, so the bytes in the
 * content stream are not letters. Chromium's happen to sit 29 below the real
 * character, which is how "The Hollows" arrives as "7KH+ROORZV": readable
 * enough to fool a check for "is this mostly letters", and complete nonsense.
 *
 * A PDF that does this is REQUIRED to ship the map back, in a /ToUnicode CMap,
 * and that is what this reads.
 *
 * ALL OF THEM MERGED, because working out which font is current means resolving
 * the object graph, and the object graph is where a PDF reader becomes a PDF
 * library. Two subset fonts CAN disagree about a code; the scorer below is what
 * catches it when they do, by preferring whichever reading looks more like
 * prose.
 */
function xeric_pdf_cmaps(array $streams): array
{
    $map = [];
    foreach ($streams as $s) {
        if (!str_contains($s, 'beginbfchar') && !str_contains($s, 'beginbfrange')) continue;

        // <src> <dst>
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $s, $blocks)) {
            foreach ($blocks[1] as $b) {
                if (!preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $b, $m, PREG_SET_ORDER)) continue;
                foreach ($m as $pair) $map[strtolower($pair[1])] = xeric_pdf_utf16($pair[2]);
            }
        }
        // <from> <to> <dst>, the destination walking up with the source
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $s, $blocks)) {
            foreach ($blocks[1] as $b) {
                if (!preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $b, $m, PREG_SET_ORDER)) continue;
                foreach ($m as $r) {
                    $from = hexdec($r[1]); $to = hexdec($r[2]); $dst = hexdec($r[3]);
                    if ($to < $from || $to - $from > 65535) continue;
                    $w = strlen($r[1]);
                    for ($c = $from; $c <= $to; $c++) {
                        $map[strtolower(str_pad(dechex($c), $w, '0', STR_PAD_LEFT))] =
                            xeric_pdf_utf16(str_pad(dechex($dst + ($c - $from)), 4, '0', STR_PAD_LEFT));
                    }
                }
            }
        }
    }
    return $map;
}

/** A UTF-16BE hex run, as UTF-8. */
function xeric_pdf_utf16(string $hex): string
{
    if (strlen($hex) % 2 === 1) $hex = '0' . $hex;
    $bin = (string)@hex2bin($hex);
    if ($bin === '') return '';
    $u = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
    return is_string($u) ? $u : '';
}

/** Read a run of bytes through a ToUnicode map, one or two bytes at a time. */
function xeric_pdf_map(string $s, array $map, int $width): string
{
    $out = '';
    $n = strlen($s);
    for ($i = 0; $i + $width <= $n; $i += $width) {
        $code = strtolower(bin2hex(substr($s, $i, $width)));
        $out .= $map[$code] ?? '';
    }
    return $out;
}

/**
 * How much this reads like English prose, per hundred characters.
 *
 * A COUNT OF THE COMMONEST WORDS, because that is the one thing mojibake cannot
 * fake: a shifted alphabet keeps the letter frequencies and the word lengths and
 * loses every actual word. It is the tie-breaker between a mapped reading and an
 * unmapped one, and the floor under both.
 */
function xeric_pdf_score(string $s): float
{
    $s = ' ' . mb_strtolower($s) . ' ';
    $len = max(100, mb_strlen($s));
    $hits = 0;
    foreach (['the', 'and', 'that', 'with', 'for', 'was', 'not', 'you', 'this', 'from',
              'have', 'are', 'his', 'her', 'they', 'but', 'has', 'been', 'were', 'said',
              ' a ', ' i ', ' in ', ' of ', ' to ', ' is ', ' it ', ' on ', ' at ', ' as '] as $w) {
        $hits += substr_count($s, str_starts_with($w, ' ') ? $w : ' ' . $w . ' ');
    }
    return $hits / ($len / 100);
}

/**
 * Does this look like language, or like glyph numbers?
 *
 * The failure mode of a subset font with no ToUnicode map is a string of
 * plausible-looking bytes that are not letters, and handing that to a model as
 * somebody's premise is worse than admitting the file could not be read.
 */
function xeric_pdf_readable(string $s): bool
{
    $s = trim($s);
    if (mb_strlen($s) < 20) return false;

    $letters = preg_match_all('/\p{L}/u', $s);
    $total   = max(1, mb_strlen(preg_replace('/\s+/u', '', $s) ?? ''));
    if ($letters / $total < 0.55) return false;

    // Real prose has spaces in it. A glyph dump usually does not.
    $words = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return count($words) >= 5;
}

/**
 * The text of a PDF, however this machine can get it.
 *
 * @return array{text:string,how:string} how is 'pdftotext' or 'php', for the log
 */
function xeric_pdf_text(string $path): array
{
    // The binary first WHERE IT EXISTS, because poppler handles encodings and
    // column layouts that the reader below does not pretend to.
    $bin = xeric_web_which('pdftotext');
    if ($bin !== '') {
        // escapeshellarg, NOT escapeshellcmd: the latter escapes metacharacters
        // and leaves spaces alone, so "C:\\Program Files\\poppler\\pdftotext.exe"
        // would have run as two arguments. And no 2>/dev/null, which on cmd.exe
        // means a file called \\dev\\null and takes the whole command with it —
        // shell_exec already drops stderr from what it returns.
        $cmd = escapeshellarg($bin) . ' -q -layout -enc UTF-8 ' . escapeshellarg($path) . ' -';
        $t = xeric_pdf_tidy((string)@shell_exec($cmd));
        if (xeric_pdf_readable($t)) return ['text' => $t, 'how' => 'pdftotext'];
    }

    $raw = (string)@file_get_contents($path);
    if ($raw === '' || !str_starts_with($raw, '%PDF')) return ['text' => '', 'how' => 'php'];

    // BT IS THE DISCRIMINATOR. Every stream that draws text opens a text object
    // with it, and nothing else in a PDF does — where "does it contain a quote"
    // let the XMP metadata block through, and a page of XML namespaces dragged
    // the whole result below the readability check. A file with perfectly good
    // prose in it came back as unreadable because of its own metadata.
    $streams = xeric_pdf_streams($raw);

    $text = '';
    foreach ($streams as $s) {
        if (!str_contains($s, 'BT')) continue;
        if (str_contains($s, '<?xpacket') || str_contains($s, 'xmpmeta')) continue;
        $text .= xeric_pdf_ops($s) . "\n";
    }

    // THREE READINGS, AND THE ONE THAT LOOKS MOST LIKE PROSE WINS. The bytes as
    // they stand are right for a document using standard encodings; through the
    // ToUnicode map they are right for one that subsets its fonts; and the map
    // may be keyed on one-byte or two-byte codes. Nothing in the stream says
    // which without resolving the object graph, so all three are read and the
    // scorer picks — which also means a merged map that got a code wrong loses
    // to the reading that did not.
    $best = xeric_pdf_tidy($text);
    $score = xeric_pdf_score($best);

    $map = xeric_pdf_cmaps($streams);
    if ($map !== []) {
        foreach ([1, 2] as $width) {
            $cand = xeric_pdf_tidy(xeric_pdf_map_text($text, $map, $width));
            $sc = xeric_pdf_score($cand);
            if ($sc > $score) { $best = $cand; $score = $sc; }
        }
    }

    // A FLOOR UNDER ALL OF THEM. Mojibake keeps the letter frequencies and the
    // word lengths and loses every actual word, so it reads as language to any
    // check that counts letters — and "The Hollows" arriving as "7KH+ROORZV"
    // would have gone to the model as somebody's premise.
    if (!xeric_pdf_readable($best) || $score < 0.8) return ['text' => '', 'how' => 'php'];
    return ['text' => $best, 'how' => 'php'];
}

/**
 * The same text, read through a ToUnicode map.
 *
 * Newlines the operators put in are kept as they are: they came from the page's
 * layout, not from the font, so they must not be looked up as glyphs.
 */
function xeric_pdf_map_text(string $text, array $map, int $width): string
{
    $out = '';
    foreach (explode("\n", $text) as $i => $line) {
        if ($i > 0) $out .= "\n";
        $out .= xeric_pdf_map($line, $map, $width);
    }
    return $out;
}

/** Collapse the whitespace a page layout leaves behind. */
function xeric_pdf_tidy(string $s): string
{
    $s = (string)preg_replace('/\r\n?/', "\n", $s);
    $s = (string)preg_replace('/[ \t]+/', ' ', $s);
    $s = (string)preg_replace('/ *\n */', "\n", $s);
    $s = (string)preg_replace('/\n{3,}/', "\n\n", $s);
    // Anything that survived as a control character was never text.
    $s = (string)preg_replace('/[^\P{C}\n]/u', '', $s);
    return trim($s);
}
