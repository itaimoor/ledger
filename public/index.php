<?php

declare(strict_types=1);

use Ledger\Exceptions\HttpException;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Http\Router;
use Ledger\Support\Env;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

Env::load($root . '/.env');

$debug = Env::bool('APP_DEBUG', false);

// Errors go to the log, never into the response body. The generic 500 below is the only
// thing a client ever sees for an unexpected failure.
ini_set('display_errors', '0');
error_reporting(E_ALL);

$https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';

$securityHeaders = [
    // No unsafe-inline anywhere: the design system's inline styles are compiled into a
    // stylesheet, and every script is an external ES module.
    'Content-Security-Policy' => implode('; ', [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        "img-src 'self' data:",
        "connect-src 'self'",
        "form-action 'self'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
    ]),
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Referrer-Policy' => 'no-referrer',
];

if ($https) {
    $securityHeaders['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
}

foreach ($securityHeaders as $name => $value) {
    header("{$name}: {$value}");
}

$request = Request::fromGlobals();

// Anything outside /api/v1 is the single-page app. Deep links such as /join/{token} and
// /projects/12 have to survive a page refresh, so the shell is served for all of them and
// the client router reads the path.
if (!str_starts_with($request->path, '/api/')) {
    // Under `php -S`, returning false hands an existing file back to the built-in server.
    if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $request->path)) {
        return false;
    }

    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');

    return;
}

/** @var Router $router */
$router = require $root . '/src/routes.php';

try {
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = Response::failure(
        $e->status(),
        $e->errorCode(),
        $e->getMessage(),
        $e->fields(),
        $e->headers(),
    );
} catch (Throwable $e) {
    error_log(sprintf(
        '[ledger] %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));

    $response = Response::failure(
        500,
        'server_error',
        $debug ? $e->getMessage() : 'Something went wrong. The failure has been logged.',
    );
}

$response->send();
