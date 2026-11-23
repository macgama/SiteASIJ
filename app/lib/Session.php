<?php
declare(strict_types=1);

namespace App;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name((string) Config::get('security.session_name', 'asij_sess'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        $ttl = (int) Config::get('security.session_lifetime', 7200);
        $now = time();
        if (isset($_SESSION['_last']) && $now - $_SESSION['_last'] > $ttl) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = $now;
    }

    public static function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return; // CLI / tests
        }
        session_regenerate_id(true);
    }
}
