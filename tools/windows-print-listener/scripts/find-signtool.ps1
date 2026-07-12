Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Find-SignTool {
    $fromPath = Get-Command signtool.exe -ErrorAction SilentlyContinue
    if ($null -ne $fromPath) {
        return $fromPath.Source
    }

    $kitsRoot = Join-Path ${env:ProgramFiles(x86)} 'Windows Kits\10\bin'
    $candidate = Get-ChildItem -Path $kitsRoot -Filter signtool.exe -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -match '\\x64\\signtool\.exe$' } |
        Sort-Object FullName -Descending |
        Select-Object -First 1

    if ($null -eq $candidate) {
        throw 'Nie znaleziono signtool.exe z Windows SDK.'
    }

    return $candidate.FullName
}

function Invoke-SignTool {
    param(
        [Parameter(Mandatory = $true)]
        [string] $SignToolPath,
        [Parameter(Mandatory = $true)]
        [string[]] $Arguments,
        [ValidateRange(10, 600)]
        [int] $TimeoutSeconds = 120
    )

    $startInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $SignToolPath
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
            throw 'Nie udało się uruchomić signtool.exe.'
        }
        $standardOutput = $process.StandardOutput.ReadToEndAsync()
        $standardError = $process.StandardError.ReadToEndAsync()

        if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
            try {
                $process.Kill($true)
                $process.WaitForExit()
            } catch {
                # The runner is ephemeral; report the original timeout even if
                # the process exited between WaitForExit and Kill.
            }
            throw "signtool.exe przekroczył limit $TimeoutSeconds sekund."
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
            throw "signtool.exe zakończył się kodem $($process.ExitCode)."
        }
    } finally {
        $process.Dispose()
    }
}
