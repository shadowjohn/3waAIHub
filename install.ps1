param(
    [ValidateSet('Core', 'WslRuntime', 'NativeAgent', 'RemoteControlPlane')]
    [string]$Mode = 'Core',
    [string]$InstallRoot = $PSScriptRoot,
    [string]$ModelsRoot = 'D:\DATA\models',
    [string]$WslDistro = 'Ubuntu-24.04',
    [string]$LinuxDataRoot = '/DATA',
    [ValidateSet(0, 1, 2, 3)]
    [int]$ProductType = 0,
    [switch]$InstallIis,
    [switch]$Check,
    [switch]$Help,
    [string]$PhpZipUri,
    [string]$PhpZipSha256
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Show-Usage {
    Write-Host 'Usage:'
    Write-Host '  .\install.ps1 -Mode Core [-InstallIis] [-ModelsRoot D:\DATA\models] [-Check] [-InstallRoot D:\DATA\3waAIHub]'
    Write-Host '  .\install.ps1 -Mode WslRuntime -InstallRoot "D:\DATA\3waAIHub" -ModelsRoot "D:\DATA\models" -WslDistro "Ubuntu-24.04" -LinuxDataRoot "/DATA" -Check'
    Write-Host '  .\install.ps1 -Mode NativeAgent -Check'
    Write-Host '  .\install.ps1 -Mode RemoteControlPlane -Check'
}

if ($Help) {
    Show-Usage
    exit 0
}

$scriptRoot = $PSScriptRoot
$checkCore = Join-Path $scriptRoot 'scripts\windows\check-core.ps1'
$installCore = Join-Path $scriptRoot 'scripts\windows\install-core.ps1'
$checkWsl = Join-Path $scriptRoot 'scripts\windows\check-wsl-runtime.ps1'

Write-Host "[3waAIHub] Windows role installer"
Write-Host "[3waAIHub] Mode: $Mode"

switch ($Mode) {
    'Core' {
        & $checkCore -InstallRoot $InstallRoot -ProductType $ProductType
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        if ($Check) {
            if ($InstallIis) {
                Write-Host '[3waAIHub] -InstallIis is ignored during -Check.'
            }
            exit 0
        }

        & $installCore -InstallRoot $InstallRoot -ModelsRoot $ModelsRoot -ProductType $ProductType -InstallIis:$InstallIis -PhpZipUri $PhpZipUri -PhpZipSha256 $PhpZipSha256
        exit $LASTEXITCODE
    }
    'WslRuntime' {
        & $checkWsl -InstallRoot $InstallRoot -ModelsRoot $ModelsRoot -WslDistro $WslDistro -LinuxDataRoot $LinuxDataRoot
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        if ($Check) { exit 0 }

        throw 'WslRuntime install is preview check-only in this build. Run with -Check.'
    }
    'NativeAgent' {
        Write-Host 'Role: Windows Native Agent'
        Write-Host 'Windows Native Agent: NOT INSTALLED'
        Write-Host 'Preview: NativeAgent install is not implemented in this build.'
        if ($Check) { exit 0 }
        throw 'NativeAgent install is not implemented in this build.'
    }
    'RemoteControlPlane' {
        Write-Host 'Role: Remote Linux Agent Control Plane'
        Write-Host 'Remote Linux Agent profile: NOT CONFIGURED'
        Write-Host 'Preview: RemoteControlPlane install is not implemented in this build.'
        if ($Check) { exit 0 }
        throw 'RemoteControlPlane install is not implemented in this build.'
    }
}
