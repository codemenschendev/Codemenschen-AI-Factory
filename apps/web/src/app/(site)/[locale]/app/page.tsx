import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import {
  PACKAGE_PRICES,
  PRICE_MIN,
  SITE_HOSTING_FREE_MONTHS,
  SITE_HOSTING_MONTHLY_EUR,
  SITE_PRICE_EUR,
} from "@ai-factory/pricing";
import { eur, getDict, isLocale, type Locale } from "@/lib/i18n";
import { AppIdeas } from "@/components/AppIdeas";
import { BudgetMeter } from "@/components/BudgetMeter";
import { LandingMotion } from "@/components/LandingMotion";
import { Icon } from "@/components/LineIcon";
import "../../../home.css";

const fill = (s: string, v: Record<string, string>) =>
  s.replace(/\{(\w+)\}/g, (_, k) => v[k] ?? "");

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  const a = getDict(locale).appDev;
  return {
    title: a.metaTitle,
    description: a.metaDesc,
    alternates: { languages: { de: "/de/app", en: "/en/app" } },
  };
}

/** The app-only landing page: one offer (code the app, build its page, run its ads), made for ad traffic. */
export default async function AppLanding({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);
  const x = d.appDev;
  const proto = `/${locale}/prototype`;
  const start = `${proto}?kind=app`;

  const parts: {
    kind: keyof typeof x.one.items;
    price: number;
    from: boolean;
  }[] = [
    { kind: "app", price: PRICE_MIN, from: true },
    { kind: "site", price: SITE_PRICE_EUR, from: false },
    { kind: "ads", price: PACKAGE_PRICES.marketingLaunch, from: true },
  ];
  const trustIcons = ["preview", "euro", "shield", "team"];
  const stepPics = ["step-describe", "step-preview", "step-approve", "step-launch"];
  const priceIcons = ["app", "site", "store", "user", "ads", "server"];
  const [appItem, ...extras] = d.pricing.items;
  const prices = [
    appItem,
    {
      h: x.prices.landing.h,
      fig: fill(x.prices.landing.fig, { price: eur(SITE_PRICE_EUR, locale) }),
      p: fill(x.prices.landing.p, {
        months: String(SITE_HOSTING_FREE_MONTHS),
        monthly: eur(SITE_HOSTING_MONTHLY_EUR, locale),
      }),
    },
    ...extras,
  ];

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
              <p className="pill reveal">{x.eyebrow}</p>
              <h1 className="reveal">
                {x.titleA}
                <br />
                <span className="grad">{x.titleB}</span>
                <br />
                {x.titleC}
              </h1>
              <p className="lede reveal">{x.lede}</p>
              <div className="hero-ctas reveal">
                <Link className="btn btn-primary" href={start}>
                  {x.cta} <Icon name="arrow" className="btn-ico" />
                </Link>
                <a className="btn btn-ghost" href="#prices">
                  {x.cta2}
                </a>
              </div>
            </div>

            <div className="hero-photo" aria-hidden="true">
              {/* eslint-disable-next-line @next/next/no-img-element -- one fixed hero picture */}
              <img
                src="/home/hero-people.webp"
                alt=""
                width={1960}
                height={802}
                fetchPriority="high"
              />
              {/* The three parts of the offer as one project card */}
              <div className="float stats stack">
                <p className="float-label">{x.stackLabel}</p>
                <ul>
                  {x.stack.map((s, i) => (
                    <li key={s}>
                      <Icon name={["app", "site", "ads"][i]} className="stack-ico" />
                      {s}
                      <Icon name="check" className="tick" />
                    </li>
                  ))}
                </ul>
              </div>
              <BudgetMeter
                className="float budget"
                of={fill(d.home.art.budgetOf, {
                  spent: eur(38, locale),
                  cap: eur(100, locale),
                })}
                stop={fill(d.home.art.budgetStop, { cap: eur(100, locale) })}
              />
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

      {/* One hand for all three parts: the app, the page that sells it, the ads that bring people there */}
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
            {parts.map((s) => {
              const it = x.one.items[s.kind];
              return (
                <Link
                  className="svc reveal"
                  key={s.kind}
                  href={`${proto}?kind=${s.kind}`}
                >
                  <div className="svc-thumb">
                    {/* eslint-disable-next-line @next/next/no-img-element -- fixed, small pictures */}
                    <img
                      src={`/home/svc-${s.kind}.webp`}
                      alt=""
                      width={348}
                      height={178}
                      loading="lazy"
                    />
                  </div>
                  <span className="svc-ico">
                    <Icon name={s.kind} />
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
                      <small>
                        {s.from ? d.home.services.from : d.home.services.fixed}
                      </small>
                      <b>{eur(s.price, locale)}</b>
                    </div>
                    <span className="svc-go">
                      <Icon name="arrow" />
                    </span>
                  </div>
                </Link>
              );
            })}
          </div>
        </div>
      </section>

      <section className="section section-tint" id="how">
        <div className="wrap">
          <p className="eyebrow reveal">{d.how.eyebrow}</p>
          <h2 className="reveal">{x.how.title}</h2>
          <ol className="steps">
            {x.how.steps.map((st, i) => (
              <li className="step reveal" key={st.h}>
                <div className="step-pic">
                  {/* eslint-disable-next-line @next/next/no-img-element -- fixed, small pictures */}
                  <img
                    src={`/home/${stepPics[i]}.webp`}
                    alt=""
                    width={420}
                    height={230}
                    loading="lazy"
                  />
                  <span className="step-num">{i + 1}</span>
                </div>
                <h3>{st.h}</h3>
                <p>{st.p}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section" id="apps">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <p className="eyebrow reveal">{d.ideas.eyebrow}</p>
              <h2 className="reveal">{d.ideas.title}</h2>
              <p className="section-lede reveal">{d.ideas.lede}</p>
            </div>
          </div>
          <AppIdeas locale={locale} d={d} />
        </div>
      </section>

      <section className="section section-tint" id="prices">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <h2 className="reveal">{x.prices.title}</h2>
              <p className="section-lede reveal">{x.prices.lede}</p>
            </div>
          </div>
          <div className="price-grid">
            {prices.map((it, i) => (
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
            {d.pricing.note}
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
              <p className="eyebrow">{d.home.final.eyebrow}</p>
              <h2>{x.final.title}</h2>
              <p>{x.final.lede}</p>
            </div>
            <Link className="btn btn-light" href={start}>
              {x.cta} <Icon name="arrow" className="btn-ico" />
            </Link>
          </div>
        </div>
      </section>

      <div className="cta-bar" id="ctaBar">
        <span className="cta-name">{x.final.title}</span>
        <span className="cta-spacer" />
        <Link className="btn btn-primary btn-sm" href={start}>
          {x.cta}
        </Link>
      </div>
    </main>
  );
}
