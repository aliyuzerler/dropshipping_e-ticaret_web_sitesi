<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Http\UrlFetcher;

/**
 * UrlFetcher için ince sarmalayıcı (mock/değiştirme noktası).
 *
 * Tam yol: app/Services/Import/UrlFetcherWrapper.php
 */
final class UrlFetcherWrapper
{
    private UrlFetcher $fetcher;

    public function __construct(?UrlFetcher $fetcher = null)
    {
        $this->fetcher = $fetcher ?? new UrlFetcher();
    }

    /** @return array{body:string, final_url:string, status:int, content_type:string} */
    public function fetch(string $url, int $maxBytes, ?string $basicAuth = null): array
    {
        return $this->fetcher->fetch($url, $maxBytes, $basicAuth);
    }
}
