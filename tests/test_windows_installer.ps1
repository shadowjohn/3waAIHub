$ErrorActionPreference = 'Stop'

$installer = Join-Path $PSScriptRoot '..\install.ps1'
$uninstaller = Join-Path $PSScriptRoot '..\uninstall.ps1'
$repo = Resolve-Path (Join-Path $PSScriptRoot '..')
$source = Get-Content -LiteralPath $installer -Raw -Encoding UTF8
$checkCoreSource = Get-Content -LiteralPath (Join-Path $PSScriptRoot '..\scripts\windows\check-core.ps1') -Raw -Encoding UTF8
$checkWslSource = Get-Content -LiteralPath (Join-Path $PSScriptRoot '..\scripts\windows\check-wsl-runtime.ps1') -Raw -Encoding UTF8
$coreSource = Get-Content -LiteralPath (Join-Path $PSScriptRoot '..\scripts\windows\install-core.ps1') -Raw -Encoding UTF8
$initDbSource = Get-Content -LiteralPath (Join-Path $PSScriptRoot '..\scripts\init_db.php') -Raw -Encoding UTF8
$profileWriter = Join-Path $PSScriptRoot '..\scripts\windows\write-runtime-profile.ps1'
$uninstallSource = Get-Content -LiteralPath $uninstaller -Raw -Encoding UTF8

