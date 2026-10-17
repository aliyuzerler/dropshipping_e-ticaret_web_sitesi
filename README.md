# XML E-Ticaret — Bağımsız PHP Projesi (Aşama 1 + 2 + 3 + 4)

XML tedarikçi feed'lerinden ürün çeken, **stoksuz satış (dropshipping)** modeliyle çalışan bağımsız, modüler PHP e-ticaret uygulaması.

> **Aşama 1 kapsamı:** Mimari dokümantasyon, çalışan proje iskeleti, migration sistemi, güvenlik altyapısı (CSRF / SSRF / XXE), health-check, cron altyapısı ve test suite.
>
> **Aşama 2 kapsamı (bu sürümde tamamlandı):** XML ürün aktarım motoru — çoklu tedarikçi/XML kaynağı yönetimi (dosya yükleme + URL), akışkan XMLReader parser, yönetilebilir alan eşleme profilleri, ürün eşleştirme ve idempotent senkronizasyon, fiyat kuralı motoru, `bin/console` CLI, cron entegrasyonu ve aktarım ekranları. Ödeme (iyzico) ve vitrin/checkout **bilinçli olarak sonraki fazlarda**.
>
> **Aşama 3 kapsamı (bu sürümde tamamlandı):** Müşteri mağazası — katalog/kategori/arama/filtre/sıralama/sayfalama, ürün detay (galeri, varyant, benzer ürünler, JSON-LD), sepet (misafir+üye, birleştirme, sunucu-taraflı hesap, kupon), üyelik (kayıt/giriş/kilit/şifre sıfırlama), adres defteri, sipariş (snapshot'lı, ödeme Aşama 4'te), hesabım, SEO (slug/canonical/sitemap/robots/structured data), hukuki TASLAK sayfalar, iletişim, admin mağaza ayarları.
>
> **Aşama 4 kapsamı (bu sürümde tamamlandı):** Sipariş durumu makinesi (10 durum + doğrulanmış geçişler + audit log), iyzico Checkout Form entegrasyonu (IYZWSv2 imza, başlatma/sorgulama/iptal/iade uçları — resmî doküman + iyzipay-php SDK ile birebir doğrulandı), token-bazlı callback + sunucu tarafı doğrulama, webhook (X-IYZ-SIGNATURE-V3 + duplike kontrolü), idempotent ödeme işleme (UNIQUE token, FOR UPDATE, tutar doğrulama), çift tedarikçi siparişi engelleme (UNIQUE + INSERT IGNORE), iptal ve iade modülü (tam/kısmi, kırılım referansları, admin onay akışı), admin sipariş yönetimi ekranları, `order:expire` CLI + cron, 16 yeni test + uçtan uca tarayıcı doğrulaması. **Canlı ödeme testi YAPILMAMIŞTIR** (gerçek API anahtarı yok — mock/sandbox yapılandırması hazırdır, iddia edilmez).
>
> **Aşama 5 kapsamı (bu sürümde tamamlandı):** Tedarikçi sipariş yönetimi (ödemesi doğrulanmış siparişin tedarikçiye göre bölünmesi, benzersiz dahili referans = idempotency anahtarı, alış maliyeti + teslimat snapshot'ı), **SupplierAdapter** (manuel işleme / API gönderim / durum sorgusu / takip bilgisi — API bilgisi olmayan tedarikçi için sahte otomatik entegrasyon YOK, panelde manuel görev), zaman aşımı → `unknown` → önce durum sorgusu (asla körleme resend), kargo yönetimi (manuel takip numarası girişi + adapter altyapısı, sipariş shipped/delivered eşitlemesi), e-posta bildirim altyapısı (dedup UNIQUE, kuyruk + tekrar deneme, log/SMTP sürücü), admin ekranları (tedarikçi siparişleri/kargo/raporlar), 4 yeni CLI komutu (`supplier:process`, `supplier:query`, `shipments:sync`, `notifications:send`), 11 yeni test + uçtan uca tarayıcı doğrulaması. **Gerçek tedarikçi/kargo API'si olmadan otomatik gönderim DOĞRULANMIŞ olarak raporlanmaz** — API yolu sahte transport ile sözleşme düzeyinde test edildi; canlıda önce gerçek API bağlanır.
>
> Detaylı modül referansı: **`docs/xml-import.md`** · **`docs/payment-iyzico.md`** · Vitrin: `routes/store.php` + `app/Controllers/Store/` + `app/Views/store/`

## Teknoloji Yığını

| Katman | Teknoloji |
|---|---|
| Backend | PHP 8.2+ (Composer bağımlılığı yok — sıfır 3. parti paket) |
| Veritabanı | MySQL 8+ / InnoDB / utf8mb4 (PDO, gerçek prepared statements) |
| XML | PHP `XMLReader` (streaming, XXE-güvenli) |
| Frontend | HTML, CSS, Bootstrap 5.3, JavaScript + jQuery 3.7 (SRI imzalı CDN) |
| Sunucu | Ubuntu LTS, Nginx + PHP-FPM + PHP CLI |
| Zamanlama | Linux Cron (`cron/import_feeds.php`) |
| Sürüm kontrol | Git |

## Dokümantasyon

| Dosya | İçerik |
|---|---|
| `docs/architecture.md` | Katmanlar, veri akışları (ASCII diyagram), hata yönetimi, güvenlik sınırları |
| `docs/database-design.md` | Tablolar, ilişkiler, FK kuralları, DECIMAL para standardı, snapshot stratejisi |
| `docs/security.md` | OWASP Top 10 eşlemesi, SSRF/XXE/CSRF savunmaları, loglama |
| `docs/development-roadmap.md` | Faz 1–8 yol haritası ve kabul kriterleri |
| `docs/xml-import.md` | **Aşama 2 referansı:** aktarım motoru, alan eşleme, senkron kuralları, fiyatlandırma, CLI, ekranlar |
| `docs/payment-iyzico.md` | **Aşama 4 referansı:** iyzico Checkout Form, IYZWSv2 imza, callback/webhook, iptal-iade, canlıya geçiş kontrol listesi |
| `docs/phase5-report.md` | **Aşama 5 referansı:** tedarikçi siparişleri, SupplierAdapter sözleşmesi, idempotency/tekrarlı gönderim engelleri, kargo, bildirim altyapısı, test sonuçları + canlıya geçiş ayarları |
| `docs/deployment.md` | **Aşama 6 referansı:** Ubuntu VPS kurulumu — SSH/ufw sertleştirme, Nginx sunucu bloğu, PHP-FPM/OPcache, MySQL kullanıcıları, izinler, HTTPS, cron/logrotate, yaygın hatalar |
| `docs/backup-recovery.md` | **Aşama 6 referansı:** yedekleme betikleri, RPO/RTO hedefleri, veri sınıfı bazlı kurtarma, geri yükleme prosedürü, offsite seçenekleri |
| `docs/performance.md` | **Aşama 6 referansı:** EXPLAIN analizi + indeks kararı (031), cron çakışma koruması (FeedLock+flock), önbellek politikası, zaman aşımı/bellek ayarları |
| `docs/go-live-checklist.md` | **Aşama 6 referansı:** canlıya geçiş kontrol listesi (ortam, XML cron, iyzico, kargo, e-posta/SPF, SSL, yedek geri dönüş testi, hukuki sayfalar) |

## Dizin Yapısı

```text
xml-ecommerce/
├── public/               # TEK web kökü (index.php + assets/)
├── bin/                  # bin/console — CLI girişi (xml:sync, feed:list, xml:preview)
├── app/
│   ├── Core/             # Router, Request, Response, Database, View, Session, Csrf, Logger, ErrorHandler...
│   ├── Console/          # ConsoleKernel + Commands (XmlSync, FeedList, XmlPreview)
│   ├── Controllers/      # Home, Health, Admin/{Auth,Dashboard,Supplier,Feed*,Import*,PriceRule,UpdateLog,XmlImport}
│   ├── Models/           # İnce entity'ler (Supplier, Product, Category, AdminUser)
│   ├── Services/         # Auth, Audit, Health, Http, Security, Pricing (kural motoru), Import (motor)
│   ├── Repositories/     # PDO prepared-only veri erişimi
│   ├── Middleware/       # SecurityHeaders, Csrf, AdminAuth
│   ├── Validators/       # Validator, XmlUploadValidator
│   ├── Views/            # layouts/, home/, admin/ (feeds, suppliers, imports, price_rules, updates...), errors/
│   ├── Exceptions/       # HttpException, ValidationException
│   └── Database/         # MigrationRunner + Migrations/ (001..024)
├── config/               # app, database, security, xml
├── database/             # migrate.php, seed.php, migrations/, seeds
├── routes/               # web.php, admin.php
├── cron/                 # import_feeds.php, crontab.example
├── storage/              # logs/, imports/, locks/, cache/ (web'den erişilemez)
├── tests/                # run.php (PHPUnit'siz), lint.php, 10 test dosyası + fixtures/
└── docs/                 # 5 doküman (mimari, DB, güvenlik, yol haritası, xml-import)
```

## Yerel Geliştirme

### Gereksinimler
- PHP 8.2+ (`pdo_mysql`, `xmlreader`, `curl`, `fileinfo`, `mbstring`, `openssl` eklentileri)
- MySQL 8+
- (Opsiyonel) Composer — Composer olmadan da çalışır (`app/Core/Autoloader.php` fallback)

### Kurulum

```bash
# 1) Bağımlılıklar (opsiyonel; autoload fallback zaten var)
composer install

# 2) Ortam değişkenleri
cp .env.example .env
# APP_KEY üret:
php -r "echo 'APP_KEY=' . base64_encode(random_bytes(32)) . PHP_EOL;" >> .env
# .env içinde DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD değerlerini düzenleyin

# 3) Veritabanını oluştur (yalnız boş şema)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS xml_ecommerce CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4) Migration'ları çalıştır
php database/migrate.php        # veya: composer migrate
php database/migrate.php status # durum listesi

# 5) İlk seed (admin + örnek kategoriler; tedarikçi verisi bilinçli olarak boş)
php database/seed.php           # veya: composer seed

# 6) Geliştirme sunucusu (yalnız local!)
php -S localhost:8000 -t public
```

> Composer yüklüyse `composer test`, `composer lint`, `composer migrate`, `composer seed` kısayolları kullanılabilir.

## Test Komutları

```bash
php tests/lint.php   # tüm PHP dosyalarında php -l (208 dosya)
php tests/run.php    # test suite (139 test: XML motoru + mağaza)
```

Test suite kapsamı: Router, Validator, XmlUploadValidator (XXE reddi), XmlStreamReader (XXE güvenli akış, nitelik/iç içe alan desteği), Csrf, IpGuard (SSRF IP bloklama), **FieldMapper** (alan eşleme + TR ondalık/para birimi ayrıştırma), **Pricing** (kural motoru — temiz test DB'sinde), **XmlSync** (uçtan uca DB senaryoları: ilk aktarım, kopya engeli, fiyat/stok güncelleme + geçmiş, pasife alma, bozuk XML, XXE, geçersiz satırlar, SKU çakışması, eşzamanlılık kilidi, alan koruması, önizleme yazmaz) ve **MigrationsTest** — migration'lar her koşumda yeni bir temiz veritabanında sıfırdan kurulur, idempotency ve FK RESTRICT davranışı doğrulanır, sonra veritabanı silinir.

> `tests/fixtures/` altındaki XML'ler **sentetik örnek veridir** (gerçek tedarikçi XML'i kullanılmamıştır).

## XML Aktarım Motoru (Aşama 2) — Hızlı Başlangıç

```bash
php database/migrate.php                 # 027 dahil tüm migration'lar (idempotent)
php database/seed.php                    # ilk admin
php database/seed_store.php              # Aşama 3 demo verisi (ÖRNEK veri — gerçek XML ile ezilir)
php -S localhost:8000 -t public          # vitrin: http://localhost:8000/  panel: /admin
# 1) Admin → Tedarikçiler → tedarikçi ekle
# 2) Admin → XML Kaynakları → kaynak ekle (upload veya url)
# 3) Eşleştirme ekranından örnek XML yükle + alan eşleştir
# 4) Önizle → Senkronize Et

php bin/console xml:sync --feed=1        # tek kaynağı senkronize et
php bin/console xml:sync --all           # zamanı gelen tüm kaynaklar (cron kullanımı)
php bin/console xml:preview --feed=1     # eşleme profiliyle önizleme (yazmaz)
```

Modül referansı: `docs/xml-import.md` — alan eşleme hedefleri, senkron kuralları, fiyat kuralı tipleri ve ekran listesi.

## VPS Kurulumu (Ubuntu LTS + Nginx + PHP-FPM)

```bash
# 1) Kod
cd /var/www && git clone <repo-url> xml-ecommerce && cd xml-ecommerce
cp .env.example .env   # production değerleri; APP_ENV=production

# 2) Yetkiler
sudo chown -R www-data:www-data storage/ && sudo chmod -R 750 storage/

# 3) Migration + seed
php database/migrate.php && php database/seed.php

# 4) Nginx sunucu bloğu (örnek):
```

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/xml-ecommerce/public;   # SADECE public/
    index index.php;

    # Hassas yolları blokla (zaten web kökü dışında, ek savunma)
    location ~ /\.(env|git) { deny all; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param HTTPS $https if_not_empty;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## Üretim Kurulumu (VPS) — Aşama 6 Özeti

Adım adım tam kılavuz: **`docs/deployment.md`** (sistem hazırlığı, SSH sertleştirme, ufw, Nginx sunucu bloğu, PHP 8.3 FPM + OPcache, MySQL kullanıcıları, izinler, HTTPS/certbot, cron/logrotate, yaygın hatalar). Yedekleme/kurtarma: **`docs/backup-recovery.md`** · Performans: **`docs/performance.md`** · Canlıya geçiş: **`docs/go-live-checklist.md`** · Üretim env şablonu: **`.env.production.example`**.

```bash
# Deploy (ilk kurulumdan sonraki standart 6 satır):
cd /var/www/xml-ecommerce
git pull --ff-only
composer install --no-dev --optimize-autoloader
php database/migrate.php                 # idempotent (31 migration)
php tests/lint.php && php bin/console doctor   # lint + PASS/WARN/FAIL denetimi
sudo systemctl reload php8.3-fpm
```

```bash
# Sunucuya özgü operasyon komutları:
php bin/console doctor                                    # üretim yapılandırma denetimi (exit 1 = FAIL var)
APP_DIR=/var/www/xml-ecommerce scripts/backup-database.sh # anlık yedek (gece 03:30 cron'da)
APP_DIR=/var/www/xml-ecommerce scripts/restore-database.sh xml_ecommerce [yedek.gz]  # geri yükle (onay sorar)
APP_DIR=/var/www/xml-ecommerce scripts/verify-backup.sh   # ayda 1: yedekten gerçek geri dönüş testi
```

## Cron Yapılandırması

```bash
crontab -e
# Her 10 dk: zamanı gelen kaynaklar (feed'in interval_minutes alanı dahili kontrol sağlar)
# Ayrıntı: cron/crontab.example
*/10 * * * * cd /var/www/xml-ecommerce && /usr/bin/php bin/console xml:sync --all >> storage/logs/cron.log 2>&1
*/10 * * * * cd /var/www/xml-ecommerce && /usr/bin/php bin/console order:expire >> storage/logs/cron.log 2>&1
# AŞAMA 5 — tedarikçi siparişleri, kargo ve bildirimler
*/5  * * * * cd /var/www/xml-ecommerce && /usr/bin/php bin/console supplier:process   >> storage/logs/cron.log 2>&1
*/15 * * * * cd /var/www/xml-ecommerce && /usr/bin/php bin/console supplier:query     >> storage/logs/cron.log 2>&1
*/30 * * * * cd /var/www/xml-ecommerce && /usr/bin/php bin/console shipments:sync     >> storage/logs/cron.log 2>&1
*/5  * * * * cd /var/www/xml-ecommerce && /usr/bin/php bin/console notifications:send >> storage/logs/cron.log 2>&1
```

Kaynak başına ayrıca `FeedLock` (flock) kilidi vardır: aynı kaynak için eşzamanlı aktarım başlatılamaz;
`cron/import_feeds.php` girişi de aynı motoru çalıştırır (ikisinden birini seçin).

## Health-Check

```bash
curl -i http://localhost:8000/health
# 200 {"status":"ok","checks":{"php_version":true,"pdo_mysql":true,...}}
# Sorun varsa 503 {"status":"degraded",...}
```

Yanıt yalnız boolean kontroller içerir — sürüm, host, kimlik bilgisi **sızmaz**. Uzaktan izleme (uptime robot vb.) bu uca bağlanır.

## Güvenlik Özeti

- Tüm SQL: PDO prepared statements (`ATTR_EMULATE_PREPARES=false`)
- CSRF: senkron token + `hash_equals`, tüm POST/PUT/PATCH/DELETE'lerde zorunlu
- Oturum: httponly + samesite=Lax + strict mode + giriş sonrası id yenileme
- Şifre: bcrypt cost 12; 5 hatalı denemede 15 dk hesap kilidi
- XML yükleme: boyut → uzantı → MIME (finfo) → içerik sniff → **DOCTYPE/ENTITY reddi**
- XMLReader: `LIBXML_NONET` + harici varlık yükleyici tamamen kapalı + streaming (XML bomb dayanıklı)
- SSRF: şema/port beyaz listesi, tüm DNS kayıtları public doğrulaması, `CURLOPT_RESOLVE` IP sabitleme (rebinding koruması), manuel redirect doğrulaması (max 3 hop), TLS doğrulaması asla kapalı değil
- Loglar: `storage/logs/app.log`, `storage/logs/security.log` + `audit_logs` tablosu

Detaylar: `docs/security.md`

## Bilinen Sınırlar

| Konu | Durum | Plan |
|---|---|---|
| XML aktarım motoru | **Çalışıyor** — kaynak yönetimi, eşleme, senkron, fiyat kuralları, CLI, cron (Aşama 2) | — |
| Müşteri mağazası | **Çalışıyor** — katalog, arama/filtre, sepet, üyelik, adres, sipariş, SEO (Aşama 3) | — |
| Ödeme (iyzico) | **Entegrasyon tamam (Aşama 4):** Checkout Form + callback + webhook + iptal/iade, mock modda uçtan uca çalışıyor. `IYZICO_MODE=mock` → simülasyon; `sandbox`/`live` + gerçek anahtarla devreye alınır. **Canlı ödeme testi yapılmadı (anahtar yok)** | Canlıya geçiş: `docs/payment-iyzico.md` §11 |
| E-posta gönderimi | **AŞAMA 5: Altyapı TAMAM** — olay temelli kuyruk (dedup UNIQUE → tekrarlı gönderim imkânsız), 7 olay tipi, log/SMTP sürücü, hata logu + tekrar deneme. Varsayılan `MAIL_DRIVER=log` (`storage/logs/mail.log`) | Canlıda `MAIL_DRIVER=smtp` + `SMTP_*` ayarlanır |
| Tedarikçi siparişi (otomatik gönderim) | **AŞAMA 5: Sözleşme + akış TAMAM** — ManualSupplierAdapter varsayılan (sahte entegrasyon yok); idempotency anahtarlı API adaptörü sahte transport ile sözleşme düzeyinde test edildi | Gerçek tedarikçi API belgesi + uç bilgisi girilince `integration_type=api`; **otomatik gönderim gerçek API olmadan doğrulanmış olarak raporlanmaz** |
| Kargo takibi | **AŞAMA 5: Manuel mod TAMAM** — takip numarası manuel girilir; müşteri takip sayfası + admin ekranı canlı doğrulandı | Gerçek kargo API'si → `ShippingAdapterInterface` + `ShippingAdapterFactory::register()` |
| Para birimi çevirimi | Statik katsayılar (`.env`: `XML_RATE_USD` vb.); canlı kur servisi yok | İsteğe bağlı |

> **Demo veri beyanı:** `database/seed_store.php` ile yüklenen ürün/kupon/müşteri verileri **sentetik ÖRNEK veridir**. Gerçek tedarikçi XML'leri admin panelinden senkronlandığında ürünler tedarikçi+SKU eşlemesiyle idempotent güncellenir. Demo müşteri (`musteri@example.test` / `Musteri!2026`) yalnız sandbox içindir.
>
> **Hukuki metin beyanı:** Gizlilik/KVKK, mesafeli satış, ön bilgilendirme ve iade sayfaları **TASLAK**tır; Admin → Mağaza Ayarları'ndan işletme bilgilerinize göre düzenlenmeli ve yayına almadan önce hukuk danışmanına kontrol ettirilmelidir.

## Lisans

Proprietary — tüm hakları saklıdır.
