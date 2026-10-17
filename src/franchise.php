<?php
declare(strict_types=1);

/**
 * Espace franchisé (/social_franchisee) : un outil de communication et de
 * marketing pour chaque boutique, entièrement cadré par le franchiseur.
 *
 *  - Le franchisé ne voit QUE sa boutique : son calendrier (ses posts et ceux du
 *    réseau qui la concernent), les campagnes du réseau où elle figure, ses
 *    promotions.
 *  - Il ne publie QUE depuis les modèles que le franchiseur lui ouvre
 *    (`social_modele_franchise`) : texte figé (FR/NL), visuel figé, et les seuls
 *    champs que le modèle autorise (produit de son catalogue, remise bornée, offre
 *    d'une liste, période, phrase courte, photo).
 *  - Les règles du réseau (`social_parametre.franchise_regles`) — validation par
 *    le franchiseur, quota par semaine, délai, jours et heures, canaux, remise
 *    maximale — sont vérifiées ICI, côté serveur : l'écran ne fait que les montrer.
 *  - Accès : par l'ERP seulement, sans connexion propre à l'espace. Le gérant déjà
 *    connecté à l'ERP y entre directement (point d'accroche `franchise_session` de
 *    config/hote.php : ses boutiques et son nom) ; la boutique ouverte est dans
 *    l'adresse (?shop=4) et doit être une des siennes. Seul autre accès : l'aperçu
 *    du franchiseur (« Voir comme cette boutique »), un cookie signé limité à une boutique.
 */

const SF_COOKIE = 'social_f';
const SF_DUREE = 12 * 3600;
const SF_JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

/* --- règles ----------------------------------------------------------------------- */

function sfReglesDefaut(): array
{
    return ['actif' => true, 'validation' => 'modele', 'quotaSemaine' => 3, 'delaiMinHeures' => 24, 'horizonJours' => 30,
        'jours' => [1, 2, 3, 4, 5, 6, 0], 'heureMin' => '07:00', 'heureMax' => '20:00', 'fb' => true, 'ig' => true,
        'remiseMax' => 30, 'promoDureeMax' => 14, 'voirReseau' => true, 'voirCampagnes' => true, 'voirResultats' => true, 'voirSuivi' => true,
        // L'analyse des vues (Page, Instagram, publications) et de l'évolution des abonnés, sans le budget.
        'voirAnalyse' => true,
        // Les publicités Meta qui touchent la boutique (réseau et boutique seule) ; leur budget et leur dépense, à part.
        'voirPublicites' => true, 'voirBudgetPub' => false,
        // Les commentaires de la Page de la boutique (et de ses publicités), classés vert / orange / rouge.
        'voirCommentaires' => true,
        // Les avis Google de la boutique (note, cible, avis 5★ à obtenir, avis classés vert / orange / rouge).
        'voirAvisGoogle' => true,
        // Qui répond : le franchisé publie lui-même sa réponse sous les commentaires de sa Page (rouges compris : la marque
        // n'a pas d'écran des commentaires) ; les avis Google rouges sont traités par le réseau ('reseau') ou par la
        // boutique ('franchise') ; au-delà du délai, un avis Google non traité remonte au réseau.
        'repondreCommentaires' => true, 'rouges' => 'reseau', 'delaiReponseHeures' => 24,
        // « Ma semaine » : la boutique voit son rang dans le réseau (régularité, réactivité, avis gagnés).
        'voirClassement' => true,
        // La boutique peut proposer au réseau de booster une publication de sa Page ou de son Instagram (la marque décide).
        'proposerBoost' => true,
        // Les messages privés Instagram que le réseau confie à la boutique (ou ceux de son propre Instagram) ; y répondre.
        'voirMessages' => true, 'repondreMessages' => true,
        // Le franchisé peut publier en story (story seule, ou post + story) ; et répéter un post (au plus N fois, 0 = non).
        'story' => true, 'recurrenceMax' => 8,
        // Le franchisé peut choisir le format du visuel (carré, portrait) et la couleur de chaque texte (palette de la marque).
        'persoVisuel' => true,
        // En attendant l'ERP (pas de point d'accroche franchise_session) : l'espace s'ouvre sans connexion
        // sur cette boutique, et elle seule ('' = fermé sans l'ERP).
        'accesLibre' => '',
        'message' => 'Bienvenue dans votre espace communication. Choisissez un modèle, complétez les champs : le visuel et le texte suivent la charte de L’Atelier By.'];
}

function sfRegles(): array
{
    $r = socSetting('franchise_regles');
    return array_replace(sfReglesDefaut(), is_array($r) ? $r : []);
}

/** Enregistre les règles (écran franchiseur), bornées. */
function sfReglesPut(array $in): array
{
    $d = sfReglesDefaut();
    $h = static fn ($v, $def) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $def;
    $r = [
        'actif' => (bool) ($in['actif'] ?? $d['actif']),
        'validation' => in_array($in['validation'] ?? '', ['toujours', 'modele', 'jamais'], true) ? $in['validation'] : $d['validation'],
        'quotaSemaine' => max(0, min(50, (int) ($in['quotaSemaine'] ?? $d['quotaSemaine']))),
        'delaiMinHeures' => max(1, min(24 * 14, (int) ($in['delaiMinHeures'] ?? $d['delaiMinHeures']))),
        'horizonJours' => max(1, min(90, (int) ($in['horizonJours'] ?? $d['horizonJours']))),
        'jours' => array_values(array_unique(array_filter(array_map('intval', (array) ($in['jours'] ?? $d['jours'])), static fn ($j) => $j >= 0 && $j <= 6))),
        'heureMin' => $h($in['heureMin'] ?? null, $d['heureMin']), 'heureMax' => $h($in['heureMax'] ?? null, $d['heureMax']),
        'fb' => (bool) ($in['fb'] ?? true), 'ig' => (bool) ($in['ig'] ?? true),
        'remiseMax' => max(0, min(90, (int) ($in['remiseMax'] ?? $d['remiseMax']))),
        'promoDureeMax' => max(1, min(90, (int) ($in['promoDureeMax'] ?? $d['promoDureeMax']))),
        'voirReseau' => (bool) ($in['voirReseau'] ?? true), 'voirCampagnes' => (bool) ($in['voirCampagnes'] ?? true), 'voirResultats' => (bool) ($in['voirResultats'] ?? true), 'voirSuivi' => (bool) ($in['voirSuivi'] ?? true),
        'voirAnalyse' => (bool) ($in['voirAnalyse'] ?? true), 'persoVisuel' => (bool) ($in['persoVisuel'] ?? true),
        'voirPublicites' => (bool) ($in['voirPublicites'] ?? true), 'voirBudgetPub' => (bool) ($in['voirBudgetPub'] ?? false),
        'voirCommentaires' => (bool) ($in['voirCommentaires'] ?? true),
        'voirAvisGoogle' => (bool) ($in['voirAvisGoogle'] ?? true),
        'repondreCommentaires' => (bool) ($in['repondreCommentaires'] ?? true),
        'voirClassement' => (bool) ($in['voirClassement'] ?? true),
        'proposerBoost' => (bool) ($in['proposerBoost'] ?? true),
        'voirMessages' => (bool) ($in['voirMessages'] ?? true), 'repondreMessages' => (bool) ($in['repondreMessages'] ?? true),
        'rouges' => in_array($in['rouges'] ?? '', ['reseau', 'franchise'], true) ? (string) $in['rouges'] : 'reseau',
        'delaiReponseHeures' => max(1, min(168, (int) ($in['delaiReponseHeures'] ?? 24))),
        'story' => (bool) ($in['story'] ?? true), 'recurrenceMax' => max(0, min(26, (int) ($in['recurrenceMax'] ?? $d['recurrenceMax']))),
        'accesLibre' => in_array((string) ($in['accesLibre'] ?? ''), array_column(socReseau(), 'id'), true) ? (string) $in['accesLibre'] : '',
        'message' => mb_substr(trim((string) ($in['message'] ?? $d['message'])), 0, 600),
    ];
    if ($r['heureMax'] <= $r['heureMin']) { $r['heureMax'] = $d['heureMax']; $r['heureMin'] = $d['heureMin']; }
    socSettingPut('franchise_regles', $r, false);
    socLog(socUser(), 'Espace franchisé', 'Règles mises à jour (validation : ' . $r['validation'] . ', ' . $r['quotaSemaine'] . ' posts / semaine)');
    return $r;
}

/* --- modèles ---------------------------------------------------------------------- */

/**
 * Les modèles livrés. Un modèle :
 *  name, description, active, type, fb, ig, validation (si la règle est « selon le modèle »),
 *  title / text / textNl (variables : {produit} {prix} {remise} {offre} {prix_promo} {du} {au}
 *  {libre}, et celles de la boutique {ville} {adresse} {horaires}, résolues à la publication),
 *  product: aucun | choix ; promo: aucune | remise (min, max) | offre (offres) | prix ;
 *  period: bool ; free: longueur max de la phrase libre (0 = aucune) ;
 *  photo: catalogue | upload | choix ; visual: { style, eyebrow, eyebrowNl }.
 */
function sfModelesDefaut(): array
{
    return [
        ['id' => 'fp-produit', 'order' => 1, 'name' => 'Produit du jour', 'description' => 'Mettre un produit de votre vitrine en avant, à son prix.', 'active' => true,
            'type' => 'Produit', 'fb' => true, 'ig' => true, 'validation' => false, 'title' => '{produit}',
            'text' => '{produit} : {prix} € chez L’Atelier By {ville}. Ouvert {horaires}.', 'textNl' => '{produit}: {prix} € bij L’Atelier By {ville}. Open {horaires}.',
            'product' => 'choix', 'promo' => ['kind' => 'aucune'], 'period' => false, 'free' => 0, 'photo' => 'choix',
            'visual' => ['style' => 'epure', 'eyebrow' => 'Aujourd’hui en vitrine', 'eyebrowNl' => 'Vandaag in de etalage']],
        ['id' => 'fp-remise', 'order' => 2, 'name' => 'Promotion en %', 'description' => 'Une remise sur un produit, pour une durée limitée.', 'active' => true,
            'type' => 'Produit', 'fb' => true, 'ig' => true, 'validation' => true, 'title' => '{produit} -{remise} %',
            'text' => '-{remise} % sur {produit} chez L’Atelier By {ville}, du {du} au {au}. Ouvert {horaires}.',
            'textNl' => '-{remise} % op {produit} bij L’Atelier By {ville}, van {du} tot {au}. Open {horaires}.',
            'product' => 'choix', 'promo' => ['kind' => 'remise', 'min' => 10, 'max' => 30], 'period' => true, 'free' => 0, 'photo' => 'choix',
            'visual' => ['style' => 'ruby', 'eyebrow' => 'Promotion', 'eyebrowNl' => 'Promotie']],
        ['id' => 'fp-offre', 'order' => 3, 'name' => 'Offre spéciale', 'description' => '4+1, 2+1… sur un produit, pour une durée limitée.', 'active' => true,
            'type' => 'Produit', 'fb' => true, 'ig' => true, 'validation' => true, 'title' => '{offre} · {produit}',
            'text' => '{offre} sur {produit} chez L’Atelier By {ville}, du {du} au {au} !', 'textNl' => '{offre} op {produit} bij L’Atelier By {ville}, van {du} tot {au}!',
            'product' => 'choix', 'promo' => ['kind' => 'offre', 'offres' => ['4+1', '2+1', '1 acheté = 1 offert']], 'period' => true, 'free' => 0, 'photo' => 'choix',
            'visual' => ['style' => 'abricot', 'eyebrow' => 'Offre spéciale', 'eyebrowNl' => 'Speciale actie']],
        ['id' => 'fp-evenement', 'order' => 4, 'name' => 'Événement en boutique', 'description' => 'Une dégustation, une animation, un horaire spécial.', 'active' => true,
            'type' => 'Événement local', 'fb' => true, 'ig' => true, 'validation' => true, 'title' => 'Événement à {ville}',
            'text' => 'Chez L’Atelier By {ville} : {libre}', 'textNl' => 'Bij L’Atelier By {ville}: {libre}',
            'product' => 'aucun', 'promo' => ['kind' => 'aucune'], 'period' => false, 'free' => 90, 'photo' => 'upload',
            'visual' => ['style' => 'noir', 'eyebrow' => 'En boutique', 'eyebrowNl' => 'In de winkel']],
        ['id' => 'fp-vitrine', 'order' => 5, 'name' => 'Nouveau en vitrine', 'description' => 'Votre photo d’un produit qui arrive.', 'active' => true,
            'type' => 'Produit', 'fb' => true, 'ig' => true, 'validation' => false, 'title' => 'Nouveau : {produit}',
            'text' => 'Nouveau dans notre vitrine à {ville} : {produit}. À découvrir {horaires} !', 'textNl' => 'Nieuw in onze etalage in {ville}: {produit}. Te ontdekken {horaires}!',
            'product' => 'choix', 'promo' => ['kind' => 'aucune'], 'period' => false, 'free' => 0, 'photo' => 'upload',
            'visual' => ['style' => 'plein', 'eyebrow' => 'Nouveau', 'eyebrowNl' => 'Nieuw']],
    ];
}

