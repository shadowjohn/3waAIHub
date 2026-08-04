param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [ValidateRange(1, 20)]
    [int]$Limit = 1
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::Equals($env:USERNAME, 'SYSTEM', [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'WSL command worker must run as the interactive Windows user, not LocalSystem.'
}

$php = Get-Command php -ErrorAction Stop
$commandWorker = Join-Path $InstallRoot 'scripts\command_worker.php'
$taskWorker = Join-Path $InstallRoot 'scripts\task_worker.php'
if (-not (Test-Path -LiteralPath $commandWorker)) {
    throw 'command_worker.php is missing.'
}
if (-not (Test-Path -LiteralPath $taskWorker)) {
    throw 'task_worker.php is missing.'
}

Set-Location -LiteralPath $InstallRoot
& $php.Source $taskWorker "--limit=$Limit" '--runtime=wsl'
$taskWorkerExitCode = $LASTEXITCODE
& $php.Source $commandWorker "--limit=$Limit" '--runtime=wsl'
$commandWorkerExitCode = $LASTEXITCODE
exit $(if ($taskWorkerExitCode -ne 0) { $taskWorkerExitCode } else { $commandWorkerExitCode })
