import Link from "next/link";
import { notFound } from "next/navigation";
import {
  PACKAGE_PRICES,
  PRICE_MIN,
  SITE_HOSTING_FREE_MONTHS,
  SITE_HOSTING_MONTHLY_EUR,
  SITE_PRICE_EUR,
} from "@ai-factory/pricing";
import { CATALOG } from "@/lib/catalog";
import { APP_ART, CONCEPT_ART } from "@/lib/art";
import { SCREENS } from "@/lib/screens";
import { eur, getDict, isLocale, t, type Locale } from "@/lib/i18n";
import { LandingMotion } from "@/components/LandingMotion";
import "../../home.css";

/** Inline SVG / mockup markup from our own modules, never user input. */
function Art({ html, className }: { html: string; className?: string }) {
  return (
    <div
      className={className}
      aria-hidden
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}

function PhoneFrame({ html, className }: { html: string; className?: string }) {
  return (
    <div
      className={className ? `ph-frame ${className}` : "ph-frame"}
      aria-hidden
    >
      <Art html={html} />
    </div>
  );
}

/** The card visual: the real app screen in a phone frame, illustration as fallback. */
function CardVisual({ slug }: { slug: string }) {
  const screen = SCREENS[slug]?.[0];
  if (screen) {
    return (
      <div className="card-phone">
        <PhoneFrame html={screen} />
      </div>
    );
  }
  return <Art className="card-art" html={APP_ART[slug] ?? ""} />;
}

/** Line icons, 24px grid, stroke follows currentColor. */
const ICONS: Record<string, string> = {
  site: '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/>',
  app: '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
  ads: '<path d="M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1Z"/><path d="M17 8.5a5 5 0 0 1 0 7M19.5 6a8.5 8.5 0 0 1 0 12"/>',
  email:
    '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
  campaign: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  preview:
    '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
  euro: '<path d="M17.5 6.5A7 7 0 1 0 17.5 17.5M4 10.5h9M4 13.5h9"/>',
  team: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18.5 20a6.5 6.5 0 0 0-2.5-5.1"/>',
  shield:
    '<path d="M12 2.5 4 5.5v6c0 5 3.4 8.6 8 10 4.6-1.4 8-5 8-10v-6Z"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
};

function Icon({ name, className }: { name: string; className?: string }) {
  return (
    <svg
      className={className ?? "ico"}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      dangerouslySetInnerHTML={{ __html: ICONS[name] ?? "" }}
    />
  );
}

const fill = (s: string, v: Record<string, string>) =>
  s.replace(/\{(\w+)\}/g, (_, k) => v[k] ?? "");

/** The ad-spend meter: the SpendGuard promise in one picture. */
function BudgetMeter({
  of,
  stop,
  className,
}: {
  of: string;
  stop: string;
  className?: string;
}) {
  return (
    <div className={className} aria-hidden="true">
      <p className="budget-head">
        <span className="meta-dot">∞</span> Meta Ads
      </p>
      <div className="meter">
        <span style={{ width: "38%" }} />
      </div>
      <p className="budget-fig">{of}</p>
      <p className="budget-note">{stop}</p>
    </div>
  );
}

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
        <div className="wrap hero-grid">
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

          {/* The photo carries its own cards; ours sit exactly on top of them, so every word
              and number on the picture comes from the dictionary in the page's language. */}
          <div className="hero-photo" aria-hidden="true">
            {/* eslint-disable-next-line @next/next/no-img-element -- one fixed hero picture */}
            <img
              src="/home/hero-people.webp"
              alt=""
              width={886}
              height={480}
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
          <p className="eyebrow reveal">{h.services.eyebrow}</p>
          <h2 className="reveal">{h.services.title}</h2>
          <p className="section-lede reveal">{h.services.lede}</p>
          <div className="svc-grid">
            {services.map((s) => {
              const it = h.services.items[s.kind];
              return (
                <Link
                  className="svc reveal"
                  key={s.kind}
                  href={`${proto}?kind=${s.kind}`}
                >
                  <span className="svc-ico">
                    <Icon name={s.kind} />
                  </span>
                  <h3>{it.h}</h3>
                  <p>{it.p}</p>
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
        </div>
      </section>

      {/* One message, three parts: the campaign prototype as it really comes out */}
      <section className="section section-tint">
        <div className="wrap camp-grid">
          <div>
            <p className="eyebrow reveal">{h.campaign.eyebrow}</p>
            <h2 className="reveal">{h.campaign.title}</h2>
            <p className="section-lede reveal">{h.campaign.p}</p>
            <Link
              className="btn btn-primary reveal"
              href={`${proto}?kind=campaign`}
            >
              {h.services.try} <Icon name="arrow" className="btn-ico" />
            </Link>
          </div>
          <div className="camp-flow reveal" aria-hidden="true">
            <div className="camp-card">
              <p className="camp-tag">1 · {h.campaign.parts[0]}</p>
              {/* eslint-disable-next-line @next/next/no-img-element -- decorative */}
              <img src="/home/bakery.webp" alt="" width={720} height={480} />
              <p className="camp-line">{h.campaign.adLine}</p>
            </div>
            <div className="camp-card">
              <p className="camp-tag">2 · {h.campaign.parts[1]}</p>
              <p className="camp-title">{h.campaign.pageTitle}</p>
              <span className="camp-field">{h.campaign.pageField}</span>
              <span className="camp-btn">{h.campaign.pageBtn}</span>
            </div>
            <div className="camp-card">
              <p className="camp-tag">3 · {h.campaign.parts[2]}</p>
              <p className="camp-title">{h.campaign.mailHi}</p>
              <p className="camp-mail">{h.campaign.mailText}</p>
            </div>
          </div>
        </div>
      </section>

      {/* The spend guard is real (SpendGuard + factory:ads-guard), so it gets its own block */}
      <section className="section">
        <div className="wrap budget-grid">
          <BudgetMeter
            className="budget-card reveal"
            of={budgetOf}
            stop={budgetStop}
          />
          <div>
            <p className="eyebrow reveal">{h.budget.eyebrow}</p>
            <h2 className="reveal">{h.budget.title}</h2>
            <ul className="ticks">
              {h.budget.points.map((p) => (
                <li className="reveal" key={p}>
                  <Icon name="check" className="tick" />
                  {p}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </section>

      <section className="section section-tint" id="how">
        <div className="wrap">
          <p className="eyebrow reveal">{d.how.eyebrow}</p>
          <h2 className="reveal">{d.how.title}</h2>
          <ol className="steps">
            {d.how.steps.map((s, i) => (
              <li className="step reveal" key={s.h}>
                <span className="step-num">{i + 1}</span>
                <h3>{s.h}</h3>
                <p>{s.p}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section" id="prices">
        <div className="wrap">
          <p className="eyebrow reveal">{d.pricing.eyebrow}</p>
          <h2 className="reveal">{d.pricing.title}</h2>
          <div className="price-grid">
            {prices.map((it) => (
              <div className="price-item reveal" key={it.h}>
                <h3>{it.h}</h3>
                <p className="price-fig">{it.fig}</p>
                <p>{it.p}</p>
              </div>
            ))}
          </div>
          <p className="price-note reveal">{d.pricing.note}</p>
        </div>
      </section>

      <section className="section section-tint" id="apps">
        <div className="wrap">
          <p className="eyebrow reveal">{d.ideas.eyebrow}</p>
          <h2 className="reveal">{d.ideas.title}</h2>
          <p className="section-lede reveal">{d.ideas.lede}</p>
          <div className="cards">
            {CATALOG.map((app) => {
              const taken = app.status === "built";
              return (
                <article
                  className={taken ? "card card-taken reveal" : "card reveal"}
                  key={app.slug}
                >
                  <span
                    className={
                      taken ? "badge badge-taken" : "badge badge-sample"
                    }
                  >
                    {taken ? d.ideas.built : d.detail.sample}
                  </span>
                  <CardVisual slug={app.slug} />
                  <h3>{app.name}</h3>
                  <p className="card-cat">{t(app.cat, locale)}</p>
                  <p className="card-desc">{t(app.cardDesc, locale)}</p>
                  <dl className="card-data">
                    <div>
                      <dt>{d.ideas.from}</dt>
                      <dd>
                        {taken || !app.price ? "—" : eur(app.price, locale)}
                      </dd>
                    </div>
                    <div>
                      <dt>{d.ideas.delivery}</dt>
                      <dd>
                        {taken || !app.weeksLo
                          ? "—"
                          : `${app.weeksLo}–${app.weeksHi} ${d.detail.weeksUnit}`}
                      </dd>
                    </div>
                    <div>
                      <dt>{d.ideas.type}</dt>
                      <dd>
                        {app.appType === "A" ? d.detail.typeA : d.detail.typeB}
                      </dd>
                    </div>
                  </dl>
                  {taken ? (
                    <span className="btn btn-ghost btn-block btn-disabled">
                      {d.detail.ctaTaken}
                    </span>
                  ) : (
                    <Link
                      className="btn btn-primary btn-block"
                      href={`/${locale}/apps/${app.slug}`}
                    >
                      {d.ideas.view}
                    </Link>
                  )}
                </article>
              );
            })}
            <article className="card card-create reveal">
              <Art className="card-art" html={CONCEPT_ART.build} />
              <h3>{d.createBanner.title}</h3>
              <p className="card-desc" style={{ marginTop: 8 }}>
                {d.createBanner.p}
              </p>
              <Link
                className="btn btn-primary btn-block"
                href={`/${locale}/create`}
              >
                {d.createBanner.cta}
              </Link>
            </article>
          </div>
          <p className="placeholder-note reveal">{d.ideas.note}</p>
        </div>
      </section>

      <section className="section" id="about">
        <div className="wrap wrap-narrow center">
          <p className="eyebrow reveal">{d.about.eyebrow}</p>
          <h2 className="reveal">{d.about.title}</h2>
          <p className="section-lede reveal about-p">{d.about.p}</p>
          <a
            className="btn btn-ghost reveal"
            href="https://www.codemenschen.at"
            target="_blank"
            rel="noopener"
          >
            {d.about.cta}
          </a>
        </div>
      </section>

      <section className="section-final">
        <div className="wrap">
          <div className="final-card reveal">
            <h2>{h.final.title}</h2>
            <p>{h.final.lede}</p>
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
