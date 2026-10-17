# Canlıya Geçiş Kontrol Listesi (Aşama 6 §6)

> Her maddede kanıt alanı vardır — canlıya geçiş kararı **tüm kritik kutular işaretliyken** alınır.
> Kurulum: `docs/deployment.md` · Yedekleme: `docs/backup-recovery.md` · Performans: `docs/performance.md`
> Ödeme canlıya geçiş ayrıntıları: `docs/payment-iyzico.md` §11 · Aşama 5 canlı ayarları: `docs/phase5-report.md`

## A. Ortam ve yapılandırma

- [ ] `.env` dosyası `.env.production.example` temel alınarak **tüm alanları dolu** şekilde sunucuda oluşturuldu (`.env` repoya commit EDİLMEDİ)
- [ ] `APP_ENV=production`, `APP_DEBUG=0` (veya satır yok), `APP_KEY` 32+ byte üretildi (`openssl rand -base64 32`)
- [ ] `APP_URL=https://...` (https), `APP_FORCE_HTTPS=true`, `APP_ALLOWED_HOSTS=orneksite.com` ayarlandı
- [ ] DB ayrı kullanıcı: `DB_USERNAME=ecommerce_app` (root DEĞİL), güçlü parola
- [ ] `SESSION_SECURE=true`, `SESSION_LIFETIME` makul (600-86400 sn)
- [ ] `bin/console doctor` → **FAIL sayısı 0** (çıktı bu dosyaya eklenir): ___________
- [ ] `php database/migrate.php status` → "şema güncel" (31/31)

## B. XML tedarik zinciri

- [ ] Tedarikçi(lar) admin panelden gerçek bilgilerle eklendi (URL/bağlantı test edildi)
- [ ] XML kaynakları eklendi + eşleme profilleri gerçek feed örneğiyle doğrulandı (`xml:preview` çıktısı kontrol edildi)
- [ ] `xml:sync --feed=ID` elle çalıştırıldı → ürünler doğru fiyat/stok/görsel ile geldi
- [ ] Cron görevleri kurulu ve ÇALIŞIYOR (log kanıtı): `storage/logs/cron.log` içinde `[xml:sync]` satırları ve `xml_import_logs`'ta cron tetikli kayıtlar — son çalışma zamanı: ___________
- [ ] Fiyat kuralları (marj, yuvarlama) gerçek feed üzerinde doğrulandı; `PRICING_PROFIT_WARN` uyarıları gözden geçirildi
- [ ] Para birimi katsayıları (`XML_RATE_*`) güncel kur ile ayarlandı

## C. Ödeme (iyzico)

- [ ] `IYZICO_MODE=sandbox` ile Sandbox hesabı bağlandı (`IYZICO_API_KEY/SECRET_KEY` dolu)
- [ ] Sandbox'ta başarılı ödeme testi yapıldı (ekran görüntüsü/log referansı): ___________
- [ ] Sandbox'ta başarısız ödeme + `payment_failed` → yeniden ödeme akışı test edildi: ___________
- [ ] Sandbox'ta iptal + kısmi iade test edildi (admin ekranı + iyzico panel eşleşmesi): ___________
- [ ] Webhook doğrulaması (imza + duplicate engel) sandbox'ta doğrulandı: ___________
- [ ] **Canlıya geçiş:** `IYZICO_MODE=live` + gerçek anahtarlar yalnız sunucudaki `.env`'e girildi; `IYZICO_BASE_URL=https://api.iyzipay.com`
- [ ] İlk canlı işlem **küçük tutarlı** gerçek kartla yapıldı ve panelde doğrulandı: ___________
- [ ] iyzico panelde callback URL + webhook URL üretim adresine işlendi

## D. Tedarikçi sipariş ve kargo süreci

