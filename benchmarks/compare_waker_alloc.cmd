@echo off
setlocal

set OLD_PHP=C:\Users\Edmond\AppData\Local\php-trueasync\php.exe
set NEW_PHP=e:\php\php-src\x64\Release_TS\php.exe
set BENCH=e:\php\php-src\ext\async\benchmarks\waker_alloc_benchmark.php

echo ============================================
echo  OLD BUILD (before inline storage)
echo ============================================
"%OLD_PHP%" "%BENCH%"

echo.
echo ============================================
echo  NEW BUILD (with inline storage)
echo ============================================
"%NEW_PHP%" "%BENCH%"

echo.
echo Done. Compare the numbers above.
pause
