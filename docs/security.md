# Güvenlik Dokümanı — XML E-Ticaret (Aşama 1)

Kapsam: Aşama 1'de uygulanan güvenlik altyapısı ve tasarım kararları. OWASP Top 10 eşlemesi en sonda.

## 1. Güvenlik Sınırları (Trust Boundaries)

```text
Internet ──> Nginx (TLS) ──> public/            [TEK web kökü]
                                │  index.php (front controller)
                                ▼
                         PHP-FPM / CLI
        ┌───────────────────────┼─────────────────────────┐
        ▼                       ▼                         ▼
   app/ (kod)             config/ + .env             storage/
   routes/ database/      (gizli anahtarlar,         logs/ imports/ locks/
   cron/ tests/            web'den erişilemez)       cache/  (web'den erişilemez)
        │
        ▼
   MySQL 8 (yalnız localhost, en az yetkili DB kullanıcısı)
```

- Nginx `root` yalnız `public/`. `.env`, `storage/`, `app/`, `config/` web kökünde **değildir**; ek olarak Nginx `.env|\.git|storage` deny kuralı README'de verilir.
- `storage/imports/` içindeki XML dosyaları rastgele adla (`bin2hex(random_bytes(16))`) saklanır, dizin listeleme kapalıdır.

## 2. SQL Enjeksiyonu

