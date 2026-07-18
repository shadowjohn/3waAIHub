param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$WslDistro = 'Ubuntu-24.04',
    [string]$LinuxDataRoot = '/DATA',
    [ValidateSet('production', 'preview')]
    [string]$SupportLevel = 'preview',
    [switch]$WslReady
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$profile = [ordered]@{
    schema_version = '0.1'
    managed_by = '3waAIHub'
    host_platform = 'windows'
    control_plane = [ordered]@{
        supported = $true
        root = $InstallRoot
    }
    runtime_targets = [ordered]@{
        'windows-native' = [ordered]@{
            supported = $false
            reason = 'Windows Agent not installed'
        }
        'windows-wsl2-linux-docker' = [ordered]@{
            supported = [bool]$WslReady
            support_level = $SupportLevel
            distro = $WslDistro
            data_root = $LinuxDataRoot
            runtime_root = "$LinuxDataRoot/3waAIHub-runtime"
            models_root = "$LinuxDataRoot/models"
            provides = @('linux-docker')
            reason = if ($WslReady) { $null } else { 'WSL Runtime readiness has not passed' }
        }
        'linux-docker' = [ordered]@{
            supported = $false
            reason = 'Direct Linux host target unavailable'
        }
        'remote-linux-agent' = [ordered]@{
            supported = $false
            reason = 'No remote station configured'
        }
    }
}

$dataDir = Join-Path $InstallRoot 'data'
New-Item -ItemType Directory -Force -Path $dataDir | Out-Null
$path = Join-Path $dataDir 'runtime_profile.json'
$temporaryPath = $path + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
$backupPath = $path + '.' + [guid]::NewGuid().ToString('N') + '.bak'
$json = $profile | ConvertTo-Json -Depth 8
try {
    [System.IO.File]::WriteAllText($temporaryPath, $json + "`n", [System.Text.UTF8Encoding]::new($false))
    $validated = Get-Content -LiteralPath $temporaryPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($null -eq $validated -or $null -eq $validated.runtime_targets) {
        throw 'Generated runtime profile is invalid.'
    }
    if (Test-Path -LiteralPath $path) {
        [System.IO.File]::Replace($temporaryPath, $path, $backupPath)
    } else {
        [System.IO.File]::Move($temporaryPath, $path)
    }
} finally {
    if (Test-Path -LiteralPath $temporaryPath) {
        Remove-Item -LiteralPath $temporaryPath -Force
    }
    if (Test-Path -LiteralPath $backupPath) {
        Remove-Item -LiteralPath $backupPath -Force
    }
}
Write-Host "Runtime profile written: $path"
exit 0
