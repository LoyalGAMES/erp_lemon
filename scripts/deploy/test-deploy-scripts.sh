#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
temporary_directory="$(mktemp -d)"
trap 'rm -rf "$temporary_directory"' EXIT

fail() {
    echo "Test skryptów wdrożeniowych nie przeszedł: $*" >&2
    exit 1
}

expect_failure() {
    if "$@" >/dev/null 2>&1; then
        fail "polecenie miało zostać odrzucone: $*"
    fi
}

base_validation_environment=(
    SSH_HOST=erp.example.test
    SSH_USER=deploy
    SSH_PORT=22
    SSH_PRIVATE_KEY=test-only-private-key
    SSH_KNOWN_HOSTS=test-only-known-host
    DEPLOY_PATH=/var/www/sempre-erp
    RELEASE_ID=abcdef1-123-1
)

env "${base_validation_environment[@]}" \
    bash "$repository_root/scripts/deploy/validate-target.sh" >/dev/null

for unsafe_path in / /var relative/path /var/www/ /var/www/../etc '/var/www/app path'; do
    expect_failure env \
        SSH_HOST=erp.example.test \
        SSH_USER=deploy \
        SSH_PORT=22 \
        SSH_PRIVATE_KEY=test \
        SSH_KNOWN_HOSTS=test \
        DEPLOY_PATH="$unsafe_path" \
        bash "$repository_root/scripts/deploy/validate-target.sh"
done

for unsafe_host in 'erp.example.test -oProxyCommand=bad' '../erp.example.test' $'erp.example.test\nother'; do
    expect_failure env \
        SSH_HOST="$unsafe_host" \
        SSH_USER=deploy \
        SSH_PORT=22 \
        SSH_PRIVATE_KEY=test \
        SSH_KNOWN_HOSTS=test \
        DEPLOY_PATH=/var/www/sempre-erp \
        bash "$repository_root/scripts/deploy/validate-target.sh"
done

ssh-keygen -q -t ed25519 -N '' -f "$temporary_directory/host-key"
awk '{ print "erp.example.test " $1 " " $2 }' \
    "$temporary_directory/host-key.pub" >"$temporary_directory/known_hosts"
env "${base_validation_environment[@]}" \
    KNOWN_HOSTS_FILE="$temporary_directory/known_hosts" \
    bash "$repository_root/scripts/deploy/validate-target.sh" >/dev/null
expect_failure env \
    "${base_validation_environment[@]}" \
    SSH_HOST=other.example.test \
    KNOWN_HOSTS_FILE="$temporary_directory/known_hosts" \
    bash "$repository_root/scripts/deploy/validate-target.sh"

mkdir -p "$temporary_directory/bin" "$temporary_directory/live"
printf '%s\n' '#!/usr/bin/env bash' 'exit 0' >"$temporary_directory/bin/flock"
chmod +x "$temporary_directory/bin/flock"

deploy_path="$temporary_directory/live/application"
PATH="$temporary_directory/bin:$PATH" \
    bash "$repository_root/scripts/deploy/initialize-release.sh" \
    "$deploy_path" abcdef1-100-1 >/dev/null
[[ "$(cat "${deploy_path}.deploy/DEPLOY_PATH")" == "$deploy_path" ]] ||
    fail 'inicjalizator zapisał nieprawidłowy znacznik.'
[[ -d "${deploy_path}.deploy/releases/abcdef1-100-1" ]] ||
    fail 'inicjalizator nie utworzył katalogu wydania.'
[[ -d "${deploy_path}.deploy/shared/windows-print-listener/releases" ]] ||
    fail 'inicjalizator nie utworzył trwałego katalogu instalatora Windows.'
[[ -d "${deploy_path}.deploy/shared/database" && -d "${deploy_path}.deploy/shared/public/uploads" ]] ||
    fail 'inicjalizator nie utworzył trwałych katalogów bazy i plików publicznych.'
expect_failure env PATH="$temporary_directory/bin:$PATH" \
    bash "$repository_root/scripts/deploy/initialize-release.sh" \
    "$deploy_path" abcdef1-100-1

collision_path="$temporary_directory/live/collision"
mkdir -p "${collision_path}.deploy"
printf '%s\n' 'foreign data' >"${collision_path}.deploy/foreign"
expect_failure env PATH="$temporary_directory/bin:$PATH" \
    bash "$repository_root/scripts/deploy/initialize-release.sh" \
    "$collision_path" abcdef1-101-1

