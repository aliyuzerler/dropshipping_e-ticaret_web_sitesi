<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;

/**
 * Global hata/istisna yönetimi.
 *  - PHP hataları -> ErrorException
 *  - Yakalanmamış istisnalar -> log + (HTML | JSON) temiz yanıt
 *  - Production'da hiçbir dosya yolu / iz sızdırılmaz.
 *
 * Tam yol: app/Core/ErrorHandler.php
 */
final class ErrorHandler
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        error_reporting(E_ALL);
        ini_set('display_errors', '0');   // ekrana asla; production/local ayrımı yok
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return false; // @ ile bastırılanlar
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::handleThrowable($e);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('app', 'fatal', [
                    'message' => $error['message'],
                    'file'    => $error['file'],
                    'line'    => $error['line'],
                ]);
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['error' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
            }
        });
    }

    public static function handleThrowable(\Throwable $e): void
    {
        $status = 500;
        $publicMessage = 'Beklenmeyen bir sunucu hatası oluştu.';

        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            $publicMessage = $e->getMessage();
        }

        Logger::error('app', $e->getMessage(), [
            'exception' => $e::class,
            'code'      => $e->getCode(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'status'    => $status,
        ]);

        if (headers_sent()) {
            return;
        }

        $wantsJson = Request::wantsJsonFromGlobals();
        http_response_code($status);

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            $payload = ['error' => $publicMessage];
            if (!App::isProduction()) {
                $payload['debug'] = [
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                ];
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }

        // HTML hata sayfası
        $viewFile = App::basePath('app/Views/errors/' . ($status === 404 ? '404' : '500') . '.php');
        $safeMessage = htmlspecialchars($publicMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $showDebug = !App::isProduction();
        if (is_file($viewFile)) {
            include $viewFile;
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Hata</title>';
            echo '<h1>' . (int) $status . '</h1><p>' . $safeMessage . '</p>';
        }
    }
}
