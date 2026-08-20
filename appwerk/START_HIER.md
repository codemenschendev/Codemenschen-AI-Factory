# APPWERK — START HIER

**Master-Index für das Thema Appwerk (inkl. Sub-Thema Review-Intelligence).** Einstieg für jede Session. **Stand: 11.07.2026.**

## Was ist Appwerk?

Marktplatz (by codemenschen.at) für exklusive Lizenzen an KI-selektierten Prototyp-Apps (300–5.000 € + 5–10 % Retainer). Aktuelles Modell (nach 2 Pivots): **automatische KI-Business-Analyse des App Stores wählt die Konzepte** — keine Echtnutzer-Validierungs-Claims mehr. Beteiligungswege: 100 % kaufen / Anteil (mode=share) / Funding (mode=fund) / Eigene App bauen lassen (create-Wizard → Custom-Checkout).

## Struktur

| Ort | Inhalt |
|---|---|
| `appwerk\site\` | Statischer DE/EN-Prototyp: index, app.html?id=slug (generische Detailseite), checkout, create, Legal-Seiten. Daten zentral in `catalog.js` (window.CATALOG, 5 Apps), Artwork in `art-*.js`, echte Screens in `screens.js`, i18n via data-i18n (DE in app.js) |
| `appwerk\docs\` | Strategie-Docs 01–30 (28 = Shared-Deals-Modell, 29 = echte Business-Analyse mit Quellen, 30 = Review-Mining-Ideen), legal-01..03 Entwürfe |
| **Sub-Thema:** `C:\Users\telbe\review-intelligence\` | TS/SQLite/Apify-Pipeline, die App-Store-/Play-Store-Reviews auf Business-Chancen auswertet — **liefert die App-Ideen für den Appwerk-Katalog** (Gewinner-Idee: TrainerPing, noch NICHT gelistet). Details: README + `reports/` |

## Konventionen (nicht brechen)

- Trust-first-Copy, kein Hype; jede Rechtsunsicherheit trägt sichtbar `[COUNSEL REVIEW]`/`[RECHTSPRÜFUNG]`.
- Alle Zahlen als Potenzial/Schätzung markiert, keine Messungs-Claims (Pivot 2!).
- Fonts selbst gehostet (DSGVO), keine CDN-Loads.
- Änderungen an Checkout-/Widerrufs-Logik: § 18 FAGG-Konstruktion beachten (Erlöschen bei Leistungsbeginn, nicht Warenausnahme) — Counsel-Hinweise stehen in withdrawal.html.

## Status & offene Punkte (11.07.2026)

- Site funktionsfähig als Prototyp inkl. Custom-Checkout (DOM-Shim-Tests in Scratchpad-Historie; test-checkout-custom.js 15 Checks).
- **Offen:** (1) Live-Stripe-Links + echte Domain (Patrick); (2) restliche [PLACEHOLDER] in catalog-Marktdaten; (3) „Zur vollständigen KI-Analyse"-Links sind Stubs; (4) TrainerPing-Idee aus Review-Mining in Katalog aufnehmen?
- **Review-Intelligence offen:** kein gültiger ANTHROPIC_API_KEY (Analyse lief interaktiv über Claude Code); Apify FREE-Plan (instapatrick); Keyword-Set verfeinern („mobility" zog EnBW-App rein).

## Regeln für Sessions

1. Details zum Code-Stand: Memory `appwerk-project` + `review-intelligence-project` lesen, aber gegen den echten Code verifizieren (Memories sind Momentaufnahmen).
2. Neue Strategie-Docs nummeriert nach `docs\` (nächste freie Nummer), Analysen der Review-Pipeline nach `review-intelligence\reports\`.
3. Dieses Dokument bei wesentlichen Änderungen aktualisieren (Status + Datum).
