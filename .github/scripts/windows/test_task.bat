@echo off

if /i "%GITHUB_ACTIONS%" neq "True" (
    echo for CI only
    exit /b 3
)

echo === True Async Extension Tests ===

rem Set build directory where PHP was compiled
set PHP_BUILD_DIR=C:\obj\Release_TS

echo Build directory: %PHP_BUILD_DIR%
echo Contents:
dir %PHP_BUILD_DIR%\php*.exe
dir %PHP_BUILD_DIR%\php*.dll

echo.
echo Testing PHP executable...
cd /d %PHP_BUILD_DIR%
php.exe --version
set PHP_EXIT_CODE=%errorlevel%

if %PHP_EXIT_CODE% neq 0 (
    echo ERROR: PHP failed to start (exit code: %PHP_EXIT_CODE%)
    echo Checking dependencies:
    dumpbin /dependents php.exe | findstr "\.dll"
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