function Assert-InstallerContract {
    param(
        [bool]$Condition,
        [string]$Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

function Invoke-ChildPowerShell {
    param([string[]]$Arguments)

    $engine = (Get-Process -Id $PID).Path
    $scriptPath = $Arguments[0]
    $scriptArguments = @($Arguments | Select-Object -Skip 1)
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $engine -NoProfile -ExecutionPolicy Bypass -File $scriptPath @scriptArguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorAction
    }
    return [pscustomobject]@{
        ExitCode = $exitCode
        Text = ($output -join "`n")
    }
}

Assert-InstallerContract (Test-Path -LiteralPath $uninstaller) 'uninstall.ps1 must exist'
Assert-InstallerContract ($source -match 'ValidateSet') 'installer must validate role modes'
Assert-InstallerContract ($source -match 'WslRuntime') 'installer must expose WslRuntime mode'
Assert-InstallerContract (($source + $checkCoreSource) -match '3waAIHub Core \(Control Plane\)') 'Core role must use the unambiguous Control Plane label'
Assert-InstallerContract (($source + $checkWslSource) -match 'WSL Runtime \(Preview\)') 'WSL role must use the Preview label'
Assert-InstallerContract ($coreSource -match 'date\.timezone') 'Core installer must manage date.timezone'
Assert-InstallerContract ($coreSource -match 'Asia/Taipei') 'Core installer must set Asia/Taipei timezone'
Assert-InstallerContract ($coreSource -match 'short_open_tag') 'Core installer must manage short_open_tag'
Assert-InstallerContract ($coreSource -match 'extension_dir') 'Core installer must point PHP at its bundled extension directory'
Assert-InstallerContract ($coreSource -match 'pdo_sqlite') 'Core installer must require pdo_sqlite'
Assert-InstallerContract ($coreSource -match 'Get-FileHash') 'direct downloads must verify a file hash'
Assert-InstallerContract ($coreSource -match 'SHA256') 'direct downloads must use SHA256'
Assert-InstallerContract ($coreSource -match 'php-8\\\.3.*-nts-Win32-vs16-x64') 'official PHP download must use the NTS x64 FastCGI build'
Assert-InstallerContract ($coreSource -match 'AIHUB_WINDOWS_INSTALLER_TEST_FUNCTIONS_ONLY') 'Core installer must expose the isolated functions-only test hook'
Assert-InstallerContract ($coreSource -notmatch 'LOCALAPPDATA') 'managed PHP must not be installed under the installer user profile'
Assert-InstallerContract ($coreSource -match 'function Get-ManagedPhpInstallDir') 'Core installer must keep managed PHP below InstallRoot'
Assert-InstallerContract ($coreSource -match 'function Resolve-PhpForCore') 'Core installer must select an existing PHP without modifying it'
Assert-InstallerContract ($source -match '-InstallRoot \$InstallRoot -ModelsRoot \$ModelsRoot -ProductType') 'Core must pass ModelsRoot to its installer'
Assert-InstallerContract ($initDbSource -match '--models-root') 'Core DB initialization must accept ModelsRoot'
Assert-InstallerContract ($source -match '\[switch\]\$InstallIis') 'Core installer must expose explicit IIS installation opt-in'
Assert-InstallerContract ($source -match '-InstallIis:\$InstallIis') 'Core installer must forward the IIS opt-in to the Core installer'
Assert-InstallerContract ($coreSource -match 'function Install-IisWebAdministration') 'Core installer must install IIS through a dedicated function'
Assert-InstallerContract ($coreSource -match 'IIS-ManagementScriptingTools') 'Workstation IIS plan must include WebAdministration tooling'
Assert-InstallerContract ($checkCoreSource -match '-InstallIis') 'Core readiness must show the IIS remediation command'
Assert-InstallerContract ($checkCoreSource -match 'Core readiness:') 'Core readiness must be reported independently of IIS'
Assert-InstallerContract ($checkCoreSource -match 'IIS readiness:') 'IIS readiness must be reported independently of Core'
Assert-InstallerContract ($checkWslSource -match 'Get-WslDistroVersion') 'WSL check must parse exact distro rows through a helper'
Assert-InstallerContract ($checkWslSource -match 'Assert-LinuxDataRoot') 'WSL check must validate LinuxDataRoot before invoking WSL'
Assert-InstallerContract ($source -notmatch 'Docker\.DockerDesktop') 'Core installer must not install Docker Desktop'
Assert-InstallerContract (($checkCoreSource + $checkWslSource) -notmatch 'Docker\.DockerDesktop|Enable-WindowsOptionalFeature|Install-WindowsFeature|dism\.exe') 'installer checks must not mutate Windows features or install Docker Desktop'
Assert-InstallerContract ($checkCoreSource -notmatch '\b(New-Item|Set-Content|Add-Content|Copy-Item|Remove-Item)\b') 'Core -Check source must stay read-only'
Assert-InstallerContract ($checkWslSource -notmatch '\b(New-Item|Set-Content|Add-Content|Copy-Item|Remove-Item|Invoke-WebRequest|Start-Process)\b') 'WslRuntime -Check source must stay read-only'

$check = & $installer -Mode Core -Check -InstallRoot $repo -ProductType 1 6>&1 2>&1
$checkText = $check -join "`n"
Assert-InstallerContract ($LASTEXITCODE -eq 0) 'installer -Check must succeed on the current control-plane host'
Assert-InstallerContract ($checkText -match 'Mode: Core') 'Core -Check must report Core mode'
Assert-InstallerContract ($checkText -match 'Role: 3waAIHub Core \(Control Plane\)') 'Core -Check must report the Control Plane role label'
Assert-InstallerContract ($checkText -match 'Host role: Workstation') 'ProductType=1 must report Workstation'
Assert-InstallerContract ($checkText -match 'Recommended: 3waAIHub Core \(Control Plane\) \+ WSL Runtime \(Preview\)') 'Workstation must recommend Core plus WSL Runtime Preview'
Assert-InstallerContract ($checkText -match 'Check: read-only') 'Core -Check must be read-only'
Assert-InstallerContract ($checkText -match 'PHP configuration: OK') 'installer -Check must report PHP configuration readiness'
Assert-InstallerContract ($checkText -notmatch 'Docker Desktop') 'Core -Check must not require Docker Desktop'

$iisCheck = & $installer -Mode Core -Check -InstallIis -InstallRoot $repo -ProductType 1 6>&1 2>&1
$iisCheckText = $iisCheck -join "`n"
Assert-InstallerContract ($LASTEXITCODE -eq 0) 'Core -Check with -InstallIis must remain read-only'
Assert-InstallerContract ($iisCheckText -match '-InstallIis is ignored during -Check') 'Core -Check must explicitly ignore the IIS installation opt-in'

$serverCheck = & $installer -Check -InstallRoot $repo -ProductType 3 6>&1 2>&1
$serverCheckText = $serverCheck -join "`n"
Assert-InstallerContract ($LASTEXITCODE -eq 0) 'Server Core -Check must complete normally'
Assert-InstallerContract ($serverCheckText -match 'Host role: Server') 'ProductType!=1 must report Server'
Assert-InstallerContract ($serverCheckText -match 'Default: 3waAIHub Core \(Control Plane\)') 'Windows Server must default to Core'
Assert-InstallerContract ($serverCheckText -match 'Recommended runtime: Remote Linux Agent') 'Windows Server must recommend Remote Linux Agent'
Assert-InstallerContract ($serverCheckText -match 'Optional: WSL Runtime \(Preview\)') 'Windows Server must keep WSL Runtime optional Preview'
Assert-InstallerContract ($serverCheckText -notmatch 'Docker Desktop') 'Windows Server must not recommend Docker Desktop'

$previousWslExecutable = $env:AIHUB_WSL_EXECUTABLE
$env:AIHUB_WSL_EXECUTABLE = '3waaihub-test-missing-wsl.exe'
try {
    $wslCheck = & $installer -Mode WslRuntime -InstallRoot $repo -ModelsRoot 'D:\DATA\models' -WslDistro 'Ubuntu-24.04' -LinuxDataRoot '/DATA' -Check 6>&1 2>&1
    $wslExitCode = $LASTEXITCODE
} finally {
    if ($null -eq $previousWslExecutable) {
        Remove-Item Env:AIHUB_WSL_EXECUTABLE -ErrorAction SilentlyContinue
    } else {
        $env:AIHUB_WSL_EXECUTABLE = $previousWslExecutable
    }
}
$wslCheckText = $wslCheck -join "`n"
Assert-InstallerContract ($wslExitCode -eq 0) 'WslRuntime -Check must not fail when WSL runtime is not ready'
Assert-InstallerContract ($wslCheckText -match 'Mode: WslRuntime') 'WslRuntime -Check must report WslRuntime mode'
Assert-InstallerContract ($wslCheckText -match 'Role: WSL Runtime \(Preview\)') 'WslRuntime -Check must report the Preview role label'
Assert-InstallerContract ($wslCheckText -match 'Check: read-only') 'WslRuntime -Check must be read-only'
Assert-InstallerContract ($wslCheckText -match 'Ubuntu-24.04') 'WslRuntime -Check must show distro'
Assert-InstallerContract ($wslCheckText -match 'Status: NOT READY') 'missing WSL must report Status: NOT READY'
Assert-InstallerContract ($wslCheckText -match 'Ready: false') 'missing WSL must report Ready: false'

$previousExceptionHook = $env:AIHUB_WINDOWS_INSTALLER_TEST_EXCEPTION
$env:AIHUB_WINDOWS_INSTALLER_TEST_EXCEPTION = '1'
try {
    $exceptionResult = Invoke-ChildPowerShell @($installer, '-Mode', 'WslRuntime', '-Check')
} finally {
    if ($null -eq $previousExceptionHook) {
        Remove-Item Env:AIHUB_WINDOWS_INSTALLER_TEST_EXCEPTION -ErrorAction SilentlyContinue
    } else {
        $env:AIHUB_WINDOWS_INSTALLER_TEST_EXCEPTION = $previousExceptionHook
    }
}
Assert-InstallerContract ($exceptionResult.ExitCode -ne 0) 'WslRuntime script exceptions must exit non-zero'

$profileRoot = Join-Path (Split-Path $repo -Parent) ('3waaihub-installer-profile-' + [guid]::NewGuid().ToString('N'))
try {
    & $profileWriter -InstallRoot $profileRoot -WslDistro 'Ubuntu-24.04' -LinuxDataRoot '/DATA' 6>&1 2>&1 | Out-Null
    Assert-InstallerContract ($LASTEXITCODE -eq 0) 'runtime profile writer must succeed'
    $profilePath = Join-Path $profileRoot 'data\runtime_profile.json'
    $profile = Get-Content -LiteralPath $profilePath -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-InstallerContract (-not $profile.runtime_targets.'windows-wsl2-linux-docker'.supported) 'WSL target must default to unsupported until readiness passes'
    Assert-InstallerContract (-not $profile.runtime_targets.'linux-docker'.supported) 'direct linux-docker must remain unsupported on Windows'
    Assert-InstallerContract ($profile.runtime_targets.'linux-docker'.reason -eq 'Direct Linux host target unavailable') 'direct linux-docker reason must stay stable'
    Assert-InstallerContract (@(Get-ChildItem -LiteralPath (Join-Path $profileRoot 'data') -Filter '*.tmp').Count -eq 0) 'atomic profile writer must not leave temporary files'

    & $profileWriter -InstallRoot $profileRoot -WslDistro 'Ubuntu-24.04' -LinuxDataRoot '/DATA' -WslReady 6>&1 2>&1 | Out-Null
    Assert-InstallerContract ($LASTEXITCODE -eq 0) 'ready runtime profile writer must succeed'
    $readyProfile = Get-Content -LiteralPath $profilePath -Raw -Encoding UTF8 | ConvertFrom-Json
    Assert-InstallerContract ($readyProfile.runtime_targets.'windows-wsl2-linux-docker'.supported) 'WSL target may be supported after readiness passes'
} finally {
    if (Test-Path -LiteralPath $profileRoot) {
        Remove-Item -LiteralPath $profileRoot -Recurse -Force
    }
}

$uninstallCheck = & $uninstaller -Mode Core -InstallRoot $repo -Check 6>&1 2>&1
$uninstallCheckText = $uninstallCheck -join "`n"
Assert-InstallerContract ($LASTEXITCODE -eq 0) 'uninstall -Check must succeed'
Assert-InstallerContract ($uninstallCheckText -match 'Mode: Core') 'uninstall -Check must report mode'
Assert-InstallerContract ($uninstallCheckText -match 'Role: 3waAIHub Core \(Control Plane\)') 'uninstall -Check must use the Core Control Plane label'
Assert-InstallerContract ($uninstallCheckText -match 'No files or services will be removed') 'uninstall -Check must be non-mutating'
Assert-InstallerContract ($uninstallCheckText -match 'Preserve: global PHP, IIS, WSL, NVIDIA driver, project root, SQLite DB, data directory, models') 'uninstall -Check must list preserved global/runtime assets'
Assert-InstallerContract ($uninstallSource -notmatch '\bRemove-Item\b') 'uninstaller must not delete project data or global runtime assets in this build'

$removeModelsResult = Invoke-ChildPowerShell @($uninstaller, '-Mode', 'WslRuntime', '-RemoveModels', '-Check')
Assert-InstallerContract ($removeModelsResult.ExitCode -ne 0) '-RemoveModels without -RemoveRuntimeData must be rejected'

$runServer = Get-Content -LiteralPath (Join-Path $PSScriptRoot '..\run_server.bat') -Raw -Encoding UTF8
Assert-InstallerContract ($runServer -match 'cd /d "%~dp0"') 'run_server.bat must start from the repository root'
Assert-InstallerContract ($runServer -match 'where php') 'run_server.bat must check PHP is available'
Assert-InstallerContract ($runServer -match 'date\.timezone') 'run_server.bat must check the PHP timezone'
Assert-InstallerContract ($runServer -match 'short_open_tag') 'run_server.bat must check short_open_tag'
Assert-InstallerContract ($runServer -match 'extension_loaded') 'run_server.bat must check required PHP extensions'

$runtimeRoot = Join-Path (Split-Path $repo -Parent) ('3waaihub-installer-runtime-' + [guid]::NewGuid().ToString('N'))
$actualUserPathBefore = [Environment]::GetEnvironmentVariable('Path', 'User')
$runtimeEnvironmentNames = @(
    'AIHUB_FAKE_PHP_FAIL',
    'AIHUB_FAKE_PHP_SHORT_TAG',
    'AIHUB_FAKE_PHP_INI',
    'AIHUB_WSL_EXECUTABLE',
    'AIHUB_FAKE_WSL_LOG',
    'AIHUB_FAKE_WSL_MODE',
    'AIHUB_WINDOWS_INSTALLER_TEST_FUNCTIONS_ONLY'
)
$runtimeEnvironmentBefore = @{}
foreach ($name in $runtimeEnvironmentNames) {
    $runtimeEnvironmentBefore[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
New-Item -ItemType Directory -Path $runtimeRoot | Out-Null
try {
    $fakePhpDir = Join-Path $runtimeRoot 'fake-php'
    $fakePhpExt = Join-Path $fakePhpDir 'ext'
    New-Item -ItemType Directory -Path $fakePhpExt | Out-Null
    $fakePhp = Join-Path $fakePhpDir 'php.cmd'
    $fakePhpSource = @'
@echo off
if /i "%~1"=="--ini" goto show_ini
if /i "%~1"=="-v" goto show_version
if /i "%~1"=="-m" goto show_modules
if /i "%~1"=="-r" goto show_setting
exit /b 0

:show_ini
echo Loaded Configuration File: %AIHUB_FAKE_PHP_INI%
exit /b 0

:show_version
echo PHP 8.3 fake
exit /b 0

:show_modules
echo pdo_sqlite
echo sqlite3
echo curl
echo mbstring
echo gd
echo fileinfo
echo openssl
echo zip
if /i "%AIHUB_FAKE_PHP_FAIL%"=="modules" goto fail_modules
exit /b 0

:show_setting
echo "%~2" | findstr /i "date.timezone" >nul
if not errorlevel 1 goto show_timezone
if /i "%AIHUB_FAKE_PHP_SHORT_TAG%"=="on" goto show_short_on
if /i "%AIHUB_FAKE_PHP_FAIL%"=="short" goto fail_short
exit /b 0

:show_short_on
<nul set /p "=1"
if /i "%AIHUB_FAKE_PHP_FAIL%"=="short" goto fail_short
exit /b 0

:show_timezone
<nul set /p "=Asia/Taipei"
if /i "%AIHUB_FAKE_PHP_FAIL%"=="timezone" goto fail_timezone
exit /b 0

:fail_modules
>&2 echo modules probe failed
exit /b 9

:fail_timezone
>&2 echo timezone probe failed
exit /b 9

:fail_short
>&2 echo short_open_tag probe failed
exit /b 9
'@
    [System.IO.File]::WriteAllText($fakePhp, ($fakePhpSource -replace "(?<!`r)`n", "`r`n"), [System.Text.UTF8Encoding]::new($false))

    $coreReadinessRoot = Join-Path $runtimeRoot 'core-ready'
    foreach ($directory in @('', 'data', 'data\logs', 'data\jobs', 'data\services')) {
        New-Item -ItemType Directory -Path (Join-Path $coreReadinessRoot $directory) -Force | Out-Null
    }
    New-Item -ItemType File -Path (Join-Path $coreReadinessRoot 'index.php') -Force | Out-Null

    $previousPath = $env:Path
    $env:Path = $fakePhpDir + ';' + $previousPath
    $env:AIHUB_FAKE_PHP_FAIL = ''
    $env:AIHUB_FAKE_PHP_SHORT_TAG = ''
    try {
        $fakeCore = & (Join-Path $PSScriptRoot '..\scripts\windows\check-core.ps1') -InstallRoot $coreReadinessRoot -ProductType 1 6>&1 2>&1
        $fakeCoreExit = $LASTEXITCODE
    } finally {
        $env:Path = $previousPath
    }
    Assert-InstallerContract ($fakeCoreExit -eq 0) 'empty short_open_tag output must be a normal Core readiness result'
    Assert-InstallerContract (($fakeCore -join "`n") -match 'PHP configuration: MISSING') 'empty short_open_tag output must report PHP configuration MISSING'

    $env:AIHUB_FAKE_PHP_SHORT_TAG = 'on'
    $previousPath = $env:Path
    try {
        $env:Path = $fakePhpDir + ';' + (Join-Path $env:SystemRoot 'System32')
        $coreWithoutFastCgi = & (Join-Path $PSScriptRoot '..\scripts\windows\check-core.ps1') -InstallRoot $coreReadinessRoot -ProductType 1 6>&1 2>&1
        $coreWithoutFastCgiExit = $LASTEXITCODE
    } finally {
        $env:Path = $previousPath
    }
    $coreWithoutFastCgiText = $coreWithoutFastCgi -join "`n"
    Assert-InstallerContract ($coreWithoutFastCgiExit -eq 0) 'Core readiness without IIS FastCGI must remain a normal report'
    Assert-InstallerContract ($coreWithoutFastCgiText -match 'Core readiness: READY') 'Core readiness must not depend on IIS FastCGI'
    Assert-InstallerContract ($coreWithoutFastCgiText -match 'IIS readiness: NOT READY') 'IIS readiness must report the missing FastCGI capability'

    $previousFunctionsOnly = $env:AIHUB_WINDOWS_INSTALLER_TEST_FUNCTIONS_ONLY
    $env:AIHUB_WINDOWS_INSTALLER_TEST_FUNCTIONS_ONLY = '1'
    try {
        . (Join-Path $PSScriptRoot '..\scripts\windows\install-core.ps1') -InstallRoot $runtimeRoot
    } finally {
        if ($null -eq $previousFunctionsOnly) {
            Remove-Item Env:AIHUB_WINDOWS_INSTALLER_TEST_FUNCTIONS_ONLY -ErrorAction SilentlyContinue
        } else {
            $env:AIHUB_WINDOWS_INSTALLER_TEST_FUNCTIONS_ONLY = $previousFunctionsOnly
        }
    }

    $managedPhpDir = Get-ManagedPhpInstallDir
    Assert-InstallerContract ($managedPhpDir -eq (Join-Path $runtimeRoot 'tools\php')) 'managed PHP must live below InstallRoot'

    $workstationIisPlan = Get-IisFeaturePlan 1
    Assert-InstallerContract ($workstationIisPlan.HostKind -eq 'workstation') 'ProductType=1 must use the workstation IIS feature plan'
    Assert-InstallerContract (@($workstationIisPlan.Features) -contains 'IIS-ManagementScriptingTools') 'workstation IIS plan must enable WebAdministration tooling'
    $serverIisPlan = Get-IisFeaturePlan 3
    Assert-InstallerContract ($serverIisPlan.HostKind -eq 'server') 'ProductType=3 must use the server IIS feature plan'
    Assert-InstallerContract (@($serverIisPlan.Features) -contains 'Web-Server') 'server IIS plan must install Web Server'

    $env:AIHUB_FAKE_PHP_SHORT_TAG = ''
    $env:AIHUB_FAKE_PHP_FAIL = ''
    $emptyShortTagThrew = $false
    try {
        $emptyShortTagReady = Test-PhpConfiguration $fakePhp
    } catch {
        $emptyShortTagThrew = $true
    }
    Assert-InstallerContract (-not $emptyShortTagThrew) 'install repair probe must not throw when short_open_tag output is empty'
    Assert-InstallerContract (-not $emptyShortTagReady) 'empty short_open_tag output must require php.ini repair'

    $env:AIHUB_FAKE_PHP_SHORT_TAG = 'on'
    foreach ($failure in @('modules', 'timezone', 'short')) {
        $env:AIHUB_FAKE_PHP_FAIL = $failure
        Assert-InstallerContract (-not (Test-PhpConfiguration $fakePhp)) "PHP $failure probe exit code must make configuration not ready"
    }
    $env:AIHUB_FAKE_PHP_FAIL = ''

    Assert-InstallerContract ((Merge-WindowsPathEntry $null 'D:\Tools\PHP') -eq 'D:\Tools\PHP') 'empty User Path must normalize to the new entry'
    $mergedPath = Merge-WindowsPathEntry 'C:\Windows;;d:\tools\php;' 'D:\Tools\PHP'
    Assert-InstallerContract ($mergedPath -eq 'C:\Windows;d:\tools\php') 'User Path merge must be case-insensitive and omit empty entries'

    $hashFixture = Join-Path $runtimeRoot 'hash-fixture.bin'
    [System.IO.File]::WriteAllText($hashFixture, 'verified payload', [System.Text.UTF8Encoding]::new($false))
    $hashMismatchThrew = $false
    try {
        Assert-FileSha256 $hashFixture ('0' * 64) | Out-Null
    } catch {
        $hashMismatchThrew = $_.Exception.Message -match 'SHA256 mismatch'
    }
    Assert-InstallerContract $hashMismatchThrew 'SHA256 mismatch must fail closed before archive extraction'

    $phpIni = Join-Path $fakePhpDir 'php.ini'
    $env:AIHUB_FAKE_PHP_INI = $phpIni
    $originalIni = ";date.timezone =`r`n;short_open_tag = Off`r`n;extension_dir = `"ext`"`r`n"
    [System.IO.File]::WriteAllText($phpIni, $originalIni, [System.Text.UTF8Encoding]::new($false))
    $managedPhpExe = Join-Path $managedPhpDir 'php.exe'
    New-Item -ItemType Directory -Path $managedPhpDir -Force | Out-Null
    [System.IO.File]::WriteAllBytes($managedPhpExe, [byte[]]@())
    $env:AIHUB_FAKE_PHP_SHORT_TAG = ''
    $previousPath = $env:Path
    try {
        $env:Path = $fakePhpDir + ';' + $previousPath
        $phpResolution = Resolve-PhpForCore
    } finally {
        $env:Path = $previousPath
    }
    Assert-InstallerContract $phpResolution.Managed 'invalid existing PHP must fall back to managed PHP'
    Assert-InstallerContract ($phpResolution.PhpExe -eq $managedPhpExe) 'managed PHP fallback path mismatch'
    Assert-InstallerContract ((Get-Content -LiteralPath $phpIni -Raw -Encoding UTF8) -eq $originalIni) 'existing PHP php.ini must stay unchanged during Core PHP selection'
    Assert-InstallerContract (-not (Test-Path -LiteralPath ($phpIni + '.3waaihub.bak'))) 'existing PHP php.ini must not receive a managed backup'
    foreach ($extension in @('pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'gd', 'fileinfo', 'openssl', 'zip')) {
        [System.IO.File]::WriteAllBytes((Join-Path $fakePhpExt "php_$extension.dll"), [byte[]]@())
    }
    Configure-Php $fakePhp | Out-Null
    $configuredIni = Get-Content -LiteralPath $phpIni -Raw -Encoding UTF8
    $backupIni = Get-Content -LiteralPath ($phpIni + '.3waaihub.bak') -Raw -Encoding UTF8
    Configure-Php $fakePhp | Out-Null
    $configuredIniAgain = Get-Content -LiteralPath $phpIni -Raw -Encoding UTF8
    Assert-InstallerContract ($configuredIniAgain -eq $configuredIni) 'php.ini configuration must be idempotent'
    Assert-InstallerContract ($backupIni -eq $originalIni) 'php.ini backup must preserve the original content'
    Assert-InstallerContract ((Get-Content -LiteralPath ($phpIni + '.3waaihub.bak') -Raw -Encoding UTF8) -eq $backupIni) 'php.ini backup must not be overwritten'

    $fakeWsl = Join-Path $runtimeRoot 'fake-wsl.cmd'
    $fakeWslLog = Join-Path $runtimeRoot 'fake-wsl.log'
    $fakeWslSource = @'
@echo off
if not "%AIHUB_FAKE_WSL_LOG%"=="" >>"%AIHUB_FAKE_WSL_LOG%" echo %~1
if /i "%~1"=="-l" goto list_distros
if /i "%AIHUB_FAKE_WSL_MODE%"=="probe-fail" goto probe_failure
echo "%~6" | findstr /i "findmnt" >nul
if not errorlevel 1 goto show_ext4
echo OK
exit /b 0

:list_distros
if /i "%AIHUB_FAKE_WSL_MODE%"=="prefix" goto prefix_only
echo * Ubuntu ETAT_LOCALISE 2
exit /b 0

:prefix_only
echo Ubuntu-24.04 ETAT_LOCALISE 2
exit /b 0

:show_ext4
echo ext4
exit /b 0

:probe_failure
>&2 echo WSL probe failed
exit /b 7
'@
    [System.IO.File]::WriteAllText($fakeWsl, ($fakeWslSource -replace "(?<!`r)`n", "`r`n"), [System.Text.UTF8Encoding]::new($false))
    $env:AIHUB_WSL_EXECUTABLE = $fakeWsl
    $env:AIHUB_FAKE_WSL_LOG = $fakeWslLog
    $env:AIHUB_FAKE_WSL_MODE = 'prefix'
    $prefixCheck = & $installer -Mode WslRuntime -InstallRoot $repo -WslDistro 'Ubuntu' -LinuxDataRoot '/DATA' -Check 6>&1 2>&1
    Assert-InstallerContract ($LASTEXITCODE -eq 0) 'prefix-only WSL fixture must be a normal not-ready report'
    Assert-InstallerContract (($prefixCheck -join "`n") -match 'Status: NOT READY') 'Ubuntu must not match Ubuntu-24.04 by prefix'
    Assert-InstallerContract ((Get-Content -LiteralPath $fakeWslLog -Raw -Encoding UTF8) -notmatch '(?m)^-d\s*$') 'missing exact distro must stop before distro probes'

    Remove-Item -LiteralPath $fakeWslLog -Force
    $env:AIHUB_FAKE_WSL_MODE = 'exact'
    $localizedCheck = & $installer -Mode WslRuntime -InstallRoot $repo -WslDistro 'Ubuntu' -LinuxDataRoot '/DATA' -Check 6>&1 2>&1
    Assert-InstallerContract ($LASTEXITCODE -eq 0) 'localized WSL fixture must complete normally'
    Assert-InstallerContract (($localizedCheck -join "`n") -match 'Status: READY') 'arbitrary localized status with final version column 2 must be ready'
    Assert-InstallerContract (($localizedCheck -join "`n") -match 'Ready: true') 'ready WSL fixture must emit Ready: true'

    Remove-Item -LiteralPath $fakeWslLog -Force
    $env:AIHUB_FAKE_WSL_MODE = 'probe-fail'
    $failedProbeCheck = & $installer -Mode WslRuntime -InstallRoot $repo -WslDistro 'Ubuntu' -LinuxDataRoot '/DATA' -Check 6>&1 2>&1
    Assert-InstallerContract ($LASTEXITCODE -eq 0) 'failed WSL native probes must remain a normal readiness report'
    Assert-InstallerContract (($failedProbeCheck -join "`n") -match 'Status: NOT READY') 'failed WSL native probes must report not ready'

    foreach ($unsafeRoot in @('/DATA;touch', '/DATA/../models', '/DATA bad', "/DATA'bad")) {
        Remove-Item -LiteralPath $fakeWslLog -Force -ErrorAction SilentlyContinue
        $unsafeResult = Invoke-ChildPowerShell @($installer, '-Mode', 'WslRuntime', '-WslDistro', 'Ubuntu', '-LinuxDataRoot', $unsafeRoot, '-Check')
        Assert-InstallerContract ($unsafeResult.ExitCode -ne 0) "unsafe LinuxDataRoot must exit non-zero: $unsafeRoot"
        Assert-InstallerContract (-not (Test-Path -LiteralPath $fakeWslLog)) "unsafe LinuxDataRoot must be rejected before WSL invocation: $unsafeRoot"
    }

    Assert-InstallerContract ([Environment]::GetEnvironmentVariable('Path', 'User') -eq $actualUserPathBefore) 'installer runtime tests must not write the real User Path'
} finally {
    foreach ($name in $runtimeEnvironmentNames) {
        if ($null -eq $runtimeEnvironmentBefore[$name]) {
            Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue
        } else {
            [Environment]::SetEnvironmentVariable($name, $runtimeEnvironmentBefore[$name], 'Process')
        }
    }
    if (Test-Path -LiteralPath $runtimeRoot) {
        Remove-Item -LiteralPath $runtimeRoot -Recurse -Force
    }
}

foreach ($name in $runtimeEnvironmentNames) {
    $expected = $runtimeEnvironmentBefore[$name]
    $actual = [Environment]::GetEnvironmentVariable($name, 'Process')
    $restored = if ($null -eq $expected) { $null -eq $actual } else { $actual -ceq $expected }
    Assert-InstallerContract $restored "runtime fixture must restore caller environment: $name"
}

Write-Output 'PASS test_windows_installer'
