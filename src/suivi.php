<?php
declare(strict_types=1);

/**
 * Suivi des boutiques : abonnés gagnés ou perdus face au budget publicitaire,
 * Page par Page (et son compte Instagram). Tout est lu en direct chez Meta à
 * chaque demande — rien n'est copié en base.
 *
 *  - abonnés actuels : `followers_count` (Page, Instagram) ;
 *  - jour par jour : insights de la Page `page_daily_follows_unique` /
 *    `page_daily_unfollows_unique` (repli : `page_follows`, total du jour),
 *    insights Instagram `follower_count` (nouveaux abonnés, 30 jours au plus) ;
 *  - budget : insights des comptes publicitaires au niveau pub, jour par jour,
 *    rapportés à la Page qui porte la pub (créa : `actor_id`).
 *
 * Permissions : `read_insights` (Pages) et `instagram_manage_insights`
 * (Instagram) pour l'historique ; sans elles, seuls les totaux actuels et le
 * budget s'affichent, avec le motif.
 */

const SOC_SUIVI_PERIODES = [7, 28, 90];

/**
 * Les insights quotidiens d'un objet Meta sur [du, au], par tranches de 90 jours : Meta refuse plus de 93 jours
 * entre `since` et `until`. Rend la forme d'une réponse de Meta, les valeurs des tranches mises bout à bout.
 */
function socSuiviInsightsJour(string $objet, array $metriques, string $du, string $au, ?string $token): array
{
    $parNom = [];
    for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($fin . ' +1 day'))) {
        $fin = min($au, date('Y-m-d', strtotime($d . ' +89 days')));
        $r = Meta::call('GET', $objet . '/insights', ['metric' => implode(',', $metriques), 'period' => 'day', 'since' => $d,
            'until' => date('Y-m-d', strtotime($fin . ' +1 day'))], $token);
        foreach ((array) ($r['data'] ?? []) as $m) {
            $nom = (string) ($m['name'] ?? '');
            $parNom[$nom] ??= array_merge($m, ['values' => []]);
            $parNom[$nom]['values'] = array_merge($parNom[$nom]['values'], (array) ($m['values'] ?? []));
        }
    }
    return ['data' => array_values($parNom)];
}

/** Série d'insights Meta → [jour => valeur] (le jour qui se termine à `end_time`). */
function socSuiviSerie(array $metric): array
{
    $out = [];
    foreach ((array) ($metric['values'] ?? []) as $v) {
        $t = strtotime((string) ($v['end_time'] ?? ''));
        if ($t === false || !is_numeric($v['value'] ?? null)) { continue; }
        $out[date('Y-m-d', $t - 86400)] = (int) $v['value'];
    }
    ksort($out);
    return $out;
}

/** Un refus d'insights dû à une permission manquante ? */
function socSuiviPermission(MetaError $e): bool
{
    return in_array($e->metaCode, [10, 200, 190], true) || (bool) preg_match('/permission/i', $e->getMessage());
}

/** Le motif lisible d'un refus d'insights. */
function socSuiviMotif(MetaError $e, string $perm): string
{
    return socSuiviPermission($e) ? 'historique indisponible : ajoutez la permission ' . $perm . ' au token (' . $e->getMessage() . ')' : 'historique indisponible : ' . $e->getMessage();
}

