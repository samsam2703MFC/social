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

/**
 * Le plan du webshop, tiré des catalogues déjà lus (cache) : catégorie → son groupe (0 : sans groupe),
 * produit → sa catégorie. Sert aux liens directs (socWebshopUrl). Une catégorie ou un produit absent
 * du plan relit le catalogue une fois par requête (lecture TFBuddy, 30 min de cache).
 */
function socWebshopPlan(int $categorie = 0, int $produit = 0): array
{
    static $relu = false;
    $plan = socWebshopPlanCache();
    $manque = ($categorie > 0 && !array_key_exists($categorie, $plan['cats'])) || ($produit > 0 && !isset($plan['prods'][$produit]));
    if ($manque && !$relu && Tfb::configured('admin')) {
        $relu = true;
        try { socNavCatalogue(''); $plan = socWebshopPlanCache(); } catch (Throwable $e) { /* le lien garde ce qu'on sait */ }
    }
    return $plan;
}

/** Le plan du webshop tel que les catalogues en cache le donnent, sans appel. */
function socWebshopPlanCache(): array
{
    $plan = ['cats' => [], 'prods' => []];
    $cache = socSetting('tfb_cache_nav');
    foreach ((array) (is_array($cache) ? ($cache['shops'] ?? []) : []) as $c) {
        foreach ((array) ($c['data']['categories'] ?? []) as $x) {
            if (!empty($x['id'])) { $plan['cats'][(int) $x['id']] = max((int) ($x['groupe'] ?? 0), (int) ($plan['cats'][(int) $x['id']] ?? 0)); }
        }
        foreach ((array) ($c['data']['produits'] ?? []) as $x) {
            if (!empty($x['id']) && !empty($x['cat'])) { $plan['prods'][(int) $x['id']] = (int) $x['cat']; }
        }
    }
    return $plan;
}

/* --- offres exactes à la publication ------------------------------------------------------ */

/** Une publication arrêtée parce qu'une donnée du post n'est plus exacte (prix, promotion). */
final class SocBlocage extends RuntimeException {}

/**
 * Les données d'offre d'un post sont-elles encore exactes ? Rend les écarts (liste vide : tout
 * est exact). Relu dans TFBuddy (caches 30 et 15 min) :
 *  - le prix du produit choisi pour une boutique (catalogue.prix, catalogue.shop) face à son prix
 *    de vente actuel ;
 *  - la promotion du post (offre.id) : toujours active, dans ses dates au jour de publication, et
 *    valable dans la boutique publiée (`$shopId` ; vide : toutes celles du post).
 * Si TFBuddy ne répond pas, rien n'est affirmé : la liste reste vide et `$injoignable` le dit.
 */