- [ ] Gerçek tedarikçi sipariş süreci testi **elle başlatıldı** (ödemesi doğrulanmış test siparişi → `supplier_orders` oluştu → tedarikçi panel/API'ine iletildi): ___________
- [ ] Tedarikçi entegrasyon tipi netleşti: `integration_type=api` ise gerçek uç bilgisi girişi yapıldı; manuel ise panel görev akışı ekiple paylaşıldı
- [ ] Kargo süreci test edildi: takip numarası girildi → sipariş `shipped`/`delivered` durumuna geçti → müşteri takip sayfası doğru gösterdi
- [ ] `order:expire`, `supplier:process`, `supplier:query`, `shipments:sync`, `notifications:send` cron'ları loga düşüyor

## E. E-posta ve bildirimler

- [ ] `MAIL_DRIVER=smtp` + gerçek SMTP hesabı (host/port/kullanıcı/parola) yapılandırıldı
- [ ] Sipariş onay e-postası gerçek hesaba ulaştı (spam klasörü dahil kontrol): ___________
- [ ] Gönderen alan adı DNS notları: **SPF** (`v=spf1 include:... -all`), **DKIM** (SMTP sağlayıcıdan kayıt), **DMARC** (`p=quarantine` önerilir) eklendi — gönderen domain: ___________
- [ ] `notifications:send` kuyruğu gerçek SMTP ile boşaldı (dedup çalışıyor)

## F. SSL / DNS

- [ ] `https://` aktif, sertifika geçerli (`certbot certificates`)
- [ ] http→https yönlendirme + HSTS header var (`curl -I` çıktısı kontrol edildi)
- [ ] `certbot renew --dry-run` başarılı — otomatik yenileme timer'ı aktif
- [ ] DNS A/CNAME kayıtları doğru; www→apex yönlendirmesi net
- [ ] `/health` ucu izleme sistemine (UptimeRobot vb.) bağlandı

## G. Yedekleme ve GERİ DÖNÜŞ

- [ ] `cron/backup-daily.sh` cron'a eklendi (03:30) ve ilk yedek oluştu: `storage/backups/xml_ecommerce_*.sql.gz`
- [ ] `storage/logs/backup.log`'da `status=OK` satırı var: ___________
- [ ] **GERİ YÜKLEME TESTİ yapıldı** — `scripts/verify-backup.sh` çıktısı buraya eklenir:

```
(verify-backup.sh çıktısı — orders/payments/products/customers satır sayıları + "SONUÇ: OK")
```

- [ ] Offsite yedek kurgusu çalışıyor (`OFFSITE_CMD` + `offsite=ok` log satırı) veya alternatifi dokümante edildi
- [ ] `BACKUP_GPG_RECIPIENT` şifreleme kararı alındı (açık/kapalı + gerekçe): ___________

## H. Loglar ve güvenlik

- [ ] `storage/logs/app.log` + `security.log` yazılıyor (hatalı istek test edildi → security.log'a düştü)
- [ ] `audit_logs` tablosu admin işlemlerini kaydediyor
- [ ] Nginx error/access log'ları döndürülüyor (logrotate test: `sudo logrotate -d /etc/logrotate.d/xml-ecommerce`)
- [ ] fail2ban aktif (`fail2ban-client status sshd`), SSH parolasız giriş zorunlu, `PermitRootLogin no`
- [ ] ufw sadece 22/80/443 açıyor; 3306 dışarıya kapalı
- [ ] `APP_DEBUG=0` ile gerçek bir hata ekranı sızdırmıyor (404/500 testi)

## I. Hukuki ve içerik

- [ ] Gizlilik/KVKK sayfası gerçek işletme bilgileriyle düzenlendi — **TASLAK etiketi kaldırıldı**
- [ ] Mesafeli Satış Sözleşmesi + Ön Bilgilendirme gerçek koşullarla düzenlendi — **TASLAK kaldırıldı**
- [ ] İade ve Teslimat sayfası gerçek kargo/iade koşullarıyla düzenlendi — **TASLAK kaldırıldı**
- [ ] İletişim sayfası gerçek adres/telefon/e-posta içeriyor; form test mesajı ulaştı
- [ ] Hukuk danışmanı metinleri onayladı (not): ___________
- [ ] Site başlığı/logolar/tema renkleri marka ayarlarıyla güncellendi; demo kuponlar ve `seed_store` sentetik verileri temizlendi/yalnız gerçek feed verisi kaldı

## J. Son gate — yayına alma kararı

- [ ] Tüm yukarıdaki kutular işaretli
- [ ] Yayın saati/dakikası planlandı (düşük trafik); geri alma planı: yedekten restore prosedürü (docs/backup-recovery.md §4) ekiple paylaşıldı
- [ ] Karar: tarih/saat — ___________ · Onaylayan — ___________

---

> **İlk canlı hafta:** günlük `doctor` + `backup.log` kontrolü, ilk gerçek siparişin uçtan uca (ödeme → tedarikçi → kargo → e-posta) takibi, `verify-backup.sh` bir kez daha çalıştırılması önerilir.