/** Les abonnés d'une Page : total actuel et, si Meta le permet, gains et pertes par jour. */
function socSuiviPage(array $c, string $du, string $au): array
{
    $out = ['followers' => null, 'name' => $c['name'], 'username' => '', 'picture' => null, 'link' => null, 'serie' => null, 'gagnes' => null, 'perdus' => null, 'net' => null, 'motif' => null];
    try {
        $pr = Meta::call('GET', $c['id'], ['fields' => 'name,username,link,followers_count,fan_count,picture.type(large){url}'], $c['token']);
        $out['followers'] = isset($pr['followers_count']) ? (int) $pr['followers_count'] : (isset($pr['fan_count']) ? (int) $pr['fan_count'] : null);
        $out['name'] = (string) ($pr['name'] ?? $c['name']); $out['username'] = (string) ($pr['username'] ?? '');
        $out['picture'] = (string) ($pr['picture']['data']['url'] ?? '') ?: null; $out['link'] = (string) ($pr['link'] ?? '') ?: null;
    } catch (MetaError $e) { $out['motif'] = 'Page illisible : ' . $e->getMessage(); return $out; }
    // Meta a changé plusieurs fois ces métriques : on essaie dans l'ordre ; une réponse vide compte comme un échec.
    $essais = [
        ['page_follows'],                                     // total d'abonnés du jour → variation
        ['page_daily_follows_unique', 'page_daily_unfollows_unique'],
        ['page_daily_follows'],                               // gains seuls
        ['page_fan_adds_unique', 'page_fan_removes_unique'],  // anciennes métriques
    ];
    $refus = []; $permission = null;
    foreach ($essais as $metriques) {
        try {
            $r = socSuiviInsightsJour($c['id'], $metriques, $du, $au, $c['token']);
        } catch (MetaError $e) {
            if (socSuiviPermission($e)) { $permission ??= $e; }
            $refus[] = implode('+', $metriques) . ' : ' . $e->getMessage();
            continue;
        }
        $par = [];
        foreach ((array) ($r['data'] ?? []) as $m) { $s2 = socSuiviSerie($m); if ($s2) { $par[(string) ($m['name'] ?? '')] = $s2; } }
        $serie = [];
        if (isset($par['page_follows'])) {
            $prec = null;
            foreach ($par['page_follows'] as $j => $v) { if ($prec !== null) { $d = $v - $prec; $serie[$j] = ['gagnes' => max(0, $d), 'perdus' => max(0, -$d)]; } $prec = $v; }
        } elseif ($par) {
            $g = $par[$metriques[0]] ?? []; $p = isset($metriques[1]) ? ($par[$metriques[1]] ?? []) : [];
            foreach (array_unique(array_merge(array_keys($g), array_keys($p))) as $j) { $serie[$j] = ['gagnes' => $g[$j] ?? 0, 'perdus' => $p[$j] ?? 0]; }
        }
        if (!$serie) { $refus[] = implode('+', $metriques) . ' : aucune valeur'; continue; }
        ksort($serie);
        $out['serie'] = $serie;
        break;
    }
    if ($out['serie'] === null) {
        $out['motif'] = $permission !== null ? socSuiviMotif($permission, 'read_insights')
            : 'historique indisponible : Meta renvoie des séries vides — ajoutez la permission read_insights au token (détail : ' . implode(' · ', $refus) . ')';
    }
    if (is_array($out['serie'])) {
        $out['gagnes'] = array_sum(array_column($out['serie'], 'gagnes')); $out['perdus'] = array_sum(array_column($out['serie'], 'perdus'));
        $out['net'] = $out['gagnes'] - $out['perdus'];
    }
    return $out;
}

