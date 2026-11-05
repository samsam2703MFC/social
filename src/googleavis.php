<?php

declare(strict_types=1);

/**
 * Répondre aux avis Google depuis le module (Business Profile API, avis « v4 »).
 *
 *  - Quand le compte Google est connecté, vérifié, que Google a ouvert l'accès aux fiches et que la fiche de la
 *    boutique est rattachée (Paramètres → Google), le module lit TOUS les avis de la fiche et leurs réponses — pas
 *    seulement les cinq que rend Google Places au BO — et publie une réponse sous un avis (`PUT …/reply`).
 *  - Tant que ce n'est pas le cas, rien n'est présenté comme branché : la réponse se prépare ici (proposée par Claude
 *    si une clé est réglée, sinon par un modèle selon la note et la langue de l'avis), se copie, se publie sur la
 *    fiche, et « J'ai répondu sur la fiche » la garde et marque l'avis traité.
 *  - Un avis lu chez Google et le même avis lu par le BO (Places) ne font qu'une ligne : même boutique, même auteur,
 *    même note, même jour (à un jour près : Places et Business Profile ne datent pas dans le même fuseau).
 *  - `reponse_id` dit d'où vient la réponse : `gbp` publiée par le module, `fiche` lue sur la fiche Google (écrite
 *    ailleurs), `manuel` notée ici après publication à la main.
 */

const SOC_GBP_API = 'https://mybusiness.googleapis.com/v4/';
const SOC_GBP_MAX_PAR_FICHE = 1000;
const SOC_GBP_REPONSE_MAX = 4000;
const SOC_GBP_ETOILES = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

/**
 * Où en est la réponse directe à Google, pour le réseau ou pour une boutique : active, ou la raison, dite pour l'écran.
 *
 * @return array{active: bool, raison: string, fiches: int}
 */
function socGbpEtat(?string $shop = null): array
{
    $ids = socGapiIds()['boutiques'];
    $fiches = count(array_filter($ids, static fn ($b) => ($b['fiche'] ?? '') !== ''));
    $non = static fn (string $r) => ['active' => false, 'raison' => $r, 'fiches' => $fiches];
    if (!GoogleApi::configure()) {
        return $non('Google n’est pas connecté au module (Paramètres → Google).');
    }
    $v = socSetting('google_verification');
    if (!is_array($v) || empty($v['valide'])) {
        return $non('La connexion Google n’est pas encore vérifiée (Paramètres → Google → Enregistrer et vérifier).');
    }
    if (empty($v['apis']['fiches']['ok'])) {
        return $non('Google n’a pas encore ouvert l’accès aux fiches (formulaire Business Profile API, docs/GOOGLE.md).');
    }
    if ($shop !== null && ($ids[$shop]['fiche'] ?? '') === '') {
        return $non('La fiche Google de cette boutique n’est pas rattachée (Paramètres → Google → rattachements).');
    }
    if ($shop === null && $fiches === 0) {
        return $non('Aucune fiche Google n’est rattachée à une boutique (Paramètres → Google → rattachements).');
    }

    return ['active' => true, 'raison' => '', 'fiches' => $fiches];
}

/**
 * Relit chez Google les avis de chaque fiche rattachée, et leurs réponses. Rend ['fiches', 'lus', 'nouveaux',
 * 'repondus', 'erreurs', 'inactif'].
 */
