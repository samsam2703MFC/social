<?php
declare(strict_types=1);

/**
 * Les avis Google des boutiques : la « Réputation digitale » du BO consultant, dans le module.
 *
 *  - Les chiffres viennent des tables du BO, LUES seulement : `ceo_shop_reputation` (note et nombre d'avis de
 *    la fiche, tels que Google les affiche) et `ceo_shop_review` (les avis rapatriés, accumulés à chaque
 *    synchronisation : Google n'en rend que cinq par appel). Le module n'appelle pas Google lui-même : la
 *    synchronisation reste celle du BO (`POST {cockpit}/reputation/sync`), lancée d'ici par un geste, comme
 *    dans le BO — chaque appel à Google est facturé.
 *  - Les calculs sont ceux du BO (src/endpoints.php, ep_reputation) : moyenne réseau PONDÉRÉE par le nombre
 *    d'avis, écart à la cible (`reputationCible` du BO, 4,5 par défaut), avis 5★ requis
 *    x ≥ n(C − A)/(5 − C) arrondi au supérieur, répartition par étoiles des avis LUS (Google ne publie pas
 *    l'histogramme d'une fiche : le nombre lu voyage avec la répartition).
 *  - Chaque avis entre dans `social_commentaire` (canal `gg`) et y est classé vert / orange / rouge comme un
 *    commentaire (src/commentaires.php), la note en étoiles et la fiche servant de contexte.
 */

const SOC_GG_CIBLE = 4.5;

/** Les tables de réputation du BO sont-elles dans cette base ? */
function socGoogleDispo(): bool
{
    return Db::hasTable('ceo_shop_reputation') && Db::hasTable('ceo_shop_review');
}

/**
 * Magasin du BO → boutique du module (identifiant de caisse). Le BO raccorde ses fiches sur la table partagée
 * `shops`, dont l'identifiant EST celui de la caisse : un identifiant que le module connaît reste tel quel. Seul un
 * ancien magasin de `ceo_shop` (identifiant propre au BO) passe par son `pwa_shop_id`.
 */
function socGoogleShopIds(): array
{
    $connus = array_fill_keys(array_map(static fn ($s) => (string) $s['id'], socShops()), true);
    $map = [];
    if (Db::hasTable('ceo_shop')) {
        foreach (Db::rows('SELECT id, pwa_shop_id FROM ceo_shop WHERE pwa_shop_id IS NOT NULL') as $r) {
            if (!isset($connus[(string) $r['id']])) { $map[(string) $r['id']] = (string) $r['pwa_shop_id']; }
        }
    }
    return $map;
}

/** La cible du BO (Paramètres du cockpit), 4,5 par défaut. */
function socGoogleCible(): float
{
    $c = boSetting('reputationCible', SOC_GG_CIBLE);
    $c = is_numeric($c) ? (float) $c : SOC_GG_CIBLE;
    return $c < 1 || $c > 5 ? SOC_GG_CIBLE : $c;
}

/** Combien d'avis 5 étoiles pour atteindre la cible (null : aucune note, ou cible à 5 hors d'atteinte). Comme le BO. */
function socGoogleAvis5(?float $note, int $avis, float $cible): ?int
{
    if ($note === null || $avis <= 0) { return null; }
    if ($note >= $cible) { return 0; }
    if ($cible >= 5) { return null; }
    return (int) ceil($avis * ($cible - $note) / (5 - $cible));
}

/** La répartition par étoiles d'avis lus (5 → 1). */
function socGoogleRepartition(array $par): array
{
    return ['lus' => array_sum($par), 'niveaux' => array_map(static fn ($n) => ['note' => $n, 'n' => (int) ($par[$n] ?? 0)], [5, 4, 3, 2, 1])];
}

/**
 * La réputation du réseau et de chaque boutique (ou d'une seule : `$shop`, le réseau restant calculé sur tout le
 * réseau), comme l'écran Réputation digitale du BO.
 */
