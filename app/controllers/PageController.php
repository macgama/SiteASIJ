<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Blocks;
use App\Controller;
use App\Db;

/** Pages publiques, redirections des anciennes URL Jimdo, plan du site et sitemap.xml. */
final class PageController extends Controller
{
    /** Chemins servis par des gabarits intégrés si aucune page ne les remplace. */
    public const SITEMAP_PATH = '/plan-du-site';
    public const LEGAL_PATH = '/mentions-legales';

    /** @var array<int,array<string,mixed>>|null */
    private static ?array $menu = null;

    public function show(string $path = ''): void
    {
        $path = self::normalizePath($path);

        $page = Db::one("SELECT * FROM pages WHERE path = ? AND status = 'published'", [$path]);
        $isDraft = false;
        if (!$page && Auth::isAdmin()) {
            $page = Db::one('SELECT * FROM pages WHERE path = ?', [$path]);
            $isDraft = $page !== null;
        }

        if (!$page) {
            if ($target = self::findLegacy($path)) {
                redirect(self::href($target), 301);
            }
            if ($path === self::SITEMAP_PATH) {
                $this->view('public/sitemap', ['tree' => self::pageTree(false)]);
                return;
            }
            if ($path === self::LEGAL_PATH) {
                $this->view('public/legal');
                return;
            }
            $this->notFound("La page demandée n'existe pas ou a été déplacée.");
            return;
        }

        if ($isDraft) {
            header('X-Robots-Tag: noindex, nofollow');
            header('Cache-Control: private, no-store');
        }

        $blocks = Blocks::decode((string) $page['blocks']);
        $children = Db::all(
            "SELECT id, path, title, menu_title, meta_description FROM pages
             WHERE parent_id = ? AND status = 'published' ORDER BY sort_order, title",
            [(int) $page['id']]
        );

        $this->view('public/page', [
            'page' => $page,
            'title' => $path === '/' ? '' : (string) $page['title'],
            'metaDescription' => (string) ($page['meta_description'] ?? ''),
            'blocksHtml' => Blocks::render($blocks),
            'children' => $children,
            'breadcrumbs' => self::breadcrumbs($page),
            'isDraft' => $isDraft,
            'currentPath' => $path,
        ]);
    }

    public function sitemap(): void
    {
        $pages = Db::all("SELECT path, updated_at FROM pages WHERE status = 'published' ORDER BY path");
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($pages as $p) {
            $loc = url(self::href((string) $p['path']));
            echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if (!empty($p['updated_at'])) {
                echo '<lastmod>' . e(substr((string) $p['updated_at'], 0, 10)) . '</lastmod>';
            }
            echo "</url>\n";
        }
        echo "</urlset>\n";
    }

    // ------------------------------------------------------------------ utilitaires publics

    /** '' ou 'a/b/' -> '/' ou '/a/b' (forme NFC). */
    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($path, \Normalizer::FORM_C);
            if (is_string($n)) {
                $path = $n;
            }
        }
        return $path;
    }

    /** Lien href d'un chemin de page (segments encodés, accents compris). */
    public static function href(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** Forme comparable d'une ancienne URL: chemin décodé, NFC, minuscules, sans / final. */
    public static function legacyKey(string $u): string
    {
        $u = trim($u);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $u) || str_starts_with($u, '//')) {
            $u = (string) parse_url($u, PHP_URL_PATH);
        } else {
            $u = (string) preg_replace('/[?#].*$/s', '', $u);
        }
        $u = rawurldecode($u);
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($u, \Normalizer::FORM_C);
            if (is_string($n)) {
                $u = $n;
            }
        }
        $u = '/' . trim($u, '/');
        return mb_strtolower($u);
    }

    /** Chemin de la page publiée dont l'ancienne URL correspond, ou null. */
    public static function findLegacy(string $path): ?string
    {
        $key = self::legacyKey($path);
        $rows = Db::all(
            "SELECT path, legacy_url FROM pages WHERE status = 'published' AND legacy_url IS NOT NULL AND legacy_url <> ''"
        );
        foreach ($rows as $r) {
            if (self::legacyKey((string) $r['legacy_url']) === $key && $r['path'] !== $path) {
                return (string) $r['path'];
            }
        }
        return null;
    }

    /** @return array<int,array{title:string,path:string}> de l'accueil au parent direct */
    public static function breadcrumbs(array $page): array
    {
        $crumbs = [];
        $seen = [(int) $page['id'] => true];
        $parentId = $page['parent_id'] !== null ? (int) $page['parent_id'] : null;
        while ($parentId && !isset($seen[$parentId]) && count($crumbs) < 10) {
            $seen[$parentId] = true;
            $p = Db::one('SELECT id, parent_id, path, title, menu_title, status FROM pages WHERE id = ?', [$parentId]);
            if (!$p) {
                break;
            }
            if ($p['path'] !== '/') {
                array_unshift($crumbs, [
                    'title' => (string) ($p['menu_title'] ?: $p['title']),
                    'path' => (string) $p['path'],
                    'published' => $p['status'] === 'published',
                ]);
            }
            $parentId = $p['parent_id'] !== null ? (int) $p['parent_id'] : null;
        }
        if ($page['path'] !== '/') {
            array_unshift($crumbs, ['title' => 'Accueil', 'path' => '/', 'published' => true]);
        }
        return $crumbs;
    }

    /**
     * Arbre des pages publiées (3 niveaux max). $menuOnly: seulement show_in_menu.
     * Chaque nœud: id, title, path, children.
     * @return array<int,array<string,mixed>>
     */
    public static function pageTree(bool $menuOnly = true, int $maxDepth = 3): array
    {
        $sql = "SELECT id, parent_id, path, title, menu_title FROM pages WHERE status = 'published'"
            . ($menuOnly ? ' AND show_in_menu = 1' : '') . ' ORDER BY sort_order, title';
        $rows = Db::all($sql);
        $byParent = [];
        $ids = [];
        foreach ($rows as $r) {
            $ids[(int) $r['id']] = true;
        }
        foreach ($rows as $r) {
            $pid = $r['parent_id'] !== null ? (int) $r['parent_id'] : 0;
            if ($pid !== 0 && !isset($ids[$pid])) {
                continue; // parent masqué ou brouillon: on n'affiche pas l'enfant orphelin
            }
            $byParent[$pid][] = $r;
        }
        $build = function (int $pid, int $depth) use (&$build, $byParent, $maxDepth): array {
            $out = [];
            foreach ($byParent[$pid] ?? [] as $r) {
                $out[] = [
                    'id' => (int) $r['id'],
                    'title' => (string) (($r['menu_title'] ?? '') !== '' ? $r['menu_title'] : $r['title']),
                    'path' => (string) $r['path'],
                    'children' => $depth < $maxDepth ? $build((int) $r['id'], $depth + 1) : [],
                ];
            }
            return $out;
        };
        return $build(0, 1);
    }

    /** Menu principal (mis en cache pour la requête). */
    public static function menu(): array
    {
        if (self::$menu === null) {
            try {
                self::$menu = self::pageTree(true);
            } catch (\Throwable $e) {
                error_log('Menu: ' . $e->getMessage());
                self::$menu = [];
            }
        }
        return self::$menu;
    }
}
