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

release_version="${release_id%%-*}"
# PHP jest celowo przekazywany jako pojedynczy, nieinterpolowany skrypt walidatora.
# shellcheck disable=SC2016
release_mode="$(php -r '
    function failValidation(string $message): never
    {
        throw new RuntimeException($message);
    }

    function requireExactKeys(array $value, array $expected, string $context): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            failValidation($context." zawiera brakujące albo nieznane pola");
        }
    }

    function requireFingerprint(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match("/^[a-f0-9]{64}$/D", $value) !== 1) {
            failValidation("pole {$field} nie jest prawidłowym SHA-256");
        }

        return $value;
    }

    try {
        $manifest = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_is_list($manifest)) {
            failValidation("manifest release nie jest obiektem JSON");
        }

        $releaseChannel = $manifest["release_channel"] ?? null;
        $signingProfile = $manifest["signing_profile"] ?? null;
        if (!is_string($releaseChannel)
            || !in_array($releaseChannel, ["public", "internal"], true)
            || $signingProfile !== $releaseChannel) {
            failValidation("manifest zawiera nieprawidłowy albo niespójny tryb wydania");
        }

        $manifestKeys = [
            "product",
            "version",
            "commit",
            "target",
            "go_version",
            "signed",
            "timestamped",
            "release_channel",
            "signing_profile",
            "publisher_subject",
            "publisher_certificate_sha256",
            "artifacts",
        ];
        if ($releaseChannel === "internal") {
            $manifestKeys[] = "root_certificate_sha256";
            $manifestKeys[] = "trust_bootstrap";
        }
        requireExactKeys($manifest, $manifestKeys, "manifest release");

        if ($manifest["product"] !== "Sempre ERP Print Listener"
            || $manifest["version"] !== $argv[3]
            || $manifest["target"] !== "windows/amd64"
            || $manifest["signed"] !== true
            || $manifest["timestamped"] !== true
            || !is_string($manifest["commit"])
            || preg_match("/^[a-f0-9]{40}$/D", $manifest["commit"]) !== 1
            || !is_string($manifest["go_version"])
            || trim($manifest["go_version"]) === ""
            || !is_string($manifest["publisher_subject"])
            || trim($manifest["publisher_subject"]) === ""
            || strlen($manifest["publisher_subject"]) > 500
            || preg_match("/[\\x00-\\x1f\\x7f]/", $manifest["publisher_subject"]) === 1
            || !is_array($manifest["artifacts"])
            || !array_is_list($manifest["artifacts"])) {
            throw new RuntimeException("manifest release jest nieprawidłowy");
        }

        $publisherFingerprint = requireFingerprint(
            $manifest["publisher_certificate_sha256"],
            "publisher_certificate_sha256"
        );
        $rootFingerprint = $releaseChannel === "internal"
            ? requireFingerprint($manifest["root_certificate_sha256"], "root_certificate_sha256")
            : null;
        if ($releaseChannel === "internal" && $manifest["trust_bootstrap"] !== "installer") {
            failValidation("wewnętrzne wydanie nie deklaruje bootstrapu zaufania przez instalator");
        }

        $artifactNames = [
            "lemon-print-listener.exe",
            "SempreERP-PrintListener-Setup.exe",
        ];
        $publishedArtifactNames = ["SempreERP-PrintListener-Setup.exe"];
        $expectedFiles = [
            "SempreERP-PrintListener-Setup.exe",
            "RELEASE-MANIFEST.json",
            "SHA256SUMS.txt",
        ];
        if ($releaseChannel === "internal") {
            $certificateNames = [
                "SempreERP-Internal-Root.cer",
                "SempreERP-Internal-Publisher.cer",
            ];
            array_push($artifactNames, ...$certificateNames);
            array_push($publishedArtifactNames, ...$certificateNames);
            array_push($expectedFiles, ...$certificateNames);
        }

        $actualFiles = array_values(array_diff(scandir($argv[2]), [".", ".."]));
        sort($actualFiles, SORT_STRING);
        $sortedExpectedFiles = $expectedFiles;
        sort($sortedExpectedFiles, SORT_STRING);
        if ($actualFiles !== $sortedExpectedFiles) {
            failValidation("katalog tymczasowy zawiera brakujące albo nieoczekiwane pliki");
        }
        foreach ($expectedFiles as $fileName) {
            $filePath = $argv[2].DIRECTORY_SEPARATOR.$fileName;
            if (!is_file($filePath) || is_link($filePath)) {
                failValidation("{$fileName} nie jest zwykłym plikiem");
            }
        }

        $artifacts = [];
        foreach ($manifest["artifacts"] as $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)) {
                failValidation("wpis artefaktu nie jest obiektem JSON");
            }
            requireExactKeys($artifact, ["name", "size", "sha256"], "wpis artefaktu");
            $name = $artifact["name"];
            if (!is_string($name)
                || !in_array($name, $artifactNames, true)
                || array_key_exists($name, $artifacts)
                || !is_int($artifact["size"])
                || $artifact["size"] <= 0) {
                failValidation("manifest zawiera nieprawidłowy albo powtórzony artefakt");
            }
            requireFingerprint($artifact["sha256"], "artifacts.sha256");
            $artifacts[$name] = $artifact;
        }

        $declaredNames = array_keys($artifacts);
        sort($declaredNames, SORT_STRING);
        $sortedArtifactNames = $artifactNames;
        sort($sortedArtifactNames, SORT_STRING);
        if ($declaredNames !== $sortedArtifactNames) {
            failValidation("manifest nie deklaruje dokładnego zestawu artefaktów profilu");
        }

        foreach ($publishedArtifactNames as $name) {
            $path = $argv[2].DIRECTORY_SEPARATOR.$name;
            $actualSize = filesize($path);
            $actualHash = hash_file("sha256", $path);
            if ($actualSize !== $artifacts[$name]["size"]
                || !is_string($actualHash)
                || !hash_equals($artifacts[$name]["sha256"], $actualHash)) {
                failValidation("{$name} nie odpowiada podpisanemu manifestowi");
            }
        }

        if ($releaseChannel === "internal") {
            if (!hash_equals($publisherFingerprint, $artifacts["SempreERP-Internal-Publisher.cer"]["sha256"])
                || !hash_equals($rootFingerprint, $artifacts["SempreERP-Internal-Root.cer"]["sha256"])) {
                failValidation("fingerprint certyfikatu nie odpowiada artefaktowi CER");
            }
        }

        $checksumContents = file_get_contents($argv[4]);
        if (!is_string($checksumContents) || $checksumContents === "" || str_contains($checksumContents, "\0")) {
            failValidation("SHA256SUMS.txt jest pusty albo nieprawidłowy");
        }
        $checksumLines = preg_split("/\\r\\n|\\n|\\r/", $checksumContents);
        if ($checksumLines === false) {
            failValidation("nie można odczytać SHA256SUMS.txt");
        }
        if (end($checksumLines) === "") {
            array_pop($checksumLines);
        }

        $checksums = [];
        foreach ($checksumLines as $line) {
            if (!is_string($line)
                || preg_match("/^([a-f0-9]{64}) \\*([A-Za-z0-9._-]+)$/D", $line, $matches) !== 1
                || array_key_exists($matches[2] ?? "", $checksums)) {
                failValidation("SHA256SUMS.txt zawiera nieprawidłowy albo powtórzony wpis");
            }
            $checksums[$matches[2]] = $matches[1];
        }

        $checksumNames = array_keys($checksums);
        sort($checksumNames, SORT_STRING);
        if ($checksumNames !== $sortedArtifactNames) {
            failValidation("SHA256SUMS.txt nie zawiera dokładnego zestawu artefaktów profilu");
        }
        foreach ($artifacts as $name => $artifact) {
            if (!hash_equals($artifact["sha256"], $checksums[$name])) {
                failValidation("suma SHA-256 dla {$name} nie odpowiada manifestowi");
            }
        }

        echo $releaseChannel;
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
        exit(1);
    }
' \
    "${staging_resolved}/RELEASE-MANIFEST.json" \
    "$staging_resolved" \
    "$release_version" \
    "${staging_resolved}/SHA256SUMS.txt")" ||
    fail 'manifest, artefakty albo sumy SHA-256 nie przeszły weryfikacji.'
[[ "$release_mode" == 'public' || "$release_mode" == 'internal' ]] ||
    fail 'walidator zwrócił nieprawidłowy tryb wydania.'

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

echo "Opublikowano podpisany instalator Windows ${release_version} (${release_mode}) w ${release_path}."
