<?php
declare(strict_types=1);

/**
 * Le repost automatique (écran « Repost automatique », public/SA Repost.dc.html) : chaque
 * nouveau post de la Page Facebook de la MARQUE est repris sur les Pages des boutiques.
 *
 * - Le guet (bin/cron.php repost, toutes les 5 minutes, ou « Relire maintenant ») lit les
 *   publications de la Page de la marque depuis l'activation (jamais l'historique) et range
 *   chacune dans `social_repost` : reprise, ou exclue (mot interdit, partage d'une autre
 *   publication, ni texte ni visuel).
 * - Une publication reprise devient un post du module (`rp-…`, type « Repost ») : il passe par la
 *   même file que les autres (`social_publication`), le même calendrier, les mêmes échecs et la
 *   même relance. Facebook : « partager » publie sur la Page de la boutique un lien vers le post
 *   de la marque ; « copier » publie le même visuel et le même texte. Une vidéo ou un lien ne se
 *   copient pas : ils sont partagés. Instagram n'a pas de repost : copie sur le compte de la
 *   boutique, seulement si les boutiques ont le leur (jamais en Instagram unique : le compte du
 *   réseau EST celui de la marque).
 * - Boutique par boutique (`social_repost_cible`) : automatique, ou le franchisé valide dans son
 *   espace (Calendrier). Sans réponse dans le délai : publié quand même, ou rien (réglage).
 *   Le réseau peut relancer un échec, reposter malgré un refus ou une exclusion, retirer un
 *   repost des Pages des boutiques.
 *
 * Réglage : `social_parametre.repost` ; dernier passage du guet : `repost_guet`.
 */

const SOC_REPOST_FENETRE_J = 3;   // une publication plus ancienne n'est jamais reprise (guet arrêté, puis relancé)
const SOC_REPOST_ECART_S = 60;    // deux lectures de la Page de la marque au plus par minute
const SOC_REPOST_CHAMPS = 'id,message,created_time,permalink_url,full_picture,status_type,'
    . 'attachments{media_type,media{image{src}},subattachments.limit(10){media_type,media{image{src}}}}';
/** États d'une boutique pour une publication de la marque. Les trois derniers publient. */
const SOC_REPOST_PUBLIENT = ['auto', 'valide', 'sans_reponse', 'force'];

/* --- réglage ------------------------------------------------------------------------------ */

function socRepostDefaut(): array
{
    return ['actif' => false, 'depuis' => null, 'mode' => 'partage', 'delaiMin' => 30, 'de' => '07:00', 'a' => '21:00',
        'exclure' => ['recrute', 'franchise', 'concours'], 'boutiques' => [], 'delaiValidationH' => 4, 'sansReponse' => 'publier',
        'texte' => '', 'instagram' => false];
}

/**
 * Le réglage, complété : chaque boutique a sa ligne (reprise ou non, validation ou non). Tant que
 * rien n'est réglé, toutes les boutiques du réseau reprennent, sans validation ; un magasin test,
 * seulement s'il est coché.
 */
function socRepostConfig(): array
{
    $c = socSetting('repost');
    $c = array_replace(socRepostDefaut(), is_array($c) ? $c : []);
    $b = [];
    foreach (socShops() as $s) {
        $x = (array) ($c['boutiques'][$s['id']] ?? []);
        $b[(string) $s['id']] = ['on' => array_key_exists('on', $x) ? (bool) $x['on'] : empty($s['test']), 'validation' => !empty($x['validation'])];
    }
    $c['boutiques'] = $b;
    $c['instagram'] = !empty($c['instagram']) && !socIgUnique();
    return $c;
}

function socRepostConfigPut(array $in): array
{
    $c = socRepostConfig();
    $avant = $c;
    if (array_key_exists('actif', $in)) { $c['actif'] = (bool) $in['actif']; }
    if (isset($in['mode'])) {
        if (!in_array($in['mode'], ['partage', 'copie'], true)) { throw new HttpError(400, 'mode : « partage » ou « copie »'); }
        $c['mode'] = $in['mode'];
    }
    if (isset($in['delaiMin'])) { $c['delaiMin'] = max(0, min(1440, (int) $in['delaiMin'])); }
    foreach (['de', 'a'] as $k) {
        if (!isset($in[$k])) { continue; }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in[$k])) { throw new HttpError(400, 'heure invalide : ' . $in[$k] . ' (HH:MM)'); }
        $c[$k] = (string) $in[$k];
    }
    if ($c['de'] >= $c['a']) { throw new HttpError(400, 'la plage horaire doit commencer avant de finir (' . $c['de'] . ' → ' . $c['a'] . ')'); }
    if (isset($in['exclure'])) {
        $mots = is_array($in['exclure']) ? $in['exclure'] : explode(',', (string) $in['exclure']);
        $c['exclure'] = array_slice(array_values(array_unique(array_filter(array_map(static fn ($m) => mb_substr(trim((string) $m), 0, 40), $mots), static fn ($m) => $m !== ''))), 0, 30);
    }
    if (isset($in['boutiques']) && is_array($in['boutiques'])) {
        foreach ($in['boutiques'] as $sid => $x) {
            if (!isset($c['boutiques'][(string) $sid]) || !is_array($x)) { continue; }
            if (array_key_exists('on', $x)) { $c['boutiques'][(string) $sid]['on'] = (bool) $x['on']; }
            if (array_key_exists('validation', $x)) { $c['boutiques'][(string) $sid]['validation'] = (bool) $x['validation']; }
        }
    }
    if (isset($in['delaiValidationH'])) { $c['delaiValidationH'] = max(1, min(72, (int) $in['delaiValidationH'])); }
    if (isset($in['sansReponse'])) {
        if (!in_array($in['sansReponse'], ['publier', 'rien'], true)) { throw new HttpError(400, 'sans réponse : « publier » ou « rien »'); }
        $c['sansReponse'] = $in['sansReponse'];
    }
    if (array_key_exists('texte', $in)) { $c['texte'] = mb_substr(trim((string) $in['texte']), 0, 500); }
    if (array_key_exists('instagram', $in)) {
        if (!empty($in['instagram']) && socIgUnique()) { throw new HttpError(409, 'Instagram unique au réseau : le compte du réseau est celui de la marque, il n’y a rien à reposter.'); }
        $c['instagram'] = (bool) $in['instagram'];
    }
    // Activé : seules les publications faites à partir de maintenant sont reprises, jamais l'historique de la Page.
    if ($c['actif'] && (!$avant['actif'] || empty($c['depuis']))) { $c['depuis'] = date('Y-m-d H:i:s'); }
    socSettingPut('repost', $c, false);
    $ch = [];
    if ($c['actif'] !== $avant['actif']) { $ch[] = $c['actif'] ? 'activé' : 'mis en pause'; }
    foreach (['mode' => 'mode', 'delaiMin' => 'délai', 'de' => 'début de plage', 'a' => 'fin de plage', 'delaiValidationH' => 'délai de validation', 'sansReponse' => 'sans réponse', 'texte' => 'texte de la boutique', 'instagram' => 'Instagram', 'exclure' => 'mots exclus'] as $k => $nom) {
        if ($c[$k] !== $avant[$k]) { $ch[] = $nom; }
    }
    $noms = array_column(socShops(), 'name', 'id');
    foreach ($c['boutiques'] as $sid => $x) {
        $a = $avant['boutiques'][$sid] ?? null;
        if ($a === null || $a === $x) { continue; }
        $ch[] = ($noms[$sid] ?? $sid) . ' : ' . (!$x['on'] ? 'ne reprend plus' : ($x['validation'] ? 'avec validation' : 'automatique'));
    }
    if ($ch) { socLog(socUser(), 'Repost', 'Réglage du repost automatique : ' . implode(', ', $ch)); }
    return $c;
}

