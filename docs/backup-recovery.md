# Yedekleme ve Kurtarma (Aşama 6 §5)

> Bu doküman üç betiğe dayanır: `scripts/backup-database.sh` (yedek), `scripts/restore-database.sh` (geri yükleme), `scripts/verify-backup.sh` (geri dönüş testi). Cron sarmalayıcı: `cron/backup-daily.sh`. Sunucu kurulumu: `docs/deployment.md` §6 (ayrı yedek kullanıcısı) ve §10 (cron).

---

## 1. Hedefler — RPO / RTO

| Hedef | Değer | Açıklama |
|---|---|---|
| **RPO** (kabul edilebilir veri kaybı) | **≤ 24 saat** (günlük yedek, 03:30) | Kritik sınıf (siparişler + ödemeler) için gece yedeği + offsite kopya. Daha sık yedek isteniyorsa cron'daki backup satırı günde 2-3 kez çalıştırılabilir (flock çakışmayı önler). |
| **RTO** (kurtarma süresi) | **≤ 1 saat** | Restore betiği + `verify-backup.sh` + servislerin yeniden başlatılması; küçük VPS'te 10 dk altında ölçülmüştür. |
| Yedek saklama | 14 gün yerel (`RETENTION_DAYS`) | Offsite kopya ayrı politikayla tutulur (önerilen: 30-90 gün). |
| Test periyodu | **Ayda bir** `verify-backup.sh` | "Yedeği var" ≠ "yedeğinden dönülüyor" — gerçek import testi zorunludur. |

## 2. Veri sınıfları ve kurtarma gereksinimleri

| Veri sınıfı | Tablolar / kaynak | Kayıp toleransı | Kurtarma yöntemi |
|---|---|---|---|
| **XML kaynak tanımları + aktarım logları** | `xml_feeds`, `xml_field_mappings`, `xml_import_logs` | RPO 24 saat yeterli | Yedekten döner. Eşleme profilleri elle yeniden kurmak zahmetlidir → yedek kritik. |
| **Ürünler / stok / fiyat** | `products`, `product_variants`, `product_images`, `product_supplier_data`, `price_rules` | RPO 24 saat yeterli | Yedekten döner **veya** kaynaktan türetilebilir: feed'ler yeniden senkronize edilir (`xml:sync --force`). Kategori ağacı yedekte tutulmalı (XML eşleşmesi buna bağlı). |
| **XML dosyaları (upload kaynakları)** | `storage/imports/` | Tedarikçi feed'i yeniden indirilebilir; upload tiplerinde **dosya yedeği ayrıca alınmalı** | Dosya sistemi yedeği / offsite kopya (rsync/rclone). DB'de yalnız `last_file_path` referansı var. |
| **SİPARİŞLER + ÖDEME KAYITLARI — KRİTİK** | `orders`, `order_items`, `payments`, `refunds`, `supplier_orders(+items)`, `shipments` | **Kayıp kabul edilmez** | Günlük yedekten kurtarılır (RPO 24h). Yedekten sonraki **fark**: iyzico Merchant Panelindeki işlem listesi (tarih, token, tutar, status) ile eşleştirilir; iyzico API'sinden `checkoutform/detail` ile token bazlı doğrulama yapılabilir (`docs/payment-iyzico.md`). Kesinti aralığındaki siparişler panel kayıtlarından manuel sisteme işlenir. |
| **Müşteriler / kullanıcılar** | `customers`, `addresses`, `carts`, `cart_items`, `admin_users` | RPO 24 saat | Yedekten döner. Sepet içeriği kaybı kabul edilebilir (müşteri yeniden kurar). |
| **Ayarlar + hukuki metinler** | `settings` (hukuki sayfa içerikleri burada düzenlenir) | RPO 24 saat | Yedekten döner — hukuk metinleri tekrar yazmamak için yedekte bulunmalı. |
| **Loglar** | `audit_logs`, `storage/logs/*` | Beste-effort | Günlük yedek; security.log ayrıca offsite'e gönderilebilir (adli gerekçe). |

## 3. Yedekleme bileşenleri

### 3.1 `scripts/backup-database.sh`