/** Les abonnés d'un compte Instagram : total actuel et nouveaux abonnés par jour (30 jours au plus chez Meta). */
function socSuiviInstagram(array $c, string $du, string $au): array
{
    $out = ['id' => $c['id'], 'followers' => null, 'username' => '', 'picture' => null, 'serie' => null, 'gagnes' => null, 'motif' => null];
    try {
        $pr = Meta::call('GET', $c['id'], ['fields' => 'username,name,profile_picture_url,followers_count'], $c['token']);
        $out['followers'] = isset($pr['followers_count']) ? (int) $pr['followers_count'] : null;
        $out['username'] = (string) ($pr['username'] ?? ''); $out['picture'] = (string) ($pr['profile_picture_url'] ?? '') ?: null;
    } catch (MetaError $e) { $out['motif'] = 'compte illisible : ' . $e->getMessage(); return $out; }
    $du30 = max($du, date('Y-m-d', strtotime('-30 days')));
    try {
        $r = Meta::call('GET', $c['id'] . '/insights', ['metric' => 'follower_count', 'period' => 'day', 'since' => strtotime($du30), 'until' => strtotime($au . ' +1 day')], $c['token']);
        $s = socSuiviSerie((array) ($r['data'][0] ?? []));
        $out['serie'] = array_map(static fn ($v) => ['gagnes' => $v, 'perdus' => 0], $s);
        $out['gagnes'] = array_sum($s);
        if ($du30 > $du) { $out['motif'] = 'Instagram ne donne que les 30 derniers jours'; }
    } catch (MetaError $e) { $out['motif'] = socSuiviMotif($e, 'instagram_manage_insights'); }
    return $out;
}

/**
 * Le budget dépensé jour par jour, par Page : chaque pub est rapportée à la
 * Page qui la porte (créa), sinon à la boutique connue du module.
 * Rend [page_id|'' => ['total' => x, 'jours' => [jour => x]], 'devise' => …, 'erreurs' => […]].
 */
