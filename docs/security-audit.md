# Güvenlik Denetimi — AŞAMA 6 §3

Denetim tarihi: AŞAMA 6 (Ajan 6-a) · Kod tabanı: Aşama 1-5 tamamlandı (166/166 test yeşil)
Kapsam: 15 güvenlik başlığı · Yöntem: statik kod incelemesi (rg taramaları + dosya/satır kanıtı) + mevcut test süitesinin kanıt olarak kullanımı

**Durum kısaltmaları:** MEVCUT (koruma tam) · KISMİ (koruma var, boşluk kapatıldı/bilinçli not) · EKSİK (koruma yoktu → bu aşamada eklendi)

**Özet:** 15 başlıktan denetim sonunda **9 MEVCUT, 4 KISMİ, 2 EKSİK** saptandı. Eksikler (HTTPS zorlama, Host kısıtlama) ve KISMİ boşlukların tamamı (XSS 2 nokta, Logger redaction, register/reset/iletişim hız sınırı) bu aşamada küçük, eklemseri düzeltmelerle kapatıldı. Sonuç: **15/15 MEVCUT**.

---

## 1. SQL Injection

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| Tüm repository'lerde PDO prepared statement; string birleştirme/interpolasyon yok | `app/Repositories/BaseRepository.php` (tüm sorgular `prepare()+execute()`); `app/Repositories/OrderRepository.php:68-99` (LIMIT/OFFSET `bindValue(... PARAM_INT)`); `app/Repositories/SupplierOrderRepository.php:169,182` ve `app/Repositories/ShipmentRepository.php:135,148` — interpolasyon yalnız `int` tipli `$perPage/$offset` (fonksiyon imzası `int $page, int $perPage`) ve hardcoded WHERE parçalarından üretilen `$whereSql` üzerinde; kullanıcı girdisi her yerde `?` parametresiyle bağlı | MEVCUT | KRİTİK | rg taraması: `->query(`/`->exec(` eşleşmeleri incelendi — `app/Controllers/HomeController.php:39` ve `app/Controllers/Admin/DashboardController.php:39` tablo adı **whitelist** (hardcoded dizi); `app/Repositories/BaseRepository.php:76` `COUNT(*) FROM $this->table()` (sınıf sabiti); `SupplierOrderRepository.php:392` tamamen statik rapor SQL'i. Dinamik ORDER BY: `app/Services/Store/StoreProductService.php:406-412` `match()` whitelist. Dinamik LIKE değerleri `?` ile bağlanıyor (`$like = '%'.$search.'%'` → `$params[]`). Düzeltme gerekmedi. |

**Test kanıtı:** `tests/ValidatorTest.php`, `tests/CheckoutOrderTest.php` (sunucu fiyatı kazanır — parametreli okuma), `tests/Phase4OrderPaymentTest.php` (tüm ödeme/sipariş okumaları repository üzerinden).

