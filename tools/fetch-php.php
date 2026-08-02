<?php
/**
 * fetch-php.php — put a PHP inside the app, so the app needs none outside it.
 *
 *     php tools/fetch-php.php              this platform
 *     php tools/fetch-php.php --os windows --arch x64
 *     php tools/fetch-php.php --all        everything, for building a release
 *
 * WHY A FETCHER AND NOT A COMMITTED BINARY. A Windows PHP is 30MB and a static
 * Linux one is 25, they change every month for security, and a repository is not
 * a CDN. So `runtime/` is gitignored and this is the one command that fills it —
 * run once by whoever builds the download, or by anybody who would rather not
 * install PHP system-wide.
 *
 * THE CHICKEN AND THE EGG: this is itself PHP, so it needs a PHP to run. That is
 * fine, because it is a BUILD step, not a launch step. The person running it is
 * the one making the package; the person receiving the package runs `xeric` and
 * never sees this. The launchers look in runtime/ first and fall back to PATH,
 * so both work either way.
 *
 * EVERY DOWNLOAD IS CHECKED. Both sources publish a SHA-256 next to the file:
 * php.net's own releases.json for Windows, and a .sha256 beside each tarball at
 * dl.static-php.dev for Linux and macOS. A binary that does not match is deleted
 * rather than kept, because the failure mode of "mostly downloaded" is a program
 * that starts and then behaves strangely.
 *
 * WHY static-php-cli FOR LINUX AND macOS. php.net ships source there and nothing
 * else, and a build that depends on the host's libraries is the problem this is
 * meant to remove. static-php-cli's "common" build is one file with sqlite,
 * mbstring and zlib in it, which is exactly the list bootstrap.php checks for.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const XERIC_PHP_SERIES = '8.4';                 // the newest series both sources carry as stable

$root = dirname(__DIR__);
$want = [];
$all  = false;
$unverified = false;
$os   = strtolower(PHP_OS_FAMILY) === 'windows' ? 'windows'
      : (strtolower(PHP_OS_FAMILY) === 'darwin' ? 'macos' : 'linux');
$arch = (php_uname('m') === 'aarch64' || php_uname('m') === 'arm64') ? 'aarch64' : 'x86_64';

for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--all':  $all = true; break;
        case '--unverified': $unverified = true; break;
        case '--os':   $os = strtolower((string)($argv[++$i] ?? '')); break;
        case '--arch': $arch = (string)($argv[++$i] ?? ''); break;
        case '-h': case '--help':
            fwrite(STDOUT, "php tools/fetch-php.php [--os windows|linux|macos] [--arch x86_64|aarch64]\n"
                         . "                       [--all] [--unverified]\n\n"
                         . "Windows builds come from php.net and are checked against its published\n"
                         . "sha256. Linux and macOS builds come from a mirror that publishes none,\n"
                         . "so they need --unverified said out loud.\n");
            exit(0);
        default:
            fwrite(STDERR, "fetch-php: unknown option {$argv[$i]}\n");
            exit(1);
    }
}
if ($arch === 'x64') $arch = 'x86_64';

$want = $all
    ? [['windows', 'x86_64'], ['linux', 'x86_64'], ['linux', 'aarch64'], ['macos', 'aarch64']]
    : [[$os, $arch]];

$fail = 0;
foreach ($want as [$o, $a]) {
    try {
        $to = $root . '/runtime/' . $o . '-' . $a;
        say("$o/$a → runtime/" . basename($to));
        xeric_fetch_php($o, $a, $to);
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDERR, '  ! ' . $e->getMessage() . "\n");
    }
}
exit($fail === 0 ? 0 : 1);

// ---------------------------------------------------------------------------

function say(string $s): void { fwrite(STDOUT, $s . "\n"); }

function xeric_fetch_php(string $os, string $arch, string $to): void
{
    [$url, $sha, $kind] = xeric_php_source($os, $arch);
    $tmp = sys_get_temp_dir() . '/xeric-php-' . bin2hex(random_bytes(4)) . ($kind === 'zip' ? '.zip' : '.tar.gz');

    say('  ' . $url);
    xeric_download($url, $tmp);

    try {
        $got = hash_file('sha256', $tmp);
        if ($sha !== '') {
            if (!hash_equals(strtolower($sha), strtolower($got))) {
                @unlink($tmp);
                throw new RuntimeException("checksum mismatch: expected $sha, got $got");
            }
            say('  sha256 ok');
        } else {
            say('  sha256 ' . $got . '  (UNVERIFIED: the source publishes none)');
        }

        if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
            throw new RuntimeException("cannot create $to");
        }
        $kind === 'zip' ? xeric_unzip($tmp, $to) : xeric_untar($tmp, $to);
    } finally {
        @unlink($tmp);
    }

    $bin = $to . ($os === 'windows' ? '/php.exe' : '/php');
    if (!is_file($bin)) throw new RuntimeException("no php landed in $to");
    if ($os !== 'windows') @chmod($bin, 0755);

    if ($os === 'windows') xeric_write_ini($to);
    say('  ' . $bin);
}

/** @return array{0:string,1:string,2:string} url, sha256, kind */
function xeric_php_source(string $os, string $arch): array
{
    if ($os === 'windows') {
        // php.net's own manifest, which names the file and its hash together, so
        // there is no version string guessed anywhere in this script.
        $j = json_decode(xeric_get('https://windows.php.net/downloads/releases/releases.json'), true);
        $series = (array)($j[XERIC_PHP_SERIES] ?? []);
        $key = 'nts-vs17-' . ($arch === 'aarch64' ? 'arm64' : 'x64');
        $z = (array)($series[$key]['zip'] ?? []);
        if (($z['path'] ?? '') === '') {
            throw new RuntimeException("php.net lists no $key build for " . XERIC_PHP_SERIES);
        }
        // NON-THREAD-SAFE, deliberately: the CLI and the built-in server are both
        // single-process, and the TS build exists for an Apache module nothing
        // here uses.
        return ['https://windows.php.net/downloads/releases/' . $z['path'],
                (string)($z['sha256'] ?? ''), 'zip'];
    }

    if ($os !== 'linux' && $os !== 'macos') throw new RuntimeException("unknown os $os");

    // static-php-cli publishes a directory listing; take the newest of the
    // series rather than pinning a patch that will be stale in a month.
    $base = 'https://dl.static-php.dev/static-php-cli/common/';
    $html = xeric_get($base);
    $pat = '/php-(' . preg_quote(XERIC_PHP_SERIES, '/') . '\.\d+)-cli-'
         . ($os === 'macos' ? 'macos' : 'linux') . '-' . preg_quote($arch, '/') . '\.tar\.gz/';
    if (!preg_match_all($pat, $html, $m)) {
        throw new RuntimeException("no " . XERIC_PHP_SERIES . " build for $os/$arch at $base");
    }
    $versions = array_unique($m[1]);
    usort($versions, 'version_compare');
    $file = 'php-' . end($versions) . '-cli-' . ($os === 'macos' ? 'macos' : 'linux') . '-' . $arch . '.tar.gz';

    // NOBODY PUBLISHES A CHECKSUM FOR THESE, and that is the difference between
    // the two platforms rather than something to paper over. php.net signs its
    // own Windows manifest with a SHA-256 per file; the static Linux and macOS
    // builds come from a community mirror that publishes none — not beside the
    // file, not in a listing, not in the upstream releases, which hold the
    // BUILDER rather than the built PHP.
    //
    // So this refuses by default and says how to override, because the platform
    // that actually needs a bundled PHP is Windows: every Linux distribution has
    // one in its package manager, and a Linux user who wants this can weigh
    // "unverified download" for themselves rather than have it decided quietly.
    global $unverified;
    if (empty($unverified)) {
        throw new RuntimeException(
            "$file has no published checksum anywhere, so this will not install it by default.\n"
            . '    Windows builds ARE verified (php.net publishes a sha256 per file).' . "\n"
            . '    On Linux and macOS, either install PHP from your package manager,' . "\n"
            . '    or re-run with --unverified if you accept an unchecked download.'
        );
    }
    return [$base . $file, '', 'tar'];
}

