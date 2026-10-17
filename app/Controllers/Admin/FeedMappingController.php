<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\FeedRepository;
use App\Repositories\FieldMappingRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Import\FieldMapper;
use App\Services\Import\XmlStreamReader;

/**
 * XML alan eşleştirme profilleri.
 *
 *  GET  /admin/feeds/{id}/mapping           → eşleme ekranı
 *  POST /admin/feeds/{id}/mapping           → profili kaydet (tümünü yeniler)
 *
 * Tam yol: app/Controllers/Admin/FeedMappingController.php
 */
final class FeedMappingController
{
    public function show(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $feed = (new FeedRepository())->find($feedId);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }

        $hasFile = (string) ($feed['last_file_path'] ?? '') !== ''
            && is_file(base_path('storage/' . ltrim((string) $feed['last_file_path'], '/')));

        return View::render('admin/feeds/mapping', [
            'title'     => 'Alan Eşleştirme: ' . $feed['name'],
            'feed'      => $feed,
            'mappings'  => (new FieldMappingRepository())->forFeed($feedId),
            'targets'   => FieldMapper::TARGETS,
            'detected'  => $hasFile ? $this->detectFields($feed) : [],
            'hasFile'   => $hasFile,
            'message'   => get_flash('feed_message'),
            'error'     => get_flash('feed_error'),
        ]);
    }

    public function save(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $feed = (new FeedRepository())->find($feedId);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }

        $sources = (array) ($request->post()['mapping_source'] ?? []);
        $targets = (array) ($request->post()['mapping_target'] ?? []);
        $transforms = (array) ($request->post()['mapping_transform'] ?? []);
        $defaults = (array) ($request->post()['mapping_default'] ?? []);

        $mappings = [];
        $invalidTargets = [];

        foreach ($sources as $i => $source) {
            $source = trim((string) $source);
            $target = trim((string) ($targets[$i] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }
            if (!array_key_exists($target, FieldMapper::TARGETS)) {
                $invalidTargets[] = $target;
                continue;
            }
            $mappings[] = [
                'source_path'   => mb_substr($source, 0, 255),
                'target_field'  => $target,
                'field_type'    => 'text',
                'transform'     => isset($transforms[$i]) && (string) $transforms[$i] !== '' ? (string) $transforms[$i] : null,
                'default_value' => isset($defaults[$i]) && (string) $defaults[$i] !== '' ? (string) $defaults[$i] : null,
            ];
        }

        if ($mappings === []) {
            flash('feed_error', 'En az bir geçerli alan eşlemesi gerekli.');
            return redirect('/admin/feeds/' . $feedId . '/mapping');
        }

        if ($invalidTargets !== []) {
            flash('feed_error', 'Geçersiz hedef alanlar: ' . implode(', ', array_unique($invalidTargets)));
            return redirect('/admin/feeds/' . $feedId . '/mapping');
        }

        // Kritik alan kontrolü
        $targetsSaved = array_column($mappings, 'target_field');
        foreach (FieldMapper::REQUIRED_TARGETS as $required) {
            if (!in_array($required, $targetsSaved, true)) {
                flash('feed_error', sprintf('Zorunlu eşleme eksik: "%s" alanı bir XML alanına bağlanmalı.', FieldMapper::TARGETS[$required]));
                return redirect('/admin/feeds/' . $feedId . '/mapping');
            }
        }

        try {
            $saved = (new FieldMappingRepository())->replaceForFeed($feedId, $mappings);
        } catch (\Throwable $e) {
            flash('feed_error', 'Profil kaydedilemedi: ' . $e->getMessage());
            return redirect('/admin/feeds/' . $feedId . '/mapping');
        }

        AuditLogger::log(
            action: 'feed.mapping_saved',
            adminUserId: Session::get('admin_id') !== null ? (int) Session::get('admin_id') : null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            entityType: 'xml_feed',
            entityId: $feedId,
            context: ['count' => $saved]
        );

        flash('feed_message', sprintf('%d alan eşlemesi kaydedildi. Önizleme ile doğrulayabilirsiniz.', $saved));
        return redirect('/admin/feeds/' . $feedId . '/preview');
    }

    /**
     * Son yüklenen dosyadan alan adlarını çıkarır (yalnız ilk ürün düğümü yeterli —
     * streaming + erken durdurma sayesinde büyük dosyalarda ucuzdur).
     * @return list<string>
     */
    private function detectFields(array $feed): array
    {
        $relative = (string) ($feed['last_file_path'] ?? '');
        if ($relative === '') {
            return [];
        }
        $path = base_path('storage/' . ltrim($relative, '/'));
        if (!is_file($path)) {
            return [];
        }

        $fields = [];
        try {
            (new XmlStreamReader())->parse(
                $path,
                (string) $feed['product_node'],
                static function (array $raw) use (&$fields): void {
                    foreach (array_keys($raw) as $key) {
                        $fields[$key] = true;
                    }
                },
                25 // ilk 25 düğüm tespit için yeterli (tekrarlayan/koşullu alanları da yakalar)
            );
        } catch (\Throwable) {
            return [];
        }

        return array_keys($fields);
    }
}
