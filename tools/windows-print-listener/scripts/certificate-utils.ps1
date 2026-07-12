Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:CodeSigningEkuOid = '1.3.6.1.5.5.7.3.3'

function Normalize-CertificateSha256 {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Value,
        [string] $Name = 'SHA-256 certyfikatu'
    )

    $candidate = $Value.Trim()
    if ($candidate -notmatch '^[0-9A-Fa-f:\s-]+$') {
        throw "$Name może zawierać wyłącznie cyfry szesnastkowe i typowe separatory."
    }

    $normalized = ($candidate -replace '[:\s-]', '').ToLowerInvariant()
    if ($normalized -notmatch '^[0-9a-f]{64}$') {
        throw "$Name musi być pełnym SHA-256 zapisanym szesnastkowo."
    }

    return $normalized
}

function Get-CertificateSha256 {
    param(
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $Certificate
    )

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        $hash = $sha256.ComputeHash($Certificate.RawData)
        return ([BitConverter]::ToString($hash) -replace '-', '').ToLowerInvariant()
    } finally {
        $sha256.Dispose()
    }
}

function Read-DerCertificate {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path,
        [string] $Name = 'Certyfikat'
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "$Name DER nie istnieje: $Path"
    }

    try {
        return [System.Security.Cryptography.X509Certificates.X509Certificate2]::new(
            [System.IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $Path).Path)
        )
    } catch {
        throw "$Name nie jest prawidłowym certyfikatem DER: $($_.Exception.Message)"
    }
}

function Assert-CertificateValidity {
    param(
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $Certificate,
        [Parameter(Mandatory = $true)]
        [string] $Name
    )

    $now = Get-Date
    if ($Certificate.NotBefore -gt $now -or $Certificate.NotAfter -le $now) {
        throw "$Name nie jest obecnie ważny (od $($Certificate.NotBefore.ToString('o')) do $($Certificate.NotAfter.ToString('o')))."
    }
}

function Get-BasicConstraintsExtension {
    param([System.Security.Cryptography.X509Certificates.X509Certificate2] $Certificate)

    return $Certificate.Extensions |
        Where-Object { $_ -is [System.Security.Cryptography.X509Certificates.X509BasicConstraintsExtension] } |
        Select-Object -First 1
}

function Get-KeyUsageExtension {
    param([System.Security.Cryptography.X509Certificates.X509Certificate2] $Certificate)

    return $Certificate.Extensions |
        Where-Object { $_ -is [System.Security.Cryptography.X509Certificates.X509KeyUsageExtension] } |
        Select-Object -First 1
}

function Assert-RsaCertificateStrength {
    param(
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $Certificate,
        [Parameter(Mandatory = $true)]
        [string] $Name
    )

    $rsa = [System.Security.Cryptography.X509Certificates.RSACertificateExtensions]::GetRSAPublicKey($Certificate)
    if ($null -eq $rsa) {
        throw "$Name musi używać klucza RSA."
    }

    try {
        if ($rsa.KeySize -lt 3072) {
            throw "$Name używa klucza RSA $($rsa.KeySize)-bit; wymagane jest co najmniej RSA-3072."
        }
    } finally {
        $rsa.Dispose()
    }
}

