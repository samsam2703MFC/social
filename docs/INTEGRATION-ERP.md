# Transporter le module Social & Ads dans l'ERP

Le module est construit pour être déplacé tel quel. Il est indépendant de son hôte
actuel (le BO consultant) sauf en **six points**, tous remplaçables sans modifier le
code du module, depuis un seul fichier : `config/hote.php`.

## 1. Ce qui forme le module (à déplacer d'un bloc)

| Dossier | Contenu |
|---|---|
| `src/` | le serveur : PHP 8.3 sans dépendance (pas de Composer), une fonction d'entrée `socHandle($method, $path)` |
| `public/` | les écrans du franchiseur (`SA *.dc.html`) et leur couche de données `social-data.js` ; `public/api/index.php` = contrôleur frontal ; `public/uploads/` = visuels |
| `franchisee/` | les écrans de l'espace boutique (`/social_franchisee`) ; assemblés avec les ressources partagées par `bin/franchisee-build.sh` |
| `bin/` | `cron.php` (file, synchro, connexions, audit), `tests.php` (recette), `deploy.sh`, `franchisee-build.sh` |
| `config/` | `config.example.php` (où trouver la base au premier lancement), `hote.example.php` (points d'accroche) |

**Données** : uniquement des tables préfixées `social_` (créées et mises à jour par
`socInstall()`, idempotent ; les colonnes ajoutées par une version sont migrées
automatiquement). Aucune table de l'hôte n'est modifiée. Pour déménager les données :
`mysqldump base $(mysql -Nse "SHOW TABLES LIKE 'social\_%'" base) > social.sql`.

**Paramètres et clés** : tous en base, `social_parametre` (token Meta, App ID, secret,
comptes API TFBuddy, URL publique, URL de l'espace franchisé, règles de l'espace
franchisé…). Rien dans le code, rien dans l'environnement.

## 2. Les six points d'accroche (`config/hote.php`)

Copier `config/hote.example.php` vers `config/hote.php` et décommenter ce que l'ERP
fournit. Chaque entrée est facultative ; sans elle, le comportement « BO » s'applique.

| Point | Aujourd'hui (BO consultant) | Dans l'ERP |
|---|---|---|
| `pdo` | identifiants MySQL lus dans `config/config.php` du BO | la connexion PDO de l'ERP (les tables `social_*` y vivent) |
| `auth` | cookie `cockpit_s` signé avec `ceo_app_setting.authSecret` | la session / le droit « Social » de l'ERP |
| `utilisateur` | `social_parametre.utilisateur` | le nom de l'utilisateur connecté |
| `journal` | copie dans `ceo_journal_entry` (le module garde son journal `social_activite`) | le journal de l'ERP |
| `boutiques` | API TFBuddy `/public/shops` + `ceo_shop` (zone, franchisé) + `brand_guard_pages` (Page Facebook) + caisse (position) | reçoit la liste calculée et la rend complétée ou remplacée |
| `franchise_session` | **seul accès des gérants** : sans lui, l'espace franchisé n'est ouvert qu'en aperçu par le franchiseur, ou sur les boutiques cochées dans la règle « accès ouvert » (`accesLibre`, une liste, en attendant l'ERP) — pas de page de connexion propre à l'espace | le gérant déjà connecté à l'ERP entre directement dans son espace : `['shops' => [...], 'name' => '…']` ; l'ERP l'y envoie par `/social_franchisee/calendrier?shop={id}` |

