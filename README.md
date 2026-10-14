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
| Opérations | les campagnes commerciales du **Marketing du BO**, lues par son API (`GET /api/v1/marketing/campaigns`, `/campaigns/{id}`, `/shops`) — jamais créées ni modifiées ici |
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
| `social_campagne` | ancienne table des opérations locales — plus utilisée (les opérations viennent du Marketing du BO) |
| `social_publicite` | les publicités (objectif, statut Meta, budget, plafond, dépense, motif de refus) |
| `social_formulaire` | les formulaires de leads, une ligne par version |
| `social_lead` | les leads reçus |
| `social_groupe` / `social_modele` | groupes de boutiques / modèles de posts |
| `social_boutique` | langues, horaires, identifiants Meta (`page_id`, `ad_account_id`, `instagram_user_id`, `pixel_id`), état des connexions |
| `social_publication` | la file de publication (post × boutique × canal, heure prévue, résultat Meta) |
| `social_meta_objet` | objets créés chez Meta (campagne, ad set, créa, pub, formulaire) |
| `social_resultat` | résultats Meta + ventes caisse par campagne et boutique |
| `social_rattachement` | le lien seul entre une publication lue chez Meta (`mfb-…`, `mig-…`) et une opération du Marketing (`bo-…`) |
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

**Créer un post → catalogue** (`src/catalogue.php`, `GET /catalogue/nav?shop=`, `GET /catalogue/offres`) :
on choisit par **catégorie** (groupe de catégories du webshop, ex. « Traiteur ») → **sous-catégorie**
(« Sandwiches garnis ») → **produit**, ou par **gamme** (« Automnale »), ou on retient toute une
catégorie, sous-catégorie ou gamme. Source : l'appel du webshop
`GET /shops/{id}/products/available?webshop_active=1&alias_type=webshop&include=webshop,portions,availability_periods,webshop_availability`
(photo du produit, illustrations des catégories et sous-catégories, gammes, `portion_price` = prix de
vente de la boutique) et `GET /product-availability-periods` (photo des gammes) — mis en cache 30 min.
Le **prix** n'est donné que si **une seule** boutique est ciblée ; plusieurs : pas de prix. Les
**promotions et bundles** actifs (`GET /admin/promotions/{bundles|buy-x-get-y|quantity|scheduled-product-discount}/{id}`,
cache 15 min) sont liés quand ils visent le produit, sa sous-catégorie ou l'un des produits de la
sélection, avec leur portée (toutes les boutiques ou lesquelles) ; les **formules** d'un produit viennent
de `GET /webshop/shops/{id}/products/{p}/menus`. « Utiliser » met l'offre dans le bandeau promo du
visuel et dans le texte. Démonstration : un instantané réel du webshop de Halle (`public/demo-catalogue.json`).
Le choix pose aussi le **lien du post** (sauf un lien saisi à la main) : la page du webshop du produit,
de la sous-catégorie ou de la catégorie. Les **photos du webshop** sont sur Cloudflare R2, sans en-tête
CORS : un visuel qui les dessine ne s'exporte pas. Le module les sert donc lui-même
(`GET /media/webshop?u=` : https, hôte R2 public `*.r2.dev` ou hôte d'image du catalogue en cache,
pas de redirection, image reconnue à ses octets) et l'écran les remet en JPEG avant de composer le
visuel. En ligne, un visuel composé qui ne s'exporte pas **bloque** l'enregistrement avec un message,
au lieu de partir en photo brute (sans logo ni titre).

Les boutiques ne viennent pas que de l'API : une Page Facebook du portefeuille Meta
(relu chaque heure) qui n'est ni celle de la marque ni celle d'une boutique de
l'API devient un **magasin test**, hors caisse (« L'Atelier by - Gembloux » →
« Gembloux », identifiant `pg` + chiffres de la Page). Il reste **hors du circuit** :
jamais compris dans « Tout le réseau » / « Toutes », ni dans les opérations du réseau,
les groupes, le suivi des boutiques, le graphique du calendrier, les publicités ou
l'espace franchisé (`socReseau()` côté serveur, `SA.SHOPS` côté écrans ; les magasins
test sont à part dans `SA.TEST_SHOPS`). On le vise en cochant sa pastille « (test) »
dans Créer un post : sa Page seulement, jamais l'Instagram du réseau. Ses connexions et
identifiants restent visibles dans Paramètres. Sans caisse : ni webshop, ni ventes.

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
  qui visent ce compte). Ces publications ne se modifient pas ici (lien vers Facebook
  ou Instagram, réactions, commentaires, partages), mais peuvent être supprimées ; elles
  ne sont jamais enregistrées ni mises en file ; celles faites par le module ne sont pas montrées deux fois.
  Supprimer (fiche du post, `POST /posts/supprimer`) : un post pas encore en ligne quitte le
  calendrier et la file ; un post publié est supprimé, au choix, aussi de Facebook
  (`DELETE /{post-id}`, token de la Page) et d'Instagram (`DELETE /{ig-media-id}`, permission
  `instagram_manage_contents` ; une frise : ses 3 publications), ou du module seul ; une
  publication faite hors du module est supprimée chez Meta. Tant que Meta refuse une
  suppression, le post reste au calendrier avec le motif par Page et par canal ; une
  publication déjà absente chez Meta compte comme supprimée. Un dark post se retire depuis
  l'écran Publicités. La fenêtre de confirmation signale les publicités actives du post.
  Ouvrir une publication lit ses chiffres en direct (`GET /meta/post-stats?id=&compte=`) :
  vues, personnes touchées, réactions, commentaires, partages, clics (Facebook) ou
  enregistrements (Instagram). Les vues demandent `read_insights` (Facebook) et
  `instagram_manage_insights` (Instagram) ; sans elles, la fiche le dit.
