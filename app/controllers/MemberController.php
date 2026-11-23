<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Controller;
use App\Db;
use App\PrivateAccess;
use App\Session;
use App\Totp;

/** Espace membres: consultation des dossiers confidentiels et profil personnel. */
final class MemberController extends Controller
{
    public function index(): void
    {
        $user = $this->guard();
        $ids = PrivateAccess::accessibleFolderIds($user);
        $this->view('private/index', [
            'user' => $user,
            'folders' => $this->withCounts(PrivateAccess::visibleFolders($user, null)),
            'recent' => $this->filesIn($ids, 'f.created_at DESC, f.id DESC', 8),
            'hasGroups' => PrivateAccess::isAdmin($user) || PrivateAccess::userGroupIds($user) !== [],
        ]);
    }

    public function folder(string $id): void
    {
        $user = $this->guard();
        $fid = (int) $id;
        if (!PrivateAccess::canAccessFolder($fid, $user)) {
            $this->notFound('Ce dossier est introuvable.');
            return;
        }
        // Le fil d'Ariane ne montre que les dossiers parents accessibles.
        $crumbs = array_values(array_filter(
            PrivateAccess::breadcrumb($fid),
            fn($c) => PrivateAccess::canAccessFolder($c['id'], $user)
        ));
        $this->view('private/folder', [
            'folder' => PrivateAccess::folder($fid),
            'breadcrumb' => $crumbs,
            'subfolders' => $this->withCounts(PrivateAccess::visibleFolders($user, $fid)),
            'files' => $this->filesIn([$fid], 'f.created_at DESC, f.id DESC', 1000),
        ]);
    }

    public function download(string $id): void
    {
        $user = $this->guard();
        $fileId = (int) $id;
        $file = Db::one('SELECT * FROM private_files WHERE id = ?', [$fileId]);
        if (!$file || !PrivateAccess::canDownloadFile($fileId, $user)) {
            $this->notFound('Ce document est introuvable.');
            return;
        }
        $stored = (string) $file['stored_name'];
        $base = realpath(STORAGE . '/private');
        $path = preg_match('/^[a-f0-9]{32}$/', $stored) ? realpath(STORAGE . '/private/' . $stored) : false;
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            error_log('Fichier privé manquant sur le disque: id ' . $fileId);
            $this->notFound('Ce document est momentanément indisponible. Merci de prévenir l\'administrateur.');
            return;
        }
        audit('download', 'file:' . $fileId . ' ' . mb_substr((string) $file['original_name'], 0, 200));
        session_write_close();

