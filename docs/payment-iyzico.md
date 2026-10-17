# AŞAMA 4 — Sipariş ve iyzico Checkout Form Entegrasyonu

> **DURUM:** Entegrasyon tamamlanmıştır. Sandbox/mock yapılandırma ile çalışır durumdadır.
> Gerçek API anahtarları olmadığından **canlı ödeme testi YAPILMAMIŞTIR** — iddia edilmez.
> Bu doküman resmî kaynaklara dayanır: `docs.iyzico.com` (CF Başlatma, CF Sorgulama,
> İptal ve İade, Webhook, HMACSHA256 Kimlik Doğrulama) + resmî `iyzipay-php` SDK (2.0.x)
> imza üretimi birebir doğrulanmıştır.

## 1. Genel Bakış

Sipariş akışı AŞAMA 3 checkout'una eklenir:

```
Sepet → Checkout (AŞAMA 3, fiyatlar sunucuda yeniden hesaplanır)
  → Sipariş oluşturma (status=pending_payment, stok düşümü, snapshot'lar)
  → iyzico Checkout Form başlatma (POST /payment/iyzipos/checkoutform/initialize/auth/ecom)
  → Müşteri iyzico formunda kart bilgilerini girer (kart verisi ASLA bizim sunucuya gelmez)
  → iyzico tarayıcıyı callbackUrl'e POST eder (token)
  → SUNUCU token ile ödeme sonucunu iyzico'dan SORGULAR (detail API)
  → Tutar/para birimi/conversationId doğrulanır → transaction içinde:
       orders.status=paid + payments.success + supplier_orders (tek kez)
  → Müşteri sonuç sayfasına yönlendirilir
```

**Temel güvenlik ilkesi:** Tarayıcıdaki başarı mesajı TEK BAŞINA kanıt değildir.
Ödeme sonucu her zaman sunucu tarafında token ile iyzico'dan sorgulanır
(`POST /payment/iyzipos/checkoutform/auth/ecom/detail/`).

## 2. Yapılandırma (`.env`)

| Değişken | Açıklama |
|---|---|
| `IYZICO_MODE` | `mock` (API çağrısı yok, simülasyon) \| `sandbox` \| `live` |
| `IYZICO_API_KEY` | iyzico panelinden (sandbox: sandbox paneli) |
| `IYZICO_SECRET_KEY` | **Yalnız sunucuda** — ASLA frontend'e/repo'ya girmez |
| `IYZICO_BASE_URL` | sandbox: `https://sandbox-api.iyzipay.com` · live: `https://api.iyzipay.com` |
| `IYZICO_TIMEOUT` | HTTP zaman aşımı (sn) |
| `ORDER_EXPIRE_MINUTES` | pending_payment siparişlerin otomatik iptal süresi (dk) |

`IYZICO_MODE=mock` iken `IyzipayMockClient` devreye girer: gerçek API çağrısı yapmadan
tüm akış (başlatma/callback/webhook/iptal/iade) çalıştırılır; `/odeme/onay/{no}` ekranında
sandbox simülasyon paneli gösterilir. Mock istemci token başına senaryoyu
`storage/cache/mock-iyzico/` altında saklar.

## 3. Kimlik Doğrulama — IYZWSv2 (resmî HMACSHA256 şeması)

`app/Services/Payment/IyzipayClient.php` — resmî `iyzipay-php` SDK'sındaki
`IyziAuthV2Generator` ile birebir uyumlu:

```php
randomKey = bin2hex(random_bytes(8));
signature = bin2hex(hash_hmac('sha256', randomKey . uriPath . jsonBody, secretKey, true));
Authorization: "IYZWSv2 " . base64("apiKey:" . apiKey . "&randomKey:" . randomKey . "&signature:" . signature);
```

## 4. Uç Noktalar (iyzico — resmî)

| İşlem | Yöntem + Uç | Kullanım yerimiz |
|---|---|---|
| CF Başlatma | `POST /payment/iyzipos/checkoutform/initialize/auth/ecom` | `CheckoutFormService::initializeForOrder` |
| CF Sorgulama | `POST /payment/iyzipos/checkoutform/auth/ecom/detail/` | `PaymentService::processToken` |
| İptal | `POST /payment/cancel` | `RefundService::approve` (type=cancel) |
| İade (kırılım bazlı) | `POST /payment/refund` | `RefundService::approve` (tam/kısmi) |

