param(
    [string] $ConfigPath = "$PSScriptRoot\config.json"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
}

$Config = Get-Content -Raw -Path $ConfigPath | ConvertFrom-Json
$BaseUrl = [string]$Config.baseUrl
$Token = [string]$Config.token
$Station = [string]$Config.station
$WorkerName = [string]$Config.workerName
$PollSeconds = if ($Config.pollSeconds) { [int]$Config.pollSeconds } else { 2 }

if ([string]::IsNullOrWhiteSpace($BaseUrl) -or [string]::IsNullOrWhiteSpace($Token)) {
    throw "baseUrl and token are required in $ConfigPath"
}

if ([string]::IsNullOrWhiteSpace($WorkerName)) {
    $WorkerName = $env:COMPUTERNAME
}

$BaseUrl = $BaseUrl.TrimEnd("/")
$Headers = @{ Authorization = "Bearer $Token" }

if (-not ("RawPrinterHelper" -as [type])) {
    Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;

public class RawPrinterHelper
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    public class DOCINFOA
    {
        [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPStr)] public string pDataType;
    }

    [DllImport("winspool.Drv", EntryPoint = "OpenPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool OpenPrinter(string szPrinter, out IntPtr hPrinter, IntPtr pd);

    [DllImport("winspool.Drv", EntryPoint = "ClosePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "StartDocPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFOA di);

    [DllImport("winspool.Drv", EntryPoint = "EndDocPrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "StartPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "EndPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "WritePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

    public static void SendBytesToPrinter(string printerName, byte[] bytes)
    {
        IntPtr printerHandle;
        if (!OpenPrinter(printerName.Normalize(), out printerHandle, IntPtr.Zero)) {
            throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
        }

        IntPtr unmanagedBytes = IntPtr.Zero;
        try {
            DOCINFOA docInfo = new DOCINFOA();
            docInfo.pDocName = "Lemon ERP label";
            docInfo.pDataType = "RAW";

            if (!StartDocPrinter(printerHandle, 1, docInfo)) {
                throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
            }

            if (!StartPagePrinter(printerHandle)) {
                throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
            }

            unmanagedBytes = Marshal.AllocCoTaskMem(bytes.Length);
            Marshal.Copy(bytes, 0, unmanagedBytes, bytes.Length);

            int written;
            if (!WritePrinter(printerHandle, unmanagedBytes, bytes.Length, out written) || written != bytes.Length) {
                throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
            }

            EndPagePrinter(printerHandle);
            EndDocPrinter(printerHandle);
        }
        finally {
            if (unmanagedBytes != IntPtr.Zero) {
                Marshal.FreeCoTaskMem(unmanagedBytes);
            }
            ClosePrinter(printerHandle);
        }
    }
}
"@
}

function Resolve-SumatraPath {
    param([object] $Config)

    $candidates = @()
    if ($Config.sumatraPath) { $candidates += [string]$Config.sumatraPath }
    $candidates += "$env:ProgramFiles\SumatraPDF\SumatraPDF.exe"
    $candidates += "${env:ProgramFiles(x86)}\SumatraPDF\SumatraPDF.exe"

    foreach ($candidate in $candidates) {
        if (-not [string]::IsNullOrWhiteSpace($candidate) -and (Test-Path $candidate)) {
            return $candidate
        }
    }

    return $null
}

function Join-ApiUrl {
    param([string] $Path)
    return "$BaseUrl$Path"
}

function Invoke-BridgePost {
    param(
        [string] $Path,
        [hashtable] $Body
    )

    Invoke-RestMethod `
        -Method Post `
        -Uri (Join-ApiUrl $Path) `
        -Headers $Headers `
        -ContentType "application/json" `
        -Body ($Body | ConvertTo-Json -Depth 5) | Out-Null
}

function Send-PrintJob {
    param(
        [object] $Job,
        [string] $FilePath
    )

    $printerName = [string]$Job.printer_name
    $format = ([string]$Job.format).ToLowerInvariant()

    if ([string]::IsNullOrWhiteSpace($printerName)) {
        throw "Job $($Job.id) has no printer_name"
    }

    if ($format -eq "zpl") {
        [RawPrinterHelper]::SendBytesToPrinter($printerName, [System.IO.File]::ReadAllBytes($FilePath))
        return
    }

    $sumatra = Resolve-SumatraPath $Config
    if ($null -eq $sumatra) {
        throw "PDF/image printing requires SumatraPDF. Set sumatraPath in config.json."
    }

    $args = @("-print-to", $printerName, "-silent", "-exit-on-print", $FilePath)
    $process = Start-Process -FilePath $sumatra -ArgumentList $args -Wait -PassThru -WindowStyle Hidden
    if ($process.ExitCode -ne 0) {
        throw "SumatraPDF exited with code $($process.ExitCode)"
    }
}

Write-Host "Lemon ERP print bridge started. Station=$Station Worker=$WorkerName BaseUrl=$BaseUrl"

while ($true) {
    $tempFile = $null
    $job = $null

    try {
        $query = "?worker=$([uri]::EscapeDataString($WorkerName))"
        if (-not [string]::IsNullOrWhiteSpace($Station)) {
            $query += "&station=$([uri]::EscapeDataString($Station))"
        }

        $response = Invoke-RestMethod -Method Get -Uri (Join-ApiUrl "/api/print-bridge/jobs/next$query") -Headers $Headers
        $job = $response.job

        if ($null -eq $job) {
            Start-Sleep -Seconds $PollSeconds
            continue
        }

        $fileName = [System.IO.Path]::GetFileName([string]$job.label.filename)
        if ([string]::IsNullOrWhiteSpace($fileName)) {
            $fileName = "label-$($job.id).bin"
        }

        $tempFile = Join-Path ([System.IO.Path]::GetTempPath()) ("lemon-print-$($job.id)-$fileName")
        Invoke-WebRequest -Method Get -Uri (Join-ApiUrl "/api/print-bridge/jobs/$($job.id)/file") -Headers $Headers -OutFile $tempFile

        Send-PrintJob -Job $job -FilePath $tempFile

        Invoke-BridgePost -Path "/api/print-bridge/jobs/$($job.id)/printed" -Body @{
            worker = $WorkerName
            message = "Printed on $env:COMPUTERNAME"
        }

        Write-Host "Printed job $($job.id) on $($job.printer_name)"
    }
    catch {
        $message = $_.Exception.Message
        Write-Warning $message

        if ($null -ne $job -and $null -ne $job.id) {
            try {
                Invoke-BridgePost -Path "/api/print-bridge/jobs/$($job.id)/failed" -Body @{
                    worker = $WorkerName
                    error = $message
                }
            }
            catch {
                Write-Warning "Could not report failed print job: $($_.Exception.Message)"
            }
        }

        Start-Sleep -Seconds $PollSeconds
    }
    finally {
        if ($tempFile -and (Test-Path $tempFile)) {
            Remove-Item -Path $tempFile -Force -ErrorAction SilentlyContinue
        }
    }
}
