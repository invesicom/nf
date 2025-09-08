#!/bin/sh

/usr/bin/php82 /usr/local/bin/composer dump-autoload;php82 artisan config:clear;php82 artisan cache:clear;php82 artisan route:clear;php82 artisan view:clear;rm -rf bootstrap/cache/*;systemctl restart php82-php-fpm;systemctl restart supervisord;php82 artisan livewire:publish;chown -R nullfake:nginx *

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
