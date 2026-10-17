# XML Ürün Aktarım Motoru (Aşama 2)

Tedari̇kçi XML feed'lerinden ürün aktarma ve senkronizasyon modülünün teknik referansı.

> Bu doküman Aşama 2 kapsamını anlatır. Genel mimari için `docs/architecture.md`,
> şema için `docs/database-design.md`, güvenlik için `docs/security.md`.

Tam yol: docs/xml-import.md

---

## 1. Genel Akış

```text
Tedarikçi XML (dosya / URL)
        │
        ▼
[1] Kaynak alma ── URL: UrlFetcher (SSRF koruması) ── Upload: feed dizinine kayıtlı dosya
        │
        ▼
[2] XmlStreamReader ── XMLReader streaming, XXE kapalı, maxNodes erken durdurma
        │   <Urun> başına ham alan haritası (etiket | @nitelik | parent.child | a|b birleşim)
        ▼
[3] FieldMapper ── xml_field_mappings profili: source_path → target_field (+ transform)
        │   normalize DTO: supplier_sku, fiyat (para çevirimi), stok, görseller, varyantlar…
        ▼
[4] ProductSyncService ── satır bazlı idempotent upsert
        │   eşleştirme: (supplier_id, supplier_sku) → product_supplier_data
        │   yeni → products + psd (+ kategori/görsel/varyant)   mevcut → kurala göre güncelleme
        ▼
[5] XmlSyncEngine ── batch transaction (parçalı), feed kilidi, xml_import_logs sayaçları
        │   TAM ve doğrulanmış aktarım sonrası: deactivate_missing kuralıyla pasife alma
        ▼
products / product_supplier_data / product_price_history / xml_import_logs
```

## 2. XML Kaynakları (Feed) Yönetimi

Yönetim paneli: **Admin → XML Kaynakları** (`/admin/feeds`)

| Özellik | Açıklama |
|---|---|
| Dosya yükleme | Upload tipi kaynakta "Eşleştirme" ekranından XML dosyası yüklenir; boyut/uzantı/MIME/içerik denetiminden geçer, `storage/imports/feeds/{id}/` altına 0640 ile kaydedilir |
| HTTP/HTTPS URL | `source_url` kayıt anında SSRF doğrulamasından geçer; her senkronda taze indirilir (timeout, boyut limiti, redirect denetimi) |
| Kimlik bilgileri | `auth_type=basic` seçilirse kullanıcı adı/şifre AES-256-GCM ile şifreli saklanır (`Cipher`, `APP_KEY`) |
| Güncelleme sıklığı | `interval_minutes` — `xml:sync --all` yalnız süresi dolan kaynakları işler |
| Güncelleme kuralları | Feed başına: açıklama/görsel/fiyat/stok güncelleme anahtarları, `auto_publish`, `deactivate_missing` |
| SKU öneki | Ürün SKU'larına eklenir (örn. `OKT-` → `OKT-TK-1001`); tedarikçiler arası çakışmayı önler. **Eşleştirme anahtarına dahil değildir** — sonradan değiştirilse bile mevcut eşleşmeler bozulmaz |

Tedarikçiler: **Admin → Tedarikçiler** (`/admin/suppliers`) — birden fazla tedarikçi, her tedarikçi için birden fazla XML kaynağı yönetilebilir.

## 3. Alan Eşleştirme (Mapping Profilleri)

XML yapısı tedarikçiye göre değiştiği için **sabit etiket varsayılmaz**. Yönetici, yüklü örnek dosyadan
otomatik tespit edilen alanları sistem alanlarına bağlar. Profil `xml_field_mappings` tablosunda
kaynak başına saklanır ve dilediği zaman yeniden düzenlenebilir.

**Hedef alanlar (`FieldMapper::TARGETS`):**

| Hedef | Ürün alanı | Not |
|---|---|---|
| `supplier_sku` | Tedarikçi ürün kodu | **Zorunlu** — eşleştirme anahtarı |
| `barcode` | Barkod | |
| `name` | Ürün adı | **Zorunlu** |
| `description` | Açıklama | Feed'in `update_description` kuralına tabi |
| `category` | Kategori | Yoksa otomatik oluşturulur |
| `brand` | Marka | |
| `price` | Alış fiyatı | `number` dönüşümü önerilir (`1.234,56` / `1234.56` / `42,50 TL` / `%20`) |
| `currency` | Para birimi | TRY/USD/EUR/GBP → katsayıyla TRY'ye çevrilir; bilinmeyen para birimi satır hatasıdır |
| `vat` | KDV (%) | 0–100 aralığı dışı satır hatasıdır |
| `stock` | Stok miktarı | Negatif/sayısal olmayan değer satır hatasıdır |
| `image` | Görsel (çoklu) | Birden çok kaynak alanı bağlanabilir; değerler `\|` ile ayrılabilir |
| `variant` | Varyant (çoklu) | `beden:S\|M\|L` veya `renk:Kırmızı` biçimi |

