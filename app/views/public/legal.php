<?php
declare(strict_types=1);
/**
 * Mentions légales par défaut, affichées tant qu'aucune page « /mentions-legales » n'est publiée.
 */
use App\Controllers\PageController;
use App\Settings;

$title = 'Mentions légales';
$currentPath = PageController::LEGAL_PATH;
$name = Settings::get('site_name');
$tagline = Settings::get('site_tagline');
$address = Settings::get('contact_address');
$email = Settings::get('contact_email');
?>
<div class="page-head">
  <div class="container">
    <nav class="breadcrumbs" aria-label="Fil d'Ariane"><ol><li><a href="/">Accueil</a></li><li><span aria-current="page">Mentions légales</span></li></ol></nav>
    <h1 class="page-title">Mentions légales</h1>
  </div>
</div>
<div class="container page-body">
  <div class="prose">
    <h2>Éditeur du site</h2>
    <p><strong><?= e($tagline !== '' ? $tagline . ' (' . $name . ')' : $name) ?></strong><br>
      <?= App\Html::nl($address) ?>
      <?php if ($email !== ''): ?><br>E-mail : <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php endif; ?></p>

    <h2>Protection des données</h2>
    <p>Ce site respecte la loi fédérale sur la protection des données (LPD). Il n'utilise ni outil de mesure d'audience,
      ni cookie publicitaire. Un seul cookie technique est déposé pour le bon fonctionnement du site
      (formulaire de contact, connexion à l'espace membres) ; il est supprimé à la fermeture du navigateur.</p>
    <p>Les informations envoyées par le formulaire de contact (nom, adresse e-mail, message) servent uniquement à
      répondre à votre demande. Elles ne sont transmises à aucun tiers.</p>
    <p>Les vidéos intégrées sont diffusées en mode « confidentialité renforcée » (YouTube sans cookie ou Vimeo sans suivi).</p>

    <h2>Droits</h2>
    <p>Vous pouvez demander à consulter, corriger ou supprimer les données vous concernant en nous écrivant
      à l'adresse ci-dessus.</p>

    <h2>Contenus</h2>
    <p>Les textes, photos et documents de ce site sont la propriété de l'<?= e($name) ?> ou utilisés avec l'accord de leurs auteurs.
      Les documents officiels font foi dans leur version signée.</p>
  </div>
</div>