function socVerifierOffres(array $post, ?string $shopId = null, ?bool &$injoignable = null): array
{
    $injoignable = false;
    $ecarts = [];
    $noms = []; foreach (socShops() as $sh) { $noms[(string) $sh['id']] = (string) $sh['name']; }
    $cat = is_array($post['catalogue'] ?? null) ? $post['catalogue'] : null;
    try {
        if ($cat && ($cat['kind'] ?? '') === 'produit' && isset($cat['prix']) && is_numeric($cat['prix']) && !empty($cat['shop'])) {
            $shop = (string) $cat['shop'];
            $nav = socNavCatalogue($shop);
            $p = null; foreach ((array) ($nav['produits'] ?? []) as $x) { if ((int) $x['id'] === (int) ($cat['id'] ?? 0)) { $p = $x; break; } }
            $nom = (string) ($cat['name'] ?? 'le produit');
            if ($p === null) { $ecarts[] = '« ' . $nom . ' » n’est plus en vente sur le webshop de ' . ($noms[$shop] ?? $shop); }
            elseif ($p['prix'] === null || abs((float) $p['prix'] - (float) $cat['prix']) > 0.004) {
                $ecarts[] = 'prix de « ' . $nom . ' » changé dans TFBuddy : ' . socEur($cat['prix']) . ' → ' . ($p['prix'] !== null ? socEur($p['prix']) : 'non renseigné');
            }
        }
        $of = is_array($post['offre'] ?? null) ? $post['offre'] : null;
        if ($of && !empty($of['id']) && ($of['genre'] ?? 'promotion') === 'promotion' && !Tfb::configured('admin')) { $injoignable = true; $of = null; }
        if ($of && !empty($of['id']) && ($of['genre'] ?? 'promotion') === 'promotion') {
            $x = null; foreach (socPromotions() as $pr) { if ((int) $pr['id'] === (int) $of['id']) { $x = $pr; break; } }
            $nom = (string) ($of['nom'] ?? $of['libelle'] ?? 'la promotion');
            $jour = (string) ($post['date'] ?? date('Y-m-d'));
            if ($x === null) { $ecarts[] = 'la promotion « ' . $nom . ' » n’est plus active dans TFBuddy'; }
            else {
                if (!empty($x['au']) && $x['au'] < $jour) { $ecarts[] = 'la promotion « ' . $nom . ' » se termine le ' . date('d/m/Y', strtotime($x['au'])) . ', avant la publication'; }
                if (!empty($x['du']) && $x['du'] > $jour) { $ecarts[] = 'la promotion « ' . $nom . ' » ne commence que le ' . date('d/m/Y', strtotime($x['du'])); }
                $cibles = $shopId !== null && $shopId !== '' ? [$shopId] : array_map('strval', (array) ($post['shops'] ?? []));
                if (empty($x['tous'])) {
                    $hors = array_values(array_filter($cibles, static fn ($id) => ctype_digit($id) && !in_array($id, (array) $x['shops'], true)));
                    if ($hors) { $ecarts[] = 'la promotion « ' . $nom . ' » n’est pas valable à ' . implode(', ', array_map(static fn ($id) => $noms[$id] ?? $id, $hors)); }
                }
            }
        }
    } catch (TfbError | HttpError $e) {
        $injoignable = true;
    }
    return $ecarts;
}

/* --- textes sur le visuel ----------------------------------------------------------------- */

/**
 * Les modèles d'étiquette enregistrés depuis le générateur (réglage `etiquettes_modeles`) : un style
 * de texte posé sur le visuel (forme, police, couleurs de la palette, taille, rotation, position).
 * Champs connus seulement, valeurs bornées, 40 modèles au plus.
 */
function socEtiquettesModeles(mixed $liste): array
{
    $formes = ['etiquette', 'pastille', 'bandeau', 'texte'];
    $polices = ['titre', 'gras', 'texte', 'script'];
    $couleurs = ['creme', 'beige', 'beige-clair', 'blanc', 'abricot', 'ruby', 'noir', 'popup', 'ambre'];
    $out = [];
    foreach (is_array($liste) ? $liste : [] as $m) {
        if (!is_array($m)) { continue; }
        $nom = trim(mb_substr((string) ($m['nom'] ?? ''), 0, 60));
        $id = preg_replace('/[^\w-]/', '', (string) ($m['id'] ?? ''));
        if ($nom === '' || $id === '') { continue; }
        $borne = static fn ($v, int $a, int $b, int $d) => is_numeric($v) ? max($a, min($b, (int) round((float) $v))) : $d;
        $out[] = ['id' => substr($id, 0, 40), 'nom' => $nom,
            'forme' => in_array($m['forme'] ?? '', $formes, true) ? $m['forme'] : 'etiquette',
            'police' => in_array($m['police'] ?? '', $polices, true) ? $m['police'] : 'titre',
            'couleur' => in_array($m['couleur'] ?? '', $couleurs, true) ? $m['couleur'] : 'blanc',
            'fond' => in_array($m['fond'] ?? '', $couleurs, true) ? $m['fond'] : null,
            'taille' => $borne($m['taille'] ?? null, 16, 220, 56), 'rot' => $borne($m['rot'] ?? null, -45, 45, 0),
            'x' => $borne($m['x'] ?? null, 0, 100, 50), 'y' => $borne($m['y'] ?? null, 0, 100, 50)];
        if (count($out) >= 40) { break; }
    }
    return $out;
}