/** Premier lancement : les modèles livrés. */
function sfSeed(): void
{
    if (socSetting('franchise_initial') !== null) { return; }
    foreach (sfModelesDefaut() as $m) { socItemPut('ftemplates', $m); }
    socSettingPut('franchise_initial', date('Y-m-d H:i:s'), false);
}

function sfModeles(bool $actifs = false): array
{
    sfSeed();
    $l = array_values(array_filter(socItems('ftemplates'), static fn ($m) => !$actifs || !empty($m['active'])));
    usort($l, static fn ($a, $b) => ((int) ($a['order'] ?? 99)) <=> ((int) ($b['order'] ?? 99)));
    return $l;
}

/* --- session ------------------------------------------------------------------------ */

function sfSecret(): string
{
    $s = (string) (socSetting('franchise_secret') ?? '');
    if ($s === '') { $s = bin2hex(random_bytes(32)); socSettingPut('franchise_secret', $s, true); }
    return $s;
}

/** Ouvre l'aperçu du franchiseur (cookie signé : boutiques, nom, origine, expiration). */
function sfSessionOuvrir(array $shops, string $nom, string $via): array
{
    $s = ['shops' => array_values(array_map('strval', $shops)), 'name' => mb_substr($nom, 0, 80), 'via' => $via, 'exp' => time() + SF_DUREE];
    $p = rtrim(strtr(base64_encode((string) json_encode($s, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    $v = $p . '.' . hash_hmac('sha256', $p, sfSecret());
    $GLOBALS['SF_COOKIE_ECRIT'] = $v;
    if (!headers_sent() && PHP_SAPI !== 'cli') {
        setcookie(SF_COOKIE, $v, ['expires' => $s['exp'], 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    }
    $_COOKIE[SF_COOKIE] = $v;
    return $s;
}

/**
 * La session de l'espace : l'aperçu du franchiseur (cookie signé) s'il est ouvert, sinon le
 * gérant connecté à l'ERP (config/hote.php → franchise_session), limité aux boutiques du réseau.
 * Tant que l'ERP n'est pas branché (pas de franchise_session), la règle « accès ouvert » ouvre
 * l'espace sans connexion sur une seule boutique (ex. 4) ; une fois l'ERP branché, elle ne joue plus.
 */
function sfSession(): ?array
{
    $c = (string) ($_COOKIE[SF_COOKIE] ?? '');
    if ($c !== '' && str_contains($c, '.')) {
        [$p, $sig] = explode('.', $c, 2);
        $s = hash_equals(hash_hmac('sha256', $p, sfSecret()), $sig) ? json_decode((string) base64_decode(strtr($p, '-_', '+/')), true) : null;
        if (is_array($s) && ($s['exp'] ?? 0) >= time() && !empty($s['shops'])) { return $s; }
    }
    if (($f = socHote('franchise_session')) !== null && is_array($h = $f()) && !empty($h['shops'])) {
        $ids = array_values(array_intersect(array_map('strval', (array) $h['shops']), array_column(socReseau(), 'id')));
        return $ids ? ['shops' => $ids, 'name' => (string) ($h['name'] ?? 'Gérant'), 'via' => 'hote', 'exp' => time() + SF_DUREE] : null;
    }
    $libre = (string) sfRegles()['accesLibre'];
    if (socHote('franchise_session') === null && $libre !== '' && ($b = socShop($libre)) !== null && in_array($libre, array_column(socReseau(), 'id'), true)) {
        return ['shops' => [$libre], 'name' => 'Gérant · ' . $b['name'], 'via' => 'libre', 'exp' => time() + SF_DUREE];
    }
    return null;
}

function sfSessionFermer(): void
{
    unset($_COOKIE[SF_COOKIE]);
    if (!headers_sent() && PHP_SAPI !== 'cli') { setcookie(SF_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']); }
}

/** La session, limitée à une boutique si elle est demandée. */
function sfExiger(?string $shop = null): array
{
    $s = sfSession();
    if ($s === null) { throw new HttpError(401, 'Ouvrez votre espace boutique depuis l’ERP.'); }
    if ($shop !== null && !in_array($shop, $s['shops'], true)) { throw new HttpError(403, 'Cette boutique n’est pas rattachée à votre compte ERP.'); }
    if (!sfRegles()['actif'] && ($s['via'] ?? '') !== 'apercu') { throw new HttpError(403, 'L’espace franchisé est fermé par le franchiseur.'); }
    return $s;
}

/* --- catalogue de la boutique ------------------------------------------------------- */

/** Les produits vendus dans la boutique (API TFBuddy), mémorisés 6 h ; à défaut, le catalogue du réseau. Sans prix : écartés. */
function sfCatalogue(string $shop): array
{
    // Un produit sans prix (ou à 0) donnerait « … : 0,00 € » dans un post.
    return array_values(array_filter(sfCatalogueBrut($shop), static fn ($p) => (float) str_replace(',', '.', (string) ($p['price'] ?? '')) > 0));
}

function sfCatalogueBrut(string $shop): array
{
    $cle = 'tfb_cache_catalogue_' . preg_replace('/\W/', '', $shop);
    $c = socSetting($cle);
    // v3 : les articles disent s'ils sont vendus sur le webshop (lien produit du webshop) ; v4 : avec leur photo du webshop.
    if (is_array($c) && ($c['v'] ?? 1) === 4 && ($c['le'] ?? 0) > time() - 21600 && !empty($c['items'])) { return $c['items']; }
    try {
        $fr = Tfb::liste(Tfb::get('/shops/' . rawurlencode($shop) . '/products/available'));
        $nl = [];
        try { foreach (Tfb::liste(Tfb::get('/shops/' . rawurlencode($shop) . '/products/available?lang_code=nl')) as $p) { $nl[(string) $p['id']] = (string) ($p['name'] ?? ''); } }
        catch (Throwable $e) { /* sans NL */ }
        // La photo du produit sur le webshop (Cloudflare R2), lue au catalogue du webshop de la boutique (30 min de cache).
        $photos = [];
        try { foreach ((array) (socNavCatalogue($shop)['produits'] ?? []) as $w) { if (!empty($w['image'])) { $photos[(int) $w['id']] = (string) $w['image']; } } }
        catch (Throwable $e) { /* sans photos : l'illustration du design system */ }
        $items = [];
        foreach ($fr as $p) {
            $cat = (string) ($p['category_name'] ?? '');
            if (empty($p['is_active']) || preg_match('/^B\.?2\.?B/i', $cat)) { continue; }
            $prix = is_numeric($p['portion_price'] ?? null) ? (float) $p['portion_price'] : (is_numeric($p['suggested_sale_price'] ?? null) ? (float) $p['suggested_sale_price'] : null);
            $items[] = ['id' => 'p' . $p['id'], 'apiId' => (int) $p['id'], 'webshop' => !empty($p['webshop_active']), 'categoryId' => (int) ($p['id_category'] ?? 0), 'name' => (string) $p['name'], 'nameNl' => ($nl[(string) $p['id']] ?? '') ?: (string) $p['name'],
                'price' => $prix !== null && $prix > 0 ? number_format($prix, 2, ',', '') : '', 'img' => socIllus($cat, (string) $p['name']), 'photo' => $photos[(int) $p['id']] ?? null, 'gamme' => $cat];
        }
        if ($items) { socSettingPut($cle, ['v' => 4, 'le' => time(), 'items' => $items], false); return $items; }
    } catch (Throwable $e) { /* repli */ }
    if (is_array($c['items'] ?? null) && $c['items']) { return $c['items']; }
    return array_values(array_map(static fn ($p) => array_intersect_key($p, array_flip(['id', 'name', 'nameNl', 'price', 'img', 'gamme'])),
        array_filter(socCatalogue(), static fn ($p) => ($p['kind'] ?? 'produit') === 'produit')));
}

/* --- état ---------------------------------------------------------------------------- */

/** Le lundi (AAAA-MM-JJ) de la semaine d'une date. */
function sfLundi(string $jour): string
{
    $t = strtotime($jour) ?: time();
    return date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t));
}

/** Les posts du franchisé dans une semaine (refusés exclus). */
function sfQuotaUtilise(string $shop, string $jour, ?string $sauf = null): int
{
    $l = sfLundi($jour); $d = date('Y-m-d', strtotime($l . ' +6 days'));
    $n = 0;
    foreach (socItems('posts') as $p) {
        if (($p['origin'] ?? '') !== 'franchise' || (string) ($p['id'] ?? '') === (string) $sauf || !in_array($shop, array_map('strval', (array) ($p['shops'] ?? [])), true)) { continue; }
        if (!empty($p['refus']) || ($p['status'] ?? '') === 'Brouillon') { continue; }
        $j = (string) ($p['date'] ?? '');
        if ($j >= $l && $j <= $d) { $n++; }
    }
    return $n;
}

/** Ce que l'espace franchisé montre d'un post. */
function sfPostVue(array $p, string $shop): array
{
    $propre = ($p['origin'] ?? '') === 'franchise';
    $ko = array_values(array_filter((array) ($p['partial']['ko'] ?? []), static fn ($k) => (string) ($k['shop'] ?? '') === $shop));
    $statut = (string) ($p['status'] ?? '');
    if ($propre && !empty($p['refus'])) { $statut = 'Refusé'; }
    elseif ($ko) { $statut = 'Échec'; }
    return ['id' => (string) $p['id'], 'title' => (string) ($p['title'] ?? ''), 'type' => (string) ($p['type'] ?? ''), 'date' => (string) ($p['date'] ?? ''),
        'time' => (string) ($p['time'] ?? ''), 'status' => $statut, 'img' => (string) ($p['img'] ?? ''), 'text' => (string) ($p['text'] ?? ''), 'textNl' => (string) ($p['textNl'] ?? ''),
        'own' => $propre, 'template' => (string) ($p['template'] ?? ''), 'promo' => $p['promo'] ?? null, 'refus' => $propre ? ($p['refus'] ?? null) : null,
        'fb' => (bool) (($p['ch'][$shop]['fb'] ?? null) ?? ($p['fb'] ?? false)), 'ig' => (bool) (($p['ch'][$shop]['ig'] ?? null) ?? ($p['ig'] ?? false)),
        'reason' => $ko ? (string) ($ko[0]['reason'] ?? '') : null, 'campaign' => $p['campaign'] ?? null,
        'story' => $p['story'] ?? null, 'serie' => $propre ? ($p['serie'] ?? null) : null];
}

/**
 * Les opérations du réseau (campagnes commerciales du Marketing du BO) où figure la boutique, en lecture
 * seule : ce qui y est rattaché (posts du module, publications de sa Page ou de l'Instagram du réseau lues
 * chez Meta, publicités) et toutes ses métriques (Meta + ventes, estimation).
 */
function sfOperations(string $shop): array
{
    $r = sfRegles();
    if (!$r['voirCampagnes']) { return ['hidden' => true, 'operations' => []]; }
    $s = socShop($shop);
    if ($s === null) { throw new HttpError(404, 'boutique inconnue'); }
    $res = $r['voirResultats'] ? socResults() : [];
    $page = (string) ($s['ids']['page_id'] ?? '');
    $igReseau = socIgUnique() ? (string) (socBrand()['ids']['instagram_user_id'] ?? '') : '';
    $ratt = socRattachements();
    $tous = array_column(socReseau(), 'id');
    $vise = static fn ($x) => ($x === 'all' || $x === null) ? $tous : array_map('strval', (array) $x);
    $out = [];
    foreach (socOperations() as $o) {
        if (!in_array($shop, $o['shops'], true)) { continue; }
        $posts = [];
        foreach (socItems('posts') as $p) {
            if (($p['campaign'] ?? null) !== $o['id'] || !empty($p['dark']) || !in_array($shop, $vise($p['shops'] ?? null), true)) { continue; }
            $posts[] = sfPostVue($p, $shop);
        }
        $meta = [];
        foreach ($ratt as $objet => $op) {
            if ($op !== $o['id']) { continue; }
            if (preg_match('/^mfb-(\d+)_\d+$/', $objet, $m) && $page !== '' && $m[1] === $page) { $meta[] = sfMetaPublication($objet, $page); }
            elseif (str_starts_with($objet, 'mig-') && $igReseau !== '') { $meta[] = sfMetaPublication($objet, null, $igReseau); }
        }
        usort($meta, static fn ($a, $b) => strcmp($b['date'], $a['date']));
        $ads = [];
        foreach (socItems('ads') as $a) {
            if (($a['campaign'] ?? null) !== $o['id']) { continue; }
            $aShops = empty($a['shops']) ? $tous : $vise($a['shops']);  // les boutiques visées, pas le compte qui paie
            if (!in_array($shop, $aShops, true)) { continue; }
            $ads[] = ['name' => (string) ($a['name'] ?? ''), 'status' => (string) ($a['status'] ?? ''), 'objective' => (string) ($a['objective'] ?? ''),
                'start' => $a['start'] ?? null, 'end' => $a['end'] ?? null, 'reseau' => count($aShops) !== 1, 'meta' => ($a['origin'] ?? '') === 'meta'];
        }
        $split = (float) ($o['split'][$shop] ?? 0);
        $out[] = ['id' => $o['id'], 'name' => $o['name'], 'type' => $o['typeLabel'] ?: $o['type'], 'status' => $o['status'], 'boStatus' => $o['boStatus'],
            'start' => $o['start'], 'end' => $o['end'], 'description' => $o['description'], 'products' => $o['products'], 'levers' => $o['levers'], 'channels' => $o['channels'],
            'goal' => $o['goal'], 'goalShop' => $o['goal'] && $split > 0 && $o['budget'] > 0 ? round($o['goal']['value'] * $split / $o['budget'], 2) : null,
            'budget' => $split > 0 ? $split : null, 'image' => $o['image'], 'result' => $res[$o['id']][$shop] ?? null,
            'posts' => $posts, 'metaPosts' => $meta, 'ads' => $ads];
    }
    return ['hidden' => false, 'operations' => $out];
}

/**
 * Les publicités Meta qui touchent la boutique, rattachées à une opération ou non : celles du réseau (plusieurs
 * boutiques, ou la marque) et celles de sa boutique seule. En cours, à venir, terminées depuis moins de 30 jours ;
 * pas celles que Meta a refusé de créer, ni celles en pause depuis plus de 30 jours. Résultats si le réseau les partage (voirResultats), budget et dépense
 * seulement avec voirBudgetPub. Lecture seule.
 */
function sfPublicites(string $shop): array
{
    $r = sfRegles();
    if (!$r['voirPublicites']) { return ['hidden' => true, 'reseau' => [], 'boutique' => []]; }
    if (socShop($shop) === null) { throw new HttpError(404, 'boutique inconnue'); }
    $noms = array_column(socReseau(), 'name', 'id');
    $ops = []; foreach (socOperations() as $o) { $ops[$o['id']] = $o['name']; }
    $today = date('Y-m-d'); $limite = date('Y-m-d', strtotime('-30 days'));
    $LIB = ['Active' => 'Diffusée', 'En revue' => 'En vérification chez Meta', 'En pause' => 'En pause', 'Budget épuisé' => 'Budget atteint'];
    $out = ['hidden' => false, 'budget' => (bool) $r['voirBudgetPub'], 'resultats' => (bool) $r['voirResultats'], 'reseau' => [], 'boutique' => []];
    foreach (socItems('ads') as $a) {
        $statut = (string) ($a['status'] ?? '');
        if ($statut === 'Refusée' || !empty($a['gone'])) { continue; }
        $shops = array_values(array_map('strval', (array) ($a['shops'] ?? [])));
        if ($shops && !in_array($shop, $shops, true)) { continue; }
        $debut = (string) ($a['start'] ?? ''); $fin = (string) ($a['end'] ?? '');
        $phase = in_array($statut, ['Terminée', 'Arrêtée'], true) || ($fin !== '' && $fin < $today) ? 'terminee' : ($debut > $today ? 'avenir' : 'encours');
        if ($phase === 'terminee' && ($fin !== '' ? $fin : $debut) < $limite) { continue; }
        if ($statut === 'En pause' && $debut < $limite) { continue; }  // en pause de longue date : pas « en route »
        // Réseau ou boutique selon les boutiques visées, pas selon le compte qui paie (une pub de Halle peut passer par le compte réseau).
        $reseau = count($shops) !== 1;
        $post = !empty($a['post']) ? socItem('posts', (string) $a['post']) : null;
        $item = ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? ''), 'objective' => (string) ($a['objective'] ?? ''),
            'status' => $phase === 'terminee' ? 'Terminée' : ($phase === 'avenir' ? 'À venir' : ($LIB[$statut] ?? $statut)), 'phase' => $phase,
            'start' => $debut ?: null, 'end' => $fin ?: null, 'radius' => $a['radius'] ?? null, 'operation' => $ops[(string) ($a['campaign'] ?? '')] ?? null,
            'img' => (string) (($a['thumb'] ?? '') ?: ($post['img'] ?? '')) ?: null, 'reseau' => $reseau,
            'shops' => $reseau ? array_values(array_filter(array_map(static fn ($id) => $noms[$id] ?? null, $shops))) : []];
        if ($r['voirResultats']) {
            $ins = (array) ($a['insights'] ?? []);
            $st = array_filter((array) ($a['stats'] ?? []), 'is_array');
            $item['stats'] = $ins ? ['impressions' => $ins['impressions'] ?? null, 'reach' => $ins['reach'] ?? null, 'clicks' => $ins['clicks'] ?? null]
                : ($st ? ['impressions' => array_sum(array_column($st, 'impressions')), 'reach' => null, 'clicks' => array_sum(array_column($st, 'clicks'))] : null);
        }
        if ($r['voirBudgetPub']) {
            $item += ['budget' => (float) ($a['budget'] ?? 0), 'budgetType' => (string) ($a['budgetType'] ?? ''), 'spent' => (float) ($a['spent'] ?? 0),
                'devise' => (string) (($a['devise'] ?? '') ?: 'EUR')];
        }
        $out[$reseau ? 'reseau' : 'boutique'][] = $item;
    }
    $ordre = ['encours' => 0, 'avenir' => 1, 'terminee' => 2];
    foreach (['reseau', 'boutique'] as $k) {
        usort($out[$k], static fn ($x, $y) => ($ordre[$x['phase']] <=> $ordre[$y['phase']]) ?: strcmp((string) $y['start'], (string) $x['start']));
    }
    return $out;
}

/**
 * Les commentaires de la boutique : ceux de sa Page Facebook et de ses publicités (pas ceux de l'Instagram du
 * réseau), classés vert / orange / rouge (src/commentaires.php), relus toutes les heures. Le franchisé les marque
 * traités ; il ne lance pas de relecture (Meta et Claude restent pilotés par le réseau).
 */
function sfCommentaires(string $shop, array $q): array
{
    if (!sfRegles()['voirCommentaires']) { return ['hidden' => true, 'commentaires' => [], 'totaux' => null]; }
    $l = socCommentairesListe(['jours' => $q['jours'] ?? 14, 'couleur' => $q['couleur'] ?? '', 'nonTraites' => $q['nonTraites'] ?? '', 'shop' => $shop]);
    $relu = is_array($l['relu']) ? ['le' => $l['relu']['le'] ?? null] : null;  // pas les erreurs des autres Pages
    return ['hidden' => false, 'jours' => $l['jours'], 'commentaires' => $l['commentaires'], 'totaux' => $l['totaux'], 'relu' => $relu, 'ia' => ['configure' => (bool) ($l['ia']['configure'] ?? false)]];
}

/**
 * Les avis Google de la boutique : sa note, l'écart à la cible du réseau, les avis 5★ à obtenir, la répartition
 * des avis lus, la moyenne du réseau pour se situer ; et ses avis classés vert / orange / rouge (src/google.php).
 * La synchronisation chez Google reste au réseau.
 */
function sfGoogle(string $shop, array $q): array
{
    if (!sfRegles()['voirAvisGoogle']) { return ['hidden' => true, 'avis' => [], 'totaux' => null]; }
    $e = socGoogleEcran(['jours' => $q['jours'] ?? 365, 'couleur' => $q['couleur'] ?? '', 'nonTraites' => $q['nonTraites'] ?? ''], $shop);
    $r = $e['reputation'];
    if (!empty($r['indispo'])) { $r = ['indispo' => true, 'cible' => $r['cible']]; }  // pas le détail technique
    else { unset($r['connecteur']['configure']); $r['reseau'] = ['moyenne' => $r['reseau']['moyenne'], 'avis' => $r['reseau']['avis'], 'notes' => $r['reseau']['notes']]; }
    $relu = is_array($e['relu']) ? ['le' => $e['relu']['le'] ?? null] : null;
    return ['hidden' => false, 'reputation' => $r, 'jours' => $e['jours'], 'avis' => $e['avis'], 'totaux' => $e['totaux'], 'relu' => $relu,
        'ia' => ['configure' => (bool) ($e['ia']['configure'] ?? false)]];
}

/**
 * Un commentaire (ou un avis Google) que la boutique traite elle-même : de sa Page, partagé par le réseau, et pas un rouge
 * que le réseau garde (règle « rouges »).
 */
function sfCommentairePropre(string $shop, string $id): array
{
    $r = Db::row('SELECT shop_id, canal, couleur FROM social_commentaire WHERE id = ?', [$id]);
    $regles = sfRegles();
    $regle = $r && $r['canal'] === 'gg' ? 'voirAvisGoogle' : 'voirCommentaires';
    if (!$regles[$regle]) { throw new HttpError(403, $regle === 'voirAvisGoogle' ? 'avis Google non partagés par le réseau' : 'commentaires non partagés par le réseau'); }
    if (!$r || (string) $r['shop_id'] !== $shop) { throw new HttpError(404, 'commentaire inconnu'); }
    if (socComResponsable($r, $regles) !== 'franchise') { throw new HttpError(403, 'Ce commentaire est pris en charge par le réseau.'); }
    return $r;
}

/** Le franchisé publie sa réponse sous un commentaire de sa Page (règle « repondreCommentaires »). */
function sfCommentaireRepondre(string $shop, string $id, string $message, array $session): array
{
    if (!sfRegles()['repondreCommentaires']) { throw new HttpError(403, 'Le réseau répond aux commentaires : copiez la réponse proposée.'); }
    sfCommentairePropre($shop, $id);
    return socCommentaireRepondre($id, $message, (string) ($session['name'] ?? 'Boutique'));
}

/** Le franchisé marque traité (ou rouvre) un commentaire ou un avis Google de sa boutique, et seulement de la sienne. */
function sfCommentaireTraiter(string $shop, string $id, bool $traite): array
{
    $r = sfCommentairePropre($shop, $id);
    return socCommentaireTraiter($id, $traite);
}

/** Une publication Meta rattachée (Page de la boutique ou Instagram du réseau), relue chez Meta ; le lien seul si Meta refuse. */
function sfMetaPublication(string $objet, ?string $page, ?string $ig = null): array
{
    $id = substr($objet, 4);
    $fb = str_starts_with($objet, 'mfb-');
    $v = ['id' => $objet, 'channel' => $fb ? 'Facebook' : 'Instagram', 'date' => '', 'text' => '', 'img' => null,
        'link' => $fb ? 'https://www.facebook.com/' . $id : null];
    try {
        if ($fb) {
            $x = Meta::call('GET', $id, ['fields' => 'message,created_time,full_picture,permalink_url'], Meta::pageToken((string) $page));
            $v['text'] = (string) ($x['message'] ?? ''); $v['img'] = (string) ($x['full_picture'] ?? '') ?: null;
            $v['date'] = substr((string) ($x['created_time'] ?? ''), 0, 10); $v['link'] = (string) ($x['permalink_url'] ?? '') ?: $v['link'];
        } else {
            $x = Meta::call('GET', $id, ['fields' => 'caption,media_type,media_url,thumbnail_url,permalink,timestamp'], socIgToken((string) $ig, ''));
            $v['text'] = (string) ($x['caption'] ?? ''); $v['img'] = (string) (($x['media_type'] ?? '') === 'VIDEO' ? ($x['thumbnail_url'] ?? '') : ($x['media_url'] ?? '')) ?: null;
            $v['date'] = substr((string) ($x['timestamp'] ?? ''), 0, 10); $v['link'] = (string) ($x['permalink'] ?? '') ?: null;
        }
    } catch (Throwable $e) { /* la publication reste listée, avec son lien */ }
    return $v;
}

/** L'état de l'espace franchisé pour une boutique. */
function sfEtat(string $shop, array $session): array
{
    $r = sfRegles();
    $s = socShop($shop);
    if ($s === null) { throw new HttpError(404, 'boutique inconnue'); }
    $posts = [];
    foreach (socItems('posts') as $p) {
        if (!empty($p['dark'])) { continue; }
        $ids = ($p['shops'] ?? null) === 'all' ? array_column(socReseau(), 'id') : array_map('strval', (array) ($p['shops'] ?? []));
        if (!in_array($shop, $ids, true)) { continue; }
        $propre = ($p['origin'] ?? '') === 'franchise';
        if (!$propre && (!$r['voirReseau'] || !in_array((string) ($p['status'] ?? ''), ['Programmé', 'Publié'], true))) { continue; }
        $posts[] = sfPostVue($p, $shop);
    }
    $camps = [];
    if ($r['voirCampagnes']) {
        $res = $r['voirResultats'] ? socResults() : [];
        foreach (socOperations() as $c) {
            if (!in_array($shop, array_map('strval', (array) ($c['shops'] ?? [])), true)) { continue; }
            $camps[] = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? ''), 'type' => (string) ($c['typeLabel'] ?? '') ?: (string) ($c['type'] ?? ''), 'start' => (string) ($c['start'] ?? ''),
                'end' => (string) ($c['end'] ?? ''), 'status' => (string) ($c['status'] ?? ''), 'products' => array_values((array) ($c['products'] ?? [])),
                'levers' => array_values((array) ($c['levers'] ?? [])), 'description' => (string) ($c['description'] ?? ''),
                'budget' => isset($c['split'][$shop]) ? (float) $c['split'][$shop] : null, 'result' => $res[(string) $c['id']][$shop] ?? null];
        }
    }
    $boutiques = array_values(array_filter(array_map(static fn ($id) => socShop($id), $session['shops'])));
    return [
        'today' => date('Y-m-d'), 'now' => date('H:i'), 'mode' => 'live',
        'me' => ['name' => (string) ($session['name'] ?? ''), 'via' => (string) ($session['via'] ?? ''), 'shops' => array_map(static fn ($b) => ['id' => $b['id'], 'name' => $b['name']], $boutiques)],
        'shop' => ['id' => $s['id'], 'name' => $s['name'], 'langs' => $s['langs'], 'hours' => $s['hours'], 'hoursNl' => $s['hoursNl'], 'address' => $s['address'],
            'page' => $s['page'], 'ig' => $s['ig'], 'fb' => $s['fb'], 'igs' => $s['igs']],
        'rules' => array_replace($r, ['ig' => $r['ig'] && !socIgUnique()]), 'templates' => sfModeles(true), 'posts' => $posts, 'campaigns' => $camps, 'catalogue' => sfCatalogue($shop),
        'quota' => ['week' => sfLundi(date('Y-m-d')), 'used' => sfQuotaUtilise($shop, date('Y-m-d')), 'max' => $r['quotaSemaine']],
        'instagramUnique' => socIgUnique(),
        // Les modèles de visuel et d'étiquette du réseau (enregistrés dans « Créer un post » du franchiseur) : l'éditeur
        // de visuel de la boutique les applique.
        'visuelsModeles' => socVisuelsModeles(socSetting('visuels_modeles')),
        'etiquettesModeles' => socEtiquettesModeles(socSetting('etiquettes_modeles')),
        // La grille Instagram de l'espace : celle du réseau (Instagram unique) ou celle du compte de la boutique.
        'grilleInstagram' => socIgUnique() || (string) ($s['ids']['instagram_user_id'] ?? '') !== '',
        // Catégorie → groupe, produit → catégorie : les liens directs du webshop (socWebshopUrl).
        'webshopPlan' => socWebshopPlanCache(),
        'webshopUrl' => rtrim((string) (socSetting('webshop_url') ?: 'https://webshop.atelierby.be/'), '/') . '/',
        'franchisorUrl' => (string) (socSetting('url_publique') ?? ''),
        // Ce qui attend la boutique : rouges et oranges non traités, en retard (commentaires et avis Google).
        'alertes' => socCommentairesAlertes($shop) + ['messages' => sfRegles()['voirMessages'] ? socMessagesAlertes($shop) : ['aRepondre' => 0]],
    ];
}

/* --- publication depuis un modèle ----------------------------------------------------- */

function sfDate(string $j): string { $t = strtotime($j); return $t ? date('d/m', $t) : $j; }

/** Une variable d'un modèle remplacée (le reste — {ville}, {horaires}… — l'est à la publication). */
function sfRemplir(string $t, array $v): string
{
    foreach ($v as $k => $x) { $t = str_replace('{' . $k . '}', (string) $x, $t); }
    return $t;
}

/**
 * Les promotions, bundles et formules liés à un produit du catalogue de la boutique, lus dans
 * TFBuddy comme dans « Créer un post » du franchiseur (src/catalogue.php) : une promotion est liée
 * si elle vise le produit ou sa catégorie ; seules celles valables dans la boutique sont rendues,
 * avec leur mention (dates, boutiques) en français et en néerlandais. `$produit` : id du catalogue (p…).
 */
function sfOffres(string $shop, string $produit): array
{
    $prod = null;
    foreach (sfCatalogue($shop) as $p) { if ((string) $p['id'] === $produit) { $prod = $p; break; } }
    $out = ['promotions' => [], 'formules' => [], 'erreur' => null];
    if ($prod === null || empty($prod['apiId'])) { return $out; }
    $pid = (int) $prod['apiId']; $cid = (int) ($prod['categoryId'] ?? 0);
    $nom = (string) (socShop($shop)['name'] ?? $shop);
    try {
        foreach (socPromotions() as $x) {
            if (!in_array($pid, $x['produits'], true) && !($cid && in_array($cid, $x['categories'], true))) { continue; }
            if (empty($x['tous']) && !in_array($shop, array_map('strval', (array) $x['shops']), true)) { continue; }  // pas dans cette boutique
            $autres = count(array_diff(array_map('strval', (array) $x['shops']), [$shop]));
            $out['promotions'][] = ['id' => (int) $x['id'], 'type' => $x['type'], 'nom' => $x['nom'], 'libelle' => $x['libelle'], 'court' => $x['court'],
                'du' => $x['du'], 'au' => $x['au'], 'tous' => !empty($x['tous']),
                'portee' => !empty($x['tous']) ? 'Toutes les boutiques' : 'Votre boutique' . ($autres ? ' et ' . $autres . ' autre' . ($autres > 1 ? 's' : '') : ''),
                'mention' => sfMention($x, $nom, 'fr'), 'mentionNl' => sfMention($x, $nom, 'nl')];
        }
    } catch (Throwable $e) { $out['erreur'] = 'Les promotions du réseau sont illisibles pour l’instant.'; }
    // Les formules du produit (sandwich + boisson…), au prix de la boutique.
    try { $out['formules'] = array_values(array_filter((socNavOffres(['shop' => $shop, 'produit' => $pid])['formules'] ?? []), static fn ($f) => ($f['nom'] ?? '') !== '')); }
    catch (Throwable $e) { /* sans formules */ }
    return $out;
}

/** La mention d'une promotion pour le post de la boutique : ses dates (si l'API en a) et où elle vaut. */
function sfMention(array $x, string $boutique, string $L): string
{
    $d = static fn (?string $iso): string => $iso ? date('d/m/Y', strtotime($iso)) : '';
    $du = $x['du'] ?? null; $au = $x['au'] ?? null;
    if ($L === 'nl') {
        $q = $du && $au ? 'van ' . $d($du) . ' tot ' . $d($au) : ($au ? 'tot ' . $d($au) : ($du ? 'vanaf ' . $d($du) : ''));
        return 'Aanbod geldig ' . implode(', ', array_filter([$q, !empty($x['tous']) ? 'in alle winkels' : 'in ' . $boutique])) . '.';
    }
    $q = $du && $au ? 'du ' . $d($du) . ' au ' . $d($au) : ($au ? 'jusqu’au ' . $d($au) : ($du ? 'à partir du ' . $d($du) : ''));
    return 'Offre valable ' . implode(', ', array_filter([$q, !empty($x['tous']) ? 'dans toutes les boutiques' : 'à ' . $boutique])) . '.';
}

/** Ajoute un bloc au texte du post : avant la ligne {lien} s'il y en a une (le lien reste en dernier), sinon à la fin. */
function sfAjouter(string $t, string $bloc): string
{
    $bloc = trim($bloc);
    if ($bloc === '' || str_contains($t, $bloc)) { return $t; }
    if (preg_match('/\n\{lien\}\s*$/u', $t, $m, PREG_OFFSET_CAPTURE)) { return substr($t, 0, $m[0][1]) . "\n" . $bloc . substr($t, $m[0][1]); }
    return (trim($t) !== '' ? rtrim($t) . "\n" : '') . $bloc;
}

/**
 * Crée un post depuis un modèle. Tout est vérifié ici : modèle ouvert, champs
 * permis et bornés, canaux, date (délai, horizon, jours, heures), quota de la
 * semaine. Le texte et le titre sont ceux du modèle ; le visuel est celui que
 * l'écran a rendu avec le style figé du modèle.
 */
function sfPostCreer(string $shop, array $b, array $session = []): array
{
    $r = sfRegles();
    $s = socShop($shop);
    if ($s === null) { throw new HttpError(404, 'boutique inconnue'); }
    $tpl = null;
    foreach (sfModeles(true) as $m) { if ((string) $m['id'] === (string) ($b['template'] ?? '')) { $tpl = $m; } }
    if ($tpl === null) { throw new HttpError(422, 'Ce modèle n’est pas (ou plus) ouvert par le franchiseur.'); }
    $err = [];

    // Canaux
    $fb = !empty($b['fb']) && !empty($tpl['fb']) && $r['fb'];
    // Instagram unique du réseau : seul le franchiseur y publie ; le franchisé n'en a qu'une vue.
    $ig = !empty($b['ig']) && !empty($tpl['ig']) && $r['ig'] && !socIgUnique();
    if (!$fb && !$ig) { $err[] = 'Choisissez au moins un canal autorisé (Facebook ou Instagram).'; }

    // Format : post (fil), story seule, ou post + story (la story : un visuel 9:16, sans texte ni lien).
    $format = in_array($b['format'] ?? 'post', ['post', 'story', 'post_story'], true) ? (string) ($b['format'] ?? 'post') : 'post';
    if ($format !== 'post' && !$r['story']) { $err[] = 'Le réseau n’ouvre pas les stories dans cet espace.'; }
    if ($format !== 'post' && (($tpl['visual']['style'] ?? '') === 'fixe')) { $err[] = 'Ce modèle a un visuel du réseau : il ne part qu’en post.'; }

    // Date et heure
    $date = (string) ($b['date'] ?? ''); $time = (string) ($b['time'] ?? '');
    $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? strtotime($date . ' ' . $time) : false;
    if ($t === false) { $err[] = 'Date ou heure invalide.'; }
    else {
        if ($t < time() + $r['delaiMinHeures'] * 3600) { $err[] = 'Programmez au moins ' . $r['delaiMinHeures'] . ' h à l’avance.'; }
        if ($t > time() + $r['horizonJours'] * 86400) { $err[] = 'Pas plus de ' . $r['horizonJours'] . ' jours à l’avance.'; }
        if (!in_array((int) date('w', $t), $r['jours'], true)) { $err[] = 'Ce jour n’est pas ouvert à la publication (' . implode(', ', array_map(static fn ($j) => SF_JOURS[$j], $r['jours'])) . ').'; }
        if ($time < $r['heureMin'] || $time > $r['heureMax']) { $err[] = 'Heure de publication entre ' . $r['heureMin'] . ' et ' . $r['heureMax'] . '.'; }
        if ($r['quotaSemaine'] < 1 || sfQuotaUtilise($shop, $date) >= $r['quotaSemaine']) { $err[] = 'Quota atteint : ' . $r['quotaSemaine'] . ' post(s) par semaine pour votre boutique.'; }
    }

    // Produit
    $prod = null;
    if (($tpl['product'] ?? 'aucun') === 'choix') {
        foreach (sfCatalogue($shop) as $p) { if ((string) $p['id'] === (string) ($b['product'] ?? '')) { $prod = $p; } }
        if ($prod === null) { $err[] = 'Choisissez un produit de votre catalogue.'; }
    }
    $prixNum = $prod && $prod['price'] !== '' ? (float) str_replace(',', '.', (string) $prod['price']) : null;
    $kind = (string) ($tpl['promo']['kind'] ?? 'aucune');

    // Promotion, bundle ou formule de TFBuddy liés au produit : relus ici (jamais pris tels quels de l'écran),
    // vérifiés encore à la publication (socVerifierOffres). Un modèle à remise ou à prix promo garde la sienne.
    $api = null;
    if (is_array($b['offre'] ?? null) && ($b['offre']['genre'] ?? '') !== '') {
        if ($prod === null) { $err[] = 'Choisissez d’abord le produit de la promotion.'; }
        elseif (in_array($kind, ['remise', 'prix'], true)) { $err[] = 'Ce modèle a sa propre remise : prenez un modèle sans remise (ex. « Produit du jour ») pour publier une promotion du réseau.'; }
        else {
            $o = sfOffres($shop, (string) $prod['id']);
            if (($b['offre']['genre'] ?? '') === 'formule') {
                foreach ($o['formules'] as $x) { if ((string) $x['nom'] === (string) ($b['offre']['nom'] ?? '')) { $api = ['genre' => 'formule'] + $x; } }
            } else {
                foreach ($o['promotions'] as $x) { if ((int) $x['id'] === (int) ($b['offre']['id'] ?? 0)) { $api = ['genre' => 'promotion'] + $x; } }
            }
            if ($api === null) { $err[] = 'Cette promotion n’est plus valable dans votre boutique : rechargez la page.'; }
            elseif ($t !== false && ($api['genre'] ?? '') === 'promotion') {
                if (!empty($api['au']) && $api['au'] < $date) { $err[] = 'La promotion « ' . $api['nom'] . ' » se termine le ' . sfDate($api['au']) . ', avant la publication.'; }
                if (!empty($api['du']) && $api['du'] > $date) { $err[] = 'La promotion « ' . $api['nom'] . ' » ne commence que le ' . sfDate($api['du']) . ' : publiez à partir de cette date.'; }
            }
        }
    }
    $court = $api ? ((string) ($api['court'] ?? '') !== '' ? (string) $api['court'] : (string) $api['nom']) : '';

    // Promotion
    $promo = null;
    if ($api && $kind === 'offre') {
        $promo = ['kind' => 'offre', 'value' => $court, 'label' => $court];
    } elseif ($api) {
        $promo = ['kind' => 'api', 'value' => $court, 'label' => $court];
    } elseif ($kind === 'remise') {
        $min = (int) ($tpl['promo']['min'] ?? 5); $max = min((int) ($tpl['promo']['max'] ?? 30), $r['remiseMax']);
        $v = (int) ($b['discount'] ?? 0);
        if ($v < $min || $v > $max) { $err[] = 'Remise entre ' . $min . ' et ' . $max . ' %.'; }
        $promo = ['kind' => 'remise', 'value' => $v, 'label' => '-' . $v . ' %'];
    } elseif ($kind === 'offre') {
        $offres = array_values(array_map('strval', (array) ($tpl['promo']['offres'] ?? [])));
        $v = (string) ($b['offer'] ?? '');
        if (!in_array($v, $offres, true)) { $err[] = 'Choisissez une offre de la liste.'; }
        $promo = ['kind' => 'offre', 'value' => $v, 'label' => $v];
    } elseif ($kind === 'prix') {
        $v = (float) str_replace(',', '.', (string) ($b['promoPrice'] ?? ''));
        if ($v <= 0 || ($prixNum !== null && $v >= $prixNum)) { $err[] = 'Le prix promotionnel doit être inférieur au prix du catalogue.'; }
        if ($prixNum !== null && $prixNum > 0 && (1 - $v / $prixNum) * 100 > $r['remiseMax'] + 0.01) { $err[] = 'Remise supérieure au maximum du réseau (' . $r['remiseMax'] . ' %).'; }
        $promo = ['kind' => 'prix', 'value' => number_format($v, 2, ',', ''), 'label' => number_format($v, 2, ',', '') . ' €'];
    }

    // Période
    $du = null; $au = null;
    if (!empty($tpl['period']) && $api && !empty($api['du']) && !empty($api['au'])) {
        // Les dates sont celles de la promotion du réseau.
        $du = (string) $api['du']; $au = (string) $api['au'];
        if ($promo) { $promo += ['from' => $du, 'to' => $au]; }
    } elseif (!empty($tpl['period'])) {
        $du = (string) ($b['from'] ?? ''); $au = (string) ($b['to'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || $au < $du) { $err[] = 'Période invalide (du … au …).'; }
        elseif ((strtotime($au) - strtotime($du)) / 86400 + 1 > $r['promoDureeMax']) { $err[] = 'Une promotion dure au plus ' . $r['promoDureeMax'] . ' jours.'; }
        elseif ($t !== false && $au < $date) { $err[] = 'La promotion se termine avant la publication du post.'; }
        if ($promo) { $promo += ['from' => $du, 'to' => $au]; }
    }

    // Phrase libre
    $libre = '';
    if ((int) ($tpl['free'] ?? 0) > 0) {
        $libre = trim(preg_replace('/\s+/u', ' ', (string) ($b['free'] ?? '')));
        if ($libre === '') { $err[] = 'Complétez la phrase.'; }
        if (mb_strlen($libre) > (int) $tpl['free']) { $err[] = 'Phrase limitée à ' . (int) $tpl['free'] . ' caractères.'; }
        if (preg_match('#https?://|www\.|@\w|\#\w#iu', $libre)) { $err[] = 'Pas de lien, de compte (@) ni de hashtag dans la phrase.'; }
    }

    // Visuel : figé tel quel (modèle créé depuis un post du réseau), sinon rendu par l'écran avec le style du modèle.
    $fixe = (($tpl['visual']['style'] ?? '') === 'fixe');
    $medias = [];
    if ($fixe) {
        foreach ((array) ($tpl['visual']['media'] ?? []) as $m) {
            $src = (string) ($m['src'] ?? '');
            if ($src !== '' && !str_starts_with($src, 'data:')) { $medias[] = ['src' => $src, 'kind' => ($m['kind'] ?? 'img') === 'video' ? 'video' : 'img', 'name' => 'Visuel du réseau « ' . $tpl['name'] . ' »']; }
        }
        $img0 = array_values(array_filter($medias, static fn ($m) => $m['kind'] === 'img'))[0]['src'] ?? '';
        $visuel = $img0 !== '' ? $img0 : (string) ($tpl['visual']['src'] ?? '');
        if (!$medias) { $err[] = 'Le visuel de ce modèle manque : prévenez le réseau.'; }
    } else {
        $visuel = (string) ($b['visual'] ?? '');
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $visuel) || strlen($visuel) > 6_000_000) { $err[] = 'Le visuel n’a pas pu être préparé : rechargez la page.'; }
    }
    $visuelStory = null;
    if ($format === 'story' && !$fixe && !sfFormatStory($visuel)) { $err[] = 'Une story demande un visuel au format story (9:16) : rechargez la page.'; }
    if ($format === 'post_story' && !$fixe) {
        $visuelStory = (string) ($b['visualStory'] ?? '');
        if (!sfFormatStory($visuelStory) || strlen($visuelStory) > 6_000_000) { $err[] = 'Le visuel de la story n’a pas pu être préparé : rechargez la page.'; $visuelStory = null; }
    }
    $photoMode = (string) ($tpl['photo'] ?? 'catalogue');
    $photo = (string) ($b['photo'] ?? '');
    if ($photoMode === 'upload' && !preg_match('#^data:image/#', $photo)) { $err[] = 'Ajoutez votre photo.'; }

    // Lien : celui du modèle, ou le webshop de la boutique (sa page, celle du produit, sa catégorie). Jamais un lien libre.
    $lien = trim((string) ($tpl['link'] ?? ''));
    $choix = (string) ($b['link'] ?? ($lien !== '' ? 'modele' : 'aucun'));
    $lien = match ($choix) {
        'modele' => $lien, 'webshop' => '{webshop}',
        'produit' => !empty($prod['apiId']) && ($prod['webshop'] ?? true) ? '{webshop_produit}' : '{webshop}',
        'categorie' => !empty($prod['categoryId']) ? '{webshop_categorie}' : '{webshop}',
        default => '',
    };

    // Répétition : chaque date suit les règles du réseau (horizon, jours, heures, quota de sa semaine),
    // et une promotion du réseau doit valoir à chaque date.
    $dates = [$date];
    $rec = is_array($b['recurrence'] ?? null) ? $b['recurrence'] : null;
    $freq = $rec ? (string) ($rec['freq'] ?? '') : '';
    if ($rec && $freq !== '' && $freq !== 'une') {
        $n = (int) ($rec['n'] ?? 0);
        if ($r['recurrenceMax'] < 2) { $err[] = 'Le réseau n’ouvre pas les posts récurrents dans cet espace.'; }
        elseif (!in_array($freq, ['jour', 'semaine', '2semaines', 'mois'], true)) { $err[] = 'Répétition inconnue.'; }
        elseif ($n < 2 || $n > $r['recurrenceMax']) { $err[] = 'Répétez le post de 2 à ' . $r['recurrenceMax'] . ' fois.'; }
        elseif (!empty($tpl['period'])) { $err[] = 'Une promotion datée ne se répète pas : publiez-la une fois.'; }
        elseif ($t !== false) {
            $dates = sfDatesSerie($date, $freq, $n);
            $parSemaine = [];
            foreach ($dates as $k => $d) {
                $parSemaine[sfLundi($d)] = ($parSemaine[sfLundi($d)] ?? 0) + 1;
                if ($k === 0) { continue; }
                $tk = strtotime($d . ' ' . $time);
                $quand = sfDate($d);
                if ($tk > time() + $r['horizonJours'] * 86400) { $err[] = 'Le ' . $quand . ' est au-delà de ' . $r['horizonJours'] . ' jours : répétez-le moins de fois.'; break; }
                if (!in_array((int) date('w', $tk), $r['jours'], true)) { $err[] = 'Le ' . $quand . ' (' . SF_JOURS[(int) date('w', $tk)] . ') n’est pas ouvert à la publication.'; }
                if (sfQuotaUtilise($shop, $d) + $parSemaine[sfLundi($d)] > $r['quotaSemaine']) { $err[] = 'Quota atteint la semaine du ' . sfDate(sfLundi($d)) . ' : ' . $r['quotaSemaine'] . ' post(s) par semaine.'; }
                if ($api && ($api['genre'] ?? '') === 'promotion' && ((!empty($api['au']) && $api['au'] < $d) || (!empty($api['du']) && $api['du'] > $d))) {
                    $err[] = 'La promotion « ' . $api['nom'] . ' » ne vaut pas le ' . $quand . ' : répétez-la moins de fois.';
                }
            }
            if (($parSemaine[sfLundi($date)] ?? 1) > 1 && sfQuotaUtilise($shop, $date) + $parSemaine[sfLundi($date)] > $r['quotaSemaine']) { $err[] = 'Quota atteint : ' . $r['quotaSemaine'] . ' post(s) par semaine pour votre boutique.'; }
        }
    }

    if ($err) { throw new HttpError(422, implode(' ', array_unique($err))); }

    $vars = ['produit' => $prod['name'] ?? '', 'prix' => $prod['price'] ?? '', 'remise' => $kind === 'remise' ? $promo['value'] : '',
        'offre' => $kind === 'offre' ? $promo['value'] : '', 'prix_promo' => $kind === 'prix' ? $promo['value'] : '', 'du' => $du ? sfDate($du) : '', 'au' => $au ? sfDate($au) : '', 'libre' => $libre];
    $varsNl = array_replace($vars, ['produit' => $prod['nameNl'] ?? ($prod['name'] ?? '')]);
    // L'offre de TFBuddy : son libellé dans le texte, puis sa mention (dates, boutiques), avant le lien.
    $texte = sfRemplir((string) ($tpl['text'] ?? ''), $vars); $texteNl = sfRemplir((string) ($tpl['textNl'] ?? ''), $varsNl);
    if ($api) {
        $texte = sfAjouter(sfAjouter($texte, (string) $api['libelle']), (string) ($api['mention'] ?? ''));
        if (trim($texteNl) !== '') { $texteNl = sfAjouter($texteNl, (string) ($api['mentionNl'] ?? '')); }
    }
    $statut = $r['validation'] === 'toujours' || ($r['validation'] === 'modele' && !empty($tpl['validation'])) ? 'À valider' : 'Programmé';
    $auteur = (($session['via'] ?? '') === 'apercu' ? 'Franchiseur (aperçu) · ' : 'Franchisé · ') . $s['name'];
    $post = ['id' => 'f' . date('ymdHis') . bin2hex(random_bytes(2)), 'origin' => 'franchise', 'author' => $auteur, 'template' => (string) $tpl['id'],
        'type' => (string) ($tpl['type'] ?? 'Produit'), 'title' => mb_substr(sfRemplir((string) ($tpl['title'] ?? $tpl['name']), $vars), 0, 120),
        'shops' => [$shop], 'ch' => [$shop => ['fb' => $fb, 'ig' => $ig]], 'fb' => $fb, 'ig' => $ig, 'status' => $statut, 'date' => $date, 'time' => $time,
        'text' => $texte, 'textNl' => $texteNl, 'langs' => $s['langs'],
        'img' => $visuel, 'media' => $fixe ? $medias : [['src' => $visuel, 'kind' => 'img', 'name' => 'Visuel du modèle « ' . $tpl['name'] . ' »']],
        // Story : seule (le visuel 9:16 remplace le post dans le fil), ou aussi (le post, puis sa story : `storyImg`).
        'story' => ['post' => null, 'story' => 'seule', 'post_story' => 'aussi'][$format], 'storyImg' => $visuelStory,
        'promo' => $promo, 'product' => $prod['id'] ?? null,
        'offre' => $api === null ? null : ($api['genre'] === 'formule'
            ? ['genre' => 'formule', 'nom' => $api['nom'], 'libelle' => $api['libelle'], 'prix' => $api['prix'] ?? null, 'shop' => $shop]
            : ['genre' => 'promotion', 'id' => (int) $api['id'], 'type' => $api['type'], 'nom' => $api['nom'], 'libelle' => $api['libelle'], 'du' => $api['du'], 'au' => $api['au'], 'tous' => $api['tous'], 'portee' => $api['portee']]), 'spread' => 0, 'campaign' => ((string) ($tpl['campaign'] ?? '')) ?: null, 'link' => $lien,
        'fields' => ['product' => $prod['id'] ?? null, 'offre' => $api ? ($api['genre'] === 'formule' ? 'formule:' . $api['nom'] : 'promotion:' . $api['id']) : null, 'discount' => $b['discount'] ?? null, 'offer' => $b['offer'] ?? null, 'promoPrice' => $b['promoPrice'] ?? null,
            'from' => $du, 'to' => $au, 'free' => $libre, 'photo' => $photo !== '' ? $photo : null]];
    // Un post récurrent : une occurrence par date, chacune un post (validée, annulée, publiée à part), liées par leur série.
    $posts = [];
    $serie = count($dates) > 1 ? ['id' => 's' . substr((string) $post['id'], 1), 'freq' => $freq, 'n' => count($dates)] : null;
    foreach ($dates as $k => $d) {
        $posts[] = array_replace($post, ['id' => $k === 0 ? $post['id'] : $post['id'] . '-' . ($k + 1), 'date' => $d,
            'serie' => $serie ? $serie + ['rang' => $k + 1] : null]);
    }
    $out = socStateSave(['changes' => ['posts' => ['upsert' => $posts]]]);
    $p = socItem('posts', (string) $post['id']) ?? $post;
    if ($out['errors']) {
        // Refusé par une règle du réseau (verrou d'une frise…) : on ne garde pas un post que le franchisé ne voit pas.
        foreach ($posts as $x) { socItemDelete('posts', (string) $x['id']); socUnqueue((string) $x['id']); }
        throw new HttpError(409, implode(' · ', array_column($out['errors'], 'error')));
    }
    $quoi = ['post' => '', 'story' => ' (story)', 'post_story' => ' (post + story)'][$format];
    socLog($auteur, $statut === 'À valider' ? 'Proposition' : 'Programmation',
        '« ' . $p['title'] . ' »' . $quoi . ' (' . $tpl['name'] . ') le ' . sfDate($date) . ' à ' . $time
        . ($serie ? ', répété ' . count($dates) . ' fois (' . ['jour' => 'chaque jour', 'semaine' => 'chaque semaine', '2semaines' => 'toutes les 2 semaines', 'mois' => 'chaque mois'][$freq] . ', jusqu’au ' . sfDate(end($dates)) . ')' : '')
        . ($statut === 'À valider' ? ' — à valider' : ''));
    return sfPostVue($p, $shop);
}

/** Un visuel au format story (9:16, de 1,7 à 1,85 fois plus haut que large), en data: (image) ? */
function sfFormatStory(string $data): bool
{
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $data, $m)) { return false; }
    $bin = base64_decode(substr($data, strlen($m[0])), true);
    $dim = $bin !== false ? @getimagesizefromstring($bin) : false;
    if (!$dim || $dim[0] <= 0) { return false; }
    $k = $dim[1] / $dim[0];
    return $k >= 1.7 && $k <= 1.85;
}

