@echo off
setlocal
cd /d "%~dp0"
lemon-print-listener.exe -listen 0.0.0.0:17777
