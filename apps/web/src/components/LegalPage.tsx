import Link from "next/link";
import type { Dict, Locale } from "@/lib/i18n";

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
    <main className="wrap wrap-narrow legal-doc" style={{ padding: "40px 24px 72px" }}>
      <h1>{page.title}</h1>
      <p className="lede">{page.lede}</p>
      {doc !== "imprint" && <p className="note">{l.draft}</p>}

      {page.sections.map((s) => (
        <section key={s.h}>
          <h2>{s.h}</h2>
          {s.p.map((text) => (
            <p key={text} style={{ maxWidth: "68ch" }}>
              {text}
            </p>
          ))}
        </section>
      ))}

      <p className="small muted" style={{ marginTop: 36 }}>
        {"updated" in page ? page.updated : l.updated}
        {LEGAL_DOCS.filter((other) => other !== doc).map((other) => (
          <span key={other}>
            {" · "}
            <Link href={`/${locale}/${other}`}>{l[other].title}</Link>
          </span>
        ))}
      </p>
    </main>
  );
}
