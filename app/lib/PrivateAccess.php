<?php
declare(strict_types=1);

namespace App;

/**
 * Règles d'accès à l'espace sécurisé.
 * - Un administrateur accède à tout.
 * - Un membre accède à un dossier si l'un de ses groupes est autorisé sur ce dossier
 *   ou sur l'un de ses dossiers parents (héritage).
 * - Sans compte, sans groupe ou compte inactif : aucun accès.
 */
final class PrivateAccess
{
    /** @var array<int,array<string,mixed>>|null dossiers indexés par id */
    private static ?array $folders = null;
    /** @var array<int,int[]> groupes autorisés directement, par dossier */
    private static array $folderGroups = [];

    /** Vide le cache (après modification des dossiers ou des droits). */
    public static function flush(): void
    {
        self::$folders = null;
        self::$folderGroups = [];
    }

    private static function load(): void
    {
        if (self::$folders !== null) {
            return;
        }
        self::$folders = [];
        foreach (Db::all('SELECT * FROM private_folders ORDER BY sort_order, name') as $f) {
            self::$folders[(int) $f['id']] = $f;
        }
        self::$folderGroups = [];
        foreach (Db::all('SELECT folder_id, group_id FROM private_folder_groups') as $r) {
            self::$folderGroups[(int) $r['folder_id']][] = (int) $r['group_id'];
        }
    }

    /** @return array<int,array<string,mixed>> tous les dossiers, indexés par id */
    public static function allFolders(): array
    {
        self::load();
        return self::$folders;
    }

    public static function folder(int $id): ?array
    {
        self::load();
        return self::$folders[$id] ?? null;
    }

    public static function isAdmin(?array $user): bool
    {
        return $user !== null && (int) ($user['is_active'] ?? 0) === 1 && ($user['role'] ?? '') === 'admin';
    }

    /** @return int[] groupes de l'utilisateur */
    public static function userGroupIds(?array $user): array
    {
        if ($user === null || empty($user['id']) || (int) ($user['is_active'] ?? 0) !== 1) {
            return [];
        }
        if (isset($user['_group_ids']) && is_array($user['_group_ids'])) {
            return $user['_group_ids'];
        }
        return array_map('intval', array_column(
            Db::all('SELECT group_id FROM user_group_members WHERE user_id = ?', [(int) $user['id']]),
            'group_id'
        ));
    }

    /** Ids du dossier et de ses parents, du dossier vers la racine (protégé contre les boucles). @return int[] */
    public static function ancestry(int $folderId): array
    {
        self::load();
        $ids = [];
        $id = $folderId;
        while ($id && isset(self::$folders[$id]) && !in_array($id, $ids, true)) {
            $ids[] = $id;
            $id = (int) (self::$folders[$id]['parent_id'] ?? 0);
        }
        return $ids;
    }

    /** @return int[] groupes autorisés directement sur ce dossier */
    public static function directGroupIds(int $folderId): array
    {
        self::load();
        return self::$folderGroups[$folderId] ?? [];
    }

    /** @return int[] groupes autorisés par héritage (dossiers parents uniquement) */
    public static function inheritedGroupIds(int $folderId): array
    {
        $g = [];
        foreach (array_slice(self::ancestry($folderId), 1) as $aid) {
            $g = array_merge($g, self::directGroupIds($aid));
        }
        return array_values(array_unique($g));
    }

    /** @param int[]|null $userGroups groupes pré-calculés */
    public static function canAccessFolder(int $folderId, ?array $user, ?array $userGroups = null): bool
    {
        self::load();
        if (!isset(self::$folders[$folderId]) || $user === null) {
            return false;
        }
        if (self::isAdmin($user)) {
            return true;
        }
        $mine = $userGroups ?? self::userGroupIds($user);
        if (!$mine) {
            return false;
        }
        foreach (self::ancestry($folderId) as $id) {
            if (array_intersect(self::directGroupIds($id), $mine)) {
                return true;
            }
        }
        return false;
    }

    /** @return int[] tous les dossiers accessibles à l'utilisateur */
    public static function accessibleFolderIds(?array $user): array
    {
        self::load();
        if ($user === null) {
            return [];
        }
        $groups = self::userGroupIds($user);
        $ids = [];
        foreach (array_keys(self::$folders) as $id) {
            if (self::canAccessFolder($id, $user, $groups)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Dossiers à afficher sous $parentId (null = accueil de l'espace membres).
     * À l'accueil, on montre les dossiers accessibles « les plus hauts »: ceux dont le parent
     * n'existe pas ou n'est pas accessible (un membre peut n'avoir accès qu'à un sous-dossier).
     * @return array<int,array<string,mixed>>
     */
    public static function visibleFolders(?array $user, ?int $parentId): array
    {
        self::load();
        if ($user === null) {
            return [];
        }
        $groups = self::userGroupIds($user);
        $out = [];
        foreach (self::$folders as $id => $f) {
            if (!self::canAccessFolder($id, $user, $groups)) {
                continue;
            }
            $pid = $f['parent_id'] !== null ? (int) $f['parent_id'] : null;
            if ($parentId === null) {
                if ($pid === null || !self::canAccessFolder($pid, $user, $groups)) {
                    $out[] = $f;
                }
            } elseif ($pid === $parentId) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * Fil d'Ariane de la racine jusqu'au dossier (inclus).
     * @return array<int,array{id:int,name:string}>
     */
    public static function breadcrumb(int $folderId): array
    {
        self::load();
        $out = [];
        foreach (array_reverse(self::ancestry($folderId)) as $id) {
            $out[] = ['id' => $id, 'name' => (string) self::$folders[$id]['name']];
        }
        return $out;
    }

    public static function canDownloadFile(int $fileId, ?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        $folderId = Db::value('SELECT folder_id FROM private_files WHERE id = ?', [$fileId]);
        return $folderId !== null && self::canAccessFolder((int) $folderId, $user);
    }

    /** Ids du dossier et de tous ses descendants. @return int[] */
    public static function descendantIds(int $folderId): array
    {
        self::load();
        $out = [$folderId];
        for ($i = 0; $i < count($out); $i++) {
            foreach (self::$folders as $id => $f) {
                if ((int) ($f['parent_id'] ?? 0) === $out[$i] && !in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }
}
