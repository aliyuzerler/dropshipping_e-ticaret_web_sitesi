/* Mağaza JS — Aşama 3 (jQuery tabanlı, JS'siz de çalışır geliştirmeler)
 * Tam yol: public/assets/js/store.js
 */
(function ($) {
    'use strict';

    $(function () {
        /* 1) Ürün detay galerisi: küçük görsel → ana görsel değişimi */
        $(document).on('click', '.pdp-thumb', function () {
            var src = $(this).data('src');
            var $main = $('#pdpMainImage');
            if (src && $main.length) {
                $main.attr('src', src);
                $('.pdp-thumb').removeClass('active');
                $(this).addClass('active');
            }
        });

        /* 2) Sepet adet artır/azalt düğmeleri (data-step) */
        $(document).on('click', '[data-step]', function () {
            var $input = $($(this).data('target'));
            if (!$input.length) { return; }
            var min = parseInt($input.attr('min'), 10) || 1;
            var max = parseInt($input.attr('max'), 10) || 999;
            var val = parseInt($input.val(), 10) || min;
            var next = val + parseInt($(this).data('step'), 10);
            $input.val(Math.min(max, Math.max(min, next))).trigger('change');
        });

        /* 3) Sepet adet değişince satır formunu otomatik gönder */
        $(document).on('change', '.cart-qty input[type="number"]', function () {
            if (this.form) {
                $(this.form).find('button[type="submit"]').trigger('click');
            }
        });

        /* 4) Checkout: kayıtlı adres ↔ yeni adres formu geçişi */
        $(document).on('change', 'input[name="address_mode"]', function () {
            var mode = $('input[name="address_mode"]:checked').val();
            $('#existingAddressBox').toggleClass('d-none', mode !== 'existing');
            $('#newAddressBox').toggleClass('d-none', mode !== 'new');
        });

        /* 5) Stoksuz/bayat ürünlerde sepete ekleme düğmesini devre dışı bırak */
        $(document).on('submit', 'form[data-add-to-cart]', function () {
            var $btn = $(this).find('button[type="submit"]');
            if ($btn.length && !this.dataset.submitting) {
                this.dataset.submitting = '1';
                $btn.prop('disabled', true).addClass('disabled');
            }
        });

        /* 6) Mobile arama odağı: açılınca input'a odaklan */
        $('#storeNav').on('shown.bs.collapse', function () {
            if (window.innerWidth < 992) {
                $('#storeSearch').trigger('focus');
            }
        });
    });
})(jQuery);
