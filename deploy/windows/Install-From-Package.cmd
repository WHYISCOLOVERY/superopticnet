@echo off
setlocal
cd /d "%~dp0"

PowerShell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-from-package.ps1" %*
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
  echo.
  echo Paketden qurasdirma ugursuz oldu. Exit code: %RC%
  pause
)
exit /b %RC%
