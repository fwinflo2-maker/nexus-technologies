@echo off
REM =============================================
REM Nexus Database Installation Script
REM =============================================
REM This script creates the nexus database and loads the schema.
REM Requires XAMPP MySQL client to be in PATH or adjust MYSQL_PATH below.

set MYSQL_PATH=C:\xampp\mysql\bin
if not exist "%MYSQL_PATH%\mysql.exe" (
    echo MySQL client not found at %MYSQL_PATH%
    echo Please adjust MYSQL_PATH variable in this script.
    exit /b 1
)

set DB_NAME=nexus
set DB_USER=root
set DB_PASS=   REM XAMPP default root password is empty
set SCHEMA_FILE=%~dp0schema.sql

echo.
echo =============================================
echo Nexus Database Installation
echo =============================================
echo.
echo Dropping database %DB_NAME% if exists...
"%MYSQL_PATH%\mysql.exe" -u%DB_USER% -p%DB_PASS% -e "DROP DATABASE IF EXISTS %DB_NAME%;" >nul 2>&1

echo Creating database %DB_NAME%...
"%MYSQL_PATH%\mysql.exe" -u%DB_USER% -p%DB_PASS% -e "CREATE DATABASE %DB_NAME% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >nul 2>&1
if errorlevel 1 (
    echo Failed to create database.
    exit /b 1
)

echo Loading schema from %SCHEMA_FILE%...
"%MYSQL_PATH%\mysql.exe" -u%DB_USER% -p%DB_PASS% %DB_NAME% < "%SCHEMA_FILE%"
if errorlevel 1 (
    echo Failed to load schema.
    exit /b 1
)

echo.
echo Installation completed successfully!
echo.
echo Database: %DB_NAME%
echo User: %DB_USER%
echo.
pause