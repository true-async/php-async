@echo off

if /i "%GITHUB_ACTIONS%" neq "True" (
    echo for CI only
    exit /b 3
)

echo === True Async Extension Tests ===

rem Set build directory where PHP was compiled
set PHP_BUILD_DIR=C:\obj\Release_TS

echo Build directory: %PHP_BUILD_DIR%
echo.
echo Checking if php.exe exists:
if exist %PHP_BUILD_DIR%\php.exe (
    echo FOUND: php.exe
) else (
    echo ERROR: php.exe not found!
    exit /b 1
)

echo.
echo Checking if php8ts.dll exists:
if exist %PHP_BUILD_DIR%\php8ts.dll (
    echo FOUND: php8ts.dll
) else (
    echo ERROR: php8ts.dll not found!
    echo Looking for any PHP DLLs:
    dir %PHP_BUILD_DIR%\*.dll | findstr php
    echo Looking in obj directory:
    dir C:\obj\*.dll 2>NUL | findstr php
    exit /b 1
)

echo.
echo Copying deps DLLs like official PHP does...
set DEPS_DIR=C:\build-cache\deps-master-vs17-x64
echo Deps directory: %DEPS_DIR%
echo Available DLLs in deps:
dir %DEPS_DIR%\bin\*.dll | findstr "vcruntime\|php"

echo Copying all deps DLLs to build directory...
copy /-y %DEPS_DIR%\bin\*.dll %PHP_BUILD_DIR%\
echo Copy completed.

echo.
echo Checking if vcruntime140.dll is now present:
if exist %PHP_BUILD_DIR%\vcruntime140.dll (
    echo SUCCESS: vcruntime140.dll found in build directory
) else (
    echo WARNING: vcruntime140.dll still missing, trying system copy...
    copy "C:\Windows\System32\vcruntime140.dll" %PHP_BUILD_DIR%\ >NUL 2>&1
)

echo.
echo Testing PHP executable...
cd /d %PHP_BUILD_DIR%
php.exe --version
set PHP_EXIT_CODE=%errorlevel%

if %PHP_EXIT_CODE% neq 0 (
    echo ERROR: PHP failed to start (exit code: %PHP_EXIT_CODE%)
    
    echo.
    echo === Detailed Diagnostics ===
    
    echo Checking dependencies:
    dumpbin /dependents php.exe | findstr "\.dll"
    
    echo.
    echo Checking which DLLs are actually present:
    for %%i in (php8ts.dll VCRUNTIME140.dll) do (
        if exist %%i (
            echo FOUND: %%i
        ) else (
            echo MISSING: %%i
        )
    )
    
    echo.
    echo Using WHERE command to find missing DLLs:
    where php8ts.dll 2>NUL || echo "php8ts.dll not in PATH"
    where VCRUNTIME140.dll 2>NUL || echo "VCRUNTIME140.dll not in PATH"
    
    echo.
    echo Checking Windows Event Log for application errors:
    powershell -Command "Get-WinEvent -FilterHashtable @{LogName='Application'; Level=2; StartTime=(Get-Date).AddMinutes(-1)} -MaxEvents 2 -ErrorAction SilentlyContinue | ForEach-Object { Write-Host ('Time: ' + $_.TimeCreated + ' - ID: ' + $_.Id + ' - Message: ' + $_.Message.Substring(0, [Math]::Min(200, $_.Message.Length))) }"
    
    echo.
    echo Trying with SysInternals style dependency check:
    powershell -Command "try { [System.Reflection.Assembly]::LoadFile('%CD%\php.exe') } catch { Write-Host 'LoadFile error: ' $_.Exception.Message }"
    
    exit /b 1
)

echo.
echo PHP working! Checking if async extension is loaded...
php.exe -m | findstr async
if %errorlevel% neq 0 (
    echo WARNING: async extension not found in module list
) else (
    echo SUCCESS: async extension is loaded
)

echo.
echo Running async extension tests...
if exist ..\ext\async\tests (
    php.exe run-tests.php --no-progress --show-diff ..\ext\async\tests
) else if exist ext\async\tests (
    php.exe run-tests.php --no-progress --show-diff ext\async\tests
) else (
    echo ERROR: Cannot find async tests directory
    exit /b 1
)

set TEST_EXIT_CODE=%errorlevel%
echo.
echo Tests completed with exit code: %TEST_EXIT_CODE%
exit /b %TEST_EXIT_CODE%