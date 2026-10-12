<?php
declare(strict_types=1);

/**
 * Les calendriers déjà tenus chez Meta : ce que chaque Page Facebook et chaque
 * compte Instagram a publié (ou programmé dans Meta Business Suite), relu et
 * rangé dans le Calendrier à côté des posts du module.
 *
 * Lu en direct via l'API Meta pour la période affichée, à chaque demande :
 * rien n'est copié en base. Une publication Meta = un post de l'écran
 * d'origine « meta » (`mfb-{post_id}`, `mig-{media_id}`), en lecture seule.
 *
 * Les comptes (Pages et Instagram, avec leur photo de profil) servent de
 * filtres au Calendrier. Les publications faites par le module sont reconnues
 * (social_publication.meta_id) et ne sont pas montrées deux fois.
 */

const SOC_CAL_MAX = 400;         // publications au plus par compte et par passage
const SOC_CAL_FB = 'id,message,created_time,permalink_url,full_picture,status_type,attachments{media_type,subattachments.limit(10){media_type}},'
    . 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares';
const SOC_CAL_FB_MIN = 'id,message,created_time,permalink_url,full_picture,status_type';
const SOC_CAL_IG = 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count';

/** Texte d'une publication → titre court pour la carte du calendrier. */
function socCalTitre(string $texte, string $repli): string
{
    $l = trim((string) strtok(trim($texte) . "\n", "\n"));
    if ($l === '') { return $repli; }
    return mb_strlen($l) > 70 ? rtrim(mb_substr($l, 0, 68)) . '…' : $l;
}

/** Date Meta (ISO ou Unix) → [jour, heure] dans le fuseau du serveur. */
function socCalQuand(mixed $t): array
{
    $ts = is_numeric($t) ? (int) $t : (strtotime((string) $t) ?: time());
    return [date('Y-m-d', $ts), date('H:i', $ts)];
}

/**
 * Les comptes à relire : chaque Page visible du System User (et son Instagram),
 * rapprochée d'une boutique (identifiants saisis, sinon nom de la Page) ou de la marque.
 */
function socCalComptes(): array
{
    $shops = socShops();
    $parPage = []; $parIg = [];
    foreach ($shops as $s) {
        if (($s['ids']['page_id'] ?? '') !== '') { $parPage[(string) $s['ids']['page_id']] = (string) $s['id']; }
        if (($s['ids']['instagram_user_id'] ?? '') !== '') { $parIg[(string) $s['ids']['instagram_user_id']] = (string) $s['id']; }
    }
    $b = socBrand()['ids'];
    if (($b['page_id'] ?? '') !== '') { $parPage[(string) $b['page_id']] ??= 'marque'; }
    if (($b['instagram_user_id'] ?? '') !== '') { $parIg[(string) $b['instagram_user_id']] ??= 'marque'; }
    $comptes = [];
    // La Page qui porte l'Instagram du réseau est celle de la marque (même si une autre Page est saisie pour la marque).
    $igMarque = (string) ($b['instagram_user_id'] ?? '');
    $pageMarque = null;
    foreach (Meta::pageTokens() as $pid => $pg) { if ($igMarque !== '' && (string) ($pg['ig'] ?? '') === $igMarque) { $pageMarque = (string) $pid; } }
    if ($pageMarque !== null) { foreach ($parPage as $k => $v) { if ($v === 'marque') { unset($parPage[$k]); } } $parPage[$pageMarque] = 'marque'; }
    foreach (Meta::pageTokens() as $pid => $pg) {
        $pid = (string) $pid;
        $sid = $parPage[$pid] ?? null;
        if ($sid === null) { $g = socDevinerBoutique((string) $pg['name'], $shops); $sid = $g === 'marque' ? null : $g; }  // « marque » seulement si c'est la Page saisie
        $comptes['fb:' . $pid] = ['key' => 'fb:' . $pid, 'type' => 'facebook', 'id' => $pid, 'name' => (string) $pg['name'], 'username' => '', 'picture' => null,
            'shop' => $sid, 'token' => (string) $pg['token'], 'page' => $pid];
        $ig = (string) ($pg['ig'] ?? '');
        if ($ig !== '') {
            $comptes['ig:' . $ig] = ['key' => 'ig:' . $ig, 'type' => 'instagram', 'id' => $ig, 'name' => (string) $pg['name'], 'username' => '', 'picture' => null,
                'shop' => $parIg[$ig] ?? $sid, 'token' => (string) $pg['token'], 'page' => $pid];
        }
    }
    // Un Instagram saisi pour une boutique mais rattaché à une Page que le System User ne voit pas.
    foreach ($parIg as $ig => $sid) {
        $ig = (string) $ig;
        if (!isset($comptes['ig:' . $ig])) {
            $comptes['ig:' . $ig] = ['key' => 'ig:' . $ig, 'type' => 'instagram', 'id' => $ig, 'name' => '', 'username' => '', 'picture' => null, 'shop' => $sid, 'token' => null, 'page' => null];
        }
    }
    return $comptes;
}

