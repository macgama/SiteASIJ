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
];
