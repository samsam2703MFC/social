<?php
declare(strict_types=1);

/**
 * Créer un post : le catalogue tel que le webshop le présente, lu dans l'API TFBuddy.
 *
 *   catégorie (groupe de catégories, ex. « Traiteur »)  — illustration du webshop
 *     → sous-catégorie (catégorie, ex. « Sandwiches garnis ») — illustration du webshop
 *       → produit — photo du webshop, prix de vente de la boutique choisie
 *   gamme (période de disponibilité, ex. « Automnale ») — photo de la gamme
 *
 * Sources (lecture seule) :
 *  - GET /shops/{id}/products/available?webshop_active=1&alias_type=webshop
 *        &include=webshop,portions,availability_periods,webshop_availability
 *    (l'appel du webshop : produits vendus en ligne, leur catégorie et son groupe avec leurs
 *    illustrations, la photo du produit, `portion_price` = prix de vente de la boutique) ;
 *  - GET /product-availability-periods (les gammes, avec leur photo) ;
 *  - GET /admin/promotions/{bundles|buy-x-get-y|quantity|scheduled-product-discount}[/{id}]
 *    (promotions et bundles : le détail porte les produits et catégories visés) ;
 *  - GET /webshop/shops/{id}/products/{p}/menus (les formules d'un produit).
 *
 * Un prix n'a de sens que pour une boutique : plusieurs boutiques visées, pas de prix.
 */

const SOC_NAV_CACHE = 1800;     // catalogue d'une boutique : 30 min
const SOC_PROMO_CACHE = 900;    // promotions : 15 min

/** URL d'une image du webshop (`{url: …}`) ou rien. */
function socNavImage(mixed $x): ?string
{
    $u = is_array($x) ? (string) ($x['url'] ?? '') : '';
    return preg_match('#^https://#', $u) ? $u : null;
}

/** Les boutiques du réseau qui ont une caisse (identifiant numérique de l'API). */
function socNavBoutiques(): array
{
    return array_values(array_filter(array_map(static fn ($s) => (string) $s['id'], socReseau()), 'ctype_digit'));
}

/**
 * La navigation du catalogue. `$shop` : la boutique dont on veut les prix (une seule) ;
 * vide : la structure seule, lue sur la première boutique, sans prix.
 */
function socNavCatalogue(string $shop = '', bool $forcer = false): array
{
    $caisses = socNavBoutiques();
    $prix = $shop !== '' && in_array($shop, $caisses, true);
    $ref = $prix ? $shop : ($caisses[0] ?? '');
    if ($ref === '') { throw new HttpError(409, 'aucune boutique de caisse : catalogue indisponible'); }
    $cache = socSetting('tfb_cache_nav');
    $cache = is_array($cache) && ($cache['v'] ?? 0) === 1 ? $cache : ['v' => 1, 'shops' => []];
    $c = $cache['shops'][$ref] ?? null;
    if ($forcer || !is_array($c) || ($c['le'] ?? 0) < time() - SOC_NAV_CACHE) {
        $c = socNavLire($ref);
        $cache['shops'][$ref] = $c;
        socSettingPut('tfb_cache_nav', $cache, false);
    }
    $out = $c['data'];
    $out['shop'] = $prix ? $shop : null;
    $out['prix'] = $prix;
    if (!$prix) { foreach ($out['produits'] as &$p) { $p['prix'] = null; } unset($p); }
    return $out;
}

