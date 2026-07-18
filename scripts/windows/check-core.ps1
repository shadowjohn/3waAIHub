param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [ValidateSet(0, 1, 2, 3)]
    [int]$ProductType = 0
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Test-CommandOutput {
    param(
        [string]$Label,
        [string]$Command,
        [string[]]$Arguments = @()
    )

    $tool = Get-Command $Command -ErrorAction SilentlyContinue
    if ($null -eq $tool) {
        Write-Host "$Label`: MISSING"
        return $false
    }

    try {
        $output = & $Command @Arguments 2>$null | Select-Object -First 1
        if ([string]::IsNullOrWhiteSpace($output)) {
            Write-Host "$Label`: OK"
        } else {
            Write-Host "$Label`: OK ($output)"
        }
        return $true
    } catch {
        Write-Host "$Label`: ERROR ($($_.Exception.Message))"
        return $false
    }
}

function Get-PhpCommand {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $php) { return $php.Source }
    return $null
}

function Get-WindowsProductType {
    param([int]$Override)

    if ($Override -ne 0) { return $Override }
    try {
        return [int](Get-CimInstance -ClassName Win32_OperatingSystem -Property ProductType).ProductType
    } catch {
        return 0
    }
}

function Invoke-PhpProbe {
    param([string]$PhpExe, [string[]]$Arguments)

    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& $PhpExe @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorAction
    }
    return [pscustomobject]@{ Output = $output; ExitCode = $exitCode }
}

function Test-PhpConfiguration {
    param([string]$PhpExe)

    if ([string]::IsNullOrWhiteSpace($PhpExe) -or -not (Test-Path -LiteralPath $PhpExe)) {
        Write-Host 'PHP: MISSING'
        Write-Host 'PHP configuration: MISSING'
        return $false
    }

    Test-CommandOutput 'PHP' $PhpExe @('-v') | Out-Null
    $moduleProbe = Invoke-PhpProbe $PhpExe @('-m')
    $moduleExitCode = $moduleProbe.ExitCode
    $modules = @($moduleProbe.Output | ForEach-Object { ([string]$_).Trim().ToLowerInvariant() })
    $required = @('pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'gd', 'fileinfo', 'openssl', 'zip')
    $missing = @($required | Where-Object { $_ -notin $modules })
    $timezoneProbe = Invoke-PhpProbe $PhpExe @('-r', "echo ini_get('date.timezone');")
    $timezoneExitCode = $timezoneProbe.ExitCode
    $timezone = ([string]($timezoneProbe.Output -join "`n")).Trim()
    $shortOpenTagProbe = Invoke-PhpProbe $PhpExe @('-r', "echo ini_get('short_open_tag');")
    $shortOpenTagExitCode = $shortOpenTagProbe.ExitCode
    $shortOpenTag = ([string]($shortOpenTagProbe.Output -join "`n")).Trim()

    if ($moduleExitCode -ne 0 -or $timezoneExitCode -ne 0 -or $shortOpenTagExitCode -ne 0 -or $missing.Count -ne 0 -or $timezone -ne 'Asia/Taipei' -or $shortOpenTag -notin @('1', 'On', 'on')) {
        $details = @()
        if ($moduleExitCode -ne 0) { $details += "php -m exit=$moduleExitCode" }
        if ($timezoneExitCode -ne 0) { $details += "date.timezone probe exit=$timezoneExitCode" }
        if ($shortOpenTagExitCode -ne 0) { $details += "short_open_tag probe exit=$shortOpenTagExitCode" }
        if ($missing.Count -ne 0) { $details += 'missing extensions: ' + ($missing -join ', ') }
        if ($timezoneExitCode -eq 0 -and $timezone -ne 'Asia/Taipei') { $details += "date.timezone=$timezone" }
        if ($shortOpenTagExitCode -eq 0 -and $shortOpenTag -notin @('1', 'On', 'on')) { $details += "short_open_tag=$shortOpenTag" }
        Write-Host ('PHP configuration: MISSING (' + ($details -join '; ') + ')')
        return $false
    }

    Write-Host "PHP configuration: OK (Asia/Taipei; short_open_tag=On; extensions=$($required -join ','))"
    return $true
}

Write-Host 'Mode: Core'
Write-Host 'Role: 3waAIHub Core (Control Plane)'
Write-Host 'Check: read-only'
Write-Host "InstallRoot: $InstallRoot"
Write-Host "Windows: $([System.Environment]::OSVersion.VersionString)"

$hostProductType = Get-WindowsProductType $ProductType
if ($hostProductType -eq 1) {
    Write-Host 'Host role: Workstation'
    Write-Host 'Recommended: 3waAIHub Core (Control Plane) + WSL Runtime (Preview)'
} elseif ($hostProductType -in @(2, 3)) {
    Write-Host 'Host role: Server'
    Write-Host 'Default: 3waAIHub Core (Control Plane)'
    Write-Host 'Recommended runtime: Remote Linux Agent'
    Write-Host 'Optional: WSL Runtime (Preview)'
} else {
    Write-Host 'Host role: Unknown'
    Write-Host 'Default: 3waAIHub Core (Control Plane)'
    Write-Host 'Recommended runtime: Remote Linux Agent'
}

$phpExe = Get-PhpCommand
$phpOk = Test-PhpConfiguration $phpExe

$phpCgi = Get-Command php-cgi -ErrorAction SilentlyContinue
$phpCgiOk = $null -ne $phpCgi
if ($null -eq $phpCgi) {
    Write-Host 'PHP FastCGI: MISSING (php-cgi not found; IIS FastCGI setup may need manual PHP package path)'
} else {
    Write-Host "PHP FastCGI: OK ($($phpCgi.Source))"
}

$dataOk = $true
foreach ($dir in @('data', 'data\logs', 'data\jobs', 'data\services')) {
    $path = Join-Path $InstallRoot $dir
    $exists = Test-Path -LiteralPath $path
    if (-not $exists) { $dataOk = $false }
    $state = if ($exists) { 'OK' } else { 'MISSING' }
    Write-Host "$dir`: $state"
}

$iis = Get-Module -ListAvailable -Name WebAdministration
$iisOk = $null -ne $iis
if ($null -eq $iis) {
    Write-Host 'IIS WebAdministration: MISSING (run elevated: .\install.ps1 -Mode Core -InstallIis; php -S remains available)'
} else {
    Write-Host 'IIS WebAdministration: OK'
}

$smokeOk = $false
if ($phpOk) {
    $lintTarget = Join-Path $InstallRoot 'index.php'
    if (Test-Path -LiteralPath $lintTarget) {
        & $phpExe -l $lintTarget *> $null
        if ($LASTEXITCODE -eq 0) {
            Write-Host 'Core smoke: OK'
            $smokeOk = $true
        } else {
            Write-Host 'Core smoke: FAIL'
            exit 1
        }
    } else {
        Write-Host 'Core smoke: MISSING (index.php not found)'
    }
} else {
    Write-Host 'Core smoke: NOT RUN (PHP configuration is not ready)'
}

if ($phpOk -and $phpCgiOk -and $dataOk -and $iisOk -and $smokeOk) {
    Write-Host 'Status: READY'
    Write-Host 'Ready: true'
} else {
    Write-Host 'Status: NOT READY'
    Write-Host 'Ready: false'
}

exit 0