/** Les dates d'un post récurrent : la première, puis chaque jour, semaine, 2 semaines ou mois (même jour du mois, borné). */
function sfDatesSerie(string $date, string $freq, int $n): array
{
    $out = [];
    $t0 = strtotime($date . ' 12:00:00');
    for ($k = 0; $k < $n; $k++) {
        if ($freq === 'mois') {
            $y = (int) date('Y', $t0); $mo = (int) date('n', $t0) + $k; $y += intdiv($mo - 1, 12); $mo = ($mo - 1) % 12 + 1;
            $out[] = sprintf('%04d-%02d-%02d', $y, $mo, min((int) date('j', $t0), (int) date('t', mktime(12, 0, 0, $mo, 1, $y))));
        } else {
            $out[] = date('Y-m-d', strtotime('+' . ($k * ['jour' => 1, 'semaine' => 7, '2semaines' => 14][$freq]) . ' days', $t0));
        }
    }
    return $out;
}

/**
 * Annule un post du franchisé tant qu'il n'est pas publié ; `$serie` : et toutes les occurrences de sa
 * série qui ne sont pas encore parties (celles déjà publiées restent). Rend le nombre de posts retirés.
 */
function sfPostAnnuler(string $shop, string $id, bool $serie = false): int
{
    $p = socItem('posts', $id);
    $aMoi = static fn (?array $x) => $x !== null && ($x['origin'] ?? '') === 'franchise' && array_map('strval', (array) ($x['shops'] ?? [])) === [$shop];
    $ouvert = static fn (array $x) => in_array((string) ($x['status'] ?? ''), ['À valider', 'Programmé', 'Brouillon'], true);
    if (!$aMoi($p)) { throw new HttpError(404, 'post introuvable'); }
    $ids = [];
    if ($serie && !empty($p['serie']['id'])) {
        foreach (socItems('posts') as $x) { if ($aMoi($x) && ($x['serie']['id'] ?? '') === $p['serie']['id'] && $ouvert($x)) { $ids[] = (string) $x['id']; } }
    }
    if (!$ids) {
        if (!$ouvert($p)) { throw new HttpError(409, 'Ce post est déjà publié : il ne peut plus être annulé.'); }
        $ids = [$id];
    }
    socStateSave(['changes' => ['posts' => ['delete' => $ids]]]);
    socLog('Franchisé · ' . (socShop($shop)['name'] ?? $shop), 'Annulation', '« ' . ($p['title'] ?? $id) . ' » retiré' . (count($ids) > 1 ? ' (' . count($ids) . ' dates de la série)' : ''));
    return count($ids);
}

