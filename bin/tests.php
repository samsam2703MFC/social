<?php
declare(strict_types=1);

/**
 * Recette du module, sur une VRAIE base MySQL/MariaDB et un Graph Meta SIMULÉ.
 *
 *   SOCIAL_TEST_DSN='mysql:host=127.0.0.1;dbname=social_test' SOCIAL_TEST_USER=… SOCIAL_TEST_PASS=… php bin/tests.php
 *
 * La base de test est VIDÉE puis remplie de tables BO factices (ceo_shop,
 * brand_guard_pages, caisse…). Ne jamais la pointer sur la base de production.
 */

$dsn = getenv('SOCIAL_TEST_DSN') ?: '';
if ($dsn === '' || !str_contains($dsn, 'test')) {
    fwrite(STDERR, "SOCIAL_TEST_DSN requis, et le nom de la base doit contenir « test » (elle est vidée).\n");
    exit(2);
}
$tmp = sys_get_temp_dir() . '/social-tests-' . getmypid();
@mkdir($tmp . '/bo/config', 0777, true);
@mkdir($tmp . '/uploads', 0777, true);
file_put_contents($tmp . '/bo/config/config.php', "<?php return ['auth' => false, 'meta' => ['systemToken' => 'TOK-BO', 'appSecret' => 'SECRET', 'verifyToken' => 'VERIF']];\n");
putenv('SOCIAL_BO_DIR=' . $tmp . '/bo');
putenv('SOCIAL_UPLOADS_DIR=' . $tmp . '/uploads');
putenv('SOCIAL_NO_GEOCODE=1');

require __DIR__ . '/../src/api.php';