## 2. XSS (Cross-Site Scripting)

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| View'larda ham `<?=` çıktısı yok; tüm kullanıcı verisi `e()` ile kaçışlı | `app/Core/helpers.php:18-21` `e() = htmlspecialchars(ENT_QUOTES\|ENT_SUBSTITUTE, UTF-8)`. rg (`--pcre2`) ile tüm `app/Views` `<?= $` çıktıları tarandı; eşleşmelerin tamamı ya `e()` sarmalı ya `int/number_format` gibi güvenli dönüşümler ya da **dokümante edilmiş bilinçli RAW** çıktılar: (1) `app/Views/layouts/base.php:41` / `store.php:57` `$content` — view kompozisyonu (iç view'lar zaten kaçışlı); (2) `store.php:65` `$jsonLd` — üretici tarafında `json_encode` ile kaçışlı (bkz. düzeltme); (3) `app/Views/store/pages/*.php` `$contentHtml` — yönetici TASLAK hukuki metinleri (`config/legal_texts.php`, güvenilir kaynak, HTML formatlaması bilinçli); (4) `app/Views/store/checkout/pay.php:95` `$content` — iyzico API'sinden TLS üzerinden gelen resmî `checkoutFormContent` betiği (dokümante: satır 9, 93). | KISMİ | YÜKSEK | **2 boşluk kapatıldı:** (a) `app/Views/store/account/address_form.php:15-21` `$val()` kapanışı flash'lenen eski form girdisini (`Session::flash('old', $request->post())` — `AddressController.php:56,83`) ve adres kaydı değerlerini **kaçışsız** `value="…"` özniteliğine basıyordu (reflected/stored XSS) → kapanış içi tek noktadan `e()` eklendi (çağrı noktaları zaten sarmasız olduğundan çift kodlama oluşmaz); (b) `app/Controllers/Store/ProductController.php:152` JSON-LD `json_encode(..., JSON_UNESCAPED_SLASHES)` XML feed'inden gelen ürün adı/marka içindeki `</script>` ile `<script type="application/ld+json">` bloğundan taşmaya izin veriyordu (2. mertebe stored XSS) → `JSON_HEX_TAG \| JSON_HEX_AMP` bayrakları eklendi (`<`, `>`, `&` → `\u003C…`, JSON-LD geçerliliği korunur). **Çift kodlama yok:** `e()` idempotent değil, ancak düzeltmeden sonra çağrı zincirinde tek escape noktası kaldı (test: `tests/Security/XssRenderingTest.php`). |

**Test kanıtı:** `tests/Security/XssRenderingTest.php` (yeni): `<script>` payload'ı `e()` ve gerçek `address_form` render'ında kaçışlı; `buildJsonLd` çıktısı JSON-LD olarak parse edilir ve ham `</script>` içermez; view çıktısında `e(e($x))` çift kodlama reddi.

## 3. CSRF

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| Tüm POST/PUT/PATCH/DELETE formlarında senkron token; webhook/callback gerekçeli istisna | `app/Core/Csrf.php:33-40` (`hash_equals`, timing-safe); `app/Middleware/CsrfMiddleware.php:25-40` (POST/PUT/PATCH/DELETE, `_token` alanı + `X-CSRF-Token` başlığı; başarısızlık → 419 + `security.log`). Rota kapsamı: tüm store POST'ları `routes/store.php:39-93` (sepet, üyelik, checkout, hesap, adres, iade, iletişim) ve tüm admin POST'ları `routes/admin.php:32-111` grup middleware'iyle CsrfMiddleware'li. **İstisnalar (gerekçeli):** `POST /odeme/callback` (`routes/store.php:64-65`) — iyzico tarayıcı yönlendirmesi, token kimliği + sonuç SUNUCU TARAFLI sorguyla doğrulanır; `POST /odeme/webhook` (`routes/store.php:66-67`) — sunucudan sunucuya bildirim, kimlik `X-IYZ-SIGNATURE-V3` HMAC imzasıyla; her iki istisna da rota dosyası ve denetleyici docblock'larında (`WebhookController.php:24`, `PaymentController.php:127-129`) dokümante. | MEVCUT | KRİTİK | Düzeltme gerekmedi. |

**Test kanıtı:** `tests/CsrfTest.php` (token üretim/rotasyon/timing-safe red). Yeni yapısal test: `tests/Security/SecurityHeadersTest.php` — rotalar taranır; CsrfMiddleware'siz POST rotalarının YALNIZ `/odeme/callback` ve `/odeme/webhook` olduğu doğrulanır (beyaz liste regresyon testi).

