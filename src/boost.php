<?php
declare(strict_types=1);

/**
 * La publicité pilotée : ce que la marque décide, et ce que la boutique propose.
 *
 *  - Règle de pause (marque, `pub_regles`, désactivée par défaut) : à la relecture horaire des pubs (socAdsSync), une
 *    pub du module active dont le coût par clic dépasse le seuil, une fois une dépense minimale atteinte, est mise en
 *    pause chez Meta. Les seuils sont par devise de compte publicitaire (un compte en złotys n'a pas les seuils d'un
 *    compte en euros). Une pub reprise à la main après une pause automatique n'est plus jamais repausée, et une pub
 *    de notoriété (qui ne vise pas le clic) n'est pas jugée au coût par clic.
 *  - Les meilleurs posts de la semaine (marque) : les publications des 7 derniers jours de chaque Page et de chaque
 *    Instagram, lues chez Meta, classées par interactions (réactions, commentaires, partages) et rapportées à la
 *    médiane du compte ; le meilleur de chaque boutique, prêt à booster (l'assistant de pub s'ouvre prérempli).
 *  - Les demandes de boost (franchisé → marque, `social_boost_demande`) : la boutique propose au réseau de booster une
 *    publication de sa Page ou de son Instagram ; la marque la booste (l'assistant s'ouvre, la demande passe
 *    « boostée » quand la pub est créée chez Meta) ou la refuse avec un mot. La boutique suit ses demandes.
 */

const SOC_BOOST_JOURS = 7;
const SOC_BOOST_CACHE_S = 7200;
const SOC_BOOST_ATTENTE_MAX = 3;

/* --- règle de pause ------------------------------------------------------------- */

function socPubRegles(): array
{
    $r = socSetting('pub_regles');
    $r = array_replace(['pauseActive' => false, 'seuils' => [], 'le' => null, 'par' => null], is_array($r) ? $r : []);
    $r['seuils'] = is_array($r['seuils']) ? $r['seuils'] : [];
    return $r;
}

/** Enregistre la règle : seuils par devise (coût par clic maximum, dépense minimale avant de juger). */
function socPubReglesPut(array $in): array
{
    $seuils = []; $err = [];
    foreach ((array) ($in['seuils'] ?? []) as $dev => $s) {
        $dev = strtoupper((string) $dev);
        if (!preg_match('/^[A-Z]{3}$/', $dev) || !is_array($s)) { continue; }
        $cpc = round((float) ($s['cpcMax'] ?? 0), 2); $min = round((float) ($s['depenseMin'] ?? 0), 2);
        if ($cpc <= 0) { continue; }  // pas de seuil pour cette devise
        if ($min < 1) { $err[] = $dev . ' : la dépense minimale avant de juger doit être d’au moins 1.'; continue; }
        $seuils[$dev] = ['cpcMax' => $cpc, 'depenseMin' => $min];
    }
    $actif = !empty($in['pauseActive']);
    if ($actif && !$seuils && !$err) { $err[] = 'Indiquez un coût par clic maximum pour au moins une devise.'; }
    if ($err) { throw new HttpError(422, implode(' ', $err)); }
    $r = ['pauseActive' => $actif, 'seuils' => $seuils, 'le' => date('Y-m-d H:i'), 'par' => socUser()];
    socSettingPut('pub_regles', $r, false);
    socLog(socUser(), 'Publicité', 'Règle de pause automatique ' . ($actif ? 'activée' : 'désactivée')
        . ($seuils ? ' (' . implode(', ', array_map(static fn ($d, $s) => 'CPC > ' . $s['cpcMax'] . ' ' . $d . ' après ' . $s['depenseMin'] . ' ' . $d, array_keys($seuils), $seuils)) . ')' : ''));
    return $r;
}

/**
 * La règle condamne-t-elle cette pub ? Rend le motif, ou null. Une pub reprise à la main après une pause automatique
 * (`autoPauseLevee`) ne l'est plus ; une campagne récupérée chez Meta non plus (elle se pilote dans le Gestionnaire).
 */