function socGbpAvisSync(): array
{
    $etat = socGbpEtat();
    $out = ['fiches' => 0, 'lus' => 0, 'nouveaux' => 0, 'repondus' => 0, 'erreurs' => [], 'inactif' => $etat['active'] ? null : $etat['raison']];
    if (!$etat['active']) { return $out; }
    $noms = [];
    foreach (socShops() as $s) { $noms[(string) $s['id']] = (string) $s['name']; }
    try { $bo = socGoogleBo(); } catch (\Throwable $e) { $bo = []; }
    foreach (socGapiIds()['boutiques'] as $sid => $b) {
        $sid = (string) $sid;
        $fiche = (string) ($b['fiche'] ?? '');
        if ($fiche === '') { continue; }
        $nom = $noms[$sid] ?? $sid;
        $out['fiches']++;
        $page = null; $n = 0;
        try {
            do {
                $r = GoogleApi::call('GET', SOC_GBP_API . $fiche . '/reviews', ['pageSize' => 50, 'orderBy' => 'updateTime desc'] + ($page !== null ? ['pageToken' => $page] : []));
                foreach ((array) ($r['reviews'] ?? []) as $avis) {
                    if (!is_array($avis)) { continue; }
                    $res = socGbpAvisGarder($sid, $nom, $avis, (array) (($bo['fiches'] ?? [])[$sid] ?? []));
                    if ($res === null) { continue; }
                    $out['lus']++; $n++;
                    if ($res['nouveau']) { $out['nouveaux']++; }
                    if ($res['repondu']) { $out['repondus']++; }
                }
                $page = ($r['nextPageToken'] ?? '') !== '' ? (string) $r['nextPageToken'] : null;
            } while ($page !== null && $n < SOC_GBP_MAX_PAR_FICHE);
        } catch (GoogleError $e) {
            $out['erreurs'][] = $nom . ' : ' . $e->getMessage();
        }
    }
    socSettingPut('google_gbp_relus', ['le' => date('Y-m-d H:i:s'), 'fiches' => $out['fiches'], 'lus' => $out['lus'], 'nouveaux' => $out['nouveaux'],
        'repondus' => $out['repondus'], 'erreurs' => $out['erreurs']], false);

    return $out;
}

/**
 * Un avis lu chez Google : retrouvé (par son nom Google, sinon comme le BO l'a vu) ou ajouté ; sa réponse suit.
 *
 * @return array{nouveau: bool, repondu: bool}|null
 */
function socGbpAvisGarder(string $sid, string $nom, array $avis, array $fiche): ?array
{
    $nomGoogle = (string) ($avis['name'] ?? '');
    $note = SOC_GBP_ETOILES[(string) ($avis['starRating'] ?? '')] ?? 0;
    if ($nomGoogle === '' || !preg_match('#^accounts/\d+/locations/\d+/reviews/[\w-]+$#', $nomGoogle) || $note === 0) { return null; }
    $auteur = !empty($avis['reviewer']['isAnonymous']) ? '' : trim((string) ($avis['reviewer']['displayName'] ?? ''));
    $t = strtotime((string) ($avis['createTime'] ?? '')) ?: time();
    $le = date('Y-m-d H:i:s', $t);
    $texte = (string) ($avis['comment'] ?? '');

    $row = Db::row('SELECT id, reponse_id FROM mar_sa_commentaire WHERE google_review = ?', [$nomGoogle]);
    if (!$row) {
        $ids = array_map(static fn (int $d) => socGoogleAvisId($sid, $auteur, date('Y-m-d', $t + $d * 86400), $note), [0, -1, 1]);
        $row = Db::row('SELECT id, reponse_id FROM mar_sa_commentaire WHERE id IN (?, ?, ?) AND google_review IS NULL ORDER BY FIELD(id, ?, ?, ?) LIMIT 1', array_merge($ids, $ids));
    }
    $nouveau = !$row;
    $id = $row ? (string) $row['id'] : socGoogleAvisId($sid, $auteur, $le, $note);
    if ($nouveau) {
        $lien = (string) ($fiche['url'] ?? '');
        $contexte = 'Fiche Google de L’Atelier By ' . $nom . (isset($fiche['note']) && $fiche['note'] !== null
            ? ' (note de la fiche : ' . number_format((float) $fiche['note'], 1, ',', '') . ' sur ' . (int) ($fiche['avis'] ?? 0) . ' avis)' : '');
        Db::exec('INSERT INTO mar_sa_commentaire (id, canal, objet_id, shop_id, post_id, pub_id, auteur, message, cree_le, masque, maj_le, compte, compte_nom, objet_texte, objet_lien, lien, note, vu_le, google_review)'
            . ' VALUES (?,?,?,?,NULL,NULL,?,?,?,0,?,?,?,?,?,?,?,NOW(),?)',
            [$id, 'gg', 'gg:' . $sid, $sid, mb_substr($auteur, 0, 160), mb_substr($texte, 0, 5000), $le, date('Y-m-d H:i:s'), mb_substr($sid, 0, 40),
                mb_substr('Google · ' . $nom, 0, 160), $contexte, mb_substr($lien, 0, 500), mb_substr($lien, 0, 500), $note, $nomGoogle]);
    } else {
        Db::exec('UPDATE mar_sa_commentaire SET google_review = ?, note = ?, message = IF(message IS NULL OR message = \'\', ?, message) WHERE id = ?',
            [$nomGoogle, $note, mb_substr($texte, 0, 5000), $id]);
    }

    $rep = is_array($avis['reviewReply'] ?? null) ? $avis['reviewReply'] : null;
    $source = $row['reponse_id'] ?? null;
    if ($rep !== null && trim((string) ($rep['comment'] ?? '')) !== '') {
        $quand = date('Y-m-d H:i:s', strtotime((string) ($rep['updateTime'] ?? '')) ?: time());
        // Publiée par le module : elle le reste ; écrite ailleurs (ou notée ici) : c'est la fiche qui fait foi.
        Db::exec('UPDATE mar_sa_commentaire SET reponse_envoyee = ?, reponse_le = ?, reponse_id = ?, reponse_par = IF(? = \'gbp\', reponse_par, \'sur la fiche Google\'),'
            . ' traite_le = COALESCE(traite_le, ?), traite_par = COALESCE(traite_par, \'Google\') WHERE id = ?',
            [mb_substr((string) $rep['comment'], 0, SOC_GBP_REPONSE_MAX), $quand, $source === 'gbp' ? 'gbp' : 'fiche', (string) $source, $quand, $id]);
        return ['nouveau' => $nouveau, 'repondu' => true];
    }
    if (in_array($source, ['gbp', 'fiche', 'manuel'], true)) {
        // La réponse a été retirée sur la fiche : l'avis n'a plus de réponse, il revient dans le suivi.
        Db::exec('UPDATE mar_sa_commentaire SET reponse_envoyee = NULL, reponse_le = NULL, reponse_id = NULL, reponse_par = NULL, traite_le = NULL, traite_par = NULL WHERE id = ?', [$id]);
    }

    return ['nouveau' => $nouveau, 'repondu' => false];
}

