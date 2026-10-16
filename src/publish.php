<?php
declare(strict_types=1);

/**
 * La publication des posts — écran « Créer un post » → Pages API.
 *
 * La file est tenue par le backend (`social_publication`), pas par Meta : un post
 * « Programmé » y dépose une ligne par boutique et par canal, à l'heure dite,
 * étalée de `spread / (n − 1)` minutes d'une Page à la suivante ; le cron
 * (bin/cron.php, chaque minute) publie ce qui est échu. « Publier
 * maintenant » passe par la même file, échue tout de suite, et se publie dans
 * la requête.
 *
 * Variables ({ville}, {adresse}, {horaires}, {prix}, {produit_du_jour}, {webshop}, {webshop_produit}, {webshop_categorie},
 * {webshop_produit:ID}, {webshop_produit:ID:liste}, {webshop_categorie:ID}) :
 * résolues ici, boutique par boutique et dans la langue de la Page, AVANT
 * l'appel Meta. Boutique bilingue : légende NL puis FR dans le même message.
 *
 * Les erreurs remontent au calendrier telles que Meta les donne : statut
 * « Échec » (aucune Page publiée) ou « Partiel » (`partial.ok` / `partial.ko`,
 * motif et code par boutique et canal).
 */

/** Le catalogue (prix et noms FR/NL) : src/data/catalogue.json, modifiable par réglage. */
function socCatalogue(): array
{
    $api = Tfb::configured('admin') ? socApiCatalogue() : [];
    if ($api) { return $api; }
    $c = socSetting('catalogue');  // social_parametre (repli)
    if (is_array($c) && $c) { return $c; }
    $j = json_decode((string) @file_get_contents(__DIR__ . '/data/catalogue.json'), true);
    return is_array($j) ? $j : [];
}

/**
 * Le webshop d'une boutique (https://webshop.atelierby.be/?shop={n° de caisse}), ou une page de ce
 * webshop, avec les paramètres que le webshop lit lui-même (parseDeepLink de son application) :
 *   catégorie        ?shop=4&category=group:28
 *   sous-catégorie   ?shop=4&category=group:28&sub=category:31000   (sans groupe : category=category:31000)
 *   produit          sa sous-catégorie + &product={id}&open=product
 * Le groupe d'une catégorie et la catégorie d'un produit viennent du catalogue du webshop
 * (socWebshopPlan). Une boutique hors caisse (magasin test) n'a pas de webshop : le lien garde
 * la page, sans boutique. Adresse de base : réglage `webshop_url`.
 */
/**
 * L'adresse du webshop d'une boutique, d'une catégorie ou d'un produit. Un produit arrive sur sa
 * sous-catégorie avec `product=ID` ; `$fiche` ajoute `open=product`, qui ouvre sa fiche (lien
 * `{webshop_produit:ID}`), sinon il reste dans la liste (`{webshop_produit:ID:liste}`).
 */
function socWebshopUrl(string $shopId, ?array $product = null, int $categorie = 0, int $groupe = 0, bool $fiche = true): string
{
    $base = rtrim((string) (socSetting('webshop_url') ?: 'https://webshop.atelierby.be/'), '/') . '/';
    $q = preg_match('/^\d+$/', $shopId) ? ['shop' => $shopId] : [];
    $pid = (int) ($product['apiId'] ?? 0);
    $produit = $pid > 0 && ($product['kind'] ?? 'produit') === 'produit';
    if ($produit && $categorie <= 0) { $categorie = (int) ($product['categoryId'] ?? 0) ?: (int) (socWebshopPlan(0, $pid)['prods'][$pid] ?? 0); }
    if ($categorie > 0) {
        $groupe = $groupe ?: (int) (socWebshopPlan($categorie)['cats'][$categorie] ?? 0);
        $q += $groupe > 0 ? ['category' => 'group:' . $groupe, 'sub' => 'category:' . $categorie] : ['category' => 'category:' . $categorie];
    } elseif ($groupe > 0) {
        $q['category'] = 'group:' . $groupe;
    }
    if ($produit) { $q += ['product' => (string) $pid] + ($fiche ? ['open' => 'product'] : []); }
    // « : » reste lisible (permis dans une requête) : ?category=group:28&sub=category:31000.
    return $q ? $base . '?' . str_replace('%3A', ':', http_build_query($q, '', '&', PHP_QUERY_RFC3986)) : $base;
}

