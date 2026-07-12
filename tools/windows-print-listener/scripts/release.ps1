param(
    [string] $Version = '',
    [string] $OutputDirectory = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'certificate-utils.ps1')
. (Join-Path $PSScriptRoot 'find-signtool.ps1')

$root = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = (Get-Content -LiteralPath (Join-Path $root 'VERSION') -Raw).Trim()
}
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $root 'dist'
}

$profile = ([string] $env:WINDOWS_CODESIGN_PROFILE).Trim().ToLowerInvariant()
$pfxPath = [string] $env:WINDOWS_CODESIGN_PFX_PATH
$pfxPassword = [string] $env:WINDOWS_CODESIGN_PFX_PASSWORD
$expectedSubject = [string] $env:WINDOWS_CODESIGN_SUBJECT
$expectedLeafSha256 = Normalize-CertificateSha256 `
    ([string] $env:WINDOWS_CODESIGN_LEAF_SHA256) `
    'WINDOWS_CODESIGN_LEAF_SHA256'

if ($profile -notin @('internal', 'public')) {
    throw 'WINDOWS_CODESIGN_PROFILE musi mieć wartość internal albo public.'
}
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

$rootCertificatePath = [string] $env:WINDOWS_CODESIGN_ROOT_CERT_PATH
$leafCertificatePath = [string] $env:WINDOWS_CODESIGN_LEAF_CERT_PATH
$expectedRootSha256 = ''
$certificateBundle = $null
$trustState = $null

