/* XML E-Ticaret — genel JS (jQuery tabanlı)
 * Tam yol: public/assets/js/app.js
 */
(function ($) {
    'use strict';

    $(function () {
        // Formlarda çift gönderimi engelle
        $('form').on('submit', function () {
            var $btn = $(this).find('button[type="submit"]');
            if ($btn.length && !this.dataset.submitted) {
                this.dataset.submitted = '1';
                $btn.prop('disabled', true);
                window.setTimeout(function () {
                    $btn.prop('disabled', false);
                    delete this.dataset;
                }.bind(this), 4000);
            }
        });
    });
})(jQuery);

/* ---- Aşama 2: admin görünüm yardımcıları (Task 2-f) ---- */

// data-confirm: onay kutulu POST formları (sil/senkron/toggle).
// Capture aşamasında dinlenir; kullanıcı vazgeçerse submit iptal edilir
// (formlardaki çift-gönderim koruması da devreye girmez).
document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var msg = form && typeof form.getAttribute === 'function' ? form.getAttribute('data-confirm') : null;
    if (msg && !window.confirm(msg)) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
    }
}, true);

// Eşleme profili (admin/feeds/mapping): "+ Satır Ekle" gizli şablon satırını klonlar.
jQuery(function ($) {
    $(document).on('click', '#add-mapping-row', function () {
        var $tpl = $('#mappingTable tr[data-mapping-template]');
        if (!$tpl.length) {
            return;
        }
        var $row = $tpl.clone();
        $row.removeAttr('data-mapping-template').removeClass('d-none');
        $row.find('input, select').val('');
        $('#mappingRows').append($row);
        $row.find('input').first().trigger('focus');
    });
});
