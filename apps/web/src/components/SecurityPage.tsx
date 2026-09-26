import Link from "next/link";
import type { Dict, Locale } from "@/lib/i18n";
import { Icon } from "./LineIcon";
import "../app/prototype.css";
import "../app/legal.css";

/**
 * Security and privacy at a glance (2026-09-24), the page a business in Austria or Germany reads
 * before it trusts us with its ad account and its customers' sign-ups. Every sentence states what
 * the code does today; the full legal text stays in the privacy policy, which this page links to.
 */
export function SecurityPage({ locale, d }: { locale: Locale; d: Dict }) {
  const s = d.security;

  const table = (head: readonly string[], rows: readonly (readonly string[])[]) => (
    <div className="tbl-wrap lg-table">
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
    <main className="legal-doc lg">
      <div className="pp-band lg-band">
        <div className="wrap">
          <h1>{s.title}</h1>
          <p className="lg-lede">{s.lede}</p>
        </div>
      </div>
      <div className="wrap lg-promises">
        {s.promises.map((p) => (
          <div key={p.h} className="lg-promise">
            <span className="lg-promise-ico"><Icon name="shield" /></span>
            <h3>{p.h}</h3>
            <p>{p.p}</p>
          </div>
        ))}
      </div>

      <div className="wrap wrap-narrow lg-body">
        {s.sections.map((sec) => (
          <section key={sec.h}>
            <h2>{sec.h}</h2>
            {sec.p.map((text) => (
              <p key={text}>{text}</p>
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
          <p>{s.contact}</p>
          <p>
            <Link href={`/${locale}/privacy`}>{s.privacyLink}</Link>
          </p>
        </section>

        <p className="lg-updated">{s.updated}</p>
      </div>
    </main>
  );
}