- `conversationId` = sipariş numarası, `basketId` = `B-{sipariş no}` — benzersiz bağ.
- `price`/`paidPrice` = siparişin `grand_total`'ı; `basketItems` toplamı price'a eşit
  (indirim satırlara paylaştırılır, yuvarlama farkı son kaleme eklenir; kargo ayrı kalem).
- `itemTransactions[].itemId` = `order_item.id` → kısmi iade kırılım eşlemesi.
- `itemTransactions[].paymentTransactionId` **kalıcı saklanır** (payments.raw_response) —
  iade/iptal iletişimi için zorunlu.

## 5. Rotalar (bizim taraf)

| Rota | Amaç | Kimlik doğrulama |
|---|---|---|
| `GET /odeme/onay/{no}` | Checkout Form başlat + göster | Müşteri + sahiplik |
| `POST /odeme/callback` | iyzico token POST'u → sunucu sorgusu → 303 sonuç sayfası | **CSRF YOK** — token + sunucu sorgusu |
| `POST /odeme/webhook` | iyzico webhook alıcı ucu | **CSRF YOK** — `X-IYZ-SIGNATURE-V3` |
| `GET /odeme/sonuc/{no}` | Sonuç ekranı (initiated ise kurtarma sorgusu) | Müşteri + sahiplik |
| `POST /odeme/simulasyon` | Yalnız mock modda senaryo seçimi | Müşteri + CSRF |
| `POST /hesabim/siparisler/{no}/iade-talebi` | Müşteri iade talebi (tam/kısmi) | Müşteri + CSRF |
| `GET/POST /admin/orders...` | Admin sipariş yönetimi + iptal/iade | Admin + CSRF |

## 6. Webhook — X-IYZ-SIGNATURE-V3 (resmî HPP şeması)

`app/Services/Payment/PaymentService::handleWebhook`:

```php
// HPP (Checkout Form) formatı — resmî sıralama:
message = secretKey . iyziEventType . iyziPaymentId . token . paymentConversationId . status
expected = bin2hex(hash_hmac('sha256', message, secretKey, true));
hash_equals($expected, $headerSignature);
```

- İmzasız/uyuşmayan bildirim → `401` + `webhook_events` kaydı (`signature_valid=0`) + security log; **içerik asla işlenmez**.
- **Tekrarlı bildirim:** `webhook_events` tablosunda `UNIQUE(provider, external_id)`;
  `external_id = sha1(eventType|iyziReferenceCode|token|status)`. Duplike → `200 duplicate`
  (iyzico 2xx alana kadar ~15 dk aralıkla en çok 3 kez tekrarlar; idempotent yanıt zorunlu).
- Webhook içeriğindeki token **yine sunucudan iyzico'ya sorgulanır** (imza + sorgu ikili savunma).

## 7. Idempotency ve Güvenlik Kontrol Listesi

- `payments.token` **UNIQUE** — token ↔ sipariş bağlamı tek satır.
- Ödeme işleme: `SELECT ... FOR UPDATE` (orders + payments) + transaction — aynı callback'in
  çift işlenmesi imkânsız; ikinci çağrı `replay` döner.
- **Tutar doğrulama:** `bccomp(detail.paidPrice, orders.grand_total, 2) !== 0` → ödeme
  `failed (validation_mismatch)` + sipariş paid OLMAZ + security log.
- `conversationId` ve `currency` eşleşmesi zorunlu.
- `fraudStatus`: `1` → onay, `0` → incelemede (paid yapılmaz), `-1` → red.
- **Çift tedarikçi siparişi:** `supplier_orders UNIQUE(order_id, supplier_id)` +
  `INSERT IGNORE` — yalnız bir kez.
- Kart verisi (PAN/CVV) **saklanmaz** — yalnız son 4 hane + kart ailesi (iyzico yanıtından).
- Secret key yalnız `.env` + sunucu; log/hata mesajlarında taşınmaz.
- Callback/webhook rotalarında CSRF yoktur; kimlik doğrulama token + imza + sunucu sorgusuyla yapılır.
- Yetkisiz sipariş erişimi: tüm müşteri okumaları `customer_id` parametreli (IDOR testi var).

## 8. Durum Makinesi (`app/Services/Order/OrderStatus.php`)

