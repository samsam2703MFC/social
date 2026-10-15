#!/usr/bin/env bash
# Assemble l'espace franchisé (/social_franchisee) dans un dossier : ses pages
# (franchisee/) et les ressources partagées avec le module franchiseur (runtime des
# écrans, design system, polices, illustrations, studio de visuels).
#   bin/franchisee-build.sh DEST [API_BASE] [MEDIA_BASE]
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${1:?dossier de destination}"
API="${2:-/social/api/v1/social}"
MEDIA="${3:-/social/}"
mkdir -p "$DEST"
rsync -a --delete --exclude 'config.js' \
  --include '/support.js' --include '/ds-catalogue.js' --include '/franchise-visuel.js' --include '/Posts Facebook.dc.html' \
  --include '/vendor/***' --include '/_ds/***' --include '/fonts/***' --include '/img/***' --exclude '*' \
  "$SRC/public/" "$DEST/.shared/"
rsync -a "$DEST/.shared/" "$DEST/"
rm -rf "$DEST/.shared"
rsync -a --exclude 'config.js' "$SRC/franchisee/" "$DEST/"
# Pages retirées : l'accès se fait par l'ERP (plus de page de connexion propre à l'espace).
rm -f "$DEST/Connexion.dc.html"
printf '%s\n' "// Écrit par bin/franchisee-build.sh — l'API et les médias du module Social & Ads." \
  "window.SF_CONFIG = { api: '$API', media: '$MEDIA' };" > "$DEST/config.js"
echo "espace franchisé assemblé dans $DEST (API $API)"
