<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Services\Security\RateLimiter;
use App\Services\Store\SettingsService;

/**
 * İletişim sayfası + mesaj formu.
 * GERÇEK E-POSTA GÖNDERİMİ YOK: mesaj yalnızca app log kanalına yazılır
 * (Aşama 4/5'te mail altyapısı devreye alınacak).
 *
 * Tam yol: app/Controllers/Store/ContactController.php
 */
final class ContactController
{
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    public function show(Request $request): Response
    {
        return $this->renderForm();
    }

    public function send(Request $request): Response
    {
        // AŞAMA 6: IP başına iletişim istismarı sınırı (5/10 dk) — IP'siz (CLI) çağrılar geçer.
        $ip = $request->ip();
        if ($ip !== '') {
            if (RateLimiter::tooManyAttempts('contact:' . $ip, 5, 600)) {
                Logger::security('contact.throttled', ['ip' => $ip]);
                flash('error', 'Çok fazla mesaj gönderildi. Lütfen bir süre sonra tekrar deneyin.');
                return redirect('/iletisim');
            }
            RateLimiter::hit('contact:' . $ip, 600);
        }

        $input = [
            'ad'    => trim((string) $request->input('ad', '')),
            'email' => trim((string) $request->input('email', '')),
            'konu'  => trim((string) $request->input('konu', '')),
            'mesaj' => trim((string) $request->input('mesaj', '')),
        ];

        try {
            $this->validate($input);
        } catch (ValidationException $e) {
            // Formu hata listesi + eski girdilerle yeniden render et.
            Session::flash('old', $request->post());
            return $this->renderForm($e->errors());
        }

        Logger::info('contact', 'message.received', [
            'name'           => $input['ad'],
            'email'          => $input['email'],
            'subject'        => $input['konu'],
            'message_length' => mb_strlen($input['mesaj'], 'UTF-8'),
            'ip'             => $request->ip(),
            'user_agent'     => $request->userAgent(),
        ]);

        flash('success', 'Mesajınız alındı. En kısa sürede size dönüş yapacağız.');
        return redirect('/iletisim');
    }

    /* -----------------------------------------------------------------
     * İç yardımcılar
     * ----------------------------------------------------------------- */

    /**
     * Alan bazlı doğrulama: ad (2-80), email (geçerli), konu (3-120), mesaj (10-2000).
     *
     * @param array{ad:string, email:string, konu:string, mesaj:string} $input
     */
    private function validate(array $input): void
    {
        $errors = [];

        $nameLength = mb_strlen($input['ad'], 'UTF-8');
        if ($nameLength === 0) {
            $errors['ad'][] = 'Ad soyad zorunludur.';
        } elseif ($nameLength < 2 || $nameLength > 80) {
            $errors['ad'][] = 'Ad soyad 2-80 karakter arasında olmalıdır.';
        }

        if ($input['email'] === '') {
            $errors['email'][] = 'E-posta adresi zorunludur.';
        } elseif (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Geçerli bir e-posta adresi girin.';
        }

        $subjectLength = mb_strlen($input['konu'], 'UTF-8');
        if ($subjectLength === 0) {
            $errors['konu'][] = 'Konu zorunludur.';
        } elseif ($subjectLength < 3 || $subjectLength > 120) {
            $errors['konu'][] = 'Konu 3-120 karakter arasında olmalıdır.';
        }

        $messageLength = mb_strlen($input['mesaj'], 'UTF-8');
        if ($messageLength === 0) {
            $errors['mesaj'][] = 'Mesaj zorunludur.';
        } elseif ($messageLength < 10 || $messageLength > 2000) {
            $errors['mesaj'][] = 'Mesaj 10-2000 karakter arasında olmalıdır.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Lütfen formdaki hataları düzeltin.');
        }
    }

    /**
     * İletişim formunu (ve bilgi kartını) render eder.
     *
     * @param array<string,list<string>> $validationErrors alan bazlı hata listesi
     */
    private function renderForm(array $validationErrors = []): Response
    {
        return View::render('store/pages/contact', [
            'title'           => 'İletişim',
            'metaDescription' => 'Sorularınız ve talepleriniz için bize ulaşın; mesaj formu, e-posta, telefon ve adres bilgileri.',
            'settingsInfo'    => [
                'name'    => $this->settings->storeName(),
                'email'   => $this->settings->contactEmail(),
                'phone'   => $this->settings->contactPhone(),
                'address' => $this->settings->contactAddress(),
            ],
            'validationErrors' => $validationErrors,
        ], 'layouts/store');
    }
}