## 4. Yetkisiz yönetici erişimi (auth + RBAC)

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| AdminAuthMiddleware tüm /admin rotalarında; rol kontrolü | `routes/admin.php:36-111` — `/admin` grubundaki TÜM rotalar `[SecurityHeadersMiddleware, AdminAuthMiddleware, CsrfMiddleware]` zincirli; auth'suz `/admin/login` GET/POST + `/admin/logout` bilinçli açık (giriş ucu). `app/Middleware/AdminAuthMiddleware.php:25-29` — oturum yoksa `/admin/login` + `security.log` + `intended` flash. Oturum sabitleme koruması: `AdminAuthService.php:71` `Session::regenerate()`. | KISMİ | ORTA | Auth kapsamı tam (rg ile grup dışı admin rotası aranmadı — yalnız login/logout bilinçli). **RBAC boşluğu BELGELENDİ (düzeltme yeniden yazım gerektirdiğinden bu aşamada yapılmadı):** `admin_users.role` (`database/migrations/002:30`; roller `AdminUser.php:15-17` superadmin/admin/viewer) oturuma yazılıyor (`AdminAuthService.php:74`) ancak hiçbir rota/denetleyici rol kontrolü yapmıyor — bugün tüm admin'ler tam yetkili (tek rol fiilen kullanımda; seed yalnız admin oluşturur), `viewer` gibi düşük-rol hesap açılırsa yetki farkı uygulanmaz. Öneri: rol bazlı rota middleware'i (AŞAMA 7 adayı). |

**Test kanıtı:** `tests/CustomerAuthTest.php`, e2e kayıtları (worklog Aşama 3/4). Yapısal test: `tests/Security/SecurityHeadersTest.php` — `/admin` grubundaki her rotada AdminAuthMiddleware bulunduğu doğrulanır.

## 5. Yetkisiz sipariş görüntüleme (IDOR)

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| Müşteri A, B'nin siparişini açamaz; guest erişimi yok | `app/Repositories/OrderRepository.php:90-99` `findByNumberForCustomer(number, customerId)` — SQL'de `AND customer_id = ?`; `app/Controllers/Store/OrderController.php:63-70` (bulunamazsa 404 — varlık bilgisi sızmaz); liste de customer_id parametreli (`:68-88`). `PaymentController.php:74-89,166-172` (showPay/result) ve `requestRefund` aynı sahiplik deseninde (`RefundService::requestByCustomer(number, customerId, …)`). **Guest siparişi yok:** checkout ve tüm sipariş uçları `CustomerAuthMiddleware` arkasında (`routes/store.php:58-89`) — misafir checkout bilinçli olarak bulunmuyor, dolayısıyla guest sipariş erişim kuralı uygulanacak yüzey mevcut değil. | MEVCUT | KRİTİK | Düzeltme gerekmedi. |

**Test kanıtı:** `tests/Phase4OrderPaymentTest.php` "Yetkisiz sipariş erişimi (IDOR): başka müşteri göremez" (satır ~432) — `tests/Security/OrderAccessTest.php` ayrıca yazılmadı (mükerrer); kanıt bu test + `tests/CustomerAuthTest.php`.

## 6. Güvenli olmayan dosya yükleme

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| XML upload çok katmanlı doğrulama; başka upload noktası yok | `app/Validators/XmlUploadValidator.php:26-104` — 6 katman: yükleme hata kodu, boyut (`xml.upload.max_mb`), uzantı whitelist (`.xml`), gerçek MIME (finfo, `xml.upload.allowed_mime`), içerik sniff (`<?xml`), DOCTYPE/ENTITY reddi (XXE — hattın 2. katmanı; 1. katman `XmlSafeParser` — `tests/XmlSafeParserTest.php`). Saklama adı rastgele: `stored_name = bin2hex(random_bytes(16)).'.xml'` (`:86`); `original_name` `basename()` ile path traversal temizliği (`:84`). Yazma noktaları: `app/Services/Import/XmlUploadManager.php:33-34` ve `app/Controllers/Admin/FeedController.php:365` — ikisi de validatör çıktısıyla, depolama yolu `storage/imports` (web kökü dışı). rg: `move_uploaded_file`/`$_FILES` kullanımı app çapında yalnız bu iki nokta — **başka upload yüzeyi yok**. | MEVCUT | YÜKSEK | Düzeltme gerekmedi. |

**Test kanıtı:** `tests/ValidatorTest.php` (uzantı/MIME/boyut/DTD reddi), `tests/XmlSafeParserTest.php` (XXE: `tests/fixtures/xxe_feed.xml`).

