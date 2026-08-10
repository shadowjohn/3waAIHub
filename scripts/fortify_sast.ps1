[CmdletBinding()]
param(
    [string]$BuildId = '3waAIHub-release',
    [string]$OutputPath = '',
    [string]$SourceAnalyzerPath = '',
    [string]$RulesPath = '',
    [string]$ReleaseRoot = '',
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Assert-ReleaseManifest {
    param([string]$Root)

    $manifestPath = Join-Path $Root 'release-manifest.json'
    if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
        throw "Release manifest is missing: $manifestPath. Run php scripts/build_release.php first."
    }
    $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($manifest.schema_version -ne 1 -or $manifest.public_root -ne 'public' -or $null -eq $manifest.files) {
        throw 'Release manifest schema is invalid.'
    }
    foreach ($forbidden in @('data', 'docs', 'fortify', 'tests', 'tools')) {
        if (Test-Path -LiteralPath (Join-Path $Root $forbidden)) {
            throw "Release artifact contains a non-deployable directory: $forbidden"
        }
    }
    foreach ($privatePath in @('public\app', 'public\packs', 'public\scripts')) {
        if (Test-Path -LiteralPath (Join-Path $Root $privatePath)) {
            throw "Release public root contains private source: $privatePath"
        }
    }

    $fileCount = 0
    foreach ($property in $manifest.files.PSObject.Properties) {
        $relativePath = [string]$property.Name
        $expectedHash = ([string]$property.Value).ToLowerInvariant()
        if ($relativePath -match '(^|/)(\.git|\.github|data|docs|fortify|tests|tools|node_modules|\.venv|__pycache__)(/|$)') {
            throw "Release manifest contains a non-deployable path: $relativePath"
        }
        if ($relativePath -match '(^|/)(acceptance|test_[^/]+|[^/]+_test\.py)(/|$)') {
            throw "Release manifest contains Pack acceptance or test source: $relativePath"
        }
        if ($expectedHash -notmatch '^[a-f0-9]{64}$') {
            throw "Release manifest contains an invalid SHA256: $relativePath"
        }
        $path = Join-Path $Root ($relativePath.Replace('/', '\'))
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "Release manifest file is missing: $relativePath"
        }
        $actualHash = ([string](Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash).ToLowerInvariant()
        if ($actualHash -ne $expectedHash) {
            throw "Release manifest SHA256 mismatch: $relativePath"
        }
        $fileCount++
    }
    if ($fileCount -eq 0) {
        throw 'Release manifest has no deployable files.'
    }
    return [pscustomobject]@{ ManifestPath = $manifestPath; FileCount = $fileCount }
}

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if ($ReleaseRoot -eq '') {
    $ReleaseRoot = Join-Path $repoRoot 'dist'
}
if (-not (Test-Path -LiteralPath $ReleaseRoot -PathType Container)) {
    throw "Release root is missing: $ReleaseRoot. Run php scripts/build_release.php first."
}
$releaseRoot = (Resolve-Path -LiteralPath $ReleaseRoot).Path
$release = Assert-ReleaseManifest $releaseRoot

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
    (Join-Path $releaseRoot 'public\*.php'),
    (Join-Path $releaseRoot 'public\admin\**\*.php'),
    (Join-Path $releaseRoot 'public\catalog_show\**\*.php'),
    (Join-Path $releaseRoot 'public\assets\js\*.js'),
    (Join-Path $releaseRoot 'app\**\*.php'),
    (Join-Path $releaseRoot 'i18n\**\*.php'),
    (Join-Path $releaseRoot 'scripts\**\*.php'),
    (Join-Path $releaseRoot 'scripts\**\*.ps1'),
    (Join-Path $releaseRoot 'packs\**\*.php'),
    (Join-Path $releaseRoot 'packs\**\*.py'),
    (Join-Path $releaseRoot 'packs\**\*.sh'),
    (Join-Path $releaseRoot 'crontab\**\*.sh'),
    (Join-Path $releaseRoot 'install.ps1'),
    (Join-Path $releaseRoot 'install.sh'),
    (Join-Path $releaseRoot 'bin\aihub-run')
)
$excludedPatterns = @(
    (Join-Path $releaseRoot 'public\assets\js\vendor\**'),
    (Join-Path $releaseRoot 'public\assets\js\jquery.min.js'),
    (Join-Path $releaseRoot 'packs\**\node_modules\**'),
    (Join-Path $releaseRoot 'packs\**\.venv\**'),
    (Join-Path $releaseRoot 'packs\**\vendor\**'),
    (Join-Path $releaseRoot 'packs\**\__pycache__\**')
)
$scope = [ordered]@{
    release_root = $releaseRoot
    public_root = (Join-Path $releaseRoot 'public')
    manifest = $release.ManifestPath
    manifest_files = $release.FileCount
    include = $sourcePatterns
    exclude = $excludedPatterns
    custom_rules = $rulesFiles
    policy = 'verified deploy artifact; includes private Control Plane and shipped Pack runtime, excludes tests, acceptance, docs, data, and static vendor JavaScript'
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
$releaseRootPattern = [regex]::Escape($releaseRoot.TrimEnd([char[]]@([char]'\', [char]'/')))
$rootRelativePattern = '(?i)^(?:' + $releaseRootPattern + '[\\/])?'
$forbiddenFiles = @($translatedFiles | Where-Object {
    $_ -match ($rootRelativePattern + '(?:data|docs|fortify|tests|tools)[\\/]') -or
        $_ -match '(?i)[\\/](?:acceptance|tests|node_modules|vendor|\.venv|__pycache__)[\\/]'
})
if ($forbiddenFiles.Count -gt 0) {
    throw ('Fortify scan scope is contaminated: ' + ($forbiddenFiles -join '; '))
}

& $SourceAnalyzerPath -b $BuildId @rulesArgs -scan -f $OutputPath
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $OutputPath -PathType Leaf)) {
    throw "Fortify scan failed for build ID: $BuildId"
}
$hash = ([string](Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash).ToLowerInvariant()
Write-Output ('fpr=' + $OutputPath)
Write-Output ('sha256=' + $hash)
