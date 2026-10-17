#!/usr/bin/env bash
# ============================================================================
# verify-backup.sh — yedek GERİ DÖNÜŞ testi (Aşama 6 §5, ayda bir çalıştırın)
#
# En yeni yedeği GERÇEKTEN geçici bir veritabanına (varsayılan xml_restore_test)
# restore eder, kritik tablo satır sayılarını yazdırır, geçici DB'yi DROP eder.
# "Yedeğimiz var" ≠ "yedekten dönülebiliyor" — bu betik ikincisini kanıtlar.
#
# Kullanım:
#   APP_DIR=/var/www/xml-ecommerce scripts/verify-backup.sh [yedek_dosyası]
#   RESTORE_TEST_DB=...  (varsayılan xml_restore_test)
#   FORCE=1              (onay gerektiren hiçbir adım yok — bilgi amaçlı)
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xml-ecommerce}"
LOG_FILE="$APP_DIR/storage/logs/backup.log"
BACKUP_FILE="${1:-}"
TEMP_DB="${RESTORE_TEST_DB:-xml_restore_test}"

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

DB_USER="${DB_USER:-${DB_USERNAME:-ecommerce_app}}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_SOCKET="${DB_SOCKET:-}"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/storage/backups}"

if [ -z "$BACKUP_FILE" ]; then
  BACKUP_FILE="$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -n 1 || true)"
  [ -z "$BACKUP_FILE" ] && BACKUP_FILE="$(ls -1t "$BACKUP_DIR"/*.sql.gz.gpg 2>/dev/null | head -n 1 || true)"
fi
[ -n "$BACKUP_FILE" ] && [ -f "$BACKUP_FILE" ] || { echo "HATA: test edilecek yedek bulunamadı ($BACKUP_DIR)" >&2; exit 1; }

if command -v mariadb >/dev/null 2>&1; then CLIENT_BIN="mariadb"; else CLIENT_BIN="mysql"; fi

CONN_ARGS=()
if [ -n "$DB_SOCKET" ]; then CONN_ARGS+=(--socket="$DB_SOCKET"); else CONN_ARGS+=(--host="$DB_HOST" --port="$DB_PORT" --protocol=TCP); fi
[ -n "$DB_USER" ] && CONN_ARGS+=(--user="$DB_USER")
export MYSQL_PWD="$DB_PASSWORD"

DURATION_START="$(date +%s)"
log() { printf '[%s] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$*" | tee -a "$LOG_FILE" >&2; }

cleanup() { "$CLIENT_BIN" "${CONN_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`$TEMP_DB\`" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "Yedek geri dönüş testi"
echo "  yedek   : $(basename "$BACKUP_FILE")"
echo "  temp db : $TEMP_DB"
echo "  istemci : $CLIENT_BIN"

# 1) geçici DB'yi sıfırdan kur
"$CLIENT_BIN" "${CONN_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`$TEMP_DB\`; CREATE DATABASE \`$TEMP_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2) yedeği içe aktar (şifreli ise decrypt)
set +e
if [[ "$BACKUP_FILE" == *.gpg ]]; then
  gpg --batch --decrypt "$BACKUP_FILE" 2>/dev/null | gunzip -c | "$CLIENT_BIN" "${CONN_ARGS[@]}" "$TEMP_DB"
else
  gunzip -c "$BACKUP_FILE" | "$CLIENT_BIN" "${CONN_ARGS[@]}" "$TEMP_DB"
fi
RC=$?
set -e
if [ $RC -ne 0 ]; then
  log "VERIFY_FAIL yedek=$(basename "$BACKUP_FILE") reason=import hatası (exit=$RC)"
  echo "SONUÇ: FAIL — import hatası" >&2
  exit 1
fi

# 3) kritik tablo satır sayıları
echo
printf "%-14s %s\n" "TABLO" "SATIR"
echo "----------------------"
FAIL=0
for t in orders payments products customers; do
  EXISTS="$("$CLIENT_BIN" "${CONN_ARGS[@]}" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$TEMP_DB' AND TABLE_NAME='$t';")"
  if [ "$EXISTS" = "0" ]; then
    printf "%-14s %s\n" "$t" "TABLO YOK!"
    FAIL=1
  else
    N="$("$CLIENT_BIN" "${CONN_ARGS[@]}" -N -e "SELECT COUNT(*) FROM \`$TEMP_DB\`.\`$t\`;")"
    printf "%-14s %s\n" "$t" "$N"
  fi
done

# 4) şema bütünlüğü: tablo sayısı
TBL_COUNT="$("$CLIENT_BIN" "${CONN_ARGS[@]}" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$TEMP_DB';")"
echo "----------------------"
echo "toplam tablo   : $TBL_COUNT"

DUR=$(( $(date +%s) - DURATION_START ))
if [ "$FAIL" -eq 0 ]; then
  log "VERIFY_OK yedek=$(basename "$BACKUP_FILE") duration=${DUR}s tables=$TBL_COUNT"
  echo "SONUÇ: OK — yedekten geri dönüş çalışıyor (${DUR}s)"
  exit 0
else
  log "VERIFY_FAIL yedek=$(basename "$BACKUP_FILE") reason=kritik tablo eksik"
  echo "SONUÇ: FAIL — kritik tablo(lar) eksik" >&2
  exit 1
fi