/** Publie une réponse sous un avis, sur la fiche Google de la boutique, puis marque l'avis traité. */
function socGbpRepondre(array $r, string $message, string $qui): array
{
    $etat = socGbpEtat((string) $r['shop_id']);
    if (!$etat['active']) {
        throw new HttpError(409, 'Réponse directe à Google pas encore possible : ' . $etat['raison']
            . ' En attendant : copiez la réponse, publiez-la sur la fiche, puis « J’ai répondu sur la fiche ».');
    }
    if ((string) ($r['google_review'] ?? '') === '') {
        throw new HttpError(409, 'Cet avis n’a pas encore été relu chez Google : « Relire les avis » d’abord, puis répondez.');
    }
    if (mb_strlen($message) > SOC_GBP_REPONSE_MAX) { throw new HttpError(422, 'Une réponse Google fait 4 000 caractères au plus.'); }
    try {
        $res = GoogleApi::call('PUT', SOC_GBP_API . $r['google_review'] . '/reply', [], ['comment' => $message]);
    } catch (GoogleError $e) {
        throw new HttpError($e->http === 404 ? 409 : 422, 'Google a refusé la réponse : ' . $e->getMessage()
            . ($e->http === 404 ? ' (l’avis a peut-être été supprimé par son auteur : « Relire les avis »).' : ''));
    }
    $now = date('Y-m-d H:i:s');
    Db::exec('UPDATE mar_sa_commentaire SET reponse_envoyee = ?, reponse_id = \'gbp\', reponse_le = ?, reponse_par = ?, traite_le = COALESCE(traite_le, ?), traite_par = COALESCE(traite_par, ?) WHERE id = ?',
        [(string) ($res['comment'] ?? $message), $now, mb_substr($qui, 0, 120), $now, mb_substr($qui, 0, 120), (string) $r['id']]);
    socLog($qui, 'Avis Google', 'Réponse publiée sous un avis ' . (int) $r['note'] . '★ (' . ($r['compte_nom'] ?: $r['shop_id']) . ')');

    return ['ok' => true, 'publieSur' => 'google', 'le' => substr($now, 0, 16)];
}

