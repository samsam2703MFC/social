<?php
declare(strict_types=1);

/**
 * Écran « Publicités » → Marketing API, en quatre objets : campagne, ad set,
 * créa, pub (docs/meta-api-reference.md §2–5).
 *
 *  - « Un par boutique » : un jeu complet par `ad_account_id` de boutique ;
 *  - « Compte réseau » : une campagne sur le compte réseau, et un ad set par
 *    boutique (une zone chacun) — c'est ce qui permet de lire la dépense et
 *    les résultats boutique par boutique dans « Retour sur campagne » ;
 *  - boutique bilingue : un ad set et une créa par langue (`targeting.locales`) ;
 *  - variantes (A/B/C) : une créa et une pub par variante dans chaque ad set —
 *    Meta répartit la diffusion et privilégie la plus performante ;
 *  - « dark post » (nouvelle créa) : Meta crée un post « pub uniquement », absent
 *    du mur de la Page et de la grille Instagram. Image, carrousel (ordre verrouillé
 *    pour un carrousel panoramique ou une frise) ou vidéo selon le post du module ;
 *    son id est relu (`effective_object_story_id`) pour le réutiliser et suivre ses
 *    commentaires (src/darkposts.php).
 *
 * L'assistant de l'écran envoie son état complet (`spec`) ; le serveur en
 * tire les appels, résout ce que l'écran ne peut pas savoir (ids des centres
 * d'intérêt et des langues par Targeting Search, `image_hash`, id du post
 * publié) et note chaque objet créé dans `social_meta_objet`.
 */

const SOC_AD_STATUT = ['Active' => 'ACTIVE', 'En pause' => 'PAUSED', 'Arrêtée' => 'ARCHIVED'];

/** Les stratégies d'enchère qui prennent un montant (`bid_amount`) ; pas « coût le plus bas » (LOWEST_COST_WITHOUT_CAP). */
const SOC_ENCHERES_MONTANT = ['LOWEST_COST_WITH_BID_CAP', 'COST_CAP', 'LOWEST_COST_WITH_MIN_ROAS'];

/** Montant en centimes : 20 € → 2000. */
function socCents(mixed $eur): int { return (int) round(((float) $eur) * 100); }

/** Une date de l'écran (AAAA-MM-JJ) et une heure, à l'heure de Bruxelles, en ISO 8601. */
function socIso(string $jour, string $heure): string
{
    $d = new DateTimeImmutable($jour . ' ' . $heure, new DateTimeZone('Europe/Brussels'));
    return $d->format('Y-m-d\TH:i:sO');
}

/**
 * Les ids Meta des centres d'intérêt, par nom (Targeting Search, mémorisé). La recherche rend encore des
 * intérêts que Meta a abandonnés (et refuse ensuite dans un ad set) : ceux-là sont écartés.
 */
function socInterets(array $noms): array
{
    $memo = (array) (socSetting('meta_cache_interets') ?? []);
    $ab = socInteretsAbandonnes();
    $out = [];
    foreach ($noms as $n) {
        $n = (string) $n;
        if (!isset($memo[$n]) || isset($ab[(string) $memo[$n]['id']])) {
            try {
                $r = Meta::call('GET', 'search', ['type' => 'adinterest', 'q' => $n, 'locale' => 'fr_FR', 'limit' => 5]);
                $hit = null;
                foreach ((array) ($r['data'] ?? []) as $x) { if (!isset($ab[(string) ($x['id'] ?? '')])) { $hit = $x; break; } }
                $memo[$n] = $hit ? ['id' => (string) $hit['id'], 'name' => (string) $hit['name']] : null;
            } catch (MetaError $e) { $memo[$n] = null; }
        }
        if ($memo[$n]) { $out[] = $memo[$n]; }
    }
    socSettingPut('meta_cache_interets', $memo);
    return $out;
}

/** Les centres d'intérêt que Meta a abandonnés : id → nom. */
function socInteretsAbandonnes(): array { return (array) (socSetting('meta_interets_abandonnes') ?? []); }

/**
 * Meta refuse un ad set dont le ciblage contient un centre d'intérêt abandonné, et les nomme dans son message
 * (« deprecated_interest_id »). Les note (écartés des recherches suivantes) et rend leurs ids ; [] pour une autre erreur.
 */
function socInteretsRefuses(MetaError $e): array
{
    if (!preg_match_all('/"deprecated_interest_id"\s*:\s*"?(\d+)"?(?:\s*,\s*"deprecated_interest_name"\s*:\s*"((?:[^"\\\\]|\\\\.)*)")?/', $e->getMessage(), $m, PREG_SET_ORDER)) { return []; }
    $ab = socInteretsAbandonnes();
    foreach ($m as $x) { $ab[$x[1]] = isset($x[2]) ? (string) json_decode('"' . $x[2] . '"') : ''; }
    socSettingPut('meta_interets_abandonnes', $ab);
    return array_values(array_unique(array_column($m, 1)));
}

/** La clé de locale Meta d'une langue (`targeting.locales`). */
function socLocale(string $lang): ?int
{
    $memo = (array) (socSetting('meta_cache_locales') ?? []);
    if (!array_key_exists($lang, $memo)) {
        $q = $lang === 'nl' ? 'Dutch' : 'French';
        try {
            $r = Meta::call('GET', 'search', ['type' => 'adlocale', 'q' => $q]);
            $hit = null;
            foreach ($r['data'] ?? [] as $l) { if (stripos((string) $l['name'], $q) === 0) { $hit = (int) $l['key']; break; } }
            $memo[$lang] = $hit;
        } catch (MetaError $e) { return null; }
        socSettingPut('meta_cache_locales', $memo);
    }
    return $memo[$lang] ?? null;
}

