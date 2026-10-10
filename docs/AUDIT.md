# Audit — module Social & Ads et API TFBuddy

Date : 10/10/2026 · module déployé sur `http://185.180.206.46/social/` · API
auditée : `https://atelierby.tfbuddy.com/api/v1` (swagger
`/api/swagger/openapi.json`, 933 routes dont 600 GET).

## 1. En bref

| Sujet | État |
|---|---|
| Module /social | en ligne, recette automatique 113/113, déploiement GitHub Actions opérationnel |
| Données | boutiques, catalogue (531 produits et gammes, prix, noms NL) et ventes **lus dans l'API TFBuddy** ; la base du BO ne sert plus qu'en complément et en repli |
| Stockage | toutes les données et clés du module dans des tables `social_*` (clés dans `social_parametre`) |
| API TFBuddy | **275 routes GET fonctionnent sur 600** ; 0 panne serveur réelle ; 266 non testables automatiquement (identifiant à fournir) |
| Meta | **aucun token Meta** sur le serveur (ni dans le BO, ni dans le module) : rien ne peut être publié tant qu'il n'est pas saisi |
| Sécurité | 3 points critiques (§3) dont le mot de passe des boîtes mail des boutiques exposé par l'API |

## 2. API TFBuddy — ce qui fonctionne, ce qui ne fonctionne pas

Méthode (`src/audit.php`, relancée chaque nuit à 5 h 20 et depuis Paramètres →
API TFBuddy) : chaque route GET du swagger est appelée avec le compte de son
réalm (consultant pour `/consultant/*`, aucun pour `/public/*`, admin sinon).
Un identifiant de boutique reçoit celui de Halle (4), une date reçoit
aujourd'hui. Les routes qui demandent un paramètre sont rejouées avec une
période (7 jours, puis 6 mois). **Lecture seule** : aucune route POST/PATCH/DELETE.

