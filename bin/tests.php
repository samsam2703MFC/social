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
Meta::$sleep = static function (int $s): void {};
Meta::$transport = static function (string $m, string $url, array $p) use (&$appels, &$restreinte, &$n): array {
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
    if ($path === '17841/media') { return [200, ['id' => 'c' . $id()]]; }
    if ($path === '17841/media_publish') { return [200, ['id' => 'ig' . $id()]]; }
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