function socGoogleReputation(?string $shop = null): array
{
    $cible = socGoogleCible();
    if (!socGoogleDispo()) {
        return ['indispo' => true, 'cible' => $cible, 'raison' => 'Les tables de réputation du BO (ceo_shop_reputation, ceo_shop_review) sont absentes de cette base.'];
    }
    $map = socGoogleShopIds();
    $vers = static fn (string $id): string => $map[$id] ?? $id;
    $agr = [];
    foreach (Db::rows('SELECT * FROM ceo_shop_reputation') as $r) { $agr[$vers((string) $r['shop_id'])] = $r; }
    $par = [];
    foreach (Db::rows('SELECT shop_id, rating, COUNT(*) n FROM ceo_shop_review GROUP BY shop_id, rating') as $r) {
        $sid = $vers((string) $r['shop_id']);
        $par[$sid][(int) $r['rating']] = ($par[$sid][(int) $r['rating']] ?? 0) + (int) $r['n'];
    }

    $magasins = []; $sommeNotes = 0.0; $sommeAvis = 0; $parReseau = [];
    foreach (socReseau() as $s) {
        $id = (string) $s['id'];
        $a = $agr[$id] ?? null;
        $note = ($a && $a['rating_avg'] !== null) ? (float) $a['rating_avg'] : null;
        $n = $a ? (int) $a['rating_count'] : 0;
        if ($note !== null && $n > 0) { $sommeNotes += $note * $n; $sommeAvis += $n; }
        foreach ($par[$id] ?? [] as $k => $v) { $parReseau[$k] = ($parReseau[$k] ?? 0) + $v; }
        $magasins[] = ['id' => $id, 'nom' => (string) $s['name'], 'note' => $note, 'avis' => $n,
            'ecart' => $note !== null ? round($note - $cible, 2) : null, 'avis5Requis' => socGoogleAvis5($note, $n, $cible),
            'repartition' => socGoogleRepartition($par[$id] ?? []), 'raccorde' => ($a['place_id'] ?? '') !== '' && $a !== null,
            'url' => ($a['profile_url'] ?? null) ?: null, 'synchro' => ($a && $a['synced_at'] !== null) ? substr((string) $a['synced_at'], 0, 16) : null];
    }
    $moyenne = $sommeAvis > 0 ? round($sommeNotes / $sommeAvis, 2) : null;
    $notes = array_values(array_filter(array_column($magasins, 'note'), static fn ($v) => $v !== null));
    $derniere = null;
    foreach ($agr as $r) { if ($r['synced_at'] !== null && ($derniere === null || $r['synced_at'] > $derniere)) { $derniere = $r['synced_at']; } }
    $g = boSetting('google');
    $configure = trim((string) (is_array($g) ? ($g['cle'] ?? '') : '')) !== '' || (string) (boConfig()['googleApiKey'] ?? '') !== '';
    $out = [
        'cible' => $cible,
        'connecteur' => ['configure' => $configure, 'raccordes' => count(array_filter($agr, static fn ($r) => (string) ($r['place_id'] ?? '') !== '')),
            'derniereSynchro' => $derniere !== null ? substr((string) $derniere, 0, 16) : null],
        'reseau' => ['moyenne' => $moyenne, 'avis' => $sommeAvis, 'magasins' => count($magasins), 'notes' => count($notes),
            'sousCible' => count(array_filter($notes, static fn ($v) => $v < $cible)), 'avis5Requis' => socGoogleAvis5($moyenne, $sommeAvis, $cible),
            'repartition' => socGoogleRepartition($parReseau)],
        'magasins' => $shop === null ? $magasins : array_values(array_filter($magasins, static fn ($m) => $m['id'] === $shop)),
    ];
    return $out;
}

/**
 * Recopie les avis rapatriés par le BO dans `social_commentaire` (canal `gg`), pour les classer et les suivre
 * comme les commentaires. Un avis déjà connu garde son classement et son suivi. Rend ['lus', 'indispo'].
 */
function socGoogleAvisSync(): array
{
    if (!socGoogleDispo()) { return ['lus' => 0, 'indispo' => true]; }
    $map = socGoogleShopIds();
    $fiches = [];
    foreach (Db::rows('SELECT * FROM ceo_shop_reputation') as $r) { $fiches[(string) $r['shop_id']] = $r; }
    $noms = [];
    foreach (socShops() as $s) { $noms[(string) $s['id']] = (string) $s['name']; }
    $lus = 0; $now = date('Y-m-d H:i:s');
    foreach (Db::rows('SELECT id, shop_id, author, rating, comment, reviewed_at FROM ceo_shop_review ORDER BY reviewed_at DESC, id DESC LIMIT 2000') as $r) {
        $ceo = (string) $r['shop_id'];
        $sid = $map[$ceo] ?? $ceo;
        $f = $fiches[$ceo] ?? null;
        $nom = $noms[$sid] ?? $sid;
        $fiche = 'Fiche Google de L’Atelier By ' . $nom . ($f && $f['rating_avg'] !== null
            ? ' (note de la fiche : ' . number_format((float) $f['rating_avg'], 1, ',', '') . ' sur ' . (int) $f['rating_count'] . ' avis)' : '');
        $lien = (string) ($f['profile_url'] ?? '');
        Db::exec('INSERT INTO social_commentaire (id, canal, objet_id, shop_id, post_id, pub_id, auteur, message, cree_le, masque, maj_le, compte, compte_nom, objet_texte, objet_lien, lien, note)'
            . ' VALUES (?,?,?,?,NULL,NULL,?,?,?,0,?,?,?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE message = VALUES(message), note = VALUES(note), shop_id = VALUES(shop_id), compte = VALUES(compte), compte_nom = VALUES(compte_nom),'
            . ' objet_texte = VALUES(objet_texte), objet_lien = VALUES(objet_lien), lien = VALUES(lien)',
            ['gg_' . $r['id'], 'gg', 'gg:' . $sid, $sid, mb_substr((string) ($r['author'] ?? ''), 0, 160), mb_substr((string) ($r['comment'] ?? ''), 0, 5000),
                (string) $r['reviewed_at'], $now, mb_substr($sid, 0, 40), mb_substr('Google · ' . $nom, 0, 160), $fiche, mb_substr($lien, 0, 500), mb_substr($lien, 0, 500),
                max(1, min(5, (int) $r['rating']))]);
        $lus++;
    }
    socSettingPut('google_avis_relus', ['le' => $now, 'lus' => $lus], false);
    return ['lus' => $lus, 'indispo' => false];
}

