#!/usr/bin/env bash

set -euo pipefail

deploy_path="${1:-$(pwd)}"
php_bin="${PHP_BIN:-$(command -v php)}"
marker="# sempre-erp-laravel-scheduler"

if [[ "$deploy_path" == *$'\n'* || "$php_bin" == *$'\n'* ]]; then
    echo "Nieprawidłowa ścieżka schedulera." >&2
    exit 1
fi

printf -v quoted_path '%q' "$deploy_path"
printf -v quoted_php '%q' "$php_bin"
cron_line="* * * * * cd ${quoted_path} && ${quoted_php} artisan schedule:run >> /dev/null 2>&1 ${marker}"
temporary_file="$(mktemp)"
trap 'rm -f "$temporary_file"' EXIT

crontab -l 2>/dev/null | grep -vF "$marker" > "$temporary_file" || true
printf '%s\n' "$cron_line" >> "$temporary_file"
crontab "$temporary_file"

echo "Scheduler Laravel jest aktywny: $cron_line"
