#!/usr/bin/env bash
# Assemble l'espace franchisé (/social_franchisee) dans un dossier : ses pages
# (franchisee/) et les ressources partagées avec le module franchiseur (runtime des
# écrans, design system, polices, illustrations, studio de visuels).
#   bin/franchisee-build.sh DEST [API_BASE] [MEDIA_BASE] [PROPRES]
# PROPRES (1 par défaut) : les liens entre pages sans « .dc.html » (calendrier, publier…) ; le serveur
# doit alors les servir (.htaccess sous Apache, bin/franchisee-router.php en local). 0 : les fichiers.
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${1:?dossier de destination}"
API="${2:-/social/api/v1/social}"
MEDIA="${3:-/social/}"
PROPRES="${4:-1}"
mkdir -p "$DEST"
rsync -a --delete --exclude 'config.js' \
  --include '/support.js' --include '/Affiche avis.html' --include '/graphes.js' --include '/ds-catalogue.js' --include '/franchise-visuel.js' --include '/Posts Facebook.dc.html' \
  --include '/vendor/***' --include '/_ds/***' --include '/fonts/***' --include '/img/***' --exclude '*' \
  "$SRC/public/" "$DEST/.shared/"
rsync -a "$DEST/.shared/" "$DEST/"
rm -rf "$DEST/.shared"
rsync -a --exclude 'config.js' "$SRC/franchisee/" "$DEST/"
# Pages retirées : l'accès se fait par l'ERP (plus de page de connexion propre à l'espace).
rm -f "$DEST/Connexion.dc.html"
printf '%s\n' "// Écrit par bin/franchisee-build.sh — l'API et les médias du module Social & Ads." \
  "window.SF_CONFIG = { api: '$API', media: '$MEDIA', propres: $([ "$PROPRES" = 1 ] && echo true || echo false) };" > "$DEST/config.js"
echo "espace franchisé assemblé dans $DEST (API $API)"
