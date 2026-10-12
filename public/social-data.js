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
    { id: 'brioche', kind: 'produit', name: 'Brioche croustillante', nameNl: 'Krokante brioche', price: '4,20', img: 'img/products/brioche-croustillante.png', gamme: 'Gamme Automne' },
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

  const CAMPAIGNS = [
    { id: 'c-automne', name: 'Gamme Automne 2026', type: 'saison', start: '2026-09-28', end: '2026-10-25', status: 'en cours',
      shops: SHOPS.map(s => s.id), products: ['Brioche croustillante', 'Tarte aux pommes', 'Tartelette poire-amande'],
      goal: { metric: 'CA additionnel', value: 12000 }, budget: 2400,
      split: { halle: 450, corbais: 350, gosselies: 400, sombreffe: 300, gembloux: 400, wavre: 500 } },
    { id: 'c-rentree', name: 'Rentrée · Goûter malin', type: 'acquisition', start: '2026-08-24', end: '2026-09-13', status: 'terminée',
      shops: SHOPS.map(s => s.id), products: ['Cookies du goûter', 'Cupcake vanille'],
      goal: { metric: 'CA additionnel', value: 6000 }, budget: 1500,
      split: { halle: 260, corbais: 220, gosselies: 250, sombreffe: 190, gembloux: 260, wavre: 320 } },
    { id: 'c-limonade', name: 'Lancement Limonade maison', type: 'lancement', start: '2026-06-15', end: '2026-07-12', status: 'terminée',
      shops: SHOPS.map(s => s.id), products: ['Limonade maison'],
      goal: { metric: 'CA additionnel', value: 7000 }, budget: 1800,
      split: { halle: 330, corbais: 280, gosselies: 310, sombreffe: 240, gembloux: 300, wavre: 340 } },
  ];

  // Résultats par campagne × boutique. Meta : spend, reach, impr, clicks, eng, vv. Ventes : CA / tickets / qté — campagne vs référence (moy. mêmes jours des 6 semaines précédentes).
  const R = (spend, reach, impr, clicks, eng, vv, caRef, caCamp, tRef, tCamp, qRef, qCamp) => ({ spend, reach, impr, clicks, eng, vv, caRef, caCamp, tRef, tCamp, qRef, qCamp });
  const RESULTS = {
    'c-automne': {
      halle: R(190, 12100, 33800, 720, 1540, 4100, 3520, 4390, 830, 1010, 420, 640),
      corbais: R(150, 9800, 27100, 560, 1190, 3200, 2870, 3420, 690, 800, 340, 500),
      gosselies: R(175, 10200, 28800, 590, 1220, 3300, 3210, 3640, 760, 850, 380, 500),
      sombreffe: R(130, 7900, 21900, 450, 920, 2600, 2240, 2470, 540, 590, 270, 330),
      gembloux: R(185, 11800, 32700, 700, 1480, 3900, 3300, 4120, 790, 960, 400, 600),
      wavre: R(230, 14900, 41500, 900, 1910, 5100, 3960, 5020, 940, 1150, 470, 700),
    },
    'c-rentree': {
      halle: R(260, 18400, 52100, 1120, 2310, 6400, 4820, 6610, 1140, 1530, 610, 980),
      corbais: R(220, 14900, 41800, 870, 1760, 5100, 3910, 5420, 960, 1290, 480, 790),
      gosselies: R(250, 17100, 49200, 990, 2040, 5900, 4400, 5790, 1050, 1370, 560, 860),
      sombreffe: R(180, 11200, 31500, 610, 1190, 3500, 2950, 3560, 720, 860, 360, 470),
      gembloux: R(260, 16800, 47700, 1010, 2120, 6100, 4510, 6420, 1080, 1490, 590, 940),
      wavre: R(310, 21400, 60100, 1290, 2650, 7400, 5230, 7330, 1250, 1720, 680, 1090),
    },
    'c-limonade': {
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
    P('p01', '2026-09-28', '08:00', 'Gamme saisonnière', 'La gamme Automne est arrivée', 'img/illus/seasonal/autumn-range.png', ALL, 'Publié', { campaign: 'c-automne', text: "La gamme Automne est arrivée chez L'Atelier By {ville}. Brioche croustillante, tarte aux pommes et tartelette poire-amande, à découvrir dès ce matin." }),
    P('p02', '2026-10-01', '07:30', 'Produit', 'Brioche croustillante', 'img/products/brioche-croustillante.png', ALL, 'Publié', { campaign: 'c-automne', text: 'Brioche filée, cœur de myrtille et crumble citronné. {prix} € la pièce à {ville}.' }),
    P('p03', '2026-10-02', '11:00', 'Recrutement', 'On recrute à Wavre', 'img/illus/customer-facing/b2b.png', ['wavre'], 'Publié', { text: "L'Atelier By Wavre recrute un·e vendeur·se à temps partiel. Passez nous voir au {adresse}." }),
    P('p04', '2026-10-03', '16:30', 'Anti-gaspi', 'Panier anti-gaspi du samedi', 'img/products/bread-3.png', ['corbais', 'gembloux'], 'Publié', { text: 'Dès 17h, nos invendus du jour en panier surprise à 5 €.' }),
    P('p05', '2026-10-04', '09:00', 'Événement local', 'Marché de Halle', 'img/illus/seasonal/crepe-festival.png', ['halle'], 'Publié', { text: 'Retrouvez-nous au marché de Halle ce dimanche.' }),
    P('p06', '2026-10-05', '08:00', 'Produit', 'Tarte aux pommes', 'img/products/sweet-tart-big.png', ALL, 'Publié', { campaign: 'c-automne', partial: { ok: ['halle', 'corbais', 'sombreffe', 'gembloux', 'wavre'], ko: [{ shop: 'gosselies', channel: 'Instagram', reason: 'Token Instagram expiré (code 190)' }] } }),
    P('p07', '2026-10-07', '07:30', 'Vidéo / film', 'Film · le geste du boulanger', 'img/illus/products/bread-2.png', ALL, 'Publié', { campaign: 'c-automne' }),
    P('p08', '2026-10-08', '12:00', 'Produit', 'Quiche du jour', 'img/products/savoury-tart.png', ['gosselies', 'sombreffe'], 'Échec', { error: 'Meta a refusé la publication : la Page Sombreffe a restreint les publications programmées (code 368).', failedShops: ['sombreffe'] }),
    P('p09', '2026-10-09', '07:30', 'Produit', 'Tartelette poire-amande', 'img/products/sweet-tart-small.png', ALL, 'Publié', { campaign: 'c-automne', partial: { ok: ['halle', 'corbais', 'gembloux', 'wavre'], ko: [{ shop: 'gosselies', channel: 'Instagram', reason: 'Token Instagram expiré (code 190)' }, { shop: 'sombreffe', channel: 'Facebook', reason: 'Délai dépassé côté Graph API (code 2) — relance possible' }] } }),
    P('p10', '2026-10-10', '08:00', 'Gamme saisonnière', 'Le week-end gourmand d\u2019automne', 'img/illus/fruits/apple.png', ALL, 'Publié', { campaign: 'c-automne' }),
    P('p11', '2026-10-11', '09:30', 'Événement local', 'Brunch du dimanche à Corbais', 'img/illus/products/cake-slice.png', ['corbais'], 'Programmé'),
    P('p12', '2026-10-12', '07:30', 'Produit', 'Croissant pur beurre', 'img/products/croissant.png', ['halle', 'wavre'], 'Programmé'),
    P('p13', '2026-10-13', '16:00', 'Anti-gaspi', 'Panier anti-gaspi', 'img/products/bread-4.png', ['gosselies'], 'À valider', { author: 'Manager Gosselies' }),
    P('p14', '2026-10-14', '11:00', 'Libre', 'Les coulisses de l\u2019atelier', 'img/illus/decorative/artboard-4.png', ['wavre'], 'Brouillon'),
    P('p15', '2026-10-15', '07:30', 'Produit', 'Pain au levain', 'img/products/bread-1.png', ['halle', 'corbais'], 'Programmé'),
    P('p16', '2026-10-16', '12:00', 'Événement local', 'Dégustation au marché de Wavre', 'img/illus/products/rolls.png', ['wavre'], 'À valider', { author: 'Manager Wavre' }),
    P('p17', '2026-10-18', '08:00', 'Produit', 'Brioche croustillante · J-7', 'img/products/brioche-croustillante.png', ALL, 'Programmé', { campaign: 'c-automne' }),
    P('p18', '2026-10-20', '07:30', 'Vidéo / film', 'Film · tarte aux pommes', 'img/products/sweet-tart-big.png', ALL, 'Programmé', { campaign: 'c-automne' }),
    P('p19', '2026-10-22', '11:00', 'Recrutement', 'On recrute à Gembloux', 'img/illus/customer-facing/office-delivery.png', ['gembloux'], 'Brouillon'),
    P('p20', '2026-10-24', '08:00', 'Gamme saisonnière', 'Derniers jours de la gamme Automne', 'img/illus/seasonal/autumn-range.png', ALL, 'Programmé', { campaign: 'c-automne' }),
    P('p21', '2026-10-27', '09:00', 'Libre', 'Halloween : biscuits citrouille', 'img/products/pumpkin.png', ALL, 'Brouillon'),
    P('p22', '2026-10-30', '16:30', 'Anti-gaspi', 'Panier anti-gaspi', 'img/products/bread-5.png', ['corbais', 'gembloux', 'sombreffe'], 'Programmé'),
    P('p23', '2026-08-24', '08:00', 'Gamme saisonnière', 'C\u2019est la rentrée du goûter', 'img/products/cookies.png', ALL, 'Publié', { campaign: 'c-rentree' }),
    P('p24', '2026-09-01', '15:30', 'Produit', 'Cupcake vanille', 'img/products/cupcake.png', ALL, 'Publié', { campaign: 'c-rentree' }),
    P('p25', '2026-09-08', '15:30', 'Vidéo / film', 'Film · goûter malin', 'img/products/cookies.png', ALL, 'Publié', { campaign: 'c-rentree' }),
    P('p26', '2026-06-15', '10:00', 'Produit', 'Nouveau : limonade maison', 'img/products/lemonade-soda.png', ALL, 'Publié', { campaign: 'c-limonade' }),
    P('p27', '2026-06-29', '12:00', 'Vidéo / film', 'Film · limonade', 'img/products/lemonade-soda.png', ALL, 'Publié', { campaign: 'c-limonade' }),
  ];

  const ADS = [
    { id: 'a01', name: 'Automne · trafic magasin', campaign: 'c-automne', objective: 'Trafic en magasin', shops: ALL, scope: 'boutique', budgetType: 'total', budget: 1600, cap: 1800, spent: 760, start: '2026-09-28', end: '2026-10-25', status: 'Active', radius: 8, post: 'p02' },
    { id: 'a02', name: 'Automne · vidéo notoriété', campaign: 'c-automne', objective: 'Notoriété locale', shops: ALL, scope: 'reseau', budgetType: 'quotidien', budget: 25, cap: 700, spent: 300, start: '2026-10-07', end: '2026-10-25', status: 'Active', radius: 12, post: 'p07' },
    { id: 'a03', name: 'Automne · relance J-7', campaign: 'c-automne', objective: 'Trafic en magasin', shops: ALL, scope: 'boutique', budgetType: 'total', budget: 400, cap: 400, spent: 0, start: '2026-10-18', end: '2026-10-25', status: 'En revue', radius: 8, post: 'p17' },
    { id: 'a04', name: 'Quiche du jour · midi', campaign: null, objective: 'Messages', shops: ['gosselies'], scope: 'boutique', budgetType: 'quotidien', budget: 8, cap: 120, spent: 0, start: '2026-10-08', end: '2026-10-22', status: 'Refusée', reason: "Texte de l'image supérieur à 20 % et promesse de prix non vérifiable (politique Publicités Meta 4.3).", radius: 5, post: 'p08' },
    { id: 'a05', name: 'Recrutement Wavre', campaign: null, objective: 'Notoriété locale', shops: ['wavre'], scope: 'boutique', budgetType: 'total', budget: 150, cap: 150, spent: 150, start: '2026-10-02', end: '2026-10-12', status: 'Budget épuisé', radius: 15, post: 'p03' },
    { id: 'a06', name: 'Goûter malin · trafic', campaign: 'c-rentree', objective: 'Trafic en magasin', shops: ALL, scope: 'boutique', budgetType: 'total', budget: 1500, cap: 1500, spent: 1480, start: '2026-08-24', end: '2026-09-13', status: 'Terminée', radius: 8, post: 'p23' },
    { id: 'a07', name: 'Limonade · lancement', campaign: 'c-limonade', objective: 'Visites du webshop', shops: ALL, scope: 'reseau', budgetType: 'total', budget: 1800, cap: 1800, spent: 1760, start: '2026-06-15', end: '2026-07-12', status: 'Terminée', radius: 10, post: 'p26' },
  ];

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
  const byId = (arr, id) => arr.find(x => x.id === id);
  const fmt = (n, d = 0) => n == null || !isFinite(n) ? '—' : n.toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const eur = (n, d = 0) => n == null || !isFinite(n) ? '—' : fmt(n, d) + ' €';
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

  // Calcul du retour : renvoie {perShop, total} pour une campagne.
  function roi(cid) {
    const c = byId(state().campaigns, cid); if (!c) return null;
    const res = RESULTS[cid] || {};
    const elapsed = c.status === 'en cours' ? Math.min(1, (daysBetween(c.start, TODAY) + 1) / (daysBetween(c.start, c.end) + 1)) : 1;
    const perShop = c.shops.map(sid => {
      const r = res[sid] || null, shop = byId(SHOPS, sid) || { name: sid };
      const goal = c.goal.value * ((c.split[sid] || 0) / (c.budget || 1)) * elapsed;
      if (!r) return { sid, name: shop.name, empty: true };
      const has = r.caRef != null;
      const caAdd = has ? r.caCamp - r.caRef : null, mAdd = has ? caAdd * MARGIN : null, tAdd = has ? r.tCamp - r.tRef : null;
      const o = { sid, name: shop.name, ...r, cpc: r.clicks ? r.spend / r.clicks : null, ctr: r.impr ? r.clicks / r.impr : null, cpm: r.impr ? r.spend / r.impr * 1000 : null, freq: r.reach ? r.impr / r.reach : null, caAdd, mAdd, tAdd, qAdd: has ? r.qCamp - r.qRef : null,
        roas: has && r.spend ? caAdd / r.spend : null, roi: has && r.spend ? (mAdd - r.spend) / r.spend : null, cpt: has && tAdd > 0 ? r.spend / tAdd : null,
        goal, att: has ? caAdd / goal : null };
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
    t.goal = c.goal.value * elapsed; t.att = t.caAdd / t.goal; t.rag = ragOf(t.att); t.elapsed = elapsed; t.missing = perShop.filter(x => x.caRef == null).map(x => x.name);
    return { c, perShop, total: t };
  }

  // ---------- état persistant (localStorage) ----------
  function fresh() { return { posts: JSON.parse(JSON.stringify(POSTS)), ads: JSON.parse(JSON.stringify(ADS)), campaigns: JSON.parse(JSON.stringify(CAMPAIGNS)), shops: JSON.parse(JSON.stringify(SHOPS)), activity: ACTIVITY.slice(), leadforms: JSON.parse(JSON.stringify(LEADFORMS)), ftemplates: JSON.parse(JSON.stringify(FTEMPLATES)), franchise: { rules: Object.assign({}, FRULES), access: [], url: '../social_franchisee/' }, meta: { version: META.version, dsa: Object.assign({}, META.dsa), ids: JSON.parse(JSON.stringify(META.ids)) } }; }
  let cache = null;
  function state() {
    if (cache) return cache;
    try { const s = JSON.parse(localStorage.getItem(KEY) || 'null'); if (s && s.posts && s.ads && s.campaigns && s.shops) { s.shops.forEach(x => { if (!x.langs) { const b = SHOPS.find(y => y.id === x.id); x.langs = b ? b.langs.slice() : ['fr']; if (b && b.hoursNl) x.hoursNl = b.hoursNl; } }); if (!s.leadforms) s.leadforms = JSON.parse(JSON.stringify(LEADFORMS)); if (!s.ftemplates) s.ftemplates = JSON.parse(JSON.stringify(FTEMPLATES)); if (!s.franchise) s.franchise = { rules: Object.assign({}, FRULES), access: [], url: '../social_franchisee/' }; if (!s.meta) s.meta = { version: META.version, dsa: Object.assign({}, META.dsa), ids: JSON.parse(JSON.stringify(META.ids)) }; cache = s; return s; } } catch (e) {}
    cache = fresh(); return cache;
  }
  function save(patch) {
    cache = Object.assign({}, state(), patch);
    if (LIVE) push(patch); else { try { localStorage.setItem(KEY, JSON.stringify(cache)); } catch (e) {} }
    window.dispatchEvent(new Event('sa-change'));
    return cache;
  }
  function reset() {
    if (LIVE) { refresh(); return; }  // en réel, on relit le serveur : rien ne s'efface
    cache = fresh(); try { localStorage.removeItem(KEY); } catch (e) {} window.dispatchEvent(new Event('sa-change'));
  }
  // Les écrans écrivent au nom de « Sarah Lambert » (maquette) : en réel, c'est l'utilisateur connecté.
  const who_ = (who) => LIVE && /^Sarah Lambert/.test(who || '') ? LIVE.user.name : who;
  function log(who, what, detail) { const now = new Date(); const at = TODAY + ' ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'); save({ activity: [{ at, who: who_(who), what, detail }].concat(state().activity) }); }
  function alerts() {
    const out = [];
    state().shops.forEach(s => {
      if (s.fb !== 'connected') out.push({ shop: s.name, what: 'Page Facebook', st: s.fb });
      if (s.igs !== 'connected') out.push({ shop: s.name, what: 'Instagram', st: s.igs });
      if (s.ads !== 'connected') out.push({ shop: s.name, what: 'Compte publicitaire', st: s.ads });
    });
    return out;
  }
  const LANGS = { fr: 'Français', nl: 'Nederlands' };
  const VDEF = { fr: { eyebrow: 'Édition saisonnière · Automne 2026', unit: 'La pièce', promoSub: 'la 5ᵉ offerte' }, nl: { eyebrow: 'Seizoenseditie · Herfst 2026', unit: 'Per stuk', promoSub: 'de 5de gratis' } };
  const langsOf = (shop) => { const s = typeof shop === 'string' ? byId(state().shops, shop) : shop; return (s && s.langs && s.langs.length) ? s.langs : ['fr']; };
  const pname = (p, lang) => p ? (lang === 'nl' && p.nameNl ? p.nameNl : p.name) : '';
  function fill(text, shop, product, lang) {
    const s = typeof shop === 'string' ? byId(state().shops, shop) : shop;
    const L = lang || (s ? langsOf(s)[0] : 'fr');
    return String(text || '')
      .replace(/\{horaires\}/g, s ? (L === 'nl' && s.hoursNl ? s.hoursNl : s.hours) : '{horaires}')
      .replace(/\{produit_du_jour\}/g, product && product.name ? pname(product, L) : (L === 'nl' ? 'Krokante brioche' : 'Brioche croustillante'))
      .replace(/\{ville\}/g, s ? s.name : '{ville}').replace(/\{adresse\}/g, s ? s.address : '{adresse}').replace(/\{horaires\}/g, s ? s.hours : '{horaires}')
      .replace(/\{prix\}/g, product && product.price ? product.price : '{prix}').replace(/\{produit_du_jour\}/g, product && product.name ? product.name : 'Brioche croustillante');
  }
  const PAGES = { cal: 'SA Calendrier.dc.html', grid: 'SA Grille.dc.html', fr: 'SA Franchise.dc.html', post: 'SA Post.dc.html', camp: 'SA Campagnes.dc.html', ads: 'SA Publicites.dc.html', leads: 'SA Leads.dc.html', roi: 'SA Retour.dc.html', set: 'SA Parametres.dc.html' };
  const href = (k, q) => encodeURI(PAGES[k]) + (q ? '?' + q : '');
  const param = (k) => { try { return new URLSearchParams(location.search).get(k); } catch (e) { return null; } };

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
  const FRULES = {"actif": true, "validation": "modele", "quotaSemaine": 3, "delaiMinHeures": 24, "horizonJours": 30, "jours": [1, 2, 3, 4, 5, 6, 0], "heureMin": "07:00", "heureMax": "20:00", "fb": true, "ig": true, "remiseMax": 30, "promoDureeMax": 14, "voirReseau": true, "voirCampagnes": true, "voirResultats": true, "message": "Bienvenue dans votre espace communication. Choisissez un modèle, complétez les champs : le visuel et le texte suivent la charte de L’Atelier By."};
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
    LIVE = L; TODAY = L.today || TODAY; MARGIN = L.margin != null ? +L.margin : MARGIN;
    const swap = (arr, v) => { arr.splice(0, arr.length, ...(v || [])); };
    swap(SHOPS, L.shops); swap(GROUPS, L.groups); swap(TEMPLATES, L.templates); swap(LEADFORMS, L.leadforms); swap(LEADS, L.leads); swap(DARK, L.darkPosts);
    if (L.catalogue && L.catalogue.length) swap(CATALOGUE, L.catalogue);
    Object.keys(RESULTS).forEach(k => delete RESULTS[k]); Object.assign(RESULTS, L.results || {});
    const m = L.meta || {};
    META.version = m.version || META.version; META.dsa = m.dsa || META.dsa; META.ids = m.ids || {}; META.networkAccount = m.networkAccount || '';
    if (META.versions.indexOf(META.version) < 0) META.versions = META.versions.concat(META.version);
    // Sans position connue, l'assistant Publicités garde un centre (Bruxelles) pour son aperçu ;
    // le serveur, lui, refuse de créer une pub sans la vraie position (ou l'adresse géocodée).
    META.geo = {}; SHOPS.forEach(s => { META.geo[s.id] = (m.geo && m.geo[s.id]) || [50.8466, 4.3528]; });
    cache = { posts: L.posts || [], ads: L.ads || [], campaigns: L.campaigns || [], shops: L.shops || [], activity: L.activity || [], leadforms: L.leadforms || [],
      groups: L.groups || [], templates: L.templates || [], leads: L.leads || [], ftemplates: L.ftemplates || [], franchise: L.franchise || { rules: {}, access: [] }, params: L.params || {}, tfb: L.tfb || {}, tfbAudit: L.tfbAudit || {}, brand: L.brand || null, meta: { version: META.version, dsa: META.dsa, ids: META.ids, networkAccount: META.networkAccount, lastCheck: m.lastCheck } };
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
  function retryPost(id) {
    return api('POST', '/posts/' + encodeURIComponent(id) + '/relancer').then(r => {
      cache.posts = cache.posts.map(p => p.id === id ? r.post : p); base.posts[id] = JSON.stringify(r.post);
      window.dispatchEvent(new Event('sa-change')); return r.post;
    });
  }
  function testConnexions(shop) {
    return api('POST', '/connexions/test', shop ? { shop } : {}).then(r => {
      SHOPS.splice(0, SHOPS.length, ...r.shops); cache.shops = r.shops; base.shops = snap(r.shops);
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
    api, refresh, retryPost, testConnexions, saved: () => queue, DARK, who: () => LIVE ? LIVE.user.name : 'Sarah Lambert (Franchiseur)', boUrl: LIVE ? LIVE.boUrl : null, metaStatus: LIVE ? LIVE.metaStatus : null,
    TODAY, LANGS, VDEF, langsOf, pname, META, LF_Q, LEADFORMS, LEADS, cents, SHOPS, GROUPS, LEVERS, CTYPES, POST_TYPES, CATALOGUE, MARGIN, RESULTS, TEMPLATES, ROLES, STATUS, AD_STATUS, CONN, RAG,
    byId, fmt, eur, pct, d2, iso, addDays, daysBetween, MONTHS, DAYS, dLabel, dShort, shopLabel, roi, ragOf, state, save, reset, log, alerts, fill, href, param };
})();
