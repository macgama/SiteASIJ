<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Controller;
use App\Db;
use App\PrivateAccess;

/** Administration de l'espace sécurisé: dossiers, droits par groupe, documents. */
final class AdminPrivateController extends Controller
{
    /** Extensions autorisées => [type MIME enregistré, types détectés acceptés par finfo] */
    public const ALLOWED = [
        'pdf' => ['application/pdf', ['application/pdf']],
        'doc' => ['application/msword', ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream']],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
        'xls' => ['application/vnd.ms-excel', ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream']],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
        'ppt' => ['application/vnd.ms-powerpoint', ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream']],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
        'odt' => ['application/vnd.oasis.opendocument.text', ['application/vnd.oasis.opendocument.text', 'application/zip']],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip']],
        'jpg' => ['image/jpeg', ['image/jpeg']],
        'jpeg' => ['image/jpeg', ['image/jpeg']],
        'png' => ['image/png', ['image/png']],
        'txt' => ['text/plain', ['text/plain']],
        'csv' => ['text/csv', ['text/csv', 'text/plain', 'application/csv']],
        'zip' => ['application/zip', ['application/zip', 'application/x-zip-compressed']],
    ];

    public function __construct()
    {
        Auth::requireAdmin();
        header('Cache-Control: private, no-store');
    }

    public function index(): void
    {
        $folders = PrivateAccess::allFolders();
        $this->view('admin/private/index', [
            'tree' => $this->tree($folders, null),
            'groupNames' => $this->groupNames(),
            'counts' => $this->fileCounts(),
            'groups' => Db::all('SELECT * FROM user_groups ORDER BY name'),
            'folderOptions' => $this->folderOptions(null),
        ]);
    }

    public function folder(string $id): void
    {
        $fid = (int) $id;
        $folder = PrivateAccess::folder($fid);
        if (!$folder) {
            $this->notFound('Ce dossier n\'existe pas.');
            return;
        }
        $children = array_values(array_filter(PrivateAccess::allFolders(), fn($f) => (int) ($f['parent_id'] ?? 0) === $fid));
        $this->view('admin/private/folder', [
            'folder' => $folder,
            'breadcrumb' => PrivateAccess::breadcrumb($fid),
            'children' => $children,
            'counts' => $this->fileCounts(),
            'files' => Db::all('SELECT f.*, u.name AS uploader FROM private_files f LEFT JOIN users u ON u.id = f.uploaded_by WHERE f.folder_id = ? ORDER BY f.created_at DESC, f.id DESC', [$fid]),
            'groups' => Db::all('SELECT * FROM user_groups ORDER BY name'),
            'direct' => PrivateAccess::directGroupIds($fid),
            'inherited' => PrivateAccess::inheritedGroupIds($fid),
            'folderOptions' => $this->folderOptions($fid),
            'maxBytes' => (int) Config::get('uploads.max_bytes', 25 * 1024 * 1024),
            'extensions' => array_keys(self::ALLOWED),
        ]);
    }

    public function saveFolder(): void
    {
        $id = (int) $this->input('id', '0');
        $name = mb_substr($this->input('name'), 0, 255);
        $desc = mb_substr($this->input('description'), 0, 2000);
        $parentId = (int) $this->input('parent_id', '0') ?: null;
        $back = $id ? '/admin/espace/dossier/' . $id : '/admin/espace';

        $existing = $id ? PrivateAccess::folder($id) : null;
        if ($id && !$existing) {
            $this->notFound();
            return;
        }
        if ($name === '') {
            flash('Indiquez le nom du dossier.', 'error');
            redirect($back);
        }
        if ($parentId !== null && !PrivateAccess::folder($parentId)) {
            flash('Le dossier parent choisi n\'existe pas.', 'error');
            redirect($back);
        }
        if ($id && $parentId !== null && in_array($parentId, PrivateAccess::descendantIds($id), true)) {
            flash('Un dossier ne peut pas être déplacé dans lui-même ou dans un de ses sous-dossiers.', 'error');
            redirect($back);
        }
        $validGroups = array_map('intval', array_column(Db::all('SELECT id FROM user_groups'), 'id'));
        $groups = array_values(array_unique(array_intersect(array_map('intval', (array) ($_POST['groups'] ?? [])), $validGroups)));

        $id = Db::transaction(function () use ($id, $existing, $name, $desc, $parentId, $groups) {
            $data = ['name' => $name, 'description' => $desc !== '' ? $desc : null, 'parent_id' => $parentId];
            if ($existing) {
                Db::update('private_folders', $data, 'id = :id', ['id' => $id]);
            } else {
                $data['sort_order'] = 0;
                $data['created_at'] = Db::now();
                $id = Db::insert('private_folders', $data);
            }
            Db::run('DELETE FROM private_folder_groups WHERE folder_id = ?', [$id]);
            foreach ($groups as $g) {
                Db::insert('private_folder_groups', ['folder_id' => $id, 'group_id' => $g]);
            }
            return $id;
        });
        PrivateAccess::flush();
        audit($existing ? 'folder_update' : 'folder_create', 'folder:' . $id . ' ' . $name . ' groupes:' . implode(',', $groups));
        flash($existing ? 'Le dossier a été enregistré.' : 'Le dossier « ' . $name . ' » a été créé.');
        redirect('/admin/espace/dossier/' . $id);
    }

    public function deleteFolder(string $id): void
    {
        $fid = (int) $id;
        $folder = PrivateAccess::folder($fid);
        if (!$folder) {
            $this->notFound();
            return;
        }
        $hasChildren = (int) Db::value('SELECT COUNT(*) FROM private_folders WHERE parent_id = ?', [$fid]) > 0;
        $hasFiles = (int) Db::value('SELECT COUNT(*) FROM private_files WHERE folder_id = ?', [$fid]) > 0;
        if ($hasChildren || $hasFiles) {
            flash('Ce dossier n\'est pas vide. Supprimez ou déplacez d\'abord ses sous-dossiers et documents.', 'error');
            redirect('/admin/espace/dossier/' . $fid);
        }
        if (empty($_POST['confirm'])) {
            flash('Confirmez la suppression en cochant la case.', 'error');
            redirect('/admin/espace/dossier/' . $fid);
        }
        Db::transaction(function () use ($fid) {
            Db::run('DELETE FROM private_folder_groups WHERE folder_id = ?', [$fid]);
            Db::run('DELETE FROM private_folders WHERE id = ?', [$fid]);
        });
        PrivateAccess::flush();
        audit('folder_delete', 'folder:' . $fid . ' ' . $folder['name']);
        flash('Le dossier « ' . $folder['name'] . ' » a été supprimé.');
        redirect($folder['parent_id'] ? '/admin/espace/dossier/' . (int) $folder['parent_id'] : '/admin/espace');
    }

    public function upload(string $id): void
    {
        $fid = (int) $id;
        if (!PrivateAccess::folder($fid)) {
            $this->notFound();
            return;
        }
        $dir = STORAGE . '/private';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $max = (int) Config::get('uploads.max_bytes', 25 * 1024 * 1024);
        $files = self::normalizeFiles($_FILES['files'] ?? null);
        $singleTitle = mb_substr($this->input('title'), 0, 255);
        $ok = [];
        $errors = [];
        if (!$files) {
            flash('Choisissez au moins un fichier à ajouter.', 'error');
            redirect('/admin/espace/dossier/' . $fid);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($files as $f) {
            $original = self::cleanName((string) $f['name']);
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = "$original : fichier trop volumineux (maximum " . human_size($max) . ').';
                continue;
            }
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                $errors[] = "$original : l'envoi a échoué, veuillez réessayer.";
                continue;
            }
            if ($f['size'] > $max || $f['size'] <= 0) {
                $errors[] = "$original : " . ($f['size'] <= 0 ? 'fichier vide.' : 'fichier trop volumineux (maximum ' . human_size($max) . ').');
                continue;
            }
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $detected = strtolower((string) $finfo->file($f['tmp_name']));
            if (!isset(self::ALLOWED[$ext]) || !in_array($detected, self::ALLOWED[$ext][1], true)) {
                $errors[] = "$original : type de fichier non autorisé.";
                continue;
            }
            $stored = bin2hex(random_bytes(16));
            $dest = $dir . '/' . $stored;
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                $errors[] = "$original : impossible d'enregistrer le fichier sur le serveur.";
                continue;
            }
            @chmod($dest, 0640);
            $title = count($files) === 1 && $singleTitle !== '' ? $singleTitle : self::titleFromName($original);
            $fileId = Db::insert('private_files', [
                'folder_id' => $fid,
                'stored_name' => $stored,
                'original_name' => $original,
                'title' => $title,
                'mime' => self::ALLOWED[$ext][0],
                'size' => (int) filesize($dest),
                'sha256' => hash_file('sha256', $dest),
                'uploaded_by' => Auth::id(),
                'created_at' => Db::now(),
            ]);
            audit('private_upload', 'file:' . $fileId . ' ' . mb_substr($original, 0, 200));
            $ok[] = $original;
        }
        if ($ok) {
            flash(count($ok) === 1 ? 'Document ajouté : ' . $ok[0] : count($ok) . ' documents ajoutés.');
        }
        foreach ($errors as $err) {
            flash($err, 'error');
        }
        redirect('/admin/espace/dossier/' . $fid);
    }

    public function deleteFile(string $id): void
    {
        $file = Db::one('SELECT * FROM private_files WHERE id = ?', [(int) $id]);
        if (!$file) {
            $this->notFound();
            return;
        }
        $stored = (string) $file['stored_name'];
        if (preg_match('/^[a-f0-9]{32}$/', $stored)) {
            $path = STORAGE . '/private/' . $stored;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        Db::run('DELETE FROM private_files WHERE id = ?', [$file['id']]);
        audit('private_delete', 'file:' . $file['id'] . ' ' . mb_substr((string) $file['original_name'], 0, 200));
        flash('Le document « ' . ($file['title'] ?: $file['original_name']) . ' » a été supprimé.');
        redirect('/admin/espace/dossier/' . (int) $file['folder_id']);
    }

    // ------------------------------------------------------------------ outils

    /** Transforme $_FILES['x'] (multiple ou non) en liste de fichiers. */
    public static function normalizeFiles(mixed $f): array
    {
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'size' => (int) $f['size'][$i], 'error' => (int) $f['error'][$i]];
        }
        return $out;
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
        }
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>|:*?]/u', '', $name);
        $name = trim($name, " .");
        return mb_substr($name !== '' ? $name : 'document', -200);
    }

    public static function titleFromName(string $name): string
    {
        $t = pathinfo($name, PATHINFO_FILENAME);
        $t = trim((string) preg_replace('/[_]+/', ' ', $t));
        return mb_substr($t !== '' ? $t : $name, 0, 255);
    }

    private function tree(array $folders, ?int $parent, int $depth = 0): array
    {
        $out = [];
        if ($depth > 20) {
            return $out;
        }
        foreach ($folders as $id => $f) {
            $pid = $f['parent_id'] !== null ? (int) $f['parent_id'] : null;
            if ($pid === $parent) {
                $f['children'] = $this->tree($folders, $id, $depth + 1);
                $out[] = $f;
            }
        }
        return $out;
    }

    /** Options « dossier parent »: tous les dossiers sauf $exclude et ses descendants. */
    private function folderOptions(?int $exclude): array
    {
        $skip = $exclude ? PrivateAccess::descendantIds($exclude) : [];
        $out = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$out, $skip) {
            foreach ($nodes as $n) {
                if (in_array((int) $n['id'], $skip, true)) {
                    continue;
                }
                $out[] = ['id' => (int) $n['id'], 'label' => str_repeat('— ', $depth) . $n['name']];
                $walk($n['children'], $depth + 1);
            }
        };
        $walk($this->tree(PrivateAccess::allFolders(), null), 0);
        return $out;
    }

    /** @return array<int,string[]> noms des groupes autorisés directement, par dossier */
    private function groupNames(): array
    {
        $out = [];
        foreach (Db::all('SELECT fg.folder_id, g.name FROM private_folder_groups fg JOIN user_groups g ON g.id = fg.group_id ORDER BY g.name') as $r) {
            $out[(int) $r['folder_id']][] = $r['name'];
        }
        return $out;
    }

    /** @return array<int,int> */
    private function fileCounts(): array
    {
        $out = [];
        foreach (Db::all('SELECT folder_id, COUNT(*) AS n FROM private_files GROUP BY folder_id') as $r) {
            $out[(int) $r['folder_id']] = (int) $r['n'];
        }
        return $out;
    }
}
