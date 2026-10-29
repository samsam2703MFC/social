<?php
declare(strict_types=1);

/**
 * L'API du module : {base}/api/v1/social/{domaine}/{ressource}.
 *
 * Toutes les routes passent par la session du cockpit (src/bootstrap.php,
 * authOk) sauf /status (santé, sans secret) et /webhook (signé par Meta).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/tfb.php';
require_once __DIR__ . '/shops.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/publish.php';
require_once __DIR__ . '/ads.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/results.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/portefeuille.php';
require_once __DIR__ . '/instagram.php';
require_once __DIR__ . '/darkposts.php';
require_once __DIR__ . '/franchise.php';
require_once __DIR__ . '/metacampagnes.php';
require_once __DIR__ . '/calendriers.php';
require_once __DIR__ . '/suivi.php';
require_once __DIR__ . '/vues.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/catalogue.php';
require_once __DIR__ . '/redaction.php';
require_once __DIR__ . '/recommandations.php';
require_once __DIR__ . '/detourage.php';
require_once __DIR__ . '/commentaires.php';
require_once __DIR__ . '/google.php';
require_once __DIR__ . '/googleapi.php';
require_once __DIR__ . '/bilan.php';
require_once __DIR__ . '/kit.php';
require_once __DIR__ . '/boost.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/plan.php';
require_once __DIR__ . '/repost.php';

date_default_timezone_set('Europe/Brussels');

/** Rend [statut HTTP, corps] pour une requête. Le corps est un tableau (JSON) ou une chaîne (texte brut). */
function socRoute(string $method, string $path): array
{
    $path = '/' . trim($path, '/');

    if ($method === 'GET' && $path === '/status') {
        $db = false; $err = null;
        try { Db::pdo()->query('SELECT 1'); $db = true; } catch (Throwable $e) { $err = 'base injoignable'; }
        return [200, ['ok' => $db, 'module' => 'social', 'version' => SOCIAL_VERSION, 'db' => $db, 'dbError' => $err,
            'bo' => ['config' => isset(boConfig()['_source']), 'auth' => authEnabled()], 'meta' => $db ? Meta::statut() : null]];
    }
    // Le lien d'avis Google d'une boutique (QR code, affiche) : public, il compte le scan et renvoie vers la fiche.
    if ($method === 'GET' && preg_match('#^/avis/([\w-]{1,16})$#', $path, $m)) {
        socInstall();
        return [302, socGoogleAvisRedirige($m[1], (string) ($_GET['s'] ?? 'lien'))];
    }
    if ($path === '/webhook') {
        socInstall();
        if ($method === 'GET') { return [200, socWebhookVerif()]; }
        return [200, socWebhook((string) file_get_contents('php://input'), $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null)];
    }

    // Espace franchisé : la session du gérant vient de l'ERP (franchise_session) ou de l'aperçu du franchiseur, pas du cockpit.
    if (($r = sfRoute($method, $path)) !== null) { return $r; }

    socInstall();
    if ($method === 'GET' && $path === '/auth') {
        return [200, ['enabled' => authEnabled(), 'authed' => authOk(), 'login' => (string) (socSetting('url_bo') ?? '/consulant_bo/')]];
    }
    if (!authOk()) {
        return [401, ['error' => 'auth', 'message' => 'Connectez-vous au cockpit pour accéder au module Social & Ads.',
            'login' => (string) (socSetting('url_bo') ?? '/consulant_bo/')]];
    }

    if (($r = sfAdminRoute($method, $path)) !== null) { return $r; }
    if ($method === 'GET' && $path === '/state') { return [200, socStateGet()]; }
    if ($method === 'POST' && $path === '/state') { return [200, socStateSave(body())]; }
    if ($method === 'GET' && $path === '/shops') { return [200, ['shops' => socShops()]]; }
    if ($method === 'POST' && preg_match('#^/posts/([\w.\-]{1,64})/relancer$#', $path, $m)) {
        $p = socRetry($m[1]);
        if ($p === null) { throw new HttpError(404, 'post introuvable'); }
        return [200, ['post' => $p]];
    }
    if ($method === 'POST' && $path === '/parametres/cles') {
        // Les clés Meta, écrites en base (social_parametre). Un champ vide n'efface rien.
        $map = ['systemToken' => 'meta_system_token', 'appSecret' => 'meta_app_secret', 'verifyToken' => 'meta_verify_token', 'anthropicKey' => 'anthropic_api_key', 'removebgKey' => 'removebg_api_key'];
        if (preg_match('/^\d{5,20}$/', trim((string) (body()['appId'] ?? '')))) { socSettingPut('meta_app_id', trim((string) body()['appId']), false); }
        $faits = [];
        foreach ($map as $k => $cle) {
            $v = trim((string) (body()[$k] ?? ''));
            if ($v !== '') { socSettingPut($cle, $v, true); $faits[] = $cle; }
        }
        Meta::reset();
        Meta::verifierJeton();
        if ($faits) { socLog(socUser(), 'Connexion', 'Clés Meta mises à jour : ' . implode(', ', $faits)); }
        // Nouveau token : les identifiants des boutiques sont relus tout de suite dans le portefeuille.
        $ids = in_array('meta_system_token', $faits, true) ? socIdsAuto(true) : null;
        return [200, ['ok' => true, 'maj' => $faits, 'metaStatus' => Meta::statut(), 'ids' => $ids]];
    }
    // Google (fiches des boutiques, Google Ads, Analytics) : la connexion, les comptes trouvés, le rattachement par boutique.
    if ($method === 'GET' && $path === '/google/connexion') { return [200, socGapiEcran()]; }
    if ($method === 'POST' && $path === '/parametres/google') {
        $faits = GoogleApi::clesPut(body());
        if ($faits) { socLog(socUser(), 'Connexion', 'Clés Google mises à jour : ' . implode(', ', $faits)); }
        if (GoogleApi::configure()) { GoogleApi::verifier(); }
        return [200, ['maj' => $faits] + socGapiEcran()];
    }
    if ($method === 'POST' && $path === '/google/connexion/verifier') { GoogleApi::verifier(); return [200, socGapiEcran()]; }
    if ($method === 'POST' && $path === '/google/decouvrir') {
        if (!GoogleApi::configure()) { throw new HttpError(409, 'Connectez d’abord Google (client OAuth et jeton de rafraîchissement).'); }
        $d = socGapiDecouvrir();
        return [200, ['trouves' => ['fiches' => count($d['fiches']), 'ads' => count($d['ads']), 'ga4' => count($d['ga4'])]] + socGapiEcran()];
    }
    if ($method === 'POST' && $path === '/google/ids') { socGapiIdsPut(body()); return [200, socGapiEcran()]; }
    if ($method === 'POST' && $path === '/google/deconnecter') { GoogleApi::deconnecter(); socLog(socUser(), 'Connexion', 'Google déconnecté'); return [200, socGapiEcran()]; }
    if ($method === 'POST' && $path === '/parametres') {
        // Les réglages non secrets modifiables depuis l'écran.
        $permis = ['url_publique' => 'string', 'url_bo' => 'string', 'marketing_api' => 'string', 'webshop_url' => 'string', 'utilisateur' => 'string', 'marge' => 'float', 'catalogue' => 'array', 'instagram_unique' => 'bool', 'lien_texte' => 'array', 'lien_fiche' => 'bool', 'etiquettes_modeles' => 'etiquettes', 'charte_editoriale' => 'charte', 'ia_modele' => 'string', 'visuels_modeles' => 'visuels'];
        $faits = [];
        foreach (body() as $k => $v) {
            if (!isset($permis[$k])) { continue; }
            $v = match ($permis[$k]) { 'float' => (float) $v, 'array' => (array) $v, 'bool' => (bool) $v, 'etiquettes' => socEtiquettesModeles($v), 'charte' => socCharteNettoyer($v), 'visuels' => socVisuelsModeles($v), default => trim((string) $v) };
            socSettingPut((string) $k, $v, false); $faits[] = $k;
        }
        if ($faits) { socLog(socUser(), 'Paramètres', 'Modifiés : ' . implode(', ', $faits)); }
        return [200, ['ok' => true, 'params' => socParams()]];
    }
    if ($method === 'GET' && $path === '/meta/ids/auto') { return [200, ['config' => socIdsAutoConfig(), 'dernier' => socSetting('meta_ids_auto_dernier')]]; }
    if ($method === 'POST' && $path === '/meta/ids/auto') { return [200, ['config' => socIdsAutoPut(body()), 'dernier' => socSetting('meta_ids_auto_dernier')]]; }
    if ($method === 'POST' && $path === '/meta/ids/auto/lancer') {
        return [200, ['dernier' => socIdsAuto(true), 'config' => socIdsAutoConfig(), 'shops' => socShops(), 'brand' => socBrand(), 'portefeuille' => socSetting('meta_portefeuille')]];
    }
    // Les campagnes déjà présentes chez Meta (src/metacampagnes.php), puis l'état des pubs du module.
    // Les commentaires de toutes les Pages et de l'Instagram, classés vert / orange / rouge (src/commentaires.php).
    if ($method === 'GET' && $path === '/commentaires') { return [200, socCommentairesListe($_GET)]; }
    if ($method === 'POST' && $path === '/commentaires/relire') {
        $b = body();
        $sync = socCommentairesSync((int) ($b['jours'] ?? SOC_COM_JOURS));
        $dark = socDarkCommentsSync();
        return [200, ['sync' => $sync + ['dark' => $dark['lus'] ?? 0], 'analyse' => socCommentairesAnalyser()] + socCommentairesListe(['jours' => $b['jours'] ?? 14])];
    }
    if ($method === 'POST' && $path === '/commentaires/repondre') { $b = body(); return [200, socCommentaireRepondre((string) ($b['id'] ?? ''), (string) ($b['message'] ?? ''))]; }
    if ($method === 'POST' && $path === '/commentaires/traiter') { $b = body(); return [200, socCommentaireTraiter((string) ($b['id'] ?? ''), !empty($b['traite']))]; }
    // Les avis Google : la Réputation digitale du BO (ses tables, lues) et les avis classés (src/google.php).
    if ($method === 'GET' && $path === '/google') { return [200, socGoogleEcran($_GET)]; }
    // Le bilan des boutiques sur la semaine, le mois, le trimestre ou l'année (?periode=, src/bilan.php).
    // Le kit du mois de l'espace franchisé (src/kit.php).
    if ($method === 'GET' && $path === '/kit') { return [200, socKitVue()]; }
    if ($method === 'POST' && $path === '/kit') { return [200, socKitPut((array) (body()['items'] ?? []))]; }
    // La publicité pilotée (src/boost.php) : règle de pause, meilleurs posts à booster, demandes des boutiques.
    // Les messages privés Instagram (src/messages.php).
    if ($method === 'GET' && $path === '/messages') { return [200, socMessages($_GET)]; }
    if ($method === 'GET' && $path === '/messages/conversation') { return [200, socMsgConversation((string) ($_GET['id'] ?? ''))]; }
    if ($method === 'POST' && $path === '/messages/relire') { return [200, ['synchro' => socMessagesSync()] + socMessages((array) (body()['q'] ?? []))]; }
    if ($method === 'POST' && $path === '/messages/repondre') { $b = body(); return [200, socMsgRepondre((string) ($b['id'] ?? ''), (string) ($b['texte'] ?? ''))]; }
    if ($method === 'POST' && $path === '/messages/traiter') { $b = body(); return [200, socMsgTraiter((string) ($b['id'] ?? ''), !empty($b['traite']))]; }
    if ($method === 'POST' && $path === '/messages/attribuer') { $b = body(); return [200, socMsgAttribuer((string) ($b['id'] ?? ''), (string) ($b['shop'] ?? ''), (string) ($b['note'] ?? ''))]; }
    if ($method === 'GET' && $path === '/pub/regles') { return [200, socPubRegles()]; }
    if ($method === 'POST' && $path === '/pub/regles') { return [200, socPubReglesPut((array) (body()['regles'] ?? []))]; }
    if ($method === 'GET' && $path === '/pub/meilleurs') { return [200, socBoostMeilleurs(!empty($_GET['forcer']))]; }
    if ($method === 'GET' && $path === '/pub/demandes') { return [200, socBoostDemandes()]; }
    if ($method === 'POST' && $path === '/pub/demandes/refuser') { $b = body(); return [200, socBoostRefuser((int) ($b['id'] ?? 0), (string) ($b['reponse'] ?? ''))]; }
    // Le repost automatique (src/repost.php) : réglage, suivi des publications de la marque reprises, gestes du réseau.
    if ($method === 'GET' && $path === '/repost') { return [200, socRepostVue((int) ($_GET['jours'] ?? 14))]; }
    if ($method === 'POST' && $path === '/repost/reglage') { socRepostConfigPut(body()); return [200, socRepostVue((int) (body()['jours'] ?? 14))]; }
    if ($method === 'POST' && $path === '/repost/relire') { $g = socRepostGuet(true); return [200, ['guet' => $g] + socRepostVue((int) (body()['jours'] ?? 14))]; }
    if ($method === 'POST' && $path === '/repost/relancer') {
        $b = body();
        $n = socRepostRelancer(isset($b['source']) ? (string) $b['source'] : null, isset($b['shop']) ? (string) $b['shop'] : null);
        return [200, ['relances' => $n] + socRepostVue((int) ($b['jours'] ?? 14))];
    }
    if ($method === 'POST' && $path === '/repost/forcer') {
        $b = body();
        socRepostForcer((string) ($b['source'] ?? ''), isset($b['shop']) && $b['shop'] !== '' ? (string) $b['shop'] : null);
        return [200, socRepostVue((int) ($b['jours'] ?? 14))];
    }
    if ($method === 'POST' && $path === '/repost/retirer') {
        $b = body();
        $r = socRepostRetirer((string) ($b['source'] ?? ''));
        return [200, ['retrait' => $r] + socRepostVue((int) ($b['jours'] ?? 14))];
    }
    if ($method === 'GET' && $path === '/bilan') { return [200, socBilan(!empty($_GET['forcer']), (string) ($_GET['periode'] ?? 'semaine'))]; }
    if ($method === 'POST' && $path === '/google/reglages') { return [200, ['reglages' => socGoogleReglagesPut(body())]]; }
    if ($method === 'POST' && $path === '/google/relire') {
        $b = body();
        return [200, ['sync' => socGoogleAvisSync(), 'analyse' => socCommentairesAnalyser()] + socGoogleEcran(['jours' => $b['jours'] ?? 365])];
    }
    if ($method === 'POST' && $path === '/google/synchroniser') {
        $b = body();
        $bo = socGoogleSynchroniser();
        return [200, ['bo' => $bo, 'sync' => socGoogleAvisSync(), 'analyse' => socCommentairesAnalyser()] + socGoogleEcran(['jours' => $b['jours'] ?? 365])];
    }
    if ($method === 'GET' && $path === '/meta/comptes') { return [200, ['comptes' => socComptesMinimums(!empty($_GET['forcer']))]]; }
    if ($method === 'GET' && $path === '/meta/campagnes') { return [200, ['import' => socSetting('meta_campagnes_import'), 'comptes' => array_values(socComptesPub())]]; }
    if ($method === 'POST' && $path === '/meta/campagnes/rafraichir') {
        $r = socCampagnesMetaImporter();
        $n = socAdsSync();
        return [200, ['import' => $r, 'synchro' => $n, 'ads' => socItems('ads')]];
    }
    // Lu en direct chez Meta, rien en base : les publications des Pages et comptes Instagram (src/calendriers.php),
    // les abonnés face au budget (src/suivi.php).
    // Les opérations = campagnes commerciales du Marketing du BO, lues par son API (src/operations.php).
    if ($method === 'POST' && $path === '/operations/rattacher') {
        $b = body();
        return [200, socRattacher((string) ($b['kind'] ?? ''), (string) ($b['id'] ?? ''), isset($b['operation']) ? (string) $b['operation'] : null)];
    }
    if ($method === 'GET' && $path === '/operations') { $o = socOperations(); return [200, ['operations' => $o, 'erreur' => socOperationsErreur(), 'api' => BoMarketing::base()]]; }
    if ($method === 'GET' && $path === '/meta/calendriers') { return [200, socCalendriersLire((string) ($_GET['du'] ?? ''), (string) ($_GET['au'] ?? ''))]; }
    // Créer un post : le catalogue du webshop (catégorie → sous-catégorie → produit, gammes), prix d'UNE boutique,
    // et les promotions, bundles et formules liés à la sélection (src/catalogue.php).
    if ($method === 'GET' && $path === '/catalogue/nav') { return [200, socNavCatalogue((string) ($_GET['shop'] ?? ''), !empty($_GET['forcer']))]; }
    if ($method === 'GET' && $path === '/catalogue/offres') { return [200, socNavOffres($_GET)]; }
    if ($method === 'POST' && $path === '/catalogue/verifier') {
        // Avant de publier : le prix et la promotion du post sont-ils encore ceux de TFBuddy ?
        $ecarts = socVerifierOffres((array) body(), null, $injoignable);
        return [200, ['ecarts' => $ecarts, 'injoignable' => $injoignable]];
    }
    if ($method === 'POST' && $path === '/redaction/proposer') { return [200, socRedactionProposer(body())]; }
    // Ce qui marche (publications des 90 derniers jours, lues chez Meta, gardées 12 h) et idées de posts (src/recommandations.php).
    if ($method === 'GET' && $path === '/recommandations') { return [200, ['reco' => socRecommandations(!empty($_GET['cache']), !empty($_GET['forcer']))]]; }
    if ($method === 'GET' && $path === '/idees') { return [200, socIdees()]; }
    // Ce qui est rattaché à une campagne du Marketing (assistant de campagne : colonne « Rattaché », canaux, récap).
    if ($method === 'GET' && preg_match('#^/operations/(bo-\d{1,9})/rattache$#', $path, $m)) { return [200, socOperationRattache($m[1])]; }
    // Le plan de posts d'une campagne (src/plan.php) : se relit d'abord, puis s'ajoute au calendrier en brouillons.
    if ($method === 'GET' && $path === '/campagnes/plan') {
        $c = socPlanCreneaux(socRecommandations(true));
        return [200, socPlanOptions() + ['heure' => $c['heure'], 'creneau' => $c['creneau'], 'source' => $c['source']]];
    }
    if ($method === 'POST' && $path === '/campagnes/plan/proposer') { return [200, socPlanProposer(array_diff_key(body(), ['demain' => 1]), socRecommandations(true))]; }
    if ($method === 'POST' && $path === '/campagnes/plan/creer') {
        $b = array_diff_key(body(), ['demain' => 1]);
        $id = (string) ($b['campagne'] ?? '');
        if (!preg_match('/^bo-\d{1,9}$/', $id)) { throw new HttpError(400, 'campagne invalide'); }
        return [200, socPlanCreer($id, $b, !empty($b['remplacer']))];
    }
    if ($method === 'POST' && $path === '/redaction/tester') {
        try { $m = SocIa::modele(true); } catch (SocIaError $e) { throw new HttpError($e->http === 401 || $e->http === 403 ? 409 : ($e->http ?: 502), $e->getMessage()); }
        return [200, ['ok' => true, 'modele' => $m] + socIaStatut()];
    }
    if ($method === 'POST' && $path === '/media/detourer') { return [200, socDetourer(body())]; }
    if ($method === 'GET' && $path === '/media/webshop') { return [200, socMediaWebshop((string) ($_GET['u'] ?? ''))]; }
    // Supprimer un post : du module (et, avec meta, de Facebook / Instagram), ou une publication lue chez Meta.
    if ($method === 'POST' && $path === '/posts/supprimer') {
        $b = body();
        $id = (string) ($b['id'] ?? '');
        if (preg_match('/^m(fb|ig)-/', $id)) { return [200, socCalSupprimer($id, (string) ($b['compte'] ?? ''))]; }
        if (!preg_match('/^[\w.\-]{1,64}$/', $id)) { throw new HttpError(400, 'post invalide'); }
        return [200, socPostSupprimer($id, !empty($b['meta']))];
    }
    if ($method === 'GET' && $path === '/meta/post-stats') { return [200, socCalStats((string) ($_GET['id'] ?? ''), (string) ($_GET['compte'] ?? ''))]; }
    if ($method === 'GET' && $path === '/suivi/abonnes') { return [200, socSuiviAbonnes((int) ($_GET['jours'] ?? 28))]; }
    if ($method === 'GET' && $path === '/meta/portefeuille') { return [200, ['portefeuille' => socSetting('meta_portefeuille'), 'businessId' => (string) (socSetting('meta_business_id') ?? '')]]; }
    if ($method === 'POST' && $path === '/meta/portefeuille/rafraichir') {
        if (isset(body()['businessId'])) { socSettingPut('meta_business_id', preg_replace('/\D/', '', (string) body()['businessId']), false); }
        return [200, ['portefeuille' => socPortefeuilleRafraichir()]];
    }
    if ($method === 'POST' && $path === '/meta/portefeuille/appliquer') {
        $r = socPortefeuilleAppliquer(!empty(body()['ecraser']));
        return [200, $r + ['shops' => socShops(), 'brand' => socBrand()]];
    }
    if ($method === 'GET' && $path === '/darkposts/commentaires') {
        return [200, ['comments' => socDarkComments(isset($_GET['ad']) ? (string) $_GET['ad'] : null, isset($_GET['post']) ? (string) $_GET['post'] : null)]];
    }
    if ($method === 'POST' && $path === '/darkposts/commentaires') {
        // Relecture immédiate chez Meta (le cron le fait toutes les heures).
        $ad = isset(body()['ad']) ? (string) body()['ad'] : null;
        $r = socDarkCommentsSync($ad);
        return [200, ['synchro' => $r, 'comments' => socDarkComments($ad, isset(body()['post']) ? (string) body()['post'] : null), 'darkPosts' => socDarkPosts()]];
    }
    if ($method === 'POST' && preg_match('#^/darkposts/commentaires/([\w.\-]{1,80})/masquer$#', $path, $m)) {
        return [200, socDarkCommentMasquer($m[1], (bool) (body()['masquer'] ?? true))];
    }
    if ($method === 'GET' && $path === '/instagram/grille') {
        $sid = (string) ($_GET['shop'] ?? '');
        if (socShop($sid) === null && !($sid === 'marque' && socIgUnique())) { throw new HttpError(404, 'boutique inconnue'); }
        return [200, socIgGrille($sid)];
    }
    // À droite de la grille : les abonnés du compte jour par jour (30 jours) et les comptes touchés, lus en direct.
    if ($method === 'GET' && $path === '/instagram/abonnes') {
        $sid = (string) ($_GET['shop'] ?? '');
        $shop = $sid === 'marque' ? socBrand() : socShop($sid);
        if ($shop === null) { throw new HttpError(404, 'boutique inconnue'); }
        $ig = (string) ($shop['ids']['instagram_user_id'] ?? '');
        if ($ig === '') { return [200, ['followers' => null, 'serie' => null, 'reach' => null, 'motif' => null, 'message' => 'aucun compte Instagram rattaché']]; }
        if (!Meta::configured()) { throw new HttpError(409, 'token Meta absent : saisissez-le dans Paramètres → API Meta → Clés d’accès Meta'); }
        return [200, socIgAbonnes(['id' => $ig, 'token' => socIgToken($ig, (string) ($shop['ids']['page_id'] ?? ''))], (int) ($_GET['jours'] ?? 30)) + ['message' => null]];
    }
    if ($method === 'POST' && $path === '/instagram/grille') {
        // Relecture immédiate chez Instagram (le cron le fait toutes les heures).
        $sid = (string) (body()['shop'] ?? '');
        if (socShop($sid) === null && !($sid === 'marque' && socIgUnique())) { throw new HttpError(404, 'boutique inconnue'); }
        $r = socIgMediaSync($sid);
        return [200, socIgGrille($sid) + ['synchro' => $r[$sid] ?? ($r['erreur'] ?? 'aucun compte Instagram rattaché')]];
    }
    if ($method === 'GET' && $path === '/audit/api') { return [200, socAuditGet()]; }
    if ($method === 'POST' && $path === '/audit/api') {
        // Trois minutes environ : trop long pour une requête web (le serveur la
        // coupe). L'audit part en arrière-plan — la même commande que le cron,
        // avec son verrou — et l'écran relit GET /audit/api jusqu'au résultat.
        return [200, socAuditLancer()];
    }
    if ($method === 'GET' && $path === '/shops/api') { return [200, ['shops' => socApiShops(isset($_GET['forcer'])), 'tfb' => Tfb::statut()]]; }
    if ($method === 'POST' && $path === '/parametres/tfb') {
        // Le compte de l'API TFBuddy, en base. Un mot de passe vide ne change rien.
        $b = body(); $faits = [];
        // L'adresse de l'API reçoit les mots de passe à la connexion : seuls les hôtes tfbuddy.com sont acceptés.
        if (isset($b['base']) && preg_match('#^https://([\w\-]+\.)*tfbuddy\.com(/[\w.\-/]*)?$#', trim((string) $b['base']))) { socSettingPut('tfb_base', rtrim(trim((string) $b['base']), '/'), false); $faits[] = 'base'; }
        foreach (['admin', 'consultant'] as $r) {
            if (isset($b[$r . 'Phone'])) { socSettingPut('tfb_' . $r . '_phone', trim((string) $b[$r . 'Phone']), false); $faits[] = $r . ' (téléphone)'; }
            if (trim((string) ($b[$r . 'Password'] ?? '')) !== '') { socSettingPut('tfb_' . $r . '_password', (string) $b[$r . 'Password'], true); $faits[] = $r . ' (mot de passe)'; }
            Db::exec('DELETE FROM social_parametre WHERE cle = ?', ['tfb_jeton_' . $r]);
        }
        Tfb::reset();
        $test = [];
        foreach (['admin', 'consultant'] as $r) {
            try { Tfb::jeton($r, true); $test[$r] = 'ok'; } catch (Throwable $e) { $test[$r] = $e->getMessage(); }
        }
        if ($faits) { socLog(socUser(), 'Connexion', 'API TFBuddy : ' . implode(', ', $faits)); }
        return [200, ['ok' => true, 'test' => $test, 'tfb' => Tfb::statut()]];
    }
    if ($method === 'POST' && $path === '/connexions/test') {
        $sid = isset(body()['shop']) ? (string) body()['shop'] : null;
        $r = socTestConnexions($sid);
        $cles = socConnexionsBoutique();
        $ko = array_filter($r, static fn ($x) => (bool) array_filter($x['shop'] === 'marque' ? ['fb', 'igs', 'ads'] : $cles, static fn ($k) => $x[$k] !== 'connected'));
        socLog(socUser(), 'Connexion', 'Test des connexions Meta' . ($sid ? ' (' . ($r[0]['name'] ?? $sid) . ')' : '') . ' : ' . (count($r) - count($ko)) . ' boutique(s) sur ' . count($r) . ' entièrement connectée(s)');
        return [200, ['resultats' => $r, 'shops' => socShops()]];
    }
    if ($method === 'POST' && $path === '/sync') {
        $quoi = (string) (body()['what'] ?? 'all');
        $r = [];
        if (in_array($quoi, ['all', 'queue'], true)) { $r['queue'] = count(socRunQueue()); }
        if (in_array($quoi, ['all', 'ads'], true)) { $r['ads'] = socAdsSync(); }
        if (in_array($quoi, ['all', 'leads'], true)) { $r['leads'] = socLeadsSync(); }
        if (in_array($quoi, ['all', 'results'], true)) { $r['results'] = socResultsSync(isset(body()['campaign']) ? (string) body()['campaign'] : null); }
        return [200, ['ok' => true] + $r];
    }
    throw new HttpError(404, 'route inconnue : ' . $method . ' ' . $path);
}

/** Exécute une requête HTTP réelle et écrit la réponse. */
function socHandle(string $method, string $path): void
{
    try {
        [$code, $body] = socRoute($method, $path);
    } catch (HttpError $e) {
        [$code, $body] = [$e->status, ['error' => $e->getMessage()]];
    } catch (PDOException $e) {
        error_log('[social] ' . $e->getMessage());
        [$code, $body] = [503, ['error' => 'base de données indisponible — vérifiez le config du BO (' . boDir() . '/config/config.php)']];
    } catch (Throwable $e) {
        error_log('[social] ' . $e);
        [$code, $body] = [500, ['error' => 'erreur interne : ' . $e->getMessage()]];
    }
    http_response_code($code);
    if ($body instanceof SocRedirection) {
        header('Location: ' . $body->url);
        header('Cache-Control: no-store');
        return;
    }
    if ($body instanceof SocFichier) {
        // Une image du catalogue : même origine que l'écran, gardée un jour par le navigateur.
        header('Content-Type: ' . $body->type);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($body->octets));
        echo $body->octets;
        return;
    }
    header('Cache-Control: no-store');
    if (is_string($body)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
