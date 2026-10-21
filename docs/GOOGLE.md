# Connecter Google (fiches des boutiques, Google Ads, Google Analytics 4)

Un seul compte Google suffit : celui qui **gère les fiches Google des boutiques**, qui a accès au(x)
**compte(s) Google Ads** et à la **propriété Google Analytics 4 du webshop**. Le module garde trois clés
(Paramètres → Google) : l'ID du client OAuth, sa clé secrète et un jeton de rafraîchissement — les deux
dernières restent sur le serveur, jamais dans l'écran (`src/googleapi.php`).

Compter 20 minutes pour les étapes 1 à 6. Les accès de l'étape 7 dépendent de Google (de quelques
minutes pour Google Ads à quelques jours ou semaines pour les fiches) : **les demander tout de suite**.

## 1. Un projet Google Cloud

<https://console.cloud.google.com> → sélecteur de projet → **Nouveau projet** (ex. « Atelier By – Social »).
Noter son **numéro de projet** (carte « Informations sur le projet » du tableau de bord) : il est demandé
par le formulaire des fiches (étape 7a).

## 2. Activer les API

Menu → **API et services → Bibliothèque**, puis activer :

| API | Pour |
|---|---|
| My Business Account Management API | lister les comptes de fiches |
| My Business Business Information API | lister les fiches (établissements) |
| Google My Business API | avis et réponses aux avis, posts sur la fiche |
| Business Profile Performance API | statistiques des fiches (appels, itinéraires, clics) |
| Google Ads API | campagnes, dépenses, clics |
| Google Analytics Admin API | lister les propriétés GA4 |
| Google Analytics Data API | visites et commandes du webshop |

## 3. L'écran de consentement

**API et services → Écran de consentement OAuth** (« Google Auth Platform ») :

- type **Interne** si le compte fait partie d'une organisation Google Workspace, sinon **Externe** ;
- nom de l'application, e-mail d'assistance et e-mail du développeur ;
- **publier l'application : « En production »**. En « Test », Google fait expirer le jeton de
  rafraîchissement au bout de **7 jours** (le module le dira : « Google refuse le jeton de
  rafraîchissement… »). Google peut afficher « application non validée » au moment d'autoriser :
  « Paramètres avancés → Accéder à … » suffit pour son propre compte.

## 4. Le client OAuth

**API et services → Identifiants → Créer des identifiants → ID client OAuth** :

- type d'application : **Application Web** ;
- URI de redirection autorisé : `https://developers.google.com/oauthplayground` ;
- copier l'**ID client** (`….apps.googleusercontent.com`) et la **clé secrète** (`GOCSPX-…`).

> Pourquoi pas un bouton « Se connecter avec Google » dans le module ? Google refuse une adresse de retour
> en `http` ou en adresse IP (`185.180.206.46`). Avec un nom de domaine en `https`, on pourra l'ajouter.

## 5. Le jeton de rafraîchissement

<https://developers.google.com/oauthplayground> :

1. roue ⚙ en haut à droite → cocher **Use your own OAuth credentials** → coller l'ID et la clé secrète ;
2. à gauche, champ « Input your own scopes », coller les trois autorisations (séparées par un espace) :
   `https://www.googleapis.com/auth/business.manage https://www.googleapis.com/auth/adwords https://www.googleapis.com/auth/analytics.readonly`
3. **Authorize APIs** → se connecter avec **le compte Google qui gère les fiches, Ads et Analytics** →
   tout autoriser ;
4. **Exchange authorization code for tokens** → copier le **Refresh token** (`1//…`).

## 6. Dans le module

**Paramètres → Google** : coller l'ID du client, la clé secrète et le jeton de rafraîchissement (et le
compte administrateur Google Ads s'il y en a un), puis **Enregistrer et vérifier**. Le module dit, API par
API, ce qui marche déjà : ✓ en vert, ⏳ en orange avec la raison (le plus souvent : un accès de
l'étape 7 pas encore accordé).

Puis **Rechercher mes fiches, comptes Ads et propriétés GA4**, vérifier le rattachement proposé pour
chaque boutique (la fiche dont l'identifiant Google est celui de la Réputation digitale du BO, sinon celle
dont le nom cite la boutique) et **Enregistrer les rattachements**.

## 7. Les accès que Google doit accorder

### a. Fiches des boutiques (Business Profile API) — à demander

- Formulaire : <https://support.google.com/business/contact/api_default> → **Application for Basic API
  Access** ; indiquer le **numéro de projet** (étape 1) et un e-mail **propriétaire ou gérant** des fiches.
- Tant que Google n'a pas accordé l'accès, le quota du projet est à **0** (console → API → Business
  Profile → Quotas) et le module affiche « accès pas encore accordé » ; accordé : 300 requêtes/minute.
- Google examine des fiches complètes, validées et actives depuis un moment, avec le site web renseigné.

### b. Google Ads — niveau « Explorer »

- **Plus de jeton développeur** : depuis le 9 septembre 2026, l'accès dépend du projet Google Cloud.
- Console Google Cloud → **Google Ads API → Présentation** (« Overview ») → demander l'accès
  **Explorer** (examen automatique la plupart du temps ; jusqu'à 2 880 opérations par jour sur les vrais
  comptes, assez pour lire et piloter les campagnes du réseau). Sans lui, Google répond
  `CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION` et le module le dit.
- « Basic » (plus d'opérations) demande une validation de la marque du projet : pas nécessaire au départ.
- Un compte administrateur (MCC) n'est utile que pour plusieurs comptes Ads liés ; s'il y en a un,
  renseigner son numéro dans le module (en-tête `login-customer-id`).

### c. Google Analytics 4 — rien à demander

Le compte connecté doit avoir au moins le rôle **Lecteur** sur la propriété GA4 du webshop.

## Ensuite

Une fois la connexion vérifiée et les rattachements enregistrés, le module pourra (prochaines étapes) :
lire tous les avis de chaque fiche et **y répondre depuis le module** (franchisé ou réseau), publier une
offre ou un événement sur la fiche, afficher les statistiques des fiches (appels, itinéraires, clics),
montrer les campagnes Google Ads à côté de celles de Meta dans « Publicités », et lire les visites et
commandes du webshop dans Google Analytics.
