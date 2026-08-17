[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$SourcePath,
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Write-Result {
    param(
        [bool]$Ready,
        [string]$Path,
        [string]$Sha256
    )

    [ordered]@{
        ready = $Ready
        path = $Path
        sha256 = $Sha256
    } | ConvertTo-Json -Compress
}

$resolvedSource = (Resolve-Path -LiteralPath $SourcePath -ErrorAction Stop).Path
$sourceItem = Get-Item -LiteralPath $resolvedSource -Force
if ($sourceItem.PSIsContainer -or $sourceItem.LinkType) {
    throw 'FFprobe source must be a regular file.'
}

$sourceHash = (Get-FileHash -LiteralPath $resolvedSource -Algorithm SHA256).Hash.ToLowerInvariant()
if ($sourceHash -notmatch '^[a-f0-9]{64}$') {
    throw 'Cannot calculate FFprobe SHA-256.'
}

$programData = [Environment]::GetFolderPath('CommonApplicationData').TrimEnd('\\')
if ($programData -notmatch '^[A-Za-z]:\\') {
    throw 'ProgramData path is invalid.'
}

$targetDir = Join-Path $programData '3waAIHub\tools\ffmpeg'
$targetPath = Join-Path $targetDir 'ffprobe.exe'
$hashPath = Join-Path $targetDir 'ffprobe.exe.sha256'

if ($Check) {
    $targetHash = if (Test-Path -LiteralPath $targetPath -PathType Leaf) {
        (Get-FileHash -LiteralPath $targetPath -Algorithm SHA256).Hash.ToLowerInvariant()
    } else {
        ''
    }
    Write-Output (Write-Result -Ready ($targetHash -eq $sourceHash) -Path $targetPath -Sha256 $targetHash)
    exit $(if ($targetHash -eq $sourceHash) { 0 } else { 1 })
}

New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
Copy-Item -LiteralPath $resolvedSource -Destination $targetPath -Force
Set-Content -LiteralPath $hashPath -Value ($sourceHash + '  ffprobe.exe') -Encoding ascii -NoNewline

# 此目錄只存放 Hub 受管媒體工具；禁止一般使用者覆寫，但保留服務與 CLI 的讀取權限。
$acl = Get-Acl -LiteralPath $targetDir
$acl.SetAccessRuleProtection($true, $false)
$inherit = [System.Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
$propagation = [System.Security.AccessControl.PropagationFlags]::None
foreach ($rule in @(
    [System.Security.AccessControl.FileSystemAccessRule]::new('NT AUTHORITY\SYSTEM', 'FullControl', $inherit, $propagation, 'Allow'),
    [System.Security.AccessControl.FileSystemAccessRule]::new('BUILTIN\Administrators', 'FullControl', $inherit, $propagation, 'Allow'),
    [System.Security.AccessControl.FileSystemAccessRule]::new('BUILTIN\Users', 'ReadAndExecute', $inherit, $propagation, 'Allow')
)) {
    [void]$acl.AddAccessRule($rule)
}
Set-Acl -LiteralPath $targetDir -AclObject $acl

$installedHash = (Get-FileHash -LiteralPath $targetPath -Algorithm SHA256).Hash.ToLowerInvariant()
if ($installedHash -ne $sourceHash) {
    throw 'Installed FFprobe SHA-256 verification failed.'
}

Write-Output (Write-Result -Ready $true -Path $targetPath -Sha256 $installedHash)
