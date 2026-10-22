<?php
declare(strict_types=1);

/**
 * Le bilan des boutiques, boutique par boutique : l'écran « Bilan des boutiques » de la marque — sur la semaine, le
 * mois, le trimestre ou l'année —, et « Ma semaine » de l'espace franchisé (sa ligne, la moyenne du réseau et son rang).
 *
 *  - régularité : publications de la Page de la boutique (et de son propre Instagram) sur la période, lues chez Meta —
 *    celles du module comme celles de Meta Business Suite —, face au rythme attendu (quota de la semaine × semaines) ;
 *    sans Page lisible, les posts du module ;
 *  - réactivité : commentaires et avis Google à traiter, en retard ; part traitée dans le délai et délai médian
 *    (30 jours) ;
 *  - avis Google : note, avis gagnés sur la période, avis 5★ requis pour la cible ;
 *  - Meta : abonnés nets et dépense publicitaire sur la période (lus chez Meta, src/suivi.php).
 *
 * Le cron le calcule toutes les heures (`bilan_cache`) : l'écran s'affiche sans attendre Meta ; « Recalculer » le refait.
 * Le classement range les boutiques sur trois critères — régularité (posts publiés rapportés au quota), réactivité
 * (part traitée dans le délai), avis gagnés — et le rang général est la somme des trois rangs.
 */

/**
 * Les périodes du bilan, glissantes jusqu'à aujourd'hui : la semaine (celle de « Ma semaine »), le mois, le trimestre,
 * l'année. Chacune est gardée à part, d'autant plus longtemps qu'elle est longue : un an de Meta se relit en plusieurs
 * appels par Page. Sur une période plus longue que la semaine, le quota est celui de la semaine × le nombre de semaines,
 * et la réactivité se lit sur la période (30 jours au moins).
 */
const SOC_BILAN_PERIODES = ['semaine' => ['jours' => 7, 'garde' => 7200], 'mois' => ['jours' => 30, 'garde' => 7200],
    'trimestre' => ['jours' => 90, 'garde' => 21600], 'annee' => ['jours' => 365, 'garde' => 43200]];
/** La version des règles du bilan : un bilan gardé sous d'autres règles est recalculé (2 : régularité lue chez Meta, réactivité corrigée). */
const SOC_BILAN_VERSION = 2;

/**
 * Les publications de chaque boutique sur la période, lues chez Meta : sa Page Facebook et son propre Instagram (pas
 * celui du réseau). Ce qui part par Meta Business Suite compte comme ce qui part par le module.
 * Rend ['shops' => [boutique => n], 'lues' => [boutique => true], 'erreurs' => […]].
 */
function socBilanPublications(string $du, string $au): array
{
    $out = ['shops' => [], 'lues' => [], 'erreurs' => []];
    if (!Meta::configured()) { return $out; }
    $t0 = strtotime($du . ' 00:00:00'); $t1 = strtotime($au . ' 23:59:59');
    try { $comptes = socCalComptes(); } catch (MetaError $e) { $out['erreurs'][] = 'Pages du System User : ' . $e->getMessage(); return $out; }
    foreach ($comptes as $c) {
        $sid = (string) ($c['shop'] ?? '');
        if ($sid === '' || $sid === 'marque' || ($c['token'] ?? null) === null) { continue; }
        try {
            $liste = $c['type'] === 'facebook' ? socCalLireFacebook($c['id'], $c['token'], $t0, $t1)
                : socGraphTout($c['id'] . '/media', ['fields' => 'id,timestamp', 'since' => $t0, 'until' => $t1, 'limit' => 100], SOC_CAL_MAX, $c['token']);
            $n = 0;
            foreach ($liste as $x) {
                $t = strtotime((string) ($x['created_time'] ?? $x['timestamp'] ?? '')) ?: 0;
                if ($t >= $t0 && $t <= $t1) { $n++; }
            }
            $out['shops'][$sid] = ($out['shops'][$sid] ?? 0) + $n;
            $out['lues'][$sid] = true;
        } catch (MetaError $e) { $out['erreurs'][] = ($c['name'] ?: $c['key']) . ' : ' . $e->getMessage(); }
    }
    return $out;
}

