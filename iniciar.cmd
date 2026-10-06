@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>&1
if errorlevel 1 (
  echo PHP nao encontrado no PATH.
  pause
  exit /b 1
)
echo.
echo   S1 Disco - servidor local
echo.
echo   Sistema : http://127.0.0.1:8080/S1DISCO/
echo   Login   : http://127.0.0.1:8080/S1DISCO/login.php
echo.
echo   O banco de dados fica na pasta pai ( .. ), em pasta oculta.
echo   Para encerrar o servidor, feche esta janela ou aperte Ctrl+C.
echo.
start "" "http://127.0.0.1:8080/S1DISCO/login.php"
php -S 127.0.0.1:8080 -t "%cd%" "%cd%\roteador.php"