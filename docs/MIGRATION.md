# Reprendre le contenu de l'ancien site (Jimdo)

Ce guide explique comment copier automatiquement les pages, les documents PDF et les photos de
l'ancien site www.asij.ch (Jimdo) dans le nouveau site, puis comment terminer à la main les quelques
éléments qui ne peuvent pas être copiés automatiquement.

Il s'adresse à la personne qui administre le site, sans connaissances techniques particulières.
Comptez une demi-journée en tout, dont une vingtaine de minutes d'attente pendant la copie.

## Ce qui est copié automatiquement

| Élément | Résultat dans le nouveau site |
|---|---|
| Les 48 pages publiques | Pages publiées, dans le même ordre et la même arborescence que le menu Jimdo |
| Les ~180 documents PDF | Médias, affichés en listes de documents à télécharger |
| Les ~60 photos (en taille originale) | Médias, affichés en images ou en galeries |
| Le logo | Média, utilisé automatiquement en haut du site |
| Le bloc « Informations Transports » (affiche Pedibus, arrêt de bus de Carrouge, horaires) répété sur toutes les pages Jimdo | Copié **une seule fois**, dans un encadré au bas de la page « Transports scolaires » |
| Les anciennes adresses des pages | Redirigées automatiquement vers les nouvelles (voir la fin de ce guide) |

## Ce qui reste à faire à la main

1. **Les documents de la page « Documents destinés aux membres »**: cette page Jimdo est protégée par un
   mot de passe, le programme ne peut donc pas la lire. Il crée seulement le dossier vide
   « Documents destinés aux membres » dans l'espace sécurisé, réservé au groupe « Conseil intercommunal ».
2. **Les 48 vidéos du chantier de Carrouge** (environ 3 Go): elles sont trop lourdes pour le site.
   Elles doivent être publiées sur YouTube puis ajoutées aux pages.
3. **Les mentions légales**: la page Jimdo était vide. Une page « Mentions légales » en brouillon est créée
   avec un modèle conforme à la loi sur la protection des données (nLPD), à compléter puis publier.

À la fin de la copie, le programme écrit un **rapport** (`migration/rapport-import.md`) qui récapitule
tout cela: pages créées, documents, erreurs éventuelles, liste des vidéos à publier avec leurs adresses.

---

## Avant de commencer

- Le nouveau site doit être installé (voir `docs/DEPLOIEMENT.md`) et vous devez pouvoir vous connecter
  à l'administration.
- **Ne fermez pas l'ancien site Jimdo** avant la fin de toute la migration: le programme y lit le contenu.
- Faites une sauvegarde de la base de données si vous avez déjà saisi du contenu dans le nouveau site.

Le programme est prudent:

- il attend **5 secondes entre deux pages** demandées à l'ancien site (règle de politesse demandée par Jimdo);
- il peut être **relancé sans risque**: il ne crée jamais de doublon (pages et documents sont reconnus par leur
  ancienne adresse) et ne retélécharge pas ce qui l'a déjà été;
- il ne remplace **jamais une page que vous avez déjà modifiée** dans l'administration.

**Durée estimée**: environ 230 téléchargements × 5 secondes, soit **20 minutes environ**.
Une relance après une interruption est beaucoup plus rapide (les éléments déjà téléchargés sont gardés
dans le dossier `migration/downloads/`).

Deux façons de faire, selon votre hébergement:

