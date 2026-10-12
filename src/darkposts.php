<?php
declare(strict_types=1);

/**
 * Dark posts : les posts « pub uniquement » que Meta crée pour une pub en
 * « nouvelle créa » (object_story_spec). Ils n'apparaissent ni sur le mur de la
 * Page ni sur la grille Instagram, seulement en sponsorisé.
 *
 *  - Un post du module marqué « Réservé à la publicité » (`dark`) n'est jamais
 *    publié : il sert de visuel et de texte à l'assistant des publicités.
 *  - Après la création de chaque pub, le serveur relit l'id du dark post
 *    (`effective_object_story_id`) et son pendant Instagram
 *    (`effective_instagram_media_id`) : lien pour l'ouvrir, réutilisation dans
 *    une autre pub (likes et commentaires cumulés), suivi des commentaires.
 *  - Les commentaires sont relus toutes les heures (cron) ou à la demande, gardés
 *    dans `social_commentaire`, et peuvent être masqués depuis l'écran.
 */

/** Relit chez Meta le dark post d'une pub créée (ou rien, s'il n'existe pas encore). */
function socDarkRelire(array $o, array $shop): array
{
    if (empty($o['creative_id'])) { return $o; }
    try {
        $c = Meta::call('GET', (string) $o['creative_id'], ['fields' => 'effective_object_story_id,effective_instagram_media_id']);
    } catch (MetaError $e) { return $o; }
    $story = (string) ($c['effective_object_story_id'] ?? '');
    $igm = (string) ($c['effective_instagram_media_id'] ?? '');
    if ($story !== '') {
        $o['story_id'] = $story;
        $o['lien'] = 'https://www.facebook.com/' . $story;
    }
    if ($igm !== '') {
        $o['ig_media_id'] = $igm;
        if (empty($o['lien_ig'])) {
            try {
                $tok = ($shop['ids']['page_id'] ?? '') !== '' ? Meta::pageToken((string) $shop['ids']['page_id']) : null;
                $m = Meta::call('GET', $igm, ['fields' => 'permalink'], $tok);
                if (!empty($m['permalink'])) { $o['lien_ig'] = mb_substr((string) $m['permalink'], 0, 300); }
            } catch (MetaError $e) { /* le lien Instagram n'est pas indispensable */ }
        }
    }
    return $o;
}

/** Le dark post déjà diffusé d'un post du module pour une boutique (même langue de préférence). */
function socDarkStory(string $postId, string $shopId, string $lang): string
{
    $rows = Db::rows("SELECT story_id, langue FROM social_meta_objet WHERE kind = 'ad' AND post_id = ? AND shop_id = ? AND dark = 1 AND story_id IS NOT NULL ORDER BY id DESC",
        [$postId, $shopId]);
    foreach ($rows as $r) { if ((string) $r['langue'] === $lang) { return (string) $r['story_id']; } }
    return (string) ($rows[0]['story_id'] ?? '');
}

