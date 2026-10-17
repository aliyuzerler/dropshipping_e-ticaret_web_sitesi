#!/usr/bin/env bash
# ============================================================================
# backup-database.sh — xml_ecommerce veritabanı yedeği (Aşama 6 §5)
#
# Özellikler:
#   - mysqldump/mariadb-dump --single-transaction (InnoDB, tutarlı anlık görüntü)
#   - gzip sıkıştırma → storage/backups/xml_ecommerce_YYYYmmdd_HHMMSS.sql.gz
#   - OPİYONEL gpg şifreleme: BACKUP_GPG_RECIPIENT tanımlıysa .sql.gz.gpg üretir
#   - saklama politikası: RETENTION_DAYS (varsayılan 14) → eski yedekleri siler
#   - OFFSITE_CMD kancası: örn "rclone copy {} remote:yedekler" veya
#     "rsync -a {} yedek@nas:/mnt/backup" ({} → dosya yolu; boşsa atlanır)
#   - sonuç storage/logs/backup.log'a yazılır (tarih, dosya, boyut, süre, durum)
#   - soket/port desteği: DB_SOCKET tanımlıysa soket, aksi hâlde TCP host/port
#   - mysqldump yoksa mariadb-dump tercih edilir (Ubuntu 22.04+: mariadb-dump)
#     Kuvvetle özelleştirmek için: BACKUP_DUMP_CMD="mariadb-dump" (veya tam yol)
#
# Kullanım:
#   APP_DIR=/var/www/xml-ecommerce scripts/backup-database.sh
#   (cron için: cron/backup-daily.sh — flock'lu sarmalayıcı)
#
# Kimlik bilgileri önceliği: ortam değişkeni > $APP_DIR/.env > varsayılan
#   DB_USERNAME / DB_USER, DB_PASSWORD, DB_HOST, DB_PORT, DB_SOCKET, DB_NAME / DB_DATABASE
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-${1:-/var/www/xml-ecommerce}}"
LOG_FILE="$APP_DIR/storage/logs/backup.log"
DURATION_START="$(date +%s)"

# --- .env yükle (yalnız DB anahtarları; mevcut ortam değişkeni kazanır) ------
if [ -f "$APP_DIR/.env" ]; then
  while IFS= read -r line; do
    key="${line%%=*}"
    val="${line#*=}"
    val="${val%\"}"; val="${val#\"}"; val="${val%\'}"; val="${val#\'}"
    case "$key" in
      DB_USERNAME|DB_PASSWORD|DB_HOST|DB_PORT)
        if [ -z "${!key:-}" ]; then export "$key=$val"; fi ;;
    esac
  done < <(grep -E '^[[:space:]]*(DB_USERNAME|DB_PASSWORD|DB_HOST|DB_PORT)[[:space:]]*=' "$APP_DIR/.env" | sed 's/[[:space:]]*$//')
fi

DB_NAME="${DB_NAME:-${DB_DATABASE:-xml_ecommerce}}"
DB_USER="${DB_USER:-${DB_USERNAME:-ecommerce_app}}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_SOCKET="${DB_SOCKET:-}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/storage/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
OFFSITE_CMD="${OFFSITE_CMD:-}"
BACKUP_GPG_RECIPIENT="${BACKUP_GPG_RECIPIENT:-}"

log() { # log "mesaj" → hem stderr hem backup.log
  printf '[%s] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*" | tee -a "$LOG_FILE" >&2
}

log_result() { # log_result status file size_bytes [error]
  local status="$1" file="$2" size="${3:-0}" err="${4:-}"
  local dur=$(( $(date +%s) - DURATION_START ))
  local line="file=$(basename "$file") size=${size} duration=${dur}s status=${status}"
  [ -n "$err" ] && line="$line error=\"$err\""
  if [ -n "$OFFSITE_CMD" ]; then line="$line offsite=ok"; else line="$line offsite=skipped"; fi
  printf '[%s] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$line" >> "$LOG_FILE"
}

on_error() {
  local err="$?"
  log_result "FAIL" "$BACKUP_DIR/${DB_NAME}_"*.sql.gz 0 "dump/pipe hatası (exit=$err)"
  rm -f "$BACKUP_DIR/${DB_NAME}_"*.sql.gz.tmp 2>/dev/null || true
  exit "$err"
}
trap on_error ERR

mkdir -p "$BACKUP_DIR" "$APP_DIR/storage/logs"

