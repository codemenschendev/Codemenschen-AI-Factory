import { SITE } from "@/lib/site";

/**
 * appmitki.com for AI assistants (llmstxt.org), German at /llms.txt and English at /llms-en.txt (owner,
 * 2026-10-19: ChatGPT, Claude, Perplexity and Gemini should understand and cite the site). Plain facts in
 * short sentences; the example prices are the desk's own calculation as of October 2026.
 */
export const LLMS_DE = `# Appmitki: App mit KI entwickeln lassen

> Appmitki ist ein Angebot der Codemenschen GmbH aus Gössendorf bei Graz, Österreich. Wir entwickeln Apps für iPhone und Android mit KI-Unterstützung, von Menschen geprüft. Du beschreibst deine Idee in einem Satz und siehst kostenlos eine klickbare Vorschau. Du zahlst erst, wenn du bestellst.

Appmitki richtet sich an Selbstständige, kleine Firmen und Vereine in Österreich und Deutschland, die eine eigene App wollen, ohne Programmierkenntnisse. Die App, ihr Quellcode und ihr Store-Eintrag gehören danach dir.

## Leistungen

- App-Entwicklung für iPhone und Android, veröffentlicht im App Store und bei Google Play.
- Kostenlose, klickbare Vorschau deiner Idee in wenigen Minuten, ohne Konto und ohne Kreditkarte.
- Landingpage für die App, optional.
- App-Marketing, optional: Anzeigen auf Google und Meta und Sichtbarkeit in KI-Suchen wie ChatGPT. Das Werbebudget zahlst du direkt an Google oder Meta.
- Wartung, optional: 9 € im Monat für Änderungen und Updates.

## Was kostet eine App?

Der Preis wird pro Teil der App berechnet: aus der geschätzten Bauzeit und den KI-Kosten, mit 20 % Sicherheitspuffer. Du siehst jeden Teil und den Gesamtpreis, bevor du zahlst. Stand Oktober 2026:

- Eine einfache App kostet ab rund 180 € einmalig, zum Beispiel eine To-do-App mit einer Liste zum Abhaken.
- Mehr Teile kosten mehr, zum Beispiel Login, Kalender oder Online-Zahlung. Apps mit Login oder geteilten Daten brauchen dazu einen Server mit Monatsgebühr.
- Die Veröffentlichung im App Store und bei Google Play ist im Preis enthalten.

## So läuft es ab

1. Idee beschreiben: ein Satz reicht.
2. Kostenlose Vorschau ansehen: Appmitki baut in wenigen Minuten eine klickbare Vorschau.
3. Umfang und Preis freigeben: du siehst jeden Teil mit Preis, bevor du zahlst.
4. Fertig in 1 bis 2 Werktagen nach der Bestellung. Fehler im vereinbarten Umfang beheben wir 24 Monate lang kostenlos.

## Wichtige Seiten

- [Startseite](${SITE}/de): Angebot, Ablauf, Preise und häufige Fragen
- [Kostenlose Vorschau und Preis](${SITE}/de/prototype): Idee beschreiben, Umfang und Preis Teil für Teil sehen
- [Beispiel-Apps](${SITE}/de/apps/praxo): Musterideen mit Umfang
- [Bedingungen](${SITE}/de/terms): Vertrag, Zahlung, Abnahme
- [Widerrufsbelehrung](${SITE}/de/withdrawal)
- [Was kostet eine App?](${SITE}/de/app-kosten): Agenturpreise in Österreich im Vergleich, was den Preis bestimmt, laufende Kosten
- [Sicherheit](${SITE}/de/security): wo die Daten liegen und wie die App geprüft wird

## Häufige Fragen

- Was kostet eine App bei Appmitki? Eine einfache App ab rund 180 €. Den genauen Preis siehst du Teil für Teil, bevor du zahlst.
- Wie lange dauert es? 1 bis 2 Werktage nach der Bestellung.
- Kann ich die App vorher sehen? Ja, die klickbare Vorschau ist kostenlos und braucht kein Konto.
- Gehört mir die App? Ja. Die App, der Quellcode und der Store-Eintrag gehören dir.
- Wer steckt dahinter? Die Codemenschen GmbH, ein Software-Team in Gössendorf bei Graz.

## Kontakt

Codemenschen GmbH, Anton-Hubmann-Platz 1/6, 8077 Gössendorf, Österreich. Telefon +43 650 4861845, E-Mail developerweb@codemenschen.at. [Impressum](${SITE}/de/imprint)

## Optional

- [English version of this file](${SITE}/llms-en.txt)
- [Datenschutz](${SITE}/de/privacy)
`;