/** L'heure d'un repost ramenée dans la plage du réglage : avant, au début de la plage ; après, le lendemain. */
function socRepostCreneau(int $ts, array $c): int
{
    $j = date('Y-m-d', $ts);
    $h = date('H:i', $ts);
    if ($h < $c['de']) { return (int) strtotime($j . ' ' . $c['de'] . ':00'); }
    if ($h >= $c['a']) { return (int) strtotime(date('Y-m-d', (int) strtotime($j . ' +1 day')) . ' ' . $c['de'] . ':00'); }
    return $ts;
}

/* --- la publication de la marque ------------------------------------------------------------- */

/** Pourquoi une publication de la marque n'est pas reprise (null : elle l'est). */
function socRepostExclu(array $x, array $c): ?string
{
    if (str_contains((string) ($x['status_type'] ?? ''), 'shared_story')) { return 'la marque y partage une autre publication'; }
    $texte = (string) ($x['message'] ?? '');
    if (trim($texte) === '' && (string) ($x['full_picture'] ?? '') === '') { return 'ni texte ni visuel'; }
    $n = ' ' . socNorm($texte) . ' ';
    foreach ($c['exclure'] as $mot) {
        $m = socNorm((string) $mot);
        if ($m !== '' && str_contains($n, ' ' . $m)) { return 'contient « ' . $mot . ' »'; }
    }
    return null;
}

/** Le type d'une publication de la marque et ses photos : [type, images[], copiable]. Une vidéo ou un lien ne se copient pas. */
function socRepostMedias(array $x): array
{
    $att = (array) ($x['attachments']['data'][0] ?? []);
    $mt = strtoupper((string) ($att['media_type'] ?? ''));
    $imgs = [];
    foreach ((array) ($att['subattachments']['data'] ?? []) as $s) {
        $u = (string) ($s['media']['image']['src'] ?? '');
        if ($u !== '' && strtoupper((string) ($s['media_type'] ?? 'PHOTO')) === 'PHOTO') { $imgs[] = $u; }
    }
    if (!$imgs) {
        $u = (string) (($att['media']['image']['src'] ?? '') ?: ($x['full_picture'] ?? ''));
        if ($u !== '') { $imgs[] = $u; }
    }
    $video = $mt === 'VIDEO' || str_contains((string) ($x['status_type'] ?? ''), 'video');
    $lien = in_array($mt, ['LINK', 'SHARE'], true);
    $type = match (true) {
        $video => 'Vidéo', $lien => 'Lien', count($imgs) > 1 => 'Album · ' . count($imgs) . ' photos', (bool) $imgs => 'Photo', default => 'Texte',
    };
    return [$type, array_slice($imgs, 0, 10), !$video && !$lien];
}

/** Les publications de la Page de la marque depuis un instant (published_posts, sinon posts). */
function socRepostLireMarque(string $page, string $tok, int $depuis): array
{
    $q = ['fields' => SOC_REPOST_CHAMPS, 'since' => $depuis, 'limit' => 50];
    $premiere = null;
    foreach (['published_posts', 'posts'] as $edge) {
        try { return socGraphTout($page . '/' . $edge, $q, 100, $tok); }
        catch (MetaError $e) {
            $premiere ??= $e;
            if (!in_array($e->metaCode, [10, 100, 200], true)) { throw $e; }
        }
    }
    throw $premiere ?? new MetaError('publications de la marque illisibles', 0);
}

/** L'identifiant du post du module qui porte le repost d'une publication de la marque. */
function socRepostPostId(string $src): string
{
    return 'rp-' . substr((string) preg_replace('/[^\w.\-]/', '', $src), 0, 61);
}

function socRepostLigne(string $src): ?array
{
    $r = Db::row('SELECT * FROM social_repost WHERE source_id = ?', [$src]);
    if ($r === null) { return null; }
    $r['data'] = json_decode((string) $r['data'], true) ?: [];
    return $r;
}

/* --- le guet ----------------------------------------------------------------------------------- */

/**
 * Relit la Page de la marque et reprend ce qui est nouveau, puis traite les validations échues.
 * Rend le compte rendu du passage (gardé dans `repost_guet`).
 */
