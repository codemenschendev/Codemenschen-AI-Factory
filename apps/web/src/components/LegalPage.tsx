import Link from "next/link";
import type { Dict, Locale } from "@/lib/i18n";
import "../app/prototype.css";
import "../app/legal.css";

/** The legal pages, each at /{locale}/{doc}. */
export const LEGAL_DOCS = ["terms", "withdrawal", "privacy", "imprint"] as const;
export type LegalDoc = (typeof LEGAL_DOCS)[number];

/**
 * Terms, withdrawal, privacy and imprint, rendered from the dictionaries so both languages stay one
 * file apart.
 *
 * The draft banner is on purpose and stays until counsel signs the text off. Publishing a plain
 * language version early is the honest move: the checkout asks people to agree to something, and
 * that something has to be readable somewhere.
 */
export function LegalPage({
  locale,
  d,
  doc,
}: {
  locale: Locale;
  d: Dict;
  doc: LegalDoc;
}) {
  const l = d.legal;
  const page = l[doc];

  return (
    <main className="legal-doc lg">
      <div className="pp-band lg-band">
        <div className="wrap wrap-narrow">
          <h1>{page.title}</h1>
          <p className="lg-lede">{page.lede}</p>
        </div>
      </div>

      <div className="wrap wrap-narrow lg-body">
        {doc !== "imprint" && <p className="note lg-draft">{l.draft}</p>}

        <div className="lg-card">
          {page.sections.map((s) => (
            <section key={s.h}>
              <h2>{s.h}</h2>
              {s.p.map((text) => (
                <p key={text}>{text}</p>
              ))}
            </section>
          ))}
        </div>

        <p className="lg-updated">{"updated" in page ? page.updated : l.updated}</p>
        <nav className="lg-more">
          {LEGAL_DOCS.filter((other) => other !== doc).map((other) => (
            <Link key={other} href={`/${locale}/${other}`}>{l[other].title}</Link>
          ))}
        </nav>
      </div>
    </main>
  );
}
