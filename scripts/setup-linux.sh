#!/usr/bin/env bash
set -euo pipefail

cat <<'EOF'
=== STARZ Morocco CRM :: Linux Setup ===

Run the following commands as root or with sudo privileges.

1. Update packages:
   sudo apt-get update && sudo apt-get upgrade -y

2. Install PHP 8.4 with extensions:
   sudo apt-get install -y php8.4 php8.4-fpm php8.4-cli \
       php8.4-mysql php8.4-intl php8.4-xml php8.4-mbstring \
       php8.4-curl php8.4-zip php8.4-gd unzip git

3. Install Composer globally:
   curl -sS https://getcomposer.org/installer | php
   sudo mv composer.phar /usr/local/bin/composer

4. Install MySQL server:
   sudo apt-get install -y mysql-server
   sudo mysql_secure_installation

5. Configure Nginx (recommended):
   sudo apt-get install -y nginx
   # Copy the vhost template from Documentation/07-Deployment-Operations
   sudo systemctl enable nginx
   sudo systemctl restart nginx

6. Configure PHP-FPM pool limits and restart the service:
   sudo systemctl enable php8.4-fpm
   sudo systemctl restart php8.4-fpm

7. Set permissions after extracting the application:
   sudo chown -R www-data:www-data /var/www/crm-starz-morocco
   sudo find /var/www/crm-starz-morocco/var -type d -exec chmod 775 {} +

Continue with DEPLOYMENT.md for cache warmup, migrations, and cron setup.
EOF
