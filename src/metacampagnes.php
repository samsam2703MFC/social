<?php
declare(strict_types=1);

/**
 * Les campagnes déjà présentes chez Meta — créées dans le Gestionnaire de
 * publicités, par une agence, ou avant le module — relues dans chaque compte
 * publicitaire et rangées dans « Publicités » à côté des pubs du module.
 *
 * Une campagne Meta = une pub de l'écran, d'origine « meta », d'identifiant
 * `meta-{campaign_id}`. Le serveur en est maître (nom, statut, budget, dates,
 * dépense, statistiques) ; l'écran peut la rattacher à une campagne du module
 * (pour le Retour sur campagne), la mettre en pause, la relancer, l'arrêter
 * ou relever son plafond — appliqué au niveau de la campagne Meta.
 *
 * Les campagnes créées par le module sont reconnues (social_meta_objet) et
 * jamais importées une seconde fois.
 *
 * Relu à la demande (« Récupérer les campagnes Meta ») et à chaque synchro
 * horaire. Le dernier passage est gardé dans `meta_campagnes_import`.
 */

const SOC_IMPORT_PREFIXE = 'meta-';
/** Objectifs ODAX → libellé de l'écran. */
const SOC_OBJECTIFS = [
    'OUTCOME_AWARENESS' => 'Notoriété', 'OUTCOME_TRAFFIC' => 'Trafic', 'OUTCOME_ENGAGEMENT' => 'Interactions',
    'OUTCOME_LEADS' => 'Prospects', 'OUTCOME_APP_PROMOTION' => 'Promotion d’application', 'OUTCOME_SALES' => 'Ventes',
    // Anciens objectifs (campagnes d'avant 2023).
    'BRAND_AWARENESS' => 'Notoriété', 'REACH' => 'Couverture', 'LINK_CLICKS' => 'Trafic', 'POST_ENGAGEMENT' => 'Interactions',
    'PAGE_LIKES' => 'J’aime la Page', 'EVENT_RESPONSES' => 'Réponses à un évènement', 'VIDEO_VIEWS' => 'Vues de vidéo',
    'LEAD_GENERATION' => 'Prospects', 'MESSAGES' => 'Messages', 'CONVERSIONS' => 'Conversions', 'STORE_VISITS' => 'Visites en magasin',
    'PRODUCT_CATALOG_SALES' => 'Ventes catalogue', 'APP_INSTALLS' => 'Installations d’application',
];
/** account_status → libellé (1 = actif). */
const SOC_COMPTE_STATUTS = [1 => 'actif', 2 => 'désactivé', 3 => 'impayé', 7 => 'en examen (risque)', 8 => 'règlement en attente',
    9 => 'période de grâce', 100 => 'fermeture en cours', 101 => 'fermé', 201 => 'actif', 202 => 'fermé'];

/**
 * Les comptes publicitaires à relire : ceux du portefeuille (tout ce que voit
 * le System User), plus ceux saisis pour la marque et chaque boutique.
 * Rend act_… → ['id', 'name', 'devise', 'statut', 'boutique' => id|marque|null].
 */
function socComptesPub(): array
{
    $out = [];
    $pf = socSetting('meta_portefeuille');
    foreach ((array) (is_array($pf) ? ($pf['comptes'] ?? []) : []) as $a) {
        $id = socActId((string) ($a['id'] ?? ''));
        if ($id !== '') { $out[$id] = ['id' => $id, 'name' => (string) ($a['name'] ?? ''), 'devise' => (string) ($a['devise'] ?? ''), 'statut' => (int) ($a['statut'] ?? 0), 'boutique' => null]; }
    }
    $brand = socActId((string) (socBrand()['ids']['ad_account_id'] ?? ''));
    if ($brand !== '') { $out[$brand] = ($out[$brand] ?? ['id' => $brand, 'name' => '', 'devise' => '', 'statut' => 0]) + ['boutique' => null]; $out[$brand]['boutique'] = 'marque'; }
    foreach (socShops() as $s) {
        $id = socActId((string) ($s['ids']['ad_account_id'] ?? ''));
        if ($id === '') { continue; }
        $out[$id] = ($out[$id] ?? ['id' => $id, 'name' => '', 'devise' => '', 'statut' => 0, 'boutique' => null]);
        $out[$id]['boutique'] = (string) $s['id'];
    }
    // Rien de connu : ce que le token voit directement.
    if (!$out && Meta::configured()) {
        try {
            foreach (socGraphTout('me/adaccounts', ['fields' => 'id,name,account_status,currency', 'limit' => 100]) as $a) {
                $id = socActId((string) ($a['id'] ?? ''));
                if ($id !== '') { $out[$id] = ['id' => $id, 'name' => (string) ($a['name'] ?? ''), 'devise' => (string) ($a['currency'] ?? ''), 'statut' => (int) ($a['account_status'] ?? 0), 'boutique' => null]; }
            }
        } catch (MetaError $e) { /* signalé plus bas : aucun compte */ }
    }
    return $out;
}