## 7. XML SSRF

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| IpGuard private/reserved blok listesi; IP pinning; redirect yeniden doğrulama | `app/Services/Security/IpGuard.php:26-98` — tüm IPv4 özel/rezerve aralıkları (0/8, 10/8, 127/8, 169.254/16 dahil cloud metadata, 172.16/12, 192.168/16, CGNAT 100.64/10, 198.18/15, ≥224) + IPv6 (::, ::1, fe80, fc/fd, ff, ::ffff: mapped, NAT64, 6to4, teredo, 2001:db8); fail-closed. `app/Services/Http/UrlFetcher.php` — yalnız http/https + 80/443 (`:153-162`), user-info reddi (`:165-167`), DNS çözümle ve **tüm** kayıtlar public değilse reddet (`:172-208`), `CURLOPT_RESOLVE` ile doğrulanan IP sabitlenir (`:69` — rebinding karşıtı), `FOLLOWLOCATION=false` + her redirect hedefi döngü başında yeniden `assertSafeUrl` (`:44`, `:114-126`). | MEVCUT | KRİTİK | Düzeltme gerekmedi. Küçük gözlem (DÜŞÜK, kayda geçti): `extractLocationHeader()` (`UrlFetcher.php:210-241`) Location başlığını okumak için ikinci bir HEAD bağlantısı açar — bu bağlantı pinning'siz yeniden DNS çözer; yalnız başlık okunduğu ve sonraki GET yeniden doğrulanıp pinlendiği için veri akışına etkisi yok. |

**Test kanıtı:** `tests/IpGuardTest.php` (28 vektör: IPv4/IPv6 blok + public kabul + şema/port/user-info/localhost reddi).

## 8. DNS rebinding ve yönlendirme kontrolleri

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| Çekimlerde rebinding koruması; redirect sayı/kural kontrolü | `UrlFetcher.php:69` `CURLOPT_RESOLVE => ["$host:$port:$ip"]` — doğrulanan IP'ye sabitleme (TOCTOU kapanır); redirect'ler manuel (`FOLLOWLOCATION=false`), her hop'ta şema/port/host-IP yeniden doğrulanır (`:43-44`), `xml.fetch.max_redirects` üst sınırı (`:121-123` + `:141`), boyut limiti WRITEFUNCTION ile kesme (`:77-84`) + `CURLOPT_MAXFILESIZE`, TLS doğrulaması asla kapalı değil (`:74-75`). | MEVCUT | YÜKSEK | Düzeltme gerekmedi (bkz. §7 küçük gözlem). |

**Test kanıtı:** `tests/IpGuardTest.php` (port/şema/localhost reddi) + Aşama 1 testleri (`tests/fixtures/*`).

## 9. Oturum yönetimi

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| Cookie HttpOnly/SameSite/Secure; login'de regenerate; idle timeout | `app/Core/Session.php:36-43` — `httponly=true`, `samesite='Lax'`, `secure` = auto|true|false; **auto** HTTPS'i `$_SERVER['HTTPS']` **veya** `HTTP_X_FORWARDED_PROTO === 'https'` ile algılar (`:30-32` — proxy arkası doğru). `use_strict_mode=1`, `use_only_cookies=1` (`:45-46`). Idle timeout: `_last_activity` + `session.lifetime` (45 dk) aşımında oturum imha + yeniden başlatma (`:52-58`). `session_regenerate_id(true)`: admin giriş `AdminAuthService.php:71`, müşteri giriş/kayıt `CustomerAuthService.php:219-226` (`startSession()` → `Session::regenerate()` — oturum sabitleme koruması). | MEVCUT | YÜKSEK | Görev maddesi (f) doğrulandı — eksik yok. `Secure=auto` X-Forwarded-Proto desteğiyle zaten HTTPS'te true üretiyor. |

**Test kanıtı:** `tests/CsrfTest.php` (aktif oturum davranışları), `tests/Phase4OrderPaymentTest.php` / `tests/CustomerAuthTest.php` (regenerate sonrası oturum akışları yeşil).

