#!/usr/bin/env bash
# ============================================================================
# backup-daily.sh — cron sarmalayıcı: flock ile tek örnek garanti edilir.
#
# Crontab satırı (bkz. cron/crontab.example):
#   30 3 * * * APP_DIR=/var/www/xml-ecommerce /var/www/xml-ecommerce/cron/backup-daily.sh >> /var/www/xml-ecommerce/storage/logs/cron.log 2>&1
#
# Aynı anda ikinci bir yedek başlatılamaz (gece backup + manuel backup çakışması);
# kilit alınamazsa sessizce çıkılır (exit 0) — cron tekrar deneyecek, veri kaybı yok.
# Yapılandırma backup-database.sh ile aynı env değişkenleriyle yapılır
# (RETENTION_DAYS, OFFSITE_CMD, BACKUP_GPG_RECIPIENT, DB_*).
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xml-ecommerce}"
LOCK_FILE="${BACKUP_LOCK_FILE:-/tmp/xml-ecommerce-backup.lock}"

# flock: tek örnek (non-blocking) — uygulama içi FeedLock'un kabuk katmanı karşılığı
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  printf '[%s] Önceki yedek işlemi hâlâ çalışıyor — bu çalıştırma atlandı.\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')"
  exit 0
fi

exec bash "$APP_DIR/scripts/backup-database.sh"
