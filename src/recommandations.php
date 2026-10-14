<?php
declare(strict_types=1);

/**
 * Créer un post : ce qui marche, et des idées de posts.
 *
 *  - CE QUI MARCHE : les publications des 90 derniers jours de chaque Page et de
 *    chaque compte Instagram, celles du module comprises, lues chez Meta en
 *    lecture seule (comme le Calendrier). Chacune est comparée à l'habitude de
 *    son propre compte : indice = (interactions + 1) / (médiane du compte + 1),
 *    interactions = réactions + commentaires + partages. Une Page de 3 000
 *    abonnés et une de 300 se comparent ainsi sans se fausser. Les indices sont
 *    groupés (jour, créneau, type de publication, longueur du texte, prix ou
 *    promo dans le texte, question, emoji, lien, réseau ; style et format du
 *    visuel pour les posts du module) ; un groupe ne parle qu'à partir de
 *    SOC_RECO_MIN publications. Ce sont des tendances, pas des preuves.
 *    Seuls les chiffres agrégés sont gardés (réglage `reco_cache`, 12 h).
 *  - IDÉES : promotions actives (TFBuddy) sans post, opérations du Marketing du
 *    BO en cours ou proches sans post, gammes qui commencent, et le créneau
 *    creux de chaque boutique (BO, `GET /api/cockpit/creux`, lecture seule).
 *    Une source muette est omise et nommée, sans bloquer les autres.
 */

const SOC_RECO_JOURS = 90;
const SOC_RECO_MIN = 4;         // publications au moins dans un groupe pour en dire quelque chose
const SOC_RECO_TTL = 43200;     // 12 h
const SOC_CREUX_TTL = 21600;    // 6 h
const SOC_JOURS_FR = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

/* --- ce qui marche -------------------------------------------------------------- */

/** Les posts du module par identifiant Meta : leur visuel (style, format) et leur type. */
function socRecoModule(): array
{
    $posts = [];
    foreach (socItems('posts') as $p) { $posts[(string) ($p['id'] ?? '')] = $p; }
    $out = [];
    foreach (Db::rows("SELECT content_id, meta_id FROM social_publication WHERE meta_id IS NOT NULL AND meta_id <> ''") as $r) {
        $p = $posts[(string) $r['content_id']] ?? null;
        if ($p === null) { continue; }
        $v = is_array($p['visual']['v'] ?? null) ? $p['visual']['v'] : null;
        $out[(string) $r['meta_id']] = ['style' => $v ? (string) ($v['style'] ?? '') : null, 'format' => $v ? (string) ($v['format'] ?? 'carre') : null, 'type' => (string) ($p['type'] ?? '')];
    }
    return $out;
}

/** Une publication du calendrier → la ligne analysée (rien d'autre n'est gardé). */
function socRecoPub(array $p, array $module): array
{
    $s = $p['stats'] ?? [];
    $e = null;
    if (($s['reactions'] ?? null) !== null || ($s['comments'] ?? null) !== null) {
        // Meta omet `shares` quand personne n'a partagé ; Instagram ne le donne pas.
        $e = (int) ($s['reactions'] ?? 0) + (int) ($s['comments'] ?? 0) + (int) ($s['shares'] ?? 0);
    }
    $type = (string) ($p['type'] ?? '');
    $type = match (true) {
        str_starts_with($type, 'Album') || $type === 'Carrousel' => 'Album ou carrousel',
        $type === 'Vidéo' || $type === 'Reel' => 'Vidéo ou reel',
        $type === 'Photo', $type === 'Lien', $type === 'Texte', $type === 'Story' => $type,
        default => 'Autre',
    };
    return ['compte' => (string) $p['account'], 'canal' => (string) $p['channel'], 'date' => (string) $p['date'], 'time' => (string) $p['time'],
        'type' => $type, 'texte' => (string) ($p['text'] ?? ''), 'e' => $e, 'module' => $module[(string) ($p['metaId'] ?? '')] ?? null];
}

