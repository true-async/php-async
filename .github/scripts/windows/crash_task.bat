@echo off
setlocal EnableDelayedExpansion

if /i "%GITHUB_ACTIONS%" neq "True" (
    echo for CI only
    exit /b 3
)

echo === Crash hunt: %CRASH_TEST% x %CRASH_ITERATIONS% ===

set PHP_BUILD_DIR=C:\obj\Release_TS

if not exist "%PHP_BUILD_DIR%\php.exe" (
    echo ERROR: php.exe not found in %PHP_BUILD_DIR%
    exit /b 1
)

rem Same deps the normal test task copies: php.exe needs them beside itself.
call %~dp0find-target-branch.bat
set DEPS_DIR=%PHP_BUILD_CACHE_BASE_DIR%\deps-%BRANCH%-%PHP_SDK_VS%-%PHP_SDK_ARCH%

if not exist "%DEPS_DIR%\bin" (
    echo ERROR: %DEPS_DIR%\bin not found
    exit /b 1
)

copy /y "%DEPS_DIR%\bin\*.dll" "%PHP_BUILD_DIR%\" >nul

set /a FAILED=0
set /a RUN=0

:loop
set /a RUN+=1
%PHP_BUILD_DIR%\php.exe run-tests.php -q --show-diff %CRASH_TEST%
if %errorlevel% neq 0 (
    set /a FAILED+=1
    echo [run !RUN!] FAIL
) else (
    echo [run !RUN!] pass
)
if !RUN! lss %CRASH_ITERATIONS% goto loop

echo.
echo === %FAILED% failures in %CRASH_ITERATIONS% runs ===

rem Always zero: the job's product is the failure count and the dumps, and a red
rem job would hide them behind a skipped upload.
exit /b 0
