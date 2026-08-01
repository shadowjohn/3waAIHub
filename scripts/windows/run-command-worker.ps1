param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$logDir = Join-Path $InstallRoot 'data\logs'
$logPath = Join-Path $logDir 'command_worker_windows.log'
$metrics = Join-Path $InstallRoot 'scripts\collect_host_metrics.php'
$worker = Join-Path $InstallRoot 'scripts\command_worker.php'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null

function Write-WorkerLog {
    param([string[]]$Lines)

    $writer = [System.IO.StreamWriter]::new($logPath, $true, [System.Text.UTF8Encoding]::new($false))
    try {
        foreach ($line in $Lines) {
            $writer.WriteLine($line)
        }
    } finally {
        $writer.Dispose()
    }
}

try {
    $php = Get-Command php -ErrorAction SilentlyContinue
    $phpExe = if ($null -ne $php) { $php.Source } else { Join-Path $InstallRoot 'tools\php\php.exe' }
    if (-not (Test-Path -LiteralPath $phpExe)) {
        throw 'PHP CLI was not found in PATH or the managed PHP directory.'
    }
    if (-not (Test-Path -LiteralPath $worker)) {
        throw 'command_worker.php is missing.'
    }
    if (-not (Test-Path -LiteralPath $metrics)) {
        throw 'collect_host_metrics.php is missing.'
    }

    Set-Location -LiteralPath $InstallRoot
    $metricsOutput = @(& $phpExe $metrics 2>&1)
    $metricsExitCode = $LASTEXITCODE
    $workerOutput = @(& $phpExe $worker '--limit=5' 2>&1)
    $workerExitCode = $LASTEXITCODE
    $timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $lines = @("[$timestamp] host_metrics exit=$metricsExitCode") + @($metricsOutput | ForEach-Object { [string]$_ })
    $lines += @("[$timestamp] command_worker exit=$workerExitCode") + @($workerOutput | ForEach-Object { [string]$_ })
    Write-WorkerLog -Lines $lines
    exit $(if ($metricsExitCode -ne 0) { $metricsExitCode } else { $workerExitCode })
} catch {
    Write-WorkerLog -Lines @("[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] command_worker error=$($_.Exception.Message)")
    exit 1
}