/** Les publications des derniers jours, toutes Pages et tous comptes Instagram, lues chez Meta. */
function socRecoLire(int $jours = SOC_RECO_JOURS): array
{
    if (!Meta::configured()) { throw new HttpError(409, 'token Meta absent : saisissez-le dans Paramètres → API Meta → Clés d’accès Meta'); }
    $t1 = time(); $t0 = $t1 - $jours * 86400;
    $out = ['du' => date('Y-m-d', $t0), 'au' => date('Y-m-d', $t1), 'pubs' => [], 'comptes' => 0, 'erreurs' => []];
    try { $comptes = socCalComptes(); }
    catch (MetaError $e) { $out['erreurs'][] = 'Pages du System User : ' . $e->getMessage(); return $out; }
    $module = socRecoModule();
    foreach ($comptes as $key => $c) {
        if ($c['token'] === null || $c['token'] === '') { continue; }
        $ligne = ['key' => $key, 'name' => $c['name'], 'username' => '', 'shop' => $c['shop']];
        try {
            if ($c['type'] === 'facebook') {
                foreach (socCalLireFacebook($c['id'], $c['token'], $t0, $t1) as $x) {
                    if (!empty($x['id'])) { $out['pubs'][] = socRecoPub(socCalPostFacebook($x, $ligne, 'Publié'), $module); }
                }
            } else {
                foreach (socGraphTout($c['id'] . '/media', ['fields' => SOC_CAL_IG, 'since' => $t0, 'until' => $t1, 'limit' => 100], SOC_CAL_MAX, $c['token']) as $x) {
                    $t = strtotime((string) ($x['timestamp'] ?? '')) ?: 0;
                    if (!empty($x['id']) && $t >= $t0 && $t <= $t1) { $out['pubs'][] = socRecoPub(socCalPostInstagram($x, $ligne), $module); }
                }
            }
            $out['comptes']++;
        } catch (MetaError $e) {
            $out['erreurs'][] = ($c['name'] ?: $key) . ' : ' . $e->getMessage();
        }
    }
    return $out;
}

function socMediane(array $l): float
{
    sort($l);
    $n = count($l);
    if ($n === 0) { return 0.0; }
    return $n % 2 ? (float) $l[intdiv($n, 2)] : ($l[$n / 2 - 1] + $l[$n / 2]) / 2;
}

/** Le créneau d'une heure « HH:MM ». */
function socRecoCreneau(string $hm): string
{
    $h = (int) substr($hm, 0, 2);
    return match (true) { $h < 11 => 'Matin (avant 11 h)', $h < 14 => 'Midi (11 – 14 h)', $h < 18 => 'Après-midi (14 – 18 h)', default => 'Soir (18 h et plus)' };
}

/** Les dimensions comparées : [libellé, classement d'une publication (null : hors de la dimension)]. */
function socRecoDimensions(): array
{
    return [
        'jour' => ['Jour de publication', static fn ($p) => SOC_JOURS_FR[(int) date('N', strtotime($p['date']))]],
        'creneau' => ['Heure de publication', static fn ($p) => socRecoCreneau($p['time'])],
        'type' => ['Type de publication', static fn ($p) => $p['type']],
        'longueur' => ['Longueur du texte', static function ($p) {
            $n = mb_strlen(trim($p['texte']));
            return match (true) { $n === 0 => 'Sans texte', $n < 125 => 'Texte court (moins de 125 signes)', $n <= 300 => 'Texte moyen (125 à 300 signes)', default => 'Texte long (plus de 300 signes)' };
        }],
        'prix' => ['Prix ou promotion dans le texte', static fn ($p) => preg_match('/\d+(?:[.,]\d{1,2})?\s?€|€\s?\d|\b\d{1,2}\s?%|\b\d\s?\+\s?\d\b|offert|gratuit|promo|réduction|remise|gratis|korting|aanbieding/iu', $p['texte'])
            ? 'Avec un prix ou une promo' : 'Sans prix ni promo'],
        'question' => ['Question au lecteur', static fn ($p) => str_contains($p['texte'], '?') ? 'Pose une question' : 'Sans question'],
        'emoji' => ['Emojis', static fn ($p) => preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $p['texte']) ? 'Avec emojis' : 'Sans emoji'],
        'lien' => ['Lien dans le texte', static fn ($p) => preg_match('#https?://|www\.#i', $p['texte']) ? 'Avec un lien' : 'Sans lien'],
        'canal' => ['Réseau', static fn ($p) => $p['canal']],
        'style' => ['Style du visuel (posts du module)', static fn ($p) => ($p['module']['style'] ?? '') !== '' ? $p['module']['style'] : null],
        'format' => ['Format du visuel (posts du module)', static fn ($p) => ($p['module']['format'] ?? '') !== '' ? $p['module']['format'] : null],
    ];
}