/** La position d'une boutique ; à défaut, son adresse géocodée (OpenStreetMap), mémorisée. */
function socGeo(array $shop): ?array
{
    if (is_array($shop['geo']) && count($shop['geo']) === 2) { return $shop['geo']; }
    $adr = trim((string) $shop['address']);
    if ($adr === '' || getenv('SOCIAL_NO_GEOCODE')) { return null; }
    $ch = curl_init('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=be&q=' . rawurlencode($adr));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'LAtelierBy-Social/1.0']);
    $j = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (!isset($j[0]['lat'], $j[0]['lon'])) { return null; }
    $geo = [(float) $j[0]['lat'], (float) $j[0]['lon']];
    socShopPut($shop['id'], ['geo' => $geo]);
    return $geo;
}

/** Un fichier du module (img/…, uploads/…) en base64 pour /adimages. */
function socImageBytes(string $src): ?string
{
    if (preg_match('#^https?://#', $src)) {
        $bin = @file_get_contents($src);
    } else {
        $f = realpath(__DIR__ . '/../public/' . ltrim($src, './'));
        $pub = realpath(__DIR__ . '/../public');
        $up = realpath(socUploadsDir());
        $ok = $f !== false && (str_starts_with($f, (string) $pub) || ($up !== false && str_starts_with($f, $up)));
        if (!$ok && str_starts_with($src, 'uploads/')) {
            $f = realpath(socUploadsDir() . '/' . substr($src, 8));
            $ok = $f !== false && $up !== false && str_starts_with($f, $up);
        }
        $bin = $ok ? @file_get_contents($f) : false;
    }
    return is_string($bin) && $bin !== '' ? base64_encode($bin) : null;
}

/**
 * Crée chez Meta une pub telle que l'assistant la décrit. Rend l'objet pub
 * de l'écran mis à jour (statut « En revue », « En pause » ou « Refusée »
 * avec le motif).
 */