# --- dump aracını seç ---------------------------------------------------------
DUMP_BIN="${BACKUP_DUMP_CMD:-}"
if [ -z "$DUMP_BIN" ]; then
  if command -v mariadb-dump >/dev/null 2>&1; then DUMP_BIN="mariadb-dump"
  elif command -v mysqldump >/dev/null 2>&1; then DUMP_BIN="mysqldump"
  else
    log "HATA: mariadb-dump/mysqldump bulunamadı (apt install mariadb-client)"
    log_result "FAIL" "$BACKUP_DIR/${DB_NAME}_dump" 0 "dump aracı yok"
    exit 1
  fi
fi

# --- bağlantı argümanları (soket veya TCP) ------------------------------------
CONN_ARGS=()
if [ -n "$DB_SOCKET" ]; then
  CONN_ARGS+=(--socket="$DB_SOCKET")
else
  CONN_ARGS+=(--host="$DB_HOST" --port="$DB_PORT" --protocol=TCP)
fi
[ -n "$DB_USER" ] && CONN_ARGS+=(--user="$DB_USER")

# Parola komut satırına YAZILMAZ (ps sızıntısı yok) → MYSQL_PWD ortamı kullanılır
export MYSQL_PWD="$DB_PASSWORD"

DUMP_ARGS=(--single-transaction --quick --routines --triggers --events
           --hex-blob --no-tablespaces --default-character-set=utf8mb4)

BASENAME="${DB_NAME}_$(date +%Y%m%d_%H%M%S)"
FINAL_FILE="$BACKUP_DIR/$BASENAME.sql.gz"
[ -n "$BACKUP_GPG_RECIPIENT" ] && FINAL_FILE="$FINAL_FILE.gpg"
TMP_FILE="$FINAL_FILE.tmp"

log "Yedek başlıyor: db=$DB_NAME araç=$DUMP_BIN hedef=$(basename "$FINAL_FILE")"

# --- dump | gzip [| gpg] — tmp dosyaya yaz, başarıda atomik rename -------------
if [ -n "$BACKUP_GPG_RECIPIENT" ]; then
  "$DUMP_BIN" "${CONN_ARGS[@]}" "${DUMP_ARGS[@]}" "$DB_NAME" \
    | gzip -6 \
    | gpg --batch --yes -e -r "$BACKUP_GPG_RECIPIENT" -o "$TMP_FILE"
else
  "$DUMP_BIN" "${CONN_ARGS[@]}" "${DUMP_ARGS[@]}" "$DB_NAME" | gzip -6 > "$TMP_FILE"
fi

# Boş/yarım dump koruması
if [ ! -s "$TMP_FILE" ]; then
  rm -f "$TMP_FILE"
  log_result "FAIL" "$FINAL_FILE" 0 "boş yedek dosyası"
  exit 1
fi
mv -f "$TMP_FILE" "$FINAL_FILE"

SIZE="$(stat -c %s "$FINAL_FILE" 2>/dev/null || stat -f %z "$FINAL_FILE")"
log "Yedek tamamlandı: $(basename "$FINAL_FILE") (${SIZE} byte)"

# --- saklama politikası: eski yedekleri sil -------------------------------------
OLD_COUNT="$(find "$BACKUP_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz*" -type f -mtime "+${RETENTION_DAYS}" | wc -l || true)"
if [ "${OLD_COUNT:-0}" -gt 0 ]; then
  find "$BACKUP_DIR" -maxdepth 1 -name "${DB_NAME}_*.sql.gz*" -type f -mtime "+${RETENTION_DAYS}" -delete
  log "Saklama: ${RETENTION_DAYS} günden eski ${OLD_COUNT} yedek silindi"
fi

# --- offsite kancası --------------------------------------------------------------
if [ -n "$OFFSITE_CMD" ]; then
  log "Offsite aktarım başlıyor"
  if BACKUP_FILE="$FINAL_FILE" bash -c "${OFFSITE_CMD//\{\}/\$BACKUP_FILE}" >> "$LOG_FILE" 2>&1; then
    log "Offsite aktarım tamamlandı"
  else
    log_result "WARN_OFFSITE" "$FINAL_FILE" "$SIZE" "offsite aktarım başarısız (yerel yedek güvenli)"
    exit 0
  fi
fi

log_result "OK" "$FINAL_FILE" "$SIZE" ""
exit 0