/** Le produit du catalogue cité par un post (null s'il n'en cite pas). */
function socPostProduit(array $post): ?array
{
    if (empty($post['product'])) { return null; }
    // Le catalogue de l'API d'abord ; un post plus ancien peut citer un produit de l'ancien catalogue.
    $legacy = json_decode((string) @file_get_contents(__DIR__ . '/data/catalogue.json'), true) ?: [];
    foreach (array_merge(socCatalogue(), (array) (socSetting('catalogue') ?: []), $legacy) as $p) { if (($p['id'] ?? null) === $post['product']) { return $p; } }
    return null;
}

/** Résout les variables d'un texte pour une boutique, dans une langue. */
function socFill(string $text, array $shop, ?array $product, string $lang): string
{
    $hours = $lang === 'nl' && ($shop['hoursNl'] ?? '') !== '' ? $shop['hoursNl'] : ($shop['hours'] ?? '');
    $pname = $product ? (string) ($lang === 'nl' && !empty($product['nameNl']) ? $product['nameNl'] : ($product['name'] ?? '')) : '';
    // Un produit ou une catégorie précis du webshop : {webshop_produit:123}, {webshop_categorie:456} ;
    // « :liste » : le produit dans sa liste, fiche fermée ({webshop_produit:123:liste}, {webshop_produit:liste}).
    $sid = (string) ($shop['id'] ?? '');
    $text = str_replace('{webshop_produit:liste}', socWebshopUrl($sid, $product, 0, 0, false), $text);
    $text = preg_replace_callback('/\{webshop_(produit|categorie|groupe):(\d+)(:liste)?\}/', static fn ($m) => match ($m[1]) {
        'produit' => socWebshopUrl($sid, ['apiId' => (int) $m[2], 'kind' => 'produit'], 0, 0, empty($m[3])),
        'categorie' => socWebshopUrl($sid, null, (int) $m[2]),
        default => socWebshopUrl($sid, null, 0, (int) $m[2]),
    }, $text);
    // Une boutique sans horaires (magasin test) : la phrase qui les cite disparaît, plutôt que « Ouvert . ».
    if (trim((string) $hours) === '') { $text = socSansHoraires($text); }
    return strtr($text, [
        '{ville}' => (string) $shop['name'], '{adresse}' => (string) ($shop['address'] ?? ''), '{horaires}' => (string) $hours,
        '{prix}' => (string) ($product['price'] ?? ''), '{produit_du_jour}' => $pname,
        '{webshop_produit}' => socWebshopUrl($sid, $product), '{webshop_categorie}' => socWebshopUrl($sid, null, (int) ($product['categoryId'] ?? 0)),
        '{webshop}' => socWebshopUrl($sid),
        // Le hashtag de la boutique (#Halle, #SaintGilles) : son nom sans espace ni ponctuation.
        '{hashtag_ville}' => '#' . preg_replace('/[^\p{L}\p{N}]/u', '', (string) $shop['name']),
    ]);
}

/** Retire d'un texte la phrase qui cite {horaires} (« Ouvert {horaires}. »), sans toucher au reste. */
function socSansHoraires(string $text): string
{
    $t = preg_replace('/[ \t]*[^.!?\n]*\{horaires\}[^.!?\n]*[.!?]?/u', '', $text);
    return preg_replace(['/[ \t]{2,}/', '/[ \t]+(\n|$)/'], [' ', '$1'], (string) $t);
}

/** Le texte qui introduit le lien d'un post (« Commandez ici 👉 ») : celui du post, sinon le réglage `lien_texte`. */
function socLienTexte(array $post, string $lang): string
{
    $d = socSetting('lien_texte');
    $defaut = ['fr' => 'Commandez ici 👉', 'nl' => 'Bestel hier 👉'];
    $t = trim((string) ($post['linkText'][$lang] ?? ''));
    if ($t === '' && is_array($d)) { $t = trim((string) ($d[$lang] ?? '')); }
    return $t !== '' ? $t : ($defaut[$lang] ?? $defaut['fr']);
}

/**
 * Le message publié sur la Page d'une boutique : NL puis FR si bilingue.
 *
 * Le lien du post (résolu pour la boutique) suit son texte d'introduction : « Commandez ici 👉
 * https://… ». Meta n'accepte pas de lien caché sous un mot dans le texte d'une publication :
 * l'adresse reste visible, juste après le texte. La variable {lien} le place dans le texte ;
 * sans elle, il est ajouté à la fin quand le post porte une photo ou une vidéo (sans média,
 * Facebook en fait une carte cliquable). Instagram : un lien de légende n'est pas cliquable,
 * {lien} disparaît.
 */