$pdo = new PDO($dsn, getenv('SOCIAL_TEST_USER') ?: 'root', getenv('SOCIAL_TEST_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);  // comme en production
Db::use($pdo);

$ok = 0; $ko = 0;
function check(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nom\n"; } else { $ko++; echo "  ÉCHEC $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/* --- base de test : tables BO factices -------------------------------------------------- */
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec("CREATE TABLE ceo_shop (id VARCHAR(8) PRIMARY KEY, code VARCHAR(16), name VARCHAR(120), franchisee VARCHAR(120), zone VARCHAR(60), status VARCHAR(20), pwa_shop_id BIGINT NULL)");
$pdo->exec("INSERT INTO ceo_shop VALUES ('hal','HAL','Halle','Nathan Charlot','Brabant wallon','Ouvert',4),('wav','WAV','Wavre','Julie M.','Brabant wallon','Ouvert',5),('old','OLD','Ancienne','X','Brabant wallon','Fermé',NULL)");
$pdo->exec("CREATE TABLE brand_guard_pages (id INT AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(8) NULL, boutique VARCHAR(80), franchise VARCHAR(120), page_url VARCHAR(300), page_id VARCHAR(40) NULL, page_nom VARCHAR(160) NULL, actif TINYINT DEFAULT 1)");
$pdo->exec("INSERT INTO brand_guard_pages (shop_id, boutique, franchise, page_url, page_id, page_nom) VALUES ('hal','Halle','N','https://facebook.com/h','111','L''Atelier By Halle'),('wav','Wavre','J','https://facebook.com/w','222','L''Atelier By Wavre'),('old','Ancienne','X','u','333','x')");
$pdo->exec("CREATE TABLE ceo_app_setting (`key` VARCHAR(64) PRIMARY KEY, value LONGTEXT)");
$pdo->exec("INSERT INTO ceo_app_setting VALUES ('erpAdmin', '{\"phone\":\"0470\",\"password\":\"pw\"}'), ('panelApi', '{\"phone\":\"0471\",\"password\":\"pw\"}')");
$pdo->exec("CREATE TABLE ceo_journal_entry (id INT AUTO_INCREMENT PRIMARY KEY, happened_at DATETIME, actor VARCHAR(120), kind VARCHAR(60), project VARCHAR(120), message TEXT)");
$pdo->exec("CREATE TABLE shops (id INT PRIMARY KEY, name VARCHAR(80), active TINYINT, address VARCHAR(200), zip VARCHAR(10), city VARCHAR(80), latitude DECIMAL(9,6), longitude DECIMAL(9,6))");
$pdo->exec("INSERT INTO shops VALUES (4,'Halle',1,'Basiliekstraat 12','1500','Halle',50.7339,4.2345),(5,'Wavre',1,'Place Bosch 3','1300','Wavre',50.7173,4.6117)");
$pdo->exec("CREATE TABLE product (id INT PRIMARY KEY, name VARCHAR(120))");
$pdo->exec("INSERT INTO product VALUES (1,'Brioche croustillante'),(2,'Pain au levain')");
$pdo->exec("CREATE TABLE `transaction` (id INT AUTO_INCREMENT PRIMARY KEY, id_shop INT, insert_timestamp DATETIME, total_gross_amount_after_discount DECIMAL(10,2), ticket_key VARCHAR(40))");
$pdo->exec("CREATE TABLE transaction_product (id INT AUTO_INCREMENT PRIMARY KEY, id_transaction INT, id_product INT, quantity DECIMAL(10,2), total_gross_value_after_discount DECIMAL(10,2))");
// Ventes de brioche à Halle : 10 €/jour sur les 6 semaines de référence, 25 €/jour pendant la campagne.
$debutCamp = date('Y-m-d', strtotime('-6 days'));
for ($d = -48; $d <= 0; $d++) {
    $jour = date('Y-m-d', strtotime("$d days"));
    $montant = $jour >= $debutCamp ? 25 : 10;
    $pdo->exec("INSERT INTO `transaction` (id_shop, insert_timestamp, total_gross_amount_after_discount, ticket_key) VALUES (4, '$jour 10:00:00', $montant, 't$d')");
    $tid = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO transaction_product (id_transaction, id_product, quantity, total_gross_value_after_discount) VALUES ($tid, 1, " . ($montant / 5) . ", $montant)");
}

/* --- Graph simulé ------------------------------------------------------------------------- */
$appels = [];
$restreinte = ['222' => true];   // la Page de Wavre refuse (code 368) au premier passage
$n = 100;
$igPanne = 0; $igPub = 0;   // $igPanne = N : la N-ième publication Instagram échoue (frise interrompue)
Meta::$sleep = static function (int $s): void {};
Meta::$transport = static function (string $m, string $url, array $p) use (&$appels, &$restreinte, &$n, &$igPanne, &$igPub): array {
    $path = preg_replace('#^https://graph\.facebook\.com/v[\d.]+/#', '', $url);
    $appels[] = [$m, $path, $p];
    $id = static function () use (&$n) { return (string) (++$n); };
    if (($p['access_token'] ?? '') === '') { return [400, ['error' => ['code' => 190, 'message' => 'no token']]]; }
    // Suppressions (fiche du calendrier) : réussies, refusées (permission), ou objet déjà absent chez Meta.
    if ($m === 'DELETE') {
        $GLOBALS['SUPPRIMES'][] = [$path, (string) ($p['access_token'] ?? '')];
        if (in_array($path, $GLOBALS['DEL_ABSENTS'] ?? [], true)) { return [400, ['error' => ['code' => 100, 'message' => 'Unsupported delete request. Object does not exist']]]; }
        if (in_array($path, $GLOBALS['DEL_REFUS'] ?? [], true)) { return [400, ['error' => ['code' => 200, 'message' => '(#200) Permissions error']]]; }
        return [200, ['success' => true]];
    }
    if ($m === 'GET' && in_array($path, $GLOBALS['DEL_ABSENTS'] ?? [], true)) { return [400, ['error' => ['code' => 100, 'message' => 'Object does not exist']]]; }
    if ($m === 'GET' && $path === 'me/accounts') {
        return [200, ['data' => [['id' => '111', 'name' => 'L\'Atelier By Halle', 'access_token' => 'PT111', 'instagram_business_account' => ['id' => '17841']],
                                 ['id' => '222', 'name' => 'L\'Atelier By Wavre', 'access_token' => 'PT222']]]];
    }
    // Story d'une Page : la photo (non publiée) puis la story.
    if ($m === 'POST' && preg_match('#^(111|222)/photo_stories$#', $path, $mm)) { return [200, ['success' => true, 'post_id' => $mm[1] . '_s' . $id()]]; }
    if ($m === 'POST' && preg_match('#^(111|222)/(photos|feed|videos)$#', $path, $mm)) {
        if (isset($restreinte[$mm[1]])) { return [400, ['error' => ['code' => 368, 'message' => 'Page restreinte']]]; }
        return [200, $mm[2] === 'photos' ? ['id' => $id(), 'post_id' => $mm[1] . '_' . $id()] : ['id' => $mm[1] . '_' . $id()]];
    }
    if ($m === 'GET' && $path === '17841') { return [200, ['id' => '17841', 'username' => 'latelierby.halle', 'name' => 'Atelier Halle', 'profile_picture_url' => 'https://cdn.ig/halle.jpg', 'followers_count' => 2300]]; }
    // Calendriers déjà tenus chez Meta : profil de la Page, publications, posts programmés.
    if ($m === 'GET' && in_array($path, ['111', '222'], true) && str_contains((string) ($p['fields'] ?? ''), 'picture')) {
        return [200, ['id' => $path, 'name' => $path === '111' ? "L'Atelier By Halle" : "L'Atelier By Wavre", 'username' => 'atelier' . $path, 'link' => 'https://facebook.com/' . $path,
            'followers_count' => $path === '111' ? 1500 : 800, 'picture' => ['data' => ['url' => 'https://cdn.fb/p' . $path . '.jpg']]]];
    }
    // Suivi des abonnés : insights de la Page 111 et de l'Instagram 17841 ; la Page 222 refuse (permission).
    if ($m === 'GET' && $path === '111/insights') {
        $e = static fn (int $j) => date('Y-m-d', strtotime('-' . $j . ' days')) . 'T07:00:00+0000';
        // Analyse des vues : les vues de la Page jour par jour (noms 2025) ; retirées, ce sont ses visites.
        if (in_array($p['metric'] ?? '', ['page_media_view', 'page_impressions'], true)) {
            if (!empty($GLOBALS['VUES_RETIREES'])) { return [400, ['error' => ['code' => 100, 'message' => '(#100) The value must be a valid insights metric']]]; }
            return [200, ['data' => [['name' => $p['metric'], 'period' => 'day', 'values' => [['value' => 120, 'end_time' => $e(2)], ['value' => 340, 'end_time' => $e(1)], ['value' => 90, 'end_time' => $e(0)]]]]]];
        }
        if (($p['metric'] ?? '') === 'page_views_total') { return [200, ['data' => [['name' => 'page_views_total', 'period' => 'day', 'values' => [['value' => 9, 'end_time' => $e(1)], ['value' => 4, 'end_time' => $e(0)]]]]]]; }
        return [200, ['data' => [['name' => 'page_daily_follows_unique', 'period' => 'day', 'values' => [['value' => 5, 'end_time' => $e(1)], ['value' => 3, 'end_time' => $e(0)]]],
            ['name' => 'page_daily_unfollows_unique', 'period' => 'day', 'values' => [['value' => 1, 'end_time' => $e(1)], ['value' => 0, 'end_time' => $e(0)]]]]]];
    }
    if ($m === 'GET' && $path === '222/insights') { return [400, ['error' => ['code' => 10, 'message' => 'Requires read_insights permission']]]; }
    // Lot Graph (`batch`) : abonnés et désabonnements d'un jour par requête ; BATCH_REFUS : Meta les refuse.
    if ($m === 'POST' && $path === '' && isset($p['batch'])) {
        return [200, array_map(static function ($q) {
            if (!empty($GLOBALS['BATCH_REFUS'])) { return ['code' => 400, 'body' => json_encode(['error' => ['code' => 100, 'message' => '(#100) metric follows_and_unfollows non disponible']])]; }
            parse_str((string) parse_url((string) $q['relative_url'], PHP_URL_QUERY), $qq);
            $j = (int) gmdate('j', (int) $qq['since']);
            if (gmdate('H', (int) $qq['since']) !== '12') { return ['code' => 200, 'body' => json_encode(['data' => []])]; }  // la veille chez Meta : refusé du test
            return ['code' => 200, 'body' => json_encode(['data' => [['name' => 'follows_and_unfollows', 'period' => 'day', 'total_value' => ['breakdowns' => [['dimension_keys' => ['follow_type'],
                'results' => [['dimension_values' => ['FOLLOWER'], 'value' => 2 + $j % 3], ['dimension_values' => ['NON_FOLLOWER'], 'value' => $j % 2]]]]]]]])];
        }, json_decode((string) $p['batch'], true))];
    }
    if ($m === 'GET' && $path === '17841/insights' && ($p['metric'] ?? '') === 'views') { return [200, ['data' => [['name' => 'views', 'period' => 'day', 'total_value' => ['value' => 777]]]]]; }
    if ($m === 'GET' && $path === '17841/insights' && ($p['metric'] ?? '') === 'reach') {
        return [200, ['data' => [['name' => 'reach', 'period' => 'day', 'values' => [['value' => 60, 'end_time' => date('Y-m-d', strtotime('-1 day')) . 'T07:00:00+0000'], ['value' => 85, 'end_time' => date('Y-m-d') . 'T07:00:00+0000']]]]]];
    }
    if ($m === 'GET' && $path === '17841/insights') { return [200, ['data' => [['name' => 'follower_count', 'period' => 'day', 'values' => [['value' => 4, 'end_time' => date('Y-m-d') . 'T07:00:00+0000']]]]]]; }
    // Chiffres d'une publication (fiche du calendrier) : la Page 111 a retiré post_clicks (refus du lot), la 222 refuse les insights.
    if ($m === 'GET' && preg_match('#^111_\d+/insights$#', $path)) {
        $noms = explode(',', (string) ($p['metric'] ?? ''));
        if (array_intersect($noms, ['post_clicks', 'post_impressions', 'post_impressions_unique'])) { return [400, ['error' => ['code' => 100, 'message' => '(#100) The value must be a valid insights metric']]]; }
        if (!empty($GLOBALS['INSIGHTS_VIDES'])) { return [200, ['data' => []]]; }  // token sans read_insights (prod) : métriques acceptées, aucune valeur
        $v = ['post_media_view' => 1234, 'post_total_media_view_unique' => 980, 'post_video_views' => 0, 'post_reactions_by_type_total' => ['like' => 30, 'love' => 5]];
        return [200, ['data' => array_values(array_map(static fn ($n) => ['name' => $n, 'period' => 'lifetime', 'values' => [['value' => $v[$n]]]], array_intersect($noms, array_keys($v))))]];
    }
    if ($m === 'GET' && preg_match('#^222_\d+/insights$#', $path)) { return [400, ['error' => ['code' => 10, 'message' => 'Requires read_insights permission']]]; }
    if ($m === 'GET' && preg_match('#^(111|222)_\d+$#', $path)) { return [400, ['error' => ['code' => 10, 'message' => 'Requires pages_read_user_content permission']]]; }
    if ($m === 'GET' && $path === 'm2/insights') {
        return [200, ['data' => [['name' => 'views', 'values' => [['value' => 500]]], ['name' => 'reach', 'values' => [['value' => 400]]], ['name' => 'saved', 'values' => [['value' => 7]]],
            ['name' => 'shares', 'values' => [['value' => 3]]], ['name' => 'total_interactions', 'values' => [['value' => 60]]]]]];
    }
    if ($m === 'GET' && $path === 'm2') { return [200, ['like_count' => 41, 'comments_count' => 6]]; }
    // Les vues lues avec la liste (`insights.metric(…)`) ; refusées (GROUPE_REFUS) : relues une par une.
    if ($m === 'GET' && $path === '111/published_posts' && str_contains((string) ($p['fields'] ?? ''), 'insights.metric(')) {
        if (!empty($GLOBALS['GROUPE_REFUS']) || !str_contains((string) $p['fields'], 'post_media_view')) { return [400, ['error' => ['code' => 100, 'message' => '(#100) The value must be a valid insights metric']]]; }
        return [200, ['data' => array_map(static fn ($x) => $x + ['insights' => ['data' => [['name' => 'post_media_view', 'values' => [['value' => $x['id'] === '111_9001' ? 1234 : 50]]],
            ['name' => 'post_total_media_view_unique', 'values' => [['value' => $x['id'] === '111_9001' ? 980 : 40]]]]]], $GLOBALS['META_PUBLIES'] ?? [])]];
    }
    if ($m === 'GET' && $path === '111/published_posts') { return [200, ['data' => $GLOBALS['META_PUBLIES'] ?? []]]; }
    if ($m === 'GET' && $path === '111/scheduled_posts') { return [200, ['data' => $GLOBALS['META_PROGRAMMES'] ?? []]]; }
    if ($m === 'GET' && preg_match('#^222/(published|scheduled)_posts$#', $path)) { return [400, ['error' => ['code' => 10, 'message' => 'Permission manquante : pages_read_engagement']]]; }
    if ($m === 'GET' && $path === '222/posts') {
        if (!empty($GLOBALS['POSTS_222_REFUS'])) { return [400, ['error' => ['code' => 10, 'message' => 'Permission manquante : pages_read_engagement']]]; }
        return [200, ['data' => [['id' => '222_7001', 'message' => 'Publié sur la Page de Wavre', 'created_time' => date('c', strtotime('-1 day 10:00'))]]]];
    }
    if ($m === 'GET' && $path === '222/feed') { return [400, ['error' => ['code' => 10, 'message' => 'Permission manquante : pages_read_engagement']]]; }
    if ($m === 'POST' && $path === '17841/media') { return [200, ['id' => 'c' . $id()]]; }
    if ($path === '17841/media_publish') {
        if ($igPanne > 0 && ++$igPub === $igPanne) { return [400, ['error' => ['code' => 9007, 'message' => 'Media ID is not available']]]; }
        return [200, ['id' => 'ig' . $id()]];
    }
    if ($m === 'GET' && $path === '17841/media' && str_contains((string) ($p['fields'] ?? ''), 'insights.metric(')) {
        if (!str_contains((string) $p['fields'], '(views,')) { return [400, ['error' => ['code' => 100, 'message' => '(#100) Invalid metric']]]; }
        $i = static fn (int $v, int $r) => ['insights' => ['data' => [['name' => 'views', 'values' => [['value' => $v]]], ['name' => 'reach', 'values' => [['value' => $r]]]]]];
        return [200, ['data' => [['id' => 'm2', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.ig/m2.jpg', 'permalink' => 'https://instagram.com/p/m2', 'timestamp' => date('c', strtotime('-1 day'))] + $i(500, 400),
            ['id' => 'm1', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.ig/m1.mp4', 'thumbnail_url' => 'https://cdn.ig/m1.jpg', 'timestamp' => date('c', strtotime('-3 days'))] + $i(200, 150)]]];
    }
    if ($m === 'GET' && $path === '17841/media') {
        return [200, ['data' => [['id' => 'm2', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.ig/m2.jpg', 'permalink' => 'https://instagram.com/p/m2', 'timestamp' => date('c', strtotime('-1 day'))],
            ['id' => 'm1', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.ig/m1.mp4', 'thumbnail_url' => 'https://cdn.ig/m1.jpg', 'timestamp' => date('c', strtotime('-3 days'))]]]];
    }
    if ($m === 'GET' && preg_match('#^c\d+$#', $path)) { return [200, ['status_code' => 'FINISHED']]; }
    // Dark posts : vidéo de pub, relecture de la créa, commentaires.
    if ($m === 'POST' && preg_match('#^act_\d+/advideos$#', $path)) { return [200, ['id' => 'v' . $id()]]; }
    if ($m === 'GET' && preg_match('#^v\d+$#', $path)) { return [200, ['status' => ['video_status' => 'ready']]]; }
    if ($m === 'GET' && preg_match('#^v\d+/thumbnails$#', $path)) { return [200, ['data' => [['uri' => 'https://cdn.fb/vignette.jpg', 'is_preferred' => true]]]]; }
    if ($m === 'GET' && preg_match('#^\d+$#', $path) && str_contains((string) ($p['fields'] ?? ''), 'effective_object_story_id')) {
        return [200, ['effective_object_story_id' => '111_8' . $path, 'effective_instagram_media_id' => '1790' . $path]];
    }
    if ($m === 'GET' && preg_match('#^1790\d+$#', $path)) { return [200, ['permalink' => 'https://www.instagram.com/p/AD' . $path]]; }
    if ($m === 'GET' && preg_match('#^(\d+_\d+)/comments$#', $path, $mm)) {
        return [200, ['data' => [['id' => $mm[1] . '_c1', 'from' => ['name' => 'Julie D.'], 'message' => 'Ça a l’air délicieux', 'created_time' => date('c')]]]];
    }
    if ($m === 'GET' && preg_match('#^(1790\d+)/comments$#', $path, $mm)) {
        return [200, ['data' => [['id' => 'igc' . $mm[1], 'username' => 'marc.b', 'text' => 'On passe samedi', 'timestamp' => date('c')]]]];
    }
    // Devise et minimums d'un compte publicitaire : le compte réseau act_2000 est en złotys (500 PLN de plafond minimum).
    if ($m === 'GET' && preg_match('#^act_\d+$#', $path) && str_contains((string) ($p['fields'] ?? ''), 'min_campaign_group_spend_cap')) {
        return [200, $path === 'act_2000' ? ['currency' => 'PLN', 'min_campaign_group_spend_cap' => '50000', 'min_daily_budget' => 500] : ['currency' => 'EUR', 'min_campaign_group_spend_cap' => '10000', 'min_daily_budget' => 100]];
    }
    if ($m === 'GET' && preg_match('#^act_\d+$#', $path)) { return [200, ['name' => 'Compte ' . $path, 'account_status' => 1]]; }
    // Centres d'intérêt : « Pâtisserie » d'abord rendu sous un id abandonné par Meta ; « Refus » fait refuser l'ad set.
    if ($m === 'GET' && $path === 'search' && ($p['q'] ?? '') === 'Pâtisserie') { return [200, ['data' => [['id' => '6003999001', 'name' => 'Pâtisserie'], ['id' => '6003999002', 'name' => 'Pâtisserie fine']]]]; }
    if ($m === 'GET' && $path === 'search' && ($p['q'] ?? '') === 'Refus') { return [200, ['data' => [['id' => '6003000666', 'name' => 'Refus']]]]; }
    if ($m === 'POST' && preg_match('#^act_\d+/adsets$#', $path) && str_contains((string) ($p['targeting'] ?? ''), '6003999001')) {
        return [400, ['error' => ['code' => 100, 'message' => 'Invalid parameter', 'error_user_msg' => 'Veuillez mettre à jour la spécification cible pour les supprimer ou les remplacer par d’autres options. Autres options pertinentes : '
            . '[{"deprecated_interest_id":"6003999001","deprecated_interest_name":"P\\u00e2tisserie","alternative_interest_id":"6003436950375","alternative_interest_name":"Restaurants"}]']]];
    }
    if ($m === 'POST' && preg_match('#^act_\d+/adsets$#', $path) && str_contains((string) ($p['targeting'] ?? ''), '6003000666')) { return [400, ['error' => ['code' => 100, 'message' => 'Invalid parameter']]]; }
    if ($m === 'POST' && preg_match('#^act_\d+/adcreatives$#', $path) && ($p['name'] ?? '') === 'Créa · App en dev') {
        return [400, ['error' => ['code' => 100, 'error_subcode' => 1885183, 'message' => 'Invalid parameter', 'error_user_msg' => 'La publication du contenu publicitaire de la publicité a été créé par une application en cours de développement. Elle doit être publique pour créer cette publicité.']]];
    }
    if ($m === 'GET' && preg_match('#^7\d{3}$#', $path) && str_contains((string) ($p['fields'] ?? ''), 'daily_budget')) {
        foreach ($GLOBALS['META_EXISTANT']['campaigns'] ?? [] as $c) { if ($c['id'] === $path) { return [200, $c]; } }
    }
    if ($m === 'GET' && preg_match('#^(7\d{3})/adsets$#', $path, $mm)) {
        return [200, ['data' => array_values(array_filter($GLOBALS['META_EXISTANT']['adsets'] ?? [], static fn ($x) => $x['campaign_id'] === $mm[1]))]];
    }
    if ($m === 'GET' && $path === 'search') { return [200, ['data' => ($p['type'] ?? '') === 'adlocale' ? [['key' => 9, 'name' => 'French (All)'], ['key' => 14, 'name' => 'Dutch']] : [['id' => '6003107902', 'name' => $p['q']]]]]; }
    // Campagnes déjà présentes chez Meta (récupération) : seul act_2001 en a.
    if ($m === 'GET' && $path === 'act_2001/insights' && ($p['level'] ?? '') === 'ad') { return [200, ['data' => $GLOBALS['META_DEPENSES'] ?? []]]; }
    if ($m === 'GET' && preg_match('#^act_\d+/(campaigns|adsets|ads|insights)$#', $path, $mm)) {
        return [200, ['data' => $path === 'act_2001/' . $mm[1] ? ($GLOBALS['META_EXISTANT'][$mm[1]] ?? []) : []]];
    }
    if (preg_match('#^act_\d+/(campaigns|adsets|adcreatives|ads)$#', $path)) { return [200, ['id' => $id()]]; }
    if (preg_match('#^act_\d+/adimages$#', $path)) { return [200, ['images' => ['x' => ['hash' => 'HASH1']]]]; }
    if (preg_match('#^(111|222)/leadgen_forms$#', $path)) { return [200, ['id' => 'F' . $id()]]; }
    if ($m === 'GET' && preg_match('#^F\d+/leads$#', $path)) {
        return [200, ['data' => [['id' => 'L1', 'created_time' => date('c'), 'form_id' => 'F', 'ad_name' => 'Pub B2B',
            'field_data' => [['name' => 'full_name', 'values' => ['Marie Claes']], ['name' => 'email', 'values' => ['m@ex.be']], ['name' => 'nb_personnes', 'values' => ['16 à 30']]]]]]];
    }
    if ($m === 'GET' && preg_match('#^\d+/insights$#', $path)) {
        return [200, ['data' => [['spend' => '40.50', 'reach' => '1200', 'impressions' => '3000', 'clicks' => '60',
            'actions' => [['action_type' => 'post_engagement', 'value' => '90'], ['action_type' => 'video_view', 'value' => '15']]]]]];
    }
    if ($m === 'GET' && $path === 'me' && str_contains((string) ($p['fields'] ?? ''), 'business')) { return [200, ['id' => '55', 'name' => 'SU', 'business' => ['id' => '900', 'name' => 'Atelier By BM']]]; }
    if ($m === 'GET' && $path === '900/owned_pages') {
        return [200, ['data' => [['id' => '111', 'name' => "L'Atelier By Halle", 'instagram_business_account' => ['id' => '17841', 'username' => 'latelierby.halle']],
            ['id' => '222', 'name' => "L'Atelier By Wavre"], ['id' => '999', 'name' => "L'Atelier By"]]]];
    }
    if ($m === 'GET' && $path === '900/client_pages') { return [400, ['error' => ['code' => 200, 'message' => 'Permissions error']]]; }
    if ($m === 'GET' && $path === '900/owned_ad_accounts') {
        return [200, ['data' => [['id' => 'act_2001', 'account_id' => '2001', 'name' => 'Atelier By - Halle', 'account_status' => 1, 'currency' => 'EUR'],
            ['id' => 'act_2002', 'account_id' => '2002', 'name' => 'Atelier Wavre', 'account_status' => 2, 'currency' => 'EUR'], ['id' => 'act_2000', 'name' => "L'Atelier By réseau", 'account_status' => 1]]]];
    }
    if ($m === 'GET' && in_array($path, ['900/client_ad_accounts', 'me/adaccounts', '900/instagram_accounts'], true)) { return [200, ['data' => []]]; }
    if ($m === 'GET' && $path === '900/adspixels') { return [200, ['data' => [['id' => '880112', 'name' => 'Pixel webshop']]]]; }
    if ($m === 'GET' && $path === 'debug_token') {
        return [200, ['data' => ['is_valid' => true, 'expires_at' => strtotime('2027-01-01 12:00'), 'app_id' => '424242', 'application' => 'Atelier Social',
            'type' => 'SYSTEM_USER', 'scopes' => ['pages_manage_posts', 'ads_management']]]];
    }
    if ($m === 'GET' && preg_match('#^\d+$#', $path)) { return [200, ['effective_status' => 'ACTIVE']]; }
    if ($m === 'POST' && preg_match('#^\w+$#', $path)) { return [200, ['success' => true]]; }
    return [404, ['error' => ['code' => 803, 'message' => 'route simulée inconnue : ' . $m . ' ' . $path]]];
};
// L'API TFBuddy simulée : boutiques publiques, connexion, swagger réduit pour l'audit.
putenv('SOCIAL_TFB_TEST=1');
$tfbAppels = [];
Tfb::$transport = static function (string $m, string $url, array $h, ?string $body) use (&$tfbAppels): array {
    $tfbAppels[] = [$m, $url];
    $p = (string) parse_url($url, PHP_URL_PATH);
    $auth = (bool) array_filter($h, static fn ($x) => str_starts_with($x, 'Authorization: Bearer JWT'));
    if ($p === '/api/v1/public/shops') {
        return [200, json_encode([
            ['id' => 4, 'representative_name' => 'Atelier by - Halle', 'email' => 'halle@atelierby.be', 'street' => 'Basiliekstraat', 'street_num' => '12', 'city' => 'Halle', 'zip' => '1500', 'phone' => '02', 'vat_number' => 'BE1', 'opening_hours' => '07:00', 'closing_hours' => '18:30'],
            ['id' => 5, 'representative_name' => 'Atelier by - Wavre', 'email' => 'wavre@atelierby.be', 'street' => 'Place Bosch', 'street_num' => '3', 'city' => 'Wavre', 'zip' => '1300', 'phone' => '010', 'vat_number' => 'BE2', 'opening_hours' => '07:00', 'closing_hours' => '19:00']])];
    }
    if (preg_match('#/(admin|consultant)/auth/login$#', $p, $mm)) {
        $b = json_decode((string) $body, true);
        return ($b['password'] ?? '') === 'pw' ? [200, json_encode(['access_token' => 'JWT-' . $mm[1]])] : [401, '{"message":"bad"}'];
    }
    if ($p === '/api/swagger/openapi.json') {
        return [200, json_encode(['openapi' => '3.0.0', 'paths' => [
            '/api/v1/public/shops' => ['get' => []], '/api/v1/shops' => ['get' => ['tags' => ['Franchise shop']]],
            '/api/v1/shops/{id}' => ['get' => []], '/api/v1/products' => ['get' => []], '/api/v1/consultant/shops' => ['get' => []],
            '/api/v1/recipes/{param1}' => ['get' => []], '/api/v1/franchisee-shop/{id}/transactions/{date}' => ['get' => []],
            '/api/v1/broken' => ['get' => []], '/api/v1/consultant/shops/sales-kpis' => ['get' => []], '/api/v1/shops/{id}/x' => ['post' => []]]])];
    }
    // Les formules d'un produit (route publique du webshop).
    if (preg_match('#^/api/v1/webshop/shops/(4|5)/products/12/menus$#', $p)) {
        return [200, json_encode([['id' => 4, 'menu_name' => 'Sandwich + boisson', 'name' => 'Avec boisson', 'price_mode' => 'BASE_PLUS', 'price_modifier' => 1.5,
            'slots' => [['label' => 'Boisson'], ['label' => 'Dessert']]]])];
    }
    // Les images du webshop (Cloudflare R2, publiques).
    if (str_contains($url, '.r2.dev/')) {
        return str_contains($url, 'absente') ? [404, ''] : [200, str_contains($url, 'faux') ? '<html>pas une image</html>' : "RIFF\x24\x00\x00\x00WEBPVP8 image"];
    }
    if (!$auth) { return [401, '{"message":"Unauthenticated"}']; }
    // Créer un post : le catalogue du webshop (catégorie → sous-catégorie → produit) et les promotions.
    if (preg_match('#^/api/v1/shops/(4|5)/products/available$#', $p, $mm) && str_contains($url, 'alias_type=webshop')) {
        $img = static fn (string $q) => ['attachment_id' => 1, 'url' => 'https://cdn.r2.dev/webshop/' . $q . '.webp'];
        $biscuit = ['id' => 31, 'name' => 'Biscuiterie', 'webshop_image' => $img('g31')]; $traiteur = ['id' => 26, 'name' => 'Traiteur', 'webshop_image' => $img('g26')];
        $nl = str_contains($url, 'lang_code=nl');
        return [200, json_encode([
            ['id' => 11, 'name' => $nl ? 'Chocoladekoek' : 'Cookie chocolat', 'is_active' => 1, 'id_category' => 32100, 'category_name' => 'Cookies', 'portion_price' => $mm[1] === '4' ? '2.80' : '3.00',
                'category' => ['id' => 32100, 'name' => 'Cookies', 'webshop_image' => $img('c32100'), 'groups' => [$biscuit]], 'webshop_image' => $img('p11'), 'webshop' => ['price_gross' => 2.8],
                'availability_periods' => [['id' => 14, 'name' => 'Gamme Automne']], 'has_menu_options' => false],
            ['id' => 12, 'name' => 'Sandwich jambon', 'is_active' => 1, 'id_category' => 23000, 'category_name' => 'Sandwiches garnis', 'portion_price' => '4.90',
                'category' => ['id' => 23000, 'name' => 'Sandwiches garnis', 'webshop_image' => null, 'groups' => [$traiteur]], 'webshop_image' => null, 'webshop' => ['price_gross' => 4.9],
                'availability_periods' => [], 'has_menu_options' => true],
            ['id' => 13, 'name' => 'Ancien', 'is_active' => 0, 'id_category' => 23000, 'category' => ['id' => 23000, 'name' => 'Sandwiches garnis', 'groups' => [$traiteur]]],
        ])];
    }
    if (preg_match('#^/api/v1/admin/promotions/(bundles|buy-x-get-y|quantity|scheduled-product-discount)(?:/(\d+))?$#', $p, $mm)) {
        $det = [
            15 => ['id' => 15, 'name' => 'Lot sandwich', 'promotion_type' => 'BUNDLE_PROMOTION', 'status' => 'ACTIVE', 'shop_scope_type' => 'ALL_SHOPS', 'shop_ids' => [],
                'bundle_promotion' => ['bundle_price' => '5.90'], 'items' => [['id_product' => 12, 'product_name' => 'Sandwich jambon', 'quantity' => '1.0000']]],
            17 => ['id' => 17, 'name' => '3 Cookies !', 'promotion_type' => 'BUY_X_GET_Y', 'status' => 'ACTIVE', 'shop_scope_type' => 'SELECTED_SHOPS', 'shop_ids' => [5],
                'trigger' => ['quantity' => 2, 'category_ids' => [32100], 'categories' => [['name' => 'Cookies']], 'product_ids' => [], 'products' => []],
                'reward' => ['quantity' => 1, 'category_ids' => [32100], 'categories' => [['name' => 'Cookies']], 'product_ids' => [], 'products' => [], 'type' => 'FIXED_AMOUNT_DISCOUNT', 'value' => '1.40']],
            18 => ['id' => 18, 'name' => 'Inactive', 'promotion_type' => 'BUY_X_GET_Y', 'status' => 'INACTIVE', 'shop_scope_type' => 'ALL_SHOPS', 'trigger' => ['product_ids' => [11]], 'reward' => ['product_ids' => [11]]],
            19 => ['id' => 19, 'name' => 'Terminée', 'promotion_type' => 'BUY_X_GET_Y', 'status' => 'ACTIVE', 'valid_to' => '2020-01-01 00:00:00', 'shop_scope_type' => 'ALL_SHOPS',
                'trigger' => ['quantity' => 1, 'product_ids' => [11]], 'reward' => ['quantity' => 1, 'product_ids' => [11], 'type' => 'FREE']],
        ];
        if (!empty($mm[2])) { return isset($det[(int) $mm[2]]) ? [200, json_encode(['data' => $det[(int) $mm[2]]])] : [404, '{}']; }
        $liste = match ($mm[1]) { 'bundles' => [15], 'buy-x-get-y' => [17, 18, 19, 15], default => [] };
        return [200, json_encode(['items' => array_map(static fn ($i) => array_intersect_key($det[$i], array_flip(['id', 'name', 'promotion_type', 'status'])), $liste), 'pagination' => ['page' => 1]])];
    }
    if ($p === '/api/v1/shops') { return [200, json_encode(['data' => [['id' => 4, 'name' => 'Halle', 'active' => true], ['id' => 5, 'name' => 'Wavre', 'active' => true]]])]; }
    if ($p === '/api/v1/shops/4' || $p === '/api/v1/consultant/shops') { return [200, '{"id":4}']; }
    if ($p === '/api/v1/products') { return [403, '{"message":"Forbidden"}']; }
    if ($p === '/api/v1/shops/4/products/available') {
        $nl = str_contains($url, 'lang_code=nl');
        return [200, json_encode([['id' => 1, 'name' => $nl ? 'Krokante brioche' : 'Brioche croustillante', 'is_active' => 1, 'category_name' => 'Viennoiseries', 'portion_price' => '4.20'],
            ['id' => 2, 'name' => 'Carton pro', 'is_active' => 1, 'category_name' => 'B.2.B - Cartons', 'portion_price' => '39.60'],
            ['id' => 3, 'name' => 'Ancien', 'is_active' => 0, 'category_name' => 'Pains', 'portion_price' => '2']])];
    }
    if ($p === '/api/v1/product-availability-periods') { return [200, json_encode([['id' => 14, 'name' => '🍂 Gamme Automne', 'is_active' => 1], ['id' => 15, 'name' => 'Vieille', 'is_active' => 0]])]; }
    if (preg_match('#^/api/v1/shops/(4|5)/statistics/sales/(product-category-groups|kpis)$#', $p, $mm) && getenv('SOCIAL_TFB_VENTES')) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $camp = ($q['date_from'] ?? '') >= date('Y-m-d', strtotime('-6 days'));
        if ($mm[2] === 'kpis') { return [200, json_encode(['tickets' => $camp ? 300 : 200, 'ca' => 1])]; }
        return [200, json_encode([['product_name' => 'Brioche croustillante', 'category_name' => 'Viennoiseries', 'sold_qty' => $camp ? '50' : '30', 'sales_value' => $camp ? '210.00' : '126.00'],
            ['product_name' => 'Pain', 'category_name' => 'Pains', 'sold_qty' => '9', 'sales_value' => '99']])];
    }
    if (str_starts_with($p, '/api/v1/franchisee-shop/4/transactions/')) { return [200, '[]']; }
    if ($p === '/api/v1/broken') { return [500, '{"message":"Server Error"}']; }
    if ($p === '/api/v1/consultant/shops/sales-kpis') { return str_contains($url, 'date_from=') ? [200, '{"kpis":{}}'] : [500, '{"status":"error","description":"Validation errors in request"}']; }
    return [404, 'not found'];
};

$appelsVers = static function (string $re) use (&$appels): array { return array_values(array_filter($appels, static fn ($a) => preg_match($re, $a[1]))); };

echo "Installation\n";
socInstall();
$tables = $pdo->query("SHOW TABLES LIKE 'social\\_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['social_parametre', 'social_content', 'social_campagne', 'social_publicite', 'social_formulaire', 'social_lead', 'social_groupe',
          'social_modele', 'social_boutique', 'social_publication', 'social_meta_objet', 'social_resultat', 'social_activite'] as $t) {
    check("table $t", in_array($t, $tables, true));
}
check('aucune table hors préfixe social_ créée par le module', !array_filter($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), static fn ($t) => str_starts_with($t, 'soc_')));
check('token Meta repris du BO, stocké en base', socSetting('meta_system_token') === 'TOK-BO');
check('token marqué secret', (int) Db::row("SELECT secret FROM social_parametre WHERE cle = 'meta_system_token'")['secret'] === 1);
check('Meta lit le token dans social_parametre', Meta::config()['systemToken'] === 'TOK-BO');
socSettingPut('meta_system_token', 'TOK-DB', true);
check('changer la clé en base change la clé utilisée', Meta::config()['systemToken'] === 'TOK-DB');
check('les clés secrètes ne partent pas au navigateur', !str_contains(json_encode(socParams()), 'TOK-DB'));

echo "Boutiques (API TFBuddy + BO)\n";
$shops = socShops();
check('boutiques = celles de l’API, identifiant de caisse', array_column($shops, 'id') === ['4', '5'], json_encode(array_column($shops, 'id')));
check('comptes API repris du BO, mot de passe secret', socSetting('tfb_admin_phone') === '0470' && (int) Db::row("SELECT secret FROM social_parametre WHERE cle = 'tfb_admin_password'")['secret'] === 1);
check('franchisé et zone du BO rattachés par pwa_shop_id', socShop('4')['franchisee'] === 'Nathan Charlot');
check('horaires lus dans l’API', socShop('4')['hours'] === '7h00–18h30' && socShop('4')['hoursNl'] === '7u00–18u30');
check('sources tracées', in_array('API public/shops + shops', socShop('4')['sources'], true), json_encode(socShop('4')['sources']));
$hal = socShop('4');
check('page_id repris de Brand Guard', ($hal['ids']['page_id'] ?? '') === '111');
check('adresse lue dans l’API', ($hal['address'] ?? '') === 'Basiliekstraat 12, 1500 Halle', (string) ($hal['address'] ?? ''));
check('position lue dans la caisse', ($hal['geo'][0] ?? 0) == 50.7339);
check('connexion non vérifiée = non connectée', $hal['fb'] === 'none');
socTestConnexions();
$hal = socShop('4'); $wav = socShop('5');
check('test : Page Halle connectée', $hal['fb'] === 'connected');
check('test : Instagram Halle trouvé par la Page', $hal['igs'] === 'connected' && $hal['ids']['instagram_user_id'] === '17841');
check('test : Wavre sans Instagram', $wav['igs'] === 'none');
check('connexions écrites en colonnes', Db::row("SELECT conn_facebook FROM social_boutique WHERE shop_id = '4'")['conn_facebook'] === 'connected');

echo "Paramètres de l'écran\n";
socStateSave(['shops' => [['id' => '4', 'langs' => ['nl', 'fr'], 'hours' => '7h00–18h30', 'hoursNl' => '7u00–18u30']],
    'meta' => ['ids' => ['4' => ['ad_account_id' => 'act_1001'], '5' => ['ad_account_id' => '1002']], 'networkAccount' => 'act_9000', 'version' => 'v26.0']]);
$hal = socShop('4');
check('langues NL + FR enregistrées', $hal['langs'] === ['nl', 'fr']);
check('ad_account_id enregistré', $hal['ids']['ad_account_id'] === 'act_1001');
check('version API en base', socSetting('meta_version') === 'v26.0' && Meta::config()['version'] === 'v26.0');
$st = socStateGet();
check('état : un groupe par zone de plusieurs boutiques', count($st['groups']) === 1 && count($st['groups'][0]['shops']) === 2);
check('état : modèles de posts livrés', count($st['templates']) >= 4);

echo "Posts : programmation, file, publication\n";
$img = 'data:image/png;base64,' . base64_encode(str_repeat("\x89PNG", 80));
$post = ['id' => 'p1', 'date' => date('Y-m-d', strtotime('+2 days')), 'time' => '08:00', 'type' => 'Produit', 'title' => 'Brioche',
    'img' => $img, 'media' => [['src' => $img, 'kind' => 'img']], 'shops' => ['4', '5'],
    'ch' => ['4' => ['fb' => true, 'ig' => true], '5' => ['fb' => true, 'ig' => false]], 'fb' => true, 'ig' => true,
    'status' => 'Programmé', 'text' => 'Brioche à {ville}, {prix} €. {horaires}', 'textNl' => 'Brioche in {ville}, {prix} €. {horaires}',
    'product' => 'p1', 'spread' => 10];
$r = socStateSave(['changes' => ['posts' => ['upsert' => [$post]]]]);
$p1 = socItem('posts', 'p1');
check('post enregistré dans social_content', Db::row("SELECT titre, statut, boutiques FROM social_content WHERE id = 'p1'") === ['titre' => 'Brioche', 'statut' => 'Programmé', 'boutiques' => '4,5']);
check('image data: écrite en fichier', str_starts_with((string) $p1['img'], 'uploads/'), (string) $p1['img']);
$rows = Db::rows("SELECT * FROM social_publication WHERE content_id = 'p1' ORDER BY prevue_le, canal");
check('3 publications en file (Halle fb+ig, Wavre fb)', count($rows) === 3);
check('étalement de 10 min sur la 2e Page', $rows[2]['prevue_le'] === date('Y-m-d', strtotime('+2 days')) . ' 08:10:00', $rows[2]['prevue_le'] ?? '');
check('rien ne part avant l’heure', socRunQueue() === []);
Db::exec("UPDATE social_publication SET prevue_le = NOW() - INTERVAL 1 MINUTE WHERE content_id = 'p1'");
$appels = [];
socRunQueue();
$p1 = socItem('posts', 'p1');
check('Wavre refusé (368) → post partiel', $p1['status'] === 'Publié' && count($p1['partial']['ko'] ?? []) === 1, json_encode($p1['partial'] ?? null));
check('motif 368 lisible', str_contains($p1['partial']['ko'][0]['reason'] ?? '', 'code 368'));
$cap = $appelsVers('#^111/photos$#')[0][2] ?? [];
check('légende bilingue NL puis FR, variables résolues', str_starts_with((string) ($cap['caption'] ?? ''), "Brioche in Halle, 4,20 €. 7u00–18u30\n\nBrioche à Halle, 4,20 €. 7h00–18h30"), (string) ($cap['caption'] ?? ''));
check('photo envoyée par URL publique', str_contains((string) ($cap['url'] ?? ''), '/social/uploads/'));
check('token de Page utilisé (pas celui du System User)', ($cap['access_token'] ?? '') === 'PT111');
check('Instagram : conteneur puis publication', count($appelsVers('#^17841/media_publish$#')) === 1);
unset($restreinte['222']);
$p1 = socRetry('p1');
check('relance : Wavre publié, post entièrement publié', $p1['status'] === 'Publié' && empty($p1['partial']));
$p1['status'] = 'Échec';
socStateSave(['changes' => ['posts' => ['upsert' => [$p1]]]]);
check('l’écran ne peut pas réécrire le statut d’un post publié', socItem('posts', 'p1')['status'] === 'Publié');
$p2 = ['id' => 'p2', 'date' => date('Y-m-d'), 'time' => '09:00', 'title' => 'Texte seul', 'shops' => ['5'], 'fb' => true, 'ig' => false, 'status' => 'Publié', 'text' => 'Bonjour {ville}'];
socStateSave(['changes' => ['posts' => ['upsert' => [$p2]]]]);
check('« Publier maintenant » publie dans la requête', socItem('posts', 'p2')['status'] === 'Publié');
check('texte seul → /feed', ($appelsVers('#^222/feed$#')[0][2]['message'] ?? '') === 'Bonjour Wavre');
$p3 = ['id' => 'p3', 'date' => date('Y-m-d', strtotime('+3 days')), 'time' => '09:00', 'title' => 'Brouillon', 'shops' => ['5'], 'fb' => true, 'status' => 'Programmé', 'text' => 'x'];
socStateSave(['changes' => ['posts' => ['upsert' => [$p3]]]]);
$p3['status'] = 'Brouillon';
socStateSave(['changes' => ['posts' => ['upsert' => [$p3]]]]);
check('repassé en brouillon → retiré de la file', (int) Db::row("SELECT COUNT(*) n FROM social_publication WHERE content_id = 'p3'")['n'] === 0);
socStateSave(['changes' => ['posts' => ['delete' => ['p3']]]]);
check('suppression', socItem('posts', 'p3') === null);

echo "Montages Instagram : carrousel panoramique\n";
$tranche = static fn (int $i) => 'data:image/jpeg;base64,' . base64_encode(str_repeat("\xFF\xD8tranche$i", 40));
$p4 = ['id' => 'p4', 'date' => date('Y-m-d'), 'time' => '10:00', 'type' => 'Gamme saisonnière', 'title' => 'Gamme automne', 'shops' => ['4'],
    'ch' => ['4' => ['fb' => true, 'ig' => true]], 'fb' => true, 'ig' => true, 'status' => 'Publié', 'text' => 'La gamme automne à {ville}',
    'img' => $tranche(1), 'media' => [['src' => $tranche(1), 'kind' => 'img'], ['src' => $tranche(2), 'kind' => 'img'], ['src' => $tranche(3), 'kind' => 'img']],
    'montage' => ['kind' => 'pano', 'm' => ['n' => 3, 'title' => 'Gamme automne', 'band' => 'Brioche · Tarte · Tartelette', 'price' => 'dès 3,60 €', 'sign' => "L'Atelier By"],
        'photos' => [['src' => $img, 'cut' => true, 'name' => 'brioche.png']]]];
$appels = [];
socStateSave(['changes' => ['posts' => ['upsert' => [$p4]]]]);
$p4 = socItem('posts', 'p4');
check('montage : les 3 images et la photo source écrites en fichiers', count(array_filter($p4['media'], static fn ($m) => str_starts_with($m['src'], 'uploads/'))) === 3
    && str_starts_with((string) ($p4['montage']['photos'][0]['src'] ?? ''), 'uploads/'));
check('montage : réglages gardés pour la réouverture', ($p4['montage']['m']['n'] ?? 0) === 3 && ($p4['montage']['kind'] ?? '') === 'pano');
$albums = $appelsVers('#^111/photos$#');
check('Facebook : 3 photos non publiées puis un post album', count($albums) === 3 && in_array($albums[0][2]['published'] ?? null, [false, 'false'], true)
    && count(json_decode((string) ($appelsVers('#^111/feed$#')[0][2]['attached_media'] ?? '[]'), true) ?: []) === 3);
$ig = $appelsVers('#^17841/media$#');
check('Instagram : 3 éléments de carrousel, dans l’ordre', count(array_filter($ig, static fn ($a) => !empty($a[2]['is_carousel_item']))) === 3
    && $ig[0][2]['image_url'] === socPublicUrl($p4['media'][0]['src']));
check('Instagram : un conteneur CAROUSEL publié', count(array_filter($ig, static fn ($a) => ($a[2]['media_type'] ?? '') === 'CAROUSEL' && count(explode(',', $a[2]['children'])) === 3)) === 1
    && count($appelsVers('#^17841/media_publish$#')) === 1);
check('post montage publié', $p4['status'] === 'Publié' && empty($p4['partial']));

echo "Frise de grille : 3 posts Instagram, visuel entier sur Facebook\n";
$full = 'data:image/jpeg;base64,' . base64_encode(str_repeat("\xFF\xD8visuel-entier", 40));
$frise = static fn (string $id, string $date, string $time, string $status) => ['id' => $id, 'date' => $date, 'time' => $time, 'type' => 'Gamme saisonnière',
    'title' => 'Frise ' . $id, 'shops' => ['4'], 'ch' => ['4' => ['fb' => true, 'ig' => true]], 'fb' => true, 'ig' => true, 'status' => $status, 'text' => 'Automne à {ville}',
    'img' => $full, 'media' => [['src' => $full, 'kind' => 'img']], 'frise' => ['tiles' => [$tranche(11), $tranche(12), $tranche(13)]],
    'montage' => ['kind' => 'frise', 'm' => ['title' => 'Automne'], 'photos' => []]];
$appels = [];
socStateSave(['changes' => ['posts' => ['upsert' => [$frise('p5', date('Y-m-d'), date('H:i'), 'Publié')]]]]);
$p5 = socItem('posts', 'p5');
$tuiles = array_map(static fn ($u) => socPublicUrl($u), $p5['frise']['tiles']);
$ig = $appelsVers('#^17841/media$#');
$chemins = array_column($appels, 1);
check('frise : 3 conteneurs préparés avant toute publication', count($ig) === 3
    && max(array_keys($chemins, '17841/media', true)) < min(array_keys($chemins, '17841/media_publish', true)));
check('frise : publiée de droite à gauche (tuile 3, 2, 1)', array_column(array_column($ig, 2), 'image_url') === array_reverse($tuiles));
check('frise : 3 publications, ni carrousel ni CAROUSEL', count($appelsVers('#^17841/media_publish$#')) === 3 && !array_filter($ig, static fn ($a) => isset($a[2]['is_carousel_item']) || isset($a[2]['media_type'])));
check('frise : les 3 ids Instagram notés dans le post', count($p5['frise']['ig']['4'] ?? []) === 3 && $p5['status'] === 'Publié');
check('frise : Facebook reçoit le visuel entier en une photo', count($appelsVers('#^111/photos$#')) === 1 && $appelsVers('#^111/photos$#')[0][2]['url'] === socPublicUrl($p5['media'][0]['src']));

echo "Frise : verrou d'une demi-heure, relance sans doublon\n";
$j5 = date('Y-m-d', strtotime('+5 days'));
socStateSave(['changes' => ['posts' => ['upsert' => [$frise('p7', $j5, '10:00', 'Programmé')]]]]);
check('frise programmée', socItem('posts', 'p7')['status'] === 'Programmé');
$p8 = ['id' => 'p8', 'date' => $j5, 'time' => '10:20', 'title' => 'Trop près', 'shops' => ['4'], 'ch' => ['4' => ['fb' => true, 'ig' => true]], 'fb' => true, 'ig' => true, 'status' => 'Programmé', 'text' => 'x', 'img' => $img];
$r = socStateSave(['changes' => ['posts' => ['upsert' => [$p8]]]]);
$p8b = socItem('posts', 'p8');
check('post Instagram à 20 min d’une frise : refusé, gardé en brouillon avec le motif', $p8b['status'] === 'Brouillon' && str_contains((string) $p8b['error'], '30 minutes')
    && str_contains($r['errors'][0]['error'] ?? '', 'Frise p7') && (int) Db::row("SELECT COUNT(*) n FROM social_publication WHERE content_id = 'p8'")['n'] === 0);
$p8['time'] = '10:40';
socStateSave(['changes' => ['posts' => ['upsert' => [$p8]]]]);
check('à 40 min : accepté et en file', socItem('posts', 'p8')['status'] === 'Programmé' && (int) Db::row("SELECT COUNT(*) n FROM social_publication WHERE content_id = 'p8'")['n'] === 2);
$p9 = ['id' => 'p9', 'date' => $j5, 'time' => '10:10', 'title' => 'Facebook seul', 'shops' => ['4'], 'ch' => ['4' => ['fb' => true, 'ig' => false]], 'fb' => true, 'ig' => false, 'status' => 'Programmé', 'text' => 'x'];
socStateSave(['changes' => ['posts' => ['upsert' => [$p9]]]]);
check('Facebook seul pendant la frise : accepté', socItem('posts', 'p9')['status'] === 'Programmé');
$appels = []; $igPanne = 2; $igPub = 0;
Db::exec("UPDATE social_publication SET prevue_le = NOW() - INTERVAL 1 MINUTE WHERE content_id = 'p7'");
socRunQueue('p7');
$p7 = socItem('posts', 'p7');
check('frise interrompue à la 2e tuile : 1 tuile en ligne, notée, échec Instagram', count($p7['frise']['ig']['4'] ?? []) === 1 && ($p7['partial']['ko'][0]['channel'] ?? '') === 'Instagram');
$appels = []; $igPanne = 0;
$p7 = socRetry('p7');
check('relance : seules les 2 tuiles manquantes repartent', count($appelsVers('#^17841/media$#')) === 2 && count($p7['frise']['ig']['4'] ?? []) === 3 && empty($p7['partial']),
    json_encode($p7['frise']['ig'] ?? null));
check('relance : dans l’ordre (tuile 2 puis 1)', array_column(array_column($appelsVers('#^17841/media$#'), 2), 'image_url') === [socPublicUrl($p7['frise']['tiles'][1]), socPublicUrl($p7['frise']['tiles'][0])]);
$p7['text'] = 'Automne à {ville} !';
socStateSave(['changes' => ['posts' => ['upsert' => [array_diff_key($p7, ['frise' => 1]) + ['frise' => ['tiles' => $p7['frise']['tiles']]]]]]]);
check('l’écran ne peut pas effacer les ids des tuiles publiées', count(socItem('posts', 'p7')['frise']['ig']['4'] ?? []) === 3);

$p10 = ['id' => 'p10', 'date' => $j5, 'time' => '12:00', 'title' => 'Midi', 'shops' => ['4'], 'ch' => ['4' => ['fb' => false, 'ig' => true]], 'fb' => false, 'ig' => true, 'status' => 'Programmé', 'text' => 'x', 'img' => $img];
$f11 = $frise('p11', $j5, '14:00', 'Programmé');
socStateSave(['changes' => ['posts' => ['upsert' => [$p10, $f11]]]]);
// La grille échange les créneaux de la frise et du post de midi : un seul envoi, deux objets.
$r = socStateSave(['changes' => ['posts' => ['upsert' => [array_merge($p10, ['time' => '14:00']), array_merge($f11, ['time' => '12:00'])]]]]);
check('grille : échange de créneaux frise ↔ post, accepté (verrou évalué sur le nouvel état)', !$r['errors'] && socItem('posts', 'p10')['time'] === '14:00'
    && socItem('posts', 'p11')['time'] === '12:00' && socItem('posts', 'p11')['status'] === 'Programmé', json_encode($r['errors']));

echo "Grille Instagram\n";
$sync = socIgMediaSync();
check('grille relue chez Instagram (Halle seulement : Wavre n’a pas de compte)', ($sync['4'] ?? null) === 2 && !isset($sync['5']), json_encode($sync));
$g = socIgGrille('4');
check('grille : du plus récent au plus ancien, vignette pour la vidéo', array_column($g['media'], 'id') === ['m2', 'm1'] && $g['media'][1]['src'] === 'https://cdn.ig/m1.jpg' && $g['lu_le'] !== null);
[$code, $b] = (static function () { $_GET['shop'] = '4'; try { return socRoute('GET', '/instagram/grille'); } finally { unset($_GET['shop']); } })();
check('GET /instagram/grille?shop=4', $code === 200 && count($b['media']) === 2 && $b['instagram'] === true);
$GLOBALS['SOC_BODY'] = ['shop' => '4'];
[$code, $b] = socRoute('POST', '/instagram/grille');
unset($GLOBALS['SOC_BODY']);
check('POST /instagram/grille : relecture immédiate', $code === 200 && $b['synchro'] === 2);

echo "Campagnes, publicités\n";
// Les opérations = campagnes commerciales du Marketing du BO, lues par son API (simulée ici).
$boAppels = [];
$GLOBALS['BO_CAMPAGNES'] = [['id' => 1, 'name' => 'Automne', 'scope' => 'BOUTIQUES', 'status_code' => 'planned', 'status_label' => 'Planifiée', 'starts_on' => $debutCamp,
    'ends_on' => date('Y-m-d', strtotime('+20 days')), 'budget_amount' => 400.0, 'spent_amount' => 0.0, 'type_code' => 'saison', 'type_label' => 'Saisonnalité', 'image_url' => './uploads/a.png'],
    ['id' => 2, 'name' => 'Présence réseau', 'scope' => 'RESEAU', 'status_code' => 'draft', 'status_label' => 'Brouillon', 'starts_on' => null, 'ends_on' => null, 'budget_amount' => 0.0]];
BoMarketing::$transport = static function (string $url) use (&$boAppels): array {
    $boAppels[] = $url;
    if (!empty($GLOBALS['BO_PANNE'])) { return [503, '']; }
    $p = preg_replace('#^.*/api/v1/marketing/#', '', $url);
    if ($p === 'campaigns') { return [200, json_encode($GLOBALS['BO_CAMPAGNES'])]; }
    if ($p === 'shops') { return [200, json_encode([['id' => 10, 'code' => 'erp-4', 'name' => 'Atelier by - Halle'], ['id' => 11, 'code' => 'erp-5', 'name' => 'Atelier Wavre'], ['id' => 12, 'code' => 'manuel']])]; }
    if ($p === 'campaigns/1') {
        return [200, json_encode($GLOBALS['BO_CAMPAGNES'][0] + ['shops' => [['shop_id' => 10, 'local_budget_amount' => 200.0], ['shop_id' => 11, 'local_budget_amount' => 200.0], ['shop_id' => 12]],
            'levers' => [['label' => 'CA additionnel', 'target_value' => 1000, 'target_unit' => 'EUR']], 'channels' => [['label' => 'Meta Ads']],
            'offer' => ['title' => 'Offre automne', 'mechanic_text' => '-20 % sur la gamme', 'items' => [['label' => 'Brioche croustillante']]]])];
    }
    if ($p === 'campaigns/2') { return [200, json_encode($GLOBALS['BO_CAMPAGNES'][1] + ['shops' => [], 'levers' => [], 'offer' => null])]; }
    return [404, '{}'];
};
$ops = array_column(socOperations(true), null, 'id');
$o1 = $ops['bo-1'] ?? [];
check('opérations = campagnes du Marketing du BO, lues par son API', count($ops) === 2 && ($o1['name'] ?? '') === 'Automne' && $o1['status'] === 'en cours' && $o1['boStatus'] === 'Planifiée'
    && (bool) array_filter($boAppels, static fn ($u) => str_ends_with($u, '/api/v1/marketing/campaigns/1')), json_encode($o1));
check('boutiques du Marketing (erp-N) → boutiques du module, budget par boutique, produits de l’offre, objectif', $o1['shops'] === ['4', '5'] && $o1['split'] === ['4' => 200.0, '5' => 200.0]
    && $o1['products'] === ['Brioche croustillante'] && $o1['goal'] === ['metric' => 'CA additionnel', 'value' => 1000.0, 'unit' => 'EUR'] && $o1['budget'] == 400
    && str_ends_with((string) $o1['image'], '/consulant_bo/assistant/uploads/a.png') && str_ends_with((string) $o1['lien'], '/assistant/?id=1'), json_encode($o1));
check('campagne réseau sans boutique choisie : tout le réseau ; sans date : à venir', count($ops['bo-2']['shops']) === count(socShops()) && $ops['bo-2']['status'] === 'à venir');
$r = socStateSave(['changes' => ['campaigns' => ['upsert' => [['id' => 'c9', 'name' => 'Locale']]]]]);
check('une opération ne se crée pas ici : refus, rien en base', str_contains((string) ($r['errors'][0]['error'] ?? ''), 'Marketing du BO') && Db::row("SELECT COUNT(*) n FROM social_campagne")['n'] == 0);
[$code, $b] = socRoute('GET', '/operations');
check('GET /operations', $code === 200 && count($b['operations']) === 2 && $b['erreur'] === null);
$GLOBALS['BO_PANNE'] = true;
$panne = socOperations(true);
check('API Marketing injoignable : aucune opération, motif clair', $panne === [] && str_contains((string) socOperationsErreur(), 'HTTP 503'));
unset($GLOBALS['BO_PANNE']); socOperations(true);
$spec = ['name' => 'Automne trafic', 'objective' => 'OUTCOME_TRAFFIC', 'status' => 'ACTIVE', 'special' => [], 'bid' => 'LOWEST_COST_WITHOUT_CAP', 'bidAmount' => 0.6, 'spendCap' => 300,
    'budgetLevel' => 'adset', 'budgetType' => 'daily', 'amount' => 10, 'account' => 'boutique', 'shops' => ['4', '5'], 'radius' => ['4' => 8, '5' => 10],
    'goal' => 'LINK_CLICKS', 'dest' => 'WEBSITE', 'start' => date('Y-m-d'), 'end' => date('Y-m-d', strtotime('+20 days')), 'ageMin' => 18, 'ageMax' => 65, 'gender' => 'all',
    'interests' => ['Boulangerie'], 'platforms' => ['facebook', 'instagram'], 'advantage' => false, 'dsaB' => "L'Atelier By SRL", 'dsaP' => "L'Atelier By SRL",
    'crea' => 'existing', 'post' => 'p1', 'cta' => 'LEARN_MORE', 'link' => 'https://www.latelierby.be', 'adName' => 'Automne', 'adStatus' => 'ACTIVE', 'pixel' => false, 'urlTags' => 'utm_source=meta'];
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [['id' => 'a1', 'name' => 'Automne trafic', 'campaign' => 'bo-1', 'objective' => 'Trafic', 'shops' => ['4', '5'],
    'scope' => 'boutique', 'budgetType' => 'quotidien', 'budget' => 20, 'cap' => 300, 'spent' => 0, 'start' => $spec['start'], 'end' => $spec['end'], 'status' => 'En revue', 'radius' => 9, 'post' => 'p1', 'spec' => $spec]]]]]);
$a1 = socItem('ads', 'a1');
check('pub créée chez Meta → En revue', $a1['status'] === 'En revue', (string) ($a1['reason'] ?? ''));
check('un jeu par compte boutique (2 campagnes)', count($appelsVers('#^act_\d+/campaigns$#')) === 2);
check('Halle bilingue : 2 ad sets (NL, FR) avec locales', count(array_filter($appelsVers('#^act_1001/adsets$#'), static fn ($a) => str_contains($a[2]['targeting'], '"locales"'))) === 2);
check('ABO : stratégie d’enchère sur chaque ad set, pas sur la campagne (Meta la refuse sans budget de campagne)',
    !array_filter($appelsVers('#^act_\d+/campaigns$#'), static fn ($a) => isset($a[2]['bid_strategy']))
    && count(array_filter($appelsVers('#^act_\d+/adsets$#'), static fn ($a) => ($a[2]['bid_strategy'] ?? '') === 'LOWEST_COST_WITHOUT_CAP')) === count($appelsVers('#^act_\d+/adsets$#')));
check('coût le plus bas : pas de bid_amount', !array_filter($appelsVers('#^act_\d+/adsets$#'), static fn ($a) => isset($a[2]['bid_amount'])));
check('montants en centimes (10 € → 1000 par jour)', str_contains(json_encode($appelsVers('#^act_1002/adsets$#')[0][2] ?? []), '"daily_budget":1000'));
check('rayon en km sur la position de la boutique', str_contains($appelsVers('#^act_1002/adsets$#')[0][2]['targeting'] ?? '', '"radius":10'));
check('post sponsorisé : object_story_id du post publié', preg_match('/^111_\d+$/', (string) ($appelsVers('#^act_1001/adcreatives$#')[0][2]['object_story_id'] ?? '')) === 1);
check('intérêt résolu par Targeting Search', str_contains($appelsVers('#^act_1002/adsets$#')[0][2]['targeting'] ?? '', '6003107902'));
check('objets Meta notés', (int) Db::row("SELECT COUNT(*) n FROM social_meta_objet WHERE kind = 'ad' AND local_id = 'a1' AND ad_id IS NOT NULL")['n'] === 3);
$appels = [];
$a1['status'] = 'En pause';
socStateSave(['changes' => ['ads' => ['upsert' => [$a1]]]]);
check('pause → status=PAUSED sur chaque pub', count(array_filter($appels, static fn ($a) => ($a[2]['status'] ?? '') === 'PAUSED')) === 3);
$a1 = socItem('ads', 'a1'); $appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [array_merge($a1, ['budget' => 55])]]]]);
check('budget périmé envoyé avec la pub : rien ne change chez Meta', !array_filter($appels, static fn ($a) => isset($a[2]['daily_budget'])) && socItem('ads', 'a1')['budget'] == 20);
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [array_merge(socItem('ads', 'a1'), ['budgetDemande' => 30])]]]]);
$budgets = array_map(static fn ($a) => $a[2]['daily_budget'], array_values(array_filter($appels, static fn ($a) => isset($a[2]['daily_budget']))));
sort($budgets);
check('budget demandé : réparti par campagne (une par boutique) puis par ad set (Halle bilingue : 2)', $budgets === [750, 750, 1500] && socItem('ads', 'a1')['budget'] == 30
    && !isset(socItem('ads', 'a1')['budgetDemande']) && (socItem('ads', 'a1')['spec']['amount'] ?? null) == 15, json_encode($budgets));
