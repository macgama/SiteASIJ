<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP', ROOT . '/app');
define('STORAGE', ROOT . '/storage');

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $rel = substr($class, 4);
        $f = str_starts_with($rel, 'Controllers\\')
            ? APP . '/controllers/' . substr($rel, 12) . '.php'
            : APP . '/lib/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

require APP . '/lib/helpers.php';

$configFile = getenv('ASIJ_CONFIG') ?: ROOT . '/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        echo 'Site non configuré : copiez config.sample.php en config.php puis lancez bin/install.php.';
        exit;
    }
    fwrite(STDERR, "config.php manquant\n");
    exit(1);
}
App\Config::load(require $configFile);

date_default_timezone_set('Europe/Zurich');
mb_internal_encoding('UTF-8');

if (App\Config::get('env') === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', STORAGE . '/logs/php-error.log');
}

App\Db::connect(App\Config::get('db'));
