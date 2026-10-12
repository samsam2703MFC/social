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
| `franchise_session` | le franchisé se connecte avec son compte atelierby (API `/owner/auth/login`) ou un code d'accès | le gérant déjà connecté à l'ERP entre directement dans son espace : `['shops' => [...], 'name' => '…']` |

La recette (`bin/tests.php`, section « Transport dans l'ERP ») vérifie chacun de ces
points.

Autres dépendances, sans adaptation nécessaire :
- **API TFBuddy** (`src/tfb.php`) : adresse et comptes dans `social_parametre`, modifiables
  dans Paramètres → API TFBuddy. Si l'ERP *est* cette API, rien ne change.
- **Ventes** (Retour sur campagne) : d'abord l'API (`/shops/{id}/statistics/sales/…`), puis,
  à défaut, les tables de caisse `transaction`, `transaction_product`, `product` si elles existent.
- **Meta** : uniquement depuis le serveur (`src/meta.php`), avec le token en base.

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
3. **Espace franchisé** : `bin/franchisee-build.sh DEST API_BASE MEDIA_BASE` assemble un
   dossier statique ; `API_BASE` = l'adresse de l'API, `MEDIA_BASE` = l'adresse de
   `public/` (pour les visuels `uploads/…`). Il doit être servi par le **même hôte** que
   l'API (cookie de session).
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
`GET|POST /meta/ids/auto` · `POST /meta/ids/auto/lancer` · `GET /meta/campagnes` ·
`POST /meta/campagnes/rafraichir` ·
`GET|POST /audit/api` · `GET|POST /instagram/grille` · `GET|POST /darkposts/commentaires` ·
`POST /darkposts/commentaires/{id}/masquer` · `GET /franchise/admin` ·
`POST /franchise/admin/regles` · `POST /franchise/admin/acces` ·
`POST /franchise/admin/acces/{id}/revoquer` · `POST /franchise/admin/apercu` ·
`GET|POST /webhook` (signé par Meta).

Espace franchisé (session franchisé, limitée à ses boutiques) :
`POST /franchise/connexion` · `POST /franchise/deconnexion` · `GET /franchise/session` ·
`GET /franchise/etat` · `POST /franchise/posts` · `POST /franchise/posts/{id}/annuler`.
