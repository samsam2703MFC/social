// Social & Ads — moteur des montages Instagram (dessin sur canvas, dans le navigateur).
//
//  - Carrousel panoramique : un visuel large (n × 1080 × 1350) découpé en n images
//    4:5. La photo et le bandeau traversent les découpes : le visuel continue quand
//    on fait glisser ; le titre et la pastille prix restent entiers dans la 1re image. Publié en UN post (carrousel Instagram, album
//    Facebook) par le serveur, comme n'importe quel post à plusieurs images.
//  - Frise de grille : un visuel découpé en 3 posts qui forment une ligne du profil
//    (recadrage 3:4 de la grille pris en compte) ; Facebook reçoit le visuel entier.
//  - Collage : gabarits Duo, Trio, Quatuor en une image 1080 × 1350 (4:5), aux
//    couleurs de la marque, avec le nom et le prix du produit.
//
// Les photos détourées (illustrations, `cut`) sont posées en entier sur fond crème ;
// les autres remplissent leur case (recadrage au centre).
(function () {
  if (window.SAMontage) return;
  const W = 1080, H = 1350;
  const C = { ruby: '#8D1D2C', cream: '#F5EFE4', sand: '#EAE4DC', ink: '#232220', white: '#FFFFFF' };
  const FONT = '"SAM Gotham", "Gotham", "Helvetica Neue", Arial, sans-serif';

  let fontsReady = null;
  function fonts() {
    if (fontsReady) return fontsReady;
    const defs = [['fonts/Gotham_Medium.otf', '500'], ['fonts/Gotham_Regular_New.otf', '400'], ['fonts/Gotham_Light.otf', '300']];
    fontsReady = Promise.all(defs.map(([u, w]) => {
      try { const f = new FontFace('SAM Gotham', 'url(' + u + ')', { weight: w }); document.fonts.add(f); return f.load().catch(() => null); }
      catch (e) { return Promise.resolve(null); }
    }));
    return fontsReady;
  }

  const cache = new Map();
  function load(src) {
    if (!src) return Promise.resolve(null);
    if (cache.has(src)) return cache.get(src);
    const p = new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => res(null); i.src = src; });
    cache.set(src, p);
    return p;
  }

  /** Une photo dans une case : recadrée (cover) ou posée en entier sur crème (contain). */
  function place(ctx, img, x, y, w, h, contain) {
    ctx.save();
    ctx.beginPath(); ctx.rect(x, y, w, h); ctx.clip();
    ctx.fillStyle = contain ? C.cream : C.sand; ctx.fillRect(x, y, w, h);
    if (img) {
      const r = contain ? Math.min(w / img.width, h / img.height) * 0.82 : Math.max(w / img.width, h / img.height);
      const dw = img.width * r, dh = img.height * r;
      ctx.drawImage(img, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
    }
    ctx.restore();
  }

  /** Texte réduit jusqu'à tenir dans la largeur. Rend la taille retenue. */
  function fit(ctx, text, maxW, size, weight, min) {
    let s = size;
    for (; s > (min || 18); s -= 2) { ctx.font = weight + ' ' + s + 'px ' + FONT; if (ctx.measureText(text).width <= maxW) break; }
    ctx.font = weight + ' ' + s + 'px ' + FONT;
    return s;
  }

  /** Titre sur une ou deux lignes (coupure la plus équilibrée), à la plus grande taille qui tient. */
  function wrap(ctx, text, maxW, size, min, weight) {
    const words = text.split(/\s+/);
    for (let s = size; s >= min; s -= 4) {
      ctx.font = weight + ' ' + s + 'px ' + FONT;
      if (ctx.measureText(text).width <= maxW) return { lines: [text], size: s };
      let best = null;
      for (let i = 1; i < words.length; i++) {
        const a = words.slice(0, i).join(' '), b = words.slice(i).join(' '), wa = ctx.measureText(a).width, wb = ctx.measureText(b).width;
        if (wa <= maxW && wb <= maxW && (!best || Math.max(wa, wb) < best.w)) best = { w: Math.max(wa, wb), lines: [a, b] };
      }
      if (best) return { lines: best.lines, size: s };
    }
    ctx.font = weight + ' ' + min + 'px ' + FONT;
    return { lines: [text], size: min };
  }

  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }

  function priceDisc(ctx, cx, cy, r, price, k) {
    ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fillStyle = C.white; ctx.fill();
    ctx.lineWidth = 6 * k; ctx.strokeStyle = C.ruby; ctx.stroke();
    ctx.fillStyle = C.ruby; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    const m = String(price).match(/^(dès|vanaf|à partir de)\s+(.*)$/i);
    if (m) {
      ctx.font = '500 ' + Math.round(r * 0.26) + 'px ' + FONT; ctx.fillText(m[1], cx, cy - r * 0.32);
      fit(ctx, m[2], r * 1.6, Math.round(r * 0.5), '500', 16); ctx.fillText(m[2], cx, cy + r * 0.12);
    } else {
      fit(ctx, String(price), r * 1.6, Math.round(r * 0.5), '500', 16); ctx.fillText(String(price), cx, cy);
    }
  }

  function exportCanvas(cv, preview) { return cv.toDataURL('image/jpeg', preview ? 0.78 : 0.9); }

  /**
   * Carrousel panoramique.
   * o = { photos: [{src, cut}], n: 2..10, title, band, price, sign } ; scale 1 = 1080 px par image.
   * Rend { slices: [dataURL…], size: {w, h} }.
   */
  async function panorama(o, scale) {
    await fonts();
    const k = scale || 1, n = Math.max(2, Math.min(10, o.n || 3));
    const TW = W * n * k, TH = H * k, w1 = W * k;
    const cv = document.createElement('canvas'); cv.width = Math.round(TW); cv.height = Math.round(TH);
    const ctx = cv.getContext('2d');
    ctx.fillStyle = C.cream; ctx.fillRect(0, 0, TW, TH);
    const photos = (o.photos || []).filter((p) => p && p.src);
    const imgs = await Promise.all(photos.map((p) => load(p.src)));
    if (imgs.length === 1) place(ctx, imgs[0], 0, 0, TW, TH, photos[0].cut);
    else if (imgs.length > 1) {
      // Plusieurs photos : des panneaux égaux sur toute la largeur, qui ne tombent pas
      // forcément sur les découpes — c'est ce qui donne la continuité.
      const pw = TW / imgs.length;
      imgs.forEach((img, i) => place(ctx, img, i * pw, 0, pw + 1, TH, photos[i].cut));
    }
    // La 1re image est la seule visible sur le profil : le titre et la pastille prix y restent entiers.
    // Titre sur une ou deux lignes ; son ruban crème déborde sur la 2e image et annonce la suite.
    const title = String(o.title || '').toUpperCase().trim();
    if (title) {
      const t = wrap(ctx, title, w1 - 140 * k, 120 * k, 52 * k, '500'), lh = t.size * 1.08, padY = t.size * 0.34;
      ctx.fillStyle = 'rgba(245,239,228,0.94)';
      ctx.fillRect(0, 96 * k, n > 1 ? w1 + 170 * k : w1, t.lines.length * lh + 2 * padY);
      ctx.fillStyle = C.ruby; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
      t.lines.forEach((l, i) => ctx.fillText(l, 70 * k, 96 * k + padY + lh * i + lh / 2));
    }
    // Bandeau bordeaux continu sur toute la largeur. Ses éléments (séparés par « · ») sont
    // répartis un par image et centrés dans leur image : aucun mot ne tombe sur une découpe.
    const bandH = 170 * k, bandY = TH - 330 * k, price = String(o.price || '').trim();
    const disc = price ? { cx: w1 - 200 * k, cy: bandY - 40 * k, r: 150 * k } : null;
    ctx.fillStyle = C.ruby; ctx.fillRect(0, bandY, TW, bandH);
    const segs = String(o.band || '').split(/\s+[·•|]\s+|\s*\n\s*/).map((x) => x.trim()).filter(Boolean);
    const per = Array.from({ length: n }, () => []);
    segs.forEach((x, j) => per[Math.min(n - 1, Math.floor(j * n / segs.length))].push(x));
    ctx.fillStyle = C.white; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    per.forEach((list, i) => {
      if (!list.length) return;
      const txt = list.join('  ·  '), x0 = i * w1 + 60 * k, x1 = i === 0 && disc ? disc.cx - disc.r - 30 * k : (i + 1) * w1 - 60 * k;
      fit(ctx, txt, x1 - x0, 62 * k, '400', 22 * k); ctx.fillText(txt, (x0 + x1) / 2, bandY + bandH / 2);
    });
    // Signature, sous le bandeau, dans la 1re image.
    const sign = String(o.sign || '').trim();
    if (sign) {
      ctx.fillStyle = 'rgba(245,239,228,0.94)'; ctx.fillRect(0, bandY + bandH, TW, TH - bandY - bandH);
      ctx.fillStyle = C.ink; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
      fit(ctx, sign, w1 * 0.86, 44 * k, '500', 20 * k); ctx.fillText(sign, 70 * k, bandY + bandH + (TH - bandY - bandH) / 2);
    }
    // Pastille prix à cheval sur le bandeau, entière dans la 1re image.
    if (disc) priceDisc(ctx, disc.cx, disc.cy, disc.r, price, k);

    const slices = [];
    for (let i = 0; i < n; i++) {
      const s = document.createElement('canvas'); s.width = Math.round(w1); s.height = Math.round(TH);
      s.getContext('2d').drawImage(cv, -Math.round(i * w1), 0);
      slices.push(exportCanvas(s, k < 1));
    }
    return { slices, size: { w: Math.round(w1), h: Math.round(TH) } };
  }

  /**
   * Frise de grille : 3 posts 1080 × 1350 qui forment une ligne du profil Instagram.
   * Le profil montre chaque post recadré au centre en 3:4 (1012,5 × 1350) : le visuel
   * est dessiné sur la largeur visible (3 × 1012,5) et chaque tuile déborde de
   * 33,75 px de chaque côté sur ses voisines, pour que la ligne soit continue dans la
   * grille. Facebook reçoit le visuel entier (`full`).
   * o = { photos, title, band, price, sign } → { tiles: [gauche, milieu, droite], full, size }.
   */
  async function frise(o, scale) {
    await fonts();
    const k = scale || 1, V = H * 3 / 4, B = (W - V) / 2;
    const v = V * k, b = B * k, w1 = W * k, TW = 3 * v + 2 * b, TH = H * k;
    const cv = document.createElement('canvas'); cv.width = Math.round(TW); cv.height = Math.round(TH);
    const ctx = cv.getContext('2d');
    ctx.fillStyle = C.cream; ctx.fillRect(0, 0, TW, TH);
    const photos = (o.photos || []).filter((p) => p && p.src);
    const imgs = await Promise.all(photos.map((p) => load(p.src)));
    if (imgs.length === 1) place(ctx, imgs[0], 0, 0, TW, TH, photos[0].cut);
    else if (imgs.length > 1) {
      // Des panneaux égaux sur la largeur visible ; les deux du bord prennent aussi le débord extérieur.
      const pw = 3 * v / imgs.length;
      imgs.forEach((img, i) => { const x0 = i === 0 ? 0 : b + i * pw, x1 = i === imgs.length - 1 ? TW : b + (i + 1) * pw; place(ctx, img, x0, 0, x1 - x0 + 1, TH, photos[i].cut); });
    }
    // Sur le profil, la ligne se lit d'un coup : le titre s'étale sur les trois tuiles.
    const title = String(o.title || '').toUpperCase().trim();
    if (title) {
      const size = fit(ctx, title, 3 * v * 0.86, 190 * k, '500', 60 * k);
      ctx.fillStyle = 'rgba(245,239,228,0.94)'; ctx.fillRect(0, 110 * k, TW, size * 1.5);
      ctx.fillStyle = C.ruby; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(title, TW / 2, 110 * k + size * 0.78);
    }
    const bandH = 170 * k, bandY = TH - 330 * k, price = String(o.price || '').trim();
    // Pastille posée sur le bandeau, dans la tuile de droite ; les éléments du bandeau
    // (séparés par « · ») vont un par tuile, centrés : aucun mot sur l'intervalle de la grille.
    const disc = price ? { cx: b + 2.5 * v, cy: bandY - 150 * k, r: 140 * k } : null;
    ctx.fillStyle = C.ruby; ctx.fillRect(0, bandY, TW, bandH);
    const segs = String(o.band || '').split(/\s+[·•|]\s+|\s*\n\s*/).map((x) => x.trim()).filter(Boolean);
    const per = [[], [], []];
    segs.forEach((x, j) => per[Math.min(2, Math.floor(j * 3 / segs.length))].push(x));
    ctx.fillStyle = C.white; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    per.forEach((list, i) => {
      if (!list.length) return;
      const txt = list.join('  ·  '), x0 = b + i * v + 50 * k, x1 = b + (i + 1) * v - 50 * k;
      fit(ctx, txt, x1 - x0, 62 * k, '400', 22 * k); ctx.fillText(txt, (x0 + x1) / 2, bandY + bandH / 2);
    });
    const sign = String(o.sign || '').trim();
    if (sign) {
      ctx.fillStyle = 'rgba(245,239,228,0.94)'; ctx.fillRect(0, bandY + bandH, TW, TH - bandY - bandH);
      ctx.fillStyle = C.ink; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
      fit(ctx, sign, v * 0.86, 44 * k, '500', 20 * k); ctx.fillText(sign, b + 70 * k, bandY + bandH + (TH - bandY - bandH) / 2);
    }
    if (disc) priceDisc(ctx, disc.cx, disc.cy, disc.r, price, k);

    const tiles = [0, 1, 2].map((i) => {
      const s = document.createElement('canvas'); s.width = Math.round(w1); s.height = Math.round(TH);
      s.getContext('2d').drawImage(cv, -Math.round(i * v), 0);
      return exportCanvas(s, k < 1);
    });
    const f = document.createElement('canvas'); f.width = Math.round(3 * v); f.height = Math.round(TH);
    f.getContext('2d').drawImage(cv, -Math.round(b), 0);
    return { tiles, full: exportCanvas(f, k < 1), size: { w: Math.round(w1), h: Math.round(TH) } };
  }

  /**
   * Collage en une image 4:5.
   * o = { tpl: 'duo'|'trio'|'quatuor', photos: [{src, cut}], title, price, sign }.
   */
  async function collage(o, scale) {
    await fonts();
    const k = scale || 1, cw = W * k, ch = H * k, g = 12 * k;
    const cv = document.createElement('canvas'); cv.width = Math.round(cw); cv.height = Math.round(ch);
    const ctx = cv.getContext('2d');
    ctx.fillStyle = C.white; ctx.fillRect(0, 0, cw, ch);
    const photos = (o.photos || []).filter((p) => p && p.src);
    const imgs = await Promise.all(photos.map((p) => load(p.src)));
    const at = (i) => imgs.length ? imgs[i % imgs.length] : null;
    const cut = (i) => photos.length ? !!photos[i % photos.length].cut : false;
    const title = String(o.title || '').toUpperCase().trim(), price = String(o.price || '').trim(), sign = String(o.sign || '').trim();
    const tpl = o.tpl || 'trio';

    if (tpl === 'duo') {
      const bandH = 250 * k, cellW = (cw - 3 * g) / 2, cellH = ch - bandH - 3 * g;
      place(ctx, at(0), g, g, cellW, cellH, cut(0));
      place(ctx, at(1), 2 * g + cellW, g, cellW, cellH, cut(1));
      ctx.fillStyle = C.ruby; ctx.fillRect(g, ch - bandH - g, cw - 2 * g, bandH);
      ctx.fillStyle = C.white; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
      if (title) { fit(ctx, title, cw * (price ? 0.58 : 0.86), 76 * k, '500', 28 * k); ctx.fillText(title, 60 * k, ch - g - bandH / 2 - (sign ? 26 * k : 0)); }
      if (sign) { ctx.font = '400 ' + Math.round(36 * k) + 'px ' + FONT; ctx.fillText(sign, 60 * k, ch - g - bandH / 2 + 48 * k); }
      if (price) { ctx.textAlign = 'right'; fit(ctx, price, cw * 0.3, 64 * k, '500', 26 * k); ctx.fillText(price, cw - 60 * k, ch - g - bandH / 2); }
    } else if (tpl === 'quatuor') {
      const cellW = (cw - 3 * g) / 2, cellH = (ch - 3 * g) / 2;
      [[0, 0], [1, 0], [0, 1], [1, 1]].forEach(([c, r], i) => place(ctx, at(i), g + c * (cellW + g), g + r * (cellH + g), cellW, cellH, cut(i)));
      const R = 210 * k, cx = cw / 2, cy = ch / 2;
      ctx.beginPath(); ctx.arc(cx, cy, R + 12 * k, 0, Math.PI * 2); ctx.fillStyle = C.white; ctx.fill();
      ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2); ctx.fillStyle = C.ruby; ctx.fill();
      ctx.fillStyle = C.white; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      if (title) { fit(ctx, title, R * 1.7, 62 * k, '500', 24 * k); ctx.fillText(title, cx, cy - (price ? 30 * k : 0)); }
      if (price) { fit(ctx, price, R * 1.5, 46 * k, '400', 20 * k); ctx.fillText(price, cx, cy + 46 * k); }
      if (sign) {
        ctx.font = '500 ' + Math.round(32 * k) + 'px ' + FONT; const sw = ctx.measureText(sign).width + 44 * k;
        ctx.fillStyle = C.white; roundRect(ctx, cx - sw / 2, ch - 100 * k, sw, 64 * k, 10 * k); ctx.fill();
        ctx.fillStyle = C.ink; ctx.fillText(sign, cx, ch - 68 * k);
      }
    } else {
      const bigW = (cw - 3 * g) * 0.6, smallW = cw - 3 * g - bigW, smallH = (ch - 3 * g) / 2;
      place(ctx, at(0), g, g, bigW, ch - 2 * g, cut(0));
      place(ctx, at(1), 2 * g + bigW, g, smallW, smallH, cut(1));
      place(ctx, at(2), 2 * g + bigW, 2 * g + smallH, smallW, smallH, cut(2));
      if (title || price || sign) {
        ctx.font = '500 ' + Math.round(60 * k) + 'px ' + FONT;
        const boxW = bigW - 70 * k, lines = (title ? 1 : 0) + (price || sign ? 1 : 0), boxH = (lines === 2 ? 190 : 120) * k;
        const bx = g + 35 * k, by = ch - g - 35 * k - boxH;
        ctx.fillStyle = C.white; roundRect(ctx, bx, by, boxW, boxH, 14 * k); ctx.fill();
        ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        if (title) { ctx.fillStyle = C.ink; fit(ctx, title, boxW - 60 * k, 60 * k, '500', 24 * k); ctx.fillText(title, bx + 30 * k, by + (lines === 2 ? 62 : 60) * k); }
        const second = [price, sign].filter(Boolean).join(' · ');
        if (second) { ctx.fillStyle = C.ruby; fit(ctx, second, boxW - 60 * k, 40 * k, '500', 18 * k); ctx.fillText(second, bx + 30 * k, by + (lines === 2 ? 138 : 60) * k); }
      }
    }
    return { image: exportCanvas(cv, k < 1), size: { w: Math.round(cw), h: Math.round(ch) } };
  }

  window.SAMontage = { W, H, panorama, frise, collage, fonts, TEMPLATES: [['duo', 'Duo', 2], ['trio', 'Trio', 3], ['quatuor', 'Quatuor', 4]] };
})();