/** Montant Meta (unités mineures, en texte) → montant de l'écran. */
function socMontantMeta(mixed $v): float { return is_numeric($v) ? round(((float) $v) / 100, 2) : 0.0; }

/** Les campagnes déjà créées par le module (jamais réimportées). */
function socCampagnesDuModule(): array
{
    $ids = [];
    foreach (Db::rows("SELECT DISTINCT campaign_id FROM social_meta_objet WHERE kind = 'ad' AND campaign_id IS NOT NULL AND local_id NOT LIKE ?", [SOC_IMPORT_PREFIXE . '%']) as $r) {
        $ids[(string) $r['campaign_id']] = true;
    }
    return $ids;
}

/**
 * Relit les campagnes de tous les comptes et met « Publicités » à jour.
 * Rend le compte rendu (aussi gardé dans `meta_campagnes_import`).
 */
function socCampagnesMetaImporter(bool $journal = true): array
{
    if (!Meta::configured()) { throw new HttpError(409, 'token Meta absent : saisissez-le dans Paramètres → API Meta → Clés d’accès Meta'); }
    $shops = socShops();
    $pageBoutique = [];
    foreach ($shops as $s) { if (($s['ids']['page_id'] ?? '') !== '') { $pageBoutique[(string) $s['ids']['page_id']] = (string) $s['id']; } }
    $brandPage = (string) (socBrand()['ids']['page_id'] ?? '');
    if ($brandPage !== '') { $pageBoutique[$brandPage] ??= 'marque'; }
    $duModule = socCampagnesDuModule();
    $existants = [];
    foreach (socItems('ads') as $a) { if (($a['origin'] ?? '') === 'meta') { $existants[(string) $a['id']] = $a; } }
    $limite = (new DateTimeImmutable('-13 months'))->format('Y-m-d');
    $today = date('Y-m-d');

    $comptes = socComptesPub();
    $rapport = ['le' => date('Y-m-d H:i'), 'comptes' => [], 'importees' => 0, 'majs' => 0, 'disparues' => 0, 'total' => 0, 'erreurs' => []];
    if (!$comptes) { $rapport['erreurs'][] = 'aucun compte publicitaire visible : rafraîchissez le portefeuille (Paramètres → API Meta) ou saisissez un ad_account_id'; }
    $vus = []; $comptesLus = [];

    foreach ($comptes as $act => $cpt) {
        $ligne = ['id' => $act, 'name' => $cpt['name'], 'devise' => $cpt['devise'], 'statut' => $cpt['statut'], 'campagnes' => 0, 'erreur' => null];
        try {
            $info = Meta::call('GET', $act, ['fields' => 'name,account_status,currency']);
            $ligne['name'] = (string) ($info['name'] ?? $ligne['name']);
            $ligne['devise'] = (string) ($info['currency'] ?? $ligne['devise']);
            $ligne['statut'] = (int) ($info['account_status'] ?? $ligne['statut']);

            $camps = socGraphTout($act . '/campaigns', ['fields' => 'id,name,objective,status,effective_status,daily_budget,lifetime_budget,spend_cap,start_time,stop_time,created_time,updated_time',
                'effective_status' => ['ACTIVE', 'PAUSED', 'IN_PROCESS', 'WITH_ISSUES', 'ARCHIVED'], 'limit' => 100], 300);
            $camps = array_values(array_filter($camps, static function ($c) use ($duModule, $limite) {
                if (isset($duModule[(string) ($c['id'] ?? '')])) { return false; }
                $fin = substr((string) ($c['stop_time'] ?? ''), 0, 10);
                $maj = substr((string) ($c['updated_time'] ?? ''), 0, 10);
                return ($c['effective_status'] ?? '') !== 'ARCHIVED' || max($fin, $maj) >= $limite;
            }));
            $comptesLus[$act] = true;
            if (!$camps) { $rapport['comptes'][] = $ligne; continue; }

            // Ensembles, pubs et statistiques du compte, en une lecture chacun.
            $parCamp = [];
            foreach (socGraphTout($act . '/adsets', ['fields' => 'id,campaign_id,name,effective_status,daily_budget,lifetime_budget,start_time,end_time,promoted_object,targeting{geo_locations}', 'limit' => 200], 2000) as $as) {
                $parCamp[(string) ($as['campaign_id'] ?? '')]['adsets'][] = $as;
            }
            foreach (socGraphTout($act . '/ads', ['fields' => 'id,campaign_id,adset_id,name,effective_status,ad_review_feedback,creative{id,actor_id,thumbnail_url,image_url,effective_object_story_id}', 'limit' => 200], 2000) as $ad) {
                $parCamp[(string) ($ad['campaign_id'] ?? '')]['ads'][] = $ad;
            }
            $stats = [];
            try {
                foreach (socGraphTout($act . '/insights', ['level' => 'campaign', 'date_preset' => 'maximum', 'limit' => 500,
                    'fields' => 'campaign_id,spend,impressions,reach,clicks,ctr,cpc,cpm,frequency,actions'], 2000) as $x) {
                    $stats[(string) ($x['campaign_id'] ?? '')] = $x;
                }
            } catch (MetaError $e) { $rapport['erreurs'][] = $act . ' (statistiques) : ' . $e->getMessage(); }

            foreach ($camps as $c) {
                $cid = (string) $c['id'];
                $item = socCampagneMetaVersPub($c, $parCamp[$cid] ?? [], $stats[$cid] ?? [], $ligne, $cpt, $shops, $pageBoutique, $existants[SOC_IMPORT_PREFIXE . $cid] ?? null, $today);
                socItemPut('ads', $item['pub']);
                socCampagneMetaObjets($item['pub']['id'], $act, $cid, $item['objets']);
                $vus[$item['pub']['id']] = true;
                if (isset($existants[$item['pub']['id']])) { $rapport['majs']++; } else {
                    $rapport['importees']++;
                    if ($journal) { socLog(socUser(), 'Publicité', '« ' . $item['pub']['name'] . ' » récupérée depuis Meta (' . $act . ')'); }
                }
                $ligne['campagnes']++;
            }
        } catch (MetaError $e) {
            $ligne['erreur'] = $e->getMessage();
            $rapport['erreurs'][] = $act . ' : ' . $e->getMessage();
        }
        $rapport['comptes'][] = $ligne;
    }

    // Disparues d'un compte bien relu : supprimées chez Meta (ou archivées depuis longtemps).
    foreach ($existants as $id => $a) {
        if (isset($vus[$id]) || !isset($comptesLus[(string) ($a['account'] ?? '')])) { continue; }
        if (in_array($a['status'] ?? '', ['Arrêtée', 'Terminée'], true) && !empty($a['gone'])) { continue; }
        $a['status'] = ($a['end'] ?? null) !== null && $a['end'] < $today ? 'Terminée' : 'Arrêtée';
        $a['gone'] = true;
        $a['note'] = 'Introuvable chez Meta lors du dernier rafraîchissement (supprimée ou archivée).';
        socItemPut('ads', $a);
        $rapport['disparues']++;
    }
    $rapport['total'] = $rapport['importees'] + $rapport['majs'];
    socSettingPut('meta_campagnes_import', $rapport);
    if ($journal) {
        socLog(socUser(), 'Publicité', 'Campagnes Meta relues : ' . count($rapport['comptes']) . ' compte(s), ' . $rapport['importees'] . ' nouvelle(s), '
            . $rapport['majs'] . ' mise(s) à jour' . ($rapport['disparues'] ? ', ' . $rapport['disparues'] . ' disparue(s)' : '') . ($rapport['erreurs'] ? ' — ' . count($rapport['erreurs']) . ' erreur(s)' : ''));
    }
    return $rapport;
}

