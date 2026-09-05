@echo off
title Maroof Storefront - Local Server
cd /d "%~dp0"
set PATH=C:\Users\rajes\php;%USERPROFILE%\php;%PATH%
node server.js
pause