function socRecoX(float $i): string { return '×' . number_format($i, 1, ',', ''); }

/**
 * L'analyse : chaque publication rapportée à l'habitude de son compte, puis groupée.
 * Pure (sans Meta) : la recette lui donne ses publications.
 */
function socRecoAnalyser(array $pubs, int $min = SOC_RECO_MIN): array
{
    $parCompte = [];
    foreach ($pubs as $p) { if ($p['e'] !== null) { $parCompte[$p['compte']][] = $p['e']; } }
    // Un compte sert de référence à partir de 3 publications chiffrées.
    $med = [];
    foreach ($parCompte as $k => $l) { if (count($l) >= 3) { $med[$k] = socMediane($l); } }
    $l = [];
    foreach ($pubs as $p) {
        if ($p['e'] === null || !isset($med[$p['compte']])) { continue; }
        $p['i'] = ($p['e'] + 1) / ($med[$p['compte']] + 1);
        $l[] = $p;
    }
    $dims = []; $constats = [];
    foreach (socRecoDimensions() as $cle => [$libelle, $classer]) {
        $g = [];
        foreach ($l as $p) { $v = $classer($p); if ($v !== null) { $g[$v][] = $p['i']; } }
        $valeurs = [];
        foreach ($g as $v => $ix) { $valeurs[] = ['valeur' => (string) $v, 'n' => count($ix), 'indice' => round(socMediane($ix), 2), 'fiable' => count($ix) >= $min]; }
        usort($valeurs, static fn ($a, $b) => [$b['fiable'], $b['indice']] <=> [$a['fiable'], $a['indice']]);
        $dims[] = ['cle' => $cle, 'libelle' => $libelle, 'valeurs' => $valeurs];
        $f = array_values(array_filter($valeurs, static fn ($x) => $x['fiable']));
        if (count($f) < 2) { continue; }
        $haut = $f[0]; $bas = $f[count($f) - 1];
        $ecart = ($haut['indice'] + 0.01) / ($bas['indice'] + 0.01);
        if ($ecart < 1.3) { continue; }
        $constats[] = ['dimension' => $cle, 'libelle' => $libelle, 'mieux' => $haut['valeur'], 'moins' => $bas['valeur'], 'ecart' => round($ecart, 1),
            'texte' => $haut['valeur'] . ' : ' . socRecoX($haut['indice']) . ' l’habitude du compte (' . $haut['n'] . ' publications), contre '
                . socRecoX($bas['indice']) . ' pour « ' . mb_strtolower($bas['valeur']) . ' » (' . $bas['n'] . ').',
            'poids' => log($ecart) * sqrt(min($haut['n'], $bas['n']))];
    }
    usort($constats, static fn ($a, $b) => $b['poids'] <=> $a['poids']);
    $constats = array_map(static function ($c) { unset($c['poids']); return $c; }, array_slice($constats, 0, 6));
    // Le prochain créneau conseillé : le meilleur jour et le meilleur créneau (chacun assez fourni).
    $meilleur = static function (string $cle) use ($dims) {
        foreach ($dims as $d) { if ($d['cle'] === $cle) { $f = array_values(array_filter($d['valeurs'], static fn ($x) => $x['fiable'])); return count($f) >= 2 ? $f[0] : null; } }
        return null;
    };
    $jour = $meilleur('jour'); $cren = $meilleur('creneau');
    $conseil = null;
    if ($jour !== null && $cren !== null) {
        $heures = array_map(static fn ($p) => (int) substr($p['time'], 0, 2) * 60 + (int) substr($p['time'], 3, 2),
            array_filter($l, static fn ($p) => socRecoCreneau($p['time']) === $cren['valeur'] && $p['i'] >= 1));
        $m = $heures ? (int) socMediane(array_values($heures)) : 9 * 60;
        $m = (int) (round($m / 15) * 15);
        $n = (int) array_search($jour['valeur'], SOC_JOURS_FR, true);
        $d = strtotime('tomorrow');
        while ((int) date('N', $d) !== $n) { $d = strtotime('+1 day', $d); }
        $conseil = ['jour' => $jour['valeur'], 'creneau' => $cren['valeur'], 'indiceJour' => $jour['indice'], 'indiceCreneau' => $cren['indice'],
            'date' => date('Y-m-d', $d), 'heure' => sprintf('%02d:%02d', intdiv($m, 60), $m % 60)];
    }
    return ['publications' => count($pubs), 'comparees' => count($l), 'comptesCompares' => count($med), 'sansChiffres' => count(array_filter($pubs, static fn ($p) => $p['e'] === null)),
        'min' => $min, 'constats' => $constats, 'conseil' => $conseil, 'dimensions' => $dims];
}

