#!/usr/bin/env bash

set -Eeuo pipefail
umask 027

operation="${1:-}"
deploy_path="${2:-}"
release_id="${3:-}"

fail() {
    echo "Błąd wdrożenia: $*" >&2
    exit 1
}

[[ "$operation" == 'deploy' || "$operation" == 'rollback' ]] ||
    fail 'pierwszym argumentem musi być deploy albo rollback.'
[[ "$deploy_path" =~ ^/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+$ ]] ||
    fail 'nieprawidłowy DEPLOY_PATH.'

deploy_root="${deploy_path}.deploy"
releases_root="${deploy_root}/releases"
shared_root="${deploy_root}/shared"
backups_root="${deploy_root}/backups"
current_link="${deploy_root}/CURRENT"
previous_link="${deploy_root}/PREVIOUS"
marker="${deploy_root}/DEPLOY_PATH"

[[ -f "$marker" && "$(cat "$marker")" == "$deploy_path" ]] ||
    fail 'brak zgodnego znacznika katalogu wdrożeniowego.'
[[ -d "$releases_root" && -d "$shared_root" && -d "$backups_root" ]] ||
    fail 'layout release/shared/backups nie został zainicjalizowany.'

command -v flock >/dev/null 2>&1 || fail 'na serwerze brakuje programu flock.'
command -v rsync >/dev/null 2>&1 || fail 'na serwerze brakuje programu rsync.'
command -v pgrep >/dev/null 2>&1 || fail 'na serwerze brakuje programu pgrep.'
php_bin="${PHP_BIN:-$(command -v php || true)}"
composer_bin="${COMPOSER_BIN:-$(command -v composer || true)}"
[[ -n "$php_bin" && -x "$php_bin" ]] || fail 'nie znaleziono wykonywalnego PHP.'

exec 9>"${deploy_root}/deploy.lock"
flock -w 300 9 || fail 'inne wdrożenie utrzymuje blokadę dłużej niż 300 sekund.'

atomic_link() {
    local target="$1"
    local link_path="$2"
    local temporary_link="${link_path}.tmp.$$"

    [[ -d "$target" ]] || fail "cel symlinka nie jest katalogiem: ${target}."
    [[ ! -e "$temporary_link" && ! -L "$temporary_link" ]] ||
        fail "tymczasowy symlink już istnieje: ${temporary_link}."
    ln -s "$target" "$temporary_link"
    mv -Tf "$temporary_link" "$link_path"
}

resolved_release() {
    local candidate="$1"
    local resolved
    resolved="$(realpath -e "$candidate")" || fail "nie można rozwiązać wydania ${candidate}."
    [[ -d "$resolved" && "$resolved" == "${releases_root}/"* ]] ||
        fail "wydanie wychodzi poza katalog releases: ${resolved}."
    printf '%s\n' "$resolved"
}

schema_is_backward_compatible() {
    local source_release="$1"
    local target_release="$2"
    local migration
    local migration_name

    [[ -d "${source_release}/database/migrations" ]] || return 0
    while IFS= read -r -d '' migration; do
        migration_name="$(basename "$migration")"
        [[ -f "${target_release}/database/migrations/${migration_name}" ]] || return 1
    done < <(find "${source_release}/database/migrations" -maxdepth 1 -type f -name '*.php' -print0)

    return 0
}

