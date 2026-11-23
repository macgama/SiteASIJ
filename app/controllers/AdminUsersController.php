<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Db;
use App\PrivateAccess;

/** Gestion des comptes, des groupes et de l'import CSV (administrateurs uniquement). */
final class AdminUsersController extends Controller
{
    private const MAX_IMPORT_ROWS = 1000;

    public function __construct()
    {
        Auth::requireAdmin();
        header('Cache-Control: private, no-store');
    }

    // ------------------------------------------------------------------ liste

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $group = (int) ($_GET['groupe'] ?? 0);
        $status = (string) ($_GET['statut'] ?? 'actifs');
        $where = [];
        $params = [];
        if ($q !== '') {
            $like = '%' . strtr(mb_strtolower(mb_substr($q, 0, 100)), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where[] = "(LOWER(u.name) LIKE ? ESCAPE '!' OR LOWER(u.email) LIKE ? ESCAPE '!')";
            array_push($params, $like, $like);
        }
        if ($group > 0) {
            $where[] = 'u.id IN (SELECT user_id FROM user_group_members WHERE group_id = ?)';
            $params[] = $group;
        }
        if ($status === 'actifs') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'inactifs') {
            $where[] = 'u.is_active = 0';
        } else {
            $status = 'tous';
        }
        $users = Db::all(
            'SELECT u.* FROM users u' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY u.name',
            $params
        );
        $groupsByUser = [];
        foreach (Db::all('SELECT m.user_id, g.name FROM user_group_members m JOIN user_groups g ON g.id = m.group_id ORDER BY g.name') as $r) {
            $groupsByUser[(int) $r['user_id']][] = $r['name'];
        }
        $pending = array_map('intval', array_column(
            Db::all('SELECT DISTINCT user_id FROM password_resets WHERE expires_at > ?', [Db::now()]),
            'user_id'
        ));
        $this->view('admin/users/index', [
            'users' => $users,
            'groupsByUser' => $groupsByUser,
            'pendingInvites' => $pending,
            'groups' => $this->allGroups(),
            'q' => $q,
            'group' => $group,
            'status' => $status,
            'total' => (int) Db::value('SELECT COUNT(*) FROM users'),
        ]);
    }

    // ------------------------------------------------------------------ création / modification

    public function create(): void
    {
        $this->view('admin/users/form', [
            'u' => ['id' => null, 'name' => '', 'email' => '', 'role' => 'member', 'is_active' => 1, 'totp_secret' => null, 'last_login_at' => null],
            'userGroups' => [],
            'groups' => $this->allGroups(),
            'errors' => [],
            'isSelf' => false,
        ]);
    }

    public function store(): void
    {
        [$data, $groupIds, $errors] = $this->validated(null);
        if ($errors) {
            $this->view('admin/users/form', [
                'u' => $data + ['id' => null, 'totp_secret' => null, 'last_login_at' => null],
                'userGroups' => $groupIds,
                'groups' => $this->allGroups(),
                'errors' => $errors,
                'isSelf' => false,
            ], 422);
            return;
        }
        $id = Db::transaction(function () use ($data, $groupIds) {
            $id = Db::insert('users', $data + [
                // mot de passe aléatoire inutilisable: la personne choisit le sien via l'invitation
                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'created_at' => Db::now(),
            ]);
            $this->syncGroups($id, $groupIds);
            return $id;
        });
        audit('user_create', 'user:' . $id . ' ' . $data['email']);
        $msg = 'Le compte de ' . $data['name'] . ' a été créé.';
        if (!empty($_POST['send_invite']) && $data['is_active']) {
            $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
            if (AuthController::sendResetLink($u, true)) {
                audit('user_invite', 'user:' . $id);
                $msg .= ' Une invitation a été envoyée à ' . $data['email'] . '.';
            } else {
                flash("L'invitation n'a pas pu être envoyée. Réessayez depuis la fiche de la personne.", 'error');
            }
        } else {
            $msg .= ' Pensez à lui envoyer une invitation pour qu\'elle choisisse son mot de passe.';
        }
        flash($msg);
        redirect('/admin/utilisateurs/' . $id);
    }

    public function edit(string $id): void
    {
        $u = $this->findUser((int) $id);
        $this->view('admin/users/form', [
            'u' => $u,
            'userGroups' => $this->userGroupIds((int) $u['id']),
            'groups' => $this->allGroups(),
            'errors' => [],
            'isSelf' => (int) $u['id'] === Auth::id(),
            'pendingInvite' => (bool) Db::value('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND expires_at > ?', [$u['id'], Db::now()]),
        ]);
    }

    public function update(string $id): void
    {
        $u = $this->findUser((int) $id);
        [$data, $groupIds, $errors] = $this->validated($u);
        if ($errors) {
            $this->view('admin/users/form', [
                'u' => $data + $u,
                'userGroups' => $groupIds,
                'groups' => $this->allGroups(),
                'errors' => $errors,
                'isSelf' => (int) $u['id'] === Auth::id(),
            ], 422);
            return;
        }
        if (!empty($_POST['reset_2fa'])) {
            $data['totp_secret'] = null;
        }
        Db::transaction(function () use ($u, $data, $groupIds) {
            Db::update('users', $data, 'id = :id', ['id' => $u['id']]);
            $this->syncGroups((int) $u['id'], $groupIds);
            if (!$data['is_active']) {
                Db::run('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
            }
        });
        audit('user_update', 'user:' . $u['id'] . ' ' . $data['email']);
        if (!empty($_POST['reset_2fa']) && !empty($u['totp_secret'])) {
            audit('2fa_reset', 'user:' . $u['id']);
        }
        flash('Les modifications ont été enregistrées.');
        redirect('/admin/utilisateurs/' . $u['id']);
    }

    public function sendInvite(string $id): void
    {
        $u = $this->findUser((int) $id);
        if ((int) $u['is_active'] !== 1) {
            flash("Ce compte est désactivé: réactivez-le avant d'envoyer une invitation.", 'error');
        } elseif (AuthController::sendResetLink($u, true)) {
            audit('user_invite', 'user:' . $u['id']);
            flash('Un e-mail a été envoyé à ' . $u['email'] . ' avec un lien (valable 7 jours) pour choisir son mot de passe.');
        } else {
            flash("L'e-mail n'a pas pu être envoyé. Vérifiez l'adresse ou réessayez plus tard.", 'error');
        }
        redirect('/admin/utilisateurs/' . $u['id']);
    }

    public function destroy(string $id): void
    {
        $u = $this->findUser((int) $id);
        $mode = $this->input('mode', 'deactivate');
        if ((int) $u['id'] === Auth::id()) {
            flash('Vous ne pouvez pas désactiver ou supprimer votre propre compte.', 'error');
            redirect('/admin/utilisateurs/' . $u['id']);
        }
        if ($u['role'] === 'admin' && (int) $u['is_active'] === 1 && $this->otherActiveAdmins((int) $u['id']) === 0) {
            flash('Il doit toujours rester au moins un administrateur actif.', 'error');
            redirect('/admin/utilisateurs/' . $u['id']);
        }
        if ($mode === 'delete') {
            if (empty($_POST['confirm'])) {
                flash('Cochez la case de confirmation pour supprimer définitivement le compte.', 'error');
                redirect('/admin/utilisateurs/' . $u['id']);
            }
            Db::transaction(function () use ($u) {
                Db::run('DELETE FROM user_group_members WHERE user_id = ?', [$u['id']]);
                Db::run('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
                Db::run('DELETE FROM users WHERE id = ?', [$u['id']]);
            });
            audit('user_delete', 'user:' . $u['id'] . ' ' . $u['email']);
            flash('Le compte de ' . $u['name'] . ' a été supprimé définitivement.');
            redirect('/admin/utilisateurs');
        }
        Db::update('users', ['is_active' => 0], 'id = :id', ['id' => $u['id']]);
        Db::run('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
        audit('user_deactivate', 'user:' . $u['id'] . ' ' . $u['email']);
        flash('Le compte de ' . $u['name'] . ' est désactivé: la personne ne peut plus se connecter. Vous pouvez le réactiver à tout moment.');
        redirect('/admin/utilisateurs/' . $u['id']);
    }

    // ------------------------------------------------------------------ import CSV

    public function importForm(): void
    {
        unset($_SESSION['user_import']);
        $this->view('admin/users/import', ['preview' => null, 'groups' => $this->allGroups(), 'error' => null]);
    }

    public function import(): void
    {
        $step = $this->input('step', 'preview');

        if ($step === 'confirm') {
            $pending = $_SESSION['user_import'] ?? null;
            if (!is_array($pending) || !hash_equals((string) ($pending['key'] ?? ''), $this->input('key'))) {
                flash("L'aperçu a expiré. Veuillez recharger le fichier.", 'error');
                redirect('/admin/utilisateurs/import');
            }
            unset($_SESSION['user_import']);
            $sendInvites = !empty($_POST['send_invites']);
            $created = 0;
            $invited = 0;
            $skipped = 0;
            foreach ($pending['rows'] as $row) {
                if (Db::one('SELECT id FROM users WHERE email = ?', [$row['email']])) {
                    $skipped++;
                    continue;
                }
                $id = Db::transaction(function () use ($row) {
                    $id = Db::insert('users', [
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'role' => $row['role'],
                        'is_active' => 1,
                        'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                        'created_at' => Db::now(),
                    ]);
                    $this->syncGroups($id, $row['group_ids']);
                    return $id;
                });
                $created++;
                if ($sendInvites) {
                    $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
                    if (AuthController::sendResetLink($u, true)) {
                        $invited++;
                    }
                }
            }
            audit('user_import', "$created créé(s), $invited invitation(s)");
            $msg = "$created compte(s) créé(s).";
            if ($sendInvites) {
                $msg .= " $invited invitation(s) envoyée(s).";
            } else {
                $msg .= ' Les invitations pourront être envoyées depuis la fiche de chaque personne.';
            }
            if ($skipped) {
                $msg .= " $skipped ligne(s) ignorée(s) car le compte existait déjà.";
            }
            flash($msg);
            redirect('/admin/utilisateurs');
        }

        // Étape 1: lecture du fichier (ou texte collé) et aperçu
        $content = '';
        $f = $_FILES['csv'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
            if ($f['size'] > 1024 * 1024) {
                $this->view('admin/users/import', ['preview' => null, 'groups' => $this->allGroups(), 'error' => 'Le fichier est trop volumineux (1 Mo au maximum).'], 422);
                return;
            }
            $content = (string) file_get_contents($f['tmp_name']);
        } elseif (trim((string) ($_POST['csv_text'] ?? '')) !== '') {
            $content = (string) $_POST['csv_text'];
        }
        if (trim($content) === '') {
            $this->view('admin/users/import', ['preview' => null, 'groups' => $this->allGroups(), 'error' => 'Choisissez un fichier CSV ou collez son contenu.'], 422);
            return;
        }
        $existing = array_column(Db::all('SELECT email FROM users'), 'email');
        $preview = self::parseImport($content, $this->allGroups(), $existing);
        $key = bin2hex(random_bytes(16));
        $_SESSION['user_import'] = ['key' => $key, 'rows' => $preview['rows']];
        $this->view('admin/users/import', ['preview' => $preview + ['key' => $key], 'groups' => $this->allGroups(), 'error' => null]);
    }

    /**
     * Analyse un CSV « nom;email;groupes;role ».
     * - séparateur « ; » ou « , » détecté automatiquement; UTF-8 avec ou sans BOM (Windows-1252 converti);
     * - groupes séparés par des virgules, reconnus par nom ou identifiant court (slug), sans tenir compte de la casse;
     * - rôle: admin/administrateur ou membre/member (vide = membre);
     * - ligne d'en-tête ignorée si elle ne contient pas d'adresse e-mail.
     * @param array<int,array{id:int|string,name:string,slug:string}> $groups
     * @param string[] $existingEmails
     * @return array{rows: array<int,array>, errors: array<int,array{line:int,raw:string,message:string}>, skipped: array<int,array{line:int,email:string}>, separator: string}
     */
    public static function parseImport(string $content, array $groups, array $existingEmails = []): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $firstLine = strtok(ltrim($content, "\n"), "\n") ?: '';
        $sep = substr_count($firstLine, ';') >= 1 ? ';' : (substr_count($firstLine, "\t") >= 1 && substr_count($firstLine, ',') === 0 ? "\t" : ',');

        $byKey = [];
        foreach ($groups as $g) {
            $byKey[self::norm((string) $g['name'])] = (int) $g['id'];
            $byKey[self::norm((string) $g['slug'])] = (int) $g['id'];
        }
        $existing = array_flip(array_map('mb_strtolower', $existingEmails));

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $rows = [];
        $errors = [];
        $skipped = [];
        $seen = [];
        $line = 0;
        while (($cols = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $line++;
            if ($cols === [null] || trim(implode('', array_map('strval', $cols))) === '') {
                continue;
            }
            $cols = array_map(fn($c) => trim((string) $c), $cols);
            $raw = implode($sep, $cols);
            if (count($rows) + count($errors) + count($skipped) >= self::MAX_IMPORT_ROWS) {
                $errors[] = ['line' => $line, 'raw' => $raw, 'message' => 'Trop de lignes (' . self::MAX_IMPORT_ROWS . ' au maximum). Les lignes suivantes sont ignorées.'];
                break;
            }
            // Séparateur « , » et groupes non entourés de guillemets: les colonnes en trop sont des groupes.
            if (count($cols) > 4) {
                $last = $cols[count($cols) - 1];
                $hasRole = self::role($last) !== null;
                $groupsCol = implode(',', array_slice($cols, 2, count($cols) - 2 - ($hasRole ? 1 : 0)));
                $cols = [$cols[0], $cols[1], $groupsCol, $hasRole ? $last : ''];
            }
            $name = $cols[0] ?? '';
            $email = mb_strtolower($cols[1] ?? '');
            $groupsCol = $cols[2] ?? '';
            $roleCol = $cols[3] ?? '';

            if ($line === 1 && !str_contains($email, '@')) {
                continue; // en-tête
            }
            $problems = [];
            if ($name === '') {
                $problems[] = 'nom manquant';
            } elseif (mb_strlen($name) > 190) {
                $problems[] = 'nom trop long';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $problems[] = $email === '' ? 'adresse e-mail manquante' : "adresse e-mail invalide « $email »";
            }
            $role = self::role($roleCol);
            if ($role === null) {
                $problems[] = "rôle inconnu « $roleCol » (indiquer membre ou admin)";
            }
            $gids = [];
            $gnames = [];
            foreach (array_filter(array_map('trim', explode(',', $groupsCol)), 'strlen') as $gname) {
                $k = self::norm($gname);
                if (!isset($byKey[$k])) {
                    $problems[] = "groupe inconnu « $gname »";
                } elseif (!in_array($byKey[$k], $gids, true)) {
                    $gids[] = $byKey[$k];
                    $gnames[] = $gname;
                }
            }
            if (!$problems && isset($seen[$email])) {
                $problems[] = 'adresse en double dans le fichier (ligne ' . $seen[$email] . ')';
            }
            if ($problems) {
                $errors[] = ['line' => $line, 'raw' => $raw, 'message' => ucfirst(implode(', ', $problems)) . '.'];
                continue;
            }
            $seen[$email] = $line;
            if (isset($existing[$email])) {
                $skipped[] = ['line' => $line, 'email' => $email];
                continue;
            }
            $rows[] = ['line' => $line, 'name' => $name, 'email' => $email, 'role' => $role, 'group_ids' => $gids, 'group_names' => $gnames];
        }
        fclose($fh);
        return ['rows' => $rows, 'errors' => $errors, 'skipped' => $skipped, 'separator' => $sep];
    }

    private static function role(string $v): ?string
    {
        $v = self::norm($v);
        return match ($v) {
            '', 'membre', 'member', 'm', 'utilisateur' => 'member',
            'admin', 'administrateur', 'administratrice', 'a' => 'admin',
            default => null,
        };
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return (string) preg_replace('/\s+/', ' ', $t !== false ? $t : $s);
    }

    // ------------------------------------------------------------------ groupes

    public function groups(): void
    {
        $groups = Db::all(
            'SELECT g.*, (SELECT COUNT(*) FROM user_group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = g.id AND u.is_active = 1) AS member_count,
                    (SELECT COUNT(*) FROM private_folder_groups fg WHERE fg.group_id = g.id) AS folder_count
             FROM user_groups g ORDER BY g.name'
        );
        $editId = (int) ($_GET['modifier'] ?? 0);
        $edit = $editId ? Db::one('SELECT * FROM user_groups WHERE id = ?', [$editId]) : null;
        $this->view('admin/users/groups', ['groups' => $groups, 'edit' => $edit, 'error' => null]);
    }

    public function saveGroup(): void
    {
        $id = (int) $this->input('id', '0');
        $name = mb_substr($this->input('name'), 0, 190);
        $desc = mb_substr($this->input('description'), 0, 1000);
        if ($name === '') {
            flash('Indiquez le nom du groupe.', 'error');
            redirect('/admin/groupes' . ($id ? '?modifier=' . $id : ''));
        }
        $dup = Db::one('SELECT id FROM user_groups WHERE LOWER(name) = ? AND id <> ?', [mb_strtolower($name), $id]);
        if ($dup) {
            flash('Un groupe porte déjà ce nom.', 'error');
            redirect('/admin/groupes' . ($id ? '?modifier=' . $id : ''));
        }
        if ($id && Db::one('SELECT id FROM user_groups WHERE id = ?', [$id])) {
            Db::update('user_groups', ['name' => $name, 'description' => $desc ?: null], 'id = :id', ['id' => $id]);
            audit('group_update', 'group:' . $id . ' ' . $name);
            flash('Le groupe « ' . $name . ' » a été modifié.');
        } else {
            $id = Db::insert('user_groups', ['name' => $name, 'slug' => $this->uniqueSlug($name), 'description' => $desc ?: null]);
            audit('group_create', 'group:' . $id . ' ' . $name);
            flash('Le groupe « ' . $name . ' » a été créé.');
        }
        redirect('/admin/groupes');
    }

    public function deleteGroup(string $id): void
    {
        $g = Db::one('SELECT * FROM user_groups WHERE id = ?', [(int) $id]);
        if (!$g) {
            $this->notFound();
            return;
        }
        Db::transaction(function () use ($g) {
            Db::run('DELETE FROM user_group_members WHERE group_id = ?', [$g['id']]);
            Db::run('DELETE FROM private_folder_groups WHERE group_id = ?', [$g['id']]);
            Db::run('DELETE FROM user_groups WHERE id = ?', [$g['id']]);
        });
        PrivateAccess::flush();
        audit('group_delete', 'group:' . $g['id'] . ' ' . $g['name']);
        flash('Le groupe « ' . $g['name'] . ' » a été supprimé. Ses membres n\'ont plus accès aux dossiers qui lui étaient réservés.');
        redirect('/admin/groupes');
    }

    // ------------------------------------------------------------------ interne

    private function findUser(int $id): array
    {
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) {
            $this->notFound('Ce compte n\'existe pas.');
            exit;
        }
        return $u;
    }

    /** @return array{0:array,1:int[],2:string[]} */
    private function validated(?array $existing): array
    {
        $name = mb_substr($this->input('name'), 0, 190);
        $email = mb_strtolower($this->input('email'));
        $role = $this->input('role') === 'admin' ? 'admin' : 'member';
        $active = !empty($_POST['is_active']) ? 1 : 0;
        $valid = array_map('intval', array_column($this->allGroups(), 'id'));
        $groupIds = array_values(array_intersect(array_map('intval', (array) ($_POST['groups'] ?? [])), $valid));
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Indiquez le nom et le prénom.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = 'Indiquez une adresse e-mail valide.';
        } elseif (Db::one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int) ($existing['id'] ?? 0)])) {
            $errors['email'] = 'Un compte existe déjà avec cette adresse e-mail.';
        }
        if ($existing) {
            $isSelf = (int) $existing['id'] === Auth::id();
            if ($isSelf && $role !== 'admin') {
                $errors['role'] = 'Vous ne pouvez pas retirer votre propre rôle d\'administrateur.';
            }
            if ($isSelf && !$active) {
                $errors['is_active'] = 'Vous ne pouvez pas désactiver votre propre compte.';
            }
            $wasActiveAdmin = $existing['role'] === 'admin' && (int) $existing['is_active'] === 1;
            if ($wasActiveAdmin && ($role !== 'admin' || !$active) && $this->otherActiveAdmins((int) $existing['id']) === 0) {
                $errors['role'] = 'Il doit toujours rester au moins un administrateur actif.';
            }
        }
        return [['name' => $name, 'email' => $email, 'role' => $role, 'is_active' => $active], $groupIds, $errors];
    }

    private function otherActiveAdmins(int $exceptId): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$exceptId]);
    }

    /** @param int[] $groupIds */
    private function syncGroups(int $userId, array $groupIds): void
    {
        Db::run('DELETE FROM user_group_members WHERE user_id = ?', [$userId]);
        foreach (array_unique($groupIds) as $gid) {
            Db::insert('user_group_members', ['user_id' => $userId, 'group_id' => (int) $gid]);
        }
    }

    /** @return int[] */
    private function userGroupIds(int $userId): array
    {
        return array_map('intval', array_column(Db::all('SELECT group_id FROM user_group_members WHERE user_id = ?', [$userId]), 'group_id'));
    }

    private function allGroups(): array
    {
        return Db::all('SELECT * FROM user_groups ORDER BY name');
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', self::norm($name)), '-') ?: 'groupe';
        $slug = $base;
        $i = 2;
        while (Db::one('SELECT id FROM user_groups WHERE slug = ?', [$slug])) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
