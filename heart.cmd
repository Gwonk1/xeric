@echo off
rem
rem  heart.cmd — the tick loop, for the Windows launcher.
rem
rem  Its own file rather than a one-liner inside xeric.cmd, because cmd has no
rem  way to background a loop without either sharing this console (start /B, so
rem  Ctrl-C aimed at the server kills the heart too, or does not, depending on
rem  where the keystroke lands) or writing the loop somewhere it can be started
rem  as a window with a name. The name is how xeric.cmd finds it again to stop
rem  it: Windows has no process group to kill and no trap to hang one on.
rem
rem  Every variable it needs is already exported by bootstrap.php through
rem  xeric.cmd, so this inherits and never decides anything.
rem
rem  One pass per tick, not a long-lived PHP process: crash-safe by construction,
rem  and a tick that finds no new hour costs nothing, because a sweep is
rem  idempotent per window.

if "%XERIC_HEART_EVERY%"=="" set "XERIC_HEART_EVERY=60"

:tick
"%XERIC_PHP%" "%XERIC_WEB%/heart.php" >> "%XERIC_DATA_DIR%\heart.log" 2>&1

rem timeout is the sleep everybody has. /nobreak so a stray keypress does not
rem shorten the wait; the redirect keeps its countdown out of the log.
timeout /t %XERIC_HEART_EVERY% /nobreak >nul 2>&1
if errorlevel 1 ping -n %XERIC_HEART_EVERY% 127.0.0.1 >nul 2>&1

goto tick