        $name = (string) $file['original_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: 'document');
        $ascii = trim((string) $ascii) !== '' ? $ascii : 'document';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header_remove('Pragma');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            readfile($path);
        }
    }

    public function search(): void
    {
        $user = $this->guard();
        $q = trim((string) ($_GET['q'] ?? ''));
        $results = [];
        if (mb_strlen($q) >= 2) {
            $ids = PrivateAccess::accessibleFolderIds($user);
            if ($ids) {
                $like = '%' . strtr(mb_strtolower(mb_substr($q, 0, 100)), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $in = implode(',', array_fill(0, count($ids), '?'));
                $results = Db::all(
                    "SELECT f.*, d.name AS folder_name FROM private_files f JOIN private_folders d ON d.id = f.folder_id
                     WHERE f.folder_id IN ($in) AND (LOWER(f.title) LIKE ? ESCAPE '!' OR LOWER(f.original_name) LIKE ? ESCAPE '!')
                     ORDER BY f.created_at DESC LIMIT 100",
                    array_merge($ids, [$like, $like])
                );
            }
        }
        $this->view('private/search', ['q' => $q, 'results' => $results]);
    }

    // ------------------------------------------------------------------ profil

    public function profile(): void
    {
        $user = $this->guard();
        $setup = $_SESSION['totp_setup'] ?? null;
        $data = [
            'user' => $user,
            'groups' => Db::all('SELECT g.name FROM user_groups g JOIN user_group_members m ON m.group_id = g.id WHERE m.user_id = ? ORDER BY g.name', [$user['id']]),
            'setup' => null,
            'adminMust2fa' => $user['role'] === 'admin' && (bool) Config::get('security.require_2fa_for_admins'),
        ];
        if (is_string($setup) && empty($user['totp_secret'])) {
            $data['setup'] = [
                'secretFormatted' => Totp::formatSecret($setup),
                'uri' => Totp::uri($setup, (string) $user['email']),
            ];
        }
        $this->view('private/profile', $data);
    }

    public function changePassword(): void
    {
        $user = $this->guard();
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $hash = (string) Db::value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!password_verify($current, $hash)) {
            flash('Le mot de passe actuel n\'est pas correct.', 'error');
        } elseif (($p = \App\Auth::passwordProblem($new)) !== null) {
            flash($p, 'error');
        } elseif ($new !== $confirm) {
            flash('Les deux nouveaux mots de passe ne sont pas identiques.', 'error');
        } elseif ($new === $current) {
            flash('Le nouveau mot de passe doit être différent de l\'actuel.', 'error');
        } else {
            Db::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
            Db::run('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]);
            Session::regenerate();
            audit('password_change', 'user:' . $user['id']);
            flash('Votre mot de passe a été modifié.');
        }
        redirect('/espace-membres/profil#mot-de-passe');
    }

    public function setupTwoFactor(): void
    {
        $user = $this->guard();
        $step = $this->input('step');
        if (!empty($user['totp_secret'])) {
            flash('La double authentification est déjà active.', 'info');
            redirect('/espace-membres/profil#double-authentification');
        }
        if ($step === 'start') {
            $_SESSION['totp_setup'] = Totp::generateSecret();
        } elseif ($step === 'cancel') {
            unset($_SESSION['totp_setup']);
            flash('Configuration annulée.', 'info');
        } elseif ($step === 'confirm') {
            $secret = $_SESSION['totp_setup'] ?? null;
            if (!is_string($secret)) {
                flash('Recommencez la configuration.', 'error');
            } elseif (!Totp::verify($secret, $this->input('code'))) {
                flash('Le code saisi n\'est pas correct. Vérifiez que l\'heure de votre téléphone est juste et saisissez le code actuel.', 'error');
            } else {
                Db::update('users', ['totp_secret' => $secret], 'id = :id', ['id' => $user['id']]);
                unset($_SESSION['totp_setup']);
                audit('2fa_enabled', 'user:' . $user['id']);
                flash('La double authentification est activée. Un code vous sera demandé à chaque connexion.');
            }
        }
        redirect('/espace-membres/profil#double-authentification');
    }

    public function disableTwoFactor(): void
    {
        $user = $this->guard();
        $hash = (string) Db::value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if ($user['role'] === 'admin' && Config::get('security.require_2fa_for_admins')) {
            flash('La double authentification est obligatoire pour les administrateurs.', 'error');
        } elseif (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) {
            flash('Le mot de passe n\'est pas correct. La double authentification reste active.', 'error');
        } else {
            Db::update('users', ['totp_secret' => null], 'id = :id', ['id' => $user['id']]);
            audit('2fa_disabled', 'user:' . $user['id']);
            flash('La double authentification est désactivée.', 'info');
        }
        redirect('/espace-membres/profil#double-authentification');
    }

    // ------------------------------------------------------------------ interne

    private function guard(): array
    {
        Auth::requireLogin();
        header('Cache-Control: private, no-store');
        return Auth::user();
    }

    /** @param int[] $folderIds */
    private function filesIn(array $folderIds, string $order, int $limit): array
    {
        if (!$folderIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($folderIds), '?'));
        return Db::all(
            "SELECT f.*, d.name AS folder_name FROM private_files f JOIN private_folders d ON d.id = f.folder_id
             WHERE f.folder_id IN ($in) ORDER BY $order LIMIT " . (int) $limit,
            array_values($folderIds)
        );
    }

    private function withCounts(array $folders): array
    {
        foreach ($folders as &$f) {
            // documents du dossier et de ses sous-dossiers
            $ids = PrivateAccess::descendantIds((int) $f['id']);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $f['file_count'] = (int) Db::value("SELECT COUNT(*) FROM private_files WHERE folder_id IN ($in)", $ids);
        }
        return $folders;
    }
}
