param(
    [string] $InstallerPath = '',
    [string] $Version = '',
    [switch] $RequireSignature
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'certificate-utils.ps1')

$root = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($InstallerPath)) {
    $InstallerPath = Join-Path $root 'dist\SempreERP-PrintListener-Setup.exe'
}
if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = (Get-Content -LiteralPath (Join-Path $root 'VERSION') -Raw).Trim()
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Test instalatora wymaga podniesionego procesu Windows.'
}

$installDirectory = Join-Path $env:ProgramFiles 'Sempre ERP\Print Listener'
$listenerPath = Join-Path $installDirectory 'lemon-print-listener.exe'
$rendererPath = Join-Path $installDirectory 'SumatraPDF.exe'
$rendererLicensePath = Join-Path $installDirectory 'SumatraPDF-COPYING.txt'
$uninstallerPath = Join-Path $installDirectory 'uninstall.exe'
$configDirectory = Join-Path $env:ProgramData 'Sempre ERP\Print Listener'
$configPath = Join-Path $configDirectory 'config.ini'
$serviceName = 'SempreERPPrintListener'
$legacyFirewallName = 'Sempre ERP Print Listener (Private LAN)'
$mockPort = 18777
$mockToken = 'ci-outbound-bridge-token-32-bytes'
$mockStation = 'station-ci'
$mockWorker = 'CI-WINDOWS'
$temporaryRoot = if ([string]::IsNullOrWhiteSpace([string] $env:RUNNER_TEMP)) {
    [System.IO.Path]::GetTempPath()
} else {
    $env:RUNNER_TEMP
}
$mockLog = Join-Path $temporaryRoot 'sempre-print-bridge-mock.log'
$protectorPath = Join-Path $root 'build\windows-amd64\lemon-print-listener.exe'
$installed = $false
$mockJob = $null
$trustState = $null
$certificateBundle = $null
$signingProfile = 'unsigned'
$expectedSignerSubject = $null
$expectedSignerSha256 = $null
$rootCertificatePath = $null
$publisherCertificatePath = $null

