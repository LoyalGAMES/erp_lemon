param(
    [string] $Version = '',
    [string] $OutputDirectory = '',
    [switch] $Sign
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'certificate-utils.ps1')

$root = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = (Get-Content -LiteralPath (Join-Path $root 'VERSION') -Raw).Trim()
}
if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    throw "Wersja '$Version' nie ma formatu MAJOR.MINOR.PATCH wymaganego przez metadane Windows."
}

if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    # An unsigned validation build must never land in the directory served by
    # ERP. Signed releases are routed to dist by release.ps1.
    $OutputDirectory = Join-Path $root 'build\unsigned'
}
$OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory)
$buildDirectory = Join-Path $root 'build\windows-amd64'
$listenerPath = Join-Path $buildDirectory 'lemon-print-listener.exe'
$installerPath = Join-Path $OutputDirectory 'SempreERP-PrintListener-Setup.exe'
$internalRootPath = Join-Path $OutputDirectory 'SempreERP-Internal-Root.cer'
$internalPublisherPath = Join-Path $OutputDirectory 'SempreERP-Internal-Publisher.cer'
$readmePath = Join-Path $buildDirectory 'README.txt'

New-Item -ItemType Directory -Force -Path $buildDirectory, $OutputDirectory | Out-Null
$staleArtifacts = @(
    $listenerPath,
    $installerPath,
    $internalRootPath,
    $internalPublisherPath,
    (Join-Path $OutputDirectory 'RELEASE-MANIFEST.json'),
    (Join-Path $OutputDirectory 'SHA256SUMS.txt')
)
Remove-Item -LiteralPath $staleArtifacts -Force -ErrorAction SilentlyContinue

$signingProfile = 'unsigned'
$releaseChannel = 'validation'
$publisherSubject = $null
$publisherSha256 = $null
$rootSha256 = $null

if ($Sign) {
    $signingProfile = ([string] $env:WINDOWS_CODESIGN_PROFILE).Trim().ToLowerInvariant()
    if ($signingProfile -notin @('internal', 'public')) {
        throw 'Podpisany build wymaga WINDOWS_CODESIGN_PROFILE=internal albo public.'
    }
    $releaseChannel = ([string] $env:WINDOWS_RELEASE_CHANNEL).Trim().ToLowerInvariant()
    if ($releaseChannel -notin @('internal', 'public')) {
        throw 'Podpisany build wymaga WINDOWS_RELEASE_CHANNEL=internal albo public.'
    }
    if ($releaseChannel -ne $signingProfile) {
        throw 'Kanał wydania nie odpowiada profilowi podpisu.'
    }

    $publisherSubject = ([string] $env:WINDOWS_CODESIGN_PUBLISHER_SUBJECT).Trim()
    if ([string]::IsNullOrWhiteSpace($publisherSubject)) {
        throw 'Podpisany build wymaga podmiotu wydawcy.'
    }
    $publisherSha256 = Normalize-CertificateSha256 `
        ([string] $env:WINDOWS_CODESIGN_PUBLISHER_SHA256) `
        'WINDOWS_CODESIGN_PUBLISHER_SHA256'

    if ($signingProfile -eq 'internal') {
        $rootSha256 = Normalize-CertificateSha256 `
            ([string] $env:WINDOWS_CODESIGN_MANIFEST_ROOT_SHA256) `
            'WINDOWS_CODESIGN_MANIFEST_ROOT_SHA256'
        $rootSource = [string] $env:WINDOWS_CODESIGN_ROOT_CERT_PATH
        $publisherSource = [string] $env:WINDOWS_CODESIGN_LEAF_CERT_PATH
        foreach ($source in @($rootSource, $publisherSource)) {
            if ([string]::IsNullOrWhiteSpace($source) -or -not (Test-Path -LiteralPath $source -PathType Leaf)) {
                throw 'Wewnętrzny build wymaga publicznych certyfikatów root i wydawcy DER.'
            }
        }
        Copy-Item -LiteralPath $rootSource -Destination $internalRootPath -Force
        Copy-Item -LiteralPath $publisherSource -Destination $internalPublisherPath -Force
    }
}

$commit = 'unknown'
try {
    $commit = (& git -C $root rev-parse HEAD 2>$null).Trim().ToLowerInvariant()
} catch {
    $commit = 'unknown'
}
if ($commit -notmatch '^[0-9a-f]{40}$') {
    throw 'Nie udało się ustalić pełnego 40-znakowego SHA commita dla manifestu wydania.'
}