/**
 * Une campagne Meta (et ses ensembles, pubs, statistiques) → la pub de l'écran
 * et ses objets Meta (une ligne par pub, avec sa boutique).
 */
function socCampagneMetaVersPub(array $c, array $enfants, array $st, array $compte, array $cpt, array $shops, array $pageBoutique, ?array $avant, string $today): array
{
    $cid = (string) $c['id'];
    $adsets = (array) ($enfants['adsets'] ?? []);
    $ads = (array) ($enfants['ads'] ?? []);
    $nomsShops = [];
    foreach ($shops as $s) { $nomsShops[(string) $s['id']] = $s; }

    // La boutique de chaque pub : sa Page, sinon le compte, sinon le nom.
    $boutiqueDe = static function (string $page, string $libelle) use ($pageBoutique, $cpt, $shops): ?string {
        if ($page !== '' && isset($pageBoutique[$page])) { return $pageBoutique[$page]; }
        if (($cpt['boutique'] ?? null) !== null) { return (string) $cpt['boutique']; }
        $g = socDevinerBoutique($libelle, $shops);
        return $g;
    };
    $objets = []; $boutiques = []; $pages = [];
    $nomsAdsets = array_column($adsets, 'name', 'id');
    $pageAdset = [];
    foreach ($adsets as $as) { if (!empty($as['promoted_object']['page_id'])) { $pageAdset[(string) $as['id']] = (string) $as['promoted_object']['page_id']; } }
    $statutsPubs = []; $motifs = []; $vignette = null;
    foreach ($ads as $ad) {
        $cr = (array) ($ad['creative'] ?? []);
        $story = (string) ($cr['effective_object_story_id'] ?? '');
        $page = (string) ($cr['actor_id'] ?? '') ?: ($story !== '' ? explode('_', $story)[0] : '') ?: ($pageAdset[(string) ($ad['adset_id'] ?? '')] ?? '');
        $sid = $boutiqueDe($page, (string) ($c['name'] ?? '') . ' ' . ($nomsAdsets[(string) ($ad['adset_id'] ?? '')] ?? '') . ' ' . ($ad['name'] ?? ''));
        if ($page !== '') { $pages[$page] = true; }
        if ($sid !== null && $sid !== 'marque') { $boutiques[$sid] = true; }
        $eff = (string) ($ad['effective_status'] ?? '');
        $statutsPubs[] = socAdStatutEcran($eff);
        foreach ((array) ($ad['ad_review_feedback']['global'] ?? []) as $k => $v) { $motifs[] = is_string($v) ? $v : (string) $k; }
        $vignette ??= ((string) ($cr['thumbnail_url'] ?? '') ?: (string) ($cr['image_url'] ?? '')) ?: null;
        $objets[] = ['shop_id' => $sid, 'adset_id' => (string) ($ad['adset_id'] ?? '') ?: null, 'creative_id' => (string) ($cr['id'] ?? '') ?: null,
            'ad_id' => (string) $ad['id'], 'meta_status' => $eff, 'story_id' => $story ?: null];
    }
    if (!$ads) {
        $sid = $boutiqueDe($pageAdset ? (string) reset($pageAdset) : '', (string) ($c['name'] ?? '') . ' ' . implode(' ', array_column($adsets, 'name')));
        if ($sid !== null && $sid !== 'marque') { $boutiques[$sid] = true; }
    }
    $ids = array_map('strval', array_keys($boutiques));  // « 4 » reste un texte (clé de tableau)
    $reseau = count($ids) !== 1;

    // Budget : celui de la campagne (CBO), sinon la somme des ensembles.
    $quotidien = null; $budget = 0.0;
    if (is_numeric($c['daily_budget'] ?? null) && (float) $c['daily_budget'] > 0) { $quotidien = true; $budget = socMontantMeta($c['daily_budget']); }
    elseif (is_numeric($c['lifetime_budget'] ?? null) && (float) $c['lifetime_budget'] > 0) { $quotidien = false; $budget = socMontantMeta($c['lifetime_budget']); }
    else {
        foreach ($adsets as $as) {
            if (($as['effective_status'] ?? '') === 'ARCHIVED') { continue; }
            if (is_numeric($as['daily_budget'] ?? null) && (float) $as['daily_budget'] > 0) { $budget += socMontantMeta($as['daily_budget']); $quotidien ??= true; }
            elseif (is_numeric($as['lifetime_budget'] ?? null) && (float) $as['lifetime_budget'] > 0) { $budget += socMontantMeta($as['lifetime_budget']); $quotidien ??= false; }
        }
    }
    // Dates : la campagne, sinon l'étendue de ses ensembles. Meta rend « 1970-01-01 » pour une date non fixée (brouillon).
    $jour = static fn (mixed $t): string => ($d = substr((string) ($t ?? ''), 0, 10)) >= '2000-01-01' ? $d : '';
    $debut = $jour($c['start_time'] ?? null);
    $fin = $jour($c['stop_time'] ?? null);
    foreach ($adsets as $as) {
        $d = $jour($as['start_time'] ?? null); $f = $jour($as['end_time'] ?? null);
        if ($d !== '' && ($debut === '' || $d < $debut)) { $debut = $d; }
        if ($f !== '' && $jour($c['stop_time'] ?? null) === '' && $f > $fin) { $fin = $f; }
    }
    if ($fin !== '' && $jour($c['stop_time'] ?? null) === '') {
        foreach ($adsets as $as) { if ($jour($as['end_time'] ?? null) === '' && ($as['effective_status'] ?? '') !== 'ARCHIVED') { $fin = ''; break; } }  // un ensemble sans fin : en continu
    }
    if ($debut === '') { $debut = $jour($c['created_time'] ?? null) ?: $today; }
    // Rayon : le cercle autour de la boutique, s'il est unique.
    $rayons = [];
    foreach ($adsets as $as) {
        foreach ((array) ($as['targeting']['geo_locations']['custom_locations'] ?? []) as $l) {
            if (isset($l['radius'])) { $rayons[] = (float) $l['radius'] * (($l['distance_unit'] ?? 'kilometer') === 'mile' ? 1.609 : 1); }
        }
    }

    $spent = round((float) ($st['spend'] ?? 0), 2);
    $cap = socMontantMeta($c['spend_cap'] ?? null);
    $effC = (string) ($c['effective_status'] ?? '');
    $statuts = array_values(array_filter($statutsPubs));
    $s = match ($effC) {
        'PAUSED' => 'En pause', 'ARCHIVED', 'DELETED' => 'Arrêtée', 'IN_PROCESS' => 'En revue', 'WITH_ISSUES' => 'Refusée',
        default => in_array('Active', $statuts, true) ? 'Active' : (in_array('En revue', $statuts, true) ? 'En revue'
            : ($statuts && !array_diff($statuts, ['Refusée']) ? 'Refusée' : ($statuts && !array_diff($statuts, ['Arrêtée']) ? 'Arrêtée' : ($statuts ? 'En pause' : 'Active')))),
    };
    if ($cap > 0 && $spent >= $cap * 0.99 && $s !== 'Arrêtée') { $s = 'Budget épuisé'; }
    if ($fin !== '' && $fin < $today && in_array($s, ['Active', 'Budget épuisé', 'En pause', 'En revue'], true)) { $s = 'Terminée'; }

    $actions = [];
    foreach ((array) ($st['actions'] ?? []) as $a) { $actions[(string) ($a['action_type'] ?? '')] = (int) ($a['value'] ?? 0); }
    $num = static fn ($v) => is_numeric($v) ? (float) $v : null;
    $note = null;
    if ((int) $compte['statut'] !== 1 && (int) $compte['statut'] !== 201) {
        $note = 'Compte publicitaire ' . (SOC_COMPTE_STATUTS[(int) $compte['statut']] ?? 'statut ' . $compte['statut']) . ' chez Meta : aucune diffusion tant qu’il n’est pas régularisé (Gestionnaire de publicités → Facturation).';
    }
    $pub = array_merge($avant ?? ['id' => SOC_IMPORT_PREFIXE . $cid, 'campaign' => null, 'importedAt' => date('Y-m-d H:i')], [
        'origin' => 'meta', 'metaId' => $cid, 'account' => $compte['id'], 'accountName' => $compte['name'], 'devise' => $compte['devise'] ?: 'EUR',
        'name' => (string) ($c['name'] ?? $cid), 'objective' => SOC_OBJECTIFS[(string) ($c['objective'] ?? '')] ?? (string) ($c['objective'] ?? ''), 'metaObjective' => (string) ($c['objective'] ?? ''),
        'shops' => $ids ?: array_map(static fn ($x) => (string) $x['id'], $shops), 'scope' => $reseau ? 'reseau' : 'boutique',
        'budgetType' => $quotidien === false ? 'total' : 'quotidien', 'budget' => $budget, 'cap' => $cap > 0 ? $cap : null, 'spent' => $spent,
        'start' => $debut, 'end' => $fin !== '' ? $fin : null, 'status' => $s,
        'reason' => $s === 'Refusée' && $motifs ? implode(' · ', array_unique($motifs)) : null, 'note' => $note, 'gone' => false,
        'radius' => $rayons && count(array_unique($rayons)) === 1 ? round($rayons[0], 1) : null, 'post' => $avant['post'] ?? null,
        'nAds' => count($ads), 'nAdsets' => count($adsets), 'thumb' => $vignette, 'pages' => array_map('strval', array_keys($pages)),
        'insights' => ['impressions' => (int) ($st['impressions'] ?? 0), 'reach' => (int) ($st['reach'] ?? 0), 'clicks' => (int) ($st['clicks'] ?? 0),
            'ctr' => $num($st['ctr'] ?? null) !== null ? round($num($st['ctr']) / 100, 4) : null, 'cpc' => $num($st['cpc'] ?? null), 'cpm' => $num($st['cpm'] ?? null),
            'frequency' => $num($st['frequency'] ?? null), 'engagement' => $actions['post_engagement'] ?? null, 'videoViews' => $actions['video_view'] ?? null,
            'leads' => $actions['lead'] ?? $actions['onsite_conversion.lead_grouped'] ?? null],
        'lienMeta' => 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=' . substr($compte['id'], 4) . '&selected_campaign_ids=' . $cid,
        'syncedAt' => date('Y-m-d H:i'),
    ]);
    return ['pub' => $pub, 'objets' => $objets];
}

