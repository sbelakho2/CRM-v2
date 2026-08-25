#!/bin/bash
# Launch FR Automotive discovery on VPS
# SSH access matches DEPLOYMENT.md (verified root path).
set -e

SSH="ssh -i ~/.ssh/hetzner-db-mac -o IdentitiesOnly=yes root@77.42.65.89"

echo "Killing any existing discovery..."
$SSH "sudo pkill -f discover-companies 2>/dev/null || true"
sleep 2

echo "Clearing log..."
$SSH "sudo -u www-data truncate -s 0 /var/www/starzcrm/var/discovery/discovery.log"

echo "Launching FR Automotive discovery..."
$SSH "cd /var/www/starzcrm && sudo -u www-data bash -c 'nohup php bin/console app:discover-companies --sector=Automotive --location=France -n -vvv --env=prod > /var/www/starzcrm/var/discovery/discovery.log 2>&1 &'"
sleep 5

echo "Checking process..."
$SSH "ps aux | grep discover-companies | grep -v grep || echo 'NOT RUNNING'"

echo "Checking log..."
$SSH "wc -l /var/www/starzcrm/var/discovery/discovery.log && head -3 /var/www/starzcrm/var/discovery/discovery.log"
