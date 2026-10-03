import Link from "next/link";
import { notFound } from "next/navigation";
import { isLocale } from "@/lib/i18n";
import { sbDict } from "@/dictionaries/sofabuilt";
import { LicenseCalc } from "@/components/sofabuilt/LicenseCalc";

/** Sofabuilt's start page: the promise, the two doors, how it works, prices, the licence calculator. */
export default async function SofabuiltHome({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const d = sbDict(locale);

  return (
    <main>
      <section className="sb-hero">
        <div className="wrap">
          <p className="sb-eyebrow">{d.hero.eyebrow}</p>
          <h1>
            {d.hero.title.map((line, i) => (
              <span key={line} className={i === 2 ? "sb-hero-relax" : undefined}>
                {line}
              </span>
            ))}
          </h1>
          <p className="sb-lede">{d.hero.lede}</p>
          <div className="sb-doors">
            {d.hero.doors.map((door) => (
              <Link key={door.key} href={`/${locale}/desk?start=${door.key}`} className={`sb-door sb-door-${door.key}`}>
                <span className="sb-door-icon" aria-hidden="true">{door.key === "idea" ? "💡" : "🔁"}</span>
                <h2>{door.h}</h2>
                <p>{door.p}</p>
                <span className="sb-door-cta">{door.cta} →</span>
              </Link>
            ))}
          </div>
          <p className="sb-hero-note">{d.hero.note}</p>
        </div>
      </section>

      <section className="sb-section" id="how">
        <div className="wrap">
          <h2 className="sb-h2">{d.how.title}</h2>
          <ol className="sb-steps">
            {d.how.steps.map((s, i) => (
              <li key={s.h}>
                <span className="sb-step-n">{i + 1}</span>
                <h3>{s.h}</h3>
                <p>{s.p}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="sb-section sb-section-alt" id="what">
        <div className="wrap">
          <h2 className="sb-h2">{d.what.title}</h2>
          <div className="sb-grid">
            {d.what.items.map((it) => (
              <div key={it.h} className="sb-card">
                <h3>{it.h}</h3>
                <p>{it.p}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="sb-section" id="licence">
        <div className="wrap">
          <h2 className="sb-h2">{d.license.title}</h2>
          <p className="sb-section-lede">{d.license.lede}</p>
          <LicenseCalc t={d.license} locale={locale} />
        </div>
      </section>

      <section className="sb-section sb-section-alt" id="prices">
        <div className="wrap">
          <h2 className="sb-h2">{d.prices.title}</h2>
          <p className="sb-section-lede">{d.prices.lede}</p>
          <div className="sb-grid sb-grid-3">
            {d.prices.items.map((it) => (
              <div key={it.h} className="sb-card sb-price">
                <h3>{it.h}</h3>
                <p className="sb-price-fig">{it.fig}</p>
                <p>{it.p}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="sb-section" id="faq">
        <div className="wrap wrap-narrow">
          <h2 className="sb-h2">{d.faq.title}</h2>
          {d.faq.items.map((f) => (
            <details key={f.q} className="sb-faq">
              <summary>{f.q}</summary>
              <p>{f.a}</p>
            </details>
          ))}
        </div>
      </section>

      <section className="sb-final">
        <div className="wrap">
          <h2>{d.final.title}</h2>
          <p>{d.final.lede}</p>
          <Link className="sb-btn" href={`/${locale}/desk`}>
            {d.final.cta}
          </Link>
        </div>
      </section>
    </main>
  );
}