La recette (`bin/tests.php`, section « Transport dans l'ERP ») vérifie chacun de ces
points.

Autres dépendances, sans adaptation nécessaire :
- **API TFBuddy** (`src/tfb.php`) : adresse et comptes dans `social_parametre`, modifiables
  dans Paramètres → API TFBuddy. Si l'ERP *est* cette API, rien ne change.
- **Ventes** (Retour sur campagne) : d'abord l'API (`/shops/{id}/statistics/sales/…`), puis,
  à défaut, les tables de caisse `transaction`, `transaction_product`, `product` si elles existent.
- **Meta** : uniquement depuis le serveur (`src/meta.php`), avec le token en base.
- **Opérations** (`src/operations.php`) : les campagnes commerciales du Marketing, lues par
  `GET {marketing_api}/campaigns`, `/campaigns/{id}` et `/shops` (lecture seule). Dans l'ERP,
  pointer `marketing_api` (Paramètres) vers ses routes `/api/v1/marketing`.

## 3. Monter le module dans l'ERP

1. **API** : router `{base}/api/v1/social/*` vers le module, en lui passant le chemin
   qui suit `/api/v1/social` :
   ```php
   require '/chemin/social/src/api.php';
   socHandle($_SERVER['REQUEST_METHOD'], $cheminApresApiV1Social);
   ```
   (c'est exactement ce que fait `public/api/index.php`).
2. **Écrans franchiseur** : servir `public/`. Ils appellent l'API en relatif
   (`api/v1/social`) ; pour une autre adresse, définir `window.SOCIAL_API_BASE` avant
   `social-data.js`.
3. **Espace franchisé** : `bin/franchisee-build.sh DEST API_BASE MEDIA_BASE [PROPRES]` assemble un
   dossier statique ; `API_BASE` = l'adresse de l'API, `MEDIA_BASE` = l'adresse de
   `public/` (pour les visuels `uploads/…`). Il doit être servi par le **même hôte** que
   l'API (session de l'ERP, cookie de l'aperçu). Le menu de l'ERP ouvre
   `calendrier?shop={id}` ; chaque lien de l'espace garde `?shop=`. Les adresses propres
   (`calendrier`, `publier`, `operations`, `instagram`) sont réécrites vers les fichiers `.dc.html`
   par `.htaccess` (Apache, mod_rewrite) : un autre serveur fait la même réécriture, ou l'espace
   s'assemble avec `PROPRES=0` (liens en `Calendrier.dc.html?shop={id}`, toujours valables).
4. **Médias** : `public/uploads/` (ou `SOCIAL_UPLOADS_DIR`) doit rester joignable depuis
   Internet à l'adresse `social_parametre.url_publique` : Meta y télécharge les visuels.
5. **Tâches planifiées** : `php bin/cron.php queue` (chaque minute), `sync` (horaire),
   `conn` (quotidien), `audit` (quotidien) — dans le planificateur de l'ERP.
6. **Recette** : `SOCIAL_TEST_DSN='mysql:host=…;dbname=social_test' php bin/tests.php`
   sur une base de test vide (elle est vidée).

## 4. Les routes de l'API

Franchiseur (session `auth`) :
`GET /status` (sans session) · `GET|POST /state` · `GET /shops` · `GET /shops/api` ·
`POST /posts/{id}/relancer` · `POST /connexions/test` · `POST /sync` · `POST /parametres` ·
`POST /parametres/cles` · `POST /parametres/tfb` · `GET /meta/portefeuille` ·
`POST /meta/portefeuille/rafraichir` · `POST /meta/portefeuille/appliquer` ·
`GET|POST /meta/ids/auto` · `POST /meta/ids/auto/lancer` · `GET /meta/campagnes` · `GET /meta/comptes[?forcer=1]` (devise et minimums Meta des comptes pub) · `GET /commentaires?jours=` · `POST /commentaires/relire` · `POST /commentaires/traiter` · `POST /commentaires/repondre` (réponse publiée sous le commentaire Facebook ou Instagram, permission Meta pages_manage_engagement / instagram_manage_comments ; commentaires de toutes les Pages et de l'Instagram, classés vert / orange / rouge, en retard au-delà du délai de réponse ; plus d'écran côté marque : ils se traitent dans l'espace franchisé, et ceux d'un compte du réseau vont à la boutique qu'ils citent) · `GET /google?jours=` · `POST /google/relire` · `GET|POST /kit` (kit du mois de l'espace franchisé : modèle, date conseillée, note ; qui l'a programmé) · `GET /bilan[?forcer=1]` (bilan de la semaine par boutique : régularité, réactivité, avis, Meta, classement ; calculé par le cron) · `GET|POST /pub/regles` (règle de pause automatique des pubs du module : coût par clic maximum et dépense minimale, par devise de compte ; désactivée par défaut, appliquée à la relecture horaire) · `GET /pub/meilleurs[?forcer=1]` (meilleur post de la semaine de chaque boutique, Page et Instagram, lu chez Meta et gardé 2 h ; « Booster » ouvre l'assistant, créa `object_story_id` ou `source_instagram_media_id`) · `GET /pub/demandes` · `POST /pub/demandes/refuser` (demandes de boost des boutiques, table `social_boost_demande` ; passée « boostée » à la création de la pub) · `GET /messages?filtre=afaire|boutiques|tout` · `GET /messages/conversation?id=` · `POST /messages/relire` · `POST /messages/repondre` · `POST /messages/traiter` · `POST /messages/attribuer` (messages privés Instagram : lus et envoyés par l'API Messenger pour Instagram, permission `instagram_manage_messages` ; réponse dans les 24 h suivant le dernier message du client ; plus d'écran côté marque : une conversation du compte du réseau va d'elle-même à la boutique que le client cite, et l'espace franchisé la traite ; tables `social_conversation`, `social_message` ; relus par le cron) · `POST /google/reglages` (synchronisation automatique 1×/jour) · `GET /avis/{boutique}?s=qr|affiche|lien` (PUBLIC : compte le scan puis renvoie vers la fenêtre d'avis de la fiche Google ; QR code et affiche `Affiche avis.html`) · `POST /google/synchroniser` (avis Google : la Réputation digitale du BO, tables `ceo_shop_reputation` / `ceo_shop_review` LUES seulement, et les avis classés vert / orange / rouge ; « synchroniser » appelle `POST {cockpit}/reputation/sync` du BO, paramètre `cockpit_api`) ·
`POST /meta/campagnes/rafraichir` · `GET /operations` · `POST /operations/rattacher` · `GET /meta/calendriers?du=&au=` · `GET /meta/post-stats?id=&compte=` · `GET /suivi/abonnes?jours=7|28|90` ·
`GET|POST /audit/api` · `GET|POST /instagram/grille` · `GET|POST /darkposts/commentaires` ·
`POST /darkposts/commentaires/{id}/masquer` · `GET /franchise/admin` ·
`POST /franchise/admin/regles` · `POST /franchise/admin/apercu` ·
`GET|POST /webhook` (signé par Meta).

Espace franchisé (session de l'ERP via `franchise_session`, ou aperçu du franchiseur ; limitée à ses boutiques) :
`POST /franchise/deconnexion` (ferme l'aperçu) ·
`GET /franchise/etat` · `POST /franchise/posts` · `POST /franchise/posts/{id}/annuler` · `GET /franchise/suivi?jours=7|28|90` · `GET /franchise/vues?jours=7|28|90` · `GET /franchise/instagram` · `GET /franchise/instagram/abonnes` ·
`GET /franchise/operations` · `GET /franchise/publicites` · `GET /franchise/commentaires?jours=` · `POST /franchise/commentaires/traiter` (commentaires de la Page de la boutique, règle « voirCommentaires » ; avis Google, règle « voirAvisGoogle ») · `GET /franchise/google?jours=` · `GET /franchise/kit` (« À publier ce mois-ci ») · `GET /franchise/semaine` (« Ma semaine » : sa ligne du bilan, la moyenne des autres, son rang si la règle « voirClassement » le permet) · `GET|POST /franchise/boost` (proposer au réseau de booster une publication de sa Page ou de son Instagram — pas d'un compte du réseau —, relue chez Meta ; 3 en attente au plus ; règle « proposerBoost ») · `GET /franchise/messages` · `GET /franchise/messages/conversation?id=` · `POST /franchise/messages/repondre` · `POST /franchise/messages/traiter` (les conversations Instagram confiées à la boutique par le réseau, ou de son propre Instagram ; règles « voirMessages » et « repondreMessages » ; une autre boutique reçoit 404) · `POST /franchise/commentaires/repondre` (règle « repondreCommentaires » ; les rouges suivent la règle « rouges » : réseau ou boutique ; « delaiReponseHeures » : au-delà, le réseau prend le relais) · `GET /franchise/catalogue/offres?produit=` · `GET /franchise/calendrier?du=&au=` · `GET /franchise/calendrier/stats?id=&compte=` · `GET /franchise/media/webshop?u=`
(toutes avec `?shop=` : la boutique, vérifiée contre la session).

### Espace franchisé intégré dans le module Marketing

La version franchisé du module Marketing (`/marketingc`) affiche les pages de l'espace franchisé dans un cadre (groupe « Mes réseaux sociaux ») : `/social_franchisee/{calendrier|publier|commentaires|messages|avis-google|operations|instagram}?embed=1`. Avec `embed=1`, l'espace retire son propre menu (barre latérale, barres du téléphone), chaque lien garde le paramètre, et la page affichée est annoncée au Marketing par `postMessage({ type: 'social:page', page: 'f:cal' | 'f:com' | … })` (même origine). La boutique et les droits restent ceux de la session de l'espace franchisé (ERP) : l'adresse ne choisit qu'entre les boutiques de cette session. Un gérant de plusieurs boutiques garde un petit sélecteur flottant.
