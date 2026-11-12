@echo off
"C:\xampp\php\php.exe" -d extension=zip "%~dp0vendor\bin\phpunit" --testdox %*
