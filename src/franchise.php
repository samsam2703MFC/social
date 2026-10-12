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
 *  - Connexion : le compte atelierby du gérant (API TFBuddy, POST /owner/auth/login
 *    puis GET /owner/shops), ou un code d'accès par boutique créé par le
 *    franchiseur (`social_acces_franchise`). Session dans un cookie signé, limitée
 *    aux boutiques du compte.
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

/* --- accès par code ---------------------------------------------------------------- */

/** Crée un code d'accès pour une boutique. Le code n'est rendu qu'une fois (seul son empreinte est gardée). */
function sfAccesCreer(string $shop, string $libelle): array
{
    if (socShop($shop) === null) { throw new HttpError(404, 'boutique inconnue'); }
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 10; $i++) { $c .= $alpha[random_int(0, strlen($alpha) - 1)]; }
    $code = 'ATB-' . substr($c, 0, 5) . '-' . substr($c, 5);
    Db::exec('INSERT INTO social_acces_franchise (shop_id, libelle, code_hash, empreinte, actif, cree_le) VALUES (?,?,?,?,1,?)',
        [$shop, mb_substr(trim($libelle) ?: 'Gérant', 0, 120), password_hash($code, PASSWORD_DEFAULT), substr(hash('sha256', $code), 0, 16), date('Y-m-d H:i:s')]);
    socLog(socUser(), 'Espace franchisé', 'Code d’accès créé pour ' . (socShop($shop)['name'] ?? $shop) . ' (' . $libelle . ')');
    return ['code' => $code, 'acces' => sfAccesListe()];
}

function sfAccesListe(): array
{
    $noms = []; foreach (socShops() as $s) { $noms[$s['id']] = $s['name']; }
    return array_map(static fn ($r) => ['id' => (int) $r['id'], 'shop' => (string) $r['shop_id'], 'shopName' => $noms[(string) $r['shop_id']] ?? (string) $r['shop_id'],
        'label' => (string) $r['libelle'], 'active' => (int) $r['actif'] === 1, 'created' => substr((string) $r['cree_le'], 0, 16),
        'lastUse' => $r['dernier_acces'] ? substr((string) $r['dernier_acces'], 0, 16) : null],
        Db::rows('SELECT * FROM social_acces_franchise ORDER BY actif DESC, shop_id, id'));
}

function sfAccesRevoquer(int $id): array
{
    Db::exec('UPDATE social_acces_franchise SET actif = 0 WHERE id = ?', [$id]);
    socLog(socUser(), 'Espace franchisé', 'Code d’accès n° ' . $id . ' révoqué');
    return sfAccesListe();
}

/** La ligne d'accès d'un code, ou null. */
function sfAccesVerifier(string $code): ?array
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^ATB-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code)) { return null; }
    $r = Db::row('SELECT * FROM social_acces_franchise WHERE empreinte = ? AND actif = 1', [substr(hash('sha256', $code), 0, 16)]);
    if ($r === null || !password_verify($code, (string) $r['code_hash'])) { return null; }
    Db::exec('UPDATE social_acces_franchise SET dernier_acces = ? WHERE id = ?', [date('Y-m-d H:i:s'), $r['id']]);
    return $r;
}

/* --- session ------------------------------------------------------------------------ */

function sfSecret(): string
{
    $s = (string) (socSetting('franchise_secret') ?? '');
    if ($s === '') { $s = bin2hex(random_bytes(32)); socSettingPut('franchise_secret', $s, true); }
    return $s;
}

/** Ouvre la session franchisé (cookie signé : boutiques, nom, origine, expiration). */
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

function sfSession(): ?array
{
    $c = (string) ($_COOKIE[SF_COOKIE] ?? '');
    if ($c === '' || !str_contains($c, '.')) {
        // Transporté dans l'ERP : le franchisé y est déjà connecté (config/hote.php).
        if (($f = socHote('franchise_session')) !== null && is_array($h = $f()) && !empty($h['shops'])) {
            $ids = array_values(array_intersect(array_map('strval', (array) $h['shops']), array_column(socShops(), 'id')));
            return $ids ? ['shops' => $ids, 'name' => (string) ($h['name'] ?? 'Gérant'), 'via' => 'hote', 'exp' => time() + SF_DUREE] : null;
        }
        return null;
    }
    [$p, $sig] = explode('.', $c, 2);
    if (!hash_equals(hash_hmac('sha256', $p, sfSecret()), $sig)) { return null; }
    $s = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
    if (!is_array($s) || ($s['exp'] ?? 0) < time() || empty($s['shops'])) { return null; }
    return $s;
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
    if ($s === null) { throw new HttpError(401, 'Connectez-vous à votre espace.'); }
    if ($shop !== null && !in_array($shop, $s['shops'], true)) { throw new HttpError(403, 'Cette boutique n’est pas rattachée à votre compte.'); }
    if (!sfRegles()['actif'] && ($s['via'] ?? '') !== 'apercu') { throw new HttpError(403, 'L’espace franchisé est fermé par le franchiseur.'); }
    return $s;
}