function socMessage(array $post, array $shop, string $canal = 'fb'): string
{
    $product = socPostProduit($post);
    $langs = (array) ($shop['langs'] ?: ['fr']);
    $url = socFill(trim((string) ($post['link'] ?? '')), $shop, $product, $langs[0] ?? 'fr');
    $lien = static fn (string $L) => $canal === 'ig' || $url === '' ? '' : socLienTexte($post, $L) . ' ' . $url;
    $place = static function (string $txt, string $L) use ($lien): string {
        $l = $lien($L);
        $t = $l === '' ? (string) preg_replace('/[ \t]*\{lien\}[ \t]*/', ' ', $txt) : str_replace('{lien}', $l, $txt);
        return trim((string) preg_replace(["/[ \t]+(\n|$)/", "/\n{3,}/"], ['$1', "\n\n"], $t));
    };
    $parts = []; $avecLien = false;
    foreach ($langs as $L) {
        $t = $L === 'nl' ? (string) ($post['textNl'] ?? '') : (string) ($post['text'] ?? '');
        if (trim($t) !== '') { $avecLien = $avecLien || str_contains($t, '{lien}'); $parts[] = $place(socFill($t, $shop, $product, $L), $L); }
    }
    if (!$parts) {  // un seul texte saisi : il sert pour toutes les langues
        $t = trim((string) ($post['text'] ?? '')) !== '' ? (string) $post['text'] : (string) ($post['textNl'] ?? '');
        if (trim($t) !== '') { $avecLien = str_contains($t, '{lien}'); $parts[] = $place(socFill($t, $shop, $product, $langs[0] ?? 'fr'), $langs[0] ?? 'fr'); }
    }
    $msg = implode("\n\n", $parts);
    [$imgs, $video] = socMedias($post);
    if (!$avecLien && ($imgs || $video !== null) && ($l = $lien($langs[0] ?? 'fr')) !== '') { $msg = trim($msg . "\n\n" . $l); }
    return $msg;
}

/** L'URL publique d'un média : Meta le télécharge depuis le serveur. */
function socPublicUrl(string $src): string
{
    if (preg_match('#^https?://#', $src)) { return $src; }
    $base = rtrim((string) (socSetting('url_publique') ?? ''), '/') . '/';
    return $base . ltrim(implode('/', array_map('rawurlencode', explode('/', ltrim($src, './')))), '/');
}

/** Les médias d'un post : [images[], video|null]. */
function socMedias(array $post): array
{
    $imgs = []; $video = null;
    foreach ((array) ($post['media'] ?? []) as $m) {
        $src = (string) ($m['src'] ?? '');
        if ($src === '' || str_starts_with($src, 'data:')) { continue; }
        if (($m['kind'] ?? 'img') === 'video') { $video = $video ?? $src; } else { $imgs[] = $src; }
    }
    if (!$imgs && !$video && !empty($post['img']) && !str_starts_with((string) $post['img'], 'data:')) { $imgs[] = (string) $post['img']; }
    return [$imgs, $video];
}

/** Les canaux d'une boutique pour un post : ['fb', 'ig'] selon `ch` (sinon fb/ig globaux). */
function socCanaux(array $post, string $shopId): array
{
    $ch = $post['ch'][$shopId] ?? null;
    $fb = is_array($ch) ? !empty($ch['fb']) : !empty($post['fb']);
    $ig = is_array($ch) ? !empty($ch['ig']) : !empty($post['ig']);
    return array_values(array_filter(['fb' => $fb ? 'fb' : null, 'ig' => $ig ? 'ig' : null]));
}

/**
 * Les canaux d'une boutique pour un post, stories comprises : `story` = 'seule' (la story remplace le
 * post dans le fil : fb_story / ig_story) ou 'aussi' (le post, puis sa story) ; sinon fb / ig.
 */
function socCanauxPost(array $post, string $shopId): array
{
    $base = socCanaux($post, $shopId);
    return match ((string) ($post['story'] ?? '')) {
        'seule' => array_map(static fn ($c) => $c . '_story', $base),
        'aussi' => array_merge($base, array_map(static fn ($c) => $c . '_story', $base)),
        default => $base,
    };
}

/** Le nom d'un canal de la file, tel que le calendrier le montre. */
function socCanalNom(string $canal): string
{
    return match ($canal) { 'ig' => 'Instagram', 'fb_story' => 'Story Facebook', 'ig_story' => 'Story Instagram', default => 'Facebook' };
}

