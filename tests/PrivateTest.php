<?php
use App\Db;
use App\Totp;
use App\PrivateAccess;
use App\Controllers\AuthController;
use App\Controllers\AdminUsersController;
use App\Controllers\AdminPrivateController;

/** Petites fabriques pour les tests de l'espace sécurisé. */
$mkUser = function (string $email, string $role = 'member', int $active = 1): array {
    $id = Db::insert('users', ['email' => $email, 'name' => $email, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => $role, 'is_active' => $active, 'created_at' => Db::now()]);
    return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
};
$mkGroup = fn(string $slug) => Db::insert('user_groups', ['name' => 'G ' . $slug, 'slug' => $slug, 'description' => null]);
$mkFolder = fn(string $name, ?int $parent = null) => Db::insert('private_folders', ['name' => $name, 'parent_id' => $parent, 'sort_order' => 0, 'created_at' => Db::now()]);

return [
    'Totp: vecteurs RFC 6238 (SHA1, 8 chiffres)' => function () {
        $key = '12345678901234567890';
        $vectors = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'];
        foreach ($vectors as $t => $code) {
            check(Totp::codeFromKey($key, $t, 8) === $code, "t=$t attendu $code, obtenu " . Totp::codeFromKey($key, $t, 8));
        }
        // même chose via le secret base32 en 6 chiffres
        $b32 = Totp::base32Encode($key);
        check($b32 === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 RFC');
        check(Totp::base32Decode($b32) === $key, 'aller-retour base32');
        check(Totp::code($b32, 59) === '287082', 'code 6 chiffres');
    },
    'Totp: vérification avec fenêtre ±1 et rejets' => function () {
        $s = Totp::generateSecret();
        check(strlen($s) === 32 && preg_match('/^[A-Z2-7]+$/', $s) === 1, 'secret base32');
        $t = 1700000000;
        check(Totp::verify($s, Totp::code($s, $t), 1, $t), 'code courant');
        check(Totp::verify($s, Totp::code($s, $t - 30), 1, $t), 'code précédent');
        check(Totp::verify($s, Totp::code($s, $t + 30), 1, $t), 'code suivant');
        check(!Totp::verify($s, Totp::code($s, $t - 90), 1, $t), 'code trop vieux');
        check(!Totp::verify($s, 'abcdef', 1, $t) && !Totp::verify($s, '', 1, $t), 'format');
        $c = Totp::code($s, $t);
        check(Totp::verify($s, substr($c, 0, 3) . ' ' . substr($c, 3), 1, $t), 'espaces tolérés');
        check(Totp::formatSecret('ABCDEFGHIJ') === 'ABCD EFGH IJ', 'groupement par 4');
        $uri = Totp::uri($s, 'marie@exemple.ch');
        check(str_starts_with($uri, 'otpauth://totp/ASIJ:marie%40exemple.ch?secret=' . $s) && str_contains($uri, 'issuer=ASIJ'), 'uri');
    },
    'Accès: héritage des droits, sans groupe, admin, inactif' => function () use ($mkUser, $mkGroup, $mkFolder) {
        $g1 = $mkGroup('t-conseil');
        $g2 = $mkGroup('t-codir');
        $root = $mkFolder('Conseil');
        $child = $mkFolder('Séances 2026', $root);
        $grand = $mkFolder('Annexes', $child);
        $other = $mkFolder('CoDir');
        Db::insert('private_folder_groups', ['folder_id' => $root, 'group_id' => $g1]);
        Db::insert('private_folder_groups', ['folder_id' => $other, 'group_id' => $g2]);
        PrivateAccess::flush();

        $m = $mkUser('membre@t.ch');
        Db::insert('user_group_members', ['user_id' => $m['id'], 'group_id' => $g1]);
        check(PrivateAccess::canAccessFolder($root, $m), 'dossier direct');
        check(PrivateAccess::canAccessFolder($grand, $m), 'petit-enfant hérité');
        check(!PrivateAccess::canAccessFolder($other, $m), 'autre groupe refusé');
        check(!PrivateAccess::canAccessFolder(999999, $m), 'dossier inexistant');
        check(PrivateAccess::inheritedGroupIds($grand) === [$g1], 'groupes hérités');
        $roots = array_column(PrivateAccess::visibleFolders($m, null), 'id');
        check(in_array($root, $roots) && !in_array($other, $roots) && !in_array($child, $roots), 'racines visibles');
        check(array_column(PrivateAccess::visibleFolders($m, $root), 'id') == [$child], 'sous-dossiers visibles');
        check(array_column(PrivateAccess::breadcrumb($grand), 'id') === [$root, $child, $grand], "fil d'Ariane");

        // accès uniquement à un sous-dossier: il apparaît à l'accueil
        $m2 = $mkUser('sous@t.ch');
        $g3 = $mkGroup('t-annexes');
        Db::insert('user_group_members', ['user_id' => $m2['id'], 'group_id' => $g3]);
        Db::insert('private_folder_groups', ['folder_id' => $child, 'group_id' => $g3]);
        PrivateAccess::flush();
        check(!PrivateAccess::canAccessFolder($root, $m2) && PrivateAccess::canAccessFolder($grand, $m2), 'droit sur sous-dossier seulement');
        check(array_column(PrivateAccess::visibleFolders($m2, null), 'id') == [$child], 'sous-dossier remonté à l\'accueil');

        $none = $mkUser('aucun@t.ch');
        check(PrivateAccess::accessibleFolderIds($none) === [], 'membre sans groupe: aucun dossier');
        check(!PrivateAccess::canAccessFolder($root, $none), 'membre sans groupe: refus');
        check(!PrivateAccess::canAccessFolder($root, null), 'anonyme: refus');

        $admin = $mkUser('admin@t.ch', 'admin');
        check(PrivateAccess::canAccessFolder($other, $admin) && PrivateAccess::canAccessFolder($grand, $admin), 'admin: tout');
        check(count(PrivateAccess::accessibleFolderIds($admin)) === count(PrivateAccess::allFolders()), 'admin: tous les dossiers');

        $inactive = $mkUser('inactif@t.ch', 'member', 0);
        Db::insert('user_group_members', ['user_id' => $inactive['id'], 'group_id' => $g1]);
        check(!PrivateAccess::canAccessFolder($root, $inactive), 'compte inactif refusé');

        $fid = Db::insert('private_files', ['folder_id' => $grand, 'stored_name' => bin2hex(random_bytes(16)), 'original_name' => 'pv.pdf', 'title' => 'PV', 'mime' => 'application/pdf', 'size' => 3, 'sha256' => str_repeat('0', 64), 'created_at' => Db::now()]);
        check(PrivateAccess::canDownloadFile($fid, $m), 'téléchargement autorisé');
        check(!PrivateAccess::canDownloadFile($fid, $none), 'téléchargement refusé sans groupe');
        check(!PrivateAccess::canDownloadFile(987654, $admin), 'fichier inexistant');
        check(PrivateAccess::descendantIds($root) == [$root, $child, $grand], 'descendants');
    },
    'Jetons: valide, expiré refusé, format invalide' => function () use ($mkUser) {
        $u = $mkUser('jeton@t.ch');
        $tok = AuthController::createToken((int) $u['id'], 3600);
        check(strlen($tok) === 64, 'longueur');
        check(Db::value('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$tok]) == 0, 'jeton jamais stocké en clair');
        check((int) (AuthController::userForToken($tok)['id'] ?? 0) === (int) $u['id'], 'jeton valide');
        $old = bin2hex(random_bytes(32));
        Db::insert('password_resets', ['token_hash' => hash('sha256', $old), 'user_id' => $u['id'], 'expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        check(AuthController::userForToken($old) === null, 'jeton expiré refusé');
        check(AuthController::userForToken('../../etc') === null, 'format invalide');
        Db::update('users', ['is_active' => 0], 'id = :id', ['id' => $u['id']]);
        check(AuthController::userForToken($tok) === null, 'compte désactivé refusé');
    },
    'Redirection après connexion: interne uniquement' => function () {
        check(AuthController::safeRedirect('/espace-membres/dossier/3') === '/espace-membres/dossier/3');
        foreach (['//evil.ch', 'https://evil.ch', '/\\evil.ch', 'javascript:x', '', null, "/a\r\nLocation: x", '/connexion'] as $bad) {
            check(AuthController::safeRedirect($bad) === null, 'refusé: ' . var_export($bad, true));
        }
    },
    'Mailer: transport log' => function () {
        $f = sys_get_temp_dir() . '/asij-mail-test-' . getmypid() . '.log';
        @unlink($f);
        $cfg = require __DIR__ . '/config.test.php';
        $cfg['mail']['log_file'] = $f;
        App\Config::load($cfg);
        check(App\Mailer::send('marie@exemple.ch', 'Invitation – ASIJ', "Bonjour\nLien"), 'envoi');
        check(!App\Mailer::send("x@y.ch\r\nBcc: z@z.ch", 'S', 'B'), 'injection refusée');
        $log = (string) file_get_contents($f);
        check(str_contains($log, 'To: marie@exemple.ch') && str_contains($log, 'Invitation – ASIJ'), 'contenu journalisé');
        @unlink($f);
        App\Config::load(require __DIR__ . '/config.test.php');
    },
    'Import CSV: séparateurs, BOM, en-tête, erreurs' => function () {
        $groups = [['id' => 1, 'name' => 'Conseil intercommunal', 'slug' => 'conseil'], ['id' => 2, 'name' => 'Comité de direction', 'slug' => 'codir']];
        $csv = "\xEF\xBB\xBFnom;email;groupes;role\r\n"
            . "Marie Dupont;Marie.Dupont@Exemple.ch;Conseil intercommunal;membre\r\n"
            . "Jean Martin;jean@exemple.ch;conseil, CODIR;admin\r\n"
            . "Sans Mail;;conseil;\r\n"
            . "Groupe Faux;faux@exemple.ch;Inconnu;membre\r\n"
            . "Doublon;marie.dupont@exemple.ch;;\r\n"
            . "Déjà Là;deja@exemple.ch;;membre\r\n"
            . "\r\n"
            . "Rôle Faux;role@exemple.ch;;chef\r\n";
        $r = AdminUsersController::parseImport($csv, $groups, ['Deja@exemple.ch']);
        check($r['separator'] === ';', 'séparateur ;');
        check(count($r['rows']) === 2, 'lignes valides: ' . count($r['rows']));
        check($r['rows'][0]['email'] === 'marie.dupont@exemple.ch' && $r['rows'][0]['group_ids'] === [1] && $r['rows'][0]['role'] === 'member', 'ligne 1');
        check($r['rows'][1]['group_ids'] === [1, 2] && $r['rows'][1]['role'] === 'admin', 'groupes multiples + admin');
        check(count($r['errors']) === 4, 'erreurs: ' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
        $lines = array_column($r['errors'], 'line');
        check($lines === [4, 5, 6, 9], 'numéros de lignes en erreur: ' . implode(',', $lines));
        check(stripos($r['errors'][1]['message'], 'groupe inconnu') !== false, 'message groupe');
        check(count($r['skipped']) === 1 && $r['skipped'][0]['email'] === 'deja@exemple.ch', 'existant ignoré');

        // virgule, sans en-tête, groupes non entourés de guillemets, accents, Windows-1252
        $csv2 = "Anne Rochat,anne@exemple.ch,Conseil intercommunal,Comité de direction,membre\n"
            . "Luc Favre,luc@exemple.ch,\"conseil,codir\",\n"
            . "Hélène Cruz,helene@exemple.ch,Comite de direction\n";
        $r2 = AdminUsersController::parseImport($csv2, $groups);
        check($r2['separator'] === ',', 'séparateur ,');
        check(count($r2['rows']) === 3 && !$r2['errors'], 'csv virgule: ' . json_encode($r2, JSON_UNESCAPED_UNICODE));
        check($r2['rows'][0]['group_ids'] === [1, 2], 'colonnes de groupes en trop fusionnées');
        check($r2['rows'][1]['group_ids'] === [1, 2], 'groupes entre guillemets');
        check($r2['rows'][2]['group_ids'] === [2], 'accents ignorés pour les groupes');
        // Fichier Excel « CSV (point-virgule) » enregistré en Windows-1252
        $r3 = AdminUsersController::parseImport(mb_convert_encoding("Hélène Cruz;helene@exemple.ch;Comité de direction;membre\n", 'Windows-1252', 'UTF-8'), $groups);
        check(count($r3['rows']) === 1 && $r3['rows'][0]['name'] === 'Hélène Cruz' && $r3['rows'][0]['group_ids'] === [2], 'Windows-1252 converti');
    },
    'Upload: nettoyage des noms et normalisation de $_FILES' => function () {
        check(AdminPrivateController::cleanName('../../etc/passwd') === 'passwd', 'basename');
        check(AdminPrivateController::cleanName("PV \"séance\"\x01.pdf") === 'PV séance.pdf', 'caractères retirés');
        check(AdminPrivateController::titleFromName('PV_seance_2026.pdf') === 'PV seance 2026', 'titre');
        $f = ['name' => ['a.pdf', ''], 'tmp_name' => ['/tmp/x', ''], 'size' => [3, 0], 'error' => [0, UPLOAD_ERR_NO_FILE], 'type' => ['', '']];
        check(count(AdminPrivateController::normalizeFiles($f)) === 1, 'fichiers multiples');
        check(isset(AdminPrivateController::ALLOWED['pdf']) && !isset(AdminPrivateController::ALLOWED['php']) && !isset(AdminPrivateController::ALLOWED['html']) && !isset(AdminPrivateController::ALLOWED['svg']), 'liste blanche');
    },
];
