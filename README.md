# Social & Ads — L'Atelier By

Module **indépendant** de publication et de publicité Meta du réseau : calendrier
éditorial, création de posts (studio visuel), campagnes, publicités, formulaires de
leads, retour sur campagne, paramètres et connexions. Implémentation de la maquette
Claude Design « Présentation produit Facebook » (écrans `SA *.dc.html`).

- **Adresse** : `/social` (ex. `http://185.180.206.46/social/`)
- **Dossier serveur** : `/var/www/social` (à côté du BO, `/var/www/consulant_bo`)
- **API** : `/social/api/v1/social/{domaine}/{ressource}`

## Relié au BO consultant

Le module a son propre dépôt, son dossier, son URL et son déploiement, mais il
partage **tous les accès** du BO consultant :

| Accès | D'où il vient |
|---|---|
| Base MySQL | identifiants lus dans `/var/www/consulant_bo/config/config.php` |
| Connexion utilisateur | le cookie de session du cockpit (`cockpit_s`), vérifié avec le même secret (`ceo_app_setting.authSecret`) — connecté au BO = connecté ici. Écran de connexion du BO désactivé = accès ouvert, comme le BO |
| Boutiques | `ceo_shop` (fermées exclues) |
| Pages Facebook | Brand Guard (`brand_guard_pages.page_id`) |
| Adresse, position GPS | caisse du panel (`shops`, via `ceo_shop.pwa_shop_id`) |
| Ventes (retour sur campagne) | caisse du panel (`transaction`, `transaction_product`, `product`) |
| Clés Meta | celles de Brand Guard, **recopiées une fois** dans `social_parametre` au premier lancement |
| Journal | chaque geste s'écrit aussi dans `ceo_journal_entry` (écran Journal du cockpit, type « Social & Ads ») |

Le module n'écrit **jamais** dans les tables `ceo_*`, `brand_guard_*` ni dans
celles du panel (à part la ligne de journal).

## Tout est en base : tables `social_*`

