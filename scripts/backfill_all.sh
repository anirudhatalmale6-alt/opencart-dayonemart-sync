#!/bin/bash
cd /home/krocerco/public_html/green7.app
LOG=storage/logs/letsync-backfill.log
echo "=== BACKFILL START $(date) ===" >> "$LOG"
/usr/local/bin/php artisan letsync:backfill products   >> "$LOG" 2>&1
echo "=== products done $(date) ===" >> "$LOG"
/usr/local/bin/php artisan letsync:backfill customers  >> "$LOG" 2>&1
echo "=== customers done $(date) ===" >> "$LOG"
/usr/local/bin/php artisan letsync:backfill orders     >> "$LOG" 2>&1
echo "=== BACKFILL COMPLETE $(date) ===" >> "$LOG"
