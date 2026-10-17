# AŞAMA 5 Raporu — Tedarikçi Siparişleri, Kargo ve Bildirimler

> Durum: **TAMAMLANDI** — Aşama 1-4 kodu korunarak genişletildi. 166/166 test yeşil, lint temiz, tarayıcı e2e doğrulandı.
> Bu rapor: uygulanan kapsam, test sonuçları ve **canlıya geçiş (go-live) için gereken ayarlar**.

Tam yol: docs/phase5-report.md · Tarih: 2026-10-17 · Ortam: PHP 8.3.32 + MariaDB 11.8.6 (portable)

---

## 1. Kapsam (görev gereklilikleri ↔ uygulama)

### 1.1 Tedarikçi siparişleri
- Ödemesi **sunucu tarafında doğrulanmış** siparişlerdeki kalemler tedarikçilere göre gruplanır; her tedarikçi için **ayrı `supplier_order`** oluşturulur (`OrderService::createSupplierOrdersLocked`, `UNIQUE(order_id, supplier_id)` + `INSERT IGNORE` → mükerrer kayıt veritabanı düzeyinde imkânsız).
- Her tedarikçi siparişi şunları içerir:
  - **Ana sipariş numarası** (`orders.order_number` — join ile),
  - **Tedarikçi kimliği** (`supplier_id`),
  - **Tedarikçi ürün kodları** (`supplier_order_items.supplier_sku` — öncelik tedarikçinin KENDİ kodu `product_supplier_data.supplier_sku`),
  - **Miktarlar** + **alış maliyetleri** (`unit_cost` = `supplier_price` snapshot; yoksa satış fiyatı snapshot),
  - **Teslimat bilgileri** (`delivery_snapshot` = ana siparişin teslimat snapshot'ı),
  - **İletim durumu** (`status`: pending→sending→sent→confirmed, error/unknown/cancelled),
  - **Tedarikçi referans numarası** (`external_reference`),
  - **Hata mesajları** (`error_message`), **gönderim zamanı** (`sent_at`) ve **durum güncelleme zamanı** (`status_updated_at`),
  - **Benzersiz dahili referans** (`internal_reference`, UNIQUE index — §1.3).

### 1.2 Tedarikçi entegrasyonları — SupplierAdapter
- `App\Services\Supplier\SupplierAdapterInterface`: `supportsApi()`, `sendOrder()`, `queryStatus()`, `fetchTracking()`.
- **ManualSupplierAdapter**: API bilgisi olmayan tedarikçi için **sahte otomatik entegrasyon ÜRETİLMEZ** (görev gereği). Otomatik gönderim yapılmaz; sipariş yönetim panelinde **bekleyen görev** olarak kalır, yönetici iletip durumu manuel işaretler.
- **ApiSupplierAdapter**: YALNIZ yöneticinin gerçek uç (`https://` zorunlu) + kimlik bilgisi girdiği tedarikçilerde devreye girer. Kimlik doğrulama: none/bearer/basic/özel başlık.
- **Kimlik bilgileri güvenli saklanır**: `Cipher` (APP_KEY, AES-256-GCM) ile şifreli (`suppliers.api_credentials_enc`); panelde asla geri gösterilmez (write-only form + "kayıtlı/yok" rozeti).

### 1.3 Tekrarlı sipariş önleme
- Her `supplier_order` için **benzersiz dahili referans**: `SORD-{sipariş no}-S{tedarikçi id}` — deterministik + UNIQUE index (`uniq_supplier_orders_internal_ref`).
- API'ye **idempotency anahtarı** gönderilir: gövdede `idempotency_key` + `Idempotency-Key` başlığı (aynı değer).
- **Zaman aşımı**: gönderim iki aşamalıdır (tx-A: `pending→sending` + `attempts++`; HTTP çağrısı tx DIŞINDA; tx-B: sonuç). Zaman aşımında kayıt `unknown` olur, **doğrudan tekrar gönderilmez** — önce durum sorgusu (`supplier:query` / panel butonu), sorguda tedarikçide kayıt bulunamazsa **manuel inceleme** işareti. Çökmüş `sending` kayıtları da grace süresi sonrası yalnız sorgu yoluna alınır.
- **Ödeme başarısızsa gönderim yok**: tedarikçi siparişi yalnız ödeme doğrulandıktan sonra oluşturulur; kuyruk (`pendingSendIds`) yalnız `paid/processing/supplier_pending` siparişleri işler.
- **Mükerrer tedarikçi siparişi**: UNIQUE(order_id, supplier_id) + INSERT IGNORE + gönderim yalnız `pending` durumundan satır kilidiyle başlar (çift işleyici koruması).

### 1.4 Kargo yönetimi
- `shipments` (tedarikçi siparişi başına tek gönderi): **kargo firması, takip numarası, gönderi durumu** (preparing→shipped→in_transit→delivered; exception/returned), **kargoya veriliş tarihi**, **teslim tarihi**; `(carrier, tracking_number)` UNIQUE.
- **Müşteri sipariş takip sayfası**: `/hesabim/siparisler/{no}` — paket paket kargo kartı (firma, takip no, tarihler, takip bağlantısı).
- **Yönetici kargo düzenleme ekranı**: `/admin/shipments` (list) + `/admin/shipments/{id}` (form; geçersiz geçişler arayüzde de kapalı, sunucuda da reddedilir).
- **Gerçek kargo API'si yoksa manuel takip numarası girişi** (varsayılan davranış); gerçek API bağlandığında `ShippingAdapterInterface` + `ShippingAdapterFactory::register()` + `shipments:sync` cron'u otomatik devreye girer.

### 1.5 Bildirimler
- `notifications` tablosu + **dedup_key UNIQUE** → aynı olay için tekrarlı bildirim **imkânsız** (callback + webhook aynı olayı tetiklese bile tek satır).
- Olaylar: `order.received`, `payment.confirmed`, `order.sent_to_supplier`, `order.shipped`, `order.delivered`, `order.cancelled`, `refund.status_changed.*` (talep/tamam/red/hata).
- **Gönderim hataları loglanır** (`last_error`, `attempts++`, app.log `notification.send_failed`) ve kuyrukta kalır; `notifications:send` cron'u tekrar dener (max 5 deneme).
- Sürücü: `MAIL_DRIVER=log` (varsayılan; `storage/logs/mail.log`) | `smtp` (fsockopen SMTP, STARTTLS/AUTH LOGIN).

### 1.6 Yönetim paneli
- `/admin/supplier-orders`: **tedarikçiye iletilecek siparişler** (pending), **API gönderim hataları** (error), **manuel işlem bekleyen** görev sayacı, **durum belirsiz** (unknown) filtreleri + tedarikçi/arama filtresi.
- Detay `/admin/supplier-orders/{id}`: kalemler + alış maliyetleri, teslimat snapshot, deneme sayısı, aksiyonlar (API'ye gönder / **manuel gönderildi işaretle** / **durum sorgula** / yeniden kuyruğa al).
- `/admin/shipments`: **kargo takip ekranı** + düzenleme.
- `/admin/reports/suppliers`: **tedarikçi bazında sipariş raporları** (durum kırılımı, alış maliyeti toplamı, ort. gönderim süresi) + bildirim kuyruğu özeti + son bildirimler.
- Tedarikçi formu: **entegrasyon tipi + API ucu + kimlik bilgileri** (şifreli).
- Sipariş durumu geçmişi: mevcut admin sipariş detayındaki denetim izi (Aşama 4) + `supplier_order.status_updated_at`/audit `supplier_order.*` olayları.

### 1.7 CLI + Cron (Linux crontab örnekleri)

```cron
*/5  * * * * cd /var/www/xml-ecommerce && php bin/console supplier:process   >> storage/logs/cron.log 2>&1
*/15 * * * * cd /var/www/xml-ecommerce && php bin/console supplier:query     >> storage/logs/cron.log 2>&1
*/30 * * * * cd /var/www/xml-ecommerce && php bin/console shipments:sync     >> storage/logs/cron.log 2>&1
*/5  * * * * cd /var/www/xml-ecommerce && php bin/console notifications:send >> storage/logs/cron.log 2>&1
```

---

## 2. Test sonuçları

### 2.1 Otomatik testler — `php tests/run.php`
**SONUÇ: 166 geçti, 0 başarısız, 0 atlandı** (155 önceki + 11 yeni). `tests/lint.php`: 267 dosya / 0 hata.

Yeni dosya `tests/Phase5SupplierShippingTest.php` (izole DB `xml_ecommerce_test5_supplier`, sahte transport ile deterministik):

| # | Senaryo | Sonuç |
|---|---------|-------|
| 1 | Siparişin **birden fazla tedarikçiye bölünmesi** (2 supplier_order, doğru kalemler, tedarikçi kodu, alış maliyeti 40/25, teslimat snapshot, deterministik dahili referanslar) | ✓ |
| 2 | **Ödeme koruması**: payment_failed siparişte tedarikçi siparişi hiç oluşmaz; refund_pending siparişin kaydı kuyruktan işlenmez (attempts=0) | ✓ |
| 3 | **Manuel tedarikçi**: otomatik gönderim yok → panel görevi → manuel işaretleme → sipariş `supplier_pending` | ✓ |
| 4 | **API başarı**: idempotency anahtarı gövde + başlıkta; tekrarlı processOne çift gönderim/bildirim YAPMAZ | ✓ |
| 5 | **Başarısız tedarikçi API çağrısı** (HTTP 503): `error` + hata saklanır; retry → pending; 2. denemede gönderilir, tek kayıt | ✓ |
| 6 | **Zaman aşımı**: `unknown` — otomatik yeniden gönderim YOK (attempts değişmez); **durum sorgusu** ile confirmed + sipariş `supplier_pending` | ✓ |
| 7 | **Çökmüş `sending` kurtarma**: grace süresi aşımı → yalnız sorgu yolu; tedarikçide kayıt yok → manuel inceleme işareti | ✓ |
| 8 | **Manuel kargo girişi**: takip bilgisi zorunlu; sipariş `shipped`→`delivered` eşitlemesi; mükerrer takip numarası reddi; geçersiz geçiş reddi | ✓ |
| 9 | **İptal zinciri**: ana sipariş iptali → gönderilmemiş tedarikçi siparişleri `cancelled` + iptal bildirimi | ✓ |
| 10 | **Bildirim tekrarları**: 3 çağrı → 1 kayıt (dedup UNIQUE); SMTP hatası → attempts++/last_error + kuyrukta kalır → sonra gönderilir | ✓ |
| 11 | Adaptör sözleşmesi: ManualSupplierAdapter asla sahte başarı üretmez; API payload gizli anahtar İÇERMEZ | ✓ |

Mevcut tüm testler tekrar koşuldu (Aşama 1-4: migration/katalog/sepet/üyelik/checkout/SEO/ödemeler) — **hepsi yeşil**; yalnız migration sayısı doğrulamaları 28→30 güncellendi (029 ALTER-only, 030 notifications).

### 2.2 Tarayıcı e2e (Agent Browser, 390px + masaüstü)
Sipariş `ORD-20261017-932E1`: kayıt → sepet → checkout (91,90 ₺) → mock ödeme success → admin panelde **1 manuel işlem bekliyor** → detayda manuel işaretleme (OKT-2026-88741) → sipariş `supplier_pending` → kargo düzenleme (Yurtiçi, YK-2026-5551234) → sipariş `shipped` → teslim işaretleme → sipariş `delivered` → **müşteri takip sayfasında paket kartı** (firma/takip no/tarihler/bağlantı) → raporlar + bildirim kuyruğu (4 olay `sent`). Mobil render doğrulandı.

### 2.3 Dürüstlük beyanı (görev gereği)
- **Gerçek tedarikçi API'si olmadan otomatik gönderim DOĞRULANMIŞ olarak RAPORLANMAZ.** API gönderim yolu yalnız **sahte transport ile** (sözleşme düzeyinde) test edilmiştir; gerçek bir tedarikçi API'si bağlanana kadar otomatik gönderim canlıda kullanılmamalıdır.
- Gerçek kargo firması API'si de bağlı değildir: kargo takibi **manuel** modda doğrulandı.
- Varsayılan entegrasyon tipi HER TEDARİKÇİDE `manual`'dir; API modu yalnız gerçek uç + kimlik bilgisi girildiğinde etkinleşir.

---

## 3. Canlıya geçiş için gereken ayarlar

### 3.1 Tedarikçi API entegrasyonu (otomatik gönderim için)
1. Admin → Tedarikçiler → tedarikçiyi düzenle → Entegrasyon Tipi = **Tedarikçi API'si**.
2. **API Endpoint** (`https://` zorunlu) gir; kimlik doğrulama tipini seç (bearer/basic/özel başlık) ve anahtar/parolayı gir (APP_KEY ile şifrelenir).
3. Uç sözleşmesi `ApiSupplierAdapter` docblock'ta tanımlıdır (`POST {endpoint}`, `GET {endpoint}/{ref}`, `GET {endpoint}/{ref}/tracking`). Gerçek tedarikçi şeması farklıysa **yalnız gerçek API belgesi görüldükten sonra** küçük bir alt sınıf/transport eşlemesi ekleyin — tahmini kod yazılmadı.
4. `suppliers.integration_type = 'api'` olduktan sonra `supplier:process` cron'u otomatik gönderir; `supplier:query` unknown/sending kayıtları sorgular.

### 3.2 Kargo API entegrasyonu (opsiyonel)
- `ShippingAdapterInterface` uygulayan gerçek adaptör yazın → `ShippingAdapterFactory::register('aras', ...)` → settings `shipping.adapter=aras`. Aksi hâlde manuel mod geçerlidir (şu anki durum).

### 3.3 E-posta (SMTP)
```env
MAIL_DRIVER=smtp
SMTP_HOST=smtp.alanadi.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=shop@alanadi.com
SMTP_PASSWORD=***
MAIL_FROM=shop@alanadi.com
MAIL_FROM_NAME="Mağaza Adı"
```
- Test modunda `MAIL_DRIVER=log` → e-postalar `storage/logs/mail.log`'a yazılır (bu doğrulamada 4 olay bu yolla `sent` oldu).

### 3.4 Ortam değişkenleri (Aşama 5)
| Değişken | Varsayılan | Açıklama |
|----------|-----------|----------|
| `SUPPLIER_SEND_TIMEOUT` | `15` | Tedarikçi API zaman aşımı (sn; min 3) |
| `MAIL_DRIVER` | `log` | `log` \| `smtp` |
| `MAIL_FROM`, `MAIL_FROM_NAME`, `SMTP_*` | — | SMTP sürücü ayarları |
| `APP_KEY` | zorunlu | Tedarikçi API kimlik bilgilerinin AES-256-GCM şifrelemesi için (zaten Aşama 2'den beri zorunlu) |

### 3.5 Yeni veritabanı yapıları
- Migration `029_phase5_supplier_integration`: suppliers (+integration_type/api_endpoint/api_auth/api_credentials_enc), supplier_orders (+internal_reference UNIQUE/error_message/attempts/delivery_snapshot/sent_by/status_updated_at), shipments (+note, UNIQUE(carrier,tracking)).
- Migration `030_phase5_notifications`: notifications tablosu (dedup_key UNIQUE).
- `php database/migrate.php` idempotenttir; geriye dönük uyumludur (mevcut kayıtlara deterministik referans doldurulur).

### 3.6 İşletim notları
- Bildirim kuyruğu max 5 denemeden sonra `failed` kalır; panelden Raporlar → Bildirim Kuyruğu ile izlenir, kök neden (SMTP kimlik vb.) giderilince yeniden kuyruk `notifications:send` ile boşalır.
- `Idempotency-Key` gövde+başlıkta gönderilir; tedarikçi API'si desteklemiyorsa bile **deterministik dahili referans** sayesinde tekrarlı gönderim bizim tarafta engellenir.
- Panelde "Manuel Gönderildi İşaretle" işlemi audit_logs'a (`supplier_order.sent_manually`) yazılır; yönetici işlemleri izlenebilir.