- **Méthode A** – votre hébergeur offre un accès « SSH » (Infomaniak, Hostpoint et la plupart des
  hébergeurs suisses le proposent, parfois à activer dans l'espace client). C'est la méthode la plus simple.
- **Méthode B** – pas d'accès SSH: la copie se fait sur un ordinateur, puis on envoie le résultat sur
  l'hébergement.

---

## Méthode A: sur l'hébergement, avec SSH

1. Ouvrez une connexion SSH (sur Windows: application « Terminal » ou PuTTY; sur Mac: « Terminal »), avec
   les identifiants SSH donnés par l'hébergeur:

   ```
   ssh utilisateur@adresse-du-serveur
   ```

2. Allez dans le dossier du site (le nom exact dépend de l'hébergeur):

   ```
   cd sites/asij
   ```

3. Faites d'abord une **simulation**: rien n'est enregistré, mais le rapport montre ce qui sera fait
   (environ 5 minutes):

   ```
   php bin/import-jimdo.php --dry-run
   ```

4. Lancez la copie réelle. La commande ci-dessous continue même si vous fermez la fenêtre:

   ```
   nohup php bin/import-jimdo.php --write-logo > migration/import.log 2>&1 &
   ```

   Pour suivre l'avancement: `tail -f migration/import.log` (touches Ctrl + C pour arrêter l'affichage,
   la copie continue). La ligne « Rapport : … » apparaît à la fin.

5. Lisez le rapport: `cat migration/rapport-import.md`, ou téléchargez ce fichier par FTP et
   ouvrez-le avec un éditeur de texte.

Pour recopier une seule page (par exemple après une correction sur Jimdo), indiquez son ancienne adresse:

```
php bin/import-jimdo.php --only=/cet/
```

---

## Méthode B: sans SSH, depuis un ordinateur

### 1. Préparer l'ordinateur

- Installez PHP 8.1 ou plus récent:
  - **Windows**: téléchargez le fichier « VS16 x64 Thread Safe » (zip) sur https://windows.php.net/download,
    décompressez-le dans `C:\php`, copiez `php.ini-development` en `php.ini` et retirez le `;` devant les lignes
    `extension=curl`, `extension=fileinfo`, `extension=mbstring`, `extension=openssl`, `extension=pdo_sqlite`.
  - **Mac**: installez Homebrew (https://brew.sh) puis tapez `brew install php` dans le Terminal.
- Copiez le dossier complet du site sur l'ordinateur.
- Dans ce dossier, copiez `config.sample.php` en `config.php`, puis dans `config.php` remplacez la ligne
  `'dsn' => 'mysql:…'` par:

  ```
  'dsn' => 'sqlite:' . __DIR__ . '/storage/asij.sqlite',
  ```

### 2. Lancer la copie sur l'ordinateur

Ouvrez un terminal dans le dossier du site et tapez:

```
php bin/install.php votre@email.ch "Prénom Nom"
php bin/import-jimdo.php --write-logo --export-sql=migration/import.sql
```

Laissez l'ordinateur allumé et connecté à Internet pendant une vingtaine de minutes.
Le programme produit:

- les fichiers des documents et photos dans `storage/media/`;
- le fichier `migration/import.sql`, qui contient les pages et la liste des médias;
- le rapport `migration/rapport-import.md`.

### 3. Envoyer le résultat sur l'hébergement

1. Installez le site sur l'hébergement comme indiqué dans `docs/DEPLOIEMENT.md`, **sans créer de page**.
2. Avec votre logiciel FTP (FileZilla, Cyberduck…), envoyez **tout le contenu** du dossier
   `storage/media/` de l'ordinateur dans le dossier `storage/media/` de l'hébergement, ainsi que le fichier
   `public/assets/img/logo.png`.
3. Ouvrez **phpMyAdmin** depuis l'espace client de l'hébergeur, choisissez la base du site, onglet
   « Importer », sélectionnez `migration/import.sql` et validez.
   Ce fichier est prévu pour un site neuf: s'il signale des doublons, c'est que des pages ou des médias
   existaient déjà; videz alors les tables `pages` et `media` (onglet « Opérations » > « Vider ») et recommencez.
4. Ouvrez le site: les pages et les documents doivent apparaître.

---

## Après la copie: vérifier

Connectez-vous à l'administration et contrôlez:

- [ ] le rapport n'indique **aucune erreur** (sinon relancez la commande: les éléments manquants seront
      téléchargés, les autres ne bougent pas);
- [ ] le menu contient les mêmes rubriques que l'ancien site, dans le bon ordre (vous pouvez le réorganiser
      dans « Pages »);
- [ ] quelques pages riches en documents (Préavis, Décisions, CEt) affichent bien tous leurs PDF;
- [ ] les galeries de photos (Collège des Gollies, Photos extension 2017) sont complètes;
- [ ] l'encadré « Informations transports » apparaît bien au bas de la page Transports scolaires;
- [ ] les pages signalées « sans contenu » dans le rapport (Évolution des transports, En cours…) sont à
      compléter ou à masquer;
- [ ] la page « Mentions légales » (brouillon) est complétée, relue puis **publiée**.

## Récupérer les documents de la zone protégée Jimdo

