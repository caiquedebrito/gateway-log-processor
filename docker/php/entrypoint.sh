#!/bin/sh

echo "Installing dependencies..."

composer install --no-interaction

echo "Waiting for database..."

while ! php artisan tinker --execute="DB::connection()->getPdo();" > /dev/null 2>&1
do
    sleep 2
done

echo "Running migrations..."

php artisan migrate --force

if [ "$RUN_SEEDERS" = "true" ]; then
    echo "Running seeders..."
    php artisan db:seed --force
fi

echo "Starting PHP-FPM..."

exec "$@"