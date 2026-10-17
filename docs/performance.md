# Performans Raporu ve Politikası (Aşama 6 §4)

> Ölçüm ortamı: MariaDB 11.4 (portable, MySQL uyumlu), 80.000 satırlık sentetik `products` veri seti, `ANALYZE FORMAT=JSON` (gerçek çalışma istatistikleri: `r_rows`, `pages_accessed`, `r_total_time_ms`). Canlı veri küçük olduğundan ölçekleme verisi sentetik banka ile üretilmiştir; sorgu metinleri uygulamadan birebir alınmıştır (`app/Services/Store/StoreProductService.php`, `app/Repositories/*.php`).

---

## 1. EXPLAIN Analizi (sorgu bazlı bulgular)

### 1.1 Vitrin ürün listeleme (`StoreProductService::listProducts` — anasayfa, kategori, sayfalama)

```sql
SELECT p.id, p.name, ..., c.slug AS category_slug, ...
FROM products p LEFT JOIN categories c ON c.id = p.category_id
WHERE p.status = 'active' AND p.deleted_at IS NULL
ORDER BY p.created_at DESC, p.id DESC LIMIT 24;
```

| Kurulum | Anahtar | r_rows | pages_accessed | süre | filesort |
|---|---|---:|---:|---:|---|
| Aşama 1 şeması (yalnız `idx_products_status`) | idx_products_status | **80.000** | **240.199** | **74,7 ms** | VAR (priority queue) |
| + `idx_products_status_created (status, created_at)` | idx_products_status_created | **24** | **155** | **0,065 ms** | **YOK** |

> **~1150× iyileşme** (74,73 ms → 0,065 ms) ve 240.199 → 155 sayfa erişimi. InnoDB buffer pool sıcakken bile 80k satırlık filesort her sayfa gösteriminde tekrarlanıyordu; indeks sıralı okuma ile sayfa başına yalnız 24 satır okunur. Sayfa 1'den derin sayfalara ve `is_featured DESC, created_at DESC` (anasayfa öne çıkanlar) sıralamasına da aynı indeks sırtlanır.

### 1.2 Kategori filtreli listeleme (`category_id IN (...)` + aynı sıralama)

| Kurulum | Anahtar | r_rows | pages | süre |
|---|---|---:|---:|---:|
| composite varken | idx_products_status_created (ters tarama) | 93 | 414 | 0,14 ms |

Kategori sorgusu composite indeksle de tatmin ediliyor; ek `(category_id, created_at)` indeksi **gerekmedi**.

### 1.3 `payments.token` lookup (callback/webhook idempotency)

```sql
EXPLAIN SELECT * FROM payments WHERE token = 'abc123' LIMIT 1;
-- type: const | key: uniq_payments_token
-- Extra: Impossible WHERE noticed after reading const tables  → direkt indeks vuruşu
```

`028_phase4_payments` migration'ının `UNIQUE` token indeksi callback/webhook ziyaretini O(1) yapar. **Ek indeks gerekmedi.**

### 1.4 `order_items` join (sipariş detayı, admin listeleri)

```sql
EXPLAIN SELECT * FROM order_items WHERE order_id = 1 ORDER BY id ASC;
-- type: ref | key: idx_order_items_order | rows: 1
```

`idx_order_items_order` (FK indeksi) join'i kapsar; `orders` tarafı PK. **Ek indeks gerekmedi.**

### 1.5 `xml_import_logs` admin listesi

```sql
SELECT l.*, f.name AS feed_name, s.name AS supplier_name
FROM xml_import_logs l LEFT JOIN xml_feeds f ON f.id = l.xml_feed_id
LEFT JOIN suppliers s ON s.id = l.supplier_id
WHERE 1=1 ORDER BY l.id DESC LIMIT 20 OFFSET 0;
-- l: type=index, key=PRIMARY (id DESC taraması — PK sıralaması LIMIT'i erken keser)
-- f, s: eq_ref PRIMARY
```