/** Les dark posts connus, pour les écrans : pub, post du module, boutique, langue, variante, liens, commentaires. */
function socDarkPosts(): array
{
    $n = [];
    foreach (Db::rows('SELECT objet_id, COUNT(*) n, SUM(masque) m FROM social_commentaire GROUP BY objet_id') as $r) { $n[(string) $r['objet_id']] = [(int) $r['n'], (int) $r['m']]; }
    $out = [];
    foreach (Db::rows("SELECT local_id, post_id, shop_id, langue, variante, story_id, ig_media_id, lien, lien_ig FROM social_meta_objet
                       WHERE kind = 'ad' AND dark = 1 AND ad_id IS NOT NULL ORDER BY id") as $r) {
        $fb = $n[(string) $r['story_id']] ?? [0, 0]; $ig = $n[(string) $r['ig_media_id']] ?? [0, 0];
        $out[] = ['ad' => (string) $r['local_id'], 'post' => (string) ($r['post_id'] ?? ''), 'shop' => (string) $r['shop_id'], 'lang' => (string) ($r['langue'] ?? ''),
            'variant' => chr(65 + (int) ($r['variante'] ?? 0)), 'story' => (string) ($r['story_id'] ?? ''), 'ig' => (string) ($r['ig_media_id'] ?? ''),
            'link' => (string) ($r['lien'] ?? ''), 'linkIg' => (string) ($r['lien_ig'] ?? ''), 'comments' => $fb[0] + $ig[0], 'hidden' => $fb[1] + $ig[1]];
    }
    return $out;
}

/** Relit chez Meta les commentaires des dark posts (de toutes les pubs, ou d'une seule). */
function socDarkCommentsSync(?string $adId = null): array
{
    if (!Meta::configured()) { return ['erreur' => 'token Meta absent']; }
    $sql = "SELECT local_id, post_id, shop_id, story_id, ig_media_id FROM social_meta_objet WHERE kind = 'ad' AND dark = 1 AND ad_id IS NOT NULL"
        . " AND (story_id IS NOT NULL OR ig_media_id IS NOT NULL)" . ($adId !== null ? ' AND local_id = ?' : '');
    $shops = []; foreach (socShops() as $s) { $shops[$s['id']] = $s; }
    $vus = []; $lus = 0; $erreurs = [];
    foreach (Db::rows($sql, $adId !== null ? [$adId] : []) as $r) {
        $shop = $shops[(string) $r['shop_id']] ?? null;
        if (!$shop) { continue; }
        try { $tok = ($shop['ids']['page_id'] ?? '') !== '' ? Meta::pageToken((string) $shop['ids']['page_id']) : null; }
        catch (MetaError $e) { $erreurs[] = $shop['name'] . ' : ' . $e->getMessage(); continue; }
        foreach ([['fb', (string) ($r['story_id'] ?? '')], ['ig', (string) ($r['ig_media_id'] ?? '')]] as [$canal, $objet]) {
            if ($objet === '' || isset($vus[$objet])) { continue; }
            $vus[$objet] = true;
            try {
                $c = $canal === 'fb'
                    ? Meta::call('GET', $objet . '/comments', ['fields' => 'id,from{name},message,created_time,is_hidden', 'filter' => 'stream', 'limit' => 100], $tok)
                    : Meta::call('GET', $objet . '/comments', ['fields' => 'id,username,text,timestamp,hidden', 'limit' => 100], $tok);
                foreach ((array) ($c['data'] ?? []) as $x) {
                    if (!is_array($x) || empty($x['id'])) { continue; }
                    $quand = strtotime((string) ($x['created_time'] ?? $x['timestamp'] ?? '')) ?: time();
                    Db::exec('INSERT INTO social_commentaire (id, canal, objet_id, shop_id, post_id, pub_id, auteur, message, cree_le, masque, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                        . ' ON DUPLICATE KEY UPDATE message = VALUES(message), masque = VALUES(masque), maj_le = VALUES(maj_le)',
                        [(string) $x['id'], $canal, $objet, (string) $r['shop_id'], $r['post_id'] ?? null, (string) $r['local_id'],
                            mb_substr((string) ($x['from']['name'] ?? $x['username'] ?? ''), 0, 160), mb_substr((string) ($x['message'] ?? $x['text'] ?? ''), 0, 5000),
                            date('Y-m-d H:i:s', $quand), !empty($x['is_hidden']) || !empty($x['hidden']) ? 1 : 0, date('Y-m-d H:i:s')]);
                    $lus++;
                }
            } catch (MetaError $e) {
                $erreurs[] = $shop['name'] . ' (' . ($canal === 'fb' ? 'Facebook' : 'Instagram') . ') : ' . $e->getMessage();
            }
        }
    }
    return ['lus' => $lus, 'objets' => count($vus), 'erreurs' => $erreurs];
}

/** Les commentaires d'une pub ou d'un post du module, du plus récent au plus ancien. */
function socDarkComments(?string $adId, ?string $postId): array
{
    if ($adId === null && $postId === null) { return []; }
    $rows = $adId !== null
        ? Db::rows('SELECT * FROM social_commentaire WHERE pub_id = ? ORDER BY cree_le DESC LIMIT 200', [$adId])
        : Db::rows('SELECT * FROM social_commentaire WHERE post_id = ? ORDER BY cree_le DESC LIMIT 200', [$postId]);
    $noms = []; foreach (socShops() as $s) { $noms[$s['id']] = $s['name']; }
    return array_map(static fn ($r) => ['id' => (string) $r['id'], 'channel' => $r['canal'] === 'ig' ? 'Instagram' : 'Facebook', 'shop' => (string) $r['shop_id'],
        'shopName' => $noms[(string) $r['shop_id']] ?? (string) $r['shop_id'], 'author' => (string) ($r['auteur'] ?? ''), 'message' => (string) ($r['message'] ?? ''),
        'at' => substr((string) $r['cree_le'], 0, 16), 'hidden' => (int) $r['masque'] === 1], $rows);
}

/** Masque (ou réaffiche) un commentaire chez Meta. */
function socDarkCommentMasquer(string $id, bool $masquer): array
{
    $r = Db::row('SELECT * FROM social_commentaire WHERE id = ?', [$id]);
    if ($r === null) { throw new HttpError(404, 'commentaire inconnu'); }
    $shop = socShop((string) $r['shop_id']);
    $tok = $shop && ($shop['ids']['page_id'] ?? '') !== '' ? Meta::pageToken((string) $shop['ids']['page_id']) : null;
    Meta::call('POST', $id, $r['canal'] === 'ig' ? ['hide' => $masquer] : ['is_hidden' => $masquer], $tok);
    Db::exec('UPDATE social_commentaire SET masque = ?, maj_le = ? WHERE id = ?', [$masquer ? 1 : 0, date('Y-m-d H:i:s'), $id]);
    socLog(socUser(), 'Commentaire', ($masquer ? 'Masqué' : 'Réaffiché') . ' sur un dark post (' . ($r['canal'] === 'ig' ? 'Instagram' : 'Facebook') . ', '
        . ($shop['name'] ?? $r['shop_id']) . ') : « ' . mb_substr((string) $r['message'], 0, 80) . ' »');
    return ['id' => $id, 'hidden' => $masquer];
}
