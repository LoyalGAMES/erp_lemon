param(
    [string] $Version = '',
    [string] $OutputDirectory = '',
    [switch] $RequireSignature
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'find-signtool.ps1')
. (Join-Path $PSScriptRoot 'certificate-utils.ps1')

function Test-ManifestProperty {
    param([object] $Manifest, [string] $Name)

    return $null -ne $Manifest.PSObject.Properties[$Name]
}

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
$internalRootPath = Join-Path $OutputDirectory 'SempreERP-Internal-Root.cer'
$internalPublisherPath = Join-Path $OutputDirectory 'SempreERP-Internal-Publisher.cer'
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
if ([string] $manifest.commit -notmatch '^[0-9a-f]{40}$') {
    throw "Manifest nie zawiera pełnego 40-znakowego SHA commita."
}
if ([bool] $manifest.signed -ne [bool] $RequireSignature) {
    throw 'Pole signed w manifeście nie odpowiada trybowi weryfikacji.'
}

$expectedArtifactNames = @('lemon-print-listener.exe', 'SempreERP-PrintListener-Setup.exe')
$expectedSubject = $null
$expectedLeafSha256 = $null
$expectedRootSha256 = $null
$profile = [string] $manifest.signing_profile

if ($RequireSignature) {
    $expectedProfile = ([string] $env:WINDOWS_CODESIGN_PROFILE).Trim().ToLowerInvariant()
    if ($expectedProfile -notin @('internal', 'public') -or $profile -ne $expectedProfile) {
        throw "Manifest ma profil podpisu '$profile', oczekiwano '$expectedProfile'."
    }
    $expectedChannel = $profile
    if ($manifest.release_channel -ne $expectedChannel) {
        throw "Manifest ma kanał '$($manifest.release_channel)', oczekiwano '$expectedChannel'."
    }
    if ($manifest.timestamped -ne $true) {
        throw 'Podpisany manifest musi potwierdzać timestamp RFC 3161.'
    }

    $expectedSubject = ([string] $env:WINDOWS_CODESIGN_SUBJECT).Trim()
    $expectedLeafSha256 = Normalize-CertificateSha256 `
        ([string] $env:WINDOWS_CODESIGN_LEAF_SHA256) `
        'WINDOWS_CODESIGN_LEAF_SHA256'
    if ([string]::IsNullOrWhiteSpace($expectedSubject) -or
        $manifest.publisher_subject -ne $expectedSubject -or
        $manifest.publisher_certificate_sha256 -ne $expectedLeafSha256) {
        throw 'Manifest nie zawiera dokładnie przypiętej tożsamości wydawcy.'
    }

    if ($profile -eq 'internal') {
        $expectedRootSha256 = Normalize-CertificateSha256 `
            ([string] $env:WINDOWS_CODESIGN_ROOT_SHA256) `
            'WINDOWS_CODESIGN_ROOT_SHA256'
        if (-not (Test-ManifestProperty $manifest 'root_certificate_sha256') -or
            $manifest.root_certificate_sha256 -ne $expectedRootSha256) {
            throw 'Wewnętrzny manifest nie zawiera przypiętego SHA-256 certyfikatu root.'
        }
        $expectedArtifactNames += @('SempreERP-Internal-Root.cer', 'SempreERP-Internal-Publisher.cer')
    } elseif (Test-ManifestProperty $manifest 'root_certificate_sha256') {
        throw 'Publiczny manifest nie może zawierać wewnętrznego certyfikatu root.'
    }
} else {
    if ($profile -ne 'unsigned' -or
        $manifest.release_channel -ne 'validation' -or
        $manifest.timestamped -ne $false) {
        throw 'Niepodpisany manifest musi mieć kanał validation, profil unsigned i timestamped=false.'
    }
    foreach ($property in @('publisher_subject', 'publisher_certificate_sha256', 'root_certificate_sha256')) {
        if (Test-ManifestProperty $manifest $property) {
            throw "Niepodpisany manifest nie może zawierać pola $property."
        }
    }
}

$manifestArtifacts = @($manifest.artifacts)
$actualArtifactNames = @($manifestArtifacts | ForEach-Object { [string] $_.name })
if ($manifestArtifacts.Count -ne $expectedArtifactNames.Count -or
    @($actualArtifactNames | Select-Object -Unique).Count -ne $expectedArtifactNames.Count -or
    (Compare-Object ($actualArtifactNames | Sort-Object) ($expectedArtifactNames | Sort-Object))) {
    throw 'Manifest nie zawiera dokładnie oczekiwanego zestawu artefaktów.'
}

foreach ($artifact in $manifestArtifacts) {
    $artifactPath = switch ($artifact.name) {
        'lemon-print-listener.exe' { $listenerPath }
        'SempreERP-PrintListener-Setup.exe' { $installerPath }
        'SempreERP-Internal-Root.cer' { $internalRootPath }
        'SempreERP-Internal-Publisher.cer' { $internalPublisherPath }
        default { throw "Manifest zawiera nieoczekiwany artefakt '$($artifact.name)'." }
    }
    if (-not (Test-Path -LiteralPath $artifactPath -PathType Leaf)) {
        throw "Brak artefaktu wymienionego w manifeście: $artifactPath"
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

if ($RequireSignature -and $profile -eq 'internal') {
    $bundle = Assert-InternalCertificateBundle `
        -RootCertificatePath $internalRootPath `
        -LeafCertificatePath $internalPublisherPath `
        -ExpectedRootSha256 $expectedRootSha256 `
        -ExpectedLeafSha256 $expectedLeafSha256 `
        -ExpectedSubject $expectedSubject
    $bundle.Root.Dispose()
    $bundle.Leaf.Dispose()
}

$installerVersion = (Get-Item -LiteralPath $installerPath).VersionInfo.ProductVersion
if ($installerVersion -notlike "$Version*") {
    throw "Instalator ma ProductVersion '$installerVersion', oczekiwano '$Version'."
}

if ($RequireSignature) {
    $signTool = Find-SignTool

    foreach ($artifactPath in @($listenerPath, $installerPath)) {
        [void] (Assert-AuthenticodeSigner $artifactPath $expectedSubject $expectedLeafSha256)
        & $signTool verify /pa /all /tw /v $artifactPath
        if ($LASTEXITCODE -ne 0) {
            throw "signtool verify nie powiódł się dla $artifactPath."
        }
    }
}

Write-Host "Artefakty wersji $Version zweryfikowane. Podpis wymagany: $([bool] $RequireSignature), profil: $profile."
