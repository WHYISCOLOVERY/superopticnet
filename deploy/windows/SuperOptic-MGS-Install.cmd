@echo off
setlocal
cd /d "%~dp0"

set "SCRIPT=%~dp0install-superoptic-mgs.ps1"
if not exist "%SCRIPT%" (
  echo install-superoptic-mgs.ps1 tapilmadi.
  pause
  exit /b 1
)

PowerShell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" %*
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
  echo.
  echo Qurasdirma ugursuz oldu. Exit code: %RC%
  pause
)
exit /b %RC%
