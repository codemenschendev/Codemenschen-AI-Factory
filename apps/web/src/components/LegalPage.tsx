import Link from "next/link";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * Terms and withdrawal, rendered from the dictionaries so both languages stay one file apart.
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
  doc: "terms" | "withdrawal";
}) {
  const l = d.legal;
  const page = l[doc];

  return (
    <main className="wrap wrap-narrow" style={{ padding: "40px 24px 72px" }}>
      <h1>{page.title}</h1>
      <p className="lede">{page.lede}</p>
      <p className="note">{l.draft}</p>

      {page.sections.map((s) => (
        <section key={s.h} style={{ marginTop: 28 }}>
          <h2 style={{ fontSize: "1.15rem" }}>{s.h}</h2>
          {s.p.map((text) => (
            <p key={text} style={{ maxWidth: "68ch" }}>
              {text}
            </p>
          ))}
        </section>
      ))}

      <p className="small muted" style={{ marginTop: 36 }}>
        {l.updated} ·{" "}
        <Link href={`/${locale}/${doc === "terms" ? "withdrawal" : "terms"}`}>
          {doc === "terms" ? d.legal.withdrawal.title : d.legal.terms.title}
        </Link>{" "}
        ·{" "}
        <a href="https://www.codemenschen.at/impressum" target="_blank" rel="noopener">
          {l.impressum}
        </a>
      </p>
    </main>
  );
}
