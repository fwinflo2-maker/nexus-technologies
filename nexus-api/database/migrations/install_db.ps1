# =============================================
# Nexus Database Installation Script (PowerShell)
# =============================================
# This script creates the nexus database and loads the schema.
# Requires MySQL client and administrative privileges.

$ErrorActionPreference = "Stop"

# Configuration
$DB_NAME = "nexus"
$DB_USER = "root"
$DB_PASS = ""  # XAMPP default root password is empty
$MYSQL_PATH = "C:\xampp\mysql\bin"

# Colors for output
function Write-Success { param($msg) Write-Host $msg -ForegroundColor Green }
function Write-Error-Custom { param($msg) Write-Host $msg -ForegroundColor Red }
function Write-Info { param($msg) Write-Host $msg -ForegroundColor Cyan }

Write-Host ""
Write-Host "============================================"
Write-Host "Nexus Database Installation (PowerShell)"
Write-Host "============================================"
Write-Host ""

# Step 1: Check prerequisites
Write-Info "Checking prerequisites..."

if (-not (Test-Path "$MYSQL_PATH\mysql.exe")) {
    Write-Error-Custom "MySQL client not found at $MYSQL_PATH"
    Write-Host "Please install XAMPP or adjust MYSQL_PATH variable."
    exit 1
}

# Check if MySQL service is running
$sqlService = Get-Service | Where-Object { $_.Name -eq "mysql" -or $_.Name -eq "mysqld" }
if ($sqlService -and $sqlService.Status -ne "Running") {
    Write-Host "MySQL service detected but not running."
    Write-Host "Attempting to start MySQL service..."
    
    # Try to start via net command
    $startResult = net start mysql 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Could not start MySQL via service command."
        Write-Host "Please start XAMPP Control Panel and click 'START' for MySQL."
        Write-Host "Then run this script again."
    }
} elseif ($sqlService) {
    Write-Success "MySQL service is running."
} else {
    Write-Info "MySQL service check skipped (may not be a service - XAMPP portable mode)."
}

# Step 2: Check database connectivity
Write-Info "Testing database connectivity..."
$testConn = & "$MYSQL_PATH\mysql.exe" -u$DB_USER -p$DB_PASS -e "SELECT 1 as test;" 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Error-Custom "Cannot connect to MySQL server."
    Write-Host "Please ensure MySQL is running and accessible."
    Write-Host "Try starting XAMPP Control Panel and click 'START' for MySQL."
    exit 1
}
Write-Success "Database connectivity OK."

# Step 3: Drop existing database
Write-Info "Dropping database $DB_NAME if exists..."
& "$MYSQL_PATH\mysql.exe" -u$DB_USER -p$DB_PASS -e "DROP DATABASE IF EXISTS $DB_NAME;" 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Error-Custom "Failed to drop database."
    exit 1
}

# Step 4: Create new database with proper charset
Write-Info "Creating database $DB_NAME with utf8mb4 charset..."
& "$MYSQL_PATH\mysql.exe" -u$DB_USER -p$DB_PASS -e "CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) {
    Write-Error-Custom "Failed to create database."
    exit 1
}
Write-Success "Database created successfully."

# Step 5: Load schema
$SCHEMA_FILE = Join-Path $PSScriptRoot "schema.sql"
if (-not (Test-Path $SCHEMA_FILE)) {
    Write-Error-Custom "Schema file not found: $SCHEMA_FILE"
    exit 1
}

Write-Info "Loading schema from $SCHEMA_FILE..."
& "$MYSQL_PATH\mysql.exe" -u$DB_USER -p$DB_PASS $DB_NAME < $SCHEMA_FILE
if ($LASTEXITCODE -ne 0) {
    Write-Error-Custom "Failed to load schema."
    exit 1
}
Write-Success "Schema loaded successfully."

# Step 6: Verify installation
Write-Info "Verifying installation..."
$tableCount = & "$MYSQL_PATH\mysql.exe" -u$DB_USER -p$DB_PASS $DB_NAME -e "SHOW TABLES;" 2>$null | Measure-Object -Line
Write-Success "Installation completed!"
Write-Host ""
Write-Host "============================================"
Write-Host "Summary:"
Write-Host "  Database:     $DB_NAME"
Write-Host "  MySQL Path:   $MYSQL_PATH"
Write-Host "  Tables:       $($tableCount.Count - 1) (minus header)"
Write-Host "  Providers:    Check 'providers' table for 19 seeded records"
Write-Host "============================================"
Write-Host ""

# Continue without pause for non-interactive mode
exit 0