function socRepostGuet(bool $forcer = false): array
{
    $c = socRepostConfig();
    $echues = socRepostEcheances();
    $avant = (array) (socSetting('repost_guet') ?? []);
    if (!$c['actif']) { return ['pause' => true, 'echues' => $echues] + $avant; }
    if (!$forcer && !empty($avant['le']) && time() - (int) strtotime((string) $avant['le']) < SOC_REPOST_ECART_S) { return $avant + ['echues' => $echues]; }
    $r = ['le' => date('Y-m-d H:i:s'), 'lus' => 0, 'nouveaux' => 0, 'exclus' => 0, 'echues' => $echues, 'erreur' => null];
    try {
        if (!Meta::configured()) { throw new MetaError('token Meta absent : à saisir dans Paramètres → API Meta → Clés d’accès Meta', 190); }
        $page = (string) (socBrand()['ids']['page_id'] ?? '');
        if ($page === '') { throw new MetaError('la Page de la marque n’est pas renseignée (Paramètres → API Meta → Identifiants Meta, ligne « marque »)', 100); }
        $depuis = max((int) strtotime((string) ($c['depuis'] ?? 'now')), time() - SOC_REPOST_FENETRE_J * 86400);
        $posts = socRepostLireMarque($page, Meta::pageToken($page), $depuis);
        $r['lus'] = count($posts);
        // Du plus ancien au plus récent : les boutiques reprennent dans l'ordre où la marque a publié.
        usort($posts, static fn ($a, $b) => strcmp((string) ($a['created_time'] ?? ''), (string) ($b['created_time'] ?? '')));
        foreach ($posts as $x) {
            if ((string) ($x['id'] ?? '') === '' || ((int) strtotime((string) ($x['created_time'] ?? '')) ?: 0) < $depuis) { continue; }
            $etat = socRepostNouveau($x, $c);
            if ($etat !== null) { $r['nouveaux']++; if ($etat === 'exclu') { $r['exclus']++; } }
        }
    } catch (MetaError $e) {
        $r['erreur'] = $e->getMessage();
    }
    socSettingPut('repost_guet', $r, false);
    return $r;
}

/**
 * Une publication de la marque vue pour la première fois : rangée (reprise ou exclue), puis
 * proposée aux boutiques. Rend 'reposte' ou 'exclu', ou null si elle était déjà connue.
 */