function Assert-RootCertificate {
    param(
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $Certificate,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedSha256
    )

    $expected = Normalize-CertificateSha256 $ExpectedSha256 'WINDOWS_CODESIGN_ROOT_SHA256'
    $actual = Get-CertificateSha256 $Certificate
    if (-not [string]::Equals($actual, $expected, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Certyfikat root ma SHA-256 $actual zamiast przypiętego $expected."
    }

    Assert-CertificateValidity $Certificate 'Certyfikat root'
    Assert-RsaCertificateStrength $Certificate 'Certyfikat root'

    $constraints = Get-BasicConstraintsExtension $Certificate
    if ($null -eq $constraints -or -not $constraints.CertificateAuthority) {
        throw 'Certyfikat root musi mieć Basic Constraints CA=true.'
    }

    $keyUsage = Get-KeyUsageExtension $Certificate
    $keyCertSign = [System.Security.Cryptography.X509Certificates.X509KeyUsageFlags]::KeyCertSign
    if ($null -eq $keyUsage -or ($keyUsage.KeyUsages -band $keyCertSign) -eq 0) {
        throw 'Certyfikat root musi mieć Key Usage Certificate Signing.'
    }

    if (-not [string]::Equals($Certificate.Subject, $Certificate.Issuer, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Wewnętrzny certyfikat root musi być samopodpisanym korzeniem (Subject musi równać się Issuer).'
    }
}

function Assert-CodeSigningLeafCertificate {
    param(
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $Certificate,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedSubject,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedSha256
    )

    if ([string]::IsNullOrWhiteSpace($ExpectedSubject)) {
        throw 'WINDOWS_CODESIGN_SUBJECT nie może być pusty.'
    }

    $expected = Normalize-CertificateSha256 $ExpectedSha256 'WINDOWS_CODESIGN_LEAF_SHA256'
    $actual = Get-CertificateSha256 $Certificate
    if (-not [string]::Equals($actual, $expected, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Certyfikat wydawcy ma SHA-256 $actual zamiast przypiętego $expected."
    }
    if (-not [string]::Equals($Certificate.Subject, $ExpectedSubject, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Podmiot certyfikatu '$($Certificate.Subject)' nie jest dokładnie oczekiwanym '$ExpectedSubject'."
    }

    Assert-CertificateValidity $Certificate 'Certyfikat wydawcy'
    Assert-RsaCertificateStrength $Certificate 'Certyfikat wydawcy'

    $constraints = Get-BasicConstraintsExtension $Certificate
    if ($null -eq $constraints -or $constraints.CertificateAuthority) {
        throw 'Certyfikat wydawcy musi mieć Basic Constraints CA=false.'
    }

    $keyUsage = Get-KeyUsageExtension $Certificate
    $digitalSignature = [System.Security.Cryptography.X509Certificates.X509KeyUsageFlags]::DigitalSignature
    if ($null -eq $keyUsage -or ($keyUsage.KeyUsages -band $digitalSignature) -eq 0) {
        throw 'Certyfikat wydawcy musi mieć Key Usage Digital Signature.'
    }

    $hasCodeSigning = $Certificate.Extensions |
        Where-Object { $_ -is [System.Security.Cryptography.X509Certificates.X509EnhancedKeyUsageExtension] } |
        ForEach-Object { $_.EnhancedKeyUsages } |
        Where-Object { $_.Value -eq $script:CodeSigningEkuOid } |
        Select-Object -First 1
    if ($null -eq $hasCodeSigning) {
        throw 'Certyfikat wydawcy musi mieć EKU Code Signing.'
    }
}

function Assert-InternalCertificateChain {
    param(
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $LeafCertificate,
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $RootCertificate
    )

    $chain = [System.Security.Cryptography.X509Certificates.X509Chain]::new()
    try {
        $chain.ChainPolicy.TrustMode = [System.Security.Cryptography.X509Certificates.X509ChainTrustMode]::CustomRootTrust
        [void] $chain.ChainPolicy.CustomTrustStore.Add($RootCertificate)
        [void] $chain.ChainPolicy.ExtraStore.Add($RootCertificate)
        $chain.ChainPolicy.RevocationMode = [System.Security.Cryptography.X509Certificates.X509RevocationMode]::NoCheck
        $chain.ChainPolicy.VerificationFlags = [System.Security.Cryptography.X509Certificates.X509VerificationFlags]::NoFlag

        if (-not $chain.Build($LeafCertificate)) {
            $statuses = ($chain.ChainStatus | ForEach-Object { $_.Status.ToString() }) -join ', '
            throw "Certyfikat wydawcy nie buduje łańcucha do przypiętego root: $statuses"
        }

        $last = $chain.ChainElements[$chain.ChainElements.Count - 1].Certificate
        if (-not [string]::Equals(
            (Get-CertificateSha256 $last),
            (Get-CertificateSha256 $RootCertificate),
            [StringComparison]::OrdinalIgnoreCase
        )) {
            throw 'Łańcuch certyfikatu wydawcy kończy się innym rootem niż przypięty.'
        }
    } finally {
        $chain.Dispose()
    }
}

function Assert-InternalCertificateBundle {
    param(
        [Parameter(Mandatory = $true)]
        [string] $RootCertificatePath,
        [Parameter(Mandatory = $true)]
        [string] $LeafCertificatePath,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedRootSha256,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedLeafSha256,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedSubject
    )

    $root = Read-DerCertificate $RootCertificatePath 'Certyfikat root'
    $leaf = Read-DerCertificate $LeafCertificatePath 'Certyfikat wydawcy'
    try {
        Assert-RootCertificate $root $ExpectedRootSha256
        Assert-CodeSigningLeafCertificate $leaf $ExpectedSubject $ExpectedLeafSha256
        Assert-InternalCertificateChain $leaf $root

        return [pscustomobject]@{
            Root = $root
            Leaf = $leaf
        }
    } catch {
        $root.Dispose()
        $leaf.Dispose()
        throw
    }
}

function Invoke-CertUtilCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string[]] $Arguments,
        [ValidateRange(5, 120)]
        [int] $TimeoutSeconds = 30
    )

    $startInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = 'certutil.exe'
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    foreach ($argument in $Arguments) {
        [void] $startInfo.ArgumentList.Add($argument)
    }

    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    try {
        if (-not $process.Start()) {
            throw 'Nie udało się uruchomić certutil.exe.'
        }
        $standardOutput = $process.StandardOutput.ReadToEndAsync()
        $standardError = $process.StandardError.ReadToEndAsync()

        if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
            try {
                $process.Kill($true)
                $process.WaitForExit()
            } catch {
                # Preserve the timeout as the actionable failure.
            }
            throw "certutil.exe przekroczył limit $TimeoutSeconds sekund."
        }

        $output = $standardOutput.GetAwaiter().GetResult()
        $errorOutput = $standardError.GetAwaiter().GetResult()
        if (-not [string]::IsNullOrWhiteSpace($output)) {
            Write-Host ($output.TrimEnd())
        }
        if (-not [string]::IsNullOrWhiteSpace($errorOutput)) {
            Write-Host ($errorOutput.TrimEnd())
        }
        if ($process.ExitCode -ne 0) {
            throw "certutil.exe zakończył się kodem $($process.ExitCode)."
        }
    } finally {
        $process.Dispose()
    }
}

