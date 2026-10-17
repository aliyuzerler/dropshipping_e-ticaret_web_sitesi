<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Güvenlik başlıkları (CSP, clickjacking, sniffing...).
 *
 * Tam yol: app/Middleware/SecurityHeadersMiddleware.php
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response
            ->withHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; frame-ancestors 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
