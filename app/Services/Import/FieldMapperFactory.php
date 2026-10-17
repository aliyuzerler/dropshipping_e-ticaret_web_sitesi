<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Repositories\FieldMappingRepository;

/**
 * FieldMapper fabrikası: feed eşleme profillerini yükler ve mapper üretir.
 * Testlerde sahte profil enjekte etmeyi kolaylaştırır.
 *
 * Tam yol: app/Services/Import/FieldMapperFactory.php
 */
final class FieldMapperFactory
{
    public function __construct(
        private readonly FieldMappingRepository $mappings = new FieldMappingRepository()
    ) {
    }

    /**
     * Feed'in veritabanındaki aktif eşlemeleri.
     * @return list<array{source_path:string, target_field:string, transform:?string, default_value:?string}>
     */
    public function mappingsForFeed(int $feedId): array
    {
        $out = [];
        foreach ($this->mappings->forFeed($feedId) as $row) {
            $out[] = [
                'source_path'   => (string) $row['source_path'],
                'target_field'  => (string) $row['target_field'],
                'transform'     => $row['transform'] !== null ? (string) $row['transform'] : null,
                'default_value' => $row['default_value'] !== null ? (string) $row['default_value'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param list<array{source_path:string, target_field:string, transform:?string, default_value:?string}> $mappings
     */
    public function make(array $mappings): FieldMapper
    {
        return new FieldMapper($mappings);
    }
}
