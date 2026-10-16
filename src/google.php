<?php
declare(strict_types=1);

/**
 * Les avis Google des boutiques : la « Réputation digitale » du BO consultant, dans le module.
 *
 *  - Les chiffres viennent des tables du BO, LUES seulement : `ceo_shop_reputation` (note et nombre d'avis de
 *    la fiche, tels que Google les affiche) et `ceo_shop_review` (les avis rapatriés, accumulés à chaque
 *    synchronisation : Google n'en rend que cinq par appel) ; si cette base ne les montre pas, de l'API du BO
 *    (`GET {cockpit}/reputation`, l'écran Réputation digitale). Le module n'appelle pas Google lui-même : la
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
 * Ce que le BO sait de la réputation, sous une seule forme : ses tables si cette base les montre (même base que le
 * BO), sinon son API — `GET {cockpit}/reputation`, celle de l'écran Réputation digitale (l'utilisateur MySQL du
 * module peut ne pas voir les tables du BO). Par l'API, les avis sont les cinq derniers de chaque fiche : le cron les
 * recopie toutes les heures, les nouveaux s'ajoutent à ceux déjà gardés.
 * Rend ['source' => base|api, 'cible', 'configure', 'derniere', 'fiches' => [boutique => {note, avis, raccorde, url,
 * synchro}], 'par' => [boutique => [étoiles => n]], 'avis' => [{shop, auteur, note, texte, le}]] ou ['indispo', 'cible', 'raison'].
 */
function socGoogleBo(): array
{
    $map = socGoogleShopIds();
    $vers = static fn (string $id): string => $map[$id] ?? $id;
    $fiches = []; $par = []; $avis = [];
    if (socGoogleDispo()) {
        $derniere = null;
        foreach (Db::rows('SELECT * FROM ceo_shop_reputation') as $r) {
            $fiches[$vers((string) $r['shop_id'])] = ['note' => $r['rating_avg'] !== null ? (float) $r['rating_avg'] : null, 'avis' => (int) $r['rating_count'],
                'raccorde' => (string) ($r['place_id'] ?? '') !== '', 'url' => ($r['profile_url'] ?? null) ?: null, 'synchro' => $r['synced_at'] !== null ? substr((string) $r['synced_at'], 0, 16) : null];
            if ($r['synced_at'] !== null && ($derniere === null || $r['synced_at'] > $derniere)) { $derniere = (string) $r['synced_at']; }
        }
        foreach (Db::rows('SELECT shop_id, rating, COUNT(*) n FROM ceo_shop_review GROUP BY shop_id, rating') as $r) {
            $sid = $vers((string) $r['shop_id']);
            $par[$sid][(int) $r['rating']] = ($par[$sid][(int) $r['rating']] ?? 0) + (int) $r['n'];
        }
        foreach (Db::rows('SELECT shop_id, author, rating, comment, reviewed_at FROM ceo_shop_review ORDER BY reviewed_at DESC, id DESC LIMIT 2000') as $r) {
            $avis[] = ['shop' => $vers((string) $r['shop_id']), 'auteur' => (string) ($r['author'] ?? ''), 'note' => (int) $r['rating'], 'texte' => (string) ($r['comment'] ?? ''), 'le' => (string) $r['reviewed_at']];
        }
        $g = boSetting('google');
        return ['source' => 'base', 'cible' => socGoogleCible(), 'configure' => trim((string) (is_array($g) ? ($g['cle'] ?? '') : '')) !== '' || (string) (boConfig()['googleApiKey'] ?? '') !== '',
            'derniere' => $derniere !== null ? substr($derniere, 0, 16) : null, 'fiches' => $fiches, 'par' => $par, 'avis' => $avis];
    }
    try { [$code, $j] = BoCockpit::requete('GET', '/reputation'); }
    catch (HttpError $e) { return ['indispo' => true, 'cible' => socGoogleCible(), 'raison' => 'La Réputation digitale du BO est illisible : ' . $e->getMessage()]; }
    if ($code !== 200 || !isset($j['magasins']) || !is_array($j['magasins'])) {
        return ['indispo' => true, 'cible' => socGoogleCible(), 'raison' => !empty($j['indispo']) ? (string) ($j['raison'] ?? 'Réputation indisponible dans le BO.')
            : 'La Réputation digitale du BO est illisible (HTTP ' . $code . ').'];
    }
    foreach ($j['magasins'] as $m) {
        if (!is_array($m) || !isset($m['id'])) { continue; }
        $sid = $vers((string) $m['id']);
        $fiches[$sid] = ['note' => isset($m['note']) && is_numeric($m['note']) ? (float) $m['note'] : null, 'avis' => (int) ($m['avis'] ?? 0),
            'raccorde' => (string) ($m['placeId'] ?? '') !== '', 'url' => ($m['url'] ?? null) ?: null, 'synchro' => isset($m['synchro']) ? substr((string) $m['synchro'], 0, 16) : null];
        foreach ((array) ($m['repartition']['niveaux'] ?? []) as $l) { if ((int) ($l['n'] ?? 0) > 0) { $par[$sid][(int) $l['note']] = (int) $l['n']; } }
        foreach ((array) ($m['derniers'] ?? []) as $a) {
            if (!is_array($a) || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($a['le'] ?? ''))) { continue; }
            $avis[] = ['shop' => $sid, 'auteur' => (string) ($a['auteur'] ?? ''), 'note' => (int) ($a['note'] ?? 0), 'texte' => (string) ($a['texte'] ?? ''),
                'le' => substr((string) $a['le'], 0, 10) . ' 12:00:00'];  // le BO ne rend que le jour
        }
    }
    $c = $j['cible'] ?? SOC_GG_CIBLE;
    return ['source' => 'api', 'cible' => is_numeric($c) && $c >= 1 && $c <= 5 ? (float) $c : SOC_GG_CIBLE, 'configure' => !empty($j['connecteur']['configure']),
        'derniere' => isset($j['connecteur']['derniereSynchro']) ? substr((string) $j['connecteur']['derniereSynchro'], 0, 16) : null, 'fiches' => $fiches, 'par' => $par, 'avis' => $avis];
}