**Dönüşümler (transform):** `upper`, `lower`, `number`, `int`, `strip_tags`, `default`.

**Desteklenen XML yapıları:**

```xml
<Urun URUN_KODU="TK-1008">          <!-- nitelik → @URUN_KODU -->
  <URUN_ADI>Defter</URUN_ADI>       <!-- basit çocuk -->
  <GORSEL>a.jpg</GORSEL>            <!-- tekrarlayan → a|b birleşim -->
  <images><img>a.jpg</img></images> <!-- 1 seviye iç içe → images.img -->
</Urun>
```

## 4. Ürün Eşleştirme ve Senkronizasyon Kuralları

1. **Eşleştirme:** `product_supplier_data` üzerinde `(supplier_id, supplier_sku)` — aynı XML tekrar
   işlendiğinde **kopya ürün oluşmaz** (aynı satır güncellenir).
2. **Yeni ürün:** `products` + `product_supplier_data` kaydı; `auto_publish` kapalıysa `draft`.
3. **Fiyat/stok:** feed'in `update_price`/`update_stock` anahtarları + satır bazlı **alan koruması**
   (`product_supplier_data.protect_price/stock/name/description/images`) → manuel düzenlenen alanlar
   XML tarafından ezilmez.
4. **Fiyat/stok geçmişi:** her değişiklik `product_price_history`'ye eski→yeni değerleri ve kâr marjıyla yazılır.
5. **Son başarılı senkron:** `xml_feeds.last_success_sync_at` yalnız tam başarıda güncellenir.
6. **Eksik ürünler:** XML'den düşen ürünler **hemen silinmez**. Yalnız **tam ve doğrulanmış aktarım**
   sonrası, feed'de `deactivate_missing` açıkken `status=passive` yapılır (`deactivated_rows` sayacı).
   Aynı tedarikçinin başka feed'i ürünü aynı koşumda görüyorsa pasife alınmaz.
7. **Başarısız aktarım:** katalog **korunur** — batch transaction geri alınır, pasife alma yapılmaz,
   `xml_import_logs` `failed` olarak kapanır.

## 5. Güvenlik ve Dayanıklılık

| Tehdit | Savunma |
|---|---|
| Sahte dosya | Uzantı + MIME (finfo) + içerik sniff + boyut limiti |
| XXE | DOCTYPE/ENTITY reddi, `LIBXML_NONET`, harici varlık yükleyici kapalı, `LIBXML_NOENT` yok |
| SSRF | Şema/port beyaz listesi, tüm DNS kayıtları public doğrulaması, IP sabitleme (`CURLOPT_RESOLVE`), redirect hedefleri tek tek doğrulanır, iç/ağ IP bloklama (`IpGuard`) |
| Eşzamanlılık | Kaynak başına `flock` kilidi (`storage/locks/feed_{id}.lock`) — kilitliyse aktarım güvenli şekilde atlanır |
| Kısmi yazım | Parçalı batch transaction + idempotent upsert; yarım kalan aktarım tekrar çalıştırılabilir |
| Geçersiz veri | Geçersiz fiyat/stok/para birimi/KDV **sessizce kabul edilmez** → satır hatası (`xml_import_logs.error_rows`, ilk 25 detay `error_message` alanına) |

## 6. Fiyatlandırma

Yönetim paneli: **Admin → Fiyat Kuralları** (`/admin/price-rules`)

Alış fiyatı ve satış fiyatı **ayrı saklanır** (`product_supplier_data.supplier_price` ↔ `products.sale_price`).
Kurallar senkronizasyon sırasında `priority ASC` sırasıyla uygulanır:

| Tip | Etki | Örnek |
|---|---|---|
| `percent` | `+value%` kâr | 100 → 130 |
| `fixed` | `+value TRY` sabit ekleme | kargo/maliyet payı |
| `min_price` | taban fiyat | `sale = max(sale, value)` |
| `round` | adıma yuvarlama | value=1 → tam sayı |
| `round_end` | psikolojik fiyat sonu | value=0.99 → `x,99` |