$camps1 = array_values(array_unique(array_column(Db::rows("SELECT campaign_id FROM social_meta_objet WHERE kind = 'ad' AND local_id = 'a1' AND ad_id IS NOT NULL"), 'campaign_id')));
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [array_merge(socItem('ads', 'a1'), ['status' => 'Active'])]]]]);
check('reprise : pubs, ad sets et campagnes remis en ACTIVE chez Meta', count(array_filter($appels, static fn ($a) => in_array($a[1], $camps1, true) && ($a[2]['status'] ?? '') === 'ACTIVE')) === count($camps1)
    && count($camps1) === 2, json_encode(array_map(static fn ($a) => [$a[1], $a[2]['status'] ?? null], $appels)));
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [array_merge(socItem('ads', 'a1'), ['status' => 'En pause'])]]]]);
socAdsSync();
check('synchro : statut Meta ACTIVE, dépense relue', socItem('ads', 'a1')['status'] === 'Active' && (float) socItem('ads', 'a1')['spent'] === 121.5);
check('dépense en colonne', Db::row("SELECT depense FROM social_publicite WHERE id = 'a1'")['depense'] === '121.50');
socStateSave(['changes' => ['ads' => ['upsert' => [['id' => 'a2', 'name' => 'Sans spec', 'shops' => ['5'], 'status' => 'En revue']]]]]);
check('pub sans description d’assistant : refusée avec motif', socItem('ads', 'a2')['status'] === 'Refusée');
// Centre d'intérêt abandonné par Meta : retiré du ciblage, ad set renvoyé, écarté des recherches suivantes.
$pubTest = static fn (string $id, array $interets) => ['id' => $id, 'name' => 'Test ' . $id, 'shops' => ['5'], 'scope' => 'boutique', 'budgetType' => 'quotidien', 'budget' => 10, 'cap' => 0,
    'spent' => 0, 'start' => $spec['start'], 'end' => $spec['end'], 'status' => 'En revue', 'radius' => 8, 'post' => 'p1', 'spec' => array_merge($spec, ['shops' => ['5'], 'interests' => $interets])];
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [$pubTest('a9', ['Boulangerie', 'Pâtisserie'])]]]]);
$a9 = socItem('ads', 'a9'); $as9 = $appelsVers('#^act_\d+/adsets$#');
check('intérêt abandonné : retiré, ad set renvoyé, pub créée et notée', $a9['status'] !== 'Refusée' && count($as9) === 2 && str_contains($as9[0][2]['targeting'], '6003999001')
    && !str_contains($as9[1][2]['targeting'], '6003999001') && str_contains($as9[1][2]['targeting'], '6003107902') && str_contains((string) ($a9['note'] ?? ''), '« Pâtisserie »'),
    json_encode([$a9['status'], $a9['reason'] ?? null, $a9['note'] ?? null, count($as9)]));
check('intérêt abandonné : écarté des recherches suivantes', (socInteretsAbandonnes()['6003999001'] ?? '') === 'Pâtisserie' && (socInterets(['Pâtisserie'])[0]['id'] ?? '') === '6003999002');
// Aucun ad set créé : la campagne vide ne reste pas chez Meta.
$appels = []; $GLOBALS['SUPPRIMES'] = [];
socStateSave(['changes' => ['ads' => ['upsert' => [$pubTest('a10', ['Refus'])]]]]);
$camp10 = (string) (Db::row("SELECT campaign_id FROM social_meta_objet WHERE kind = 'ad' AND local_id = 'a10'")['campaign_id'] ?? '');
check('aucun ad set : pub refusée, campagne vide supprimée chez Meta', socItem('ads', 'a10')['status'] === 'Refusée' && $camp10 !== ''
    && in_array($camp10, array_column($GLOBALS['SUPPRIMES'], 0), true), json_encode([$camp10, $GLOBALS['SUPPRIMES']]));
socStateSave(['changes' => ['ads' => ['delete' => ['a9', 'a10']]]]);
socSettingPut('meta_interets_abandonnes', null); socSettingPut('meta_cache_interets', null);

echo "Dark posts : réservés à la publicité\n";
$video = 'data:video/mp4;base64,' . base64_encode(str_repeat("\x00\x00\x00\x18ftypmp42", 30));
$d1 = ['id' => 'd1', 'date' => date('Y-m-d'), 'time' => '09:00', 'title' => 'Panorama automne', 'shops' => ['4'], 'ch' => ['4' => ['fb' => true, 'ig' => true]], 'fb' => true, 'ig' => true,
    'status' => 'Programmé', 'dark' => true, 'text' => 'La gamme automne à {ville}', 'img' => $tranche(31),
    'media' => [['src' => $tranche(31), 'kind' => 'img'], ['src' => $tranche(32), 'kind' => 'img'], ['src' => $tranche(33), 'kind' => 'img']],
    'montage' => ['kind' => 'pano', 'm' => ['n' => 3], 'photos' => []]];
$d2 = ['id' => 'd2', 'date' => date('Y-m-d'), 'time' => '09:00', 'title' => 'Film brioche', 'shops' => ['4'], 'fb' => true, 'ig' => true, 'status' => 'Brouillon', 'dark' => true,
    'text' => 'x', 'media' => [['src' => $video, 'kind' => 'video', 'name' => 'brioche.mp4']]];
socStateSave(['changes' => ['posts' => ['upsert' => [$d1, $d2]]]]);
check('dark post : jamais publié (statut « Dark post », rien en file)', socItem('posts', 'd1')['status'] === 'Dark post' && socItem('posts', 'd2')['status'] === 'Dark post'
    && (int) Db::row("SELECT COUNT(*) n FROM social_publication WHERE content_id IN ('d1','d2')")['n'] === 0);
check('dark post : absent de la grille Instagram (non programmé)', !in_array('d1', array_column(socItems('posts'), 'id'), true) || socItem('posts', 'd1')['status'] !== 'Programmé');
$appels = [];
$spec3 = array_merge($spec, ['name' => 'Automne dark', 'shops' => ['4'], 'crea' => 'new', 'post' => 'd1', 'message' => 'Le panorama de {ville}', 'messageNl' => 'Het panorama van {ville}',
    'headline' => 'Gamme automne', 'headlineNl' => 'Herfstgamma', 'variants' => [['post' => 'd2', 'message' => 'Le film de la brioche', 'headline' => '']], 'adName' => 'Automne dark']);
socStateSave(['changes' => ['ads' => ['upsert' => [['id' => 'a3', 'name' => 'Automne dark', 'campaign' => null, 'objective' => 'Trafic', 'shops' => ['4'], 'scope' => 'boutique',
    'budgetType' => 'quotidien', 'budget' => 10, 'cap' => 200, 'spent' => 0, 'start' => $spec['start'], 'end' => $spec['end'], 'status' => 'En revue', 'radius' => 8, 'post' => 'd1', 'spec' => $spec3]]]]]);
$a3 = socItem('ads', 'a3');
$cr = array_column($appelsVers('#^act_1001/adcreatives$#'), 2);
$specs = array_map(static fn ($c) => json_decode((string) ($c['object_story_spec'] ?? '{}'), true), $cr);
$carr = array_values(array_filter($specs, static fn ($x) => isset($x['link_data']['child_attachments'])));
$vids = array_values(array_filter($specs, static fn ($x) => isset($x['video_data'])));
check('dark post : Halle bilingue × 2 variantes = 4 créas et 4 pubs', count($cr) === 4 && count($appelsVers('#^act_1001/ads$#')) === 4 && $a3['status'] === 'En revue', (string) ($a3['reason'] ?? ''));
check('variante A (panorama) : carrousel de 3 cartes, ordre verrouillé, sans carte de fin', count($carr) === 2 && count($carr[0]['link_data']['child_attachments']) === 3
    && $carr[0]['link_data']['multi_share_optimized'] === false && $carr[0]['link_data']['multi_share_end_card'] === false);
check('variante A : texte par langue, variables résolues', in_array('Het panorama van Halle', array_column(array_column($carr, 'link_data'), 'message'), true)
    && in_array('Le panorama de Halle', array_column(array_column($carr, 'link_data'), 'message'), true));
check('variante B (vidéo) : video_data, vignette de Meta, titre repris de la variante A', count($vids) === 2 && str_starts_with((string) $vids[0]['video_data']['video_id'], 'v')
    && ($vids[0]['video_data']['image_url'] ?? '') === 'https://cdn.fb/vignette.jpg' && in_array($vids[0]['video_data']['title'], ['Gamme automne', 'Herfstgamma'], true));
check('vidéo envoyée une fois au compte (advideos), images une fois chacune (adimages)', count($appelsVers('#^act_1001/advideos$#')) === 1 && count($appelsVers('#^act_1001/adimages$#')) === 3);
check('identité Instagram de la boutique sur chaque créa', count(array_filter($specs, static fn ($x) => ($x['instagram_user_id'] ?? '') === '17841')) === 4);
$objs = Db::rows("SELECT * FROM social_meta_objet WHERE kind = 'ad' AND local_id = 'a3' AND ad_id IS NOT NULL ORDER BY id");
check('dark posts relus : id Facebook, id Instagram, liens', count($objs) === 4 && !array_filter($objs, static fn ($o) => !$o['story_id'] || !$o['ig_media_id'] || !str_starts_with((string) $o['lien'], 'https://www.facebook.com/111_') || !str_starts_with((string) $o['lien_ig'], 'https://www.instagram.com/p/')));
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [['id' => 'a12', 'name' => 'Itinéraire', 'shops' => ['5'], 'scope' => 'boutique', 'budgetType' => 'quotidien', 'budget' => 10, 'cap' => 0, 'spent' => 0,
    'start' => $spec['start'], 'end' => $spec['end'], 'status' => 'En revue', 'radius' => 8, 'post' => 'd1', 'spec' => array_merge($spec3, ['shops' => ['5'], 'variants' => [], 'cta' => 'GET_DIRECTIONS'])]]]]]);
$cta12 = json_decode((string) ($appelsVers('#^act_\d+/adcreatives$#')[0][2]['object_story_spec'] ?? '{}'), true);
$lien12 = (string) ($cta12['link_data']['child_attachments'][0]['call_to_action']['value']['link'] ?? $cta12['link_data']['call_to_action']['value']['link'] ?? '');
try { Meta::call('POST', 'act_1002/adcreatives', ['name' => 'Créa · App en dev']); $devMsg = ''; } catch (MetaError $e) { $devMsg = $e->getMessage(); }
check('app Meta en développement : le refus dit quoi faire (mode Live)', str_contains($devMsg, 'en cours de développement') && str_contains($devMsg, 'mode Live'), $devMsg);
check('domaine de conversion : celui du lien (webshop.atelierby.be → atelierby.be)', socDomaineConversion('https://webshop.atelierby.be/?shop=4') === 'atelierby.be' && socDomaineConversion('https://www.latelierby.be') === 'latelierby.be' && socDomaineConversion('') === '');
check('bouton « Itinéraire » : position de la boutique (fbgeo://), pas une adresse web', str_starts_with($lien12, 'fbgeo://') && socItem('ads', 'a12')['status'] !== 'Refusée', $lien12);
socStateSave(['changes' => ['ads' => ['delete' => ['a12']]]]);
check('variante, langue et post du module notés', array_map(static fn ($o) => [(int) $o['variante'], $o['langue'], $o['post_id'], (int) $o['dark']], $objs)
    === [[0, 'nl', 'd1', 1], [1, 'nl', 'd2', 1], [0, 'fr', 'd1', 1], [1, 'fr', 'd2', 1]]);
$appels = [];
$spec4 = array_merge($spec, ['name' => 'Relance', 'shops' => ['4'], 'crea' => 'existing', 'post' => 'd1', 'adName' => 'Relance']);
socStateSave(['changes' => ['ads' => ['upsert' => [['id' => 'a4', 'name' => 'Relance', 'shops' => ['4'], 'scope' => 'boutique', 'budgetType' => 'quotidien', 'budget' => 5, 'cap' => 50, 'spent' => 0,
    'start' => $spec['start'], 'end' => $spec['end'], 'status' => 'En revue', 'radius' => 8, 'post' => 'd1', 'spec' => $spec4]]]]]);
$stories = array_column(array_column($appelsVers('#^act_1001/adcreatives$#'), 2), 'object_story_id');
$attendu = [Db::row("SELECT story_id FROM social_meta_objet WHERE local_id = 'a3' AND post_id = 'd1' AND langue = 'nl'")['story_id'], Db::row("SELECT story_id FROM social_meta_objet WHERE local_id = 'a3' AND post_id = 'd1' AND langue = 'fr'")['story_id']];
check('réutilisation : le même dark post (par langue) sponsorisé à nouveau', $stories === $attendu, json_encode($stories));
socAdsSync();
$st3 = socItem('ads', 'a3')['stats'] ?? [];
check('résultats par variante (A, B) : dépense, impressions, clics, CTR', array_column($st3, 'variant') === ['A', 'B'] && $st3[0]['impressions'] === 6000 && abs((float) $st3[0]['ctr'] - 0.02) < 0.0001, json_encode($st3));

echo "Dark posts : commentaires\n";
$sync = socDarkCommentsSync('a3');
$com = socDarkComments('a3', null);
check('commentaires relus sur les dark posts Facebook et Instagram', $sync['lus'] === 8 && count($com) === 8 && count(array_filter($com, static fn ($c) => $c['channel'] === 'Instagram')) === 4, json_encode($sync));
check('commentaires aussi retrouvés par post du module', count(socDarkComments(null, 'd2')) === 4);
$appels = [];
$cid = $com[0]['id'];
$GLOBALS['SOC_BODY'] = ['masquer' => true];
[$code, $b] = socRoute('POST', '/darkposts/commentaires/' . $cid . '/masquer');
unset($GLOBALS['SOC_BODY']);
$hide = $appels[count($appels) - 1] ?? [];
check('masquer un commentaire : appel Meta et état en base', $code === 200 && $hide[1] === $cid && (($hide[2]['is_hidden'] ?? $hide[2]['hide'] ?? '') === 'true')
    && (int) Db::row('SELECT masque FROM social_commentaire WHERE id = ?', [$cid])['masque'] === 1);
[$code, $b] = (static function () { $_GET['ad'] = 'a3'; try { return socRoute('GET', '/darkposts/commentaires'); } finally { unset($_GET['ad']); } })();
check('GET /darkposts/commentaires?ad=a3', $code === 200 && count($b['comments']) === 8);
$dp = array_values(array_filter(socStateGet()['darkPosts'], static fn ($x) => $x['ad'] === 'a3'));
check('état : dark posts de la pub avec liens et nombre de commentaires', count($dp) === 4 && $dp[0]['comments'] === 2 && $dp[0]['link'] !== '' && array_sum(array_column($dp, 'hidden')) === 1);

echo "Espace franchisé : accès et cloisonnement\n";
$fr = static function (string $m, string $path, array $body = [], array $get = []) {
    $GLOBALS['SOC_BODY'] = $body; $_GET = $get;
    try { return socRoute($m, $path); } catch (HttpError $e) { return [$e->status, ['error' => $e->getMessage()]]; }
    finally { unset($GLOBALS['SOC_BODY']); $_GET = []; }
};
unset($_COOKIE[SF_COOKIE]);
[$code, $b] = $fr('GET', '/franchise/admin');
check('modèles franchisé livrés (5), règles par défaut', $code === 200 && count($b['templates']) === 5 && $b['rules']['quotaSemaine'] === 3 && $b['rules']['validation'] === 'modele');
[$code, $b] = $fr('GET', '/franchise/etat');
check('sans session (ni ERP, ni aperçu) : 401, renvoyé vers l’ERP', $code === 401 && str_contains((string) $b['error'], 'ERP'));
[$c1] = $fr('POST', '/franchise/connexion', ['code' => 'ATB-AAAAA-BBBBB']);
[$c2] = $fr('POST', '/franchise/connexion', ['phone' => '0499', 'password' => 'ok']);
[$c3] = $fr('POST', '/franchise/admin/acces', ['shop' => '4', 'label' => 'Nathan']);
[$c4] = $fr('GET', '/franchise/session');
check('pas de connexion propre à l’espace (ni code d’accès, ni compte atelierby) : aucune session ouverte, l’accès passe par l’ERP', $c1 === 401 && $c2 === 401 && $c4 === 401 && $c3 === 404
    && sfSession() === null && empty($_COOKIE[SF_COOKIE]), json_encode([$c1, $c2, $c3, $c4]));
// Le gérant connecté à l'ERP (config/hote.php → franchise_session).
$GLOBALS['SOCIAL_HOTE'] = ['franchise_session' => static fn (): ?array => ['shops' => ['4'], 'name' => 'Nathan (ERP)']];
[$code, $et] = $fr('GET', '/franchise/etat');
$ids = array_column($et['posts'] ?? [], 'id');
check('état : sa boutique, son catalogue (API, sans B2B ni inactif), les campagnes où elle figure', $code === 200 && $et['shop']['name'] === 'Halle'
    && array_column($et['catalogue'], 'id') === ['p1'] && $et['catalogue'][0]['nameNl'] === 'Krokante brioche' && in_array('bo-1', array_column($et['campaigns'], 'id'), true), json_encode($et['error'] ?? ''));
check('état : posts du réseau qui la concernent, jamais les dark posts ni ceux des autres boutiques', in_array('p1', $ids, true) && !in_array('d1', $ids, true) && !in_array('p2', $ids, true));
[$code] = $fr('GET', '/franchise/etat', [], ['shop' => '5']);
check('une autre boutique : 403', $code === 403);
socSettingPut('visuels_modeles', [['id' => 'vm1', 'nom' => 'Promo du mois', 'v' => ['style' => 'ruby', 'format' => 'portrait', 'fond' => 'noir']]], false);
socSettingPut('etiquettes_modeles', [['id' => 'e1', 'nom' => 'Badge abricot', 'forme' => 'pastille', 'police' => 'titre', 'couleur' => 'ruby', 'fond' => 'abricot']], false);
[$code, $et2] = $fr('GET', '/franchise/etat');
check('état : les modèles de visuel et d’étiquette du réseau, pour l’éditeur de visuel de la boutique', ($et2['visuelsModeles'][0]['nom'] ?? '') === 'Promo du mois'
    && ($et2['visuelsModeles'][0]['v']['style'] ?? '') === 'ruby' && ($et2['etiquettesModeles'][0]['nom'] ?? '') === 'Badge abricot', json_encode([$et2['visuelsModeles'] ?? null, $et2['etiquettesModeles'] ?? null]));
socSettingPut('visuels_modeles', null, false); socSettingPut('etiquettes_modeles', null, false);

echo "Espace franchisé : publier depuis un modèle\n";
$vis = 'data:image/png;base64,' . base64_encode(str_repeat("\x89PNGvisuel", 60));
$j3 = date('Y-m-d', strtotime('+3 days')); $j8 = date('Y-m-d', strtotime('+8 days'));
$promoOk = ['template' => 'fp-remise', 'product' => 'p1', 'discount' => 20, 'from' => $j3, 'to' => $j8, 'fb' => true, 'ig' => true, 'date' => $j3, 'time' => '10:00', 'visual' => $vis];
[$code, $b] = $fr('POST', '/franchise/posts', $promoOk);
$pf = socItem('posts', (string) ($b['post']['id'] ?? ''));
check('promotion -20 % : créée « à valider », texte du modèle rempli, rien en file', $code === 200 && $pf['status'] === 'À valider' && $pf['origin'] === 'franchise'
    && str_starts_with($pf['text'], '-20 % sur Brioche croustillante chez L’Atelier By {ville}, du ' . date('d/m', strtotime($j3)))
    && str_contains($pf['textNl'], 'Krokante brioche') && (int) Db::row('SELECT COUNT(*) n FROM social_publication WHERE content_id = ?', [$pf['id']])['n'] === 0, json_encode($b));