/**
 * Le suivi de la boutique (src/suivi.php) limité à SA Page et à son Instagram :
 * abonnés gagnés / perdus face au budget dépensé pour elle. Lu en direct chez Meta.
 */
function sfSuivi(string $shop, int $jours): array
{
    if (!sfRegles()['voirSuivi']) { throw new HttpError(403, 'Le réseau ne partage pas le suivi des boutiques dans cet espace.'); }
    $s = socShop($shop);
    $page = (string) ($s['ids']['page_id'] ?? '');
    $vide = ['jours' => in_array($jours, SOC_SUIVI_PERIODES, true) ? $jours : 28, 'lignes' => [], 'devise' => 'EUR', 'erreurs' => []];
    if ($page === '') { return $vide + ['message' => 'La Page Facebook de votre boutique n’est pas encore reliée par le réseau.']; }
    if (!Meta::configured()) { return $vide + ['message' => 'Le réseau n’a pas encore relié ses comptes Meta.']; }
    $r = socSuiviAbonnes($jours, $page);
    // Rien des autres boutiques : ni leurs lignes, ni la dépense non attribuée, ni le détail des erreurs du réseau.
    return ['du' => $r['du'], 'au' => $r['au'], 'jours' => $r['jours'], 'devise' => $r['devise'], 'le' => $r['le'], 'lignes' => $r['lignes'],
        'erreurs' => $r['erreurs'] ? ['une partie des chiffres n’a pas pu être lue chez Meta'] : [],
        'message' => $r['lignes'] ? null : 'La Page de votre boutique n’est pas accessible au réseau chez Meta.'];
}

