<?php
declare(strict_types=1);

/**
 * Écran « Retour sur campagne » — ce que la campagne a coûté et rapporté,
 * boutique par boutique.
 *
 *  - CÔTÉ META : `GET /{ad_id}/insights` sur chaque pub de la campagne
 *    (spend, reach, impressions, clicks, actions), sur les dates de la
 *    campagne ou jusqu'à aujourd'hui si elle court encore ;
 *  - CÔTÉ VENTES : la caisse du réseau, lue dans la base partagée avec le BO
 *    (`transaction`, `transaction_product`, `product`, boutique =
 *    `ceo_shop.pwa_shop_id`) — jamais la caisse directement.
 *
 * CA additionnel = CA des produits de la campagne pendant la période − moyenne
 * des mêmes jours sur les 6 semaines précédentes. C'est une ESTIMATION, et
 * l'écran l'affiche comme telle. Marge, ROAS et ROI se calculent à l'écran
 * (social-data.js, `roi()`), avec le taux de marge du réglage.
 *
 * Le cron recalcule ; l'écran lit `social_resultat`.
 */

function socResults(): array
{
    $out = [];
    foreach (Db::rows('SELECT campagne_id, shop_id, data FROM social_resultat') as $r) {
        $d = json_decode((string) $r['data'], true);
        if (is_array($d)) { $out[(string) $r['campagne_id']][(string) $r['shop_id']] = $d; }
    }
    return $out;
}

/**
 * Les ventes des produits d'une campagne pour une boutique : période et
 * référence (moyenne des mêmes jours, 6 semaines avant). null + motif si la
 * caisse ne peut pas répondre.
 *
 * @return array{0:?array,1:?string}
 */
function socVentes(?int $pwaShop, array $produits, string $du, string $au): array
{
    if ($pwaShop === null) { return [null, 'Boutique non reliée à la caisse (ceo_shop.pwa_shop_id vide)']; }
    foreach (['transaction', 'transaction_product', 'product'] as $t) {
        if (!Db::hasTable($t)) { return [null, 'Caisse non disponible dans cette base (table ' . $t . ' absente)']; }
    }
    $noms = array_values(array_unique(array_filter(array_map(static fn ($n) => mb_strtolower(trim((string) $n)), $produits))));
    if (!$noms) { return [null, 'Aucun produit rattaché à la campagne']; }
    $in = implode(',', array_fill(0, count($noms), '?'));
    $ids = array_map(static fn ($r) => (int) $r['id'], Db::rows("SELECT id FROM product WHERE LOWER(TRIM(name)) IN ($in)", $noms));
    if (!$ids) { return [null, 'Produits de la campagne introuvables dans la caisse (' . implode(', ', $produits) . ')']; }
    $inP = implode(',', $ids);
    $somme = static function (string $de, string $a) use ($pwaShop, $inP): array {
        $r = Db::row("SELECT /*+ MAX_EXECUTION_TIME(20000) */ COALESCE(SUM(l.total_gross_value_after_discount), 0) ca, COALESCE(SUM(l.quantity), 0) q, COUNT(DISTINCT t.id) tk
                        FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                       WHERE t.id_shop = ? AND t.insert_timestamp >= ? AND t.insert_timestamp < ? AND l.id_product IN ($inP)",
            [$pwaShop, $de . ' 00:00:00', $a . ' 00:00:00']);
        return [(float) ($r['ca'] ?? 0), (float) ($r['q'] ?? 0), (int) ($r['tk'] ?? 0)];
    };
    $fin = (new DateTimeImmutable($au))->modify('+1 day')->format('Y-m-d');
    try {
        [$ca, $q, $tk] = $somme($du, $fin);
        $ref = [0.0, 0.0, 0];
        for ($k = 1; $k <= 6; $k++) {
            $d = (new DateTimeImmutable($du))->modify('-' . (7 * $k) . ' days')->format('Y-m-d');
            $f = (new DateTimeImmutable($fin))->modify('-' . (7 * $k) . ' days')->format('Y-m-d');
            [$a, $b, $c] = $somme($d, $f);
            $ref[0] += $a; $ref[1] += $b; $ref[2] += $c;
        }
    } catch (Throwable $e) {
        return [null, 'Lecture de la caisse impossible : ' . $e->getMessage()];
    }
    if ($ca == 0.0 && $ref[0] == 0.0) { return [null, 'Aucune vente de ces produits remontée par la caisse sur la période']; }
    return [['caCamp' => round($ca, 2), 'caRef' => round($ref[0] / 6, 2), 'tCamp' => $tk, 'tRef' => (int) round($ref[2] / 6),
        'qCamp' => round($q, 1), 'qRef' => round($ref[1] / 6, 1)], null];
}

