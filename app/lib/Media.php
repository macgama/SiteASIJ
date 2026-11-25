<?php
declare(strict_types=1);

namespace App;

/**
 * Médias publics (table `media`, fichiers dans storage/media/).
 * Les fichiers sont servis par MediaController::serve via /media/{id}/{nom}.
 */
final class Media
{
    /** Largeurs de vignettes autorisées (paramètre ?w= de l'URL). */
    public const THUMB_WIDTHS = [480, 960, 1600];

    /** Types d'images affichables dans le navigateur. */
    public const INLINE_IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif'];

    /** @var array<int,array<string,mixed>|null> */
    private static array $cache = [];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if (!array_key_exists($id, self::$cache)) {
            self::$cache[$id] = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
        }
        return self::$cache[$id];
    }

    /**
     * Charge plusieurs médias d'un coup (évite une requête par vignette).
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    public static function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        $missing = array_values(array_filter($ids, fn($i) => !array_key_exists($i, self::$cache)));
        if ($missing) {
            foreach ($missing as $i) {
                self::$cache[$i] = null;
            }
            $in = implode(',', array_fill(0, count($missing), '?'));
            foreach (Db::all("SELECT * FROM media WHERE id IN ($in)", $missing) as $row) {
                self::$cache[(int) $row['id']] = $row;
            }
        }
        $out = [];
        foreach ($ids as $i) {
            if (self::$cache[$i] !== null) {
                $out[$i] = self::$cache[$i];
            }
        }
        return $out;
    }

    /** Vide le cache interne (après modification d'un média). */
    public static function forget(?int $id = null): void
    {
        if ($id === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$id]);
        }
    }

    /**
     * URL publique d'un média: /media/{id}/{nom-lisible}. Chaîne vide si le média n'existe pas.
     * $width (images seulement): vignette redimensionnée, arrondie à une largeur autorisée.
     */
    public static function url(int $id, ?int $width = null): string
    {
        $m = self::find($id);
        if (!$m) {
            return '';
        }
        $u = '/media/' . (int) $m['id'] . '/' . rawurlencode(self::slugName((string) $m['original_name']));
        if ($width !== null && self::isImage($m) && $m['mime'] !== 'image/svg+xml') {
            $u .= '?w=' . self::thumbWidth($width);
        }
        return $u;
    }

    /** Chemin absolu du fichier sur le disque (jamais construit à partir de l'URL). */
    public static function path(array $m): string
    {
        return STORAGE . '/media/' . basename((string) ($m['stored_name'] ?? ''));
    }

    /** Largeur et hauteur d'origine d'une image (null si illisible), mémorisées par requête. */
    public static function dimensions(array $m): ?array
    {
        static $cache = [];
        $id = (int) $m['id'];
        if (!array_key_exists($id, $cache)) {
            $info = self::isImage($m) ? @getimagesize(self::path($m)) : false;
            $cache[$id] = $info ? ['w' => (int) $info[0], 'h' => (int) $info[1]] : null;
        }
        return $cache[$id];
    }

    public static function isImage(array $m): bool
    {
        return in_array((string) ($m['mime'] ?? ''), self::INLINE_IMAGES, true);
    }

    /** Plus petite largeur autorisée >= $w (ou la plus grande). */
    public static function thumbWidth(int $w): int
    {
        foreach (self::THUMB_WIDTHS as $allowed) {
            if ($w <= $allowed) {
                return $allowed;
            }
        }
        return self::THUMB_WIDTHS[count(self::THUMB_WIDTHS) - 1];
    }

    /** Nom de fichier lisible et sûr pour l'URL: « Préavis 01/2026.pdf » -> « preavis-01-2026.pdf ». */
    public static function slugName(string $name): string
    {
        $name = str_replace(['/', '\\'], '-', $name);
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $base = (string) pathinfo($name, PATHINFO_FILENAME);
        $ext = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : '';
        $slug = self::slug($base);
        if ($slug === '') {
            $slug = 'fichier';
        }
        return $ext !== '' ? $slug . '.' . $ext : $slug;
    }

    public static function slug(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($n)) {
                $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
            }
        }
        $s = strtr($s, ['ß' => 'ss', 'æ' => 'ae', 'Æ' => 'ae', 'œ' => 'oe', 'Œ' => 'oe', 'ø' => 'o', 'Ø' => 'o', 'đ' => 'd', 'ł' => 'l']);
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return mb_substr($s, 0, 80);
    }

    /** Extension en majuscules pour affichage (PDF, DOCX…). */
    public static function extension(array $m): string
    {
        $ext = strtoupper((string) pathinfo(str_replace(['/', '\\'], '-', (string) ($m['original_name'] ?? '')), PATHINFO_EXTENSION));
        return preg_match('/^[A-Z0-9]{1,6}$/', $ext) ? $ext : '';
    }

    /**
     * Catégorie d'icône d'un fichier: pdf, word, sheet, slides, image, archive, audio, video, file.
     */
    public static function iconType(array $m): string
    {
        $mime = (string) ($m['mime'] ?? '');
        $ext = strtolower(self::extension($m));
        return match (true) {
            $mime === 'application/pdf' || $ext === 'pdf' => 'pdf',
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'txt'], true) => 'word',
            in_array($ext, ['xls', 'xlsx', 'ods', 'csv'], true) => 'sheet',
            in_array($ext, ['ppt', 'pptx', 'odp'], true) => 'slides',
            in_array($ext, ['zip', '7z', 'rar', 'gz'], true) => 'archive',
            default => 'file',
        };
    }
}