1. Connectez-vous à **Jimdo** (https://account.e.jimdo.com) avec le compte qui gère l'ancien site, puis
   ouvrez l'éditeur du site.
2. Allez sur la page **Présentation > Le Conseil intercommunal > Documents destinés aux membres**
   (l'éditeur l'affiche sans demander le mot de passe; sinon saisissez le mot de passe de la page).
3. Pour **chaque document** de la page: cliquez sur « Télécharger » et enregistrez le fichier sur votre
   ordinateur, dans un dossier nommé par exemple `Documents membres`. Gardez les noms d'origine.
   Notez aussi les éventuels textes de la page (ordre, intitulés) pour les reprendre.
4. Dans le nouveau site, menu **Espace sécurisé**: ouvrez le dossier **« Documents destinés aux membres »**
   (créé par le programme) et déposez-y les fichiers. Vous pouvez créer des sous-dossiers (par année, par séance).
5. Dans **Utilisateurs**, vérifiez que chaque membre du Conseil intercommunal a un compte et fait partie du
   groupe **« Conseil intercommunal »**: seul ce groupe (et les administrateurs) voit ce dossier.
6. Une fois tout vérifié, **supprimez ces documents de Jimdo**: sur Jimdo, un fichier reste téléchargeable par
   toute personne qui connaît son lien, même sur une page protégée. Supprimez ensuite les fichiers de votre
   ordinateur s'ils sont confidentiels.

## Publier les vidéos du chantier sur YouTube

Les 48 vidéos de la page **Collège Gustave-Roud (Carrouge)** sont listées dans le rapport, avec leur adresse
d'origine quand elle a pu être trouvée.

1. **Récupérer les vidéos**: cliquez sur chaque adresse du rapport (ou, dans l'éditeur Jimdo, sur chaque vidéo
   de la page puis « Télécharger ») et enregistrez les fichiers MP4 sur votre ordinateur.
2. **Créer une chaîne YouTube pour l'ASIJ** (une seule fois): connectez-vous à https://studio.youtube.com avec un
   compte Google de l'association (pas un compte personnel), puis créez la chaîne « ASIJ ».
3. **Envoyer les vidéos**: dans YouTube Studio, bouton **Créer > Importer des vidéos**. Vous pouvez glisser
   jusqu'à 15 fichiers à la fois. Pour chaque vidéo:
   - titre clair, par exemple « Chantier collège Gustave-Roud – mai 2020 »;
   - « Non, elle n'est pas conçue pour les enfants »;
   - à l'étape **Visibilité**, choisissez **« Non répertoriée »**: la vidéo n'apparaît pas dans les recherches
     YouTube, seules les personnes qui ont le lien (donc les visiteurs du site) peuvent la voir.
   Vous pouvez aussi regrouper les vidéos dans une playlist « Chantier Carrouge ».
4. **Ajouter les vidéos au site**: sur YouTube, ouvrez la vidéo, bouton **Partager > Copier**. Dans
   l'administration du site, ouvrez la page « Collège Gustave-Roud Carrouge », ajoutez un bloc **Vidéo**
   et collez l'adresse. Le site affiche la vidéo en mode « sans cookie » (respect de la vie privée).
   Conseil: plutôt que 48 blocs, publiez une sélection ou un montage, et mettez un lien vers la playlist
   pour le reste (bloc Bouton).

## Vérifier les redirections des anciennes adresses

Beaucoup de personnes ont des liens vers l'ancien site (favoris, courriels, autres sites).
Chaque page importée garde son ancienne adresse: quand quelqu'un ouvre une ancienne adresse,
le nouveau site l'envoie automatiquement vers la nouvelle (redirection « 301 », que Google comprend aussi).

Exemple: `www.asij.ch/présentation/le-comité-de-direction/` mène à `www.asij.ch/presentation/le-comite-de-direction`.

Le tableau **« Pages (ancienne adresse → nouvelle adresse) »** du rapport donne la correspondance complète.
Pour vérifier, **une fois le domaine www.asij.ch dirigé vers le nouveau site**:

1. Prenez 5 à 10 lignes du tableau, de préférence des adresses avec accents.
2. Tapez l'ancienne adresse dans le navigateur (par exemple `https://www.asij.ch/bâtiments-scolaires/collège-du-raffort-mézières/`).
3. La barre d'adresse doit afficher la nouvelle adresse et la bonne page doit s'afficher.

Avec SSH, cette commande vérifie toutes les pages d'un coup (chaque ligne doit commencer par `301`):

```
grep -o '^| /[^|]*' migration/rapport-import.md | sed 's/^| //; s/ *$//' | while read p; do
  curl -s -o /dev/null -w "%{http_code} $p -> %{redirect_url}\n" -g "https://www.asij.ch$p"; done
```

À savoir:

- l'accueil (`/`) et les pages dont l'adresse n'a pas changé (par exemple `/cet/`, `/contact/`) répondent
  directement (`200`) au lieu de rediriger: c'est normal;
- une page en **brouillon** ne redirige pas: l'ancienne adresse `/about/` (mentions légales) redirigera dès que
  la page « Mentions légales » sera publiée;
- si vous changez plus tard l'adresse d'une page dans l'administration, l'ancienne adresse Jimdo continue de
  mener à la page;
- les liens directs vers les anciens fichiers PDF Jimdo (`/app/download/…`) cesseront de fonctionner à la
  fermeture du site Jimdo: mettez à jour les liens importants (courriels types, sites des communes).

## En cas de problème

| Message | Que faire |
|---|---|
| « config.php manquant » | Le site n'est pas installé dans ce dossier: voir `docs/DEPLOIEMENT.md`. |
| « HTTP 404 » pour un document | Le document a été supprimé de Jimdo: rien à faire, ou ajoutez-le à la main dans les médias. |
| « Échec après 3 essais » | Problème de connexion passager: relancez la même commande. |
| « page conservée (modifiée dans l'administration) » | Normal: vos modifications sont protégées. Pour forcer la recopie, ajoutez `--force` (vos modifications seront perdues). |
| La copie s'arrête en cours de route | Relancez la même commande: elle reprend sans doublon. |

Pour toutes les options du programme: `php bin/import-jimdo.php --help`.