Toutes les données **et toutes les clés** sont stockées et lues dans la base
(aucun secret en fichier ni en variable d'environnement côté module) :

| Table | Contenu |
|---|---|
| `social_parametre` | paramètres et clés : `meta_system_token`, `meta_app_secret`, `meta_verify_token` (marqués `secret`, jamais renvoyés au navigateur), `meta_version`, `meta_dsa`, `meta_compte_reseau`, `url_publique`, `url_bo`, `utilisateur`, `marge`, `catalogue`… |
| `social_content` | les posts (titre, statut, date, heure, boutiques, canaux, textes FR/NL, image, erreur…) |
| `social_campagne` | les campagnes (type, dates, budget, objectif, boutiques, produits) |
| `social_publicite` | les publicités (objectif, statut Meta, budget, plafond, dépense, motif de refus) |
| `social_formulaire` | les formulaires de leads, une ligne par version |
| `social_lead` | les leads reçus |
| `social_groupe` / `social_modele` | groupes de boutiques / modèles de posts |
| `social_boutique` | langues, horaires, identifiants Meta (`page_id`, `ad_account_id`, `instagram_user_id`, `pixel_id`), état des connexions |
| `social_publication` | la file de publication (post × boutique × canal, heure prévue, résultat Meta) |
| `social_meta_objet` | objets créés chez Meta (campagne, ad set, créa, pub, formulaire) |
| `social_resultat` | résultats Meta + ventes caisse par campagne et boutique |
| `social_instagram_media` | les 30 dernières publications de chaque compte Instagram (grille du profil) |
| `social_commentaire` | les commentaires reçus sur les dark posts (Facebook et Instagram), masqués ou non |
| `social_modele_franchise` | les modèles de post ouverts aux boutiques (espace franchisé) |
| `social_acces_franchise` | les codes d'accès des boutiques à leur espace (empreinte seulement) |
| `social_activite` | le journal d'activité |

Chaque table a des colonnes typées (lisibles en SQL) et une colonne `data` qui
garde l'objet complet de l'écran (réglages du studio visuel, ciblage de
l'assistant Publicités, questions d'un formulaire). Tables créées au premier
appel (`src/schema.php`), sans migration, comme le BO.

Les clés Meta se saisissent dans **Paramètres → API Meta → Clés d'accès Meta**
(un champ vide ne change rien ; la valeur n'est jamais réaffichée).

## API TFBuddy : la source principale

Le module lit l'API TFBuddy (`https://atelierby.tfbuddy.com/api/v1`, swagger
`/api/swagger/openapi.json`) avant la base du BO, pour pouvoir être déplacé :
boutiques (`/public/shops`, `/shops`), catalogue et prix
(`/shops/{id}/products/available`, `?lang_code=nl`), gammes
(`/product-availability-periods`), ventes par produit et tickets
(`/shops/{id}/statistics/sales/product-category-groups` et `/kpis`, avec
`date_from`/`date_to`). Comptes admin et consultant dans `social_parametre`
(repris une fois du BO), modifiables dans **Paramètres → API TFBuddy**.

Ce même onglet liste **les routes qui fonctionnent et celles qui ne
fonctionnent pas** sur le serveur : audit de toutes les routes GET du swagger
(bouton, ou cron chaque nuit à 5 h 20), résultat dans `social_api_audit`.
Rapport complet : **`docs/AUDIT.md`**.

## Portefeuille Business Manager

**Paramètres → API Meta → Portefeuille Business Manager → Rafraîchir** relit
chez Meta les Pages, comptes publicitaires, comptes Instagram et pixels visibles
du System User (Business trouvé automatiquement ou saisi), les rapproche des
boutiques et de la **marque**, puis « Remplir les identifiants vides » ou « Tout
remplacer » complète le tableau « Identifiants Meta par boutique » (la marque
y a sa ligne ; son compte publicitaire est le compte réseau).

**Mise à jour automatique** (bandeau au-dessus du tableau des identifiants) : le
cron relit le portefeuille toutes les heures (à la 37e minute ; ou toutes les 6 h /
24 h) et remplit les identifiants vides — ou les remplace tous si « Remplacer aussi
les identifiants saisis à la main » est coché — puis revérifie les connexions des
boutiques modifiées. Elle part aussi dès qu'un nouveau token est enregistré, et à la
demande (« Mettre à jour maintenant »). Le journal ne garde que les changements.
Réglages en base : `social_parametre.meta_ids_auto` (dernier passage :
`meta_ids_auto_dernier`).

## Ce que fait le serveur chez Meta

Le navigateur n'appelle **jamais** Meta. Référence complète des appels :
`docs/meta-api-reference.md`.

- **Posts** — file tenue par le backend (`social_publication`) : « Programmé » =
  en file à l'heure dite, étalée de `X / (n−1)` minutes par Page ; « Publier
  maintenant » = publié dans la requête. Variables `{ville}`, `{adresse}`,
  `{horaires}`, `{prix}`, `{produit_du_jour}` résolues boutique par boutique, dans
  la langue de la Page (bilingue : NL puis FR). Facebook : `/photos`, `/feed`
  (+ `attached_media`), `/videos`. Instagram : `/media` → `/media_publish`.
  Échecs remontés au calendrier avec le motif et le code Meta (190, 368, 2) :
  statut « Échec » ou « Partiel », bouton **Relancer** réel.
- **Montages Instagram** (écran « Créer un post », `public/montage.js`, dessinés
  dans le navigateur sur un canvas, aux couleurs et polices de la marque) :
  - *Carrousel panoramique* — un visuel large découpé en 2 à 10 images 1080 × 1350
    (4:5) ; la photo et le bandeau bordeaux traversent les découpes, le titre et la
    pastille prix restent entiers dans la 1re image (la seule visible sur le
    profil), les éléments du bandeau (séparés par « · ») sont répartis un par
    image. Publié en **un** post : carrousel Instagram (`is_carousel_item` ×n →
    `media_type=CAROUSEL` → `media_publish`), album Facebook (`/photos`
    `published=false` ×n → `/feed` + `attached_media`).
  - *Collage 4:5* — gabarits Duo, Trio, Quatuor en une image 1080 × 1350.
  - *Frise de grille* (franchiseur) — un visuel découpé en 3 posts qui forment une
    ligne du profil. Le profil recadre chaque post en 3:4 (1012,5 × 1350) : le
    visuel est dessiné sur la largeur visible et chaque tuile 1080 × 1350 déborde
    de 33,75 px sur ses voisines. Instagram : les 3 conteneurs sont préparés
    d'abord (un refus ne publie rien), puis publiés à la suite, tuile 3 → 2 → 1 ;
    les ids publiés sont notés dans le post (`frise.ig`), une relance ne republie
    pas une tuile en ligne. Facebook reçoit le visuel entier en une photo. Verrou :
    aucun autre post Instagram de la boutique dans la demi-heure avant et après
    (refus à l'écran et côté serveur, le post reste en brouillon avec le motif).
  Les images partent en pleine définition, sont écrites en fichiers par le serveur
  (`public/uploads/`) ; les réglages et les photos sources restent dans le post
  (`montage`) pour le rouvrir et le modifier.
- **Grille Instagram** (écran du même nom) — par boutique, le profil tel qu'il
  sera : les posts programmés (calendrier) au-dessus des 30 dernières publications
  relues chez Instagram (`GET /{ig_user_id}/media`, table
  `social_instagram_media`, cron horaire + bouton « Relire chez Instagram »).
  Contrôles : alignement de chaque frise (programmée ou déjà en ligne), posts « à
  valider » qui la décaleraient s'ils étaient refusés, demi-heure autour des
  frises. Glisser une case programmée sur une autre échange leurs créneaux.
- **Dark posts** (pub uniquement) — dans « Créer un post », destination
  « Réservé à la publicité » : le post n'est jamais publié (statut « Dark post »,
  hors file, hors grille Instagram) et ouvre l'assistant des publicités. En
  « Dark post », la créa suit le format du post : image, carrousel (ordre fixe pour
  un panorama ou une frise) ou vidéo (`advideos` + `video_data`). Jusqu'à 3
  variantes A/B/C (visuel et/ou texte) : une créa et une pub par variante, résultats
  par variante dans le suivi. Après création, le serveur relit l'id du dark post
  (`effective_object_story_id`, `effective_instagram_media_id`) : liens, réutilisation
  dans une autre pub (« Sponsoriser un post existant », likes et commentaires
  cumulés), commentaires relus toutes les heures (`social_commentaire`), masquables
  depuis l'écran Publicités.
- **Post → modèle franchisé** — dans « Créer un post », « Enregistrer comme modèle
  franchisé » fige le visuel du post (studio, montage ou photos) et son texte en un
  modèle de l'espace franchisé (`visual.style = fixe`) : la boutique le publie sur sa
  Page à la date de son choix ({ville}, {adresse}, {horaires} remplis pour elle ;
  produit du post écrit en clair ; phrase libre de 90 caractères si ouverte ;
  validation au choix). Réglable ensuite dans Espace franchisé → Modèles.
- **Instagram unique du réseau** (`socIgUnique()`, src/instagram.php) — un seul
  compte Instagram (celui de la marque) pour toutes les boutiques : réglage
  `instagram_unique`, sinon automatique dès que la marque a son Instagram et
  qu'aucune boutique n'en a un autre. Un post qui coche Instagram pour une ou
  plusieurs boutiques part **une seule fois** sur ce compte ({ville} devient la
  liste des boutiques visées), la Grille Instagram n'a qu'une grille, les
  connexions Instagram des boutiques reprennent celle de la marque. Instagram est
  **réservé au franchiseur** : l'espace franchisé ne propose pas ce canal et n'en a
  qu'une vue en lecture (« Instagram du réseau », `GET /franchise/instagram`, en
  direct chez Meta, avec ce que le réseau y a programmé).
