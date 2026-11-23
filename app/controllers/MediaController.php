<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Controller;
use App\Media;

/**
 * Sert les médias publics de storage/media. Le fichier est toujours trouvé par son id
 * en base (jamais par un chemin issu de l'URL); le {name} de l'URL est décoratif.
 * Paramètre ?w=480|960|1600 sur une image: vignette mise en cache dans storage/cache/thumbs.
 */
final class MediaController extends Controller
{
    private const MAX_AGE = 2592000; // 30 jours

    public function serve(string $id, string $name = ''): void
    {
        $m = Media::find((int) $id);
        $file = $m ? Media::path($m) : '';
        if (!$m || $file === '' || !is_file($file) || !is_readable($file)) {
            $this->plainNotFound();
            return;
        }
        $mime = (string) $m['mime'];
        if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime)) {
            $mime = 'application/octet-stream';
        }

        $w = isset($_GET['w']) && is_string($_GET['w']) ? (int) $_GET['w'] : 0;
        if ($w > 0 && in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $thumb = $this->thumbnail($m, $file, Media::thumbWidth($w), $mime);
            if ($thumb !== null) {
                $file = $thumb;
            }
        }

        $size = (int) filesize($file);
        $mtime = (int) filemtime($file);
        $etag = '"' . substr(sha1($m['id'] . '|' . $m['stored_name'] . '|' . $size . '|' . $mtime . '|' . $file), 0, 20) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: public, max-age=' . self::MAX_AGE);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModified);
        header('X-Content-Type-Options: nosniff');
        // Un fichier servi ne doit jamais exécuter de script (SVG, HTML déguisé…).
        // Pas pour les PDF: la visionneuse intégrée de Chrome refuse les documents « sandbox ».
        if ($mime !== 'application/pdf') {
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; media-src 'self'; sandbox");
        }

        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        if (($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true))
            || ($inm === '' && $ims !== '' && strtotime($ims) !== false && strtotime($ims) >= $mtime)) {
            http_response_code(304);
            return;
        }

        $inline = in_array($mime, Media::INLINE_IMAGES, true) || $mime === 'application/pdf'
            || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; ' . self::filenameHeader((string) $m['original_name']));
        header('Accept-Ranges: bytes');

        // Requêtes partielles (lecture vidéo, reprise de téléchargement): une seule plage.
        $start = 0;
        $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range !== '' && $size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $r) && ($r[1] !== '' || $r[2] !== '')) {
            if ($r[1] === '') {
                $start = max(0, $size - (int) $r[2]);
            } else {
                $start = (int) $r[1];
                $end = $r[2] !== '' ? min((int) $r[2], $size - 1) : $size - 1;
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        $length = $end - $start + 1;
        header('Content-Length: ' . max(0, $length));

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length <= 0) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return;
        }
        fseek($fh, $start);
        $left = $length;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, min(65536, $left));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
    }

    private function plainNotFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Fichier introuvable.';
    }

    /** Content-Disposition: filename ASCII de repli + filename* UTF-8 (RFC 6266). */
    public static function filenameHeader(string $name): string
    {
        $name = str_replace(["\r", "\n", '"', '\\', '/'], ' ', $name);
        $ascii = Media::slugName($name);
        return 'filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /** Crée (une fois) une vignette redimensionnée. Retourne son chemin ou null (fichier d'origine). */
    private function thumbnail(array $m, string $file, int $width, string $mime): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $info = @getimagesize($file);
        if (!$info || $info[0] <= $width || $info[0] * $info[1] > 40_000_000) {
            return null; // déjà assez petite, ou trop grande pour la mémoire
        }
        $dir = STORAGE . '/cache/thumbs';
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : ($mime === 'image/gif' ? 'gif' : 'jpg'));
        $target = $dir . '/' . (int) $m['id'] . '-' . $width . '-' . substr(sha1((string) $m['stored_name'] . filemtime($file)), 0, 10) . '.' . $ext;
        if (is_file($target)) {
            return $target;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png' => @imagecreatefrompng($file),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            'image/gif' => @imagecreatefromgif($file),
            default => false,
        };
        if (!$src) {
            return null;
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file);
            $rot = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($rot) {
                $rotated = imagerotate($src, $rot, 0);
                if ($rotated) {
                    imagedestroy($src);
                    $src = $rotated;
                }
            }
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= $width) {
            imagedestroy($src);
            return null;
        }
        $h = max(1, (int) round($sh * $width / $sw));
        $dst = imagecreatetruecolor($width, $h);
        if ($ext !== 'jpg') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $h, $sw, $sh);
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = match ($ext) {
            'png' => imagepng($dst, $tmp, 7),
            'webp' => imagewebp($dst, $tmp, 82),
            'gif' => imagegif($dst, $tmp),
            default => imagejpeg($dst, $tmp, 82),
        };
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok || !@rename($tmp, $target)) {
            @unlink($tmp);
            return null;
        }
        return $target;
    }
}