/**
 * Ce qui marche, gardé 12 h. `$cache` : ne rien lire chez Meta (rend null sans analyse gardée) ;
 * `$forcer` : relire maintenant.
 */
function socRecommandations(bool $cache = false, bool $forcer = false): ?array
{
    $c = socSetting('reco_cache');
    if (!$forcer && is_array($c) && is_array($c['data'] ?? null) && ($cache || ($c['le'] ?? 0) > time() - SOC_RECO_TTL)) { return $c['data']; }
    if ($cache) { return null; }
    $lu = socRecoLire();
    $data = socRecoAnalyser($lu['pubs']) + ['du' => $lu['du'], 'au' => $lu['au'], 'jours' => SOC_RECO_JOURS, 'comptesLus' => $lu['comptes'],
        'erreurs' => array_slice($lu['erreurs'], 0, 10), 'le' => date('Y-m-d H:i')];
    socSettingPut('reco_cache', ['le' => time(), 'data' => $data], false);
    return $data;
}

/** Les tendances qui touchent au texte, pour l'assistant de rédaction (rien si rien n'est mesuré). */
function socRecoConseilsTexte(): array
{
    $c = socSetting('reco_cache');
    $out = [];
    foreach ((array) ($c['data']['constats'] ?? []) as $x) {
        if (in_array($x['dimension'] ?? '', ['longueur', 'question', 'emoji', 'prix', 'lien'], true)) { $out[] = (string) $x['texte']; }
    }
    return array_slice($out, 0, 3);
}

/* --- idées de posts --------------------------------------------------------------- */

/** Le jour suivant (à partir de demain) qui tombe un des jours ISO donnés (1 = lundi). */
function socProchainJour(array $jours, ?string $apres = null): string
{
    $d = strtotime(($apres ?? date('Y-m-d')) . ' +1 day');
    for ($i = 0; $i < 7 && $jours && !in_array((int) date('N', $d), array_map('intval', $jours), true); $i++) { $d = strtotime('+1 day', $d); }
    return date('Y-m-d', $d);
}

function socDateFr(?string $d): string { return $d ? substr($d, 8, 2) . '/' . substr($d, 5, 2) : ''; }

