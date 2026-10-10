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
 *  - boutique bilingue : un ad set et une créa par langue (`targeting.locales`).
 *
 * L'assistant de l'écran envoie son état complet (`spec`) ; le serveur en
 * tire les appels, résout ce que l'écran ne peut pas savoir (ids des centres
 * d'intérêt et des langues par Targeting Search, `image_hash`, id du post
 * publié) et note chaque objet créé dans `social_meta_objet`.
 */

const SOC_AD_STATUT = ['Active' => 'ACTIVE', 'En pause' => 'PAUSED', 'Arrêtée' => 'ARCHIVED'];

/** Montant en centimes : 20 € → 2000. */
function socCents(mixed $eur): int { return (int) round(((float) $eur) * 100); }

/** Une date de l'écran (AAAA-MM-JJ) et une heure, à l'heure de Bruxelles, en ISO 8601. */
function socIso(string $jour, string $heure): string
{
    $d = new DateTimeImmutable($jour . ' ' . $heure, new DateTimeZone('Europe/Brussels'));
    return $d->format('Y-m-d\TH:i:sO');
}

/** Les ids Meta des centres d'intérêt, par nom (Targeting Search, mémorisé). */
function socInterets(array $noms): array
{
    $memo = (array) (socSetting('meta_cache_interets') ?? []);
    $out = [];
    foreach ($noms as $n) {
        $n = (string) $n;
        if (!isset($memo[$n])) {
            try {
                $r = Meta::call('GET', 'search', ['type' => 'adinterest', 'q' => $n, 'locale' => 'fr_FR', 'limit' => 1]);
                $hit = $r['data'][0] ?? null;
                $memo[$n] = $hit ? ['id' => (string) $hit['id'], 'name' => (string) $hit['name']] : null;
            } catch (MetaError $e) { $memo[$n] = null; }
        }
        if ($memo[$n]) { $out[] = $memo[$n]; }
    }
    socSettingPut('meta_cache_interets', $memo);
    return $out;
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
    $erreurs = []; $crees = 0;
    $interets = !empty($w['interests']) ? socInterets((array) $w['interests']) : [];
    $post = !empty($w['post']) ? socItem('posts', (string) $w['post']) : null;
    $postIds = $post ? socPostMetaIds((string) $post['id']) : [];
    $form = $isLeads && !empty($w['leadForm']) ? socLeadformMetaIds((string) $w['leadForm']) : [];

    // Les unités : [compte, [boutiques]] — une par boutique, ou une seule réseau.
    $unites = $reseau ? [[socActId(socBrand()['ids']['ad_account_id']), $cibles]]
        : array_map(static fn ($id) => [socActId((string) $shops[$id]['ids']['ad_account_id']), [$id]], $cibles);

    foreach ($unites as [$act, $ids]) {
        $nomU = $reseau ? 'réseau' : $shops[$ids[0]]['name'];
        try {
            if ($act === '') { throw new MetaError($reseau ? 'compte publicitaire réseau non renseigné (Paramètres → API Meta)' : 'ad_account_id non renseigné', 100); }
            $camp = ['name' => (string) $w['name'] . ($reseau ? '' : ' · ' . $nomU), 'objective' => (string) $w['objective'], 'status' => (string) ($w['status'] ?? 'PAUSED'),
                'special_ad_categories' => array_values((array) ($w['special'] ?? [])), 'buying_type' => 'AUCTION',
                'bid_strategy' => (string) ($w['bid'] ?? 'LOWEST_COST_WITHOUT_CAP')];
            if (!empty($w['spendCap'])) { $camp['spend_cap'] = socCents(((float) $w['spendCap']) / $nCamp); }
            if (($w['budgetLevel'] ?? 'adset') === 'campaign') { $camp[$budgetKey] = socCents($w['amount']); }
            else { $camp['is_adset_budget_sharing_enabled'] = false; }
            $c = Meta::call('POST', $act . '/campaigns', $camp);
            $campaignId = (string) ($c['id'] ?? '');

            // Un ad set (et une créa, une pub) par boutique et par langue.
            $jeux = [];
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
                    if (preg_match('/CAP|ROAS/', (string) ($w['bid'] ?? '')) && !empty($w['bidAmount'])) { $as['bid_amount'] = socCents($w['bidAmount']); }
                    if ($isLeads || ($w['objective'] ?? '') === 'OUTCOME_ENGAGEMENT') { $as['promoted_object'] = ['page_id' => $shop['ids']['page_id']]; }
                    elseif (($w['objective'] ?? '') === 'OUTCOME_SALES') { $as['promoted_object'] = ['pixel_id' => $shop['ids']['pixel_id'], 'custom_event_type' => 'PURCHASE']; }
                    $r = Meta::call('POST', $act . '/adsets', $as);
                    $obj['adset_id'] = (string) ($r['id'] ?? '');

                    $obj['creative_id'] = socCreative($act, $w, $shop, $L, $post, $postIds, $form);
                    $pub = ['name' => (string) ($w['adName'] ?? $ad['name']) . ' · ' . $shop['name'] . ($bilingue ? ' · ' . strtoupper($L) : ''),
                        'adset_id' => $obj['adset_id'], 'creative' => ['creative_id' => $obj['creative_id']], 'status' => (string) ($w['adStatus'] ?? 'ACTIVE')];
                    if (!empty($w['pixel']) && $shop['ids']['pixel_id'] !== '') {
                        $pub['tracking_specs'] = [['action.type' => ['offsite_conversion'], 'fb_pixel' => [$shop['ids']['pixel_id']]]];
                        $pub['conversion_domain'] = 'latelierby.be';
                    }
                    $r = Meta::call('POST', $act . '/ads', $pub);
                    $obj['ad_id'] = (string) ($r['id'] ?? '');
                    $obj['meta_status'] = 'IN_PROCESS';
                    $crees++;
                } catch (MetaError $e) {
                    $obj['error'] = mb_substr($e->getMessage(), 0, 500);
                    $erreurs[] = $shop['name'] . ($bilingue ? ' ' . strtoupper($L) : '') . ' : ' . $e->getMessage();
                }
                socMetaObjectAdd($obj);
            }
        } catch (MetaError $e) {
            $erreurs[] = $nomU . ' : ' . $e->getMessage();
        }
    }
    if ($crees === 0) {
        $ad['status'] = 'Refusée';
        $ad['reason'] = 'Non créée chez Meta — ' . implode(' · ', $erreurs);
    } else {
        // Comme l'écran : en pause seulement si campagne ET pub sont créées en pause.
        $ad['status'] = ($w['status'] ?? '') === 'PAUSED' && ($w['adStatus'] ?? '') === 'PAUSED' ? 'En pause' : 'En revue';
        $ad['reason'] = $erreurs ? 'Créée partiellement — ' . implode(' · ', $erreurs) : null;
    }
    socLog(socUser(), 'Publicité', '« ' . ($ad['name'] ?? '') . ' » envoyée à Meta : ' . $crees . ' pub(s) créée(s)' . ($erreurs ? ', ' . count($erreurs) . ' échec(s)' : ''));
    return $ad;
}