- **Calendriers des Pages et comptes Instagram** (`src/calendriers.php`, lecture
  directe, rien en base) — le Calendrier montre, à côté des posts du module, ce que
  chaque Page Facebook et chaque compte Instagram visibles du System User ont
  publié (ou programmé dans Meta Business Suite) sur la période affichée
  (`GET /{page}/published_posts`, `/scheduled_posts`, `GET /{ig}/media`), relu à
  chaque changement de mois et au bouton « Actualiser ». Une pastille par compte,
  avec sa photo de profil, sert de filtre (elle compte aussi les posts du module
  qui visent ce compte). Ces publications sont en lecture seule (lien vers Facebook
  ou Instagram, réactions, commentaires, partages) et ne sont jamais enregistrées ni
  mises en file ; celles faites par le module ne sont pas montrées deux fois.
  Ouvrir une publication lit ses chiffres en direct (`GET /meta/post-stats?id=&compte=`) :
  vues, personnes touchées, réactions, commentaires, partages, clics (Facebook) ou
  enregistrements (Instagram). Les vues demandent `read_insights` (Facebook) et
  `instagram_manage_insights` (Instagram) ; sans elles, la fiche le dit.
- **Contrôle** (maquette, `public/SA Controle.dc.html`, rien n'est envoyé) — depuis
  la fiche d'une publication de boutique dans le Calendrier (« Contrôler → ») :
  annotation au stylo ou au carré rouge (coordonnées en %, comme le contrôle des
  tâches du BO), grille de la charte, gravité, échéance, puis « Valider et envoyer
  par mail » avec l'aperçu du mail (visuel annoté, points numérotés). L'envoi réel
  reste à décider.
- **Suivi des boutiques** (`src/suivi.php`, lecture directe, rien en base) — par
  Page (et son Instagram) sur 7, 28 ou 90 jours : abonnés actuels
  (`followers_count`), gagnés et perdus par jour (insights `page_daily_follows_unique`
  / `page_daily_unfollows_unique`, repli `page_follows` ; Instagram `follower_count`,
  30 jours au plus), budget dépensé par jour (insights des comptes publicitaires au
  niveau pub, rapportés à la Page qui porte la pub), coût par abonné gagné, effet
  de la pub (abonnés par jour avec et sans budget) et classement. Permissions :
  `read_insights` et `instagram_manage_insights` pour l'historique ; sans elles,
  le total actuel et le budget s'affichent avec le motif.
  Dans l'espace franchisé, **Suivi de ma boutique** montre la même chose pour la
  seule Page de la boutique (`GET /franchise/suivi`, règle « Voir le suivi de sa
  boutique ») ; le compte Instagram, commun à tout le réseau, n'y est pas attribué.
- **Publicités** — l'assistant envoie sa description complète ; le serveur crée
  campagne → ad set → créa → pub, un jeu par compte boutique ou un jeu réseau
  (un ad set par boutique, pour lire les résultats boutique par boutique).
  Boutique bilingue : un ad set et une créa par langue (`targeting.locales`).
  Centres d'intérêt et langues résolus par Targeting Search, montants en
  centimes, position de la boutique (caisse, sinon adresse géocodée).
  Pause / reprise / arrêt / plafond appliqués chez Meta.
- **Campagnes déjà chez Meta** (`src/metacampagnes.php`) — bouton « Récupérer les
  campagnes Meta » (Publicités Meta, et depuis Opérations) et synchro horaire : chaque
  compte publicitaire du portefeuille (plus ceux de la marque et des boutiques) est
  relu — campagnes non supprimées (archivées : 13 derniers mois), ensembles, pubs,
  statistiques `date_preset=maximum` — et chaque campagne créée hors du module
  (Gestionnaire de publicités, agence…) devient une pub « Récupérée de Meta »
  (`meta-{campaign_id}`) : statut, budget (campagne, sinon somme des ensembles),
  plafond, dates (« en continu » sans fin), dépense, impressions, couverture, clics,
  CTR, CPC, CPM, répétition, vignette, lien vers le Gestionnaire. Boutique déduite
  de la Page de chaque pub (sinon du compte, sinon du nom). Les campagnes créées par
  le module ne sont jamais réimportées. Depuis l'écran : pause, reprise, arrêt et
  plafond (au niveau de la campagne Meta) et rattachement à une opération du module
  (sa dépense et ses résultats comptent alors dans le Retour sur opération). Une
  campagne disparue chez Meta passe « Arrêtée ». Les montants restent dans la devise
  du compte (affichée si ce n'est pas l'euro). Compte rendu du dernier passage :
  `social_parametre.meta_campagnes_import`.
- **Formulaires de leads** — `POST /{page_id}/leadgen_forms` sur chaque Page ;
  nouvelle version = nouveau formulaire + archivage de l'ancien ; les pubs
  reçoivent une nouvelle créa avec le nouveau formulaire.
- **Synchronisation** (cron) — statut et dépense des pubs, leads
  (`GET /{form_id}/leads`), résultats de campagne. Le webhook `leadgen`
  (`/social/api/v1/social/webhook`) est prêt, mais l'app Meta n'a qu'une URL de
  webhook par objet Page, déjà utilisée par Brand Guard : le cron fait donc le
  rattrapage toutes les heures.
- **Retour sur campagne** — CA additionnel = CA des produits de la campagne
  pendant la période − moyenne des mêmes jours sur les 6 semaines précédentes
  (**estimation**, affichée comme telle).

## Espace franchisé (`/social_franchisee`)

Un outil de communication et de marketing pour chaque boutique, entièrement cadré
par le franchiseur (écran **Espace franchisé** du module, dans le rail) :

- **La boutique voit** son calendrier (ses posts et ceux du réseau qui la
  concernent), ses promotions, les campagnes du réseau où elle figure et leurs
  résultats pour elle — jamais les autres boutiques.
- **Elle publie uniquement depuis les modèles ouverts** : texte FR/NL, titre et
  visuel figés (style du studio, mention) ; elle ne remplit que les champs que le
  modèle ouvre — un produit de *son* catalogue (API TFBuddy), une remise bornée,
  une offre d'une liste, un prix promotionnel, une période, une phrase courte (sans
  lien, @ ni #), sa photo. L'écran franchiseur montre le visuel exact obtenu.
- **Règles du réseau**, vérifiées par le serveur : espace ouvert ou fermé, validation
  (toujours / selon le modèle / jamais), posts par semaine, délai minimum, horizon,
  jours et heures de publication, canaux, remise maximale, durée maximale d'une
  promotion, ce que la boutique peut voir. Les posts « à valider » arrivent dans le
  calendrier du franchiseur ; un refus est motivé et la boutique voit le motif.
- **Connexion** : le compte atelierby du gérant (API TFBuddy, espace « owner » :
  `POST /owner/auth/login` puis `GET /owner/shops` — le mot de passe n'est pas
  conservé) ou un code d'accès par boutique créé par le franchiseur. Session dans un
  cookie signé, limitée aux boutiques du compte. « Voir comme cette boutique » ouvre
  l'espace d'une boutique depuis l'écran franchiseur.
- Démonstration : `/social_franchisee/?demo=1`.

## Transport dans l'ERP

Le module se déplace d'un bloc (tables `social_*`, `src/`, `public/`, `franchisee/`,
`bin/`). Ses six liens à l'hôte — base, session, utilisateur, journal, boutiques,
session des franchisés — se remplacent dans un seul fichier, `config/hote.php`
(modèle : `config/hote.example.php`), sans toucher au code. Mode d'emploi complet :
**`docs/INTEGRATION-ERP.md`**.

