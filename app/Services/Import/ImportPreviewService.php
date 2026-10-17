<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Config;
use App\Repositories\CategoryRepository;
use App\Services\Pricing\PricingService;
use RuntimeException;

/**
 * Aktarım önizlemesi: XML dosyasını eşleme profiliyle işleyip ilk N satırı
 * hesaplanan satış fiyatı ve kurallarla birlikte gösterir. VERİTABANINA YAZMAZ.
 *
 * Tam yol: app/Services/Import/ImportPreviewService.php
 */
final class ImportPreviewService
{
    public function __construct(
        private readonly FieldMapperFactory $mapperFactory = new FieldMapperFactory(),
        private readonly PricingService $pricing = new PricingService(),
        private readonly CategoryRepository $categories = new CategoryRepository()
    ) {
    }

    /**
     * @return array{rows:list<array<string,mixed>>, total_seen:int, detected_fields:list<string>,
     *              mapping_count:int, problems:list<string>}
     */
    public function preview(array $feed, string $filePath, ?int $limit = null): array
    {
        $limit = $limit ?? (int) Config::get('xml.sync.preview_rows', 20);

        $mappings = $this->mapperFactory->mappingsForFeed((int) $feed['id']);
        $mapper = $this->mapperFactory->make($mappings);

        $rows = [];
        $problems = [];
        $totalSeen = 0;
        $detected = [];
        $categoryParentMap = $this->categories->parentMap();

        $onRow = function (array $rawFields) use (
            &$rows, &$problems, &$totalSeen, &$detected, $mapper, $feed, $limit, $categoryParentMap
        ): void {
            $totalSeen++;
            foreach (array_keys($rawFields) as $key) {
                $detected[$key] = true;
            }

            if (count($rows) < $limit) {
                $mapped = $mapper->map($rawFields);
                $problems = [...$problems, ...array_map(
                    static fn (string $p): string => 'Satır ' . $totalSeen . ': ' . $p,
                    $mapped['problems']
                )];

                $data = $mapped['data'];
                $pricing = null;
                if ($data['cost'] !== null) {
                    $pricing = $this->pricing->compute(
                        $data['cost'],
                        null,
                        (int) $feed['supplier_id'],
                        null,
                        (float) ($data['vat'] ?? 20.0),
                        $categoryParentMap
                    );
                }

                $rows[] = [
                    'line'            => $totalSeen,
                    'supplier_sku'    => $data['supplier_sku'],
                    'name'            => $data['name'],
                    'category'        => $data['category'],
                    'brand'           => $data['brand'],
                    'barcode'         => $data['barcode'],
                    'cost_original'   => $data['cost_original'],
                    'source_currency' => $data['source_currency'],
                    'cost_try'        => $data['cost'],
                    'vat'             => $data['vat'],
                    'stock'           => $data['stock'],
                    'images'          => $data['images'],
                    'variants'        => $data['variants'],
                    'sale_price'      => $pricing['sale_price'] ?? null,
                    'margin'          => $pricing['margin'] ?? null,
                    'low_margin'      => $pricing['low_margin'] ?? false,
                    'applied_rules'   => $pricing['applied'] ?? [],
                    'problems'        => $mapped['problems'],
                ];
            }
        };

        $reader = new XmlStreamReader();
        $productNode = (string) ($feed['product_node'] ?? 'product');

        try {
            // Önizleme akış erken durdurmaya uygundur: limit+25 düğüm yeterli
            // (limit satırı dolduracak bozuk/boş düğümler olsa bile yeterli örnek çıkar)
            $reader->parse($filePath, $productNode, $onRow, min($limit + 25, 500));
        } catch (RuntimeException $e) {
            // Bozuk XML — önizleme bunu raporlar
            $problems[] = $e->getMessage();
        }

        return [
            'rows'           => $rows,
            'total_seen'     => $totalSeen,
            'detected_fields'=> array_keys($detected),
            'mapping_count'  => count($mappings),
            'problems'       => array_slice(array_unique($problems), 0, 25),
        ];
    }
}
