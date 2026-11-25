# Mise en ligne

Ce guide sera complété dès que l'hébergeur sera connu. Il couvre un hébergement
mutualisé classique (PHP 8.1 ou plus récent, MySQL/MariaDB, Apache avec .htaccess).

## Prérequis chez l'hébergeur

- PHP 8.1+ avec les extensions `pdo_mysql`, `mbstring`, `dom`, `fileinfo`, `gd` (presque toujours présentes)
- Une base MySQL/MariaDB vide et son utilisateur
- Un certificat HTTPS (Let's Encrypt, généralement gratuit)
- Idéalement un accès SSH (sinon FTP suffit, voir plus bas)

## Étapes

1. Envoyer le contenu du dépôt sur le serveur (FTP/SFTP ou `git clone`).
2. Faire pointer le domaine (racine web) vers le dossier `public/`.
   Si l'hébergeur ne le permet pas, laisser la racine sur le dossier du projet :
   le fichier `.htaccess` à la racine redirige tout vers `public/` et bloque les autres dossiers.
3. Copier `config.sample.php` en `config.php` et y mettre les accès à la base et l'adresse du site.
4. Créer les tables et le premier administrateur :
   `php bin/install.php votre@email.ch "Prénom Nom"` (le mot de passe provisoire s'affiche).
5. Vérifier que `storage/` est accessible en écriture par PHP et qu'il n'est PAS accessible depuis le web
   (https://www.asij.ch/storage/ doit renvoyer une erreur 403 ou 404).
6. Importer le contenu de l'ancien site : voir `docs/MIGRATION.md`.
7. Activer la redirection HTTPS dans `public/.htaccess` (lignes commentées).
8. Se connecter sur `/connexion`, changer le mot de passe, activer la double authentification.

## Sauvegardes

À mettre en place chez l'hébergeur : sauvegarde quotidienne de la base **et** du dossier `storage/`
(les documents confidentiels sont dans `storage/private/`).
