# XML E-Ticaret — Geliştirme Yol Haritası

Sürüm: 1.0 (Faz 1 teslimi ile uyumlu)
Bağlantılı doküman: `docs/architecture.md` (mimari, güvenlik sınırları, veri modeli)
Kural: Her faz, önceki fazın "Tanım of Done" maddeleri tamamlanmadan başlamaz. Fazlar arası bağımlılıklar her fazın sonunda listelenmiştir.

## Faz Özeti

| Faz | Ad | Durum | Temel Çıktı |
|---|---|---|---|
| 1 | Temel, iskelet & güvenlik altyapısı | ✅ TAMAMLANDI (bu teslim) | Çalışan çekirdek + 23 migration + test runner |
| 2 | XML import motoru (tam devre) | Bekliyor | Alan eşleme UI + delta import + hata raporu |
| 3 | Katalog yönetimi | Bekliyor | Ürün CRUD + kategori ağacı + price_rules |
| 4 | Vitrin, sepet & checkout | Bekliyor | Müşteri akışı + iyzico + idempotent webhook |
| 5 | Dropshipping operasyonu | Bekliyor | supplier_orders otomasyonu + shipment takibi |
| 6 | Güçlendirme | Bekliyor | Rate limit + 2FA + yedekleme + monitoring |
| 7 | Performans & SEO | Bekliyor | Cache + sitemap + canonical + CDN |
| 8 | Yayına alma | Bekliyor | Prod env + TLS + PenTest + KVKK checklist |

---

## Faz 1 — Temel, İskelet & Güvenlik Altyapısı  ✅ TAMAMLANDI

**Hedef:** Uygulamanın tüm temel taşı sınıfları, veritabanı şeması ve güvenlik altyapısı çalışır ve test edilir durumda olsun; sonraki fazlar yalnızca bu temelin üzerine ekleme yapsın.

**Kapsam:**
- [x] Mimari dokümanlar (`docs/architecture.md`, `docs/development-roadmap.md`)
- [x] Dizin iskeleti (public/, app/, config/, database/, routes/, cron/, storage/, tests/, docs/)
- [x] Çekirdek: Router, Request, Response, Database (PDO), View, Session, Logger, ErrorHandler, Env, Config, Csrf, Autoloader, helpers.php
- [x] Rota dosyaları: `routes/web.php`, `routes/admin.php` (closure döndürür)
- [x] Middleware: SecurityHeaders, Csrf, AdminAuth (`MiddlewareInterface`)
- [x] 23 migration (001→023, FK sıralı) + `database/migrate.php` CLI + seed altyapısı
- [x] Güvenlik: CSRF (sync token), güvenli session, SSRF guard'lı `UrlFetcher` (IP pin + redirect teyidi), XXE-safe `XmlStreamReader`, upload validator (`XmlUploadValidator`)
- [x] Health-check uç noktası (`HealthController`, JSON yanıt)
- [x] Cron altyapısı: `cron/import_feeds.php` (flock kilidi), `crontab.example`
- [x] Bağımsız test runner: `tests/run.php` + `Support/Assert.php`

**Kabul Kriterleri:**
1. `php database/migrate.php` temiz veritabanında 23 migration'ı sırasız hatasız çalıştırır; tekrar çalıştırma idempotent davranır (migrations meta tablosu).
2. `public/index.php` üzerinden örnek sayfa ve health-check (JSON) erişilebilir; statik varlıklar dışında hiçbir dosya web'den sunulamaz.
3. Admin rotaları oturum olmadan 302/401 döndürür; CSRF token'sız POST 419/403 döndürür.
4. `UrlFetcher` özel IP'ye (127.0.0.1, 10.x, 169.254.x, metadata) istek reddeder; `XmlStreamReader` DOCTYPE içeren girdiyi reddeder.
5. `php tests/run.php` tüm assertion'ları geçer; `php -l` tüm kaynak dosyalarda temizdir.

**Tanım of Done:**
- Tüm kaynak dosyalar `php -l` temiz; test runner yeşil.
- Migration testi temiz DB'de koşup DB'yi temizleyerek bitiyor.
- app.log / security.log kanalları ayrışmış; production modunda hata detayı sızmiyor.
- Git deposunda çalışma commit'lenmiş; worklog güncel.

**Bağımlılıklar:** Yok (başlangıç fazı).
**Etkilenen klasörler:** tümü (iskelet). **Tablolar:** 001→023 (tümü, ilk kez).

