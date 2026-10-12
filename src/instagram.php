<?php
declare(strict_types=1);

/**
 * Instagram : la grille du profil et la frise.
 *
 *  - La grille (écran « Grille Instagram ») : les publications déjà en ligne sont
 *    relues chez Instagram (`GET /{ig_user_id}/media`) et gardées dans
 *    `social_instagram_media` ; les posts programmés viennent du calendrier.
 *  - La frise : un visuel découpé en 3 posts qui forment une ligne du profil.
 *    Ils partent à la suite, de droite à gauche (le plus récent s'affiche en haut
 *    à gauche), et aucun autre post Instagram de la boutique ne doit tomber dans
 *    la demi-heure qui précède ou qui suit : il décalerait la ligne.
 */

const SOC_IG_CHAMPS = 'id,media_type,media_url,thumbnail_url,permalink,timestamp,caption';
const SOC_FRISE_VERROU = 30;  // minutes sans autre post Instagram avant et après une frise

/** Relit chez Instagram les dernières publications de chaque boutique (ou d'une seule). */
function socIgMediaSync(?string $shopId = null, int $limite = 30): array
{
    $out = [];
    if (!Meta::configured()) { return ['erreur' => 'token Meta absent']; }
    // Instagram unique : une seule grille, celle de la marque.
    foreach (socIgUnique() ? [socBrand()] : socShops() as $s) {
        if ($shopId !== null && (string) $s['id'] !== $shopId) { continue; }
        $ig = (string) ($s['ids']['instagram_user_id'] ?? '');
        if ($ig === '') { continue; }
        try {
            $tok = socIgToken($ig, (string) ($s['ids']['page_id'] ?? ''));
            $r = Meta::call('GET', $ig . '/media', ['fields' => SOC_IG_CHAMPS, 'limit' => $limite], $tok);
            $vus = [];
            foreach ((array) ($r['data'] ?? []) as $m) {
                if (!is_array($m) || empty($m['id'])) { continue; }
                $vus[] = (string) $m['id'];
                $quand = strtotime((string) ($m['timestamp'] ?? '')) ?: time();
                Db::exec('INSERT INTO social_instagram_media (media_id, shop_id, type, media_url, vignette_url, permalink, publie_le, legende, maj_le)'
                    . ' VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id), type = VALUES(type), media_url = VALUES(media_url),'
                    . ' vignette_url = VALUES(vignette_url), permalink = VALUES(permalink), publie_le = VALUES(publie_le), legende = VALUES(legende), maj_le = VALUES(maj_le)',
                    [(string) $m['id'], (string) $s['id'], mb_substr((string) ($m['media_type'] ?? ''), 0, 20), mb_substr((string) ($m['media_url'] ?? ''), 0, 1000) ?: null,
                        mb_substr((string) ($m['thumbnail_url'] ?? ''), 0, 1000) ?: null, mb_substr((string) ($m['permalink'] ?? ''), 0, 300) ?: null,
                        date('Y-m-d H:i:s', $quand), mb_substr((string) ($m['caption'] ?? ''), 0, 2000), date('Y-m-d H:i:s')]);
            }
            // Seules les dernières publications comptent pour la grille : le reste est oublié
            // (dont celles supprimées dans l'application Instagram).
            if ($vus) {
                Db::exec('DELETE FROM social_instagram_media WHERE shop_id = ? AND media_id NOT IN (' . implode(',', array_fill(0, count($vus), '?')) . ')', array_merge([(string) $s['id']], $vus));
            } else {
                Db::exec('DELETE FROM social_instagram_media WHERE shop_id = ?', [(string) $s['id']]);
            }
            socShopPut((string) $s['id'], ['ig_grille_le' => date('Y-m-d H:i:s')]);
            $out[(string) $s['id']] = count($vus);
        } catch (MetaError $e) {
            $out[(string) $s['id']] = 'échec : ' . socMotif($e, 'ig');
        }
    }
    return $out;
}

