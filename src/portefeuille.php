<?php
declare(strict_types=1);

/**
 * Le portefeuille Business Manager : tout ce que le System User voit chez
 * Meta — Pages, comptes publicitaires, comptes Instagram, pixels — relu d'un
 * bouton « Rafraîchir », puis rapproché des boutiques (et de la marque) pour
 * remplir « Identifiants Meta par boutique » sans recopier d'identifiants.
 *
 * Sources, dans l'ordre (le token du System User suffit) :
 *  - le Business : `meta_business_id` (Paramètres), sinon GET /me?fields=business,
 *    sinon GET /me/businesses ;
 *  - Pages : /{business}/owned_pages + /client_pages, sinon /me/accounts ;
 *  - comptes publicitaires : /{business}/owned_ad_accounts + /client_ad_accounts,
 *    sinon /me/adaccounts ;
 *  - Instagram : /{business}/instagram_accounts + le compte rattaché à chaque Page ;
 *  - pixels : /{business}/adspixels, sinon /act_…/adspixels de chaque compte.
 *
 * Le résultat est gardé en base (`meta_portefeuille`, social_parametre).
 */

/** Toutes les pages d'un appel paginé de Graph. */
function socGraphTout(string $path, array $params, int $max = 500): array
{
    $out = [];
    for ($p = 0; $p < 20 && count($out) < $max; $p++) {
        $r = Meta::call('GET', $path, $params);
        foreach ((array) ($r['data'] ?? []) as $x) { $out[] = $x; }
        $next = $r['paging']['next'] ?? null;
        if (!is_string($next) || $next === '') { break; }
        $path = $next; $params = [];
    }
    return $out;
}

