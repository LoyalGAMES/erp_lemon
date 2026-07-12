#!/usr/bin/env bash

set -euo pipefail

deploy_path="${1:-$(pwd)}"
php_bin="${PHP_BIN:-$(command -v php)}"
marker="# sempre-erp-laravel-scheduler"
scheduler_log="${SCHEDULER_LOG:-${deploy_path}/storage/logs/scheduler.log}"

if [[ "$deploy_path" == *$'\n'* || "$php_bin" == *$'\n'* || "$scheduler_log" == *$'\n'* ]]; then
    echo "Nieprawidłowa ścieżka schedulera." >&2
    exit 1
fi

printf -v quoted_path '%q' "$deploy_path"
printf -v quoted_php '%q' "$php_bin"
printf -v quoted_log '%q' "$scheduler_log"
cron_line="* * * * * cd ${quoted_path} && ${quoted_php} artisan schedule:run >> ${quoted_log} 2>&1 ${marker}"
temporary_directory="$(mktemp -d)"
current_crontab="${temporary_directory}/current"
new_crontab="${temporary_directory}/new"
crontab_error="${temporary_directory}/error"
trap 'rm -rf "$temporary_directory"' EXIT

set +e
crontab -l >"$current_crontab" 2>"$crontab_error"
crontab_status=$?
set -e
if [[ "$crontab_status" -ne 0 ]]; then
    if [[ "$crontab_status" -eq 1 ]] && grep -qi 'no crontab' "$crontab_error"; then
        : >"$current_crontab"
    else
        echo "Nie można bezpiecznie odczytać istniejącego crontaba; niczego nie zmieniono." >&2
        cat "$crontab_error" >&2
        exit "$crontab_status"
    fi
fi

set +e
grep -vF "$marker" "$current_crontab" >"$new_crontab"
grep_status=$?
set -e
if [[ "$grep_status" -gt 1 ]]; then
    echo "Nie można przygotować nowego crontaba; niczego nie zmieniono." >&2
    exit "$grep_status"
fi

printf '%s\n' "$cron_line" >>"$new_crontab"
crontab "$new_crontab"

echo "Scheduler Laravel jest aktywny: $cron_line"
