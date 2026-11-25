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
    public const VERSION = 10;

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

    /**
     * Encadré « Informations importantes » de l'accueil (ancienne colonne de droite de Jimdo,
     * importée en bas de la page Transports): page cachée éditable comme les autres.
     * Retire aussi le titre « Bienvenue… » du texte d'accueil (il répète le grand titre).
     */
    private static function v5(): void
    {
        $admin = Db::value("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $admin = $admin !== null ? (int) $admin : null;
        $enc = fn(array $b) => json_encode(array_values($b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (Db::value('SELECT id FROM pages WHERE path = ?', [Pages::INFOS_PATH]) === null) {
            $blocks = self::sidebarFromTransports();
            if (!$blocks) {
                $blocks = [
                    ['type' => 'heading', 'data' => ['text' => 'Informations Transports', 'level' => 3]],
                    ['type' => 'text', 'data' => ['html' => '<p>Horaires des bus de ligne&nbsp;: <a href="https://www.tp-info.ch/fr/horaire-actuel/rechercher-un-tableau-horaire#/" target="_blank" rel="noopener noreferrer">tp-info.ch</a></p>'
                        . '<p>Horaires des transports scolaires&nbsp;: <a href="https://www.ecolesdujorat.ch/transports/horaires/" target="_blank" rel="noopener noreferrer">ecolesdujorat.ch</a></p>'
                        . '<p>Horaires, changements de bus, trajets UAPE&nbsp;: <a href="mailto:asij@helvecie.ch">asij@helvecie.ch</a></p>'
                        . '<p>Problèmes sur le terrain et coordination ASIJ – HelvéCie&nbsp;: <strong>M. Louis Meyer</strong>, directeur d’exploitation, <a href="tel:0793376507">079 337 65 07</a></p>']],
                ];
            }
            foreach ($blocks as &$b) {
                $b = ['id' => Pages::newBlockId()] + $b;
            }
            unset($b);
            Db::insert('pages', [
                'parent_id' => null,
                'slug' => ltrim(Pages::INFOS_PATH, '/'),
                'path' => Pages::INFOS_PATH,
                'title' => 'Informations importantes',
                'menu_title' => null,
                'meta_description' => 'Informations urgentes et importantes affichées sur la page d’accueil.',
                'blocks' => $enc($blocks),
                'status' => 'published',
                'show_in_menu' => 0,
                'sort_order' => 2 + (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) FROM pages WHERE parent_id IS NULL'),
                'updated_at' => Db::now(),
                'updated_by' => $admin,
            ]);
        }

        // E-mail du contact masqué par Cloudflare lors de l'import: on le relit dans la version gardée
        $home = Db::one("SELECT * FROM pages WHERE path = '/'");
        if (!$home) {
            return;
        }
        if (trim(Settings::get('contact_email')) === '') {
            $sources = array_merge([(string) $home['blocks']], array_column(Db::all('SELECT blocks FROM page_revisions WHERE page_id = ? ORDER BY id DESC LIMIT 5', [(int) $home['id']]), 'blocks'));
            foreach ($sources as $json) {
                foreach ((array) json_decode($json, true) as $b) {
                    $h = (string) ($b['data']['html'] ?? '');
                    if ($h !== '' && preg_match('/contact/i', $h) && ($c = self::parseContact($h))['email'] !== '') {
                        Settings::set('contact_email', $c['email']);
                        break 2;
                    }
                }
            }
        }

        $blocks = json_decode((string) $home['blocks'], true);
        if (!is_array($blocks) || !$blocks) {
            return;
        }
        $blocks = array_values($blocks);
        $changed = false;
        foreach ($blocks as $k => $b) {
            if (($b['type'] ?? '') === 'heading') {
                if (preg_match('/^\s*bienvenue/iu', (string) ($b['data']['text'] ?? ''))) {
                    array_splice($blocks, $k, 1);
                    $changed = true;
                }
                break;
            }
            if (($b['type'] ?? '') === 'text') {
                $h = (string) ($b['data']['html'] ?? '');
                $new = preg_replace('#^\s*<h[2-4][^>]*>(?:(?!</h[2-4]>).)*bienvenue(?:(?!</h[2-4]>).)*</h[2-4]>#isu', '', $h, 1) ?? $h;
                if ($new !== $h) {
                    $blocks[$k]['data']['html'] = Html::tidy($new);
                    $changed = true;
                }
                break;
            }
        }
        if ($changed) {
            Db::insert('page_revisions', [
                'page_id' => (int) $home['id'], 'title' => $home['title'], 'blocks' => $home['blocks'],
                'created_at' => Db::now(), 'created_by' => null,
            ]);
            Db::update('pages', ['blocks' => $enc($blocks), 'updated_at' => Db::now(), 'updated_by' => $admin ?? $home['updated_by']], 'id = :id', ['id' => (int) $home['id']]);
        }
    }

    /** Encadré transports ajouté par l'import en bas de la page Transports (copie, l'original reste). */
    private static function sidebarFromTransports(): array
    {
        $page = Db::one("SELECT blocks FROM pages WHERE path = '/transports-scolaires'")
            ?? Db::one("SELECT blocks FROM pages WHERE parent_id IS NULL AND LOWER(title) LIKE '%transport%' ORDER BY sort_order LIMIT 1");
        $blocks = $page ? json_decode((string) $page['blocks'], true) : null;
        if (!is_array($blocks)) {
            return [];
        }
        $blocks = array_values($blocks);
        $at = null;
        foreach ($blocks as $k => $b) {
            if (($b['type'] ?? '') === 'callout' && preg_match('/transport/iu', (string) ($b['data']['title'] ?? ''))) {
                $at = $k; // le dernier trouvé
            }
        }
        if ($at === null) {
            return [];
        }
        $c = $blocks[$at]['data'];
        $html = Html::cfDecode((string) ($c['html'] ?? ''));
        $html = preg_replace('/Les\s+informations?\s+urgentes\s+et\s+importantes\s*:?/iu', '', $html) ?? $html;
        // lien dont le texte (tp-info.ch) ne correspond pas à la cible sur l'ancien site
        $html = preg_replace_callback('#<a\b([^>]*)href="[^"]*"([^>]*)>\s*(https?://[^<\s]+)\s*</a>#i', fn($m) => '<a' . $m[1] . 'href="' . $m[3] . '"' . $m[2] . '>' . preg_replace('/^www\./', '', (string) parse_url(html_entity_decode($m[3]), PHP_URL_HOST)) . '</a>', $html) ?? $html;
        $out = [['type' => 'heading', 'data' => ['text' => (string) ($c['title'] ?? 'Informations Transports'), 'level' => 3]]];
        if (trim(strip_tags(Html::tidy($html))) !== '') {
            $out[] = ['type' => 'text', 'data' => ['html' => Html::tidy($html)]];
        }
        for ($k = $at + 1; $k < count($blocks); $k++) {
            $b = $blocks[$k];
            if (!in_array($b['type'] ?? '', ['image', 'files', 'gallery', 'button', 'video'], true)) {
                break;
            }
            unset($b['id']);
            if ($b['type'] === 'image') {
                $b['data']['width'] = 'full';
            }
            $out[] = $b;
        }
        return $out;
    }

    /**
     * Pages importées dont le titre était le premier intertitre Jimdo (« Ordre du jour de la
     * prochaine séance » pour la page CEt…): le libellé du menu devient le titre, l'ancien
     * titre reste en tête du contenu. Pages modifiées dans l'administration: pas touchées.
     */
    private static function v6(): void
    {
        $rows = Db::all("SELECT id, title, menu_title, blocks FROM pages
            WHERE legacy_url IS NOT NULL AND updated_by IS NULL AND path <> '/'
              AND menu_title IS NOT NULL AND menu_title <> '' AND menu_title <> title");
        foreach ($rows as $r) {
            $blocks = json_decode((string) $r['blocks'], true);
            $blocks = is_array($blocks) ? array_values($blocks) : [];
            $first = $blocks[0] ?? null;
            if (!($first && ($first['type'] ?? '') === 'heading' && trim((string) ($first['data']['text'] ?? '')) === trim((string) $r['title']))) {
                array_unshift($blocks, ['id' => Pages::newBlockId(), 'type' => 'heading', 'data' => ['text' => (string) $r['title'], 'level' => 2]]);
            }
            Db::update('pages', [
                'title' => mb_substr((string) $r['menu_title'], 0, 255),
                'menu_title' => null,
                'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], 'id = :id', ['id' => (int) $r['id']]);
        }
    }

    /**
     * Les tableaux contenus dans les blocs Texte (pages importées) deviennent des blocs « Tableau »,
     * modifiables ligne par ligne dans l'éditeur. Colonnes vides retirées; une ligne finale avec
     * une seule case remplie (ex. « BUREAU … ») sort du tableau et redevient du texte.
     */
    private static function v7(): void
    {
        foreach (Db::all("SELECT id, title, blocks FROM pages WHERE blocks LIKE '%<table%'") as $p) {
            $blocks = json_decode((string) $p['blocks'], true);
            if (!is_array($blocks)) {
                continue;
            }
            $out = [];
            $changed = false;
            foreach ($blocks as $b) {
                if (($b['type'] ?? '') === 'text' && str_contains((string) ($b['data']['html'] ?? ''), '<table')) {
                    $parts = self::splitTables((string) $b['data']['html']);
                    if (count($parts) !== 1 || ($parts[0]['type'] ?? '') !== 'text') {
                        foreach ($parts as $k => $part) {
                            $out[] = ['id' => $k === 0 && isset($b['id']) ? $b['id'] : Pages::newBlockId()] + $part;
                        }
                        $changed = true;
                        continue;
                    }
                }
                $out[] = $b;
            }
            if (!$changed) {
                continue;
            }
            Db::insert('page_revisions', [
                'page_id' => (int) $p['id'], 'title' => $p['title'], 'blocks' => $p['blocks'],
                'created_at' => Db::now(), 'created_by' => null,
            ]);
            Db::update('pages', ['blocks' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'id = :id', ['id' => (int) $p['id']]);
        }
    }

    /** Découpe un HTML en blocs text / table (sans id). */
    public static function splitTables(string $html): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__r">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $root = $doc->getElementById('__r');
        if (!$root) {
            return [['type' => 'text', 'data' => ['html' => $html]]];
        }
        // tableau emballé dans un paragraphe ou un div: on déballe
        do {
            $unwrapped = false;
            foreach (iterator_to_array($root->childNodes) as $n) {
                if ($n instanceof \DOMElement && strtolower($n->tagName) !== 'table' && $n->getElementsByTagName('table')->length > 0) {
                    while ($n->firstChild) {
                        $root->insertBefore($n->firstChild, $n);
                    }
                    $root->removeChild($n);
                    $unwrapped = true;
                }
            }
        } while ($unwrapped);
        $parts = [];
        $buf = '';
        $flushText = function () use (&$buf, &$parts): void {
            $h = Html::tidy(trim($buf));
            if (trim(strip_tags($h)) !== '') {
                $parts[] = ['type' => 'text', 'data' => ['html' => $h]];
            }
            $buf = '';
        };
        foreach (iterator_to_array($root->childNodes) as $n) {
            if (!($n instanceof \DOMElement) || strtolower($n->tagName) !== 'table') {
                $buf .= $doc->saveHTML($n);
                continue;
            }
            // lignes et cases
            $rows = [];
            foreach ($n->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $td) {
                    if ($td instanceof \DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) {
                        $inner = '';
                        foreach ($td->childNodes as $c) {
                            $inner .= $doc->saveHTML($c);
                        }
                        $cells[] = ['html' => $inner, 'text' => self::cellText($inner), 'bold' => (bool) preg_match('#^\s*(<p[^>]*>\s*)*<(strong|b)\b#i', $inner)];
                    }
                }
                if ($cells) {
                    $rows[] = $cells;
                }
            }
            $w = $rows ? max(array_map('count', $rows)) : 0;
            foreach ($rows as &$r) {
                $r = array_pad($r, $w, ['html' => '', 'text' => '', 'bold' => false]);
            }
            unset($r);
            // colonnes entièrement vides
            for ($j = $w - 1; $j >= 0; $j--) {
                if (implode('', array_map(fn($r) => $r[$j]['text'], $rows)) === '') {
                    foreach ($rows as &$r) {
                        array_splice($r, $j, 1);
                    }
                    unset($r);
                }
            }
            $rows = array_values(array_filter($rows, fn($r) => implode('', array_column($r, 'text')) !== ''));
            $w = $rows ? count($rows[0]) : 0;
            $single = fn($r) => count(array_filter(array_column($r, 'text'), fn($t) => $t !== '')) === 1 && $w > 1;
            $before = '';
            while ($rows && $single($rows[0])) {
                $before .= implode('', array_column(array_shift($rows), 'html'));
            }
            $after = '';
            while ($rows && $single(end($rows))) {
                $after = implode('', array_column(array_pop($rows), 'html')) . $after;
            }
            $buf .= $before;
            if (count($rows) < 2 || $w < 2) {
                foreach ($rows as $r) {
                    $buf .= '<p>' . implode('<br>', array_filter(array_column($r, 'html'), fn($h) => trim(strip_tags($h)) !== '')) . '</p>';
                }
                $buf .= $after;
                continue;
            }
            $flushText();
            $first = $rows[0];
            $head = true;
            foreach ($first as $c) {
                $up = $c['text'] !== '' && mb_strtoupper($c['text']) === $c['text'] && preg_match('/\p{L}/u', $c['text']);
                if ($c['text'] !== '' && !$c['bold'] && !$up) {
                    $head = false;
                }
            }
            $parts[] = ['type' => 'table', 'data' => ['head' => $head, 'rows' => array_map(fn($r) => array_column($r, 'text'), $rows)]];
            $buf = $after;
        }
        $flushText();
        return $parts ?: [['type' => 'text', 'data' => ['html' => $html]]];
    }

    /** Texte d'une case: paragraphes et <br> en retours à la ligne; un lien garde son adresse. */
    private static function cellText(string $html): string
    {
        $html = Html::cfDecode($html);
        $html = preg_replace_callback('#<a\b[^>]*href="(?:mailto:)?([^"]+)"[^>]*>(.*?)</a>#is', function ($m) {
            $txt = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return ($txt === '' || str_contains($url, $txt) || str_starts_with($url, '/')) ? ($txt !== '' ? $txt : $url) : "$txt ($url)";
        }, $html) ?? $html;
        $t = preg_replace('#<(br|/p|/li|/h\d|/div)[^>]*>#i', "\n", $html) ?? $html;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{a0}", ' ', $t);
        $lines = array_filter(array_map(fn($l) => trim(preg_replace('/[ \t]+/u', ' ', $l) ?? $l), explode("\n", $t)), fn($l) => $l !== '');
        return implode("\n", $lines);
    }

    /**
     * Page CEt: les « Résumé des séances 2026, 2025… » (un titre + des PDF par année) sont
     * regroupés en bas de page sous « Comptes rendus des séances », en listes repliables
     * (l'année la plus récente ouverte). Le reste de la page garde son ordre.
     */
    private static function v8(): void
    {
        $p = Db::one("SELECT id, title, blocks FROM pages WHERE path = '/cet' OR legacy_url LIKE '%/cet/' ORDER BY (path = '/cet') DESC LIMIT 1");
        if (!$p) {
            return;
        }
        $blocks = json_decode((string) $p['blocks'], true);
        if (!is_array($blocks)) {
            return;
        }
        $new = self::groupYearArchives(array_values($blocks), 'Comptes rendus des séances');
        if ($new === null) {
            return;
        }
        Db::insert('page_revisions', [
            'page_id' => (int) $p['id'], 'title' => $p['title'], 'blocks' => $p['blocks'],
            'created_at' => Db::now(), 'created_by' => null,
        ]);
        Db::update('pages', ['blocks' => json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'id = :id', ['id' => (int) $p['id']]);
    }

    /** Sections « titre contenant une année + documents » regroupées à la fin. null si rien à faire. */
    public static function groupYearArchives(array $blocks, string $archiveTitle): ?array
    {
        $sections = [];
        $cur = ['head' => null, 'blocks' => []];
        foreach ($blocks as $b) {
            // intertitre saisi comme simple texte (« Résumé des séances 2021 »)
            if (($b['type'] ?? '') === 'text' && ($pt = self::plain($b)) !== '' && mb_strlen($pt) <= 80
                && preg_match('/(?:s[ée]ances?|proc[eè]s|comptes?[- ]rendus?|r[ée]sum[ée]s?|pv)\D{0,30}(?:19|20)\d\d\b/iu', $pt)) {
                $b = ['id' => $b['id'] ?? Pages::newBlockId(), 'type' => 'heading', 'data' => ['text' => $pt, 'level' => 2]];
            }
            if (($b['type'] ?? '') === 'heading') {
                $sections[] = $cur;
                $cur = ['head' => $b, 'blocks' => []];
            } else {
                $cur['blocks'][] = $b;
            }
        }
        $sections[] = $cur;
        $keep = [];
        $years = [];
        foreach ($sections as $sec) {
            $t = (string) ($sec['head']['data']['text'] ?? '');
            $hasFiles = (bool) array_filter($sec['blocks'], fn($b) => ($b['type'] ?? '') === 'files');
            if ($sec['head'] !== null && $hasFiles && preg_match('/(?:s[ée]ances?|proc[eè]s|comptes?[- ]rendus?|r[ée]sum[ée]s?|pv)\D{0,30}((?:19|20)\d\d)\b|^\s*((?:19|20)\d\d)\s*$/iu', $t, $m)) {
                $year = $m[1] !== '' ? $m[1] : $m[2];
                $items = [];
                $other = [];
                foreach ($sec['blocks'] as $b) {
                    if (($b['type'] ?? '') === 'files') {
                        $items = array_merge($items, (array) ($b['data']['items'] ?? []));
                    } elseif (($b['type'] ?? '') !== 'separator') {
                        $other[] = $b;
                    }
                }
                $years[] = ['year' => (int) $year, 'title' => 'Séances ' . $year, 'items' => $items, 'other' => $other];
                continue;
            }
            $keep[] = $sec;
        }
        if (count($years) < 2) {
            return null;
        }
        usort($years, fn($a, $z) => $z['year'] <=> $a['year']);
        $out = [];
        foreach ($keep as $sec) {
            if ($sec['head'] !== null) {
                $out[] = $sec['head'];
            }
            $bs = $sec['blocks'];
            while ($bs && (end($bs)['type'] ?? '') === 'separator') {
                array_pop($bs);
            }
            foreach ($bs as $b) {
                $out[] = $b;
            }
        }
        while ($out && (end($out)['type'] ?? '') === 'separator') {
            array_pop($out);
        }
        $out[] = ['id' => Pages::newBlockId(), 'type' => 'heading', 'data' => ['text' => $archiveTitle, 'level' => 2]];
        foreach ($years as $i => $y) {
            $out[] = ['id' => Pages::newBlockId(), 'type' => 'files', 'data' => [
                'title' => $y['title'], 'items' => $y['items'], 'sort' => 'manual', 'collapsed' => $i > 0,
            ]];
            foreach ($y['other'] as $b) {
                $out[] = $b;
            }
        }
        return $out;
    }

    /** Listes de membres (texte ou tableau « Commune / Membre ») → blocs « Personnes » uniformes. */
    private static function v9(): void
    {
        foreach (Db::all("SELECT id, title, blocks FROM pages") as $p) {
            $blocks = json_decode((string) $p['blocks'], true);
            if (!is_array($blocks)) {
                continue;
            }
            $new = self::peopleBlocks(array_values($blocks));
            if ($new === null) {
                continue;
            }
            foreach ($new as &$b) {
                $b = isset($b['id']) ? $b : ['id' => Pages::newBlockId()] + $b;
            }
            unset($b);
            Db::insert('page_revisions', [
                'page_id' => (int) $p['id'], 'title' => $p['title'], 'blocks' => $p['blocks'],
                'created_at' => Db::now(), 'created_by' => null,
            ]);
            Db::update('pages', ['blocks' => json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'id = :id', ['id' => (int) $p['id']]);
        }
    }

    /** Convertit les listes de personnes d'une page; null si rien trouvé. Les blocs créés n'ont pas d'id. */
    public static function peopleBlocks(array $blocks): ?array
    {
        $out = [];
        $changed = false;
        foreach ($blocks as $b) {
            $type = $b['type'] ?? '';
            if ($type === 'table' && is_array($b['data']['rows'] ?? null) && ($people = self::peopleFromTable($b['data'])) !== null) {
                $out[] = ['type' => 'people', 'data' => ['items' => $people]];
                $changed = true;
                continue;
            }
            if ($type === 'text' && ($parts = self::peopleFromHtml((string) ($b['data']['html'] ?? ''))) !== null) {
                foreach ($parts as $k => $part) {
                    $out[] = ($k === 0 && isset($b['id']) ? ['id' => $b['id']] : []) + $part;
                }
                $changed = true;
                continue;
            }
            $out[] = $b;
        }
        return $changed ? $out : null;
    }

    /** Tableau « Commune | Membre du comité » → personnes. */
    private static function peopleFromTable(array $d): ?array
    {
        $rows = $d['rows'];
        if (count($rows) < 3 || count($rows[0]) !== 2) {
            return null;
        }
        $head = mb_strtolower(implode(' ', $rows[0]));
        if (!str_contains($head, 'commune') || !preg_match('/membre|nom|délégu|representant|représentant/u', $head)) {
            return null;
        }
        $items = [];
        foreach (array_slice($rows, 1) as $r) {
            $p = self::parsePerson(str_replace("\n", ', ', (string) $r[1]), '');
            if ($p === null) {
                return null;
            }
            $p['commune'] = self::niceCommune((string) $r[0]);
            $items[] = $p;
        }
        return $items;
    }

    /** Découpe un HTML: suites de personnes (paragraphes ou listes) → titre de groupe + bloc Personnes. */
    private static function peopleFromHtml(string $html): ?array
    {
        if (trim($html) === '' || !preg_match('/\b(Mmes?|M\.|Madame|Monsieur)\s|\p{Lu}{3,}\s+\p{Lu}\p{Ll}/u', $html)) {
            return null;
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__r">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $root = $doc->getElementById('__r');
        if (!$root) {
            return null;
        }
        $inner = function (\DOMNode $n) use ($doc): string {
            $h = '';
            foreach ($n->childNodes as $c) {
                $h .= $doc->saveHTML($c);
            }
            return $h;
        };
        $nodes = [];
        foreach (iterator_to_array($root->childNodes) as $n) {
            if ($n instanceof \DOMText && trim($n->textContent) === '') {
                continue;
            }
            $entry = ['html' => $doc->saveHTML($n), 'people' => null, 'title' => null];
            if ($n instanceof \DOMElement) {
                $tag = strtolower($n->tagName);
                if ($tag === 'p') {
                    $person = self::parsePerson($inner($n), '', true);
                    if ($person !== null) {
                        $entry['people'] = [$person];
                    } elseif (preg_match('#^\s*<(strong|b)>([^<]{2,80})</\1>\s*:?\s*$#u', $inner($n), $m)) {
                        $entry['title'] = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), " :\u{a0}");
                    }
                } elseif ($tag === 'ul' || $tag === 'ol') {
                    $list = [];
                    $total = 0;
                    foreach ($n->childNodes as $li) {
                        if ($li instanceof \DOMElement && strtolower($li->tagName) === 'li') {
                            $total++;
                            if (($person = self::parsePerson($inner($li), '')) !== null) {
                                $list[] = $person;
                            }
                        }
                    }
                    if ($total > 0 && count($list) === $total) {
                        $entry['people'] = $list;
                    }
                }
            }
            $nodes[] = $entry;
        }
        $parts = [];
        $buf = '';
        $flush = function () use (&$buf, &$parts): void {
            $h = Html::tidy(trim($buf));
            if (trim(strip_tags($h)) !== '') {
                $parts[] = ['type' => 'text', 'data' => ['html' => $h]];
            }
            $buf = '';
        };
        $found = false;
        for ($i = 0; $i < count($nodes); $i++) {
            if ($nodes[$i]['people'] === null) {
                $buf .= $nodes[$i]['html'];
                continue;
            }
            $run = [];
            $j = $i;
            while ($j < count($nodes) && $nodes[$j]['people'] !== null) {
                $run = array_merge($run, $nodes[$j]['people']);
                $j++;
            }
            if (count($run) < 2) {
                $buf .= $nodes[$i]['html'];
                continue;
            }
            $title = ($i > 0 && $nodes[$i - 1]['title'] !== null) ? $nodes[$i - 1]['title'] : null;
            if ($title !== null) {
                $buf = substr($buf, 0, strlen($buf) - strlen($nodes[$i - 1]['html']));
            }
            $flush();
            if ($title !== null) {
                $parts[] = ['type' => 'heading', 'data' => ['text' => mb_substr($title, 0, 300), 'level' => 3]];
            }
            $parts[] = ['type' => 'people', 'data' => ['items' => $run]];
            $found = true;
            $i = $j - 1;
        }
        $flush();
        return $found ? $parts : null;
    }

    /**
     * « Mme Sandra Favre, Présidente, <em>Conseil intercommunal</em> », « FISCHER Laetitia » →
     * ['name', 'role', 'commune']. null si ce n'est pas une personne. $strict: paragraphe isolé
     * (il faut alors une fonction ou une civilité pour éviter les faux positifs).
     */
    public static function parsePerson(string $html, string $commune, bool $strict = false): ?array
    {
        $html = Html::cfDecode($html);
        $org = [];
        $html = preg_replace_callback('#<(em|i)\b[^>]*>(.*?)</\1>#is', function ($m) use (&$org) {
            $t = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{a0},;:-–");
            if ($t !== '' && preg_match('/\p{L}/u', $t)) {
                $org[] = $t;
            }
            return ' ';
        }, $html) ?? $html;
        $t = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', ', ', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim(preg_replace('/[\s\x{a0}]+/u', ' ', $t) ?? $t, " ,;:-\u{a0}");
        if ($t === '' || mb_strlen($t) > 140 || str_contains($t, '@') || preg_match('/\d{3}/', $t) || str_ends_with($t, ':')) {
            return null;
        }
        $civ = '(?:Mmes?|M\.|MM\.|Mr|Madame|Monsieur|Mlle|Dr|Prof\.?)';
        $hasCiv = (bool) preg_match('/^' . $civ . '\s/u', $t);
        $upper = (bool) preg_match('/^\p{Lu}[\p{Lu}\'’-]+(?:[ -]\p{Lu}[\p{Lu}\'’-]+)*\s+\p{Lu}\p{Ll}/u', $t);
        if (!$hasCiv && !$upper) {
            return null;
        }
        $bits = array_values(array_filter(array_map('trim', explode(',', $t)), fn($x) => (bool) preg_match('/\p{L}/u', $x)));
        $name = array_shift($bits) ?? '';
        $words = preg_split('/\s+/u', $name) ?: [];
        if (count($words) < 2 || count($words) > 6) {
            return null;
        }
        $role = implode(', ', $bits);
        $org = implode(', ', $org);
        if ($strict && $role === '' && $org === '' && !$hasCiv) {
            return null;
        }
        if ($role === '' && $org !== '') {
            [$role, $org] = [$org, ''];
        }
        return ['name' => mb_substr($name, 0, 200), 'role' => mb_substr($role, 0, 200), 'commune' => mb_substr($org !== '' ? $org : $commune, 0, 200)];
    }

    /** « 1082 CORCELLES-LE-JORAT » → « Corcelles-le-Jorat ». */
    private static function niceCommune(string $s): string
    {
        $s = trim(preg_replace('/^\d{4}\s*/', '', trim($s)) ?? $s);
        if ($s !== '' && mb_strtoupper($s) === $s) {
            $s = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
            $s = preg_replace_callback('/(?<=[-\s])(Le|La|Les|De|Du|Des|Sur|Sous|En)(?=[-\s])/u', fn($m) => mb_strtolower($m[1]), $s) ?? $s;
        }
        return $s;
    }

    /**
     * Cartes « Personnes »: virgules et espaces parasites retirés. Page CEt: le dessin sous
     * droits d'auteur (filigrane 123RF) est remplacé par une illustration créée pour le site;
     * le remplacement est mémorisé pour qu'un nouvel import ne remette pas l'ancienne image.
     */
    private static function v10(): void
    {
        $clean = fn(string $v) => trim(preg_replace('/^[\s\x{a0},;:.\-–]+|[\s\x{a0},;:\-–]+$/u', '', preg_replace('/(?:\s*,\s*){2,}/u', ', ', $v) ?? $v) ?? $v);
        foreach (Db::all("SELECT id, blocks FROM pages WHERE blocks LIKE '%\"people\"%'") as $p) {
            $blocks = json_decode((string) $p['blocks'], true);
            if (!is_array($blocks)) {
                continue;
            }
            $changed = false;
            foreach ($blocks as &$b) {
                if (($b['type'] ?? '') !== 'people' || !is_array($b['data']['items'] ?? null)) {
                    continue;
                }
                foreach ($b['data']['items'] as &$it) {
                    foreach (['name', 'role', 'commune'] as $k) {
                        $v = $clean((string) ($it[$k] ?? ''));
                        if ($v !== ($it[$k] ?? '')) {
                            $it[$k] = $v;
                            $changed = true;
                        }
                    }
                }
                unset($it);
            }
            unset($b);
            if ($changed) {
                Db::update('pages', ['blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'id = :id', ['id' => (int) $p['id']]);
            }
        }

        $cet = Db::one("SELECT id, blocks FROM pages WHERE path = '/cet'");
        $src = dirname(__DIR__, 2) . '/resources/illustrations/reunion.png';
        if (!$cet || !is_file($src)) {
            return;
        }
        $old = [];
        foreach ((array) json_decode((string) $cet['blocks'], true) as $b) {
            if (($b['type'] ?? '') === 'image' && !empty($b['data']['media_id'])) {
                $old[] = (int) $b['data']['media_id'];
            }
        }
        if (!$old) {
            return;
        }
        $tmp = sys_get_temp_dir() . '/asij-reunion-' . bin2hex(random_bytes(4)) . '.png';
        copy($src, $tmp);
        $m = Controllers\AdminMediaController::store(['name' => 'illustration-reunion-cet.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp)], false);
        @unlink($tmp);
        Db::update('media', ['alt' => 'Illustration : une réunion autour d’une table', 'title' => 'Illustration réunion (libre de droits, créée pour le site)'], 'id = :id', ['id' => (int) $m['id']]);
        $map = json_decode(Settings::get('media_replacements') ?: '{}', true);
        $map = is_array($map) ? $map : [];
        foreach ($old as $o) {
            $map[(string) $o] = (int) $m['id'];
        }
        Settings::set('media_replacements', json_encode($map));
        foreach (Db::all('SELECT id, blocks FROM pages') as $p) {
            $json = (string) $p['blocks'];
            $new = $json;
            foreach ($old as $o) {
                $new = preg_replace('/"media_id":' . $o . '(?=[,}])/', '"media_id":' . (int) $m['id'], $new) ?? $new;
            }
            if ($new !== $json) {
                Db::update('pages', ['blocks' => $new], 'id = :id', ['id' => (int) $p['id']]);
            }
        }
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
        $html = Html::cfDecode($html);
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
