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

    $rootStorePath = "Cert:\CurrentUser\Root\$($RootCertificate.Thumbprint)"
    $publisherStorePath = "Cert:\CurrentUser\TrustedPublisher\$($LeafCertificate.Thumbprint)"
    $rootAdded = -not (Test-Path -LiteralPath $rootStorePath)
    $publisherAdded = -not (Test-Path -LiteralPath $publisherStorePath)

    try {
        if ($rootAdded) {
            Import-Certificate -FilePath $RootCertificatePath -CertStoreLocation 'Cert:\CurrentUser\Root' | Out-Null
        }
        if ($publisherAdded) {
            Import-Certificate -FilePath $LeafCertificatePath -CertStoreLocation 'Cert:\CurrentUser\TrustedPublisher' | Out-Null
        }
    } catch {
        if ($publisherAdded) {
            Remove-Item -LiteralPath $publisherStorePath -Force -ErrorAction SilentlyContinue
        }
        if ($rootAdded) {
            Remove-Item -LiteralPath $rootStorePath -Force -ErrorAction SilentlyContinue
        }
        throw
    }

    return [pscustomobject]@{
        RootStorePath = $rootStorePath
        PublisherStorePath = $publisherStorePath
        RootAdded = $rootAdded
        PublisherAdded = $publisherAdded
    }
}

function Remove-TemporaryInternalCertificateTrust {
    param([AllowNull()] [object] $State)

    if ($null -eq $State) {
        return
    }
    if ($State.PublisherAdded) {
        Remove-Item -LiteralPath $State.PublisherStorePath -Force -ErrorAction SilentlyContinue
    }
    if ($State.RootAdded) {
        Remove-Item -LiteralPath $State.RootStorePath -Force -ErrorAction SilentlyContinue
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
