# XML E-Ticaret — Mimari Dokümanı

Sürüm: 1.0 (Faz 1 teslimi)
Hedef: XML tedarikçi feed'lerinden ürün çeken, stoksuz satış (dropshipping) yapan bağımsız PHP 8.2+ uygulaması.
Not: WordPress/WooCommerce/Node.js KULLANILMAZ. Tüm iş mantığı PHP'de, veri MySQL'de, arayüz Bootstrap 5 + jQuery'dedir.

---

## 1. Genel Bakış

Uygulama **katmanlı (layered) monolit** olarak tasarlanmıştır:

```
HTTP katmanı   → public/ (tek web kökü) + Nginx + PHP-FPM
Çekirdek       → app/Core (Router, Middleware pipeline, DB, View, Session, Error)
Sunum          → app/Controllers + app/Views (+ app/Middleware)
İş mantığı     → app/Services
Veri erişimi   → app/Repositories (yalnızca PDO prepared statements)
Kalıcılık      → MySQL 8+ (utf8mb4, InnoDB)
Arka plan işleri → cron/ (PHP CLI + flock kilidi)
```

Tasarım ilkeleri:

- **Front Controller:** Tüm HTTP istekleri tek giriş noktasından (`public/index.php`) geçer; config, app, storage ve `.env` web kökünün dışındadır.
- **İnce Controller, kalın Service:** Controller yalnızca HTTP ayrıntılarını (request/response, status kodu) yönetir; iş kuralları `Services/` içinde durur.
- **Repository deseni:** SQL yalnızca `app/Repositories/` içinde yazılır; her sorgu PDO prepared statement'tır.
- **Konfigürasyon dizilerle:** `config/*.php` dosyaları dizi döndürür, değerler `Env` sınıfından `.env` üzerinden okunur.
- **PSR-4 otomatik yükleme:** `App\` → `app/`; Composer yoksa `app/Core/Autoloader.php` fallback devreye girer.

---

## 2. Teknoloji Yığını

| Katman | Seçim | Amaç |
|---|---|---|
| Dil | PHP 8.2+ (CLI + PHP-FPM) | Tüm uygulama mantığı |
| Veritabanı | MySQL 8+ / MariaDB (utf8mb4, InnoDB) | Ürün, sipariş, log verisi |
| DB erişim | PDO (prepared statements) | SQL injection'a kapalı tek yol |
| XML | PHP XMLReader (streaming) | Büyük feed'leri belleğe almadan okuma |
| HTTP istemci | cURL (yalnız `UrlFetcher` üzerinden) | Feed indirme; SSRF guard'lı |
| Arayüz | Bootstrap 5 + jQuery | Admin paneli ve vitrin |
| Ödeme | iyzico Checkout Form | **İleri faz** (Faz 4); bu fazda yok |
| Web sunucu | Nginx + PHP-FPM | Statik/PHP ayrımı, güvenlik başlıkları |
| Zamanlama | Linux Cron + PHP CLI | Feed import, senkron işleri |
| Sürüm kontrol | Git | Kaynak yönetimi |
| Test | Bağımsız runner (`tests/run.php` + `Support/Assert.php`) | PHPUnit bağımlılığı yok |

---

## 3. Dizin Ağacı

```
xml-ecommerce/
├── public/                  # Tek web kökü (index.php front controller, assets/)
├── app/
│   ├── Core/                # Çekirdek: Router, Request, Response, Database(PDO), View,
│   │                        #   Session, Logger, ErrorHandler, Env, Config, Csrf,
│   │                        #   Autoloader, helpers.php
│   ├── Controllers/         # HomeController, HealthController, Admin/ (Auth, Dashboard, XmlImport)
│   ├── Models/              # İnce entity sınıfları (Supplier, Product, Category, AdminUser)
│   ├── Services/            # İş mantığı:
│   │                        #   Auth/  → kimlik doğrulama
│   │                        #   Audit/ → denetim kaydı (audit_logs)
│   │                        #   Health/ → health-check kontrolleri
│   │                        #   Http/  → UrlFetcher (SSRF guard'lı tek dış erişim noktası)
│   │                        #   Security/ → IpGuard
│   │                        #   Import/ → XmlStreamReader, XmlUploadManager, FeedImportService
│   ├── Repositories/        # Veri erişimi (BaseRepository + tablo bazlı, yalnız PDO prepared)
│   ├── Middleware/          # SecurityHeaders, Csrf, AdminAuth (MiddlewareInterface)
│   ├── Validators/          # Validator (kural tabanlı) + XmlUploadValidator
│   ├── Views/               # PHP şablonları: layouts/, home/, admin/, errors/ (e() ile kaçış)
│   ├── Exceptions/          # HttpException, ValidationException
│   └── Database/            # MigrationRunner + Migrations/ (001..023)
├── config/                  # app.php, database.php, security.php, xml.php (dizi döndürür, Env okur)
├── database/                # migrate.php (CLI), seed.php, migrations/, seeds/
├── routes/                  # web.php, admin.php (closure döndürür: function(Router $r))
├── cron/                    # import_feeds.php (flock kilidili), crontab.example
├── storage/                 # logs/ (app.log, security.log), imports/, locks/, cache/ — WEB'DEN ERİŞİLEMEZ
├── tests/                   # run.php bağımsız test runner + Support/Assert.php
└── docs/                    # Bu dokümanlar
```

Kritik kural: **Yalnız `public/` Nginx web köküdür.** `storage/`, `config/`, `app/`, `.env` hiçbir URL üzerinden sunulamaz.

---

## 4. İstek Yaşam Döngüsü (HTTP Akışı)

```
Tarayıcı / Cron / API
        │
        ▼
   ┌─────────┐   statik dosya    ┌────────────────┐
   │  Nginx  │──────────────────▶│ public/assets/ │  (CSS, JS, görsel)
   │         │   PHP isteği      └────────────────┘
   │         │────────────────────────────────────────────┐
   └─────────┘   FastCGI                                 │
                                                         ▼
                                            ┌─────────────────────────┐
                                            │  public/index.php       │
                                            │  App::boot:             │
                                            │   1. Env (.env yükle)   │
                                            │   2. Config (diziler)   │
                                            │   3. ErrorHandler       │
                                            │   4. Session (güvenli)  │
                                            └───────────┬─────────────┘
                                                        ▼
                             ┌──────────────────────────────────────────┐
                             │  Middleware pipeline (sırayla):          │
                             │   SecurityHeaders → Csrf → AdminAuth     │
                             └───────────┬──────────────────────────────┘
                                         ▼
         Controller (HTTP ayrıntısı) → Service (iş mantığı) → Repository (PDO prepared)
                                         │
                                         ▼
                                 ┌───────────────┐
                                 │  MySQL 8      │
                                 └───────┬───────┘
                                         ▼
                     Response: HTML (View + e()) veya JSON
