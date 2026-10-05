/**
 * Sofabuilt's terms and withdrawal information (docs/specs/sofabuilt.md). Same company and the
 * same structure as Appmitki's, written for a plugin built to an agreed scope. A draft until counsel
 * signs it off, like Appmitki's (the legal pages show the draft note).
 */
type Doc = { title: string; lede: string; sections: { h: string; p: string[] }[] };

const en: { terms: Doc; withdrawal: Doc } = {
  terms: {
    title: "Terms",
    lede: "What you order, what it costs, who owns the plugin, and what happens if something goes wrong.",
    sections: [
      {
        h: "1. Who sells",
        p: [
          "Sofabuilt is a service of Codemenschen GmbH in Gössendorf near Graz. The full company details are in the imprint.",
          "The contract is between you and Codemenschen GmbH. We build software to order. We broker nothing and we run no marketplace.",
        ],
      },
      {
        h: "2. What you get",
        p: [
          "A WordPress plugin or Shopify app built to the scope shown in the desk when you order: its features, what is not included, and the WordPress, WooCommerce and PHP versions it needs.",
          "Before handover you try the plugin live in a WordPress in your browser. One change round within the agreed scope is included. Further rounds or new features are priced before we start them.",
          "You get the plugin as a ZIP file, its source code and a readme. WordPress plugins are licensed under the GPL: you may use, change, pass on and sell it. We keep no share of your revenue.",
          "If you asked for your own version of a paid plugin, you get new code with its own name. It covers the features listed in the scope, not everything the original does.",
          "A Shopify app is tried on a Shopify development store. You get the app with all its code and may use, change and sell it. It runs on a server you choose; hosting, Shopify's own fees and the App Store review are not part of our price.",
        ],
      },
      {
        h: "3. What you pay",
        p: [
          "The price is fixed before you pay and is due today. Optional launch services are listed separately at checkout.",
          "The care plan is optional and monthly. It is offered after handover and can be cancelled at any time.",
          "An ad budget is optional. Ads run on your own ad account, and Google or Meta bill the budget to you directly. It is never part of a payment to us.",
          "Store fees, for example for a seller account, are passed on at cost when they apply.",
          "With the ready-to-sell option your sales run through Freemius, in your own seller account and under its terms. Freemius keeps a share of each sale; we are not part of that contract.",
        ],
      },
      {
        h: "4. Build start and withdrawal",
        p: [
          "As a consumer you have a 14-day right of withdrawal.",
          "You decide at checkout. Either we start right away, in which case the withdrawal right expires when performance begins (§ 18 FAGG). Or we start after the 14 days, in which case it stays fully intact. The box is never pre-ticked.",
          "The details are on the withdrawal page.",
        ],
      },
      {
        h: "5. Warranty",
        p: [
          "The plugin works as described in the scope with the versions named there, on the day of handover. Statutory warranty applies.",
          "WordPress, WooCommerce, PHP, themes and other plugins change over time. Keeping the plugin compatible after handover is what the care plan is for.",
        ],
      },
      {
        h: "6. What we do not promise",
        p: [
          "We promise no sales, no revenue, no downloads and no ranking. Figures on this site are examples and are marked as such.",
          "WordPress.org and other stores decide on admission themselves. We prepare and accompany a submission, we cannot guarantee it.",
        ],
      },
      {
        h: "7. Liability",
        p: [
          "We are liable for intent and gross negligence. For slight negligence only for injury to life, body or health and for the breach of essential duties, limited to the damage that was foreseeable when the contract was made. Back up your site before installing any plugin.",
        ],
      },
      {
        h: "8. Law",
        p: ["Austrian law applies. Mandatory consumer rights in your country of residence remain untouched."],
      },
    ],
  },
  withdrawal: {
    title: "Withdrawal information",
    lede: "Your 14-day right of withdrawal, and the choice you make yourself at checkout.",
    sections: [
      {
        h: "The right",
        p: [
          "As a consumer in the EU you may withdraw within 14 days without giving any reason. The period starts when the contract is concluded.",
          "An unambiguous statement to developerweb@codemenschen.at before the period ends is enough. We confirm receipt and refund payments received without undue delay.",
        ],
      },
      {
        h: "Your choice at checkout",
        p: [
          "Start now: you expressly request that we begin building right after payment, and you acknowledge that your withdrawal right expires when performance begins (§ 18 FAGG). The box is never pre-ticked.",
          "Start later: without that tick we begin after the 14 days and your withdrawal right stays fully intact. Nothing about the plugin changes, it just starts later.",
        ],
      },
      {
        h: "What is still open",
        p: [
          "The statutory model instruction with its form, and the exact classification of the contract, are with counsel. Until that is settled: in case of doubt we decide in your favour.",
        ],
      },
    ],
  },
};

