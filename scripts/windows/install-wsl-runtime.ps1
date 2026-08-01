param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$ModelsRoot = 'D:\DATA\models',
    [string]$WslDistro = 'Ubuntu-24.04',
    [string]$LinuxDataRoot = '/DATA'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'wsl-yolo-runtime-profile.ps1')

function ConvertTo-LinuxShellLiteral {
    param([Parameter(Mandatory = $true)][string]$Value)

    return "'" + $Value.Replace("'", "'`"'`"'") + "'"
}

function Invoke-WslShell {
    param(
        [Parameter(Mandatory = $true)][string]$Wsl,
        [Parameter(Mandatory = $true)][string]$Distro,
        [Parameter(Mandatory = $true)][string]$Command,
        [switch]$AsRoot
    )

    $wslArguments = @('-d', $Distro)
    if ($AsRoot) { $wslArguments += @('-u', 'root') }
    $wslArguments += @('--', 'sh', '-lc', $Command)
    $output = & $Wsl @wslArguments 2>&1
    $exitCode = $LASTEXITCODE
    $output = (($output | Out-String).Trim())
    if ($exitCode -ne 0) {
        throw $output
    }
    return $output
}

function Test-WslCommand {
    param(
        [Parameter(Mandatory = $true)][string]$Wsl,
        [Parameter(Mandatory = $true)][string]$Distro,
        [Parameter(Mandatory = $true)][string]$Command
    )

    & $Wsl -d $Distro -- sh -lc $Command 1>$null 2>$null
    return $LASTEXITCODE -eq 0
}

function Invoke-WslScript {
    param(
        [Parameter(Mandatory = $true)][string]$Wsl,
        [Parameter(Mandatory = $true)][string]$Distro,
        [Parameter(Mandatory = $true)][string]$Script
    )

    $payload = [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes(($Script -replace "`r", '')))
    return Invoke-WslShell -Wsl $Wsl -Distro $Distro -Command ('printf %s ' + (ConvertTo-LinuxShellLiteral $payload) + ' | base64 -d | bash')
}

function Assert-LinuxDataRoot {
    param([string]$Path)

    if ($Path -eq '/' -or $Path -notmatch '^/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$') {
        throw 'LinuxDataRoot must be a safe non-root absolute Linux path.'
    }
}

Assert-LinuxDataRoot $LinuxDataRoot
$InstallRoot = (Resolve-Path -LiteralPath $InstallRoot).Path
$wslCommand = if ([string]::IsNullOrWhiteSpace($env:AIHUB_WSL_EXECUTABLE)) { 'wsl.exe' } else { $env:AIHUB_WSL_EXECUTABLE }
$wsl = Get-Command $wslCommand -ErrorAction Stop

$sourceRoot = Invoke-WslShell -Wsl $wsl.Source -Distro $WslDistro -Command ('wslpath -a ' + (ConvertTo-LinuxShellLiteral $InstallRoot))
if ($sourceRoot -eq '') {
    throw 'Cannot resolve the Windows install root inside the selected WSL distro.'
}

if (-not (Test-WslCommand -Wsl $wsl.Source -Distro $WslDistro -Command 'command -v apt-get')) {
    throw 'The selected WSL distro must provide apt-get to install php-cli.'
}
$hasPhpCli = Test-WslCommand -Wsl $wsl.Source -Distro $WslDistro -Command 'command -v php'
$hasPdoSqlite = Test-WslCommand -Wsl $wsl.Source -Distro $WslDistro -Command "php -m | grep -qi '^pdo_sqlite$'"
if (-not $hasPhpCli -or -not $hasPdoSqlite) {
    Write-Host '[3waAIHub] Installing PHP CLI and SQLite support into the existing WSL distro...'
    Invoke-WslShell -Wsl $wsl.Source -Distro $WslDistro -AsRoot -Command 'DEBIAN_FRONTEND=noninteractive apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y php-cli php-sqlite3' | Out-Null
}

$gpuName = Invoke-WslShell -Wsl $wsl.Source -Distro $WslDistro -Command 'nvidia-smi --query-gpu=name --format=csv,noheader | head -n 1'
if ($gpuName -eq '') {
    throw 'nvidia-smi did not report a GPU in the selected WSL distro.'
}
$profile = Get-WslYoloRuntimeProfile -InstallRoot $InstallRoot -GpuName $gpuName
$runtimeRoot = "$LinuxDataRoot/3waAIHub-runtime"
$runtimeLiteral = ConvertTo-LinuxShellLiteral $runtimeRoot
$sourceLiteral = ConvertTo-LinuxShellLiteral $sourceRoot

$syncCommand = @'
set -eu
runtime_root=__RUNTIME_ROOT__
source_root=__SOURCE_ROOT__
install -d -m 0775 "$runtime_root/app" "$runtime_root/bin" "$runtime_root/packs/hello" "$runtime_root/packs/yolo" "$runtime_root/packs/taiwan-address" "$runtime_root/packs/web-screenshot" "$runtime_root/packs/edge-tts" "$runtime_root/scripts"
cp -a "$source_root/app/." "$runtime_root/app/"
cp -a "$source_root/bin/aihub-run" "$runtime_root/bin/aihub-run"
cp -a "$source_root/scripts/init_db.php" "$runtime_root/scripts/init_db.php"
cp -a "$source_root/packs/hello/." "$runtime_root/packs/hello/"
cp -a "$source_root/packs/yolo/." "$runtime_root/packs/yolo/"
cp -a "$source_root/packs/taiwan-address/." "$runtime_root/packs/taiwan-address/"
cp -a "$source_root/packs/web-screenshot/." "$runtime_root/packs/web-screenshot/"
cp -a "$source_root/packs/edge-tts/." "$runtime_root/packs/edge-tts/"
for script in "$runtime_root"/packs/yolo/jobs/*.sh "$runtime_root"/packs/edge-tts/service/*.sh "$runtime_root"/packs/edge-tts/service/*.py; do
  [ -f "$script" ] || continue
  tr -d '\015' < "$script" > "$script.3waaihub-lf"
  mv "$script.3waaihub-lf" "$script"
  chmod 0755 "$script"
done
chmod 0755 "$runtime_root/bin/aihub-run"
'@.Replace('__RUNTIME_ROOT__', $runtimeLiteral).Replace('__SOURCE_ROOT__', $sourceLiteral)
Invoke-WslScript -Wsl $wsl.Source -Distro $WslDistro -Script $syncCommand | Out-Null

$dockerfile = [string]$profile.dockerfile
if ($dockerfile -notmatch '^service/[A-Za-z0-9._-]+$') {
    throw 'YOLO runtime profile has an invalid Dockerfile path.'
}
$image = [string]$profile.image
$serviceRoot = "$runtimeRoot/packs/yolo/service"
$profileIdLiteral = ConvertTo-LinuxShellLiteral ([string]$profile.id)
$profileLabelFormat = ConvertTo-LinuxShellLiteral '{{ index .Config.Labels "com.3waaihub.yolo.runtime_profile" }}'
$imageLiteral = ConvertTo-LinuxShellLiteral $image
$buildScript = 'if [ "$(docker image inspect --format ' + $profileLabelFormat + ' ' + $imageLiteral + ' 2>/dev/null || true)" != ' + $profileIdLiteral + ' ]; then docker build --progress=quiet -t ' + $imageLiteral + ' -f ' + (ConvertTo-LinuxShellLiteral "$runtimeRoot/packs/yolo/$dockerfile") + ' ' + (ConvertTo-LinuxShellLiteral $serviceRoot) + '; fi'
Invoke-WslScript -Wsl $wsl.Source -Distro $WslDistro -Script $buildScript | Out-Null

$initScript = 'php ' + (ConvertTo-LinuxShellLiteral "$runtimeRoot/scripts/init_db.php") + ' --models-root=' + (ConvertTo-LinuxShellLiteral "$LinuxDataRoot/models")
Invoke-WslScript -Wsl $wsl.Source -Distro $WslDistro -Script $initScript | Out-Null

$profileWriter = Join-Path $PSScriptRoot 'write-runtime-profile.ps1'
& $profileWriter -InstallRoot $InstallRoot -WslDistro $WslDistro -LinuxDataRoot $LinuxDataRoot -WslReady -YoloRuntimeProfile ([string]$profile.id)
if (-not $?) { throw 'Failed to write the WSL runtime profile.' }

Write-Host "WSL runtime installed: $runtimeRoot"
Write-Host "YOLO profile: $($profile.id) ($gpuName)"
Write-Host "YOLO image: $image"
exit 0