check('visuel écrit en fichier, promo notée', str_starts_with((string) $pf['img'], 'uploads/') && ($pf['promo']['label'] ?? '') === '-20 %' && ($pf['promo']['to'] ?? '') === $j8);
$refus = static function (array $patch, string $attendu) use ($fr, $promoOk) {
    [$code, $b] = $fr('POST', '/franchise/posts', array_merge($promoOk, $patch));
    return $code === 422 && str_contains((string) ($b['error'] ?? ''), $attendu);
};
check('remise hors bornes refusée', $refus(['discount' => 50], 'Remise entre 10 et 30 %'));
check('moins de 24 h à l’avance refusé', $refus(['date' => date('Y-m-d', strtotime('+1 hour')), 'time' => date('H:i', strtotime('+1 hour'))], 'à l’avance'));
check('heure hors plage refusée', $refus(['time' => '22:30'], 'entre 07:00 et 20:00'));
check('produit hors catalogue refusé', $refus(['product' => 'p2'], 'votre catalogue'));
check('promotion trop longue refusée', $refus(['to' => date('Y-m-d', strtotime('+30 days'))], 'au plus 14 jours'));
check('modèle inconnu refusé', $refus(['template' => 'fp-inexistant'], 'pas (ou plus) ouvert'));
[$code, $b] = $fr('POST', '/franchise/posts', ['template' => 'fp-evenement', 'free' => 'Dégustation samedi, infos sur www.exemple.be', 'fb' => true, 'date' => $j3, 'time' => '11:00', 'visual' => $vis, 'photo' => $vis]);
check('phrase libre : pas de lien', $code === 422 && str_contains($b['error'], 'Pas de lien'));
[$code, $b] = $fr('POST', '/franchise/posts', ['template' => 'fp-produit', 'product' => 'p1', 'fb' => true, 'ig' => false, 'date' => $j3, 'time' => '09:00', 'visual' => $vis]);
$pp = socItem('posts', (string) ($b['post']['id'] ?? ''));
check('produit du jour (sans validation) : programmé et en file, Facebook seul', $code === 200 && $pp['status'] === 'Programmé'
    && Db::rows('SELECT canal FROM social_publication WHERE content_id = ?', [$pp['id']]) === [['canal' => 'fb']] && sfQuotaUtilise('4', $j3) === 2, json_encode([$code, $b, Db::rows('SELECT canal FROM social_publication WHERE content_id = ?', [$pp['id'] ?? ''])]));
socStateSave(['changes' => ['posts' => ['upsert' => [array_merge(socItem('posts', $pf['id']), ['status' => 'Brouillon', 'refus' => 'Remise trop forte pour cette période'])]]]]);
[$code, $et] = $fr('GET', '/franchise/etat');
$vue = array_values(array_filter($et['posts'], static fn ($x) => $x['id'] === $pf['id']))[0] ?? [];
check('refusé par le franchiseur : vu « Refusé » avec le motif, hors quota', ($vue['status'] ?? '') === 'Refusé' && ($vue['refus'] ?? '') === 'Remise trop forte pour cette période' && $et['quota']['used'] <= 2);
$fr('POST', '/franchise/admin/regles', ['rules' => array_merge(sfRegles(), ['quotaSemaine' => 1, 'validation' => 'toujours'])]);
check('quota de la semaine atteint : refusé', $refus(['date' => $j3, 'time' => '15:00'], 'Quota atteint'));
$fr('POST', '/franchise/admin/regles', ['rules' => array_merge(sfRegles(), ['quotaSemaine' => 5])]);
[$code, $b] = $fr('POST', '/franchise/posts', ['template' => 'fp-produit', 'product' => 'p1', 'fb' => true, 'date' => $j3, 'time' => '16:00', 'visual' => $vis]);
check('règle « toujours valider » : même un modèle sans validation part « à valider »', $code === 200 && socItem('posts', $b['post']['id'])['status'] === 'À valider');
[$code] = $fr('POST', '/franchise/posts/' . $b['post']['id'] . '/annuler', ['shop' => '4']);
check('annuler son post non publié', $code === 200 && socItem('posts', $b['post']['id']) === null);
[$code] = $fr('POST', '/franchise/posts/p1/annuler', ['shop' => '4']);
check('annuler un post du réseau : impossible', $code === 404);

echo "Espace franchisé : aperçu du franchiseur, espace fermé\n";
unset($_COOKIE[SF_COOKIE]);
[$code, $b] = $fr('POST', '/franchise/admin/apercu', ['shop' => '5']);
check('aperçu franchiseur : session sur la boutique choisie (prime sur celle de l’ERP)', $code === 200 && sfSession()['shops'] === ['5'] && sfSession()['via'] === 'apercu');
[$code] = $fr('POST', '/franchise/deconnexion');
check('aperçu fermé : retour à la session de l’ERP', $code === 200 && sfSession()['via'] === 'hote' && sfSession()['shops'] === ['4']);
$fr('POST', '/franchise/admin/regles', ['rules' => array_merge(sfRegles(), ['actif' => false])]);
[$code] = $fr('GET', '/franchise/etat');
$fr('POST', '/franchise/admin/apercu', ['shop' => '4']);
[$code2] = $fr('GET', '/franchise/etat');
check('espace fermé par le franchiseur : le gérant (ERP) refusé, l’aperçu du franchiseur passe', $code === 403 && $code2 === 200);
$fr('POST', '/franchise/admin/regles', ['rules' => array_merge(sfRegles(), ['actif' => true, 'validation' => 'modele', 'quotaSemaine' => 3])]);
unset($_COOKIE[SF_COOKIE], $GLOBALS['SOCIAL_HOTE']);

echo "Espace franchisé : accès ouvert sur une boutique, en attendant l'ERP\n";
[$code, $b] = $fr('GET', '/franchise/etat');
check('sans ERP ni réglage : fermé (401)', $code === 401);
$fr('POST', '/franchise/admin/regles', ['rules' => array_merge(sfRegles(), ['accesLibre' => '4'])]);
[$code, $b] = $fr('GET', '/franchise/etat');
check('accès ouvert sur la boutique 4 : l’espace s’ouvre sans connexion, sur elle', $code === 200 && $b['shop']['id'] === '4' && $b['me']['via'] === 'libre'
    && array_column($b['me']['shops'], 'id') === ['4'] && empty($_COOKIE[SF_COOKIE]), json_encode($b['me'] ?? $b));
[$code] = $fr('GET', '/franchise/etat', [], ['shop' => '5']);
check('accès ouvert : les autres boutiques restent fermées (403)', $code === 403);
$GLOBALS['SOCIAL_HOTE'] = ['franchise_session' => static fn (): ?array => null];
[$code] = $fr('GET', '/franchise/etat');
check('ERP branché : l’accès ouvert ne joue plus (seule la session de l’ERP ouvre l’espace)', $code === 401);
unset($GLOBALS['SOCIAL_HOTE']);
check('réglage borné : une boutique hors du réseau est refusée', sfReglesPut(array_merge(sfRegles(), ['accesLibre' => '999']))['accesLibre'] === '');
$fr('POST', '/franchise/admin/regles', ['rules' => array_merge(sfRegles(), ['accesLibre' => ''])]);

echo "Transport dans l'ERP : points d'accroche de l'hôte\n";
$journalHote = [];
$GLOBALS['SOCIAL_HOTE'] = [
    'utilisateur' => static fn (): string => 'ERP · Marie Dupont',
    'auth' => static fn (): bool => true,
    'journal' => static function (string $at, string $qui, string $quoi, string $d) use (&$journalHote): void { $journalHote[] = [$qui, $quoi]; },
    'boutiques' => static fn (array $l): array => array_map(static fn ($s) => $s + ['erp' => true], $l),
    'franchise_session' => static fn (): ?array => ['shops' => ['5', '999'], 'name' => 'Julie (ERP)'],
];
$avantBo = (int) Db::row("SELECT COUNT(*) n FROM ceo_journal_entry")['n'];
socLog(socUser(), 'Test', 'journal de l’hôte');
check('hôte : utilisateur et journal de l’ERP (plus de miroir dans le BO)', socUser() === 'ERP · Marie Dupont' && $journalHote === [['ERP · Marie Dupont', 'Test']]
    && (int) Db::row("SELECT COUNT(*) n FROM ceo_journal_entry")['n'] === $avantBo);
check('hôte : boutiques complétées par l’ERP', !empty(socShop('4')['erp']));
unset($_COOKIE[SF_COOKIE]);
[$code, $b] = $fr('GET', '/franchise/etat');
check('hôte : franchisé connecté à l’ERP → son espace, ses boutiques du réseau seulement', $code === 200 && $b['shop']['id'] === '5' && $b['me']['via'] === 'hote'
    && array_column($b['me']['shops'], 'id') === ['5']);
unset($GLOBALS['SOCIAL_HOTE']);
[$code] = $fr('GET', '/franchise/etat');
check('sans hôte : retour au comportement du BO (401 sans session)', $code === 401 && socUser() !== 'ERP · Marie Dupont');

echo "Formulaires de leads\n";
$lf = ['id' => 'lf1', 'family' => 'b2b', 'version' => 1, 'status' => 'Actif', 'name' => 'Plateaux', 'locale' => 'fr_FR', 'created' => date('Y-m-d'), 'shops' => ['4', '5'],
    'questions' => [['type' => 'FULL_NAME'], ['type' => 'EMAIL'], ['type' => 'CUSTOM', 'key' => 'nb', 'label' => 'Combien ?', 'options' => [['key' => 'a', 'value' => '8 à 15']]]],
    'privacy' => ['url' => 'https://www.latelierby.be/confidentialite', 'link_text' => 'Confidentialité'], 'context' => ['title' => 'Plateaux', 'style' => 'LIST_STYLE', 'content' => ['Livraison'], 'button_text' => 'Devis'],
    'thanks' => ['title' => 'Merci', 'body' => 'À bientôt', 'button_type' => 'VIEW_WEBSITE', 'button_text' => 'Site', 'website_url' => 'https://www.latelierby.be'], 'quality' => true];
socStateSave(['changes' => ['leadforms' => ['upsert' => [$lf]]]]);
check('formulaire publié sur 2 Pages', count(socLeadformMetaIds('lf1')) === 2);
check('formulaire dans social_formulaire', Db::row("SELECT nom, version FROM social_formulaire WHERE id = 'lf1'") == ['nom' => 'Plateaux', 'version' => 1]);
socLeadsSync();
$leads = socLeads();
check('leads relus, champs normalisés', ($leads[0]['field_data']['FULL_NAME'] ?? '') === 'Marie Claes' && ($leads[0]['field_data']['nb_personnes'] ?? '') === '16 à 30');
check('lead stocké une fois (idempotent)', count($leads) === 1);
$appels = [];
$lf['status'] = 'Archivé';
socStateSave(['changes' => ['leadforms' => ['upsert' => [$lf]]]]);
check('archivage → status=ARCHIVED chez Meta', count(array_filter($appels, static fn ($a) => ($a[2]['status'] ?? '') === 'ARCHIVED')) === 2);

echo "Retour sur campagne\n";
socResultsSync();
$res = socResults();
$h = $res['bo-1']['4'] ?? [];
check('dépense Meta par boutique', (float) ($h['spend'] ?? 0) === 81.0, json_encode($h));
check('CA campagne lu dans la caisse (7 jours × 25 €)', (float) ($h['caCamp'] ?? 0) === 175.0, (string) ($h['caCamp'] ?? 'null'));
check('référence = moyenne des 6 semaines précédentes (7 × 10 €)', (float) ($h['caRef'] ?? 0) === 70.0, (string) ($h['caRef'] ?? 'null'));
check('Wavre sans vente : motif affiché', isset($res['bo-1']['5']['noSales']));
check('résultat en colonnes', Db::row("SELECT ca_campagne FROM social_resultat WHERE campagne_id = 'bo-1' AND shop_id = '4'")['ca_campagne'] === '175.00');

echo "Marque et audit de l'API\n";
socStateSave(['meta' => ['ids' => ['marque' => ['page_id' => '999', 'ad_account_id' => 'act_7777']]]]);
check('identifiants Meta de la marque enregistrés', socBrand()['ids']['page_id'] === '999');
check('compte publicitaire réseau = celui de la marque', socStateGet()['meta']['networkAccount'] === 'act_7777');
$aud = socAuditRun();
$st = [];
foreach (socAuditGet()['routes'] as $r) { $st[$r['chemin']] = $r['statut']; }
check('audit : routes GET seulement (POST ignorée)', !isset($st['/api/v1/shops/{id}/x']) && count($st) === 9, json_encode($st));
check('audit : 500 « validation » rejoué avec une période → fonctionne', ($st['/api/v1/consultant/shops/sales-kpis'] ?? '') === 'ok');
check('audit : /public/shops fonctionne sans compte', ($st['/api/v1/public/shops'] ?? '') === 'ok');
check('audit : paramètre boutique deviné', ($st['/api/v1/shops/{id}'] ?? '') === 'ok' && ($st['/api/v1/franchisee-shop/{id}/transactions/{date}'] ?? '') === 'ok');
check('audit : réalm consultant', ($st['/api/v1/consultant/shops'] ?? '') === 'ok');
check('audit : refus, erreur, non testée', ($st['/api/v1/products'] ?? '') === 'refuse' && ($st['/api/v1/broken'] ?? '') === 'erreur' && ($st['/api/v1/recipes/{param1}'] ?? '') === 'non_teste');
check('audit : résumé en base', ($aud['ok'] ?? 0) === 6 && socSetting('tfb_audit')['total'] === 9, json_encode($aud));

echo "Catalogue et ventes depuis l'API\n";
$cat = socApiCatalogue(true);
$ids = array_column($cat, 'id');
check('catalogue API : produits actifs hors B2B + gammes actives', $ids === ['g14', 'p1'], json_encode($ids));
check('catalogue API : prix et nom NL', ($cat[1]['price'] ?? '') === '4,20' && ($cat[1]['nameNl'] ?? '') === 'Krokante brioche');
check('catalogue API : gamme sans emoji', ($cat[0]['name'] ?? '') === 'Gamme Automne');
check('catalogue des écrans = celui de l’API', array_column(socStateGet()['catalogue'], 'id') === ['g14', 'p1']);
putenv('SOCIAL_TFB_VENTES=1');
[$v] = socVentesApi('5', ['Brioche croustillante'], date('Y-m-d', strtotime('-6 days')), date('Y-m-d'));
check('ventes API : CA campagne / référence (6 semaines)', ($v['caCamp'] ?? 0) == 210.0 && ($v['caRef'] ?? 0) == 126.0 && ($v['qCamp'] ?? 0) == 50.0, json_encode($v));
check('ventes API : tickets de la boutique', ($v['tCamp'] ?? 0) === 300 && ($v['tRef'] ?? 0) === 200);
socResultsSync('bo-1');
check('retour sur campagne : l’API prime sur la base', (socResults()['bo-1']['4']['source'] ?? '') === 'API TFBuddy', json_encode(socResults()['bo-1']['4'] ?? null));
putenv('SOCIAL_TFB_VENTES');

echo "Clés Meta : App ID et vérification du token\n";
socSettingPut('meta_app_id', '424242', false); socSettingPut('meta_app_secret', 'SECRET', true);
$info = Meta::verifierJeton();
check('debug_token : valide, expiration, permissions', ($info['valide'] ?? false) && ($info['expire'] ?? '') === '2027-01-01' && in_array('pages_manage_posts', $info['scopes'] ?? [], true), json_encode($info));
check('debug_token appelé avec le jeton d’application', ($appelsVers('#^debug_token$#')[0][2]['access_token'] ?? '') === '424242|SECRET');
check('état Meta : App ID et token exposés sans secret', Meta::statut()['appId'] === '424242' && !str_contains(json_encode(Meta::statut()), 'SECRET'));
$GLOBALS['SOC_BODY'] = ['base' => 'https://pirate.example.com/api/v1', 'adminPhone' => '0470'];
socRoute('POST', '/parametres/tfb');
check('adresse de l’API refusée hors tfbuddy.com (les mots de passe n’y partent pas)', Tfb::base() === 'https://atelierby.tfbuddy.com/api/v1', Tfb::base());
$GLOBALS['SOC_BODY'] = ['base' => 'https://demo.tfbuddy.com/api/v1'];
socRoute('POST', '/parametres/tfb');
check('adresse de l’API acceptée sur tfbuddy.com', Tfb::base() === 'https://demo.tfbuddy.com/api/v1');
socSettingPut('tfb_base', 'https://atelierby.tfbuddy.com/api/v1', false);
unset($GLOBALS['SOC_BODY']);

echo "Portefeuille Business Manager\n";
$pf = socPortefeuilleRafraichir();
$pb = array_column($pf['pages'], 'boutique', 'id');
check('portefeuille : business trouvé par le System User', $pf['business']['id'] === '900');
check('portefeuille : Pages rapprochées (ville → boutique, nom seul → marque)', ($pb['111'] ?? '') === '4' && ($pb['222'] ?? '') === '5' && ($pb['999'] ?? '') === 'marque', json_encode($pb));
check('portefeuille : lecture refusée signalée, le reste lu', count($pf['erreurs']) === 1 && count($pf['comptes']) === 3);
check('portefeuille : Instagram rattaché à sa Page', ($pf['instagram'][0]['boutique'] ?? '') === '4');
socShopPut('5', ['ids' => ['page_id' => '', 'ad_account_id' => '', 'instagram_user_id' => '', 'pixel_id' => '']]);
socShopPut('marque', ['ids' => ['page_id' => '', 'ad_account_id' => '', 'instagram_user_id' => '', 'pixel_id' => '']]);
socSettingPut('meta_compte_reseau', '', false);  // l'ancien compte réseau saisi à la main compterait comme rempli
$ap = socPortefeuilleAppliquer(false);
check('appliquer : champs vides remplis, compte inactif ignoré', socShop('5')['ids']['page_id'] === '222' && socShop('5')['ids']['ad_account_id'] === '' && socShop('5')['ids']['pixel_id'] === '880112', json_encode(socShop('5')['ids']));
check('appliquer : la marque reçoit sa Page et le compte réseau', socBrand()['ids']['page_id'] === '999' && socBrand()['ids']['ad_account_id'] === 'act_2000', json_encode(socBrand()['ids']));
check('appliquer : un champ déjà rempli n’est pas écrasé', socShop('4')['ids']['ad_account_id'] === 'act_1001');
socPortefeuilleAppliquer(true);
check('tout remplacer : écrase', socShop('4')['ids']['ad_account_id'] === 'act_2001');

echo "Identifiants Meta : mise à jour automatique\n";
socShopPut('5', ['ids' => ['page_id' => '', 'ad_account_id' => '', 'instagram_user_id' => '', 'pixel_id' => '']]);
$journalAvant = (int) Db::row("SELECT COUNT(*) n FROM social_activite WHERE detail LIKE 'Portefeuille Meta relu%'")['n'];
$r = socIdsAuto();
check('auto (cron) : identifiants vides remplis depuis le portefeuille', ($r['ok'] ?? false) && socShop('5')['ids']['page_id'] === '222' && socShop('5')['ids']['pixel_id'] === '880112'
    && socShop('4')['ids']['ad_account_id'] === 'act_2001', json_encode($r));
check('auto : pas relancé avant la fréquence choisie (1 h)', isset(socIdsAuto()['ignore']));
$r2 = socIdsAuto(true);
check('auto : rien de nouveau → journal silencieux', ($r2['ok'] ?? false) && $r2['appliques'] === []
    && (int) Db::row("SELECT COUNT(*) n FROM social_activite WHERE detail LIKE 'Portefeuille Meta relu%'")['n'] === $journalAvant);
socShopPut('5', ['ids' => array_merge(socShop('5')['ids'], ['page_id' => '777'])]);
socIdsAutoPut(['actif' => true, 'ecraser' => true, 'frequence' => 6]);
socIdsAuto(true);
check('auto « remplacer les saisies » : la valeur manuelle est remplacée par le portefeuille', socShop('5')['ids']['page_id'] === '222');
socIdsAutoPut(['actif' => false]);
check('auto désactivé : le cron ne fait rien', (socIdsAuto()['ignore'] ?? '') === 'désactivé');
socIdsAutoPut(['actif' => true, 'ecraser' => false, 'frequence' => 1]);
socShopPut('5', ['ids' => ['page_id' => '', 'ad_account_id' => '', 'instagram_user_id' => '', 'pixel_id' => '']]);
$GLOBALS['SOC_BODY'] = ['systemToken' => 'TOK-NOUVEAU'];
[$code, $b] = socRoute('POST', '/parametres/cles');
unset($GLOBALS['SOC_BODY']);
check('nouveau token enregistré : identifiants relus tout de suite', $code === 200 && ($b['ids']['ok'] ?? false) && socShop('5')['ids']['page_id'] === '222');
[$code, $b] = socRoute('GET', '/meta/ids/auto');
check('GET /meta/ids/auto : réglages et dernier passage', $code === 200 && $b['config']['frequence'] === 1 && !empty($b['dernier']['le']));

echo "Campagnes existantes chez Meta : récupération\n";
$modCid = (string) Db::row("SELECT campaign_id FROM social_meta_objet WHERE kind = 'ad' AND campaign_id IS NOT NULL ORDER BY id LIMIT 1")['campaign_id'];
$j = static fn (int $d) => date('Y-m-d', strtotime(($d >= 0 ? '+' : '') . $d . ' days')) . 'T08:00:00+0200';
$GLOBALS['META_EXISTANT'] = [
    'campaigns' => [
        ['id' => '7001', 'name' => 'Promo hiver Halle', 'objective' => 'OUTCOME_TRAFFIC', 'effective_status' => 'ACTIVE', 'daily_budget' => '1500', 'spend_cap' => '30000', 'start_time' => $j(-5)],
        ['id' => '7002', 'name' => 'Notoriété réseau', 'objective' => 'OUTCOME_AWARENESS', 'effective_status' => 'PAUSED', 'lifetime_budget' => '50000', 'start_time' => $j(-30), 'stop_time' => $j(10)],
        ['id' => '7003', 'name' => 'Brouillon', 'objective' => 'OUTCOME_SALES', 'effective_status' => 'PAUSED', 'start_time' => '1970-01-01T00:59:59+0100', 'created_time' => $j(-1)],
        ['id' => $modCid, 'name' => 'Créée par le module', 'objective' => 'OUTCOME_TRAFFIC', 'effective_status' => 'ACTIVE', 'start_time' => $j(-2)],
    ],
    'adsets' => [
        ['id' => '7101', 'campaign_id' => '7001', 'name' => 'Halle 8 km', 'effective_status' => 'ACTIVE', 'start_time' => $j(-5),
         'targeting' => ['geo_locations' => ['custom_locations' => [['latitude' => 50.7, 'longitude' => 4.2, 'radius' => 8, 'distance_unit' => 'kilometer']]]]],
        ['id' => '7102', 'campaign_id' => '7002', 'name' => 'Réseau', 'effective_status' => 'PAUSED', 'start_time' => $j(-30), 'end_time' => $j(10)],
    ],
    'ads' => [
        ['id' => '7201', 'campaign_id' => '7001', 'adset_id' => '7101', 'name' => 'Visuel A', 'effective_status' => 'ACTIVE', 'creative' => ['id' => '7301', 'actor_id' => '111', 'thumbnail_url' => 'https://cdn.fb/t7201.jpg']],
        ['id' => '7202', 'campaign_id' => '7002', 'adset_id' => '7102', 'name' => 'Halle', 'effective_status' => 'CAMPAIGN_PAUSED', 'creative' => ['id' => '7302', 'effective_object_story_id' => '111_55']],
        ['id' => '7203', 'campaign_id' => '7002', 'adset_id' => '7102', 'name' => 'Wavre', 'effective_status' => 'CAMPAIGN_PAUSED', 'creative' => ['id' => '7303', 'actor_id' => '222']],
    ],
    'insights' => [['campaign_id' => '7001', 'spend' => '42.10', 'impressions' => '5000', 'reach' => '3000', 'clicks' => '120', 'ctr' => '2.4', 'cpc' => '0.35', 'cpm' => '8.42',
        'frequency' => '1.66', 'actions' => [['action_type' => 'post_engagement', 'value' => '300']]]],
];
socShopPut('5', ['ids' => array_merge(socShop('5')['ids'], ['page_id' => '222'])]);
$appels = [];
[$code, $b] = socRoute('POST', '/meta/campagnes/rafraichir');
$m1 = socItem('ads', 'meta-7001'); $m2 = socItem('ads', 'meta-7002');
$m3 = socItem('ads', 'meta-7003');
check('récupération : 3 campagnes importées, celle du module ignorée', $code === 200 && $b['import']['importees'] === 3 && $m1 && $m2 && socItem('ads', 'meta-' . $modCid) === null, json_encode($b['import'] ?? null));
check('campagne Halle : boutique par la Page, budget quotidien, plafond, dépense, rayon', $m1['origin'] === 'meta' && $m1['shops'] === ['4'] && $m1['scope'] === 'boutique'
    && $m1['budgetType'] === 'quotidien' && $m1['budget'] == 15 && $m1['cap'] == 300 && $m1['spent'] == 42.1 && $m1['status'] === 'Active' && $m1['radius'] == 8 && $m1['end'] === null, json_encode($m1));
check('campagne Halle : statistiques et vignette', $m1['insights']['impressions'] === 5000 && $m1['insights']['ctr'] == 0.024 && $m1['insights']['engagement'] === 300 && $m1['thumb'] === 'https://cdn.fb/t7201.jpg'
    && str_contains($m1['lienMeta'], 'act=2001&selected_campaign_ids=7001'));
check('campagne réseau : deux boutiques (Page de la créa / du post), budget total, en pause, fin', $m2['scope'] === 'reseau' && count($m2['shops']) === 2 && $m2['budgetType'] === 'total'
    && $m2['budget'] == 500 && $m2['status'] === 'En pause' && $m2['end'] === date('Y-m-d', strtotime('+10 days')) && $m2['objective'] === 'Notoriété', json_encode($m2));
$objs = socMetaObjects('ad', 'meta-7002');
check('objets Meta : une ligne par pub, avec sa boutique (Retour sur campagne)', count($objs) === 2 && array_column($objs, 'shop_id') === ['4', '5'] && $objs[0]['campaign_id'] === '7002');
check('les pubs importées ne sont pas relues pub par pub', !array_filter($appels, static fn ($a) => $a[0] === 'GET' && in_array($a[1], ['7201', '7201/insights'], true)));
check('brouillon sans date (1970 chez Meta) : daté de sa création', $m3['start'] === date('Y-m-d', strtotime('-1 day')) && $m3['end'] === null && $m3['budget'] == 0, json_encode($m3));
check('compte rendu gardé et rendu dans l’état', (socStateGet()['metaImport']['importees'] ?? null) === 3);
// Devise et minimums des comptes (assistant « Nouvelle publicité ») : lus chez Meta, gardés 12 h, rendus dans l'état.
$minLus = static fn (array $ap) => count(array_filter($ap, static fn ($a) => str_contains((string) ($a[2]['fields'] ?? ''), 'min_campaign_group_spend_cap')));
check('comptes : la relecture des campagnes lit aussi devise et minimums', $minLus($appels) > 0 && (socSetting('meta_comptes_minimums')['comptes']['act_2000']['devise'] ?? '') === 'PLN');
$appels = [];
[$code, $b] = socRoute('GET', '/meta/comptes');
$cp = $b['comptes'] ?? [];
check('comptes : minimums en unités de la devise (500 PLN de plafond, 5 PLN par jour)', $code === 200 && ($cp['act_2000']['devise'] ?? '') === 'PLN' && ($cp['act_2000']['minCap'] ?? null) == 500
    && ($cp['act_2000']['minDaily'] ?? null) == 5 && ($cp['act_2001']['minCap'] ?? null) == 100, json_encode($cp));
check('comptes : gardés 12 h (pas de nouvel appel à Meta)', $minLus($appels) === 0);
$_GET['forcer'] = '1'; socRoute('GET', '/meta/comptes'); unset($_GET['forcer']);
check('comptes : relus à la demande', $minLus($appels) > 0);
check('comptes : rendus dans l’état', (socStateGet()['meta']['comptes']['act_2000']['minCap'] ?? null) == 500);
$appels = [];
$r = socStateSave(['changes' => ['ads' => ['upsert' => [array_merge($m1, ['status' => 'En pause', 'name' => 'Renommée', 'campaign' => 'bo-1', 'budget' => 999])]]]]);
$m1 = socItem('ads', 'meta-7001');
check('pause depuis l’écran : appliquée à la campagne Meta', $m1['status'] === 'En pause' && in_array(['POST', '7001', ['status' => 'PAUSED', 'access_token' => 'TOK-NOUVEAU']], $appels, true), json_encode($appels));
check('champs Meta gardés (nom, budget), rattachement à une campagne du module accepté', $m1['name'] === 'Promo hiver Halle' && $m1['budget'] == 15 && $m1['campaign'] === 'bo-1');
socStateSave(['changes' => ['ads' => ['upsert' => [array_merge($m1, ['cap' => 400])]]]]);
check('plafond relevé : spend_cap de la campagne Meta', in_array(['POST', '7001', ['spend_cap' => 40000, 'access_token' => 'TOK-NOUVEAU']], $appels, true));
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [array_merge(socItem('ads', 'meta-7001'), ['budgetDemande' => 25])]]]]);
check('budget demandé (campagne Meta avec budget) : daily_budget de la campagne', in_array(['POST', '7001', ['daily_budget' => 2500, 'access_token' => 'TOK-NOUVEAU']], $appels, true)
    && socItem('ads', 'meta-7001')['budget'] == 25, json_encode($appels));
$sauve = $GLOBALS['META_EXISTANT'];
unset($GLOBALS['META_EXISTANT']['campaigns'][0]['daily_budget']);
$GLOBALS['META_EXISTANT']['adsets'][0]['daily_budget'] = '1000';
$GLOBALS['META_EXISTANT']['adsets'][] = ['id' => '7104', 'campaign_id' => '7001', 'name' => 'Halle 5 km', 'effective_status' => 'ACTIVE', 'daily_budget' => '500'];
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [array_merge(socItem('ads', 'meta-7001'), ['budgetDemande' => 30])]]]]);
check('budget demandé (budget dans les ensembles) : réparti au prorata', in_array(['POST', '7101', ['daily_budget' => 2000, 'access_token' => 'TOK-NOUVEAU']], $appels, true)
    && in_array(['POST', '7104', ['daily_budget' => 1000, 'access_token' => 'TOK-NOUVEAU']], $appels, true) && socItem('ads', 'meta-7001')['budget'] == 30, json_encode($appels));
