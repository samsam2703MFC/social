// Franchise Buddy · Social & Ads — couche de données et helpers partagés par les écrans.
//
// Deux modes :
//  - RÉEL (par défaut) : l'état se lit au serveur du module, {base}/api/v1/social/state,
//    qui partage la base, les accès Meta et la session du BO consultant. Chaque
//    SA.save() n'envoie que ce qui a changé ; le serveur publie, programme, crée les
//    pubs et les formulaires chez Meta, et rend les statuts tels que Meta les donne.
//  - DÉMONSTRATION : si l'API ne répond pas (ou ?demo=1), les données d'exemple
//    ci-dessous, conservées dans le navigateur — un bandeau le dit à l'écran.
(function () {
  // Les écrans et la nav chargent ce fichier chacun : une seule instance.
  if (window.SA && window.SA.__ready) return;
  let TODAY = '2026-10-10';
  const KEY = 'sa.state.v1';

  const SHOPS = [
    { id: 'halle', name: 'Halle', langs: ['nl', 'fr'], address: 'Basiliekstraat 12, 1500 Halle', hours: '7h00–18h30', hoursNl: '7u00–18u30', page: "L'Atelier By Halle", ig: '@latelierby.halle', fb: 'connected', igs: 'connected', ads: 'connected' },
    { id: 'corbais', name: 'Corbais', langs: ['fr'], address: 'Rue de la Station 4, 1435 Corbais', hours: '6h30–18h00', page: "L'Atelier By Corbais", ig: '@latelierby.corbais', fb: 'connected', igs: 'connected', ads: 'connected' },
    { id: 'gosselies', name: 'Gosselies', langs: ['fr'], address: 'Rue Circulaire 88, 6041 Gosselies', hours: '7h00–19h00', page: "L'Atelier By Gosselies", ig: '@latelierby.gosselies', fb: 'connected', igs: 'expired', ads: 'connected' },
    { id: 'sombreffe', name: 'Sombreffe', langs: ['fr'], address: 'Route de Gembloux 21, 5140 Sombreffe', hours: '7h00–18h00', page: "L'Atelier By Sombreffe", ig: '@latelierby.sombreffe', fb: 'connected', igs: 'connected', ads: 'none' },
    { id: 'gembloux', name: 'Gembloux', langs: ['fr'], address: 'Avenue de la Faculté 9, 5030 Gembloux', hours: '6h30–19h00', page: "L'Atelier By Gembloux", ig: '@latelierby.gembloux', fb: 'connected', igs: 'connected', ads: 'connected' },
    { id: 'wavre', name: 'Wavre', langs: ['fr'], address: 'Place Bosch 3, 1300 Wavre', hours: '7h00–19h00', page: "L'Atelier By Wavre", ig: '@latelierby.wavre', fb: 'connected', igs: 'connected', ads: 'connected' },
  ];
  // Magasins test (boutiques hors caisse, ex. Gembloux) : hors du réseau — jamais dans « toutes les boutiques »,
  // les rapports, les compteurs ni l'espace franchisé ; ciblés seulement quand on les coche (Créer un post).
  const TEST_SHOPS = [];

  const GROUPS = [
    { id: 'bw', name: 'Brabant wallon', shops: ['corbais', 'wavre'] },
    { id: 'namur', name: 'Province de Namur', shops: ['sombreffe', 'gembloux'] },
    { id: 'hainaut', name: 'Hainaut', shops: ['gosselies'] },
    { id: 'fl', name: 'Flandre', shops: ['halle'], lang: 'nl' },
  ];

  const LEVERS = {
    trafic: { name: 'Trafic', color: '#6366f1' }, recurrence: { name: 'Récurrence', color: '#ec4899' },
    xp: { name: 'Expérience Client', color: '#f59e0b' }, 'food-cost': { name: 'Food Cost', color: '#10b981' },
    'labour-cost': { name: 'Labour Cost', color: '#8b5cf6' }, 'overhead-cost': { name: 'Overhead Cost', color: '#8b5cf6' },
  };

  const CTYPES = [
    { id: 'ouverture', name: 'Ouverture de boutique', color: '#8D1D2C', icon: 'img/illus/customer-facing/click-collect.png', lev: 'trafic', kpi: 'Tickets / jour' },
    { id: 'lancement', name: 'Lancement / nouveauté', color: '#A61E4D', icon: 'img/illus/products/croissant.png', lev: 'trafic', kpi: 'Quantités vendues du produit' },
    { id: 'saison', name: 'Saisonnalité', color: '#D97B29', icon: 'img/illus/seasonal/autumn-range.png', lev: 'trafic', kpi: 'CA de la gamme' },
    { id: 'fetes', name: 'Fêtes', color: '#7B1424', icon: 'img/illus/seasonal/christmas-new-year-range.png', lev: 'trafic', kpi: 'CA commandes fêtes' },
    { id: 'event', name: 'Événementiel local', color: '#78554B', icon: 'img/illus/seasonal/crepe-festival.png', lev: 'xp', kpi: 'Fréquentation boutique' },
    { id: 'antigaspi', name: 'Anti-gaspi / invendus', color: '#6B4A26', icon: 'img/illus/products/bread-3.png', lev: 'food-cost', kpi: "Taux d'invendus" },
    { id: 'acquisition', name: 'Acquisition', color: '#3B3468', icon: 'img/illus/customer-facing/webshop.png', lev: 'trafic', kpi: 'Nouveaux clients' },
    { id: 'retention', name: 'Rétention / réactivation', color: '#5C3A6E', icon: 'img/illus/products/cookies.png', lev: 'recurrence', kpi: 'Fréquence de visite' },
    { id: 'partenariat', name: 'Partenariat local', color: '#9C6B2F', icon: 'img/illus/customer-facing/b2b.png', lev: 'recurrence', kpi: 'CA B2B' },
    { id: 'continu', name: 'Présence continue', color: '#666666', icon: 'img/illus/decorative/circle-01.png', lev: 'xp', kpi: 'Engagement moyen', continuous: true },
  ];

  const POST_TYPES = ['Produit', 'Gamme saisonnière', 'Vidéo / film', 'Événement local', 'Anti-gaspi', 'Recrutement', 'Libre'];

  const CATALOGUE = [
    { id: 'brioche', apiId: 1410006, categoryId: 14100, webshop: true, kind: 'produit', name: 'Brioche croustillante', nameNl: 'Krokante brioche', price: '4,20', img: 'img/products/brioche-croustillante.png', gamme: 'Gamme Automne' },
    { id: 'tarte-pomme', kind: 'produit', name: 'Tarte aux pommes', nameNl: 'Appeltaart', price: '18,50', img: 'img/products/sweet-tart-big.png', gamme: 'Gamme Automne' },
    { id: 'tartelette', kind: 'produit', name: 'Tartelette poire-amande', nameNl: 'Peer-amandeltaartje', price: '3,60', img: 'img/products/sweet-tart-small.png', gamme: 'Gamme Automne' },
    { id: 'croissant', kind: 'produit', name: 'Croissant pur beurre', nameNl: 'Botercroissant', price: '1,30', img: 'img/products/croissant.png', gamme: 'Viennoiseries' },
    { id: 'levain', kind: 'produit', name: 'Pain au levain', nameNl: 'Zuurdesembrood', price: '4,10', img: 'img/products/bread-1.png', gamme: 'Pains' },
    { id: 'cookies', kind: 'produit', name: 'Cookies du goûter', nameNl: 'Vieruurtjescookies', price: '2,20', img: 'img/products/cookies.png', gamme: 'Goûter malin' },
    { id: 'cupcake', kind: 'produit', name: 'Cupcake vanille', nameNl: 'Vanillecupcake', price: '3,40', img: 'img/products/cupcake.png', gamme: 'Goûter malin' },
    { id: 'limonade', kind: 'produit', name: 'Limonade maison', nameNl: 'Huisgemaakte limonade', price: '3,20', img: 'img/products/lemonade-soda.png', gamme: 'Boissons' },
    { id: 'quiche', kind: 'produit', name: 'Quiche du jour', nameNl: 'Quiche van de dag', price: '5,90', img: 'img/products/savoury-tart.png', gamme: 'Salé' },
    { id: 'g-automne', kind: 'gamme', name: 'Gamme Automne', nameNl: 'Herfstgamma', price: 'dès 3,60', img: 'img/illus/seasonal/autumn-range.png' },
    { id: 'g-gouter', kind: 'gamme', name: 'Goûter malin', nameNl: 'Slim vieruurtje', price: 'dès 2,20', img: 'img/illus/products/cookies.png' },
    { id: 'g-noel', kind: 'gamme', name: 'Noël & Nouvel An', nameNl: 'Kerst & Nieuwjaar', price: 'dès 4,50', img: 'img/illus/seasonal/christmas-new-year-range.png' },
  ];

  let MARGIN = 0.62;

  // Démo : les opérations sont les campagnes commerciales du Marketing du BO (en réel : GET /operations, lecture seule).
  const BO_OP = (id, typeLabel, boStatus, boStatusCode) => ({ boId: id, source: 'bo', typeLabel, boStatus, boStatusCode, lien: '/consulant_bo/assistant/?id=' + id });
  const CAMPAIGNS = [
    { id: 'bo-12', ...BO_OP(12, 'Saisonnalité', 'En cours', 'running'), name: 'Gamme Automne 2026', type: 'saison', start: '2026-09-28', end: '2026-10-25', status: 'en cours',
      shops: SHOPS.map(s => s.id), products: ['Brioche croustillante', 'Tarte aux pommes', 'Tartelette poire-amande'],
      goal: { metric: 'CA additionnel', value: 12000 }, budget: 2400,
      split: { halle: 450, corbais: 350, gosselies: 400, sombreffe: 300, gembloux: 400, wavre: 500 } },
    { id: 'bo-11', ...BO_OP(11, 'Acquisition', 'Terminée', 'done'), name: 'Rentrée · Goûter malin', type: 'acquisition', start: '2026-08-24', end: '2026-09-13', status: 'terminée',
      shops: SHOPS.map(s => s.id), products: ['Cookies du goûter', 'Cupcake vanille'],
      goal: { metric: 'CA additionnel', value: 6000 }, budget: 1500,
      split: { halle: 260, corbais: 220, gosselies: 250, sombreffe: 190, gembloux: 260, wavre: 320 } },
    { id: 'bo-10', ...BO_OP(10, 'Lancement / nouveauté', 'Terminée', 'done'), name: 'Lancement Limonade maison', type: 'lancement', start: '2026-06-15', end: '2026-07-12', status: 'terminée',
      shops: SHOPS.map(s => s.id), products: ['Limonade maison'],
      goal: { metric: 'CA additionnel', value: 7000 }, budget: 1800,
      split: { halle: 330, corbais: 280, gosselies: 310, sombreffe: 240, gembloux: 300, wavre: 340 } },
  ];

  // Résultats par campagne × boutique. Meta : spend, reach, impr, clicks, eng, vv. Ventes : CA / tickets / qté — campagne vs référence (moy. mêmes jours des 6 semaines précédentes).
  const R = (spend, reach, impr, clicks, eng, vv, caRef, caCamp, tRef, tCamp, qRef, qCamp) => ({ spend, reach, impr, clicks, eng, vv, caRef, caCamp, tRef, tCamp, qRef, qCamp });
  const RESULTS = {
    'bo-12': {
      halle: R(190, 12100, 33800, 720, 1540, 4100, 3520, 4390, 830, 1010, 420, 640),
      corbais: R(150, 9800, 27100, 560, 1190, 3200, 2870, 3420, 690, 800, 340, 500),
      gosselies: R(175, 10200, 28800, 590, 1220, 3300, 3210, 3640, 760, 850, 380, 500),
      sombreffe: R(130, 7900, 21900, 450, 920, 2600, 2240, 2470, 540, 590, 270, 330),
      gembloux: R(185, 11800, 32700, 700, 1480, 3900, 3300, 4120, 790, 960, 400, 600),
      wavre: R(230, 14900, 41500, 900, 1910, 5100, 3960, 5020, 940, 1150, 470, 700),
    },
    'bo-11': {
      halle: R(260, 18400, 52100, 1120, 2310, 6400, 4820, 6610, 1140, 1530, 610, 980),
      corbais: R(220, 14900, 41800, 870, 1760, 5100, 3910, 5420, 960, 1290, 480, 790),
      gosselies: R(250, 17100, 49200, 990, 2040, 5900, 4400, 5790, 1050, 1370, 560, 860),
      sombreffe: R(180, 11200, 31500, 610, 1190, 3500, 2950, 3560, 720, 860, 360, 470),
      gembloux: R(260, 16800, 47700, 1010, 2120, 6100, 4510, 6420, 1080, 1490, 590, 940),
      wavre: R(310, 21400, 60100, 1290, 2650, 7400, 5230, 7330, 1250, 1720, 680, 1090),
    },
    'bo-10': {
      halle: R(330, 21000, 60200, 1310, 2400, 9800, 5100, 5560, 1210, 1290, 300, 520),
      corbais: R(280, 16200, 46000, 990, 1810, 7600, 4020, 4300, 980, 1030, 240, 410),
      gosselies: R(300, 18800, 53500, 1100, 2010, 8400, 4580, 4950, 1090, 1160, 270, 450),
      sombreffe: Object.assign(R(230, 12900, 36800, 720, 1300, 5600, null, null, null, null, null, null), { noSales: 'Caisse GoPOS en migration du 10/06 au 20/07 — aucune vente remontée' }),
      gembloux: R(290, 17900, 51000, 1070, 1980, 8100, 4610, 5080, 1100, 1190, 280, 480),
      wavre: R(330, 22300, 63100, 1420, 2600, 10300, 5300, 5820, 1260, 1370, 320, 560),
    },
  };

  const ALL = SHOPS.map(s => s.id);
  const P = (id, date, time, type, title, img, shops, status, extra) => Object.assign({ id, date, time, type, title, img, shops, fb: true, ig: true, status, campaign: null, text: '' }, extra || {});
  const POSTS = [
    P('p01', '2026-09-28', '08:00', 'Gamme saisonnière', 'La gamme Automne est arrivée', 'img/illus/seasonal/autumn-range.png', ALL, 'Publié', { campaign: 'bo-12', text: "La gamme Automne est arrivée chez L'Atelier By {ville}. Brioche croustillante, tarte aux pommes et tartelette poire-amande, à découvrir dès ce matin." }),
    P('p02', '2026-10-01', '07:30', 'Produit', 'Brioche croustillante', 'img/products/brioche-croustillante.png', ALL, 'Publié', { campaign: 'bo-12', text: 'Brioche filée, cœur de myrtille et crumble citronné. {prix} € la pièce à {ville}.' }),
    P('p03', '2026-10-02', '11:00', 'Recrutement', 'On recrute à Wavre', 'img/illus/customer-facing/b2b.png', ['wavre'], 'Publié', { text: "L'Atelier By Wavre recrute un·e vendeur·se à temps partiel. Passez nous voir au {adresse}." }),
    P('p04', '2026-10-03', '16:30', 'Anti-gaspi', 'Panier anti-gaspi du samedi', 'img/products/bread-3.png', ['corbais', 'gembloux'], 'Publié', { text: 'Dès 17h, nos invendus du jour en panier surprise à 5 €.' }),
    P('p05', '2026-10-04', '09:00', 'Événement local', 'Marché de Halle', 'img/illus/seasonal/crepe-festival.png', ['halle'], 'Publié', { text: 'Retrouvez-nous au marché de Halle ce dimanche.' }),
    P('p06', '2026-10-05', '08:00', 'Produit', 'Tarte aux pommes', 'img/products/sweet-tart-big.png', ALL, 'Publié', { campaign: 'bo-12', partial: { ok: ['halle', 'corbais', 'sombreffe', 'gembloux', 'wavre'], ko: [{ shop: 'gosselies', channel: 'Instagram', reason: 'Token Instagram expiré (code 190)' }] } }),
    P('p07', '2026-10-07', '07:30', 'Vidéo / film', 'Film · le geste du boulanger', 'img/illus/products/bread-2.png', ALL, 'Publié', { campaign: 'bo-12' }),
    P('p08', '2026-10-08', '12:00', 'Produit', 'Quiche du jour', 'img/products/savoury-tart.png', ['gosselies', 'sombreffe'], 'Échec', { error: 'Meta a refusé la publication : la Page Sombreffe a restreint les publications programmées (code 368).', failedShops: ['sombreffe'] }),
    P('p09', '2026-10-09', '07:30', 'Produit', 'Tartelette poire-amande', 'img/products/sweet-tart-small.png', ALL, 'Publié', { campaign: 'bo-12', partial: { ok: ['halle', 'corbais', 'gembloux', 'wavre'], ko: [{ shop: 'gosselies', channel: 'Instagram', reason: 'Token Instagram expiré (code 190)' }, { shop: 'sombreffe', channel: 'Facebook', reason: 'Délai dépassé côté Graph API (code 2) — relance possible' }] } }),
    P('p10', '2026-10-10', '08:00', 'Gamme saisonnière', 'Le week-end gourmand d\u2019automne', 'img/illus/fruits/apple.png', ALL, 'Publié', { campaign: 'bo-12' }),
    P('p11', '2026-10-11', '09:30', 'Événement local', 'Brunch du dimanche à Corbais', 'img/illus/products/cake-slice.png', ['corbais'], 'Programmé'),
    P('p12', '2026-10-12', '07:30', 'Produit', 'Croissant pur beurre', 'img/products/croissant.png', ['halle', 'wavre'], 'Programmé'),
    P('p13', '2026-10-13', '16:00', 'Anti-gaspi', 'Panier anti-gaspi', 'img/products/bread-4.png', ['gosselies'], 'À valider', { author: 'Manager Gosselies' }),
    P('p14', '2026-10-14', '11:00', 'Libre', 'Les coulisses de l\u2019atelier', 'img/illus/decorative/artboard-4.png', ['wavre'], 'Brouillon'),
    P('p15', '2026-10-15', '07:30', 'Produit', 'Pain au levain', 'img/products/bread-1.png', ['halle', 'corbais'], 'Programmé'),
    P('p16', '2026-10-16', '12:00', 'Événement local', 'Dégustation au marché de Wavre', 'img/illus/products/rolls.png', ['wavre'], 'À valider', { author: 'Manager Wavre' }),
    P('p17', '2026-10-18', '08:00', 'Produit', 'Brioche croustillante · J-7', 'img/products/brioche-croustillante.png', ALL, 'Programmé', { campaign: 'bo-12' }),
    P('p18', '2026-10-20', '07:30', 'Vidéo / film', 'Film · tarte aux pommes', 'img/products/sweet-tart-big.png', ALL, 'Programmé', { campaign: 'bo-12' }),
    P('p19', '2026-10-22', '11:00', 'Recrutement', 'On recrute à Gembloux', 'img/illus/customer-facing/office-delivery.png', ['gembloux'], 'Brouillon'),
    P('p20', '2026-10-24', '08:00', 'Gamme saisonnière', 'Derniers jours de la gamme Automne', 'img/illus/seasonal/autumn-range.png', ALL, 'Programmé', { campaign: 'bo-12' }),
    P('p21', '2026-10-27', '09:00', 'Libre', 'Halloween : biscuits citrouille', 'img/products/pumpkin.png', ALL, 'Brouillon'),
    P('p22', '2026-10-30', '16:30', 'Anti-gaspi', 'Panier anti-gaspi', 'img/products/bread-5.png', ['corbais', 'gembloux', 'sombreffe'], 'Programmé'),
    P('p23', '2026-08-24', '08:00', 'Gamme saisonnière', 'C\u2019est la rentrée du goûter', 'img/products/cookies.png', ALL, 'Publié', { campaign: 'bo-11' }),
    P('p24', '2026-09-01', '15:30', 'Produit', 'Cupcake vanille', 'img/products/cupcake.png', ALL, 'Publié', { campaign: 'bo-11' }),
    P('p25', '2026-09-08', '15:30', 'Vidéo / film', 'Film · goûter malin', 'img/products/cookies.png', ALL, 'Publié', { campaign: 'bo-11' }),
    P('p26', '2026-06-15', '10:00', 'Produit', 'Nouveau : limonade maison', 'img/products/lemonade-soda.png', ALL, 'Publié', { campaign: 'bo-10' }),
    P('p27', '2026-06-29', '12:00', 'Vidéo / film', 'Film · limonade', 'img/products/lemonade-soda.png', ALL, 'Publié', { campaign: 'bo-10' }),
  ];

  const ADS = [
    { id: 'a01', name: 'Automne · trafic magasin', campaign: 'bo-12', objective: 'Trafic en magasin', shops: ALL, scope: 'boutique', budgetType: 'total', budget: 1600, cap: 1800, spent: 760, start: '2026-09-28', end: '2026-10-25', status: 'Active', radius: 8, post: 'p02' },
    { id: 'a02', name: 'Automne · vidéo notoriété', campaign: 'bo-12', objective: 'Notoriété locale', shops: ALL, scope: 'reseau', budgetType: 'quotidien', budget: 25, cap: 700, spent: 300, start: '2026-10-07', end: '2026-10-25', status: 'Active', radius: 12, post: 'p07' },
    { id: 'a03', name: 'Automne · relance J-7', campaign: 'bo-12', objective: 'Trafic en magasin', shops: ALL, scope: 'boutique', budgetType: 'total', budget: 400, cap: 400, spent: 0, start: '2026-10-18', end: '2026-10-25', status: 'En revue', radius: 8, post: 'p17' },
    { id: 'a04', name: 'Quiche du jour · midi', campaign: null, objective: 'Messages', shops: ['gosselies'], scope: 'boutique', budgetType: 'quotidien', budget: 8, cap: 120, spent: 0, start: '2026-10-08', end: '2026-10-22', status: 'Refusée', reason: "Texte de l'image supérieur à 20 % et promesse de prix non vérifiable (politique Publicités Meta 4.3).", radius: 5, post: 'p08' },
    { id: 'a05', name: 'Recrutement Wavre', campaign: null, objective: 'Notoriété locale', shops: ['wavre'], scope: 'boutique', budgetType: 'total', budget: 150, cap: 150, spent: 150, start: '2026-10-02', end: '2026-10-12', status: 'Budget épuisé', radius: 15, post: 'p03' },
    { id: 'a06', name: 'Goûter malin · trafic', campaign: 'bo-11', objective: 'Trafic en magasin', shops: ALL, scope: 'boutique', budgetType: 'total', budget: 1500, cap: 1500, spent: 1480, start: '2026-08-24', end: '2026-09-13', status: 'Terminée', radius: 8, post: 'p23' },
    { id: 'a07', name: 'Limonade · lancement', campaign: 'bo-10', objective: 'Visites du webshop', shops: ALL, scope: 'reseau', budgetType: 'total', budget: 1800, cap: 1800, spent: 1760, start: '2026-06-15', end: '2026-07-12', status: 'Terminée', radius: 10, post: 'p26' },
    // Campagnes créées hors du module (Gestionnaire de publicités) et récupérées chez Meta.
    { id: 'meta-120211', origin: 'meta', metaId: '120211', account: 'act_1428801', accountName: 'Atelier By Halle', devise: 'EUR', name: 'Boost · Marché de Halle', objective: 'Interactions', metaObjective: 'OUTCOME_ENGAGEMENT',
      campaign: null, shops: ['halle'], scope: 'boutique', budgetType: 'quotidien', budget: 6, cap: null, spent: 38.4, start: '2026-10-03', end: null, status: 'Active', radius: 10, post: null, nAds: 1, nAdsets: 1,
      thumb: 'img/illus/seasonal/crepe-festival.png', insights: { impressions: 9200, reach: 6100, clicks: 210, ctr: 0.0228, cpc: 0.18, cpm: 4.17, frequency: 1.51, engagement: 640 },
      lienMeta: 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=1428801&selected_campaign_ids=120211', syncedAt: '2026-10-12 07:37', importedAt: '2026-10-03 09:12' },
    { id: 'meta-120198', origin: 'meta', metaId: '120198', account: 'act_1428800', accountName: 'Atelier By réseau', devise: 'EUR', name: 'Notoriété réseau · printemps', objective: 'Notoriété', metaObjective: 'OUTCOME_AWARENESS',
      campaign: null, shops: ALL, scope: 'reseau', budgetType: 'total', budget: 900, cap: 900, spent: 884.2, start: '2026-04-01', end: '2026-05-15', status: 'Terminée', radius: null, post: null, nAds: 4, nAdsets: 2,
      thumb: 'img/illus/seasonal/autumn-range.png', insights: { impressions: 212000, reach: 98500, clicks: 2140, ctr: 0.0101, cpc: 0.41, cpm: 4.17, frequency: 2.15, engagement: 5300, videoViews: 18400 },
      lienMeta: 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=1428800&selected_campaign_ids=120198', syncedAt: '2026-10-12 07:37', importedAt: '2026-10-01 09:12' },
  ];
  // Le dernier passage de « Récupérer les campagnes Meta » (en réel : GET /state → metaImport).
  let IMPORT = { le: '2026-10-12 07:37', comptes: [{ id: 'act_1428800', name: 'Atelier By réseau', devise: 'EUR', statut: 1, campagnes: 1, erreur: null }, { id: 'act_1428801', name: 'Atelier By Halle', devise: 'EUR', statut: 1, campagnes: 1, erreur: null }],
    importees: 0, majs: 2, disparues: 0, total: 2, erreurs: [] };

  const ACTIVITY = [
    { at: '2026-10-10 08:00', who: 'Système', what: 'Publication', detail: '« Le week-end gourmand d\u2019automne » publié sur 6 Pages Facebook et 5 comptes Instagram' },
    { at: '2026-10-09 18:42', who: 'Sarah Lambert (Franchiseur)', what: 'Validation', detail: '« Brunch du dimanche à Corbais » approuvé et programmé le 11/10 à 09:30' },
    { at: '2026-10-09 07:31', who: 'Système', what: 'Échec partiel', detail: '« Tartelette poire-amande » : 4 Pages sur 6 — Gosselies (Instagram, token expiré), Sombreffe (délai Graph API)' },
    { at: '2026-10-08 12:00', who: 'Système', what: 'Échec', detail: '« Quiche du jour » refusé sur la Page Sombreffe (code 368)' },
    { at: '2026-10-08 09:15', who: 'Agence Pâte Brisée', what: 'Création', detail: 'Pub « Automne · relance J-7 » soumise à Meta' },
    { at: '2026-10-07 16:04', who: 'Manager Gosselies', what: 'Proposition', detail: '« Panier anti-gaspi » soumis à validation' },
    { at: '2026-10-06 10:20', who: 'Système', what: 'Connexion', detail: 'Token Instagram de Gosselies expiré — reconnexion requise' },
    { at: '2026-10-02 11:00', who: 'Sarah Lambert (Franchiseur)', what: 'Publication', detail: '« On recrute à Wavre » publié sur la Page Wavre' },
  ];

  const TEMPLATES = [
    { id: 't1', name: 'Produit du jour', type: 'Produit', text: 'Ce matin à {ville} : {produit_du_jour}, {prix} €. Ouvert {horaires}.', textNl: 'Vanochtend in {ville}: {produit_du_jour}, {prix} €. Open {horaires}.' },
    { id: 't2', name: 'Panier anti-gaspi', type: 'Anti-gaspi', text: 'Dès 17h, nos invendus en panier surprise à 5 € chez L\u2019Atelier By {ville}, {adresse}.', textNl: 'Vanaf 17u: onze overschotten in een verrassingsmand voor 5 € bij L\u2019Atelier By {ville}, {adresse}.' },
    { id: 't3', name: 'Recrutement', type: 'Recrutement', text: 'L\u2019Atelier By {ville} recrute ! Envoyez votre CV ou passez nous voir au {adresse}.' , textNl: 'L\u2019Atelier By {ville} zoekt versterking! Stuur je cv of kom langs op {adresse}.' },
    { id: 't4', name: 'Nouvelle gamme', type: 'Gamme saisonnière', text: 'La nouvelle gamme est arrivée à {ville}. À découvrir {horaires}.' , textNl: 'Het nieuwe gamma is er in {ville}. Ontdek het {horaires}.' },
  ];

  const ROLES = [
    { id: 'franchiseur', name: 'Franchiseur (admin réseau)', rights: { create: 'Tout le réseau', validate: true, ads: true, reporting: 'Complet', sales: true, settings: true } },
    { id: 'franchise', name: 'Franchisé / manager boutique', rights: { create: 'Sa boutique (soumis à validation)', validate: false, ads: false, reporting: 'Sa boutique', sales: true, settings: false } },
    { id: 'agence', name: 'Agence partenaire', rights: { create: 'Tout le réseau', validate: false, ads: true, reporting: 'Meta uniquement', sales: false, settings: false } },
  ];

  // ---------- helpers ----------
  // Un magasin test se retrouve aussi par son identifiant dans la liste du réseau (noms des posts, journal…).
  const byId = (arr, id) => arr.find(x => x.id === id) || (arr === SHOPS ? TEST_SHOPS.find(x => x.id === id) : undefined);
  const fmt = (n, d = 0) => n == null || !isFinite(n) ? '—' : n.toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const eur = (n, d = 0) => n == null || !isFinite(n) ? '—' : fmt(n, d) + ' €';
  // Un montant dans la devise d'un compte publicitaire (Meta compte dans la devise du compte, pas forcément en euros).
  const devSym = (dev) => !dev || dev === 'EUR' ? '€' : dev;
  const argent = (n, dev, d = 0) => !dev || dev === 'EUR' ? eur(n, d) : n == null || !isFinite(n) ? '—' : fmt(n, d) + ' ' + dev;
  const pct = (n, d = 0) => n == null || !isFinite(n) ? '—' : fmt(n * 100, d) + ' %';
  // Une date absente ou illisible (objet incomplet en base) ne doit pas faire tomber un écran : « — ».
  const d2 = (s) => { const [y, m, d] = String(s || TODAY).split('-').map(Number); return new Date(y, (m || 1) - 1, d || 1); };
  const okDate = (s) => /^\d{4}-\d{2}-\d{2}/.test(String(s || ''));
  const iso = (dt) => dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
  const addDays = (s, n) => { const dt = d2(s); dt.setDate(dt.getDate() + n); return iso(dt); };
  const daysBetween = (a, b) => Math.round((d2(b) - d2(a)) / 86400000);
  const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  const dLabel = (s) => { if (!okDate(s)) return '—'; const dt = d2(s); return DAYS[dt.getDay()] + ' ' + dt.getDate() + ' ' + MONTHS[dt.getMonth()].slice(0, 4).replace(/\.$/, '') + (MONTHS[dt.getMonth()].length > 4 ? '.' : ''); };
  const dShort = (s) => { if (!okDate(s)) return '—'; const dt = d2(s); return dt.getDate() + '/' + String(dt.getMonth() + 1).padStart(2, '0'); };

  const STATUS = {
    'Brouillon': { bg: '#F4EFE8', fg: '#555555' },
    'À valider': { bg: '#FAC775', fg: '#6b3d0a' },
    'Programmé': { bg: '#F2C9A0', fg: '#6b4420' },
    'Publié': { bg: '#E2F0E8', fg: '#0F6B4A' },
    'Échec': { bg: '#8D1D2C', fg: '#FFFFFF' },
    'Partiel': { bg: '#F6DCDF', fg: '#8D1D2C' },
    'Dark post': { bg: '#232220', fg: '#F2C9A0' },
  };
  const AD_STATUS = {
    'En revue': { bg: '#FAC775', fg: '#6b3d0a' }, 'Active': { bg: '#E2F0E8', fg: '#0F6B4A' }, 'Refusée': { bg: '#8D1D2C', fg: '#FFFFFF' },
    'En pause': { bg: '#F4EFE8', fg: '#555555' }, 'Arrêtée': { bg: '#EAE4DC', fg: '#555555' }, 'Budget épuisé': { bg: '#F6DCDF', fg: '#8D1D2C' }, 'Terminée': { bg: '#EAE4DC', fg: '#555555' },
  };
  const CONN = { connected: { label: 'Connecté', bg: '#E2F0E8', fg: '#0F6B4A' }, expired: { label: 'Token expiré', bg: '#FAC775', fg: '#6b3d0a' }, none: { label: 'Non connecté', bg: '#F6DCDF', fg: '#8D1D2C' } };
  const RAG = { green: { label: 'Objectif atteint', bg: '#E2F0E8', fg: '#0F6B4A', bar: '#1F8A60' }, orange: { label: 'En retrait', bg: '#FCE7C8', fg: '#8A4B00', bar: '#D97B29' }, red: { label: 'Objectif manqué', bg: '#F6DCDF', fg: '#8D1D2C', bar: '#8D1D2C' }, grey: { label: 'Données indisponibles', bg: '#F4EFE8', fg: '#555555', bar: '#B8B2A8' } };
  const ragOf = (r) => r == null ? 'grey' : r >= 1 ? 'green' : r >= 0.7 ? 'orange' : 'red';

  function shopLabel(shops) {
    if (!shops || shops === 'all' || shops.length === SHOPS.length) return 'Réseau';
    return shops.map(id => (byId(SHOPS, id) || {}).name || id).join(', ');
  }

  /** Le type d'une opération : le référentiel s'il le connaît, sinon le libellé du Marketing du BO. */
  function ctype(c) {
    return CTYPES.find(t => t.id === (c && c.type)) || { id: c && c.type, name: (c && c.typeLabel) || 'Opération', color: '#666666', icon: 'img/illus/decorative/circle-01.png', lev: 'trafic', kpi: (c && c.goal && c.goal.metric) || '—' };
  }

  // Calcul du retour : renvoie {perShop, total} pour une campagne.
  function roi(cid) {
    const c = byId(state().campaigns, cid); if (!c) return null;
    const res = RESULTS[cid] || {};
    // Opération du Marketing du BO : objectif (levier chiffré), budget par boutique et fin peuvent manquer.
    const gv = c.goal && c.goal.value ? c.goal.value : 0, split = c.split || {};
    const elapsed = c.status === 'en cours' && c.start && c.end ? Math.min(1, (daysBetween(c.start, TODAY) + 1) / (daysBetween(c.start, c.end) + 1)) : 1;
    const perShop = c.shops.map(sid => {
      const r = res[sid] || null, shop = byId(SHOPS, sid) || { name: sid };
      const goal = gv * (Object.keys(split).length ? (split[sid] || 0) / (c.budget || 1) : 1 / (c.shops.length || 1)) * elapsed;
      if (!r) return { sid, name: shop.name, empty: true };
      const has = r.caRef != null;
      const caAdd = has ? r.caCamp - r.caRef : null, mAdd = has ? caAdd * MARGIN : null, tAdd = has ? r.tCamp - r.tRef : null;
      const o = { sid, name: shop.name, ...r, cpc: r.clicks ? r.spend / r.clicks : null, ctr: r.impr ? r.clicks / r.impr : null, cpm: r.impr ? r.spend / r.impr * 1000 : null, freq: r.reach ? r.impr / r.reach : null, caAdd, mAdd, tAdd, qAdd: has ? r.qCamp - r.qRef : null,
        roas: has && r.spend ? caAdd / r.spend : null, roi: has && r.spend ? (mAdd - r.spend) / r.spend : null, cpt: has && tAdd > 0 ? r.spend / tAdd : null,
        goal, att: has && goal ? caAdd / goal : null };
      o.rag = ragOf(o.att);
      return o;
    });
    const sum = (k, rows) => rows.reduce((a, x) => a + (x[k] || 0), 0);
    const withSales = perShop.filter(x => !x.empty && x.caRef != null);
    const t = { spend: sum('spend', perShop), reach: sum('reach', perShop), impr: sum('impr', perShop), clicks: sum('clicks', perShop), eng: sum('eng', perShop), vv: sum('vv', perShop),
      caRef: sum('caRef', withSales), caCamp: sum('caCamp', withSales), tAdd: sum('tAdd', withSales), qAdd: sum('qAdd', withSales) };
    t.caAdd = t.caCamp - t.caRef; t.mAdd = t.caAdd * MARGIN; t.cpc = t.clicks ? t.spend / t.clicks : null;
    t.ctr = t.impr ? t.clicks / t.impr : null; t.cpm = t.impr ? t.spend / t.impr * 1000 : null; t.freq = t.reach ? t.impr / t.reach : null;
    t.roas = t.spend ? t.caAdd / t.spend : null; t.roi = t.spend ? (t.mAdd - t.spend) / t.spend : null; t.cpt = t.tAdd > 0 ? t.spend / t.tAdd : null;
    t.goal = gv * elapsed; t.att = t.goal ? t.caAdd / t.goal : null; t.rag = ragOf(t.att); t.elapsed = elapsed; t.missing = perShop.filter(x => x.caRef == null).map(x => x.name);
    return { c, perShop, total: t };
  }

  // ---------- état persistant (localStorage) ----------
  function fresh() { return { posts: JSON.parse(JSON.stringify(POSTS)), ads: JSON.parse(JSON.stringify(ADS)), campaigns: JSON.parse(JSON.stringify(CAMPAIGNS)), shops: JSON.parse(JSON.stringify(SHOPS)), activity: ACTIVITY.slice(), leadforms: JSON.parse(JSON.stringify(LEADFORMS)), ftemplates: JSON.parse(JSON.stringify(FTEMPLATES)), franchise: { rules: Object.assign({}, FRULES), url: '../social_franchisee/' }, meta: { version: META.version, dsa: Object.assign({}, META.dsa), ids: JSON.parse(JSON.stringify(META.ids)) } }; }
  let cache = null;
  function state() {
    if (cache) return cache;
    try { const s = JSON.parse(localStorage.getItem(KEY) || 'null'); if (s && s.posts && s.ads && s.campaigns && s.shops) { s.shops.forEach(x => { if (!x.langs) { const b = SHOPS.find(y => y.id === x.id); x.langs = b ? b.langs.slice() : ['fr']; if (b && b.hoursNl) x.hoursNl = b.hoursNl; } }); if (!s.leadforms) s.leadforms = JSON.parse(JSON.stringify(LEADFORMS)); if (!s.ftemplates) s.ftemplates = JSON.parse(JSON.stringify(FTEMPLATES)); if (!s.franchise) s.franchise = { rules: Object.assign({}, FRULES), url: '../social_franchisee/' }; if (!s.meta) s.meta = { version: META.version, dsa: Object.assign({}, META.dsa), ids: JSON.parse(JSON.stringify(META.ids)) }; cache = s; return s; } } catch (e) {}
    cache = fresh(); return cache;
  }
  function save(patch) {
    cache = Object.assign({}, state(), patch);
    if (LIVE) push(patch); else { try { localStorage.setItem(KEY, JSON.stringify(cache)); } catch (e) {} }
    window.dispatchEvent(new Event('sa-change'));
    return cache;
  }
  /**
   * Rattache (op = « bo-… ») ou détache (null) un post ou une publicité à une opération du Marketing du BO.
   * Post du module et publicité : leur champ campaign ; publication lue chez Meta (mfb-…, mig-…) : le lien seul,
   * côté serveur (POST /operations/rattacher). Rend une promesse.
   */
  const demoRatt = () => { try { return JSON.parse(localStorage.getItem('sa.rattach') || '{}') || {}; } catch (e) { return {}; } };
  function rattacher(kind, id, op) {
    const meta = kind === 'post' && /^m(fb|ig)-/.test(id);
    const local = () => {
      if (meta) {
        if (!LIVE) { const m = demoRatt(); if (op) m[id] = op; else delete m[id]; try { localStorage.setItem('sa.rattach', JSON.stringify(m)); } catch (e) {} }
      } else {
        const col = kind === 'post' ? 'posts' : 'ads';
        cache = Object.assign({}, state(), { [col]: state()[col].map(x => x.id === id ? Object.assign({}, x, { campaign: op || null }) : x) });
        if (!LIVE) { try { localStorage.setItem(KEY, JSON.stringify(cache)); } catch (e) {} }
      }
      window.dispatchEvent(new Event('sa-change'));
      return { ok: true, kind, id, operation: op || null };
    };
    if (LIVE) return api('POST', '/operations/rattacher', { kind, id, operation: op || null }).then(local);
    return Promise.resolve(local());
  }
  function reset() {
    if (LIVE) { refresh(); return; }  // en réel, on relit le serveur : rien ne s'efface
    cache = fresh(); try { localStorage.removeItem(KEY); } catch (e) {} window.dispatchEvent(new Event('sa-change'));
  }
  // Les écrans écrivent au nom de « Sarah Lambert » (maquette) : en réel, c'est l'utilisateur connecté.
  const who_ = (who) => LIVE && /^Sarah Lambert/.test(who || '') ? LIVE.user.name : who;
  function log(who, what, detail) { const now = new Date(); const at = TODAY + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'); save({ activity: [{ at, who: who_(who), what, detail }].concat(state().activity) }); }
  /** Ce qui est unique au réseau : Instagram (compte de la marque) et le compte publicitaire, si aucune boutique n'a le sien. */
  function reseau() {
    if (LIVE) return LIVE.reseau || { instagram: !!LIVE.instagramUnique, publicite: false };
    return { instagram: true, publicite: !SHOPS.some(s => (META.ids[s.id] || {}).ad_account_id) };
  }
  /** La marque (connexions du réseau) ; en démonstration, tout est connecté. */
  function marque() {
    if (LIVE && LIVE.brand) return LIVE.brand;
    return { id: 'marque', name: 'L’Atelier By', fb: 'connected', igs: 'connected', ads: 'connected', ig: '@latelierby.be',
      connDetail: { fb: 'L’Atelier By', igs: '@latelierby.be', ads: (META.networkAccount || 'act_1428800') + ' · L’Atelier By réseau' } };
  }
  function alerts() {
    const out = [], R = reseau();
    state().shops.forEach(s => {
      if (s.fb !== 'connected') out.push({ shop: s.name, what: 'Page Facebook', st: s.fb });
      if (!R.instagram && s.igs !== 'connected') out.push({ shop: s.name, what: 'Instagram', st: s.igs });
      if (!R.publicite && s.ads !== 'connected') out.push({ shop: s.name, what: 'Compte publicitaire', st: s.ads });
    });
    // Instagram et compte publicitaire du réseau : suivis une seule fois.
    const b = marque();
    if (R.instagram && b.igs !== 'connected') out.push({ shop: 'Réseau', what: 'Instagram', st: b.igs || 'none' });
    if (R.publicite && b.ads !== 'connected') out.push({ shop: 'Réseau', what: 'Compte publicitaire', st: b.ads || 'none' });
    return out;
  }
  const LANGS = { fr: 'Français', nl: 'Nederlands' };
  const VDEF = { fr: { eyebrow: 'Édition saisonnière · Automne 2026', unit: 'La pièce', promoSub: 'la 5ᵉ offerte' }, nl: { eyebrow: 'Seizoenseditie · Herfst 2026', unit: 'Per stuk', promoSub: 'de 5de gratis' } };
  const langsOf = (shop) => { const s = typeof shop === 'string' ? byId(state().shops, shop) : shop; return (s && s.langs && s.langs.length) ? s.langs : ['fr']; };
  const pname = (p, lang) => p ? (lang === 'nl' && p.nameNl ? p.nameNl : p.name) : '';
  function fill(text, shop, product, lang) {
    const s = typeof shop === 'string' ? byId(state().shops, shop) : shop;
    const L = lang || (s ? langsOf(s)[0] : 'fr');
    const h = s ? (L === 'nl' && s.hoursNl ? s.hoursNl : s.hours) : '{horaires}';
    return (s && !String(h || '').trim() ? sansHoraires(String(text || '')) : String(text || ''))
      .replace(/\{horaires\}/g, s ? (L === 'nl' && s.hoursNl ? s.hoursNl : s.hours) : '{horaires}')
      .replace(/\{produit_du_jour\}/g, product && product.name ? pname(product, L) : (L === 'nl' ? 'Krokante brioche' : 'Brioche croustillante'))
      .replace(/\{ville\}/g, s ? s.name : '{ville}').replace(/\{adresse\}/g, s ? s.address : '{adresse}').replace(/\{horaires\}/g, s ? s.hours : '{horaires}')
      .replace(/\{prix\}/g, product && product.price ? product.price : '{prix}').replace(/\{produit_du_jour\}/g, product && product.name ? product.name : 'Brioche croustillante')
      .replace(/\{webshop_produit:liste\}/g, webshopUrl(s ? s.id : '', product, 0, 0, false))
      .replace(/\{webshop_(produit|categorie|groupe):(\d+)(:liste)?\}/g, (m, k, id, liste) => k === 'produit' ? webshopUrl(s ? s.id : '', { apiId: +id, kind: 'produit' }, 0, 0, !liste) : k === 'categorie' ? webshopUrl(s ? s.id : '', null, +id) : webshopUrl(s ? s.id : '', null, 0, +id))
      .replace(/\{webshop_produit\}/g, webshopUrl(s ? s.id : '', product)).replace(/\{webshop_categorie\}/g, webshopUrl(s ? s.id : '', null, product && product.categoryId))
      .replace(/\{webshop\}/g, webshopUrl(s ? s.id : ''))
      .replace(/\{hashtag_ville\}/g, s ? '#' + String(s.name || '').replace(/[^\p{L}\p{N}]/gu, '') : '{hashtag_ville}');
  }
  /**
   * Le texte qui introduit le lien d'un post (« Commandez ici 👉 ») : celui du post, sinon le réglage
   * `lien_texte` (Paramètres), sinon le texte d'origine. Meta n'accepte pas de lien caché sous un mot :
   * l'adresse suit ce texte (comme le serveur, socMessage).
   */
  const LIEN_DEF = { fr: 'Commandez ici 👉', nl: 'Bestel hier 👉' };
  function lienTexte(L, post) {
    const p = post && post.linkText ? String(post.linkText[L] || '').trim() : '';
    if (p) return p;
    const d = typeof cache !== 'undefined' && cache && cache.params ? cache.params.lien_texte : null;
    return (d && String(d[L] || '').trim()) || LIEN_DEF[L] || LIEN_DEF.fr;
  }
  /** La charte éditoriale de la marque (Paramètres → Rédaction IA) ; en démonstration, celle par défaut. */
  const CHARTE_DEF = { ton: 'Chaleureux, gourmand, simple et artisanal.', vouvoiement: true, emojisMax: 2, motsInterdits: [], hashtags: ['#latelierby'], hashtagVille: true, longueur: 125, signature: '' };
  const charte = () => Object.assign({}, CHARTE_DEF, (LIVE && LIVE.charte) || {});
  /**
   * Relecture d'un texte selon la charte : rend [{niveau: 'erreur' | 'attention' | 'conseil', msg}].
   * `ctx` : {prix (nombre, prix de vente connu), lien (bool), langue}.
   */
  function relire(texte, ctx) {
    const c = charte(), t = String(texte || ''), out = [], L = (ctx && ctx.langue) || 'fr';
    if (!t.trim()) return out;
    const nn = (x) => String(x || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const emojis = (t.match(/\p{Extended_Pictographic}/gu) || []).length;
    if (emojis > c.emojisMax) out.push({ niveau: 'attention', msg: emojis + ' emojis (la charte en permet ' + c.emojisMax + ')' });
    (c.motsInterdits || []).forEach(m => { if (m && new RegExp('(^|[^\\p{L}])' + nn(m).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '($|[^\\p{L}])', 'u').test(nn(t))) out.push({ niveau: 'erreur', msg: '« ' + m + ' » : mot à éviter (charte)' }); });
    if (L === 'fr' && c.vouvoiement && /(^|[^\p{L}])(tu|toi|ton|ta|tes|te|t’|t')([^\p{L}]|$)/iu.test(t.replace(/\{[^}]*\}/g, ''))) out.push({ niveau: 'attention', msg: 'tutoiement : la charte vouvoie' });
    if (L === 'nl' && c.vouvoiement && /(^|[^\p{L}])(je|jij|jou|jouw)([^\p{L}]|$)/iu.test(t)) out.push({ niveau: 'conseil', msg: 'néerlandais : « je/jij » — la charte vouvoie (« u »)' });
    const premiere = (t.split(/(?<=[.!?…])\s|\n/)[0] || '').trim();
    if (premiere.length > c.longueur) out.push({ niveau: 'conseil', msg: 'accroche de ' + premiere.length + ' caractères : seuls ~' + c.longueur + ' s’affichent avant « Voir plus »' });
    if ((t.match(/!/g) || []).length > 2 || /!!|\?\?/.test(t)) out.push({ niveau: 'conseil', msg: 'trop de points d’exclamation' });
    const cris = (t.replace(/\{[^}]*\}/g, '').match(/\b[\p{Lu}]{5,}\b/gu) || []).filter(w => !/^(CLICK|COLLECT)$/.test(w));
    if (cris.length) out.push({ niveau: 'conseil', msg: 'mots en capitales (' + cris.slice(0, 3).join(', ') + ') : à réserver au visuel' });
    if (ctx && ctx.prix != null) {
      const vus = (t.match(/(\d+[.,]\d{2})\s?€/g) || []).map(x => +x.replace(/[^\d,.]/g, '').replace(',', '.'));
      const faux = vus.filter(x => Math.abs(x - ctx.prix) > 0.004);
      if (faux.length && !vus.some(x => Math.abs(x - ctx.prix) <= 0.004)) out.push({ niveau: 'erreur', msg: 'prix ' + faux.map(x => x.toFixed(2).replace('.', ',') + ' €').join(', ') + ' dans le texte ≠ prix de vente ' + ctx.prix.toFixed(2).replace('.', ',') + ' €' });
    }
    if (ctx && ctx.lien && !/\{lien\}/.test(t)) out.push({ niveau: 'conseil', msg: 'le lien est ajouté en fin de post (placez {lien} pour le mettre ailleurs)' });
    return out;
  }
  /** Place {lien} dans un texte (« Commandez ici 👉 https://… ») ; sans lien (ou sur Instagram), la variable disparaît. */
  function placerLien(txt, lien) {
    const t = lien ? String(txt || '').split('{lien}').join(lien) : String(txt || '').replace(/[ \t]*\{lien\}[ \t]*/g, ' ');
    return t.replace(/[ \t]+(\n|$)/g, '$1').replace(/\n{3,}/g, '\n\n').trim();
  }
  /** Sans horaires (magasin test), la phrase qui cite {horaires} disparaît plutôt que « Ouvert . » (comme le serveur). */
  const sansHoraires = (t) => t.replace(/[ \t]*[^.!?\n]*\{horaires\}[^.!?\n]*[.!?]?/g, '').replace(/[ \t]{2,}/g, ' ').replace(/[ \t]+(\n|$)/g, '$1');
  // Le plan du webshop : catégorie → groupe (0 : sans groupe), produit → catégorie. Servi avec l'état, complété par chaque catalogue lu.
  const PLAN = { cats: {}, prods: {} };
  function planAjouter(nav) {
    if (!nav) return;
    (nav.categories || []).forEach(c => { if (c && c.id) PLAN.cats[c.id] = Math.max(+c.groupe || 0, PLAN.cats[c.id] || 0); });
    (nav.produits || []).forEach(p => { if (p && p.id && p.cat) PLAN.prods[p.id] = p.cat; });
    Object.entries((nav.cats || {})).forEach(([k, v]) => { PLAN.cats[k] = Math.max(+v || 0, PLAN.cats[k] || 0); });
    Object.entries((nav.prods || {})).forEach(([k, v]) => { if (v) PLAN.prods[k] = +v; });
  }
  /**
   * Le webshop d'une boutique, ou une page de ce webshop avec ses paramètres à lui (comme le serveur, src/publish.php) :
   * catégorie ?category=group:G · sous-catégorie &sub=category:C · produit : sa sous-catégorie + &product=ID&open=product.
   * Une boutique hors caisse (magasin test) n'a pas de webshop : le lien garde la page, sans boutique.
   */
  // `fiche` (par défaut) : un produit ouvre sa fiche (&open=product) ; false : il reste dans la liste de sa sous-catégorie.
  function webshopUrl(shopId, product, categoryId, groupId, fiche) {
    const base = (LIVE && LIVE.webshopUrl) || 'https://webshop.atelierby.be/';
    const sh = shopId ? byId(SHOPS, shopId) : null, q = [];
    if (shopId && !(sh && sh.test) && (LIVE ? /^\d+$/ : /^\w+$/).test(shopId)) q.push(['shop', shopId]);
    const pid = product && product.apiId && product.kind !== 'gamme' ? +product.apiId : 0;
    let cat = +categoryId || 0, grp = +groupId || 0;
    if (pid && !cat) cat = +product.categoryId || PLAN.prods[pid] || 0;
    if (cat) { grp = grp || PLAN.cats[cat] || 0; if (grp) q.push(['category', 'group:' + grp], ['sub', 'category:' + cat]); else q.push(['category', 'category:' + cat]); }
    else if (grp) q.push(['category', 'group:' + grp]);
    if (pid) { q.push(['product', String(pid)]); if (fiche !== false) q.push(['open', 'product']); }
    // « : » reste lisible (permis dans une requête) : ?category=group:28&sub=category:31000.
    return q.length ? base + '?' + q.map(([k, v]) => k + '=' + encodeURIComponent(v).replace(/%3A/g, ':')).join('&') : base;
  }
  /**
   * Une photo du webshop (Cloudflare R2, sans en-tête CORS) rapatriée par le module et remise en JPEG (data:) :
   * le visuel composé qui la dessine peut alors être exporté pour Meta (sinon le post part avec la photo brute).
   * Une image déjà locale (même origine, data:, blob:) est rendue telle quelle ; en démonstration, sans serveur, aussi.
   */
  const LOCALES = new Map();
  function photoLocale(src) {
    src = String(src || '');
    if (!/^https?:\/\//i.test(src)) return Promise.resolve(src);
    try { if (new URL(src, location.href).origin === location.origin) return Promise.resolve(src); } catch (e) { return Promise.resolve(src); }
    if (!LIVE) return Promise.resolve(src);
    if (LOCALES.has(src)) return LOCALES.get(src);
    const p = (async () => {
      const r = await fetch(API + '/media/webshop?u=' + encodeURIComponent(src), { credentials: 'same-origin' });
      if (!r.ok) { let m = 'HTTP ' + r.status; try { m = (await r.json()).error || m; } catch (e) {} throw new Error('photo du webshop illisible : ' + m); }
      const blob = await r.blob(), url = URL.createObjectURL(blob);
      try {
        const img = await new Promise((ok, ko) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => ko(new Error('photo du webshop illisible')); i.src = url; });
        const k = Math.min(1, 1600 / Math.max(img.naturalWidth || 1, img.naturalHeight || 1));
        const c = document.createElement('canvas'); c.width = Math.max(1, Math.round(img.naturalWidth * k)); c.height = Math.max(1, Math.round(img.naturalHeight * k));
        const g = c.getContext('2d'); g.fillStyle = '#FFFFFF'; g.fillRect(0, 0, c.width, c.height); g.drawImage(img, 0, 0, c.width, c.height);
        return c.toDataURL('image/jpeg', 0.9);
      } finally { URL.revokeObjectURL(url); }
    })();
    LOCALES.set(src, p); p.catch(() => LOCALES.delete(src));
    return p;
  }
  const PAGES = { cal: 'SA Calendrier.dc.html', grid: 'SA Grille.dc.html', fr: 'SA Franchise.dc.html', post: 'SA Post.dc.html', camp: 'SA Campagnes.dc.html', ads: 'SA Publicites.dc.html', leads: 'SA Leads.dc.html', roi: 'SA Retour.dc.html', suivi: 'SA Suivi.dc.html', ctrl: 'SA Controle.dc.html', com: 'SA Commentaires.dc.html', google: 'SA Google.dc.html', set: 'SA Parametres.dc.html' };
  const param = (k) => { try { return new URLSearchParams(location.search).get(k); } catch (e) { return null; } };
  // Intégré dans une autre application (`?embed=1`, ex. le module /marketing/) : sans le rail,
  // et chaque lien interne garde le paramètre pour que la navigation reste dans le cadre.
  const embed = param('embed') === '1';
  const href = (k, q) => encodeURI(PAGES[k]) + (q || embed ? '?' + [q, embed ? 'embed=1' : ''].filter(Boolean).join('&') : '');
  // Dans le cadre, l'application hôte apprend quel écran s'affiche (pour suivre la navigation dans son menu).
  if (embed && window.parent !== window) {
    try {
      const f = decodeURIComponent(location.pathname.split('/').pop());
      const k = Object.keys(PAGES).find(x => PAGES[x] === f);
      if (k) window.parent.postMessage({ type: 'social:page', page: k }, location.origin);
    } catch (e) {}
  }

  // ---------- Meta Graph / Marketing API ----------
  const META = {
    versions: ['v25.0', 'v26.0'], version: 'v25.0', base: 'https://graph.facebook.com/',
    ids: {
      halle: { page_id: '104857392011', ad_account_id: 'act_1428801', instagram_user_id: '17841400001', pixel_id: '880112' },
      corbais: { page_id: '104857392027', ad_account_id: 'act_1428802', instagram_user_id: '17841400002', pixel_id: '880112' },
      gosselies: { page_id: '104857392043', ad_account_id: 'act_1428803', instagram_user_id: '17841400003', pixel_id: '880112' },
      sombreffe: { page_id: '104857392059', ad_account_id: '', instagram_user_id: '17841400004', pixel_id: '880112' },
      gembloux: { page_id: '104857392075', ad_account_id: 'act_1428805', instagram_user_id: '17841400005', pixel_id: '880112' },
      wavre: { page_id: '104857392091', ad_account_id: 'act_1428806', instagram_user_id: '17841400006', pixel_id: '880112' },
    },
    networkAccount: 'act_1428800',
    // Devise et minimums de Meta par compte publicitaire (GET /meta/comptes) : act_… → { devise, minCap, minDaily }.
    comptes: { act_1428800: { devise: 'EUR', minCap: 100, minDaily: 1 } },
    dsa: { beneficiary: "L'Atelier By SRL", payor: "L'Atelier By SRL" },
    permissions: [['business_management', 'Full access'], ['pages_show_list', 'Full access'], ['pages_manage_posts', 'Full access'], ['pages_read_engagement', 'Full access'], ['instagram_basic', 'Full access'], ['instagram_content_publish', 'Full access'], ['ads_management', 'Full access'], ['pages_manage_ads', 'Full access'], ['leads_retrieval', 'Limited access'], ['pages_manage_engagement', 'Full access'], ['instagram_manage_comments', 'Full access']],
    objectives: [
      { id: 'OUTCOME_AWARENESS', label: 'Notoriété locale', desc: 'Montrer la marque au plus grand nombre autour des boutiques.', icon: 'img/illus/decorative/bubble.png', goals: ['REACH', 'IMPRESSIONS', 'THRUPLAY'], dest: [''] },
      { id: 'OUTCOME_TRAFFIC', label: 'Trafic', desc: 'Faire venir en boutique ou sur le webshop.', icon: 'img/illus/customer-facing/click-collect.png', goals: ['LINK_CLICKS', 'LANDING_PAGE_VIEWS', 'REACH'], dest: ['WEBSITE', 'PHONE_CALL'] },
      { id: 'OUTCOME_ENGAGEMENT', label: 'Engagement / messages', desc: 'Réactions, commentaires ou conversations Messenger.', icon: 'img/illus/customer-facing/phone-orders.png', goals: ['POST_ENGAGEMENT', 'CONVERSATIONS', 'THRUPLAY'], dest: ['ON_AD', 'MESSENGER', 'WHATSAPP', 'INSTAGRAM_DIRECT'] },
      { id: 'OUTCOME_LEADS', label: 'Prospects (leads)', desc: 'Collecter des contacts via un formulaire instantané.', icon: 'img/illus/customer-facing/b2b.png', goals: ['LEAD_GENERATION'], dest: ['ON_AD'] },
      { id: 'OUTCOME_SALES', label: 'Ventes webshop', desc: 'Commandes en ligne mesurées par le pixel.', icon: 'img/illus/customer-facing/webshop.png', goals: ['OFFSITE_CONVERSIONS', 'LANDING_PAGE_VIEWS'], dest: ['WEBSITE'] },
    ],
    bidStrategies: [['LOWEST_COST_WITHOUT_CAP', 'Coût le plus bas (recommandé)'], ['LOWEST_COST_WITH_BID_CAP', 'Enchère plafonnée'], ['COST_CAP', 'Coût par résultat plafonné'], ['LOWEST_COST_WITH_MIN_ROAS', 'ROAS minimum']],
    specialCats: [['EMPLOYMENT', 'Emploi (recrutement)'], ['HOUSING', 'Logement'], ['FINANCIAL_PRODUCTS_SERVICES', 'Services financiers'], ['ISSUES_ELECTIONS_POLITICS', 'Politique']],
    ctas: ['LEARN_MORE', 'SHOP_NOW', 'ORDER_NOW', 'GET_DIRECTIONS', 'CALL_NOW', 'SIGN_UP', 'CONTACT_US', 'GET_OFFER', 'GET_QUOTE', 'APPLY_NOW', 'MESSAGE_PAGE', 'WHATSAPP_MESSAGE'],
    ctaLabels: { LEARN_MORE: 'En savoir plus', SHOP_NOW: 'Acheter', ORDER_NOW: 'Commander', GET_DIRECTIONS: 'Itinéraire', CALL_NOW: 'Appeler', SIGN_UP: "S'inscrire", CONTACT_US: 'Nous contacter', GET_OFFER: "Profiter de l'offre", GET_QUOTE: 'Demander un devis', APPLY_NOW: 'Postuler', MESSAGE_PAGE: 'Envoyer un message', WHATSAPP_MESSAGE: 'WhatsApp' },
    platforms: ['facebook', 'instagram', 'messenger', 'audience_network'],
    geo: { halle: [50.7339, 4.2345], corbais: [50.6531, 4.6420], gosselies: [50.4694, 4.4318], sombreffe: [50.5297, 4.6006], gembloux: [50.5614, 4.6947], wavre: [50.7173, 4.6117] },
  };
  const LF_Q = { FULL_NAME: 'Nom complet', FIRST_NAME: 'Prénom', LAST_NAME: 'Nom', EMAIL: 'E-mail', PHONE: 'Téléphone', CITY: 'Ville', ZIP: 'Code postal', COMPANY_NAME: 'Société', JOB_TITLE: 'Fonction' };
  const LEADFORMS = [
    { id: 'lf-b2b-1', family: 'b2b', version: 1, status: 'Archivé', name: 'Plateaux entreprises · devis', locale: 'fr_FR', created: '2026-05-04', shops: ['wavre', 'gembloux'],
      questions: [{ type: 'FULL_NAME' }, { type: 'EMAIL' }, { type: 'COMPANY_NAME' }], privacy: { url: 'https://www.latelierby.be/confidentialite', link_text: 'Politique de confidentialité' },
      context: { title: 'Plateaux pour vos réunions', style: 'LIST_STYLE', content: ['Livraison au bureau', 'Commande dès 8 personnes'], button_text: 'Demander un devis' },
      thanks: { title: 'Merci !', body: 'Nous revenons vers vous sous 24 h.', button_type: 'VIEW_WEBSITE', button_text: 'Voir les plateaux', website_url: 'https://www.latelierby.be/b2b' }, quality: false, followUp: 'https://www.latelierby.be/b2b' },
    { id: 'lf-b2b-2', family: 'b2b', version: 2, status: 'Actif', name: 'Plateaux entreprises · devis', locale: 'fr_FR', created: '2026-09-02', shops: ['wavre', 'gembloux', 'corbais'],
      questions: [{ type: 'FULL_NAME' }, { type: 'EMAIL' }, { type: 'PHONE' }, { type: 'COMPANY_NAME' }, { type: 'CUSTOM', key: 'nb_personnes', label: 'Pour combien de personnes ?', options: [{ key: '8_15', value: '8 à 15' }, { key: '16_30', value: '16 à 30' }, { key: '30p', value: 'Plus de 30' }] }],
      privacy: { url: 'https://www.latelierby.be/confidentialite', link_text: 'Politique de confidentialité' },
      context: { title: 'Plateaux pour vos réunions', style: 'LIST_STYLE', content: ['Livraison au bureau dès 7h30', 'Commande dès 8 personnes', 'Devis sous 24 h'], button_text: 'Demander un devis' },
      thanks: { title: 'Merci, demande reçue', body: 'La boutique la plus proche vous rappelle sous 24 h.', button_type: 'CALL_BUSINESS', button_text: 'Appeler la boutique', website_url: '' }, quality: true, followUp: 'https://www.latelierby.be/b2b' },
    { id: 'lf-rec-1', family: 'recrut', version: 1, status: 'Actif', name: 'Recrutement vendeur·se', locale: 'fr_FR', created: '2026-10-01', shops: ['wavre', 'gembloux'],
      questions: [{ type: 'FULL_NAME' }, { type: 'EMAIL' }, { type: 'PHONE' }, { type: 'CITY' }, { type: 'CUSTOM', key: 'dispo', label: 'Disponibilités', options: [{ key: 'temps_plein', value: 'Temps plein' }, { key: 'temps_partiel', value: 'Temps partiel' }, { key: 'weekend', value: 'Week-ends' }] }],
      privacy: { url: 'https://www.latelierby.be/confidentialite', link_text: 'Politique de confidentialité' },
      context: { title: 'Rejoignez l\u2019équipe', style: 'PARAGRAPH_STYLE', content: ['Nous cherchons des vendeur·ses souriant·es pour nos boutiques de Wavre et Gembloux.'], button_text: 'Postuler' },
      thanks: { title: 'Candidature envoyée', body: 'Le manager de la boutique vous contacte rapidement.', button_type: 'VIEW_WEBSITE', button_text: 'Découvrir la marque', website_url: 'https://www.latelierby.be' }, quality: true, followUp: 'https://www.latelierby.be' },
  ];
  // Espace franchisé : les modèles et les règles livrés (le serveur fait foi en réel).
  const FTEMPLATES = [{"id": "fp-produit", "order": 1, "name": "Produit du jour", "description": "Mettre un produit de votre vitrine en avant, à son prix.", "active": true, "type": "Produit", "fb": true, "ig": true, "validation": false, "title": "{produit}", "text": "{produit} : {prix} € chez L’Atelier By {ville}. Ouvert {horaires}.", "textNl": "{produit}: {prix} € bij L’Atelier By {ville}. Open {horaires}.", "product": "choix", "promo": {"kind": "aucune"}, "period": false, "free": 0, "photo": "choix", "visual": {"style": "epure", "eyebrow": "Aujourd’hui en vitrine", "eyebrowNl": "Vandaag in de etalage"}}, {"id": "fp-remise", "order": 2, "name": "Promotion en %", "description": "Une remise sur un produit, pour une durée limitée.", "active": true, "type": "Produit", "fb": true, "ig": true, "validation": true, "title": "{produit} -{remise} %", "text": "-{remise} % sur {produit} chez L’Atelier By {ville}, du {du} au {au}. Ouvert {horaires}.", "textNl": "-{remise} % op {produit} bij L’Atelier By {ville}, van {du} tot {au}. Open {horaires}.", "product": "choix", "promo": {"kind": "remise", "min": 10, "max": 30}, "period": true, "free": 0, "photo": "choix", "visual": {"style": "ruby", "eyebrow": "Promotion", "eyebrowNl": "Promotie"}}, {"id": "fp-offre", "order": 3, "name": "Offre spéciale", "description": "4+1, 2+1… sur un produit, pour une durée limitée.", "active": true, "type": "Produit", "fb": true, "ig": true, "validation": true, "title": "{offre} · {produit}", "text": "{offre} sur {produit} chez L’Atelier By {ville}, du {du} au {au} !", "textNl": "{offre} op {produit} bij L’Atelier By {ville}, van {du} tot {au}!", "product": "choix", "promo": {"kind": "offre", "offres": ["4+1", "2+1", "1 acheté = 1 offert"]}, "period": true, "free": 0, "photo": "choix", "visual": {"style": "abricot", "eyebrow": "Offre spéciale", "eyebrowNl": "Speciale actie"}}, {"id": "fp-evenement", "order": 4, "name": "Événement en boutique", "description": "Une dégustation, une animation, un horaire spécial.", "active": true, "type": "Événement local", "fb": true, "ig": true, "validation": true, "title": "Événement à {ville}", "text": "Chez L’Atelier By {ville} : {libre}", "textNl": "Bij L’Atelier By {ville}: {libre}", "product": "aucun", "promo": {"kind": "aucune"}, "period": false, "free": 90, "photo": "upload", "visual": {"style": "noir", "eyebrow": "En boutique", "eyebrowNl": "In de winkel"}}, {"id": "fp-vitrine", "order": 5, "name": "Nouveau en vitrine", "description": "Votre photo d’un produit qui arrive.", "active": true, "type": "Produit", "fb": true, "ig": true, "validation": false, "title": "Nouveau : {produit}", "text": "Nouveau dans notre vitrine à {ville} : {produit}. À découvrir {horaires} !", "textNl": "Nieuw in onze etalage in {ville}: {produit}. Te ontdekken {horaires}!", "product": "choix", "promo": {"kind": "aucune"}, "period": false, "free": 0, "photo": "upload", "visual": {"style": "plein", "eyebrow": "Nouveau", "eyebrowNl": "Nieuw"}}];
  const FRULES = {"actif": true, "validation": "modele", "quotaSemaine": 3, "delaiMinHeures": 24, "horizonJours": 30, "jours": [1, 2, 3, 4, 5, 6, 0], "heureMin": "07:00", "heureMax": "20:00", "fb": true, "ig": true, "remiseMax": 30, "promoDureeMax": 14, "voirReseau": true, "voirCampagnes": true, "voirResultats": true, "voirSuivi": true, "voirAnalyse": true, "voirPublicites": true, "voirBudgetPub": false, "voirCommentaires": true, "voirAvisGoogle": true, "repondreCommentaires": true, "rouges": "reseau", "delaiReponseHeures": 24, "story": true, "recurrenceMax": 8, "persoVisuel": true, "message": "Bienvenue dans votre espace communication. Choisissez un modèle, complétez les champs : le visuel et le texte suivent la charte de L’Atelier By."};
  // Les dark posts créés par les pubs (relus chez Meta par le serveur) : pub, post, boutique, langue, variante, liens, commentaires.
  const DARK = [];
  const LEADS = [
    { id: 'ld1', form: 'lf-b2b-2', created_time: '2026-10-09 16:42', ad: 'Plateaux B2B · leads', shop: 'wavre', field_data: { FULL_NAME: 'Marie Claes', EMAIL: 'm.claes@exemple.be', PHONE: '+32 470 11 22 33', COMPANY_NAME: 'Bureau Claes', nb_personnes: '16 à 30' } },
    { id: 'ld2', form: 'lf-b2b-2', created_time: '2026-10-08 10:05', ad: 'Plateaux B2B · leads', shop: 'gembloux', field_data: { FULL_NAME: 'Pierre Dumont', EMAIL: 'p.dumont@exemple.be', PHONE: '+32 471 44 55 66', COMPANY_NAME: 'AgroTech SA', nb_personnes: 'Plus de 30' } },
    { id: 'ld3', form: 'lf-b2b-2', created_time: '2026-10-06 14:18', ad: 'Plateaux B2B · leads', shop: 'corbais', field_data: { FULL_NAME: 'Sofia Rossi', EMAIL: 's.rossi@exemple.be', PHONE: '+32 472 77 88 99', COMPANY_NAME: 'Rossi & Fils', nb_personnes: '8 à 15' } },
    { id: 'ld4', form: 'lf-rec-1', created_time: '2026-10-09 20:11', ad: 'Recrutement Wavre', shop: 'wavre', field_data: { FULL_NAME: 'Noah Verbeke', EMAIL: 'noah.v@exemple.be', PHONE: '+32 473 12 34 56', CITY: 'Wavre', dispo: 'Week-ends' } },
    { id: 'ld5', form: 'lf-rec-1', created_time: '2026-10-07 09:30', ad: 'Recrutement Wavre', shop: 'gembloux', field_data: { FULL_NAME: 'Léa Jacquet', EMAIL: 'lea.j@exemple.be', PHONE: '+32 474 65 43 21', CITY: 'Gembloux', dispo: 'Temps partiel' } },
    { id: 'ld6', form: 'lf-b2b-1', created_time: '2026-06-18 11:02', ad: 'Plateaux B2B · printemps', shop: 'wavre', field_data: { FULL_NAME: 'Hugo Lambotte', EMAIL: 'h.lambotte@exemple.be', COMPANY_NAME: 'Lambotte SPRL' } },
  ];
  const cents = (eurV) => Math.round((+eurV || 0) * 100);

  // ---------- mode réel : l'API du module ----------
  const KINDS = ['posts', 'ads', 'campaigns', 'leadforms', 'groups', 'templates', 'ftemplates'];
  const API = String(window.SOCIAL_API_BASE || 'api/v1/social').replace(/\/$/, '');
  let LIVE = null, base = {}, known = new WeakSet(), queue = Promise.resolve(), pending = 0, why = '';
  const snap = (arr) => { const m = {}; (arr || []).forEach(x => { if (x && x.id != null) m[x.id] = JSON.stringify(x); }); return m; };
  function apiSync(method, path) {
    const x = new XMLHttpRequest();
    x.open(method, API + path, false);
    x.setRequestHeader('Accept', 'application/json');
    x.send();
    let j = null; try { j = JSON.parse(x.responseText); } catch (e) {}
    return { status: x.status, json: j };
  }
  async function api(method, path, body) {
    const r = await fetch(API + path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: body ? JSON.stringify(body) : undefined });
    let j = null; try { j = await r.json(); } catch (e) {}
    if (r.status === 401 && j && j.login) { location.href = j.login; throw new Error('auth'); }
    if (!r.ok) throw new Error((j && j.error) || ('HTTP ' + r.status));
    return j;
  }
  // Applique un état lu au serveur aux constantes que les écrans lisent directement.
  function apply(L) {
    LIVE = L; planAjouter(L.webshopPlan); TODAY = L.today || TODAY; MARGIN = L.margin != null ? +L.margin : MARGIN; IMPORT = L.metaImport || null;
    const swap = (arr, v) => { arr.splice(0, arr.length, ...(v || [])); };
    swap(SHOPS, (L.shops || []).filter(x => !x.test)); swap(TEST_SHOPS, (L.shops || []).filter(x => x.test)); swap(GROUPS, L.groups); swap(TEMPLATES, L.templates); swap(LEADFORMS, L.leadforms); swap(LEADS, L.leads); swap(DARK, L.darkPosts);
    if (L.catalogue && L.catalogue.length) swap(CATALOGUE, L.catalogue);
    Object.keys(RESULTS).forEach(k => delete RESULTS[k]); Object.assign(RESULTS, L.results || {});
    const m = L.meta || {};
    META.version = m.version || META.version; META.dsa = m.dsa || META.dsa; META.ids = m.ids || {}; META.networkAccount = m.networkAccount || ''; META.comptes = m.comptes || {};
    if (META.versions.indexOf(META.version) < 0) META.versions = META.versions.concat(META.version);
    // Sans position connue, l'assistant Publicités garde un centre (Bruxelles) pour son aperçu ;
    // le serveur, lui, refuse de créer une pub sans la vraie position (ou l'adresse géocodée).
    META.geo = {}; SHOPS.forEach(s => { META.geo[s.id] = (m.geo && m.geo[s.id]) || [50.8466, 4.3528]; });
    cache = { posts: L.posts || [], ads: L.ads || [], campaigns: L.campaigns || [], shops: L.shops || [], activity: L.activity || [], leadforms: L.leadforms || [],
      groups: L.groups || [], templates: L.templates || [], leads: L.leads || [], ftemplates: L.ftemplates || [], franchise: L.franchise || { rules: {} }, params: L.params || {}, tfb: L.tfb || {}, tfbAudit: L.tfbAudit || {}, brand: L.brand || null, meta: { version: META.version, dsa: META.dsa, ids: META.ids, networkAccount: META.networkAccount, lastCheck: m.lastCheck } };
    base = {}; KINDS.forEach(k => { base[k] = snap(cache[k]); }); base.shops = snap(cache.shops); base.meta = JSON.stringify(cache.meta);
    known = new WeakSet(); cache.activity.forEach(a => known.add(a));
    if (window.SA) Object.assign(window.SA, { TODAY, MARGIN, user: L.user, live: true, metaStatus: L.metaStatus });
  }
  // N'envoie que les objets changés depuis la dernière lecture.
  function push(patch) {
    const body = { changes: {} };
    KINDS.forEach(k => {
      if (!(k in patch)) return;
      const cur = snap(patch[k]), old = base[k] || {}, up = [], del = [];
      (patch[k] || []).forEach(x => { if (x && x.id != null && cur[x.id] !== old[x.id]) up.push(x); });
      Object.keys(old).forEach(id => { if (!(id in cur)) del.push(id); });
      if (up.length || del.length) body.changes[k] = { upsert: up, delete: del };
      base[k] = cur;
    });
    if ('shops' in patch) {
      const cur = snap(patch.shops), ch = (patch.shops || []).filter(x => cur[x.id] !== (base.shops || {})[x.id]);
      if (ch.length) body.shops = ch.map(x => ({ id: x.id, langs: x.langs, address: x.address, hours: x.hours, hoursNl: x.hoursNl }));
      base.shops = cur;
    }
    if ('meta' in patch && JSON.stringify(patch.meta) !== base.meta) { body.meta = patch.meta; base.meta = JSON.stringify(patch.meta); }
    if ('activity' in patch) { const n = (patch.activity || []).filter(a => !known.has(a)); n.forEach(a => known.add(a)); if (n.length) body.activity = n; }
    if (!Object.keys(body.changes).length && !body.shops && !body.meta && !body.activity) return;
    pending++;
    queue = queue.then(() => api('POST', '/state', body)).then(r => {
      // Le serveur rend chaque objet tel qu'il l'a gardé (statut Meta, échecs, médias enregistrés).
      (r.items || []).forEach(([k, it]) => {
        if (!it || !cache[k]) return;
        const i = cache[k].findIndex(x => x.id === it.id);
        cache[k] = i < 0 ? cache[k].concat(it) : cache[k].map(x => x.id === it.id ? it : x);
        base[k] = Object.assign({}, base[k], { [it.id]: JSON.stringify(it) });
      });
      if ((r.errors || []).length) notify('Enregistré, mais Meta a répondu : ' + r.errors.map(e => e.error).join(' · '), true);
      if ((r.items || []).length) window.dispatchEvent(new Event('sa-change'));
    }).catch(e => { if (e.message !== 'auth') notify('Échec de l’enregistrement : ' + e.message + ' — rechargez la page.', true); })
      .finally(() => { pending--; });
  }
  function refresh() {
    if (!LIVE || pending) return Promise.resolve();
    return api('GET', '/state').then(L => { if (!pending) { apply(L); window.dispatchEvent(new Event('sa-change')); } }).catch(() => {});
  }
  /**
   * « Récupérer les campagnes Meta » : relit chez Meta les campagnes de tous les comptes
   * publicitaires (et l'état des pubs du module), puis relit l'état. Rend le compte rendu.
   */
  function importMeta() {
    if (!LIVE) {
      const st = state(), add = ADS.filter(a => a.origin === 'meta' && !st.ads.some(x => x.id === a.id)).map(a => JSON.parse(JSON.stringify(a)));
      if (add.length) save({ ads: st.ads.concat(add) });
      const n = st.ads.filter(a => a.origin === 'meta').length, now = new Date();
      IMPORT = Object.assign({}, IMPORT, { le: TODAY + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'), importees: add.length, majs: n, total: add.length + n, erreurs: [], demo: true });
      window.dispatchEvent(new Event('sa-change'));
      return Promise.resolve(IMPORT);
    }
    return api('POST', '/meta/campagnes/rafraichir').then(r => { IMPORT = r.import || IMPORT; return refresh().then(() => IMPORT); });
  }
  /** Une pastille de profil sans photo : les initiales sur un rond aux couleurs de la marque. */
  function avatar(name, net) {
    const ini = String(name || '?').replace(/^l[’']atelier\s*by\s*[-–·]?\s*/i, '').split(/[\s.@_-]+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('') || 'AB';
    const bg = net === 'instagram' ? '#C13584' : '#8D1D2C';
    return 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" rx="32" fill="' + bg + '"/><text x="32" y="41" font-family="Arial,sans-serif" font-size="24" font-weight="700" fill="#fff" text-anchor="middle">' + ini + '</text></svg>');
  }
  const hash = (t) => { let h = 7; for (let i = 0; i < t.length; i++) h = (h * 31 + t.charCodeAt(i)) % 100003; return h; };
  /**
   * Les publications des Pages et comptes Instagram entre deux dates, lues en direct chez Meta
   * (GET /meta/calendriers) : rien n'est gardé en base ni dans l'état de l'écran.
   */
  function calendriers(du, au) {
    if (LIVE) return api('GET', '/meta/calendriers?du=' + du + '&au=' + au);
    // Démonstration : une Page et un Instagram par boutique, plus la marque, et quelques publications faites hors du module.
    const comptes = [{ key: 'fb:999000', type: 'facebook', id: '999000', name: "L'Atelier By", username: 'latelierby', picture: null, link: 'https://www.facebook.com/latelierby', shop: 'marque' },
      { key: 'ig:17841400000', type: 'instagram', id: '17841400000', name: "L'Atelier By", username: 'latelierby.be', picture: null, link: 'https://www.instagram.com/latelierby.be/', shop: 'marque' }];
    SHOPS.forEach(sh => { const ids = META.ids[sh.id] || {};
      if (ids.page_id) comptes.push({ key: 'fb:' + ids.page_id, type: 'facebook', id: ids.page_id, name: sh.page || sh.name, username: '', picture: null, link: null, shop: sh.id });
 });  // un seul compte Instagram pour tout le réseau : celui de la marque
    const pics = CATALOGUE.filter(x => x.img).map(x => x.img), titres = ['Ce matin au comptoir', 'Le coin du boulanger', 'Merci pour vos avis !', 'Nouveau en vitrine', 'Fournée du week-end', 'On vous attend samedi'];
    const posts = [];
    for (let d = du; d <= au; d = addDays(d, 1)) {
      comptes.forEach(c => {
        const h = hash(c.key + d); if (h % 13) return;
        const fb = c.type === 'facebook', futur = d > TODAY;
        if (futur && !fb) return;  // Instagram ne programme pas par l'API : seuls les posts publiés remontent
        const t = titres[h % titres.length];
        posts.push({ id: (fb ? 'mfb-' : 'mig-') + c.id + '_' + d, origin: 'meta', channel: fb ? 'Facebook' : 'Instagram', account: c.key, accountName: fb ? c.name : '@' + c.username,
          date: d, time: String(8 + h % 10).padStart(2, '0') + ':' + (h % 2 ? '30' : '00'), type: fb ? (h % 4 ? 'Photo' : 'Vidéo') : (h % 3 ? 'Photo' : 'Reel'), title: t, text: t + ' — publié directement depuis Meta Business Suite.',
          img: pics[h % pics.length], shops: c.shop && c.shop !== 'marque' ? [c.shop] : [], marque: c.shop === 'marque', fb, ig: !fb, status: futur ? 'Programmé' : 'Publié',
          permalink: fb ? 'https://www.facebook.com/' : 'https://www.instagram.com/', stats: futur ? null : { reactions: 8 + h % 60, comments: h % 9, shares: fb ? h % 5 : null } });
      });
    }
    comptes.forEach(c => { c.publies = posts.filter(p => p.account === c.key && p.status === 'Publié').length; c.programmes = posts.filter(p => p.account === c.key && p.status === 'Programmé').length; c.erreur = null; });
    const ratt = demoRatt(); posts.forEach(p => { p.campaign = ratt[p.id] || null; });
    return Promise.resolve({ du, au, le: TODAY + ' ' + String(new Date().getHours()).padStart(2, '0') + ':' + String(new Date().getMinutes()).padStart(2, '0'), comptes, posts, erreurs: [], demo: true });
  }
  /**
   * Supprime un post (POST /posts/supprimer) : du module — et, avec meta, de Facebook et d'Instagram
   * là où il est déjà publié —, ou une publication lue chez Meta (mfb-…, mig-…), supprimée chez Meta.
   * Rend une promesse { ok, supprimees: [{shopName, channel}], erreurs: [{shopName, channel, reason}] } ;
   * tant qu'une suppression est refusée chez Meta, le post reste au calendrier.
   */
  function supprimerPost(p, meta) {
    const horsModule = /^m(fb|ig)-/.test(p.id);
    const local = (r) => {
      if (r && r.ok && !horsModule) {
        cache = Object.assign({}, state(), { posts: state().posts.filter(x => x.id !== p.id) });
        if (base.posts) { base.posts = Object.assign({}, base.posts); delete base.posts[p.id]; }
        if (!LIVE) { try { localStorage.setItem(KEY, JSON.stringify(cache)); } catch (e) {} }
        window.dispatchEvent(new Event('sa-change'));
      }
      return r;
    };
    if (LIVE) return api('POST', '/posts/supprimer', { id: p.id, meta: !!meta, compte: p.account || '' }).then(local);
    // Démonstration : rien n'existe chez Meta, la suppression réussit toujours.
    const enLigne = p.status === 'Publié' && (meta || horsModule);
    const faits = !enLigne ? [] : horsModule ? [{ shopName: p.accountName || '', channel: p.channel }]
      : (p.shops === 'all' ? SHOPS.map(x => x.id) : p.shops || []).flatMap(id => [p.fb ? 'Facebook' : '', p.ig ? 'Instagram' : ''].filter(Boolean)
        .map(channel => ({ shopName: (byId(SHOPS, id) || { name: id }).name, channel })));
    return new Promise(r => setTimeout(() => r(local({ ok: true, id: p.id, supprimees: faits, erreurs: [], demo: true })), 300));
  }
  /**
   * Créer un post : le catalogue du webshop — catégorie → sous-catégorie → produit, gammes —, avec le prix
   * de vente d'UNE boutique (`shop` ; vide : plusieurs boutiques, pas de prix). GET /catalogue/nav.
   * Démonstration : un instantané du webshop de Halle (demo-catalogue.json).
   */
  let DEMO_CAT = null;
  const demoCat = () => DEMO_CAT || (DEMO_CAT = fetch('demo-catalogue.json').then(r => r.json()));
  function catalogueNav(shop) {
    if (LIVE) return api('GET', '/catalogue/nav?shop=' + encodeURIComponent(shop || '')).then(nav => { planAjouter(nav); return nav; });
    return demoCat().then(d => { planAjouter(d.nav);
      const nav = JSON.parse(JSON.stringify(d.nav));
      nav.shop = shop || null; nav.prix = !!shop;
      if (!shop) nav.produits.forEach(p => { p.prix = null; });
      return nav;
    });
  }
  /**
   * Les promotions, bundles et formules liés à la sélection (q : {shop, produit | categorie | groupe | gamme}).
   * GET /catalogue/offres ; démonstration : les promotions réelles de l'instantané, même règle de lien.
   */
  function catalogueOffres(q) {
    if (LIVE) return api('GET', '/catalogue/offres?' + Object.keys(q).filter(k => q[k] != null && q[k] !== '').map(k => k + '=' + encodeURIComponent(q[k])).join('&'));
    return demoCat().then(d => {
      const prods = d.nav.produits, pid = +q.produit || 0, cid = +q.categorie || 0, gid = +q.groupe || 0, gam = +q.gamme || 0;
      let mp = [], mc = [];
      if (pid) { mp = [pid]; const p = prods.find(x => x.id === pid); if (p && p.cat) mc = [p.cat]; }
      else if (cid) { mc = [cid]; mp = prods.filter(x => x.cat === cid).map(x => x.id); }
      else if (gid) { const l = prods.filter(x => x.groupe === gid); mp = l.map(x => x.id); mc = [...new Set(l.map(x => x.cat))]; }
      else if (gam) { const l = prods.filter(x => (x.gammes || []).includes(gam)); mp = l.map(x => x.id); mc = [...new Set(l.map(x => x.cat))]; }
      // L'instantané cite les boutiques par leur n° de caisse ; la démonstration, par leur nom.
      const CAISSES = { 2: 'Corbais', 3: 'Gosselies', 4: 'Halle', 5: 'Sombreffe' }, nomShop = (id) => CAISSES[id] || (byId(SHOPS, id) || { name: id }).name;
      const ici = q.shop ? nomShop(q.shop) : null;
      const promotions = d.promotions.filter(x => x.produits.some(i => mp.includes(i)) || x.categories.some(i => mc.includes(i)))
        .map(x => Object.assign({}, x, { ici: x.tous || !ici || x.shops.map(nomShop).includes(ici), portee: x.tous ? 'Toutes les boutiques' : x.shops.map(nomShop).join(', ') }));
      return { promotions, formules: pid ? (d.formules[String(pid)] || []) : [], shop: q.shop || null, erreur: null, demo: true };
    });
  }
  /** Suivi des boutiques : abonnés gagnés / perdus face au budget, lus en direct chez Meta (GET /suivi/abonnes). */
  /**
   * Les chiffres d'une publication Meta (vues, personnes touchées, clics, réactions…),
   * lus à l'ouverture de sa fiche (GET /meta/post-stats) : rien n'est gardé.
   */
  function postStats(p) {
    if (LIVE) return api('GET', '/meta/post-stats?id=' + encodeURIComponent(p.id) + '&compte=' + encodeURIComponent(p.account || ''));
    const h = hash(p.id), fb = !p.ig, vues = 180 + h % 2400;
    return new Promise(r => setTimeout(() => r({ id: p.id, vues, personnes: Math.round(vues * (0.62 + (h % 20) / 100)), clics: fb ? 4 + h % 70 : null,
      videos: fb && /Vidéo/.test(p.type || '') ? Math.round(vues * 0.4) : null, reactions: 8 + h % 60, commentaires: h % 9, partages: fb ? h % 5 : 1 + h % 4,
      enregistrements: fb ? null : h % 12, interactions: fb ? null : 20 + h % 80, motif: null, le: TODAY, demo: true }), 350));
  }

  /**
   * Les commentaires de toutes les Pages et de l'Instagram, classés vert / orange / rouge (src/commentaires.php).
   * En démonstration : quelques commentaires d'exemple, classés comme le ferait le serveur.
   */
  let DEMO_COM = null;
  function demoCommentaires(jours) {
    if (!DEMO_COM) {
      const h = (n, k) => addDays(TODAY, -n) + ' ' + k;
      const pub = 'Nouvelle tarte aux pommes ! −20 % ce week-end sur les tartes entières.';
      DEMO_COM = [
        ['d1', 'fb', 'L’Atelier By Halle', 'Halle', 'Paul R.', 'J’ai trouvé un cheveu dans ma tarte, inadmissible.', h(0, '09:12'), pub, 'rouge', 'Plainte hygiène', 'Client mécontent : un cheveu dans sa tarte.', 'Répondre vite et proposer d’en parler en message privé.', 'Bonjour Paul, nous sommes vraiment désolés. Pouvez-vous nous écrire en message privé pour que nous puissions arranger cela ?'],
        ['d2', 'fb', 'L’Atelier By Halle', 'Halle', 'Marc B.', 'Jusqu’à quand la promo ?', h(0, '08:40'), pub, 'orange', 'Question promotion', 'Demande la date de fin de la promotion.', 'Répondre avec les dates de la promotion.', 'Bonjour Marc, la promotion court jusqu’à dimanche inclus. À bientôt !'],
        ['d3', 'ig', 'Instagram · L’Atelier By', 'Réseau', 'anna.b', 'Waar is de winkel in Halle?', h(1, '18:05'), 'De zondagsbrioche is er weer!', 'orange', 'Question adresse', 'Demande l’adresse de la boutique de Halle.', 'Répondre avec l’adresse de Halle.', 'Hallo Anna, u vindt ons in de Auguste Demaeghtlaan 227 in Halle. Tot snel!'],
        ['d4', 'fb', 'L’Atelier By Corbais', 'Corbais', 'Julie D.', 'Trop bon !! 😍', h(1, '12:30'), 'Les cookies du jour sont sortis du four.', 'vert', 'Compliment', 'Apprécie les cookies.', 'Un j’aime suffit.', 'Merci beaucoup Julie !'],
        ['d5', 'fb', 'L’Atelier By Gosselies', 'Gosselies', 'Sam V.', '@Lucie on y va samedi ?', h(2, '20:14'), 'Brunch du samedi : réservez votre table.', 'vert', 'Mention d’un ami', 'Invite une amie au brunch.', '', ''],
      ].map(([id, canal, compte, boutique, auteur, message, le, publication, couleur, sujet, resume, action, reponse]) => ({ id, canal, compte, boutique, auteur, message, le, masque: false,
        publication, lienPublication: '', lien: '', couleur, sujet, resume, action, reponse, analyse: 'ia', traite: false, traiteLe: null, traitePar: '', compteKey: canal + ':' + compte,
        responsable: couleur === 'rouge' || boutique === 'Réseau' ? 'reseau' : 'franchise', enRetard: id === 'd2', escalade: id === 'd2', ageH: id === 'd2' ? 27 : 2, peutRepondre: true, reponseEnvoyee: '' }));
    }
    const liste = DEMO_COM.filter(c => daysBetween(c.le.slice(0, 10), TODAY) < jours);
    const totaux = { rouge: 0, orange: 0, vert: 0, aClasser: 0, rougeOuverts: 0, orangeOuverts: 0, total: liste.length, enRetard: liste.filter(c => c.enRetard && !c.traite).length, delaiMedianH: 5.5 };
    liste.forEach(c => { totaux[c.couleur]++; if (!c.traite && c.couleur !== 'vert') totaux[c.couleur + 'Ouverts']++; });
    return { jours, commentaires: liste.map(c => Object.assign({}, c)), totaux, comptes: [...new Set(liste.map(c => c.canal + ':' + c.compte))].map(k => ({ key: k, name: k.split(':').slice(1).join(':') })),
      relu: { le: TODAY + ' 08:00', lus: liste.length, comptes: 6, erreurs: [] }, ia: { configure: true, modele: 'démonstration' }, demo: true };
  }
  function commentaires(jours) {
    if (LIVE) return api('GET', '/commentaires?jours=' + jours);
    return Promise.resolve(demoCommentaires(jours));
  }
  function commentairesRelire(jours) {
    if (LIVE) return api('POST', '/commentaires/relire', { jours });
    return Promise.resolve(Object.assign(demoCommentaires(jours), { sync: { lus: 5, comptes: 6, erreurs: [], dark: 0 }, analyse: { classes: 0, ia: true, erreur: null } }));
  }
  function commentaireRepondre(id, message) {
    if (LIVE) return api('POST', '/commentaires/repondre', { id, message });
    const c = (DEMO_COM || []).find(x => x.id === id), le = TODAY + ' ' + new Date().toTimeString().slice(0, 5);
    if (c) { c.reponseEnvoyee = message; c.reponseLe = le; c.reponsePar = 'Vous'; c.traite = true; c.traiteLe = le; c.traitePar = 'Vous'; c.enRetard = false; }
    return Promise.resolve({ ok: true, le });
  }
  function commentaireTraiter(id, traite) {
    if (LIVE) return api('POST', '/commentaires/traiter', { id, traite });
    const c = (DEMO_COM || []).concat(DEMO_GG || []).find(x => x.id === id);
    if (c) { c.traite = !!traite; c.traiteLe = traite ? TODAY + ' ' + new Date().toTimeString().slice(0, 5) : null; c.traitePar = traite ? 'Vous' : ''; }
    return Promise.resolve({ ok: true });
  }
  /**
   * Les avis Google : la Réputation digitale du BO (note, cible, avis 5★ à obtenir, répartition des avis lus) et les avis
   * classés vert / orange / rouge (src/google.php). En démonstration : un petit réseau d'exemple.
   */
  let DEMO_GG = null;
  function demoGoogle(jours) {
    if (!DEMO_GG) {
      const h = (n) => addDays(TODAY, -n) + ' 10:00';
      DEMO_GG = [
        ['g1', '4', 'Halle', 'Paul R.', 1, 'Un cheveu dans ma tarte, et personne pour s’excuser.', h(3), 'rouge', 'Plainte hygiène', 'Client mécontent : un cheveu dans sa tarte, accueil froid.', 'Répondre sous l’avis : s’excuser et inviter à contacter la boutique.', 'Bonjour Paul, nous sommes sincèrement désolés. Ce n’est pas notre standard : contactez directement la boutique, nous voulons arranger cela.'],
        ['g2', '2', 'Corbais', 'Sophie L.', 3, 'Bons produits mais beaucoup d’attente le dimanche.', h(9), 'orange', 'Attente le week-end', 'Apprécie les produits, regrette l’attente du dimanche.', 'Remercier et expliquer ce qui est fait pour l’attente.', 'Merci Sophie pour votre retour ! Nous renforçons l’équipe le dimanche matin pour vous servir plus vite.'],
        ['g3', '4', 'Halle', 'Anne B.', 5, 'Délicieux, accueil parfait !', h(20), 'vert', 'Compliment', 'Très satisfaite des produits et de l’accueil.', 'Remercier sous l’avis.', 'Merci beaucoup Anne, à très bientôt chez L’Atelier By Halle !'],
        ['g4', '5', 'Sombreffe', 'Eva', 5, '', h(40), 'vert', 'Note sans texte', '5 étoiles sans commentaire.', 'Un merci court suffit.', 'Merci pour vos 5 étoiles !'],
      ].map(([id, shopId, boutique, auteur, note, message, le, couleur, sujet, resume, action, reponse]) => ({ id, canal: 'gg', shopId, boutique, compte: 'Google · ' + boutique, compteKey: 'gg:' + shopId,
        auteur, note, message, le, masque: false, publication: 'Fiche Google de L’Atelier By ' + boutique, lienPublication: '', lien: '', couleur, sujet, resume, action, reponse, analyse: 'ia', traite: false, traiteLe: null, traitePar: '' }));
    }
    const liste = DEMO_GG.filter(c => daysBetween(c.le.slice(0, 10), TODAY) < jours).map(c => Object.assign({}, c));
    const totaux = { rouge: 0, orange: 0, vert: 0, aClasser: 0, rougeOuverts: 0, orangeOuverts: 0, total: liste.length };
    liste.forEach(c => { totaux[c.couleur]++; if (!c.traite && c.couleur !== 'vert') totaux[c.couleur + 'Ouverts']++; });
    const rep = (par) => ({ lus: Object.values(par).reduce((a, b) => a + b, 0), niveaux: [5, 4, 3, 2, 1].map(n => ({ note: n, n: par[n] || 0 })) });
    const mag = (id, nom, note, avis, par, avis5) => ({ id, nom, langs: id === '4' ? ['nl', 'fr'] : ['fr'], note, avis, ecart: Math.round((note - 4.6) * 100) / 100, avis5Requis: avis5, repartition: rep(par), raccorde: true, url: '', synchro: TODAY + ' 07:00',
      lienAvis: 'https://search.google.com/local/writereview?placeid=demo' + id, lienCourt: location.origin + '/social/api/v1/social/avis/' + id, gagnes7: id === '4' ? 2 : 0, gagnes30: id === '4' ? 6 : 1, scans7: 3, scans30: 11 });
    return { jours, avis: liste, totaux, relu: { le: TODAY + ' 08:00', lus: 4 }, ia: { configure: true, modele: 'démonstration' }, demo: true, reglages: { actif: true, heure: 6, dernier: { le: TODAY + ' 06:07', ok: true, message: '3 fiche(s), 0 nouvel(s) avis' } },
      reputation: { cible: 4.6, connecteur: { configure: true, raccordes: 3, derniereSynchro: TODAY + ' 07:00' },
        reseau: { moyenne: 4.21, avis: 876, magasins: 3, notes: 3, sousCible: 2, avis5Requis: 856, repartition: rep({ 5: 2, 3: 1, 1: 1 }), gagnes7: 2, gagnes30: 8, scans7: 9, scans30: 33 }, suiviDepuis: null,
        magasins: [mag('4', 'Halle', 4.4, 50, { 5: 1, 1: 1 }, 25), mag('2', 'Corbais', 4.1, 754, { 3: 1 }, 943), mag('5', 'Sombreffe', 4.7, 72, { 5: 1 }, 0)] } };
  }
  function google(jours) {
    if (LIVE) return api('GET', '/google?jours=' + jours);
    return Promise.resolve(demoGoogle(jours));
  }
  function googleRelire(jours) {
    if (LIVE) return api('POST', '/google/relire', { jours });
    return Promise.resolve(Object.assign(demoGoogle(jours), { sync: { lus: 4 }, analyse: { classes: 0, ia: true, erreur: null } }));
  }
  function googleSynchroniser(jours) {
    if (LIVE) return api('POST', '/google/synchroniser', { jours });
    return Promise.resolve(Object.assign(demoGoogle(jours), { bo: { magasins: 3, nouveaux: 0, erreurs: [], aucuneFiche: false }, sync: { lus: 4 }, analyse: { classes: 0, ia: true, erreur: null } }));
  }
  function googleReglages(r) {
    if (LIVE) return api('POST', '/google/reglages', r);
    return Promise.resolve({ reglages: Object.assign({ dernier: null }, r) });
  }
  function suivi(jours) {
    if (LIVE) return api('GET', '/suivi/abonnes?jours=' + jours);
    const au = addDays(TODAY, -1), du = addDays(au, -(jours - 1)), lignes = [];
    const comptes = [{ id: '999000', name: "L'Atelier By", picture: null, shop: 'marque', ig: 'latelierby.be' }].concat(SHOPS.map(sh => ({ id: (META.ids[sh.id] || {}).page_id, name: sh.page || sh.name, picture: null, shop: sh.id, ig: String(sh.ig || '').replace(/^@/, '') })));
    comptes.forEach((c, k) => {
      const base = c.shop === 'marque' ? 9 : 2 + (hash(c.id) % 6), serieFb = {}, serieIg = {}, dep = {};
      let gF = 0, pF = 0, gI = 0, tot = 0;
      for (let d = du; d <= au; d = addDays(d, 1)) {
        const h = hash(c.id + d), boost = (h % 7 < 3) ? 1 : 0, v = c.shop === 'gosselies' ? 0 : Math.round((boost ? base * (2 + h % 3) : (h % 4 ? 0 : base)) * 100) / 100;
        if (v) { dep[d] = v; tot += v; }
        const g = Math.max(0, Math.round(v * (0.35 + (h % 5) / 10) + h % 3)), p = (h % 6 === 0 ? 2 + h % 3 : h % 3 === 0 ? 1 : 0) + (c.shop === 'gosselies' ? 1 : 0);
        serieFb[d] = { gagnes: g, perdus: p }; gF += g; pF += p;
        const gi = Math.max(0, Math.round(v * (0.5 + (h % 4) / 10) + (h % 2)));
        if (daysBetween(d, au) < 30) { serieIg[d] = { gagnes: gi, perdus: 0 }; gI += gi; }
      }
      const net = gF - pF + (c.shop === 'marque' ? gI : 0);
      lignes.push({ page: c.id, name: c.name, username: '', picture: c.picture, link: null, shop: c.shop, shopName: c.shop === 'marque' ? 'Marque' : (byId(SHOPS, c.shop) || {}).name,
        facebook: { followers: 1800 + hash(c.id) % 4200 + (c.shop === 'marque' ? 9000 : 0), gagnes: gF, perdus: pF, net: gF - pF, serie: serieFb, motif: null },
        instagram: c.shop !== 'marque' ? null : { id: '17841400000', username: c.ig, picture: null, followers: 9300 + hash(c.ig) % 3100, gagnes: gI, serie: serieIg, motif: jours > 30 ? 'Instagram ne donne que les 30 derniers jours' : null },
        depense: Math.round(tot * 100) / 100, depenseJours: dep, abonnesNet: net, coutParAbonne: net > 0 && tot > 0 ? Math.round(tot / net * 100) / 100 : null });
    });
    lignes.sort((a, b) => b.depense - a.depense);
    return Promise.resolve({ du, au, jours, devise: 'EUR', lignes, nonAttribue: 0, erreurs: [], le: TODAY + ' 10:00', demo: true });
  }
  function retryPost(id) {
    return api('POST', '/posts/' + encodeURIComponent(id) + '/relancer').then(r => {
      cache.posts = cache.posts.map(p => p.id === id ? r.post : p); base.posts[id] = JSON.stringify(r.post);
      window.dispatchEvent(new Event('sa-change')); return r.post;
    });
  }
  function testConnexions(shop) {
    return api('POST', '/connexions/test', shop ? { shop } : {}).then(r => {
      SHOPS.splice(0, SHOPS.length, ...r.shops.filter(x => !x.test)); TEST_SHOPS.splice(0, TEST_SHOPS.length, ...r.shops.filter(x => x.test)); cache.shops = r.shops; base.shops = snap(r.shops);
      return refresh().then(() => r.resultats);
    });
  }
  function notify(msg, err) {
    const show = () => {
      let el = document.getElementById('sa-notice');
      if (!el) { el = document.createElement('div'); el.id = 'sa-notice'; document.body.appendChild(el); }
      el.style.cssText = 'position:fixed;right:16px;bottom:16px;max-width:420px;z-index:9999;padding:10px 14px;border-radius:8px;font:13px/1.45 var(--font-ui,system-ui);box-shadow:0 4px 18px rgba(0,0,0,.12);'
        + (err ? 'background:#8D1D2C;color:#fff;' : 'background:#FCE7C8;color:#6b3d0a;');
      el.textContent = msg;
      if (err) { clearTimeout(el._t); el._t = setTimeout(() => el.remove(), 9000); }
    };
    if (document.body) show(); else document.addEventListener('DOMContentLoaded', show);
  }

  if (param('demo') !== '1') {
    try {
      const r = apiSync('GET', '/state');
      if (r.status === 200 && r.json && r.json.mode === 'live') apply(r.json);
      else if (r.status === 401 && r.json && r.json.login) { location.replace(r.json.login); }
      else why = (r.json && r.json.error) || ('API HTTP ' + r.status);
    } catch (e) { why = 'API injoignable'; }
  } else why = 'paramètre ?demo=1';
  if (!LIVE) notify('Mode démonstration — données d’exemple (' + why + '). Rien n’est publié chez Meta.');
  else window.addEventListener('focus', () => { refresh(); });

  window.SA = { __ready: true, live: !!LIVE, mode: LIVE ? 'live' : 'demo', user: LIVE ? LIVE.user : { name: 'Sarah Lambert', role: 'Franchiseur', shops: SHOPS.length },
    api, refresh, retryPost, testConnexions, importMeta, metaImport: () => IMPORT,
    commentaires, commentairesRelire, commentaireTraiter, commentaireRepondre, google, googleRelire, googleSynchroniser, googleReglages,
    comptesMeta: () => LIVE ? api('GET', '/meta/comptes').then(r => { META.comptes = (r && r.comptes) || META.comptes; return META.comptes; }) : Promise.resolve(META.comptes), calendriers, postStats, supprimerPost, catalogueNav, catalogueOffres, photoLocale, lienTexte, placerLien, LIEN_DEF, charte, relire, ia: () => (LIVE && LIVE.ia) || { configure: false, modele: '' }, suivi, avatar,
    // Instagram unique : un seul compte (la marque) pour tout le réseau — décidé par le serveur ; la démonstration fait de même.
    // Opérations = campagnes du Marketing du BO : lien vers l'assistant, erreur de lecture de l'API.
    ctype, rattacher, webshopUrl, opsLink: () => LIVE && LIVE.operations ? LIVE.operations.lien : '/consulant_bo/assistant/', opsErreur: () => LIVE && LIVE.operations ? LIVE.operations.erreur : null,
    igUnique: () => LIVE ? !!LIVE.instagramUnique : true, reseau, marque, saved: () => queue, DARK, who: () => LIVE ? LIVE.user.name : 'Sarah Lambert (Franchiseur)', boUrl: LIVE ? LIVE.boUrl : null, metaStatus: LIVE ? LIVE.metaStatus : null,
    TODAY, LANGS, VDEF, langsOf, pname, META, LF_Q, LEADFORMS, LEADS, cents, SHOPS, TEST_SHOPS, GROUPS, LEVERS, CTYPES, POST_TYPES, CATALOGUE, MARGIN, RESULTS, TEMPLATES, ROLES, STATUS, AD_STATUS, CONN, RAG,
    byId, fmt, eur, argent, devSym, pct, d2, iso, addDays, daysBetween, MONTHS, DAYS, dLabel, dShort, shopLabel, roi, ragOf, state, save, reset, log, alerts, fill, href, param, embed };
})();
