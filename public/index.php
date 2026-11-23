<?php
declare(strict_types=1);

// Serveur de développement PHP: laisser servir les fichiers statiques.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/app/bootstrap.php';

App\Session::start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$r = new App\Router();
require APP . '/routes.php';

try {
    if (!$r->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/')) {
        http_response_code(404);
        echo App\View::render('public/error', ['code' => 404, 'message' => 'Page introuvable.']);
    }
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    if (App\Config::get('env') === 'development') {
        echo '<pre>' . e((string) $e) . '</pre>';
    } else {
        echo App\View::render('public/error', ['code' => 500, 'message' => 'Une erreur est survenue.']);
    }
}
