#!/usr/bin/env bash
#
# Build an installable zip of the plugin: dist/woocommerce-dual-api-<version>.zip
#
# The zip contains every file git knows about (tracked, or untracked but not
# ignored) except the paths listed in .distignore, the same list `wp
# dist-archive` uses, plus a production Composer autoloader generated in a
# staging directory with `composer install --no-dev`. Ignored files never
# make it in, so a checkout with local build products yields the same zip as
# a clean clone. The archive unpacks to a single `woocommerce-dual-api/`
# directory, as WordPress expects.
#
# Usage: composer build:zip   (or: bash bin/build-zip.sh)
# Needs: bash, git, composer, zip.

set -euo pipefail

SLUG='woocommerce-dual-api'
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/dist"

for tool in git composer zip; do
	if ! command -v "$tool" > /dev/null 2>&1; then
		echo "build-zip: '$tool' is required but not installed." >&2
		exit 1
	fi
done

VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/$SLUG.php" | head -n 1 | tr -d '[:space:]')"
if [[ -z "$VERSION" ]]; then
	echo "build-zip: could not read the Version header from $SLUG.php." >&2
	exit 1
fi

# Exclusion patterns from .distignore. A leading slash anchors a pattern to
# the plugin root; a pattern without one matches at any depth. Every pattern
# matches the path itself and everything below it.
EXCLUDES=()
while IFS= read -r line; do
	line="${line%%#*}"
	line="$(echo "$line" | tr -d '[:space:]')"
	[[ -n "$line" ]] && EXCLUDES+=( "$line" )
done < "$ROOT/.distignore"
EXCLUDES+=( '/dist' )

is_excluded() {
	local path="$1" pattern
	for pattern in "${EXCLUDES[@]}"; do
		if [[ "$pattern" == /* ]]; then
			pattern="${pattern#/}"
			if [[ "$path" == "$pattern" || "$path" == "$pattern"/* ]]; then
				return 0
			fi
		elif [[ "$path" == "$pattern" || "$path" == "$pattern"/* || "$path" == */"$pattern" || "$path" == */"$pattern"/* ]]; then
			return 0
		fi
	done
	return 1
}

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
PKG="$STAGE/$SLUG"
mkdir -p "$PKG"

echo "Collecting files..."
count=0
while IFS= read -r -d '' path; do
	if is_excluded "$path"; then
		continue
	fi
	mkdir -p "$PKG/$(dirname "$path")"
	cp -p "$ROOT/$path" "$PKG/$path"
	count=$(( count + 1 ))
done < <(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard)
echo "  $count files."

echo "Generating the production autoloader..."
# The lock file is not shipped (see .distignore) but pins the install.
cp -p "$ROOT/composer.lock" "$PKG/composer.lock"
composer install --working-dir="$PKG" --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet
rm -f "$PKG/composer.lock"
if [[ ! -f "$PKG/vendor/autoload.php" ]]; then
	echo "build-zip: composer did not produce vendor/autoload.php." >&2
	exit 1
fi

mkdir -p "$DIST"
OUT="$DIST/$SLUG-$VERSION.zip"
rm -f "$OUT"
echo "Zipping..."
( cd "$STAGE" && zip -q -r -X "$OUT" "$SLUG" )

echo "Built $OUT ($(du -h "$OUT" | cut -f1))."
