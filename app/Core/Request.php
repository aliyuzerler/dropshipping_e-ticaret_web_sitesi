<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP isteği sarmalayıcı.
 *
 * Tam yol: app/Core/Request.php
 */
final class Request
{
    private string $method;
    private string $path;
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $post;
    /** @var array<string,array<string,mixed>> */
    private array $files;
    /** @var array<string,string> */
    private array $headers;

    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $post = [],
        array $files = [],
        array $headers = [],
        private string $ip = '',
        private string $userAgent = ''
    ) {
        $this->method  = strtoupper($method);
        $this->path    = '/' . trim($path, '/');
        $this->query   = $query;
        $this->post    = $post;
        $this->files   = $files;
        $this->headers = $headers;
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $path,
            $_GET,
            $_POST,
            $_FILES,
            self::collectHeaders(),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
    }

    /** @return array<string,string> */
    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<string,mixed> */
    public function post(): array
    {
        return $this->post;
    }

    /**
     * AŞAMA 6 — Sorgu dizisi (yalnız-okuma; HttpsEnforcementMiddleware'in
     * 301 yönlendirmesinde query string'i korumak için eklenmiştir).
     *
     * @return array<string,mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function wantsJson(): bool
    {
        if (str_starts_with($this->path, '/api') || $this->path === '/health') {
            return true;
        }
        $accept = $this->header('accept') ?? '';
        return str_contains($accept, 'application/json');
    }

    /** ErrorHandler için globals tabanlı sürüm (Request nesnesi yokken). */
    public static function wantsJsonFromGlobals(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        if (str_starts_with($path, '/api') || $path === '/health') {
            return true;
        }
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json');
    }
}
