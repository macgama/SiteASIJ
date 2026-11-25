<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Controller;
use App\Db;
use App\Pages;
use App\Settings;

/** Bibliothèque de médias publics (images et documents). */
final class AdminMediaController extends Controller
{
    private const PER_PAGE = 48;

    /**
     * Extensions autorisées => [type MIME enregistré, types détectés acceptés par finfo].
     * Les formats Office récents sont des archives ZIP, les anciens des conteneurs OLE.
     */
    public const TYPES = [
        'jpg' => ['image/jpeg', ['image/jpeg', 'image/pjpeg']],
        'jpeg' => ['image/jpeg', ['image/jpeg', 'image/pjpeg']],
        'png' => ['image/png', ['image/png']],
        'gif' => ['image/gif', ['image/gif']],
        'webp' => ['image/webp', ['image/webp']],
        'pdf' => ['application/pdf', ['application/pdf', 'application/x-pdf']],
        'doc' => ['application/msword', ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream']],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
        'xls' => ['application/vnd.ms-excel', ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream']],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
        'ppt' => ['application/vnd.ms-powerpoint', ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream']],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
        'odt' => ['application/vnd.oasis.opendocument.text', ['application/vnd.oasis.opendocument.text', 'application/zip']],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip']],
        'mp4' => ['video/mp4', ['video/mp4', 'application/mp4', 'video/quicktime']],
        'txt' => ['text/plain', ['text/plain']],
        'csv' => ['text/csv', ['text/csv', 'text/plain', 'application/csv', 'text/x-csv']],
    ];

    private const MAX_DIMENSION = 2400;

    public function index(): void
    {
        Auth::requireAdmin();
        [$items, $total, $page, $pages, $q, $kind] = $this->search(self::PER_PAGE);
        $this->view('admin/media/index', [
            'title' => 'Médias',
            'items' => array_map([self::class, 'toJson'], $items),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'q' => $q,
            'kind' => $kind,
            'maxBytes' => self::maxBytes(),
            'accept' => self::acceptAttr(),
        ]);
    }

    /**
     * GET /admin/medias/json?q=&kind=image|file&type=video&page=
     * (Le nom de la route impose « json » : appelée avec des arguments, la méthode
     * se comporte comme Controller::json().)
     */
    public function json(mixed $data = null, int $status = 200): void
    {
        if (func_num_args() > 0) {
            parent::json($data, $status);
            return;
        }
        Auth::requireAdmin();
        [$items, $total, $page, $pages] = $this->search(60);
        parent::json([
            'items' => array_map([self::class, 'toJson'], $items),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'max_bytes' => self::maxBytes(),
        ]);
    }

    /** POST /admin/medias — champ files[] (ou file). Réponse JSON pour fetch/XHR. */
    public function upload(): void
    {
        Auth::requireAdmin();
        $files = self::normalizeFiles($_FILES['files'] ?? ($_FILES['file'] ?? null));
        $created = [];
        $errors = [];
        if (!$files) {
            $errors[] = 'Aucun fichier reçu. Le fichier est peut-être trop volumineux.';
        }
        foreach ($files as $f) {
            try {
                $created[] = self::toJson(self::store($f));
            } catch (\RuntimeException $e) {
                $errors[] = $f['name'] . ' : ' . $e->getMessage();
            }
        }
        if ($this->wantsJson()) {
            $this->json(['ok' => !$errors, 'items' => $created, 'errors' => $errors], $created || !$errors ? 200 : 422);
            return;
        }
        if ($created) {
            flash(count($created) === 1 ? 'Le fichier a été ajouté.' : count($created) . ' fichiers ont été ajoutés.');
        }
        foreach ($errors as $err) {
            flash($err, 'error');
        }
        redirect('/admin/medias');
    }

    public function update(string $id): void
    {
        Auth::requireAdmin();
        $m = Db::one('SELECT * FROM media WHERE id = ?', [(int) $id]);
        if (!$m) {
            $this->wantsJson() ? $this->json(['ok' => false, 'error' => 'Média introuvable.'], 404) : $this->notFound();
            return;
        }
        $data = [
            'title' => mb_substr($this->input('title'), 0, 255) ?: null,
            'alt' => mb_substr($this->input('alt'), 0, 255) ?: null,
        ];
        Db::update('media', $data, 'id = :id', ['id' => (int) $id]);
        audit('media.update', 'media:' . (int) $id . ' ' . $m['original_name']);
        if ($this->wantsJson()) {
            $this->json(['ok' => true, 'item' => self::toJson($data + $m)]);
            return;
        }
        flash('Les informations du fichier ont été enregistrées.');
        redirect($this->back());
    }

    public function destroy(string $id): void
    {
        Auth::requireAdmin();
        $id = (int) $id;
        $m = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$m) {
            flash('Ce fichier n’existe plus.', 'error');
            redirect('/admin/medias');
        }
        $usedBy = Pages::usingMedia($id);
        $isLogo = Settings::get('logo_media_id') === (string) $id;
        if (($usedBy || $isLogo) && empty($_POST['force'])) {
            $this->view('admin/media/confirm-delete', [
                'title' => 'Supprimer un fichier utilisé',
                'media' => self::toJson($m),
                'pages' => $usedBy,
                'isLogo' => $isLogo,
            ]);
            return;
        }
        Db::run('DELETE FROM media WHERE id = ?', [$id]);
        $file = self::dir() . '/' . basename((string) $m['stored_name']);
        if (is_file($file)) {
            @unlink($file);
        }
        if ($isLogo) {
            Settings::set('logo_media_id', '');
        }
        audit('media.delete', 'media:' . $id . ' ' . $m['original_name']);
        flash('Le fichier « ' . ($m['title'] ?: $m['original_name']) . ' » a été supprimé.');
        redirect($this->back());
    }

    // ------------------------------------------------------------------ utilitaires

    /** Représentation d'un média pour les listes et l'éditeur. */
    public static function toJson(array $m): array
    {
        $id = (int) $m['id'];
        $url = '';
        $thumb = '';
        if (class_exists(\App\Media::class) && method_exists(\App\Media::class, 'url')) {
            $url = \App\Media::url($id);
            $thumb = $m['kind'] === 'image' ? \App\Media::url($id, 480) : '';
        }
        if ($url === '') {
            $url = '/media/' . $id . '/' . rawurlencode((string) $m['original_name']);
        }
        if ($thumb === '' && $m['kind'] === 'image') {
            $thumb = $url;
        }
        return [
            'id' => $id,
            'url' => $url,
            'thumb' => $thumb,
            'kind' => (string) $m['kind'],
            'mime' => (string) $m['mime'],
            'original_name' => (string) $m['original_name'],
            'title' => (string) ($m['title'] ?? ''),
            'alt' => (string) ($m['alt'] ?? ''),
            'size' => (int) $m['size'],
            'size_human' => human_size((int) $m['size']),
            'ext' => strtolower(pathinfo((string) $m['original_name'], PATHINFO_EXTENSION)),
            'created_at' => date_fr((string) ($m['created_at'] ?? ''), false),
        ];
    }

    public static function dir(): string
    {
        $d = STORAGE . '/media';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    /** Taille maximale effective (config et limites PHP). */
    public static function maxBytes(): int
    {
        $max = (int) Config::get('uploads.max_bytes', 25 * 1024 * 1024);
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $v = self::iniBytes((string) ini_get($k));
            if ($v > 0) {
                $max = min($max, $v);
            }
        }
        return $max;
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function acceptAttr(): string
    {
        return implode(',', array_map(fn($e) => '.' . $e, array_keys(self::TYPES)));
    }

    /** @return array<int,array{name:string,tmp_name:string,error:int,size:int}> */
    public static function normalizeFiles(mixed $f): array
    {
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return [['name' => (string) $f['name'], 'tmp_name' => (string) $f['tmp_name'], 'error' => (int) $f['error'], 'size' => (int) $f['size']]];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if (is_array($name)) {
                continue;
            }
            $out[] = ['name' => (string) $name, 'tmp_name' => (string) $f['tmp_name'][$i], 'error' => (int) $f['error'][$i], 'size' => (int) $f['size'][$i]];
        }
        return $out;
    }

    /**
     * Valide et enregistre un fichier téléversé. Retourne la ligne media créée.
     * @param bool $isUpload false pour importer un fichier local (tests, scripts)
     */
    public static function store(array $f, bool $isUpload = true): array
    {
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('fichier trop volumineux (maximum ' . human_size(self::maxBytes()) . ').');
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('le téléversement a échoué, veuillez réessayer.');
        }
        if ($isUpload && !is_uploaded_file($f['tmp_name'])) {
            throw new \RuntimeException('fichier invalide.');
        }
        $size = (int) filesize($f['tmp_name']);
        if ($size <= 0) {
            throw new \RuntimeException('le fichier est vide.');
        }
        if ($size > (int) Config::get('uploads.max_bytes', 25 * 1024 * 1024)) {
            throw new \RuntimeException('fichier trop volumineux (maximum ' . human_size((int) Config::get('uploads.max_bytes')) . ').');
        }
        $original = self::cleanName($f['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw new \RuntimeException('ce type de fichier n’est pas accepté (formats acceptés : images JPG, PNG, GIF, WebP, PDF, Word, Excel, PowerPoint, OpenDocument, MP4, TXT, CSV).');
        }
        [$mime, $accepted] = self::TYPES[$ext];
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($detected, $accepted, true)) {
            throw new \RuntimeException('le contenu du fichier ne correspond pas à son extension .' . $ext . '.');
        }
        $kind = str_starts_with($mime, 'image/') ? 'image' : 'file';
        if ($kind === 'image' && @getimagesize($f['tmp_name']) === false) {
            throw new \RuntimeException('image illisible ou endommagée.');
        }
        $safeExt = $ext === 'jpeg' ? 'jpg' : $ext;
        $stored = bin2hex(random_bytes(16)) . '.' . $safeExt;
        $dest = self::dir() . '/' . $stored;
        $ok = $isUpload ? move_uploaded_file($f['tmp_name'], $dest) : copy($f['tmp_name'], $dest);
        if (!$ok) {
            throw new \RuntimeException('impossible d’enregistrer le fichier sur le serveur.');
        }
        @chmod($dest, 0644);
        if ($kind === 'image') {
            self::resize($dest, $mime);
            clearstatcache(true, $dest);
            $size = (int) filesize($dest);
        }
        $row = [
            'stored_name' => $stored,
            'original_name' => $original,
            'title' => pathinfo($original, PATHINFO_FILENAME),
            'mime' => $mime,
            'size' => $size,
            'kind' => $kind,
            'alt' => null,
            'created_at' => Db::now(),
            'created_by' => Auth::id(),
        ];
        $row['id'] = Db::insert('media', $row);
        audit('media.upload', 'media:' . $row['id'] . ' ' . $original);
        return $row;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '', $name) ?? '';
        $name = trim($name, " .");
        if ($name === '') {
            $name = 'fichier';
        }
        return mb_substr($name, 0, 200);
    }