function socAdCreate(array $ad): array
{
    $w = (array) ($ad['spec'] ?? []);
    if (!$w) { $ad['status'] = 'Refusée'; $ad['reason'] = 'Non envoyée à Meta : la description de l’assistant manque (créez la pub depuis l’assistant).'; return $ad; }
    if (!Meta::configured()) { $ad['status'] = 'Refusée'; $ad['reason'] = 'Non envoyée à Meta : token Meta absent : à saisir dans Paramètres → API Meta → Clés d’accès Meta.'; return $ad; }
    $shops = []; foreach (socShops() as $s) { $shops[$s['id']] = $s; }
    $reseau = ($w['account'] ?? 'boutique') === 'reseau';
    $cibles = array_values(array_filter((array) ($w['shops'] ?? []), static fn ($id) => isset($shops[$id])));
    if (!$cibles) { $ad['status'] = 'Refusée'; $ad['reason'] = 'Aucune boutique ciblée.'; return $ad; }
    $isLeads = ($w['objective'] ?? '') === 'OUTCOME_LEADS';
    $budgetKey = ($w['budgetType'] ?? 'daily') === 'daily' ? 'daily_budget' : 'lifetime_budget';
    $nCamp = $reseau ? 1 : count($cibles);
    $cbo = ($w['budgetLevel'] ?? 'adset') === 'campaign';
    $enchere = (string) (($w['bid'] ?? '') ?: 'LOWEST_COST_WITHOUT_CAP');
    $erreurs = []; $crees = 0; $retraits = [];
    $interets = !empty($w['interests']) ? socInterets((array) $w['interests']) : [];
    $variantes = socVariantes($w);
    $dark = ($w['crea'] ?? 'existing') === 'new';
    $form = $isLeads && !empty($w['leadForm']) ? socLeadformMetaIds((string) $w['leadForm']) : [];

    // Les unités : [compte, [boutiques]] — une par boutique, ou une seule réseau.
    $unites = $reseau ? [[socActId(socBrand()['ids']['ad_account_id']), $cibles]]
        : array_map(static fn ($id) => [socActId((string) $shops[$id]['ids']['ad_account_id']), [$id]], $cibles);

    foreach ($unites as [$act, $ids]) {
        $nomU = $reseau ? 'réseau' : $shops[$ids[0]]['name'];
        try {
            if ($act === '') { throw new MetaError($reseau ? 'compte publicitaire réseau non renseigné (Paramètres → API Meta)' : 'ad_account_id non renseigné', 100); }
            $camp = ['name' => (string) $w['name'] . ($reseau ? '' : ' · ' . $nomU), 'objective' => (string) $w['objective'], 'status' => (string) ($w['status'] ?? 'PAUSED'),
                'special_ad_categories' => array_values((array) ($w['special'] ?? [])), 'buying_type' => 'AUCTION'];
            if (!empty($w['spendCap'])) { $camp['spend_cap'] = socCents(((float) $w['spendCap']) / $nCamp); }
            // La stratégie d'enchère suit le budget : sur la campagne en CBO ; en ABO, Meta la refuse sur une
            // campagne sans budget (« Ajoutez un budget pour modifier la stratégie d'enchère ») : sur chaque ad set.
            if ($cbo) { $camp[$budgetKey] = socCents($w['amount']); $camp['bid_strategy'] = $enchere; }
            else { $camp['is_adset_budget_sharing_enabled'] = false; }
            $c = Meta::call('POST', $act . '/campaigns', $camp);
            $campaignId = (string) ($c['id'] ?? '');

            // Un ad set (et une créa, une pub) par boutique et par langue.
            $jeux = []; $creesAvant = $crees;
            foreach ($ids as $sid) {
                $langs = $shops[$sid]['langs'] ?: ['fr'];
                foreach ($langs as $L) { $jeux[] = [$sid, $L, count($langs) > 1]; }
            }
            $parJeu = ($w['budgetLevel'] ?? 'adset') === 'adset' ? ((float) $w['amount']) / ($reseau ? count($jeux) : max(1, count($jeux))) : 0;
            foreach ($jeux as [$sid, $L, $bilingue]) {
                $shop = $shops[$sid];
                $obj = ['kind' => 'ad', 'local_id' => (string) $ad['id'], 'shop_id' => $sid, 'account' => $act, 'campaign_id' => $campaignId];
                try {
                    $geo = socGeo($shop);
                    if ($geo === null) { throw new MetaError('position de la boutique inconnue : renseignez son adresse', 100); }
                    $radius = (float) (($w['radius'][$sid] ?? null) ?: ($ad['radius'] ?? 8));
                    $targeting = ['geo_locations' => ['custom_locations' => [['latitude' => $geo[0], 'longitude' => $geo[1], 'radius' => $radius, 'distance_unit' => 'kilometer']]],
                        'age_min' => (int) ($w['ageMin'] ?? 18), 'age_max' => (int) ($w['ageMax'] ?? 65),
                        'publisher_platforms' => array_values((array) ($w['platforms'] ?? ['facebook', 'instagram'])),
                        'targeting_automation' => ['advantage_audience' => !empty($w['advantage']) ? 1 : 0]];
                    if (($w['gender'] ?? 'all') !== 'all') { $targeting['genders'] = [$w['gender'] === 'men' ? 1 : 2]; }
                    if ($interets) { $targeting['flexible_spec'] = [['interests' => $interets]]; }
                    if ($bilingue && ($loc = socLocale($L)) !== null) { $targeting['locales'] = [$loc]; }
                    $as = ['name' => (string) $w['name'] . ' · ' . $shop['name'] . ($bilingue ? ' · ' . strtoupper($L) : ''), 'campaign_id' => $campaignId,
                        'status' => (string) ($w['status'] ?? 'PAUSED'), 'billing_event' => 'IMPRESSIONS', 'optimization_goal' => (string) $w['goal'],
                        'start_time' => socIso((string) $w['start'], '07:00'), 'end_time' => socIso((string) $w['end'], '22:00'),
                        'dsa_beneficiary' => (string) ($w['dsaB'] ?? ''), 'dsa_payor' => (string) ($w['dsaP'] ?? ''), 'targeting' => $targeting];
                    if (!empty($w['dest']) && $w['dest'] !== '') { $as['destination_type'] = (string) $w['dest']; }
                    if ($parJeu > 0) { $as[$budgetKey] = socCents($parJeu); }
                    if (!$cbo) { $as['bid_strategy'] = $enchere; }
                    if (in_array((string) ($w['bid'] ?? ''), SOC_ENCHERES_MONTANT, true) && !empty($w['bidAmount'])) { $as['bid_amount'] = socCents($w['bidAmount']); }
                    if ($isLeads || ($w['objective'] ?? '') === 'OUTCOME_ENGAGEMENT') { $as['promoted_object'] = ['page_id' => $shop['ids']['page_id']]; }
                    elseif (($w['objective'] ?? '') === 'OUTCOME_SALES') { $as['promoted_object'] = ['pixel_id' => $shop['ids']['pixel_id'], 'custom_event_type' => 'PURCHASE']; }
                    // Centre d'intérêt abandonné par Meta (refus qui le nomme) : retiré du ciblage, noté, ad set renvoyé.
                    for ($essai = 0; ; $essai++) {
                        try { $r = Meta::call('POST', $act . '/adsets', $as); break; }
                        catch (MetaError $e) {
                            $refuses = $essai < 3 ? socInteretsRefuses($e) : [];
                            $retires = array_values(array_filter($interets, static fn ($i) => in_array((string) $i['id'], $refuses, true)));
                            if (!$retires) { throw $e; }
                            foreach ($retires as $i) { $retraits[(string) $i['id']] = (string) $i['name']; }
                            $interets = array_values(array_filter($interets, static fn ($i) => !in_array((string) $i['id'], $refuses, true)));
                            if ($interets) { $as['targeting']['flexible_spec'] = [['interests' => $interets]]; } else { unset($as['targeting']['flexible_spec']); }
                        }
                    }
                    $obj['adset_id'] = (string) ($r['id'] ?? '');

                } catch (MetaError $e) {
                    $obj['error'] = mb_substr($e->getMessage(), 0, 500);
                    $erreurs[] = $shop['name'] . ($bilingue ? ' ' . strtoupper($L) : '') . ' : ' . $e->getMessage();
                    socMetaObjectAdd($obj);
                    continue;
                }
                // Une créa et une pub par variante, dans le même ad set.
                foreach ($variantes as $k => $v) {
                    $o = $obj + ['variante' => $k, 'langue' => $L, 'dark' => $dark ? 1 : 0, 'post_id' => $v['post'] ?? null];
                    $lettre = count($variantes) > 1 ? ' · ' . chr(65 + $k) : '';
                    try {
                        $o['creative_id'] = socCreative($act, $w, $shop, $L, $v, $form);
                        $pub = ['name' => (string) ($w['adName'] ?? $ad['name']) . ' · ' . $shop['name'] . ($bilingue ? ' · ' . strtoupper($L) : '') . $lettre,
                            'adset_id' => $obj['adset_id'], 'creative' => ['creative_id' => $o['creative_id']], 'status' => (string) ($w['adStatus'] ?? 'ACTIVE')];
                        if (!empty($w['pixel']) && $shop['ids']['pixel_id'] !== '') {
                            $pub['tracking_specs'] = [['action.type' => ['offsite_conversion'], 'fb_pixel' => [$shop['ids']['pixel_id']]]];
                            $pub['conversion_domain'] = 'latelierby.be';
                        }
                        $r = Meta::call('POST', $act . '/ads', $pub);
                        $o['ad_id'] = (string) ($r['id'] ?? '');
                        $o['meta_status'] = 'IN_PROCESS';
                        $o = socDarkRelire($o, $shop);
                        $crees++;
                    } catch (MetaError $e) {
                        $o['error'] = mb_substr($e->getMessage(), 0, 500);
                        $erreurs[] = $shop['name'] . ($bilingue ? ' ' . strtoupper($L) : '') . $lettre . ' : ' . $e->getMessage();
                    }
                    socMetaObjectAdd($o);
                }
            }
            // Aucune pub créée sous cette campagne : elle ne reste pas chez Meta, vide (une relance en crée une nouvelle).
            if ($crees === $creesAvant && $campaignId !== '') { try { Meta::call('DELETE', $campaignId); } catch (MetaError $e) { /* vide et en pause : sans effet */ } }
        } catch (MetaError $e) {
            $erreurs[] = $nomU . ' : ' . $e->getMessage();
        }
    }
    $ad['note'] = $retraits ? 'Ciblage : ' . implode(', ', array_map(static fn ($n) => '« ' . $n . ' »', $retraits))
        . (count($retraits) > 1 ? ' retirés' : ' retiré') . ' (centre d’intérêt abandonné par Meta).' : null;
    if ($crees === 0) {
        $ad['status'] = 'Refusée';
        $ad['reason'] = 'Non créée chez Meta — ' . implode(' · ', $erreurs);
    } else {
        // Comme l'écran : en pause seulement si campagne ET pub sont créées en pause.
        $ad['status'] = ($w['status'] ?? '') === 'PAUSED' && ($w['adStatus'] ?? '') === 'PAUSED' ? 'En pause' : 'En revue';
        $ad['reason'] = $erreurs ? 'Créée partiellement — ' . implode(' · ', $erreurs) : null;
    }
    socLog(socUser(), 'Publicité', '« ' . ($ad['name'] ?? '') . ' » envoyée à Meta : ' . $crees . ' pub(s) créée(s)' . ($erreurs ? ', ' . count($erreurs) . ' échec(s)' : '') . ($ad['note'] ? ' · ' . $ad['note'] : ''));
    return $ad;
}

