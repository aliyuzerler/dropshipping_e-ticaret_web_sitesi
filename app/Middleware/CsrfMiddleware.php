<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * CSRF koruması: POST/PUT/PATCH/DELETE isteklerinde token doğrulaması.
 *  - Form: "_token" alanı
 *  - AJAX: X-CSRF-Token başlığı
 * Başarısızlık → 419 + security.log
 *
 * Tam yol: app/Middleware/CsrfMiddleware.php
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $provided = $request->string('_token');
        if ($provided === '') {
            $provided = (string) ($request->header('x-csrf-token') ?? '');
        }

        if (!Csrf::validate($provided !== '' ? $provided : null)) {
            Logger::security('csrf.invalid', [
                'path' => $request->path(),
                'ip'   => $request->ip(),
            ]);
            throw new HttpException(419);
        }

        return $next($request);
    }
}