cat >"$temporary_directory/bin/crontab" <<'CRONTAB'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == '-l' ]]; then
    case "${FAKE_CRONTAB_MODE:-existing}" in
        existing)
            cat "$FAKE_CRONTAB_SOURCE"
            ;;
        missing)
            echo 'no crontab for deploy' >&2
            exit 1
            ;;
        error)
            echo 'permission denied while reading crontab' >&2
            exit 2
            ;;
    esac
else
    cp "$1" "$FAKE_CRONTAB_INSTALLED"
fi
CRONTAB
chmod +x "$temporary_directory/bin/crontab"

printf '%s\n' '0 2 * * * /usr/local/bin/backup' >"$temporary_directory/crontab-existing"
PATH="$temporary_directory/bin:$PATH" \
    PHP_BIN="$(command -v php)" \
    FAKE_CRONTAB_MODE=existing \
    FAKE_CRONTAB_SOURCE="$temporary_directory/crontab-existing" \
    FAKE_CRONTAB_INSTALLED="$temporary_directory/crontab-installed" \
    bash "$repository_root/scripts/install-laravel-scheduler.sh" \
    "$temporary_directory/application with spaces" >/dev/null
grep -Fq '/usr/local/bin/backup' "$temporary_directory/crontab-installed" ||
    fail 'instalator schedulera usunął obcy wpis crontaba.'
[[ "$(grep -Fc '# sempre-erp-laravel-scheduler' "$temporary_directory/crontab-installed")" -eq 1 ]] ||
    fail 'scheduler nie został zapisany dokładnie raz.'
grep -Fq 'storage/logs/scheduler.log' "$temporary_directory/crontab-installed" ||
    fail 'scheduler nadal wyrzuca diagnostykę zamiast zapisać log.'

PATH="$temporary_directory/bin:$PATH" \
    PHP_BIN="$(command -v php)" \
    FAKE_CRONTAB_MODE=missing \
    FAKE_CRONTAB_SOURCE="$temporary_directory/unused" \
    FAKE_CRONTAB_INSTALLED="$temporary_directory/crontab-missing" \
    bash "$repository_root/scripts/install-laravel-scheduler.sh" \
    /var/www/sempre-erp >/dev/null
[[ "$(grep -Fc '# sempre-erp-laravel-scheduler' "$temporary_directory/crontab-missing")" -eq 1 ]] ||
    fail 'brak istniejącego crontaba nie został obsłużony.'

printf '%s\n' 'sentinel' >"$temporary_directory/crontab-error"
expect_failure env \
    PATH="$temporary_directory/bin:$PATH" \
    PHP_BIN="$(command -v php)" \
    FAKE_CRONTAB_MODE=error \
    FAKE_CRONTAB_SOURCE="$temporary_directory/unused" \
    FAKE_CRONTAB_INSTALLED="$temporary_directory/crontab-error" \
    bash "$repository_root/scripts/install-laravel-scheduler.sh" \
    /var/www/sempre-erp
[[ "$(cat "$temporary_directory/crontab-error")" == 'sentinel' ]] ||
    fail 'błąd odczytu crontaba nadpisał istniejące zadania.'

sqlite_source="$temporary_directory/source.sqlite"
sqlite_backups="$temporary_directory/database-backups"
mkdir "$sqlite_backups"
php -r '
    $pdo = new PDO("sqlite:".$argv[1]);
    $pdo->exec("CREATE TABLE deployment_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)");
    $pdo->exec("INSERT INTO deployment_probe (value) VALUES (\"backup-ok\")");
' "$sqlite_source"
env \
    APP_ENV=testing \
    APP_CONFIG_CACHE="$temporary_directory/config-cache.php" \
    DB_URL= \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$sqlite_source" \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    php "$repository_root/scripts/deploy/backup-database.php" \
    "$repository_root" \
    "$sqlite_backups" \
    /var/www/sempre-erp >/dev/null
sqlite_backup="$(find "$sqlite_backups" -type f -name '*.sqlite' -print -quit)"
[[ -n "$sqlite_backup" && -f "${sqlite_backup}.sha256" ]] ||
    fail 'backup SQLite albo jego SHA-256 nie został utworzony.'
[[ "$(php -r '$pdo = new PDO("sqlite:".$argv[1]); echo $pdo->query("SELECT value FROM deployment_probe")->fetchColumn();' "$sqlite_backup")" == 'backup-ok' ]] ||
    fail 'backup SQLite nie zawiera oczekiwanych danych.'

if [[ "$(uname -s)" == 'Linux' ]]; then
    integration_deploy_path="$temporary_directory/integration/application"
    mkdir -p "$(dirname "$integration_deploy_path")"

    cat >"$temporary_directory/bin/fake-composer" <<'COMPOSER'
