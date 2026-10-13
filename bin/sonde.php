<?php
declare(strict_types=1);

/**
 * Sonde de l'API TFBuddy (lecture seule) — lancée par le déploiement quand
 * deploy.trigger contient le mot « sonde ». Elle imprime, pour les routes
 * dont le module a besoin, les paramètres du swagger et la FORME de la
 * réponse (clés, types, valeurs courtes) — de quoi brancher le module sur
 * les bons champs. Courriels et téléphones masqués. Rien n'est écrit.
 */
require __DIR__ . '/../src/api.php';
socInstall();

// Meta (lecture seule) : quelles métriques d'abonnés la Page et l'Instagram acceptent-ils aujourd'hui ?
try {
    echo "== Meta : métriques d'abonnés ==\n";
    $toks = Meta::pageTokens();
    $page = (string) (array_values(array_filter(array_map(static fn ($s) => (string) ($s['ids']['page_id'] ?? ''), socShops())))[0] ?? array_key_first($toks));
    $tok = $toks[$page]['token'] ?? null;
    $p0 = ['period' => 'day', 'since' => date('Y-m-d', strtotime('-8 days')), 'until' => date('Y-m-d')];
    foreach (['page_follows', 'page_daily_follows', 'page_daily_follows_unique', 'page_daily_unfollows_unique', 'page_fan_adds_unique', 'page_fans', 'page_fan_adds', 'page_impressions_unique', 'page_media_view'] as $m) {
        try {
            $r = Meta::call('GET', $page . '/insights', ['metric' => $m] + $p0, $tok);
            $v = (array) ($r['data'][0]['values'] ?? []);
            echo "  page $m : OK, " . count($v) . ' valeur(s)' . ($v ? ', dernière = ' . json_encode(end($v)['value'] ?? null) : '') . "\n";
        } catch (MetaError $e) { echo "  page $m : refus (" . $e->metaCode . ') ' . mb_substr($e->getMessage(), 0, 160) . "\n"; }
    }
    $ig = (string) (socBrand()['ids']['instagram_user_id'] ?? '');
    if ($ig !== '') {
        $it = socIgToken($ig, (string) socBrand()['ids']['page_id']);
        foreach (['follower_count', 'reach'] as $m) {
            try {
                $r = Meta::call('GET', $ig . '/insights', ['metric' => $m, 'period' => 'day', 'since' => strtotime('-8 days'), 'until' => time()], $it);
                echo "  instagram $m : OK, " . count((array) ($r['data'][0]['values'] ?? [])) . " valeur(s)\n";
            } catch (MetaError $e) { echo "  instagram $m : refus (" . $e->metaCode . ') ' . mb_substr($e->getMessage(), 0, 160) . "\n"; }
        }
    }
} catch (Throwable $e) { echo "  Meta : " . $e->getMessage() . "\n"; }

// Meta (lecture seule) : quel accès aux publications des Pages le token permet-il ?
try {
    echo "== Meta : publications des Pages ==\n";
    $q = ['since' => strtotime('-30 days'), 'until' => time(), 'limit' => 5];
    foreach (Meta::pageTokens() as $pid => $pg) {
        foreach ([['published_posts', SOC_CAL_FB], ['published_posts', SOC_CAL_FB_MIN], ['posts', SOC_CAL_FB_MIN], ['feed', SOC_CAL_FB_MIN], ['published_posts', 'id,created_time'], ['scheduled_posts', 'id,scheduled_publish_time']] as [$edge, $f]) {
            try {
                $r = Meta::call('GET', $pid . '/' . $edge, ['fields' => $f] + ($edge === 'scheduled_posts' ? ['limit' => 5] : $q), $pg['token']);
                echo '  ' . $pg['name'] . " · $edge (" . (strlen($f) > 30 ? 'champs complets' : $f) . ') : OK, ' . count((array) ($r['data'] ?? [])) . " publication(s)\n";
            } catch (MetaError $e) { echo '  ' . $pg['name'] . " · $edge (" . (strlen($f) > 30 ? 'champs complets' : $f) . ') : refus (' . $e->metaCode . ') ' . mb_substr($e->getMessage(), 0, 110) . "\n"; }
        }
    }
} catch (Throwable $e) { echo "  Meta : " . $e->getMessage() . "\n"; }