- `mariadb-dump` (Ubuntu 22.04+; `mysqldump` fallback) + `--single-transaction` → InnoDB'de tutarlı anlık görüntü, uygulamayı KİLİTLEMEZ.
- `--routines --triggers --events --hex-blob --default-character-set=utf8mb4`
- Çıktı: `storage/backups/xml_ecommerce_YYYYmmdd_HHMMSS.sql.gz` (gzip).
- Şifreleme (opsiyonel): `BACKUP_GPG_RECIPIENT=anahtar@site.com` → `.sql.gz.gpg` (alıcıya şifreli).
- Parola komut satırına yazılmaz (`MYSQL_PWD` env) — `ps` sızıntısı yok.
- Saklama: `RETENTION_DAYS=14` (varsayılan) günden eski yedekler silinir.
- Offsite kancası: `OFFSITE_CMD="rclone copy {} remote:xml-backup"` — `{}` dosya yoluyla değiştirilir; boşsa log'a `offsite=skipped` yazılır.
- Sonuç: `storage/logs/backup.log` → `file=... size=... duration=...s status=OK|FAIL|WARN_OFFSITE offsite=...`

### 3.2 `cron/backup-daily.sh`

`flock -n /tmp/xml-ecommerce-backup.lock` ile tek örnek garantisi verip backup betiğini çağırır. Crontab satırı (`cron/crontab.example`):

```
30 3 * * * flock -n /tmp/xml-backup.lock -c 'APP_DIR=/var/www/xml-ecommerce /var/www/xml-ecommerce/cron/backup-daily.sh >> /var/www/xml-ecommerce/storage/logs/cron.log 2>&1'
```

### 3.3 Offsite seçenekleri (en az biri zorunlu)

| Yöntem | OFFSITE_CMD örneği | Not |
|---|---|---|
| **rclone (önerilen)** | `OFFSITE_CMD="rclone copy {} gdrive:xml-ecommerce-backup"` | S3/B2/GDrive/OneDrive hepsi tek arayüz; `rclone config` ile kurulum. |
| rsync (ikinci sunucu/NAS) | `OFFSITE_CMD="rsync -a -e 'ssh -i /home/deploy/.ssh/id_ed25519' {} backup@nas:/mnt/backup/xml/"` | SSH anahtarı `deploy` kullanıcısına ait; hedef makinede sadece yazma izni. |
| scp (basit) | `OFFSITE_CMD="scp {} backup@10.0.0.5:/mnt/backup/xml/"` | rsync yoksa. |
| Nesne depolama CLI (S3) | `OFFSITE_CMD="aws s3 cp {} s3://ornek-yedek/xml/"` | `--storage-class STANDARD_IA` maliyeti düşürür. |

> 3-2-1 kuralı: **3** kopya (canlı + yerel yedek + offsite), **2** farklı ortam, **1** kopya offsite. Offsite kopya **şifreli** önerilir (`BACKUP_GPG_RECIPIENT`) — taşıyıcıya DB tam içeriği verilmemiş olur.

## 4. Geri yükleme prosedürü (adım adım)

```bash
# 0) Hazırlık — hangi yedek? En yenisi:
ls -1t /var/www/xml-ecommerce/storage/backups/*.sql.gz | head

# 1) Uygulamayı durdur (yeni sipariş/ödeme callback'i gelmesin)
sudo systemctl stop php8.3-fpm
# (Nginx'i tamamen kapatmak yerine 503 statik sayfaya almak da mümkündür:
#  sudo nginx -s quit   — süre uzarsa müşteriye "bakım" gösterin)

# 2) Ön kontroller
cd /var/www/xml-ecommerce
sudo -u www-data php8.3 bin/console doctor || true   # mevcut durumun fotoğrafı

# 3) Geri yükleme (betik OTOMATİK pre-restore yedeği alır → başarısızlıkta geri dönülebilir nokta)
APP_DIR=/var/www/xml-ecommerce \
DB_USERNAME=ecommerce_app \
bash scripts/restore-database.sh xml_ecommerce
# betik onay ister: "EVET" yazın. Otomasyon gerekiyorsa: FORCE=1

# 4) Doğrulama — şema + kritik tablolar
APP_DIR=/var/www/xml-ecommerce \
DB_USERNAME=ecommerce_app \
bash scripts/verify-backup.sh
#   orders / payments / products / customers satır sayıları + toplam tablo sayısı yazdırılır

# 5) Uygulama seviyesi kontroller
sudo -u www-data php8.3 bin/console doctor          # PASS/WARN/FAIL
sudo -u www-data php8.3 database/migrate.php status # şema güncel mi
sudo -u www-data php8.3 bin/console feed:list       # kaynaklar duruyor mu
curl -s https://orneksite.com/health                # 200 {"status":"ok"}

# 6) Servisleri başlat
sudo systemctl start php8.3-fpm
sudo systemctl reload nginx

# 7) Rapor — restore satırı backup.log'a düşmüştür:
tail -5 storage/logs/backup.log
```

