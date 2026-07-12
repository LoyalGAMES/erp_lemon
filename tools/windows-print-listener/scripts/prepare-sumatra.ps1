param([string] $DestinationPath = '')

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$version = '3.6.1'
$zipSha256 = '98b33a518d42986856d225064b0cd2d3643ecf78cbf84ab873d26cc51877a544'
$exeSha256 = '719f689b34f47be8ca105ce8484948474dafde0e106bab599e4a89326070c3d0'
$downloadUrl = "https://www.sumatrapdfreader.org/dl/rel/$version/SumatraPDF-$version-64.zip"
$licenseUrl = 'https://raw.githubusercontent.com/sumatrapdfreader/sumatrapdf/3.6.1rel/COPYING'
$licenseSha256 = '3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986'
if ([string]::IsNullOrWhiteSpace($DestinationPath)) {
    $DestinationPath = Join-Path $root 'build\windows-amd64\SumatraPDF.exe'
}
$DestinationPath = [IO.Path]::GetFullPath($DestinationPath)
$temporary = Join-Path ([IO.Path]::GetTempPath()) ('sempre-sumatra-' + [Guid]::NewGuid().ToString('N'))
$archive = "$temporary.zip"
$licensePath = Join-Path (Split-Path -Parent $DestinationPath) 'SumatraPDF-COPYING.txt'
$temporaryLicense = "$temporary-COPYING.txt"

try {
    New-Item -ItemType Directory -Force -Path $temporary, (Split-Path -Parent $DestinationPath) | Out-Null
    & curl.exe --fail --location --silent --show-error --output $archive $downloadUrl
    if ($LASTEXITCODE -ne 0) { throw "Pobieranie SumatraPDF zakończyło się kodem $LASTEXITCODE." }
    if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $zipSha256) {
        throw 'Archiwum SumatraPDF ma nieoczekiwany SHA-256.'
    }
    Expand-Archive -LiteralPath $archive -DestinationPath $temporary -Force
    $source = Join-Path $temporary "SumatraPDF-$version-64.exe"
    if ((Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant() -ne $exeSha256) {
        throw 'SumatraPDF.exe ma nieoczekiwany SHA-256.'
    }
    $signature = Get-AuthenticodeSignature -FilePath $source
    if ($signature.Status -ne 'Valid') {
        throw "Podpis Authenticode oficjalnego SumatraPDF jest nieprawidłowy: $($signature.Status)."
    }
    & curl.exe --fail --location --silent --show-error --output $temporaryLicense $licenseUrl
    if ($LASTEXITCODE -ne 0) { throw "Pobieranie licencji SumatraPDF zakończyło się kodem $LASTEXITCODE." }
    if ((Get-FileHash -LiteralPath $temporaryLicense -Algorithm SHA256).Hash.ToLowerInvariant() -ne $licenseSha256) {
        throw 'Licencja SumatraPDF ma nieoczekiwany SHA-256.'
    }
    Copy-Item -LiteralPath $source -Destination $DestinationPath -Force
    Copy-Item -LiteralPath $temporaryLicense -Destination $licensePath -Force
    Write-Host "Zweryfikowany renderer SumatraPDF $version: $DestinationPath"
} finally {
    Remove-Item -LiteralPath $archive, $temporary, $temporaryLicense -Recurse -Force -ErrorAction SilentlyContinue
}