const de: typeof en = {
  terms: {
    title: "AGB",
    lede: "Was du bestellst, was es kostet, wem das Plugin gehört und was passiert, wenn etwas schiefgeht.",
    sections: [
      {
        h: "1. Wer verkauft",
        p: [
          "Sofabuilt ist ein Angebot der Codemenschen GmbH in Gössendorf bei Graz. Alle Firmendaten stehen im Impressum.",
          "Der Vertrag kommt zwischen dir und der Codemenschen GmbH zustande. Wir entwickeln Software im Auftrag. Wir vermitteln nichts und betreiben keinen Marktplatz.",
        ],
      },
      {
        h: "2. Was du bekommst",
        p: [
          "Ein WordPress-Plugin oder eine Shopify-App nach dem Umfang, der bei der Bestellung im Desk steht: seine Funktionen, was nicht enthalten ist, und die Versionen von WordPress, WooCommerce und PHP, die es braucht.",
          "Vor der Übergabe probierst du das Plugin live in einem WordPress im Browser aus. Eine Änderungsrunde im vereinbarten Umfang ist inklusive. Weitere Runden oder neue Funktionen bepreisen wir, bevor wir sie starten.",
          "Du bekommst das Plugin als ZIP-Datei, den Quellcode und eine Readme. WordPress-Plugins stehen unter der GPL: du darfst es nutzen, ändern, weitergeben und verkaufen. Wir behalten keinen Anteil an deinen Einnahmen.",
          "Wenn du deine eigene Version eines bezahlten Plugins bestellt hast, bekommst du neuen Code mit eigenem Namen. Er umfasst die Funktionen im Umfang, nicht alles, was das Original kann.",
          "Eine Shopify-App probierst du in einem Shopify-Entwicklungsshop aus. Du bekommst die App mit dem ganzen Code und darfst sie nutzen, ändern und verkaufen. Sie läuft auf einem Server deiner Wahl; Hosting, Gebühren von Shopify und die Prüfung im App Store sind nicht Teil unseres Preises.",
        ],
      },
      {
        h: "3. Was du zahlst",
        p: [
          "Der Preis steht vor der Zahlung fest und ist heute fällig. Optionale Start-Leistungen stehen im Checkout einzeln.",
          "Der Wartungsplan ist optional und monatlich. Wir bieten ihn nach der Übergabe an, er ist jederzeit kündbar.",
          "Ein Werbebudget ist optional. Anzeigen laufen auf deinem eigenen Werbekonto, Google oder Meta rechnen das Budget direkt mit dir ab. Es ist nie Teil einer Zahlung an uns.",
          "Gebühren von Stores, zum Beispiel für ein Verkäuferkonto, geben wir zum Selbstkostenpreis weiter, wenn sie anfallen.",
          "Mit der Option Bereit zum Verkauf laufen deine Verkäufe über Freemius, in deinem eigenen Verkäuferkonto und nach dessen Bedingungen. Freemius behält einen Anteil pro Verkauf; wir sind an diesem Vertrag nicht beteiligt.",
        ],
      },
      {
        h: "4. Start und Widerruf",
        p: [
          "Als Verbraucher hast du ein 14-tägiges Widerrufsrecht.",
          "Du entscheidest im Checkout. Entweder wir starten sofort, dann erlischt das Widerrufsrecht mit Beginn der Leistung (§ 18 FAGG). Oder wir starten nach den 14 Tagen, dann bleibt es voll erhalten. Das Häkchen ist nie vorausgewählt.",
          "Die Details stehen auf der Seite zum Widerruf.",
        ],
      },
      {
        h: "5. Gewährleistung",
        p: [
          "Das Plugin funktioniert am Tag der Übergabe so, wie im Umfang beschrieben, mit den dort genannten Versionen. Es gilt die gesetzliche Gewährleistung.",
          "WordPress, WooCommerce, PHP, Themes und andere Plugins ändern sich mit der Zeit. Für die Kompatibilität nach der Übergabe gibt es den Wartungsplan.",
        ],
      },
      {
        h: "6. Was wir nicht versprechen",
        p: [
          "Wir versprechen keine Verkäufe, keine Einnahmen, keine Downloads und keine Platzierung. Zahlen auf dieser Seite sind Beispiele und als solche gekennzeichnet.",
          "WordPress.org und andere Stores entscheiden selbst über die Aufnahme. Wir bereiten eine Einreichung vor und begleiten sie, garantieren können wir sie nicht.",
        ],
      },
      {
        h: "7. Haftung",
        p: [
          "Wir haften für Vorsatz und grobe Fahrlässigkeit. Bei leichter Fahrlässigkeit nur für Schäden an Leben, Körper oder Gesundheit und für die Verletzung wesentlicher Pflichten, begrenzt auf den bei Vertragsschluss vorhersehbaren Schaden. Mach vor der Installation eines Plugins ein Backup deiner Website.",
        ],
      },
      {
        h: "8. Recht",
        p: ["Es gilt österreichisches Recht. Zwingende Verbraucherrechte deines Wohnsitzlandes bleiben unberührt."],
      },
    ],
  },
  withdrawal: {
    title: "Widerrufsbelehrung",
    lede: "Dein 14-tägiges Widerrufsrecht und die Wahl, die du im Checkout selbst triffst.",
    sections: [
      {
        h: "Das Recht",
        p: [
          "Als Verbraucher in der EU kannst du innerhalb von 14 Tagen ohne Angabe von Gründen widerrufen. Die Frist beginnt mit Vertragsabschluss.",
          "Eine eindeutige Erklärung an developerweb@codemenschen.at vor Fristende genügt. Wir bestätigen den Eingang und erstatten erhaltene Zahlungen unverzüglich.",
        ],
      },
      {
        h: "Deine Wahl im Checkout",
        p: [
          "Sofort starten: du verlangst ausdrücklich, dass wir direkt nach der Zahlung beginnen, und nimmst zur Kenntnis, dass dein Widerrufsrecht mit Beginn der Leistung erlischt (§ 18 FAGG). Das Häkchen ist nie vorausgewählt.",
          "Später starten: ohne das Häkchen beginnen wir nach den 14 Tagen und dein Widerrufsrecht bleibt voll erhalten. Am Plugin ändert sich nichts, es startet nur später.",
        ],
      },
      {
        h: "Was noch offen ist",
        p: [
          "Die gesetzliche Muster-Belehrung mit Formular und die genaue Einordnung des Vertrags liegen bei der Rechtsberatung. Bis das geklärt ist: im Zweifel entscheiden wir zu deinen Gunsten.",
        ],
      },
    ],
  },
};

export function sbLegal(locale: string) {
  return locale === "de" ? de : en;
}
