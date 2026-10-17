# Veritabanı Tasarımı — XML E-Ticaret (Aşama 1)

MySQL 8+ / InnoDB / `utf8mb4_unicode_ci`. Tüm DDL `database/migrations/` altında, `App\Database\MigrationRunner` ile yürütülür ve `migrations` meta tablosuna kaydedilir.

## 1. Genel Kurallar

| Kural | Uygulama |
|---|---|
| Motor / Charset | `ENGINE=InnoDB`, `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` |
| Para (birim/tedarikçi fiyatı) | `DECIMAL(15,4)` — XML feed'lerde küsuratlı fiyatlar kaybolmasın |
| Para (toplamlar: sepet, sipariş, ödeme) | `DECIMAL(15,2)` |
| Vergi oranı | `DECIMAL(5,2)` (yüzde) |
| Koli/float para | **Yasak** — float/double hiçbir yerde kullanılmaz |
| Birincil anahtar | `id BIGINT UNSIGNED AUTO_INCREMENT` |
| Zaman damgası | `created_at` / `updated_at TIMESTAMP NULL DEFAULT NULL` (uygulama doldurur) |
| Durum alanları | `VARCHAR(20-30)` + uygulama tarafında sınıf sabitleri (ENUM yerine; şema değişikliği gerektirmez) |
| Silme stratejisi | Ticari kayıtlarda (orders, payments, supplier_orders) **hard delete FK ile engellenir**; products/categories soft-delete (`deleted_at`) |
| FK adlandırma | `fk_{tablo}_{kolon}`, index: `idx_{tablo}_{kolonlar}`, unique: `uniq_{tablo}_{kolonlar}` |
| FK kuralları | Çocuk kayıt (item/image) → `CASCADE`; referans kayıp kabul edilemez (order→customer, payment→order) → `RESTRICT`; opsiyonel bağ (order→product silinmiş olsa da satır kalsın) → `SET NULL` |
| Anlık görüntü (snapshot) | Sipariş anındaki ürün adı/SKU/fiyat `order_items` içinde **kalıcı** saklanır; fatura/kargo adresi `orders` içinde JSON snapshot |

## 2. Varlık İlişkileri (özet ER)

```text
suppliers 1─┬─* xml_feeds 1─* xml_field_mappings
            ├─* xml_import_logs
            ├─* product_supplier_data *─1 products
            ├─* price_rules
            └─* supplier_orders *─1 orders

categories (self FK parent_id) 1─* products 1─┬─* product_images
                                              ├─* product_variants
                                              └─* cart_items *─1 carts *─1 customers 1─* addresses

customers 1─* orders 1─┬─* order_items ─(snapshot)─ products/variants
                       ├─1..* payments
                       └─1..* supplier_orders 1─* supplier_order_items *─1 order_items
                                              1─* shipments

webhook_events (provider + external_id unique → idempotency)
audit_logs (admin_users SET NULL, actor_type= admin|system|customer)
```

## 3. Tablolar (migration sırası)

### 001 suppliers — Tedarikçiler
`code` UNIQUE, `name`, iletişim alanları, `is_active`. Dropshipping'in merkezi varlığı; silme `RESTRICT` ile korunur (geçmiş sipariş bağları kopmaz).

### 002 admin_users — Yöneticiler
`email` UNIQUE, `password_hash` (bcrypt, PHP `password_hash`), `role` (`superadmin|admin|viewer`), `status`, `last_login_at`, `failed_attempts INT`, `locked_until DATETIME` (brute-force kilidi AuthService tarafından yönetilir).

### 003 categories — Kategoriler
`parent_id` self FK (`ON DELETE SET NULL`), `slug` UNIQUE, `xml_category_code` (feed eşlemesi için, indexed), `sort_order`.

### 004 products — Ürünler
`sku` UNIQUE, `slug` UNIQUE, `category_id` FK `SET NULL`, `barcode`, `brand`, `description TEXT`, `currency CHAR(3)`, `list_price`/`sale_price DECIMAL(15,4)`, `tax_rate DECIMAL(5,2)`, `stock INT`, `stock_status` (`in_stock|out_of_stock|backorder`), `status` (`draft|active|passive`), `source_type` (`manual|xml`), `deleted_at` (soft delete).
İndeksler: `idx_products_status`, `idx_products_category`, `idx_products_barcode`; arama için `FULLTEXT (name, description)`.

### 005 product_variants — Varyantlar
`product_id` FK `CASCADE`, `sku` UNIQUE, `attributes JSON` (beden/renk vb.), `price DECIMAL(15,4) NULL` (override), `stock`, `status`.