/** Texte comparable : minuscules, sans accents ni ponctuation. */
function socNorm(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** La boutique dont le nom (ville) figure dans un libellé Meta — sinon la marque si rien ne correspond. */
function socDevinerBoutique(string $libelle, array $shops): ?string
{
    $l = ' ' . socNorm($libelle) . ' ';
    $trouve = null;
    foreach ($shops as $s) {
        $n = socNorm((string) $s['name']);
        if ($n !== '' && str_contains($l, ' ' . $n . ' ')) {
            if ($trouve !== null) { return null; }  // ambigu : deux boutiques dans le libellé
            $trouve = (string) $s['id'];
        }
    }
    if ($trouve !== null) { return $trouve; }
    return preg_match('/atelier\s*by|latelierby/', socNorm($libelle) . ' ' . str_replace(' ', '', socNorm($libelle))) ? 'marque' : null;
}

/** Relit le portefeuille chez Meta. `$journal` faux : pas de trace (rafraîchissement automatique). */
function socPortefeuilleRafraichir(bool $journal = true): array
{
    if (!Meta::configured()) { throw new HttpError(409, 'token Meta absent : saisissez-le dans « Clés d’accès Meta »'); }
    $err = [];
    $essai = static function (callable $f, string $quoi) use (&$err) {
        try { return $f(); } catch (MetaError $e) { $err[] = $quoi . ' : ' . $e->getMessage(); return null; }
    };

    // Le Business.
    $biz = trim((string) (socSetting('meta_business_id') ?? ''));
    $bizNom = '';
    if ($biz === '') {
        $me = $essai(static fn () => Meta::call('GET', 'me', ['fields' => 'id,name,business']), 'utilisateur système');
        if (!empty($me['business']['id'])) { $biz = (string) $me['business']['id']; $bizNom = (string) ($me['business']['name'] ?? ''); }
    }
    if ($biz === '') {
        $b = $essai(static fn () => socGraphTout('me/businesses', ['fields' => 'id,name']), 'businesses');
        if (!empty($b[0]['id'])) { $biz = (string) $b[0]['id']; $bizNom = (string) ($b[0]['name'] ?? ''); }
    }
    if ($biz !== '' && $bizNom === '') {
        $r = $essai(static fn () => Meta::call('GET', $biz, ['fields' => 'id,name']), 'business');
        $bizNom = (string) ($r['name'] ?? '');
    }

    // Pages (et leur compte Instagram).
    $fp = 'id,name,username,link,instagram_business_account{id,username}';
    $pages = [];
    if ($biz !== '') {
        foreach (['owned_pages' => 'propriétaire', 'client_pages' => 'client'] as $edge => $lien) {
            foreach ((array) $essai(static fn () => socGraphTout($biz . '/' . $edge, ['fields' => $fp, 'limit' => 100]), 'Pages ' . $lien) as $p) { $pages[(string) $p['id']] = $p + ['_lien' => $lien]; }
        }
    }
    foreach ((array) $essai(static fn () => socGraphTout('me/accounts', ['fields' => $fp, 'limit' => 100]), 'Pages du System User') as $p) {
        $pages[(string) $p['id']] = ($pages[(string) $p['id']] ?? []) + $p + ['_lien' => 'attribuée au System User'];
    }

    // Comptes publicitaires.
    $fa = 'id,account_id,name,account_status,currency,business{id,name}';
    $comptes = [];
    if ($biz !== '') {
        foreach (['owned_ad_accounts' => 'propriétaire', 'client_ad_accounts' => 'client'] as $edge => $lien) {
            foreach ((array) $essai(static fn () => socGraphTout($biz . '/' . $edge, ['fields' => $fa, 'limit' => 100]), 'Comptes pub ' . $lien) as $a) { $comptes[(string) $a['id']] = $a + ['_lien' => $lien]; }
        }
    }
    foreach ((array) $essai(static fn () => socGraphTout('me/adaccounts', ['fields' => $fa, 'limit' => 100]), 'Comptes pub du System User') as $a) {
        $comptes[(string) $a['id']] = ($comptes[(string) $a['id']] ?? []) + $a + ['_lien' => 'attribué au System User'];
    }

    // Instagram.
    $ig = [];
    if ($biz !== '') {
        foreach ((array) $essai(static fn () => socGraphTout($biz . '/instagram_accounts', ['fields' => 'id,username', 'limit' => 100]), 'Instagram') as $i) { $ig[(string) $i['id']] = ['id' => (string) $i['id'], 'username' => (string) ($i['username'] ?? ''), 'page' => null]; }
    }
    foreach ($pages as $p) {
        $iba = $p['instagram_business_account'] ?? null;
        if (!empty($iba['id'])) { $ig[(string) $iba['id']] = ['id' => (string) $iba['id'], 'username' => (string) ($iba['username'] ?? ($ig[(string) $iba['id']]['username'] ?? '')), 'page' => (string) $p['id']]; }
    }

    // Pixels.
    $pixels = [];
    if ($biz !== '') {
        foreach ((array) $essai(static fn () => socGraphTout($biz . '/adspixels', ['fields' => 'id,name', 'limit' => 100]), 'Pixels') as $x) { $pixels[(string) $x['id']] = ['id' => (string) $x['id'], 'name' => (string) ($x['name'] ?? ''), 'compte' => null]; }
    }
    if (!$pixels) {
        foreach ($comptes as $a) {
            foreach ((array) $essai(static fn () => socGraphTout((string) $a['id'] . '/adspixels', ['fields' => 'id,name']), 'Pixels ' . $a['id']) as $x) {
                $pixels[(string) $x['id']] = ['id' => (string) $x['id'], 'name' => (string) ($x['name'] ?? ''), 'compte' => (string) $a['id']];
            }
        }
    }

    // Rapprochement avec les boutiques et la marque.
    $shops = socShops();
    $listePages = array_values(array_map(static fn ($p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'username' => (string) ($p['username'] ?? ''),
        'lien' => (string) ($p['_lien'] ?? ''), 'instagram' => (string) ($p['instagram_business_account']['id'] ?? ''),
        'boutique' => socDevinerBoutique((string) ($p['name'] ?? '') . ' ' . ($p['username'] ?? ''), $shops)], $pages));
    $listeComptes = array_values(array_map(static fn ($a) => ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? ''), 'statut' => (int) ($a['account_status'] ?? 0),
        'actif' => (int) ($a['account_status'] ?? 0) === 1, 'devise' => (string) ($a['currency'] ?? ''), 'lien' => (string) ($a['_lien'] ?? ''),
        'boutique' => socDevinerBoutique((string) ($a['name'] ?? ''), $shops)], $comptes));
    $pageBoutique = array_column($listePages, 'boutique', 'id');
    $listeIg = array_values(array_map(static fn ($i) => $i + ['boutique' => ($i['page'] !== null ? ($pageBoutique[$i['page']] ?? null) : null) ?? socDevinerBoutique($i['username'], $shops)], $ig));
    $listePixels = array_values(array_map(static fn ($x) => $x + ['boutique' => socDevinerBoutique($x['name'], $shops)], $pixels));

    $res = ['le' => date('Y-m-d H:i'), 'business' => ['id' => $biz, 'name' => $bizNom], 'pages' => $listePages, 'comptes' => $listeComptes,
        'instagram' => $listeIg, 'pixels' => $listePixels, 'erreurs' => $err];
    socSettingPut('meta_portefeuille', $res);
    if ($journal) socLog(socUser(), 'Connexion', 'Portefeuille Meta relu : ' . count($listePages) . ' Page(s), ' . count($listeComptes) . ' compte(s) pub, '
        . count($listeIg) . ' Instagram, ' . count($listePixels) . ' pixel(s)' . ($err ? ' — ' . count($err) . ' lecture(s) refusée(s)' : ''));
    return $res;
}

/**
 * Remplit « Identifiants Meta par boutique » d'après le rapprochement.
 * `$ecraser` faux : seuls les champs vides sont remplis. Un pixel seul dans le
 * portefeuille sert à toutes les boutiques (pixel unique du webshop).
 */