/**
 * L'analyse des vues de la boutique (« Mes réseaux » → Analyse) : pour sa Page et son Instagram
 * (ou celui du réseau, comme au calendrier), les vues jour par jour, les vues de chaque publication
 * de la période et l'évolution des abonnés (total actuel, gagnés / perdus par jour). Sans le budget
 * publicitaire (c'est le « Suivi de ma boutique »). Lu en direct chez Meta ; motifs sans détail technique.
 */
function sfVues(string $shop, int $jours): array
{
    if (!sfRegles()['voirAnalyse']) { throw new HttpError(403, 'Le réseau ne partage pas l’analyse des vues dans cet espace.'); }
    $jours = in_array($jours, SOC_SUIVI_PERIODES, true) ? $jours : 28;
    $au = date('Y-m-d', strtotime('-1 day'));  // les insights d'un jour sont complets le lendemain
    $du = date('Y-m-d', strtotime($au . ' -' . ($jours - 1) . ' days'));
    $out = ['du' => $du, 'au' => $au, 'jours' => $jours, 'le' => date('Y-m-d H:i'), 'comptes' => [], 'publications' => [], 'erreurs' => [], 'message' => null];
    if (!Meta::configured()) { return ['message' => 'Le réseau n’a pas encore relié ses comptes Meta.'] + $out; }
    try { $garde = sfCalComptes($shop); $tous = socCalComptes(); }
    catch (MetaError $e) { return ['message' => 'Les comptes Meta du réseau sont illisibles pour l’instant.'] + $out; }
    if (!$garde) { return ['message' => 'La Page Facebook de votre boutique n’est pas encore reliée par le réseau.'] + $out; }
    $sans = static fn (?string $m, string $dit) => $m === null ? null : (str_contains($m, '30 derniers jours') && !str_contains($m, 'permission') ? 'Instagram ne donne que les 30 derniers jours, jour par jour.' : $dit);
    $t0 = strtotime($du . ' 00:00:00');
    $illisible = false;
    foreach ($garde as $k => $reseau) {
        $c = $tous[$k] ?? null;
        if ($c === null) { continue; }
        $fb = $c['type'] === 'facebook';
        $ab = $fb ? socSuiviPage($c, $du, $au) : socSuiviInstagram($c, $du, $au);
        $v = $fb ? socVuesPage($c, $du, $au) : socVuesInstagram($c, $du, $au);
        try { $pubs = socVuesPublications($c + ['username' => (string) ($ab['username'] ?? '')], $t0, time()); }
        catch (MetaError $e) { $pubs = ['publications' => [], 'motif' => 'illisible']; $illisible = true; }
        $nom = $fb ? (string) ($ab['name'] ?? $c['name']) : ((string) ($ab['username'] ?? '') !== '' ? '@' . $ab['username'] : $c['name']);
        foreach ($pubs['publications'] as $p) { $out['publications'][] = ['accountName' => $nom] + $p; }
        $out['comptes'][] = ['key' => $k, 'type' => $c['type'], 'name' => $nom, 'picture' => $ab['picture'] ?? null,
            'link' => $fb ? ($ab['link'] ?? null) : ((string) ($ab['username'] ?? '') !== '' ? 'https://www.instagram.com/' . $ab['username'] . '/' : null), 'reseau' => $reseau,
            'vues' => ['metrique' => $v['metrique'], 'serie' => $v['serie'], 'total' => $v['total'],
                'motif' => $sans($v['motif'], 'Meta ne partage pas encore les vues jour par jour de ce compte.')],
            'abonnes' => ['followers' => $ab['followers'], 'serie' => $ab['serie'], 'gagnes' => $ab['gagnes'], 'perdus' => $fb ? $ab['perdus'] : null,
                'motif' => $sans($ab['motif'], 'Meta ne partage pas l’historique des abonnés de ce compte pour l’instant.')],
            'publicationsMotif' => $pubs['motif'] !== null ? 'Les vues des publications ne sont pas partagées par Meta pour ce compte.' : null];
    }
    // Les plus vues d'abord ; sans chiffre, à la fin, les plus récentes d'abord.
    usort($out['publications'], static fn ($a, $b) => (($b['vues'] ?? -1) <=> ($a['vues'] ?? -1)) ?: strcmp($b['date'] . $b['time'], $a['date'] . $a['time']));
    if ($illisible) { $out['erreurs'][] = 'une partie des publications n’a pas pu être lue chez Meta'; }
    return $out;
}

