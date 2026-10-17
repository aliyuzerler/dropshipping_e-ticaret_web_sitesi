<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth\AdminAuthService;

/**
 * Admin paneli yetkilendirme middleware'i.
 * Oturum yoksa /admin/login'a yönlendirir; aktif admin bilgisini request'e bağlar.
 *
 * Tam yol: app/Middleware/AdminAuthMiddleware.php
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $auth = new AdminAuthService();

        if (!$auth->check()) {
            Logger::security('admin.auth_required', ['path' => $request->path(), 'ip' => $request->ip()]);
            Session::flash('intended', $request->path());
            return redirect('/admin/login');
        }

        // Oturum süresi uzatma + admin kimliği sonraki katmanlara flash ile taşınır
        Session::set('_admin_seen_at', time());

        return $next($request);
    }
}
