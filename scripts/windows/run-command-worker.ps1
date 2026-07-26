param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$logDir = Join-Path $InstallRoot 'data\logs'
$logPath = Join-Path $logDir 'command_worker_windows.log'
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

    Set-Location -LiteralPath $InstallRoot
    $output = @(& $phpExe $worker '--limit=5' 2>&1)
    $exitCode = $LASTEXITCODE
    $lines = @("[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] command_worker exit=$exitCode") + @($output | ForEach-Object { [string]$_ })
    Write-WorkerLog -Lines $lines
    exit $exitCode
} catch {
    Write-WorkerLog -Lines @("[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] command_worker error=$($_.Exception.Message)")
    exit 1
}
