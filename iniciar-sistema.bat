@echo off
title Sistema de Facturacion Electronica UBL 2.1 + PHPUnit
echo ============================================================================
echo   INICIANDO BACKEND PHP 8.2 (Puerto 8000) Y FRONTEND REACT (Puerto 5173)
echo ============================================================================
start "Backend PHP 8.2 API" "C:\xampp\php\php.exe" -d extension=zip -S 127.0.0.1:8000 -t "%~dp0backend\public"
cd /d "%~dp0frontend"
call npm run dev
