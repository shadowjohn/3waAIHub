@echo off
setlocal
cd /d "%~dp0"

where php >nul 2>&1
if errorlevel 1 (
    echo [3waAIHub] PHP was not found in PATH.
    echo Run .\install.ps1 first.
    pause
    exit /b 1
)

php -r "if (ini_get('date.timezone') !== 'Asia/Taipei' || !ini_get('short_open_tag')) { exit(1); } foreach (array('pdo_sqlite','sqlite3','curl','mbstring','gd','fileinfo','openssl','zip') as $ext) { if (!extension_loaded($ext)) { exit(1); } }"
if errorlevel 1 (
    echo [3waAIHub] PHP or php.ini is not ready for the control plane.
    echo Run .\install.ps1 to repair it.
    pause
    exit /b 1
)

php -S 127.0.0.1:8080
