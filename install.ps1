param(
    [switch]$Check,
    [switch]$Help
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath $PSScriptRoot

function Show-Usage {
    Write-Host "Usage:"
    Write-Host "  .\install.ps1"
    Write-Host "  .\install.ps1 -Check"
}

function Test-Tool {
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
        Write-Host "$Label`: MISSING"
        return $false
    }
}

function Test-PhpSqlite {
    $modules = & php -m
    $hasPdo = $modules -contains "pdo_sqlite"
    $hasSqlite = $modules -contains "sqlite3"
    if ($hasPdo -and $hasSqlite) {
        Write-Host "SQLite extension: OK"
        return $true
    }

    Write-Host "SQLite extension: MISSING"
    return $false
}

function New-RuntimeDirs {
    $dirs = @(
        "data",
        "data/cache",
        "data/uploads",
        "data/results",
        "data/logs",
        "data/logs/jobs",
        "data/logs/install",
        "data/jobs",
        "data/services"
    )

    foreach ($dir in $dirs) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
}

if ($Help) {
    Show-Usage
    exit 0
}

Write-Host "[3waAIHub] Windows Control Plane preview installer"
Write-Host "[3waAIHub] This does not install Docker, NVIDIA, cron, or Linux GPU runtimes."

$phpOk = Test-Tool "PHP" "php" @("-v")
if ($phpOk) {
    $sqliteOk = Test-PhpSqlite
} else {
    $sqliteOk = $false
}
Test-Tool "Docker" "docker" @("--version") | Out-Null
if (Get-Command docker -ErrorAction SilentlyContinue) {
    Test-Tool "Docker Compose" "docker" @("compose", "version") | Out-Null
} else {
    Write-Host "Docker Compose: MISSING"
}

if ($Check) {
    exit 0
}

if (-not $phpOk) {
    throw "PHP not found. Install PHP 8.x and make php.exe available in PATH."
}
if (-not $sqliteOk) {
    throw "PHP SQLite extensions missing. Enable pdo_sqlite and sqlite3."
}

New-RuntimeDirs

Write-Host "[3waAIHub] Initializing SQLite..."
& php scripts/init_db.php

Write-Host "[3waAIHub] Done."
Write-Host "Preview server:"
Write-Host "  php -S 127.0.0.1:8080"
Write-Host "Home URL:"
Write-Host "  http://127.0.0.1:8080/"
Write-Host "Admin URL:"
Write-Host "  http://127.0.0.1:8080/admin/"
Write-Host "Default login: admin / admin123"
