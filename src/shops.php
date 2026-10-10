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
 * {id, name, langs, address, hours, hoursNl, page, ig, fb, igs, ads, ids, geo, …}
 */
function socShops(): array
{
    $base = [];
    if (Db::hasTable('ceo_shop')) {
        foreach (Db::rows("SELECT id, code, name, franchisee, zone, status, pwa_shop_id FROM ceo_shop WHERE status <> 'Fermé' ORDER BY name") as $r) {
            $base[(string) $r['id']] = ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'code' => (string) $r['code'],
                'franchisee' => (string) $r['franchisee'], 'zone' => (string) $r['zone'], 'shopStatus' => (string) $r['status'],
                'pwaShopId' => $r['pwa_shop_id'] !== null ? (int) $r['pwa_shop_id'] : null];
        }
    }
    $pages = [];
    if (Db::hasTable('brand_guard_pages')) {
        foreach (Db::rows('SELECT id, shop_id, boutique, franchise, page_url, page_id, page_nom FROM brand_guard_pages WHERE actif = 1 ORDER BY id') as $p) {
            $sid = $p['shop_id'] !== null && $p['shop_id'] !== '' ? (string) $p['shop_id'] : null;
            // Une page sans boutique du cockpit reste une boutique du réseau :
            // elle publie, on la montre (identifiant stable « bg<id> »).
            // Rattachée à une boutique fermée (absente de $base) : on ne la montre pas.
            if ($sid !== null && !isset($base[$sid]) && Db::hasTable('ceo_shop')) { continue; }
            $sid = $sid ?? ('bg' . $p['id']);
            if (!isset($base[$sid])) {
                $base[$sid] = ['id' => $sid, 'name' => (string) $p['boutique'], 'code' => '', 'franchisee' => (string) $p['franchise'],
                    'zone' => '', 'shopStatus' => 'Ouvert', 'pwaShopId' => null];
            }
            $pages[$sid] = $p;
            if (trim((string) $p['boutique']) !== '') { $base[$sid]['short'] = (string) $p['boutique']; }
        }
    }

    // L'adresse et la position, lues dans la caisse du panel si elle les porte.
    $caisse = [];
    $pwa = array_values(array_filter(array_map(static fn ($s) => $s['pwaShopId'], $base)));
    if ($pwa && Db::hasTable('shops')) {
        try {
            $in = implode(',', array_fill(0, count($pwa), '?'));
            foreach (Db::rows("SELECT * FROM shops WHERE id IN ($in)", $pwa) as $r) { $caisse[(int) $r['id']] = $r; }
        } catch (Throwable $e) { $caisse = []; }
    }

    $ov = socShopOverrides();
    $out = [];
    foreach ($base as $id => $s) {
        $o = $ov[$id] ?? [];
        $p = $pages[$id] ?? null;
        $c = $s['pwaShopId'] !== null ? ($caisse[$s['pwaShopId']] ?? []) : [];
        $nom = (string) ($o['name'] ?? ($s['short'] ?? $s['name']));
        $adresse = $o['address'] ?? null;
        if ($adresse === null && $c) {
            $rue = socCol($c, ['address', 'street', 'address_line', 'adresse', 'rue']);
            $cp = socCol($c, ['zip', 'postal_code', 'postcode', 'zipcode', 'code_postal']);
            $ville = socCol($c, ['city', 'town', 'locality', 'ville']);
            $adresse = trim(implode(', ', array_filter([$rue, trim(($cp ?? '') . ' ' . ($ville ?? ''))])));
        }
        $geo = $o['geo'] ?? null;
        if (!$geo && $c) {
            $lat = socCol($c, ['latitude', 'lat']); $lng = socCol($c, ['longitude', 'lng', 'lon']);
            if (is_numeric($lat) && is_numeric($lng)) { $geo = [(float) $lat, (float) $lng]; }
        }
        $ids = array_fill_keys(SOC_IDS, '');
        foreach (SOC_IDS as $k) { $ids[$k] = (string) ($o['ids'][$k] ?? ''); }
        if ($ids['page_id'] === '' && $p && $p['page_id']) { $ids['page_id'] = (string) $p['page_id']; }
        $conn = (array) ($o['conn'] ?? []);
        $out[] = [
            'id' => (string) $id, 'name' => $nom, 'code' => $s['code'], 'franchisee' => $s['franchisee'], 'zone' => $s['zone'],
            'shopStatus' => $s['shopStatus'], 'pwaShopId' => $s['pwaShopId'],
            'langs' => array_values(array_filter((array) ($o['langs'] ?? ['fr']), static fn ($l) => in_array($l, ['fr', 'nl'], true))) ?: ['fr'],
            'address' => (string) ($adresse ?? ''), 'hours' => (string) ($o['hours'] ?? ''), 'hoursNl' => (string) ($o['hoursNl'] ?? ''),
            'page' => (string) ($o['page'] ?? ($p['page_nom'] ?? '') ?: "L'Atelier By " . $nom),
            'pageUrl' => (string) ($p['page_url'] ?? ''),
            'ig' => (string) ($o['ig'] ?? ''),
            // Une connexion n'est « connectée » qu'une fois VÉRIFIÉE chez Meta
            // (bouton Tester, ou le contrôle du cron) — jamais par défaut.
            'fb' => (string) ($conn['fb'] ?? 'none'), 'igs' => (string) ($conn['igs'] ?? 'none'), 'ads' => (string) ($conn['ads'] ?? 'none'),
            'connDetail' => (array) ($conn['detail'] ?? []), 'checkedAt' => $conn['at'] ?? null,
            'ids' => $ids, 'geo' => $geo,
        ];
    }
    return $out;
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
    $shops = array_values(array_filter(socShops(), static fn ($s) => $seule === null || $s['id'] === $seule));
    $now = date('Y-m-d H:i:s');
    $tokens = null; $global = null;
    if (!Meta::configured()) {
        $global = ['none', 'Token Meta absent : META_SYSTEM_TOKEN (ou bloc meta du config du BO) à renseigner'];
    } else {
        try { $tokens = Meta::pageTokens(true); }
        catch (MetaError $e) { $global = [$e->metaCode === 190 ? 'expired' : 'none', $e->getMessage()]; }
    }
    $out = [];
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
            if ($ig === '') { $c['detail']['igs'] = 'Aucun compte Instagram professionnel rattaché à la Page'; }
            else {
                try {
                    $r = Meta::call('GET', $ig, ['fields' => 'id,username'], $page['token'] ?? null);
                    $c['igs'] = 'connected'; $c['detail']['igs'] = '@' . ($r['username'] ?? $ig);
                    $patch = ['ig' => '@' . ($r['username'] ?? '')];
                    if ($s['ids']['instagram_user_id'] === '') { $patch['ids'] = array_replace($s['ids'], ['instagram_user_id' => $ig]); }
                    if (($r['username'] ?? '') !== '') { socShopPut($s['id'], $patch); }
                } catch (MetaError $e) { $c['igs'] = $e->metaCode === 190 ? 'expired' : 'none'; $c['detail']['igs'] = $e->getMessage(); }
            }

            $act = socActId($s['ids']['ad_account_id']);
            if ($act === '') { $c['detail']['ads'] = 'ad_account_id non renseigné'; }
            else {
                try {
                    $r = Meta::call('GET', $act, ['fields' => 'name,account_status']);
                    $ok = (int) ($r['account_status'] ?? 0) === 1;
                    $c['ads'] = $ok ? 'connected' : 'none';
                    $c['detail']['ads'] = ($r['name'] ?? $act) . ($ok ? '' : ' · compte inactif (statut ' . ($r['account_status'] ?? '?') . ')');
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
