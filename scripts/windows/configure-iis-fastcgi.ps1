param(
    [Parameter(Mandatory = $true)]
    [string]$PhpCgiPath,
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$SiteName = 'Default Web Site',
    [string]$VirtualPath = '/3waAIHub',
    [string]$PhysicalPath,
    [switch]$Check
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-Elevated {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'IIS FastCGI configuration requires an elevated PowerShell session.'
    }
}

function Invoke-AppCmd {
    param([string[]]$Arguments)

    $output = & $script:appcmd @Arguments 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) {
        throw "appcmd failed: $($Arguments -join ' ')`n$output"
    }
    return $output.Trim()
}

function Test-SameWindowsPath {
    param([string]$Left, [string]$Right)

    return [string]::Equals($Left.TrimEnd('\\'), $Right.TrimEnd('\\'), [System.StringComparison]::OrdinalIgnoreCase)
}

function Test-PhpFastCgiConfiguration {
    param([string]$Executable)

    $requiredModules = @('pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'gd', 'fileinfo', 'openssl', 'zip')
    $moduleOutput = @(& $Executable -m 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw ('PHP FastCGI module probe failed: ' + (($moduleOutput -join "`n").Trim()))
    }
    $modules = @($moduleOutput | ForEach-Object { ([string]$_).Trim().ToLowerInvariant() })
    $missingModules = @($requiredModules | Where-Object { $_ -notin $modules })
    if ($missingModules.Count -ne 0) {
        throw ('PHP FastCGI is missing required extensions: ' + ($missingModules -join ', '))
    }

    $infoOutput = @(& $Executable -i 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw ('PHP FastCGI configuration probe failed: ' + (($infoOutput -join "`n").Trim()))
    }
    $info = [regex]::Replace(($infoOutput -join "`n"), '<[^>]+>', ' ')
    if ($info -notmatch '(?is)short_open_tag\s+on\b' -or $info -notmatch '(?is)date\.timezone\s+asia/taipei\b') {
        throw 'PHP FastCGI must set short_open_tag=On and date.timezone=Asia/Taipei.'
    }

    Write-Host 'PHP FastCGI configuration: VERIFIED'
}

$InstallRoot = (Resolve-Path -LiteralPath $InstallRoot -ErrorAction Stop).Path
$PhpCgiPath = (Resolve-Path -LiteralPath $PhpCgiPath -ErrorAction Stop).Path
$releaseRoot = Join-Path $InstallRoot 'dist'
$expectedPhysicalPath = Join-Path $InstallRoot 'dist\public'
if ([string]::IsNullOrWhiteSpace($PhysicalPath)) {
    $PhysicalPath = $expectedPhysicalPath
}
$PhysicalPath = (Resolve-Path -LiteralPath $PhysicalPath -ErrorAction Stop).Path
if ((Split-Path -Leaf $PhpCgiPath).ToLowerInvariant() -ne 'php-cgi.exe' -or $PhpCgiPath.Contains("'")) {
    throw 'PhpCgiPath must be a safe php-cgi.exe path.'
}
Test-PhpFastCgiConfiguration -Executable $PhpCgiPath
if (-not (Test-SameWindowsPath $PhysicalPath $expectedPhysicalPath)) {
    throw "PhysicalPath must be the verified release public root: $expectedPhysicalPath"
}
if (-not $VirtualPath.StartsWith('/') -or $VirtualPath -match '[\\/]\.\.?([\\/]|$)' -or $VirtualPath.Contains("'")) {
    throw 'VirtualPath must be a safe IIS application path.'
}

$releaseBuilder = Join-Path $InstallRoot 'scripts\build_release.php'
$phpCliPath = Join-Path (Split-Path -Parent $PhpCgiPath) 'php.exe'
if (-not (Test-Path -LiteralPath $releaseBuilder) -or -not (Test-Path -LiteralPath $phpCliPath)) {
    throw 'Verified release artifact requires scripts\build_release.php and sibling php.exe.'
}
$releaseCheck = @(& $phpCliPath $releaseBuilder "--output=$releaseRoot" --check 2>&1)
if ($LASTEXITCODE -ne 0) {
    throw ('Release artifact verification failed before IIS configuration: ' + (($releaseCheck -join "`n").Trim()))
}

$appcmd = Join-Path $env:WINDIR 'System32\inetsrv\appcmd.exe'
if (-not (Test-Path -LiteralPath $appcmd)) {
    throw 'IIS appcmd.exe is not installed. Run .\install.ps1 -Mode Core -InstallIis from an elevated PowerShell session first.'
}

$iisLocation = $SiteName.TrimEnd('/') + $VirtualPath
Write-Host "IIS location: $iisLocation"
Write-Host "Physical path: $PhysicalPath"
Write-Host "PHP FastCGI: $PhpCgiPath"
Write-Host 'Release artifact: VERIFIED'
if ($Check) {
    Write-Host 'Status: READY TO CONFIGURE'
    exit 0
}

Assert-Elevated

$site = Invoke-AppCmd @('list', 'site', "/name:$SiteName")
if ($site -eq '') {
    throw "IIS site not found: $SiteName"
}

$vdir = Invoke-AppCmd @('list', 'vdir', "/vdir.name:$iisLocation")
if ($vdir -eq '') {
    Invoke-AppCmd @('add', 'vdir', "/vdir.name:$iisLocation", "/physicalPath:$PhysicalPath") | Out-Null
    Write-Host 'IIS virtual directory: CREATED'
} else {
    Invoke-AppCmd @('set', 'vdir', "/vdir.name:$iisLocation", "/physicalPath:$PhysicalPath") | Out-Null
    Write-Host 'IIS virtual directory: UPDATED'
}

$fastCgi = Invoke-AppCmd @('list', 'config', '-section:system.webServer/fastCgi', '/config:*')
if ($fastCgi -notmatch [regex]::Escape($PhpCgiPath)) {
    Invoke-AppCmd @('set', 'config', '-section:system.webServer/fastCgi', "/+[fullPath='$PhpCgiPath',arguments='']", '/commit:apphost') | Out-Null
    Write-Host 'IIS FastCGI application: REGISTERED'
} else {
    Write-Host 'IIS FastCGI application: EXISTS'
}

$handlerName = '3waAIHub PHP FastCGI'
$handlers = Invoke-AppCmd @('list', 'config', $iisLocation, '-section:system.webServer/handlers', '/config:*')
if ($handlers -notmatch [regex]::Escape($handlerName)) {
    $handler = "/+[name='$handlerName',path='*.php',verb='GET,HEAD,POST',modules='FastCgiModule',scriptProcessor='$PhpCgiPath',resourceType='Either',requireAccess='Script']"
    Invoke-AppCmd @('set', 'config', $iisLocation, '-section:system.webServer/handlers', $handler, '/commit:apphost') | Out-Null
    Write-Host 'IIS PHP handler: REGISTERED'
} else {
    Write-Host 'IIS PHP handler: EXISTS'
}

Write-Host 'Status: CONFIGURED'
