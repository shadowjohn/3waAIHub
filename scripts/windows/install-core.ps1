param(
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$PhpZipUri,
    [string]$PhpZipSha256
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-InstallLog {
    param([string]$Message)

    $logDir = Join-Path $InstallRoot 'data\logs\install'
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    Add-Content -LiteralPath (Join-Path $logDir 'windows_installer.log') -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $Message" -Encoding utf8
}

function Get-PhpCommand {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $php) { return $php.Source }
    return $null
}

function Get-OfficialPhpDownload {
    $page = Invoke-WebRequest -UseBasicParsing -Uri 'https://www.php.net/downloads.php?os=windows&version=8.3'
    $match = [regex]::Match(
        $page.Content,
        '(?s)<h3>PHP 8\.3 \((?<version>[^)]+)\)</h3>.*?href="(?<uri>https://downloads\.php\.net/[^"]+/php-8\.3\.\d+-nts-Win32-vs16-x64\.zip)".*?<span class="sha256"><strong>sha256:</strong>\s*(?<sha256>[a-fA-F0-9]{64})'
    )
    if (-not $match.Success) {
        throw 'Cannot find the official PHP 8.3 NTS x64 download and SHA256.'
    }

    return [pscustomobject]@{
        Version = $match.Groups['version'].Value
        Uri = $match.Groups['uri'].Value
        Sha256 = $match.Groups['sha256'].Value.ToLowerInvariant()
    }
}