```
pending_payment → paid | payment_failed | cancelled
payment_failed  → pending_payment (yeniden dene) | paid (geç webhook) | cancelled
paid            → processing | supplier_pending | refund_pending | cancelled
processing      → supplier_pending | shipped | refund_pending | cancelled
supplier_pending→ processing | shipped | refund_pending
shipped         → delivered | refund_pending
delivered       → refund_pending
refund_pending  → refunded | (kısmi/red: önceki duruma geri — restore_status)
refunded/cancelled → terminal
```

Her geçiş `OrderService::transition` üzerinden: harita dışı geçiş `ValidationException`,
her değişiklik `audit_logs`'a (`order.status_changed`, from/to, aktör, IP).

## 9. İptal ve İade Veri Modeli (`refunds` tablosu)

| Alan | Anlam |
|---|---|
| `type` | `refund` (iade) \| `cancel` (ödeme iptali) |
| `scope` | `full` \| `partial` |
| `status` | `pending → completed | failed | rejected` |
| `amount` | iade tutarı (kısmi iade kırılım kalanıyla sınırlı) |
| `payment_transaction_id` | kısmi iade iyzico kırılımı (order_item.id eşlemesi) |
| `refund_reference` | iyzico `refundHostReference` / `cancelHostReference` |
| `context` | per-kırılım referans listesi (JSON) |
| `restore_status` | kısmi iade/reddinde geri dönülen sipariş durumu |
| `requested_by / handled_by` | müşteri talebi / admin işlemi (audit log'larıyla) |

Müşteri akışı: talep → sipariş `refund_pending` → admin onayı → iyzico iade/iptal →
tam: `refunded` (+payments `refunded`), kısmi: önceki duruma dönüş (+payments `partially_refunded`).
Admin iptal (kargo öncesi): `RefundService::cancelByAdmin` → iyzico `/payment/cancel` →
`payments.cancelled` + `orders.cancelled`.

> Stok politikası: iptalde stok geri ALINMAZ — stok tedarikçi XML senkronizasyonuyla
> tazelenir (dropshipping modelinde yerel artırım veri drifti yaratır). Karar audit
> notlarında belgelenmiştir.

## 10. Cron / Bakım

```cron
# Süresi geçen ödeme bekleyen siparişleri iptal et (pending_payment → cancelled)
*/10 * * * * cd /var/www/xml-ecommerce && /usr/bin/php bin/console order:expire >> storage/logs/cron.log 2>&1
```

## 11. Canlıya Geçiş (Go-Live) Kontrol Listesi

1. iyzico üyelik + onay; **canlı** API anahtarları panelinden alınır → `.env` (repo dışı).
2. `IYZICO_MODE=live`, `IYZICO_BASE_URL=https://api.iyzipay.com`.
3. Geçerli TLS sertifikalı **herkese açık** `APP_URL` (callbackUrl gereksinimi).
4. iyzico paneli → Ayarlar > Firma Ayarları > İşyeri Bildirimleri →
   `https://ALANADI/odeme/webhook` + webhook imza (V3) özelliğinin aktifleştirilmesi
   (entegrasyon@iyzico.com).
5. Sandbox'ta uçtan uca gerçek kartlarla (sandbox kartları) test: 3DS, BKM, başarısız kart.
6. `buyer.identityNumber` için gerçek TCKN toplama (mevcut fallback `11111111111` yalnız
   sandbox içindir) — KVKK/GDPR onay metinleriyle.
7. `IYZICO_MODE=mock` bilgisinin asla canlıda bırakılmaması (deploy checklist).
8. Mutabakat: iyzico panel raporları ↔ `payments`/`refunds` tabloları; webhook + callback
   kesilirse `GET /odeme/sonuc/{no}` kurtarma sorgusu ve (isteğe bağlı) cron mutabakatı.

## 12. Testler

`tests/Phase4OrderPaymentTest.php` — 16 test (mock istemci, izole test DB):
durum makinesi, başlatma payload'ı + token UNIQUE, başarılı ödeme + tek tedarikçi
siparişi + tekrarlı callback replay, başarısızlık → yeniden başlatma, yanlış tutar reddi,
bilinmeyen token, webhook (bozuk imza 401 / geçerli V3 / duplike), sepet fiyatı manipülasyonu,
IDOR, `order:expire`, tam/kısmi iade, iade reddi, admin iptal, audit log.

Uçtan uca tarayıcı doğrulaması: sipariş `ORD-20261017-9CCA8` — başarısız simülasyon →
yeniden başlatma → başarı → iade talebi → admin onayı (`MOCKREFUND-0c36c98887`) →
audit izi; masaüstü + mobil (390px) render doğrulandı.
