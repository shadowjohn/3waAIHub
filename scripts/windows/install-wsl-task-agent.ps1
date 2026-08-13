param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$WslDistro = 'Ubuntu-24.04',
    [string]$LinuxDataRoot = '/DATA',
    [string]$TaskName = '3waAIHub WSL Runtime Agent'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

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

    $arguments = @('-d', $Distro)
    if ($AsRoot) { $arguments += @('-u', 'root') }
    $arguments += @('--', 'sh', '-lc', $Command)
    $output = & $Wsl @arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw (($output | Out-String).Trim())
    }
    return (($output | Out-String).Trim())
}

function ConvertTo-Base64Utf8 {
    param([Parameter(Mandatory = $true)][string]$Value)

    return [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes(($Value -replace "`r", '')))
}

function Assert-SafeValue {
    param([Parameter(Mandatory = $true)][string]$Value, [Parameter(Mandatory = $true)][string]$Name)

    if ($Value -eq '' -or $Value -match '[\r\n"]') {
        throw "$Name is invalid."
    }
}

if ($WslDistro -notmatch '^[A-Za-z0-9._-]{1,128}$') {
    throw 'WslDistro is invalid.'
}
if ($LinuxDataRoot -eq '/' -or $LinuxDataRoot -notmatch '^/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$') {
    throw 'LinuxDataRoot must be a safe non-root absolute Linux path.'
}
if ($TaskName -notmatch '^[A-Za-z0-9 ._-]{1,128}$') {
    throw 'TaskName is invalid.'
}

$InstallRoot = (Resolve-Path -LiteralPath $InstallRoot).Path.TrimEnd('\')
$wsl = Get-Command wsl.exe -ErrorAction Stop
$php = Get-Command php -ErrorAction Stop
$runnerPath = Join-Path $InstallRoot 'scripts\wsl\aihub-wsl-worker.sh'
$unitTemplatePath = Join-Path $InstallRoot 'deploy\systemd\aihub-wsl-worker.service'
$starterPath = Join-Path $InstallRoot 'scripts\windows\start-wsl-task-agent.ps1'
foreach ($path in @($runnerPath, $unitTemplatePath, $starterPath)) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw "WSL agent source is missing: $path"
    }
}

$runtimeRoot = "$LinuxDataRoot/3waAIHub-runtime"
$sourceRoot = Invoke-WslShell -Wsl $wsl.Source -Distro $WslDistro -Command ('wslpath -a ' + (ConvertTo-LinuxShellLiteral $InstallRoot))
$phpPath = Invoke-WslShell -Wsl $wsl.Source -Distro $WslDistro -Command ('wslpath -a ' + (ConvertTo-LinuxShellLiteral $php.Source))
$wslUser = Invoke-WslShell -Wsl $wsl.Source -Distro $WslDistro -Command 'id -un'
$wslGroup = Invoke-WslShell -Wsl $wsl.Source -Distro $WslDistro -Command 'id -gn'
if ($sourceRoot -eq '' -or $phpPath -eq '' -or $wslUser -notmatch '^[a-z_][a-z0-9_-]{0,31}$' -or $wslGroup -notmatch '^[a-z_][a-z0-9_-]{0,31}$') {
    throw 'The selected WSL distro cannot resolve the agent runtime identity.'
}
foreach ($value in @($runtimeRoot, $sourceRoot, $phpPath, $wslUser, $wslGroup)) {
    Assert-SafeValue -Value $value -Name 'WSL agent value'
}
$runtimeOwner = ConvertTo-LinuxShellLiteral "$wslUser`:$wslGroup"

$runner = Get-Content -LiteralPath $runnerPath -Raw -Encoding UTF8
$unit = (Get-Content -LiteralPath $unitTemplatePath -Raw -Encoding UTF8).
    Replace('__AIHUB_WSL_USER__', $wslUser).
    Replace('__AIHUB_WINDOWS_PHP__', $phpPath).
    Replace('__AIHUB_WINDOWS_HUB_ROOT__', $sourceRoot).
    Replace('__AIHUB_RUNTIME_ROOT__', $runtimeRoot)
$runnerPayload = ConvertTo-Base64Utf8 $runner
$unitPayload = ConvertTo-Base64Utf8 $unit
$installCommand = @"
set -eu
install -d -m 0775 -o $(ConvertTo-LinuxShellLiteral $wslUser) -g $(ConvertTo-LinuxShellLiteral $wslGroup) $(ConvertTo-LinuxShellLiteral "$runtimeRoot/scripts")
install -d -m 0755 /etc/systemd/system
printf %s $(ConvertTo-LinuxShellLiteral $runnerPayload) | base64 -d > $(ConvertTo-LinuxShellLiteral "$runtimeRoot/scripts/aihub-wsl-worker.sh")
chown $runtimeOwner $(ConvertTo-LinuxShellLiteral "$runtimeRoot/scripts/aihub-wsl-worker.sh")
chmod 0755 $(ConvertTo-LinuxShellLiteral "$runtimeRoot/scripts/aihub-wsl-worker.sh")
printf %s $(ConvertTo-LinuxShellLiteral $unitPayload) | base64 -d > /etc/systemd/system/aihub-wsl-worker.service
chmod 0644 /etc/systemd/system/aihub-wsl-worker.service
systemctl daemon-reload
systemctl enable --now aihub-wsl-worker.service
systemctl is-active --quiet aihub-wsl-worker.service
"@
Invoke-WslShell -Wsl $wsl.Source -Distro $WslDistro -AsRoot -Command $installCommand | Out-Null

$sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$powershell = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$arguments = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File &quot;$starterPath&quot; -WslDistro &quot;$WslDistro&quot;"
$taskXml = @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Author>3waAIHub</Author><Description>登入時啟動 3waAIHub WSL Runtime Agent；不保存使用者密碼。</Description></RegistrationInfo>
  <Triggers><LogonTrigger><Enabled>true</Enabled><UserId>$sid</UserId></LogonTrigger></Triggers>
  <Principals><Principal id="Author"><UserId>$sid</UserId><LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><AllowHardTerminate>true</AllowHardTerminate><StartWhenAvailable>true</StartWhenAvailable><AllowStartOnDemand>true</AllowStartOnDemand><Enabled>true</Enabled><Hidden>false</Hidden><ExecutionTimeLimit>PT5M</ExecutionTimeLimit></Settings>
  <Actions Context="Author"><Exec><Command>$powershell</Command><Arguments>$arguments</Arguments><WorkingDirectory>$InstallRoot</WorkingDirectory></Exec></Actions>
</Task>
"@
$document = [xml]$taskXml
Register-ScheduledTask -TaskName $TaskName -Xml $document.OuterXml -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

Write-Host "WSL Runtime Agent: READY ($WslDistro, service=aihub-wsl-worker.service)"
Write-Host "Windows logon launcher: $TaskName"
