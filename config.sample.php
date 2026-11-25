<?php
// Copier ce fichier en config.php (jamais dans git) et adapter les valeurs.
return [
    'site_url' => 'https://www.asij.ch',
    'env' => 'production',            // 'development' affiche les erreurs
    'db' => [
        // MySQL / MariaDB (hébergement):
        'dsn' => 'mysql:host=localhost;dbname=asij;charset=utf8mb4',
        'user' => 'asij',
        'password' => 'CHANGER',
        // SQLite (développement local): 'dsn' => 'sqlite:' . __DIR__ . '/storage/asij.sqlite',
    ],
    'mail' => [
        'from' => 'noreply@asij.ch',
        'from_name' => 'ASIJ',
        'contact_to' => 'contact@asij.ch',   // destinataire du formulaire de contact
        // Envoi par SMTP (obligatoire chez Infomaniak, où mail() est désactivée).
        // 'from' doit être l'adresse du compte SMTP ci-dessous.
        // 'smtp' => [
        //     'host' => 'mail.infomaniak.com',
        //     'port' => 465,               // 465 = SSL, 587 = STARTTLS ('secure' => 'tls')
        //     'secure' => 'ssl',
        //     'user' => 'noreply@asij.ch',
        //     'password' => 'CHANGER',
        // ],
    ],
    'security' => [
        'session_name' => 'asij_sess',
        'session_lifetime' => 7200,        // secondes d'inactivité avant déconnexion
        'max_login_attempts' => 5,         // par 15 minutes et par IP/compte
        'require_2fa_for_admins' => false, // passer à true après activation de la 2FA
    ],
    'uploads' => [
        'max_bytes' => 25 * 1024 * 1024,
    ],
];