/**
 * Les comptes Meta que voit la boutique : sa Page Facebook, son Instagram (s'il lui est
 * propre) et, en Instagram unique, celui du réseau si la règle « voir les posts du réseau »
 * le permet. Clés `fb:…` / `ig:…` de socCalComptes().
 */
function sfCalComptes(string $shop): array
{
    $unique = socIgUnique();
    $igReseau = $unique && sfRegles()['voirReseau'] ? (string) (socBrand()['ids']['instagram_user_id'] ?? '') : '';
    $page = (string) (socShop($shop)['ids']['page_id'] ?? '');
    $out = [];
    foreach (socCalComptes() as $k => $c) {
        $saPage = $c['type'] === 'facebook' && ((string) $c['shop'] === $shop || ($page !== '' && (string) $c['id'] === $page));
        if ($saPage || ($c['type'] === 'instagram' && !$unique && (string) $c['shop'] === $shop)) { $out[$k] = false; }
        elseif ($c['type'] === 'instagram' && $igReseau !== '' && (string) $c['id'] === $igReseau) { $out[$k] = true; }
    }
    return $out;  // clé → compte du réseau ?
}

/**
 * Le calendrier Meta de la boutique : les publications de sa Page (et de son Instagram, ou de
 * celui du réseau) entre deux dates, lues en direct chez Meta comme au Calendrier du franchiseur
 * (src/calendriers.php), plus ce qui y est programmé dans Meta Business Suite. Rien des autres
 * boutiques : ni leurs comptes, ni le détail technique des erreurs.
 */