function Assert-MachineStoreCertificate {
    param(
        [Parameter(Mandatory = $true)]
        [string] $StoreName,
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $Certificate
    )

    $path = "Cert:\LocalMachine\$StoreName\$($Certificate.Thumbprint)"
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Brak oczekiwanego certyfikatu po imporcie do LocalMachine\\$StoreName."
    }
    $stored = Get-Item -LiteralPath $path
    if (-not [string]::Equals(
        (Get-CertificateSha256 $stored),
        (Get-CertificateSha256 $Certificate),
        [StringComparison]::OrdinalIgnoreCase
    )) {
        throw "Magazyn LocalMachine\\$StoreName zawiera inny certyfikat pod oczekiwanym thumbprintem."
    }
}

function Remove-ExactMachineStoreCertificate {
    param(
        [Parameter(Mandatory = $true)]
        [string] $StoreName,
        [Parameter(Mandatory = $true)]
        [string] $Thumbprint,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedSha256
    )

    $store = [System.Security.Cryptography.X509Certificates.X509Store]::new(
        $StoreName,
        [System.Security.Cryptography.X509Certificates.StoreLocation]::LocalMachine
    )
    try {
        $store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
        $matches = @(
            $store.Certificates.Find(
                [System.Security.Cryptography.X509Certificates.X509FindType]::FindByThumbprint,
                $Thumbprint,
                $false
            )
        )
        foreach ($match in $matches) {
            if (-not [string]::Equals(
                (Get-CertificateSha256 $match),
                $ExpectedSha256,
                [StringComparison]::OrdinalIgnoreCase
            )) {
                throw "Odmowa usunięcia nieoczekiwanego certyfikatu z LocalMachine\\$StoreName."
            }
            $store.Remove($match)
        }
    } finally {
        $store.Close()
        $store.Dispose()
    }

    if (Test-Path -LiteralPath "Cert:\LocalMachine\$StoreName\$Thumbprint") {
        throw "Certyfikat pozostał w LocalMachine\\$StoreName po cleanupie."
    }
}

