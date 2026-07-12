param(
    [string] $InstallerPath = '',
    [string] $ListenerPath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($InstallerPath)) {
    $InstallerPath = Join-Path $root 'dist\SempreERP-PrintListener-Setup.exe'
}
if ([string]::IsNullOrWhiteSpace($ListenerPath)) {
    $ListenerPath = Join-Path $root 'build\windows-amd64\lemon-print-listener.exe'
}

$paths = @($InstallerPath, $ListenerPath) | ForEach-Object {
    if (-not (Test-Path -LiteralPath $_ -PathType Leaf)) {
        throw "Brak artefaktu do skanowania Microsoft Defender: $_"
    }
    (Resolve-Path -LiteralPath $_).Path
}

function Write-ScanStatus {
    param([string] $Status, [string] $Message)

    Write-Host "Microsoft Defender scan: $Status — $Message"
    if (-not [string]::IsNullOrWhiteSpace([string] $env:GITHUB_OUTPUT)) {
        "status=$Status" | Out-File -FilePath $env:GITHUB_OUTPUT -Encoding utf8 -Append
        "message=$Message" | Out-File -FilePath $env:GITHUB_OUTPUT -Encoding utf8 -Append
    }
}

$requiredCommands = @('Get-MpComputerStatus', 'Start-MpScan', 'Get-MpThreatDetection')
$missingCommands = @($requiredCommands | Where-Object { $null -eq (Get-Command $_ -ErrorAction SilentlyContinue) })
$defenderService = Get-Service -Name WinDefend -ErrorAction SilentlyContinue

if ($null -eq $defenderService -or $defenderService.Status -ne 'Running' -or $missingCommands.Count -gt 0) {
    $details = @()
    if ($null -eq $defenderService) {
        $details += 'brak usługi WinDefend'
    } elseif ($defenderService.Status -ne 'Running') {
        $details += "WinDefend=$($defenderService.Status)"
    }
    if ($missingCommands.Count -gt 0) {
        $details += "brak cmdletów: $($missingCommands -join ', ')"
    }
    Write-ScanStatus 'unavailable' "Runner nie udostępnia działającej platformy Microsoft Defender ($($details -join '; ')); skan został jawnie pominięty."
    exit 0
}

$status = Get-MpComputerStatus
if (-not $status.AntivirusEnabled) {
    Write-ScanStatus 'unavailable' 'Microsoft Defender działa jako usługa, ale AntivirusEnabled=false; skan został jawnie pominięty.'
    exit 0
}

$startedAt = Get-Date
foreach ($path in $paths) {
    Write-Host "Skanuję Microsoft Defender: $path"
    Start-MpScan -ScanType CustomScan -ScanPath $path
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Microsoft Defender usunął lub poddał kwarantannie artefakt: $path"
    }
}

$detections = @()
foreach ($detection in @(Get-MpThreatDetection -ErrorAction Stop)) {
    if ($detection.InitialDetectionTime -lt $startedAt.AddMinutes(-1)) {
        continue
    }
    $matchesArtifact = $false
    foreach ($resource in @($detection.Resources)) {
        foreach ($path in $paths) {
            if (([string] $resource).IndexOf($path, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
                $matchesArtifact = $true
                break
            }
        }
        if ($matchesArtifact) { break }
    }
    if ($matchesArtifact) {
        $detections += $detection
    }
}
if ($detections.Count -gt 0) {
    $names = @($detections | ForEach-Object {
        if (-not [string]::IsNullOrWhiteSpace([string] $_.ThreatName)) {
            [string] $_.ThreatName
        } else {
            "ThreatID=$($_.ThreatID)"
        }
    } | Select-Object -Unique)
    throw "Microsoft Defender wykrył zagrożenie w artefaktach wydania: $($names -join ', ')."
}

$signatureAge = if ($null -ne $status.AntivirusSignatureLastUpdated) {
    ((Get-Date) - $status.AntivirusSignatureLastUpdated).TotalHours
} else {
    $null
}
$signatureMessage = if ($null -eq $signatureAge) {
    'wersja sygnatur nie została zgłoszona'
} else {
    'wiek sygnatur: {0:N1} h' -f $signatureAge
}
Write-ScanStatus 'passed' "Przeskanowano $($paths.Count) podpisane artefakty; $signatureMessage."
