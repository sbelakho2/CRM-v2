#!/bin/bash
# Launch FR Automotive discovery on the production VPS.
# Host/user/key are provided by the ops secret store (see DEPLOYMENT.md):
#   PRODUCTION_HOST, PRODUCTION_USER, SSH_KEY
set -e

: "${PRODUCTION_HOST:?export PRODUCTION_HOST (ops secret store)}"
: "${PRODUCTION_USER:?export PRODUCTION_USER (ops secret store)}"
: "${SSH_KEY:?export SSH_KEY (ops secret store)}"

ssh_cmd() {
  ssh -i "$SSH_KEY" -o IdentitiesOnly=yes "$PRODUCTION_USER@$PRODUCTION_HOST" "$@"
}

echo "Killing any existing discovery..."
ssh_cmd "sudo pkill -f discover-companies 2>/dev/null || true"
sleep 2

echo "Clearing log..."
ssh_cmd "sudo -u www-data truncate -s 0 /var/www/starzcrm/var/discovery/discovery.log"

echo "Launching FR Automotive discovery..."
ssh_cmd "cd /var/www/starzcrm && sudo -u www-data bash -c 'nohup php bin/console app:discover-companies --sector=Automotive --location=France -n -vvv --env=prod > /var/www/starzcrm/var/discovery/discovery.log 2>&1 &'"
sleep 5

echo "Checking process..."
ssh_cmd "ps aux | grep discover-companies | grep -v grep || echo 'NOT RUNNING'"

echo "Checking log..."
ssh_cmd "wc -l /var/www/starzcrm/var/discovery/discovery.log && head -3 /var/www/starzcrm/var/discovery/discovery.log"
