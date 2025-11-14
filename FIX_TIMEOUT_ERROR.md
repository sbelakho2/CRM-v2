# Fix for "Maximum execution time of 30 seconds exceeded" Error

## Quick Fix

Run these commands in PowerShell **as Administrator**:

```powershell
# Stop the Symfony server first
symfony server:stop

# Update PHP max_execution_time in XAMPP php.ini
(Get-Content C:\xampp\php\php.ini) -replace 'max_execution_time\s*=\s*30', 'max_execution_time = 300' | Set-Content C:\xampp\php\php.ini

# Verify the change
php -i | Select-String "max_execution_time"

# Clear Symfony cache
php bin/console cache:clear

# Restart the Symfony server
symfony serve -d
```

## Alternative: Manual Fix

1. Open `C:\xampp\php\php.ini` in a text editor **as Administrator**
2. Find the line: `max_execution_time = 30`
3. Change it to: `max_execution_time = 300`
4. Save the file
5. Stop and restart the Symfony server:
   ```powershell
   symfony server:stop
   symfony serve -d
   ```

## What This Does

- Increases PHP execution time from 30 seconds to 300 seconds (5 minutes)
- Prevents timeout errors during cache operations and long-running tasks
- Applies to all PHP operations including Symfony commands

## Verify It's Working

After making the change, verify with:

```powershell
php -r "echo ini_get('max_execution_time');"
```

Should output: `300`
