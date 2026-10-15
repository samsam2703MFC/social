// Espace franchisé — le visuel figé d'un modèle (partagé par l'écran franchiseur et /social_franchisee).
//
// Le studio (« Posts Facebook ») dessine le visuel ; ce module ne fait que lui donner
// des réglages : le style, la mention et la mise en page viennent du modèle, seuls
// les champs que le modèle ouvre (produit, prix, remise, offre, période, phrase,
// photo) changent. Le franchisé ne touche jamais au studio lui-même.
(function () {
  if (window.SFVisuel) return;
  const STYLES = [['epure', 'Épuré'], ['abricot', 'Abricot'], ['ruby', 'Ruby'], ['noir', 'Noir'], ['plein', 'Plein cadre'], ['duo', 'Duo']];
  const PROMOS = [['aucune', 'Aucune'], ['remise', 'Remise en %'], ['offre', 'Offre (4+1…)'], ['prix', 'Prix promotionnel']];
  const d2 = (iso) => { const m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '/' + m[2] : ''; };

  /** Les variables d'un modèle, à partir de ce que le franchisé a saisi. */
  function vars(tpl, f, lang) {
    const p = f.product || null, promo = (tpl.promo || {}).kind || 'aucune';
    return {
      produit: p ? (lang === 'nl' && p.nameNl ? p.nameNl : p.name) : '', prix: p ? p.price : '',
      remise: promo === 'remise' && f.discount ? String(f.discount) : '', offre: promo === 'offre' ? (f.offer || '') : '',
      prix_promo: promo === 'prix' ? (f.promoPrice || '') : '', du: d2(f.from), au: d2(f.to), libre: f.free || '',
    };
  }
  function fill(t, v) { return String(t || '').replace(/\{(produit|prix|remise|offre|prix_promo|du|au|libre)\}/g, (_, k) => v[k] || ''); }

  /** Les réglages du studio pour un modèle et des valeurs. */
  function control(tpl, f, lang) {
    const L = lang === 'nl' ? 'nl' : 'fr', v = vars(tpl, f || {}, L), vis = tpl.visual || {}, promo = (tpl.promo || {}).kind || 'aucune';
    const per = v.du && v.au ? (L === 'nl' ? 'van ' + v.du + ' tot ' + v.au : 'du ' + v.du + ' au ' + v.au) : '';
    const promoTxt = promo === 'remise' && v.remise ? '-' + v.remise + '%' : promo === 'offre' ? v.offre : '';
    const price = promo === 'prix' && v.prix_promo ? v.prix_promo : v.prix;
    const title = v.produit || fill(tpl.title, v) || tpl.name || '';
    const photo = f && f.photo ? { src: f.photo, cut: false, name: 'Photo de la boutique' } : (f && f.product && f.product.img ? { src: f.product.img, cut: true, name: f.product.name } : null);
    return {
      style: vis.style || 'epure', cutMode: 'auto', showLogo: true,
      showEyebrow: !!(vis.eyebrow || per), eyebrow: [L === 'nl' ? (vis.eyebrowNl || vis.eyebrow || '') : (vis.eyebrow || ''), promo !== 'aucune' ? per : ''].filter(Boolean).join(' · '),
      showTitle: !!title, title,
      showPhrase: !!v.libre, phrase: v.libre, phraseFont: 'script', phraseColor: 'auto',
      // Le prix reste avec une offre (4+1…) : le visuel pose l'offre à côté du prix (« 20,90 € la pièce | 4+1 offre ») — sans lui, elle disparaissait.
      showPrice: !!price, price: price || '', unit: L === 'nl' ? 'Per stuk' : 'La pièce',
      showPromo: !!promoTxt, promo: promoTxt, promoSub: promo === 'offre' ? (L === 'nl' ? 'actie' : 'offre') : (promo === 'remise' ? (L === 'nl' ? 'korting' : 'de remise') : ''),
      showSite: true, site: 'www.latelierby.be', photos: photo ? [photo] : [],
    };
  }

  /** Ce que le modèle ouvre au franchisé, en clair (écran franchiseur). */
  function resume(tpl) {
    const p = tpl.promo || {}, out = [];
    // Modèle créé depuis un post du réseau : visuel et texte figés, seule la date (et une phrase, si ouverte) change.
    if ((tpl.visual || {}).style === 'fixe') return ['visuel du réseau', +tpl.free > 0 ? 'phrase de ' + tpl.free + ' car. max' : 'date et heure seulement'].join(' · ');
    if (tpl.product === 'choix') out.push('produit de son catalogue');
    if (p.kind === 'remise') out.push('remise ' + (p.min || 0) + '–' + (p.max || 0) + ' %');
    if (p.kind === 'offre') out.push('offre : ' + (p.offres || []).join(', '));
    if (p.kind === 'prix') out.push('prix promotionnel');
    if (tpl.period) out.push('période');
    if (+tpl.free > 0) out.push('phrase de ' + tpl.free + ' car. max');
    out.push(tpl.photo === 'upload' ? 'sa photo (obligatoire)' : tpl.photo === 'choix' ? 'sa photo ou celle du catalogue' : 'photo du catalogue');
    return out.join(' · ');
  }

  window.SFVisuel = { STYLES, PROMOS, vars, fill, control, resume, d2 };
})();
