#!/usr/bin/env bash

set -Eeuo pipefail
umask 027

deploy_path="${1:-}"
release_id="${2:-}"
staging_path="${3:-}"

fail() {
    echo "Nie można opublikować instalatora Windows: $*" >&2
    exit 1
}

[[ "$deploy_path" =~ ^/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+$ ]] ||
    fail 'nieprawidłowy DEPLOY_PATH.'
[[ "$release_id" =~ ^[0-9]+\.[0-9]+\.[0-9]+-[0-9]+-[0-9]+$ ]] ||
    fail 'nieprawidłowy identyfikator wydania instalatora.'
[[ "$(basename "$staging_path")" =~ ^\.windows-release-upload-[0-9]+-[0-9]+$ ]] ||
    fail 'nieprawidłowy katalog tymczasowy.'

deploy_root="${deploy_path}.deploy"
shared_root="${deploy_root}/shared"
windows_root="${shared_root}/windows-print-listener"
releases_root="${windows_root}/releases"
release_path="${releases_root}/${release_id}"
marker="${deploy_root}/DEPLOY_PATH"

[[ -f "$marker" && "$(cat "$marker")" == "$deploy_path" ]] ||
    fail 'brak zgodnego znacznika wdrożenia.'
[[ -d "$shared_root" ]] || fail 'brak współdzielonego katalogu wdrożenia.'
command -v flock >/dev/null 2>&1 || fail 'na serwerze brakuje programu flock.'
command -v php >/dev/null 2>&1 || fail 'na serwerze brakuje programu php.'
command -v sha256sum >/dev/null 2>&1 || fail 'na serwerze brakuje programu sha256sum.'

exec 9>"${deploy_root}/deploy.lock"
flock -w 300 9 || fail 'inne wdrożenie utrzymuje blokadę dłużej niż 300 sekund.'

staging_resolved="$(realpath -e "$staging_path")" ||
    fail 'katalog tymczasowy nie istnieje.'
deploy_root_resolved="$(realpath -e "$deploy_root")" ||
    fail 'zarządzany katalog wdrożenia nie istnieje.'
[[ "$staging_resolved" == "${deploy_root_resolved}/.windows-release-upload-"* ]] ||
    fail 'katalog tymczasowy wychodzi poza zarządzany katalog wdrożenia.'
[[ -d "$staging_resolved" && ! -L "$staging_path" ]] ||
    fail 'katalog tymczasowy nie jest zwykłym katalogiem.'

expected_files=(
    'SempreERP-PrintListener-Setup.exe'
    'RELEASE-MANIFEST.json'
    'SHA256SUMS.txt'
)
for filename in "${expected_files[@]}"; do
    [[ -f "${staging_resolved}/${filename}" && ! -L "${staging_resolved}/${filename}" ]] ||
        fail "brak zwykłego pliku ${filename}."
done
file_count="$(find "$staging_resolved" -mindepth 1 -maxdepth 1 -print | wc -l | tr -d ' ')"
[[ "$file_count" -eq "${#expected_files[@]}" ]] ||
    fail 'katalog tymczasowy zawiera nieoczekiwane pliki.'

release_version="${release_id%%-*}"
php -r '
    try {
        $manifest = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
        if (($manifest["product"] ?? null) !== "Sempre ERP Print Listener"
            || ($manifest["version"] ?? null) !== $argv[3]
            || ($manifest["target"] ?? null) !== "windows/amd64"
            || ($manifest["signed"] ?? null) !== true
            || !is_array($manifest["artifacts"] ?? null)) {
            throw new RuntimeException("manifest release jest nieprawidłowy");
        }
        $installer = null;
        $installerCount = 0;
        foreach ($manifest["artifacts"] as $artifact) {
            if (is_array($artifact) && ($artifact["name"] ?? null) === "SempreERP-PrintListener-Setup.exe") {
                $installer = $artifact;
                $installerCount++;
            }
        }
        if ($installerCount !== 1
            || !is_array($installer)
            || !preg_match("/^[a-f0-9]{64}$/", (string) ($installer["sha256"] ?? ""))
            || (int) ($installer["size"] ?? -1) !== filesize($argv[2])
            || !hash_equals((string) $installer["sha256"], hash_file("sha256", $argv[2]))) {
            throw new RuntimeException("instalator nie odpowiada podpisanemu manifestowi");
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
        exit(1);
    }
' \
    "${staging_resolved}/RELEASE-MANIFEST.json" \
    "${staging_resolved}/SempreERP-PrintListener-Setup.exe" \
    "$release_version" ||
    fail 'manifest albo suma instalatora nie przeszły weryfikacji.'

installer_checksum_count="$(
    grep -Ec '^[a-f0-9]{64} \*SempreERP-PrintListener-Setup\.exe$' \
        "${staging_resolved}/SHA256SUMS.txt"
)"
[[ "$installer_checksum_count" -eq 1 ]] ||
    fail 'SHA256SUMS.txt nie zawiera dokładnie jednej sumy instalatora.'
installer_checksum="$(
    grep -E '^[a-f0-9]{64} \*SempreERP-PrintListener-Setup\.exe$' \
        "${staging_resolved}/SHA256SUMS.txt"
)"
(
    cd "$staging_resolved"
    printf '%s\n' "$installer_checksum" | sha256sum -c -
) >/dev/null || fail 'suma SHA-256 instalatora jest nieprawidłowa.'

group_file="${deploy_root}/RUNTIME_GROUP"
group="$(id -gn)"
if [[ -f "$group_file" ]]; then
    group="$(cat "$group_file")"
fi
[[ "$group" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'grupa runtime ma nieprawidłową nazwę.'
id -nG | tr ' ' '\n' | grep -Fxq "$group" ||
    fail "użytkownik publikujący nie należy do grupy runtime ${group}."

mkdir -p "$releases_root"
[[ ! -e "$release_path" && ! -L "$release_path" ]] ||
    fail 'wydanie o tym identyfikatorze już istnieje.'
mv "$staging_resolved" "$release_path"

chgrp -R "$group" "$windows_root"
find "$windows_root" -type d -exec chmod 2750 {} +
find "$windows_root" -type f -exec chmod 0640 {} +

pointer_temporary="${windows_root}/.CURRENT.$$"
trap 'rm -f "$pointer_temporary"' EXIT
printf '%s\n' "$release_id" >"$pointer_temporary"
chgrp "$group" "$pointer_temporary"
chmod 0640 "$pointer_temporary"
mv -fT "$pointer_temporary" "${windows_root}/CURRENT"
trap - EXIT

echo "Opublikowano podpisany instalator Windows ${release_version} w ${release_path}."
