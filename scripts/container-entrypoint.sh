#!/bin/sh
set -eu
mkdir -p /app/storage/downloads /app/storage/local-pages /app/storage/runtime /app/public/uploads
chown -R www-data:www-data /app/storage/downloads /app/storage/local-pages /app/storage/runtime /app/public/uploads
php scripts/migrate.php
exec "$@"