/** Connexion avec le compte atelierby du gérant (API TFBuddy, espace « owner »). Rend [boutiques, nom]. */
function sfConnexionTfb(string $phone, string $password): array
{
    if (trim($phone) === '' || $password === '') { throw new HttpError(422, 'Téléphone et mot de passe requis.'); }
    [$code, $b] = Tfb::raw('POST', Tfb::base() . '/owner/auth/login', ['Content-Type: application/json'], (string) json_encode(['phone' => trim($phone), 'password' => $password]));
    $j = json_decode($b, true);
    if ($code === 401 || $code === 422) { throw new HttpError(401, 'Identifiants refusés par atelierby.'); }
    if ($code === 403) { throw new HttpError(403, 'Ce compte atelierby n’est rattaché à aucune boutique (accès « owner »).'); }
    if ($code === 409) { throw new HttpError(409, 'Ce compte gère plusieurs structures : demandez un code d’accès boutique au franchiseur.'); }
    $tok = '';
    foreach ([$j['access_token'] ?? null, $j['token'] ?? null, $j['data']['access_token'] ?? null] as $t) { if (is_string($t) && $t !== '') { $tok = $t; break; } }
    if ($code < 200 || $code >= 300 || $tok === '') { throw new HttpError(502, 'atelierby ne répond pas (HTTP ' . $code . ').'); }
    $claims = [];
    $parts = explode('.', $tok);
    if (count($parts) === 3) { $claims = (array) json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true); }
    $ids = [];
    try {
        [$c2, $b2] = Tfb::raw('GET', Tfb::base() . '/owner/shops', ['Authorization: Bearer ' . $tok]);
        if ($c2 >= 200 && $c2 < 300) {
            $l = json_decode($b2, true);
            $l = is_array($l['data'] ?? null) ? $l['data'] : (is_array($l['shops'] ?? null) ? $l['shops'] : $l);
            foreach ((array) $l as $x) {
                if (is_array($x)) { $id = $x['id'] ?? $x['shop_id'] ?? $x['franchisee_shop_id'] ?? null; if ($id !== null) { $ids[] = (string) $id; } }
                elseif (is_scalar($x)) { $ids[] = (string) $x; }
            }
        }
    } catch (Throwable $e) { /* les claims du jeton prennent le relais */ }
    if (!$ids && in_array(strtolower((string) ($claims['scope_type'] ?? '')), ['shop', 'franchisee_shop'], true) && isset($claims['scope_id'])) { $ids[] = (string) $claims['scope_id']; }
    $reseau = array_column(socShops(), 'id');
    $ids = array_values(array_intersect(array_unique($ids), $reseau));
    if (!$ids) { throw new HttpError(403, 'Aucune boutique de ce compte n’est dans le réseau Social & Ads.'); }
    $nom = trim((string) ($claims['name'] ?? $claims['full_name'] ?? '')) ?: ('Gérant ' . (socShop($ids[0])['name'] ?? ''));
    return [$ids, $nom];
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
    if (is_array($c) && ($c['le'] ?? 0) > time() - 21600 && !empty($c['items'])) { return $c['items']; }
    try {
        $fr = Tfb::liste(Tfb::get('/shops/' . rawurlencode($shop) . '/products/available'));
        $nl = [];
        try { foreach (Tfb::liste(Tfb::get('/shops/' . rawurlencode($shop) . '/products/available?lang_code=nl')) as $p) { $nl[(string) $p['id']] = (string) ($p['name'] ?? ''); } }
        catch (Throwable $e) { /* sans NL */ }
        $items = [];
        foreach ($fr as $p) {
            $cat = (string) ($p['category_name'] ?? '');
            if (empty($p['is_active']) || preg_match('/^B\.?2\.?B/i', $cat)) { continue; }
            $prix = is_numeric($p['portion_price'] ?? null) ? (float) $p['portion_price'] : (is_numeric($p['suggested_sale_price'] ?? null) ? (float) $p['suggested_sale_price'] : null);
            $items[] = ['id' => 'p' . $p['id'], 'name' => (string) $p['name'], 'nameNl' => ($nl[(string) $p['id']] ?? '') ?: (string) $p['name'],
                'price' => $prix !== null ? number_format($prix, 2, ',', '') : '', 'img' => socIllus($cat, (string) $p['name']), 'gamme' => $cat];
        }
        if ($items) { socSettingPut($cle, ['le' => time(), 'items' => $items], false); return $items; }
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
        'reason' => $ko ? (string) ($ko[0]['reason'] ?? '') : null, 'campaign' => $p['campaign'] ?? null];
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
        $ids = ($p['shops'] ?? null) === 'all' ? array_column(socShops(), 'id') : array_map('strval', (array) ($p['shops'] ?? []));
        if (!in_array($shop, $ids, true)) { continue; }
        $propre = ($p['origin'] ?? '') === 'franchise';
        if (!$propre && (!$r['voirReseau'] || !in_array((string) ($p['status'] ?? ''), ['Programmé', 'Publié'], true))) { continue; }
        $posts[] = sfPostVue($p, $shop);
    }
    $camps = [];
    if ($r['voirCampagnes']) {
        $res = $r['voirResultats'] ? socResults() : [];
        foreach (socItems('campaigns') as $c) {
            if (!in_array($shop, array_map('strval', (array) ($c['shops'] ?? [])), true)) { continue; }
            $camps[] = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? ''), 'type' => (string) ($c['type'] ?? ''), 'start' => (string) ($c['start'] ?? ''),
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
        'franchisorUrl' => (string) (socSetting('url_publique') ?? ''),
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

    // Promotion
    $kind = (string) ($tpl['promo']['kind'] ?? 'aucune');
    $promo = null;
    if ($kind === 'remise') {
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
    if (!empty($tpl['period'])) {
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

    // Visuel (rendu par l'écran avec le style figé du modèle) et photo
    $visuel = (string) ($b['visual'] ?? '');
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $visuel) || strlen($visuel) > 6_000_000) { $err[] = 'Le visuel n’a pas pu être préparé : rechargez la page.'; }
    $photoMode = (string) ($tpl['photo'] ?? 'catalogue');
    $photo = (string) ($b['photo'] ?? '');
    if ($photoMode === 'upload' && !preg_match('#^data:image/#', $photo)) { $err[] = 'Ajoutez votre photo.'; }

    if ($err) { throw new HttpError(422, implode(' ', array_unique($err))); }

    $vars = ['produit' => $prod['name'] ?? '', 'prix' => $prod['price'] ?? '', 'remise' => $kind === 'remise' ? $promo['value'] : '',
        'offre' => $kind === 'offre' ? $promo['value'] : '', 'prix_promo' => $kind === 'prix' ? $promo['value'] : '', 'du' => $du ? sfDate($du) : '', 'au' => $au ? sfDate($au) : '', 'libre' => $libre];
    $varsNl = array_replace($vars, ['produit' => $prod['nameNl'] ?? ($prod['name'] ?? '')]);
    $statut = $r['validation'] === 'toujours' || ($r['validation'] === 'modele' && !empty($tpl['validation'])) ? 'À valider' : 'Programmé';
    $auteur = (($session['via'] ?? '') === 'apercu' ? 'Franchiseur (aperçu) · ' : 'Franchisé · ') . $s['name'];
    $post = ['id' => 'f' . date('ymdHis') . bin2hex(random_bytes(2)), 'origin' => 'franchise', 'author' => $auteur, 'template' => (string) $tpl['id'],
        'type' => (string) ($tpl['type'] ?? 'Produit'), 'title' => mb_substr(sfRemplir((string) ($tpl['title'] ?? $tpl['name']), $vars), 0, 120),
        'shops' => [$shop], 'ch' => [$shop => ['fb' => $fb, 'ig' => $ig]], 'fb' => $fb, 'ig' => $ig, 'status' => $statut, 'date' => $date, 'time' => $time,
        'text' => sfRemplir((string) ($tpl['text'] ?? ''), $vars), 'textNl' => sfRemplir((string) ($tpl['textNl'] ?? ''), $varsNl), 'langs' => $s['langs'],
        'img' => $visuel, 'media' => [['src' => $visuel, 'kind' => 'img', 'name' => 'Visuel du modèle « ' . $tpl['name'] . ' »']],
        'promo' => $promo, 'product' => $prod['id'] ?? null, 'spread' => 0, 'campaign' => null, 'link' => '',
        'fields' => ['product' => $prod['id'] ?? null, 'discount' => $b['discount'] ?? null, 'offer' => $b['offer'] ?? null, 'promoPrice' => $b['promoPrice'] ?? null,
            'from' => $du, 'to' => $au, 'free' => $libre, 'photo' => $photo !== '' ? $photo : null]];
    $out = socStateSave(['changes' => ['posts' => ['upsert' => [$post]]]]);
    $p = socItem('posts', (string) $post['id']) ?? $post;
    if ($out['errors']) {
        // Refusé par une règle du réseau (verrou d'une frise…) : on ne garde pas un post que le franchisé ne voit pas.
        socItemDelete('posts', (string) $post['id']);
        socUnqueue((string) $post['id']);
        throw new HttpError(409, implode(' · ', array_column($out['errors'], 'error')));
    }
    socLog($auteur, $statut === 'À valider' ? 'Proposition' : 'Programmation',
        '« ' . $p['title'] . ' » (' . $tpl['name'] . ') le ' . sfDate($date) . ' à ' . $time . ($statut === 'À valider' ? ' — à valider' : ''));
    return sfPostVue($p, $shop);
}