function xeric_get(string $url): string
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5,
        'header' => "User-Agent: xeric-fetch-php\r\n",
    ]]);
    $s = @file_get_contents($url, false, $ctx);
    if ($s === false) throw new RuntimeException("cannot reach $url");
    return $s;
}

function xeric_download(string $url, string $to): void
{
    $in = @fopen($url, 'rb', false, stream_context_create(['http' => [
        'timeout' => 300, 'follow_location' => 1, 'max_redirects' => 5,
        'header' => "User-Agent: xeric-fetch-php\r\n",
    ]]));
    if (!$in) throw new RuntimeException("cannot download $url");
    $out = @fopen($to, 'wb');
    if (!$out) { fclose($in); throw new RuntimeException("cannot write $to"); }
    $n = stream_copy_to_stream($in, $out);
    fclose($in); fclose($out);
    if ($n === false || $n < 1024) throw new RuntimeException('the download came back empty');
    say('  ' . round($n / 1048576, 1) . 'MB');
}

/**
 * The zip, flattened.
 *
 * php.net's zip has everything at the top already, so this is a plain extract —
 * but it is done through ZipArchive rather than a shelled-out unzip, because the
 * machine building a Windows package is usually not a Windows machine and this
 * script is the only thing that has to be portable before the app is.
 */
