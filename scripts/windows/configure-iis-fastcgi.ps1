param(
    [Parameter(Mandatory = $true)]
    [string]$PhpCgiPath,
    [string]$SiteName = 'Default Web Site',
    [string]$VirtualPath = '/3waAIHub',
    [string]$PhysicalPath = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
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

$PhpCgiPath = (Resolve-Path -LiteralPath $PhpCgiPath -ErrorAction Stop).Path
$PhysicalPath = (Resolve-Path -LiteralPath $PhysicalPath -ErrorAction Stop).Path
if ((Split-Path -Leaf $PhpCgiPath).ToLowerInvariant() -ne 'php-cgi.exe' -or $PhpCgiPath.Contains("'")) {
    throw 'PhpCgiPath must be a safe php-cgi.exe path.'
}
if (-not $VirtualPath.StartsWith('/') -or $VirtualPath -match '[\\/]\.\.?([\\/]|$)' -or $VirtualPath.Contains("'")) {
    throw 'VirtualPath must be a safe IIS application path.'
}

$appcmd = Join-Path $env:WINDIR 'System32\inetsrv\appcmd.exe'
if (-not (Test-Path -LiteralPath $appcmd)) {
    throw 'IIS appcmd.exe is not installed. Run .\install.ps1 -Mode Core -InstallIis from an elevated PowerShell session first.'
}

$iisLocation = $SiteName.TrimEnd('/') + $VirtualPath
Write-Host "IIS location: $iisLocation"
Write-Host "Physical path: $PhysicalPath"
Write-Host "PHP FastCGI: $PhpCgiPath"
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
    Write-Host 'IIS virtual directory: EXISTS'
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
