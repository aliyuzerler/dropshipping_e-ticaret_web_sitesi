<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Basit PHP şablon motoru (layout + değişken kaçışı).
 *
 * Tam yol: app/Core/View.php
 */
final class View
{
    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], string $layout = 'layouts/base'): Response
    {
        $content = self::partial($template, $data);
        $html = self::partial($layout, [...$data, 'content' => $content]);
        return Response::html($html);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        $file = App::basePath('app/Views/' . $template . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException('View bulunamadı: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } finally {
            $out = ob_get_clean();
        }
        return $out === false ? '' : (string) $out;
    }
}
