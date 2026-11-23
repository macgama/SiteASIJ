<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Controller;
use App\Db;
use App\Mailer;
use App\Session;
use App\Settings;
use App\Totp;

/**
 * Connexion, double authentification, mot de passe oublié et invitations.
 * Le lien /reinitialiser/{jeton} sert aussi d'invitation pour les nouveaux comptes.
 */
final class AuthController extends Controller
{
    public const RESET_TTL = 3600;          // mot de passe oublié: 1 heure
    public const INVITE_TTL = 7 * 86400;    // invitation: 7 jours
    private const PENDING_TTL = 600;        // délai pour saisir le code 2FA

    private const GENERIC_LOGIN_ERROR = 'Adresse e-mail ou mot de passe incorrect.';
    private const THROTTLE_ERROR = 'Trop de tentatives de connexion. Pour votre sécurité, l\'accès est bloqué pendant 15 minutes. Réessayez plus tard ou utilisez « Mot de passe oublié ».';
    private const FORGOT_MESSAGE = 'Si cette adresse correspond à un compte actif, un e-mail contenant un lien de réinitialisation vient d\'être envoyé. Pensez à vérifier vos courriels indésirables.';

    // ------------------------------------------------------------------ connexion

    public function loginForm(): void
    {
        $this->noStore();
        if (Auth::check()) {
            redirect($this->homeFor(Auth::user()));
        }
        $this->view('auth/login', ['email' => '', 'error' => null]);
    }

    public function login(): void
    {
        $this->noStore();
        $email = mb_strtolower($this->input('email'));
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::throttled($email)) {
            audit('login_throttled', mb_substr($email, 0, 190));
            $this->view('auth/login', ['email' => $email, 'error' => self::THROTTLE_ERROR], 429);
            return;
        }
        $u = ($email !== '' && $password !== '') ? Auth::attempt($email, $password) : null;
        if ($u === null) {
            if ($email === '' || $password === '') {
                \App\Db::insert('login_attempts', ['ip' => client_ip(), 'email' => $email, 'created_at' => Db::now()]);
            }
            $this->view('auth/login', ['email' => $email, 'error' => self::GENERIC_LOGIN_ERROR], 422);
            return;
        }

        // Double authentification obligatoire pour les administrateurs: configuration avant l'accès.
        if ($u['role'] === 'admin' && empty($u['totp_secret']) && Config::get('security.require_2fa_for_admins')) {
            $after = $_SESSION['after_login'] ?? null;
            Session::regenerate();
            unset($_SESSION['uid'], $_SESSION['pending_2fa']);
            $_SESSION['setup_2fa'] = ['uid' => (int) $u['id'], 'secret' => Totp::generateSecret(), 'at' => time()];
            if ($after) {
                $_SESSION['after_login'] = $after;
            }
            redirect('/connexion/code');
        }

