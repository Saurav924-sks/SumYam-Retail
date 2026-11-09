
@echo off
title SumYam Retail - Retail Management
color 0A

set "PROJECT_DIR=%~dp0"
cd /d "%PROJECT_DIR%"

echo ==========================================
echo       SUMYAM RETAIL LAUNCHER
echo ==========================================
echo.

echo Starting XAMPP Control Panel...
start "" "C:\xampp\xampp-control.exe"

echo.
echo Please start Apache and MySQL in XAMPP.
pause

echo.
echo Starting SumYam Retail PHP Server on port 8080...

start "SumYam Retail PHP Server" cmd /k ""C:\xampp\php\php.exe" -S localhost:8080 -t "%PROJECT_DIR%""

timeout /t 3 /nobreak >nul

echo.
echo Opening SumYam Retail...
start "" "http://localhost:8080/"

echo.
echo SumYam Retail launched!
pause