function Merge-WindowsPathEntry {
    param(
        [AllowNull()][string]$CurrentPath,
        [string]$Entry
    )

    $entries = @(([string]$CurrentPath -split ';') | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
    $exists = @($entries | Where-Object { [string]::Equals($_, $Entry, [System.StringComparison]::OrdinalIgnoreCase) }).Count -ne 0
    if (-not $exists) { $entries += $Entry }
    return $entries -join ';'
}

function Assert-FileSha256 {
    param([string]$Path, [string]$ExpectedSha256)

    $actualSha256 = ([string](Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash).ToLowerInvariant()
    if ($actualSha256 -ne $ExpectedSha256.ToLowerInvariant()) {
        $exception = [System.IO.InvalidDataException]::new('PHP download SHA256 mismatch. Installation stopped.')
        $exception.Data['ActualSha256'] = $actualSha256
        throw $exception
    }
    return $actualSha256
}

function Install-Php {
    $download = if ($PhpZipUri -or $PhpZipSha256) {
        if (-not $PhpZipUri -or $PhpZipSha256 -notmatch '^[a-fA-F0-9]{64}$') {
            throw 'PhpZipUri and a 64-character PhpZipSha256 are required together.'
        }
        [pscustomobject]@{ Version = 'custom'; Uri = $PhpZipUri; Sha256 = $PhpZipSha256.ToLowerInvariant() }
    } else {
        Get-OfficialPhpDownload
    }

    $cacheDir = Join-Path $InstallRoot 'data\cache\install'
    $installDir = Join-Path $env:LOCALAPPDATA "3waAIHub\tools\php-$($download.Version)"
    $zipPath = Join-Path $cacheDir "php-$($download.Version)-windows-x64.zip"
    New-Item -ItemType Directory -Force -Path $cacheDir | Out-Null

    Write-Host "[3waAIHub] Downloading PHP $($download.Version) from the official PHP Windows release site..."
    Invoke-WebRequest -UseBasicParsing -Uri $download.Uri -OutFile $zipPath
    try {
        $actualSha256 = Assert-FileSha256 $zipPath $download.Sha256
    } catch {
        $actualSha256 = [string]$_.Exception.Data['ActualSha256']
        Write-InstallLog "php_download status=hash_mismatch source=$($download.Uri) expected_sha256=$($download.Sha256) actual_sha256=$actualSha256"
        throw
    }

    New-Item -ItemType Directory -Force -Path $installDir | Out-Null
    Expand-Archive -LiteralPath $zipPath -DestinationPath $installDir -Force
    $phpExe = Join-Path $installDir 'php.exe'
    if (-not (Test-Path -LiteralPath $phpExe)) {
        throw 'PHP archive did not contain php.exe.'
    }

    $userPath = [string][Environment]::GetEnvironmentVariable('Path', 'User')
    $mergedUserPath = Merge-WindowsPathEntry $userPath $installDir
    if (-not [string]::Equals($userPath, $mergedUserPath, [System.StringComparison]::Ordinal)) {
        [Environment]::SetEnvironmentVariable('Path', $mergedUserPath, 'User')
    }
    $env:Path = Merge-WindowsPathEntry ([string]$env:Path) $installDir
    Write-InstallLog "php_download status=verified source=$($download.Uri) sha256=$actualSha256 install_dir=$installDir"
    return $phpExe
}

function Get-PhpIniPath {
    param([string]$PhpExe)

    $iniOutput = & $PhpExe --ini 2>&1
    $loaded = $iniOutput | Where-Object { $_ -match '^Loaded Configuration File:\s*(.+)$' } | Select-Object -First 1
    if ($loaded -and $loaded -match '^Loaded Configuration File:\s*(.+)$') {
        $path = $Matches[1].Trim()
        if ($path -and $path -ne '(none)' -and (Test-Path -LiteralPath $path)) {
            return $path
        }
    }

    $phpDir = Split-Path -Parent $PhpExe
    $phpIni = Join-Path $phpDir 'php.ini'
    if (-not (Test-Path -LiteralPath $phpIni)) {
        $template = Join-Path $phpDir 'php.ini-development'
        if (-not (Test-Path -LiteralPath $template)) {
            throw 'PHP has no loaded php.ini or php.ini-development template.'
        }
        Copy-Item -LiteralPath $template -Destination $phpIni
    }
    return $phpIni
}

function Set-PhpIniDirective {
    param([string]$Content, [string]$Name, [string]$Value)

    $pattern = "(?mi)^[ \t]*;?[ \t]*$([regex]::Escape($Name))[ \t]*=[^\r\n]*\r?$"
    $line = "$Name = $Value"
    if ([regex]::IsMatch($Content, $pattern)) {
        return [regex]::Replace($Content, $pattern, $line + "`r", 1)
    }

    return $Content.TrimEnd() + "`r`n$line`r`n"
}

function Enable-PhpExtension {
    param([string]$Content, [string]$Extension)

    $pattern = "(?mi)^[ \t]*;?[ \t]*extension[ \t]*=[ \t]*(?:php_)?$([regex]::Escape($Extension))(?:\.dll)?[ \t]*(?:;.*)?\r?$"
    $line = "extension=$Extension"
    if ([regex]::IsMatch($Content, $pattern)) {
        return [regex]::Replace($Content, $pattern, $line + "`r", 1)
    }

    return $Content.TrimEnd() + "`r`n$line`r`n"
}

function Configure-Php {
    param([string]$PhpExe)

    $phpIni = Get-PhpIniPath $PhpExe
    $content = Get-Content -LiteralPath $phpIni -Raw -Encoding UTF8
    $updated = Set-PhpIniDirective $content 'date.timezone' 'Asia/Taipei'
    $updated = Set-PhpIniDirective $updated 'short_open_tag' 'On'
    $updated = Set-PhpIniDirective $updated 'extension_dir' '"ext"'
    $phpDir = Split-Path -Parent $PhpExe
    foreach ($extension in @('pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'gd', 'fileinfo', 'openssl', 'zip')) {
        if (-not (Test-Path -LiteralPath (Join-Path $phpDir "ext\php_$extension.dll"))) {
            throw "Required PHP extension DLL is missing: php_$extension.dll"
        }
        $updated = Enable-PhpExtension $updated $extension
    }

    if ($updated -ne $content) {
        $backup = $phpIni + '.3waaihub.bak'
        if (-not (Test-Path -LiteralPath $backup)) {
            Copy-Item -LiteralPath $phpIni -Destination $backup
        }
        [System.IO.File]::WriteAllText($phpIni, $updated, [System.Text.UTF8Encoding]::new($false))
        Write-InstallLog "php_ini status=updated path=$phpIni backup=$backup"
    }

    return $phpIni
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
        Write-Host 'PHP configuration: MISSING'
        return $false
    }

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

function New-RuntimeDirs {
    foreach ($dir in @(
        'data',
        'data/cache',
        'data/uploads',
        'data/results',
        'data/logs',
        'data/logs/jobs',
        'data/logs/install',
        'data/jobs',
        'data/services'
    )) {
        New-Item -ItemType Directory -Force -Path (Join-Path $InstallRoot $dir) | Out-Null
    }
}

if ($env:AIHUB_WINDOWS_INSTALLER_TEST_FUNCTIONS_ONLY -eq '1') {
    return
}

Set-Location -LiteralPath $InstallRoot

Write-Host '[3waAIHub] Installing Windows Core control plane'
Write-Host '[3waAIHub] Role: 3waAIHub Core (Control Plane)'
Write-Host '[3waAIHub] Core does not install container runtimes, WSL, or NVIDIA drivers.'

$phpExe = Get-PhpCommand
if ($null -eq $phpExe) {
    $phpExe = Install-Php
    Configure-Php $phpExe | Out-Null
} elseif (-not (Test-PhpConfiguration $phpExe)) {
    Configure-Php $phpExe | Out-Null
}

if (-not (Test-PhpConfiguration $phpExe)) {
    throw 'PHP configuration is incomplete after installation.'
}

New-RuntimeDirs

Write-Host '[3waAIHub] Initializing SQLite...'
& $phpExe scripts/init_db.php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host '[3waAIHub] Done.'
Write-Host 'Preview server:'
Write-Host '  php -S 127.0.0.1:8080'
Write-Host 'Home URL:'
Write-Host '  http://127.0.0.1:8080/'
Write-Host 'Admin URL:'
Write-Host '  http://127.0.0.1:8080/admin/'
Write-Host 'Default login: admin / admin123'