/** La créa d'une boutique dans une langue : post sponsorisé, ou nouvelle créa avec son image. */
function socCreative(string $act, array $w, array $shop, string $L, ?array $post, array $postIds, array $form): string
{
    $pid = (string) $shop['ids']['page_id'];
    if ($pid === '') { throw new MetaError('page_id non renseigné', 100); }
    if (($w['crea'] ?? 'existing') === 'existing') {
        $fbPost = $postIds[$shop['id']] ?? '';
        if ($fbPost === '') { throw new MetaError('le post choisi n’est pas (encore) publié sur la Page de ' . $shop['name'], 100); }
        $objectStory = str_contains($fbPost, '_') ? $fbPost : $pid . '_' . $fbPost;
        $r = Meta::call('POST', $act . '/adcreatives', array_filter(['name' => 'Créa · ' . ($post['title'] ?? ''), 'object_story_id' => $objectStory, 'url_tags' => (string) ($w['urlTags'] ?? '')]));
        return (string) ($r['id'] ?? '');
    }
    $src = '';
    if ($post) { [$imgs] = socMedias($post); $src = $imgs[0] ?? ''; }
    $hash = null;
    if ($src !== '' && ($bytes = socImageBytes($src)) !== null) {
        $r = Meta::call('POST', $act . '/adimages', ['bytes' => $bytes]);
        foreach ((array) ($r['images'] ?? []) as $img) { $hash = (string) ($img['hash'] ?? ''); break; }
    }
    $nl = $L === 'nl';
    $msg = $nl && trim((string) ($w['messageNl'] ?? '')) !== '' ? (string) $w['messageNl'] : (string) ($w['message'] ?? '');
    $titre = $nl && trim((string) ($w['headlineNl'] ?? '')) !== '' ? (string) $w['headlineNl'] : (string) ($w['headline'] ?? '');
    $isLeads = ($w['objective'] ?? '') === 'OUTCOME_LEADS';
    $cta = ['type' => (string) ($w['cta'] ?? 'LEARN_MORE')];
    if ($isLeads) {
        $fid = $form[$shop['id']] ?? '';
        if ($fid === '') { throw new MetaError('le formulaire de leads n’est pas publié sur la Page de ' . $shop['name'], 100); }
        $cta['value'] = ['lead_gen_form_id' => $fid];
    } else {
        $cta['value'] = ['link' => (string) ($w['link'] ?? '')];
    }
    $link = ['link' => (string) (($w['link'] ?? '') ?: 'https://www.latelierby.be'), 'message' => socFill($msg, $shop, null, $L), 'name' => $titre,
        'description' => (string) ($w['description'] ?? ''), 'call_to_action' => $cta];
    if ($hash) { $link['image_hash'] = $hash; }
    $spec = ['page_id' => $pid, 'link_data' => $link];
    if ($shop['ids']['instagram_user_id'] !== '') { $spec['instagram_user_id'] = $shop['ids']['instagram_user_id']; }
    $r = Meta::call('POST', $act . '/adcreatives', array_filter(['name' => 'Créa · ' . ($titre ?: ($post['title'] ?? '')), 'object_story_spec' => $spec, 'url_tags' => (string) ($w['urlTags'] ?? '')]));
    return (string) ($r['id'] ?? '');
}

