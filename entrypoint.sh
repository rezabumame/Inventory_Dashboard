#!/bin/bash
set -e

# Fix permissions on bind-mounted directories so www-data can write
chown -R www-data:www-data /var/www/html/uploads 2>/dev/null || true
chown -R www-data:www-data /var/lib/php/sessions 2>/dev/null || true

exec "$@"