**Ödeme mutabakatı (kritik sınıf):** restore sonrası yedek zamanı ile kesinti anı arasındaki siparişleri iyzico Merchant Panelindeki işlem listesinden çıkarın; ödemesi tahsil edilmiş ama sisteme düşmemiş işlem için `payments.token` alanına paneldeki token ile `xml:sync` tarzı manuel işleme veya iade akışına geçin (politika: **önce müşteri lehine**).

## 5. Yedek testi prosedürü (ayda bir)

```bash
# En yeni yedeği gerçekten geçici bir DB'ye restore eder (xml_restore_test),
# kritik tablo satır sayılarını yazdırır, temp DB'yi DROP eder:
APP_DIR=/var/www/xml-ecommerce bash scripts/verify-backup.sh
```

Beklenen çıktı (sandbox'ta doğrulanmıştır):

```
TABLO          SATIR
----------------------
orders         1
payments       1
products       4
customers      2
----------------------
toplam tablo   : 30
SONUÇ: OK — yedekten geri dönüş çalışıyor (0s)
```

- Sonuç `VERIFY_OK` / `VERIFY_FAIL` olarak `storage/logs/backup.log`'a yazılır.
- Şifreli yedekte (`*.gpg`) `gpg --decrypt` otomatik denenir; sunucunun gpg anahtar zincirinde özel anahtar bulunmalı.
- Ayda bir testten başka, her **major deploy sonrası** ve yedek politikası değişince de çalıştırın. Cron ile otomatikleştirmek için `cron/crontab.example` içindeki açıklamalı `verify` satırını aktifleştirin (1. pazar 04:30).

## 6. Felaket senaryoları (hızlı karar tablosu)

| Senaryo | Aksiyon |
|---|---|
| Yanlışlıkla silinen ürünler/kategoriler | Uygulamayı durdurmadan: yedeği `xml_restore_fix` isimli temp DB'ye aç (`FORCE=1 restore`), eksik satırları `INSERT INTO xml_ecommerce... SELECT` ile taşı, temp DB'yi DROP et. |
| Bozuk migration (yari tamamlanmış ALTER) | `migrate.php` idempotenttir; `doctor` migration sayımını FAIL verirse şemanın bozuk tablosunu yedekten satır bazlı onarın. |
| Sunucu kaybı (VPS gitti) | 1) Yeni Ubuntu + `docs/deployment.md` §1-9, 2) offsite yedeği indir (`rclone copy remote:xml-backup ./`), 3) `restore-database.sh`, 4) `storage/imports/` dosya yedeğini geri koy, 5) DNS'i yeni IP'ye çevir, 6) iyzico panelinden webhook/callback URL'ini doğrula. |
| Veritabanı ransomware/şifreleme | Yerel yedekler de şifrelendi ise offsite **versiyonlu** kopyadan dön (rclone `--backup-dir` ile eski sürümler saklanmalı), hemen `BACKUP_GPG_RECIPIENT` şifrelemesini devreye al. |
| Tek tablo bozulması (InnoDB corruption) | `mysqlcheck --repair` yerine: yedekten temp DB'ye restore + bozuk tabloyu satır bazlı taşı. |

---

**İlgili dokümanlar:** `docs/deployment.md` (kurulum, DB kullanıcıları, cron) · `docs/go-live-checklist.md` (canlıya geçişte yedek + geri dönüş testi şartı) · `docs/performance.md` (cron çakışma koruması).