#!/usr/bin/env bash
set -euo pipefail
mkdir -p "$PWD/vendor"
printf '%s\n' '<?php // fake autoloader for deploy integration test' > "$PWD/vendor/autoload.php"
COMPOSER
    cat >"$temporary_directory/bin/fake-php" <<'PHP'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == */scripts/deploy/backup-database.php ]]; then
    mkdir -p "$3"
    printf '%s\n' 'verified backup' > "$3/integration-backup.sql"
fi
exit 0
PHP
    chmod +x "$temporary_directory/bin/fake-composer" "$temporary_directory/bin/fake-php"

    create_fake_release() {
        local release_id="$1"
        local release_path="${integration_deploy_path}.deploy/releases/${release_id}"
        mkdir -p "$release_path/scripts/deploy" "$release_path/bootstrap/cache" "$release_path/public" "$release_path/database"
        printf '%s\n' '# fake artisan' >"$release_path/artisan"
        printf '%s\n' '{}' >"$release_path/composer.lock"
        cp "$repository_root/scripts/install-laravel-scheduler.sh" "$release_path/scripts/install-laravel-scheduler.sh"
        cp "$repository_root/scripts/deploy/remote-release.sh" "$release_path/scripts/deploy/remote-release.sh"
        cp "$repository_root/scripts/deploy/backup-database.php" "$release_path/scripts/deploy/backup-database.php"
        chmod +x "$release_path/scripts/install-laravel-scheduler.sh" "$release_path/scripts/deploy/remote-release.sh"
    }

    first_release=abcdef1-200-1
    PATH="$temporary_directory/bin:$PATH" \
        bash "$repository_root/scripts/deploy/initialize-release.sh" \
        "$integration_deploy_path" "$first_release" >/dev/null
    printf '%s\n' 'APP_ENV=production' >"${integration_deploy_path}.deploy/shared/.env"
    create_fake_release "$first_release"

    create_windows_staging() {
        local staging_path="$1"
        local version="$2"
        local signing_profile="$3"
        local installer_hash installer_size listener_hash listener_size
        local root_hash='' root_size=0 publisher_hash publisher_size=0

        mkdir "$staging_path"
        printf '%s\n' "signed-installer-${signing_profile}-fixture" \
            >"$staging_path/SempreERP-PrintListener-Setup.exe"
        installer_hash="$(sha256sum "$staging_path/SempreERP-PrintListener-Setup.exe" | awk '{print $1}')"
        installer_size="$(stat -c '%s' "$staging_path/SempreERP-PrintListener-Setup.exe")"
        listener_hash="$(printf '%s' 'signed-listener-fixture' | sha256sum | awk '{print $1}')"
        listener_size=23

        if [[ "$signing_profile" == 'internal' ]]; then
            printf '%s\n' 'internal-root-certificate-fixture' \
                >"$staging_path/SempreERP-Internal-Root.cer"
            printf '%s\n' 'internal-publisher-certificate-fixture' \
                >"$staging_path/SempreERP-Internal-Publisher.cer"
            root_hash="$(sha256sum "$staging_path/SempreERP-Internal-Root.cer" | awk '{print $1}')"
            root_size="$(stat -c '%s' "$staging_path/SempreERP-Internal-Root.cer")"
            publisher_hash="$(sha256sum "$staging_path/SempreERP-Internal-Publisher.cer" | awk '{print $1}')"
            publisher_size="$(stat -c '%s' "$staging_path/SempreERP-Internal-Publisher.cer")"
        else
            publisher_hash='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
        fi

        php -r '
            $profile = $argv[3];
            $artifacts = [
                ["name" => "lemon-print-listener.exe", "size" => (int) $argv[4], "sha256" => $argv[5]],
                ["name" => "SempreERP-PrintListener-Setup.exe", "size" => (int) $argv[6], "sha256" => $argv[7]],
            ];
            $manifest = [
                "product" => "Sempre ERP Print Listener",
                "version" => $argv[2],
                "commit" => str_repeat("a", 40),
                "target" => "windows/amd64",
                "go_version" => "go1.24.5",
                "signed" => true,
                "timestamped" => true,
                "release_channel" => $profile,
                "signing_profile" => $profile,
                "publisher_subject" => "CN=Sempre ERP Internal Code Signing",
                "publisher_certificate_sha256" => $argv[8],
                "artifacts" => &$artifacts,
            ];
            if ($profile === "internal") {
                $manifest["root_certificate_sha256"] = $argv[10];
                $manifest["trust_bootstrap"] = "installer";
                $artifacts[] = [
                    "name" => "SempreERP-Internal-Root.cer",
                    "size" => (int) $argv[9],
                    "sha256" => $argv[10],
                ];
                $artifacts[] = [
                    "name" => "SempreERP-Internal-Publisher.cer",
                    "size" => (int) $argv[11],
                    "sha256" => $argv[8],
                ];
            }
            file_put_contents(
                $argv[1]."/RELEASE-MANIFEST.json",
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"
            );
        ' \
            "$staging_path" \
            "$version" \
            "$signing_profile" \
            "$listener_size" \
            "$listener_hash" \
            "$installer_size" \
            "$installer_hash" \
            "$publisher_hash" \
            "$root_size" \
            "$root_hash" \
            "$publisher_size"

        {
            printf '%s *lemon-print-listener.exe\n' "$listener_hash"
            printf '%s *SempreERP-PrintListener-Setup.exe\n' "$installer_hash"
            if [[ "$signing_profile" == 'internal' ]]; then
                printf '%s *SempreERP-Internal-Root.cer\n' "$root_hash"
                printf '%s *SempreERP-Internal-Publisher.cer\n' "$publisher_hash"
            fi
        } >"$staging_path/SHA256SUMS.txt"
    }

    expect_windows_publish_failure() {
        local release_id="$1"
        local staging_path="$2"
        expect_failure env PATH="$temporary_directory/bin:$PATH" \
            bash "$repository_root/scripts/deploy/publish-windows-listener.sh" \
            "$integration_deploy_path" "$release_id" "$staging_path"
        [[ ! -e "${integration_deploy_path}.deploy/shared/windows-print-listener/releases/${release_id}" ]] ||
            fail "publikator utworzył odrzucone wydanie ${release_id}."
    }

    windows_staging="${integration_deploy_path}.deploy/.windows-release-upload-200-1"
    create_windows_staging "$windows_staging" '0.2.0' public
    PATH="$temporary_directory/bin:$PATH" \
        bash "$repository_root/scripts/deploy/publish-windows-listener.sh" \
        "$integration_deploy_path" '0.2.0-200-1' "$windows_staging" >/dev/null
    [[ "$(cat "${integration_deploy_path}.deploy/shared/windows-print-listener/CURRENT")" == '0.2.0-200-1' ]] ||
        fail 'publikator nie przełączył współdzielonego CURRENT instalatora.'

    unsigned_staging="${integration_deploy_path}.deploy/.windows-release-upload-200-2"
    mkdir "$unsigned_staging"
    cp "${integration_deploy_path}.deploy/shared/windows-print-listener/releases/0.2.0-200-1/"* \
        "$unsigned_staging/"
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $manifest["signed"] = false;
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    ' "$unsigned_staging/RELEASE-MANIFEST.json"
    expect_windows_publish_failure '0.2.0-200-2' "$unsigned_staging"

    untimestamped_staging="${integration_deploy_path}.deploy/.windows-release-upload-200-3"
    create_windows_staging "$untimestamped_staging" '0.2.0' public
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $manifest["timestamped"] = false;
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    ' "$untimestamped_staging/RELEASE-MANIFEST.json"
    expect_windows_publish_failure '0.2.0-200-3' "$untimestamped_staging"

    mismatched_mode_staging="${integration_deploy_path}.deploy/.windows-release-upload-200-4"
    create_windows_staging "$mismatched_mode_staging" '0.2.0' public
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $manifest["signing_profile"] = "internal";
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    ' "$mismatched_mode_staging/RELEASE-MANIFEST.json"
    expect_windows_publish_failure '0.2.0-200-4' "$mismatched_mode_staging"

    unknown_mode_staging="${integration_deploy_path}.deploy/.windows-release-upload-200-5"
    create_windows_staging "$unknown_mode_staging" '0.2.0' public
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $manifest["release_channel"] = "preview";
        $manifest["signing_profile"] = "preview";
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    ' "$unknown_mode_staging/RELEASE-MANIFEST.json"
    expect_windows_publish_failure '0.2.0-200-5' "$unknown_mode_staging"

    unknown_field_staging="${integration_deploy_path}.deploy/.windows-release-upload-200-6"
    create_windows_staging "$unknown_field_staging" '0.2.0' public
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $manifest["trust_installer"] = true;
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    ' "$unknown_field_staging/RELEASE-MANIFEST.json"
    expect_windows_publish_failure '0.2.0-200-6' "$unknown_field_staging"

    extra_file_staging="${integration_deploy_path}.deploy/.windows-release-upload-200-7"
    create_windows_staging "$extra_file_staging" '0.2.0' public
    printf '%s\n' 'unexpected' >"$extra_file_staging/extra.txt"
    expect_windows_publish_failure '0.2.0-200-7' "$extra_file_staging"

    internal_staging="${integration_deploy_path}.deploy/.windows-release-upload-201-1"
    create_windows_staging "$internal_staging" '0.2.0' internal
    PATH="$temporary_directory/bin:$PATH" \
        bash "$repository_root/scripts/deploy/publish-windows-listener.sh" \
        "$integration_deploy_path" '0.2.0-201-1' "$internal_staging" >/dev/null
    [[ "$(cat "${integration_deploy_path}.deploy/shared/windows-print-listener/CURRENT")" == '0.2.0-201-1' ]] ||
        fail 'publikator nie przełączył CURRENT na wydanie wewnętrzne.'
    [[ -f "${integration_deploy_path}.deploy/shared/windows-print-listener/releases/0.2.0-201-1/SempreERP-Internal-Root.cer" ]] ||
        fail 'publikator nie zachował certyfikatu root wydania wewnętrznego.'
    [[ -f "${integration_deploy_path}.deploy/shared/windows-print-listener/releases/0.2.0-201-1/SempreERP-Internal-Publisher.cer" ]] ||
        fail 'publikator nie zachował certyfikatu wydawcy wydania wewnętrznego.'

    missing_certificate_staging="${integration_deploy_path}.deploy/.windows-release-upload-201-2"
    create_windows_staging "$missing_certificate_staging" '0.2.0' internal
    rm "$missing_certificate_staging/SempreERP-Internal-Root.cer"
    expect_windows_publish_failure '0.2.0-201-2' "$missing_certificate_staging"

    tampered_certificate_staging="${integration_deploy_path}.deploy/.windows-release-upload-201-3"
    create_windows_staging "$tampered_certificate_staging" '0.2.0' internal
    printf '%s\n' 'tampered' >>"$tampered_certificate_staging/SempreERP-Internal-Publisher.cer"
    expect_windows_publish_failure '0.2.0-201-3' "$tampered_certificate_staging"

    tampered_checksum_staging="${integration_deploy_path}.deploy/.windows-release-upload-201-4"
    create_windows_staging "$tampered_checksum_staging" '0.2.0' internal
    php -r '
        $path = $argv[1];
        $contents = file_get_contents($path);
        $contents = preg_replace(
            "/^[a-f0-9]{64} \\*SempreERP-Internal-Root\\.cer$/m",
            str_repeat("0", 64)." *SempreERP-Internal-Root.cer",
            $contents
        );
        file_put_contents($path, $contents);
    ' "$tampered_checksum_staging/SHA256SUMS.txt"
    expect_windows_publish_failure '0.2.0-201-4' "$tampered_checksum_staging"

    duplicate_certificate_staging="${integration_deploy_path}.deploy/.windows-release-upload-201-5"
    create_windows_staging "$duplicate_certificate_staging" '0.2.0' internal
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        foreach ($manifest["artifacts"] as $artifact) {
            if ($artifact["name"] === "SempreERP-Internal-Root.cer") {
                $manifest["artifacts"][] = $artifact;
                break;
            }
        }
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    ' "$duplicate_certificate_staging/RELEASE-MANIFEST.json"
    expect_windows_publish_failure '0.2.0-201-5' "$duplicate_certificate_staging"

    mismatched_fingerprint_staging="${integration_deploy_path}.deploy/.windows-release-upload-201-6"
    create_windows_staging "$mismatched_fingerprint_staging" '0.2.0' internal
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $manifest["root_certificate_sha256"] = str_repeat("c", 64);
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    ' "$mismatched_fingerprint_staging/RELEASE-MANIFEST.json"
    expect_windows_publish_failure '0.2.0-201-6' "$mismatched_fingerprint_staging"

    missing_bootstrap_staging="${integration_deploy_path}.deploy/.windows-release-upload-201-7"
    create_windows_staging "$missing_bootstrap_staging" '0.2.0' internal
    php -r '
        $path = $argv[1];
        $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        unset($manifest["trust_bootstrap"]);
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR)."\n");
    ' "$missing_bootstrap_staging/RELEASE-MANIFEST.json"
    expect_windows_publish_failure '0.2.0-201-7' "$missing_bootstrap_staging"

    [[ "$(cat "${integration_deploy_path}.deploy/shared/windows-print-listener/CURRENT")" == '0.2.0-201-1' ]] ||
        fail 'odrzucone wydanie zmieniło atomowy wskaźnik CURRENT instalatora.'

    PATH="$temporary_directory/bin:$PATH" \
        PHP_BIN="$temporary_directory/bin/fake-php" \
        COMPOSER_BIN="$temporary_directory/bin/fake-composer" \
        FAKE_CRONTAB_MODE=missing \
        FAKE_CRONTAB_SOURCE="$temporary_directory/unused" \
        FAKE_CRONTAB_INSTALLED="$temporary_directory/integration-crontab" \
        bash "${integration_deploy_path}.deploy/releases/${first_release}/scripts/deploy/remote-release.sh" \
        deploy "$integration_deploy_path" "$first_release" >/dev/null
    [[ -L "$integration_deploy_path" ]] || fail 'świeży deploy nie utworzył symlinka aplikacji.'
    [[ "$(basename "$(realpath "${integration_deploy_path}.deploy/CURRENT")")" == "$first_release" ]] ||
        fail 'CURRENT nie wskazuje pierwszego wydania.'
    [[ -f "${integration_deploy_path}.deploy/shared/database/database.sqlite" ]] ||
        fail 'świeży deploy nie utworzył trwałego pliku SQLite.'
    [[ -L "$integration_deploy_path/database/database.sqlite" ]] ||
        fail 'świeży release nie podpiął SQLite z katalogu shared.'
    [[ "$(realpath "$integration_deploy_path/database/database.sqlite")" == "$(realpath "${integration_deploy_path}.deploy/shared/database/database.sqlite")" ]] ||
        fail 'release SQLite wskazuje poza współdzieloną bazę.'
    [[ -L "$integration_deploy_path/tools/windows-print-listener/dist" ]] ||
        fail 'wydanie nie podpięło współdzielonego katalogu instalatora Windows.'
    [[ "$(cat "$integration_deploy_path/tools/windows-print-listener/dist/CURRENT")" == '0.2.0-201-1' ]] ||
        fail 'wydanie nie widzi opublikowanego instalatora ze shared.'

    second_release=abcdef2-201-1
    PATH="$temporary_directory/bin:$PATH" \
        bash "$repository_root/scripts/deploy/initialize-release.sh" \
        "$integration_deploy_path" "$second_release" >/dev/null
    create_fake_release "$second_release"
    PATH="$temporary_directory/bin:$PATH" \
        PHP_BIN="$temporary_directory/bin/fake-php" \
        COMPOSER_BIN="$temporary_directory/bin/fake-composer" \
        FAKE_CRONTAB_MODE=missing \
        FAKE_CRONTAB_SOURCE="$temporary_directory/unused" \
        FAKE_CRONTAB_INSTALLED="$temporary_directory/integration-crontab" \
        bash "${integration_deploy_path}.deploy/releases/${second_release}/scripts/deploy/remote-release.sh" \
        deploy "$integration_deploy_path" "$second_release" >/dev/null
    [[ "$(basename "$(realpath "${integration_deploy_path}.deploy/CURRENT")")" == "$second_release" ]] ||
        fail 'CURRENT nie został atomowo przełączony na drugie wydanie.'
    [[ "$(basename "$(realpath "${integration_deploy_path}.deploy/PREVIOUS")")" == "$first_release" ]] ||
        fail 'PREVIOUS nie zachował pierwszego wydania.'
    [[ "$(cat "$integration_deploy_path/tools/windows-print-listener/dist/CURRENT")" == '0.2.0-201-1' ]] ||
        fail 'kolejny deploy zgubił współdzielony instalator Windows.'

    mkdir -p "${integration_deploy_path}.deploy/releases/${second_release}/database/migrations"
    printf '%s\n' '<?php // schema required by second release' \
        >"${integration_deploy_path}.deploy/releases/${second_release}/database/migrations/2026_01_01_000000_second_release_schema.php"
    expect_failure env \
        PATH="$temporary_directory/bin:$PATH" \
        PHP_BIN="$temporary_directory/bin/fake-php" \
        COMPOSER_BIN="$temporary_directory/bin/fake-composer" \
        FAKE_CRONTAB_MODE=missing \
        FAKE_CRONTAB_SOURCE="$temporary_directory/unused" \
        FAKE_CRONTAB_INSTALLED="$temporary_directory/integration-crontab" \
        bash "$integration_deploy_path/scripts/deploy/remote-release.sh" \
        rollback "$integration_deploy_path"
    [[ "$(basename "$(realpath "${integration_deploy_path}.deploy/CURRENT")")" == "$second_release" ]] ||
        fail 'odrzucony rollback mimo niezgodnego schematu przełączył CURRENT.'
    rm "${integration_deploy_path}.deploy/releases/${second_release}/database/migrations/2026_01_01_000000_second_release_schema.php"

    PATH="$temporary_directory/bin:$PATH" \
        PHP_BIN="$temporary_directory/bin/fake-php" \
        COMPOSER_BIN="$temporary_directory/bin/fake-composer" \
        FAKE_CRONTAB_MODE=missing \
        FAKE_CRONTAB_SOURCE="$temporary_directory/unused" \
        FAKE_CRONTAB_INSTALLED="$temporary_directory/integration-crontab" \
        bash "$integration_deploy_path/scripts/deploy/remote-release.sh" \
        rollback "$integration_deploy_path" >/dev/null
    [[ "$(basename "$(realpath "${integration_deploy_path}.deploy/CURRENT")")" == "$first_release" ]] ||
        fail 'rollback nie przywrócił pierwszego wydania.'
    [[ "$(basename "$(realpath "${integration_deploy_path}.deploy/PREVIOUS")")" == "$second_release" ]] ||
        fail 'rollback nie zachował drugiego wydania jako PREVIOUS.'

    legacy_deploy_path="$temporary_directory/legacy/application"
    integration_deploy_path="$legacy_deploy_path"
    mkdir -p \
        "$legacy_deploy_path/storage/app/private" \
        "$legacy_deploy_path/public/uploads" \
        "$legacy_deploy_path/tools/windows-print-listener/dist/releases/0.1.0-legacy"
    printf '%s\n' '# fake artisan' >"$legacy_deploy_path/artisan"
    printf '%s\n' 'APP_ENV=production' >"$legacy_deploy_path/.env"
    printf '%s\n' 'runtime data must survive' >"$legacy_deploy_path/storage/app/private/runtime.txt"
    printf '%s\n' 'uploaded product image' >"$legacy_deploy_path/public/uploads/product.jpg"
    printf '%s\n' '0.1.0-legacy' >"$legacy_deploy_path/tools/windows-print-listener/dist/CURRENT"
    printf '%s\n' 'legacy signed installer' \
        >"$legacy_deploy_path/tools/windows-print-listener/dist/releases/0.1.0-legacy/SempreERP-PrintListener-Setup.exe"

    legacy_release_id=abcdef3-202-1
    PATH="$temporary_directory/bin:$PATH" \
        bash "$repository_root/scripts/deploy/initialize-release.sh" \
        "$legacy_deploy_path" "$legacy_release_id" >/dev/null
    printf '%s\n' "$(id -gn)" >"${legacy_deploy_path}.deploy/RUNTIME_GROUP"
    create_fake_release "$legacy_release_id"
    PATH="$temporary_directory/bin:$PATH" \
        PHP_BIN="$temporary_directory/bin/fake-php" \
        COMPOSER_BIN="$temporary_directory/bin/fake-composer" \
        FAKE_CRONTAB_MODE=missing \
        FAKE_CRONTAB_SOURCE="$temporary_directory/unused" \
        FAKE_CRONTAB_INSTALLED="$temporary_directory/integration-crontab" \
        bash "${legacy_deploy_path}.deploy/releases/${legacy_release_id}/scripts/deploy/remote-release.sh" \
        deploy "$legacy_deploy_path" "$legacy_release_id" >/dev/null

    [[ -L "$legacy_deploy_path" ]] || fail 'bootstrap nie zastąpił starego katalogu bezpiecznym symlinkiem.'
    [[ "$(cat "$legacy_deploy_path/storage/app/private/runtime.txt")" == 'runtime data must survive' ]] ||
        fail 'bootstrap utracił dane storage/app.'
    [[ "$(cat "$legacy_deploy_path/public/uploads/product.jpg")" == 'uploaded product image' ]] ||
        fail 'bootstrap utracił public/uploads.'
    [[ -L "$legacy_deploy_path/tools/windows-print-listener/dist" ]] ||
        fail 'bootstrap nie podpiął instalatora Windows do shared.'
    [[ "$(cat "$legacy_deploy_path/tools/windows-print-listener/dist/releases/0.1.0-legacy/SempreERP-PrintListener-Setup.exe")" == 'legacy signed installer' ]] ||
        fail 'bootstrap utracił legacy dist instalatora Windows.'
    legacy_previous="$(realpath "${legacy_deploy_path}.deploy/PREVIOUS")"
    [[ "$(basename "$legacy_previous")" == "legacy-${legacy_release_id}" ]] ||
        fail 'bootstrap nie zachował starego katalogu jako PREVIOUS.'
    [[ -f "$legacy_previous/storage/app/private/runtime.txt" ]] ||
        fail 'stary release nie zachował oryginalnej kopii storage/app.'