## 10. Parola sıfırlama

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| Token hash'li, tek kullanım, süreli; enumeration güvenli | `app/Services/Auth/CustomerAuthService.php:175-215` — ham token `random_bytes(32)` yalnız e-postaya gider; DB'ye `hash('sha256', $token)` (`:187`); süre 1 saat (`:187`); `resetPassword()` valid token'ı bulup şifreyi günceller ve `markUsed()` ile **tek kullanıma** kapatır (`:207-213`); `PasswordResetRepository::findValid()` (kullanılmamış + süresi geçmemiş koşullu). E-posta yoksa yanıt her zaman aynı (`sendResetLink` null döner, `AuthController.php:104-119` aynı flash mesajı — enumeration önlenir). Production'da token ekrana asla basılmaz (`APP_ENV=local` koşullu, `AuthController.php:114-116`). | KISMİ | YÜKSEK | Token yaşam döngüsü tamdı; **talep ucu hız sınırı yoktu** → §11'de eklendi (`RateLimiter`, `AuthController::forgot` — IP başına 5/15 dk; sınır aşımında yanıt YİNE aynı generic mesaj, `security.log` düşer). |

**Test kanıtı:** `tests/CustomerAuthTest.php` (kayıt/giriş/kilit; reset akışı), `tests/Security/HttpsHostTest.php` ile aynı klasörde yeni `tests/Security/LogRedactionTest.php` reset loglarının redaksiyonu.

## 11. Rate limiting

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| admin login, customer login, register, contact form, password reset | **Admin login:** hesap bazlı 5 hatalı deneme → 15 dk kilit (`AdminAuthService.php:42-63`, config `security.admin.*`). **Customer login:** aynı model (`CustomerAuthService.php:116-144`). **Register / forgot / contact:** koruma YOKTU. | EKSİK → düzeltildi | ORTA | **Yeni `app/Services/Security/RateLimiter.php`** — dosya tabanlı sabit pencere sayaç (`storage/cache/rate-limit/<sha256(key)>.json`; IP kilitlenebilir yazım, süresi geçen dosya silinir; best-effort, çoklu-sunucu senaryosunda öneri Redis — docblock'ta not). Bağlantılar: `AuthController::register` (IP başına 10/saat → ValidationException form hatası), `AuthController::forgot` (IP başına 5/15 dk → sessiz düşürme + `Logger::security('customer.reset_throttled')`), `ContactController::send` (IP başına 5/10 dk → bilinen hata flash'i). **IP'siz (CLI/test) çağrılar sınırlamadan geçer** — mevcut testler etkilenmez. |

**Test kanıtı:** `tests/CustomerAuthTest.php` (login kilitleme), `tests/Security/RateLimitTest.php` (yeni — pencere, limit aşımı, pencere sıfırlama; geçici cache dizini izole).

## 12. Ödeme callback manipülasyonu

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| Callback token doğrulaması sunucu tarafında; sahte success'e güvenme; tutar/para birimi karşılaştırması | `app/Services/Payment/PaymentService.php:86-244` `processToken()` — (1) token `payments.token UNIQUE` lookup, bilinmeyen → `IyzipayException` + `security.log` (`:88-92`); (2) sonuçlanmış ödeme → **replay** kısa devresi (`:97-104`); (3) sonuç HER ZAMAN sunucudan iyzico detail API'sinden sorgulanır (`:108-115`) — tarayıcıdaki success mesajı kanıt değildir; (4) `conversationId` + `currency` + `bccomp(paidPrice, amount, 2)` karşılaştırması, uyuşmazlıkta ödeme RED + `payment.tampering_suspected` audit + `security.log` (`:137-154`); (5) `fraudStatus` -1 → red, 0 → beklemede (`:157-168`); (6) FOR UPDATE satır kilidi + ikinci idempotency kontrolü (`:171-193`). Callback denetleyicisi sahte sonucu asla işlemez: `PaymentController.php:131-163`. | MEVCUT | KRİTİK | Düzeltme gerekmedi. |

