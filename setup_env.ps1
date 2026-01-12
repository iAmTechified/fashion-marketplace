$ErrorActionPreference = "Stop"

$toolsDir = "C:\tools"
$phpDir = "$toolsDir\php"
$phpZipUrl = "https://windows.php.net/downloads/releases/php-8.2.27-Win32-vs16-x64.zip" 
# Falling back to 8.2.27 to be safe, or I can try the 'latest' link if I knew it. 
# Search said 8.2.30 is latest. providing 8.2.30 might be safer if 27 is archived. 
# Let's use 8.2.30 based on search.
$phpZipUrl = "https://windows.php.net/downloads/releases/php-8.2.30-Win32-vs16-x64.zip"

$composerUrl = "https://getcomposer.org/composer.phar"

# Create directories
Write-Host "Creating directories..."
if (!(Test-Path $toolsDir)) { New-Item -ItemType Directory -Path $toolsDir | Out-Null }
if (!(Test-Path $phpDir)) { New-Item -ItemType Directory -Path $phpDir | Out-Null }

# Download PHP
$zipFile = "$toolsDir\php.zip"
Write-Host "Downloading PHP from $phpZipUrl..."
Invoke-WebRequest -Uri $phpZipUrl -OutFile $zipFile

# Extract PHP
Write-Host "Extracting PHP..."
Expand-Archive -Path $zipFile -DestinationPath $phpDir -Force
Remove-Item $zipFile

# Setup php.ini
Write-Host "Configuring php.ini..."
Copy-Item "$phpDir\php.ini-development" "$phpDir\php.ini"

# Enable extensions in php.ini (common ones for Laravel)
$iniFile = "$phpDir\php.ini"
$iniContent = Get-Content $iniFile
$iniContent = $iniContent -replace ";extension_dir = `"ext`"", "extension_dir = `"ext`""
$iniContent = $iniContent -replace ";extension=curl", "extension=curl"
$iniContent = $iniContent -replace ";extension=fileinfo", "extension=fileinfo"
$iniContent = $iniContent -replace ";extension=mbstring", "extension=mbstring"
$iniContent = $iniContent -replace ";extension=openssl", "extension=openssl"
$iniContent = $iniContent -replace ";extension=pdo_mysql", "extension=pdo_mysql"
$iniContent = $iniContent -replace ";extension=pdo_sqlite", "extension=pdo_sqlite"
$iniContent = $iniContent -replace ";extension=sqlite3", "extension=sqlite3"
$iniContent | Set-Content $iniFile

# Download Composer
Write-Host "Downloading Composer..."
Invoke-WebRequest -Uri $composerUrl -OutFile "$phpDir\composer.phar"

# Create composer.bat
Write-Host "Creating composer.bat..."
"@php ""%~dp0composer.phar"" %*" | Set-Content "$phpDir\composer.bat"

# Update PATH for current session
Write-Host "Updating environment variables for this session..."
$env:Path += ";$phpDir"

# Update PATH permanently (User scope)
Write-Host "Updating user PATH permanently..."
$oldPath = [Environment]::GetEnvironmentVariable("Path", "User")
if ($oldPath -notlike "*$phpDir*") {
    [Environment]::SetEnvironmentVariable("Path", "$oldPath;$phpDir", "User")
    Write-Host "PATH updated. You may need to restart your terminal for global changes to take effect."
} else {
    Write-Host "PHP is already in the user PATH."
}

Write-Host "Installation Complete!"
Write-Host "PHP Version:"
php -v
Write-Host "Composer Version:"
call composer -v