fi

workflow="$repository_root/.github/workflows/deploy.yml"
windows_workflow="$repository_root/.github/workflows/windows-print-listener.yml"
remote_script="$repository_root/scripts/deploy/remote-release.sh"
windows_publisher="$repository_root/scripts/deploy/publish-windows-listener.sh"
! grep -Fq -- '--delete' "$workflow" || fail 'workflow ponownie używa destrukcyjnego --delete.'
grep -Fq -- "--exclude='/storage/***'" "$workflow" || fail 'workflow nie chroni storage.'
grep -Fq -- "--exclude='/database/*.sqlite*'" "$workflow" || fail 'workflow nie chroni SQLite.'
grep -Fq 'cancel-in-progress: false' "$workflow" || fail 'produkcyjny deploy nadal może być anulowany.'
grep -Fq 'SSH_KNOWN_HOSTS' "$workflow" || fail 'workflow nie wymaga przypiętego klucza hosta.'
grep -Eq 'actions/checkout@[0-9a-f]{40}' "$workflow" || fail 'checkout nie jest przypięty do pełnego SHA.'
! grep -Fq 'rm -rf' "$remote_script" || fail 'zdalny deploy zawiera rekursywne kasowanie.'
grep -Fq 'publish-windows-listener.sh' "$windows_workflow" ||
    fail 'workflow instalatora omija zweryfikowany publikator.'