/** Les variantes de la créa : A (les champs de l'assistant), puis B et C (`variants`). */
function socVariantes(array $w): array
{
    $a = ['post' => $w['post'] ?? null, 'message' => (string) ($w['message'] ?? ''), 'headline' => (string) ($w['headline'] ?? ''),
        'messageNl' => (string) ($w['messageNl'] ?? ''), 'headlineNl' => (string) ($w['headlineNl'] ?? ''), 'description' => (string) ($w['description'] ?? '')];
    $out = [$a];
    foreach (array_slice(array_values((array) ($w['variants'] ?? [])), 0, 2) as $v) {
        if (!is_array($v)) { continue; }
        // Un champ laissé vide reprend celui de la variante A.
        $out[] = array_merge($a, array_filter(array_intersect_key($v, $a), static fn ($x) => $x !== null && $x !== ''));
    }
    return $out;
}

/**
 * Ce qu'un post du module apporte à une créa : ses images dans l'ordre, sa vidéo,
 * et si l'ordre est imposé (carrousel panoramique, frise : la continuité du visuel).
 */
function socCreaMedia(?array $post): array
{
    if (!$post) { return ['imgs' => [], 'video' => null, 'ordre' => false]; }
    $kind = (string) ($post['montage']['kind'] ?? '');
    if (socEstFrise($post)) {
        $imgs = array_values(array_filter(array_map('strval', (array) $post['frise']['tiles']), static fn ($u) => $u !== '' && !str_starts_with($u, 'data:')));
        return ['imgs' => $imgs, 'video' => null, 'ordre' => true];
    }
    [$imgs, $video] = socMedias($post);
    return ['imgs' => array_slice($imgs, 0, 10), 'video' => $video, 'ordre' => $kind === 'pano'];
}

/** Une image envoyée au compte publicitaire (`/adimages`), une seule fois par compte. */
function socAdImageHash(string $act, string $src): ?string
{
    static $memo = [];
    $cle = $act . '|' . $src;
    if (!array_key_exists($cle, $memo)) {
        $memo[$cle] = null;
        if (($bytes = socImageBytes($src)) !== null) {
            $r = Meta::call('POST', $act . '/adimages', ['bytes' => $bytes]);
            foreach ((array) ($r['images'] ?? []) as $img) { $memo[$cle] = (string) ($img['hash'] ?? '') ?: null; break; }
        }
    }
    return $memo[$cle];
}

