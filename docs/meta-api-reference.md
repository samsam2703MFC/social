# Franchise Buddy · Social & Ads — Référence Meta API pour Claude Code

Ce document relie chaque écran du prototype aux appels Meta que le backend doit faire. Le front n'appelle **jamais** Meta : tout passe par `{API_BASE_URL}/api/v1/social/{domaine}/{ressource}` (JWT court + refresh token). La file de programmation est gérée par le backend, pas par Meta.

> À valider au moment d'implémenter : sections 1 (Pages API, fenêtre de programmation) et 6 (leadgen_forms) contre la doc officielle. La version d'API (v25.0 ou v26.0) est une variable de config (écran Paramètres → API Meta).

## Socle commun

| Élément | Valeur |
|---|---|
| Base | `https://graph.facebook.com/{VERSION}/` — `VERSION` en config |
| Auth posts | Page access token, un par boutique |
| Auth pubs | Token System User du Business Manager |
| Montants | **centimes** : 20 € → `2000` |
| Dates | ISO 8601 ou timestamp Unix |
| IDs par boutique | `page_id`, `ad_account_id` (`act_…`), `instagram_user_id`, `pixel_id` — écran Paramètres → API Meta |
| Niveaux d'accès | « Limited access » (ex-Standard), « Full access » (ex-Advanced) depuis mai 2026 |

Permissions : `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`, `ads_management`, `pages_manage_ads`, `leads_retrieval`.

## 1. Écran « Créer un post » → Pages API

| Cas (prototype) | Endpoint | Paramètres |
|---|---|---|
| Visuel composé (studio) | `POST /{page_id}/photos` | `url` (PNG 1080×1080 rendu côté serveur, stocké sur R2), `caption`, `published`, `scheduled_publish_time` |
| 1 photo telle quelle | `POST /{page_id}/photos` | `url` ou `source`, `caption`, … |
| Plusieurs photos | `POST /{page_id}/photos` (`published=false`) pour chaque photo → `POST /{page_id}/feed` | `message`, `link`, `attached_media: [{media_fbid}]` |
| Texte / lien seul | `POST /{page_id}/feed` | `message`, `link` |
| Vidéo | `POST /{page_id}/videos` | `file_url` ou `source`, `title`, `description`, `thumb` |
| Reel | `POST /{page_id}/video_reels` | `upload_phase=start` → upload → `upload_phase=finish` + `video_id`, `description`, `video_state=PUBLISHED` |
| Instagram | `POST /{ig_user_id}/media` → `POST /{ig_user_id}/media_publish` | `image_url`, `caption` (lien non cliquable) |

Programmation : `published=false` + `scheduled_publish_time`, **entre 10 minutes et 30 jours** dans le futur (bloqué côté UI). Champ « Étaler sur X min » : le backend décale chaque Page de `X / (n−1)` minutes.

Variables `{ville}`, `{adresse}`, `{horaires}`, `{prix}`, `{produit_du_jour}` : résolues par le backend, boutique par boutique, **avant** l'appel Meta.

Erreurs à remonter dans le calendrier (statut Échec / Partiel) : code 190 (token expiré), 368 (Page restreinte), 2 (timeout, relançable).

## 2–5. Écran « Publicités » → Marketing API (assistant en 4 objets)

Mode « Un par boutique » : le backend crée un jeu complet par `ad_account_id`. Mode « Compte réseau » : un seul jeu sur le compte réseau avec une zone par boutique.

### Étape 1 · Campagne — `POST /act_{ad_account_id}/campaigns`

| Champ UI | Paramètre |
|---|---|
| Nom | `name` * |
| Objectif | `objective` * : `OUTCOME_AWARENESS`, `OUTCOME_TRAFFIC`, `OUTCOME_ENGAGEMENT`, `OUTCOME_LEADS`, `OUTCOME_SALES` |
| Statut à la création | `status` * : `PAUSED` par défaut |
| Catégorie spéciale | `special_ad_categories` * : `[]` ou `EMPLOYMENT` (recrutement), `HOUSING`, `FINANCIAL_PRODUCTS_SERVICES`, `ISSUES_ELECTIONS_POLITICS` |
| — | `buying_type: AUCTION` |
| Stratégie d'enchère | `bid_strategy` : `LOWEST_COST_WITHOUT_CAP`, `LOWEST_COST_WITH_BID_CAP`, `COST_CAP`, `LOWEST_COST_WITH_MIN_ROAS` |
| Plafond | `spend_cap` (centimes) |
| Niveau du budget = Campagne (CBO) | `daily_budget` / `lifetime_budget` ici |
| Niveau du budget = Ad set (ABO) | `is_adset_budget_sharing_enabled: false` (obligatoire depuis v24) |