if ($RequireSignature) {
    $signingProfile = ([string] $env:WINDOWS_CODESIGN_PROFILE).Trim().ToLowerInvariant()
    if ($signingProfile -notin @('internal', 'public')) {
        throw 'Podpisany smoke-test wymaga WINDOWS_CODESIGN_PROFILE=internal albo public.'
    }
    $expectedSignerSubject = ([string] $env:WINDOWS_CODESIGN_SUBJECT).Trim()
    $expectedSignerSha256 = Normalize-CertificateSha256 `
        ([string] $env:WINDOWS_CODESIGN_LEAF_SHA256) `
        'WINDOWS_CODESIGN_LEAF_SHA256'

    if ($signingProfile -eq 'internal') {
        $releaseDirectory = Split-Path -Parent ([System.IO.Path]::GetFullPath($InstallerPath))
        $rootCertificatePath = Join-Path $releaseDirectory 'SempreERP-Internal-Root.cer'
        $publisherCertificatePath = Join-Path $releaseDirectory 'SempreERP-Internal-Publisher.cer'
        $expectedRootSha256 = Normalize-CertificateSha256 `
            ([string] $env:WINDOWS_CODESIGN_ROOT_SHA256) `
            'WINDOWS_CODESIGN_ROOT_SHA256'
        $certificateBundle = Assert-InternalCertificateBundle `
            -RootCertificatePath $rootCertificatePath `
            -LeafCertificatePath $publisherCertificatePath `
            -ExpectedRootSha256 $expectedRootSha256 `
            -ExpectedLeafSha256 $expectedSignerSha256 `
            -ExpectedSubject $expectedSignerSubject

        $rootStorePath = "Cert:\LocalMachine\Root\$($certificateBundle.Root.Thumbprint)"
        $publisherStorePath = "Cert:\LocalMachine\TrustedPublisher\$($certificateBundle.Leaf.Thumbprint)"
        if ((Test-Path -LiteralPath $rootStorePath) -or (Test-Path -LiteralPath $publisherStorePath)) {
            throw 'Podpisany smoke-test internal wymaga czystych magazynów certyfikatów Sempre ERP.'
        }
        # The installer must create these exact entries. This state removes
        # only those previously absent entries from the ephemeral runner.
        $trustState = [pscustomobject]@{
            RootStorePath = $rootStorePath
            PublisherStorePath = $publisherStorePath
            RootThumbprint = $certificateBundle.Root.Thumbprint
            PublisherThumbprint = $certificateBundle.Leaf.Thumbprint
            RootSha256 = Get-CertificateSha256 $certificateBundle.Root
            PublisherSha256 = Get-CertificateSha256 $certificateBundle.Leaf
            RootAdded = $true
            PublisherAdded = $true
        }
    }
}

Remove-Item -LiteralPath $mockLog -Force -ErrorAction SilentlyContinue

try {
if ($RequireSignature -and $signingProfile -eq 'internal') {
    foreach ($path in @($installDirectory, $configDirectory)) {
        if (Test-Path -LiteralPath $path) {
            throw "Smoke-test bootstrapu wymaga czystej ścieżki: $path"
        }
    }
    if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
        throw 'Smoke-test bootstrapu wymaga braku istniejącej usługi listenera.'
    }

    # Force a failure after the installer has bootstrapped trust but before it
    # can install the service. .onInstFailed must roll back both new entries.
    $failedInstall = Start-Process -FilePath $InstallerPath -ArgumentList '/S' -PassThru
    if (-not $failedInstall.WaitForExit(60000)) {
        Stop-Process -Id $failedInstall.Id -Force -ErrorAction SilentlyContinue
        throw 'Negatywna próba instalatora nie zakończyła się w ciągu 60 sekund.'
    }
    $failedInstall.Refresh()
    if ($failedInstall.ExitCode -eq 0) {
        throw 'Instalator bez konfiguracji nieoczekiwanie zakończył się sukcesem.'
    }
    if ((Test-Path -LiteralPath $trustState.RootStorePath) -or
        (Test-Path -LiteralPath $trustState.PublisherStorePath)) {
        throw 'Instalator nie wycofał zaufania po kontrolowanym niepowodzeniu.'
    }
    Remove-Item -LiteralPath $installDirectory, $configDirectory -Recurse -Force -ErrorAction SilentlyContinue
}
try {
    $mockJob = Start-Job -ArgumentList $mockPort, $mockToken, $mockStation, $mockWorker, $mockLog -ScriptBlock {
        param($Port, $Token, $Station, $Worker, $LogPath)
        $ErrorActionPreference = 'Stop'
        $listener = New-Object System.Net.HttpListener
        $listener.Prefixes.Add("http://127.0.0.1:$Port/")
        $listener.Start()
        try {
            while ($true) {
                $context = $listener.GetContext()
                $request = $context.Request
                $response = $context.Response
                $response.ContentType = 'application/json'
                $body = '{"success":false}'

                if ($request.Headers['Authorization'] -ne "Bearer $Token") {
                    $response.StatusCode = 401
                    $body = '{"success":false,"message":"unauthorized"}'
                } elseif ($request.Url.AbsolutePath -eq '/api/print-bridge/jobs/next' -and
                    $request.QueryString['station'] -eq $Station -and
                    $request.QueryString['worker'] -eq $Worker) {
                    Add-Content -LiteralPath $LogPath -Value 'authorized-outbound-poll' -Encoding ASCII
                    $response.StatusCode = 200
                    $body = '{"success":true,"job":null}'
                } else {
                    $response.StatusCode = 404
                    $body = '{"success":false,"message":"not-found"}'
                }

                $bytes = [Text.Encoding]::UTF8.GetBytes($body)
                $response.ContentLength64 = $bytes.Length
                $response.OutputStream.Write($bytes, 0, $bytes.Length)
                $response.OutputStream.Close()
            }
        } finally {
            $listener.Stop()
            $listener.Close()
        }
    }

    Start-Sleep -Seconds 1
    if ($mockJob.State -eq 'Failed') {
        Receive-Job -Job $mockJob -ErrorAction Stop | Out-Null
        throw 'Lokalny mock ERP nie uruchomił się.'
    }

    $secureToken = ConvertTo-SecureString $mockToken -AsPlainText -Force
    & (Join-Path $PSScriptRoot 'write-config.ps1') `
        -BaseUrl "http://127.0.0.1:$mockPort" `
        -Station $mockStation `
        -WorkerName $mockWorker `
        -Token $secureToken `
        -ConfigPath $configPath `
        -ProtectorPath $protectorPath

    # Set this before launching so a partially completed installation is also
    # cleaned up when the installer reports an error.
    $installed = $true
    $install = Start-Process -FilePath $InstallerPath -ArgumentList '/S' -PassThru
    if (-not $install.WaitForExit(60000)) {
        Stop-Process -Id $install.Id -Force -ErrorAction SilentlyContinue
        throw 'Instalator nie zakończył się w ciągu 60 sekund.'
    }
    $install.Refresh()
    if ($install.ExitCode -ne 0) {
        $listenerLog = Join-Path $configDirectory 'listener.log'
        if (Test-Path -LiteralPath $listenerLog -PathType Leaf) {
            Write-Host 'Dziennik instalatora/usługi:'
            Get-Content -LiteralPath $listenerLog | Write-Host
        }
        throw "Instalator zakończył się kodem $($install.ExitCode)."
    }

    if ($RequireSignature -and $signingProfile -eq 'internal') {
        Assert-MachineStoreCertificate -StoreName Root -Certificate $certificateBundle.Root
        Assert-MachineStoreCertificate -StoreName TrustedPublisher -Certificate $certificateBundle.Leaf
    }

    $service = Get-Service -Name $serviceName -ErrorAction Stop
    if ($service.Status -ne 'Running') {
        $service.WaitForStatus('Running', [TimeSpan]::FromSeconds(20))
    }
    foreach ($path in @($rendererPath, $rendererLicensePath)) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "Instalator nie umieścił wymaganego pliku: $path"
        }
    }
    if ((Get-FileHash -LiteralPath $rendererPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne
        '719f689b34f47be8ca105ce8484948474dafde0e106bab599e4a89326070c3d0') {
        throw 'Zainstalowany renderer PDF ma nieoczekiwany SHA-256.'
    }
    if ((Get-AuthenticodeSignature -FilePath $rendererPath).Status -ne 'Valid') {
        throw 'Zainstalowany renderer PDF nie ma prawidłowego podpisu Authenticode.'
    }
    & $listenerPath -validate-renderer
    if ($LASTEXITCODE -ne 0) {
        throw 'Listener odrzucił zainstalowany renderer PDF.'
    }

    $deadline = (Get-Date).AddSeconds(25)
    $health = $null
    while ((Get-Date) -lt $deadline) {
        try {
            $candidate = Invoke-RestMethod -Uri 'http://127.0.0.1:17778/health' -TimeoutSec 3
            if ($candidate.connected -eq $true) {
                $health = $candidate
                break
            }
        } catch {
            # The service and its loopback-only health endpoint may still start.
        }
        Start-Sleep -Milliseconds 500
    }
    if ($null -eq $health -or $health.success -ne $true -or $health.mode -ne 'bridge' -or
        $health.version -ne $Version -or $health.station -ne $mockStation -or
        [string]::IsNullOrWhiteSpace([string] $health.last_success_at)) {
        throw "Health nie potwierdził połączenia outbound wersji $Version z mockiem ERP."
    }
    if (-not (Test-Path -LiteralPath $mockLog) -or
        -not (Select-String -LiteralPath $mockLog -SimpleMatch 'authorized-outbound-poll' -Quiet)) {
        throw 'Mock ERP nie zarejestrował autoryzowanego pollingu wychodzącego.'
    }

    $serviceConfig = Get-CimInstance Win32_Service -Filter "Name='$serviceName'"
    if ($serviceConfig.PathName -match [regex]::Escape($mockToken)) {
        throw 'Token wyciekł do argumentów usługi Windows.'
    }
    if ($serviceConfig.PathName -notmatch '-config') {
        throw 'Usługa nie wskazuje chronionego pliku konfiguracji.'
    }
    if ($serviceConfig.StartName -ne 'LocalSystem') {
        throw "Usługa działa na nieoczekiwanym koncie: $($serviceConfig.StartName)"
    }

    foreach ($path in @($configDirectory, $configPath)) {
        $sddl = (Get-Acl -LiteralPath $path).Sddl
        if ($sddl -notmatch '^O:BA' -or $sddl -notmatch 'D:P' -or
            $sddl -notmatch ';;;SY\)' -or $sddl -notmatch ';;;BA\)') {
            throw "ACL $path nie jest ograniczony do SYSTEM i Administratorów: $sddl"
        }
    }

    if (Get-NetFirewallRule -DisplayName $legacyFirewallName -ErrorAction SilentlyContinue) {
        throw 'Instalator outbound pozostawił przychodzącą regułę Zapory Windows.'
    }

    # A second silent run exercises the real in-place upgrade path: the service
    # must be stopped, updated without DeleteService, restarted and usable.
    $upgrade = Start-Process -FilePath $InstallerPath -ArgumentList '/S' -PassThru
    if (-not $upgrade.WaitForExit(60000)) {
        Stop-Process -Id $upgrade.Id -Force -ErrorAction SilentlyContinue
        throw 'Aktualizacja instalatora nie zakończyła się w ciągu 60 sekund.'
    }
    $upgrade.Refresh()
    if ($upgrade.ExitCode -ne 0) {
        throw "Ponowne uruchomienie instalatora (upgrade) zakończyło się kodem $($upgrade.ExitCode)."
    }
    $service = Get-Service -Name $serviceName -ErrorAction Stop
    if ($service.Status -ne 'Running') {
        $service.WaitForStatus('Running', [TimeSpan]::FromSeconds(20))
    }
    $upgradeDeadline = (Get-Date).AddSeconds(25)
    $upgradeHealth = $null
    while ((Get-Date) -lt $upgradeDeadline) {
        try {
            $candidate = Invoke-RestMethod -Uri 'http://127.0.0.1:17778/health' -TimeoutSec 3
            if ($candidate.connected -eq $true -and
                -not [string]::IsNullOrWhiteSpace([string] $candidate.last_success_at)) {
                $upgradeHealth = $candidate
                break
            }
        } catch {
            # The upgraded service may still be starting.
        }
        Start-Sleep -Milliseconds 500
    }
    if ($null -eq $upgradeHealth) {
        throw 'Usługa po aktualizacji nie odzyskała połączenia wychodzącego z ERP.'
    }

    if ($RequireSignature) {
        foreach ($path in @($InstallerPath, $listenerPath, $uninstallerPath)) {
            [void] (Assert-AuthenticodeSigner $path $expectedSignerSubject $expectedSignerSha256)
        }
        $rendererSignature = Get-AuthenticodeSignature -FilePath $rendererPath
        if ($rendererSignature.Status -ne 'Valid') {
            throw "Renderer $rendererPath nie ma prawidłowego podpisu Authenticode."
        }
    }
} finally {
    if ($installed -and (Test-Path -LiteralPath $uninstallerPath)) {
        $uninstall = Start-Process -FilePath $uninstallerPath -ArgumentList '/S', "_?=$installDirectory" -PassThru
        if (-not $uninstall.WaitForExit(60000)) {
            Stop-Process -Id $uninstall.Id -Force -ErrorAction SilentlyContinue
            throw 'Deinstalator nie zakończył się w ciągu 60 sekund.'
        }
        $uninstall.Refresh()
        if ($uninstall.ExitCode -ne 0) {
            throw "Uninstaller zakończył się kodem $($uninstall.ExitCode)."
        }
    }
    if ($null -ne $mockJob) {
        Stop-Job -Job $mockJob -ErrorAction SilentlyContinue
        Remove-Job -Job $mockJob -Force -ErrorAction SilentlyContinue
    }
}

if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
    throw 'Usługa pozostała po odinstalowaniu.'
}
if (Get-NetFirewallRule -DisplayName $legacyFirewallName -ErrorAction SilentlyContinue) {
    throw 'Stara reguła przychodząca pozostała po odinstalowaniu.'
}
if (Test-Path -LiteralPath $configPath) {
    throw 'Plik z tokenem pozostał po odinstalowaniu.'
}

Write-Host 'Outbound polling, ACL, brak tokenu w SCM, usługa, health i deinstalacja: OK.'
} finally {
    Remove-TemporaryInternalCertificateTrust $trustState
    $trustCleanupFailed = $null -ne $trustState -and (
        (Test-Path -LiteralPath $trustState.RootStorePath) -or
        (Test-Path -LiteralPath $trustState.PublisherStorePath)
    )
    if ($null -ne $certificateBundle) {
        $certificateBundle.Root.Dispose()
        $certificateBundle.Leaf.Dispose()
    }
    if ($trustCleanupFailed) {
        throw 'Smoke-test pozostawił wewnętrzne certyfikaty w magazynach maszyny.'
    }
}
