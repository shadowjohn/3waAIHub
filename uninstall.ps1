param(
    [ValidateSet('Core', 'WslRuntime', 'NativeAgent', 'RemoteControlPlane')]
    [string]$Mode = 'Core',
    [string]$InstallRoot = $PSScriptRoot,
    [string]$WslDistro = 'Ubuntu-24.04',
    [string]$LinuxDataRoot = '/DATA',
    [switch]$RemoveRuntimeData,
    [switch]$RemoveModels,
    [switch]$Check,
    [switch]$Help
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Show-Usage {
    Write-Host 'Usage:'
    Write-Host '  .\uninstall.ps1 -Mode Core -Check'
    Write-Host '  .\uninstall.ps1 -Mode WslRuntime -Check [-RemoveRuntimeData] [-RemoveModels]'
    Write-Host '  .\uninstall.ps1 -Mode NativeAgent -Check'
    Write-Host '  .\uninstall.ps1 -Mode RemoteControlPlane -Check'
}

if ($Help) {
    Show-Usage
    exit 0
}

if ($RemoveModels -and -not $RemoveRuntimeData) {
    throw '-RemoveModels requires -RemoveRuntimeData.'
}

$profilePath = Join-Path $InstallRoot 'data\runtime_profile.json'

Write-Host '[3waAIHub] Windows role uninstaller'
Write-Host "Mode: $Mode"
switch ($Mode) {
    'Core' { Write-Host 'Role: 3waAIHub Core (Control Plane)' }
    'WslRuntime' { Write-Host 'Role: WSL Runtime (Preview)' }
    'NativeAgent' { Write-Host 'Role: Windows Native Agent' }
    'RemoteControlPlane' { Write-Host 'Role: Remote Linux Agent Control Plane' }
}
Write-Host "InstallRoot: $InstallRoot"
Write-Host 'No files or services will be removed during -Check.'

switch ($Mode) {
    'Core' {
        Write-Host 'Preserve: global PHP, WSL, NVIDIA driver, project root, SQLite DB, data directory, models.'
        if (Test-Path -LiteralPath $profilePath) {
            Write-Host "Managed profile candidate: $profilePath"
        } else {
            Write-Host "Managed profile candidate: none"
        }
        if ($Check) { exit 0 }
        Write-Host 'No managed Core resources removed; project and data are preserved.'
    }
    'WslRuntime' {
        Write-Host "Preserve by default: WSL distro $WslDistro, Docker Engine, $LinuxDataRoot, $LinuxDataRoot/models."
        if ($RemoveRuntimeData) {
            Write-Host "Runtime data removal requested: $LinuxDataRoot/3waAIHub-runtime"
        }
        if ($RemoveModels) {
            Write-Host "Models removal requested: $LinuxDataRoot/models"
        }
        if ($Check) { exit 0 }
        throw 'WslRuntime removal is check-only in this build.'
    }
    'NativeAgent' {
        Write-Host 'Windows Native Agent removal is check-only in this build.'
        if ($Check) { exit 0 }
        throw 'NativeAgent uninstall is not implemented in this build.'
    }
    'RemoteControlPlane' {
        Write-Host 'RemoteControlPlane profile removal is check-only in this build.'
        if ($Check) { exit 0 }
        throw 'RemoteControlPlane uninstall is not implemented in this build.'
    }
}