function socPubPauseVerdict(array $ad, float $spent, int $clics, ?array $regles = null): ?array
{
    $regles ??= socPubRegles();
    if (!$regles['pauseActive'] || !empty($ad['autoPauseLevee']) || ($ad['origin'] ?? '') === 'meta') { return null; }
    // Une pub de notoriété (couverture, impressions) ne vise pas le clic : le coût par clic ne la juge pas.
    $obj = (string) (($ad['spec']['objective'] ?? '') ?: ($ad['metaObjective'] ?? ''));
    if (in_array($obj, ['OUTCOME_AWARENESS', 'REACH', 'BRAND_AWARENESS'], true)) { return null; }
    $dev = strtoupper((string) (($ad['devise'] ?? '') ?: 'EUR'));
    $s = $regles['seuils'][$dev] ?? null;
    if (!$s || $spent < (float) $s['depenseMin']) { return null; }
    $cpc = $clics > 0 ? $spent / $clics : null;
    if ($cpc !== null && $cpc <= (float) $s['cpcMax']) { return null; }
    $f = static fn (float $v) => number_format($v, 2, ',', ' ') . ' ' . $dev;
    return ['le' => date('Y-m-d H:i'), 'devise' => $dev, 'depense' => round($spent, 2), 'clics' => $clics, 'cpc' => $cpc !== null ? round($cpc, 2) : null, 'seuil' => (float) $s['cpcMax'],
        'texte' => 'Mise en pause automatiquement : ' . ($cpc === null ? 'aucun clic pour ' . $f($spent) . ' dépensés' : 'coût par clic de ' . $f($cpc) . ' (seuil ' . $f((float) $s['cpcMax']) . ') après ' . $f($spent) . ' dépensés')
            . '. Reprenez-la si vous voulez la laisser tourner : elle ne sera plus repausée.'];
}

/** Met en pause chez Meta toutes les pubs d'une pub du module ; rend vrai si au moins une l'a été. */
function socPubPauser(array $objs): bool
{
    $ok = false;
    foreach ($objs as $o) {
        try { Meta::call('POST', (string) $o['ad_id'], ['status' => 'PAUSED']); $ok = true; } catch (MetaError $e) { /* les autres d'abord */ }
    }
    return $ok;
}

/* --- meilleurs posts de la semaine ---------------------------------------------- */

/** Les interactions d'une publication lue chez Meta (null si Meta ne les donne pas). */
function socBoostInteractions(array $p): ?int
{
    $st = (array) ($p['stats'] ?? []);
    $v = array_filter([$st['reactions'] ?? null, $st['comments'] ?? null, $st['shares'] ?? null], static fn ($x) => $x !== null);
    return $v ? (int) array_sum($v) : null;
}

/** Les publications déjà boostées par une pub du module : metaId → [id, nom]. */
function socBoostDeja(): array
{
    $out = [];
    foreach (socItems('ads') as $a) {
        $m = (string) ($a['boost']['metaId'] ?? ($a['spec']['boost']['metaId'] ?? ''));
        if ($m !== '' && ($a['status'] ?? '') !== 'Refusée') { $out[$m] = ['id' => (string) $a['id'], 'nom' => (string) ($a['name'] ?? '')]; }
    }
    return $out;
}

/** Une publication du calendrier → ce que l'écran de la marque montre d'un post à booster. */
function socBoostPost(array $p, array $c, array $shops, ?float $mediane, array $deja): array
{
    $sid = $c['shop'] !== null && $c['shop'] !== 'marque' ? (string) $c['shop'] : null;
    $e = socBoostInteractions($p);
    return ['metaId' => (string) $p['metaId'], 'compte' => (string) $c['key'], 'canal' => (string) $p['channel'], 'shop' => $sid,
        'shopNom' => $sid !== null ? (string) ($shops[$sid]['name'] ?? $c['name']) : 'Marque', 'compteNom' => (string) $c['name'],
        'date' => (string) $p['date'], 'type' => (string) $p['type'], 'titre' => (string) $p['title'], 'texte' => mb_substr((string) ($p['text'] ?? ''), 0, 300),
        'img' => $p['img'] ?? null, 'permalink' => $p['permalink'] ?? null, 'stats' => $p['stats'] ?? [], 'interactions' => $e,
        'mediane' => $mediane, 'indice' => $e !== null && $mediane !== null && $mediane > 0 ? round($e / $mediane, 1) : null,
        'boostee' => $deja[(string) $p['metaId']] ?? null];
}

/**
 * Les meilleurs posts de la semaine : pour chaque compte (Page, Instagram), ses publications des 7 derniers jours et
 * leur médiane ; puis, par boutique (et pour la marque), la meilleure. Gardé deux heures (« Actualiser » relit).
 */