/** Les publications déjà faites par le module (jamais réimportées). */
function socCalDuModule(): array
{
    $ids = [];
    foreach (Db::rows("SELECT meta_id FROM social_publication WHERE meta_id IS NOT NULL AND meta_id <> ''") as $r) { $ids[(string) $r['meta_id']] = true; }
    foreach (socItems('posts') as $p) {
        foreach ((array) ($p['frise']['ig'] ?? []) as $parBoutique) { foreach ((array) $parBoutique as $mid) { if (is_scalar($mid)) { $ids[(string) $mid] = true; } } }
    }
    return $ids;
}

/**
 * Les publications de toutes les Pages et de tous les comptes Instagram entre
 * deux dates, lues en direct chez Meta (rien n'est gardé en base), plus les
 * posts programmés dans Meta Business Suite qui tombent dans la période.
 * Rend ['comptes' => profils (photo, nom, boutique, compteurs), 'posts' => …].
 */
function socCalendriersLire(string $du, string $au): array
{
    if (!Meta::configured()) { throw new HttpError(409, 'token Meta absent : saisissez-le dans Paramètres → API Meta → Clés d’accès Meta'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || $au < $du) { throw new HttpError(400, 'période invalide (du, au : AAAA-MM-JJ)'); }
    if ((strtotime($au) - strtotime($du)) / 86400 > 100) { throw new HttpError(400, 'période trop longue : 100 jours au plus'); }
    $t0 = strtotime($du . ' 00:00:00'); $t1 = strtotime($au . ' 23:59:59');
    $out = ['du' => $du, 'au' => $au, 'le' => date('Y-m-d H:i'), 'comptes' => [], 'posts' => [], 'erreurs' => []];
    try { $comptes = socCalComptes(); }
    catch (MetaError $e) { $comptes = []; $out['erreurs'][] = 'Pages du System User : ' . $e->getMessage(); }
    if (!$comptes && !$out['erreurs']) { $out['erreurs'][] = 'aucune Page visible du System User (Business Manager → Utilisateurs système → Ressources)'; }
    $duModule = socCalDuModule();

    foreach ($comptes as $key => $c) {
        $ligne = ['key' => $key, 'type' => $c['type'], 'id' => $c['id'], 'name' => $c['name'], 'username' => '', 'picture' => null, 'link' => null,
            'shop' => $c['shop'], 'publies' => 0, 'programmes' => 0, 'erreur' => null];
        try {
            if ($c['type'] === 'facebook') {
                try {
                    $pr = Meta::call('GET', $c['id'], ['fields' => 'name,username,link,picture.type(large){url}'], $c['token']);
                    $ligne['name'] = (string) ($pr['name'] ?? $ligne['name']); $ligne['username'] = (string) ($pr['username'] ?? '');
                    $ligne['picture'] = (string) ($pr['picture']['data']['url'] ?? '') ?: null; $ligne['link'] = (string) ($pr['link'] ?? '') ?: null;
                } catch (MetaError $e) { /* la photo manque : le nom suffit */ }
                $posts = socCalLireFacebook($c['id'], $c['token'], $t0, $t1);
                foreach ($posts as $x) {
                    if (empty($x['id']) || isset($duModule[(string) $x['id']])) { continue; }
                    $out['posts'][] = socCalPostFacebook($x, $ligne, 'Publié');
                    $ligne['publies']++;
                }
                if ($t1 >= time()) {
                    try {
                        foreach (socGraphTout($c['id'] . '/scheduled_posts', ['fields' => 'id,message,scheduled_publish_time,created_time,full_picture,permalink_url', 'limit' => 100], 200, $c['token']) as $x) {
                            $t = (int) ($x['scheduled_publish_time'] ?? 0);
                            if (empty($x['id']) || isset($duModule[(string) $x['id']]) || $t < $t0 || $t > $t1) { continue; }
                            $out['posts'][] = socCalPostFacebook($x, $ligne, 'Programmé');
                            $ligne['programmes']++;
                        }
                    } catch (MetaError $e) { $out['erreurs'][] = $ligne['name'] . ' (posts programmés) : ' . $e->getMessage(); }
                }
            } else {
                try {
                    $pr = Meta::call('GET', $c['id'], ['fields' => 'username,name,profile_picture_url'], $c['token']);
                    $ligne['username'] = (string) ($pr['username'] ?? ''); $ligne['name'] = (string) (($pr['name'] ?? '') ?: ($ligne['name'] ?: $ligne['username']));
                    $ligne['picture'] = (string) ($pr['profile_picture_url'] ?? '') ?: null;
                    $ligne['link'] = $ligne['username'] !== '' ? 'https://www.instagram.com/' . $ligne['username'] . '/' : null;
                } catch (MetaError $e) { /* idem */ }
                foreach (socGraphTout($c['id'] . '/media', ['fields' => SOC_CAL_IG, 'since' => $t0, 'until' => $t1, 'limit' => 100], SOC_CAL_MAX, $c['token']) as $x) {
                    $t = strtotime((string) ($x['timestamp'] ?? '')) ?: 0;
                    if (empty($x['id']) || isset($duModule[(string) $x['id']]) || $t < $t0 || $t > $t1) { continue; }
                    $out['posts'][] = socCalPostInstagram($x, $ligne);
                    $ligne['publies']++;
                }
            }
        } catch (MetaError $e) {
            $ligne['erreur'] = $e->getMessage();
            $out['erreurs'][] = ($ligne['name'] ?: $key) . ' : ' . $e->getMessage();
        }
        $out['comptes'][] = $ligne;
    }
    usort($out['posts'], static fn ($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
    return $out;
}

/**
 * Les publications d'une Page sur une période. Meta exige `pages_read_user_content`
 * dès que la réponse peut contenir des contenus d'utilisateurs (réactions,
 * commentaires…) : on demande d'abord tout, puis l'essentiel seulement, puis par
 * les autres accès (`/posts`, `/feed` filtré sur la Page). Le premier qui répond gagne.
 */
function socCalLireFacebook(string $page, ?string $tok, int $t0, int $t1): array
{
    $q = ['since' => $t0, 'until' => $t1, 'limit' => 100];
    $essais = [['published_posts', SOC_CAL_FB], ['published_posts', SOC_CAL_FB_MIN], ['posts', SOC_CAL_FB_MIN], ['feed', SOC_CAL_FB_MIN . ',from']];
    $premiere = null;
    foreach ($essais as [$edge, $champs]) {
        try {
            $l = socGraphTout($page . '/' . $edge, ['fields' => $champs] + $q, SOC_CAL_MAX, $tok);
            if ($edge === 'feed') { $l = array_values(array_filter($l, static fn ($x) => (string) ($x['from']['id'] ?? $page) === $page)); }
            return $l;
        } catch (MetaError $e) {
            $premiere ??= $e;
            if (!in_array($e->metaCode, [10, 100, 200], true)) { throw $e; }  // permission ou champ refusés : essai suivant
        }
    }
    throw $premiere ?? new MetaError('publications illisibles', 0);
}

/** Une publication (ou un post programmé) d'une Page → post du calendrier. */
function socCalPostFacebook(array $x, array $compte, string $statut): array
{
    [$jour, $heure] = socCalQuand($statut === 'Programmé' ? ($x['scheduled_publish_time'] ?? null) : ($x['created_time'] ?? null));
    $att = (array) ($x['attachments']['data'][0] ?? []);
    $mt = strtoupper((string) ($att['media_type'] ?? ''));
    $n = count((array) ($att['subattachments']['data'] ?? []));
    $type = match (true) {
        $n > 1 => 'Album · ' . $n . ' photos',
        $mt === 'VIDEO' || str_contains((string) ($x['status_type'] ?? ''), 'video') => 'Vidéo',
        $mt === 'PHOTO' || ($x['full_picture'] ?? '') !== '' => 'Photo',
        $mt === 'LINK' || $mt === 'SHARE' => 'Lien',
        default => 'Texte',
    };
    $texte = (string) ($x['message'] ?? '');
    return ['id' => 'mfb-' . (string) $x['id'], 'origin' => 'meta', 'channel' => 'Facebook', 'account' => $compte['key'], 'accountName' => $compte['name'],
        'metaId' => (string) $x['id'], 'date' => $jour, 'time' => $heure, 'type' => $type, 'title' => socCalTitre($texte, $type . ' Facebook'), 'text' => $texte,
        'img' => (string) ($x['full_picture'] ?? '') ?: null, 'shops' => $compte['shop'] !== null && $compte['shop'] !== 'marque' ? [(string) $compte['shop']] : [],
        'marque' => $compte['shop'] === 'marque', 'fb' => true, 'ig' => false, 'status' => $statut, 'permalink' => (string) ($x['permalink_url'] ?? '') ?: null,
        'stats' => ['reactions' => isset($x['reactions']['summary']['total_count']) ? (int) $x['reactions']['summary']['total_count'] : null,
            'comments' => isset($x['comments']['summary']['total_count']) ? (int) $x['comments']['summary']['total_count'] : null,
            'shares' => isset($x['shares']['count']) ? (int) $x['shares']['count'] : null],
        'syncedAt' => date('Y-m-d H:i')];
}

/** Un média Instagram → post du calendrier. */
function socCalPostInstagram(array $x, array $compte): array
{
    [$jour, $heure] = socCalQuand($x['timestamp'] ?? null);
    $mt = strtoupper((string) ($x['media_type'] ?? '')); $pt = strtoupper((string) ($x['media_product_type'] ?? ''));
    $type = match (true) { $pt === 'REELS' => 'Reel', $pt === 'STORY' => 'Story', $mt === 'CAROUSEL_ALBUM' => 'Carrousel', $mt === 'VIDEO' => 'Vidéo', default => 'Photo' };
    $texte = (string) ($x['caption'] ?? '');
    return ['id' => 'mig-' . (string) $x['id'], 'origin' => 'meta', 'channel' => 'Instagram', 'account' => $compte['key'], 'accountName' => $compte['username'] !== '' ? '@' . $compte['username'] : $compte['name'],
        'metaId' => (string) $x['id'], 'date' => $jour, 'time' => $heure, 'type' => $type, 'title' => socCalTitre($texte, $type . ' Instagram'), 'text' => $texte,
        'img' => (string) (($mt === 'VIDEO' ? ($x['thumbnail_url'] ?? '') : '') ?: ($x['media_url'] ?? '')) ?: null,
        'shops' => $compte['shop'] !== null && $compte['shop'] !== 'marque' ? [(string) $compte['shop']] : [], 'marque' => $compte['shop'] === 'marque',
        'fb' => false, 'ig' => true, 'status' => 'Publié', 'permalink' => (string) ($x['permalink'] ?? '') ?: null,
        'stats' => ['reactions' => isset($x['like_count']) ? (int) $x['like_count'] : null, 'comments' => isset($x['comments_count']) ? (int) $x['comments_count'] : null, 'shares' => null],
        'syncedAt' => date('Y-m-d H:i')];
}

/*
 * Les chiffres d'une publication (vues, personnes touchées, clics, réactions…),
 * lus chez Meta à l'ouverture de sa fiche dans le Calendrier : rien n'est gardé.
 * Meta renomme régulièrement ces métriques (les « impressions » sont devenues des
 * « vues » en 2025) : chaque chiffre a ses noms possibles, essayés dans l'ordre.
 * Facebook demande `read_insights`, Instagram `instagram_manage_insights`.
 */
const SOC_STATS_FB = ['vues' => ['post_media_view', 'post_impressions'], 'personnes' => ['post_total_media_view_unique', 'post_impressions_unique'],
    'clics' => ['post_clicks'], 'videos' => ['post_video_views'], 'reactions' => ['post_reactions_by_type_total']];
const SOC_STATS_IG = ['vues' => ['views', 'impressions', 'plays'], 'personnes' => ['reach'], 'enregistrements' => ['saved'], 'partages' => ['shares'],
    'interactions' => ['total_interactions']];

/** Valeur d'une métrique : un nombre, ou la somme d'une répartition (réactions par type). */
function socCalValeur(mixed $v): ?int
{
    if (is_numeric($v)) { return (int) $v; }
    if (is_array($v)) { return array_sum(array_map('intval', array_filter($v, 'is_numeric'))); }
    return null;
}

/** Les chiffres d'une publication du calendrier (`mfb-…` / `mig-…`) du compte `fb:…` / `ig:…`. */
function socCalStats(string $id, string $compte): array
{
    $fb = str_starts_with($id, 'mfb-');
    $mid = substr($id, 4);
    if ((!$fb && !str_starts_with($id, 'mig-')) || !preg_match('/^\w+$/', $mid)) { throw new HttpError(400, 'publication inconnue : ' . $id); }
    $c = socCalComptes()[$compte] ?? null;
    if ($c === null || $c['type'] !== ($fb ? 'facebook' : 'instagram') || ($fb && !str_starts_with($mid, $c['id'] . '_'))) {
        throw new HttpError(404, 'cette publication n’appartient pas au compte ' . $compte);
    }
    $out = ['id' => $id, 'vues' => null, 'personnes' => null, 'clics' => null, 'videos' => null, 'reactions' => null, 'commentaires' => null, 'partages' => null,
        'enregistrements' => null, 'interactions' => null, 'motif' => null, 'le' => date('Y-m-d H:i')];
    $tok = $c['token'];
    if ($tok === null) { $out['motif'] = 'compte non accessible au token (Page non partagée avec l’utilisateur système)'; return $out; }
    $perm = $fb ? 'read_insights' : 'instagram_manage_insights';
    $metriques = $fb ? SOC_STATS_FB : SOC_STATS_IG;
    $lire = static function (array $noms) use ($mid, $tok, $fb): array {
        $r = Meta::call('GET', $mid . '/insights', ['metric' => implode(',', $noms)] + ($fb ? ['period' => 'lifetime'] : []), $tok);
        $v = [];
        foreach ((array) ($r['data'] ?? []) as $d) {
            $x = socCalValeur($d['values'][0]['value'] ?? ($d['total_value']['value'] ?? null));
            if ($x !== null) { $v[(string) ($d['name'] ?? '')] = $x; }
        }
        return $v;
    };
    // Un seul appel pour les noms actuels ; un nom refusé (métrique retirée, type de média) fait tout refuser : alors un par un.
    $lu = []; $essayes = [];
    try { $tete = array_map(static fn ($n) => $n[0], $metriques); $lu = $lire(array_values($tete)); $essayes = array_flip($tete); }
    catch (MetaError $e) { if (socSuiviPermission($e)) { $out['motif'] = 'vues indisponibles : ajoutez la permission ' . $perm . ' au token (' . $e->getMessage() . ')'; } }
    if ($out['motif'] === null) {
        foreach ($metriques as $cle => $noms) {
            foreach ($noms as $nom) {
                if (!isset($lu[$nom]) && !isset($essayes[$nom])) {
                    $essayes[$nom] = true;
                    try { $lu += $lire([$nom]); }
                    catch (MetaError $e) { if (socSuiviPermission($e)) { $out['motif'] = 'vues indisponibles : ajoutez la permission ' . $perm . ' au token (' . $e->getMessage() . ')'; break 2; } }
                }
                if (isset($lu[$nom])) { $out[$cle] = $lu[$nom]; break; }
            }
        }
    }
    // Réactions, commentaires, partages : champs du post (Facebook : `pages_read_user_content`), utiles même sans insights.
    try {
        if ($fb) {
            $x = Meta::call('GET', $mid, ['fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares'], $tok);
            if (isset($x['reactions']['summary']['total_count'])) { $out['reactions'] = (int) $x['reactions']['summary']['total_count']; }
            if (isset($x['comments']['summary']['total_count'])) { $out['commentaires'] = (int) $x['comments']['summary']['total_count']; }
            if (isset($x['shares']['count'])) { $out['partages'] ??= (int) $x['shares']['count']; }
        } else {
            $x = Meta::call('GET', $mid, ['fields' => 'like_count,comments_count'], $tok);
            if (isset($x['like_count'])) { $out['reactions'] = (int) $x['like_count']; }
            if (isset($x['comments_count'])) { $out['commentaires'] = (int) $x['comments_count']; }
        }
    } catch (MetaError $e) { /* réactions illisibles sans pages_read_user_content : les vues suffisent */ }
    return $out;
}
