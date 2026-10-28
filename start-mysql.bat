@echo off
rem ============================================================
rem  Business Suite - MySQL Starter
rem  Double-click this file before opening the system.
rem  Starts MariaDB (port 3306) if it is not already running.
rem ============================================================
set MYSQLDIR=C:\xampp\mysql\bin

rem Kill any stuck mysqld processes that are not serving connections
for /f "tokens=5" %%p in ('netstat -ano ^| findstr "LISTENING" ^| findstr ":3306"') do set PORT_PID=%%p
if defined PORT_PID (
    echo MySQL is already running on port 3306. No action needed.
    timeout /t 2 >nul
    exit /b 0
)

echo Starting MySQL (MariaDB) on port 3306 ...
start "MySQL" /MIN "%MYSQLDIR%\mysqld.exe" --defaults-file=c:\xampp\mysql\bin\my.ini
timeout /t 6 >nul

"%MYSQLDIR%\mysql.exe" -h 127.0.0.1 -P 3306 -u root -e "SELECT 'MySQL is ready' AS status;" >nul 2>&1
if %errorlevel%==0 (
    echo MySQL started successfully.
) else (
    echo WARNING: Could not confirm MySQL connection. Wait a few seconds and retry.
)
timeout /t 3 >nul
