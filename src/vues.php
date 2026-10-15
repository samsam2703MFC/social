<?php
declare(strict_types=1);

/**
 * Analyse des vues d'un compte : jour par jour, ce que Meta compte comme vues de la
 * Page Facebook (ou, à défaut, ses visites) et, pour Instagram, les vues de la période
 * et les comptes touchés par jour ; puis les vues de chaque publication de la période.
 * Tout est lu en direct chez Meta à chaque demande — rien n'est copié en base.
 *
 *  - Page : insights `page_media_view` (les « vues » depuis 2025), repli
 *    `page_impressions` puis `page_views_total` (visites de la Page), période jour ;
 *  - Instagram : `views` (total de la période, par tranches de 30 jours) et `reach`
 *    en série jour par jour (30 derniers jours au plus) — Meta ne donne pas les vues
 *    Instagram jour par jour ;
 *  - publications : `post_media_view` / `views` lus en même temps que la liste
 *    (`insights.metric(…)`), sinon publication par publication.
 *
 * Permissions : `read_insights` (Pages), `instagram_manage_insights` (Instagram).
 */

const SOC_VUES_PAGE = [['page_media_view', 'vues'], ['page_impressions', 'vues'], ['page_views_total', 'visites']];
const SOC_VUES_PUBLICATIONS = 40;  // publications lues au plus par compte
const SOC_VUES_UNE_A_UNE = 20;     // relues une par une au plus, quand Meta refuse la lecture groupée

/** Les vues d'une Page jour par jour : ['metrique' => vues|visites, 'serie' => [jour => n], 'total', 'motif']. */
function socVuesPage(array $c, string $du, string $au): array
{
    $out = ['metrique' => 'vues', 'serie' => null, 'total' => null, 'motif' => null];
    $periode = ['period' => 'day', 'since' => $du, 'until' => date('Y-m-d', strtotime($au . ' +1 day'))];
    $refus = []; $permission = null;
    foreach (SOC_VUES_PAGE as [$metrique, $sens]) {
        try { $r = Meta::call('GET', $c['id'] . '/insights', ['metric' => $metrique] + $periode, $c['token']); }
        catch (MetaError $e) {
            if (socSuiviPermission($e)) { $permission ??= $e; }
            $refus[] = $metrique . ' : ' . $e->getMessage();
            continue;
        }
        $serie = [];
        foreach ((array) ($r['data'] ?? []) as $m) { if (($m['name'] ?? '') === $metrique) { $serie = socSuiviSerie($m); } }
        $serie = array_filter($serie, static fn ($j) => $j >= $du && $j <= $au, ARRAY_FILTER_USE_KEY);
        if (!$serie) { $refus[] = $metrique . ' : aucune valeur'; continue; }
        return ['metrique' => $sens, 'serie' => $serie, 'total' => array_sum($serie), 'motif' => null];
    }
    $out['motif'] = $permission !== null ? socSuiviMotif($permission, 'read_insights')
        : 'vues indisponibles : Meta renvoie des séries vides — ajoutez la permission read_insights au token (détail : ' . implode(' · ', $refus) . ')';
    return $out;
}

/**
 * Instagram : les vues de la période (total, par tranches de 30 jours que Meta impose) et
 * les comptes touchés jour par jour (30 derniers jours au plus).
 */
function socVuesInstagram(array $c, string $du, string $au): array
{
    $out = ['metrique' => 'personnes', 'serie' => null, 'total' => null, 'motif' => null];
    if ($c['token'] === null) { $out['motif'] = 'compte non accessible au token (Page non partagée avec l’utilisateur système)'; return $out; }
    $motifs = [];
    $total = null;
    for ($debut = $du; $debut <= $au; $debut = date('Y-m-d', strtotime($fin . ' +1 day'))) {
        $fin = min($au, date('Y-m-d', strtotime($debut . ' +29 days')));
        try {
            $r = Meta::call('GET', $c['id'] . '/insights', ['metric' => 'views', 'period' => 'day', 'metric_type' => 'total_value',
                'since' => strtotime($debut), 'until' => strtotime($fin . ' +1 day')], $c['token']);
            $v = $r['data'][0]['total_value']['value'] ?? null;
            if (is_numeric($v)) { $total = ($total ?? 0) + (int) $v; }
        } catch (MetaError $e) { $motifs[] = socSuiviMotif($e, 'instagram_manage_insights'); $total = null; break; }
    }
    $out['total'] = $total;
    $du30 = max($du, date('Y-m-d', strtotime('-30 days')));
    try {
        $r = Meta::call('GET', $c['id'] . '/insights', ['metric' => 'reach', 'period' => 'day', 'metric_type' => 'time_series',
            'since' => strtotime($du30), 'until' => strtotime($au . ' +1 day')], $c['token']);
        $s = array_filter(socSuiviSerie((array) ($r['data'][0] ?? [])), static fn ($j) => $j >= $du && $j <= $au, ARRAY_FILTER_USE_KEY);
        if ($s) { $out['serie'] = $s; }
        if ($du30 > $du) { $motifs[] = 'Instagram ne donne que les 30 derniers jours, jour par jour'; }
    } catch (MetaError $e) { $motifs[] = socSuiviMotif($e, 'instagram_manage_insights'); }
    $out['motif'] = $motifs ? implode(' · ', array_unique($motifs)) : null;
    return $out;
}