/** Attente entre deux relectures chez Meta (neutralisée en recette). */
function socDormir(int $s): void
{
    if (Meta::$sleep) { (Meta::$sleep)($s); return; }
    sleep($s);
}

/** Une vidéo envoyée au compte publicitaire (`/advideos`), attendue jusqu'à ce que Meta l'ait traitée. */
function socAdVideo(string $act, string $src): string
{
    static $memo = [];
    $cle = $act . '|' . $src;
    if (isset($memo[$cle])) { return $memo[$cle]; }
    $r = Meta::call('POST', $act . '/advideos', ['file_url' => socPublicUrl($src), 'name' => basename($src)]);
    $vid = (string) ($r['id'] ?? '');
    if ($vid === '') { throw new MetaError('Meta n’a pas accepté la vidéo', 100); }
    for ($i = 0; $i < 30; $i++) {
        $st = Meta::call('GET', $vid, ['fields' => 'status']);
        $etat = (string) ($st['status']['video_status'] ?? 'ready');
        if ($etat === 'ready') { return $memo[$cle] = $vid; }
        if ($etat === 'error') { throw new MetaError('Meta n’a pas pu traiter la vidéo', 100); }
        socDormir(3);
    }
    throw new MetaError('la vidéo est encore en traitement chez Meta : relancez dans quelques minutes', 2, 0, true);
}

/**
 * La créa d'une variante, pour une boutique et une langue.
 *  - « Sponsoriser un post existant » : le post publié sur la Page, ou un dark post
 *    déjà diffusé par une autre pub (ses likes et commentaires se cumulent) ;
 *  - « Dark post » : `object_story_spec` — image (`link_data.image_hash`), carrousel
 *    (`link_data.child_attachments`, ordre verrouillé pour un panorama ou une frise)
 *    ou vidéo (`video_data`) selon le post du module.
 */
function socCreative(string $act, array $w, array $shop, string $L, array $v, array $form): string
{
    $pid = (string) $shop['ids']['page_id'];
    if ($pid === '') { throw new MetaError('page_id non renseigné', 100); }
    $post = !empty($v['post']) ? socItem('posts', (string) $v['post']) : null;
    $nom = static fn (string $t) => 'Créa · ' . ($t !== '' ? $t : (string) ($post['title'] ?? ''));
    if (($w['crea'] ?? 'existing') === 'existing') {
        $story = '';
        if ($post && !empty($post['dark'])) {
            $story = socDarkStory((string) $post['id'], (string) $shop['id'], $L);
            if ($story === '') { throw new MetaError('ce dark post n’a pas encore été diffusé pour ' . $shop['name'] . ' : choisissez « Dark post » pour le créer', 100); }
        } else {
            $fbPost = $post ? (socPostMetaIds((string) $post['id'])[$shop['id']] ?? '') : '';
            if ($fbPost === '') { throw new MetaError('le post choisi n’est pas (encore) publié sur la Page de ' . $shop['name'], 100); }
            $story = str_contains($fbPost, '_') ? $fbPost : $pid . '_' . $fbPost;
        }
        $r = Meta::call('POST', $act . '/adcreatives', array_filter(['name' => $nom(''), 'object_story_id' => $story, 'url_tags' => (string) ($w['urlTags'] ?? '')]));
        return (string) ($r['id'] ?? '');
    }

    $nl = $L === 'nl';
    $msg = $nl && trim((string) ($v['messageNl'] ?? '')) !== '' ? (string) $v['messageNl'] : (string) ($v['message'] ?? '');
    $titre = $nl && trim((string) ($v['headlineNl'] ?? '')) !== '' ? (string) $v['headlineNl'] : (string) ($v['headline'] ?? '');
    $desc = (string) ($v['description'] ?? '');
    $url = (string) (($w['link'] ?? '') ?: 'https://www.latelierby.be');
    $cta = ['type' => (string) ($w['cta'] ?? 'LEARN_MORE')];
    if (($w['objective'] ?? '') === 'OUTCOME_LEADS') {
        $fid = $form[$shop['id']] ?? '';
        if ($fid === '') { throw new MetaError('le formulaire de leads n’est pas publié sur la Page de ' . $shop['name'], 100); }
        $cta['value'] = ['lead_gen_form_id' => $fid];
    } else {
        $cta['value'] = ['link' => $url];
    }
    $texte = socFill($msg, $shop, null, $L);
    $media = socCreaMedia($post);
    $spec = ['page_id' => $pid];
    if (($ig = socIgPub($shop)) !== '') { $spec['instagram_user_id'] = $ig; }

    if ($media['video'] !== null) {
        $vid = socAdVideo($act, $media['video']);
        $vd = ['video_id' => $vid, 'message' => $texte, 'title' => $titre, 'link_description' => $desc, 'call_to_action' => $cta];
        $couv = $media['imgs'][0] ?? null;
        if ($couv !== null && ($h = socAdImageHash($act, $couv)) !== null) { $vd['image_hash'] = $h; }
        else {
            $th = Meta::call('GET', $vid . '/thumbnails', []);
            $uri = '';
            foreach ((array) ($th['data'] ?? []) as $t) { if (!empty($t['is_preferred']) || $uri === '') { $uri = (string) ($t['uri'] ?? ''); } }
            if ($uri === '') { throw new MetaError('vignette de la vidéo indisponible : ajoutez une photo de couverture au post', 100); }
            $vd['image_url'] = $uri;
        }
        $spec['video_data'] = array_filter($vd, static fn ($x) => $x !== '');
    } elseif (count($media['imgs']) > 1) {
        $cartes = [];
        foreach ($media['imgs'] as $u) {
            $h = socAdImageHash($act, $u);
            if ($h === null) { throw new MetaError('une image du carrousel est introuvable sur le serveur (' . basename($u) . ')', 100); }
            $cartes[] = array_filter(['link' => $url, 'image_hash' => $h, 'name' => $titre, 'description' => $desc, 'call_to_action' => $cta], static fn ($x) => $x !== '');
        }
        // Panorama ou frise : Meta ne réordonne pas les cartes et n'ajoute pas de carte de fin.
        $spec['link_data'] = ['link' => $url, 'message' => $texte, 'child_attachments' => $cartes,
            'multi_share_optimized' => !$media['ordre'], 'multi_share_end_card' => !$media['ordre'], 'call_to_action' => $cta];
    } else {
        $link = ['link' => $url, 'message' => $texte, 'name' => $titre, 'description' => $desc, 'call_to_action' => $cta];
        if (isset($media['imgs'][0]) && ($h = socAdImageHash($act, $media['imgs'][0])) !== null) { $link['image_hash'] = $h; }
        $spec['link_data'] = $link;
    }
    $r = Meta::call('POST', $act . '/adcreatives', array_filter(['name' => $nom($titre), 'object_story_spec' => $spec, 'url_tags' => (string) ($w['urlTags'] ?? '')]));
    return (string) ($r['id'] ?? '');
}

