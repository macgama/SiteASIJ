<?php
declare(strict_types=1);
/**
 * Import du contenu de l'ancien site Jimdo Creator (www.asij.ch) dans le nouveau site.
 *
 * À lancer en ligne de commande SUR LE SERVEUR (ou un ordinateur) qui a accès à www.asij.ch :
 *   php bin/import-jimdo.php                 import complet (≈ 20 minutes, 5 s entre deux pages)
 *   php bin/import-jimdo.php --dry-run       simulation : rien n'est écrit (ni base, ni fichiers)
 *   php bin/import-jimdo.php --only=/cet/    une seule page (ancien chemin)
 *
 * Options :
 *   --source=URL          ancien site (défaut https://www.asij.ch)
 *   --manifest=FICHIER    inventaire (défaut migration/manifest.json)
 *   --cache=DOSSIER       cache des téléchargements (défaut migration/downloads) : relancer ne retélécharge rien
 *   --delay=5             secondes entre deux requêtes vers l'ancien site (Crawl-Delay du robots.txt)
 *   --cdn-delay=1         secondes entre deux requêtes vers image.jimcdn.com
 *   --skip-videos         (défaut) les vidéos MP4 ne sont pas importées, seulement listées dans le rapport
 *   --download-videos     importe aussi les vidéos MP4 dans les médias (déconseillé : ~3 Go)
 *   --write-logo          copie aussi le logo dans public/assets/img/logo.png
 *   --force               réécrit aussi les pages déjà modifiées dans l'administration
 *   --report=FICHIER      rapport (défaut migration/rapport-import.md)
 *   --export-sql=FICHIER  écrit en plus un fichier SQL (MySQL) des pages/médias importés, pour phpMyAdmin
 *   --media-dir=DOSSIER   dossier des médias (défaut storage/media)
 *   --from-dir=DOSSIER    mode hors-ligne : lit les pages HTML dans ce dossier (tests)
 *   --files-dir=DOSSIER   mode hors-ligne : lit les fichiers/images dans ce dossier (tests)
 *   --quiet               n'affiche que le résumé
 *
 * Le script est idempotent : pages et médias sont retrouvés par leur legacy_url (ancienne adresse),
 * les médias sont en plus dédoublonnés par empreinte sha1 du contenu.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Db;
use App\Html;
use App\Settings;

final class JimdoImporter
{
    public const TITLE_SUFFIX = '/\s*[-–|]\s*ASIJ\s*(?:[-–|]\s*Association scolaire intercommunale du Jorat)?\s*$/iu';
    public const PROTECTED_RE = '#/documents-destin(?:é|e)s-aux-membres/#u';
    public const PRIVATE_FOLDER = 'Documents destinés aux membres';
    public const LEGAL_LEGACY = '/about/';
    public const CONTACT_LEGACY = '/contact/';
    public const TRANSPORT_LEGACY = '/transports-scolaires/';
    public const LOGO_ID = 'ie9fe4872af78a219';
    private const VIDEO_EXT = ['mp4', 'm4v', 'mov', 'webm', 'avi', 'mpg', 'mpeg', 'wmv'];
    private const UA = 'ASIJ-Migration/1.0 (import du site Jimdo vers le nouveau site)';

    /** @var array<string,mixed> */
    public array $opt;
    private array $manifest = [];
    /** @var array<string,string> ancien chemin => nouveau chemin */
    private array $map = [];
    /** @var array<string,array> ancien chemin => données analysées */
    private array $parsed = [];
    private array $navOrder = [];
    private array $navTitle = [];
    private ?array $transport = null;
    private int $transportSeen = 0;
    private array $lastHit = [];
    private ?array $sha1Index = null;
    private int $fakeId = 0;
    private int $requests = 0;
    private array $mediaUrlCache = [];

    public array $stats = [
        'pages_created' => 0, 'pages_updated' => 0, 'pages_unchanged' => 0, 'pages_skipped' => 0,
        'media_created' => 0, 'media_reused' => 0, 'media_dedup' => 0,
    ];
    public array $errors = [];
    public array $warnings = [];
    public array $videos = [];
    public array $info = [];
    public array $pageLog = [];
    public array $dedupLog = [];

    public function __construct(array $opt)
    {
        $this->opt = $opt + [
            'source' => 'https://www.asij.ch',
            'manifest' => ROOT . '/migration/manifest.json',
            'cache' => ROOT . '/migration/downloads',
            'delay' => 5.0,
            'cdn-delay' => 1.0,
            'dry-run' => false,
            'only' => null,
            'download-videos' => false,
            'write-logo' => false,
            'force' => false,
            'report' => ROOT . '/migration/rapport-import.md',
            'export-sql' => null,
            'media-dir' => STORAGE . '/media',
            'from-dir' => null,
            'files-dir' => null,
            'logo-path' => ROOT . '/public/assets/img/logo.png',
            'quiet' => false,
        ];
        $this->opt['source'] = rtrim((string) $this->opt['source'], '/');
    }

    // =================================================================== exécution

    public function run(): array
    {
        $started = microtime(true);
        $this->manifest = $this->loadManifest();
        $paths = $this->discoverPaths();
        foreach ($paths as $p) {
            $this->map[$p] = self::newPath($p);
        }
        $only = $this->opt['only'] !== null ? self::normPath((string) $this->opt['only']) : null;
        if ($only !== null) {
            if (!in_array($only, $paths, true)) {
                $this->errors[] = "Chemin --only inconnu : $only";
            }
            $paths = array_values(array_filter($paths, fn($p) => $p === $only));
        }

        $dry = (bool) $this->opt['dry-run'];
        $pdo = Db::pdo();
        if ($dry) {
            $pdo->beginTransaction();
        }
        try {
            if (!$this->opt['from-dir']) {
                $this->readRobots();
            }
            $this->importLogo();
            $n = count($paths);
            foreach ($paths as $i => $legacy) {
                $this->say(sprintf('[%d/%d] %s', $i + 1, $n, $legacy));
                if (preg_match(self::PROTECTED_RE, $legacy)) {
                    $this->createPrivateFolder($legacy);
                    continue;
                }
                if ($legacy === self::LEGAL_LEGACY) {
                    $this->parsed[$legacy] = $this->legalPage();
                    continue;
                }
                $html = $this->fetchPage($legacy);
                if ($html === null) {
                    continue;
                }
                if (self::looksProtected($html)) {
                    $this->warnings[] = "$legacy : page protégée par mot de passe, non importée.";
                    $this->createPrivateFolder($legacy);
                    continue;
                }
                try {
                    $this->parsed[$legacy] = $this->parsePage($legacy, $html);
                } catch (\Throwable $e) {
                    $this->errors[] = "$legacy : analyse impossible (" . $e->getMessage() . ')';
                }
            }
            $this->attachTransport();
            $this->writePages();
            if ($this->opt['export-sql'] && !$dry) {
                $this->exportSql((string) $this->opt['export-sql']);
            }
        } finally {
            if ($dry && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $this->stats['duration'] = (int) round(microtime(true) - $started);
        $this->stats['requests'] = $this->requests;
        $this->writeReport();
        return $this->stats;
    }

    private function say(string $msg): void
    {
        if (!$this->opt['quiet']) {
            echo $msg, "\n";
        }
    }

    private function loadManifest(): array
    {
        $f = (string) $this->opt['manifest'];
        if (!is_file($f)) {
            $this->warnings[] = "Inventaire introuvable ($f) : seul le sitemap sera utilisé.";
            return ['pages' => [], 'common' => []];
        }
        $m = json_decode((string) file_get_contents($f), true);
        if (!is_array($m)) {
            throw new RuntimeException("Inventaire illisible : $f");
        }
        return $m;
    }

    /** Liste des anciens chemins : sitemap.xml, complété par l'inventaire. */
    private function discoverPaths(): array
    {
        $paths = [];
        $xml = null;
        if ($this->opt['from-dir']) {
            $f = rtrim((string) $this->opt['from-dir'], '/') . '/sitemap.xml';
            $xml = is_file($f) ? (string) file_get_contents($f) : null;
        } else {
            $xml = $this->fetch($this->opt['source'] . '/sitemap.xml', 'pages', (float) $this->opt['delay']);
        }
        if ($xml !== null && preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m)) {
            foreach ($m[1] as $loc) {
                $paths[] = self::normPath((string) parse_url(html_entity_decode($loc), PHP_URL_PATH));
            }
        } else {
            $this->warnings[] = 'sitemap.xml indisponible : utilisation des chemins de l’inventaire.';
        }
        foreach ($this->manifest['pages'] ?? [] as $p) {
            $paths[] = self::normPath((string) $p['path']);
        }
        return array_values(array_unique($paths));
    }

    // =================================================================== chemins et slugs

    /** Chemin décodé, NFC, avec / initial et final ("/" pour l'accueil). */
    public static function normPath(string $p): string
    {
        if (preg_match('#^[a-z]+://#i', $p) || str_starts_with($p, '//')) {
            $p = (string) parse_url($p, PHP_URL_PATH);
        }
        $p = (string) preg_replace('/[?#].*$/s', '', $p);
        $p = self::nfc(rawurldecode($p));
        $p = trim($p, '/');
        return $p === '' ? '/' : '/' . $p . '/';
    }

    public static function nfc(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) {
                return $n;
            }
        }
        return $s;
    }

    /** Translittération ASCII d'un segment (utilise App\Pages::slugify si disponible). */
    public static function slugify(string $s): string
    {
        $s = self::nfc($s);
        if (class_exists('App\\Pages') && method_exists('App\\Pages', 'slugify')) {
            return \App\Pages::slugify($s);
        }
        return self::localSlugify($s);
    }

    public static function localSlugify(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($n)) {
                $s = (string) preg_replace('/\p{Mn}+/u', '', $n);
            }
        }
        $s = strtr($s, ['ß' => 'ss', 'æ' => 'ae', 'Æ' => 'ae', 'œ' => 'oe', 'Œ' => 'oe', 'ø' => 'o', 'Ø' => 'o', '&' => '-et-']);
        $s = mb_strtolower($s, 'UTF-8');
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }

    /** Ancien chemin Jimdo -> nouveau chemin. "/présentation/le-comité/" -> "/presentation/le-comite". */
    public static function newPath(string $legacy): string
    {
        $legacy = self::normPath($legacy);
        if ($legacy === '/') {
            return '/';
        }
        if ($legacy === self::LEGAL_LEGACY) {
            return '/mentions-legales';
        }
        $segs = array_filter(explode('/', trim($legacy, '/')), fn($s) => $s !== '');
        return '/' . implode('/', array_map(fn($s) => self::slugify($s) ?: 'page', $segs));
    }

    public static function parentLegacy(string $legacy): ?string
    {
        if ($legacy === '/') {
            return null;
        }
        $segs = explode('/', trim($legacy, '/'));
        array_pop($segs);
        return $segs ? '/' . implode('/', $segs) . '/' : null; // pages de 1er niveau: pas de parent
    }

    private function sourceUrl(string $path): string
    {
        return $this->opt['source'] . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    // =================================================================== téléchargements

    private function readRobots(): void
    {
        $txt = $this->fetch($this->opt['source'] . '/robots.txt', 'pages', (float) $this->opt['delay']);
        if ($txt !== null && preg_match('/^\s*Crawl-delay\s*:\s*([\d.]+)/mi', $txt, $m)) {
            $d = (float) $m[1];
            if ($d > (float) $this->opt['delay']) {
                $this->opt['delay'] = $d;
                $this->info[] = "Crawl-Delay du robots.txt respecté : $d s.";
            }
        }
    }

    private function fetchPage(string $legacy): ?string
    {
        if ($this->opt['from-dir']) {
            $dir = rtrim((string) $this->opt['from-dir'], '/');
            $flat = $legacy === '/' ? 'index' : str_replace('/', '__', trim(self::newPath($legacy), '/'));
            foreach ([$dir . $legacy . 'index.html', $dir . '/' . $flat . '.html'] as $f) {
                if (is_file($f)) {
                    return (string) file_get_contents($f);
                }
            }
            $this->errors[] = "$legacy : fichier de page introuvable dans " . $dir;
            return null;
        }
        $html = $this->fetch($this->sourceUrl($legacy), 'pages', (float) $this->opt['delay']);
        if ($html === null) {
            $this->errors[] = "$legacy : page impossible à télécharger.";
        }
        return $html;
    }

    /** Téléchargement avec cache local et délai entre requêtes par hôte. */
    private function fetch(string $url, string $bucket, float $delay): ?string
    {
        $dir = rtrim((string) $this->opt['cache'], '/') . '/' . $bucket;
        $cacheFile = $dir . '/' . sha1($url) . '.bin';
        if (is_file($cacheFile)) {
            return (string) file_get_contents($cacheFile);
        }
        $body = $this->httpGet($url, $delay);
        if ($body !== null) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($cacheFile . '.tmp', $body);
            @rename($cacheFile . '.tmp', $cacheFile);
        }
        return $body;
    }

    private function httpGet(string $url, float $delay): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $wait = ($this->lastHit[$host] ?? 0) + $delay - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1e6));
            }
            $this->requests++;
            [$code, $body] = function_exists('curl_init') ? $this->curlGet($url) : $this->streamGet($url);
            $this->lastHit[$host] = microtime(true);
            if ($code === 200 && $body !== null) {
                return $body;
            }
            if ($code !== 0 && $code !== 429 && $code < 500) {
                $this->errors[] = "HTTP $code : $url";
                return null;
            }
            sleep((int) max(2, $delay * 2 * $attempt));
        }
        $this->errors[] = "Échec après 3 essais : $url";
        return null;
    }

    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : null];
    }

    private function streamGet(string $url): array
    {
        $ctx = stream_context_create(['http' => [
            'user_agent' => self::UA, 'timeout' => 600, 'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1]; // le dernier statut (après redirections)
            }
        }
        return [$code, is_string($body) ? $body : null];
    }

    /** Contenu d'un fichier ou d'une image (dossier factice en mode test, sinon réseau + cache). */
    private function fetchBinary(string $url): ?string
    {
        if ($this->opt['files-dir']) {
            $dir = rtrim((string) $this->opt['files-dir'], '/');
            $cands = [];
            if (preg_match('#/app/download/(\d+)/([^/?]+)#', $url, $m)) {
                $cands = glob($dir . '/' . $m[1] . '.*') ?: [];
                $cands[] = $dir . '/' . self::nfc(urldecode($m[2]));
            } elseif (preg_match('#/image/(i[0-9a-f]+)/#', $url, $m)) {
                $cands = glob($dir . '/' . $m[1] . '.*') ?: [];
            } else {
                $cands[] = $dir . '/' . basename((string) parse_url($url, PHP_URL_PATH));
            }
            foreach ($cands as $f) {
                if (is_file($f)) {
                    return (string) file_get_contents($f);
                }
            }
            $this->errors[] = "Fichier factice introuvable pour $url";
            return null;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $delay = str_ends_with($host, 'jimcdn.com') ? (float) $this->opt['cdn-delay'] : (float) $this->opt['delay'];
        $body = $this->fetch($url, 'files', $delay);
        if ($body === null) {
            $this->errors[] = "Téléchargement impossible : $url";
        }
        return $body;
    }

    // =================================================================== médias

    /** URL d'origine d'une image Jimdo (sans le segment transf/...). */
    public static function originalImageUrl(string $src): string
    {
        $src = trim(html_entity_decode($src));
        if (str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        }
        $src = (string) preg_replace('/[?#].*$/s', '', $src);
        return (string) preg_replace('#/app/cms/image/transf/[^/]+/#', '/app/cms/image/', $src);
    }

    /** URL absolue d'un fichier Jimdo, sans le paramètre ?t=. */
    public function downloadUrl(string $href): string
    {
        $href = trim(html_entity_decode($href));
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        } elseif (str_starts_with($href, '/')) {
            $href = $this->opt['source'] . $href;
        }
        $href = (string) preg_replace('/#.*$/s', '', $href);
        $parts = parse_url($href);
        $q = [];
        parse_str($parts['query'] ?? '', $q);
        unset($q['t']);
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
        return $q ? $base . '?' . http_build_query($q) : $base;
    }

    private static function isJimdoImage(string $src): bool
    {
        return (bool) preg_match('#(^|//)([a-z0-9-]+\.)*jimcdn\.com/app/cms/image/#i', $src);
    }

    private function mediaDir(): string
    {
        $d = rtrim((string) $this->opt['media-dir'], '/');
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    private function buildSha1Index(): void
    {
        $this->sha1Index = [];
        $dir = $this->mediaDir();
        foreach (Db::all('SELECT id, stored_name FROM media') as $r) {
            $f = $dir . '/' . basename((string) $r['stored_name']);
            if (is_file($f)) {
                $this->sha1Index[sha1_file($f)] ??= (int) $r['id'];
            }
        }
    }

    /**
     * Retourne l'id du média correspondant à $url (créé si besoin). null en cas d'erreur.
     * @param string $kindHint 'image' | 'file'
     */
    public function media(string $url, string $kindHint, string $title = '', string $alt = '', string $name = ''): ?int
    {
        if (isset($this->mediaUrlCache[$url])) {
            return $this->mediaUrlCache[$url];
        }
        $row = Db::one('SELECT id FROM media WHERE legacy_url = ?', [$url]);
        if ($row) {
            $this->stats['media_reused']++;
            return $this->mediaUrlCache[$url] = (int) $row['id'];
        }
        if ($this->opt['dry-run']) {
            $this->stats['media_created']++;
            return $this->mediaUrlCache[$url] = 900000 + (++$this->fakeId);
        }
        $data = $this->fetchBinary($url);
        if ($data === null || $data === '') {
            return null;
        }
        $sha = sha1($data);
        if ($this->sha1Index === null) {
            $this->buildSha1Index();
        }
        if (isset($this->sha1Index[$sha])) {
            $this->stats['media_dedup']++;
            $this->dedupLog[] = "$url = média n° " . $this->sha1Index[$sha] . ' (contenu identique)';
            return $this->mediaUrlCache[$url] = $this->sha1Index[$sha];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->buffer($data) ?: 'application/octet-stream');
        $kind = str_starts_with($mime, 'image/') ? 'image' : 'file';
        if ($kindHint === 'image' && $kind !== 'image') {
            $this->errors[] = "$url : n'est pas une image ($mime).";
            return null;
        }
        if ($name === '') {
            $name = self::nfc(urldecode(basename((string) parse_url($url, PHP_URL_PATH))));
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $byMime = self::extForMime($mime);
        if ($kind === 'image' && $byMime !== '') {
            $ext = $byMime;
        } elseif (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
            $ext = $byMime !== '' ? $byMime : 'bin';
        }
        $base = pathinfo($name, PATHINFO_FILENAME);
        $name = ($base !== '' ? $base : 'fichier') . '.' . $ext;
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = $this->mediaDir();
        if (@file_put_contents($dir . '/' . $stored, $data) === false) {
            $this->errors[] = "Écriture impossible dans $dir";
            return null;
        }
        $id = Db::insert('media', [
            'stored_name' => $stored,
            'original_name' => mb_substr($name, 0, 255),
            'title' => $title !== '' ? mb_substr($title, 0, 255) : null,
            'mime' => $mime,
            'size' => strlen($data),
            'kind' => $kind,
            'alt' => $alt !== '' ? mb_substr($alt, 0, 255) : null,
            'legacy_url' => $url,
            'created_at' => Db::now(),
            'created_by' => null,
        ]);
        $this->sha1Index[$sha] = $id;
        $this->stats['media_created']++;
        return $this->mediaUrlCache[$url] = $id;
    }

    private static function extForMime(string $mime): string
    {
        return [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'image/svg+xml' => 'svg', 'application/pdf' => 'pdf', 'video/mp4' => 'mp4',
            'application/msword' => 'doc', 'application/zip' => 'zip',
        ][$mime] ?? '';
    }

    private function mediaHref(int $id): string
    {
        if ($id >= 900000 || !class_exists('App\\Media')) {
            return '/media/' . $id . '/fichier';
        }
        return \App\Media::url($id) ?: '/media/' . $id . '/fichier';
    }

    private function imageMedia(string $src, string $alt = '', string $caption = ''): ?int
    {
        $url = self::originalImageUrl($src);
        $imgId = preg_match('#/image/(i[0-9a-f]+)/#', $url, $m) ? $m[1] : substr(sha1($url), 0, 12);
        $label = trim($alt !== '' ? $alt : $caption);
        $base = $label !== '' ? (self::localSlugify($label) ?: 'image') : 'image';
        return $this->media($url, 'image', $label, $alt, mb_substr($base, 0, 60) . '-' . $imgId . '.jpg');
    }

    private function importLogo(): void
    {
        $logo = (string) ($this->manifest['common']['logo'] ?? '');
        if ($logo === '' || !str_contains($logo, self::LOGO_ID)) {
            $logo = 'https://image.jimcdn.com/app/cms/image/transf/dimension=239x10000:format=png/path/s2c49c8dc469ab9ea/image/'
                . self::LOGO_ID . '/version/1487177292/image.png';
        }
        $url = self::originalImageUrl($logo);
        $id = $this->media($url, 'image', 'Logo ASIJ', 'ASIJ – Association scolaire intercommunale du Jorat', 'logo-asij.png');
        if ($id === null) {
            $this->errors[] = 'Logo non téléchargé.';
            return;
        }
        Settings::set('logo_media_id', (string) $id);
        $this->info[] = "Logo enregistré comme média n° $id (réglage logo_media_id).";
        if ($this->opt['write-logo'] && !$this->opt['dry-run']) {
            $m = Db::one('SELECT stored_name, mime FROM media WHERE id = ?', [$id]);
            if ($m && $m['mime'] === 'image/png') {
                $target = (string) $this->opt['logo-path'];
                @mkdir(dirname($target), 0775, true);
                if (@copy($this->mediaDir() . '/' . $m['stored_name'], $target)) {
                    $this->info[] = "Logo copié dans $target.";
                } else {
                    $this->errors[] = "Copie du logo impossible vers $target.";
                }
            } else {
                $this->warnings[] = 'Logo non copié dans public/assets/img/logo.png : ce n’est pas un PNG.';
            }
        }
    }

    // =================================================================== analyse HTML

    public static function looksProtected(string $html): bool
    {
        return (bool) preg_match('#<input[^>]+type=["\']?password#i', $html);
    }

    private static function loadDom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $doc;
    }

    private static function cls(string $c): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
    }

    private static function text(?\DOMNode $n): string
    {
        if ($n === null) {
            return '';
        }
        $t = str_replace("\u{a0}", ' ', $n->textContent);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    private static function inner(\DOMNode $n): string
    {
        $out = '';
        foreach ($n->childNodes as $c) {
            $out .= $n->ownerDocument->saveHTML($c);
        }
        return $out;
    }

    private static function blockId(string $legacy, int $i, string $type): string
    {
        return 'b_' . substr(sha1($legacy . '|' . $i . '|' . $type), 0, 10);
    }

    /** @return array{title:string,menu_title:?string,meta:?string,blocks:array} */
    public function parsePage(string $legacy, string $html): array
    {
        $doc = self::loadDom($html);
        $xp = new \DOMXPath($doc);
        $this->readNav($xp);

        $title = '';
        $t = $xp->query('//title')->item(0);
        $docTitle = trim((string) preg_replace(self::TITLE_SUFFIX, '', self::text($t)));
        $meta = $xp->query('//meta[@name="description"]/@content')->item(0);
        $meta = $meta ? trim($meta->nodeValue ?? '') : '';

        $root = $xp->query('//*[@id="content_area"]')->item(0)
            ?? $xp->query('//*[@data-container="content"]')->item(0);
        $ctx = ['legacy' => $legacy, 'skip' => false, 'transport' => []];
        $blocks = [];
        if ($root === null) {
            $this->warnings[] = "$legacy : zone de contenu (#content_area) introuvable.";
        } else {
            foreach (self::modules($xp, $root) as $mod) {
                $this->convertModule($xp, $mod, $blocks, $ctx);
            }
        }
        // Titre: premier h1 du contenu, sinon <title>
        foreach ($blocks as $i => $b) {
            if ($b['type'] === 'heading' && !empty($b['h1'])) {
                $title = $b['data']['text'];
                unset($blocks[$i]);
                break;
            }
        }
        $blocks = array_values($blocks);
        if ($title === '') {
            $title = $docTitle !== '' ? $docTitle : ($this->navTitle[$legacy] ?? 'Page sans titre');
        }

        // Bloc latéral « Informations Transports »
        $side = $xp->query('//*[@data-container="sidebar"]')->item(0)
            ?? $xp->query('//*[' . self::cls('jtpl-sidebar') . ']')->item(0)
            ?? $xp->query('//*[@id="sidebar"]')->item(0);
        if ($side !== null) {
            $sctx = ['legacy' => $legacy, 'skip' => false, 'transport' => [], 'sidebar' => true];
            $sb = [];
            foreach (self::modules($xp, $side) as $mod) {
                $this->convertModule($xp, $mod, $sb, $sctx);
            }
            $sb = array_merge($sctx['transport'], $sb);
            if ($sb && stripos(self::text($side), 'transport') !== false) {
                $this->transportSeen++;
                $this->transport ??= ['from' => $legacy, 'blocks' => $sb];
            } elseif ($sb) {
                $this->warnings[] = "$legacy : contenu de la colonne latérale ignoré.";
            }
        }
        if ($ctx['transport']) {
            $this->transportSeen++;
            $this->transport ??= ['from' => $legacy, 'blocks' => $ctx['transport']];
        }

        if ($legacy === self::CONTACT_LEGACY) {
            $has = false;
            foreach ($blocks as $b) {
                $has = $has || $b['type'] === 'contact';
            }
            if (!$has) {
                $blocks[] = ['type' => 'contact', 'data' => ['intro' => '']];
            }
        }

        $blocks = self::mergeFiles($blocks);
        foreach ($blocks as $i => &$b) {
            unset($b['h1']);
            $b = ['id' => self::blockId($legacy, $i, $b['type'])] + $b;
        }
        unset($b);

        $menuTitle = $this->navTitle[$legacy] ?? null;
        return [
            'title' => mb_substr($title, 0, 255),
            'menu_title' => ($menuTitle !== null && $menuTitle !== $title) ? mb_substr($menuTitle, 0, 255) : null,
            'meta' => $meta !== '' ? mb_substr($meta, 0, 500) : null,
            'blocks' => $blocks,
        ];
    }

    /** Modules de premier niveau sous $root (div.j-module sans ancêtre j-module intermédiaire). */
    private static function modules(\DOMXPath $xp, \DOMNode $root): array
    {
        $out = [];
        foreach ($xp->query('.//div[' . self::cls('j-module') . ']', $root) as $mod) {
            $p = $mod->parentNode;
            $nested = false;
            while ($p !== null && !$p->isSameNode($root)) {
                if ($p instanceof \DOMElement && preg_match('/(^|\s)j-module(\s|$)/', $p->getAttribute('class'))) {
                    $nested = true;
                    break;
                }
                $p = $p->parentNode;
            }
            if (!$nested) {
                $out[] = $mod;
            }
        }
        return $out;
    }

    private static function moduleType(\DOMElement $mod): string
    {
        foreach (preg_split('/\s+/', trim($mod->getAttribute('class'))) as $c) {
            if (str_starts_with($c, 'j-') && $c !== 'j-module') {
                return $c;
            }
        }
        return '';
    }

    private function readNav(\DOMXPath $xp): void
    {
        $q = '//ul[contains(@class, "j-nav-level-") or contains(@class, "cc-nav-level-")]';
        foreach ($xp->query($q) as $ul) {
            $pos = 0;
            foreach ($ul->childNodes as $li) {
                if (!$li instanceof \DOMElement || strtolower($li->tagName) !== 'li') {
                    continue;
                }
                $a = null;
                foreach ($li->childNodes as $c) {
                    if ($c instanceof \DOMElement && strtolower($c->tagName) === 'a') {
                        $a = $c;
                        break;
                    }
                }
                if ($a === null) {
                    continue;
                }
                $href = $a->getAttribute('href');
                if (!$this->isInternal($href)) {
                    continue;
                }
                $path = self::normPath($href);
                $this->navOrder[$path] ??= $pos;
                $label = $a->getAttribute('data-link-title') ?: self::text($a);
                if ($label !== '') {
                    $this->navTitle[$path] ??= $label;
                }
                $pos++;
            }
        }
    }

    private function isInternal(string $href): bool
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#' || preg_match('#^(mailto|tel|javascript):#i', $href)) {
            return false;
        }
        if (preg_match('#^(https?:)?//([^/]+)#i', $href, $m)) {
            $h = strtolower((string) preg_replace('/:\d+$/', '', $m[2]));
            $src = strtolower((string) parse_url($this->opt['source'], PHP_URL_HOST));
            return $h === $src || preg_replace('/^www\./', '', $h) === preg_replace('/^www\./', '', $src);
        }
        return $href[0] === '/';
    }

    /** Ajoute à $blocks le(s) bloc(s) correspondant au module Jimdo. */
    private function convertModule(\DOMXPath $xp, \DOMElement $mod, array &$blocks, array &$ctx): void
    {
        $type = self::moduleType($mod);
        $legacy = $ctx['legacy'];

        if ($type === 'j-hgrid' || $type === 'j-hgrid-col') {
            foreach (self::modules($xp, $mod) as $sub) {
                $this->convertModule($xp, $sub, $blocks, $ctx);
            }
            return;
        }

        // En-tête « Informations Transports » dans le contenu : on met de côté jusqu'au prochain titre/ligne
        if ($type === 'j-header') {
            $h = $xp->query('.//h1|.//h2|.//h3|.//h4', $mod)->item(0);
            $txt = self::text($h ?? $mod);
            if ($txt === '') {
                return;
            }
            if (preg_match('/^infos?(rmations?)?\s+(sur\s+les\s+)?transports?\b/iu', $txt) && empty($ctx['sidebar'])) {
                $ctx['skip'] = true;
                $ctx['transport'][] = ['type' => 'heading', 'data' => ['text' => $txt, 'level' => 2]];
                return;
            }
            $ctx['skip'] = false;
            $tag = $h ? strtolower($h->tagName) : 'h2';
            $blocks[] = [
                'type' => 'heading',
                'data' => ['text' => mb_substr($txt, 0, 300), 'level' => in_array($tag, ['h3', 'h4'], true) ? 3 : 2],
                'h1' => $tag === 'h1',
            ];
            return;
        }
        if ($type === 'j-hr') {
            $ctx['skip'] = false;
            $blocks[] = ['type' => 'separator', 'data' => new \stdClass()];
            return;
        }
        if ($ctx['skip']) {
            $target = &$ctx['transport'];
        } else {
            $target = &$blocks;
        }

        switch ($type) {
            case 'j-spacing':
                return;

            case 'j-text':
                $this->textBlocks($xp, $mod, $target, $legacy);
                return;

            case 'j-textWithImage':
                foreach ($xp->query('.//img', $mod) as $img) {
                    $this->imageBlock($xp, $img, $mod, $target, 'half');
                    $img->parentNode?->removeChild($img);
                }
                foreach (iterator_to_array($xp->query('.//figure|.//figcaption', $mod)) as $f) {
                    $f->parentNode?->removeChild($f);
                }
                $this->textBlocks($xp, $mod, $target, $legacy);
                return;

            case 'j-imageSubtitle':
            case 'j-image':
                $img = $xp->query('.//img', $mod)->item(0);
                if ($img instanceof \DOMElement) {
                    $this->imageBlock($xp, $img, $mod, $target, null);
                }
                return;

            case 'j-gallery':
            case 'j-sidebarGallery':
                $items = [];
                $seen = [];
                foreach ($xp->query('.//a[@href]|.//img', $mod) as $el) {
                    /** @var \DOMElement $el */
                    if (strtolower($el->tagName) === 'a') {
                        $src = $el->getAttribute('data-href') ?: $el->getAttribute('href');
                        $cap = $el->getAttribute('data-title') ?: $el->getAttribute('title');
                        $img = $xp->query('.//img', $el)->item(0);
                        if (!self::isJimdoImage($src) && $img instanceof \DOMElement) {
                            $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
                        }
                        if ($cap === '' && $img instanceof \DOMElement) {
                            $cap = $img->getAttribute('alt');
                        }
                    } else {
                        if ($el->parentNode instanceof \DOMElement && strtolower($el->parentNode->tagName) === 'a'
                            && self::isJimdoImage($el->parentNode->getAttribute('href'))) {
                            continue; // déjà traité via le lien
                        }
                        $src = $el->getAttribute('data-src') ?: $el->getAttribute('src');
                        $cap = $el->getAttribute('alt');
                    }
                    if (!self::isJimdoImage($src)) {
                        continue;
                    }
                    $orig = self::originalImageUrl($src);
                    if (isset($seen[$orig])) {
                        continue;
                    }
                    $seen[$orig] = true;
                    $cap = trim($cap);
                    $id = $this->imageMedia($src, $cap, $cap);
                    if ($id !== null) {
                        $items[] = ['media_id' => $id, 'caption' => mb_substr($cap, 0, 500)];
                    }
                }
                if ($items) {
                    $target[] = ['type' => 'gallery', 'data' => ['title' => '', 'items' => $items]];
                }
                return;

            case 'j-downloadDocument':
                $a = $xp->query('.//a[contains(@href, "/app/download/")]', $mod)->item(0);
                if (!$a instanceof \DOMElement) {
                    $this->warnings[] = "$legacy : module de téléchargement sans lien.";
                    return;
                }
                $url = $this->downloadUrl($a->getAttribute('href'));
                $label = self::text($xp->query('.//*[' . self::cls('cc-m-download-title') . ']', $mod)->item(0));
                $fname = self::nfc(urldecode(basename((string) parse_url($url, PHP_URL_PATH))));
                if ($label === '') {
                    $label = self::text($a) ?: pathinfo($fname, PATHINFO_FILENAME);
                }
                $size = self::text($xp->query('.//*[' . self::cls('cc-m-download-file-size') . ']', $mod)->item(0));
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if (in_array($ext, self::VIDEO_EXT, true)) {
                    $this->videoFound($legacy, $label, $url, $size, $target);
                    return;
                }
                $id = $this->media($url, 'file', $label, '', $fname);
                if ($id !== null) {
                    $target[] = ['type' => 'files', 'data' => ['title' => '', 'items' => [['media_id' => $id, 'label' => mb_substr($label, 0, 300)]], 'sort' => 'manual']];
                }
                return;

            case 'j-video':
            case 'j-htmlCode':
            case 'j-htmlcode':
                $found = false;
                foreach ($xp->query('.//iframe[@src]|.//iframe[@data-src]', $mod) as $if) {
                    $u = self::videoUrl($if->getAttribute('src') ?: $if->getAttribute('data-src'));
                    if ($u !== null) {
                        $target[] = ['type' => 'video', 'data' => ['url' => $u]];
                        $found = true;
                    }
                }
                foreach ($xp->query('.//video[@src]|.//source[@src]|.//a[@href]', $mod) as $v) {
                    $u = $v->getAttribute('src') ?: $v->getAttribute('href');
                    $path = (string) parse_url($u, PHP_URL_PATH);
                    if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::VIDEO_EXT, true)) {
                        $u = str_contains($u, '/app/download/') ? $this->downloadUrl($u) : $u;
                        $this->videoFound($legacy, $v->getAttribute('title') ?: basename($path), $u, '', $target);
                        $found = true;
                    }
                }
                if (!$found) {
                    $this->warnings[] = "$legacy : module $type sans vidéo reconnue, ignoré.";
                }
                return;

            case 'j-callToAction':
                $a = $xp->query('.//a[@href]', $mod)->item(0);
                if ($a instanceof \DOMElement) {
                    $target[] = ['type' => 'button', 'data' => ['label' => mb_substr(self::text($a), 0, 200), 'url' => $this->rewriteHref($a->getAttribute('href'), $legacy)]];
                }
                return;

            case 'j-formnew':
            case 'j-form':
            case 'j-contactForm':
            case 'j-contactform':
                $target[] = ['type' => 'contact', 'data' => ['intro' => '']];
                if ($legacy !== self::CONTACT_LEGACY) {
                    $this->info[] = "$legacy : formulaire Jimdo remplacé par le formulaire de contact du nouveau site.";
                }
                return;

            default:
                if (self::text($mod) !== '') {
                    $this->warnings[] = "$legacy : module « $type » converti en texte simple, à vérifier.";
                    $this->textBlocks($xp, $mod, $target, $legacy);
                }
                return;
        }
    }

    private function videoFound(string $legacy, string $label, string $url, string $size, array &$target): void
    {
        if ($this->opt['download-videos']) {
            $id = $this->media($url, 'file', $label);
            if ($id !== null) {
                $target[] = ['type' => 'video', 'data' => ['media_id' => $id]];
                return;
            }
        }
        $this->videos[] = ['page' => $legacy, 'label' => $label, 'url' => $url, 'size' => $size];
    }

    /** URL YouTube/Vimeo canonique depuis une URL d'iframe. */
    public static function videoUrl(string $src): ?string
    {
        $src = html_entity_decode(trim($src));
        if (str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        }
        if (preg_match('#youtu(?:be(?:-nocookie)?\.com/(?:embed/|watch\?v=|v/|shorts/)|\.be/)([A-Za-z0-9_-]{6,20})#', $src, $m)) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d{5,12})#', $src, $m)) {
            return 'https://vimeo.com/' . $m[1];
        }
        return null;
    }

    private function imageBlock(\DOMXPath $xp, \DOMElement $img, \DOMElement $mod, array &$target, ?string $width): void
    {
        $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
        if (!self::isJimdoImage($src)) {
            $this->warnings[] = "Image externe ignorée : $src";
            return;
        }
        $alt = trim($img->getAttribute('alt'));
        $cap = self::text($xp->query('.//figcaption|.//*[' . self::cls('cc-imagewrapper-caption') . ']', $mod)->item(0));
        $id = $this->imageMedia($src, $alt, $cap);
        if ($id === null) {
            return;
        }
        if ($width === null) {
            $w = (int) ($img->getAttribute('data-src-width') ?: $img->getAttribute('width'));
            if ($w === 0 && preg_match('#dimension=(\d+)x#', $src, $m)) {
                $w = (int) $m[1];
            }
            $width = ($w > 0 && $w < 400) ? 'half' : 'full';
        }
        $target[] = ['type' => 'image', 'data' => [
            'media_id' => $id, 'alt' => mb_substr($alt !== '' ? $alt : $cap, 0, 300), 'caption' => mb_substr($cap, 0, 500), 'width' => $width,
        ]];
    }

    /** Texte riche : images extraites en blocs, liens réécrits, HTML nettoyé. */
    private function textBlocks(\DOMXPath $xp, \DOMElement $mod, array &$target, string $legacy): void
    {
        $images = [];
        foreach (iterator_to_array($xp->query('.//img', $mod)) as $img) {
            /** @var \DOMElement $img */
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
            if (self::isJimdoImage($src)) {
                $images[] = $img;
            }
            $img->parentNode?->removeChild($img);
        }
        foreach ($xp->query('.//a[@href]', $mod) as $a) {
            /** @var \DOMElement $a */
            $a->setAttribute('href', $this->rewriteHref($a->getAttribute('href'), $legacy));
        }
        $html = Html::clean(self::inner($mod));
        $plain = trim(str_replace("\u{a0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($plain !== '') {
            $target[] = ['type' => 'text', 'data' => ['html' => $html]];
        }
        foreach ($images as $img) {
            $this->imageBlock($xp, $img, $mod, $target, 'half');
        }
    }

    /** Réécrit un lien de l'ancien site vers le nouveau (pages et fichiers). */
    public function rewriteHref(string $href, string $legacy = ''): string
    {
        $href = trim(html_entity_decode($href));
        if (!$this->isInternal($href)) {
            return $href;
        }
        $path = (string) preg_replace('#^(https?:)?//[^/]+#i', '', $href);
        if ($path === '') {
            $path = '/';
        }
        if (str_starts_with($path, '/app/download/')) {
            $url = $this->downloadUrl($path);
            $fname = self::nfc(urldecode(basename((string) parse_url($url, PHP_URL_PATH))));
            $id = $this->media($url, 'file', pathinfo($fname, PATHINFO_FILENAME), '', $fname);
            if ($id === null) {
                $this->warnings[] = "$legacy : lien vers un fichier introuvable ($href).";
                return $this->opt['source'] . $path;
            }
            return $this->mediaHref($id);
        }
        if (preg_match('#^/(app|j|protected)/#', $path)) {
            $this->warnings[] = "$legacy : lien technique Jimdo conservé tel quel ($href).";
            return $href;
        }
        $frag = '';
        if (($p = strpos($path, '#')) !== false) {
            $frag = substr($path, $p);
            $path = substr($path, 0, $p);
        }
        $norm = self::normPath($path);
        $new = $this->map[$norm] ?? self::newPath($norm);
        if (!isset($this->map[$norm])) {
            $this->warnings[] = "$legacy : lien vers une page inconnue ($norm), réécrit en $new.";
        }
        if (preg_match(self::PROTECTED_RE, $norm)) {
            $new = '/espace-membres';
        }
        $out = $new === '/' ? '/' : implode('/', array_map('rawurlencode', explode('/', $new)));
        return $out . $frag;
    }

    /** Fusionne les blocs « files » consécutifs (un module Jimdo par fichier). */
    private static function mergeFiles(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $last = count($out) - 1;
            if ($b['type'] === 'files' && $last >= 0 && $out[$last]['type'] === 'files') {
                foreach ($b['data']['items'] as $it) {
                    $out[$last]['data']['items'][] = $it;
                }
                continue;
            }
            $out[] = $b;
        }
        return $out;
    }

    // =================================================================== pages spéciales

    private function attachTransport(): void
    {
        if ($this->transport === null) {
            return;
        }
        if (!isset($this->parsed[self::TRANSPORT_LEGACY])) {
            if ($this->opt['only'] === null) {
                $this->warnings[] = 'Bloc « Informations transports » trouvé mais page Transports scolaires absente : non importé.';
            }
            return;
        }
        $title = 'Informations transports';
        $html = '';
        $extra = [];
        foreach ($this->transport['blocks'] as $b) {
            if ($b['type'] === 'heading') {
                $title = $b['data']['text'];
            } elseif ($b['type'] === 'text') {
                $html .= $b['data']['html'];
            } elseif (in_array($b['type'], ['image', 'files', 'gallery', 'button', 'video'], true)) {
                if ($b['type'] === 'image') {
                    $b['data']['width'] = 'half';
                }
                $extra[] = $b;
            }
        }
        $mb = &$this->parsed[self::TRANSPORT_LEGACY]['blocks'];
        $add = array_merge([['type' => 'separator', 'data' => new \stdClass()], ['type' => 'callout', 'data' => ['style' => 'info', 'title' => $title, 'html' => Html::clean($html)]]], $extra);
        $add = self::mergeFiles($add);
        $base = count($mb);
        foreach ($add as $i => $b) {
            $mb[] = ['id' => self::blockId(self::TRANSPORT_LEGACY . '#transport', $base + $i, $b['type'])] + $b;
        }
        unset($mb);
        $this->info[] = sprintf(
            'Bloc latéral « Informations transports » (vu sur %d page(s), repris de %s) importé une seule fois, en encadré au bas de la page Transports scolaires (%s).',
            $this->transportSeen, $this->transport['from'], $this->map[self::TRANSPORT_LEGACY]
        );
    }

    private function legalPage(): array
    {
        $l = self::LEGAL_LEGACY;
        $addr = str_replace("\n", '<br>', e(Settings::get('contact_address')));
        $blocks = [
            ['type' => 'callout', 'data' => ['style' => 'warning', 'title' => 'Modèle à compléter',
                'html' => '<p>Cette page est un modèle de mentions légales conforme à la nouvelle loi fédérale sur la protection des données (nLPD). Complétez les passages entre crochets, faites-la relire, puis publiez-la et supprimez cet encadré.</p>']],
            ['type' => 'heading', 'data' => ['text' => 'Éditeur du site', 'level' => 2]],
            ['type' => 'text', 'data' => ['html' => "<p>Association scolaire intercommunale du Jorat (ASIJ)<br>$addr<br>Courriel : [adresse e-mail]<br>Téléphone : [numéro]</p><p>Responsable de la publication : [nom, fonction]</p>"]],
            ['type' => 'heading', 'data' => ['text' => 'Hébergement', 'level' => 2]],
            ['type' => 'text', 'data' => ['html' => '<p>[Nom de l’hébergeur, adresse, pays]. Les données sont hébergées en [Suisse / pays].</p>']],
            ['type' => 'heading', 'data' => ['text' => 'Protection des données (nLPD)', 'level' => 2]],
            ['type' => 'text', 'data' => ['html' => '<p>Responsable du traitement : l’ASIJ, à l’adresse ci-dessus.</p>'
                . '<h3>Données traitées</h3><ul><li>Formulaire de contact : nom, adresse e-mail et message, utilisés uniquement pour répondre à votre demande et conservés au maximum [durée, p. ex. 12 mois].</li>'
                . '<li>Espace sécurisé : nom et adresse e-mail des membres, journal des connexions et des téléchargements (sécurité), conservés [durée].</li>'
                . '<li>Journaux techniques du serveur (adresse IP, date, page consultée), conservés [durée] par l’hébergeur.</li></ul>'
                . '<h3>Cookies</h3><p>Le site n’utilise qu’un cookie technique de session, nécessaire à la connexion à l’espace sécurisé. Aucun cookie publicitaire ni outil de mesure d’audience tiers.</p>'
                . '<h3>Communication à des tiers</h3><p>Les données ne sont ni vendues ni transmises à des tiers, sauf à l’hébergeur pour l’exploitation technique du site [et aux sous-traitants suivants : …]. Les vidéos intégrées (YouTube) ne sont chargées qu’en mode « sans cookie ».</p>'
                . '<h3>Vos droits</h3><p>Vous pouvez demander l’accès à vos données, leur rectification ou leur effacement en écrivant à [adresse e-mail] (art. 25 ss nLPD). Vous pouvez aussi vous adresser au Préposé fédéral à la protection des données et à la transparence (PFPDT) ou à l’autorité cantonale vaudoise compétente.</p>']],
            ['type' => 'heading', 'data' => ['text' => 'Droits d’auteur et responsabilité', 'level' => 2]],
            ['type' => 'text', 'data' => ['html' => '<p>Les textes, photos et documents de ce site appartiennent à l’ASIJ ou à leurs auteurs [crédits photos]. Toute reproduction nécessite une autorisation. Malgré le soin apporté, l’ASIJ ne peut garantir l’exactitude des informations publiées ni le contenu des sites externes vers lesquels renvoient des liens.</p>']],
        ];
        foreach ($blocks as $i => &$b) {
            if ($b['type'] === 'text' || $b['type'] === 'callout') {
                $b['data']['html'] = Html::clean($b['data']['html']);
            }
            $b = ['id' => self::blockId($l, $i, $b['type'])] + $b;
        }
        unset($b);
        $this->info[] = 'Mentions légales (/about/) : page brouillon /mentions-legales créée avec un modèle nLPD à compléter, masquée du menu.';
        return ['title' => 'Mentions légales', 'menu_title' => null, 'meta' => null, 'blocks' => $blocks,
            'status' => 'draft', 'show_in_menu' => 0, 'slug' => 'mentions-legales'];
    }

    private function createPrivateFolder(string $legacy): void
    {
        $this->stats['pages_skipped']++;
        $folder = Db::one('SELECT id FROM private_folders WHERE name = ? AND parent_id IS NULL', [self::PRIVATE_FOLDER]);
        $id = $folder ? (int) $folder['id'] : Db::insert('private_folders', [
            'parent_id' => null,
            'name' => self::PRIVATE_FOLDER,
            'description' => 'Documents réservés aux membres du Conseil intercommunal (anciennement zone protégée par mot de passe du site Jimdo). Fichiers à déposer manuellement : voir docs/MIGRATION.md.',
            'sort_order' => 0,
            'created_at' => Db::now(),
        ]);
        $gid = Db::value('SELECT id FROM user_groups WHERE slug = ?', ['conseil']);
        if ($gid === null) {
            $gid = Db::insert('user_groups', ['name' => 'Conseil intercommunal', 'slug' => 'conseil', 'description' => 'Membres du Conseil intercommunal']);
        }
        if (!Db::one('SELECT folder_id FROM private_folder_groups WHERE folder_id = ? AND group_id = ?', [$id, (int) $gid])) {
            Db::insert('private_folder_groups', ['folder_id' => $id, 'group_id' => (int) $gid]);
        }
        $this->info[] = "$legacy : page protégée par mot de passe NON importée comme page publique. "
            . "Dossier « " . self::PRIVATE_FOLDER . " » (n° $id) " . ($folder ? 'déjà présent' : 'créé')
            . " dans l’espace sécurisé, accessible au groupe Conseil intercommunal. Les documents sont à récupérer à la main (docs/MIGRATION.md).";
    }

    // =================================================================== écriture des pages

    private function writePages(): void
    {
        $order = array_keys($this->parsed);
        $pos = array_flip(array_keys($this->map));
        usort($order, fn($a, $b) => [substr_count($a, '/'), $pos[$a] ?? 0] <=> [substr_count($b, '/'), $pos[$b] ?? 0]);
        $useSanitize = !$this->opt['dry-run'] && class_exists('App\\Pages') && method_exists('App\\Pages', 'sanitizeBlocks');

        foreach ($order as $legacy) {
            $d = $this->parsed[$legacy];
            $parentId = null;
            $parentPath = '';
            for ($pl = self::parentLegacy($legacy); $pl !== null; $pl = self::parentLegacy($pl)) {
                $pr = Db::one('SELECT id, path FROM pages WHERE legacy_url = ?', [$pl]);
                if ($pr) {
                    $parentId = (int) $pr['id'];
                    $parentPath = rtrim((string) $pr['path'], '/');
                    break;
                }
                if (!preg_match(self::PROTECTED_RE, $pl)) {
                    $this->warnings[] = "$legacy : page parente $pl absente, page rattachée plus haut.";
                }
            }
            if ($legacy === '/') {
                $slug = '';
            } else {
                $segs = explode('/', trim($legacy, '/'));
                $slug = $d['slug'] ?? (self::slugify((string) end($segs)) ?: 'page');
            }
            $blocks = $d['blocks'];
            if ($useSanitize) {
                $blocks = \App\Pages::sanitizeBlocks(json_decode(json_encode($blocks), true));
            }
            $json = json_encode(array_values($blocks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $row = [
                'parent_id' => $parentId,
                'slug' => $slug,
                'title' => $d['title'],
                'menu_title' => $d['menu_title'],
                'meta_description' => $d['meta'],
                'blocks' => $json,
                'status' => $d['status'] ?? 'published',
                'show_in_menu' => $d['show_in_menu'] ?? 1,
                'sort_order' => $this->navOrder[$legacy] ?? (100 + ($pos[$legacy] ?? 0)),
                'legacy_url' => $legacy,
            ];
            $existing = Db::one('SELECT * FROM pages WHERE legacy_url = ?', [$legacy]);
            $path = $slug === '' ? ($parentPath === '' ? '/' : $parentPath) : $parentPath . '/' . $slug;
            if ($existing) {
                if ($existing['updated_by'] !== null && !$this->opt['force']) {
                    $this->stats['pages_skipped']++;
                    $this->pageLog[] = [$legacy, (string) $existing['path'], 'conservée (modifiée dans l’administration)'];
                    continue;
                }
                $path = self::freePath($path, (int) $existing['id']);
                $row['path'] = $path;
                $diff = false;
                foreach ($row as $k => $v) {
                    if ((string) $existing[$k] !== (string) $v) {
                        $diff = true;
                    }
                }
                if ($diff) {
                    Db::update('pages', $row + ['updated_at' => Db::now(), 'updated_by' => null], 'id = :id', ['id' => (int) $existing['id']]);
                    $this->stats['pages_updated']++;
                    $this->pageLog[] = [$legacy, $path, 'mise à jour'];
                } else {
                    $this->stats['pages_unchanged']++;
                    $this->pageLog[] = [$legacy, $path, 'inchangée'];
                }
            } else {
                $row['path'] = self::freePath($path, null);
                if ($row['path'] !== $path) {
                    $this->warnings[] = "$legacy : adresse $path déjà prise, page créée en {$row['path']}.";
                }
                if ($row['path'] !== $path && $slug !== '') {
                    $row['slug'] = basename($row['path']);
                }
                Db::insert('pages', $row + ['updated_at' => Db::now(), 'updated_by' => null]);
                $this->stats['pages_created']++;
                $this->pageLog[] = [$legacy, $row['path'], 'créée' . ($row['status'] === 'draft' ? ' (brouillon)' : '')];
            }
            if (!$d['blocks']) {
                $this->warnings[] = "$legacy : page sans contenu (à compléter ou supprimer).";
            }
        }
    }

    private static function freePath(string $path, ?int $exceptId): string
    {
        $try = $path;
        $n = 2;
        while (($id = Db::value('SELECT id FROM pages WHERE path = ?', [$try])) !== null && (int) $id !== (int) $exceptId) {
            $try = ($path === '/' ? '/accueil' : $path) . '-' . $n++;
        }
        return $try;
    }

    // =================================================================== export SQL (sans SSH)

    private static function sqlValue(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || (is_string($v) && preg_match('/^-?\d{1,18}$/', $v) && $v === (string) (int) $v)) {
            return (string) $v;
        }
        return "'" . str_replace(['\\', "'", "\0", "\n", "\r", "\x1a"], ['\\\\', "\\'", '\\0', '\\n', '\\r', '\\Z'], (string) $v) . "'";
    }

    private function exportSql(string $file): void
    {
        $out = "-- Export de l'import Jimdo (" . date('Y-m-d H:i') . ")\n-- À importer avec phpMyAdmin sur un site fraîchement installé (tables pages et media vides).\nSET NAMES utf8mb4;\n";
        foreach (['media' => 'SELECT * FROM media ORDER BY id', 'pages' => 'SELECT * FROM pages ORDER BY id'] as $t => $sql) {
            foreach (Db::all($sql) as $r) {
                $out .= "INSERT INTO $t (" . implode(', ', array_keys($r)) . ') VALUES ('
                    . implode(', ', array_map([self::class, 'sqlValue'], array_values($r))) . ");\n";
            }
        }
        foreach (Db::all('SELECT * FROM private_folders WHERE name = ?', [self::PRIVATE_FOLDER]) as $f) {
            $out .= 'INSERT INTO private_folders (name, description, sort_order, created_at) VALUES ('
                . implode(', ', array_map([self::class, 'sqlValue'], [$f['name'], $f['description'], $f['sort_order'], $f['created_at']])) . ");\n"
                . "INSERT INTO private_folder_groups (folder_id, group_id) SELECT LAST_INSERT_ID(), id FROM user_groups WHERE slug = 'conseil';\n";
        }
        $logo = Settings::get('logo_media_id');
        if ($logo !== '') {
            $out .= 'REPLACE INTO settings (name, value) VALUES (\'logo_media_id\', ' . self::sqlValue($logo) . ");\n";
        }
        if (@file_put_contents($file, $out) === false) {
            $this->errors[] = "Export SQL impossible : $file";
        } else {
            $this->info[] = "Export SQL écrit : $file";
        }
    }

    // =================================================================== rapport

    private function writeReport(): void
    {
        $s = $this->stats;
        $dry = $this->opt['dry-run'] ? ' (SIMULATION : rien n’a été enregistré)' : '';
        $md = "# Rapport d'import du site Jimdo$dry\n\n";
        $md .= '- Date : ' . date('d.m.Y H:i') . "\n";
        $md .= '- Source : ' . ($this->opt['from-dir'] ? 'dossier ' . $this->opt['from-dir'] : $this->opt['source']) . "\n";
        $md .= "- Durée : {$s['duration']} s, requêtes réseau : {$s['requests']}\n\n";
        $md .= "## Résumé\n\n| | Nombre |\n|---|---|\n";
        $md .= "| Pages créées | {$s['pages_created']} |\n| Pages mises à jour | {$s['pages_updated']} |\n| Pages inchangées | {$s['pages_unchanged']} |\n| Pages non importées | {$s['pages_skipped']} |\n";
        $md .= "| Médias créés (fichiers et images) | {$s['media_created']} |\n| Médias déjà présents | {$s['media_reused']} |\n| Doublons évités (contenu identique) | {$s['media_dedup']} |\n";
        $md .= '| Vidéos à publier | ' . count($this->videos) . " |\n| Erreurs | " . count($this->errors) . " |\n\n";

        $md .= "## Erreurs\n\n" . ($this->errors ? implode('', array_map(fn($e) => "- $e\n", $this->errors)) : "Aucune.\n") . "\n";
        $md .= "## Points à connaître\n\n" . ($this->info ? implode('', array_map(fn($e) => "- $e\n", array_unique($this->info))) : "Aucun.\n") . "\n";

        $md .= "## Vidéos à publier sur YouTube (non répertorié) ou Vimeo\n\n";
        if ($this->videos) {
            $md .= "Ajoutez ensuite un bloc « Vidéo » sur la page indiquée avec l’adresse YouTube obtenue (voir docs/MIGRATION.md).\n\n| Page | Vidéo | Taille | Adresse d’origine |\n|---|---|---|---|\n";
            foreach ($this->videos as $v) {
                $md .= '| ' . ($this->map[$v['page']] ?? $v['page']) . ' | ' . str_replace('|', '/', $v['label']) . ' | ' . $v['size'] . ' | ' . $v['url'] . " |\n";
            }
        } else {
            $md .= "Aucune vidéo détectée dans les pages.";
            if (!$this->opt['only']) {
                $md .= " L’inventaire signale 48 vidéos de chantier sur la page Collège Gustave-Roud (Carrouge) : à récupérer depuis l’administration Jimdo.";
            }
            $md .= "\n";
        }
        $md .= "\n## Pages (ancienne adresse → nouvelle adresse)\n\nLes anciennes adresses publiées redirigent automatiquement (301) vers les nouvelles.\n\n| Ancienne adresse | Nouvelle adresse | État |\n|---|---|---|\n";
        foreach ($this->pageLog as [$old, $new, $st]) {
            $md .= "| $old | $new | $st |\n";
        }
        $md .= "\n## Avertissements\n\n" . ($this->warnings ? implode('', array_map(fn($e) => "- $e\n", array_unique($this->warnings))) : "Aucun.\n");
        if ($this->dedupLog) {
            $md .= "\n## Fichiers en double (un seul exemplaire conservé)\n\n" . implode('', array_map(fn($e) => "- $e\n", $this->dedupLog));
        }
        $f = (string) $this->opt['report'];
        if ($f !== '') {
            @mkdir(dirname($f), 0775, true);
            if (@file_put_contents($f, $md) === false) {
                fwrite(STDERR, "Rapport impossible à écrire : $f\n");
            }
        }
        $this->stats['report'] = $md;
    }
}

