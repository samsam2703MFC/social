<?php
/**
 * Configuration du module Social & Ads — copiez ce fichier vers
 * config/config.php SUR LE SERVEUR (hors Git) si une valeur doit changer.
 *
 * Le fichier ne sert qu'à TROUVER LA BASE : le module lit les identifiants
 * MySQL du BO consultant dans le config/config.php du BO. Tout le reste —
 * données et clés (token Meta, secret de l'app, jeton webhook, URL publique…)
 * — est stocké et lu dans la base, table `social_parametre`, et se modifie
 * dans l'écran Paramètres. Les valeurs ci-dessous ne servent qu'à remplir
 * `social_parametre` au premier lancement.
 */
return [
    // Dossier d'installation du BO consultant (celui qui contient config/config.php).
    'boDir' => getenv('SOCIAL_BO_DIR') ?: '/var/www/consulant_bo',

    // URL du BO, pour les liens (retour au cockpit, page de connexion).
    'boUrl' => getenv('SOCIAL_BO_URL') ?: '/consulant_bo/',

    // URL publique du module, telle que Meta la voit : les visuels envoyés à
    // Meta (POST /{page_id}/photos avec `url`) sont téléchargés par Meta depuis
    // cette adresse. Doit être joignable depuis Internet.
    'publicUrl' => getenv('SOCIAL_PUBLIC_URL') ?: 'https://185.180.206.46/social/',

    // URL de l'espace franchisé (/social_franchisee), pour les liens de l'écran franchiseur.
    'franchiseeUrl' => getenv('SOCIAL_FRANCHISEE_URL') ?: '/social_franchisee/',

    // Surcharges optionnelles — null : la valeur du BO s'applique.
    'db'   => null,        // ['host'=>…, 'port'=>…, 'name'=>…, 'user'=>…, 'password'=>…, 'charset'=>'utf8mb4']
    'auth' => null,        // true / false — null : comme le BO

    // Nom affiché pour les gestes faits depuis l'écran (journal, nav).
    'userName' => getenv('SOCIAL_USER_NAME') ?: 'Franchiseur',
];