function socRepostNouveau(array $x, array $c): ?string
{
    $src = (string) $x['id'];
    [$type, $imgs, $copiable] = socRepostMedias($x);
    $publie = date('Y-m-d H:i:s', (int) strtotime((string) ($x['created_time'] ?? 'now')));
    $motif = socRepostExclu($x, $c);
    $mode = $c['mode'] === 'copie' && $copiable ? 'copie' : 'partage';
    // Le réglage du moment est figé avec la publication : une validation tardive suit ce qui a été proposé.
    $data = ['images' => $imgs, 'delaiMin' => $c['delaiMin'], 'texte' => $c['texte'], 'sansReponse' => $c['sansReponse'],
        'instagram' => $c['instagram'], 'modeDemande' => $c['mode']];
    $n = Db::exec('INSERT IGNORE INTO social_repost (source_id, publie_le, type, texte, image, permalink, statut, motif, mode, content_id, vu_le, maj_le, data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$src, $publie, $type, (string) ($x['message'] ?? ''), mb_substr((string) (($imgs[0] ?? '') ?: ($x['full_picture'] ?? '')), 0, 1000) ?: null,
            mb_substr((string) ($x['permalink_url'] ?? ''), 0, 500) ?: null, $motif === null ? 'reposte' : 'exclu', $motif, $mode, socRepostPostId($src),
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    if ($n === 0) { return null; }
    $titre = socCalTitre((string) ($x['message'] ?? ''), $type . ' de la marque');
    if ($motif !== null) {
        socLog('Système', 'Repost', '« ' . $titre . ' » : pas repris (' . $motif . ')');
        return 'exclu';
    }
    socRepostCibler($src, $c);
    $rp = socRepostLigne($src);
    $nb = (int) Db::row('SELECT COUNT(*) n FROM social_repost_cible WHERE source_id = ?', [$src])['n'];
    $av = (int) Db::row("SELECT COUNT(*) n FROM social_repost_cible WHERE source_id = ? AND etat = 'a_valider'", [$src])['n'];
    socLog('Système', 'Repost', '« ' . $titre . ' » repris sur ' . $nb . ' Page(s)' . ($av ? ', dont ' . $av . ' à valider par le franchisé' : '')
        . ($rp !== null && $rp['mode'] !== $c['mode'] ? ' (partagé : ' . mb_strtolower($type) . ' impossible à copier)' : ''));
    socRepostPoster($src);
    return 'reposte';
}

/** Propose une publication reprise à chaque boutique cochée : automatique, ou à valider par le franchisé. */
function socRepostCibler(string $src, array $c, ?array $seules = null): void
{
    $rp = socRepostLigne($src);
    if ($rp === null) { return; }
    $slot = date('Y-m-d H:i:s', socRepostCreneau((int) strtotime((string) $rp['publie_le']) + 60 * (int) ($rp['data']['delaiMin'] ?? $c['delaiMin']), $c));
    $now = date('Y-m-d H:i:s');
    $limite = date('Y-m-d H:i:s', time() + 3600 * (int) $c['delaiValidationH']);
    foreach (socShops() as $s) {
        $sid = (string) $s['id'];
        $b = $c['boutiques'][$sid] ?? ['on' => false, 'validation' => false];
        if ($seules !== null ? !in_array($sid, $seules, true) : !$b['on']) { continue; }
        $valider = $b['validation'] && $seules === null;
        Db::exec('INSERT IGNORE INTO social_repost_cible (source_id, shop_id, etat, propose_le, limite_le, prevue_le) VALUES (?,?,?,?,?,?)',
            [$src, $sid, $valider ? 'a_valider' : 'auto', $now, $valider ? $limite : null, $valider ? null : max($slot, $now)]);
    }
}

/**
 * (Re)fait le post du module d'une publication reprise : ses boutiques sont celles qui publient
 * (automatique, validé, sans réponse, forcé) ; chaque nouvelle boutique entre dans la file à son
 * heure. Les lignes déjà dans la file (publiées, en échec) ne bougent pas.
 */
function socRepostPoster(string $src): ?array
{
    $rp = socRepostLigne($src);
    if ($rp === null || $rp['statut'] !== 'reposte') { return null; }
    $cibles = Db::rows('SELECT * FROM social_repost_cible WHERE source_id = ? AND etat IN (' . implode(',', array_fill(0, count(SOC_REPOST_PUBLIENT), '?')) . ') ORDER BY prevue_le, shop_id',
        array_merge([$src], SOC_REPOST_PUBLIENT));
    if (!$cibles) { return null; }
    $d = $rp['data'];
    $parId = [];
    foreach (socShops() as $s) { $parId[(string) $s['id']] = $s; }
    $ig = !empty($d['instagram']) && !socIgUnique();
    $ch = [];
    foreach ($cibles as $cb) {
        $sid = (string) $cb['shop_id'];
        $ch[$sid] = ['fb' => true, 'ig' => $ig && (string) ($parId[$sid]['ids']['instagram_user_id'] ?? '') !== ''];
    }
    $t0 = min(array_map(static fn ($cb) => (int) strtotime((string) ($cb['prevue_le'] ?: 'now')), $cibles));
    $id = (string) $rp['content_id'];
    $avant = socItem('posts', $id);
    $texteMarque = (string) $rp['texte'];
    $texteB = trim((string) ($d['texte'] ?? ''));
    $imgs = array_values(array_filter((array) ($d['images'] ?? []), 'is_string'));
    $nouvelles = false;
    foreach ($cibles as $cb) {
        foreach ($ch[(string) $cb['shop_id']] as $canal => $oui) {
            if (!$oui) { continue; }
            $nouvelles = Db::exec('INSERT IGNORE INTO social_publication (content_id, shop_id, canal, prevue_le, statut, maj_le) VALUES (?,?,?,?,?,?)',
                [$id, (string) $cb['shop_id'], $canal, (string) ($cb['prevue_le'] ?: date('Y-m-d H:i:s')), 'attente', date('Y-m-d H:i:s')]) > 0 || $nouvelles;
        }
    }
    $p = array_replace($avant ?? [], [
        'id' => $id, 'origin' => 'repost', 'type' => 'Repost', 'author' => 'Repost automatique',
        'title' => 'Repost · ' . socCalTitre($texteMarque, (string) $rp['type'] . ' de la marque'),
        'status' => $nouvelles || $avant === null ? 'Programmé' : (string) ($avant['status'] ?? 'Programmé'),
        'date' => date('Y-m-d', $t0), 'time' => date('H:i', $t0),
        'shops' => array_map('strval', array_keys($ch)), 'ch' => $ch, 'fb' => true, 'ig' => $ig && in_array(true, array_column($ch, 'ig'), true),
        // Le texte d'une copie (Facebook en copie, Instagram) : celui de la marque, puis celui de la boutique.
        'text' => trim($texteMarque . ($texteB !== '' ? "\n\n" . $texteB : '')),
        'img' => $imgs[0] ?? null, 'media' => array_map(static fn ($u) => ['kind' => 'img', 'src' => $u], $imgs),
        'repost' => ['source' => $src, 'permalink' => (string) $rp['permalink'], 'mode' => (string) $rp['mode'], 'texte' => $texteB, 'type' => (string) $rp['type']],
    ]);
    socItemPut('posts', $p);
    // Ce qui est déjà dû part tout de suite (une validation, une relance) ; le reste attend la file.
    if ((int) Db::row("SELECT COUNT(*) n FROM social_publication WHERE content_id = ? AND statut = 'attente' AND prevue_le <= ?", [$id, date('Y-m-d H:i:s')])['n'] > 0) {
        socRunQueue($id);
    }
    return socItem('posts', $id);
}

/** Publie sur la Page d'une boutique un lien vers le post de la marque, avec le texte de la boutique au-dessus. */
function socRepostPartager(array $post, array $shop, string $pid, string $tok): string
{
    $lien = (string) ($post['repost']['permalink'] ?? '');
    if ($lien === '') { throw new MetaError('le lien du post de la marque manque : il ne peut pas être partagé', 100); }
    $msg = trim(socFill((string) ($post['repost']['texte'] ?? ''), $shop, null, (string) (($shop['langs'] ?? ['fr'])[0] ?? 'fr')));
    $r = Meta::call('POST', $pid . '/feed', array_filter(['message' => $msg !== '' ? $msg : null, 'link' => $lien]), $tok);
    return (string) ($r['id'] ?? '');
}

/** Les validations échues : publiées quand même ou abandonnées, selon le réglage du moment. Rend leur nombre. */
function socRepostEcheances(): int
{
    $c = socRepostConfig();
    $touches = [];
    $n = 0;
    foreach (Db::rows("SELECT k.*, r.data FROM social_repost_cible k JOIN social_repost r ON r.source_id = k.source_id WHERE k.etat = 'a_valider' AND k.limite_le <= ?", [date('Y-m-d H:i:s')]) as $cb) {
        $d = json_decode((string) $cb['data'], true) ?: [];
        $publier = (string) ($d['sansReponse'] ?? $c['sansReponse']) === 'publier';
        $ok = Db::exec("UPDATE social_repost_cible SET etat = ?, repondu_le = ?, prevue_le = ? WHERE source_id = ? AND shop_id = ? AND etat = 'a_valider'",
            [$publier ? 'sans_reponse' : 'expire', date('Y-m-d H:i:s'), $publier ? date('Y-m-d H:i:s', socRepostCreneau(time(), $c)) : null, $cb['source_id'], $cb['shop_id']]);
        if ($ok === 0) { continue; }
        $n++;
        if ($publier) { $touches[(string) $cb['source_id']] = true; }
    }
    foreach (array_keys($touches) as $src) { socRepostPoster($src); }
    return $n;
}

/* --- les gestes du réseau et du franchisé ---------------------------------------------------- */

/** Le franchisé publie ou refuse un repost proposé à sa boutique. */
function socRepostRepondre(string $shop, string $src, bool $accepter, string $motif, string $par): array
{
    $cb = Db::row('SELECT * FROM social_repost_cible WHERE source_id = ? AND shop_id = ?', [$src, $shop]);
    $rp = socRepostLigne($src);
    if ($cb === null || $rp === null) { throw new HttpError(404, 'publication introuvable pour cette boutique'); }
    if ($cb['etat'] !== 'a_valider') { throw new HttpError(409, 'déjà traité : ' . socRepostEtatNom((string) $cb['etat'])); }
    if ($rp['statut'] !== 'reposte') { throw new HttpError(409, 'le réseau a retiré cette publication'); }
    $c = socRepostConfig();
    $titre = socCalTitre((string) $rp['texte'], (string) $rp['type'] . ' de la marque');
    $nom = (string) (socShop($shop)['name'] ?? $shop);
    if ($accepter) {
        $slot = socRepostCreneau(max(time(), (int) strtotime((string) $rp['publie_le']) + 60 * (int) ($rp['data']['delaiMin'] ?? $c['delaiMin'])), $c);
        Db::exec("UPDATE social_repost_cible SET etat = 'valide', repondu_le = ?, par = ?, prevue_le = ?, motif = NULL WHERE source_id = ? AND shop_id = ? AND etat = 'a_valider'",
            [date('Y-m-d H:i:s'), mb_substr($par, 0, 120), date('Y-m-d H:i:s', $slot), $src, $shop]);
        socLog($par, 'Repost', $nom . ' publie « ' . $titre . ' » sur sa Page');
        socRepostPoster($src);
    } else {
        Db::exec("UPDATE social_repost_cible SET etat = 'refuse', repondu_le = ?, par = ?, motif = ? WHERE source_id = ? AND shop_id = ? AND etat = 'a_valider'",
            [date('Y-m-d H:i:s'), mb_substr($par, 0, 120), mb_substr(trim($motif), 0, 500) ?: null, $src, $shop]);
        socLog($par, 'Repost', $nom . ' refuse « ' . $titre . ' »' . (trim($motif) !== '' ? ' : ' . trim($motif) : ''));
    }
    return socRepostVueFranchise($shop);
}

/** Relance les publications en échec : d'une publication reprise (une boutique, ou toutes), ou de toutes. Rend le nombre relancé. */
function socRepostRelancer(?string $src, ?string $shop): int
{
    $ids = $src !== null ? [socRepostPostId($src)]
        : array_column(Db::rows("SELECT DISTINCT p.content_id FROM social_publication p JOIN social_repost r ON r.content_id = p.content_id WHERE p.statut = 'echec'"), 'content_id');
    $n = 0;
    foreach ($ids as $id) {
        $n += Db::exec("UPDATE social_publication SET statut = 'attente', prevue_le = ?, maj_le = ? WHERE content_id = ? AND statut = 'echec'" . ($shop !== null ? ' AND shop_id = ?' : ''),
            array_merge([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id], $shop !== null ? [$shop] : []));
        socRunQueue((string) $id);
    }
    if ($n) { socLog(socUser(), 'Repost', $n . ' repost(s) en échec relancé(s)'); }
    return $n;
}

/**
 * Reposter malgré tout : une publication exclue (proposée aux boutiques comme si elle venait
 * d'arriver), ou une boutique qui a refusé, laissé passer le délai, ou n'était pas visée.
 */
function socRepostForcer(string $src, ?string $shop): void
{
    $rp = socRepostLigne($src);
    if ($rp === null) { throw new HttpError(404, 'publication de la marque inconnue'); }
    $c = socRepostConfig();
    if ($rp['statut'] === 'exclu') {
        Db::exec("UPDATE social_repost SET statut = 'reposte', motif = NULL, maj_le = ? WHERE source_id = ?", [date('Y-m-d H:i:s'), $src]);
        socRepostCibler($src, $c, $shop !== null ? [$shop] : null);
    } elseif ($rp['statut'] === 'reposte' && $shop !== null) {
        if (socShop($shop) === null) { throw new HttpError(404, 'boutique inconnue'); }
        $prevue = date('Y-m-d H:i:s', socRepostCreneau(time(), $c));
        $deja = Db::row('SELECT etat FROM social_repost_cible WHERE source_id = ? AND shop_id = ?', [$src, $shop]);
        if ($deja !== null && in_array($deja['etat'], SOC_REPOST_PUBLIENT, true)) { throw new HttpError(409, 'cette boutique reprend déjà la publication'); }
        Db::exec('INSERT INTO social_repost_cible (source_id, shop_id, etat, propose_le, prevue_le, repondu_le, par) VALUES (?,?,?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE etat = VALUES(etat), prevue_le = VALUES(prevue_le), repondu_le = VALUES(repondu_le), par = VALUES(par)',
            [$src, $shop, 'force', date('Y-m-d H:i:s'), $prevue, date('Y-m-d H:i:s'), mb_substr(socUser(), 0, 120)]);
    } else {
        throw new HttpError(409, $rp['statut'] === 'retire' ? 'publication retirée des Pages des boutiques' : 'précisez la boutique');
    }
    socLog(socUser(), 'Repost', '« ' . socCalTitre((string) $rp['texte'], (string) $rp['type'] . ' de la marque') . ' » reposté malgré tout'
        . ($shop !== null ? ' sur ' . (socShop($shop)['name'] ?? $shop) : ''));
    socRepostPoster($src);
}

/** Retire une publication reprise des Pages des boutiques (et de la file) ; les validations en attente tombent. */
function socRepostRetirer(string $src): array
{
    $rp = socRepostLigne($src);
    if ($rp === null) { throw new HttpError(404, 'publication de la marque inconnue'); }
    $id = (string) $rp['content_id'];
    $r = socItem('posts', $id) !== null ? socPostSupprimer($id, true) : ['ok' => true, 'supprimees' => [], 'erreurs' => []];
    if (!$r['ok']) { return $r; }
    Db::exec("UPDATE social_repost SET statut = 'retire', maj_le = ? WHERE source_id = ?", [date('Y-m-d H:i:s'), $src]);
    Db::exec("UPDATE social_repost_cible SET etat = 'retire' WHERE source_id = ?", [$src]);
    socLog(socUser(), 'Repost', '« ' . socCalTitre((string) $rp['texte'], (string) $rp['type'] . ' de la marque') . ' » retiré des Pages des boutiques');
    return $r;
}

/* --- les publications à venir ------------------------------------------------------------------- */

const SOC_REPOST_AVENIR_S = 120;  // les posts programmés de la marque relus au plus toutes les 2 minutes

/**
 * Les posts programmés sur la Page de la marque (Meta Business Suite, ou l'API) : ce qu'elle publiera,
 * et que le guet reprendra à sa sortie. Lus chez Meta et gardés 2 minutes (`repost_avenir`) : chaque
 * geste de l'écran rend la vue entière, il ne relit pas Meta à chaque fois. `$forcer` : « Relire ».
 * Rend ['le', 'posts' => publications telles que Meta les donne, 'erreur'].
 */
function socRepostProgrammes(bool $forcer = false): array
{
    $avant = socSetting('repost_avenir');
    if (!$forcer && is_array($avant) && time() - (int) strtotime((string) ($avant['le'] ?? '')) < SOC_REPOST_AVENIR_S) { return $avant; }
    $r = ['le' => date('Y-m-d H:i:s'), 'posts' => [], 'erreur' => null];
    $page = (string) (socBrand()['ids']['page_id'] ?? '');
    // Sans token ni Page de la marque, le bandeau du guet dit déjà ce qui manque.
    if (!Meta::configured() || $page === '') { return $r; }
    try {
        $tok = Meta::pageToken($page);
        // Les médias d'abord (le type, la copie possible) ; l'essentiel seulement si Meta les refuse.
        $echec = null;
        foreach ([SOC_REPOST_CHAMPS . ',scheduled_publish_time', 'id,message,scheduled_publish_time,full_picture,permalink_url'] as $champs) {
            try { $r['posts'] = socGraphTout($page . '/scheduled_posts', ['fields' => $champs, 'limit' => 100], 200, $tok); $echec = null; break; }
            catch (MetaError $e) {
                $echec = $e;
                if (!in_array($e->metaCode, [10, 100, 200], true)) { throw $e; }
            }
        }
        if ($echec !== null) { throw $echec; }
    } catch (MetaError $e) {
        $r['erreur'] = $e->getMessage();
    }
    socSettingPut('repost_avenir', $r, false);
    return $r;
}

/**
 * Ce que le repost fera des posts programmés de la marque, avec le réglage d'aujourd'hui : exclu (et
 * pourquoi), ou repris — à quelle heure dans chaque boutique automatique, jusqu'à quand le franchisé
 * peut valider dans les autres. Une prévision : le réglage du moment où le post sortira décidera.
 */
function socRepostAVenir(array $c, array $shops, bool $forcer = false): array
{
    $p = socRepostProgrammes($forcer);
    $posts = [];
    foreach ($p['posts'] as $x) {
        $t = (int) ($x['scheduled_publish_time'] ?? 0);
        if ((string) ($x['id'] ?? '') === '' || $t <= time()) { continue; }
        [$type, , $copiable] = socRepostMedias($x);
        $motif = socRepostExclu($x, $c);
        $repost = socRepostCreneau($t + 60 * (int) $c['delaiMin'], $c);
        $cases = [];
        foreach ($shops as $s) {
            $b = $c['boutiques'][$s['id']] ?? ['on' => false, 'validation' => false];
            $cases[$s['id']] = !$b['on'] ? ['statut' => 'hors'] : ($b['validation']
                ? ['statut' => 'val', 'limite' => date('Y-m-d H:i:s', $t + 3600 * (int) $c['delaiValidationH'])]
                : ['statut' => 'prog', 'le' => date('Y-m-d H:i:s', $repost)]);
        }
        $posts[] = ['source' => (string) $x['id'], 'prevuLe' => date('Y-m-d H:i:s', $t), 'type' => $type,
            'titre' => socCalTitre((string) ($x['message'] ?? ''), $type . ' de la marque'), 'texte' => (string) ($x['message'] ?? ''),
            'img' => (string) ($x['full_picture'] ?? '') ?: null, 'exclu' => $motif, 'repostLe' => $motif === null ? date('Y-m-d H:i:s', $repost) : null,
            'mode' => $c['mode'] === 'copie' && $copiable ? 'copie' : 'partage', 'cases' => $cases];
    }
    usort($posts, static fn ($a, $b) => strcmp($a['prevuLe'], $b['prevuLe']));
    return ['le' => $p['le'], 'erreur' => $p['erreur'], 'posts' => $posts];
}

/* --- ce que les écrans lisent ------------------------------------------------------------------- */

function socRepostEtatNom(string $etat): string
{
    return match ($etat) {
        'a_valider' => 'à valider', 'valide' => 'validé', 'refuse' => 'refusé', 'sans_reponse' => 'publié sans réponse', 'expire' => 'sans réponse, rien publié',
        'force' => 'reposté par le réseau', 'retire' => 'retiré', default => 'automatique',
    };
}

/**
 * Une case du tableau : ce que la boutique a fait de la publication de la marque.
 * statut : ok (publié), prog (programmé), val (à valider), ref (refusé), err (échec), exp (sans réponse, rien),
 * excl (publication exclue), ret (retiré), hors (boutique pas visée).
 */
function socRepostCase(array $rp, ?array $cb, array $pubs): array
{
    if ($rp['statut'] === 'exclu') { return ['statut' => 'excl', 'detail' => (string) $rp['motif']]; }
    if ($rp['statut'] === 'retire') { return ['statut' => 'ret', 'detail' => 'retiré des Pages des boutiques']; }
    if ($cb === null) { return ['statut' => 'hors', 'detail' => 'boutique pas visée à ce moment-là']; }
    $etat = (string) $cb['etat'];
    $base = ['etat' => $etat, 'etatNom' => socRepostEtatNom($etat), 'par' => (string) ($cb['par'] ?? ''), 'reponduLe' => $cb['repondu_le'], 'motif' => (string) ($cb['motif'] ?? '')];
    if ($etat === 'a_valider') { return $base + ['statut' => 'val', 'limite' => $cb['limite_le'], 'detail' => 'proposé le ' . substr((string) $cb['propose_le'], 0, 16)]; }
    if ($etat === 'refuse') { return $base + ['statut' => 'ref', 'detail' => (string) ($cb['motif'] ?? '') ?: 'refusé sans motif']; }
    if ($etat === 'expire') { return $base + ['statut' => 'exp', 'detail' => 'sans réponse dans le délai : rien n’a été publié']; }
    if ($etat === 'retire') { return $base + ['statut' => 'ret', 'detail' => 'retiré']; }
    $canaux = [];
    foreach ($pubs as $p) {
        $canaux[] = ['canal' => socCanalNom((string) $p['canal']), 'statut' => (string) $p['statut'], 'prevue' => (string) $p['prevue_le'], 'le' => (string) $p['maj_le'],
            'erreur' => (string) ($p['erreur'] ?? ''), 'code' => (int) ($p['code_erreur'] ?? 0),
            'lien' => $p['statut'] === 'publie' && (string) $p['meta_id'] !== '' && $p['canal'] === 'fb' ? 'https://www.facebook.com/' . rawurlencode((string) $p['meta_id']) : null];
    }
    if (!$canaux) { return $base + ['statut' => 'ret', 'detail' => 'retiré du calendrier', 'canaux' => []]; }
    $st = array_column($canaux, 'statut');
    $err = array_values(array_filter($canaux, static fn ($x) => $x['statut'] === 'echec'));
    if ($err) { return $base + ['statut' => 'err', 'detail' => implode(' · ', array_map(static fn ($x) => $x['canal'] . ' : ' . $x['erreur'], $err)), 'canaux' => $canaux]; }
    if (!array_diff($st, ['publie'])) {
        $le = max(array_column($canaux, 'le'));
        return $base + ['statut' => 'ok', 'le' => $le, 'detail' => socRepostEtatNom($etat), 'canaux' => $canaux, 'lien' => $canaux[0]['lien'] ?? null];
    }
    return $base + ['statut' => 'prog', 'le' => min(array_column(array_filter($canaux, static fn ($x) => $x['statut'] !== 'publie'), 'prevue')), 'detail' => socRepostEtatNom($etat), 'canaux' => $canaux];
}

/**
 * L'écran « Repost automatique » : le réglage, les publications de la marque sur la période et leur sort
 * dans chaque boutique, et ses posts programmés avec le repost prévu. `$relire` : relit les programmés.
 */
function socRepostVue(int $jours = 14, bool $relire = false): array
{
    $jours = in_array($jours, [7, 14, 30, 90], true) ? $jours : 14;
    $c = socRepostConfig();
    $rps = Db::rows('SELECT * FROM social_repost WHERE publie_le >= ? ORDER BY publie_le DESC LIMIT 300', [date('Y-m-d H:i:s', time() - $jours * 86400)]);
    $srcs = array_column($rps, 'source_id');
    $cibles = []; $pubs = [];
    if ($srcs) {
        $in = implode(',', array_fill(0, count($srcs), '?'));
        foreach (Db::rows("SELECT * FROM social_repost_cible WHERE source_id IN ($in)", $srcs) as $cb) { $cibles[(string) $cb['source_id']][(string) $cb['shop_id']] = $cb; }
        $cids = array_column($rps, 'content_id');
        foreach (Db::rows('SELECT * FROM social_publication WHERE content_id IN (' . implode(',', array_fill(0, count($cids), '?')) . ') ORDER BY canal', $cids) as $p) {
            $pubs[(string) $p['content_id']][(string) $p['shop_id']][] = $p;
        }
    }
    // Les colonnes : les boutiques cochées, plus celles qui ont reçu une publication de la période.
    $vues = [];
    foreach ($cibles as $parShop) { foreach (array_keys($parShop) as $sid) { $vues[(string) $sid] = true; } }
    $shops = [];
    foreach (socShops() as $s) {
        $sid = (string) $s['id'];
        if (!($c['boutiques'][$sid]['on'] ?? false) && !isset($vues[$sid])) { continue; }
        $shops[] = ['id' => $sid, 'name' => (string) $s['name'], 'page' => (string) ($s['page'] ?? ''), 'test' => !empty($s['test']), 'fb' => (string) ($s['fb'] ?? 'none'),
            'pageId' => (string) ($s['ids']['page_id'] ?? ''), 'instagram' => (string) ($s['ids']['instagram_user_id'] ?? '') !== '',
            'on' => (bool) ($c['boutiques'][$sid]['on'] ?? false), 'validation' => (bool) ($c['boutiques'][$sid]['validation'] ?? false)];
    }
    $chiffres = ['posts' => count($rps), 'exclus' => 0, 'attendus' => 0, 'publies' => 0, 'programmes' => 0, 'aValider' => 0, 'refuses' => 0, 'echecs' => 0, 'sansReponse' => 0];
    $parShop = []; $alertes = []; $attente = [];
    $lignes = [];
    foreach ($rps as $rp) {
        $rp['data'] = json_decode((string) $rp['data'], true) ?: [];
        $src = (string) $rp['source_id'];
        if ($rp['statut'] === 'exclu') { $chiffres['exclus']++; }
        $cases = [];
        foreach ($shops as $s) {
            $k = socRepostCase($rp, $cibles[$src][$s['id']] ?? null, $pubs[(string) $rp['content_id']][$s['id']] ?? []);
            $cases[$s['id']] = $k;
            if (in_array($k['statut'], ['excl', 'hors', 'ret'], true)) { continue; }
            $chiffres['attendus']++;
            $parShop[$s['id']]['attendus'] = ($parShop[$s['id']]['attendus'] ?? 0) + 1;
            match ($k['statut']) {
                'ok' => $chiffres['publies']++, 'prog' => $chiffres['programmes']++, 'val' => $chiffres['aValider']++, 'ref' => $chiffres['refuses']++,
                'err' => $chiffres['echecs']++, 'exp' => $chiffres['sansReponse']++, default => null,
            };
            if ($k['statut'] === 'ok') { $parShop[$s['id']]['publies'] = ($parShop[$s['id']]['publies'] ?? 0) + 1; }
            if ($k['statut'] === 'err') {
                foreach ((array) ($k['canaux'] ?? []) as $x) {
                    if ($x['statut'] !== 'echec') { continue; }
                    $cle = $s['id'] . '|' . $x['canal'];
                    $alertes[$cle] ??= ['shop' => $s['id'], 'name' => $s['name'], 'canal' => $x['canal'], 'n' => 0, 'motif' => $x['erreur'], 'code' => $x['code'], 'le' => $x['le']];
                    $alertes[$cle]['n']++;
                    if ($x['le'] > $alertes[$cle]['le']) { $alertes[$cle]['motif'] = $x['erreur']; $alertes[$cle]['code'] = $x['code']; $alertes[$cle]['le'] = $x['le']; }
                }
            }
            if ($k['statut'] === 'val') { $attente[] = ['source' => $src, 'shop' => $s['id'], 'name' => $s['name'], 'titre' => socCalTitre((string) $rp['texte'], (string) $rp['type'] . ' de la marque'), 'limite' => $k['limite'] ?? null]; }
        }
        $lignes[] = ['source' => $src, 'publieLe' => (string) $rp['publie_le'], 'type' => (string) $rp['type'], 'titre' => socCalTitre((string) $rp['texte'], (string) $rp['type'] . ' de la marque'),
            'texte' => (string) $rp['texte'], 'img' => $rp['image'], 'permalink' => $rp['permalink'], 'statut' => (string) $rp['statut'], 'motif' => (string) ($rp['motif'] ?? ''),
            'mode' => (string) $rp['mode'], 'modeDemande' => (string) ($rp['data']['modeDemande'] ?? $rp['mode']), 'instagram' => !empty($rp['data']['instagram']), 'cases' => $cases];
    }
    foreach ($shops as &$s) {
        $a = (int) ($parShop[$s['id']]['attendus'] ?? 0);
        $s['repris'] = $a ? (int) round(100 * (int) ($parShop[$s['id']]['publies'] ?? 0) / $a) : null;
    }
    unset($s);
    usort($attente, static fn ($a, $b) => strcmp((string) $a['limite'], (string) $b['limite']));
    $b = socBrand();
    return [
        'reglage' => $c, 'jours' => $jours, 'shops' => $shops, 'lignes' => $lignes, 'chiffres' => $chiffres, 'alertes' => array_values($alertes), 'attente' => $attente,
        'aVenir' => socRepostAVenir($c, $shops, $relire),
        'guet' => socSetting('repost_guet'), 'marque' => ['name' => $b['name'], 'pageId' => (string) ($b['ids']['page_id'] ?? ''), 'fb' => (string) $b['fb']],
        'instagramUnique' => socIgUnique(), 'metaConfigure' => Meta::configured(), 'franchiseUrl' => (string) (socSetting('url_franchise') ?? '/social_franchisee/'),
    ];
}

/** L'espace franchisé : ce que le réseau propose à la boutique (à valider), et ce qu'elle a déjà reçu. */
function socRepostVueFranchise(string $shop): array
{
    $s = socShop($shop);
    if ($s === null) { throw new HttpError(404, 'boutique inconnue'); }
    $vue = static function (array $r) use ($s): array {
        $d = json_decode((string) $r['data'], true) ?: [];
        $txt = trim((string) ($d['texte'] ?? ''));
        return ['source' => (string) $r['source_id'], 'publieLe' => (string) $r['publie_le'], 'type' => (string) $r['type'],
            'titre' => socCalTitre((string) $r['texte'], (string) $r['type'] . ' de la marque'), 'texte' => (string) $r['texte'], 'img' => $r['image'], 'permalink' => $r['permalink'],
            'mode' => (string) $r['mode'], 'texteBoutique' => $txt !== '' ? socFill($txt, $s, null, (string) (($s['langs'] ?? ['fr'])[0] ?? 'fr')) : '',
            'instagram' => !empty($d['instagram']) && (string) ($s['ids']['instagram_user_id'] ?? '') !== '' && !socIgUnique(),
            'etat' => (string) $r['etat'], 'etatNom' => socRepostEtatNom((string) $r['etat']), 'limite' => $r['limite_le'], 'sansReponse' => (string) ($d['sansReponse'] ?? 'publier'),
            'motif' => (string) ($r['motif'] ?? ''), 'reponduLe' => $r['repondu_le'], 'par' => (string) ($r['par'] ?? '')];
    };
    $sql = 'SELECT k.*, r.publie_le, r.type, r.texte, r.image, r.permalink, r.mode, r.data FROM social_repost_cible k JOIN social_repost r ON r.source_id = k.source_id WHERE k.shop_id = ? AND r.statut = ?';
    $attente = array_map($vue, Db::rows($sql . " AND k.etat = 'a_valider' ORDER BY k.limite_le", [$shop, 'reposte']));
    $recents = array_map($vue, Db::rows($sql . " AND k.etat <> 'a_valider' AND k.propose_le >= ? ORDER BY r.publie_le DESC LIMIT 10", [$shop, 'reposte', date('Y-m-d H:i:s', time() - 30 * 86400)]));
    return ['attente' => $attente, 'recents' => $recents, 'marque' => socBrand()['name']];
}
