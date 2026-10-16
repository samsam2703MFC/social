<?php
declare(strict_types=1);

/**
 * Le bilan de la semaine, boutique par boutique : l'écran « Bilan des boutiques » de la marque, et « Ma semaine » de
 * l'espace franchisé (sa ligne, la moyenne du réseau et son rang).
 *
 *  - régularité : posts publiés sur la période (ceux du module : réseau et boutique) et quota de la boutique cette
 *    semaine ;
 *  - réactivité : commentaires et avis Google à traiter, en retard ; part traitée dans le délai et délai médian
 *    (30 jours) ;
 *  - avis Google : note, avis gagnés sur la période, avis 5★ requis pour la cible ;
 *  - Meta : abonnés nets et dépense publicitaire sur la période (lus chez Meta, src/suivi.php).
 *
 * Le cron le calcule toutes les heures (`bilan_cache`) : l'écran s'affiche sans attendre Meta ; « Recalculer » le refait.
 * Le classement range les boutiques sur trois critères — régularité (posts publiés rapportés au quota), réactivité
 * (part traitée dans le délai), avis gagnés — et le rang général est la somme des trois rangs.
 */

const SOC_BILAN_JOURS = 7;
const SOC_BILAN_CACHE_S = 7200;

function socBilanCalculer(int $jours = SOC_BILAN_JOURS): array
{
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

    // Réactivité : ce qui attend, ce qui est en retard, ce qui a été traité dans le délai (30 jours).
    $lim = date('Y-m-d H:i:s', strtotime('-' . $delai . ' hours')); $d30 = date('Y-m-d H:i:s', strtotime('-30 days'));
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
    $meta = []; $devise = 'EUR'; $erreurs = [];
    if (Meta::configured()) {
        try {
            $s = socSuiviAbonnes($jours);
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

    $lignes = [];
    foreach ($shops as $s) {
        $id = (string) $s['id']; $c = $com[$id] ?? ['rouges' => 0, 'oranges' => 0, 'enRetard' => 0, 'traites30' => 0, 'dansDelai30' => 0];
        $g = $gg[$id] ?? null; $m = $meta[$id] ?? null;
        $quota = (int) $regles['quotaSemaine'];
        $lignes[] = ['id' => $id, 'nom' => (string) $s['name'],
            'posts' => $posts[$id]['reseau'] + $posts[$id]['boutique'], 'postsReseau' => $posts[$id]['reseau'], 'postsBoutique' => $posts[$id]['boutique'],
            'quotaUtilise' => sfQuotaUtilise($id, $au), 'quota' => $quota,
            'aTraiter' => $c['rouges'] + $c['oranges'], 'rouges' => $c['rouges'], 'oranges' => $c['oranges'], 'enRetard' => $c['enRetard'],
            'dansDelaiPct' => $c['traites30'] > 0 ? (int) round(100 * $c['dansDelai30'] / $c['traites30']) : null, 'traites30' => $c['traites30'],
            'delaiMedianH' => socComDelaiMedian('c.shop_id = ?', [$id, $d30]),
            'note' => $g['note'] ?? null, 'avisGagnes' => $g['gagnes7'] ?? null, 'avis5Requis' => $g['avis5Requis'] ?? null,
            'abonnesNet' => $m['abonnesNet'] ?? null, 'abonnes' => $m['abonnes'] ?? null, 'depense' => $m['depense'] ?? null];
    }

    // Classement : trois critères, rang 1 = le meilleur ; une valeur inconnue prend le dernier rang.
    $criteres = [
        'regularite' => static fn ($l) => $l['quota'] > 0 ? min(1.5, $l['quotaUtilise'] / $l['quota']) + $l['posts'] / 100 : (float) $l['posts'],
        'reactivite' => static fn ($l) => $l['dansDelaiPct'] === null ? ($l['enRetard'] === 0 ? null : -1.0) : $l['dansDelaiPct'] - 5 * $l['enRetard'],
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
    return ['du' => $du, 'au' => $au, 'jours' => $jours, 'le' => date('Y-m-d H:i'), 'devise' => $devise, 'cible' => $cible, 'delaiReponseHeures' => $delai,
        'reseau' => ['posts' => (int) $tot('posts'), 'aTraiter' => (int) $tot('aTraiter'), 'enRetard' => (int) $tot('enRetard'), 'avisGagnes' => (int) $tot('avisGagnes'),
            'abonnesNet' => (int) $tot('abonnesNet'), 'depense' => round($tot('depense'), 2), 'boutiques' => $n],
        'lignes' => $lignes, 'erreurs' => $erreurs];
}

/** Le bilan gardé (calculé par le cron) ; recalculé s'il a plus de deux heures, ou sur demande. */
function socBilan(bool $forcer = false): array
{
    $c = socSetting('bilan_cache');
    if (!$forcer && is_array($c) && isset($c['t'], $c['data']) && time() - (int) $c['t'] < SOC_BILAN_CACHE_S) { return $c['data']; }
    $data = socBilanCalculer();
    socSettingPut('bilan_cache', ['t' => time(), 'data' => $data], false);
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
