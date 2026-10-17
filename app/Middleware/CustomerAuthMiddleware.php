<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth\CustomerAuthService;

/**
 * Mağaza üyelik koruması — hesap/checkout rotaları için.
 * Oturum yoksa /giris'a yönlendirir (giriş sonrası geri dönüş için 'intended').
 *
 * Tam yol: app/Middleware/CustomerAuthMiddleware.php
 */
final class CustomerAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = new CustomerAuthService();

        if (!$auth->check()) {
            Logger::security('customer.auth_required', ['path' => $request->path(), 'ip' => $request->ip()]);
            Session::flash('intended', $request->path());
            Session::flash('error', 'Bu sayfa için lütfen giriş yapın.');
            return redirect('/giris');
        }

        return $next($request);
    }
}
