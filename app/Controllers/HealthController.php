<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Health\HealthService;

/**
 * Health-check endpoint: GET /health
 * Yalnız boolean kontrol sonuçları — sunucu/DB detayı sızmaz.
 *
 * Tam yol: app/Controllers/HealthController.php
 */
final class HealthController
{
    public function show(Request $request): Response
    {
        $result = (new HealthService())->check();

        $status = $result['status'] === 'ok' ? 200 : 503;

        return Response::json([
            'status' => $result['status'],
            'checks' => $result['checks'],
            'time'   => $result['time'],
        ], $status);
    }
}