function sfCalendrier(string $shop, string $du, string $au): array
{
    $vide = ['du' => $du, 'au' => $au, 'le' => date('Y-m-d H:i'), 'comptes' => [], 'posts' => [], 'erreurs' => []];
    if (!Meta::configured()) { return $vide + ['message' => 'Le réseau n’a pas encore relié ses comptes Meta.']; }
    try { $garde = sfCalComptes($shop); }
    catch (MetaError $e) { return $vide + ['message' => 'Les comptes Meta du réseau sont illisibles pour l’instant.']; }
    if (!$garde) { return $vide + ['message' => 'La Page Facebook de votre boutique n’est pas encore reliée par le réseau.']; }
    $r = socCalendriersLire($du, $au, static fn ($c) => isset($garde[$c['key']]));
    $comptes = array_map(static fn ($c) => ['key' => $c['key'], 'type' => $c['type'], 'name' => $c['name'], 'username' => $c['username'], 'picture' => $c['picture'],
        'link' => $c['link'], 'reseau' => $garde[$c['key']] ?? false, 'publies' => $c['publies'], 'programmes' => $c['programmes'],
        'erreur' => $c['erreur'] !== null ? 'lecture impossible chez Meta pour l’instant' : null], $r['comptes']);
    $champs = array_flip(['id', 'channel', 'account', 'accountName', 'date', 'time', 'type', 'title', 'text', 'img', 'status', 'permalink', 'stats', 'campaign']);
    $posts = array_map(static fn ($p) => array_intersect_key($p, $champs), $r['posts']);
    return ['du' => $r['du'], 'au' => $r['au'], 'le' => $r['le'], 'comptes' => $comptes, 'posts' => array_values($posts),
        'erreurs' => $r['erreurs'] ? ['une partie des publications n’a pas pu être lue chez Meta'] : [], 'message' => null];
}

/** Les chiffres (vues, personnes touchées…) d'une publication du calendrier de la boutique, lus en direct. */
function sfCalStats(string $shop, string $id, string $compte): array
{
    if (!isset(sfCalComptes($shop)[$compte])) { throw new HttpError(404, 'Cette publication n’est pas à votre boutique.'); }
    $r = socCalStats($id, $compte);
    if ($r['motif'] !== null) { $r['motif'] = 'Les chiffres détaillés ne sont pas partagés par Meta pour cette publication.'; }
    return $r;
}

/**
 * La grille Instagram de la boutique, comme la « Grille Instagram » du franchiseur : le profil
 * et ses dernières publications lus en direct chez Meta, et au-dessus, du plus récent au plus
 * ancien, ce qui est programmé (une frise = 3 cases). En Instagram unique, c'est le compte du
 * réseau (ses posts programmés si la règle « voir les posts du réseau » le permet) ; sinon celui
 * de la boutique. Lecture seule : le franchisé ne déplace rien.
 */
