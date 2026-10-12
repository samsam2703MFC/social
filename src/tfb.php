<?php
declare(strict_types=1);

/**
 * L'API TFBuddy (atelierby.tfbuddy.com, swagger : /api/swagger/openapi.json) —
 * la SOURCE PRINCIPALE des données du module. Le module doit pouvoir être
 * déplacé sur un autre serveur : tout ce que l'API sait (boutiques, adresses,
 * horaires, produits, prix, ventes) est lu chez elle ; la base partagée avec le
 * BO n'est plus qu'un repli.
 *
 * Deux réalms, deux comptes :
 *  - admin      : POST /admin/auth/login {phone, password}       (ERP)
 *  - consultant : POST /consultant/auth/login {phone, password}  (panel)
 *
 * Tout vit dans `social_parametre` : base (tfb_base), identifiants
 * (tfb_admin_phone / tfb_admin_password, tfb_consultant_phone /
 * tfb_consultant_password — mots de passe secrets) et jetons en cache
 * (tfb_jeton_admin, tfb_jeton_consultant, secrets). Au premier lancement, les
 * comptes du BO (réglages erpAdmin / panelApi) y sont recopiés.
 */
final class TfbError extends RuntimeException
{
    public function __construct(string $m, public int $http = 0) { parent::__construct($m); }
}

final class Tfb
{
    /** @var null|callable(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    public static $transport = null;
    private static array $jetons = [];

    public static function base(): string
    {
        return rtrim((string) (socSetting('tfb_base') ?: 'https://atelierby.tfbuddy.com/api/v1'), '/');
    }

    /** L'URL du swagger : {hôte}/api/swagger/openapi.json. */
    public static function swaggerUrl(): string
    {
        return preg_replace('#/api/v\d+$#', '', self::base()) . '/api/swagger/openapi.json';
    }

    public static function configured(string $realm = 'admin'): bool
    {
        return trim((string) socSetting('tfb_' . $realm . '_phone', '')) !== '' && trim((string) socSetting('tfb_' . $realm . '_password', '')) !== '';
    }

    public static function statut(): array
    {
        return ['base' => self::base(), 'swagger' => self::swaggerUrl(),
            'admin' => ['phone' => (string) socSetting('tfb_admin_phone', ''), 'configure' => self::configured('admin')],
            'consultant' => ['phone' => (string) socSetting('tfb_consultant_phone', ''), 'configure' => self::configured('consultant')]];
    }

    /** Une requête HTTP brute : [code, corps]. */
    public static function raw(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        if (self::$transport) { return (self::$transport)($method, $url, $headers, $body); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $r = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$r === false ? 0 : $code, $r === false ? '' : (string) $r];
    }

    /** Le jeton d'un réalm : cache (base), sinon connexion. */
    public static function jeton(string $realm = 'admin', bool $forcer = false): string
    {
        if (!$forcer && isset(self::$jetons[$realm])) { return self::$jetons[$realm]; }
        $c = socSetting('tfb_jeton_' . $realm);
        if (!$forcer && is_array($c) && ($c['exp'] ?? 0) > time() + 60 && !empty($c['token'])) { return self::$jetons[$realm] = (string) $c['token']; }
        if (!self::configured($realm)) { throw new TfbError('compte API ' . $realm . ' non renseigné (Paramètres → API TFBuddy)'); }
        [$code, $b] = self::raw('POST', self::base() . '/' . $realm . '/auth/login', ['Content-Type: application/json'],
            json_encode(['phone' => (string) socSetting('tfb_' . $realm . '_phone'), 'password' => (string) socSetting('tfb_' . $realm . '_password')]));
        $j = json_decode($b, true);
        $tok = null;
        foreach ([$j['access_token'] ?? null, $j['token'] ?? null, $j['data']['access_token'] ?? null, $j['data']['token'] ?? null] as $t) {
            if (is_string($t) && $t !== '') { $tok = $t; break; }
        }
        if ($tok === null) { throw new TfbError('connexion ' . $realm . ' refusée (HTTP ' . $code . ')', $code); }
        socSettingPut('tfb_jeton_' . $realm, ['token' => $tok, 'exp' => time() + 1500], true);
        return self::$jetons[$realm] = $tok;
    }