function socPortefeuilleAppliquer(bool $ecraser = false): array
{
    $pf = socSetting('meta_portefeuille');
    if (!is_array($pf)) { throw new HttpError(409, 'rafraîchissez d’abord le portefeuille'); }
    $cibles = ['marque' => socBrand()];
    foreach (socShops() as $s) { $cibles[$s['id']] = $s; }
    $prop = [];
    foreach ((array) $pf['pages'] as $p) { if ($p['boutique']) { $prop[$p['boutique']]['page_id'] ??= $p['id']; if ($p['instagram']) { $prop[$p['boutique']]['instagram_user_id'] ??= $p['instagram']; } } }
    foreach ((array) $pf['comptes'] as $a) { if ($a['boutique'] && $a['actif']) { $prop[$a['boutique']]['ad_account_id'] ??= $a['id']; } }
    foreach ((array) $pf['instagram'] as $i) { if ($i['boutique']) { $prop[$i['boutique']]['instagram_user_id'] ??= $i['id']; } }
    $pixels = (array) $pf['pixels'];
    foreach ($pixels as $x) { if ($x['boutique']) { $prop[$x['boutique']]['pixel_id'] ??= $x['id']; } }
    if (count($pixels) === 1) { foreach (array_keys($cibles) as $sid) { $prop[$sid]['pixel_id'] ??= $pixels[0]['id']; } }

    $faits = [];
    foreach ($prop as $sid => $ids) {
        if (!isset($cibles[$sid])) { continue; }
        $cur = $cibles[$sid]['ids']; $n = $cur;
        foreach ($ids as $k => $v) { if ($ecraser || ($cur[$k] ?? '') === '') { $n[$k] = $v; } }
        if ($n !== $cur) { socShopPut((string) $sid, ['ids' => $n]); $faits[] = $cibles[$sid]['name'] . ' (' . implode(', ', array_keys(array_diff_assoc($n, $cur))) . ')'; }
    }
    if ($faits) { socLog(socUser(), 'Connexion', 'Identifiants Meta repris du portefeuille : ' . implode(' · ', $faits)); }
    return ['appliques' => $faits];
}

/* --- rafraîchissement automatique des identifiants ------------------------------------- */

/** Réglages : actif, remplacer aussi les identifiants saisis à la main, fréquence (heures). */
function socIdsAutoConfig(): array
{
    $c = socSetting('meta_ids_auto');
    return array_replace(['actif' => true, 'ecraser' => false, 'frequence' => 1], is_array($c) ? $c : []);
}

function socIdsAutoPut(array $in): array
{
    $f = (int) ($in['frequence'] ?? 1);
    $c = ['actif' => (bool) ($in['actif'] ?? true), 'ecraser' => (bool) ($in['ecraser'] ?? false), 'frequence' => in_array($f, [1, 6, 24], true) ? $f : 1];
    socSettingPut('meta_ids_auto', $c, false);
    socLog(socUser(), 'Connexion', 'Mise à jour automatique des identifiants Meta : ' . ($c['actif'] ? 'toutes les ' . $c['frequence'] . ' h' . ($c['ecraser'] ? ', remplace les saisies' : ', champs vides seulement') : 'désactivée'));
    return $c;
}

/**
 * « Identifiants Meta par boutique » tenus à jour tout seuls : relit le portefeuille
 * Business et remplit les identifiants (vides seulement, ou tous si « remplacer »),
 * puis revérifie les connexions des boutiques modifiées. Lancé par le cron (toutes
 * les heures, selon la fréquence choisie) et dès qu'un nouveau token est enregistré.
 * Le journal ne garde que les changements.
 */
function socIdsAuto(bool $forcer = false): array
{
    $c = socIdsAutoConfig();
    if (!$c['actif'] && !$forcer) { return ['ignore' => 'désactivé']; }
    if (!Meta::configured()) { return ['ignore' => 'token Meta absent']; }
    $der = socSetting('meta_ids_auto_dernier');
    if (!$forcer && is_array($der) && ($t = strtotime((string) ($der['le'] ?? ''))) && $t > time() - $c['frequence'] * 3600 + 300) { return ['ignore' => 'déjà fait à ' . $der['le']]; }
    try {
        $pf = socPortefeuilleRafraichir(false);
        $ap = socPortefeuilleAppliquer($c['ecraser']);
        if ($ap['appliques']) { socTestConnexions(); }
        $res = ['le' => date('Y-m-d H:i'), 'ok' => true, 'appliques' => $ap['appliques'], 'erreurs' => $pf['erreurs'],
            'lus' => ['pages' => count($pf['pages']), 'comptes' => count($pf['comptes']), 'instagram' => count($pf['instagram']), 'pixels' => count($pf['pixels'])]];
    } catch (Throwable $e) {
        $res = ['le' => date('Y-m-d H:i'), 'ok' => false, 'appliques' => [], 'erreurs' => [$e->getMessage()]];
    }
    socSettingPut('meta_ids_auto_dernier', $res, false);
    return $res;
}

