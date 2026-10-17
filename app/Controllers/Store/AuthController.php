<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Services\Auth\CustomerAuthService;
use App\Services\Security\RateLimiter;

/**
 * Üyelik akışı: giriş, kayıt, şifremi unuttum / sıfırla, çıkış.
 *
 * Güvenlik kararları:
 *  - Giriş hatasında kilit/şifre ayrımı YAPILMAZ (tek mesaj — enumeration önlenir);
 *    kilitlenme politikası CustomerAuthService::attempt içinde uygulanır.
 *  - Şifremi unuttum yanıtı her zaman aynıdır; token yalnızca APP_ENV=local'da
 *    sandbox bilgilendirmesi olarak gösterilir (mail sunucusu yoktur).
 *  - Şifre alanları eski girdi (old) flash'ına ASLA yazılmaz.
 *  - AŞAMA 6: kayıt ve şifre sıfırlama talepleri IP bazlı hız sınırına tabidir
 *    (RateLimiter; IP'siz CLI/test çağrıları sınırlamadan geçer).
 *
 * Tam yol: app/Controllers/Store/AuthController.php
 */
final class AuthController
{
    private CustomerAuthService $auth;

    public function __construct(?CustomerAuthService $auth = null)
    {
        $this->auth = $auth ?? new CustomerAuthService();
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return redirect('/hesabim');
        }

        return View::render('store/auth/login', [
            'title'  => 'Giriş Yap',
            'errors' => [],
        ], 'layouts/store');
    }

    public function login(Request $request): Response
    {
        $email    = trim($request->string('email'));
        $password = (string) ($request->post()['password'] ?? '');

        if ($this->auth->attempt($email, $password, $request->ip(), $request->userAgent())) {
            // Giriş öncesi hedeflenen sayfa (CustomerAuthMiddleware 'intended' flash'lar).
            $intended = get_flash('intended');
            if (!is_string($intended) || $intended === ''
                || !str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
                $intended = '/hesabim'; // open redirect koruması: yalnız yerel yol
            }
            flash('success', 'Giriş yapıldı. Hoş geldiniz!');
            return redirect($intended);
        }

        // Hesap kilitli / şifre hatalı ayrımı yapılmaz — aynı mesaj.
        flash('error', 'E-posta adresi veya şifre hatalı.');
        Session::flash('old', $this->safeOld($request->post()));
        return redirect('/giris');
    }

    public function showRegister(Request $request): Response
    {
        if ($this->auth->check()) {
            return redirect('/hesabim');
        }

        return View::render('store/auth/register', [
            'title'  => 'Üye Ol',
            'errors' => [],
        ], 'layouts/store');
    }

    public function register(Request $request): Response
    {
        // AŞAMA 6: IP başına kayıt istismarı sınırı (10/saat) — IP'siz (CLI) çağrılar geçer.
        $ip = $request->ip();
        if ($ip !== '') {
            if (RateLimiter::tooManyAttempts('register:' . $ip, 10, 3600)) {
                Logger::security('customer.register_throttled', ['ip' => $ip]);
                flash('error', 'Çok fazla kayıt denemesi algılandı. Lütfen bir süre sonra tekrar deneyin.');
                return redirect('/kayit');
            }
            RateLimiter::hit('register:' . $ip, 3600);
        }

        try {
            $this->auth->register($request->post());
            flash('success', 'Üyeliğiniz oluşturuldu. Hoş geldiniz!');
            return redirect('/hesabim');
        } catch (ValidationException $e) {
            // Redirect değil: tek istekte, alan bazlı hata + eski girdilerle render.
            Session::flash('old', $this->safeOld($request->post()));
            return View::render('store/auth/register', [
                'title'  => 'Üye Ol',
                'errors' => $e->errors(),
            ], 'layouts/store');
        }
    }

    public function showForgot(Request $request): Response
    {
        return View::render('store/auth/forgot', [
            'title' => 'Şifremi Unuttum',
        ], 'layouts/store');
    }

    public function forgot(Request $request): Response
    {
        $email = trim($request->string('email'));

        // AŞAMA 6: token üretim seli sınırı — IP başına 5 talep / 15 dk. Sınır aşımında
        // yanıt YİNE aynı generic mesaj (enumeration olasılığı doğmaz), token üretilmez.
        $ip = $request->ip();
        if ($ip !== '') {
            if (RateLimiter::tooManyAttempts('pwreset:' . $ip, 5, 900)) {
                Logger::security('customer.reset_throttled', ['ip' => $ip]);
                flash('success', 'E-postanız kayıtlıysa sıfırlama bağlantısı gönderildi. Lütfen gelen kutunuzu ve spam klasörünüzü kontrol edin.');
                return redirect('/sifremi-unuttum');
            }
            RateLimiter::hit('pwreset:' . $ip, 900);
        }

        $token = $this->auth->sendResetLink($email);

        // E-posta kayıtlı olsun olmasın yanıt her zaman aynı (enumeration önlenir).
        flash('success', 'E-postanız kayıtlıysa sıfırlama bağlantısı gönderildi. Lütfen gelen kutunuzu ve spam klasörünüzü kontrol edin.');

        // Sandbox'ta mail sunucusu bulunmadığından bağlantı yalnızca APP_ENV=local
        // iken geliştiriciye bilgi amaçlı gösterilir (production'da hiçbir zaman gösterilmez).
        if ($token !== null && env('APP_ENV') === 'local') {
            flash('info', '[Sandbox] Bağlantı: /sifre-sifirla?token=' . $token);
        }

        return redirect('/sifremi-unuttum');
    }

    public function showReset(Request $request): Response
    {
        return View::render('store/auth/reset', [
            'title'  => 'Yeni Şifre Belirle',
            'errors' => [],
            'token'  => trim($request->string('token')),
        ], 'layouts/store');
    }

    public function reset(Request $request): Response
    {
        $token        = trim($request->string('token'));
        $password     = (string) ($request->post()['password'] ?? '');
        $confirmation = (string) ($request->post()['password_confirmation'] ?? '');

        try {
            $this->auth->resetPassword($token, $password, $confirmation);
            flash('success', 'Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.');
            return redirect('/giris');
        } catch (ValidationException $e) {
            Session::flash('old', $this->safeOld($request->post()));
            return View::render('store/auth/reset', [
                'title'  => 'Yeni Şifre Belirle',
                'errors' => $e->errors(),
                'token'  => $token,
            ], 'layouts/store');
        }
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        flash('info', 'Çıkış yapıldı.');
        return redirect('/');
    }

    /**
     * Eski girdi flash'ı — hassas alanlar (şifreler) çıkarılır.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function safeOld(array $post): array
    {
        unset($post['password'], $post['password_confirmation'], $post['current_password']);
        return $post;
    }
}