```

Akış adımları:

1. **Boot:** `index.php`, `Env` ile `.env`'i yükler, `Config` dizilerini hazırlar, `ErrorHandler`'ı kurar, güvenli `Session` başlatır.
2. **Routing:** `routes/web.php` ve `routes/admin.php` dosyaları `function(Router $r)` closure'ı döndürür; `Router` eşleşen rota + middleware listesini bulur.
3. **Middleware pipeline:** `SecurityHeaders` (CSP, X-Frame-Options, vb.) → `Csrf` (POST'ta sync token doğrulaması) → `AdminAuth` (yalnız admin rotaları; oturum kontrolü).
4. **Controller → Service → Repository:** Controller girdiyi `Validator` ile doğrular, Service iş kuralını çalıştırır, Repository veriyi yazar/okur.
5. **Response:** HTML ise `View` şablonu `e()` kaçış fonksiyonuyla render edilir; API/health uçlarında JSON döner.

---

## 5. XML Import Akışı (Cron veya Admin Upload)

```
        ┌──────────────────────────────┐   ┌──────────────────────────────┐
        │  KAYNAK A: URL feed (cron)   │   │  KAYNAK B: Admin upload      │
        │  cron/import_feeds.php       │   │  Admin\XmlImportController   │
        │  (flock kilidi: storage/locks)│  │  + XmlUploadValidator        │
        └──────────────┬───────────────┘   └──────────────┬───────────────┘
                       │                                  │
                       ▼                                  ▼
        ┌──────────────────────────┐        ┌──────────────────────────┐
        │ Services/Http/           │        │ Services/Import/         │
        │ UrlFetcher               │        │ XmlUploadManager         │
        │  · SSRF guard            │        │  · uzantı+MIME+content   │
        │    - şema/port/IP kontrol│        │    sniff doğrulama       │
        │    - DNS çöz + pin       │        │  · storage/imports/ altına│
        │      (CURLOPT_RESOLVE)   │        │    rastgele-güvenli ad   │
        │    - her redirect hop'ı  │        │  · boyut limiti          │
        │      yeniden doğrulanır  │        └────────────┬─────────────┘
        │    - özel IP bloklu      │                     │
        └──────────────┬───────────┘                     │
                       │                                 │
                       └───────────────┬─────────────────┘
                                       ▼
                       ┌───────────────────────────────┐
                       │ Services/Import/              │
                       │ XmlStreamReader               │
                       │  · XMLReader streaming        │
                       │  · DOCTYPE/ENTITY reddi       │
                       │  · LIBXML_NONET               │
                       │  · libxml_set_external_entity │
                       │    _loader(null)  → XXE kapalı│
                       └───────────────┬───────────────┘
                                       ▼
                       ┌───────────────────────────────┐
                       │ Alan eşleme                   │
                       │  · xml_field_mappings         │
                       │  · tedarikçi başına şablon    │
                       │  · (TAM DEVRE: Faz 2)         │
                       └───────────────┬───────────────┘
                                       ▼
                       ┌───────────────────────────────┐
                       │ FeedImportService             │
                       │  · delta: checksum/hash ile   │
                       │    değişiklik tespiti         │
                       │  · ürün eşleme / merge        │
                       │  · transaction per batch      │
                       └───────────────┬───────────────┘
                                       ▼
                       ┌───────────────────────────────┐
                       │ ProductRepository             │
                       │  · products                   │
                       │  · product_supplier_data      │
                       │    (stok, fiyat, SKU eşleme)  │
                       └───────────────┬───────────────┘
                                       ▼
                       ┌───────────────────────────────┐
                       │ Sonuç kaydı:                  │
                       │  · xml_import_logs            │
                       │  · AuditLogger (audit_logs)   │
                       └───────────────────────────────┘