// Marketing du BO (lecture seule) : les opérations sont-elles lisibles par son API ?
try {
    echo "== Marketing du BO : opérations ==\n";
    foreach (BoMarketing::candidates() as $b) { echo '  candidate : ' . $b . "\n"; }
    $ops = socOperations(true);
    echo '  adresse retenue : ' . BoMarketing::base() . ' · ' . count($ops) . ' opération(s)' . (socOperationsErreur() ? ' · ERREUR ' . socOperationsErreur() : '') . "\n";
    foreach (array_slice($ops, 0, 8) as $o) {
        echo '  ' . $o['id'] . ' · ' . $o['name'] . ' · ' . ($o['start'] ?? '?') . ' → ' . ($o['end'] ?? '…') . ' · ' . $o['status'] . ' (' . $o['boStatus'] . ') · boutiques ' . implode(',', $o['shops'])
            . ' · produits ' . count($o['products']) . ' · budget ' . $o['budget'] . "\n";
    }
} catch (Throwable $e) { echo '  Marketing : ' . $e->getMessage() . "\n"; }

// Boutiques (lecture seule) : d'où vient la liste, et pourquoi une boutique (ex. un magasin de test) n'y est pas.
try {
    echo "== Boutiques : sources de la liste ==\n";
    echo '  module : ' . implode(', ', array_map(static fn ($s) => $s['id'] . ' ' . $s['name'], socShops())) . "\n";
    echo '  TFBuddy (API) : ' . implode(', ', array_map(static fn ($a) => $a['id'] . ' ' . $a['name'] . (array_key_exists('active', $a) ? ($a['active'] ? '' : ' (inactive)') : '') . ' [' . ($a['source'] ?? '') . ']', socApiShops(true))) . "\n";
    if (Db::hasTable('ceo_shop')) {
        foreach (Db::rows('SELECT id, code, name, status, pwa_shop_id FROM ceo_shop ORDER BY name') as $r) {
            echo '  cockpit ceo_shop : #' . $r['id'] . ' ' . $r['name'] . ' · code ' . $r['code'] . ' · statut ' . $r['status'] . ' · caisse ' . ($r['pwa_shop_id'] ?? '—') . "\n";
        }
    }
    if (Db::hasTable('brand_guard_pages')) {
        foreach (Db::rows('SELECT id, shop_id, boutique, page_id, actif FROM brand_guard_pages ORDER BY id') as $r) {
            echo '  brand_guard_pages : #' . $r['id'] . ' ' . $r['boutique'] . ' · page ' . $r['page_id'] . ' · shop ' . ($r['shop_id'] ?? '—') . ' · actif ' . $r['actif'] . "\n";
        }
    }
    foreach (Meta::pageTokens(true) as $pid => $pg) { echo '  Page du token : ' . $pid . ' · ' . $pg['name'] . ' · IG ' . ($pg['ig'] !== '' ? $pg['ig'] : '—') . "\n"; }
    $pf = socSetting('meta_portefeuille');
    echo '  portefeuille Meta (cache) : ' . (is_array($pf) ? 'relu le ' . ($pf['le'] ?? '?') . ' · ' . implode(', ', array_map(static fn ($p) => $p['id'] . ' ' . $p['name'] . ' → ' . ($p['boutique'] ?? '—'), (array) ($pf['pages'] ?? []))) : 'absent') . "\n";
    echo '  boutiques hors caisse : ' . (implode(', ', array_map(static fn ($s) => $s['id'] . ' ' . $s['name'] . ' (Page ' . $s['ids']['page_id'] . ', FB ' . $s['fb'] . ')', array_filter(socShops(), static fn ($s) => !empty($s['horsCaisse'])))) ?: 'aucune') . "\n";
} catch (Throwable $e) { echo '  Boutiques : ' . $e->getMessage() . "\n"; }