grep -Fq 'windows_root="${shared_root}/windows-print-listener"' "$windows_publisher" ||
    fail 'publikator instalatora nie używa trwałego katalogu shared.'
grep -Fq 'StrictHostKeyChecking yes' "$windows_workflow" ||
    fail 'workflow instalatora nie wymusza przypiętego klucza hosta SSH.'
! grep -Fq '$DEPLOY_PATH/tools/windows-print-listener/dist' "$windows_workflow" ||
    fail 'workflow instalatora nadal mutuje katalog aktywnego release aplikacji.'
grep -Fq 'deploy.lock' "$windows_publisher" ||
    fail 'publikator instalatora nie współdzieli blokady z deployem aplikacji.'
grep -Fq 'ln -s "${shared_root}/windows-print-listener" "$windows_dist"' "$remote_script" ||
    fail 'nowy release nie podłącza trwałego katalogu instalatora.'
grep -Fq 'erp:inspect-woocommerce-product-creation-recovery --limit=20' "$remote_script" ||
    fail 'deploy nie uruchamia diagnostyki odzyskiwania nowych produktów WooCommerce.'
grep -Fq 'erp:inspect-woocommerce-product-export-failures --limit=20' "$remote_script" ||
    fail 'deploy nie uruchamia diagnostyki błędów eksportu produktów WooCommerce.'
