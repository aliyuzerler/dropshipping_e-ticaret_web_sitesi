#!/usr/bin/env bash
# ============================================================================
# restore-database.sh — yedekten veritabanı geri yükleme (Aşama 6 §5)
#
# Kullanım:
#   APP_DIR=/var/www/xml-ecommerce scripts/restore-database.sh <hedef_db> [yedek_dosyası]
#     - yedek_dosyası verilmezse BACKUP_DIR içindeki EN YENİ xml_ecommerce_*.sql.gz* kullanılır
#     - .sql.gz ve .sql.gz.gpg desteklenir (gpg için BACKUP_GPG_RECIPIENT ile aynı anahtar)
#   FORCE=1 → onay sorusu atlanır (otomasyon/iç içe scriptler için)
#   BACKUP_DIR=...  (varsayılan $APP_DIR/storage/backups)
#
# Güvenlik akışı:
#   1) Hedef DB'nin ÖNCEKİ halinden otomatik pre-restore yedeği alınır
#      (xml_ecommerce_pre_restore_YYYYmmdd_HHMMSS.sql.gz)
#   2) Onay istenir (FORCE=1 ile atlanır)
#   3) gunzip | mysql import
#   4) Sonuç storage/logs/backup.log'a yazılır (status=RESTORE_OK / RESTORE_FAIL)
#
# UYARI: Bu betik hedef DB'deki TÜM veriyi yedekteki hâl ile EZER. Üretimde
# önce apps'i durdurun (bkz. docs/backup-recovery.md §geri yükleme).
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xml-ecommerce}"
LOG_FILE="$APP_DIR/storage/logs/backup.log"
FORCE="${FORCE:-0}"

# --- .env yükle (yalnız DB anahtarları; mevcut ortam değişkeni kazanır) ------
if [ -f "$APP_DIR/.env" ]; then
  while IFS= read -r line; do
    key="${line%%=*}"; val="${line#*=}"
    val="${val%\"}"; val="${val#\"}"; val="${val%\'}"; val="${val#\'}"
    case "$key" in
      DB_USERNAME|DB_PASSWORD|DB_HOST|DB_PORT)
        if [ -z "${!key:-}" ]; then export "$key=$val"; fi ;;
    esac
  done < <(grep -E '^[[:space:]]*(DB_USERNAME|DB_PASSWORD|DB_HOST|DB_PORT)[[:space:]]*=' "$APP_DIR/.env" | sed 's/[[:space:]]*$//')
fi

TARGET_DB="${1:-}"
BACKUP_FILE="${2:-}"
DB_USER="${DB_USER:-${DB_USERNAME:-ecommerce_app}}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_SOCKET="${DB_SOCKET:-}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/storage/backups}"

if [ -z "$TARGET_DB" ]; then
  echo "Kullanım: APP_DIR=... scripts/restore-database.sh <hedef_db> [yedek_dosyası]" >&2
  exit 2
fi

# --- istemci araçlarını seç ----------------------------------------------------
if command -v mariadb >/dev/null 2>&1; then CLIENT_BIN="mariadb"; else CLIENT_BIN="mysql"; fi
if [ -z "$BACKUP_FILE" ]; then
  BACKUP_FILE="$(ls -1t "$BACKUP_DIR"/${TARGET_DB%.sql*}_*.sql.gz* 2>/dev/null | head -n 1 || true)"
  if [ -z "$BACKUP_FILE" ]; then
    # isim deseni eşleşmedi → dizindeki en yeni yedek
    BACKUP_FILE="$(ls -1t "$BACKUP_DIR"/*.sql.gz* 2>/dev/null | head -n 1 || true)"
  fi
  if [ -z "$BACKUP_FILE" ] || [ ! -f "$BACKUP_FILE" ]; then
    echo "HATA: $BACKUP_DIR içinde yedek bulunamadı." >&2
    exit 1
  fi
fi
[ -f "$BACKUP_FILE" ] || { echo "HATA: yedek dosyası yok: $BACKUP_FILE" >&2; exit 1; }