copy_sqlite_snapshot() {
    local source="$1"
    local destination="$2"
    local staged="${destination}.stage.${release_id:-rollback}.$$"

    [[ -f "$source" ]] || return 0
    [[ ! -e "$staged" ]] || fail "tymczasowa kopia SQLite już istnieje: ${staged}."
    "$php_bin" -r '
        $source = $argv[1];
        $destination = $argv[2];
        $pdo = new PDO("sqlite:".$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("PRAGMA busy_timeout = 30000");
        $pdo->exec("VACUUM INTO ".$pdo->quote($destination));
    ' "$source" "$staged"
    chmod 0660 "$staged"
    mv -fT "$staged" "$destination"
}

migrate_windows_listener_dist() {
    local source_root="$1"
    local source_dist="${source_root}/tools/windows-print-listener/dist"
    local shared_dist="${shared_root}/windows-print-listener"
    local resolved_source=''
    local resolved_shared=''

    if [[ -L "$source_dist" ]]; then
        resolved_source="$(realpath -e "$source_dist")" ||
            fail 'symlink starego katalogu instalatora Windows jest uszkodzony.'
        resolved_shared="$(realpath -e "$shared_dist")" ||
            fail 'współdzielony katalog instalatora Windows nie istnieje.'
        [[ "$resolved_source" == "$resolved_shared" ]] ||
            fail 'stary katalog instalatora Windows wskazuje poza katalog shared.'
        return 0
    fi
    [[ -d "$source_dist" ]] || return 0
    [[ -z "$(find "$source_dist" -type l -print -quit)" ]] ||
        fail 'stary katalog instalatora Windows zawiera niedozwolony symlink.'

    mkdir -p "${shared_dist}/releases"
    rsync -a --ignore-existing "${source_dist}/" "${shared_dist}/"

    if [[ -f "${shared_dist}/CURRENT" ]]; then
        local current_release
        current_release="$(tr -d '\r\n' <"${shared_dist}/CURRENT")"
        [[ "$current_release" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] ||
            fail 'wskaźnik CURRENT instalatora Windows ma nieprawidłowy format.'
        [[ -d "${shared_dist}/releases/${current_release}" ]] ||
            fail 'wskaźnik CURRENT instalatora Windows nie ma kompletnego wydania.'
    fi
}

sync_bootstrap_runtime() {
    local source_root="$1"

    if [[ -d "${source_root}/storage" && ! -L "${source_root}/storage" ]]; then
        rsync -a "${source_root}/storage/" "${shared_root}/storage/"
    fi
    if [[ -d "${source_root}/public/uploads" && ! -L "${source_root}/public/uploads" ]]; then
        rsync -a "${source_root}/public/uploads/" "${shared_root}/public/uploads/"
    fi
    if [[ -f "${source_root}/database/database.sqlite" && ! -L "${source_root}/database/database.sqlite" ]]; then
        copy_sqlite_snapshot \
            "${source_root}/database/database.sqlite" \
            "${shared_root}/database/database.sqlite"
    fi
    migrate_windows_listener_dist "$source_root"
}

runtime_group() {
    local group_file="${deploy_root}/RUNTIME_GROUP"
    local group

    if [[ -f "$group_file" ]]; then
        group="$(cat "$group_file")"
    elif [[ -d "$deploy_path/storage" ]]; then
        group="$(stat -c '%G' "$deploy_path/storage")"
        printf '%s\n' "$group" >"$group_file"
        chmod 0444 "$group_file"
    else
        group="$(id -gn)"
        printf '%s\n' "$group" >"$group_file"
        chmod 0444 "$group_file"
    fi
    [[ "$group" =~ ^[A-Za-z0-9._-]+$ ]] || fail 'zapamiętana grupa runtime ma nieprawidłową nazwę.'
    local group_is_available=0
    local member_group
    for member_group in $(id -nG); do
        if [[ "$member_group" == "$group" ]]; then
            group_is_available=1
            break
        fi
    done
    [[ "$group_is_available" -eq 1 ]] ||
        fail "użytkownik wdrożeniowy nie należy do grupy runtime ${group}."
    printf '%s\n' "$group"
}

secure_runtime_permissions() {
    local release="$1"
    local group="$2"

    chgrp -R "$group" "${shared_root}/storage" "${shared_root}/public/uploads" "${shared_root}/database"
    chgrp -R "$group" "${shared_root}/windows-print-listener"
    find "${shared_root}/storage" -type d -exec chmod 2770 {} +
    find "${shared_root}/storage" -type f -exec chmod 0660 {} +
    find "${shared_root}/public/uploads" -type d -exec chmod 2775 {} +
    find "${shared_root}/public/uploads" -type f -exec chmod 0664 {} +
    find "${shared_root}/database" -type d -exec chmod 2770 {} +
    find "${shared_root}/database" -type f -exec chmod 0660 {} +
    find "${shared_root}/windows-print-listener" -type d -exec chmod 2750 {} +
    find "${shared_root}/windows-print-listener" -type f -exec chmod 0640 {} +
    chgrp "$group" "${shared_root}/.env" "$release/bootstrap/cache"
    chmod 0640 "${shared_root}/.env"
    find "$release/bootstrap/cache" -type d -exec chmod 2770 {} +
    find "$release/bootstrap/cache" -type f -exec chmod 0660 {} +
    if [[ -d "$release/vendor" ]]; then
        chgrp -R "$group" "$release/vendor"
        chmod -R g+rX,o-rwx "$release/vendor"
    fi
}

wait_for_queue_workers() {
    local timeout_seconds="${QUEUE_DRAIN_TIMEOUT_SECONDS:-920}"
    local deadline
    local polls_since_restart=0
    local restart_every_polls=15

    [[ "$timeout_seconds" =~ ^[1-9][0-9]*$ ]] ||
        fail 'QUEUE_DRAIN_TIMEOUT_SECONDS musi być dodatnią liczbą całkowitą.'
    deadline=$((SECONDS + timeout_seconds))

    # The scheduler starts workers from DEPLOY_PATH as `php artisan
    # queue:work`, so the absolute application path is not present in their
    # process title. The deploy account is dedicated to this application;
    # wait for all of its Laravel queue workers after queue:restart instead of
    # allowing an old full catalog export to overlap a data migration.
    #
    # A background worker can race with the first queue:restart: it may boot
    # after that cache timestamp is written but after maintenance mode already
    # prevents it from taking a job. Such an idle worker will wait forever and
    # never observe the older restart timestamp. Refresh the graceful restart
    # signal every 30 seconds. Running jobs still finish their current job;
    # late idle workers observe a newer timestamp and exit cleanly.
    while pgrep -u "$(id -u)" -f -- '(^|[[:space:]/])artisan[[:space:]]+queue:work([[:space:]]|$)' >/dev/null; do
        (( SECONDS < deadline )) ||
            fail "workery kolejki nie zakończyły pracy w ciągu ${timeout_seconds} sekund."

        (( polls_since_restart += 1 ))
        if (( polls_since_restart >= restart_every_polls )); then
            "$php_bin" "$deploy_path/artisan" queue:restart
            polls_since_restart=0
        fi

        sleep 2
    done
}

prepare_release_runtime() {
    local release="$1"
    local windows_dist

    mkdir -p \
        "${shared_root}/storage/app/private" \
        "${shared_root}/storage/app/public" \
        "${shared_root}/storage/framework/cache/data" \
        "${shared_root}/storage/framework/sessions" \
        "${shared_root}/storage/framework/views" \
        "${shared_root}/storage/logs" \
        "${shared_root}/public/uploads" \
        "${shared_root}/database" \
        "${shared_root}/windows-print-listener/releases" \
        "$release/bootstrap/cache" \
        "$release/public" \
        "$release/database" \
        "$release/tools/windows-print-listener"

    if [[ -e "$release/storage" || -L "$release/storage" ]]; then
        [[ ! -e "$release/.storage-skeleton" && ! -L "$release/.storage-skeleton" ]] ||
            fail 'wydanie ma niejednoznaczny katalog storage.'
        mv "$release/storage" "$release/.storage-skeleton"
    fi
    ln -s "${shared_root}/storage" "$release/storage"

    [[ ! -e "$release/.env" && ! -L "$release/.env" ]] ||
        fail 'wydanie nie może zawierać własnego pliku .env.'
    ln -s "${shared_root}/.env" "$release/.env"

    if [[ -e "$release/public/uploads" || -L "$release/public/uploads" ]]; then
        [[ ! -e "$release/public/.uploads-skeleton" && ! -L "$release/public/.uploads-skeleton" ]] ||
            fail 'wydanie ma niejednoznaczny katalog public/uploads.'
        mv "$release/public/uploads" "$release/public/.uploads-skeleton"
    fi
    ln -s "${shared_root}/public/uploads" "$release/public/uploads"

    windows_dist="$release/tools/windows-print-listener/dist"
    [[ ! -e "$windows_dist" && ! -L "$windows_dist" ]] ||
        fail 'wydanie nie może zawierać własnego katalogu instalatora Windows.'
    ln -s "${shared_root}/windows-print-listener" "$windows_dist"

    if [[ -f "${shared_root}/database/database.sqlite" ]]; then
        if [[ -e "$release/database/database.sqlite" || -L "$release/database/database.sqlite" ]]; then
            mv "$release/database/database.sqlite" "$release/database/.database.sqlite-source"
        fi
        ln -s "${shared_root}/database/database.sqlite" "$release/database/database.sqlite"
    fi
}

completed=0
activated=0
maintenance_enabled=0
migration_started=0
migration_completed=0
bootstrap_moved=0
size_order_sync_verified=0
variant_axis_repair_verified=0
previous_release=''
rollback_release=''
legacy_release=''

handle_exit() {
    local status=$?
    trap - EXIT
    if [[ "$status" -eq 0 || "$completed" -eq 1 ]]; then
        exit "$status"
    fi

    set +e
    echo 'Wdrożenie nie powiodło się; sprawdzam bezpieczną ścieżkę odzyskania.' >&2
    if [[ "$activated" -eq 1 && -n "$rollback_release" && -d "$rollback_release" ]]; then
        if [[ "$migration_completed" -eq 1 ]] &&
            ! schema_is_backward_compatible "$release_path" "$rollback_release"; then
            echo 'Migracje zmieniły schemat wymagany przez nowe wydanie. CURRENT nie został cofnięty, a aplikacja pozostaje w maintenance do ręcznej weryfikacji lub odtworzenia backupu.' >&2
        else
            atomic_link "$rollback_release" "$current_link"
            "$php_bin" "$deploy_path/artisan" queue:restart >/dev/null 2>&1
            "$php_bin" "$deploy_path/artisan" up >/dev/null 2>&1
            echo "Przywrócono kod z ${rollback_release}. Migracje nie zostały automatycznie cofnięte." >&2
        fi
    elif [[ "$bootstrap_moved" -eq 1 && ! -e "$deploy_path" && -d "$legacy_release" ]]; then
        mv "$legacy_release" "$deploy_path"
        if [[ "$migration_completed" -eq 1 ]] &&
            ! schema_is_backward_compatible "$release_path" "$deploy_path"; then
            echo 'Przywrócono ścieżkę pierwotnej aplikacji, ale po zmianie schematu pozostaje ona w maintenance do ręcznego odzyskania.' >&2
        else
            "$php_bin" "$deploy_path/artisan" up >/dev/null 2>&1
            echo 'Przywrócono pierwotny katalog aplikacji po nieudanym bootstrapie.' >&2
        fi
    elif [[ "$maintenance_enabled" -eq 1 && -d "$deploy_path" ]]; then
        if [[ "$migration_started" -eq 1 ]]; then
            echo 'Migracja bazy rozpoczęła się; aplikacja pozostaje w maintenance do ręcznej weryfikacji/odtworzenia backupu.' >&2
        else
            "$php_bin" "$deploy_path/artisan" up >/dev/null 2>&1
        fi
    fi
    exit "$status"
}
trap handle_exit EXIT

if [[ "$operation" == 'rollback' ]]; then
    [[ -L "$deploy_path" && "$(readlink "$deploy_path")" == "$current_link" ]] ||
        fail 'rollback wymaga DEPLOY_PATH zarządzanego przez symlink CURRENT.'
    [[ -L "$current_link" && -L "$previous_link" ]] ||
        fail 'brak CURRENT albo PREVIOUS do rollbacku.'

    current_release="$(resolved_release "$current_link")"
    previous_release="$(resolved_release "$previous_link")"
    [[ "$current_release" != "$previous_release" ]] || fail 'CURRENT i PREVIOUS wskazują to samo wydanie.'
    schema_is_backward_compatible "$current_release" "$previous_release" ||
        fail 'rollback kodu jest niezgodny z aktualnym schematem bazy: poprzednie wydanie nie zawiera wszystkich migracji CURRENT. Najpierw odtwórz zgodny backup bazy w maintenance.'
    rollback_release="$current_release"

    "$php_bin" "$previous_release/artisan" erp:preflight
    "$php_bin" "$previous_release/artisan" schedule:list >/dev/null
    atomic_link "$current_release" "$previous_link"
    atomic_link "$previous_release" "$current_link"
    activated=1
    "$php_bin" "$deploy_path/artisan" queue:restart
    bash "$deploy_path/scripts/install-laravel-scheduler.sh" "$deploy_path"
    "$php_bin" "$deploy_path/artisan" up

    completed=1
    echo "Rollback kodu zakończony: CURRENT -> ${previous_release}. Baza danych nie została cofnięta."
    exit 0
fi

[[ "$release_id" =~ ^[A-Fa-f0-9]{7,64}-[0-9]+-[0-9]+$ ]] ||
    fail 'nieprawidłowy identyfikator wydania.'
release_path="$(resolved_release "${releases_root}/${release_id}")"
[[ -f "$release_path/artisan" && -f "$release_path/composer.lock" ]] ||
    fail 'wydanie nie zawiera kompletnej aplikacji Laravel.'
[[ -n "$composer_bin" && -x "$composer_bin" ]] || fail 'nie znaleziono programu composer.'

bootstrap_mode='existing-layout'
if [[ -L "$deploy_path" ]]; then
    [[ "$(readlink "$deploy_path")" == "$current_link" ]] ||
        fail 'DEPLOY_PATH jest obcym symlinkiem; wdrożenie zostało zatrzymane.'
    [[ -L "$current_link" ]] || fail 'DEPLOY_PATH wskazuje layout bez CURRENT.'
    previous_release="$(resolved_release "$current_link")"
    rollback_release="$previous_release"
elif [[ -d "$deploy_path" ]]; then
    bootstrap_mode='legacy-directory'
    [[ -f "$deploy_path/artisan" && -f "$deploy_path/.env" ]] ||
        fail 'istniejący DEPLOY_PATH nie wygląda jak aplikacja Laravel.'
elif [[ ! -e "$deploy_path" ]]; then
    bootstrap_mode='fresh'
else
    fail 'DEPLOY_PATH istnieje, ale nie jest katalogiem ani obsługiwanym symlinkiem.'
fi

if [[ "$bootstrap_mode" == 'legacy-directory' ]]; then
    if [[ ! -f "${shared_root}/.env" ]]; then
        cp -p "$deploy_path/.env" "${shared_root}/.env"
    fi
    sync_bootstrap_runtime "$deploy_path"
elif [[ "$bootstrap_mode" == 'existing-layout' ]]; then
    sync_bootstrap_runtime "$previous_release"
fi
[[ -f "${shared_root}/.env" ]] ||
    fail "brak ${shared_root}/.env; świeży serwer wymaga wcześniejszego umieszczenia konfiguracji."

# A fresh installation may use Laravel's default SQLite connection. The file
# must exist before runtime symlinks, backup and migrations are prepared.
# MySQL/PostgreSQL installations merely keep this unused zero-length fallback.
if [[ "$bootstrap_mode" == 'fresh' && ! -e "${shared_root}/database/database.sqlite" ]]; then
    : >"${shared_root}/database/database.sqlite"
    chmod 0660 "${shared_root}/database/database.sqlite"
fi

prepare_release_runtime "$release_path"
group="$(runtime_group)"
secure_runtime_permissions "$release_path" "$group"

(
    cd "$release_path"
    "$composer_bin" install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader
)
secure_runtime_permissions "$release_path" "$group"

if [[ "$bootstrap_mode" != 'fresh' ]]; then
    "$php_bin" "$deploy_path/artisan" down --retry=60
    maintenance_enabled=1
    "$php_bin" "$deploy_path/artisan" queue:restart
    wait_for_queue_workers
else
    # There is no previous application or worker to stop, but the deployment
    # sync command still requires the same explicit maintenance invariant.
    "$php_bin" "$release_path/artisan" down --retry=60
    maintenance_enabled=1
fi
if [[ "$bootstrap_mode" == 'legacy-directory' ]]; then
    sync_bootstrap_runtime "$deploy_path"
    secure_runtime_permissions "$release_path" "$group"
fi

backup_directory="${backups_root}/${release_id}"
mkdir "$backup_directory"
chmod 0700 "$backup_directory"
"$php_bin" "$release_path/scripts/deploy/backup-database.php" \
    "$release_path" \
    "$backup_directory" \
    "$deploy_path"

migration_started=1
(
    cd "$release_path"
    "$php_bin" artisan migrate --force
)
migration_completed=1
(
    cd "$release_path"
    "$php_bin" artisan optimize:clear
    "$php_bin" artisan config:cache
    "$php_bin" artisan route:cache
    "$php_bin" artisan view:cache
    "$php_bin" artisan storage:link --force
    "$php_bin" artisan schedule:list >/dev/null
    "$php_bin" artisan erp:preflight
)

# Every release performs a fresh existing-term-only WooCommerce repair. The
# normal asynchronous job must share the full catalog lock with product
# exports, because an overlapping export can overwrite term menu_order. During
# deploy the application is in maintenance and all old queue workers have
# exited, so this one guarded synchronous command can safely bypass a stale
# cache lock left by a terminated worker. This must precede product-axis repair:
# Woo materializes a global attribute's product option order from these term
# ranks, and an axis written first would otherwise read back in the old order.
size_order_sync_since="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
if (
    cd "$release_path"
    "$php_bin" artisan erp:sync-woocommerce-global-size-order-during-maintenance \
        --trigger="deploy_${release_id}"
); then
    if (
        cd "$release_path"
        "$php_bin" artisan erp:verify-woocommerce-global-size-order-sync \
            --since="$size_order_sync_since" \
            --trigger="deploy_${release_id}"
    ); then
        size_order_sync_verified=1
    else
        echo 'Błąd: synchroniczna naprawa kolejności rozmiarów nie spełniła świeżego warunku końcowego.' >&2
    fi
else
    echo 'Błąd: synchroniczna naprawa kolejności rozmiarów WooCommerce zakończyła się błędem.' >&2
fi

# Migrations may mark historical Woo product families for an axis-only repair.
# All old workers have exited, global Size order is now authoritative and
# maintenance prevents every web catalog writer, so finish the exact current
# revision before reporting the release as successful. The repair job only
# queues its broad follow-up catalog export; it is not executed synchronously.
if [[ "$size_order_sync_verified" -eq 1 ]]; then
    variant_axis_repair_succeeded=0
    if (
        cd "$release_path"
        "$php_bin" artisan erp:repair-woo-owned-variant-axes-during-maintenance
    ); then
        variant_axis_repair_succeeded=1
    else
        echo 'Błąd: synchroniczna naprawa osi wariantów WooCommerce nie zakończyła się czystym stanem.' >&2
    fi
    if (
        cd "$release_path"
        "$php_bin" artisan erp:verify-woo-owned-variant-axis-repair
    ); then
        if [[ "$variant_axis_repair_succeeded" -eq 1 ]]; then
            variant_axis_repair_verified=1
        fi
    else
        echo 'Błąd: bieżąca rewizja naprawy osi wariantów WooCommerce ma nierozwiązane rodziny.' >&2
    fi
else
    echo 'Błąd: pominięto naprawę osi wariantów, ponieważ globalna kolejność rozmiarów nie została potwierdzona.' >&2
fi

# Custom storefront settings belong to Lemon Elementor Theme and are only six
# product meta fields. Flush their narrow corrective revisions synchronously
# while the application is still in maintenance and every old queue worker has
# exited.
# A Woo outage is reported but does not make an otherwise healthy ERP release
# unrecoverable; the durable pending state remains available for a later retry.
if ! (
    cd "$release_path"
    "$php_bin" artisan erp:sync-pending-woocommerce-product-labels-during-maintenance --limit=100
); then
    echo 'Błąd: bezpośrednia synchronizacja metadanych motywu WooCommerce nie zakończyła się w pełni; stan oczekujący pozostawiono do ponowienia.' >&2
fi

# A normal product save can already have a durable full export reservation or
# failure from the previous release. Repair the newest such products now so
# their label, shipping date and preorder configuration reaches WooCommerce
# without waiting for historical catalog/attribute work.
if ! (
    cd "$release_path"
    "$php_bin" artisan erp:sync-pending-woocommerce-storefront-metadata-during-maintenance --limit=50
); then
    echo 'Błąd: część oczekujących konfiguracji sklepowych WooCommerce nie została wysłana; pełny eksport pozostawiono do ponowienia.' >&2
fi

if [[ "$bootstrap_mode" == 'legacy-directory' ]]; then
    legacy_release="${releases_root}/legacy-${release_id}"
    [[ ! -e "$legacy_release" ]] || fail 'katalog legacy dla bootstrapu już istnieje.'
    [[ "$(stat -c '%d' "$deploy_path")" == "$(stat -c '%d' "$releases_root")" ]] ||
        fail 'pierwszy atomowy deploy wymaga DEPLOY_PATH i releases na tym samym systemie plików.'

    mv "$deploy_path" "$legacy_release"
    bootstrap_moved=1
    previous_release="$legacy_release"
    rollback_release="$legacy_release"
    atomic_link "$legacy_release" "$previous_link"
    atomic_link "$release_path" "$current_link"

    live_link="${deploy_path}.tmp.$$"
    ln -s "$current_link" "$live_link"
    mv -T "$live_link" "$deploy_path"
    bootstrap_moved=0
    activated=1
elif [[ "$bootstrap_mode" == 'fresh' ]]; then
    atomic_link "$release_path" "$current_link"
    live_link="${deploy_path}.tmp.$$"
    ln -s "$current_link" "$live_link"
    mv -T "$live_link" "$deploy_path"
    activated=1
else
    atomic_link "$previous_release" "$previous_link"
    atomic_link "$release_path" "$current_link"
    activated=1
fi

"$php_bin" "$deploy_path/artisan" erp:preflight
"$php_bin" "$deploy_path/artisan" schedule:list >/dev/null
bash "$deploy_path/scripts/install-laravel-scheduler.sh" "$deploy_path"
"$php_bin" "$deploy_path/artisan" queue:restart
if [[ "$maintenance_enabled" -eq 1 ]]; then
    "$php_bin" "$deploy_path/artisan" up
    maintenance_enabled=0
fi
"$php_bin" "$deploy_path/artisan" erp:inspect-woocommerce-product-creation-recovery --limit=20
"$php_bin" "$deploy_path/artisan" erp:inspect-woocommerce-product-export-failures --limit=20
"$php_bin" "$deploy_path/artisan" erp:inspect-woo-owned-variant-axis-repair --limit=30

completed=1
if [[ "$variant_axis_repair_verified" -ne 1 ]]; then
    fail 'Kod został aktywowany, ale naprawa osi wariantów WooCommerce nie zakończyła się sukcesem; sprawdź stan bieżącej rewizji powyżej.'
fi
if [[ "$size_order_sync_verified" -ne 1 ]]; then
    fail 'Kod został aktywowany, ale naprawa globalnej kolejności rozmiarów WooCommerce nie zakończyła się sukcesem; sprawdź log joba powyżej.'
fi
echo "Wdrożenie aktywne: ${release_path}"
echo "Backup bazy: ${backup_directory}"
if [[ -n "$previous_release" ]]; then
    echo "Rollback kodu: bash ${deploy_path}/scripts/deploy/remote-release.sh rollback ${deploy_path}"
fi
