#!/usr/bin/env bash
# Lance l'import Jimdo détaché de la console (survit à la fermeture de la console SSH web).
# Usage : bash ~/sites/asij/bin/lancer-import.sh   puis suivi : tail -f ~/sites/asij/storage/logs/import.log
set -eu
DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$DIR"
PHP="${PHP:-}"
if [ -z "$PHP" ]; then
  for c in /opt/php8.4/bin/php /opt/php8.3/bin/php /opt/php8.2/bin/php php; do
    if command -v "$c" >/dev/null 2>&1; then PHP="$c"; break; fi
  done
fi
mkdir -p storage/logs
if pgrep -f "bin/import-jimdo.php" >/dev/null 2>&1; then
  echo "Un import tourne déjà. Suivi : tail -f $DIR/storage/logs/import.log"
  exit 0
fi
if command -v setsid >/dev/null 2>&1; then
  setsid nohup "$PHP" bin/import-jimdo.php --write-logo "$@" > storage/logs/import.log 2>&1 < /dev/null &
else
  nohup "$PHP" bin/import-jimdo.php --write-logo "$@" > storage/logs/import.log 2>&1 < /dev/null &
fi
disown 2>/dev/null || true
echo "Import lancé en arrière-plan. Vous pouvez fermer la console."
echo "Suivi : tail -f $DIR/storage/logs/import.log   (Ctrl+C quitte le suivi, pas l'import)"
