<?php
declare(strict_types=1);

/**
 * Les messages privés Instagram (Direct), lus et envoyés par l'API Messenger pour Instagram (permission
 * `instagram_manage_messages`, déjà accordée au System User ; le compte Instagram doit autoriser l'accès aux messages
 * dans ses réglages « Outils connectés »). Les messages privés de Facebook (Messenger) demandent `pages_messaging`,
 * que le token n'a pas : la boîte le dit, sans rien tenter.
 *
 *  - La marque : toutes les conversations des comptes Instagram (en Instagram unique, celui du réseau), celles à
 *    répondre d'abord ; elle répond, marque traité, ou confie une conversation à une boutique (question sur une
 *    commande, des horaires, un retrait) — la boutique la voit alors dans son espace.
 *  - La boutique : les conversations que le réseau lui a confiées, et celles de son propre Instagram s'il en a un ;
 *    elle répond (règle « repondreMessages ») — sa réponse part du compte Instagram de la conversation.
 *
 * Meta n'autorise une réponse que dans les 24 h qui suivent le dernier message du client (fenêtre standard) : au-delà,
 * l'outil le dit et renvoie vers l'application Instagram. Rien n'est envoyé à Claude.
 *
 * Tables : `social_conversation` (une par client et par compte, son attribution, son suivi) et `social_message`.
 * Relues par le cron toutes les heures et par « Relire ».
 */

const SOC_MSG_JOURS = 30;
const SOC_MSG_PAR_CONV = 25;
const SOC_MSG_FENETRE_H = 24;

/** Les comptes Instagram lisibles (avec la Page qui les porte et son token). */
function socMsgComptes(): array
{
    return array_filter(socCalComptes(), static fn ($c) => $c['type'] === 'instagram' && ($c['token'] ?? '') !== '' && ($c['page'] ?? '') !== '');
}

/** Relit chez Meta les conversations Instagram récentes et leurs derniers messages. */
function socMessagesSync(): array
{
    if (!Meta::configured()) { return ['lus' => 0, 'conversations' => 0, 'comptes' => 0, 'erreurs' => ['token Meta absent']]; }
    try { $comptes = socMsgComptes(); }
    catch (MetaError $e) { return ['lus' => 0, 'conversations' => 0, 'comptes' => 0, 'erreurs' => ['Pages illisibles : ' . $e->getMessage()]]; }
    $depuis = time() - SOC_MSG_JOURS * 86400;
    $lus = 0; $nc = 0; $erreurs = [];
    foreach ($comptes as $c) {
        try {
            $convs = socGraphTout($c['page'] . '/conversations', ['platform' => 'instagram', 'limit' => 25,
                'fields' => 'id,updated_time,participants,messages.limit(' . SOC_MSG_PAR_CONV . '){id,created_time,from,to,message,attachments}'], 200, $c['token']);
            foreach ($convs as $cv) {
                if (!is_array($cv) || empty($cv['id']) || (strtotime((string) ($cv['updated_time'] ?? '')) ?: 0) < $depuis) { continue; }
                $lus += socMsgGarder($cv, $c);
                $nc++;
            }
        } catch (MetaError $e) {
            $erreurs[] = 'Instagram ' . ($c['name'] ?: $c['id']) . ' : ' . $e->getMessage()
                . (in_array($e->metaCode, [3, 10, 200, 230], true) ? ' (permission instagram_manage_messages, et « Autoriser l’accès aux messages » dans les réglages du compte Instagram)' : '');
        }
    }
    socSettingPut('messages_relus', ['le' => date('Y-m-d H:i:s'), 'lus' => $lus, 'conversations' => $nc, 'comptes' => count($comptes), 'erreurs' => $erreurs], false);
    return ['lus' => $lus, 'conversations' => $nc, 'comptes' => count($comptes), 'erreurs' => $erreurs];
}

