<?php
declare(strict_types=1);

/**
 * Les tables du module, préfixe `social_`, créées au premier appel
 * (idempotent). Elles vivent dans la base du BO sans jamais toucher à ses
 * tables `ceo_*` ni à celles du panel.
 *
 * TOUT est en base, clés comprises :
 *  - social_parametre  : réglages et clés d'accès (token Meta, secret de l'app,
 *                        jeton webhook, version d'API, DSA, compte réseau, URL
 *                        publique, marge, catalogue…) — lus ici, nulle part ailleurs ;
 *  - social_content    : les posts (calendrier, créer un post) ;
 *  - social_campagne   : les campagnes ;
 *  - social_publicite  : les publicités ;
 *  - social_formulaire : les formulaires de leads (une ligne par version) ;
 *  - social_lead       : les leads reçus ;
 *  - social_groupe, social_modele : groupes de boutiques, modèles de posts ;
 *  - social_boutique   : ce que le social sait d'une boutique en plus du BO
 *                        (langues, horaires, identifiants Meta, connexions) ;
 *  - social_publication: la file de publication (post × boutique × canal) ;
 *  - social_meta_objet : les objets créés chez Meta (campagne, ad set, créa, pub, formulaire) ;
 *  - social_resultat   : résultats Meta + ventes par campagne et boutique ;
 *  - social_instagram_media : les dernières publications de chaque compte
 *                        Instagram, relues chez Meta (grille du profil) ;
 *  - social_activite   : le journal d'activité.
 *
 * Les colonnes typées portent ce que le serveur interroge et ce qu'on lit en
 * SQL ; la colonne `data` garde l'objet complet tel que l'écran le manipule
 * (réglages du studio visuel, ciblage de l'assistant, questions d'un formulaire).
 */