/** L'identifiant d'un avis dans le module : le même, qu'il soit lu dans les tables du BO ou par son API. */
function socGoogleAvisId(string $shop, string $auteur, string $le, int $note): string
{
    return 'gg_' . substr(sha1($shop . '|' . $auteur . '|' . substr($le, 0, 10) . '|' . $note), 0, 24);
}

/**
 * La réputation du réseau et de chaque boutique (ou d'une seule : `$shop`, le réseau restant calculé sur tout le
 * réseau), comme l'écran Réputation digitale du BO.
 */
function socGoogleReputation(?string $shop = null, ?array $bo = null): array
{
    $bo ??= socGoogleBo();
    if (!empty($bo['indispo'])) { return $bo; }
    $cible = (float) $bo['cible'];
    $magasins = []; $sommeNotes = 0.0; $sommeAvis = 0; $parReseau = [];
    foreach (socReseau() as $s) {
        $id = (string) $s['id'];
        $a = $bo['fiches'][$id] ?? null;
        $note = $a['note'] ?? null;
        $n = $a ? (int) $a['avis'] : 0;
        if ($note !== null && $n > 0) { $sommeNotes += $note * $n; $sommeAvis += $n; }
        foreach ($bo['par'][$id] ?? [] as $k => $v) { $parReseau[$k] = ($parReseau[$k] ?? 0) + $v; }
        $magasins[] = ['id' => $id, 'nom' => (string) $s['name'], 'note' => $note, 'avis' => $n,
            'ecart' => $note !== null ? round($note - $cible, 2) : null, 'avis5Requis' => socGoogleAvis5($note, $n, $cible),
            'repartition' => socGoogleRepartition($bo['par'][$id] ?? []), 'raccorde' => (bool) ($a['raccorde'] ?? false),
            'url' => $a['url'] ?? null, 'synchro' => $a['synchro'] ?? null];
    }
    $moyenne = $sommeAvis > 0 ? round($sommeNotes / $sommeAvis, 2) : null;
    $notes = array_values(array_filter(array_column($magasins, 'note'), static fn ($v) => $v !== null));
    return [
        'cible' => $cible, 'source' => $bo['source'],
        'connecteur' => ['configure' => (bool) $bo['configure'], 'raccordes' => count(array_filter($bo['fiches'], static fn ($f) => $f['raccorde'])), 'derniereSynchro' => $bo['derniere']],
        'reseau' => ['moyenne' => $moyenne, 'avis' => $sommeAvis, 'magasins' => count($magasins), 'notes' => count($notes),
            'sousCible' => count(array_filter($notes, static fn ($v) => $v < $cible)), 'avis5Requis' => socGoogleAvis5($moyenne, $sommeAvis, $cible),
            'repartition' => socGoogleRepartition($parReseau)],
        'magasins' => $shop === null ? $magasins : array_values(array_filter($magasins, static fn ($m) => $m['id'] === $shop)),
    ];
}