| Statut | Routes | Signification |
|---|---:|---|
| Fonctionne | **275** | 2xx avec réponse JSON (dont 12 après ajout d'une période) |
| Paramètre requis | 41 | l'API attend un paramètre que le swagger ne décrit pas |
| Refusée | 5 | 403 avec les comptes admin et consultant |
| Absente | 11 | 404 (route déclarée mais introuvable, ou données absentes) |
| Injoignable | 2 | délai dépassé (25 s) |
| Non testée | 266 | identifiant non devinable (`{id}` d'un produit, d'un employé, d'un client…) |

La liste route par route, filtrable (fonctionnent / ne fonctionnent pas /
non testées / utilisées par le module), est dans **Paramètres → API TFBuddy**.

### Anomalies de l'API à signaler à l'équipe TFBuddy

1. **500 au lieu de 4xx** : 38 routes répondent `500 Validation errors in request`
   quand un paramètre manque (`/clients`, `/courses`, `/franchisee-employees`,
   `/franchisee-shop/{id}/products`, `/sales/{date}`, `/daily-shifts`…). Une
   erreur de saisie ne doit pas ressembler à une panne serveur, et la réponse
   contient le chemin du fichier PHP sur le serveur (`/var/www/html/api/…`).
2. **Le swagger ne décrit pas les paramètres** de la plupart des routes
   (« contracts are not inferred by coverage generation »). Exemple mesuré :
   `statistics/sales/product-category-groups` accepte `date` et
   `date_from`/`date_to`, mais ignore sans erreur `from`/`to` et
   `start_date`/`end_date` — il rend alors la journée en cours, ce qui fausse
   silencieusement un calcul.
3. **Routes déclarées mais absentes** : `/cases`, `/cases/stats`,
   `/shops/{id}/cases`, `/shops/{id}/cases/stats`, `/consultant/tasks/helpdesk`
   (404 Apache : déclarées hors de `/api/v1`) ; `operational-procedure-categories/name-aliases`
   et `operational-procedures/title-aliases` (« Unknown field »).
4. **403 « Shop context required »** sur `/panel/royalties/*` et
   `/panel/sales-anomalies*` : un en-tête ou paramètre de boutique attendu,
   non documenté.
5. **Lenteurs** : `/owner/network/sales/trend` 22 s, `/consultant/shops/pnl/monthly`
   21 s, `/panel/shops/{id}/b2b/yearly` 8,7 s ; `/panel/shops/{id}/b2b/dashboard`
   et `/products/technical-sheets` dépassent 25 s.

### Routes utilisées par le module

| Donnée | Route | Résultat |
|---|---|---|
| Boutiques (adresse, horaires, contact, TVA) | `GET /public/shops` (sans compte) + `GET /shops` | OK — 4 boutiques |
| Catalogue (prix, catégories) | `GET /shops/{id}/products/available` (+ `?lang_code=nl`) | OK — 519 produits, noms NL |
| Gammes | `GET /product-availability-periods` | OK — 15 gammes |
| Ventes par produit sur une période | `GET /shops/{id}/statistics/sales/product-category-groups?date_from&date_to` | OK |
| Tickets d'une période | `GET /shops/{id}/statistics/sales/kpis?date_from&date_to` | OK |
| Connexion | `POST /admin/auth/login`, `POST /consultant/auth/login` | OK (comptes repris du BO) |

Disponibles et utiles plus tard : `marketing-materials` (19 visuels de marque,
liens Drive), `marketing-campaigns` (campagnes de l'ERP), `owner/network/dashboard/today`,
`consultant/shops/category-sales`.

## 3. Sécurité

1. **CRITIQUE — l'API TFBuddy rend le mot de passe des boîtes mail des
   boutiques en clair** : `GET /shops` et `GET /shops/{id}` contiennent
   `email_password` (ainsi que IBAN, BIC et numéro de compte) pour tout compte
   admin. À retirer de la réponse côté API, puis à **changer ces mots de passe** :
   ils ont circulé dans un journal de déploiement de ce dépôt pendant l'audit.
   Le journal a été supprimé aussitôt, et la sonde masque désormais tout champ
   dont le nom évoque un mot de passe, une banque ou un contact. Le module ne
   lit ni ne stocke ces champs.
2. **CRITIQUE — accès ouvert** : l'écran de connexion du BO est désactivé
   (`auth => false`, accès « par redirection depuis l'ERP »). Le module suit
   la même règle : n'importe qui connaissant l'adresse peut lire les données
   et, une fois le token Meta saisi, **publier sur les Pages des boutiques et
   créer des publicités**. À fermer avant de saisir le token : activer l'écran
   de connexion du BO (`COCKPIT_AUTH=1`, le module l'applique aussitôt), ou
   restreindre `/social` par IP / authentification Apache.
3. **ÉLEVÉ — HTTP sans TLS** : le module et le BO sont servis en `http://`.
   Les mots de passe et le token Meta saisis dans Paramètres circulent en clair.
   Le BO notait déjà que `https://185.180.206.46/` ne répond pas.
4. Mesures en place : clés et mots de passe en base, marqués `secret`, jamais
   renvoyés au navigateur (vérifié par la recette) ; l'adresse de l'API TFBuddy
   n'accepte que `*.tfbuddy.com` (elle reçoit les mots de passe à la
   connexion) ; webhook Meta vérifié par signature HMAC ; visuels déposés jamais
   exécutés (`.htaccess`).

## 4. D'où viennent les données

| Donnée | Source principale | Repli / complément |
|---|---|---|
| Liste des boutiques, adresse, horaires, contact | API TFBuddy | BO `ceo_shop` |
| Franchisé, zone | BO `ceo_shop` | — |
| Page Facebook | Portefeuille Meta (bouton Rafraîchir) | Brand Guard du BO |
| Identifiants Meta (Page, compte pub, Instagram, pixel), marque comprise | `social_boutique` | — |
| Catalogue et gammes | API TFBuddy | `social_parametre.catalogue`, puis le catalogue livré |
| Ventes (retour sur campagne) | API TFBuddy | caisse dans la base du BO |
| Posts, campagnes, pubs, formulaires, leads, résultats | tables `social_*` | — |
| Clés Meta, comptes API, réglages | `social_parametre` | recopiés une fois du BO au premier lancement |
| Session utilisateur | cookie du cockpit | — |

## 5. Meta

- Aucun token Meta configuré sur le serveur. Les connexions (4 boutiques de l’API + la marque,
  × Page / Instagram / compte pub) sont donc « non connectées », à raison.
- À saisir dans **Paramètres → API Meta → Clés d'accès Meta** : l'identifiant
  de l'application (App ID), le token du System User, la clé secrète de l'app,
  le jeton du webhook. Avec l'App ID et la clé secrète, le module vérifie le
  token (`debug_token`) : validité, date d'expiration, permissions réellement
  accordées (affichées à la place de la liste théorique).
- Puis **Portefeuille Business Manager → Rafraîchir** : relit les Pages,
  comptes publicitaires, comptes Instagram et pixels du Business, les rapproche
  des boutiques (nom de la ville) et de la marque, et remplit les identifiants.
- Permissions requises : `pages_manage_posts`, `pages_read_engagement`,
  `pages_show_list`, `ads_management`, `pages_manage_ads`, `leads_retrieval`
  (+ `business_management` pour lire le portefeuille).
- Le webhook `leadgen` est prêt, mais l'app Meta n'a qu'une URL de webhook par
  objet Page (déjà prise par Brand Guard) : les leads sont relus chaque heure.

## 6. Déménagement vers le serveur atelierby.tfbuddy.com

Le module est prêt à être déplacé : tout ce qu'il lui faut est **une base
MySQL** (ses tables `social_*`) et **l'accès HTTPS à l'API TFBuddy et à Meta**.

1. Copier le dossier, et dans `config/config.php` pointer `db` vers la base
   (le module n'a plus besoin du BO pour fonctionner).
2. Exporter les tables `social_*` de l'ancienne base et les importer :
   paramètres, clés, posts, file de publication, historique.
3. Mettre à jour `url_publique` (Paramètres → API Meta) : Meta y télécharge les visuels.
4. Ce qui ne suivra pas sans le BO : franchisé et zone (`ceo_shop`), pages
   résolues par Brand Guard (remplacées par le portefeuille Meta), journal du
   cockpit, ventes en repli sur la caisse locale. Rien de bloquant.
5. Session : sans le BO à côté, prévoir l'authentification du nouveau serveur
   (le module lit aujourd'hui le cookie du cockpit).

## 7. Actions recommandées, par priorité

1. Changer les mots de passe des boîtes mail `*@atelierby.be` et retirer
   `email_password` (et les données bancaires) de `GET /shops` côté API.
2. Activer l'authentification (BO `COCKPIT_AUTH=1` ou protection Apache de
   `/social`) **avant** de saisir le token Meta.
3. Rétablir HTTPS sur le serveur.
4. Créer le System User Meta, lui attribuer les Pages, comptes pub et pixel,
   générer le token, puis le saisir avec l'App ID et la clé secrète ; cliquer
   « Rafraîchir » dans Portefeuille Business Manager, vérifier les
   correspondances, « Tester toutes les connexions ».
5. Côté API TFBuddy : 4xx au lieu de 500 pour les paramètres manquants,
   paramètres décrits dans le swagger, routes `/cases` à corriger.
