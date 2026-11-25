<?php
declare(strict_types=1);

namespace App;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function check(): void
    {
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            // post_max_size dépassé: PHP a vidé $_POST
            http_response_code(413);
            exit('Envoi trop volumineux. Réduisez la taille ou le nombre de fichiers et réessayez.');
        }
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || !hash_equals(self::token(), $sent)) {
            http_response_code(419);
            exit('Jeton de sécurité expiré. Rechargez la page et réessayez.');
        }
    }
}
