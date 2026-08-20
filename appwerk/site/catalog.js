/* Appwerk — central app catalog. One object per app; app.html renders the
   detail page from this, index.html renders the cards, checkout.html reads
   pricing + Stripe links. All display strings are {en, de} pairs.
   ⚠ All numbers are illustrative placeholders until real listings exist. */

window.CATALOG = {

  formpilot: {
    name: "FormPilot", icon: "◧", status: "available",
    price: 3900, retainerPct: 7, retainer: 273,
    stripe: "https://buy.stripe.com/test_dRmbJ39aH6Orc6G4yA2wU00",
    maintenance: { en: "≈ €80–115/month [pending final rate]", de: "≈ 80–115 €/Monat [endgültiger Satz offen]" },
    adsCtx: [500, 2000], adsDefault: 500,
    /* unit economics for the scenario model: assumed price point, AI-estimated
       cost per paying user (category benchmark), monthly churn (never modelled
       below 4%). All assumed — no app is pre-validated with real users. */
    unit: { priceMo: 9, cac: 28, churn: 0.10, startPayers: 0, assumed: true },
    buildCost: 2700,
    aud: [
      { i: "🏢", en: "Micro-agencies, 1–10 people", de: "Kleinstagenturen, 1–10 Personen" },
      { i: "🧑‍💻", en: "Freelance consultants & designers", de: "Freelance-Berater & Designer" },
      { i: "📄", en: "Anyone drowning in PDF forms", de: "Alle, die in PDF-Formularen versinken" }
    ],
    /* AI category scores (0–100, estimated from app-store analysis):
       demand vs. specialised supply, and share of unaddressed complaint themes */
    catScore: { demand: 78, supply: 24, unmet: 62 },
    cat: { en: "B2B · Form automation", de: "B2B · Formular-Automatisierung" },
    cardDesc: { en: "Turns PDF forms into fillable web flows for small agencies.", de: "Macht aus PDF-Formularen ausfüllbare Web-Flows für kleine Agenturen." },
    cardVal: { en: "≈ €1,150/mo potential", de: "≈ 1.150 €/Monat möglich" },
    lede: { en: "FormPilot turns PDF forms into fillable web flows for small agencies — upload a PDF, get a shareable web form with submissions in a dashboard. Selected by our AI analysis of the app store, built and run by us, licensed exclusively.",
            de: "FormPilot macht aus PDF-Formularen ausfüllbare Web-Flows für kleine Agenturen — PDF hochladen, teilbares Webformular erhalten, Einsendungen im Dashboard. Von unserer KI-Analyse des App Stores ausgewählt, von uns gebaut und betrieben, exklusiv lizenziert." },
    why: [
      { h: { en: "The demand signal is loud.", de: "Das Nachfrage-Signal ist laut." }, p: { en: "Thousands of app-store reviews complain about broken PDF-to-form workflows — the AI ranks this gap in the top percentile of the category.", de: "Tausende App-Store-Rezensionen klagen über kaputte PDF-zu-Formular-Workflows — die KI stuft diese Lücke im obersten Perzentil der Kategorie ein." } },
      { h: { en: "Buyers have budgets, not allowances.", de: "Die Käufer haben Budgets, kein Taschengeld." }, p: { en: "Micro-agencies pay from business budgets and churn less impulsively. €9/month sits well below the category's price complaints.", de: "Kleinstagenturen zahlen aus Geschäftsbudgets und kündigen weniger impulsiv. 9 €/Monat liegt deutlich unter den Preis-Beschwerden der Kategorie." } },
      { h: { en: "The niche is real and unserved.", de: "Die Nische ist echt und unbesetzt." }, p: { en: "Incumbents price at €20–60/month, none focus on PDF-to-webform. A focused tool can win what the big players ignore.", de: "Die Etablierten liegen bei 20–60 €/Monat, keiner fokussiert PDF-zu-Webformular. Ein fokussiertes Tool kann gewinnen, was die Großen ignorieren." } }
    ],
    market: [
      [{ en: "Category", de: "Kategorie" }, { en: "Form-builder / document-automation SaaS", de: "Formular-Builder / Dokumenten-Automatisierung (SaaS)" }],
      [{ en: "Established players & prices", de: "Etablierte Anbieter & Preise" }, { en: "Jotform from $34/mo (35M users 2025), Typeform from $29/mo — established global players, no DACH-focused PDF-to-form specialist. Source: Jotform/Typeform pricing pages 2026", de: "Jotform ab 34 $/Monat (35 Mio. Nutzer 2025), Typeform ab 29 $/Monat — etablierte globale Anbieter, kein DACH-fokussierter PDF-zu-Formular-Spezialist. Quelle: Jotform/Typeform-Preisseiten 2026" }],
      [{ en: "Market size", de: "Marktgröße" }, { en: "Online form builder market ~$4.1B (2024), ~11% CAGR — estimates vary widely ($0.6–4B) by analyst. Source: Verified Market Research 2024", de: "Form-Builder-Markt weltweit ca. 4,1 Mrd. USD (2024), ~11 % CAGR — Schätzungen je nach Analyst stark abweichend (0,6–4 Mrd.). Quelle: Verified Market Research 2024" }],
      [{ en: "Demand indicators", de: "Nachfrage-Indikatoren" }, { en: "Jotform grew by 10M users in 2025 to 35M total; est. ARR ~$145M (2024). Sources: Jotform blog 2026, Latka 2024", de: "Jotform wuchs 2025 um 10 Mio. auf 35 Mio. Nutzer; geschätzter ARR ~145 Mio. USD (2024). Quellen: Jotform-Blog 2026, Latka 2024" }]
    ],
    prodCost: { en: "≈ €2,700 development", de: "≈ 2.700 € Entwicklung" },
    opps: [
      { h: { en: "An open niche, today", de: "Eine offene Nische, heute" }, p: { en: "No incumbent focuses on PDF-to-webform. The first focused mover takes what Typeform and Jotform ignore.", de: "Kein Etablierter fokussiert PDF-zu-Webformular. Der erste fokussierte Anbieter holt sich, was Typeform und Jotform ignorieren." } },
      { h: { en: "A clear growth lever", de: "Ein klarer Wachstumshebel" }, p: { en: "≈€28 per paying user as category benchmark — every €500 of ads could bring ≈18 new B2B subscriptions (estimate).", de: "≈ 28 € pro zahlendem Nutzer als Kategorie-Benchmark — jede 500 € Ads könnten ≈ 18 neue B2B-Abos bringen (Schätzung)." } },
      { h: { en: "Upside you own", de: "Upside, die dir gehört" }, p: { en: "Prove retention past month three and the license value multiplies — resale on the platform, full history attached.", de: "Beweise die Bindung über Monat drei hinaus und der Lizenzwert vervielfacht sich — Resale auf der Plattform, mit voller Historie." } }
    ]
  },

  mealgrid: {
    name: "Mealgrid", icon: "◔", status: "available",
    price: 1400, retainerPct: 6, retainer: 84,
    stripe: "https://buy.stripe.com/test_5kQaEZ2Mj5Kn6Mm1mo2wU01",
    maintenance: { en: "≈ €30–42/month [pending final rate]", de: "≈ 30–42 €/Monat [endgültiger Satz offen]" },
    adsCtx: [300, 1500], adsDefault: 300,
    unit: { priceMo: 4.99, cac: 16, churn: 0.16, startPayers: 0, assumed: true },
    buildCost: 1100,
    aud: [
      { i: "🏠", en: "Busy working households", de: "Berufstätige Haushalte" },
      { i: "🥦", en: "Food-waste avoiders", de: "Lebensmittel-Retter" },
      { i: "🛒", en: "People who hate shopping lists", de: "Einkaufslisten-Muffel" }
    ],
    catScore: { demand: 71, supply: 45, unmet: 48 },
    cat: { en: "Consumer · Meal planning", de: "Consumer · Essensplanung" },
    cardDesc: { en: "Weekly meal plans from what's already in your fridge.", de: "Wochenpläne aus dem, was ohnehin im Kühlschrank ist." },
    cardVal: { en: "≈ €510/mo potential", de: "≈ 510 €/Monat möglich" },
    lede: { en: "Mealgrid builds a weekly meal plan from what's already in your fridge — enter what you have, get a plan and a minimal shopping list. Selected by our AI analysis of the app store, built and run by us, licensed exclusively.",
            de: "Mealgrid erstellt einen Wochen-Essensplan aus dem, was ohnehin im Kühlschrank ist — eingeben, was da ist, Plan und minimale Einkaufsliste erhalten. Von unserer KI-Analyse des App Stores ausgewählt, von uns gebaut und betrieben, exklusiv lizenziert." },
    why: [
      { h: { en: "Acquisition benchmarks are cheap.", de: "Die Akquise-Benchmarks sind günstig." }, p: { en: "≈€16 per paying user via ordinary Instagram ads (category benchmark). Boring channels tend to keep working.", de: "≈ 16 € pro zahlendem Nutzer über gewöhnliche Instagram-Ads (Kategorie-Benchmark). Langweilige Kanäle funktionieren meist weiter." } },
      { h: { en: "The hook is the category's top wish.", de: "Der Aufhänger ist der Top-Wunsch der Kategorie." }, p: { en: "“Cook from what's in the fridge” is the most-requested missing feature in the reviews our AI analyzed.", de: "„Kochen aus dem, was da ist“ ist das meistgewünschte fehlende Feature in den Rezensionen, die unsere KI analysiert hat." } },
      { h: { en: "Small but coherent economics.", de: "Kleine, aber stimmige Zahlen." }, p: { en: "Low license, low retainer, cheap acquisition — deliberately a first app for learning the model.", de: "Niedriger Lizenzpreis, niedrige Pauschale, günstige Akquise — bewusst eine erste App, um das Modell kennenzulernen." } }
    ],
    market: [
      [{ en: "Category", de: "Kategorie" }, { en: "Meal-planning / recipe apps (consumer subscription)", de: "Essensplanungs- / Rezept-Apps (Consumer-Abo)" }],
      [{ en: "Established players & prices", de: "Etablierte Anbieter & Preise" }, { en: "KptnCook (Berlin) Premium $5.99/mo, Mealime freemium (Pro price not public) — freemium consumer pricing dominates. Source: KptnCook FAQ 2026", de: "KptnCook (Berlin) Premium 5,99 $/Monat, Mealime Freemium (Pro-Preis nicht öffentlich) — Freemium-Preismodelle dominieren. Quelle: KptnCook-FAQ 2026" }],
      [{ en: "Market size", de: "Marktgröße" }, { en: "Meal planning apps ~$2.45B globally (2025, 10.5% CAGR); broader nutrition apps ~$6B revenue. Sources: Business Research Insights 2025, Statista 2025", de: "Meal-Planning-Apps weltweit ca. 2,45 Mrd. USD (2025, 10,5 % CAGR); Nutrition-Apps gesamt ~6 Mrd. USD Umsatz. Quellen: Business Research Insights 2025, Statista 2025" }],
      [{ en: "Demand indicators", de: "Nachfrage-Indikatoren" }, { en: "DACH competitor KptnCook: ~2.3M Play Store downloads, claims 7M+ users; Mealime claims 5M+ users. Sources: AppBrain 2026, vendor claims", de: "DACH-Wettbewerber KptnCook: ~2,3 Mio. Play-Store-Downloads, Eigenangabe 7 Mio.+ Nutzer; Mealime laut Eigenangabe 5 Mio.+ Nutzer. Quellen: AppBrain 2026, Anbieterangaben" }]
    ],
    prodCost: { en: "≈ €1,100 development", de: "≈ 1.100 € Entwicklung" },
    opps: [
      { h: { en: "The cheapest consumer reach", de: "Die günstigste Consumer-Reichweite" }, p: { en: "≈€16 per paying user via Instagram ads (category benchmark) — a boring, repeatable channel with room to scale.", de: "≈ 16 € pro zahlendem Nutzer über Instagram-Ads (Kategorie-Benchmark) — ein langweiliger, wiederholbarer Kanal mit Luft nach oben." } },
      { h: { en: "An unclaimed wedge", de: "Ein unbesetzter Hebel" }, p: { en: "Every incumbent is recipe-first. Fridge-first as a weekly habit is Mealgrid's — win the loop and consumer scale kicks in.", de: "Alle Etablierten sind Rezept-zuerst. Kühlschrank-zuerst als Wochengewohnheit gehört Mealgrid — gewinn die Routine und Consumer-Skalierung greift." } },
      { h: { en: "The lightest entry ticket", de: "Der leichteste Einstieg" }, p: { en: "Low license, low retainer: the most affordable way to run the whole model with real upside.", de: "Niedrige Lizenz, niedrige Pauschale: der günstigste Weg, das ganze Modell mit echter Upside zu fahren." } }
    ]
  },

  countbee: {
    name: "Countbee", icon: "◒", status: "available",
    price: 300, retainerPct: 10, retainer: 30,
    stripe: "https://buy.stripe.com/test_28E6oJdqXfkX4Ee6GI2wU02",
    maintenance: { en: "≈ €6–9/month [pending final rate]", de: "≈ 6–9 €/Monat [endgültiger Satz offen]" },
    adsCtx: [100, 400], adsDefault: 100,
    unit: { priceMo: 3.99, cac: 11, churn: 0.09, startPayers: 0, assumed: true },
    buildCost: 240,
    aud: [
      { i: "🏪", en: "Independent small shops", de: "Unabhängige kleine Läden" },
      { i: "🧮", en: "Year-end stocktake duty", de: "Jahresend-Inventur-Pflichtige" },
      { i: "👗", en: "Boutiques & concept stores", de: "Boutiquen & Concept Stores" }
    ],
    catScore: { demand: 54, supply: 18, unmet: 57 },
    cat: { en: "B2B · Inventory", de: "B2B · Inventur" },
    cardDesc: { en: "Stocktaking for small shops — scan, count, export.", de: "Inventur für kleine Läden — scannen, zählen, exportieren." },
    cardVal: { en: "≈ €275/mo potential", de: "≈ 275 €/Monat möglich" },
    lede: { en: "Countbee turns a phone into a stocktake scanner for small retail — scan barcodes, count stock, export a clean sheet for the accountant. Selected by our AI analysis of the app store, built and run by us, licensed exclusively.",
            de: "Countbee macht aus dem Handy einen Inventur-Scanner für kleine Läden — Barcodes scannen, Bestand zählen, saubere Tabelle für die Buchhaltung exportieren. Von unserer KI-Analyse des App Stores ausgewählt, von uns gebaut und betrieben, exklusiv lizenziert." },
    why: [
      { h: { en: "The competition is built for warehouses.", de: "Die Konkurrenz ist für Lagerhäuser gebaut." }, p: { en: "Inventory tools start at €30–100/month with features a corner shop never touches. A one-job tool at €3.99 attacks from below.", de: "Inventur-Tools starten bei 30–100 €/Monat mit Funktionen, die ein kleiner Laden nie braucht. Ein Ein-Zweck-Tool um 3,99 € greift von unten an." } },
      { h: { en: "Buyers search with exact words.", de: "Die Käufer suchen mit exakten Worten." }, p: { en: "“inventur app kleines geschäft” — exact-match searches at an estimated ≈€11 per payer, the cheapest acquisition estimate on the platform.", de: "„inventur app kleines geschäft“ — exakte Suchbegriffe bei geschätzt ≈ 11 € pro Zahlendem, die günstigste Akquise-Schätzung der Plattform." } },
      { h: { en: "The smallest possible commitment.", de: "Das kleinstmögliche Engagement." }, p: { en: "€300 license, €30/month. An experiment-sized way to learn the model — priced accordingly.", de: "300 € Lizenz, 30 €/Monat. Ein Experiment-großer Einstieg ins Modell — entsprechend bepreist." } }
    ],
    market: [
      [{ en: "Category", de: "Kategorie" }, { en: "Inventory / stocktaking apps for micro-retail", de: "Inventur-Apps für Kleinsthandel" }],
      [{ en: "Established players & prices", de: "Etablierte Anbieter & Preise" }, { en: "Sortly free–$299/mo (Advanced $49/mo) is the category leader; few mobile-first tools for micro-retail. Source: Sortly pricing page 2026", de: "Sortly gratis–299 $/Monat (Advanced 49 $/Monat) ist Kategorieführer; kaum Mobile-first-Tools für Kleinstläden. Quelle: Sortly-Preisseite 2026" }],
      [{ en: "Market size", de: "Marktgröße" }, { en: "Inventory management software ~$3.7B globally (2025), to $7.1B by 2033; SMB segment fastest-growing (~13.5% CAGR). Sources: Grand View Research 2026, GMI 2025", de: "Inventory-Management-Software weltweit ca. 3,7 Mrd. USD (2025), bis 2033 7,1 Mrd.; SMB-Segment wächst am schnellsten (~13,5 % CAGR). Quellen: Grand View Research 2026, GMI 2025" }],
      [{ en: "Demand indicators", de: "Nachfrage-Indikatoren" }, { en: "Sortly claims 20,000+ paying businesses at $49–299/mo; ~310K Play Store downloads — small niche but proven willingness to pay. Sources: Sortly 2026, AppBrain 2026", de: "Sortly laut Eigenangabe 20.000+ zahlende Firmen bei 49–299 $/Monat; ~310.000 Play-Store-Downloads — kleine Nische, aber belegte Zahlungsbereitschaft. Quellen: Sortly 2026, AppBrain 2026" }]
    ],
    prodCost: { en: "≈ €240 development", de: "≈ 240 € Entwicklung" },
    opps: [
      { h: { en: "Own the season", de: "Die Saison gehört dir" }, p: { en: "Stocktaking peaks in December — license in summer, own the whole year-end wave.", de: "Inventur peakt im Dezember — im Sommer lizenzieren, die ganze Jahresend-Welle mitnehmen." } },
      { h: { en: "The platform's cheapest acquisition estimate", de: "Die günstigste Akquise-Schätzung der Plattform" }, p: { en: "≈€11 per paying shop on exact-match searches (benchmark), and the German keyword pool is barely touched.", de: "≈ 11 € pro zahlendem Laden über exakte Suchbegriffe (Benchmark) — und der deutsche Keyword-Pool ist kaum angetastet." } },
      { h: { en: "Experiment-sized ticket", de: "Ticket in Experiment-Größe" }, p: { en: "€300 entry, €30/month — the smallest way to hold a full exclusive license with everything that comes with it.", de: "300 € Einstieg, 30 €/Monat — der kleinste Weg zu einer vollen Exklusivlizenz mit allem, was dazugehört." } }
    ]
  },

  praxo: {
    name: "Praxo", icon: "◍", status: "available",
    price: 2400, retainerPct: 7, retainer: 168,
    stripe: "https://buy.stripe.com/test_00wbJ32Mja0D2w67KM2wU03",
    maintenance: { en: "≈ €48–72/month [pending final rate]", de: "≈ 48–72 €/Monat [endgültiger Satz offen]" },
    adsCtx: [300, 1200], adsDefault: 300,
    // category shows the lowest churn — modelled at the 4% floor, never below
    unit: { priceMo: 19, cac: 40, churn: 0.04, startPayers: 0, assumed: true },
    buildCost: 1900,
    aud: [
      { i: "💆", en: "Physio & massage practices", de: "Physio- & Massagepraxen" },
      { i: "📞", en: "Front desks tired of phoning", de: "Rezeptionen, die nicht mehr hinterhertelefonieren wollen" },
      { i: "✂️", en: "Next: hairdressers & studios", de: "Als Nächstes: Friseure & Studios" }
    ],
    catScore: { demand: 66, supply: 21, unmet: 64 },
    cat: { en: "B2B · Appointment reminders", de: "B2B · Terminerinnerungen" },
    cardDesc: { en: "SMS appointment reminders for physio and massage practices.", de: "SMS-Terminerinnerungen für Physio- und Massagepraxen." },
    cardVal: { en: "≈ €1,380/mo potential", de: "≈ 1.380 €/Monat möglich" },
    lede: { en: "Praxo sends automatic SMS reminders synced from a practice calendar — no-shows drop, the front desk stops phoning. Selected by our AI analysis of the app store, built and run by us, licensed exclusively.",
            de: "Praxo verschickt automatische SMS-Erinnerungen aus dem Praxiskalender — No-Shows sinken, die Rezeption telefoniert nicht mehr hinterher. Von unserer KI-Analyse des App Stores ausgewählt, von uns gebaut und betrieben, exklusiv lizenziert." },
    why: [
      { h: { en: "No-shows are a quantified, named pain.", de: "No-Shows sind ein bezifferter, benannter Schmerz." }, p: { en: "A missed slot costs a practice €40–80. A €19 tool that reduces them sells itself on arithmetic, not persuasion.", de: "Ein verpasster Termin kostet eine Praxis 40–80 €. Ein 19-€-Tool, das sie reduziert, verkauft sich über Arithmetik, nicht Überredung." } },
      { h: { en: "The stickiest category.", de: "Die treueste Kategorie." }, p: { en: "Appointment tools show the lowest churn in the AI's category analysis — B2B utilities that remove a named pain stick.", de: "Termin-Tools zeigen den niedrigsten Churn in der Kategorie-Analyse der KI — B2B-Utilities, die einen benannten Schmerz beseitigen, bleiben." } },
      { h: { en: "Incumbents bundle, we unbundle.", de: "Die Etablierten bündeln, wir entbündeln." }, p: { en: "Reminders sit inside €80–150/month practice suites. Standalone at €19 wins the practices that only want this one job done.", de: "Erinnerungen stecken in Praxis-Suiten um 80–150 €/Monat. Standalone um 19 € gewinnt die Praxen, die nur diese eine Aufgabe gelöst haben wollen." } }
    ],
    market: [
      [{ en: "Category", de: "Kategorie" }, { en: "Practice software / appointment tools (B2B subscription)", de: "Praxissoftware / Termin-Tools (B2B-Abo)" }],
      [{ en: "Established players & prices", de: "Etablierte Anbieter & Preise" }, { en: "Doctolib from €139/mo per practitioner, Timify from ~€25/mo, appointmed (AT physio) €45–99/mo — big price gap below Doctolib. Source: vendor pricing pages 2026", de: "Doctolib ab 139 €/Monat pro Behandler, Timify ab ~25 €/Monat, appointmed (AT, Physio) 45–99 €/Monat — große Preislücke unterhalb von Doctolib. Quelle: Anbieter-Preisseiten 2026" }],
      [{ en: "Market size", de: "Marktgröße" }, { en: "Scheduling apps ~$663M globally (2025, 13.5% CAGR); no reliable figure for DACH physio/SMS-reminder niche. Source: Grand View Research 2025", de: "Scheduling-Apps weltweit ca. 663 Mio. USD (2025, 13,5 % CAGR); keine belastbare Zahl für die DACH-Physio-/SMS-Nische. Quelle: Grand View Research 2025" }],
      [{ en: "Demand indicators", de: "Nachfrage-Indikatoren" }, { en: "Doctolib: 25M patients and 110K practitioners in Germany (2025); vendors report SMS reminders cut no-shows 30–60%. Sources: Doctolib 2025, smsmode 2025", de: "Doctolib: 25 Mio. Patienten und 110.000 Behandler in Deutschland (2025); SMS-Erinnerungen senken No-Shows laut Anbietern um 30–60 %. Quellen: Doctolib 2025, smsmode 2025" }]
    ],
    prodCost: { en: "≈ €1,900 development", de: "≈ 1.900 € Entwicklung" },
    opps: [
      { h: { en: "The stickiest category", de: "Die treueste Kategorie" }, p: { en: "Appointment tools show the lowest churn in our AI analysis. B2B tools that remove a named pain tend to stick for years.", de: "Termin-Tools zeigen den niedrigsten Churn in unserer KI-Analyse. B2B-Tools, die einen benannten Schmerz beseitigen, bleiben oft jahrelang." } },
      { h: { en: "Sells on arithmetic", de: "Verkauft sich über Arithmetik" }, p: { en: "A missed slot costs a practice €40–80; Praxo costs €19. The pitch is a calculation, not a persuasion.", de: "Ein verpasster Termin kostet die Praxis 40–80 €; Praxo kostet 19 €. Der Pitch ist eine Rechnung, keine Überredung." } },
      { h: { en: "Verticals waiting next door", de: "Nachbar-Branchen warten schon" }, p: { en: "Hairdressers, studios, tutors — the same no-show pain, untouched. Each one is a roadmap vote away.", de: "Friseure, Studios, Nachhilfe — derselbe No-Show-Schmerz, unberührt. Jede Branche ist eine Roadmap-Entscheidung entfernt." } }
    ]
  },

  rechni: {
    name: "Rechni", icon: "◐", status: "funding",
    /* Funding-stage app: not built yet. goal = build + launch budget;
       committed = already-pledged amount. Build starts at 100%. One buyer can
       take it all (sole owner) or several split it pro-rata [COUNSEL REVIEW]. */
    funding: { goal: 3000, committed: 1500, minTicket: 300, deadlineDays: 60 },
    price: 3000, retainerPct: 7, retainer: 210,
    stripe: "",
    maintenance: { en: "≈ €60–90/month [pending final rate]", de: "≈ 60–90 €/Monat [endgültiger Satz offen]" },
    adsCtx: [200, 800], adsDefault: 200,
    /* Not built yet: pure assumptions, no live app behind them. */
    unit: { priceMo: 6, cac: 22, churn: 0.12, startPayers: 0, assumed: true },
    buildCost: 2300,
    aud: [
      { i: "🧑‍💻", en: "Austrian freelancers (376k EPU)", de: "Österreichische Freelancer (376.000 EPU)" },
      { i: "🧾", en: "Anyone chasing late invoices", de: "Alle, die Rechnungen hinterherlaufen" },
      { i: "🤝", en: "Agencies invoicing monthly", de: "Agenturen mit Monatsrechnungen" }
    ],
    catScore: { demand: 60, supply: 12, unmet: 71 },
    cat: { en: "B2B · Invoice reminders", de: "B2B · Zahlungserinnerungen" },
    cardDesc: { en: "Polite, automatic payment reminders for Austrian freelancers.", de: "Höfliche, automatische Zahlungserinnerungen für österreichische Freelancer." },
    cardVal: { en: "Not built yet — funding stage", de: "Noch nicht gebaut — Finanzierungsphase" },
    lede: { en: "Rechni chases unpaid invoices for freelancers — connect your invoicing, and overdue clients get polite, escalating reminders automatically. Not built yet: our AI analysis ranked this concept, and this listing funds its build and launch. Fund it alone and hold the exclusive license, or join with a share and hold a pro-rata revenue share.",
            de: "Rechni mahnt offene Rechnungen für Freelancer — Rechnungsstellung verbinden, säumige Kunden bekommen automatisch höfliche, eskalierende Erinnerungen. Noch nicht gebaut: Unsere KI-Analyse hat dieses Konzept ausgewählt; dieses Listing finanziert Bau und Launch. Finanziere allein und halte die exklusive Lizenz — oder steig mit einem Anteil ein und halte einen anteiligen Erlösanteil." },
    scope: [
      { en: "Build: reminder engine, e-mail templates, connection to common AT invoicing tools — ≈ €2,300", de: "Bau: Mahn-Engine, E-Mail-Vorlagen, Anbindung gängiger AT-Rechnungstools — ≈ 2.300 €" },
      { en: "Launch marketing: first ad campaigns after release — ≈ €700", de: "Launch-Marketing: erste Werbekampagnen nach dem Release — ≈ 700 €" },
      { en: "All KPIs come from the AI's app-store analysis — revenue figures are potential, reported live in the dashboard once real numbers exist.", de: "Alle KPIs stammen aus der App-Store-Analyse der KI — Einnahmen sind Potenziale und werden im Dashboard live berichtet, sobald echte Zahlen existieren." }
    ],
    opps: [
      { h: { en: "Shape it from day one", de: "Gestalte sie vom ersten Tag" }, p: { en: "Funders vote on the roadmap before the first line is built — the earliest influence the platform offers.", de: "Finanzierende stimmen über die Roadmap ab, bevor die erste Zeile gebaut ist — der früheste Einfluss, den die Plattform bietet." } },
      { h: { en: "A defined maximum stake", de: "Ein definierter Maximaleinsatz" }, p: { en: "Build and launch marketing are inside the goal. You see with real users how it flies — your exposure is capped at your ticket.", de: "Bau und Launch-Marketing stecken im Ziel. Du siehst mit echten Nutzern, wie sie fliegt — dein Einsatz ist auf dein Ticket begrenzt." } },
      { h: { en: "Nothing lost if it doesn't fill", de: "Nichts verloren, wenn er nicht füllt" }, p: { en: "60-day window, full refund if the deal doesn't reach 100%. Joining costs from €300.", de: "60-Tage-Fenster, volle Rückerstattung, wenn der Deal keine 100 % erreicht. Einstieg ab 300 €." } }
    ]
  },

  shiftly: {
    name: "Shiftly", icon: "◫", status: "taken",
    price: null, retainerPct: null, retainer: null, stripe: "",
    cat: { en: "B2B · Shift scheduling", de: "B2B · Schichtplanung" },
    cardDesc: { en: "Simple shift planning for hospitality teams.", de: "Einfache Schichtplanung für Gastro-Teams." },
    cardVal: { en: "Licensed exclusively in 2026", de: "2026 exklusiv lizenziert" }
  }
};
