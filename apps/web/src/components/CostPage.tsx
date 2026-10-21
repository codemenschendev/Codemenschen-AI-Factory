import Link from "next/link";
import type { Locale } from "@/lib/i18n";
import type { CostDict } from "@/dictionaries/cost";
import { Icon } from "./LineIcon";
import "../app/prototype.css";
import "../app/legal.css";

/**
 * "App entwickeln lassen: Kosten" (2026-10-21). Written to be quoted: the answer first, a table that sets
 * agency prices against ours, then what sets a price, and the same answers again as FAQ data.
 */
export function CostPage({ locale, c }: { locale: Locale; c: CostDict }) {
  return (
    <main className="legal-doc lg">
      <div className="pp-band lg-band">
        <div className="wrap">
          <h1>{c.title}</h1>
          <p className="lg-lede">{c.lede}</p>
        </div>
      </div>
      <div className="wrap lg-promises">
        {c.promises.map((p) => (
          <div key={p.h} className="lg-promise">
            <span className="lg-promise-ico"><Icon name="euro" /></span>
            <h3>{p.h}</h3>
            <p>{p.p}</p>
          </div>
        ))}
      </div>

      <div className="wrap wrap-narrow lg-body">
        <section>
          <h2>{c.compareTitle}</h2>
          <div className="tbl-wrap lg-table">
            <table>
              <thead>
                <tr>{c.compareHead.map((h, i) => <th key={i}>{h}</th>)}</tr>
              </thead>
              <tbody>
                {c.compare.map((r) => (
                  <tr key={r[0]}>
                    <th scope="row">{r[0]}</th>
                    <td>{r[1]}</td>
                    <td><strong>{r[2]}</strong></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="lg-updated">{c.compareNote}</p>
        </section>

        {c.sections.map((sec) => (
          <section key={sec.h}>
            <h2>{sec.h}</h2>
            {sec.p.map((text) => (
              <p key={text}>{text}</p>
            ))}
          </section>
        ))}

        <section>
          <h2>{c.faqTitle}</h2>
          {c.faq.map((f) => (
            <div key={f.q}>
              <h3>{f.q}</h3>
              <p>{f.a}</p>
            </div>
          ))}
        </section>

        <section>
          <h2>{c.ctaTitle}</h2>
          <p>{c.ctaLede}</p>
          <p>
            <Link className="btn btn-primary" href={`/${locale}/prototype?kind=app`}>
              {c.cta}
            </Link>
          </p>
        </section>

        <p className="lg-updated">{c.updated}</p>
      </div>
    </main>
  );
}