/** Les créneaux creux du BO (chaque boutique : son bloc principal), gardés 6 h. Null : BO muet. */
function socCreux(bool $forcer = false): ?array
{
    $c = socSetting('creux_cache');
    if (!$forcer && is_array($c) && ($c['le'] ?? 0) > time() - SOC_CREUX_TTL && is_array($c['reseau'] ?? null)) { return $c['reseau']; }
    $bases = array_values(array_unique(array_map(static fn ($b) => preg_replace('#/api/v1/marketing$#', '/api/cockpit', $b), BoMarketing::candidates())));
    $cookie = (string) ($_COOKIE[BO_AUTH_COOKIE] ?? '');
    foreach ($bases as $base) {
        // N'importe quelle boutique connue du BO rend le bloc creux principal de toutes les autres (`reseau`).
        foreach (array_slice(array_map(static fn ($s) => (string) $s['id'], socReseau()), 0, 3) as $shop) {
            $url = $base . '/creux?' . http_build_query(['shop' => $shop, 'semaines' => 4, '_cache' => 900]);
            if (BoMarketing::$transport) { [$code, $b] = (BoMarketing::$transport)($url); }
            else {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $cookie !== '' ? ['Cookie: ' . BO_AUTH_COOKIE . '=' . $cookie] : [])]);
                $r = curl_exec($ch);
                $code = $r === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $b = $r === false ? '' : (string) $r;
            }
            if ($code === 0) { continue 2; }   // adresse injoignable : l'adresse suivante
            if ($code === 400) { continue; }   // boutique inconnue du BO : la boutique suivante
            $j = $code >= 200 && $code < 300 ? json_decode($b, true) : null;
            if (!is_array($j) || !is_array($j['reseau'] ?? null)) { return null; }
            $reseau = [];
            foreach ($j['reseau'] as $x) {
                $bl = $x['bloc'] ?? null;
                if (!is_array($bl) || empty($x['id']) || (float) ($bl['potentiel'] ?? 0) <= 0) { continue; }
                $reseau[] = ['shop' => (string) $x['id'], 'nom' => (string) ($x['nom'] ?? ''), 'moyenne' => (float) ($x['moyenne'] ?? 0),
                    'jours' => array_values(array_map('intval', (array) ($bl['jours'] ?? []))), 'heureDe' => (int) ($bl['heureDe'] ?? 0), 'heureA' => (int) ($bl['heureA'] ?? 0),
                    'creneau' => (string) ($bl['nom'] ?? ''), 'caH' => (float) ($bl['caH'] ?? 0), 'potentiel' => (float) $bl['potentiel']];
            }
            socSettingPut('creux_cache', ['le' => time(), 'reseau' => $reseau], false);
            return $reseau;
        }
    }
    return null;
}

/**
 * Les idées de posts, des plus pressées aux autres. Chaque idée porte de quoi
 * préremplir Créer un post (`prefill` : boutiques, date, heure, opération, offre,
 * élément du catalogue, objectif pour l'assistant de rédaction).
 */
