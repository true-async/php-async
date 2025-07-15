REM Clean release folder if exists
IF EXIST C:\php-release rmdir /S /Q C:\php-release

REM Create release folder
mkdir C:\php-release

REM Copy php.exe
copy C:\obj\Release_TS\php.exe C:\php-release\

REM Copy ini files
copy C:\obj\Release_TS\php.ini-* C:\php-release\

REM Copy all DLLs
xcopy /E /I /H /Y C:\obj\Release_TS\*.dll C:\php-release\

REM Copy ext directory
xcopy /E /I /H /Y C:\obj\Release_TS\ext C:\php-release\ext\