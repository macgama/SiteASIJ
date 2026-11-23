<?php
declare(strict_types=1);
$q = $q ?? '';
?>
<form method="get" action="/espace-membres/recherche" class="pa-search" role="search">
  <label for="pa-q" class="visually-hidden">Rechercher un document</label>
  <input type="search" id="pa-q" name="q" value="<?= e($q) ?>" placeholder="Rechercher un document (ex. procès-verbal, budget…)" minlength="2" maxlength="100">
  <button type="submit" class="btn btn-primary">Rechercher</button>
</form>