function socIdees(): array
{
    $auj = date('Y-m-d');
    $dans = static fn (int $j) => date('Y-m-d', strtotime($auj . ' ' . ($j >= 0 ? '+' : '') . $j . ' days'));
    $idees = []; $muettes = [];
    $posts = socItems('posts');
    $recents = array_filter($posts, static fn ($p) => (string) ($p['date'] ?? '') >= $dans(-30));
    $noms = [];
    foreach (socShops() as $s) { $noms[(string) $s['id']] = socNomDePage((string) $s['name']); }
    $portee = static fn (array $ids) => implode(', ', array_map(static fn ($i) => $noms[$i] ?? $i, $ids));
    $demain = $dans(1);

    // 1. Promotions actives sans post depuis 30 jours.
    try {
        $promos = socPromotions();
        $vues = [];
        foreach ($recents as $p) { if (is_array($p['offre'] ?? null) && isset($p['offre']['id'])) { $vues[(string) $p['offre']['id']] = true; } }
        foreach ($promos as $x) {
            if (isset($vues[(string) $x['id']]) || ($x['du'] !== null && $x['du'] > $dans(14))) { continue; }
            $reste = $x['au'] !== null ? (int) round((strtotime($x['au']) - strtotime($auj)) / 86400) : null;
            $shops = $x['tous'] ? [] : array_values(array_intersect($x['shops'], array_keys($noms)));
            $offre = ['genre' => 'promotion', 'id' => $x['id'], 'type' => $x['type'], 'nom' => $x['nom'], 'libelle' => $x['libelle'], 'court' => (string) ($x['court'] ?? ''), 'du' => $x['du'], 'au' => $x['au'],
                'tous' => $x['tous'], 'shops' => $x['shops'], 'portee' => $x['tous'] ? 'Toutes les boutiques' : $portee($shops)];
            $idees[] = ['id' => 'promo-' . $x['id'], 'genre' => 'promo', 'urgence' => $reste !== null ? max(0, 30 - $reste) : 5,
                'titre' => 'Promotion « ' . $x['nom'] . ' » : aucun post',
                'detail' => trim(($x['libelle'] ?: $x['nom']) . ' · ' . ($x['du'] && $x['du'] > $auj ? 'à partir du ' . socDateFr($x['du']) : 'en cours')
                    . ($x['au'] ? ', jusqu’au ' . socDateFr($x['au']) . ($reste !== null && $reste <= 7 ? ' (plus que ' . $reste . ' jour' . ($reste > 1 ? 's' : '') . ')' : '') : '')
                    . ' · ' . $offre['portee']),
                'prefill' => ['shops' => $shops, 'date' => max($demain, (string) ($x['du'] ?? '')), 'offre' => $offre,
                    'catalogue' => $x['produits'] ? ['kind' => 'produit', 'id' => (int) $x['produits'][0]] : ($x['categories'] ? ['kind' => 'categorie', 'id' => (int) $x['categories'][0]] : null),
                    'objectif' => 'Faire connaître la promotion « ' . $x['nom'] . ' » (' . $x['libelle'] . ')']];
        }
    } catch (Throwable $e) { $muettes[] = 'promotions (TFBuddy)'; }

    // 2. Opérations du Marketing du BO en cours, ou qui commencent dans les 3 semaines, sans post.
    $ops = socOperations();
    if (socOperationsErreur() !== null) { $muettes[] = 'opérations (Marketing du BO)'; }
    $ratt = array_count_values(array_map('strval', socRattachements()));
    $parOp = [];
    foreach ($posts as $p) { if (($p['campaign'] ?? '') !== '') { $parOp[(string) $p['campaign']] = ($parOp[(string) $p['campaign']] ?? 0) + 1; } }
    foreach ($ops as $o) {
        // Une opération sans date (brouillon) attend d'être planifiée au BO.
        if ($o['status'] === 'terminée' || ($o['start'] === null && $o['status'] !== 'en cours') || ($o['start'] !== null && $o['start'] > $dans(21))) { continue; }
        if (($parOp[$o['id']] ?? 0) + ($ratt[$o['id']] ?? 0) > 0) { continue; }
        $debut = $o['start'] !== null && $o['start'] > $auj ? $o['start'] : null;
        $idees[] = ['id' => 'op-' . $o['id'], 'genre' => 'operation', 'urgence' => $debut ? max(0, 21 - (int) round((strtotime($debut) - strtotime($auj)) / 86400)) : 25,
            'titre' => 'Opération « ' . $o['name'] . ' » : aucun post',
            'detail' => trim(($debut ? 'Commence le ' . socDateFr($debut) : 'En cours') . ($o['end'] ? ', jusqu’au ' . socDateFr($o['end']) : '')
                . ($o['description'] !== '' ? ' · ' . $o['description'] : '') . ' · ' . (count($o['shops']) ? $portee($o['shops']) : 'réseau')),
            'prefill' => ['shops' => array_values(array_intersect($o['shops'], array_keys($noms))), 'date' => $debut ? date('Y-m-d', max(strtotime($demain), strtotime($debut . ' -2 days'))) : $demain,
                'campaign' => $o['id'], 'objectif' => 'Annoncer l’opération « ' . $o['name'] . ' »' . ($o['description'] !== '' ? ' : ' . $o['description'] : '')]];
    }

    // 3. Gammes qui commencent dans les 3 semaines (ou depuis moins d'une semaine) sans post.
    try {
        $nav = socNavCatalogue();
        $annee = (int) date('Y');
        foreach ((array) ($nav['gammes'] ?? []) as $g) {
            if (empty($g['du']) || !preg_match('#^(\d{2})/(\d{2})$#', (string) $g['du'], $m)) { continue; }
            $debut = sprintf('%04d-%s-%s', $annee, $m[2], $m[1]);
            if ($debut < $dans(-180)) { $debut = sprintf('%04d-%s-%s', $annee + 1, $m[2], $m[1]); }
            if ($debut < $dans(-7) || $debut > $dans(21)) { continue; }
            $deja = false;
            foreach ($recents as $p) { if (($p['catalogue']['kind'] ?? '') === 'gamme' && (int) ($p['catalogue']['id'] ?? 0) === (int) $g['id']) { $deja = true; } }
            if ($deja) { continue; }
            $nom = trim((string) preg_replace('/^gamme\s+/iu', '', (string) $g['name'])) ?: (string) $g['name'];
            $idees[] = ['id' => 'gamme-' . $g['id'], 'genre' => 'gamme', 'urgence' => 20 - (int) round((strtotime($debut) - strtotime($auj)) / 86400),
                'titre' => 'Gamme « ' . $nom . ' » : ' . ($debut > $auj ? 'commence le ' . socDateFr($debut) : 'commencée le ' . socDateFr($debut)),
                'detail' => implode(' · ', array_filter([$g['n'] ? $g['n'] . ' produit' . ($g['n'] > 1 ? 's' : '') . ' sur le webshop' : '', $g['au'] ? 'jusqu’au ' . $g['au'] : '', 'aucun post depuis 30 jours'])),
                'prefill' => ['shops' => [], 'date' => max($demain, $debut), 'catalogue' => ['kind' => 'gamme', 'id' => (int) $g['id']], 'objectif' => 'Annoncer l’arrivée de la gamme « ' . $nom . ' »']];
        }
    } catch (Throwable $e) { $muettes[] = 'gammes (catalogue)'; }

    // 4. Le créneau creux de chaque boutique : un post quelques heures avant pour le remplir.
    $creux = socCreux();
    if ($creux === null) { $muettes[] = 'heures creuses (BO)'; }
    foreach ($creux ?? [] as $x) {
        if (!isset($noms[$x['shop']])) { continue; }
        $h = max(8, $x['heureDe'] - 2);
        $idees[] = ['id' => 'creux-' . $x['shop'], 'genre' => 'creux', 'urgence' => min(15, (int) round($x['potentiel'] / 500)),
            'titre' => $noms[$x['shop']] . ' : creux ' . mb_strtolower($x['creneau']),
            'detail' => number_format($x['caH'], 0, ',', ' ') . ' € de l’heure contre ' . number_format($x['moyenne'], 0, ',', ' ') . ' € en moyenne · environ '
                . number_format($x['potentiel'], 0, ',', ' ') . ' € à aller chercher sur 4 semaines · un post vers ' . $h . ' h pour donner envie de passer',
            'prefill' => ['shops' => [$x['shop']], 'date' => socProchainJour($x['jours']), 'time' => sprintf('%02d:00', $h),
                'objectif' => 'Faire venir des clients ' . mb_strtolower($x['creneau']) . ' (heures creuses de la boutique) : une raison de passer à ce moment-là, sans inventer d’offre']];
    }
    usort($idees, static fn ($a, $b) => $b['urgence'] <=> $a['urgence']);
    return ['idees' => array_slice($idees, 0, 12), 'muettes' => $muettes, 'le' => date('Y-m-d H:i')];
}
