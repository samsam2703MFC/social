<?php
declare(strict_types=1);

/**
 * Le plan de posts d'une campagne : sur sa durée, pour les réseaux choisis, une liste de posts aux meilleurs jours et
 * heures (ce qui marche, src/recommandations.php : les publications des 90 derniers jours rapportées à l'habitude
 * de leur compte), avec un mix de sujets et de formats préétabli. Le plan se relit d'abord (rien n'est écrit), puis
 * s'ajoute au calendrier en BROUILLONS rattachés à la campagne : ils ne partent pas tant que personne ne les a
 * complétés (visuel, texte) et programmés.
 *
 *  - Les jours : chaque semaine, les meilleurs jours du classement (indice du jour), espacés d'au moins un jour sur
 *    un même réseau, et sans deux posts le même jour sur un même fil (Facebook + Instagram et Instagram seul se
 *    partagent le fil Instagram) tant que la semaine le permet ; une semaine incomplète (début ou fin de période)
 *    reçoit sa part, au prorata.
 *  - L'heure : celle du meilleur créneau (médiane des publications au-dessus de l'habitude, arrondie au quart
 *    d'heure) ; sans chiffres assez fournis, 09:00.
 *  - Les sujets : au prorata des poids du mix (plus grand reste), entrelacés dans l'ordre des dates ; si le mix les
 *    prévoit, les annonces ouvrent le plan (dans sa première semaine) et les rappels le closent (dans la dernière).
 *  - Le format : celui du sujet, réseau par réseau (Facebook + Instagram : celui d'Instagram, que Facebook reprend) ;
 *    story = story seule.
 */

// `fbig` : le même post sur Facebook et Instagram (le cas courant) ; `story` : une story sur Facebook et Instagram.
const SOC_PLAN_RESEAUX = ['fbig' => 'Facebook + Instagram', 'facebook' => 'Facebook seul', 'instagram' => 'Instagram seul', 'story' => 'Stories'];
const SOC_PLAN_FILS = ['fbig' => ['fb', 'ig'], 'facebook' => ['fb'], 'instagram' => ['ig'], 'story' => ['story']];
const SOC_PLAN_MAX = 120;   // posts au plus dans un plan

/** Les sujets : libellé, type de post du module, format par réseau. */
function socPlanSujets(): array
{
    return [
        'annonce' => ['Annonce de la campagne', 'Événement local', ['facebook' => 'Vidéo', 'instagram' => 'Reel']],
        'produit' => ['Produit vedette', 'Produit', ['facebook' => 'Photo', 'instagram' => 'Carrousel']],
        'offre' => ['Offre / promotion', 'Produit', ['facebook' => 'Photo', 'instagram' => 'Photo']],
        'coulisses' => ['Coulisses & savoir-faire', 'Vidéo / film', ['facebook' => 'Vidéo', 'instagram' => 'Reel']],
        'avis' => ['Avis client / communauté', 'Libre', ['facebook' => 'Photo', 'instagram' => 'Carrousel']],
        'gamme' => ['Gamme / nouveauté', 'Gamme saisonnière', ['facebook' => 'Album', 'instagram' => 'Carrousel']],
        'rappel' => ['Rappel / derniers jours', 'Produit', ['facebook' => 'Photo', 'instagram' => 'Photo']],
    ];
}

/** Les mix préétablis : [libellé, [sujet => poids]]. */
function socPlanMix(): array
{
    return [
        'equilibre' => ['Équilibré', ['produit' => 40, 'coulisses' => 20, 'offre' => 20, 'avis' => 20]],
        'lancement' => ['Lancement / nouveauté', ['annonce' => 15, 'produit' => 30, 'gamme' => 15, 'coulisses' => 20, 'avis' => 20]],
        'promo' => ['Promotion', ['offre' => 50, 'produit' => 25, 'avis' => 10, 'rappel' => 15]],
        'notoriete' => ['Notoriété / B2B', ['coulisses' => 30, 'avis' => 25, 'produit' => 25, 'annonce' => 20]],
    ];
}

/** Ce que l'écran propose : réseaux, mix (avec leur composition), sujets. */
function socPlanOptions(): array
{
    $sujets = socPlanSujets();
    return ['reseaux' => SOC_PLAN_RESEAUX, 'sujets' => array_map(static fn ($s) => $s[0], $sujets),
        'mix' => array_map(static fn ($m) => ['libelle' => $m[0], 'composition' => array_map(static fn ($k, $p) => ['sujet' => $sujets[$k][0], 'part' => $p], array_keys($m[1]), $m[1])], socPlanMix())];
}

