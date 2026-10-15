/*
 * Les graphiques des écrans (espace franchisé : Vues et abonnés, Grille Instagram ; franchiseur : Grille).
 * Une série par graphique, sur un seul axe ; des bandes régulières (un jour, une publication), le point au
 * milieu de sa bande ; au survol, un repère commun aux graphiques du même axe et l'infobulle du graphique
 * survolé ; un clic rend la bande (`ouvrir`). Rend les valeurs que les gabarits .dc.html dessinent : courbe
 * et aire en SVG (viewBox 0 0 1000 100), barres en blocs, graduations, dates, points (marges : 44 px à
 * gauche pour les graduations, 10 px à droite, 12 px en haut, 24 px en bas pour les dates).
 *
 *   const G = window.Graphes({ n, titre: (i) => …, court: (i) => …, get: () => hov, set: (h) => …, ouvrir: (i) => … });
 *   G.courbe('cle', { title, sub, serie, note, vide, zero, H }, valeurs, couleur, marque(i), lignes(i))
 *   G.barres('cle', { title, sub, note, vide, legend, H, up, down }, hauts, bas | null, lignes(i))
 */
(function () {
  // Chaque compte garde sa couleur (Facebook, Instagram) ; gagnés / perdus : paire lisible par tous (daltonisme vérifié).
  const C = { fb: '#1877F2', ig: '#C13584', mark: '#232220', up: '#1F8A60', down: '#D97B29' };
  const fmt = (v, d = 0) => v == null || isNaN(v) ? '—' : Number(v).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const axe = (v) => Math.abs(v) >= 10000 ? fmt(v / 1000, Math.abs(v) >= 100000 ? 0 : 1) + ' k' : fmt(v);
  const signe = (v) => v == null ? '—' : v > 0 ? '+' + fmt(v) : v < 0 ? '−' + fmt(-v) : '0';
  const MO = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const court = (d) => { const m = String(d || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? +m[3] + ' ' + MO[+m[2] - 1] : ''; };
  /** Graduations « rondes » (1, 2, 5 × 10ⁿ, pas entier) couvrant [lo, hi]. */
  const nice = (lo, hi, k = 4) => {
    if (!(hi > lo)) hi = lo + 1;
    const raw = (hi - lo) / k, p = Math.pow(10, Math.floor(Math.log10(raw))), m = raw / p;
    const step = Math.max(1, (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p), a = Math.floor(lo / step) * step, b = Math.ceil(hi / step) * step, t = [];
    for (let v = a; v <= b + step / 2; v += step) t.push(Math.round(v) || 0);
    return { lo: a, hi: b, ticks: t };
  };
  const lg = {
    ligne: (c, n) => ({ c, n, w: '14px', h: '2px', r: '1px', ring: 'none' }),
    point: (n) => ({ c: C.mark, n, w: '8px', h: '8px', r: '50%', ring: '0 0 0 2px #FFFFFF, 0 0 0 2.5px rgba(34,34,34,0.25)' }),
    barre: (c, n) => ({ c, n, w: '10px', h: '10px', r: '3px', ring: 'none' }),
  };

  /**
   * Les jours qu'un compte n'a pas encore (ou pas entièrement) comptés chez Meta : anormalement bas (moins de
   * 10 % du jour médian), souvent les 2 ou 3 derniers jours. Laissés en blanc et hors des moyennes, signalés.
   */
  const incomplets = (vals) => { const ok = vals.filter(v => v != null).sort((a, b) => a - b), med = ok.length ? ok[Math.floor(ok.length / 2)] : 0;
    return vals.map(v => v != null && med >= 100 && v < med * 0.1); };
  /** La phrase qui signale ces jours (`jours` : les dates AAAA-MM-JJ des bandes). */
  const plages = (bad, jours) => { const out = []; let a = -1;
    bad.concat([false]).forEach((x, i) => { if (x && a < 0) a = i; if (!x && a >= 0) {
      const m1 = jours[a].slice(5, 7), m2 = jours[i - 1].slice(5, 7);
      out.push(a === i - 1 ? court(jours[a]) : m1 === m2 ? +jours[a].slice(8, 10) + '–' + court(jours[i - 1]) : court(jours[a]) + ' – ' + court(jours[i - 1])); a = -1; } });
    return out.length ? 'Chiffres incomplets chez Meta (anormalement bas), laissés en blanc et hors des moyennes : ' + out.join(', ').replace(/\.?$/, '.')
      + (bad.slice(-4).some(Boolean) ? ' Meta compte souvent les derniers jours avec quelques jours de retard.' : '') : ''; };

  function Graphes(cfg) {
    const n = Math.max(1, cfg.n), h0 = cfg.get ? cfg.get() : null, hov = h0 && h0.i < n ? h0 : null;
    const PT = 12, PB = 24, fOf = (i) => (i + 0.5) / n, xAt = (f) => 'calc(44px + (100% - 54px) * ' + f.toFixed(4) + ')';
    const dates = () => { const k = Math.min(n, cfg.etiquettes || 5), out = [];
      for (let j = 0; j < k; j++) { const i = k === 1 ? 0 : Math.round(j * (n - 1) / (k - 1)); out.push({ left: xAt(fOf(i)), label: cfg.court(i) }); }
      return out; };
    const idx = (e) => { const b = e.currentTarget.getBoundingClientRect(); return Math.max(0, Math.min(n - 1, Math.floor((e.clientX - b.left) / Math.max(1, b.width) * n))); };
    const survol = (k, lignes) => ({
      move: (e) => { const i = idx(e), h = cfg.get(); if (!h || h.i !== i || h.k !== k) cfg.set({ k, i }); },
      leave: () => { if (cfg.get()) cfg.set(null); },
      click: (e) => { if (cfg.ouvrir) cfg.ouvrir(idx(e)); },
      hasCross: !!hov, crossLeft: hov ? xAt(fOf(hov.i)) : '0px', hasTip: !!hov && hov.k === k,
      tipX: hov && fOf(hov.i) > 0.6 ? 'translateX(calc(-100% - 12px))' : 'translateX(12px)',
      tipTitle: hov ? cfg.titre(hov.i) : '', tipLines: hov && hov.k === k ? lignes(hov.i) : [] });
    const carte = (o) => Object.assign({ isLine: false, isBars: false, isTop: false, legend: [], grid: [], dots: [], xl: [], bars: [], rows: [], hasNote: !!o.note, note: o.note || '', empty: false,
      hasCross: false, hasTip: false, tipLines: [], move: () => {}, leave: () => {}, click: () => {} }, o);

    /** Une courbe (une série) : `vals` un nombre ou null par bande ; `marque(i)` : un point noir sur la bande (jour de publication). */
    const courbe = (k, o, vals, color, marque, lignes) => {
      const ok = vals.filter(v => v != null);
      if (!ok.length) return carte({ title: o.title, sub: o.sub, note: o.vide });
      const zero = o.zero !== false, H = o.H || 200, PH = H - PT - PB;
      const sc = zero ? nice(0, Math.max(...ok)) : nice(Math.min(...ok), Math.max(...ok));
      const y = (v) => 1 - (v - sc.lo) / (sc.hi - sc.lo);
      let line = '', area = '', seg = [];
      const fin = () => { if (!seg.length) return; line += seg.map((p, j) => (j ? 'L' : 'M') + p[0] + ' ' + p[1]).join(' ') + ' ';
        if (zero && seg.length > 1) area += 'M' + seg[0][0] + ' 100 ' + seg.map(p => 'L' + p[0] + ' ' + p[1]).join(' ') + ' L' + seg[seg.length - 1][0] + ' 100 Z ';
        seg = []; };
      vals.forEach((v, i) => { if (v == null) fin(); else seg.push([(fOf(i) * 1000).toFixed(1), (y(v) * 100).toFixed(2)]); });
      fin();
      const dots = [];
      vals.forEach((v, i) => { if (v != null && marque && marque(i)) dots.push({ left: xAt(fOf(i)), top: (PT + y(v) * PH).toFixed(1) + 'px', size: '8px', bg: C.mark }); });
      if (hov && vals[hov.i] != null) dots.push({ left: xAt(fOf(hov.i)), top: (PT + y(vals[hov.i]) * PH).toFixed(1) + 'px', size: '10px', bg: color });
      return carte(Object.assign({ isLine: true, title: o.title, sub: o.sub, note: o.note, H: H + 'px', PH: PH + 'px', line, area, color, fill: color + '1A',
        legend: o.legend || [lg.ligne(color, o.serie)].concat(marque ? [lg.point(o.marque || 'jour avec publication')] : []),
        grid: sc.ticks.map(t => ({ top: (PT + y(t) * PH).toFixed(1) + 'px', labTop: (PT + y(t) * PH - 6).toFixed(1) + 'px', label: axe(t), line: zero && t === sc.lo ? 'rgba(34,34,34,0.30)' : 'rgba(34,34,34,0.08)' })),
        dots, xl: dates() }, survol(k, lignes)));
    };
    /** Des barres : `hauts` au-dessus de la ligne de base, `bas` (facultatif) en dessous (gagnés / perdus). */
    const barres = (k, o, hauts, bas, lignes) => {
      if (!hauts.some(v => v != null)) return carte({ title: o.title, sub: o.sub, note: o.vide });
      const H = o.H || 160, PH = H - PT - PB, up = o.up || C.up, down = o.down || C.down;
      const sc = nice(-Math.max(0, ...(bas || []).filter(v => v != null)), Math.max(1, ...hauts.filter(v => v != null)), bas ? 4 : 3);
      const y = (v) => (sc.hi - v) / (sc.hi - sc.lo) * PH, y0 = y(0);
      const bars = hauts.map((g, i) => { const p = bas ? bas[i] : 0, hu = g ? y0 - y(g) : 0, hd = p ? y(-p) - y0 : 0;
        return { upTop: (y0 - hu).toFixed(1) + 'px', upH: hu.toFixed(1) + 'px', downTop: (y0 + 1).toFixed(1) + 'px', downH: hd.toFixed(1) + 'px', upC: up, downC: down,
          colBg: hov && hov.i === i ? 'rgba(34,34,34,0.06)' : 'transparent' }; });
      return carte(Object.assign({ isBars: true, title: o.title, sub: o.sub, note: o.note, H: H + 'px', PH: PH + 'px', bars, legend: o.legend || [],
        grid: sc.ticks.map(t => ({ top: (PT + y(t)).toFixed(1) + 'px', labTop: (PT + y(t) - 6).toFixed(1) + 'px', label: bas ? signe(t) : axe(t), line: t === 0 ? 'rgba(34,34,34,0.30)' : 'rgba(34,34,34,0.08)' })),
        xl: dates() }, survol(k, lignes)));
    };
    return { courbe, barres, carte, n };
  }
  Object.assign(Graphes, { C, fmt, axe, signe, court, nice, lg, incomplets, plages });
  window.Graphes = Graphes;
})();
