<?php
/**
 * Xeric — QR codes, in PHP, with no dependencies.
 *
 * WHY WRITE THIS AT ALL. The one thing standing between a xeric and the phone
 * in somebody's pocket is typing `http://192.168.1.34:8787/play.php?w=…` with
 * two thumbs. A QR fixes that — and every ordinary way of getting one (an
 * image service, a composer package, a CDN script) either phones a stranger
 * or adds a build step, and this project promises neither. So: the encoder,
 * small, exact, and answering one narrow question.
 *
 * DELIBERATELY NARROW. Byte mode, error correction L, mask 0, versions 1-10 —
 * enough for a LAN URL with room to spare, and nothing else. No kanji mode, no
 * micro QR, no structured append. A URL is bytes; that is the whole job.
 *
 * The encoder is written against the spec's own vocabulary so it can be read
 * beside it: codewords, a Reed-Solomon remainder over GF(256), the function
 * patterns laid down before the data snakes up and down the columns, and the
 * format bits stamped last. The suite checks the finished matrix against a
 * reference encoder, module for module, which is the only test that means
 * anything here — a QR that is almost right is a QR that does not scan.
 *
 * PHP 8.2+.
 */

declare(strict_types=1);

/** Data codeword capacity at ECC level L, versions 1-10. */
function xeric_qr_caps(): array
{
    return [1 => 19, 2 => 34, 3 => 55, 4 => 80, 5 => 108,
            6 => 136, 7 => 156, 8 => 194, 9 => 232, 10 => 274];
}

/** ECC codewords per block at L, and the block layout, versions 1-10. */
function xeric_qr_ecc(): array
{
    // [ecc per block, [[count, data codewords], …]]
    return [
        1  => [7,  [[1, 19]]],
        2  => [10, [[1, 34]]],
        3  => [15, [[1, 55]]],
        4  => [20, [[1, 80]]],
        5  => [26, [[1, 108]]],
        6  => [18, [[2, 68]]],
        7  => [20, [[2, 78]]],
        8  => [24, [[2, 97]]],
        9  => [30, [[2, 116]]],
        10 => [18, [[2, 68], [2, 69]]],
    ];
}

/** GF(256) log/antilog tables for the Reed-Solomon arithmetic. */
function xeric_qr_gf(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11d;          // the QR generator polynomial
    }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = [$exp, $log];
}

/** The Reed-Solomon remainder — the error correction codewords themselves. */
function xeric_qr_rs(array $data, int $n): array
{
    [$exp, $log] = xeric_qr_gf();

    // The generator polynomial for n ECC codewords.
    $gen = [1];
    for ($i = 0; $i < $n; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $c) {
            $next[$j]     ^= $c;
            $next[$j + 1] ^= $c === 0 ? 0 : $exp[($log[$c] + $i) % 255];
        }
        $gen = $next;
    }

    $rem = array_merge($data, array_fill(0, $n, 0));
    for ($i = 0; $i < count($data); $i++) {
        $lead = $rem[$i];
        if ($lead === 0) continue;
        for ($j = 0; $j < count($gen); $j++) {
            $rem[$i + $j] ^= $gen[$j] === 0 ? 0 : $exp[($log[$gen[$j]] + $log[$lead]) % 255];
        }
    }
    return array_slice($rem, count($data), $n);
}

/** Where the alignment patterns go, per version. */
function xeric_qr_align(int $v): array
{
    static $rows = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
                    6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
                    10 => [6, 28, 50]];
    return $rows[$v] ?? [];
}

/**
 * The matrix for a string: [[0|1, …], …], true modules are dark.
 *
 * Byte mode, ECC L, mask 0 — the fixed choices this file exists to make. The
 * caller gets a square array and decides what a module looks like.
 *
 * @throws RuntimeException when the text will not fit version 10 at L
 */