/**
 * L'identité Instagram d'une pub : le compte de la boutique ; sans compte à elle, quand Instagram est unique
 * au réseau, celui du réseau (comme pour les publications, src/publish.php). Vide : Meta prend la Page.
 */
function socIgPub(array $shop): string
{
    $ig = (string) ($shop['ids']['instagram_user_id'] ?? '');
    if ($ig === '' && socIgUnique()) { $ig = (string) (socBrand()['ids']['instagram_user_id'] ?? ''); }
    return $ig;
}

function socMetaObjectAdd(array $o): void
{
    Db::exec('INSERT INTO social_meta_objet (kind, local_id, shop_id, account, campaign_id, adset_id, creative_id, ad_id, form_id, meta_status, error, created_at,
                variante, post_id, langue, dark, story_id, ig_media_id, lien, lien_ig)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$o['kind'], $o['local_id'], $o['shop_id'] ?? null, $o['account'] ?? null, $o['campaign_id'] ?? null, $o['adset_id'] ?? null,
         $o['creative_id'] ?? null, $o['ad_id'] ?? null, $o['form_id'] ?? null, $o['meta_status'] ?? null, $o['error'] ?? null, date('Y-m-d H:i:s'),
         $o['variante'] ?? null, $o['post_id'] ?? null, $o['langue'] ?? null, $o['dark'] ?? null, $o['story_id'] ?? null, $o['ig_media_id'] ?? null,
         $o['lien'] ?? null, $o['lien_ig'] ?? null]);
}

function socMetaObjects(string $kind, string $localId): array
{
    return Db::rows('SELECT * FROM social_meta_objet WHERE kind = ? AND local_id = ? ORDER BY id', [$kind, $localId]);
}

/**
 * Une pub enregistrée par l'écran. Nouvelle → créée chez Meta. Statut changé
 * (pause, reprise, arrêt) → appliqué chez Meta. Plafond relevé → `spend_cap`.
 * Formulaire de leads remplacé → nouvelle créa sur chaque pub.
 */
function socAdHook(?array $avant, array $ad): array
{
    if ($avant === null) { return socAdCreate($ad); }
    // Campagne récupérée chez Meta (src/metacampagnes.php) : pilotée au niveau de la campagne.
    if (($avant['origin'] ?? '') === 'meta') { return socCampagneMetaHook($avant, $ad); }
    // Les champs qui viennent de Meta restent ceux du serveur.
    foreach (['spent', 'reason', 'spec', 'stats'] as $k) { if (array_key_exists($k, $avant)) { $ad[$k] = $avant[$k]; } }
    $objs = array_filter(socMetaObjects('ad', (string) $ad['id']), static fn ($o) => $o['ad_id']);
    if (!$objs) { return $ad; }
    $s = (string) ($ad['status'] ?? ''); $sAvant = (string) ($avant['status'] ?? '');
    $erreurs = [];
    if ($s !== $sAvant && isset(SOC_AD_STATUT[$s])) {
        $cible = SOC_AD_STATUT[$s];
        $camps = [];
        foreach ($objs as $o) {
            try {
                if ($cible === 'ARCHIVED') { $camps[(string) $o['campaign_id']] = true; continue; }
                Meta::call('POST', (string) $o['ad_id'], ['status' => $cible]);
                if ($cible === 'ACTIVE') { $camps[(string) $o['campaign_id']] = true; Meta::call('POST', (string) $o['adset_id'], ['status' => 'ACTIVE']); }
            } catch (MetaError $e) { $erreurs[] = $e->getMessage(); }
        }
        foreach (array_keys($camps) as $cid) {
            try { Meta::call('POST', $cid, ['status' => $cible]); } catch (MetaError $e) { $erreurs[] = $e->getMessage(); }
        }
    }
    if ((float) ($ad['cap'] ?? 0) !== (float) ($avant['cap'] ?? 0) && (float) ($ad['cap'] ?? 0) > 0) {
        $camps = array_values(array_unique(array_column($objs, 'campaign_id')));
        foreach ($camps as $cid) {
            try { Meta::call('POST', (string) $cid, ['spend_cap' => socCents(((float) $ad['cap']) / max(1, count($camps)))]); } catch (MetaError $e) { $erreurs[] = $e->getMessage(); }
        }
    }
    if (($ad['leadForm'] ?? null) !== ($avant['leadForm'] ?? null) && !empty($ad['leadForm']) && !empty($ad['spec'])) {
        $shops = []; foreach (socShops() as $sh) { $shops[$sh['id']] = $sh; }
        $form = socLeadformMetaIds((string) $ad['leadForm']);
        $w = (array) $ad['spec']; $w['leadForm'] = $ad['leadForm'];
        $variantes = socVariantes($w);
        foreach ($objs as $o) {
            $shop = $shops[(string) $o['shop_id']] ?? null;
            if (!$shop) { continue; }
            try {
                $v = $variantes[(int) ($o['variante'] ?? 0)] ?? $variantes[0];
                $cr = socCreative((string) $o['account'], $w, $shop, (string) ($o['langue'] ?: ($shop['langs'][0] ?? 'fr')), $v, $form);
                Meta::call('POST', (string) $o['ad_id'], ['creative' => ['creative_id' => $cr]]);
                Db::exec('UPDATE social_meta_objet SET creative_id = ? WHERE id = ?', [$cr, $o['id']]);
            } catch (MetaError $e) { $erreurs[] = $shop['name'] . ' : ' . $e->getMessage(); }
        }
    }
    if ($erreurs) {
        $ad['reason'] = 'Meta : ' . implode(' · ', $erreurs);
        socLog('Système', 'Échec', '« ' . ($ad['name'] ?? '') . ' » : ' . $ad['reason']);
    }
    return $ad;
}

