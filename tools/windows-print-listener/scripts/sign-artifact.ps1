param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string] $Path
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'find-signtool.ps1')

$thumbprint = [string] $env:WINDOWS_CODESIGN_THUMBPRINT
$timestampUrl = [string] $env:WINDOWS_CODESIGN_TIMESTAMP_URL

if ([string]::IsNullOrWhiteSpace($thumbprint)) {
    throw 'WINDOWS_CODESIGN_THUMBPRINT nie jest ustawiony. Artefakt release nie może być niepodpisany.'
}
if ($thumbprint -notmatch '^[0-9A-Fa-f]{40}$') {
    throw 'WINDOWS_CODESIGN_THUMBPRINT nie jest prawidłowym thumbprintem SHA-1 certyfikatu.'
}
if ([string]::IsNullOrWhiteSpace($timestampUrl)) {
    throw 'WINDOWS_CODESIGN_TIMESTAMP_URL nie jest ustawiony. Podpis release wymaga RFC 3161 timestampu.'
}
$timestampUri = $null
if (-not [Uri]::TryCreate($timestampUrl, [UriKind]::Absolute, [ref] $timestampUri) -or
    $timestampUri.Scheme -notin @('http', 'https')) {
    throw 'WINDOWS_CODESIGN_TIMESTAMP_URL musi być bezwzględnym adresem HTTP(S) usługi RFC 3161.'
}

$absolutePath = (Resolve-Path -LiteralPath $Path).Path
$signTool = Find-SignTool
$arguments = @(
    'sign',
    '/sha1', $thumbprint,
    '/s', 'My',
    '/fd', 'SHA256',
    '/td', 'SHA256',
    '/tr', $timestampUrl,
    '/d', 'Sempre ERP Print Listener',
    $absolutePath
)

& $signTool @arguments
if ($LASTEXITCODE -ne 0) {
    throw "signtool sign zakończył się kodem $LASTEXITCODE dla $absolutePath"
}

& $signTool verify /pa /all /tw /v $absolutePath
if ($LASTEXITCODE -ne 0) {
    throw "Weryfikacja Authenticode zakończyła się kodem $LASTEXITCODE dla $absolutePath"
}
