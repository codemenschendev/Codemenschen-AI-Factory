/**
 * The cost page (2026-10-21): "App entwickeln lassen Kosten", the question AI assistants answer with agency
 * price lists. Our only own figure is "ab rund 180 €" (owner, 2026-10-19); the agency ranges are the prices
 * Austrian app agencies publish on their sites, without naming them.
 */
export interface CostDict {
  metaTitle: string;
  metaDesc: string;
  crumb: string;
  title: string;
  lede: string;
  promises: { h: string; p: string }[];
  compareTitle: string;
  compareHead: string[];
  compare: string[][];
  compareNote: string;
  sections: { h: string; p: string[] }[];
  faqTitle: string;
  faq: { q: string; a: string }[];
  ctaTitle: string;
  ctaLede: string;
  cta: string;
  updated: string;
}

const de: CostDict = {
  metaTitle: "App entwickeln lassen: Kosten in Österreich | Appmitki",
  metaDesc:
    "Was kostet es, eine App entwickeln zu lassen? Agenturen in Österreich nennen meist 15.000 bis 30.000 € für eine einfache App. Bei Appmitki ab rund 180 €, mit kostenloser Vorschau.",
  crumb: "App-Kosten",
  title: "App entwickeln lassen: Was kostet das?",
  lede:
    "Eine einfache App kostet bei Appmitki ab rund 180 € einmalig. Klassische Agenturen in Österreich nennen für eine einfache App meist 15.000 bis 30.000 €. Hier siehst du, woher der Unterschied kommt und was den Preis bestimmt.",
  promises: [
    { h: "Ab rund 180 €", p: "Eine einfache App für iPhone und Android, mit Veröffentlichung im App Store und bei Google Play." },
    { h: "Preis vor der Bestellung", p: "Du siehst jeden Teil deiner App mit seinem Preis. Du zahlst erst, wenn du bestellst." },
    { h: "Kostenlose Vorschau", p: "Die klickbare Vorschau ist in wenigen Minuten fertig und braucht kein Konto." },
  ],
  compareTitle: "Agentur oder Appmitki: der Vergleich",
  compareHead: ["", "Klassische Agentur", "Appmitki"],
  compare: [
    ["Einfache App", "meist 15.000 bis 30.000 €", "ab rund 180 €"],
    ["App mit Login und Daten", "oft 30.000 bis 70.000 €", "Preis Teil für Teil vor der Bestellung"],
    ["Erste Version", "mehrere Wochen bis Monate", "1 bis 2 Werktage nach dem Start"],
    ["Vor dem Kauf", "Erstgespräch und Angebot", "Klickbare Vorschau, kostenlos"],
    ["Zahlung", "laut Angebot", "erst bei der Bestellung"],
    ["Fehler beheben", "laut Vertrag", "24 Monate kostenlos im vereinbarten Umfang"],
  ],
  compareNote:
    "Agenturpreise: veröffentlichte Angaben österreichischer App-Agenturen, Stand Oktober 2026. Sie gelten meist netto und hängen vom Umfang ab.",
  sections: [
    {
      h: "Warum ist eine App bei Appmitki günstiger?",
      p: [
        "Bei einer Agentur bezahlst du vor allem Arbeitszeit: Konzept, Design, Programmierung, Tests und Projektleitung. Für eine einfache App sind das schnell mehrere Wochen.",
        "Bei Appmitki schreibt KI den Großteil des Codes. Unser Team in Gössendorf bei Graz prüft die App, bevor sie zu dir kommt. So bleibt der Preis klein, und die erste Version ist in Tagen fertig statt in Monaten.",
      ],
    },
    {
      h: "Was bestimmt den Preis einer App?",
      p: [
        "Die Funktionen. Eine App, die Inhalte zeigt, kostet weniger als eine mit Login, Buchungen oder Zahlungen.",
        "Die Daten. Apps mit Konten oder geteilten Daten brauchen einen Server, Apps nur fürs Handy nicht.",
        "Die Anbindungen. Jede Verbindung zu einem bestehenden System, zum Beispiel einem Kalender, ist ein eigener Teil mit eigenem Preis.",
        "Die Geräte. Bei Appmitki sind iPhone und Android immer beide dabei.",
      ],
    },
    {
      h: "Wie entsteht der Preis bei Appmitki?",
      p: [
        "Du beschreibst deine App-Idee in ein paar Sätzen. Daraus wird eine Liste der Teile deiner App, jeder mit seinem Preis.",
        "Die Summe siehst du, bevor du etwas bestellst. Gefällt dir die Vorschau, bestellst du. Erst dann zahlst du.",
      ],
    },
    {
      h: "Welche laufenden Kosten kommen dazu?",
      p: [
        "Apps, die nur am Handy laufen, brauchen keinen Server. Apps mit Login oder geteilten Daten haben eine Monatsgebühr für Hosting und Wartung. Du siehst sie vor dem Zahlen.",
        "Für die Veröffentlichung unter deinem Namen verlangen Apple und Google einen eigenen Developer-Account: Apple 99 $ im Jahr, Google einmalig 25 $. Wir richten ihn mit dir ein.",
      ],
    },
  ],
  faqTitle: "Häufige Fragen zu den Kosten",
  faq: [
    {
      q: "Was kostet es, eine App entwickeln zu lassen?",
      a: "Agenturen in Österreich nennen für eine einfache App meist 15.000 bis 30.000 €, für eine App mit Login und Daten oft 30.000 bis 70.000 €. Bei Appmitki kostet eine einfache App ab rund 180 €.",
    },
    {
      q: "Was kostet eine einfache App bei Appmitki?",
      a: "Ab rund 180 € einmalig. Jeder Teil der App hat seinen eigenen Preis. Die Summe siehst du vor der Bestellung.",
    },
    {
      q: "Wie lange dauert es, eine App entwickeln zu lassen?",
      a: "Bei Appmitki ist die Vorschau in wenigen Minuten fertig und die App 1 bis 2 Werktage nach dem Start der Entwicklung. Agenturen rechnen meist mit mehreren Wochen bis Monaten.",
    },
    {
      q: "Gibt es versteckte Kosten?",
      a: "Nein. Den Preis siehst du vor der Bestellung. Eine Monatsgebühr gibt es nur für Apps mit Login oder geteilten Daten, und auch die siehst du vorher. Die Gebühren für die Developer-Accounts zahlst du direkt an Apple und Google.",
    },
    {
      q: "Gehört mir die App?",
      a: "Ja. Die fertige App, ihr Code und ihr Store-Eintrag gehören dir.",
    },
  ],
  ctaTitle: "Was kostet deine App?",
  ctaLede: "Beschreib deine Idee und sieh Umfang und Preis, bevor du zahlst.",
  cta: "Kostenlose Vorschau starten",
  updated: "Stand: Oktober 2026",
};