$GLOBALS['META_EXISTANT'] = $sauve;
array_pop($GLOBALS['META_EXISTANT']['campaigns']); array_pop($GLOBALS['META_EXISTANT']['campaigns']); array_pop($GLOBALS['META_EXISTANT']['campaigns']);
$r = socCampagnesMetaImporter(false);
$m1 = socItem('ads', 'meta-7001'); $m2 = socItem('ads', 'meta-7002');
check('rafraîchissement : mise à jour sans doublon, rattachement conservé', $r['majs'] === 1 && $r['importees'] === 0 && $m1['campaign'] === 'bo-1' && $m1['status'] === 'Active');
check('campagnes supprimées chez Meta : marquées arrêtées', $r['disparues'] === 2 && $m2['status'] === 'Arrêtée' && $m2['gone'] === true);

echo "Calendriers Meta : lecture en direct (rien en base)\n";
$duMod = (string) (Db::row("SELECT meta_id FROM social_publication WHERE canal = 'fb' AND meta_id LIKE '111\_%' ORDER BY id LIMIT 1")['meta_id'] ?? '111_0');
$demain = strtotime('+1 day 10:30');
$GLOBALS['META_PUBLIES'] = [
    ['id' => '111_9001', 'message' => "Nouvelle tarte aux pommes !\nDisponible dès ce matin.", 'created_time' => date('c', strtotime('-2 days 09:15')), 'permalink_url' => 'https://facebook.com/111/posts/9001',
     'full_picture' => 'https://cdn.fb/9001.jpg', 'status_type' => 'added_photos', 'attachments' => ['data' => [['media_type' => 'photo']]],
     'reactions' => ['summary' => ['total_count' => 12]], 'comments' => ['summary' => ['total_count' => 3]], 'shares' => ['count' => 2]],
    ['id' => $duMod, 'message' => 'Publié par le module', 'created_time' => date('c', strtotime('-1 day'))],
];
$GLOBALS['META_PROGRAMMES'] = [['id' => '111_9002', 'message' => 'Brunch de dimanche', 'scheduled_publish_time' => $demain, 'full_picture' => 'https://cdn.fb/9002.jpg'],
    ['id' => '111_9003', 'message' => 'Hors période', 'scheduled_publish_time' => strtotime('+40 days')]];
Meta::reset();
$_GET = ['du' => date('Y-m-d', strtotime('-7 days')), 'au' => date('Y-m-d', strtotime('+7 days'))];
[$code, $b] = socRoute('GET', '/meta/calendriers');
$_GET = [];
$cp = array_column($b['posts'], null, 'id');
$f1 = $cp['mfb-111_9001'] ?? null; $f2 = $cp['mfb-111_9002'] ?? null; $i1 = $cp['mig-m2'] ?? null;
check('calendriers : publications Facebook, post programmé et Instagram lus en direct', $code === 200 && $f1 && $f2 && $i1 && isset($cp['mig-m1']), json_encode(array_keys($cp)));
check('publication Facebook : date, heure, titre, visuel, boutique par la Page, statistiques', $f1['status'] === 'Publié' && $f1['date'] === date('Y-m-d', strtotime('-2 days')) && $f1['time'] === '09:15'
    && $f1['title'] === 'Nouvelle tarte aux pommes !' && $f1['img'] === 'https://cdn.fb/9001.jpg' && $f1['shops'] === ['4'] && $f1['stats']['reactions'] === 12 && $f1['account'] === 'fb:111' && $f1['type'] === 'Photo', json_encode($f1));
check('post programmé dans Meta Business Suite : « Programmé » à sa date ; hors période écarté', $f2['status'] === 'Programmé' && $f2['date'] === date('Y-m-d', $demain) && $f2['time'] === '10:30' && !isset($cp['mfb-111_9003']));
check('Instagram : média, compte @, boutique', $i1['channel'] === 'Instagram' && $i1['account'] === 'ig:17841' && $i1['accountName'] === '@latelierby.halle' && $i1['shops'] === ['4'] && $i1['type'] === 'Photo');
check('publication faite par le module : pas montrée deux fois', !isset($cp['mfb-' . $duMod]));
$cpt = array_column($b['comptes'], null, 'key');
check('comptes et photos de profil (filtres du calendrier)', ($cpt['fb:111']['picture'] ?? '') === 'https://cdn.fb/p111.jpg' && ($cpt['ig:17841']['picture'] ?? '') === 'https://cdn.ig/halle.jpg'
    && $cpt['fb:111']['publies'] === 1 && $cpt['fb:111']['programmes'] === 1, json_encode($cpt));
check('published_posts refusé : repli sur /posts (publications de la Page lues quand même)', isset($cp['mfb-222_7001']) && ($cpt['fb:222']['erreur'] ?? null) === null && !str_contains(json_encode($b), 'PT111'), json_encode($cpt['fb:222'] ?? null));
$GLOBALS['POSTS_222_REFUS'] = true;
$_GET = ['du' => date('Y-m-d', strtotime('-7 days')), 'au' => date('Y-m-d', strtotime('+7 days'))];
[$code, $b2] = socRoute('GET', '/meta/calendriers');
$_GET = []; unset($GLOBALS['POSTS_222_REFUS']);
$cpt2 = array_column($b2['comptes'], null, 'key');
check('tous les accès refusés : Page signalée avec le motif, les autres comptes passent', str_contains((string) ($cpt2['fb:222']['erreur'] ?? ''), 'pages_read_engagement') && ($cpt2['fb:111']['publies'] ?? 0) === 1, json_encode([$cpt2['fb:222'] ?? null, $cpt2['fb:111'] ?? null]));
check('rien n’est copié en base', (int) Db::row("SELECT COUNT(*) n FROM social_content WHERE id LIKE 'mfb-%' OR id LIKE 'mig-%'")['n'] === 0);
$r = socStateSave(['changes' => ['posts' => ['upsert' => [$f2]]]]);
check('publication Meta renvoyée par l’écran : refusée, ni enregistrée ni mise en file', ($r['errors'][0]['error'] ?? '') === 'publication Meta : lecture seule' && socItem('posts', 'mfb-111_9002') === null
    && (int) Db::row("SELECT COUNT(*) n FROM social_publication WHERE content_id LIKE 'mfb-%'")['n'] === 0);
$_GET = ['du' => '2026-01-01', 'au' => '2026-12-31'];
$c400 = (static function () { try { return socRoute('GET', '/meta/calendriers')[0]; } catch (HttpError $e) { return $e->status; } })();
$_GET = [];
check('période de plus de 100 jours refusée', $c400 === 400);

echo "Espace franchisé : le calendrier Meta de sa boutique (en direct)\n";
sfSessionOuvrir(['4'], 'Test calendrier', 'code');
$per = ['du' => date('Y-m-d', strtotime('-7 days')), 'au' => date('Y-m-d', strtotime('+7 days'))];
[$code, $fc] = $fr('GET', '/franchise/calendrier', [], $per);
$fcp = array_column($fc['posts'] ?? [], null, 'id');
check('franchisé : les publications de SA Page et de son Instagram, lues en direct (programmées comprises)', $code === 200 && isset($fcp['mfb-111_9001'], $fcp['mfb-111_9002'], $fcp['mig-m2'])
    && ($fcp['mfb-111_9002']['status'] ?? '') === 'Programmé' && ($fcp['mfb-111_9001']['stats']['reactions'] ?? null) === 12, json_encode(array_keys($fcp)));
check('franchisé : rien des autres boutiques (ni leurs publications, ni leurs comptes, ni les tokens)', !isset($fcp['mfb-222_7001']) && array_column($fc['comptes'], 'key') === ['fb:111', 'ig:17841']
    && !str_contains(json_encode($fc), 'PT111') && !str_contains(json_encode($fc), 'Wavre'), json_encode($fc['comptes']));
check('franchisé : les champs utiles seulement (ni boutiques, ni marque)', !array_key_exists('shops', $fcp['mfb-111_9001']) && !array_key_exists('marque', $fcp['mfb-111_9001']) && $fcp['mfb-111_9001']['permalink'] === 'https://facebook.com/111/posts/9001');
[$code] = $fr('GET', '/franchise/calendrier', [], $per + ['shop' => '5']);
check('franchisé : le calendrier d’une autre boutique est refusé', $code === 403);
[$code, $fs] = $fr('GET', '/franchise/calendrier/stats', [], ['id' => 'mfb-111_9001', 'compte' => 'fb:111']);
[$c2] = $fr('GET', '/franchise/calendrier/stats', [], ['id' => 'mfb-222_7001', 'compte' => 'fb:222']);
[$c3] = $fr('GET', '/franchise/calendrier/stats', [], ['id' => 'mfb-222_7001', 'compte' => 'fb:111']);
check('franchisé : les vues d’une publication de sa Page ; celles d’une autre Page refusées', $code === 200 && $fs['vues'] === 1234 && $c2 === 404 && $c3 === 404, json_encode([$code, $fs['vues'] ?? null, $c2, $c3]));
[$code] = $fr('GET', '/franchise/calendrier', [], ['du' => '2026-01-01', 'au' => '2026-12-31']);
check('franchisé : période trop longue refusée', $code === 400);
unset($_COOKIE[SF_COOKIE]);

echo "Liens du webshop (boutique, catégorie, sous-catégorie, produit)\n";
// Le plan du webshop (catégorie → groupe, produit → catégorie) tel que le catalogue lu le laisse en cache.
socSettingPut('tfb_cache_nav', ['v' => 1, 'shops' => ['4' => ['le' => time(), 'data' => [
    'categories' => [['id' => 31000, 'groupe' => 28], ['id' => 32000, 'groupe' => 28], ['id' => 45000, 'groupe' => null]],
    'produits' => [['id' => 6700092, 'cat' => 32000], ['id' => 3200006, 'cat' => 31000]]]]]], false);
$W = 'https://webshop.atelierby.be/';
$prodWs = ['id' => 'p3200006', 'apiId' => 3200006, 'kind' => 'produit', 'name' => 'Amandes', 'price' => '4,90'];
check('{webshop} et {webshop_produit} : la boutique, puis la sous-catégorie du produit, ouverte sur lui', socFill('{webshop} | {webshop_produit}', ['id' => '4', 'name' => 'Halle'], $prodWs, 'fr')
    === $W . '?shop=4 | ' . $W . '?shop=4&category=group:28&sub=category:31000&product=3200006&open=product');
check('« :liste » : le produit dans sa sous-catégorie, sans ouvrir sa fiche (pas de &open=product)',
    socFill('{webshop_produit:3200006:liste} | {webshop_produit:liste}', ['id' => '4', 'name' => 'Halle'], $prodWs, 'fr')
    === $W . '?shop=4&category=group:28&sub=category:31000&product=3200006 | ' . $W . '?shop=4&category=group:28&sub=category:31000&product=3200006');
check('sous-catégorie au format du webshop (?shop=4&category=group:28&sub=category:31000)', socWebshopUrl('4', null, 31000) === $W . '?shop=4&category=group:28&sub=category:31000');
check('catégorie sans groupe : category=category:… ; catégorie (groupe) seule : category=group:…',
    socWebshopUrl('4', null, 45000) === $W . '?shop=4&category=category:45000' && socWebshopUrl('4', null, 0, 28) === $W . '?shop=4&category=group:28');
check('sans produit (ou gamme) : le webshop de la boutique ; boutique sans n° de caisse : l’accueil du webshop',
    socFill('{webshop_produit}', ['id' => '5', 'name' => 'Wavre'], null, 'fr') === $W . '?shop=5'
    && socWebshopUrl('5', ['apiId' => 7, 'kind' => 'gamme']) === $W . '?shop=5' && socWebshopUrl('ceo:9') === $W);
check('catégorie du produit, produit, sous-catégorie ou catégorie précis', socFill('{webshop_categorie} | {webshop_produit:6700092} | {webshop_categorie:32000} | {webshop_groupe:28}', ['id' => '2', 'name' => 'Corbais'], $prodWs + ['categoryId' => 31000], 'fr')
    === $W . '?shop=2&category=group:28&sub=category:31000 | ' . $W . '?shop=2&category=group:28&sub=category:32000&product=6700092&open=product | '
    . $W . '?shop=2&category=group:28&sub=category:32000 | ' . $W . '?shop=2&category=group:28');
check('boutique hors caisse (magasin test) : la page du webshop, sans boutique', socWebshopUrl('pg12345678901234', null, 31000) === $W . '?category=group:28&sub=category:31000');
check('boutique sans horaires (magasin test) : la phrase des horaires disparaît, pas « Ouvert . »',
    socFill("Brownies : 3,50 € chez L’Atelier By {ville}. Ouvert {horaires}.\nCommandez : {webshop}", ['id' => 'pg1', 'name' => 'Gembloux', 'hours' => ''], null, 'fr')
    === "Brownies : 3,50 € chez L’Atelier By Gembloux.\nCommandez : " . $W
    && socFill('Ouvert {horaires}.', ['id' => '4', 'name' => 'Halle', 'hours' => '7h–18h30'], null, 'fr') === 'Ouvert 7h–18h30.');
$appels = [];
socPublishFb(['id' => 'pw1', 'text' => 'Commandez', 'link' => '{webshop}', 'img' => null], socShop('4'), 'Commandez');
$feed = array_values(array_filter($appels, static fn ($a) => $a[0] === 'POST' && $a[1] === '111/feed'));
check('publication : le lien {webshop} devient celui de la boutique visée', ($feed[0][2]['link'] ?? '') === 'https://webshop.atelierby.be/?shop=4', json_encode($feed[0][2] ?? null));
$halle = ['id' => '4', 'name' => 'Halle', 'langs' => ['fr'], 'hours' => '7h–18h30'];
$pl = ['id' => 'pl1', 'text' => 'Cookie chez {ville}.' . "\n" . '{lien}', 'link' => '{webshop_categorie:31000}', 'media' => [['src' => 'uploads/x.jpg', 'kind' => 'img']]];
check('{lien} : le texte du lien puis l’adresse résolue, à sa place (Facebook)', socMessage($pl, $halle, 'fb') === "Cookie chez Halle.\nCommandez ici 👉 " . $W . '?shop=4&category=group:28&sub=category:31000');
check('Instagram : {lien} disparaît (lien de légende non cliquable)', socMessage($pl, $halle, 'ig') === 'Cookie chez Halle.');
$pl2 = ['text' => 'Cookie chez {ville}.', 'link' => '{webshop}', 'media' => [['src' => 'uploads/x.jpg', 'kind' => 'img']], 'linkText' => ['fr' => 'À commander ici :']];
check('sans {lien}, avec photo : ajouté à la fin, avec le texte du post', socMessage($pl2, $halle, 'fb') === "Cookie chez Halle.\n\nÀ commander ici : " . $W . '?shop=4');
socSettingPut('lien_texte', ['fr' => 'Réservez ici →', 'nl' => 'Reserveer hier →'], false);
check('texte par défaut réglé (Paramètres) ; sans média, Facebook fait la carte du lien : rien d’ajouté', socMessage(['text' => 'A {lien}', 'link' => '{webshop}'], $halle, 'fb') === 'A Réservez ici → ' . $W . '?shop=4'
    && socMessage(['text' => 'A', 'link' => '{webshop}'], $halle, 'fb') === 'A');
socSettingPut('lien_texte', null, false);
check('sans lien : {lien} disparaît', socMessage(['text' => "A\n{lien}\nB", 'link' => ''], $halle, 'fb') === "A\n\nB");

echo "Rattacher posts et publicités aux opérations du Marketing\n";
$ratt = static function (array $b): array { $GLOBALS['SOC_BODY'] = $b; try { return socRoute('POST', '/operations/rattacher'); } catch (HttpError $e) { return [$e->status, ['error' => $e->getMessage()]]; } finally { unset($GLOBALS['SOC_BODY']); } };
[$code] = $ratt(['kind' => 'post', 'id' => 'mfb-111_9001', 'operation' => 'bo-1']);
$_GET = ['du' => date('Y-m-d', strtotime('-7 days')), 'au' => date('Y-m-d', strtotime('+7 days'))];
[, $b3] = socRoute('GET', '/meta/calendriers'); $_GET = [];
$cp3 = array_column($b3['posts'], null, 'id');
check('publication Facebook lue chez Meta rattachée : le lien seul en base, l’opération revient avec la publication', $code === 200
    && Db::row("SELECT operation_id FROM social_rattachement WHERE objet = 'mfb-111_9001'")['operation_id'] === 'bo-1' && ($cp3['mfb-111_9001']['campaign'] ?? null) === 'bo-1' && array_key_exists('campaign', $cp3['mig-m2'] ?? []) && $cp3['mig-m2']['campaign'] === null, json_encode([$code, $cp3['mfb-111_9001']['campaign'] ?? '?']));
[$code] = $ratt(['kind' => 'post', 'id' => 'mfb-111_9001', 'operation' => null]);
check('détacher une publication Meta', $code === 200 && Db::row("SELECT COUNT(*) n FROM social_rattachement WHERE objet = 'mfb-111_9001'")['n'] == 0);
[$code] = $ratt(['kind' => 'post', 'id' => 'p1', 'operation' => 'bo-1']);
check('post du module rattaché (champ campaign)', $code === 200 && (socItem('posts', 'p1')['campaign'] ?? null) === 'bo-1');
[$code] = $ratt(['kind' => 'ad', 'id' => 'a1', 'operation' => null]);
[$code2] = $ratt(['kind' => 'ad', 'id' => 'a1', 'operation' => 'bo-1']);
check('publicité détachée puis rattachée', $code === 200 && $code2 === 200 && (socItem('ads', 'a1')['campaign'] ?? null) === 'bo-1');
[$c1] = $ratt(['kind' => 'post', 'id' => 'mfb-111_9001', 'operation' => 'bo-999']);
[$c2] = $ratt(['kind' => 'ad', 'id' => 'inconnue', 'operation' => 'bo-1']);
[$c3] = $ratt(['kind' => 'post', 'id' => '../x', 'operation' => 'bo-1']);
check('opération inconnue du Marketing, objet introuvable ou invalide : refusé', $c1 === 404 && $c2 === 404 && $c3 === 400);

echo "Calendrier : chiffres d'une publication (vues), lus à la demande\n";
$stat = static function (string $id, string $compte): array { $_GET = ['id' => $id, 'compte' => $compte];
    try { return socRoute('GET', '/meta/post-stats'); } catch (HttpError $e) { return [$e->status, ['error' => $e->getMessage()]]; } finally { $_GET = []; } };
$appels = [];
[$code, $s1] = $stat('mfb-111_9001', 'fb:111');
check('Facebook : vues et personnes (noms 2025), réactions par les insights, métrique retirée ignorée', $code === 200 && $s1['vues'] === 1234 && $s1['personnes'] === 980 && $s1['reactions'] === 35
    && $s1['clics'] === null && $s1['motif'] === null, json_encode($s1));
check('Facebook : lu en direct avec le token de la Page, rien en base', (bool) array_filter($appels, static fn ($a) => $a[1] === '111_9001/insights' && ($a[2]['access_token'] ?? '') === 'PT111')
    && (int) Db::row("SELECT COUNT(*) n FROM social_content WHERE id LIKE 'mfb-%'")['n'] === 0);
[$code, $s2] = $stat('mig-m2', 'ig:17841');
check('Instagram : vues, couverture, enregistrements, partages, j’aime', $code === 200 && $s2['vues'] === 500 && $s2['personnes'] === 400 && $s2['enregistrements'] === 7
    && $s2['partages'] === 3 && $s2['reactions'] === 41 && $s2['commentaires'] === 6 && $s2['motif'] === null, json_encode($s2));
[$code, $s3] = $stat('mfb-222_7001', 'fb:222');
check('permission read_insights manquante : motif clair, pas d’erreur', $code === 200 && $s3['vues'] === null && str_contains((string) $s3['motif'], 'read_insights'), json_encode($s3));
[$c4] = $stat('mfb-222_7001', 'fb:111');
[$c5] = $stat('mfb-../me', 'fb:111');
check('publication d’un autre compte ou identifiant invalide : refusé', $c4 === 404 && $c5 === 400);
$GLOBALS['INSIGHTS_VIDES'] = true; $appels = [];
[$code, $s6] = $stat('mfb-111_9001', 'fb:111');
check('vues acceptées mais vides : un seul appel d’insights, pas une dizaine', count(array_filter($appels, static fn ($a) => $a[1] === '111_9001/insights')) === 1);
unset($GLOBALS['INSIGHTS_VIDES']);
check('insights vides (token sans read_insights) : motif clair plutôt que des tirets muets', $code === 200 && $s6['vues'] === null && str_contains((string) $s6['motif'], 'read_insights'), json_encode($s6));

echo "Suivi des boutiques : abonnés face au budget (en direct)\n";
$GLOBALS['META_DEPENSES'] = [['ad_id' => '7201', 'date_start' => date('Y-m-d', strtotime('-2 days')), 'spend' => '10.00'],
    ['ad_id' => '7203', 'date_start' => date('Y-m-d', strtotime('-1 day')), 'spend' => '5.50'], ['ad_id' => 'X999', 'date_start' => date('Y-m-d', strtotime('-1 day')), 'spend' => '3']];
$_GET = ['jours' => '7'];
[$code, $b] = socRoute('GET', '/suivi/abonnes');
$_GET = [];
$L = array_column($b['lignes'], null, 'page');
$h = $L['111'] ?? null; $w = $L['222'] ?? null;
check('suivi : une ligne par Page, période de 7 jours jusqu’à hier', $code === 200 && $h && $w && $b['jours'] === 7 && $b['au'] === date('Y-m-d', strtotime('-1 day')), json_encode($b));
check('Page : abonnés actuels, gagnés, perdus, net (insights jour par jour)', $h['facebook']['followers'] === 1500 && $h['facebook']['gagnes'] === 8 && $h['facebook']['perdus'] === 1 && $h['facebook']['net'] === 7
    && count($h['facebook']['serie']) === 2, json_encode($h['facebook']));
check('Instagram de la Page : abonnés actuels et nouveaux abonnés', $h['instagram']['followers'] === 2300 && $h['instagram']['gagnes'] === 4);
check('budget rapporté à la Page de chaque pub, jour par jour ; coût par abonné', $h['depense'] == 10 && $w['depense'] == 5.5 && $b['nonAttribue'] == 3 && $h['abonnesNet'] === 11 && $h['coutParAbonne'] == 0.91
    && ($h['depenseJours'][date('Y-m-d', strtotime('-2 days'))] ?? null) == 10, json_encode([$h['depense'], $w['depense'], $b['nonAttribue'], $h['abonnesNet'], $h['coutParAbonne']]));
check('permission d’insights manquante : motif clair, le total et le budget restent', $w['facebook']['followers'] === 800 && str_contains((string) $w['facebook']['motif'], 'read_insights') && $w['abonnesNet'] === null);
check('suivi : rien n’est écrit en base', (int) Db::row("SELECT COUNT(*) n FROM social_parametre WHERE cle LIKE '%suivi%'")['n'] === 0);

echo "Espace franchisé : suivi de sa boutique\n";
unset($GLOBALS['SOCIAL_HOTE']);
sfSessionOuvrir(['4'], 'Test suivi', 'code');
[$code, $b] = $fr('GET', '/franchise/suivi', [], ['jours' => '7']);
check('franchisé : le suivi de SA Page seulement (ni les autres, ni la dépense non attribuée)', $code === 200 && count($b['lignes']) === 1 && $b['lignes'][0]['page'] === '111'
    && $b['lignes'][0]['depense'] == 10 && !isset($b['nonAttribue']), json_encode($b));
[$code] = $fr('GET', '/franchise/suivi', [], ['shop' => '5', 'jours' => '7']);
check('franchisé : le suivi d’une autre boutique est refusé', $code === 403);
sfReglesPut(array_merge(sfRegles(), ['voirSuivi' => false]));
[$code] = $fr('GET', '/franchise/suivi', [], ['jours' => '7']);
check('suivi non partagé par le réseau : refusé', $code === 403);
sfReglesPut(array_merge(sfRegles(), ['voirSuivi' => true]));
$b = sfSuivi('999', 7);
check('boutique sans Page reliée : message, aucune donnée', $b['lignes'] === [] && str_contains((string) $b['message'], 'Page Facebook'));

echo "Espace franchisé : analyse des vues et des abonnés\n";
$appels = [];
[$code, $va] = $fr('GET', '/franchise/vues', [], ['jours' => '7']);
$vc = array_column($va['comptes'] ?? [], null, 'key');
$vf = $vc['fb:111'] ?? null; $vi = $vc['ig:17841'] ?? null;
check('vues : SA Page et son Instagram, 7 jours jusqu’à hier', $code === 200 && $vf && $vi && count($vc) === 2 && $va['jours'] === 7 && $va['au'] === date('Y-m-d', strtotime('-1 day'))
    && $va['du'] === date('Y-m-d', strtotime('-7 days')), json_encode([$code, array_keys($vc), $va['du'] ?? null]));
check('vues de la Page jour par jour (page_media_view), total de la période', $vf['vues']['metrique'] === 'vues' && $vf['vues']['total'] === 550 && count($vf['vues']['serie']) === 3
    && ($vf['vues']['serie'][date('Y-m-d', strtotime('-2 days'))] ?? null) === 340 && $vf['vues']['motif'] === null, json_encode($vf['vues']));
check('Instagram : vues de la période et comptes touchés jour par jour', $vi['vues']['total'] === 777 && $vi['vues']['metrique'] === 'personnes' && count($vi['vues']['serie']) === 2, json_encode($vi['vues']));
check('abonnés : total actuel, gagnés et perdus par jour (Page) ; nouveaux abonnés (Instagram)', $vf['abonnes']['followers'] === 1500 && $vf['abonnes']['gagnes'] === 8 && $vf['abonnes']['perdus'] === 1
    && count($vf['abonnes']['serie']) === 2 && $vi['abonnes']['followers'] === 2300 && $vi['abonnes']['gagnes'] === 4 && $vi['abonnes']['perdus'] === null, json_encode([$vf['abonnes'], $vi['abonnes']]));
$vp = array_column($va['publications'], 'vues', 'id');
check('vues de chaque publication de la période, la plus vue d’abord (celles du module comprises)', array_slice(array_values($vp), 0, 4) === [1234, 500, 200, 50] && array_key_first($vp) === 'mfb-111_9001'
    && ($va['publications'][0]['accountName'] ?? '') === "L'Atelier By Halle" && ($va['publications'][1]['personnes'] ?? null) === 400, json_encode($va['publications']));
check('vues des publications lues avec la liste : un appel par compte, pas un par publication',
    !array_filter($appels, static fn ($a) => (bool) preg_match('#^(111_\d+|m\d)/insights$#', $a[1])), json_encode(array_column($appels, 1)));
check('analyse : rien des autres boutiques, ni token, ni dépense', !str_contains(json_encode($va), 'PT111') && !str_contains(json_encode($va), 'Wavre') && !str_contains(json_encode($va), 'depense'));
[$code] = $fr('GET', '/franchise/vues', [], ['shop' => '5', 'jours' => '7']);
check('analyse d’une autre boutique : refusée', $code === 403);
$GLOBALS['VUES_RETIREES'] = true; $GLOBALS['GROUPE_REFUS'] = true;
[$code, $va2] = $fr('GET', '/franchise/vues', [], ['jours' => '7']);
unset($GLOBALS['VUES_RETIREES'], $GLOBALS['GROUPE_REFUS']);
$vf2 = array_column($va2['comptes'], null, 'key')['fb:111'];
check('vues retirées par Meta : les visites de la Page à la place, dites comme telles', $vf2['vues']['metrique'] === 'visites' && $vf2['vues']['total'] === 13, json_encode($vf2['vues']));
check('lecture groupée refusée : vues relues publication par publication', (array_column($va2['publications'], 'vues', 'id')['mfb-111_9001'] ?? null) === 1234, json_encode($va2['publications']));
[$code, $va3] = $fr('GET', '/franchise/vues', [], ['jours' => '13']);
check('période inconnue : 28 jours', $code === 200 && $va3['jours'] === 28);
$w5 = sfVues('5', 7);
check('permission manquante (Wavre) : motifs lisibles, sans détail technique ni token', !str_contains(json_encode($w5), 'read_insights') && !str_contains(json_encode($w5), 'PT222'), json_encode($w5));
sfReglesPut(array_merge(sfRegles(), ['voirAnalyse' => false]));
[$code] = $fr('GET', '/franchise/vues', [], ['jours' => '7']);
check('analyse non partagée par le réseau : refusée ; la règle revient dans l’état du franchisé', $code === 403 && sfRegles()['voirAnalyse'] === false);
sfReglesPut(array_merge(sfRegles(), ['voirAnalyse' => true]));
check('analyse : rien n’est écrit en base', (int) Db::row("SELECT COUNT(*) n FROM social_parametre WHERE cle LIKE '%vues%'")['n'] === 0);

