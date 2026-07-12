@echo off
setlocal
cd /d "%~dp0"
lemon-print-listener.exe -mode listener -listen 127.0.0.1:17777
