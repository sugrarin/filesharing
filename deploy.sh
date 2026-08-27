#!/usr/bin/env bash
set -euo pipefail

# Single FTP deploy script (lftp): code + data/ database + s/ uploads.
#   ./deploy.sh pull [--force]   — download from the server
#   ./deploy.sh push             — upload to the server (--delete)
# .env and the local review folder are never transferred either way.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$ROOT_DIR/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    echo "Error: .env file not found next to this script"
    exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

REMOTE_DIR="/public_html/"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m'

# Clock/TZ skew tolerance (seconds) when comparing mtimes.
MTIME_SKEW_SEC=120

usage() {
    echo "Usage: $0 <pull|push> [--force]"
    echo ""
    echo "  pull           Download everything from the server (data/, s/, code)"
    echo "  pull --force   Same, even if local files are newer"
    echo "  push           Upload everything to the server (except exclusions), with --delete"
    echo ""
    echo "Always excluded: .env, .git/, USELESS/, backups/,"
    echo "  tests, OS junk, archives."
    exit 1
}

ACTION=""
FORCE=0
for arg in "$@"; do
    case "$arg" in
        pull|push) ACTION="$arg" ;;
        --force|-f) FORCE=1 ;;
        -h|--help) usage ;;
        *)
            echo -e "${RED}Unknown argument: $arg${NC}"
            usage
            ;;
    esac
done
[[ -n "$ACTION" ]] || usage

if [[ "$FORCE" -eq 1 && "$ACTION" != "pull" ]]; then
    echo -e "${YELLOW}--force only makes sense for pull, ignoring${NC}"
fi

if ! command -v lftp >/dev/null 2>&1; then
    echo -e "${RED}Error: lftp is not installed${NC}"
    exit 1
fi

# --- php -l syntax check before push ---
if [[ "$ACTION" == "push" ]]; then
    if command -v php >/dev/null 2>&1; then
        echo -e "${BLUE}Checking PHP syntax...${NC}"
        fail=0
        while IFS= read -r -d '' f; do
            if ! php -l "$f" >/dev/null; then
                echo -e "${RED}Syntax error: $f${NC}"
                fail=1
            fi
        done < <(find "$ROOT_DIR" -maxdepth 1 -name '*.php' -print0)
        if [[ "$fail" -ne 0 ]]; then
            echo -e "${RED}Deploy aborted: PHP errors${NC}"
            exit 1
        fi
        echo -e "${GREEN}PHP syntax OK${NC}"
    else
        echo -e "${BLUE}php not found locally — skipping php -l${NC}"
    fi
fi

# lftp excludes (symmetric for pull/push). .env — never.
EXCLUDES_STR='--exclude-glob .git/ --exclude-glob .DS_Store --exclude-glob .gitignore --exclude-glob .env --exclude-glob .env.* --exclude-glob USELESS/ --exclude-glob __MACOSX/ --exclude-glob plan.md --exclude-glob project.md --exclude-glob backups/ --exclude-glob tests/ --exclude-glob backup.sh --exclude-glob "*.tar.gz" --exclude-glob data/sessions/'

file_mtime() {
    if stat -f %m "$1" >/dev/null 2>&1; then
        stat -f %m "$1"
    else
        stat -c %Y "$1"
    fi
}

fmt_time() {
    local ts="$1"
    date -r "$ts" '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
        || date -d "@$ts" '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
        || echo "$ts"
}

rel_path() {
    local p="$1"
    p="${p#"$ROOT_DIR"/}"
    p="${p#./}"
    echo "$p"
}