function socBoostMeilleurs(bool $forcer = false): array
{
    $c = socSetting('pub_meilleurs_cache');
    if (!$forcer && is_array($c) && isset($c['t'], $c['data']) && time() - (int) $c['t'] < SOC_BOOST_CACHE_S) {
        $d = $c['data']; $deja = socBoostDeja();
        foreach ($d['posts'] as &$p) { $p['boostee'] = $deja[$p['metaId']] ?? null; }
        unset($p);
        return $d;
    }
    $t1 = time(); $t0 = $t1 - SOC_BOOST_JOURS * 86400;
    $out = ['du' => date('Y-m-d', $t0), 'au' => date('Y-m-d', $t1), 'le' => date('Y-m-d H:i'), 'posts' => [], 'lus' => 0, 'erreurs' => []];
    if (!Meta::configured()) { $out['erreurs'][] = 'Token Meta absent : Paramètres → API Meta.'; return $out; }
    try { $comptes = socCalComptes(); }
    catch (MetaError $e) { $out['erreurs'][] = 'Pages du System User : ' . $e->getMessage(); return $out; }
    $shops = []; foreach (socShops() as $s) { $shops[(string) $s['id']] = $s; }
    $deja = socBoostDeja();
    $meilleurs = [];
    foreach ($comptes as $key => $cpt) {
        if (($cpt['token'] ?? '') === '' || $cpt['token'] === null) { continue; }
        if ($cpt['shop'] !== null && $cpt['shop'] !== 'marque' && !empty($shops[(string) $cpt['shop']]['horsCaisse'])) { continue; }
        try {
            $posts = [];
            if ($cpt['type'] === 'facebook') {
                foreach (socCalLireFacebook((string) $cpt['id'], $cpt['token'], $t0, $t1) as $x) { if (!empty($x['id'])) { $posts[] = socCalPostFacebook($x, $cpt, 'Publié'); } }
            } else {
                foreach (socGraphTout($cpt['id'] . '/media', ['fields' => SOC_CAL_IG, 'since' => $t0, 'until' => $t1, 'limit' => 100], SOC_CAL_MAX, $cpt['token']) as $x) {
                    $t = strtotime((string) ($x['timestamp'] ?? '')) ?: 0;
                    if (!empty($x['id']) && $t >= $t0 && $t <= $t1 && strtoupper((string) ($x['media_product_type'] ?? '')) !== 'STORY') { $posts[] = socCalPostInstagram($x, $cpt); }
                }
            }
            $out['lus']++;
            $mesures = array_values(array_filter(array_map('socBoostInteractions', $posts), static fn ($v) => $v !== null));
            $med = $mesures ? socMediane($mesures) : null;
            foreach ($posts as $p) {
                $b = socBoostPost($p, $cpt, $shops, $med, $deja);
                if ($b['interactions'] === null) { continue; }
                $k = $b['shop'] ?? 'marque';
                if (!isset($meilleurs[$k]) || $b['interactions'] > $meilleurs[$k]['interactions']) { $meilleurs[$k] = $b; }
            }
        } catch (MetaError $e) {
            $out['erreurs'][] = ($cpt['name'] ?: $key) . ' : ' . $e->getMessage();
        }
    }
    $l = array_values($meilleurs);
    usort($l, static fn ($a, $b) => ($b['indice'] ?? 0) <=> ($a['indice'] ?? 0) ?: $b['interactions'] <=> $a['interactions']);
    $out['posts'] = $l;
    socSettingPut('pub_meilleurs_cache', ['t' => time(), 'data' => $out], false);
    return $out;
}

/* --- demandes de boost ---------------------------------------------------------- */

function socBoostLigne(array $r): array
{
    return ['id' => (int) $r['id'], 'shop' => (string) $r['shop_id'], 'compte' => (string) $r['compte'], 'metaId' => (string) $r['meta_id'], 'canal' => (string) $r['canal'],
        'titre' => (string) ($r['titre'] ?? ''), 'texte' => (string) ($r['texte'] ?? ''), 'img' => $r['img'] ?: null, 'permalink' => $r['permalink'] ?: null,
        'datePost' => $r['date_post'] ? substr((string) $r['date_post'], 0, 10) : null, 'message' => (string) ($r['message'] ?? ''), 'par' => (string) ($r['par'] ?? ''),
        'statut' => (string) $r['statut'], 'reponse' => (string) ($r['reponse'] ?? ''), 'adId' => $r['ad_id'] ?: null,
        'le' => substr((string) $r['cree_le'], 0, 16), 'traiteLe' => $r['traite_le'] ? substr((string) $r['traite_le'], 0, 16) : null, 'traitePar' => (string) ($r['traite_par'] ?? '')];
}