    /** GET authentifié ; un 401 renouvelle le jeton une fois. Rend le JSON décodé. */
    public static function get(string $path, string $realm = 'admin', bool $auth = true): mixed
    {
        $url = str_starts_with($path, 'http') ? $path : self::base() . '/' . ltrim($path, '/');
        for ($essai = 0; $essai < 2; $essai++) {
            $h = $auth ? ['Authorization: Bearer ' . self::jeton($realm, $essai > 0)] : [];
            [$code, $b] = self::raw('GET', $url, $h);
            if ($code === 401 && $auth && $essai === 0) { continue; }
            if ($code < 200 || $code >= 300) { throw new TfbError('GET ' . $path . ' → HTTP ' . $code, $code); }
            $j = json_decode($b, true);
            if ($j === null && trim($b) !== 'null') { throw new TfbError('GET ' . $path . ' : réponse non JSON', $code); }
            return $j;
        }
        throw new TfbError('GET ' . $path . ' → 401', 401);
    }

    /** Une liste, qu'elle arrive nue ou dans {data: […]} / {items: […]}. */
    public static function liste(mixed $j): array
    {
        if (is_array($j) && array_is_list($j)) { return $j; }
        foreach (['data', 'items', 'results', 'shops', 'products'] as $k) {
            if (is_array($j[$k] ?? null) && array_is_list($j[$k])) { return $j[$k]; }
        }
        return [];
    }

    /** Pour les tests. */
    public static function reset(): void { self::$jetons = []; }
}

/**
 * Premier lancement : les comptes API du BO (réglages `erpAdmin` et
 * `panelApi` de ceo_app_setting, sinon son config/config.php) sont recopiés
 * dans social_parametre. Ensuite, seule la base du module fait foi.
 */
function socTfbInit(): void
{
    if (socSetting('tfb_init') !== null) { return; }
    $bo = boConfig();
    foreach (['admin' => 'erpAdmin', 'consultant' => 'panelApi'] as $realm => $cle) {
        $s = boSetting($cle, []);
        $s = is_array($s) ? $s : [];
        $c = (array) ($bo[$cle] ?? []);
        $v = static fn (string $k) => trim((string) (($s[$k] ?? '') ?: ($c[$k] ?? '')));
        if (socSetting('tfb_' . $realm . '_phone') === null && $v('phone') !== '') { socSettingPut('tfb_' . $realm . '_phone', $v('phone'), false); }
        if (socSetting('tfb_' . $realm . '_password') === null && $v('password') !== '') { socSettingPut('tfb_' . $realm . '_password', $v('password'), true); }
        if (socSetting('tfb_base') === null && $v('base') !== '') { socSettingPut('tfb_base', rtrim($v('base'), '/'), false); }
    }
    if (socSetting('tfb_base') === null) { socSettingPut('tfb_base', 'https://atelierby.tfbuddy.com/api/v1', false); }
    socSettingPut('tfb_init', date('Y-m-d H:i:s'));
}

/* --- boutiques ----------------------------------------------------------------------- */

/**
 * Les boutiques de l'API : `GET /public/shops` (sans compte : adresse, horaires,
 * e-mail, téléphone, TVA), complétées par `GET /shops` (compte admin) quand il
 * répond. Mémorisées 15 min dans social_parametre (`tfb_cache_shops`) : l'écran
 * ne doit pas attendre l'API à chaque chargement, ni s'éteindre si elle tombe.
 *
 * @return list<array{id:string,name:string,address:string,hours:string,email:string,phone:string,vat:string,source:string}>
 */
function socApiShops(bool $forcer = false): array
{
    $cache = socSetting('tfb_cache_shops');
    if (!$forcer && is_array($cache) && ($cache['le'] ?? 0) > time() - 900 && is_array($cache['shops'] ?? null)) { return $cache['shops']; }
    $out = [];
    try {
        foreach (Tfb::liste(Tfb::get('/public/shops', 'admin', false)) as $s) {
            if (!isset($s['id'])) { continue; }
            $out[(string) $s['id']] = socApiShopNorm($s, 'public/shops');
        }
    } catch (Throwable $e) { /* /shops ci-dessous, sinon le cache */ }
    if (Tfb::configured('admin')) {
        try {
            foreach (Tfb::liste(Tfb::get('/shops')) as $s) {
                if (!isset($s['id'])) { continue; }
                $n = socApiShopNorm($s, 'shops');
                $prev = $out[(string) $s['id']] ?? null;
                // public/shops prime là où il a une valeur ; /shops complète le reste.
                $out[(string) $s['id']] = $prev ? array_replace($n, array_filter($prev, static fn ($v) => $v !== '' && $v !== null), ['source' => 'public/shops + shops']) : $n;
                if (isset($s['active']) || isset($s['is_active'])) { $out[(string) $s['id']]['active'] = (bool) ($s['active'] ?? $s['is_active']); }
            }
        } catch (Throwable $e) { /* public/shops suffit */ }
    }
    if (!$out) { return is_array($cache['shops'] ?? null) ? $cache['shops'] : []; }
    $out = array_values($out);
    socSettingPut('tfb_cache_shops', ['le' => time(), 'shops' => $out]);
    return $out;
}

