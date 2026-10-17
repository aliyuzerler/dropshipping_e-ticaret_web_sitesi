<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Services\Store\StoreProductService;

/**
 * SEO teknik yanıtları: XML sitemap + robots.txt.
 *
 * Tam yol: app/Controllers/Store/SitemapController.php
 */
final class SitemapController
{
    private StoreProductService $products;

    public function __construct(?StoreProductService $products = null)
    {
        $this->products = $products ?? new StoreProductService();
    }

    public function xml(Request $request): Response
    {
        $base = request_base_url();
        $urls = [];

        // Ana sayfa (1.0)
        $urls[] = ['loc' => $base . '/', 'priority' => '1.0', 'changefreq' => 'daily', 'lastmod' => ''];

        // Statik / kurumsal sayfalar (0.5)
        foreach ([
            '/gizlilik-ve-kvkk',
            '/mesafeli-satis-sozlesmesi',
            '/on-bilgilendirme-formu',
            '/iade-ve-teslimat',
            '/iletisim',
        ] as $path) {
            $urls[] = ['loc' => $base . $path, 'priority' => '0.5', 'changefreq' => 'monthly', 'lastmod' => ''];
        }

        // Kategoriler (0.8) — aktif kategori ağacı
        foreach ($this->flattenCategories($this->products->categoriesTree()) as $category) {
            $slug = (string) ($category['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $urls[] = [
                'loc'        => $base . '/kategori/' . rawurlencode($slug),
                'priority'   => '0.8',
                'changefreq' => 'weekly',
                'lastmod'    => '',
            ];
        }

        // Ürünler (0.9) — service hide modunu zaten uygular; burada ekstra
        // purchasable/is_stale filtresi YOK (disable modunda bayat ürün de
        // vitrinde görüntülenebildiği için sitemap'te kalabilir).
        $result = $this->products->search(['sort' => 'newest'], 100, 1);
        foreach ($result['rows'] as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $urls[] = [
                'loc'        => $base . '/urun/' . rawurlencode($slug),
                'priority'   => '0.9',
                'changefreq' => 'daily',
                'lastmod'    => $this->lastmod((string) ($row['created_at'] ?? '')),
            ];
        }

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $lines[] = "\t<url>";
            $lines[] = "\t\t<loc>" . e($url['loc']) . '</loc>';
            if ($url['lastmod'] !== '') {
                $lines[] = "\t\t<lastmod>" . $url['lastmod'] . '</lastmod>';
            }
            $lines[] = "\t\t<changefreq>" . $url['changefreq'] . '</changefreq>';
            $lines[] = "\t\t<priority>" . $url['priority'] . '</priority>';
            $lines[] = "\t</url>";
        }
        $lines[] = '</urlset>';

        $xml = implode("\n", $lines) . "\n";

        return (new \App\Core\Response($xml, 200))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    public function robots(Request $request): Response
    {
        $base = request_base_url();
        $content = "User-agent: *\n"
            . "Allow: /\n"
            . "Disallow: /admin\n"
            . "Disallow: /sepet\n"
            . "Disallow: /odeme\n"
            . "Disallow: /hesabim\n"
            . 'Sitemap: ' . $base . "/sitemap.xml\n";

        return (new \App\Core\Response($content, 200))
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /* -----------------------------------------------------------------
     * İç yardımcılar
     * ----------------------------------------------------------------- */

    /**
     * Kategori ağacını derinlemesine dolaşarak düz liste üretir.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array{slug:string}>
     */
    private function flattenCategories(array $nodes): array
    {
        $flat = [];
        $stack = $nodes;
        while ($stack !== []) {
            /** @var array<string,mixed> $node */
            $node = array_shift($stack);
            $slug = (string) ($node['slug'] ?? '');
            if ($slug !== '') {
                $flat[] = ['slug' => $slug];
            }
            foreach ((array) ($node['children'] ?? []) as $child) {
                $stack[] = $child;
            }
        }
        return $flat;
    }

    /** created_at → W3C tarih (YYYY-MM-DD); parse edilemezse boş. */
    private function lastmod(string $createdAt): string
    {
        if ($createdAt === '') {
            return '';
        }
        $timestamp = strtotime($createdAt);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }
}
