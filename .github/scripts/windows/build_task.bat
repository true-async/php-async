@echo off

if /i "%GITHUB_ACTIONS%" neq "True" (
    echo for CI only
    exit /b 3
)

call %~dp0find-target-branch.bat
set STABILITY=staging
set DEPS_DIR=%PHP_BUILD_CACHE_BASE_DIR%\deps-%BRANCH%-%PHP_SDK_VS%-%PHP_SDK_ARCH%
rem SDK is cached, deps info is cached as well
echo Updating dependencies in %DEPS_DIR%
cmd /c phpsdk_deps --update --no-backup --branch %BRANCH% --stability %STABILITY% --deps %DEPS_DIR% --crt %PHP_BUILD_CRT%
if %errorlevel% neq 0 exit /b 3

rem Something went wrong, most likely when concurrent builds were to fetch deps
rem updates. It might be, that some locking mechanism is needed.
if not exist "%DEPS_DIR%" (
	cmd /c phpsdk_deps --update --force --no-backup --branch %BRANCH% --stability %STABILITY% --deps %DEPS_DIR%
)
if %errorlevel% neq 0 exit /b 3

rem Copy LibUV from vcpkg to deps directory
if not exist "%DEPS_DIR%\include\libuv" mkdir "%DEPS_DIR%\include\libuv"
if not exist "%DEPS_DIR%\lib" mkdir "%DEPS_DIR%\lib"
if not exist "%DEPS_DIR%\bin" mkdir "%DEPS_DIR%\bin"
copy "C:\vcpkg\installed\x64-windows\include\uv.h" "%DEPS_DIR%\include\libuv\uv.h"
xcopy /E /I /H /Y "C:\vcpkg\installed\x64-windows\include\uv" "%DEPS_DIR%\include\libuv\uv\"
copy "C:\vcpkg\installed\x64-windows\lib\uv.lib" "%DEPS_DIR%\lib\libuv.lib"
copy "C:\vcpkg\installed\x64-windows\bin\uv.dll" "%DEPS_DIR%\bin\uv.dll"

cmd /c buildconf.bat --force
if %errorlevel% neq 0 exit /b 3

if "%THREAD_SAFE%" equ "0" set ADD_CONF=%ADD_CONF% --disable-zts
if "%INTRINSICS%" neq "" set ADD_CONF=%ADD_CONF% --enable-native-intrinsics=%INTRINSICS%
if "%ASAN%" equ "1" set ADD_CONF=%ADD_CONF% --enable-sanitizer --enable-debug-pack

rem C4018: comparison: signed/unsigned mismatch
rem C4146: unary minus operator applied to unsigned type
rem C4244: type conversion, possible loss of data
rem C4267: 'size_t' type conversion, possible loss of data
set CFLAGS=/W3 /WX /wd4018 /wd4146 /wd4244 /wd4267

cmd /c configure.bat ^
	--enable-snapshot-build ^
	--disable-debug-pack ^
	--without-analyzer ^
	--enable-object-out-dir=%PHP_BUILD_OBJ_DIR% ^
	--with-php-build=%DEPS_DIR% ^
	--enable-async ^
	%ADD_CONF% ^
	--disable-test-ini
if %errorlevel% neq 0 exit /b 3

nmake /NOLOGO
if %errorlevel% neq 0 exit /b 3
nmake /NOLOGO comtest.dll
if %errorlevel% neq 0 exit /b 3

exit /b 0
