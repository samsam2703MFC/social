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
