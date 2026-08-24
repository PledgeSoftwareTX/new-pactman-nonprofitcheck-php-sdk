<?php

/**
 * Router for PHP's built-in server. Not meant to be run directly.
 *
 * `MockServer` and `scripts/mock-server.php` start it with
 * `php -S 127.0.0.1:<port> scripts/mock-router.php`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Pactman\NonprofitCheckPlus\Dev\MockRouter;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

MockRouter::fromEnvironment()->handle(
    is_string($method) ? $method : 'GET',
    is_string($path) ? $path : '/',
    (string) file_get_contents('php://input'),
);
