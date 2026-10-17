<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Middleware sözleşmesi.
 *
 * Tam yol: app/Middleware/MiddlewareInterface.php
 */
interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
