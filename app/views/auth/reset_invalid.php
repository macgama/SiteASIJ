<?php
declare(strict_types=1);
$title = 'Lien expiré';
$noindex = true;
?>
<link rel="stylesheet" href="<?= e(asset('css/private.css')) ?>">
<div class="container pa-auth">
  <div class="pa-card">
    <h1 class="pa-auth-title">Ce lien n'est plus valable</h1>
    <p>Le lien a peut-être expiré ou a déjà été utilisé. Pour des raisons de sécurité, chaque lien ne fonctionne qu'une seule fois et pendant une durée limitée.</p>
    <p><a class="btn btn-primary" href="/mot-de-passe-oublie">Recevoir un nouveau lien</a></p>
    <p><a href="/connexion">Retour à la connexion</a></p>
  </div>
</div>
