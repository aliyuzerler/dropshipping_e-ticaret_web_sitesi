<?php

declare(strict_types=1);

/**
 * Hukuki metin TASLAKLARI (Aşama 3).
 * DİKKAT: Bu metinler şablondur; yayına almadan önce mutlaka işletmenin
 * avukatı/mali müşaviri tarafından kontrol edilmelidir. Admin → Ayarlar
 * ekranından işletme bilgilerine göre düzenlenebilir (settings tablosu).
 *
 * Yer tutucular render sırasında değiştirilir:
 *   {{STORE_NAME}}, {{COMPANY_TITLE}}, {{EMAIL}}, {{PHONE}}, {{ADDRESS}}, {{DATE}}
 *
 * Tam yol: config/legal_texts.php
 */
return [
    'legal.privacy' => <<<'HTML'
        <div class="alert alert-warning"><strong>TASLAK METİN:</strong> Bu sayfa örnek olarak hazırlanmıştır. Yayına almadan önce işletme bilgilerinizle güncelleyin ve hukuki danışmanınıza kontrol ettirin. (Admin → Ayarlar ekranından düzenlenebilir.)</div>

        <h2>KVKK Aydınlatma Metni ve Gizlilik Politikası</h2>
        <p><strong>{{COMPANY_TITLE}}</strong> ("{{STORE_NAME}}") olarak, 6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") kapsamında veri sorumlusu olarak hareket etmekteyiz.</p>

        <h3>1. İşlenen Kişisel Veriler</h3>
        <p>Üyelik, alışveriş ve iletişim süreçlerinde; ad-soyad, e-posta, telefon, teslimat/fatura adresi, sipariş geçmişi ve IP adresi gibi veriler işlenir.</p>

        <h3>2. İşleme Amaçları</h3>
        <p>Siparişlerin alınması, kargolanması, faturalandırılması, müşteri desteği, hesap yönetimi, yasal yükümlülüklerin yerine getirilmesi ve dolandırıcılık önlemleri.</p>

        <h3>3. Aktarım</h3>
        <p>Verileriniz; kargo firmaları, ödeme kuruluşları ve yasal yükümlülük gereği yetkili kamu kurumları dışında üçüncü taraflarla paylaşılmaz. Yurt dışına aktarım yapılmaz.</p>

        <h3>4. Saklama Süreleri</h3>
        <p>Veriler, ilgili mevzuatta öngörülen süre boyunca; yasal bir gerekçe yoksa sipariş tarihinden itibaren 10 yıl boyunca saklanır ve sonunda silinir.</p>

        <h3>5. Haklarınız</h3>
        <p>KVKK m.11 kapsamındaki haklarınızı kullanmak için <strong>{{EMAIL}}</strong> adresine başvurabilirsiniz. Başvurular en geç 30 gün içinde yanıtlanır.</p>

        <h3>6. Çerezler (Cookies)</h3>
        <p>Sitemizde oturum yönetimi ve sepet işlevleri için zorunlu çerezler kullanılır. Pazarlama çerezleri yalnızca onayınızla kullanılır.</p>
        HTML,

    'legal.distance_sales' => <<<'HTML'
        <div class="alert alert-warning"><strong>TASLAK METİN:</strong> Bu sözleşme örneği işletme bilgilerinize göre düzenlenmeli ve hukuki danışmanınıza kontrol ettirilmelidir. (Admin → Ayarlar ekranından düzenlenebilir.)</div>

        <h2>Mesafeli Satış Sözleşmesi</h2>
        <p><strong>SATICI:</strong> {{COMPANY_TITLE}} — {{ADDRESS}} · Tel: {{PHONE}} · E-posta: {{EMAIL}}<br>
        <strong>ALICI:</strong> Siteye üye olan / sipariş veren müşteri.</p>

        <h3>Madde 1 — Konu</h3>
        <p>İşbu sözleşmenin konusu, ALICI'nın {{STORE_NAME}} elektronik ortamında sipariş verdiğini ürünlere ilişkin 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği uyarınca satışı ve tarafların hak ve yükümlülükleridir.</p>

        <h3>Madde 2 — Sipariş ve Ödeme</h3>
        <p>Sipariş, ALICI'nın elektronik ortamda onay vermesiyle kurulur. Ödeme koşulları sipariş adımında belirtilir. Ödeme altyapısı entegrasyonu ayrı bir aşamada devreye alınacaktır.</p>

        <h3>Madde 3 — Teslimat</h3>
        <p>Ürünler, ödemenin tamamlanmasını takiben en geç 30 gün içinde ALICI'nın bildirdiği adrese teslim edilir. Teslimat süresi yoğunluk ve kargo firması nedeniyle kısa sürelerle uzayabilir.</p>

        <h3>Madde 4 — Cayma Hakkı</h3>
        <p>ALICI, teslimattan itibaren 14 gün içinde gerekçe göstermeksizin cayma hakkını kullanabilir. Cayma bildirimi <strong>{{EMAIL}}</strong> adresine yapılır. Hijyen ürünleri, açılmış ambalajlı ürünler ve kişiye özel üretilen ürünlerde cayma hakkı kullanılamaz.</p>

        <h3>Madde 5 — Uyuşmazlık Çözümü</h3>
        <p>Bu sözleşmeden doğan uyuşmazlıklarda Tüketici Hakem Heyetleri ve Tüketici Mahkemeleri yetkilidir.</p>
        HTML,

    'legal.preinformation' => <<<'HTML'
        <div class="alert alert-warning"><strong>TASLAK METİN:</strong> Bu ön bilgilendirme formu örnektir; işletme bilgilerinizle güncellenmelidir. (Admin → Ayarlar ekranından düzenlenebilir.)</div>

        <h2>Ön Bilgilendirme Formu</h2>
        <ol>
            <li><strong>Satıcı:</strong> {{COMPANY_TITLE}} — {{ADDRESS}} · {{PHONE}} · {{EMAIL}}</li>
            <li><strong>Ürün/Ürünler:</strong> Sipariş adımında görüntülenen ürün kartındaki ad, kod (SKU), adet ve birim fiyat.</li>
            <li><strong>Toplam Tutar:</strong> Ürün bedelleri, indirimler, kargo ücreti ve varsa vergiler dahil sipariş özetinde gösterilen toplam.</li>
            <li><strong>Kargo Ücreti:</strong> Sipariş tutarı belirlenen eşik değerin üzerindeyse ücretsiz; aksi hâlde sipariş adımında gösterilen sabit ücret uygulanır.</li>
            <li><strong>Teslimat:</strong> Ödeme tamamlanmasını takiben en geç 30 gün içinde kargo firması aracılığıyla.</li>
            <li><strong>Cayma Hakkı:</strong> Teslimattan itibaren 14 gün; istisnalar Mesafeli Satış Sözleşmesinde belirtilmiştir.</li>
            <li><strong>Şikâyet ve İtiraz:</strong> Tüketici Hakem Heyetleri ve Tüketici Mahkemeleri yetkilidir.</li>
        </ol>
        HTML,

    'legal.returns_delivery' => <<<'HTML'
        <div class="alert alert-warning"><strong>TASLAK METİN:</strong> İade ve teslimat politikası örnektir; işletme bilgilerinize göre güncelleyin. (Admin → Ayarlar ekranından düzenlenebilir.)</div>

        <h2>İade Politikası</h2>
        <p>Ürünü teslim aldıktan sonra <strong>14 gün</strong> içinde gerekçe göstermeksizin iade edebilirsiniz. İade kargo ücreti, cayma hakkı kapsamındaki iadelerde {{STORE_NAME}} tarafından karşılanır.</p>
        <ul>
            <li>Ürün kutusu, etiketi ve aksesuarları ile birlikte, kullanılmamış olmalıdır.</li>
            <li>Hijyen gereği açılmış kozmetik/iç giyim ürünleri iade alınamaz.</li>
            <li>İade onayı sonrası tutar, ödemenin yapıldığı yönteme göre 7-14 gün içinde iade edilir.</li>
        </ul>

        <h2>Teslimat Politikası</h2>
        <ul>
            <li>Siparişler anlaşmalı kargo firmaları ile gönderilir.</li>
            <li>15:00'e kadar verilen siparişler aynı gün kargoya teslim edilmeye çalışılır (iş günü).</li>
            <li>Tahmini teslim süresi 1-5 iş günüdür; kargo takip numarası e-posta ile iletilir.</li>
            <li>Adres teslim edilemezse kargo firmasının şubesinde 3 gün bekletilir ve iade edilir.</li>
        </ul>
        HTML,
];
