# Espace membres : guide pour les administrateurs

L'espace membres permet à environ 70 personnes (Comité de direction, Conseil
intercommunal, Bureau du Conseil, Direction) de consulter des documents confidentiels.
Chaque personne a **son propre compte** (son adresse e-mail et son mot de passe) :
il n'y a plus de mot de passe partagé.

Tout se gère depuis l'administration du site : **/admin**.

## Comment fonctionnent les accès

- Les **groupes** réunissent les personnes ayant la même fonction (par exemple « Conseil intercommunal »).
- Chaque **dossier** de l'espace sécurisé indique quels groupes peuvent le consulter.
- Un sous-dossier **hérite** des autorisations du dossier qui le contient : si le Conseil
  peut voir « Conseil intercommunal », il voit aussi « Conseil intercommunal › Séances 2026 ».
- Une personne sans groupe ne voit aucun document.
- Les **administrateurs** voient tous les dossiers.
- Les dossiers qu'une personne n'a pas le droit de voir n'apparaissent pas du tout pour elle.

Chaque téléchargement est enregistré dans le **Journal** (qui, quoi, quand).

## Créer un compte pour une personne

1. Menu **Utilisateurs** → **Ajouter une personne**.
2. Indiquez le nom et l'adresse e-mail, cochez ses **groupes**.
3. Laissez le rôle sur **Membre** (choisissez « Administrateur » seulement pour les personnes qui gèrent le site).
4. Laissez cochée la case **Envoyer maintenant une invitation par e-mail**, puis **Créer le compte**.

La personne reçoit un e-mail avec un lien personnel, **valable 7 jours**, pour choisir
elle-même son mot de passe. Aucun mot de passe n'est jamais envoyé par e-mail.

Si le lien a expiré ou que l'e-mail s'est perdu : ouvrez la fiche de la personne et cliquez
sur **Renvoyer l'invitation**. La personne peut aussi utiliser « Mot de passe oublié » sur la
page de connexion.

## Créer tous les comptes d'un coup (import)

1. Dans Excel, préparez 4 colonnes : **nom ; email ; groupes ; role**.
   - plusieurs groupes : séparés par une virgule (`Comité de direction, Bureau du Conseil`) ;
   - rôle : `membre` (ou vide) ou `admin`.
2. **Fichier → Enregistrer sous → CSV (séparateur : point-virgule)**.
3. Menu **Utilisateurs** → **Importer une liste (CSV)** → choisissez le fichier → **Voir l'aperçu**.
4. Vérifiez l'aperçu. Les lignes en erreur (adresse invalide, groupe inconnu…) sont listées
   avec leur numéro : elles ne seront pas importées. Les personnes déjà inscrites sont ignorées.
5. Cochez **Envoyer tout de suite une invitation** si vous le souhaitez, puis **Créer les comptes**.

Vous pouvez corriger le fichier et l'importer à nouveau sans risque de doublon.

## Modifier ou retirer un accès

- Fiche de la personne (cliquez sur son nom) : changez ses groupes, son adresse, puis **Enregistrer**.
- Quand une personne quitte sa fonction : **Désactiver le compte** (recommandé). Elle ne peut
  plus se connecter ; le compte peut être réactivé plus tard en recochant « Compte actif ».
- **Supprimer définitivement** n'est utile que pour un compte créé par erreur.
- Par sécurité, vous ne pouvez pas désactiver votre propre compte ni retirer votre propre rôle
  d'administrateur, et il reste toujours au moins un administrateur actif.

## Gérer les groupes

Menu **Groupes** : créer, renommer ou supprimer un groupe. Le tableau indique le nombre de
membres actifs et de dossiers autorisés. Supprimer un groupe ne supprime aucun compte, mais
ses membres perdent l'accès aux dossiers qui lui étaient réservés.

## Gérer les dossiers et les documents

Menu **Espace sécurisé** :

- **Créer un dossier** : nom, emplacement (à la racine ou dans un autre dossier) et groupes autorisés.
- Cliquez sur un dossier pour :
  - **ajouter des documents** (plusieurs à la fois ; PDF, Word, Excel, PowerPoint, OpenOffice,
    images JPG/PNG, texte, CSV, ZIP ; 25 Mo au maximum par fichier) ;
  - supprimer un document ;
  - le renommer, le déplacer, changer ses groupes autorisés. La mention
    « hérité du dossier parent » signale un groupe qui a déjà accès grâce au dossier parent ;
  - créer un sous-dossier ;
  - le supprimer (uniquement s'il est vide).
- **Voir comme un membre** ouvre l'espace membres tel que les membres le voient.

## Double authentification (recommandée, obligatoire pour les administrateurs)

Avec la double authentification, la connexion demande, en plus du mot de passe, un code à
6 chiffres affiché par une application sur le téléphone. Même si quelqu'un découvre le mot
de passe, il ne peut pas entrer sans le téléphone.

Pour l'activer (chaque personne le fait pour son propre compte) :

1. Installez sur votre téléphone une application gratuite : **Google Authenticator**,
   **Microsoft Authenticator** ou **FreeOTP**.
2. Sur le site : **Mon compte** (ou « Mon profil et mot de passe » dans l'administration)
   → **Activer la double authentification**.
3. Sur le téléphone, touchez le lien « ajouter le compte ASIJ » ; depuis un ordinateur,
   choisissez dans l'application « Saisir une clé de configuration » et recopiez la clé affichée.
4. Saisissez le code à 6 chiffres affiché par l'application → **Vérifier et activer**.

**Téléphone perdu ou changé ?** Un administrateur ouvre la fiche de la personne, coche
**Réinitialiser la double authentification** et enregistre. La personne se connecte alors
avec son seul mot de passe et peut réactiver la double authentification.

**Rendre la double authentification obligatoire pour les administrateurs** : une fois que
tous les administrateurs l'ont activée, la personne qui gère l'hébergement met
`'require_2fa_for_admins' => true` dans `config.php`. Un administrateur qui ne l'a pas encore
activée devra la configurer lors de sa prochaine connexion avant d'accéder au site.

## Sécurité : à savoir

- Après 5 essais de connexion manqués en 15 minutes, l'accès est bloqué 15 minutes
  (pour l'adresse e-mail et pour la connexion internet utilisée).
- Les mots de passe doivent contenir au moins 12 caractères. Une phrase facile à retenir est idéale.
- Les liens « mot de passe oublié » sont valables 1 heure, les invitations 7 jours ;
  chaque lien ne fonctionne qu'une fois.
- Les documents sont stockés hors du site public (`storage/private/`) sous un nom aléatoire,
  et ne sont envoyés qu'aux personnes autorisées. Pensez à inclure ce dossier dans les sauvegardes.
