<?php
declare(strict_types=1);

namespace App;

/**
 * Mises à jour de la base pour les sites déjà installés (appelées à la première visite
 * après une mise à jour du code). Chaque étape est idempotente; la version atteinte
 * est notée dans le réglage « schema_version ».
 */
final class Migrations
{
    public const VERSION = 3;

    public static function run(): void
    {
        try {
            $current = (int) Settings::get('schema_version');
        } catch (\Throwable $e) {
            return; // base pas encore installée
        }
        if ($current >= self::VERSION) {
            return;
        }
        for ($v = $current + 1; $v <= self::VERSION; $v++) {
            try {
                self::{'v' . $v}();
            } catch (\Throwable $e) {
                error_log("Migration v$v : " . $e->getMessage());
                return; // on réessaiera à la prochaine visite
            }
            Settings::set('schema_version', (string) $v);
        }
    }

    /** Image d'en-tête et date d'actualité sur les pages. */
    private static function v1(): void
    {
        self::addColumn('pages', 'cover_media_id', 'INTEGER NULL');
        self::addColumn('pages', 'news_date', 'DATE NULL');
    }

    /** Rubrique « Actualités » (les actualités sont ses sous-pages). */
    private static function v2(): void
    {
        if (Db::value('SELECT id FROM pages WHERE path = ?', [Pages::NEWS_PATH]) !== null) {
            return;
        }
        $slug = ltrim(Pages::NEWS_PATH, '/');
        Db::insert('pages', [
            'parent_id' => null,
            'slug' => $slug,
            'path' => Pages::NEWS_PATH,
            'title' => 'Actualités',
            'menu_title' => null,
            'meta_description' => 'Les dernières nouvelles de l’ASIJ.',
            'blocks' => '[]',
            'status' => 'published',
            'show_in_menu' => 0, // mise en avant sur l'accueil et dans le pied de page
            'sort_order' => 1 + (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE parent_id IS NULL'),
            'updated_at' => Db::now(),
            'updated_by' => null,
        ]);
    }

    /**
     * Le contact de l'accueil (bloc « Personnes ») passe dans le pied de page:
     * nom et fonction copiés dans les réglages, bloc retiré de l'accueil (version gardée).
     */
    private static function v3(): void
    {
        $home = Db::one("SELECT * FROM pages WHERE path = '/'");
        if (!$home) {
            return;
        }
        $blocks = json_decode((string) $home['blocks'], true);
        if (!is_array($blocks)) {
            return;
        }
        $i = null;
        foreach ($blocks as $k => $b) {
            if (($b['type'] ?? '') === 'people' && !empty($b['data']['items'][0]['name'])) {
                $i = $k;
                break;
            }
        }
        if ($i === null) {
            return;
        }
        $person = $blocks[$i]['data']['items'][0];
        if (Settings::get('contact_name') === '') {
            Settings::set('contact_name', (string) $person['name']);
            Settings::set('contact_role', (string) ($person['role'] ?? ''));
        }
        $remove = [$i];
        $prev = $blocks[$i - 1] ?? null;
        if ($prev && ($prev['type'] ?? '') === 'heading' && preg_match('/contact/i', (string) ($prev['data']['text'] ?? ''))) {
            $remove[] = $i - 1;
        }
        $next = $blocks[$i + 1] ?? null;
        if ($next && ($next['type'] ?? '') === 'button' && str_starts_with((string) ($next['data']['url'] ?? ''), '/contact')) {
            $remove[] = $i + 1;
        }
        Db::insert('page_revisions', [
            'page_id' => (int) $home['id'], 'title' => $home['title'], 'blocks' => $home['blocks'],
            'created_at' => Db::now(), 'created_by' => null,
        ]);
        foreach ($remove as $k) {
            unset($blocks[$k]);
        }
        $admin = Db::value("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $data = [
            'blocks' => json_encode(array_values($blocks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Db::now(),
            // Marquée comme modifiée dans l'administration: un nouvel import ne remettra pas le bloc.
            'updated_by' => $admin !== null ? (int) $admin : null,
        ];
        if (!str_starts_with((string) $home['title'], 'Bienvenue') && $home['updated_by'] === null) {
            $data['title'] = "Bienvenue sur le site de l'ASIJ"; // l'ancien import reprenait une actualité
        }
        Db::update('pages', $data, 'id = :id', ['id' => (int) $home['id']]);
    }

    private static function addColumn(string $table, string $col, string $def): void
    {
        if (Db::driver() === 'sqlite') {
            foreach (Db::all("PRAGMA table_info($table)") as $c) {
                if ($c['name'] === $col) {
                    return;
                }
            }
        } elseif (Db::value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $col])) {
            return;
        }
        if (Db::driver() !== 'sqlite') {
            $def = str_replace('INTEGER', 'INT UNSIGNED', $def);
        }
        Db::pdo()->exec("ALTER TABLE $table ADD COLUMN $col $def");
    }
}