/** Remplace les objets Meta d'une campagne importée (une ligne par pub, pour le Retour sur campagne). */
function socCampagneMetaObjets(string $localId, string $act, string $cid, array $objets): void
{
    Db::exec("DELETE FROM social_meta_objet WHERE kind = 'ad' AND local_id = ?", [$localId]);
    if (!$objets) { $objets = [['shop_id' => null]]; }
    foreach ($objets as $o) {
        socMetaObjectAdd($o + ['kind' => 'ad', 'local_id' => $localId, 'account' => $act, 'campaign_id' => $cid]);
    }
}

/**
 * Changement fait à l'écran sur une campagne importée : statut et plafond,
 * au niveau de la campagne Meta (ses pubs et ensembles gardent leur réglage).
 * Les champs qui viennent de Meta restent ceux du serveur.
 */
function socCampagneMetaHook(array $avant, array $ad): array
{
    foreach (['origin', 'metaId', 'account', 'accountName', 'devise', 'name', 'objective', 'metaObjective', 'shops', 'scope', 'budgetType', 'budget',
        'spent', 'start', 'end', 'reason', 'note', 'gone', 'radius', 'nAds', 'nAdsets', 'thumb', 'pages', 'insights', 'lienMeta', 'syncedAt', 'importedAt', 'stats'] as $k) {
        if (array_key_exists($k, $avant)) { $ad[$k] = $avant[$k]; } else { unset($ad[$k]); }
    }
    $cid = (string) ($avant['metaId'] ?? '');
    if ($cid === '' || !Meta::configured()) { return $ad; }
    $erreurs = [];
    $s = (string) ($ad['status'] ?? ''); $sAvant = (string) ($avant['status'] ?? '');
    if ($s !== $sAvant && isset(SOC_AD_STATUT[$s])) {
        try { Meta::call('POST', $cid, ['status' => SOC_AD_STATUT[$s]]); }
        catch (MetaError $e) { $erreurs[] = $e->getMessage(); $ad['status'] = $sAvant; }
    } elseif ($s !== $sAvant) { $ad['status'] = $sAvant; }  // seuls pause, reprise et arrêt se décident ici
    $cap = (float) ($ad['cap'] ?? 0);
    if ($cap > 0 && $cap !== (float) ($avant['cap'] ?? 0)) {
        try { Meta::call('POST', $cid, ['spend_cap' => socCents($cap)]); }
        catch (MetaError $e) { $erreurs[] = $e->getMessage(); $ad['cap'] = $avant['cap'] ?? null; }
    }
    if ($erreurs) {
        $ad['reason'] = 'Meta : ' . implode(' · ', $erreurs);
        socLog('Système', 'Échec', '« ' . ($ad['name'] ?? '') . ' » : ' . $ad['reason']);
    } elseif ($s !== $sAvant && isset(SOC_AD_STATUT[$s])) {
        socLog(socUser(), 'Publicité', '« ' . ($ad['name'] ?? '') . ' » (campagne Meta ' . $cid . ') : ' . $sAvant . ' → ' . $s);
    }
    return $ad;
}
