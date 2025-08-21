#!/bin/sh

/usr/bin/php82 /usr/local/bin/composer dump-autoload;php82 artisan config:clear;php82 artisan cache:clear;php82 artisan route:clear;php82 artisan view:clear;rm -rf bootstrap/cache/*;systemctl restart php82-php-fpm;systemctl restart supervisord;php82 artisan livewire:publish;chown -R nullfake:nginx *

