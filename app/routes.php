<?php
declare(strict_types=1);

use App\Controllers\{
    AuthController, PageController, MediaController, ContactController,
    MemberController, AdminController, AdminPagesController, AdminMediaController,
    AdminUsersController, AdminPrivateController, AdminSettingsController
};

/** @var App\Router $r */

// --- Authentification
$r->get('/connexion', [AuthController::class, 'loginForm']);
$r->post('/connexion', [AuthController::class, 'login']);
$r->get('/connexion/code', [AuthController::class, 'twoFactorForm']);
$r->post('/connexion/code', [AuthController::class, 'twoFactor']);
$r->post('/deconnexion', [AuthController::class, 'logout']);
$r->get('/mot-de-passe-oublie', [AuthController::class, 'forgotForm']);
$r->post('/mot-de-passe-oublie', [AuthController::class, 'forgot']);
$r->get('/reinitialiser/{token}', [AuthController::class, 'resetForm']);
$r->post('/reinitialiser/{token}', [AuthController::class, 'reset']);

// --- Espace sécurisé (membres connectés)
$r->get('/espace-membres', [MemberController::class, 'index']);
$r->get('/espace-membres/dossier/{id}', [MemberController::class, 'folder']);
$r->get('/espace-membres/fichier/{id}', [MemberController::class, 'download']);
$r->get('/espace-membres/recherche', [MemberController::class, 'search']);
$r->get('/espace-membres/profil', [MemberController::class, 'profile']);
$r->post('/espace-membres/profil/mot-de-passe', [MemberController::class, 'changePassword']);
$r->post('/espace-membres/profil/2fa', [MemberController::class, 'setupTwoFactor']);
$r->post('/espace-membres/profil/2fa/desactiver', [MemberController::class, 'disableTwoFactor']);

// --- Administration (rôle admin)
$r->get('/admin', [AdminController::class, 'dashboard']);
$r->get('/admin/journal', [AdminController::class, 'auditLog']);
$r->get('/admin/messages', [AdminController::class, 'messages']);

$r->get('/admin/pages', [AdminPagesController::class, 'index']);
$r->get('/admin/pages/nouvelle', [AdminPagesController::class, 'create']);
$r->post('/admin/pages', [AdminPagesController::class, 'store']);
$r->get('/admin/pages/{id}', [AdminPagesController::class, 'edit']);
$r->post('/admin/pages/{id}', [AdminPagesController::class, 'update']);
$r->post('/admin/pages/{id}/supprimer', [AdminPagesController::class, 'destroy']);
$r->get('/admin/pages/{id}/apercu', [AdminPagesController::class, 'preview']);
$r->get('/admin/pages/{id}/versions', [AdminPagesController::class, 'revisions']);
$r->post('/admin/pages/{id}/versions/{rev}', [AdminPagesController::class, 'restore']);
$r->post('/admin/pages/ordre', [AdminPagesController::class, 'reorder']);

$r->get('/admin/medias', [AdminMediaController::class, 'index']);
$r->get('/admin/medias/json', [AdminMediaController::class, 'json']);
$r->post('/admin/medias', [AdminMediaController::class, 'upload']);
$r->post('/admin/medias/{id}', [AdminMediaController::class, 'update']);
$r->post('/admin/medias/{id}/supprimer', [AdminMediaController::class, 'destroy']);

$r->get('/admin/utilisateurs', [AdminUsersController::class, 'index']);
$r->get('/admin/utilisateurs/nouveau', [AdminUsersController::class, 'create']);
$r->post('/admin/utilisateurs', [AdminUsersController::class, 'store']);
$r->get('/admin/utilisateurs/import', [AdminUsersController::class, 'importForm']);
$r->post('/admin/utilisateurs/import', [AdminUsersController::class, 'import']);
$r->get('/admin/utilisateurs/{id}', [AdminUsersController::class, 'edit']);
$r->post('/admin/utilisateurs/{id}', [AdminUsersController::class, 'update']);
$r->post('/admin/utilisateurs/{id}/invitation', [AdminUsersController::class, 'sendInvite']);
$r->post('/admin/utilisateurs/{id}/supprimer', [AdminUsersController::class, 'destroy']);
$r->get('/admin/groupes', [AdminUsersController::class, 'groups']);
$r->post('/admin/groupes', [AdminUsersController::class, 'saveGroup']);
$r->post('/admin/groupes/{id}/supprimer', [AdminUsersController::class, 'deleteGroup']);

$r->get('/admin/espace', [AdminPrivateController::class, 'index']);
$r->get('/admin/espace/dossier/{id}', [AdminPrivateController::class, 'folder']);
$r->post('/admin/espace/dossiers', [AdminPrivateController::class, 'saveFolder']);
$r->post('/admin/espace/dossiers/{id}/supprimer', [AdminPrivateController::class, 'deleteFolder']);
$r->post('/admin/espace/dossier/{id}/fichiers', [AdminPrivateController::class, 'upload']);
$r->post('/admin/espace/fichiers/{id}/supprimer', [AdminPrivateController::class, 'deleteFile']);

$r->get('/admin/reglages', [AdminSettingsController::class, 'index']);
$r->post('/admin/reglages', [AdminSettingsController::class, 'save']);

// --- Public
$r->post('/contact/envoyer', [ContactController::class, 'submit']);
$r->get('/media/{id}/{name}', [MediaController::class, 'serve']);
$r->get('/sitemap.xml', [PageController::class, 'sitemap']);
$r->get('/{path*}', [PageController::class, 'show']);
