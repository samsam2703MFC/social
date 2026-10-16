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
 *  - Faire venir des avis : un lien court par boutique (`/avis/{boutique}`, public) qui compte les scans du QR code
 *    et de l'affiche puis ouvre la fenêtre d'avis de la fiche ; l'historique quotidien de la note
 *    (`social_google_suivi`) dit combien d'avis chaque boutique a gagnés.
 *  - La synchronisation chez Google se fait aussi toute seule, une fois par jour (réglable, activée par défaut).
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
                'raccorde' => (string) ($r['place_id'] ?? '') !== '', 'placeId' => (string) ($r['place_id'] ?? ''), 'url' => ($r['profile_url'] ?? null) ?: null,
                'synchro' => $r['synced_at'] !== null ? substr((string) $r['synced_at'], 0, 16) : null];
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
            'raccorde' => (string) ($m['placeId'] ?? '') !== '', 'placeId' => (string) ($m['placeId'] ?? ''), 'url' => ($m['url'] ?? null) ?: null,
            'synchro' => isset($m['synchro']) ? substr((string) $m['synchro'], 0, 16) : null];
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
    socGoogleSuiviNoter($bo);
    $cible = (float) $bo['cible'];
    $g7 = socGoogleGagnes(7); $g30 = socGoogleGagnes(30); $sc7 = socGoogleScans(7); $sc30 = socGoogleScans(30);
    $magasins = []; $sommeNotes = 0.0; $sommeAvis = 0; $parReseau = [];
    foreach (socReseau() as $s) {
        $id = (string) $s['id'];
        $a = $bo['fiches'][$id] ?? null;
        $note = $a['note'] ?? null;
        $n = $a ? (int) $a['avis'] : 0;
        if ($note !== null && $n > 0) { $sommeNotes += $note * $n; $sommeAvis += $n; }
        foreach ($bo['par'][$id] ?? [] as $k => $v) { $parReseau[$k] = ($parReseau[$k] ?? 0) + $v; }
        $magasins[] = ['id' => $id, 'nom' => (string) $s['name'], 'langs' => array_values((array) ($s['langs'] ?? ['fr'])), 'note' => $note, 'avis' => $n,
            'ecart' => $note !== null ? round($note - $cible, 2) : null, 'avis5Requis' => socGoogleAvis5($note, $n, $cible),
            'repartition' => socGoogleRepartition($bo['par'][$id] ?? []), 'raccorde' => (bool) ($a['raccorde'] ?? false),
            'url' => $a['url'] ?? null, 'synchro' => $a['synchro'] ?? null,
            // Faire venir des avis : le lien d'avis Google de la fiche, et le lien court du module qui compte les scans.
            'lienAvis' => socGoogleLienAvis((string) ($a['placeId'] ?? '')), 'lienCourt' => ($a['placeId'] ?? '') !== '' ? socGoogleLienCourt($id) : null,
            'gagnes7' => $g7['shops'][$id] ?? null, 'gagnes30' => $g30['shops'][$id] ?? null, 'scans7' => $sc7[$id] ?? 0, 'scans30' => $sc30[$id] ?? 0];
    }
    $moyenne = $sommeAvis > 0 ? round($sommeNotes / $sommeAvis, 2) : null;
    $notes = array_values(array_filter(array_column($magasins, 'note'), static fn ($v) => $v !== null));
    return [
        'cible' => $cible, 'source' => $bo['source'],
        'connecteur' => ['configure' => (bool) $bo['configure'], 'raccordes' => count(array_filter($bo['fiches'], static fn ($f) => $f['raccorde'])), 'derniereSynchro' => $bo['derniere']],
        'suiviDepuis' => $g30['depuis'],
        'reseau' => ['moyenne' => $moyenne, 'avis' => $sommeAvis, 'magasins' => count($magasins), 'notes' => count($notes),
            'gagnes7' => array_sum(array_filter(array_column($magasins, 'gagnes7'), 'is_int')), 'gagnes30' => array_sum(array_filter(array_column($magasins, 'gagnes30'), 'is_int')),
            'scans7' => array_sum($sc7), 'scans30' => array_sum($sc30),
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
        Db::exec('INSERT INTO social_commentaire (id, canal, objet_id, shop_id, post_id, pub_id, auteur, message, cree_le, masque, maj_le, compte, compte_nom, objet_texte, objet_lien, lien, note, vu_le)'
            . ' VALUES (?,?,?,?,NULL,NULL,?,?,?,0,?,?,?,?,?,?,?,NOW())'
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
 * L'historique de la note : une ligne par boutique et par jour (`social_google_suivi`), écrite à chaque lecture de la
 * réputation (la dernière du jour fait foi). C'est lui qui dit combien d'avis une boutique a gagnés : Google ne publie
 * que le total du moment.
 */
function socGoogleSuiviNoter(array $bo): void
{
    $jour = date('Y-m-d');
    foreach ($bo['fiches'] ?? [] as $sid => $f) {
        if ($f['note'] === null && (int) $f['avis'] === 0) { continue; }
        Db::exec('INSERT INTO social_google_suivi (jour, shop_id, note, avis) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE note = VALUES(note), avis = VALUES(avis)',
            [$jour, mb_substr((string) $sid, 0, 16), $f['note'], (int) $f['avis']]);
    }
}

/**
 * Les avis gagnés sur `$jours` : le total d'aujourd'hui moins celui du jour le plus proche avant la période (ou du premier
 * jour suivi, `depuis`, tant que l'historique est plus court). Rend ['shops' => [boutique => n], 'depuis' => ?date].
 */
function socGoogleGagnes(int $jours): array
{
    $depuis = Db::row('SELECT MIN(jour) j FROM social_google_suivi')['j'] ?? null;
    if ($depuis === null) { return ['shops' => [], 'depuis' => null]; }
    $debut = date('Y-m-d', strtotime('-' . $jours . ' days'));
    $out = [];
    foreach (Db::rows('SELECT s.shop_id, s.avis FROM social_google_suivi s JOIN (SELECT shop_id, MAX(jour) j FROM social_google_suivi GROUP BY shop_id) d'
        . ' ON d.shop_id = s.shop_id AND d.j = s.jour') as $r) {
        $avant = Db::row('SELECT avis FROM social_google_suivi WHERE shop_id = ? AND jour <= ? ORDER BY jour DESC LIMIT 1', [$r['shop_id'], $debut])
            ?? Db::row('SELECT avis FROM social_google_suivi WHERE shop_id = ? ORDER BY jour ASC LIMIT 1', [$r['shop_id']]);
        $out[(string) $r['shop_id']] = max(0, (int) $r['avis'] - (int) ($avant['avis'] ?? $r['avis']));
    }
    return ['shops' => $out, 'depuis' => (string) $depuis > $debut ? (string) $depuis : null];
}

/** Les scans du lien d'avis (QR, affiche, lien partagé) sur `$jours`, par boutique. */
function socGoogleScans(int $jours): array
{
    $out = [];
    foreach (Db::rows('SELECT shop_id, COUNT(*) n FROM social_avis_scan WHERE le >= ? GROUP BY shop_id', [date('Y-m-d H:i:s', strtotime('-' . $jours . ' days'))]) as $r) {
        $out[(string) $r['shop_id']] = (int) $r['n'];
    }
    return $out;
}

/** Le lien qui ouvre directement la fenêtre « Donner un avis » de la fiche Google. */
function socGoogleLienAvis(string $placeId): ?string
{
    return preg_match('/^[\w-]{3,200}$/', $placeId) ? 'https://search.google.com/local/writereview?placeid=' . rawurlencode($placeId) : null;
}

/** Le lien court du module (`/avis/{boutique}`) : il compte le scan, puis renvoie vers la fiche Google. */
function socGoogleLienCourt(string $shop): string
{
    $base = rtrim((string) (socSetting('url_publique') ?? ''), '/');
    if ($base === '' && isset($_SERVER['HTTP_HOST'])) { $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/social'; }
    return $base . '/api/v1/social/avis/' . rawurlencode($shop);
}

/**
 * GET /avis/{boutique}?s=qr|affiche|lien|ticket — public (un client qui scanne n'a pas de session) : note le scan,
 * puis renvoie vers la fenêtre d'avis de la fiche. Rien d'autre n'est lu ni écrit.
 */
function socGoogleAvisRedirige(string $shop, string $source): SocRedirection
{
    $bo = socGoogleBo();
    $f = $bo['fiches'][$shop] ?? null;
    $lien = $f ? socGoogleLienAvis((string) ($f['placeId'] ?? '')) : null;
    if ($lien === null) { throw new HttpError(404, 'Pas de fiche Google pour cette boutique.'); }
    Db::exec('INSERT INTO social_avis_scan (shop_id, source, le) VALUES (?,?,?)',
        [mb_substr($shop, 0, 16), in_array($source, ['qr', 'affiche', 'lien', 'ticket', 'webshop'], true) ? $source : 'lien', date('Y-m-d H:i:s')]);
    return new SocRedirection($lien);
}

/** La synchronisation automatique chez Google (une fois par jour, à partir de `heure`) : activée par défaut. */
function socGoogleReglages(): array
{
    $r = socSetting('google_synchro_auto');
    $r = is_array($r) ? $r : [];
    return ['actif' => (bool) ($r['actif'] ?? true), 'heure' => max(0, min(23, (int) ($r['heure'] ?? 6))), 'dernier' => socSetting('google_synchro_auto_dernier')];
}

function socGoogleReglagesPut(array $b): array
{
    socSettingPut('google_synchro_auto', ['actif' => !empty($b['actif']), 'heure' => max(0, min(23, (int) ($b['heure'] ?? 6)))], false);
    socLog(socUser(), 'Avis Google', 'Synchronisation automatique ' . (!empty($b['actif']) ? 'activée, chaque jour à ' . (int) ($b['heure'] ?? 6) . ' h' : 'désactivée'));
    return socGoogleReglages();
}

/**
 * Le cron horaire : une synchronisation chez Google par jour, à partir de l'heure choisie, sauf si le BO a déjà
 * synchronisé aujourd'hui (un clic dans le BO ou ici). Rend null quand il n'y a rien à faire.
 */
function socGoogleSynchroAuto(): ?array
{
    $r = socGoogleReglages();
    $jour = date('Y-m-d');
    if (!$r['actif'] || (int) date('G') < $r['heure'] || (is_array($r['dernier']) && ($r['dernier']['jour'] ?? '') === $jour)) { return null; }
    $bo = socGoogleBo();
    if (empty($bo['indispo']) && substr((string) ($bo['derniere'] ?? ''), 0, 10) === $jour) {
        socSettingPut('google_synchro_auto_dernier', ['jour' => $jour, 'le' => date('Y-m-d H:i'), 'ok' => true, 'message' => 'déjà synchronisé aujourd’hui'], false);
        return null;
    }
    try {
        $s = socGoogleSynchroniser('Synchronisation automatique');
        $res = ['jour' => $jour, 'le' => date('Y-m-d H:i'), 'ok' => true, 'message' => $s['magasins'] . ' fiche(s), ' . $s['nouveaux'] . ' nouvel(s) avis' . ($s['erreurs'] ? ' — ' . implode(' · ', $s['erreurs']) : '')];
    } catch (HttpError $e) {
        $res = ['jour' => $jour, 'le' => date('Y-m-d H:i'), 'ok' => false, 'message' => $e->getMessage()];
    }
    socSettingPut('google_synchro_auto_dernier', $res, false);
    return $res;
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
function socGoogleSynchroniser(?string $qui = null): array
{
    [$code, $j] = BoCockpit::requete('POST', '/reputation/sync');
    if ($code === 401) { throw new HttpError(422, 'Le cockpit du BO demande une connexion : reconnectez-vous au BO, puis réessayez.'); }
    if ($code < 200 || $code >= 300 || !empty($j['error'])) {
        throw new HttpError($code === 422 ? 422 : 502, 'Synchronisation Google : ' . ((string) ($j['error'] ?? '') ?: 'HTTP ' . $code));
    }
    socLog($qui ?? socUser(), 'Avis Google', 'Synchronisation des fiches Google (BO) : ' . (int) ($j['magasins'] ?? 0) . ' fiche(s), ' . (int) ($j['nouveaux'] ?? 0) . ' nouvel(s) avis');
    return ['magasins' => (int) ($j['magasins'] ?? 0), 'nouveaux' => (int) ($j['nouveaux'] ?? 0), 'erreurs' => array_values(array_map('strval', (array) ($j['erreurs'] ?? []))),
        'aucuneFiche' => !empty($j['aucuneFiche'])];
}

/** L'écran : la réputation, plus les avis classés de la période (`jours`, un an par défaut). */
function socGoogleEcran(array $q, ?string $shop = null): array
{
    $l = socCommentairesListe(['source' => 'google', 'jours' => $q['jours'] ?? 365, 'couleur' => $q['couleur'] ?? '', 'nonTraites' => $q['nonTraites'] ?? '',
        'shop' => $shop ?? ($q['shop'] ?? '')]);
    return ['reputation' => socGoogleReputation($shop), 'jours' => $l['jours'], 'avis' => $l['commentaires'], 'totaux' => $l['totaux'],
        'relu' => socSetting('google_avis_relus'), 'ia' => $l['ia'], 'reglages' => socGoogleReglages()];
}
