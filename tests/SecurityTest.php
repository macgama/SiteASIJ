<?php
// Tests de non-régression issus de la revue de sécurité.
use App\Auth;
use App\Db;
use App\Totp;

require_once dirname(__DIR__) . '/bin/import-jimdo.php';

function sec_user(string $email, string $pw = 'motdepasse-solide-123'): array
{
    Db::run('DELETE FROM users WHERE email = ?', [$email]);
    $id = Db::insert('users', [
        'email' => $email, 'name' => $email, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'role' => 'member', 'is_active' => 1, 'created_at' => Db::now(),
    ]);
    return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
}

return [
    "La connexion d'un autre compte n'efface pas les échecs de la victime" => function () {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SESSION = [];
        Db::run('DELETE FROM login_attempts');
        sec_user('victime@test.ch');
        $mallory = sec_user('mallory@test.ch');
        for ($i = 0; $i < 5; $i++) {
            check(Auth::attempt('victime@test.ch', 'faux' . $i) === null);
        }
        check(Auth::throttled('victime@test.ch'), 'victime devrait être bloquée');
        Auth::login($mallory);
        check(Auth::throttled('victime@test.ch'), 'le blocage a été effacé par la connexion de mallory');
        check(!Auth::throttled('mallory@test.ch'));
    },
    'Les échecs 2FA sont limités par compte et non remis à zéro' => function () {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
        $_SESSION = [];
        Db::run('DELETE FROM login_attempts');
        for ($i = 0; $i < 10; $i++) {
            Auth::recordTwoFactorFailure('victime@test.ch');
        }
        check(Auth::twoFactorThrottled('victime@test.ch'));
        Auth::login(sec_user('autre@test.ch'));
        check(Auth::twoFactorThrottled('victime@test.ch'), 'remis à zéro par une autre connexion');
        check(!Auth::throttled('autre@test.ch'), 'les échecs 2FA ne doivent pas bloquer les connexions par IP');
    },
    'Changer le mot de passe invalide les autres sessions' => function () {
        $u = sec_user('sess@test.ch');
        $_SESSION = [];
        Auth::login($u);
        $other = $_SESSION;               // « autre appareil »
        Auth::bumpSessionVersion((int) $u['id']);
        check(Auth::id() === (int) $u['id'], 'la session courante doit rester valide');
        $_SESSION = $other;
        $r = new ReflectionClass(Auth::class);
        $r->setStaticPropertyValue('loaded', false);
        check(Auth::user() === null, "l'ancienne session devrait être refusée");
    },
    'TOTP: un code déjà utilisé a un pas connu (anti-rejeu)' => function () {
        $secret = Totp::generateSecret();
        $t = 1_700_000_000;
        $code = Totp::code($secret, $t);
        check(Totp::verifyStep($secret, $code, 1, $t) === intdiv($t, 30));
        check(Totp::verifyStep($secret, '000000x', 1, $t) === null);
    },
    'Import: seuls l\'ancien site et jimcdn.com sont joignables' => function () {
        $imp = new JimdoImporter(['source' => 'https://www.asij.ch', 'quiet' => true]);
        check($imp->allowedUrl('https://www.asij.ch/app/download/1/a.pdf'));
        check($imp->allowedUrl('https://asij.ch/x'));
        check($imp->allowedUrl('https://image.jimcdn.com/app/cms/image/path/x'));
        check(!$imp->allowedUrl('http://169.254.169.254/latest/meta-data//x.jimcdn.com/app/cms/image/'));
        check(!$imp->allowedUrl('http://10.0.0.5/app/download/1/x.pdf'));
        check(!$imp->allowedUrl('https://evil.example/jimcdn.com/'));
        check(!$imp->allowedUrl('https://jimcdn.com.evil.example/'));
        check(!$imp->allowedUrl('http://image.jimcdn.com/app/cms/image/x'));
    },
];