function socSuiviDepenses(string $du, string $au, bool $parJour = true): array
{
    $parPage = []; $devises = []; $erreurs = [];
    $pageDeBoutique = [];
    foreach (socShops() as $s) { if (($s['ids']['page_id'] ?? '') !== '') { $pageDeBoutique[(string) $s['id']] = (string) $s['ids']['page_id']; } }
    $bp = (string) (socBrand()['ids']['page_id'] ?? '');
    if ($bp !== '') { $pageDeBoutique['marque'] = $bp; }
    $connus = [];
    foreach (Db::rows("SELECT ad_id, shop_id FROM social_meta_objet WHERE kind = 'ad' AND ad_id IS NOT NULL") as $r) { $connus[(string) $r['ad_id']] = $pageDeBoutique[(string) $r['shop_id']] ?? ''; }
    foreach (socComptesPub() as $act => $cpt) {
        try {
            $info = Meta::call('GET', $act, ['fields' => 'currency']);
            if (!empty($info['currency'])) { $devises[(string) $info['currency']] = true; }
            // Sans le détail par jour : une ligne par pub sur toute la période (un an jour par jour dépasserait la limite de lecture).
            $lignes = socGraphTout($act . '/insights', ['level' => 'ad', 'fields' => 'ad_id,spend', 'time_range' => ['since' => $du, 'until' => $au], 'limit' => 500]
                + ($parJour ? ['time_increment' => 1] : []), 5000);
            if (!$lignes) { continue; }
            $pageDePub = [];
            foreach (socGraphTout($act . '/ads', ['fields' => 'id,creative{actor_id,effective_object_story_id}', 'limit' => 200,
                'effective_status' => ['ACTIVE', 'PAUSED', 'ARCHIVED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'DISAPPROVED', 'PENDING_REVIEW', 'WITH_ISSUES', 'IN_PROCESS']], 5000) as $ad) {
                $cr = (array) ($ad['creative'] ?? []);
                $story = (string) ($cr['effective_object_story_id'] ?? '');
                $pageDePub[(string) $ad['id']] = (string) ($cr['actor_id'] ?? '') ?: ($story !== '' ? explode('_', $story)[0] : '');
            }
            foreach ($lignes as $l) {
                $ad = (string) ($l['ad_id'] ?? '');
                $page = ($pageDePub[$ad] ?? '') ?: ($connus[$ad] ?? '');
                $j = substr((string) ($l['date_start'] ?? ''), 0, 10);
                $v = (float) ($l['spend'] ?? 0);
                if ($j === '' || $v <= 0) { continue; }
                if ($parJour) { $parPage[$page]['jours'][$j] = round(($parPage[$page]['jours'][$j] ?? 0) + $v, 2); }
                $parPage[$page]['total'] = round(($parPage[$page]['total'] ?? 0) + $v, 2);
            }
        } catch (MetaError $e) { $erreurs[] = $act . ' : ' . $e->getMessage(); }
    }
    return ['pages' => $parPage, 'devise' => count($devises) === 1 ? (string) array_key_first($devises) : ($devises ? implode(' + ', array_keys($devises)) : 'EUR'), 'erreurs' => $erreurs];
}

/** Le rapport complet pour l'écran « Suivi des boutiques ». */
function socSuiviAbonnes(int $jours, ?string $seulePage = null, bool $parJour = true): array
{
    if (!Meta::configured()) { throw new HttpError(409, 'token Meta absent : saisissez-le dans Paramètres → API Meta → Clés d’accès Meta'); }
    // Sans le détail par jour (le bilan des boutiques n'a besoin que des totaux), toute période jusqu'à un an.
    $jours = in_array($jours, SOC_SUIVI_PERIODES, true) || !$parJour ? max(1, min(366, $jours)) : 28;
    $au = date('Y-m-d', strtotime('-1 day'));  // les insights d'un jour sont complets le lendemain
    $du = date('Y-m-d', strtotime($au . ' -' . ($jours - 1) . ' days'));
    $erreurs = [];
    try { $comptes = socCalComptes(); } catch (MetaError $e) { $comptes = []; $erreurs[] = 'Pages du System User : ' . $e->getMessage(); }
    $dep = socSuiviDepenses($du, $au, $parJour);
    $erreurs = array_merge($erreurs, $dep['erreurs']);
    $noms = array_column(socShops(), 'name', 'id');
    $tests = array_column(array_filter(socShops(), static fn ($s) => !empty($s['test'])), 'id');
    $lignes = [];
    foreach ($comptes as $c) {
        if ($c['type'] !== 'facebook' || ($seulePage !== null && $c['id'] !== $seulePage)) { continue; }
        // Un magasin test reste hors du suivi du réseau (sauf demandé seul).
        if ($seulePage === null && in_array((string) $c['shop'], $tests, true)) { continue; }
        $fb = socSuiviPage($c, $du, $au);
        $ig = null;
        foreach ($comptes as $c2) { if ($c2['type'] === 'instagram' && ($c2['page'] ?? null) === $c['id']) { $ig = socSuiviInstagram($c2, $du, $au); break; } }
        $d = $dep['pages'][$c['id']] ?? ['total' => 0.0, 'jours' => []];
        $gain = ($fb['net'] ?? 0) + ($ig['gagnes'] ?? 0);
        $connu = $fb['net'] !== null || ($ig['gagnes'] ?? null) !== null;
        $lignes[] = ['page' => $c['id'], 'name' => $fb['name'], 'username' => $fb['username'], 'picture' => $fb['picture'], 'link' => $fb['link'],
            'shop' => $c['shop'], 'shopName' => $c['shop'] === 'marque' ? 'Marque' : ($noms[$c['shop']] ?? null),
            'facebook' => $fb, 'instagram' => $ig, 'depense' => (float) ($d['total'] ?? 0), 'depenseJours' => $d['jours'] ?? [],
            'abonnesNet' => $connu ? $gain : null, 'coutParAbonne' => $connu && $gain > 0 && ($d['total'] ?? 0) > 0 ? round($d['total'] / $gain, 2) : null];
    }
    usort($lignes, static fn ($a, $b) => ($b['depense'] <=> $a['depense']) ?: strcmp((string) $a['name'], (string) $b['name']));
    $nonAttribue = (float) ($dep['pages']['']['total'] ?? 0);
    return ['du' => $du, 'au' => $au, 'jours' => $jours, 'devise' => $dep['devise'], 'lignes' => $lignes, 'nonAttribue' => $nonAttribue,
        'erreurs' => $erreurs, 'le' => date('Y-m-d H:i')];
}