/**
 * Les modèles de visuel complets enregistrés depuis le générateur (réglage `visuels_modeles`) :
 * style, format, fond, logo, fondu, illustration, textes posés, éléments affichés et textes fixes
 * (sur-titre, phrase, unité, site). Le produit, le prix et la photo restent ceux du post.
 * Champs connus seulement, valeurs bornées, 30 modèles au plus.
 */
function socVisuelsModeles(mixed $liste): array
{
    $mot = static fn ($v, array $ok, ?string $d) => in_array($v, $ok, true) ? $v : $d;
    $num = static fn ($v, int $a, int $b, int $d) => is_numeric($v) ? max($a, min($b, (int) round((float) $v))) : $d;
    $txt = static fn ($v, int $n) => trim(mb_substr((string) ($v ?? ''), 0, $n));
    $couleurs = ['creme', 'beige', 'beige-clair', 'blanc', 'abricot', 'ruby', 'noir', 'popup', 'ambre'];
    $out = [];
    foreach (is_array($liste) ? $liste : [] as $m) {
        if (!is_array($m) || !is_array($m['v'] ?? null)) { continue; }
        $id = substr((string) preg_replace('/[^\w-]/', '', (string) ($m['id'] ?? '')), 0, 40);
        $nom = $txt($m['nom'] ?? '', 60);
        if ($id === '' || $nom === '') { continue; }
        $v = $m['v'];
        $w = ['style' => preg_match('/^[a-z]{2,20}$/', (string) ($v['style'] ?? '')) ? (string) $v['style'] : 'epure',
            'format' => $mot($v['format'] ?? '', ['carre', 'portrait', 'story', 'paysage'], 'carre'),
            'fond' => $mot($v['fond'] ?? null, $couleurs, null),
            'logo' => $mot($v['logo'] ?? 'auto', ['auto', 'fonce', 'clair', 'noir', 'ruby', 'abricot', 'creme'], 'auto'),
            'fondu' => $num($v['fondu'] ?? null, 0, 100, 60), 'cutMode' => $mot($v['cutMode'] ?? 'auto', ['auto', 'cut', 'full'], 'auto'),
            'phraseFont' => $mot($v['phraseFont'] ?? 'script', ['script', 'display', 'sans'], 'script'),
            'phraseColor' => $mot($v['phraseColor'] ?? 'auto', ['auto', 'ruby', 'noir', 'abricot', 'blanc', 'gris'], 'auto')];
        foreach (['showLogo', 'showEyebrow', 'showTitle', 'showPhrase', 'showPrice', 'showPromo', 'showSite'] as $k) { if (array_key_exists($k, $v)) { $w[$k] = (bool) $v[$k]; } }
        foreach (['eyebrow' => 80, 'phrase' => 160, 'unit' => 40, 'site' => 60] as $k => $n) { if (isset($v[$k])) { $w[$k] = $txt($v[$k], $n); } }
        $il = is_array($v['illus'] ?? null) ? $v['illus'] : null;
        $w['illus'] = $il && preg_match('#^img/(illus|products)/[\w./-]+\.png$#', (string) ($il['src'] ?? '')) && !str_contains((string) $il['src'], '..')
            ? ['src' => (string) $il['src'], 'name' => $txt($il['name'] ?? '', 60), 'x' => $num($il['x'] ?? null, 0, 100, 82), 'y' => $num($il['y'] ?? null, 0, 100, 18),
                'taille' => $num($il['taille'] ?? null, 5, 80, 28), 'trait' => $mot($il['trait'] ?? 'auto', ['auto', 'origine', 'ruby', 'noir', 'blanc', 'abricot', 'creme'], 'auto')] : null;
        $w['textes'] = [];
        foreach (array_slice((array) ($v['textes'] ?? []), 0, 8) as $t) {
            if (!is_array($t)) { continue; }
            $e = socEtiquettesModeles([['id' => 'x', 'nom' => 'x'] + $t])[0] ?? null;
            if ($e === null) { continue; }
            unset($e['nom']);
            $w['textes'][] = ['id' => 't' . substr(md5(json_encode($t)), 0, 10), 'txt' => $txt($t['txt'] ?? '', 80), 'sous' => $txt($t['sous'] ?? '', 80)] + array_diff_key($e, ['id' => 1]);
        }
        $out[] = ['id' => $id, 'nom' => $nom, 'v' => $w];
        if (count($out) >= 30) { break; }
    }
    return $out;
}