/** Garde une conversation et ses messages ; un nouveau message du client la rouvre. Rend le nombre de messages vus. */
function socMsgGarder(array $cv, array $c): int
{
    $moi = (string) $c['id'];
    $client = null;
    foreach ((array) ($cv['participants']['data'] ?? []) as $p) {
        if (is_array($p) && (string) ($p['id'] ?? '') !== '' && (string) $p['id'] !== $moi) { $client = $p; break; }
    }
    $msgs = array_values(array_filter((array) ($cv['messages']['data'] ?? []), static fn ($m) => is_array($m) && !empty($m['id'])));
    if ($client === null) {
        foreach ($msgs as $m) { if ((string) ($m['from']['id'] ?? '') !== $moi) { $client = (array) $m['from']; break; } }
    }
    $clientId = (string) ($client['id'] ?? ''); $clientNom = (string) (($client['username'] ?? '') ?: ($client['name'] ?? ''));
    $n = 0;
    foreach ($msgs as $m) {
        $in = (string) ($m['from']['id'] ?? '') !== $moi;
        $quand = date('Y-m-d H:i:s', strtotime((string) ($m['created_time'] ?? '')) ?: time());
        $pieces = [];
        foreach ((array) ($m['attachments']['data'] ?? []) as $a) {
            $u = (string) ($a['image_data']['url'] ?? ($a['video_data']['url'] ?? ($a['file_url'] ?? '')));
            if ($u !== '') { $pieces[] = ['type' => isset($a['video_data']) ? 'video' : (isset($a['image_data']) ? 'image' : 'fichier'), 'url' => $u]; }
        }
        $texte = (string) ($m['message'] ?? '');
        if ($texte === '' && !$pieces) { $texte = '(message non lisible ici : story, réaction ou contenu partagé)'; }
        Db::exec('INSERT INTO social_message (id, conversation_id, sens, auteur, texte, pieces, cree_le) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE texte = VALUES(texte), pieces = VALUES(pieces)',
            [(string) $m['id'], (string) $cv['id'], $in ? 'in' : 'out', $in ? $clientNom : (string) ($m['from']['username'] ?? ($c['name'] ?? '')), mb_substr($texte, 0, 4000),
             $pieces ? json_encode($pieces, JSON_UNESCAPED_SLASHES) : null, $quand]);
        $n++;
    }
    $der = Db::row('SELECT sens, texte, pieces, cree_le FROM social_message WHERE conversation_id = ? ORDER BY cree_le DESC, id DESC LIMIT 1', [(string) $cv['id']]);
    if ($der && (string) $der['texte'] === '' && $der['pieces']) { $der['texte'] = 'Photo ou vidéo'; }
    $dIn = Db::row("SELECT MAX(cree_le) d FROM social_message WHERE conversation_id = ? AND sens = 'in'", [(string) $cv['id']])['d'] ?? null;
    $shop = $c['shop'] !== null && $c['shop'] !== 'marque' ? (string) $c['shop'] : null;
    $maintenant = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO social_conversation (id, canal, compte, page_id, shop_id, client_id, client_nom, dernier_le, dernier_sens, dernier_texte, dernier_in_le, vu_le, maj_le)
        VALUES (?,\'ig\',?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE client_id = IF(VALUES(client_id) <> \'\', VALUES(client_id), client_id), client_nom = IF(VALUES(client_nom) <> \'\', VALUES(client_nom), client_nom),
            dernier_le = VALUES(dernier_le), dernier_sens = VALUES(dernier_sens), dernier_texte = VALUES(dernier_texte), dernier_in_le = VALUES(dernier_in_le), maj_le = VALUES(maj_le),
            traite_le = IF(VALUES(dernier_in_le) IS NOT NULL AND traite_le IS NOT NULL AND VALUES(dernier_in_le) > traite_le, NULL, traite_le)',
        [(string) $cv['id'], (string) $c['key'], (string) $c['page'], $shop, $clientId, mb_substr($clientNom, 0, 120),
         $der['cree_le'] ?? null, $der['sens'] ?? null, mb_substr((string) ($der['texte'] ?? ''), 0, 500), $dIn, $maintenant, $maintenant]);
    return $n;
}

