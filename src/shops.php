<?php
declare(strict_types=1);

/**
 * Les boutiques du module — celles du BO, jamais une liste à part.
 *
 *  - l'IDENTITÉ vient de `ceo_shop` (id, nom, franchisé, zone, statut) ; une
 *    boutique fermée n'apparaît pas ;
 *  - la PAGE FACEBOOK vient de Brand Guard (`brand_guard_pages`) : l'URL et le
 *    page_id résolus là-bas servent ici sans ressaisie ;
 *  - l'ADRESSE et la POSITION viennent de la caisse du panel (`shops`, via
 *    `ceo_shop.pwa_shop_id`) quand elle les porte ;
 *  - ce qui n'existe que pour le social (langues, horaires, compte Instagram,
 *    compte publicitaire, pixel, état des connexions) vit dans `social_boutique`.
 *
 * Le module n'écrit JAMAIS dans `ceo_shop` ni dans `brand_guard_pages`.
 */

/** Les champs de `social_boutique` que l'écran peut modifier. */
const SOC_SHOP_CHAMPS = ['langs', 'address', 'hours', 'hoursNl', 'ig', 'page', 'geo', 'name'];
const SOC_IDS = ['page_id', 'ad_account_id', 'instagram_user_id', 'pixel_id'];

function socShopOverrides(): array
{
    $out = [];
    foreach (Db::rows('SELECT shop_id, data FROM social_boutique') as $r) {
        $d = json_decode((string) $r['data'], true);
        $out[(string) $r['shop_id']] = is_array($d) ? $d : [];
    }
    return $out;
}

