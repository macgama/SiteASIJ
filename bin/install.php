<?php
declare(strict_types=1);
/**
 * Installation: crée les tables, les groupes par défaut et un premier administrateur.
 * Usage (SSH):   php bin/install.php admin@exemple.ch "Prénom Nom"
 * Sans SSH: voir docs/DEPLOIEMENT.md (install via navigateur avec public/install.php temporaire).
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI uniquement');
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Db;

$email = $argv[1] ?? null;
$name = $argv[2] ?? 'Administrateur';
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/install.php email@exemple.ch \"Nom\"\n");
    exit(1);
}

$exists = false;
try {
    Db::value('SELECT COUNT(*) FROM users');
    $exists = true;
} catch (Throwable) {
}
if (!$exists) {
    Db::runSchema(ROOT . '/database/schema.sql');
    echo "Tables créées.\n";
}

foreach ([
    ['Comité de direction', 'codir', 'Membres du CoDir'],
    ['Conseil intercommunal', 'conseil', 'Membres du Conseil intercommunal'],
    ['Bureau du Conseil', 'bureau', 'Bureau du Conseil intercommunal'],
    ['Direction', 'direction', "Direction des opérations et de l'établissement"],
] as [$g, $slug, $desc]) {
    if (!Db::one('SELECT id FROM user_groups WHERE slug = ?', [$slug])) {
        Db::insert('user_groups', ['name' => $g, 'slug' => $slug, 'description' => $desc]);
    }
}

$email = mb_strtolower($email);
if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) {
    echo "L'utilisateur $email existe déjà.\n";
    exit(0);
}
$pw = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
Db::insert('users', [
    'email' => $email,
    'name' => $name,
    'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
    'role' => 'admin',
    'is_active' => 1,
    'created_at' => Db::now(),
]);
echo "Administrateur créé: $email\nMot de passe provisoire: $pw\n(à changer dès la première connexion)\n";