/** Une conversation, telle que l'écran la montre (sans ses messages). */
function socMsgLigne(array $r, array $noms): array
{
    $dIn = $r['dernier_in_le'] ? strtotime((string) $r['dernier_in_le']) : null;
    $aRepondre = $r['dernier_sens'] === 'in' && $r['traite_le'] === null;
    $boutique = $r['attribue_shop'] ?: $r['shop_id'];
    return ['id' => (string) $r['id'], 'canal' => 'Instagram', 'compte' => (string) $r['compte'], 'client' => (string) ($r['client_nom'] ?: 'Client Instagram'),
        'dernierLe' => $r['dernier_le'] ? substr((string) $r['dernier_le'], 0, 16) : null, 'dernierSens' => (string) $r['dernier_sens'], 'dernierTexte' => (string) ($r['dernier_texte'] ?? ''),
        'aRepondre' => $aRepondre, 'traiteLe' => $r['traite_le'] ? substr((string) $r['traite_le'], 0, 16) : null,
        'ageH' => $dIn ? round((time() - $dIn) / 3600, 1) : null, 'fenetre' => $dIn !== null && time() - $dIn < SOC_MSG_FENETRE_H * 3600,
        'fenetreFin' => $dIn !== null ? date('Y-m-d H:i', $dIn + SOC_MSG_FENETRE_H * 3600) : null,
        'shop' => $boutique !== null ? (string) $boutique : null, 'shopNom' => $boutique !== null ? ($noms[(string) $boutique] ?? (string) $boutique) : null,
        'attribue' => $r['attribue_shop'] !== null, 'attribuePar' => (string) ($r['attribue_par'] ?? ''), 'note' => (string) ($r['attribue_note'] ?? ''),
        'reponduPar' => (string) ($r['repondu_par'] ?? '')];
}

function socMsgNoms(): array
{
    $n = []; foreach (socShops() as $s) { $n[(string) $s['id']] = (string) $s['name']; }
    return $n;
}

