#!/bin/bash
set -e
APP=/home/krocerco/public_html/green7.app
LINE="* * * * * cd $APP && /usr/local/bin/php artisan queue:work database --queue=letsync --stop-when-empty --max-time=55 --tries=5 --sleep=3 >> $APP/storage/logs/letsync-worker.log 2>&1"
TMP=$(mktemp)
crontab -l 2>/dev/null > "$TMP" || true
if grep -Fq "queue:work database --queue=letsync" "$TMP"; then
  echo "cron already present"
else
  echo "$LINE" >> "$TMP"
  crontab "$TMP"
  echo "cron installed"
fi
rm -f "$TMP"
crontab -l | grep letsync || true
