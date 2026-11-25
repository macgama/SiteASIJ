<?php
declare(strict_types=1);
/** Instructions d'ajout du compte dans une application d'authentification. @var string $secretFormatted @var string $uri */
?>
<ol class="pa-steps">
  <li>Installez sur votre téléphone une application d'authentification gratuite, par exemple <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong> ou <strong>FreeOTP</strong>.</li>
  <li>
    <strong>Sur ce téléphone :</strong> touchez <a href="<?= e($uri) ?>">ce lien pour ajouter le compte ASIJ</a>.<br>
    <strong>Sinon</strong>, dans l'application, choisissez « Saisir une clé de configuration » et recopiez cette clé :
    <span class="pa-secret" id="pa-secret"><?= e($secretFormatted) ?></span>
    <button type="button" class="pa-linkbtn" data-copy="pa-secret">Copier la clé</button>
    <span class="pa-help">Nom du compte : ASIJ · Type : basé sur l'heure</span>
  </li>
  <li>Saisissez ci-dessous le code à 6 chiffres affiché par l'application.</li>
</ol>
