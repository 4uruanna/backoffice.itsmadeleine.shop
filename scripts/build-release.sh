#!/usr/bin/env bash
# Prépare le dossier à envoyer par FTP sur OVH : build/backoffice/
# (code versionné au commit courant + vendor/ sans dépendances de dev).
# Usage : scripts/build-release.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build/backoffice"
cd "$ROOT"

if [[ -n "$(git status --porcelain -- public src migrations composer.json composer.lock)" ]]; then
    echo "Attention : modifications non commitées dans le code, elles ne seront PAS dans la release." >&2
fi

rm -rf "$ROOT/build"
mkdir -p "$OUT"

# Uniquement ce qui doit aller sur le serveur (ni tests, ni Docker, ni shopify/, ni roadmap/).
git archive HEAD public src migrations composer.json composer.lock | tar -x -C "$OUT"

if [[ -n "${USE_HOST_COMPOSER:-}" ]]; then
    composer install --working-dir="$OUT" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
else
    # Composer dans l'image du projet : même PHP (8.5) et mêmes extensions qu'OVH.
    docker compose run --rm --no-deps -v "$OUT":/release -w /release php \
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
fi

rm -f "$OUT/composer.json" "$OUT/composer.lock"
# Paquets installés depuis les sources (repli de Composer) : pas de .git sur le serveur.
find "$OUT/vendor" -name .git -prune -exec rm -rf {} +

echo
echo "Release prête : $OUT ($(git rev-parse --short HEAD))"
echo "À envoyer dans le dossier backoffice/ de l'hébergement (voir docs/deploiement.md)."