/** La grille d'une boutique telle qu'Instagram l'a rendue à la dernière lecture (du plus récent au plus ancien). */
function socIgGrille(string $shopId): array
{
    $rows = Db::rows('SELECT media_id, type, media_url, vignette_url, permalink, publie_le, legende FROM social_instagram_media WHERE shop_id = ? ORDER BY publie_le DESC, media_id DESC', [$shopId]);
    $media = array_map(static fn ($r) => ['id' => (string) $r['media_id'], 'type' => (string) $r['type'],
        'src' => (string) (($r['type'] === 'VIDEO' ? $r['vignette_url'] : $r['media_url']) ?: ($r['media_url'] ?? '')),
        'permalink' => (string) ($r['permalink'] ?? ''), 'at' => substr((string) $r['publie_le'], 0, 16), 'caption' => mb_substr((string) $r['legende'], 0, 140)], $rows);
    $shop = $shopId === 'marque' ? socBrand() : socShop($shopId);
    return ['shop' => $shopId, 'media' => $media, 'lu_le' => socShopOverrides()[$shopId]['ig_grille_le'] ?? null,
        'instagram' => $shop !== null && (string) ($shop['ids']['instagram_user_id'] ?? '') !== ''];
}

/**
 * Instagram unique : un seul compte (celui de la marque) pour tout le réseau.
 * Réglage `instagram_unique` (oui / non) ; sans réglage : oui dès que la marque
 * a son Instagram et qu'aucune boutique n'en a un autre.
 */
function socIgUnique(): bool
{
    $v = socSetting('instagram_unique');
    if (is_bool($v)) { return $v; }
    $ig = (string) (socBrand()['ids']['instagram_user_id'] ?? '');
    if ($ig === '') { return false; }
    foreach (socShops() as $s) {
        $x = (string) ($s['ids']['instagram_user_id'] ?? '');
        if ($x !== '' && $x !== $ig) { return false; }
    }
    return true;
}

/**
 * Le « compte » sur lequel part un post Instagram en mode unique : la marque,
 * avec, pour les variables du texte, la boutique visée (une seule) ou la liste
 * des boutiques ({ville} = « Halle, Corbais et Wavre »).
 */
function socIgCompteReseau(array $post): array
{
    $b = socBrand();
    $parId = []; foreach (socShops() as $s) { $parId[(string) $s['id']] = $s; }
    $cibles = array_values(array_filter(array_map(static fn ($id) => $parId[(string) $id] ?? null, (array) ($post['shops'] ?? []))));
    if (count($cibles) === 1) { return array_replace($cibles[0], ['id' => 'marque', 'ids' => $b['ids']]); }
    $noms = array_map(static fn ($s) => (string) $s['name'], $cibles);
    $ville = count($noms) > 1 ? implode(', ', array_slice($noms, 0, -1)) . ' et ' . end($noms) : ($noms[0] ?? '');
    $langs = [];
    foreach ($cibles as $s) { foreach ((array) ($s['langs'] ?: ['fr']) as $L) { if (!in_array($L, $langs, true)) { $langs[] = $L; } } }
    return array_replace($b, ['name' => $ville !== '' ? $ville : $b['name'], 'langs' => $langs ?: ['fr'], 'address' => '', 'hours' => '', 'hoursNl' => '']);
}

/** Le token pour un compte Instagram : celui de la Page à laquelle il est rattaché, sinon de la Page donnée. */
function socIgToken(string $ig, string $pageId): ?string
{
    try {
        foreach (Meta::pageTokens() as $pid => $pg) { if (($pg['ig'] ?? '') === $ig) { return (string) $pg['token']; } }
        return $pageId !== '' ? Meta::pageToken($pageId) : null;
    } catch (MetaError $e) { return null; }
}

/**
 * Compte publicitaire unique : aucune boutique n'a le sien, tout passe par le
 * compte du réseau (celui de la marque). Les connexions ne se suivent alors
 * qu'une fois, au niveau du réseau.
 */
function socPubReseau(): bool
{
    foreach (socShops() as $s) { if (($s['ids']['ad_account_id'] ?? '') !== '') { return false; } }
    return true;
}

/** Ce qui se suit boutique par boutique : la Page, plus Instagram et la pub s'ils ne sont pas uniques au réseau. */
function socConnexionsBoutique(): array
{
    return array_values(array_filter(['fb', socIgUnique() ? null : 'igs', socPubReseau() ? null : 'ads']));
}

/** Est-ce une frise ? (3 tuiles prêtes) */
function socEstFrise(array $p): bool
{
    return is_array($p['frise']['tiles'] ?? null) && count($p['frise']['tiles']) === 3;
}

/** Les boutiques sur lesquelles un post part sur Instagram (en mode unique : « marque », une fois). */
function socShopsIg(array $p): array
{
    $out = [];
    foreach ((array) ($p['shops'] ?? []) as $sid) {
        $c = $p['ch'][(string) $sid] ?? null;
        if (is_array($c) ? !empty($c['ig']) : !empty($p['ig'])) { $out[] = (string) $sid; }
    }
    return $out && socIgUnique() ? ['marque'] : $out;
}