Sıralama PK üzerinden; feed bazlı geçmiş için `idx_xil_feed_created (xml_feed_id, created_at)`, durum filtresi için `idx_xil_status` mevcut. **Ek indeks gerekmedi.**

### 1.6 `order:expire` cron sorgusu

```sql
SELECT id, order_number FROM orders
WHERE status = 'pending_payment' AND placed_at < (NOW() - INTERVAL ? MINUTE) LIMIT 200;
-- type: range | key: idx_orders_status_placed (status, placed_at) → iki sütunlu aralık erişimi
```

Aşama 1'in `(status, placed_at)` kompoziti bu sorguya tam oturuyor. **Ek indeks gerekmedi.**

### 1.7 Arama sorgusu

`MATCH(p.name, p.description) AGAINST(? IN BOOLEAN MODE)` → `FULLTEXT ft_products_name_desc` kullanılır; `LIKE '%terim%'` yedeği leading-wildcard olduğu için indeks kullanamaz (bilinen ve kabul edilmiş davranış — FULLTEXT öncelikli, LIKE yalnız FULLTEXT boş dönerse).

## 2. İndeks Kararı → Migration 031

Denetlenen tablolar: `products, payments, order_items, orders, xml_import_logs, product_supplier_data, customers, categories, xml_feeds, notifications, supplier_orders, webhook_events, product_variants, carts, cart_items, coupons, addresses, shipments, refunds, price_rules, product_price_history, admin_users, suppliers`.

**Sonuç: tek eksik indeks tespit edildi** — `database/migrations/031_phase6_indexes.php`:

```sql
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_status_created (status, created_at);
```

- Idempotent: MariaDB `IF NOT EXISTS` DDL desteğiyle (proje standardı — bkz. `028_phase4_payments`). MySQL 8'de `information_schema.STATISTICS` kontrolüyle koşullu ALTER gerekir (migration docblock'ta notlandı).
- `down()`: `DROP INDEX IF EXISTS`.
- MigrationsTest beklentisi 30 → **31** migration olarak güncellendi (tablo sayısı değişmedi: 29 CREATE + meta = 30).
- Gerçek DB'de uygulandı ve idempotentliği doğrulandı (`migrate.php` ikinci çalıştırma: "şema güncel").

**Kasitli olarak eklenmeyenler:** `is_featured` öne çıkanlar bloğu için ayrı indeks (composite ile 0,1 ms altı), `(category_id, created_at)` (§1.2), arama için n-gram indeks (FULLTEXT mevcut), `stock DESC` sıralama indeksi (nadir seçilen sıralama). Her biri ölçülerek gerek görülmedi — indeks sayısını yazma maliyetiyle (XML senkronu her satırda UPDATE yapar) dengede tutmak bilinçli tercihtir.

## 3. Cron çakışma koruması — iki katman

| Katman | Mekanizma | Nereye uygulanır | Kilit yolu |
|---|---|---|---|
| **1. Kabuk (flock)** | `flock -n /tmp/xxx.lock -c '...'` | `cron/crontab.example` içindeki HER cron satırı | `/tmp/xmlsync.lock`, `/tmp/order-expire.lock`, `/tmp/supplier-process.lock`, ... |
| **2. Uygulama (FeedLock)** | PHP `flock(LOCK_EX\|LOCK_NB)` kaynak başına | `app/Services/Import/FeedLock.php` — `XmlSyncEngine` her senkron öncesi alır | `storage/locks/feed_{id}.lock` |

Kabuk katmanı *aynı cron işinin* üst üste binmesini engeller; uygulama katmanı *farklı giriş noktalarının* (cron + admin paneli + CLI elle çağrı) aynı feed'i eşzamanlı işlemesini engeller. Kilit alınamazsa senkron **güvenli şekilde atlanır** (`status=locked`) — asla paralel yazma olmaz. Yedekleme için aynı desen `cron/backup-daily.sh` içindedir (`/tmp/xml-ecommerce-backup.lock`).

> Kilit dosyaları `/tmp` (cron) ve `storage/locks` (uygulama) altındadır; sunucu yeniden başlatınca cron kilitleri kendiliğinden temizlenir.