CONN_ARGS=()
if [ -n "$DB_SOCKET" ]; then CONN_ARGS+=(--socket="$DB_SOCKET"); else CONN_ARGS+=(--host="$DB_HOST" --port="$DB_PORT" --protocol=TCP); fi
[ -n "$DB_USER" ] && CONN_ARGS+=(--user="$DB_USER")
export MYSQL_PWD="$DB_PASSWORD"

DURATION_START="$(date +%s)"
log() { printf '[%s] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*" | tee -a "$LOG_FILE" >&2; }
log_result() { # log_result status detail
  local dur=$(( $(date +%s) - DURATION_START ))
  printf '[%s] restore=%s duration=%ss status=%s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$1" "$dur" "$2" >> "$LOG_FILE"
}

log "GERİ YÜKLEME: hedef=$TARGET_DB yedek=$(basename "$BACKUP_FILE")"

# --- 1) pre-restore güvenlik yedeği --------------------------------------------
PRE_FILE="$BACKUP_DIR/${TARGET_DB}_pre_restore_$(date +%Y%m%d_%H%M%S).sql.gz"
DUMP_BIN=""
if command -v mariadb-dump >/dev/null 2>&1; then DUMP_BIN="mariadb-dump"
elif command -v mysqldump >/dev/null 2>&1; then DUMP_BIN="mysqldump"; fi

if [ -n "$DUMP_BIN" ]; then
  if "$DUMP_BIN" "${CONN_ARGS[@]}" --single-transaction --quick --routines --triggers --events \
       --hex-blob --no-tablespaces --default-character-set=utf8mb4 "$TARGET_DB" 2>/dev/null | gzip -6 > "$PRE_FILE"; then
    log "Pre-restore yedeği alındı: $(basename "$PRE_FILE") ($(stat -c %s "$PRE_FILE") byte)"
  else
    rm -f "$PRE_FILE"
    if [ "$FORCE" != "1" ]; then
      log "HATA: hedef DB'den pre-restore yedeği alınamadı — işlem iptal. (Zorlamak için FORCE=1)"
      exit 1
    fi
    log "UYARI: pre-restore yedeği alınamadı, FORCE=1 ile devam ediliyor"
  fi
fi

# --- 2) onay ---------------------------------------------------------------------
if [ "$FORCE" != "1" ]; then
  printf 'UYARI: "%s" veritabanı "%s" yedeğiyle EZİLECEK.\nOnaylamak için EVET yazın: ' "$TARGET_DB" "$(basename "$BACKUP_FILE")"
  read -r answer
  if [ "$answer" != "EVET" ]; then
    log "Geri yükleme kullanıcı tarafından iptal edildi"
    echo "İptal edildi." >&2
    exit 1
  fi
fi

# --- 3) import --------------------------------------------------------------------
# Hedef DB yoksa oluştur (boş sunucudan kurtarma senaryosu) — şema standardı utf8mb4
"$CLIENT_BIN" "${CONN_ARGS[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

set +e
if [[ "$BACKUP_FILE" == *.gpg ]]; then
  gpg --batch --decrypt "$BACKUP_FILE" 2>/dev/null | gunzip -c | "$CLIENT_BIN" "${CONN_ARGS[@]}" "$TARGET_DB"
else
  gunzip -c "$BACKUP_FILE" | "$CLIENT_BIN" "${CONN_ARGS[@]}" "$TARGET_DB"
fi
RC=$?
set -e

if [ $RC -eq 0 ]; then
  log_result "RESTORE_OK" "db=$TARGET_DB yedek=$(basename "$BACKUP_FILE")"
  log "Geri yükleme tamamlandı: $TARGET_DB"
  exit 0
else
  log_result "RESTORE_FAIL" "db=$TARGET_DB exit=$RC"
  if [ -f "$PRE_FILE" ]; then
    log "HATA: import başarısız. Kalabildiğimiz yere dönülmek istenirse: scripts/restore-database.sh $TARGET_DB $PRE_FILE"
  fi
  exit "$RC"
fi
