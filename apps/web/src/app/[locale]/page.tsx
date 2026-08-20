import Link from "next/link";
import { CATALOG } from "@/lib/catalog";
import { eur, getDict, isLocale, t, type Locale } from "@/lib/i18n";
import { notFound } from "next/navigation";

export default async function Home({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main>
      <section className="wrap">
        <h1>{d.hero.title}</h1>
        <p className="muted" style={{ fontSize: 18 }}>
          {d.hero.lede}
        </p>
        <p style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
          <a className="btn btn-primary" href="#apps">
            {d.hero.ctaIdeas}
          </a>
          <Link className="btn btn-ghost" href={`/${locale}/create`}>
            {d.hero.ctaCreate}
          </Link>
        </p>
      </section>

      <section className="wrap" id="how">
        <h2>{d.how.title}</h2>
        <div className="steps">
          {d.how.steps.map((s) => (
            <div className="step" key={s.h}>
              <h3>{s.h}</h3>
              <p>{s.p}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="wrap" id="apps">
        <h2>{d.ideas.title}</h2>
        <p className="muted">{d.ideas.lede}</p>
        <p className="note">{d.ideas.note}</p>
        <div className="grid" style={{ marginTop: 24 }}>
          {CATALOG.map((app) => (
            <div className="card" key={app.slug}>
              <span className="card-icon" aria-hidden>
                {app.icon}
              </span>
              <span className="cat">{t(app.cat, locale)}</span>
              <h3>{app.name}</h3>
              <p className="muted" style={{ fontSize: 14.5 }}>
                {t(app.cardDesc, locale)}
              </p>
              {app.status === "built" ? (
                <div className="card-foot">
                  <span className="badge badge-built">{d.ideas.built}</span>
                </div>
              ) : (
                <div className="card-foot">
                  <span>
                    <span className="muted small">{d.ideas.from}: </span>
                    <span className="price">{eur(app.price!, locale)}</span>
                  </span>
                  <Link className="btn btn-ghost" href={`/${locale}/apps/${app.slug}`}>
                    {d.ideas.view}
                  </Link>
                </div>
              )}
            </div>
          ))}
        </div>
      </section>

      <section className="wrap">
        <div className="card" style={{ alignItems: "flex-start" }}>
          <h2 style={{ marginBottom: 4 }}>{d.createBanner.title}</h2>
          <p className="muted">{d.createBanner.p}</p>
          <Link className="btn btn-primary" href={`/${locale}/create`}>
            {d.createBanner.cta}
          </Link>
        </div>
      </section>

      {/* Honest-risk section: same typographic dignity as everything else,
          no primary CTA adjacent (appwerk docs 15/19/26). */}
      <section className="wrap">
        <h2>{d.honest.title}</h2>
        <div className="steps" style={{ counterReset: "none" }}>
          {d.honest.items.map((it) => (
            <div className="card" key={it.h}>
              <h3>{it.h}</h3>
              <p className="muted" style={{ fontSize: 14.5 }}>
                {it.p}
              </p>
            </div>
          ))}
        </div>
      </section>

      <section className="wrap">
        <h2>{d.pricing.title}</h2>
        <div className="tbl-wrap">
          <table>
            <tbody>
              {d.pricing.rows.map((r) => (
                <tr key={r[0]}>
                  <td>{r[0]}</td>
                  <td className="muted">{r[1]}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="small muted" style={{ marginTop: 10 }}>
          {d.pricing.note}
        </p>
      </section>

      <section className="wrap-narrow">
        <h2>{d.faq.title}</h2>
        {d.faq.items.map((f) => (
          <div key={f.q} style={{ marginBottom: 18 }}>
            <h3>{f.q}</h3>
            <p className="muted">{f.a}</p>
          </div>
        ))}
      </section>
    </main>
  );
}
