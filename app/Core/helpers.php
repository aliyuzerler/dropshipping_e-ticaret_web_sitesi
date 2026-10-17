<?php

declare(strict_types=1);

/**
 * Global yardımcı fonksiyonlar.
 *
 * Tam yol: app/Core/helpers.php
 */

use App\Core\App;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Session;

/** XSS'e karşı çıktı kodlama (ENT_QUOTES + UTF-8). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function env(string $key, ?string $default = null): ?string
{
    return \App\Core\Env::get($key, $default);
}

function base_path(string $path = ''): string
{
    return App::basePath($path);
}

function storage_path(string $path = ''): string
{
    return App::storagePath($path);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return Csrf::field();
}

/** Yönlendirme yanıtı döndürür. */
function redirect(string $to, int $status = 302): Response
{
    return Response::redirect($to, $status);
}

/** 404 fırlatır. */
function abort(int $status, string $message = ''): never
{
    throw new \App\Exceptions\HttpException($status, $message);
}

/** Flash + eski form değerleri */
function flash(string $key, mixed $value): void
{
    Session::flash($key, $value);
}

function get_flash(string $key, mixed $default = null): mixed
{
    return Session::getFlash($key, $default);
}

/** Sipariş numarası üretici: ORD-YYYYMMDD-XXXXX */
function generate_order_number(): string
{
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

/* ----- Aşama 3: mağaza yardımcıları ----- */

/** Tutarı TR biçiminde biçimlendirir: 1.234,56 ₺ */
function money(mixed $amount, string $suffix = ' ₺'): string
{
    return number_format((float) ($amount ?? 0), 2, ',', '.') . $suffix;
}

/* ----- Aşama 4: sipariş durumu yardımcıları ----- */

/** Sipariş durumunun Türkçe etiketi. */
function order_status_label(mixed $status): string
{
    return \App\Services\Order\OrderStatus::label((string) ($status ?? ''));
}

/** Sipariş durumunun Bootstrap badge sınıfı. */
function order_status_badge(mixed $status): string
{
    return \App\Services\Order\OrderStatus::badge((string) ($status ?? ''));
}

/** Ödeme durumunun etiketi. */
function payment_status_label(mixed $status): string
{
    return match ((string) ($status ?? '')) {
        'initiated'           => 'Başlatıldı',
        'initiation_failed'   => 'Başlatılamadı',
        'success'             => 'Başarılı',
        'failed'              => 'Başarısız',
        'cancelled'           => 'İptal Edildi',
        'refunded'            => 'İade Edildi',
        'partially_refunded'  => 'Kısmen İade Edildi',
        default               => (string) ($status ?? '-'),
    };
}

/** İade kaydı durumunun etiketi. */
function refund_status_label(mixed $status): string
{
    return match ((string) ($status ?? '')) {
        'pending'   => 'Onay Bekliyor',
        'completed' => 'Tamamlandı',
        'failed'    => 'Başarısız',
        'rejected'  => 'Reddedildi',
        default     => (string) ($status ?? '-'),
    };
}

/** Doğrulama hatasından sonra eski form değerleri (bir kez flash'lenir). */
function old(string $key, mixed $default = ''): mixed
{
    static $old = null;
    if ($old === null) {
        $stored = Session::getFlash('old', []);
        $old = is_array($stored) ? $stored : [];
    }
    return $old[$key] ?? $default;
}

/** Mevcut isteğin scheme+host kısmı (canonical/absolute URL üretimi için). */
function request_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    // Ters proksi (Next.js köprüsü / Nginx) arkasında gerçek hostu koru
    $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host) ?: 'localhost';
    return $scheme . '://' . $host;
}
