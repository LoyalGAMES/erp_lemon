param(
    [Parameter(Mandatory = $true)]
    [string] $BaseUrl,

    [Parameter(Mandatory = $true)]
    [string] $Station,

    [string] $WorkerName = $env:COMPUTERNAME,
    [int] $PollSeconds = 2,
    [string] $SumatraPath = '',
    [SecureString] $Token,
    [string] $ConfigPath = "$env:ProgramData\Sempre ERP\Print Listener\config.ini",
    [string] $ProtectorPath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Zapis konfiguracji wymaga podniesionego procesu Windows.'
}

if ($BaseUrl -notmatch '^https://') {
    $uri = $null
    if (-not [Uri]::TryCreate($BaseUrl, [UriKind]::Absolute, [ref] $uri) -or
        $uri.Scheme -ne 'http' -or
        -not ($uri.IsLoopback)) {
        throw 'BaseUrl musi używać HTTPS. HTTP jest dozwolone wyłącznie dla testu loopback.'
    }
}
if ([string]::IsNullOrWhiteSpace($Station) -or $Station.Length -gt 40) {
    throw 'Station jest wymagane i może mieć najwyżej 40 znaków.'
}
if ([string]::IsNullOrWhiteSpace($WorkerName) -or $WorkerName.Length -gt 120) {
    throw 'WorkerName jest wymagane i może mieć najwyżej 120 znaków.'
}
if ($PollSeconds -lt 1 -or $PollSeconds -gt 60) {
    throw 'PollSeconds musi być w zakresie 1-60.'
}
foreach ($value in @($BaseUrl, $Station, $WorkerName, $SumatraPath)) {
    if ($value -match '[\r\n\x00]') {
        throw 'Wartości konfiguracji nie mogą zawierać znaków sterujących.'
    }
}
if ($null -eq $Token) {
    $Token = Read-Host 'Token z ustawień pakowania ERP' -AsSecureString
}

if ([string]::IsNullOrWhiteSpace($ProtectorPath)) {
    $root = Split-Path -Parent $PSScriptRoot
    $developmentProtector = Join-Path $root 'build\windows-amd64\lemon-print-listener.exe'
    $installedProtector = Join-Path $env:ProgramFiles 'Sempre ERP\Print Listener\lemon-print-listener.exe'
    $ProtectorPath = if (Test-Path -LiteralPath $installedProtector -PathType Leaf) {
        $installedProtector
    } else {
        $developmentProtector
    }
}
if (-not (Test-Path -LiteralPath $ProtectorPath -PathType Leaf)) {
    throw "Brak aplikacji zabezpieczającej ACL: $ProtectorPath"
}

$ConfigPath = [System.IO.Path]::GetFullPath($ConfigPath)
$configDirectory = Split-Path -Parent $ConfigPath
& $ProtectorPath -protect-config-directory $configDirectory
if ($LASTEXITCODE -ne 0) {
    throw 'Nie udało się zabezpieczyć katalogu konfiguracji.'
}

$bstr = [IntPtr]::Zero
$plainToken = $null
$tempPath = Join-Path $configDirectory ('.config-' + [Guid]::NewGuid().ToString('N') + '.tmp')
try {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Token)
    $plainToken = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    if ([string]::IsNullOrWhiteSpace($plainToken) -or $plainToken -match '[\r\n\x00]') {
        throw 'Token jest pusty albo zawiera niedozwolony znak sterujący.'
    }

    $lines = @(
        '[bridge]',
        "base_url=$($BaseUrl.TrimEnd('/'))",
        "token=$plainToken",
        "station=$Station",
        "worker_name=$WorkerName",
        "poll_seconds=$PollSeconds",
        "sumatra_path=$SumatraPath"
    )
    [IO.File]::WriteAllLines($tempPath, $lines, (New-Object Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $tempPath -Destination $ConfigPath -Force

    & $ProtectorPath -protect-config-file $ConfigPath
    if ($LASTEXITCODE -ne 0) {
        throw 'Nie udało się zabezpieczyć pliku konfiguracji.'
    }
    & $ProtectorPath -mode bridge -config $ConfigPath -validate-config
    if ($LASTEXITCODE -ne 0) {
        throw 'Aplikacja odrzuciła zapisaną konfigurację.'
    }
} finally {
    Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue
    if ($bstr -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
    $plainToken = $null
}

Write-Host "Chroniona konfiguracja została zapisana: $ConfigPath"