## Mode démonstration

Si l'API ne répond pas (ou avec `?demo=1`), les écrans tournent sur les données
d'exemple de la maquette, dans le navigateur, avec un bandeau « Mode
démonstration ». Rien n'est alors envoyé à Meta.

## Installation / déploiement

Automatique : modifier `deploy.trigger` et pousser (workflow
`.github/workflows/deploy.yml` : recette sur MySQL puis déploiement SSH). Secret
GitHub requis dans ce dépôt : `DEPLOY_SSH_PASSWORD` (le même que le BO).

À la main, sur le serveur : `sudo bin/deploy.sh` — copie vers `/var/www/social`,
alias Apache `/social`, `config/config.php` (chemin du BO, aucun secret),
tables `social_*`, cron `/etc/cron.d/social` :

```
* * * * *   php bin/cron.php queue   # posts échus
7 * * * *   php bin/cron.php sync    # pubs, leads, résultats
40 6 * * *  php bin/cron.php conn    # contrôle des connexions Meta
```

Développement :

```bash
SOCIAL_BO_DIR=/chemin/vers/consultant_BO php -S 0.0.0.0:8090 -t public public/router.php
```

## Recette

```bash
SOCIAL_TEST_DSN='mysql:host=127.0.0.1;dbname=social_test' SOCIAL_TEST_USER=… SOCIAL_TEST_PASS=… php bin/tests.php
```

