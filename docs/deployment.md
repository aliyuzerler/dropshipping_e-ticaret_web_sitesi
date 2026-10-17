# Üretim Kurulumu — Ubuntu VPS (Aşama 6 §1)

> Hedef ortam: **Ubuntu 22.04 / 24.04 LTS + Nginx + PHP 8.3 FPM + MariaDB/MySQL 8**.
> Tüm komutlar kopyala-yapıştır formatındadır. Kök dizin standardı: `/var/www/xml-ecommerce`.
> Sunucuya özgü denetim sonrası: `php bin/console doctor` (bkz. §11 ve `docs/go-live-checklist.md`).

---

## İçindekiler

1. [Sistem hazırlığı](#1-sistem-hazırlığı)
2. [SSH sertleştirme](#2-ssh-sertleştirme)
3. [Güvenlik duvarı (ufw)](#3-güvenlik-duvarı-ufw)
4. [Nginx kurulumu ve sunucu bloğu](#4-nginx-kurulumu-ve-sunucu-bloğu)
5. [PHP 8.3 FPM yapılandırması](#5-php-83-fpm-yapılandırması)
6. [MySQL/MariaDB kurulumu ve kullanıcıları](#6-mysqlmariadb-kurulumu-ve-kullanıcıları)
7. [Composer + Git ve deploy akışı](#7-composer--git-ve-deploy-akışı)
8. [Dosya izinleri](#8-dosya-izinleri)
9. [HTTPS (Let's Encrypt)](#9-https-lets-encrypt)
10. [Cron ve logrotate](#10-cron-ve-logrotate)
11. [Sunucuya özgü uygulama komutları](#11-sunucuya-özgü-uygulama-komutları)
12. [Yaygın hatalar](#12-yaygın-hatalar)

---

## 1. Sistem hazırlığı

```bash
# Paket dizinini güncelle
sudo apt update && sudo apt upgrade -y

# Otomatik güvenlik güncellemeleri
sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure --priority=low unattended-upgrades   # "Yes" seçin
sudo systemctl enable --now unattended-upgrades

# Zaman dilimi (sipariş/ödeme zaman damgaları için kritik)
sudo timedatectl set-timezone Europe/Istanbul
timedatectl   # "Time zone: Europe/Istanbul" ve "NTP service: active" doğrulayın
```

> **Not:** Uygulamanın `.env` dosyasındaki `APP_TIMEZONE=Europe/Istanbul` ile sunucu saat dilimi aynı olmalıdır.

## 2. SSH sertleştirme

```bash
# 2.1 — Yönetim makinenizde anahtar üretin (zaten varsa atlayın)
ssh-keygen -t ed25519 -a 100 -C "yonetici@orneksite.com"

# 2.2 — Sunucuya kopyalayın
ssh-copy-id kullanici@SUNUCU_IP
```

```bash
# 2.3 — Sunucuda: parolasız sudo kullanan ayrı bir yönetici kullanıcı
sudo adduser deploy
sudo usermod -aG sudo deploy
sudo mkdir -p /home/deploy/.ssh && sudo chmod 700 /home/deploy/.ssh
sudo cp /home/KULLANICI/.ssh/authorized_keys /home/deploy/.ssh/
sudo chown -R deploy:deploy /home/deploy/.ssh && sudo chmod 600 /home/deploy/.ssh/authorized_keys
```

```bash
# 2.4 — SSH daemon sertleştirme
sudo cp /etc/ssh/sshd_config /etc/ssh/sshd_config.bak
sudo tee /etc/ssh/sshd_config.d/99-hardening.conf > /dev/null << 'EOF'
PasswordAuthentication no
PermitRootLogin no
PubkeyAuthentication yes
KbdInteractiveAuthentication no
X11Forwarding no
MaxAuthTries 3
EOF

# (OPSIYONEL) varsayılan portu değiştirin — örn. 2222:
# echo "Port 2222" | sudo tee -a /etc/ssh/sshd_config.d/99-hardening.conf
# ufw allow 2222/tcp   # YENİ portu firewall'da AÇMADAN bağlantıyı KAPATMAYIN

sudo sshd -t && sudo systemctl restart ssh

# 2.5 — fail2ban: kaba kuvvet denemelerini engeller
sudo apt install -y fail2ban
sudo systemctl enable --now fail2ban
sudo fail2ban-client status sshd
```

> **UYARI:** Parola girişini kapatmadan önce **yeni bir terminalden** anahtarla giriş yapabildiğinizi doğrulayın. Aksi hâlde sunucudan kilitlenirsiniz.

## 3. Güvenlik duvarı (ufw)

```bash
sudo apt install -y ufw
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp     # SSH (port değiştirdiyseniz yeni portu yazın)
sudo ufw allow 80/tcp     # HTTP  (certbot doğrulaması + https yönlendirme)
sudo ufw allow 443/tcp    # HTTPS
sudo ufw enable
sudo ufw status verbose
```

> MariaDB **dışarıya açılmaz**: DB yalnız `localhost`/soket üzerinden erişilir; 3306 için ufw kuralı YOK.

## 4. Nginx kurulumu ve sunucu bloğu

```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

`/etc/nginx/sites-available/xml-ecommerce` dosyasını oluşturun:

```nginx
# XML E-Ticaret — üretim sunucu bloğu
# WEB KÖKÜ SADECE public/ — storage/, config/, app/ ... web'den ERİŞİLEMEZ.
server {
    listen 80;
    listen [::]:80;
    server_name orneksite.com www.orneksite.com;

    # (9. bölümde certbot bu bloğu otomatik https'e çevirecek)

    root /var/www/xml-ecommerce/public;      # SADECE public/
    index index.php;

    # XML feed yükleme (admin paneli) için: XML_UPLOAD_MAX_MB=100 → biraz pay
    client_max_body_size 120M;

    charset utf-8;

    # --- Güvenlik: gizli dosyalar (zaten web kökü dışında; derin savunma) ----
    location ~ /\.(?!well-known) { deny all; access_log off; log_not_found off; }
    location = /storage { deny all; }          # storage/ guard (yanlış symlink/yanlış root'a karşı)
    location ^~ /storage      { deny all; }
    location ~* ^/(app|config|database|docs|cron|scripts|tests|routes|bin)/ { deny all; }
    location = /composer.json { deny all; }
    location = /\.env         { deny all; }

    # --- PHP yürütme kapalı dizinler (uploads/imports içerik dizinleri) -------
    location ^~ /uploads/ { try_files $uri =404; location ~ \.php$ { return 403; } }

    # --- Statik varlıklar: sürüm değişene kadar agresif önbellek --------------
    location ~* \.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf)$ {
        expires 30d;
        add_header Cache-Control "public, max-age=2592000, immutable";
        try_files $uri =404;
        access_log off;
    }

    # --- Sitemap / robots doğrudan servis edilir ------------------------------
    location = /robots.txt  { try_files $uri =404; access_log off; }
    location = /sitemap.xml { try_files $uri =404; access_log off; }

    # --- Front controller ------------------------------------------------------
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME $fastcgi_script_name;
        fastcgi_read_timeout 300s;          # büyük XML senkron/yükleme
        # HTTPS bayrağını FPM'e güvenli aktar (session secure çerezi için):
        fastcgi_param HTTPS $https if_not_empty;
        fastcgi_param X-Forwarded-Proto $scheme;
    }

    # --- gzip -------------------------------------------------------------------
    gzip on;
    gzip_vary on;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_proxied any;
    gzip_types text/plain text/css text/xml application/xml application/json
               application/javascript application/rss+xml image/svg+xml;

    # --- Loglar ------------------------------------------------------------------
    access_log /var/log/nginx/xml-ecommerce.access.log;
    error_log  /var/log/nginx/xml-ecommerce.error.log warn;
}
```

```bash
sudo ln -sf /etc/nginx/sites-available/xml-ecommerce /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

> `HTTPS $https` parametresi, `SESSION_SECURE=true` iken çerezlerin doğru bayraklanması ve uygulamanın https base-url üretmesi için gereklidir. `X-Forwarded-Proto $scheme` ters proxy arkasında çalışıyorsanız mutlaka korunmalıdır.

## 5. PHP 8.3 FPM yapılandırması

```bash
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-xml php8.3-mbstring \
                    php8.3-curl php8.3-gd php8.3-zip php8.3-intl php8.3-opcache
php8.3 -v && php8.3 -m   # pdo_mysql, xmlreader, mbstring, curl, openssl, gd doğrulayın
```

`/etc/php/8.3/fpm/php.ini` (üretim ayarları — mevcut satırları düzenleyin):

```ini
; --- Hata gösterimi: EKRANA ASLA -------------------------------------------------
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php8.3-fpm/error.log
error_reporting = E_ALL

; --- Sürüm ifşası ---------------------------------------------------------------
expose_php = Off

; --- Kaynaklar ------------------------------------------------------------------
memory_limit = 256M                 ; XML_MAX_MEMORY_MB (256) ile uyumlu
upload_max_filesize = 120M          ; XML feed yükleme (XML_UPLOAD_MAX_MB=100 + pay)
post_max_size = 125M                ; upload_max_filesize'dan BÜYÜK olmalı
max_execution_time = 300            ; büyük XML senkronizasyonu
max_input_time = 300
max_input_vars = 5000

; --- OPcache --------------------------------------------------------------------
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1     ; deploy sonrası otomatik yenilenir
opcache.revalidate_freq = 60        ; 60 sn'de bir dosya değişimi kontrol edilir
```

> Deploy sonrası değişikliklerin anında yansımasını isterseniz: `sudo systemctl reload php8.3-fpm` (validate_timestamps=1 + revalidate_freq=60 zaten 1 dakika içinde yakalar).

Pool ayarları — `/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
; pm.dynamic — küçük VPS (2 vCPU / 4 GB RAM) için örnek:
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500

; Uzun süren XML işlemleri PHP-FPM worker'ını bloklamasın:
request_terminate_timeout = 300
request_slowlog_timeout = 30s
slowlog = /var/log/php8.3-fpm/slow.log

; Güvenlik:
php_admin_value[open_basedir] = /var/www/xml-ecommerce:/tmp:/var/log/php8.3-fpm
php_admin_value[disable_functions] = exec,shell_exec,system,proc_open,popen
```

**`pm.max_children` hesabı:** `max_children ≈ (RAM − sistem/mariadb payı) / ortalama PHP süreç belleği`.
Örn. 4 GB RAM, MariaDB ~1 GB, sistem ~0.5 GB → 2.5 GB PHP'ye; süreç başına ~100 MB → **25 çocuk**.
`sudo ps --no-headers -o rss -C php-fpm8.3 | awk '{s+=$1} END {print s/NR/1024" MB"}'` ile gerçek ortalamayı ölçün.

```bash
sudo mkdir -p /var/log/php8.3-fpm && sudo chown www-data:adm /var/log/php8.3-fpm
sudo php-fpm8.3 -t && sudo systemctl restart php8.3-fpm
```

> **CLI php.ini ayrıdır** (`/etc/php/8.3/cli/php.ini`): cron'daki `xml:sync` için `memory_limit=512M`, `max_execution_time=0` önerilir (XML_MAX_MEMORY_MB=256 limitinin üzerinde çalışma alanı bırakır).

## 6. MySQL/MariaDB kurulumu ve kullanıcıları

```bash
# Ubuntu 22.04/24.04: MySQL 8 → sudo apt install -y mysql-server
# MariaDB tercih ederseniz:
sudo apt install -y mariadb-server
sudo systemctl enable --now mariadb
sudo mysql_secure_installation
#   Switch to unix_socket authentication : N (zaten aktif)
#   Change the root password             : Y (güçlü parola)
#   Remove anonymous users               : Y
#   Disallow root login remotely         : Y
#   Remove test database                 : Y
#   Reload privilege tables              : Y
```

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS xml_ecommerce CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

**AYRI uygulama kullanıcısı** (root'u uygulama ASLA kullanmaz):

```sql
-- sudo mysql ile girip çalıştırın. PAROLAYI güçlü üretin:
-- openssl rand -base64 24
CREATE USER 'ecommerce_app'@'localhost' IDENTIFIED BY 'GUCLU_PAROLA_BURAYA';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON xml_ecommerce.* TO 'ecommerce_app'@'localhost';
FLUSH PRIVILEGES;
```

> Bu yetki seti uygulamayı çalıştırır **ve** `php database/migrate.php` şema değişikliklerini uygular. Sadece SELECT yetkisi verirseniz migration adımı başarısız olur.

**Yalnız yedek kullanıcısı** (dump işi için en-az-yetkili):

```sql
CREATE USER 'ecommerce_backup'@'localhost' IDENTIFIED BY 'BASKA_GUCLU_PAROLA';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER, PROCESS
  ON xml_ecommerce.* TO 'ecommerce_backup'@'localhost';
-- --single-transaction InnoDB'de LOCK TABLES gerektirmez ama MyISAM karışık
-- şemalarda korur; PROCESS, MySQL 8'de tablo listesi erişimi için istenir.
FLUSH PRIVILEGES;
```

Cron yedeği bu kullanıcıyla çalışır: `DB_USERNAME=ecommerce_backup` (bkz. `docs/backup-recovery.md`).

`.env` içine girilecek değerler:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=xml_ecommerce
DB_USERNAME=ecommerce_app
DB_PASSWORD=GUCLU_PAROLA_BURAYA
```

## 7. Composer + Git ve deploy akışı

```bash
sudo apt install -y git
# Composer (resmî installer):
curl -sS https://getcomposer.org/installer | php8.3
sudo mv composer.phar /usr/local/bin/composer && composer --version
```

**İlk kurulum / standart deploy akışı:**

```bash
cd /var/www
sudo git clone <REPO_URL> xml-ecommerce
sudo chown -R deploy:www-data /var/www/xml-ecommerce

cd /var/www/xml-ecommerce

# 1) Bağımlılıklar (üretim: dev paketleri + optimize autoload)
composer install --no-dev --optimize-autoloader

# 2) Ortam dosyası
cp .env.production.example .env
sudo nano .env            # DB, APP_URL=https://..., APP_KEY, iyzico live anahtarları...
sudo chown www-data:www-data .env && sudo chmod 640 .env

# 3) APP_KEY üret (henüz yoksa):
php8.3 -r "echo 'APP_KEY=' . base64_encode(random_bytes(32)) . PHP_EOL;"   # çıktıyı .env'e yapıştırın

# 4) Migration + ilk seed
php8.3 database/migrate.php
php8.3 database/seed.php          # ilk admin şifresi tek sefer stdout'a yazılır

# 5) storage izinleri (bkz. §8)
sudo chown -R www-data:www-data storage
sudo chmod -R 750 storage

# 6) Yapılandırma denetimi
php8.3 bin/console doctor
```

**Sonraki deploy'lar (6 satır):**

```bash
cd /var/www/xml-ecommerce
sudo -u deploy git pull --ff-only
composer install --no-dev --optimize-autoloader
php8.3 database/migrate.php
php8.3 tests/lint.php && php8.3 bin/console doctor
sudo systemctl reload php8.3-fpm          # OPcache'ı tazele
```

> Deploy penceresi boyunca sipariş akışı kesintiye uğramasın isteniyorsa `sudo systemctl stop nginx` yerine kısa `reload` kullanın; migration'lar idempotenttir (IF NOT EXISTS / information_schema kontrollü).

## 8. Dosya izinleri

```bash
cd /var/www/xml-ecommerce

# storage/ tamamen www-data'ya (Nginx/PHP-FPM yazma hakları)
sudo chown -R www-data:www-data storage
sudo find storage -type d -exec chmod 750 {} \;    # dizinler 750
sudo find storage -type f -exec chmod 640 {} \;    # dosyalar 640

# .env: yalnız www-data okur
sudo chown www-data:www-data .env
sudo chmod 640 .env

# Kod: deploy sahibi, grup www-data (okuma yeterli)
sudo chown -R deploy:www-data app config database public routes cron scripts bin
sudo find . -type d -not -path "./storage*" -exec chmod 750 {} \;
sudo find . -type f -not -path "./storage*" -not -name ".env" -exec chmod 640 {} \;

# Public assets Nginx tarafından okunmalı:
sudo chmod 755 public public/assets

# Sağlama: doctor "storage/* yazılabilir" kontrolleri PASS olmalı
php8.3 bin/console doctor
```

> `storage/` web kökünde DEĞİLDİR (`public/` dışında) — Nginx bloğundaki `/storage deny all` guard'ı yalnızca yanlış yapılandırmaya karşı ikinci savunmadır.

## 9. HTTPS (Let's Encrypt)

```bash
sudo apt install -y certbot python3-certbot-nginx

# Sertifikayı al ve Nginx'i otomatik yapılandır:
sudo certbot --nginx -d orneksite.com -d www.orneksite.com

# Otomatik yenileme doğrulaması:
sudo systemctl list-timers | grep certbot
sudo certbot renew --dry-run
```

Certbot sonrası sunucu bloğuna **HSTS** ekleyin (`/etc/nginx/sites-available/xml-ecommerce`, 443 server block):

```nginx
# HTTPS'i zorla + HSTS (bir kez https'e girdikten sonra tarayıcı 1 yıl https ister)
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options SAMEORIGIN always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
```

http→https yönlendirme bloğu (certbot genelde ekler; yoksa elle):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name orneksite.com www.orneksite.com;
    return 301 https://orneksite.com$request_uri;
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -I https://orneksite.com   # HTTP/2 200 + strict-transport-security görülmeli
```

> Uygulama tarafında `APP_URL=https://orneksite.com` ve `SESSION_SECURE=true` olmalı; ayrıca `APP_FORCE_HTTPS=true` (bkz. `.env.production.example`).

## 10. Cron ve logrotate

```bash
sudo crontab -u www-data -e
# cron/crontab.example içeriğini yapıştırın (/var/www yolunu doğrulayın).
# Aşama 6 şablonunda tüm satırlar flock -n ile korunur ve gece 03:30 yedeği içerir.
sudo crontab -u www-data -l    # doğrulama
```

logrotate örneği — `/etc/logrotate.d/xml-ecommerce`:

```
/var/www/xml-ecommerce/storage/logs/*.log {
    weekly
    rotate 8
    size 20M
    missingok
    notifempty
    compress
    delaycompress
    copytruncate          # PHP dosya tanıtıcısını kaybetmez; döndürme sonrası yeniden açma gerekmez
    su www-data www-data
}
```

> `copytruncate` kullanılmazsa PHP log dosyası tanıtıcısı kopar ve kayıtlar eski inode'a yazılmaya devam eder. Nginx logları sistem logrotate'i (`/etc/logrotate.d/nginx`) tarafından yönetilir.

## 11. Sunucuya özgü uygulama komutları

```bash
cd /var/www/xml-ecommerce

php8.3 bin/console doctor              # üretim yapılandırma denetimi (PASS/WARN/FAIL)
php8.3 database/migrate.php status     # migration durumu
php8.3 database/migrate.php            # bekleyen migration'ları uygula (idempotent)
php8.3 bin/console feed:list           # XML kaynaklarını listele
php8.3 bin/console xml:sync --all      # zamanı gelen tüm kaynakları senkronize et
php8.3 bin/console xml:sync --feed=1   # tek kaynağı hemen senkronize et
php8.3 bin/console xml:preview --feed=1 --limit=5   # eşleme profilini doğrula (yazmaz)
php8.3 bin/console order:expire        # süresi geçen ödeme bekleyen siparişleri iptal et
php8.3 bin/console supplier:process    # tedarikçi sipariş kuyruğunu işle
php8.3 bin/console notifications:send  # e-posta bildirim kuyruğunu boşalt

APP_DIR=/var/www/xml-ecommerce scripts/backup-database.sh   # anlık yedek
APP_DIR=/var/www/xml-ecommerce scripts/verify-backup.sh     # yedek geri dönüş testi (ayda 1)

curl -s https://orneksite.com/health   # 200 {"status":"ok",...} — izleme ucu
tail -f storage/logs/app.log storage/logs/security.log
```

## 12. Yaygın hatalar

| Belirti | Neden | Çözüm |
|---|---|---|
| **502 Bad Gateway** | PHP-FPM kapalı/çökmüş, soket yolu yanlış, `pm.max_children` tükenmiş | `systemctl status php8.3-fpm`; Nginx'teki `fastcgi_pass` yolu ile `/run/php/php8.3-fpm.sock` eşleşmesini doğrula; `pm.max_children` değerini bellek hesabıyla büyüt; `sudo journalctl -u php8.3-fpm -n 50` |
| **413 Request Entity Too Large** | `client_max_body_size` yetersiz (XML feed yükleme) | Nginx server block: `client_max_body_size 120M` + PHP: `upload_max_filesize=120M`, `post_max_size=125M`; her ikisini de reload et |
| **"session_start(): Permission denied" / oturum açılmıyor** | `storage/` izinleri www-data'ya ait değil | `chown -R www-data:www-data storage && chmod -R 750 storage`; doctor "storage/\* yazılabilir" PASS olmalı |
| **Session çerezi set edilmiyor (https'te)** | `SESSION_SECURE=true` + Nginx `HTTPS $https` parametresi eksik | fastcgi bloğuna `fastcgi_param HTTPS $https if_not_empty;` ekle |
| **500 — boş beyaz sayfa** | `APP_DEBUG` kapalıyken hata yalnız logda | `tail -f storage/logs/app.log /var/log/php8.3-fpm/error.log`; debug'ı geçici açma — loga bak |
| **migration "Access denied"** | DB kullanıcısı ALTER/CREATE/INDEX/DROP yetkisiz | §6 SQL bloğundaki GRANT setini uygula (uygulama kullanıcısı migration yetkilerine sahip olmalı) |
| **xml:sync cron çalışmıyor** | PHP CLI yolu/izin sorunu, flock kilitli | `grep CRON /var/log/syslog`; `crontab -u www-data -l`; `storage/logs/cron.log`; kilit dosyaları: `ls /tmp/*.lock` |
| **Görseller/asset'ler 404** | `root` public/ dışını gösteriyor veya asset yolu yanlış | Nginx `root /var/www/xml-ecommerce/public;` yalnız public olmalı |
| **"Storage konumu FAIL" (doctor)** | yanlışlıkla storage public/ içine taşınmış | storage/ her zaman `<app>/storage` kalmalı (public dışı) |

---

**Sonraki adım:** `docs/go-live-checklist.md` — canlıya geçiş öncesi tam kontrol listesi.
**Yedekleme/kurtarma:** `docs/backup-recovery.md` · **Performans:** `docs/performance.md`
