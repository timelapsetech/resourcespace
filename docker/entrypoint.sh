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

# Cron (www-data) cannot write to /proc/1/fd/*; log file must be writable by www-data.
touch /var/log/resourcespace-cron.log
chown www-data:www-data /var/log/resourcespace-cron.log
chmod 0644 /var/log/resourcespace-cron.log

# After a container kill/restart, orphan job_* / offlinejobs_* locks and
# STATUS_INPROGRESS rows on the filestore volume block cron (max-jobs).
# Recover best-effort before cron starts; never fail container boot.
if [[ -f /var/www/html/include/config.php ]]; then
  php /var/www/html/docker/recover_offline_jobs_on_boot.php \
    >>/var/log/resourcespace-cron.log 2>&1 \
    || echo "$(date -Is) recover_offline_jobs_on_boot failed (DB not ready?); continuing" \
      >>/var/log/resourcespace-cron.log
fi

service cron start

exec apachectl -D FOREGROUND
