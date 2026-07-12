param(
    [string] $Version = '',
    [string] $OutputDirectory = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = (Get-Content -LiteralPath (Join-Path $root 'VERSION') -Raw).Trim()
}
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $root 'dist'
}

$pfxPath = [string] $env:WINDOWS_CODESIGN_PFX_PATH
$pfxPassword = [string] $env:WINDOWS_CODESIGN_PFX_PASSWORD
$expectedSubject = [string] $env:WINDOWS_CODESIGN_SUBJECT

if ([string]::IsNullOrWhiteSpace($pfxPath) -or -not (Test-Path -LiteralPath $pfxPath -PathType Leaf)) {
    throw 'WINDOWS_CODESIGN_PFX_PATH musi wskazywać istniejący certyfikat Authenticode PFX.'
}
if ([string]::IsNullOrWhiteSpace($pfxPassword)) {
    throw 'WINDOWS_CODESIGN_PFX_PASSWORD nie jest ustawiony.'
}
if ([string]::IsNullOrWhiteSpace([string] $env:WINDOWS_CODESIGN_TIMESTAMP_URL)) {
    throw 'WINDOWS_CODESIGN_TIMESTAMP_URL nie jest ustawiony.'
}
if ([string]::IsNullOrWhiteSpace($expectedSubject)) {
    throw 'WINDOWS_CODESIGN_SUBJECT nie jest ustawiony; wydanie musi przypinać oczekiwanego wydawcę certyfikatu.'
}

$previousThumbprint = $env:WINDOWS_CODESIGN_THUMBPRINT
$securePassword = $null
$importedCertificates = @()
$certificate = $null

try {
    $securePassword = ConvertTo-SecureString $pfxPassword -AsPlainText -Force
    $importedCertificates = @(
        Import-PfxCertificate `
            -FilePath $pfxPath `
            -CertStoreLocation 'Cert:\CurrentUser\My' `
            -Password $securePassword `
            -Exportable:$false
    )

    $codeSigningOid = '1.3.6.1.5.5.7.3.3'
    $certificate = $importedCertificates |
        Where-Object {
            $_.HasPrivateKey -and
            ($_.Extensions |
                Where-Object { $_ -is [System.Security.Cryptography.X509Certificates.X509EnhancedKeyUsageExtension] } |
                ForEach-Object { $_.EnhancedKeyUsages } |
                Where-Object { $_.Value -eq $codeSigningOid })
        } |
        Select-Object -First 1

    if ($null -eq $certificate) {
        throw 'PFX nie zawiera certyfikatu z kluczem prywatnym i EKU Code Signing.'
    }
    if ($certificate.NotBefore -gt (Get-Date) -or $certificate.NotAfter -le (Get-Date)) {
        throw 'Certyfikat Authenticode nie jest obecnie ważny.'
    }
    if (-not [string]::Equals($certificate.Subject, $expectedSubject, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Podmiot certyfikatu '$($certificate.Subject)' nie jest dokładnie oczekiwanym '$expectedSubject'."
    }

    $env:WINDOWS_CODESIGN_THUMBPRINT = $certificate.Thumbprint
    & (Join-Path $PSScriptRoot 'build.ps1') -Version $Version -OutputDirectory $OutputDirectory -Sign
    & (Join-Path $PSScriptRoot 'verify-artifacts.ps1') -Version $Version -OutputDirectory $OutputDirectory -RequireSignature
} finally {
    $env:WINDOWS_CODESIGN_THUMBPRINT = $previousThumbprint
    foreach ($imported in $importedCertificates) {
        if (-not [string]::IsNullOrWhiteSpace([string] $imported.Thumbprint)) {
            Remove-Item -LiteralPath "Cert:\CurrentUser\My\$($imported.Thumbprint)" -Force -ErrorAction SilentlyContinue
        }
    }
    $certificate = $null
    $importedCertificates = @()
    $pfxPassword = $null
    $securePassword = $null
}
