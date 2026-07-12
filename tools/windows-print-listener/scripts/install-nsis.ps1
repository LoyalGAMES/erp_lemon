param(
    [string] $DestinationDirectory = '',
    [string] $Version = '3.12',
    [string] $ExpectedSha256 = '56581f90db321581c5381193d796fffcf2d24b2f8fed2160a6c6a3baa67f2c4f'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($Version -ne '3.12') {
    throw 'Zmiana wersji NSIS wymaga osobnej aktualizacji i weryfikacji SHA-256.'
}
if ($ExpectedSha256 -notmatch '^[0-9a-fA-F]{64}$') {
    throw 'ExpectedSha256 musi być pełnym SHA-256 zapisanym szesnastkowo.'
}
if ([string]::IsNullOrWhiteSpace($DestinationDirectory)) {
    $temporaryRoot = if ([string]::IsNullOrWhiteSpace([string] $env:RUNNER_TEMP)) {
        [System.IO.Path]::GetTempPath()
    } else {
        $env:RUNNER_TEMP
    }
    $DestinationDirectory = Join-Path $temporaryRoot "nsis-$Version"
}

$DestinationDirectory = [System.IO.Path]::GetFullPath($DestinationDirectory)
$archivePath = "$DestinationDirectory.zip"
$downloadUrl = "https://downloads.sourceforge.net/project/nsis/NSIS%203/$Version/nsis-$Version.zip"

Remove-Item -LiteralPath $DestinationDirectory, $archivePath -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $DestinationDirectory) | Out-Null

try {
    Invoke-WebRequest -UseBasicParsing -Uri $downloadUrl -OutFile $archivePath
    $actualHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne $ExpectedSha256.ToLowerInvariant()) {
        throw "Archiwum NSIS ma SHA-256 $actualHash zamiast $ExpectedSha256. Nie zostanie uruchomione."
    }

    Expand-Archive -LiteralPath $archivePath -DestinationPath $DestinationDirectory -Force
    $makeNsis = Get-ChildItem -LiteralPath $DestinationDirectory -Filter makensis.exe -Recurse -File |
        Select-Object -First 1
    if ($null -eq $makeNsis) {
        throw 'Zweryfikowane archiwum NSIS nie zawiera makensis.exe.'
    }

    & $makeNsis.FullName /VERSION
    if ($LASTEXITCODE -ne 0) {
        throw 'makensis.exe ze zweryfikowanego archiwum nie uruchomił się poprawnie.'
    }

    $nsisDirectory = Split-Path -Parent $makeNsis.FullName
    if (-not [string]::IsNullOrWhiteSpace([string] $env:GITHUB_PATH)) {
        Add-Content -LiteralPath $env:GITHUB_PATH -Value $nsisDirectory -Encoding UTF8
    }
    Write-Host "Zweryfikowany NSIS $Version: $nsisDirectory"
    return $nsisDirectory
} finally {
    Remove-Item -LiteralPath $archivePath -Force -ErrorAction SilentlyContinue
}