/** La valeur d'une métrique dans un `insights` lu avec la publication (lecture groupée). */
function socVuesValeur(array $x, array $noms): ?int
{
    foreach ((array) ($x['insights']['data'] ?? []) as $d) {
        if (in_array((string) ($d['name'] ?? ''), $noms, true)) { return socCalValeur($d['values'][0]['value'] ?? ($d['total_value']['value'] ?? null)); }
    }
    return null;
}

/**
 * Les publications d'un compte entre deux instants et leurs vues. D'abord la liste avec les
 * vues en un appel ; Meta refuse (métrique, permission) : la liste seule, puis les vues
 * publication par publication (les plus récentes), jusqu'au premier refus de permission.
 * Rend ['publications' => […], 'motif' => ?string].
 */
function socVuesPublications(array $c, int $t0, int $t1): array
{
    $fb = $c['type'] === 'facebook';
    $tok = $c['token'];
    if ($tok === null) { return ['publications' => [], 'motif' => 'compte non accessible au token']; }
    $q = ['since' => $t0, 'until' => $t1, 'limit' => 100];
    $essais = $fb ? [['post_media_view', 'post_total_media_view_unique'], ['post_impressions', 'post_impressions_unique']] : [['views', 'reach'], ['impressions', 'reach']];
    $liste = null; $groupe = false;
    foreach ($essais as $noms) {
        try {
            $liste = $fb ? socGraphTout($c['id'] . '/published_posts', ['fields' => SOC_CAL_FB_MIN . ',insights.metric(' . implode(',', $noms) . ')'] + $q, SOC_VUES_PUBLICATIONS, $tok)
                : socGraphTout($c['id'] . '/media', ['fields' => SOC_CAL_IG . ',insights.metric(' . implode(',', $noms) . ')'] + $q, SOC_VUES_PUBLICATIONS, $tok);
            $groupe = true;
            break;
        } catch (MetaError $e) { if (!in_array($e->metaCode, [10, 100, 200], true)) { throw $e; } }
    }
    $liste ??= $fb ? socCalLireFacebook($c['id'], $tok, $t0, $t1) : socGraphTout($c['id'] . '/media', ['fields' => SOC_CAL_IG] + $q, SOC_VUES_PUBLICATIONS, $tok);
    $compte = ['key' => $c['key'], 'name' => $c['name'], 'username' => (string) ($c['username'] ?? ''), 'shop' => $c['shop']];
    $out = []; $motif = null; $relues = 0;
    foreach ($liste as $x) {
        $t = strtotime((string) ($x[$fb ? 'created_time' : 'timestamp'] ?? '')) ?: 0;
        if (empty($x['id']) || $t < $t0 || $t > $t1) { continue; }
        $p = $fb ? socCalPostFacebook($x, $compte, 'Publié') : socCalPostInstagram($x, $compte);
        $vues = $groupe ? socVuesValeur($x, [$essais[0][0], $essais[1][0]]) : null;
        $personnes = $groupe ? socVuesValeur($x, [$essais[0][1], $essais[1][1]]) : null;
        if (!$groupe && $motif === null && $relues < SOC_VUES_UNE_A_UNE) {
            $relues++;
            try {
                $r = Meta::call('GET', $x['id'] . '/insights', ['metric' => $essais[0][0]] + ($fb ? ['period' => 'lifetime'] : []), $tok);
                $vues = socVuesValeur(['insights' => $r], [$essais[0][0]]);
            } catch (MetaError $e) { if (socSuiviPermission($e)) { $motif = socSuiviMotif($e, $fb ? 'read_insights' : 'instagram_manage_insights'); } }
        }
        $out[] = ['id' => $p['id'], 'account' => $c['key'], 'channel' => $p['channel'], 'date' => $p['date'], 'time' => $p['time'], 'type' => $p['type'],
            'title' => $p['title'], 'img' => $p['img'], 'permalink' => $p['permalink'], 'vues' => $vues, 'personnes' => $personnes];
    }
    if ($motif === null && $out && !array_filter(array_column($out, 'vues'), static fn ($v) => $v !== null)) {
        $motif = 'vues des publications indisponibles : la permission ' . ($fb ? 'read_insights' : 'instagram_manage_insights') . ' manque au token';
    }
    return ['publications' => $out, 'motif' => $motif];
}

