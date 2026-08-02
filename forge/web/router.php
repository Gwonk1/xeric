<?php
/**
 * router.php — the front door, for `php -S` only.
 *
 * THE SHELF IS THE FRONT DOOR. Apache is told so by DirectoryIndex in
 * .htaccess; PHP's built-in server does not read .htaccess and has one fixed
 * idea, which is a file called index.php. There is no such file here — the forge
 * is forge.php, named for what it does — so without this, a local install opened
 * at its bare address would 404 at its own front door. It used to be worse: back
 * when the forge WAS index.php, the bare address dropped somebody into a
 * twenty-question interview instead of onto their own worlds.
 *
 * Only `/` is handled. Everything else returns false, which tells the built-in
 * server to serve the request exactly as it would have — a router that started
 * dispatching would be a second, divergent copy of what the deployed host does
 * with the same URLs.
 */

declare(strict_types=1);

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if ($path === '/' || $path === '') {
    require __DIR__ . '/play.php';
    return true;
}
return false;
