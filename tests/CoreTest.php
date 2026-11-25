<?php
use App\Html;
use App\Db;

return [
    'Html::clean retire les scripts et attributs dangereux' => function () {
        $out = Html::clean('<p onclick="x">a<script>bad()</script><a href="javascript:alert(1)">l</a></p>');
        check(!str_contains($out, 'script'), 'script présent');
        check(!str_contains($out, 'onclick'), 'onclick présent');
        check(!str_contains($out, 'javascript:'), 'javascript: présent');
    },
    'Html::clean garde le formatage autorisé' => function () {
        $out = Html::clean('<p><strong>gras</strong> <a href="https://x.ch">lien</a></p>');
        check(str_contains($out, '<strong>gras</strong>'));
        check(str_contains($out, 'href="https://x.ch"'));
    },
    'Schéma: insertion utilisateur' => function () {
        $id = Db::insert('users', ['email' => 'a@b.ch', 'name' => 'A', 'password_hash' => 'x', 'role' => 'member', 'is_active' => 1, 'created_at' => Db::now()]);
        check($id > 0);
        check(Db::value('SELECT email FROM users WHERE id = ?', [$id]) === 'a@b.ch');
    },
    'Migrations: actualités et contact de l’accueil déplacé dans le pied de page' => function () {
        $blocks = [
            ['id' => 'a', 'type' => 'text', 'data' => ['html' => '<p>x</p>']],
            ['id' => 'b', 'type' => 'heading', 'data' => ['text' => 'Contact', 'level' => 2]],
            ['id' => 'c', 'type' => 'people', 'data' => ['items' => [['name' => 'N. Test', 'role' => 'Directeur', 'commune' => '']]]],
            ['id' => 'd', 'type' => 'button', 'data' => ['label' => 'Nous écrire', 'url' => '/contact']],
        ];
        Db::insert('pages', ['parent_id' => null, 'slug' => '', 'path' => '/', 'title' => 'Travaux routiers', 'blocks' => json_encode($blocks),
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'updated_at' => Db::now(), 'updated_by' => null]);
        App\Settings::set('schema_version', '0');
        App\Migrations::run();
        App\Migrations::run(); // idempotent
        check(App\Settings::get('schema_version') === (string) App\Migrations::VERSION, 'version');
        check(App\Settings::get('contact_name') === 'N. Test' && App\Settings::get('contact_role') === 'Directeur', 'contact copié');
        $home = Db::one("SELECT * FROM pages WHERE path = '/'");
        check(count(json_decode($home['blocks'], true)) === 1, 'bloc contact retiré: ' . $home['blocks']);
        check(str_starts_with($home['title'], 'Bienvenue'), 'titre accueil');
        check((int) Db::value('SELECT COUNT(*) FROM page_revisions WHERE page_id = ?', [(int) $home['id']]) === 1, 'version gardée');
        $news = Db::one('SELECT * FROM pages WHERE path = ?', [App\Pages::NEWS_PATH]);
        check($news !== null && $news['status'] === 'published', 'rubrique Actualités');
        Db::insert('pages', ['parent_id' => (int) $news['id'], 'slug' => 'a', 'path' => '/actualites/a', 'title' => 'Ancienne', 'blocks' => '[]',
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'news_date' => '2026-01-05', 'updated_at' => Db::now()]);
        Db::insert('pages', ['parent_id' => (int) $news['id'], 'slug' => 'b', 'path' => '/actualites/b', 'title' => 'Récente', 'blocks' => '[]',
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 1, 'news_date' => '2026-11-01', 'updated_at' => Db::now()]);
        $l = App\Controllers\PageController::latestNews(5);
        check(count($l) === 2 && $l[0]['title'] === 'Récente', 'ordre des actualités');
        check(App\Controllers\PageController::newsDate($l[0]) === '1er novembre 2026', App\Controllers\PageController::newsDate($l[0]));
        check(App\Controllers\PageController::rubriqueIcon('/transports-scolaires/horaires') === 'bus');
        // nettoyage: les autres tests partent d'une base sans pages
        Db::run('DELETE FROM page_revisions');
        Db::run('DELETE FROM pages');
        Db::run("DELETE FROM settings WHERE name IN ('schema_version', 'contact_name', 'contact_role')");
    },
    'Migrations: accueil Jimdo (contact en texte, nouvelles en sections)' => function () {
        $blocks = [
            ['id' => 'a', 'type' => 'heading', 'data' => ['text' => 'Bienvenue sur le site de l’ASIJ !', 'level' => 3]],
            ['id' => 'b', 'type' => 'text', 'data' => ['html' => '<p>Texte d’accueil</p>']],
            ['id' => 'c', 'type' => 'text', 'data' => ['html' => '<h3><strong>Contacter l’ASIJ :</strong></h3><p>Nicolas Deprez<br>Directeur des Opérations<br>Chemin du Raffort 11<br>1083 Mézières<br><a href="mailto:x.test@example.ch">x.test@example.ch</a><br>www.asij.ch<br>079 592 20 96<br>021&nbsp;557 35 08</p>']],
            ['id' => 'd', 'type' => 'separator', 'data' => []],
            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => 'Travaux routiers', 'level' => 2]],
            ['id' => 'f', 'type' => 'heading', 'data' => ['text' => 'Veuillez vous renseigner sur les sites des communes', 'level' => 3]],
            ['id' => 'g', 'type' => 'separator', 'data' => []],
            ['id' => 'h', 'type' => 'heading', 'data' => ['text' => 'L’ASIJ met au concours le poste de Secrétaire', 'level' => 2]],
            ['id' => 'i', 'type' => 'text', 'data' => ['html' => '<p>Postulez jusqu’au 2 octobre.</p>']],
        ];
        Db::insert('pages', ['parent_id' => null, 'slug' => '', 'path' => '/', 'title' => 'Bienvenue sur le site de l’ASIJ', 'blocks' => json_encode($blocks),
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'updated_at' => Db::now(), 'updated_by' => null]);
        App\Settings::set('schema_version', '0');
        App\Migrations::run();
        App\Migrations::run();
        check(App\Settings::get('contact_name') === 'Nicolas Deprez', 'nom: ' . App\Settings::get('contact_name'));
        check(App\Settings::get('contact_role') === 'Directeur des Opérations', 'fonction');
        check(App\Settings::get('contact_email') === 'x.test@example.ch', 'email');
        check(App\Settings::get('contact_phone') === '079 592 20 96 / 021 557 35 08', 'tél: ' . App\Settings::get('contact_phone'));
        $home = json_decode((string) Db::value("SELECT blocks FROM pages WHERE path = '/'"), true);
        check(array_column($home, 'id') === ['a', 'b'], 'accueil: ' . json_encode(array_column($home, 'id')));
        $l = App\Controllers\PageController::latestNews(5);
        check(count($l) === 2 && $l[0]['title'] === 'Travaux routiers', 'actualités: ' . json_encode(array_column($l, 'title')));
        check($l[0]['meta_description'] === 'Veuillez vous renseigner sur les sites des communes', 'résumé');
        Db::run('DELETE FROM page_revisions');
        Db::run('DELETE FROM pages');
        Db::run("DELETE FROM settings WHERE name IN ('schema_version', 'contact_name', 'contact_role', 'contact_email', 'contact_phone')");
    },
];