echo "Grille Instagram : abonnés jour par jour (gagnés, perdus) et comptes touchés\n";
$appels = [];
[$code, $ia] = $fr('GET', '/franchise/instagram/abonnes', [], []);
$attendu = []; for ($d = date('Y-m-d', strtotime('-30 days')); $d <= date('Y-m-d', strtotime('-1 day')); $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $jj = (int) date('j', strtotime($d)); $attendu[$d] = [2 + $jj % 3, $jj % 2]; }
check('grille : 30 jours jusqu’à hier, nouveaux abonnés et désabonnements de chaque jour', $code === 200 && $ia['jours'] === 30 && count($ia['serie']) === 30 && $ia['followers'] === 2300
    && $ia['gagnes'] === array_sum(array_column($attendu, 0)) && $ia['perdus'] === array_sum(array_column($attendu, 1)) && $ia['motif'] === null
    && ($ia['serie'][date('Y-m-d', strtotime('-1 day'))] ?? null) === ['gagnes' => $attendu[date('Y-m-d', strtotime('-1 day'))][0], 'perdus' => $attendu[date('Y-m-d', strtotime('-1 day'))][1]], json_encode([$code, $ia['gagnes'] ?? null, $ia['perdus'] ?? null, $ia['motif'] ?? null]));
$lots = array_values(array_filter($appels, static fn ($a) => $a[0] === 'POST' && $a[1] === ''));
check('désabonnements : les 30 jours en un seul appel (lot Graph), avec le token de la Page', count($lots) === 1 && count(json_decode((string) $lots[0][2]['batch'], true)) === 30 && ($lots[0][2]['access_token'] ?? '') === 'PT111');
check('comptes touchés jour par jour', count($ia['reach'] ?? []) === 2 && !str_contains(json_encode($ia), 'PT111'));
$GLOBALS['BATCH_REFUS'] = true;
[$code, $ib] = $fr('GET', '/franchise/instagram/abonnes', [], []);
unset($GLOBALS['BATCH_REFUS']);
check('désabonnements refusés par Meta : les nouveaux abonnés seuls, motif lisible sans détail technique', $code === 200 && $ib['perdus'] === null && $ib['gagnes'] === 4
    && str_contains((string) $ib['motif'], 'désabonnements') && !str_contains((string) $ib['motif'], '#100'), json_encode([$ib['gagnes'] ?? null, $ib['motif'] ?? null]));
[$code] = $fr('GET', '/franchise/instagram/abonnes', [], ['shop' => '5']);
check('abonnés de la grille d’une autre boutique : refusés', $code === 403);
sfReglesPut(array_merge(sfRegles(), ['voirAnalyse' => false]));
[$code] = $fr('GET', '/franchise/instagram/abonnes', [], []);
sfReglesPut(array_merge(sfRegles(), ['voirAnalyse' => true]));
check('« voir les vues et les abonnés » fermé : refusé', $code === 403);
$_GET = ['shop' => '4']; [$code, $ic] = socRoute('GET', '/instagram/abonnes'); $_GET = ['shop' => '999'];
$c404 = (static function () { try { return socRoute('GET', '/instagram/abonnes')[0]; } catch (HttpError $e) { return $e->status; } })(); $_GET = [];
check('franchiseur (SA Grille) : les abonnés du compte de la boutique ; boutique inconnue refusée', $code === 200 && count($ic['serie']) === 30 && $ic['perdus'] > 0 && $c404 === 404);
unset($_COOKIE[SF_COOKIE]);

echo "Instagram unique pour tout le réseau\n";
$marqueIds = socBrand()['ids'];
socShopPut('marque', ['ids' => array_merge($marqueIds, ['instagram_user_id' => '17841'])]);
socSettingPut('instagram_unique', true, false);
check('pub : boutique sans Instagram, Instagram unique au réseau → le compte du réseau', socIgPub(['ids' => ['instagram_user_id' => '']]) === '17841'
    && socIgPub(['ids' => ['instagram_user_id' => '555']]) === '555');
$restreinte = [];
$pu = ['id' => 'pu1', 'date' => date('Y-m-d', strtotime('+1 day')), 'time' => '09:00', 'type' => 'Produit', 'title' => 'Réseau', 'img' => 'uploads/x.png', 'media' => [['src' => 'uploads/x.png', 'kind' => 'img']],
    'shops' => ['4', '5'], 'ch' => ['4' => ['fb' => true, 'ig' => true], '5' => ['fb' => true, 'ig' => true]], 'fb' => true, 'ig' => true, 'status' => 'Programmé', 'text' => 'Bon appétit à {ville} !', 'spread' => 0];
socStateSave(['changes' => ['posts' => ['upsert' => [$pu]]]]);
$rows = Db::rows("SELECT shop_id, canal FROM social_publication WHERE content_id = 'pu1' ORDER BY canal, shop_id");
check('file : Facebook par boutique, Instagram une seule fois (compte de la marque)', array_map(static fn ($r) => $r['canal'] . ':' . $r['shop_id'], $rows) === ['fb:4', 'fb:5', 'ig:marque'], json_encode($rows));
Db::exec("UPDATE social_publication SET prevue_le = NOW() - INTERVAL 1 MINUTE WHERE content_id = 'pu1'");
$appels = [];
socRunQueue('pu1');
$igPosts = array_values(array_filter($appels, static fn ($a) => $a[0] === 'POST' && $a[1] === '17841/media'));
check('Instagram : un seul post, légende avec les boutiques visées ({ville})', count($igPosts) === 1 && str_contains((string) ($igPosts[0][2]['caption'] ?? ''), 'Bon appétit à Halle et Wavre !')
    && ($igPosts[0][2]['access_token'] ?? '') === 'PT111', json_encode($igPosts));
check('post publié (2 Pages + Instagram du réseau)', socItem('posts', 'pu1')['status'] === 'Publié');
check('frise et grille : un seul compte, celui de la marque', socShopsIg($pu) === ['marque'] && socIgGrille('marque')['instagram'] === true);
check('état : mode Instagram unique signalé à l’écran', socStateGet()['instagramUnique'] === true);
sfSessionOuvrir(['4'], 'Test IG', 'code');
{
    [$code, $b] = $fr('POST', '/franchise/posts', ['template' => 'fp-produit', 'product' => 'p1', 'fb' => true, 'ig' => true, 'date' => date('Y-m-d', strtotime('+4 days')), 'time' => '10:30', 'visual' => $vis]);
    check('franchisé : Instagram du réseau réservé au franchiseur (le post part sur sa Page seulement)', $code === 200 && ($b['post']['ig'] ?? null) === false && ($b['post']['fb'] ?? null) === true, json_encode($b));
    [$code, $et] = $fr('GET', '/franchise/etat');
    check('franchisé : la règle Instagram lui est rendue fermée', $code === 200 && $et['rules']['ig'] === false && $et['instagramUnique'] === true);
    [$code, $vu] = $fr('GET', '/franchise/instagram');
    check('franchisé : vue en lecture sur l’Instagram du réseau (profil, publications, programmés)', $code === 200 && $vu['username'] === 'latelierby.halle' && count($vu['media']) === 2
        && $vu['followers'] === 2300, json_encode($vu));
    $progIds = array_column($vu['programmes'], 'id');
    check('franchisé : la grille du réseau, avec au-dessus ce qui y est programmé (ni dark post, ni passé)', $vu['reseau'] === true && in_array('pu1', $progIds, true) === false
        && array_filter($vu['programmes'], static fn ($p) => $p['status'] === 'Programmé') == array_values(array_filter($vu['programmes'], static fn ($p) => $p['status'] === 'Programmé')), json_encode($vu['programmes']));
    $pu2 = array_merge($pu, ['id' => 'pu2', 'date' => date('Y-m-d', strtotime('+3 days')), 'time' => '08:00', 'title' => 'Prochain du réseau']);
    socStateSave(['changes' => ['posts' => ['upsert' => [$pu2]]]]);
    [, $vu2] = $fr('GET', '/franchise/instagram');
    $dates = array_map(static fn ($p) => $p['date'] . $p['time'], $vu2['programmes']); $tri = $dates; rsort($tri);
    check('franchisé : un post du réseau programmé sur Instagram s’ajoute au-dessus de la grille, du plus récent au plus ancien', in_array('pu2', array_column($vu2['programmes'], 'id'), true)
        && $dates === $tri && !array_filter($vu2['programmes'], static fn ($p) => $p['own']), json_encode($vu2['programmes']));
    sfReglesPut(array_merge(sfRegles(), ['voirReseau' => false]));
    [, $vu3] = $fr('GET', '/franchise/instagram');
    [, $fc3] = $fr('GET', '/franchise/calendrier', [], ['du' => date('Y-m-d', strtotime('-7 days')), 'au' => date('Y-m-d', strtotime('+7 days'))]);
    check('« voir les posts du réseau » fermé : ni les programmés du réseau, ni l’Instagram du réseau au calendrier', !in_array('pu2', array_column($vu3['programmes'], 'id'), true)
        && !in_array('ig:17841', array_column($fc3['comptes'] ?? [], 'key'), true), json_encode([$vu3['programmes'], $fc3['comptes'] ?? null]));
    sfReglesPut(array_merge(sfRegles(), ['voirReseau' => true]));
    [, $fc4] = $fr('GET', '/franchise/calendrier', [], ['du' => date('Y-m-d', strtotime('-7 days')), 'au' => date('Y-m-d', strtotime('+7 days'))]);
    check('Instagram unique : au calendrier de la boutique, sa Page et l’Instagram du réseau (signalé)', array_column($fc4['comptes'] ?? [], 'reseau', 'key') === ['fb:111' => false, 'ig:17841' => true], json_encode($fc4['comptes'] ?? null));
    socStateSave(['changes' => ['posts' => ['delete' => ['pu2']]]]);
}
unset($_COOKIE[SF_COOKIE]);
socSettingPut('instagram_unique', null, false);
socShopPut('marque', ['ids' => $marqueIds]);
check('sans réglage : mode automatique (une boutique a son propre Instagram → non)', socIgUnique() === false);

echo "Modèle franchisé créé depuis un post du réseau (visuel fixe)\n";
$ftFixe = ['id' => 'ftfixe', 'order' => 9, 'name' => 'Gamme automne', 'description' => 'Post du réseau', 'active' => true, 'type' => 'Gamme saisonnière', 'fb' => true, 'ig' => false,
    'validation' => false, 'title' => 'Gamme automne', 'text' => "La gamme automne est arrivée à {ville} !\n\n{libre}", 'textNl' => '', 'product' => 'aucun', 'promo' => ['kind' => 'aucune'],
    'period' => false, 'free' => 90, 'photo' => 'aucune', 'visual' => ['style' => 'fixe', 'src' => $img, 'media' => [['src' => $img, 'kind' => 'img'], ['src' => $img, 'kind' => 'img']]], 'source' => 'p1'];
socStateSave(['changes' => ['ftemplates' => ['upsert' => [$ftFixe]]]]);
$ftSaved = socItem('ftemplates', 'ftfixe');
check('modèle enregistré depuis l’écran : visuels écrits en fichiers', str_starts_with((string) $ftSaved['visual']['media'][0]['src'], 'uploads/') && str_starts_with((string) $ftSaved['visual']['src'], 'uploads/'));
sfSessionOuvrir(['4'], 'Test modèle fixe', 'code');
[$code, $b] = $fr('POST', '/franchise/posts', ['template' => 'ftfixe', 'free' => 'Dégustation samedi', 'fb' => true, 'date' => date('Y-m-d', strtotime('+5 days')), 'time' => '11:30']);
$pf = $code === 200 ? socItem('posts', (string) $b['post']['id']) : null;
check('franchisé : publie le modèle sans visuel à dessiner, avec les visuels du réseau', $code === 200 && $pf && count($pf['media']) === 2 && $pf['img'] === $ftSaved['visual']['media'][0]['src']
    && str_contains((string) $pf['text'], '{ville}') && str_contains((string) $pf['text'], 'Dégustation samedi') && $pf['ig'] === false, json_encode($b));

echo "Espace franchisé : opérations du réseau (contenus rattachés, métriques), modèle avec opération et lien\n";
socRattacher('post', 'mfb-111_9001', 'bo-1');
[$code, $b] = $fr('GET', '/franchise/operations');
$opF = array_column($b['operations'] ?? [], null, 'id')['bo-1'] ?? null;
check('opération du Marketing où figure la boutique : posts et pub rattachés, publication de sa Page (lecture seule)', $code === 200 && $opF
    && in_array('p1', array_column($opF['posts'], 'id'), true) && in_array('mfb-111_9001', array_column($opF['metaPosts'], 'id'), true) && count($opF['ads']) >= 1 && $opF['budget'] == 200, json_encode($opF));
check('métriques de la boutique (Meta + ventes) et objectif à sa part du budget', is_array($opF['result']) && array_key_exists('spend', $opF['result']) && $opF['goalShop'] == 500, json_encode([$opF['result'] ?? null, $opF['goalShop'] ?? null]));
// Publicités en cours, rattachées ou non à une opération : celles du réseau et celles de la boutique seule.
$jP = static fn (int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
$pubs = [['id' => 'pubR', 'name' => 'Réseau automne', 'scope' => 'reseau', 'shops' => ['4', '5'], 'status' => 'Active', 'start' => $jP(-3), 'end' => $jP(10), 'budget' => 40, 'spent' => 12,
        'budgetType' => 'quotidien', 'devise' => 'PLN', 'insights' => ['impressions' => 1000, 'reach' => 600, 'clicks' => 20]],
    ['id' => 'pubB', 'name' => 'Halle seule', 'scope' => 'boutique', 'shops' => ['4'], 'status' => 'En pause', 'start' => $jP(-1), 'end' => null, 'budget' => 10, 'spent' => 2, 'budgetType' => 'quotidien'],
    ['id' => 'pubX', 'name' => 'Wavre seule', 'scope' => 'boutique', 'shops' => ['5'], 'status' => 'Active', 'start' => $jP(-1), 'end' => null],
    ['id' => 'pubRef', 'name' => 'Refusée', 'scope' => 'boutique', 'shops' => ['4'], 'status' => 'Refusée', 'start' => $jP(1), 'end' => null],
    ['id' => 'pubOld', 'name' => 'Ancienne', 'scope' => 'boutique', 'shops' => ['4'], 'status' => 'Terminée', 'start' => $jP(-90), 'end' => $jP(-60)],
    ['id' => 'pubS', 'name' => 'Halle par le compte réseau', 'scope' => 'reseau', 'shops' => ['4'], 'status' => 'Active', 'start' => $jP(-2), 'end' => null],
    ['id' => 'pubP', 'name' => 'En pause depuis février', 'scope' => 'reseau', 'shops' => ['4', '5'], 'status' => 'En pause', 'start' => $jP(-200), 'end' => null]];
foreach ($pubs as $x) { socItemPut('ads', $x); }
$regPub = sfRegles();
[$code, $b] = $fr('GET', '/franchise/publicites');
$idsR = array_column($b['reseau'] ?? [], 'id'); $idsB = array_column($b['boutique'] ?? [], 'id');
$pR = array_column($b['reseau'] ?? [], null, 'id')['pubR'] ?? [];
check('publicités en cours : réseau et boutique seule, hors opération comprises ; ni celles d’une autre boutique, ni refusées, ni anciennes',
    $code === 200 && in_array('pubR', $idsR, true) && in_array('pubB', $idsB, true) && !array_intersect(['pubX', 'pubRef', 'pubOld'], array_merge($idsR, $idsB))
    && $pR['status'] === 'Diffusée' && ($pR['stats']['reach'] ?? null) === 600 && in_array('Halle', $pR['shops'], true), json_encode($b));
check('publicités : rangées selon les boutiques visées (Halle seule par le compte réseau → boutique) ; pauses anciennes écartées',
    in_array('pubS', $idsB, true) && !in_array('pubP', array_merge($idsR, $idsB), true), json_encode([$idsR, $idsB]));
check('publicités : budget et dépense cachés par défaut', !array_key_exists('budget', $pR) && $b['budget'] === false);
sfReglesPut(array_merge($regPub, ['voirBudgetPub' => true]));
[, $b] = $fr('GET', '/franchise/publicites');
$pR = array_column($b['reseau'] ?? [], null, 'id')['pubR'] ?? [];
check('publicités : budget et dépense si le réseau les partage', ($pR['budget'] ?? null) == 40 && ($pR['spent'] ?? null) == 12 && ($pR['devise'] ?? '') === 'PLN');
sfReglesPut(array_merge($regPub, ['voirPublicites' => false]));
[, $b] = $fr('GET', '/franchise/publicites');
check('publicités : masquées si le réseau ne les partage pas', $b['hidden'] === true && !$b['reseau'] && !$b['boutique']);
sfReglesPut($regPub);
foreach ($pubs as $x) { socItemDelete('ads', $x['id']); }
$ftLien = array_merge($ftFixe, ['id' => 'ftlien', 'campaign' => 'bo-1', 'link' => '{webshop}']);
socStateSave(['changes' => ['ftemplates' => ['upsert' => [$ftLien]]]]);
$jL = date('Y-m-d', strtotime('monday +3 weeks'));
[$c1, $b1] = $fr('POST', '/franchise/posts', ['template' => 'ftlien', 'free' => 'Venez goûter', 'fb' => true, 'date' => $jL, 'time' => '12:30']);
[$c2, $b2] = $fr('POST', '/franchise/posts', ['template' => 'ftlien', 'free' => 'Venez goûter', 'fb' => true, 'date' => $jL, 'time' => '13:30', 'link' => 'https://autre-site.be']);
$pL1 = $c1 === 200 ? socItem('posts', (string) $b1['post']['id']) : null; $pL2 = $c2 === 200 ? socItem('posts', (string) $b2['post']['id']) : null;
check('post du franchisé : hérite l’opération et le lien webshop du modèle', $pL1 && $pL1['campaign'] === 'bo-1' && $pL1['link'] === '{webshop}', json_encode([$c1, $b1]));
check('jamais de lien libre venu du franchisé', $pL2 && $pL2['link'] === '', json_encode([$c2, $b2]));
unset($_COOKIE[SF_COOKIE]);

echo "Calendrier : supprimer un post (du module, de Facebook, d'Instagram)\n";
$publie = static function (string $id, array $lignes, string $statut = 'Publié'): void {
    socItemPut('posts', ['id' => $id, 'title' => 'À supprimer ' . $id, 'type' => 'Produit', 'shops' => ['4'], 'fb' => true, 'ig' => true, 'status' => $statut,
        'date' => date('Y-m-d'), 'time' => '10:00', 'text' => 'x']);
    foreach ($lignes as [$canal, $mid, $st]) {
        Db::exec('INSERT INTO social_publication (content_id, shop_id, canal, prevue_le, statut, meta_id, maj_le) VALUES (?,?,?,?,?,?,?)', [$id, '4', $canal, date('Y-m-d H:i:s'), $st, $mid, date('Y-m-d H:i:s')]);
    }
};
$suppr = static function (array $b): array { $GLOBALS['SOC_BODY'] = $b; try { return socRoute('POST', '/posts/supprimer'); } catch (HttpError $e) { return [$e->status, ['error' => $e->getMessage()]]; } finally { unset($GLOBALS['SOC_BODY']); } };
$lignesDe = static fn (string $id) => (int) Db::row('SELECT COUNT(*) n FROM social_publication WHERE content_id = ?', [$id])['n'];
$publie('pdel1', [['fb', '111_5001', 'publie'], ['ig', 'ig5002', 'publie']]);
$GLOBALS['SUPPRIMES'] = [];
[$code, $r] = $suppr(['id' => 'pdel1', 'meta' => true]);
check('publié : supprimé de la Page Facebook et du compte Instagram, avec le token de la Page', $code === 200 && $r['ok'] && count($r['supprimees']) === 2
    && in_array(['111_5001', 'PT111'], $GLOBALS['SUPPRIMES'], true) && in_array(['ig5002', 'PT111'], $GLOBALS['SUPPRIMES'], true), json_encode([$code, $r, $GLOBALS['SUPPRIMES']]));
check('… puis retiré du module (post et file)', socItem('posts', 'pdel1') === null && $lignesDe('pdel1') === 0);
$publie('pdel2', [['ig', 'ig6001,ig6002,ig6003', 'publie']]);
$GLOBALS['SUPPRIMES'] = [];
[$code, $r] = $suppr(['id' => 'pdel2', 'meta' => true]);
check('frise Instagram : ses 3 publications supprimées', $code === 200 && $r['ok'] && array_column($GLOBALS['SUPPRIMES'], 0) === ['ig6001', 'ig6002', 'ig6003'], json_encode($GLOBALS['SUPPRIMES']));
$publie('pdel3', [['fb', '111_5003', 'publie'], ['ig', 'ig5004', 'publie']]);
$GLOBALS['DEL_REFUS'] = ['111_5003']; $GLOBALS['SUPPRIMES'] = [];
[$code, $r] = $suppr(['id' => 'pdel3', 'meta' => true]);
check('Meta refuse la Page : le post reste au calendrier, motif par canal', $code === 200 && !$r['ok'] && count($r['erreurs']) === 1 && $r['erreurs'][0]['channel'] === 'Facebook'
    && str_contains($r['erreurs'][0]['reason'], 'Permissions error') && count($r['supprimees']) === 1 && socItem('posts', 'pdel3') !== null, json_encode($r));
check('… la partie supprimée (Instagram) n’est plus suivie', $lignesDe('pdel3') === 1);
unset($GLOBALS['DEL_REFUS']); $GLOBALS['SUPPRIMES'] = [];
[$code, $r] = $suppr(['id' => 'pdel3', 'meta' => true]);
check('réessai : seule la Page restante est supprimée, puis le post', $code === 200 && $r['ok'] && array_column($GLOBALS['SUPPRIMES'], 0) === ['111_5003'] && socItem('posts', 'pdel3') === null, json_encode([$r, $GLOBALS['SUPPRIMES']]));
$publie('pdel4', [['fb', '111_5005', 'publie']]);
$GLOBALS['DEL_ABSENTS'] = ['111_5005'];
[$code, $r] = $suppr(['id' => 'pdel4', 'meta' => true]);
unset($GLOBALS['DEL_ABSENTS']);
check('déjà supprimée chez Meta (Business Suite) : vérifié, puis retirée du module', $code === 200 && $r['ok'] && socItem('posts', 'pdel4') === null, json_encode($r));
$publie('pdel5', [['fb', '111_5006', 'publie']]);
$GLOBALS['SUPPRIMES'] = [];
[$code, $r] = $suppr(['id' => 'pdel5', 'meta' => false]);
check('« retirer seulement du module » : rien chez Meta, la publication reste en ligne', $code === 200 && $r['ok'] && $r['enLigne'] === 1 && $GLOBALS['SUPPRIMES'] === [] && socItem('posts', 'pdel5') === null && $lignesDe('pdel5') === 0, json_encode($r));
$publie('pdel6', [['fb', null, 'attente'], ['ig', null, 'attente']], 'Programmé');
$GLOBALS['SUPPRIMES'] = [];
[$code, $r] = $suppr(['id' => 'pdel6', 'meta' => true]);
check('programmé (rien chez Meta) : retiré du calendrier et de la file, aucun appel Meta', $code === 200 && $r['ok'] && $GLOBALS['SUPPRIMES'] === [] && socItem('posts', 'pdel6') === null && $lignesDe('pdel6') === 0);
socRattacher('post', 'mfb-111_9001', 'bo-1');
$GLOBALS['SUPPRIMES'] = [];
[$code, $r] = $suppr(['id' => 'mfb-111_9001', 'compte' => 'fb:111']);
[$c2, $r2] = $suppr(['id' => 'mig-m2', 'compte' => 'ig:17841']);
check('publication faite hors du module : supprimée chez Meta (Page et Instagram), rattachement retiré', $code === 200 && $r['ok'] && $c2 === 200 && $r2['ok']
    && array_column($GLOBALS['SUPPRIMES'], 0) === ['111_9001', 'm2'] && !isset(socRattachements()['mfb-111_9001']), json_encode([$r, $r2, $GLOBALS['SUPPRIMES']]));
[$c3] = $suppr(['id' => 'mfb-222_7001', 'compte' => 'fb:111']);
[$c4] = $suppr(['id' => 'introuvable', 'meta' => true]);
[$c5] = $suppr(['id' => '../x']);
check('publication d’un autre compte, post inconnu ou identifiant invalide : refusé', $c3 === 404 && $c4 === 404 && $c5 === 400, json_encode([$c3, $c4, $c5]));
check('suppressions tracées au journal', (int) Db::row("SELECT COUNT(*) n FROM social_activite WHERE quoi = 'Suppression'")['n'] >= 6);

echo "Boutiques hors caisse : Pages Meta du portefeuille sans boutique de l'API\n";
$pfAvant = socSetting('meta_portefeuille');
socSettingPut('meta_portefeuille', ['le' => date('Y-m-d H:i'), 'pages' => [
    ['id' => '111', 'name' => "L'Atelier By Halle", 'instagram' => '17841', 'boutique' => '4'],
    ['id' => '222', 'name' => "L'Atelier By Wavre", 'instagram' => '', 'boutique' => '5'],
    ['id' => '822018994338891', 'name' => "L'Atelier by - Gembloux", 'instagram' => '', 'boutique' => 'marque'],   // ancien rapprochement, trop large
    ['id' => '999001', 'name' => "L'Atelier by", 'username' => 'latelierby.be', 'instagram' => '', 'boutique' => 'marque'],
], 'comptes' => [], 'instagram' => [], 'pixels' => []]);
$hc = array_values(array_filter(socShops(), static fn ($s) => !empty($s['horsCaisse'])));
check('Page sans boutique de caisse (magasin de test) : boutique « Gembloux », hors caisse, rattachée à sa Page', count($hc) === 1 && $hc[0]['name'] === 'Gembloux'
    && $hc[0]['id'] === 'pg' . substr('822018994338891', -14) && $hc[0]['ids']['page_id'] === '822018994338891' && $hc[0]['pwaShopId'] === null, json_encode($hc));
check('les Pages des boutiques de l’API et de la marque ne sont pas dupliquées', count(socShops()) === count(array_filter(socShops(), static fn ($s) => empty($s['horsCaisse']))) + 1);
socShopPut($hc[0]['id'], ['langs' => ['fr', 'nl'], 'conn' => ['fb' => 'connected']]);
$g = socShop($hc[0]['id']);
check('ses réglages se gardent comme pour une boutique (langues, connexion)', $g && $g['langs'] === ['fr', 'nl'] && $g['fb'] === 'connected' && $g['ids']['page_id'] === '822018994338891', json_encode($g));
check('hors caisse : pas de webshop (lien vers l’accueil)', socWebshopUrl($hc[0]['id']) === rtrim((string) (socSetting('webshop_url') ?: 'https://webshop.atelierby.be/'), '/') . '/');
check('magasin test : hors du réseau (« toutes les boutiques », opérations, espace franchisé), retrouvé par son identifiant',
    !empty($hc[0]['test']) && !in_array($hc[0]['id'], array_column(socReseau(), 'id'), true) && socShop($hc[0]['id']) !== null && count(socReseau()) === count(socShops()) - 1);
socQueue(['id' => 'ptest1', 'shops' => [$hc[0]['id']], 'fb' => true, 'ig' => true, 'date' => date('Y-m-d', strtotime('+2 days')), 'time' => '10:00']);
$rq = Db::rows('SELECT shop_id, canal FROM social_publication WHERE content_id = ?', ['ptest1']);
check('post sur le magasin test : sa Page, jamais l’Instagram du réseau', in_array(['shop_id' => $hc[0]['id'], 'canal' => 'fb'], $rq, true) && !in_array('marque', array_column($rq, 'shop_id'), true), json_encode($rq));
Db::exec('DELETE FROM social_publication WHERE content_id = ?', ['ptest1']);
check('nom tiré de la Page', socNomDePage("L'Atelier by - Gembloux") === 'Gembloux' && socNomDePage('L’Atelier By Namur') === 'Namur' && socNomDePage('Autre Page') === 'Autre Page');
check('la marque : son nom seul ; une boutique ou un franchisé « L’Atelier by … » n’est pas la marque', socEstLaMarque("L'Atelier By") && socEstLaMarque("L'Atelier by latelierby.be")
    && !socEstLaMarque("L'Atelier by - Gembloux latelierbygembloux") && !socEstLaMarque("L'Atelier by Harmonie") && socDevinerBoutique("L'Atelier by Harmonie", socShops()) === null);
// La Page de la marque prise par erreur sur celle de Gembloux : Gembloux n'est plus une boutique… jusqu'à la correction.
$marqueAvant = socBrand()['ids'];
socShopPut('marque', ['ids' => array_replace($marqueAvant, ['page_id' => '822018994338891'])]);
$hc2 = array_values(array_filter(socShops(), static fn ($s) => !empty($s['horsCaisse'])));
check('Page de la marque erronée : la vraie Page de la marque n’est jamais listée comme boutique', !in_array('999001', array_map(static fn ($s) => $s['ids']['page_id'], $hc2), true), json_encode($hc2));
$ap = socPortefeuilleAppliquer(false);
check('rafraîchissement des identifiants : la Page de la marque revient à la Page au nom de la marque', socBrand()['ids']['page_id'] === '999001'
    && (bool) array_filter($ap['appliques'], static fn ($f) => str_contains($f, 'Page de la marque corrigée')), json_encode([$ap, socBrand()['ids']]));
check('… et Gembloux redevient une boutique hors caisse', in_array('Gembloux', array_column(array_filter(socShops(), static fn ($s) => !empty($s['horsCaisse'])), 'name'), true));
socShopPut('marque', ['ids' => $marqueAvant]);
Db::exec("DELETE FROM social_boutique WHERE shop_id LIKE 'pg%'");
socSettingPut('meta_portefeuille', $pfAvant);

echo "Créer un post : catalogue du webshop (catégorie → sous-catégorie → produit), prix d'une boutique, promotions\n";
socSettingPut('tfb_cache_nav', null, false); socSettingPut('tfb_cache_promos', null, false);
$nav = socNavCatalogue('');
$cat = array_column($nav['categories'], null, 'id'); $grp = array_column($nav['groupes'], null, 'id'); $pr = array_column($nav['produits'], null, 'id');
check('catégories (groupes) → sous-catégories → produits actifs, avec les illustrations du webshop', count($nav['groupes']) === 2 && count($nav['categories']) === 2 && count($nav['produits']) === 2
    && $grp[31]['image'] === 'https://cdn.r2.dev/webshop/g31.webp' && $grp[31]['cats'] === [32100] && $cat[32100]['image'] === 'https://cdn.r2.dev/webshop/c32100.webp'
    && $pr[11]['image'] === 'https://cdn.r2.dev/webshop/p11.webp' && $pr[11]['cat'] === 32100 && $pr[11]['groupe'] === 31 && $pr[11]['nameNl'] === 'Chocoladekoek' && $pr[12]['formules'] === true, json_encode($nav));
check('sans boutique (ou plusieurs) : pas de prix', $nav['prix'] === false && $pr[11]['prix'] === null && $nav['shop'] === null);
check('gammes actives seulement, nombre de produits', array_column($nav['gammes'], 'id') === [14] && $nav['gammes'][0]['n'] === 1 && $pr[11]['gammes'] === [14], json_encode($nav['gammes']));
$n4 = socNavCatalogue('4'); $n5 = socNavCatalogue('5');
check('une boutique : son prix de vente (Halle 2,80 €, Wavre 3,00 €)', $n4['prix'] === true && array_column($n4['produits'], 'prix', 'id')[11] === 2.8 && array_column($n5['produits'], 'prix', 'id')[11] === 3.0 && $n4['shop'] === '4');
check('magasin test ou boutique inconnue : pas de prix', socNavCatalogue('pg12345678901234')['prix'] === false);
$o = socNavOffres(['shop' => '4', 'produit' => '11']);
check('produit : promotion liée par sa catégorie, avec libellé et portée (ici : pas à Halle)', count($o['promotions']) === 1 && $o['promotions'][0]['id'] === 17 && $o['promotions'][0]['ici'] === false
    && $o['promotions'][0]['libelle'] === '2 Cookies achetés → −1,40 € sur 1 de plus' && $o['promotions'][0]['court'] === '−1,40 €' && $o['promotions'][0]['portee'] === 'Wavre', json_encode($o));
check('promotions inactives ou terminées : écartées', !in_array(18, array_column($o['promotions'], 'id'), true) && !in_array(19, array_column($o['promotions'], 'id'), true));
$o2 = socNavOffres(['shop' => '4', 'produit' => '12']);
check('bundle du produit et ses formules (prix de la boutique + supplément)', ($o2['promotions'][0]['libelle'] ?? '') === 'Bundle : 1 × Sandwich jambon = 5,90 €' && $o2['promotions'][0]['ici'] === true
    && count($o2['formules']) === 1 && $o2['formules'][0]['prix'] === 6.4 && str_contains($o2['formules'][0]['libelle'], '+1,50 €'), json_encode($o2));
$o3 = socNavOffres(['categorie' => '32100']); $o4 = socNavOffres(['groupe' => '26']); $o5 = socNavOffres(['gamme' => '14']);
check('sous-catégorie, catégorie, gamme : les promotions de leurs produits', array_column($o3['promotions'], 'id') === [17] && array_column($o4['promotions'], 'id') === [15] && array_column($o5['promotions'], 'id') === [17]);
$_GET = ['shop' => '5']; [$code, $b] = socRoute('GET', '/catalogue/nav'); $_GET = [];
check('GET /catalogue/nav?shop=5', $code === 200 && $b['prix'] === true && count($b['produits']) === 2);
$plan = socWebshopPlanCache();
check('plan du webshop tiré du catalogue lu : sous-catégorie → catégorie, produit → sous-catégorie', ($plan['cats'][32100] ?? null) === 31 && ($plan['prods'][11] ?? null) === 32100 && ($plan['cats'][23000] ?? null) === 26, json_encode($plan));
check('le plan part avec l’état (liens du webshop à l’écran)', (socStateGet()['webshopPlan']['cats'][32100] ?? null) === 31);

echo "Offres exactes : prix et promotion vérifiés à la publication\n";
$cookie = ['kind' => 'produit', 'id' => 11, 'name' => 'Cookie chocolat', 'prix' => 2.8, 'shop' => '4'];
check('prix et promotion inchangés : rien à revoir', socVerifierOffres(['shops' => ['4'], 'date' => date('Y-m-d'), 'catalogue' => $cookie, 'offre' => ['genre' => 'promotion', 'id' => 15, 'nom' => 'Lot sandwich']]) === []);
check('prix changé dans TFBuddy : écart signalé (ancien → actuel)', socVerifierOffres(['catalogue' => ['prix' => 2.5] + $cookie]) === ['prix de « Cookie chocolat » changé dans TFBuddy : 2,50 € → 2,80 €']);
$e17 = socVerifierOffres(['shops' => ['4'], 'offre' => ['genre' => 'promotion', 'id' => 17, 'nom' => '3 Cookies !']]);
check('promotion d’une autre boutique : pas valable à Halle', count($e17) === 1 && str_contains($e17[0], 'n’est pas valable à Halle'), json_encode($e17));
check('promotion terminée ou inactive : plus active', socVerifierOffres(['shops' => ['4'], 'offre' => ['id' => 19, 'nom' => 'Terminée']]) === ['la promotion « Terminée » n’est plus active dans TFBuddy']);
$GLOBALS['SOC_BODY'] = ['catalogue' => ['prix' => 2.5] + $cookie, 'shops' => ['4']]; [$code, $b] = socRoute('POST', '/catalogue/verifier'); unset($GLOBALS['SOC_BODY']);
check('POST /catalogue/verifier : les écarts pour l’écran', $code === 200 && count($b['ecarts']) === 1 && $b['injoignable'] === false);
$pv = ['id' => 'pv1', 'date' => date('Y-m-d', strtotime('+1 day')), 'time' => '09:00', 'type' => 'Produit', 'title' => 'Cookie', 'img' => 'uploads/x.png', 'media' => [['src' => 'uploads/x.png', 'kind' => 'img']],
    'shops' => ['4'], 'ch' => ['4' => ['fb' => true, 'ig' => false]], 'fb' => true, 'ig' => false, 'status' => 'Programmé', 'text' => 'Cookie à 2,50 €', 'spread' => 0, 'catalogue' => ['prix' => 2.5] + $cookie];
socStateSave(['changes' => ['posts' => ['upsert' => [$pv]]]]);
Db::exec("UPDATE social_publication SET prevue_le = NOW() - INTERVAL 1 MINUTE WHERE content_id = 'pv1'");
$appels = [];
socRunQueue('pv1');
$lig = Db::row("SELECT statut, erreur FROM social_publication WHERE content_id = 'pv1'");
check('publication arrêtée : prix changé, rien n’est envoyé à Meta', ($lig['statut'] ?? '') === 'echec' && str_starts_with((string) $lig['erreur'], 'Publication bloquée, offre à revoir : prix de « Cookie chocolat »')
    && !array_filter($appels, static fn ($a) => $a[0] === 'POST'), json_encode($lig));
$tfbAvant = Tfb::$transport;
Tfb::$transport = static fn () => [503, '{}'];
socSettingPut('tfb_cache_promos', null, false); $navAvant = socSetting('tfb_cache_nav'); socSettingPut('tfb_cache_nav', null, false);
$e = socVerifierOffres(['shops' => ['4'], 'catalogue' => $cookie, 'offre' => ['genre' => 'promotion', 'id' => 15]], null, $inj);
check('TFBuddy injoignable : rien n’est affirmé (pas de blocage), l’écran le sait', $e === [] && $inj === true);
Tfb::$transport = $tfbAvant; socSettingPut('tfb_cache_nav', $navAvant, false);

echo "Rédaction assistée (API Anthropic simulée)\n";
$iaAppels = []; $iaMode = 'ok';
SocIa::$transport = static function (string $m, string $url, array $h, ?string $body) use (&$iaAppels, &$iaMode): array {
    $iaAppels[] = [$m, $url, $h, $body !== null ? json_decode($body, true) : null];
    if (str_contains($url, '/v1/models')) { return [200, json_encode(['data' => [['id' => 'claude-fable-9'], ['id' => 'claude-opus-9'], ['id' => 'claude-opus-8']]])]; }
    if ($iaMode === 'refus') { return [200, json_encode(['stop_reason' => 'refusal', 'content' => []])]; }
    if ($iaMode === 'cle') { return [401, json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']])]; }
    return [200, json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['variantes' => [
        ['angle' => 'Gourmandise', 'fr' => "Le cookie chocolat vous attend chez {ville}.\n{lien}", 'nl' => 'Niet gevraagd', 'titre' => 'Cookie chocolat', 'titreNl' => 'Chocoladekoek', 'surtitre' => 'Fait maison', 'phrase' => 'croquant dehors, fondant dedans']],
        'hashtags' => ['cookie', '#gourmandise']])]]])];
};
$proposer = static function (array $b): array { $GLOBALS['SOC_BODY'] = $b; try { return socRoute('POST', '/redaction/proposer'); } catch (HttpError $e) { return [$e->status, $e->getMessage()]; } finally { unset($GLOBALS['SOC_BODY']); } };
socSettingPut('anthropic_api_key', null, true);
[$code, $msg] = $proposer(['produit' => 'Cookie chocolat']);
check('sans clé : refus 409 avec la marche à suivre', $code === 409 && str_contains((string) $msg, 'Paramètres → Rédaction IA'));
socSettingPut('anthropic_api_key', 'sk-test-cle', true);
socSettingPut('ia_modele_auto', null, false);
[$code, $r] = $proposer(['produit' => 'Cookie chocolat', 'prix' => '2,80 €', 'langues' => ['fr'], 'boutiques' => ['Halle'], 'lien' => true]);
$msgs = array_values(array_filter($iaAppels, static fn ($a) => str_ends_with($a[1], '/v1/messages')));
$req = $msgs[0][3] ?? [];
check('modèle : le plus récent de la famille Opus accessible à la clé', $code === 200 && ($req['model'] ?? '') === 'claude-opus-9' && $r['modele'] === 'claude-opus-9', json_encode($req['model'] ?? null));
check('requête : sortie JSON imposée, effort moyen, repli serveur, clé dans l’en-tête', ($req['output_config']['format']['type'] ?? '') === 'json_schema' && ($req['output_config']['effort'] ?? '') === 'medium'
    && ($req['fallbacks'] ?? '') === 'default' && in_array('anthropic-beta: server-side-fallback-2026-07-01', $msgs[0][2], true) && in_array('x-api-key: sk-test-cle', $msgs[0][2], true));
