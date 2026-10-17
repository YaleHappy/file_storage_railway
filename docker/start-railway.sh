#!/bin/sh
set -eu

PORT="${PORT:-8080}"
case "$PORT" in
    ''|*[!0-9]*) echo "Invalid PORT: expected an integer" >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "Invalid PORT: expected 1-65535" >&2
    exit 1
fi

# mod_php requires prefork. Normalize modules in the running container too.
a2dismod -f mpm_event mpm_worker
a2enmod mpm_prefork

# Replace the entire Listen directive so repeated starts remain safe.
sed -i -E "s/^[[:space:]]*Listen[[:space:]]+.*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i -E "s/<VirtualHost [^>]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "Railway startup: checking Apache configuration on port ${PORT}"
apache2ctl -t
apache2ctl -M
exec apache2-foreground