// Module Marketing (/marketing/, lecture seule) : sur quelle base est-il branché, et que dit
// son registre des migrations ? (son déploiement bloque sur « tables existantes, registre vide »).
try {
    echo "== Module Marketing : base et registre des migrations ==\n";
    $ptr = '/var/www/html/marketing/api/.env-path';
    $envf = is_readable($ptr) ? trim((string) file_get_contents($ptr)) : '';
    echo '  pointeur api/.env-path : ' . ($envf !== '' ? $envf : 'absent ou illisible') . "\n";
    foreach (glob('/var/www/private/marketing/.env*') ?: [] as $f) { echo '  fichier : ' . $f . (is_readable($f) ? '' : ' (illisible)') . "\n"; }
    $env = [];
    if ($envf !== '' && is_readable($envf)) {
        foreach ((array) file($envf) as $l) { if (preg_match('/^\s*(MAR_DB_\w+)\s*=\s*(.*)$/', (string) $l, $m)) { $env[$m[1]] = trim($m[2], " \t\r\n\"'"); } }
    }
    echo '  MAR_DB_NAME=' . ($env['MAR_DB_NAME'] ?? '?') . ' · MAR_DB_HOST=' . ($env['MAR_DB_HOST'] ?? '(défaut)') . ' · socket ' . (($env['MAR_DB_SOCKET'] ?? '') !== '' ? 'oui' : 'non') . "\n";
    if (($env['MAR_DB_NAME'] ?? '') !== '') {
        $dsn = ($env['MAR_DB_SOCKET'] ?? '') !== ''
            ? 'mysql:unix_socket=' . $env['MAR_DB_SOCKET'] . ';dbname=' . $env['MAR_DB_NAME'] . ';charset=utf8mb4'
            : 'mysql:host=' . ($env['MAR_DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['MAR_DB_PORT'] ?? '3306') . ';dbname=' . $env['MAR_DB_NAME'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $env['MAR_DB_USER'] ?? 'root', $env['MAR_DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'mar\\_%' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        echo '  base ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . ' : ' . count($tables) . ' tables mar_* · ' . implode(',', array_slice($tables, 0, 60)) . "\n";
        if (in_array('mar_schema_migration', $tables, true)) {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM mar_schema_migration')->fetchColumn();
            echo '  registre : ' . $n . ' ligne(s)' . ($n ? ' · dernière ' . $pdo->query('SELECT filename FROM mar_schema_migration ORDER BY id DESC LIMIT 1')->fetchColumn() : '') . "\n";
        }
        $col = static function (string $t, string $c) use ($pdo): string {
            $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $q->execute([$t, $c]);
            return (int) $q->fetchColumn() ? 'oui' : 'non';
        };
        echo '  031 mar_campaign_type.color_hex : ' . $col('mar_campaign_type', 'color_hex')
            . ' · 032 mar_campaign.show_web_shop : ' . $col('mar_campaign', 'show_web_shop')
            . ' · 033 mar_campaign_offer_item.image_url : ' . $col('mar_campaign_offer_item', 'image_url')
            . ' · 034 mar_fund_movement.base_amount (doit être absent) : ' . $col('mar_fund_movement', 'base_amount')
            . ' · mar_royalty_rate : ' . (in_array('mar_royalty_rate', $tables, true) ? 'présente' : 'absente') . "\n";
        echo '  campagnes : ' . (int) $pdo->query('SELECT COUNT(*) FROM mar_campaign')->fetchColumn() . "\n";
    }
} catch (Throwable $e) { echo '  Marketing (module) : ' . $e->getMessage() . "\n"; }

// Meta (lecture seule) : les vues d'une publication récente de chaque compte sont-elles lisibles ?
try {
    echo "== Meta : vues des publications (fiche du calendrier) ==\n";
    foreach (socCalComptes() as $c) {
        if ($c['token'] === null) { continue; }
        try {
            $r = $c['type'] === 'facebook'
                ? Meta::call('GET', $c['id'] . '/published_posts', ['fields' => 'id', 'limit' => 1], $c['token'])
                : Meta::call('GET', $c['id'] . '/media', ['fields' => 'id', 'limit' => 1], $c['token']);
            $pid = (string) ($r['data'][0]['id'] ?? '');
            if ($pid === '') { echo '  ' . $c['key'] . " : aucune publication\n"; continue; }
            $st = socCalStats(($c['type'] === 'facebook' ? 'mfb-' : 'mig-') . $pid, $c['key']);
            foreach ($c['type'] === 'facebook' ? [['post_media_view', 'lifetime'], ['post_impressions', 'lifetime'], ['post_reactions_by_type_total', 'lifetime']] : [['views', null], ['reach', null]] as [$m, $per]) {
                try { $raw = Meta::call('GET', $pid . '/insights', ['metric' => $m] + ($per ? ['period' => $per] : []), $c['token']);
                    echo '    brut ' . $m . ' : ' . mb_substr(json_encode($raw['data'] ?? $raw, JSON_UNESCAPED_UNICODE), 0, 160) . "\n"; }
                catch (MetaError $e) { echo '    brut ' . $m . ' : refus (' . $e->metaCode . ') ' . mb_substr($e->getMessage(), 0, 120) . "\n"; }
            }
            echo '  ' . $c['key'] . ' (' . $c['name'] . ') : vues=' . json_encode($st['vues']) . ' personnes=' . json_encode($st['personnes']) . ' réactions=' . json_encode($st['reactions'])
                . ($st['motif'] ? ' · ' . mb_substr((string) $st['motif'], 0, 140) : '') . "\n";
        } catch (Throwable $e) { echo '  ' . $c['key'] . ' : ' . mb_substr($e->getMessage(), 0, 140) . "\n"; }
    }
} catch (Throwable $e) { echo "  Meta : " . $e->getMessage() . "\n"; }

$shop = (string) (socApiShops()[0]['id'] ?? '4');
$jour = date('Y-m-d');
$j7 = date('Y-m-d', strtotime('-7 days')); $j1 = date('Y-m-d', strtotime('-1 day'));
$routes = [
    "/shops/$shop/statistics/sales/product-category-groups?date=$j1",
    "/shops/$shop/statistics/sales/product-category-groups?date_from=$j7&date_to=$j1",
    "/shops/$shop/statistics/sales/product-category-groups?from=$j7&to=$j1",
    "/shops/$shop/statistics/sales/product-category-groups?start_date=$j7&end_date=$j1",
    "/shops/$shop/statistics/sales/kpis?date_from=$j7&date_to=$j1",
    "/shops/$shop/statistics/sales/kpis?date=$j1",
    "/consultant/shops/category-sales?date_from=$j7&date_to=$j1&shop_ids=$shop",
    "/consultant/shops/sales-kpis?date_from=$j7&date_to=$j1&shop_ids=$shop",
    "/shops/$shop/statistics/daily-summary?date=$j1",
    "/products/aliases?lang_code=nl",
    "/shops/$shop/products/available?lang_code=nl",
    '/product-availability-periods?lang_code=nl',
];

[$c, $corps] = Tfb::raw('GET', Tfb::swaggerUrl(), [], null, 60);
$spec = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) $corps), true) ?: [];
$ref = static function (array $p) use ($spec): array {
    if (isset($p['$ref'])) { $k = basename($p['$ref']); return (array) ($spec['components']['parameters'][$k] ?? ['$ref' => $p['$ref']]); }
    return $p;
};

