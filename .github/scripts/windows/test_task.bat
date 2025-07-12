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
echo Running async extension tests...
%PHP_BUILD_DIR%\php.exe run-tests.php --show-diff ext\async\tests

set TEST_EXIT_CODE=%errorlevel%

echo.
echo Tests completed with exit code: %TEST_EXIT_CODE%
exit /b %TEST_EXIT_CODE%