function socInstall(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $maj = 'maj_le DATETIME NOT NULL';

    Db::exec('CREATE TABLE IF NOT EXISTS social_parametre (cle VARCHAR(64) PRIMARY KEY, valeur LONGTEXT NOT NULL,'
        . 'secret TINYINT(1) NOT NULL DEFAULT 0, ' . $maj . ')' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_content (id VARCHAR(64) PRIMARY KEY, titre VARCHAR(200) NULL, type VARCHAR(60) NULL,'
        . 'statut VARCHAR(30) NULL, date_pub DATE NULL, heure VARCHAR(5) NULL, campagne_id VARCHAR(64) NULL,'
        . 'boutiques TEXT NULL, facebook TINYINT(1) NOT NULL DEFAULT 0, instagram TINYINT(1) NOT NULL DEFAULT 0,'
        . 'texte_fr TEXT NULL, texte_nl TEXT NULL, lien VARCHAR(500) NULL, image VARCHAR(500) NULL, auteur VARCHAR(120) NULL,'
        . 'publie_le DATETIME NULL, erreur TEXT NULL, data LONGTEXT NOT NULL, ' . $maj . ','
        . 'KEY idx_statut (statut, date_pub), KEY idx_campagne (campagne_id))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_campagne (id VARCHAR(64) PRIMARY KEY, nom VARCHAR(200) NULL, type VARCHAR(40) NULL,'
        . 'statut VARCHAR(30) NULL, debut DATE NULL, fin DATE NULL, budget DECIMAL(12,2) NULL, objectif_kpi VARCHAR(80) NULL,'
        . 'objectif_valeur DECIMAL(12,2) NULL, boutiques TEXT NULL, produits TEXT NULL, data LONGTEXT NOT NULL, ' . $maj . ','
        . 'KEY idx_dates (debut, fin))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_publicite (id VARCHAR(64) PRIMARY KEY, nom VARCHAR(200) NULL, campagne_id VARCHAR(64) NULL,'
        . 'objectif VARCHAR(60) NULL, statut VARCHAR(30) NULL, portee VARCHAR(20) NULL, type_budget VARCHAR(20) NULL,'
        . 'budget DECIMAL(12,2) NULL, plafond DECIMAL(12,2) NULL, depense DECIMAL(12,2) NULL, debut DATE NULL, fin DATE NULL,'
        . 'content_id VARCHAR(64) NULL, formulaire_id VARCHAR(64) NULL, motif TEXT NULL, data LONGTEXT NOT NULL, ' . $maj . ','
        . 'KEY idx_campagne (campagne_id), KEY idx_statut (statut))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_formulaire (id VARCHAR(64) PRIMARY KEY, famille VARCHAR(64) NULL, version INT NULL,'
        . 'nom VARCHAR(200) NULL, locale VARCHAR(10) NULL, statut VARCHAR(30) NULL, cree_le DATE NULL, boutiques TEXT NULL,'
        . 'erreur_meta TEXT NULL, data LONGTEXT NOT NULL, ' . $maj . ', KEY idx_famille (famille, version))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_groupe (id VARCHAR(64) PRIMARY KEY, nom VARCHAR(160) NULL, langue VARCHAR(10) NULL,'
        . 'boutiques TEXT NULL, data LONGTEXT NOT NULL, ' . $maj . ')' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_modele (id VARCHAR(64) PRIMARY KEY, nom VARCHAR(160) NULL, type VARCHAR(60) NULL,'
        . 'texte_fr TEXT NULL, texte_nl TEXT NULL, data LONGTEXT NOT NULL, ' . $maj . ')' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_boutique (shop_id VARCHAR(16) PRIMARY KEY, langues VARCHAR(10) NULL,'
        . 'adresse VARCHAR(300) NULL, horaires VARCHAR(120) NULL, horaires_nl VARCHAR(120) NULL,'
        . 'page_id VARCHAR(40) NULL, ad_account_id VARCHAR(40) NULL, instagram_user_id VARCHAR(40) NULL, pixel_id VARCHAR(40) NULL,'
        . 'conn_facebook VARCHAR(12) NULL, conn_instagram VARCHAR(12) NULL, conn_publicite VARCHAR(12) NULL, verifie_le DATETIME NULL,'
        . 'data LONGTEXT NOT NULL, ' . $maj . ')' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_activite (id BIGINT AUTO_INCREMENT PRIMARY KEY, le DATETIME NOT NULL,'
        . 'qui VARCHAR(120) NOT NULL, quoi VARCHAR(60) NOT NULL, detail TEXT NOT NULL, KEY idx_le (le))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_publication (id BIGINT AUTO_INCREMENT PRIMARY KEY,'
        . 'content_id VARCHAR(64) NOT NULL, shop_id VARCHAR(16) NOT NULL, canal VARCHAR(12) NOT NULL,'
        . "prevue_le DATETIME NOT NULL, statut VARCHAR(12) NOT NULL DEFAULT 'attente',"
        . 'meta_id VARCHAR(80) NULL, code_erreur INT NULL, erreur VARCHAR(500) NULL, essais INT NOT NULL DEFAULT 0, ' . $maj . ','
        . 'UNIQUE KEY uq_cible (content_id, shop_id, canal), KEY idx_due (statut, prevue_le))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_meta_objet (id BIGINT AUTO_INCREMENT PRIMARY KEY,'
        . 'kind VARCHAR(12) NOT NULL, local_id VARCHAR(64) NOT NULL, shop_id VARCHAR(16) NULL, account VARCHAR(40) NULL,'
        . 'campaign_id VARCHAR(40) NULL, adset_id VARCHAR(40) NULL, creative_id VARCHAR(40) NULL, ad_id VARCHAR(40) NULL,'
        . 'form_id VARCHAR(40) NULL, meta_status VARCHAR(40) NULL, error VARCHAR(500) NULL, created_at DATETIME NOT NULL,'
        . 'KEY idx_local (kind, local_id), KEY idx_ad (ad_id), KEY idx_form (form_id))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_lead (id VARCHAR(40) PRIMARY KEY, formulaire_id VARCHAR(64) NULL,'
        . 'form_meta VARCHAR(40) NULL, shop_id VARCHAR(16) NULL, pub VARCHAR(160) NULL, cree_le DATETIME NOT NULL,'
        . 'champs LONGTEXT NOT NULL, KEY idx_form (formulaire_id, cree_le))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_resultat (campagne_id VARCHAR(64) NOT NULL, shop_id VARCHAR(16) NOT NULL,'
        . 'depense DECIMAL(12,2) NULL, portee INT NULL, impressions INT NULL, clics INT NULL, ca_campagne DECIMAL(12,2) NULL,'
        . 'ca_reference DECIMAL(12,2) NULL, data LONGTEXT NOT NULL, synchro_le DATETIME NOT NULL, PRIMARY KEY (campagne_id, shop_id))' . $t);

    Db::exec('CREATE TABLE IF NOT EXISTS social_instagram_media (media_id VARCHAR(40) PRIMARY KEY, shop_id VARCHAR(16) NOT NULL,'
        . 'type VARCHAR(20) NULL, media_url VARCHAR(1000) NULL, vignette_url VARCHAR(1000) NULL, permalink VARCHAR(300) NULL,'
        . 'publie_le DATETIME NOT NULL, legende TEXT NULL, maj_le DATETIME NOT NULL, KEY idx_shop (shop_id, publie_le))' . $t);

    socParamsInit();
    socTfbInit();
}

/**
 * Premier lancement : les valeurs par défaut des paramètres. Les clés Meta
 * sont reprises UNE fois du BO (Brand Guard : variable d'environnement ou
 * bloc `meta` de son config) pour que le module marche tout de suite ; elles
 * vivent ensuite dans social_parametre, modifiables dans Paramètres → API Meta.
 */
function socParamsInit(): void
{
    if (socSetting('installe_le') !== null) { return; }
    $c = socialConfig();
    $b = (array) (boConfig()['meta'] ?? []);
    $env = static fn (string $k, string $key) => trim((string) (getenv($k) ?: ($b[$key] ?? '')));
    $d = socDefaults();
    $bg = boSetting('brandGuard');
    $defauts = [
        'meta_system_token' => [$env('META_SYSTEM_TOKEN', 'systemToken'), true],
        'meta_app_secret'   => [$env('META_APP_SECRET', 'appSecret'), true],
        'meta_verify_token' => [$env('META_VERIFY_TOKEN', 'verifyToken'), true],
        'meta_version'      => [(is_array($bg) ? trim((string) ($bg['graphVersion'] ?? '')) : '') ?: (string) ($d['version'] ?? 'v25.0'), false],
        'meta_dsa'          => [(array) ($d['dsa'] ?? []), false],
        'meta_compte_reseau'=> ['', false],
        'url_publique'      => [(string) ($c['publicUrl'] ?? ''), false],
        'url_bo'            => [(string) ($c['boUrl'] ?? '/consulant_bo/'), false],
        'utilisateur'       => [(string) ($c['userName'] ?? 'Franchiseur'), false],
        'marge'             => [(float) ($d['margin'] ?? 0.62), false],
        'catalogue'         => [json_decode((string) @file_get_contents(__DIR__ . '/data/catalogue.json'), true) ?: [], false],
    ];
    foreach ($defauts as $k => [$v, $secret]) {
        if (socSetting($k) === null) { socSettingPut($k, $v, $secret); }
    }
    socSettingPut('installe_le', date('Y-m-d H:i:s'));
}
