@echo off
setlocal EnableDelayedExpansion

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
if exist "%PHP_BUILD_DIR%\php.exe" (
    echo FOUND: php.exe
) else (
    echo ERROR: php.exe not found!
    exit /b 1
)

echo.
echo Copying deps DLLs like official PHP does...
set DEPS_DIR=C:\build-cache\deps-master-vs17-x64
copy /-y %DEPS_DIR%\bin\*.dll %PHP_BUILD_DIR%\*

echo.
echo Testing PHP executable...
cd /d %PHP_BUILD_DIR%

echo Testing PHP directly...
php.exe --version
set PHP_EXIT_CODE=%errorlevel%

echo Exit code = %PHP_EXIT_CODE%
if not "%PHP_EXIT_CODE%"=="0" (
    echo ERROR: PHP failed to start (exit code: %PHP_EXIT_CODE%)
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
echo Current directory: %CD%
echo Checking for test directories:
if exist ..\ext\async\tests (
    echo FOUND: ..\ext\async\tests
    echo Running tests from ..\ext\async\tests
    php.exe run-tests.php --no-progress --show-diff ..\ext\async\tests
) else if exist ext\async\tests (
    echo FOUND: ext\async\tests  
    echo Running tests from ext\async\tests
    php.exe run-tests.php --no-progress --show-diff ext\async\tests
) else (
    echo ERROR: Cannot find async tests directory
    echo Looking for test directories:
    dir ..\ext\async\ 2>NUL
    dir ext\async\ 2>NUL
    dir .\ext\async\ 2>NUL
    exit /b 1
)

set TEST_EXIT_CODE=%errorlevel%

echo.
echo Tests completed with exit code: %TEST_EXIT_CODE%
exit /b %TEST_EXIT_CODE%