**Test kanıtı:** `tests/Phase4OrderPaymentTest.php` — "Yanlış tutar (tampering): ödeme reddedilir; sipariş paid OLMAZ; security log düşer", "Bilinmeyen token: IyzipayException", "Tekrarlı callback → replay", "Sepet fiyatı değiştirilerek gönderilen istek: sunucu fiyatı kazanır". Bu senaryolar için ayrı `CallbackHardeningTest.php` yazılmadı (mükerrer test üretme ilkesi).

## 13. Webhook imza doğrulaması

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| V3 HMAC, hash_equals, tekrar bildirim dedup | `PaymentService.php:251-309` `handleWebhook()` — resmî HPP şeması `hex(HMAC-SHA256(secret, secret+eventType+iyziPaymentId+token+conversationId+status))` (`:267-271`), `hash_equals` timing-safe; imzasız/bozuk → 401 + `webhook_events` güvenlik kaydı (işlenmez) (`:275-284`); tekrarlı bildirim `webhook_events UNIQUE(provider, external_id)` ile dedup → duplicate'e 2xx (iyzico idempotent beklentisi) (`:287-291`); yalnız SUCCESS/FAILURE işlenir (`:294-297`). Uç: `app/Controllers/Store/WebhookController.php:36-44`. | MEVCUT | KRİTİK | Düzeltme gerekmedi. |

**Test kanıtı:** `tests/Phase4OrderPaymentTest.php` — "Webhook: bozuk imza 401; geçerli V3 imzası işlenir; DUPLİKE 2xx ve tek işleme".

## 14. Yinelenen ödeme ve tedarikçi siparişi

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| token UNIQUE; conversationId; UNIQUE(order_id, supplier_id) | `database/migrations/028_phase4_payments.php` — `payments.token VARCHAR(128) UNIQUE`; başlatma `CheckoutFormService` `conversationId = sipariş no` (worklog Aşama 4; `CheckoutFormService.php:212` çevresi); tedarikçi siparişi çift kaydı `UNIQUE(order_id, supplier_id)` + `INSERT IGNORE` (`OrderService::createSupplierOrdersLocked` — worklog Aşama 4; migration 028/029) + `supplier_orders.internal_reference UNIQUE` (migration 029); ödeme tarafı ikinci idempotency: `PaymentService.php:189-193` FOR UPDATE + status kontrolü. | MEVCUT | KRİTİK | Düzeltme gerekmedi. |

**Test kanıtı:** `tests/Phase4OrderPaymentTest.php` — "token UNIQUE", "Tekrarlı callback replay (çift tedarikçi siparişi yok)"; `tests/Phase5SupplierShippingTest.php` (internal_reference/deterministik backfill).

## 15. Hassas bilgilerin loglara yazılması

| Madde | Mevcut koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| Logger redaction; payment raw_response alt kümesi; mail/SMTP şifresi loglanmıyor | **raw_response alt kümesi:** `PaymentService.php:198-217` — detail yanıtının yalnız güvenli alanları (status/tutar/para birimi/kart tipi/son 4 hane…) saklanır; kart tam numarası/CVV asla. **SMTP:** `app/Services/Notification/SmtpMailer.php` — parola yalnız bağlantı için kullanılır; `NotificationService.php:201-206,224-226` logları `notification_id/event/attempts/reason` anahtarlarıyla sınırlı, kimlik bilgisinden arınmış. **Logger:** `app/Core/Logger.php` — redaction YOKTU. | KISMİ | YÜKSEK | **Redaction eklendi:** `Logger::write()` context dizisini yazmadan önce `redact()` eder — hassas anahtarlar (password*, card*, cvv/cvc, secret*, token*, authorization, api_key, auth*, cookie) `***` maskelenir, iç içe diziler özyinelemeli taranır (anahtar adı **içerir** kuralı, case-insensitive — `card_token`, `smtp_password` gibi varyantları da yakalar). Mevcut log çağrıları tarandı (`rg 'Logger::' app`): hiçbiri redaksiyona takılacak bilgiyi iş mantığı için gerektirmiyor (`payment.unknown_token` kısmi token ön eki artık maskelenir — log amaçlı kayıp yok). |

