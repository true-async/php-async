@echo off
setlocal

set BASE_PATH=%~dp0tests
set RUN_TESTS_PATH=%~dp0..\..\run-tests.php
set PHP_EXECUTABLE=%~dp0..\..\x64\Debug_TS\php.exe

if "%~1"=="" (
    set TEST_PATH=%BASE_PATH%
) else (
    set TEST_PATH=%BASE_PATH%\%~1
)

php.exe "%RUN_TESTS_PATH%" --show-diff -p "%PHP_EXECUTABLE%" "%TEST_PATH%"

endlocal