/** Lit le catalogue webshop d'une boutique et le met en forme (groupes, catégories, produits, gammes). */
function socNavLire(string $shop): array
{
    $q = 'webshop_active=1&alias_type=webshop&include=webshop,portions,availability_periods,webshop_availability';
    $fr = Tfb::liste(Tfb::get('/shops/' . $shop . '/products/available?lang_code=fr&' . $q));
    $nl = [];
    try { foreach (Tfb::liste(Tfb::get('/shops/' . $shop . '/products/available?lang_code=nl&' . $q)) as $p) { $nl[(string) ($p['id'] ?? '')] = (string) ($p['name'] ?? ''); } }
    catch (Throwable $e) { /* sans les noms NL */ }
    $groupes = []; $cats = []; $produits = []; $parGamme = [];
    foreach ($fr as $p) {
        if (!is_array($p) || empty($p['id']) || empty($p['is_active'])) { continue; }
        $c = is_array($p['category'] ?? null) ? $p['category'] : [];
        $cid = (int) ($p['id_category'] ?? ($c['id'] ?? 0));
        $g = is_array($c['groups'][0] ?? null) ? $c['groups'][0] : null;
        $gid = $g ? (int) $g['id'] : 0;
        if ($gid && !isset($groupes[$gid])) { $groupes[$gid] = ['id' => $gid, 'name' => trim((string) ($g['name'] ?? $g['base_name'] ?? '')), 'image' => socNavImage($g['webshop_image'] ?? null), 'cats' => [], 'n' => 0]; }
        if ($cid && !isset($cats[$cid])) {
            $cats[$cid] = ['id' => $cid, 'name' => trim((string) ($c['name'] ?? $p['category_name'] ?? '')), 'image' => socNavImage($c['webshop_image'] ?? null), 'groupe' => $gid ?: null, 'n' => 0];
            if ($gid) { $groupes[$gid]['cats'][] = $cid; }
        }
        if ($gid) { $groupes[$gid]['n']++; }
        if ($cid) { $cats[$cid]['n']++; }
        $gammes = [];
        foreach ((array) ($p['availability_periods'] ?? []) as $a) { if (!empty($a['id'])) { $gammes[] = (int) $a['id']; $parGamme[(int) $a['id']] = ($parGamme[(int) $a['id']] ?? 0) + 1; } }
        $prix = is_numeric($p['portion_price'] ?? null) ? round((float) $p['portion_price'], 2) : null;
        $web = is_numeric($p['webshop']['price_gross'] ?? null) ? round((float) $p['webshop']['price_gross'], 2) : null;
        $produits[] = ['id' => (int) $p['id'], 'name' => trim((string) $p['name']), 'nameNl' => trim(($nl[(string) $p['id']] ?? '') ?: (string) $p['name']),
            'image' => socNavImage($p['webshop_image'] ?? null), 'cat' => $cid ?: null, 'groupe' => $gid ?: null,
            'prix' => $prix, 'prixWeb' => $web, 'gammes' => $gammes, 'formules' => !empty($p['has_menu_options'])];
    }
    // Les gammes vendues sur le webshop (et actives), avec leur photo et le nombre de produits.
    $listeGammes = [];
    try {
        foreach (Tfb::liste(Tfb::get('/product-availability-periods?lang_code=fr')) as $g) {
            if (!is_array($g) || empty($g['id']) || empty($g['is_active'])) { continue; }
            $listeGammes[] = ['id' => (int) $g['id'], 'name' => trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', (string) ($g['name'] ?? ''))),
                'image' => socNavImage($g['photo'] ?? null), 'description' => trim((string) ($g['description'] ?? '')), 'webshop' => !empty($g['webshop_active']),
                'du' => isset($g['from_md']) ? sprintf('%02d/%02d', (int) $g['from_md'] % 100, intdiv((int) $g['from_md'], 100)) : null,
                'au' => isset($g['to_md']) ? sprintf('%02d/%02d', (int) $g['to_md'] % 100, intdiv((int) $g['to_md'], 100)) : null,
                'n' => $parGamme[(int) $g['id']] ?? 0];
        }
    } catch (Throwable $e) { /* sans les gammes */ }
    usort($produits, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    $groupes = array_values($groupes); usort($groupes, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    return ['le' => time(), 'data' => ['groupes' => $groupes, 'categories' => array_values($cats), 'produits' => $produits, 'gammes' => $listeGammes, 'lu' => date('Y-m-d H:i'), 'source' => $shop]];
}

/* --- promotions et bundles ------------------------------------------------------------------ */

/** « 1,40 € ». */
function socEur(mixed $v): string
{
    return number_format((float) $v, 2, ',', ' ') . ' €';
}

/**
 * Les promotions actives de l'API (bundles, X acheté → Y, quantité, remises programmées),
 * avec les produits et catégories qu'elles visent et un libellé lisible. Lecture admin.
 */
function socPromotions(bool $forcer = false): array
{
    $cache = socSetting('tfb_cache_promos');
    if (!$forcer && is_array($cache) && ($cache['le'] ?? 0) > time() - SOC_PROMO_CACHE && is_array($cache['items'] ?? null)) { return $cache['items']; }
    if (!Tfb::configured('admin')) { return []; }
    $liste = [];
    foreach (['bundles', 'buy-x-get-y', 'quantity', 'scheduled-product-discount'] as $t) {
        try { foreach (Tfb::liste(Tfb::get('/admin/promotions/' . $t . '?limit=100')) as $x) { if (is_array($x) && !empty($x['id'])) { $liste[(int) $x['id']] = $x; } } }
        catch (Throwable $e) { /* type indisponible */ }
    }
    $out = [];
    foreach ($liste as $id => $x) {
        if (strtoupper((string) ($x['status'] ?? '')) !== 'ACTIVE') { continue; }
        $type = strtoupper((string) ($x['promotion_type'] ?? ''));
        $route = match (true) { str_contains($type, 'BUNDLE') => 'bundles', str_contains($type, 'BUY') => 'buy-x-get-y', str_contains($type, 'QUANTITY') => 'quantity', default => 'scheduled-product-discount' };
        try { $d = Tfb::get('/admin/promotions/' . $route . '/' . $id); $d = is_array($d['data'] ?? null) ? $d['data'] : $d; }
        catch (Throwable $e) { continue; }
        if (!is_array($d)) { continue; }
        $n = socPromotionNorm($d + $x);
        if ($n !== null) { $out[] = $n; }
    }
    socSettingPut('tfb_cache_promos', ['le' => time(), 'items' => $out], false);
    return $out;
}

/** Une promotion de l'API → {id, type, nom, libelle, du, au, tous, shops, produits, categories}. */
function socPromotionNorm(array $d): ?array
{
    $type = strtoupper((string) ($d['promotion_type'] ?? ''));
    $ids = static fn (array $l): array => array_values(array_unique(array_filter(array_map('intval', $l))));
    $noms = static function (array $cote): string {
        $n = array_merge(array_map(static fn ($p) => trim((string) ($p['name'] ?? '')), (array) ($cote['products'] ?? [])),
            array_map(static fn ($c) => trim((string) ($c['name'] ?? '')), (array) ($cote['categories'] ?? [])));
        return implode(', ', array_filter($n));
    };
    $produits = []; $categories = []; $libelle = (string) ($d['name'] ?? ''); $court = '';
    if (str_contains($type, 'BUNDLE')) {
        $items = (array) ($d['items'] ?? []);
        $produits = $ids(array_column($items, 'id_product'));
        $quoi = implode(' + ', array_map(static fn ($i) => rtrim(rtrim(number_format((float) ($i['quantity'] ?? 1), 2, ',', ''), '0'), ',') . ' × ' . trim((string) ($i['product_name'] ?? '')), $items));
        $libelle = 'Bundle : ' . $quoi . ' = ' . socEur($d['bundle_promotion']['bundle_price'] ?? $d['bundle_price'] ?? 0);
        $court = socEur($d['bundle_promotion']['bundle_price'] ?? $d['bundle_price'] ?? 0);
    } elseif (str_contains($type, 'BUY')) {
        $t = (array) ($d['trigger'] ?? []); $r = (array) ($d['reward'] ?? []);
        $produits = $ids(array_merge((array) ($t['product_ids'] ?? []), (array) ($r['product_ids'] ?? [])));
        $categories = $ids(array_merge((array) ($t['category_ids'] ?? []), (array) ($r['category_ids'] ?? [])));
        $tq = (int) ($t['quantity'] ?? 1); $rq = (int) ($r['quantity'] ?? 1); $v = $r['value'] ?? null;
        $memes = $noms($t) === $noms($r);
        $cible = $memes ? '' : ' ' . $noms($r);
        $court = match (strtoupper((string) ($r['type'] ?? ''))) {
            'FREE' => $memes ? $tq . '+' . $rq : $rq . ' offert' . ($rq > 1 ? 's' : ''),
            'FIXED_PRICE' => socEur($v), 'FIXED_AMOUNT_DISCOUNT' => '−' . socEur($v),
            'PERCENTAGE_DISCOUNT', 'PERCENT' => '−' . rtrim(rtrim((string) $v, '0'), '.') . ' %', default => '',
        };
        $libelle = match (strtoupper((string) ($r['type'] ?? ''))) {
            'FREE' => $memes ? $tq . ' + ' . $rq . ' offert' . ($rq > 1 ? 's' : '') . ' (' . $noms($t) . ')' : $tq . ' ' . $noms($t) . ' acheté' . ($tq > 1 ? 's' : '') . ' = ' . $rq . $cible . ' offert' . ($rq > 1 ? 's' : ''),
            'FIXED_PRICE' => $tq . ' ' . $noms($t) . ' acheté' . ($tq > 1 ? 's' : '') . ' → ' . $rq . ($cible ?: ' de plus') . ' à ' . socEur($v),
            'FIXED_AMOUNT_DISCOUNT' => $tq . ' ' . $noms($t) . ' acheté' . ($tq > 1 ? 's' : '') . ' → −' . socEur($v) . ' sur ' . $rq . ($cible ?: ' de plus'),
            'PERCENTAGE_DISCOUNT', 'PERCENT' => $tq . ' ' . $noms($t) . ' acheté' . ($tq > 1 ? 's' : '') . ' → −' . rtrim(rtrim((string) $v, '0'), '.') . ' % sur ' . $rq . ($cible ?: ' de plus'),
            default => (string) ($d['name'] ?? ''),
        };
    } elseif (str_contains($type, 'QUANTITY')) {
        $produits = $ids((array) ($d['eligible_product_ids'] ?? []));
        $categories = $ids((array) ($d['eligible_category_ids'] ?? []));
    } else {
        $produits = $ids((array) ($d['reward_product_ids'] ?? []));
        $categories = $ids((array) ($d['reward_category_ids'] ?? []));
        $v = $d['discount_value'] ?? null;
        if ($v !== null) { $court = '−' . (str_contains(strtoupper((string) ($d['discount_type'] ?? '')), 'PERC') ? rtrim(rtrim((string) $v, '0'), '.') . ' %' : socEur($v)); $libelle = $court . ' · ' . $libelle; }
    }
    $jour = static fn ($x) => is_string($x) && $x !== '' ? substr($x, 0, 10) : null;
    $au = $jour($d['valid_to'] ?? null);
    if ($au !== null && $au < date('Y-m-d')) { return null; }  // terminée
    return ['id' => (int) $d['id'], 'type' => match (true) { str_contains($type, 'BUNDLE') => 'bundle', str_contains($type, 'BUY') => 'offre', str_contains($type, 'QUANTITY') => 'quantite', default => 'remise' },
        'nom' => trim((string) ($d['name'] ?? '')), 'libelle' => $libelle, 'court' => $court, 'du' => $jour($d['valid_from'] ?? null), 'au' => $au,
        'tous' => strtoupper((string) ($d['shop_scope_type'] ?? '')) === 'ALL_SHOPS',
        'shops' => array_values(array_map('strval', array_filter((array) ($d['shop_ids'] ?? []), 'is_numeric'))),
        'produits' => $produits, 'categories' => $categories];
}

/**
 * Les promotions, bundles et formules liés à ce qu'on a choisi (produit, sous-catégorie,
 * catégorie ou gamme), pour une boutique (ou toutes). Une promotion est liée si elle vise
 * le produit ou sa catégorie, ou (pour une catégorie) l'un de ses produits.
 */
function socNavOffres(array $q): array
{
    $shop = (string) ($q['shop'] ?? '');
    $nav = socNavCatalogue(in_array($shop, socNavBoutiques(), true) ? $shop : '');
    $prods = [];
    foreach ($nav['produits'] as $p) { $prods[$p['id']] = $p; }
    $pid = (int) ($q['produit'] ?? 0); $cid = (int) ($q['categorie'] ?? 0); $gid = (int) ($q['groupe'] ?? 0); $gam = (int) ($q['gamme'] ?? 0);
    // Les produits et catégories concernés par la sélection.
    $mesProduits = []; $mesCats = [];
    if ($pid) { $mesProduits = [$pid]; if (isset($prods[$pid]['cat'])) { $mesCats = [(int) $prods[$pid]['cat']]; } }
    elseif ($cid) { $mesCats = [$cid]; foreach ($prods as $p) { if ((int) $p['cat'] === $cid) { $mesProduits[] = $p['id']; } } }
    elseif ($gid) { foreach ($prods as $p) { if ((int) $p['groupe'] === $gid) { $mesProduits[] = $p['id']; $mesCats[(int) $p['cat']] = (int) $p['cat']; } } $mesCats = array_values($mesCats); }
    elseif ($gam) { foreach ($prods as $p) { if (in_array($gam, $p['gammes'], true)) { $mesProduits[] = $p['id']; $mesCats[(int) $p['cat']] = (int) $p['cat']; } } $mesCats = array_values($mesCats); }
    $promos = [];
    $erreur = null;
    try {
        foreach (socPromotions() as $x) {
            if (!array_intersect($x['produits'], $mesProduits) && !array_intersect($x['categories'], $mesCats)) { continue; }
            $ici = $x['tous'] || $shop === '' || in_array($shop, $x['shops'], true);
            $noms = array_map(static fn ($s) => (socShop($s) ?? ['name' => $s])['name'], $x['shops']);
            $promos[] = $x + ['ici' => $ici, 'portee' => $x['tous'] ? 'Toutes les boutiques' : implode(', ', $noms)];
        }
    } catch (Throwable $e) { $erreur = 'promotions : ' . $e->getMessage(); }
    // Les formules d'un produit (sandwich + boisson…) : prix de la boutique, ou de la boutique de référence.
    $formules = [];
    if ($pid && !empty($prods[$pid]['formules'])) {
        $ref = $nav['shop'] ?? $nav['source'];
        try {
            foreach (Tfb::liste(Tfb::get('/webshop/shops/' . $ref . '/products/' . $pid . '/menus?lang_code=fr', 'admin', false)) as $f) {
                if (!is_array($f)) { continue; }
                $mod = (float) ($f['price_modifier'] ?? 0);
                $base = $prods[$pid]['prix'] ?? null;
                $mode = strtoupper((string) ($f['price_mode'] ?? ''));
                $total = $mode === 'BASE_PLUS' && $base !== null ? $base + $mod : ($mode === 'FIXED' ? $mod : null);
                $formules[] = ['nom' => trim((string) ($f['name'] ?? '')), 'menu' => trim((string) ($f['menu_name'] ?? '')),
                    'libelle' => trim((string) ($f['menu_name'] ?? '')) . ' · ' . trim((string) ($f['name'] ?? '')) . ($mode === 'BASE_PLUS' ? ' : +' . socEur($mod) : ''),
                    'prix' => $total !== null ? round($total, 2) : null,
                    'court' => $total !== null ? socEur($total) : ($mode === 'BASE_PLUS' ? '+' . socEur($mod) : ''),
                    'choix' => array_values(array_map(static fn ($sl) => trim((string) ($sl['label'] ?? '')), (array) ($f['slots'] ?? [])))];
            }
        } catch (Throwable $e) { $erreur = ($erreur ? $erreur . ' · ' : '') . 'formules : ' . $e->getMessage(); }
    }
    return ['promotions' => $promos, 'formules' => $formules, 'shop' => $nav['shop'], 'erreur' => $erreur];
}