---

## Faz 2 — XML Import Motoru (Tam Devre)

**Hedef:** Tedarikçi feed'leri uçtan uca otomatik işlensin: alan eşleme, delta import, ürün eşleme ve hata raporlama operasyonel olarak kullanılabilir olsun.

**Kapsam:**
- `xml_field_mappings` UI: tedarikçi başına şablon eşleme ekranı (XML alan adı → sistem alanı), eşleme test/dry-run aracı.
- Delta import: feed dosyası checksum/hash hesaplama, değişmemiş feed'i atlama, kayıt bazlı değişiklik tespiti.
- Ürün eşleme/merge stratejisi: SKU/GTIN bazlı eşleme, var olan ürünü güncelleme vs. yeni kayıt kuralları, çakışma çözümü.
- `FeedImportService` tam devrede: batch transaction'lar, progress takibi.
- Hata raporlama ekranı: `xml_import_logs` üzerinden son import'lar, satır bazlı hatalar, yeniden deneme (retry) butonu.
- Admin upload akışının canlıya alınması (Faz 1 altyapısını kullanır).

**Kabul Kriterleri:**
1. Tanımlı bir tedarikçi feed'i cron ile bitişten bitişe import edilir; ürünler `products` + `product_supplier_data`'ya doğru stok/fiyatla yazılır.
2. Aynı feed ikinci kez çalıştırıldığında delta tespiti sayesinde değişmeyen kayıtlar güncellenmez (log'da atılan kayıt sayısı görünür).
3. Bozuk/eksik alan içeren feed, import'u durdurmaz; hatalar `xml_import_logs`'a kaydedilir ve admin ekranda listelenir.
4. Alan eşleme ekranından yapılan değişiklik sonraki import'ta etkili olur; dry-run gerçek yazım yapmadan sonucu gösterir.
5. Import sırasında `flock` kilidi aktiftir; ikinci eş zamanlı çalıştırma bekler/terk eder ve loglanır.

**Tanım of Done:**
- Faz 1 test runner'a import testleri eklenmiş (küçük örnek feed fixture'larıyla).
- 100 MB+ örnek feed bellek tavanında (ayarlanan `memory_limit`) tamamlanıyor (streaming doğrulaması).
- Import özeti audit_logs'a yazılıyor; hata ekranı admin yetkisi ve CSRF arkasında.

**Bağımlılıklar:** Faz 1 (UrlFetcher, XmlStreamReader, migration 009-011).
**Etkilenen klasörler:** `app/Services/Import/`, `app/Controllers/Admin/`, `app/Views/admin/`, `cron/`, `storage/imports/`. **Tablolar:** suppliers, products, product_supplier_data, xml_feeds, xml_field_mappings, xml_import_logs, audit_logs.

---

## Faz 3 — Katalog Yönetimi

**Hedef:** Admin, XML'den gelen katalogu elle yönetebilsin: ürün düzenleme, kategori ağacı, görsel yönetimi ve fiyat kâr marjı kuralları.

**Kapsam:**
- Admin ürün CRUD: listeleme/filtre/arama, düzenleme, aktif/pasif, varyant yönetimi.
- Kategori ağacı yönetimi: ekleme/taşıma/silme, ürün-kategori ilişkilendirme.
- Görsel indirme/optimize: tedarikçi görsel URL'lerinden indirme (UrlFetcher üzerinden), boyutlandırma/optimize, `product_images` kayıtları.
- `price_rules` uygulaması: tedarikçi/kategori bazlı kâr marjı kuralları, satış fiyatı hesaplama (tedarikçi fiyatı × kural), kural önceliği.
- Import ile elle düzenlenen alanların korunma politikası (merge kuralı netleşmesi).

**Kabul Kriterleri:**
1. Admin panelinden ürün düzenlemesi kalıcıdır ve vitrin/admin listelerinde anında yansır.
2. Kategori ağacı derin (multi-level) yapıyı destekler; alt kategori taşıma tutarlı kalır (FK + transaction).
3. Fiyat kuralı tanımlandığında sonraki import'ta satış fiyatı kurala göre hesaplanır; kural yoksa config varsayılanı kullanılır.
4. Görsel indirme SSRF guard'dan geçer; başarısız indirme ürünü bloklamaz, loglanır.