Catégorie spéciale cochée → l'UI force rayon ≥ 15 km, 18–65 ans, tous genres.

### Étape 2 · Ad set — `POST /act_{ad_account_id}/adsets`

| Champ UI | Paramètre |
|---|---|
| — | `name` *, `campaign_id` *, `status` *, `billing_event: IMPRESSIONS` * |
| Optimiser pour | `optimization_goal` * (filtré par objectif) |
| Destination | `destination_type` : `WEBSITE`, `ON_AD`, `MESSENGER`, `WHATSAPP`, `INSTAGRAM_DIRECT`, `PHONE_CALL` |
| Objet promu | `promoted_object` : `{page_id}` (leads, engagement) ou `{pixel_id, custom_event_type}` (ventes) |
| Budget | `daily_budget` ou `lifetime_budget` * (si ABO) |
| Début / Fin | `start_time`, `end_time` (obligatoire avec `lifetime_budget`) |
| Enchère max | `bid_amount` (si stratégie plafonnée) |
| Bénéficiaire / Payeur | `dsa_beneficiary` *, `dsa_payor` * (pré-remplis depuis Paramètres) |
| Rayon par boutique | `targeting.geo_locations.custom_locations: [{latitude, longitude, radius, distance_unit: "kilometer"}]` |
| Âge, genre | `targeting.age_min`, `age_max`, `genders` (`[1]` hommes, `[2]` femmes) |
| Centres d'intérêt | `targeting.flexible_spec: [{interests: [{id, name}]}]` — ids via Targeting Search |
| Plateformes | `targeting.publisher_platforms` |
| Audience Advantage+ | `targeting.targeting_automation: {advantage_audience: 0|1}` |

### Étape 3 · Créa — `POST /act_{ad_account_id}/adcreatives`

| Champ UI | Paramètre |
|---|---|
| Sponsoriser un post existant | `object_story_id: "{page_id}_{post_id}"` |
| Nouvelle créa | upload `POST /act_{id}/adimages` → `image_hash`, puis `object_story_spec: {page_id, instagram_user_id, link_data}` |
| Texte principal, Titre, Description, Lien | `link_data.message`, `name`, `description`, `link` * |
| Bouton | `call_to_action.type` : `LEARN_MORE`, `SHOP_NOW`, `ORDER_NOW`, `GET_DIRECTIONS`, `CALL_NOW`, `SIGN_UP`, `CONTACT_US`, `GET_OFFER`, `GET_QUOTE`, `APPLY_NOW`, `MESSAGE_PAGE`, `WHATSAPP_MESSAGE` |
| Formulaire de leads | `call_to_action.value.lead_gen_form_id` |
| Paramètres de suivi | `url_tags` (UTM) |

### Étape 4 · Pub — `POST /act_{ad_account_id}/ads`

`name` *, `adset_id` *, `creative: {creative_id}` *, `status` *, `tracking_specs` (pixel), `conversion_domain: latelierby.be`.

Suivi (tableau Publicités) : statut Meta `IN_PROCESS` → « En revue », `ACTIVE`, `DISAPPROVED` + motif → « Refusée », `PAUSED`, plafond atteint → « Budget épuisé ». Pause / reprise / arrêt = `POST /{ad_id}` avec `status`.

## 6. Écran « Formulaires de leads » — `POST /{page_id}/leadgen_forms`