/**
 * Les abonnés d'un compte Instagram jour par jour, sur les 30 derniers jours au plus (limite de Meta) :
 * nouveaux abonnés et désabonnements (`follows_and_unfollows`, un jour par requête, envoyées en un seul
 * lot Graph `batch`) ; à défaut, les nouveaux abonnés seuls (`follower_count`). Et les comptes touchés
 * jour par jour (`reach`). `$c` : ['id' => compte Instagram, 'token' => token de sa Page].
 * Rend ['followers', 'username', 'picture', 'du', 'au', 'jours', 'serie' => [jour => ['gagnes', 'perdus']],
 * 'gagnes', 'perdus' (null : non donnés par Meta), 'reach' => [jour => n] | null, 'motif', 'le'].
 */
function socIgAbonnes(array $c, int $jours = 30): array
{
    $jours = max(7, min(30, $jours));
    $au = date('Y-m-d', strtotime('-1 day'));  // les insights d'un jour sont complets le lendemain
    $du = date('Y-m-d', strtotime($au . ' -' . ($jours - 1) . ' days'));
    $b = socSuiviInstagram($c, $du, $au);       // total actuel et nouveaux abonnés par jour (follower_count)
    $out = ['followers' => $b['followers'], 'username' => $b['username'], 'picture' => $b['picture'], 'du' => $du, 'au' => $au, 'jours' => $jours,
        'serie' => $b['serie'], 'gagnes' => $b['gagnes'], 'perdus' => null, 'reach' => null, 'motif' => $b['motif'], 'le' => date('Y-m-d H:i')];
    if ($b['followers'] === null && $b['motif'] !== null) { return $out; }  // compte illisible
    // Désabonnements : Meta ne les donne qu'en total sur une période ; un jour par requête, toutes dans un lot.
    $jourDe = []; $lot = [];
    for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $jourDe[] = $d;
        $lot[] = ['method' => 'GET', 'relative_url' => $c['id'] . '/insights?' . http_build_query(['metric' => 'follows_and_unfollows', 'period' => 'day',
            'metric_type' => 'total_value', 'breakdown' => 'follow_type', 'since' => strtotime($d), 'until' => strtotime($d . ' +1 day')])];
    }
    $flux = []; $refus = null;
    try {
        $rep = Meta::call('POST', '', ['batch' => $lot, 'include_headers' => false], $c['token']);
        foreach ($jourDe as $j => $d) {
            $x = $rep[$j] ?? null;
            $corps = is_array($x) ? json_decode((string) ($x['body'] ?? ''), true) : null;
            if (!is_array($x) || (int) ($x['code'] ?? 0) !== 200 || !is_array($corps) || isset($corps['error'])) {
                $refus ??= (string) ($corps['error']['message'] ?? 'réponse illisible');
                continue;
            }
            $g = 0; $p = 0; $lu = false;
            foreach ((array) ($corps['data'] ?? []) as $m) {
                if (($m['name'] ?? '') !== 'follows_and_unfollows') { continue; }
                $lu = true;
                foreach ((array) ($m['total_value']['breakdowns'][0]['results'] ?? []) as $res) {
                    $type = strtoupper((string) ($res['dimension_values'][0] ?? '')); $v = (int) ($res['value'] ?? 0);
                    if ($type === 'FOLLOWER') { $g += $v; } elseif ($type === 'NON_FOLLOWER') { $p += $v; }
                }
            }
            if ($lu) { $flux[$d] = ['gagnes' => $g, 'perdus' => $p]; }
        }
    } catch (MetaError $e) { $refus = $e->getMessage(); }
    if (count($flux) * 2 >= count($jourDe)) {
        $out['serie'] = $flux;
        $out['gagnes'] = array_sum(array_column($flux, 'gagnes')); $out['perdus'] = array_sum(array_column($flux, 'perdus'));
    } else {
        $out['motif'] = trim(($out['motif'] ? $out['motif'] . ' · ' : '') . 'désabonnements non donnés par Meta' . ($refus !== null ? ' (' . $refus . ')' : ''));
    }
    try {
        $r = Meta::call('GET', $c['id'] . '/insights', ['metric' => 'reach', 'period' => 'day', 'metric_type' => 'time_series',
            'since' => strtotime($du), 'until' => strtotime($au . ' +1 day')], $c['token']);
        $s = array_filter(socSuiviSerie((array) ($r['data'][0] ?? [])), static fn ($j) => $j >= $du && $j <= $au, ARRAY_FILTER_USE_KEY);
        if ($s) { $out['reach'] = $s; }
    } catch (MetaError $e) { /* sans les comptes touchés */ }
    return $out;
}