/** Annule un post du franchisé tant qu'il n'est pas publié. */
function sfPostAnnuler(string $shop, string $id): void
{
    $p = socItem('posts', $id);
    if ($p === null || ($p['origin'] ?? '') !== 'franchise' || array_map('strval', (array) ($p['shops'] ?? [])) !== [$shop]) { throw new HttpError(404, 'post introuvable'); }
    if (!in_array((string) ($p['status'] ?? ''), ['À valider', 'Programmé', 'Brouillon'], true)) { throw new HttpError(409, 'Ce post est déjà publié : il ne peut plus être annulé.'); }
    socStateSave(['changes' => ['posts' => ['delete' => [$id]]]]);
    socLog('Franchisé · ' . (socShop($shop)['name'] ?? $shop), 'Annulation', '« ' . ($p['title'] ?? $id) . ' » retiré');
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
 * La vue du franchisé sur l'Instagram du réseau (compte unique, géré par le
 * franchiseur) : le profil et ses dernières publications, lus en direct chez
 * Meta, plus ce que le réseau y a programmé si la règle « voir les posts du
 * réseau » le permet. Lecture seule.
 */
function sfInstagram(): array
{
    if (!socIgUnique()) { throw new HttpError(404, 'Chaque boutique a son propre Instagram : voir « Mon calendrier ».'); }
    $b = socBrand();
    $ig = (string) $b['ids']['instagram_user_id'];
    $tok = socIgToken($ig, (string) $b['ids']['page_id']);
    $out = ['username' => '', 'name' => '', 'picture' => null, 'followers' => null, 'media' => [], 'programmes' => [], 'erreur' => null, 'le' => date('Y-m-d H:i')];
    try {
        $pr = Meta::call('GET', $ig, ['fields' => 'username,name,profile_picture_url,followers_count,media_count'], $tok);
        $out['username'] = (string) ($pr['username'] ?? ''); $out['name'] = (string) ($pr['name'] ?? '');
        $out['picture'] = (string) ($pr['profile_picture_url'] ?? '') ?: null; $out['followers'] = isset($pr['followers_count']) ? (int) $pr['followers_count'] : null;
        $r = Meta::call('GET', $ig . '/media', ['fields' => SOC_CAL_IG, 'limit' => 30], $tok);
        foreach ((array) ($r['data'] ?? []) as $x) {
            if (empty($x['id'])) { continue; }
            $video = strtoupper((string) ($x['media_type'] ?? '')) === 'VIDEO';
            $out['media'][] = ['id' => (string) $x['id'], 'src' => (string) (($video ? ($x['thumbnail_url'] ?? '') : '') ?: ($x['media_url'] ?? '')), 'video' => $video,
                'permalink' => (string) ($x['permalink'] ?? ''), 'at' => date('Y-m-d H:i', strtotime((string) ($x['timestamp'] ?? '')) ?: time()),
                'caption' => mb_substr((string) ($x['caption'] ?? ''), 0, 200), 'likes' => isset($x['like_count']) ? (int) $x['like_count'] : null, 'comments' => isset($x['comments_count']) ? (int) $x['comments_count'] : null];
        }
    } catch (MetaError $e) { $out['erreur'] = 'Instagram illisible pour l’instant : ' . $e->getMessage(); }
    if (sfRegles()['voirReseau']) {
        foreach (socItems('posts') as $p) {
            if (($p['status'] ?? '') !== 'Programmé' || !empty($p['dark']) || !socShopsIg($p)) { continue; }
            $out['programmes'][] = ['title' => (string) ($p['title'] ?? ''), 'date' => (string) ($p['date'] ?? ''), 'time' => (string) ($p['time'] ?? ''),
                'src' => (string) (((array) ($p['frise']['tiles'] ?? []))[0] ?? ($p['img'] ?? ''))];
        }
        usort($out['programmes'], static fn ($a, $c) => strcmp($c['date'] . $c['time'], $a['date'] . $a['time']));
    }
    return $out;
}

