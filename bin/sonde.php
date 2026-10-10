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

$shop = (string) (socApiShops()[0]['id'] ?? '4');
$jour = date('Y-m-d');
$routes = [
    '/products', '/products/aliases', "/shops/$shop/products/available", '/product-availability-periods',
    '/product-categories', '/marketing-campaigns', '/marketing-materials', "/shops/$shop/marketing-materials",
    "/shops/$shop/statistics/sales/product-category-groups", "/shops/$shop/statistics/sales/kpis",
    "/shops/$shop/transactions", "/shops/$shop/statistics/daily-summary", "/shops/$shop/statistics/monthly-summary",
    '/owner/network/sales/trend', '/owner/network/dashboard/today', "/shops/$shop/owner/dashboard/today",
    '/consultant/shops/sales-kpis/quarterly', "/shops/$shop/statistics/sales/compare-to-last-week/$jour",
    '/shops', "/shops/$shop", '/consultant/shops',
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
    if (preg_match('/^\+?[\d .\/-]{8,}$/', $v)) { return '…tél…'; }
    return mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v;
};
$forme = static function (mixed $x, int $prof = 0) use (&$forme, $masque): mixed {
    if (!is_array($x)) { return $masque($x); }
    if ($prof > 2) { return '[' . (array_is_list($x) ? count($x) . ' él.' : 'objet') . ']'; }
    if (array_is_list($x)) { return ['_n' => count($x), '_premier' => isset($x[0]) ? $forme($x[0], $prof + 1) : null]; }
    $o = [];
    foreach (array_slice($x, 0, 40, true) as $k => $v) { $o[$k] = $forme($v, $prof + 1); }
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
        echo json_encode($forme($j), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    } catch (Throwable $e) {
        echo 'ERREUR : ' . $e->getMessage() . "\n";
    }
}
