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
    public const VERSION = 4;

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

    /**
     * Accueil importé de Jimdo: le contact y est un texte (« Contacter l'ASIJ »), pas un bloc
     * « Personnes », et les nouvelles sont des sections à titre. On copie le contact dans les
     * réglages du pied de page et chaque section à titre devient une actualité.
     * Une version de l'accueil est gardée (« Versions précédentes »).
     */
    private static function v4(): void
    {
        $home = Db::one("SELECT * FROM pages WHERE path = '/'");
        $newsId = Db::value('SELECT id FROM pages WHERE path = ?', [Pages::NEWS_PATH]);
        if (!$home || $newsId === null) {
            return;
        }
        $blocks = json_decode((string) $home['blocks'], true);
        if (!is_array($blocks) || !$blocks) {
            return;
        }
        $blocks = array_values($blocks);
        $changed = false;

        // 1. Contact
        $start = null;
        foreach ($blocks as $k => $b) {
            $t = self::plain($b);
            if (in_array($b['type'] ?? '', ['heading', 'text'], true) && preg_match('/^\s*contact(er|ez)?\b/iu', $t)) {
                $start = $k;
                break;
            }
        }
        if ($start !== null) {
            $end = $start;
            $html = '';
            for ($k = $start; $k < count($blocks); $k++) {
                $type = $blocks[$k]['type'] ?? '';
                if ($k > $start && in_array($type, ['heading', 'separator'], true)) {
                    break;
                }
                if ($type === 'text') {
                    $html .= "\n" . (string) ($blocks[$k]['data']['html'] ?? '');
                } elseif (!in_array($type, ['heading', 'contact', 'button'], true)) {
                    break;
                }
                $end = $k;
            }
            $c = self::parseContact($html);
            if ($c['name'] !== '') {
                foreach (['name', 'role', 'email', 'phone'] as $f) {
                    if ($c[$f] !== '' && trim(Settings::get('contact_' . $f)) === '') {
                        Settings::set('contact_' . $f, $c[$f]);
                    }
                }
                array_splice($blocks, $start, $end - $start + 1);
                $changed = true;
            }
        }

        // 2. Sections à titre (niveau 2) → actualités, seulement s'il n'y en a encore aucune
        $hasNews = (int) Db::value('SELECT COUNT(*) FROM pages WHERE parent_id = ?', [(int) $newsId]) > 0;
        $sections = [];
        $keep = [];
        $cur = null;
        foreach ($blocks as $b) {
            $isH2 = ($b['type'] ?? '') === 'heading' && (int) ($b['data']['level'] ?? 2) === 2;
            if ($isH2 && !$hasNews && !preg_match('/bienvenue|trouver une information/iu', self::plain($b))) {
                if ($cur !== null) {
                    $sections[] = $cur;
                }
                $cur = ['title' => self::plain($b), 'blocks' => []];
                continue;
            }
            if ($cur !== null && !($isH2)) {
                $cur['blocks'][] = $b;
            } else {
                if ($cur !== null) {
                    $sections[] = $cur;
                    $cur = null;
                }
                $keep[] = $b;
            }
        }
        if ($cur !== null) {
            $sections[] = $cur;
        }
        $admin = Db::value("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $admin = $admin !== null ? (int) $admin : null;
        if ($sections) {
            $blocks = $keep;
            $changed = true;
            $day = time();
            foreach ($sections as $i => $sec) {
                $body = array_values(array_filter($sec['blocks'], fn($b) => ($b['type'] ?? '') !== 'separator'));
                $cover = null;
                foreach ($body as $k => $b) {
                    if (($b['type'] ?? '') === 'image' && !empty($b['data']['media_id'])) {
                        $cover = (int) $b['data']['media_id'];
                        array_splice($body, $k, 1);
                        break;
                    }
                }
                $summary = '';
                foreach ($body as $b) {
                    if (in_array($b['type'] ?? '', ['text', 'heading', 'callout'], true) && ($t = self::plain($b)) !== '') {
                        $summary = $t;
                        break;
                    }
                }
                if (mb_strlen($summary) > 200) {
                    $summary = rtrim(mb_substr($summary, 0, 197)) . '…';
                }
                $title = mb_substr($sec['title'] !== '' ? $sec['title'] : 'Actualité', 0, 255);
                $slug = Pages::uniqueSlug((int) $newsId, Pages::slugify($title));
                Db::insert('pages', [
                    'parent_id' => (int) $newsId,
                    'slug' => $slug,
                    'path' => Pages::computePath((int) $newsId, $slug),
                    'title' => $title,
                    'menu_title' => null,
                    'meta_description' => $summary,
                    'blocks' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => 'published',
                    'show_in_menu' => 0,
                    'sort_order' => $i + 1,
                    'cover_media_id' => $cover,
                    'news_date' => date('Y-m-d', $day - $i * 86400), // même ordre que sur l'ancien accueil
                    'updated_at' => Db::now(),
                    'updated_by' => $admin, // un nouvel import ne les écrasera pas
                ]);
            }
        }
        if (!$changed) {
            return;
        }
        while ($blocks && (end($blocks)['type'] ?? '') === 'separator') {
            array_pop($blocks);
        }
        Db::insert('page_revisions', [
            'page_id' => (int) $home['id'], 'title' => $home['title'], 'blocks' => $home['blocks'],
            'created_at' => Db::now(), 'created_by' => null,
        ]);
        Db::update('pages', [
            'blocks' => json_encode(array_values($blocks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Db::now(),
            'updated_by' => $admin ?? ($home['updated_by'] !== null ? (int) $home['updated_by'] : null),
        ], 'id = :id', ['id' => (int) $home['id']]);
    }

    /** Texte brut d'un bloc titre/texte/encadré. */
    private static function plain(array $b): string
    {
        $d = $b['data'] ?? [];
        $raw = (string) ($d['text'] ?? ($d['html'] ?? ''));
        if (isset($d['html'])) {
            $raw = (string) ($d['title'] ?? '') . ' ' . $d['html'];
        }
        $t = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h\d)[^>]*>#i', ' ', $raw) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $t)) ?? '');
    }

    /** @return array{name:string,role:string,email:string,phone:string} */
    public static function parseContact(string $html): array
    {
        $out = ['name' => '', 'role' => '', 'email' => '', 'phone' => ''];
        if (preg_match('#mailto:([^"\'?\s>]+@[^"\'?\s>]+)#i', $html, $m)) {
            $out['email'] = rawurldecode($m[1]);
        }
        $txt = preg_replace('#<(br|/p|/div|/li|/h\d)[^>]*>#i', "\n", $html) ?? '';
        $txt = html_entity_decode(strip_tags($txt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = str_replace("\u{a0}", ' ', $txt);
        if ($out['email'] === '' && preg_match('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', $txt, $m)) {
            $out['email'] = $m[0];
        }
        if (preg_match_all('/(?<!\d)(?:\+41\s?|0)\d{2}(?:[\s.]?\d){7}(?!\d)/', $txt, $m)) {
            $out['phone'] = implode(' / ', array_unique(array_map('trim', $m[0])));
        }
        $lines = array_values(array_filter(array_map(fn($l) => trim($l, " \t:*"), explode("\n", $txt)), fn($l) => $l !== ''));
        foreach ($lines as $i => $l) {
            if (preg_match('/^contact/iu', $l) || preg_match('/\d|@|www\.|asij|chemin|route|rue|case postale/iu', $l)) {
                continue;
            }
            if (preg_match('/^\p{Lu}[\p{L}\'’.-]+(?:\s+(?:de\s+|von\s+|van\s+)?\p{Lu}[\p{L}\'’.-]+){1,3}$/u', $l)) {
                $out['name'] = $l;
                $next = $lines[$i + 1] ?? '';
                if ($next !== '' && !preg_match('/\d|@|www\./u', $next) && mb_strlen($next) <= 80) {
                    $out['role'] = $next;
                }
                break;
            }
        }
        return $out;
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