function socShopPut(string $id, array $patch): void
{
    $cur = socShopOverrides()[$id] ?? [];
    $d = array_replace($cur, $patch);
    $ids = (array) ($d['ids'] ?? []); $conn = (array) ($d['conn'] ?? []);
    $v = [$id, isset($d['langs']) ? implode(',', (array) $d['langs']) : null, $d['address'] ?? null, $d['hours'] ?? null, $d['hoursNl'] ?? null,
        ($ids['page_id'] ?? '') ?: null, ($ids['ad_account_id'] ?? '') ?: null, ($ids['instagram_user_id'] ?? '') ?: null, ($ids['pixel_id'] ?? '') ?: null,
        $conn['fb'] ?? null, $conn['igs'] ?? null, $conn['ads'] ?? null, $conn['at'] ?? null,
        json_encode($d, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')];
    Db::exec('INSERT INTO social_boutique (shop_id, langues, adresse, horaires, horaires_nl, page_id, ad_account_id, instagram_user_id, pixel_id,
                conn_facebook, conn_instagram, conn_publicite, verifie_le, data, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE langues = VALUES(langues), adresse = VALUES(adresse), horaires = VALUES(horaires), horaires_nl = VALUES(horaires_nl),
                page_id = VALUES(page_id), ad_account_id = VALUES(ad_account_id), instagram_user_id = VALUES(instagram_user_id), pixel_id = VALUES(pixel_id),
                conn_facebook = VALUES(conn_facebook), conn_instagram = VALUES(conn_instagram), conn_publicite = VALUES(conn_publicite),
                verifie_le = VALUES(verifie_le), data = VALUES(data), maj_le = VALUES(maj_le)', $v);
}

/** Une valeur d'une ligne de la caisse, sous l'un des noms de colonne connus. */
function socCol(array $row, array $noms): ?string
{
    foreach ($noms as $n) {
        if (isset($row[$n]) && trim((string) $row[$n]) !== '') { return trim((string) $row[$n]); }
    }
    return null;
}

/**
 * Les boutiques telles que les écrans les lisent :
 * {id, name, langs, address, hours, hoursNl, page, ig, fb, igs, ads, ids, geo, sources, …}
 *
 * Ordre des sources, champ par champ :
 *  1. l'API TFBuddy (socApiShops) — la liste des boutiques, leur identifiant
 *     (celui de la caisse), adresse, horaires, e-mail, téléphone, TVA ;
 *  2. le module (social_boutique) — langues, identifiants Meta, connexions,
 *     et ce que l'API ne donne pas ;
 *  3. le BO, s'il partage la base — franchisé et zone (ceo_shop), page
 *     Facebook résolue par Brand Guard, position GPS de la caisse (shops).
 * Sans API joignable, la liste vient du BO (ceo_shop + Brand Guard).
 */
function socShops(): array
{
    // Le BO, s'il est là : par identifiant de caisse (pwa_shop_id).
    $bo = []; $boParId = [];
    if (Db::hasTable('ceo_shop')) {
        foreach (Db::rows("SELECT id, code, name, franchisee, zone, status, pwa_shop_id FROM ceo_shop ORDER BY name") as $r) {
            $x = ['ceoId' => (string) $r['id'], 'name' => (string) $r['name'], 'code' => (string) $r['code'], 'franchisee' => (string) $r['franchisee'],
                'zone' => (string) $r['zone'], 'shopStatus' => (string) $r['status'], 'pwaShopId' => $r['pwa_shop_id'] !== null ? (int) $r['pwa_shop_id'] : null];
            $boParId[(string) $r['id']] = $x;
            $bo[$x['pwaShopId'] !== null ? (string) $x['pwaShopId'] : 'ceo:' . $r['id']] = $x;
        }
    }
    $pages = [];   // clé : identifiant de caisse, sinon « bg<id> »
    if (Db::hasTable('brand_guard_pages')) {
        foreach (Db::rows('SELECT id, shop_id, boutique, franchise, page_url, page_id, page_nom FROM brand_guard_pages WHERE actif = 1 ORDER BY id') as $p) {
            $c = $p['shop_id'] !== null && isset($boParId[(string) $p['shop_id']]) ? $boParId[(string) $p['shop_id']] : null;
            $cle = $c && $c['pwaShopId'] !== null ? (string) $c['pwaShopId'] : ($c ? 'ceo:' . $c['ceoId'] : 'bg' . $p['id']);
            $pages[$cle] = $p;
        }
    }

    $api = socApiShops();
    $liste = [];
    if ($api) {
        foreach ($api as $a) {
            if (array_key_exists('active', $a) && $a['active'] === false) { continue; }
            $liste[$a['id']] = ['api' => $a];
        }
        // Une page Brand Guard sans boutique du cockpit : rattachée par le nom de la ville.
        foreach ($pages as $cle => $p) {
            if (isset($liste[$cle])) { continue; }
            foreach ($liste as $id => $l) {
                if (mb_strtolower(trim((string) $p['boutique'])) === mb_strtolower($l['api']['name']) && !isset($pages[$id])) { $pages[$id] = $p; }
            }
        }
    } else {
        foreach ($bo as $cle => $x) { if ($x['shopStatus'] !== 'Fermé') { $liste[$cle] = []; } }
        foreach ($pages as $cle => $p) { if (!isset($liste[$cle]) && str_starts_with($cle, 'bg')) { $liste[$cle] = ['bg' => $p]; } }
    }

    // La position GPS de la caisse (table shops du panel), si la base la porte.
    $caisse = [];
    // Les clés numériques deviennent des entiers en PHP : on les repasse en texte.
    $ids = array_values(array_filter(array_map('strval', array_keys($liste)), 'ctype_digit'));
    if ($ids && Db::hasTable('shops')) {
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::rows("SELECT * FROM shops WHERE id IN ($in)", array_map('intval', $ids)) as $r) { $caisse[(string) $r['id']] = $r; }
        } catch (Throwable $e) { $caisse = []; }
    }

    $ov = socShopOverrides();
    $out = [];
    foreach ($liste as $id => $l) {
        $id = (string) $id;
        $a = $l['api'] ?? [];
        $x = $bo[$id] ?? null;
        $p = $pages[$id] ?? ($l['bg'] ?? null);
        $c = $caisse[$id] ?? [];
        $o = $ov[$id] ?? [];
        $sources = [];
        if ($a) { $sources[] = 'API ' . ($a['source'] ?? 'shops'); }
        if ($x) { $sources[] = 'BO ceo_shop'; }
        if ($p) { $sources[] = 'Brand Guard'; }
        if ($o) { $sources[] = 'social_boutique'; }
        $nom = (string) (($a['name'] ?? '') ?: ($p['boutique'] ?? '') ?: ($x['name'] ?? $id));
        $adresse = (string) (($a['address'] ?? '') ?: ($o['address'] ?? ''));
        if ($adresse === '' && $c) {
            $rue = socCol($c, ['address', 'street', 'address_line', 'adresse', 'rue']);
            $cp = socCol($c, ['zip', 'postal_code', 'postcode', 'zipcode', 'code_postal']);
            $ville = socCol($c, ['city', 'town', 'locality', 'ville']);
            $adresse = trim(implode(', ', array_filter([$rue, trim(($cp ?? '') . ' ' . ($ville ?? ''))])));
        }
        $geo = $o['geo'] ?? null;
        if (!$geo && isset($a['lat'], $a['lng'])) { $geo = [$a['lat'], $a['lng']]; }
        if (!$geo && $c) {
            $lat = socCol($c, ['latitude', 'lat']); $lng = socCol($c, ['longitude', 'lng', 'lon']);
            if (is_numeric($lat) && is_numeric($lng)) { $geo = [(float) $lat, (float) $lng]; }
        }
        $ids = array_fill_keys(SOC_IDS, '');
        foreach (SOC_IDS as $k) { $ids[$k] = (string) ($o['ids'][$k] ?? ''); }
        if ($ids['page_id'] === '' && $p && $p['page_id']) { $ids['page_id'] = (string) $p['page_id']; }
        $conn = (array) ($o['conn'] ?? []);
        $out[] = [
            'id' => $id, 'name' => $nom, 'fullName' => (string) ($a['fullName'] ?? ''), 'code' => (string) ($x['code'] ?? ''),
            'franchisee' => (string) (($x['franchisee'] ?? '') ?: ($p['franchise'] ?? '')), 'zone' => (string) ($x['zone'] ?? ''),
            'shopStatus' => (string) ($x['shopStatus'] ?? 'Ouvert'), 'pwaShopId' => ctype_digit($id) ? (int) $id : ($x['pwaShopId'] ?? null),
            'email' => (string) ($a['email'] ?? ''), 'phone' => (string) ($a['phone'] ?? ''), 'vat' => (string) ($a['vat'] ?? ''),
            'langs' => array_values(array_filter((array) ($o['langs'] ?? ['fr']), static fn ($l) => in_array($l, ['fr', 'nl'], true))) ?: ['fr'],
            'address' => $adresse,
            'hours' => (string) (($a['hours'] ?? '') ?: ($o['hours'] ?? '')), 'hoursNl' => (string) (($a['hoursNl'] ?? '') ?: ($o['hoursNl'] ?? '')),
            'page' => (string) (($o['page'] ?? '') ?: ($p['page_nom'] ?? '') ?: "L'Atelier By " . $nom),
            'pageUrl' => (string) ($p['page_url'] ?? ''),
            'ig' => (string) ($o['ig'] ?? ''),
            // Une connexion n'est « connectée » qu'une fois VÉRIFIÉE chez Meta.
            'fb' => (string) ($conn['fb'] ?? 'none'), 'igs' => (string) ($conn['igs'] ?? 'none'), 'ads' => (string) ($conn['ads'] ?? 'none'),
            'connDetail' => (array) ($conn['detail'] ?? []), 'checkedAt' => $conn['at'] ?? null,
            'ids' => $ids, 'geo' => $geo, 'sources' => $sources,
        ];
    }
    foreach (socShopsHorsCaisse($out, $ov) as $x) { $out[] = $x; }
    // L'hôte (ERP…) peut compléter ou remplacer la liste (config/hote.php).
    if (($f = socHote('boutiques')) !== null) { $out = array_values((array) $f($out)); }
    return $out;
}

/**
 * Le réseau : les boutiques sans les magasins test. C'est lui que parcourent « toutes les
 * boutiques », les opérations du réseau, les rapports, l'espace franchisé ; un magasin test
 * n'est visé que quand on le choisit (et se retrouve toujours par socShop()).
 */
function socReseau(): array
{
    return array_values(array_filter(socShops(), static fn ($s) => empty($s['test'])));
}

/** « L'Atelier by - Gembloux » → « Gembloux » : le nom d'une boutique d'après sa Page. */
function socNomDePage(string $nom): string
{
    $n = trim((string) preg_replace("/^l['’]\s*atelier\s+by\b\s*[-–—·:]?\s*/iu", '', trim($nom)));
    return $n !== '' ? $n : trim($nom);
}

/**
 * Les boutiques hors caisse (magasins test) : chaque Page Facebook du portefeuille Meta (relu chaque heure
 * par le cron, `meta_portefeuille`) qui n'est ni la Page de la marque ni celle d'une boutique
 * de l'API — un magasin de test, une boutique pas encore ouverte en caisse. Identifiant
 * stable tiré de la Page (`pg` + ses 14 derniers chiffres) ; ses réglages (langues,
 * identifiants Meta, connexions) se gardent dans social_boutique comme pour les autres.
 * Sans caisse : ni webshop, ni ventes, ni accès franchisé.
 */
function socShopsHorsCaisse(array $boutiques, array $ov): array
{
    $pf = socSetting('meta_portefeuille');
    if (!is_array($pf) || !is_array($pf['pages'] ?? null)) { return []; }
    $prises = array_filter(array_map(static fn ($s) => (string) ($s['ids']['page_id'] ?? ''), $boutiques));
    $prises[] = (string) ($ov['marque']['ids']['page_id'] ?? '');
    $nos = array_map(static fn ($s) => (string) $s['id'], $boutiques);
    $out = [];
    foreach ($pf['pages'] as $pg) {
        $pid = preg_replace('/\D/', '', (string) ($pg['id'] ?? ''));
        $rapproche = (string) ($pg['boutique'] ?? '');
        // La Page d'une boutique de caisse (même avant que ses identifiants soient remplis) ou de la marque : déjà là.
        // La marque se reconnaît à son identifiant ou à son nom seul (pas au rapprochement deviné, trop large).
        if ($pid === '' || in_array($pid, $prises, true) || in_array($rapproche, $nos, true) || socEstLaMarque((string) ($pg['name'] ?? ''))) { continue; }
        $id = 'pg' . substr($pid, -14);
        $o = $ov[$id] ?? [];
        $ids = array_fill_keys(SOC_IDS, '');
        foreach (SOC_IDS as $k) { $ids[$k] = (string) ($o['ids'][$k] ?? ''); }
        if ($ids['page_id'] === '') { $ids['page_id'] = $pid; }
        if ($ids['instagram_user_id'] === '' && ($pg['instagram'] ?? '') !== '') { $ids['instagram_user_id'] = (string) $pg['instagram']; }
        $conn = (array) ($o['conn'] ?? []);
        $nom = socNomDePage((string) ($pg['name'] ?? $pid));
        $out[] = [
            'id' => $id, 'name' => $nom, 'fullName' => (string) ($pg['name'] ?? ''), 'code' => '', 'franchisee' => '', 'zone' => '',
            'shopStatus' => 'Magasin test', 'pwaShopId' => null, 'horsCaisse' => true, 'test' => true, 'email' => '', 'phone' => '', 'vat' => '',
            'langs' => array_values(array_filter((array) ($o['langs'] ?? ['fr']), static fn ($l) => in_array($l, ['fr', 'nl'], true))) ?: ['fr'],
            'address' => (string) ($o['address'] ?? ''), 'hours' => (string) ($o['hours'] ?? ''), 'hoursNl' => (string) ($o['hoursNl'] ?? ''),
            'page' => (string) (($o['page'] ?? '') ?: ($pg['name'] ?? '')), 'pageUrl' => '', 'ig' => (string) ($o['ig'] ?? ''),
            'fb' => (string) ($conn['fb'] ?? 'none'), 'igs' => (string) ($conn['igs'] ?? 'none'), 'ads' => (string) ($conn['ads'] ?? 'none'),
            'connDetail' => (array) ($conn['detail'] ?? []), 'checkedAt' => $conn['at'] ?? null,
            'ids' => $ids, 'geo' => $o['geo'] ?? null, 'sources' => ['Page Meta (portefeuille) · magasin test, hors caisse'],
        ];
    }
    return $out;
}

/**
 * La MARQUE (L'Atelier By) : sa Page, son compte Instagram, son compte
 * publicitaire (le compte réseau des publicités) et son pixel — une ligne
 * « marque » de social_boutique, à côté des boutiques.
 */
function socBrand(): array
{
    $o = socShopOverrides()['marque'] ?? [];
    $ids = array_fill_keys(SOC_IDS, '');
    foreach (SOC_IDS as $k) { $ids[$k] = (string) ($o['ids'][$k] ?? ''); }
    // Le compte réseau saisi avant la ligne marque reste valable.
    if ($ids['ad_account_id'] === '') { $ids['ad_account_id'] = (string) (socSetting('meta_compte_reseau') ?? ''); }
    $conn = (array) ($o['conn'] ?? []);
    return ['id' => 'marque', 'name' => (string) ($o['name'] ?? "L'Atelier By"), 'ids' => $ids, 'langs' => ['fr', 'nl'],
        'fb' => (string) ($conn['fb'] ?? 'none'), 'igs' => (string) ($conn['igs'] ?? 'none'), 'ads' => (string) ($conn['ads'] ?? 'none'),
        'connDetail' => (array) ($conn['detail'] ?? []), 'checkedAt' => $conn['at'] ?? null, 'address' => '', 'hours' => '', 'hoursNl' => '', 'geo' => null,
        'ig' => (string) ($o['ig'] ?? '')];
}

function socShop(string $id): ?array
{
    foreach (socShops() as $s) { if ($s['id'] === $id) { return $s; } }
    return null;
}

/**
 * Vérifie chez Meta les trois connexions de chaque boutique (ou d'une seule) :
 * Page Facebook accessible au System User, compte Instagram professionnel
 * rattaché, compte publicitaire actif. Le résultat est écrit, avec l'heure.
 */
function socTestConnexions(?string $seule = null): array
{
    $shops = array_values(array_filter(array_merge([socBrand()], socShops()), static fn ($s) => $seule === null || $s['id'] === $seule));
    if ($seule === null) { Meta::verifierJeton(); }
    $now = date('Y-m-d H:i:s');
    $tokens = null; $global = null;
    if (!Meta::configured()) {
        $global = ['none', 'Token Meta absent : à saisir dans « Clés d’accès Meta » (onglet API Meta)'];
    } else {
        try { $tokens = Meta::pageTokens(true); }
        catch (MetaError $e) { $global = [$e->metaCode === 190 ? 'expired' : 'none', $e->getMessage()]; }
    }
    $out = [];
    $unique = socIgUnique(); $igMarque = null;
    if ($unique && $seule !== null && $seule !== 'marque') { $b = socBrand(); $igMarque = [$b['igs'], $b['connDetail']['igs'] ?? '']; }
    foreach ($shops as $s) {
        $c = ['fb' => 'none', 'igs' => 'none', 'ads' => 'none', 'detail' => [], 'at' => $now];
        if ($global !== null) {
            $c['fb'] = $c['igs'] = $c['ads'] = $global[0];
            $c['detail'] = ['fb' => $global[1], 'igs' => $global[1], 'ads' => $global[1]];
        } else {
            $pid = $s['ids']['page_id'];
            $page = $pid !== '' ? ($tokens[$pid] ?? null) : null;
            if ($pid === '') { $c['detail']['fb'] = 'page_id non renseigné (Paramètres → API Meta, ou Brand Guard dans le cockpit)'; }
            elseif ($page === null) { $c['detail']['fb'] = 'Page ' . $pid . ' non attribuée au System User'; }
            else { $c['fb'] = 'connected'; $c['detail']['fb'] = $page['name']; }

            $ig = $s['ids']['instagram_user_id'] !== '' ? $s['ids']['instagram_user_id'] : (string) ($page['ig'] ?? '');
            if ($unique && $s['id'] !== 'marque' && $igMarque !== null) {
                // Instagram unique : chaque boutique publie sur le compte de la marque.
                $c['igs'] = $igMarque[0]; $c['detail']['igs'] = 'Instagram du réseau · ' . $igMarque[1];
            }
            elseif ($ig === '') { $c['detail']['igs'] = 'Aucun compte Instagram professionnel rattaché à la Page'; }
            else {
                try {
                    $r = Meta::call('GET', $ig, ['fields' => 'id,username'], socIgToken($ig, $pid) ?? ($page['token'] ?? null));
                    $c['igs'] = 'connected'; $c['detail']['igs'] = '@' . ($r['username'] ?? $ig);
                    if ($s['id'] === 'marque') { $igMarque = ['connected', '@' . ($r['username'] ?? $ig)]; }
                    $patch = ['ig' => '@' . ($r['username'] ?? '')];
                    if ($s['ids']['instagram_user_id'] === '') { $patch['ids'] = array_replace($s['ids'], ['instagram_user_id' => $ig]); }
                    if (($r['username'] ?? '') !== '') { socShopPut($s['id'], $patch); }
                } catch (MetaError $e) {
                    $c['igs'] = $e->metaCode === 190 ? 'expired' : 'none'; $c['detail']['igs'] = $e->getMessage();
                    if ($s['id'] === 'marque') { $igMarque = [$c['igs'], $e->getMessage()]; }
                }
            }
            if ($unique && $s['id'] === 'marque' && $igMarque === null) { $igMarque = [$c['igs'], $c['detail']['igs'] ?? '']; }

            $act = socActId($s['ids']['ad_account_id']);
            if ($act === '') { $c['detail']['ads'] = 'ad_account_id non renseigné'; }
            else {
                try {
                    $r = Meta::call('GET', $act, ['fields' => 'name,account_status']);
                    $ok = (int) ($r['account_status'] ?? 0) === 1;
                    $c['ads'] = $ok ? 'connected' : 'none';
                    $st = (int) ($r['account_status'] ?? 0);
                    $c['detail']['ads'] = ($r['name'] ?? $act) . ($ok ? '' : ' · compte ' . (defined('SOC_COMPTE_STATUTS') && isset(SOC_COMPTE_STATUTS[$st]) ? SOC_COMPTE_STATUTS[$st] : 'inactif') . ' chez Meta (statut ' . $st . ')');
                } catch (MetaError $e) { $c['ads'] = $e->metaCode === 190 ? 'expired' : 'none'; $c['detail']['ads'] = $e->getMessage(); }
            }
        }
        socShopPut($s['id'], ['conn' => $c]);
        $out[] = ['shop' => $s['id'], 'name' => $s['name']] + $c;
    }
    return $out;
}

/** `1428801` ou `act_1428801` → `act_1428801` ; vide → vide. */
function socActId(string $v): string
{
    $v = trim($v);
    if ($v === '') { return ''; }
    return str_starts_with($v, 'act_') ? $v : 'act_' . $v;
}
