#!/usr/bin/env bash
# Installation / mise à jour du site ASIJ sur un hébergement Infomaniak (console SSH).
# Usage :  curl -fsSLo deploy.sh https://raw.githubusercontent.com/macgama/SiteASIJ/dev/site-v1/bin/deploy-infomaniak.sh && bash deploy.sh
# Relancer la même commande plus tard met le code à jour sans toucher au contenu ni aux comptes.
set -euo pipefail

REPO="https://github.com/macgama/SiteASIJ.git"
BRANCH="${ASIJ_BRANCH:-dev/site-v1}"
DIR="${ASIJ_DIR:-$HOME/sites/asij}"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

# --- PHP 8.1+ en ligne de commande
PHP=""
for c in php8.4 php8.3 php8.2 php8.1 /opt/php8.4/bin/php /opt/php8.3/bin/php /opt/php8.2/bin/php /opt/php8.1/bin/php php; do
  if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null; then PHP="$c"; break; fi
done
if [ -z "$PHP" ]; then
  echo "PHP 8.1 ou plus récent est introuvable en ligne de commande. Dans le Manager Infomaniak, choisissez PHP 8.3 pour le site, puis relancez."
  exit 1
fi
say "PHP utilisé : $($PHP -r 'echo PHP_VERSION;') ($PHP)"
for ext in pdo_sqlite mbstring dom fileinfo gd; do
  $PHP -m | grep -qi "^$ext\$" || echo "  Attention : extension PHP '$ext' absente."
done

# --- Code
if [ -d "$DIR/.git" ]; then
  say "Mise à jour du code dans $DIR"
  git -C "$DIR" fetch --depth 1 origin "$BRANCH"
  git -C "$DIR" checkout -q -B "$BRANCH" FETCH_HEAD
else
  say "Téléchargement du code dans $DIR"
  mkdir -p "$(dirname "$DIR")"
  git clone -q --depth 1 -b "$BRANCH" "$REPO" "$DIR"
fi
cd "$DIR"
chmod -R u+rwX storage

# --- Configuration (première fois seulement)
if [ ! -f config.php ]; then
  read -r -p "Adresse du site (ex. https://test.asij.ch) : " SITE_URL </dev/tty
  read -r -p "Votre e-mail (compte administrateur) : " ADMIN_EMAIL </dev/tty
  read -r -p "Votre prénom et nom : " ADMIN_NAME </dev/tty
  SITE_URL="${SITE_URL%/}"
  cat > config.php <<CFG
<?php
return [
    'site_url' => '${SITE_URL}',
    'env' => 'production',
    'db' => ['dsn' => 'sqlite:' . __DIR__ . '/storage/asij.sqlite'],
    'mail' => ['from' => 'noreply@asij.ch', 'from_name' => 'ASIJ', 'contact_to' => '${ADMIN_EMAIL}'],
    'security' => ['session_name' => 'asij_sess', 'session_lifetime' => 7200, 'max_login_attempts' => 5, 'require_2fa_for_admins' => false],
    'uploads' => ['max_bytes' => 25 * 1024 * 1024],
];
CFG
  chmod 600 config.php
  say "Création de la base et de votre compte administrateur"
  $PHP bin/install.php "$ADMIN_EMAIL" "$ADMIN_NAME"
  echo "  >>> Notez ce mot de passe provisoire maintenant. <<<"

  say "Import du contenu de www.asij.ch en arrière-plan (environ 20 à 30 minutes)"
  nohup $PHP bin/import-jimdo.php --write-logo > storage/logs/import.log 2>&1 &
  echo "  Suivi : tail -f $DIR/storage/logs/import.log   (Ctrl+C pour quitter le suivi)"
  echo "  Rapport à la fin : $DIR/migration/rapport-import.md"
else
  say "Configuration existante conservée (config.php)."
fi

say "Terminé."
echo "Dans le Manager Infomaniak, le dossier racine du site doit être :"
echo "    $DIR/public"
echo "(Hébergement > votre site > Paramètres avancés / Modifier le dossier racine)."