check('charte et vraies données dans le message (aucun fait inventé demandé)', str_contains((string) $req['system'], 'Vouvoiement') && str_contains((string) $req['system'], 'N’invente aucun fait')
    && str_contains((string) $req['messages'][0]['content'], 'Prix de vente : 2,80 €') && str_contains((string) $req['system'], '{lien}'));
check('réponse : langue non demandée vidée, hashtags de la marque + thématiques', $r['variantes'][0]['nl'] === '' && $r['variantes'][0]['titre'] === 'Cookie chocolat'
    && $r['hashtags'] === ['#latelierby', '#cookie', '#gourmandise'], json_encode($r));
check('clé secrète : ni dans les paramètres ni dans l’état', socParams()['anthropic_api_key'] === ['defini' => true] && !str_contains(json_encode(socStateGet()), 'sk-test-cle') && socStateGet()['ia']['configure'] === true);
$iaMode = 'refus'; [$code] = $proposer(['produit' => 'X']);
$iaMode = 'cle'; [$code2, $msg2] = $proposer(['produit' => 'X']);
check('refus du modèle : 422 ; clé refusée : 409 lisible', $code === 422 && $code2 === 409 && str_contains((string) $msg2, 'clé API Anthropic refusée'));
$iaMode = 'ok';
$GLOBALS['SOC_BODY'] = ['charte_editoriale' => ['ton' => str_repeat('a', 900), 'vouvoiement' => false, 'emojisMax' => 99, 'motsInterdits' => 'pas cher, discount', 'hashtags' => ['latelierby', '#Halle'], 'pirate' => 1]];
socRoute('POST', '/parametres'); unset($GLOBALS['SOC_BODY']);
$ch = socCharte();
check('charte : champs connus, bornés, mots et hashtags normalisés', mb_strlen($ch['ton']) === 600 && $ch['vouvoiement'] === false && $ch['emojisMax'] === 10 && $ch['motsInterdits'] === ['pas cher', 'discount']
    && $ch['hashtags'] === ['#latelierby', '#Halle'] && !isset($ch['pirate']), json_encode($ch));
check('{hashtag_ville} : le hashtag de chaque boutique', socFill('{hashtag_ville}', ['id' => '4', 'name' => 'Saint-Gilles'], null, 'fr') === '#SaintGilles');
socSettingPut('charte_editoriale', null, false);

echo "Ce qui marche : chaque publication comparée à l'habitude de son compte\n";
$pub = static fn (string $compte, string $date, string $time, string $type, string $texte, ?int $e) => ['compte' => $compte, 'canal' => str_starts_with($compte, 'ig') ? 'Instagram' : 'Facebook',
    'date' => $date, 'time' => $time, 'type' => $type, 'texte' => $texte, 'e' => $e, 'module' => null];
$pubs = [];
// Deux Pages de tailles très différentes : la photo du mardi après-midi, la vidéo (avec une question) du samedi matin.
foreach (['2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06', '2026-10-13'] as $d) {
    $pubs[] = $pub('fb:A', $d, '14:00', 'Photo', 'Brioche du jour.', 100); $pubs[] = $pub('fb:B', $d, '14:00', 'Photo', 'Brioche du jour.', 10);
}
foreach (['2026-09-12', '2026-09-19', '2026-09-26', '2026-10-03', '2026-10-10'] as $d) {
    $pubs[] = $pub('fb:A', $d, '09:30', 'Vidéo ou reel', 'Vous l’avez goûtée ?', 300); $pubs[] = $pub('fb:B', $d, '09:00', 'Vidéo ou reel', 'Vous l’avez goûtée ?', 30);
}
$pubs[] = $pub('fb:C', '2026-10-01', '12:00', 'Photo', 'x', 5000); $pubs[] = $pub('fb:C', '2026-10-02', '12:00', 'Photo', 'x', 9000);  // 2 publications : pas de référence
foreach ([1, 2, 3] as $i) { $pubs[] = $pub('ig:D', '2026-10-0' . $i, '10:00', 'Photo', 'x', null); }                                 // sans chiffres
$an = socRecoAnalyser($pubs);
$cst = array_column($an['constats'], null, 'dimension');
check('analyse : une grosse et une petite Page se comparent à leur propre habitude ; un compte de 2 publications ou sans chiffres n’est pas comparé',
    $an['publications'] === 25 && $an['comparees'] === 20 && $an['comptesCompares'] === 2 && $an['sansChiffres'] === 3, json_encode(array_diff_key($an, ['dimensions' => 1])));
check('constat : vidéo ou reel ≈ ×1,5 l’habitude, photo ≈ ×0,5 ; aussi le jour, le créneau et la question', ($cst['type']['mieux'] ?? '') === 'Vidéo ou reel' && $cst['type']['moins'] === 'Photo'
    && str_contains($cst['type']['texte'], '×1,5') && str_contains($cst['type']['texte'], '(10 publications)') && ($cst['jour']['mieux'] ?? '') === 'Samedi'
    && ($cst['creneau']['mieux'] ?? '') === 'Matin (avant 11 h)' && ($cst['question']['mieux'] ?? '') === 'Pose une question', json_encode($an['constats']));
$cs = $an['conseil'] ?? [];
check('prochain créneau conseillé : le prochain samedi, à l’heure médiane des bonnes publications du matin (09:15)', ($cs['jour'] ?? '') === 'Samedi' && ($cs['heure'] ?? '') === '09:15'
    && date('N', strtotime($cs['date'])) === '6' && $cs['date'] > date('Y-m-d') && $cs['date'] <= date('Y-m-d', strtotime('+7 days')), json_encode($cs));
$an2 = socRecoAnalyser($pubs, 11);
check('groupes trop petits (moins que le minimum) : aucun constat ni conseil', $an2['constats'] === [] && $an2['conseil'] === null);
$rp = socRecoPub(['account' => 'fb:A', 'channel' => 'Facebook', 'date' => '2026-10-01', 'time' => '10:00', 'type' => 'Album · 4 photos', 'text' => 'x', 'metaId' => '111_9',
    'stats' => ['reactions' => 7, 'comments' => 2, 'shares' => null]], ['111_9' => ['style' => 'ruby', 'format' => 'story', 'type' => 'Produit']]);
$rp2 = socRecoPub(['account' => 'ig:D', 'channel' => 'Instagram', 'date' => '2026-10-01', 'time' => '10:00', 'type' => 'Reel', 'text' => '', 'metaId' => '9', 'stats' => ['reactions' => null, 'comments' => null, 'shares' => null]], []);
check('publication lue : partages absents = 0, sans réactions ni commentaires = pas de chiffres, types regroupés, visuel du module retrouvé',
    $rp['e'] === 9 && $rp['type'] === 'Album ou carrousel' && $rp['module']['style'] === 'ruby' && $rp2['e'] === null && $rp2['type'] === 'Vidéo ou reel' && $rp2['module'] === null);
socSettingPut('reco_cache', null, false);
$_GET = ['cache' => '1']; [$code, $b] = socRoute('GET', '/recommandations'); $_GET = [];
check('GET /recommandations?cache=1 sans analyse gardée : rien (pas de lecture chez Meta)', $code === 200 && $b['reco'] === null);
socSettingPut('reco_cache', ['le' => time() - 86400 * 3, 'data' => $an], false);
$_GET = ['cache' => '1']; [$code, $b] = socRoute('GET', '/recommandations'); $_GET = [];
check('… avec une analyse gardée (même ancienne) : elle, telle quelle', $code === 200 && ($b['reco']['comparees'] ?? 0) === 20);
check('tendances du texte pour l’assistant : question, longueur, emoji, prix, lien seulement', socRecoConseilsTexte() !== [] && !array_filter(socRecoConseilsTexte(), static fn ($t) => str_starts_with($t, 'Samedi') || str_starts_with($t, 'Vidéo')));
$iaAppels = [];
[$code] = $proposer(['produit' => 'Cookie chocolat', 'langues' => ['fr'], 'objectif' => 'Faire venir des clients du lundi au vendredi, 13 – 18 h', 'operation' => 'Automne']);
$req = (array_values(array_filter($iaAppels, static fn ($a) => str_ends_with($a[1], '/v1/messages')))[0][3] ?? []);
check('assistant : l’objectif de l’idée, l’opération et ce qui marche sur nos comptes sont dans la demande', $code === 200 && str_contains((string) $req['messages'][0]['content'], 'Objectif du post : Faire venir des clients')
    && str_contains((string) $req['messages'][0]['content'], 'Opération commerciale : Automne') && str_contains((string) $req['system'], 'Ce qui marche sur nos comptes') && str_contains((string) $req['system'], 'Pose une question'));
socSettingPut('reco_cache', null, false); socSettingPut('anthropic_api_key', null, true);