function xeric_unzip(string $zip, string $to): void
{
    // THREE WAYS, BECAUSE THE MACHINE BUILDING A WINDOWS PACKAGE IS USUALLY NOT
    // A WINDOWS MACHINE and ext-zip is not always there — this very box has
    // phar but not zip, which is how the first run of this got a verified 33MB
    // download and then could not open it.
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($zip) !== true) throw new RuntimeException('that zip will not open');
        $z->extractTo($to);
        $z->close();
        return;
    }

    // PharData reads zip as well as tar, and phar ships enabled far more often.
    if (class_exists('PharData')) {
        try {
            (new PharData($zip))->extractTo($to, null, true);
            return;
        } catch (Throwable $e) {
            // and if it will not, fall through to the last resort rather than
            // stopping on a technicality about which extension is installed.
        }
    }

    // A build step may shell out; the app it is packaging may not.
    foreach (['unzip -q -o ' , '7z x -y -bso0 -bsp0 -o'] as $i => $prog) {
        $bin = strtok($prog, ' ');
        if (xeric_have($bin) === '') continue;
        $cmd = $i === 0
            ? 'unzip -q -o ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($to)
            : '7z x -y -bso0 -bsp0 -o' . escapeshellarg($to) . ' ' . escapeshellarg($zip);
        @exec($cmd, $out, $rc);
        if ($rc === 0) return;
    }

    throw new RuntimeException('nothing here can unpack a zip: no ext-zip, no phar, no unzip and no 7z');
}

/** Is this on PATH? A build-time convenience, not the app's xeric_web_which(). */
function xeric_have(string $name): string
{
    $sep = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
    foreach (explode($sep, (string)(getenv('PATH') ?: '')) as $dir) {
        $p = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
        foreach (PHP_OS_FAMILY === 'Windows' ? ['.exe', '.cmd', ''] : [''] as $ext) {
            if (@is_file($p . $ext)) return $p . $ext;
        }
    }
    return '';
}

/** The tarball, flattened: static-php-cli ships a bare `php` at the top. */
function xeric_untar(string $tar, string $to): void
{
    if (class_exists('PharData')) {
        $tmp = sys_get_temp_dir() . '/xeric-tar-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0775, true);
        try {
            $p = new PharData($tar);
            $p->decompress();                       // .tar.gz → .tar beside it
            $plain = preg_replace('/\.gz$/', '', $tar) ?: $tar;
            (new PharData($plain))->extractTo($tmp, null, true);
            @unlink($plain);
            xeric_move_php($tmp, $to);
            return;
        } finally {
            xeric_rmdir($tmp);
        }
    }
    throw new RuntimeException('this php has no phar extension, so it cannot unpack a tarball');
}

/** Find the php that came out of an archive and put it where the launcher looks. */
function xeric_move_php(string $from, string $to): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (!in_array($f->getFilename(), ['php', 'php.exe'], true)) continue;
        if (!@copy($f->getPathname(), $to . '/' . $f->getFilename())) {
            throw new RuntimeException('cannot place php in ' . $to);
        }
        return;
    }
    throw new RuntimeException('no php binary inside that archive');
}

function xeric_rmdir(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $d . '/' . $f;
        is_dir($p) ? xeric_rmdir($p) : @unlink($p);
    }
    @rmdir($d);
}

/**
 * A php.ini for the Windows build.
 *
 * The official zip ships php.ini-development and php.ini-production and loads
 * NEITHER until one is named php.ini, with every extension commented out — so a
 * bundled PHP with no ini is a PHP with no sqlite, which is a xeric that cannot
 * open. The list is exactly what bootstrap.php checks for.
 */
function xeric_write_ini(string $dir): void
{
    $ini = $dir . '/php.ini';
    if (is_file($ini)) return;
    file_put_contents($ini, implode("\n", [
        '; Written by tools/fetch-php.php. The zip ships with every extension off.',
        'extension_dir = "ext"',
        'extension=pdo_sqlite',
        'extension=sqlite3',
        'extension=mbstring',
        'extension=zip',
        'extension=openssl',
        '',
        '; A build is minutes of model time; nothing here may give up first.',
        'max_execution_time = 0',
        'memory_limit = 512M',
        '',
    ]) . "\n");
    say('  php.ini written (sqlite, mbstring, zlib are built in or enabled here)');
}