function socApiShopNorm(array $s, string $source): array
{
    $nom = trim((string) ($s['representative_name'] ?? $s['name'] ?? ''));
    $ville = trim((string) ($s['city'] ?? ''));
    $court = $ville !== '' ? $ville : (preg_match('/-\s*([^-]+)$/', $nom, $m) ? trim($m[1]) : $nom);
    $rue = trim(trim((string) ($s['street'] ?? '')) . ' ' . trim((string) ($s['street_num'] ?? '')));
    $adr = trim(implode(', ', array_filter([$rue, trim(($s['zip'] ?? '') . ' ' . $ville)])), ', ');
    $o = trim((string) ($s['opening_hours'] ?? '')); $f = trim((string) ($s['closing_hours'] ?? ''));
    $h = static fn (string $x) => preg_replace('/^0?(\d{1,2}):(\d{2}).*$/', '$1h$2', $x);
    return ['id' => (string) $s['id'], 'name' => $court, 'fullName' => $nom, 'address' => $adr,
        'hours' => $o !== '' && $f !== '' ? $h($o) . '–' . $h($f) : '', 'hoursNl' => $o !== '' && $f !== '' ? str_replace('h', 'u', $h($o) . '–' . $h($f)) : '',
        'email' => (string) ($s['email'] ?? ''), 'phone' => (string) ($s['phone'] ?? ''), 'vat' => (string) ($s['vat_number'] ?? ''),
        'lat' => is_numeric($s['latitude'] ?? null) ? (float) $s['latitude'] : null, 'lng' => is_numeric($s['longitude'] ?? null) ? (float) $s['longitude'] : null,
        'active' => true, 'source' => $source];
}

/* --- catalogue ------------------------------------------------------------------------ */

/** Une illustration du design system d'après la catégorie (l'API ne fournit pas de photo). */
function socIllus(string $cat, string $nom = ''): string
{
    $t = mb_strtolower($cat . ' ' . $nom);
    foreach ([
        'croissant|viennois' => 'img/illus/products/croissant.png', 'cookie|biscui' => 'img/illus/products/cookies.png',
        'cupcake|muffin' => 'img/illus/products/cupcake.png', 'tartelette' => 'img/illus/products/sweet-tart-small.png',
        'tarte|flan' => 'img/illus/products/sweet-tart-big.png', 'quiche|salé|sale' => 'img/illus/products/savoury-tart.png',
        'gâteau|gateau|entremet|cake|pâtisserie|patisserie' => 'img/illus/products/cake.png', 'sandwich|wrap|panini' => 'img/illus/products/sandwiches.png',
        'salade' => 'img/illus/products/salads.png', 'pistolet|petit pain|roll' => 'img/illus/products/rolls.png',
        'baguette|pain|boulang' => 'img/illus/products/bread-1.png', 'café|cafe|thé|chocolat chaud|boisson chaude' => 'img/illus/category-icons/hot-drink.png',
        'boisson|jus|soda|limonade|eau' => 'img/illus/category-icons/cold-drink.png', 'fromage|charcut' => 'img/illus/category-icons/cheese-fine-cuts.png',
    ] as $re => $img) {
        if (preg_match('/' . $re . '/u', $t)) { return $img; }
    }
    return 'img/illus/decorative/bubble.png';
}

/**
 * Le catalogue des écrans (Créer un post, Campagnes) depuis l'API :
 *  - produits : GET /shops/{id}/products/available (prix de vente
 *    `portion_price`), noms NL par ?lang_code=nl ; actifs, hors B2B ;
 *  - gammes : GET /product-availability-periods (actives).
 * Mémorisé 6 h (`tfb_cache_catalogue`) ; sans API, le catalogue en base.
 */