# true if the path (relative to root) should not be synced
is_excluded() {
    local rel="$1"
    case "$rel" in
        .git|.git/*) return 0 ;;
        .DS_Store|*/.DS_Store) return 0 ;;
        .gitignore) return 0 ;;
        .env|.env.*) return 0 ;;
        __MACOSX|__MACOSX/*) return 0 ;;
        plan.md|project.md) return 0 ;;
        backups|backups/*) return 0 ;;
        tests|tests/*) return 0 ;;
        backup.sh) return 0 ;;
        USELESS|USELESS/*) return 0 ;;
        .deploy-stamp) return 0 ;;
        data/sessions|data/sessions/*) return 0 ;;
    esac
    [[ "$rel" == *.tar.gz ]] && return 0
    return 1
}

SSL_SETTINGS='set ftp:ssl-allow no'
if [[ "${FTP_SSL:-0}" == "1" ]]; then
    echo -e "${BLUE}FTP: SSL/TLS enabled (FTP_SSL=1)${NC}"
    SSL_SETTINGS='set ftp:ssl-allow yes
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate no'
fi

fetch_remote_listing() {
    lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" <<EOF
set cmd:fail-exit yes
$SSL_SETTINGS
cd $REMOTE_DIR
cls -lR --time-style=+%s
quit
EOF
}

# Parse cls -lR output into "path epoch" lines (path first for join)
parse_remote_listing() {
    local curdir="."
    local line epoch name rel

    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" =~ ^(.*):$ ]]; then
            curdir="${BASH_REMATCH[1]}"
            continue
        fi
        [[ -z "$line" ]] && continue
        [[ "$line" =~ ^total[[:space:]] ]] && continue
        [[ "$line" =~ ^- ]] || continue

        # -rw-r--r-- 1 user group size epoch name
        read -r _perms _links _user _group _size epoch name <<<"$line" || continue
        [[ "$epoch" =~ ^[0-9]+$ ]] || continue
        [[ -n "${name:-}" ]] || continue
        [[ "$name" == "." || "$name" == ".." ]] && continue

        if [[ "$curdir" == "." ]]; then
            rel="$name"
        else
            rel="$curdir/$name"
        fi
        rel="${rel#./}"
        # path may contain spaces — rare; keep simple
        printf '%s %s\n' "$rel" "$epoch"
    done
}

# Before pull: return 1 if local files are newer; return 2 if remote mtimes can't be parsed.
check_local_not_newer() {
    local tmp_raw tmp_remote tmp_local conflicts
    tmp_raw="$(mktemp)"
    tmp_remote="$(mktemp)"
    tmp_local="$(mktemp)"
    conflicts="$(mktemp)"
    # shellcheck disable=SC2064
    trap "rm -f '$tmp_raw' '$tmp_remote' '$tmp_local' '$conflicts'" RETURN

    echo -e "${BLUE}Checking whether any local files are newer than the server's...${NC}"

    local err
    err="$(mktemp)"
    if ! fetch_remote_listing >"$tmp_raw" 2>"$err"; then
        echo -e "${RED}Failed to get the file listing from the server${NC}"
        [[ -s "$err" ]] && cat "$err"
        rm -f "$err"
        return 1
    fi
    rm -f "$err"

    parse_remote_listing <"$tmp_raw" | LC_ALL=C sort >"$tmp_remote"

    local remote_count
    remote_count="$(wc -l <"$tmp_remote" | tr -d ' ')"
    if [[ "$remote_count" -eq 0 ]]; then
        echo -e "${YELLOW}Could not parse mtimes from the server.${NC}"
        echo -e "${YELLOW}Enabling --only-newer: locally newer files won't be overwritten.${NC}"
        return 2
    fi

    # local: "path epoch"
    local local_path rel lmtime
    : >"$tmp_local"
    while IFS= read -r -d '' local_path; do
        rel="$(rel_path "$local_path")"
        is_excluded "$rel" && continue
        lmtime="$(file_mtime "$local_path")"
        printf '%s %s\n' "$rel" "$lmtime" >>"$tmp_local"
    done < <(find "$ROOT_DIR" -type f -print0)
    LC_ALL=C sort -o "$tmp_local" "$tmp_local"

    # join on path (field 1): path remote_epoch local_epoch
    local count=0
    while read -r path rmtime lmtime; do
        [[ -n "${path:-}" && -n "${rmtime:-}" && -n "${lmtime:-}" ]] || continue
        if [[ "$lmtime" =~ ^[0-9]+$ && "$rmtime" =~ ^[0-9]+$ ]] \
            && (( lmtime > rmtime + MTIME_SKEW_SEC )); then
            printf '%s\tlocal %s · remote %s\n' \
                "$path" "$(fmt_time "$lmtime")" "$(fmt_time "$rmtime")" >>"$conflicts"
            count=$((count + 1))
        fi
    done < <(join "$tmp_remote" "$tmp_local")

    if [[ "$count" -gt 0 ]]; then
        echo -e "${RED}Stopped: $count local file(s) are newer than the server's.${NC}"
        echo -e "${YELLOW}Pull would overwrite your work. Push first, or:${NC}"
        echo -e "  ${BLUE}./deploy.sh pull --force${NC}"
        echo ""
        echo "Files (up to 40):"
        head -n 40 "$conflicts" | while IFS=$'\t' read -r fpath rest; do
            echo "  • $fpath  ($rest)"
        done
        if [[ "$count" -gt 40 ]]; then
            echo "  ... and $((count - 40)) more"
        fi
        return 1
    fi

    echo -e "${GREEN}No conflicts (local files are not newer than the server's).${NC}"
    return 0
}

echo -e "${BLUE}Mode: full sync (code + data/ + s/; excludes .env / USELESS / backups / tests)${NC}"
echo -e "${BLUE}Local directory: ${ROOT_DIR}${NC}"
echo -e "${BLUE}Remote directory: ${REMOTE_DIR}${NC}"

PULL_ONLY_NEWER=""
if [[ "$ACTION" == "pull" ]]; then
    if [[ "$FORCE" -eq 1 ]]; then
        echo -e "${YELLOW}--force: skipping local mtime check${NC}"
    else
        set +e
        check_local_not_newer
        check_rc=$?
        set -e
        case "$check_rc" in
            0) ;;
            2) PULL_ONLY_NEWER="--only-newer" ;;
            *) exit 1 ;;
        esac
    fi
    echo -e "${GREEN}Downloading files from the server...${NC}"
    MIRROR="mirror --parallel=5 --verbose $PULL_ONLY_NEWER $EXCLUDES_STR \"$REMOTE_DIR\" \"$ROOT_DIR/\""
else
    echo -e "${GREEN}Uploading files to the server (--delete)...${NC}"
    MIRROR="mirror -R --delete --parallel=5 --verbose $EXCLUDES_STR \"$ROOT_DIR/\" \"$REMOTE_DIR\""
fi

lftp -u "$FTP_USER,$FTP_PASS" "$FTP_HOST" <<EOF
set cmd:fail-exit yes
$SSL_SETTINGS
$MIRROR
quit
EOF

echo -e "${GREEN}${ACTION} complete.${NC}"