/**
 * (Re)met un post dans la file. Les lignes déjà publiées sont gardées ; les
 * autres sont recalculées (date, boutiques, canaux, étalement).
 */
function socQueue(array $post, bool $maintenant = false): int
{
    $id = (string) $post['id'];
    Db::exec("DELETE FROM social_publication WHERE content_id = ? AND statut <> 'publie'", [$id]);
    $shops = array_values((array) ($post['shops'] ?? []));
    $n = count($shops);
    $t0 = $maintenant ? time() : (strtotime(($post['date'] ?? date('Y-m-d')) . ' ' . ($post['time'] ?? '08:00')) ?: time());
    $spread = max(0, (int) ($post['spread'] ?? 0));
    $deja = [];
    foreach (Db::rows("SELECT shop_id, canal FROM social_publication WHERE content_id = ?", [$id]) as $r) { $deja[$r['shop_id'] . '|' . $r['canal']] = true; }
    $k = 0;
    // Instagram unique : le post part une seule fois sur le compte de la marque (dès qu'une boutique visée l'a coché).
    $unique = socIgUnique();
    $igReseau = [];
    $tests = $unique ? array_column(array_filter(socShops(), static fn ($s) => !empty($s['test'])), 'id') : [];
    foreach ($shops as $i => $sid) {
        $off = ($n > 1 && $spread > 0 && !$maintenant) ? (int) round($i * $spread / ($n - 1) * 60) : 0;
        foreach (socCanauxPost($post, (string) $sid) as $c) {
            // Instagram unique : un magasin test ne publie jamais sur le compte du réseau.
            if (str_starts_with($c, 'ig') && $unique) { if (!in_array((string) $sid, $tests, true)) { $igReseau[$c] = true; } continue; }
            if (isset($deja[$sid . '|' . $c])) { continue; }
            Db::exec('INSERT INTO social_publication (content_id, shop_id, canal, prevue_le, statut, maj_le) VALUES (?,?,?,?,?,?)',
                [$id, (string) $sid, $c, date('Y-m-d H:i:s', $t0 + $off), 'attente', date('Y-m-d H:i:s')]);
            $k++;
        }
    }
    foreach (array_keys($igReseau) as $c) {
        if (isset($deja['marque|' . $c])) { continue; }
        Db::exec('INSERT INTO social_publication (content_id, shop_id, canal, prevue_le, statut, maj_le) VALUES (?,?,?,?,?,?)',
            [$id, 'marque', $c, date('Y-m-d H:i:s', $t0), 'attente', date('Y-m-d H:i:s')]);
        $k++;
    }
    return $k;
}

/** Retire de la file ce qui n'est pas encore parti (brouillon, refus, suppression). */
function socUnqueue(string $postId): void
{
    Db::exec("DELETE FROM social_publication WHERE content_id = ? AND statut <> 'publie'", [$postId]);
}

/** Publie sur une Page Facebook. Rend l'id Meta du post. */
function socPublishFb(array $post, array $shop, string $message): string
{
    $pid = (string) $shop['ids']['page_id'];
    if ($pid === '') { throw new MetaError('page_id non renseigné pour ' . $shop['name'], 100); }
    $tok = Meta::pageToken($pid);
    [$imgs, $video] = socMedias($post);
    // Le lien peut citer le webshop de la boutique ou la page du produit : résolu boutique par boutique.
    $link = socFill(trim((string) ($post['link'] ?? '')), $shop, socPostProduit($post), $shop['langs'][0] ?? 'fr');
    if ($video !== null) {
        $r = Meta::call('POST', $pid . '/videos', ['file_url' => socPublicUrl($video), 'description' => $message,
            'title' => (string) ($post['title'] ?? '')], $tok);
        return (string) ($r['id'] ?? '');
    }
    if (count($imgs) > 1) {
        $att = [];
        foreach ($imgs as $u) {
            $r = Meta::call('POST', $pid . '/photos', ['url' => socPublicUrl($u), 'published' => false], $tok);
            if (!empty($r['id'])) { $att[] = ['media_fbid' => (string) $r['id']]; }
        }
        $r = Meta::call('POST', $pid . '/feed', array_filter(['message' => $message, 'link' => $link ?: null, 'attached_media' => $att]), $tok);
        return (string) ($r['id'] ?? '');
    }
    if (count($imgs) === 1) {
        // Le lien est déjà dans le message, après son texte (socMessage).
        $r = Meta::call('POST', $pid . '/photos', ['url' => socPublicUrl($imgs[0]), 'caption' => $message], $tok);
        return (string) ($r['post_id'] ?? $r['id'] ?? '');
    }
    $r = Meta::call('POST', $pid . '/feed', array_filter(['message' => $message, 'link' => $link ?: null]), $tok);
    return (string) ($r['id'] ?? '');
}