**Kapsamlar:** `global`, `supplier` (tedarikçi bazlı), `category` (üst kategori zinciri dahil), `product`.

**Vergi/maliyet modellemesi:** satış fiyatı varsayılan KDV hariç hesaplanır; `PRICING_TAX_IN_SALE=1`
ile KDV dahil saklanabilir (`products.price_includes_tax=1`). Sabit ek maliyetler `fixed` kuralıyla modellenir.

**Kârlılık eşiği uyarısı:** marj `PRICING_PROFIT_WARN` (varsayılan %10) altına düşerse satır
`is_low_margin=1` ile işaretlenir; **Admin → Güncellemeler → Yalnız Kâr Uyarıları** ekranında listelenir
ve aktarım `warnings` alanına özet yazılır.

## 7. CLI (bin/console)

```bash
php bin/console list                        # yardım
php bin/console feed:list                   # kaynakları listele
php bin/console xml:preview --feed=1        # eşleme profiliyle önizleme (yazmaz)
php bin/console xml:preview --feed=1 --file=tests/fixtures/sample_feed.xml --limit=5
php bin/console xml:sync --feed=1           # kaynağı hemen senkronize et
php bin/console xml:sync --all              # zamanı gelen aktif url kaynakları (cron kullanımı)
php bin/console xml:sync --force            # zamanlamayı yok say (upload kaynakları dahil)
```

Örnek Cron (ayrıntı: `cron/crontab.example`):

```cron
*/10 * * * * cd /var/www/xml-ecommerce && php bin/console xml:sync --all >> storage/logs/cron.log 2>&1
```

## 8. Yönetim Ekranları

| Ekran | Yol | İçerik |
|---|---|---|
| Tedarikçiler | `/admin/suppliers` | Tedarikçi CRUD, bağlı kaynak/ürün sayıları |
| XML Kaynakları | `/admin/feeds` | Kaynak listesi, son durum, senkron/toggle/sil, ekleme-düzenleme |
| Alan Eşleştirme | `/admin/feeds/{id}/mapping` | Örnek dosya yükleme, tespit edilen alanlar, profil kaydetme |
| Önizleme | `/admin/feeds/{id}/preview` | İlk N satır, hesaplanan satış fiyatı/kural/marj — **yazmaz** |
| Aktarım başlatma | POST `/admin/feeds/{id}/sync` | Manuel senkron + sonuç detayına yönlendirme |
| İlerleme | `/admin/feeds/{id}/sync-status` | JSON sayaçlar (running log polling) |
| Geçmiş | `/admin/imports` | Feed/durum filtreli aktarım geçmişi |
| Detay | `/admin/imports/{id}` | Sayaçlar, satır hataları, uyarılar, fiyat/stok değişimleri |
| Fiyat Kuralları | `/admin/price-rules` | Kural CRUD, öncelik, kapsam |
| Güncellemeler | `/admin/updates` | Son fiyat/stok değişimleri + kâr uyarı filtresi |

## 9. Testler

```bash
php tests/lint.php   # 139 dosya / 0 hata
php tests/run.php    # 103 test / 0 başarısız (FieldMapper, Pricing, XmlSync DB e2e dahil)
```

Aşama 2 senaryoları (temiz test veritabanında, `tests/fixtures/` örnek XML'leriyle):

- İlk aktarım 8 ürün oluşturur (fiyat/kdv/stok/görsel/varyant/kategori/USD çevirimi)
- Aynı XML tekrar işlenir → kopya oluşmaz
- Fiyat ve stok güncellenir + `product_price_history` yazılır
- XML'den eksilen ürün tam aktarım sonrası pasife alınır; görülenler korunur
- Yeni ürün v2'den eklenir
- Bozuk/yarım XML → `failed`, katalog korunur
- XXE denemesi reddedilir
- Geçersiz fiyat/stok satırları kabul edilmez (partial + satır hataları)
- İkinci tedarikçide SKU çakışması önlenir (önek ile çözüm)
- Eşzamanlı aktarım kilitlenir (`locked`)
- `protect_price` alan korumasına saygı duyulur
- Son başarılı senkron zamanı saklanır
- Önizleme veritabanına yazmaz

> **Not:** `tests/fixtures/` altındaki XML dosyaları gerçek bir tedarikçiden değil,
> bu projeye ait **sentetik örnek veridir** (gerçek tedarikçi XML'i olmadığı için açıkça belirtilir).