const en: CostDict = {
  metaTitle: "App development cost in Austria | Appmitki",
  metaDesc:
    "How much does it cost to have an app built? Agencies in Austria usually quote 15,000 to 30,000 EUR for a simple app. At Appmitki from about 180 EUR, with a free preview.",
  crumb: "App cost",
  title: "Having an app built: what does it cost?",
  lede:
    "A simple app costs from about 180 EUR one-off at Appmitki. Traditional agencies in Austria usually quote 15,000 to 30,000 EUR for a simple app. Here you see where the difference comes from and what sets the price.",
  promises: [
    { h: "From about 180 EUR", p: "A simple app for iPhone and Android, published in the App Store and on Google Play." },
    { h: "Price before you order", p: "You see every part of your app with its price. You only pay when you order." },
    { h: "Free preview", p: "The clickable preview is ready in a few minutes and needs no account." },
  ],
  compareTitle: "Agency or Appmitki: the comparison",
  compareHead: ["", "Traditional agency", "Appmitki"],
  compare: [
    ["Simple app", "usually 15,000 to 30,000 EUR", "from about 180 EUR"],
    ["App with login and data", "often 30,000 to 70,000 EUR", "price part by part before you order"],
    ["First version", "several weeks to months", "1 to 2 working days after the start"],
    ["Before you buy", "first meeting and a quote", "clickable preview, free"],
    ["Payment", "as quoted", "only when you order"],
    ["Fixing faults", "as contracted", "24 months free within the agreed scope"],
  ],
  compareNote:
    "Agency prices: figures Austrian app agencies publish on their websites, as of October 2026. They are usually net and depend on the scope.",
  sections: [
    {
      h: "Why is an app at Appmitki cheaper?",
      p: [
        "At an agency you mainly pay for working time: concept, design, coding, testing and project management. For a simple app that quickly adds up to several weeks.",
        "At Appmitki, AI writes most of the code. Our team in Gössendorf near Graz checks the app before it reaches you. That keeps the price small, and the first version is ready in days instead of months.",
      ],
    },
    {
      h: "What sets the price of an app?",
      p: [
        "The features. An app that shows content costs less than one with login, bookings or payments.",
        "The data. Apps with accounts or shared data need a server, phone-only apps do not.",
        "The connections. Every link to an existing system, for example a calendar, is a part with its own price.",
        "The devices. At Appmitki, iPhone and Android are always both included.",
      ],
    },
    {
      h: "How does Appmitki set the price?",
      p: [
        "You describe your app idea in a few sentences. It becomes a list of the parts of your app, each with its price.",
        "You see the total before you order anything. If you like the preview, you order. Only then do you pay.",
      ],
    },
    {
      h: "What running costs come on top?",
      p: [
        "Apps that run only on the phone need no server. Apps with login or shared data have a monthly fee for hosting and care. You see it before you pay.",
        "To publish under your own name, Apple and Google require your own developer account: Apple 99 USD a year, Google 25 USD once. We set it up with you.",
      ],
    },
  ],
  faqTitle: "Common questions about cost",
  faq: [
    {
      q: "How much does it cost to have an app built?",
      a: "Agencies in Austria usually quote 15,000 to 30,000 EUR for a simple app and often 30,000 to 70,000 EUR for an app with login and data. At Appmitki a simple app costs from about 180 EUR.",
    },
    {
      q: "What does a simple app cost at Appmitki?",
      a: "From about 180 EUR one-off. Every part of the app has its own price. You see the total before you order.",
    },
    {
      q: "How long does it take to have an app built?",
      a: "At Appmitki the preview is ready in a few minutes and the app 1 to 2 working days after development starts. Agencies usually plan several weeks to months.",
    },
    {
      q: "Are there hidden costs?",
      a: "No. You see the price before you order. A monthly fee applies only to apps with login or shared data, and you see it first too. The developer account fees go directly to Apple and Google.",
    },
    {
      q: "Do I own the app?",
      a: "Yes. The finished app, its code and its store listing are yours.",
    },
  ],
  ctaTitle: "What will your app cost?",
  ctaLede: "Describe your idea and see scope and price before you pay.",
  cta: "Start the free preview",
  updated: "As of October 2026",
};

export const costDict = (locale: string): CostDict => (locale === "de" ? de : en);