```

Önemli noktalar:

- **Tek dış erişim noktası:** Uygulamadan dış URL'ye yapılan TÜM istekler `UrlFetcher`'dan geçer; başka hiçbir sınıf cURL kullanamaz. Bu, SSRF savunmasının tek yerde toplanmasını sağlar.
- **XXE savunması üç katmanlı:** (1) DOCTYPE/ENTITY görüldüğünde stream reddedilir, (2) `LIBXML_NONET` bayrağı ağ erişimini kapatır, (3) `libxml_set_external_entity_loader(null)` harici varlık yüklemeyi imkânsız kılar.
- **Streaming:** XMLReader node node okur; 1 GB+ feed'lerde bellek sabit kalır.
- **Delta import:** Feed checksum/hash karşılaştırılarak değişmeyen kayıtlar atlanır (Faz 2'de tam devrede).
- **Gözlemlenebilirlik:** Her import denemesi `xml_import_logs` tablosuna (durum, sayaç, hata) ve `audit_logs`'a yazılır.

---

## 6. Sipariş Akışı (İleri Faz — Faz 4-5)

```
Müşteri
  │ 1. Ürün gezinme → Sepete ekle
  ▼
carts / cart_items ──▶ 2. Checkout (adres formu) ──▶ customers + addresses
  │                                                    │
  │                                                    ▼
  │            3. iyzico Checkout Form (3D Secure; önce sandbox, sonra prod)
  │                                                    │
  │                                                    ▼
  │            webhook_events (IDEMPOTENT: event_id tekil indeks; aynı
  │            bildirim iki kez işlenmez) ──▶ payments (iyzico yanıt snapshot)
  │                                                    │
  │                                                    ▼
  │            4. orders (+ adres JSON snapshot) + order_items
  │               SNAPSHOT KURALI: ürün adı, SKU, birim fiyat sipariş
  │               anındaki haliyle KALICI saklanır; katalog sonradan
  │               değişse bile sipariş belgesi bozulmaz.
  │                                                    │
  │                                                    ▼
  └──▶ 5. Dropshipping: supplier_orders + supplier_order_items
                                                       │
                                                       ▼
            6. Tedarikçiye bildirim (e-posta/API — Faz 5) ──▶ 7. shipments
               (kargo takip no) → durum takibi → müşteriye teslim