/**
 * Les meilleurs jours et l'heure, d'après ce qui marche ; sans chiffres, un ordre neutre et 09:00.
 * Rend ['jours' => [1..7 → indice], 'heure' => 'HH:MM', 'source' => texte].
 */
function socPlanCreneaux(?array $reco): array
{
    $jours = array_fill(1, 7, 1.0); $source = 'sans statistiques assez fournies : jours répartis, 09:00';
    $dims = is_array($reco) ? (array) ($reco['dimensions'] ?? []) : [];
    foreach ($dims as $d) {
        if (($d['cle'] ?? '') !== 'jour') { continue; }
        $fiables = array_filter((array) $d['valeurs'], static fn ($v) => !empty($v['fiable']));
        if (count($fiables) < 2) { break; }
        foreach ($fiables as $v) { $n = array_search($v['valeur'], SOC_JOURS_FR, true); if ($n !== false) { $jours[(int) $n] = (float) $v['indice']; } }
        $source = 'meilleurs jours et heures de vos publications des ' . (int) ($reco['jours'] ?? SOC_RECO_JOURS) . ' derniers jours';
    }
    $heure = is_array($reco['conseil'] ?? null) && preg_match('/^\d{2}:\d{2}$/', (string) $reco['conseil']['heure']) ? (string) $reco['conseil']['heure'] : '09:00';
    $creneau = is_array($reco['conseil'] ?? null) ? (string) ($reco['conseil']['creneau'] ?? '') : '';
    return ['jours' => $jours, 'heure' => $heure, 'creneau' => $creneau, 'source' => $source];
}

/**
 * Le plan proposé (pur : sans écrire). `$in` : du, au (AAAA-MM-JJ), rythme [réseau => posts par semaine], mix,
 * demain (la recette fixe la date ; sinon celle de demain).
 * `$reco` : ce qui marche (null : sans statistiques). Rend ['posts' => [...], 'resume' => ..., 'creneaux' => ...].
 */