function socApiCatalogue(bool $forcer = false): array
{
    $cache = socSetting('tfb_cache_catalogue');
    if (!$forcer && is_array($cache) && ($cache['le'] ?? 0) > time() - 21600 && !empty($cache['items'])) { return $cache['items']; }
    try {
        $shop = (string) (socApiShops()[0]['id'] ?? '');
        if ($shop === '') { throw new TfbError('aucune boutique'); }
        $fr = Tfb::liste(Tfb::get('/shops/' . $shop . '/products/available'));
        $nl = [];
        try { foreach (Tfb::liste(Tfb::get('/shops/' . $shop . '/products/available?lang_code=nl')) as $p) { $nl[(string) $p['id']] = (string) ($p['name'] ?? ''); } }
        catch (Throwable $e) { /* sans NL */ }
        $items = [];
        foreach (Tfb::liste(Tfb::get('/product-availability-periods')) as $g) {
            if (empty($g['is_active'])) { continue; }
            $nom = trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', (string) ($g['name'] ?? '')));
            $items[] = ['id' => 'g' . $g['id'], 'apiId' => (int) $g['id'], 'kind' => 'gamme', 'name' => $nom, 'nameNl' => $nom, 'price' => '',
                'img' => socIllus('saison ' . $nom), 'gamme' => $nom, 'description' => (string) ($g['description'] ?? '')];
        }
        foreach ($fr as $p) {
            $cat = (string) ($p['category_name'] ?? '');
            if (empty($p['is_active']) || preg_match('/^B\.?2\.?B/i', $cat)) { continue; }
            $prix = is_numeric($p['portion_price'] ?? null) ? (float) $p['portion_price'] : (is_numeric($p['suggested_sale_price'] ?? null) ? (float) $p['suggested_sale_price'] : null);
            $items[] = ['id' => 'p' . $p['id'], 'apiId' => (int) $p['id'], 'kind' => 'produit', 'webshop' => !empty($p['webshop_active']), 'name' => (string) $p['name'],
                'nameNl' => ($nl[(string) $p['id']] ?? '') ?: (string) $p['name'], 'price' => $prix !== null ? number_format($prix, 2, ',', '') : '',
                'img' => socIllus($cat, (string) $p['name']), 'gamme' => $cat];
        }
        if (!$items) { throw new TfbError('catalogue vide'); }
        socSettingPut('tfb_cache_catalogue', ['le' => time(), 'shop' => $shop, 'items' => $items]);
        return $items;
    } catch (Throwable $e) {
        return is_array($cache['items'] ?? null) && $cache['items'] ? $cache['items'] : [];
    }
}

/* --- ventes ------------------------------------------------------------------------------ */

/**
 * Les ventes des produits d'une campagne pour une boutique, lues dans l'API :
 * GET /shops/{id}/statistics/sales/product-category-groups?date_from&date_to
 * (une ligne par produit vendu : sold_qty, sales_value) — la période, puis les
 * six semaines précédentes décalées de 7 jours (référence = moyenne).
 * Tickets : GET /shops/{id}/statistics/sales/kpis (tickets de la boutique).
 *
 * @return array{0:?array,1:?string}
 */
function socVentesApi(string $shopId, array $produits, string $du, string $au): array
{
    if (!ctype_digit($shopId)) { return [null, 'boutique sans identifiant de caisse']; }
    $noms = array_values(array_unique(array_filter(array_map(static fn ($n) => mb_strtolower(trim((string) $n)), $produits))));
    if (!$noms) { return [null, 'Aucun produit rattaché à la campagne']; }
    $somme = static function (string $de, string $a) use ($shopId, $noms): array {
        $q = '?date_from=' . $de . '&date_to=' . $a;
        $ca = 0.0; $qt = 0.0; $vu = false;
        foreach (Tfb::liste(Tfb::get('/shops/' . $shopId . '/statistics/sales/product-category-groups' . $q)) as $l) {
            $n = mb_strtolower(trim((string) ($l['product_name'] ?? '')));
            $c = mb_strtolower(trim((string) ($l['category_name'] ?? '')));
            if (in_array($n, $noms, true) || in_array($c, $noms, true)) { $ca += (float) ($l['sales_value'] ?? 0); $qt += (float) ($l['sold_qty'] ?? 0); $vu = true; }
        }
        $k = Tfb::get('/shops/' . $shopId . '/statistics/sales/kpis' . $q);
        return [$ca, $qt, (int) ($k['tickets'] ?? 0), $vu];
    };
    try {
        [$ca, $q, $tk, $vu] = $somme($du, $au);
        $ref = [0.0, 0.0, 0]; $vuRef = false;
        for ($k = 1; $k <= 6; $k++) {
            [$a, $b, $c, $v] = $somme(date('Y-m-d', strtotime($du . ' -' . (7 * $k) . ' days')), date('Y-m-d', strtotime($au . ' -' . (7 * $k) . ' days')));
            $ref[0] += $a; $ref[1] += $b; $ref[2] += $c; $vuRef = $vuRef || $v;
        }
    } catch (Throwable $e) {
        return [null, 'API des ventes injoignable : ' . $e->getMessage()];
    }
    if (!$vu && !$vuRef) { return [null, 'Aucune vente de ces produits dans l’API sur la période (' . implode(', ', $produits) . ')']; }
    return [['caCamp' => round($ca, 2), 'caRef' => round($ref[0] / 6, 2), 'tCamp' => $tk, 'tRef' => (int) round($ref[2] / 6),
        'qCamp' => round($q, 1), 'qRef' => round($ref[1] / 6, 1), 'source' => 'API TFBuddy'], null];
}
