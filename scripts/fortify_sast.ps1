[CmdletBinding()]
param(
    [string]$BuildId = '3waAIHub-production',
    [string]$OutputPath = '',
    [string]$SourceAnalyzerPath = '',
    [string]$RulesPath = '',
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if ($RulesPath -eq '') {
    $RulesPath = Join-Path $repoRoot 'fortify\rules'
}
$rulesDirectory = Test-Path -LiteralPath $RulesPath -PathType Container
$rulesFiles = @()
if ($rulesDirectory) {
    $rulesFiles = @(Get-ChildItem -LiteralPath $RulesPath -File -Include '*.xml','*.bin' | Sort-Object -Property Name | Select-Object -ExpandProperty FullName)
}
$rulesArgs = if ($rulesFiles.Count -gt 0) { @('-rules', $RulesPath) } else { @() }
$sourcePatterns = @(
    (Join-Path $repoRoot '*.php'),
    (Join-Path $repoRoot 'app\**\*.php'),
    (Join-Path $repoRoot 'admin\**\*.php'),
    (Join-Path $repoRoot 'catalog_show\**\*.php'),
    (Join-Path $repoRoot 'scripts\**\*.php'),
    (Join-Path $repoRoot 'i18n\**\*.php'),
    (Join-Path $repoRoot 'packs\**\*.php'),
    (Join-Path $repoRoot 'packs\**\*.py'),
    (Join-Path $repoRoot 'packs\**\*.sh'),
    (Join-Path $repoRoot 'deploy\**\*.ps1'),
    (Join-Path $repoRoot 'assets\js\*.js'),
    (Join-Path $repoRoot 'bin\aihub-run')
)
$excludedPatterns = @(
    (Join-Path $repoRoot '.git\**'),
    (Join-Path $repoRoot '.worktrees\**'),
    (Join-Path $repoRoot 'data\**'),
    (Join-Path $repoRoot 'tests\**'),
    (Join-Path $repoRoot 'docs\**'),
    (Join-Path $repoRoot 'tools\**'),
    (Join-Path $repoRoot 'assets\js\jquery.min.js'),
    (Join-Path $repoRoot 'packs\**\node_modules\**'),
    (Join-Path $repoRoot 'packs\**\.venv\**'),
    (Join-Path $repoRoot 'packs\**\vendor\**'),
    (Join-Path $repoRoot 'packs\**\__pycache__\**')
)

$scope = [ordered]@{
    repo_root = $repoRoot
    include = $sourcePatterns
    exclude = $excludedPatterns
    custom_rules = $rulesFiles
    policy = 'production source plus Pack acceptance/service tests; root test fixtures and historical worktrees are excluded'
}

if ($Check) {
    $scope | ConvertTo-Json -Depth 4
    exit 0
}

if ($SourceAnalyzerPath -eq '') {
    $command = Get-Command sourceanalyzer.exe -ErrorAction SilentlyContinue
    if ($null -ne $command) {
        $SourceAnalyzerPath = $command.Source
    } else {
        $candidate = 'C:\Program Files\Fortify\OpenText_SAST_Fortify_26.2.0\bin\sourceanalyzer.exe'
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            $SourceAnalyzerPath = $candidate
        }
    }
}
if ($SourceAnalyzerPath -eq '' -or -not (Test-Path -LiteralPath $SourceAnalyzerPath -PathType Leaf)) {
    throw 'sourceanalyzer.exe was not found. Set -SourceAnalyzerPath to the installed OpenText SAST executable.'
}

if ($OutputPath -eq '') {
    $OutputPath = Join-Path $repoRoot ('data\fortify\' + $BuildId + '.fpr')
}
$outputDirectory = Split-Path -Parent $OutputPath
if ($outputDirectory -eq '') {
    throw 'OutputPath must include a parent directory.'
}
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null

$excludeArgs = foreach ($pattern in $excludedPatterns) {
    '-exclude'
    $pattern
}

& $SourceAnalyzerPath -b $BuildId -clean
if ($LASTEXITCODE -ne 0) {
    throw "Fortify clean failed for build ID: $BuildId"
}

& $SourceAnalyzerPath -b $BuildId @excludeArgs @sourcePatterns
if ($LASTEXITCODE -ne 0) {
    throw "Fortify translation failed for build ID: $BuildId"
}

$translatedFiles = @(& $SourceAnalyzerPath -b $BuildId -show-files)
if ($LASTEXITCODE -ne 0) {
    throw "Fortify file inventory failed for build ID: $BuildId"
}
$repoRootPattern = [regex]::Escape($repoRoot.TrimEnd([char[]]@([char]'\', [char]'/')))
$rootRelativePattern = '(?i)^(?:' + $repoRootPattern + '[\\/])?'
$forbiddenFiles = @($translatedFiles | Where-Object {
    if ($_ -match '(?i)[\\/]\.worktrees[\\/]') {
        return $true
    }
    if ($_ -match ($rootRelativePattern + 'tests[\\/]')) {
        return $true
    }
    return $_ -match ($rootRelativePattern + 'data[\\/]')
})
if ($forbiddenFiles.Count -gt 0) {
    throw ('Fortify scan scope is contaminated: ' + ($forbiddenFiles -join '; '))
}

& $SourceAnalyzerPath -b $BuildId @rulesArgs -scan -f $OutputPath
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $OutputPath -PathType Leaf)) {
    throw "Fortify scan failed for build ID: $BuildId"
}

$sha256 = [System.Security.Cryptography.SHA256]::Create()
try {
    $stream = [System.IO.File]::OpenRead($OutputPath)
    try {
        $hashBytes = $sha256.ComputeHash($stream)
    } finally {
        $stream.Dispose()
    }
} finally {
    $sha256.Dispose()
}
$hash = -join ($hashBytes | ForEach-Object { $_.ToString('x2') })
Write-Output ('fpr=' + $OutputPath)
Write-Output ('sha256=' + $hash)