/**
 * Les posts d'un même enregistrement (un échange de dates dans la grille en
 * envoie deux) : le verrou les compare dans leur nouvel état, pas dans l'ancien.
 */
function socLotPosts(?array $lot = null): array
{
    static $courant = [];
    if ($lot !== null) { $courant = []; foreach ($lot as $x) { if (is_array($x) && isset($x['id'])) { $courant[(string) $x['id']] = $x; } } }
    return $courant;
}

/**
 * Le verrou de la frise : rend le motif du refus, ou null. Une frise ne
 * partage pas sa demi-heure (avant et après) avec un autre post Instagram de la
 * même boutique ; un post ordinaire ne se programme pas dans la demi-heure d'une frise.
 */
function socFriseConflit(array $p): ?string
{
    $shops = socShopsIg($p);
    if (!$shops || empty($p['date']) || empty($p['time'])) { return null; }
    $t = strtotime($p['date'] . ' ' . $p['time']);
    if ($t === false) { return null; }
    $frise = socEstFrise($p);
    $lot = socLotPosts();
    $posts = [];
    foreach (socItems('posts') as $q) { $posts[(string) $q['id']] = $lot[(string) $q['id']] ?? $q; }
    foreach ($lot as $id => $q) { $posts[$id] ??= $q; }
    foreach ($posts as $q) {
        if ((string) $q['id'] === (string) $p['id'] || ($q['status'] ?? '') !== 'Programmé') { continue; }
        if (!$frise && !socEstFrise($q)) { continue; }
        $commun = array_intersect($shops, socShopsIg($q));
        if (!$commun) { continue; }
        $tq = strtotime(($q['date'] ?? '') . ' ' . ($q['time'] ?? ''));
        if ($tq === false || abs($tq - $t) >= SOC_FRISE_VERROU * 60) { continue; }
        $noms = array_map(static fn ($sid) => (socShop($sid)['name'] ?? $sid), array_values($commun));
        return 'Instagram ' . implode(', ', $noms) . ' : « ' . ($q['title'] ?? $q['id']) . ' » est programmé à ' . $q['time'] . ' le ' . date('d/m', $tq)
            . ($frise ? '' : ' et c’est une frise') . ' — une frise demande ' . SOC_FRISE_VERROU . ' minutes sans autre post Instagram avant et après.';
    }
    return null;
}

/**
 * Publie une frise sur Instagram : les 3 conteneurs d'abord (un refus à cette
 * étape ne publie rien), puis la publication à la suite, tuile 3 → 2 → 1. Les
 * ids publiés sont notés au fil de l'eau dans le post : une relance ne republie
 * pas une tuile déjà en ligne.
 */
function socPublishFrise(array $post, array $shop, string $ig, ?string $tok, string $message): string
{
    $tiles = array_values(array_filter(array_map('strval', (array) ($post['frise']['tiles'] ?? [])), static fn ($u) => $u !== '' && !str_starts_with($u, 'data:')));
    if (count($tiles) !== 3) { throw new MetaError('frise incomplète : 3 images attendues', 100); }
    $sid = (string) $shop['id'];
    $deja = array_values((array) ($post['frise']['ig'][$sid] ?? []));
    $ordre = array_slice(array_reverse($tiles), count($deja));  // tuile 3 en premier
    $cids = [];
    foreach ($ordre as $u) {
        $c = Meta::call('POST', $ig . '/media', ['image_url' => socPublicUrl($u), 'caption' => $message], $tok);
        $cids[] = (string) ($c['id'] ?? '');
    }
    foreach ($cids as $cid) {
        for ($i = 0; $i < 10; $i++) {
            try { $s = Meta::call('GET', $cid, ['fields' => 'status_code'], $tok); } catch (MetaError $e) { break; }
            $code = (string) ($s['status_code'] ?? 'FINISHED');
            if ($code === 'FINISHED') { break; }
            if ($code === 'ERROR' || $code === 'EXPIRED') { throw new MetaError('Instagram a refusé une image de la frise (' . $code . ') — rien n’a été publié', 2207026); }
            sleep(2);
        }
    }
    foreach ($cids as $cid) {
        $r = Meta::call('POST', $ig . '/media_publish', ['creation_id' => $cid], $tok);
        $deja[] = (string) ($r['id'] ?? '');
        $p = socItem('posts', (string) $post['id']) ?? $post;
        $p['frise']['ig'][$sid] = $deja;
        socItemPut('posts', $p);
    }
    return implode(',', $deja);
}
