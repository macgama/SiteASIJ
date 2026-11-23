<?php
declare(strict_types=1);

namespace App;

final class View
{
    /**
     * Rend un gabarit de app/views. Un gabarit peut définir $layout
     * ('layout' public, 'admin/layout', null pour aucun) et $title.
     */
    public static function render(string $template, array $data = []): string
    {
        $file = APP . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Gabarit introuvable: $template");
        }
        $layout = str_starts_with($template, 'admin/') ? 'admin/layout' : 'layout';
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        $content = ob_get_clean();
        if ($layout === null) {
            return $content;
        }
        ob_start();
        include APP . '/views/' . $layout . '.php';
        return ob_get_clean();
    }

    /** Rend un fragment sans layout. */
    public static function partial(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include APP . '/views/' . $template . '.php';
        return ob_get_clean();
    }
}
