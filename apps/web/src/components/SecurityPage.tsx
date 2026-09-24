import Link from "next/link";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * Security and privacy at a glance (2026-09-24), the page a business in Austria or Germany reads
 * before it trusts us with its ad account and its customers' sign-ups. Every sentence states what
 * the code does today; the full legal text stays in the privacy policy, which this page links to.
 */
export function SecurityPage({ locale, d }: { locale: Locale; d: Dict }) {
  const s = d.security;

  const table = (head: readonly string[], rows: readonly (readonly string[])[]) => (
    <div className="tbl-wrap" style={{ marginTop: 12 }}>
      <table>
        <thead>
          <tr>{head.map((h) => <th key={h}>{h}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r[0]}>{r.map((c, i) => <td key={i}>{c}</td>)}</tr>
          ))}
        </tbody>
      </table>
    </div>
  );

  return (
    <main className="legal-doc" style={{ padding: "40px 0 72px" }}>
      <div className="wrap">
        <h1>{s.title}</h1>
        <p className="lede" style={{ maxWidth: "62ch" }}>{s.lede}</p>
        <div className="grid" style={{ marginTop: 28 }}>
          {s.promises.map((p) => (
            <div key={p.h} className="card">
              <h3>{p.h}</h3>
              <p className="muted" style={{ margin: 0 }}>{p.p}</p>
            </div>
          ))}
        </div>
      </div>

      <div className="wrap wrap-narrow" style={{ marginTop: 20 }}>
        {s.sections.map((sec) => (
          <section key={sec.h}>
            <h2>{sec.h}</h2>
            {sec.p.map((text) => (
              <p key={text} style={{ maxWidth: "68ch" }}>{text}</p>
            ))}
          </section>
        ))}

        <section>
          <h2>{s.processorsTitle}</h2>
          {table(s.processorsHead, s.processors)}
        </section>

        <section>
          <h2>{s.retentionTitle}</h2>
          {table(s.retentionHead, s.retention)}
        </section>

        <section>
          <h2>{s.contactTitle}</h2>
          <p style={{ maxWidth: "68ch" }}>{s.contact}</p>
          <p>
            <Link href={`/${locale}/privacy`}>{s.privacyLink}</Link>
          </p>
        </section>

        <p className="small muted" style={{ marginTop: 36 }}>{s.updated}</p>
      </div>
    </main>
  );
}
