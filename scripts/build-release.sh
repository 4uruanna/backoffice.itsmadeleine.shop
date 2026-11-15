#!/usr/bin/env bash
# Prépare le dossier à envoyer par FTP sur OVH : build/backoffice/
# (code versionné au commit courant + vendor/ sans dépendances de dev).
# Usage : scripts/build-release.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build/backoffice"
cd "$ROOT"

if [[ -n "$(git status --porcelain -- public src templates migrations composer.json composer.lock)" ]]; then
    echo "Attention : modifications non commitées dans le code, elles ne seront PAS dans la release." >&2
fi

rm -rf "$ROOT/build"
mkdir -p "$OUT"

# Uniquement ce qui doit aller sur le serveur (ni tests, ni Docker, ni shopify/, ni roadmap/).
git archive HEAD public src templates migrations composer.json composer.lock | tar -x -C "$OUT"

COMPOSER_ARGS=(install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress)

if [[ -n "${USE_HOST_COMPOSER:-}" ]]; then
    # Composer installé sur le poste. Les extensions (pdo_mysql, gd) sont celles d'OVH,
    # pas celles du poste : on ne les vérifie pas ici.
    composer "${COMPOSER_ARGS[@]}" --working-dir="$OUT" --ignore-platform-req='ext-*'
else
    # Composer dans l'image du projet : même PHP (8.5) et mêmes extensions qu'OVH.
    # Docker seul suffit (pas besoin de Docker Compose).
    IMAGE="${RELEASE_IMAGE:-backoffice-php}"
    if [[ -z "${RELEASE_IMAGE:-}" ]]; then
        docker build -q -t "$IMAGE" "$ROOT" >/dev/null
    fi
    # shellcheck disable=SC2086
    docker run --rm ${RELEASE_DOCKER_ARGS:-} -v "$OUT":/release -w /release "$IMAGE" \
        composer "${COMPOSER_ARGS[@]}"
fi

rm -f "$OUT/composer.json" "$OUT/composer.lock"
# Paquets installés depuis les sources (repli de Composer) : pas de .git sur le serveur.
find "$OUT/vendor" -name .git -prune -exec rm -rf {} +

echo
echo "Release prête : $OUT ($(git rev-parse --short HEAD))"
echo "À envoyer dans le dossier backoffice/ de l'hébergement (voir docs/deploiement.md)."
