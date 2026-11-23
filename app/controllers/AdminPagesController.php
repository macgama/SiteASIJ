<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Db;
use App\Pages;
use App\View;

/** Gestion des pages du site (arbre, édition par blocs, versions). */
final class AdminPagesController extends Controller
{
    private const KEEP_REVISIONS = 30;

    public function index(): void
    {
        Auth::requireAdmin();
        $this->view('admin/pages/index', [
            'title' => 'Pages',
            'tree' => Pages::tree(),
            'count' => (int) Db::value('SELECT COUNT(*) FROM pages'),
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();
        $parent = isset($_GET['parent']) && ctype_digit((string) $_GET['parent']) ? (int) $_GET['parent'] : null;
        if ($parent && !Db::value('SELECT id FROM pages WHERE id = ?', [$parent])) {
            $parent = null;
        }
        $page = [
            'id' => null, 'parent_id' => $parent, 'slug' => '', 'path' => '', 'title' => '', 'menu_title' => '',
            'meta_description' => '', 'blocks' => '[]', 'status' => 'draft', 'show_in_menu' => 1,
        ];
        $this->form($page, []);
    }

    public function store(): void
    {
        Auth::requireAdmin();
        [$data, $errors, $blocks] = $this->validate(null);
        if ($errors) {
            $this->form($data + ['id' => null, 'path' => ''], $errors, 422);
            return;
        }
        $id = Db::transaction(function () use ($data, $blocks) {
            $maxSort = (int) Db::value(
                'SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE ' . ($data['parent_id'] ? 'parent_id = ?' : 'parent_id IS NULL'),
                $data['parent_id'] ? [$data['parent_id']] : []
            );
            $id = Db::insert('pages', $data + [
                'sort_order' => $maxSort + 1,
                'updated_at' => Db::now(),
                'updated_by' => Auth::id(),
            ]);
            $this->saveRevision($id, $data['title'], $blocks);
            return $id;
        });
        audit('page.create', 'page:' . $id . ' ' . $data['title']);
        flash('La page « ' . $data['title'] . ' » a été créée.' . ($data['status'] === 'draft' ? ' Elle est en brouillon : les visiteurs ne la voient pas encore.' : ''));
        $this->afterSave($id);
    }

    public function edit(string $id): void
    {
        Auth::requireAdmin();
        $page = Db::one('SELECT * FROM pages WHERE id = ?', [(int) $id]);
        if (!$page) {
            $this->notFound('Cette page n’existe pas ou a été supprimée.');
            return;
        }
        $this->form($page, []);
    }

    public function update(string $id): void
    {
        Auth::requireAdmin();
        $id = (int) $id;
        $old = Db::one('SELECT * FROM pages WHERE id = ?', [$id]);
        if (!$old) {
            $this->notFound('Cette page n’existe pas ou a été supprimée.');
            return;
        }
        [$data, $errors, $blocks] = $this->validate($old);
        if ($errors) {
            $this->form($data + ['id' => $id, 'path' => $old['path']], $errors, 422);
            return;
        }
        Db::transaction(function () use ($id, $data, $blocks, $old) {
            Db::update('pages', $data + ['updated_at' => Db::now(), 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
            if ($data['path'] !== $old['path']) {
                Pages::refreshDescendantPaths($id);
            }
            $this->saveRevision($id, $data['title'], $blocks);
        });
        audit('page.update', 'page:' . $id . ' ' . $data['title']);
        $msg = 'Modifications enregistrées.';
        if ($data['status'] === 'published' && $old['status'] !== 'published') {
            $msg = 'La page est maintenant publiée et visible par les visiteurs.';
        } elseif ($data['status'] === 'draft' && $old['status'] === 'published') {
            $msg = 'La page a été repassée en brouillon : les visiteurs ne la voient plus.';
        }
        if ($data['path'] !== $old['path']) {
            $msg .= ' Nouvelle adresse : ' . $data['path'];
        }
        flash($msg);
        $this->afterSave($id);
    }

    public function destroy(string $id): void
    {
        Auth::requireAdmin();
        $id = (int) $id;
        $page = Db::one('SELECT id, title FROM pages WHERE id = ?', [$id]);
        if (!$page) {
            flash('Cette page n’existe plus.', 'error');
            redirect('/admin/pages');
        }
        $children = (int) Db::value('SELECT COUNT(*) FROM pages WHERE parent_id = ?', [$id]);
        if ($children > 0) {
            flash('Impossible de supprimer « ' . $page['title'] . ' » : elle contient ' . $children . ' sous-page(s). Déplacez ou supprimez d’abord ses sous-pages.', 'error');
            redirect('/admin/pages/' . $id);
        }
        Db::transaction(function () use ($id) {
            Db::run('DELETE FROM page_revisions WHERE page_id = ?', [$id]);
            Db::run('DELETE FROM pages WHERE id = ?', [$id]);
        });
        audit('page.delete', 'page:' . $id . ' ' . $page['title']);
        flash('La page « ' . $page['title'] . ' » a été supprimée.');
        redirect('/admin/pages');
    }

    public function preview(string $id): void
    {
        Auth::requireAdmin();
        $page = Db::one('SELECT * FROM pages WHERE id = ?', [(int) $id]);
        if (!$page) {
            $this->notFound();
            return;
        }
        $blocks = json_decode((string) $page['blocks'], true) ?: [];
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, no-store');
        $bar = View::partial('admin/pages/_preview-bar', ['page' => $page]);
        if (is_file(APP . '/views/public/page.php') && class_exists(\App\Blocks::class)) {
            // même rendu que le site public (PageController::show)
            $children = Db::all(
                "SELECT id, path, title, menu_title, meta_description FROM pages
                 WHERE parent_id = ? AND status = 'published' ORDER BY sort_order, title",
                [(int) $page['id']]
            );
            $crumbs = method_exists(PageController::class, 'breadcrumbs') ? PageController::breadcrumbs($page) : [];
            $html = View::render('public/page', [
                'page' => $page,
                'title' => $page['path'] === '/' ? '' : (string) $page['title'],
                'metaDescription' => (string) ($page['meta_description'] ?? ''),
                'blocksHtml' => \App\Blocks::render($blocks),
                'children' => $children,
                'breadcrumbs' => $crumbs,
                'isDraft' => $page['status'] !== 'published',
                'currentPath' => (string) $page['path'],
                'noindex' => true,
            ]);
            echo preg_replace_callback('/<body[^>]*>/', fn($m) => $m[0] . $bar, $html, 1);
            return;
        }
        $this->view('admin/pages/preview', [
            'title' => (string) $page['title'],
            'page' => $page,
            'blocks' => $blocks,
            'preview' => true,
        ]);
    }

    public function revisions(string $id): void
    {
        Auth::requireAdmin();
        $page = Db::one('SELECT id, title, path, status FROM pages WHERE id = ?', [(int) $id]);
        if (!$page) {
            $this->notFound();
            return;
        }
        $revs = Db::all(
            'SELECT r.id, r.title, r.blocks, r.created_at, u.name AS author
             FROM page_revisions r LEFT JOIN users u ON u.id = r.created_by
             WHERE r.page_id = ? ORDER BY r.id DESC',
            [(int) $id]
        );
        $this->view('admin/pages/revisions', ['title' => 'Versions de « ' . $page['title'] . ' »', 'page' => $page, 'revisions' => $revs]);
    }

    public function restore(string $id, string $rev): void
    {
        Auth::requireAdmin();
        $id = (int) $id;
        $r = Db::one('SELECT * FROM page_revisions WHERE id = ? AND page_id = ?', [(int) $rev, $id]);
        if (!$r || !Db::value('SELECT id FROM pages WHERE id = ?', [$id])) {
            flash('Cette version est introuvable.', 'error');
            redirect('/admin/pages/' . $id . '/versions');
        }
        // re-valider: des médias ont pu être supprimés entre-temps
        $blocks = Pages::sanitizeBlocks((string) $r['blocks']);
        Db::transaction(function () use ($id, $r, $blocks) {
            Db::update('pages', [
                'title' => $r['title'],
                'blocks' => self::encode($blocks),
                'updated_at' => Db::now(),
                'updated_by' => Auth::id(),
            ], 'id = :id', ['id' => $id]);
            $this->saveRevision($id, (string) $r['title'], $blocks, true);
        });
        audit('page.restore', 'page:' . $id . ' version ' . (int) $rev);
        flash('La version du ' . date_fr((string) $r['created_at'], true) . ' a été restaurée.');
        redirect('/admin/pages/' . $id);
    }

    /** POST /admin/pages/ordre — JSON {items:[{id, parent_id, sort_order}]} */
    public function reorder(): void
    {
        Auth::requireAdmin();
        $body = json_decode((string) file_get_contents('php://input'), true);
        $items = is_array($body['items'] ?? null) ? $body['items'] : null;
        if ($items === null) {
            $this->json(['ok' => false, 'error' => 'Requête invalide.'], 400);
            return;
        }
        try {
            Pages::applyOrder($items);
        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        }
        audit('page.reorder', count($items) . ' pages');
        $paths = [];
        foreach (Db::all('SELECT id, path FROM pages') as $p) {
            $paths[(int) $p['id']] = $p['path'];
        }
        $this->json(['ok' => true, 'message' => 'Nouvel ordre enregistré.', 'paths' => $paths]);
    }

    // ------------------------------------------------------------------ interne

    private function afterSave(int $id): never
    {
        if (($_POST['after'] ?? '') === 'preview') {
            redirect('/admin/pages/' . $id . '/apercu');
        }
        redirect('/admin/pages/' . $id);
    }

    public static function encode(array $blocks): string
    {
        return json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * Lit et valide le formulaire.
     * @return array{0:array,1:array<string,string>,2:array} [données page, erreurs, blocs]
     */
    private function validate(?array $old): array
    {
        $errors = [];
        $id = $old ? (int) $old['id'] : null;
        $title = mb_substr($this->input('title'), 0, 255);
        if ($title === '') {
            $errors['title'] = 'Indiquez un titre pour la page.';
        }
        $parent = $this->input('parent_id');
        $parentId = ctype_digit($parent) && $parent !== '0' ? (int) $parent : null;
        if ($parentId && !Db::value('SELECT id FROM pages WHERE id = ?', [$parentId])) {
            $errors['parent_id'] = 'La page parente choisie n’existe plus.';
            $parentId = null;
        }
        if ($id && Pages::wouldCycle($id, $parentId)) {
            $errors['parent_id'] = 'Une page ne peut pas être placée sous elle-même ou sous une de ses sous-pages.';
            $parentId = $old['parent_id'] ? (int) $old['parent_id'] : null;
        }

        $isHome = !$parentId && !empty($_POST['is_home']);
        if ($isHome) {
            $slug = '';
            $homeId = Db::value("SELECT id FROM pages WHERE path = '/'");
            if ($homeId !== null && (int) $homeId !== $id) {
                $errors['slug'] = 'Une autre page est déjà la page d’accueil.';
            }
        } else {
            $slug = Pages::slugify($this->input('slug'));
            if ($slug === '') {
                $slug = Pages::slugify($title);
            }
            if ($slug === '') {
                $slug = 'page';
            }
            $wanted = $slug;
            if (!isset($errors['parent_id'])) {
                $slug = Pages::uniqueSlug($parentId, $slug, $id);
                if ($slug !== $wanted && !$errors) {
                    flash('L’adresse « ' . $wanted . ' » était déjà utilisée : « ' . $slug . ' » a été choisie à la place.', 'info');
                }
            }
        }
        $path = isset($errors['parent_id']) ? ($old['path'] ?? '') : Pages::computePath($parentId, $slug);

        $status = $this->input('status') === 'published' ? 'published' : 'draft';
        $raw = $_POST['blocks'] ?? '[]';
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $errors['blocks'] = 'Le contenu de la page n’a pas pu être lu. Rechargez la page et réessayez.';
            $decoded = [];
        }
        $blocks = Pages::sanitizeBlocks($decoded);

        $data = [
            'parent_id' => $parentId,
            'slug' => $slug,
            'path' => $path,
            'title' => $title,
            'menu_title' => mb_substr($this->input('menu_title'), 0, 255) ?: null,
            'meta_description' => mb_substr(preg_replace('/\s+/', ' ', $this->input('meta_description')) ?? '', 0, 500) ?: null,
            'blocks' => self::encode($blocks),
            'status' => $status,
            'show_in_menu' => empty($_POST['show_in_menu']) ? 0 : 1,
        ];
        return [$data, $errors, $blocks];
    }

    private function saveRevision(int $pageId, string $title, array $blocks, bool $force = false): void
    {
        $json = self::encode($blocks);
        $last = Db::one('SELECT title, blocks FROM page_revisions WHERE page_id = ? ORDER BY id DESC LIMIT 1', [$pageId]);
        if (!$force && $last && $last['title'] === $title && $last['blocks'] === $json) {
            return; // rien n'a changé dans le contenu
        }
        Db::insert('page_revisions', [
            'page_id' => $pageId,
            'title' => $title,
            'blocks' => $json,
            'created_at' => Db::now(),
            'created_by' => Auth::id(),
        ]);
        $keep = Db::all('SELECT id FROM page_revisions WHERE page_id = ? ORDER BY id DESC LIMIT ' . self::KEEP_REVISIONS, [$pageId]);
        if (count($keep) === self::KEEP_REVISIONS) {
            Db::run('DELETE FROM page_revisions WHERE page_id = ? AND id < ?', [$pageId, (int) end($keep)['id']]);
        }
    }

    private function form(array $page, array $errors, int $status = 200): void
    {
        $id = $page['id'] ? (int) $page['id'] : null;
        $blocks = json_decode((string) ($page['blocks'] ?? '[]'), true) ?: [];
        // infos sur les médias utilisés, pour l'affichage dans l'éditeur
        $ids = [];
        array_walk_recursive($blocks, function ($v, $k) use (&$ids) {
            if ($k === 'media_id' && $v) {
                $ids[(int) $v] = true;
            }
        });
        $media = [];
        if ($ids) {
            $list = array_keys($ids);
            foreach (Db::all('SELECT * FROM media WHERE id IN (' . implode(',', array_fill(0, count($list), '?')) . ')', $list) as $m) {
                $media[(int) $m['id']] = AdminMediaController::toJson($m);
            }
        }
        $links = [];
        foreach (Pages::flat() as $p) {
            $links[] = ['title' => $p['title'], 'path' => $p['path']];
        }
        $this->view('admin/pages/edit', [
            'title' => $id ? 'Modifier « ' . $page['title'] . ' »' : 'Nouvelle page',
            'page' => $page,
            'errors' => $errors,
            'blocks' => $blocks,
            'media' => $media,
            'parents' => Pages::flat($id),
            'links' => $links,
            'revisionCount' => $id ? (int) Db::value('SELECT COUNT(*) FROM page_revisions WHERE page_id = ?', [$id]) : 0,
            'homeExists' => Db::value("SELECT id FROM pages WHERE path = '/'"),
        ], $status);
    }
}