function Add-TemporaryInternalCertificateTrust {
    param(
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $RootCertificate,
        [Parameter(Mandatory = $true)]
        [System.Security.Cryptography.X509Certificates.X509Certificate2] $LeafCertificate,
        [Parameter(Mandatory = $true)]
        [string] $RootCertificatePath,
        [Parameter(Mandatory = $true)]
        [string] $LeafCertificatePath
    )

    # Adding a private root to CurrentUser\Root can display the Windows
    # "Security Warning" confirmation dialog. That dialog blocks forever on a
    # headless GitHub runner. Release and smoke-test jobs already require an
    # elevated process, so use certutil against the machine stores explicitly;
    # certutil is non-interactive and its exit code can be enforced.
    $rootStorePath = "Cert:\LocalMachine\Root\$($RootCertificate.Thumbprint)"
    $publisherStorePath = "Cert:\LocalMachine\TrustedPublisher\$($LeafCertificate.Thumbprint)"
    $rootAdded = -not (Test-Path -LiteralPath $rootStorePath)
    $publisherAdded = -not (Test-Path -LiteralPath $publisherStorePath)

    # A pre-existing entry is never overwritten blindly. SHA-1 thumbprints are
    # only store locators here; the DER SHA-256 remains the trust decision.
    if (-not $rootAdded) {
        Assert-MachineStoreCertificate -StoreName Root -Certificate $RootCertificate
    }
    if (-not $publisherAdded) {
        Assert-MachineStoreCertificate -StoreName TrustedPublisher -Certificate $LeafCertificate
    }

    try {
        if ($rootAdded) {
            Invoke-CertUtilCommand -Arguments @('-f', '-addstore', 'Root', $RootCertificatePath)
            Assert-MachineStoreCertificate -StoreName Root -Certificate $RootCertificate
        }
        if ($publisherAdded) {
            Invoke-CertUtilCommand -Arguments @('-f', '-addstore', 'TrustedPublisher', $LeafCertificatePath)
            Assert-MachineStoreCertificate -StoreName TrustedPublisher -Certificate $LeafCertificate
        }
    } catch {
        $originalError = $_
        try {
            if ($publisherAdded) {
                Remove-ExactMachineStoreCertificate `
                    -StoreName TrustedPublisher `
                    -Thumbprint $LeafCertificate.Thumbprint `
                    -ExpectedSha256 (Get-CertificateSha256 $LeafCertificate)
            }
            if ($rootAdded) {
                Remove-ExactMachineStoreCertificate `
                    -StoreName Root `
                    -Thumbprint $RootCertificate.Thumbprint `
                    -ExpectedSha256 (Get-CertificateSha256 $RootCertificate)
            }
        } catch {
            Write-Warning "Cleanup po nieudanym imporcie zaufania także się nie powiódł: $($_.Exception.Message)"
        }
        throw $originalError
    }

    return [pscustomobject]@{
        RootStorePath = $rootStorePath
        PublisherStorePath = $publisherStorePath
        RootThumbprint = $RootCertificate.Thumbprint
        PublisherThumbprint = $LeafCertificate.Thumbprint
        RootSha256 = Get-CertificateSha256 $RootCertificate
        PublisherSha256 = Get-CertificateSha256 $LeafCertificate
        RootAdded = $rootAdded
        PublisherAdded = $publisherAdded
    }
}

function Remove-TemporaryInternalCertificateTrust {
    param([AllowNull()] [object] $State)

    if ($null -eq $State) {
        return
    }
    $cleanupErrors = @()
    if ($State.PublisherAdded) {
        try {
            Remove-ExactMachineStoreCertificate `
                -StoreName TrustedPublisher `
                -Thumbprint $State.PublisherThumbprint `
                -ExpectedSha256 $State.PublisherSha256
        } catch {
            $cleanupErrors += "wydawcy z LocalMachine\TrustedPublisher: $($_.Exception.Message)"
        }
    }
    if ($State.RootAdded) {
        try {
            Remove-ExactMachineStoreCertificate `
                -StoreName Root `
                -Thumbprint $State.RootThumbprint `
                -ExpectedSha256 $State.RootSha256
        } catch {
            $cleanupErrors += "root z LocalMachine\Root: $($_.Exception.Message)"
        }
    }
    if ($cleanupErrors.Count -gt 0) {
        # The hosted runner is destroyed after the job. Do not let a cleanup
        # problem mask a more useful signing/build error or skip later cleanup.
        Write-Warning "Nie udało się usunąć tymczasowych certyfikatów: $($cleanupErrors -join ', ')."
    }
}

function Assert-AuthenticodeSigner {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedSubject,
        [Parameter(Mandatory = $true)]
        [string] $ExpectedLeafSha256
    )

    $signature = Get-AuthenticodeSignature -FilePath $Path
    if ($signature.Status -ne 'Valid') {
        throw "Podpis Authenticode $Path ma status $($signature.Status): $($signature.StatusMessage)"
    }
    if ($null -eq $signature.SignerCertificate) {
        throw "Podpis Authenticode $Path nie zawiera certyfikatu wydawcy."
    }

    Assert-CodeSigningLeafCertificate $signature.SignerCertificate $ExpectedSubject $ExpectedLeafSha256
    return $signature
}