echo "Idées de posts : promotions sans post, opérations, gammes, heures creuses (BO)\n";
$auj = date('Y-m-d'); $j = static fn (int $n) => date('Y-m-d', strtotime($auj . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
socSettingPut('tfb_cache_promos', ['le' => time(), 'items' => [
    ['id' => 901, 'type' => 'offre', 'nom' => 'Cookies 4+1', 'libelle' => '4+1 sur les cookies', 'court' => '4+1', 'du' => $j(-3), 'au' => $j(4), 'tous' => false, 'shops' => ['4'], 'produits' => [3200006], 'categories' => []],
    ['id' => 902, 'type' => 'remise', 'nom' => 'Déjà annoncée', 'libelle' => '−10 %', 'court' => '−10 %', 'du' => null, 'au' => null, 'tous' => true, 'shops' => [], 'produits' => [], 'categories' => []],
    ['id' => 903, 'type' => 'bundle', 'nom' => 'Noël', 'libelle' => 'Coffret', 'court' => '', 'du' => $j(40), 'au' => $j(60), 'tous' => true, 'shops' => [], 'produits' => [], 'categories' => []]]], false);
socStateSave(['changes' => ['posts' => ['upsert' => [['id' => 'pidee1', 'date' => $j(-2), 'time' => '08:00', 'type' => 'Promo', 'title' => 'Promo', 'shops' => ['4'], 'ch' => ['4' => ['fb' => true, 'ig' => false]],
    'fb' => true, 'ig' => false, 'status' => 'Brouillon', 'text' => 'x', 'offre' => ['genre' => 'promotion', 'id' => 902]]]]]]);
$GLOBALS['BO_CAMPAGNES'][] = ['id' => 3, 'name' => 'Saint-Nicolas', 'scope' => 'BOUTIQUES', 'status_code' => 'planned', 'starts_on' => $j(6), 'ends_on' => $j(12), 'budget_amount' => 0.0];
$GLOBALS['BO_CAMPAGNES'][] = ['id' => 4, 'name' => 'Pâques', 'scope' => 'RESEAU', 'status_code' => 'planned', 'starts_on' => $j(90), 'ends_on' => $j(100), 'budget_amount' => 0.0];
socOperations(true);
$caisse = socNavBoutiques()[0];
$navAvant = socSetting('tfb_cache_nav');
socSettingPut('tfb_cache_nav', ['v' => 1, 'shops' => [$caisse => ['le' => time(), 'data' => ['groupes' => [], 'categories' => [], 'produits' => [], 'gammes' => [
    ['id' => 77, 'name' => 'Hivernale', 'du' => date('d/m', strtotime($j(10))), 'au' => '28/02', 'n' => 12], ['id' => 78, 'name' => 'Printanière', 'du' => '21/03', 'au' => '20/06', 'n' => 9]]]]]], false);
$boAvant = BoMarketing::$transport; $creuxAppels = [];
BoMarketing::$transport = static function (string $url) use ($boAvant, &$creuxAppels, $caisse): array {
    if (!str_contains($url, '/api/cockpit/creux')) { return $boAvant($url); }
    $creuxAppels[] = $url;
    if (str_contains($url, 'shop=' . socReseau()[0]['id'] . '&')) { return [400, json_encode(['error' => 'magasin manquant ou inconnu'])]; }
    return [200, json_encode(['reseau' => [
        ['id' => '4', 'nom' => 'Atelier by - Halle', 'moyenne' => 153.1, 'bloc' => ['jours' => [1, 2, 3, 4, 5], 'heureDe' => 13, 'heureA' => 17, 'nom' => 'Lundi → vendredi, 13 – 18 h', 'caH' => 80.6, 'potentiel' => 2995]],
        ['id' => '5', 'nom' => 'Wavre', 'moyenne' => 100, 'bloc' => null], ['id' => '999', 'nom' => 'Inconnue', 'moyenne' => 90, 'bloc' => ['jours' => [2], 'heureDe' => 15, 'heureA' => 16, 'nom' => 'Mardi', 'caH' => 1, 'potentiel' => 10]]]])];
};
socSettingPut('creux_cache', null, false);
[$code, $b] = socRoute('GET', '/idees');
$id = array_column($b['idees'] ?? [], null, 'id');
check('promotion active sans post : idée préremplie (offre, boutiques, produit) ; déjà annoncée ou lointaine : non', $code === 200 && isset($id['promo-901']) && !isset($id['promo-902']) && !isset($id['promo-903'])
    && $id['promo-901']['prefill']['offre']['id'] === 901 && $id['promo-901']['prefill']['shops'] === ['4'] && $id['promo-901']['prefill']['catalogue'] === ['kind' => 'produit', 'id' => 3200006]
    && str_contains($id['promo-901']['detail'], 'plus que 4 jours'), json_encode($b));
check('opération du BO proche sans post : idée 2 jours avant son début, rattachée ; dans 3 mois ou sans date : non', isset($id['op-bo-3']) && $id['op-bo-3']['prefill']['campaign'] === 'bo-3'
    && $id['op-bo-3']['prefill']['date'] === $j(4) && !isset($id['op-bo-4']) && !isset($id['op-bo-2']), json_encode(array_keys($id)));
check('gamme qui commence dans 10 jours : idée à sa date, gamme choisie ; une gamme loin dans l’année : non', isset($id['gamme-77']) && $id['gamme-77']['prefill']['date'] === $j(10)
    && $id['gamme-77']['prefill']['catalogue'] === ['kind' => 'gamme', 'id' => 77] && (!isset($id['gamme-78']) || abs(strtotime('2026-03-21') - time()) < 86400 * 21));
$cx = $id['creux-4'] ?? [];
check('heures creuses (BO) : la boutique, un jour du creux, 2 h avant ; boutique inconnue du module ou sans creux : non ; boutique inconnue du BO : la suivante',
    ($cx['prefill']['shops'] ?? null) === ['4'] && $cx['prefill']['time'] === '11:00' && (int) date('N', strtotime($cx['prefill']['date'])) <= 5 && $cx['prefill']['date'] > $auj
    && str_contains($cx['detail'], '2 995 €') && !isset($id['creux-999']) && !isset($id['creux-5']) && count($creuxAppels) === 2 && str_contains($creuxAppels[1], '_cache=900'), json_encode([$cx, $creuxAppels]));
socRoute('GET', '/idees');
check('heures creuses gardées 6 h (pas de nouvel appel au BO)', count($creuxAppels) === 2 && $b['muettes'] === []);
socSettingPut('creux_cache', null, false); socSettingPut('tfb_cache_promos', null, false);
BoMarketing::$transport = static fn (string $url) => str_contains($url, '/api/cockpit/creux') ? [401, '{"error":"auth"}'] : $boAvant($url);
$tfbAvant2 = Tfb::$transport; Tfb::$transport = static fn () => [503, ''];
[$code, $b] = socRoute('GET', '/idees');
check('sources muettes (TFBuddy, BO) : nommées, le reste suit', $code === 200 && in_array('promotions (TFBuddy)', $b['muettes'], true) && in_array('heures creuses (BO)', $b['muettes'], true)
    && isset(array_column($b['idees'], null, 'id')['op-bo-3']), json_encode($b['muettes']));
Tfb::$transport = $tfbAvant2;
BoMarketing::$transport = $boAvant; array_splice($GLOBALS['BO_CAMPAGNES'], 2); socOperations(true);
socSettingPut('tfb_cache_nav', $navAvant, false); socSettingPut('creux_cache', null, false);
socStateSave(['changes' => ['posts' => ['delete' => ['pidee1']]]]);

echo "Détourage des photos (remove.bg simulé)\n";
$png1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$rbAppels = []; $rbMode = 'ok';
SocDetourage::$transport = static function (array $corps) use (&$rbAppels, &$rbMode, $png1): array {
    $rbAppels[] = $corps;
    return match ($rbMode) {
        'ok' => [200, json_encode(['data' => ['result_b64' => $png1]]), ['x-credits-charged' => '1']],
        'credits' => [402, json_encode(['errors' => [['title' => 'Insufficient credits']]]), []],
        default => [403, json_encode(['errors' => [['title' => 'Invalid API Key']]]), []],
    };
};
$detourer = static function (array $b): array { $GLOBALS['SOC_BODY'] = $b; try { return socRoute('POST', '/media/detourer'); } catch (HttpError $e) { return [$e->status, $e->getMessage()]; } finally { unset($GLOBALS['SOC_BODY']); } };
socSettingPut('removebg_api_key', null, true);
[$code, $msg] = $detourer(['image' => 'data:image/png;base64,' . $png1]);
check('sans clé : 409 avec la marche à suivre, rien envoyé', $code === 409 && str_contains((string) $msg, 'Paramètres') && $rbAppels === [] && socStateGet()['ia']['detourage'] === false);
socSettingPut('removebg_api_key', 'rb-test', true);
[$code, $r] = $detourer(['image' => 'data:image/png;base64,' . $png1]);
check('photo déposée : PNG détouré rendu en data:, crédits notés, image envoyée en base64', $code === 200 && str_starts_with($r['src'], 'data:image/png;base64,') && $r['credits'] === 1.0
    && ($rbAppels[0]['image_file_b64'] ?? '') === $png1 && ($rbAppels[0]['format'] ?? '') === 'png' && socStateGet()['ia']['detourage'] === true, json_encode([$code, $rbAppels[0] ?? null]));
check('clé secrète : jamais dans les paramètres ni dans l’état', socParams()['removebg_api_key'] === ['defini' => true] && !str_contains(json_encode(socStateGet()), 'rb-test'));
$n = count($rbAppels);
[$c1] = $detourer(['image' => 'https://evil.example/x.png']); [$c2] = $detourer(['image' => 'uploads/../../config.png']); [$c3] = $detourer(['image' => 'data:image/png;base64,' . base64_encode('pas une image')]);
check('hôte hors du webshop, chemin détourné, faux fichier : refusés (422), rien envoyé', $c1 === 422 && $c2 === 422 && $c3 === 422 && count($rbAppels) === $n, json_encode([$c1, $c2, $c3]));
$rbMode = 'credits'; [$c4, $m4] = $detourer(['image' => 'data:image/png;base64,' . $png1]);
$rbMode = 'cle'; [$c5, $m5] = $detourer(['image' => 'data:image/png;base64,' . $png1]);
check('crédits épuisés, clé refusée : 409 lisibles', $c4 === 409 && str_contains((string) $m4, 'crédits remove.bg épuisés') && $c5 === 409 && str_contains((string) $m5, 'clé remove.bg refusée'));
SocDetourage::$transport = null; socSettingPut('removebg_api_key', null, true);

echo "Publicités : verdict A/B des variantes (taux de clic)\n";
$ab = static fn (array ...$v) => socAbVerdict(array_map(static fn ($x) => ['variant' => $x[0], 'impressions' => $x[1], 'clicks' => $x[2]], $v));
$g = $ab(['A', 5000, 70], ['B', 5000, 110]);
check('B nettement devant (2,2 % contre 1,4 %) : gagnante, sûr à plus de 95 %', $g['statut'] === 'gagnante' && $g['variante'] === 'B' && $g['confiance'] > 0.99
    && str_contains($g['texte'], 'taux de clic 2,2 % contre 1,4 % pour A (+57 %)'), json_encode($g));
$t = $ab(['A', 3000, 60], ['B', 3000, 72], ['C', 3000, 50]);
check('écart modeste : tendance (80 à 95 %), comparée à la plus proche', $t['statut'] === 'tendance' && $t['variante'] === 'B' && $t['confiance'] >= 0.8 && $t['confiance'] < 0.95 && str_contains($t['texte'], 'pour A'), json_encode($t));
$e = $ab(['A', 4000, 80], ['B', 4000, 82]);
check('pas d’écart net', $e['statut'] === 'egalite' && $e['variante'] === null, json_encode($e));
$tt = $ab(['A', 2000, 40], ['B', 300, 9]);
check('trop tôt sous 500 impressions ou 20 clics par variante ; une seule variante : rien', $tt['statut'] === 'tot' && str_contains($tt['texte'], '300 impressions et 9 clics') && $ab(['A', 9000, 90]) === null, json_encode($tt));

echo "Générateur : modèles d'étiquette (textes posés sur le visuel)\n";
$GLOBALS['SOC_BODY'] = ['etiquettes_modeles' => [
    ['id' => 'm1', 'nom' => 'Promo cookies', 'forme' => 'pastille', 'police' => 'titre', 'couleur' => 'blanc', 'fond' => 'ruby', 'taille' => 999, 'rot' => -8, 'x' => 80, 'y' => 24, 'pirate' => '<script>'],
    ['id' => 'm2', 'nom' => '', 'forme' => 'etiquette'], ['id' => 'm<3>', 'nom' => 'Étiquette', 'forme' => 'inconnue', 'police' => 'comic', 'couleur' => '#ff00ff', 'fond' => 'abricot']]];
[$code, $b] = socRoute('POST', '/parametres'); unset($GLOBALS['SOC_BODY']);
$em = socSetting('etiquettes_modeles');
check('modèles enregistrés : champs connus, valeurs bornées, palette et polices de la marque seulement', $code === 200 && count($em) === 2 && $em[0]['taille'] === 220 && !isset($em[0]['pirate'])
    && $em[1]['id'] === 'm3' && $em[1]['forme'] === 'etiquette' && $em[1]['police'] === 'titre' && $em[1]['couleur'] === 'blanc' && $em[1]['fond'] === 'abricot' && ($b['params']['etiquettes_modeles'][0]['nom'] ?? '') === 'Promo cookies', json_encode($em));
socSettingPut('etiquettes_modeles', null, false);

echo "Générateur : modèles de visuel complets (style, format, fond, logo, illustration, textes posés)\n";
$GLOBALS['SOC_BODY'] = ['visuels_modeles' => [
    ['id' => 'v1', 'nom' => 'Story automne', 'v' => ['style' => 'pleinhaut', 'format' => 'story', 'fond' => 'abricot', 'logo' => 'clair', 'fondu' => 140, 'showLogo' => 0, 'phraseColor' => 'creme',
        'couleurs' => ['title' => 'abricot', 'price' => 'gris', 'site' => '#ff00ff', 'pirate' => 'ruby'], 'eyebrow' => 'Automne', 'title' => 'Ne doit pas suivre', 'price' => '9,99', 'photos' => ['https://x/y.jpg'],
        'illus' => ['src' => 'img/illus/croissant.png', 'x' => 120, 'taille' => 2, 'trait' => 'ruby'],
        'textes' => [['txt' => '4+1', 'sous' => 'la 5ᵉ offerte', 'forme' => 'pastille', 'police' => 'titre', 'couleur' => 'blanc', 'fond' => 'ruby', 'taille' => 999, 'x' => 80, 'y' => 20], 'pas un texte']]],
    ['id' => 'v2', 'nom' => 'Piégé', 'v' => ['style' => '<b>', 'format' => '16:9', 'fond' => '#ff00ff', 'logo' => 'neon', 'illus' => ['src' => 'https://evil.example/x.png'], 'textes' => [['txt' => 'x', 'police' => 'comic']]]],
    ['id' => 'v3', 'nom' => '', 'v' => ['style' => 'epure']], ['id' => 'v4', 'nom' => 'Sans réglages'],
    ['id' => 'v5', 'nom' => 'Chemin', 'v' => ['illus' => ['src' => 'img/illus/../../config.png']]]]];
[$code, $b] = socRoute('POST', '/parametres'); unset($GLOBALS['SOC_BODY']);
$vm = socSetting('visuels_modeles');
$v1 = $vm[0]['v'] ?? []; $v2 = $vm[1]['v'] ?? [];
check('modèle de visuel : réglages connus et bornés, sans le produit, le prix ni la photo du post', $code === 200 && count($vm) === 3 && $vm[0]['nom'] === 'Story automne'
    && $v1['style'] === 'pleinhaut' && $v1['format'] === 'story' && $v1['fond'] === 'abricot' && $v1['logo'] === 'clair' && $v1['fondu'] === 100 && $v1['showLogo'] === false && $v1['eyebrow'] === 'Automne'
    && !isset($v1['title']) && !isset($v1['price']) && !isset($v1['photos']) && $v1['phraseColor'] === 'creme' && $v1['couleurs'] === ['title' => 'abricot', 'price' => 'gris'] && $v1['illus']['x'] === 100 && $v1['illus']['taille'] === 5 && $v1['illus']['trait'] === 'ruby'
    && count($v1['textes']) === 1 && $v1['textes'][0]['txt'] === '4+1' && $v1['textes'][0]['taille'] === 220 && $v1['textes'][0]['forme'] === 'pastille'
    && ($b['params']['visuels_modeles'][0]['id'] ?? '') === 'v1', json_encode($vm));
check('modèle de visuel : style, format, fond, logo et illustration hors liste ramenés aux valeurs sûres', $v2['style'] === 'epure' && $v2['format'] === 'carre' && $v2['fond'] === null && $v2['logo'] === 'auto'
    && $v2['illus'] === null && $v2['textes'][0]['police'] === 'titre' && ($vm[2]['nom'] ?? '') === 'Chemin' && array_key_exists('illus', $vm[2]['v']) && $vm[2]['v']['illus'] === null, json_encode([$v2, $vm[2] ?? null]));
socSettingPut('visuels_modeles', null, false);

echo "Images du webshop servies par le module (le visuel composé s'exporte)\n";
$media = static function (string $u): array { $_GET = ['u' => $u]; try { return socRoute('GET', '/media/webshop'); } catch (HttpError $e) { return [$e->status, $e->getMessage()]; } finally { $_GET = []; } };
[$code, $f] = $media('https://cdn.r2.dev/webshop/p11.webp');
check('photo du catalogue (R2) rendue par le module, type lu dans ses octets', $code === 200 && $f instanceof SocFichier && $f->type === 'image/webp' && str_starts_with($f->octets, 'RIFF'));
check('hôte hors du webshop, http, ou adresse détournée : refusés (422)', $media('https://exemple.com/x.webp')[0] === 422 && $media('http://cdn.r2.dev/x.webp')[0] === 422
    && $media('https://cdn.r2.dev@exemple.com/x.webp')[0] === 422 && $media('file:///etc/passwd')[0] === 422 && $media('')[0] === 422);
check('réponse qui n’est pas une image, ou image absente : 502', $media('https://cdn.r2.dev/faux.webp')[0] === 502 && $media('https://cdn.r2.dev/absente.webp')[0] === 502);

echo "Espace franchisé : la photo du webshop d'un produit, le visuel personnalisable\n";
$fmedia = static function (string $u, array $q = []): array { $_GET = ['u' => $u] + $q; try { return socRoute('GET', '/franchise/media/webshop'); } catch (HttpError $e) { return [$e->status, $e->getMessage()]; } finally { $_GET = []; } };
unset($_COOKIE[SF_COOKIE]);
check('franchisé sans session : la photo du webshop est refusée (401)', $fmedia('https://cdn.r2.dev/webshop/p11.webp')[0] === 401);
sfSessionOuvrir(['4'], 'Test photo', 'code');
[$code, $f] = $fmedia('https://cdn.r2.dev/webshop/p11.webp');
check('franchisé : la photo du produit (R2) servie par le module ; autre hôte ou autre boutique refusés', $code === 200 && $f instanceof SocFichier && $f->type === 'image/webp'
    && $fmedia('https://exemple.com/x.webp')[0] === 422 && $fmedia('https://cdn.r2.dev/webshop/p11.webp', ['shop' => '5'])[0] === 403);
// Le catalogue du webshop de la boutique (en cache) donne la photo du produit 1 ; le catalogue de caisse, ses produits.
$navAvant = socSetting('tfb_cache_nav');
socSettingPut('tfb_cache_nav', ['v' => 1, 'shops' => ['4' => ['le' => time(), 'data' => ['groupes' => [], 'categories' => [], 'gammes' => [],
    'produits' => [['id' => 1, 'name' => 'Brioche croustillante', 'image' => 'https://cdn.r2.dev/webshop/p1.webp']]]]]], false);
socSettingPut('tfb_cache_catalogue_4', null, false);
$catF = array_column(sfCatalogue('4'), null, 'apiId');
check('catalogue de la boutique : chaque produit avec sa photo du webshop quand il en a une', ($catF[1]['photo'] ?? null) === 'https://cdn.r2.dev/webshop/p1.webp' && $catF[1]['img'] !== '', json_encode(array_values($catF)));
socSettingPut('tfb_cache_nav', $navAvant, false); socSettingPut('tfb_cache_catalogue_4', null, false);
unset($_COOKIE[SF_COOKIE]);
check('règle « personnaliser le visuel » (format, couleur des textes) : ouverte par défaut, le franchiseur peut la fermer', sfRegles()['persoVisuel'] === true
    && sfReglesPut(array_merge(sfRegles(), ['persoVisuel' => false]))['persoVisuel'] === false && sfRegles()['persoVisuel'] === false);
sfReglesPut(array_merge(sfRegles(), ['persoVisuel' => true]));

echo "Espace franchisé : promotions, bundles et formules liés au produit (Publier)\n";
$regAvant = sfRegles();
sfReglesPut(array_merge($regAvant, ['actif' => true, 'quotaSemaine' => 50, 'validation' => 'modele', 'jours' => [0, 1, 2, 3, 4, 5, 6], 'heureMin' => '07:00', 'heureMax' => '20:00']));
$jj = static fn (int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
socSettingPut('tfb_cache_catalogue_4', null, false);
socSettingPut('tfb_cache_promos', ['le' => time(), 'items' => [
    ['id' => 950, 'type' => 'offre', 'nom' => 'Brioches 4+1', 'libelle' => '4 + 1 offert (Brioche croustillante)', 'court' => '4+1', 'du' => $jj(-1), 'au' => $jj(20), 'tous' => false, 'shops' => ['4', '5'], 'produits' => [1], 'categories' => []],
    ['id' => 951, 'type' => 'bundle', 'nom' => 'Duo brioche', 'libelle' => 'Bundle : 2 × Brioche croustillante = 9,90 €', 'court' => '9,90 €', 'du' => null, 'au' => null, 'tous' => true, 'shops' => [], 'produits' => [1], 'categories' => []],
    ['id' => 952, 'type' => 'offre', 'nom' => 'Wavre seulement', 'libelle' => '2+1 à Wavre', 'court' => '2+1', 'du' => null, 'au' => null, 'tous' => false, 'shops' => ['5'], 'produits' => [1], 'categories' => []],
    ['id' => 953, 'type' => 'offre', 'nom' => 'Autre produit', 'libelle' => 'Autre', 'court' => '1+1', 'du' => null, 'au' => null, 'tous' => true, 'shops' => [], 'produits' => [99], 'categories' => []],
    ['id' => 954, 'type' => 'remise', 'nom' => 'Plus tard', 'libelle' => '−10 % · Plus tard', 'court' => '−10 %', 'du' => $jj(25), 'au' => $jj(28), 'tous' => true, 'shops' => [], 'produits' => [1], 'categories' => []]]], false);
sfSessionOuvrir(['4'], 'Test offres', 'code');
[$code, $of] = $fr('GET', '/franchise/catalogue/offres', [], ['produit' => 'p1']);
$ofi = array_column($of['promotions'] ?? [], null, 'id');
check('offres du produit : les promotions et bundles qui le visent, valables dans la boutique (ni celles d’une autre boutique, ni d’un autre produit)', $code === 200
    && array_keys($ofi) === [950, 951, 954] && $ofi[950]['portee'] === 'Votre boutique et 1 autre' && $ofi[951]['portee'] === 'Toutes les boutiques', json_encode($of));
check('mention de l’offre tirée de l’API (dates, boutique), en français et en néerlandais', $ofi[950]['mention'] === 'Offre valable du ' . date('d/m/Y', strtotime($jj(-1))) . ' au ' . date('d/m/Y', strtotime($jj(20))) . ', à Halle.'
    && $ofi[951]['mention'] === 'Offre valable dans toutes les boutiques.' && $ofi[951]['mentionNl'] === 'Aanbod geldig in alle winkels.' && !str_contains(json_encode($of), '"shops"'), json_encode([$ofi[950]['mention'], $ofi[951]['mention']]));
$d3 = $jj(3);
[$code, $b] = $fr('POST', '/franchise/posts', ['template' => 'fp-produit', 'product' => 'p1', 'offre' => ['genre' => 'promotion', 'id' => 951], 'fb' => true, 'ig' => false, 'date' => $d3, 'time' => '10:00', 'visual' => $vis]);
$po = socItem('posts', (string) ($b['post']['id'] ?? ''));
check('produit du jour + bundle du réseau : libellé et mention dans le texte, offre gardée pour la vérification à la publication', $code === 200
    && str_contains($po['text'], "\nBundle : 2 × Brioche croustillante = 9,90 €\nOffre valable dans toutes les boutiques.") && ($po['offre']['id'] ?? null) === 951 && ($po['offre']['genre'] ?? '') === 'promotion'
    && ($po['promo']['label'] ?? '') === '9,90 €' && str_contains($po['textNl'], 'Aanbod geldig in alle winkels.'), json_encode([$code, $b, $po['text'] ?? null]));
[$code, $b] = $fr('POST', '/franchise/posts', ['template' => 'fp-offre', 'product' => 'p1', 'offre' => ['genre' => 'promotion', 'id' => 950], 'from' => $jj(40), 'to' => $jj(41), 'fb' => true, 'date' => $d3, 'time' => '11:00', 'visual' => $vis]);
$po2 = socItem('posts', (string) ($b['post']['id'] ?? ''));
check('offre spéciale avec la promotion du réseau : {offre} = 4+1, ses dates (pas celles saisies), sa mention', $code === 200
    && str_starts_with($po2['text'], '4+1 sur Brioche croustillante chez L’Atelier By {ville}, du ' . date('d/m', strtotime($jj(-1)))) && ($po2['promo']['to'] ?? '') === $jj(20)
    && str_contains($po2['text'], 'Offre valable du ') && ($po2['offre']['id'] ?? null) === 950, json_encode([$code, $b, $po2['text'] ?? null]));
check('à la publication : la promotion relue dans TFBuddy (encore valable : rien à redire)', socVerifierOffres($po2, '4') === []);
$offreRefus = static function (array $patch, string $attendu) use ($fr, $vis, $d3): bool {
    [$code, $b] = $fr('POST', '/franchise/posts', array_merge(['template' => 'fp-produit', 'product' => 'p1', 'fb' => true, 'date' => $d3, 'time' => '12:00', 'visual' => $vis], $patch));
    return $code === 422 && str_contains((string) ($b['error'] ?? ''), $attendu);
};
check('promotion d’une autre boutique (ou inconnue) : refusée', $offreRefus(['offre' => ['genre' => 'promotion', 'id' => 952]], 'plus valable dans votre boutique') && $offreRefus(['offre' => ['genre' => 'promotion', 'id' => 777]], 'plus valable'));
check('promotion qui commence après la publication : refusée', $offreRefus(['offre' => ['genre' => 'promotion', 'id' => 954]], 'ne commence que le'));
check('modèle à remise : garde la sienne (promotion du réseau refusée)', $offreRefus(['template' => 'fp-remise', 'discount' => 20, 'from' => $d3, 'to' => $jj(5), 'offre' => ['genre' => 'promotion', 'id' => 951]], 'sa propre remise'));
$cacheP = socSetting('tfb_cache_promos'); $cacheP['items'] = array_values(array_filter($cacheP['items'], static fn ($x) => $x['id'] !== 950)); socSettingPut('tfb_cache_promos', $cacheP, false);
check('promotion arrêtée dans TFBuddy avant la publication : la publication s’arrête', (bool) array_filter(socVerifierOffres($po2, '4'), static fn ($e) => str_contains($e, 'n’est plus active')));
foreach ([$po, $po2] as $x) { if ($x) { socItemDelete('posts', (string) $x['id']); socUnqueue((string) $x['id']); } }
socSettingPut('tfb_cache_promos', null, false); socSettingPut('tfb_cache_catalogue_4', null, false);
sfReglesPut($regAvant);
unset($_COOKIE[SF_COOKIE]);

echo "Espace franchisé : story seule, post + story, post récurrent\n";
$regAvant = sfRegles(); $igUniqueAvant = socSetting('instagram_unique');
socSettingPut('instagram_unique', false, false);
sfReglesPut(array_merge($regAvant, ['actif' => true, 'quotaSemaine' => 50, 'horizonJours' => 60, 'validation' => 'modele', 'jours' => [0, 1, 2, 3, 4, 5, 6], 'heureMin' => '07:00', 'heureMax' => '20:00', 'fb' => true, 'ig' => true, 'story' => true, 'recurrenceMax' => 8]));
$png = static function (int $w, int $h): string { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 141, 29, 44)); ob_start(); imagepng($im); return 'data:image/png;base64,' . base64_encode((string) ob_get_clean()); };
$visCarre = $png(108, 108); $visStory = $png(108, 192);
sfSessionOuvrir(['4'], 'Test stories', 'code');
$jj = static fn (int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
$base = ['template' => 'fp-produit', 'product' => 'p1', 'fb' => true, 'ig' => true, 'date' => $jj(3), 'time' => '10:00'];
$lignes = static fn (string $id) => array_map(static fn ($r) => $r['shop_id'] . ':' . $r['canal'], Db::rows('SELECT shop_id, canal FROM social_publication WHERE content_id = ? ORDER BY canal', [$id]));
[$code, $b] = $fr('POST', '/franchise/posts', array_merge($base, ['format' => 'story', 'visual' => $visStory]));
$ps = socItem('posts', (string) ($b['post']['id'] ?? ''));
check('story seule : le visuel 9:16 part en story sur la Page et l’Instagram, pas dans le fil', $code === 200 && ($ps['story'] ?? null) === 'seule' && ($b['post']['story'] ?? null) === 'seule'
    && $lignes($ps['id']) === ['4:fb_story', '4:ig_story'], json_encode([$code, $b, $ps ? $lignes($ps['id']) : null]));
Db::exec("UPDATE social_publication SET prevue_le = NOW() - INTERVAL 1 MINUTE WHERE content_id = ?", [$ps['id']]);
$appels = [];
socRunQueue($ps['id']);
$photoStory = array_values(array_filter($appels, static fn ($a) => $a[0] === 'POST' && $a[1] === '111/photos'));
$fbStory = array_values(array_filter($appels, static fn ($a) => $a[0] === 'POST' && $a[1] === '111/photo_stories'));
$igStory = array_values(array_filter($appels, static fn ($a) => $a[0] === 'POST' && $a[1] === '17841/media'));
check('story Facebook : la photo non publiée, puis la story (photo_stories) ; story Instagram : media_type STORIES, puis publication',
    ($photoStory[0][2]['published'] ?? null) === 'false' && !empty($fbStory[0][2]['photo_id']) && ($igStory[0][2]['media_type'] ?? '') === 'STORIES'
    && str_contains((string) ($igStory[0][2]['image_url'] ?? ''), 'uploads/') && !isset($igStory[0][2]['caption'])
    && (bool) array_filter($appels, static fn ($a) => $a[1] === '17841/media_publish') && socItem('posts', $ps['id'])['status'] === 'Publié', json_encode(array_column($appels, 1)));
[$code, $b] = $fr('POST', '/franchise/posts', array_merge($base, ['format' => 'post_story', 'ig' => false, 'visual' => $visCarre, 'visualStory' => $visStory, 'time' => '11:00']));
$pps = socItem('posts', (string) ($b['post']['id'] ?? ''));
check('post + story : le post dans le fil, puis sa story (son propre visuel 9:16, écrit en fichier)', $code === 200 && ($pps['story'] ?? null) === 'aussi'
    && str_starts_with((string) $pps['storyImg'], 'uploads/') && $pps['storyImg'] !== $pps['img'] && $lignes($pps['id']) === ['4:fb', '4:fb_story'], json_encode([$code, $b]));
Db::exec("UPDATE social_publication SET prevue_le = NOW() - INTERVAL 1 MINUTE WHERE content_id = ?", [$pps['id']]);
$appels = [];
socRunQueue($pps['id']);
$ph = array_values(array_filter($appels, static fn ($a) => $a[0] === 'POST' && $a[1] === '111/photos'));
check('post + story : la photo du post (avec son texte), puis la photo de la story (9:16, non publiée)', count($ph) === 2 && isset($ph[0][2]['caption'])
    && str_ends_with((string) $ph[1][2]['url'], basename((string) $pps['storyImg'])) && ($ph[1][2]['published'] ?? null) === 'false', json_encode($ph));
$sfRefus = static function (array $patch, string $attendu) use ($fr, $base, $visStory): bool {
    [$code, $b] = $fr('POST', '/franchise/posts', array_merge($base + ['visual' => $visStory], $patch));
    return $code === 422 && str_contains((string) ($b['error'] ?? ''), $attendu);
};
check('story : un visuel qui n’est pas au format story (9:16) est refusé', $sfRefus(['format' => 'story', 'visual' => $visCarre], 'format story'));
sfReglesPut(array_merge(sfRegles(), ['story' => false]));
check('stories fermées par le réseau : refusées', $sfRefus(['format' => 'story'], 'n’ouvre pas les stories'));
sfReglesPut(array_merge(sfRegles(), ['story' => true]));

[$code, $b] = $fr('POST', '/franchise/posts', array_merge($base, ['format' => 'post', 'ig' => false, 'visual' => $visCarre, 'time' => '12:00', 'recurrence' => ['freq' => 'semaine', 'n' => 3]]));
$serie = array_values(array_filter(socItems('posts'), static fn ($x) => ($x['serie']['id'] ?? '') !== '' && ($x['serie']['id'] ?? '') === ($b['post']['serie']['id'] ?? '-')));
usort($serie, static fn ($a, $c) => strcmp($a['date'], $c['date']));
check('post récurrent : chaque semaine, 3 fois — un post par date, liés par leur série, chacun dans la file', $code === 200 && count($serie) === 3
    && array_column($serie, 'date') === [$jj(3), $jj(10), $jj(17)] && array_column(array_column($serie, 'serie'), 'rang') === [1, 2, 3]
    && !array_filter($serie, static fn ($x) => $lignes($x['id']) !== ['4:fb']), json_encode([$code, $b, array_column($serie, 'date')]));
check('dates d’une série : chaque mois, le même jour (borné à la fin du mois) ; toutes les 2 semaines', sfDatesSerie('2027-01-31', 'mois', 3) === ['2027-01-31', '2027-02-28', '2027-03-31']
    && sfDatesSerie('2027-01-04', '2semaines', 3) === ['2027-01-04', '2027-01-18', '2027-02-01'] && sfDatesSerie('2027-01-04', 'jour', 2) === ['2027-01-04', '2027-01-05']);
check('série au-delà de l’horizon du réseau : refusée', $sfRefus(['format' => 'post', 'visual' => $visCarre, 'time' => '13:00', 'recurrence' => ['freq' => 'semaine', 'n' => 10]], 'Répétez le post de 2 à 8 fois')
    && $sfRefus(['format' => 'post', 'visual' => $visCarre, 'time' => '13:00', 'recurrence' => ['freq' => 'mois', 'n' => 4]], 'au-delà de 60 jours'));
sfReglesPut(array_merge(sfRegles(), ['jours' => [1, 2, 3, 4, 5], 'quotaSemaine' => 50]));
$lundi = date('Y-m-d', strtotime('next monday', strtotime('+2 days')));
check('série sur un jour fermé (le week-end) : refusée avec la date', $sfRefus(['format' => 'post', 'visual' => $visCarre, 'date' => $lundi, 'time' => '13:00', 'recurrence' => ['freq' => 'jour', 'n' => 7]], '(sam.) n’est pas ouvert'));
sfReglesPut(array_merge(sfRegles(), ['jours' => [0, 1, 2, 3, 4, 5, 6], 'quotaSemaine' => 2]));
check('série qui dépasse le quota d’une semaine : refusée', $sfRefus(['format' => 'post', 'visual' => $visCarre, 'date' => $lundi, 'time' => '14:00', 'recurrence' => ['freq' => 'jour', 'n' => 3]], 'Quota atteint'));
sfReglesPut(array_merge(sfRegles(), ['quotaSemaine' => 50]));
check('une promotion datée ne se répète pas', $sfRefus(['template' => 'fp-remise', 'format' => 'post', 'visual' => $visCarre, 'discount' => 20, 'from' => $jj(3), 'to' => $jj(5), 'recurrence' => ['freq' => 'semaine', 'n' => 2]], 'ne se répète pas'));
sfReglesPut(array_merge(sfRegles(), ['recurrenceMax' => 0]));
check('posts récurrents fermés par le réseau : refusés', $sfRefus(['format' => 'post', 'visual' => $visCarre, 'recurrence' => ['freq' => 'semaine', 'n' => 2]], 'n’ouvre pas les posts récurrents'));
sfReglesPut(array_merge(sfRegles(), ['recurrenceMax' => 8]));
[$code, $an] = $fr('POST', '/franchise/posts/' . $serie[1]['id'] . '/annuler', ['shop' => '4']);
check('annuler une date de la série : celle-là seule', $code === 200 && $an['retires'] === 1 && socItem('posts', $serie[1]['id']) === null && socItem('posts', $serie[2]['id']) !== null);
[$code, $an] = $fr('POST', '/franchise/posts/' . $serie[0]['id'] . '/annuler', ['shop' => '4', 'serie' => true]);
check('annuler toute la série : les dates encore à venir', $code === 200 && $an['retires'] === 2 && socItem('posts', $serie[0]['id']) === null && socItem('posts', $serie[2]['id']) === null);
foreach ([$ps, $pps] as $x) { if ($x) { Db::exec('DELETE FROM social_publication WHERE content_id = ?', [$x['id']]); socItemDelete('posts', (string) $x['id']); } }
sfReglesPut($regAvant); socSettingPut('instagram_unique', $igUniqueAvant, false);
unset($_COOKIE[SF_COOKIE]);

echo "API HTTP (routeur)\n";
[$code, $b] = socRoute('GET', '/state');
check('GET /state', $code === 200 && $b['mode'] === 'live' && count($b['shops']) === 2);
check('GET /state ne contient aucune clé', !str_contains(json_encode($b), 'TOK-DB') && !str_contains(json_encode($b), 'SECRET'));
check('journal : activité tracée, miroir dans le journal du cockpit', count($b['activity']) > 3 && (int) Db::row("SELECT COUNT(*) n FROM ceo_journal_entry WHERE kind = 'Social & Ads'")['n'] > 3);
[$code, $b] = (static function () { try { return socRoute('GET', '/inconnue'); } catch (HttpError $e) { return [$e->status, null]; } })();
check('route inconnue → 404', $code === 404);

echo "Authentification partagée avec le cockpit\n";
Db::exec("INSERT INTO ceo_app_setting VALUES ('authSecret', ?), ('authPasswordHash', ?)", [json_encode('S3CR3T'), json_encode(password_hash('x', PASSWORD_DEFAULT))]);
$exp = (string) (time() + 3600);
$_COOKIE['cockpit_s'] = $exp . '.' . hash_hmac('sha256', $exp, 'S3CR3T');
check('cookie du cockpit valide accepté', socAuthCheck(true));
$_COOKIE['cockpit_s'] = $exp . '.faux';
check('cookie falsifié refusé', !socAuthCheck(true));
unset($_COOKIE['cockpit_s']);
check('sans cookie : refusé', !socAuthCheck(true));

echo "\n$ok ok, $ko échec(s)\n";
exec('rm -rf ' . escapeshellarg($tmp));
exit($ko === 0 ? 0 : 1);