/* --- routes ------------------------------------------------------------------------------- */

/** Les routes de l'espace franchisé (session franchisé) — null si la route n'en est pas une. */
function sfRoute(string $method, string $path): ?array
{
    if (!str_starts_with($path, '/franchise/') || str_starts_with($path, '/franchise/admin')) { return null; }
    socInstall();
    if ($method === 'POST' && $path === '/franchise/connexion') {
        $b = body();
        if (!sfRegles()['actif']) { throw new HttpError(403, 'L’espace franchisé est fermé par le franchiseur.'); }
        if (!empty($b['code'])) {
            $a = sfAccesVerifier((string) $b['code']);
            if ($a === null) { throw new HttpError(401, 'Code d’accès inconnu ou révoqué.'); }
            $s = sfSessionOuvrir([(string) $a['shop_id']], (string) $a['libelle'] . ' · ' . (socShop((string) $a['shop_id'])['name'] ?? ''), 'code');
        } else {
            [$ids, $nom] = sfConnexionTfb((string) ($b['phone'] ?? ''), (string) ($b['password'] ?? ''));
            $s = sfSessionOuvrir($ids, $nom, 'tfb');
        }
        socLog($s['name'], 'Connexion', 'Espace franchisé (' . ($s['via'] === 'code' ? 'code d’accès' : 'compte atelierby') . ')');
        return [200, ['ok' => true, 'shops' => $s['shops'], 'name' => $s['name']]];
    }
    if ($method === 'POST' && $path === '/franchise/deconnexion') { sfSessionFermer(); return [200, ['ok' => true]]; }
    if ($method === 'GET' && $path === '/franchise/session') {
        $s = sfSession();
        return [200, ['authed' => $s !== null, 'open' => sfRegles()['actif'], 'shops' => $s['shops'] ?? [], 'name' => $s['name'] ?? '']];
    }
    $session = sfExiger();
    $shop = (string) ($_GET['shop'] ?? (body()['shop'] ?? '')) ?: (string) $session['shops'][0];
    sfExiger($shop);
    if ($method === 'GET' && $path === '/franchise/etat') { return [200, sfEtat($shop, $session)]; }
    if ($method === 'POST' && $path === '/franchise/posts') { return [200, ['post' => sfPostCreer($shop, body(), $session), 'quota' => ['used' => sfQuotaUtilise($shop, date('Y-m-d')), 'max' => sfRegles()['quotaSemaine']]]]; }
    if ($method === 'POST' && preg_match('#^/franchise/posts/([\w.\-]{1,64})/annuler$#', $path, $m)) { sfPostAnnuler($shop, $m[1]); return [200, ['ok' => true]]; }
    if ($method === 'GET' && $path === '/franchise/suivi') { return [200, sfSuivi($shop, (int) ($_GET['jours'] ?? 28))]; }
    if ($method === 'GET' && $path === '/franchise/instagram') { return [200, sfInstagram()]; }
    throw new HttpError(404, 'route inconnue');
}