| Champ UI | Paramètre |
|---|---|
| Nom interne | `name` * |
| Langue | `locale` (`fr_FR`, `nl_BE`, `en_GB`) |
| Questions standard | `questions: [{type}]` * : `FULL_NAME`, `FIRST_NAME`, `LAST_NAME`, `EMAIL`, `PHONE`, `CITY`, `ZIP`, `COMPANY_NAME`, `JOB_TITLE` |
| Questions personnalisées | `{type: "CUSTOM", key, label, options: [{key, value}]}` |
| Politique de confidentialité | `privacy_policy: {url, link_text}` * |
| Lien après envoi | `follow_up_action_url` |
| Écran d'introduction | `context_card: {title, style: LIST_STYLE|PARAGRAPH_STYLE, content, button_text}` |
| Écran de remerciement | `thank_you_page: {title, body, button_type: VIEW_WEBSITE|CALL_BUSINESS|DOWNLOAD, button_text, website_url}` |
| Écran de confirmation | `is_optimized_for_quality` |

**Versions** : un formulaire ne se modifie pas après création. « Nouvelle version » = création d'un nouveau formulaire, puis archivage de l'ancien (`POST /{form_id}` `status=ARCHIVED`) et remplacement de `lead_gen_form_id` dans les créas actives. Côté base : table `leadform` avec `family_id`, `version`, `meta_form_id`, `status`.

Branchement pub : `OUTCOME_LEADS` + `optimization_goal=LEAD_GENERATION` + `destination_type=ON_AD` + `promoted_object={page_id}` + `call_to_action.value.lead_gen_form_id`.

Récupération : webhook `leadgen` sur chaque Page (temps réel) + rattrapage `GET /{form_id}/leads` (`id`, `created_time`, `field_data`, `ad_id`, `form_id`).

## 7. Écran « Retour sur campagne » — `GET /{campaign_id}/insights`

| Indicateur UI | Champ |
|---|---|
| Dépense, Portée, Impressions, Fréquence | `spend`, `reach`, `impressions`, `frequency` |
| Clics, CTR, CPC, CPM | `clicks`, `ctr`, `cpc`, `cpm` |
| Engagement, Vues vidéo | `actions` (`post_engagement`, `video_view`) |
| ROAS webshop | `purchase_roas` |

Paramètres : `time_range: {since, until}` (dates de la campagne, ou jusqu'à aujourd'hui si en cours), `time_increment=1`, `level=campaign` (réseau) ou `adset` (par boutique), `breakdowns` optionnels (`age`, `gender`, `publisher_platform`).

Les ventes viennent de l'API de l'ERP (caisse GoPOS), jamais de la caisse directement. CA additionnel = CA des produits de la campagne pendant la période − moyenne des mêmes jours sur les 6 semaines précédentes (**estimation**, affichée comme telle). Marge = CA additionnel × taux de marge. ROAS = CA additionnel / dépense. ROI = (marge − dépense) / dépense.

## Multilingue FR / NL

- Chaque boutique porte `langs` : `["fr"]`, `["nl"]` ou bilingue (`["nl","fr"]`, première langue = langue du visuel). Un groupe peut imposer sa langue à toutes ses boutiques (écran Paramètres → Groupes).
- Post : deux textes (`text`, `textNl`) et des textes de visuel localisés (`eyebrow`, `title`, `phrase`, `unit`, `promoSub`). Le backend publie sur chaque Page la ou les langues de la boutique ; boutique bilingue → légende NL puis FR dans le même `message`, visuel rendu dans la première langue.
- Variables : `{horaires}` et `{produit_du_jour}` sont résolues dans la langue de la Page (`7u00–18u30`, nom produit `nameNl`).
- Publicités : une créa par langue. Boutique bilingue → deux ad sets, `targeting.locales` NL et FR (ids de locale via Targeting Search).
- Formulaires de leads : `locale` `fr_FR` ou `nl_BE` ; une version par langue si besoin.
- Validation bloquante : texte NL manquant alors qu'une boutique NL est ciblée.

## Fichiers du prototype

| Écran | Fichier |
|---|---|
| Calendrier | `SA Calendrier.dc.html` |
| Créer un post (+ studio visuel) | `SA Post.dc.html`, `Posts Facebook.dc.html` |
| Campagnes | `SA Campagnes.dc.html` |
| Publicités | `SA Publicites.dc.html` |
| Formulaires de leads | `SA Leads.dc.html` |
| Retour sur campagne | `SA Retour.dc.html` |
| Paramètres et connexions | `SA Parametres.dc.html` |
| Données d'exemple + calculs | `social-data.js` |
