@echo off
setlocal EnableExtensions
rem
rem  xeric.cmd — run a xeric on this machine.
rem
rem    xeric                     start it, and open a browser
rem    xeric --port 9000         somewhere else
rem    xeric --lan              reachable from your phone on the same wifi
rem    xeric --data D:\xerics    keep xerics somewhere else
rem    xeric --no-open           do not touch the browser
rem
rem  EVERY DECISION IS IN bootstrap.php, the same file the bash launcher uses:
rem  which PHP, which extensions are missing, which port is free, where the data
rem  lives. Two copies of a decision is one copy that will be wrong, and it is
rem  always wrong on the platform the author does not use. What is left here is
rem  the part that cannot be shared: starting a background process, and cleaning
rem  it up.
rem
rem  IT ASSUMES php.exe ON PATH. Windows has no package manager anybody agrees
rem  on, so: https://windows.php.net/download — take the thread-safe zip, unzip
rem  it somewhere, add that folder to PATH, and copy php.ini-development to
rem  php.ini with extension=pdo_sqlite, sqlite3, mbstring and zlib uncommented.
rem  bootstrap.php will name whichever of those is missing.
rem

set "HERE=%~dp0"
set "OPEN=1"

rem THE OPTIONS BECOME ENVIRONMENT VARIABLES, which bootstrap.php already reads,
rem rather than a command line it has to build. A path with a space in it inside
rem a `set` inside parentheses inside cmd is three levels of quoting that do not
rem nest, and D:\My Xerics is the ordinary case on Windows, not the exotic one.
:parse
if "%~1"=="" goto parsed
if /I "%~1"=="--no-open" set "OPEN=0" & shift & goto parse
if /I "%~1"=="-h"        goto usage
if /I "%~1"=="--help"    goto usage
if /I "%~1"=="--port"    set "XERIC_PORT=%~2" & shift & shift & goto parse
if /I "%~1"=="--data"    set "XERIC_DATA_DIR=%~2" & shift & shift & goto parse
if /I "%~1"=="--lan"     set "XERIC_BIND=0.0.0.0" & shift & goto parse
echo xeric: unknown option %~1 1>&2
exit /b 2

:usage
echo   xeric                     start it, and open a browser
echo   xeric --port 9000         somewhere else
echo   xeric --lan              reachable from your phone on the same wifi
echo   xeric --data D:\xerics    keep xerics somewhere else
echo   xeric --no-open           do not touch the browser
exit /b 0

:parsed
rem THE BUNDLED PHP FIRST. bootstrap.php IS php, so something has to find an
rem interpreter before it can be asked anything — this is the one lookup that
rem cannot be shared. runtime\ is filled by tools\fetch-php.php and is
rem gitignored; an install that has it needs nothing on PATH.
set "XERIC_PHPEXE="
if exist "%HERE%runtime\windows-x86_64\php.exe" set "XERIC_PHPEXE=%HERE%runtime\windows-x86_64\php.exe"
if not defined XERIC_PHPEXE if exist "%HERE%runtime\php.exe" set "XERIC_PHPEXE=%HERE%runtime\php.exe"
if not defined XERIC_PHPEXE (
  where php.exe >nul 2>&1
  if errorlevel 1 (
    echo xeric: no php here and none on your PATH. 1>&2
    echo         Either install PHP 8.1+ from https://windows.php.net/download, 1>&2
    echo         or run:  php tools\fetch-php.php 1>&2
    exit /b 1
  )
  set "XERIC_PHPEXE=php.exe"
)

rem bootstrap.php prints `set "KEY=VALUE"` lines on stdout and anything it wants
rem to SAY on stderr, so a refusal is already on screen. Its output is executed
rem line by line, which is why nothing else may be printed there.
set "BOOTOK="
for /f "usebackq delims=" %%L in (`""%XERIC_PHPEXE%" "%HERE%bootstrap.php" --cmd"`) do (
  %%L
  set "BOOTOK=1"
)
if not defined BOOTOK exit /b 1

echo   xerics   %XERIC_WORLDS_DIR%
echo   php      %XERIC_PHP%
echo   xeric    %XERIC_URL%

if "%OPEN%"=="1" start "" "%XERIC_URL%"

rem THE HEARTBEAT, in its own minimised window so it can be found and so this one
rem can take it down again. `start /B` would share this console and survive a
rem Ctrl-C aimed at the server, which is the daemon-nobody-started problem the
rem bash launcher's trap exists to avoid.
rem
rem The title is how it is identified at the end: there is no process group to
rem kill on Windows and no trap to hang one on, so the window's name is the
rem handle.
if not "%XERIC_HEART%"=="0" (
  if "%XERIC_HEART_EVERY%"=="" set "XERIC_HEART_EVERY=60"
  start "xeric-heart" /MIN cmd /c ""%HERE%heart.cmd""
  echo   heart    every %XERIC_HEART_EVERY%s  -^>  %XERIC_DATA_DIR%\heart.log
  echo.
)

rem ONE REQUEST AT A TIME. PHP only forks server workers where fork exists, so
rem this is single-request on Windows however many PHP_CLI_SERVER_WORKERS says.
rem bootstrap.php shortens the progress stream here (XERIC_PROGRESS_WINDOW) so it
rem hands the server back every few seconds instead of holding it for a whole
rem build. It is a mitigation and it is on the punchlist as one: the fix is a
rem real server, or polling instead of streaming.
if not "%XERIC_BIND%"=="" if not "%XERIC_BIND%"=="127.0.0.1" (
  echo   ON THE NETWORK: anybody who can reach this machine can open your xerics.
  echo   There is no password on this app - the bind address is the whole guard.
  echo.
)
if "%XERIC_BIND%"=="" set "XERIC_BIND=127.0.0.1"
"%XERIC_PHP%" -S %XERIC_BIND%:%XERIC_PORT% -t "%XERIC_WEB%" "%XERIC_WEB%/router.php"

rem The server has stopped, so the heart should too.
taskkill /FI "WINDOWTITLE eq xeric-heart*" /T /F >nul 2>&1
endlocal
