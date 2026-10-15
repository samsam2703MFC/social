// Espace franchisé — couche de données.
//
// En réel : l'état de la boutique est lu à l'API du module Social & Ads
// (GET {api}/franchise/etat?shop=…). Pas de connexion propre à l'espace : le gérant
// y entre depuis l'ERP, déjà connecté (ou le franchiseur, en aperçu). Sans session :
// un message renvoie vers l'ERP.
// En démonstration (?demo=1) : des données d'exemple, gardées dans ce navigateur.
(function () {
  if (window.SF) return;
  const CFG = Object.assign({ api: '/social/api/v1/social', media: '/social/' }, window.SF_CONFIG || {});
  const Q = new URLSearchParams(location.search);
  // Les pages, sous leur adresse propre (sans « .dc.html ») quand le serveur la sert (`propres`, écrit par
  // bin/franchisee-build.sh ; .htaccess) : /social_franchisee/calendrier, publier, operations, instagram.
  const FICHIERS = { cal: 'Calendrier.dc.html', pub: 'Publier.dc.html', camp: 'Campagnes.dc.html', ig: 'Instagram.dc.html' };
  const PROPRES = { cal: 'calendrier', pub: 'publier', camp: 'operations', ig: 'instagram' };
  const PAGES = CFG.propres ? PROPRES : FICHIERS;
  PAGES.suivi = PAGES.cal;  // le suivi est un onglet du calendrier (?vue=suivi)
  // Une ancienne adresse (lien de l'ERP, favori) s'affiche sous son nom propre ; elle reste valable.
  if (CFG.propres) {
    try { const f = decodeURIComponent(location.pathname.split('/').pop()), k = Object.keys(FICHIERS).find(x => FICHIERS[x] === f);
      if (k) history.replaceState(history.state, '', location.pathname.replace(/[^/]*$/, PROPRES[k]) + location.search + location.hash); } catch (e) {}
  }
  const HORS_SESSION = 'Ouvrez votre espace boutique depuis l’ERP : vous y êtes déjà connecté.';
  let DEMO = Q.has('demo');
  try { if (!DEMO && sessionStorage.getItem('sf.demo') === '1' && !Q.has('live')) DEMO = true; if (DEMO) sessionStorage.setItem('sf.demo', '1'); else sessionStorage.removeItem('sf.demo'); } catch (e) {}

  // ---------- dates ----------
  const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const d2 = (s) => { const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(NaN); };
  const addDays = (s, n) => { const d = d2(s); d.setDate(d.getDate() + n); return iso(d); };
  const dLabel = (s) => { const d = d2(s); return isNaN(d) ? '' : DAYS[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()].slice(0, 4) + (MONTHS[d.getMonth()].length > 4 ? '.' : ''); };
  const dShort = (s) => { const d = d2(s); return isNaN(d) ? '' : pad(d.getDate()) + '/' + pad(d.getMonth() + 1); };
  const at = (date, time) => { const d = d2(date); const [h, m] = String(time || '00:00').split(':').map(Number); d.setHours(h || 0, m || 0, 0, 0); return d.getTime(); };
  const eur = (n) => n == null || isNaN(n) ? '—' : Math.round(n).toLocaleString('fr-BE') + ' €';

  // ---------- démonstration ----------
  const TEMPLATES = [{"id": "fp-produit", "order": 1, "name": "Produit du jour", "description": "Mettre un produit de votre vitrine en avant, à son prix.", "active": true, "type": "Produit", "fb": true, "ig": true, "validation": false, "title": "{produit}", "text": "{produit} : {prix} € chez L’Atelier By {ville}. Ouvert {horaires}.", "textNl": "{produit}: {prix} € bij L’Atelier By {ville}. Open {horaires}.", "product": "choix", "promo": {"kind": "aucune"}, "period": false, "free": 0, "photo": "choix", "visual": {"style": "epure", "eyebrow": "Aujourd’hui en vitrine", "eyebrowNl": "Vandaag in de etalage"}}, {"id": "fp-remise", "order": 2, "name": "Promotion en %", "description": "Une remise sur un produit, pour une durée limitée.", "active": true, "type": "Produit", "fb": true, "ig": true, "validation": true, "title": "{produit} -{remise} %", "text": "-{remise} % sur {produit} chez L’Atelier By {ville}, du {du} au {au}. Ouvert {horaires}.", "textNl": "-{remise} % op {produit} bij L’Atelier By {ville}, van {du} tot {au}. Open {horaires}.", "product": "choix", "promo": {"kind": "remise", "min": 10, "max": 30}, "period": true, "free": 0, "photo": "choix", "visual": {"style": "ruby", "eyebrow": "Promotion", "eyebrowNl": "Promotie"}}, {"id": "fp-offre", "order": 3, "name": "Offre spéciale", "description": "4+1, 2+1… sur un produit, pour une durée limitée.", "active": true, "type": "Produit", "fb": true, "ig": true, "validation": true, "title": "{offre} · {produit}", "text": "{offre} sur {produit} chez L’Atelier By {ville}, du {du} au {au} !", "textNl": "{offre} op {produit} bij L’Atelier By {ville}, van {du} tot {au}!", "product": "choix", "promo": {"kind": "offre", "offres": ["4+1", "2+1", "1 acheté = 1 offert"]}, "period": true, "free": 0, "photo": "choix", "visual": {"style": "abricot", "eyebrow": "Offre spéciale", "eyebrowNl": "Speciale actie"}}, {"id": "fp-evenement", "order": 4, "name": "Événement en boutique", "description": "Une dégustation, une animation, un horaire spécial.", "active": true, "type": "Événement local", "fb": true, "ig": true, "validation": true, "title": "Événement à {ville}", "text": "Chez L’Atelier By {ville} : {libre}", "textNl": "Bij L’Atelier By {ville}: {libre}", "product": "aucun", "promo": {"kind": "aucune"}, "period": false, "free": 90, "photo": "upload", "visual": {"style": "noir", "eyebrow": "En boutique", "eyebrowNl": "In de winkel"}}, {"id": "fp-vitrine", "order": 5, "name": "Nouveau en vitrine", "description": "Votre photo d’un produit qui arrive.", "active": true, "type": "Produit", "fb": true, "ig": true, "validation": false, "title": "Nouveau : {produit}", "text": "Nouveau dans notre vitrine à {ville} : {produit}. À découvrir {horaires} !", "textNl": "Nieuw in onze etalage in {ville}: {produit}. Te ontdekken {horaires}!", "product": "choix", "promo": {"kind": "aucune"}, "period": false, "free": 0, "photo": "upload", "visual": {"style": "plein", "eyebrow": "Nouveau", "eyebrowNl": "Nieuw"}}];
  const RULES = {"actif": true, "validation": "modele", "quotaSemaine": 3, "delaiMinHeures": 24, "horizonJours": 30, "jours": [1, 2, 3, 4, 5, 6, 0], "heureMin": "07:00", "heureMax": "20:00", "fb": true, "ig": true, "remiseMax": 30, "promoDureeMax": 14, "voirReseau": true, "voirCampagnes": true, "voirResultats": true, "voirSuivi": true, "voirAnalyse": true, "persoVisuel": true, "message": "Bienvenue dans votre espace communication. Choisissez un modèle, complétez les champs : le visuel et le texte suivent la charte de L’Atelier By."};
  // Un modèle créé depuis un post du réseau (« Créer un post » → « Enregistrer comme modèle franchisé ») : visuel figé.
  TEMPLATES.push({ id: 'ft-automne', order: 6, name: 'Gamme automne (visuel du réseau)', description: 'Le post du réseau, prêt à publier sur votre Page.', active: true, type: 'Gamme saisonnière',
    fb: true, ig: false, validation: false, title: 'Gamme automne', text: 'La gamme automne est arrivée chez L’Atelier By {ville} ! Ouvert {horaires}.\n\n{libre}', textNl: 'Het herfstgamma is er bij L’Atelier By {ville}! Open {horaires}.\n\n{libre}',
    product: 'aucun', promo: { kind: 'aucune' }, period: false, free: 90, photo: 'aucune',
    visual: { style: 'fixe', src: 'img/illus/seasonal/autumn-range.png', media: [{ src: 'img/illus/seasonal/autumn-range.png', kind: 'img' }] } });
  function demoState() {
    const today = iso(new Date()), t = (n) => addDays(today, n);
    const cat = [
      { id: 'p1', apiId: 1410006, webshop: true, categoryId: 14100, name: 'Brioche croustillante', nameNl: 'Krokante brioche', price: '4,20', img: 'img/products/brioche-croustillante.png', gamme: 'Viennoiseries' },
      { id: 'p2', name: 'Tarte aux pommes', nameNl: 'Appeltaart', price: '18,50', img: 'img/products/sweet-tart-big.png', gamme: 'Pâtisseries' },
      { id: 'p3', name: 'Croissant pur beurre', nameNl: 'Botercroissant', price: '1,30', img: 'img/products/croissant.png', gamme: 'Viennoiseries' },
      { id: 'p4', name: 'Cookies du goûter', nameNl: 'Vieruurtjescookies', price: '2,20', img: 'img/products/cookies.png', gamme: 'Goûter' },
      { id: 'p5', name: 'Cupcake vanille', nameNl: 'Vanillecupcake', price: '3,40', img: 'img/products/cupcake.png', gamme: 'Goûter' },
      { id: 'p6', name: 'Quiche du jour', nameNl: 'Quiche van de dag', price: '5,90', img: 'img/products/savoury-tart.png', gamme: 'Salé' },
    ];
    const P = (id, date, time, title, status, own, extra) => Object.assign({ id, date, time, title, status, own, type: 'Produit', img: 'img/products/brioche-croustillante.png', text: '', textNl: '', fb: true, ig: true, template: '', promo: null, refus: null, reason: null, campaign: null }, extra || {});
    return {
      today, now: pad(new Date().getHours()) + ':' + pad(new Date().getMinutes()), mode: 'demo',
      me: { name: 'Nathan Charlot (démo)', via: 'demo', shops: [{ id: 'halle', name: 'Halle' }, { id: 'wavre', name: 'Wavre' }] },
      shop: { id: 'halle', name: 'Halle', langs: ['nl', 'fr'], hours: '7h00–18h30', hoursNl: '7u00–18u30', address: 'Basiliekstraat 12, 1500 Halle', page: 'L’Atelier By Halle', ig: '@latelierby.halle', fb: 'connected', igs: 'connected' },
      rules: Object.assign({}, RULES, { ig: false }), templates: JSON.parse(JSON.stringify(TEMPLATES)), catalogue: cat,
      posts: [
        P('n1', t(-6), '08:00', 'Gamme automne', 'Publié', false, { img: 'img/illus/seasonal/autumn-range.png', campaign: 'bo-12', text: 'La gamme automne est arrivée chez L’Atelier By {ville}.' }),
        P('n2', t(2), '08:00', 'Brunch du dimanche', 'Programmé', false, { img: 'img/products/cupcake.png' }),
        P('n3', t(9), '09:30', 'Halloween : biscuits citrouille', 'Programmé', false, { img: 'img/products/cookies.png' }),
        P('f1', t(-3), '10:00', 'Croissant pur beurre', 'Publié', true, { template: 'fp-produit', img: 'img/products/croissant.png' }),
        P('f2', t(4), '10:00', 'Brioche croustillante -20 %', 'À valider', true, { template: 'fp-remise', promo: { kind: 'remise', value: 20, label: '-20 %', from: t(4), to: t(10) } }),
        P('f3', t(1), '11:00', '4+1 · Cookies du goûter', 'Refusé', true, { template: 'fp-offre', img: 'img/products/cookies.png', refus: 'Le 4+1 sur les cookies est déjà prévu par le réseau la semaine prochaine.', promo: { kind: 'offre', value: '4+1', label: '4+1', from: t(1), to: t(5) } }),
      ],
      campaigns: [{ id: 'bo-12', name: 'Gamme automne', type: 'saison', start: t(-10), end: t(20), status: 'en cours', products: ['Brioche croustillante', 'Tarte aux pommes', 'Tartelette poire-amande'],
        levers: ['Posts', 'Publicité locale'], description: 'Lancement de la gamme automne dans tout le réseau.', budget: 120,
        result: { spend: 64.3, reach: 5200, impr: 11800, clicks: 214, caCamp: 1840, caRef: 1420, tCamp: 512, tRef: 455 } }],
      quota: { week: today, used: 1, max: RULES.quotaSemaine }, instagramUnique: true, grilleInstagram: true,
    };
  }
  function demoLoad() {
    let s = null;
    try { s = JSON.parse(localStorage.getItem('sf.demo.state') || 'null'); } catch (e) {}
    if (!s || s.today !== iso(new Date())) s = demoState();
    return s;
  }
  function demoSave(s) { try { localStorage.setItem('sf.demo.state', JSON.stringify(s)); } catch (e) {} }

  // ---------- réel ----------
  function xhr(method, path) {
    const x = new XMLHttpRequest();
    x.open(method, CFG.api + path, false);
    x.withCredentials = true;
    x.setRequestHeader('Accept', 'application/json');
    try { x.send(); } catch (e) { return { status: 0, json: null }; }
    let j = null; try { j = JSON.parse(x.responseText); } catch (e) {}
    return { status: x.status, json: j };
  }
  async function api(method, path, body) {
    const r = await fetch(CFG.api + path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: body ? JSON.stringify(body) : undefined });
    let j = null; try { j = await r.json(); } catch (e) {}
    if (r.status === 401) throw new Error(HORS_SESSION);
    if (!r.ok) throw new Error((j && j.error) || ('HTTP ' + r.status));
    return j;
  }
  // Chaque lien de l'espace porte la boutique (?shop=4) : l'adresse dit toujours de quelle boutique on parle,
  // se partage et se garde en favori telle quelle.
  function href(k, q) {
    const sid = STATE && STATE.shop ? String(STATE.shop.id) : SHOP, own = /(^|&)shop=/.test(q || '');
    const extra = [DEMO ? 'demo=1' : '', sid && !own ? 'shop=' + encodeURIComponent(sid) : '', q || ''].filter(Boolean).join('&');
    return encodeURI(PAGES[k]) + (extra ? '?' + extra : '');
  }

  let STATE = null, ERR = '';
  let SHOP = Q.get('shop') || '';
  try { if (!SHOP) SHOP = localStorage.getItem('sf.shop') || ''; } catch (e) {}
  function load() {
    if (DEMO) { STATE = demoLoad(); if (SHOP && STATE.me.shops.some(x => x.id === SHOP) && SHOP !== STATE.shop.id) { const x = STATE.me.shops.find(y => y.id === SHOP); STATE.shop = Object.assign({}, STATE.shop, { id: x.id, name: x.name, langs: x.id === 'halle' ? ['nl', 'fr'] : ['fr'] }); } return; }
    const r = xhr('GET', '/franchise/etat' + (SHOP ? '?shop=' + encodeURIComponent(SHOP) : ''));
    if (r.status === 401) { ERR = HORS_SESSION; return; }
    if (r.status === 403 && SHOP) { try { localStorage.removeItem('sf.shop'); } catch (e) {} const r2 = xhr('GET', '/franchise/etat'); if (r2.status === 200) { STATE = r2.json; return; } ERR = (r2.json && r2.json.error) || 'Accès refusé.'; return; }
    if (r.status !== 200 || !r.json) { ERR = (r.json && r.json.error) || 'Espace indisponible (HTTP ' + r.status + ').'; return; }
    STATE = r.json;
  }
  load();
  if (STATE) {
    try { localStorage.setItem('sf.shop', STATE.shop.id); } catch (e) {}
    // L'adresse du navigateur dit la boutique ouverte (ajoutée, ou corrigée si la boutique demandée n'est pas au compte).
    if (Q.get('shop') !== String(STATE.shop.id)) { try { const u = new URL(location.href); u.searchParams.set('shop', STATE.shop.id); history.replaceState(history.state, '', u.toString()); } catch (e) {} }
  }

  const media = (src) => !src ? '' : /^(https?:|data:|\/)/.test(src) ? src : /^uploads\//.test(src) ? CFG.media + src : src;
  const fill = (text, lang) => {
    const s = STATE ? STATE.shop : {};
    const h = lang === 'nl' ? (s.hoursNl || s.hours || '') : (s.hours || '');
    // Sans horaires, la phrase qui les cite disparaît plutôt que « Ouvert . » (comme le serveur).
    // {lien} : le lien du post, ajouté par le serveur à la publication (« Commandez ici 👉 https://… ») ; pas dans l'aperçu du texte.
    const t = String(text || '').replace(/[ \t]*\{lien\}[ \t]*/g, ' ').replace(/[ \t]+(\n|$)/g, '$1'), t2 = STATE && !String(h).trim() ? t.replace(/[ \t]*[^.!?\n]*\{horaires\}[^.!?\n]*[.!?]?/g, '').replace(/[ \t]{2,}/g, ' ').replace(/[ \t]+(\n|$)/g, '$1') : t;
    return t2.replace(/\{ville\}/g, s.name || '').replace(/\{adresse\}/g, s.address || '').replace(/\{horaires\}/g, h);
  };
  function emit() { window.dispatchEvent(new Event('sf-change')); }

  /** Publier depuis un modèle : le serveur vérifie tout ; en démonstration, les mêmes règles ici. */
  async function createPost(body) {
    if (!DEMO) { const r = await api('POST', '/franchise/posts', Object.assign({ shop: STATE.shop.id }, body)); STATE.posts = STATE.posts.concat(r.post); STATE.quota = Object.assign({}, STATE.quota, r.quota); emit(); return r.post; }
    const err = check(body); if (err.length) throw new Error(err.join(' '));
    const tpl = STATE.templates.find(t => t.id === body.template), v = window.SFVisuel.vars(tpl, Object.assign({}, body, { product: STATE.catalogue.find(p => p.id === body.product) }), 'fr');
    const r = STATE.rules, statut = r.validation === 'toujours' || (r.validation === 'modele' && tpl.validation) ? 'À valider' : 'Programmé';
    const k = (tpl.promo || {}).kind || 'aucune';
    const post = { id: 'demo' + Date.now(), date: body.date, time: body.time, title: window.SFVisuel.fill(tpl.title || tpl.name, v), status: statut, own: true, type: tpl.type, img: body.visual || ((tpl.visual || {}).style === 'fixe' ? media((tpl.visual.media || [])[0] ? tpl.visual.media[0].src : tpl.visual.src) : ''), text: window.SFVisuel.fill(tpl.text, v), textNl: window.SFVisuel.fill(tpl.textNl, v),
      fb: !!body.fb, ig: !!body.ig, template: tpl.id, refus: null, reason: null, campaign: tpl.campaign || null, link: body.link || 'aucun',
      promo: k === 'aucune' ? null : { kind: k, value: k === 'remise' ? +body.discount : k === 'offre' ? body.offer : body.promoPrice, label: k === 'remise' ? '-' + body.discount + ' %' : k === 'offre' ? body.offer : body.promoPrice + ' €', from: body.from || null, to: body.to || null } };
    STATE.posts = STATE.posts.concat(post); STATE.quota.used++; demoSave(STATE); emit(); return post;
  }
  async function cancelPost(id) {
    if (!DEMO) { await api('POST', '/franchise/posts/' + encodeURIComponent(id) + '/annuler', { shop: STATE.shop.id }); }
    const p = STATE.posts.find(x => x.id === id);
    STATE.posts = STATE.posts.filter(x => x.id !== id);
    if (p && p.status !== 'Refusé' && STATE.quota.used > 0) STATE.quota.used--;
    if (DEMO) demoSave(STATE);
    emit();
  }

  /** Les règles du réseau appliquées à une saisie (l'écran les montre ; le serveur les impose). */
  function check(b) {
    const r = STATE.rules, tpl = STATE.templates.find(t => t.id === b.template), err = [];
    if (!tpl) return ['Ce modèle n’est plus ouvert.'];
    if (!(b.fb && tpl.fb && r.fb) && !(b.ig && tpl.ig && r.ig)) err.push('Choisissez au moins un canal.');
    const when = at(b.date, b.time), now = Date.now();
    if (!b.date || !b.time || isNaN(when)) err.push('Choisissez la date et l’heure.');
    else {
      if (when < now + r.delaiMinHeures * 3600000) err.push('Programmez au moins ' + r.delaiMinHeures + ' h à l’avance.');
      if (when > now + r.horizonJours * 86400000) err.push('Pas plus de ' + r.horizonJours + ' jours à l’avance.');
      if (!(r.jours || []).includes(d2(b.date).getDay())) err.push('Ce jour n’est pas ouvert à la publication.');
      if (b.time < r.heureMin || b.time > r.heureMax) err.push('Heure entre ' + r.heureMin + ' et ' + r.heureMax + '.');
    }
    if (STATE.quota.used >= r.quotaSemaine) err.push('Quota de la semaine atteint (' + r.quotaSemaine + ').');
    if (tpl.product === 'choix' && !STATE.catalogue.some(p => p.id === b.product)) err.push('Choisissez un produit.');
    const p = tpl.promo || {};
    if (p.kind === 'remise') { const max = Math.min(p.max, r.remiseMax); if (!(+b.discount >= p.min && +b.discount <= max)) err.push('Remise entre ' + p.min + ' et ' + max + ' %.'); }
    const api = b.offre && b.offre.genre ? b.offre : null;  // promotion ou formule de TFBuddy (relue par le serveur)
    if (api && (p.kind === 'remise' || p.kind === 'prix')) err.push('Ce modèle a sa propre remise : la promotion du réseau se publie avec un modèle sans remise.');
    if (p.kind === 'offre' && !api && !(p.offres || []).includes(b.offer)) err.push('Choisissez une offre.');
    if (p.kind === 'prix' && !(parseFloat(String(b.promoPrice).replace(',', '.')) > 0)) err.push('Indiquez le prix promotionnel.');
    if (api && api.au && b.date && api.au < b.date) err.push('La promotion « ' + api.nom + ' » se termine avant la publication.');
    if (api && api.du && b.date && api.du > b.date) err.push('La promotion « ' + api.nom + ' » ne commence que le ' + dShort(api.du) + ' : publiez à partir de cette date.');
    if (tpl.period && !(api && api.du && api.au)) {
      if (!b.from || !b.to || b.to < b.from) err.push('Indiquez la période (du … au …).');
      else if ((d2(b.to) - d2(b.from)) / 86400000 + 1 > r.promoDureeMax) err.push('Une promotion dure au plus ' + r.promoDureeMax + ' jours.');
    }
    if (+tpl.free > 0) {
      const f = String(b.free || '').trim();
      if (!f) err.push('Complétez la phrase.');
      if (f.length > +tpl.free) err.push('Phrase limitée à ' + tpl.free + ' caractères.');
      if (/https?:\/\/|www\.|@\w|#\w/i.test(f)) err.push('Pas de lien, de compte (@) ni de hashtag.');
    }
    if (tpl.photo === 'upload' && !b.photo) err.push('Ajoutez votre photo.');
    return err;
  }
  /** Ferme l'aperçu du franchiseur (la session du gérant, elle, est celle de l'ERP). */
  async function fermerApercu() {
    try { await api('POST', '/franchise/deconnexion', {}); } catch (e) {}
    try { localStorage.removeItem('sf.shop'); } catch (e) {}
    window.close();
    location.reload();
  }
  /** Suivi de la boutique : abonnés gagnés / perdus face au budget, lu en direct chez Meta (sa Page seulement). */
  function suivi(jours) {
    if (!DEMO) return api('GET', '/franchise/suivi?jours=' + jours + '&shop=' + encodeURIComponent(STATE.shop.id));
    const h = (t) => { let x = 7; for (let i = 0; i < t.length; i++) x = (x * 31 + t.charCodeAt(i)) % 100003; return x; };
    const au = addDays(STATE.today, -1), du = addDays(au, -(jours - 1)), fbS = {}, dep = {};
    let gF = 0, pF = 0, tot = 0;
    for (let d = du; d <= au; d = addDays(d, 1)) {
      const k = h(STATE.shop.id + d), v = k % 7 < 3 ? 4 + k % 9 : 0;
      if (v) { dep[d] = v; tot += v; }
      const g = Math.round(v * 0.6 + k % 3), p = k % 6 === 0 ? 2 : k % 3 === 0 ? 1 : 0;
      fbS[d] = { gagnes: g, perdus: p }; gF += g; pF += p;
    }
    const net = gF - pF;  // Instagram est commun à tout le réseau : il n'entre pas dans le suivi de la boutique
    return Promise.resolve({ du, au, jours, devise: 'EUR', le: STATE.today + ' 10:00', demo: true, erreurs: [], message: null, lignes: [{ page: 'demo', name: STATE.shop.page || 'L’Atelier By ' + STATE.shop.name, picture: null,
      facebook: { followers: 4780, gagnes: gF, perdus: pF, net: gF - pF, serie: fbS, motif: null },
      instagram: null,
      depense: tot, depenseJours: dep, abonnesNet: net, coutParAbonne: net > 0 && tot ? Math.round(tot / net * 100) / 100 : null }] });
  }
  /**
   * L'analyse des vues de la boutique : vues de sa Page (et de l'Instagram) jour par jour, vues de chaque
   * publication de la période et évolution des abonnés, lues en direct chez Meta (sans le budget publicitaire).
   */
  function vues(jours) {
    if (!DEMO) return api('GET', '/franchise/vues?jours=' + jours + '&shop=' + encodeURIComponent(STATE.shop.id));
    const h = (t) => { let x = 7; for (let i = 0; i < t.length; i++) x = (x * 31 + t.charCodeAt(i)) % 100003; return x; };
    const au = addDays(STATE.today, -1), du = addDays(au, -(jours - 1)), fv = {}, ir = {}, fa = {}, ia = {}, pubs = [];
    const imgs = ['img/products/croissant.png', 'img/products/sweet-tart-big.png', 'img/products/cookies.png', 'img/products/cupcake.png', 'img/products/brioche-croustillante.png'];
    const titres = ['Ce matin au comptoir', 'La fournée du jour', 'Merci pour vos avis !', 'La gamme automne est là', 'Brunch du dimanche'];
    let i = 0, g = 0, p = 0, gi = 0;
    for (let d = du; d <= au; d = addDays(d, 1), i++) {
      const k = h(STATE.shop.id + d), pub = i % 3 === 1, we = [0, 6].includes(d2(d).getDay());
      fv[d] = 180 + k % 140 + (pub ? 260 + k % 200 : 0) + (we ? 90 : 0);
      if (d >= addDays(au, -29)) ir[d] = 120 + k % 90 + (i % 4 === 2 ? 210 : 0);
      fa[d] = { gagnes: (pub ? 3 : 1) + k % 3, perdus: k % 5 === 0 ? 2 : k % 4 === 0 ? 1 : 0 }; g += fa[d].gagnes; p += fa[d].perdus;
      if (d >= addDays(au, -29)) { ia[d] = { gagnes: 1 + k % 4, perdus: 0 }; gi += ia[d].gagnes; }
      if (pub) pubs.push({ accountName: STATE.shop.page || 'L’Atelier By ' + STATE.shop.name, id: 'mfb-demo_' + i, account: 'fb:demo', channel: 'Facebook', date: d, time: '10:' + pad(i % 6 * 10), type: 'Photo',
        title: titres[i % titres.length], img: imgs[i % imgs.length], permalink: 'https://www.facebook.com/', vues: 300 + (k % 900), personnes: 220 + (k % 600) });
      if (i % 4 === 2) pubs.push({ accountName: '@latelierby.be', id: 'mig-demo' + i, account: 'ig:demo', channel: 'Instagram', date: d, time: '11:30', type: 'Reel',
        title: 'Reel du réseau · ' + titres[(i + 2) % titres.length], img: imgs[(i + 2) % imgs.length], permalink: 'https://www.instagram.com/', vues: 500 + (k % 1500), personnes: 400 + (k % 900) });
    }
    pubs.sort((a, b) => b.vues - a.vues);
    const sum = (o) => Object.values(o).reduce((t, v) => t + v, 0);
    return Promise.resolve({ du, au, jours, le: STATE.today + ' 10:00', demo: true, erreurs: [], message: null, publications: pubs,
      comptes: [{ key: 'fb:demo', type: 'facebook', name: STATE.shop.page || 'L’Atelier By ' + STATE.shop.name, picture: null, link: 'https://www.facebook.com/', reseau: false,
        vues: { metrique: 'vues', serie: fv, total: sum(fv), motif: null }, abonnes: { followers: 4780, serie: fa, gagnes: g, perdus: p, motif: null }, publicationsMotif: null },
        { key: 'ig:demo', type: 'instagram', name: '@latelierby.be', picture: null, link: 'https://www.instagram.com/', reseau: true,
        vues: { metrique: 'personnes', serie: ir, total: Math.round(sum(ir) * 2.6), motif: jours > 30 ? 'Instagram ne donne que les 30 derniers jours, jour par jour.' : null },
        abonnes: { followers: 9412, serie: ia, gagnes: gi, perdus: null, motif: jours > 30 ? 'Instagram ne donne que les 30 derniers jours, jour par jour.' : null }, publicationsMotif: null }] });
  }
  /**
   * Le calendrier Meta de la boutique : les publications de sa Page (et de son Instagram, ou de celui du
   * réseau) entre deux dates, lues en direct chez Meta, plus ce qui est programmé dans Meta Business Suite.
   */
  function calendrier(du, au) {
    if (!DEMO) return api('GET', '/franchise/calendrier?shop=' + encodeURIComponent(STATE.shop.id) + '&du=' + du + '&au=' + au);
    const imgs = ['img/products/croissant.png', 'img/products/sweet-tart-big.png', 'img/products/cookies.png', 'img/products/cupcake.png', 'img/products/brioche-croustillante.png'];
    const posts = [];
    for (let d = du, i = 0; d <= au; d = addDays(d, 1), i++) {
      if (i % 3 === 1) posts.push({ id: 'mfb-demo_' + i, channel: 'Facebook', account: 'fb:demo', accountName: STATE.shop.page || 'L’Atelier By ' + STATE.shop.name, date: d, time: '10:' + pad(i % 6 * 10),
        type: i % 2 ? 'Photo' : 'Album · 3 photos', title: ['Ce matin au comptoir', 'La fournée du jour', 'Merci pour vos avis !'][i % 3], text: 'Publication d’exemple (démonstration).', img: imgs[i % imgs.length],
        status: d > STATE.today ? 'Programmé' : 'Publié', permalink: 'https://www.facebook.com/', stats: { reactions: 4 + i % 9, comments: i % 3, shares: i % 4 }, campaign: null });
      if (i % 4 === 2 && d <= STATE.today) posts.push({ id: 'mig-demo' + i, channel: 'Instagram', account: 'ig:demo', accountName: '@latelierby.be', date: d, time: '11:30', type: 'Reel',
        title: 'Reel du réseau', text: 'Publication d’exemple (démonstration).', img: imgs[(i + 2) % imgs.length], status: 'Publié', permalink: 'https://www.instagram.com/', stats: { reactions: 20 + i, comments: 1, shares: null }, campaign: null });
    }
    return Promise.resolve({ du, au, le: STATE.today + ' ' + STATE.now, demo: true, erreurs: [], message: null, posts,
      comptes: [{ key: 'fb:demo', type: 'facebook', name: STATE.shop.page || 'L’Atelier By ' + STATE.shop.name, username: '', picture: null, link: 'https://www.facebook.com/', reseau: false, publies: posts.filter(p => p.channel === 'Facebook').length, programmes: 0, erreur: null },
        { key: 'ig:demo', type: 'instagram', name: 'L’Atelier By', username: 'latelierby.be', picture: null, link: 'https://www.instagram.com/', reseau: true, publies: posts.filter(p => p.channel === 'Instagram').length, programmes: 0, erreur: null }] });
  }
  /** Les chiffres (vues, personnes touchées…) d'une publication Meta du calendrier, lus à l'ouverture de sa fiche. */
  function calStats(p) {
    if (!DEMO) return api('GET', '/franchise/calendrier/stats?shop=' + encodeURIComponent(STATE.shop.id) + '&id=' + encodeURIComponent(p.id) + '&compte=' + encodeURIComponent(p.account));
    const n = (p.stats && p.stats.reactions) || 5;
    return Promise.resolve({ id: p.id, vues: n * 61, personnes: n * 47, clics: n * 3, reactions: n, commentaires: (p.stats && p.stats.comments) || 0, partages: (p.stats && p.stats.shares) || 0, motif: null, demo: true });
  }
  /** La grille Instagram de la boutique (celle du réseau en Instagram unique), lue en direct chez Meta, et ce qui y est programmé. */
  function instagram() {
    if (!DEMO) return api('GET', '/franchise/instagram?shop=' + encodeURIComponent(STATE.shop.id));
    const imgs = ['img/products/croissant.png', 'img/products/sweet-tart-big.png', 'img/products/cookies.png', 'img/products/cupcake.png', 'img/products/brioche-croustillante.png', 'img/products/savoury-tart.png', 'img/products/bread-1.png', 'img/illus/seasonal/autumn-range.png', 'img/products/sweet-tart-small.png'];
    const media = Array.from({ length: 12 }, (_, i) => ({ id: 'm' + i, src: imgs[i % imgs.length], video: i === 4, permalink: 'https://www.instagram.com/', at: addDays(STATE.today, -1 - i * 2) + ' 11:30',
      caption: ['Ce matin au comptoir', 'La gamme automne est là', 'Merci pour vos avis !', 'Fournée du week-end'][i % 4], likes: 40 + (i * 37) % 120, comments: i % 7 }));
    return Promise.resolve({ reseau: true, username: 'latelierby.be', name: 'L’Atelier By', picture: null, followers: 9412, media: media.map(m => Object.assign({ type: m.video ? 'Reel' : 'Photo', frise: null }, m)), erreur: null, demo: true,
      programmes: [{ id: 'n3', title: 'Halloween : biscuits citrouille', date: addDays(STATE.today, 9), time: '09:00', status: 'Programmé', own: false, frise: null, src: 'img/products/pumpkin.png' },
        { id: 'n2', title: 'Derniers jours de la gamme automne', date: addDays(STATE.today, 3), time: '08:00', status: 'Programmé', own: false, frise: null, src: 'img/illus/seasonal/autumn-range.png' }] });
  }
  /**
   * Les promotions, bundles et formules liés à un produit du catalogue (TFBuddy), valables dans la boutique,
   * avec leur mention (dates, boutiques). Comme « Créer un post » du franchiseur.
   */
  const OFFRES = new Map();
  function offres(produit) {
    if (!produit) return Promise.resolve({ promotions: [], formules: [] });
    if (DEMO) {
      const p = (STATE.catalogue || []).find(x => x.id === produit) || {}, d = (n) => addDays(STATE.today, n), f = (iso) => iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4);
      return Promise.resolve({ demo: true, erreur: null, formules: [],
        promotions: [{ id: 1, type: 'offre', nom: p.name + ' 4+1', libelle: '4 + 1 offert (' + (p.name || 'produit') + ')', court: '4+1', du: d(-2), au: d(12), tous: true, portee: 'Toutes les boutiques',
            mention: 'Offre valable du ' + f(d(-2)) + ' au ' + f(d(12)) + ', dans toutes les boutiques.', mentionNl: 'Aanbod geldig van ' + f(d(-2)) + ' tot ' + f(d(12)) + ', in alle winkels.' },
          { id: 2, type: 'bundle', nom: 'Duo', libelle: 'Bundle : 2 × ' + (p.name || 'produit') + ' = 9,90 €', court: '9,90 €', du: null, au: null, tous: false, portee: 'Votre boutique',
            mention: 'Offre valable à ' + STATE.shop.name + '.', mentionNl: 'Aanbod geldig in ' + STATE.shop.name + '.' }] });
    }
    if (!OFFRES.has(produit)) {
      const q = api('GET', '/franchise/catalogue/offres?shop=' + encodeURIComponent(STATE.shop.id) + '&produit=' + encodeURIComponent(produit));
      OFFRES.set(produit, q); q.catch(() => OFFRES.delete(produit));
    }
    return OFFRES.get(produit);
  }
  /**
   * À droite de la grille Instagram : les abonnés du compte jour par jour (30 jours : nouveaux, désabonnements)
   * et les comptes touchés, lus en direct chez Meta.
   */
  function instagramAbonnes() {
    if (!DEMO) return api('GET', '/franchise/instagram/abonnes?shop=' + encodeURIComponent(STATE.shop.id));
    const h = (t) => { let x = 7; for (let i = 0; i < t.length; i++) x = (x * 31 + t.charCodeAt(i)) % 100003; return x; };
    const au = addDays(STATE.today, -1), du = addDays(au, -29), serie = {}, reach = {};
    let g = 0, p = 0;
    for (let d = du, i = 0; d <= au; d = addDays(d, 1), i++) {
      const k = h('ig' + d); serie[d] = { gagnes: 1 + k % 5 + (i % 4 === 2 ? 4 : 0), perdus: k % 3 === 0 ? 2 : k % 2 }; g += serie[d].gagnes; p += serie[d].perdus;
      reach[d] = 420 + k % 380 + (i % 4 === 2 ? 600 : 0);
    }
    return Promise.resolve({ reseau: true, followers: 9412, username: 'latelierby.be', du, au, jours: 30, serie, gagnes: g, perdus: p, reach, motif: null, message: null, le: STATE.today + ' 10:00', demo: true });
  }
  /**
   * Les opérations du réseau (campagnes du Marketing du BO) où figure la boutique : ce qui y est rattaché
   * (posts, publications de la Page, publicités) et toutes les métriques de la boutique. Lecture seule.
   */
  function operations() {
    if (!DEMO) return api('GET', '/franchise/operations?shop=' + encodeURIComponent(STATE.shop.id));
    const ops = (STATE.campaigns || []).map(c => Object.assign({}, c, { type: c.type === 'saison' ? 'Saisonnalité' : c.type, boStatus: 'En cours',
      levers: c.levers || [], channels: ['Meta Ads', 'Posts des Pages'], goal: { metric: 'CA additionnel', value: 4000, unit: 'EUR' }, goalShop: 480,
      posts: (STATE.posts || []).filter(p => p.campaign === c.id),
      metaPosts: [{ id: 'mfb-demo1', channel: 'Facebook', date: addDays(STATE.today, -3), text: 'La gamme automne est là : brioche croustillante, tarte aux pommes… Venez goûter !', img: 'img/illus/seasonal/autumn-range.png', link: 'https://www.facebook.com/' },
        { id: 'mig-demo2', channel: 'Instagram', date: addDays(STATE.today, -5), text: 'Nouveau : tartelette poire-amande 🍐', img: 'img/products/sweet-tart-small.png', link: 'https://www.instagram.com/' }],
      ads: [{ name: 'Automne · trafic magasin', status: 'Active', objective: 'Trafic en magasin', start: addDays(STATE.today, -9), end: addDays(STATE.today, 18), reseau: false, meta: false },
        { name: 'Automne · vidéo notoriété', status: 'Active', objective: 'Notoriété locale', start: addDays(STATE.today, -9), end: addDays(STATE.today, 18), reseau: true, meta: false }],
      result: c.result ? Object.assign({ impr: 11800, eng: 640, vv: 2100, qCamp: 310, qRef: 240, source: 'API TFBuddy' }, c.result) : null }));
    return Promise.resolve({ hidden: STATE.rules.voirCampagnes === false, operations: ops });
  }
  /**
   * Le webshop de la boutique, ou une page de ce webshop avec ses paramètres à lui (comme le serveur, src/publish.php) :
   * catégorie ?category=group:G · sous-catégorie &sub=category:C · produit : sa sous-catégorie + &product=ID, et &open=product
   * pour ouvrir sa fiche (`fiche` false : {webshop_produit:ID:liste}, le produit reste dans la liste).
   */
  function webshopUrl(product, kind, fiche) {
    const base = (STATE && STATE.webshopUrl) || 'https://webshop.atelierby.be/', sid = STATE && STATE.shop ? String(STATE.shop.id) : '';
    const plan = (STATE && STATE.webshopPlan) || {}, cats = plan.cats || {}, prods = plan.prods || {}, q = [];
    if (/^\w+$/.test(sid) && (DEMO || /^\d+$/.test(sid))) q.push(['shop', sid]);
    const pid = kind === 'produit' && product && product.apiId ? +product.apiId : 0;
    const cat = kind === 'groupe' ? 0 : (product && +product.categoryId) || (pid && prods[pid]) || 0;
    const grp = kind === 'groupe' ? +(product && product.groupId) || 0 : cat ? +cats[cat] || 0 : 0;
    if (kind !== 'produit' && kind !== 'categorie' && kind !== 'groupe') return q.length ? base + '?shop=' + sid : base;
    if (cat) { if (grp) q.push(['category', 'group:' + grp], ['sub', 'category:' + cat]); else q.push(['category', 'category:' + cat]); }
    else if (grp) q.push(['category', 'group:' + grp]);
    if (pid) { q.push(['product', String(pid)]); if (fiche !== false) q.push(['open', 'product']); }
    return q.length ? base + '?' + q.map(([k, v]) => k + '=' + encodeURIComponent(v).replace(/%3A/g, ':')).join('&') : base;
  }
  /**
   * Une photo du webshop (Cloudflare R2, sans en-tête CORS) rapatriée par le module et remise en JPEG (data:) :
   * le visuel qui la dessine peut alors être exporté. En démonstration, sans serveur, rendue telle quelle.
   */
  const LOCALES = new Map();
  function photoLocale(src) {
    src = String(src || '');
    if (DEMO || !/^https?:\/\//i.test(src)) return Promise.resolve(src);
    if (LOCALES.has(src)) return LOCALES.get(src);
    const p = (async () => {
      const r = await fetch(CFG.api + '/franchise/media/webshop?shop=' + encodeURIComponent(STATE.shop.id) + '&u=' + encodeURIComponent(src), { credentials: 'same-origin' });
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
  function switchShop(id) { try { localStorage.setItem('sf.shop', id); } catch (e) {} const u = new URL(location.href); u.searchParams.set('shop', id); location.href = u.toString(); }
  function reset() { if (DEMO) { try { localStorage.removeItem('sf.demo.state'); } catch (e) {} location.reload(); } }

  window.SF = { live: !DEMO, demo: DEMO, error: ERR, cfg: CFG, state: () => STATE, api, href, media, fill, check, createPost, cancelPost, fermerApercu, switchShop, reset, emit, suivi, vues, instagram, instagramAbonnes, offres, calendrier, calStats, operations, webshopUrl, photoLocale,
    MONTHS, DAYS, iso, d2, addDays, dLabel, dShort, at, eur, pad,
    STATUS: { 'À valider': { bg: '#FAC775', fg: '#6b3d0a' }, 'Programmé': { bg: '#F2C9A0', fg: '#6b4420' }, 'Publié': { bg: '#E2F0E8', fg: '#0F6B4A' }, 'Refusé': { bg: '#F6DCDF', fg: '#8D1D2C' }, 'Échec': { bg: '#8D1D2C', fg: '#FFFFFF' }, 'Brouillon': { bg: '#F4EFE8', fg: '#555555' } } };
})();
