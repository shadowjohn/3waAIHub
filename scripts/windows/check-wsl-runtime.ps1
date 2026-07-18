param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$ModelsRoot = 'D:\DATA\models',
    [string]$WslDistro = 'Ubuntu-24.04',
    [string]$LinuxDataRoot = '/DATA'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-Captured {
    param([string[]]$Command)

    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $Command[0] @($Command | Select-Object -Skip 1) 2>&1 | Out-String
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorAction
    }
    return [pscustomobject]@{
        ExitCode = $exitCode
        Output = (($output -replace "`0", '')).Trim()
    }
}

function Write-Check {
    param([string]$Label, [bool]$Ok, [string]$Detail = '', [string]$Fix = '')

    $state = if ($Ok) { 'OK' } else { 'MISSING' }
    if ($Detail -ne '') {
        Write-Host "$Label`: $state ($Detail)"
    } else {
        Write-Host "$Label`: $state"
    }
    if (-not $Ok -and $Fix -ne '') {
        Write-Host "  fix: $Fix"
    }
}

function Write-Readiness {
    param([bool]$Ready)

    if ($Ready) {
        Write-Host 'Status: READY'
        Write-Host 'Ready: true'
    } else {
        Write-Host 'Status: NOT READY'
        Write-Host 'Ready: false'
    }
}

function Assert-LinuxDataRoot {
    param([string]$Path)

    if ($Path -eq '/') { return }
    if ($Path -notmatch '^/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$') {
        throw 'LinuxDataRoot must be a safe absolute Linux path.'
    }
    foreach ($segment in ($Path.Substring(1) -split '/')) {
        if ($segment -in @('.', '..')) {
            throw 'LinuxDataRoot must be a safe absolute Linux path.'
        }
    }
}

function Get-WslDistroVersion {
    param([string]$Output, [string]$Distro)

    foreach ($line in (($Output -replace "`0", '') -split '\r?\n')) {
        $clean = ([string]$line).Trim() -replace '^\*\s*', ''
        if ($clean -eq '') { continue }
        $columns = @($clean -split '\s+' | Where-Object { $_ -ne '' })
        if ($columns.Count -lt 2 -or -not [string]::Equals($columns[0], $Distro, [System.StringComparison]::OrdinalIgnoreCase)) {
            continue
        }
        if ($columns[-1] -match '^\d+$') {
            return [int]$columns[-1]
        }
    }
    return $null
}

Assert-LinuxDataRoot $LinuxDataRoot

if ($env:AIHUB_WINDOWS_INSTALLER_TEST_EXCEPTION -eq '1') {
    throw 'Forced Windows installer test exception.'
}

Write-Host 'Mode: WslRuntime'
Write-Host 'Role: WSL Runtime (Preview)'
Write-Host 'Check: read-only'
Write-Host "InstallRoot: $InstallRoot"
Write-Host "ModelsRoot: $ModelsRoot"
Write-Host "WslDistro: $WslDistro"
Write-Host "LinuxDataRoot: $LinuxDataRoot"
Write-Host "RuntimeRoot: $LinuxDataRoot/3waAIHub-runtime"
Write-Host "LinuxModelsRoot: $LinuxDataRoot/models"

$wslCommand = if ([string]::IsNullOrWhiteSpace($env:AIHUB_WSL_EXECUTABLE)) { 'wsl.exe' } else { $env:AIHUB_WSL_EXECUTABLE }
$wsl = Get-Command $wslCommand -ErrorAction SilentlyContinue
if ($null -eq $wsl) {
    Write-Check 'WSL' $false '' 'Enable WSL2, install Ubuntu, then rerun this check.'
    Write-Readiness $false
    exit 0
}
Write-Check 'WSL' $true $wsl.Source

$list = Invoke-Captured @($wsl.Source, '-l', '-v')
Write-Host 'WSL distros:'
if ($list.Output -eq '') {
    Write-Host '  (none)'
} else {
    foreach ($line in ($list.Output -split '\r?\n')) {
        if ($line.Trim() -ne '') { Write-Host "  $($line.Trim())" }
    }
}

$distroVersion = Get-WslDistroVersion $list.Output $WslDistro
$distroPresent = $null -ne $distroVersion
Write-Check 'WSL distro' $distroPresent $WslDistro "wsl --install -d $WslDistro"
if (-not $distroPresent) {
    Write-Readiness $false
    exit 0
}

$version2 = $distroVersion -eq 2
Write-Check 'WSL2 distro version' $version2 $WslDistro "wsl --set-version $WslDistro 2"

$docker = Invoke-Captured @($wsl.Source, '-d', $WslDistro, '--', 'sh', '-lc', 'docker --version')
Write-Check 'Docker Engine in distro' ($docker.ExitCode -eq 0) $docker.Output 'Install Docker Engine inside the WSL distro or configure a verified WSL2 backend.'

$daemon = Invoke-Captured @($wsl.Source, '-d', $WslDistro, '--', 'sh', '-lc', 'docker info --format "{{.ServerVersion}}"')
Write-Check 'Docker daemon in distro' ($daemon.ExitCode -eq 0) $daemon.Output 'Start Docker Engine inside the WSL distro.'

$compose = Invoke-Captured @($wsl.Source, '-d', $WslDistro, '--', 'sh', '-lc', 'docker compose version')
Write-Check 'Docker Compose in distro' ($compose.ExitCode -eq 0) $compose.Output 'sudo apt-get install -y docker-compose-plugin'

$nvidia = Invoke-Captured @($wsl.Source, '-d', $WslDistro, '--', 'sh', '-lc', 'nvidia-smi --query-gpu=name,driver_version,memory.total --format=csv,noheader')
Write-Check 'nvidia-smi in distro' ($nvidia.ExitCode -eq 0) $nvidia.Output 'Install/update NVIDIA Windows driver with WSL CUDA support.'

$dataFs = Invoke-Captured @($wsl.Source, '-d', $WslDistro, '--', 'sh', '-lc', "findmnt -n -o FSTYPE -T '$LinuxDataRoot' 2>/dev/null")
$isExt4 = $dataFs.ExitCode -eq 0 -and $dataFs.Output -match 'ext4'
Write-Check "$LinuxDataRoot filesystem" $isExt4 $dataFs.Output "sudo mkdir -p $LinuxDataRoot/3waAIHub-runtime $LinuxDataRoot/models && keep runtime data on WSL ext4, not /mnt/d"

$gpuSmoke = Invoke-Captured @($wsl.Source, '-d', $WslDistro, '--', 'sh', '-lc', 'docker run --rm --pull=never --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi')
Write-Check 'Container GPU smoke' ($gpuSmoke.ExitCode -eq 0) $gpuSmoke.Output "wsl.exe -d $WslDistro -- docker pull nvidia/cuda:12.9.0-base-ubuntu22.04"

$ready = $version2 -and $docker.ExitCode -eq 0 -and $daemon.ExitCode -eq 0 -and $compose.ExitCode -eq 0 -and $nvidia.ExitCode -eq 0 -and $isExt4 -and $gpuSmoke.ExitCode -eq 0
Write-Readiness $ready

exit 0