function socPlanProposer(array $in, ?array $reco): array
{
    $du = (string) ($in['du'] ?? ''); $au = (string) ($in['au'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || $au < $du) { throw new HttpError(422, 'Période invalide : choisissez un début et une fin.'); }
    // Rien dans le passé : une campagne en cours se planifie à partir de demain.
    $demain = (string) ($in['demain'] ?? date('Y-m-d', strtotime('tomorrow')));
    if ($du < $demain) { $du = $demain; }
    if ($au < $du) { throw new HttpError(422, 'Cette période est déjà passée : choisissez une fin à partir de demain.'); }
    if ((strtotime($au) - strtotime($du)) / 86400 > 366) { throw new HttpError(422, 'Un plan couvre au plus un an.'); }
    $mixes = socPlanMix(); $mixCle = (string) ($in['mix'] ?? 'equilibre');
    if (!isset($mixes[$mixCle])) { throw new HttpError(422, 'Mix inconnu.'); }
    $rythme = [];
    foreach (SOC_PLAN_RESEAUX as $r => $l) { $n = (int) (($in['rythme'] ?? [])[$r] ?? 0); if ($n > 0) { $rythme[$r] = min(7, $n); } }
    if (!$rythme) { throw new HttpError(422, 'Choisissez au moins un réseau.'); }
    $cr = socPlanCreneaux($reco);
    $creneaux = [];   // [date, heure, réseau, raison]
    for ($debut = strtotime($du); $debut <= strtotime($au); $debut = strtotime('+7 days', $debut)) {
        $fin = min(strtotime('+6 days', $debut), strtotime($au));
        $jours = [];
        for ($t = $debut; $t <= $fin; $t = strtotime('+1 day', $t)) { $jours[] = $t; }
        $occupe = [];   // [fil => [jour => true]] : un fil (Facebook, Instagram, stories) sans deux posts le même jour si possible
        foreach ($rythme as $r => $parSemaine) {
            $fils = SOC_PLAN_FILS[$r];
            // Semaine incomplète : sa part au prorata (au moins un post dès 3 jours).
            $n = count($jours) >= 7 ? $parSemaine : (int) round($parSemaine * count($jours) / 7);
            if ($n === 0 && count($jours) >= 3) { $n = 1; }
            $classes = $jours;
            usort($classes, static fn ($a, $b) => [$cr['jours'][(int) date('N', $b)], $a] <=> [$cr['jours'][(int) date('N', $a)], $b]);
            // D'abord des jours espacés (pas deux jours de suite) et libres sur ses fils, puis libres, puis n'importe lesquels.
            $pris = [];
            foreach ([2, 1, 0] as $exigence) {
                foreach ($classes as $t) {
                    if (count($pris) >= $n) { break 2; }
                    if (in_array($t, $pris, true)) { continue; }
                    if ($exigence >= 1) { foreach ($fils as $f) { if (isset($occupe[$f][$t])) { continue 2; } } }
                    if ($exigence === 2) { foreach ($pris as $p) { if (abs($p - $t) < 1.5 * 86400) { continue 2; } } }
                    $pris[] = $t;
                }
            }
            foreach ($pris as $t) {
                foreach ($fils as $f) { $occupe[$f][$t] = true; }
                $j = SOC_JOURS_FR[(int) date('N', $t)];
                $creneaux[] = ['date' => date('Y-m-d', $t), 'heure' => $cr['heure'], 'reseau' => $r,
                    'raison' => mb_strtolower($j) . ' ' . socRecoX($cr['jours'][(int) date('N', $t)])];
            }
        }
    }
    usort($creneaux, static fn ($a, $b) => [$a['date'], array_search($a['reseau'], array_keys(SOC_PLAN_RESEAUX), true)] <=> [$b['date'], array_search($b['reseau'], array_keys(SOC_PLAN_RESEAUX), true)]);
    $creneaux = array_slice($creneaux, 0, SOC_PLAN_MAX);
    // Les sujets : au prorata des poids (plus grand reste), dans l'ordre des dates ; les annonces d'abord, les rappels à la fin.
    $poids = $mixes[$mixCle][1]; $total = array_sum($poids); $n = count($creneaux);
    $parts = []; foreach ($poids as $k => $p) { $parts[$k] = $n * $p / $total; }
    $nb = array_map('intval', array_map('floor', $parts));
    $reste = $n - array_sum($nb);
    uasort($parts, static fn ($a, $b) => ($b - floor($b)) <=> ($a - floor($a)));
    foreach (array_keys($parts) as $k) { if ($reste-- <= 0) { break; } $nb[$k]++; }
    // L'annonce ouvre un plan qui la prévoit (dès 2 posts) et tient dans sa première semaine, au plus la moitié des posts ;
    // le rappel le clôt (dès 3 posts), dans sa dernière semaine. Ce qui manque ou déborde passe au sujet principal du mix.
    $principal = (string) array_search(max(array_diff_key($poids, ['annonce' => 1, 'rappel' => 1])), $poids, true);
    $semaine = static fn (callable $dans) => count(array_filter($creneaux, static fn ($c) => $dans($c['date'])));
    $bornes = ['annonce' => [2, $semaine(static fn ($d) => $d <= date('Y-m-d', strtotime($du . ' +6 days')))],
        'rappel' => [3, $semaine(static fn ($d) => $d >= date('Y-m-d', strtotime($au . ' -6 days')))]];
    foreach ($bornes as $k => [$des, $dansSemaine]) {
        if (!isset($nb[$k]) || $n < $des) { continue; }
        $voulu = min(max(1, $nb[$k]), max(1, (int) ceil($dansSemaine / 2)));
        $nb[$principal] += $nb[$k] - $voulu; $nb[$k] = $voulu;
    }
    $suite = array_fill(0, $nb['annonce'] ?? 0, 'annonce'); $nb['annonce'] = 0;
    $finale = array_fill(0, $nb['rappel'] ?? 0, 'rappel'); $nb['rappel'] = 0;
    // Le reste, entrelacé : à chaque pas, le sujet le plus en retard sur sa part.
    $faits = array_fill_keys(array_keys($nb), 0); $milieu = $n - count($suite) - count($finale);
    for ($i = 0; $i < $milieu; $i++) {
        $choix = null; $retard = -INF;
        foreach ($nb as $k => $v) { if ($v <= $faits[$k]) { continue; } $r = ($i + 1) * $v / max(1, $milieu) - $faits[$k]; if ($r > $retard) { $retard = $r; $choix = $k; } }
        if ($choix === null) { break; }
        $faits[$choix]++; $suite[] = $choix;
    }
    $suite = array_merge($suite, $finale);
    $sujets = socPlanSujets(); $posts = [];
    foreach ($creneaux as $i => $c) {
        $k = $suite[$i] ?? 'produit';
        $posts[] = $c + ['sujet' => $k, 'sujetLibelle' => $sujets[$k][0], 'type' => $sujets[$k][1],
            'format' => match ($c['reseau']) { 'story' => 'Story', 'fbig' => $sujets[$k][2]['instagram'], default => $sujets[$k][2][$c['reseau']] }];
    }
    $parReseau = []; foreach ($posts as $p) { $parReseau[$p['reseau']] = ($parReseau[$p['reseau']] ?? 0) + 1; }
    $classement = [];
    foreach ($cr['jours'] as $n => $i) { $classement[] = ['jour' => SOC_JOURS_FR[$n], 'indice' => $i]; }
    usort($classement, static fn ($a, $b) => $b['indice'] <=> $a['indice']);
    return ['du' => $du, 'au' => $au, 'mix' => $mixCle, 'posts' => $posts,
        'creneaux' => ['heure' => $cr['heure'], 'creneau' => $cr['creneau'], 'source' => $cr['source'], 'jours' => $classement],
        'resume' => ['total' => count($posts), 'parReseau' => $parReseau, 'semaines' => (int) ceil(((strtotime($au) - strtotime($du)) / 86400 + 1) / 7)]];
}

/**
 * Ajoute le plan au calendrier : des brouillons rattachés à la campagne, sur ses boutiques. `remplacer` : les
 * brouillons d'un plan précédent de la campagne, encore vides (ni texte ni visuel), sont retirés d'abord.
 * Rend ['crees' => n, 'retires' => n, 'ids' => [...]].
 */
function socPlanCreer(string $campagne, array $in, bool $remplacer): array
{
    $op = socOperationParId($campagne);
    if ($op === null) { throw new HttpError(404, 'Campagne introuvable' . (socOperationsErreur() ? ' (' . socOperationsErreur() . ')' : '') . '.'); }
    $plan = socPlanProposer($in, socRecommandations(true));
    if (!$plan['posts']) { throw new HttpError(422, 'Le plan est vide sur cette période.'); }
    $retires = 0;
    if ($remplacer) {
        foreach (socItems('posts') as $p) {
            if (($p['campaign'] ?? null) === $campagne && !empty($p['plan']) && ($p['status'] ?? '') === 'Brouillon'
                && trim((string) ($p['text'] ?? '')) === '' && empty($p['media']) && empty($p['img'])) { socItemDelete('posts', (string) $p['id']); $retires++; }
        }
    }
    $shops = array_values(array_filter(array_map('strval', (array) ($op['shops'] ?? [])), static fn ($s) => $s !== ''));
    if (!$shops) { $shops = array_map(static fn ($s) => (string) $s['id'], socReseau()); }
    $planId = 'plan' . date('YmdHis'); $ids = [];
    foreach ($plan['posts'] as $i => $p) {
        $id = 'p' . substr((string) (int) (microtime(true) * 1000), -10) . sprintf('%03d', $i);
        $story = $p['reseau'] === 'story';
        $post = ['id' => $id, 'date' => $p['date'], 'time' => $p['heure'], 'type' => $p['type'], 'title' => $p['sujetLibelle'] . ' · ' . $op['name'],
            'status' => 'Brouillon', 'campaign' => $campagne, 'shops' => $shops, 'fb' => $p['reseau'] !== 'instagram', 'ig' => $p['reseau'] !== 'facebook',
            'story' => $story ? 'seule' : '', 'text' => '', 'textNl' => '', 'langs' => ['fr'], 'link' => '', 'spread' => 10, 'media' => [], 'img' => null,
            'author' => socUser(), 'plan' => ['id' => $planId, 'sujet' => $p['sujet'], 'format' => $p['format'], 'reseau' => $p['reseau'], 'raison' => $p['raison']]];
        socItemPut('posts', $post);
        $ids[] = $id;
    }
    socLog(socUser(), 'Plan de posts', count($ids) . ' brouillons ajoutés au calendrier pour « ' . $op['name'] . ' »' . ($retires ? ' (' . $retires . ' du plan précédent retirés)' : ''));
    return ['crees' => count($ids), 'retires' => $retires, 'ids' => $ids, 'plan' => $planId];
}
