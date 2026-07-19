#!/bin/bash
set -euo pipefail

# Ensure bind-mounted storage dirs exist and are writable.
# Avoid recursive chown of large NAS/sync trees (slow/hangs on virtiofs).
mkdir -p /var/www/html/filestore /data/syncdir
chmod ug+rwx /var/www/html/filestore /data/syncdir 2>/dev/null || true
chown www-data:www-data /var/www/html/filestore /data/syncdir 2>/dev/null || true

if [[ -f /var/www/html/include/config.php ]]; then
  chown www-data:www-data /var/www/html/include/config.php 2>/dev/null || true
fi
chmod ug+rwX /var/www/html/include 2>/dev/null || true

service cron start

exec apachectl -D FOREGROUND
