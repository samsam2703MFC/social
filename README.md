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
| `social_activite` | le journal d'activité |

Chaque table a des colonnes typées (lisibles en SQL) et une colonne `data` qui
garde l'objet complet de l'écran (réglages du studio visuel, ciblage de
l'assistant Publicités, questions d'un formulaire). Tables créées au premier
appel (`src/schema.php`), sans migration, comme le BO.

Les clés Meta se saisissent dans **Paramètres → API Meta → Clés d'accès Meta**
(un champ vide ne change rien ; la valeur n'est jamais réaffichée).

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
- **Publicités** — l'assistant envoie sa description complète ; le serveur crée
  campagne → ad set → créa → pub, un jeu par compte boutique ou un jeu réseau
  (un ad set par boutique, pour lire les résultats boutique par boutique).
  Boutique bilingue : un ad set et une créa par langue (`targeting.locales`).
  Centres d'intérêt et langues résolus par Targeting Search, montants en
  centimes, position de la boutique (caisse, sinon adresse géocodée).
  Pause / reprise / arrêt / plafond appliqués chez Meta.
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

80 contrôles sur une vraie base (vidée : son nom doit contenir « test ») et un
Graph Meta simulé : tables, clés en base, boutiques lues dans le BO, file de
publication, échecs partiels et relance, pubs, formulaires, leads, ventes,
session du cockpit.

## Structure

```
public/            écrans de la maquette (SA *.dc.html) + social-data.js (couche de données)
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
  api.php          routes
bin/               cron.php, deploy.sh, tests.php
```