## 4. Zaman aşımı / bellek ayarları

| Ayar | Mevcut değer (`config/xml.php` / `.env`) | Açıklama | Öneri |
|---|---|---|---|
| `XML_FETCH_TIMEOUT` | **30 sn** | Feed indirme (URL) toplam zaman aşımı (SSRF-güvenli UrlFetcher) | Yavaş tedarikçi için 60'a kadar çıkılabilir; FPM `request_terminate_timeout` (300s) altında kalmalı |
| `XML_MAX_MEMORY_MB` | **256 MB** | Akış işlemenin bellek üst sınırı — aşıldığında senkron durdurulur | PHP `memory_limit` bunun ÜSTÜNDE olmalı: FPM 256M, **CLI 512M** |
| `XML_SYNC_BATCH` | **50** | Parçalı idempotent upsert blok boyutu | 100k+ satırlık feed'lerde 100-200 denenebilir; her blok kendi transaction'ı → yarı yolda koparsa tamamlanan bloklar güvenli |
| `XML_UPLOAD_MAX_MB` | **100 MB** | Admin XML yükleme üst sınırı | Nginx `client_max_body_size 120M` + `upload_max_filesize=120M`, `post_max_size=125M` |

**PHP-FPM (üretim):** `pm.max_children` bellek hesabıyla (bkz. `docs/deployment.md` §5), `request_terminate_timeout=300`, `max_execution_time=300`.
**PHP CLI (cron):** `memory_limit=512M`, `max_execution_time=0` (cron işi kendi mantığıyla durur; FeedLock + flock zaten üst üste binmeyi önler).
**MySQL:** InnoDB buffer pool'u VPS RAM'inin %40-50'sine çıkarın (`innodb_buffer_pool_size`); 80k+ ürün kataloğu için ≥ 1G önerilir.

## 5. Önbellek politikası

### 5.1 Mevcut durum (denetim)

- Projede genel amaçlı bir **Cache sınıfı yoktur**; `storage/cache/` yalnızca iyzico **mock** istemcisinin simülasyon artefaktlarını (`cache/mock-iyzico/*.json`) içerir — bu üretimde hiçbir sayfa yanıtını etkilemez.
- Ürün/fiyat/stok okumaları her istekte **doğrudan DB'den** gelir (`StoreProductService` + PDO prepared). Riskli (bayat veri sunan) bir cache **bilinçli olarak bulunmamaktadır** ve bu Aşama 6'da davranış değişikliği riskine girmemek için **kod eklenmedi**.

### 5.2 Yasa — asla önbelleğe alınmayacaklar

Aşağıdaki veriler **KESİNLİKLE cache'lenemez** (yanlış güncellik = yanlış fiyat/yanlış stok = hukuki ve ticari risk):

