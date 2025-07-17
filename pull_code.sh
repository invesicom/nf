#!/bin/sh

/usr/bin/php82 /usr/local/bin/composer dump-autoload;/usr/bin/php82 artisan config:clear;/usr/bin/php82 artisan cache:clear;/usr/bin/php82 artisan route:clear;/usr/bin/php82 artisan view:clear;rm -rf bootstrap/cache/*;systemctl restart php82-php-fpm;chown -R nullfake:nginx *;systemctl restart nullfake-queue
