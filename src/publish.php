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
 * Variables ({ville}, {adresse}, {horaires}, {prix}, {produit_du_jour}) :
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

/** Résout les variables d'un texte pour une boutique, dans une langue. */
function socFill(string $text, array $shop, ?array $product, string $lang): string
{
    $hours = $lang === 'nl' && ($shop['hoursNl'] ?? '') !== '' ? $shop['hoursNl'] : ($shop['hours'] ?? '');
    $pname = $product ? (string) ($lang === 'nl' && !empty($product['nameNl']) ? $product['nameNl'] : ($product['name'] ?? '')) : '';
    return strtr($text, [
        '{ville}' => (string) $shop['name'], '{adresse}' => (string) ($shop['address'] ?? ''), '{horaires}' => (string) $hours,
        '{prix}' => (string) ($product['price'] ?? ''), '{produit_du_jour}' => $pname,
    ]);
}

/** Le message publié sur la Page d'une boutique : NL puis FR si bilingue. */
function socMessage(array $post, array $shop): string
{
    $product = null;
    if (!empty($post['product'])) {
        // Le catalogue de l'API d'abord ; un post plus ancien peut citer un produit de l'ancien catalogue.
        $legacy = json_decode((string) @file_get_contents(__DIR__ . '/data/catalogue.json'), true) ?: [];
        foreach (array_merge(socCatalogue(), (array) (socSetting('catalogue') ?: []), $legacy) as $p) { if (($p['id'] ?? null) === $post['product']) { $product = $p; break; } }
    }
    $parts = [];
    foreach ((array) ($shop['langs'] ?: ['fr']) as $L) {
        $t = $L === 'nl' ? (string) ($post['textNl'] ?? '') : (string) ($post['text'] ?? '');
        if (trim($t) !== '') { $parts[] = socFill($t, $shop, $product, $L); }
    }
    if (!$parts) {  // un seul texte saisi : il sert pour toutes les langues
        $t = trim((string) ($post['text'] ?? '')) !== '' ? (string) $post['text'] : (string) ($post['textNl'] ?? '');
        if (trim($t) !== '') { $parts[] = socFill($t, $shop, $product, $shop['langs'][0] ?? 'fr'); }
    }
    return implode("\n\n", $parts);
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
    $igReseau = false;
    foreach ($shops as $i => $sid) {
        $off = ($n > 1 && $spread > 0 && !$maintenant) ? (int) round($i * $spread / ($n - 1) * 60) : 0;
        foreach (socCanaux($post, (string) $sid) as $c) {
            if ($c === 'ig' && $unique) { $igReseau = true; continue; }
            if (isset($deja[$sid . '|' . $c])) { continue; }
            Db::exec('INSERT INTO social_publication (content_id, shop_id, canal, prevue_le, statut, maj_le) VALUES (?,?,?,?,?,?)',
                [$id, (string) $sid, $c, date('Y-m-d H:i:s', $t0 + $off), 'attente', date('Y-m-d H:i:s')]);
            $k++;
        }
    }
    if ($igReseau && !isset($deja['marque|ig'])) {
        Db::exec('INSERT INTO social_publication (content_id, shop_id, canal, prevue_le, statut, maj_le) VALUES (?,?,?,?,?,?)',
            [$id, 'marque', 'ig', date('Y-m-d H:i:s', $t0), 'attente', date('Y-m-d H:i:s')]);
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
    $link = trim((string) ($post['link'] ?? ''));
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
        $r = Meta::call('POST', $pid . '/photos', ['url' => socPublicUrl($imgs[0]), 'caption' => $message . ($link !== '' ? "\n" . $link : '')], $tok);
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
        // La vidéo se traite chez Meta avant de pouvoir être publiée.
        for ($i = 0; $i < 20; $i++) {
            $s = Meta::call('GET', $cid, ['fields' => 'status_code'], $tok);
            if (($s['status_code'] ?? '') === 'FINISHED') { break; }
            if (($s['status_code'] ?? '') === 'ERROR') { throw new MetaError('Instagram a refusé la vidéo', 2207026); }
            sleep(3);
        }
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

/** Le motif lisible d'une erreur Meta, avec son code — ce que montre le calendrier. */
function socMotif(MetaError $e, string $canal): string
{
    $code = $e->metaCode;
    $quoi = match (true) {
        $code === 190 => ($canal === 'ig' ? 'Token Instagram' : 'Token de la Page') . ' expiré ou non attribué',
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
            $msg = socMessage($post, $shop);
            $mid = $r['canal'] === 'ig' ? socPublishIg($post, $shop, $msg) : socPublishFb($post, $shop, $msg);
            Db::exec("UPDATE social_publication SET statut = 'publie', meta_id = ?, erreur = NULL, code_erreur = NULL, maj_le = ? WHERE id = ?", [$mid, date('Y-m-d H:i:s'), $r['id']]);
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
        else { $ko[] = ['shop' => (string) $r['shop_id'], 'channel' => $r['canal'] === 'ig' ? 'Instagram' : 'Facebook', 'reason' => (string) $r['erreur'], 'code' => (int) $r['code_erreur']]; }
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

/** Les ids Meta des posts publiés : shop_id → id du post Facebook (pour sponsoriser). */
function socPostMetaIds(string $postId): array
{
    $out = [];
    foreach (Db::rows("SELECT shop_id, meta_id FROM social_publication WHERE content_id = ? AND canal = 'fb' AND statut = 'publie'", [$postId]) as $r) {
        $out[(string) $r['shop_id']] = (string) $r['meta_id'];
    }
    return $out;
}
