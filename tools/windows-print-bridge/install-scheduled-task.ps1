param(
    [string] $ConfigPath = "$PSScriptRoot\config.json",
    [string] $TaskName = "Lemon ERP Print Bridge"
)

$ErrorActionPreference = "Stop"

$scriptPath = Join-Path $PSScriptRoot "print-bridge.ps1"
if (-not (Test-Path $scriptPath)) {
    throw "Bridge script not found: $scriptPath"
}

if (-not (Test-Path $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
}

$powershell = (Get-Command pwsh -ErrorAction SilentlyContinue).Source
if (-not $powershell) {
    $powershell = (Get-Command powershell.exe -ErrorAction Stop).Source
}

$arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`" -ConfigPath `"$ConfigPath`""
$action = New-ScheduledTaskAction -Execute $powershell -Argument $arguments
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description "Polls Lemon ERP and prints shipping labels on Windows printers." `
    -Force | Out-Null

Write-Host "Scheduled task installed: $TaskName"