// =================================================================== ligne de commande

function import_jimdo_main(array $argv): int
{
    $opt = [];
    $map = ['skip-videos' => ['download-videos', false]];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--help' || $a === '-h') {
            $doc = (string) file_get_contents(__FILE__);
            preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
            echo preg_replace('/^\s*\* ?/m', '', $m[1] ?? ''), "\n";
            return 0;
        }
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
            fwrite(STDERR, "Argument inconnu : $a (voir --help)\n");
            return 2;
        }
        [$k, $v] = [$m[1], $m[2] ?? null];
        if (isset($map[$k])) {
            [$k, $v] = $map[$k];
        } elseif ($v === null) {
            $v = true;
        }
        $known = ['source', 'manifest', 'cache', 'delay', 'cdn-delay', 'dry-run', 'only', 'download-videos', 'write-logo', 'force',
            'report', 'export-sql', 'media-dir', 'from-dir', 'files-dir', 'quiet'];
        if (!in_array($k, $known, true)) {
            fwrite(STDERR, "Option inconnue : --$k (voir --help)\n");
            return 2;
        }
        if (in_array($k, ['delay', 'cdn-delay'], true)) {
            $v = max(0.0, (float) $v);
        }
        if (in_array($k, ['manifest', 'cache', 'report', 'export-sql', 'media-dir', 'from-dir', 'files-dir'], true) && is_string($v)
            && $v !== '' && $v[0] !== '/' && !file_exists($v) && file_exists(ROOT . '/' . $v)) {
            $v = ROOT . '/' . $v;
        } elseif (in_array($k, ['cache', 'report', 'export-sql'], true) && is_string($v) && $v !== '' && $v[0] !== '/') {
            $v = ROOT . '/' . $v;
        }
        $opt[$k] = $v;
    }
    $imp = new JimdoImporter($opt);
    try {
        $s = $imp->run();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
        return 1;
    }
    echo "\nPages : {$s['pages_created']} créées, {$s['pages_updated']} mises à jour, {$s['pages_unchanged']} inchangées, {$s['pages_skipped']} non importées.\n";
    echo "Médias : {$s['media_created']} créés, {$s['media_reused']} déjà présents, {$s['media_dedup']} doublons évités.\n";
    echo 'Vidéos à publier : ' . count($imp->videos) . ', erreurs : ' . count($imp->errors) . ', avertissements : ' . count(array_unique($imp->warnings)) . "\n";
    if ($imp->opt['report']) {
        echo 'Rapport : ' . $imp->opt['report'] . "\n";
    }
    return $imp->errors ? 1 : 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    exit(import_jimdo_main($_SERVER['argv']));
}