/** Statut Meta (effective_status) → statut de l'écran. */
function socAdStatutEcran(string $eff): string
{
    return match ($eff) {
        'ACTIVE' => 'Active',
        'PENDING_REVIEW', 'IN_PROCESS', 'PREAPPROVED', 'PENDING_BILLING_INFO' => 'En revue',
        'DISAPPROVED', 'WITH_ISSUES' => 'Refusée',
        'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED' => 'En pause',
        'ARCHIVED', 'DELETED' => 'Arrêtée',
        default => '',
    };
}

/** Fonction de répartition de la loi normale centrée réduite (Abramowitz et Stegun 26.2.17). */
function socPhi(float $z): float
{
    $t = 1 / (1 + 0.2316419 * abs($z));
    $d = 0.3989422804014327 * exp(-$z * $z / 2);
    $p = $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));
    return $z >= 0 ? 1 - $p : $p;
}

/**
 * Le verdict A/B des variantes d'une pub : la variante au meilleur taux de clic face à
 * chacune des autres (test de deux proportions, unilatéral). Trop tôt sous 500
 * impressions ou 20 clics par variante ; gagnante à 95 % face à toutes ; tendance à 80 %.
 * Rend null sans au moins deux variantes.
 */
function socAbVerdict(array $stats): ?array
{
    $v = array_values(array_filter($stats, static fn ($x) => is_array($x) && isset($x['variant'])));
    if (count($v) < 2) { return null; }
    $pct = static fn (float $x) => number_format($x * 100, 1, ',', '') . ' %';
    $taux = static fn ($x) => (int) $x['impressions'] > 0 ? (int) $x['clicks'] / (int) $x['impressions'] : 0.0;
    $minImp = min(array_map(static fn ($x) => (int) $x['impressions'], $v));
    $minClics = min(array_map(static fn ($x) => (int) $x['clicks'], $v));
    if ($minImp < 500 || $minClics < 20) {
        return ['statut' => 'tot', 'variante' => null, 'confiance' => null,
            'texte' => 'Trop tôt pour conclure : il faut au moins 500 impressions et 20 clics par variante (aujourd’hui ' . number_format($minImp, 0, ',', ' ') . ' impressions et ' . $minClics . ' clics pour la moins servie).'];
    }
    usort($v, static fn ($a, $b) => $taux($b) <=> $taux($a));
    $m = $v[0]; $p1 = $taux($m); $n1 = (int) $m['impressions'];
    $conf = 1.0; $second = $v[1];
    foreach (array_slice($v, 1) as $x) {
        $p2 = $taux($x); $n2 = (int) $x['impressions'];
        $p = ((int) $m['clicks'] + (int) $x['clicks']) / ($n1 + $n2);
        $se = sqrt(max($p * (1 - $p) * (1 / $n1 + 1 / $n2), 1e-12));
        $c = socPhi(($p1 - $p2) / $se);
        if ($c < $conf) { $conf = $c; $second = $x; }
    }
    $p2 = $taux($second);
    $ecart = $p2 > 0 ? '+' . number_format(($p1 / $p2 - 1) * 100, 0, ',', '') . ' %' : '';
    $face = 'taux de clic ' . $pct($p1) . ' contre ' . $pct($p2) . ' pour ' . $second['variant'] . ($ecart !== '' ? ' (' . $ecart . ')' : '');
    if ($conf >= 0.95) {
        return ['statut' => 'gagnante', 'variante' => $m['variant'], 'confiance' => round($conf, 3),
            'texte' => 'Variante ' . $m['variant'] . ' gagnante : ' . $face . ', sûr à ' . floor($conf * 100) . ' %. Reprenez son visuel et son texte pour la suite.'];
    }
    if ($conf >= 0.80) {
        return ['statut' => 'tendance', 'variante' => $m['variant'], 'confiance' => round($conf, 3),
            'texte' => 'Variante ' . $m['variant'] . ' devant sans certitude : ' . $face . ', sûr à ' . floor($conf * 100) . ' % seulement. Laissez tourner.'];
    }
    return ['statut' => 'egalite', 'variante' => null, 'confiance' => round($conf, 3),
        'texte' => 'Pas d’écart net entre les variantes (' . $face . ', sûr à ' . floor($conf * 100) . ' % seulement).'];
}

