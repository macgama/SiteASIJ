# Site ASIJ – consignes pour le code

- Lire docs/ARCHITECTURE.md avant toute modification.
- PHP 8.1+, `declare(strict_types=1);`, pas de dépendance Composer à l'exécution, pas de framework.
- Interface et textes en français (Suisse romande). Code et commentaires en français ou anglais, cohérent par fichier.
- Le dépôt est PUBLIC: jamais de mot de passe, de config.php, ni de document confidentiel dans git.
- Tester localement: `cp config.sample.php config.php` (dsn sqlite, env development), `php bin/install.php admin@test.ch Admin`, puis `php -S localhost:8000 -t public public/index.php`.
- Tests: `php tests/run.php`.