- **Contrôle** (maquette, `public/SA Controle.dc.html`, rien n'est envoyé ni
  enregistré) — les publications déjà en ligne sur les Pages des boutiques, lues
  en direct chez Meta sur 7, 30 ou 90 jours (comme le Calendrier), plus les posts
  publiés depuis l'espace franchisé ; aussi depuis la fiche d'un post publié dans
  le Calendrier (« Contrôler → »). Annotation au stylo ou au carré rouge
  (coordonnées en %, comme le contrôle des tâches du BO), grille de la charte,
  gravité, échéance, puis « Valider et envoyer par mail » avec l'aperçu du mail
  (visuel annoté, points numérotés). Verdicts gardés dans l'onglet seulement.
  L'envoi réel et l'enregistrement des verdicts restent à décider.
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
- **Liens du webshop** — variables `{webshop}` (le webshop de la boutique :
  `https://webshop.atelierby.be/?shop={n° de caisse}`), `{webshop_produit}` /
  `{webshop_produit:ID}`, `{webshop_categorie}` / `{webshop_categorie:ID}` et
  `{webshop_groupe:ID}`, résolues boutique par boutique dans le lien et dans le texte du
  post (`socWebshopUrl`, `src/publish.php` ; adresse de base : réglage `webshop_url`), aux
  paramètres que le webshop lit lui-même : catégorie `?shop=4&category=group:28`,
  sous-catégorie `…&sub=category:31000` (sans groupe : `category=category:31000`), produit :
  sa sous-catégorie + `&product={id}&open=product` (le webshop lit ces deux paramètres mais
  n'ouvre pas encore la fiche : on arrive sur la sous-catégorie, produit en vue). Le groupe
  d'une catégorie et la catégorie d'un produit viennent du catalogue lu (`socWebshopPlan`).
  Magasin test (hors caisse) : la page du webshop sans boutique. Une boutique sans horaires :
  la phrase qui cite `{horaires}` disparaît (plus de « Ouvert . »). Boutons « Lien rapide »
  sous le champ Lien.
- **Espace franchisé aligné** — « Opérations du réseau » (`GET /franchise/operations`) :
  chaque campagne du Marketing où figure la boutique, avec ce qui y est rattaché en
  lecture seule (posts du module, publications de sa Page et de l'Instagram du réseau
  relues chez Meta, publicités) et toutes ses métriques (budget dépensé, portée,
  impressions, clics, interactions, vues vidéo, ventes et CA additionnel estimés,
  tickets, quantités, coûts, objectif à sa part du budget). Les modèles franchisés
  portent une opération et un lien (webshop de la boutique, produit, catégorie) : le
  post de la boutique en hérite ; dans « Publier », la boutique garde ou retire ce lien,
  ou choisit son webshop — jamais un lien libre. Le calendrier franchisé montre
  l'opération de chaque post.
- **Ciblage** — une pastille on/off par boutique dans « Créer un post » (Toutes = tout le réseau).
- **Opérations** (`src/operations.php`) — ce sont les campagnes commerciales du
  Marketing du BO, lues en direct par son API (adresse : Paramètres → API Marketing,
  par défaut `http://127.0.0.1/consulant_bo/api/v1/marketing`). Une boutique du
  Marketing (code `erp-N`) est la boutique N du module ; une campagne « réseau »
  sans boutique choisie vise tout le réseau. Produits = articles de l'offre,
  objectif = premier levier chiffré, budget par boutique = budget local. Le module
  ne les crée ni ne les modifie (bouton « Créer dans le Marketing du BO ↗ ») : il y
  rattache ses posts et ses publicités (`bo-{id}`) et en calcule le retour.
  Rattacher (`POST /operations/rattacher {kind: post|ad, id, operation}`) : depuis la
  fiche d'un post du Calendrier (sélecteur « Opération », posts du module comme
  publications déjà en ligne sur les Pages), depuis une publicité (Publicités Meta),
  ou depuis l'opération (panneau « Rattacher » : posts et pubs de la période, à
  cocher). Post du module et publicité : leur champ `campaign` ; publication lue
  chez Meta : le lien seul, table `social_rattachement`.
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

## Écran intégré dans une autre application

Avec `?embed=1`, un écran du module s'affiche sans son rail : c'est ainsi que le
module Marketing (`/marketing/`, groupes « Social & Ads » du menu, et le bouton
« Créer un post pour cette campagne » du suivi de campagne) affiche tous les
écrans `SA *.dc.html` dans un cadre. Chaque lien interne garde le paramètre, la
navigation reste donc dans le cadre ; chaque écran annonce sa clé de page à
l'application hôte (`postMessage` `{type: 'social:page', page}`, même origine),
dont le menu suit sans recharger le cadre. `?op=bo-19` (ou `?op=19`, l'identifiant de la
campagne dans le Marketing) présélectionne l'« Opération liée » d'un nouveau post.

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
