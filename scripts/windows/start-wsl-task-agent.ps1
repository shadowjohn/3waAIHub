param(
    [string]$WslDistro = 'Ubuntu-24.04'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($WslDistro -notmatch '^[A-Za-z0-9._-]{1,128}$') {
    throw 'WslDistro is invalid.'
}

$wsl = Get-Command wsl.exe -ErrorAction Stop
& $wsl.Source -d $WslDistro -u root -- systemctl start aihub-wsl-worker.service
exit $LASTEXITCODE