function xeric_qr_matrix(string $text): array
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    $len   = count($bytes);

    // The smallest version that holds it. The 4-bit mode indicator plus an
    // 8-bit length (versions 1-9) or 16-bit (10+) ride in front of the data.
    $version = 0;
    foreach (xeric_qr_caps() as $v => $cap) {
        $need = 4 + ($v < 10 ? 8 : 16) + $len * 8;
        if ($need <= $cap * 8) { $version = $v; break; }
    }
    if ($version === 0) throw new RuntimeException('qr: that is too long for this encoder');

    // -- the bit stream ------------------------------------------------------
    $bits = '0100';                                            // byte mode
    $bits .= str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);

    $capBits = xeric_qr_caps()[$version] * 8;
    $bits   .= str_repeat('0', min(4, $capBits - strlen($bits)));   // terminator
    if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);

    $words = [];
    foreach (str_split($bits, 8) as $byte) $words[] = bindec($byte);
    // The pad alternation the spec names, until the version is full.
    $pad = [0xEC, 0x11];
    $i = 0;
    while (count($words) < xeric_qr_caps()[$version]) $words[] = $pad[$i++ % 2];

    // -- blocks, and their remainders ---------------------------------------
    [$eccLen, $layout] = xeric_qr_ecc()[$version];
    $dataBlocks = [];
    $eccBlocks  = [];
    $at = 0;
    foreach ($layout as [$count, $dataLen]) {
        for ($c = 0; $c < $count; $c++) {
            $block = array_slice($words, $at, $dataLen);
            $at += $dataLen;
            $dataBlocks[] = $block;
            $eccBlocks[]  = xeric_qr_rs($block, $eccLen);
        }
    }
    // Interleaved, column-wise across the blocks: data then ECC.
    $final = [];
    $maxData = max(array_map('count', $dataBlocks));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($dataBlocks as $b) if (isset($b[$i])) $final[] = $b[$i];
    }
    for ($i = 0; $i < $eccLen; $i++) {
        foreach ($eccBlocks as $b) if (isset($b[$i])) $final[] = $b[$i];
    }

    // -- the canvas ----------------------------------------------------------
    $size = $version * 4 + 17;
    $m    = array_fill(0, $size, array_fill(0, $size, null));   // null = free

    $finder = function (int $r, int $c) use (&$m, $size): void {
        for ($i = -1; $i <= 7; $i++) {
            for ($j = -1; $j <= 7; $j++) {
                $y = $r + $i; $x = $c + $j;
                if ($y < 0 || $x < 0 || $y >= $size || $x >= $size) continue;
                $on = ($i >= 0 && $i <= 6 && ($j === 0 || $j === 6))
                   || ($j >= 0 && $j <= 6 && ($i === 0 || $i === 6))
                   || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4);
                $m[$y][$x] = $on ? 1 : 0;
            }
        }
    };
    $finder(0, 0); $finder(0, $size - 7); $finder($size - 7, 0);

    // Timing.
    for ($i = 8; $i < $size - 8; $i++) {
        $m[6][$i] = $i % 2 === 0 ? 1 : 0;
        $m[$i][6] = $i % 2 === 0 ? 1 : 0;
    }

    // Alignment, skipping the finder corners.
    $rows = xeric_qr_align($version);
    foreach ($rows as $r) {
        foreach ($rows as $c) {
            if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size - 9) || ($r >= $size - 9 && $c <= 8)) continue;
            for ($i = -2; $i <= 2; $i++) {
                for ($j = -2; $j <= 2; $j++) {
                    $m[$r + $i][$c + $j] = (abs($i) === 2 || abs($j) === 2 || ($i === 0 && $j === 0)) ? 1 : 0;
                }
            }
        }
    }

    $m[$size - 8][8] = 1;                                        // the dark module

    // Format areas are reserved before the data snakes past them.
    for ($i = 0; $i <= 8; $i++) {
        if ($m[8][$i] === null) $m[8][$i] = 0;
        if ($m[$i][8] === null) $m[$i][8] = 0;
    }
    for ($i = 0; $i < 8; $i++) {
        if ($m[8][$size - 1 - $i] === null) $m[8][$size - 1 - $i] = 0;
        if ($m[$size - 1 - $i][8] === null) $m[$size - 1 - $i][8] = 0;
    }

    // -- the data, up and down the columns -----------------------------------
    $bitstr = '';
    foreach ($final as $w) $bitstr .= str_pad(decbin($w), 8, '0', STR_PAD_LEFT);
    $bitstr .= str_repeat('0', 8);                               // remainder bits, harmless

    $p = 0;
    $up = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) $col--;                                  // the timing column is skipped
        for ($n = 0; $n < $size; $n++) {
            $row = $up ? $size - 1 - $n : $n;
            foreach ([0, 1] as $k) {
                $c = $col - $k;
                if ($m[$row][$c] !== null) continue;
                $bit = $p < strlen($bitstr) ? (int)$bitstr[$p] : 0;
                $p++;
                // Mask 0: (row + col) % 2 === 0 inverts.
                if (($row + $c) % 2 === 0) $bit ^= 1;
                $m[$row][$c] = $bit;
            }
        }
        $up = !$up;
    }

    // -- format information (ECC L, mask 0), BCH-protected -------------------
    $fmt = 0b01000;                                              // L=01, mask=000
    $rem = $fmt << 10;
    for ($i = 14; $i >= 10; $i--) {
        if ($rem & (1 << $i)) $rem ^= 0b10100110111 << ($i - 10);
    }
    $bitsF = (($fmt << 10) | $rem) ^ 0b101010000010010;

    // MSB FIRST: format bit 14 is the first module placed, at (8,0), and the
    // string walks right along row 8 and then up column 8. The second copy
    // runs bottom-up column 8 for the first seven bits and then rightward
    // along row 8 — switching at SEVEN, because the eighth position down
    // there is the dark module and belongs to nobody.
    for ($i = 0; $i < 15; $i++) {
        $bit = ($bitsF >> (14 - $i)) & 1;

        if ($i < 6)       $m[8][$i] = $bit;
        elseif ($i === 6) $m[8][7] = $bit;
        elseif ($i === 7) $m[8][8] = $bit;
        elseif ($i === 8) $m[7][8] = $bit;
        else              $m[14 - $i][8] = $bit;

        if ($i < 7) $m[$size - 1 - $i][8] = $bit;
        else        $m[8][$size - 15 + $i] = $bit;
    }

    foreach ($m as $r => $row) foreach ($row as $c => $v) if ($v === null) $m[$r][$c] = 0;
    return $m;
}

/**
 * The same thing as an SVG, which is what a page actually wants: no image
 * library, no temp file, no request — one string, inline, and it prints.
 */
function xeric_qr_svg(string $text, int $scale = 4, int $quiet = 4): string
{
    $m    = xeric_qr_matrix($text);
    $n    = count($m);
    $side = ($n + $quiet * 2) * $scale;

    $d = '';
    foreach ($m as $r => $row) {
        foreach ($row as $c => $v) {
            if (!$v) continue;
            $d .= 'M' . (($c + $quiet) * $scale) . ' ' . (($r + $quiet) * $scale)
                . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $side . '" height="' . $side . '" '
         . 'viewBox="0 0 ' . $side . ' ' . $side . '" role="img" aria-label="QR code">'
         . '<rect width="100%" height="100%" fill="#fff"/>'
         . '<path d="' . $d . '" fill="#000"/></svg>';
}
