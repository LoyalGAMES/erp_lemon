#!/usr/bin/env bash

set -euo pipefail
umask 027

deploy_path="${1:-}"
release_id="${2:-}"

fail() {
    echo "Nie można zainicjalizować wydania: $*" >&2
    exit 1
}

[[ "$deploy_path" =~ ^/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+$ ]] ||
    fail 'nieprawidłowy DEPLOY_PATH.'
[[ "$release_id" =~ ^[A-Fa-f0-9]{7,64}-[0-9]+-[0-9]+$ ]] ||
    fail 'nieprawidłowy identyfikator wydania.'

deploy_root="${deploy_path}.deploy"
marker="${deploy_root}/DEPLOY_PATH"
release_path="${deploy_root}/releases/${release_id}"

if [[ -e "$deploy_root" && ! -d "$deploy_root" ]]; then
    fail "${deploy_root} istnieje i nie jest katalogiem."
fi
if [[ -d "$deploy_root" && ! -f "$marker" &&
    -n "$(find "$deploy_root" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
    fail "${deploy_root} nie jest pusty i nie ma znacznika Sempre ERP."
fi

mkdir -p "$deploy_root"
chmod 0755 "$deploy_root"

if [[ -f "$marker" ]]; then
    [[ "$(cat "$marker")" == "$deploy_path" ]] ||
        fail 'znacznik katalogu wdrożeniowego wskazuje inną aplikację.'
else
    printf '%s\n' "$deploy_path" >"$marker"
    chmod 0444 "$marker"
fi

command -v flock >/dev/null 2>&1 || fail 'na serwerze brakuje programu flock.'
exec 9>"${deploy_root}/deploy.lock"
flock -w 300 9 || fail 'inne wdrożenie utrzymuje blokadę dłużej niż 300 sekund.'

mkdir -p \
    "${deploy_root}/releases" \
    "${deploy_root}/shared/storage" \
    "${deploy_root}/shared/public/uploads" \
    "${deploy_root}/shared/database" \
    "${deploy_root}/shared/windows-print-listener/releases" \
    "${deploy_root}/backups"
chmod 0755 "${deploy_root}/releases"
chmod 0750 "${deploy_root}/shared"
chmod 0750 \
    "${deploy_root}/shared/storage" \
    "${deploy_root}/shared/public" \
    "${deploy_root}/shared/public/uploads" \
    "${deploy_root}/shared/database"
chmod 0750 "${deploy_root}/shared/windows-print-listener" "${deploy_root}/shared/windows-print-listener/releases"
chmod 0700 "${deploy_root}/backups"

[[ ! -e "$release_path" ]] || fail "wydanie ${release_id} już istnieje."
mkdir "$release_path"
chmod 0755 "$release_path"

printf '%s\n' "$release_path"