/**
 * Le cockpit du BO, pour son seul geste « Synchroniser » de la Réputation digitale (Google Places) : le module
 * le déclenche, le BO appelle Google avec SA clé et écrit SES tables. La session du cockpit suit l'appel.
 */
final class BoCockpit
{
    /** @var null|callable(string $method, string $url, array $body): array{0:int,1:string} — remplacé par la recette */
    public static $transport = null;

    public static function candidates(): array
    {
        $l = [rtrim((string) (socSetting('cockpit_api') ?: 'http://127.0.0.1/consulant_bo/api/cockpit'), '/')];
        $hote = parse_url((string) (socSetting('url_publique') ?? ''), PHP_URL_HOST);
        if (is_string($hote) && $hote !== '') { $l[] = 'http://' . $hote . '/' . trim((string) (socSetting('url_bo') ?? '/consulant_bo/'), '/') . '/api/cockpit'; }
        return array_values(array_unique($l));
    }

    /** POST JSON ; la première adresse qui répond. Rend [code HTTP, réponse décodée]. */
    public static function post(string $path, array $body): array
    {
        $err = 'aucune adresse';
        foreach (self::candidates() as $b) {
            $url = $b . '/' . ltrim($path, '/');
            if (self::$transport) { [$code, $txt] = (self::$transport)('POST', $url, $body); }
            else {
                $ch = curl_init($url);
                $h = ['Accept: application/json', 'Content-Type: application/json'];
                $c = (string) ($_COOKIE[BO_AUTH_COOKIE] ?? '');
                if ($c !== '') { $h[] = 'Cookie: ' . BO_AUTH_COOKIE . '=' . rawurlencode($c); }
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
                    CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $h]);
                $r = curl_exec($ch);
                $code = $r === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $txt = $r === false ? '' : (string) $r;
                if ($r === false) { $err = curl_error($ch); }
                curl_close($ch);
            }
            if ($code === 0 || $code === 404) { $err = $code === 404 ? 'HTTP 404 sur ' . $url : $err; continue; }
            $j = json_decode((string) $txt, true);
            return [$code, is_array($j) ? $j : []];
        }
        throw new HttpError(502, 'Le cockpit du BO est injoignable (' . $err . ').');
    }
}

/** « Synchroniser chez Google » : le BO relit les fiches, puis le module recopie et classe les nouveaux avis. */
function socGoogleSynchroniser(): array
{
    [$code, $j] = BoCockpit::post('/reputation/sync', []);
    if ($code === 401) { throw new HttpError(422, 'Le cockpit du BO demande une connexion : reconnectez-vous au BO, puis réessayez.'); }
    if ($code < 200 || $code >= 300 || !empty($j['error'])) {
        throw new HttpError($code === 422 ? 422 : 502, 'Synchronisation Google : ' . ((string) ($j['error'] ?? '') ?: 'HTTP ' . $code));
    }
    socLog(socUser(), 'Avis Google', 'Synchronisation des fiches Google (BO) : ' . (int) ($j['magasins'] ?? 0) . ' fiche(s), ' . (int) ($j['nouveaux'] ?? 0) . ' nouvel(s) avis');
    return ['magasins' => (int) ($j['magasins'] ?? 0), 'nouveaux' => (int) ($j['nouveaux'] ?? 0), 'erreurs' => array_values(array_map('strval', (array) ($j['erreurs'] ?? []))),
        'aucuneFiche' => !empty($j['aucuneFiche'])];
}

/** L'écran : la réputation, plus les avis classés de la période (`jours`, un an par défaut). */
function socGoogleEcran(array $q, ?string $shop = null): array
{
    $l = socCommentairesListe(['source' => 'google', 'jours' => $q['jours'] ?? 365, 'couleur' => $q['couleur'] ?? '', 'nonTraites' => $q['nonTraites'] ?? '',
        'shop' => $shop ?? ($q['shop'] ?? '')]);
    return ['reputation' => socGoogleReputation($shop), 'jours' => $l['jours'], 'avis' => $l['commentaires'], 'totaux' => $l['totaux'],
        'relu' => socSetting('google_avis_relus'), 'ia' => $l['ia']];
}
