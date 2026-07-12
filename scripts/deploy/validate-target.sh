#!/usr/bin/env bash

set -euo pipefail

fail() {
    echo "Błąd konfiguracji wdrożenia: $*" >&2
    exit 1
}

for variable in SSH_HOST SSH_USER SSH_PORT SSH_PRIVATE_KEY SSH_KNOWN_HOSTS DEPLOY_PATH; do
    [[ -n "${!variable:-}" ]] || fail "brak wymaganej wartości ${variable}."
done

[[ "$SSH_HOST" != *$'\n'* && "$SSH_HOST" != *$'\r'* ]] || fail 'SSH_HOST zawiera znak nowej linii.'
[[ ${#SSH_HOST} -le 253 ]] || fail 'SSH_HOST jest zbyt długi.'

IFS='.' read -r -a host_labels <<<"$SSH_HOST"
[[ ${#host_labels[@]} -gt 0 ]] || fail 'SSH_HOST jest pusty.'
for label in "${host_labels[@]}"; do
    [[ "$label" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]] ||
        fail 'SSH_HOST musi być nazwą DNS albo adresem IPv4 bez dodatkowych opcji SSH.'
done

[[ "$SSH_USER" =~ ^[A-Za-z_][A-Za-z0-9._-]{0,63}$ ]] ||
    fail 'SSH_USER ma niedozwolony format.'
[[ "$SSH_PORT" =~ ^[0-9]{1,5}$ ]] ||
    fail 'SSH_PORT musi być liczbą całkowitą.'
((10#$SSH_PORT >= 1 && 10#$SSH_PORT <= 65535)) ||
    fail 'SSH_PORT musi mieścić się w zakresie 1-65535.'

[[ ${#DEPLOY_PATH} -le 200 ]] || fail 'DEPLOY_PATH jest zbyt długi.'
[[ "$DEPLOY_PATH" =~ ^/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+$ ]] ||
    fail 'DEPLOY_PATH musi być bezwzględną ścieżką z co najmniej dwoma segmentami.'
[[ "$DEPLOY_PATH" != "/" && "$DEPLOY_PATH" != */ ]] ||
    fail 'DEPLOY_PATH nie może wskazywać katalogu głównego ani kończyć się ukośnikiem.'

IFS='/' read -r -a deploy_segments <<<"${DEPLOY_PATH#/}"
for segment in "${deploy_segments[@]}"; do
    [[ "$segment" != '.' && "$segment" != '..' ]] ||
        fail 'DEPLOY_PATH nie może zawierać segmentów . ani ...'
done

if [[ -n "${RELEASE_ID:-}" ]]; then
    [[ "$RELEASE_ID" =~ ^[A-Fa-f0-9]{7,64}-[0-9]+-[0-9]+$ ]] ||
        fail 'RELEASE_ID ma niedozwolony format.'
fi

if [[ -n "${KNOWN_HOSTS_FILE:-}" ]]; then
    [[ -s "$KNOWN_HOSTS_FILE" ]] || fail 'plik SSH_KNOWN_HOSTS jest pusty.'
    ssh-keygen -l -f "$KNOWN_HOSTS_FILE" >/dev/null ||
        fail 'SSH_KNOWN_HOSTS nie zawiera prawidłowego klucza hosta.'

    known_host_lookup="$SSH_HOST"
    if [[ "$SSH_PORT" != '22' ]]; then
        known_host_lookup="[$SSH_HOST]:$SSH_PORT"
    fi
    ssh-keygen -F "$known_host_lookup" -f "$KNOWN_HOSTS_FILE" >/dev/null ||
        fail "SSH_KNOWN_HOSTS nie zawiera przypiętego wpisu dla ${known_host_lookup}."
fi

echo 'Konfiguracja celu wdrożenia jest prawidłowa.'
