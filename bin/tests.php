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
    if ($m === 'GET' && $path === 'me/accounts') {
        return [200, ['data' => [['id' => '111', 'name' => 'L\'Atelier By Halle', 'access_token' => 'PT111', 'instagram_business_account' => ['id' => '17841']],
                                 ['id' => '222', 'name' => 'L\'Atelier By Wavre', 'access_token' => 'PT222']]]];
    }
    if (preg_match('#^(111|222)/(photos|feed|videos)$#', $path, $mm)) {
        if (isset($restreinte[$mm[1]])) { return [400, ['error' => ['code' => 368, 'message' => 'Page restreinte']]]; }
        return [200, $mm[2] === 'photos' ? ['id' => $id(), 'post_id' => $mm[1] . '_' . $id()] : ['id' => $mm[1] . '_' . $id()]];
    }
    if ($m === 'GET' && $path === '17841') { return [200, ['id' => '17841', 'username' => 'latelierby.halle']]; }
    if ($m === 'POST' && $path === '17841/media') { return [200, ['id' => 'c' . $id()]]; }
    if ($path === '17841/media_publish') {
        if ($igPanne > 0 && ++$igPub === $igPanne) { return [400, ['error' => ['code' => 9007, 'message' => 'Media ID is not available']]]; }
        return [200, ['id' => 'ig' . $id()]];
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
    if ($m === 'GET' && preg_match('#^act_\d+$#', $path)) { return [200, ['name' => 'Compte ' . $path, 'account_status' => 1]]; }
    if ($m === 'GET' && $path === 'search') { return [200, ['data' => ($p['type'] ?? '') === 'adlocale' ? [['key' => 9, 'name' => 'French (All)'], ['key' => 14, 'name' => 'Dutch']] : [['id' => '6003107902', 'name' => $p['q']]]]]; }
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
    if ($p === '/api/v1/owner/auth/login') {
        $b = json_decode((string) $body, true);
        if (($b['password'] ?? '') !== 'ok') { return [401, '{"status":"error","description":"Invalid credentials"}']; }
        $claims = rtrim(strtr(base64_encode(json_encode(['name' => 'Nathan Charlot', 'scope_type' => 'shop', 'scope_id' => 4])), '+/', '-_'), '=');
        return [200, json_encode(['access_token' => 'h.' . $claims . '.s', 'refresh_token' => 'r', 'token_type' => 'Bearer', 'session_id' => 1, 'expires_in' => 1800])];
    }
    if ($p === '/api/v1/owner/shops') {
        $ok = (bool) array_filter($h, static fn ($x) => str_starts_with($x, 'Authorization: Bearer h.'));
        return $ok ? [200, json_encode(['data' => [['id' => 4, 'name' => 'Atelier by - Halle'], ['id' => 99, 'name' => 'Hors réseau']]])] : [401, '{}'];
    }
    if ($p === '/api/swagger/openapi.json') {
        return [200, json_encode(['openapi' => '3.0.0', 'paths' => [
            '/api/v1/public/shops' => ['get' => []], '/api/v1/shops' => ['get' => ['tags' => ['Franchise shop']]],
            '/api/v1/shops/{id}' => ['get' => []], '/api/v1/products' => ['get' => []], '/api/v1/consultant/shops' => ['get' => []],
            '/api/v1/recipes/{param1}' => ['get' => []], '/api/v1/franchisee-shop/{id}/transactions/{date}' => ['get' => []],
            '/api/v1/broken' => ['get' => []], '/api/v1/consultant/shops/sales-kpis' => ['get' => []], '/api/v1/shops/{id}/x' => ['post' => []]]])];
    }
    if (!$auth) { return [401, '{"message":"Unauthenticated"}']; }
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
$camp = ['id' => 'c1', 'name' => 'Automne', 'type' => 'saison', 'start' => $debutCamp, 'end' => date('Y-m-d', strtotime('+20 days')), 'status' => 'en cours',
    'shops' => ['4', '5'], 'products' => ['Brioche croustillante'], 'goal' => ['metric' => 'CA additionnel', 'value' => 1000], 'budget' => 400, 'split' => ['4' => 200, '5' => 200]];
socStateSave(['changes' => ['campaigns' => ['upsert' => [$camp]]]]);
check('campagne dans social_campagne', Db::row("SELECT nom, budget FROM social_campagne WHERE id = 'c1'") == ['nom' => 'Automne', 'budget' => '400.00']);
$spec = ['name' => 'Automne trafic', 'objective' => 'OUTCOME_TRAFFIC', 'status' => 'ACTIVE', 'special' => [], 'bid' => 'LOWEST_COST_WITHOUT_CAP', 'spendCap' => 300,
    'budgetLevel' => 'adset', 'budgetType' => 'daily', 'amount' => 10, 'account' => 'boutique', 'shops' => ['4', '5'], 'radius' => ['4' => 8, '5' => 10],
    'goal' => 'LINK_CLICKS', 'dest' => 'WEBSITE', 'start' => date('Y-m-d'), 'end' => date('Y-m-d', strtotime('+20 days')), 'ageMin' => 18, 'ageMax' => 65, 'gender' => 'all',
    'interests' => ['Boulangerie'], 'platforms' => ['facebook', 'instagram'], 'advantage' => false, 'dsaB' => "L'Atelier By SRL", 'dsaP' => "L'Atelier By SRL",
    'crea' => 'existing', 'post' => 'p1', 'cta' => 'LEARN_MORE', 'link' => 'https://www.latelierby.be', 'adName' => 'Automne', 'adStatus' => 'ACTIVE', 'pixel' => false, 'urlTags' => 'utm_source=meta'];
$appels = [];
socStateSave(['changes' => ['ads' => ['upsert' => [['id' => 'a1', 'name' => 'Automne trafic', 'campaign' => 'c1', 'objective' => 'Trafic', 'shops' => ['4', '5'],
    'scope' => 'boutique', 'budgetType' => 'quotidien', 'budget' => 20, 'cap' => 300, 'spent' => 0, 'start' => $spec['start'], 'end' => $spec['end'], 'status' => 'En revue', 'radius' => 9, 'post' => 'p1', 'spec' => $spec]]]]]);
$a1 = socItem('ads', 'a1');
check('pub créée chez Meta → En revue', $a1['status'] === 'En revue', (string) ($a1['reason'] ?? ''));
check('un jeu par compte boutique (2 campagnes)', count($appelsVers('#^act_\d+/campaigns$#')) === 2);
check('Halle bilingue : 2 ad sets (NL, FR) avec locales', count(array_filter($appelsVers('#^act_1001/adsets$#'), static fn ($a) => str_contains($a[2]['targeting'], '"locales"'))) === 2);
check('montants en centimes (10 € → 1000 par jour)', str_contains(json_encode($appelsVers('#^act_1002/adsets$#')[0][2] ?? []), '"daily_budget":1000'));
check('rayon en km sur la position de la boutique', str_contains($appelsVers('#^act_1002/adsets$#')[0][2]['targeting'] ?? '', '"radius":10'));
check('post sponsorisé : object_story_id du post publié', preg_match('/^111_\d+$/', (string) ($appelsVers('#^act_1001/adcreatives$#')[0][2]['object_story_id'] ?? '')) === 1);
check('intérêt résolu par Targeting Search', str_contains($appelsVers('#^act_1002/adsets$#')[0][2]['targeting'] ?? '', '6003107902'));
check('objets Meta notés', (int) Db::row("SELECT COUNT(*) n FROM social_meta_objet WHERE kind = 'ad' AND local_id = 'a1' AND ad_id IS NOT NULL")['n'] === 3);
$appels = [];
$a1['status'] = 'En pause';
socStateSave(['changes' => ['ads' => ['upsert' => [$a1]]]]);
check('pause → status=PAUSED sur chaque pub', count(array_filter($appels, static fn ($a) => ($a[2]['status'] ?? '') === 'PAUSED')) === 3);
socAdsSync();
check('synchro : statut Meta ACTIVE, dépense relue', socItem('ads', 'a1')['status'] === 'Active' && (float) socItem('ads', 'a1')['spent'] === 121.5);
check('dépense en colonne', Db::row("SELECT depense FROM social_publicite WHERE id = 'a1'")['depense'] === '121.50');
socStateSave(['changes' => ['ads' => ['upsert' => [['id' => 'a2', 'name' => 'Sans spec', 'shops' => ['5'], 'status' => 'En revue']]]]]);
check('pub sans description d’assistant : refusée avec motif', socItem('ads', 'a2')['status'] === 'Refusée');

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
[$code] = $fr('GET', '/franchise/etat');
check('sans session : 401', $code === 401);
[$code, $acc] = $fr('POST', '/franchise/admin/acces', ['shop' => '4', 'label' => 'Nathan']);
check('code d’accès créé (rendu une fois, empreinte seule en base)', $code === 200 && preg_match('/^ATB-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $acc['code']) === 1
    && !str_contains(json_encode(Db::rows('SELECT * FROM social_acces_franchise')), $acc['code']));
[$code] = $fr('POST', '/franchise/connexion', ['code' => 'ATB-AAAAA-BBBBB']);
check('code inconnu : 401', $code === 401);
[$code, $b] = $fr('POST', '/franchise/connexion', ['code' => strtolower($acc['code'])]);
check('connexion par code : session limitée à Halle', $code === 200 && $b['shops'] === ['4']);
[$code, $et] = $fr('GET', '/franchise/etat');
$ids = array_column($et['posts'] ?? [], 'id');
check('état : sa boutique, son catalogue (API, sans B2B ni inactif), les campagnes où elle figure', $code === 200 && $et['shop']['name'] === 'Halle'
    && array_column($et['catalogue'], 'id') === ['p1'] && $et['catalogue'][0]['nameNl'] === 'Krokante brioche' && in_array('c1', array_column($et['campaigns'], 'id'), true), json_encode($et['error'] ?? ''));
check('état : posts du réseau qui la concernent, jamais les dark posts ni ceux des autres boutiques', in_array('p1', $ids, true) && !in_array('d1', $ids, true) && !in_array('p2', $ids, true));
[$code] = $fr('GET', '/franchise/etat', [], ['shop' => '5']);
check('une autre boutique : 403', $code === 403);

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
    && Db::rows('SELECT canal FROM social_publication WHERE content_id = ?', [$pp['id']]) === [['canal' => 'fb']] && $b['quota']['used'] === 2);
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

echo "Espace franchisé : compte atelierby, aperçu, fermeture\n";
unset($_COOKIE[SF_COOKIE]);
[$code] = $fr('POST', '/franchise/connexion', ['phone' => '0499', 'password' => 'faux']);
check('compte atelierby refusé : 401', $code === 401);
[$code, $b] = $fr('POST', '/franchise/connexion', ['phone' => '0499', 'password' => 'ok']);
check('compte atelierby (owner) : ses boutiques du réseau seulement', $code === 200 && $b['shops'] === ['4'] && $b['name'] === 'Nathan Charlot', json_encode($b));
[$code, $b] = $fr('POST', '/franchise/admin/apercu', ['shop' => '5']);
check('aperçu franchiseur : session sur la boutique choisie', $code === 200 && sfSession()['shops'] === ['5'] && sfSession()['via'] === 'apercu');
$fr('POST', '/franchise/admin/regles', ['rules' => array_merge(sfRegles(), ['actif' => false])]);
unset($_COOKIE[SF_COOKIE]);
[$code] = $fr('POST', '/franchise/connexion', ['code' => $acc['code']]);
check('espace fermé par le franchiseur : connexion refusée', $code === 403);
$fr('POST', '/franchise/admin/regles', ['rules' => array_merge(sfRegles(), ['actif' => true, 'validation' => 'modele', 'quotaSemaine' => 3])]);
[$code, $b] = $fr('POST', '/franchise/admin/acces/' . $fr('GET', '/franchise/admin')[1]['access'][0]['id'] . '/revoquer');
[$code2] = $fr('POST', '/franchise/connexion', ['code' => $acc['code']]);
check('code révoqué : refusé', $code === 200 && $code2 === 401);
unset($_COOKIE[SF_COOKIE]);

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
$h = $res['c1']['4'] ?? [];
check('dépense Meta par boutique', (float) ($h['spend'] ?? 0) === 81.0, json_encode($h));
check('CA campagne lu dans la caisse (7 jours × 25 €)', (float) ($h['caCamp'] ?? 0) === 175.0, (string) ($h['caCamp'] ?? 'null'));
check('référence = moyenne des 6 semaines précédentes (7 × 10 €)', (float) ($h['caRef'] ?? 0) === 70.0, (string) ($h['caRef'] ?? 'null'));
check('Wavre sans vente : motif affiché', isset($res['c1']['5']['noSales']));
check('résultat en colonnes', Db::row("SELECT ca_campagne FROM social_resultat WHERE campagne_id = 'c1' AND shop_id = '4'")['ca_campagne'] === '175.00');

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
socResultsSync('c1');
check('retour sur campagne : l’API prime sur la base', (socResults()['c1']['4']['source'] ?? '') === 'API TFBuddy', json_encode(socResults()['c1']['4'] ?? null));
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
