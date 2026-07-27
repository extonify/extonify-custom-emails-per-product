#!/usr/bin/env bash
#
# Build the distributable release archive, honouring .distignore.
#
# Produces dist/extonify-custom-emails-per-product-<version>.zip containing a
# single top-level plugin directory, with production-only Composer
# dependencies installed. Nothing in .distignore reaches the archive.
#
# Usage:  bin/build-release.sh
set -euo pipefail

SLUG="extonify-custom-emails-per-product"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${PLUGIN_DIR}/dist"
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "${STAGE_DIR}"' EXIT

VERSION="$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9a-zA-Z.\-]+' "${PLUGIN_DIR}/${SLUG}.php")"
if [[ -z "${VERSION}" ]]; then
	echo "Could not read Version from the plugin header." >&2
	exit 1
fi

echo "Building ${SLUG} ${VERSION}"

# 1. Copy the working tree into a staging directory, applying .distignore.
#    Patterns starting with / are anchored to the plugin root; everything else
#    matches at any depth, which is how WP-CLI's dist-archive treats them.
RSYNC_ARGS=( -a --exclude '.git' --exclude 'dist' )
while IFS= read -r pattern; do
	[[ -z "${pattern}" || "${pattern}" == \#* ]] && continue
	if [[ "${pattern}" == /* ]]; then
		RSYNC_ARGS+=( --exclude "${pattern}" )
	else
		RSYNC_ARGS+=( --exclude "${pattern}" )
	fi
done < "${PLUGIN_DIR}/.distignore"

mkdir -p "${STAGE_DIR}/${SLUG}"
rsync "${RSYNC_ARGS[@]}" "${PLUGIN_DIR}/" "${STAGE_DIR}/${SLUG}/"

# 2. Production-only autoloader: dev tooling must never ship.
cp "${PLUGIN_DIR}/composer.json" "${STAGE_DIR}/${SLUG}/composer.json"
(
	cd "${STAGE_DIR}/${SLUG}"
	composer install --no-dev --optimize-autoloader --no-interaction --quiet
	rm -f composer.json composer.lock
)

# 3. Zip it.
mkdir -p "${DIST_DIR}"
ZIP_PATH="${DIST_DIR}/${SLUG}-${VERSION}.zip"
rm -f "${ZIP_PATH}"
( cd "${STAGE_DIR}" && zip -qr "${ZIP_PATH}" "${SLUG}" )

( cd "${DIST_DIR}" && sha256sum "$(basename "${ZIP_PATH}")" > "$(basename "${ZIP_PATH}").sha256" )

echo "Built: ${ZIP_PATH}"
echo "Size:  $(du -h "${ZIP_PATH}" | cut -f1)"