/** « J'ai répondu sur la fiche » : la réponse publiée à la main est gardée, l'avis est traité. */
function socAvisReponduAlaMain(string $id, string $message, ?string $qui = null): array
{
    $r = Db::row('SELECT * FROM mar_sa_commentaire WHERE id = ?', [$id]);
    if (!$r) { throw new HttpError(404, 'avis inconnu'); }
    if ($r['canal'] !== 'gg') { throw new HttpError(422, 'Seul un avis Google se répond sur sa fiche.'); }
    $message = trim(preg_replace('/[ \t]+/u', ' ', $message));
    if ($message === '' || mb_strlen($message) > SOC_GBP_REPONSE_MAX) { throw new HttpError(422, 'Collez la réponse publiée sur la fiche (4 000 caractères au plus).'); }
    $qui ??= socUser();
    $now = date('Y-m-d H:i:s');
    Db::exec('UPDATE mar_sa_commentaire SET reponse_envoyee = ?, reponse_id = \'manuel\', reponse_le = ?, reponse_par = ?, traite_le = COALESCE(traite_le, ?), traite_par = COALESCE(traite_par, ?) WHERE id = ?',
        [$message, $now, mb_substr($qui, 0, 120), $now, mb_substr($qui, 0, 120), $id]);
    socLog($qui, 'Avis Google', 'Réponse notée (publiée sur la fiche) sous un avis ' . (int) $r['note'] . '★ (' . ($r['compte_nom'] ?: $r['shop_id']) . ')');

    return ['ok' => true, 'publieSur' => 'fiche', 'le' => substr($now, 0, 16)];
}

/**
 * Une réponse proposée pour un avis : Claude quand une clé est réglée (dans la langue de l'avis, selon la charte),
 * sinon un modèle selon la note et la langue. Rien n'est publié : la réponse se relit avant.
 */
function socAvisProposer(string $id): array
{
    $r = Db::row('SELECT * FROM mar_sa_commentaire WHERE id = ?', [$id]);
    if (!$r) { throw new HttpError(404, 'avis inconnu'); }
    if ($r['canal'] !== 'gg') { throw new HttpError(422, 'Les commentaires Facebook et Instagram ont leur réponse proposée au classement.'); }
    $boutique = '';
    foreach (socShops() as $s) { if ((string) $s['id'] === (string) $r['shop_id']) { $boutique = (string) $s['name']; } }
    $note = max(1, min(5, (int) ($r['note'] ?? 5)));
    $langue = socAvisLangue((string) ($r['message'] ?? ''), (string) $r['shop_id']);
    $prenom = socAvisPrenom((string) ($r['auteur'] ?? ''));
    if (SocIa::configured()) {
        try {
            $c = socCharte();
            $system = "Tu réponds, au nom d'une boulangerie-pâtisserie franchisée (L'Atelier By " . $boutique . "), à un avis Google. "
                . 'Ton de la marque : ' . $c['ton'] . ' Règles : dans la langue de l\'avis (' . ($langue === 'nl' ? 'néerlandais' : 'français') . '), '
                . ($c['vouvoiement'] ? 'au vouvoiement, ' : '') . 'trois phrases au plus, sans hashtag, sans promesse chiffrée ni geste commercial, sans reprendre le nom complet de l\'auteur ; '
                . 'remercier pour un bon avis ; pour une déception ou une plainte, s\'excuser sans se justifier et proposer d\'en parler en message privé. '
                . ($c['signature'] !== '' ? 'Signer : ' . $c['signature'] . '. ' : '');
            $j = SocIa::json($system, json_encode(['note' => $note, 'avis' => (string) ($r['message'] ?? ''), 'prenom' => $prenom], JSON_UNESCAPED_UNICODE),
                ['type' => 'object', 'properties' => ['reponse' => ['type' => 'string']], 'required' => ['reponse'], 'additionalProperties' => false], 2000, 'low');
            $texte = trim((string) ($j['reponse'] ?? ''));
            if ($texte !== '') { return ['reponse' => mb_substr($texte, 0, SOC_GBP_REPONSE_MAX), 'source' => 'claude', 'langue' => $langue]; }
        } catch (\Throwable $e) {
            // Claude indisponible : le modèle prend le relais, l'écran le dit par `source`.
        }
    }

    return ['reponse' => socAvisModele($note, $langue, $prenom, $boutique, trim((string) ($r['message'] ?? '')) === ''), 'source' => 'modele', 'langue' => $langue];
}

/** Le prénom de l'auteur (le premier mot d'un nom), ou rien si le nom n'en a pas l'air. */
function socAvisPrenom(string $auteur): string
{
    $p = trim((string) preg_split('/\s+/u', trim($auteur))[0]);
    return preg_match('/^\p{Lu}[\p{L}\'’-]{1,24}$/u', $p) && !in_array(mb_strtolower($p), ['client', 'google', 'un', 'une'], true) ? $p : '';
}

