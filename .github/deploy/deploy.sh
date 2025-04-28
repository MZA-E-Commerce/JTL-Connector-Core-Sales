#!/usr/bin/env bash
set -euo pipefail

# Change directory
cd /home/www/p689712/html/jtl-connector-pimcore

# Reset to main
git fetch --all
git reset --hard origin/main

# Composer install
composer install --no-dev --optimize-autoloader

# Clear cache
php bin/console cache:clear --env=prod

# Restart services
#sudo systemctl reload php8.2-fpm
#sudo systemctl reload nginx