grep -Fq 'erp:inspect-woo-owned-variant-axis-repair --limit=30' "$remote_script" ||
    fail 'deploy nie uruchamia diagnostyki historycznej naprawy osi wariantów WooCommerce.'

creation_recovery_diagnostic_line="$(grep -n 'erp:inspect-woocommerce-product-creation-recovery --limit=20' "$remote_script" | cut -d: -f1)"
product_export_diagnostic_line="$(grep -n 'erp:inspect-woocommerce-product-export-failures --limit=20' "$remote_script" | cut -d: -f1)"
variant_axis_diagnostic_line="$(grep -n 'erp:inspect-woo-owned-variant-axis-repair --limit=30' "$remote_script" | cut -d: -f1)"
[[ "$creation_recovery_diagnostic_line" -lt "$product_export_diagnostic_line" ]] ||
    fail 'diagnostyka eksportu produktów nie jest uruchamiana po diagnostyce tworzenia produktów.'
[[ "$product_export_diagnostic_line" -lt "$variant_axis_diagnostic_line" ]] ||
    fail 'diagnostyka osi wariantów nie jest uruchamiana po diagnostyce eksportu produktów.'

backup_line="$(grep -n 'backup-database.php' "$remote_script" | tail -n 1 | cut -d: -f1)"
migration_line="$(grep -n 'artisan migrate --force' "$remote_script" | cut -d: -f1)"
preflight_line="$(grep -n '\"\$php_bin\" artisan erp:preflight' "$remote_script" | cut -d: -f1)"
activation_line="$(grep -n 'atomic_link \"\$release_path\" \"\$current_link\"' "$remote_script" | head -n 1 | cut -d: -f1)"
[[ "$backup_line" -lt "$migration_line" && "$migration_line" -lt "$preflight_line" && "$preflight_line" -lt "$activation_line" ]] ||
    fail 'kolejność backup -> migracja -> preflight -> aktywacja została naruszona.'

echo 'Testy skryptów wdrożeniowych przeszły.'