$masque = static function (mixed $v): mixed {
    if (!is_string($v)) { return $v; }
    if (str_contains($v, '@')) { return '…@…'; }
    if (preg_match('/^\+?[\d .\/]{8,}$/', $v) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) { return '…tél…'; }
    return mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v;
};
// Champs sensibles masqués PAR LEUR NOM, quelle que soit la valeur : l'API rend
// par exemple `email_password` en clair et les coordonnées bancaires des boutiques.
$sensible = static fn (string $k): bool => (bool) preg_match('/pass|secret|token|iban|bank|swift|account_number|tax_number|vat|peppol|email|phone|billing/i', $k);
$forme = static function (mixed $x, int $prof = 0) use (&$forme, $masque, $sensible): mixed {
    if (!is_array($x)) { return $masque($x); }
    if ($prof > 2) { return '[' . (array_is_list($x) ? count($x) . ' él.' : 'objet') . ']'; }
    if (array_is_list($x)) { return ['_n' => count($x), '_premier' => isset($x[0]) ? $forme($x[0], $prof + 1) : null]; }
    $o = [];
    foreach (array_slice($x, 0, 40, true) as $k => $v) { $o[$k] = $sensible((string) $k) ? '«masqué»' : $forme($v, $prof + 1); }
    return $o;
};

foreach ($routes as $r) {
    $cle = '/api/v1' . preg_replace(['#/shops/' . preg_quote($shop, '#') . '(/|$)#', '#/\d{4}-\d{2}-\d{2}$#'], ['/shops/{param1}$1', '/{param2}'], $r);
    $op = null;
    foreach (array_keys($spec['paths'] ?? []) as $p) {
        if (preg_replace('/\{[^}]+\}/', '{}', $p) === preg_replace('/\{[^}]+\}/', '{}', $cle)) { $op = $spec['paths'][$p]['get'] ?? null; break; }
    }
    echo "\n===== GET $r\n";
    if ($op) {
        $ps = array_map($ref, (array) ($op['parameters'] ?? []));
        echo 'paramètres : ' . json_encode(array_map(static fn ($p) => ($p['in'] ?? '?') . ':' . ($p['name'] ?? '?') . (!empty($p['required']) ? '*' : ''), $ps), JSON_UNESCAPED_UNICODE) . "\n";
        if (!empty($op['summary']) || !empty($op['description'])) { echo 'doc : ' . mb_substr(trim(($op['summary'] ?? '') . ' ' . ($op['description'] ?? '')), 0, 300) . "\n"; }
    }
    $realm = str_starts_with($r, '/consultant/') ? 'consultant' : 'admin';
    try {
        $j = Tfb::get($r, $realm);
        $l = Tfb::liste($j);
        if ($l && isset($l[0]['sales_value'])) {
            echo 'empreinte : ' . count($l) . ' lignes, ventes = ' . round(array_sum(array_map(static fn ($x) => (float) $x['sales_value'], $l)), 2)
                . ' €, qté = ' . array_sum(array_map(static fn ($x) => (float) $x['sold_qty'], $l)) . "\n";
        }
        echo mb_substr(json_encode($forme($j), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, 2500) . "\n";
    } catch (Throwable $e) {
        echo 'ERREUR : ' . $e->getMessage() . "\n";
    }
}