function sfInstagram(string $shop): array
{
    $unique = socIgUnique();
    $s = $unique ? socBrand() : socShop($shop);
    $ig = (string) ($s['ids']['instagram_user_id'] ?? '');
    $compte = $unique ? 'marque' : $shop;
    $out = ['reseau' => $unique, 'username' => '', 'name' => '', 'picture' => null, 'followers' => null, 'media' => [], 'programmes' => [], 'erreur' => null, 'le' => date('Y-m-d H:i')];
    if ($ig === '') { $out['erreur'] = 'Votre boutique n’a pas de compte Instagram relié par le réseau.'; return $out; }
    // Les frises déjà publiées par le module : leurs ids Instagram, tuile 3 → 1.
    $frises = [];
    foreach (socItems('posts') as $p) {
        foreach (array_values((array) ($p['frise']['ig'][$compte] ?? [])) as $j => $mid) { if (is_scalar($mid)) { $frises[(string) $mid] = ['title' => (string) ($p['title'] ?? ''), 'tile' => 2 - $j]; } }
    }
    try {
        $tok = socIgToken($ig, (string) ($s['ids']['page_id'] ?? ''));
        $pr = Meta::call('GET', $ig, ['fields' => 'username,name,profile_picture_url,followers_count,media_count'], $tok);
        $out['username'] = (string) ($pr['username'] ?? ''); $out['name'] = (string) ($pr['name'] ?? '');
        $out['picture'] = (string) ($pr['profile_picture_url'] ?? '') ?: null; $out['followers'] = isset($pr['followers_count']) ? (int) $pr['followers_count'] : null;
        $r = Meta::call('GET', $ig . '/media', ['fields' => SOC_CAL_IG, 'limit' => 30], $tok);
        foreach ((array) ($r['data'] ?? []) as $x) {
            if (empty($x['id'])) { continue; }
            $video = strtoupper((string) ($x['media_type'] ?? '')) === 'VIDEO';
            $pt = strtoupper((string) ($x['media_product_type'] ?? ''));
            $out['media'][] = ['id' => (string) $x['id'], 'src' => (string) (($video ? ($x['thumbnail_url'] ?? '') : '') ?: ($x['media_url'] ?? '')), 'video' => $video,
                'type' => $pt === 'REELS' ? 'Reel' : (strtoupper((string) ($x['media_type'] ?? '')) === 'CAROUSEL_ALBUM' ? 'Carrousel' : ($video ? 'Vidéo' : 'Photo')),
                'permalink' => (string) ($x['permalink'] ?? ''), 'at' => date('Y-m-d H:i', strtotime((string) ($x['timestamp'] ?? '')) ?: time()),
                'caption' => mb_substr((string) ($x['caption'] ?? ''), 0, 300), 'likes' => isset($x['like_count']) ? (int) $x['like_count'] : null,
                'comments' => isset($x['comments_count']) ? (int) $x['comments_count'] : null, 'frise' => $frises[(string) $x['id']] ?? null];
        }
    } catch (MetaError $e) { $out['erreur'] = 'Instagram illisible pour l’instant chez Meta.'; }
    // Au-dessus : les posts programmés sur ce compte (ceux du réseau si la règle le permet, ceux de la boutique toujours).
    $voir = sfRegles()['voirReseau'];
    foreach (socItems('posts') as $p) {
        $propre = ($p['origin'] ?? '') === 'franchise' && array_map('strval', (array) ($p['shops'] ?? [])) === [$shop];
        $st = (string) ($p['status'] ?? '');
        if (!empty($p['dark']) || !($st === 'Programmé' || ($propre && $st === 'À valider')) || !($propre || $voir)) { continue; }
        if (!in_array($compte, socShopsIg($p), true)) { continue; }
        $t = strtotime(($p['date'] ?? '') . ' ' . ($p['time'] ?? ''));
        if ($t === false || $t < time() - 300) { continue; }
        $tuiles = array_values(array_filter(array_map('strval', (array) ($p['frise']['tiles'] ?? [])), static fn ($u) => $u !== ''));
        $out['programmes'][] = ['id' => (string) $p['id'], 'title' => (string) ($p['title'] ?? ''), 'date' => (string) ($p['date'] ?? ''), 'time' => (string) ($p['time'] ?? ''),
            'status' => $st, 'own' => $propre, 'frise' => count($tuiles) === 3 ? $tuiles : null, 'src' => (string) ($p['img'] ?? '')];
    }
    usort($out['programmes'], static fn ($a, $c) => strcmp($c['date'] . $c['time'], $a['date'] . $a['time']));
    return $out;
}

/**
 * À droite de la grille Instagram : les abonnés du compte de la grille (celui du réseau en Instagram
 * unique, sinon celui de la boutique) jour par jour sur 30 jours — nouveaux, désabonnements, total —
 * et les comptes touchés. Règle « voir les vues et les abonnés ». Motifs sans détail technique.
 */
function sfInstagramAbonnes(string $shop): array
{
    if (!sfRegles()['voirAnalyse']) { throw new HttpError(403, 'Le réseau ne partage pas les abonnés dans cet espace.'); }
    $unique = socIgUnique();
    $s = $unique ? socBrand() : socShop($shop);
    $ig = (string) ($s['ids']['instagram_user_id'] ?? '');
    $vide = ['reseau' => $unique, 'followers' => null, 'serie' => null, 'gagnes' => null, 'perdus' => null, 'reach' => null, 'motif' => null, 'le' => date('Y-m-d H:i')];
    if ($ig === '') { return ['message' => 'Votre boutique n’a pas de compte Instagram relié par le réseau.'] + $vide; }
    if (!Meta::configured()) { return ['message' => 'Le réseau n’a pas encore relié ses comptes Meta.'] + $vide; }
    $r = socIgAbonnes(['id' => $ig, 'token' => socIgToken($ig, (string) ($s['ids']['page_id'] ?? ''))], 30);
    $m = $r['motif'];
    $r['motif'] = $m === null ? null : (str_contains($m, 'illisible') ? 'Instagram illisible pour l’instant chez Meta.'
        : (str_contains($m, 'désabonnements') && !str_contains($m, 'permission') ? 'Meta ne donne pas les désabonnements jour par jour de ce compte : seuls les nouveaux abonnés s’affichent.'
        : 'Meta ne partage pas l’historique des abonnés de ce compte pour l’instant.'));
    return ['reseau' => $unique, 'message' => null] + $r;
}

/* --- routes ------------------------------------------------------------------------------- */

/** Les routes de l'espace franchisé (session franchisé) — null si la route n'en est pas une. */
function sfRoute(string $method, string $path): ?array
{
    if (!str_starts_with($path, '/franchise/') || str_starts_with($path, '/franchise/admin')) { return null; }
    socInstall();
    // Pas de connexion propre à l'espace : l'ERP ouvre la session ; seul l'aperçu du franchiseur se ferme ici.
    if ($method === 'POST' && $path === '/franchise/deconnexion') { sfSessionFermer(); return [200, ['ok' => true]]; }
    $session = sfExiger();
    $shop = (string) ($_GET['shop'] ?? (body()['shop'] ?? '')) ?: (string) $session['shops'][0];
    sfExiger($shop);
    if ($method === 'GET' && $path === '/franchise/etat') { return [200, sfEtat($shop, $session)]; }
    if ($method === 'POST' && $path === '/franchise/posts') { return [200, ['post' => sfPostCreer($shop, body(), $session), 'quota' => ['used' => sfQuotaUtilise($shop, date('Y-m-d')), 'max' => sfRegles()['quotaSemaine']]]]; }
    if ($method === 'POST' && preg_match('#^/franchise/posts/([\w.\-]{1,64})/annuler$#', $path, $m)) { return [200, ['ok' => true, 'retires' => sfPostAnnuler($shop, $m[1], !empty(body()['serie']))]]; }
    if ($method === 'GET' && $path === '/franchise/suivi') { return [200, sfSuivi($shop, (int) ($_GET['jours'] ?? 28))]; }
    if ($method === 'GET' && $path === '/franchise/vues') { return [200, sfVues($shop, (int) ($_GET['jours'] ?? 28))]; }
    if ($method === 'GET' && $path === '/franchise/instagram') { return [200, sfInstagram($shop)]; }
    if ($method === 'GET' && $path === '/franchise/instagram/abonnes') { return [200, sfInstagramAbonnes($shop)]; }
    if ($method === 'GET' && $path === '/franchise/calendrier') { return [200, sfCalendrier($shop, (string) ($_GET['du'] ?? ''), (string) ($_GET['au'] ?? ''))]; }
    if ($method === 'GET' && $path === '/franchise/calendrier/stats') { return [200, sfCalStats($shop, (string) ($_GET['id'] ?? ''), (string) ($_GET['compte'] ?? ''))]; }
    if ($method === 'GET' && $path === '/franchise/operations') { return [200, sfOperations($shop)]; }
    if ($method === 'GET' && $path === '/franchise/publicites') { return [200, sfPublicites($shop)]; }
    if ($method === 'GET' && $path === '/franchise/commentaires') { return [200, sfCommentaires($shop, $_GET)]; }
    if ($method === 'GET' && $path === '/franchise/kit') { return [200, sfKit($shop)]; }
    if ($method === 'GET' && $path === '/franchise/semaine') { return [200, sfMaSemaine($shop)]; }
    if ($method === 'GET' && $path === '/franchise/messages') { return [200, sfMessages($shop)]; }
    if ($method === 'GET' && $path === '/franchise/messages/conversation') { return [200, sfMsgConversation($shop, (string) ($_GET['id'] ?? ''))]; }
    if ($method === 'POST' && $path === '/franchise/messages/repondre') { $b = body(); return [200, sfMsgRepondre($shop, (string) ($b['id'] ?? ''), (string) ($b['texte'] ?? ''), $session)]; }
    if ($method === 'POST' && $path === '/franchise/messages/traiter') { $b = body(); return [200, sfMsgTraiter($shop, (string) ($b['id'] ?? ''), !empty($b['traite']))]; }
    if ($method === 'GET' && $path === '/franchise/boost') { return [200, sfBoostListe($shop)]; }
    if ($method === 'POST' && $path === '/franchise/boost') { $b = body(); return [200, sfBoostDemander($shop, (string) ($b['id'] ?? ''), (string) ($b['compte'] ?? ''), (string) ($b['message'] ?? ''), $session)]; }
    if ($method === 'GET' && $path === '/franchise/google') { return [200, sfGoogle($shop, $_GET)]; }
    if ($method === 'POST' && $path === '/franchise/commentaires/repondre') { $b = body(); return [200, sfCommentaireRepondre($shop, (string) ($b['id'] ?? ''), (string) ($b['message'] ?? ''), $session)]; }
    if ($method === 'POST' && $path === '/franchise/commentaires/traiter') { $b = body(); return [200, sfCommentaireTraiter($shop, (string) ($b['id'] ?? ''), !empty($b['traite']))]; }
    if ($method === 'GET' && $path === '/franchise/catalogue/offres') { return [200, sfOffres($shop, (string) ($_GET['produit'] ?? ''))]; }
    // La photo d'un produit du webshop (R2, sans CORS) servie par le module : le visuel qui la dessine s'exporte.
    if ($method === 'GET' && $path === '/franchise/media/webshop') { return [200, socMediaWebshop((string) ($_GET['u'] ?? ''))]; }
    throw new HttpError(404, 'route inconnue');
}

/** Les routes d'administration (écran franchiseur, session du cockpit). */
function sfAdminRoute(string $method, string $path): ?array
{
    if (!str_starts_with($path, '/franchise/admin')) { return null; }
    if ($method === 'GET' && $path === '/franchise/admin') { return [200, ['rules' => sfRegles(), 'templates' => sfModeles()]]; }
    if ($method === 'POST' && $path === '/franchise/admin/regles') { return [200, ['rules' => sfReglesPut((array) (body()['rules'] ?? []))]]; }
    if ($method === 'POST' && $path === '/franchise/admin/apercu') {
        // « Voir comme la boutique » : une session franchisé de prévisualisation, ouverte par le franchiseur.
        $shop = (string) (body()['shop'] ?? '');
        if (socShop($shop) === null) { throw new HttpError(404, 'boutique inconnue'); }
        sfSessionOuvrir([$shop], 'Aperçu franchiseur · ' . socShop($shop)['name'], 'apercu');
        return [200, ['ok' => true, 'shop' => $shop]];
    }
    throw new HttpError(404, 'route inconnue');
}
