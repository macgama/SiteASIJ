<?php
declare(strict_types=1);

namespace App;

/**
 * Rendu des blocs de contenu d'une page.
 * Chaque bloc: ['id' => 'b_xxxx', 'type' => '...', 'data' => [...]].
 * Le gabarit app/views/blocks/{type}.php reçoit $b (le bloc) et $data (tableau, jamais null).
 * Un type inconnu, un bloc mal formé ou un gabarit en erreur est ignoré (jamais d'erreur fatale).
 */
final class Blocks
{
    /** Types de blocs reconnus (voir docs/ARCHITECTURE.md). */
    public const TYPES = [
        'heading', 'text', 'image', 'gallery', 'files', 'video',
        'callout', 'button', 'contact', 'columns', 'people', 'separator', 'table',
    ];

    /** @param array<int,mixed> $blocks */
    public static function render(array $blocks): string
    {
        $out = '';
        $row = [];
        $flush = function () use (&$row, &$out): void {
            if (count($row) > 1) {
                $out .= '<div class="block image-row">' . implode('', $row) . '</div>';
            } elseif ($row) {
                $out .= $row[0];
            }
            $row = [];
        };
        // séparateurs en double, en tête ou en fin (pages importées): ignorés
        $blocks = array_values(array_filter($blocks, 'is_array'));
        $keep = [];
        foreach ($blocks as $b) {
            $sep = ($b['type'] ?? '') === 'separator';
            if ($sep && ($keep === [] || (end($keep)['type'] ?? '') === 'separator')) {
                continue;
            }
            $keep[] = $b;
        }
        while ($keep && (end($keep)['type'] ?? '') === 'separator') {
            array_pop($keep);
        }
        foreach ($keep as $b) {
            // petites images qui se suivent (QR codes, logos…) : côte à côte
            if (is_array($b) && ($b['type'] ?? '') === 'image' && self::isSmallImage($b)) {
                $row[] = self::renderOne($b);
                continue;
            }
            $flush();
            $out .= self::renderOne($b);
        }
        $flush();
        return $out;
    }

    /** Image dont la taille d'origine est petite (≤ 420 px de large). */
    public static function isSmallImage(array $b): bool
    {
        $m = Media::find((int) ($b['data']['media_id'] ?? 0));
        $d = $m ? Media::dimensions($m) : null;
        return $d !== null && ($d['w'] <= 420 || self::isSquareGraphic($m, $d));
    }

    /** PNG/GIF carré: QR code ou pictogramme, à ne pas afficher en pleine largeur. */
    public static function isSquareGraphic(array $m, ?array $d): bool
    {
        return $d !== null && $d['h'] > 0 && in_array($m['mime'], ['image/png', 'image/gif'], true)
            && $d['w'] / $d['h'] > 0.85 && $d['w'] / $d['h'] < 1.18;
    }

    /** Rend un bloc isolé (utile pour l'aperçu de l'éditeur). */
    public static function renderOne(mixed $b): string
    {
        if (!is_array($b)) {
            return '';
        }
        $type = $b['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            return '';
        }
        $file = APP . '/views/blocks/' . $type . '.php';
        if (!is_file($file)) {
            return '';
        }
        $data = is_array($b['data'] ?? null) ? $b['data'] : [];
        $level = ob_get_level();
        try {
            $html = self::include($file, $b, $data);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            error_log('Bloc ' . $type . ' ignoré: ' . $e->getMessage());
            return '';
        }
        return trim($html) === '' ? '' : $html . "\n";
    }

    /**
     * Inclut le gabarit avec exactement $b et $data dans sa portée.
     * (View::partial() ne convient pas: son paramètre $data masquerait celui du bloc.)
     */
    private static function include(string $__file, array $b, array $data): string
    {
        ob_start();
        include $__file;
        return (string) ob_get_clean();
    }

    /** Décode le JSON de la colonne pages.blocks (tableau vide si invalide). */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $v = json_decode($json, true);
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    /** Vrai si aucun bloc ne produit de contenu visible. */
    public static function isEmpty(array $blocks): bool
    {
        return trim(self::render($blocks)) === '';
    }

    /** Lit une chaîne dans $data sans jamais échouer. */
    public static function str(array $data, string $key, string $default = ''): string
    {
        $v = $data[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /** Lit une liste d'éléments (tableaux) dans $data. @return array<int,array<string,mixed>> */
    public static function items(array $data, string $key = 'items'): array
    {
        $v = $data[$key] ?? [];
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    /**
     * Reconnaît une URL YouTube ou Vimeo et retourne l'URL d'intégration
     * (youtube-nocookie.com ou player.vimeo.com uniquement), ou null.
     */
    public static function videoEmbed(string $url): ?string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.)/', '', $host);
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $id = null;
        if (in_array($host, ['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'], true)) {
            if ($path === '/watch' && is_string($q['v'] ?? null)) {
                $id = $q['v'];
            } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
                $id = $m[1];
            }
        } elseif ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        }
        if ($id !== null) {
            return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)
                ? 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0'
                : null;
        }
        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            if (preg_match('#/(?:video/)?(\d{5,12})(?:/([0-9a-f]{6,20}))?/?$#', $path, $m)) {
                $u = 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1';
                if (!empty($m[2])) {
                    $u .= '&h=' . $m[2];
                }
                return $u;
            }
        }
        return null;
    }
}
