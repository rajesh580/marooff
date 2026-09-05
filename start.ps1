$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $scriptDir
$env:Path = "C:\Users\rajes\php;$env:USERPROFILE\php;" + $env:Path
node server.js