export const LLMS_EN = `# Appmitki: have an app built with AI

> Appmitki is a service of Codemenschen GmbH from Gössendorf near Graz, Austria. We build apps for iPhone and Android with AI support, checked by people. You describe your idea in one sentence and see a free clickable preview. You only pay when you order.

Appmitki is for freelancers, small businesses and clubs in Austria and Germany who want their own app without knowing how to code. The app, its source code and its store listing belong to you.

## Services

- App development for iPhone and Android, released in the App Store and Google Play.
- A free clickable preview of your idea in a few minutes, no account and no credit card.
- A landing page for the app, optional.
- App marketing, optional: ads on Google and Meta and visibility in AI search such as ChatGPT. You pay the ad budget directly to Google or Meta.
- Care, optional: 9 EUR a month for changes and updates.

## What does an app cost?

The price is calculated per part of the app: from the estimated build time and the AI cost, with a 20 % safety margin. You see every part and the total before you pay. As of October 2026:

- A simple app costs from about 180 EUR one-time, for example a to-do app with a list to tick off.
- More parts cost more, for example login, a calendar or online payment. Apps with login or shared data also need a server with a monthly fee.
- Publishing in the App Store and Google Play is included.

## How it works

1. Describe the idea: one sentence is enough.
2. See the free preview: Appmitki builds a clickable preview in a few minutes.
3. Approve scope and price: you see every part with its price before you pay.
4. Ready 1 to 2 working days after the order. We fix faults within the agreed scope free of charge for 24 months.

## Key pages

- [Home](${SITE}/en): offer, process, prices and common questions
- [Free preview and price](${SITE}/en/prototype): describe the idea, see scope and price part by part
- [Sample apps](${SITE}/en/apps/praxo): sample ideas with their scope
- [Terms](${SITE}/en/terms): contract, payment, acceptance
- [Withdrawal](${SITE}/en/withdrawal)
- [What does an app cost?](${SITE}/en/app-kosten): agency prices in Austria compared, what sets the price, running costs
- [Security](${SITE}/en/security): where data is stored and how the app is checked

## FAQ

- What does an app cost at Appmitki? A simple app from about 180 EUR. You see the exact price part by part before you pay.
- How long does it take? 1 to 2 working days after the order.
- Can I see the app first? Yes, the clickable preview is free and needs no account.
- Do I own the app? Yes. The app, the source code and the store listing belong to you.
- Who is behind it? Codemenschen GmbH, a software team in Gössendorf near Graz, Austria.

## Contact

Codemenschen GmbH, Anton-Hubmann-Platz 1/6, 8077 Gössendorf, Austria. Phone +43 650 4861845, e-mail developerweb@codemenschen.at. [Imprint](${SITE}/en/imprint)

## Optional

- [German version of this file](${SITE}/llms.txt)
- [Privacy](${SITE}/en/privacy)
`;

/** Agentic Resource Discovery manifest (agenticresourcediscovery.org), like wp-giftcard.com's. */
export const AI_CATALOG = {
  specVersion: "1.0",
  host: { displayName: "Appmitki by Codemenschen", identifier: "urn:air:appmitki.com:host:appmitki", documentationUrl: `${SITE}/llms.txt` },
  entries: [
    {
      identifier: "urn:air:appmitki.com:docs:appmitki-guide-de",
      displayName: "Appmitki: App mit KI entwickeln lassen (Deutsch)",
      type: 'text/markdown; profile="urn:air:agent-skills"',
      url: `${SITE}/llms.txt`,
      description: "Was Appmitki macht, was eine App kostet, wie die kostenlose Vorschau funktioniert und wie man Kontakt aufnimmt.",
      tags: ["app entwickeln lassen", "app agentur österreich", "ki app", "app kosten"],
      representativeQueries: ["App mit KI entwickeln lassen", "Was kostet eine App?", "App-Agentur Österreich", "App zum Festpreis entwickeln lassen"],
    },
    {
      identifier: "urn:air:appmitki.com:docs:appmitki-guide-en",
      displayName: "Appmitki: have an app built with AI (English)",
      type: 'text/markdown; profile="urn:air:agent-skills"',
      url: `${SITE}/llms-en.txt`,
      description: "What Appmitki does, what an app costs, how the free preview works and how to get in touch.",
      tags: ["app development", "app agency austria", "ai app builder", "app cost"],
      representativeQueries: ["have an app built with AI", "how much does an app cost", "app agency in Austria", "fixed price app development"],
    },
  ],
};