| Yasak alan | Neden |
|---|---|
| **STOK** (`products.stock`, `product_variants.stock`, `stock_status`, `is_stale`) | Dropshipping'de stok tedarikçi feed'iyle değişir; bayat stok gösterimi satılamayan ürünün satışını doğurur. |
| **SEPET** (carts, cart_items, sepet toplamları, kupon doğrulaması) | Sunucu-taraflı hesap anlık fiyatla yapılır (`CartService`); cache'li fiyat → farklı toplam. |
| **ÖDEME** (payments, iyzico callback/webhook cevapları, ödeme form token'ları) | Finansal doğru-olma şartı; `FOR UPDATE` + token UNIQUE ile DB gerçek kaynaktır. |
| **SİPARİŞ** (orders, order_items, durum geçişleri, supplier_orders) | Durum makinesi audit'li geçişlerle çalışır; cache'li durum iptal/iade akışını bozar. |
| Oturum, CSRF, yetki kontrolleri | Güvenlik doğrulamaları her istek tazedir. |

### 5.3 Güvenli alanlar — TTL cache önerisi (canlıya geçiş sonrası iyileştirme)

Bunlar okuma-yoğun ve değişim seyrek alanlardır; bir gün eklenirse TTL'li (60-300 sn) uygulama içi cache uygun olur:

1. **Kategori ağacı** (`CategoryRepository::activeTree` — her vitrin sayfasında breadcrumb+sidebar için okunur): TTL 300 sn + admin kategori CRUD sonrası invalidate.
2. **Anasayfa öne çıkanlar / en yeniler bloğu**: TTL 60-120 sn — ürün kartlarında stok bilgisi göründüğünden TTL kısa tutulmalı VEYA cache yalnız ürün ID listesi tutup kartları anlık DB'den kurmalı (önerilen).
3. **Settings satırları** (mağaza adı, staleStockAction vb.): TTL 300 sn + ayar ekranında invalidate.
4. **Sitemap.xml**: üretimi 1 saatte bir dosyaya yaz, statik servis et.

> Kural ne olursa olsun: ürün kartında **fiyat/stok görünen hiçbir blok** cache'lenemez; cache yalnız yapısal (kategori ağacı, URL listesi) veriye uygulanır.

## 6. Görseller

**Mevcut durum (view denetimi):**

| View | loading="lazy" | decoding="async" | width/height |
|---|---|---|---|
| `app/Views/store/partials/_product_card.php` | VAR | VAR | **YOK → eklendi (600×600, CSS aspect-ratio 1/1 ile tutarlı)**
| `app/Views/store/product/show.php` (ana görsel) | — (hero görsel, lazy YAPILMAZ) | VAR | **YOK → eklendi (600×600)** |
| `app/Views/store/product/show.php` (küçük resimler) | VAR | VAR | **YOK → eklendi (64×64, CSS boyutuyla birebir)** |

`width`/`height` nitelikleri tarayıcıya CLS (cumulative layout shift) önlemek için iç oran verir; CSS `width:100%/object-fit:cover` ve `aspect-ratio:1/1` zaten baskın olduğundan görsel görünüm değişmez — yalnız kaydırma zıplaması engellenir.

**Import sırasında görsel optimizasyonu (GD thumbnail) — planlandı (kod yazılmadı):**
Feed'lerde gelen görseller genelde tedarikçinin orijinal (800-2000 px) JPEG'leridir. Şu an uygulama orijinal URL'i `product_images.path`'e kaydeder ve Proxy'siz servis eder. **Canlıya geçiş sonrası iyileştirme planı:** XML senkronunda görsel ilk kez görüldüğünde GD ile `800×800` display + `300×300` thumb türevi üret, `storage/media/{hash}` altına yaz ve `product_images`'e türev yolu ekle; WebP varyantı + `srcset`. Bu, disk yönetimi (retention) ve Nginx `location ^~ /media` bloğu gerektirdiğinden ayrı bir iş kalemi olarak planlanmıştır — bu Aşama 6'da davranış değişikliği yapılmadı.

## 7. Streaming kanıtı — XML bellek profili

`app/Services/Import/XmlStreamReader.php` **XMLReader** tabanlı akış parser'dır: dosya diskten düğüm düğüm okunur, **tüm XML belleğe alınmaz**; `XXE` savunması (LIBXML_NONET + harici varlık yükleyici kapalı + DOCTYPE reddi) akış sırasında uygulanır. `XmlSyncEngine` her ürün düğümünü `XML_SYNC_BATCH` (50) satırlık idempotent bloklarla DB'ye yazar; `XML_MAX_MEMORY_MB=256` sınırı aşılırsa senkron güvenli durdurulur. Bu nedenle 100 MB + XML feed'i ~256 MB PHP belleğiyle işlenebilir — "dosya boyutu ≈ bellek gereksinimi" varsayımı bu mimaride geçersizdir (dosya boyutu belleği sürmez).

---

**İlgili:** `docs/deployment.md` (FPM/OPcache/MySQL ayarları) · `docs/backup-recovery.md` (yedek cron'u, flock) · `database/migrations/031_phase6_indexes.php` (ölçüme dayalı indeks).
