#!/usr/bin/env bash
#
# Builds the distributable plugin zip into dist/, rooted at wc-bom-stock/
# (the installed folder name — must never change between releases, see
# BUILD_PLAN.md §14.4). Run `npm run build` first for fresh JS; the GitHub
# Actions release workflow does both automatically on tag push.
#
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="wc-bom-stock"
VERSION=$(sed -n 's/^ \* Version:[[:space:]]*//p' wc-bom-stock.php | head -1 | tr -d '[:space:]')

if [[ -z "$VERSION" ]]; then
    echo "ERROR: could not read Version from wc-bom-stock.php" >&2
    exit 1
fi

# When run from the tag-push release workflow, refuse to ship a zip whose
# plugin header doesn't match the tag — mislabeled releases would confuse
# every install's update checker forever.
if [[ -n "${GITHUB_REF_NAME:-}" ]]; then
    TAG_VERSION="${GITHUB_REF_NAME#v}"
    if [[ "$TAG_VERSION" != "$VERSION" ]]; then
        echo "ERROR: tag ${GITHUB_REF_NAME} does not match plugin header version ${VERSION}. Bump the header (and WCBOM_VERSION) before tagging." >&2
        exit 1
    fi
fi

if [[ ! -f "assets/build/bom-editor/index.js" || ! -f "assets/build/inventory/index.js" ]]; then
    echo "ERROR: built assets missing — run 'npm run build' first." >&2
    exit 1
fi

STAGE="dist/$SLUG"
rm -rf dist
mkdir -p "$STAGE"

# Runtime files only.
cp wc-bom-stock.php uninstall.php readme.txt "$STAGE/"
cp -R src "$STAGE/src"
mkdir -p "$STAGE/assets"
cp -R assets/build "$STAGE/assets/build"
cp -R assets/css "$STAGE/assets/css"
cp -R assets/docs "$STAGE/assets/docs"

# Generate the production autoloader (no dev packages — our only runtime
# dependency is the PSR-4 autoloader itself).
cp composer.json composer.lock "$STAGE/"
composer install --no-dev --optimize-autoloader --quiet --working-dir="$STAGE"
rm "$STAGE/composer.json" "$STAGE/composer.lock"

( cd dist && zip -rq "$SLUG-$VERSION.zip" "$SLUG" )
rm -rf "$STAGE"

echo "Built dist/$SLUG-$VERSION.zip"
unzip -l "dist/$SLUG-$VERSION.zip" | tail -3
