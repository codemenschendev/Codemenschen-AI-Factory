import Link from "next/link";
import { notFound } from "next/navigation";
import { eur, isLocale, type Locale } from "@/lib/i18n";
import { sbDict } from "@/dictionaries/sofabuilt";
import { LandingMotion } from "@/components/LandingMotion";
import { LicenseCalc } from "@/components/sofabuilt/LicenseCalc";
import { Icon } from "@/components/LineIcon";
import "../../../home.css";

/**
 * Sofabuilt's start page in the layout of Appmitki's app page (same sections, same classes):
 * hero with the project card, trust bar, the two ways in, the licence calculator, how it works,
 * prices, FAQ and the final call.
 */
export default async function SofabuiltHome({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = sbDict(locale);
  const x = d.page;
  const desk = `/${locale}/desk`;
  const doors = [`${desk}?start=idea`, `${desk}?start=premium`, "#prices"];
  const doorPics = ["step-describe", "svc-app", "svc-ads"];
  const doorIcons = ["preview", "app", "ads"];
  const trustIcons = ["preview", "euro", "shield", "team"];
  const stepPics = ["step-describe", "step-preview", "step-approve", "step-launch"];
  const priceIcons = ["app", "store", "server"];

  return (
    <main className="lp">
      <noscript>
        <style>{`.lp .reveal { opacity: 1; transform: none; }`}</style>
      </noscript>
      <LandingMotion />

      <section className="hero">
        <div className="wrap">
          <div className="hero-frame">
            <div className="hero-copy">
              <p className="pill reveal">{d.hero.eyebrow}</p>
              <h1 className="reveal">
                {d.hero.title[0]}
                <br />
                <span className="grad">{d.hero.title[1]}</span>
                <br />
                {d.hero.title[2]}
              </h1>
              <p className="lede reveal">{d.hero.lede}</p>
              <div className="hero-ctas reveal">
                <Link className="btn btn-primary" href={desk}>
                  {d.nav.cta} <Icon name="arrow" className="btn-ico" />
                </Link>
                <a className="btn btn-ghost" href="#licence">
                  {x.cta2}
                </a>
              </div>
            </div>

            <div className="hero-photo" aria-hidden="true">
              {/* eslint-disable-next-line @next/next/no-img-element -- one fixed hero picture */}
              <img src="/home/hero-people.webp" alt="" width={1960} height={802} fetchPriority="high" />
              <div className="float stats stack">
                <p className="float-label">{x.stackLabel}</p>
                <ul>
                  {x.stack.map((s, i) => (
                    <li key={s}>
                      <Icon name={["preview", "app", "ads"][i]} className="stack-ico" />
                      {s}
                      <Icon name="check" className="tick" />
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="trustbar">
        <div className="wrap">
          <div className="trust-grid">
            {x.trust.map((it, i) => (
              <div className="trust reveal" key={it.h}>
                <span className="trust-ico">
                  <Icon name={trustIcons[i]} />
                </span>
                <div>
                  <h3>{it.h}</h3>
                  <p>{it.p}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="section" id="services">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <p className="eyebrow reveal">{x.one.eyebrow}</p>
              <h2 className="reveal">{x.one.title}</h2>
              <p className="section-lede reveal">{x.one.lede}</p>
            </div>
          </div>
          <div className="svc-grid svc-grid-3">
            {x.one.items.map((it, i) => (
              <Link className="svc reveal" key={it.h} href={doors[i]}>
                <div className="svc-thumb">
                  {/* eslint-disable-next-line @next/next/no-img-element -- fixed, small pictures */}
                  <img src={`/home/${doorPics[i]}.webp`} alt="" width={348} height={178} loading="lazy" />
                </div>
                <span className="svc-ico">
                  <Icon name={doorIcons[i]} />
                </span>
                <h3>{it.h}</h3>
                <p>{it.p}</p>
                <ul className="svc-points">
                  {it.points.map((pt) => (
                    <li key={pt}>
                      <Icon name="check" className="tick" />
                      {pt}
                    </li>
                  ))}
                </ul>
                <div className="svc-foot">
                  <div>
                    <small>{x.one.from}</small>
                    <b>{eur(it.price, locale)}</b>
                  </div>
                  <span className="svc-go">
                    <Icon name="arrow" />
                  </span>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>

      <section className="section section-tint" id="licence">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <p className="eyebrow reveal">{x.licenceEyebrow}</p>
              <h2 className="reveal">{d.license.title}</h2>
              <p className="section-lede reveal">{d.license.lede}</p>
            </div>
          </div>
          <LicenseCalc t={d.license} locale={locale} />
        </div>
      </section>

      <section className="section" id="how">
        <div className="wrap">
          <p className="eyebrow reveal">{x.howEyebrow}</p>
          <h2 className="reveal">{d.how.title}</h2>
          <ol className="steps">
            {d.how.steps.map((st, i) => (
              <li className="step reveal" key={st.h}>
                <div className="step-pic">
                  {/* eslint-disable-next-line @next/next/no-img-element -- fixed, small pictures */}
                  <img src={`/home/${stepPics[i]}.webp`} alt="" width={420} height={230} loading="lazy" />
                  <span className="step-num">{i + 1}</span>
                </div>
                <h3>{st.h}</h3>
                <p>{st.p}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section section-tint" id="prices">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <h2 className="reveal">{d.prices.title}</h2>
              <p className="section-lede reveal">{d.prices.lede}</p>
            </div>
          </div>
          <div className="price-grid">
            {d.prices.items.map((it, i) => (
              <div className="price-item reveal" key={it.h}>
                <span className="price-ico">
                  <Icon name={priceIcons[i]} />
                </span>
                <div>
                  <h3>{it.h}</h3>
                  <p className="price-fig">{it.fig}</p>
                  <p className="price-p">{it.p}</p>
                </div>
              </div>
            ))}
          </div>
          <p className="price-note reveal">
            <Icon name="shield" className="price-note-ico" />
            {x.pricesNote}
          </p>
        </div>
      </section>

      <section className="section" id="faq">
        <div className="wrap faq-wrap">
          <h2 className="reveal">{x.faqTitle}</h2>
          <div className="faq-list">
            {d.faq.items.map((f) => (
              <details className="faq-item reveal" key={f.q}>
                <summary>{f.q}</summary>
                <p>{f.a}</p>
              </details>
            ))}
          </div>
        </div>
      </section>

      <section className="section-final">
        <div className="wrap">
          <div className="final-band reveal">
            <div className="final-text">
              <p className="eyebrow">{x.finalEyebrow}</p>
              <h2>{d.final.title}</h2>
              <p>{d.final.lede}</p>
            </div>
            <Link className="btn btn-light" href={desk}>
              {d.final.cta} <Icon name="arrow" className="btn-ico" />
            </Link>
          </div>
        </div>
      </section>
    </main>
  );
}
