<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP yanıtı.
 *
 * Tam yol: app/Core/Response.php
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    public function __construct(
        private string $content = '',
        private int $status = 200
    ) {
    }

    public static function html(string $content, int $status = 200): self
    {
        return (new self($content, $status))->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (new self($body === false ? '{}' : $body, $status))->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return (new self('', $status))->withHeader('Location', $to);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->content;
    }
}