/**
 * Recopie les avis rapatriés par le BO dans `social_commentaire` (canal `gg`), pour les classer et les suivre
 * comme les commentaires. Un avis déjà connu garde son classement et son suivi. Rend ['lus', 'indispo', 'source'].
 */
function socGoogleAvisSync(): array
{
    $bo = socGoogleBo();
    if (!empty($bo['indispo'])) { return ['lus' => 0, 'indispo' => true, 'raison' => $bo['raison'] ?? '']; }
    $noms = [];
    foreach (socShops() as $s) { $noms[(string) $s['id']] = (string) $s['name']; }
    $lus = 0; $now = date('Y-m-d H:i:s');
    foreach ($bo['avis'] as $a) {
        $sid = $a['shop'];
        $f = $bo['fiches'][$sid] ?? null;
        $nom = $noms[$sid] ?? $sid;
        $fiche = 'Fiche Google de L’Atelier By ' . $nom . ($f && $f['note'] !== null
            ? ' (note de la fiche : ' . number_format((float) $f['note'], 1, ',', '') . ' sur ' . (int) $f['avis'] . ' avis)' : '');
        $lien = (string) ($f['url'] ?? '');
        $note = max(1, min(5, (int) $a['note']));
        Db::exec('INSERT INTO social_commentaire (id, canal, objet_id, shop_id, post_id, pub_id, auteur, message, cree_le, masque, maj_le, compte, compte_nom, objet_texte, objet_lien, lien, note)'
            . ' VALUES (?,?,?,?,NULL,NULL,?,?,?,0,?,?,?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE note = VALUES(note), shop_id = VALUES(shop_id), compte = VALUES(compte), compte_nom = VALUES(compte_nom),'
            . ' objet_texte = VALUES(objet_texte), objet_lien = VALUES(objet_lien), lien = VALUES(lien)',
            [socGoogleAvisId($sid, $a['auteur'], $a['le'], $note), 'gg', 'gg:' . $sid, $sid, mb_substr($a['auteur'], 0, 160), mb_substr($a['texte'], 0, 5000),
                $a['le'], $now, mb_substr($sid, 0, 40), mb_substr('Google · ' . $nom, 0, 160), $fiche, mb_substr($lien, 0, 500), mb_substr($lien, 0, 500), $note]);
        $lus++;
    }
    socSettingPut('google_avis_relus', ['le' => $now, 'lus' => $lus, 'source' => $bo['source']], false);
    return ['lus' => $lus, 'indispo' => false, 'source' => $bo['source']];
}

/**
 * Le cockpit du BO, pour la Réputation digitale : sa lecture (`GET /reputation`) quand cette base ne montre pas ses
 * tables, et son geste « Synchroniser » (Google Places) — le module le déclenche, le BO appelle Google avec SA clé et
 * écrit SES tables. La session du cockpit suit l'appel.
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

    /** GET ou POST JSON ; la première adresse qui répond. Rend [code HTTP, réponse décodée]. */
    public static function requete(string $method, string $path, array $body = []): array
    {
        $err = 'aucune adresse';
        foreach (self::candidates() as $b) {
            $url = $b . '/' . ltrim($path, '/');
            if (self::$transport) { [$code, $txt] = (self::$transport)($method, $url, $body); }
            else {
                $ch = curl_init($url);
                $h = ['Accept: application/json', 'Content-Type: application/json'];
                $c = (string) ($_COOKIE[BO_AUTH_COOKIE] ?? '');
                if ($c !== '') { $h[] = 'Cookie: ' . BO_AUTH_COOKIE . '=' . rawurlencode($c); }
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $method === 'POST' ? 90 : 20, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $h]
                    + ($method === 'POST' ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)] : []));
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
    [$code, $j] = BoCockpit::requete('POST', '/reputation/sync');
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