/** Les demandes des boutiques (la marque) : en attente d'abord, puis les 60 derniers jours. */
function socBoostDemandes(): array
{
    $noms = []; foreach (socShops() as $s) { $noms[(string) $s['id']] = (string) $s['name']; }
    $l = array_map(static fn ($r) => socBoostLigne($r) + ['shopNom' => $noms[(string) $r['shop_id']] ?? (string) $r['shop_id']],
        Db::rows("SELECT * FROM social_boost_demande WHERE statut = 'attente' OR cree_le >= ? ORDER BY statut = 'attente' DESC, cree_le DESC LIMIT 200",
            [date('Y-m-d H:i:s', strtotime('-60 days'))]));
    return ['demandes' => $l, 'attente' => count(array_filter($l, static fn ($d) => $d['statut'] === 'attente'))];
}

function socBoostEnAttente(): int
{
    return (int) (Db::row("SELECT COUNT(*) n FROM social_boost_demande WHERE statut = 'attente'")['n'] ?? 0);
}

/** La marque refuse une demande, avec un mot pour la boutique. */
function socBoostRefuser(int $id, string $reponse): array
{
    $r = Db::row('SELECT * FROM social_boost_demande WHERE id = ?', [$id]);
    if (!$r) { throw new HttpError(404, 'Demande introuvable.'); }
    if ($r['statut'] !== 'attente') { throw new HttpError(409, 'Cette demande est déjà traitée.'); }
    $reponse = mb_substr(trim($reponse), 0, 500);
    Db::exec("UPDATE social_boost_demande SET statut = 'refusee', reponse = ?, traite_le = ?, traite_par = ? WHERE id = ?", [$reponse, date('Y-m-d H:i:s'), socUser(), $id]);
    socLog(socUser(), 'Publicité', 'Demande de boost refusée (boutique ' . $r['shop_id'] . ')' . ($reponse !== '' ? ' : ' . $reponse : ''));
    return socBoostDemandes();
}

/** La pub qui booste une demande est créée chez Meta : la demande passe « boostée ». */
function socBoostBoostee(int $id, string $metaId, string $adId): void
{
    $n = Db::exec("UPDATE social_boost_demande SET statut = 'boostee', ad_id = ?, reponse = NULL, traite_le = ?, traite_par = ? WHERE id = ? AND meta_id = ? AND statut <> 'boostee'",
        [$adId, date('Y-m-d H:i:s'), socUser(), $id, $metaId]);
    if ($n > 0) { socLog(socUser(), 'Publicité', 'Demande de boost n° ' . $id . ' boostée'); }
}

/**
 * Le post à booster d'une pub (`spec.boost`), vérifié côté serveur : le compte (Page ou Instagram) est celui de la
 * boutique de l'ad set, ou celui de la marque ; la publication est bien de ce compte. Rend les champs de la créa.
 */
function socBoostCrea(array $boost, array $shop): array
{
    $meta = (string) ($boost['metaId'] ?? ''); $key = (string) ($boost['compte'] ?? '');
    if (!preg_match('/^[\w]{1,40}(_[\w]{1,40})?$/', $meta)) { throw new MetaError('publication à booster invalide', 100); }
    $c = socCalComptes()[$key] ?? null;
    if ($c === null) { throw new MetaError('le compte de la publication à booster n’est plus visible du System User', 100); }
    if ($c['shop'] !== 'marque' && (string) $c['shop'] !== (string) $shop['id']) {
        throw new MetaError('cette publication est sur le compte d’une autre boutique : ne ciblez que sa boutique', 100);
    }
    if ($c['type'] === 'facebook') {
        if (!str_starts_with($meta, (string) $c['id'] . '_')) { throw new MetaError('cette publication n’est pas sur la Page indiquée', 100); }
        return ['object_story_id' => $meta];
    }
    if ((string) ($c['page'] ?? '') === '') { throw new MetaError('le compte Instagram de cette publication n’est relié à aucune Page visible', 100); }
    // Un post Instagram existant sponsorisé tel quel : Meta le reprend par son id, sous l'identité de son compte.
    return ['object_id' => (string) $c['page'], 'instagram_user_id' => (string) $c['id'], 'source_instagram_media_id' => $meta];
}

/* --- côté franchisé ------------------------------------------------------------- */

/** Les demandes de la boutique, les plus récentes d'abord. */
function sfBoostListe(string $shop): array
{
    $l = array_map('socBoostLigne', Db::rows('SELECT * FROM social_boost_demande WHERE shop_id = ? ORDER BY cree_le DESC, id DESC LIMIT 50', [$shop]));
    return ['demandes' => $l, 'attente' => count(array_filter($l, static fn ($d) => $d['statut'] === 'attente')), 'max' => SOC_BOOST_ATTENTE_MAX,
        'permis' => (bool) sfRegles()['proposerBoost']];
}