/* --- images du webshop ---------------------------------------------------------------------- */

/**
 * Une image du catalogue servie par le module (même origine que l'écran). Les photos du webshop
 * sont sur Cloudflare R2, sans en-tête CORS : un visuel qui les dessine ne peut plus être exporté
 * en image, et le post partait avec la photo brute au lieu du visuel composé (logo, titre, prix).
 * Seules passent les images https d'un hôte R2 public (*.r2.dev) ou d'un hôte d'image du
 * catalogue en cache ; pas de redirection suivie, une image reconnue à ses octets, 15 Mo au plus.
 */
function socMediaWebshop(string $url): SocFichier
{
    $hote = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!preg_match('#^https://[^/?\#@]+/#i', $url) || !socMediaHoteAdmis($hote)) { throw new HttpError(422, 'image refusée : seules les images du catalogue du webshop passent'); }
    [$code, $octets] = Tfb::raw('GET', $url, ['Accept: image/*'], null, 20);
    if ($code !== 200 || $octets === '') { throw new HttpError(502, 'image du webshop illisible (HTTP ' . $code . ')'); }
    if (strlen($octets) > 15 * 1024 * 1024) { throw new HttpError(502, 'image du webshop trop lourde'); }
    $type = socMediaType($octets);
    if ($type === null) { throw new HttpError(502, 'le webshop n’a pas rendu une image'); }
    return new SocFichier($type, $octets);
}

function socMediaHoteAdmis(string $hote): bool
{
    if ($hote === '') { return false; }
    if (preg_match('/^[a-z0-9-]+\.r2\.dev$/', $hote)) { return true; }
    $cache = socSetting('tfb_cache_nav');
    $vu = json_encode(is_array($cache) ? $cache : []);
    return is_string($vu) && str_contains($vu, 'https:\/\/' . $hote . '\/');
}

/** Le type d'une image d'après ses premiers octets (JPEG, PNG, WebP, GIF, AVIF), sinon null. */
function socMediaType(string $o): ?string
{
    return match (true) {
        str_starts_with($o, "\xFF\xD8\xFF") => 'image/jpeg',
        str_starts_with($o, "\x89PNG\r\n\x1A\n") => 'image/png',
        str_starts_with($o, 'RIFF') && substr($o, 8, 4) === 'WEBP' => 'image/webp',
        str_starts_with($o, 'GIF87a'), str_starts_with($o, 'GIF89a') => 'image/gif',
        substr($o, 4, 8) === 'ftypavif' => 'image/avif',
        default => null,
    };
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
    $liste = []; $lus = 0; $derniere = null;
    foreach (['bundles', 'buy-x-get-y', 'quantity', 'scheduled-product-discount'] as $t) {
        try { foreach (Tfb::liste(Tfb::get('/admin/promotions/' . $t . '?limit=100')) as $x) { if (is_array($x) && !empty($x['id'])) { $liste[(int) $x['id']] = $x; } } $lus++; }
        catch (Throwable $e) { $derniere = $e; /* type indisponible */ }
    }
    // Aucune liste lue : TFBuddy ne répond pas (ne pas en conclure qu'il n'y a plus de promotion).
    if ($lus === 0) { throw new TfbError('promotions illisibles' . ($derniere ? ' : ' . $derniere->getMessage() : '')); }
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
