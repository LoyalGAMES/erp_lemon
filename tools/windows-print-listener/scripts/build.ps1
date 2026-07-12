param(
    [string] $Version = '',
    [string] $OutputDirectory = '',
    [switch] $Sign
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

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
$readmePath = Join-Path $buildDirectory 'README.txt'

New-Item -ItemType Directory -Force -Path $buildDirectory, $OutputDirectory | Out-Null
Remove-Item -LiteralPath $listenerPath, $installerPath -Force -ErrorAction SilentlyContinue

$commit = 'unknown'
try {
    $commit = (& git -C $root rev-parse --short=12 HEAD 2>$null).Trim()
} catch {
    $commit = 'unknown'
}
if ([string]::IsNullOrWhiteSpace($commit)) {
    $commit = 'unknown'
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
        $nsisArguments += '/DSIGN_ARTIFACTS'
    }
    $nsisArguments += (Join-Path $root 'installer\sempre-erp-print-listener.nsi')

    & $makeNsis.Source @nsisArguments
    if ($LASTEXITCODE -ne 0) { throw 'Budowanie instalatora NSIS nie powiodło się.' }

    if (-not (Test-Path -LiteralPath $installerPath -PathType Leaf)) {
        throw "NSIS nie utworzył oczekiwanego instalatora: $installerPath"
    }

    $artifacts = @($listenerPath, $installerPath)
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
        artifacts = $artifactRows
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
