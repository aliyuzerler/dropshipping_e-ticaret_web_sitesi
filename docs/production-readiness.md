# Üretim Hazırlık Raporu — AŞAMA 6

Proje: XML tabanlı stoksuz satış e-ticaret uygulaması (PHP 8.3 · MySQL/MariaDB · Nginx/PHP-FPM hedefli)
Denetim tarihi: AŞAMA 6 · Kod tabanı: Aşama 1-5 (166 test) + Aşama 6 düzeltmeleri (33 test) = **199 test / 0 başarısız**
Raporun niteliği: Bu rapor **dürüstlük ilkesiyle** yazılmıştır — bu ortamda gerçekleştirilemeyen testler (gerçek iyzico anahtarı, gerçek tedarikçi/kargo API'si, SMTP hesabı, gerçek VPS) **DOĞRULANMIŞ olarak raporlanmaz.**

---

## 1. Sonuç Kararı

> **Uygulama bugün itibarıyla CANLIYA HAZIR DEĞİLDİR — ancak canlıya geçiş için yalnızca SUNUCU İŞLETİMİ ve HARİCÎ HESAP adımları kalmıştır.**

- Kod tarafı (güvenlik, idempotency, yönetim, test): ✅ hazır — 199/199 test yeşil, lint 278 dosya/0 hata.
- Sunucu tarafı: ⏳ `bin/console doctor` sandbox .env'i ile **3 FAIL** verir (http URL, root DB kullanıcısı, boş DB parolası) — bunlar *bilinçli sandbox yansımasıdır* ve gerçek VPS'te `docs/deployment.md` izlenerek çözülür.
- Haricî entegrasyonlar: ⏳ iyzico Sandbox anahtarı, SMTP hesabı, tedarikçi API bilgisi, kargo API'si henüz alınmadı.

Canlıya hazır işareti, `docs/go-live-checklist.md`'deki tüm kutular kanıtla işaretlendiğinde verilir (bkz. §7).

---

## 2. Tamamlanan Kontroller

| Alan | Kontrol | Sonuç | Kanıt |
|---|---|---|---|
| Test süitesi | 199 test (166 eski + 33 yeni güvenlik) | ✅ 0 başarısız | `tests/run.php`, `storage/test-results.json` |
| Lint | `php -l` tüm proje | ✅ 278 dosya / 0 hata | `tests/lint.php` |
| Güvenlik denetimi | 15 başlık (§3) | ✅ 15/15 MEVCUT | `docs/security-audit.md` |
| Üretim yapılandırması otomasyonu | `bin/console doctor` (22 kontrol) | ✅ çalışıyor; sandbox'ta 16 PASS/3 WARN/3 FAIL (beklenen) | DoctorCommand |
| VPS kurulum dokümanı | Nginx/PHP-FPM/MySQL/Composer/Git/HTTPS/Cron | ✅ yazıldı | `docs/deployment.md` |
| Yedekleme | backup + restore + **verify** scriptleri | ✅ sandbox'ta gerçek koşum: `backup.log` → `OK` + `VERIFY_OK tables=30` | `scripts/*.sh`, `docs/backup-recovery.md` |
| Performans | EXPLAIN analizi (80k satır sentetik ölçekleme) | ✅ ürün listeleme 74,7 ms → 0,065 ms (yeni composite indeks) | `docs/performance.md`, migration 031 |
| Cron çakışma önleme | kabuk `flock` + uygulama `FeedLock` iki katman | ✅ `cron/crontab.example` tüm satırlar flock'lu | crontab.example |
| E2E regresyon (tarayıcı) | kayıt→ürün→sepet→checkout→mock ödeme→paid→tedarikçi siparişi→admin | ✅ akış sağlam; `ORD-20261017-449BC` | worklog 6-a |
| Sağlık uçları | `/health` 200, `sitemap.xml` 200, `robots.txt` 200, `/admin` 302, 404 doğru | ✅ | curl çıktıları |
| Güvenlik başlıkları | CSP, X-Frame-Options DENY, nosniff, Referrer-Policy | ✅ tüm yanıtlarda | `SecurityHeadersMiddleware` + test |

---

## 3. Başarısız Testler

**Kod testi bazında başarısız test YOKTUR** (199/199).

Doctor'un sandbox ortamında **FAIL** verdiği 3 madde, test başarısızlığı değil, *sandbox `.env` yapılandırma gerçeğidir* ve canlıya geçişte zorunlu düzeltmelerdir:

| Doctor kontrolü | Sandbox değeri | Canlıda yapılacak |
|---|---|---|
| APP_URL | `http://localhost:8000` | `https://<alanadi>` (certbot sonrası) |
| DB kullanıcısı | `root` | Ayrı `ecommerce_app` kullanıcısı (deployment.md §6 SQL hazır) |
| DB parolası | boş | Güçlü parola |

WARN'lar (3): crontab sunucuda kurulacak, `.env` izni 0640 önerilir, `intl`/`opcache` eklentileri (portable PHP'de yok; VPS'te apt ile gelir).

---

## 4. Düzeltilen Güvenlik Açıkları (AŞAMA 6'da)

| # | Açık | Önem | Düzeltme |
|---|---|---|---|
| 1 | `address_form.php` `$val()` kaçışsız `value=` çıktısı (reflected + stored XSS) | **YÜKSEK** | Kapanış içi tek noktadan `e()`; çift kodlama testle reddedilir |
| 2 | JSON-LD `json_encode` `</script>` taşması (2. mertebe stored XSS) | **YÜKSEK** | `JSON_HEX_TAG \| JSON_HEX_AMP` (JSON-LD geçerliliği korunur) |
| 3 | Logger'da hassas veri redaksiyonu yok (password/card/cvv/secret/token) | **YÜKSEK** | `Logger::write()` recursive maskeleme (anahtar-icerir kuralı) |
| 4 | register / parola sıfırlama / iletişim formu hız sınırı yok | ORTA | Yeni `RateLimiter` (dosya tabanlı); reset yanıtı enumeration'a karşı yine generic |
| 5 | HTTPS zorlama yok | ORTA | `HttpsEnforcementMiddleware` (`APP_FORCE_HTTPS`), proxy-uyumlu, oturum öncesi |
| 6 | Host header kısıtlaması yok (host-injection / reset-link poisoning) | ORTA | `TrustedHostMiddleware` (`APP_ALLOWED_HOSTS`), XFH spoof → 400 |

**Bilinçli olarak bu aşamada düzeltilmeyen (belgelenmiş):** RBAC rol farkı — `admin_users.role` saklanıyor ama rota bazlı yetki farkı uygulanmıyor (bugün tek-rol gerçekliğinde risk düşük; AŞAMA 7 adayı, `docs/security-audit.md` §4). Bu, canlıya geçişi engelleyen bir bulgu DEĞİLDİR ancak çoklu admin hesabı açılacaksa önce kapatılmalıdır.

---

## 5. Çalışmayan / Doğrulanmamış Entegrasyonlar (Dürüst Beyan)

| Entegrasyon | Durum | Ne zaman "doğrulanmış" olur |
|---|---|---|
| **iyzico Checkout Form** | Mock modda uçtan uca çalışıyor; gerçek Sandbox API çağrısı **yapılmadı** (anahtar yok) | `IYZICO_MODE=sandbox` + gerçek anahtar ile Sandbox test siparişi → docs/go-live-checklist.md B3 |
| **Tedarikçi API gönderimi** | Adapter sözleşmesi + idempotency + zaman aşımı disiplini sahte transport ile test edildi; gerçek tedarikçi API'si **bağlanmadı** (ortamda API bilgisi yok — sahte entegrasyon bilinçli üretilmedi) | Gerçek API'li tedarikçide integration_type=api + test siparişi → checklist D |
| **Kargo API** | Manuel takip girşi çalışıyor; adapter kayıt noktası hazır; gerçek kargo API'si yok | Gerçek carrier adapter'ı yazılıp test siparişiyle doğrulanınca |
| **E-posta (SMTP)** | `MAIL_DRIVER=log` (mail.log'a yazıyor) + SmtpMailer kodu hazır; gerçek SMTP hesabıyla **gönderim testi yok** | Gerçek SMTP kimlik bilgisi + checklist E |
| **Canlı ödeme (live)** | Hiç denenmedi (bilinçli — Aşama 4 kuralı) | Sandbox başarılı olduktan sonra, iyzico canlı onay süreciyle |
| **VPS dağıtımı** | deployment.md hazır ama gerçek sunucuda **koşulmadı** | İlk VPS kurulumunda doküman izlenip doctor PASS alınca |

---

## 6. Gerekli Sunucu Ayarları (canlıya geçişte zorunlu)

Kısa liste — tam komutlar `docs/deployment.md` içinde:

1. **Nginx**: `root .../public` (yalnız public), `client_max_body_size 120M`, PHP-FPM unix socket, statik cache, dotfile/storage deny, HTTP→HTTPS 301, HSTS.
2. **PHP 8.3-FPM**: `display_errors=Off`, `log_errors=On`, `memory_limit=256M`, `upload_max_filesize=120M`, `max_execution_time=300`, `expose_php=Off`, OPcache açık.
3. **MySQL 8**: ayrı uygulama kullanıcısı (SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/INDEX/DROP/REFERENCES — root asla), ayrı yedek kullanıcısı (SELECT+LOCK TABLES), `utf8mb4_unicode_ci`.
4. **SSH/Firewall**: anahtarlı giriş (`PasswordAuthentication no`, `PermitRootLogin no`), ufw 22/80/443, fail2ban.
5. **İzinler**: `storage/` www-data yazılabilir (750/640), `.env` 640 + www-data sahipli, web kökü dışı.
6. **.env (üretim)**: `APP_ENV=production`, `APP_DEBUG` kapalı, `APP_URL=https://...`, `APP_FORCE_HTTPS=true`, `APP_ALLOWED_HOSTS=<alanadi>`, `SESSION_SECURE=true`, `IYZICO_MODE=sandbox→live`, `MAIL_DRIVER=smtp`, güçlü `APP_KEY` (bkz. `.env.production.example`).
7. **Cron**: `cron/crontab.example` → `crontab -u www-data` (tüm satırlar `flock -n` korumalı; xml:sync, order:expire, supplier:process/query, shipments:sync, notifications:send, gece backup, aylık verify-backup).
8. **HTTPS**: certbot + otomatik yenileme.
9. **Yedek**: `cron/backup-daily.sh` + offsite kancası (`BACKUP_OFFSITE_CMD`, örn. rclone) + ayda bir `verify-backup.sh`.

---

## 7. Canlıya Geçiş Ön Koşulları

`docs/go-live-checklist.md` tam listeyi kanıt alanlarıyla içerir. Kısa özet:

- [ ] A. Ortam değişkenleri (`.env.production.example` baz alınarak) + `bin/console doctor` → **0 FAIL**
- [ ] B. Migration'lar güncel (31/31) + iyzico **Sandbox** test siparişi başarılı
- [ ] C. XML cron görevleri çalışıyor (xml_import_logs'da kanıt) + tedarikçi sipariş süreci test edildi (manuel en az bir gerçek yönlendirme)
- [ ] D. Kargo süreci (manuel takip girişi) çalışıyor
- [ ] E. E-posta gönderimi gerçek SMTP ile test edildi (7 olaydan en az 2'si gerçek kutuya düştü)
- [ ] F. SSL etkin + otomatik yenileme + HSTS
- [ ] G. Yedekleme ÇALIŞIYOR ve **geri yükleme testi** (verify-backup.sh) yapıldı
- [ ] H. Hata ve güvenlik logları çalışıyor (app.log/security.log/payment.log/mail.log/backup.log)
- [ ] I. Hukuki sayfalar gerçek işletme bilgileriyle düzenlendi (TASLAK etiketleri kaldırıldı: KVKK, mesafeli satış, ön bilgilendirme, iade)
- [ ] J. RBAC kararı: çoklu admin hesabı açılacaksa rol middleware'i önce eklenmeli (§4 notu)

**Yukarıdakilerin tamamı kanıtlanmadan `IYZICO_MODE=live`'a geçilmemesi ve site "canlı" ilan edilmemesi zorunludur.**

---

## 8. AŞAMA 6'da Üretilen Dosyalar

- **Dokümanlar:** `docs/security-audit.md`, `docs/deployment.md`, `docs/backup-recovery.md`, `docs/performance.md`, `docs/go-live-checklist.md`, `docs/production-readiness.md` (bu rapor)
- **Scriptler:** `scripts/backup-database.sh`, `scripts/restore-database.sh`, `scripts/verify-backup.sh`, `cron/backup-daily.sh`, `.env.production.example`
- **Kod (minimum diff + eklemeler):** `RateLimiter`, `HttpsEnforcementMiddleware`, `TrustedHostMiddleware`, `DoctorCommand`, migration `031_phase6_indexes`, `Logger` redaction, XSS düzeltmeleri (2), rate-limit bağlantıları (3 controller), görsellere `width/height`
- **Testler (33 yeni):** `tests/Security/{SecurityHeadersTest, XssRenderingTest, HttpsHostTest, LogRedactionTest, RateLimitTest}.php`
