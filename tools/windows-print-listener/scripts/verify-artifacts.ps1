param(
    [string] $Version = '',
    [string] $OutputDirectory = '',
    [switch] $RequireSignature
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'find-signtool.ps1')

$root = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = (Get-Content -LiteralPath (Join-Path $root 'VERSION') -Raw).Trim()
}
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = if ($RequireSignature) {
        Join-Path $root 'dist'
    } else {
        Join-Path $root 'build\unsigned'
    }
}

$listenerPath = Join-Path $root 'build\windows-amd64\lemon-print-listener.exe'
$rendererPath = Join-Path $root 'build\windows-amd64\SumatraPDF.exe'
$rendererLicensePath = Join-Path $root 'build\windows-amd64\SumatraPDF-COPYING.txt'
$installerPath = Join-Path $OutputDirectory 'SempreERP-PrintListener-Setup.exe'
$manifestPath = Join-Path $OutputDirectory 'RELEASE-MANIFEST.json'
$checksumPath = Join-Path $OutputDirectory 'SHA256SUMS.txt'

foreach ($path in @($listenerPath, $rendererPath, $rendererLicensePath, $installerPath, $manifestPath, $checksumPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Brak oczekiwanego artefaktu: $path"
    }
}

$expectedRendererHash = '719f689b34f47be8ca105ce8484948474dafde0e106bab599e4a89326070c3d0'
if ((Get-FileHash -LiteralPath $rendererPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedRendererHash) {
    throw 'Renderer PDF ma nieoczekiwany SHA-256.'
}
if ((Get-AuthenticodeSignature -FilePath $rendererPath).Status -ne 'Valid') {
    throw 'Oficjalny renderer PDF nie ma prawidłowego podpisu Authenticode.'
}
& $listenerPath -validate-renderer
if ($LASTEXITCODE -ne 0) {
    throw 'Listener odrzucił dołączony renderer PDF.'
}

$versionOutput = (& $listenerPath --version).Trim()
if ($LASTEXITCODE -ne 0 -or $versionOutput -notmatch [regex]::Escape($Version)) {
    throw "Plik EXE nie raportuje oczekiwanej wersji ${Version}: $versionOutput"
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ($manifest.version -ne $Version) {
    throw "Manifest ma wersję '$($manifest.version)', oczekiwano '$Version'."
}
if ([bool] $manifest.signed -ne [bool] $RequireSignature) {
    throw "Pole signed w manifeście nie odpowiada trybowi weryfikacji."
}
if (@($manifest.artifacts).Count -ne 2 -or
    @($manifest.artifacts.name | Select-Object -Unique).Count -ne 2) {
    throw 'Manifest musi zawierać dokładnie dwa unikalne artefakty.'
}

foreach ($artifact in $manifest.artifacts) {
    $artifactPath = switch ($artifact.name) {
        'lemon-print-listener.exe' { $listenerPath }
        'SempreERP-PrintListener-Setup.exe' { $installerPath }
        default { throw "Manifest zawiera nieoczekiwany artefakt '$($artifact.name)'." }
    }
    if ((Get-Item -LiteralPath $artifactPath).Length -ne [long] $artifact.size) {
        throw "Niezgodny rozmiar dla $($artifact.name)."
    }
    $actual = (Get-FileHash -LiteralPath $artifactPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $artifact.sha256) {
        throw "Niezgodny SHA-256 dla $($artifact.name)."
    }
    if (-not (Select-String -LiteralPath $checksumPath -SimpleMatch "$actual *$($artifact.name)" -Quiet)) {
        throw "SHA256SUMS.txt nie zawiera $($artifact.name)."
    }
}

$installerVersion = (Get-Item -LiteralPath $installerPath).VersionInfo.ProductVersion
if ($installerVersion -notlike "$Version*") {
    throw "Instalator ma ProductVersion '$installerVersion', oczekiwano '$Version'."
}

if ($RequireSignature) {
    $expectedSubject = [string] $env:WINDOWS_CODESIGN_SUBJECT
    if ([string]::IsNullOrWhiteSpace($expectedSubject)) {
        throw 'WINDOWS_CODESIGN_SUBJECT jest wymagany przy weryfikacji podpisanego wydania.'
    }
    $signTool = Find-SignTool

    foreach ($artifactPath in @($listenerPath, $installerPath)) {
        $signature = Get-AuthenticodeSignature -FilePath $artifactPath
        if ($signature.Status -ne 'Valid') {
            throw "Podpis Authenticode $artifactPath ma status $($signature.Status): $($signature.StatusMessage)"
        }
        if (-not [string]::Equals(
            $signature.SignerCertificate.Subject,
            $expectedSubject,
            [StringComparison]::OrdinalIgnoreCase
        )) {
            throw "Podpis $artifactPath ma nieoczekiwany podmiot '$($signature.SignerCertificate.Subject)'."
        }

        & $signTool verify /pa /all /tw /v $artifactPath
        if ($LASTEXITCODE -ne 0) {
            throw "signtool verify nie powiódł się dla $artifactPath."
        }
    }
}

Write-Host "Artefakty wersji $Version zweryfikowane. Podpis wymagany: $([bool] $RequireSignature)."