- Tüm veri erişimi `app/Repositories/` içindedir; yalnız **PDO prepared statements** (`?` bağlayıcıları) kullanılır. String interpolasyonu ile SQL kurulması Repository katmanında yasaktır (istisna: tablo/kolon adları yalnız kod sabitlerinden gelir, kullanıcı girdisi asla birleştirilmez).
- `PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES => false` (gerçek prepared), `ATTR_STRINGIFY_FETCHES => false`.
- DB kullanıcısı en az yetki ilkesi: uygulama kullanıcısında `DROP DATABASE`, `GRANT`, `FILE` yetkisi yoktur (migration'lar ayrı/ yönetici bağlantısıyla çalışır).

## 3. Oturum Yönetimi (`app/Core/Session.php`)

- Çerez: `httponly=1`, `samesite=Lax`, `secure` (HTTPS varsa zorunlu), özel ad (`SESSION_NAME`, `sess_` değil), `path=/`.
- `session.use_strict_mode=1` — sabitlenmiş oturum id'si kabul edilmez.
- Kimlik doğrulama sonrası `session_regenerate_id(true)` (session fixation önlemi).
- Admin oturumu 45 dk inaktiflikte zaman aşımı (`last_activity` karşılaştırması), oturum verisi sunucuda; istemcide yalnız id bulunur.

## 4. CSRF (`app/Core/Csrf.php` + `Middleware/CsrfMiddleware.php`)

- Senkron token modeli: oturum başına `random_bytes(32)` token, formda `csrf_field()` ile `"_token"` gizli alanı.
- Tüm POST/PUT/PATCH/DELETE isteklerinde `hash_equals()` ile karşılaştırma (timing-safe); header `X-CSRF-Token` alternatifi (AJAX).
- Başarısız doğrulama → 419 sayfası + `security.log` kaydı.
- Login başarısında token yeniden üretilir.

## 5. XSS ve Çıktı Kodlaması

- Tüm şablon çıktıları `e()` yardımcısından geçer: `htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')`.
- `Content-Security-Policy` başlığı: `default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'` (Bootstrap/jQuery CDN Faz 4'e kadar; sonrasında SRI + self-host planlanır), artı `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`.
- Kullanıcı HTML'i kabul eden hiçbir alan Aşama 1'de yoktur; ileride html tipi alan eşlemede `HTMLPurifier` benzeri beyaz liste temizliği zorunlu tutulur (roadmap Faz 2).

## 6. Kimlik Doğrulama ve Yetkilendirme

- Şifre: `password_hash(..., PASSWORD_BCRYPT, ['cost' => 12])`, doğrulama `password_verify` + `password_needs_rehash`.
- Brute-force: `admin_users.failed_attempts` sayacı; 5 başarısız denemede `locked_until = now + 15 dk`; başarılı girişte sıfırlama. Tüm denemeler `audit_logs` + `security.log`'a yazılır (IP + user agent).
- Yetkilendirme: `role` sabitleri (`superadmin > admin > viewer`); `AdminAuthMiddleware` oturum + rol kontrolü yapar, başarısızsa `/admin/login` redirect. API/health uçları anonimdir, sadece okuma sunar.
- Oturum çerezi dışında hiçbir yetki taşıyıcı (JWT vb.) Aşama 1'de yoktur.

## 7. Dosya/XML Yükleme Güvenliği (`Validators/XmlUploadValidator.php`, `Services/Import/XmlUploadManager.php`)

Sırayla (hepsi geçilmeli):
1. PHP yükleme hatası kontrolü (`$_FILES[...]['error'] === UPLOAD_ERR_OK`).
2. Boyut: `XML_UPLOAD_MAX_MB` (varsayılan 100 MB) — hem `post_max_size` hem dosya bazlı kontrol.
3. Uzantı: yalnız `.xml` (küçük/büyük harf duyarsız).
4. MIME: `finfo` ile gerçek içerik tipi; beyaz liste `text/xml`, `application/xml`.
5. Content sniff: ilk baytlar `<?xml` / BOM+`<?xml` / `<` ile başlamalı.
6. **Zararlı DDL taraması:** ilk 64 KB içinde `<!DOCTYPE` veya `<!ENTITY` geçiyorsa reddet (XXE ikinci savunma hattı).
7. Kayıt: `storage/imports/YYYY/MM/` altında rastgele ad + `chmod 0640`; orijinal dosya adı yalnız log'da.
8. Yükleme uçları yalnız `AdminAuth + CSRF` arkasında.

## 8. XXE / XML Bomb Koruması (`Services/Import/XmlStreamReader.php`)

- `XMLReader` **streaming** açılır: bellekte tüm belge tutulmaz (XML bomb/quadritic blowup'e dayanıklı).
- Açılış bayrakları: `LIBXML_NONET` (ağ erişimi yok) | `LIBXML_COMPACT`.
- `libxml_set_external_entity_loader(fn() => null)` — harici DTD/varlık yüklemesi tamamen kapatılır; iş bitince eski loader geri yüklenir (`try/finally`).
- `LIBXML_NOENT` **kullanılmaz** (varlık ikamesi yapılmaz).
- `libxml_use_internal_errors(true)` + hata toplama: ayrıntılar kullanıcıya değil log'a gider.
- Upload validator'ının DOCTYPE/ENTITY reddi ek katman olarak uygulanır.

## 9. SSRF / DNS Rebinding / Yönlendirme (`Services/Security/IpGuard.php`, `Services/Http/UrlFetcher.php`)

Zamanlanmış görevlerin uzak XML URL'lerini çekme şekli:

1. **Şema:** yalnız `http`/`https`. **Port:** yalnız 80/443.
2. **DNS çözümleme:** host için tüm A/AAAA kayıtları çözülür; **tek bir kayıt bile özel/rezerve ise istek reddedilir** (fail-closed).
3. **Engellenen aralıklar:** loopback, `10/8`, `172.16/12`, `192.168/16`, `169.254/16` (link-local + metadata), `0/8`, `100.64/10` (CGNAT), `198.18/15`, multicast `224/4`, reserved `240/4`, IPv6: `::1`, `fe80::/10`, `fc00::/7`, `ff00::/8`, IPv4-mapped (`::ffff:a.b.c.d`) adresler IPv4 kurallarına göre değerlendirilir.
4. **DNS rebinding:** doğrulanan IP, `CURLOPT_RESOLVE` (host:port:ip) ile **sabitlenir** — ikinci saniyede DNS değişse bile istek doğrulanmış IP'ye gider. cURL yoksa istek yapılmaz (fallback yok).
5. **Redirect:** `CURLOPT_FOLLOWLOCATION` kapalı; her `3xx` adımında `Location` hedefi yeniden şema/port/IP doğrulamasından geçer, en fazla 3 atlama.
6. **Sınır kontroller:** toplam indirme boyutu (`max_file_size_mb`), bağlantı/toplam zaman aşımı, TLS sertifika doğrulaması **kapalıya alınmaz** (`VERIFYHOST`/`VERIFYPEER` aktif).
7. Kimlik doğrulama bilgileri (basic/header) feed kaydından çözülür; şifreler `APP_KEY` ile AES-256-GCM şifreli saklanır (Cipher AES-GCM, IV 12 byte random, tag saklanır).
8. Tüm reddedilen denemeler `security.log` + `audit_logs`'a yazılır.

## 10. Gizli Anahtar ve Yapılandırma

- `.env` dosyası web kökünde değildir, `.gitignore`'da; `APP_KEY` (32+ byte, base64) tüm simetrik şifrelemede kullanılır.
- Config dosyaları `config/*.php` dizi döndürür, değerler `Env::get()` ile gelir; kod içinde gizli anahtar sabiti **yoktur**.
- Hata sayfaları production'da mesaj/dosya/iz göstermez; ayrıntı yalnız `APP_ENV=local` iken görünür.
- `php -S` yalnız local geliştirme içindir; VPS'te Nginx + PHP-FPM kullanılır.

## 11. Loglama

| Kanal | Dosya | İçerik |
|---|---|---|
| Uygulama | `storage/logs/app.log` | istisnalar, import sonuçları (JSON satır) |
| Güvenlik | `storage/logs/security.log` | CSRF ihlali, SSRF reddi, login başarısız, upload reddi |
| Denetim | `audit_logs` tablosu | kim, ne, ne zaman, hangi kayıt |

Loglarda **şifre, token, tam kart numarası, API anahtarı asla yazılmaz**; IP/user-agent kaydedilir. Dosya izinleri `0640`, dizin `0750`.

## 12. OWASP Top 10 (2021) Eşlemesi

| Risk | Karşı önlem (bölüm) |
|---|---|
| A01 Broken Access Control | AdminAuth middleware + rol sabitleri, CSRF (§6, §4) |
| A02 Cryptographic Failures | bcrypt cost 12, AES-256-GCM, TLS zorunlu doğrulama (§6, §9, §10) |
| A03 Injection | Prepared-only PDO, XMLReader + XXE kapalı, e() kaçış (§2, §8, §5) |
| A04 Insecure Design | Snapshot tabanlı sipariş modeli, idempotent webhook, fail-closed guard'lar (database-design.md) |
| A05 Security Misconfiguration | Tek web kökü, production hata maskeleme, güvenlik başlıkları (§1, §5, §10) |
| A06 Vulnerable Components | Sıfır 3. parti PHP bağımlılığı (composer require boş), statik ikili PHP dokümante edilir |
| A07 Auth Failures | Kilitleme, oturum yenileme, strict mode (§6, §3) |
| A08 Integrity Failures | Webhook idempotency + imza doğrulama alanı, migration izi (§12, database-design.md) |
| A09 Logging Failures | Üç kanallı log + audit tablosu, PII/kart verisi yasağı (§11) |
| A10 SSRF | IpGuard + IP pinning + redirect re-validasyon (§9) |
