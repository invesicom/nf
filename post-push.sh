#!/bin/sh

php_cmd="/usr/bin/php82"

${php_cmd} /usr/local/bin/composer dump-autoload --no-interaction
${php_cmd} /usr/local/bin/composer install --no-interaction --no-dev --optimize-autoloader
${php_cmd} artisan config:clear
${php_cmd} artisan cache:clear
${php_cmd} artisan route:clear
${php_cmd} artisan view:clear
rm -rf bootstrap/cache/*
systemctl restart php82-php-fpm
systemctl restart supervisord
${php_cmd} artisan livewire:publish

chown -R nullfake:nginx *

# Fix cache directory permissions
sudo chown -R nullfake:nginx storage/framework/cache/
sudo chmod -R 755 storage/framework/cache/

# Also fix the broader storage directory to prevent future issues
sudo chown -R nullfake:nginx storage/
sudo chmod -R 755 storage/
sudo chmod -R 775 storage/logs/
sudo chmod -R 775 storage/framework/cache/
sudo chmod -R 775 storage/framework/sessions/
sudo chmod -R 775 storage/framework/views/