/** Recalcule les résultats des campagnes en cours ou finies depuis moins de 60 jours. */
function socResultsSync(?string $seule = null): int
{
    $shops = []; foreach (socShops() as $s) { $shops[$s['id']] = $s; }
    $today = date('Y-m-d');
    $limite = (new DateTimeImmutable('-60 days'))->format('Y-m-d');
    $ads = socItems('ads');
    $n = 0;
    foreach (socItems('campaigns') as $c) {
        if ($seule !== null && $c['id'] !== $seule) { continue; }
        if (($c['start'] ?? '9999') > $today || ($c['end'] ?? '0000') < $limite) { continue; }
        $du = (string) $c['start'];
        $au = min((string) $c['end'], $today);
        $meta = [];
        if (Meta::configured()) {
            foreach ($ads as $ad) {
                if (($ad['campaign'] ?? null) !== $c['id']) { continue; }
                foreach (socMetaObjects('ad', (string) $ad['id']) as $o) {
                    if (!$o['ad_id']) { continue; }
                    try {
                        $r = Meta::call('GET', (string) $o['ad_id'] . '/insights', ['fields' => 'spend,reach,impressions,clicks,actions',
                            'time_range' => ['since' => $du, 'until' => $au]]);
                    } catch (MetaError $e) { continue; }
                    $x = $r['data'][0] ?? null;
                    if (!$x) { continue; }
                    $sid = (string) $o['shop_id'];
                    $m = $meta[$sid] ?? ['spend' => 0.0, 'reach' => 0, 'impr' => 0, 'clicks' => 0, 'eng' => 0, 'vv' => 0];
                    $m['spend'] += (float) ($x['spend'] ?? 0); $m['reach'] += (int) ($x['reach'] ?? 0);
                    $m['impr'] += (int) ($x['impressions'] ?? 0); $m['clicks'] += (int) ($x['clicks'] ?? 0);
                    foreach ((array) ($x['actions'] ?? []) as $a) {
                        if (($a['action_type'] ?? '') === 'post_engagement') { $m['eng'] += (int) $a['value']; }
                        if (($a['action_type'] ?? '') === 'video_view') { $m['vv'] += (int) $a['value']; }
                    }
                    $meta[$sid] = $m;
                }
            }
        }
        foreach ((array) ($c['shops'] ?? []) as $sid) {
            $shop = $shops[$sid] ?? null;
            if (!$shop) { continue; }
            $m = $meta[$sid] ?? ['spend' => 0.0, 'reach' => 0, 'impr' => 0, 'clicks' => 0, 'eng' => 0, 'vv' => 0];
            // Les ventes : l'API TFBuddy d'abord, la caisse de la base partagée en repli.
            [$v, $motif] = Tfb::configured('admin') ? socVentesApi((string) $sid, (array) ($c['products'] ?? []), $du, $au) : [null, 'compte API non configuré'];
            if ($v === null) {
                [$v2, $motif2] = socVentes($shop['pwaShopId'], (array) ($c['products'] ?? []), $du, $au);
                if ($v2 !== null) { $v = $v2 + ['source' => 'base (caisse)']; $motif = null; } else { $motif = $motif . ' · base : ' . $motif2; }
            }
            $d = $m + ($v ?? ['caRef' => null, 'caCamp' => null, 'tRef' => null, 'tCamp' => null, 'qRef' => null, 'qCamp' => null]);
            $d['spend'] = round((float) $d['spend'], 2);
            if ($motif !== null) { $d['noSales'] = $motif; }
            Db::exec('INSERT INTO social_resultat (campagne_id, shop_id, depense, portee, impressions, clics, ca_campagne, ca_reference, data, synchro_le)
                      VALUES (?,?,?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE depense = VALUES(depense), portee = VALUES(portee), impressions = VALUES(impressions), clics = VALUES(clics),
                        ca_campagne = VALUES(ca_campagne), ca_reference = VALUES(ca_reference), data = VALUES(data), synchro_le = VALUES(synchro_le)',
                [(string) $c['id'], (string) $sid, $d['spend'], $d['reach'], $d['impr'], $d['clicks'], $d['caCamp'], $d['caRef'],
                 json_encode($d, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
            $n++;
        }
    }
    return $n;
}