### 006 product_images — Görseller
`product_id` FK `CASCADE`, `path` (lokal relative path), `source_url` (feed kaynağı), `is_primary`, `sort_order`, `checksum CHAR(64)` (source_url SHA-256) → `UNIQUE(product_id, checksum)` ile tekrar indirme engellenir.

### 007 product_supplier_data — Tedarikçi başına ürün verisi
`UNIQUE(product_id, supplier_id)`; `supplier_sku`, `supplier_price DECIMAL(15,4)`, `supplier_currency`, `stock`, `stock_updated_at`, `lead_time_days` (dropshipping teslim süresi), `supplier_status` (feed'deki ham durum). Bir ürünün birden çok tedarikçisi olabilir; aktif tedarikçi seçimi fiyat/kural mantığına aittir.

### 008 price_rules — Fiyat kuralları
`scope` (`global|category|product|variant`), `type` (`markup_percent|markup_fixed|sale_price`), `value DECIMAL(15,4)`, `min_qty`, `starts_at/ends_at`, `priority`, `is_active`. Opsiyonel FK'lar: `product_id`, `category_id`, `variant_id`, `supplier_id` (uygun olanlar; `CASCADE`/`SET NULL`).

### 009 xml_feeds — XML kaynakları
`supplier_id` FK `RESTRICT`, `UNIQUE(supplier_id, name)`; `source_type` (`upload|url`), `source_url`, `auth_type` (`none|basic|header`) + `auth_username`/`auth_password` (**app katmanında APP_KEY ile AES-256-GCM şifreli saklanır**), `encoding`, `product_node`, `interval_minutes` (cron sıklığı), `max_file_size_mb`, `is_active`, `last_import_at`.

### 010 xml_field_mappings — Alan eşlemeleri
`xml_feed_id` FK `CASCADE`; `source_path` (feed alanı, örn. `title`, `images/image`), `target_field` (örn. `product.name`, `product.price`, `product.stock`), `field_type` (`text|decimal|int|html|image|category`), `transform` (örn. `trim|lowercase`), `default_value`, `is_active`. `UNIQUE(xml_feed_id, source_path, target_field)`.

### 011 xml_import_logs — İthalat günlükleri
`xml_feed_id`/`supplier_id` FK `SET NULL` (feed silinse bile log kalsın), `file_name`, `source` (`upload|url|cron`), `status` (`running|success|partial|failed`), sayaçlar: `total_rows`, `created_rows`, `updated_rows`, `skipped_rows`, `error_rows`, `error_message TEXT`, `started_at`, `finished_at`, `duration_ms`.
İndeksler: `idx_xil_feed_created (xml_feed_id, created_at)`, `idx_xil_status`.

### 012 customers — Müşteriler
`email` UNIQUE, `password_hash NULL` (misafir checkout desteklenir), `phone`, `status`, `email_verified_at`, `last_login_at`.

### 013 addresses — Adresler
`customer_id` FK `CASCADE`; `type` (`billing|shipping`), ülke/shehir/ilçe/posta, `line1/line2`, `is_default`. **Siparişler adres satırına FK yerine JSON snapshot da yazar** — müşteri adresini selse bile sipariş verisi bozulmaz.

### 014 carts — Sepetler
`customer_id` FK `SET NULL` (misafir sepeti), `session_token CHAR(64)` UNIQUE (oturuma rastgele token; oturum id'si saklanmaz), `status` (`active|converted|abandoned`), `currency`, `expires_at`.

### 015 cart_items — Sepet kalemleri
`cart_id` FK `CASCADE`, `product_id` FK `RESTRICT` (ürün soft-delete), `variant_id` FK `SET NULL`, `quantity`, `unit_price DECIMAL(15,4)` (ekleme anındaki fiyat; checkout'ta yeniden doğrulanır).

### 016 orders — Siparişler
`order_number VARCHAR(20)` UNIQUE (`ORD-YYYYMMDD-XXXXX`), `customer_id` FK `RESTRICT`, `status` (`pending|paid|processing|shipped|delivered|cancelled|refunded|payment_failed`), para alanları `items_total`, `discount_total`, `shipping_total`, `tax_total`, `grand_total DECIMAL(15,2)`, `currency`.
**Kalıcı snapshot:** `shipping_snapshot JSON`, `billing_snapshot JSON` (adres verisinin sipariş kopyası). Ayrıca `shipping_method`, `note`, `ip_address VARCHAR(45)`, `user_agent`, `placed_at`, `paid_at`.
İndeksler: `idx_orders_status_placed (status, placed_at)`, `idx_orders_customer`.

### 017 order_items — Sipariş kalemleri
`order_id` FK `CASCADE`; `product_id`/`variant_id` FK `SET NULL`; **snapshot kolonları:** `product_name_snapshot`, `sku_snapshot`, `unit_price DECIMAL(15,4)`, `tax_rate DECIMAL(5,2)`, `tax_amount DECIMAL(15,2)`, `line_total DECIMAL(15,2)`, `currency`, `quantity`, `supplier_id FK SET NULL` (varsayılan dropship yönlendirmesi).
Ürün/supplier silinse bile fatura verisi bozulmaz.

### 018 payments — Ödemeler
`order_id` FK `RESTRICT` (finansal kayıt korunur), `provider VARCHAR(30)` (Faz 4: `iyzico`), `provider_payment_id`, `conversation_id`, `status` (`initiated|three_d_pending|authorized|captured|failed|refunded|partially_refunded`), `amount DECIMAL(15,2)`, `currency`, `installment`, `card_last_four CHAR(4)`, `card_association` (PAN asla saklanmaz), `error_code`, `error_message`, `raw_response JSON` (tokanize alanlar dışı veri yazılmaz), `initiated_at`, `completed_at`.
`UNIQUE(provider, provider_payment_id)` + `idx_payments_order (order_id, status)`.

### 019 supplier_orders — Tedarikçi siparişleri (dropship)
`order_id` FK `RESTRICT`, `supplier_id` FK `RESTRICT`, `UNIQUE(order_id, supplier_id)` (sipariş başına tedarikçi başına tek satır); `status` (`pending|sent_to_supplier|acknowledged|shipped|delivered|cancelled`), `external_reference`, `sent_at`.

### 020 supplier_order_items — Tedarikçi sipariş kalemleri
`supplier_order_id` FK `CASCADE`, `order_item_id` FK `RESTRICT` + `UNIQUE` (her sipariş kalemi yalnız bir tedarikçi siparişine bağlanır), `supplier_sku`, `quantity`, `unit_cost DECIMAL(15,4)` (maliyet snapshot), `line_total DECIMAL(15,2)`.

### 021 shipments — Kargo gönderileri
`supplier_order_id` FK `CASCADE`, `carrier`, `tracking_number`, `tracking_url`, `status` (`preparing|in_transit|delivered|returned|failed`), `shipped_at`, `delivered_at`. İndeks: `idx_shipments_tracking`.

### 022 webhook_events — Webhook kayıtları (idempotency)
`provider`, `event_type`, `external_id` (iyzico token) → `UNIQUE(provider, external_id)` sayesinde aynı event iki kez işlenmez; `payload JSON`, `signature_valid TINYINT(1)`, `status` (`received|processed|failed|ignored`), `processed_at`, `error_message`.
İndeks: `idx_webhook_status_created (status, created_at)`.

### 023 audit_logs — Denetim izleri
`admin_user_id` FK `SET NULL`, `actor_type` (`admin|system|customer`), `action VARCHAR(100)` (örn. `admin.login`, `admin.login_failed`, `feed.imported`, `xml.uploaded`), `entity_type`, `entity_id`, `ip_address`, `user_agent`, `context JSON`.
İndeksler: `idx_audit_user_created`, `idx_audit_action`, `idx_audit_entity (entity_type, entity_id)`.

### `migrations` (runner'a ait meta tablo)
`migration VARCHAR(190) UNIQUE`, `batch INT`, `applied_at TIMESTAMP` — idempotent çalıştırma sağlar.

## 4. Tutarlılık Notları

1. **Para aritmetiği** uygulama tarafında tam sayı kuruş bazında ya da `bcmath` ile yapılır; SQL'de `SUM()` sonuçları `DECIMAL` döner, float'a çevrilmez.
2. **Stok:** stoksuz modelde `products.stock` satış anında `product_supplier_data.stock` ile doğrulanır; feed senkronu yazan tek yazardır (cron tek kilitle çalışır).
3. **FK silme matrisi** yukarıdaki kurallara göredir; `ORDER`/`PAYMENT`/`SUPPLIER_ORDER` silinmesi `RESTRICT`'lerle fiilen engellenir — iptal `status` değişikliğidir.
4. Tüm tablolar `App\Database\Migrations\*` sınıflarında, her sınıf `up()/down()` içerir; `down()` yalnız geliştirme içindir.
