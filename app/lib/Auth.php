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
                if ($u && (int) $u['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
                    $u = null; // mot de passe, 2FA ou droits modifiés depuis l'ouverture de cette session
                }
                self::$user = $u ?: null;
                if (!$u) {
                    $_SESSION = [];
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

    /**
     * Trop de tentatives récentes ?
     * - couple (IP, compte): $max échecs / 15 min (cas normal);
     * - compte toutes IP confondues: 10 x $max / 15 min (attaque distribuée), sans
     *   permettre à un inconnu de bloquer facilement un compte depuis une seule IP;
     * - IP tous comptes confondus: 6 x $max / 15 min (plusieurs membres d'une même commune).
     * Les échecs ne sont jamais effacés par la connexion réussie d'un AUTRE compte.
     */
    public static function throttled(string $email): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - 900);
        $max = (int) Config::get('security.max_login_attempts', 5);
        $email = mb_strtolower(trim($email));
        $pair = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE created_at > ? AND email = ? AND ip = ?',
            [$since, $email, client_ip()]
        );
        $byEmail = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE created_at > ? AND email = ?',
            [$since, $email]
        );
        $byIp = (int) Db::value(
            "SELECT COUNT(*) FROM login_attempts WHERE created_at > ? AND ip = ? AND email NOT LIKE '2fa:%'",
            [$since, client_ip()]
        );
        return $pair >= $max || $byEmail >= $max * 10 || $byIp >= $max * 6;
    }

    /** Codes 2FA faux: au plus 10 par heure et par compte, jamais remis à zéro par une autre connexion. */
    public static function twoFactorThrottled(string $email): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        return (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE created_at > ? AND email = ?',
            [$since, '2fa:' . mb_strtolower(trim($email))]
        ) >= 10;
    }

    public static function recordTwoFactorFailure(string $email): void
    {
        Db::insert('login_attempts', ['ip' => client_ip(), 'email' => '2fa:' . mb_strtolower(trim($email)), 'created_at' => Db::now()]);
    }

    /**
     * Invalide toutes les sessions de l'utilisateur (changement de mot de passe, 2FA, rôle…).
     * Si c'est l'utilisateur courant, sa session actuelle reste valable.
     */
    public static function bumpSessionVersion(int $userId): void
    {
        Db::run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        if ((int) ($_SESSION['uid'] ?? 0) === $userId) {
            $_SESSION['sv'] = (int) Db::value('SELECT session_version FROM users WHERE id = ?', [$userId]);
        }
        self::$loaded = false;
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
        $_SESSION['sv'] = (int) Db::value('SELECT session_version FROM users WHERE id = ?', [$u['id']]);
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
        // Effacer uniquement les échecs de CE compte depuis cette IP (pas ceux des autres comptes, ni les échecs 2FA).
        $email = (string) Db::value('SELECT email FROM users WHERE id = ?', [$userId]);
        Db::run('DELETE FROM login_attempts WHERE email = ? AND ip = ?', [mb_strtolower($email), client_ip()]);
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
