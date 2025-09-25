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

rem Find target branch like build_task.bat does
call %~dp0find-target-branch.bat
set DEPS_DIR=%PHP_BUILD_CACHE_BASE_DIR%\deps-%BRANCH%-%PHP_SDK_VS%-%PHP_SDK_ARCH%

echo Target branch: %BRANCH%
echo PHP SDK VS: %PHP_SDK_VS%
echo PHP SDK ARCH: %PHP_SDK_ARCH%
echo DEPS_DIR: %DEPS_DIR%

echo Checking if DEPS_DIR exists: %DEPS_DIR%
if exist "%DEPS_DIR%\bin" (
    echo FOUND: %DEPS_DIR%\bin
    dir "%DEPS_DIR%\bin\*.dll" /b
) else (
    echo ERROR: %DEPS_DIR%\bin not found!
    echo Available directories in build cache:
    if exist "%PHP_BUILD_CACHE_BASE_DIR%" (
        dir "%PHP_BUILD_CACHE_BASE_DIR%" /b
    ) else (
        echo Build cache directory doesn't exist: %PHP_BUILD_CACHE_BASE_DIR%
    )
    exit /b 1
)

echo.
echo Copying DLL files...
copy /y "%DEPS_DIR%\bin\*.dll" "%PHP_BUILD_DIR%\"
if %errorlevel% neq 0 (
    echo ERROR: Failed to copy DLL files (exit code: %errorlevel%)
    exit /b 1
) else (
    echo SUCCESS: DLL files copied
)

echo.
echo Running async extension tests...
%PHP_BUILD_DIR%\php.exe run-tests.php --show-diff ext\async\tests

set TEST_EXIT_CODE=%errorlevel%

echo.
echo Tests completed with exit code: %TEST_EXIT_CODE%
exit /b %TEST_EXIT_CODE%