        $after = $_SESSION['after_login'] ?? null;
        Auth::login($u);
        if ($after) {
            $_SESSION['after_login'] = $after;
        }
        if (!empty($_SESSION['pending_2fa'])) {
            $_SESSION['pending_2fa_at'] = time();
            redirect('/connexion/code');
        }
        $this->finishLogin($u);
    }

    public function twoFactorForm(): void
    {
        $this->noStore();
        $mode = $this->twoFactorMode();
        if ($mode === null) {
            redirect('/connexion');
        }
        $this->view('auth/two_factor', $this->twoFactorData($mode, null));
    }

    public function twoFactor(): void
    {
        $this->noStore();
        $mode = $this->twoFactorMode();
        if ($mode === null) {
            flash('Votre session a expiré. Veuillez vous reconnecter.', 'error');
            redirect('/connexion');
        }
        $uid = $mode === 'setup' ? (int) $_SESSION['setup_2fa']['uid'] : (int) $_SESSION['uid'];
        $u = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$uid]);
        if (!$u) {
            $this->abandonLogin();
            redirect('/connexion');
        }
        if (Auth::throttled((string) $u['email']) || Auth::twoFactorThrottled((string) $u['email'])) {
            audit('login_throttled', (string) $u['email']);
            $this->abandonLogin();
            flash(self::THROTTLE_ERROR, 'error');
            redirect('/connexion');
        }
        $secret = $mode === 'setup' ? (string) $_SESSION['setup_2fa']['secret'] : (string) $u['totp_secret'];
        $code = $this->input('code');
        $step = Totp::verifyStep($secret, $code);
        if ($step === null || ($mode !== 'setup' && $u['totp_last_step'] !== null && $step <= (int) $u['totp_last_step'])) {
            Auth::recordTwoFactorFailure((string) $u['email']);
            $this->view('auth/two_factor', $this->twoFactorData($mode, 'Code incorrect ou expiré. Vérifiez l\'heure de votre téléphone et saisissez le code actuel.'), 422);
            return;
        }

        Db::update('users', ['totp_last_step' => $step], 'id = :id', ['id' => $uid]);
        $after = $_SESSION['after_login'] ?? null;
        if ($mode === 'setup') {
            Db::update('users', ['totp_secret' => $secret], 'id = :id', ['id' => $uid]);
            Auth::bumpSessionVersion($uid);
            unset($_SESSION['setup_2fa']);
            $u['totp_secret'] = $secret;
            Auth::login($u);
            Auth::complete($uid);
            audit('2fa_enabled', 'user:' . $uid);
            flash('La double authentification est activée pour votre compte.');
        } else {
            Session::regenerate();
            Auth::complete($uid);
            unset($_SESSION['pending_2fa_at']);
        }
        if ($after) {
            $_SESSION['after_login'] = $after;
        }
        $this->finishLogin($u);
    }

    public function logout(): void
    {
        Auth::logout();
        flash('Vous êtes déconnecté. À bientôt !');
        redirect('/');
    }

    // ------------------------------------------------------------------ mot de passe oublié

    public function forgotForm(): void
    {
        $this->noStore();
        $this->view('auth/forgot', ['email' => '', 'sent' => false]);
    }

    public function forgot(): void
    {
        $this->noStore();
        $email = mb_strtolower($this->input('email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $u = Db::one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
            // Limite: au plus 3 liens valides en même temps par compte (anti-abus)
            if ($u && (int) Db::value('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND expires_at > ?', [$u['id'], Db::now()]) < 3) {
                self::sendResetLink($u, false);
                audit('password_reset_request', 'user:' . $u['id']);
            }
        }
        $this->view('auth/forgot', ['email' => '', 'sent' => true]);
    }

    public function resetForm(string $token): void
    {
        $this->noStore();
        header('Referrer-Policy: no-referrer');
        $u = self::userForToken($token);
        if (!$u) {
            $this->view('auth/reset_invalid', [], 410);
            return;
        }
        $this->view('auth/reset', ['token' => $token, 'user' => $u, 'invite' => $u['last_login_at'] === null, 'error' => null]);
    }

    public function reset(string $token): void
    {
        $this->noStore();
        header('Referrer-Policy: no-referrer');
        $u = self::userForToken($token);
        if (!$u) {
            $this->view('auth/reset_invalid', [], 410);
            return;
        }
        $pw = (string) ($_POST['password'] ?? '');
        $pw2 = (string) ($_POST['password_confirm'] ?? '');
        $error = Auth::passwordProblem($pw);
        if ($error === null && $pw !== $pw2) {
            $error = 'Les deux mots de passe ne sont pas identiques.';
        }
        if ($error !== null) {
            $this->view('auth/reset', ['token' => $token, 'user' => $u, 'invite' => $u['last_login_at'] === null, 'error' => $error], 422);
            return;
        }
        Db::transaction(function () use ($u, $pw) {
            Db::update('users', [
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'failed_logins' => 0,
                'locked_until' => null,
            ], 'id = :id', ['id' => $u['id']]);
            // Le jeton utilisé et tous les autres jetons de l'utilisateur deviennent invalides.
            Db::run('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
        });
        Db::run('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower((string) $u['email'])]);
        Auth::bumpSessionVersion((int) $u['id']);
        audit('password_reset', 'user:' . $u['id']);
        flash('Votre mot de passe est enregistré. Vous pouvez maintenant vous connecter.');
        redirect('/connexion');
    }

    // ------------------------------------------------------------------ outils partagés (aussi utilisés par AdminUsersController)

    /** Crée un jeton à usage unique et retourne sa valeur en clair (seul son sha256 est stocké). */
    public static function createToken(int $userId, int $ttl): string
    {
        $token = bin2hex(random_bytes(32));
        Db::insert('password_resets', [
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
        ]);
        return $token;
    }

    /** Utilisateur actif lié à un jeton valide et non expiré, sinon null. */
    public static function userForToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Db::one(
            'SELECT u.* FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.expires_at > ? AND u.is_active = 1',
            [hash('sha256', $token), Db::now()]
        );
    }

    /**
     * Envoie un lien pour (re)définir le mot de passe.
     * $invite = true: e-mail d'invitation, lien valable 7 jours; sinon réinitialisation, 1 heure.
     */
    public static function sendResetLink(array $u, bool $invite): bool
    {
        $token = self::createToken((int) $u['id'], $invite ? self::INVITE_TTL : self::RESET_TTL);
        $link = url('/reinitialiser/' . $token);
        $site = Settings::get('site_name') ?: 'ASIJ';
        $name = (string) $u['name'];
        if ($invite) {
            $subject = "Invitation à l'espace membres – $site";
            $body = "Bonjour $name,\n\n"
                . "Un compte personnel a été créé pour vous sur l'espace membres du site $site,\n"
                . "où vous trouverez les documents réservés à votre fonction.\n\n"
                . "Pour activer votre compte, cliquez sur le lien ci-dessous et choisissez votre mot de passe :\n\n"
                . "$link\n\n"
                . "Ce lien est personnel et valable 7 jours. Votre identifiant de connexion est votre adresse e-mail : {$u['email']}\n\n"
                . "Ensuite, vous pourrez vous connecter à tout moment sur " . url('/connexion') . "\n\n"
                . "Si vous ne vous attendiez pas à ce message, vous pouvez l'ignorer.\n\n"
                . "Avec nos meilleures salutations,\n$site";
        } else {
            $subject = "Réinitialisation de votre mot de passe – $site";
            $body = "Bonjour $name,\n\n"
                . "Une demande de nouveau mot de passe a été faite pour votre compte.\n"
                . "Pour choisir un nouveau mot de passe, cliquez sur le lien ci-dessous :\n\n"
                . "$link\n\n"
                . "Ce lien est valable 1 heure et ne peut être utilisé qu'une seule fois.\n"
                . "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement ce message : votre mot de passe actuel reste valable.\n\n"
                . "Avec nos meilleures salutations,\n$site";
        }
        return Mailer::send((string) $u['email'], $subject, $body);
    }

    /** Redirection interne sûre après connexion: chemin relatif commençant par un seul « / ». */
    public static function safeRedirect(?string $to): ?string
    {
        if (!is_string($to) || $to === '' || $to[0] !== '/' || strlen($to) > 500) {
            return null;
        }
        if (str_starts_with($to, '//') || str_contains($to, '\\') || preg_match('/[\x00-\x1F\x7F]/', $to)) {
            return null;
        }
        $path = (string) parse_url($to, PHP_URL_PATH);
        foreach (['/connexion', '/deconnexion', '/reinitialiser', '/mot-de-passe-oublie'] as $p) {
            if (str_starts_with($path, $p)) {
                return null;
            }
        }
        return $to;
    }

    // ------------------------------------------------------------------ interne

    private function finishLogin(array $u): never
    {
        $to = self::safeRedirect($_SESSION['after_login'] ?? null);
        unset($_SESSION['after_login']);
        if ($to !== null && str_starts_with($to, '/admin') && $u['role'] !== 'admin') {
            $to = null;
        }
        redirect($to ?? $this->homeFor($u));
    }

    private function homeFor(?array $u): string
    {
        return ($u['role'] ?? '') === 'admin' ? '/admin' : '/espace-membres';
    }

    /** 'verify' (code à saisir), 'setup' (configuration imposée) ou null. */
    private function twoFactorMode(): ?string
    {
        if (!empty($_SESSION['pending_2fa']) && !empty($_SESSION['uid'])) {
            if (time() - (int) ($_SESSION['pending_2fa_at'] ?? 0) > self::PENDING_TTL) {
                $this->abandonLogin();
                return null;
            }
            return 'verify';
        }
        if (!empty($_SESSION['setup_2fa']['uid'])) {
            if (time() - (int) $_SESSION['setup_2fa']['at'] > self::PENDING_TTL * 2) {
                $this->abandonLogin();
                return null;
            }
            return 'setup';
        }
        return null;
    }

    private function twoFactorData(string $mode, ?string $error): array
    {
        $data = ['mode' => $mode, 'error' => $error];
        if ($mode === 'setup') {
            $u = Db::one('SELECT email FROM users WHERE id = ?', [$_SESSION['setup_2fa']['uid']]);
            $secret = (string) $_SESSION['setup_2fa']['secret'];
            $data['secret'] = $secret;
            $data['secretFormatted'] = Totp::formatSecret($secret);
            $data['uri'] = Totp::uri($secret, (string) ($u['email'] ?? ''));
        }
        return $data;
    }

    private function abandonLogin(): void
    {
        unset($_SESSION['uid'], $_SESSION['pending_2fa'], $_SESSION['pending_2fa_at'], $_SESSION['setup_2fa']);
        Session::regenerate();
    }

    private function noStore(): void
    {
        header('Cache-Control: private, no-store');
    }
}