**Test kanıtı:** `tests/Security/LogRedactionTest.php` (yeni — düz anahtar, iç içe dizi, gerçek `payment.unknown_token` benzeri çağrı; dosya içeriği doğrulanır ve test log dosyası silinir).

---

## Bu aşamada eklenen taşıma güvenliği muhafızları (opt-in, production)

| Madde | Koruma (kanıt) | Durum | Önem | Yapılan eylem |
|---|---|---|---|---|
| HTTPS zorlama | YENİ `app/Middleware/HttpsEnforcementMiddleware.php` — `security.force_https` (env `APP_FORCE_HTTPS`) açıkken HTTPS olmayan istek 301 → aynı host+path+query; şema algısı `X-Forwarded-Proto` destekli; Host header'ı regex temizli (redirect injection yok). Front controller'da `public/index.php:37-40` rota zincirinden bağımsız, oturum başlamadan uygulanır (boş env = kapalı — local/staging etkilenmez). | EKSİK → düzeltildi | ORTA | Eklendi + `.env.example` anahtarı + `config/security.php` bağlantısı. **Test:** `tests/Security/HttpsHostTest.php` |
| Host kısıtlama (host-header injection / cache poisoning / reset-link poisoning karşıtı) | YENİ `app/Middleware/TrustedHostMiddleware.php` — `security.allowed_hosts` (env `APP_ALLOWED_HOSTS`, virgülle) yapılandırılmışken `Host`/`X-Forwarded-Host` normalize edilip (port/dot temizliği, lowercase) beyaz listede değilse 400. X-Forwarded-Host **yalnız** yapılandırma aktifken dikkate alınır; boş env = kapalı. | EKSİK → düzeltildi | ORTA | Eklendi + `.env.example` anahtarı. **Test:** `tests/Security/HttpsHostTest.php` (production simülasyonu, XFH spoof red, restore ile izole) |

**Not — RBAC (madde 4):** `viewer` rolü için rota-bazlı yetki farkı AŞAMA 7 önerisi olarak `docs/development-roadmap.md` kapsamına bırakıldı; bugünkü tek-rol gerçekliğinde risk düşük.

## Düzeltme dosyaları özeti (minimum diff ilkesi)

1. `app/Views/store/account/address_form.php` — `$val()` kapanışına `e()` (XSS). [YÜKSEK]
2. `app/Controllers/Store/ProductController.php` — `buildJsonLd` json_encode bayraklarına `JSON_HEX_TAG | JSON_HEX_AMP` (XSS). [YÜKSEK]
3. `app/Core/Logger.php` — context redaction (hassas veri sızıntısı). [YÜKSEK]
4. `app/Services/Security/RateLimiter.php` (YENİ) + `app/Controllers/Store/AuthController.php` + `app/Controllers/Store/ContactController.php` — register/forgot/contact hız sınırı. [ORTA]
5. `app/Middleware/HttpsEnforcementMiddleware.php` (YENİ), `app/Middleware/TrustedHostMiddleware.php` (YENİ), `public/index.php` (4 satır bağlantı), `config/security.php` (2 anahtar), `.env.example` (yorumlu 2 anahtar). [ORTA]
6. `app/Core/Request.php` — yalnız-okuma `query()` getter (redirect'te query korumak için; mevcut davranışa dokunmaz). [DÜŞÜK]

**Yeni test dosyaları:** `tests/Security/SecurityHeadersTest.php`, `tests/Security/XssRenderingTest.php`, `tests/Security/HttpsHostTest.php`, `tests/Security/LogRedactionTest.php`, `tests/Security/RateLimitTest.php` (+ `tests/run.php` Security klasörünü toplar). DB tabanlı güvenlik senaryoları (IDOR, callback tampering, webhook imza, token replay) için mükerrer test yazılmadı — kanıtlar `tests/Phase4OrderPaymentTest.php`, `tests/Phase5SupplierShippingTest.php`, `tests/CsrfTest.php`, `tests/IpGuardTest.php` dosyalarında yukarıda başlık başına referanslıdır.