/** La boîte de la marque : conversations des 30 derniers jours, à répondre d'abord. Filtre : afaire | boutiques | tout. */
function socMessages(array $q = []): array
{
    $f = (string) ($q['filtre'] ?? 'afaire');
    $where = 'c.dernier_le >= ?'; $args = [date('Y-m-d H:i:s', strtotime('-' . SOC_MSG_JOURS . ' days'))];
    if ($f === 'afaire') { $where .= " AND c.dernier_sens = 'in' AND c.traite_le IS NULL"; }
    elseif ($f === 'boutiques') { $where .= ' AND c.attribue_shop IS NOT NULL'; }
    $noms = socMsgNoms();
    $l = array_map(static fn ($r) => socMsgLigne($r, $noms), Db::rows("SELECT c.* FROM social_conversation c WHERE $where ORDER BY (c.dernier_sens = 'in' AND c.traite_le IS NULL) DESC, c.dernier_le DESC LIMIT 200", $args));
    $tot = Db::row("SELECT SUM(dernier_sens = 'in' AND traite_le IS NULL) afaire, SUM(dernier_sens = 'in' AND traite_le IS NULL AND attribue_shop IS NULL) reseau, SUM(attribue_shop IS NOT NULL) boutiques, COUNT(*) tout
        FROM social_conversation WHERE dernier_le >= ?", $args) ?? [];
    $scopes = (array) ((socSetting('meta_jeton_info') ?? [])['scopes'] ?? []);
    return ['conversations' => $l, 'totaux' => ['afaire' => (int) ($tot['afaire'] ?? 0), 'reseau' => (int) ($tot['reseau'] ?? 0), 'boutiques' => (int) ($tot['boutiques'] ?? 0), 'tout' => (int) ($tot['tout'] ?? 0)],
        'relus' => socSetting('messages_relus'), 'jours' => SOC_MSG_JOURS, 'fenetreH' => SOC_MSG_FENETRE_H,
        'permissions' => ['instagram' => !$scopes || in_array('instagram_manage_messages', $scopes, true), 'messenger' => in_array('pages_messaging', $scopes, true)],
        'boutiques' => array_map(static fn ($id, $nom) => ['id' => (string) $id, 'nom' => $nom], array_keys($noms), $noms)];
}

function socMsgConversationRow(string $id): array
{
    $r = Db::row('SELECT * FROM social_conversation WHERE id = ?', [$id]);
    if (!$r) { throw new HttpError(404, 'Conversation introuvable.'); }
    return $r;
}

/** Une conversation et ses messages (du plus ancien au plus récent). */
function socMsgConversation(string $id): array
{
    $r = socMsgConversationRow($id);
    $msgs = array_map(static fn ($m) => ['id' => (string) $m['id'], 'sens' => (string) $m['sens'], 'auteur' => (string) ($m['auteur'] ?? ''), 'texte' => (string) ($m['texte'] ?? ''),
        'pieces' => $m['pieces'] ? (json_decode((string) $m['pieces'], true) ?: []) : [], 'le' => substr((string) $m['cree_le'], 0, 16), 'par' => (string) ($m['par'] ?? '')],
        Db::rows('SELECT * FROM social_message WHERE conversation_id = ? ORDER BY cree_le, id', [$id]));
    return ['conversation' => socMsgLigne($r, socMsgNoms()), 'messages' => $msgs];
}

/** Répond dans la conversation, depuis le compte Instagram qui la porte (token de sa Page), dans la fenêtre de 24 h. */
function socMsgRepondre(string $id, string $texte, ?string $qui = null): array
{
    $r = socMsgConversationRow($id);
    $texte = trim(preg_replace('/[ \t]+/u', ' ', $texte) ?? '');
    if ($texte === '') { throw new HttpError(422, 'Écrivez la réponse.'); }
    if (mb_strlen($texte) > 1000) { throw new HttpError(422, 'Réponse trop longue (1 000 caractères au plus pour un message Instagram).'); }
    if ((string) $r['client_id'] === '') { throw new HttpError(422, 'Destinataire inconnu : relisez les messages.'); }
    $dIn = $r['dernier_in_le'] ? strtotime((string) $r['dernier_in_le']) : null;
    if ($dIn === null || time() - $dIn >= SOC_MSG_FENETRE_H * 3600) {
        throw new HttpError(422, 'Plus de 24 h depuis le dernier message du client : Meta n’autorise plus la réponse depuis l’outil. Répondez depuis l’application Instagram.');
    }
    $c = socCalComptes()[(string) $r['compte']] ?? null;
    if ($c === null || ($c['token'] ?? '') === '') { throw new HttpError(422, 'Le compte Instagram de cette conversation n’est plus visible du System User.'); }
    try {
        $res = Meta::call('POST', (string) $r['page_id'] . '/messages', ['recipient' => ['id' => (string) $r['client_id']], 'message' => ['text' => $texte], 'messaging_type' => 'RESPONSE'], (string) $c['token']);
    } catch (MetaError $e) {
        $m = $e->getMessage();
        if (in_array($e->metaCode, [10, 200, 230, 3], true) && !preg_match('/window|fenêtre|24/i', $m)) {
            throw new HttpError(422, 'Meta refuse l’envoi : ' . $m . ' — permission instagram_manage_messages, et « Autoriser l’accès aux messages » dans les réglages du compte Instagram.');
        }
        throw new HttpError(422, 'Meta refuse l’envoi : ' . $m);
    }
    $mid = (string) ($res['message_id'] ?? ('local-' . bin2hex(random_bytes(6))));
    $qui ??= socUser();
    $maintenant = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO social_message (id, conversation_id, sens, auteur, texte, pieces, cree_le, par) VALUES (?,?,\'out\',?,?,NULL,?,?)
        ON DUPLICATE KEY UPDATE par = VALUES(par)', [$mid, $id, (string) ($c['name'] ?? ''), $texte, $maintenant, mb_substr($qui, 0, 120)]);
    Db::exec("UPDATE social_conversation SET dernier_le = ?, dernier_sens = 'out', dernier_texte = ?, traite_le = ?, repondu_par = ?, maj_le = ? WHERE id = ?",
        [$maintenant, mb_substr($texte, 0, 500), $maintenant, mb_substr($qui, 0, 120), $maintenant, $id]);
    socLog($qui, 'Messages', 'Réponse en message privé Instagram à ' . ((string) $r['client_nom'] ?: 'un client'));
    return socMsgConversation($id);
}

/** Marque traitée (ou rouvre) une conversation sans y répondre. */
function socMsgTraiter(string $id, bool $traite): array
{
    socMsgConversationRow($id);
    Db::exec('UPDATE social_conversation SET traite_le = ? WHERE id = ?', [$traite ? date('Y-m-d H:i:s') : null, $id]);
    return socMsgConversation($id);
}

/** La marque confie la conversation à une boutique (ou la reprend : shop vide), avec un mot. */
function socMsgAttribuer(string $id, string $shop, string $note = ''): array
{
    $r = socMsgConversationRow($id);
    if ($shop !== '' && !in_array($shop, array_map(static fn ($s) => (string) $s['id'], socShops()), true)) { throw new HttpError(422, 'Boutique inconnue.'); }
    Db::exec('UPDATE social_conversation SET attribue_shop = ?, attribue_par = ?, attribue_le = ?, attribue_note = ? WHERE id = ?',
        [$shop !== '' ? $shop : null, $shop !== '' ? socUser() : null, $shop !== '' ? date('Y-m-d H:i:s') : null, $shop !== '' ? mb_substr(trim($note), 0, 300) : null, $id]);
    socLog(socUser(), 'Messages', $shop !== '' ? 'Conversation avec ' . ((string) $r['client_nom'] ?: 'un client') . ' confiée à ' . (socMsgNoms()[$shop] ?? $shop) : 'Conversation reprise par le réseau');
    return socMsgConversation($id);
}

/** Les pastilles : à répondre pour le réseau (non confiées), et pour une boutique (les siennes). */
function socMessagesAlertes(?string $shop = null): array
{
    $d = date('Y-m-d H:i:s', strtotime('-' . SOC_MSG_JOURS . ' days'));
    if ($shop !== null) {
        $n = Db::row("SELECT COUNT(*) n FROM social_conversation WHERE dernier_le >= ? AND dernier_sens = 'in' AND traite_le IS NULL AND (attribue_shop = ? OR (attribue_shop IS NULL AND shop_id = ?))", [$d, $shop, $shop]);
        return ['aRepondre' => (int) ($n['n'] ?? 0)];
    }
    $n = Db::row("SELECT COUNT(*) n FROM social_conversation WHERE dernier_le >= ? AND dernier_sens = 'in' AND traite_le IS NULL AND attribue_shop IS NULL AND shop_id IS NULL", [$d]);
    return ['aRepondre' => (int) ($n['n'] ?? 0)];
}

/* --- côté franchisé ------------------------------------------------------------- */

/** La conversation est-elle à cette boutique ? (confiée par le réseau, ou sur son propre Instagram) — sinon 404. */
function sfMsgPropre(string $shop, string $id): array
{
    $r = Db::row('SELECT * FROM social_conversation WHERE id = ?', [$id]);
    $a = $r ? ($r['attribue_shop'] !== null ? (string) $r['attribue_shop'] : ($r['shop_id'] !== null ? (string) $r['shop_id'] : null)) : null;
    if (!$r || $a !== $shop) { throw new HttpError(404, 'Conversation introuvable.'); }
    return $r;
}

function sfMessages(string $shop): array
{
    if (!sfRegles()['voirMessages']) { return ['conversations' => [], 'masque' => true, 'aRepondre' => 0]; }
    $noms = socMsgNoms();
    $l = array_map(static fn ($r) => socMsgLigne($r, $noms), Db::rows("SELECT * FROM social_conversation WHERE dernier_le >= ? AND (attribue_shop = ? OR (attribue_shop IS NULL AND shop_id = ?))
        ORDER BY (dernier_sens = 'in' AND traite_le IS NULL) DESC, dernier_le DESC LIMIT 100", [date('Y-m-d H:i:s', strtotime('-' . SOC_MSG_JOURS . ' days')), $shop, $shop]));
    return ['conversations' => $l, 'masque' => false, 'aRepondre' => count(array_filter($l, static fn ($c) => $c['aRepondre'])), 'peutRepondre' => (bool) sfRegles()['repondreMessages'],
        'fenetreH' => SOC_MSG_FENETRE_H, 'relus' => (socSetting('messages_relus') ?? [])['le'] ?? null];
}

function sfMsgConversation(string $shop, string $id): array
{
    if (!sfRegles()['voirMessages']) { throw new HttpError(403, 'Le réseau ne partage pas les messages privés dans l’espace boutique.'); }
    sfMsgPropre($shop, $id);
    return socMsgConversation($id);
}

function sfMsgRepondre(string $shop, string $id, string $texte, array $session): array
{
    if (!sfRegles()['voirMessages'] || !sfRegles()['repondreMessages']) { throw new HttpError(403, 'Le réseau répond lui-même aux messages privés.'); }
    sfMsgPropre($shop, $id);
    return socMsgRepondre($id, $texte, (string) (($session['name'] ?? '') ?: 'Boutique') . ' · ' . (string) (socShop($shop)['name'] ?? $shop));
}

function sfMsgTraiter(string $shop, string $id, bool $traite): array
{
    if (!sfRegles()['voirMessages']) { throw new HttpError(403, 'Le réseau ne partage pas les messages privés dans l’espace boutique.'); }
    sfMsgPropre($shop, $id);
    return socMsgTraiter($id, $traite);
}