/**
 * Relit chez Meta l'état et la dépense de chaque pub (cron horaire) :
 * effective_status, motif de refus, dépense cumulée → « Budget épuisé »
 * au plafond, « Terminée » après la date de fin.
 */
function socAdsSync(): int
{
    if (!Meta::configured()) { return 0; }
    $n = 0;
    foreach (socItems('ads') as $ad) {
        if (($ad['origin'] ?? '') === 'meta') { continue; }  // relues campagne par campagne (socCampagnesMetaImporter)
        $objs = array_filter(socMetaObjects('ad', (string) $ad['id']), static fn ($o) => $o['ad_id']);
        if (!$objs) { continue; }
        $statuts = []; $motifs = []; $spent = 0.0; $lu = false; $parVariante = [];
        $shops = null;
        foreach ($objs as $o) {
            try {
                $r = Meta::call('GET', (string) $o['ad_id'], ['fields' => 'effective_status,ad_review_feedback']);
                $eff = (string) ($r['effective_status'] ?? '');
                $statuts[] = socAdStatutEcran($eff);
                Db::exec('UPDATE social_meta_objet SET meta_status = ? WHERE id = ?', [$eff, $o['id']]);
                foreach ((array) ($r['ad_review_feedback']['global'] ?? []) as $k => $v) { $motifs[] = is_string($v) ? $v : (string) $k; }
                $i = Meta::call('GET', (string) $o['ad_id'] . '/insights', ['fields' => 'spend,impressions,clicks', 'date_preset' => 'maximum']);
                $row = (array) ($i['data'][0] ?? []);
                $spent += (float) ($row['spend'] ?? 0);
                $k = (int) ($o['variante'] ?? 0);
                $parVariante[$k] ??= ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0];
                $parVariante[$k]['spend'] += (float) ($row['spend'] ?? 0);
                $parVariante[$k]['impressions'] += (int) ($row['impressions'] ?? 0);
                $parVariante[$k]['clicks'] += (int) ($row['clicks'] ?? 0);
                $lu = true;
                // Le dark post (et son pendant Instagram) apparaît parfois après la revue de la pub.
                if ((int) ($o['dark'] ?? 0) === 1 && (empty($o['story_id']) || empty($o['ig_media_id'])) && !empty($o['creative_id'])) {
                    if ($shops === null) { $shops = []; foreach (socShops() as $sh) { $shops[$sh['id']] = $sh; } }
                    if (isset($shops[(string) $o['shop_id']])) {
                        $n2 = socDarkRelire($o, $shops[(string) $o['shop_id']]);
                        Db::exec('UPDATE social_meta_objet SET story_id = ?, ig_media_id = ?, lien = ?, lien_ig = ? WHERE id = ?',
                            [$n2['story_id'] ?? null, $n2['ig_media_id'] ?? null, $n2['lien'] ?? null, $n2['lien_ig'] ?? null, $o['id']]);
                    }
                }
            } catch (MetaError $e) { /* une pub illisible n'empêche pas les autres */ }
        }
        if (count($parVariante) > 1 || isset($ad['stats'])) {
            ksort($parVariante);
            $ad['stats'] = array_values(array_map(static fn ($k, $x) => ['variant' => chr(65 + $k), 'spend' => round($x['spend'], 2), 'impressions' => $x['impressions'],
                'clicks' => $x['clicks'], 'ctr' => $x['impressions'] ? round($x['clicks'] / $x['impressions'], 4) : null], array_keys($parVariante), $parVariante));
            $ad['ab'] = socAbVerdict($ad['stats']);
        }
        if (!$lu) { continue; }
        $statuts = array_filter($statuts);
        $s = in_array('Active', $statuts, true) ? 'Active' : (in_array('En revue', $statuts, true) ? 'En revue'
            : (in_array('Refusée', $statuts, true) ? 'Refusée' : (in_array('En pause', $statuts, true) ? 'En pause' : ($statuts ? 'Arrêtée' : ($ad['status'] ?? '')))));
        $cap = (float) ($ad['cap'] ?? 0);
        if ($cap > 0 && $spent >= $cap * 0.99 && $s !== 'Arrêtée') { $s = 'Budget épuisé'; }
        if (($ad['end'] ?? '9999') < date('Y-m-d') && in_array($s, ['Active', 'Budget épuisé', 'En pause'], true)) { $s = 'Terminée'; }
        $avant = (string) ($ad['status'] ?? '');
        $ad['spent'] = round($spent, 2);
        if ($s !== '') { $ad['status'] = $s; }
        if ($s === 'Refusée' && $motifs) { $ad['reason'] = implode(' · ', array_unique($motifs)); }
        socItemPut('ads', $ad);
        if ($s !== $avant) { socLog('Système', 'Publicité', '« ' . ($ad['name'] ?? '') . ' » : ' . $avant . ' → ' . $s); }
        $n++;
    }
    return $n;
}
