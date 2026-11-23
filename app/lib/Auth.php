<?php
declare(strict_types=1);

namespace App;

/**
 * Authentification par compte individuel.
 * Rôles: 'admin' (gère pages, médias, utilisateurs, espace sécurisé) et
 * 'member' (accède aux dossiers de l'espace sécurisé autorisés à ses groupes).
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['uid'] ?? null;
            if ($id && empty($_SESSION['pending_2fa'])) {
                $u = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
                self::$user = $u ?: null;
                if (!$u) {
                    unset($_SESSION['uid']);
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    /** Ids des groupes de l'utilisateur courant. @return int[] */
    public static function groupIds(): array
    {
        $id = self::id();
        if (!$id) {
            return [];
        }
        return array_map('intval', array_column(
            Db::all('SELECT group_id FROM user_group_members WHERE user_id = ?', [$id]),
            'group_id'
        ));
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            redirect('/connexion');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            exit(View::render('public/error', ['code' => 403, 'message' => 'Accès réservé aux administrateurs.']));
        }
    }

    /** Trop de tentatives récentes pour cette IP ou cet e-mail ? */
    public static function throttled(string $email): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - 900);
        $max = (int) Config::get('security.max_login_attempts', 5);
        // Par compte: $max essais / 15 min. Par IP: plafond plus large, car plusieurs
        // membres peuvent se connecter depuis le même réseau (administration communale).
        $byEmail = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE created_at > ? AND email = ?',
            [$since, mb_strtolower(trim($email))]
        );
        $byIp = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE created_at > ? AND ip = ?',
            [$since, client_ip()]
        );
        return $byEmail >= $max || $byIp >= $max * 6;
    }

    /**
     * Vérifie identifiants. Retourne l'utilisateur ou null.
     * N'ouvre pas la session (voir login()).
     */
    public static function attempt(string $email, string $password): ?array
    {
        $email = mb_strtolower(trim($email));
        $u = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        $ok = $u && (int) $u['is_active'] === 1 && password_verify($password, $u['password_hash']);
        if (!$ok) {
            // temps constant approximatif si l'utilisateur n'existe pas
            if (!$u) {
                password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            }
            Db::insert('login_attempts', ['ip' => client_ip(), 'email' => $email, 'created_at' => Db::now()]);
            return null;
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $u['id']]);
        }
        return $u;
    }

    /** Ouvre la session; si 2FA active, la session reste en attente du code. */
    public static function login(array $u): void
    {
        Session::regenerate();
        $_SESSION['uid'] = (int) $u['id'];
        if (!empty($u['totp_secret'])) {
            $_SESSION['pending_2fa'] = true;
        } else {
            self::complete((int) $u['id']);
        }
        self::$loaded = false;
    }

    public static function complete(int $userId): void
    {
        unset($_SESSION['pending_2fa']);
        Db::update('users', ['last_login_at' => Db::now(), 'failed_logins' => 0], 'id = :id', ['id' => $userId]);
        Db::run('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
        self::$loaded = false;
        audit('login');
    }

    public static function logout(): void
    {
        if (self::check()) {
            audit('logout');
        }
        $_SESSION = [];
        Session::regenerate();
        self::$user = null;
    }

    /** Règles minimales de mot de passe. Retourne un message d'erreur ou null. */
    public static function passwordProblem(string $pw): ?string
    {
        if (mb_strlen($pw) < 12) {
            return 'Le mot de passe doit contenir au moins 12 caractères.';
        }
        return null;
    }
}