    /** Réduit les images trop grandes (GD) et corrige l'orientation des photos JPEG. */
    private static function resize(string $file, string $mime): void
    {
        if (!function_exists('imagecreatetruecolor') || $mime === 'image/gif') {
            return; // GIF: conserver l'animation
        }
        $info = @getimagesize($file);
        if (!$info) {
            return;
        }
        [$w, $h] = $info;
        $orientation = 1;
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file);
            $orientation = (int) ($exif['Orientation'] ?? 1);
        }
        if (max($w, $h) <= self::MAX_DIMENSION && $orientation === 1) {
            return;
        }
        if ($w * $h > 60_000_000) {
            return; // trop grande pour être traitée en mémoire sans risque
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png' => @imagecreatefrompng($file),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            default => false,
        };
        if (!$src) {
            return;
        }
        if (in_array($orientation, [3, 6, 8], true)) {
            $rot = imagerotate($src, [3 => 180, 6 => -90, 8 => 90][$orientation], 0);
            if ($rot) {
                imagedestroy($src);
                $src = $rot;
                [$w, $h] = [imagesx($src), imagesy($src)];
            }
        }
        $scale = min(1, self::MAX_DIMENSION / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime !== 'image/jpeg') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        match ($mime) {
            'image/jpeg' => imagejpeg($dst, $file, 85),
            'image/png' => imagepng($dst, $file, 6),
            'image/webp' => imagewebp($dst, $file, 85),
        };
        imagedestroy($src);
        imagedestroy($dst);
    }

    /** @return array{0:array,1:int,2:int,3:int,4:string,5:string} */
    private function search(int $perPage): array
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $kind = (string) ($_GET['kind'] ?? '');
        $kind = in_array($kind, ['image', 'file'], true) ? $kind : '';
        $where = [];
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $where[] = "(original_name LIKE ? ESCAPE '!' OR title LIKE ? ESCAPE '!' OR alt LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        if ($kind !== '') {
            $where[] = 'kind = ?';
            $params[] = $kind;
        }
        if (($_GET['type'] ?? '') === 'video') {
            $where[] = "mime LIKE 'video/%'";
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Db::value('SELECT COUNT(*) FROM media' . $sqlWhere, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
        $items = Db::all(
            'SELECT * FROM media' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );
        return [$items, $total, $page, $pages, $q, $kind];
    }

    private function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    private function back(): string
    {
        $ref = (string) ($_POST['back'] ?? '');
        return (str_starts_with($ref, '/admin/medias') && !str_starts_with($ref, '//')) ? $ref : '/admin/medias';
    }
}
