# Architecture du site ASIJ

Site sur mesure en **PHP 8.1+** sans framework ni dépendance Composer à l'exécution,
base **MySQL/MariaDB** en production (SQLite en développement). Il fonctionne sur
un hébergement mutualisé standard (Infomaniak, Hostpoint, OVH, etc.).

## Arborescence

```
public/            racine web (le domaine pointe ici)
  index.php        contrôleur frontal unique
  assets/css|js|img
app/
  bootstrap.php    autoload (App\X -> app/lib/X.php, App\Controllers\X -> app/controllers/X.php), config, BDD
  routes.php       TOUTES les routes (ne pas dupliquer ailleurs)
  lib/             noyau: Db, Auth, Session, Csrf, Router, View, Html (nettoyage), Settings, Controller...
  controllers/     un contrôleur par domaine (namespace App\Controllers)
  views/           gabarits PHP. layout.php (public), admin/layout.php (admin)
bin/               scripts CLI (install, import Jimdo, création admin)
database/schema.sql
storage/           HORS racine web: media/ (fichiers publics), private/ (confidentiels), logs/, cache/
migration/         manifest.json = inventaire de l'ancien site; downloads/ (ignoré par git)
```

## Principes de sécurité (obligatoires)

- Tout `POST` est vérifié par `Csrf::check()` dans le routeur: chaque formulaire contient `<?= csrf_field() ?>`;
  les appels `fetch()` envoient l'en-tête `X-CSRF-Token` (valeur dans `<meta name="csrf-token">`).
- Toute sortie est échappée avec `e()`. Le seul HTML non échappé est le texte riche déjà passé par `Html::clean()` à l'enregistrement.
- Requêtes SQL uniquement préparées via `App\Db`.
- Les fichiers confidentiels sont dans `storage/private/` avec un nom aléatoire, servis uniquement par
  `MemberController::download` après contrôle d'accès, avec `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`.
- Chaque téléchargement confidentiel et chaque action d'admin est tracé avec `audit()`.
- Connexion: limite par couple (IP, compte) avec plafonds plus larges par compte et par IP; échecs 2FA limités à 10/heure/compte; un code TOTP ne peut pas être rejoué; `users.session_version` invalide toutes les autres sessions après changement de mot de passe, de 2FA, de rôle, d'e-mail ou de statut.
- Rôles: `admin` (édite tout) et `member` (lit les dossiers de ses groupes). Seuls les admins modifient les pages.
- Accès à un dossier privé: l'utilisateur appartient à au moins un groupe autorisé sur le dossier **ou sur un dossier parent**. Les admins voient tout.

## Modèle de contenu: pages et blocs

Une page (`pages`) a un parent (menu hiérarchique), un `slug`, un `path` complet calculé
(`/presentation/le-conseil-intercommunal`), un statut `draft|published`, et `blocks`:
un tableau JSON de blocs. Chaque bloc: `{"id": "b_xxxx", "type": "...", "data": {...}}`.

| type | data | rendu |
|---|---|---|
| `heading` | `{text, level: 2\|3}` | titre de section |
| `text` | `{html}` (nettoyé par Html::clean) | texte riche |
| `image` | `{media_id, alt, caption, width: "full"\|"half"}` | image |
| `gallery` | `{items: [{media_id, caption}], title?}` | galerie responsive + agrandissement |
| `files` | `{title?, items: [{media_id, label}], sort: "manual"\|"name_desc"}` | liste de documents à télécharger (icône, taille) |
| `video` | `{url}` (YouTube/Vimeo) ou `{media_id}` | vidéo intégrée (iframe youtube-nocookie) |
| `callout` | `{style: "info"\|"warning", title, html}` | encadré |
| `button` | `{label, url}` | bouton lien |
| `contact` | `{intro?}` | formulaire de contact |
| `columns` | `{left: html, right: html}` | 2 colonnes (texte riche) |
| `people` | `{items: [{name, role, commune}]}` | liste de personnes (CoDir, bureau…) |
| `separator` | `{}` | ligne |

Rendu: `App\Blocks::render(array $blocks): string` (app/lib/Blocks.php) appelle le gabarit
`app/views/blocks/{type}.php` avec `$b` (le bloc) et `$data`. Un type inconnu est ignoré.

Médias publics: table `media`, fichiers dans `storage/media/`, servis par
`/media/{id}/{nom}` (MediaController::serve) avec cache long. Fonction utilitaire
`App\Media::url(int $id): string`.

## Répartition du travail (équipes)

| Équipe | Fichiers |
|---|---|
| Noyau (fait) | app/lib/{Db,Config,Auth,Session,Csrf,Router,View,Html,Settings,Controller}.php, helpers.php, routes.php, public/index.php, schema.sql, bin/install.php |
| Site public & design | app/lib/Blocks.php, app/lib/Media.php, app/views/layout.php, app/views/public/*, app/views/blocks/*, app/controllers/{PageController,MediaController,ContactController}.php, public/assets/css/site.css, public/assets/js/site.js |
| Administration & éditeur | app/views/admin/*, app/controllers/{AdminController,AdminPagesController,AdminMediaController,AdminSettingsController}.php, app/lib/Pages.php, public/assets/css/admin.css, public/assets/js/editor.js, public/assets/js/admin.js |
| Espace sécurisé & comptes | app/controllers/{AuthController,MemberController,AdminUsersController,AdminPrivateController}.php, app/lib/{Totp,Mailer,PrivateAccess}.php, app/views/auth/*, app/views/private/*, app/views/admin/users/*, app/views/admin/private/* |
| Migration | bin/import-jimdo.php, migration/*, docs/MIGRATION.md |

Les gabarits admin des équipes « Espace sécurisé » utilisent `app/views/admin/layout.php` (équipe Administration).
Contrat du layout admin: variables `$title` (string) et `$content` (string, injecté par View); le menu latéral
contient: Tableau de bord, Pages, Médias, Espace sécurisé, Utilisateurs, Groupes, Messages, Réglages, Journal.

## Identité visuelle

Reprendre le logo actuel (téléchargé par l'import dans les médias, réglage `logo_media_id`;
repli: `public/assets/img/logo.png`). Couleurs à dériver du logo; en attendant, variables CSS dans `:root` de site.css.
