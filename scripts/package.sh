#!/bin/sh

set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
PLUGIN_FILE="$PROJECT_ROOT/Plugin.php"

fail()
{
    printf 'package: %s\n' "$1" >&2
    exit 1
}

command -v zip >/dev/null 2>&1 || fail 'zip is required'
command -v unzip >/dev/null 2>&1 || fail 'unzip is required'

[ -f "$PLUGIN_FILE" ] || fail 'Plugin.php is missing'

VERSION=$(sed -n \
    's/^[[:space:]]*\*[[:space:]]*@version[[:space:]][[:space:]]*\([^[:space:]]*\).*$/\1/p' \
    "$PLUGIN_FILE" | sed -n '1p')

case "$VERSION" in
    ''|*[!0-9A-Za-z.-]*) fail 'Plugin.php contains an invalid @version' ;;
esac

case "$VERSION" in
    *.*.*) ;;
    *) fail 'Plugin.php @version must contain at least three components' ;;
esac

PACKAGE_TMP_DIR=$(mktemp -d /tmp/feed-enhancer-package.XXXXXX)

cleanup()
{
    case "$PACKAGE_TMP_DIR" in
        /tmp/feed-enhancer-package.*)
            if [ -d "$PACKAGE_TMP_DIR" ]; then
                rm -rf -- "$PACKAGE_TMP_DIR"
            fi
            ;;
        *)
            printf 'package: refusing to clean unexpected path: %s\n' \
                "$PACKAGE_TMP_DIR" >&2
            ;;
    esac
}

trap cleanup 0
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

PACKAGE_ROOT="$PACKAGE_TMP_DIR/FeedEnhancer"
LISTING_FILE="$PACKAGE_TMP_DIR/archive-list.txt"
DIST_DIR="$PROJECT_ROOT/dist"
ARCHIVE_NAME="FeedEnhancer-$VERSION.zip"
CHECKSUM_NAME="$ARCHIVE_NAME.sha256"
ARCHIVE_PATH="$DIST_DIR/$ARCHIVE_NAME"
CHECKSUM_PATH="$DIST_DIR/$CHECKSUM_NAME"

mkdir -p -- "$PACKAGE_ROOT" "$DIST_DIR"

for item in Plugin.php Runtime Feed Http assets LICENSE README.md CHANGELOG.md; do
    if [ -e "$PROJECT_ROOT/$item" ]; then
        cp -R -- "$PROJECT_ROOT/$item" "$PACKAGE_ROOT/"
    fi
done

for required in \
    Plugin.php \
    Runtime \
    Feed \
    Http \
    assets/feed-preview.xsl \
    LICENSE \
    README.md
do
    [ -e "$PACKAGE_ROOT/$required" ] \
        || fail "required release path is missing: FeedEnhancer/$required"
done

find "$PACKAGE_ROOT" -type f -name '.DS_Store' -delete

if find "$PACKAGE_ROOT" -type l -print | sed -n '1p' | grep -q .; then
    fail 'release files must not contain symbolic links'
fi

if [ -e "$ARCHIVE_PATH" ]; then
    rm -f -- "$ARCHIVE_PATH"
fi
if [ -e "$CHECKSUM_PATH" ]; then
    rm -f -- "$CHECKSUM_PATH"
fi

(
    cd -- "$PACKAGE_TMP_DIR"
    zip -q -X -r "$ARCHIVE_PATH" FeedEnhancer
)

unzip -tq "$ARCHIVE_PATH" >/dev/null
unzip -Z1 "$ARCHIVE_PATH" > "$LISTING_FILE"

TOP_LEVELS=$(awk -F/ 'NF > 0 && $1 != "" { print $1 }' "$LISTING_FILE" | sort -u)
[ "$TOP_LEVELS" = 'FeedEnhancer' ] \
    || fail 'archive must contain exactly one FeedEnhancer top-level directory'

for required in \
    FeedEnhancer/Plugin.php \
    FeedEnhancer/assets/feed-preview.xsl \
    FeedEnhancer/LICENSE \
    FeedEnhancer/README.md
do
    grep -Fqx "$required" "$LISTING_FILE" \
        || fail "archive is missing required file: $required"
done

if grep -Eq '(^/|(^|/)\.\.(/|$))' "$LISTING_FILE"; then
    fail 'archive contains an unsafe path'
fi

if grep -Eq '(^|/)(\.git|\.github|tests|vendor|dist|scripts)(/|$)' "$LISTING_FILE" \
    || grep -Eq '(^|/)(composer[^/]*|phpunit[^/]*|phpcs[^/]*|Makefile|\.editorconfig|\.gitattributes|\.gitignore)$' \
        "$LISTING_FILE"
then
    fail 'archive contains a development-only path'
fi

if command -v shasum >/dev/null 2>&1; then
    (
        cd -- "$DIST_DIR"
        shasum -a 256 "$ARCHIVE_NAME" > "$CHECKSUM_NAME"
    )
elif command -v sha256sum >/dev/null 2>&1; then
    (
        cd -- "$DIST_DIR"
        sha256sum "$ARCHIVE_NAME" > "$CHECKSUM_NAME"
    )
else
    fail 'shasum or sha256sum is required'
fi

printf 'Created %s\n' "$ARCHIVE_PATH"
printf 'SHA-256 %s\n' "$(awk 'NR == 1 { print $1 }' "$CHECKSUM_PATH")"
printf 'Archive contents:\n'
sed 's/^/  /' "$LISTING_FILE"