113 contrôles sur une vraie base (vidée : son nom doit contenir « test ») et un
Graph Meta et API TFBuddy simulés : tables, clés en base, boutiques lues dans le BO, file de
publication, échecs partiels et relance, pubs, formulaires, leads, ventes,
session du cockpit.

## Structure

```
public/            écrans de la maquette (SA *.dc.html) + social-data.js (couche de données)
  montage.js       montages Instagram (carrousel panoramique, frise, collages 4:5) dessinés sur canvas
  franchise-visuel.js  visuel figé d'un modèle franchisé (partagé avec l'espace franchisé)
franchisee/        espace boutique /social_franchisee (connexion, calendrier, publier, promotions, campagnes)
config/            config.example.php (premier lancement), hote.example.php (points d'accroche de l'hôte)
  api/index.php    contrôleur frontal de l'API
  vendor/          React, ReactDOM, Babel, html-to-image (embarqués, aucun CDN)
src/
  bootstrap.php    lien BO : config, base, session, social_parametre, journal
  schema.php       tables social_*
  store.php        état des écrans (lecture, enregistrement par différences)
  shops.php        boutiques du BO + test des connexions Meta
  meta.php         client Graph / Marketing API
  publish.php      file et publication des posts
  ads.php          publicités
  leads.php        formulaires, leads, webhook
  results.php      insights Meta + ventes caisse
  instagram.php    grille du profil (lecture des publications), frise (publication, verrou)
  darkposts.php    dark posts : relecture chez Meta, réutilisation, commentaires (lecture, masquage)
  metacampagnes.php  campagnes existantes chez Meta : récupération, rafraîchissement, pilotage
  calendriers.php  publications des Pages et Instagram lues en direct pour le Calendrier
  suivi.php        suivi des boutiques : abonnés gagnés / perdus face au budget (en direct)
  franchise.php    espace franchisé : règles, modèles, accès, sessions, publication depuis un modèle
  api.php          routes
bin/               cron.php, deploy.sh, franchisee-build.sh, tests.php
```
