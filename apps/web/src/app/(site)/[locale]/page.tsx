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
import "../../home.css";

const fill = (s: string, v: Record<string, string>) =>
  s.replace(/\{(\w+)\}/g, (_, k) => v[k] ?? "");

export default async function Home({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);
  const h = d.home;
  const a = h.art;
  const n = (x: number) =>
    x.toLocaleString(locale === "de" ? "de-AT" : "en-IE");

  // A price only where one exists in the pricing package; the rest says "after the preview".
  const services: {
    kind: keyof typeof h.services.items;
    price?: number;
    from?: boolean;
  }[] = [
    { kind: "site", price: SITE_PRICE_EUR },
    { kind: "app", price: PRICE_MIN, from: true },
    { kind: "ads", price: PACKAGE_PRICES.marketingLaunch, from: true },
    { kind: "email" },
    { kind: "campaign" },
  ];
  const trustIcons = ["preview", "euro", "team", "shield"];
  const stepPics = ["step-describe", "step-preview", "step-approve", "step-launch"];
  const priceIcons = ["site", "app", "store", "user", "ads", "server"];
  const prices = [
    {
      h: h.site.h,
      fig: fill(h.site.fig, { price: eur(SITE_PRICE_EUR, locale) }),
      p: fill(h.site.p, {
        months: String(SITE_HOSTING_FREE_MONTHS),
        monthly: eur(SITE_HOSTING_MONTHLY_EUR, locale),
      }),
    },
    ...d.pricing.items,
  ];
  const budgetOf = fill(a.budgetOf, {
    spent: eur(38, locale),
    cap: eur(100, locale),
  });
  const budgetStop = fill(a.budgetStop, { cap: eur(100, locale) });
  const proto = `/${locale}/prototype`;

  return (
    <main className="lp">
      <noscript>
        <style>{`.lp .reveal { opacity: 1; transform: none; }`}</style>
      </noscript>
      <LandingMotion />

      {/* Hero: the promise on the left, a real Appwerk site with its ad and numbers on the right */}
      <section className="hero">
        <div className="wrap">
          <div className="hero-frame">
            <div className="hero-copy">
              <p className="pill reveal">{h.eyebrow}</p>
              <h1 className="reveal">
                {h.titleA}
                <br />
                <span className="grad">{h.titleB}</span>
                <br />
                {h.titleC}
              </h1>
              <p className="lede reveal">{h.lede}</p>
              <div className="hero-ctas reveal">
                <Link className="btn btn-primary" href={proto}>
                  {h.cta} <Icon name="arrow" className="btn-ico" />
                </Link>
                <a className="btn btn-ghost" href="#prices">
                  {h.cta2}
                </a>
              </div>
            </div>

            {/* The whole photo, framed inside the page width; the copy sits on its bright window side. The two
              cards are page text, so they read in the page's language. */}
            <div className="hero-photo" aria-hidden="true">
              {/* eslint-disable-next-line @next/next/no-img-element -- one fixed hero picture */}
              <img
                src="/home/hero-people.webp"
                alt=""
                width={1960}
                height={802}
                fetchPriority="high"
              />
              <div className="float stats">
                <p className="float-label">{a.period}</p>
                <div className="stats-row">
                  <div>
                    <small>{a.visits}</small>
                    <b>{n(2384)}</b>
                    <em>↑ 12%</em>
                  </div>
                  <div>
                    <small>{a.signups}</small>
                    <b>186</b>
                    <em>↑ 24%</em>
                  </div>
                  <div>
                    <small>{a.spend}</small>
                    <b>{eur(128, locale)}</b>
                  </div>
                </div>
              </div>

              <BudgetMeter
                className="float budget"
                of={budgetOf}
                stop={budgetStop}
              />
            </div>
          </div>
        </div>
      </section>

      {/* Four promises, each one something the checkout and the terms actually hold */}
      <section className="trustbar">
        <div className="wrap">
          <div className="trust-grid">
            {h.trust.map((it, i) => (
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
              <p className="eyebrow reveal">{h.services.eyebrow}</p>
              <h2 className="reveal">{h.services.title}</h2>
              <p className="section-lede reveal">{h.services.lede}</p>
            </div>
            <Link className="sec-link" href={proto}>
              {h.services.all} <Icon name="arrow" className="btn-ico" />
            </Link>
          </div>
          <div className="svc-grid">
            {services.map((s) => {
              const it = h.services.items[s.kind];
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
                        {s.price
                          ? s.from
                            ? h.services.from
                            : h.services.fixed
                          : h.services.try}
                      </small>
                      <b className={s.price ? undefined : "svc-free"}>
                        {s.price
                          ? eur(s.price, locale)
                          : h.services.afterPreview}
                      </b>
                    </div>
                    <span className="svc-go">
                      <Icon name="arrow" />
                    </span>
                  </div>
                </Link>
              );
            })}
          </div>

          {/* One idea, three parts, next to the budget guard that keeps the ads safe */}
          <div className="combo">
            <div className="combo-card reveal">
              <h2 className="combo-title">{h.campaign.title}</h2>
              <p className="combo-p">{h.campaign.p}</p>
              <div className="flow">
                <div className="flow-offer">
                  <p className="flow-label">{h.campaign.offerLabel}</p>
                  <p className="flow-input">{h.campaign.offerExample}</p>
                  <Link
                    className="btn btn-primary btn-sm"
                    href={`${proto}?kind=campaign`}
                  >
                    {h.campaign.offerBtn}{" "}
                    <Icon name="arrow" className="btn-ico" />
                  </Link>
                </div>
                {/* One rendered picture: the ad on a phone, the page on a laptop, the e-mail on a
                    phone. The three labels are real text placed over their part of the picture. */}
                <div className="flow-pic" aria-hidden="true">
                  {h.campaign.parts.map((label, i) => (
                    <p className={`part-tag part-tag-${i + 1}`} key={label}>
                      <span>{i + 1}</span> {label}
                    </p>
                  ))}
                  {/* eslint-disable-next-line @next/next/no-img-element -- one fixed picture */}
                  <img
                    src="/home/campaign-flow.webp"
                    alt=""
                    width={1656}
                    height={680}
                    loading="lazy"
                  />
                </div>
              </div>
            </div>

            <div className="combo-card budget-card reveal">
              <div className="budget-text">
                <h2 className="combo-title">{h.budget.title}</h2>
                <p className="combo-p">{h.budget.p}</p>
              </div>
              <div className="budget-box">
                <p className="budget-big">
                  {fill(h.budget.spentOf, {
                    spent: eur(38, locale),
                    cap: eur(100, locale),
                  })}
                </p>
                <div className="meter">
                  <span style={{ width: "38%" }} />
                </div>
              </div>
              <dl className="budget-rows">
                {h.budget.rows.map(([k, v], i) => (
                  <div key={k}>
                    <dt>{k}</dt>
                    <dd
                      className={
                        i === h.budget.rows.length - 1 ? "on" : undefined
                      }
                    >
                      {fill(v, { cap: eur(100, locale) })}
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          </div>
        </div>
      </section>

      <section className="section section-tint" id="how">
        <div className="wrap">
          <p className="eyebrow reveal">{d.how.eyebrow}</p>
          <h2 className="reveal">{d.how.title}</h2>
          <ol className="steps">
            {d.how.steps.map((st, i) => (
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

      <section className="section" id="prices">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <h2 className="reveal">{h.prices.title}</h2>
              <p className="section-lede reveal">{h.prices.lede}</p>
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

      <section className="section section-tint" id="apps">
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

      <section className="section" id="about">
        <div className="wrap about-grid">
          <div>
            <p className="eyebrow reveal">{d.about.eyebrow}</p>
            <h2 className="reveal">{d.about.title}</h2>
            <p className="section-lede reveal">{d.about.p}</p>
            <div className="about-chips">
              {d.about.chips.map((c, i) => (
                <div className="about-chip reveal" key={c.h}>
                  <span className="trust-ico">
                    <Icon name={["team", "mail", "pin"][i]} />
                  </span>
                  <div>
                    <b>{c.h}</b>
                    <small>{c.p}</small>
                  </div>
                </div>
              ))}
            </div>
            <a
              className="btn btn-ghost reveal"
              href="https://www.codemenschen.at"
              target="_blank"
              rel="noopener"
            >
              {d.about.cta}
            </a>
          </div>
          <div className="about-pic reveal">
            {/* eslint-disable-next-line @next/next/no-img-element -- one fixed picture */}
            <img
              src="/home/about-team.webp"
              alt=""
              width={431}
              height={229}
              loading="lazy"
            />
          </div>
        </div>
      </section>

      {/* Closing band: the one next step */}
      <section className="section-final">
        <div className="wrap">
          <div className="final-band reveal">
          <div className="final-text">
            <p className="eyebrow">{h.final.eyebrow}</p>
            <h2>{h.final.title}</h2>
            <p>{h.final.lede}</p>
          </div>
          <Link className="btn btn-light" href={proto}>
            {h.cta} <Icon name="arrow" className="btn-ico" />
          </Link>
  </div>
        </div>
      </section>

      {/* Sticky CTA: appears after the first screen */}
      <div className="cta-bar" id="ctaBar">
        <span className="cta-name">{h.final.title}</span>
        <span className="cta-spacer" />
        <Link className="btn btn-primary btn-sm" href={proto}>
          {h.cta}
        </Link>
      </div>
    </main>
  );
}