function socBilanCalculer(string $periode = 'semaine'): array
{
    $jours = SOC_BILAN_PERIODES[$periode]['jours'] ?? throw new HttpError(400, 'période inconnue : semaine, mois, trimestre ou annee');
    $semaine = $periode === 'semaine'; $semaines = max(1, (int) round($jours / 7));
    $du = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days')); $au = date('Y-m-d');
    $regles = sfRegles(); $delai = (int) $regles['delaiReponseHeures'];
    $shops = array_values(array_filter(socReseau(), static fn ($s) => empty($s['horsCaisse'])));
    $ids = array_map(static fn ($s) => (string) $s['id'], $shops);

    // Régularité : les posts du module publiés sur la période, par boutique (réseau et boutique séparés).
    $posts = array_fill_keys($ids, ['reseau' => 0, 'boutique' => 0]);
    foreach (socItems('posts') as $p) {
        if (($p['status'] ?? '') !== 'Publié') { continue; }
        $j = (string) ($p['date'] ?? '');
        if ($j < $du || $j > $au) { continue; }
        foreach (array_map('strval', (array) ($p['shops'] ?? [])) as $sid) {
            if (isset($posts[$sid])) { $posts[$sid][($p['origin'] ?? '') === 'franchise' ? 'boutique' : 'reseau']++; }
        }
    }

    // Réactivité : ce qui attend, ce qui est en retard, ce qui a été traité dans le délai (sur la période, 30 jours au moins).
    $fenetre = max(30, $jours);
    $lim = date('Y-m-d H:i:s', strtotime('-' . $delai . ' hours')); $d30 = date('Y-m-d H:i:s', strtotime('-' . $fenetre . ' days'));
    $vu = SOC_COM_VU;
    $com = [];
    foreach (Db::rows("SELECT c.shop_id,
            SUM(c.traite_le IS NULL AND c.couleur = 'rouge') rouges, SUM(c.traite_le IS NULL AND c.couleur = 'orange') oranges,
            SUM(c.traite_le IS NULL AND c.couleur IN ('rouge','orange') AND $vu < ?) retard,
            SUM(c.traite_le IS NOT NULL AND c.couleur IN ('rouge','orange') AND c.traite_le >= ?) traites,
            SUM(c.traite_le IS NOT NULL AND c.couleur IN ('rouge','orange') AND c.traite_le >= ? AND TIMESTAMPDIFF(MINUTE, $vu, c.traite_le) <= ?) dansDelai
        FROM social_commentaire c WHERE c.shop_id IS NOT NULL GROUP BY c.shop_id", [$lim, $d30, $d30, $delai * 60]) as $r) {
        $com[(string) $r['shop_id']] = ['rouges' => (int) $r['rouges'], 'oranges' => (int) $r['oranges'], 'enRetard' => (int) $r['retard'],
            'traites30' => (int) $r['traites'], 'dansDelai30' => (int) $r['dansDelai']];
    }

    // Avis Google (la Réputation digitale du BO) et Meta (abonnés, dépense).
    $gg = [];
    try {
        $rep = socGoogleReputation();
        foreach ($rep['magasins'] ?? [] as $m) { $gg[(string) $m['id']] = $m; }
        $cible = $rep['cible'] ?? null;
    } catch (Throwable $e) { $cible = null; }
    $gagnes = socGoogleGagnes($jours);
    $meta = []; $devise = 'EUR'; $erreurs = [];
    if (Meta::configured()) {
        try {
            $s = socSuiviAbonnes($jours, null, false);
            $devise = (string) ($s['devise'] ?? 'EUR');
            foreach ($s['lignes'] ?? [] as $l) {
                $sid = (string) ($l['shop'] ?? '');
                if ($sid === '' || $sid === 'marque') { continue; }
                $meta[$sid] = ['abonnesNet' => isset($meta[$sid]['abonnesNet']) || $l['abonnesNet'] !== null ? (int) ($meta[$sid]['abonnesNet'] ?? 0) + (int) $l['abonnesNet'] : null,
                    'depense' => round((float) ($meta[$sid]['depense'] ?? 0) + (float) $l['depense'], 2), 'abonnes' => $l['facebook']['followers'] ?? ($meta[$sid]['abonnes'] ?? null)];
            }
            $erreurs = array_values((array) ($s['erreurs'] ?? []));
        } catch (Throwable $e) { $erreurs[] = 'Meta : ' . $e->getMessage(); }
    }
    $publications = socBilanPublications($du, $au);
    $erreurs = array_merge($erreurs, $publications['erreurs']);

    $lignes = [];
    foreach ($shops as $s) {
        $id = (string) $s['id']; $c = $com[$id] ?? ['rouges' => 0, 'oranges' => 0, 'enRetard' => 0, 'traites30' => 0, 'dansDelai30' => 0];
        $g = $gg[$id] ?? null; $m = $meta[$id] ?? null;
        // Le quota : celui de la semaine en cours ; sur une période plus longue, les posts publiés par la boutique face au quota × semaines.
        $quota = (int) $regles['quotaSemaine'] * ($semaine ? 1 : $semaines);
        // Les publications lues chez Meta (Page et Instagram de la boutique) ; sans Page lisible, celles du module.
        $lue = isset($publications['lues'][$id]);
        $lignes[] = ['id' => $id, 'nom' => (string) $s['name'],
            'posts' => $lue ? (int) $publications['shops'][$id] : $posts[$id]['reseau'] + $posts[$id]['boutique'], 'postsSource' => $lue ? 'meta' : 'module',
            'postsReseau' => $posts[$id]['reseau'], 'postsBoutique' => $posts[$id]['boutique'],
            'quotaUtilise' => $semaine ? sfQuotaUtilise($id, $au) : $posts[$id]['boutique'], 'quota' => $quota,
            'aTraiter' => $c['rouges'] + $c['oranges'], 'rouges' => $c['rouges'], 'oranges' => $c['oranges'], 'enRetard' => $c['enRetard'],
            'dansDelaiPct' => $c['traites30'] > 0 ? (int) round(100 * $c['dansDelai30'] / $c['traites30']) : null, 'traites30' => $c['traites30'],
            'delaiMedianH' => socComDelaiMedian('c.shop_id = ?', [$id, $d30]),
            'note' => $g['note'] ?? null, 'avisGagnes' => $gagnes['shops'][$id] ?? null, 'avis5Requis' => $g['avis5Requis'] ?? null,
            'abonnesNet' => $m['abonnesNet'] ?? null, 'abonnes' => $m['abonnes'] ?? null, 'depense' => $m['depense'] ?? null];
    }

    // Classement : trois critères, rang 1 = le meilleur ; une valeur inconnue prend le dernier rang.
    //  - régularité : les publications face au rythme attendu (plafonné à 1,5), puis leur nombre ;
    //  - réactivité : la part traitée dans le délai, moins 5 points par retard. Sans rien de traité, rien en retard vaut
    //    100 et chaque retard part de 0 : une boutique qui laisse tout en retard ne passe plus devant celle qui n'a rien
    //    à traiter.
    $criteres = [
        'regularite' => static fn ($l) => $l['quota'] > 0 ? min(1.5, $l['posts'] / $l['quota']) + $l['posts'] / 1000 : (float) $l['posts'],
        'reactivite' => static fn ($l) => ($l['dansDelaiPct'] ?? ($l['enRetard'] > 0 ? 0 : 100)) - 5 * $l['enRetard'],
        'avis' => static fn ($l) => $l['avisGagnes'],
    ];
    $n = count($lignes);
    foreach ($criteres as $k => $f) {
        $vals = [];
        foreach ($lignes as $i => $l) { $v = $f($l); if ($v !== null) { $vals[$i] = (float) $v; } }
        arsort($vals);
        $rang = 0; $prec = null; $pos = 0;
        foreach ($vals as $i => $v) { $pos++; if ($prec === null || $v < $prec) { $rang = $pos; } $prec = $v; $lignes[$i]['rangs'][$k] = $rang; }
        foreach ($lignes as $i => $l) { $lignes[$i]['rangs'][$k] ??= null; }
    }
    foreach ($lignes as $i => $l) { $lignes[$i]['score'] = array_sum(array_map(static fn ($r) => $r ?? $n, $l['rangs'])); }
    $scores = array_column($lignes, 'score');
    foreach ($lignes as $i => $l) { $lignes[$i]['rang'] = 1 + count(array_filter($scores, static fn ($x) => $x < $l['score'])); }
    usort($lignes, static fn ($a, $b) => ($a['rang'] <=> $b['rang']) ?: strcmp($a['nom'], $b['nom']));

    $tot = static fn (string $k) => array_sum(array_map(static fn ($l) => (float) ($l[$k] ?? 0), $lignes));
    // avisDepuis : le suivi des avis a commencé après le début de la période ; instagramPartiel : Meta ne donne que 30 jours d'Instagram.
    return ['periode' => $periode, 'du' => $du, 'au' => $au, 'jours' => $jours, 'semaines' => $semaines, 'reactJours' => $fenetre, 'avisDepuis' => $gagnes['depuis'],
        'instagramPartiel' => $jours > 30, 'le' => date('Y-m-d H:i'), 'devise' => $devise, 'cible' => $cible, 'delaiReponseHeures' => $delai,
        'reseau' => ['posts' => (int) $tot('posts'), 'aTraiter' => (int) $tot('aTraiter'), 'enRetard' => (int) $tot('enRetard'), 'avisGagnes' => (int) $tot('avisGagnes'),
            'abonnesNet' => (int) $tot('abonnesNet'), 'depense' => round($tot('depense'), 2), 'boutiques' => $n],
        'lignes' => $lignes, 'erreurs' => $erreurs];
}

/** Le bilan d'une période, gardé (calculé par le cron) ; recalculé quand sa garde est passée, ou sur demande. */
function socBilan(bool $forcer = false, string $periode = 'semaine'): array
{
    $p = SOC_BILAN_PERIODES[$periode] ?? throw new HttpError(400, 'période inconnue : semaine, mois, trimestre ou annee');
    $cle = $periode === 'semaine' ? 'bilan_cache' : 'bilan_cache_' . $periode;
    $c = socSetting($cle);
    if (!$forcer && is_array($c) && isset($c['t'], $c['data']) && ($c['v'] ?? 1) === SOC_BILAN_VERSION && time() - (int) $c['t'] < $p['garde']) { return $c['data']; }
    if ($periode !== 'semaine') { @set_time_limit(300); }  // un an de Meta : plusieurs appels par Page
    $data = socBilanCalculer($periode);
    socSettingPut($cle, ['t' => time(), 'v' => SOC_BILAN_VERSION, 'data' => $data], false);
    return $data;
}

/**
 * « Ma semaine » d'une boutique : sa ligne du bilan, la moyenne des autres boutiques pour se situer, et son rang
 * (règle « voirClassement » : le réseau peut le masquer). Jamais les chiffres d'une autre boutique, un par un.
 */
function sfMaSemaine(string $shop): array
{
    $b = socBilan();
    $moi = null; $autres = [];
    foreach ($b['lignes'] as $l) { if ($l['id'] === $shop) { $moi = $l; } else { $autres[] = $l; } }
    if ($moi === null) { return ['ligne' => null, 'du' => $b['du'], 'au' => $b['au']]; }
    $moy = static function (string $k) use ($autres): ?float {
        $v = array_values(array_filter(array_map(static fn ($l) => $l[$k], $autres), static fn ($x) => $x !== null));
        return $v ? round(array_sum($v) / count($v), 1) : null;
    };
    $classement = (bool) sfRegles()['voirClassement'];
    unset($moi['score']);
    if (!$classement) { unset($moi['rang'], $moi['rangs']); }
    return ['du' => $b['du'], 'au' => $b['au'], 'le' => $b['le'], 'jours' => $b['jours'], 'devise' => $b['devise'], 'cible' => $b['cible'], 'delaiReponseHeures' => $b['delaiReponseHeures'],
        'ligne' => $moi, 'boutiques' => count($b['lignes']), 'classement' => $classement,
        'moyenne' => ['posts' => $moy('posts'), 'dansDelaiPct' => $moy('dansDelaiPct'), 'avisGagnes' => $moy('avisGagnes'), 'abonnesNet' => $moy('abonnesNet'), 'note' => $moy('note')]];
}