/** La langue d'un avis : néerlandais s'il en a les mots, sinon la première langue de la boutique, sinon le français. */
function socAvisLangue(string $texte, string $shop): string
{
    $t = ' ' . mb_strtolower(preg_replace('/[^\p{L}\s]+/u', ' ', $texte)) . ' ';
    $nl = 0; $fr = 0;
    foreach (['het', 'een', 'niet', 'zeer', 'lekker', 'heel', 'goed', 'bedankt', 'vriendelijk', 'brood', 'winkel', 'zijn', 'was', 'ik', 'wij', 'maar', 'ook', 'met', 'voor', 'altijd'] as $w) { if (str_contains($t, ' ' . $w . ' ')) { $nl++; } }
    foreach (['le', 'la', 'les', 'est', 'très', 'pain', 'merci', 'pas', 'une', 'des', 'pour', 'avec', 'mais', 'aussi', 'bon', 'accueil', 'toujours', 'nous', 'je', 'et'] as $w) { if (str_contains($t, ' ' . $w . ' ')) { $fr++; } }
    if ($nl > $fr) { return 'nl'; }
    if ($fr > 0) { return 'fr'; }
    $s = socShop($shop);
    $l = $s ? (string) (((array) ($s['langs'] ?? ['fr']))[0] ?? 'fr') : 'fr';
    return $l === 'nl' ? 'nl' : 'fr';
}

/** Le modèle de réponse selon la note et la langue : une base à relire, jamais publiée telle quelle sans geste. */
function socAvisModele(int $note, string $langue, string $prenom, string $boutique, bool $sansTexte): string
{
    $b = 'L’Atelier By' . ($boutique !== '' ? ' ' . $boutique : '');
    if ($langue === 'nl') {
        $a = $prenom !== '' ? ' ' . $prenom : '';
        return match (true) {
            $note >= 5 && $sansTexte => 'Hartelijk bedankt' . $a . ' voor de vijf sterren! Het hele team van ' . $b . ' kijkt ernaar uit u snel terug te zien.',
            $note >= 5 => 'Hartelijk bedankt' . $a . ' voor deze mooie review! Het hele team van ' . $b . ' is blij dat u tevreden bent. Tot binnenkort!',
            $note === 4 => 'Bedankt' . $a . ' voor uw review! Fijn dat u een goede ervaring had bij ' . $b . '. Laat ons gerust weten wat ons die vijfde ster zou opleveren.',
            $note === 3 => 'Bedankt' . $a . ' om de tijd te nemen ons te schrijven. We nemen uw opmerkingen mee om het beter te doen; het team van ' . $b . ' hoopt u snel terug te zien.',
            default => 'Beste' . ($prenom !== '' ? ' ' . $prenom : ' klant') . ', het spijt ons dat uw bezoek aan ' . $b . ' niet naar wens was. Uw feedback is belangrijk: stuur ons gerust een privébericht, zodat we dit samen kunnen bespreken.',
        };
    }
    $a = $prenom !== '' ? ' ' . $prenom : '';
    return match (true) {
        $note >= 5 && $sansTexte => 'Merci beaucoup' . $a . ' pour ces cinq étoiles ! Toute l’équipe de ' . $b . ' a hâte de vous revoir.',
        $note >= 5 => 'Merci beaucoup' . $a . ' pour ce bel avis ! Toute l’équipe de ' . $b . ' est ravie que vous ayez apprécié. Au plaisir de vous revoir très bientôt.',
        $note === 4 => 'Merci' . $a . ' pour votre avis ! Nous sommes contents que vous ayez passé un bon moment chez ' . $b . '. N’hésitez pas à nous dire ce qui nous vaudrait la cinquième étoile.',
        $note === 3 => 'Merci' . $a . ' d’avoir pris le temps de nous écrire. Nous prenons note de vos remarques pour faire mieux ; l’équipe de ' . $b . ' espère vous revoir bientôt.',
        default => 'Bonjour' . $a . ', nous sommes désolés que votre visite chez ' . $b . ' ne vous ait pas satisfait. Votre retour compte : écrivez-nous en message privé, que nous puissions en parler et arranger les choses.',
    };
}
