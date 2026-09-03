/**
 * App-idea catalog — ported from appwerk/site/catalog.js and repositioned for
 * the build-service model: fixed development price, customer owns the app.
 * License/share/fund modes are parked pending counsel (PLAN.md §2).
 *
 * Static seed data for Phase 1; moves into the `listings` table in Phase 2.
 * ⚠ All market figures are AI-generated estimates from public sources.
 */
import type { I18nText } from "@/lib/i18n";
import type { AppType } from "@ai-factory/pricing";

export interface CatalogEntry {
  slug: string;
  name: string;
  icon: string;
  status: "available" | "built";
  /** Fixed development price in EUR (undefined for built/taken listings). */
  price?: number;
  appType?: AppType;
  weeksLo?: number;
  weeksHi?: number;
  cat: I18nText;
  cardDesc: I18nText;
  lede?: I18nText;
  aud?: { i: string; en: string; de: string }[];
  why?: { h: I18nText; p: I18nText }[];
  /** [label, value] rows; optional — detail page must render without it. */
  market?: [I18nText, I18nText][];
}

export const CATALOG: CatalogEntry[] = [
  {
    slug: "formpilot",
    name: "FormPilot",
    icon: "◧",
    status: "available",
    price: 3900,
    appType: "B",
    weeksLo: 6,
    weeksHi: 9,
    cat: { en: "B2B · Form automation", de: "B2B · Formular-Automatisierung" },
    cardDesc: {
      en: "Turns PDF forms into fillable web flows for small agencies.",
      de: "Macht aus PDF-Formularen ausfüllbare Web-Flows für kleine Agenturen.",
    },
    lede: {
      en: "FormPilot turns PDF forms into fillable web flows — upload a PDF, get a shareable web form with submissions in a dashboard. Selected by our AI analysis of app-store data; built exclusively for you, owned by you.",
      de: "FormPilot macht aus PDF-Formularen ausfüllbare Web-Flows — PDF hochladen, teilbares Webformular erhalten, Einsendungen im Dashboard. Von unserer KI-Analyse von App-Store-Daten ausgewählt; exklusiv für dich gebaut, dir gehörend.",
    },
    aud: [
      { i: "🏢", en: "Micro-agencies, 1–10 people", de: "Kleinstagenturen, 1–10 Personen" },
      { i: "🧑‍💻", en: "Freelance consultants & designers", de: "Freelance-Berater & Designer" },
      { i: "📄", en: "Anyone drowning in PDF forms", de: "Alle, die in PDF-Formularen versinken" },
    ],
    why: [
      {
        h: { en: "The demand signal is loud.", de: "Das Nachfrage-Signal ist laut." },
        p: {
          en: "Thousands of app-store reviews complain about broken PDF-to-form workflows — the AI ranks this gap in the top percentile of the category.",
          de: "Tausende App-Store-Rezensionen klagen über kaputte PDF-zu-Formular-Workflows — die KI stuft diese Lücke im obersten Perzentil der Kategorie ein.",
        },
      },
      {
        h: { en: "Buyers have budgets, not allowances.", de: "Die Käufer haben Budgets, kein Taschengeld." },
        p: {
          en: "Micro-agencies pay from business budgets and churn less impulsively. €9/month sits well below the category's price complaints.",
          de: "Kleinstagenturen zahlen aus Geschäftsbudgets und kündigen weniger impulsiv. 9 €/Monat liegt deutlich unter den Preis-Beschwerden der Kategorie.",
        },
      },
      {
        h: { en: "The niche is real and unserved.", de: "Die Nische ist echt und unbesetzt." },
        p: {
          en: "Incumbents price at €20–60/month, none focus on PDF-to-webform. A focused tool can win what the big players ignore.",
          de: "Die Etablierten liegen bei 20–60 €/Monat, keiner fokussiert PDF-zu-Webformular. Ein fokussiertes Tool kann gewinnen, was die Großen ignorieren.",
        },
      },
    ],
    market: [
      [
        { en: "Category", de: "Kategorie" },
        { en: "Form-builder / document-automation SaaS", de: "Formular-Builder / Dokumenten-Automatisierung (SaaS)" },
      ],
      [
        { en: "Established players & prices", de: "Etablierte Anbieter & Preise" },
        {
          en: "Jotform from $34/mo (35M users 2025), Typeform from $29/mo — no DACH-focused PDF-to-form specialist. Source: vendor pricing pages 2026",
          de: "Jotform ab 34 $/Monat (35 Mio. Nutzer 2025), Typeform ab 29 $/Monat — kein DACH-fokussierter PDF-zu-Formular-Spezialist. Quelle: Anbieter-Preisseiten 2026",
        },
      ],
      [
        { en: "Market size", de: "Marktgröße" },
        {
          en: "Form-builder market ~$4.1B (2024), ~11% CAGR — estimates vary widely by analyst. Source: Verified Market Research 2024",
          de: "Form-Builder-Markt ca. 4,1 Mrd. USD (2024), ~11 % CAGR — Schätzungen je nach Analyst stark abweichend. Quelle: Verified Market Research 2024",
        },
      ],
      [
        { en: "Demand indicators", de: "Nachfrage-Indikatoren" },
        {
          en: "Jotform grew by 10M users in 2025 to 35M total; est. ARR ~$145M (2024). Sources: Jotform blog 2026, Latka 2024",
          de: "Jotform wuchs 2025 um 10 Mio. auf 35 Mio. Nutzer; geschätzter ARR ~145 Mio. USD (2024). Quellen: Jotform-Blog 2026, Latka 2024",
        },
      ],
    ],
  },
  {
    slug: "mealgrid",
    name: "Mealgrid",
    icon: "◔",
    status: "available",
    price: 1400,
    appType: "A",
    weeksLo: 4,
    weeksHi: 7,
    cat: { en: "Consumer · Meal planning", de: "Consumer · Essensplanung" },
    cardDesc: {
      en: "Weekly meal plans from what's already in your fridge.",
      de: "Wochenpläne aus dem, was ohnehin im Kühlschrank ist.",
    },
    lede: {
      en: "Mealgrid builds a weekly meal plan from what's already in your fridge — enter what you have, get a plan and a minimal shopping list. Runs fully on the device: no accounts, no monthly fee.",
      de: "Mealgrid erstellt einen Wochen-Essensplan aus dem, was ohnehin im Kühlschrank ist — eingeben, was da ist, Plan und minimale Einkaufsliste erhalten. Läuft vollständig am Gerät: keine Accounts, keine Monatsgebühr.",
    },
    aud: [
      { i: "🏠", en: "Busy working households", de: "Berufstätige Haushalte" },
      { i: "🥦", en: "Food-waste avoiders", de: "Lebensmittel-Retter" },
      { i: "🛒", en: "People who hate shopping lists", de: "Einkaufslisten-Muffel" },
    ],
    why: [
      {
        h: { en: "The hook is the category's top wish.", de: "Der Aufhänger ist der Top-Wunsch der Kategorie." },
        p: {
          en: "“Cook from what's in the fridge” is the most-requested missing feature in the reviews our AI analyzed.",
          de: "„Kochen aus dem, was da ist“ ist das meistgewünschte fehlende Feature in den Rezensionen, die unsere KI analysiert hat.",
        },
      },
      {
        h: { en: "Acquisition benchmarks are cheap.", de: "Die Akquise-Benchmarks sind günstig." },
        p: {
          en: "≈€16 per paying user via ordinary Instagram ads (category benchmark). Boring channels tend to keep working.",
          de: "≈ 16 € pro zahlendem Nutzer über gewöhnliche Instagram-Ads (Kategorie-Benchmark). Langweilige Kanäle funktionieren meist weiter.",
        },
      },
      {
        h: { en: "Every incumbent is recipe-first.", de: "Alle Etablierten sind Rezept-zuerst." },
        p: {
          en: "Fridge-first as a weekly habit is an unclaimed wedge — win the loop and consumer scale kicks in.",
          de: "Kühlschrank-zuerst als Wochengewohnheit ist ein unbesetzter Hebel — gewinn die Routine und Consumer-Skalierung greift.",
        },
      },
    ],
    market: [
      [
        { en: "Category", de: "Kategorie" },
        { en: "Meal-planning / recipe apps (consumer)", de: "Essensplanungs- / Rezept-Apps (Consumer)" },
      ],
      [
        { en: "Established players & prices", de: "Etablierte Anbieter & Preise" },
        {
          en: "KptnCook (Berlin) Premium $5.99/mo, Mealime freemium — freemium pricing dominates. Source: KptnCook FAQ 2026",
          de: "KptnCook (Berlin) Premium 5,99 $/Monat, Mealime Freemium — Freemium-Preismodelle dominieren. Quelle: KptnCook-FAQ 2026",
        },
      ],
      [
        { en: "Market size", de: "Marktgröße" },
        {
          en: "Meal-planning apps ~$2.45B globally (2025, 10.5% CAGR). Source: Business Research Insights 2025",
          de: "Meal-Planning-Apps weltweit ca. 2,45 Mrd. USD (2025, 10,5 % CAGR). Quelle: Business Research Insights 2025",
        },
      ],
      [
        { en: "Demand indicators", de: "Nachfrage-Indikatoren" },
        {
          en: "KptnCook ~2.3M Play Store downloads, claims 7M+ users; Mealime claims 5M+ users. Sources: AppBrain 2026, vendor claims",
          de: "KptnCook ~2,3 Mio. Play-Store-Downloads, Eigenangabe 7 Mio.+ Nutzer; Mealime laut Eigenangabe 5 Mio.+ Nutzer. Quellen: AppBrain 2026, Anbieterangaben",
        },
      ],
    ],
  },
  {
    slug: "countbee",
    name: "Countbee",
    icon: "◒",
    status: "available",
    price: 300,
    appType: "A",
    weeksLo: 3,
    weeksHi: 5,
    cat: { en: "B2B · Inventory", de: "B2B · Inventur" },
    cardDesc: {
      en: "Stocktaking for small shops: scan, count, export.",
      de: "Inventur für kleine Läden: scannen, zählen, exportieren.",
    },
    lede: {
      en: "Countbee turns a phone into a stocktake scanner for small retail — scan barcodes, count stock, export a clean sheet for the accountant. Runs fully on the device: no accounts, no monthly fee.",
      de: "Countbee macht aus dem Handy einen Inventur-Scanner für kleine Läden — Barcodes scannen, Bestand zählen, saubere Tabelle für die Buchhaltung exportieren. Läuft vollständig am Gerät: keine Accounts, keine Monatsgebühr.",
    },
    aud: [
      { i: "🏪", en: "Independent small shops", de: "Unabhängige kleine Läden" },
      { i: "🧮", en: "Year-end stocktake duty", de: "Jahresend-Inventur-Pflichtige" },
      { i: "👗", en: "Boutiques & concept stores", de: "Boutiquen & Concept Stores" },
    ],
    why: [
      {
        h: { en: "The competition is built for warehouses.", de: "Die Konkurrenz ist für Lagerhäuser gebaut." },
        p: {
          en: "Inventory tools start at €30–100/month with features a corner shop never touches. A one-job tool attacks from below.",
          de: "Inventur-Tools starten bei 30–100 €/Monat mit Funktionen, die ein kleiner Laden nie braucht. Ein Ein-Zweck-Tool greift von unten an.",
        },
      },
      {
        h: { en: "Buyers search with exact words.", de: "Die Käufer suchen mit exakten Worten." },
        p: {
          en: "“inventur app kleines geschäft” — exact-match searches, and the German keyword pool is barely touched.",
          de: "„inventur app kleines geschäft“ — exakte Suchbegriffe, und der deutsche Keyword-Pool ist kaum angetastet.",
        },
      },
      {
        h: { en: "The smallest possible commitment.", de: "Das kleinstmögliche Engagement." },
        p: {
          en: "€300 fixed price, no monthly fee — the smallest way to own a finished, focused tool.",
          de: "300 € Fixpreis, keine Monatsgebühr — der kleinste Weg zu einem fertigen, fokussierten Tool.",
        },
      },
    ],
    market: [
      [
        { en: "Category", de: "Kategorie" },
        { en: "Inventory / stocktaking apps for micro-retail", de: "Inventur-Apps für Kleinsthandel" },
      ],
      [
        { en: "Established players & prices", de: "Etablierte Anbieter & Preise" },
        {
          en: "Sortly free–$299/mo (Advanced $49/mo) leads the category; few mobile-first tools for micro-retail. Source: Sortly pricing 2026",
          de: "Sortly gratis–299 $/Monat (Advanced 49 $/Monat) führt die Kategorie; kaum Mobile-first-Tools für Kleinstläden. Quelle: Sortly-Preisseite 2026",
        },
      ],
      [
        { en: "Market size", de: "Marktgröße" },
        {
          en: "Inventory-management software ~$3.7B (2025), SMB segment fastest-growing (~13.5% CAGR). Sources: Grand View Research 2026, GMI 2025",
          de: "Inventory-Management-Software ca. 3,7 Mrd. USD (2025), SMB-Segment wächst am schnellsten (~13,5 % CAGR). Quellen: Grand View Research 2026, GMI 2025",
        },
      ],
      [
        { en: "Demand indicators", de: "Nachfrage-Indikatoren" },
        {
          en: "Sortly claims 20,000+ paying businesses at $49–299/mo — small niche, proven willingness to pay. Sources: Sortly 2026, AppBrain 2026",
          de: "Sortly laut Eigenangabe 20.000+ zahlende Firmen bei 49–299 $/Monat — kleine Nische, belegte Zahlungsbereitschaft. Quellen: Sortly 2026, AppBrain 2026",
        },
      ],
    ],
  },
  {
    slug: "praxo",
    name: "Praxo",
    icon: "◍",
    status: "available",
    price: 2400,
    appType: "B",
    weeksLo: 5,
    weeksHi: 8,
    cat: { en: "B2B · Appointment reminders", de: "B2B · Terminerinnerungen" },
    cardDesc: {
      en: "SMS appointment reminders for physio and massage practices.",
      de: "SMS-Terminerinnerungen für Physio- und Massagepraxen.",
    },
    lede: {
      en: "Praxo sends automatic SMS reminders synced from a practice calendar — no-shows drop, the front desk stops phoning. Selected by our AI analysis; built exclusively for you, owned by you.",
      de: "Praxo verschickt automatische SMS-Erinnerungen aus dem Praxiskalender — No-Shows sinken, die Rezeption telefoniert nicht mehr hinterher. Von unserer KI-Analyse ausgewählt; exklusiv für dich gebaut, dir gehörend.",
    },
    aud: [
      { i: "💆", en: "Physio & massage practices", de: "Physio- & Massagepraxen" },
      { i: "📞", en: "Front desks tired of phoning", de: "Rezeptionen, die nicht mehr hinterhertelefonieren wollen" },
      { i: "✂️", en: "Next: hairdressers & studios", de: "Als Nächstes: Friseure & Studios" },
    ],
    why: [
      {
        h: { en: "No-shows are a quantified, named pain.", de: "No-Shows sind ein bezifferter, benannter Schmerz." },
        p: {
          en: "A missed slot costs a practice €40–80. A tool that reduces them sells itself on arithmetic, not persuasion.",
          de: "Ein verpasster Termin kostet eine Praxis 40–80 €. Ein Tool, das sie reduziert, verkauft sich über Arithmetik, nicht Überredung.",
        },
      },
      {
        h: { en: "The stickiest category.", de: "Die treueste Kategorie." },
        p: {
          en: "Appointment tools show the lowest churn in the AI's category analysis — B2B utilities that remove a named pain stick.",
          de: "Termin-Tools zeigen den niedrigsten Churn in der Kategorie-Analyse der KI — B2B-Utilities, die einen benannten Schmerz beseitigen, bleiben.",
        },
      },
      {
        h: { en: "Incumbents bundle, we unbundle.", de: "Die Etablierten bündeln, wir entbündeln." },
        p: {
          en: "Reminders sit inside €80–150/month practice suites. A standalone tool wins the practices that only want this one job done.",
          de: "Erinnerungen stecken in Praxis-Suiten um 80–150 €/Monat. Ein Standalone-Tool gewinnt die Praxen, die nur diese eine Aufgabe gelöst haben wollen.",
        },
      },
    ],
    market: [
      [
        { en: "Category", de: "Kategorie" },
        { en: "Practice software / appointment tools (B2B)", de: "Praxissoftware / Termin-Tools (B2B)" },
      ],
      [
        { en: "Established players & prices", de: "Etablierte Anbieter & Preise" },
        {
          en: "Doctolib from €139/mo per practitioner, Timify from ~€25/mo, appointmed (AT) €45–99/mo — big price gap below Doctolib. Source: vendor pricing pages 2026",
          de: "Doctolib ab 139 €/Monat pro Behandler, Timify ab ~25 €/Monat, appointmed (AT) 45–99 €/Monat — große Preislücke unterhalb von Doctolib. Quelle: Anbieter-Preisseiten 2026",
        },
      ],
      [
        { en: "Market size", de: "Marktgröße" },
        {
          en: "Scheduling apps ~$663M globally (2025, 13.5% CAGR); no reliable figure for the DACH physio/SMS niche. Source: Grand View Research 2025",
          de: "Scheduling-Apps weltweit ca. 663 Mio. USD (2025, 13,5 % CAGR); keine belastbare Zahl für die DACH-Physio-/SMS-Nische. Quelle: Grand View Research 2025",
        },
      ],
      [
        { en: "Demand indicators", de: "Nachfrage-Indikatoren" },
        {
          en: "Doctolib: 25M patients, 110K practitioners in Germany (2025); vendors report SMS reminders cut no-shows 30–60%. Sources: Doctolib 2025, smsmode 2025",
          de: "Doctolib: 25 Mio. Patienten, 110.000 Behandler in Deutschland (2025); SMS-Erinnerungen senken No-Shows laut Anbietern um 30–60 %. Quellen: Doctolib 2025, smsmode 2025",
        },
      ],
    ],
  },
  {
    slug: "rechni",
    name: "Rechni",
    icon: "◐",
    status: "available",
    price: 3000,
    appType: "B",
    weeksLo: 5,
    weeksHi: 8,
    cat: { en: "B2B · Invoice reminders", de: "B2B · Zahlungserinnerungen" },
    cardDesc: {
      en: "Polite, automatic payment reminders for Austrian freelancers.",
      de: "Höfliche, automatische Zahlungserinnerungen für österreichische Freelancer.",
    },
    lede: {
      en: "Rechni chases unpaid invoices for freelancers — connect your invoicing, and overdue clients get polite, escalating reminders automatically. Selected by our AI analysis; built exclusively for you, owned by you.",
      de: "Rechni mahnt offene Rechnungen für Freelancer — Rechnungsstellung verbinden, säumige Kunden bekommen automatisch höfliche, eskalierende Erinnerungen. Von unserer KI-Analyse ausgewählt; exklusiv für dich gebaut, dir gehörend.",
    },
    aud: [
      { i: "🧑‍💻", en: "Austrian freelancers (376k EPU)", de: "Österreichische Freelancer (376.000 EPU)" },
      { i: "🧾", en: "Anyone chasing late invoices", de: "Alle, die Rechnungen hinterherlaufen" },
      { i: "🤝", en: "Agencies invoicing monthly", de: "Agenturen mit Monatsrechnungen" },
    ],
    why: [
      {
        h: { en: "One in six invoices is paid late.", de: "Jede sechste Rechnung wird zu spät gezahlt." },
        p: {
          en: "KSV1870 (2025): 17% of Austrian receivables are overdue; corporate clients pay after Ø 25 days. The pain is measured, not guessed.",
          de: "KSV1870 (2025): 17 % der österreichischen Forderungen sind überfällig; Firmenkunden zahlen nach Ø 25 Tagen. Der Schmerz ist gemessen, nicht geraten.",
        },
      },
      {
        h: { en: "A huge, reachable audience.", de: "Eine große, erreichbare Zielgruppe." },
        p: {
          en: "376,000 one-person businesses in Austria (WKO 2025), >60% of all businesses — and no dunning tool speaks their language.",
          de: "376.000 EPU in Österreich (WKO 2025), >60 % aller Unternehmen — und kein Mahn-Tool spricht ihre Sprache.",
        },
      },
      {
        h: { en: "Incumbents are accounting-first.", de: "Die Etablierten sind Buchhaltung-zuerst." },
        p: {
          en: "sevDesk and Billomat bundle dunning deep inside accounting suites. A polite-reminders-only tool is the unbundled wedge.",
          de: "sevDesk und Billomat verstecken das Mahnwesen tief in Buchhaltungs-Suiten. Ein Nur-Erinnerungen-Tool ist der entbündelte Hebel.",
        },
      },
    ],
    market: [
      [
        { en: "Category", de: "Kategorie" },
        { en: "AR-automation / dunning for freelancers (B2B)", de: "Forderungs-Automatisierung / Mahnwesen für Freelancer (B2B)" },
      ],
      [
        { en: "Established players & prices", de: "Etablierte Anbieter & Preise" },
        {
          en: "sevDesk from €8.90/mo, Billomat Professional €19/mo — accounting suites, no dunning-only tool for AT freelancers. Source: vendor pricing 2026",
          de: "sevDesk ab 8,90 €/Monat, Billomat Professional 19 €/Monat — Buchhaltungs-Suiten, kein Nur-Mahnwesen-Tool für AT-Freelancer. Quelle: Anbieter-Preise 2026",
        },
      ],
      [
        { en: "Market size", de: "Marktgröße" },
        {
          en: "AR-automation ~$4.8B (2025, 13.2% CAGR), enterprise-heavy; no defensible figure for the Austrian freelancer niche. Source: analyst reports 2025",
          de: "AR-Automatisierung ca. 4,8 Mrd. USD (2025, 13,2 % CAGR), Enterprise-lastig; keine belastbare Zahl für die österreichische Freelancer-Nische. Quelle: Analystenberichte 2025",
        },
      ],
      [
        { en: "Demand indicators", de: "Nachfrage-Indikatoren" },
        {
          en: "376,112 one-person businesses in Austria (Dec 2025, +3.9% YoY); sevDesk claims 80,000+ customers DE/AT. Sources: WKO 2025, sevDesk 2026",
          de: "376.112 EPU in Österreich (Dez. 2025, +3,9 % YoY); sevDesk laut Eigenangabe 80.000+ Kunden DE/AT. Quellen: WKO 2025, sevDesk 2026",
        },
      ],
    ],
  },
  {
    slug: "shiftly",
    name: "Shiftly",
    icon: "◫",
    status: "built",
    cat: { en: "B2B · Shift scheduling", de: "B2B · Schichtplanung" },
    cardDesc: {
      en: "Simple shift planning for hospitality teams.",
      de: "Einfache Schichtplanung für Gastro-Teams.",
    },
  },
];

export function getEntry(slug: string): CatalogEntry | undefined {
  return CATALOG.find((e) => e.slug === slug);
}
