<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Db;

/** Tableau de bord, journal d'activité et messages de contact. */
final class AdminController extends Controller
{
    /** Libellés lisibles des actions du journal. */
    public const ACTIONS = [
        'login' => 'Connexion',
        'logout' => 'Déconnexion',
        'page.create' => 'Page créée',
        'page.update' => 'Page modifiée',
        'page.delete' => 'Page supprimée',
        'page.restore' => 'Ancienne version restaurée',
        'page.reorder' => 'Menu réorganisé',
        'media.upload' => 'Fichier ajouté',
        'media.update' => 'Fichier renommé',
        'media.delete' => 'Fichier supprimé',
        'settings.update' => 'Réglages modifiés',
        'login_throttled' => 'Connexion bloquée (trop d\'essais)',
        'password_reset_request' => 'Demande de nouveau mot de passe',
        'password_reset' => 'Mot de passe réinitialisé',
        'password_change' => 'Mot de passe modifié',
        '2fa_enabled' => 'Double authentification activée',
        '2fa_disabled' => 'Double authentification désactivée',
        '2fa_reset' => 'Double authentification réinitialisée',
        'download' => 'Document confidentiel téléchargé',
        'user_create' => 'Compte créé',
        'user_update' => 'Compte modifié',
        'user_invite' => 'Invitation envoyée',
        'user_deactivate' => 'Compte désactivé',
        'user_delete' => 'Compte supprimé',
        'user_import' => 'Comptes importés',
        'group_create' => 'Groupe créé',
        'group_update' => 'Groupe modifié',
        'group_delete' => 'Groupe supprimé',
        'folder_create' => 'Dossier créé',
        'folder_update' => 'Dossier modifié',
        'folder_delete' => 'Dossier supprimé',
        'private_upload' => 'Document confidentiel ajouté',
        'private_delete' => 'Document confidentiel supprimé',
    ];

    public function dashboard(): void
    {
        Auth::requireAdmin();
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $stats = [
            'published' => (int) Db::value("SELECT COUNT(*) FROM pages WHERE status = 'published'"),
            'drafts' => (int) Db::value("SELECT COUNT(*) FROM pages WHERE status <> 'published'"),
            'media' => (int) Db::value('SELECT COUNT(*) FROM media'),
            'users' => (int) Db::value('SELECT COUNT(*) FROM users WHERE is_active = 1'),
            'messages30' => (int) Db::value('SELECT COUNT(*) FROM contact_messages WHERE created_at > ?', [$since]),
            'private_files' => (int) Db::value('SELECT COUNT(*) FROM private_files'),
        ];
        $recentPages = Db::all(
            'SELECT p.id, p.title, p.path, p.status, p.updated_at, u.name AS author
             FROM pages p LEFT JOIN users u ON u.id = p.updated_by
             ORDER BY p.updated_at DESC, p.id DESC LIMIT 8'
        );
        $recentMessages = Db::all('SELECT id, name, email, message, created_at FROM contact_messages ORDER BY id DESC LIMIT 5');
        $this->view('admin/dashboard', [
            'title' => 'Tableau de bord',
            'stats' => $stats,
            'recentPages' => $recentPages,
            'recentMessages' => $recentMessages,
            'user' => Auth::user(),
        ]);
    }

    public function auditLog(): void
    {
        Auth::requireAdmin();
        $per = 50;
        $where = '';
        $params = [];
        $action = (string) ($_GET['action'] ?? '');
        if ($action !== '' && preg_match('/^[a-z0-9_.-]{1,100}$/i', $action)) {
            $where = ' WHERE a.action = ?';
            $params[] = $action;
        } else {
            $action = '';
        }
        $total = (int) Db::value('SELECT COUNT(*) FROM audit_log a' . $where, $params);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
        $rows = Db::all(
            'SELECT a.*, u.name AS user_name, u.email AS user_email
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . $where . '
             ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params
        );
        $actions = array_column(Db::all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action');
        $this->view('admin/journal', [
            'title' => 'Journal d’activité',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'action' => $action,
            'actions' => $actions,
        ]);
    }

    public function messages(): void
    {
        Auth::requireAdmin();
        $per = 20;
        $total = (int) Db::value('SELECT COUNT(*) FROM contact_messages');
        $pages = max(1, (int) ceil($total / $per));
        $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
        $rows = Db::all('SELECT * FROM contact_messages ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
        $this->view('admin/messages', [
            'title' => 'Messages reçus',
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** Libellé lisible d'une action du journal. */
    public static function actionLabel(string $action): string
    {
        return self::ACTIONS[$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
    }
}
