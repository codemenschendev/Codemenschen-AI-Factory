/**
 * High-fidelity app-screen mockups, ported verbatim from the appwerk prototype
 * (appwerk/site/screens.js). Rendered inside a phone frame on the landing page.
 * Each app carries its own product branding via CSS vars (--a/--d/--al) on .ph;
 * the UI copy is German — these are product drafts for the DACH market.
 * SCREENS[appSlug] = [screenHTML ×3]
 */

const STATUS = `<div class="ph-status"><span>9:41</span><span class="ph-sig"><i></i><i></i><i style="width:13px"></i></span></div>`;
const nav = (icons: string[], on: number) =>
  `<div class="ph-nav">${icons.map((ic, i) => `<span class="${i === on ? "on" : ""}">${ic}</span>`).join("")}</div>`;
const appbar = (initials: string, greet: string, name: string) => `
  <div class="ph-appbar">
    <span class="ph-avatar">${initials}</span>
    <span class="ph-greet"><small>${greet}</small><b>${name}</b></span>
    <span class="ph-bell">🔔</span>
  </div>`;
const titlebar = (title: string, link?: string) => `
  <div class="ph-appbar">
    <span class="ph-back">‹</span>
    <span class="ph-title2" style="position:absolute;left:0;right:0;text-align:center;pointer-events:none">${title}</span>
    ${link ? `<span class="ph-link">${link}</span>` : ""}
  </div>`;

const FP = `--a:#2B5BE3;--d:#1E47C2;--al:#EAF0FE`;
const MG = `--a:#1A9E6C;--d:#0E7A4F;--al:#E3F5ED`;
const CB = `--a:#4F46E5;--d:#3730A3;--al:#ECEBFD`;
const PX = `--a:#7C3AED;--d:#5B21B6;--al:#F1EAFD`;
const RN = `--a:#0E9488;--d:#0B6E66;--al:#E1F4F2`;