Push-Location $root
try {
    & go test ./...
    if ($LASTEXITCODE -ne 0) { throw 'go test nie powiódł się.' }

    & go vet ./...
    if ($LASTEXITCODE -ne 0) { throw 'go vet nie powiódł się.' }

    $previousGoos = $env:GOOS
    $previousGoarch = $env:GOARCH
    $previousCgo = $env:CGO_ENABLED
    try {
        $env:GOOS = 'windows'
        $env:GOARCH = 'amd64'
        $env:CGO_ENABLED = '0'
        $ldflags = "-s -w -X main.version=$Version -X main.commit=$commit"
        & go build -trimpath -buildvcs=false -ldflags $ldflags -o $listenerPath .
        if ($LASTEXITCODE -ne 0) { throw 'Budowanie pliku Windows EXE nie powiodło się.' }
    } finally {
        $env:GOOS = $previousGoos
        $env:GOARCH = $previousGoarch
        $env:CGO_ENABLED = $previousCgo
    }

    Copy-Item -LiteralPath (Join-Path $root 'README.md') -Destination $readmePath -Force

    & (Join-Path $PSScriptRoot 'prepare-sumatra.ps1') -DestinationPath (Join-Path $buildDirectory 'SumatraPDF.exe')

    if ($Sign) {
        & (Join-Path $PSScriptRoot 'sign-artifact.ps1') $listenerPath
    }

    $makeNsis = Get-Command makensis.exe -ErrorAction SilentlyContinue
    if ($null -eq $makeNsis) {
        $makeNsis = Get-Command makensis -ErrorAction SilentlyContinue
    }
    if ($null -eq $makeNsis) {
        throw 'Nie znaleziono makensis. Zainstaluj zweryfikowany NSIS 3.12 i dodaj go do PATH.'
    }

    $nsisArguments = @(
        '/WX',
        '/INPUTCHARSET', 'UTF8',
        "/DAPP_VERSION=$Version",
        "/DAPP_FILE_VERSION=$Version.0",
        "/DBUILD_DIR=$($buildDirectory.Replace('/', '\'))",
        "/DOUTPUT_DIR=$($OutputDirectory.Replace('/', '\'))"
    )
    if ($Sign) {
        $signingScript = (Join-Path $PSScriptRoot 'sign-artifact.ps1').Replace('/', '\')
        $nsisArguments += @(
            '/DSIGN_ARTIFACTS',
            "/DSIGN_SCRIPT=$signingScript"
        )
        if ($signingProfile -eq 'internal') {
            $installerRoot = Read-DerCertificate $internalRootPath 'Certyfikat root osadzany w instalatorze'
            $installerPublisher = Read-DerCertificate $internalPublisherPath 'Certyfikat wydawcy osadzany w instalatorze'
            try {
                if ((Get-CertificateSha256 $installerRoot) -ne $rootSha256 -or
                    (Get-CertificateSha256 $installerPublisher) -ne $publisherSha256) {
                    throw 'Certyfikaty osadzane w instalatorze nie odpowiadają przypiętym SHA-256.'
                }
                $nsisArguments += @(
                    '/DINTERNAL_TRUST_BOOTSTRAP',
                    "/DINTERNAL_ROOT_CERT=$($internalRootPath.Replace('/', '\'))",
                    "/DINTERNAL_PUBLISHER_CERT=$($internalPublisherPath.Replace('/', '\'))",
                    "/DINTERNAL_ROOT_THUMBPRINT=$($installerRoot.Thumbprint)",
                    "/DINTERNAL_PUBLISHER_THUMBPRINT=$($installerPublisher.Thumbprint)",
                    "/DINTERNAL_ROOT_SHA256=$rootSha256",
                    "/DINTERNAL_PUBLISHER_SHA256=$publisherSha256"
                )
            } finally {
                $installerRoot.Dispose()
                $installerPublisher.Dispose()
            }
        }
    }
    $nsisArguments += (Join-Path $root 'installer\sempre-erp-print-listener.nsi')

    & $makeNsis.Source @nsisArguments
    if ($LASTEXITCODE -ne 0) { throw 'Budowanie instalatora NSIS nie powiodło się.' }

    if (-not (Test-Path -LiteralPath $installerPath -PathType Leaf)) {
        throw "NSIS nie utworzył oczekiwanego instalatora: $installerPath"
    }

    $artifacts = @($listenerPath, $installerPath)
    if ($signingProfile -eq 'internal') {
        $artifacts += @($internalRootPath, $internalPublisherPath)
    }
    $artifactRows = foreach ($artifact in $artifacts) {
        $file = Get-Item -LiteralPath $artifact
        $hash = Get-FileHash -LiteralPath $artifact -Algorithm SHA256
        [ordered]@{
            name = $file.Name
            size = $file.Length
            sha256 = $hash.Hash.ToLowerInvariant()
        }
    }

    $manifest = [ordered]@{
        product = 'Sempre ERP Print Listener'
        version = $Version
        commit = $commit
        target = 'windows/amd64'
        go_version = (& go version).Trim()
        signed = [bool] $Sign
        release_channel = $releaseChannel
        signing_profile = $signingProfile
        timestamped = [bool] $Sign
        artifacts = $artifactRows
    }
    if ($Sign) {
        $manifest['publisher_subject'] = $publisherSubject
        $manifest['publisher_certificate_sha256'] = $publisherSha256
    }
    if ($signingProfile -eq 'internal') {
        $manifest['root_certificate_sha256'] = $rootSha256
        $manifest['trust_bootstrap'] = 'installer'
    }
    $manifestPath = Join-Path $OutputDirectory 'RELEASE-MANIFEST.json'
    $manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $manifestPath -Encoding UTF8

    $checksumPath = Join-Path $OutputDirectory 'SHA256SUMS.txt'
    $artifactRows |
        ForEach-Object { "$($_.sha256) *$($_.name)" } |
        Set-Content -LiteralPath $checksumPath -Encoding ASCII

    Write-Host "Gotowe: $installerPath"
} finally {
    Pop-Location
}
