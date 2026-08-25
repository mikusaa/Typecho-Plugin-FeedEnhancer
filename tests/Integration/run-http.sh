#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "usage: $0 TYPECHO_SOURCE [full|comments]" >&2
    exit 2
fi

TYPECHO_SOURCE=$1
CONTRACT_MODE=${2:-full}
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
PROJECT_ROOT=$(cd -- "$SCRIPT_DIR/../.." && pwd)
PHP_BIN=${PHP_BIN:-php}
FE_HTTP_PORT=${FE_HTTP_PORT:-18080}
FE_HTTP_ROOT="http://127.0.0.1:${FE_HTTP_PORT}"

if [[ ! -f "$TYPECHO_SOURCE/install.php" || ! -d "$TYPECHO_SOURCE/var" ]]; then
    echo "Typecho source is invalid: $TYPECHO_SOURCE" >&2
    exit 1
fi

if [[ "$CONTRACT_MODE" != full && "$CONTRACT_MODE" != comments ]]; then
    echo "Unknown contract mode: $CONTRACT_MODE" >&2
    exit 2
fi

for command_name in "$PHP_BIN" curl; do
    command -v "$command_name" >/dev/null 2>&1 \
        || { echo "Required command is missing: $command_name" >&2; exit 1; }
done

WORK_DIR=$(mktemp -d /tmp/feed-enhancer-http.XXXXXX)
SITE_ROOT="$WORK_DIR/site"
SERVER_LOG="$WORK_DIR/php-server.log"
FIXTURE_STATE="$WORK_DIR/fixture-state.json"
PROBE_LOG="$WORK_DIR/probe.log"
SERVER_PID=''

cleanup()
{
    local status=$1

    if [[ "$SERVER_PID" =~ ^[0-9]+$ ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi

    if [[ $status -ne 0 && -f "$SERVER_LOG" ]]; then
        echo "PHP server log:" >&2
        sed 's/^/  /' "$SERVER_LOG" >&2
    fi

    case "$WORK_DIR" in
        /tmp/feed-enhancer-http.*)
            [[ -d "$WORK_DIR" ]] && rm -rf -- "$WORK_DIR"
            ;;
        *)
            echo "Refusing to clean unexpected path: $WORK_DIR" >&2
            status=1
            ;;
    esac

    trap - EXIT
    exit "$status"
}

trap 'cleanup $?' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p -- "$SITE_ROOT"
cp -R -- "$TYPECHO_SOURCE/." "$SITE_ROOT/"

PLUGIN_ROOT="$SITE_ROOT/usr/plugins"
FEED_ENHANCER_ROOT="$PLUGIN_ROOT/FeedEnhancer"
PROBE_ROOT="$PLUGIN_ROOT/FeedContractProbe"

if [[ -e "$FEED_ENHANCER_ROOT" || -e "$PROBE_ROOT" ]]; then
    echo "The temporary Typecho copy already contains a test plugin target." >&2
    exit 1
fi

mkdir -p -- "$FEED_ENHANCER_ROOT" "$PROBE_ROOT"
for release_path in Plugin.php Runtime Feed Http assets; do
    cp -R -- "$PROJECT_ROOT/$release_path" "$FEED_ENHANCER_ROOT/"
done
cp -- "$PROJECT_ROOT/tests/Fixtures/plugins/FeedContractProbe/Plugin.php" \
    "$PROBE_ROOT/Plugin.php"

export TYPECHO_ROOT="$SITE_ROOT"
export TYPECHO_SITE_URL="$FE_HTTP_ROOT"
export TYPECHO_USER_NAME='feed-ci-admin'
export TYPECHO_USER_PASSWORD='feed-ci-admin-password'
export TYPECHO_USER_MAIL='feed-ci-admin@example.test'
export TYPECHO_DB_PREFIX='typecho_'
export TYPECHO_DB_NEXT='none'
export TYPECHO_DB_ADAPTER=${TYPECHO_DB_ADAPTER:-Pdo_SQLite}
export FE_HTTP_ROOT
export FE_FIXTURE_STATE="$FIXTURE_STATE"
export FE_PROBE_LOG="$PROBE_LOG"

case "$TYPECHO_DB_ADAPTER" in
    Pdo_SQLite)
        export TYPECHO_DB_FILE="$WORK_DIR/typecho.db"
        ;;
    Pdo_Mysql)
        export TYPECHO_DB_HOST=${TYPECHO_DB_HOST:-127.0.0.1}
        export TYPECHO_DB_PORT=${TYPECHO_DB_PORT:-3306}
        export TYPECHO_DB_USER=${TYPECHO_DB_USER:-root}
        export TYPECHO_DB_PASSWORD=${TYPECHO_DB_PASSWORD:-root}
        export TYPECHO_DB_DATABASE=${TYPECHO_DB_DATABASE:-feed_enhancer}
        export TYPECHO_DB_CHARSET=${TYPECHO_DB_CHARSET:-utf8mb4}
        export TYPECHO_DB_ENGINE=${TYPECHO_DB_ENGINE:-InnoDB}
        export TYPECHO_DB_SSL_VERIFY='off'
        ;;
    Pdo_Pgsql)
        export TYPECHO_DB_HOST=${TYPECHO_DB_HOST:-127.0.0.1}
        export TYPECHO_DB_PORT=${TYPECHO_DB_PORT:-5432}
        export TYPECHO_DB_USER=${TYPECHO_DB_USER:-postgres}
        export TYPECHO_DB_PASSWORD=${TYPECHO_DB_PASSWORD:-postgres}
        export TYPECHO_DB_DATABASE=${TYPECHO_DB_DATABASE:-feed_enhancer}
        export TYPECHO_DB_CHARSET=${TYPECHO_DB_CHARSET:-utf8}
        export TYPECHO_DB_SSL_VERIFY='off'
        ;;
    *)
        echo "Unsupported Typecho database adapter: $TYPECHO_DB_ADAPTER" >&2
        exit 2
        ;;
esac

(
    cd -- "$SITE_ROOT"
    "$PHP_BIN" install.php
)

"$PHP_BIN" "$SCRIPT_DIR/prepare-site.php" seed

(
    cd -- "$SITE_ROOT"
    exec "$PHP_BIN" -S "127.0.0.1:${FE_HTTP_PORT}" index.php
) >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

ready=false
for _ in $(seq 1 40); do
    if curl --fail --silent --show-error --output /dev/null \
        "$FE_HTTP_ROOT/feed/" 2>/dev/null; then
        ready=true
        break
    fi

    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        break
    fi
    sleep 0.25
done

if [[ "$ready" != true ]]; then
    echo "The Typecho HTTP server did not become ready." >&2
    exit 1
fi

"$PHP_BIN" "$SCRIPT_DIR/http-contract.php" "$CONTRACT_MODE"

if [[ "$CONTRACT_MODE" == full ]]; then
    "$PHP_BIN" "$SCRIPT_DIR/prepare-site.php" truncation-on
    "$PHP_BIN" "$SCRIPT_DIR/http-contract.php" truncation
    "$PHP_BIN" "$SCRIPT_DIR/prepare-site.php" truncation-on-full-text
    "$PHP_BIN" "$SCRIPT_DIR/http-contract.php" truncation-restore-one
    "$PHP_BIN" "$SCRIPT_DIR/prepare-site.php" safari-on
    "$PHP_BIN" "$SCRIPT_DIR/http-contract.php" safari
fi

echo "Typecho HTTP integration passed with $TYPECHO_DB_ADAPTER ($CONTRACT_MODE)."
