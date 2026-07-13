@echo off
setlocal
if exist "D:\xampp\php\php.exe" (
  "D:\xampp\php\php.exe" %*
  exit /b %ERRORLEVEL%
)

"%~dp0.tools\php\php.exe" %*
