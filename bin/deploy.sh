#!/usr/bin/env bash
#
# Déploiement du module Social & Ads sur le serveur du BO consultant.
# À exécuter EN ROOT SUR LE SERVEUR, depuis un checkout du dépôt :
#
#   sudo bin/deploy.sh
#
# Le module est installé à côté du BO, dans son propre dossier
# (/var/www/social) et à sa propre adresse (/social). Il n'a aucun secret à
# lui : il lit la base dans le config du BO (/var/www/consulant_bo/config/
# config.php), et TOUT le reste — données et clés Meta — est en base, tables
# social_* (social_parametre pour les clés).
set -euo pipefail

TARGET_DIR="${TARGET_DIR:-/var/www/social}"            # dépôt complet, hors webroot
ALIAS_PATH="${ALIAS_PATH:-/social}"                    # URL servie par Apache
BO_DIR="${BO_DIR:-/var/www/consulant_bo}"              # installation du BO consultant
BO_URL="${BO_URL:-/consulant_bo/}"
PUBLIC_URL="${PUBLIC_URL:-https://185.180.206.46${ALIAS_PATH}/}"
LOCAL_BASE="http://127.0.0.1${ALIAS_PATH}"
# Espace franchisé : pages statiques dans leur propre dossier, servies à leur propre
# adresse ; elles appellent l'API du module (même hôte, donc même session).
FR_DIR="${FR_DIR:-/var/www/social_franchisee}"
FR_ALIAS="${FR_ALIAS:-/social_franchisee}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_SRC="$(cd "$SCRIPT_DIR/.." && pwd)"

log()  { printf '\033[1;34m[social]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[social]\033[0m %s\n' "$*"; }

# --- 1. Prérequis : ceux du BO (Apache, PHP, pdo_mysql, curl) ----------------
command -v php >/dev/null || { warn "php absent — installez d'abord le BO consultant."; exit 1; }
# La liste des modules est lue une fois : `php -m | grep -q` échoue sous pipefail
# (grep s'arrête au premier résultat, php reçoit SIGPIPE) — faux « absent ».
PHP_MODS="$(php -m 2>/dev/null)"
grep -qi '^pdo_mysql$' <<<"$PHP_MODS" || { warn "pdo_mysql absent."; exit 1; }
grep -qi '^curl$' <<<"$PHP_MODS" || { warn "php-curl absent (appels Meta) — installation…"; apt-get -o DPkg::Lock::Timeout=600 install -y -qq php-curl >/dev/null; }
a2enmod rewrite >/dev/null 2>&1 || true
a2enmod headers >/dev/null 2>&1 || true
[[ -f "$BO_DIR/config/config.php" ]] || warn "Config du BO introuvable ($BO_DIR/config/config.php) : l'API répondra 503 tant qu'il manque."