function socMetaObjectAdd(array $o): void
{
    Db::exec('INSERT INTO social_meta_objet (kind, local_id, shop_id, account, campaign_id, adset_id, creative_id, ad_id, form_id, meta_status, error, created_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$o['kind'], $o['local_id'], $o['shop_id'] ?? null, $o['account'] ?? null, $o['campaign_id'] ?? null, $o['adset_id'] ?? null,
         $o['creative_id'] ?? null, $o['ad_id'] ?? null, $o['form_id'] ?? null, $o['meta_status'] ?? null, $o['error'] ?? null, date('Y-m-d H:i:s')]);
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
    // Les champs qui viennent de Meta restent ceux du serveur.
    foreach (['spent', 'reason', 'spec'] as $k) { if (array_key_exists($k, $avant)) { $ad[$k] = $avant[$k]; } }
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
        foreach ($objs as $o) {
            $shop = $shops[(string) $o['shop_id']] ?? null;
            if (!$shop) { continue; }
            try {
                $cr = socCreative((string) $o['account'], $w, $shop, $shop['langs'][0] ?? 'fr', null, [], $form);
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
        $objs = array_filter(socMetaObjects('ad', (string) $ad['id']), static fn ($o) => $o['ad_id']);
        if (!$objs) { continue; }
        $statuts = []; $motifs = []; $spent = 0.0; $lu = false;
        foreach ($objs as $o) {
            try {
                $r = Meta::call('GET', (string) $o['ad_id'], ['fields' => 'effective_status,ad_review_feedback']);
                $eff = (string) ($r['effective_status'] ?? '');
                $statuts[] = socAdStatutEcran($eff);
                Db::exec('UPDATE social_meta_objet SET meta_status = ? WHERE id = ?', [$eff, $o['id']]);
                foreach ((array) ($r['ad_review_feedback']['global'] ?? []) as $k => $v) { $motifs[] = is_string($v) ? $v : (string) $k; }
                $i = Meta::call('GET', (string) $o['ad_id'] . '/insights', ['fields' => 'spend', 'date_preset' => 'maximum']);
                $spent += (float) ($i['data'][0]['spend'] ?? 0);
                $lu = true;
            } catch (MetaError $e) { /* une pub illisible n'empêche pas les autres */ }
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
