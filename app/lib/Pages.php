<?php
declare(strict_types=1);

namespace App;

/**
 * Arbre des pages: chemins, slugs, réorganisation et validation des blocs.
 *
 * Convention: la page d'accueil est la page racine dont le slug est vide (path "/").
 * Les autres pages ont un path "/parent/enfant" calculé à partir des slugs.
 */
final class Pages
{
    /** Types de blocs connus (voir docs/ARCHITECTURE.md). */
    public const BLOCK_TYPES = [
        'heading', 'text', 'image', 'gallery', 'files', 'video',
        'callout', 'button', 'contact', 'columns', 'people', 'separator', 'table',
    ];

    public const MAX_BLOCKS = 300;
    /** Rubrique des actualités: ses sous-pages sont les actualités. */
    public const NEWS_PATH = '/actualites';
    /** Page cachée dont le contenu s'affiche dans l'encadré « Informations importantes » de l'accueil. */
    public const INFOS_PATH = '/informations-importantes';

    /** Translittère et normalise un texte en slug d'URL (minuscules, chiffres, tirets). */
    public static function slugify(string $s): string
    {
        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', '’' => '-', "'" => '-', '&' => '-et-',
        ];
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, $map);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t)) {
                $s = $t;
            }
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '';
        $s = trim($s, '-');
        if (strlen($s) > 120) {
            $s = rtrim(substr($s, 0, 120), '-');
        }
        return $s;
    }

    /** Chemin complet d'une page à partir de son parent et de son slug. */
    public static function computePath(?int $parentId, string $slug): string
    {
        $base = '';
        if ($parentId) {
            $p = Db::value('SELECT path FROM pages WHERE id = ?', [$parentId]);
            if ($p === null) {
                throw new \InvalidArgumentException('Page parente introuvable');
            }
            $base = rtrim((string) $p, '/');
        }
        if ($slug === '') {
            return $base === '' ? '/' : $base;
        }
        return $base . '/' . $slug;
    }

    /** Un path est-il déjà utilisé (par une autre page que $exceptId) ? */
    public static function pathTaken(string $path, ?int $exceptId = null): bool
    {
        $id = Db::value('SELECT id FROM pages WHERE path = ?', [$path]);
        return $id !== null && (int) $id !== (int) $exceptId;
    }

    /** Retourne un slug libre sous ce parent (ajoute -2, -3… si nécessaire). */
    public static function uniqueSlug(?int $parentId, string $slug, ?int $exceptId = null): string
    {
        $slug = $slug === '' ? 'page' : $slug;
        $try = $slug;
        $n = 2;
        while (self::pathTaken(self::computePath($parentId, $try), $exceptId)) {
            $try = $slug . '-' . $n++;
        }
        return $try;
    }

    /** @return array<int,array<string,mixed>> toutes les pages (sans blocs), triées. */
    public static function all(): array
    {
        return Db::all(
            'SELECT p.id, p.parent_id, p.slug, p.path, p.title, p.menu_title, p.status, p.show_in_menu,
                    p.sort_order, p.updated_at, p.updated_by, u.name AS updated_by_name
             FROM pages p LEFT JOIN users u ON u.id = p.updated_by
             ORDER BY p.sort_order, p.title'
        );
    }

    /**
     * Arbre des pages: liste de nœuds avec la clé 'children'.
     * @param bool $publishedOnly ne garder que les pages publiées (et leurs descendants publiés)
     * @param bool $menuOnly ne garder que les pages affichées dans le menu
     */
    public static function tree(bool $publishedOnly = false, bool $menuOnly = false): array
    {
        $byParent = [];
        foreach (self::all() as $p) {
            if ($publishedOnly && $p['status'] !== 'published') {
                continue;
            }
            if ($menuOnly && (int) $p['show_in_menu'] !== 1) {
                continue;
            }
            $byParent[(int) ($p['parent_id'] ?? 0)][] = $p;
        }
        $build = function (int $parent, int $depth, array $seen) use (&$build, $byParent): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $p) {
                $id = (int) $p['id'];
                if (isset($seen[$id])) {
                    continue; // protection contre des données corrompues
                }
                $p['depth'] = $depth;
                $p['children'] = $build($id, $depth + 1, $seen + [$id => true]);
                $out[] = $p;
            }
            return $out;
        };
        return $build(0, 0, []);
    }

    /**
     * Liste à plat (ordre de l'arbre) avec 'depth', pour les listes déroulantes.
     * @param int|null $exclude exclut cette page et ses descendants (choix d'un parent)
     */
    public static function flat(?int $exclude = null): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out, $exclude): void {
            foreach ($nodes as $n) {
                if ($exclude !== null && (int) $n['id'] === $exclude) {
                    continue;
                }
                $children = $n['children'];
                unset($n['children']);
                $out[] = $n;
                $walk($children);
            }
        };
        $walk(self::tree());
        return $out;
    }

    /** @return int[] ids de tous les descendants d'une page */
    public static function descendantIds(int $id): array
    {
        $out = [];
        $queue = [$id];
        while ($queue) {
            $cur = array_shift($queue);
            foreach (Db::all('SELECT id FROM pages WHERE parent_id = ?', [$cur]) as $r) {
                $cid = (int) $r['id'];
                if (!in_array($cid, $out, true) && $cid !== $id) {
                    $out[] = $cid;
                    $queue[] = $cid;
                }
            }
        }
        return $out;
    }

    /** Placer $id sous $newParent créerait-il un cycle ? */
    public static function wouldCycle(int $id, ?int $newParent): bool
    {
        if (!$newParent) {
            return false;
        }
        return $newParent === $id || in_array($newParent, self::descendantIds($id), true);
    }

    /**
     * Recalcule le path des descendants de $id (après changement de slug ou de parent).
     * En cas de collision, le slug de l'enfant reçoit un suffixe.
     */
    public static function refreshDescendantPaths(int $id): void
    {
        $parentPath = (string) Db::value('SELECT path FROM pages WHERE id = ?', [$id]);
        $base = rtrim($parentPath, '/');
        foreach (Db::all('SELECT id, slug FROM pages WHERE parent_id = ? ORDER BY sort_order, id', [$id]) as $c) {
            $cid = (int) $c['id'];
            $slug = $c['slug'] !== '' ? (string) $c['slug'] : 'page';
            $path = $base . '/' . $slug;
            if (self::pathTaken($path, $cid)) {
                $slug = self::uniqueSlug($id, $slug, $cid);
                $path = $base . '/' . $slug;
            }
            Db::update('pages', ['slug' => $slug, 'path' => $path], 'id = :id', ['id' => $cid]);
            self::refreshDescendantPaths($cid);
        }
    }

    /**
     * Déplace une page (parent + position) et met à jour les paths.
     * Lève InvalidArgumentException en cas de cycle.
     */
    public static function move(int $id, ?int $parentId, int $sortOrder): void
    {
        if (self::wouldCycle($id, $parentId)) {
            throw new \InvalidArgumentException('Une page ne peut pas être placée sous elle-même.');
        }
        $p = Db::one('SELECT id, slug, parent_id FROM pages WHERE id = ?', [$id]);
        if (!$p) {
            throw new \InvalidArgumentException('Page introuvable.');
        }
        $slug = (string) $p['slug'];
        if ($slug === '' && $parentId) {
            $slug = 'accueil'; // l'accueil déplacé sous une autre page devient une page normale
        }
        $samePlace = (int) ($p['parent_id'] ?? 0) === (int) ($parentId ?? 0) && $slug === $p['slug'];
        $data = ['parent_id' => $parentId ?: null, 'sort_order' => $sortOrder];
        if (!$samePlace) {
            $slug = $slug === '' ? '' : self::uniqueSlug($parentId, $slug, $id);
            $data['slug'] = $slug;
            $data['path'] = self::computePath($parentId, $slug);
        }
        Db::update('pages', $data, 'id = :id', ['id' => $id]);
        if (!$samePlace) {
            self::refreshDescendantPaths($id);
        }
    }

    /**
     * Applique un nouvel ordre complet: [[id, parent_id|null, sort_order], ...].
     * Vérifie l'existence des pages et l'absence de cycle, puis recalcule tous les paths.
     * Lève InvalidArgumentException si l'ordre est invalide.
     */
    public static function applyOrder(array $items): void
    {
        $current = [];
        foreach (Db::all('SELECT id, parent_id, sort_order, slug FROM pages') as $r) {
            $current[(int) $r['id']] = ['parent' => $r['parent_id'] === null ? null : (int) $r['parent_id'], 'sort' => (int) $r['sort_order'], 'slug' => (string) $r['slug']];
        }
        $next = $current;
        foreach ($items as $it) {
            $id = (int) ($it['id'] ?? 0);
            $parent = isset($it['parent_id']) && $it['parent_id'] !== null && $it['parent_id'] !== '' ? (int) $it['parent_id'] : null;
            if (!isset($current[$id]) || ($parent !== null && !isset($current[$parent]))) {
                throw new \InvalidArgumentException('Page introuvable dans le nouvel ordre.');
            }
            $next[$id]['parent'] = $parent;
            $next[$id]['sort'] = (int) ($it['sort_order'] ?? 0);
        }
        // détection de cycle
        foreach (array_keys($next) as $id) {
            $seen = [];
            $cur = $id;
            while ($cur !== null) {
                if (isset($seen[$cur])) {
                    throw new \InvalidArgumentException('Une page ne peut pas être placée sous elle-même.');
                }
                $seen[$cur] = true;
                $cur = $next[$cur]['parent'];
            }
        }
        Db::transaction(function () use ($next) {
            foreach ($next as $id => $n) {
                $slug = $n['slug'];
                if ($slug === '' && $n['parent'] !== null) {
                    $slug = 'accueil';
                }
                Db::update('pages', ['parent_id' => $n['parent'], 'sort_order' => $n['sort'], 'slug' => $slug, 'path' => '#tmp-' . $id], 'id = :id', ['id' => $id]);
            }
            self::rebuildPaths();
        });
    }

    /** Recalcule le path de toutes les pages depuis les racines. */
    public static function rebuildPaths(): void
    {
        foreach (Db::all('SELECT id, slug FROM pages WHERE parent_id IS NULL ORDER BY sort_order, id') as $r) {
            $id = (int) $r['id'];
            $slug = (string) $r['slug'];
            $path = $slug === '' ? '/' : '/' . $slug;
            if (self::pathTaken($path, $id)) {
                $slug = self::uniqueSlug(null, $slug === '' ? 'accueil' : $slug, $id);
                $path = '/' . $slug;
            }
            Db::update('pages', ['slug' => $slug, 'path' => $path], 'id = :id', ['id' => $id]);
            self::refreshDescendantPaths($id);
        }
    }

    // ------------------------------------------------------------------ blocs

    /** Génère un identifiant de bloc. */
    public static function newBlockId(): string
    {
        return 'b_' . bin2hex(random_bytes(5));
    }

    /**
     * Valide et nettoie une liste de blocs reçue de l'éditeur (JSON décodé ou chaîne JSON).
     * Types inconnus ignorés, HTML nettoyé, URLs vérifiées, media_id contrôlés, ids régénérés si invalides.
     * @return array<int,array{id:string,type:string,data:array}>
     */
    public static function sanitizeBlocks(mixed $blocks): array
    {
        if (is_string($blocks)) {
            $blocks = $blocks === '' ? [] : json_decode($blocks, true);
        }
        if (!is_array($blocks)) {
            return [];
        }
        $blocks = array_slice(array_values(array_filter($blocks, 'is_array')), 0, self::MAX_BLOCKS);

        // ids de médias existants (une seule requête)
        $wanted = [];
        array_walk_recursive($blocks, function ($v, $k) use (&$wanted) {
            if ($k === 'media_id' && (is_int($v) || (is_string($v) && ctype_digit($v)))) {
                $wanted[(int) $v] = true;
            }
        });
        $media = [];
        if ($wanted) {
            $ids = array_keys($wanted);
            $rows = Db::all('SELECT id, kind, mime FROM media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            foreach ($rows as $r) {
                $media[(int) $r['id']] = $r;
            }
        }

        $out = [];
        $seenIds = [];
        foreach ($blocks as $b) {
            $type = is_string($b['type'] ?? null) ? $b['type'] : '';
            if (!in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }
            $data = is_array($b['data'] ?? null) ? $b['data'] : [];
            $id = is_string($b['id'] ?? null) ? $b['id'] : '';
            if (!preg_match('/^b_[a-z0-9_-]{3,32}$/i', $id) || isset($seenIds[$id])) {
                $id = self::newBlockId();
            }
            $seenIds[$id] = true;
            $out[] = ['id' => $id, 'type' => $type, 'data' => self::sanitizeData($type, $data, $media)];
        }
        return $out;
    }

    private static function str(mixed $v, int $max = 500): string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            return '';
        }
        $s = trim(strip_tags((string) $v));
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return mb_substr($s, 0, $max);
    }

    private static function text(mixed $v, int $max = 2000): string
    {
        // texte brut multi-lignes
        if (!is_string($v)) {
            return '';
        }
        $s = str_replace("\r\n", "\n", trim(strip_tags($v)));
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return mb_substr($s, 0, $max);
    }

    private static function html(mixed $v): string
    {
        return is_string($v) ? Html::clean($v) : '';
    }

    private static function url(mixed $v): string
    {
        $u = self::str($v, 1000);
        return ($u !== '' && Html::safeUrl($u)) ? $u : '';
    }

    private static function mediaId(mixed $v, array $media, ?string $kind = null): ?int
    {
        if (!(is_int($v) || (is_string($v) && ctype_digit($v)))) {
            return null;
        }
        $id = (int) $v;
        if (!isset($media[$id])) {
            return null;
        }
        if ($kind !== null && $media[$id]['kind'] !== $kind) {
            return null;
        }
        return $id;
    }

    private static function sanitizeData(string $type, array $d, array $media): array
    {
        switch ($type) {
            case 'heading':
                return ['text' => self::str($d['text'] ?? '', 300), 'level' => (int) ($d['level'] ?? 2) === 3 ? 3 : 2];
            case 'text':
                return ['html' => self::html($d['html'] ?? '')];
            case 'image':
                return [
                    'media_id' => self::mediaId($d['media_id'] ?? null, $media, 'image'),
                    'alt' => self::str($d['alt'] ?? '', 300),
                    'caption' => self::str($d['caption'] ?? '', 500),
                    'width' => ($d['width'] ?? '') === 'half' ? 'half' : 'full',
                ];
            case 'gallery':
                $items = [];
                foreach (is_array($d['items'] ?? null) ? $d['items'] : [] as $it) {
                    $mid = is_array($it) ? self::mediaId($it['media_id'] ?? null, $media, 'image') : null;
                    if ($mid) {
                        $items[] = ['media_id' => $mid, 'caption' => self::str($it['caption'] ?? '', 500)];
                    }
                }
                return ['title' => self::str($d['title'] ?? '', 300), 'items' => array_slice($items, 0, 500)];
            case 'files':
                $items = [];
                foreach (is_array($d['items'] ?? null) ? $d['items'] : [] as $it) {
                    $mid = is_array($it) ? self::mediaId($it['media_id'] ?? null, $media) : null;
                    if ($mid) {
                        $items[] = ['media_id' => $mid, 'label' => self::str($it['label'] ?? '', 300)];
                    }
                }
                return [
                    'title' => self::str($d['title'] ?? '', 300),
                    'items' => array_slice($items, 0, 500),
                    'sort' => ($d['sort'] ?? '') === 'name_desc' ? 'name_desc' : 'manual',
                    'collapsed' => !empty($d['collapsed']),
                ];
            case 'video':
                $mid = self::mediaId($d['media_id'] ?? null, $media);
                if ($mid && !str_starts_with((string) $media[$mid]['mime'], 'video/')) {
                    $mid = null;
                }
                $url = self::url($d['url'] ?? '');
                if ($url !== '' && !preg_match('#^https://#i', $url)) {
                    $url = '';
                }
                return $mid ? ['media_id' => $mid] : ['url' => $url];
            case 'callout':
                return [
                    'style' => ($d['style'] ?? '') === 'warning' ? 'warning' : 'info',
                    'title' => self::str($d['title'] ?? '', 300),
                    'html' => self::html($d['html'] ?? ''),
                ];
            case 'button':
                return ['label' => self::str($d['label'] ?? '', 200), 'url' => self::url($d['url'] ?? '')];
            case 'contact':
                return ['intro' => self::text($d['intro'] ?? '', 2000)];
            case 'columns':
                return ['left' => self::html($d['left'] ?? ''), 'right' => self::html($d['right'] ?? '')];
            case 'people':
                $items = [];
                foreach (is_array($d['items'] ?? null) ? $d['items'] : [] as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $row = [
                        'name' => self::str($it['name'] ?? '', 200),
                        'role' => self::str($it['role'] ?? '', 200),
                        'commune' => self::str($it['commune'] ?? '', 200),
                    ];
                    if ($row['name'] !== '' || $row['role'] !== '' || $row['commune'] !== '') {
                        $items[] = $row;
                    }
                }
                return ['items' => array_slice($items, 0, 300)];
            case 'table':
                $rows = [];
                foreach (array_slice(is_array($d['rows'] ?? null) ? $d['rows'] : [], 0, 300) as $r) {
                    if (is_array($r)) {
                        $rows[] = array_map(fn($c) => self::text(is_scalar($c) ? (string) $c : '', 2000), array_slice(array_values($r), 0, 8));
                    }
                }
                $n = $rows ? max(array_map('count', $rows)) : 0;
                foreach ($rows as &$r) {
                    $r = array_pad($r, $n, '');
                }
                unset($r);
                return ['head' => ($d['head'] ?? true) !== false, 'rows' => $rows];
            case 'separator':
            default:
                return [];
        }
    }

    /** Ids des pages dont les blocs utilisent ce média. @return array<int,array{id:int,title:string,path:string}> */
    public static function usingMedia(int $mediaId): array
    {
        $rows = Db::all(
            'SELECT id, title, path, blocks FROM pages WHERE blocks LIKE ? OR blocks LIKE ?',
            ['%"media_id":' . $mediaId . ',%', '%"media_id":' . $mediaId . '}%']
        );
        $out = [];
        foreach ($rows as $r) {
            $found = false;
            $blocks = json_decode((string) $r['blocks'], true);
            if (is_array($blocks)) {
                array_walk_recursive($blocks, function ($v, $k) use ($mediaId, &$found) {
                    if ($k === 'media_id' && (int) $v === $mediaId) {
                        $found = true;
                    }
                });
            }
            if ($found) {
                $out[] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'path' => (string) $r['path']];
            }
        }
        return $out;
    }
}