# --- 2. Syntaxe : refuser de livrer du code qui ne se charge pas --------------
for f in "$REPO_SRC"/src/*.php "$REPO_SRC"/public/api/*.php "$REPO_SRC"/bin/*.php; do
  php -l "$f" >/dev/null || { warn "SYNTAXE PHP : $f"; exit 1; }
done
if command -v node >/dev/null 2>&1; then
  for f in "$REPO_SRC/public/social-data.js" "$REPO_SRC/public/montage.js" "$REPO_SRC/public/franchise-visuel.js" "$REPO_SRC/franchisee/franchise-data.js"; do
    node --check "$f" || { warn "SYNTAXE JS : $f"; exit 1; }
  done
fi

# --- 3. Fichiers -----------------------------------------------------------------
log "Copie vers $TARGET_DIR (les visuels déposés, public/uploads, sont conservés)…"
mkdir -p "$TARGET_DIR"
rsync -a --delete --exclude '.git' --exclude 'config/config.php' --exclude 'config/hote.php' --exclude 'public/uploads' --exclude '.deployenv' \
  "$REPO_SRC/" "$TARGET_DIR/"
mkdir -p "$TARGET_DIR/public/uploads"
chown -R www-data:www-data "$TARGET_DIR/public/uploads"
chmod -R u+rwX,g+rX "$TARGET_DIR/public/uploads"

log "Espace franchisé → $FR_DIR ($FR_ALIAS)…"
bash "$REPO_SRC/bin/franchisee-build.sh" "$FR_DIR" "${ALIAS_PATH}/api/v1/social" "${ALIAS_PATH}/"

# --- 4. config/config.php : où trouver le BO (aucun secret) ------------------------
CFG="$TARGET_DIR/config/config.php"
BO_DIR="$BO_DIR" BO_URL="$BO_URL" PUBLIC_URL="$PUBLIC_URL" php -r '
  $cfg = ["boDir" => getenv("BO_DIR"), "boUrl" => getenv("BO_URL"), "publicUrl" => getenv("PUBLIC_URL"),
          "db" => null, "auth" => null, "userName" => "Franchiseur"];
  echo "<?php\n// Écrit par bin/deploy.sh — les données et les clés sont en base (social_parametre).\nreturn " . var_export($cfg, true) . ";\n";
' > "$CFG"
chmod 640 "$CFG"; chown www-data:www-data "$CFG"

# --- 5. Apache : alias /social -----------------------------------------------------
log "Apache : alias $ALIAS_PATH → $TARGET_DIR/public"
cat > /etc/apache2/conf-available/social.conf <<APACHE
Alias ${ALIAS_PATH} ${TARGET_DIR}/public
<Directory ${TARGET_DIR}/public>
    AllowOverride All
    Require all granted
</Directory>
# Espace franchisé
Alias ${FR_ALIAS} ${FR_DIR}
<Directory ${FR_DIR}>
    AllowOverride All
    Require all granted
</Directory>
APACHE
a2enconf social >/dev/null
apache2ctl configtest
systemctl reload apache2 || systemctl restart apache2

# --- 6. Installation des tables social_* (premier appel) ---------------------------
log "Installation des tables social_* et des paramètres…"
runuser -u www-data -- php -r 'require "'"$TARGET_DIR"'/src/api.php"; socInstall(); echo "tables OK\n";' || warn "Installation des tables en échec (base injoignable ?)"

# --- 7. Cron : file de publication, synchro Meta, contrôle des connexions ----------
cat > /etc/cron.d/social <<CRON
# Module Social & Ads — posé par bin/deploy.sh
SHELL=/bin/bash
* * * * *   www-data php ${TARGET_DIR}/bin/cron.php queue >> /var/log/social-cron.log 2>&1
7 * * * *   www-data php ${TARGET_DIR}/bin/cron.php sync  >> /var/log/social-cron.log 2>&1
40 6 * * *  www-data php ${TARGET_DIR}/bin/cron.php conn  >> /var/log/social-cron.log 2>&1
20 5 * * *  www-data php ${TARGET_DIR}/bin/cron.php audit >> /var/log/social-cron.log 2>&1
CRON
chmod 644 /etc/cron.d/social
touch /var/log/social-cron.log; chown www-data:www-data /var/log/social-cron.log

# --- 8. Recette locale ---------------------------------------------------------------
fail=0
st=$(curl -fsS --max-time 20 "$LOCAL_BASE/api/v1/social/status" || echo '{}')
echo "status : $st"
grep -q '"db":true' <<<"$st" && log "OK  base joignable (identifiants du BO)" || { warn "base injoignable"; fail=1; }
code=$(curl -s -o /dev/null -w '%{http_code}' "$LOCAL_BASE/")
[[ "$code" == "200" ]] && log "OK  $ALIAS_PATH/ → 200" || { warn "$ALIAS_PATH/ → HTTP $code"; fail=1; }
code=$(curl -s -o /dev/null -w '%{http_code}' "$LOCAL_BASE/SA%20Calendrier.dc.html")
[[ "$code" == "200" ]] && log "OK  calendrier servi" || { warn "calendrier → HTTP $code"; fail=1; }
code=$(curl -s -o /dev/null -w '%{http_code}' "$LOCAL_BASE/uploads/__absent__.png")
[[ "$code" == "404" ]] && log "OK  un visuel absent rend 404" || warn "uploads absent → HTTP $code (404 attendu)"
code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1${FR_ALIAS}/Connexion.dc.html")
[[ "$code" == "200" ]] && log "OK  espace franchisé servi ($FR_ALIAS)" || { warn "$FR_ALIAS → HTTP $code"; fail=1; }
st=$(curl -s --max-time 20 "$LOCAL_BASE/api/v1/social/franchise/session" || echo '{}')
grep -q '"authed":false' <<<"$st" && log "OK  API de l'espace franchisé (sans session : non connecté)" || { warn "franchise/session : $st"; fail=1; }
[[ "$fail" == "0" ]] && log "Module en ligne : http://185.180.206.46${ALIAS_PATH}/" || { warn "Recette en échec."; exit 1; }