/** Les routes d'administration (écran franchiseur, session du cockpit). */
function sfAdminRoute(string $method, string $path): ?array
{
    if (!str_starts_with($path, '/franchise/admin')) { return null; }
    if ($method === 'GET' && $path === '/franchise/admin') { return [200, ['rules' => sfRegles(), 'templates' => sfModeles(), 'access' => sfAccesListe()]]; }
    if ($method === 'POST' && $path === '/franchise/admin/regles') { return [200, ['rules' => sfReglesPut((array) (body()['rules'] ?? []))]]; }
    if ($method === 'POST' && $path === '/franchise/admin/acces') { return [200, sfAccesCreer((string) (body()['shop'] ?? ''), (string) (body()['label'] ?? 'Gérant'))]; }
    if ($method === 'POST' && preg_match('#^/franchise/admin/acces/(\d+)/revoquer$#', $path, $m)) { return [200, ['access' => sfAccesRevoquer((int) $m[1])]]; }
    if ($method === 'POST' && $path === '/franchise/admin/apercu') {
        // « Voir comme la boutique » : une session franchisé de prévisualisation, ouverte par le franchiseur.
        $shop = (string) (body()['shop'] ?? '');
        if (socShop($shop) === null) { throw new HttpError(404, 'boutique inconnue'); }
        sfSessionOuvrir([$shop], 'Aperçu franchiseur · ' . socShop($shop)['name'], 'apercu');
        return [200, ['ok' => true, 'shop' => $shop]];
    }
    throw new HttpError(404, 'route inconnue');
}