/**
 * La boutique propose au réseau de booster une de ses publications (`mfb-…` ou `mig-…` de son calendrier) : le compte
 * doit être le sien (pas celui du réseau), la publication est relue chez Meta (titre, visuel, lien), jamais prise du
 * navigateur. Trois demandes en attente au plus ; une publication refusée peut être reproposée.
 */
function sfBoostDemander(string $shop, string $id, string $compte, string $message, array $session): array
{
    if (!sfRegles()['proposerBoost']) { throw new HttpError(403, 'Le réseau ne prend pas de demandes de boost pour l’instant.'); }
    if (!preg_match('/^m(fb|ig)-([\w]{1,40}(_[\w]{1,40})?)$/', $id, $m)) { throw new HttpError(422, 'Publication invalide.'); }
    $meta = $m[2]; $fb = $m[1] === 'fb';
    if (!Meta::configured()) { throw new HttpError(409, 'Le réseau n’a pas encore relié ses comptes Meta.'); }
    $garde = sfCalComptes($shop);
    if (!array_key_exists($compte, $garde) || $garde[$compte] !== false || str_starts_with($compte, 'fb:') !== $fb) {
        throw new HttpError(404, 'Cette publication n’est pas sur un compte de votre boutique.');
    }
    $c = socCalComptes()[$compte];
    if ($fb && !str_starts_with($meta, (string) $c['id'] . '_')) { throw new HttpError(404, 'Cette publication n’est pas sur la Page de votre boutique.'); }
    $ex = Db::row('SELECT * FROM social_boost_demande WHERE shop_id = ? AND meta_id = ?', [$shop, $meta]);
    if ($ex && $ex['statut'] === 'attente') { throw new HttpError(409, 'Vous avez déjà proposé cette publication : le réseau ne l’a pas encore traitée.'); }
    if ($ex && $ex['statut'] === 'boostee') { throw new HttpError(409, 'Cette publication est déjà boostée par le réseau.'); }
    $n = (int) (Db::row("SELECT COUNT(*) n FROM social_boost_demande WHERE shop_id = ? AND statut = 'attente'", [$shop])['n'] ?? 0);
    if ($n >= SOC_BOOST_ATTENTE_MAX) { throw new HttpError(422, 'Vous avez déjà ' . $n . ' demandes en attente : attendez la réponse du réseau.'); }
    try {
        $x = Meta::call('GET', $meta, ['fields' => $fb ? 'id,message,created_time,permalink_url,full_picture' : 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp'], (string) $c['token']);
    } catch (MetaError $e) { throw new HttpError(404, 'Publication introuvable chez Meta.'); }
    $texte = (string) ($fb ? ($x['message'] ?? '') : ($x['caption'] ?? ''));
    $img = (string) ($fb ? ($x['full_picture'] ?? '') : ((strtoupper((string) ($x['media_type'] ?? '')) === 'VIDEO' ? ($x['thumbnail_url'] ?? '') : '') ?: ($x['media_url'] ?? '')));
    $quand = socCalQuand($fb ? ($x['created_time'] ?? null) : ($x['timestamp'] ?? null));
    $par = (string) ($session['name'] ?? '') ?: 'Boutique';
    $vals = [$compte, $fb ? 'Facebook' : 'Instagram', socCalTitre($texte, $fb ? 'Publication Facebook' : 'Publication Instagram'), mb_substr($texte, 0, 2000),
        $img !== '' ? mb_substr($img, 0, 1000) : null, mb_substr((string) ($fb ? ($x['permalink_url'] ?? '') : ($x['permalink'] ?? '')), 0, 1000) ?: null,
        $quand[0] !== '' ? $quand[0] : null, mb_substr(trim($message), 0, 500), mb_substr($par, 0, 120), date('Y-m-d H:i:s')];
    if ($ex) {
        Db::exec("UPDATE social_boost_demande SET compte = ?, canal = ?, titre = ?, texte = ?, img = ?, permalink = ?, date_post = ?, message = ?, par = ?, cree_le = ?,
            statut = 'attente', reponse = NULL, traite_le = NULL, traite_par = NULL, ad_id = NULL WHERE id = ?", array_merge($vals, [$ex['id']]));
    } else {
        Db::exec('INSERT INTO social_boost_demande (compte, canal, titre, texte, img, permalink, date_post, message, par, cree_le, shop_id, meta_id, statut)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'attente\')', array_merge($vals, [$shop, $meta]));
    }
    socLog('Franchisé · ' . (string) (socShop($shop)['name'] ?? $shop), 'Publicité', 'Propose de booster « ' . $vals[2] . ' » (' . $vals[1] . ')');
    return sfBoostListe($shop);
}