```

Tasarım kararları:

- **Idempotent webhook:** iyzico bildirimleri `webhook_events`'e event kimliğiyle yazılır; aynı event tekrar gelirse ikinci işleme alınmaz.
- **Snapshot ilkesi:** `order_items.name/sku/price` ve `orders.address` (JSON) sipariş anı verisini kilitler; `products` tablosundaki sonraki fiyat/stok değişimleri geçmiş siparişleri etkilemez.
- **Para tipleri:** Tedarikçi/birim fiyat `DECIMAL(15,4)`, sepet/sipariş toplamları `DECIMAL(15,2)`. Float kullanılmaz.

---

## 7. Hata Yönetimi

```
PHP hatası / Exception
        │
        ▼
ErrorHandler (app/Core/ErrorHandler.php)
  ├─ set_error_handler        → her uyarı/hata ErrorException'a dönüşür
  ├─ set_exception_handler    → tek merkezi yakalayıcı
  │
  ├─ HttpException (4xx/5xx)  → temiz HTML hata sayfası (Views/errors/)
  │                             veya JSON (istek Accept: application/json ise:
  │                             health / api uçları)
  ├─ ValidationException      → 422 + alan bazlı hata listesi
  └─ Beklenmeyen hata         → 500 + genel mesaj (production'da detay SIZMAZ)
        │
        ▼
Log kanalları (app/Core/Logger.php)
  ├─ storage/logs/app.log       → uygulama hataları, import sonuçları
  └─ storage/logs/security.log  → CSRF ihlali, IpGuard bloğu, şüpheli upload,
                                  başarısız admin girişi vb. güvenlik olayları
```

Kurallar:

- **Production** modda stack trace ve dosya yolları yanıtta görünmez; yalnız log dosyasına yazılır.
- **JSON bekleyen uçlar** (health-check, gelecekteki api/*) hata durumunda bile JSON döndürür; HTML hata sayfası basmaz.
- Tüm exception'lar loglanır; güvenlikle ilgili olanlar `security.log` kanalına ayrılır (log ayrıştırma/alerting kolaylaşır).

---

## 8. Güvenlik Sınırları

| # | Sınır | Uygulama |
|---|---|---|
| a | **Dosya sistemi sınırı** | `public/` tek web kökü; `config/`, `app/`, `storage/`, `.env` web'den erişilemez. `storage/` içindeki uploads/logs için doğrudan URL yok. |
| b | **Veri tabanı sınırı** | SQL yalnız `Repositories/` içinde; her sorgu PDO **prepared statement**. Dinamik SQL birleştirme yok (ORDER BY/LIMIT beyaz listeleriyle). |
| c | **Çıktı sınırı (XSS)** | Tüm şablonda `e()` kaçış fonksiyonu zorunlu; `SecurityHeaders` middleware CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy başlıklarını ekler. |
| d | **Admin sınırı** | Admin rotaları `AdminAuth` middleware + `Csrf` (sync token) arkasında; oturum çerezleri HttpOnly/SameSite, başarısız girişler `security.log`'a. |
| e | **Dış ağ sınırı (SSRF)** | Dış URL erişimi yalnız `Services/Http/UrlFetcher`: şema (http/https) ve port beyaz listesi, DNS çözümleme + `CURLOPT_RESOLVE` ile IP pinleme, redirect döngüsünde **her hop yeniden doğrulanır**, özel IP aralıkları (RFC1918, loopback, link-local, metadata) blokludur (`Security/IpGuard` desteğiyle). |
| f | **XML sınırı (XXE)** | `XmlStreamReader`: DOCTYPE/ENTITY reddi, `LIBXML_NONET`, `libxml_set_external_entity_loader(null)`. |
| g | **Upload sınırı** | `XmlUploadValidator` + `XmlUploadManager`: uzantı + MIME + içerik sniff (fileinfo), boyut limiti, rastgele dosya adı, `storage/imports/` altına kayıt (çalıştırılabilir konum değil). |
| h | **CSRF** | POST formlarında `Csrf` middleware, oturum bazlı sync token. |

---

## 9. Veri Modeli Özeti (23 Migration)

Migration'lar FK bağımlılık sırasına göre 001→023 numaralandırılmıştır; `migrations` meta tablosu MigrationRunner'a aittir.

| Migration | Tablo | Amaç |
|---|---|---|
| 001 | suppliers | XML tedarikçileri (feed URL, aktiflik) |
| 002 | admin_users | Yönetici hesapları (hash'li şifre) |
| 003 | categories | Kategori ağacı (parent_id) |
| 004 | products | Ürün kayıtları (SKU, başlık) |
| 005 | product_variants | Varyantlar (beden/renk) |
| 006 | product_images | Görseller (sıra, yol) |
| 007 | product_supplier_data | Tedarikçi başına stok/fiyat/SKU eşlemesi |
| 008 | price_rules | Kâr marjı / fiyat kuralları |
| 009 | xml_feeds | Feed tanımları (kaynak, zamanlama) |
| 010 | xml_field_mappings | Alan eşleme şablonları (Faz 2'de tam devrede) |
| 011 | xml_import_logs | Import çalışma kayıtları (durum, sayaç, hata) |
| 012 | customers | Müşteri hesapları |
| 013 | addresses | Müşteri adresleri |
| 014 | carts | Aktif sepetler |
| 015 | cart_items | Sepet satırları |
| 016 | orders | Siparişler (+ adres JSON snapshot) |
| 017 | order_items | Sipariş satırları (name/sku/price SNAPSHOT) |
| 018 | payments | Ödeme kayıtları (iyzico yanıtı) |
| 019 | supplier_orders | Tedarikçiye gönderilen dropship siparişleri |
| 020 | supplier_order_items | Dropship sipariş satırları |
| 021 | shipments | Kargo gönderileri + takip numarası |
| 022 | webhook_events | Idempotent webhook kayıtları (iyzico) |
| 023 | audit_logs | Denetim izi (kim, ne, ne zaman) |

Veri tipleri: parasal alanlar `DECIMAL(15,4)` / `DECIMAL(15,2)`; metinler `utf8mb4`; anahtarlar InnoDB + yabancı kısıt.

---

## 10. Loglama ve Gözlemlenebilirlik

- **app.log:** Uygulama hataları, cron import sonuçları, genel olaylar.
- **security.log:** CSRF ihlalleri, IpGuard/SSRF blokları, başarısız admin girişleri, reddedilen upload'lar.
- **audit_logs (DB):** Admin işlemleri ve kritik veri değişiklikleri; sorgulanabilir denetim izi.
- **Health-check:** `HealthController` — DB bağlantısı, yazılabilir storage, PHP eklenti kontrolü; izleme sistemleri için JSON döner (Faz 6'da alerting'e bağlanır).
- **Kilit dosyaları:** `storage/locks/` altında `flock` tabanlı kilitler; aynı cron işinin üst üste binmesini engeller.

---

## 11. Test Stratejisi

- PHPUnit bağımlılığı yok; `tests/run.php` bağımsız runner + `Support/Assert.php` yardımcıları.
- Migration testi **temiz veritabanı** üzerinde çalışır (test DB'si yaratılır, migration'lar koşar, doğrulanır, DB silinir).
- PHP sözdizimi doğrulaması: `php -l` tüm kaynak dosyalarında.
- Hedef: Core sınıfları (Router, Validator, Csrf, UrlFetcher guard mantığı) ve migration bütünlüğü otomatik testle korunur; ekran testleri Faz 2'den itibaren genişler.

---

*Bu doküman Faz 1 teslimine karşılık gelir; Faz ilerledikçe (özellikle Faz 2 XML import ve Faz 4 ödeme) ilgili bölümler güncellenir.*