export const SCREENS: Record<string, string[]> = {

    formpilot: [
      `<div class="ph" style="${FP}">${STATUS}
        ${appbar("MH", "Guten Morgen ☀️", "Maria Huber")}
        <div class="ph-body">
          <div class="ph-hero"><small>Aktive Formulare</small><span class="ph-big">12</span>
            <div class="ph-hero-stats"><span><b>248</b> Einsendungen</span><span><b>38 %</b> Conversion</span><span><b>4</b> Entwürfe</span></div>
          </div>
          <div class="ph-sec"><b>Schnellstart</b></div>
          <div class="ph-card"><div class="ph-drop">⬆&nbsp; PDF hierher ziehen</div></div>
          <div class="ph-sec"><b>Zuletzt bearbeitet</b><span>Alle ›</span></div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-tile">📄</span><span class="ph-txt"><b>Anmeldung 2026</b><small>vor 2 Std. · 41 Antworten</small></span><span class="ph-right"><span class="ph-badge ok">Live</span></span></div>
            <div class="ph-item"><span class="ph-tile">📑</span><span class="ph-txt"><b>Kunden-Briefing</b><small>gestern · Entwurf</small></span><span class="ph-right"><span class="ph-badge">Entwurf</span></span></div>
          </div>
        </div>
        <span class="ph-fab">+</span>
        ${nav(["🏠", "📄", "📊", "⚙️"], 0)}
      </div>`,
      `<div class="ph" style="${FP}">${STATUS}
        ${titlebar("Anmeldung 2026", "Vorschau")}
        <div class="ph-body">
          <div class="ph-pills"><span class="ph-pill on">Felder</span><span class="ph-pill">Design</span><span class="ph-pill">Logik</span></div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-tile">👤</span><span class="ph-txt"><b>Name</b><small>Textfeld</small></span><span class="ph-right"><span class="ph-badge">Pflicht</span></span></div>
            <div class="ph-item"><span class="ph-tile">✉️</span><span class="ph-txt"><b>E-Mail</b><small>E-Mail-Feld</small></span><span class="ph-right"><span class="ph-badge">Pflicht</span></span></div>
            <div class="ph-item"><span class="ph-tile">💬</span><span class="ph-txt"><b>Nachricht</b><small>Mehrzeilig</small></span><span class="ph-right"><span class="ph-badge warn">Optional</span></span></div>
            <div class="ph-item"><span class="ph-tile">📎</span><span class="ph-txt"><b>Datei-Upload</b><small>max. 10 MB</small></span><span class="ph-right"><span class="ph-badge warn">Optional</span></span></div>
          </div>
          <span class="ph-btn2 ghost">＋ Feld hinzufügen</span>
          <span class="ph-btn2" style="margin-top:6px">Formular veröffentlichen</span>
        </div>
        ${nav(["🏠", "📄", "📊", "⚙️"], 1)}
      </div>`,
      `<div class="ph" style="${FP}">${STATUS}
        ${titlebar("Statistik")}
        <div class="ph-body">
          <div class="ph-pills"><span class="ph-pill on">7 Tage</span><span class="ph-pill">30 Tage</span><span class="ph-pill">Jahr</span></div>
          <div class="ph-card">
            <div class="ph-sec" style="margin:0 0 6px"><b>Einsendungen</b><span>+23 %</span></div>
            <div class="ph-bars2"><i style="height:34%"></i><i style="height:52%"></i><i class="hi" style="height:78%"></i><i style="height:44%"></i><i class="hi" style="height:100%"></i><i style="height:58%"></i><i style="height:30%"></i></div>
            <div class="ph-axis"><span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span><span>Sa</span><span>So</span></div>
          </div>
          <div class="ph-sec"><b>Neueste Antworten</b><span>Alle ›</span></div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-avatar" style="width:22px;height:22px;font-size:7px">JK</span><span class="ph-txt"><b>Jonas K.</b><small>vor 12 Min.</small></span><span class="ph-right"><span class="ph-badge ok">Neu</span></span></div>
            <div class="ph-item"><span class="ph-avatar" style="width:22px;height:22px;font-size:7px;background:#7C3AED">AD</span><span class="ph-txt"><b>Aylin D.</b><small>vor 1 Std.</small></span><span class="ph-right"><span class="ph-badge ok">Neu</span></span></div>
          </div>
        </div>
        ${nav(["🏠", "📄", "📊", "⚙️"], 2)}
      </div>`
    ],

    mealgrid: [
      `<div class="ph" style="${MG}">${STATUS}
        ${appbar("JW", "Hallo 👋", "Jonas Weber")}
        <div class="ph-body">
          <div class="ph-hero"><small>Diese Woche geplant</small><span class="ph-big">5 Gerichte</span>
            <div class="ph-hero-stats"><span><b>82 %</b> Zutaten da</span><span><b>4</b> fehlen nur</span><span><b>−1,2 kg</b> Foodwaste</span></div>
          </div>
          <div class="ph-search">🔍&nbsp; Zutat hinzufügen …</div>
          <div class="ph-pills"><span class="ph-pill on">Eier ×6</span><span class="ph-pill on">Reis</span><span class="ph-pill on">Feta</span><span class="ph-pill">＋</span></div>
          <div class="ph-sec"><b>Läuft bald ab</b></div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-tile">🥬</span><span class="ph-txt"><b>Spinat</b><small>Kühlschrank · Fach 2</small></span><span class="ph-right"><span class="ph-badge warn">2 Tage</span></span></div>
            <div class="ph-item"><span class="ph-tile">🥛</span><span class="ph-txt"><b>Joghurt</b><small>Kühlschrank</small></span><span class="ph-right"><span class="ph-badge warn">3 Tage</span></span></div>
          </div>
          <span class="ph-btn2">Wochenplan erstellen ✨</span>
        </div>
        ${nav(["🏠", "🧊", "📅", "🛒"], 0)}
      </div>`,
      `<div class="ph" style="${MG}">${STATUS}
        ${titlebar("Wochenplan", "KW 28")}
        <div class="ph-body">
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">🍳</span><span class="ph-txt"><b>Shakshuka mit Feta</b><small>Mo · 20 Min · alles da ✓</small></span><span class="ph-right"><span class="ph-badge ok">0 fehlt</span></span></div></div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">🥘</span><span class="ph-txt"><b>Spinat-Reispfanne</b><small>Di · 25 Min · rettet Spinat 🥬</small></span><span class="ph-right"><span class="ph-badge ok">0 fehlt</span></span></div></div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">🍛</span><span class="ph-txt"><b>Linsencurry</b><small>Mi · 30 Min</small></span><span class="ph-right"><span class="ph-badge">2 fehlen</span></span></div></div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">🫑</span><span class="ph-txt"><b>Gefüllte Paprika</b><small>Do · 40 Min</small></span><span class="ph-right"><span class="ph-badge">1 fehlt</span></span></div></div>
          <div class="ph-card" style="opacity:0.55"><div class="ph-item"><span class="ph-tile">➕</span><span class="ph-txt"><b>Fr – So frei</b><small>Tippen zum Planen</small></span></div></div>
        </div>
        ${nav(["🏠", "🧊", "📅", "🛒"], 2)}
      </div>`,
      `<div class="ph" style="${MG}">${STATUS}
        ${titlebar("Einkaufsliste")}
        <div class="ph-body">
          <div class="ph-card ph-center" style="padding:12px">
            <div class="ph-ring ok" style="--p:67"><i>8/12<small>erledigt</small></i></div>
            <small style="color:#8B90A0;display:block;margin-top:6px">Nur was wirklich fehlt, alles andere hast du schon.</small>
          </div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-check on"></span><span class="ph-txt"><b class="ph-strike">Dosentomaten</b></span><span class="ph-right"><small style="color:#A7ACB9">2×</small></span></div>
            <div class="ph-item"><span class="ph-check on"></span><span class="ph-txt"><b class="ph-strike">Kokosmilch</b></span><span class="ph-right"><small style="color:#A7ACB9">1×</small></span></div>
            <div class="ph-item"><span class="ph-check"></span><span class="ph-txt"><b>Petersilie</b></span><span class="ph-right"><small style="color:#8B90A0">1 Bund</small></span></div>
            <div class="ph-item"><span class="ph-check"></span><span class="ph-txt"><b>Zitrone</b></span><span class="ph-right"><small style="color:#8B90A0">2×</small></span></div>
          </div>
          <span class="ph-btn2 ghost">Liste teilen 📤</span>
        </div>
        ${nav(["🏠", "🧊", "📅", "🛒"], 3)}
      </div>`
    ],

    countbee: [
      `<div class="ph" style="${CB}">${STATUS}
        ${titlebar("Scanner")}
        <div class="ph-body">
          <div class="ph-cam"><span class="ph-laser"></span><div class="ph-barcode"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-tile">🧃</span><span class="ph-txt"><b>Bio-Apfelsaft 1 l</b><small>EAN 9002490 · Regal 3</small></span><span class="ph-stepper"><span>−</span><b>12</b><span>＋</span></span></div>
          </div>
          <span class="ph-btn2">Zum Bestand hinzufügen</span>
          <div class="ph-center" style="margin-top:6px"><span class="ph-badge ok">✓ 83 heute gescannt</span></div>
        </div>
        ${nav(["📷", "📦", "📈", "⚙️"], 0)}
      </div>`,
      `<div class="ph" style="${CB}">${STATUS}
        ${appbar("LM", "Laden Mitte", "Bestand · 84 Artikel")}
        <div class="ph-body">
          <div class="ph-search">🔍&nbsp; Artikel suchen …</div>
          <div class="ph-pills"><span class="ph-pill on">Alle</span><span class="ph-pill">Getränke</span><span class="ph-pill">Regal 3</span><span class="ph-pill">Niedrig</span></div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-tile">🧃</span><span class="ph-txt"><b>Bio-Apfelsaft 1 l</b><small>Regal 3</small></span><span class="ph-right"><b style="font-size:10px">12</b> <span class="ph-badge ok">ok</span></span></div>
            <div class="ph-item"><span class="ph-tile">🧀</span><span class="ph-txt"><b>Bergkäse 200 g</b><small>Kühlung</small></span><span class="ph-right"><b style="font-size:10px">7</b> <span class="ph-badge ok">ok</span></span></div>
            <div class="ph-item"><span class="ph-tile">🍞</span><span class="ph-txt"><b>Roggenbrot</b><small>Regal 1</small></span><span class="ph-right"><b style="font-size:10px">3</b> <span class="ph-badge warn">niedrig</span></span></div>
            <div class="ph-item"><span class="ph-tile">🍯</span><span class="ph-txt"><b>Honig 500 g</b><small>Regal 2</small></span><span class="ph-right"><b style="font-size:10px">9</b> <span class="ph-badge ok">ok</span></span></div>
          </div>
        </div>
        <span class="ph-fab">＋</span>
        ${nav(["📷", "📦", "📈", "⚙️"], 1)}
      </div>`,
      `<div class="ph" style="${CB}">${STATUS}
        ${titlebar("Inventur 07/2026", "Teilen")}
        <div class="ph-body">
          <div class="ph-hero"><small>Warenwert gesamt</small><span class="ph-big">€ 4.812</span>
            <div class="ph-hero-stats"><span><b>84/84</b> gezählt</span><span><b>2</b> Differenzen</span><span><b>38 Min.</b> Dauer</span></div>
          </div>
          <div class="ph-card ph-center" style="padding:12px">
            <div class="ph-ring ok" style="--p:100"><i>100 %<small>fertig</small></i></div>
            <small style="color:#8B90A0;display:block;margin-top:6px">Inventur abgeschlossen · 08.07.2026, 14:32</small>
          </div>
          <span class="ph-btn2">Als CSV exportieren ⬇</span>
          <span class="ph-btn2 ghost" style="margin-top:6px">An Steuerberater senden</span>
        </div>
        ${nav(["📷", "📦", "📈", "⚙️"], 2)}
      </div>`
    ],

    praxo: [
      `<div class="ph" style="${PX}">${STATUS}
        ${appbar("PT", "Praxis Teubner", "Mittwoch, 8. Juli")}
        <div class="ph-body">
          <div class="ph-hero"><small>Heute</small><span class="ph-big">9 Termine</span>
            <div class="ph-hero-stats"><span><b>8</b> bestätigt ✓</span><span><b>0</b> No-Shows 🎉</span><span><b>1</b> Warteliste</span></div>
          </div>
          <div class="ph-sec"><b>Nächste Termine</b><span>Kalender ›</span></div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-tile">🕘</span><span class="ph-txt"><b>M. Leitner</b><small>09:00 · Physio 50 Min.</small></span><span class="ph-right"><span class="ph-badge ok">bestätigt</span></span></div>
            <div class="ph-item"><span class="ph-tile">🕥</span><span class="ph-txt"><b>P. Gruber</b><small>10:30 · Massage 30 Min.</small></span><span class="ph-right"><span class="ph-badge">SMS geplant</span></span></div>
            <div class="ph-item"><span class="ph-tile">🕛</span><span class="ph-txt"><b>S. Öztürk</b><small>12:00 · Physio 50 Min.</small></span><span class="ph-right"><span class="ph-badge ok">bestätigt</span></span></div>
          </div>
        </div>
        <span class="ph-fab">＋</span>
        ${nav(["🏠", "📅", "💬", "📈"], 0)}
      </div>`,
      `<div class="ph" style="${PX}">${STATUS}
        ${titlebar("Erinnerungen", "Heute")}
        <div class="ph-body" style="padding-top:6px">
          <div class="ph-bubble out">Erinnerung: Ihr Physio-Termin morgen um 09:00. Antworten Sie JA zum Bestätigen.<small>Gesendet 07:00 ✓✓</small></div>
          <div class="ph-bubble in"><b>JA</b><small>07:04</small></div>
          <div class="ph-center" style="margin:2px 0 8px"><span class="ph-badge ok">✓ Termin bestätigt, Kalender aktualisiert</span></div>
          <div class="ph-bubble out">Erinnerung: Ihr Termin morgen um 10:30 bei Praxis Teubner.<small>Gesendet 07:00 ✓✓</small></div>
          <div class="ph-bubble in">Muss leider verschieben 🙏<small>07:31</small></div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">🔁</span><span class="ph-txt"><b>Slot 10:30 wieder frei</b><small>Warteliste: 3 Personen</small></span><span class="ph-right"><span class="ph-badge">anbieten ›</span></span></div></div>
        </div>
        ${nav(["🏠", "📅", "💬", "📈"], 2)}
      </div>`,
      `<div class="ph" style="${PX}">${STATUS}
        ${titlebar("Statistik", "Quartal")}
        <div class="ph-body">
          <div class="ph-card">
            <div class="ph-sec" style="margin:0 0 6px"><b>No-Shows pro Monat</b><span class="ph-badge ok" style="margin-left:auto">−38 %</span></div>
            <div class="ph-bars2"><i class="hi" style="height:100%"></i><i class="hi" style="height:74%"></i><i class="hi" style="height:52%"></i><i class="ok" style="height:36%"></i></div>
            <div class="ph-axis"><span>Apr</span><span>Mai</span><span>Jun</span><span>Jul</span></div>
          </div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">✅</span><span class="ph-txt"><b>Bestätigungsquote</b><small>per SMS-Antwort</small></span><span class="ph-right"><b style="font-size:11px">91 %</b></span></div></div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">💶</span><span class="ph-txt"><b>Gerettete Slots</b><small>≈ 60 € je Slot</small></span><span class="ph-right"><b style="font-size:11px">14</b></span></div></div>
        </div>
        ${nav(["🏠", "📅", "💬", "📈"], 3)}
      </div>`
    ],

    rechni: [
      `<div class="ph" style="${RN}">${STATUS}
        ${appbar("LK", "Studio Krainer e.U.", "Rechnungen")}
        <div class="ph-body">
          <div class="ph-hero"><small>Offen gesamt</small><span class="ph-big">€ 4.320</span>
            <div class="ph-hero-stats"><span><b>€ 1.860</b> überfällig</span><span><b>3</b> Mahnungen aktiv</span><span><b>Ø 21 T.</b> Zahlungsziel</span></div>
          </div>
          <div class="ph-pills"><span class="ph-pill">Alle</span><span class="ph-pill on">Offen</span><span class="ph-pill">Bezahlt</span></div>
          <div class="ph-card">
            <div class="ph-item"><span class="ph-tile">🏢</span><span class="ph-txt"><b>RE-1042 · Studio Wien</b><small>€ 2.460 · fällig in 6 Tagen</small></span><span class="ph-right"><span class="ph-badge">offen</span></span></div>
            <div class="ph-item"><span class="ph-tile">🏗️</span><span class="ph-txt"><b>RE-1039 · Bau Maier</b><small>€ 1.860 · 12 Tage überfällig</small></span><span class="ph-right"><span class="ph-badge hot">Mahnstufe 1</span></span></div>
            <div class="ph-item"><span class="ph-tile">🎨</span><span class="ph-txt"><b>RE-1041 · Agentur Nord</b><small>€ 980 · bezahlt in 9 Tagen</small></span><span class="ph-right"><span class="ph-badge ok">bezahlt</span></span></div>
          </div>
        </div>
        <span class="ph-fab">＋</span>
        ${nav(["🏠", "🧾", "⏰", "📈"], 0)}
      </div>`,
      `<div class="ph" style="${RN}">${STATUS}
        ${titlebar("Mahnplan · RE-1039")}
        <div class="ph-body">
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">✉️</span><span class="ph-txt"><b>Stufe 1 · freundlich</b><small>Tag 3 nach Fälligkeit</small></span><span class="ph-right"><span class="ph-badge ok">✓ gesendet</span></span></div></div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">📨</span><span class="ph-txt"><b>Stufe 2 · mit Frist</b><small>Tag 10 · Zahlungsfrist 7 Tage</small></span><span class="ph-right"><span class="ph-badge ok">✓ gesendet</span></span></div></div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">⚖️</span><span class="ph-txt"><b>Stufe 3 · letzte Mahnung</b><small>Tag 21 · geplant 14.07.</small></span><span class="ph-right"><span class="ph-badge warn">geplant</span></span></div></div>
          <div class="ph-card"><div class="ph-item"><span class="ph-tile">🤖</span><span class="ph-txt"><b>Automatisch senden</b><small>Ton: höflich, bestimmt</small></span><span class="ph-switch"></span></div></div>
          <span class="ph-btn2 ghost">Vorschau ansehen</span>
        </div>
        ${nav(["🏠", "🧾", "⏰", "📈"], 2)}
      </div>`,
      `<div class="ph" style="${RN}">${STATUS}
        ${titlebar("Rechni")}
        <div class="ph-body ph-center ph-conf" style="position:relative">
          <i style="left:22%;top:16%;background:#0E9488"></i><i style="left:74%;top:12%;background:#F2B33D"></i><i style="left:60%;top:30%;background:#7C3AED"></i><i style="left:30%;top:34%;background:#2B5BE3"></i>
          <div class="ph-bigcheck"></div>
          <div style="font-size:12px;font-weight:700">Zahlung eingegangen 🎉</div>
          <small style="color:#8B90A0;display:block;margin:2px 0 10px">RE-1039 · Bau Maier · <b style="color:#1B1E24">€ 1.860</b></small>
          <div class="ph-card" style="text-align:left"><div class="ph-item"><span class="ph-tile">⚡</span><span class="ph-txt"><b>Nach Stufe 2 bezahlt</b><small>Ø 9 Tage schneller als ohne Rechni</small></span></div></div>
          <span class="ph-btn2 ghost">Beleg ansehen</span>
        </div>
        ${nav(["🏠", "🧾", "⏰", "📈"], 0)}
      </div>`
    ]
};