/** Publie sur Instagram (conteneur puis publication). Rend l'id du média. */
function socPublishIg(array $post, array $shop, string $message): string
{
    $ig = (string) $shop['ids']['instagram_user_id'];
    if ($ig === '') { throw new MetaError('compte Instagram non rattaché à ' . $shop['name'], 100); }
    $tok = socIgToken($ig, (string) $shop['ids']['page_id']);
    // Frise de grille : 3 posts à la suite (Facebook, lui, reçoit le visuel entier — `media`).
    if (socEstFrise($post)) { return socPublishFrise($post, $shop, $ig, $tok, $message); }
    [$imgs, $video] = socMedias($post);
    if ($video !== null) {
        $c = Meta::call('POST', $ig . '/media', ['media_type' => 'REELS', 'video_url' => socPublicUrl($video), 'caption' => $message], $tok);
        $cid = (string) ($c['id'] ?? '');
        socIgAttendre($cid, $tok);
    } elseif (count($imgs) > 1) {
        $kids = [];
        foreach (array_slice($imgs, 0, 10) as $u) {
            $k = Meta::call('POST', $ig . '/media', ['image_url' => socPublicUrl($u), 'is_carousel_item' => true], $tok);
            $kids[] = (string) ($k['id'] ?? '');
        }
        $c = Meta::call('POST', $ig . '/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => $message], $tok);
        $cid = (string) ($c['id'] ?? '');
    } elseif (count($imgs) === 1) {
        $c = Meta::call('POST', $ig . '/media', ['image_url' => socPublicUrl($imgs[0]), 'caption' => $message], $tok);
        $cid = (string) ($c['id'] ?? '');
    } else {
        throw new MetaError('Instagram exige au moins une image ou une vidéo', 100);
    }
    $r = Meta::call('POST', $ig . '/media_publish', ['creation_id' => $cid], $tok);
    return (string) ($r['id'] ?? '');
}

/** Une vidéo se traite chez Meta avant de pouvoir être publiée : on attend que le conteneur soit prêt. */
function socIgAttendre(string $cid, ?string $tok): void
{
    for ($i = 0; $i < 20; $i++) {
        $s = Meta::call('GET', $cid, ['fields' => 'status_code'], $tok);
        if (($s['status_code'] ?? '') === 'FINISHED') { return; }
        if (($s['status_code'] ?? '') === 'ERROR') { throw new MetaError('Instagram a refusé la vidéo', 2207026); }
        if (!Meta::$sleep) { sleep(3); } else { (Meta::$sleep)(3); }
    }
}

/** L'image d'une story : son visuel 9:16 (`storyImg`, post + story), sinon le visuel du post (story seule). */
function socStoryMedia(array $post): array
{
    [$imgs, $video] = socMedias($post);
    $img = (string) ($post['storyImg'] ?? '');
    if ($img !== '' && !str_starts_with($img, 'data:')) { return [$img, null]; }
    return [$imgs[0] ?? null, $imgs ? null : $video];
}

/**
 * Publie une story sur une Page Facebook : la photo d'abord, non publiée, puis la story
 * (`/{page}/photo_stories`). Une story n'a ni texte ni lien : tout est sur le visuel.
 */
function socPublishFbStory(array $post, array $shop): string
{
    $pid = (string) $shop['ids']['page_id'];
    if ($pid === '') { throw new MetaError('page_id non renseigné pour ' . $shop['name'], 100); }
    $tok = Meta::pageToken($pid);
    [$img] = socStoryMedia($post);
    if ($img === null) { throw new MetaError('une story Facebook demande une image', 100); }
    $p = Meta::call('POST', $pid . '/photos', ['url' => socPublicUrl($img), 'published' => false], $tok);
    $r = Meta::call('POST', $pid . '/photo_stories', ['photo_id' => (string) ($p['id'] ?? '')], $tok);
    return (string) ($r['post_id'] ?? $r['id'] ?? '');
}

/** Publie une story Instagram (`media_type` STORIES : image, ou vidéo traitée d'abord). */
function socPublishIgStory(array $post, array $shop): string
{
    $ig = (string) $shop['ids']['instagram_user_id'];
    if ($ig === '') { throw new MetaError('compte Instagram non rattaché à ' . $shop['name'], 100); }
    $tok = socIgToken($ig, (string) $shop['ids']['page_id']);
    [$img, $video] = socStoryMedia($post);
    if ($img === null && $video === null) { throw new MetaError('une story Instagram demande une image ou une vidéo', 100); }
    $c = Meta::call('POST', $ig . '/media', $img !== null ? ['media_type' => 'STORIES', 'image_url' => socPublicUrl($img)] : ['media_type' => 'STORIES', 'video_url' => socPublicUrl((string) $video)], $tok);
    $cid = (string) ($c['id'] ?? '');
    if ($img === null) { socIgAttendre($cid, $tok); }
    $r = Meta::call('POST', $ig . '/media_publish', ['creation_id' => $cid], $tok);
    return (string) ($r['id'] ?? '');
}

/** Le motif lisible d'une erreur Meta, avec son code — ce que montre le calendrier. */
function socMotif(MetaError $e, string $canal): string
{
    $code = $e->metaCode;
    $quoi = match (true) {
        $code === 190 => (str_starts_with($canal, 'ig') ? 'Token Instagram' : 'Token de la Page') . ' expiré ou non attribué',
        $code === 368 => 'Page restreinte par Meta',
        $code === 2 || $e->transient => 'Délai dépassé côté Graph API — relance possible',
        default => 'Refus de Meta',
    };
    return $quoi . ' : ' . $e->getMessage() . ($code ? ' (code ' . $code . ')' : '');
}

/**
 * Publie les lignes échues d'un post (ou de tous) et met à jour le statut du
 * post. Rend les posts touchés.
 */
function socRunQueue(?string $postId = null, int $max = 60): array
{
    $now = date('Y-m-d H:i:s');
    $sql = "SELECT * FROM social_publication WHERE statut = 'attente' AND prevue_le <= ?" . ($postId !== null ? ' AND content_id = ?' : '') . ' ORDER BY prevue_le, id LIMIT ' . (int) $max;
    $rows = Db::rows($sql, $postId !== null ? [$now, $postId] : [$now]);
    $touches = [];
    $shops = [];
    foreach (socShops() as $s) { $shops[$s['id']] = $s; }
    foreach ($rows as $r) {
        // Réservation : deux crons simultanés ne publient pas deux fois.
        if (Db::exec("UPDATE social_publication SET statut = 'en_cours', essais = essais + 1, maj_le = ? WHERE id = ? AND statut = 'attente'", [$now, $r['id']]) === 0) { continue; }
        $post = socItem('posts', (string) $r['content_id']);
        $shop = (string) $r['shop_id'] === 'marque' && $post !== null ? socIgCompteReseau($post) : ($shops[(string) $r['shop_id']] ?? null);
        try {
            if ($post === null) { throw new MetaError('post supprimé', 0); }
            if ($shop === null) { throw new MetaError('boutique inconnue ou fermée', 0); }
            if (!Meta::configured()) { throw new MetaError('token Meta absent : à saisir dans Paramètres → API Meta → Clés d’accès Meta', 190); }
            // Les offres du post encore exactes (prix, promotion) dans TFBuddy, sinon la publication s'arrête.
            if ((string) $r['shop_id'] !== 'marque' && ($ecarts = socVerifierOffres($post, (string) $r['shop_id']))) { throw new SocBlocage(implode(' ; ', $ecarts)); }
            $msg = socMessage($post, $shop, (string) $r['canal']);
            $mid = match ((string) $r['canal']) {
                'ig' => socPublishIg($post, $shop, $msg), 'fb_story' => socPublishFbStory($post, $shop), 'ig_story' => socPublishIgStory($post, $shop),
                default => socPublishFb($post, $shop, $msg),
            };
            Db::exec("UPDATE social_publication SET statut = 'publie', meta_id = ?, erreur = NULL, code_erreur = NULL, maj_le = ? WHERE id = ?", [$mid, date('Y-m-d H:i:s'), $r['id']]);
        } catch (SocBlocage $e) {
            Db::exec("UPDATE social_publication SET statut = 'echec', erreur = ?, code_erreur = 0, maj_le = ? WHERE id = ?",
                [mb_substr('Publication bloquée, offre à revoir : ' . $e->getMessage(), 0, 500), date('Y-m-d H:i:s'), $r['id']]);
        } catch (MetaError $e) {
            Db::exec("UPDATE social_publication SET statut = 'echec', erreur = ?, code_erreur = ?, maj_le = ? WHERE id = ?",
                [mb_substr(socMotif($e, (string) $r['canal']), 0, 500), $e->metaCode, date('Y-m-d H:i:s'), $r['id']]);
        } catch (Throwable $e) {
            Db::exec("UPDATE social_publication SET statut = 'echec', erreur = ?, code_erreur = 0, maj_le = ? WHERE id = ?",
                [mb_substr('Erreur interne : ' . $e->getMessage(), 0, 500), date('Y-m-d H:i:s'), $r['id']]);
        }
        $touches[(string) $r['content_id']] = true;
    }
    $out = [];
    foreach (array_keys($touches) as $pid) {
        $p = socSyncPostStatus((string) $pid);
        if ($p !== null) { $out[] = $p; }
    }
    return $out;
}

/**
 * Le statut d'un post d'après sa file : tout publié → « Publié » ; rien
 * publié et tout échu en échec → « Échec » ; un mélange → « Publié » avec
 * `partial` (affiché « Partiel »). Tant qu'une ligne attend, le post reste
 * « Programmé ». Journalisé au passage.
 */
function socSyncPostStatus(string $postId): ?array
{
    $post = socItem('posts', $postId);
    if ($post === null) { return null; }
    $rows = Db::rows('SELECT * FROM social_publication WHERE content_id = ?', [$postId]);
    if (!$rows) { return $post; }
    $attente = array_filter($rows, static fn ($r) => in_array($r['statut'], ['attente', 'en_cours'], true));
    if ($attente) { return $post; }
    $okShops = []; $ko = [];
    foreach ($rows as $r) {
        if ($r['statut'] === 'publie') { $okShops[$r['shop_id']] = true; }
        else { $ko[] = ['shop' => (string) $r['shop_id'], 'channel' => socCanalNom((string) $r['canal']), 'reason' => (string) $r['erreur'], 'code' => (int) $r['code_erreur']]; }
    }
    foreach ($ko as $k) { unset($okShops[$k['shop']]); }
    $shopsKo = array_values(array_unique(array_column($ko, 'shop')));
    $avant = (string) ($post['status'] ?? '');
    $titre = (string) ($post['title'] ?? $postId);
    $post['publishedAt'] = date('Y-m-d H:i:s');
    if (!$ko) {
        $post['status'] = 'Publié'; $post['partial'] = null; $post['error'] = null; $post['failedShops'] = null;
        if ($avant !== 'Publié') { socLog('Système', 'Publication', '« ' . $titre . ' » publié sur ' . count($okShops) . ' Page(s)'); }
    } elseif (!array_filter($rows, static fn ($r) => $r['statut'] === 'publie')) {
        $post['status'] = 'Échec'; $post['partial'] = null;
        $post['error'] = implode(' · ', array_map(static fn ($k) => $k['shop'] . ' (' . $k['channel'] . ') : ' . $k['reason'], $ko));
        $post['failedShops'] = $shopsKo;
        socLog('Système', 'Échec', '« ' . $titre . ' » : ' . $post['error']);
    } else {
        $post['status'] = 'Publié'; $post['error'] = null; $post['failedShops'] = null;
        $post['partial'] = ['ok' => array_values(array_diff(array_map('strval', (array) $post['shops']), $shopsKo)), 'ko' => $ko];
        socLog('Système', 'Échec partiel', '« ' . $titre . ' » : ' . count($post['partial']['ok']) . ' Page(s) sur ' . count((array) $post['shops'])
            . ' — ' . implode(', ', array_map(static fn ($k) => $k['shop'] . ' (' . $k['channel'] . ')', $ko)));
    }
    socItemPut('posts', $post);
    return $post;
}

/** Relance les lignes en échec d'un post, tout de suite. */
function socRetry(string $postId): ?array
{
    $n = Db::exec("UPDATE social_publication SET statut = 'attente', prevue_le = ?, maj_le = ? WHERE content_id = ? AND statut = 'echec'",
        [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $postId]);
    if ($n === 0) { return socItem('posts', $postId); }
    $r = socRunQueue($postId);
    return $r[0] ?? socItem('posts', $postId);
}

/**
 * Supprime une publication chez Meta : post de Page (`DELETE /{post-id}`, token de la
 * Page) ou média Instagram (`DELETE /{ig-media-id}`, permission instagram_manage_contents).
 * Déjà absente chez Meta (supprimée à la main dans Business Suite…) : c'est le but, pas
 * une erreur — vérifié par une relecture, car Meta répond le même code 100 à un refus.
 */
function socMetaSupprimer(string $mid, string $tok): void
{
    try {
        Meta::call('DELETE', $mid, [], $tok);
    } catch (MetaError $e) {
        if ($e->metaCode !== 100) { throw $e; }
        try { Meta::call('GET', $mid, ['fields' => 'id'], $tok); } catch (MetaError $g) { if ($g->metaCode === 100) { return; } }
        throw $e;
    }
}

/**
 * Supprime un post du module et, avec $chezMeta, ce qu'il a déjà publié sur les Pages
 * Facebook et les comptes Instagram (une frise : ses 3 publications). Tant qu'une
 * publication refuse de partir chez Meta, le post reste au calendrier, avec la liste des
 * refus : rien ne disparaît du module qui serait encore en ligne. Sans $chezMeta, le post
 * quitte le module seul ; ses publications restent en ligne et le calendrier les relit
 * ensuite comme publications faites hors du module.
 */
function socPostSupprimer(string $id, bool $chezMeta): array
{
    $post = socItem('posts', $id);
    if ($post === null) { throw new HttpError(404, 'post introuvable : ' . $id); }
    $titre = (string) ($post['title'] ?? $id);
    $lignes = Db::rows("SELECT * FROM social_publication WHERE content_id = ? AND statut = 'publie' AND meta_id IS NOT NULL AND meta_id <> ''", [$id]);
    $faits = []; $erreurs = [];
    if ($chezMeta && $lignes) {
        $shops = [];
        foreach (socShops() as $s) { $shops[$s['id']] = $s; }
        foreach ($lignes as $r) {
            $sid = (string) $r['shop_id'];
            $ig = str_starts_with((string) $r['canal'], 'ig');
            $shop = $sid === 'marque' ? socIgCompteReseau($post) : ($shops[$sid] ?? null);
            $quoi = ['shop' => $sid, 'shopName' => $sid === 'marque' ? 'Instagram du réseau' : (string) ($shop['name'] ?? $sid), 'channel' => socCanalNom((string) $r['canal'])];
            try {
                if ($shop === null) { throw new MetaError('boutique inconnue ou fermée', 0); }
                $tok = $ig ? socIgToken((string) $shop['ids']['instagram_user_id'], (string) $shop['ids']['page_id']) : Meta::pageToken((string) $shop['ids']['page_id']);
                if ($tok === null || $tok === '') { throw new MetaError('compte non accessible au token (Page non partagée avec l’utilisateur système)', 190); }
                foreach (array_filter(array_map('trim', explode(',', (string) $r['meta_id']))) as $mid) { socMetaSupprimer($mid, $tok); }
                // Partie chez Meta : la ligne n'a plus rien à suivre (ni à re-supprimer si une autre échoue).
                Db::exec('DELETE FROM social_publication WHERE id = ?', [$r['id']]);
                $faits[] = $quoi;
            } catch (MetaError $e) {
                $erreurs[] = $quoi + ['reason' => socMotif($e, (string) $r['canal'])];
            }
        }
    }
    if ($erreurs) {
        socLog(socUser(), 'Suppression', '« ' . $titre . ' » : suppression refusée chez Meta — '
            . implode(' · ', array_map(static fn ($k) => $k['shopName'] . ' (' . $k['channel'] . ') : ' . $k['reason'], $erreurs)));
        return ['ok' => false, 'id' => $id, 'supprimees' => $faits, 'erreurs' => $erreurs, 'post' => $post];
    }
    Db::exec('DELETE FROM social_publication WHERE content_id = ?', [$id]);
    socItemDelete('posts', $id);
    socLog(socUser(), 'Suppression', '« ' . $titre . ' » supprimé'
        . ($faits ? ' (et retiré de ' . implode(', ', array_map(static fn ($k) => $k['shopName'] . ' ' . $k['channel'], $faits)) . ')'
            : ($lignes ? ' du module seul — ' . count($lignes) . ' publication(s) restent en ligne' : '')));
    return ['ok' => true, 'id' => $id, 'supprimees' => $faits, 'erreurs' => [], 'enLigne' => $chezMeta ? 0 : count($lignes)];
}

/** Les ids Meta des posts publiés : shop_id → id du post Facebook (pour sponsoriser). */
function socPostMetaIds(string $postId): array
{
    $out = [];
    foreach (Db::rows("SELECT shop_id, meta_id FROM social_publication WHERE content_id = ? AND canal = 'fb' AND statut = 'publie'", [$postId]) as $r) {
        $out[(string) $r['shop_id']] = (string) $r['meta_id'];
    }
    return $out;
}