**Tanım of Done:**
- Ürün/kategori formlarında Validator + CSRF tam; tüm çıktılar `e()` ile kaçışlı.
- price_rules hesaplama birim testleri (yuvarlama, üst/alt sınırlar) test runner'da.
- Görseller `public/` altındaki assets alanına güvenli adlarla kaydediliyor; orijinaller tedarikçi kaynakta kalıyor.

**Bağımlılıklar:** Faz 2 (ürünler import'la geliyor; price_rules fiyat hesabı import sırasında devreye girer).
**Etkilenen klasörler:** `app/Controllers/Admin/`, `app/Services/Import/` (görsel adımı), `app/Views/admin/`, `public/assets/`. **Tablolar:** products, product_variants, product_images, categories, product_supplier_data, price_rules.

---

## Faz 4 — Vitrin, Sepet & Checkout

**Hedef:** Müşteri ürünleri gezip satın alabilsin; ödeme iyzico Checkout Form ile alınsın, webhook'lar idempotent işlensin.

**Kapsam:**
- Vitrin: ana sayfa, kategori/ürün listeleme, ürün detay, arama (stok durumları tedarikçi verisinden).
- Müşteri kaydı/girişi, adres defteri (`customers`, `addresses`).
- Sepet: `carts`/`cart_items` (oturum + giriş sonrası birleştirme), stok kontrollü adet güncelleme.
- Checkout: adres seçimi, sipariş özeti, iyzico Checkout Form entegrasyonu (3D Secure, önce sandbox).
- Webhook alıcı uç: `webhook_events` tablosuyla **idempotent** işleme; ödeme sonucu `payments`'a snapshot.
- Sipariş oluşumu: `orders` (+ adres JSON snapshot) + `order_items` (+ name/sku/price SNAPSHOT).

**Kabul Kriterleri:**
1. Sandbox ortamında başarılı/başarısız ödeme senaryoları uçtan uca çalışır; sipariş yalnız ödeme onayı sonrası oluşur.
2. Aynı iyzico webhook birden çok kez geldiğinde yalnız ilk işlenir (webhook_events tekil event kimliği); yanıt her seferinde 2xx döner.
3. order_items içindeki ad/SKU/fiyat, sonraki katalog değişimlerinden bağımsız kalır (snapshot testi).
4. Sepet adetleri tedarikçi stoğunu aşamaz; ödeme anında stok yeniden doğrulanır.

**Tanım of Done:**
- Ödeme anahtarları yalnız `.env`/Env üzerinden; kodda gömülü secret yok.
- Webhook ucu CSRF muaf ama imza/doğrulama kontrollü; tüm webhook trafiği loglanıyor.
- Sipariş e-postası (temel bildirim) gönderiliyor (Faz 6'da kuyruk/şablon genişler).
- Test runner'a sepet toplama/kupon yok-snapshot birim testleri eklenmiş.

**Bağımlılıklar:** Faz 3 (satılabilir katalog + fiyat kuralları).
**Etkilenen klasörler:** `app/Controllers/` (Storefront), `app/Services/` (Cart, Checkout, Payment), `app/Views/` (vitrin), `config/` (iyzico ayarları). **Tablolar:** customers, addresses, carts, cart_items, orders, order_items, payments, webhook_events, audit_logs.

---

## Faz 5 — Dropshipping Operasyonu

**Hedef:** Ödenen siparişler tedarikçiye otomatik iletildiği takiple, teslimat müşteriye ulaşsın; stok/fiyat senkronu cron ile canlı kalsın.

**Kapsam:**
- `supplier_orders` otomasyonu: sipariş → tedarikçi bazlı gruplama → dropship sipariş kaydı üretimi.
- Tedarikçi bildirimi: e-posta (şablonlu) ve/veya basit API gönderimi; gönderim durumu ve yeniden deneme.
- Shipment takibi: `shipments` kayıtları, takip numarası girişi (admin), müşteriye durum güncellemesi.
- Stok/fiyat senkron cron'ları: periyodik hızlı stok/fiyat çekimi (tam feed importundan bağımsız hafif akış).
- Sipariş durum makinesi: pending → paid → sent_to_supplier → shipped → delivered / cancelled (+ kısmi teslim politikası).

**Kabul Kriterleri:**
1. Ödeme onaylı her sipariş, doğru tedarikçiye doğru satırlarla iletilir; başarısız bildirim retry kuyruğuna düşer ve loglanır.
2. Takip numarası girildiğinde müşteri sipariş durumunu görebilir; durum geçişleri audit_logs'a yazılır.
3. Stok/fiyat senkron cron'u, ürün başına tedarikçi verisini güncel tutar; tedarikçi stoğu biten ürün vitrinde tükenir olarak görünür.
4. İptal/iade akışı: tedarikçi iptali sipariş durumuna ve (gerekiyorsa) iade süreç notlarına yansır.

**Tanım of Done:**
- Durum makinesi geçersiz geçişleri reddediyor (unit test).
- Cron'lar flock kilidili; senkron sonuçları xml_import_logs/uygun log kanalına yazılıyor.
- Tedarikçi bildirim şablonları config'den yönetiliyor; kimlik bilgileri .env'de.

**Bağımlılıklar:** Faz 4 (gerçek sipariş verisi), Faz 2 (tedarikçi feed bağlantıları).
**Etkilenen klasörler:** `app/Services/` (Dropship, Sync), `cron/`, `app/Views/admin/`. **Tablolar:** supplier_orders, supplier_order_items, shipments, orders, product_supplier_data, audit_logs.

---

## Faz 6 — Güçlendirme (Hardening)

**Hedef:** Sistemi kötüye kullanıma, arızalara ve veri kaybına karşı dayanıklı hale getirmek; operasyon prosedürlerini yazılı hale getirmek.

**Kapsam:**
- Rate limiting: giriş/kayıt/checkout/webhook uçlarında IP+oturum bazlı sınır (IpGuard/Security servisi genişletmesi).
- E-posta doğrulama: müşteri kaydında doğrulama bağlantısı; doğrulanmamış hesap politikası.
- Şifre sıfırlama: token tabanlı, tek kullanımlık, süre sınırlı akış.
- 2FA (admin): TOTP tabanlı zorunlu ikinci adım; kurtarma kodları.
- Yedekleme/restore: DB dump + `storage/` yedekleme betikleri, restore prosedürü dokümanı, periyodik test.
- Log rotasyonu: logrotate yapılandırması (app.log, security.log).
- Monitoring: health-check uç noktasını dış izlemeye bağlama (alerting), disk/doluluk ve cron başarısızlık uyarıları.

**Kabul Kriterleri:**
1. Rate limit aşımları 429 döndürür ve security.log'a yazılır; whitelist (admin tanımlı) çalışır.
2. Şifre sıfırlama token'ı tek kullanımlık ve süreli; eski token geçersiz.
3. Admin girişi 2FA olmadan tamamlanamaz; TOTP doğrulama test edilmiş.
4. Yedekten geri yükleme, temiz bir ortamda yazılı prosedürle başarılı sonuçlanır (restore tatbikatı not edilir).
5. logrotate yapılandırması kurulmuş; loglar boyut/tarih bazında döner.

**Tanım of Done:**
- Güvenlik olayları tek formatta loglanıyor (alerting ayrıştırabilir).
- Yedekleme cron'a bağlı ve yedek bütünlüğü kontrolü var.
- Bu fazın dokümantasyonu (ops notları) `docs/` altına eklenmiş.

**Bağımlılıklar:** Faz 4 (müşteri akışları), Faz 5 (operasyon cron'ları).
**Etkilenen klasörler:** `app/Core/` (Session/Logger), `app/Services/Security/`, `cron/`, `storage/logs/`. **Tablolar:** customers, admin_users, audit_logs (+ varsa yeni rate-limit/meta tablosu).

---

## Faz 7 — Performans & SEO

**Hedef:** Vitrin hızlı ve arama motoru dostu olsun; trafik artışında yanıt süreleri kararlı kalsın.

**Kapsam:**
- Önbellekleme: query cache (yaygın kategori/ürün listeleri) + view/parça cache; `storage/cache/` kullanımı, TTL ve temizleme komutu.
- Sitemap üretimi: ürünler + kategoriler (cron ile güncellenen XML sitemap).
- Canonical URL'ler, meta başlık/açıklama yönetimi, yapılandırılmış veri (ürün şeması).
- CDN hazırlığı: statik varlık sürümlendirme (cache-busting), CDN uyumlu başlıklar.
- Görsel lazy-load + responsive görsel boyutları.

**Kabul Kriterleri:**
1. Kategori/ürün sayfaları önbellek ısındığında hedeflenen yanıt süresinin altında yanıt veriyor (ölçüm loglanıyor).
2. Sitemap geçerli XML olarak sunuluyor ve ürün/kategori değişimlerini (Faz 5 senkronuyla) yansıtıyor.
3. Ürün sayfalarında canonical + OpenGraph + ürün yapılandırılmış verisi doğrulanıyor.
4. Cache temizleme (import sonrası otomatik + manuel komut) çalışıyor; eski cache doğrudan servis edilmiyor.

**Tanım of Done:**
- Cache katmanı opsiyonel (config ile kapatılabilir); cache hatası sayfayı bozmuyor.
- Lighthouse/temel performans kontrolü kayda geçirilmiş.
- Statik varlık URL'leri sürümlendirilmiş.

**Bağımlılıklar:** Faz 3-5 (gerçek katalog ve trafik desenleri).
**Etkilenen klasörler:** `app/Core/` (Cache), `app/Views/`, `cron/`, `storage/cache/`, `public/assets/`. **Tablolar:** doğrudan şema değişikliği beklenmiyor (cache meta varsa ekleme).

---

## Faz 8 — Yayına Alma Checklist'i

**Hedef:** Production'a güvenli, izlenebilir ve mevzuata uyumlu geçiş.

**Kapsam (checklist):**
- [ ] Prod `.env`: APP_DEBUG kapalı, güçlü APP_KEY, gerçek iyzico anahtarları (secret kodda yok).
- [ ] TLS: Nginx sertifikası, HSTS, yönlendirme (http→https).
- [ ] DB kullanıcı en-az-yetki: uygulama kullanıcısı yalnız gerekli DML yetkileri (DDL yalnız migration kullanıcısında).
- [ ] Nginx: `storage/`, `config/`, `app/`, `.env` için deny; yalnız `public/` kök.
- [ ] PenTest listesi: SQLi (prepared doğrulaması), XSS (e() kapsama), CSRF, SSRF (UrlFetcher bypass araması), XXE, upload kaçakları, oturum sabitleme, yetkisiz admin erişimi.
- [ ] KVKK/GDPR notları: müşteri verisi saklama süresi politikası, çerez bilgilendirmesi/onayı, loglarda kişisel veri minimizasyonu, veri silme/anonimleştirme prosedürü.
- [ ] Yedekleme zamanlaması prod'da aktif + restore tatbikatı.
- [ ] Monitoring/alerting prod izleme sistemine bağlı; cron takvimi (`crontab.example` → gerçek crontab) doğrulanmış.
- [ ] Rollback planı: önceki release tag'i + DB migration geri dönüş stratejisi (geri alınabilir migration disiplini).

**Kabul Kriterleri:**
1. Yukarıdaki maddelerin tamamı işaretlenmiş ve kanıt (ekran görüntüsü/log/rapor) `docs/` altına eklenmiş.
2. Production health-check yeşil; ilk gerçek sipariş + ilk gerçek feed import uçtan uca doğrulanmış.
3. PenTest listesinde kritik/yüksek bulgu kalmamış; orta bulgular için tarihli aksiyon planı var.

**Tanım of Done:**
- Yayın sonrası ilk hafta: hata oranları, import başarı oranı ve ödeme başarı oranı izlenmiş; worklog'a yayın kaydı düşülmüş.

**Bağımlılıklar:** Tüm önceki fazlar (özellikle Faz 6 güçlendirme).
**Etkilenen klasörler:** `config/`, `storage/logs/`, sunucu yapılandırması (Nginx, logrotate, crontab). **Tablolar:** şema değişikliği beklenmiyor; operasyonel doğrulama.

---

## Riskler ve Notlar

| Risk | Etki | Önlem |
|---|---|---|
| Tedarikçi feed formatı farklılıkları | Import hataları | Faz 2 esnek alan eşleme + dry-run + satır bazlı hata toleransı |
| Büyük feed'lerde bellek/zaman | Cron taşması | XMLReader streaming, flock kilidi, batch transaction, delta import |
| Ödeme webhook kaybı/tekrarı | Yanlış sipariş durumu | webhook_events idempotency + ödeme sorgulama (Faz 4) |
| Tedarikçi stok gecikmesi | Satılamayan ürün satışı | Faz 5 hızlı stok senkronu + checkout anı stok teyidi |
| Sandbox kısıtları (root/apt yok) | Ortam kurulumu | Statik PHP binary + portable MariaDB; migration testi temiz DB'de |
