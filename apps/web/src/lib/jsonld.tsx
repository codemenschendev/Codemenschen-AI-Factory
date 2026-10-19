import { SITE } from "@/lib/site";

/**
 * Structured data (schema.org JSON-LD) for search engines and AI assistants (owner, 2026-10-19: appmitki.com
 * should be understood and cited by ChatGPT, Claude, Perplexity and Gemini). Every fact here is also on the
 * visible page or in /llms.txt; the FAQ is built from the same texts the page shows.
 */
export const ORG_ID = `${SITE}/#organization`;

export function JsonLd({ data }: { data: object | object[] }) {
  // JSON in a <script> is data, not code; "<" is escaped so no text can close the tag.
  return <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(data).replace(/</g, "\\u003c") }} />;
}

export function organization() {
  return {
    "@context": "https://schema.org",
    "@type": "Organization",
    "@id": ORG_ID,
    name: "Codemenschen GmbH",
    alternateName: "Appmitki",
    url: SITE,
    logo: `${SITE}/apple-icon.png`,
    email: "developerweb@codemenschen.at",
    telephone: "+43 650 4861845",
    address: { "@type": "PostalAddress", streetAddress: "Anton-Hubmann-Platz 1/6", postalCode: "8077", addressLocality: "Gössendorf", addressRegion: "Steiermark", addressCountry: "AT" },
    founder: { "@type": "Person", name: "Patrick Fuchshofer" },
    sameAs: ["https://codemenschen.at", "https://www.facebook.com/profile.php?id=61594659700982"],
  };
}

export function website(locale: string) {
  return { "@context": "https://schema.org", "@type": "WebSite", "@id": `${SITE}/#website`, name: "Appmitki", url: `${SITE}/${locale}`, inLanguage: locale, publisher: { "@id": ORG_ID } };
}

/** App development with AI: the free preview and the price range of the desk's examples (llms.txt). */
export function service(locale: string) {
  const de = locale === "de";
  return {
    "@context": "https://schema.org",
    "@type": "Service",
    "@id": `${SITE}/#app-development`,
    name: de ? "App mit KI entwickeln lassen" : "App development with AI",
    serviceType: de ? "App-Entwicklung für iPhone und Android" : "App development for iPhone and Android",
    description: de
      ? "Apps für iPhone und Android, mit KI-Unterstützung gebaut und von Menschen geprüft. Kostenlose klickbare Vorschau, Preis pro Teil vor der Zahlung, fertig in 1 bis 2 Werktagen."
      : "Apps for iPhone and Android, built with AI support and checked by people. Free clickable preview, price per part before you pay, ready in 1 to 2 working days.",
    provider: { "@id": ORG_ID },
    areaServed: [{ "@type": "Country", name: "Austria" }, { "@type": "Country", name: "Germany" }],
    url: `${SITE}/${locale}`,
    offers: [
      { "@type": "Offer", name: de ? "Kostenlose klickbare Vorschau" : "Free clickable preview", price: "0", priceCurrency: "EUR", url: `${SITE}/${locale}/prototype` },
      { "@type": "AggregateOffer", name: de ? "App-Entwicklung, Preis pro Teil" : "App development, priced per part", lowPrice: "180", highPrice: "600", priceCurrency: "EUR", url: `${SITE}/${locale}/prototype` },
    ],
  };
}

export function faqPage(items: { q: string; a: string }[], locale: string) {
  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    inLanguage: locale,
    mainEntity: items.map((f) => ({ "@type": "Question", name: f.q, acceptedAnswer: { "@type": "Answer", text: f.a } })),
  };
}

/** Home > page (> sub page): each crumb is [name, path after the locale]. */
export function breadcrumbs(locale: string, crumbs: [string, string][]) {
  const home: [string, string] = [locale === "de" ? "Startseite" : "Home", ""];
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [home, ...crumbs].map(([name, path], i) => ({ "@type": "ListItem", position: i + 1, name, item: `${SITE}/${locale}${path}` })),
  };
}