if ($profile -eq 'internal') {
    Write-Host 'Waliduję wewnętrzny łańcuch certyfikatów...'
    $expectedRootSha256 = Normalize-CertificateSha256 `
        ([string] $env:WINDOWS_CODESIGN_ROOT_SHA256) `
        'WINDOWS_CODESIGN_ROOT_SHA256'
    $certificateBundle = Assert-InternalCertificateBundle `
        -RootCertificatePath $rootCertificatePath `
        -LeafCertificatePath $leafCertificatePath `
        -ExpectedRootSha256 $expectedRootSha256 `
        -ExpectedLeafSha256 $expectedLeafSha256 `
        -ExpectedSubject $expectedSubject
}

$previousEnvironment = @{
    WINDOWS_CODESIGN_THUMBPRINT = $env:WINDOWS_CODESIGN_THUMBPRINT
    WINDOWS_RELEASE_CHANNEL = $env:WINDOWS_RELEASE_CHANNEL
    WINDOWS_CODESIGN_PUBLISHER_SUBJECT = $env:WINDOWS_CODESIGN_PUBLISHER_SUBJECT
    WINDOWS_CODESIGN_PUBLISHER_SHA256 = $env:WINDOWS_CODESIGN_PUBLISHER_SHA256
    WINDOWS_CODESIGN_MANIFEST_ROOT_SHA256 = $env:WINDOWS_CODESIGN_MANIFEST_ROOT_SHA256
}
$securePassword = $null
$importedCertificates = @()
$newMyThumbprints = @()
$certificate = $null
$preexistingMyThumbprints = @(
    Get-ChildItem 'Cert:\CurrentUser\My' -ErrorAction SilentlyContinue |
        ForEach-Object { [string] $_.Thumbprint }
)

try {
    Write-Host 'Importuję roboczy certyfikat podpisujący jako nieeksportowalny...'
    $securePassword = ConvertTo-SecureString $pfxPassword -AsPlainText -Force
    $importedCertificates = @(
        Import-PfxCertificate `
            -FilePath $pfxPath `
            -CertStoreLocation 'Cert:\CurrentUser\My' `
            -Password $securePassword `
            -Exportable:$false
    )
    # Import-PfxCertificate may also place public chain certificates from the
    # PFX in CurrentUser\My while returning only the leaf with a private key.
    # Capture the exact isolated-runner store delta immediately so every entry
    # introduced by this import is removed in finally.
    $newMyThumbprints = @(
        Get-ChildItem 'Cert:\CurrentUser\My' -ErrorAction SilentlyContinue |
            ForEach-Object { [string] $_.Thumbprint } |
            Where-Object { $_ -notin $preexistingMyThumbprints }
    )

    # The PFX password is needed only for the import above. Remove it from this
    # process before NSIS and SignTool child processes are started.
    $pfxPassword = $null
    $env:WINDOWS_CODESIGN_PFX_PASSWORD = $null

    $candidates = @(
        $importedCertificates |
            Where-Object {
                $_.HasPrivateKey -and
                [string]::Equals(
                    (Get-CertificateSha256 $_),
                    $expectedLeafSha256,
                    [StringComparison]::OrdinalIgnoreCase
                )
            }
    )
    if ($candidates.Count -ne 1) {
        throw 'PFX musi zawierać dokładnie jeden klucz prywatny przypiętego certyfikatu wydawcy.'
    }
    $certificate = $candidates[0]
    Assert-CodeSigningLeafCertificate $certificate $expectedSubject $expectedLeafSha256

    if ($profile -eq 'internal') {
        if (-not [string]::Equals(
            (Get-CertificateSha256 $certificateBundle.Leaf),
            (Get-CertificateSha256 $certificate),
            [StringComparison]::OrdinalIgnoreCase
        )) {
            throw 'Publiczny certyfikat wydawcy DER nie odpowiada kluczowi prywatnemu z PFX.'
        }

        Write-Host 'Dodaję tymczasowe zaufanie w magazynach maszyny runnera...'
        $trustState = Add-TemporaryInternalCertificateTrust `
            -RootCertificate $certificateBundle.Root `
            -LeafCertificate $certificateBundle.Leaf `
            -RootCertificatePath $rootCertificatePath `
            -LeafCertificatePath $leafCertificatePath
    }

    $env:WINDOWS_CODESIGN_THUMBPRINT = $certificate.Thumbprint
    $env:WINDOWS_RELEASE_CHANNEL = $profile
    $env:WINDOWS_CODESIGN_PUBLISHER_SUBJECT = $certificate.Subject
    $env:WINDOWS_CODESIGN_PUBLISHER_SHA256 = $expectedLeafSha256
    $env:WINDOWS_CODESIGN_MANIFEST_ROOT_SHA256 = if ($profile -eq 'internal') { $expectedRootSha256 } else { $null }

    # Discover the SDK once. NSIS starts separate PowerShell processes for the
    # uninstaller and installer signatures; inheriting this PATH prevents each
    # process from recursively scanning the entire Windows SDK tree.
    $signToolDirectory = Split-Path -Parent (Find-SignTool)
    $env:PATH = "$signToolDirectory;$($env:PATH)"

    Write-Host 'Buduję i podpisuję listener, deinstalator oraz instalator...'
    & (Join-Path $PSScriptRoot 'build.ps1') -Version $Version -OutputDirectory $OutputDirectory -Sign
    Write-Host 'Weryfikuję podpisy, timestampy, manifest i sumy...'
    & (Join-Path $PSScriptRoot 'verify-artifacts.ps1') -Version $Version -OutputDirectory $OutputDirectory -RequireSignature
} finally {
    $pfxPassword = $null
    $env:WINDOWS_CODESIGN_PFX_PASSWORD = $null
    Remove-TemporaryInternalCertificateTrust $trustState

    foreach ($thumbprint in $newMyThumbprints) {
        if (-not [string]::IsNullOrWhiteSpace($thumbprint)) {
            $storePath = "Cert:\CurrentUser\My\$thumbprint"
            Remove-Item -LiteralPath $storePath -Force -ErrorAction SilentlyContinue
            if (Test-Path -LiteralPath $storePath) {
                Write-Warning "Certyfikat $thumbprint pozostał w CurrentUser\\My po cleanupie."
            }
        }
    }

    foreach ($name in $previousEnvironment.Keys) {
        Set-Item -Path "Env:$name" -Value $previousEnvironment[$name] -ErrorAction SilentlyContinue
        if ($null -eq $previousEnvironment[$name]) {
            Remove-Item -Path "Env:$name" -ErrorAction SilentlyContinue
        }
    }

    if ($null -ne $certificateBundle) {
        $certificateBundle.Root.Dispose()
        $certificateBundle.Leaf.Dispose()
    }
    $certificate = $null
    $importedCertificates = @()
    $newMyThumbprints = @()
    $securePassword = $null
}
